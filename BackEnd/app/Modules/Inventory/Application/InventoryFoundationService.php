<?php

namespace App\Modules\Inventory\Application;

use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class InventoryFoundationService
{
    public const OWNER_TYPES = ['COMPANY', 'PARTY'];
    public const OWNER_STATUSES = ['DRAFT', 'ACTIVE', 'INACTIVE'];
    public const LOT_ORIGINS = ['PURCHASE', 'PRODUCTION', 'RETURN', 'OPENING'];
    public const LOT_STATUSES = ['DRAFT', 'ACTIVE', 'CLOSED', 'RECALLED'];

    private const OWNER_TRANSITIONS = [
        'DRAFT' => ['ACTIVE', 'INACTIVE'],
        'ACTIVE' => ['INACTIVE'],
        'INACTIVE' => ['ACTIVE'],
    ];

    private const LOT_TRANSITIONS = [
        'DRAFT' => ['ACTIVE', 'CLOSED'],
        'ACTIVE' => ['CLOSED', 'RECALLED'],
        'CLOSED' => ['ACTIVE', 'RECALLED'],
        'RECALLED' => ['CLOSED'],
    ];

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    public function createOwner(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $namespace = 'inventory.owner.create';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }

            $this->validateOwner($data);
            $this->assertOwnerCodeAvailable($data['company_id'], $data['code']);
            $this->assertOwnerIdentityAvailable($data['company_id'], $data['owner_type'], $data['party_id'] ?? null);

            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('inventory_owners')->insert([
                'id' => $id,
                'company_id' => $data['company_id'],
                'party_id' => $data['owner_type'] === 'PARTY' ? $data['party_id'] : null,
                'code' => $data['code'],
                'name' => trim($data['name']),
                'owner_type' => $data['owner_type'],
                'status' => $data['status'],
                'record_version' => 1,
                'status_reason' => $data['status'] === 'ACTIVE' ? 'Activated during owner creation.' : null,
                'status_changed_at' => $data['status'] === 'ACTIVE' ? $now : null,
                'status_changed_by' => $data['status'] === 'ACTIVE' ? $data['actor_id'] : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $result = $this->result('inventory_owner', $id, $data['status'], 1);
            $this->record('CREATE_INVENTORY_OWNER', 'inventory.owner.created', 'inventory_owner', $id,
                $data, 1, ['created' => [
                    'code' => $data['code'], 'owner_type' => $data['owner_type'], 'status' => $data['status'],
                ]], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function updateOwner(string $ownerId, array $data): array
    {
        return DB::transaction(function () use ($ownerId, $data) {
            $namespace = 'inventory.owner.update.'.$ownerId;
            if ($replay = $this->begin($namespace, $data + ['owner_id' => $ownerId])) {
                return $replay;
            }

            $owner = $this->findOwnerLocked($ownerId, $data['company_id']);
            $this->assertVersion('inventory owner', $owner, $data['expected_version']);
            $data['status'] = $owner->status;
            $this->validateOwner($data);
            $partyId = $data['owner_type'] === 'PARTY' ? $data['party_id'] : null;
            $identityChanged = $owner->owner_type !== $data['owner_type']
                || (string) ($owner->party_id ?? '') !== (string) ($partyId ?? '');
            if ($identityChanged && DB::table('stock_positions')->where('inventory_owner_id', $ownerId)->exists()) {
                throw ValidationException::withMessages([
                    'owner_type' => ['Owner type and party cannot change after a stock position has used this owner.'],
                ]);
            }
            $this->assertOwnerIdentityAvailable($data['company_id'], $data['owner_type'], $partyId, $ownerId);

            $version = (int) $owner->record_version + 1;
            DB::table('inventory_owners')->where('id', $ownerId)->update([
                'party_id' => $partyId,
                'name' => trim($data['name']),
                'owner_type' => $data['owner_type'],
                'record_version' => $version,
                'updated_at' => now(),
            ]);
            $result = $this->result('inventory_owner', $ownerId, (string) $owner->status, $version);
            $this->record('UPDATE_INVENTORY_OWNER', 'inventory.owner.updated', 'inventory_owner', $ownerId,
                $data, $version, [
                    'name' => ['from' => $owner->name, 'to' => trim($data['name'])],
                    'owner_type' => ['from' => $owner->owner_type, 'to' => $data['owner_type']],
                    'party_id' => ['from' => $owner->party_id, 'to' => $partyId],
                ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function changeOwnerStatus(string $ownerId, string $targetStatus, string $reason, array $data): array
    {
        return DB::transaction(function () use ($ownerId, $targetStatus, $reason, $data) {
            $namespace = 'inventory.owner.status.'.$ownerId;
            $payload = $data + ['owner_id' => $ownerId, 'target_status' => $targetStatus, 'reason' => $reason];
            if ($replay = $this->begin($namespace, $payload)) {
                return $replay;
            }

            $owner = $this->findOwnerLocked($ownerId, $data['company_id']);
            $this->assertVersion('inventory owner', $owner, $data['expected_version']);
            if (! in_array($targetStatus, self::OWNER_TRANSITIONS[$owner->status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'target_status' => ["An inventory owner cannot move from {$owner->status} to {$targetStatus}."],
                ]);
            }
            if ($targetStatus === 'ACTIVE') {
                $this->validateOwner([
                    ...$data,
                    'owner_type' => $owner->owner_type,
                    'party_id' => $owner->party_id,
                    'status' => 'ACTIVE',
                ]);
            } else {
                if ($owner->owner_type === 'COMPANY') {
                    throw ValidationException::withMessages([
                        'target_status' => ['The company-owned stock owner must remain active.'],
                    ]);
                }
                if (DB::table('stock_positions')->where('inventory_owner_id', $ownerId)
                    ->where('quantity_base', '>', 0)->exists()) {
                    throw ValidationException::withMessages([
                        'target_status' => ['Move all stock assigned to this owner before deactivating it.'],
                    ]);
                }
                if (DB::table('stock_reservations as reservation')
                    ->join('stock_positions as position', 'position.id', '=', 'reservation.stock_position_id')
                    ->where('position.inventory_owner_id', $ownerId)->where('reservation.status', 'ACTIVE')->exists()) {
                    throw ValidationException::withMessages([
                        'target_status' => ['Release all active reservations for this owner before deactivating it.'],
                    ]);
                }
            }

            $version = (int) $owner->record_version + 1;
            DB::table('inventory_owners')->where('id', $ownerId)->update([
                'status' => $targetStatus,
                'status_reason' => $reason,
                'status_changed_at' => now(),
                'status_changed_by' => $data['actor_id'],
                'record_version' => $version,
                'updated_at' => now(),
            ]);
            $result = $this->result('inventory_owner', $ownerId, $targetStatus, $version);
            $this->record('CHANGE_INVENTORY_OWNER_STATUS', 'inventory.owner.status_changed', 'inventory_owner',
                $ownerId, $data, $version, [
                    'status' => ['from' => $owner->status, 'to' => $targetStatus],
                    'reason' => $reason,
                ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function deleteOwner(string $ownerId, array $data): array
    {
        return DB::transaction(function () use ($ownerId, $data) {
            $namespace = 'inventory.owner.delete.'.$ownerId;
            if ($replay = $this->begin($namespace, $data + ['owner_id' => $ownerId])) {
                return $replay;
            }

            $owner = $this->findOwnerLocked($ownerId, $data['company_id']);
            $this->assertVersion('inventory owner', $owner, $data['expected_version']);
            if ($owner->owner_type === 'COMPANY') {
                throw ValidationException::withMessages([
                    'owner' => ['The protected company-owned inventory identity cannot be deleted.'],
                ]);
            }
            if (DB::table('stock_positions')->where('inventory_owner_id', $ownerId)->exists()) {
                throw ValidationException::withMessages([
                    'owner' => ['This owner has stock-position history and cannot be deleted. Deactivate it instead.'],
                ]);
            }
            try {
                DB::table('inventory_owners')->where('id', $ownerId)->delete();
            } catch (QueryException) {
                throw ValidationException::withMessages([
                    'owner' => ['This owner is used by another ERP entry and cannot be deleted. Deactivate it instead.'],
                ]);
            }

            $version = (int) $owner->record_version + 1;
            $result = $this->result('inventory_owner', $ownerId, 'DELETED', $version) + ['deleted' => true];
            $this->record('DELETE_INVENTORY_OWNER', 'inventory.owner.deleted', 'inventory_owner',
                $ownerId, $data, $version, ['deleted' => ['code' => $owner->code, 'name' => $owner->name]], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function createLot(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $namespace = 'inventory.lot.create';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }

            $this->validateLot($data);
            if (DB::table('lots')->where('company_id', $data['company_id'])
                ->where('internal_lot_code', $data['internal_lot_code'])->exists()) {
                throw ValidationException::withMessages([
                    'internal_lot_code' => ['That internal lot code already exists in the selected company.'],
                ]);
            }

            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('lots')->insert([
                'id' => $id,
                'company_id' => $data['company_id'],
                'item_id' => $data['item_id'],
                'supplier_party_id' => $data['supplier_party_id'] ?? null,
                'internal_lot_code' => $data['internal_lot_code'],
                'supplier_lot_code' => $this->nullable($data['supplier_lot_code'] ?? null),
                'origin_type' => $data['origin_type'],
                'manufacture_date' => $data['manufacture_date'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? null,
                'status' => $data['status'],
                'notes' => $this->nullable($data['notes'] ?? null),
                'record_version' => 1,
                'status_reason' => $data['status'] === 'ACTIVE' ? 'Activated during lot creation.' : null,
                'status_changed_at' => $data['status'] === 'ACTIVE' ? $now : null,
                'status_changed_by' => $data['status'] === 'ACTIVE' ? $data['actor_id'] : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $result = $this->result('inventory_lot', $id, $data['status'], 1);
            $this->record('CREATE_INVENTORY_LOT', 'inventory.lot.created', 'inventory_lot', $id,
                $data, 1, ['created' => [
                    'internal_lot_code' => $data['internal_lot_code'], 'item_id' => $data['item_id'],
                    'origin_type' => $data['origin_type'], 'status' => $data['status'],
                ]], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function updateLot(string $lotId, array $data): array
    {
        return DB::transaction(function () use ($lotId, $data) {
            $namespace = 'inventory.lot.update.'.$lotId;
            if ($replay = $this->begin($namespace, $data + ['lot_id' => $lotId])) {
                return $replay;
            }

            $lot = $this->findLotLocked($lotId, $data['company_id']);
            $this->assertVersion('lot', $lot, $data['expected_version']);
            $data['status'] = $lot->status;
            $this->validateLot($data);
            if ($lot->item_id !== $data['item_id'] && (
                DB::table('stock_positions')->where('lot_id', $lotId)->exists()
                || DB::table('shipment_lines')->where('fg_lot_id', $lotId)->exists()
            )) {
                throw ValidationException::withMessages([
                    'item_id' => ['A referenced lot cannot be reassigned to another SKU.'],
                ]);
            }
            if ($this->dateIsPast($data['expiry_date'] ?? null)
                && DB::table('stock_reservations')->whereIn('stock_position_id', function ($query) use ($lotId) {
                    $query->select('id')->from('stock_positions')->where('lot_id', $lotId);
                })->where('status', 'ACTIVE')->exists()) {
                throw ValidationException::withMessages([
                    'expiry_date' => ['Release active reservations before changing this lot to an expired date.'],
                ]);
            }

            $version = (int) $lot->record_version + 1;
            DB::table('lots')->where('id', $lotId)->update([
                'item_id' => $data['item_id'],
                'supplier_party_id' => $data['supplier_party_id'] ?? null,
                'supplier_lot_code' => $this->nullable($data['supplier_lot_code'] ?? null),
                'origin_type' => $data['origin_type'],
                'manufacture_date' => $data['manufacture_date'] ?? null,
                'expiry_date' => $data['expiry_date'] ?? null,
                'notes' => $this->nullable($data['notes'] ?? null),
                'record_version' => $version,
                'updated_at' => now(),
            ]);
            $result = $this->result('inventory_lot', $lotId, (string) $lot->status, $version);
            $this->record('UPDATE_INVENTORY_LOT', 'inventory.lot.updated', 'inventory_lot', $lotId,
                $data, $version, [
                    'item_id' => ['from' => $lot->item_id, 'to' => $data['item_id']],
                    'supplier_party_id' => ['from' => $lot->supplier_party_id, 'to' => $data['supplier_party_id'] ?? null],
                    'origin_type' => ['from' => $lot->origin_type, 'to' => $data['origin_type']],
                    'manufacture_date' => ['from' => $lot->manufacture_date, 'to' => $data['manufacture_date'] ?? null],
                    'expiry_date' => ['from' => $lot->expiry_date, 'to' => $data['expiry_date'] ?? null],
                ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function changeLotStatus(string $lotId, string $targetStatus, string $reason, array $data): array
    {
        return DB::transaction(function () use ($lotId, $targetStatus, $reason, $data) {
            $namespace = 'inventory.lot.status.'.$lotId;
            $payload = $data + ['lot_id' => $lotId, 'target_status' => $targetStatus, 'reason' => $reason];
            if ($replay = $this->begin($namespace, $payload)) {
                return $replay;
            }

            $lot = $this->findLotLocked($lotId, $data['company_id']);
            $this->assertVersion('lot', $lot, $data['expected_version']);
            if (! in_array($targetStatus, self::LOT_TRANSITIONS[$lot->status] ?? [], true)) {
                throw ValidationException::withMessages([
                    'target_status' => ["A lot cannot move from {$lot->status} to {$targetStatus}."],
                ]);
            }
            if ($targetStatus === 'ACTIVE') {
                $this->validateLot([
                    ...$data,
                    'item_id' => $lot->item_id,
                    'supplier_party_id' => $lot->supplier_party_id,
                    'manufacture_date' => $lot->manufacture_date,
                    'expiry_date' => $lot->expiry_date,
                    'status' => 'ACTIVE',
                ]);
            }
            if ($targetStatus === 'CLOSED' && DB::table('stock_positions')->where('lot_id', $lotId)
                ->where('quantity_base', '>', 0)->exists()) {
                throw ValidationException::withMessages([
                    'target_status' => ['A lot cannot be closed while stock remains.'],
                ]);
            }

            $version = (int) $lot->record_version + 1;
            DB::table('lots')->where('id', $lotId)->update([
                'status' => $targetStatus,
                'status_reason' => $reason,
                'status_changed_at' => now(),
                'status_changed_by' => $data['actor_id'],
                'record_version' => $version,
                'updated_at' => now(),
            ]);
            $result = $this->result('inventory_lot', $lotId, $targetStatus, $version);
            $this->record('CHANGE_INVENTORY_LOT_STATUS', 'inventory.lot.status_changed', 'inventory_lot',
                $lotId, $data, $version, [
                    'status' => ['from' => $lot->status, 'to' => $targetStatus],
                    'reason' => $reason,
                ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function deleteLot(string $lotId, array $data): array
    {
        return DB::transaction(function () use ($lotId, $data) {
            $namespace = 'inventory.lot.delete.'.$lotId;
            if ($replay = $this->begin($namespace, $data + ['lot_id' => $lotId])) {
                return $replay;
            }

            $lot = $this->findLotLocked($lotId, $data['company_id']);
            $this->assertVersion('lot', $lot, $data['expected_version']);
            if (DB::table('stock_positions')->where('lot_id', $lotId)->exists()) {
                throw ValidationException::withMessages([
                    'lot' => ['This lot has stock-position history and cannot be deleted. Close it instead.'],
                ]);
            }
            try {
                DB::table('lots')->where('id', $lotId)->delete();
            } catch (QueryException) {
                throw ValidationException::withMessages([
                    'lot' => ['This lot is used by another ERP entry and cannot be deleted. Close it instead.'],
                ]);
            }

            $version = (int) $lot->record_version + 1;
            $result = $this->result('inventory_lot', $lotId, 'DELETED', $version) + ['deleted' => true];
            $this->record('DELETE_INVENTORY_LOT', 'inventory.lot.deleted', 'inventory_lot',
                $lotId, $data, $version,
                ['deleted' => ['internal_lot_code' => $lot->internal_lot_code]], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function reserve(string $positionId, array $data): array
    {
        return DB::transaction(function () use ($positionId, $data) {
            $namespace = 'inventory.reservation.create.'.$positionId;
            if ($replay = $this->begin($namespace, $data + ['position_id' => $positionId])) {
                return $replay;
            }

            $position = $this->findPositionLocked($positionId, $data['company_id'], $data['plant_id']);
            $this->assertVersion('stock position', $position, $data['expected_version']);
            $quantity = $this->positiveQuantity($data['quantity_base']);
            $this->assertReservationProjection($position);
            $this->assertPositionReservable($position);
            $available = bcsub((string) $position->quantity_base, (string) $position->reserved_quantity_base, 6);
            if (bccomp($available, $quantity, 6) < 0) {
                throw ValidationException::withMessages([
                    'quantity_base' => ['Reservation quantity exceeds the currently available stock.'],
                ]);
            }
            if (DB::table('stock_reservations')->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])->where('reservation_number', $data['reservation_number'])->exists()) {
                throw ValidationException::withMessages([
                    'reservation_number' => ['That reservation number already exists in the selected plant.'],
                ]);
            }

            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('stock_reservations')->insert([
                'id' => $id,
                'company_id' => $data['company_id'],
                'plant_id' => $data['plant_id'],
                'stock_position_id' => $positionId,
                'reservation_number' => $data['reservation_number'],
                'quantity_base' => $quantity,
                'status' => 'ACTIVE',
                'purpose' => trim($data['purpose']),
                'record_version' => 1,
                'created_by' => $data['actor_id'],
                'released_at' => null,
                'released_by' => null,
                'release_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $positionVersion = (int) $position->record_version + 1;
            DB::table('stock_positions')->where('id', $positionId)->update([
                'reserved_quantity_base' => bcadd((string) $position->reserved_quantity_base, $quantity, 6),
                'record_version' => $positionVersion,
                'updated_at' => $now,
            ]);

            $result = [
                ...$this->result('stock_reservation', $id, 'ACTIVE', 1),
                'stock_position_id' => $positionId,
                'position_record_version' => $positionVersion,
                'reserved_quantity' => bcadd((string) $position->reserved_quantity_base, $quantity, 6),
            ];
            $this->record('CREATE_STOCK_RESERVATION', 'inventory.reservation.created', 'stock_reservation',
                $id, $data, 1, ['created' => [
                    'reservation_number' => $data['reservation_number'],
                    'stock_position_id' => $positionId,
                    'quantity_base' => $quantity,
                ]], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function releaseReservation(string $reservationId, string $reason, array $data): array
    {
        return DB::transaction(function () use ($reservationId, $reason, $data) {
            $namespace = 'inventory.reservation.release.'.$reservationId;
            $payload = $data + ['reservation_id' => $reservationId, 'reason' => $reason];
            if ($replay = $this->begin($namespace, $payload)) {
                return $replay;
            }

            $reservation = DB::table('stock_reservations')->where('id', $reservationId)
                ->where('company_id', $data['company_id'])->where('plant_id', $data['plant_id'])
                ->lockForUpdate()->first();
            if (! $reservation) {
                throw new NotFoundHttpException('Stock reservation not found.');
            }
            $this->assertVersion('stock reservation', $reservation, $data['expected_version']);
            if ($reservation->status !== 'ACTIVE') {
                throw ValidationException::withMessages([
                    'status' => ['Only an active reservation can be released.'],
                ]);
            }
            $position = $this->findPositionLocked(
                (string) $reservation->stock_position_id,
                $data['company_id'],
                $data['plant_id'],
            );
            $this->assertReservationProjection($position);
            if (bccomp((string) $position->reserved_quantity_base, (string) $reservation->quantity_base, 6) < 0) {
                throw new ConflictHttpException('The stock reservation projection is inconsistent. Reconcile it before release.');
            }

            $version = (int) $reservation->record_version + 1;
            $positionVersion = (int) $position->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table('stock_reservations')->where('id', $reservationId)->update([
                'status' => 'RELEASED',
                'record_version' => $version,
                'released_at' => $now,
                'released_by' => $data['actor_id'],
                'release_reason' => $reason,
                'updated_at' => $now,
            ]);
            $remaining = bcsub((string) $position->reserved_quantity_base, (string) $reservation->quantity_base, 6);
            DB::table('stock_positions')->where('id', $position->id)->update([
                'reserved_quantity_base' => $remaining,
                'record_version' => $positionVersion,
                'updated_at' => $now,
            ]);

            $result = [
                ...$this->result('stock_reservation', $reservationId, 'RELEASED', $version),
                'stock_position_id' => (string) $position->id,
                'position_record_version' => $positionVersion,
                'reserved_quantity' => $remaining,
            ];
            $this->record('RELEASE_STOCK_RESERVATION', 'inventory.reservation.released', 'stock_reservation',
                $reservationId, $data, $version, [
                    'status' => ['from' => 'ACTIVE', 'to' => 'RELEASED'],
                    'release_reason' => $reason,
                    'quantity_base' => (string) $reservation->quantity_base,
                ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    private function validateOwner(array $data): void
    {
        $partyId = $data['party_id'] ?? null;
        if ($data['owner_type'] === 'COMPANY' && $partyId !== null) {
            throw ValidationException::withMessages(['party_id' => ['Company-owned stock cannot reference a party.']]);
        }
        if ($data['owner_type'] === 'PARTY' && $partyId === null) {
            throw ValidationException::withMessages(['party_id' => ['Party-owned stock requires a party.']]);
        }
        if ($partyId !== null) {
            $party = DB::table('parties')->where('id', $partyId)->where('company_id', $data['company_id'])
                ->first(['status']);
            if (! $party) {
                throw ValidationException::withMessages(['party_id' => ['Select a party from the current company.']]);
            }
            if ($data['status'] === 'ACTIVE' && $party->status !== 'ACTIVE') {
                throw ValidationException::withMessages(['party_id' => ['An active inventory owner requires an active party.']]);
            }
        }
    }

    private function validateLot(array $data): void
    {
        $item = DB::table('items as sku')->join('catalog_items as item', 'item.id', '=', 'sku.catalog_item_id')
            ->where('sku.id', $data['item_id'])->where('sku.company_id', $data['company_id'])
            ->first(['sku.status', 'item.lot_controlled', 'item.shelf_life_days']);
        if (! $item) {
            throw ValidationException::withMessages(['item_id' => ['Select a SKU from the current company.']]);
        }
        if (! (bool) $item->lot_controlled) {
            throw ValidationException::withMessages(['item_id' => ['The selected SKU is not lot controlled.']]);
        }
        if ($data['status'] === 'ACTIVE' && $item->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['item_id' => ['An active lot requires an active SKU.']]);
        }

        $manufactured = $data['manufacture_date'] ?? null;
        $expires = $data['expiry_date'] ?? null;
        if ($manufactured && $expires && CarbonImmutable::parse($expires)->lt(CarbonImmutable::parse($manufactured))) {
            throw ValidationException::withMessages([
                'expiry_date' => ['Expiry date must be on or after the manufacture date.'],
            ]);
        }
        if ($data['status'] === 'ACTIVE' && $item->shelf_life_days !== null && ! $expires) {
            throw ValidationException::withMessages([
                'expiry_date' => ['An active lot for this SKU requires an expiry date.'],
            ]);
        }
        if ($data['status'] === 'ACTIVE' && $this->dateIsPast($expires)) {
            throw ValidationException::withMessages([
                'expiry_date' => ['An expired lot cannot be activated.'],
            ]);
        }

        $supplierId = $data['supplier_party_id'] ?? null;
        if ($data['status'] === 'ACTIVE' && $data['origin_type'] === 'PURCHASE' && $supplierId === null) {
            throw ValidationException::withMessages([
                'supplier_party_id' => ['An active purchased lot requires an active supplier.'],
            ]);
        }
        if ($supplierId !== null) {
            $supplier = DB::table('parties')->where('id', $supplierId)
                ->where('company_id', $data['company_id'])->where('status', 'ACTIVE')->exists();
            $hasRole = DB::table('party_roles')->where('party_id', $supplierId)
                ->where('company_id', $data['company_id'])->where('role_code', 'SUPPLIER')->exists();
            if (! $supplier || ! $hasRole) {
                throw ValidationException::withMessages([
                    'supplier_party_id' => ['Select an active supplier from the current company.'],
                ]);
            }
        }
    }

    private function assertPositionReservable(object $position): void
    {
        $quality = DB::table('inventory_quality_statuses')->where('code', $position->quality_status)
            ->first(['is_reservable']);
        $lot = DB::table('lots')->where('id', $position->lot_id)->where('company_id', $position->company_id)
            ->first(['status', 'expiry_date']);
        $owner = DB::table('inventory_owners')->where('id', $position->inventory_owner_id)
            ->where('company_id', $position->company_id)->first(['status']);
        if (! $quality || ! $quality->is_reservable) {
            throw ValidationException::withMessages([
                'stock_position_id' => ['The position quality status does not permit reservations.'],
            ]);
        }
        if (! $lot || $lot->status !== 'ACTIVE' || $this->dateIsPast($lot->expiry_date)) {
            throw ValidationException::withMessages([
                'stock_position_id' => ['Only stock in an active, unexpired lot can be reserved.'],
            ]);
        }
        if (! $owner || $owner->status !== 'ACTIVE') {
            throw ValidationException::withMessages([
                'stock_position_id' => ['Only stock assigned to an active inventory owner can be reserved.'],
            ]);
        }
    }

    private function assertReservationProjection(object $position): void
    {
        $sum = (string) (DB::table('stock_reservations')->where('stock_position_id', $position->id)
            ->where('status', 'ACTIVE')->sum('quantity_base') ?: '0');
        if (bccomp($sum, (string) $position->reserved_quantity_base, 6) !== 0) {
            throw new ConflictHttpException('The stock reservation projection is inconsistent. Reconcile it before continuing.');
        }
    }

    private function assertOwnerCodeAvailable(string $companyId, string $code): void
    {
        if (DB::table('inventory_owners')->where('company_id', $companyId)->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => ['That inventory-owner code already exists in this company.']]);
        }
    }

    private function assertOwnerIdentityAvailable(
        string $companyId,
        string $ownerType,
        ?string $partyId,
        ?string $ignoreId = null,
    ): void {
        $query = DB::table('inventory_owners')->where('company_id', $companyId);
        if ($ownerType === 'COMPANY') {
            $query->where('owner_type', 'COMPANY');
        } else {
            $query->where('party_id', $partyId);
        }
        if ($ignoreId !== null) {
            $query->where('id', '<>', $ignoreId);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([
                $ownerType === 'COMPANY' ? 'owner_type' : 'party_id' => [
                    $ownerType === 'COMPANY'
                        ? 'The company-owned inventory owner already exists.'
                        : 'That party already has an inventory-owner record.',
                ],
            ]);
        }
    }

    private function findOwnerLocked(string $ownerId, string $companyId): object
    {
        $owner = DB::table('inventory_owners')->where('id', $ownerId)->where('company_id', $companyId)
            ->lockForUpdate()->first();
        if (! $owner) {
            throw new NotFoundHttpException('Inventory owner not found.');
        }

        return $owner;
    }

    private function findLotLocked(string $lotId, string $companyId): object
    {
        $lot = DB::table('lots')->where('id', $lotId)->where('company_id', $companyId)
            ->lockForUpdate()->first();
        if (! $lot) {
            throw new NotFoundHttpException('Lot not found.');
        }

        return $lot;
    }

    private function findPositionLocked(string $positionId, string $companyId, string $plantId): object
    {
        $position = DB::table('stock_positions')->where('id', $positionId)
            ->where('company_id', $companyId)->where('plant_id', $plantId)->lockForUpdate()->first();
        if (! $position) {
            throw new NotFoundHttpException('Stock position not found.');
        }

        return $position;
    }

    private function assertVersion(string $label, object $record, int $expectedVersion): void
    {
        if ((int) $record->record_version !== $expectedVersion) {
            throw new ConflictHttpException(
                "The {$label} changed from version {$expectedVersion} to {$record->record_version}. Refresh it before saving."
            );
        }
    }

    private function positiveQuantity(mixed $quantity): string
    {
        $value = (string) $quantity;
        if (! preg_match('/^\d+(?:\.\d{1,6})?$/', $value) || bccomp($value, '0', 6) <= 0) {
            throw ValidationException::withMessages([
                'quantity_base' => ['Quantity must be positive with at most 6 decimal places.'],
            ]);
        }

        return bcadd($value, '0', 6);
    }

    private function dateIsPast(mixed $date): bool
    {
        return $date !== null && $date !== '' && CarbonImmutable::parse((string) $date)->isBefore(today());
    }

    private function result(string $entityType, string $id, string $status, int $version): array
    {
        return [
            'entity_type' => $entityType,
            'id' => $id,
            'status' => $status,
            'record_version' => $version,
        ];
    }

    private function record(
        string $command,
        string $event,
        string $entityType,
        string $id,
        array $data,
        int $version,
        array $safeDiff,
        array $result,
    ): void {
        $this->audit->record($command, $entityType, $id, $data['actor_id'],
            $data['company_id'], $data['plant_id'], 'SUCCESS', [
                'entity_version' => $version,
                'correlation_id' => $data['correlation_id'] ?? null,
                'safe_diff' => $safeDiff,
            ]);
        $this->outbox->append($event, $entityType, $id, $id.':'.$version, $result,
            $data['correlation_id'] ?? null, $data['company_id'], $data['plant_id']);
    }

    private function begin(string $namespace, array $data): ?array
    {
        return $this->idempotency->begin($namespace, $data['idempotency_key'], $this->idempotencyPayload($data));
    }

    private function idempotencyPayload(array $data): array
    {
        return collect($data)->except(['actor_id', 'permissions', 'idempotency_key', 'correlation_id'])->all();
    }

    private function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}

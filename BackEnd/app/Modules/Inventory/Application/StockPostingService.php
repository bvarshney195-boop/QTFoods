<?php

namespace App\Modules\Inventory\Application;

use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class StockPostingService
{
    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    public function move(array $command): array
    {
        return DB::transaction(function () use ($command) {
            $namespace = 'inventory.movement';
            $key = $command['idempotency_key'];

            $qty = $this->positiveQuantity($command['quantity_base']);

            if ($command['source_position_id'] === $command['target_position_id']) {
                throw ValidationException::withMessages([
                    'target_position_id' => ['Source and target stock positions must be different.'],
                ]);
            }

            if ($existing = $this->idempotency->begin($namespace, $key, $this->idempotencyPayload($command))) {
                return $existing;
            }

            $positions = DB::table('stock_positions')
                ->whereIn('id', [$command['source_position_id'], $command['target_position_id']])
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');
            $source = $positions->get($command['source_position_id']);
            $target = $positions->get($command['target_position_id']);

            if (! $source || ! $target) {
                throw ValidationException::withMessages(['position' => ['Stock position not found.']]);
            }

            if (
                $source->company_id !== $command['company_id']
                || $target->company_id !== $command['company_id']
                || $source->plant_id !== $command['plant_id']
                || $target->plant_id !== $command['plant_id']
                || $source->item_id !== $target->item_id
                || $source->lot_id !== $target->lot_id
                || $source->inventory_owner_id !== $target->inventory_owner_id
                || $source->uom_code !== $target->uom_code
                || $source->uom_code !== $command['uom_code']
                || (isset($command['expected_item_id']) && $source->item_id !== $command['expected_item_id'])
                || (isset($command['expected_lot_id']) && $source->lot_id !== $command['expected_lot_id'])
                || (isset($command['expected_owner_id']) && $source->inventory_owner_id !== $command['expected_owner_id'])
                || (isset($command['expected_source_quality_status']) && $source->quality_status !== $command['expected_source_quality_status'])
                || (isset($command['expected_target_quality_status']) && $target->quality_status !== $command['expected_target_quality_status'])
            ) {
                throw ValidationException::withMessages([
                    'position' => ['Stock positions must belong to the command company and plant and use the same item, lot, owner, and UOM.'],
                ]);
            }

            $unreserved = bcsub(
                (string) $source->quantity_base,
                (string) ($source->reserved_quantity_base ?? 0),
                6,
            );
            if (bccomp($unreserved, $qty, 6) < 0) {
                throw ValidationException::withMessages([
                    'quantity' => ['Insufficient eligible stock at commit time.'],
                ]);
            }

            $movementId = (string) Str::uuid();

            DB::table('stock_positions')->where('id', $source->id)->update([
                'quantity_base' => bcsub((string) $source->quantity_base, $qty, 6),
                'record_version' => $source->record_version + 1,
                'updated_at' => now(),
            ]);

            DB::table('stock_positions')->where('id', $target->id)->update([
                'quantity_base' => bcadd((string) $target->quantity_base, $qty, 6),
                'record_version' => $target->record_version + 1,
                'updated_at' => now(),
            ]);

            DB::table('stock_movements')->insert([
                'id' => $movementId,
                'company_id' => $command['company_id'],
                'plant_id' => $command['plant_id'] ?? null,
                'movement_type' => $command['movement_type'],
                'source_type' => $command['source_type'],
                'source_id' => $command['source_id'],
                'source_version' => $command['source_version'] ?? null,
                'from_position_id' => $source->id,
                'to_position_id' => $target->id,
                'quantity_base' => $qty,
                'uom_code' => $command['uom_code'],
                'actor_id' => $command['actor_id'],
                'reason_code' => $command['reason_code'] ?? null,
                'idempotency_key' => $key,
                'event_at' => $command['event_at'] ?? now(),
                'posted_at' => now(),
                'created_at' => now(),
            ]);

            $this->audit->record(
                'POST_STOCK_MOVEMENT',
                'stock_movement',
                $movementId,
                $command['actor_id'],
                $command['company_id'],
                $command['plant_id'] ?? null,
                'SUCCESS',
                [
                    'entity_version' => 1,
                    'correlation_id' => $command['correlation_id'] ?? null,
                    'reason_code' => $command['reason_code'] ?? null,
                ]
            );

            $this->outbox->append(
                'inventory.movement.posted',
                'stock_movement',
                $movementId,
                $key,
                ['movement_id' => $movementId, 'quantity_base' => $qty],
                $command['correlation_id'] ?? null
            );

            $result = [
                'movement_id' => $movementId,
                'posted_quantity' => $qty,
                'uom' => $command['uom_code'],
                'posted_at' => now()->toISOString(),
            ];

            $this->idempotency->complete($namespace, $key, $result);

            return $result;
        }, 3);
    }

    public function issue(array $command): array
    {
        return DB::transaction(function () use ($command) {
            $namespace = 'inventory.movement';
            $key = $command['idempotency_key'];
            $qty = $this->positiveQuantity($command['quantity_base']);

            if ($existing = $this->idempotency->begin($namespace, $key, $this->idempotencyPayload($command))) {
                return $existing;
            }

            $source = DB::table('stock_positions')
                ->where('id', $command['source_position_id'])
                ->lockForUpdate()
                ->first();

            if (! $source) {
                throw ValidationException::withMessages(['source_position_id' => ['Stock position not found.']]);
            }

            if (
                $source->company_id !== $command['company_id']
                || $source->plant_id !== $command['plant_id']
                || $source->uom_code !== $command['uom_code']
                || (isset($command['expected_item_id']) && $source->item_id !== $command['expected_item_id'])
                || (isset($command['expected_lot_id']) && $source->lot_id !== $command['expected_lot_id'])
                || (isset($command['expected_owner_id']) && $source->inventory_owner_id !== $command['expected_owner_id'])
                || (isset($command['expected_quality_status']) && $source->quality_status !== $command['expected_quality_status'])
            ) {
                throw ValidationException::withMessages([
                    'source_position_id' => [
                        'The source position must match the command company, plant, item, lot, quality status, and UOM.',
                    ],
                ]);
            }

            $unreserved = bcsub(
                (string) $source->quantity_base,
                (string) ($source->reserved_quantity_base ?? 0),
                6,
            );
            if (bccomp($unreserved, $qty, 6) < 0) {
                throw ValidationException::withMessages([
                    'quantity' => ['Insufficient eligible stock at commit time.'],
                ]);
            }

            $movementId = (string) Str::uuid();

            DB::table('stock_positions')->where('id', $source->id)->update([
                'quantity_base' => bcsub((string) $source->quantity_base, $qty, 6),
                'record_version' => $source->record_version + 1,
                'updated_at' => now(),
            ]);

            DB::table('stock_movements')->insert([
                'id' => $movementId,
                'company_id' => $command['company_id'],
                'plant_id' => $command['plant_id'],
                'movement_type' => $command['movement_type'],
                'source_type' => $command['source_type'],
                'source_id' => $command['source_id'],
                'source_version' => $command['source_version'] ?? null,
                'from_position_id' => $source->id,
                'to_position_id' => null,
                'quantity_base' => $qty,
                'uom_code' => $command['uom_code'],
                'actor_id' => $command['actor_id'],
                'reason_code' => $command['reason_code'] ?? null,
                'idempotency_key' => $key,
                'event_at' => $command['event_at'] ?? now(),
                'posted_at' => now(),
                'created_at' => now(),
            ]);

            $this->audit->record(
                'POST_STOCK_MOVEMENT',
                'stock_movement',
                $movementId,
                $command['actor_id'],
                $command['company_id'],
                $command['plant_id'],
                'SUCCESS',
                [
                    'entity_version' => 1,
                    'correlation_id' => $command['correlation_id'] ?? null,
                    'reason_code' => $command['reason_code'] ?? null,
                ]
            );

            $this->outbox->append(
                'inventory.movement.posted',
                'stock_movement',
                $movementId,
                $key,
                [
                    'movement_id' => $movementId,
                    'source_position_id' => (string) $source->id,
                    'target_position_id' => null,
                    'quantity_base' => $qty,
                ],
                $command['correlation_id'] ?? null
            );

            $result = [
                'movement_id' => $movementId,
                'posted_quantity' => $qty,
                'uom' => $command['uom_code'],
                'posted_at' => now()->toISOString(),
            ];

            $this->idempotency->complete($namespace, $key, $result);

            return $result;
        }, 3);
    }

    /**
     * Post stock into an existing, fully classified position.
     *
     * Position creation and quality/lot/owner eligibility deliberately remain the
     * responsibility of the calling workflow. This primitive only performs the
     * locked, scoped and idempotent ledger mutation.
     */
    public function receive(array $command): array
    {
        return DB::transaction(function () use ($command) {
            $namespace = 'inventory.movement';
            $key = $command['idempotency_key'];
            $qty = $this->positiveQuantity($command['quantity_base']);

            if ($existing = $this->idempotency->begin($namespace, $key, $this->idempotencyPayload($command))) {
                return $existing;
            }

            $target = DB::table('stock_positions')
                ->where('id', $command['target_position_id'])
                ->lockForUpdate()
                ->first();

            if (! $target) {
                throw ValidationException::withMessages(['target_position_id' => ['Stock position not found.']]);
            }

            if (
                $target->company_id !== $command['company_id']
                || $target->plant_id !== $command['plant_id']
                || $target->uom_code !== $command['uom_code']
                || (isset($command['expected_item_id']) && $target->item_id !== $command['expected_item_id'])
                || (isset($command['expected_lot_id']) && $target->lot_id !== $command['expected_lot_id'])
                || (isset($command['expected_owner_id']) && $target->inventory_owner_id !== $command['expected_owner_id'])
                || (isset($command['expected_quality_status']) && $target->quality_status !== $command['expected_quality_status'])
            ) {
                throw ValidationException::withMessages([
                    'target_position_id' => [
                        'The target position must match the command company, plant, item, lot, owner, quality status, and UOM.',
                    ],
                ]);
            }

            $movementId = (string) Str::uuid();
            DB::table('stock_positions')->where('id', $target->id)->update([
                'quantity_base' => bcadd((string) $target->quantity_base, $qty, 6),
                'record_version' => $target->record_version + 1,
                'updated_at' => now(),
            ]);

            DB::table('stock_movements')->insert([
                'id' => $movementId,
                'company_id' => $command['company_id'],
                'plant_id' => $command['plant_id'],
                'movement_type' => $command['movement_type'],
                'source_type' => $command['source_type'],
                'source_id' => $command['source_id'],
                'source_version' => $command['source_version'] ?? null,
                'from_position_id' => null,
                'to_position_id' => $target->id,
                'quantity_base' => $qty,
                'uom_code' => $command['uom_code'],
                'actor_id' => $command['actor_id'],
                'reason_code' => $command['reason_code'] ?? null,
                'idempotency_key' => $key,
                'event_at' => $command['event_at'] ?? now(),
                'posted_at' => now(),
                'created_at' => now(),
            ]);

            $this->audit->record(
                'POST_STOCK_MOVEMENT',
                'stock_movement',
                $movementId,
                $command['actor_id'],
                $command['company_id'],
                $command['plant_id'],
                'SUCCESS',
                [
                    'entity_version' => 1,
                    'correlation_id' => $command['correlation_id'] ?? null,
                    'reason_code' => $command['reason_code'] ?? null,
                ]
            );

            $this->outbox->append(
                'inventory.movement.posted',
                'stock_movement',
                $movementId,
                $key,
                [
                    'movement_id' => $movementId,
                    'source_position_id' => null,
                    'target_position_id' => (string) $target->id,
                    'quantity_base' => $qty,
                ],
                $command['correlation_id'] ?? null,
                $command['company_id'],
                $command['plant_id'],
            );

            $result = [
                'movement_id' => $movementId,
                'posted_quantity' => $qty,
                'uom' => $command['uom_code'],
                'posted_at' => now()->toISOString(),
            ];

            $this->idempotency->complete($namespace, $key, $result);

            return $result;
        }, 3);
    }

    private function positiveQuantity(mixed $quantity): string
    {
        $qty = (string) $quantity;
        if (! preg_match('/^\d+(?:\.\d{1,6})?$/', $qty) || bccomp($qty, '0', 6) <= 0) {
            throw ValidationException::withMessages([
                'quantity_base' => ['Movement quantity must be a positive number with at most 6 decimal places.'],
            ]);
        }

        return $qty;
    }

    private function idempotencyPayload(array $command): array
    {
        return Arr::except($command, ['correlation_id']);
    }
}

<?php

namespace App\Modules\Inventory\Application;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class InventoryFoundationQuery
{
    public const STOCK_SORTS = ['SKU', 'LOT', 'EXPIRY', 'QUANTITY_DESC', 'UPDATED'];
    public const OWNER_SORTS = ['CODE', 'NAME', 'NEWEST', 'OLDEST'];
    public const LOT_SORTS = ['CODE', 'SKU', 'EXPIRY', 'NEWEST', 'OLDEST'];
    public const AVAILABILITY_FILTERS = ['AVAILABLE', 'BLOCKED', 'RESERVED', 'EXPIRED', 'EXPIRING_30'];

    public function stockWorkspace(array $scope, array $filters, array $permissions): array
    {
        $base = $this->stockBase($scope);
        $query = clone $base;
        $this->applyStockFilters($query, $filters);
        $this->applyStockSort($query, $filters['sort'] ?? 'SKU');

        $positions = $query->select($this->stockFields())->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($positions->items())->map(
                fn (object $position) => $this->stockPayload($position, $permissions),
            )->all(),
            'meta' => $this->meta($positions),
            'summary' => $this->stockSummary($scope),
            'lookups' => $this->stockLookups($scope),
            'allowed_actions' => [],
        ];
    }

    public function stockDetail(string $positionId, array $scope, array $permissions): array
    {
        $position = $this->stockBase($scope)->where('position.id', $positionId)
            ->first($this->stockFields());
        if (! $position) {
            throw new NotFoundHttpException('Stock position not found.');
        }
        $payload = $this->stockPayload($position, $permissions);
        $payload['reservations'] = DB::table('stock_reservations as reservation')
            ->join('users as creator', 'creator.id', '=', 'reservation.created_by')
            ->leftJoin('users as releaser', 'releaser.id', '=', 'reservation.released_by')
            ->where('reservation.stock_position_id', $positionId)
            ->where('reservation.company_id', $scope['company_id'])
            ->where('reservation.plant_id', $scope['plant_id'])
            ->orderByDesc('reservation.created_at')
            ->get([
                'reservation.*', 'creator.name as creator_name', 'releaser.name as releaser_name',
            ])->map(fn (object $reservation) => $this->reservation($reservation, $permissions))->all();

        return $payload;
    }

    public function ownerWorkspace(array $scope, array $filters, array $permissions): array
    {
        $base = DB::table('inventory_owners as inventory_owner')
            ->leftJoin('parties as party', 'party.id', '=', 'inventory_owner.party_id')
            ->leftJoin('users as status_actor', 'status_actor.id', '=', 'inventory_owner.status_changed_by')
            ->where('inventory_owner.company_id', $scope['company_id']);
        $query = clone $base;
        $this->applyOwnerFilters($query, $filters);
        $this->applyOwnerSort($query, $filters['sort'] ?? 'CODE');
        $owners = $query->select([
            'inventory_owner.*', 'party.code as party_code', 'party.display_name as party_name',
            'status_actor.name as status_actor_name',
        ])->paginate((int) ($filters['per_page'] ?? 25));
        $items = collect($owners->items());
        $metrics = $this->positionMetrics('inventory_owner_id', $items->pluck('id')->all(), $scope['plant_id']);

        return [
            'data' => $items->map(fn (object $owner) => $this->ownerPayload(
                $owner, $metrics->get((string) $owner->id), $permissions,
            ))->all(),
            'meta' => $this->meta($owners),
            'summary' => [
                'total' => (clone $base)->count('inventory_owner.id'),
                'draft' => (clone $base)->where('inventory_owner.status', 'DRAFT')->count('inventory_owner.id'),
                'active' => (clone $base)->where('inventory_owner.status', 'ACTIVE')->count('inventory_owner.id'),
                'inactive' => (clone $base)->where('inventory_owner.status', 'INACTIVE')->count('inventory_owner.id'),
                'party_owned' => (clone $base)->where('inventory_owner.owner_type', 'PARTY')->count('inventory_owner.id'),
            ],
            'lookups' => [
                'statuses' => InventoryFoundationService::OWNER_STATUSES,
                'owner_types' => InventoryFoundationService::OWNER_TYPES,
                'parties' => $this->partyLookups($scope['company_id']),
            ],
            'allowed_actions' => $this->can($permissions, 'ACTION:INV-STK:OWNER-CREATE') ? ['CREATE'] : [],
        ];
    }

    public function ownerDetail(string $ownerId, array $scope, array $permissions): array
    {
        $owner = DB::table('inventory_owners as inventory_owner')
            ->leftJoin('parties as party', 'party.id', '=', 'inventory_owner.party_id')
            ->leftJoin('users as status_actor', 'status_actor.id', '=', 'inventory_owner.status_changed_by')
            ->where('inventory_owner.company_id', $scope['company_id'])->where('inventory_owner.id', $ownerId)
            ->first([
                'inventory_owner.*', 'party.code as party_code', 'party.display_name as party_name',
                'status_actor.name as status_actor_name',
            ]);
        if (! $owner) {
            throw new NotFoundHttpException('Inventory owner not found.');
        }
        $metrics = $this->positionMetrics('inventory_owner_id', [$ownerId], $scope['plant_id'])->get($ownerId);
        $payload = $this->ownerPayload($owner, $metrics, $permissions);
        $payload['quality_totals'] = $this->qualityTotals('inventory_owner_id', $ownerId, $scope['plant_id']);

        return $payload;
    }

    public function lotWorkspace(array $scope, array $filters, array $permissions): array
    {
        $base = $this->lotBase($scope['company_id']);
        $query = clone $base;
        $this->applyLotFilters($query, $filters);
        $this->applyLotSort($query, $filters['sort'] ?? 'CODE');
        $lots = $query->select($this->lotFields())->paginate((int) ($filters['per_page'] ?? 25));
        $items = collect($lots->items());
        $metrics = $this->positionMetrics('lot_id', $items->pluck('id')->all(), $scope['plant_id']);
        $today = today()->toDateString();
        $soon = today()->addDays(30)->toDateString();

        return [
            'data' => $items->map(fn (object $lot) => $this->lotPayload(
                $lot, $metrics->get((string) $lot->id), $permissions,
            ))->all(),
            'meta' => $this->meta($lots),
            'summary' => [
                'total' => (clone $base)->count('lot.id'),
                'draft' => (clone $base)->where('lot.status', 'DRAFT')->count('lot.id'),
                'active' => (clone $base)->where('lot.status', 'ACTIVE')->count('lot.id'),
                'closed' => (clone $base)->where('lot.status', 'CLOSED')->count('lot.id'),
                'recalled' => (clone $base)->where('lot.status', 'RECALLED')->count('lot.id'),
                'expired' => (clone $base)->whereNotNull('lot.expiry_date')->where('lot.expiry_date', '<', $today)
                    ->count('lot.id'),
                'expiring_30' => (clone $base)->whereBetween('lot.expiry_date', [$today, $soon])->count('lot.id'),
            ],
            'lookups' => [
                'statuses' => InventoryFoundationService::LOT_STATUSES,
                'origin_types' => InventoryFoundationService::LOT_ORIGINS,
                'skus' => $this->skuLookups($scope['company_id']),
                'suppliers' => $this->supplierLookups($scope['company_id']),
            ],
            'allowed_actions' => $this->can($permissions, 'ACTION:INV-STK:LOT-CREATE') ? ['CREATE'] : [],
        ];
    }

    public function lotDetail(string $lotId, array $scope, array $permissions): array
    {
        $lot = $this->lotBase($scope['company_id'])->where('lot.id', $lotId)->first($this->lotFields());
        if (! $lot) {
            throw new NotFoundHttpException('Lot not found.');
        }
        $metrics = $this->positionMetrics('lot_id', [$lotId], $scope['plant_id'])->get($lotId);
        $payload = $this->lotPayload($lot, $metrics, $permissions);
        $payload['quality_totals'] = $this->qualityTotals('lot_id', $lotId, $scope['plant_id']);

        return $payload;
    }

    private function stockBase(array $scope): Builder
    {
        return DB::table('stock_positions as position')
            ->join('items as sku', 'sku.id', '=', 'position.item_id')
            ->join('lots as lot', 'lot.id', '=', 'position.lot_id')
            ->join('inventory_owners as inventory_owner', 'inventory_owner.id', '=', 'position.inventory_owner_id')
            ->join('locations as location', 'location.id', '=', 'position.location_id')
            ->join('inventory_quality_statuses as quality', 'quality.code', '=', 'position.quality_status')
            ->leftJoin('parties as owner_party', 'owner_party.id', '=', 'inventory_owner.party_id')
            ->where('position.company_id', $scope['company_id'])
            ->where('position.plant_id', $scope['plant_id']);
    }

    private function stockFields(): array
    {
        return [
            'position.*', 'sku.code as sku_code', 'sku.name as sku_name',
            'lot.internal_lot_code', 'lot.status as lot_status', 'lot.manufacture_date', 'lot.expiry_date',
            'inventory_owner.code as owner_code', 'inventory_owner.name as owner_name',
            'inventory_owner.owner_type', 'inventory_owner.status as owner_status',
            'owner_party.id as owner_party_reference_id', 'owner_party.code as owner_party_code',
            'owner_party.display_name as owner_party_name',
            'location.code as location_code', 'location.name as location_name',
            'quality.name as quality_name', 'quality.availability_bucket', 'quality.is_reservable',
        ];
    }

    private function applyStockFilters(Builder $query, array $filters): void
    {
        if (! ($filters['include_zero'] ?? false)) {
            $query->where('position.quantity_base', '>', 0);
        }
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $query) use ($needle): void {
                foreach ([
                    'sku.code', 'sku.name', 'lot.internal_lot_code', 'lot.supplier_lot_code',
                    'inventory_owner.code', 'inventory_owner.name', 'location.code', 'location.name',
                ] as $column) {
                    $query->orWhereRaw("LOWER(COALESCE({$column}, '')) LIKE ?", [$needle]);
                }
            });
        }
        foreach ([
            'item_id' => 'position.item_id', 'lot_id' => 'position.lot_id',
            'owner_id' => 'position.inventory_owner_id', 'location_id' => 'position.location_id',
            'quality_status' => 'position.quality_status',
        ] as $filter => $column) {
            if (! empty($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }

        $today = today()->toDateString();
        $soon = today()->addDays(30)->toDateString();
        match ($filters['availability'] ?? null) {
            'AVAILABLE' => $query->where('quality.availability_bucket', 'AVAILABLE')
                ->where('lot.status', 'ACTIVE')
                ->where(fn (Builder $q) => $q->whereNull('lot.expiry_date')->orWhere('lot.expiry_date', '>=', $today))
                ->whereRaw('position.quantity_base > position.reserved_quantity_base'),
            'BLOCKED' => $query->where(function (Builder $q) use ($today): void {
                $q->where('quality.availability_bucket', 'BLOCKED')
                    ->orWhere('lot.status', '<>', 'ACTIVE')
                    ->orWhere('lot.expiry_date', '<', $today);
            })->whereRaw('position.quantity_base > position.reserved_quantity_base'),
            'RESERVED' => $query->where('position.reserved_quantity_base', '>', 0),
            'EXPIRED' => $query->whereNotNull('lot.expiry_date')->where('lot.expiry_date', '<', $today),
            'EXPIRING_30' => $query->whereBetween('lot.expiry_date', [$today, $soon]),
            default => null,
        };
    }

    private function applyStockSort(Builder $query, string $sort): void
    {
        match ($sort) {
            'LOT' => $query->orderBy('lot.internal_lot_code')->orderBy('sku.code'),
            'EXPIRY' => $query->orderByRaw('CASE WHEN lot.expiry_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('lot.expiry_date')->orderBy('sku.code'),
            'QUANTITY_DESC' => $query->orderByDesc('position.quantity_base')->orderBy('sku.code'),
            'UPDATED' => $query->orderByDesc('position.updated_at')->orderByDesc('position.id'),
            default => $query->orderBy('sku.code')->orderBy('lot.internal_lot_code')
                ->orderBy('location.code')->orderBy('position.quality_status'),
        };
    }

    private function stockPayload(object $position, array $permissions): array
    {
        $expired = $this->expired($position->expiry_date);
        $eligible = $position->availability_bucket === 'AVAILABLE'
            && $position->lot_status === 'ACTIVE' && ! $expired;
        $unreserved = bcsub((string) $position->quantity_base, (string) $position->reserved_quantity_base, 6);
        $available = $eligible ? $unreserved : '0.000000';
        $blocked = $eligible ? '0.000000' : $unreserved;
        $allowed = [];
        if ($this->can($permissions, 'ACTION:INV-STK:RESERVE')
            && (bool) $position->is_reservable && $eligible && bccomp($available, '0', 6) > 0
            && $position->owner_status === 'ACTIVE') {
            $allowed[] = 'RESERVE';
        }

        return [
            'id' => (string) $position->id,
            'company_id' => (string) $position->company_id,
            'plant_id' => (string) $position->plant_id,
            'sku' => $this->reference($position->item_id, $position->sku_code, $position->sku_name),
            'lot' => [
                'id' => (string) $position->lot_id,
                'code' => (string) $position->internal_lot_code,
                'status' => (string) $position->lot_status,
                'manufacture_date' => $this->date($position->manufacture_date),
                'expiry_date' => $this->date($position->expiry_date),
                'is_expired' => $expired,
                'days_to_expiry' => $this->daysToExpiry($position->expiry_date),
            ],
            'owner' => [
                'id' => (string) $position->inventory_owner_id,
                'code' => (string) $position->owner_code,
                'name' => (string) $position->owner_name,
                'type' => (string) $position->owner_type,
                'status' => (string) $position->owner_status,
                'party' => $position->owner_party_reference_id
                    ? $this->reference(
                        $position->owner_party_reference_id,
                        $position->owner_party_code,
                        $position->owner_party_name,
                    ) : null,
            ],
            'location' => $this->reference($position->location_id, $position->location_code, $position->location_name),
            'quality_status' => [
                'code' => (string) $position->quality_status,
                'name' => (string) $position->quality_name,
                'availability_bucket' => (string) $position->availability_bucket,
                'is_reservable' => (bool) $position->is_reservable,
            ],
            'stock_bucket' => $eligible ? 'AVAILABLE' : 'BLOCKED',
            'quantity' => [
                'total' => $this->decimal($position->quantity_base),
                'available' => $this->decimal($available),
                'blocked' => $this->decimal($blocked),
                'reserved' => $this->decimal($position->reserved_quantity_base),
                'uom_code' => (string) $position->uom_code,
            ],
            'record_version' => (int) $position->record_version,
            'allowed_actions' => $allowed,
            'created_at' => $this->timestamp($position->created_at),
            'updated_at' => $this->timestamp($position->updated_at),
        ];
    }

    private function stockSummary(array $scope): array
    {
        $rows = $this->stockBase($scope)->get([
            'position.id', 'position.item_id', 'position.lot_id', 'position.quantity_base',
            'position.reserved_quantity_base', 'position.uom_code', 'lot.status as lot_status', 'lot.expiry_date',
            'quality.availability_bucket',
        ]);
        $byUom = [];
        foreach ($rows as $row) {
            $quantity = $this->decimal($row->quantity_base);
            $rowReserved = $this->decimal($row->reserved_quantity_base);
            $unreserved = bcsub($quantity, $rowReserved, 6);
            $eligible = $row->availability_bucket === 'AVAILABLE'
                && $row->lot_status === 'ACTIVE' && ! $this->expired($row->expiry_date);
            $uom = (string) $row->uom_code;
            $byUom[$uom] ??= ['uom_code' => $uom, 'total' => '0.000000', 'available' => '0.000000',
                'blocked' => '0.000000', 'reserved' => '0.000000'];
            $byUom[$uom]['total'] = bcadd($byUom[$uom]['total'], $quantity, 6);
            $byUom[$uom]['reserved'] = bcadd($byUom[$uom]['reserved'], $rowReserved, 6);
            if ($eligible) {
                $byUom[$uom]['available'] = bcadd($byUom[$uom]['available'], $unreserved, 6);
            } else {
                $byUom[$uom]['blocked'] = bcadd($byUom[$uom]['blocked'], $unreserved, 6);
            }
        }
        $today = today()->toDateString();
        $soon = today()->addDays(30)->toDateString();
        $lotBase = DB::table('lots as lot')->join('stock_positions as position', 'position.lot_id', '=', 'lot.id')
            ->where('position.company_id', $scope['company_id'])->where('position.plant_id', $scope['plant_id']);

        ksort($byUom);
        $single = count($byUom) === 1 ? array_values($byUom)[0] : null;

        return [
            'position_count' => $rows->count(),
            'sku_count' => $rows->pluck('item_id')->unique()->count(),
            'lot_count' => $rows->pluck('lot_id')->unique()->count(),
            'quantity_summary_status' => $single ? 'SINGLE_UOM' : (count($byUom) > 1 ? 'MIXED_UOM' : 'EMPTY'),
            'quantities_by_uom' => array_values($byUom),
            // Legacy scalar fields are populated only when their unit is unambiguous.
            'total' => $single['total'] ?? null,
            'available' => $single['available'] ?? null,
            'blocked' => $single['blocked'] ?? null,
            'reserved' => $single['reserved'] ?? null,
            'uom_code' => $single['uom_code'] ?? null,
            'expired_lots' => (clone $lotBase)->where('lot.expiry_date', '<', $today)
                ->distinct()->count('lot.id'),
            'expiring_30_lots' => (clone $lotBase)->whereBetween('lot.expiry_date', [$today, $soon])
                ->distinct()->count('lot.id'),
        ];
    }

    private function stockLookups(array $scope): array
    {
        return [
            'availability_filters' => self::AVAILABILITY_FILTERS,
            'sorts' => self::STOCK_SORTS,
            'quality_statuses' => DB::table('inventory_quality_statuses')->orderBy('sort_order')->get()->map(
                fn (object $quality) => [
                    'code' => (string) $quality->code,
                    'name' => (string) $quality->name,
                    'availability_bucket' => (string) $quality->availability_bucket,
                    'is_reservable' => (bool) $quality->is_reservable,
                ],
            )->all(),
            'owners' => DB::table('inventory_owners')->where('company_id', $scope['company_id'])
                ->orderBy('code')->get(['id', 'code', 'name', 'status'])->map(
                    fn (object $owner) => $this->reference($owner->id, $owner->code, $owner->name) + [
                        'status' => (string) $owner->status,
                    ],
                )->all(),
            'skus' => $this->skuLookups($scope['company_id']),
            'lots' => DB::table('lots')->where('company_id', $scope['company_id'])->orderBy('internal_lot_code')
                ->get(['id', 'internal_lot_code as code', 'status'])->map(
                    fn (object $lot) => $this->reference($lot->id, $lot->code, $lot->code) + [
                        'status' => (string) $lot->status,
                    ],
                )->all(),
            'locations' => DB::table('locations')->where('company_id', $scope['company_id'])
                ->where('plant_id', $scope['plant_id'])->where('status', 'ACTIVE')->orderBy('code')
                ->get(['id', 'code', 'name'])->map(
                    fn (object $location) => $this->reference($location->id, $location->code, $location->name),
                )->all(),
        ];
    }

    private function lotBase(string $companyId): Builder
    {
        return DB::table('lots as lot')
            ->join('items as sku', 'sku.id', '=', 'lot.item_id')
            ->leftJoin('parties as supplier', 'supplier.id', '=', 'lot.supplier_party_id')
            ->leftJoin('users as status_actor', 'status_actor.id', '=', 'lot.status_changed_by')
            ->where('lot.company_id', $companyId);
    }

    private function lotFields(): array
    {
        return [
            'lot.*', 'sku.code as sku_code', 'sku.name as sku_name', 'sku.status as sku_status',
            'supplier.code as supplier_code', 'supplier.display_name as supplier_name',
            'status_actor.name as status_actor_name',
        ];
    }

    private function applyOwnerFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $query) use ($needle): void {
                foreach (['inventory_owner.code', 'inventory_owner.name', 'party.code', 'party.display_name'] as $column) {
                    $query->orWhereRaw("LOWER(COALESCE({$column}, '')) LIKE ?", [$needle]);
                }
            });
        }
        if (! empty($filters['status'])) {
            $query->where('inventory_owner.status', $filters['status']);
        }
        if (! empty($filters['owner_type'])) {
            $query->where('inventory_owner.owner_type', $filters['owner_type']);
        }
    }

    private function applyOwnerSort(Builder $query, string $sort): void
    {
        match ($sort) {
            'NAME' => $query->orderBy('inventory_owner.name')->orderBy('inventory_owner.code'),
            'NEWEST' => $query->orderByDesc('inventory_owner.created_at')->orderByDesc('inventory_owner.id'),
            'OLDEST' => $query->orderBy('inventory_owner.created_at')->orderBy('inventory_owner.id'),
            default => $query->orderBy('inventory_owner.code')->orderBy('inventory_owner.id'),
        };
    }

    private function applyLotFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $query) use ($needle): void {
                foreach ([
                    'lot.internal_lot_code', 'lot.supplier_lot_code', 'sku.code', 'sku.name',
                    'supplier.code', 'supplier.display_name',
                ] as $column) {
                    $query->orWhereRaw("LOWER(COALESCE({$column}, '')) LIKE ?", [$needle]);
                }
            });
        }
        foreach (['status' => 'lot.status', 'origin_type' => 'lot.origin_type', 'item_id' => 'lot.item_id'] as $filter => $column) {
            if (! empty($filters[$filter])) {
                $query->where($column, $filters[$filter]);
            }
        }
        $today = today()->toDateString();
        $soon = today()->addDays(30)->toDateString();
        match ($filters['expiry'] ?? null) {
            'EXPIRED' => $query->whereNotNull('lot.expiry_date')->where('lot.expiry_date', '<', $today),
            'EXPIRING_30' => $query->whereBetween('lot.expiry_date', [$today, $soon]),
            'VALID' => $query->where(fn (Builder $q) => $q->whereNull('lot.expiry_date')->orWhere('lot.expiry_date', '>=', $today)),
            default => null,
        };
    }

    private function applyLotSort(Builder $query, string $sort): void
    {
        match ($sort) {
            'SKU' => $query->orderBy('sku.code')->orderBy('lot.internal_lot_code'),
            'EXPIRY' => $query->orderByRaw('CASE WHEN lot.expiry_date IS NULL THEN 1 ELSE 0 END')
                ->orderBy('lot.expiry_date')->orderBy('lot.internal_lot_code'),
            'NEWEST' => $query->orderByDesc('lot.created_at')->orderByDesc('lot.id'),
            'OLDEST' => $query->orderBy('lot.created_at')->orderBy('lot.id'),
            default => $query->orderBy('lot.internal_lot_code')->orderBy('lot.id'),
        };
    }

    private function ownerPayload(object $owner, ?object $metrics, array $permissions): array
    {
        $allowed = [];
        if ($this->can($permissions, 'ACTION:INV-STK:OWNER-UPDATE')) {
            $allowed[] = 'UPDATE';
        }
        if ($this->can($permissions, 'ACTION:INV-STK:OWNER-LIFECYCLE')) {
            $allowed[] = 'CHANGE_STATUS';
        }

        return [
            'id' => (string) $owner->id,
            'company_id' => (string) $owner->company_id,
            'code' => (string) $owner->code,
            'name' => (string) $owner->name,
            'owner_type' => (string) $owner->owner_type,
            'party_id' => $owner->party_id ? (string) $owner->party_id : null,
            'party' => $owner->party_id
                ? $this->reference($owner->party_id, $owner->party_code, $owner->party_name)
                : null,
            'status' => (string) $owner->status,
            'record_version' => (int) $owner->record_version,
            'position_count' => (int) ($metrics->position_count ?? 0),
            'total_quantity' => $this->decimal($metrics->total_quantity ?? 0),
            'reserved_quantity' => $this->decimal($metrics->reserved_quantity ?? 0),
            'status_change' => $owner->status_changed_at ? [
                'reason' => $owner->status_reason,
                'changed_at' => $this->timestamp($owner->status_changed_at),
                'changed_by' => $owner->status_changed_by ? [
                    'id' => (string) $owner->status_changed_by,
                    'name' => $owner->status_actor_name,
                ] : null,
            ] : null,
            'allowed_actions' => $allowed,
            'allowed_statuses' => self::allowedStatuses((string) $owner->status, 'owner'),
            'created_at' => $this->timestamp($owner->created_at),
            'updated_at' => $this->timestamp($owner->updated_at),
        ];
    }

    private function lotPayload(object $lot, ?object $metrics, array $permissions): array
    {
        $allowed = [];
        if ($this->can($permissions, 'ACTION:INV-STK:LOT-UPDATE')) {
            $allowed[] = 'UPDATE';
        }
        if ($this->can($permissions, 'ACTION:INV-STK:LOT-LIFECYCLE')) {
            $allowed[] = 'CHANGE_STATUS';
        }

        return [
            'id' => (string) $lot->id,
            'company_id' => (string) $lot->company_id,
            'internal_lot_code' => (string) $lot->internal_lot_code,
            'supplier_lot_code' => $lot->supplier_lot_code,
            'item_id' => (string) $lot->item_id,
            'sku' => $this->reference($lot->item_id, $lot->sku_code, $lot->sku_name) + [
                'status' => (string) $lot->sku_status,
            ],
            'supplier_party_id' => $lot->supplier_party_id ? (string) $lot->supplier_party_id : null,
            'supplier' => $lot->supplier_party_id
                ? $this->reference($lot->supplier_party_id, $lot->supplier_code, $lot->supplier_name)
                : null,
            'origin_type' => (string) $lot->origin_type,
            'manufacture_date' => $this->date($lot->manufacture_date),
            'expiry_date' => $this->date($lot->expiry_date),
            'is_expired' => $this->expired($lot->expiry_date),
            'days_to_expiry' => $this->daysToExpiry($lot->expiry_date),
            'status' => (string) $lot->status,
            'notes' => $lot->notes,
            'record_version' => (int) $lot->record_version,
            'position_count' => (int) ($metrics->position_count ?? 0),
            'total_quantity' => $this->decimal($metrics->total_quantity ?? 0),
            'reserved_quantity' => $this->decimal($metrics->reserved_quantity ?? 0),
            'status_change' => $lot->status_changed_at ? [
                'reason' => $lot->status_reason,
                'changed_at' => $this->timestamp($lot->status_changed_at),
                'changed_by' => $lot->status_changed_by ? [
                    'id' => (string) $lot->status_changed_by,
                    'name' => $lot->status_actor_name,
                ] : null,
            ] : null,
            'allowed_actions' => $allowed,
            'allowed_statuses' => self::allowedStatuses((string) $lot->status, 'lot'),
            'created_at' => $this->timestamp($lot->created_at),
            'updated_at' => $this->timestamp($lot->updated_at),
        ];
    }

    private function reservation(object $reservation, array $permissions): array
    {
        return [
            'id' => (string) $reservation->id,
            'reservation_number' => (string) $reservation->reservation_number,
            'quantity_base' => $this->decimal($reservation->quantity_base),
            'status' => (string) $reservation->status,
            'purpose' => (string) $reservation->purpose,
            'record_version' => (int) $reservation->record_version,
            'created_by' => ['id' => (string) $reservation->created_by, 'name' => (string) $reservation->creator_name],
            'released_at' => $this->timestamp($reservation->released_at),
            'released_by' => $reservation->released_by ? [
                'id' => (string) $reservation->released_by,
                'name' => (string) $reservation->releaser_name,
            ] : null,
            'release_reason' => $reservation->release_reason,
            'allowed_actions' => $reservation->status === 'ACTIVE'
                && $this->can($permissions, 'ACTION:INV-STK:RELEASE') ? ['RELEASE'] : [],
            'created_at' => $this->timestamp($reservation->created_at),
            'updated_at' => $this->timestamp($reservation->updated_at),
        ];
    }

    private function positionMetrics(string $column, array $ids, string $plantId): Collection
    {
        if ($ids === []) {
            return collect();
        }

        return DB::table('stock_positions')->where('plant_id', $plantId)->whereIn($column, $ids)
            ->groupBy($column)->get([
                $column,
                DB::raw('COUNT(*) as position_count'),
                DB::raw('COALESCE(SUM(quantity_base), 0) as total_quantity'),
                DB::raw('COALESCE(SUM(reserved_quantity_base), 0) as reserved_quantity'),
            ])->keyBy($column);
    }

    private function qualityTotals(string $column, string $id, string $plantId): array
    {
        return DB::table('stock_positions as position')
            ->join('inventory_quality_statuses as quality', 'quality.code', '=', 'position.quality_status')
            ->where('position.plant_id', $plantId)->where("position.{$column}", $id)
            ->groupBy(['position.quality_status', 'quality.name', 'quality.availability_bucket'])
            ->orderBy('position.quality_status')
            ->get([
                'position.quality_status as code', 'quality.name', 'quality.availability_bucket',
                DB::raw('COALESCE(SUM(position.quantity_base), 0) as total_quantity'),
                DB::raw('COALESCE(SUM(position.reserved_quantity_base), 0) as reserved_quantity'),
            ])->map(fn (object $row) => [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'availability_bucket' => (string) $row->availability_bucket,
                'total_quantity' => $this->decimal($row->total_quantity),
                'reserved_quantity' => $this->decimal($row->reserved_quantity),
            ])->all();
    }

    private function skuLookups(string $companyId): array
    {
        return DB::table('items as sku')->join('catalog_items as item', 'item.id', '=', 'sku.catalog_item_id')
            ->where('sku.company_id', $companyId)->where('item.lot_controlled', true)
            ->orderBy('sku.code')->get(['sku.id', 'sku.code', 'sku.name', 'sku.status'])->map(
                fn (object $sku) => $this->reference($sku->id, $sku->code, $sku->name) + [
                    'status' => (string) $sku->status,
                ],
            )->all();
    }

    private function partyLookups(string $companyId): array
    {
        return DB::table('parties')->where('company_id', $companyId)->where('status', 'ACTIVE')
            ->orderBy('code')->get(['id', 'code', 'display_name as name'])->map(
                fn (object $party) => $this->reference($party->id, $party->code, $party->name),
            )->all();
    }

    private function supplierLookups(string $companyId): array
    {
        return DB::table('parties as party')->join('party_roles as role', function ($join): void {
            $join->on('role.party_id', '=', 'party.id')->where('role.role_code', 'SUPPLIER');
        })->where('party.company_id', $companyId)->where('party.status', 'ACTIVE')
            ->orderBy('party.code')->get(['party.id', 'party.code', 'party.display_name as name'])->map(
                fn (object $party) => $this->reference($party->id, $party->code, $party->name),
            )->all();
    }

    private static function allowedStatuses(string $status, string $type): array
    {
        $transitions = $type === 'owner'
            ? ['DRAFT' => ['ACTIVE', 'INACTIVE'], 'ACTIVE' => ['INACTIVE'], 'INACTIVE' => ['ACTIVE']]
            : [
                'DRAFT' => ['ACTIVE', 'CLOSED'], 'ACTIVE' => ['CLOSED', 'RECALLED'],
                'CLOSED' => ['ACTIVE', 'RECALLED'], 'RECALLED' => ['CLOSED'],
            ];

        return $transitions[$status] ?? [];
    }

    private function meta(object $paginator): array
    {
        return [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ];
    }

    private function reference(mixed $id, mixed $code, mixed $name): array
    {
        return ['id' => (string) $id, 'code' => (string) $code, 'name' => (string) $name];
    }

    private function can(array $permissions, string $permission): bool
    {
        return in_array($permission, $permissions, true);
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 6);
    }

    private function date(mixed $value): ?string
    {
        return $value ? CarbonImmutable::parse((string) $value)->toDateString() : null;
    }

    private function timestamp(mixed $value): ?string
    {
        return $value ? CarbonImmutable::parse((string) $value)->toISOString() : null;
    }

    private function expired(mixed $date): bool
    {
        return $date !== null && CarbonImmutable::parse((string) $date)->isBefore(today());
    }

    private function daysToExpiry(mixed $date): ?int
    {
        return $date ? (int) today()->diffInDays(CarbonImmutable::parse((string) $date), false) : null;
    }
}

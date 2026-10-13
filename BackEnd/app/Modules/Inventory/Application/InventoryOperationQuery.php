<?php

namespace App\Modules\Inventory\Application;

use App\Shared\Visibility\OperationalVisibilityScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class InventoryOperationQuery
{
    public const SORTS = ['NEWEST', 'OLDEST', 'NUMBER', 'STATUS'];
    public const MOVEMENT_SORTS = ['NEWEST', 'OLDEST', 'TYPE'];
    public const MOVEMENT_DIRECTIONS = ['OUTBOUND', 'INBOUND', 'TRANSFER'];

    public function __construct(private readonly OperationalVisibilityScope $visibility) {}

    public function workspace(string $resource, array $scope, array $filters, array $permissions): array
    {
        $config = $this->resource($resource);
        $base = $this->operationBase($scope, $config['types'], $permissions, $filters['visibility'] ?? null);
        $query = clone $base;
        $this->applyOperationFilters($query, $filters);
        $this->applyOperationSort($query, $filters['sort'] ?? 'NEWEST');
        $operations = $query->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($operations->items())->map(
                fn (object $operation) => $this->operationPayload($operation, $config['screen'], $permissions),
            )->all(),
            'meta' => $this->meta($operations),
            'summary' => [
                'total' => (clone $base)->count(),
                'draft' => (clone $base)->where('operation.status', 'DRAFT')->count(),
                'posted' => (clone $base)->where('operation.status', 'POSTED')->count(),
                'cancelled' => (clone $base)->where('operation.status', 'CANCELLED')->count(),
                'by_type' => collect($config['types'])->mapWithKeys(fn (string $type) => [
                    $type => (clone $base)->where('operation.operation_type', $type)->count(),
                ])->all(),
            ],
            'lookups' => [
                'operation_types' => $config['types'],
                'statuses' => InventoryOperationService::STATUSES,
                'adjustment_directions' => InventoryOperationService::ADJUSTMENT_DIRECTIONS,
                'sorts' => self::SORTS,
                'positions' => $this->positionLookups($scope, $permissions),
                'visibility_filters' => $this->visibility->filterOptions($permissions),
            ],
            'operational_view' => $this->visibility->viewDescriptor($permissions),
            'allowed_actions' => $this->can($permissions, "ACTION:{$config['screen']}:CREATE")
                ? ['CREATE'] : [],
        ];
    }

    public function detail(
        string $resource,
        string $operationId,
        array $scope,
        array $permissions,
    ): array {
        $config = $this->resource($resource);
        $operation = $this->operationBase($scope, $config['types'], $permissions)
            ->where('operation.id', $operationId)->first();
        if (! $operation) {
            throw new NotFoundHttpException('Inventory operation not found.');
        }

        $lines = DB::table('inventory_operation_lines')->where('operation_id', $operationId)
            ->orderBy('sequence_no')->get();
        $positionIds = $lines->flatMap(fn (object $line) => [
            $line->source_position_id,
            $line->target_position_id,
        ])->filter()->unique()->values()->all();
        $positions = $this->positions($scope, $positionIds, $permissions)->keyBy('id');

        $payload = $this->operationPayload($operation, $config['screen'], $permissions);
        $payload['lines'] = $lines->map(function (object $line) use ($positions): array {
            $system = $line->system_quantity_base !== null ? $this->decimal($line->system_quantity_base) : null;
            $counted = $line->counted_quantity_base !== null ? $this->decimal($line->counted_quantity_base) : null;

            return [
                'id' => (string) $line->id,
                'sequence_no' => (int) $line->sequence_no,
                'source_position_id' => $line->source_position_id ? (string) $line->source_position_id : null,
                'source_position' => $line->source_position_id
                    ? $this->positionPayload($positions->get((string) $line->source_position_id)) : null,
                'target_position_id' => $line->target_position_id ? (string) $line->target_position_id : null,
                'target_position' => $line->target_position_id
                    ? $this->positionPayload($positions->get((string) $line->target_position_id)) : null,
                'quantity_base' => $line->quantity_base !== null ? $this->decimal($line->quantity_base) : null,
                'counted_quantity_base' => $counted,
                'system_quantity_base' => $system,
                'variance_quantity_base' => $counted !== null && $system !== null
                    ? bcsub($counted, $system, 6) : null,
                'source_position_version' => $line->source_position_version !== null
                    ? (int) $line->source_position_version : null,
                'adjustment_direction' => $line->adjustment_direction,
                'uom_code' => (string) $line->uom_code,
                'movement_id' => $line->movement_id ? (string) $line->movement_id : null,
                'notes' => $line->notes,
            ];
        })->all();
        $payload['movements'] = $this->movementsForOperation($operationId, $scope, $permissions);

        return $payload;
    }

    public function movementWorkspace(array $scope, array $filters, array $permissions): array
    {
        $base = $this->movementBase($scope, $permissions);
        $query = clone $base;
        $this->applyMovementFilters($query, $filters);
        $this->applyMovementSort($query, $filters['sort'] ?? 'NEWEST');
        $movements = $query->paginate((int) ($filters['per_page'] ?? 25));
        $items = collect($movements->items());
        $positionIds = $items->flatMap(fn (object $movement) => [
            $movement->from_position_id,
            $movement->to_position_id,
        ])->filter()->unique()->values()->all();
        $positions = $this->positions($scope, $positionIds, $permissions)->keyBy('id');

        return [
            'data' => $items->map(fn (object $movement) => $this->movementPayload($movement, $positions))->all(),
            'meta' => $this->meta($movements),
            'summary' => [
                'total' => (clone $base)->count(),
                'outbound' => (clone $base)->whereNotNull('movement.from_position_id')
                    ->whereNull('movement.to_position_id')->count(),
                'inbound' => (clone $base)->whereNull('movement.from_position_id')
                    ->whereNotNull('movement.to_position_id')->count(),
                'transfer' => (clone $base)->whereNotNull('movement.from_position_id')
                    ->whereNotNull('movement.to_position_id')->count(),
            ],
            'lookups' => [
                'movement_types' => (clone $base)->select('movement.movement_type')->distinct()
                    ->orderBy('movement.movement_type')->pluck('movement_type')->all(),
                'directions' => self::MOVEMENT_DIRECTIONS,
                'sorts' => self::MOVEMENT_SORTS,
            ],
            'allowed_actions' => [],
            'operational_view' => $this->visibility->viewDescriptor($permissions),
        ];
    }

    public function movementDetail(string $movementId, array $scope, array $permissions): array
    {
        $movement = $this->movementBase($scope, $permissions)->where('movement.id', $movementId)->first();
        if (! $movement) {
            throw new NotFoundHttpException('Stock movement not found.');
        }
        $ids = array_values(array_filter([$movement->from_position_id, $movement->to_position_id]));
        $positions = $this->positions($scope, $ids, $permissions)->keyBy('id');

        return $this->movementPayload($movement, $positions);
    }

    private function operationBase(array $scope, array $types, array $permissions, ?string $visibilityFilter = null): Builder
    {
        $query = DB::table('inventory_operations as operation')
            ->join('users as creator', 'creator.id', '=', 'operation.created_by')
            ->leftJoin('users as poster', 'poster.id', '=', 'operation.posted_by')
            ->leftJoin('users as canceller', 'canceller.id', '=', 'operation.cancelled_by')
            ->where('operation.company_id', $scope['company_id'])
            ->where('operation.plant_id', $scope['plant_id'])
            ->whereIn('operation.operation_type', $types)
            ->select([
                'operation.*', 'creator.name as creator_name', 'poster.name as poster_name',
                'canceller.name as canceller_name',
            ])->selectSub(
                fn (Builder $line) => $line->from('inventory_operation_lines as line')
                    ->whereColumn('line.operation_id', 'operation.id')->selectRaw('COUNT(*)'),
                'line_count',
            );
        return $this->visibility->apply($query, 'INVENTORY_OPERATION', 'operation.id', $permissions, $visibilityFilter, 'operation_visibility');
    }

    private function movementBase(array $scope, array $permissions): Builder
    {
        $query = DB::table('stock_movements as movement')
            ->join('users as actor', 'actor.id', '=', 'movement.actor_id')
            ->leftJoin('inventory_operations as operation', function ($join): void {
                $join->on('operation.id', '=', 'movement.source_id')
                    ->where('movement.source_type', '=', 'INVENTORY_OPERATION');
            })
            ->where('movement.company_id', $scope['company_id'])
            ->where('movement.plant_id', $scope['plant_id'])
            ->select([
                'movement.*', 'actor.name as actor_name',
                'operation.operation_number', 'operation.operation_type', 'operation.status as operation_status',
            ]);

        return $this->visibility->applyStockMovementSources($query, $permissions);
    }

    private function applyOperationFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $query) use ($needle): void {
                foreach (['operation.operation_number', 'operation.reason_code', 'operation.notes', 'creator.name'] as $column) {
                    $query->orWhereRaw("LOWER(COALESCE({$column}, '')) LIKE ?", [$needle]);
                }
            });
        }
        if (! empty($filters['status'])) {
            $query->where('operation.status', $filters['status']);
        }
        if (! empty($filters['operation_type'])) {
            $query->where('operation.operation_type', $filters['operation_type']);
        }
    }

    private function applyOperationSort(Builder $query, string $sort): void
    {
        match ($sort) {
            'OLDEST' => $query->orderBy('operation.created_at')->orderBy('operation.id'),
            'NUMBER' => $query->orderBy('operation.operation_number')->orderBy('operation.id'),
            'STATUS' => $query->orderBy('operation.status')->orderByDesc('operation.updated_at'),
            default => $query->orderByDesc('operation.updated_at')->orderByDesc('operation.id'),
        };
    }

    private function applyMovementFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $query) use ($needle): void {
                foreach ([
                    'movement.movement_type', 'movement.source_type', 'movement.reason_code',
                    'operation.operation_number', 'actor.name',
                ] as $column) {
                    $query->orWhereRaw("LOWER(COALESCE({$column}, '')) LIKE ?", [$needle]);
                }
            });
        }
        if (! empty($filters['movement_type'])) {
            $query->where('movement.movement_type', $filters['movement_type']);
        }
        match ($filters['direction'] ?? null) {
            'OUTBOUND' => $query->whereNotNull('movement.from_position_id')->whereNull('movement.to_position_id'),
            'INBOUND' => $query->whereNull('movement.from_position_id')->whereNotNull('movement.to_position_id'),
            'TRANSFER' => $query->whereNotNull('movement.from_position_id')->whereNotNull('movement.to_position_id'),
            default => null,
        };
        if (! empty($filters['item_id'])) {
            $itemId = $filters['item_id'];
            $query->where(function (Builder $query) use ($itemId): void {
                $query->whereExists(fn (Builder $position) => $position->selectRaw('1')
                    ->from('stock_positions as source')->whereColumn('source.id', 'movement.from_position_id')
                    ->where('source.item_id', $itemId))
                    ->orWhereExists(fn (Builder $position) => $position->selectRaw('1')
                        ->from('stock_positions as target')->whereColumn('target.id', 'movement.to_position_id')
                        ->where('target.item_id', $itemId));
            });
        }
        if (! empty($filters['lot_id'])) {
            $lotId = $filters['lot_id'];
            $query->where(function (Builder $query) use ($lotId): void {
                $query->whereExists(fn (Builder $position) => $position->selectRaw('1')
                    ->from('stock_positions as source')->whereColumn('source.id', 'movement.from_position_id')
                    ->where('source.lot_id', $lotId))
                    ->orWhereExists(fn (Builder $position) => $position->selectRaw('1')
                        ->from('stock_positions as target')->whereColumn('target.id', 'movement.to_position_id')
                        ->where('target.lot_id', $lotId));
            });
        }
        if (! empty($filters['from'])) {
            $query->where('movement.posted_at', '>=', $filters['from'].' 00:00:00');
        }
        if (! empty($filters['to'])) {
            $query->where('movement.posted_at', '<=', $filters['to'].' 23:59:59.999999');
        }
    }

    private function applyMovementSort(Builder $query, string $sort): void
    {
        match ($sort) {
            'OLDEST' => $query->orderBy('movement.posted_at')->orderBy('movement.id'),
            'TYPE' => $query->orderBy('movement.movement_type')->orderByDesc('movement.posted_at'),
            default => $query->orderByDesc('movement.posted_at')->orderByDesc('movement.id'),
        };
    }

    private function operationPayload(object $operation, string $screen, array $permissions): array
    {
        $allowed = [];
        if ($operation->status === 'DRAFT') {
            foreach (['UPDATE', 'POST', 'CANCEL'] as $action) {
                if ($this->can($permissions, "ACTION:{$screen}:{$action}")) {
                    $allowed[] = $action;
                }
            }
        }

        return [
            'id' => (string) $operation->id,
            'company_id' => (string) $operation->company_id,
            'plant_id' => (string) $operation->plant_id,
            'operation_number' => (string) $operation->operation_number,
            'operation_type' => (string) $operation->operation_type,
            'status' => (string) $operation->status,
            'reason_code' => (string) $operation->reason_code,
            'notes' => $operation->notes,
            'record_version' => (int) $operation->record_version,
            'line_count' => (int) $operation->line_count,
            'created_by' => ['id' => (string) $operation->created_by, 'name' => (string) $operation->creator_name],
            'posted_at' => $this->timestamp($operation->posted_at),
            'posted_by' => $operation->posted_by
                ? ['id' => (string) $operation->posted_by, 'name' => (string) $operation->poster_name] : null,
            'cancelled_at' => $this->timestamp($operation->cancelled_at),
            'cancelled_by' => $operation->cancelled_by
                ? ['id' => (string) $operation->cancelled_by, 'name' => (string) $operation->canceller_name] : null,
            'cancellation_reason' => $operation->cancellation_reason,
            'visibility' => $this->visibility->metadata($operation),
            'allowed_actions' => $allowed,
            'created_at' => $this->timestamp($operation->created_at),
            'updated_at' => $this->timestamp($operation->updated_at),
        ];
    }

    private function movementsForOperation(string $operationId, array $scope, array $permissions): array
    {
        $movements = $this->movementBase($scope, $permissions)->where('movement.source_type', 'INVENTORY_OPERATION')
            ->where('movement.source_id', $operationId)->orderBy('movement.posted_at')->get();
        $ids = $movements->flatMap(fn (object $movement) => [
            $movement->from_position_id,
            $movement->to_position_id,
        ])->filter()->unique()->values()->all();
        $positions = $this->positions($scope, $ids, $permissions)->keyBy('id');

        return $movements->map(fn (object $movement) => $this->movementPayload($movement, $positions))->all();
    }

    private function movementPayload(object $movement, Collection $positions): array
    {
        $from = $movement->from_position_id
            ? $this->positionPayload($positions->get((string) $movement->from_position_id)) : null;
        $to = $movement->to_position_id
            ? $this->positionPayload($positions->get((string) $movement->to_position_id)) : null;

        return [
            'id' => (string) $movement->id,
            'company_id' => (string) $movement->company_id,
            'plant_id' => $movement->plant_id ? (string) $movement->plant_id : null,
            'movement_type' => (string) $movement->movement_type,
            'direction' => $from && $to ? 'TRANSFER' : ($from ? 'OUTBOUND' : 'INBOUND'),
            'source' => [
                'type' => (string) $movement->source_type,
                'id' => (string) $movement->source_id,
                'version' => $movement->source_version !== null ? (int) $movement->source_version : null,
                'operation_number' => $movement->operation_number ?? null,
                'operation_type' => $movement->operation_type ?? null,
                'operation_status' => $movement->operation_status ?? null,
            ],
            'from_position' => $from,
            'to_position' => $to,
            'quantity_base' => $this->decimal($movement->quantity_base),
            'uom_code' => (string) $movement->uom_code,
            'reason_code' => $movement->reason_code,
            'actor' => ['id' => (string) $movement->actor_id, 'name' => (string) $movement->actor_name],
            'event_at' => $this->timestamp($movement->event_at),
            'posted_at' => $this->timestamp($movement->posted_at),
        ];
    }

    private function positionLookups(array $scope, array $permissions): array
    {
        return $this->positions($scope, null, $permissions)->map(fn (object $position) => $this->positionPayload($position))->all();
    }

    private function positions(array $scope, ?array $ids, array $permissions): Collection
    {
        $query = DB::table('stock_positions as position')
            ->join('items as sku', 'sku.id', '=', 'position.item_id')
            ->join('lots as lot', 'lot.id', '=', 'position.lot_id')
            ->join('inventory_owners as owner', 'owner.id', '=', 'position.inventory_owner_id')
            ->join('locations as location', 'location.id', '=', 'position.location_id')
            ->join('inventory_quality_statuses as quality', 'quality.code', '=', 'position.quality_status')
            ->where('position.company_id', $scope['company_id'])
            ->where('position.plant_id', $scope['plant_id'])
            ->orderBy('sku.code')->orderBy('lot.internal_lot_code')->orderBy('location.code');
        $this->visibility->applyInventoryAttribution($query, 'position.lot_id', $permissions);
        if ($ids !== null) {
            if ($ids === []) {
                return collect();
            }
            $query->whereIn('position.id', $ids);
        }

        return $query->get([
            'position.*', 'sku.code as sku_code', 'sku.name as sku_name',
            'lot.internal_lot_code as lot_code', 'lot.status as lot_status', 'lot.expiry_date',
            'owner.code as owner_code', 'owner.name as owner_name', 'owner.status as owner_status',
            'location.code as location_code', 'location.name as location_name',
            'quality.name as quality_name', 'quality.availability_bucket',
        ]);
    }

    private function positionPayload(?object $position): ?array
    {
        if (! $position) {
            return null;
        }
        $unreserved = bcsub((string) $position->quantity_base, (string) $position->reserved_quantity_base, 6);
        $eligible = $position->availability_bucket === 'AVAILABLE'
            && $position->lot_status === 'ACTIVE'
            && ! ($position->expiry_date && CarbonImmutable::parse((string) $position->expiry_date)->isBefore(today()));

        return [
            'id' => (string) $position->id,
            'label' => "{$position->sku_code} · {$position->lot_code} · {$position->location_code} · {$position->quality_status} · {$position->owner_code}",
            'sku' => ['id' => (string) $position->item_id, 'code' => (string) $position->sku_code, 'name' => (string) $position->sku_name],
            'lot' => [
                'id' => (string) $position->lot_id,
                'code' => (string) $position->lot_code,
                'status' => (string) $position->lot_status,
                'expiry_date' => $position->expiry_date ? CarbonImmutable::parse((string) $position->expiry_date)->toDateString() : null,
            ],
            'owner' => [
                'id' => (string) $position->inventory_owner_id,
                'code' => (string) $position->owner_code,
                'name' => (string) $position->owner_name,
                'status' => (string) $position->owner_status,
            ],
            'location' => [
                'id' => (string) $position->location_id,
                'code' => (string) $position->location_code,
                'name' => (string) $position->location_name,
            ],
            'quality_status' => [
                'code' => (string) $position->quality_status,
                'name' => (string) $position->quality_name,
                'availability_bucket' => (string) $position->availability_bucket,
            ],
            'quantity' => [
                'total' => $this->decimal($position->quantity_base),
                'available' => $eligible ? $this->decimal($unreserved) : '0.000000',
                'reserved' => $this->decimal($position->reserved_quantity_base),
                'uom_code' => (string) $position->uom_code,
            ],
            'record_version' => (int) $position->record_version,
        ];
    }

    private function resource(string $resource): array
    {
        $config = InventoryOperationService::RESOURCES[$resource] ?? null;
        if (! $config) {
            throw new NotFoundHttpException('Inventory operation resource not found.');
        }

        return $config;
    }

    private function can(array $permissions, string $permission): bool
    {
        return in_array($permission, $permissions, true);
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

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 6);
    }

    private function timestamp(mixed $value): ?string
    {
        return $value ? CarbonImmutable::parse((string) $value)->toISOString() : null;
    }
}

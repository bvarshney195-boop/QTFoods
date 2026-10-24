<?php

namespace App\Modules\MasterData\Application;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProductMasterQuery
{
    public const SORTS = ['CODE', 'NAME', 'NEWEST', 'OLDEST'];

    public function workspace(string $resource, array $scope, array $filters, array $permissions): array
    {
        $definition = $this->definition($resource);
        $base = $this->scoped($resource, $scope['company_id']);
        $query = clone $base;
        $this->applyFilters($resource, $query, $filters);
        $this->applySort($query, $filters['sort'] ?? 'CODE');

        $records = $query->select($this->selects($resource))
            ->paginate((int) ($filters['per_page'] ?? 25));
        $items = collect($records->items());
        $counts = $this->childCounts($definition, $items->pluck('id')->all());

        return [
            'resource' => $resource,
            'data' => $items->map(fn (object $record) => $this->payload(
                $resource,
                $record,
                $permissions,
                (int) ($counts[(string) $record->id] ?? 0),
                null,
            ))->all(),
            'meta' => [
                'current_page' => $records->currentPage(),
                'last_page' => $records->lastPage(),
                'per_page' => $records->perPage(),
                'total' => $records->total(),
            ],
            'summary' => $this->summary($base),
            'lookups' => $this->lookups($scope['company_id']),
            'allowed_actions' => $this->can($permissions, 'ACTION:'.$definition['screen'].':CREATE')
                ? ['CREATE']
                : [],
        ];
    }

    public function detail(string $resource, string $recordId, array $scope, array $permissions): array
    {
        $definition = $this->definition($resource);
        $record = $this->scoped($resource, $scope['company_id'])->where('record.id', $recordId)
            ->first($this->selects($resource));
        if (! $record) {
            throw new NotFoundHttpException($definition['label'].' not found.');
        }
        $children = DB::table($definition['child_table'])->where($definition['child_key'], $recordId)
            ->orderBy($this->childOrder($resource))->get();

        return $this->payload($resource, $record, $permissions, $children->count(), $children->all());
    }

    private function scoped(string $resource, string $companyId): Builder
    {
        $definition = $this->definition($resource);
        $query = DB::table($definition['table'].' as record')
            ->leftJoin('users as status_actor', 'status_actor.id', '=', 'record.status_changed_by')
            ->where('record.company_id', $companyId);

        return match ($resource) {
            'items' => $query->leftJoin('brands as parent', function ($join): void {
                $join->on('parent.id', '=', 'record.brand_id')->on('parent.company_id', '=', 'record.company_id');
            }),
            'skus' => $query->leftJoin('catalog_items as parent', function ($join): void {
                $join->on('parent.id', '=', 'record.catalog_item_id')->on('parent.company_id', '=', 'record.company_id');
            }),
            'recipes' => $query->leftJoin('items as parent', function ($join): void {
                $join->on('parent.id', '=', 'record.output_sku_id')->on('parent.company_id', '=', 'record.company_id');
            }),
            'routes' => $query->leftJoin('catalog_items as parent', function ($join): void {
                $join->on('parent.id', '=', 'record.catalog_item_id')->on('parent.company_id', '=', 'record.company_id');
            }),
            'specifications' => $query
                ->leftJoin('catalog_items as target_item', function ($join): void {
                    $join->on('target_item.id', '=', 'record.catalog_item_id')
                        ->on('target_item.company_id', '=', 'record.company_id');
                })
                ->leftJoin('items as target_sku', function ($join): void {
                    $join->on('target_sku.id', '=', 'record.sku_id')
                        ->on('target_sku.company_id', '=', 'record.company_id');
                }),
            default => $query,
        };
    }

    private function selects(string $resource): array
    {
        $selects = ['record.*', 'status_actor.name as status_actor_name'];
        return match ($resource) {
            'items' => array_merge($selects, ['parent.code as parent_code', 'parent.name as parent_name']),
            'skus', 'recipes', 'routes' => array_merge($selects, [
                'parent.code as parent_code', 'parent.name as parent_name',
            ]),
            'specifications' => array_merge($selects, [
                DB::raw('COALESCE(target_item.code, target_sku.code) as parent_code'),
                DB::raw('COALESCE(target_item.name, target_sku.name) as parent_name'),
            ]),
            default => $selects,
        };
    }

    private function applyFilters(string $resource, Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $filter) use ($resource, $needle): void {
                $filter->whereRaw("LOWER(COALESCE(record.code, '')) LIKE ?", [$needle])
                    ->orWhereRaw("LOWER(COALESCE(record.name, '')) LIKE ?", [$needle]);
                if (in_array($resource, ['items', 'skus', 'recipes', 'routes'], true)) {
                    $filter->orWhereRaw("LOWER(COALESCE(parent.code, '')) LIKE ?", [$needle])
                        ->orWhereRaw("LOWER(COALESCE(parent.name, '')) LIKE ?", [$needle]);
                }
                if ($resource === 'specifications') {
                    $filter->orWhereRaw("LOWER(COALESCE(target_item.code, '')) LIKE ?", [$needle])
                        ->orWhereRaw("LOWER(COALESCE(target_sku.code, '')) LIKE ?", [$needle]);
                }
                if ($resource === 'brands') {
                    $filter->orWhereExists(fn (Builder $child) => $child->from('brand_agreements as agreement')
                        ->whereColumn('agreement.brand_id', 'record.id')
                        ->whereRaw("LOWER(COALESCE(agreement.agreement_number, '')) LIKE ?", [$needle]));
                }
            });
        }
        if (! empty($filters['status'])) {
            $query->where('record.status', $filters['status']);
        }
        if (! empty($filters['type'])) {
            match ($resource) {
                'items', 'skus' => $query->where('record.item_type', $filters['type']),
                'specifications' => $query->where('record.target_type', $filters['type']),
                default => null,
            };
        }
        if (! empty($filters['parent_id'])) {
            match ($resource) {
                'items' => $query->where('record.brand_id', $filters['parent_id']),
                'skus', 'routes' => $query->where('record.catalog_item_id', $filters['parent_id']),
                'recipes' => $query->where('record.output_sku_id', $filters['parent_id']),
                'specifications' => $query->where(function (Builder $target) use ($filters): void {
                    $target->where('record.catalog_item_id', $filters['parent_id'])
                        ->orWhere('record.sku_id', $filters['parent_id']);
                }),
                default => null,
            };
        }
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'NAME' => $query->orderBy('record.name')->orderBy('record.code'),
            'NEWEST' => $query->orderByDesc('record.created_at')->orderByDesc('record.id'),
            'OLDEST' => $query->orderBy('record.created_at')->orderBy('record.id'),
            default => $query->orderBy('record.code')->orderBy('record.id'),
        };
    }

    private function payload(
        string $resource,
        object $record,
        array $permissions,
        int $childCount,
        ?array $children,
    ): array {
        $definition = $this->definition($resource);
        $allowed = [];
        if ($this->can($permissions, 'ACTION:'.$definition['screen'].':UPDATE')) {
            $allowed[] = 'UPDATE';
        }
        if ($this->can($permissions, 'ACTION:'.$definition['screen'].':LIFECYCLE')) {
            $allowed[] = 'CHANGE_STATUS';
        }

        $payload = [
            'id' => (string) $record->id,
            'company_id' => (string) $record->company_id,
            'code' => (string) $record->code,
            'name' => (string) $record->name,
            'status' => (string) $record->status,
            'record_version' => (int) $record->record_version,
            'child_count' => $childCount,
            'child_label' => $definition['children'],
            'parent' => isset($record->parent_code) && $record->parent_code !== null ? [
                'code' => (string) $record->parent_code,
                'name' => (string) $record->parent_name,
            ] : null,
            'status_change' => $record->status_changed_at ? [
                'reason' => $record->status_reason,
                'changed_at' => $this->timestamp($record->status_changed_at),
                'changed_by' => $record->status_changed_by ? [
                    'id' => (string) $record->status_changed_by,
                    'name' => $record->status_actor_name ?: 'Unknown actor',
                ] : null,
            ] : null,
            'allowed_actions' => $allowed,
            'allowed_statuses' => ProductMasterService::allowedTransitions((string) $record->status),
            'created_at' => $this->timestamp($record->created_at),
            'updated_at' => $this->timestamp($record->updated_at),
        ] + $this->resourcePayload($resource, $record);

        if ($children !== null) {
            $payload[$definition['children']] = array_map(
                fn (object $child) => $this->childPayload($resource, $child),
                $children,
            );
        }

        return $payload;
    }

    private function resourcePayload(string $resource, object $record): array
    {
        return match ($resource) {
            'brands' => [
                'description' => $record->description,
            ],
            'items' => [
                'brand_id' => $record->brand_id ? (string) $record->brand_id : null,
                'item_type' => (string) $record->item_type,
                'base_uom' => (string) $record->base_uom,
                'description' => $record->description,
                'shelf_life_days' => $record->shelf_life_days === null ? null : (int) $record->shelf_life_days,
                'lot_controlled' => (bool) $record->lot_controlled,
            ],
            'skus' => [
                'catalog_item_id' => (string) $record->catalog_item_id,
                'barcode' => $record->barcode,
                'description' => $record->description,
                'item_type' => (string) $record->item_type,
                'base_uom' => (string) $record->base_uom,
                'pack_quantity' => $this->decimal($record->pack_quantity, 6),
                'pack_uom_code' => $record->pack_uom_code,
            ],
            'recipes' => [
                'revision' => (int) $record->revision,
                'output_sku_id' => (string) $record->output_sku_id,
                'output_quantity' => $this->decimal($record->output_quantity, 6),
                'output_uom_code' => (string) $record->output_uom_code,
                'yield_percent' => $this->decimal($record->yield_percent, 3),
                'basis_quantity' => $this->decimal($record->basis_quantity, 6),
                'basis_uom_code' => (string) $record->basis_uom_code,
                'calculation_model' => (string) $record->calculation_model,
                'standard_piece_weight' => $record->standard_piece_weight === null ? null : $this->decimal($record->standard_piece_weight, 6),
                'piece_weight_uom_code' => $record->piece_weight_uom_code,
                'target_finished_weight' => $record->target_finished_weight === null ? null : $this->decimal($record->target_finished_weight, 6),
                'target_weight_uom_code' => $record->target_weight_uom_code,
                'pieces_per_pack' => (int) $record->pieces_per_pack,
                'handling_loss_percent' => $this->decimal($record->handling_loss_percent, 6),
                'handling_loss_in_standard' => (bool) $record->handling_loss_in_standard,
                'transformation_loss_percent' => $this->decimal($record->transformation_loss_percent, 6),
                'transformation_loss_in_standard' => (bool) $record->transformation_loss_in_standard,
                'rejection_allowance_percent' => $this->decimal($record->rejection_allowance_percent, 6),
                'output_rounding_mode' => (string) $record->output_rounding_mode,
                'unit_conversion_rate' => $record->unit_conversion_rate === null ? null : $this->decimal($record->unit_conversion_rate, 9),
                'effective_from' => $this->date($record->effective_from),
                'effective_to' => $this->date($record->effective_to),
                'notes' => $record->notes,
            ],
            'routes' => [
                'catalog_item_id' => (string) $record->catalog_item_id,
                'description' => $record->description,
            ],
            'specifications' => [
                'target_type' => (string) $record->target_type,
                'catalog_item_id' => $record->catalog_item_id ? (string) $record->catalog_item_id : null,
                'sku_id' => $record->sku_id ? (string) $record->sku_id : null,
                'effective_from' => $this->date($record->effective_from),
                'effective_to' => $this->date($record->effective_to),
                'sampling_plan' => $record->sampling_plan,
                'notes' => $record->notes,
            ],
        };
    }

    private function childPayload(string $resource, object $row): array
    {
        return ['id' => (string) $row->id] + match ($resource) {
            'brands' => [
                'party_id' => (string) $row->party_id,
                'agreement_number' => (string) $row->agreement_number,
                'agreement_type' => (string) $row->agreement_type,
                'effective_from' => $this->date($row->effective_from),
                'effective_to' => $this->date($row->effective_to),
                'currency_code' => (string) $row->currency_code,
                'minimum_commitment' => $this->decimal($row->minimum_commitment, 2),
                'status' => (string) $row->status,
                'notes' => $row->notes,
            ],
            'items' => [
                'from_uom_code' => (string) $row->from_uom_code,
                'to_uom_code' => (string) $row->to_uom_code,
                'multiplier' => $this->decimal($row->multiplier, 9),
                'rounding_mode' => (string) $row->rounding_mode,
            ],
            'skus' => [
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'uom_code' => (string) $row->uom_code,
                'quantity' => $this->decimal($row->quantity, 6),
                'barcode' => $row->barcode,
                'is_default' => (bool) $row->is_default,
            ],
            'recipes' => [
                'component_sku_id' => (string) $row->component_sku_id,
                'sequence_no' => (int) $row->sequence_no,
                'quantity' => $this->decimal($row->quantity, 6),
                'uom_code' => (string) $row->uom_code,
                'waste_percent' => $this->decimal($row->waste_percent, 3),
                'scaling_method' => (string) $row->scaling_method,
                'bakers_percentage' => $row->bakers_percentage === null ? null : $this->decimal($row->bakers_percentage, 6),
                'is_basis_component' => (bool) $row->is_basis_component,
                'condition_code' => $row->condition_code,
                'quantity_per_unit' => $row->quantity_per_unit === null ? null : $this->decimal($row->quantity_per_unit, 9),
                'loss_included_in_standard' => (bool) $row->loss_included_in_standard,
                'is_packaging' => (bool) $row->is_packaging,
            ],
            'routes' => [
                'sequence_no' => (int) $row->sequence_no,
                'name' => (string) $row->name,
                'work_center_code' => (string) $row->work_center_code,
                'setup_minutes' => $this->decimal($row->setup_minutes, 3),
                'run_minutes_per_unit' => $this->decimal($row->run_minutes_per_unit, 6),
                'instructions' => $row->instructions,
            ],
            'specifications' => [
                'sequence_no' => (int) $row->sequence_no,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'value_type' => (string) $row->value_type,
                'uom_code' => $row->uom_code,
                'minimum_value' => $row->minimum_value === null ? null : $this->decimal($row->minimum_value, 6),
                'target_value' => $row->target_value === null ? null : $this->decimal($row->target_value, 6),
                'maximum_value' => $row->maximum_value === null ? null : $this->decimal($row->maximum_value, 6),
                'text_requirement' => $row->text_requirement,
                'test_method' => $row->test_method,
                'is_required' => (bool) $row->is_required,
            ],
        };
    }

    private function childCounts(array $definition, array $recordIds): array
    {
        if ($recordIds === []) {
            return [];
        }

        return DB::table($definition['child_table'])->whereIn($definition['child_key'], $recordIds)
            ->select($definition['child_key'], DB::raw('COUNT(*) as aggregate_count'))
            ->groupBy($definition['child_key'])->pluck('aggregate_count', $definition['child_key'])
            ->map(fn ($value) => (int) $value)->all();
    }

    private function summary(Builder $base): array
    {
        $summary = ['total' => (clone $base)->count()];
        foreach (ProductMasterService::STATUSES as $status) {
            $summary[strtolower($status)] = (clone $base)->where('record.status', $status)->count();
        }

        return $summary;
    }

    private function lookups(string $companyId): array
    {
        $reference = fn (Builder $query) => $query->orderBy('code')->get(['id', 'code', 'name', 'status'])
            ->map(fn (object $row) => [
                'id' => (string) $row->id,
                'code' => (string) $row->code,
                'name' => (string) $row->name,
                'status' => (string) $row->status,
            ])->all();

        return [
            'statuses' => ProductMasterService::STATUSES,
            'item_types' => ProductMasterService::ITEM_TYPES,
            'agreement_types' => ProductMasterService::AGREEMENT_TYPES,
            'agreement_statuses' => ProductMasterService::AGREEMENT_STATUSES,
            'rounding_modes' => ProductMasterService::ROUNDING_MODES,
            'spec_target_types' => ProductMasterService::SPEC_TARGET_TYPES,
            'spec_value_types' => ProductMasterService::SPEC_VALUE_TYPES,
            'uoms' => DB::table('uoms')->orderBy('code')->get(['code', 'name', 'precision'])
                ->map(fn (object $row) => [
                    'code' => (string) $row->code,
                    'name' => (string) $row->name,
                    'precision' => (int) $row->precision,
                ])->all(),
            'brands' => $reference(DB::table('brands')->where('company_id', $companyId)),
            'items' => $reference(DB::table('catalog_items')->where('company_id', $companyId)),
            'skus' => DB::table('items')->where('company_id', $companyId)->orderBy('code')
                ->get(['id', 'code', 'name', 'status', 'base_uom'])
                ->map(fn (object $row) => [
                    'id' => (string) $row->id,
                    'code' => (string) $row->code,
                    'name' => (string) $row->name,
                    'status' => (string) $row->status,
                    'base_uom' => (string) $row->base_uom,
                ])->all(),
            'parties' => DB::table('parties')->where('company_id', $companyId)->orderBy('code')
                ->get(['id', 'code', 'display_name as name', 'status'])
                ->map(fn (object $row) => [
                    'id' => (string) $row->id,
                    'code' => (string) $row->code,
                    'name' => (string) $row->name,
                    'status' => (string) $row->status,
                ])->all(),
        ];
    }

    private function childOrder(string $resource): string
    {
        return match ($resource) {
            'brands' => 'agreement_number',
            'items' => 'from_uom_code',
            'skus' => 'code',
            default => 'sequence_no',
        };
    }

    private function definition(string $resource): array
    {
        if (! isset(ProductMasterService::DEFINITIONS[$resource])) {
            throw new NotFoundHttpException('Product master resource not found.');
        }

        return ProductMasterService::DEFINITIONS[$resource];
    }

    private function date(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toDateString();
    }

    private function timestamp(mixed $value): string
    {
        return CarbonImmutable::parse((string) $value)->utc()->toIso8601String();
    }

    private function decimal(mixed $value, int $scale): string
    {
        return bcadd((string) $value, '0', $scale);
    }

    private function can(array $permissions, string $permission): bool
    {
        return in_array($permission, $permissions, true);
    }
}

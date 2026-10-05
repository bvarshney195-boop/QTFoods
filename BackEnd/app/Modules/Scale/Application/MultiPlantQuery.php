<?php

namespace App\Modules\Scale\Application;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class MultiPlantQuery
{
    public function workspace(array $scope, array $permissions, array $filters = []): array
    {
        $transfers = $this->visibleTransfers($scope)
            ->when($filters['q'] ?? null, function (Builder $query, string $search): void {
                $query->where(function (Builder $nested) use ($search): void {
                    $nested->where('transfer.transfer_number', 'like', '%'.$search.'%')
                        ->orWhere('transfer.commercial_reference', 'like', '%'.$search.'%')
                        ->orWhere('route.route_code', 'like', '%'.$search.'%')
                        ->orWhere('source_plant.name', 'like', '%'.$search.'%')
                        ->orWhere('destination_plant.name', 'like', '%'.$search.'%');
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('transfer.status', $status))
            ->orderByDesc('transfer.updated_at')->limit(150)->get()
            ->map(fn (object $row): array => $this->transferPayload($row, $scope, $permissions))->all();

        $routes = $this->visibleRoutes($scope)
            ->when($filters['q'] ?? null, fn (Builder $query, string $search) => $query->where(function (Builder $nested) use ($search): void {
                $nested->where('route.route_code', 'like', '%'.$search.'%')->orWhere('route.name', 'like', '%'.$search.'%');
            }))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('route.status', $status))
            ->orderBy('route.route_code')->get()
            ->map(fn (object $row): array => $this->routePayload($row, $scope, $permissions))->all();

        $groups = $this->visibleGroups($scope)
            ->when($filters['q'] ?? null, fn (Builder $query, string $search) => $query->where(function (Builder $nested) use ($search): void {
                $nested->where('group.group_code', 'like', '%'.$search.'%')->orWhere('group.name', 'like', '%'.$search.'%');
            }))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('group.status', $status))
            ->orderBy('group.group_code')->get()
            ->map(fn (object $row): array => $this->groupPayload($row, $scope, $permissions))->all();

        $runs = DB::table('consolidation_runs as run')
            ->join('consolidation_groups as group', 'group.id', '=', 'run.consolidation_group_id')
            ->where('run.reporting_company_id', $scope['company_id'])
            ->where('run.reporting_plant_id', $scope['plant_id'])
            ->when($filters['q'] ?? null, fn (Builder $query, string $search) => $query->where(function (Builder $nested) use ($search): void {
                $nested->where('run.run_number', 'like', '%'.$search.'%')->orWhere('group.name', 'like', '%'.$search.'%');
            }))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('run.status', $status))
            ->orderByDesc('run.cutoff_date')
            ->select(['run.*', 'group.group_code', 'group.name as group_name'])
            ->get()->map(fn (object $row): array => $this->runPayload($row, $permissions))->all();

        return [
            'data' => $transfers,
            'routes' => $routes,
            'groups' => $groups,
            'consolidation_runs' => $runs,
            'meta' => ['total' => count($transfers)],
            'summary' => $this->summary($scope),
            'lookups' => $this->lookups($scope),
            'allowed_actions' => $this->screenActions($permissions),
            'scope' => $scope,
        ];
    }

    public function transfer(string $id, array $scope, array $permissions): array
    {
        $row = $this->visibleTransfers($scope)->where('transfer.id', $id)->first();
        if (! $row) {
            throw new NotFoundHttpException('Inter-plant transfer not found.');
        }

        $payload = $this->transferPayload($row, $scope, $permissions);
        $payload['lines'] = DB::table('interplant_transfer_lines as line')
            ->join('items as source_item', 'source_item.id', '=', 'line.source_item_id')
            ->join('items as destination_item', 'destination_item.id', '=', 'line.destination_item_id')
            ->join('lots as source_lot', 'source_lot.id', '=', 'line.source_lot_id')
            ->join('lots as destination_lot', 'destination_lot.id', '=', 'line.destination_lot_id')
            ->where('line.interplant_transfer_order_id', $id)->orderBy('line.line_number')
            ->select([
                'line.*', 'source_item.code as source_item_code', 'source_item.name as source_item_name',
                'destination_item.code as destination_item_code', 'destination_item.name as destination_item_name',
                'source_lot.internal_lot_code as source_lot_code',
                'destination_lot.internal_lot_code as destination_lot_code',
            ])->get()->map(fn (object $line): array => $this->payload($line))->all();

        return $payload;
    }

    public function route(string $id, array $scope, array $permissions): array
    {
        $row = $this->visibleRoutes($scope)->where('route.id', $id)->first();
        if (! $row) {
            throw new NotFoundHttpException('Plant transfer route not found.');
        }

        $payload = $this->routePayload($row, $scope, $permissions);
        $payload['mappings'] = DB::table('plant_transfer_item_mappings as mapping')
            ->join('items as source_item', 'source_item.id', '=', 'mapping.source_item_id')
            ->join('items as destination_item', 'destination_item.id', '=', 'mapping.destination_item_id')
            ->where('mapping.plant_transfer_route_id', $id)->orderBy('mapping.line_number')
            ->select([
                'mapping.*', 'source_item.code as source_item_code', 'source_item.name as source_item_name',
                'destination_item.code as destination_item_code', 'destination_item.name as destination_item_name',
            ])->get()->map(fn (object $mapping): array => $this->payload($mapping))->all();

        return $payload;
    }

    public function group(string $id, array $scope, array $permissions): array
    {
        $row = $this->visibleGroups($scope)->where('group.id', $id)->first();
        if (! $row) {
            throw new NotFoundHttpException('Consolidation group not found.');
        }

        $payload = $this->groupPayload($row, $scope, $permissions);
        $payload['members'] = DB::table('consolidation_group_members as member')
            ->join('companies as company', 'company.id', '=', 'member.company_id')
            ->where('member.consolidation_group_id', $id)->orderBy('member.member_code')
            ->select(['member.*', 'company.code as company_code', 'company.display_name as company_name', 'company.status as company_status'])
            ->get()->map(function (object $member): array {
                $value = $this->payload($member);
                $value['plant_count'] = DB::table('plants')->where('company_id', $member->company_id)->count();

                return $value;
            })->all();

        return $payload;
    }

    public function consolidation(string $id, array $scope, array $permissions): array
    {
        $row = DB::table('consolidation_runs as run')
            ->join('consolidation_groups as group', 'group.id', '=', 'run.consolidation_group_id')
            ->where('run.id', $id)->where('run.reporting_company_id', $scope['company_id'])
            ->where('run.reporting_plant_id', $scope['plant_id'])
            ->select(['run.*', 'group.group_code', 'group.name as group_name'])->first();
        if (! $row) {
            throw new NotFoundHttpException('Consolidation run not found.');
        }

        $payload = $this->runPayload($row, $permissions);
        $payload['members'] = DB::table('consolidation_run_members as member')
            ->join('companies as company', 'company.id', '=', 'member.company_id')
            ->where('member.consolidation_run_id', $id)->orderBy('company.code')
            ->select(['member.*', 'company.code as company_code', 'company.display_name as company_name'])
            ->get()->map(fn (object $member): array => $this->payload($member))->all();
        $payload['eliminations'] = DB::table('consolidation_elimination_lines')
            ->where('consolidation_run_id', $id)->orderBy('line_number')->get()
            ->map(fn (object $line): array => $this->payload($line))->all();

        return $payload;
    }

    private function visibleTransfers(array $scope): Builder
    {
        return DB::table('interplant_transfer_orders as transfer')
            ->join('plant_transfer_routes as route', 'route.id', '=', 'transfer.plant_transfer_route_id')
            ->join('companies as source_company', 'source_company.id', '=', 'transfer.source_company_id')
            ->join('plants as source_plant', 'source_plant.id', '=', 'transfer.source_plant_id')
            ->join('companies as destination_company', 'destination_company.id', '=', 'transfer.destination_company_id')
            ->join('plants as destination_plant', 'destination_plant.id', '=', 'transfer.destination_plant_id')
            ->where(function (Builder $query) use ($scope): void {
                $query->where(function (Builder $source) use ($scope): void {
                    $source->where('transfer.source_company_id', $scope['company_id'])
                        ->where('transfer.source_plant_id', $scope['plant_id']);
                })->orWhere(function (Builder $destination) use ($scope): void {
                    $destination->where('transfer.destination_company_id', $scope['company_id'])
                        ->where('transfer.destination_plant_id', $scope['plant_id']);
                });
            })
            ->select([
                'transfer.*', 'route.route_code', 'route.name as route_name', 'route.transfer_scope',
                'route.require_destination_acceptance', 'route.require_commercial_reference',
                'source_company.code as source_company_code', 'source_company.display_name as source_company_name',
                'source_plant.code as source_plant_code', 'source_plant.name as source_plant_name',
                'destination_company.code as destination_company_code', 'destination_company.display_name as destination_company_name',
                'destination_plant.code as destination_plant_code', 'destination_plant.name as destination_plant_name',
            ]);
    }

    private function visibleRoutes(array $scope): Builder
    {
        return DB::table('plant_transfer_routes as route')
            ->join('companies as source_company', 'source_company.id', '=', 'route.source_company_id')
            ->join('plants as source_plant', 'source_plant.id', '=', 'route.source_plant_id')
            ->join('companies as destination_company', 'destination_company.id', '=', 'route.destination_company_id')
            ->join('plants as destination_plant', 'destination_plant.id', '=', 'route.destination_plant_id')
            ->where(function (Builder $query) use ($scope): void {
                $query->where(function (Builder $source) use ($scope): void {
                    $source->where('route.source_company_id', $scope['company_id'])->where('route.source_plant_id', $scope['plant_id']);
                })->orWhere(function (Builder $destination) use ($scope): void {
                    $destination->where('route.destination_company_id', $scope['company_id'])->where('route.destination_plant_id', $scope['plant_id']);
                });
            })
            ->select([
                'route.*', 'source_company.code as source_company_code', 'source_company.display_name as source_company_name',
                'source_plant.code as source_plant_code', 'source_plant.name as source_plant_name',
                'destination_company.code as destination_company_code', 'destination_company.display_name as destination_company_name',
                'destination_plant.code as destination_plant_code', 'destination_plant.name as destination_plant_name',
            ]);
    }

    private function visibleGroups(array $scope): Builder
    {
        return DB::table('consolidation_groups as group')
            ->join('companies as owner', 'owner.id', '=', 'group.owner_company_id')
            ->where(function (Builder $query) use ($scope): void {
                $query->where('group.owner_company_id', $scope['company_id'])
                    ->orWhereExists(function (Builder $member) use ($scope): void {
                        $member->selectRaw('1')->from('consolidation_group_members as visible_member')
                            ->whereColumn('visible_member.consolidation_group_id', 'group.id')
                            ->where('visible_member.company_id', $scope['company_id']);
                    });
            })->select(['group.*', 'owner.code as owner_company_code', 'owner.display_name as owner_company_name']);
    }

    private function transferPayload(object $row, array $scope, array $permissions): array
    {
        $value = $this->payload($row);
        $value['side'] = $row->source_company_id === $scope['company_id'] && $row->source_plant_id === $scope['plant_id']
            ? 'SOURCE' : 'DESTINATION';
        $value['line_count'] = DB::table('interplant_transfer_lines')->where('interplant_transfer_order_id', $row->id)->count();
        $value['allowed_actions'] = $this->transferActions($row, $scope, $permissions);

        return $value;
    }

    private function routePayload(object $row, array $scope, array $permissions): array
    {
        $value = $this->payload($row);
        $value['side'] = $row->source_company_id === $scope['company_id'] && $row->source_plant_id === $scope['plant_id']
            ? 'SOURCE' : 'DESTINATION';
        $value['mapping_count'] = DB::table('plant_transfer_item_mappings')->where('plant_transfer_route_id', $row->id)->count();
        $value['allowed_actions'] = $value['side'] === 'SOURCE' ? $this->routeActions((string) $row->status, $permissions) : [];

        return $value;
    }

    private function groupPayload(object $row, array $scope, array $permissions): array
    {
        $value = $this->payload($row);
        $value['member_count'] = DB::table('consolidation_group_members')->where('consolidation_group_id', $row->id)->count();
        $value['plant_count'] = DB::table('plants')->whereIn('company_id', DB::table('consolidation_group_members')
            ->where('consolidation_group_id', $row->id)->select('company_id'))->count();
        $value['allowed_actions'] = $row->owner_company_id === $scope['company_id']
            ? $this->groupActions((string) $row->status, $permissions) : [];

        return $value;
    }

    private function runPayload(object $row, array $permissions): array
    {
        $value = $this->payload($row);
        $value['member_count'] = DB::table('consolidation_run_members')->where('consolidation_run_id', $row->id)->count();
        $value['allowed_actions'] = $row->status === 'DRAFT' && $this->has($permissions, 'CONSOLIDATION-FINALIZE')
            ? ['FINALIZE'] : [];

        return $value;
    }

    private function transferActions(object $row, array $scope, array $permissions): array
    {
        $source = $row->source_company_id === $scope['company_id'] && $row->source_plant_id === $scope['plant_id'];
        $destination = $row->destination_company_id === $scope['company_id'] && $row->destination_plant_id === $scope['plant_id'];
        $rules = [];
        if ($source) {
            $rules = match ((string) $row->status) {
                'DRAFT' => [['UPDATE', 'TRANSFER-UPDATE'], ['SUBMIT', 'TRANSFER-SUBMIT'], ['CANCEL', 'TRANSFER-CANCEL']],
                'SUBMITTED' => [['APPROVE', 'TRANSFER-APPROVE'], ['CANCEL', 'TRANSFER-CANCEL']],
                'SOURCE_APPROVED', 'APPROVED' => [['DISPATCH', 'TRANSFER-DISPATCH'], ['CANCEL', 'TRANSFER-CANCEL']],
                default => [],
            };
            if ($row->status === 'SOURCE_APPROVED' && (bool) $row->require_destination_acceptance) {
                $rules = [['CANCEL', 'TRANSFER-CANCEL']];
            }
        } elseif ($destination) {
            $rules = match ((string) $row->status) {
                'SOURCE_APPROVED' => [['ACCEPT', 'TRANSFER-ACCEPT']],
                'IN_TRANSIT' => [['RECEIVE', 'TRANSFER-RECEIVE']],
                default => [],
            };
        }

        return collect($rules)->filter(fn (array $rule): bool => $this->has($permissions, $rule[1]))
            ->pluck(0)->values()->all();
    }

    private function groupActions(string $status, array $permissions): array
    {
        $rules = match ($status) {
            'DRAFT' => [['UPDATE', 'GROUP-UPDATE'], ['ACTIVATE', 'GROUP-ACTIVATE']],
            'ACTIVE' => [['RETIRE', 'GROUP-RETIRE']],
            default => [],
        };

        return $this->filterActions($rules, $permissions);
    }

    private function routeActions(string $status, array $permissions): array
    {
        $rules = match ($status) {
            'DRAFT' => [['UPDATE', 'ROUTE-UPDATE'], ['ACTIVATE', 'ROUTE-ACTIVATE']],
            'ACTIVE' => [['DEACTIVATE', 'ROUTE-DEACTIVATE']],
            default => [],
        };

        return $this->filterActions($rules, $permissions);
    }

    private function filterActions(array $rules, array $permissions): array
    {
        return collect($rules)->filter(fn (array $rule): bool => $this->has($permissions, $rule[1]))
            ->pluck(0)->values()->all();
    }

    private function has(array $permissions, string $action): bool
    {
        return in_array('ACTION:SCALE-PLANT:'.$action, $permissions, true);
    }

    private function screenActions(array $permissions): array
    {
        $prefix = 'ACTION:SCALE-PLANT:';

        return collect($permissions)->filter(fn (string $permission): bool => str_starts_with($permission, $prefix))
            ->map(fn (string $permission): string => substr($permission, strlen($prefix)))->values()->all();
    }

    private function summary(array $scope): array
    {
        $visible = fn () => $this->visibleTransfers($scope);
        $stockByUom = DB::table('stock_positions')->where($scope)->groupBy('uom_code')
            ->orderBy('uom_code')->get(['uom_code', DB::raw('SUM(quantity_base) as quantity')])
            ->map(fn (object $row): array => [
                'uom_code' => (string) $row->uom_code,
                'quantity' => $this->decimal($row->quantity),
            ])->all();

        return [
            'active_routes' => $this->visibleRoutes($scope)->where('route.status', 'ACTIVE')->count(),
            'open_transfers' => $visible()->whereIn('transfer.status', ['DRAFT', 'SUBMITTED', 'SOURCE_APPROVED', 'APPROVED'])->count(),
            'in_transit' => $visible()->where('transfer.status', 'IN_TRANSIT')->count(),
            'awaiting_destination' => $visible()->where('transfer.status', 'SOURCE_APPROVED')->count(),
            'active_groups' => $this->visibleGroups($scope)->where('group.status', 'ACTIVE')->count(),
            'plant_stock_by_uom' => $stockByUom,
            'plant_stock_quantity' => count($stockByUom) === 1 ? $stockByUom[0]['quantity'] : null,
            'plant_stock_uom' => count($stockByUom) === 1 ? $stockByUom[0]['uom_code'] : null,
        ];
    }

    private function lookups(array $scope): array
    {
        $companyIds = $this->visibleGroups($scope)->pluck('group.id')->flatMap(function (string $groupId) {
            return DB::table('consolidation_group_members')->where('consolidation_group_id', $groupId)->pluck('company_id');
        })->push($scope['company_id'])->unique()->values();

        $plants = DB::table('plants as plant')->join('companies as company', 'company.id', '=', 'plant.company_id')
            ->whereIn('plant.company_id', $companyIds)->where('plant.status', 'ACTIVE')->where('company.status', 'ACTIVE')
            ->orderBy('company.code')->orderBy('plant.code')
            ->get(['plant.id', 'plant.company_id', 'plant.code', 'plant.name', 'plant.timezone', 'company.code as company_code', 'company.display_name as company_name'])
            ->map(fn (object $row): array => $this->payload($row))->all();

        $sourcePositions = $this->eligiblePositions()->where('position.company_id', $scope['company_id'])
            ->where('position.plant_id', $scope['plant_id'])
            ->whereRaw('position.quantity_base > position.reserved_quantity_base')->get()
            ->map(fn (object $row): array => $this->positionPayload($row))->all();

        $destinationScopes = $this->visibleRoutes($scope)->where('route.status', 'ACTIVE')
            ->where('route.source_company_id', $scope['company_id'])->where('route.source_plant_id', $scope['plant_id'])
            ->get(['route.destination_company_id', 'route.destination_plant_id']);
        $destinationPositions = collect();
        foreach ($destinationScopes as $destination) {
            $destinationPositions = $destinationPositions->merge($this->eligiblePositions()
                ->where('position.company_id', $destination->destination_company_id)
                ->where('position.plant_id', $destination->destination_plant_id)->get());
        }

        return [
            'plants' => $plants,
            'groups' => $this->visibleGroups($scope)->where('group.status', 'ACTIVE')
                ->get(['group.id', 'group.group_code', 'group.name', 'group.base_currency'])
                ->map(fn (object $row): array => $this->payload($row))->all(),
            'group_members' => DB::table('consolidation_group_members as member')
                ->join('consolidation_groups as group', 'group.id', '=', 'member.consolidation_group_id')
                ->join('companies as company', 'company.id', '=', 'member.company_id')
                ->whereIn('group.id', $this->visibleGroups($scope)->where('group.status', 'ACTIVE')->select('group.id'))
                ->orderBy('group.group_code')->orderBy('member.member_code')
                ->get([
                    'member.consolidation_group_id', 'member.company_id', 'member.member_code',
                    'member.reporting_currency', 'company.code as company_code', 'company.display_name as company_name',
                ])->map(fn (object $row): array => $this->payload($row))->all(),
            'routes' => $this->visibleRoutes($scope)->where('route.status', 'ACTIVE')
                ->where('route.source_company_id', $scope['company_id'])->where('route.source_plant_id', $scope['plant_id'])
                ->get()->map(fn (object $row): array => $this->routePayload($row, $scope, []))->all(),
            'source_positions' => $sourcePositions,
            'destination_positions' => $destinationPositions->unique('id')->values()
                ->map(fn (object $row): array => $this->positionPayload($row))->all(),
            'items' => DB::table('items')->whereIn('company_id', $companyIds)->where('status', 'ACTIVE')
                ->orderBy('company_id')->orderBy('code')->get(['id', 'company_id', 'code', 'name', 'base_uom'])
                ->map(fn (object $row): array => $this->payload($row))->all(),
            'currencies' => ['INR', 'USD', 'EUR', 'GBP'],
        ];
    }

    private function eligiblePositions(): Builder
    {
        return DB::table('stock_positions as position')
            ->join('items as item', 'item.id', '=', 'position.item_id')
            ->join('lots as lot', 'lot.id', '=', 'position.lot_id')
            ->join('inventory_owners as owner', 'owner.id', '=', 'position.inventory_owner_id')
            ->join('locations as location', 'location.id', '=', 'position.location_id')
            ->where('position.quality_status', 'RELEASED')->where('item.status', 'ACTIVE')
            ->where('lot.status', 'ACTIVE')->where('owner.status', 'ACTIVE')->where('location.status', 'ACTIVE')
            ->where(function (Builder $query): void {
                $query->whereNull('lot.expiry_date')->orWhereDate('lot.expiry_date', '>=', now()->toDateString());
            })
            ->orderBy('item.code')->orderBy('lot.expiry_date')->orderBy('position.id')
            ->select([
                'position.*', 'item.code as item_code', 'item.name as item_name',
                'lot.internal_lot_code as lot_code', 'lot.expiry_date',
                'owner.code as owner_code', 'owner.name as owner_name',
                'location.code as location_code', 'location.name as location_name',
            ]);
    }

    private function positionPayload(object $row): array
    {
        $value = $this->payload($row);
        $value['available_quantity'] = bcsub((string) $row->quantity_base, (string) $row->reserved_quantity_base, 6);

        return $value;
    }

    private function payload(object $row): array
    {
        return (array) $row;
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 6);
    }
}

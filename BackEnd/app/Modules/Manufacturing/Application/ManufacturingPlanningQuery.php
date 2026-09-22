<?php

namespace App\Modules\Manufacturing\Application;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ManufacturingPlanningQuery
{
    public const SORTS = ['NEWEST', 'OLDEST', 'NUMBER', 'HORIZON', 'STATUS'];

    public function demandWorkspace(array $scope, array $filters, array $permissions): array
    {
        $base = $this->demandBase($scope);
        $query = clone $base;
        $this->applyFilters($query, $filters, 'plan.plan_number', ['plan.name'], 'plan.status');
        $this->sort($query, $filters['sort'] ?? 'NEWEST', 'plan', 'plan_number', 'horizon_start');
        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($page->items())->map(fn (object $row) => $this->demandPayload($row, $permissions))->all(),
            'meta' => $this->meta($page),
            'summary' => [
                'total' => (clone $base)->count(),
                'draft' => (clone $base)->where('plan.status', 'DRAFT')->count(),
                'released' => (clone $base)->where('plan.status', 'RELEASED')->count(),
                'cancelled' => (clone $base)->where('plan.status', 'CANCELLED')->count(),
                'demand_quantity' => $this->decimal(DB::table('demand_plan_lines')
                    ->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])->sum('quantity')),
            ],
            'lookups' => [
                'statuses' => ManufacturingPlanningService::DEMAND_STATUSES,
                'demand_types' => ManufacturingPlanningService::DEMAND_TYPES,
                'sorts' => self::SORTS,
                'output_skus' => $this->outputSkus($scope),
            ],
            'allowed_actions' => $this->can($permissions, 'ACTION:PLAN-DEM:CREATE') ? ['CREATE'] : [],
        ];
    }

    public function demandDetail(string $id, array $scope, array $permissions): array
    {
        $row = $this->demandBase($scope)->where('plan.id', $id)->first();
        if (! $row) {
            throw new NotFoundHttpException('Demand plan not found.');
        }
        $payload = $this->demandPayload($row, $permissions);
        $payload['lines'] = DB::table('demand_plan_lines as line')
            ->join('items as sku', 'sku.id', '=', 'line.output_sku_id')
            ->where('line.demand_plan_id', $id)->orderBy('line.line_number')
            ->get(['line.*', 'sku.code as sku_code', 'sku.name as sku_name', 'sku.item_type'])
            ->map(fn (object $line) => [
                'id' => (string) $line->id, 'line_number' => (int) $line->line_number,
                'output_sku' => ['id' => (string) $line->output_sku_id, 'code' => $line->sku_code, 'name' => $line->sku_name, 'item_type' => $line->item_type],
                'demand_date' => (string) $line->demand_date, 'demand_type' => $line->demand_type,
                'quantity' => $this->decimal($line->quantity), 'uom_code' => $line->uom_code,
                'notes' => $line->notes,
            ])->all();

        return $payload;
    }

    public function mrpWorkspace(array $scope, array $filters, array $permissions): array
    {
        $base = $this->mrpBase($scope);
        $query = clone $base;
        $this->applyFilters($query, $filters, 'run.run_number', ['plan.plan_number', 'plan.name'], 'run.status');
        $this->sort($query, $filters['sort'] ?? 'NEWEST', 'run', 'run_number', 'run_date');
        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($page->items())->map(fn (object $row) => $this->mrpPayload($row, $permissions))->all(),
            'meta' => $this->meta($page),
            'summary' => [
                'total' => (clone $base)->count(),
                'completed' => (clone $base)->where('run.status', 'COMPLETED')->count(),
                'cancelled' => (clone $base)->where('run.status', 'CANCELLED')->count(),
                'planned_orders' => (int) DB::table('mrp_planned_orders')->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])->count(),
                'shortage_quantity' => $this->decimal(DB::table('mrp_material_requirements')->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])->sum('shortage_quantity')),
            ],
            'lookups' => [
                'statuses' => ManufacturingPlanningService::MRP_STATUSES,
                'sorts' => self::SORTS,
                'released_demand_plans' => $this->releasedDemandPlans($scope),
            ],
            'allowed_actions' => $this->can($permissions, 'ACTION:PLAN-MRP:RUN') ? ['RUN'] : [],
        ];
    }

    public function mrpDetail(string $id, array $scope, array $permissions): array
    {
        $row = $this->mrpBase($scope)->where('run.id', $id)->first();
        if (! $row) {
            throw new NotFoundHttpException('MRP run not found.');
        }
        $payload = $this->mrpPayload($row, $permissions);
        $payload['planned_orders'] = DB::table('mrp_planned_orders as planned')
            ->join('items as sku', 'sku.id', '=', 'planned.output_sku_id')
            ->join('recipes as recipe', 'recipe.id', '=', 'planned.recipe_id')
            ->where('planned.mrp_run_id', $id)->orderBy('planned.line_number')
            ->get([
                'planned.*', 'sku.code as sku_code', 'sku.name as sku_name',
                'recipe.code as recipe_code', 'recipe.name as recipe_name',
            ])->map(function (object $order): array {
                $materials = DB::table('mrp_material_requirements as requirement')
                    ->join('items as sku', 'sku.id', '=', 'requirement.component_sku_id')
                    ->where('requirement.mrp_planned_order_id', $order->id)
                    ->orderBy('requirement.line_number')
                    ->get(['requirement.*', 'sku.code as sku_code', 'sku.name as sku_name'])
                    ->map(fn (object $material) => [
                        'id' => (string) $material->id, 'line_number' => (int) $material->line_number,
                        'component_sku' => ['id' => (string) $material->component_sku_id, 'code' => $material->sku_code, 'name' => $material->sku_name],
                        'gross_requirement' => $this->decimal($material->gross_requirement),
                        'on_hand_snapshot' => $this->decimal($material->on_hand_snapshot),
                        'reserved_snapshot' => $this->decimal($material->reserved_snapshot),
                        'available_snapshot' => $this->decimal($material->available_snapshot),
                        'shortage_quantity' => $this->decimal($material->shortage_quantity),
                        'uom_code' => $material->uom_code,
                    ])->all();

                return [
                    'id' => (string) $order->id, 'line_number' => (int) $order->line_number,
                    'output_sku' => ['id' => (string) $order->output_sku_id, 'code' => $order->sku_code, 'name' => $order->sku_name],
                    'recipe' => ['id' => (string) $order->recipe_id, 'code' => $order->recipe_code, 'name' => $order->recipe_name, 'revision' => (int) $order->recipe_revision_snapshot],
                    'due_date' => (string) $order->due_date, 'planned_quantity' => $this->decimal($order->planned_quantity),
                    'uom_code' => $order->uom_code, 'materials' => $materials,
                ];
            })->all();

        return $payload;
    }

    public function scheduleWorkspace(array $scope, array $filters, array $permissions): array
    {
        $base = $this->scheduleBase($scope);
        $query = clone $base;
        $this->applyFilters($query, $filters, 'schedule.schedule_number', ['run.run_number', 'plan.plan_number'], 'schedule.status');
        $this->sort($query, $filters['sort'] ?? 'NEWEST', 'schedule', 'schedule_number', 'horizon_start');
        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($page->items())->map(fn (object $row) => $this->schedulePayload($row, $permissions))->all(),
            'meta' => $this->meta($page),
            'summary' => [
                'total' => (clone $base)->count(),
                'draft' => (clone $base)->where('schedule.status', 'DRAFT')->count(),
                'released' => (clone $base)->where('schedule.status', 'RELEASED')->count(),
                'cancelled' => (clone $base)->where('schedule.status', 'CANCELLED')->count(),
                'active_reservations' => DB::table('production_material_reservations')->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])->whereNull('released_at')->count(),
            ],
            'lookups' => [
                'statuses' => ManufacturingPlanningService::SCHEDULE_STATUSES,
                'sorts' => self::SORTS,
                'mrp_runs' => $this->schedulableMrpRuns($scope),
            ],
            'allowed_actions' => $this->can($permissions, 'ACTION:PLAN-SCH:CREATE') ? ['CREATE'] : [],
        ];
    }

    public function scheduleDetail(string $id, array $scope, array $permissions): array
    {
        $row = $this->scheduleBase($scope)->where('schedule.id', $id)->first();
        if (! $row) {
            throw new NotFoundHttpException('Production schedule not found.');
        }
        $payload = $this->schedulePayload($row, $permissions);
        $payload['lines'] = DB::table('production_schedule_lines as line')
            ->join('items as sku', 'sku.id', '=', 'line.output_sku_id')
            ->join('recipes as recipe', 'recipe.id', '=', 'line.recipe_id')
            ->join('production_routes as route', 'route.id', '=', 'line.route_id')
            ->where('line.production_schedule_id', $id)->orderBy('line.line_number')
            ->get([
                'line.*', 'sku.code as sku_code', 'sku.name as sku_name',
                'recipe.code as recipe_code', 'route.code as route_code', 'route.name as route_name',
            ])->map(function (object $line) use ($id): array {
                $operations = DB::table('production_schedule_operations')->where('production_schedule_line_id', $line->id)
                    ->orderBy('sequence_no')->get()->map(fn (object $operation) => [
                        'id' => (string) $operation->id, 'sequence_no' => (int) $operation->sequence_no,
                        'name' => $operation->operation_name, 'work_center_code' => $operation->work_center_code,
                        'setup_minutes' => $this->decimal($operation->setup_minutes_snapshot),
                        'run_minutes_per_unit' => $this->decimal($operation->run_minutes_per_unit_snapshot),
                        'required_minutes' => $this->decimal($operation->required_minutes),
                    ])->all();
                $materials = DB::table('mrp_material_requirements as requirement')
                    ->join('items as sku', 'sku.id', '=', 'requirement.component_sku_id')
                    ->where('requirement.mrp_planned_order_id', $line->mrp_planned_order_id)
                    ->orderBy('requirement.line_number')
                    ->get(['requirement.*', 'sku.code as sku_code', 'sku.name as sku_name'])
                    ->map(function (object $material) use ($id): array {
                        $reserved = DB::table('production_material_reservations')->where('production_schedule_id', $id)
                            ->where('mrp_material_requirement_id', $material->id)->whereNull('released_at')->sum('quantity_base');
                        return [
                            'id' => (string) $material->id,
                            'component_sku' => ['id' => (string) $material->component_sku_id, 'code' => $material->sku_code, 'name' => $material->sku_name],
                            'gross_requirement' => $this->decimal($material->gross_requirement),
                            'mrp_shortage_quantity' => $this->decimal($material->shortage_quantity),
                            'reserved_quantity' => $this->decimal($reserved), 'uom_code' => $material->uom_code,
                        ];
                    })->all();

                return [
                    'id' => (string) $line->id, 'mrp_planned_order_id' => (string) $line->mrp_planned_order_id,
                    'line_number' => (int) $line->line_number,
                    'output_sku' => ['id' => (string) $line->output_sku_id, 'code' => $line->sku_code, 'name' => $line->sku_name],
                    'recipe' => ['id' => (string) $line->recipe_id, 'code' => $line->recipe_code],
                    'route' => ['id' => (string) $line->route_id, 'code' => $line->route_code, 'name' => $line->route_name],
                    'planned_quantity' => $this->decimal($line->planned_quantity), 'uom_code' => $line->uom_code,
                    'planned_start_date' => (string) $line->planned_start_date,
                    'planned_end_date' => (string) $line->planned_end_date,
                    'operations' => $operations, 'materials' => $materials,
                ];
            })->all();
        $payload['capacities'] = DB::table('production_schedule_capacities')
            ->where('production_schedule_id', $id)->orderBy('work_center_code')->get()
            ->map(fn (object $capacity) => [
                'id' => (string) $capacity->id, 'work_center_code' => $capacity->work_center_code,
                'daily_capacity_minutes' => $this->decimal($capacity->daily_capacity_minutes),
                'working_days' => (int) $capacity->working_days,
                'available_minutes' => $this->decimal($capacity->available_minutes),
                'required_minutes' => $this->decimal($capacity->required_minutes),
                'utilisation_percent' => $this->decimal($capacity->utilisation_percent),
                'is_overloaded' => (bool) $capacity->is_overloaded,
            ])->all();
        $payload['reservations'] = DB::table('production_material_reservations as link')
            ->join('stock_reservations as reservation', 'reservation.id', '=', 'link.stock_reservation_id')
            ->join('stock_positions as position', 'position.id', '=', 'reservation.stock_position_id')
            ->join('lots as lot', 'lot.id', '=', 'position.lot_id')
            ->join('items as item', 'item.id', '=', 'position.item_id')
            ->where('link.production_schedule_id', $id)->orderBy('item.code')->orderBy('lot.expiry_date')
            ->get([
                'link.*', 'reservation.reservation_number', 'reservation.status',
                'position.id as stock_position_id', 'item.code as item_code',
                'lot.internal_lot_code', 'lot.expiry_date',
            ])->map(fn (object $reservation) => [
                'id' => (string) $reservation->id, 'number' => $reservation->reservation_number,
                'status' => $reservation->status, 'stock_position_id' => (string) $reservation->stock_position_id,
                'item_code' => $reservation->item_code, 'lot_code' => $reservation->internal_lot_code,
                'expiry_date' => $reservation->expiry_date ? (string) $reservation->expiry_date : null,
                'quantity' => $this->decimal($reservation->quantity_base), 'uom_code' => $reservation->uom_code,
            ])->all();

        return $payload;
    }

    private function demandBase(array $scope): Builder
    {
        return DB::table('demand_plans as plan')
            ->join('users as creator', 'creator.id', '=', 'plan.created_by')
            ->where('plan.company_id', $scope['company_id'])->where('plan.plant_id', $scope['plant_id'])
            ->select(['plan.*', 'creator.name as creator_name'])
            ->selectSub(fn (Builder $line) => $line->from('demand_plan_lines')->whereColumn('demand_plan_id', 'plan.id')->selectRaw('COUNT(*)'), 'line_count')
            ->selectSub(fn (Builder $run) => $run->from('mrp_runs')->whereColumn('demand_plan_id', 'plan.id')->where('status', 'COMPLETED')->selectRaw('COUNT(*)'), 'active_mrp_count');
    }

    private function mrpBase(array $scope): Builder
    {
        return DB::table('mrp_runs as run')
            ->join('demand_plans as plan', 'plan.id', '=', 'run.demand_plan_id')
            ->join('users as creator', 'creator.id', '=', 'run.created_by')
            ->where('run.company_id', $scope['company_id'])->where('run.plant_id', $scope['plant_id'])
            ->select([
                'run.*', 'plan.plan_number', 'plan.name as plan_name', 'creator.name as creator_name',
            ])->selectSub(fn (Builder $order) => $order->from('mrp_planned_orders')->whereColumn('mrp_run_id', 'run.id')->selectRaw('COUNT(*)'), 'planned_order_count')
            ->selectSub(fn (Builder $material) => $material->from('mrp_material_requirements')->whereColumn('mrp_run_id', 'run.id')->selectRaw('COUNT(*)'), 'material_requirement_count')
            ->selectSub(fn (Builder $material) => $material->from('mrp_material_requirements')->whereColumn('mrp_run_id', 'run.id')->selectRaw('COALESCE(SUM(shortage_quantity), 0)'), 'shortage_quantity')
            ->selectSub(fn (Builder $schedule) => $schedule->from('production_schedules')->whereColumn('mrp_run_id', 'run.id')->where('status', '<>', 'CANCELLED')->selectRaw('COUNT(*)'), 'active_schedule_count');
    }

    private function scheduleBase(array $scope): Builder
    {
        return DB::table('production_schedules as schedule')
            ->join('mrp_runs as run', 'run.id', '=', 'schedule.mrp_run_id')
            ->join('demand_plans as plan', 'plan.id', '=', 'run.demand_plan_id')
            ->join('users as creator', 'creator.id', '=', 'schedule.created_by')
            ->where('schedule.company_id', $scope['company_id'])->where('schedule.plant_id', $scope['plant_id'])
            ->select([
                'schedule.*', 'run.run_number', 'plan.plan_number', 'creator.name as creator_name',
            ])->selectSub(fn (Builder $line) => $line->from('production_schedule_lines')->whereColumn('production_schedule_id', 'schedule.id')->selectRaw('COUNT(*)'), 'line_count')
            ->selectSub(fn (Builder $capacity) => $capacity->from('production_schedule_capacities')->whereColumn('production_schedule_id', 'schedule.id')->where('is_overloaded', true)->selectRaw('COUNT(*)'), 'overloaded_work_centers')
            ->selectSub(fn (Builder $reservation) => $reservation->from('production_material_reservations')->whereColumn('production_schedule_id', 'schedule.id')->whereNull('released_at')->selectRaw('COUNT(*)'), 'active_reservation_count')
            ->selectSub(fn (Builder $reservation) => $reservation->from('production_material_reservations')->whereColumn('production_schedule_id', 'schedule.id')->whereNull('released_at')->selectRaw('COALESCE(SUM(quantity_base), 0)'), 'reserved_quantity');
    }

    private function demandPayload(object $row, array $permissions): array
    {
        $actions = [];
        if ($row->status === 'DRAFT' && $this->can($permissions, 'ACTION:PLAN-DEM:UPDATE')) {
            $actions[] = 'UPDATE';
        }
        if ($row->status === 'DRAFT' && $this->can($permissions, 'ACTION:PLAN-DEM:RELEASE')) {
            $actions[] = 'RELEASE';
        }
        if (in_array($row->status, ['DRAFT', 'RELEASED'], true) && (int) $row->active_mrp_count === 0
            && $this->can($permissions, 'ACTION:PLAN-DEM:CANCEL')) {
            $actions[] = 'CANCEL';
        }
        return [
            'id' => (string) $row->id, 'company_id' => (string) $row->company_id, 'plant_id' => (string) $row->plant_id,
            'plan_number' => $row->plan_number, 'name' => $row->name,
            'horizon_start' => (string) $row->horizon_start, 'horizon_end' => (string) $row->horizon_end,
            'notes' => $row->notes, 'status' => $row->status, 'record_version' => (int) $row->record_version,
            'line_count' => (int) $row->line_count, 'active_mrp_count' => (int) $row->active_mrp_count,
            'created_by' => ['id' => (string) $row->created_by, 'name' => $row->creator_name],
            'released_at' => $this->timestamp($row->released_at), 'cancelled_at' => $this->timestamp($row->cancelled_at),
            'cancellation_reason' => $row->cancellation_reason, 'allowed_actions' => $actions,
            'created_at' => $this->timestamp($row->created_at), 'updated_at' => $this->timestamp($row->updated_at),
        ];
    }

    private function mrpPayload(object $row, array $permissions): array
    {
        $actions = $row->status === 'COMPLETED' && (int) $row->active_schedule_count === 0
            && $this->can($permissions, 'ACTION:PLAN-MRP:CANCEL') ? ['CANCEL'] : [];
        return [
            'id' => (string) $row->id, 'company_id' => (string) $row->company_id, 'plant_id' => (string) $row->plant_id,
            'run_number' => $row->run_number,
            'demand_plan' => ['id' => (string) $row->demand_plan_id, 'number' => $row->plan_number, 'name' => $row->plan_name, 'version' => (int) $row->demand_plan_version_snapshot],
            'run_date' => (string) $row->run_date, 'status' => $row->status, 'record_version' => (int) $row->record_version,
            'planned_order_count' => (int) $row->planned_order_count,
            'material_requirement_count' => (int) $row->material_requirement_count,
            'shortage_quantity' => $this->decimal($row->shortage_quantity),
            'active_schedule_count' => (int) $row->active_schedule_count,
            'created_by' => ['id' => (string) $row->created_by, 'name' => $row->creator_name],
            'completed_at' => $this->timestamp($row->completed_at), 'cancelled_at' => $this->timestamp($row->cancelled_at),
            'cancellation_reason' => $row->cancellation_reason, 'allowed_actions' => $actions,
        ];
    }

    private function schedulePayload(object $row, array $permissions): array
    {
        $actions = [];
        if ($row->status === 'DRAFT' && $this->can($permissions, 'ACTION:PLAN-SCH:UPDATE')) {
            $actions[] = 'UPDATE';
        }
        if ($row->status === 'DRAFT' && (int) $row->overloaded_work_centers === 0
            && $this->can($permissions, 'ACTION:PLAN-SCH:RELEASE')) {
            $actions[] = 'RELEASE';
        }
        if (in_array($row->status, ['DRAFT', 'RELEASED'], true) && $this->can($permissions, 'ACTION:PLAN-SCH:CANCEL')) {
            $actions[] = 'CANCEL';
        }
        return [
            'id' => (string) $row->id, 'company_id' => (string) $row->company_id, 'plant_id' => (string) $row->plant_id,
            'schedule_number' => $row->schedule_number,
            'mrp_run' => ['id' => (string) $row->mrp_run_id, 'number' => $row->run_number],
            'demand_plan_number' => $row->plan_number,
            'horizon_start' => (string) $row->horizon_start, 'horizon_end' => (string) $row->horizon_end,
            'notes' => $row->notes, 'status' => $row->status, 'record_version' => (int) $row->record_version,
            'line_count' => (int) $row->line_count, 'overloaded_work_centers' => (int) $row->overloaded_work_centers,
            'active_reservation_count' => (int) $row->active_reservation_count,
            'reserved_quantity' => $this->decimal($row->reserved_quantity),
            'created_by' => ['id' => (string) $row->created_by, 'name' => $row->creator_name],
            'released_at' => $this->timestamp($row->released_at), 'cancelled_at' => $this->timestamp($row->cancelled_at),
            'cancellation_reason' => $row->cancellation_reason, 'allowed_actions' => $actions,
        ];
    }

    private function outputSkus(array $scope): array
    {
        return DB::table('items as sku')->where('sku.company_id', $scope['company_id'])->where('sku.status', 'ACTIVE')
            ->whereIn('sku.item_type', ['INTERMEDIATE', 'FINISHED_GOOD'])
            ->orderBy('sku.code')->get(['sku.id', 'sku.code', 'sku.name', 'sku.item_type', 'sku.base_uom', 'sku.catalog_item_id'])
            ->map(function (object $sku): array {
                $hasRecipe = DB::table('recipes')->where('output_sku_id', $sku->id)->where('status', 'ACTIVE')->exists();
                $hasRoute = $sku->catalog_item_id && DB::table('production_routes')->where('catalog_item_id', $sku->catalog_item_id)->where('status', 'ACTIVE')->exists();
                $issues = [];
                if (! $hasRecipe) $issues[] = 'active recipe required';
                if (! $hasRoute) $issues[] = 'active production route required';
                return [
                    'id' => (string) $sku->id, 'code' => $sku->code, 'name' => $sku->name,
                    'item_type' => $sku->item_type, 'uom_code' => $sku->base_uom,
                    'eligible' => $issues === [], 'eligibility_issues' => $issues,
                ];
            })->all();
    }

    private function releasedDemandPlans(array $scope): array
    {
        return DB::table('demand_plans as plan')->where('plan.company_id', $scope['company_id'])
            ->where('plan.plant_id', $scope['plant_id'])->where('plan.status', 'RELEASED')
            ->whereNotExists(fn (Builder $run) => $run->selectRaw('1')->from('mrp_runs')
                ->whereColumn('mrp_runs.demand_plan_id', 'plan.id')->where('mrp_runs.status', 'COMPLETED'))
            ->orderBy('plan.horizon_start')->get(['plan.id', 'plan.plan_number', 'plan.name', 'plan.horizon_start', 'plan.horizon_end', 'plan.record_version'])
            ->map(fn (object $plan) => [
                'id' => (string) $plan->id, 'number' => $plan->plan_number, 'name' => $plan->name,
                'horizon_start' => (string) $plan->horizon_start, 'horizon_end' => (string) $plan->horizon_end,
                'record_version' => (int) $plan->record_version,
            ])->all();
    }

    private function schedulableMrpRuns(array $scope): array
    {
        return DB::table('mrp_runs as run')->join('demand_plans as plan', 'plan.id', '=', 'run.demand_plan_id')
            ->where('run.company_id', $scope['company_id'])->where('run.plant_id', $scope['plant_id'])
            ->where('run.status', 'COMPLETED')->orderByDesc('run.run_date')
            ->get(['run.id', 'run.run_number', 'run.run_date', 'plan.plan_number'])
            ->map(function (object $run) use ($scope): array {
                $orders = DB::table('mrp_planned_orders as planned')
                    ->join('items as sku', 'sku.id', '=', 'planned.output_sku_id')
                    ->where('planned.mrp_run_id', $run->id)
                    ->whereNotExists(fn (Builder $line) => $line->selectRaw('1')->from('production_schedule_lines')
                        ->whereColumn('production_schedule_lines.mrp_planned_order_id', 'planned.id')->where('production_schedule_lines.is_active', true))
                    ->orderBy('planned.line_number')->get([
                        'planned.id', 'planned.due_date', 'planned.planned_quantity', 'planned.uom_code',
                        'sku.code as sku_code', 'sku.name as sku_name', 'sku.catalog_item_id',
                    ])->map(function (object $order) use ($scope): array {
                        $routeId = DB::table('production_routes')
                            ->where('company_id', $scope['company_id'])
                            ->where('catalog_item_id', $order->catalog_item_id)
                            ->where('status', 'ACTIVE')->orderBy('code')->value('id');
                        $centers = $routeId === null ? [] : DB::table('route_operations')
                            ->where('route_id', $routeId)->orderBy('sequence_no')
                            ->pluck('work_center_code')->unique()->values()->all();
                        return [
                            'id' => (string) $order->id,
                            'output_sku' => ['code' => $order->sku_code, 'name' => $order->sku_name],
                            'due_date' => (string) $order->due_date,
                            'planned_quantity' => $this->decimal($order->planned_quantity),
                            'uom_code' => $order->uom_code, 'work_centers' => $centers,
                        ];
                    })->all();
                return [
                    'id' => (string) $run->id, 'number' => $run->run_number,
                    'run_date' => (string) $run->run_date, 'demand_plan_number' => $run->plan_number,
                    'planned_orders' => $orders,
                ];
            })->filter(fn (array $run) => $run['planned_orders'] !== [])->values()->all();
    }

    private function applyFilters(Builder $query, array $filters, string $number, array $other, string $status): void
    {
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $query) use ($needle, $number, $other): void {
                foreach ([$number, ...$other] as $column) {
                    $query->orWhereRaw("LOWER(COALESCE({$column}, '')) LIKE ?", [$needle]);
                }
            });
        }
        if (! empty($filters['status'])) {
            $query->where($status, $filters['status']);
        }
    }

    private function sort(Builder $query, string $sort, string $alias, string $number, string $date): void
    {
        match ($sort) {
            'OLDEST' => $query->orderBy("{$alias}.created_at")->orderBy("{$alias}.id"),
            'NUMBER' => $query->orderBy("{$alias}.{$number}")->orderBy("{$alias}.id"),
            'HORIZON' => $query->orderBy("{$alias}.{$date}")->orderBy("{$alias}.id"),
            'STATUS' => $query->orderBy("{$alias}.status")->orderByDesc("{$alias}.updated_at"),
            default => $query->orderByDesc("{$alias}.updated_at")->orderByDesc("{$alias}.id"),
        };
    }

    private function meta(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
    }

    private function can(array $permissions, string $permission): bool
    {
        return in_array($permission, $permissions, true);
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 6);
    }
}

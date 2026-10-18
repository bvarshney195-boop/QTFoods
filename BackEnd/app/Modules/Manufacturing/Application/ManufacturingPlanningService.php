<?php

namespace App\Modules\Manufacturing\Application;

use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use App\Shared\Units\UomQuantityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ManufacturingPlanningService
{
    public const DEMAND_STATUSES = ['DRAFT', 'RELEASED', 'CANCELLED'];
    public const DEMAND_TYPES = ['FORECAST', 'FIRM', 'SAFETY_STOCK'];
    public const MRP_STATUSES = ['COMPLETED', 'CANCELLED'];
    public const SCHEDULE_STATUSES = ['DRAFT', 'RELEASED', 'CANCELLED'];

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
        private readonly RecipeYieldService $recipeYield,
        private readonly UomQuantityService $uomQuantities,
    ) {}

    public function createDemand(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'manufacturing.demand-plan.create';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }
            $this->assertUniqueNumber('demand_plans', 'plan_number', $data['plan_number'], $data);
            [$start, $end] = $this->horizon($data);
            $lines = $this->prepareDemandLines($data['lines'], $data, $start, $end);
            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('demand_plans')->insert([
                'id' => $id,
                'company_id' => $data['company_id'],
                'plant_id' => $data['plant_id'],
                'plan_number' => $data['plan_number'],
                'name' => trim($data['name']),
                'horizon_start' => $start,
                'horizon_end' => $end,
                'notes' => $this->nullable($data['notes'] ?? null),
                'status' => 'DRAFT',
                'record_version' => 1,
                'created_by' => $data['actor_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->replaceDemandLines($id, $lines, $data, $now);
            $result = $this->demandResult($id, 'DRAFT', 1, count($lines));
            $this->record('CREATE_DEMAND_PLAN', 'manufacturing.demand-plan.created', 'demand_plan', $id, $data, 1, [
                'plan_number' => $data['plan_number'], 'line_count' => count($lines),
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function updateDemand(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'manufacturing.demand-plan.update.'.$id;
            if ($replay = $this->begin($namespace, $data + ['demand_plan_id' => $id])) {
                return $replay;
            }
            $plan = $this->findDemand($id, $data, true);
            $this->assertVersion($plan, $data['expected_version'], 'demand plan');
            $this->assertStatus($plan, ['DRAFT'], 'Only a draft demand plan can be edited.');
            [$start, $end] = $this->horizon($data);
            $lines = $this->prepareDemandLines($data['lines'], $data, $start, $end);
            $version = (int) $plan->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table('demand_plans')->where('id', $id)->update([
                'name' => trim($data['name']),
                'horizon_start' => $start,
                'horizon_end' => $end,
                'notes' => $this->nullable($data['notes'] ?? null),
                'record_version' => $version,
                'updated_at' => $now,
            ]);
            DB::table('demand_plan_lines')->where('demand_plan_id', $id)->delete();
            $this->replaceDemandLines($id, $lines, $data, $now);
            $result = $this->demandResult($id, 'DRAFT', $version, count($lines));
            $this->record('UPDATE_DEMAND_PLAN', 'manufacturing.demand-plan.updated', 'demand_plan', $id, $data, $version, [
                'record_version' => ['from' => (int) $plan->record_version, 'to' => $version],
                'line_count' => count($lines),
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function releaseDemand(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'manufacturing.demand-plan.release.'.$id;
            if ($replay = $this->begin($namespace, $data + ['demand_plan_id' => $id])) {
                return $replay;
            }
            $plan = $this->findDemand($id, $data, true);
            $this->assertVersion($plan, $data['expected_version'], 'demand plan');
            $this->assertStatus($plan, ['DRAFT'], 'Only a draft demand plan can be released.');
            $lines = DB::table('demand_plan_lines')->where('demand_plan_id', $id)->orderBy('line_number')->get();
            if ($lines->isEmpty()) {
                throw new ConflictHttpException('The demand plan has no lines to release.');
            }
            foreach ($lines as $index => $line) {
                $item = DB::table('items')->where('id', $line->output_sku_id)
                    ->where('company_id', $data['company_id'])->where('status', 'ACTIVE')->first();
                if (! $item || ! in_array($item->item_type, ['INTERMEDIATE', 'FINISHED_GOOD'], true)) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.output_sku_id" => ['Every output SKU must still be an active manufactured item.'],
                    ]);
                }
                $this->activeRecipe((string) $line->output_sku_id, (string) $line->demand_date, $data, "lines.{$index}.output_sku_id");
                $this->activeRoute($item, $data, "lines.{$index}.output_sku_id");
            }
            $version = (int) $plan->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table('demand_plans')->where('id', $id)->update([
                'status' => 'RELEASED', 'record_version' => $version,
                'released_at' => $now, 'released_by' => $data['actor_id'], 'updated_at' => $now,
            ]);
            $result = $this->demandResult($id, 'RELEASED', $version, $lines->count());
            $this->record('RELEASE_DEMAND_PLAN', 'manufacturing.demand-plan.released', 'demand_plan', $id, $data, $version, [
                'status' => ['from' => 'DRAFT', 'to' => 'RELEASED'],
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function cancelDemand(string $id, string $reason, array $data): array
    {
        return DB::transaction(function () use ($id, $reason, $data): array {
            $namespace = 'manufacturing.demand-plan.cancel.'.$id;
            if ($replay = $this->begin($namespace, $data + ['demand_plan_id' => $id, 'reason' => $reason])) {
                return $replay;
            }
            $plan = $this->findDemand($id, $data, true);
            $this->assertVersion($plan, $data['expected_version'], 'demand plan');
            $this->assertStatus($plan, ['DRAFT', 'RELEASED'], 'Only a draft or released demand plan can be cancelled.');
            if (DB::table('mrp_runs')->where('demand_plan_id', $id)->where('status', 'COMPLETED')->exists()) {
                throw ValidationException::withMessages([
                    'status' => ['Cancel the active MRP run before cancelling this demand plan.'],
                ]);
            }
            $version = (int) $plan->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table('demand_plans')->where('id', $id)->update([
                'status' => 'CANCELLED', 'record_version' => $version,
                'cancelled_at' => $now, 'cancelled_by' => $data['actor_id'],
                'cancellation_reason' => trim($reason), 'updated_at' => $now,
            ]);
            $result = $this->demandResult(
                $id,
                'CANCELLED',
                $version,
                DB::table('demand_plan_lines')->where('demand_plan_id', $id)->count(),
            );
            $this->record('CANCEL_DEMAND_PLAN', 'manufacturing.demand-plan.cancelled', 'demand_plan', $id, $data, $version, [
                'status' => ['from' => $plan->status, 'to' => 'CANCELLED'], 'reason' => trim($reason),
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function runMrp(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'manufacturing.mrp.run';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }
            $this->assertUniqueNumber('mrp_runs', 'run_number', $data['run_number'], $data);
            $plan = $this->findDemand($data['demand_plan_id'], $data, true);
            $this->assertStatus($plan, ['RELEASED'], 'MRP can only run from a released demand plan.');
            if (DB::table('mrp_runs')->where('demand_plan_id', $plan->id)->where('status', 'COMPLETED')->exists()) {
                throw ValidationException::withMessages([
                    'demand_plan_id' => ['This demand plan already has an active MRP run. Cancel it before rerunning.'],
                ]);
            }
            $runDate = CarbonImmutable::parse($data['run_date'])->toDateString();
            $demandLines = DB::table('demand_plan_lines as line')
                ->join('items as sku', 'sku.id', '=', 'line.output_sku_id')
                ->where('line.demand_plan_id', $plan->id)
                ->orderBy('line.demand_date')->orderBy('line.line_number')
                ->get(['line.*', 'sku.catalog_item_id', 'sku.code as sku_code', 'sku.name as sku_name']);
            if ($demandLines->isEmpty()) {
                throw new ConflictHttpException('The released demand plan has no lines.');
            }
            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('mrp_runs')->insert([
                'id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'run_number' => $data['run_number'], 'demand_plan_id' => $plan->id,
                'demand_plan_version_snapshot' => $plan->record_version, 'run_date' => $runDate,
                'status' => 'COMPLETED', 'record_version' => 1, 'created_by' => $data['actor_id'],
                'completed_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $plannedConsumption = [];
            $orderCount = 0;
            $requirementCount = 0;
            $shortage = '0.000000';
            foreach ($demandLines as $index => $line) {
                $recipe = $this->activeRecipe((string) $line->output_sku_id, (string) $line->demand_date, $data, "lines.{$index}.output_sku_id");
                $components = DB::table('recipe_components as component')
                    ->join('items as sku', 'sku.id', '=', 'component.component_sku_id')
                    ->where('component.recipe_id', $recipe->id)->orderBy('component.sequence_no')
                    ->get([
                        'component.*', 'sku.code as sku_code', 'sku.name as sku_name',
                        'sku.base_uom', 'sku.catalog_item_id', 'sku.status as sku_status',
                    ]);
                if ($components->isEmpty()) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.output_sku_id" => ['The effective recipe must contain at least one component.'],
                    ]);
                }
                $plannedOrderId = (string) Str::uuid();
                DB::table('mrp_planned_orders')->insert([
                    'id' => $plannedOrderId, 'mrp_run_id' => $id, 'demand_plan_id' => $plan->id,
                    'demand_plan_line_id' => $line->id, 'company_id' => $data['company_id'],
                    'plant_id' => $data['plant_id'], 'line_number' => $index + 1,
                    'output_sku_id' => $line->output_sku_id, 'recipe_id' => $recipe->id,
                    'recipe_revision_snapshot' => $recipe->revision, 'due_date' => $line->demand_date,
                    'planned_quantity' => $this->decimal($line->quantity), 'uom_code' => $line->uom_code,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                $outputInRecipeUom = $this->convert(
                    $this->decimal($line->quantity),
                    (string) $line->uom_code,
                    (string) $recipe->output_uom_code,
                    $line->catalog_item_id,
                    $data,
                    "lines.{$index}.quantity",
                );
                $batchFactor = bcdiv($outputInRecipeUom, $this->decimal($recipe->output_quantity), 12);
                $calculatedRequirements = null;
                if (($recipe->calculation_model ?? 'LEGACY_YIELD') !== 'LEGACY_YIELD') {
                    $calculatedRequirements = collect($this->recipeYield->forPlannedOutput(
                        (string) $recipe->id,
                        $outputInRecipeUom,
                        $data,
                    )['requirements'])->keyBy('component_id');
                }
                foreach ($components as $componentIndex => $component) {
                    if ($component->sku_status !== 'ACTIVE') {
                        throw ValidationException::withMessages([
                            "lines.{$index}.components.{$componentIndex}" => ["Recipe component {$component->sku_code} is not active."],
                        ]);
                    }
                    if ($calculatedRequirements !== null) {
                        $calculated = $calculatedRequirements->get((string) $component->id);
                        $gross = (string) ($calculated['calculated_quantity'] ?? '0');
                        if (bccomp($gross, '0', 12) <= 0) {
                            continue;
                        }
                    } else {
                        $gross = bcmul($batchFactor, $this->decimal($component->quantity), 12);
                        $gross = bcmul($gross, bcadd('1', bcdiv($this->decimal($component->waste_percent), '100', 12), 12), 12);
                        $gross = bcdiv($gross, bcdiv($this->decimal($recipe->yield_percent), '100', 12), 12);
                    }
                    $gross = $this->convert(
                        $gross,
                        (string) $component->uom_code,
                        (string) $component->base_uom,
                        $component->catalog_item_id,
                        $data,
                        "lines.{$index}.components.{$componentIndex}",
                    );
                    $gross = bcround($gross, 6);
                    $skuId = (string) $component->component_sku_id;
                    $inventory = $this->netInventory(
                        $skuId,
                        (string) $component->base_uom,
                        (string) $line->demand_date,
                        $gross,
                        $data,
                        $plannedConsumption,
                    );
                    $lineShortage = $inventory['shortage'];
                    $shortage = bcadd($shortage, $lineShortage, 6);
                    DB::table('mrp_material_requirements')->insert([
                        'id' => (string) Str::uuid(), 'mrp_run_id' => $id,
                        'mrp_planned_order_id' => $plannedOrderId, 'company_id' => $data['company_id'],
                        'plant_id' => $data['plant_id'], 'line_number' => $componentIndex + 1,
                        'recipe_component_id' => $component->id, 'component_sku_id' => $skuId,
                        'uom_code' => $component->base_uom, 'gross_requirement' => $gross,
                        'on_hand_snapshot' => $inventory['on_hand'],
                        'reserved_snapshot' => $inventory['reserved'],
                        'available_snapshot' => $inventory['available'], 'shortage_quantity' => $lineShortage,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    $requirementCount++;
                }
                $orderCount++;
            }
            $result = $this->mrpResult($id, 'COMPLETED', 1, $orderCount, $requirementCount, $shortage);
            $this->record('RUN_MRP', 'manufacturing.mrp.completed', 'mrp_run', $id, $data, 1, [
                'demand_plan_id' => $plan->id, 'planned_order_count' => $orderCount,
                'requirement_count' => $requirementCount, 'shortage_quantity' => $shortage,
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function cancelMrp(string $id, string $reason, array $data): array
    {
        return DB::transaction(function () use ($id, $reason, $data): array {
            $namespace = 'manufacturing.mrp.cancel.'.$id;
            if ($replay = $this->begin($namespace, $data + ['mrp_run_id' => $id, 'reason' => $reason])) {
                return $replay;
            }
            $run = $this->findMrp($id, $data, true);
            $this->assertVersion($run, $data['expected_version'], 'MRP run');
            $this->assertStatus($run, ['COMPLETED'], 'Only a completed MRP run can be cancelled.');
            if (DB::table('production_schedules')->where('mrp_run_id', $id)->where('status', '<>', 'CANCELLED')->exists()) {
                throw ValidationException::withMessages([
                    'status' => ['Cancel every active production schedule before cancelling this MRP run.'],
                ]);
            }
            $version = (int) $run->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table('mrp_runs')->where('id', $id)->update([
                'status' => 'CANCELLED', 'record_version' => $version,
                'cancelled_at' => $now, 'cancelled_by' => $data['actor_id'],
                'cancellation_reason' => trim($reason), 'updated_at' => $now,
            ]);
            $result = $this->mrpResult(
                $id,
                'CANCELLED',
                $version,
                DB::table('mrp_planned_orders')->where('mrp_run_id', $id)->count(),
                DB::table('mrp_material_requirements')->where('mrp_run_id', $id)->count(),
                $this->decimal(DB::table('mrp_material_requirements')->where('mrp_run_id', $id)->sum('shortage_quantity')),
            );
            $this->record('CANCEL_MRP', 'manufacturing.mrp.cancelled', 'mrp_run', $id, $data, $version, [
                'status' => ['from' => 'COMPLETED', 'to' => 'CANCELLED'], 'reason' => trim($reason),
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function createSchedule(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'manufacturing.production-schedule.create';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }
            $this->assertUniqueNumber('production_schedules', 'schedule_number', $data['schedule_number'], $data);
            $run = $this->findMrp($data['mrp_run_id'], $data, true);
            $this->assertStatus($run, ['COMPLETED'], 'A schedule requires an active completed MRP run.');
            [$start, $end] = $this->horizon($data);
            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('production_schedules')->insert([
                'id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'schedule_number' => $data['schedule_number'], 'mrp_run_id' => $run->id,
                'horizon_start' => $start, 'horizon_end' => $end,
                'notes' => $this->nullable($data['notes'] ?? null), 'status' => 'DRAFT',
                'record_version' => 1, 'created_by' => $data['actor_id'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $metrics = $this->replaceSchedulePlan($id, $run, $data, $start, $end, $now);
            $result = $this->scheduleResult($id, 'DRAFT', 1, $metrics);
            $this->record('CREATE_PRODUCTION_SCHEDULE', 'manufacturing.production-schedule.created', 'production_schedule', $id, $data, 1, [
                'schedule_number' => $data['schedule_number'], 'line_count' => $metrics['line_count'],
                'overloaded_work_centers' => $metrics['overloaded_work_centers'],
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function updateSchedule(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'manufacturing.production-schedule.update.'.$id;
            if ($replay = $this->begin($namespace, $data + ['production_schedule_id' => $id])) {
                return $replay;
            }
            $schedule = $this->findSchedule($id, $data, true);
            $this->assertVersion($schedule, $data['expected_version'], 'production schedule');
            $this->assertStatus($schedule, ['DRAFT'], 'Only a draft production schedule can be edited.');
            $run = $this->findMrp((string) $schedule->mrp_run_id, $data, true);
            $this->assertStatus($run, ['COMPLETED'], 'The schedule MRP run is no longer active.');
            [$start, $end] = $this->horizon($data);
            $version = (int) $schedule->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table('production_schedule_capacities')->where('production_schedule_id', $id)->delete();
            DB::table('production_schedule_lines')->where('production_schedule_id', $id)->delete();
            DB::table('production_schedules')->where('id', $id)->update([
                'horizon_start' => $start, 'horizon_end' => $end,
                'notes' => $this->nullable($data['notes'] ?? null),
                'record_version' => $version, 'updated_at' => $now,
            ]);
            $metrics = $this->replaceSchedulePlan($id, $run, $data, $start, $end, $now);
            $result = $this->scheduleResult($id, 'DRAFT', $version, $metrics);
            $this->record('UPDATE_PRODUCTION_SCHEDULE', 'manufacturing.production-schedule.updated', 'production_schedule', $id, $data, $version, [
                'record_version' => ['from' => (int) $schedule->record_version, 'to' => $version],
                'line_count' => $metrics['line_count'], 'overloaded_work_centers' => $metrics['overloaded_work_centers'],
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function releaseSchedule(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'manufacturing.production-schedule.release.'.$id;
            if ($replay = $this->begin($namespace, $data + ['production_schedule_id' => $id])) {
                return $replay;
            }
            $schedule = $this->findSchedule($id, $data, true);
            $this->assertVersion($schedule, $data['expected_version'], 'production schedule');
            $this->assertStatus($schedule, ['DRAFT'], 'Only a draft production schedule can be released.');
            if (DB::table('production_schedule_capacities')->where('production_schedule_id', $id)->where('is_overloaded', true)->exists()) {
                throw ValidationException::withMessages([
                    'capacities' => ['Resolve every overloaded work center before releasing the schedule.'],
                ]);
            }
            $materials = DB::table('production_schedule_lines as schedule_line')
                ->join('mrp_material_requirements as requirement', 'requirement.mrp_planned_order_id', '=', 'schedule_line.mrp_planned_order_id')
                ->join('items as item', 'item.id', '=', 'requirement.component_sku_id')
                ->where('schedule_line.production_schedule_id', $id)
                ->orderBy('requirement.component_sku_id')
                ->orderBy('schedule_line.planned_start_date')
                ->orderBy('schedule_line.line_number')->orderBy('requirement.line_number')
                ->get([
                    'schedule_line.id as schedule_line_id', 'schedule_line.mrp_planned_order_id',
                    'schedule_line.planned_start_date', 'requirement.*', 'item.code as item_code',
                ]);
            if ($materials->isEmpty()) {
                throw new ConflictHttpException('The schedule has no material requirements to reserve.');
            }
            $now = CarbonImmutable::now();
            $reservationCount = 0;
            $reservedTotal = '0.000000';
            foreach ($materials as $material) {
                $remaining = $this->decimal($material->gross_requirement);
                $positions = DB::table('stock_positions as position')
                    ->join('inventory_owners as owner', 'owner.id', '=', 'position.inventory_owner_id')
                    ->join('inventory_quality_statuses as quality', 'quality.code', '=', 'position.quality_status')
                    ->join('lots as lot', 'lot.id', '=', 'position.lot_id')
                    ->where('position.company_id', $data['company_id'])
                    ->where('position.plant_id', $data['plant_id'])
                    ->where('position.item_id', $material->component_sku_id)
                    ->where('position.uom_code', $material->uom_code)
                    ->where('owner.owner_type', 'COMPANY')->where('owner.status', 'ACTIVE')
                    ->where('quality.is_reservable', true)->where('lot.status', 'ACTIVE')
                    ->where(function ($query) use ($material): void {
                        $query->whereNull('lot.expiry_date')->orWhereDate('lot.expiry_date', '>=', $material->planned_start_date);
                    })
                    ->whereRaw('position.quantity_base > position.reserved_quantity_base')
                    ->orderByRaw('CASE WHEN lot.expiry_date IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('lot.expiry_date')->orderBy('lot.internal_lot_code')->orderBy('position.id')
                    ->lockForUpdate()->get(['position.*']);
                foreach ($positions as $position) {
                    if (bccomp($remaining, '0', 6) <= 0) {
                        break;
                    }
                    $available = bcsub($this->decimal($position->quantity_base), $this->decimal($position->reserved_quantity_base), 6);
                    if (bccomp($available, '0', 6) <= 0) {
                        continue;
                    }
                    $quantity = bccomp($available, $remaining, 6) >= 0 ? $remaining : $available;
                    $reservationCount++;
                    $stockReservationId = (string) Str::uuid();
                    DB::table('stock_reservations')->insert([
                        'id' => $stockReservationId, 'company_id' => $data['company_id'],
                        'plant_id' => $data['plant_id'], 'stock_position_id' => $position->id,
                        'reservation_number' => $this->reservationNumber((string) $schedule->schedule_number, $reservationCount),
                        'quantity_base' => $quantity, 'status' => 'ACTIVE',
                        'purpose' => "Production schedule {$schedule->schedule_number}; material {$material->item_code}",
                        'record_version' => 1, 'created_by' => $data['actor_id'],
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    DB::table('production_material_reservations')->insert([
                        'id' => (string) Str::uuid(), 'production_schedule_id' => $id,
                        'production_schedule_line_id' => $material->schedule_line_id,
                        'mrp_run_id' => $schedule->mrp_run_id,
                        'mrp_planned_order_id' => $material->mrp_planned_order_id,
                        'mrp_material_requirement_id' => $material->id,
                        'stock_reservation_id' => $stockReservationId,
                        'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                        'quantity_base' => $quantity, 'uom_code' => $material->uom_code,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                    DB::table('stock_positions')->where('id', $position->id)->update([
                        'reserved_quantity_base' => bcadd($this->decimal($position->reserved_quantity_base), $quantity, 6),
                        'record_version' => (int) $position->record_version + 1, 'updated_at' => $now,
                    ]);
                    $position->reserved_quantity_base = bcadd($this->decimal($position->reserved_quantity_base), $quantity, 6);
                    $remaining = bcsub($remaining, $quantity, 6);
                    $reservedTotal = bcadd($reservedTotal, $quantity, 6);
                }
                if (bccomp($remaining, '0', 6) > 0) {
                    throw ValidationException::withMessages([
                        'materials' => ["{$material->item_code} is short by {$remaining} {$material->uom_code} for the planned start date."],
                    ]);
                }
            }
            $version = (int) $schedule->record_version + 1;
            DB::table('production_schedules')->where('id', $id)->update([
                'status' => 'RELEASED', 'record_version' => $version,
                'released_at' => $now, 'released_by' => $data['actor_id'], 'updated_at' => $now,
            ]);
            $metrics = $this->scheduleMetrics($id) + [
                'reservation_count' => $reservationCount, 'reserved_quantity' => $reservedTotal,
            ];
            $result = $this->scheduleResult($id, 'RELEASED', $version, $metrics);
            $this->record('RELEASE_PRODUCTION_SCHEDULE', 'manufacturing.production-schedule.released', 'production_schedule', $id, $data, $version, [
                'status' => ['from' => 'DRAFT', 'to' => 'RELEASED'],
                'reservation_count' => $reservationCount, 'reserved_quantity' => $reservedTotal,
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function cancelSchedule(string $id, string $reason, array $data): array
    {
        return DB::transaction(function () use ($id, $reason, $data): array {
            $namespace = 'manufacturing.production-schedule.cancel.'.$id;
            if ($replay = $this->begin($namespace, $data + ['production_schedule_id' => $id, 'reason' => $reason])) {
                return $replay;
            }
            $schedule = $this->findSchedule($id, $data, true);
            $this->assertVersion($schedule, $data['expected_version'], 'production schedule');
            $this->assertStatus($schedule, ['DRAFT', 'RELEASED'], 'Only a draft or released production schedule can be cancelled.');
            $now = CarbonImmutable::now();
            $releasedCount = 0;
            if ($schedule->status === 'RELEASED') {
                $links = DB::table('production_material_reservations as link')
                    ->join('stock_reservations as reservation', 'reservation.id', '=', 'link.stock_reservation_id')
                    ->where('link.production_schedule_id', $id)->whereNull('link.released_at')
                    ->orderBy('reservation.stock_position_id')->orderBy('reservation.id')
                    ->lockForUpdate()->get([
                        'link.id as link_id', 'reservation.id as reservation_id',
                        'reservation.stock_position_id', 'reservation.quantity_base',
                        'reservation.status', 'reservation.record_version',
                    ]);
                foreach ($links as $link) {
                    if ($link->status !== 'ACTIVE') {
                        throw new ConflictHttpException('A linked stock reservation was released outside this schedule. Refresh and investigate it.');
                    }
                    $position = DB::table('stock_positions')->where('id', $link->stock_position_id)
                        ->where('company_id', $data['company_id'])->where('plant_id', $data['plant_id'])
                        ->lockForUpdate()->first();
                    if (! $position || bccomp($this->decimal($position->reserved_quantity_base), $this->decimal($link->quantity_base), 6) < 0) {
                        throw new ConflictHttpException('Reserved-stock projection is inconsistent with the schedule reservation.');
                    }
                    DB::table('stock_reservations')->where('id', $link->reservation_id)->update([
                        'status' => 'RELEASED', 'record_version' => (int) $link->record_version + 1,
                        'released_at' => $now, 'released_by' => $data['actor_id'],
                        'release_reason' => "Production schedule cancelled: {$reason}", 'updated_at' => $now,
                    ]);
                    DB::table('stock_positions')->where('id', $position->id)->update([
                        'reserved_quantity_base' => bcsub($this->decimal($position->reserved_quantity_base), $this->decimal($link->quantity_base), 6),
                        'record_version' => (int) $position->record_version + 1, 'updated_at' => $now,
                    ]);
                    DB::table('production_material_reservations')->where('id', $link->link_id)->update([
                        'released_at' => $now, 'updated_at' => $now,
                    ]);
                    $releasedCount++;
                }
            }
            DB::table('production_schedule_lines')->where('production_schedule_id', $id)->update([
                'is_active' => false, 'updated_at' => $now,
            ]);
            $version = (int) $schedule->record_version + 1;
            DB::table('production_schedules')->where('id', $id)->update([
                'status' => 'CANCELLED', 'record_version' => $version,
                'cancelled_at' => $now, 'cancelled_by' => $data['actor_id'],
                'cancellation_reason' => trim($reason), 'updated_at' => $now,
            ]);
            $metrics = $this->scheduleMetrics($id) + ['released_reservation_count' => $releasedCount];
            $result = $this->scheduleResult($id, 'CANCELLED', $version, $metrics);
            $this->record('CANCEL_PRODUCTION_SCHEDULE', 'manufacturing.production-schedule.cancelled', 'production_schedule', $id, $data, $version, [
                'status' => ['from' => $schedule->status, 'to' => 'CANCELLED'],
                'reason' => trim($reason), 'released_reservation_count' => $releasedCount,
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    private function prepareDemandLines(array $input, array $scope, string $start, string $end): array
    {
        $lines = [];
        foreach (array_values($input) as $index => $line) {
            $item = DB::table('items')->where('id', $line['output_sku_id'])
                ->where('company_id', $scope['company_id'])->where('status', 'ACTIVE')->first();
            if (! $item || ! in_array($item->item_type, ['INTERMEDIATE', 'FINISHED_GOOD'], true)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.output_sku_id" => ['Select an active intermediate or finished-goods SKU from this company.'],
                ]);
            }
            $date = CarbonImmutable::parse($line['demand_date'])->toDateString();
            if ($date < $start || $date > $end) {
                throw ValidationException::withMessages([
                    "lines.{$index}.demand_date" => ['Demand date must fall inside the plan horizon.'],
                ]);
            }
            $quantity = $this->positive($line['quantity'], "lines.{$index}.quantity", 'Demand quantity');
            $quantity = $this->uomQuantities->assertPrecision(
                $quantity,
                (string) $item->base_uom,
                "lines.{$index}.quantity",
                'Demand quantity',
            );
            $lines[] = [
                'output_sku_id' => (string) $item->id,
                'demand_date' => $date,
                'demand_type' => Str::upper((string) $line['demand_type']),
                'quantity' => $quantity,
                'uom_code' => (string) $item->base_uom,
                'notes' => $this->nullable($line['notes'] ?? null),
            ];
        }

        return $lines;
    }

    private function replaceDemandLines(string $id, array $lines, array $scope, CarbonImmutable $now): void
    {
        foreach ($lines as $index => $line) {
            DB::table('demand_plan_lines')->insert($line + [
                'id' => (string) Str::uuid(), 'demand_plan_id' => $id,
                'company_id' => $scope['company_id'], 'plant_id' => $scope['plant_id'],
                'line_number' => $index + 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function replaceSchedulePlan(
        string $scheduleId,
        object $run,
        array $data,
        string $start,
        string $end,
        CarbonImmutable $now,
    ): array {
        $requested = collect($data['lines'])->keyBy('mrp_planned_order_id');
        if ($requested->count() !== count($data['lines'])) {
            throw ValidationException::withMessages(['lines' => ['Select each MRP planned order exactly once.']]);
        }
        $orders = DB::table('mrp_planned_orders as planned')
            ->join('items as sku', 'sku.id', '=', 'planned.output_sku_id')
            ->where('planned.mrp_run_id', $run->id)
            ->whereIn('planned.id', $requested->keys()->all())
            ->orderBy('planned.line_number')
            ->get(['planned.*', 'sku.catalog_item_id', 'sku.code as sku_code']);
        if ($orders->count() !== $requested->count()) {
            throw ValidationException::withMessages([
                'lines' => ['Every scheduled line must belong to the selected active MRP run.'],
            ]);
        }
        if (DB::table('production_schedule_lines')->whereIn('mrp_planned_order_id', $requested->keys()->all())
            ->where('is_active', true)->where('production_schedule_id', '<>', $scheduleId)->exists()) {
            throw ValidationException::withMessages([
                'lines' => ['One or more planned orders already belong to an active production schedule.'],
            ]);
        }
        $requiredByCenter = [];
        foreach ($orders as $index => $order) {
            $input = $requested->get($order->id);
            $lineStart = CarbonImmutable::parse($input['planned_start_date'])->toDateString();
            $lineEnd = CarbonImmutable::parse($input['planned_end_date'])->toDateString();
            if ($lineStart < $start || $lineEnd > $end || $lineEnd < $lineStart) {
                throw ValidationException::withMessages([
                    "lines.{$index}.planned_end_date" => ['Line dates must be ordered and fall inside the schedule horizon.'],
                ]);
            }
            $route = $this->activeRoute($order, $data, "lines.{$index}.mrp_planned_order_id");
            $operations = DB::table('route_operations')->where('route_id', $route->id)
                ->orderBy('sequence_no')->get();
            if ($operations->isEmpty()) {
                throw ValidationException::withMessages([
                    "lines.{$index}.mrp_planned_order_id" => ["Route {$route->code} has no operations."],
                ]);
            }
            $scheduleLineId = (string) Str::uuid();
            DB::table('production_schedule_lines')->insert([
                'id' => $scheduleLineId, 'production_schedule_id' => $scheduleId,
                'mrp_run_id' => $run->id, 'mrp_planned_order_id' => $order->id,
                'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'line_number' => $index + 1, 'output_sku_id' => $order->output_sku_id,
                'recipe_id' => $order->recipe_id, 'route_id' => $route->id,
                'planned_quantity' => $this->decimal($order->planned_quantity), 'uom_code' => $order->uom_code,
                'planned_start_date' => $lineStart, 'planned_end_date' => $lineEnd,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($operations as $operation) {
                $center = Str::upper(trim((string) $operation->work_center_code));
                $required = bcadd(
                    $this->decimal($operation->setup_minutes),
                    bcmul($this->decimal($operation->run_minutes_per_unit), $this->decimal($order->planned_quantity), 6),
                    6,
                );
                $requiredByCenter[$center] = bcadd($requiredByCenter[$center] ?? '0.000000', $required, 6);
                DB::table('production_schedule_operations')->insert([
                    'id' => (string) Str::uuid(), 'production_schedule_id' => $scheduleId,
                    'production_schedule_line_id' => $scheduleLineId,
                    'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                    'route_operation_id' => $operation->id, 'sequence_no' => $operation->sequence_no,
                    'operation_name' => $operation->name, 'work_center_code' => $center,
                    'setup_minutes_snapshot' => $this->decimal($operation->setup_minutes),
                    'run_minutes_per_unit_snapshot' => $this->decimal($operation->run_minutes_per_unit),
                    'required_minutes' => $required, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        $capacities = [];
        foreach (array_values($data['capacities']) as $index => $capacity) {
            $center = Str::upper(trim((string) $capacity['work_center_code']));
            if (isset($capacities[$center])) {
                throw ValidationException::withMessages([
                    "capacities.{$index}.work_center_code" => ['Enter each required work center exactly once.'],
                ]);
            }
            $capacities[$center] = $this->positive(
                $capacity['daily_capacity_minutes'],
                "capacities.{$index}.daily_capacity_minutes",
                'Daily capacity',
            );
        }
        $missing = array_values(array_diff(array_keys($requiredByCenter), array_keys($capacities)));
        $extra = array_values(array_diff(array_keys($capacities), array_keys($requiredByCenter)));
        if ($missing !== [] || $extra !== []) {
            $message = $missing !== [] ? 'Add capacity for: '.implode(', ', $missing).'.' : 'Remove unused capacity for: '.implode(', ', $extra).'.';
            throw ValidationException::withMessages(['capacities' => [$message]]);
        }
        $workingDays = $this->workingDays($start, $end);
        $overloaded = 0;
        foreach ($requiredByCenter as $center => $required) {
            $available = bcmul($capacities[$center], (string) $workingDays, 6);
            $utilisation = bcround(bcmul(bcdiv($required, $available, 6), '100', 6), 3);
            $isOverloaded = bccomp($required, $available, 6) > 0;
            $overloaded += $isOverloaded ? 1 : 0;
            DB::table('production_schedule_capacities')->insert([
                'id' => (string) Str::uuid(), 'production_schedule_id' => $scheduleId,
                'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'work_center_code' => $center, 'daily_capacity_minutes' => $capacities[$center],
                'working_days' => $workingDays, 'available_minutes' => $available,
                'required_minutes' => $required, 'utilisation_percent' => $utilisation,
                'is_overloaded' => $isOverloaded, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }

        return [
            'line_count' => $orders->count(), 'work_center_count' => count($requiredByCenter),
            'overloaded_work_centers' => $overloaded, 'reservation_count' => 0,
            'reserved_quantity' => '0.000000',
        ];
    }

    private function activeRecipe(string $skuId, string $date, array $scope, string $field): object
    {
        $recipe = DB::table('recipes')->where('company_id', $scope['company_id'])
            ->where('output_sku_id', $skuId)->where('status', 'ACTIVE')
            ->where(function ($query) use ($date): void {
                $query->whereNull('effective_from')->orWhereDate('effective_from', '<=', $date);
            })->where(function ($query) use ($date): void {
                $query->whereNull('effective_to')->orWhereDate('effective_to', '>=', $date);
            })->orderByDesc('revision')->orderByDesc('effective_from')->first();
        if (! $recipe || bccomp($this->decimal($recipe->output_quantity), '0', 6) <= 0
            || bccomp($this->decimal($recipe->yield_percent), '0', 6) <= 0) {
            throw ValidationException::withMessages([
                $field => ['No effective active recipe with positive output and yield exists for this SKU and date.'],
            ]);
        }

        return $recipe;
    }

    private function activeRoute(object $item, array $scope, string $field): object
    {
        if (! is_string($item->catalog_item_id ?? null)) {
            throw ValidationException::withMessages([$field => ['The output SKU is not linked to a catalog item.']]);
        }
        $route = DB::table('production_routes')->where('company_id', $scope['company_id'])
            ->where('catalog_item_id', $item->catalog_item_id)->where('status', 'ACTIVE')
            ->orderBy('code')->first();
        if (! $route) {
            throw ValidationException::withMessages([$field => ['No active production route exists for this output SKU.']]);
        }

        return $route;
    }

    private function netInventory(
        string $skuId,
        string $uom,
        string $date,
        string $requirement,
        array $scope,
        array &$plannedConsumption,
    ): array
    {
        $positions = DB::table('stock_positions as position')
            ->join('inventory_owners as owner', 'owner.id', '=', 'position.inventory_owner_id')
            ->join('inventory_quality_statuses as quality', 'quality.code', '=', 'position.quality_status')
            ->join('lots as lot', 'lot.id', '=', 'position.lot_id')
            ->where('position.company_id', $scope['company_id'])->where('position.plant_id', $scope['plant_id'])
            ->where('position.item_id', $skuId)->where('position.uom_code', $uom)
            ->where('owner.owner_type', 'COMPANY')->where('owner.status', 'ACTIVE')
            ->where('quality.is_reservable', true)->where('lot.status', 'ACTIVE')
            ->where(function ($query) use ($date): void {
                $query->whereNull('lot.expiry_date')->orWhereDate('lot.expiry_date', '>=', $date);
            })->orderByRaw('CASE WHEN lot.expiry_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('lot.expiry_date')->orderBy('lot.internal_lot_code')->orderBy('position.id')
            ->get(['position.id', 'position.quantity_base', 'position.reserved_quantity_base']);
        $onHand = '0.000000';
        $reserved = '0.000000';
        $available = '0.000000';
        foreach ($positions as $position) {
            $quantity = $this->decimal($position->quantity_base);
            $positionReserved = $this->decimal($position->reserved_quantity_base);
            $onHand = bcadd($onHand, $quantity, 6);
            $reserved = bcadd($reserved, $positionReserved, 6);
            $free = bcsub(
                bcsub($quantity, $positionReserved, 6),
                $plannedConsumption[(string) $position->id] ?? '0.000000',
                6,
            );
            if (bccomp($free, '0', 6) > 0) {
                $available = bcadd($available, $free, 6);
            }
        }
        $remaining = $requirement;
        foreach ($positions as $position) {
            if (bccomp($remaining, '0', 6) <= 0) {
                break;
            }
            $positionId = (string) $position->id;
            $free = bcsub(
                bcsub($this->decimal($position->quantity_base), $this->decimal($position->reserved_quantity_base), 6),
                $plannedConsumption[$positionId] ?? '0.000000',
                6,
            );
            if (bccomp($free, '0', 6) <= 0) {
                continue;
            }
            $covered = bccomp($free, $remaining, 6) >= 0 ? $remaining : $free;
            $plannedConsumption[$positionId] = bcadd($plannedConsumption[$positionId] ?? '0.000000', $covered, 6);
            $remaining = bcsub($remaining, $covered, 6);
        }

        return ['on_hand' => $onHand, 'reserved' => $reserved, 'available' => $available, 'shortage' => $remaining];
    }

    private function convert(
        string $quantity,
        string $from,
        string $to,
        mixed $catalogItemId,
        array $scope,
        string $field,
    ): string {
        if ($from === $to) {
            return $quantity;
        }
        if (! is_string($catalogItemId)) {
            throw ValidationException::withMessages([$field => ["No {$from}-to-{$to} conversion can be resolved without a catalog item."]]);
        }
        $direct = DB::table('item_uom_conversions')->where('company_id', $scope['company_id'])
            ->where('catalog_item_id', $catalogItemId)->where('from_uom_code', $from)
            ->where('to_uom_code', $to)->first();
        if ($direct) {
            return bcmul($quantity, (string) $direct->multiplier, 12);
        }
        $reverse = DB::table('item_uom_conversions')->where('company_id', $scope['company_id'])
            ->where('catalog_item_id', $catalogItemId)->where('from_uom_code', $to)
            ->where('to_uom_code', $from)->first();
        if ($reverse && bccomp((string) $reverse->multiplier, '0', 12) > 0) {
            return bcdiv($quantity, (string) $reverse->multiplier, 12);
        }
        throw ValidationException::withMessages([$field => ["Configure a {$from}-to-{$to} UOM conversion before planning this item."]]);
    }

    private function horizon(array $data): array
    {
        $start = CarbonImmutable::parse($data['horizon_start'])->toDateString();
        $end = CarbonImmutable::parse($data['horizon_end'])->toDateString();
        if ($end < $start) {
            throw ValidationException::withMessages(['horizon_end' => ['Horizon end must be on or after horizon start.']]);
        }

        return [$start, $end];
    }

    private function workingDays(string $start, string $end): int
    {
        $cursor = CarbonImmutable::parse($start);
        $last = CarbonImmutable::parse($end);
        $days = 0;
        while ($cursor->lte($last)) {
            if (! $cursor->isWeekend()) {
                $days++;
            }
            $cursor = $cursor->addDay();
        }
        if ($days < 1) {
            throw ValidationException::withMessages([
                'horizon_start' => ['The schedule horizon must include at least one working weekday.'],
            ]);
        }

        return $days;
    }

    private function findDemand(string $id, array $scope, bool $lock = false): object
    {
        $query = DB::table('demand_plans')->where('id', $id)
            ->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id']);
        $plan = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $plan) {
            throw new NotFoundHttpException('Demand plan not found.');
        }

        return $plan;
    }

    private function findMrp(string $id, array $scope, bool $lock = false): object
    {
        $query = DB::table('mrp_runs')->where('id', $id)
            ->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id']);
        $run = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $run) {
            throw new NotFoundHttpException('MRP run not found.');
        }

        return $run;
    }

    private function findSchedule(string $id, array $scope, bool $lock = false): object
    {
        $query = DB::table('production_schedules')->where('id', $id)
            ->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id']);
        $schedule = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $schedule) {
            throw new NotFoundHttpException('Production schedule not found.');
        }

        return $schedule;
    }

    private function assertUniqueNumber(string $table, string $column, string $number, array $scope): void
    {
        if (DB::table($table)->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])
            ->where($column, $number)->exists()) {
            throw ValidationException::withMessages([$column => ['That document number already exists in the selected plant.']]);
        }
    }

    private function assertVersion(object $record, int $expected, string $label): void
    {
        if ((int) $record->record_version !== $expected) {
            throw new ConflictHttpException(
                "The {$label} changed from version {$expected} to {$record->record_version}. Refresh it before continuing."
            );
        }
    }

    private function assertStatus(object $record, array $allowed, string $message): void
    {
        if (! in_array($record->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => [$message]]);
        }
    }

    private function scheduleMetrics(string $id): array
    {
        return [
            'line_count' => DB::table('production_schedule_lines')->where('production_schedule_id', $id)->count(),
            'work_center_count' => DB::table('production_schedule_capacities')->where('production_schedule_id', $id)->count(),
            'overloaded_work_centers' => DB::table('production_schedule_capacities')->where('production_schedule_id', $id)->where('is_overloaded', true)->count(),
            'reservation_count' => DB::table('production_material_reservations')->where('production_schedule_id', $id)->whereNull('released_at')->count(),
            'reserved_quantity' => $this->decimal(DB::table('production_material_reservations')->where('production_schedule_id', $id)->whereNull('released_at')->sum('quantity_base')),
        ];
    }

    private function demandResult(string $id, string $status, int $version, int $lines): array
    {
        return ['entity_type' => 'demand_plan', 'id' => $id, 'status' => $status, 'record_version' => $version, 'line_count' => $lines];
    }

    private function mrpResult(string $id, string $status, int $version, int $orders, int $requirements, string $shortage): array
    {
        return [
            'entity_type' => 'mrp_run', 'id' => $id, 'status' => $status, 'record_version' => $version,
            'planned_order_count' => $orders, 'material_requirement_count' => $requirements,
            'shortage_quantity' => $shortage,
        ];
    }

    private function scheduleResult(string $id, string $status, int $version, array $metrics): array
    {
        return ['entity_type' => 'production_schedule', 'id' => $id, 'status' => $status, 'record_version' => $version] + $metrics;
    }

    private function reservationNumber(string $scheduleNumber, int $sequence): string
    {
        $safe = preg_replace('/[^A-Z0-9_-]/', '-', Str::upper($scheduleNumber)) ?: 'SCHEDULE';

        return 'MRP-'.substr($safe, 0, 68).'-'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT);
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
        $this->audit->record($command, $entityType, $id, $data['actor_id'], $data['company_id'], $data['plant_id'], 'SUCCESS', [
            'entity_version' => $version, 'correlation_id' => $data['correlation_id'] ?? null,
            'reason_code' => str_starts_with($command, 'CANCEL_') ? 'CANCELLED' : null,
            'safe_diff' => $safeDiff,
        ]);
        $this->outbox->append(
            $event,
            $entityType,
            $id,
            $id.':'.$version,
            $result + ['company_id' => $data['company_id'], 'plant_id' => $data['plant_id']],
            $data['correlation_id'] ?? null,
            $data['company_id'],
            $data['plant_id'],
        );
    }

    private function begin(string $namespace, array $data): ?array
    {
        return $this->idempotency->begin(
            $namespace,
            $data['idempotency_key'],
            Arr::except($data, ['actor_id', 'permissions', 'idempotency_key', 'correlation_id']),
        );
    }

    private function positive(mixed $value, string $field, string $label): string
    {
        $value = (string) $value;
        if (! preg_match('/^\d{1,14}(?:\.\d{1,6})?$/', $value) || bccomp($value, '0', 6) <= 0) {
            throw ValidationException::withMessages([
                $field => ["{$label} must be positive with at most 14 whole digits and 6 decimal places."],
            ]);
        }

        return $this->decimal($value);
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 6);
    }

    private function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}

<?php

namespace App\Modules\Manufacturing\Application;

use App\Modules\Inventory\Application\StockPostingService;
use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ManufacturingExecutionService
{
    public const ORDER_STATUSES = ['DRAFT', 'RELEASED', 'IN_PROCESS', 'COMPLETED', 'CANCELLED'];
    public const OUTPUT_TYPES = ['GOOD', 'LOSS', 'REWORK'];
    public const REWORK_DISPOSITIONS = ['RECOVERED', 'SCRAPPED'];
    public const LOSS_CATEGORIES = ['NORMAL_PROCESS_LOSS', 'HANDLING_WASTE', 'REJECTED_OUTPUT',
        'OVERWEIGHT_GIVEAWAY', 'UNEXPLAINED_VARIANCE', 'APPROVED_REWORK'];
    public const QUANTITY_DIMENSIONS = ['COUNT', 'MASS', 'PACK'];
    public const VALUE_SOURCES = ['MEASURED', 'ESTIMATED', 'DERIVED'];
    public const MATERIAL_DISPOSITIONS = ['WASTE', 'REWORK_OUT', 'REWORK_IN'];

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
        private readonly StockPostingService $stock,
        private readonly RecipeYieldService $recipeYield,
        private readonly ManufacturingMeasurementService $measurements,
        private readonly ManufacturingMaterialReconciliationService $materialReconciliation,
    ) {}

    public function createOrder(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'manufacturing.production-order.create';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }
            $this->assertUniqueNumber('order_number', $data['order_number'], $data);
            $this->assertUniqueNumber('batch_number', $data['batch_number'], $data);

            $line = DB::table('production_schedule_lines as line')
                ->join('production_schedules as schedule', 'schedule.id', '=', 'line.production_schedule_id')
                ->where('line.id', $data['production_schedule_line_id'])
                ->where('line.company_id', $data['company_id'])->where('line.plant_id', $data['plant_id'])
                ->lockForUpdate()->first([
                    'line.*', 'schedule.status as schedule_status', 'schedule.schedule_number',
                ]);
            if (! $line) {
                throw ValidationException::withMessages(['production_schedule_line_id' => ['Released production schedule line not found in the selected plant.']]);
            }
            if ($line->schedule_status !== 'RELEASED' || ! $line->is_active) {
                throw ValidationException::withMessages(['production_schedule_line_id' => ['Only an active line on a released schedule can become a production order.']]);
            }
            if (DB::table('production_orders')->where('production_schedule_line_id', $line->id)
                ->where('status', '<>', 'CANCELLED')->exists()) {
                throw ValidationException::withMessages(['production_schedule_line_id' => ['This schedule line already has an active production order.']]);
            }

            $materials = DB::table('mrp_material_requirements')
                ->where('mrp_planned_order_id', $line->mrp_planned_order_id)
                ->orderBy('line_number')->get();
            $stages = DB::table('production_schedule_operations')
                ->where('production_schedule_line_id', $line->id)
                ->orderBy('sequence_no')->get();
            if ($materials->isEmpty() || $stages->isEmpty()) {
                throw new ConflictHttpException('The released schedule line is missing its material or route-operation snapshot.');
            }

            $recipe = DB::table('recipes')->where('id', $line->recipe_id)
                ->where('company_id', $data['company_id'])->first();
            $route = DB::table('production_routes')->where('id', $line->route_id)
                ->where('company_id', $data['company_id'])->first();
            if (! $recipe || ! $route) {
                throw new ConflictHttpException('The approved recipe or route no longer exists in this company.');
            }
            $yieldPlan = $this->recipeYield->forPlannedOutput((string) $recipe->id, $line->planned_quantity, $data);
            $standardSnapshot = $this->recipeYield->standardSnapshot((string) $recipe->id, $data);
            $componentSnapshots = collect($standardSnapshot['components'])->keyBy('id');

            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('production_orders')->insert([
                'id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'order_number' => $data['order_number'], 'batch_number' => $data['batch_number'],
                'production_schedule_id' => $line->production_schedule_id,
                'production_schedule_line_id' => $line->id, 'output_sku_id' => $line->output_sku_id,
                'recipe_id' => $line->recipe_id, 'route_id' => $line->route_id,
                'planned_quantity' => $this->decimal($line->planned_quantity), 'uom_code' => $line->uom_code,
                'planned_start_date' => $data['planned_start_date'] ?? $line->planned_start_date,
                'planned_end_date' => $data['planned_end_date'] ?? $line->planned_end_date,
                'recipe_revision_snapshot' => (int) $recipe->revision,
                'route_revision_snapshot' => (int) $route->revision,
                'shift_code' => $this->nullable($data['shift_code'] ?? null),
                'recipe_standard_snapshot' => json_encode($standardSnapshot, JSON_THROW_ON_ERROR),
                'calculation_model_snapshot' => $yieldPlan['calculation_model'],
                'recipe_basis_quantity_snapshot' => $yieldPlan['recipe_basis_quantity'],
                'planned_basis_quantity' => $yieldPlan['batch_basis_quantity'],
                'scaling_factor' => $yieldPlan['scaling_factor'],
                'theoretical_input_mass' => $yieldPlan['theoretical_input_mass'],
                'theoretical_complete_units' => $yieldPlan['theoretical_complete_units'],
                'theoretical_residual_quantity' => $yieldPlan['theoretical_residual_quantity'],
                'expected_formed_units' => $yieldPlan['expected_formed_units'],
                'expected_good_units' => $yieldPlan['expected_good_units'],
                'expected_output_exception' => $yieldPlan['exception_code'],
                'status' => 'DRAFT', 'quality_status' => 'PENDING', 'record_version' => 1,
                'notes' => $this->nullable($data['notes'] ?? null), 'created_by' => $data['actor_id'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($materials as $index => $material) {
                $component = $componentSnapshots->get((string) $material->recipe_component_id);
                DB::table('production_order_materials')->insert([
                    'id' => (string) Str::uuid(), 'production_order_id' => $id,
                    'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                    'line_number' => $index + 1, 'mrp_material_requirement_id' => $material->id,
                    'component_sku_id' => $material->component_sku_id,
                    'required_quantity' => $this->decimal($material->gross_requirement),
                    'issued_quantity' => '0.000000', 'uom_code' => $material->uom_code,
                    'scaling_method_snapshot' => $component['scaling_method'] ?? 'SCALABLE',
                    'is_packaging' => (bool) ($component['is_packaging'] ?? false),
                    'original_plan_quantity' => $this->decimal($material->gross_requirement),
                    'reserved_quantity' => $this->decimal($material->gross_requirement),
                    'additional_issued_quantity' => '0.000000', 'returned_quantity' => '0.000000',
                    'wasted_quantity' => '0.000000', 'rework_in_quantity' => '0.000000',
                    'rework_out_quantity' => '0.000000', 'net_consumed_quantity' => '0.000000',
                    'normalised_standard_quantity' => $this->decimal($material->gross_requirement),
                    'original_plan_variance' => '0.000000', 'efficiency_variance' => '0.000000',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            foreach ($stages as $stage) {
                $template = DB::table('stage_measurement_templates')->where('company_id', $data['company_id'])
                    ->where('recipe_id', $recipe->id)->where('route_id', $route->id)
                    ->where('recipe_revision', $recipe->revision)->where('route_revision', $route->revision)
                    ->where('route_operation_id', $stage->route_operation_id)->where('status', 'APPROVED')
                    ->orderByDesc('revision')->first();
                $templateSnapshot = null;
                if ($template) {
                    $templateSnapshot = ['id' => (string) $template->id, 'revision' => (int) $template->revision,
                        'recipe_revision' => (int) $template->recipe_revision,
                        'route_revision' => (int) $template->route_revision,
                        'name' => (string) $template->name, 'lines' => DB::table('stage_measurement_template_lines')
                            ->where('template_id', $template->id)->orderBy('sequence_no')->get()->map(fn (object $line): array => (array) $line)->all()];
                }
                DB::table('production_order_stages')->insert([
                    'id' => (string) Str::uuid(), 'production_order_id' => $id,
                    'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                    'production_schedule_operation_id' => $stage->id, 'sequence_no' => $stage->sequence_no,
                    'operation_name' => $stage->operation_name, 'work_center_code' => $stage->work_center_code,
                    'planned_minutes' => $this->decimal($stage->required_minutes), 'status' => 'PENDING',
                    'measurement_template_id' => $template?->id,
                    'measurement_template_revision' => $template?->revision,
                    'measurement_template_snapshot' => $templateSnapshot === null ? null : json_encode($templateSnapshot, JSON_THROW_ON_ERROR),
                    'record_version' => 1, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            $result = $this->orderResult($id, 'DRAFT', 1);
            $this->record('CREATE_PRODUCTION_ORDER', 'manufacturing.production-order.created', 'production_order', $id, $data, 1, [
                'schedule_number' => $line->schedule_number, 'material_count' => $materials->count(), 'stage_count' => $stages->count(),
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function releaseOrder(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'manufacturing.production-order.release.'.$id;
            if ($replay = $this->begin($namespace, $data + ['production_order_id' => $id])) {
                return $replay;
            }
            $order = $this->findOrder($id, $data, true);
            $this->assertVersion($order, $data['expected_version'], 'production order');
            $this->assertStatus($order, ['DRAFT'], 'Only a draft production order can be released.');

            $reservations = DB::table('production_material_reservations as link')
                ->join('stock_reservations as reservation', 'reservation.id', '=', 'link.stock_reservation_id')
                ->where('link.production_schedule_line_id', $order->production_schedule_line_id)
                ->where('link.company_id', $data['company_id'])->where('link.plant_id', $data['plant_id'])
                ->whereNull('link.released_at')->where('reservation.status', 'ACTIVE')->get([
                    'link.mrp_material_requirement_id', 'link.quantity_base',
                ])->groupBy('mrp_material_requirement_id');
            $materials = DB::table('production_order_materials')->where('production_order_id', $id)->get();
            foreach ($materials as $material) {
                $reserved = $this->decimal($reservations->get($material->mrp_material_requirement_id)?->sum('quantity_base') ?? 0);
                if (bccomp($reserved, $this->decimal($material->required_quantity), 6) !== 0) {
                    throw ValidationException::withMessages([
                        'materials' => ["Material line {$material->line_number} is not backed by the exact active planning reservation."],
                    ]);
                }
            }

            $now = CarbonImmutable::now();
            $version = (int) $order->record_version + 1;
            DB::table('production_orders')->where('id', $id)->update([
                'status' => 'RELEASED', 'record_version' => $version,
                'released_at' => $now, 'released_by' => $data['actor_id'], 'updated_at' => $now,
            ]);
            $result = $this->orderResult($id, 'RELEASED', $version);
            $this->record('RELEASE_PRODUCTION_ORDER', 'manufacturing.production-order.released', 'production_order', $id, $data, $version, [
                'status' => ['from' => 'DRAFT', 'to' => 'RELEASED'], 'reservation_count' => $reservations->flatten()->count(),
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function issueMaterials(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'manufacturing.production-order.issue-materials.'.$id;
            if ($replay = $this->begin($namespace, $data + ['production_order_id' => $id])) {
                return $replay;
            }
            $order = $this->findOrder($id, $data, true);
            $this->assertVersion($order, $data['expected_version'], 'production order');
            $this->assertStatus($order, ['RELEASED', 'IN_PROCESS'], 'Material can only be issued to a released or in-process production order.');

            $rows = DB::table('production_material_reservations as link')
                ->join('stock_reservations as reservation', 'reservation.id', '=', 'link.stock_reservation_id')
                ->join('stock_positions as position', 'position.id', '=', 'reservation.stock_position_id')
                ->join('production_order_materials as material', function ($join) use ($id): void {
                    $join->on('material.mrp_material_requirement_id', '=', 'link.mrp_material_requirement_id')
                        ->where('material.production_order_id', '=', $id);
                })
                ->where('link.production_schedule_line_id', $order->production_schedule_line_id)
                ->where('link.company_id', $data['company_id'])->where('link.plant_id', $data['plant_id'])
                ->orderBy('reservation.stock_position_id')->orderBy('reservation.id')->lockForUpdate()
                ->get([
                    'link.id as link_id', 'link.quantity_base as link_quantity',
                    'reservation.id as reservation_id', 'reservation.quantity_base as reserved_quantity',
                    'reservation.status as reservation_status', 'reservation.record_version as reservation_version',
                    'position.id as position_id', 'position.item_id', 'position.lot_id', 'position.inventory_owner_id',
                    'position.quality_status', 'position.uom_code', 'position.quantity_base as position_quantity',
                    'position.reserved_quantity_base', 'position.record_version as position_version',
                    'material.id as material_id', 'material.component_sku_id',
                ]);
            if ($rows->isEmpty()) {
                throw new ConflictHttpException('No planning reservations remain for this production order.');
            }

            $now = CarbonImmutable::now();
            $issuedTotal = '0.000000';
            foreach ($rows as $row) {
                if ($row->reservation_status !== 'ACTIVE') {
                    throw new ConflictHttpException('A production reservation was already consumed or released. Refresh the order.');
                }
                $quantity = $this->decimal($row->reserved_quantity);
                $previouslyIssued = $this->decimal(DB::table('production_material_issues')
                    ->where('production_material_reservation_id', $row->link_id)->sum('quantity'));
                $linkRemaining = bcsub($this->decimal($row->link_quantity), $previouslyIssued, 6);
                if (bccomp($quantity, $linkRemaining, 6) !== 0
                    || bccomp($this->decimal($row->reserved_quantity_base), $quantity, 6) < 0
                    || $row->item_id !== $row->component_sku_id) {
                    throw new ConflictHttpException('The material reservation and stock projection are inconsistent.');
                }

                DB::table('stock_reservations')->where('id', $row->reservation_id)->update([
                    'status' => 'RELEASED', 'record_version' => (int) $row->reservation_version + 1,
                    'released_at' => $now, 'released_by' => $data['actor_id'],
                    'release_reason' => "Consumed by production order {$order->order_number}", 'updated_at' => $now,
                ]);
                DB::table('stock_positions')->where('id', $row->position_id)->update([
                    'reserved_quantity_base' => bcsub($this->decimal($row->reserved_quantity_base), $quantity, 6),
                    'record_version' => (int) $row->position_version + 1, 'updated_at' => $now,
                ]);

                $movement = $this->stock->issue([
                    'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                    'source_position_id' => $row->position_id, 'quantity_base' => $quantity,
                    'uom_code' => $row->uom_code, 'expected_item_id' => $row->component_sku_id,
                    'expected_lot_id' => $row->lot_id, 'expected_owner_id' => $row->inventory_owner_id,
                    'expected_quality_status' => $row->quality_status, 'movement_type' => 'PRODUCTION_ISSUE',
                    'source_type' => 'production_order', 'source_id' => $id,
                    'source_version' => (int) $order->record_version, 'actor_id' => $data['actor_id'],
                    'reason_code' => 'MATERIAL_CONSUMPTION',
                    'idempotency_key' => "production-order:{$id}:reservation:{$row->reservation_id}",
                    'correlation_id' => $data['correlation_id'] ?? null,
                ]);
                $issueId = (string) Str::uuid();
                DB::table('production_material_issues')->insert([
                    'id' => $issueId, 'production_order_id' => $id,
                    'production_order_material_id' => $row->material_id,
                    'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                    'production_material_reservation_id' => $row->link_id,
                    'stock_reservation_id' => $row->reservation_id, 'source_position_id' => $row->position_id,
                    'input_lot_id' => $row->lot_id, 'stock_movement_id' => $movement['movement_id'],
                    'quantity' => $quantity, 'uom_code' => $row->uom_code,
                    'issued_by' => $data['actor_id'], 'issued_at' => $now, 'created_at' => $now,
                ]);
                DB::table('production_material_reservations')->where('id', $row->link_id)->update([
                    'released_at' => $now, 'updated_at' => $now,
                ]);
                DB::table('production_order_materials')->where('id', $row->material_id)->increment('issued_quantity', $quantity, [
                    'updated_at' => $now,
                ]);
                DB::table('production_material_postings')->insert([
                    'id' => (string) Str::uuid(), 'production_order_id' => $id,
                    'production_order_material_id' => $row->material_id,
                    'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                    'posting_type' => 'PLANNED_ISSUE', 'quantity' => $quantity, 'uom_code' => $row->uom_code,
                    'lot_id' => $row->lot_id, 'inventory_owner_id' => $row->inventory_owner_id,
                    'from_position_id' => $row->position_id, 'to_position_id' => null,
                    'stock_movement_id' => $movement['movement_id'], 'parent_posting_id' => null,
                    'quality_status' => $row->quality_status, 'status' => 'POSTED',
                    'reason_code' => 'MATERIAL_CONSUMPTION', 'disposition_code' => null,
                    'notes' => null, 'posted_by' => $data['actor_id'], 'posted_at' => $now,
                    'approved_by' => null, 'approved_at' => null, 'created_at' => $now, 'updated_at' => $now,
                ]);
                $this->refreshMaterialReconciliation((string) $row->material_id);
                $issuedTotal = bcadd($issuedTotal, $quantity, 6);
            }
            if (DB::table('production_order_materials')->where('production_order_id', $id)
                ->whereColumn('issued_quantity', '<', 'required_quantity')->exists()) {
                throw new ConflictHttpException('The atomic material issue did not satisfy every production requirement.');
            }

            $version = (int) $order->record_version + 1;
            DB::table('production_orders')->where('id', $id)->update([
                'status' => 'IN_PROCESS', 'record_version' => $version,
                'started_at' => $now, 'updated_at' => $now,
            ]);
            $result = $this->orderResult($id, 'IN_PROCESS', $version) + [
                'issue_count' => $rows->count(), 'issued_quantity' => $issuedTotal,
            ];
            $this->record('ISSUE_PRODUCTION_MATERIALS', 'manufacturing.production-materials.issued', 'production_order', $id, $data, $version, [
                'status' => ['from' => $order->status, 'to' => 'IN_PROCESS'], 'issue_count' => $rows->count(),
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function startStage(string $stageId, array $data): array
    {
        return DB::transaction(function () use ($stageId, $data): array {
            $namespace = 'manufacturing.production-stage.start.'.$stageId;
            if ($replay = $this->begin($namespace, $data + ['production_stage_id' => $stageId])) {
                return $replay;
            }
            $stage = $this->findStage($stageId, $data, true);
            $this->assertVersion($stage, $data['expected_version'], 'production stage');
            if ($stage->status !== 'PENDING' || $stage->order_status !== 'IN_PROCESS') {
                throw ValidationException::withMessages(['status' => ['Only a pending stage on an in-process order can be started.']]);
            }
            if (DB::table('production_order_stages')->where('production_order_id', $stage->production_order_id)
                ->where('status', 'IN_PROGRESS')->exists()) {
                throw ValidationException::withMessages(['status' => ['Complete the current in-progress stage before starting another.']]);
            }
            if (DB::table('production_order_stages')->where('production_order_id', $stage->production_order_id)
                ->where('sequence_no', '<', $stage->sequence_no)->where('status', '<>', 'COMPLETED')->exists()) {
                throw ValidationException::withMessages(['sequence' => ['Every preceding production stage must be completed first.']]);
            }
            $now = CarbonImmutable::now();
            $version = (int) $stage->record_version + 1;
            DB::table('production_order_stages')->where('id', $stageId)->update([
                'status' => 'IN_PROGRESS', 'record_version' => $version,
                'started_at' => $now, 'started_by' => $data['actor_id'], 'updated_at' => $now,
            ]);
            DB::table('stage_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'status' => 'POSTED', 'record_version' => 1, 'created_by' => $data['actor_id'],
                'production_order_id' => $stage->production_order_id, 'production_order_stage_id' => $stageId,
                'event_type' => 'STARTED', 'event_at' => $now, 'actual_minutes' => null, 'notes' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $result = $this->stageResult($stageId, $stage->production_order_id, 'IN_PROGRESS', $version);
            $this->record('START_PRODUCTION_STAGE', 'manufacturing.production-stage.started', 'production_stage', $stageId, $data, $version, [
                'status' => ['from' => 'PENDING', 'to' => 'IN_PROGRESS'], 'sequence_no' => (int) $stage->sequence_no,
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function completeStage(string $stageId, array $data): array
    {
        return DB::transaction(function () use ($stageId, $data): array {
            $namespace = 'manufacturing.production-stage.complete.'.$stageId;
            if ($replay = $this->begin($namespace, $data + ['production_stage_id' => $stageId])) {
                return $replay;
            }
            $stage = $this->findStage($stageId, $data, true);
            $this->assertVersion($stage, $data['expected_version'], 'production stage');
            if ($stage->status !== 'IN_PROGRESS' || $stage->order_status !== 'IN_PROCESS') {
                throw ValidationException::withMessages(['status' => ['Only an in-progress stage can be completed.']]);
            }
            $minutes = $this->nonNegative($data['actual_minutes'], 'actual_minutes', 'Actual minutes');
            $this->measurements->assertStageReadyForCompletion($stageId);
            $now = CarbonImmutable::now();
            $version = (int) $stage->record_version + 1;
            DB::table('production_order_stages')->where('id', $stageId)->update([
                'status' => 'COMPLETED', 'record_version' => $version, 'actual_minutes' => $minutes,
                'completed_at' => $now, 'completed_by' => $data['actor_id'],
                'notes' => $this->nullable($data['notes'] ?? null), 'updated_at' => $now,
            ]);
            DB::table('stage_events')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'status' => 'POSTED', 'record_version' => 1, 'created_by' => $data['actor_id'],
                'production_order_id' => $stage->production_order_id, 'production_order_stage_id' => $stageId,
                'event_type' => 'COMPLETED', 'event_at' => $now, 'actual_minutes' => $minutes,
                'notes' => $this->nullable($data['notes'] ?? null), 'created_at' => $now, 'updated_at' => $now,
            ]);
            $result = $this->stageResult($stageId, $stage->production_order_id, 'COMPLETED', $version) + [
                'actual_minutes' => $minutes,
            ];
            $this->record('COMPLETE_PRODUCTION_STAGE', 'manufacturing.production-stage.completed', 'production_stage', $stageId, $data, $version, [
                'status' => ['from' => 'IN_PROGRESS', 'to' => 'COMPLETED'], 'actual_minutes' => $minutes,
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function recordOutput(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'manufacturing.production-output.record.'.$id;
            if ($replay = $this->begin($namespace, $data + ['production_order_id' => $id])) {
                return $replay;
            }
            $order = $this->findOrder($id, $data, true);
            $this->assertVersion($order, $data['expected_version'], 'production order');
            $this->assertStatus($order, ['IN_PROCESS'], 'Output can only be declared for an in-process production order.');
            $quantity = $this->positive($data['quantity'], 'quantity', 'Output quantity');
            $type = Str::upper($data['event_type']);
            if ($type !== 'GOOD' && trim((string) ($data['reason_code'] ?? '')) === '') {
                throw ValidationException::withMessages(['reason_code' => ['Loss and rework declarations require a reason code.']]);
            }
            $dimension = Str::upper((string) ($data['quantity_dimension'] ?? 'COUNT'));
            $source = Str::upper((string) ($data['value_source'] ?? 'MEASURED'));
            $category = $type === 'GOOD' ? null : Str::upper((string) ($data['loss_category']
                ?? ($type === 'REWORK' ? 'APPROVED_REWORK' : 'NORMAL_PROCESS_LOSS')));
            if (! in_array($dimension, self::QUANTITY_DIMENSIONS, true) || ! in_array($source, self::VALUE_SOURCES, true)
                || ($category !== null && ! in_array($category, self::LOSS_CATEGORIES, true))) {
                throw ValidationException::withMessages(['classification' => ['Choose a supported output dimension, source and loss category.']]);
            }
            if ($category === 'OVERWEIGHT_GIVEAWAY' && $dimension !== 'MASS') {
                throw ValidationException::withMessages(['quantity_dimension' => ['Overweight giveaway must be recorded as a mass quantity.']]);
            }
            if ($category === 'REJECTED_OUTPUT' && trim((string) ($data['disposition_code'] ?? '')) === '') {
                throw ValidationException::withMessages(['disposition_code' => ['Rejected output requires a disposition.']]);
            }
            $weightUom = $this->nullable($data['weight_uom_code'] ?? null);
            if (($dimension === 'MASS' || ($data['weight_quantity'] ?? null) !== null)
                && ($weightUom === null || ! DB::table('uoms')->where('code', Str::upper($weightUom))->exists())) {
                throw ValidationException::withMessages(['weight_uom_code' => ['Choose a valid weight UOM for a mass reading.']]);
            }
            $eventUom = $dimension === 'MASS' ? Str::upper((string) $weightUom) : $order->uom_code;
            $sampleWeight = ($data['weight_quantity'] ?? null) === null
                ? null : $this->positive($data['weight_quantity'], 'weight_quantity', 'Weight quantity');
            $already = $this->decimal(DB::table('production_output_events')->where('production_order_id', $id)
                ->where('quantity_dimension', 'COUNT')->sum('quantity'));
            if (($order->calculation_model_snapshot ?? 'LEGACY_YIELD') === 'LEGACY_YIELD' && $dimension === 'COUNT'
                && bccomp(bcadd($already, $quantity, 6), $this->decimal($order->planned_quantity), 6) > 0) {
                throw ValidationException::withMessages(['quantity' => ['Declared good, loss, and rework quantity cannot exceed the planned batch quantity.']]);
            }
            $sequence = (int) DB::table('production_output_events')->where('production_order_id', $id)->max('sequence_no') + 1;
            $eventId = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('production_output_events')->insert([
                'id' => $eventId, 'production_order_id' => $id,
                'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'sequence_no' => $sequence, 'event_type' => $type, 'quantity' => $quantity,
                'uom_code' => $eventUom, 'reason_code' => $type === 'GOOD' ? null : Str::upper(trim($data['reason_code'])),
                'notes' => $this->nullable($data['notes'] ?? null),
                'loss_category' => $category, 'disposition_code' => $this->nullable($data['disposition_code'] ?? null),
                'value_source' => $source, 'quantity_dimension' => $dimension,
                'weight_quantity' => $dimension === 'MASS' ? $quantity : $sampleWeight,
                'weight_uom_code' => $weightUom === null ? null : Str::upper($weightUom),
                'rework_status' => $type === 'REWORK' ? 'OPEN' : 'NA',
                'recorded_by' => $data['actor_id'], 'recorded_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $version = (int) $order->record_version + 1;
            DB::table('production_orders')->where('id', $id)->update(['record_version' => $version, 'updated_at' => $now]);
            $this->materialReconciliation->persistRecorded($id);
            $metrics = $this->outputMetrics($id);
            $result = $this->orderResult($id, 'IN_PROCESS', $version) + ['output_event_id' => $eventId] + $metrics;
            $this->record('RECORD_PRODUCTION_OUTPUT', 'manufacturing.production-output.recorded', 'production_order', $id, $data, $version, [
                'event_type' => $type, 'quantity' => $quantity, 'accounted_quantity' => $metrics['accounted_quantity'],
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function resolveRework(string $eventId, array $data): array
    {
        return DB::transaction(function () use ($eventId, $data): array {
            $namespace = 'manufacturing.production-rework.resolve.'.$eventId;
            if ($replay = $this->begin($namespace, $data + ['production_output_event_id' => $eventId])) {
                return $replay;
            }
            $event = DB::table('production_output_events')->where('id', $eventId)
                ->where('company_id', $data['company_id'])->where('plant_id', $data['plant_id'])
                ->lockForUpdate()->first();
            if (! $event) {
                throw new NotFoundHttpException('Production output event not found.');
            }
            if ($event->event_type !== 'REWORK' || $event->rework_status !== 'OPEN') {
                throw ValidationException::withMessages(['status' => ['Only an open rework declaration can be resolved.']]);
            }
            $order = $this->findOrder($event->production_order_id, $data, true);
            if ($order->status !== 'IN_PROCESS') {
                throw ValidationException::withMessages(['status' => ['Rework must be resolved before the production order is completed.']]);
            }
            $now = CarbonImmutable::now();
            DB::table('production_output_events')->where('id', $eventId)->update([
                'rework_status' => Str::upper($data['disposition']), 'resolved_at' => $now,
                'resolved_by' => $data['actor_id'], 'resolution_notes' => trim($data['notes']), 'updated_at' => $now,
            ]);
            $version = (int) $order->record_version + 1;
            DB::table('production_orders')->where('id', $order->id)->update(['record_version' => $version, 'updated_at' => $now]);
            $this->materialReconciliation->persistRecorded((string) $order->id);
            $metrics = $this->outputMetrics($order->id);
            $result = $this->orderResult($order->id, 'IN_PROCESS', $version) + ['output_event_id' => $eventId] + $metrics;
            $this->record('RESOLVE_PRODUCTION_REWORK', 'manufacturing.production-rework.resolved', 'production_order', $order->id, $data, $version, [
                'rework_status' => ['from' => 'OPEN', 'to' => Str::upper($data['disposition'])],
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function completeOrder(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'manufacturing.production-order.complete.'.$id;
            if ($replay = $this->begin($namespace, $data + ['production_order_id' => $id])) {
                return $replay;
            }
            $order = $this->findOrder($id, $data, true);
            $this->assertVersion($order, $data['expected_version'], 'production order');
            $this->assertStatus($order, ['IN_PROCESS'], 'Only an in-process production order can be completed.');
            if (DB::table('production_order_materials')->where('production_order_id', $id)
                ->whereColumn('issued_quantity', '<', 'required_quantity')->exists()) {
                throw ValidationException::withMessages(['materials' => ['Issue every planned material quantity before completing production.']]);
            }
            if (DB::table('production_order_stages')->where('production_order_id', $id)->where('status', '<>', 'COMPLETED')->exists()) {
                throw ValidationException::withMessages(['stages' => ['Complete every route stage before completing production.']]);
            }
            $metrics = $this->outputMetrics($id);
            if (($order->calculation_model_snapshot ?? 'LEGACY_YIELD') === 'LEGACY_YIELD'
                && bccomp($metrics['accounted_quantity'], $this->decimal($order->planned_quantity), 6) !== 0) {
                throw ValidationException::withMessages(['output' => ['Good, loss, and rework declarations must account for the exact planned batch quantity.']]);
            }
            if ($metrics['open_rework_count'] > 0) {
                throw ValidationException::withMessages(['rework' => ['Resolve every rework declaration before completing production.']]);
            }
            if (bccomp($metrics['good_quantity'], '0', 6) <= 0) {
                throw ValidationException::withMessages(['output' => ['A completed production order must have positive recoverable good output.']]);
            }
            $this->materialReconciliation->persistRecorded($id);
            $now = CarbonImmutable::now();
            $version = (int) $order->record_version + 1;
            DB::table('production_orders')->where('id', $id)->update([
                'status' => 'COMPLETED', 'record_version' => $version,
                'completed_at' => $now, 'completed_by' => $data['actor_id'], 'updated_at' => $now,
            ]);
            $result = $this->orderResult($id, 'COMPLETED', $version) + $metrics;
            $this->record('COMPLETE_PRODUCTION_ORDER', 'manufacturing.production-order.completed', 'production_order', $id, $data, $version, [
                'status' => ['from' => 'IN_PROCESS', 'to' => 'COMPLETED'],
                'good_quantity' => $metrics['good_quantity'], 'loss_quantity' => $metrics['loss_quantity'],
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function cancelOrder(string $id, string $reason, array $data): array
    {
        return DB::transaction(function () use ($id, $reason, $data): array {
            $namespace = 'manufacturing.production-order.cancel.'.$id;
            if ($replay = $this->begin($namespace, $data + ['production_order_id' => $id, 'reason' => $reason])) {
                return $replay;
            }
            $order = $this->findOrder($id, $data, true);
            $this->assertVersion($order, $data['expected_version'], 'production order');
            $this->assertStatus($order, ['DRAFT', 'RELEASED'], 'Only an unstarted production order can be cancelled.');
            $now = CarbonImmutable::now();
            $version = (int) $order->record_version + 1;
            DB::table('production_orders')->where('id', $id)->update([
                'status' => 'CANCELLED', 'record_version' => $version,
                'cancelled_at' => $now, 'cancelled_by' => $data['actor_id'],
                'cancellation_reason' => trim($reason), 'updated_at' => $now,
            ]);
            $result = $this->orderResult($id, 'CANCELLED', $version);
            $this->record('CANCEL_PRODUCTION_ORDER', 'manufacturing.production-order.cancelled', 'production_order', $id, $data, $version, [
                'status' => ['from' => $order->status, 'to' => 'CANCELLED'], 'reason' => trim($reason),
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function outputMetrics(string $orderId): array
    {
        $events = DB::table('production_output_events')->where('production_order_id', $orderId)->get();
        $countEvents = $events->where('quantity_dimension', 'COUNT');
        $directGood = $this->decimal($countEvents->where('event_type', 'GOOD')->sum('quantity'));
        $loss = $this->decimal($countEvents->where('event_type', 'LOSS')->sum('quantity'));
        $rework = $this->decimal($countEvents->where('event_type', 'REWORK')->sum('quantity'));
        $recovered = $this->decimal($countEvents->where('rework_status', 'RECOVERED')->sum('quantity'));
        $scrapped = $this->decimal($countEvents->where('rework_status', 'SCRAPPED')->sum('quantity'));
        $expected = $this->decimal(DB::table('production_orders')->where('id', $orderId)->value('expected_good_units'));
        $adjustments = DB::table('production_batch_adjustment_effects')->where('production_order_id', $orderId)
            ->whereIn('metric_code', ['GOOD_OUTPUT', 'LOSS_OUTPUT', 'REWORK_OUTPUT'])
            ->selectRaw('metric_code, SUM(signed_quantity) as signed_quantity')->groupBy('metric_code')
            ->pluck('signed_quantity', 'metric_code');
        $goodAdjustment = $this->decimal($adjustments->get('GOOD_OUTPUT', 0));
        $lossAdjustment = $this->decimal($adjustments->get('LOSS_OUTPUT', 0));
        $reworkAdjustment = $this->decimal($adjustments->get('REWORK_OUTPUT', 0));
        $finalGood = bcadd(bcadd($directGood, $recovered, 6), $goodAdjustment, 6);
        $finalLoss = bcadd(bcadd($loss, $scrapped, 6), $lossAdjustment, 6);
        $adjustedRework = bcadd($rework, $reworkAdjustment, 6);
        $openRework = bcadd($this->decimal($countEvents->where('event_type', 'REWORK')
            ->where('rework_status', 'OPEN')->sum('quantity')), $reworkAdjustment, 6);
        $variance = bcsub($finalGood, $expected, 6);

        return [
            'accounted_quantity' => bcadd(bcadd($finalGood, $finalLoss, 6), $openRework, 6),
            'first_pass_good_quantity' => $directGood,
            'recovered_quantity' => $recovered,
            'good_quantity' => $finalGood,
            'final_good_quantity' => $finalGood,
            'loss_quantity' => $finalLoss,
            'rework_quantity' => $adjustedRework,
            'scrapped_rework_quantity' => $scrapped,
            'approved_output_adjustments' => ['good' => $goodAdjustment, 'loss' => $lossAdjustment,
                'rework' => $reworkAdjustment],
            'expected_good_quantity' => $expected,
            'output_variance' => $variance,
            'output_variance_percent' => bccomp($expected, '0', 6) === 0 ? null
                : bcmul(bcdiv($variance, $expected, 8), '100', 6),
            'output_variance_exception' => bccomp($expected, '0', 6) === 0 ? 'ZERO_EXPECTED_OUTPUT' : null,
            'normal_process_loss_quantity' => $this->decimal($countEvents->where('loss_category', 'NORMAL_PROCESS_LOSS')->sum('quantity')),
            'handling_waste_quantity' => $this->decimal($countEvents->where('loss_category', 'HANDLING_WASTE')->sum('quantity')),
            'rejected_output_quantity' => $this->decimal($countEvents->where('loss_category', 'REJECTED_OUTPUT')->sum('quantity')),
            'overweight_giveaway_quantity' => $this->decimal($events->where('quantity_dimension', 'MASS')->where('loss_category', 'OVERWEIGHT_GIVEAWAY')->sum('quantity')),
            'unexplained_variance_quantity' => $this->decimal($countEvents->where('loss_category', 'UNEXPLAINED_VARIANCE')->sum('quantity')),
            'open_rework_count' => $countEvents->where('event_type', 'REWORK')->where('rework_status', 'OPEN')->count(),
        ];
    }

    public function issueMaterial(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'manufacturing.production-material.issue.'.$id;
            if ($replay = $this->begin($namespace, $data + ['production_order_id' => $id])) {
                return $replay;
            }
            $order = $this->findOrder($id, $data, true);
            $this->assertVersion($order, $data['expected_version'], 'production order');
            $kind = Str::upper((string) ($data['issue_kind'] ?? 'PLANNED'));
            $allowedStatuses = $kind === 'ADDITIONAL' ? ['IN_PROCESS'] : ['RELEASED', 'IN_PROCESS'];
            $this->assertStatus($order, $allowedStatuses, 'This material issue is not allowed in the current production state.');
            $material = DB::table('production_order_materials')->where('id', $data['production_order_material_id'])
                ->where('production_order_id', $id)->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])->lockForUpdate()->first();
            if (! $material) {
                throw ValidationException::withMessages(['production_order_material_id' => ['Production material line not found in the selected batch.']]);
            }
            $quantity = $this->positive($data['quantity'], 'quantity', 'Issue quantity');
            $now = CarbonImmutable::now();
            $posted = [];

            if ($kind === 'PLANNED') {
                $remainingRequirement = bcsub($this->decimal($material->required_quantity), $this->decimal($material->issued_quantity), 6);
                if (bccomp($quantity, $remainingRequirement, 6) > 0) {
                    throw ValidationException::withMessages(['quantity' => ['Planned issue cannot exceed the unissued standard requirement. Use an additional issue with a reason.']]);
                }
                $rows = DB::table('production_material_reservations as link')
                    ->join('stock_reservations as reservation', 'reservation.id', '=', 'link.stock_reservation_id')
                    ->join('stock_positions as position', 'position.id', '=', 'reservation.stock_position_id')
                    ->where('link.production_schedule_line_id', $order->production_schedule_line_id)
                    ->where('link.mrp_material_requirement_id', $material->mrp_material_requirement_id)
                    ->where('link.company_id', $data['company_id'])->where('link.plant_id', $data['plant_id'])
                    ->whereNull('link.released_at')->where('reservation.status', 'ACTIVE')
                    ->when(! empty($data['source_position_id']), fn ($query) => $query->where('position.id', $data['source_position_id']))
                    ->orderBy('position.id')->orderBy('reservation.id')->lockForUpdate()->get([
                        'link.id as link_id', 'reservation.id as reservation_id',
                        'reservation.quantity_base as reserved_quantity', 'reservation.record_version as reservation_version',
                        'position.id as position_id', 'position.item_id', 'position.lot_id', 'position.inventory_owner_id',
                        'position.quality_status', 'position.uom_code', 'position.reserved_quantity_base',
                        'position.record_version as position_version',
                    ]);
                $remaining = $quantity;
                foreach ($rows as $row) {
                    if (bccomp($remaining, '0', 6) <= 0) {
                        break;
                    }
                    if ($row->item_id !== $material->component_sku_id || $row->uom_code !== $material->uom_code) {
                        throw new ConflictHttpException('The planned reservation no longer matches its material snapshot.');
                    }
                    $reserved = $this->decimal($row->reserved_quantity);
                    $cover = bccomp($reserved, $remaining, 6) > 0 ? $remaining : $reserved;
                    if (bccomp($this->decimal($row->reserved_quantity_base), $cover, 6) < 0) {
                        throw new ConflictHttpException('Reserved stock changed before the partial issue could post.');
                    }
                    $fullyConsumed = bccomp($cover, $reserved, 6) === 0;
                    DB::table('stock_reservations')->where('id', $row->reservation_id)->update($fullyConsumed ? [
                        'status' => 'RELEASED', 'record_version' => (int) $row->reservation_version + 1,
                        'released_at' => $now, 'released_by' => $data['actor_id'],
                        'release_reason' => "Consumed by production order {$order->order_number}", 'updated_at' => $now,
                    ] : [
                        'quantity_base' => bcsub($reserved, $cover, 6),
                        'record_version' => (int) $row->reservation_version + 1, 'updated_at' => $now,
                    ]);
                    DB::table('stock_positions')->where('id', $row->position_id)->update([
                        'reserved_quantity_base' => bcsub($this->decimal($row->reserved_quantity_base), $cover, 6),
                        'record_version' => (int) $row->position_version + 1, 'updated_at' => $now,
                    ]);
                    if ($fullyConsumed) {
                        DB::table('production_material_reservations')->where('id', $row->link_id)->update(['released_at' => $now, 'updated_at' => $now]);
                    }
                    $posted[] = $this->postMaterialIssue($order, $material, $row, $cover, 'PLANNED_ISSUE',
                        'MATERIAL_CONSUMPTION', $data, $now, (string) $row->link_id, (string) $row->reservation_id);
                    $remaining = bcsub($remaining, $cover, 6);
                }
                if (bccomp($remaining, '0', 6) > 0) {
                    throw ValidationException::withMessages(['quantity' => ['Insufficient active reserved stock for this partial issue.']]);
                }
                DB::table('production_order_materials')->where('id', $material->id)->increment('issued_quantity', $quantity, ['updated_at' => $now]);
            } else {
                if (trim((string) ($data['reason_code'] ?? '')) === '' || empty($data['source_position_id'])) {
                    throw ValidationException::withMessages(['reason_code' => ['Additional issues require a source position and reason code.']]);
                }
                $position = DB::table('stock_positions as position')
                    ->join('inventory_quality_statuses as quality', 'quality.code', '=', 'position.quality_status')
                    ->where('position.id', $data['source_position_id'])->where('position.company_id', $data['company_id'])
                    ->where('position.plant_id', $data['plant_id'])->where('quality.is_reservable', true)
                    ->lockForUpdate()->first(['position.*']);
                if (! $position || $position->item_id !== $material->component_sku_id || $position->uom_code !== $material->uom_code) {
                    throw ValidationException::withMessages(['source_position_id' => ['Choose reservable stock for this material, plant and UOM.']]);
                }
                $row = (object) ['position_id' => $position->id, 'item_id' => $position->item_id,
                    'lot_id' => $position->lot_id, 'inventory_owner_id' => $position->inventory_owner_id,
                    'quality_status' => $position->quality_status, 'uom_code' => $position->uom_code];
                $posted[] = $this->postMaterialIssue($order, $material, $row, $quantity, 'ADDITIONAL_ISSUE',
                    Str::upper(trim((string) $data['reason_code'])), $data, $now, null, null);
                DB::table('production_order_materials')->where('id', $material->id)->increment('additional_issued_quantity', $quantity, ['updated_at' => $now]);
            }

            $this->refreshMaterialReconciliation((string) $material->id);
            $this->materialReconciliation->persistRecorded($id);
            $version = (int) $order->record_version + 1;
            DB::table('production_orders')->where('id', $id)->update([
                'status' => 'IN_PROCESS', 'record_version' => $version,
                'started_at' => $order->started_at ?? $now, 'updated_at' => $now,
            ]);
            $result = $this->orderResult($id, 'IN_PROCESS', $version) + [
                'posting_ids' => collect($posted)->pluck('posting_id')->all(), 'issue_kind' => $kind,
                'issued_quantity' => $quantity,
            ];
            $this->record('ISSUE_PRODUCTION_MATERIAL', 'manufacturing.production-material.issued', 'production_order', $id, $data, $version, [
                'material_id' => $material->id, 'issue_kind' => $kind, 'quantity' => $quantity,
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function returnMaterial(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'manufacturing.production-material.return.'.$id;
            if ($replay = $this->begin($namespace, $data + ['production_order_id' => $id])) {
                return $replay;
            }
            $order = $this->findOrder($id, $data, true);
            $this->assertVersion($order, $data['expected_version'], 'production order');
            $this->assertStatus($order, ['IN_PROCESS'], 'Direct material returns are only allowed while the batch is in process. Closed batches require an adjustment request.');
            $postingId = $data['production_material_posting_id'] ?? null;
            if ($postingId === null && ! empty($data['production_material_issue_id'])) {
                $postingId = DB::table('production_material_issues as issue')
                    ->join('production_material_postings as posting', 'posting.stock_movement_id', '=', 'issue.stock_movement_id')
                    ->where('issue.id', $data['production_material_issue_id'])->value('posting.id');
            }
            $issue = DB::table('production_material_postings as posting')
                ->join('production_order_materials as material', 'material.id', '=', 'posting.production_order_material_id')
                ->join('stock_positions as source', 'source.id', '=', 'posting.from_position_id')
                ->where('posting.id', $postingId)->where('posting.production_order_id', $id)
                ->whereIn('posting.posting_type', ['PLANNED_ISSUE', 'ADDITIONAL_ISSUE'])
                ->where('posting.company_id', $data['company_id'])->where('posting.plant_id', $data['plant_id'])
                ->lockForUpdate()->first(['posting.*', 'material.component_sku_id', 'source.inventory_owner_id']);
            if (! $issue) {
                throw ValidationException::withMessages(['production_material_posting_id' => ['Issued material posting not found in this batch.']]);
            }
            $quantity = $this->positive($data['quantity'], 'quantity', 'Return quantity');
            $alreadyReturned = $this->decimal(DB::table('production_material_postings')
                ->where('posting_type', 'RETURN_QUARANTINE')->where('parent_posting_id', $issue->id)->sum('quantity'));
            if (bccomp(bcadd($alreadyReturned, $quantity, 6), $this->decimal($issue->quantity), 6) > 0) {
                throw ValidationException::withMessages(['quantity' => ['Return quantity exceeds the unreturned quantity from this issue.']]);
            }
            $target = DB::table('stock_positions as position')
                ->join('inventory_quality_statuses as quality', 'quality.code', '=', 'position.quality_status')
                ->where('position.id', $data['quarantine_position_id'])->where('position.company_id', $data['company_id'])
                ->where('position.plant_id', $data['plant_id'])->where('quality.is_reservable', false)
                ->lockForUpdate()->first(['position.*']);
            if (! $target || $target->item_id !== $issue->component_sku_id || $target->lot_id !== $issue->lot_id
                || $target->inventory_owner_id !== $issue->inventory_owner_id || $target->uom_code !== $issue->uom_code) {
                throw ValidationException::withMessages(['quarantine_position_id' => ['Return quarantine must preserve the original item, lot, owner and UOM.']]);
            }
            $now = CarbonImmutable::now();
            $movement = $this->stock->receive([
                'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'target_position_id' => $target->id, 'quantity_base' => $quantity, 'uom_code' => $issue->uom_code,
                'expected_item_id' => $issue->component_sku_id, 'expected_lot_id' => $issue->lot_id,
                'expected_owner_id' => $issue->inventory_owner_id, 'expected_quality_status' => $target->quality_status,
                'movement_type' => 'PRODUCTION_RETURN_QUARANTINE', 'source_type' => 'production_order', 'source_id' => $id,
                'source_version' => (int) $order->record_version, 'actor_id' => $data['actor_id'],
                'reason_code' => Str::upper(trim((string) $data['reason_code'])),
                'idempotency_key' => 'production-return:'.$data['idempotency_key'],
                'correlation_id' => $data['correlation_id'] ?? null,
            ]);
            $returnPostingId = (string) Str::uuid();
            DB::table('production_material_postings')->insert([
                'id' => $returnPostingId, 'production_order_id' => $id, 'production_order_material_id' => $issue->production_order_material_id,
                'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'posting_type' => 'RETURN_QUARANTINE',
                'quantity' => $quantity, 'uom_code' => $issue->uom_code, 'lot_id' => $issue->lot_id,
                'inventory_owner_id' => $issue->inventory_owner_id, 'from_position_id' => null, 'to_position_id' => $target->id,
                'stock_movement_id' => $movement['movement_id'], 'parent_posting_id' => $issue->id,
                'quality_status' => $target->quality_status, 'status' => 'QC_PENDING',
                'reason_code' => Str::upper(trim((string) $data['reason_code'])), 'disposition_code' => null,
                'notes' => $this->nullable($data['notes'] ?? null), 'posted_by' => $data['actor_id'], 'posted_at' => $now,
                'approved_by' => null, 'approved_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('production_order_materials')->where('id', $issue->production_order_material_id)
                ->increment('returned_quantity', $quantity, ['updated_at' => $now]);
            $this->refreshMaterialReconciliation((string) $issue->production_order_material_id);
            $this->materialReconciliation->persistRecorded($id);
            $version = (int) $order->record_version + 1;
            DB::table('production_orders')->where('id', $id)->update(['record_version' => $version, 'updated_at' => $now]);
            $result = $this->orderResult($id, 'IN_PROCESS', $version) + ['posting_id' => $returnPostingId,
                'stock_movement_id' => $movement['movement_id'], 'return_status' => 'QC_PENDING'];
            $this->record('RETURN_PRODUCTION_MATERIAL', 'manufacturing.production-material.returned-to-quarantine',
                'production_order', $id, $data, $version, ['material_id' => $issue->production_order_material_id,
                    'quantity' => $quantity, 'lot_id' => $issue->lot_id], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
            return $result;
        }, 3);
    }

    public function recordMaterialDisposition(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'manufacturing.production-material.disposition.'.$id;
            if ($replay = $this->begin($namespace, $data + ['production_order_id' => $id])) {
                return $replay;
            }
            $order = $this->findOrder($id, $data, true);
            $this->assertVersion($order, $data['expected_version'], 'production order');
            $this->assertStatus($order, ['IN_PROCESS'], 'Material waste and rework can only be classified while the batch is in process.');
            $kind = Str::upper((string) $data['disposition_kind']);
            if (! in_array($kind, self::MATERIAL_DISPOSITIONS, true)) {
                throw ValidationException::withMessages(['disposition_kind' => ['Choose waste, rework out, or rework in.']]);
            }
            $parent = DB::table('production_material_postings')->where('id', $data['parent_posting_id'])
                ->where('production_order_id', $id)->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])->lockForUpdate()->first();
            $allowedParents = $kind === 'REWORK_IN' ? ['REWORK_OUT'] : ['PLANNED_ISSUE', 'ADDITIONAL_ISSUE'];
            if (! $parent || ! in_array($parent->posting_type, $allowedParents, true)) {
                throw ValidationException::withMessages(['parent_posting_id' => [
                    $kind === 'REWORK_IN'
                        ? 'Rework in must reference an open rework-out posting from this batch.'
                        : 'Waste and rework out must reference an issued lot posting from this batch.',
                ]]);
            }
            $quantity = $this->positive($data['quantity'], 'quantity', 'Disposition quantity');
            if ($kind === 'REWORK_IN') {
                $alreadyClassified = $this->decimal(DB::table('production_material_postings')
                    ->where('parent_posting_id', $parent->id)->where('posting_type', 'REWORK_IN')->sum('quantity'));
                $available = bcsub($this->decimal($parent->quantity), $alreadyClassified, 6);
            } else {
                $alreadyClassified = $this->decimal(DB::table('production_material_postings')
                    ->where('parent_posting_id', $parent->id)
                    ->whereIn('posting_type', ['RETURN_QUARANTINE', 'WASTE', 'REWORK_OUT'])->sum('quantity'));
                $available = bcsub($this->decimal($parent->quantity), $alreadyClassified, 6);
            }
            if (bccomp($quantity, $available, 6) > 0) {
                throw ValidationException::withMessages(['quantity' => ['Disposition quantity exceeds the unclassified quantity on the source posting.']]);
            }

            $now = CarbonImmutable::now();
            $postingId = (string) Str::uuid();
            DB::table('production_material_postings')->insert([
                'id' => $postingId, 'production_order_id' => $id,
                'production_order_material_id' => $parent->production_order_material_id,
                'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'posting_type' => $kind, 'quantity' => $quantity, 'uom_code' => $parent->uom_code,
                'lot_id' => $parent->lot_id, 'inventory_owner_id' => $parent->inventory_owner_id,
                'from_position_id' => null, 'to_position_id' => null, 'stock_movement_id' => null,
                'parent_posting_id' => $parent->id, 'quality_status' => $parent->quality_status,
                'status' => 'POSTED', 'reason_code' => Str::upper(trim((string) $data['reason_code'])),
                'disposition_code' => Str::upper(trim((string) $data['disposition_code'])),
                'notes' => $this->nullable($data['notes'] ?? null), 'posted_by' => $data['actor_id'],
                'posted_at' => $now, 'approved_by' => null, 'approved_at' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $aggregateColumn = match ($kind) {
                'WASTE' => 'wasted_quantity',
                'REWORK_OUT' => 'rework_out_quantity',
                'REWORK_IN' => 'rework_in_quantity',
            };
            DB::table('production_order_materials')->where('id', $parent->production_order_material_id)
                ->increment($aggregateColumn, $quantity, ['updated_at' => $now]);
            $this->refreshMaterialReconciliation((string) $parent->production_order_material_id);
            $this->materialReconciliation->persistRecorded($id);
            $version = (int) $order->record_version + 1;
            DB::table('production_orders')->where('id', $id)->update([
                'record_version' => $version, 'updated_at' => $now,
            ]);
            $result = $this->orderResult($id, 'IN_PROCESS', $version) + [
                'posting_id' => $postingId, 'posting_type' => $kind, 'quantity' => $quantity,
                'stock_movement_id' => null, 'ledger_effect' => 'WIP_MATERIAL_POSTING',
            ];
            $this->record('CLASSIFY_PRODUCTION_MATERIAL', 'manufacturing.production-material.classified',
                'production_order', $id, $data, $version, [
                    'material_id' => $parent->production_order_material_id, 'posting_type' => $kind,
                    'quantity' => $quantity, 'parent_posting_id' => $parent->id,
                    'inventory_mutation' => false,
                ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function releaseMaterialReturn(string $postingId, array $data): array
    {
        return DB::transaction(function () use ($postingId, $data): array {
            $namespace = 'manufacturing.production-material-return.release.'.$postingId;
            if ($replay = $this->begin($namespace, $data + ['production_material_posting_id' => $postingId])) {
                return $replay;
            }
            $posting = DB::table('production_material_postings')->where('id', $postingId)
                ->where('company_id', $data['company_id'])->where('plant_id', $data['plant_id'])->lockForUpdate()->first();
            if (! $posting || $posting->posting_type !== 'RETURN_QUARANTINE' || $posting->status !== 'QC_PENDING') {
                throw new NotFoundHttpException('Pending production material return not found.');
            }
            if ($posting->posted_by === $data['actor_id']) {
                throw ValidationException::withMessages(['reviewer' => ['The return recorder cannot approve its own reusable-stock release.']]);
            }
            $order = $this->findOrder((string) $posting->production_order_id, $data, true);
            $this->assertVersion($order, $data['expected_version'], 'production order');
            $source = DB::table('stock_positions')->where('id', $posting->to_position_id)->lockForUpdate()->first();
            $target = DB::table('stock_positions as position')
                ->join('inventory_quality_statuses as quality', 'quality.code', '=', 'position.quality_status')
                ->where('position.id', $data['reusable_position_id'])->where('position.company_id', $data['company_id'])
                ->where('position.plant_id', $data['plant_id'])->where('quality.is_reservable', true)
                ->lockForUpdate()->first(['position.*']);
            if (! $source || ! $target || $source->item_id !== $target->item_id || $source->lot_id !== $target->lot_id
                || $source->inventory_owner_id !== $target->inventory_owner_id || $source->uom_code !== $target->uom_code
                || $source->lot_id !== $posting->lot_id || $source->inventory_owner_id !== $posting->inventory_owner_id) {
                throw ValidationException::withMessages(['reusable_position_id' => ['Released return stock must preserve its original item, lot, owner and UOM.']]);
            }
            $movement = $this->stock->move([
                'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'source_position_id' => $source->id, 'target_position_id' => $target->id,
                'quantity_base' => $this->decimal($posting->quantity), 'uom_code' => $posting->uom_code,
                'expected_item_id' => $source->item_id, 'expected_lot_id' => $posting->lot_id,
                'expected_owner_id' => $posting->inventory_owner_id,
                'expected_source_quality_status' => $source->quality_status,
                'expected_target_quality_status' => $target->quality_status,
                'movement_type' => 'PRODUCTION_RETURN_RELEASE', 'source_type' => 'production_material_return',
                'source_id' => $postingId, 'source_version' => 1, 'actor_id' => $data['actor_id'],
                'reason_code' => 'QC_RETURN_RELEASE', 'idempotency_key' => 'production-return-release:'.$data['idempotency_key'],
                'correlation_id' => $data['correlation_id'] ?? null,
            ]);
            $now = CarbonImmutable::now();
            $releaseId = (string) Str::uuid();
            DB::table('production_material_postings')->insert([
                'id' => $releaseId, 'production_order_id' => $posting->production_order_id,
                'production_order_material_id' => $posting->production_order_material_id,
                'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'posting_type' => 'RETURN_RELEASE',
                'quantity' => $posting->quantity, 'uom_code' => $posting->uom_code, 'lot_id' => $posting->lot_id,
                'inventory_owner_id' => $posting->inventory_owner_id, 'from_position_id' => $source->id,
                'to_position_id' => $target->id, 'stock_movement_id' => $movement['movement_id'],
                'parent_posting_id' => $postingId, 'quality_status' => $target->quality_status, 'status' => 'RELEASED',
                'reason_code' => 'QC_RETURN_RELEASE', 'disposition_code' => 'REUSE',
                'notes' => trim((string) $data['notes']), 'posted_by' => $data['actor_id'], 'posted_at' => $now,
                'approved_by' => $data['actor_id'], 'approved_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('production_material_postings')->where('id', $postingId)->update([
                'status' => 'RELEASED', 'disposition_code' => 'REUSE', 'approved_by' => $data['actor_id'],
                'approved_at' => $now, 'updated_at' => $now,
            ]);
            $version = (int) $order->record_version + 1;
            DB::table('production_orders')->where('id', $order->id)->update(['record_version' => $version, 'updated_at' => $now]);
            $result = $this->orderResult((string) $order->id, (string) $order->status, $version) + [
                'posting_id' => $releaseId, 'stock_movement_id' => $movement['movement_id'], 'return_status' => 'RELEASED'];
            $this->record('RELEASE_PRODUCTION_MATERIAL_RETURN', 'manufacturing.production-material.return-released',
                'production_order', (string) $order->id, $data, $version, ['return_posting_id' => $postingId,
                    'quantity' => $this->decimal($posting->quantity), 'reviewer_separated' => true], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
            return $result;
        }, 3);
    }

    private function refreshMaterialReconciliation(string $materialId): void
    {
        $material = DB::table('production_order_materials')->where('id', $materialId)->lockForUpdate()->first();
        if (! $material) {
            return;
        }
        $net = bcsub(
            bcadd(
                bcadd($this->decimal($material->issued_quantity), $this->decimal($material->additional_issued_quantity), 6),
                $this->decimal($material->rework_in_quantity),
                6,
            ),
            bcadd($this->decimal($material->returned_quantity), $this->decimal($material->rework_out_quantity), 6),
            6,
        );
        $original = $this->decimal($material->original_plan_quantity ?? $material->required_quantity);
        $normalised = $this->decimal($material->normalised_standard_quantity ?? $material->required_quantity);
        DB::table('production_order_materials')->where('id', $materialId)->update([
            'net_consumed_quantity' => $net,
            'original_plan_variance' => bcsub($net, $original, 6),
            'efficiency_variance' => bcsub($net, $normalised, 6),
            'updated_at' => CarbonImmutable::now(),
        ]);
    }

    /** @return array{posting_id:string,movement_id:string,issue_id:?string} */
    private function postMaterialIssue(
        object $order,
        object $material,
        object $position,
        string $quantity,
        string $postingType,
        string $reasonCode,
        array $data,
        CarbonImmutable $now,
        ?string $planningReservationId,
        ?string $stockReservationId,
    ): array {
        $postingId = (string) Str::uuid();
        $movement = $this->stock->issue([
            'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
            'source_position_id' => $position->position_id, 'quantity_base' => $quantity,
            'uom_code' => $position->uom_code, 'expected_item_id' => $material->component_sku_id,
            'expected_lot_id' => $position->lot_id, 'expected_owner_id' => $position->inventory_owner_id,
            'expected_quality_status' => $position->quality_status, 'movement_type' => 'PRODUCTION_ISSUE',
            'source_type' => 'production_order', 'source_id' => $order->id,
            'source_version' => (int) $order->record_version, 'actor_id' => $data['actor_id'],
            'reason_code' => $reasonCode, 'idempotency_key' => 'production-material-posting:'.$postingId,
            'correlation_id' => $data['correlation_id'] ?? null,
        ]);
        $issueId = null;
        if ($planningReservationId !== null && $stockReservationId !== null) {
            $issueId = (string) Str::uuid();
            DB::table('production_material_issues')->insert([
                'id' => $issueId, 'production_order_id' => $order->id,
                'production_order_material_id' => $material->id,
                'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'production_material_reservation_id' => $planningReservationId,
                'stock_reservation_id' => $stockReservationId, 'source_position_id' => $position->position_id,
                'input_lot_id' => $position->lot_id, 'stock_movement_id' => $movement['movement_id'],
                'quantity' => $quantity, 'uom_code' => $position->uom_code,
                'issued_by' => $data['actor_id'], 'issued_at' => $now, 'created_at' => $now,
            ]);
        }
        DB::table('production_material_postings')->insert([
            'id' => $postingId, 'production_order_id' => $order->id,
            'production_order_material_id' => $material->id,
            'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
            'posting_type' => $postingType, 'quantity' => $quantity, 'uom_code' => $position->uom_code,
            'lot_id' => $position->lot_id, 'inventory_owner_id' => $position->inventory_owner_id,
            'from_position_id' => $position->position_id, 'to_position_id' => null,
            'stock_movement_id' => $movement['movement_id'], 'parent_posting_id' => null,
            'quality_status' => $position->quality_status, 'status' => 'POSTED',
            'reason_code' => $reasonCode, 'disposition_code' => null,
            'notes' => $this->nullable($data['notes'] ?? null), 'posted_by' => $data['actor_id'], 'posted_at' => $now,
            'approved_by' => null, 'approved_at' => null, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return ['posting_id' => $postingId, 'movement_id' => $movement['movement_id'], 'issue_id' => $issueId];
    }

    private function findOrder(string $id, array $scope, bool $lock = false): object
    {
        $query = DB::table('production_orders')->where('id', $id)
            ->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id']);
        $order = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $order) {
            throw new NotFoundHttpException('Production order not found.');
        }

        return $order;
    }

    private function findStage(string $id, array $scope, bool $lock = false): object
    {
        $query = DB::table('production_order_stages as stage')
            ->join('production_orders as order', 'order.id', '=', 'stage.production_order_id')
            ->where('stage.id', $id)->where('stage.company_id', $scope['company_id'])
            ->where('stage.plant_id', $scope['plant_id']);
        $stage = ($lock ? $query->lockForUpdate() : $query)->first([
            'stage.*', 'order.status as order_status', 'order.order_number',
        ]);
        if (! $stage) {
            throw new NotFoundHttpException('Production stage not found.');
        }

        return $stage;
    }

    private function assertUniqueNumber(string $column, string $number, array $scope): void
    {
        if (DB::table('production_orders')->where('company_id', $scope['company_id'])
            ->where('plant_id', $scope['plant_id'])->where($column, $number)->exists()) {
            throw ValidationException::withMessages([$column => ['That production identifier already exists in the selected plant.']]);
        }
    }

    private function assertVersion(object $record, int $expected, string $label): void
    {
        if ((int) $record->record_version !== $expected) {
            throw new ConflictHttpException("The {$label} changed from version {$expected} to {$record->record_version}. Refresh it before continuing.");
        }
    }

    private function assertStatus(object $record, array $statuses, string $message): void
    {
        if (! in_array($record->status, $statuses, true)) {
            throw ValidationException::withMessages(['status' => [$message]]);
        }
    }

    private function orderResult(string $id, string $status, int $version): array
    {
        return ['entity_type' => 'production_order', 'id' => $id, 'status' => $status, 'record_version' => $version];
    }

    private function stageResult(string $id, string $orderId, string $status, int $version): array
    {
        return ['entity_type' => 'production_stage', 'id' => $id, 'production_order_id' => $orderId, 'status' => $status, 'record_version' => $version];
    }

    private function record(string $command, string $event, string $entityType, string $id, array $data, int $version, array $safeDiff, array $result): void
    {
        $this->audit->record($command, $entityType, $id, $data['actor_id'], $data['company_id'], $data['plant_id'], 'SUCCESS', [
            'entity_version' => $version, 'correlation_id' => $data['correlation_id'] ?? null, 'safe_diff' => $safeDiff,
        ]);
        $this->outbox->append($event, $entityType, $id, $id.':'.$version, $result + [
            'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
        ], $data['correlation_id'] ?? null, $data['company_id'], $data['plant_id']);
    }

    private function begin(string $namespace, array $data): ?array
    {
        return $this->idempotency->begin($namespace, $data['idempotency_key'], Arr::except($data, [
            'actor_id', 'permissions', 'idempotency_key', 'correlation_id',
        ]));
    }

    private function positive(mixed $value, string $field, string $label): string
    {
        $value = (string) $value;
        if (! preg_match('/^\d{1,14}(?:\.\d{1,6})?$/', $value) || bccomp($value, '0', 6) <= 0) {
            throw ValidationException::withMessages([$field => ["{$label} must be positive with at most 6 decimal places."]]);
        }

        return $this->decimal($value);
    }

    private function nonNegative(mixed $value, string $field, string $label): string
    {
        $value = (string) $value;
        if (! preg_match('/^\d{1,14}(?:\.\d{1,6})?$/', $value)) {
            throw ValidationException::withMessages([$field => ["{$label} must be non-negative with at most 6 decimal places."]]);
        }

        return $this->decimal($value);
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 6);
    }

    private function nullable(mixed $value): ?string
    {
        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? null : $value;
    }
}

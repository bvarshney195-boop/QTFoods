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

final class ManufacturingTraceCostService
{
    public const RECALL_CLASSIFICATIONS = ['CLASS_I', 'CLASS_II', 'CLASS_III', 'WITHDRAWAL'];

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
        private readonly StockPostingService $stock,
        private readonly ManufacturingExecutionService $execution,
        private readonly ManufacturingMaterialReconciliationService $materialReconciliation,
    ) {}

    public function createRecall(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'trace.recall.create';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }
            if (DB::table('recall_cases')->where('company_id', $data['company_id'])->where('plant_id', $data['plant_id'])
                ->where('recall_number', $data['recall_number'])->exists()) {
                throw ValidationException::withMessages(['recall_number' => ['That recall number already exists in the selected plant.']]);
            }
            $source = DB::table('lots')->where('id', $data['source_lot_id'])->where('company_id', $data['company_id'])->first();
            if (! $source) {
                throw ValidationException::withMessages(['source_lot_id' => ['Traceable source lot not found in the selected company.']]);
            }
            if (DB::table('recall_cases')->where('company_id', $data['company_id'])->where('plant_id', $data['plant_id'])
                ->where('source_lot_id', $source->id)->where('status', 'OPEN')->exists()) {
                throw ValidationException::withMessages(['source_lot_id' => ['This source lot already has an open recall case.']]);
            }

            $affected = $this->downstreamLots($source->id, $data);
            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('recall_cases')->insert([
                'id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'recall_number' => $data['recall_number'], 'source_lot_id' => $source->id,
                'classification' => Str::upper($data['classification']), 'reason' => trim($data['reason']),
                'status' => 'OPEN', 'record_version' => 1, 'initiated_at' => $now,
                'initiated_by' => $data['actor_id'], 'created_at' => $now, 'updated_at' => $now,
            ]);

            $movementCount = 0;
            foreach ($affected as $lotId => $depth) {
                $onHand = $this->decimal(DB::table('stock_positions')->where('company_id', $data['company_id'])
                    ->where('plant_id', $data['plant_id'])->where('lot_id', $lotId)->sum('quantity_base'));
                DB::table('recall_case_lots')->insert([
                    'id' => (string) Str::uuid(), 'recall_case_id' => $id,
                    'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'lot_id' => $lotId,
                    'relationship' => $depth === 0 ? 'SOURCE' : 'DOWNSTREAM', 'depth' => $depth,
                    'on_hand_quantity' => $onHand, 'action_status' => 'BLOCKED', 'created_at' => $now,
                ]);
                $movementCount += $this->blockLotPositions($lotId, $id, $data, $now);
                DB::table('lots')->where('id', $lotId)->where('company_id', $data['company_id'])->update([
                    'status' => 'RECALLED', 'record_version' => DB::raw('record_version + 1'),
                    'status_reason' => "Recall {$data['recall_number']}: ".trim($data['reason']),
                    'status_changed_at' => $now, 'status_changed_by' => $data['actor_id'], 'updated_at' => $now,
                ]);
                DB::table('fg_lots')->where('lot_id', $lotId)->where('company_id', $data['company_id'])
                    ->where('plant_id', $data['plant_id'])->update([
                        'status' => 'RECALLED', 'record_version' => DB::raw('record_version + 1'), 'updated_at' => $now,
                    ]);
            }
            $orderIds = DB::table('lot_genealogy_edges')->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])->whereIn('output_lot_id', array_keys($affected))
                ->pluck('production_order_id')->unique();
            if ($orderIds->isNotEmpty()) {
                DB::table('production_orders')->whereIn('id', $orderIds)->update([
                    'quality_status' => 'RECALLED', 'record_version' => DB::raw('record_version + 1'), 'updated_at' => $now,
                ]);
            }

            $result = ['entity_type' => 'recall_case', 'id' => $id, 'status' => 'OPEN', 'record_version' => 1,
                'affected_lot_count' => count($affected), 'blocked_movement_count' => $movementCount];
            $this->record('CREATE_RECALL_CASE', 'trace.recall.created', 'recall_case', $id, $data, 1, [
                'source_lot_id' => $source->id, 'affected_lot_count' => count($affected), 'blocked_movement_count' => $movementCount,
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function closeRecall(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'trace.recall.close.'.$id;
            if ($replay = $this->begin($namespace, $data + ['recall_case_id' => $id])) {
                return $replay;
            }
            $recall = DB::table('recall_cases')->where('id', $id)->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])->lockForUpdate()->first();
            if (! $recall) {
                throw new NotFoundHttpException('Recall case not found.');
            }
            $this->assertVersion($recall, $data['expected_version'], 'recall case');
            if ($recall->status !== 'OPEN') {
                throw ValidationException::withMessages(['status' => ['Only an open recall case can be closed.']]);
            }
            $now = CarbonImmutable::now();
            $version = (int) $recall->record_version + 1;
            DB::table('recall_cases')->where('id', $id)->update([
                'status' => 'CLOSED', 'record_version' => $version, 'closed_at' => $now,
                'closed_by' => $data['actor_id'], 'closure_action' => trim($data['closure_action']), 'updated_at' => $now,
            ]);
            $result = ['entity_type' => 'recall_case', 'id' => $id, 'status' => 'CLOSED', 'record_version' => $version];
            $this->record('CLOSE_RECALL_CASE', 'trace.recall.closed', 'recall_case', $id, $data, $version, [
                'status' => ['from' => 'OPEN', 'to' => 'CLOSED'],
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function calculateBatchCost(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'costing.batch.calculate';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }
            if (DB::table('batch_costs')->where('company_id', $data['company_id'])->where('plant_id', $data['plant_id'])
                ->where('cost_number', $data['cost_number'])->exists()) {
                throw ValidationException::withMessages(['cost_number' => ['That batch-cost number already exists in the selected plant.']]);
            }
            $order = DB::table('production_orders')->where('id', $data['production_order_id'])
                ->where('company_id', $data['company_id'])->where('plant_id', $data['plant_id'])->lockForUpdate()->first();
            if (! $order) {
                throw ValidationException::withMessages(['production_order_id' => ['Production order not found in the selected plant.']]);
            }
            if ($order->status !== 'COMPLETED') {
                throw ValidationException::withMessages(['production_order_id' => ['Batch costing requires a completed production order.']]);
            }
            $materials = DB::table('production_order_materials as material')
                ->join('items as item', 'item.id', '=', 'material.component_sku_id')
                ->where('material.production_order_id', $order->id)->orderBy('material.line_number')
                ->get(['material.*', 'item.code as item_code']);
            $submitted = collect($data['material_costs'])->keyBy('production_order_material_id');
            if ($materials->count() !== $submitted->count() || $materials->contains(fn (object $row) => ! $submitted->has($row->id))) {
                throw ValidationException::withMessages(['material_costs' => ['Submit one unit cost for every production-order material line.']]);
            }
            $stages = DB::table('production_order_stages')->where('production_order_id', $order->id)
                ->orderBy('sequence_no')->get();
            if ($stages->isEmpty() || $stages->contains(fn (object $stage) => $stage->status !== 'COMPLETED' || $stage->actual_minutes === null)) {
                throw new ConflictHttpException('Completed route-stage actuals are required for batch costing.');
            }

            $labourRate = $this->nonNegative($data['labour_rate_per_minute'], 'labour_rate_per_minute');
            $overheadRate = $this->nonNegative($data['overhead_rate_per_minute'], 'overhead_rate_per_minute');
            $conversionRate = bcadd($labourRate, $overheadRate, 6);
            $reconciliation = collect($this->materialReconciliation->forOrder((string) $order->id)['materials'])->keyBy('id');
            $plannedMaterial = $actualMaterial = $plannedConversion = $actualConversion = '0.000000';
            $packagingVarianceQuantity = $packagingVarianceCost = '0.000000';
            $materialLines = [];
            foreach ($materials as $material) {
                $unitCost = $this->nonNegative($submitted->get($material->id)['unit_cost'], "material_costs.{$material->line_number}.unit_cost");
                $originalQuantity = $this->decimal($material->original_plan_quantity ?? $material->required_quantity);
                $reconciled = $reconciliation->get((string) $material->id, []);
                $normalisedQuantity = $this->decimal($reconciled['normalised_standard_quantity']
                    ?? $material->normalised_standard_quantity ?? $material->required_quantity);
                $actualQuantity = $this->decimal($reconciled['adjusted_net_consumed_quantity']
                    ?? $material->net_consumed_quantity ?? $material->issued_quantity);
                $plannedCost = bcmul($originalQuantity, $unitCost, 6);
                $normalisedCost = bcmul($normalisedQuantity, $unitCost, 6);
                $actualCost = bcmul($actualQuantity, $unitCost, 6);
                $originalQuantityVariance = bcsub($actualQuantity, $originalQuantity, 6);
                $efficiencyQuantityVariance = bcsub($actualQuantity, $normalisedQuantity, 6);
                $originalCostVariance = bcsub($actualCost, $plannedCost, 6);
                $efficiencyCostVariance = bcsub($actualCost, $normalisedCost, 6);
                $materialLines[] = [$material, $unitCost, $originalQuantity, $normalisedQuantity, $actualQuantity,
                    $plannedCost, $actualCost, $originalQuantityVariance, $efficiencyQuantityVariance,
                    $originalCostVariance, $efficiencyCostVariance];
                $plannedMaterial = bcadd($plannedMaterial, $plannedCost, 6);
                $actualMaterial = bcadd($actualMaterial, $actualCost, 6);
                if ((bool) ($material->is_packaging ?? false)) {
                    $packagingVarianceQuantity = bcadd($packagingVarianceQuantity, $efficiencyQuantityVariance, 6);
                    $packagingVarianceCost = bcadd($packagingVarianceCost, $efficiencyCostVariance, 6);
                }
            }
            $stageLines = [];
            foreach ($stages as $stage) {
                $plannedCost = bcmul($this->decimal($stage->planned_minutes), $conversionRate, 6);
                $actualCost = bcmul($this->decimal($stage->actual_minutes), $conversionRate, 6);
                $stageLines[] = [$stage, $plannedCost, $actualCost];
                $plannedConversion = bcadd($plannedConversion, $plannedCost, 6);
                $actualConversion = bcadd($actualConversion, $actualCost, 6);
            }
            $plannedTotal = bcadd($plannedMaterial, $plannedConversion, 6);
            $actualTotal = bcadd($actualMaterial, $actualConversion, 6);
            $variance = bcsub($actualTotal, $plannedTotal, 6);
            $variancePercent = bccomp($plannedTotal, '0', 6) === 0 ? '0.000000'
                : bcmul(bcdiv($variance, $plannedTotal, 8), '100', 6);
            $metrics = $this->execution->outputMetrics($order->id);
            $good = $metrics['good_quantity'];
            if (bccomp($good, '0', 6) <= 0) {
                throw new ConflictHttpException('A positive final good quantity is required before per-unit batch cost can be finalized.');
            }
            $yieldPercent = bcmul(bcdiv($good, $this->decimal($order->planned_quantity), 8), '100', 6);
            $costPerGood = bcdiv($actualTotal, $good, 6);
            $netMaterialCostPerGood = bcdiv($actualMaterial, $good, 6);
            $allSourceCostsIncluded = (bool) ($data['all_source_costs_included'] ?? false);
            $fullManufacturingCostPerGood = $allSourceCostsIncluded ? $costPerGood : null;
            $normalLoss = bcadd($metrics['normal_process_loss_quantity'], $metrics['handling_waste_quantity'], 6);
            $abnormalLoss = bcadd(bcadd($metrics['rejected_output_quantity'], $metrics['unexplained_variance_quantity'], 6),
                $metrics['scrapped_rework_quantity'] ?? '0.000000', 6);
            $reworkQuantity = $this->decimal($metrics['rework_quantity']);
            $reworkValue = bcmul($reworkQuantity, $netMaterialCostPerGood, 6);
            $giveawayQuantity = $this->decimal($metrics['overweight_giveaway_quantity']);
            $inputMass = $this->decimal($order->theoretical_input_mass ?? 0);
            $giveawayCost = bccomp($inputMass, '0', 6) > 0
                ? bcmul($giveawayQuantity, bcdiv($actualMaterial, $inputMass, 8), 6) : '0.000000';
            $snapshot = (int) DB::table('batch_costs')->where('production_order_id', $order->id)->max('snapshot_version') + 1;
            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('batch_costs')->insert([
                'id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'cost_number' => $data['cost_number'], 'production_order_id' => $order->id,
                'snapshot_version' => $snapshot, 'currency' => 'INR',
                'labour_rate_per_minute' => $labourRate, 'overhead_rate_per_minute' => $overheadRate,
                'planned_material_cost' => $plannedMaterial, 'actual_material_cost' => $actualMaterial,
                'planned_conversion_cost' => $plannedConversion, 'actual_conversion_cost' => $actualConversion,
                'planned_total_cost' => $plannedTotal, 'actual_total_cost' => $actualTotal,
                'total_variance' => $variance, 'variance_percent' => $variancePercent,
                'good_quantity' => $good, 'yield_percent' => $yieldPercent, 'cost_per_good_unit' => $costPerGood,
                'expected_good_quantity' => $metrics['expected_good_quantity'],
                'output_variance' => $metrics['output_variance'],
                'output_variance_percent' => $metrics['output_variance_percent'],
                'normal_loss_quantity' => $normalLoss, 'abnormal_loss_quantity' => $abnormalLoss,
                'rework_quantity' => $reworkQuantity, 'rework_value' => $reworkValue,
                'overweight_giveaway_quantity' => $giveawayQuantity, 'overweight_giveaway_cost' => $giveawayCost,
                'packaging_variance_quantity' => $packagingVarianceQuantity,
                'packaging_variance_cost' => $packagingVarianceCost,
                'net_material_cost_per_good_unit' => $netMaterialCostPerGood,
                'all_source_costs_included' => $allSourceCostsIncluded,
                'full_manufacturing_cost_per_good_unit' => $fullManufacturingCostPerGood,
                'status' => 'FINALIZED', 'calculated_by' => $data['actor_id'], 'calculated_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($materialLines as [$material, $unitCost, $originalQuantity, $normalisedQuantity, $actualQuantity,
                $plannedCost, $actualCost, $originalQuantityVariance, $efficiencyQuantityVariance,
                $originalCostVariance, $efficiencyCostVariance]) {
                DB::table('batch_cost_material_lines')->insert([
                    'id' => (string) Str::uuid(), 'batch_cost_id' => $id,
                    'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                    'production_order_material_id' => $material->id, 'component_sku_id' => $material->component_sku_id,
                    'planned_quantity' => $originalQuantity, 'actual_quantity' => $actualQuantity,
                    'uom_code' => $material->uom_code, 'unit_cost' => $unitCost,
                    'planned_cost' => $plannedCost, 'actual_cost' => $actualCost,
                    'usage_variance' => $originalCostVariance, 'is_packaging' => (bool) ($material->is_packaging ?? false),
                    'normalised_standard_quantity' => $normalisedQuantity,
                    'original_plan_quantity_variance' => $originalQuantityVariance,
                    'efficiency_quantity_variance' => $efficiencyQuantityVariance,
                    'original_plan_cost_variance' => $originalCostVariance,
                    'efficiency_cost_variance' => $efficiencyCostVariance, 'created_at' => $now,
                ]);
            }
            foreach ($stageLines as [$stage, $plannedCost, $actualCost]) {
                DB::table('batch_cost_stage_lines')->insert([
                    'id' => (string) Str::uuid(), 'batch_cost_id' => $id,
                    'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                    'production_order_stage_id' => $stage->id, 'work_center_code' => $stage->work_center_code,
                    'planned_minutes' => $stage->planned_minutes, 'actual_minutes' => $stage->actual_minutes,
                    'planned_cost' => $plannedCost, 'actual_cost' => $actualCost,
                    'time_variance' => bcsub($actualCost, $plannedCost, 6), 'created_at' => $now,
                ]);
            }
            $result = ['entity_type' => 'batch_cost', 'id' => $id, 'status' => 'FINALIZED',
                'snapshot_version' => $snapshot, 'planned_total_cost' => $plannedTotal,
                'actual_total_cost' => $actualTotal, 'total_variance' => $variance,
                'variance_percent' => $variancePercent, 'cost_per_good_unit' => $costPerGood,
                'net_material_cost_per_good_unit' => $netMaterialCostPerGood,
                'full_manufacturing_cost_per_good_unit' => $fullManufacturingCostPerGood];
            $this->record('CALCULATE_BATCH_COST', 'costing.batch.finalized', 'batch_cost', $id, $data, $snapshot, [
                'production_order_id' => $order->id, 'planned_total_cost' => $plannedTotal,
                'actual_total_cost' => $actualTotal, 'total_variance' => $variance,
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function downstreamLots(string $sourceLotId, array $scope): array
    {
        $depths = [$sourceLotId => 0];
        $frontier = [$sourceLotId];
        while ($frontier !== [] && count($depths) < 1000) {
            $edges = DB::table('lot_genealogy_edges')->where('company_id', $scope['company_id'])
                ->where('plant_id', $scope['plant_id'])->whereIn('input_lot_id', $frontier)
                ->get(['input_lot_id', 'output_lot_id']);
            $next = [];
            foreach ($edges as $edge) {
                if (array_key_exists($edge->output_lot_id, $depths)) {
                    continue;
                }
                $depths[$edge->output_lot_id] = $depths[$edge->input_lot_id] + 1;
                $next[] = $edge->output_lot_id;
            }
            $frontier = $next;
        }

        return $depths;
    }

    private function blockLotPositions(string $lotId, string $recallId, array $data, CarbonImmutable $now): int
    {
        $positions = DB::table('stock_positions')->where('company_id', $data['company_id'])
            ->where('plant_id', $data['plant_id'])->where('lot_id', $lotId)
            ->where('quality_status', '<>', 'BLOCKED')->orderBy('id')->lockForUpdate()->get();
        $movements = 0;
        foreach ($positions as $source) {
            $reservations = DB::table('stock_reservations')->where('stock_position_id', $source->id)
                ->where('status', 'ACTIVE')->orderBy('id')->lockForUpdate()->get();
            $released = '0.000000';
            foreach ($reservations as $reservation) {
                $released = bcadd($released, $this->decimal($reservation->quantity_base), 6);
                DB::table('stock_reservations')->where('id', $reservation->id)->update([
                    'status' => 'RELEASED', 'record_version' => (int) $reservation->record_version + 1,
                    'released_at' => $now, 'released_by' => $data['actor_id'],
                    'release_reason' => 'Released by recall containment.', 'updated_at' => $now,
                ]);
            }
            if (bccomp($released, '0', 6) > 0) {
                DB::table('stock_positions')->where('id', $source->id)->update([
                    'reserved_quantity_base' => bcsub($this->decimal($source->reserved_quantity_base), $released, 6),
                    'record_version' => (int) $source->record_version + 1, 'updated_at' => $now,
                ]);
            }
            $source = DB::table('stock_positions')->where('id', $source->id)->lockForUpdate()->first();
            if (! $source || bccomp($this->decimal($source->quantity_base), '0', 6) <= 0) {
                continue;
            }
            $target = DB::table('stock_positions')->where('company_id', $source->company_id)
                ->where('plant_id', $source->plant_id)->where('item_id', $source->item_id)
                ->where('lot_id', $source->lot_id)->where('inventory_owner_id', $source->inventory_owner_id)
                ->where('location_id', $source->location_id)->where('quality_status', 'BLOCKED')
                ->where('uom_code', $source->uom_code)->lockForUpdate()->first();
            if (! $target) {
                $targetId = (string) Str::uuid();
                DB::table('stock_positions')->insert([
                    'id' => $targetId, 'company_id' => $source->company_id, 'plant_id' => $source->plant_id,
                    'item_id' => $source->item_id, 'lot_id' => $source->lot_id,
                    'owner_party_id' => $source->owner_party_id, 'inventory_owner_id' => $source->inventory_owner_id,
                    'location_id' => $source->location_id, 'quality_status' => 'BLOCKED',
                    'quantity_base' => '0.000000', 'reserved_quantity_base' => '0.000000',
                    'uom_code' => $source->uom_code, 'record_version' => 1,
                    'status_reason' => 'Created for recall containment.', 'status_changed_at' => $now,
                    'status_changed_by' => $data['actor_id'], 'created_at' => $now, 'updated_at' => $now,
                ]);
                $target = DB::table('stock_positions')->where('id', $targetId)->first();
            }
            $this->stock->move([
                'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'source_position_id' => $source->id, 'target_position_id' => $target->id,
                'quantity_base' => $this->decimal($source->quantity_base), 'uom_code' => $source->uom_code,
                'expected_item_id' => $source->item_id, 'expected_lot_id' => $source->lot_id,
                'expected_owner_id' => $source->inventory_owner_id,
                'expected_source_quality_status' => $source->quality_status,
                'expected_target_quality_status' => 'BLOCKED', 'movement_type' => 'RECALL_BLOCK',
                'source_type' => 'recall_case', 'source_id' => $recallId, 'source_version' => 1,
                'actor_id' => $data['actor_id'], 'reason_code' => 'RECALL_CONTAINMENT',
                'idempotency_key' => "recall:{$recallId}:position:{$source->id}",
                'correlation_id' => $data['correlation_id'] ?? null,
            ]);
            $movements++;
        }

        return $movements;
    }

    private function assertVersion(object $row, int $expected, string $label): void
    {
        if ((int) $row->record_version !== $expected) {
            throw new ConflictHttpException("The {$label} changed from version {$expected} to {$row->record_version}. Refresh it before continuing.");
        }
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

    private function nonNegative(mixed $value, string $field): string
    {
        $value = (string) $value;
        if (! preg_match('/^\d{1,14}(?:\.\d{1,6})?$/', $value)) {
            throw ValidationException::withMessages([$field => ['Enter a non-negative amount with at most 6 decimal places.']]);
        }

        return $this->decimal($value);
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 6);
    }
}

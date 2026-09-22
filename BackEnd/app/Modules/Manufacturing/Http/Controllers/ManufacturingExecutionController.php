<?php

namespace App\Modules\Manufacturing\Http\Controllers;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use App\Modules\Manufacturing\Application\ManufacturingExecutionQuery;
use App\Modules\Manufacturing\Application\ManufacturingExecutionService;
use App\Modules\Manufacturing\Application\ManufacturingQualityService;
use App\Modules\Manufacturing\Application\ManufacturingTraceCostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ManufacturingExecutionController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly SessionService $sessions,
        private readonly ManufacturingExecutionQuery $query,
        private readonly ManufacturingExecutionService $execution,
        private readonly ManufacturingQualityService $quality,
        private readonly ManufacturingTraceCostService $traceCost,
    ) {}

    public function orderIndex(Request $request): JsonResponse
    {
        return response()->json($this->query->orderWorkspace($this->selectedScope($request, true),
            $request->validate($this->filters(ManufacturingExecutionService::ORDER_STATUSES)), $this->currentPermissions($request)));
    }

    public function orderShow(string $productionOrderId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->orderDetail($productionOrderId,
            $this->selectedScope($request, true), $this->currentPermissions($request))]);
    }

    public function orderCreate(Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'order_number' => $this->numberRules(), 'batch_number' => $this->numberRules(),
            'production_schedule_line_id' => ['required', 'uuid'],
            'planned_start_date' => ['nullable', 'date_format:Y-m-d'],
            'planned_end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:planned_start_date'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        return response()->json(['data' => $this->execution->createOrder($validated + $this->commandContext($request, false))], 201);
    }

    public function orderRelease(string $productionOrderId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        return response()->json(['data' => $this->execution->releaseOrder($productionOrderId, $this->commandContext($request, true))]);
    }

    public function orderIssue(string $productionOrderId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        return response()->json(['data' => $this->execution->issueMaterials($productionOrderId, $this->commandContext($request, true))]);
    }

    public function orderOutput(string $productionOrderId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'event_type' => ['required', 'string', Rule::in(ManufacturingExecutionService::OUTPUT_TYPES)],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'reason_code' => ['nullable', 'string', 'max:80'], 'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        return response()->json(['data' => $this->execution->recordOutput($productionOrderId,
            $validated + $this->commandContext($request, true))], 201);
    }

    public function reworkResolve(string $outputEventId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'disposition' => ['required', 'string', Rule::in(ManufacturingExecutionService::REWORK_DISPOSITIONS)],
            'notes' => ['required', 'string', 'min:3', 'max:4000'],
        ]);
        return response()->json(['data' => $this->execution->resolveRework($outputEventId,
            $validated + $this->commandContext($request, false))]);
    }

    public function orderComplete(string $productionOrderId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        return response()->json(['data' => $this->execution->completeOrder($productionOrderId, $this->commandContext($request, true))]);
    }

    public function orderCancel(string $productionOrderId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return response()->json(['data' => $this->execution->cancelOrder($productionOrderId,
            trim($validated['reason']), $this->commandContext($request, true))]);
    }

    public function stageIndex(Request $request): JsonResponse
    {
        return response()->json($this->query->stageWorkspace($this->selectedScope($request, true),
            $request->validate($this->filters(['PENDING', 'IN_PROGRESS', 'COMPLETED'])), $this->currentPermissions($request)));
    }

    public function stageShow(string $productionStageId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->stageDetail($productionStageId,
            $this->selectedScope($request, true), $this->currentPermissions($request))]);
    }

    public function stageStart(string $productionStageId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        return response()->json(['data' => $this->execution->startStage($productionStageId, $this->commandContext($request, true))]);
    }

    public function stageComplete(string $productionStageId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'actual_minutes' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        return response()->json(['data' => $this->execution->completeStage($productionStageId,
            $validated + $this->commandContext($request, true))]);
    }

    public function lossIndex(Request $request): JsonResponse
    {
        return response()->json($this->query->lossWorkspace($this->selectedScope($request, true),
            $request->validate($this->filters(['IN_PROCESS', 'COMPLETED'])), $this->currentPermissions($request)));
    }

    public function labIndex(Request $request): JsonResponse
    {
        return response()->json($this->query->labWorkspace($this->selectedScope($request, true),
            $request->validate($this->filters(ManufacturingQualityService::SAMPLE_STATUSES)), $this->currentPermissions($request)));
    }

    public function labShow(string $labSampleId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->labDetail($labSampleId,
            $this->selectedScope($request, true), $this->currentPermissions($request))]);
    }

    public function labCreate(Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'sample_number' => $this->numberRules(), 'production_order_id' => ['required', 'uuid'],
            'specification_id' => ['required', 'uuid'], 'sampled_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        return response()->json(['data' => $this->quality->createSample($validated + $this->commandContext($request, false))], 201);
    }

    public function labComplete(string $labSampleId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'results' => ['required', 'array', 'between:1,100'], 'results.*' => ['required', 'array'],
            'results.*.result_id' => ['required', 'uuid', 'distinct'],
            'results.*.numeric_value' => ['nullable', 'numeric'], 'results.*.text_value' => ['nullable', 'string', 'max:255'],
            'results.*.boolean_value' => ['nullable', 'boolean'], 'results.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);
        return response()->json(['data' => $this->quality->completeSample($labSampleId,
            $validated + $this->commandContext($request, true))]);
    }

    public function safetyIndex(Request $request): JsonResponse
    {
        return response()->json($this->query->safetyWorkspace($this->selectedScope($request, true),
            $request->validate($this->filters(['OPEN', 'RESOLVED', 'ACTIVE', 'RELEASED'])), $this->currentPermissions($request)));
    }

    public function safetyShow(string $kind, string $safetyRecordId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->safetyDetail(Str::upper($kind), $safetyRecordId,
            $this->selectedScope($request, true), $this->currentPermissions($request))]);
    }

    public function holdCreate(Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'hold_number' => $this->numberRules(), 'production_order_id' => ['required', 'uuid'],
            'hazard_type' => ['required', 'string', Rule::in(ManufacturingQualityService::HAZARD_TYPES)],
            'reason' => ['required', 'string', 'min:3', 'max:4000'],
        ]);
        return response()->json(['data' => $this->quality->placeHold($validated + $this->commandContext($request, true))], 201);
    }

    public function holdRelease(string $foodSafetyHoldId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $this->normalise($request);
        $validated = $request->validate([
            'disposition' => ['required', 'string', Rule::in(ManufacturingQualityService::DEVIATION_DISPOSITIONS)],
            'root_cause' => ['required', 'string', 'min:3', 'max:4000'],
            'corrective_action' => ['required', 'string', 'min:3', 'max:4000'],
        ]);
        return response()->json(['data' => $this->quality->releaseHold($foodSafetyHoldId,
            $validated + $this->commandContext($request, true))]);
    }

    public function deviationResolve(string $qualityDeviationId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'disposition' => ['required', 'string', Rule::in(ManufacturingQualityService::DEVIATION_DISPOSITIONS)],
            'root_cause' => ['required', 'string', 'min:3', 'max:4000'],
            'corrective_action' => ['required', 'string', 'min:3', 'max:4000'],
        ]);
        return response()->json(['data' => $this->quality->resolveDeviation($qualityDeviationId,
            $validated + $this->commandContext($request, true))]);
    }

    public function qualityRelease(string $productionOrderId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        return response()->json(['data' => $this->quality->releaseBatchQuality($productionOrderId,
            $this->commandContext($request, true))]);
    }

    public function artworkIndex(Request $request): JsonResponse
    {
        return response()->json($this->query->artworkWorkspace($this->selectedScope($request, true),
            $request->validate($this->filters(ManufacturingQualityService::ARTWORK_STATUSES)), $this->currentPermissions($request)));
    }

    public function artworkShow(string $packagingArtworkId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->artworkDetail($packagingArtworkId,
            $this->selectedScope($request, true), $this->currentPermissions($request))]);
    }

    public function artworkCreate(Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate($this->artworkRules() + [
            'output_sku_id' => ['required', 'uuid'], 'artwork_code' => $this->numberRules(),
            'revision' => ['required', 'integer', 'min:1', 'max:999999'],
        ]);
        return response()->json(['data' => $this->quality->createArtwork($validated + $this->commandContext($request, false))], 201);
    }

    public function artworkUpdate(string $packagingArtworkId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate($this->artworkRules() + [
            'output_sku_id' => ['prohibited'], 'artwork_code' => ['prohibited'], 'revision' => ['prohibited'],
        ]);
        return response()->json(['data' => $this->quality->updateArtwork($packagingArtworkId,
            $validated + $this->commandContext($request, true))]);
    }

    public function artworkApprove(string $packagingArtworkId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        return response()->json(['data' => $this->quality->approveArtwork($packagingArtworkId, $this->commandContext($request, true))]);
    }

    public function artworkRetire(string $packagingArtworkId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return response()->json(['data' => $this->quality->retireArtwork($packagingArtworkId,
            trim($validated['reason']), $this->commandContext($request, true))]);
    }

    public function packingIndex(Request $request): JsonResponse
    {
        return response()->json($this->query->packingWorkspace($this->selectedScope($request, true),
            $request->validate($this->filters(ManufacturingQualityService::PACKING_STATUSES)), $this->currentPermissions($request)));
    }

    public function packingShow(string $packingRunId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->packingDetail($packingRunId,
            $this->selectedScope($request, true), $this->currentPermissions($request))]);
    }

    public function packingCreate(Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'run_number' => $this->numberRules(), 'production_order_id' => ['required', 'uuid'],
            'packaging_artwork_id' => ['required', 'uuid'], 'packed_quantity' => ['required', 'numeric', 'gt:0'],
            'finished_lot_code' => $this->numberRules(), 'manufacture_date' => ['required', 'date_format:Y-m-d'],
            'expiry_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:manufacture_date'],
            'target_location_id' => ['required', 'uuid'], 'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        return response()->json(['data' => $this->quality->createPackingRun($validated + $this->commandContext($request, false))], 201);
    }

    public function packingComplete(string $packingRunId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        return response()->json(['data' => $this->quality->completePackingRun($packingRunId, $this->commandContext($request, true))]);
    }

    public function packingCancel(string $packingRunId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return response()->json(['data' => $this->quality->cancelPackingRun($packingRunId,
            trim($validated['reason']), $this->commandContext($request, true))]);
    }

    public function finishedGoodsIndex(Request $request): JsonResponse
    {
        return response()->json($this->query->finishedGoodsWorkspace($this->selectedScope($request, true),
            $request->validate($this->filters(['ACTIVE', 'CLOSED', 'RECALLED']))));
    }

    public function finishedGoodsShow(string $finishedLotId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->finishedGoodsDetail($finishedLotId, $this->selectedScope($request, true))]);
    }

    public function traceIndex(Request $request): JsonResponse
    {
        return response()->json($this->query->traceWorkspace($this->selectedScope($request, true),
            $request->validate($this->filters(['OPEN', 'CLOSED'])), $this->currentPermissions($request)));
    }

    public function traceShow(string $recallCaseId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->recallDetail($recallCaseId,
            $this->selectedScope($request, true), $this->currentPermissions($request))]);
    }

    public function lotTrace(string $lotId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->lotTrace($lotId, $this->selectedScope($request, true))]);
    }

    public function recallCreate(Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'recall_number' => $this->numberRules(), 'source_lot_id' => ['required', 'uuid'],
            'classification' => ['required', 'string', Rule::in(ManufacturingTraceCostService::RECALL_CLASSIFICATIONS)],
            'reason' => ['required', 'string', 'min:3', 'max:4000'],
        ]);
        return response()->json(['data' => $this->traceCost->createRecall($validated + $this->commandContext($request, false))], 201);
    }

    public function recallClose(string $recallCaseId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate(['closure_action' => ['required', 'string', 'min:3', 'max:4000']]);
        return response()->json(['data' => $this->traceCost->closeRecall($recallCaseId,
            $validated + $this->commandContext($request, true))]);
    }

    public function costIndex(Request $request): JsonResponse
    {
        return response()->json($this->query->costWorkspace($this->selectedScope($request, true),
            $request->validate($this->filters(['FINALIZED'])), $this->currentPermissions($request)));
    }

    public function costShow(string $batchCostId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->costDetail($batchCostId, $this->selectedScope($request, true))]);
    }

    public function costCalculate(Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'cost_number' => $this->numberRules(), 'production_order_id' => ['required', 'uuid'],
            'labour_rate_per_minute' => ['required', 'numeric', 'min:0'],
            'overhead_rate_per_minute' => ['required', 'numeric', 'min:0'],
            'material_costs' => ['required', 'array', 'between:1,100'], 'material_costs.*' => ['required', 'array'],
            'material_costs.*.production_order_material_id' => ['required', 'uuid', 'distinct'],
            'material_costs.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);
        return response()->json(['data' => $this->traceCost->calculateBatchCost($validated + $this->commandContext($request, false))], 201);
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }

    private function filters(array $statuses): array
    {
        return ['q' => ['nullable', 'string', 'max:160'], 'status' => ['nullable', 'string', Rule::in($statuses)],
            'sort' => ['nullable', 'string', Rule::in(ManufacturingExecutionQuery::SORTS)],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])], 'page' => ['nullable', 'integer', 'min:1']];
    }

    private function artworkRules(): array
    {
        return ['label_name' => ['required', 'string', 'min:2', 'max:160'],
            'barcode' => ['required', 'string', 'min:4', 'max:80'], 'coding_template' => ['required', 'string', 'min:3', 'max:255'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:4000']];
    }

    private function numberRules(): array
    {
        return ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'];
    }

    private function normalise(Request $request): void
    {
        $input = $request->all();
        foreach (['order_number', 'batch_number', 'event_type', 'reason_code', 'disposition', 'sample_number',
            'hold_number', 'hazard_type', 'artwork_code', 'run_number', 'finished_lot_code', 'recall_number',
            'classification', 'cost_number'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = Str::upper(trim($input[$field]));
            }
        }
        $request->replace($input);
    }
}

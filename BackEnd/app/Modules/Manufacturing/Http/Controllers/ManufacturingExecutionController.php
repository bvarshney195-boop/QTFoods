<?php

namespace App\Modules\Manufacturing\Http\Controllers;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use App\Modules\Manufacturing\Application\ManufacturingAdjustmentService;
use App\Modules\Manufacturing\Application\ManufacturingExecutionQuery;
use App\Modules\Manufacturing\Application\ManufacturingExecutionService;
use App\Modules\Manufacturing\Application\ManufacturingMeasurementService;
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
        private readonly ManufacturingAdjustmentService $adjustments,
        private readonly ManufacturingMeasurementService $measurements,
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
            'shift_code' => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9][A-Za-z0-9 _-]*$/'],
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

    public function orderMaterialIssue(string $productionOrderId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'production_order_material_id' => ['required', 'uuid'],
            'issue_kind' => ['required', 'string', Rule::in(['PLANNED', 'ADDITIONAL'])],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'source_position_id' => ['nullable', 'uuid'],
            'reason_code' => ['nullable', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        return response()->json(['data' => $this->execution->issueMaterial(
            $productionOrderId,
            $validated + $this->commandContext($request, true),
        )], 201);
    }

    public function orderMaterialReturn(string $productionOrderId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'production_material_posting_id' => ['nullable', 'uuid', 'required_without:production_material_issue_id'],
            'production_material_issue_id' => ['nullable', 'uuid', 'required_without:production_material_posting_id'],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'quarantine_position_id' => ['required', 'uuid'],
            'reason_code' => ['required', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        return response()->json(['data' => $this->execution->returnMaterial(
            $productionOrderId,
            $validated + $this->commandContext($request, true),
        )], 201);
    }

    public function orderMaterialDisposition(string $productionOrderId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'parent_posting_id' => ['required', 'uuid'],
            'disposition_kind' => ['required', 'string', Rule::in(ManufacturingExecutionService::MATERIAL_DISPOSITIONS)],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'reason_code' => ['required', 'string', 'max:80'],
            'disposition_code' => ['required', 'string', 'max:80'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        return response()->json(['data' => $this->execution->recordMaterialDisposition(
            $productionOrderId,
            $validated + $this->commandContext($request, true),
        )], 201);
    }

    public function materialReturnRelease(string $materialPostingId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'reusable_position_id' => ['required', 'uuid'],
            'notes' => ['required', 'string', 'min:3', 'max:4000'],
        ]);
        return response()->json(['data' => $this->execution->releaseMaterialReturn(
            $materialPostingId,
            $validated + $this->commandContext($request, true),
        )]);
    }

    public function orderOutput(string $productionOrderId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'event_type' => ['required', 'string', Rule::in(ManufacturingExecutionService::OUTPUT_TYPES)],
            'quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'loss_category' => ['nullable', 'string', Rule::in(ManufacturingExecutionService::LOSS_CATEGORIES)],
            'quantity_dimension' => ['sometimes', 'string', Rule::in(ManufacturingExecutionService::QUANTITY_DIMENSIONS)],
            'value_source' => ['sometimes', 'string', Rule::in(ManufacturingExecutionService::VALUE_SOURCES)],
            'weight_quantity' => ['nullable', 'numeric', 'gt:0', 'max:99999999999999'],
            'weight_uom_code' => ['nullable', 'string', 'max:16'],
            'disposition_code' => ['nullable', 'string', 'max:80'],
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

    public function batchAdjustmentCreate(string $productionOrderId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'adjustment_number' => $this->numberRules(),
            'adjustment_type' => ['required', 'string', Rule::in(ManufacturingAdjustmentService::TYPES)],
            'reason' => ['required', 'string', 'min:3', 'max:4000'],
            'effects' => ['required', 'array', 'between:1,50'],
            'effects.*.metric_code' => ['required', 'string', Rule::in(ManufacturingAdjustmentService::METRICS)],
            'effects.*.subject_id' => ['nullable', 'uuid'],
            'effects.*.signed_quantity' => ['required', 'numeric'],
            'effects.*.uom_code' => ['nullable', 'string', 'max:16'],
            'effects.*.signed_value' => ['required', 'numeric'],
            'effects.*.currency' => ['nullable', 'string', Rule::in(['INR'])],
        ]);

        return response()->json(['data' => $this->adjustments->request(
            $productionOrderId,
            $validated + $this->commandContext($request, true),
        )], 201);
    }

    public function batchAdjustmentReview(string $batchAdjustmentId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'decision' => ['required', 'string', Rule::in(['APPROVED', 'REJECTED'])],
            'review_notes' => ['required', 'string', 'min:3', 'max:4000'],
        ]);

        return response()->json(['data' => $this->adjustments->review(
            $batchAdjustmentId,
            $validated['decision'],
            $validated['review_notes'],
            $this->commandContext($request, true),
        )]);
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

    public function stageMeasurementTemplateCreate(string $productionStageId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'lines' => ['required', 'array', 'between:1,50'],
            'lines.*.measurement_kind' => ['required', 'string', Rule::in(ManufacturingMeasurementService::KINDS)],
            'lines.*.quantity_dimension' => ['required', 'string', Rule::in(ManufacturingMeasurementService::DIMENSIONS)],
            'lines.*.uom_code' => ['required', 'string', 'max:16'],
            'lines.*.is_required' => ['required', 'boolean'],
            'lines.*.default_value_source' => ['required', 'string', Rule::in(ManufacturingMeasurementService::VALUE_SOURCES)],
            'lines.*.measurement_method' => ['nullable', 'string', 'max:120'],
            'lines.*.balance_tolerance' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
        ]);
        return response()->json(['data' => $this->measurements->createTemplateForStage(
            $productionStageId,
            $validated + $this->commandContext($request, false),
        )], 201);
    }

    public function stageMeasurementTemplateApprove(string $measurementTemplateId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        return response()->json(['data' => $this->measurements->approveTemplate(
            $measurementTemplateId,
            $this->commandContext($request, false),
        )]);
    }

    public function stageMeasurementsRecord(string $productionStageId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'measurements' => ['required', 'array', 'between:1,100'],
            'measurements.*.measurement_kind' => ['required', 'string', Rule::in(ManufacturingMeasurementService::KINDS)],
            'measurements.*.quantity_dimension' => ['required', 'string', Rule::in(ManufacturingMeasurementService::DIMENSIONS)],
            'measurements.*.quantity' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'measurements.*.uom_code' => ['required', 'string', 'max:16'],
            'measurements.*.value_source' => ['required', 'string', Rule::in(ManufacturingMeasurementService::VALUE_SOURCES)],
            'measurements.*.measurement_source' => ['required', 'string', 'min:2', 'max:120'],
            'measurements.*.measurement_method' => ['required', 'string', 'min:2', 'max:120'],
            'measurements.*.target_quantity' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'measurements.*.reason_code' => ['nullable', 'string', 'max:80'],
            'measurements.*.disposition_code' => ['nullable', 'string', 'max:80'],
            'measurements.*.notes' => ['nullable', 'string', 'max:2000'],
            'measurements.*.recorded_at' => ['nullable', 'date'],
        ]);
        return response()->json(['data' => $this->measurements->recordMeasurements(
            $productionStageId,
            $validated + $this->commandContext($request, false),
        )], 201);
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
            'target_location_id' => ['required', 'uuid'],
            'actual_weight_samples' => ['sometimes', 'array', 'max:100'],
            'actual_weight_samples.*' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'weight_uom_code' => ['nullable', 'required_with:actual_weight_samples', 'string', 'max:16'],
            'packing_configuration' => ['sometimes', 'array', 'max:50'],
            'notes' => ['nullable', 'string', 'max:4000'],
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
        $filters = $request->validate($this->filters(['FINALIZED']) + [
            'recipe_version' => ['nullable', 'integer', 'min:1'], 'output_sku_id' => ['nullable', 'uuid'],
            'shift_code' => ['nullable', 'string', 'max:40'], 'operator_id' => ['nullable', 'uuid'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);
        return response()->json($this->query->costWorkspace($this->selectedScope($request, true),
            $filters, $this->currentPermissions($request)));
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
            'all_source_costs_included' => ['sometimes', 'boolean'],
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
        foreach (['order_number', 'batch_number', 'event_type', 'issue_kind', 'disposition_kind', 'loss_category', 'measurement_kind', 'quantity_dimension',
            'value_source', 'default_value_source', 'uom_code', 'weight_uom_code', 'reason_code', 'disposition_code', 'disposition', 'sample_number',
            'hold_number', 'hazard_type', 'artwork_code', 'run_number', 'finished_lot_code', 'recall_number',
            'classification', 'cost_number', 'adjustment_number', 'adjustment_type', 'decision'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = Str::upper(trim($input[$field]));
            }
        }
        foreach (['lines', 'measurements', 'effects'] as $collection) {
            if (! is_array($input[$collection] ?? null)) continue;
            foreach ($input[$collection] as &$line) {
                if (! is_array($line)) continue;
                foreach (['measurement_kind', 'quantity_dimension', 'uom_code', 'value_source', 'default_value_source',
                    'reason_code', 'disposition_code', 'metric_code', 'currency'] as $field) {
                    if (is_string($line[$field] ?? null)) $line[$field] = Str::upper(trim($line[$field]));
                }
            }
            unset($line);
        }
        $request->replace($input);
    }
}

<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use App\Modules\Procurement\Application\InboundProcurementQuery;
use App\Modules\Procurement\Application\InboundProcurementService;
use App\Shared\Visibility\OperationalVisibilityScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class InboundProcurementController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly InboundProcurementQuery $query,
        private readonly InboundProcurementService $service,
        private readonly SessionService $sessions,
    ) {}

    public function gates(Request $request): JsonResponse
    {
        return response()->json($this->query->gateWorkspace($this->selectedScope($request, true), $this->filters($request, InboundProcurementService::GATE_STATUSES), $this->currentPermissions($request)));
    }

    public function gate(string $gateEntryId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->gateDetail($gateEntryId, $this->selectedScope($request, true), $this->currentPermissions($request))]);
    }

    public function createGate(Request $request): JsonResponse
    {
        $this->normalise($request, ['gate_entry_number', 'vehicle_number']);
        $validated = $request->validate([
            'gate_entry_number' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'],
            'purchase_order_id' => ['required', 'uuid'], 'vehicle_number' => ['required', 'string', 'max:40'],
            'transporter_name' => ['nullable', 'string', 'max:255'], 'supplier_document_number' => ['nullable', 'string', 'max:120'],
            'arrived_at' => ['required', 'date'], 'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        return response()->json(['data' => $this->service->createGateEntry($validated + $this->commandContext($request, false))], 201);
    }

    public function updateGate(string $gateEntryId, Request $request): JsonResponse
    {
        $this->normalise($request, ['vehicle_number']);
        $validated = $request->validate([
            'vehicle_number' => ['required', 'string', 'max:40'], 'transporter_name' => ['nullable', 'string', 'max:255'],
            'supplier_document_number' => ['nullable', 'string', 'max:120'], 'arrived_at' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        return response()->json(['data' => $this->service->updateGateEntry($gateEntryId, $validated + $this->commandContext($request, true))]);
    }

    public function cancelGate(string $gateEntryId, Request $request): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return response()->json(['data' => $this->service->cancelGateEntry($gateEntryId, trim($validated['reason']), $this->commandContext($request, true))]);
    }

    public function receipts(Request $request): JsonResponse
    {
        return response()->json($this->query->receiptWorkspace($this->selectedScope($request, true), $this->filters($request, InboundProcurementService::RECEIPT_STATUSES), $this->currentPermissions($request)));
    }

    public function receipt(string $receiptId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->receiptDetail($receiptId, $this->selectedScope($request, true), $this->currentPermissions($request))]);
    }

    public function createReceipt(Request $request): JsonResponse
    {
        $this->normalise($request, ['receipt_number']);
        $validated = $request->validate($this->receiptRules(true));
        return response()->json(['data' => $this->service->createReceipt($validated + $this->commandContext($request, false))], 201);
    }

    public function updateReceipt(string $receiptId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $validated = $request->validate($this->receiptRules(false));
        return response()->json(['data' => $this->service->updateReceipt($receiptId, $validated + $this->commandContext($request, true))]);
    }

    public function postReceipt(string $receiptId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->postReceipt($receiptId, $this->commandContext($request, true))]);
    }

    public function cancelReceipt(string $receiptId, Request $request): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return response()->json(['data' => $this->service->cancelReceipt($receiptId, trim($validated['reason']), $this->commandContext($request, true))]);
    }

    public function qualityTasks(Request $request): JsonResponse
    {
        return response()->json($this->query->qualityWorkspace($this->selectedScope($request, true), $this->filters($request, InboundProcurementService::QUALITY_STATUSES), $this->currentPermissions($request)));
    }

    public function qualityTask(string $qualityTaskId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->qualityDetail($qualityTaskId, $this->selectedScope($request, true), $this->currentPermissions($request))]);
    }

    public function completeQuality(string $qualityTaskId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'notes' => ['nullable', 'string', 'max:4000'], 'lines' => ['required', 'array', 'between:1,100'],
            'lines.*.quality_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.accepted_quantity' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'lines.*.rejected_quantity' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'lines.*.rejection_reason' => ['nullable', 'string', 'max:2000'],
        ]);
        return response()->json(['data' => $this->service->completeIncomingQuality($qualityTaskId, $validated + $this->commandContext($request, true))]);
    }

    public function supplierReturns(Request $request): JsonResponse
    {
        return response()->json($this->query->supplierReturnWorkspace($this->selectedScope($request, true), $this->filters($request, InboundProcurementService::RETURN_STATUSES), $this->currentPermissions($request)));
    }

    public function supplierReturn(string $supplierReturnId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->supplierReturnDetail($supplierReturnId, $this->selectedScope($request, true), $this->currentPermissions($request))]);
    }

    public function createSupplierReturn(Request $request): JsonResponse
    {
        $this->normalise($request, ['return_number']);
        $validated = $request->validate($this->supplierReturnRules(true));
        return response()->json(['data' => $this->service->createSupplierReturn($validated + $this->commandContext($request, false))], 201);
    }

    public function updateSupplierReturn(string $supplierReturnId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $validated = $request->validate($this->supplierReturnRules(false));
        return response()->json(['data' => $this->service->updateSupplierReturn($supplierReturnId, $validated + $this->commandContext($request, true))]);
    }

    public function postSupplierReturn(string $supplierReturnId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->postSupplierReturn($supplierReturnId, $this->commandContext($request, true))]);
    }

    public function cancelSupplierReturn(string $supplierReturnId, Request $request): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return response()->json(['data' => $this->service->cancelSupplierReturn($supplierReturnId, trim($validated['reason']), $this->commandContext($request, true))]);
    }

    protected function sessionService(): SessionService { return $this->sessions; }

    private function filters(Request $request, array $statuses): array
    {
        return $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'q' => ['sometimes', 'string', 'max:100'], 'status' => ['sometimes', 'string', Rule::in($statuses)],
            'visibility' => ['sometimes', 'string', Rule::in(OperationalVisibilityScope::FILTERS)],
        ]);
    }

    private function receiptRules(bool $creating): array
    {
        return [
            'receipt_number' => $creating ? ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'] : ['prohibited'],
            'gate_entry_id' => $creating ? ['required', 'uuid'] : ['prohibited'],
            'receipt_date' => ['required', 'date_format:Y-m-d'], 'supplier_document_number' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:4000'], 'lines' => ['required', 'array', 'between:1,100'],
            'lines.*.purchase_order_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.received_quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'lines.*.internal_lot_code' => ['required', 'string', 'max:80', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.\/-]*$/'],
            'lines.*.supplier_lot_code' => ['nullable', 'string', 'max:120'],
            'lines.*.manufacture_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.expiry_date' => ['nullable', 'date_format:Y-m-d'],
            'lines.*.quality_hold_location_id' => ['required', 'uuid'], 'lines.*.released_location_id' => ['required', 'uuid'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function supplierReturnRules(bool $creating): array
    {
        return [
            'return_number' => $creating ? ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'] : ['prohibited'],
            'return_date' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'lines' => ['required', 'array', 'between:1,100'], 'lines.*.quality_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.return_quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'lines.*.reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function normalise(Request $request, array $upper = []): void
    {
        $input = $request->all();
        foreach ($upper as $field) if (is_string($input[$field] ?? null)) $input[$field] = Str::upper(trim($input[$field]));
        foreach (['transporter_name', 'supplier_document_number', 'notes', 'reason'] as $field) if (is_string($input[$field] ?? null)) $input[$field] = trim($input[$field]);
        if (is_array($input['lines'] ?? null)) foreach ($input['lines'] as &$line) {
            if (is_string($line['internal_lot_code'] ?? null)) $line['internal_lot_code'] = Str::upper(trim($line['internal_lot_code']));
            foreach (['supplier_lot_code', 'notes', 'reason', 'rejection_reason'] as $field) if (is_string($line[$field] ?? null)) $line[$field] = trim($line[$field]);
        }
        unset($line);
        $request->replace($input);
    }
}

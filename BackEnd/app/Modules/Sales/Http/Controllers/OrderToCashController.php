<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use App\Modules\Sales\Application\OrderToCashQuery;
use App\Modules\Sales\Application\OrderToCashService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class OrderToCashController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly OrderToCashQuery $query,
        private readonly OrderToCashService $service,
        private readonly SessionService $sessions,
    ) {}

    public function leads(Request $request): JsonResponse { return $this->workspace($request, 'leads', OrderToCashService::LEAD_STATUSES); }
    public function pricing(Request $request): JsonResponse { return $this->workspace($request, 'pricing', OrderToCashService::PRICE_STATUSES); }
    public function orders(Request $request): JsonResponse { return $this->workspace($request, 'orders', OrderToCashService::ORDER_STATUSES); }
    public function work(Request $request): JsonResponse { return $this->workspace($request, 'thirdPartyWork', OrderToCashService::WORK_STATUSES); }
    public function allocations(Request $request): JsonResponse { return $this->workspace($request, 'allocations', OrderToCashService::ALLOCATION_STATUSES); }
    public function shipments(Request $request): JsonResponse { return $this->workspace($request, 'shipments', OrderToCashService::SHIPMENT_STATUSES); }
    public function pods(Request $request): JsonResponse { return $this->workspace($request, 'deliveryProofs', ['DISPATCHED', 'DELIVERED', 'FAILED']); }
    public function claims(Request $request): JsonResponse { return $this->workspace($request, 'claims', OrderToCashService::CLAIM_STATUSES); }
    public function receivables(Request $request): JsonResponse { return $this->workspace($request, 'receivables', []); }

    public function profitability(Request $request): JsonResponse
    {
        $filters = $request->validate(['q' => ['sometimes', 'string', 'max:100'], 'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:date_from']]);
        return response()->json($this->query->profitability($this->selectedScope($request, true), $filters));
    }

    public function lead(string $leadId, Request $request): JsonResponse { return $this->show('lead', $leadId, $request); }
    public function priceList(string $priceListId, Request $request): JsonResponse { return $this->show('price-list', $priceListId, $request); }
    public function contract(string $contractId, Request $request): JsonResponse { return $this->show('contract', $contractId, $request); }
    public function order(string $orderId, Request $request): JsonResponse { return $this->show('order', $orderId, $request); }
    public function workDetail(string $workId, Request $request): JsonResponse { return $this->show('work', $workId, $request); }
    public function allocation(string $allocationId, Request $request): JsonResponse { return $this->show('allocation', $allocationId, $request); }
    public function shipment(string $shipmentId, Request $request): JsonResponse { return $this->show('shipment', $shipmentId, $request); }
    public function claim(string $claimId, Request $request): JsonResponse { return $this->show('claim', $claimId, $request); }
    public function receivable(string $invoiceId, Request $request): JsonResponse { return $this->show('receivable', $invoiceId, $request); }

    public function createLead(Request $request): JsonResponse
    {
        $this->normalise($request, ['lead_number', 'source']);
        $validated = $request->validate($this->leadRules());
        return response()->json(['data' => $this->service->createLead($validated + $this->commandContext($request, false))], 201);
    }

    public function updateLead(string $leadId, Request $request): JsonResponse
    {
        $this->normalise($request, ['source']);
        $validated = $request->validate($this->leadRules(false));
        return response()->json(['data' => $this->service->updateLead($leadId, $validated + $this->commandContext($request, true))]);
    }

    public function qualifyLead(string $leadId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->qualifyLead($leadId, $this->commandContext($request, true))]);
    }

    public function convertLead(string $leadId, Request $request): JsonResponse
    {
        if (! $request->filled('conversion_path') && $request->filled('customer_party_id')) {
            $request->merge(['conversion_path' => 'EXISTING_CUSTOMER']);
        }
        $this->normalise($request, ['conversion_path', 'customer_code']);
        $validated = $request->validate([
            'conversion_path' => ['required', Rule::in(['EXISTING_CUSTOMER', 'CREATE_CUSTOMER'])],
            'customer_party_id' => ['nullable', 'uuid', 'required_if:conversion_path,EXISTING_CUSTOMER'],
            'customer_code' => ['nullable', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/', 'required_if:conversion_path,CREATE_CUSTOMER'],
            'customer_name' => ['nullable', 'string', 'max:255', 'required_if:conversion_path,CREATE_CUSTOMER'],
            'contact_name' => ['nullable', 'string', 'max:160', 'required_if:conversion_path,CREATE_CUSTOMER'],
            'contact_email' => ['nullable', 'email', 'max:255'], 'contact_phone' => ['nullable', 'string', 'max:40'],
        ]);
        return response()->json(['data' => $this->service->convertLead($leadId, $validated + $this->commandContext($request, true))]);
    }

    public function closeLead(string $leadId, Request $request): JsonResponse
    {
        $this->normalise($request, ['outcome']);
        $validated = $request->validate(['outcome' => ['required', Rule::in(['WON', 'LOST'])], 'reason' => ['nullable', 'string', 'max:2000']]);
        return response()->json(['data' => $this->service->closeLead($leadId, $validated['outcome'], $validated['reason'] ?? null, $this->commandContext($request, true))]);
    }

    public function createPriceList(Request $request): JsonResponse
    {
        $this->normalise($request, ['list_number']);
        $validated = $request->validate($this->priceRules());
        return response()->json(['data' => $this->service->createPriceList($validated + $this->commandContext($request, false))], 201);
    }

    public function updatePriceList(string $priceListId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $validated = $request->validate($this->priceRules(false));
        return response()->json(['data' => $this->service->updatePriceList($priceListId, $validated + $this->commandContext($request, true))]);
    }

    public function activatePriceList(string $priceListId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->activatePriceList($priceListId, $this->commandContext($request, true))]);
    }

    public function retirePriceList(string $priceListId, Request $request): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return response()->json(['data' => $this->service->retirePriceList($priceListId, trim($validated['reason']), $this->commandContext($request, true))]);
    }

    public function upsertCredit(Request $request): JsonResponse
    {
        $validated = $request->validate(['customer_party_id' => ['required', 'uuid'], 'credit_limit' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'payment_terms_days' => ['required', 'integer', 'between:0,3650'], 'is_on_hold' => ['required', 'boolean'],
            'hold_reason' => ['nullable', 'string', 'max:2000'], 'record_version' => ['nullable', 'integer', 'min:1']]);
        return response()->json(['data' => $this->service->upsertCreditProfile($validated + $this->commandContext($request, false))]);
    }

    public function createContract(Request $request): JsonResponse
    {
        $this->normalise($request, ['contract_number']);
        $validated = $request->validate($this->contractRules());
        return response()->json(['data' => $this->service->createContract($validated + $this->commandContext($request, false))], 201);
    }

    public function updateContract(string $contractId, Request $request): JsonResponse
    {
        $validated = $request->validate($this->contractRules(false));
        return response()->json(['data' => $this->service->updateContract($contractId, $validated + $this->commandContext($request, true))]);
    }

    public function activateContract(string $contractId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->activateContract($contractId, $this->commandContext($request, true))]);
    }

    public function closeContract(string $contractId, Request $request): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return response()->json(['data' => $this->service->closeContract($contractId, trim($validated['reason']), $this->commandContext($request, true))]);
    }

    public function createOrder(Request $request): JsonResponse
    {
        $this->normalise($request, ['order_number']); $validated = $request->validate($this->orderRules());
        return response()->json(['data' => $this->service->createOrder($validated + $this->commandContext($request, false))], 201);
    }

    public function updateOrder(string $orderId, Request $request): JsonResponse
    {
        $validated = $request->validate($this->orderRules(false));
        return response()->json(['data' => $this->service->updateOrder($orderId, $validated + $this->commandContext($request, true))]);
    }

    public function confirmOrder(string $orderId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->confirmOrder($orderId, $this->commandContext($request, true))]);
    }

    public function amendOrder(string $orderId, Request $request): JsonResponse
    {
        $validated = $request->validate($this->orderRules(false) + ['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        $reason = $validated['reason']; unset($validated['reason']);
        return response()->json(['data' => $this->service->amendOrder($orderId, $reason, $validated + $this->commandContext($request, true))]);
    }

    public function cancelOrder(string $orderId, Request $request): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return response()->json(['data' => $this->service->cancelOrder($orderId, trim($validated['reason']), $this->commandContext($request, true))]);
    }

    public function createWork(Request $request): JsonResponse
    {
        $this->normalise($request, ['work_number', 'work_type']);
        $validated = $request->validate(['work_number' => $this->numberRules(), 'sales_order_id' => ['nullable', 'uuid'], 'provider_party_id' => ['required', 'uuid'],
            'work_type' => ['required', 'string', 'max:40'], 'description' => ['required', 'string', 'min:3', 'max:4000'],
            'expected_start_date' => ['required', 'date_format:Y-m-d'], 'expected_end_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:expected_start_date'],
            'agreed_cost' => ['required', 'numeric', 'min:0', 'max:99999999999999']]);
        return response()->json(['data' => $this->service->createThirdPartyWork($validated + $this->commandContext($request, false))], 201);
    }

    public function releaseWork(string $workId, Request $request): JsonResponse { return response()->json(['data' => $this->service->releaseThirdPartyWork($workId, $this->commandContext($request, true))]); }

    public function completeWork(string $workId, Request $request): JsonResponse
    {
        $validated = $request->validate(['actual_cost' => ['required', 'numeric', 'min:0', 'max:99999999999999']]);
        return response()->json(['data' => $this->service->completeThirdPartyWork($workId, $validated['actual_cost'], $this->commandContext($request, true))]);
    }

    public function cancelWork(string $workId, Request $request): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return response()->json(['data' => $this->service->cancelThirdPartyWork($workId, trim($validated['reason']), $this->commandContext($request, true))]);
    }

    public function allocateOrder(string $orderId, Request $request): JsonResponse
    {
        $this->normalise($request, ['allocation_number']);
        $validated = $request->validate(['allocation_number' => $this->numberRules(), 'lines' => ['nullable', 'array', 'between:1,100'],
            'lines.*.sales_order_line_id' => ['required', 'uuid', 'distinct'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999']]);
        return response()->json(['data' => $this->service->allocateOrder($orderId, $validated + $this->commandContext($request, true))], 201);
    }

    public function pickAllocation(string $allocationId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->pickAllocation($allocationId, $this->commandContext($request, true))]);
    }

    public function cancelAllocation(string $allocationId, Request $request): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return response()->json(['data' => $this->service->cancelAllocation($allocationId, trim($validated['reason']), $this->commandContext($request, true))]);
    }

    public function createShipment(Request $request): JsonResponse
    {
        $this->normalise($request, ['shipment_number', 'vehicle_number']);
        $validated = $request->validate(['shipment_number' => $this->numberRules(), 'sales_allocation_id' => ['required', 'uuid'],
            'carrier_name' => ['required', 'string', 'max:160'], 'vehicle_number' => ['required', 'string', 'max:40'],
            'driver_name' => ['required', 'string', 'max:160'], 'notes' => ['nullable', 'string', 'max:4000']]);
        return response()->json(['data' => $this->service->createShipment($validated + $this->commandContext($request, false))], 201);
    }

    public function loadShipment(string $shipmentId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->loadShipment($shipmentId, $this->commandContext($request, true))]);
    }

    public function dispatchShipment(string $shipmentId, Request $request): JsonResponse
    {
        $this->normalise($request, ['invoice_number']);
        $validated = $request->validate(['invoice_number' => $this->numberRules()]);
        return response()->json(['data' => $this->service->dispatchShipment($shipmentId, $validated['invoice_number'], $this->commandContext($request, true))]);
    }

    public function cancelShipment(string $shipmentId, Request $request): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return response()->json(['data' => $this->service->cancelShipment($shipmentId, trim($validated['reason']), $this->commandContext($request, true))]);
    }

    public function completePod(string $shipmentId, Request $request): JsonResponse
    {
        $this->normalise($request, ['proof_number', 'outcome']);
        $validated = $request->validate(['proof_number' => $this->numberRules(), 'outcome' => ['required', Rule::in(['DELIVERED', 'FAILED'])],
            'receiver_name' => ['nullable', 'string', 'max:160'], 'event_at' => ['required', 'date'],
            'failure_reason' => ['nullable', 'string', 'max:160'], 'notes' => ['nullable', 'string', 'max:4000']]);
        return response()->json(['data' => $this->service->completeDelivery($shipmentId, $validated + $this->commandContext($request, true))], 201);
    }

    public function createClaim(Request $request): JsonResponse
    {
        $this->normalise($request, ['claim_number', 'claim_type', 'requested_resolution']);
        $validated = $request->validate(['claim_number' => $this->numberRules(), 'shipment_id' => ['required', 'uuid'],
            'claim_type' => ['required', Rule::in(OrderToCashService::CLAIM_TYPES)], 'requested_resolution' => ['required', Rule::in(OrderToCashService::CLAIM_RESOLUTIONS)],
            'reason' => ['required', 'string', 'min:3', 'max:4000'], 'lines' => ['required', 'array', 'between:1,100'],
            'lines.*.shipment_line_id' => ['required', 'uuid', 'distinct'], 'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999']]);
        return response()->json(['data' => $this->service->createClaim($validated + $this->commandContext($request, false))], 201);
    }

    public function receiveClaim(string $claimId, Request $request): JsonResponse
    {
        $validated = $request->validate(['notes' => ['nullable', 'string', 'max:4000']]);
        return response()->json(['data' => $this->service->receiveClaim($claimId, $validated + $this->commandContext($request, true))]);
    }

    public function resolveClaim(string $claimId, Request $request): JsonResponse
    {
        $this->normalise($request, ['resolution_type']);
        $validated = $request->validate(['resolution_type' => ['required', Rule::in(['CREDIT', 'REPLACEMENT', 'REJECT'])],
            'credit_amount' => ['nullable', 'numeric', 'gt:0', 'max:99999999999999'], 'notes' => ['required', 'string', 'min:3', 'max:4000']]);
        return response()->json(['data' => $this->service->resolveClaim($claimId, $validated + $this->commandContext($request, true))]);
    }

    public function collectReceivable(Request $request): JsonResponse
    {
        $this->normalise($request, ['receipt_number', 'bank_reference', 'payment_method']);
        $validated = $request->validate(['receipt_number' => $this->numberRules(), 'customer_party_id' => ['required', 'uuid'],
            'receipt_date' => ['required', 'date_format:Y-m-d'], 'payment_method' => ['required', Rule::in(['BANK', 'UPI', 'CHEQUE', 'CASH'])],
            'bank_reference' => ['required', 'string', 'max:120', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'],
            'total_amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999'], 'allocations' => ['required', 'array', 'between:1,100'],
            'allocations.*.invoice_id' => ['required', 'uuid', 'distinct'], 'allocations.*.amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999']]);
        return response()->json(['data' => $this->service->collectReceivable($validated + $this->commandContext($request, false))], 201);
    }

    protected function sessionService(): SessionService { return $this->sessions; }

    private function show(string $resource, string $resourceId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->detail($resource, $resourceId, $this->selectedScope($request, true), $this->currentPermissions($request))]);
    }

    private function workspace(Request $request, string $method, array $statuses): JsonResponse
    {
        $rules = ['page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'q' => ['sometimes', 'string', 'max:100'], 'sort' => ['sometimes', Rule::in(OrderToCashQuery::SORTS)]];
        if ($statuses !== []) $rules['status'] = ['sometimes', Rule::in($statuses)];
        $filters = $request->validate($rules);
        return response()->json($this->query->{$method}($this->selectedScope($request, true), $filters, $this->currentPermissions($request)));
    }

    private function leadRules(bool $creating = true): array
    {
        return ['lead_number' => $creating ? $this->numberRules() : ['prohibited'], 'customer_party_id' => ['nullable', 'uuid'],
            'company_name' => ['required', 'string', 'max:160'], 'contact_name' => ['required', 'string', 'max:160'],
            'contact_email' => ['nullable', 'email', 'max:255', 'required_without:contact_phone'],
            'contact_phone' => ['nullable', 'string', 'max:40', 'required_without:contact_email'],
            'source' => ['required', Rule::in(OrderToCashService::LEAD_SOURCES)], 'enquiry_date' => ['required', 'date_format:Y-m-d'],
            'expected_close_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:enquiry_date'],
            'estimated_value' => ['required', 'numeric', 'min:0', 'max:99999999999999'], 'notes' => ['nullable', 'string', 'max:4000']];
    }

    private function priceRules(bool $creating = true): array
    {
        return ['list_number' => $creating ? $this->numberRules() : ['prohibited'], 'name' => ['required', 'string', 'max:160'],
            'effective_from' => ['required', 'date_format:Y-m-d'], 'effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'notes' => ['nullable', 'string', 'max:4000'], 'lines' => ['required', 'array', 'between:1,100'],
            'lines.*.item_id' => ['required', 'uuid'], 'lines.*.uom_code' => ['required', 'string', 'max:16'],
            'lines.*.minimum_quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'lines.*.maximum_discount_percent' => ['required', 'numeric', 'between:0,100'], 'lines.*.tax_rate' => ['required', 'numeric', 'between:0,100']];
    }

    private function contractRules(bool $creating = true): array
    {
        return ['contract_number' => $creating ? $this->numberRules() : ['prohibited'], 'customer_party_id' => ['required', 'uuid'],
            'sales_price_list_id' => ['nullable', 'uuid'], 'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:effective_from'],
            'committed_value' => ['required', 'numeric', 'min:0', 'max:99999999999999'], 'notes' => ['nullable', 'string', 'max:4000'],
            'lines' => ['required', 'array', 'between:1,100'], 'lines.*.item_id' => ['required', 'uuid', 'distinct'],
            'lines.*.uom_code' => ['required', 'string', 'max:16'], 'lines.*.committed_quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999999999'], 'lines.*.tax_rate' => ['required', 'numeric', 'between:0,100']];
    }

    private function orderRules(bool $creating = true): array
    {
        return ['order_number' => $creating ? $this->numberRules() : ['prohibited'], 'customer_party_id' => ['required', 'uuid'],
            'sales_lead_id' => ['nullable', 'uuid'], 'sales_contract_id' => ['nullable', 'uuid'], 'sales_price_list_id' => ['nullable', 'uuid'],
            'order_date' => ['required', 'date_format:Y-m-d'], 'requested_delivery_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:order_date'],
            'notes' => ['nullable', 'string', 'max:4000'], 'lines' => ['required', 'array', 'between:1,100'],
            'lines.*.item_id' => ['required', 'uuid', 'distinct'], 'lines.*.uom_code' => ['required', 'string', 'max:16'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999'], 'lines.*.discount_percent' => ['nullable', 'numeric', 'between:0,100']];
    }

    private function numberRules(): array
    {
        return ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'];
    }

    private function normalise(Request $request, array $upper = []): void
    {
        $input = $request->all();
        foreach ($upper as $field) if (is_string($input[$field] ?? null)) $input[$field] = Str::upper(trim($input[$field]));
        foreach (['company_name', 'contact_name', 'contact_email', 'contact_phone', 'name', 'notes', 'reason', 'description', 'carrier_name', 'driver_name', 'receiver_name', 'failure_reason'] as $field) {
            if (is_string($input[$field] ?? null)) $input[$field] = trim($input[$field]);
        }
        $request->replace($input);
    }
}

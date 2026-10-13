<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Application\AccountsPayableQuery;
use App\Modules\Finance\Application\AccountsPayableService;
use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use App\Shared\Visibility\OperationalVisibilityScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class AccountsPayableController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly AccountsPayableQuery $query,
        private readonly AccountsPayableService $service,
        private readonly SessionService $sessions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'], 'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'q' => ['sometimes', 'string', 'max:100'], 'status' => ['sometimes', 'string', Rule::in(AccountsPayableService::INVOICE_STATUSES)],
            'proposal_status' => ['sometimes', 'string', Rule::in(AccountsPayableService::PROPOSAL_STATUSES)],
            'payment_status' => ['sometimes', 'string', Rule::in(AccountsPayableService::PAYMENT_STATUSES)],
            'visibility' => ['sometimes', 'string', Rule::in(OperationalVisibilityScope::FILTERS)],
        ]);
        return response()->json($this->query->workspace($this->selectedScope($request, true), $filters, $this->currentPermissions($request)));
    }

    public function invoice(string $invoiceId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->invoiceDetail($invoiceId, $this->selectedScope($request, true), $this->currentPermissions($request))]);
    }

    public function createInvoice(Request $request): JsonResponse
    {
        $this->normalise($request, ['ap_number']);
        $validated = $request->validate($this->invoiceRules(true));
        return response()->json(['data' => $this->service->createInvoice($validated + $this->commandContext($request, false))], 201);
    }

    public function updateInvoice(string $invoiceId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $validated = $request->validate($this->invoiceRules(false));
        return response()->json(['data' => $this->service->updateInvoice($invoiceId, $validated + $this->commandContext($request, true))]);
    }

    public function matchInvoice(string $invoiceId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->matchInvoice($invoiceId, $this->commandContext($request, true))]);
    }

    public function approveInvoice(string $invoiceId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->approveInvoice($invoiceId, $this->commandContext($request, true))]);
    }

    public function cancelInvoice(string $invoiceId, Request $request): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return response()->json(['data' => $this->service->cancelInvoice($invoiceId, trim($validated['reason']), $this->commandContext($request, true))]);
    }

    public function proposal(string $proposalId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->proposalDetail($proposalId, $this->selectedScope($request, true), $this->currentPermissions($request))]);
    }

    public function createProposal(Request $request): JsonResponse
    {
        $this->normalise($request, ['proposal_number']);
        $validated = $request->validate($this->proposalRules(true));
        return response()->json(['data' => $this->service->createProposal($validated + $this->commandContext($request, false))], 201);
    }

    public function updateProposal(string $proposalId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $validated = $request->validate($this->proposalRules(false));
        return response()->json(['data' => $this->service->updateProposal($proposalId, $validated + $this->commandContext($request, true))]);
    }

    public function approveProposal(string $proposalId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->approveProposal($proposalId, $this->commandContext($request, true))]);
    }

    public function cancelProposal(string $proposalId, Request $request): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);
        return response()->json(['data' => $this->service->cancelProposal($proposalId, trim($validated['reason']), $this->commandContext($request, true))]);
    }

    public function executeProposal(string $proposalId, Request $request): JsonResponse
    {
        $this->normalise($request, ['payment_number', 'bank_reference']);
        $validated = $request->validate([
            'payment_number' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'],
            'payment_date' => ['required', 'date_format:Y-m-d'],
            'method' => ['required', 'string', Rule::in(AccountsPayableService::PAYMENT_METHODS)],
            'bank_reference' => ['required', 'string', 'max:120', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'],
        ]);
        return response()->json(['data' => $this->service->executeProposal($proposalId, $validated + $this->commandContext($request, true))]);
    }

    public function payment(string $paymentId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->paymentDetail($paymentId, $this->selectedScope($request, true), $this->currentPermissions($request))]);
    }

    public function reconcilePayment(string $paymentId, Request $request): JsonResponse
    {
        $this->normalise($request, ['statement_reference']);
        $validated = $request->validate([
            'statement_date' => ['required', 'date_format:Y-m-d'],
            'statement_reference' => ['required', 'string', 'max:120', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);
        return response()->json(['data' => $this->service->reconcilePayment($paymentId, $validated + $this->commandContext($request, true))]);
    }

    protected function sessionService(): SessionService { return $this->sessions; }

    private function invoiceRules(bool $creating): array
    {
        return [
            'ap_number' => $creating ? ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'] : ['prohibited'],
            'supplier_invoice_number' => $creating ? ['required', 'string', 'max:120'] : ['prohibited'],
            'purchase_order_id' => $creating ? ['required', 'uuid'] : ['prohibited'],
            'invoice_date' => ['required', 'date_format:Y-m-d'], 'due_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:invoice_date'],
            'notes' => ['nullable', 'string', 'max:4000'], 'lines' => ['required', 'array', 'between:1,100'],
            'lines.*.purchase_order_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.invoice_quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'lines.*.invoice_unit_price' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'lines.*.tax_rate' => ['required', 'numeric', 'between:0,100'],
        ];
    }

    private function proposalRules(bool $creating): array
    {
        return [
            'proposal_number' => $creating ? ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'] : ['prohibited'],
            'payment_date' => ['required', 'date_format:Y-m-d'], 'notes' => ['nullable', 'string', 'max:4000'],
            'lines' => ['required', 'array', 'between:1,100'], 'lines.*.invoice_id' => ['required', 'uuid', 'distinct'],
            'lines.*.proposed_amount' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
        ];
    }

    private function normalise(Request $request, array $upper = []): void
    {
        $input = $request->all();
        foreach ($upper as $field) if (is_string($input[$field] ?? null)) $input[$field] = Str::upper(trim($input[$field]));
        foreach (['supplier_invoice_number', 'notes', 'reason'] as $field) if (is_string($input[$field] ?? null)) $input[$field] = trim($input[$field]);
        $request->replace($input);
    }
}

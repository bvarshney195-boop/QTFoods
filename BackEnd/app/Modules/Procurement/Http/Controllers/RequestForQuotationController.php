<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use App\Modules\Procurement\Application\RequestForQuotationQuery;
use App\Modules\Procurement\Application\RequestForQuotationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class RequestForQuotationController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly RequestForQuotationQuery $query,
        private readonly RequestForQuotationService $service,
        private readonly SessionService $sessions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'q' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(RequestForQuotationService::STATUSES)],
            'response_from' => ['sometimes', 'date_format:Y-m-d'],
            'response_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:response_from'],
            'sort' => ['sometimes', 'string', Rule::in(RequestForQuotationQuery::SORTS)],
        ]);

        return response()->json($this->query->workspace(
            $this->selectedScope($request, true),
            $filters,
            $this->currentPermissions($request),
        ));
    }

    public function show(string $rfqId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->detail(
            $rfqId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request),
        )]);
    }

    public function create(Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'rfq_number' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'],
            'requisition_id' => ['required', 'uuid'],
            ...$this->writeRules(),
        ]);

        return response()->json(['data' => $this->service->create(
            $validated + $this->commandContext($request, false),
        )], 201);
    }

    public function update(string $rfqId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'rfq_number' => ['prohibited'],
            'requisition_id' => ['prohibited'],
            ...$this->writeRules(),
        ]);

        return response()->json(['data' => $this->service->update(
            $rfqId,
            $validated + $this->commandContext($request, true),
        )]);
    }

    public function issue(string $rfqId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);

        return response()->json(['data' => $this->service->issue(
            $rfqId,
            $this->commandContext($request, true),
        )]);
    }

    public function recordQuote(string $rfqId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'supplier_party_id' => ['required', 'uuid'],
            'quote_number' => ['required', 'string', 'max:100'],
            'quote_date' => ['required', 'date_format:Y-m-d'],
            'valid_until' => ['required', 'date_format:Y-m-d', 'after_or_equal:quote_date'],
            'promised_delivery_date' => ['required', 'date_format:Y-m-d'],
            'payment_terms_days' => ['required', 'integer', 'between:0,3650'],
            'freight_amount' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'other_charges' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'discount_amount' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'lines' => ['required', 'array', 'between:1,100'],
            'lines.*' => ['required', 'array'],
            'lines.*.rfq_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $this->service->recordQuote(
            $rfqId,
            $validated + $this->commandContext($request, true),
        )]);
    }

    public function award(string $rfqId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'supplier_quote_id' => ['required', 'uuid'],
            'award_reason' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json(['data' => $this->service->award(
            $rfqId,
            $validated + $this->commandContext($request, true),
        )]);
    }

    public function cancel(string $rfqId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        return response()->json(['data' => $this->service->cancel(
            $rfqId,
            trim($validated['reason']),
            $this->commandContext($request, true),
        )]);
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }

    private function writeRules(): array
    {
        return [
            'response_due_date' => ['required', 'date_format:Y-m-d'],
            'commercial_terms' => ['nullable', 'string', 'max:4000'],
            'supplier_ids' => ['required', 'array', 'between:2,50'],
            'supplier_ids.*' => ['required', 'uuid', 'distinct'],
        ];
    }

    private function normalise(Request $request): void
    {
        $input = $request->all();
        foreach (['rfq_number', 'quote_number'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = Str::upper(trim($input[$field]));
            }
        }
        foreach (['commercial_terms', 'notes'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim($input[$field]);
            }
        }
        $request->replace($input);
    }
}

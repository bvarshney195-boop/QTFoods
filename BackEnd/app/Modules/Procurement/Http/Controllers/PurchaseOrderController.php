<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use App\Modules\Procurement\Application\PurchaseOrderQuery;
use App\Modules\Procurement\Application\PurchaseOrderService;
use App\Shared\Visibility\OperationalVisibilityScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class PurchaseOrderController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly PurchaseOrderQuery $query,
        private readonly PurchaseOrderService $service,
        private readonly SessionService $sessions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'q' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(PurchaseOrderService::STATUSES)],
            'supplier_party_id' => ['sometimes', 'uuid'],
            'required_from' => ['sometimes', 'date_format:Y-m-d'],
            'required_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:required_from'],
            'sort' => ['sometimes', 'string', Rule::in(PurchaseOrderQuery::SORTS)],
            'visibility' => ['sometimes', 'string', Rule::in(OperationalVisibilityScope::FILTERS)],
        ]);

        return response()->json($this->query->workspace(
            $this->selectedScope($request, true),
            $filters,
            $this->currentPermissions($request),
        ));
    }

    public function show(string $purchaseOrderId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->detail(
            $purchaseOrderId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request),
        )]);
    }

    public function create(Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'po_number' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'],
            'rfq_id' => ['required', 'uuid'],
            'order_date' => ['required', 'date_format:Y-m-d'],
            'incoterm_code' => ['nullable', 'string', 'max:12', 'regex:/^[A-Z0-9-]+$/'],
            'delivery_terms' => ['nullable', 'string', 'max:4000'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ]);

        return response()->json(['data' => $this->service->create(
            $validated + $this->commandContext($request, false),
        )], 201);
    }

    public function amend(string $purchaseOrderId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
            'required_by_date' => ['required', 'date_format:Y-m-d'],
            'payment_terms_days' => ['required', 'integer', 'between:0,3650'],
            'freight_amount' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'other_charges' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'discount_amount' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'incoterm_code' => ['nullable', 'string', 'max:12', 'regex:/^[A-Z0-9-]+$/'],
            'delivery_terms' => ['nullable', 'string', 'max:4000'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'lines' => ['required', 'array', 'between:1,100'],
            'lines.*' => ['required', 'array'],
            'lines.*.rfq_line_id' => ['required', 'uuid', 'distinct'],
            'lines.*.ordered_quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'lines.*.unit_price' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        return response()->json(['data' => $this->service->amend(
            $purchaseOrderId,
            $validated + $this->commandContext($request, true),
        )]);
    }

    public function issue(string $purchaseOrderId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);

        return response()->json(['data' => $this->service->issue(
            $purchaseOrderId,
            $this->commandContext($request, true),
        )]);
    }

    public function cancel(string $purchaseOrderId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        return response()->json(['data' => $this->service->cancel(
            $purchaseOrderId,
            trim($validated['reason']),
            $this->commandContext($request, true),
        )]);
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }

    private function normalise(Request $request): void
    {
        $input = $request->all();
        foreach (['po_number', 'incoterm_code'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = Str::upper(trim($input[$field]));
            }
        }
        foreach (['reason', 'delivery_terms', 'notes'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim($input[$field]);
            }
        }
        $request->replace($input);
    }
}

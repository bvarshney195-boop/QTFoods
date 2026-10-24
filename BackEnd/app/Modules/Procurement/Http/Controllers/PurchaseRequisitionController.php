<?php

namespace App\Modules\Procurement\Http\Controllers;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Domain\User;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use App\Modules\Procurement\Application\PurchaseRequisitionQuery;
use App\Modules\Procurement\Application\PurchaseRequisitionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class PurchaseRequisitionController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly PurchaseRequisitionQuery $query,
        private readonly PurchaseRequisitionService $service,
        private readonly SessionService $sessions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'q' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(PurchaseRequisitionService::STATUSES)],
            'requested_by' => ['sometimes', 'uuid'],
            'required_from' => ['sometimes', 'date_format:Y-m-d'],
            'required_to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:required_from'],
            'sort' => ['sometimes', 'string', Rule::in(PurchaseRequisitionQuery::SORTS)],
        ]);
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->query->workspace(
            $this->selectedScope($request, true),
            $filters,
            $this->currentPermissions($request),
            (string) $user->id,
        ));
    }

    public function show(string $requisitionId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->query->detail(
            $requisitionId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request),
            (string) $user->id,
        )]);
    }

    public function create(Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'requisition_number' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'],
            ...$this->writeRules(),
        ]);

        return response()->json(['data' => $this->service->create(
            $validated + $this->commandContext($request, false),
        )], 201);
    }

    public function update(string $requisitionId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'requisition_number' => ['prohibited'],
            ...$this->writeRules(),
        ]);

        return response()->json(['data' => $this->service->update(
            $requisitionId,
            $validated + $this->commandContext($request, true),
        )]);
    }

    public function submit(string $requisitionId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);

        return response()->json(['data' => $this->service->submit(
            $requisitionId,
            $this->commandContext($request, true),
        )]);
    }

    public function cancel(string $requisitionId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        return response()->json(['data' => $this->service->cancel(
            $requisitionId,
            trim($validated['reason']),
            $this->commandContext($request, true),
        )]);
    }

    public function approve(string $approvalId, Request $request): JsonResponse
    {
        return $this->decision($approvalId, 'APPROVE', $request);
    }

    public function reject(string $approvalId, Request $request): JsonResponse
    {
        return $this->decision($approvalId, 'REJECT', $request);
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }

    private function decision(string $approvalId, string $decision, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'reason' => [Rule::requiredIf($decision === 'REJECT'), 'nullable', 'string', 'min:3', 'max:2000'],
        ]);

        return response()->json(['data' => $this->service->decide(
            $approvalId,
            $decision,
            $this->commandContext($request, true) + [
                'reason' => isset($validated['reason']) ? trim($validated['reason']) : null,
            ],
        )]);
    }

    private function writeRules(): array
    {
        return [
            'department' => ['required', 'string', 'min:2', 'max:120'],
            'purpose' => ['required', 'string', 'min:3', 'max:4000'],
            'requested_date' => ['required', 'date_format:Y-m-d'],
            'required_by_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:requested_date'],
            'currency' => ['required', 'string', Rule::in(PurchaseRequisitionQuery::CURRENCIES)],
            'lines' => ['required', 'array', 'between:1,100'],
            'lines.*' => ['required', 'array'],
            'lines.*.item_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'lines.*.estimated_unit_cost' => ['required', 'numeric', 'min:0', 'max:99999999999999'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function normalise(Request $request): void
    {
        $input = $request->all();
        foreach (['requisition_number', 'currency'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = Str::upper(trim($input[$field]));
            }
        }
        foreach (['department', 'purpose'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = trim($input[$field]);
            }
        }
        $request->replace($input);
    }
}

<?php

namespace App\Modules\Scale\Http\Controllers;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use App\Modules\Scale\Application\OptimisationQuery;
use App\Modules\Scale\Application\OptimisationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class OptimisationController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly SessionService $sessions,
        private readonly OptimisationQuery $query,
        private readonly OptimisationService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', 'string', Rule::in(OptimisationService::STATUSES)],
            'objective' => ['nullable', 'string', Rule::in(OptimisationService::OBJECTIVES)],
            'sort' => ['nullable', 'string', Rule::in(OptimisationQuery::SORTS)],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($this->query->workspace(
            $this->selectedScope($request, true), $filters, $this->currentPermissions($request),
        ));
    }

    public function show(string $planId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->detail(
            $planId, $this->selectedScope($request, true), $this->currentPermissions($request),
        )]);
    }

    public function create(Request $request): JsonResponse
    {
        $this->normalise($request);
        $validated = $request->validate([
            'plan_number' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'],
            'name' => ['required', 'string', 'min:3', 'max:160'],
            ...$this->inputRules(),
        ]);

        return response()->json(['data' => $this->service->create(
            $validated + $this->commandContext($request, false),
        )], 201);
    }

    public function revise(string $planId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $validated = $request->validate([
            'plan_number' => ['prohibited'], 'name' => ['prohibited'], ...$this->inputRules(),
        ]);

        return response()->json(['data' => $this->service->revise(
            $planId, $validated + $this->commandContext($request, true),
        )]);
    }

    public function generate(string $planId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->generate(
            $planId, $this->commandContext($request, true),
        )]);
    }

    public function submit(string $planId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->submit(
            $planId, $this->commandContext($request, true),
        )]);
    }

    public function approve(string $planId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'decision_notes' => ['required', 'string', 'min:3', 'max:4000'],
        ]);

        return response()->json(['data' => $this->service->decide(
            $planId, 'APPROVED', trim($validated['decision_notes']), $this->commandContext($request, true),
        )]);
    }

    public function reject(string $planId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'decision_notes' => ['required', 'string', 'min:3', 'max:4000'],
        ]);

        return response()->json(['data' => $this->service->decide(
            $planId, 'REJECTED', trim($validated['decision_notes']), $this->commandContext($request, true),
        )]);
    }

    public function outcome(string $planId, string $recommendationId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $validated = $request->validate([
            'result' => ['required', 'string', Rule::in(OptimisationService::OUTCOMES)],
            'actual_stock_quantity' => $this->quantity(),
            'actual_production_quantity' => $this->quantity(),
            'actual_service_level' => ['required', 'numeric', 'gt:0', 'max:100', 'decimal:0,3'],
            'actual_cost' => $this->quantity(),
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'observed_on' => ['required', 'date_format:Y-m-d'],
            'notes' => ['required', 'string', 'min:3', 'max:4000'],
            'expected_outcome_version' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json(['data' => $this->service->recordOutcome(
            $planId, $recommendationId, $validated + $this->commandContext($request, true),
        )]);
    }

    public function complete(string $planId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->completePlan(
            $planId, $this->commandContext($request, true),
        )]);
    }

    public function cancel(string $planId, Request $request): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);

        return response()->json(['data' => $this->service->cancel(
            $planId, trim($validated['reason']), $this->commandContext($request, true),
        )]);
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }

    private function inputRules(): array
    {
        return [
            'demand_plan_id' => ['required', 'uuid'],
            'objective' => ['required', 'string', Rule::in(OptimisationService::OBJECTIVES)],
            'service_level_target' => ['required', 'numeric', 'gt:0', 'max:100', 'decimal:0,3'],
            'safety_stock_percent' => ['required', 'numeric', 'min:0', 'max:100', 'decimal:0,3'],
            'planning_lead_days' => ['required', 'integer', 'between:0,365'],
            'max_utilisation_percent' => ['required', 'numeric', 'gt:0', 'max:100', 'decimal:0,3'],
            'holding_cost_rate' => ['required', 'numeric', 'min:0', 'max:1000000', 'decimal:0,4'],
            'shortage_penalty_rate' => ['required', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,6'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'assumptions' => ['nullable', 'string', 'max:4000'],
        ];
    }

    private function quantity(): array
    {
        return ['required', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,6'];
    }

    private function normalise(Request $request): void
    {
        $input = $request->all();
        foreach (['plan_number', 'objective', 'currency', 'result'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = Str::upper(trim($input[$field]));
            }
        }
        if (is_string($input['name'] ?? null)) {
            $input['name'] = trim($input['name']);
        }
        $request->replace($input);
    }
}

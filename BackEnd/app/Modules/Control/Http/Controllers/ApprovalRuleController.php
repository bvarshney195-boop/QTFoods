<?php

namespace App\Modules\Control\Http\Controllers;

use App\Modules\Control\Application\ApprovalRuleQuery;
use App\Modules\Control\Application\ApprovalRuleService;
use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ApprovalRuleController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly ApprovalRuleQuery $query,
        private readonly ApprovalRuleService $service,
        private readonly SessionService $sessions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->query->workspace(
            $this->selectedScope($request, true),
            $this->currentPermissions($request)
        ));
    }

    public function show(string $ruleId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->rule(
            $ruleId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request)
        )]);
    }

    public function create(Request $request): JsonResponse
    {
        if (is_string($request->input('code'))) {
            $request->merge(['code' => Str::upper(trim((string) $request->input('code')))]);
        }
        $validated = $request->validate($this->ruleRules(true));

        return response()->json([
            'data' => $this->service->createRule(
                $validated + $this->commandContext($request, false)
            ),
        ], 201);
    }

    public function update(string $ruleId, Request $request): JsonResponse
    {
        $validated = $request->validate($this->ruleRules(false));

        return response()->json(['data' => $this->service->updateRule(
            $ruleId,
            $validated + $this->commandContext($request, true)
        )]);
    }

    public function delete(string $ruleId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate(['confirmed' => ['required', 'accepted']]);

        return response()->json(['data' => $this->service->deleteRule(
            $ruleId,
            $validated + $this->commandContext($request, true)
        )]);
    }

    public function delegate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'delegator_id' => ['required', 'uuid'],
            'delegate_id' => ['required', 'uuid', 'different:delegator_id'],
            'permission_code' => ['required', 'string', 'max:128'],
            'effective_from' => ['required', 'date'],
            'effective_to' => ['required', 'date', 'after:effective_from'],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        return response()->json([
            'data' => $this->service->createDelegation(
                $validated + $this->commandContext($request, false)
            ),
        ], 201);
    }

    public function revokeDelegation(string $delegationId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->revokeDelegation(
            $delegationId,
            $this->commandContext($request, true)
        )]);
    }

    public function escalateDue(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->escalateDue(
            $this->commandContext($request, false)
        )]);
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }

    private function ruleRules(bool $creating): array
    {
        return array_filter([
            'code' => $creating
                ? ['required', 'string', Rule::in(array_keys(ApprovalRuleService::SUPPORTED_RULES))]
                : null,
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'string', Rule::in(ApprovalRuleService::STATUSES)],
            'bands' => ['required', 'array', 'min:1', 'max:10'],
            'bands.*.name' => ['required', 'string', 'max:255'],
            'bands.*.minimum_value' => ['required', 'numeric', 'min:0', 'max:9999999999999'],
            'bands.*.maximum_value' => ['nullable', 'numeric', 'min:0', 'max:9999999999999'],
            'bands.*.required_permission' => ['required', 'string', 'max:128'],
            'bands.*.escalation_permission' => ['required', 'string', 'max:128'],
            'bands.*.work_priority' => ['required', 'string', Rule::in(ApprovalRuleService::PRIORITIES)],
            'bands.*.due_hours' => ['required', 'integer', 'between:1,8760'],
            'bands.*.escalate_after_hours' => ['required', 'integer', 'between:1,8760'],
        ], fn ($rule) => $rule !== null);
    }
}

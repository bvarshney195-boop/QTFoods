<?php

namespace App\Modules\Manufacturing\Http\Controllers;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use App\Modules\Manufacturing\Application\ManufacturingPlanningQuery;
use App\Modules\Manufacturing\Application\ManufacturingPlanningService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ManufacturingPlanningController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly SessionService $sessions,
        private readonly ManufacturingPlanningQuery $query,
        private readonly ManufacturingPlanningService $service,
    ) {}

    public function demandIndex(Request $request): JsonResponse
    {
        $scope = $this->selectedScope($request, true);
        $filters = $request->validate($this->filterRules(ManufacturingPlanningService::DEMAND_STATUSES));

        return response()->json($this->query->demandWorkspace($scope, $filters, $this->currentPermissions($request)));
    }

    public function demandShow(string $demandPlanId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->demandDetail(
            $demandPlanId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request),
        )]);
    }

    public function demandCreate(Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'plan_number' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'],
            ...$this->demandRules(),
        ]);

        return response()->json(['data' => $this->service->createDemand(
            $validated + $this->commandContext($request, false),
        )], 201);
    }

    public function demandUpdate(string $demandPlanId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate(['plan_number' => ['prohibited'], ...$this->demandRules()]);

        return response()->json(['data' => $this->service->updateDemand(
            $demandPlanId,
            $validated + $this->commandContext($request, true),
        )]);
    }

    public function demandRelease(string $demandPlanId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);

        return response()->json(['data' => $this->service->releaseDemand(
            $demandPlanId,
            $this->commandContext($request, true),
        )]);
    }

    public function demandCancel(string $demandPlanId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);

        return response()->json(['data' => $this->service->cancelDemand(
            $demandPlanId,
            trim($validated['reason']),
            $this->commandContext($request, true),
        )]);
    }

    public function mrpIndex(Request $request): JsonResponse
    {
        $scope = $this->selectedScope($request, true);
        $filters = $request->validate($this->filterRules(ManufacturingPlanningService::MRP_STATUSES));

        return response()->json($this->query->mrpWorkspace($scope, $filters, $this->currentPermissions($request)));
    }

    public function mrpShow(string $mrpRunId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->mrpDetail(
            $mrpRunId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request),
        )]);
    }

    public function mrpRun(Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'run_number' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'],
            'demand_plan_id' => ['required', 'uuid'],
            'run_date' => ['required', 'date_format:Y-m-d'],
        ]);

        return response()->json(['data' => $this->service->runMrp(
            $validated + $this->commandContext($request, false),
        )], 201);
    }

    public function mrpCancel(string $mrpRunId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);

        return response()->json(['data' => $this->service->cancelMrp(
            $mrpRunId,
            trim($validated['reason']),
            $this->commandContext($request, true),
        )]);
    }

    public function scheduleIndex(Request $request): JsonResponse
    {
        $scope = $this->selectedScope($request, true);
        $filters = $request->validate($this->filterRules(ManufacturingPlanningService::SCHEDULE_STATUSES));

        return response()->json($this->query->scheduleWorkspace($scope, $filters, $this->currentPermissions($request)));
    }

    public function scheduleShow(string $scheduleId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->scheduleDetail(
            $scheduleId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request),
        )]);
    }

    public function scheduleCreate(Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'schedule_number' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'],
            'mrp_run_id' => ['required', 'uuid'],
            ...$this->scheduleRules(),
        ]);

        return response()->json(['data' => $this->service->createSchedule(
            $validated + $this->commandContext($request, false),
        )], 201);
    }

    public function scheduleUpdate(string $scheduleId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'schedule_number' => ['prohibited'], 'mrp_run_id' => ['prohibited'], ...$this->scheduleRules(),
        ]);

        return response()->json(['data' => $this->service->updateSchedule(
            $scheduleId,
            $validated + $this->commandContext($request, true),
        )]);
    }

    public function scheduleRelease(string $scheduleId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);

        return response()->json(['data' => $this->service->releaseSchedule(
            $scheduleId,
            $this->commandContext($request, true),
        )]);
    }

    public function scheduleCancel(string $scheduleId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);

        return response()->json(['data' => $this->service->cancelSchedule(
            $scheduleId,
            trim($validated['reason']),
            $this->commandContext($request, true),
        )]);
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }

    private function demandRules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'horizon_start' => ['required', 'date_format:Y-m-d'],
            'horizon_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:horizon_start'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'lines' => ['required', 'array', 'between:1,100'],
            'lines.*' => ['required', 'array'],
            'lines.*.output_sku_id' => ['required', 'uuid'],
            'lines.*.demand_date' => ['required', 'date_format:Y-m-d'],
            'lines.*.demand_type' => ['required', 'string', Rule::in(ManufacturingPlanningService::DEMAND_TYPES)],
            'lines.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function scheduleRules(): array
    {
        return [
            'horizon_start' => ['required', 'date_format:Y-m-d'],
            'horizon_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:horizon_start'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'lines' => ['required', 'array', 'between:1,100'],
            'lines.*' => ['required', 'array'],
            'lines.*.mrp_planned_order_id' => ['required', 'uuid', 'distinct'],
            'lines.*.planned_start_date' => ['required', 'date_format:Y-m-d'],
            'lines.*.planned_end_date' => ['required', 'date_format:Y-m-d'],
            'capacities' => ['required', 'array', 'between:1,100'],
            'capacities.*' => ['required', 'array'],
            'capacities.*.work_center_code' => ['required', 'string', 'max:64', 'distinct'],
            'capacities.*.daily_capacity_minutes' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
        ];
    }

    private function filterRules(array $statuses): array
    {
        return [
            'q' => ['nullable', 'string', 'max:160'],
            'status' => ['nullable', 'string', Rule::in($statuses)],
            'sort' => ['nullable', 'string', Rule::in(ManufacturingPlanningQuery::SORTS)],
            'per_page' => ['nullable', 'integer', Rule::in([10, 25, 50, 100])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    private function normalise(Request $request): void
    {
        $input = $request->all();
        foreach (['plan_number', 'run_number', 'schedule_number'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = Str::upper(trim($input[$field]));
            }
        }
        if (is_string($input['name'] ?? null)) {
            $input['name'] = trim($input['name']);
        }
        foreach ($input['lines'] ?? [] as $index => $line) {
            if (is_string($line['demand_type'] ?? null)) {
                $input['lines'][$index]['demand_type'] = Str::upper(trim($line['demand_type']));
            }
        }
        foreach ($input['capacities'] ?? [] as $index => $capacity) {
            if (is_string($capacity['work_center_code'] ?? null)) {
                $input['capacities'][$index]['work_center_code'] = Str::upper(trim($capacity['work_center_code']));
            }
        }
        $request->replace($input);
    }
}

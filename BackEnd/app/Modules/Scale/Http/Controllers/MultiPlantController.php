<?php

namespace App\Modules\Scale\Http\Controllers;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use App\Modules\Scale\Application\MultiPlantQuery;
use App\Modules\Scale\Application\MultiPlantService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class MultiPlantController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly SessionService $sessions,
        private readonly MultiPlantQuery $query,
        private readonly MultiPlantService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'max:24'],
        ]);

        return response()->json($this->query->workspace(
            $this->selectedScope($request, true),
            $this->currentPermissions($request),
            $filters,
        ));
    }

    public function transfer(string $transferId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->transfer(
            $transferId, $this->selectedScope($request, true), $this->currentPermissions($request),
        )]);
    }

    public function route(string $routeId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->route(
            $routeId, $this->selectedScope($request, true), $this->currentPermissions($request),
        )]);
    }

    public function group(string $groupId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->group(
            $groupId, $this->selectedScope($request, true), $this->currentPermissions($request),
        )]);
    }

    public function consolidation(string $runId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->consolidation(
            $runId, $this->selectedScope($request, true), $this->currentPermissions($request),
        )]);
    }

    public function createGroup(Request $request): JsonResponse
    {
        $this->normalise($request, ['group_code', 'base_currency'], ['members' => ['member_code', 'reporting_currency']]);
        $validated = $request->validate(['group_code' => $this->code(), ...$this->groupRules()]);

        return $this->created($this->service->createGroup($validated + $this->commandContext($request, false)));
    }

    public function updateGroup(string $groupId, Request $request): JsonResponse
    {
        $this->normalise($request, ['base_currency'], ['members' => ['member_code', 'reporting_currency']]);
        $validated = $request->validate(['group_code' => ['prohibited'], ...$this->groupRules()]);

        return $this->ok($this->service->updateGroup($groupId, $validated + $this->commandContext($request, true)));
    }

    public function activateGroup(string $groupId, Request $request): JsonResponse
    {
        return $this->ok($this->service->activateGroup($groupId, $this->commandContext($request, true)));
    }

    public function retireGroup(string $groupId, Request $request): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);

        return $this->ok($this->service->retireGroup($groupId, trim($validated['reason']), $this->commandContext($request, true)));
    }

    public function createRoute(Request $request): JsonResponse
    {
        $this->normalise($request, ['route_code', 'transfer_scope', 'currency'], ['mappings' => ['source_uom_code', 'destination_uom_code']]);
        $validated = $request->validate([
            'route_code' => $this->code(),
            'destination_company_id' => ['required', 'uuid'],
            'destination_plant_id' => ['required', 'uuid'],
            'consolidation_group_id' => ['nullable', 'uuid'],
            'transfer_scope' => ['required', Rule::in(['INTER_PLANT', 'INTER_COMPANY'])],
            'require_destination_acceptance' => ['sometimes', 'boolean'],
            'require_commercial_reference' => ['sometimes', 'boolean'],
            ...$this->routeRules(),
        ]);

        return $this->created($this->service->createRoute($validated + $this->commandContext($request, false)));
    }

    public function updateRoute(string $routeId, Request $request): JsonResponse
    {
        $this->normalise($request, ['currency'], ['mappings' => ['source_uom_code', 'destination_uom_code']]);
        $validated = $request->validate([
            'route_code' => ['prohibited'], 'destination_company_id' => ['prohibited'],
            'destination_plant_id' => ['prohibited'], 'consolidation_group_id' => ['prohibited'],
            'transfer_scope' => ['prohibited'], ...$this->routeRules(),
        ]);

        return $this->ok($this->service->updateRoute($routeId, $validated + $this->commandContext($request, true)));
    }

    public function activateRoute(string $routeId, Request $request): JsonResponse
    {
        return $this->ok($this->service->activateRoute($routeId, $this->commandContext($request, true)));
    }

    public function deactivateRoute(string $routeId, Request $request): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);

        return $this->ok($this->service->deactivateRoute($routeId, trim($validated['reason']), $this->commandContext($request, true)));
    }

    public function createTransfer(Request $request): JsonResponse
    {
        $this->normalise($request, ['transfer_number']);
        $validated = $request->validate([
            'transfer_number' => $this->code(), 'plant_transfer_route_id' => ['required', 'uuid'],
            ...$this->transferRules(),
        ]);

        return $this->created($this->service->createTransfer($validated + $this->commandContext($request, false)));
    }

    public function updateTransfer(string $transferId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'transfer_number' => ['prohibited'], 'plant_transfer_route_id' => ['prohibited'],
            ...$this->transferRules(),
        ]);

        return $this->ok($this->service->updateTransfer($transferId, $validated + $this->commandContext($request, true)));
    }

    public function submitTransfer(string $transferId, Request $request): JsonResponse
    {
        return $this->ok($this->service->submitTransfer($transferId, $this->commandContext($request, true)));
    }

    public function approveTransfer(string $transferId, Request $request): JsonResponse
    {
        return $this->ok($this->service->approveTransfer($transferId, $this->commandContext($request, true)));
    }

    public function acceptTransfer(string $transferId, Request $request): JsonResponse
    {
        $validated = $request->validate(['destination_reference' => ['required', 'string', 'min:3', 'max:160']]);

        return $this->ok($this->service->acceptTransfer(
            $transferId, trim($validated['destination_reference']), $this->commandContext($request, true),
        ));
    }

    public function dispatchTransfer(string $transferId, Request $request): JsonResponse
    {
        return $this->ok($this->service->dispatchTransfer($transferId, $this->commandContext($request, true)));
    }

    public function receiveTransfer(string $transferId, Request $request): JsonResponse
    {
        return $this->ok($this->service->receiveTransfer($transferId, $this->commandContext($request, true)));
    }

    public function cancelTransfer(string $transferId, Request $request): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]);

        return $this->ok($this->service->cancelTransfer($transferId, trim($validated['reason']), $this->commandContext($request, true)));
    }

    public function createConsolidation(Request $request): JsonResponse
    {
        $this->normalise($request, ['run_number']);
        $validated = $request->validate([
            'run_number' => $this->code(), 'consolidation_group_id' => ['required', 'uuid'],
            'cutoff_date' => ['required', 'date_format:Y-m-d'],
            'member_rates' => ['required', 'array', 'between:1,100'],
            'member_rates.*.company_id' => ['required', 'uuid', 'distinct'],
            'member_rates.*.exchange_rate' => ['required', 'numeric', 'gt:0', 'max:1000000', 'decimal:0,8'],
            'eliminations' => ['sometimes', 'array', 'max:500'],
            'eliminations.*.description' => ['required', 'string', 'max:255'],
            'eliminations.*.debit_amount' => $this->amount(4),
            'eliminations.*.credit_amount' => $this->amount(4),
        ]);

        return $this->created($this->service->createConsolidation($validated + $this->commandContext($request, false)));
    }

    public function finalizeConsolidation(string $runId, Request $request): JsonResponse
    {
        return $this->ok($this->service->finalizeConsolidation($runId, $this->commandContext($request, true)));
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }

    private function groupRules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'base_currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'members' => ['required', 'array', 'between:1,50'],
            'members.*.company_id' => ['required', 'uuid', 'distinct'],
            'members.*.member_code' => $this->code(32),
            'members.*.reporting_currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'members.*.ownership_percent' => ['required', 'numeric', 'gt:0', 'max:100', 'decimal:0,4'],
            'members.*.effective_from' => ['required', 'date_format:Y-m-d'],
            'members.*.effective_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:members.*.effective_from'],
        ];
    }

    private function routeRules(): array
    {
        return [
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'currency' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'transit_days' => ['required', 'integer', 'between:0,365'],
            'markup_percent' => ['required', 'numeric', 'min:0', 'max:1000', 'decimal:0,4'],
            'mappings' => ['required', 'array', 'between:1,200'],
            'mappings.*.source_item_id' => ['required', 'uuid'],
            'mappings.*.destination_item_id' => ['required', 'uuid'],
            'mappings.*.source_uom_code' => ['required', 'string', 'max:16'],
            'mappings.*.destination_uom_code' => ['required', 'string', 'max:16'],
            'mappings.*.conversion_rate' => ['required', 'numeric', 'gt:0', 'max:999999999999', 'decimal:0,8'],
        ];
    }

    private function transferRules(): array
    {
        return [
            'transfer_date' => ['required', 'date_format:Y-m-d'],
            'expected_arrival_date' => ['required', 'date_format:Y-m-d', 'after_or_equal:transfer_date'],
            'commercial_reference' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'lines' => ['required', 'array', 'between:1,200'],
            'lines.*.source_position_id' => ['required', 'uuid'],
            'lines.*.destination_position_id' => ['required', 'uuid'],
            'lines.*.quantity_base' => ['required', 'numeric', 'gt:0', 'max:99999999999999', 'decimal:0,6'],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function code(int $max = 80): array
    {
        return ['required', 'string', "max:{$max}", 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'];
    }

    private function amount(int $scale): array
    {
        return ['required', 'numeric', 'min:0', 'max:9999999999999999', "decimal:0,{$scale}"];
    }

    private function normalise(Request $request, array $root, array $nested = []): void
    {
        $input = $request->all();
        foreach ($root as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = Str::upper(trim($input[$field]));
            }
        }
        foreach ($nested as $collection => $fields) {
            foreach ($input[$collection] ?? [] as $index => $row) {
                foreach ($fields as $field) {
                    if (is_string($row[$field] ?? null)) {
                        $input[$collection][$index][$field] = Str::upper(trim($row[$field]));
                    }
                }
            }
        }
        $request->replace($input);
    }

    private function created(array $data): JsonResponse
    {
        return response()->json(['data' => $data], 201);
    }

    private function ok(array $data): JsonResponse
    {
        return response()->json(['data' => $data]);
    }
}

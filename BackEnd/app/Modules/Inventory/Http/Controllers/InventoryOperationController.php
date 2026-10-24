<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use App\Modules\Inventory\Application\InventoryOperationQuery;
use App\Modules\Inventory\Application\InventoryOperationService;
use App\Shared\Visibility\OperationalVisibilityScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class InventoryOperationController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly InventoryOperationQuery $query,
        private readonly InventoryOperationService $service,
        private readonly SessionService $sessions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $config = $this->resource($request);
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'q' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(InventoryOperationService::STATUSES)],
            'operation_type' => ['sometimes', 'string', Rule::in($config['types'])],
            'sort' => ['sometimes', 'string', Rule::in(InventoryOperationQuery::SORTS)],
            'visibility' => ['sometimes', 'string', Rule::in(OperationalVisibilityScope::FILTERS)],
        ]);

        return response()->json($this->query->workspace(
            $config['resource'],
            $this->selectedScope($request, true),
            $filters,
            $this->currentPermissions($request),
        ));
    }

    public function show(string $operationId, Request $request): JsonResponse
    {
        $config = $this->resource($request);

        return response()->json(['data' => $this->query->detail(
            $config['resource'],
            $operationId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request),
        )]);
    }

    public function create(Request $request): JsonResponse
    {
        $config = $this->resource($request);
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'operation_number' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'],
            'operation_type' => ['required', 'string', Rule::in($config['types'])],
            ...$this->writeRules(),
        ]);

        return response()->json(['data' => $this->service->create(
            $validated + $this->command($request, $config, false),
        )], 201);
    }

    public function update(string $operationId, Request $request): JsonResponse
    {
        $config = $this->resource($request);
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'operation_number' => ['prohibited'],
            'operation_type' => ['prohibited'],
            ...$this->writeRules(),
        ]);

        return response()->json(['data' => $this->service->update(
            $operationId,
            $validated + $this->command($request, $config, true),
        )]);
    }

    public function post(string $operationId, Request $request): JsonResponse
    {
        $config = $this->resource($request);
        $this->selectedScope($request, true);

        return response()->json(['data' => $this->service->post(
            $operationId,
            $this->command($request, $config, true),
        )]);
    }

    public function cancel(string $operationId, Request $request): JsonResponse
    {
        $config = $this->resource($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        return response()->json(['data' => $this->service->cancel(
            $operationId,
            trim($validated['reason']),
            $this->command($request, $config, true),
        )]);
    }

    public function movements(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'q' => ['sometimes', 'string', 'max:100'],
            'movement_type' => ['sometimes', 'string', 'max:64'],
            'direction' => ['sometimes', 'string', Rule::in(InventoryOperationQuery::MOVEMENT_DIRECTIONS)],
            'item_id' => ['sometimes', 'uuid'],
            'lot_id' => ['sometimes', 'uuid'],
            'from' => ['sometimes', 'date_format:Y-m-d'],
            'to' => ['sometimes', 'date_format:Y-m-d', 'after_or_equal:from'],
            'sort' => ['sometimes', 'string', Rule::in(InventoryOperationQuery::MOVEMENT_SORTS)],
        ]);

        return response()->json($this->query->movementWorkspace(
            $this->selectedScope($request, true),
            $filters,
            $this->currentPermissions($request),
        ));
    }

    public function movement(string $movementId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->movementDetail(
            $movementId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request),
        )]);
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }

    private function writeRules(): array
    {
        return [
            'reason_code' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'lines' => ['required', 'array', 'between:1,100'],
            'lines.*' => ['required', 'array'],
            'lines.*.source_position_id' => ['nullable', 'uuid'],
            'lines.*.target_position_id' => ['nullable', 'uuid'],
            'lines.*.quantity_base' => ['nullable', 'numeric', 'gt:0', 'max:99999999999999'],
            'lines.*.counted_quantity_base' => ['nullable', 'numeric', 'min:0', 'max:99999999999999'],
            'lines.*.adjustment_direction' => [
                'nullable', 'string', Rule::in(InventoryOperationService::ADJUSTMENT_DIRECTIONS),
            ],
            'lines.*.notes' => ['nullable', 'string', 'max:1000'],
        ];
    }

    private function resource(Request $request): array
    {
        $resource = (string) $request->route('resource');
        $config = InventoryOperationService::RESOURCES[$resource] ?? null;
        if (! $config) {
            throw new NotFoundHttpException('Inventory operation resource not found.');
        }

        return $config + ['resource' => $resource];
    }

    private function command(Request $request, array $config, bool $versionRequired): array
    {
        return $this->commandContext($request, $versionRequired) + [
            'allowed_operation_types' => $config['types'],
        ];
    }

    private function normalise(Request $request): void
    {
        $input = $request->all();
        foreach (['operation_number', 'operation_type', 'reason_code'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = Str::upper(trim($input[$field]));
            }
        }
        foreach ($input['lines'] ?? [] as $index => $line) {
            if (is_string($line['adjustment_direction'] ?? null)) {
                $input['lines'][$index]['adjustment_direction'] = Str::upper(trim($line['adjustment_direction']));
            }
        }
        $request->replace($input);
    }
}

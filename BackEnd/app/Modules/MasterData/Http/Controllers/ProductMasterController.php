<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use App\Modules\MasterData\Application\ProductMasterQuery;
use App\Modules\MasterData\Application\ProductMasterService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class ProductMasterController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly ProductMasterQuery $query,
        private readonly ProductMasterService $service,
        private readonly SessionService $sessions,
    ) {}

    public function index(Request $request, string $resource): JsonResponse
    {
        $this->service->definition($resource);
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'q' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(ProductMasterService::STATUSES)],
            'type' => ['sometimes', 'string', 'max:32'],
            'parent_id' => ['sometimes', 'uuid'],
            'sort' => ['sometimes', 'string', Rule::in(ProductMasterQuery::SORTS)],
        ]);

        return response()->json($this->query->workspace(
            $resource,
            $this->selectedScope($request, true),
            $filters,
            $this->currentPermissions($request),
        ));
    }

    public function show(string $recordId, Request $request, string $resource): JsonResponse
    {
        return response()->json(['data' => $this->query->detail(
            $resource,
            $recordId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request),
        )]);
    }

    public function create(Request $request, string $resource): JsonResponse
    {
        $this->normalise($request, $resource);
        $this->selectedScope($request, true);
        $validated = $request->validate($this->rules($resource, true));

        return response()->json(['data' => $this->service->create(
            $resource,
            $validated + $this->commandContext($request, false),
        )], 201);
    }

    public function update(string $recordId, Request $request, string $resource): JsonResponse
    {
        $this->normalise($request, $resource);
        $this->selectedScope($request, true);
        $validated = $request->validate($this->rules($resource, false));

        return response()->json(['data' => $this->service->update(
            $resource,
            $recordId,
            $validated + $this->commandContext($request, true),
        )]);
    }

    public function changeStatus(string $recordId, Request $request, string $resource): JsonResponse
    {
        $this->normalise($request, $resource);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'target_status' => ['required', 'string', Rule::in(ProductMasterService::STATUSES)],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        return response()->json(['data' => $this->service->changeStatus(
            $resource,
            $recordId,
            $validated['target_status'],
            trim($validated['reason']),
            $this->commandContext($request, true),
        )]);
    }

    public function delete(string $recordId, Request $request, string $resource): JsonResponse
    {
        $this->service->definition($resource);
        $this->selectedScope($request, true);
        $validated = $request->validate(['confirmed' => ['required', 'accepted']]);

        return response()->json(['data' => $this->service->delete(
            $resource,
            $recordId,
            $validated + $this->commandContext($request, true),
        )]);
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }

    private function rules(string $resource, bool $creating): array
    {
        $this->service->definition($resource);
        $common = [
            'code' => $creating
                ? ['required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/']
                : ['prohibited'],
            'name' => ['required', 'string', 'max:255'],
            'status' => $creating
                ? ['required', 'string', Rule::in(['DRAFT', 'ACTIVE'])]
                : ['prohibited'],
        ];

        return $common + match ($resource) {
            'brands' => [
                'description' => ['nullable', 'string', 'max:4000'],
                'agreements' => ['present', 'array', 'max:50'],
                'agreements.*.id' => ['sometimes', 'nullable', 'uuid'],
                'agreements.*.party_id' => ['required', 'uuid'],
                'agreements.*.agreement_number' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'],
                'agreements.*.agreement_type' => ['required', 'string', Rule::in(ProductMasterService::AGREEMENT_TYPES)],
                'agreements.*.effective_from' => ['required', 'date'],
                'agreements.*.effective_to' => ['nullable', 'date'],
                'agreements.*.currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
                'agreements.*.minimum_commitment' => ['required', 'numeric', 'min:0', 'max:999999999999999999'],
                'agreements.*.status' => ['required', 'string', Rule::in(ProductMasterService::AGREEMENT_STATUSES)],
                'agreements.*.notes' => ['nullable', 'string', 'max:2000'],
            ],
            'items' => [
                'brand_id' => ['nullable', 'uuid'],
                'item_type' => ['required', 'string', Rule::in(ProductMasterService::ITEM_TYPES)],
                'base_uom' => ['required', 'string', 'max:16'],
                'description' => ['nullable', 'string', 'max:4000'],
                'shelf_life_days' => ['nullable', 'integer', 'between:0,36500'],
                'lot_controlled' => ['required', 'boolean'],
                'conversions' => ['present', 'array', 'max:50'],
                'conversions.*.id' => ['sometimes', 'nullable', 'uuid'],
                'conversions.*.from_uom_code' => ['required', 'string', 'max:16'],
                'conversions.*.to_uom_code' => ['required', 'string', 'max:16'],
                'conversions.*.multiplier' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
                'conversions.*.rounding_mode' => ['required', 'string', Rule::in(ProductMasterService::ROUNDING_MODES)],
            ],
            'skus' => [
                'catalog_item_id' => ['required', 'uuid'],
                'barcode' => ['nullable', 'string', 'max:80'],
                'description' => ['nullable', 'string', 'max:4000'],
                'pack_quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
                'pack_uom_code' => ['required', 'string', 'max:16'],
                'packs' => ['present', 'array', 'max:50'],
                'packs.*.id' => ['sometimes', 'nullable', 'uuid'],
                'packs.*.code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/'],
                'packs.*.name' => ['required', 'string', 'max:160'],
                'packs.*.uom_code' => ['required', 'string', 'max:16'],
                'packs.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
                'packs.*.barcode' => ['nullable', 'string', 'max:80'],
                'packs.*.is_default' => ['required', 'boolean'],
            ],
            'recipes' => [
                'revision' => ['required', 'integer', 'between:1,65535'],
                'output_sku_id' => ['required', 'uuid'],
                'output_quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
                'output_uom_code' => ['required', 'string', 'max:16'],
                'yield_percent' => ['required', 'numeric', 'gt:0', 'max:100'],
                'effective_from' => ['nullable', 'date'],
                'effective_to' => ['nullable', 'date'],
                'notes' => ['nullable', 'string', 'max:4000'],
                'components' => ['present', 'array', 'max:200'],
                'components.*.id' => ['sometimes', 'nullable', 'uuid'],
                'components.*.component_sku_id' => ['required', 'uuid'],
                'components.*.sequence_no' => ['required', 'integer', 'between:1,65535'],
                'components.*.quantity' => ['required', 'numeric', 'gt:0', 'max:99999999999'],
                'components.*.uom_code' => ['required', 'string', 'max:16'],
                'components.*.waste_percent' => ['required', 'numeric', 'between:0,100'],
            ],
            'routes' => [
                'catalog_item_id' => ['required', 'uuid'],
                'description' => ['nullable', 'string', 'max:4000'],
                'operations' => ['present', 'array', 'max:200'],
                'operations.*.id' => ['sometimes', 'nullable', 'uuid'],
                'operations.*.sequence_no' => ['required', 'integer', 'between:1,65535'],
                'operations.*.name' => ['required', 'string', 'max:160'],
                'operations.*.work_center_code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/'],
                'operations.*.setup_minutes' => ['required', 'numeric', 'min:0', 'max:999999999'],
                'operations.*.run_minutes_per_unit' => ['required', 'numeric', 'min:0', 'max:999999'],
                'operations.*.instructions' => ['nullable', 'string', 'max:4000'],
            ],
            'specifications' => [
                'target_type' => ['required', 'string', Rule::in(ProductMasterService::SPEC_TARGET_TYPES)],
                'catalog_item_id' => ['nullable', 'uuid'],
                'sku_id' => ['nullable', 'uuid'],
                'effective_from' => ['nullable', 'date'],
                'effective_to' => ['nullable', 'date'],
                'sampling_plan' => ['nullable', 'string', 'max:120'],
                'notes' => ['nullable', 'string', 'max:4000'],
                'parameters' => ['present', 'array', 'max:200'],
                'parameters.*.id' => ['sometimes', 'nullable', 'uuid'],
                'parameters.*.sequence_no' => ['required', 'integer', 'between:1,65535'],
                'parameters.*.code' => ['required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/'],
                'parameters.*.name' => ['required', 'string', 'max:160'],
                'parameters.*.value_type' => ['required', 'string', Rule::in(ProductMasterService::SPEC_VALUE_TYPES)],
                'parameters.*.uom_code' => ['nullable', 'string', 'max:16'],
                'parameters.*.minimum_value' => ['nullable', 'numeric'],
                'parameters.*.target_value' => ['nullable', 'numeric'],
                'parameters.*.maximum_value' => ['nullable', 'numeric'],
                'parameters.*.text_requirement' => ['nullable', 'string', 'max:255'],
                'parameters.*.test_method' => ['nullable', 'string', 'max:160'],
                'parameters.*.is_required' => ['required', 'boolean'],
            ],
        };
    }

    private function normalise(Request $request, string $resource): void
    {
        $input = $request->all();
        foreach (['code', 'status', 'target_status', 'item_type', 'base_uom', 'pack_uom_code',
            'output_uom_code', 'target_type'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = Str::upper(trim($input[$field]));
            }
        }
        foreach ($this->childNormalisation($resource) as $child => $fields) {
            if (! is_array($input[$child] ?? null)) {
                continue;
            }
            foreach ($input[$child] as &$row) {
                if (! is_array($row)) {
                    continue;
                }
                foreach ($fields as $field) {
                    if (is_string($row[$field] ?? null)) {
                        $row[$field] = Str::upper(trim($row[$field]));
                    }
                }
            }
            unset($row);
        }
        $request->replace($input);
    }

    private function childNormalisation(string $resource): array
    {
        return match ($resource) {
            'brands' => ['agreements' => ['agreement_number', 'agreement_type', 'currency_code', 'status']],
            'items' => ['conversions' => ['from_uom_code', 'to_uom_code', 'rounding_mode']],
            'skus' => ['packs' => ['code', 'uom_code']],
            'recipes' => ['components' => ['uom_code']],
            'routes' => ['operations' => ['work_center_code']],
            'specifications' => ['parameters' => ['code', 'value_type', 'uom_code']],
        };
    }
}

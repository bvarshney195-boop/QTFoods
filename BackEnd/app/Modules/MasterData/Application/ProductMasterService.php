<?php

namespace App\Modules\MasterData\Application;

use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ProductMasterService
{
    public const STATUSES = ['DRAFT', 'ACTIVE', 'INACTIVE'];
    public const ITEM_TYPES = ['RAW_MATERIAL', 'PACKAGING', 'INTERMEDIATE', 'FINISHED_GOOD', 'SERVICE'];
    public const AGREEMENT_TYPES = ['LICENSE', 'DISTRIBUTION', 'MANUFACTURING', 'SUPPLY'];
    public const AGREEMENT_STATUSES = ['DRAFT', 'ACTIVE', 'EXPIRED', 'TERMINATED'];
    public const ROUNDING_MODES = ['HALF_UP', 'UP', 'DOWN', 'NONE'];
    public const SPEC_TARGET_TYPES = ['ITEM', 'SKU'];
    public const SPEC_VALUE_TYPES = ['NUMERIC', 'TEXT', 'BOOLEAN'];

    public const DEFINITIONS = [
        'brands' => [
            'table' => 'brands', 'entity' => 'brand', 'screen' => 'MD-BRAND',
            'child_table' => 'brand_agreements', 'child_key' => 'brand_id', 'children' => 'agreements',
            'event' => 'master.brand', 'label' => 'Brand',
        ],
        'items' => [
            'table' => 'catalog_items', 'entity' => 'catalog_item', 'screen' => 'MD-ITEM',
            'child_table' => 'item_uom_conversions', 'child_key' => 'catalog_item_id', 'children' => 'conversions',
            'event' => 'master.item', 'label' => 'Item',
        ],
        'skus' => [
            'table' => 'items', 'entity' => 'sku', 'screen' => 'MD-SKU',
            'child_table' => 'sku_packs', 'child_key' => 'sku_id', 'children' => 'packs',
            'event' => 'master.sku', 'label' => 'SKU',
        ],
        'recipes' => [
            'table' => 'recipes', 'entity' => 'recipe', 'screen' => 'MD-REC',
            'child_table' => 'recipe_components', 'child_key' => 'recipe_id', 'children' => 'components',
            'event' => 'manufacturing.recipe', 'label' => 'Recipe',
        ],
        'routes' => [
            'table' => 'production_routes', 'entity' => 'route', 'screen' => 'MD-ROUTE',
            'child_table' => 'route_operations', 'child_key' => 'route_id', 'children' => 'operations',
            'event' => 'manufacturing.route', 'label' => 'Route',
        ],
        'specifications' => [
            'table' => 'quality_specifications', 'entity' => 'specification', 'screen' => 'MD-SPEC',
            'child_table' => 'quality_spec_parameters', 'child_key' => 'specification_id', 'children' => 'parameters',
            'event' => 'quality.specification', 'label' => 'Specification',
        ],
    ];

    private const TRANSITIONS = [
        'DRAFT' => ['ACTIVE', 'INACTIVE'],
        'ACTIVE' => ['INACTIVE'],
        'INACTIVE' => ['ACTIVE'],
    ];

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    public function create(string $resource, array $data): array
    {
        $definition = $this->definition($resource);

        return DB::transaction(function () use ($resource, $definition, $data) {
            $namespace = $definition['event'].'.create';
            $payload = $this->idempotencyPayload($data) + ['resource' => $resource];
            $replay = $this->idempotency->begin($namespace, $data['idempotency_key'], $payload);
            if ($replay !== null) {
                return $replay;
            }

            $this->validateAggregate($resource, $data);
            $this->assertCodeAvailable($definition['table'], $data['company_id'], $data['code']);
            $this->assertChildUniqueValuesAvailable($resource, $data, null);

            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table($definition['table'])->insert($this->parentRow($resource, $data, $now) + [
                'id' => $id,
                'company_id' => $data['company_id'],
                'code' => $data['code'],
                'status' => $data['status'],
                'record_version' => 1,
                'status_reason' => $data['status'] === 'ACTIVE' ? 'Activated during creation.' : null,
                'status_changed_at' => $data['status'] === 'ACTIVE' ? $now : null,
                'status_changed_by' => $data['status'] === 'ACTIVE' ? $data['actor_id'] : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->replaceChildren($resource, $id, $data, $now, false);

            $result = $this->result($definition, $id, $data['status'], 1, count($data[$definition['children']]));
            $this->record($definition, 'CREATE', $id, $data, 1, [
                'created' => [
                    'code' => $data['code'],
                    'name' => trim($data['name']),
                    'status' => $data['status'],
                    $definition['children'].'_count' => count($data[$definition['children']]),
                ],
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function update(string $resource, string $recordId, array $data): array
    {
        $definition = $this->definition($resource);

        return DB::transaction(function () use ($resource, $definition, $recordId, $data) {
            $namespace = $definition['event'].'.update.'.$recordId;
            $payload = $this->idempotencyPayload($data) + ['resource' => $resource, 'record_id' => $recordId];
            $replay = $this->idempotency->begin($namespace, $data['idempotency_key'], $payload);
            if ($replay !== null) {
                return $replay;
            }

            $record = $this->findLocked($definition, $recordId, $data['company_id']);
            $this->assertVersion($definition, $record, $data['expected_version']);
            $data['status'] = (string) $record->status;
            $this->assertSafeStructuralUpdate($resource, $record, $data);
            $this->validateAggregate($resource, $data);
            $this->assertOwnedChildIds($definition, $recordId, $data['company_id'], $data[$definition['children']]);
            $this->assertChildUniqueValuesAvailable($resource, $data, $recordId);

            $version = (int) $record->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table($definition['table'])->where('id', $recordId)->update(
                $this->parentRow($resource, $data, $now) + [
                    'record_version' => $version,
                    'updated_at' => $now,
                ]
            );
            $this->replaceChildren($resource, $recordId, $data, $now, true);

            $result = $this->result(
                $definition,
                $recordId,
                (string) $record->status,
                $version,
                count($data[$definition['children']]),
            );
            $this->record($definition, 'UPDATE', $recordId, $data, $version, [
                'name' => ['from' => $record->name, 'to' => trim($data['name'])],
                $definition['children'].'_count' => count($data[$definition['children']]),
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function changeStatus(
        string $resource,
        string $recordId,
        string $targetStatus,
        string $reason,
        array $data,
    ): array {
        $definition = $this->definition($resource);

        return DB::transaction(function () use ($resource, $definition, $recordId, $targetStatus, $reason, $data) {
            $namespace = $definition['event'].'.status.'.$recordId;
            $payload = [
                'resource' => $resource,
                'record_id' => $recordId,
                'target_status' => $targetStatus,
                'reason' => $reason,
                'expected_version' => $data['expected_version'],
            ];
            $replay = $this->idempotency->begin($namespace, $data['idempotency_key'], $payload);
            if ($replay !== null) {
                return $replay;
            }

            $record = $this->findLocked($definition, $recordId, $data['company_id']);
            $this->assertVersion($definition, $record, $data['expected_version']);
            if (! in_array($targetStatus, self::allowedTransitions((string) $record->status), true)) {
                throw ValidationException::withMessages([
                    'target_status' => ["A {$definition['label']} cannot move from {$record->status} to {$targetStatus}."],
                ]);
            }
            if ($targetStatus === 'ACTIVE') {
                $this->assertReadyForActivation($resource, $recordId, $data['company_id']);
            }
            if ($targetStatus === 'INACTIVE') {
                $this->assertSafeToDeactivate($resource, $recordId);
            }

            $version = (int) $record->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table($definition['table'])->where('id', $recordId)->update([
                'status' => $targetStatus,
                'status_reason' => $reason,
                'status_changed_at' => $now,
                'status_changed_by' => $data['actor_id'],
                'record_version' => $version,
                'updated_at' => $now,
            ]);

            $result = $this->result($definition, $recordId, $targetStatus, $version);
            $this->record($definition, 'CHANGE_STATUS', $recordId, $data, $version, [
                'status' => ['from' => $record->status, 'to' => $targetStatus],
                'reason' => $reason,
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function delete(string $resource, string $recordId, array $data): array
    {
        $definition = $this->definition($resource);

        return DB::transaction(function () use ($resource, $definition, $recordId, $data) {
            $namespace = $definition['event'].'.delete.'.$recordId;
            $payload = [
                'resource' => $resource, 'record_id' => $recordId,
                'expected_version' => $data['expected_version'], 'confirmed' => true,
            ];
            $replay = $this->idempotency->begin($namespace, $data['idempotency_key'], $payload);
            if ($replay !== null) {
                return $replay;
            }

            $record = $this->findLocked($definition, $recordId, $data['company_id']);
            $this->assertVersion($definition, $record, $data['expected_version']);
            try {
                DB::table($definition['table'])->where('id', $recordId)->delete();
            } catch (QueryException) {
                throw ValidationException::withMessages([
                    $definition['entity'] => ["This {$definition['label']} is used by another ERP entry and cannot be deleted. Deactivate it instead."],
                ]);
            }

            $version = (int) $record->record_version + 1;
            $result = $this->result($definition, $recordId, 'DELETED', $version) + ['deleted' => true];
            $this->record($definition, 'DELETE', $recordId, $data, $version, [
                'deleted' => ['code' => $record->code, 'name' => $record->name],
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public static function allowedTransitions(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }

    public function definition(string $resource): array
    {
        if (! isset(self::DEFINITIONS[$resource])) {
            throw new NotFoundHttpException('Product master resource not found.');
        }

        return self::DEFINITIONS[$resource];
    }

    private function validateAggregate(string $resource, array $data): void
    {
        $this->assertUomsExist($resource, $data);
        match ($resource) {
            'brands' => $this->validateBrand($data),
            'items' => $this->validateItem($data),
            'skus' => $this->validateSku($data),
            'recipes' => $this->validateRecipe($data),
            'routes' => $this->validateRoute($data),
            'specifications' => $this->validateSpecification($data),
        };
    }

    private function validateBrand(array $data): void
    {
        $this->assertUniqueChildValues($data['agreements'], 'agreement_number', 'agreements');
        foreach ($data['agreements'] as $index => $agreement) {
            $this->assertDateRange($agreement['effective_from'], $agreement['effective_to'] ?? null,
                "agreements.{$index}.effective_to");
            if ((float) $agreement['minimum_commitment'] < 0) {
                throw ValidationException::withMessages([
                    "agreements.{$index}.minimum_commitment" => ['Minimum commitment cannot be negative.'],
                ]);
            }
            $party = DB::table('parties')->where('id', $agreement['party_id'])
                ->where('company_id', $data['company_id'])->first(['id', 'status']);
            if (! $party) {
                throw ValidationException::withMessages([
                    "agreements.{$index}.party_id" => ['Select a party from the current company.'],
                ]);
            }
            if ($agreement['status'] === 'ACTIVE' && $party->status !== 'ACTIVE') {
                throw ValidationException::withMessages([
                    "agreements.{$index}.party_id" => ['An active agreement requires an active party.'],
                ]);
            }
            if ($agreement['status'] === 'ACTIVE' && $data['status'] !== 'ACTIVE') {
                throw ValidationException::withMessages([
                    "agreements.{$index}.status" => ['Activate the brand before activating an agreement.'],
                ]);
            }
        }
    }

    private function validateItem(array $data): void
    {
        if (! empty($data['brand_id'])) {
            $brand = DB::table('brands')->where('id', $data['brand_id'])
                ->where('company_id', $data['company_id'])->first(['status']);
            if (! $brand) {
                throw ValidationException::withMessages(['brand_id' => ['Select a brand from the current company.']]);
            }
            if ($data['status'] === 'ACTIVE' && $brand->status !== 'ACTIVE') {
                throw ValidationException::withMessages(['brand_id' => ['An active item requires an active brand.']]);
            }
        }
        $seen = [];
        foreach ($data['conversions'] as $index => $conversion) {
            $key = $conversion['from_uom_code'].'>'.$conversion['to_uom_code'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "conversions.{$index}.to_uom_code" => ['That UOM conversion is duplicated.'],
                ]);
            }
            $seen[$key] = true;
            if ($conversion['from_uom_code'] === $conversion['to_uom_code']) {
                throw ValidationException::withMessages([
                    "conversions.{$index}.to_uom_code" => ['Conversion UOMs must be different.'],
                ]);
            }
            if ($conversion['from_uom_code'] !== $data['base_uom']
                && $conversion['to_uom_code'] !== $data['base_uom']) {
                throw ValidationException::withMessages([
                    "conversions.{$index}.from_uom_code" => ['Each conversion must include the item base UOM.'],
                ]);
            }
            if ((float) $conversion['multiplier'] <= 0) {
                throw ValidationException::withMessages([
                    "conversions.{$index}.multiplier" => ['Conversion multiplier must be greater than zero.'],
                ]);
            }
        }
    }

    private function validateSku(array $data): void
    {
        $catalog = DB::table('catalog_items')->where('id', $data['catalog_item_id'])
            ->where('company_id', $data['company_id'])->first(['status']);
        if (! $catalog) {
            throw ValidationException::withMessages([
                'catalog_item_id' => ['Select an item from the current company.'],
            ]);
        }
        if ($data['status'] === 'ACTIVE' && $catalog->status !== 'ACTIVE') {
            throw ValidationException::withMessages([
                'catalog_item_id' => ['An active SKU requires an active catalog item.'],
            ]);
        }
        if ((float) $data['pack_quantity'] <= 0) {
            throw ValidationException::withMessages(['pack_quantity' => ['Pack quantity must be greater than zero.']]);
        }
        $this->assertUniqueChildValues($data['packs'], 'code', 'packs');
        $defaults = 0;
        $barcodes = [];
        foreach ($data['packs'] as $index => $pack) {
            if ((float) $pack['quantity'] <= 0) {
                throw ValidationException::withMessages([
                    "packs.{$index}.quantity" => ['Pack quantity must be greater than zero.'],
                ]);
            }
            $barcode = $this->nullable($pack['barcode'] ?? null);
            if ($barcode !== null) {
                if (isset($barcodes[$barcode])) {
                    throw ValidationException::withMessages([
                        "packs.{$index}.barcode" => ['Pack barcodes must be unique.'],
                    ]);
                }
                $barcodes[$barcode] = true;
            }
            $defaults += ($pack['is_default'] ?? false) ? 1 : 0;
        }
        if ($data['status'] === 'ACTIVE' && $data['packs'] === []) {
            throw ValidationException::withMessages(['packs' => ['An active SKU requires at least one pack.']]);
        }
        if ($data['packs'] !== [] && $defaults !== 1) {
            throw ValidationException::withMessages(['packs' => ['Select exactly one default pack.']]);
        }
    }

    private function validateRecipe(array $data): void
    {
        $this->assertDateRange($data['effective_from'] ?? null, $data['effective_to'] ?? null, 'effective_to');
        if ((float) $data['output_quantity'] <= 0) {
            throw ValidationException::withMessages(['output_quantity' => ['Output quantity must be greater than zero.']]);
        }
        if ((float) $data['yield_percent'] <= 0 || (float) $data['yield_percent'] > 100) {
            throw ValidationException::withMessages(['yield_percent' => ['Yield must be greater than zero and at most 100.']]);
        }
        $output = $this->scopedSku($data['output_sku_id'], $data['company_id']);
        if (! $output) {
            throw ValidationException::withMessages(['output_sku_id' => ['Select an output SKU from the current company.']]);
        }
        $this->assertUniqueChildValues($data['components'], 'sequence_no', 'components');
        $this->assertUniqueChildValues($data['components'], 'component_sku_id', 'components');
        foreach ($data['components'] as $index => $component) {
            if ($component['component_sku_id'] === $data['output_sku_id']) {
                throw ValidationException::withMessages([
                    "components.{$index}.component_sku_id" => ['A recipe cannot consume its own output SKU.'],
                ]);
            }
            $sku = $this->scopedSku($component['component_sku_id'], $data['company_id']);
            if (! $sku) {
                throw ValidationException::withMessages([
                    "components.{$index}.component_sku_id" => ['Select a component SKU from the current company.'],
                ]);
            }
            if ((float) $component['quantity'] <= 0) {
                throw ValidationException::withMessages([
                    "components.{$index}.quantity" => ['Component quantity must be greater than zero.'],
                ]);
            }
            if ((float) $component['waste_percent'] < 0 || (float) $component['waste_percent'] > 100) {
                throw ValidationException::withMessages([
                    "components.{$index}.waste_percent" => ['Waste must be between zero and 100.'],
                ]);
            }
            if ($data['status'] === 'ACTIVE' && $sku->status !== 'ACTIVE') {
                throw ValidationException::withMessages([
                    "components.{$index}.component_sku_id" => ['Active recipes require active component SKUs.'],
                ]);
            }
        }
        if ($data['status'] === 'ACTIVE' && $output->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['output_sku_id' => ['An active recipe requires an active output SKU.']]);
        }
        if ($data['status'] === 'ACTIVE' && $data['components'] === []) {
            throw ValidationException::withMessages(['components' => ['An active recipe requires at least one component.']]);
        }
    }

    private function validateRoute(array $data): void
    {
        $item = DB::table('catalog_items')->where('id', $data['catalog_item_id'])
            ->where('company_id', $data['company_id'])->first(['status']);
        if (! $item) {
            throw ValidationException::withMessages(['catalog_item_id' => ['Select an item from the current company.']]);
        }
        $this->assertUniqueChildValues($data['operations'], 'sequence_no', 'operations');
        foreach ($data['operations'] as $index => $operation) {
            if ((float) $operation['setup_minutes'] < 0 || (float) $operation['run_minutes_per_unit'] < 0) {
                throw ValidationException::withMessages([
                    "operations.{$index}.setup_minutes" => ['Operation times cannot be negative.'],
                ]);
            }
        }
        if ($data['status'] === 'ACTIVE' && $item->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['catalog_item_id' => ['An active route requires an active item.']]);
        }
        if ($data['status'] === 'ACTIVE' && $data['operations'] === []) {
            throw ValidationException::withMessages(['operations' => ['An active route requires at least one operation.']]);
        }
    }

    private function validateSpecification(array $data): void
    {
        $this->assertDateRange($data['effective_from'] ?? null, $data['effective_to'] ?? null, 'effective_to');
        $target = null;
        if ($data['target_type'] === 'ITEM') {
            if (empty($data['catalog_item_id']) || ! empty($data['sku_id'])) {
                throw ValidationException::withMessages([
                    'catalog_item_id' => ['Item specifications require exactly one catalog item target.'],
                ]);
            }
            $target = DB::table('catalog_items')->where('id', $data['catalog_item_id'])
                ->where('company_id', $data['company_id'])->first(['status']);
        } else {
            if (empty($data['sku_id']) || ! empty($data['catalog_item_id'])) {
                throw ValidationException::withMessages([
                    'sku_id' => ['SKU specifications require exactly one SKU target.'],
                ]);
            }
            $target = $this->scopedSku($data['sku_id'], $data['company_id']);
        }
        if (! $target) {
            throw ValidationException::withMessages(['target_type' => ['Select a target from the current company.']]);
        }
        $this->assertUniqueChildValues($data['parameters'], 'sequence_no', 'parameters');
        $this->assertUniqueChildValues($data['parameters'], 'code', 'parameters');
        foreach ($data['parameters'] as $index => $parameter) {
            $this->validateSpecificationParameter($parameter, $index);
        }
        if ($data['status'] === 'ACTIVE' && $target->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['target_type' => ['An active specification requires an active target.']]);
        }
        if ($data['status'] === 'ACTIVE' && $data['parameters'] === []) {
            throw ValidationException::withMessages(['parameters' => ['An active specification requires at least one parameter.']]);
        }
    }

    private function validateSpecificationParameter(array $parameter, int $index): void
    {
        $numeric = ['minimum_value', 'target_value', 'maximum_value'];
        $presentNumeric = array_filter($numeric, fn (string $field) => $this->nullable($parameter[$field] ?? null) !== null);
        if ($parameter['value_type'] === 'NUMERIC' && $presentNumeric === []) {
            throw ValidationException::withMessages([
                "parameters.{$index}.target_value" => ['Numeric parameters require a minimum, target, or maximum value.'],
            ]);
        }
        if ($parameter['value_type'] !== 'NUMERIC' && $presentNumeric !== []) {
            throw ValidationException::withMessages([
                "parameters.{$index}.minimum_value" => ['Only numeric parameters may contain numeric limits.'],
            ]);
        }
        if ($parameter['value_type'] === 'TEXT' && $this->nullable($parameter['text_requirement'] ?? null) === null) {
            throw ValidationException::withMessages([
                "parameters.{$index}.text_requirement" => ['Text parameters require a text requirement.'],
            ]);
        }
        if ($parameter['value_type'] !== 'TEXT' && $this->nullable($parameter['text_requirement'] ?? null) !== null) {
            throw ValidationException::withMessages([
                "parameters.{$index}.text_requirement" => ['Only text parameters may contain a text requirement.'],
            ]);
        }
        $minimum = $this->nullable($parameter['minimum_value'] ?? null);
        $maximum = $this->nullable($parameter['maximum_value'] ?? null);
        if ($minimum !== null && $maximum !== null && (float) $maximum < (float) $minimum) {
            throw ValidationException::withMessages([
                "parameters.{$index}.maximum_value" => ['Maximum value must be greater than or equal to minimum value.'],
            ]);
        }
    }

    private function assertUomsExist(string $resource, array $data): void
    {
        $codes = match ($resource) {
            'items' => array_merge([$data['base_uom']], collect($data['conversions'])
                ->flatMap(fn (array $row) => [$row['from_uom_code'], $row['to_uom_code']])->all()),
            'skus' => array_merge([$data['pack_uom_code']], collect($data['packs'])->pluck('uom_code')->all()),
            'recipes' => array_merge([$data['output_uom_code']], collect($data['components'])->pluck('uom_code')->all()),
            'specifications' => collect($data['parameters'])->pluck('uom_code')->filter()->all(),
            default => [],
        };
        $codes = array_values(array_unique(array_filter($codes)));
        if ($codes === []) {
            return;
        }
        $existing = DB::table('uoms')->whereIn('code', $codes)->pluck('code')->all();
        $missing = array_values(array_diff($codes, $existing));
        if ($missing !== []) {
            throw ValidationException::withMessages([
                'uom' => ['Unknown UOM code(s): '.implode(', ', $missing).'.'],
            ]);
        }
    }

    private function parentRow(string $resource, array $data, CarbonImmutable $now): array
    {
        return match ($resource) {
            'brands' => [
                'name' => trim($data['name']),
                'description' => $this->nullable($data['description'] ?? null),
            ],
            'items' => [
                'name' => trim($data['name']),
                'brand_id' => $data['brand_id'] ?? null,
                'item_type' => $data['item_type'],
                'base_uom' => $data['base_uom'],
                'description' => $this->nullable($data['description'] ?? null),
                'shelf_life_days' => $data['shelf_life_days'] ?? null,
                'lot_controlled' => (bool) $data['lot_controlled'],
            ],
            'skus' => $this->skuParentRow($data),
            'recipes' => [
                'name' => trim($data['name']),
                'revision' => (int) $data['revision'],
                'output_sku_id' => $data['output_sku_id'],
                'output_quantity' => $data['output_quantity'],
                'output_uom_code' => $data['output_uom_code'],
                'yield_percent' => $data['yield_percent'],
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
                'notes' => $this->nullable($data['notes'] ?? null),
            ],
            'routes' => [
                'name' => trim($data['name']),
                'catalog_item_id' => $data['catalog_item_id'],
                'description' => $this->nullable($data['description'] ?? null),
            ],
            'specifications' => [
                'name' => trim($data['name']),
                'target_type' => $data['target_type'],
                'catalog_item_id' => $data['target_type'] === 'ITEM' ? $data['catalog_item_id'] : null,
                'sku_id' => $data['target_type'] === 'SKU' ? $data['sku_id'] : null,
                'effective_from' => $data['effective_from'] ?? null,
                'effective_to' => $data['effective_to'] ?? null,
                'sampling_plan' => $this->nullable($data['sampling_plan'] ?? null),
                'notes' => $this->nullable($data['notes'] ?? null),
            ],
        };
    }

    private function skuParentRow(array $data): array
    {
        $catalog = DB::table('catalog_items')->where('id', $data['catalog_item_id'])
            ->where('company_id', $data['company_id'])->first(['item_type', 'base_uom']);
        if (! $catalog) {
            throw ValidationException::withMessages(['catalog_item_id' => ['Select an item from the current company.']]);
        }

        return [
            'name' => trim($data['name']),
            'catalog_item_id' => $data['catalog_item_id'],
            'barcode' => $this->nullable($data['barcode'] ?? null),
            'description' => $this->nullable($data['description'] ?? null),
            'item_type' => (string) $catalog->item_type,
            'base_uom' => (string) $catalog->base_uom,
            'pack_quantity' => $data['pack_quantity'],
            'pack_uom_code' => $data['pack_uom_code'],
        ];
    }

    private function replaceChildren(
        string $resource,
        string $recordId,
        array $data,
        CarbonImmutable $now,
        bool $preserveIds,
    ): void {
        $definition = $this->definition($resource);
        $table = $definition['child_table'];
        $foreignKey = $definition['child_key'];
        $rows = $data[$definition['children']];
        $existing = DB::table($table)->where($foreignKey, $recordId)->get()->keyBy('id');
        DB::table($table)->where($foreignKey, $recordId)->delete();
        if ($rows === []) {
            return;
        }

        DB::table($table)->insert(array_map(function (array $row) use (
            $resource, $recordId, $data, $now, $preserveIds, $existing, $foreignKey
        ): array {
            $id = $preserveIds && ! empty($row['id']) ? (string) $row['id'] : (string) Str::uuid();

            return [
                'id' => $id,
                'company_id' => $data['company_id'],
                $foreignKey => $recordId,
                'created_at' => $existing->get($id)?->created_at ?? $now,
                'updated_at' => $now,
            ] + $this->childRow($resource, $row);
        }, $rows));
    }

    private function childRow(string $resource, array $row): array
    {
        return match ($resource) {
            'brands' => [
                'party_id' => $row['party_id'],
                'agreement_number' => $row['agreement_number'],
                'agreement_type' => $row['agreement_type'],
                'effective_from' => $row['effective_from'],
                'effective_to' => $row['effective_to'] ?? null,
                'currency_code' => $row['currency_code'],
                'minimum_commitment' => $row['minimum_commitment'],
                'status' => $row['status'],
                'notes' => $this->nullable($row['notes'] ?? null),
            ],
            'items' => [
                'from_uom_code' => $row['from_uom_code'],
                'to_uom_code' => $row['to_uom_code'],
                'multiplier' => $row['multiplier'],
                'rounding_mode' => $row['rounding_mode'],
            ],
            'skus' => [
                'code' => $row['code'],
                'name' => trim($row['name']),
                'uom_code' => $row['uom_code'],
                'quantity' => $row['quantity'],
                'barcode' => $this->nullable($row['barcode'] ?? null),
                'is_default' => (bool) $row['is_default'],
            ],
            'recipes' => [
                'component_sku_id' => $row['component_sku_id'],
                'sequence_no' => (int) $row['sequence_no'],
                'quantity' => $row['quantity'],
                'uom_code' => $row['uom_code'],
                'waste_percent' => $row['waste_percent'],
            ],
            'routes' => [
                'sequence_no' => (int) $row['sequence_no'],
                'name' => trim($row['name']),
                'work_center_code' => $row['work_center_code'],
                'setup_minutes' => $row['setup_minutes'],
                'run_minutes_per_unit' => $row['run_minutes_per_unit'],
                'instructions' => $this->nullable($row['instructions'] ?? null),
            ],
            'specifications' => [
                'sequence_no' => (int) $row['sequence_no'],
                'code' => $row['code'],
                'name' => trim($row['name']),
                'value_type' => $row['value_type'],
                'uom_code' => $row['uom_code'] ?? null,
                'minimum_value' => $this->nullable($row['minimum_value'] ?? null),
                'target_value' => $this->nullable($row['target_value'] ?? null),
                'maximum_value' => $this->nullable($row['maximum_value'] ?? null),
                'text_requirement' => $this->nullable($row['text_requirement'] ?? null),
                'test_method' => $this->nullable($row['test_method'] ?? null),
                'is_required' => (bool) $row['is_required'],
            ],
        };
    }

    private function assertReadyForActivation(string $resource, string $recordId, string $companyId): void
    {
        $definition = $this->definition($resource);
        $record = DB::table($definition['table'])->where('id', $recordId)->where('company_id', $companyId)->first();
        if (! $record) {
            throw new NotFoundHttpException($definition['label'].' not found.');
        }

        match ($resource) {
            'brands' => $this->assertBrandReady($recordId, $companyId),
            'items' => $this->assertItemReady($record),
            'skus' => $this->assertSkuReady($record),
            'recipes' => $this->assertRecipeReady($record, $companyId),
            'routes' => $this->assertRouteReady($record, $companyId),
            'specifications' => $this->assertSpecificationReady($record, $companyId),
        };
    }

    private function assertBrandReady(string $recordId, string $companyId): void
    {
        $invalidAgreement = DB::table('brand_agreements as agreement')
            ->leftJoin('parties as party', function ($join): void {
                $join->on('party.id', '=', 'agreement.party_id')
                    ->on('party.company_id', '=', 'agreement.company_id');
            })
            ->where('agreement.brand_id', $recordId)->where('agreement.company_id', $companyId)
            ->where('agreement.status', 'ACTIVE')->where('party.status', '<>', 'ACTIVE')->exists();
        if ($invalidAgreement) {
            throw ValidationException::withMessages([
                'target_status' => ['Activate every party used by an active agreement before activating this brand.'],
            ]);
        }
    }

    private function assertItemReady(object $record): void
    {
        if ($record->brand_id && ! DB::table('brands')->where('id', $record->brand_id)->where('status', 'ACTIVE')->exists()) {
            throw ValidationException::withMessages(['target_status' => ['Activate the assigned brand before this item.']]);
        }
    }

    private function assertSkuReady(object $record): void
    {
        if (! DB::table('catalog_items')->where('id', $record->catalog_item_id)->where('status', 'ACTIVE')->exists()) {
            throw ValidationException::withMessages(['target_status' => ['Activate the catalog item before this SKU.']]);
        }
        if (DB::table('sku_packs')->where('sku_id', $record->id)->count() === 0) {
            throw ValidationException::withMessages(['target_status' => ['Add at least one pack before activating this SKU.']]);
        }
        if (DB::table('sku_packs')->where('sku_id', $record->id)->where('is_default', true)->count() !== 1) {
            throw ValidationException::withMessages(['target_status' => ['Select exactly one default pack before activation.']]);
        }
    }

    private function assertRecipeReady(object $record, string $companyId): void
    {
        if (! DB::table('items')->where('id', $record->output_sku_id)->where('company_id', $companyId)
            ->where('status', 'ACTIVE')->exists()) {
            throw ValidationException::withMessages(['target_status' => ['Activate the output SKU before this recipe.']]);
        }
        if (! DB::table('recipe_components')->where('recipe_id', $record->id)->exists()) {
            throw ValidationException::withMessages(['target_status' => ['Add at least one component before activation.']]);
        }
        if (DB::table('recipe_components as component')->join('items as sku', function ($join): void {
            $join->on('sku.id', '=', 'component.component_sku_id')
                ->on('sku.company_id', '=', 'component.company_id');
        })->where('component.recipe_id', $record->id)->where('sku.status', '<>', 'ACTIVE')->exists()) {
            throw ValidationException::withMessages(['target_status' => ['Activate every component SKU before this recipe.']]);
        }
    }

    private function assertRouteReady(object $record, string $companyId): void
    {
        if (! DB::table('catalog_items')->where('id', $record->catalog_item_id)->where('company_id', $companyId)
            ->where('status', 'ACTIVE')->exists()) {
            throw ValidationException::withMessages(['target_status' => ['Activate the route item before this route.']]);
        }
        if (! DB::table('route_operations')->where('route_id', $record->id)->exists()) {
            throw ValidationException::withMessages(['target_status' => ['Add at least one operation before activation.']]);
        }
    }

    private function assertSpecificationReady(object $record, string $companyId): void
    {
        $table = $record->target_type === 'ITEM' ? 'catalog_items' : 'items';
        $targetId = $record->target_type === 'ITEM' ? $record->catalog_item_id : $record->sku_id;
        if (! DB::table($table)->where('id', $targetId)->where('company_id', $companyId)
            ->where('status', 'ACTIVE')->exists()) {
            throw ValidationException::withMessages(['target_status' => ['Activate the specification target first.']]);
        }
        if (! DB::table('quality_spec_parameters')->where('specification_id', $record->id)->exists()) {
            throw ValidationException::withMessages(['target_status' => ['Add at least one parameter before activation.']]);
        }
    }

    private function assertSafeToDeactivate(string $resource, string $recordId): void
    {
        $message = match ($resource) {
            'brands' => $this->brandDependency($recordId),
            'items' => $this->itemDependency($recordId),
            'skus' => $this->skuDependency($recordId),
            default => null,
        };
        if ($message !== null) {
            throw ValidationException::withMessages(['target_status' => [$message]]);
        }
    }

    private function brandDependency(string $recordId): ?string
    {
        if (DB::table('brand_agreements')->where('brand_id', $recordId)->where('status', 'ACTIVE')->exists()) {
            return 'End every active agreement before deactivating this brand.';
        }
        if (DB::table('catalog_items')->where('brand_id', $recordId)->where('status', 'ACTIVE')->exists()) {
            return 'Deactivate every branded item before deactivating this brand.';
        }

        return null;
    }

    private function itemDependency(string $recordId): ?string
    {
        if (DB::table('items')->where('catalog_item_id', $recordId)->where('status', 'ACTIVE')->exists()) {
            return 'Deactivate every SKU for this item before deactivating it.';
        }
        if (DB::table('production_routes')->where('catalog_item_id', $recordId)->where('status', 'ACTIVE')->exists()) {
            return 'Deactivate every production route for this item first.';
        }
        if (DB::table('quality_specifications')->where('catalog_item_id', $recordId)->where('status', 'ACTIVE')->exists()) {
            return 'Deactivate every active item specification first.';
        }

        return null;
    }

    private function skuDependency(string $recordId): ?string
    {
        if (DB::table('stock_positions')->where('item_id', $recordId)->where('quantity_base', '>', 0)->exists()) {
            return 'Move or consume all stock for this SKU before deactivating it.';
        }
        if (DB::table('lots')->where('item_id', $recordId)->where('status', 'ACTIVE')->exists()) {
            return 'Close or recall every active lot for this SKU before deactivating it.';
        }
        if (DB::table('recipes')->where('output_sku_id', $recordId)->where('status', 'ACTIVE')->exists()
            || DB::table('recipe_components as component')->join('recipes as recipe', 'recipe.id', '=', 'component.recipe_id')
                ->where('component.component_sku_id', $recordId)->where('recipe.status', 'ACTIVE')->exists()) {
            return 'Deactivate every active recipe using this SKU first.';
        }
        if (DB::table('quality_specifications')->where('sku_id', $recordId)->where('status', 'ACTIVE')->exists()) {
            return 'Deactivate every active SKU specification first.';
        }

        return null;
    }

    private function assertSafeStructuralUpdate(string $resource, object $record, array $data): void
    {
        if ($resource === 'items'
            && ($record->item_type !== $data['item_type'] || $record->base_uom !== $data['base_uom'])
            && DB::table('items')->where('catalog_item_id', $record->id)->exists()) {
            throw ValidationException::withMessages([
                'base_uom' => ['Item type and base UOM cannot change after a SKU has been created.'],
            ]);
        }
        if ($resource === 'skus' && $record->catalog_item_id !== $data['catalog_item_id']) {
            $used = DB::table('stock_positions')->where('item_id', $record->id)->exists()
                || DB::table('recipe_components')->where('component_sku_id', $record->id)->exists()
                || DB::table('recipes')->where('output_sku_id', $record->id)->exists();
            if ($used) {
                throw ValidationException::withMessages([
                    'catalog_item_id' => ['A referenced SKU cannot be reassigned to another catalog item.'],
                ]);
            }
        }
    }

    private function assertCodeAvailable(string $table, string $companyId, string $code): void
    {
        if (DB::table($table)->where('company_id', $companyId)->where('code', $code)->exists()) {
            throw ValidationException::withMessages(['code' => ['That code already exists in the selected company.']]);
        }
    }

    private function assertChildUniqueValuesAvailable(string $resource, array $data, ?string $recordId): void
    {
        $checks = match ($resource) {
            'brands' => [['brand_agreements', 'agreement_number', 'agreements', 'agreement_number', 'brand_id']],
            'skus' => [
                ['sku_packs', 'code', 'packs', 'code', 'sku_id'],
                ['sku_packs', 'barcode', 'packs', 'barcode', 'sku_id'],
                ['items', 'barcode', null, 'barcode', 'id'],
            ],
            default => [],
        };
        foreach ($checks as [$table, $column, $childField, $inputField, $ownerColumn]) {
            $values = $childField === null
                ? [$data[$inputField] ?? null]
                : collect($data[$childField])->pluck($inputField)->all();
            foreach ($values as $index => $value) {
                $value = $this->nullable($value);
                if ($value === null) {
                    continue;
                }
                $query = DB::table($table)->where('company_id', $data['company_id'])->where($column, $value);
                if ($recordId !== null) {
                    $query->where($ownerColumn, '<>', $recordId);
                }
                if ($query->exists()) {
                    $field = $childField === null ? $inputField : "{$childField}.{$index}.{$inputField}";
                    throw ValidationException::withMessages([$field => ['That value already exists in this company.']]);
                }
            }
        }
    }

    private function assertOwnedChildIds(
        array $definition,
        string $recordId,
        string $companyId,
        array $rows,
    ): void {
        $ids = collect($rows)->pluck('id')->filter()->values();
        if ($ids->isEmpty()) {
            return;
        }
        if ($ids->count() !== $ids->unique()->count()) {
            throw ValidationException::withMessages([
                $definition['children'] => ['A child record cannot be submitted more than once.'],
            ]);
        }
        $owned = DB::table($definition['child_table'])->where($definition['child_key'], $recordId)
            ->where('company_id', $companyId)->whereIn('id', $ids)->count();
        if ($owned !== $ids->count()) {
            throw ValidationException::withMessages([
                $definition['children'] => ['One or more submitted child records do not belong to this record.'],
            ]);
        }
    }

    private function assertUniqueChildValues(array $rows, string $field, string $root): void
    {
        $seen = [];
        foreach ($rows as $index => $row) {
            $value = (string) ($row[$field] ?? '');
            if (isset($seen[$value])) {
                throw ValidationException::withMessages([
                    "{$root}.{$index}.{$field}" => ['That value is duplicated in this record.'],
                ]);
            }
            $seen[$value] = true;
        }
    }

    private function assertDateRange(mixed $from, mixed $to, string $field): void
    {
        if ($from !== null && $from !== '' && $to !== null && $to !== ''
            && CarbonImmutable::parse((string) $to)->lt(CarbonImmutable::parse((string) $from))) {
            throw ValidationException::withMessages([$field => ['The end date must be on or after the start date.']]);
        }
    }

    private function scopedSku(string $id, string $companyId): ?object
    {
        return DB::table('items')->where('id', $id)->where('company_id', $companyId)->first(['id', 'status']);
    }

    private function findLocked(array $definition, string $recordId, string $companyId): object
    {
        $record = DB::table($definition['table'])->where('id', $recordId)->where('company_id', $companyId)
            ->lockForUpdate()->first();
        if (! $record) {
            throw new NotFoundHttpException($definition['label'].' not found.');
        }

        return $record;
    }

    private function assertVersion(array $definition, object $record, int $expectedVersion): void
    {
        if ((int) $record->record_version !== $expectedVersion) {
            throw new ConflictHttpException(
                "The {$definition['label']} changed from version {$expectedVersion} to {$record->record_version}. Refresh it before saving."
            );
        }
    }

    private function result(array $definition, string $recordId, string $status, int $version, ?int $childCount = null): array
    {
        $result = [
            'entity_type' => $definition['entity'],
            'id' => $recordId,
            'status' => $status,
            'record_version' => $version,
        ];
        if ($childCount !== null) {
            $result[$definition['children'].'_count'] = $childCount;
        }

        return $result;
    }

    private function record(
        array $definition,
        string $verb,
        string $recordId,
        array $data,
        int $version,
        array $safeDiff,
        array $result,
    ): void {
        $commandEntity = strtoupper($definition['entity']);
        $this->audit->record($verb.'_'.$commandEntity, $definition['entity'], $recordId, $data['actor_id'],
            $data['company_id'], $data['plant_id'], 'SUCCESS', [
                'entity_version' => $version,
                'correlation_id' => $data['correlation_id'] ?? null,
                'safe_diff' => $safeDiff,
            ]);
        $eventSuffix = match ($verb) {
            'CREATE' => 'created',
            'UPDATE' => 'updated',
            'DELETE' => 'deleted',
            default => 'status_changed',
        };
        $this->outbox->append($definition['event'].'.'.$eventSuffix, $definition['entity'], $recordId,
            $recordId.':'.$version, $result, $data['correlation_id'] ?? null,
            $data['company_id'], $data['plant_id']);
    }

    private function idempotencyPayload(array $data): array
    {
        return collect($data)->except(['actor_id', 'permissions', 'idempotency_key', 'correlation_id'])->all();
    }

    private function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}

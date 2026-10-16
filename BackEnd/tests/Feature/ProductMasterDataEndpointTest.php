<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProductMasterDataEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const ADMIN_ID = '00000000-0000-4000-8000-000000000204';
    private const OPERATIONS_ID = '00000000-0000-4000-8000-000000000202';
    private const SALES_ID = '00000000-0000-4000-8000-000000000201';
    private const PARTY_ID = '00000000-0000-4000-8000-000000000501';
    private const BRAND_ID = '00000000-0000-4000-8000-000000000901';
    private const ITEM_ID = '00000000-0000-4000-8000-000000000591';
    private const RAW_ITEM_ID = '00000000-0000-4000-8000-000000000593';
    private const SKU_ID = '00000000-0000-4000-8000-000000000601';
    private const RAW_SKU_ID = '00000000-0000-4000-8000-000000000603';
    private const RECIPE_ID = '00000000-0000-4000-8000-000000000911';
    private const ROUTE_ID = '00000000-0000-4000-8000-000000000921';
    private const SPEC_ID = '00000000-0000-4000-8000-000000000931';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->signIn(self::ADMIN_ID);
    }

    public function test_all_six_workspaces_are_live_filterable_and_return_complete_aggregates(): void
    {
        $this->getJson('/api/v1/master/brands?q=DIST-2026')
            ->assertOk()->assertJsonPath('resource', 'brands')->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.code', 'QT-NATURALS')->assertJsonPath('data.0.child_count', 1)
            ->assertJsonPath('allowed_actions.0', 'CREATE');
        $this->getJson('/api/v1/master/brands/'.self::BRAND_ID)
            ->assertOk()->assertJsonPath('data.agreements.0.agreement_number', 'DIST-2026-001')
            ->assertJsonPath('data.agreements.0.party_id', self::PARTY_ID);

        $this->getJson('/api/v1/master/items?type=FINISHED_GOOD&sort=NAME')
            ->assertOk()->assertJsonPath('meta.total', 2)->assertJsonPath('summary.total', 3)
            ->assertJsonPath('data.0.parent.code', 'QT-NATURALS');
        $this->getJson('/api/v1/master/items/'.self::ITEM_ID)
            ->assertOk()->assertJsonPath('data.base_uom', 'PACK')
            ->assertJsonPath('data.conversions.0.from_uom_code', 'CASE');

        $this->getJson('/api/v1/master/skus?type=RAW_MATERIAL')
            ->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.id', self::RAW_SKU_ID);
        $this->getJson('/api/v1/master/skus/'.self::SKU_ID)
            ->assertOk()->assertJsonPath('data.parent.code', 'ITEM-APPLE-SNACK')
            ->assertJsonPath('data.packs.0.is_default', true);

        $this->getJson('/api/v1/manufacturing/recipes/'.self::RECIPE_ID)
            ->assertOk()->assertJsonPath('data.output_sku_id', self::SKU_ID)
            ->assertJsonPath('data.components.0.component_sku_id', self::RAW_SKU_ID);
        $this->getJson('/api/v1/manufacturing/routes/'.self::ROUTE_ID)
            ->assertOk()->assertJsonCount(3, 'data.operations')
            ->assertJsonPath('data.operations.2.work_center_code', 'PACK-01');
        $this->getJson('/api/v1/quality/specifications/'.self::SPEC_ID)
            ->assertOk()->assertJsonPath('data.target_type', 'SKU')
            ->assertJsonPath('data.parameters.0.code', 'NET-WEIGHT')
            ->assertJsonPath('data.parameters.0.target_value', '100.000000');

        $this->getJson('/api/v1/master/items/'.Str::uuid())
            ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_brand_aggregate_commands_are_idempotent_versioned_audited_and_scope_safe(): void
    {
        $payload = $this->brandPayload('FRESH-BRAND', 'AGR-FRESH-001');
        $key = (string) Str::uuid();
        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/master/brands', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.agreements_count', 1);
        $brandId = $first->json('data.id');
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/master/brands', $payload)
            ->assertCreated()->assertExactJson($first->json());

        $detail = $this->getJson('/api/v1/master/brands/'.$brandId)->assertOk()->json('data');
        $update = [
            'name' => 'Fresh Brand Updated',
            'description' => 'Updated description.',
            'agreements' => $detail['agreements'],
        ];
        $update['agreements'][0]['minimum_commitment'] = '80000';
        $this->withHeaders($this->headers(1))->postJson('/api/v1/master/brands/'.$brandId, $update)
            ->assertOk()->assertJsonPath('data.record_version', 2);
        $this->assertDatabaseHas('brand_agreements', [
            'id' => $detail['agreements'][0]['id'], 'brand_id' => $brandId, 'minimum_commitment' => '80000.00',
        ]);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/master/brands/'.$brandId, $update)
            ->assertConflict()->assertJsonPath('error.code', 'CONFLICT');

        $this->assertDatabaseHas('audit_events', [
            'command' => 'CREATE_BRAND', 'entity_id' => $brandId, 'company_id' => self::COMPANY_ID,
            'plant_id' => self::PLANT_ID,
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'master.brand.created', 'aggregate_id' => $brandId,
        ]);

        $duplicate = $this->brandPayload('OTHER-BRAND', 'DIST-2026-001');
        $duplicateResponse = $this->command()->postJson('/api/v1/master/brands', $duplicate)
            ->assertUnprocessable();
        $this->assertSame('That value already exists in this company.',
            $duplicateResponse->json('error.fields')['agreements.0.agreement_number'][0]);
        $foreignParty = $this->brandPayload('FOREIGN-PARTY-BRAND', 'AGR-FRESH-002');
        $foreignParty['agreements'][0]['party_id'] = (string) Str::uuid();
        $foreignResponse = $this->command()->postJson('/api/v1/master/brands', $foreignParty)
            ->assertUnprocessable();
        $this->assertSame('Select a party from the current company.',
            $foreignResponse->json('error.fields')['agreements.0.party_id'][0]);
    }

    public function test_item_and_uom_conversion_commands_preserve_children_and_protect_stock_contracts(): void
    {
        $created = $this->command()->postJson('/api/v1/master/items', $this->itemPayload())
            ->assertCreated()->assertJsonPath('data.conversions_count', 1);
        $itemId = $created->json('data.id');
        $detail = $this->getJson('/api/v1/master/items/'.$itemId)->assertOk()
            ->assertJsonPath('data.item_type', 'RAW_MATERIAL')->json('data');
        $update = $this->itemUpdate($detail);
        $update['description'] = 'Approved dry ingredient.';
        $update['conversions'][0]['multiplier'] = '1000.5';
        $this->withHeaders($this->headers(1))->postJson('/api/v1/master/items/'.$itemId, $update)
            ->assertOk()->assertJsonPath('data.record_version', 2);
        $this->assertDatabaseHas('item_uom_conversions', [
            'id' => $detail['conversions'][0]['id'], 'catalog_item_id' => $itemId,
        ]);

        $bad = $this->itemPayload('BAD-CONVERSION');
        $bad['conversions'][0]['from_uom_code'] = 'CASE';
        $badResponse = $this->command()->postJson('/api/v1/master/items', $bad)
            ->assertUnprocessable();
        $this->assertSame('Each conversion must include the item base UOM.',
            $badResponse->json('error.fields')['conversions.0.from_uom_code'][0]);

        $seeded = $this->getJson('/api/v1/master/items/'.self::ITEM_ID)->assertOk()->json('data');
        $seededUpdate = $this->itemUpdate($seeded);
        $seededUpdate['base_uom'] = 'EA';
        $this->withHeaders($this->headers(1))->postJson('/api/v1/master/items/'.self::ITEM_ID, $seededUpdate)
            ->assertUnprocessable()->assertJsonPath(
                'error.fields.base_uom.0',
                'Item type and base UOM cannot change after a SKU has been created.',
            );
    }

    public function test_sku_and_pack_commands_enforce_parent_completeness_uniqueness_and_stock_safety(): void
    {
        $payload = $this->skuPayload('SKU-NEW-APPLE', 'PACK-NEW-APPLE', '8901999999001');
        $created = $this->command()->postJson('/api/v1/master/skus', $payload)
            ->assertCreated()->assertJsonPath('data.packs_count', 1);
        $skuId = $created->json('data.id');
        $this->assertDatabaseHas('items', [
            'id' => $skuId, 'catalog_item_id' => self::ITEM_ID,
            'item_type' => 'FINISHED_GOOD', 'base_uom' => 'PACK',
        ]);
        $detail = $this->getJson('/api/v1/master/skus/'.$skuId)->assertOk()->json('data');
        $update = $this->skuUpdate($detail);
        $update['packs'][0]['name'] = 'Updated retail pack';
        $this->withHeaders($this->headers(1))->postJson('/api/v1/master/skus/'.$skuId, $update)
            ->assertOk()->assertJsonPath('data.record_version', 2);
        $this->assertDatabaseHas('sku_packs', [
            'id' => $detail['packs'][0]['id'], 'sku_id' => $skuId, 'name' => 'Updated retail pack',
        ]);

        $draft = $this->skuPayload('SKU-DRAFT-NO-PACK', 'UNUSED', '8901999999002', 'DRAFT');
        $draft['packs'] = [];
        $draftId = $this->command()->postJson('/api/v1/master/skus', $draft)
            ->assertCreated()->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/master/skus/'.$draftId.'/status', [
            'target_status' => 'ACTIVE', 'reason' => 'Ready for use.',
        ])->assertUnprocessable()->assertJsonPath(
            'error.fields.target_status.0', 'Add at least one pack before activating this SKU.',
        );

        $duplicate = $this->skuPayload('SKU-DUP-BARCODE', 'PACK-DUP-BARCODE', '8901000000011');
        $this->command()->postJson('/api/v1/master/skus', $duplicate)
            ->assertUnprocessable()->assertJsonPath('error.fields.barcode.0', 'That value already exists in this company.');

        $duplicatePackBarcode = $this->skuPayload('SKU-DUP-PACK-BARCODE', 'PACK-DUP-PACK-A', '8901999999010');
        $duplicatePackBarcode['packs'][] = [
            'code' => 'PACK-DUP-PACK-B', 'name' => 'Alternate pack', 'uom_code' => 'PACK', 'quantity' => '2',
            'barcode' => $duplicatePackBarcode['packs'][0]['barcode'], 'is_default' => false,
        ];
        $duplicatePackResponse = $this->command()->postJson('/api/v1/master/skus', $duplicatePackBarcode)
            ->assertUnprocessable();
        $this->assertSame('Pack barcodes must be unique.',
            $duplicatePackResponse->json('error.fields')['packs.1.barcode'][0]);

        DB::table('stock_positions')->where('item_id', self::SKU_ID)->limit(1)->update(['quantity_base' => 5]);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/master/skus/'.self::SKU_ID.'/status', [
            'target_status' => 'INACTIVE', 'reason' => 'Attempt stock retirement.',
        ])->assertUnprocessable()->assertJsonPath(
            'error.fields.target_status.0', 'Move or consume all stock for this SKU before deactivating it.',
        );
    }

    public function test_recipe_commands_validate_bom_references_and_lifecycle(): void
    {
        $payload = $this->recipePayload('REC-FRESH-APPLE');
        $created = $this->command()->postJson('/api/v1/manufacturing/recipes', $payload)
            ->assertCreated()->assertJsonPath('data.components_count', 1);
        $recipeId = $created->json('data.id');
        $detail = $this->getJson('/api/v1/manufacturing/recipes/'.$recipeId)->assertOk()->json('data');
        $update = $this->recipeUpdate($detail);
        $update['yield_percent'] = '97.75';
        $update['components'][0]['waste_percent'] = '2.25';
        $this->withHeaders($this->headers(1))->postJson('/api/v1/manufacturing/recipes/'.$recipeId, $update)
            ->assertOk()->assertJsonPath('data.record_version', 2);
        $this->assertDatabaseHas('recipe_components', [
            'id' => $detail['components'][0]['id'], 'recipe_id' => $recipeId,
        ]);

        $self = $this->recipePayload('REC-SELF');
        $self['components'][0]['component_sku_id'] = self::SKU_ID;
        $selfResponse = $this->command()->postJson('/api/v1/manufacturing/recipes', $self)
            ->assertUnprocessable();
        $this->assertSame('A recipe cannot consume its own output SKU.',
            $selfResponse->json('error.fields')['components.0.component_sku_id'][0]);

        $draft = $this->recipePayload('REC-DRAFT', 'DRAFT');
        $draft['components'] = [];
        $draftId = $this->command()->postJson('/api/v1/manufacturing/recipes', $draft)
            ->assertCreated()->json('data.id');
        $key = (string) Str::uuid();
        $this->withHeaders(['Idempotency-Key' => $key, 'If-Match' => '1'])
            ->postJson('/api/v1/manufacturing/recipes/'.$draftId.'/status', [
                'target_status' => 'ACTIVE', 'reason' => 'Attempt incomplete activation.',
            ])->assertUnprocessable()->assertJsonPath(
                'error.fields.target_status.0', 'Add at least one component before activation.',
            );
    }

    public function test_generic_recipe_preview_scales_non_one_hundred_basis_and_expected_output(): void
    {
        $payload = $this->recipePayload('REC-GENERIC-YIELD');
        $payload += [
            'basis_quantity' => '50', 'basis_uom_code' => 'KG', 'calculation_model' => 'WEIGHT_PIECE',
            'standard_piece_weight' => '0.1', 'piece_weight_uom_code' => 'KG',
            'target_finished_weight' => '0.095', 'target_weight_uom_code' => 'KG', 'pieces_per_pack' => 1,
            'handling_loss_percent' => '2', 'handling_loss_in_standard' => false,
            'transformation_loss_percent' => '6', 'transformation_loss_in_standard' => true,
            'rejection_allowance_percent' => '1', 'output_rounding_mode' => 'DOWN',
            'unit_conversion_rate' => null,
        ];
        $payload['yield_percent'] = '100';
        $payload['components'][0] += [
            'scaling_method' => 'SCALABLE', 'bakers_percentage' => null,
            'is_basis_component' => true, 'condition_code' => null, 'quantity_per_unit' => null,
            'loss_included_in_standard' => true, 'is_packaging' => false,
        ];
        $payload['components'][0]['quantity'] = '50';

        $recipeId = (string) $this->command()->postJson('/api/v1/manufacturing/recipes', $payload)
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/manufacturing/recipes/'.$recipeId.'/preview', [
            'batch_basis_quantity' => '75', 'planned_units' => null, 'active_conditions' => [],
            'measured_additions' => [['quantity' => '5', 'uom_code' => 'KG']],
        ])->assertOk()->assertJsonPath('data.recipe_basis_quantity', '50.000000')
            ->assertJsonPath('data.batch_basis_quantity', '75.000000')
            ->assertJsonPath('data.scaling_factor', '1.500000000')
            ->assertJsonPath('data.requirements.0.calculated_quantity', '75.000000')
            ->assertJsonPath('data.measured_additions_mass', '5.000000')
            ->assertJsonPath('data.theoretical_input_mass', '80.000000')
            ->assertJsonPath('data.theoretical_complete_units', '800.000000')
            ->assertJsonPath('data.expected_formed_units', '784.000000')
            ->assertJsonPath('data.expected_good_units', '776.000000')
            ->assertJsonPath('data.transformation_loss_deducted_from_piece_count', false);
    }

    public function test_route_and_specification_aggregates_enforce_operations_parameters_and_targets(): void
    {
        $route = $this->command()->postJson('/api/v1/manufacturing/routes', $this->routePayload())
            ->assertCreated()->assertJsonPath('data.operations_count', 2);
        $routeId = $route->json('data.id');
        $routeDetail = $this->getJson('/api/v1/manufacturing/routes/'.$routeId)->assertOk()->json('data');
        $routeUpdate = $this->routeUpdate($routeDetail);
        $routeUpdate['operations'][0]['setup_minutes'] = '25';
        $this->withHeaders($this->headers(1))->postJson('/api/v1/manufacturing/routes/'.$routeId, $routeUpdate)
            ->assertOk()->assertJsonPath('data.record_version', 2);
        $this->assertDatabaseHas('route_operations', ['id' => $routeDetail['operations'][0]['id'], 'route_id' => $routeId]);

        $spec = $this->command()->postJson('/api/v1/quality/specifications', $this->specPayload())
            ->assertCreated()->assertJsonPath('data.parameters_count', 1);
        $specId = $spec->json('data.id');
        $specDetail = $this->getJson('/api/v1/quality/specifications/'.$specId)->assertOk()->json('data');
        $specUpdate = $this->specUpdate($specDetail);
        $specUpdate['parameters'][0]['maximum_value'] = '105';
        $this->withHeaders($this->headers(1))->postJson('/api/v1/quality/specifications/'.$specId, $specUpdate)
            ->assertOk()->assertJsonPath('data.record_version', 2);
        $this->assertDatabaseHas('quality_spec_parameters', [
            'id' => $specDetail['parameters'][0]['id'], 'specification_id' => $specId,
        ]);

        $badTarget = $this->specPayload('SPEC-BAD-TARGET');
        $badTarget['catalog_item_id'] = self::ITEM_ID;
        $this->command()->postJson('/api/v1/quality/specifications', $badTarget)
            ->assertUnprocessable()->assertJsonPath(
                'error.fields.sku_id.0', 'SKU specifications require exactly one SKU target.',
            );
        $badRange = $this->specPayload('SPEC-BAD-RANGE');
        $badRange['parameters'][0]['minimum_value'] = '110';
        $badRangeResponse = $this->command()->postJson('/api/v1/quality/specifications', $badRange)
            ->assertUnprocessable();
        $this->assertSame('Maximum value must be greater than or equal to minimum value.',
            $badRangeResponse->json('error.fields')['parameters.0.maximum_value'][0]);
    }

    public function test_dependencies_and_screen_action_permissions_are_enforced(): void
    {
        $this->withHeaders($this->headers(1))->postJson('/api/v1/master/brands/'.self::BRAND_ID.'/status', [
            'target_status' => 'INACTIVE', 'reason' => 'Attempt brand retirement.',
        ])->assertUnprocessable()->assertJsonPath(
            'error.fields.target_status.0', 'End every active agreement before deactivating this brand.',
        );
        $this->withHeaders($this->headers(1))->postJson('/api/v1/master/items/'.self::ITEM_ID.'/status', [
            'target_status' => 'INACTIVE', 'reason' => 'Attempt item retirement.',
        ])->assertUnprocessable()->assertJsonPath(
            'error.fields.target_status.0', 'Deactivate every SKU for this item before deactivating it.',
        );

        $this->signIn(self::OPERATIONS_ID);
        foreach (['master/brands', 'master/items', 'master/skus', 'manufacturing/recipes',
            'manufacturing/routes', 'quality/specifications'] as $path) {
            $this->getJson('/api/v1/'.$path)->assertOk()->assertJsonPath('allowed_actions.0', 'CREATE');
        }
        $this->command()->postJson('/api/v1/master/items', $this->itemPayload('OPS-ITEM'))->assertCreated();

        $this->signIn(self::SALES_ID);
        $this->getJson('/api/v1/master/brands')->assertForbidden();
        $this->command()->postJson('/api/v1/master/brands', [])->assertForbidden();
    }

    private function brandPayload(string $code, string $agreementNumber): array
    {
        return [
            'code' => $code, 'name' => 'Fresh Foods Brand', 'description' => 'Managed brand.', 'status' => 'ACTIVE',
            'agreements' => [[
                'party_id' => self::PARTY_ID, 'agreement_number' => $agreementNumber,
                'agreement_type' => 'DISTRIBUTION', 'effective_from' => '2026-04-01',
                'effective_to' => '2027-03-31', 'currency_code' => 'INR',
                'minimum_commitment' => '75000', 'status' => 'ACTIVE', 'notes' => 'Regional terms.',
            ]],
        ];
    }

    private function itemPayload(string $code = 'ITEM-OAT-BASE'): array
    {
        return [
            'code' => $code, 'name' => 'Oat Ingredient Base', 'brand_id' => null,
            'item_type' => 'RAW_MATERIAL', 'base_uom' => 'KG', 'description' => 'Dry ingredient.',
            'shelf_life_days' => 120, 'lot_controlled' => true, 'status' => 'ACTIVE',
            'conversions' => [[
                'from_uom_code' => 'KG', 'to_uom_code' => 'G', 'multiplier' => '1000',
                'rounding_mode' => 'HALF_UP',
            ]],
        ];
    }

    private function itemUpdate(array $detail): array
    {
        return [
            'name' => $detail['name'], 'brand_id' => $detail['brand_id'], 'item_type' => $detail['item_type'],
            'base_uom' => $detail['base_uom'], 'description' => $detail['description'],
            'shelf_life_days' => $detail['shelf_life_days'], 'lot_controlled' => $detail['lot_controlled'],
            'conversions' => $detail['conversions'],
        ];
    }

    private function skuPayload(string $code, string $packCode, string $barcode, string $status = 'ACTIVE'): array
    {
        return [
            'code' => $code, 'name' => 'New Apple Snack SKU', 'catalog_item_id' => self::ITEM_ID,
            'barcode' => $barcode, 'description' => 'Retail pack.', 'pack_quantity' => '1',
            'pack_uom_code' => 'PACK', 'status' => $status,
            'packs' => [[
                'code' => $packCode, 'name' => 'Retail pack', 'uom_code' => 'PACK', 'quantity' => '1',
                'barcode' => $barcode.'9', 'is_default' => true,
            ]],
        ];
    }

    private function skuUpdate(array $detail): array
    {
        return [
            'name' => $detail['name'], 'catalog_item_id' => $detail['catalog_item_id'],
            'barcode' => $detail['barcode'], 'description' => $detail['description'],
            'pack_quantity' => $detail['pack_quantity'], 'pack_uom_code' => $detail['pack_uom_code'],
            'packs' => $detail['packs'],
        ];
    }

    private function recipePayload(string $code, string $status = 'ACTIVE'): array
    {
        return [
            'code' => $code, 'name' => 'Fresh Apple Recipe', 'revision' => 1,
            'output_sku_id' => self::SKU_ID, 'output_quantity' => '1', 'output_uom_code' => 'PACK',
            'yield_percent' => '98.5', 'effective_from' => '2026-04-01', 'effective_to' => null,
            'notes' => 'Controlled BOM.', 'status' => $status,
            'components' => [[
                'component_sku_id' => self::RAW_SKU_ID, 'sequence_no' => 10,
                'quantity' => '0.1', 'uom_code' => 'KG', 'waste_percent' => '1.5',
            ]],
        ];
    }

    private function recipeUpdate(array $detail): array
    {
        return [
            'name' => $detail['name'], 'revision' => $detail['revision'],
            'output_sku_id' => $detail['output_sku_id'], 'output_quantity' => $detail['output_quantity'],
            'output_uom_code' => $detail['output_uom_code'], 'yield_percent' => $detail['yield_percent'],
            'effective_from' => $detail['effective_from'], 'effective_to' => $detail['effective_to'],
            'notes' => $detail['notes'], 'components' => $detail['components'],
        ];
    }

    private function routePayload(): array
    {
        return [
            'code' => 'ROUTE-FRESH-APPLE', 'name' => 'Fresh Apple Route',
            'catalog_item_id' => self::ITEM_ID, 'description' => 'Mix and pack.', 'status' => 'ACTIVE',
            'operations' => [
                ['sequence_no' => 10, 'name' => 'Mix', 'work_center_code' => 'MIX-01',
                    'setup_minutes' => '20', 'run_minutes_per_unit' => '0.05', 'instructions' => 'Mix evenly.'],
                ['sequence_no' => 20, 'name' => 'Pack', 'work_center_code' => 'PACK-01',
                    'setup_minutes' => '10', 'run_minutes_per_unit' => '0.03', 'instructions' => 'Seal packs.'],
            ],
        ];
    }

    private function routeUpdate(array $detail): array
    {
        return [
            'name' => $detail['name'], 'catalog_item_id' => $detail['catalog_item_id'],
            'description' => $detail['description'], 'operations' => $detail['operations'],
        ];
    }

    private function specPayload(string $code = 'SPEC-FRESH-APPLE'): array
    {
        return [
            'code' => $code, 'name' => 'Fresh Apple Pack Standard', 'target_type' => 'SKU',
            'catalog_item_id' => null, 'sku_id' => self::SKU_ID, 'effective_from' => '2026-04-01',
            'effective_to' => null, 'sampling_plan' => 'Five packs per lot', 'notes' => 'Release standard.',
            'status' => 'ACTIVE',
            'parameters' => [[
                'sequence_no' => 10, 'code' => 'NET-WEIGHT', 'name' => 'Net weight',
                'value_type' => 'NUMERIC', 'uom_code' => 'G', 'minimum_value' => '98',
                'target_value' => '100', 'maximum_value' => '102', 'text_requirement' => null,
                'test_method' => 'Bench scale', 'is_required' => true,
            ]],
        ];
    }

    private function specUpdate(array $detail): array
    {
        return [
            'name' => $detail['name'], 'target_type' => $detail['target_type'],
            'catalog_item_id' => $detail['catalog_item_id'], 'sku_id' => $detail['sku_id'],
            'effective_from' => $detail['effective_from'], 'effective_to' => $detail['effective_to'],
            'sampling_plan' => $detail['sampling_plan'], 'notes' => $detail['notes'],
            'parameters' => $detail['parameters'],
        ];
    }

    private function signIn(string $userId): void
    {
        $this->actingAs(User::query()->findOrFail($userId))->withSession([
            'erp.company_id' => self::COMPANY_ID,
            'erp.plant_id' => self::PLANT_ID,
        ]);
    }

    private function command(): static
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid());
    }

    private function headers(int $version): array
    {
        return ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => (string) $version];
    }
}

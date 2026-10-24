<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MultiPlantScaleEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = '00000000-0000-4000-8000-000000000001';
    private const SOURCE_PLANT = '00000000-0000-4000-8000-000000000101';
    private const DESTINATION_PLANT = '00000000-0000-4000-8000-000000000102';
    private const OPERATIONS = '00000000-0000-4000-8000-000000000202';
    private const FINANCE = '00000000-0000-4000-8000-000000000203';
    private const ADMIN = '00000000-0000-4000-8000-000000000204';
    private const ROUTE = '00000000-0000-4000-8000-000000003011';
    private const GROUP = '00000000-0000-4000-8000-000000003001';
    private const SOURCE_POSITION = '00000000-0000-4000-8000-000000002602';
    private const DESTINATION_POSITION = '00000000-0000-4000-8000-000000003021';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->signIn(self::OPERATIONS);
    }

    public function test_interplant_handoff_posts_separate_source_and_destination_movements(): void
    {
        $sourceBefore = (string) DB::table('stock_positions')->where('id', self::SOURCE_POSITION)->value('quantity_base');
        $destinationBefore = (string) DB::table('stock_positions')->where('id', self::DESTINATION_POSITION)->value('quantity_base');

        $workspace = $this->getJson('/api/v1/scale/plants')->assertOk()
            ->assertJsonPath('routes.0.route_code', 'TRAINING-TO-FIN')
            ->assertJsonPath('routes.0.status', 'ACTIVE')
            ->assertJsonFragment(['TRANSFER-CREATE']);
        $this->assertNotEmpty($workspace->json('lookups.source_positions'));
        $this->assertNotEmpty($workspace->json('lookups.destination_positions'));

        $created = $this->command()->postJson('/api/v1/scale/transfers', [
            'transfer_number' => 'XFER-SCALE-001',
            'plant_transfer_route_id' => self::ROUTE,
            'transfer_date' => '2026-09-15',
            'expected_arrival_date' => '2026-09-16',
            'commercial_reference' => null,
            'notes' => 'Move saleable stock between governed company plants.',
            'lines' => [[
                'source_position_id' => self::SOURCE_POSITION,
                'destination_position_id' => self::DESTINATION_POSITION,
                'quantity_base' => '5',
                'notes' => 'Sealed pallet quantity.',
            ]],
        ])->assertCreated()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.record_version', 1);
        $transferId = (string) $created->json('data.id');

        $this->withHeaders($this->headers(1))->postJson("/api/v1/scale/transfers/{$transferId}/submit")
            ->assertOk()->assertJsonPath('data.status', 'SUBMITTED')->assertJsonPath('data.record_version', 2);
        $this->withHeaders($this->headers(2))->postJson("/api/v1/scale/transfers/{$transferId}/approve")
            ->assertForbidden();

        $this->signIn(self::ADMIN);
        $this->withHeaders($this->headers(2))->postJson("/api/v1/scale/transfers/{$transferId}/approve")
            ->assertOk()->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.record_version', 3);

        $sourceItem = DB::table('stock_positions')->where('id', self::SOURCE_POSITION)->value('item_id');
        DB::table('items')->where('id', $sourceItem)->update(['status' => 'INACTIVE']);
        $this->withHeaders($this->headers(3))->postJson("/api/v1/scale/transfers/{$transferId}/dispatch")
            ->assertUnprocessable()->assertJsonPath(
                'error.fields.source_position_id.0',
                'Transfer stock must be released, active, located in an active store, and unexpired.',
            );
        DB::table('items')->where('id', $sourceItem)->update(['status' => 'ACTIVE']);

        $dispatched = $this->withHeaders($this->headers(3))->postJson("/api/v1/scale/transfers/{$transferId}/dispatch")
            ->assertOk()->assertJsonPath('data.status', 'IN_TRANSIT')->assertJsonPath('data.record_version', 4);
        $this->assertCount(1, $dispatched->json('data.movement_ids'));
        $this->assertSame('495.000000', bcadd((string) DB::table('stock_positions')->where('id', self::SOURCE_POSITION)->value('quantity_base'), '0', 6));
        $this->assertSame(bcadd($destinationBefore, '0', 6), bcadd((string) DB::table('stock_positions')->where('id', self::DESTINATION_POSITION)->value('quantity_base'), '0', 6));

        $this->withHeaders($this->headers(4))->postJson("/api/v1/scale/transfers/{$transferId}/receive")
            ->assertStatus(409);
        $this->signIn(self::ADMIN, self::DESTINATION_PLANT);
        $this->getJson("/api/v1/scale/transfers/{$transferId}")->assertOk()
            ->assertJsonPath('data.side', 'DESTINATION')->assertJsonPath('data.allowed_actions.0', 'RECEIVE');

        $destinationLocation = DB::table('stock_positions')->where('id', self::DESTINATION_POSITION)->value('location_id');
        DB::table('locations')->where('id', $destinationLocation)->update(['status' => 'INACTIVE']);
        $this->withHeaders($this->headers(4))->postJson("/api/v1/scale/transfers/{$transferId}/receive")
            ->assertUnprocessable()->assertJsonPath(
                'error.fields.destination_position_id.0',
                'Transfer stock must be released, active, located in an active store, and unexpired.',
            );
        DB::table('locations')->where('id', $destinationLocation)->update(['status' => 'ACTIVE']);

        $this->withHeaders($this->headers(4))->postJson("/api/v1/scale/transfers/{$transferId}/receive")
            ->assertOk()->assertJsonPath('data.status', 'RECEIVED')->assertJsonPath('data.record_version', 5);

        $this->assertSame(bcsub($sourceBefore, '5', 6), bcadd((string) DB::table('stock_positions')->where('id', self::SOURCE_POSITION)->value('quantity_base'), '0', 6));
        $this->assertSame(bcadd($destinationBefore, '5', 6), bcadd((string) DB::table('stock_positions')->where('id', self::DESTINATION_POSITION)->value('quantity_base'), '0', 6));
        $this->assertSame(1, DB::table('stock_movements')->where('source_id', $transferId)->where('movement_type', 'INTERPLANT_DISPATCH')->where('plant_id', self::SOURCE_PLANT)->count());
        $this->assertSame(1, DB::table('stock_movements')->where('source_id', $transferId)->where('movement_type', 'INTERPLANT_RECEIPT')->where('plant_id', self::DESTINATION_PLANT)->count());
        $this->assertSame(5, DB::table('audit_events')->where('entity_type', 'interplant_transfer')->where('entity_id', $transferId)->count());
        $this->assertSame(5, DB::table('outbox_events')->where('aggregate_type', 'interplant_transfer')->where('aggregate_id', $transferId)->count());
    }

    public function test_transfer_submitter_cannot_approve_their_own_handoff(): void
    {
        $created = $this->command()->postJson('/api/v1/scale/transfers', [
            'transfer_number' => 'XFER-SCALE-MAKER-001',
            'plant_transfer_route_id' => self::ROUTE,
            'transfer_date' => '2026-09-15',
            'expected_arrival_date' => '2026-09-16',
            'lines' => [[
                'source_position_id' => self::SOURCE_POSITION,
                'destination_position_id' => self::DESTINATION_POSITION,
                'quantity_base' => '1',
            ]],
        ])->assertCreated();
        $transferId = (string) $created->json('data.id');

        $this->signIn(self::ADMIN);
        $this->withHeaders($this->headers(1))->postJson("/api/v1/scale/transfers/{$transferId}/submit")
            ->assertOk()->assertJsonPath('data.status', 'SUBMITTED');
        $this->withHeaders($this->headers(2))->postJson("/api/v1/scale/transfers/{$transferId}/approve")
            ->assertUnprocessable()->assertJsonPath(
                'error.fields.actor.0',
                'The transfer maker cannot approve the same transfer.',
            );

        $this->assertDatabaseHas('interplant_transfer_orders', [
            'id' => $transferId, 'status' => 'SUBMITTED', 'record_version' => 2,
        ]);
    }

    public function test_consolidation_is_balanced_scoped_and_maker_checked(): void
    {
        $this->signIn(self::FINANCE);
        $created = $this->command()->postJson('/api/v1/scale/consolidations', [
            'run_number' => 'CONS-SCALE-001',
            'consolidation_group_id' => self::GROUP,
            'cutoff_date' => '2026-09-30',
            'member_rates' => [['company_id' => self::COMPANY, 'exchange_rate' => '1']],
            'eliminations' => [
                ['description' => 'Eliminate internal debit', 'debit_amount' => '100', 'credit_amount' => '0'],
                ['description' => 'Eliminate internal credit', 'debit_amount' => '0', 'credit_amount' => '100'],
            ],
        ])->assertCreated()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.member_count', 1);
        $runId = (string) $created->json('data.id');
        $this->assertSame($created->json('data.consolidated_debit'), $created->json('data.consolidated_credit'));

        $this->withHeaders($this->headers(1))->postJson("/api/v1/scale/consolidations/{$runId}/finalize")
            ->assertUnprocessable()->assertJsonPath('error.fields.actor.0', 'The consolidation maker cannot finalize the same snapshot.');
        $this->signIn(self::ADMIN);
        $this->withHeaders($this->headers(1))->postJson("/api/v1/scale/consolidations/{$runId}/finalize")
            ->assertOk()->assertJsonPath('data.status', 'FINALIZED')->assertJsonPath('data.record_version', 2);
        $this->getJson("/api/v1/scale/consolidations/{$runId}")->assertOk()
            ->assertJsonCount(1, 'data.members')->assertJsonCount(2, 'data.eliminations');

        $this->signIn(self::ADMIN, self::DESTINATION_PLANT);
        $this->getJson("/api/v1/scale/consolidations/{$runId}")->assertNotFound();
    }

    public function test_cross_company_configuration_enforces_authority_group_and_controlled_handoff(): void
    {
        $otherCompany = '10000000-0000-4000-8000-000000000001';
        $otherPlant = '10000000-0000-4000-8000-000000000101';
        DB::table('companies')->insert([
            'id' => $otherCompany, 'code' => 'OTHER', 'legal_name' => 'Other Foods Pvt Ltd',
            'display_name' => 'Other Foods', 'status' => 'ACTIVE', 'record_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('plants')->insert([
            'id' => $otherPlant, 'company_id' => $otherCompany, 'code' => 'OTHER-01', 'name' => 'Other Plant',
            'timezone' => 'Asia/Kolkata', 'status' => 'ACTIVE', 'record_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->signIn(self::ADMIN);
        $payload = [
            'route_code' => 'CROSS-NO-AUTH', 'name' => 'Unauthorised cross-company lane',
            'destination_company_id' => $otherCompany, 'destination_plant_id' => $otherPlant,
            'consolidation_group_id' => self::GROUP, 'transfer_scope' => 'INTER_COMPANY',
            'currency' => 'INR', 'transit_days' => 2, 'markup_percent' => '5',
            'require_destination_acceptance' => true, 'require_commercial_reference' => true,
            'mappings' => [[
                'source_item_id' => '00000000-0000-4000-8000-000000000601',
                'destination_item_id' => '00000000-0000-4000-8000-000000000601',
                'source_uom_code' => 'PACK', 'destination_uom_code' => 'PACK', 'conversion_rate' => '1',
            ]],
        ];
        $this->command()->postJson('/api/v1/scale/transfer-routes', $payload)
            ->assertUnprocessable()->assertJsonPath('error.fields.authority.0', 'The actor lacks ACTION:SCALE-PLANT:ROUTE-CREATE in a required legal-entity or plant scope.');

        DB::table('role_assignments')->insert([
            'id' => (string) Str::uuid(), 'user_id' => self::ADMIN,
            'role_id' => '00000000-0000-4000-8000-000000000304',
            'company_id' => $otherCompany, 'plant_id' => $otherPlant, 'party_id' => null,
            'is_active' => true, 'effective_from' => null, 'effective_to' => null,
            'record_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $payload['route_code'] = 'CROSS-NO-GROUP';
        $this->command()->postJson('/api/v1/scale/transfer-routes', $payload)
            ->assertUnprocessable()->assertJsonPath('error.fields.consolidation_group_id.0', 'Cross-company routes require an active group containing both legal entities.');

        $this->assertDatabaseCount('plant_transfer_routes', 1);

        DB::table('consolidation_group_members')->insert([
            'id' => '10000000-0000-4000-8000-000000003002',
            'consolidation_group_id' => self::GROUP, 'owner_company_id' => self::COMPANY,
            'company_id' => $otherCompany, 'member_code' => 'OTHER', 'reporting_currency' => 'INR',
            'ownership_percent' => 100, 'effective_from' => '2026-04-01', 'effective_to' => null,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $destinationItem = '10000000-0000-4000-8000-000000000601';
        $destinationLot = '10000000-0000-4000-8000-000000002601';
        $destinationOwner = '10000000-0000-4000-8000-000000002501';
        $destinationLocation = '10000000-0000-4000-8000-000000000501';
        $destinationPosition = '10000000-0000-4000-8000-000000002602';
        $sourceItem = '00000000-0000-4000-8000-000000000601';
        $destinationCatalogItem = '10000000-0000-4000-8000-000000000600';
        $sourceCatalogItem = (string) DB::table('items')->where('id', $sourceItem)->value('catalog_item_id');
        $sourceLot = (string) DB::table('stock_positions')->where('id', self::SOURCE_POSITION)->value('lot_id');
        $sourceOwner = (string) DB::table('stock_positions')->where('id', self::SOURCE_POSITION)->value('inventory_owner_id');
        $sourceLocation = (string) DB::table('stock_positions')->where('id', self::DESTINATION_POSITION)->value('location_id');

        DB::table('catalog_items')->insert(array_merge((array) DB::table('catalog_items')->where('id', $sourceCatalogItem)->first(), [
            'id' => $destinationCatalogItem, 'company_id' => $otherCompany, 'brand_id' => null,
            'code' => 'OTHER-FG', 'name' => 'Other company finished good', 'status' => 'ACTIVE',
            'status_reason' => null, 'status_changed_at' => null, 'status_changed_by' => null,
        ]));
        DB::table('items')->insert(array_merge((array) DB::table('items')->where('id', $sourceItem)->first(), [
            'id' => $destinationItem, 'company_id' => $otherCompany, 'catalog_item_id' => $destinationCatalogItem,
            'code' => 'OTHER-FG-PACK', 'name' => 'Other company finished pack', 'barcode' => null,
            'status' => 'ACTIVE', 'status_reason' => null, 'status_changed_at' => null, 'status_changed_by' => null,
        ]));
        DB::table('lots')->insert(array_merge((array) DB::table('lots')->where('id', $sourceLot)->first(), [
            'id' => $destinationLot, 'company_id' => $otherCompany, 'item_id' => $destinationItem,
            'supplier_party_id' => null, 'internal_lot_code' => 'OTHER-FG-LOT-001', 'supplier_lot_code' => null,
            'status' => 'ACTIVE', 'status_reason' => null, 'status_changed_at' => null, 'status_changed_by' => null,
        ]));
        DB::table('inventory_owners')->insert(array_merge((array) DB::table('inventory_owners')->where('id', $sourceOwner)->first(), [
            'id' => $destinationOwner, 'company_id' => $otherCompany, 'party_id' => null,
            'code' => 'OTHER-LEGAL', 'name' => 'Other Foods legal inventory', 'status' => 'ACTIVE',
            'status_reason' => null, 'status_changed_at' => null, 'status_changed_by' => null,
        ]));
        DB::table('locations')->insert(array_merge((array) DB::table('locations')->where('id', $sourceLocation)->first(), [
            'id' => $destinationLocation, 'company_id' => $otherCompany, 'plant_id' => $otherPlant,
            'code' => 'OTHER-FG', 'name' => 'Other finished goods store', 'parent_location_id' => null, 'status' => 'ACTIVE',
        ]));
        DB::table('stock_positions')->insert(array_merge((array) DB::table('stock_positions')->where('id', self::DESTINATION_POSITION)->first(), [
            'id' => $destinationPosition, 'company_id' => $otherCompany, 'plant_id' => $otherPlant,
            'item_id' => $destinationItem, 'lot_id' => $destinationLot, 'owner_party_id' => null,
            'inventory_owner_id' => $destinationOwner, 'location_id' => $destinationLocation,
            'quantity_base' => 0, 'reserved_quantity_base' => 0, 'record_version' => 1,
            'status_reason' => null, 'status_changed_at' => null, 'status_changed_by' => null,
        ]));

        DB::table('role_assignments')->insert([
            [
                'id' => '10000000-0000-4000-8000-000000003101', 'user_id' => self::FINANCE,
                'role_id' => '00000000-0000-4000-8000-000000000304',
                'company_id' => self::COMPANY, 'plant_id' => self::SOURCE_PLANT, 'party_id' => null,
                'is_active' => true, 'effective_from' => null, 'effective_to' => null,
                'record_version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ],
            [
                'id' => '10000000-0000-4000-8000-000000003102', 'user_id' => self::FINANCE,
                'role_id' => '00000000-0000-4000-8000-000000000304',
                'company_id' => $otherCompany, 'plant_id' => $otherPlant, 'party_id' => null,
                'is_active' => true, 'effective_from' => null, 'effective_to' => null,
                'record_version' => 1, 'created_at' => now(), 'updated_at' => now(),
            ],
        ]);

        $payload['route_code'] = 'CROSS-CONTROLLED';
        $payload['name'] = 'Controlled cross-company lane';
        $payload['mappings'][0]['destination_item_id'] = $destinationItem;
        $payload['require_destination_acceptance'] = false;
        $payload['require_commercial_reference'] = false;
        $createdRoute = $this->command()->postJson('/api/v1/scale/transfer-routes', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.transfer_scope', 'INTER_COMPANY');
        $routeId = (string) $createdRoute->json('data.id');
        $this->assertDatabaseHas('plant_transfer_routes', [
            'id' => $routeId, 'require_destination_acceptance' => true, 'require_commercial_reference' => true,
        ]);
        $this->withHeaders($this->headers(1))->postJson("/api/v1/scale/transfer-routes/{$routeId}/activate")
            ->assertOk()->assertJsonPath('data.status', 'ACTIVE');

        $sourceBefore = (string) DB::table('stock_positions')->where('id', self::SOURCE_POSITION)->value('quantity_base');
        $this->signIn(self::OPERATIONS);
        $createdTransfer = $this->command()->postJson('/api/v1/scale/transfers', [
            'transfer_number' => 'XFER-CROSS-001', 'plant_transfer_route_id' => $routeId,
            'transfer_date' => '2026-09-15', 'expected_arrival_date' => '2026-09-17',
            'commercial_reference' => 'IC-SALE-001',
            'lines' => [[
                'source_position_id' => self::SOURCE_POSITION,
                'destination_position_id' => $destinationPosition, 'quantity_base' => '2',
            ]],
        ])->assertCreated()->assertJsonPath('data.transfer_scope', 'INTER_COMPANY');
        $transferId = (string) $createdTransfer->json('data.id');
        $this->withHeaders($this->headers(1))->postJson("/api/v1/scale/transfers/{$transferId}/submit")
            ->assertOk()->assertJsonPath('data.status', 'SUBMITTED');

        $this->signIn(self::ADMIN);
        $this->withHeaders($this->headers(2))->postJson("/api/v1/scale/transfers/{$transferId}/approve")
            ->assertOk()->assertJsonPath('data.status', 'SOURCE_APPROVED');
        $this->signIn(self::FINANCE, $otherPlant, $otherCompany);
        $this->withHeaders($this->headers(3))->postJson("/api/v1/scale/transfers/{$transferId}/accept", [
            'destination_reference' => 'OTHER-IC-001',
        ])->assertOk()->assertJsonPath('data.status', 'APPROVED');

        $this->signIn(self::ADMIN);
        $this->withHeaders($this->headers(4))->postJson("/api/v1/scale/transfers/{$transferId}/dispatch")
            ->assertOk()->assertJsonPath('data.status', 'IN_TRANSIT');
        $this->signIn(self::FINANCE, $otherPlant, $otherCompany);
        $this->withHeaders($this->headers(5))->postJson("/api/v1/scale/transfers/{$transferId}/receive")
            ->assertOk()->assertJsonPath('data.status', 'RECEIVED');

        $this->assertSame(bcsub($sourceBefore, '2', 6), bcadd((string) DB::table('stock_positions')->where('id', self::SOURCE_POSITION)->value('quantity_base'), '0', 6));
        $this->assertSame('2.000000', bcadd((string) DB::table('stock_positions')->where('id', $destinationPosition)->value('quantity_base'), '0', 6));
        $this->assertSame(2, DB::table('stock_movements')->where('source_id', $transferId)->count());
    }

    private function signIn(string $id, string $plant = self::SOURCE_PLANT, string $company = self::COMPANY): void
    {
        $this->actingAs(User::query()->findOrFail($id))->withSession([
            'erp.company_id' => $company, 'erp.plant_id' => $plant,
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

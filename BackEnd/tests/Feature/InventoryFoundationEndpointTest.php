<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InventoryFoundationEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const TRAINING_PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const FINANCE_PLANT_ID = '00000000-0000-4000-8000-000000000102';
    private const ADMIN_ID = '00000000-0000-4000-8000-000000000204';
    private const OPERATIONS_ID = '00000000-0000-4000-8000-000000000202';
    private const SALES_ID = '00000000-0000-4000-8000-000000000201';
    private const COMPANY_OWNER_ID = self::COMPANY_ID;
    private const PARTY_OWNER_ID = '00000000-0000-4000-8000-000000000501';
    private const SUPPLIER_ID = '00000000-0000-4000-8000-000000000502';
    private const RAW_SKU_ID = '00000000-0000-4000-8000-000000000603';
    private const ALT_SKU_ID = '00000000-0000-4000-8000-000000000602';
    private const RAW_LOT_ID = '00000000-0000-4000-8000-000000000703';
    private const AVAILABLE_POSITION_ID = '00000000-0000-4000-8000-000000001213';
    private const BLOCKED_POSITION_ID = '00000000-0000-4000-8000-000000001214';
    private const SEEDED_RESERVATION_ID = '00000000-0000-4000-8000-000000001251';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->signIn(self::OPERATIONS_ID);
    }

    public function test_stock_workspace_exposes_owner_lot_quality_and_derived_buckets(): void
    {
        $this->getJson('/api/v1/inventory/stock')
            ->assertOk()
            ->assertJsonPath('meta.total', 5)
            ->assertJsonPath('summary.quantity_summary_status', 'MIXED_UOM')
            ->assertJsonPath('summary.total', null)
            ->assertJsonPath('summary.available', null)
            ->assertJsonPath('summary.blocked', null)
            ->assertJsonPath('summary.reserved', null)
            ->assertJsonPath('summary.uom_code', null)
            ->assertJsonPath('summary.quantities_by_uom.0.uom_code', 'KG')
            ->assertJsonPath('summary.quantities_by_uom.0.total', '160.000000')
            ->assertJsonPath('summary.quantities_by_uom.0.available', '117.000000')
            ->assertJsonPath('summary.quantities_by_uom.0.blocked', '23.000000')
            ->assertJsonPath('summary.quantities_by_uom.0.reserved', '20.000000')
            ->assertJsonPath('summary.quantities_by_uom.1.uom_code', 'PACK')
            ->assertJsonPath('summary.quantities_by_uom.1.total', '500.000000')
            ->assertJsonPath('summary.quantities_by_uom.1.available', '500.000000')
            ->assertJsonPath('summary.sku_count', 3)
            ->assertJsonPath('summary.lot_count', 5)
            ->assertJsonCount(8, 'lookups.quality_statuses');

        $this->getJson('/api/v1/inventory/stock?availability=BLOCKED')
            ->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.id', self::BLOCKED_POSITION_ID)
            ->assertJsonPath('data.0.stock_bucket', 'BLOCKED')
            ->assertJsonPath('data.0.quantity.blocked', '15.000000');

        $this->getJson('/api/v1/inventory/stock?owner_id='.self::PARTY_OWNER_ID)
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.owner.code', 'OWN-NORTH')
            ->assertJsonPath('data.0.quantity.available', '12.000000');

        $this->getJson('/api/v1/inventory/stock/'.self::AVAILABLE_POSITION_ID)
            ->assertOk()->assertJsonPath('data.sku.code', 'SKU-APPLE-BASE')
            ->assertJsonPath('data.lot.code', 'RM-APPLE-2609A')
            ->assertJsonPath('data.owner.code', 'OWN')
            ->assertJsonPath('data.quantity.available', '105.000000')
            ->assertJsonPath('data.reservations.0.id', self::SEEDED_RESERVATION_ID)
            ->assertJsonPath('data.reservations.0.allowed_actions.0', 'RELEASE');
    }

    public function test_owner_commands_are_idempotent_versioned_and_dependency_safe(): void
    {
        $payload = [
            'code' => 'OWN-CENTRAL',
            'name' => 'Central supplier-owned stock',
            'owner_type' => 'PARTY',
            'party_id' => self::SUPPLIER_ID,
            'status' => 'ACTIVE',
        ];
        $key = (string) Str::uuid();
        $created = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/inventory/owners', $payload)
            ->assertCreated()->assertJsonPath('data.record_version', 1);
        $ownerId = $created->json('data.id');
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/inventory/owners', $payload)
            ->assertCreated()->assertExactJson($created->json());

        $this->getJson('/api/v1/inventory/owners?owner_type=PARTY')
            ->assertOk()->assertJsonPath('meta.total', 2)
            ->assertJsonPath('summary.party_owned', 2);
        $this->getJson('/api/v1/inventory/owners/'.$ownerId)
            ->assertOk()->assertJsonPath('data.party.code', 'DIST-CENTRAL');

        $duplicate = $payload;
        $duplicate['code'] = 'OWN-CENTRAL-ALT';
        $this->command()->postJson('/api/v1/inventory/owners', $duplicate)
            ->assertUnprocessable()->assertJsonPath(
                'error.fields.party_id.0', 'That party already has an inventory-owner record.',
            );

        $this->withHeaders($this->headers(1))->postJson('/api/v1/inventory/owners/'.$ownerId, [
            'name' => 'Central consignment stock',
            'owner_type' => 'PARTY',
            'party_id' => self::SUPPLIER_ID,
        ])->assertOk()->assertJsonPath('data.record_version', 2);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/inventory/owners/'.$ownerId, [
            'name' => 'Stale update', 'owner_type' => 'PARTY', 'party_id' => self::SUPPLIER_ID,
        ])->assertConflict()->assertJsonPath('error.code', 'CONFLICT');

        $this->withHeaders($this->headers(2))->postJson('/api/v1/inventory/owners/'.$ownerId.'/status', [
            'target_status' => 'INACTIVE', 'reason' => 'No consignment stock remains.',
        ])->assertOk()->assertJsonPath('data.record_version', 3);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/inventory/owners/'.self::COMPANY_OWNER_ID.'/status', [
            'target_status' => 'INACTIVE', 'reason' => 'Attempt company-owner retirement.',
        ])->assertUnprocessable()->assertJsonPath(
            'error.fields.target_status.0', 'The company-owned stock owner must remain active.',
        );

        $this->assertDatabaseHas('audit_events', [
            'command' => 'UPDATE_INVENTORY_OWNER', 'entity_id' => $ownerId, 'entity_version' => 2,
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'inventory.owner.status_changed', 'aggregate_id' => $ownerId,
        ]);
    }

    public function test_lot_commands_validate_traceability_and_protect_referenced_identity(): void
    {
        $payload = [
            'internal_lot_code' => 'RM-APPLE-NEW',
            'item_id' => self::RAW_SKU_ID,
            'supplier_party_id' => self::SUPPLIER_ID,
            'supplier_lot_code' => 'SUP-NEW-01',
            'origin_type' => 'PURCHASE',
            'manufacture_date' => now()->subDay()->toDateString(),
            'expiry_date' => now()->addDays(60)->toDateString(),
            'notes' => 'Incoming approved ingredient lot.',
            'status' => 'ACTIVE',
        ];
        $created = $this->command()->postJson('/api/v1/inventory/lots', $payload)
            ->assertCreated()->assertJsonPath('data.record_version', 1);
        $lotId = $created->json('data.id');

        $this->getJson('/api/v1/inventory/lots?origin_type=PURCHASE')
            ->assertOk()->assertJsonPath('meta.total', 3)
            ->assertJsonPath('summary.total', 6);
        $this->getJson('/api/v1/inventory/lots/'.$lotId)
            ->assertOk()->assertJsonPath('data.supplier.code', 'DIST-CENTRAL')
            ->assertJsonPath('data.sku.code', 'SKU-APPLE-BASE');

        $update = $payload;
        unset($update['internal_lot_code'], $update['status']);
        $update['notes'] = 'Updated supplier trace note.';
        $this->withHeaders($this->headers(1))->postJson('/api/v1/inventory/lots/'.$lotId, $update)
            ->assertOk()->assertJsonPath('data.record_version', 2);
        $this->withHeaders($this->headers(2))->postJson('/api/v1/inventory/lots/'.$lotId.'/status', [
            'target_status' => 'CLOSED', 'reason' => 'Unused lot retired.',
        ])->assertOk()->assertJsonPath('data.status', 'CLOSED');

        $seeded = $this->getJson('/api/v1/inventory/lots/'.self::RAW_LOT_ID)->assertOk()->json('data');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/inventory/lots/'.self::RAW_LOT_ID, [
            'item_id' => self::ALT_SKU_ID,
            'supplier_party_id' => $seeded['supplier_party_id'],
            'supplier_lot_code' => $seeded['supplier_lot_code'],
            'origin_type' => $seeded['origin_type'],
            'manufacture_date' => $seeded['manufacture_date'],
            'expiry_date' => $seeded['expiry_date'],
            'notes' => $seeded['notes'],
        ])->assertUnprocessable()->assertJsonPath(
            'error.fields.item_id.0', 'A referenced lot cannot be reassigned to another SKU.',
        );

        $invalid = $payload;
        $invalid['internal_lot_code'] = 'RM-BAD-SUPPLIER';
        $invalid['supplier_party_id'] = self::PARTY_OWNER_ID;
        $this->command()->postJson('/api/v1/inventory/lots', $invalid)
            ->assertUnprocessable()->assertJsonPath(
                'error.fields.supplier_party_id.0', 'Select an active supplier from the current company.',
            );

        $missing = $payload;
        $missing['internal_lot_code'] = 'RM-MISSING-SUPPLIER';
        $missing['supplier_party_id'] = null;
        $this->command()->postJson('/api/v1/inventory/lots', $missing)
            ->assertUnprocessable()->assertJsonPath(
                'error.fields.supplier_party_id.0', 'An active purchased lot requires an active supplier.',
            );
    }

    public function test_reservations_preserve_the_projection_and_are_replay_safe(): void
    {
        $payload = [
            'reservation_number' => 'RSV-TEST-001',
            'quantity_base' => '5',
            'purpose' => 'Reserve for the next production schedule.',
        ];
        $key = (string) Str::uuid();
        $headers = ['Idempotency-Key' => $key, 'If-Match' => '1'];
        $created = $this->withHeaders($headers)
            ->postJson('/api/v1/inventory/stock/'.self::AVAILABLE_POSITION_ID.'/reservations', $payload)
            ->assertCreated()->assertJsonPath('data.position_record_version', 2)
            ->assertJsonPath('data.reserved_quantity', '25.000000');
        $reservationId = $created->json('data.id');
        $this->withHeaders($headers)
            ->postJson('/api/v1/inventory/stock/'.self::AVAILABLE_POSITION_ID.'/reservations', $payload)
            ->assertCreated()->assertExactJson($created->json());

        $this->getJson('/api/v1/inventory/stock/'.self::AVAILABLE_POSITION_ID)
            ->assertOk()->assertJsonPath('data.quantity.reserved', '25.000000')
            ->assertJsonPath('data.quantity.available', '100.000000');

        $releaseKey = (string) Str::uuid();
        $releaseHeaders = ['Idempotency-Key' => $releaseKey, 'If-Match' => '1'];
        $released = $this->withHeaders($releaseHeaders)
            ->postJson('/api/v1/inventory/reservations/'.$reservationId.'/release', [
                'reason' => 'Production schedule was cancelled.',
            ])->assertOk()->assertJsonPath('data.status', 'RELEASED')
            ->assertJsonPath('data.position_record_version', 3);
        $this->withHeaders($releaseHeaders)
            ->postJson('/api/v1/inventory/reservations/'.$reservationId.'/release', [
                'reason' => 'Production schedule was cancelled.',
            ])->assertOk()->assertExactJson($released->json());

        $this->assertDatabaseHas('stock_positions', [
            'id' => self::AVAILABLE_POSITION_ID, 'quantity_base' => 125, 'reserved_quantity_base' => 20,
            'record_version' => 3,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'command' => 'RELEASE_STOCK_RESERVATION', 'entity_id' => $reservationId, 'entity_version' => 2,
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'inventory.reservation.released', 'aggregate_id' => $reservationId,
        ]);
    }

    public function test_reservation_guards_block_quality_hold_overcommit_and_stale_versions(): void
    {
        $this->withHeaders($this->headers(1))
            ->postJson('/api/v1/inventory/stock/'.self::BLOCKED_POSITION_ID.'/reservations', [
                'reservation_number' => 'RSV-BLOCKED-01', 'quantity_base' => '1',
                'purpose' => 'This quality-held stock must not reserve.',
            ])->assertUnprocessable()->assertJsonPath(
                'error.fields.stock_position_id.0', 'The position quality status does not permit reservations.',
            );

        $this->withHeaders($this->headers(1))
            ->postJson('/api/v1/inventory/stock/'.self::AVAILABLE_POSITION_ID.'/reservations', [
                'reservation_number' => 'RSV-OVER-01', 'quantity_base' => '106',
                'purpose' => 'Attempt to reserve beyond availability.',
            ])->assertUnprocessable()->assertJsonPath(
                'error.fields.quantity_base.0', 'Reservation quantity exceeds the currently available stock.',
            );

        DB::table('stock_positions')->where('id', self::AVAILABLE_POSITION_ID)->update(['record_version' => 2]);
        $this->withHeaders($this->headers(1))
            ->postJson('/api/v1/inventory/stock/'.self::AVAILABLE_POSITION_ID.'/reservations', [
                'reservation_number' => 'RSV-STALE-01', 'quantity_base' => '1',
                'purpose' => 'Attempt with a stale stock version.',
            ])->assertConflict()->assertJsonPath('error.code', 'CONFLICT');
    }

    public function test_screen_permissions_and_plant_scope_are_enforced(): void
    {
        $this->signIn(self::SALES_ID);
        $this->getJson('/api/v1/inventory/stock')->assertForbidden();
        $this->command()->postJson('/api/v1/inventory/owners', [
            'code' => 'FORBIDDEN', 'name' => 'Forbidden owner', 'owner_type' => 'PARTY',
            'party_id' => self::SUPPLIER_ID, 'status' => 'DRAFT',
        ])->assertForbidden();

        $this->signIn(self::ADMIN_ID, self::FINANCE_PLANT_ID);
        $this->getJson('/api/v1/inventory/stock/'.self::AVAILABLE_POSITION_ID)
            ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->getJson('/api/v1/inventory/stock')->assertOk()->assertJsonPath('meta.total', 0);
    }

    private function signIn(string $userId, string $plantId = self::TRAINING_PLANT_ID): void
    {
        $this->actingAs(User::query()->findOrFail($userId))->withSession([
            'erp.company_id' => self::COMPANY_ID,
            'erp.plant_id' => $plantId,
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

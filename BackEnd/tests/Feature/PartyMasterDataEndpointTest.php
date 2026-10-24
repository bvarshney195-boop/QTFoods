<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PartyMasterDataEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const TRAINING_PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const ADMIN_USER_ID = '00000000-0000-4000-8000-000000000204';
    private const OPERATIONS_USER_ID = '00000000-0000-4000-8000-000000000202';
    private const SALES_USER_ID = '00000000-0000-4000-8000-000000000201';
    private const SEEDED_PARTY_ID = '00000000-0000-4000-8000-000000000501';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->signIn(self::ADMIN_USER_ID);
    }

    public function test_party_workspace_and_detail_are_live_filterable_and_company_scoped(): void
    {
        $workspace = $this->getJson('/api/v1/master/parties?role=CUSTOMER&country=IN&sort=NAME')
            ->assertOk()
            ->assertJsonPath('summary.total', 4)
            ->assertJsonPath('summary.active', 4)
            ->assertJsonPath('summary.customers', 2)
            ->assertJsonPath('summary.suppliers', 3)
            ->assertJsonPath('data.0.allowed_actions.0', 'UPDATE')
            ->assertJsonPath('allowed_actions.0', 'CREATE');
        $this->assertCount(2, $workspace->json('data'));

        $this->getJson('/api/v1/master/parties?q=27ABCDE1234F1Z5')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', self::SEEDED_PARTY_ID);

        $this->getJson('/api/v1/master/parties/'.self::SEEDED_PARTY_ID)
            ->assertOk()
            ->assertJsonPath('data.code', 'DIST-NORTH')
            ->assertJsonPath('data.roles.0', 'CUSTOMER')
            ->assertJsonPath('data.addresses.0.address_type', 'REGISTERED')
            ->assertJsonPath('data.contacts.0.email', 'orders@north-market.example')
            ->assertJsonPath('data.tax_registrations.0.registration_type', 'GSTIN')
            ->assertJsonPath('data.commercial_terms.currency_code', 'INR');

        $foreignCompany = (string) Str::uuid();
        $foreignParty = (string) Str::uuid();
        DB::table('companies')->insert([
            'id' => $foreignCompany, 'code' => 'FOREIGN', 'legal_name' => 'Foreign Company',
            'display_name' => 'Foreign Company', 'status' => 'ACTIVE', 'record_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('parties')->insert([
            'id' => $foreignParty, 'company_id' => $foreignCompany, 'code' => 'FOREIGN-PARTY',
            'display_name' => 'Foreign Party', 'legal_name' => 'Foreign Party',
            'party_kind' => 'ORGANISATION', 'status' => 'ACTIVE', 'record_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/master/parties/'.$foreignParty)
            ->assertNotFound()->assertJsonPath('error.code', 'NOT_FOUND');
        $this->getJson('/api/v1/master/parties')->assertOk()->assertJsonPath('summary.total', 4);
    }

    public function test_complete_party_creation_is_idempotent_audited_and_outbox_backed(): void
    {
        $payload = $this->partyPayload('FRESH-CUSTOMER', '32ABCDE1234F1Z7');
        $key = (string) Str::uuid();
        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/master/parties', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.record_version', 1)
            ->assertJsonPath('data.address_count', 1)
            ->assertJsonPath('data.tax_registration_count', 1);
        $partyId = $first->json('data.id');

        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/master/parties', $payload)
            ->assertCreated()->assertExactJson($first->json());

        $this->assertDatabaseCount('parties', 5);
        $this->assertDatabaseHas('parties', [
            'id' => $partyId, 'company_id' => self::COMPANY_ID, 'code' => 'FRESH-CUSTOMER',
            'record_version' => 1,
        ]);
        $this->assertDatabaseHas('party_roles', ['party_id' => $partyId, 'role_code' => 'CUSTOMER']);
        $this->assertDatabaseHas('party_addresses', ['party_id' => $partyId, 'city' => 'Pune', 'is_primary' => true]);
        $this->assertDatabaseHas('party_contacts', ['party_id' => $partyId, 'email' => 'buying@example.local']);
        $this->assertDatabaseHas('party_tax_registrations', [
            'party_id' => $partyId, 'registration_number' => '32ABCDE1234F1Z7',
        ]);
        $this->assertDatabaseHas('party_commercial_terms', [
            'party_id' => $partyId, 'currency_code' => 'INR', 'payment_terms_days' => 30,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'command' => 'CREATE_PARTY', 'entity_id' => $partyId,
            'company_id' => self::COMPANY_ID, 'plant_id' => self::TRAINING_PLANT_ID,
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'master.party.created', 'aggregate_id' => $partyId,
            'company_id' => self::COMPANY_ID, 'plant_id' => self::TRAINING_PLANT_ID,
        ]);
        $this->assertStringNotContainsString('32ABCDE1234F1Z7',
            (string) DB::table('outbox_events')->where('aggregate_id', $partyId)->value('payload_json'));
    }

    public function test_party_update_preserves_owned_child_ids_and_enforces_version_and_tax_uniqueness(): void
    {
        $partyId = $this->createParty('EDIT-CUSTOMER', '33ABCDE1234F1Z8');
        $detail = $this->getJson('/api/v1/master/parties/'.$partyId)->assertOk()->json('data');
        $update = $this->updatePayload($detail);
        $update['display_name'] = 'Edited Customer Display';
        $update['addresses'][0]['city'] = 'Nashik';
        $update['commercial_terms']['credit_limit'] = '950000.00';

        $this->withHeaders($this->commandHeaders(1))->postJson('/api/v1/master/parties/'.$partyId, $update)
            ->assertOk()->assertJsonPath('data.record_version', 2);
        $this->assertDatabaseHas('party_addresses', [
            'id' => $detail['addresses'][0]['id'], 'party_id' => $partyId, 'city' => 'Nashik',
        ]);
        $this->assertDatabaseHas('party_commercial_terms', [
            'party_id' => $partyId, 'credit_limit' => '950000.00',
        ]);

        $this->withHeaders($this->commandHeaders(1))->postJson('/api/v1/master/parties/'.$partyId, $update)
            ->assertConflict()->assertJsonPath('error.code', 'CONFLICT');

        $update['addresses'][0]['id'] = DB::table('party_addresses')
            ->where('party_id', self::SEEDED_PARTY_ID)->value('id');
        $this->withHeaders($this->commandHeaders(2))->postJson('/api/v1/master/parties/'.$partyId, $update)
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.addresses.0', 'One or more submitted child records do not belong to this party.');

        $duplicateTax = $this->partyPayload('DUPLICATE-TAX', '27ABCDE1234F1Z5');
        $duplicate = $this->command()->postJson('/api/v1/master/parties', $duplicateTax)
            ->assertUnprocessable();
        $this->assertSame(
            'That tax registration already belongs to another party in this company.',
            $duplicate->json('error.fields')['tax_registrations.0.registration_number'][0],
        );
    }

    public function test_lifecycle_is_versioned_replay_safe_and_blocks_unsafe_deactivation(): void
    {
        $partyId = $this->createParty('LIFECYCLE', '34ABCDE1234F1Z9');
        $holdKey = (string) Str::uuid();
        $held = $this->withHeaders(['Idempotency-Key' => $holdKey, 'If-Match' => '1'])
            ->postJson('/api/v1/master/parties/'.$partyId.'/status', [
                'target_status' => 'ON_HOLD', 'reason' => 'Credit review requested.',
            ])->assertOk()->assertJsonPath('data.record_version', 2);
        $this->withHeaders(['Idempotency-Key' => $holdKey, 'If-Match' => '1'])
            ->postJson('/api/v1/master/parties/'.$partyId.'/status', [
                'target_status' => 'ON_HOLD', 'reason' => 'Credit review requested.',
            ])->assertOk()->assertExactJson($held->json());

        $this->withHeaders($this->commandHeaders(2))
            ->postJson('/api/v1/master/parties/'.$partyId.'/status', [
                'target_status' => 'ACTIVE', 'reason' => 'Credit review completed.',
            ])->assertOk()->assertJsonPath('data.record_version', 3);

        DB::table('unsold_return_cases')->insert([
            'id' => (string) Str::uuid(), 'company_id' => self::COMPANY_ID,
            'plant_id' => self::TRAINING_PLANT_ID, 'party_id' => $partyId,
            'sales_order_id' => null, 'shipment_id' => (string) Str::uuid(), 'invoice_id' => null,
            'status' => 'REQUESTED', 'reason_code' => 'TEST', 'expected_return_date' => null,
            'sales_note' => null, 'maker_id' => self::ADMIN_USER_ID, 'received_at' => null,
            'loss_event_id' => null, 'record_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->withHeaders($this->commandHeaders(3))
            ->postJson('/api/v1/master/parties/'.$partyId.'/status', [
                'target_status' => 'INACTIVE', 'reason' => 'Relationship ended.',
            ])->assertUnprocessable()
            ->assertJsonPath(
                'error.fields.target_status.0',
                'Resolve all open Unsold Return cases before deactivating this party.',
            );

        DB::table('unsold_return_cases')->where('party_id', $partyId)->update(['status' => 'FINANCE_RESOLVED']);
        $positionId = DB::table('stock_positions')->where('plant_id', self::TRAINING_PLANT_ID)->value('id');
        DB::table('stock_positions')->where('id', $positionId)->update([
            'owner_party_id' => $partyId, 'quantity_base' => 2,
        ]);
        $this->withHeaders($this->commandHeaders(3))
            ->postJson('/api/v1/master/parties/'.$partyId.'/status', [
                'target_status' => 'INACTIVE', 'reason' => 'Relationship ended.',
            ])->assertUnprocessable()
            ->assertJsonPath(
                'error.fields.target_status.0',
                'Move all party-owned stock before deactivating this party.',
            );

        DB::table('stock_positions')->where('id', $positionId)->update([
            'owner_party_id' => null, 'quantity_base' => 0,
        ]);
        $assignmentId = DB::table('role_assignments')->where('user_id', self::OPERATIONS_USER_ID)
            ->where('plant_id', self::TRAINING_PLANT_ID)->value('id');
        DB::table('role_assignments')->where('id', $assignmentId)->update(['party_id' => $partyId]);
        $this->withHeaders($this->commandHeaders(3))
            ->postJson('/api/v1/master/parties/'.$partyId.'/status', [
                'target_status' => 'INACTIVE', 'reason' => 'Relationship ended.',
            ])->assertUnprocessable()
            ->assertJsonPath(
                'error.fields.target_status.0',
                'End active portal role assignments before deactivating this party.',
            );

        DB::table('role_assignments')->where('id', $assignmentId)->update(['party_id' => null]);
        $this->withHeaders($this->commandHeaders(3))
            ->postJson('/api/v1/master/parties/'.$partyId.'/status', [
                'target_status' => 'INACTIVE', 'reason' => 'Relationship ended.',
            ])->assertOk()->assertJsonPath('data.record_version', 4);
        $this->assertDatabaseHas('audit_events', [
            'command' => 'CHANGE_PARTY_STATUS', 'entity_id' => $partyId, 'entity_version' => 4,
        ]);
    }

    public function test_activation_requires_complete_data_and_action_permissions_are_enforced(): void
    {
        $draft = $this->partyPayload('INCOMPLETE', '35ABCDE1234F1Z1', 'DRAFT');
        $draft['addresses'] = [];
        $draft['contacts'] = [];
        $created = $this->command()->postJson('/api/v1/master/parties', $draft)
            ->assertCreated()->assertJsonPath('data.status', 'DRAFT');
        $this->withHeaders($this->commandHeaders(1))
            ->postJson('/api/v1/master/parties/'.$created->json('data.id').'/status', [
                'target_status' => 'ACTIVE', 'reason' => 'Attempt activation.',
            ])->assertUnprocessable()
            ->assertJsonPath('error.fields.target_status.0', 'Add at least one address before activation.');

        $this->signIn(self::OPERATIONS_USER_ID);
        $this->getJson('/api/v1/master/parties')->assertOk()
            ->assertJsonPath('allowed_actions.0', 'CREATE');
        $this->command()->postJson('/api/v1/master/parties',
            $this->partyPayload('OPS-PARTY', '36ABCDE1234F1Z2'))->assertCreated();

        $this->signIn(self::SALES_USER_ID);
        $this->getJson('/api/v1/master/parties')->assertForbidden();
        $this->command()->postJson('/api/v1/master/parties', [])->assertForbidden();
    }

    private function partyPayload(
        string $code,
        string $taxNumber,
        string $status = 'ACTIVE',
    ): array {
        return [
            'code' => $code,
            'display_name' => 'Fresh Foods Distribution',
            'legal_name' => 'Fresh Foods Distribution Private Limited',
            'party_kind' => 'ORGANISATION',
            'notes' => 'Approved distribution partner.',
            'status' => $status,
            'roles' => ['CUSTOMER'],
            'addresses' => [[
                'label' => 'Registered office', 'address_type' => 'REGISTERED',
                'line_1' => '18 Market Road', 'line_2' => null, 'city' => 'Pune',
                'district' => null, 'region' => 'Maharashtra', 'postal_code' => '411001',
                'country_code' => 'IN', 'is_primary' => true,
            ]],
            'contacts' => [[
                'name' => 'Buying Desk', 'job_title' => 'Buyer', 'department' => 'Procurement',
                'email' => 'BUYING@EXAMPLE.LOCAL', 'phone' => '+91 20 4000 1000',
                'mobile' => null, 'is_primary' => true,
            ]],
            'tax_registrations' => [[
                'registration_type' => 'GSTIN', 'registration_number' => $taxNumber,
                'country_code' => 'IN', 'is_primary' => true,
                'valid_from' => '2024-04-01', 'valid_to' => null,
            ]],
            'commercial_terms' => [
                'currency_code' => 'INR', 'payment_terms_days' => 30,
                'credit_limit' => '750000.00', 'credit_hold' => false,
                'incoterm_code' => 'DAP', 'delivery_terms' => 'Delivered to registered warehouse.',
            ],
        ];
    }

    private function updatePayload(array $detail): array
    {
        return [
            'display_name' => $detail['display_name'],
            'legal_name' => $detail['legal_name'],
            'party_kind' => $detail['party_kind'],
            'notes' => $detail['notes'],
            'roles' => $detail['roles'],
            'addresses' => $detail['addresses'],
            'contacts' => $detail['contacts'],
            'tax_registrations' => $detail['tax_registrations'],
            'commercial_terms' => collect($detail['commercial_terms'])->except('id')->all(),
        ];
    }

    private function createParty(string $code, string $taxNumber): string
    {
        return $this->command()->postJson('/api/v1/master/parties', $this->partyPayload($code, $taxNumber))
            ->assertCreated()->json('data.id');
    }

    private function signIn(string $userId): void
    {
        $this->actingAs(User::query()->findOrFail($userId))->withSession([
            'erp.company_id' => self::COMPANY_ID,
            'erp.plant_id' => self::TRAINING_PLANT_ID,
        ]);
    }

    private function command(): static
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid());
    }

    private function commandHeaders(int $version): array
    {
        return ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => (string) $version];
    }
}

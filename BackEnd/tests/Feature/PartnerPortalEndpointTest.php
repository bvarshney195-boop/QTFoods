<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PartnerPortalEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = '00000000-0000-4000-8000-000000000001';
    private const PLANT = '00000000-0000-4000-8000-000000000101';
    private const ADMIN = '00000000-0000-4000-8000-000000000204';
    private const PARTNER = '00000000-0000-4000-8000-000000000205';
    private const GRANT = '00000000-0000-4000-8000-000000004002';
    private const NORTH = '00000000-0000-4000-8000-000000000501';
    private const CENTRAL = '00000000-0000-4000-8000-000000000502';
    private const NORTH_SHIPMENT = '00000000-0000-4000-8000-000000001001';
    private const CENTRAL_SHIPMENT = '00000000-0000-4000-8000-000000001002';
    private const NORTH_SHIPMENT_LINE = '00000000-0000-4000-8000-000000001101';
    private const NORTH_INVOICE = '00000000-0000-4000-8000-000000001301';
    private const ITEM = '00000000-0000-4000-8000-000000000601';
    private const CONTRACT = '00000000-0000-4000-8000-000000002501';

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('qtfoods.private_document_disk', 'private');
        Storage::fake('private');
        $this->seed();
        $this->signIn(self::PARTNER);
    }

    public function test_partner_workspace_is_exactly_tenant_scoped_and_entitlement_gated(): void
    {
        $this->signIn(self::ADMIN);
        $createdOrder = $this->command()->postJson('/api/v1/sales/orders', [
            'order_number' => 'PARTNER-ALLOWLIST-001',
            'customer_party_id' => self::NORTH,
            'sales_lead_id' => null,
            'sales_contract_id' => self::CONTRACT,
            'sales_price_list_id' => null,
            'order_date' => now()->toDateString(),
            'requested_delivery_date' => now()->addDays(3)->toDateString(),
            'notes' => 'Internal commercial note that must not reach the partner contract.',
            'lines' => [[
                'item_id' => self::ITEM,
                'uom_code' => 'PACK',
                'quantity' => '2',
                'discount_percent' => '0',
            ]],
        ])->assertCreated();
        $orderId = (string) $createdOrder->json('data.id');
        $this->signIn(self::PARTNER);

        $workspace = $this->getJson('/api/v1/partner/workspaces')->assertOk()
            ->assertJsonPath('mode', 'PARTNER')
            ->assertJsonPath('identity.party_id', self::NORTH)
            ->assertJsonPath('identity.party_code', 'DIST-NORTH')
            ->assertJsonPath('meta.tenant_isolation', 'PARTY_ENFORCED')
            ->assertJsonFragment(['DOCUMENT-ACKNOWLEDGE'])
            ->assertJsonFragment(['shipment_number' => 'SHP-2026-0001'])
            ->assertJsonFragment(['invoice_number' => 'INV-2026-0001']);

        $this->assertSame(
            [self::NORTH_SHIPMENT],
            collect($workspace->json('shipments'))->pluck('id')->all(),
        );
        $this->assertSame(
            [self::NORTH_INVOICE],
            collect($workspace->json('invoices'))->pluck('id')->all(),
        );
        $this->assertFalse(collect($workspace->json('shipments'))->contains('id', self::CENTRAL_SHIPMENT));
        $this->getJson('/api/v1/partner/shipments/'.self::CENTRAL_SHIPMENT)->assertNotFound();

        $this->assertContains($orderId, collect($workspace->json('orders'))->pluck('id')->all());
        $this->getJson('/api/v1/partner/orders/'.$orderId)->assertOk()
            ->assertJsonMissingPath('data.company_id')
            ->assertJsonMissingPath('data.plant_id')
            ->assertJsonMissingPath('data.created_by')
            ->assertJsonMissingPath('data.notes')
            ->assertJsonMissingPath('data.credit_limit_snapshot')
            ->assertJsonMissingPath('data.credit_exposure_snapshot');

        $this->getJson('/api/v1/partner/invoices/'.self::NORTH_INVOICE)->assertOk()
            ->assertJsonMissingPath('data.company_id')
            ->assertJsonMissingPath('data.plant_id')
            ->assertJsonMissingPath('data.transactions.0.company_id')
            ->assertJsonMissingPath('data.transactions.0.plant_id')
            ->assertJsonMissingPath('data.transactions.0.actor_id');

        DB::table('partner_access_entitlements')
            ->where('partner_access_grant_id', self::GRANT)
            ->whereIn('entitlement_code', ['INVOICES_VIEW', 'DOCUMENT_UPLOAD'])
            ->delete();

        $restricted = $this->getJson('/api/v1/partner/workspaces')->assertOk();
        $this->assertSame([], $restricted->json('invoices'));
        $this->getJson('/api/v1/partner/invoices/'.self::NORTH_INVOICE)->assertNotFound();
        $this->command()->postJson('/api/v1/partner/documents', [
            'document_number' => 'PORTAL-RESTRICTED-001',
            'document_type' => 'GENERAL',
            'title' => 'Should not upload',
            'file' => UploadedFile::fake()->createWithContent('blocked.pdf', '%PDF-1.4 blocked'),
        ])->assertForbidden();
        $this->assertDatabaseMissing('partner_documents', ['document_number' => 'PORTAL-RESTRICTED-001']);
    }

    public function test_document_exchange_is_private_versioned_and_acknowledgement_is_receipt_only(): void
    {
        $this->signIn(self::ADMIN);
        $published = $this->command()->postJson('/api/v1/partner/documents/publish', [
            'party_id' => self::NORTH,
            'document_number' => 'PORTAL-OUT-001',
            'document_type' => 'SHIPPING_DOCUMENT',
            'title' => 'Signed delivery packet',
            'description' => 'Outbound packet for partner acknowledgement.',
            'shipment_id' => self::NORTH_SHIPMENT,
            'file' => UploadedFile::fake()->createWithContent('delivery-packet.pdf', '%PDF-1.4 governed outbound packet'),
        ])->assertCreated()
            ->assertJsonPath('data.direction', 'OUTBOUND')
            ->assertJsonPath('data.status', 'AVAILABLE')
            ->assertJsonPath('data.record_version', 1);
        $documentId = (string) $published->json('data.id');
        $document = DB::table('partner_documents')->where('id', $documentId)->firstOrFail();
        Storage::disk('private')->assertExists((string) $document->storage_path);

        $this->signIn(self::PARTNER);
        $this->getJson('/api/v1/partner/documents/'.$documentId)->assertOk()
            ->assertJsonPath('data.party_id', self::NORTH)
            ->assertJsonPath('data.allowed_actions.0', 'DOWNLOAD')
            ->assertJsonPath('data.allowed_actions.1', 'ACKNOWLEDGE')
            ->assertJsonMissingPath('data.company_id')
            ->assertJsonMissingPath('data.plant_id')
            ->assertJsonMissingPath('data.created_by')
            ->assertJsonMissingPath('data.created_by_name')
            ->assertJsonMissingPath('data.storage_path');
        $this->get('/api/v1/partner/documents/'.$documentId.'/download')
            ->assertOk()
            ->assertHeader('X-Content-SHA256', (string) $document->sha256_checksum)
            ->assertHeader('X-Partner-Direction', 'OUTBOUND');

        $acknowledged = $this->withHeaders($this->headers(1))->postJson(
            '/api/v1/partner/documents/'.$documentId.'/acknowledge',
            ['acknowledgement_reference' => 'RECEIPT-NORTH-001'],
        )->assertOk()
            ->assertJsonPath('data.status', 'ACKNOWLEDGED')
            ->assertJsonPath('data.record_version', 2)
            ->assertJsonPath('data.business_effect', 'RECEIPT_ONLY');
        $this->assertSame($documentId, $acknowledged->json('data.id'));
        $this->assertDatabaseHas('partner_documents', [
            'id' => $documentId,
            'status' => 'ACKNOWLEDGED',
            'acknowledged_by' => self::PARTNER,
            'acknowledgement_reference' => 'RECEIPT-NORTH-001',
        ]);
        $this->assertSame(2, DB::table('partner_document_events')->where('partner_document_id', $documentId)->count());
        $this->assertSame(2, DB::table('audit_events')->where('entity_type', 'partner_document')->where('entity_id', $documentId)->count());
        $this->assertSame(2, DB::table('outbox_events')->where('aggregate_type', 'partner_document')->where('aggregate_id', $documentId)->count());

        $uploaded = $this->command()->postJson('/api/v1/partner/documents', [
            'document_number' => 'PORTAL-IN-001',
            'document_type' => 'CLAIM_EVIDENCE',
            'title' => 'Receiving photo evidence',
            'invoice_id' => self::NORTH_INVOICE,
            'file' => UploadedFile::fake()->createWithContent('receiving-photo.jpg', 'partner inbound evidence bytes'),
        ])->assertCreated()->assertJsonPath('data.direction', 'INBOUND');
        $this->assertDatabaseHas('partner_documents', [
            'id' => $uploaded->json('data.id'),
            'party_id' => self::NORTH,
            'direction' => 'INBOUND',
            'invoice_id' => self::NORTH_INVOICE,
        ]);
    }

    public function test_partner_can_submit_a_claim_only_for_its_own_shipment(): void
    {
        $created = $this->command()->postJson('/api/v1/partner/claims', [
            'claim_number' => 'PORTAL-CLAIM-001',
            'shipment_id' => self::NORTH_SHIPMENT,
            'claim_type' => 'DAMAGE',
            'requested_resolution' => 'CREDIT',
            'reason' => 'One delivered pack arrived visibly damaged.',
            'lines' => [[
                'shipment_line_id' => self::NORTH_SHIPMENT_LINE,
                'quantity' => '1',
            ]],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'OPEN')
            ->assertJsonPath('data.submitted_via', 'PARTNER_PORTAL');
        $claimId = (string) $created->json('data.id');
        $this->assertDatabaseHas('customer_claims', [
            'id' => $claimId,
            'customer_party_id' => self::NORTH,
            'created_by' => self::PARTNER,
        ]);
        $this->getJson('/api/v1/partner/claims/'.$claimId)->assertOk()
            ->assertJsonPath('data.claim_number', 'PORTAL-CLAIM-001')
            ->assertJsonCount(1, 'data.lines');

        $this->command()->postJson('/api/v1/partner/claims', [
            'claim_number' => 'PORTAL-CLAIM-CROSS-TENANT',
            'shipment_id' => self::CENTRAL_SHIPMENT,
            'claim_type' => 'SHORTAGE',
            'requested_resolution' => 'CREDIT',
            'reason' => 'Cross-tenant request must not reveal or mutate shipment data.',
            'lines' => [[
                'shipment_line_id' => '00000000-0000-4000-8000-000000001102',
                'quantity' => '1',
            ]],
        ])->assertNotFound();
        $this->assertDatabaseMissing('customer_claims', ['claim_number' => 'PORTAL-CLAIM-CROSS-TENANT']);
    }

    public function test_internal_admin_controls_identity_entitlements_and_revocation(): void
    {
        $newUserId = '10000000-0000-4000-8000-000000000205';
        $user = (array) DB::table('users')->where('id', self::PARTNER)->firstOrFail();
        DB::table('users')->insert(array_merge($user, [
            'id' => $newUserId,
            'email' => 'second.partner@qtfoods.local',
            'name' => 'Second Partner User',
            'last_login_at' => null,
            'last_login_ip' => null,
        ]));

        $this->signIn(self::ADMIN);
        $created = $this->command()->postJson('/api/v1/partner/access-grants', [
            'user_id' => $newUserId,
            'party_id' => self::CENTRAL,
            'effective_from' => '2026-04-01T00:00:00+05:30',
            'effective_to' => null,
            'entitlements' => ['SHIPMENTS_VIEW', 'INVOICES_VIEW', 'DOCUMENTS_VIEW', 'DOCUMENT_DOWNLOAD'],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.party_id', self::CENTRAL)
            ->assertJsonPath('data.user_id', $newUserId);
        $grantId = (string) $created->json('data.id');
        $assignmentId = (string) DB::table('partner_access_grants')->where('id', $grantId)->value('role_assignment_id');
        $this->assertDatabaseHas('role_assignments', [
            'id' => $assignmentId,
            'user_id' => $newUserId,
            'party_id' => self::CENTRAL,
            'is_active' => true,
        ]);

        $this->withHeaders($this->headers(1))->postJson('/api/v1/partner/access-grants/'.$grantId, [
            'effective_from' => '2026-04-01T00:00:00+05:30',
            'effective_to' => null,
            'entitlements' => ['SHIPMENTS_VIEW', 'DOCUMENTS_VIEW', 'DOCUMENT_UPLOAD'],
        ])->assertOk()
            ->assertJsonPath('data.record_version', 2)
            ->assertJsonPath('data.entitlements.2', 'SHIPMENTS_VIEW');
        $this->assertDatabaseMissing('partner_access_entitlements', [
            'partner_access_grant_id' => $grantId,
            'entitlement_code' => 'INVOICES_VIEW',
        ]);

        $this->withHeaders($this->headers(2))->postJson('/api/v1/partner/access-grants/'.$grantId.'/revoke', [
            'reason' => 'Partner contract ended.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'REVOKED')
            ->assertJsonPath('data.record_version', 3);
        $this->assertDatabaseHas('partner_access_grants', [
            'id' => $grantId,
            'status' => 'REVOKED',
            'revoked_by' => self::ADMIN,
        ]);
        $this->assertDatabaseHas('role_assignments', ['id' => $assignmentId, 'is_active' => false]);

        $this->signIn($newUserId);
        $this->getJson('/api/v1/partner/workspaces')->assertStatus(409)
            ->assertJsonPath('error.code', 'CONTEXT_REQUIRED');
        $this->assertSame(3, DB::table('audit_events')->where('entity_type', 'partner_access_grant')->where('entity_id', $grantId)->count());
        $this->assertSame(3, DB::table('outbox_events')->where('aggregate_type', 'partner_access_grant')->where('aggregate_id', $grantId)->count());

        $this->signIn(self::ADMIN);
        $this->command()->postJson('/api/v1/partner/access-grants', [
            'user_id' => self::ADMIN,
            'party_id' => self::CENTRAL,
            'entitlements' => ['SHIPMENTS_VIEW'],
        ])->assertUnprocessable()->assertJsonPath(
            'error.fields.user_id.0',
            'Portal identities cannot share internal ERP role assignments in the selected scope.',
        );
    }

    private function signIn(string $userId): void
    {
        $this->actingAs(User::query()->findOrFail($userId));
        $this->withSession([
            'erp.company_id' => self::COMPANY,
            'erp.plant_id' => self::PLANT,
        ]);
    }

    private function command()
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid());
    }

    private function headers(int $version): array
    {
        return [
            'Idempotency-Key' => (string) Str::uuid(),
            'If-Match' => (string) $version,
        ];
    }
}

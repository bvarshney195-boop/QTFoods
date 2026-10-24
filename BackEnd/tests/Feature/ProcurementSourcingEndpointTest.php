<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProcurementSourcingEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const FINANCE_PLANT_ID = '00000000-0000-4000-8000-000000000102';
    private const OPERATIONS_ID = '00000000-0000-4000-8000-000000000202';
    private const FINANCE_ID = '00000000-0000-4000-8000-000000000203';
    private const SALES_ID = '00000000-0000-4000-8000-000000000201';
    private const ADMIN_ID = '00000000-0000-4000-8000-000000000204';
    private const RAW_ITEM_ID = '00000000-0000-4000-8000-000000000603';
    private const FINISHED_ITEM_ID = '00000000-0000-4000-8000-000000000601';
    private const SUPPLIER_WESTERN = '00000000-0000-4000-8000-000000000503';
    private const SUPPLIER_DECCAN = '00000000-0000-4000-8000-000000000504';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->signIn(self::OPERATIONS_ID);
    }

    public function test_workspaces_are_live_scoped_and_permission_aware(): void
    {
        $this->getJson('/api/v1/procurement/rfqs')
            ->assertOk()
            ->assertJsonPath('summary.total', 0)
            ->assertJsonPath('summary.awarded_total', '0.000000')
            ->assertJsonPath('lookups.currency', 'INR')
            ->assertJsonCount(3, 'lookups.suppliers')
            ->assertJsonCount(0, 'lookups.approved_requisitions')
            ->assertJsonPath('allowed_actions.0', 'CREATE');
        $this->getJson('/api/v1/procurement/purchase-orders')
            ->assertOk()
            ->assertJsonPath('summary.total', 0)
            ->assertJsonPath('summary.committed_total', '0.000000')
            ->assertJsonCount(0, 'lookups.awarded_rfqs')
            ->assertJsonPath('allowed_actions.0', 'CREATE');

        $this->createApprovedRequisition('REQ-SOURCE-LOOKUP');
        $this->getJson('/api/v1/procurement/rfqs')
            ->assertOk()->assertJsonCount(1, 'lookups.approved_requisitions')
            ->assertJsonPath('lookups.approved_requisitions.0.number', 'REQ-SOURCE-LOOKUP');

        $this->signIn(self::SALES_ID);
        $this->getJson('/api/v1/procurement/rfqs')->assertForbidden();
        $this->getJson('/api/v1/procurement/purchase-orders')->assertForbidden();
    }

    public function test_rfq_draft_update_issue_and_reads_are_versioned_idempotent_and_relational(): void
    {
        $requisitionId = $this->createApprovedRequisition('REQ-RFQ-001');
        $payload = $this->rfqPayload($requisitionId, 'RFQ-TEST-001');
        $key = (string) Str::uuid();
        $created = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/procurement/rfqs', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.record_version', 1)
            ->assertJsonPath('data.line_count', 2)
            ->assertJsonPath('data.supplier_count', 2);
        $rfqId = (string) $created->json('data.id');
        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/procurement/rfqs', $payload)
            ->assertCreated()->assertExactJson($created->json());

        $this->getJson('/api/v1/procurement/rfqs/'.$rfqId)
            ->assertOk()
            ->assertJsonPath('data.requisition.id', $requisitionId)
            ->assertJsonPath('data.lines.0.item.code', 'SKU-APPLE-BASE')
            ->assertJsonPath('data.lines.0.quantity', '10.000000')
            ->assertJsonPath('data.allowed_actions.0', 'UPDATE')
            ->assertJsonPath('data.allowed_actions.1', 'ISSUE');

        $updatedPayload = [
            'response_due_date' => now()->addDays(5)->toDateString(),
            'commercial_terms' => 'Updated comparison terms include delivered pricing.',
            'supplier_ids' => [
                self::SUPPLIER_WESTERN,
                self::SUPPLIER_DECCAN,
                '00000000-0000-4000-8000-000000000502',
            ],
        ];
        $this->withHeaders($this->headers(1))->postJson('/api/v1/procurement/rfqs/'.$rfqId, $updatedPayload)
            ->assertOk()->assertJsonPath('data.record_version', 2)
            ->assertJsonPath('data.supplier_count', 3);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/procurement/rfqs/'.$rfqId, $updatedPayload)
            ->assertConflict()->assertJsonPath('error.code', 'CONFLICT');

        $issueKey = (string) Str::uuid();
        $issued = $this->withHeaders(['Idempotency-Key' => $issueKey, 'If-Match' => '2'])
            ->postJson('/api/v1/procurement/rfqs/'.$rfqId.'/issue')
            ->assertOk()->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.record_version', 3);
        $this->withHeaders(['Idempotency-Key' => $issueKey, 'If-Match' => '2'])
            ->postJson('/api/v1/procurement/rfqs/'.$rfqId.'/issue')
            ->assertOk()->assertExactJson($issued->json());

        $this->assertDatabaseHas('rfq_lines', [
            'rfq_id' => $rfqId, 'requisition_id' => $requisitionId,
            'item_id' => self::RAW_ITEM_ID, 'quantity' => 10, 'uom_code' => 'KG',
        ]);
        $this->assertDatabaseHas('rfq_suppliers', [
            'rfq_id' => $rfqId, 'supplier_party_id' => self::SUPPLIER_WESTERN, 'status' => 'INVITED',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'command' => 'ISSUE_REQUEST_FOR_QUOTATION', 'entity_id' => $rfqId, 'entity_version' => 3,
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'procurement.rfq.issued', 'aggregate_id' => $rfqId,
        ]);

        $this->command()->postJson('/api/v1/procurement/rfqs', $payload)
            ->assertUnprocessable()->assertJsonPath(
                'error.fields.rfq_number.0',
                'That RFQ number already exists in the selected plant.',
            );
    }

    public function test_supplier_comparison_requires_complete_quotes_and_explains_non_lowest_award(): void
    {
        [$rfqId, $version] = $this->createIssuedRfq('REQ-COMPARE-001', 'RFQ-COMPARE-001');
        $lines = $this->rfqLines($rfqId);
        $first = $this->quotePayload(self::SUPPLIER_WESTERN, 'WEST-1001', $lines, ['40', '20']);
        $quoteKey = (string) Str::uuid();
        $firstResponse = $this->withHeaders(['Idempotency-Key' => $quoteKey, 'If-Match' => (string) $version])
            ->postJson('/api/v1/procurement/rfqs/'.$rfqId.'/quotes', $first)
            ->assertOk()->assertJsonPath('data.record_version', $version + 1)
            ->assertJsonPath('data.quote_total', '510.000000');
        $firstQuoteId = (string) $firstResponse->json('data.quote_id');
        $this->withHeaders(['Idempotency-Key' => $quoteKey, 'If-Match' => (string) $version])
            ->postJson('/api/v1/procurement/rfqs/'.$rfqId.'/quotes', $first)
            ->assertOk()->assertExactJson($firstResponse->json());

        $incomplete = $this->quotePayload(self::SUPPLIER_DECCAN, 'DECCAN-BAD', $lines, ['42', '18']);
        array_pop($incomplete['lines']);
        $this->withHeaders($this->headers($version + 1))
            ->postJson('/api/v1/procurement/rfqs/'.$rfqId.'/quotes', $incomplete)
            ->assertUnprocessable()->assertJsonPath(
                'error.fields.lines.0',
                'Price every RFQ line exactly once.',
            );

        $second = $this->quotePayload(self::SUPPLIER_DECCAN, 'DECCAN-2001', $lines, ['42', '20']);
        $secondResponse = $this->withHeaders($this->headers($version + 1))
            ->postJson('/api/v1/procurement/rfqs/'.$rfqId.'/quotes', $second)
            ->assertOk()->assertJsonPath('data.record_version', $version + 2)
            ->assertJsonPath('data.quote_total', '530.000000');
        $secondQuoteId = (string) $secondResponse->json('data.quote_id');

        $this->getJson('/api/v1/procurement/rfqs/'.$rfqId)
            ->assertOk()->assertJsonCount(2, 'data.comparison')
            ->assertJsonPath('data.comparison.0.quote_id', $firstQuoteId)
            ->assertJsonPath('data.comparison.0.rank', 1)
            ->assertJsonPath('data.comparison.1.variance_from_lowest', '20.000000');

        $this->withHeaders($this->headers($version + 2))
            ->postJson('/api/v1/procurement/rfqs/'.$rfqId.'/award', [
                'supplier_quote_id' => $secondQuoteId,
            ])->assertUnprocessable()->assertJsonPath(
                'error.fields.award_reason.0',
                'Explain a non-lowest-price or late-delivery award.',
            );
        $awardKey = (string) Str::uuid();
        $awarded = $this->withHeaders([
            'Idempotency-Key' => $awardKey, 'If-Match' => (string) ($version + 2),
        ])->postJson('/api/v1/procurement/rfqs/'.$rfqId.'/award', [
            'supplier_quote_id' => $secondQuoteId,
            'award_reason' => 'Deccan provides the validated continuity-of-supply commitment.',
        ])->assertOk()->assertJsonPath('data.status', 'AWARDED')
            ->assertJsonPath('data.awarded_supplier_id', self::SUPPLIER_DECCAN)
            ->assertJsonPath('data.awarded_total', '530.000000');
        $this->withHeaders([
            'Idempotency-Key' => $awardKey, 'If-Match' => (string) ($version + 2),
        ])->postJson('/api/v1/procurement/rfqs/'.$rfqId.'/award', [
            'supplier_quote_id' => $secondQuoteId,
            'award_reason' => 'Deccan provides the validated continuity-of-supply commitment.',
        ])->assertOk()->assertExactJson($awarded->json());

        $this->assertDatabaseHas('rfq_suppliers', [
            'rfq_id' => $rfqId, 'supplier_party_id' => self::SUPPLIER_DECCAN, 'status' => 'AWARDED',
        ]);
        $this->assertDatabaseHas('rfq_suppliers', [
            'rfq_id' => $rfqId, 'supplier_party_id' => self::SUPPLIER_WESTERN, 'status' => 'NOT_SELECTED',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'command' => 'AWARD_REQUEST_FOR_QUOTATION', 'entity_id' => $rfqId,
        ]);
    }

    public function test_award_cannot_exceed_the_approved_requisition_ceiling(): void
    {
        [$rfqId, $version] = $this->createIssuedRfq('REQ-CEILING-001', 'RFQ-CEILING-001');
        $lines = $this->rfqLines($rfqId);
        $expensive = $this->quotePayload(self::SUPPLIER_WESTERN, 'WEST-HIGH', $lines, ['60', '30']);
        $response = $this->withHeaders($this->headers($version))
            ->postJson('/api/v1/procurement/rfqs/'.$rfqId.'/quotes', $expensive)
            ->assertOk()->assertJsonPath('data.quote_total', '760.000000');

        $this->withHeaders($this->headers($version + 1))
            ->postJson('/api/v1/procurement/rfqs/'.$rfqId.'/award', [
                'supplier_quote_id' => $response->json('data.quote_id'),
                'award_reason' => 'Only compliant response received.',
            ])->assertUnprocessable()->assertJsonPath(
                'error.fields.supplier_quote_id.0',
                'The selected quote exceeds the approved requisition value. Revise and reapprove the requirement before award.',
            );
    }

    public function test_purchase_order_conversion_amendment_issue_and_cancellation_preserve_revision_lineage(): void
    {
        [$rfqId, $quoteId] = $this->createAwardedRfq('REQ-PO-001', 'RFQ-PO-001');
        $poKey = (string) Str::uuid();
        $poPayload = [
            'po_number' => 'PO-TEST-001',
            'rfq_id' => $rfqId,
            'order_date' => now()->toDateString(),
            'incoterm_code' => 'DAP',
            'delivery_terms' => 'Delivered to the training plant receiving dock.',
            'notes' => 'Generated from the governed supplier award.',
        ];
        $created = $this->withHeader('Idempotency-Key', $poKey)
            ->postJson('/api/v1/procurement/purchase-orders', $poPayload)
            ->assertCreated()->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.record_version', 1)
            ->assertJsonPath('data.revision_number', 1)
            ->assertJsonPath('data.total_amount', '510.000000');
        $poId = (string) $created->json('data.id');
        $this->withHeader('Idempotency-Key', $poKey)
            ->postJson('/api/v1/procurement/purchase-orders', $poPayload)
            ->assertCreated()->assertExactJson($created->json());

        $detail = $this->getJson('/api/v1/procurement/purchase-orders/'.$poId)
            ->assertOk()->assertJsonPath('data.rfq.id', $rfqId)
            ->assertJsonPath('data.supplier_quote_id', $quoteId)
            ->assertJsonCount(2, 'data.lines')->assertJsonCount(1, 'data.revisions');
        $lines = $detail->json('data.lines');
        $amendment = $this->amendmentPayload($lines, 'Quantity aligned with the revised production release.');
        $amendment['lines'][0]['ordered_quantity'] = '8';
        $amendKey = (string) Str::uuid();
        $amended = $this->withHeaders(['Idempotency-Key' => $amendKey, 'If-Match' => '1'])
            ->postJson('/api/v1/procurement/purchase-orders/'.$poId.'/amend', $amendment)
            ->assertOk()->assertJsonPath('data.record_version', 2)
            ->assertJsonPath('data.revision_number', 2)
            ->assertJsonPath('data.total_amount', '430.000000');
        $this->withHeaders(['Idempotency-Key' => $amendKey, 'If-Match' => '1'])
            ->postJson('/api/v1/procurement/purchase-orders/'.$poId.'/amend', $amendment)
            ->assertOk()->assertExactJson($amended->json());

        $this->withHeaders($this->headers(2))
            ->postJson('/api/v1/procurement/purchase-orders/'.$poId.'/issue')
            ->assertOk()->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.record_version', 3);
        $issuedDetail = $this->getJson('/api/v1/procurement/purchase-orders/'.$poId)->assertOk();
        $postIssueAmendment = $this->amendmentPayload(
            $issuedDetail->json('data.lines'),
            'Supplier confirmed an earlier delivery slot after order issue.',
        );
        $postIssueAmendment['required_by_date'] = now()->addDays(20)->toDateString();
        $this->withHeaders($this->headers(3))
            ->postJson('/api/v1/procurement/purchase-orders/'.$poId.'/amend', $postIssueAmendment)
            ->assertOk()->assertJsonPath('data.status', 'ISSUED')
            ->assertJsonPath('data.record_version', 4)
            ->assertJsonPath('data.revision_number', 3);

        $this->withHeaders($this->headers(4))
            ->postJson('/api/v1/procurement/purchase-orders/'.$poId.'/cancel', [
                'reason' => 'Production programme was withdrawn before receipt.',
            ])->assertOk()->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.record_version', 5);
        $this->getJson('/api/v1/procurement/purchase-orders/'.$poId)
            ->assertOk()->assertJsonCount(3, 'data.revisions')
            ->assertJsonPath('data.revisions.0.revision_number', 3)
            ->assertJsonPath('data.revisions.0.reason', 'Supplier confirmed an earlier delivery slot after order issue.');

        $this->assertDatabaseHas('purchase_order_revisions', [
            'purchase_order_id' => $poId, 'revision_number' => 2,
            'reason' => 'Quantity aligned with the revised production release.',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'command' => 'AMEND_PURCHASE_ORDER', 'entity_id' => $poId, 'entity_version' => 4,
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'procurement.purchase-order.cancelled', 'aggregate_id' => $poId,
        ]);
    }

    public function test_scope_action_and_upstream_cancellation_controls_are_enforced(): void
    {
        [$rfqId] = $this->createIssuedRfq('REQ-CONTROL-001', 'RFQ-CONTROL-001');
        $requisitionId = (string) DB::table('requests_for_quotation')->where('id', $rfqId)
            ->value('requisition_id');
        $this->withHeaders($this->headers(3))
            ->postJson('/api/v1/procurement/requisitions/'.$requisitionId.'/cancel', [
                'reason' => 'Attempt to bypass active sourcing.',
            ])->assertUnprocessable()->assertJsonPath(
                'error.fields.status.0',
                'Cancel the active RFQ or purchase order before cancelling this requisition.',
            );

        $this->signIn(self::ADMIN_ID, self::FINANCE_PLANT_ID);
        $this->getJson('/api/v1/procurement/rfqs/'.$rfqId)->assertNotFound();

        $this->signIn(self::OPERATIONS_ID);
        $permissionId = DB::table('permissions')->where('code', 'ACTION:PUR-RFQ:AWARD')->value('id');
        $roleId = DB::table('roles')->where('code', 'OPERATIONS_MANAGER')->value('id');
        DB::table('role_permissions')->where('role_id', $roleId)
            ->where('permission_id', $permissionId)->delete();
        $this->withHeaders($this->headers(2))
            ->postJson('/api/v1/procurement/rfqs/'.$rfqId.'/award', [
                'supplier_quote_id' => (string) Str::uuid(),
            ])->assertForbidden();
    }

    public function test_repeated_seeding_preserves_live_sourcing_and_order_revisions(): void
    {
        [$rfqId] = $this->createAwardedRfq('REQ-RESEED-SOURCE', 'RFQ-RESEED-SOURCE');
        $poId = (string) $this->command()->postJson('/api/v1/procurement/purchase-orders', [
            'po_number' => 'PO-RESEED-001',
            'rfq_id' => $rfqId,
            'order_date' => now()->toDateString(),
            'incoterm_code' => 'DAP',
            'delivery_terms' => 'Preserve this transaction during reference reseeding.',
            'notes' => null,
        ])->assertCreated()->json('data.id');

        $this->seed();
        $this->seed();

        $this->assertDatabaseHas('requests_for_quotation', ['id' => $rfqId, 'status' => 'AWARDED']);
        $this->assertDatabaseHas('purchase_orders', [
            'id' => $poId, 'po_number' => 'PO-RESEED-001', 'status' => 'DRAFT', 'revision_number' => 1,
        ]);
        $this->assertDatabaseHas('purchase_order_revisions', [
            'purchase_order_id' => $poId, 'revision_number' => 1,
        ]);
        $this->assertDatabaseCount('purchase_order_lines', 2);
    }

    private function requisitionPayload(string $number): array
    {
        return [
            'requisition_number' => $number,
            'department' => 'Manufacturing',
            'purpose' => 'Source controlled materials for the governed production plan.',
            'requested_date' => now()->toDateString(),
            'required_by_date' => now()->addDays(30)->toDateString(),
            'currency' => 'INR',
            'lines' => [
                [
                    'item_id' => self::RAW_ITEM_ID,
                    'quantity' => '10',
                    'estimated_unit_cost' => '50',
                    'notes' => 'Use the approved ingredient specification.',
                ],
                [
                    'item_id' => self::FINISHED_ITEM_ID,
                    'quantity' => '5',
                    'estimated_unit_cost' => '30',
                    'notes' => null,
                ],
            ],
        ];
    }

    private function createApprovedRequisition(string $number): string
    {
        $id = (string) $this->command()->postJson(
            '/api/v1/procurement/requisitions',
            $this->requisitionPayload($number),
        )->assertCreated()->json('data.id');
        $approvalId = (string) $this->withHeaders($this->headers(1))
            ->postJson('/api/v1/procurement/requisitions/'.$id.'/submit')
            ->assertOk()->json('data.approval_request_id');
        $this->signIn(self::FINANCE_ID);
        $this->withHeaders($this->headers(1))
            ->postJson('/api/v1/procurement/requisition-approvals/'.$approvalId.'/approve', [
                'reason' => 'Budget and production requirement verified for sourcing.',
            ])->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->signIn(self::OPERATIONS_ID);

        return $id;
    }

    private function rfqPayload(string $requisitionId, string $number): array
    {
        return [
            'rfq_number' => $number,
            'requisition_id' => $requisitionId,
            'response_due_date' => now()->addDays(3)->toDateString(),
            'commercial_terms' => 'Prices must be delivered, exclusive of recoverable tax, and valid through award.',
            'supplier_ids' => [self::SUPPLIER_WESTERN, self::SUPPLIER_DECCAN],
        ];
    }

    private function createIssuedRfq(string $requisitionNumber, string $rfqNumber): array
    {
        $requisitionId = $this->createApprovedRequisition($requisitionNumber);
        $rfqId = (string) $this->command()->postJson(
            '/api/v1/procurement/rfqs',
            $this->rfqPayload($requisitionId, $rfqNumber),
        )->assertCreated()->json('data.id');
        $version = (int) $this->withHeaders($this->headers(1))
            ->postJson('/api/v1/procurement/rfqs/'.$rfqId.'/issue')
            ->assertOk()->json('data.record_version');

        return [$rfqId, $version];
    }

    private function createAwardedRfq(string $requisitionNumber, string $rfqNumber): array
    {
        [$rfqId, $version] = $this->createIssuedRfq($requisitionNumber, $rfqNumber);
        $quote = $this->withHeaders($this->headers($version))
            ->postJson('/api/v1/procurement/rfqs/'.$rfqId.'/quotes', $this->quotePayload(
                self::SUPPLIER_WESTERN,
                'WEST-AWARD-'.$rfqNumber,
                $this->rfqLines($rfqId),
                ['40', '20'],
            ))->assertOk();
        $quoteId = (string) $quote->json('data.quote_id');
        $this->withHeaders($this->headers($version + 1))
            ->postJson('/api/v1/procurement/rfqs/'.$rfqId.'/award', [
                'supplier_quote_id' => $quoteId,
                'award_reason' => null,
            ])->assertOk()->assertJsonPath('data.status', 'AWARDED');

        return [$rfqId, $quoteId];
    }

    private function rfqLines(string $rfqId): array
    {
        return $this->getJson('/api/v1/procurement/rfqs/'.$rfqId)
            ->assertOk()->json('data.lines');
    }

    private function quotePayload(string $supplierId, string $number, array $lines, array $prices): array
    {
        return [
            'supplier_party_id' => $supplierId,
            'quote_number' => $number,
            'quote_date' => now()->toDateString(),
            'valid_until' => now()->addDays(15)->toDateString(),
            'promised_delivery_date' => now()->addDays(25)->toDateString(),
            'payment_terms_days' => 30,
            'freight_amount' => '10',
            'other_charges' => '0',
            'discount_amount' => '0',
            'notes' => 'Supplier response recorded from the signed quotation.',
            'lines' => collect($lines)->values()->map(fn (array $line, int $index): array => [
                'rfq_line_id' => $line['id'],
                'unit_price' => $prices[$index],
                'notes' => null,
            ])->all(),
        ];
    }

    private function amendmentPayload(array $lines, string $reason): array
    {
        return [
            'reason' => $reason,
            'required_by_date' => now()->addDays(25)->toDateString(),
            'payment_terms_days' => 30,
            'freight_amount' => '10',
            'other_charges' => '0',
            'discount_amount' => '0',
            'incoterm_code' => 'DAP',
            'delivery_terms' => 'Delivered to the controlled receiving dock.',
            'notes' => 'Commercial amendment retained with its complete revision snapshot.',
            'lines' => collect($lines)->map(fn (array $line): array => [
                'rfq_line_id' => $line['rfq_line_id'],
                'ordered_quantity' => $line['ordered_quantity'],
                'unit_price' => $line['unit_price'],
                'notes' => $line['notes'],
            ])->all(),
        ];
    }

    private function signIn(string $userId, string $plantId = self::PLANT_ID): void
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

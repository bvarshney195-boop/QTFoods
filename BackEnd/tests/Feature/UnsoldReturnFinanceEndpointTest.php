<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use App\Modules\Sales\Application\UnsoldSalesReturnService;
use App\Shared\Approval\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UnsoldReturnFinanceEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const TRAINING_PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const FINANCE_PLANT_ID = '00000000-0000-4000-8000-000000000102';
    private const SALES_USER_ID = '00000000-0000-4000-8000-000000000201';
    private const OPERATIONS_USER_ID = '00000000-0000-4000-8000-000000000202';
    private const FINANCE_USER_ID = '00000000-0000-4000-8000-000000000203';
    private const OPEN_INVOICE_ID = '00000000-0000-4000-8000-000000001301';
    private const OTHER_INVOICE_ID = '00000000-0000-4000-8000-000000001302';
    private const PAID_INVOICE_ID = '00000000-0000-4000-8000-000000001303';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->signIn(self::FINANCE_USER_ID, self::TRAINING_PLANT_ID);
    }

    public function test_open_invoice_is_resolved_through_separate_idempotent_finance_actions(): void
    {
        $caseId = $this->createLossPostedCase(self::TRAINING_PLANT_ID, null);

        $invoiceHeaders = $this->headers(4);
        $invoice = $this->withHeaders($invoiceHeaders)
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/finance/invoice", [
                'invoice_id' => self::OPEN_INVOICE_ID,
                'notes' => 'Matched to the dispatch and distributor account.',
            ]);

        $invoice
            ->assertOk()
            ->assertJsonPath('data.action_type', 'INVOICE_LINK')
            ->assertJsonPath('data.reference_number', 'INV-2026-0001')
            ->assertJsonPath('data.record_version', 5);

        $this->withHeaders($invoiceHeaders)
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/finance/invoice", [
                'invoice_id' => self::OPEN_INVOICE_ID,
                'notes' => 'Matched to the dispatch and distributor account.',
            ])
            ->assertOk()
            ->assertExactJson($invoice->json());

        $this->withHeaders($this->headers(5))
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/finance/credit-note", [
                'document_number' => 'CN-2026-0001',
                'amount' => '800',
                'currency' => 'INR',
                'notes' => 'Net value approved for returned goods.',
            ])
            ->assertOk()
            ->assertJsonPath('data.action_type', 'CREDIT_NOTE')
            ->assertJsonPath('data.amount', '800.0000')
            ->assertJsonPath('data.record_version', 6);

        $this->withHeaders($this->headers(6))
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/finance/tax-adjustment", [
                'document_number' => 'TAX-2026-0001',
                'amount' => '144',
                'currency' => 'INR',
                'tax_code' => 'gst18',
            ])
            ->assertOk()
            ->assertJsonPath('data.action_type', 'TAX_ADJUSTMENT')
            ->assertJsonPath('data.tax_code', 'GST18')
            ->assertJsonPath('data.record_version', 7);

        $settlementHeaders = $this->headers(7);
        $settlementPayload = [
            'reference_number' => 'AR-2026-0001',
            'notes' => 'Applied to the distributor open balance.',
        ];
        $settlement = $this->withHeaders($settlementHeaders)
            ->postJson(
                "/api/v1/sales/unsold-returns/{$caseId}/finance/receivable-adjustment",
                $settlementPayload
            );

        $settlement
            ->assertOk()
            ->assertJsonPath('data.action_type', 'RECEIVABLE_ADJUSTMENT')
            ->assertJsonPath('data.amount', '944.0000')
            ->assertJsonPath('data.outstanding_amount', '3056.0000')
            ->assertJsonPath('data.status', 'FINANCE_RESOLVED')
            ->assertJsonPath('data.record_version', 8)
            ->assertJsonPath('data.invoice_record_version', 2);

        $this->withHeaders($settlementHeaders)
            ->postJson(
                "/api/v1/sales/unsold-returns/{$caseId}/finance/receivable-adjustment",
                $settlementPayload
            )
            ->assertOk()
            ->assertExactJson($settlement->json());

        $this->assertDatabaseCount('unsold_return_finance_actions', 4);
        $this->assertDatabaseHas('unsold_return_cases', [
            'id' => $caseId,
            'status' => 'FINANCE_RESOLVED',
            'record_version' => 8,
        ]);
        $this->assertDatabaseHas('unsold_return_status_history', [
            'return_case_id' => $caseId,
            'from_status' => 'LOSS_POSTED',
            'to_status' => 'FINANCE_RESOLVED',
            'record_version' => 8,
        ]);
        $this->assertSame(
            3056.0,
            (float) DB::table('sales_invoice_financials')
                ->where('invoice_id', self::OPEN_INVOICE_ID)
                ->value('outstanding_amount')
        );

        foreach ([
            'LINK_UNSOLD_RETURN_INVOICE',
            'POST_UNSOLD_RETURN_CREDIT_NOTE',
            'POST_UNSOLD_RETURN_TAX_ADJUSTMENT',
            'APPLY_UNSOLD_RETURN_RECEIVABLE',
        ] as $command) {
            $this->assertDatabaseHas('audit_events', ['command' => $command]);
        }
        $this->assertSame(
            4,
            DB::table('outbox_events')
                ->where('aggregate_id', $caseId)
                ->where('event_type', 'sales.unsold_return.finance_action_posted')
                ->count()
        );

        $detail = $this->getJson("/api/v1/sales/unsold-returns/{$caseId}")
            ->assertOk()
            ->assertJsonPath('data.invoice.number', 'INV-2026-0001')
            ->assertJsonPath('data.invoice.outstanding_amount', '3056')
            ->assertJsonPath('data.finance.stage', 'RESOLVED')
            ->assertJsonPath('data.finance.credit_total', '944.0000')
            ->assertJsonPath('data.finance.settlement_type', 'RECEIVABLE_ADJUSTMENT')
            ->assertJsonCount(4, 'data.finance.actions');

        $this->assertSame(
            ['INVOICE_LINK', 'CREDIT_NOTE', 'TAX_ADJUSTMENT', 'RECEIVABLE_ADJUSTMENT'],
            array_column($detail->json('data.finance.actions'), 'action_type')
        );
        $this->assertSame([5, 6, 7, 8], array_column($detail->json('data.finance.actions'), 'case_record_version'));
    }

    public function test_paid_invoice_can_finish_with_a_refund(): void
    {
        $caseId = $this->createLossPostedCase(self::FINANCE_PLANT_ID, self::PAID_INVOICE_ID);
        $this->signIn(self::FINANCE_USER_ID, self::FINANCE_PLANT_ID);
        $this->postFinancePrerequisites($caseId, self::PAID_INVOICE_ID, 'REF');

        $this->withHeaders($this->headers(7))
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/finance/refund", [
                'reference_number' => 'PAY-REFUND-0001',
            ])
            ->assertOk()
            ->assertJsonPath('data.action_type', 'REFUND')
            ->assertJsonPath('data.amount', '118.0000')
            ->assertJsonPath('data.outstanding_amount', '0')
            ->assertJsonPath('data.status', 'FINANCE_RESOLVED');

        $this->assertDatabaseHas('audit_events', ['command' => 'POST_UNSOLD_RETURN_REFUND']);
    }

    public function test_paid_invoice_can_finish_with_a_replacement_authorisation(): void
    {
        $caseId = $this->createLossPostedCase(self::FINANCE_PLANT_ID, self::PAID_INVOICE_ID);
        $this->signIn(self::FINANCE_USER_ID, self::FINANCE_PLANT_ID);
        $this->postFinancePrerequisites($caseId, self::PAID_INVOICE_ID, 'REP');

        $this->withHeaders($this->headers(7))
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/finance/replacement", [
                'reference_number' => 'REPL-ORDER-0001',
                'notes' => 'Replacement authorised instead of cash settlement.',
            ])
            ->assertOk()
            ->assertJsonPath('data.action_type', 'REPLACEMENT')
            ->assertJsonPath('data.status', 'FINANCE_RESOLVED');

        $this->assertDatabaseHas('audit_events', ['command' => 'AUTHORISE_UNSOLD_RETURN_REPLACEMENT']);
    }

    public function test_settlement_path_is_guarded_by_invoice_balance_and_is_mutually_exclusive(): void
    {
        $caseId = $this->createLossPostedCase(self::TRAINING_PLANT_ID, self::OPEN_INVOICE_ID);
        $this->postFinancePrerequisites($caseId, self::OPEN_INVOICE_ID, 'GUARD');

        $this->withHeaders($this->headers(7))
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/finance/refund", [
                'reference_number' => 'INVALID-REFUND',
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.fields.settlement.0',
                'Refund and replacement settlement require a fully paid source invoice. Use receivable adjustment for an open invoice.'
            );

        DB::table('sales_invoice_financials')
            ->where('invoice_id', self::OPEN_INVOICE_ID)
            ->update(['outstanding_amount' => '50']);

        $this->withHeaders($this->headers(7))
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/finance/receivable-adjustment", [
                'reference_number' => 'EXCESS-AR-0001',
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.fields.settlement.0',
                'The open receivable is lower than the approved credit and tax total. Use the paid-invoice refund or replacement path after reconciling the invoice.'
            );

        $this->assertDatabaseCount('unsold_return_finance_actions', 3);
        $this->assertDatabaseHas('unsold_return_cases', [
            'id' => $caseId,
            'status' => 'LOSS_POSTED',
            'record_version' => 7,
        ]);

        $this->assertSame(
            50.0,
            (float) DB::table('sales_invoice_financials')
                ->where('invoice_id', self::OPEN_INVOICE_ID)
                ->value('outstanding_amount')
        );
        DB::table('sales_invoice_financials')
            ->where('invoice_id', self::OPEN_INVOICE_ID)
            ->update(['outstanding_amount' => '4000']);

        $this->withHeaders($this->headers(7))
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/finance/receivable-adjustment", [
                'reference_number' => 'VALID-AR-0001',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'FINANCE_RESOLVED');

        $this->withHeaders($this->headers(8))
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/finance/replacement", [
                'reference_number' => 'SECOND-SETTLEMENT',
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.fields.state.0',
                'Finance treatment is available only after the approved inventory loss is posted.'
            );

        $this->assertDatabaseCount('unsold_return_finance_actions', 4);
    }

    public function test_invoice_can_back_multiple_returns_but_posted_document_references_are_unique(): void
    {
        $firstCaseId = $this->createLossPostedCase(self::TRAINING_PLANT_ID, null);
        $secondCaseId = $this->createLossPostedCase(self::TRAINING_PLANT_ID, null);

        foreach ([$firstCaseId, $secondCaseId] as $caseId) {
            $this->withHeaders($this->headers(4))
                ->postJson("/api/v1/sales/unsold-returns/{$caseId}/finance/invoice", [
                    'invoice_id' => self::OPEN_INVOICE_ID,
                ])
                ->assertOk()
                ->assertJsonPath('data.reference_number', 'INV-2026-0001');
        }

        $credit = [
            'document_number' => 'CN-COMPANY-UNIQUE',
            'amount' => '100',
            'currency' => 'INR',
        ];
        $this->withHeaders($this->headers(5))
            ->postJson("/api/v1/sales/unsold-returns/{$firstCaseId}/finance/credit-note", $credit)
            ->assertOk();

        $this->withHeaders($this->headers(5))
            ->postJson("/api/v1/sales/unsold-returns/{$secondCaseId}/finance/credit-note", $credit)
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.fields.document_number.0',
                'This finance reference has already been used in the company.'
            );

        $this->assertDatabaseHas('unsold_return_cases', [
            'id' => $secondCaseId,
            'record_version' => 5,
        ]);
        $this->assertSame(
            2,
            DB::table('unsold_return_finance_actions')
                ->where('action_type', 'INVOICE_LINK')
                ->where('invoice_id', self::OPEN_INVOICE_ID)
                ->count()
        );
    }

    public function test_finance_actions_require_permission_headers_sequence_and_scoped_invoice(): void
    {
        $caseId = $this->createLossPostedCase(self::TRAINING_PLANT_ID, null);

        $this->postJson("/api/v1/sales/unsold-returns/{$caseId}/finance/invoice", [
            'invoice_id' => self::OPEN_INVOICE_ID,
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.if_match.0', 'The If-Match header is required for this finance action.');

        $this->withHeaders(['If-Match' => '4', 'Idempotency-Key' => ''])
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/finance/invoice", [
                'invoice_id' => self::OPEN_INVOICE_ID,
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.idempotency_key.0', 'The Idempotency-Key header is required.');

        $this->withHeaders($this->headers(4))
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/finance/credit-note", [
                'document_number' => 'EARLY-CN',
                'amount' => '100',
                'currency' => 'INR',
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.finance_action.0', 'Complete INVOICE_LINK before continuing.');

        $this->withHeaders($this->headers(4))
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/finance/invoice", [
                'invoice_id' => self::OTHER_INVOICE_ID,
            ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.fields.invoice_id.0',
                'Select a posted invoice for this return customer, shipment, company, and plant.'
            );

        $this->signIn(self::OPERATIONS_USER_ID, self::TRAINING_PLANT_ID);
        $this->withHeaders($this->headers(4))
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/finance/invoice", [
                'invoice_id' => self::OPEN_INVOICE_ID,
            ])
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    private function postFinancePrerequisites(string $caseId, string $invoiceId, string $suffix): void
    {
        $this->withHeaders($this->headers(4))
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/finance/invoice", [
                'invoice_id' => $invoiceId,
            ])
            ->assertOk();

        $this->withHeaders($this->headers(5))
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/finance/credit-note", [
                'document_number' => "CN-{$suffix}",
                'amount' => '100',
                'currency' => 'INR',
            ])
            ->assertOk();

        $this->withHeaders($this->headers(6))
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/finance/tax-adjustment", [
                'document_number' => "TAX-{$suffix}",
                'amount' => '18',
                'currency' => 'INR',
                'tax_code' => 'GST18',
            ])
            ->assertOk();
    }

    private function createLossPostedCase(string $plantId, ?string $invoiceId): string
    {
        $financePlant = $plantId === self::FINANCE_PLANT_ID;
        $shipmentId = $financePlant
            ? '00000000-0000-4000-8000-000000001003'
            : '00000000-0000-4000-8000-000000001001';
        $shipmentLineId = $financePlant
            ? '00000000-0000-4000-8000-000000001103'
            : '00000000-0000-4000-8000-000000001101';
        $positionId = $financePlant
            ? '00000000-0000-4000-8000-000000001203'
            : '00000000-0000-4000-8000-000000001201';
        $scope = ['company_id' => self::COMPANY_ID, 'plant_id' => $plantId];
        $service = $this->app->make(UnsoldSalesReturnService::class);

        $case = $service->createRequest($scope + [
            'party_id' => '00000000-0000-4000-8000-000000000501',
            'shipment_id' => $shipmentId,
            'invoice_id' => $invoiceId,
            'reason_code' => 'UNSOLD_MARKET_RETURN',
            'actor_id' => self::SALES_USER_ID,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'shipment_line_id' => $shipmentLineId,
                'sku_id' => '00000000-0000-4000-8000-000000000601',
                'fg_lot_id' => '00000000-0000-4000-8000-000000000701',
                'requested_quantity' => '10',
                'uom_code' => 'PACK',
            ]],
        ]);
        $caseId = $case['return_case_id'];
        $lineId = (string) DB::table('unsold_return_lines')
            ->where('return_case_id', $caseId)
            ->value('id');

        $service->receive($caseId, $scope + [
            'actor_id' => self::OPERATIONS_USER_ID,
            'expected_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'line_id' => $lineId,
                'received_quantity' => '10',
                'return_position_id' => $positionId,
            ]],
        ]);
        $disposition = $service->disposition($caseId, $scope + [
            'actor_id' => self::OPERATIONS_USER_ID,
            'expected_version' => 2,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'line_id' => $lineId,
                'restock_quantity' => '0',
                'repack_quantity' => '0',
                'rework_quantity' => '0',
                'destroy_quantity' => '10',
                'quality_reason_code' => 'SHORT_SHELF_LIFE',
            ]],
        ]);

        $this->app->make(ApprovalService::class)->decide(
            $disposition['approval_request_id'],
            self::FINANCE_USER_ID,
            'APPROVE',
            'Verified for finance endpoint testing.'
        );
        $service->postLossAfterApproval($caseId, $scope + [
            'actor_id' => self::FINANCE_USER_ID,
            'expected_version' => 3,
            'idempotency_key' => (string) Str::uuid(),
            'uom_code' => 'PACK',
            'currency' => 'INR',
        ]);

        return $caseId;
    }

    private function headers(int $version): array
    {
        return [
            'If-Match' => (string) $version,
            'Idempotency-Key' => (string) Str::uuid(),
            'X-Correlation-ID' => (string) Str::uuid(),
        ];
    }

    private function signIn(string $userId, string $plantId): void
    {
        $this->actingAs(User::query()->findOrFail($userId))->withSession([
            'erp.company_id' => self::COMPANY_ID,
            'erp.plant_id' => $plantId,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ProcureToPayEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const FINANCE_PLANT_ID = '00000000-0000-4000-8000-000000000102';
    private const OPERATIONS_ID = '00000000-0000-4000-8000-000000000202';
    private const FINANCE_ID = '00000000-0000-4000-8000-000000000203';
    private const ADMIN_ID = '00000000-0000-4000-8000-000000000204';
    private const SALES_ID = '00000000-0000-4000-8000-000000000201';
    private const RAW_ITEM_ID = '00000000-0000-4000-8000-000000000603';
    private const SUPPLIER_ID = '00000000-0000-4000-8000-000000000503';
    private const OTHER_SUPPLIER_ID = '00000000-0000-4000-8000-000000000504';
    private const QUALITY_HOLD_LOCATION = '00000000-0000-4000-8000-000000000810';
    private const RAW_STORE_LOCATION = '00000000-0000-4000-8000-000000000809';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->signIn(self::OPERATIONS_ID);
    }

    public function test_partial_receipts_qc_supplier_return_and_stock_posting_complete_the_inbound_chain(): void
    {
        [$poId, $poLineId] = $this->createIssuedOrder('INBOUND-A');
        [$firstReceiptId, $firstTaskId] = $this->receive($poId, $poLineId, '01', '4', 'LOT-P2P-A');
        $quality = $this->getJson('/api/v1/quality/incoming/'.$firstTaskId)->assertOk()
            ->assertJsonPath('data.receipt.id', $firstReceiptId)
            ->assertJsonPath('data.lines.0.inspected_quantity', '4.000000');
        $qualityLineId = (string) $quality->json('data.lines.0.id');
        $qcKey = (string) Str::uuid();
        $completed = $this->withHeaders(['Idempotency-Key' => $qcKey, 'If-Match' => '1'])
            ->postJson('/api/v1/quality/incoming/'.$firstTaskId.'/complete', [
                'notes' => 'Incoming sample inspected against the approved specification.',
                'lines' => [[
                    'quality_line_id' => $qualityLineId, 'accepted_quantity' => '3',
                    'rejected_quantity' => '1', 'rejection_reason' => 'Moisture exceeds the incoming limit.',
                ]],
            ])->assertOk()->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.accepted_quantity', '3.000000')->assertJsonPath('data.rejected_quantity', '1.000000');
        $this->withHeaders(['Idempotency-Key' => $qcKey, 'If-Match' => '1'])
            ->postJson('/api/v1/quality/incoming/'.$firstTaskId.'/complete', [
                'notes' => 'Incoming sample inspected against the approved specification.',
                'lines' => [[
                    'quality_line_id' => $qualityLineId, 'accepted_quantity' => '3',
                    'rejected_quantity' => '1', 'rejection_reason' => 'Moisture exceeds the incoming limit.',
                ]],
            ])->assertOk()->assertExactJson($completed->json());

        $return = $this->command()->postJson('/api/v1/procurement/supplier-returns', [
            'return_number' => 'SRT-P2P-001', 'return_date' => now()->toDateString(),
            'reason' => 'Return rejected incoming material to the supplier.',
            'lines' => [['quality_line_id' => $qualityLineId, 'return_quantity' => '1', 'reason' => 'QC rejection']],
        ])->assertCreated()->assertJsonPath('data.status', 'DRAFT');
        $returnId = (string) $return->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/procurement/supplier-returns/'.$returnId.'/post')
            ->assertOk()->assertJsonPath('data.status', 'POSTED');

        [, $secondTaskId] = $this->receive($poId, $poLineId, '02', '6', 'LOT-P2P-B');
        $secondQuality = $this->getJson('/api/v1/quality/incoming/'.$secondTaskId)->assertOk();
        $this->withHeaders($this->headers(1))->postJson('/api/v1/quality/incoming/'.$secondTaskId.'/complete', [
            'notes' => 'Second partial delivery accepted in full.',
            'lines' => [[
                'quality_line_id' => $secondQuality->json('data.lines.0.id'), 'accepted_quantity' => '6',
                'rejected_quantity' => '0', 'rejection_reason' => null,
            ]],
        ])->assertOk()->assertJsonPath('data.accepted_quantity', '6.000000');

        $this->assertDatabaseHas('receipts', ['id' => $firstReceiptId, 'status' => 'COMPLETED']);
        $this->assertDatabaseHas('incoming_quality_lines', ['id' => $qualityLineId, 'result' => 'PARTIAL']);
        $this->assertDatabaseHas('supplier_returns', ['id' => $returnId, 'status' => 'POSTED']);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'PURCHASE_RECEIPT', 'source_type' => 'goods_receipt_line']);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'INCOMING_QC_ACCEPT', 'source_id' => $qualityLineId]);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'INCOMING_QC_REJECT', 'source_id' => $qualityLineId]);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'SUPPLIER_RETURN', 'source_type' => 'supplier_return_line']);
        $this->assertSame('9.000000', $this->decimal(DB::table('receipt_lines')->where('purchase_order_line_id', $poLineId)->sum('accepted_quantity')));
        $this->assertSame('0.000000', $this->decimal(DB::table('stock_positions')->where('quality_status', 'QUALITY_HOLD')
            ->whereIn('lot_id', DB::table('receipt_lines')->where('purchase_order_line_id', $poLineId)->pluck('lot_id'))->sum('quantity_base')));
    }

    public function test_three_way_match_tax_payment_proposal_payment_and_reconciliation_are_governed(): void
    {
        [$poId, $poLineId] = $this->createIssuedOrder('PAYABLE-A');
        [, $taskId] = $this->receive($poId, $poLineId, 'AP', '10', 'LOT-PAYABLE-A');
        $quality = $this->getJson('/api/v1/quality/incoming/'.$taskId)->assertOk();
        $this->withHeaders($this->headers(1))->postJson('/api/v1/quality/incoming/'.$taskId.'/complete', [
            'notes' => 'All material accepted for payable matching.',
            'lines' => [['quality_line_id' => $quality->json('data.lines.0.id'), 'accepted_quantity' => '10', 'rejected_quantity' => '0', 'rejection_reason' => null]],
        ])->assertOk();

        $this->signIn(self::FINANCE_ID);
        $workspace = $this->getJson('/api/v1/finance/payables')->assertOk()
            ->assertJsonPath('lookups.currency', 'INR')->assertJsonPath('lookups.eligible_orders.0.id', $poId)
            ->assertJsonPath('lookups.eligible_orders.0.lines.0.accepted_quantity', '10.000000');
        $invoicePayload = [
            'ap_number' => 'AP-P2P-001', 'supplier_invoice_number' => 'WESTERN-INV-001',
            'purchase_order_id' => $poId, 'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(), 'notes' => 'Recoverable GST invoice.',
            'lines' => [['purchase_order_line_id' => $poLineId, 'invoice_quantity' => '10', 'invoice_unit_price' => '40', 'tax_rate' => '18']],
        ];
        $invoice = $this->command()->postJson('/api/v1/finance/payables/invoices', $invoicePayload)
            ->assertCreated()->assertJsonPath('data.subtotal', '400.000000')
            ->assertJsonPath('data.tax_amount', '72.000000')->assertJsonPath('data.total_amount', '472.000000');
        $invoiceId = (string) $invoice->json('data.id');
        $matched = $this->withHeaders($this->headers(1))->postJson('/api/v1/finance/payables/invoices/'.$invoiceId.'/match')
            ->assertOk()->assertJsonPath('data.status', 'MATCHED')->assertJsonPath('data.match_result', 'PASS');
        $this->withHeaders($this->headers(2))->postJson('/api/v1/finance/payables/invoices/'.$invoiceId.'/approve')
            ->assertUnprocessable()->assertJsonPath('error.fields.actor.0', 'Maker-checker control prevents the invoice creator from approving it.');

        $this->signIn(self::ADMIN_ID);
        $this->withHeaders($this->headers(2))->postJson('/api/v1/finance/payables/invoices/'.$invoiceId.'/approve')
            ->assertOk()->assertJsonPath('data.status', 'APPROVED');

        $this->signIn(self::OPERATIONS_ID);
        [$otherPoId, $otherPoLineId] = $this->createIssuedOrder('PAYABLE-B', self::OTHER_SUPPLIER_ID);
        [, $otherTaskId] = $this->receive($otherPoId, $otherPoLineId, 'AP-B', '10', 'LOT-PAYABLE-B');
        $otherQuality = $this->getJson('/api/v1/quality/incoming/'.$otherTaskId)->assertOk();
        $this->withHeaders($this->headers(1))->postJson('/api/v1/quality/incoming/'.$otherTaskId.'/complete', [
            'notes' => 'Second supplier material accepted for payment-proposal isolation.',
            'lines' => [['quality_line_id' => $otherQuality->json('data.lines.0.id'), 'accepted_quantity' => '10', 'rejected_quantity' => '0', 'rejection_reason' => null]],
        ])->assertOk();
        $this->signIn(self::FINANCE_ID);
        $otherInvoice = $this->command()->postJson('/api/v1/finance/payables/invoices', [
            'ap_number' => 'AP-P2P-002', 'supplier_invoice_number' => 'CENTRAL-INV-001',
            'purchase_order_id' => $otherPoId, 'invoice_date' => now()->toDateString(),
            'due_date' => now()->addDays(30)->toDateString(), 'notes' => 'Second supplier invoice.',
            'lines' => [['purchase_order_line_id' => $otherPoLineId, 'invoice_quantity' => '10', 'invoice_unit_price' => '40', 'tax_rate' => '18']],
        ])->assertCreated();
        $otherInvoiceId = (string) $otherInvoice->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/finance/payables/invoices/'.$otherInvoiceId.'/match')
            ->assertOk()->assertJsonPath('data.status', 'MATCHED');
        $this->signIn(self::ADMIN_ID);
        $this->withHeaders($this->headers(2))->postJson('/api/v1/finance/payables/invoices/'.$otherInvoiceId.'/approve')
            ->assertOk()->assertJsonPath('data.status', 'APPROVED');

        $this->signIn(self::FINANCE_ID);
        $mixedSupplierProposal = $this->command()->postJson('/api/v1/finance/payables/proposals', [
            'proposal_number' => 'PAYPROP-MIXED-001', 'payment_date' => now()->addDay()->toDateString(),
            'notes' => 'Invalid cross-supplier payment batch.',
            'lines' => [
                ['invoice_id' => $invoiceId, 'proposed_amount' => '100'],
                ['invoice_id' => $otherInvoiceId, 'proposed_amount' => '100'],
            ],
        ])->assertUnprocessable();
        $this->assertSame(
            'A payment proposal can contain invoices for only one supplier.',
            $mixedSupplierProposal->json('error.fields')['lines.1.invoice_id'][0] ?? null,
        );

        $proposal = $this->command()->postJson('/api/v1/finance/payables/proposals', [
            'proposal_number' => 'PAYPROP-P2P-001', 'payment_date' => now()->addDay()->toDateString(),
            'notes' => 'Supplier invoice due in the next payment run.',
            'lines' => [['invoice_id' => $invoiceId, 'proposed_amount' => '472']],
        ])->assertCreated()->assertJsonPath('data.total_amount', '472.000000');
        $proposalId = (string) $proposal->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/finance/payables/proposals/'.$proposalId.'/approve')
            ->assertUnprocessable()->assertJsonPath('error.fields.actor.0', 'Maker-checker control prevents the proposal creator from approving it.');
        $this->signIn(self::ADMIN_ID);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/finance/payables/proposals/'.$proposalId.'/approve')
            ->assertOk()->assertJsonPath('data.status', 'APPROVED');

        $this->signIn(self::FINANCE_ID);
        $executed = $this->withHeaders($this->headers(2))->postJson('/api/v1/finance/payables/proposals/'.$proposalId.'/execute', [
            'payment_number' => 'PAY-P2P-001', 'payment_date' => now()->addDay()->toDateString(),
            'method' => 'BANK_TRANSFER', 'bank_reference' => 'UTR-P2P-001',
        ])->assertOk()->assertJsonPath('data.status', 'EXECUTED')->assertJsonPath('data.payment_status', 'POSTED');
        $paymentId = (string) $executed->json('data.payment_id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/finance/payables/payments/'.$paymentId.'/reconcile', [
            'statement_date' => now()->addDays(2)->toDateString(), 'statement_reference' => 'STMT-P2P-001',
            'notes' => 'UTR and value date agree to the bank statement.',
        ])->assertOk()->assertJsonPath('data.status', 'RECONCILED');

        $this->getJson('/api/v1/finance/payables/invoices/'.$invoiceId)->assertOk()
            ->assertJsonPath('data.status', 'PAID')->assertJsonPath('data.paid_amount', '472.000000')
            ->assertJsonPath('data.allocations.0.payment_id', $paymentId);
        $this->getJson('/api/v1/finance/payables/payments/'.$paymentId)->assertOk()
            ->assertJsonPath('data.status', 'RECONCILED')->assertJsonPath('data.reconciliation.statement_reference', 'STMT-P2P-001');
        $this->getJson('/api/v1/finance/payables?q=UTR-P2P-001&payment_status=RECONCILED')->assertOk()
            ->assertJsonCount(1, 'payments')->assertJsonPath('payments.0.id', $paymentId);
        $this->getJson('/api/v1/finance/payables?q=PAYPROP-P2P-001&proposal_status=EXECUTED')->assertOk()
            ->assertJsonCount(1, 'proposals')->assertJsonPath('proposals.0.id', $proposalId);
        $this->assertDatabaseHas('audit_events', ['command' => 'RECONCILE_SUPPLIER_PAYMENT', 'entity_id' => $paymentId]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'finance.supplier-payment.reconciled', 'aggregate_id' => $paymentId]);
    }

    public function test_match_exceptions_scope_permissions_and_over_receipt_are_rejected(): void
    {
        [$poId, $poLineId] = $this->createIssuedOrder('CONTROL-A');
        [, $taskId] = $this->receive($poId, $poLineId, 'CTRL', '4', 'LOT-CONTROL-A');
        $quality = $this->getJson('/api/v1/quality/incoming/'.$taskId)->assertOk();
        $this->withHeaders($this->headers(1))->postJson('/api/v1/quality/incoming/'.$taskId.'/complete', [
            'notes' => null, 'lines' => [['quality_line_id' => $quality->json('data.lines.0.id'), 'accepted_quantity' => '4', 'rejected_quantity' => '0', 'rejection_reason' => null]],
        ])->assertOk();

        $this->signIn(self::FINANCE_ID);
        $invoice = $this->command()->postJson('/api/v1/finance/payables/invoices', [
            'ap_number' => 'AP-EXCEPTION-001', 'supplier_invoice_number' => 'WESTERN-EXCEPTION-001',
            'purchase_order_id' => $poId, 'invoice_date' => now()->toDateString(), 'due_date' => now()->addDays(30)->toDateString(),
            'lines' => [['purchase_order_line_id' => $poLineId, 'invoice_quantity' => '5', 'invoice_unit_price' => '41', 'tax_rate' => '5']],
        ])->assertCreated();
        $invoiceId = (string) $invoice->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/finance/payables/invoices/'.$invoiceId.'/match')
            ->assertOk()->assertJsonPath('data.status', 'MATCH_EXCEPTION')->assertJsonCount(1, 'data.exceptions')
            ->assertJsonCount(2, 'data.exceptions.0.messages');

        $this->signIn(self::SALES_ID);
        $this->getJson('/api/v1/procurement/gate-entries')->assertForbidden();
        $this->getJson('/api/v1/finance/payables')->assertForbidden();
        $this->signIn(self::ADMIN_ID, self::FINANCE_PLANT_ID);
        $this->getJson('/api/v1/procurement/receipts/'.DB::table('receipts')->whereNotNull('receipt_number')->value('id'))->assertNotFound();
    }

    private function createIssuedOrder(string $suffix, string $supplierId = self::SUPPLIER_ID): array
    {
        $requisitionId = (string) $this->command()->postJson('/api/v1/procurement/requisitions', [
            'requisition_number' => 'REQ-'.$suffix, 'department' => 'Manufacturing',
            'purpose' => 'Receive and settle controlled raw material.', 'requested_date' => now()->toDateString(),
            'required_by_date' => now()->addDays(30)->toDateString(), 'currency' => 'INR',
            'lines' => [['item_id' => self::RAW_ITEM_ID, 'quantity' => '10', 'estimated_unit_cost' => '50', 'notes' => null]],
        ])->assertCreated()->json('data.id');
        $approvalId = (string) $this->withHeaders($this->headers(1))->postJson('/api/v1/procurement/requisitions/'.$requisitionId.'/submit')
            ->assertOk()->json('data.approval_request_id');
        $this->signIn(self::FINANCE_ID);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/procurement/requisition-approvals/'.$approvalId.'/approve', [
            'reason' => 'Budget verified for controlled procure-to-pay testing.',
        ])->assertOk();
        $this->signIn(self::OPERATIONS_ID);
        $rfqId = (string) $this->command()->postJson('/api/v1/procurement/rfqs', [
            'rfq_number' => 'RFQ-'.$suffix, 'requisition_id' => $requisitionId,
            'response_due_date' => now()->addDays(3)->toDateString(),
            'commercial_terms' => 'Delivered pricing exclusive of recoverable GST.',
            'supplier_ids' => [self::SUPPLIER_ID, self::OTHER_SUPPLIER_ID],
        ])->assertCreated()->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/procurement/rfqs/'.$rfqId.'/issue')->assertOk();
        $rfqLineId = (string) $this->getJson('/api/v1/procurement/rfqs/'.$rfqId)->assertOk()->json('data.lines.0.id');
        $quote = $this->withHeaders($this->headers(2))->postJson('/api/v1/procurement/rfqs/'.$rfqId.'/quotes', [
            'supplier_party_id' => $supplierId, 'quote_number' => 'QUOTE-'.$suffix,
            'quote_date' => now()->toDateString(), 'valid_until' => now()->addDays(15)->toDateString(),
            'promised_delivery_date' => now()->addDays(20)->toDateString(), 'payment_terms_days' => 30,
            'freight_amount' => '0', 'other_charges' => '0', 'discount_amount' => '0', 'notes' => null,
            'lines' => [['rfq_line_id' => $rfqLineId, 'unit_price' => '40', 'notes' => null]],
        ])->assertOk();
        $quoteId = (string) $quote->json('data.quote_id');
        $this->withHeaders($this->headers(3))->postJson('/api/v1/procurement/rfqs/'.$rfqId.'/award', [
            'supplier_quote_id' => $quoteId, 'award_reason' => null,
        ])->assertOk();
        $po = $this->command()->postJson('/api/v1/procurement/purchase-orders', [
            'po_number' => 'PO-'.$suffix, 'rfq_id' => $rfqId, 'order_date' => now()->toDateString(),
            'incoterm_code' => 'DAP', 'delivery_terms' => 'Delivered to the receiving dock.', 'notes' => null,
        ])->assertCreated();
        $poId = (string) $po->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/procurement/purchase-orders/'.$poId.'/issue')->assertOk();
        $poLineId = (string) $this->getJson('/api/v1/procurement/purchase-orders/'.$poId)->assertOk()->json('data.lines.0.id');
        return [$poId, $poLineId];
    }

    private function receive(string $poId, string $poLineId, string $suffix, string $quantity, string $lotCode): array
    {
        $gate = $this->command()->postJson('/api/v1/procurement/gate-entries', [
            'gate_entry_number' => 'GATE-'.$suffix, 'purchase_order_id' => $poId, 'vehicle_number' => 'MH12QT'.$suffix,
            'transporter_name' => 'Controlled Logistics', 'supplier_document_number' => 'CHALLAN-'.$suffix,
            'arrived_at' => now()->toISOString(), 'notes' => null,
        ])->assertCreated()->assertJsonPath('data.status', 'ARRIVED');
        $gateId = (string) $gate->json('data.id');
        $receipt = $this->command()->postJson('/api/v1/procurement/receipts', [
            'receipt_number' => 'GRN-'.$suffix, 'gate_entry_id' => $gateId, 'receipt_date' => now()->toDateString(),
            'supplier_document_number' => 'CHALLAN-'.$suffix, 'notes' => null,
            'lines' => [[
                'purchase_order_line_id' => $poLineId, 'received_quantity' => $quantity,
                'internal_lot_code' => $lotCode, 'supplier_lot_code' => 'SUP-'.$lotCode,
                'manufacture_date' => now()->subDay()->toDateString(), 'expiry_date' => now()->addMonths(3)->toDateString(),
                'quality_hold_location_id' => self::QUALITY_HOLD_LOCATION, 'released_location_id' => self::RAW_STORE_LOCATION, 'notes' => null,
            ]],
        ])->assertCreated()->assertJsonPath('data.status', 'DRAFT');
        $receiptId = (string) $receipt->json('data.id');
        $posted = $this->withHeaders($this->headers(1))->postJson('/api/v1/procurement/receipts/'.$receiptId.'/post')
            ->assertOk()->assertJsonPath('data.status', 'QC_PENDING');
        return [$receiptId, (string) $posted->json('data.quality_task_id')];
    }

    private function signIn(string $userId, string $plantId = self::PLANT_ID): void
    {
        $this->actingAs(User::query()->findOrFail($userId))->withSession(['erp.company_id' => self::COMPANY_ID, 'erp.plant_id' => $plantId]);
    }

    private function command(): static { return $this->withHeader('Idempotency-Key', (string) Str::uuid()); }
    private function headers(int $version): array { return ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => (string) $version]; }
    private function decimal(mixed $value): string { return bcadd((string) ($value ?? 0), '0', 6); }
}

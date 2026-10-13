<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class TransactionVisibilityEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const OTHER_PLANT_ID = '00000000-0000-4000-8000-000000000102';
    private const SALES_ID = '00000000-0000-4000-8000-000000000201';
    private const OPERATIONS_ID = '00000000-0000-4000-8000-000000000202';
    private const FINANCE_ID = '00000000-0000-4000-8000-000000000203';
    private const ADMIN_ID = '00000000-0000-4000-8000-000000000204';
    private const CUSTOMER_ID = '00000000-0000-4000-8000-000000000501';
    private const ITEM_ID = '00000000-0000-4000-8000-000000000601';
    private const RAW_ITEM_ID = '00000000-0000-4000-8000-000000000603';
    private const CONTRACT_ID = '00000000-0000-4000-8000-000000002501';
    private const EXPENSE_ACCOUNT_ID = '00000000-0000-4000-8000-000000002013';
    private const SUPPLIER_ID = '00000000-0000-4000-8000-000000000503';
    private const OTHER_SUPPLIER_ID = '00000000-0000-4000-8000-000000000504';
    private const QUALITY_HOLD_LOCATION_ID = '00000000-0000-4000-8000-000000000810';
    private const RAW_STORE_LOCATION_ID = '00000000-0000-4000-8000-000000000809';
    private const RESTRICT_PERMISSION = 'DidntShowCheckbox';
    private const SHOW_PERMISSION = 'ShowEntries';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->signIn(self::SALES_ID);
    }

    public function test_sales_permission_matrix_direct_access_search_concurrency_and_immutable_audit(): void
    {
        $normalId = $this->createSalesOrder('SO-VIS-NORMAL');
        $restrictedId = $this->createSalesOrder('SO-VIS-RESTRICTED');
        $this->setSalesOrderValue($normalId, '250000');
        $this->setSalesOrderValue($restrictedId, '50000');

        $this->getJson('/api/v1/sales/orders')
            ->assertOk()
            ->assertJsonPath('summary.sales_total', '300000.000000')
            ->assertJsonPath('operational_view.label', 'Permission-Filtered Operational View');
        $this->withHeader('If-Match', '0')->patchJson(
            '/api/v1/transaction-visibility/SALES_ORDER/'.$restrictedId,
            ['visibility_status' => 'RESTRICTED'],
        )->assertForbidden();

        $this->grant(self::SALES_ID, self::RESTRICT_PERMISSION);
        $approvalCount = DB::table('approval_requests')->count();
        $this->withHeaders(['If-Match' => '0', 'X-Correlation-ID' => (string) Str::uuid()])
            ->patchJson('/api/v1/transaction-visibility/SALES_ORDER/'.$restrictedId, [
                'visibility_status' => 'RESTRICTED',
                'restriction_reason' => 'Confidential commercial negotiation.',
            ])->assertOk()
            ->assertJsonPath('data.status', 'RESTRICTED')
            ->assertJsonPath('data.record_version', 1);
        $this->assertSame($approvalCount, DB::table('approval_requests')->count(), 'Visibility changes must not create approvals.');

        $this->getJson('/api/v1/sales/orders')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.sales_total', '250000.000000')
            ->assertJsonMissing(['id' => $restrictedId]);
        $this->getJson('/api/v1/sales/orders/'.$restrictedId)->assertNotFound();
        $this->getJson('/api/v1/experience/search?q=SO-VIS-RESTRICTED')
            ->assertOk()->assertJsonPath('meta.count', 0)->assertJsonMissing(['id' => $restrictedId]);
        $this->assertSame('300000.000000', $this->decimal(DB::table('sales_orders')
            ->whereIn('id', [$normalId, $restrictedId])->sum('total_amount')));

        $this->signIn(self::ADMIN_ID);
        $this->getJson('/api/v1/partner/workspaces?party_id='.self::CUSTOMER_ID)
            ->assertOk()
            ->assertJsonFragment(['id' => $normalId])
            ->assertJsonMissing(['id' => $restrictedId]);
        $this->getJson('/api/v1/partner/orders/'.$restrictedId)->assertNotFound();
        $filteredRunId = (string) $this->command()->postJson('/api/v1/reports/runs', [
            'run_number' => 'RPT-VIS-FILTERED',
            'report_code' => 'ORDER_FULFILMENT',
            'as_of_date' => now()->toDateString(),
            'parameters' => ['include_closed' => true],
        ])->assertCreated()->assertJsonPath('data.view_classification', 'PERMISSION_FILTERED_OPERATIONAL')
            ->assertJsonPath('data.row_count', 1)->json('data.id');
        $this->getJson('/api/v1/reports/runs/'.$filteredRunId)
            ->assertOk()->assertJsonPath('data.view_label', 'Permission-Filtered Operational View')
            ->assertJsonPath('data.totals.order_value', '250000.000000')
            ->assertJsonMissing(['order_number' => 'SO-VIS-RESTRICTED']);
        $filteredExportId = (string) $this->command()->postJson(
            '/api/v1/reports/runs/'.$filteredRunId.'/exports',
            ['format' => 'CSV'],
        )->assertCreated()->json('data.id');
        $filteredCsv = $this->get('/api/v1/reports/exports/'.$filteredExportId.'/download')->assertOk()->getContent();
        $this->assertStringContainsString('Permission-Filtered Operational View', $filteredCsv);
        $this->assertStringContainsString('SO-VIS-NORMAL', $filteredCsv);
        $this->assertStringNotContainsString('SO-VIS-RESTRICTED', $filteredCsv);

        $this->grant(self::ADMIN_ID, self::SHOW_PERMISSION);
        $this->getJson('/api/v1/partner/workspaces?party_id='.self::CUSTOMER_ID)
            ->assertOk()->assertJsonFragment(['id' => $restrictedId]);
        $this->getJson('/api/v1/partner/orders/'.$restrictedId)
            ->assertOk()->assertJsonPath('data.id', $restrictedId);
        $fullRunId = (string) $this->command()->postJson('/api/v1/reports/runs', [
            'run_number' => 'RPT-VIS-FULL',
            'report_code' => 'ORDER_FULFILMENT',
            'as_of_date' => now()->toDateString(),
            'parameters' => ['include_closed' => true],
        ])->assertCreated()->assertJsonPath('data.view_classification', 'FULL_OPERATIONAL')
            ->assertJsonPath('data.row_count', 2)->json('data.id');
        $this->getJson('/api/v1/reports/runs/'.$fullRunId)
            ->assertOk()->assertJsonPath('data.view_label', 'Full Operational View')
            ->assertJsonPath('data.totals.order_value', '300000.000000')
            ->assertJsonFragment(['order_number' => 'SO-VIS-RESTRICTED']);
        $fullExportId = (string) $this->command()->postJson(
            '/api/v1/reports/runs/'.$fullRunId.'/exports',
            ['format' => 'CSV'],
        )->assertCreated()->json('data.id');
        $fullCsv = $this->get('/api/v1/reports/exports/'.$fullExportId.'/download')->assertOk()->getContent();
        $this->assertStringContainsString('Full Operational View', $fullCsv);
        $this->assertStringContainsString('SO-VIS-RESTRICTED', $fullCsv);
        $this->revoke(self::ADMIN_ID, self::SHOW_PERMISSION);
        $this->getJson('/api/v1/reports/runs/'.$fullRunId)->assertNotFound();
        $this->get('/api/v1/reports/exports/'.$fullExportId.'/download')->assertNotFound();
        $this->get('/api/v1/reports/exports/'.$filteredExportId.'/download')->assertOk();

        $this->signIn(self::SALES_ID);

        $this->grant(self::SALES_ID, self::SHOW_PERMISSION);
        $this->getJson('/api/v1/sales/orders')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.sales_total', '300000.000000')
            ->assertJsonPath('operational_view.label', 'Full Operational View')
            ->assertJsonFragment(['id' => $restrictedId]);
        $this->getJson('/api/v1/sales/orders?visibility=RESTRICTED')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('summary.sales_total', '50000.000000')
            ->assertJsonPath('data.0.visibility.status', 'RESTRICTED');
        $this->getJson('/api/v1/sales/orders/'.$restrictedId)
            ->assertOk()->assertJsonPath('data.visibility.status', 'RESTRICTED');
        $this->getJson('/api/v1/experience/search?q=SO-VIS-RESTRICTED')
            ->assertOk()->assertJsonFragment(['id' => $restrictedId]);
        $this->getJson('/api/v1/transaction-visibility/SALES_ORDER/'.$restrictedId.'/history')
            ->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.previous_visibility', 'NORMAL')
            ->assertJsonPath('data.0.new_visibility', 'RESTRICTED');

        $this->withHeader('If-Match', '0')->patchJson(
            '/api/v1/transaction-visibility/SALES_ORDER/'.$restrictedId,
            ['visibility_status' => 'NORMAL'],
        )->assertConflict()->assertJsonPath('error.code', 'CONFLICT');

        $this->revoke(self::SALES_ID, self::RESTRICT_PERMISSION);
        $this->withHeader('If-Match', '1')->patchJson(
            '/api/v1/transaction-visibility/SALES_ORDER/'.$restrictedId,
            ['visibility_status' => 'NORMAL'],
        )->assertForbidden();

        $this->grant(self::SALES_ID, self::RESTRICT_PERMISSION);
        $this->withHeader('If-Match', '1')->patchJson(
            '/api/v1/transaction-visibility/SALES_ORDER/'.$restrictedId,
            ['visibility_status' => 'NORMAL'],
        )->assertOk()->assertJsonPath('data.record_version', 2)
            ->assertJsonPath('data.status', 'NORMAL');
        $this->assertDatabaseCount('transaction_visibility_events', 2);
        $this->assertDatabaseHas('audit_events', [
            'command' => 'CHANGE_TRANSACTION_VISIBILITY',
            'entity_id' => $restrictedId,
            'entity_version' => 2,
        ]);

        $eventId = (string) DB::table('transaction_visibility_events')->value('id');
        try {
            DB::table('transaction_visibility_events')->where('id', $eventId)->update(['reason' => 'tampered']);
            $this->fail('Visibility audit events must be immutable.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable', strtolower($exception->getMessage()));
        }

        $this->grant(self::ADMIN_ID, self::SHOW_PERMISSION);
        $this->signIn(self::ADMIN_ID, self::OTHER_PLANT_ID);
        $this->getJson('/api/v1/sales/orders/'.$restrictedId)->assertNotFound();
    }

    public function test_expense_totals_are_permission_filtered_without_changing_gl_or_authoritative_amounts(): void
    {
        $this->signIn(self::FINANCE_ID);
        $normalId = $this->createExpense('EXP-VIS-NORMAL', '70000');
        $restrictedId = $this->createExpense('EXP-VIS-RESTRICTED', '10000');
        $journalCount = DB::table('journals')->count();
        $journalDebits = $this->decimal(DB::table('journals')->sum('total_debit'));

        $this->grant(self::FINANCE_ID, self::RESTRICT_PERMISSION);
        $this->withHeader('If-Match', '0')->patchJson(
            '/api/v1/transaction-visibility/EXPENSE/'.$restrictedId,
            ['visibility_status' => 'RESTRICTED'],
        )->assertOk()->assertJsonPath('data.status', 'RESTRICTED');

        $this->getJson('/api/v1/finance/expenses?q=EXP-VIS')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('summary.operational_total', '70000.000000')
            ->assertJsonPath('operational_view.label', 'Permission-Filtered Operational View')
            ->assertJsonMissing(['id' => $restrictedId]);
        $this->getJson('/api/v1/finance/expenses/'.$restrictedId)->assertNotFound();
        $this->assertSame('80000.000000', $this->decimal(DB::table('expense_claims')
            ->whereIn('id', [$normalId, $restrictedId])->sum('total_amount')));
        $this->assertSame($journalCount, DB::table('journals')->count());
        $this->assertSame($journalDebits, $this->decimal(DB::table('journals')->sum('total_debit')));

        $this->grant(self::FINANCE_ID, self::SHOW_PERMISSION);
        $this->getJson('/api/v1/finance/expenses?q=EXP-VIS')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('summary.operational_total', '80000.000000')
            ->assertJsonPath('operational_view.label', 'Full Operational View')
            ->assertJsonFragment(['id' => $restrictedId]);
        $this->getJson('/api/v1/finance/expenses?q=EXP-VIS&visibility=RESTRICTED')
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('summary.operational_total', '10000.000000');
    }

    public function test_purchase_and_traceable_inventory_totals_filter_to_185_without_changing_the_200_authority(): void
    {
        $this->signIn(self::OPERATIONS_ID);
        [$normalPoId, $normalPoLineId] = $this->createIssuedPurchaseOrder('VIS');
        DB::table('purchase_orders')->where('id', $normalPoId)->update([
            'subtotal' => 185000,
            'freight_amount' => 0,
            'other_charges' => 0,
            'discount_amount' => 0,
            'total_amount' => 185000,
        ]);
        DB::table('purchase_order_lines')->where('id', $normalPoLineId)->update([
            'ordered_quantity' => 185,
            'unit_price' => 1000,
            'line_total' => 185000,
        ]);
        [$restrictedPoId, $restrictedPoLineId] = $this->clonePurchaseOrder(
            $normalPoId,
            $normalPoLineId,
            'PO-VIS-RESTRICTED',
            '15',
            '1000',
        );

        // The seeded KG authority is 160. Add 25 to the ordinary traceable lot,
        // then receive 15 on the restricted PO for an exact 185/200 example.
        DB::table('stock_positions')->where('id', '00000000-0000-4000-8000-000000001213')
            ->update(['quantity_base' => 150, 'updated_at' => now()]);
        $this->assertSame('185.000000', $this->authoritativeKgStock());
        [$receiptId, $movementId] = $this->receivePurchase($restrictedPoId, $restrictedPoLineId);
        $this->assertSame('200.000000', $this->authoritativeKgStock());
        $movementCount = DB::table('stock_movements')->count();
        $movementQuantity = $this->decimal(DB::table('stock_movements')->sum('quantity_base'));
        $authoritativeAvailable = $this->authoritativeKgAvailability();

        $this->grant(self::OPERATIONS_ID, self::RESTRICT_PERMISSION);
        $this->withHeader('If-Match', '0')->patchJson(
            '/api/v1/transaction-visibility/PURCHASE_ORDER/'.$restrictedPoId,
            ['visibility_status' => 'RESTRICTED'],
        )->assertOk()->assertJsonPath('data.status', 'RESTRICTED');

        $this->getJson('/api/v1/procurement/purchase-orders')
            ->assertOk()->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.committed_total', '185000.000000')
            ->assertJsonPath('operational_view.label', 'Permission-Filtered Operational View')
            ->assertJsonMissing(['id' => $restrictedPoId]);
        $this->getJson('/api/v1/procurement/receipts/'.$receiptId)->assertNotFound();
        $this->getJson('/api/v1/inventory/movements/'.$movementId)->assertNotFound();
        $this->getJson('/api/v1/inventory/movements?movement_type=PURCHASE_RECEIPT')
            ->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/inventory/stock')
            ->assertOk()
            ->assertJsonPath('summary.quantities_by_uom.0.uom_code', 'KG')
            ->assertJsonPath('summary.quantities_by_uom.0.total', '185.000000')
            ->assertJsonPath('summary.quantity_basis', 'TRACEABLE_PERMISSION_FILTERED_ON_HAND')
            ->assertJsonPath('summary.authoritative_availability_unchanged', true)
            ->assertJsonPath('operational_view.label', 'Permission-Filtered Operational View');

        // A hidden purchase-order ID cannot be smuggled into a create command.
        $this->command()->postJson('/api/v1/procurement/gate-entries', [
            'gate_entry_number' => 'GATE-VIS-BYPASS',
            'purchase_order_id' => $restrictedPoId,
            'vehicle_number' => 'MH12VIS02',
            'transporter_name' => 'Visibility Logistics',
            'supplier_document_number' => 'CHALLAN-VIS-BYPASS',
            'arrived_at' => now()->toISOString(),
            'notes' => null,
        ])->assertNotFound();

        $this->assertSame('200000.000000', $this->decimal(DB::table('purchase_orders')
            ->whereIn('id', [$normalPoId, $restrictedPoId])->sum('total_amount')));
        $this->assertSame('200.000000', $this->authoritativeKgStock());
        $this->assertSame($authoritativeAvailable, $this->authoritativeKgAvailability());
        $this->assertSame($movementCount, DB::table('stock_movements')->count());
        $this->assertSame($movementQuantity, $this->decimal(DB::table('stock_movements')->sum('quantity_base')));

        $this->grant(self::OPERATIONS_ID, self::SHOW_PERMISSION);
        $this->getJson('/api/v1/procurement/purchase-orders')
            ->assertOk()->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.committed_total', '200000.000000')
            ->assertJsonPath('operational_view.label', 'Full Operational View')
            ->assertJsonFragment(['id' => $restrictedPoId]);
        $this->getJson('/api/v1/procurement/purchase-orders?visibility=RESTRICTED')
            ->assertOk()->assertJsonPath('summary.total', 1)
            ->assertJsonPath('summary.committed_total', '15000.000000');
        $this->getJson('/api/v1/procurement/receipts/'.$receiptId)->assertOk()
            ->assertJsonPath('data.purchase_order.id', $restrictedPoId);
        $this->getJson('/api/v1/inventory/movements/'.$movementId)->assertOk()
            ->assertJsonPath('data.quantity_base', '15.000000');
        $this->getJson('/api/v1/inventory/movements?movement_type=PURCHASE_RECEIPT')
            ->assertOk()->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/inventory/stock')
            ->assertOk()
            ->assertJsonPath('summary.quantities_by_uom.0.uom_code', 'KG')
            ->assertJsonPath('summary.quantities_by_uom.0.total', '200.000000')
            ->assertJsonPath('operational_view.label', 'Full Operational View');
    }

    private function createSalesOrder(string $number): string
    {
        return (string) $this->command()->postJson('/api/v1/sales/orders', [
            'order_number' => $number,
            'customer_party_id' => self::CUSTOMER_ID,
            'sales_lead_id' => null,
            'sales_contract_id' => self::CONTRACT_ID,
            'sales_price_list_id' => null,
            'order_date' => now()->toDateString(),
            'requested_delivery_date' => now()->addDays(3)->toDateString(),
            'notes' => 'Visibility acceptance fixture.',
            'lines' => [[
                'item_id' => self::ITEM_ID,
                'uom_code' => 'PACK',
                'quantity' => '1',
                'discount_percent' => '0',
            ]],
        ])->assertCreated()->json('data.id');
    }

    private function setSalesOrderValue(string $id, string $amount): void
    {
        DB::table('sales_orders')->where('id', $id)->update([
            'subtotal' => $amount,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'total_amount' => $amount,
        ]);
    }

    private function createExpense(string $number, string $amount): string
    {
        return (string) $this->command()->postJson('/api/v1/finance/expenses', [
            'expense_number' => $number,
            'claimant_user_id' => self::FINANCE_ID,
            'expense_date' => now()->toDateString(),
            'category' => 'SUPPLIES',
            'description' => 'Visibility acceptance fixture '.$number,
            'lines' => [[
                'expense_account_id' => self::EXPENSE_ACCOUNT_ID,
                'description' => $number,
                'net_amount' => $amount,
                'tax_rate' => '0',
            ]],
        ])->assertCreated()->json('data.id');
    }

    /** @return array{string,string} */
    private function createIssuedPurchaseOrder(string $suffix): array
    {
        $requisitionId = (string) $this->command()->postJson('/api/v1/procurement/requisitions', [
            'requisition_number' => 'REQ-'.$suffix,
            'department' => 'Manufacturing',
            'purpose' => 'Visibility-controlled raw material purchase.',
            'requested_date' => now()->toDateString(),
            'required_by_date' => now()->addDays(30)->toDateString(),
            'currency' => 'INR',
            'lines' => [[
                'item_id' => self::RAW_ITEM_ID,
                'quantity' => '10',
                'estimated_unit_cost' => '50',
                'notes' => null,
            ]],
        ])->assertCreated()->json('data.id');
        $approvalId = (string) $this->withHeaders($this->headers(1))
            ->postJson('/api/v1/procurement/requisitions/'.$requisitionId.'/submit')
            ->assertOk()->json('data.approval_request_id');
        $this->signIn(self::FINANCE_ID);
        $this->withHeaders($this->headers(1))
            ->postJson('/api/v1/procurement/requisition-approvals/'.$approvalId.'/approve', [
                'reason' => 'Budget verified for the visibility acceptance fixture.',
            ])->assertOk();
        $this->signIn(self::OPERATIONS_ID);

        $rfqId = (string) $this->command()->postJson('/api/v1/procurement/rfqs', [
            'rfq_number' => 'RFQ-'.$suffix,
            'requisition_id' => $requisitionId,
            'response_due_date' => now()->addDays(3)->toDateString(),
            'commercial_terms' => 'Delivered pricing exclusive of recoverable GST.',
            'supplier_ids' => [self::SUPPLIER_ID, self::OTHER_SUPPLIER_ID],
        ])->assertCreated()->json('data.id');
        $this->withHeaders($this->headers(1))
            ->postJson('/api/v1/procurement/rfqs/'.$rfqId.'/issue')->assertOk();
        $rfqLineId = (string) $this->getJson('/api/v1/procurement/rfqs/'.$rfqId)
            ->assertOk()->json('data.lines.0.id');
        $quoteId = (string) $this->withHeaders($this->headers(2))
            ->postJson('/api/v1/procurement/rfqs/'.$rfqId.'/quotes', [
                'supplier_party_id' => self::SUPPLIER_ID,
                'quote_number' => 'QUOTE-'.$suffix,
                'quote_date' => now()->toDateString(),
                'valid_until' => now()->addDays(15)->toDateString(),
                'promised_delivery_date' => now()->addDays(20)->toDateString(),
                'payment_terms_days' => 30,
                'freight_amount' => '0',
                'other_charges' => '0',
                'discount_amount' => '0',
                'notes' => null,
                'lines' => [['rfq_line_id' => $rfqLineId, 'unit_price' => '40', 'notes' => null]],
            ])->assertOk()->json('data.quote_id');
        $this->withHeaders($this->headers(3))
            ->postJson('/api/v1/procurement/rfqs/'.$rfqId.'/award', [
                'supplier_quote_id' => $quoteId,
                'award_reason' => null,
            ])->assertOk();
        $poId = (string) $this->command()->postJson('/api/v1/procurement/purchase-orders', [
            'po_number' => 'PO-'.$suffix,
            'rfq_id' => $rfqId,
            'order_date' => now()->toDateString(),
            'incoterm_code' => 'DAP',
            'delivery_terms' => 'Delivered to the receiving dock.',
            'notes' => null,
        ])->assertCreated()->json('data.id');
        $this->withHeaders($this->headers(1))
            ->postJson('/api/v1/procurement/purchase-orders/'.$poId.'/issue')->assertOk();
        $lineId = (string) $this->getJson('/api/v1/procurement/purchase-orders/'.$poId)
            ->assertOk()->json('data.lines.0.id');

        return [$poId, $lineId];
    }

    /** @return array{string,string} */
    private function clonePurchaseOrder(
        string $sourceOrderId,
        string $sourceLineId,
        string $number,
        string $quantity,
        string $unitPrice,
    ): array {
        $orderId = (string) Str::uuid();
        $order = (array) DB::table('purchase_orders')->where('id', $sourceOrderId)->firstOrFail();
        $order['id'] = $orderId;
        $order['po_number'] = $number;
        $order['subtotal'] = bcmul($quantity, $unitPrice, 6);
        $order['freight_amount'] = 0;
        $order['other_charges'] = 0;
        $order['discount_amount'] = 0;
        $order['total_amount'] = $order['subtotal'];
        $order['created_at'] = now();
        $order['updated_at'] = now();
        DB::table('purchase_orders')->insert($order);

        $lineId = (string) Str::uuid();
        $line = (array) DB::table('purchase_order_lines')->where('id', $sourceLineId)->firstOrFail();
        $line['id'] = $lineId;
        $line['purchase_order_id'] = $orderId;
        $line['ordered_quantity'] = $quantity;
        $line['unit_price'] = $unitPrice;
        $line['line_total'] = bcmul($quantity, $unitPrice, 6);
        $line['created_at'] = now();
        $line['updated_at'] = now();
        DB::table('purchase_order_lines')->insert($line);

        return [$orderId, $lineId];
    }

    /** @return array{string,string} */
    private function receivePurchase(string $purchaseOrderId, string $purchaseOrderLineId): array
    {
        $gateId = (string) $this->command()->postJson('/api/v1/procurement/gate-entries', [
            'gate_entry_number' => 'GATE-VIS-01',
            'purchase_order_id' => $purchaseOrderId,
            'vehicle_number' => 'MH12VIS01',
            'transporter_name' => 'Visibility Logistics',
            'supplier_document_number' => 'CHALLAN-VIS-01',
            'arrived_at' => now()->toISOString(),
            'notes' => null,
        ])->assertCreated()->json('data.id');
        $receiptId = (string) $this->command()->postJson('/api/v1/procurement/receipts', [
            'receipt_number' => 'GRN-VIS-01',
            'gate_entry_id' => $gateId,
            'receipt_date' => now()->toDateString(),
            'supplier_document_number' => 'CHALLAN-VIS-01',
            'notes' => null,
            'lines' => [[
                'purchase_order_line_id' => $purchaseOrderLineId,
                'received_quantity' => '15',
                'internal_lot_code' => 'LOT-VIS-RESTRICTED',
                'supplier_lot_code' => 'SUP-LOT-VIS-RESTRICTED',
                'manufacture_date' => now()->subDay()->toDateString(),
                'expiry_date' => now()->addMonths(3)->toDateString(),
                'quality_hold_location_id' => self::QUALITY_HOLD_LOCATION_ID,
                'released_location_id' => self::RAW_STORE_LOCATION_ID,
                'notes' => null,
            ]],
        ])->assertCreated()->json('data.id');
        $this->withHeaders($this->headers(1))
            ->postJson('/api/v1/procurement/receipts/'.$receiptId.'/post')
            ->assertOk()->assertJsonPath('data.status', 'QC_PENDING');
        $movementId = (string) DB::table('receipt_lines')->where('receipt_id', $receiptId)
            ->value('stock_receipt_movement_id');

        return [$receiptId, $movementId];
    }

    private function authoritativeKgStock(): string
    {
        return $this->decimal(DB::table('stock_positions')
            ->where('company_id', self::COMPANY_ID)
            ->where('plant_id', self::PLANT_ID)
            ->where('uom_code', 'KG')
            ->sum('quantity_base'));
    }

    private function authoritativeKgAvailability(): string
    {
        return $this->decimal(DB::table('stock_positions')
            ->where('company_id', self::COMPANY_ID)
            ->where('plant_id', self::PLANT_ID)
            ->where('uom_code', 'KG')
            ->selectRaw('COALESCE(SUM(quantity_base - reserved_quantity_base), 0) AS available')
            ->value('available'));
    }

    private function grant(string $userId, string ...$permissionCodes): void
    {
        $roleId = $this->roleId($userId);
        foreach ($permissionCodes as $code) {
            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => DB::table('permissions')->where('code', $code)->value('id'),
            ]);
        }
    }

    private function revoke(string $userId, string ...$permissionCodes): void
    {
        DB::table('role_permissions')->where('role_id', $this->roleId($userId))
            ->whereIn('permission_id', DB::table('permissions')->whereIn('code', $permissionCodes)->pluck('id'))
            ->delete();
    }

    private function roleId(string $userId): string
    {
        return (string) DB::table('role_assignments')->where('user_id', $userId)
            ->where('is_active', true)->value('role_id');
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

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 6);
    }
}

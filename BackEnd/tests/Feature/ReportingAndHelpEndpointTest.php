<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReportingAndHelpEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = '00000000-0000-4000-8000-000000000001';
    private const PLANT = '00000000-0000-4000-8000-000000000101';
    private const OTHER_PLANT = '00000000-0000-4000-8000-000000000102';
    private const SALES = '00000000-0000-4000-8000-000000000201';
    private const OPERATIONS = '00000000-0000-4000-8000-000000000202';
    private const FINANCE = '00000000-0000-4000-8000-000000000203';
    private const ADMIN = '00000000-0000-4000-8000-000000000204';
    private const BI = '00000000-0000-4000-8000-000000000206';
    private const PERIOD = '00000000-0000-4000-8000-000000002101';
    private const EXPENSE = '00000000-0000-4000-8000-000000002013';
    private const OVERHEAD = '00000000-0000-4000-8000-000000002014';
    private const CUSTOMER = '00000000-0000-4000-8000-000000000501';
    private const PACK_ITEM = '00000000-0000-4000-8000-000000000601';
    private const KG_ITEM = '00000000-0000-4000-8000-000000000603';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_report_run_is_cutoff_bound_immutable_idempotent_and_exportable(): void
    {
        $this->signIn(self::FINANCE);
        $journal = $this->command()->postJson('/api/v1/finance/journals', [
            'journal_number' => 'JV-REPORT-001',
            'fiscal_period_id' => self::PERIOD,
            'posting_date' => '2026-09-13',
            'description' => 'Reporting control journal.',
            'source_reference' => 'REPORT-TEST',
            'lines' => [
                ['account_id' => self::EXPENSE, 'description' => 'Report debit', 'debit_amount' => '1250', 'credit_amount' => '0'],
                ['account_id' => self::OVERHEAD, 'description' => 'Report credit', 'debit_amount' => '0', 'credit_amount' => '1250'],
            ],
        ])->assertCreated();
        $this->withHeaders($this->headers(1))->postJson('/api/v1/finance/journals/'.$journal->json('data.id').'/post')
            ->assertOk()->assertJsonPath('data.status', 'POSTED');

        $workspace = $this->getJson('/api/v1/reports')->assertOk()->assertJsonCount(4, 'definitions');
        $this->assertContains('RUN', $workspace->json('allowed_actions'));
        $this->assertContains('EXPORT', $workspace->json('allowed_actions'));
        $payload = [
            'run_number' => 'rep-trial-001',
            'report_code' => 'trial_balance',
            'as_of_date' => '2026-09-13',
            'parameters' => ['include_zero' => false],
        ];
        $key = (string) Str::uuid();
        $created = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/reports/runs', $payload)
            ->assertCreated()->assertJsonPath('data.report_code', 'TRIAL_BALANCE')
            ->assertJsonPath('data.run_number', 'REP-TRIAL-001')
            ->assertJsonPath('data.row_count', 2);
        $runId = (string) $created->json('data.id');
        $checksum = (string) $created->json('data.sha256');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $checksum);
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/reports/runs', $payload)
            ->assertCreated()->assertExactJson($created->json());

        $detail = $this->getJson('/api/v1/reports/runs/'.$runId)->assertOk()
            ->assertJsonPath('data.sha256', $checksum)->assertJsonPath('data.totals.debit', '1250.000000')
            ->assertJsonPath('data.totals.credit', '1250.000000')->assertJsonCount(2, 'data.rows');
        $this->assertSame(['510000', '520000'], collect($detail->json('data.rows'))->pluck('data.account_code')->all());

        $export = $this->command()->postJson('/api/v1/reports/runs/'.$runId.'/exports', ['format' => 'csv'])
            ->assertCreated()->assertJsonPath('data.format', 'CSV')->assertJsonPath('data.row_count', 2);
        $exportId = (string) $export->json('data.id');
        $this->get('/api/v1/reports/exports/'.$exportId.'/download')->assertOk()
            ->assertHeader('X-Content-SHA256', $export->json('data.sha256'))
            ->assertHeader('Content-Disposition', 'attachment; filename="rep-trial-001.csv"')
            ->assertSee('General operating expense')->assertSee('1250.000000');
        $this->assertDatabaseHas('audit_events', ['command' => 'GENERATE_REPORT', 'entity_id' => $runId]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'reporting.export.created', 'aggregate_id' => $exportId]);
    }

    public function test_report_permissions_scope_filters_and_current_inventory_cutoff_are_enforced(): void
    {
        $this->signIn(self::OPERATIONS);
        $this->getJson('/api/v1/reports')->assertForbidden();
        $this->command()->postJson('/api/v1/reports/runs', [])->assertForbidden();

        $this->signIn(self::FINANCE);
        $this->command()->postJson('/api/v1/reports/runs', [
            'run_number' => 'REP-ORDER-BAD-PARAM',
            'report_code' => 'ORDER_FULFILMENT',
            'as_of_date' => '2026-09-14',
            'parameters' => ['include_zero' => true],
        ])->assertUnprocessable()->assertJsonPath(
            'error.fields.parameters.0',
            'Unsupported parameter(s) for this report: include_zero.',
        );
        $run = $this->command()->postJson('/api/v1/reports/runs', [
            'run_number' => 'REP-ORDER-001',
            'report_code' => 'ORDER_FULFILMENT',
            'as_of_date' => '2026-09-14',
            'parameters' => ['include_closed' => true],
        ])->assertCreated();
        $runId = (string) $run->json('data.id');
        $this->getJson('/api/v1/reports?report_code=ORDER_FULFILMENT&q=ORDER')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $runId);

        $pastDate = now('Asia/Kolkata')->subDay()->toDateString();
        $this->command()->postJson('/api/v1/reports/runs', [
            'run_number' => 'REP-STOCK-PAST',
            'report_code' => 'INVENTORY_AVAILABILITY',
            'as_of_date' => $pastDate,
        ])->assertUnprocessable()->assertJsonPath(
            'error.fields.as_of_date.0',
            'Inventory availability is a current ledger projection and must use today as its cutoff.',
        );

        $this->signIn(self::ADMIN, self::OTHER_PLANT);
        $this->getJson('/api/v1/reports/runs/'.$runId)->assertNotFound();
    }

    public function test_inventory_report_blocks_expired_stock_and_keeps_totals_separate_by_uom(): void
    {
        $this->signIn(self::FINANCE);
        DB::table('stock_positions')->where('company_id', self::COMPANY)->where('plant_id', self::PLANT)
            ->update(['quantity_base' => 0, 'reserved_quantity_base' => 0, 'updated_at' => now()]);
        $ownerId = (string) DB::table('inventory_owners')->where('company_id', self::COMPANY)
            ->where('owner_type', 'COMPANY')->where('status', 'ACTIVE')->value('id');
        $locationId = (string) DB::table('locations')->where('company_id', self::COMPANY)
            ->where('plant_id', self::PLANT)->where('status', 'ACTIVE')->value('id');
        $catalogItemId = (string) Str::uuid();
        $pieceItemId = (string) Str::uuid();
        $now = now();
        DB::table('catalog_items')->insert([
            'id' => $catalogItemId, 'company_id' => self::COMPANY, 'brand_id' => null,
            'code' => 'REPORT-BREAD-PCS', 'name' => 'Report bread pieces', 'item_type' => 'FINISHED_GOOD',
            'base_uom' => 'PCS', 'description' => 'Mixed-UOM reporting regression fixture.', 'shelf_life_days' => 3,
            'lot_controlled' => true, 'status' => 'ACTIVE', 'record_version' => 1,
            'status_reason' => 'Regression fixture.', 'status_changed_at' => $now,
            'status_changed_by' => self::ADMIN, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('items')->insert([
            'id' => $pieceItemId, 'company_id' => self::COMPANY, 'catalog_item_id' => $catalogItemId,
            'code' => 'REPORT-BREAD-PCS', 'name' => 'Report bread pieces', 'barcode' => null,
            'description' => 'Mixed-UOM reporting regression fixture.', 'item_type' => 'FINISHED_GOOD',
            'base_uom' => 'PCS', 'pack_quantity' => 1, 'pack_uom_code' => 'PCS', 'status' => 'ACTIVE',
            'record_version' => 1, 'status_reason' => 'Regression fixture.', 'status_changed_at' => $now,
            'status_changed_by' => self::ADMIN, 'created_at' => $now, 'updated_at' => $now,
        ]);
        $kgLotId = (string) Str::uuid();
        $expiredLotId = (string) Str::uuid();
        DB::table('lots')->insert([
            [
                'id' => $kgLotId, 'company_id' => self::COMPANY, 'item_id' => self::KG_ITEM,
                'supplier_party_id' => null, 'internal_lot_code' => 'REPORT-FLOUR-KG', 'supplier_lot_code' => null,
                'origin_type' => 'OPENING', 'manufacture_date' => now()->subDays(2)->toDateString(),
                'expiry_date' => now()->addMonth()->toDateString(), 'status' => 'ACTIVE', 'notes' => null,
                'record_version' => 1, 'status_reason' => null, 'status_changed_at' => null,
                'status_changed_by' => null, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'id' => $expiredLotId, 'company_id' => self::COMPANY, 'item_id' => $pieceItemId,
                'supplier_party_id' => null, 'internal_lot_code' => 'REPORT-BREAD-EXPIRED', 'supplier_lot_code' => null,
                'origin_type' => 'PRODUCTION', 'manufacture_date' => now()->subDays(4)->toDateString(),
                'expiry_date' => now()->subDay()->toDateString(), 'status' => 'ACTIVE', 'notes' => null,
                'record_version' => 1, 'status_reason' => null, 'status_changed_at' => null,
                'status_changed_by' => null, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);
        DB::table('stock_positions')->insert([
            [
                'id' => (string) Str::uuid(), 'company_id' => self::COMPANY, 'plant_id' => self::PLANT,
                'item_id' => self::KG_ITEM, 'lot_id' => $kgLotId, 'owner_party_id' => null,
                'inventory_owner_id' => $ownerId, 'location_id' => $locationId, 'quality_status' => 'RELEASED',
                'quantity_base' => 1, 'reserved_quantity_base' => 0, 'uom_code' => 'KG', 'record_version' => 1,
                'status_reason' => null, 'status_changed_at' => null, 'status_changed_by' => null,
                'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'id' => (string) Str::uuid(), 'company_id' => self::COMPANY, 'plant_id' => self::PLANT,
                'item_id' => $pieceItemId, 'lot_id' => $expiredLotId, 'owner_party_id' => null,
                'inventory_owner_id' => $ownerId, 'location_id' => $locationId, 'quality_status' => 'RELEASED',
                'quantity_base' => 1846, 'reserved_quantity_base' => 0, 'uom_code' => 'PCS', 'record_version' => 1,
                'status_reason' => null, 'status_changed_at' => null, 'status_changed_by' => null,
                'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        $run = $this->command()->postJson('/api/v1/reports/runs', [
            'run_number' => 'REP-STOCK-UOM-001', 'report_code' => 'INVENTORY_AVAILABILITY',
            'as_of_date' => now('Asia/Kolkata')->toDateString(), 'parameters' => ['include_zero' => false],
        ])->assertCreated();
        $detail = $this->getJson('/api/v1/reports/runs/'.$run->json('data.id'))->assertOk()
            ->assertJsonPath('data.totals.quantity_summary_status', 'MIXED_UOM')
            ->assertJsonPath('data.totals.on_hand', null)
            ->assertJsonPath('data.totals.quantities_by_uom.0.uom_code', 'KG')
            ->assertJsonPath('data.totals.quantities_by_uom.0.on_hand', '1.000000')
            ->assertJsonPath('data.totals.quantities_by_uom.0.available', '1.000000')
            ->assertJsonPath('data.totals.quantities_by_uom.1.uom_code', 'PCS')
            ->assertJsonPath('data.totals.quantities_by_uom.1.on_hand', '1846.000000')
            ->assertJsonPath('data.totals.quantities_by_uom.1.available', '0.000000')
            ->assertJsonPath('data.totals.quantities_by_uom.1.blocked', '1846.000000');
        $expired = collect($detail->json('data.rows'))->firstWhere('data.lot_code', 'REPORT-BREAD-EXPIRED');
        $this->assertSame('EXPIRED', $expired['data']['expiry_risk']);
        $this->assertSame('0.000000', $expired['data']['available']);
        $this->assertSame('1846.000000', $expired['data']['blocked']);
    }

    public function test_order_fulfilment_uses_dispatch_and_invoice_events_at_the_cutoff(): void
    {
        $this->signIn(self::FINANCE);
        $cutoff = now('Asia/Kolkata')->startOfDay()->subDays(2);
        $createdAt = $cutoff->copy()->subDay()->utc();
        $loadedAt = $cutoff->copy()->setTime(12, 0)->utc();
        $dispatchedAt = $cutoff->copy()->addDay()->setTime(9, 0)->utc();
        $orderId = (string) Str::uuid();
        $lineId = (string) Str::uuid();
        $allocationId = (string) Str::uuid();
        $shipmentId = (string) Str::uuid();
        DB::table('sales_orders')->insert([
            'id' => $orderId, 'company_id' => self::COMPANY, 'plant_id' => self::PLANT,
            'status' => 'DISPATCHED', 'record_version' => 5, 'created_by' => self::FINANCE,
            'order_type' => 'SALES', 'order_number' => 'SO-CUTOFF-DISPATCH', 'customer_party_id' => self::CUSTOMER,
            'sales_lead_id' => null, 'sales_contract_id' => null, 'sales_price_list_id' => null,
            'order_date' => $cutoff->copy()->subDay()->toDateString(), 'requested_delivery_date' => $cutoff->copy()->addDays(2)->toDateString(),
            'currency' => 'INR', 'subtotal' => 100, 'discount_amount' => 0, 'tax_amount' => 0, 'total_amount' => 100,
            'credit_limit_snapshot' => 1000, 'credit_exposure_snapshot' => 0, 'notes' => null,
            'confirmed_at' => $createdAt, 'confirmed_by' => self::FINANCE, 'cancelled_at' => null,
            'cancelled_by' => null, 'cancellation_reason' => null, 'created_at' => $createdAt, 'updated_at' => $dispatchedAt,
        ]);
        DB::table('sales_order_lines')->insert([
            'id' => $lineId, 'sales_order_id' => $orderId, 'company_id' => self::COMPANY, 'plant_id' => self::PLANT,
            'line_number' => 1, 'item_id' => self::PACK_ITEM, 'description' => 'Cutoff test item', 'uom_code' => 'PACK',
            'ordered_quantity' => 10, 'allocated_quantity' => 10, 'dispatched_quantity' => 10, 'invoiced_quantity' => 10,
            'unit_price' => 10, 'discount_percent' => 0, 'tax_rate' => 0, 'net_amount' => 100,
            'tax_amount' => 0, 'gross_amount' => 100, 'unit_cost_snapshot' => 0,
            'sales_contract_id' => null, 'sales_contract_line_id' => null, 'created_at' => $createdAt, 'updated_at' => $dispatchedAt,
        ]);
        DB::table('sales_allocations')->insert([
            'id' => $allocationId, 'company_id' => self::COMPANY, 'plant_id' => self::PLANT,
            'allocation_number' => 'ALLOC-CUTOFF-DISPATCH', 'sales_order_id' => $orderId, 'status' => 'DISPATCHED',
            'record_version' => 3, 'created_by' => self::FINANCE, 'picked_at' => $createdAt,
            'picked_by' => self::FINANCE, 'cancelled_at' => null, 'cancelled_by' => null,
            'cancellation_reason' => null, 'created_at' => $createdAt, 'updated_at' => $dispatchedAt,
        ]);
        DB::table('shipments')->insert([
            'id' => $shipmentId, 'company_id' => self::COMPANY, 'plant_id' => self::PLANT,
            'status' => 'DISPATCHED', 'record_version' => 3, 'created_by' => self::FINANCE,
            'shipment_number' => 'SHP-CUTOFF-DISPATCH', 'party_id' => self::CUSTOMER,
            'shipment_type' => 'SALES', 'sales_order_id' => $orderId, 'sales_allocation_id' => $allocationId,
            'carrier_name' => null, 'vehicle_number' => null, 'driver_name' => null, 'notes' => null,
            'loaded_at' => $loadedAt, 'loaded_by' => self::FINANCE, 'dispatched_at' => $dispatchedAt,
            'dispatched_by' => self::FINANCE, 'delivered_at' => null, 'delivered_by' => null,
            'cancelled_at' => null, 'cancelled_by' => null, 'cancellation_reason' => null,
            'created_at' => $createdAt, 'updated_at' => $dispatchedAt,
        ]);
        DB::table('shipment_lines')->insert([
            'id' => (string) Str::uuid(), 'shipment_id' => $shipmentId, 'sales_order_id' => $orderId,
            'sales_order_line_id' => $lineId, 'sales_allocation_line_id' => null,
            'company_id' => self::COMPANY, 'plant_id' => self::PLANT, 'item_id' => self::PACK_ITEM,
            'fg_lot_id' => null, 'stock_position_id' => null, 'stock_movement_id' => null,
            'shipped_quantity' => 10, 'returned_quantity' => 0, 'uom_code' => 'PACK',
            'unit_price' => 10, 'tax_rate' => 0, 'record_version' => 1,
            'created_at' => $createdAt, 'updated_at' => $dispatchedAt,
        ]);

        $run = $this->command()->postJson('/api/v1/reports/runs', [
            'run_number' => 'REP-ORDER-CUTOFF-001', 'report_code' => 'ORDER_FULFILMENT',
            'as_of_date' => $cutoff->toDateString(), 'parameters' => ['include_closed' => true],
        ])->assertCreated();
        $detail = $this->getJson('/api/v1/reports/runs/'.$run->json('data.id'))->assertOk();
        $row = collect($detail->json('data.rows'))->firstWhere('data.order_number', 'SO-CUTOFF-DISPATCH');
        $this->assertSame('LOADED', $row['data']['status']);
        $this->assertSame('0.000000', $row['data']['dispatched_quantity']);
        $this->assertSame('0.000000', $row['data']['invoiced_quantity']);
        $this->assertSame('0.00', $row['data']['fulfilment_percent']);
    }

    public function test_bi_analyst_role_is_read_only_and_can_run_export_and_profitability_reports(): void
    {
        $this->signIn(self::BI);

        $session = $this->getJson('/api/v1/me')->assertOk();
        $this->assertSame(['BI_ANALYST'], $session->json('data.roles'));
        $this->assertSame(['BI-PROFIT', 'BI-REP'], $session->json('data.allowed_screens'));
        $this->assertSame(
            ['ACTION:BI-REP:EXPORT', 'ACTION:BI-REP:RUN'],
            $session->json('data.allowed_actions'),
        );

        $workspace = $this->getJson('/api/v1/reports')->assertOk();
        $this->assertContains('RUN', $workspace->json('allowed_actions'));
        $this->assertContains('EXPORT', $workspace->json('allowed_actions'));

        $run = $this->command()->postJson('/api/v1/reports/runs', [
            'run_number' => 'REP-BI-ANALYST-001',
            'report_code' => 'INVENTORY_AVAILABILITY',
            'as_of_date' => now('Asia/Kolkata')->toDateString(),
            'parameters' => ['include_zero' => false],
        ])->assertCreated()->assertJsonPath('data.report_code', 'INVENTORY_AVAILABILITY');

        $runId = (string) $run->json('data.id');
        $this->command()->postJson('/api/v1/reports/runs/'.$runId.'/exports', ['format' => 'CSV'])
            ->assertCreated()->assertJsonPath('data.format', 'CSV');

        $this->getJson('/api/v1/reports/profitability')->assertOk();

        $this->getJson('/api/v1/finance/ledger')->assertForbidden();
        $this->command()->postJson('/api/v1/sales/leads', [])->assertForbidden();
        $this->command()->postJson('/api/v1/inventory/issues', [])->assertForbidden();
    }

    public function test_help_articles_are_role_filtered_and_support_cases_complete_the_handoff(): void
    {
        $this->signIn(self::SALES);
        $workspace = $this->getJson('/api/v1/admin/help')->assertOk()
            ->assertJsonPath('is_support_manager', false)
            ->assertJsonFragment(['slug' => 'getting-started-with-your-context'])
            ->assertJsonFragment(['slug' => 'work-queue-ownership-and-escalation']);
        $this->assertNotContains('controlled-report-snapshots-and-exports', collect($workspace->json('articles'))->pluck('slug')->all());
        $this->getJson('/api/v1/admin/help/articles/controlled-report-snapshots-and-exports')->assertNotFound();
        $this->getJson('/api/v1/admin/help/articles/getting-started-with-your-context')
            ->assertOk()->assertJsonCount(3, 'data.sections');

        $created = $this->command()->postJson('/api/v1/admin/help/cases', [
            'category' => 'workflow',
            'priority' => 'high',
            'affected_screen_code' => 'CRM-ORDER',
            'subject' => 'Confirmed order does not appear in picking',
            'description' => 'The confirmed sales order is visible, but no allocation task is available for picking.',
        ])->assertCreated()->assertJsonPath('data.status', 'OPEN');
        $caseId = (string) $created->json('data.id');
        $caseNumber = (string) $created->json('data.case_number');
        $this->assertMatchesRegularExpression('/^HLP-\d{8}-[A-F0-9]{8}$/', $caseNumber);
        $this->getJson('/api/v1/admin/help')->assertOk()->assertJsonCount(1, 'cases');

        $this->signIn(self::OPERATIONS);
        $this->getJson('/api/v1/admin/help/cases/'.$caseId)->assertNotFound();
        $this->signIn(self::ADMIN);
        $this->getJson('/api/v1/admin/help')->assertOk()->assertJsonPath('is_support_manager', true)
            ->assertJsonFragment(['case_number' => $caseNumber]);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/admin/help/cases/'.$caseId.'/start')
            ->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS')->assertJsonPath('data.record_version', 2);
        $this->withHeaders($this->headers(2))->postJson('/api/v1/admin/help/cases/'.$caseId.'/resolve', [
            'resolution_summary' => 'The order was credit-held. Finance released the hold and allocation is now available.',
        ])->assertOk()->assertJsonPath('data.status', 'RESOLVED')->assertJsonPath('data.record_version', 3);

        $this->signIn(self::SALES);
        $this->withHeaders($this->headers(2))->postJson('/api/v1/admin/help/cases/'.$caseId.'/close', [
            'confirmation' => 'Allocation is now visible and the issue is resolved.',
        ])->assertConflict();
        $this->withHeaders($this->headers(3))->postJson('/api/v1/admin/help/cases/'.$caseId.'/close', [
            'confirmation' => 'Allocation is now visible and the issue is resolved.',
        ])->assertOk()->assertJsonPath('data.status', 'CLOSED')->assertJsonPath('data.record_version', 4);
        $this->getJson('/api/v1/admin/help/cases/'.$caseId)->assertOk()->assertJsonCount(4, 'data.events')
            ->assertJsonPath('data.events.3.event_type', 'CLOSED');
        $this->assertDatabaseHas('audit_events', ['command' => 'CLOSED_HELP_SUPPORT_CASE', 'entity_id' => $caseId]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'help.support.resolved', 'aggregate_id' => $caseId]);

        $this->signIn(self::ADMIN, self::OTHER_PLANT);
        $this->getJson('/api/v1/admin/help/cases/'.$caseId)->assertNotFound();
    }

    public function test_help_case_screen_scope_and_state_permissions_are_enforced(): void
    {
        $this->signIn(self::SALES);
        $this->command()->postJson('/api/v1/admin/help/cases', [
            'category' => 'REPORTING',
            'priority' => 'NORMAL',
            'affected_screen_code' => 'BI-REP',
            'subject' => 'Report help request',
            'description' => 'This role must not open support cases for a screen it cannot access.',
        ])->assertUnprocessable()->assertJsonPath(
            'error.fields.affected_screen_code.0',
            'Choose a screen available in your current role and context.',
        );
        $case = $this->command()->postJson('/api/v1/admin/help/cases', [
            'category' => 'ACCESS',
            'priority' => 'NORMAL',
            'affected_screen_code' => 'CRM-ORDER',
            'subject' => 'Order access question',
            'description' => 'Please confirm the correct action authority for sales-order amendments.',
        ])->assertCreated();
        $caseId = (string) $case->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/admin/help/cases/'.$caseId.'/start')->assertForbidden();
        $this->withHeaders($this->headers(1))->postJson('/api/v1/admin/help/cases/'.$caseId.'/comments', [
            'message' => 'The order is still in draft state.',
        ])->assertOk()->assertJsonPath('data.record_version', 2);
        $this->withHeaders($this->headers(2))->postJson('/api/v1/admin/help/cases/'.$caseId.'/close', [
            'confirmation' => 'Trying to close an unresolved request.',
        ])->assertUnprocessable()->assertJsonPath(
            'error.fields.status.0', 'Only a resolved support case can be closed.',
        );
    }

    private function signIn(string $id, string $plant = self::PLANT): void
    {
        $this->actingAs(User::query()->findOrFail($id))->withSession([
            'erp.company_id' => self::COMPANY,
            'erp.plant_id' => $plant,
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

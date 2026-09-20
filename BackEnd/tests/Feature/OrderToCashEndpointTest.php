<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class OrderToCashEndpointTest extends TestCase
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
    private const CONTRACT_ID = '00000000-0000-4000-8000-000000002501';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->signIn(self::SALES_ID);
    }

    public function test_qualified_lead_can_create_and_link_a_new_customer_during_conversion(): void
    {
        $lead = $this->command()->postJson('/api/v1/sales/leads', [
            'lead_number' => 'LEAD-NEW-CUSTOMER', 'customer_party_id' => null,
            'company_name' => 'Fresh Retail Prospect', 'contact_name' => 'Buyer Desk',
            'contact_email' => 'buyer@fresh-retail.example', 'contact_phone' => null,
            'source' => 'DIRECT', 'enquiry_date' => now()->toDateString(),
            'expected_close_date' => now()->addDays(7)->toDateString(), 'estimated_value' => '5000', 'notes' => null,
        ])->assertCreated();
        $leadId = (string) $lead->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/sales/leads/'.$leadId.'/qualify')->assertOk();

        $this->withHeaders($this->headers(2))->postJson('/api/v1/sales/leads/'.$leadId.'/convert', [
            'conversion_path' => 'CREATE_CUSTOMER', 'customer_code' => 'CUST-FRESH-RETAIL',
            'customer_name' => 'Fresh Retail Prospect', 'contact_name' => 'Buyer Desk',
            'contact_email' => 'buyer@fresh-retail.example', 'contact_phone' => null,
        ])->assertOk()->assertJsonPath('data.status', 'CONVERTED');

        $customerId = DB::table('sales_leads')->where('id', $leadId)->value('customer_party_id');
        $this->assertNotNull($customerId);
        $this->assertDatabaseHas('parties', ['id' => $customerId, 'code' => 'CUST-FRESH-RETAIL', 'status' => 'ACTIVE']);
        $this->assertDatabaseHas('party_roles', ['party_id' => $customerId, 'role_code' => 'CUSTOMER']);
        $this->assertDatabaseHas('party_contacts', ['party_id' => $customerId, 'email' => 'buyer@fresh-retail.example', 'is_primary' => true]);
    }

    public function test_lead_to_cash_claim_and_profitability_chain_is_governed(): void
    {
        $lead = $this->command()->postJson('/api/v1/sales/leads', [
            'lead_number' => 'LEAD-P2-001', 'customer_party_id' => self::CUSTOMER_ID,
            'company_name' => 'North Market Distributor', 'contact_name' => 'Commercial Desk',
            'contact_email' => 'commercial@north-market.example', 'contact_phone' => null,
            'source' => 'DIRECT', 'enquiry_date' => now()->toDateString(),
            'expected_close_date' => now()->addDays(10)->toDateString(), 'estimated_value' => '25000',
            'notes' => 'New distributor enquiry for the P2 flow.',
        ])->assertCreated()->assertJsonPath('data.status', 'NEW');
        $leadId = (string) $lead->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/sales/leads/'.$leadId.'/qualify')
            ->assertOk()->assertJsonPath('data.status', 'QUALIFIED')->assertJsonPath('data.record_version', 2);
        $this->withHeaders($this->headers(2))->postJson('/api/v1/sales/leads/'.$leadId.'/convert', ['customer_party_id' => self::CUSTOMER_ID])
            ->assertOk()->assertJsonPath('data.status', 'CONVERTED')->assertJsonPath('data.record_version', 3);

        $order = $this->command()->postJson('/api/v1/sales/orders', [
            'order_number' => 'SO-P2-001', 'customer_party_id' => self::CUSTOMER_ID, 'sales_lead_id' => $leadId,
            'sales_contract_id' => self::CONTRACT_ID, 'sales_price_list_id' => null,
            'order_date' => now()->toDateString(), 'requested_delivery_date' => now()->addDays(3)->toDateString(),
            'notes' => 'Contract-backed customer order.',
            'lines' => [['item_id' => self::ITEM_ID, 'uom_code' => 'PACK', 'quantity' => '10', 'discount_percent' => '0']],
        ])->assertCreated()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.total_amount', '1121.000000');
        $orderId = (string) $order->json('data.id');
        $confirmKey = (string) Str::uuid();
        $confirmed = $this->withHeaders(['Idempotency-Key' => $confirmKey, 'If-Match' => '1'])
            ->postJson('/api/v1/sales/orders/'.$orderId.'/confirm')->assertOk()
            ->assertJsonPath('data.status', 'CONFIRMED')->assertJsonPath('data.record_version', 2);
        $this->withHeaders(['Idempotency-Key' => $confirmKey, 'If-Match' => '1'])
            ->postJson('/api/v1/sales/orders/'.$orderId.'/confirm')->assertOk()->assertExactJson($confirmed->json());
        $this->assertDatabaseHas('sales_leads', ['id' => $leadId, 'status' => 'WON']);

        $allocation = $this->withHeaders($this->headers(2))->postJson('/api/v1/dispatch/orders/'.$orderId.'/allocations', [
            'allocation_number' => 'ALLOC-P2-001',
        ])->assertCreated()->assertJsonPath('data.status', 'RESERVED')
            ->assertJsonPath('data.allocated_quantity', '10.000000')->assertJsonPath('data.fefo_break_count', 1);
        $allocationId = (string) $allocation->json('data.id');
        $this->assertDatabaseHas('stock_reservations', ['status' => 'ACTIVE', 'quantity_base' => 10]);
        $this->signIn(self::OPERATIONS_ID);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/dispatch/allocations/'.$allocationId.'/pick')
            ->assertOk()->assertJsonPath('data.status', 'PICKED')->assertJsonPath('data.record_version', 2);

        $shipment = $this->command()->postJson('/api/v1/dispatch/shipments', [
            'shipment_number' => 'SHP-P2-001', 'sales_allocation_id' => $allocationId,
            'carrier_name' => 'Q&T Contract Logistics', 'vehicle_number' => 'MH01QT2609',
            'driver_name' => 'Demo Driver', 'notes' => 'Sealed load.',
        ])->assertCreated()->assertJsonPath('data.status', 'DRAFT');
        $shipmentId = (string) $shipment->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/dispatch/shipments/'.$shipmentId.'/load')
            ->assertOk()->assertJsonPath('data.status', 'LOADED')->assertJsonPath('data.record_version', 2);
        $dispatched = $this->withHeaders($this->headers(2))->postJson('/api/v1/dispatch/shipments/'.$shipmentId.'/dispatch', [
            'invoice_number' => 'INV-P2-001',
        ])->assertOk()->assertJsonPath('data.status', 'DISPATCHED')->assertJsonPath('data.invoice_total', '1121.0000');
        $invoiceId = (string) $dispatched->json('data.invoice_id');
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'SALES_DISPATCH', 'source_id' => $shipmentId]);
        $this->assertSame('490.000000', $this->decimal(DB::table('stock_positions')->where('id', '00000000-0000-4000-8000-000000002602')->value('quantity_base')));
        $this->assertSame('0.000000', $this->decimal(DB::table('stock_positions')->where('id', '00000000-0000-4000-8000-000000002602')->value('reserved_quantity_base')));

        $this->withHeaders($this->headers(3))->postJson('/api/v1/dispatch/shipments/'.$shipmentId.'/pod', [
            'proof_number' => 'POD-P2-001', 'outcome' => 'DELIVERED', 'receiver_name' => 'North Market Receiving',
            'event_at' => now()->toISOString(), 'failure_reason' => null, 'notes' => 'Delivery accepted without shortage.',
        ])->assertCreated()->assertJsonPath('data.status', 'DELIVERED')->assertJsonPath('data.sales_order_status', 'COMPLETED');
        $shipmentLineId = (string) $this->getJson('/api/v1/dispatch/shipments/'.$shipmentId)->assertOk()->json('data.lines.0.id');

        $this->signIn(self::SALES_ID);
        $claim = $this->command()->postJson('/api/v1/sales/customer-claims', [
            'claim_number' => 'CLM-P2-001', 'shipment_id' => $shipmentId, 'claim_type' => 'DAMAGE',
            'requested_resolution' => 'CREDIT', 'reason' => 'One outer case was damaged in transit.',
            'lines' => [['shipment_line_id' => $shipmentLineId, 'quantity' => '1']],
        ])->assertCreated()->assertJsonPath('data.status', 'OPEN');
        $claimId = (string) $claim->json('data.id');
        $this->getJson('/api/v1/sales/customer-claims')->assertOk()
            ->assertJsonPath('data.0.id', $claimId)->assertJsonPath('data.0.status', 'OPEN');
        $this->getJson('/api/v1/sales/customer-claims/'.$claimId)->assertOk()
            ->assertJsonPath('data.id', $claimId)->assertJsonPath('data.lines.0.shipment_line_id', $shipmentLineId);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/sales/customer-claims/'.$claimId.'/resolve', [
            'resolution_type' => 'CREDIT', 'credit_amount' => '118', 'notes' => 'Commercial credit approved for damaged pack.',
        ])->assertOk()->assertJsonPath('data.status', 'RESOLVED')->assertJsonPath('data.credit_amount', '118.0000');

        $this->command()->postJson('/api/v1/finance/receivables/collections', [
            'receipt_number' => 'RCPT-P2-001', 'customer_party_id' => self::CUSTOMER_ID,
            'receipt_date' => now()->toDateString(), 'payment_method' => 'BANK', 'bank_reference' => 'BANK-P2-001',
            'total_amount' => '1003', 'allocations' => [['invoice_id' => $invoiceId, 'amount' => '1003']],
        ])->assertCreated()->assertJsonPath('data.status', 'POSTED')->assertJsonPath('data.allocated_amount', '1003.0000');
        $this->getJson('/api/v1/finance/receivables/'.$invoiceId)->assertOk()
            ->assertJsonPath('data.outstanding_amount', '0.0000')->assertJsonCount(3, 'data.transactions');
        $this->getJson('/api/v1/reports/profitability')->assertOk()->assertJsonPath('data.0.order_number', 'SO-P2-001');
        $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'invoice_type' => 'RECEIVABLE', 'status' => 'PAID']);
        $this->assertDatabaseHas('audit_events', ['command' => 'DISPATCH_SALES_SHIPMENT', 'entity_id' => $shipmentId]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'finance.customer-receipt.posted']);
    }

    public function test_pricing_contract_work_credit_scope_and_permissions_are_enforced(): void
    {
        $price = $this->command()->postJson('/api/v1/sales/price-lists', [
            'list_number' => 'PL-P2-SPECIAL', 'name' => 'Special distributor price',
            'effective_from' => now()->toDateString(), 'effective_to' => now()->addMonth()->toDateString(), 'notes' => null,
            'lines' => [['item_id' => self::ITEM_ID, 'uom_code' => 'PACK', 'minimum_quantity' => '1',
                'unit_price' => '90', 'maximum_discount_percent' => '5', 'tax_rate' => '18']],
        ])->assertCreated();
        $priceId = (string) $price->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/sales/price-lists/'.$priceId.'/activate')
            ->assertOk()->assertJsonPath('data.status', 'ACTIVE');

        $work = $this->command()->postJson('/api/v1/sales/third-party-work', [
            'work_number' => 'TPW-P2-001', 'sales_order_id' => null, 'provider_party_id' => '00000000-0000-4000-8000-000000000502',
            'work_type' => 'CO_PACKING', 'description' => 'Contract packing validation lot.',
            'expected_start_date' => now()->toDateString(), 'expected_end_date' => now()->addDays(2)->toDateString(), 'agreed_cost' => '5000',
        ])->assertCreated();
        $workId = (string) $work->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/sales/third-party-work/'.$workId.'/release')->assertOk();
        $this->withHeaders($this->headers(2))->postJson('/api/v1/sales/third-party-work/'.$workId.'/complete', ['actual_cost' => '4900'])
            ->assertOk()->assertJsonPath('data.status', 'COMPLETED');

        $this->postJson('/api/v1/sales/credit-profiles', [
            'customer_party_id' => self::CUSTOMER_ID, 'credit_limit' => 1, 'payment_terms_days' => 30,
            'is_on_hold' => true, 'hold_reason' => 'Unauthorised sales hold.', 'record_version' => 1,
        ], ['Idempotency-Key' => (string) Str::uuid()])->assertForbidden();

        $this->signIn(self::FINANCE_ID);
        $this->command()->postJson('/api/v1/sales/credit-profiles', [
            'customer_party_id' => self::CUSTOMER_ID, 'credit_limit' => 500000, 'payment_terms_days' => 30,
            'is_on_hold' => true, 'hold_reason' => 'Temporary finance review.', 'record_version' => 1,
        ])->assertOk()->assertJsonPath('data.status', 'ON_HOLD');

        $this->signIn(self::ADMIN_ID, self::OTHER_PLANT_ID);
        $this->getJson('/api/v1/sales/price-lists/'.$priceId)->assertNotFound();
        $this->getJson('/api/v1/sales/pricing')->assertOk()->assertJsonMissing(['list_number' => 'PL-P2-SPECIAL']);
    }

    private function signIn(string $userId, string $plantId = self::PLANT_ID): void
    {
        $this->actingAs(User::query()->findOrFail($userId))->withSession(['erp.company_id' => self::COMPANY_ID, 'erp.plant_id' => $plantId]);
    }

    private function command(): static { return $this->withHeader('Idempotency-Key', (string) Str::uuid()); }
    private function headers(int $version): array { return ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => (string) $version]; }
    private function decimal(mixed $value): string { return bcadd((string) ($value ?? 0), '0', 6); }
}

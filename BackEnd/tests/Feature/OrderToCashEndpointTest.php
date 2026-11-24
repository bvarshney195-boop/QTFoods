<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Carbon\CarbonImmutable;
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

    public function test_sales_orders_reject_fractional_quantities_for_whole_unit_uoms_at_capture_and_confirmation(): void
    {
        $payload = [
            'order_number' => 'SO-WHOLE-UNIT-001', 'customer_party_id' => self::CUSTOMER_ID,
            'sales_lead_id' => null, 'sales_contract_id' => self::CONTRACT_ID, 'sales_price_list_id' => null,
            'order_date' => now()->toDateString(), 'requested_delivery_date' => now()->addDays(3)->toDateString(),
            'notes' => 'Whole-unit UOM acceptance case.',
            'lines' => [['item_id' => self::ITEM_ID, 'uom_code' => 'PACK', 'quantity' => '1.5', 'discount_percent' => '0']],
        ];

        $capture = $this->command()->postJson('/api/v1/sales/orders', $payload)
            ->assertUnprocessable();
        $this->assertSame(
            'Order quantity for PACK must use whole units.',
            $capture->json()['error']['fields']['lines.0.quantity'][0] ?? null,
        );

        $payload['order_number'] = 'SO-WHOLE-UNIT-LEGACY';
        $payload['lines'][0]['quantity'] = '2';
        $draft = $this->command()->postJson('/api/v1/sales/orders', $payload)
            ->assertCreated()->assertJsonPath('data.status', 'DRAFT');
        $orderId = (string) $draft->json('data.id');
        DB::table('sales_order_lines')->where('sales_order_id', $orderId)->update(['ordered_quantity' => '1.5']);

        $confirmation = $this->withHeaders($this->headers(1))->postJson('/api/v1/sales/orders/'.$orderId.'/confirm')
            ->assertUnprocessable();
        $this->assertSame(
            'Order quantity for PACK must use whole units.',
            $confirmation->json()['error']['fields']['lines.0.quantity'][0] ?? null,
        );
        $this->assertDatabaseHas('sales_orders', ['id' => $orderId, 'status' => 'DRAFT', 'record_version' => 1]);
    }

    public function test_sales_allocation_uses_active_company_owner_and_rolls_back_partial_fefo_reservations(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-23 09:00:00', 'Asia/Kolkata'));
        $fixture = $this->createManualBreadAllocationFixture();
        $this->assertNotSame(self::COMPANY_ID, $fixture['company_owner_id']);

        $order = $this->command()->postJson('/api/v1/sales/orders', [
            'order_number' => 'MANUAL-SO-BREAD-150G-221126', 'customer_party_id' => self::CUSTOMER_ID,
            'sales_lead_id' => null, 'sales_contract_id' => self::CONTRACT_ID, 'sales_price_list_id' => null,
            'order_date' => now()->toDateString(), 'requested_delivery_date' => now()->addDays(3)->toDateString(),
            'notes' => 'Independent company inventory-owner allocation regression.',
            'lines' => [[
                'item_id' => $fixture['item_id'], 'uom_code' => 'PCS', 'quantity' => '10', 'discount_percent' => '0',
            ]],
        ])->assertCreated()->assertJsonPath('data.status', 'DRAFT');
        $orderId = (string) $order->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/sales/orders/'.$orderId.'/confirm')
            ->assertOk()->assertJsonPath('data.status', 'CONFIRMED');

        $allocation = $this->withHeaders($this->headers(2))
            ->postJson('/api/v1/dispatch/orders/'.$orderId.'/allocations', [
                'allocation_number' => 'MANUAL-ALLOC-BREAD-150G-221126',
            ])->assertCreated()->assertJsonPath('data.status', 'RESERVED')
            ->assertJsonPath('data.allocated_quantity', '10.000000')
            ->assertJsonPath('data.fefo_lot_count', 1)
            ->assertJsonPath('data.fefo_break_count', 0);

        $allocationId = (string) $allocation->json('data.id');
        $this->assertSame(
            $fixture['saleable_lot_id'],
            (string) DB::table('sales_allocation_lines')->where('sales_allocation_id', $allocationId)->value('lot_id'),
        );
        $this->assertDatabaseHas('stock_reservations', [
            'stock_position_id' => $fixture['saleable_position_id'],
            'quantity_base' => 10,
            'status' => 'ACTIVE',
        ]);
        $reserved = $this->decimal(DB::table('stock_positions')->where('id', $fixture['saleable_position_id'])
            ->value('reserved_quantity_base'));
        $this->assertSame('10.000000', $reserved);
        $this->assertSame('997.000000', bcsub('1007.000000', $reserved, 6));
        foreach ($fixture['excluded_position_ids'] as $positionId) {
            $this->assertSame(
                '0.000000',
                $this->decimal(DB::table('stock_positions')->where('id', $positionId)->value('reserved_quantity_base')),
            );
        }

        $reservationCount = DB::table('stock_reservations')->count();
        $failingOrder = $this->command()->postJson('/api/v1/sales/orders', [
            'order_number' => 'MANUAL-SO-BREAD-150G-ROLLBACK', 'customer_party_id' => self::CUSTOMER_ID,
            'sales_lead_id' => null, 'sales_contract_id' => self::CONTRACT_ID, 'sales_price_list_id' => null,
            'order_date' => now()->toDateString(), 'requested_delivery_date' => now()->addDays(3)->toDateString(),
            'notes' => 'Atomic insufficient-stock allocation regression.',
            'lines' => [[
                'item_id' => $fixture['item_id'], 'uom_code' => 'PCS', 'quantity' => '1000', 'discount_percent' => '0',
            ]],
        ])->assertCreated();
        $failingOrderId = (string) $failingOrder->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/sales/orders/'.$failingOrderId.'/confirm')
            ->assertOk()->assertJsonPath('data.status', 'CONFIRMED');

        $this->withHeaders($this->headers(2))
            ->postJson('/api/v1/dispatch/orders/'.$failingOrderId.'/allocations', [
                'allocation_number' => 'MANUAL-ALLOC-BREAD-150G-ROLLBACK',
            ])->assertUnprocessable()->assertJsonPath(
                'error.fields.stock.0',
                'Insufficient released FEFO stock for order line 1.',
            );

        $this->assertDatabaseMissing('sales_allocations', ['allocation_number' => 'MANUAL-ALLOC-BREAD-150G-ROLLBACK']);
        $this->assertSame($reservationCount, DB::table('stock_reservations')->count());
        $this->assertSame(0, DB::table('sales_allocation_lines')->where('sales_order_id', $failingOrderId)->count());
        $this->assertDatabaseHas('sales_orders', [
            'id' => $failingOrderId, 'status' => 'CONFIRMED', 'record_version' => 2,
        ]);
        $this->assertSame(
            '0.000000',
            $this->decimal(DB::table('sales_order_lines')->where('sales_order_id', $failingOrderId)
                ->value('allocated_quantity')),
        );
        $this->assertSame(
            '10.000000',
            $this->decimal(DB::table('stock_positions')->where('id', $fixture['saleable_position_id'])
                ->value('reserved_quantity_base')),
        );
        foreach ($fixture['excluded_position_ids'] as $positionId) {
            $this->assertSame(
                '0.000000',
                $this->decimal(DB::table('stock_positions')->where('id', $positionId)->value('reserved_quantity_base')),
            );
        }
    }

    public function test_sales_dispatch_uses_active_company_owner_and_is_atomic_and_idempotent(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-24 10:30:00', 'Asia/Kolkata'));
        $fixture = $this->createManualBreadAllocationFixture();
        $this->assertNotSame(self::COMPANY_ID, $fixture['company_owner_id']);

        $order = $this->command()->postJson('/api/v1/sales/orders', [
            'order_number' => 'AI-UAT-SO-150G-221126-01', 'customer_party_id' => self::CUSTOMER_ID,
            'sales_lead_id' => null, 'sales_contract_id' => self::CONTRACT_ID, 'sales_price_list_id' => null,
            'order_date' => now()->toDateString(), 'requested_delivery_date' => now()->addDays(3)->toDateString(),
            'notes' => 'Independent company inventory-owner dispatch regression.',
            'lines' => [[
                'item_id' => $fixture['item_id'], 'uom_code' => 'PCS', 'quantity' => '1', 'discount_percent' => '0',
            ]],
        ])->assertCreated()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.total_amount', '20.000000');
        $orderId = (string) $order->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/sales/orders/'.$orderId.'/confirm')
            ->assertOk()->assertJsonPath('data.status', 'CONFIRMED');

        $allocation = $this->withHeaders($this->headers(2))
            ->postJson('/api/v1/dispatch/orders/'.$orderId.'/allocations', [
                'allocation_number' => 'AI-UAT-ALLOC-150G-241126-01',
            ])->assertCreated()->assertJsonPath('data.status', 'RESERVED')
            ->assertJsonPath('data.allocated_quantity', '1.000000');
        $allocationId = (string) $allocation->json('data.id');

        $this->signIn(self::OPERATIONS_ID);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/dispatch/allocations/'.$allocationId.'/pick')
            ->assertOk()->assertJsonPath('data.status', 'PICKED');
        $shipment = $this->command()->postJson('/api/v1/dispatch/shipments', [
            'shipment_number' => 'AI-UAT-SHP-150G-241126-01', 'sales_allocation_id' => $allocationId,
            'carrier_name' => 'Q&T UAT Logistics', 'vehicle_number' => 'UAT241126',
            'driver_name' => 'UAT Driver', 'notes' => 'Dispatch owner regression.',
        ])->assertCreated()->assertJsonPath('data.status', 'DRAFT');
        $shipmentId = (string) $shipment->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/dispatch/shipments/'.$shipmentId.'/load')
            ->assertOk()->assertJsonPath('data.status', 'LOADED')->assertJsonPath('data.record_version', 2);

        $otherOwnerId = (string) DB::table('inventory_owners')->where('company_id', self::COMPANY_ID)
            ->where('owner_type', 'PARTY')->where('status', 'ACTIVE')->value('id');
        $this->assertNotSame('', $otherOwnerId);
        DB::table('stock_positions')->where('inventory_owner_id', $otherOwnerId)->delete();
        DB::table('stock_positions')->where('id', $fixture['saleable_position_id'])
            ->update(['inventory_owner_id' => $otherOwnerId]);

        $this->withHeaders($this->headers(2))
            ->postJson('/api/v1/dispatch/shipments/'.$shipmentId.'/dispatch', [
                'invoice_number' => 'AI-UAT-INV-150G-241126-01',
            ])->assertUnprocessable()->assertJsonPath(
                'error.fields.source_position_id.0',
                'The source position must match the command company, plant, item, lot, inventory owner, quality status, and UOM.',
            );

        $this->assertDatabaseHas('shipments', ['id' => $shipmentId, 'status' => 'LOADED', 'record_version' => 2]);
        $this->assertDatabaseHas('sales_allocations', ['id' => $allocationId, 'status' => 'PICKED', 'record_version' => 2]);
        $this->assertDatabaseHas('sales_orders', ['id' => $orderId, 'status' => 'LOADED']);
        $this->assertDatabaseHas('stock_reservations', [
            'stock_position_id' => $fixture['saleable_position_id'], 'quantity_base' => 1, 'status' => 'ACTIVE',
        ]);
        $this->assertSame('1007.000000', $this->decimal(DB::table('stock_positions')
            ->where('id', $fixture['saleable_position_id'])->value('quantity_base')));
        $this->assertSame('1.000000', $this->decimal(DB::table('stock_positions')
            ->where('id', $fixture['saleable_position_id'])->value('reserved_quantity_base')));
        $this->assertSame(0, DB::table('stock_movements')->where('source_id', $shipmentId)->count());
        $this->assertSame(0, DB::table('shipment_lines')->where('shipment_id', $shipmentId)->count());
        $this->assertDatabaseMissing('sales_invoice_financials', ['invoice_number' => 'AI-UAT-INV-150G-241126-01']);

        DB::table('stock_positions')->where('id', $fixture['saleable_position_id'])
            ->update(['inventory_owner_id' => $fixture['company_owner_id']]);
        $dispatchHeaders = ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '2'];
        $dispatchPayload = ['invoice_number' => 'AI-UAT-INV-150G-241126-01'];
        $dispatched = $this->withHeaders($dispatchHeaders)
            ->postJson('/api/v1/dispatch/shipments/'.$shipmentId.'/dispatch', $dispatchPayload)
            ->assertOk()->assertJsonPath('data.status', 'DISPATCHED')
            ->assertJsonPath('data.invoice_total', '20.0000');
        $invoiceId = (string) $dispatched->json('data.invoice_id');
        $this->withHeaders($dispatchHeaders)
            ->postJson('/api/v1/dispatch/shipments/'.$shipmentId.'/dispatch', $dispatchPayload)
            ->assertOk()->assertExactJson($dispatched->json());

        $this->assertDatabaseHas('shipments', ['id' => $shipmentId, 'status' => 'DISPATCHED', 'record_version' => 3]);
        $this->assertDatabaseHas('sales_allocations', ['id' => $allocationId, 'status' => 'DISPATCHED', 'record_version' => 3]);
        $this->assertDatabaseHas('sales_orders', ['id' => $orderId, 'status' => 'DISPATCHED']);
        $this->assertDatabaseHas('sales_order_lines', [
            'sales_order_id' => $orderId, 'dispatched_quantity' => 1, 'invoiced_quantity' => 1,
        ]);
        $this->assertDatabaseHas('stock_reservations', [
            'stock_position_id' => $fixture['saleable_position_id'], 'quantity_base' => 1, 'status' => 'RELEASED',
        ]);
        $this->assertSame('1006.000000', $this->decimal(DB::table('stock_positions')
            ->where('id', $fixture['saleable_position_id'])->value('quantity_base')));
        $reserved = $this->decimal(DB::table('stock_positions')->where('id', $fixture['saleable_position_id'])
            ->value('reserved_quantity_base'));
        $this->assertSame('0.000000', $reserved);
        $this->assertSame('1006.000000', bcsub('1006.000000', $reserved, 6));
        $this->assertSame(1, DB::table('stock_movements')->where('movement_type', 'SALES_DISPATCH')
            ->where('source_id', $shipmentId)->where('from_position_id', $fixture['saleable_position_id'])->count());
        $this->assertSame(1, DB::table('shipment_lines')->where('shipment_id', $shipmentId)->count());
        $this->assertSame(1, DB::table('sales_invoice_financials')->where('shipment_id', $shipmentId)
            ->where('invoice_number', 'AI-UAT-INV-150G-241126-01')->count());
        $this->assertDatabaseHas('invoices', [
            'id' => $invoiceId, 'status' => 'POSTED', 'subtotal' => 20, 'tax_amount' => 0, 'total_amount' => 20,
        ]);
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
            ->assertJsonPath('data.allocated_quantity', '10.000000')
            ->assertJsonPath('data.fefo_lot_count', 1)->assertJsonPath('data.fefo_break_count', 0);
        $allocationId = (string) $allocation->json('data.id');
        $this->assertDatabaseHas('stock_reservations', ['status' => 'ACTIVE', 'quantity_base' => 10]);
        $this->getJson('/api/v1/dispatch/allocations')->assertOk()
            ->assertJsonPath('lookups.orders.0.order_number', 'SO-P2-001')
            ->assertJsonPath('lookups.orders.0.customer_name', 'North Market Distributor');
        $this->signIn(self::OPERATIONS_ID);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/dispatch/allocations/'.$allocationId.'/pick')
            ->assertOk()->assertJsonPath('data.status', 'PICKED')->assertJsonPath('data.record_version', 2);
        $this->getJson('/api/v1/dispatch/allocations')->assertOk()
            ->assertJsonPath('data.0.allocated_quantity', '10.000000')
            ->assertJsonPath('data.0.picked_quantity', '10.000000')
            ->assertJsonPath('data.0.fefo_lot_count', 1)
            ->assertJsonPath('data.0.fefo_break_count', 0)
            ->assertJsonPath('data.0.allocation_summary_available', true);

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

        $this->getJson('/api/v1/finance/receivables?q=INV-P2-001')->assertOk()
            ->assertJsonPath('data.0.payment_status', 'PARTIALLY_PAID')
            ->assertJsonPath('data.0.status', 'PARTIALLY_PAID')
            ->assertJsonPath('data.0.document_retention_status', 'NOT_ARCHIVED');

        $this->command()->postJson('/api/v1/finance/receivables/collections', [
            'receipt_number' => 'RCPT-P2-001', 'customer_party_id' => self::CUSTOMER_ID,
            'receipt_date' => now()->toDateString(), 'payment_method' => 'BANK', 'bank_reference' => 'BANK-P2-001',
            'total_amount' => '1003', 'allocations' => [['invoice_id' => $invoiceId, 'amount' => '1003']],
        ])->assertCreated()->assertJsonPath('data.status', 'POSTED')->assertJsonPath('data.allocated_amount', '1003.0000');
        $this->getJson('/api/v1/finance/receivables/'.$invoiceId)->assertOk()
            ->assertJsonPath('data.outstanding_amount', '0.0000')->assertJsonCount(3, 'data.transactions');
        $this->getJson('/api/v1/finance/receivables?q=INV-P2-001')->assertOk()
            ->assertJsonPath('data.0.payment_status', 'SETTLED')
            ->assertJsonPath('data.0.document_retention_status', 'NOT_ARCHIVED');

        $partialProfitability = $this->getJson('/api/v1/reports/profitability?q=SO-P2-001')->assertOk()
            ->assertJsonPath('data.0.order_number', 'SO-P2-001')
            ->assertJsonPath('data.0.recognized_revenue', '950.000000')
            ->assertJsonPath('data.0.costed_revenue', '0.000000')
            ->assertJsonPath('data.0.uncosted_revenue', '950.000000')
            ->assertJsonPath('data.0.cost_coverage_percent', '0.0000')
            ->assertJsonPath('data.0.cost_status', 'PARTIAL')
            ->assertJsonPath('data.0.gross_margin', null)
            ->assertJsonPath('data.0.margin_percent', null)
            ->assertJsonPath('summary.margin_status', 'PARTIAL_COST_COVERAGE');
        $this->assertNotNull($partialProfitability->json('data.0.known_gross_margin'));

        [$productionScheduleId, $productionScheduleLineId] = $this->createCostBackfillScheduleFixture();
        $productionOrderId = (string) Str::uuid();
        DB::table('production_orders')->insert([
            'id' => $productionOrderId,
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::PLANT_ID,
            'status' => 'DRAFT',
            'record_version' => 1,
            'created_by' => self::FINANCE_ID,
            'order_number' => 'PROD-COST-BACKFILL-001',
            'batch_number' => 'BATCH-COST-BACKFILL-001',
            'production_schedule_id' => $productionScheduleId,
            'production_schedule_line_id' => $productionScheduleLineId,
            'output_sku_id' => self::ITEM_ID,
            'recipe_id' => '00000000-0000-4000-8000-000000000911',
            'route_id' => '00000000-0000-4000-8000-000000000921',
            'planned_quantity' => 100,
            'uom_code' => 'PACK',
            'planned_start_date' => now()->toDateString(),
            'planned_end_date' => now()->toDateString(),
            'quality_status' => 'PENDING',
            'notes' => 'Controlled finalized cost source for audit acceptance.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $batchCostId = (string) Str::uuid();
        DB::table('batch_costs')->insert([
            'id' => $batchCostId,
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::PLANT_ID,
            'cost_number' => 'COST-BACKFILL-001',
            'production_order_id' => $productionOrderId,
            'snapshot_version' => 1,
            'currency' => 'INR',
            'labour_rate_per_minute' => 1,
            'overhead_rate_per_minute' => 1,
            'planned_material_cost' => 300,
            'actual_material_cost' => 300,
            'planned_conversion_cost' => 200,
            'actual_conversion_cost' => 200,
            'planned_total_cost' => 500,
            'actual_total_cost' => 500,
            'total_variance' => 0,
            'variance_percent' => 0,
            'good_quantity' => 100,
            'yield_percent' => 100,
            'cost_per_good_unit' => 5,
            'status' => 'FINALIZED',
            'calculated_by' => self::FINANCE_ID,
            'calculated_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->signIn(self::FINANCE_ID);
        $this->command()->postJson('/api/v1/reports/profitability/cost-backfill', ['order_id' => $orderId])
            ->assertOk()->assertJsonPath('data.updated_count', 1)
            ->assertJsonPath('data.unavailable_count', 0);
        $lineId = (string) DB::table('sales_order_lines')->where('sales_order_id', $orderId)->value('id');
        $this->assertDatabaseHas('sales_order_lines', [
            'id' => $lineId,
            'cost_snapshot_status' => 'BACKFILLED',
            'cost_snapshot_source_type' => 'BATCH_COST',
            'cost_snapshot_source_id' => $batchCostId,
            'cost_backfilled_by' => self::FINANCE_ID,
        ]);
        $this->assertNotNull(DB::table('sales_order_lines')->where('id', $lineId)->value('cost_snapshot_source_id'));
        $this->assertDatabaseHas('audit_events', ['command' => 'BACKFILL_PROFITABILITY_COST', 'entity_id' => $lineId]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'sales.profitability-cost.backfilled', 'aggregate_id' => $lineId]);
        $backfilled = $this->getJson('/api/v1/reports/profitability?q=SO-P2-001')->assertOk()
            ->assertJsonPath('data.0.cost_status', 'COMPLETE')
            ->assertJsonPath('data.0.uncosted_revenue', '0.000000')
            ->assertJsonPath('summary.margin_status', 'DEFINITIVE');
        $this->assertNotNull($backfilled->json('summary.margin_percent'));
        $this->assertDatabaseHas('invoices', ['id' => $invoiceId, 'invoice_type' => 'RECEIVABLE', 'status' => 'PAID']);
        $this->assertDatabaseHas('audit_events', ['command' => 'DISPATCH_SALES_SHIPMENT', 'entity_id' => $shipmentId]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'finance.customer-receipt.posted']);
    }

    public function test_draft_and_cancelled_orders_never_enter_recognized_revenue(): void
    {
        $order = $this->command()->postJson('/api/v1/sales/orders', [
            'order_number' => 'SO-AUDIT-CANCELLED',
            'customer_party_id' => self::CUSTOMER_ID,
            'sales_lead_id' => null,
            'sales_contract_id' => self::CONTRACT_ID,
            'sales_price_list_id' => null,
            'order_date' => now()->toDateString(),
            'requested_delivery_date' => now()->addDays(3)->toDateString(),
            'notes' => 'Revenue-recognition cancellation acceptance case.',
            'lines' => [[
                'item_id' => self::ITEM_ID, 'uom_code' => 'PACK', 'quantity' => '2', 'discount_percent' => '0',
            ]],
        ])->assertCreated()->assertJsonPath('data.status', 'DRAFT');
        $orderId = (string) $order->json('data.id');

        $this->getJson('/api/v1/reports/profitability?q=SO-AUDIT-CANCELLED')->assertOk()
            ->assertJsonPath('data.0.order_value', '190.000000')
            ->assertJsonPath('data.0.booked_revenue', '0.000000')
            ->assertJsonPath('data.0.recognized_revenue', '0.000000')
            ->assertJsonPath('data.0.revenue', '0.000000')
            ->assertJsonPath('data.0.cost_status', 'NOT_RECOGNIZED');

        $this->withHeaders($this->headers(1))->postJson('/api/v1/sales/orders/'.$orderId.'/confirm')
            ->assertOk()->assertJsonPath('data.status', 'CONFIRMED');
        $this->getJson('/api/v1/reports/profitability?q=SO-AUDIT-CANCELLED')->assertOk()
            ->assertJsonPath('data.0.booked_revenue', '190.000000')
            ->assertJsonPath('data.0.recognized_revenue', '0.000000');

        $this->withHeaders($this->headers(2))->postJson('/api/v1/sales/orders/'.$orderId.'/cancel', [
            'reason' => 'Controlled audit cancellation.',
        ])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->getJson('/api/v1/reports/profitability?q=SO-AUDIT-CANCELLED')->assertOk()
            ->assertJsonPath('data.0.status', 'CANCELLED')
            ->assertJsonPath('data.0.booked_revenue', '0.000000')
            ->assertJsonPath('data.0.recognized_revenue', '0.000000')
            ->assertJsonPath('summary.recognized_revenue', '0.000000')
            ->assertJsonPath('summary.cancelled_orders', 1);
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
        $this->getJson('/api/v1/sales/third-party-work')->assertOk()
            ->assertJsonPath('lookups.work_types.0', 'CO_PACKING');
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

    /** @return array{0: string, 1: string} */
    private function createCostBackfillScheduleFixture(): array
    {
        $demandPlanId = (string) Str::uuid();
        $demandLineId = (string) Str::uuid();
        $mrpRunId = (string) Str::uuid();
        $plannedOrderId = (string) Str::uuid();
        $scheduleId = (string) Str::uuid();
        $scheduleLineId = (string) Str::uuid();
        $today = now()->toDateString();
        $timestamps = ['created_at' => now(), 'updated_at' => now()];

        DB::table('demand_plans')->insert([
            'id' => $demandPlanId, 'company_id' => self::COMPANY_ID, 'plant_id' => self::PLANT_ID,
            'plan_number' => 'DEMAND-COST-BACKFILL-001', 'name' => 'Cost backfill test demand',
            'horizon_start' => $today, 'horizon_end' => $today, 'status' => 'DRAFT',
            'record_version' => 1, 'created_by' => self::FINANCE_ID,
        ] + $timestamps);
        DB::table('demand_plan_lines')->insert([
            'id' => $demandLineId, 'demand_plan_id' => $demandPlanId,
            'company_id' => self::COMPANY_ID, 'plant_id' => self::PLANT_ID, 'line_number' => 1,
            'output_sku_id' => self::ITEM_ID, 'demand_date' => $today, 'demand_type' => 'FIRM',
            'quantity' => 100, 'uom_code' => 'PACK',
        ] + $timestamps);
        DB::table('mrp_runs')->insert([
            'id' => $mrpRunId, 'company_id' => self::COMPANY_ID, 'plant_id' => self::PLANT_ID,
            'run_number' => 'MRP-COST-BACKFILL-001', 'demand_plan_id' => $demandPlanId,
            'demand_plan_version_snapshot' => 1, 'run_date' => $today, 'status' => 'COMPLETED',
            'record_version' => 1, 'created_by' => self::FINANCE_ID, 'completed_at' => now(),
        ] + $timestamps);
        DB::table('mrp_planned_orders')->insert([
            'id' => $plannedOrderId, 'mrp_run_id' => $mrpRunId, 'demand_plan_id' => $demandPlanId,
            'demand_plan_line_id' => $demandLineId, 'company_id' => self::COMPANY_ID,
            'plant_id' => self::PLANT_ID, 'line_number' => 1, 'output_sku_id' => self::ITEM_ID,
            'recipe_id' => '00000000-0000-4000-8000-000000000911', 'recipe_revision_snapshot' => 1,
            'due_date' => $today, 'planned_quantity' => 100, 'uom_code' => 'PACK',
        ] + $timestamps);
        DB::table('production_schedules')->insert([
            'id' => $scheduleId, 'company_id' => self::COMPANY_ID, 'plant_id' => self::PLANT_ID,
            'schedule_number' => 'SCHED-COST-BACKFILL-001', 'mrp_run_id' => $mrpRunId,
            'horizon_start' => $today, 'horizon_end' => $today, 'status' => 'DRAFT',
            'record_version' => 1, 'created_by' => self::FINANCE_ID,
        ] + $timestamps);
        DB::table('production_schedule_lines')->insert([
            'id' => $scheduleLineId, 'production_schedule_id' => $scheduleId, 'mrp_run_id' => $mrpRunId,
            'mrp_planned_order_id' => $plannedOrderId, 'company_id' => self::COMPANY_ID,
            'plant_id' => self::PLANT_ID, 'line_number' => 1, 'output_sku_id' => self::ITEM_ID,
            'recipe_id' => '00000000-0000-4000-8000-000000000911',
            'route_id' => '00000000-0000-4000-8000-000000000921', 'planned_quantity' => 100,
            'uom_code' => 'PACK', 'planned_start_date' => $today, 'planned_end_date' => $today,
            'is_active' => true,
        ] + $timestamps);

        return [$scheduleId, $scheduleLineId];
    }

    /** @return array{company_owner_id: string, item_id: string, saleable_lot_id: string, saleable_position_id: string, excluded_position_ids: list<string>} */
    private function createManualBreadAllocationFixture(): array
    {
        DB::table('stock_reservations')->delete();
        DB::table('stock_positions')->delete();
        DB::table('inventory_owners')->where('company_id', self::COMPANY_ID)
            ->where('owner_type', 'COMPANY')->delete();

        $now = now();
        $companyOwnerId = (string) Str::uuid();
        DB::table('inventory_owners')->insert([
            'id' => $companyOwnerId, 'company_id' => self::COMPANY_ID, 'party_id' => null,
            'code' => 'OWN-MANUAL-COMPANY', 'name' => 'Manual company-owned stock',
            'owner_type' => 'COMPANY', 'status' => 'ACTIVE', 'record_version' => 1,
            'status_reason' => 'Independent owner identity regression fixture.', 'status_changed_at' => $now,
            'status_changed_by' => self::ADMIN_ID, 'created_at' => $now, 'updated_at' => $now,
        ]);

        $itemId = (string) Str::uuid();
        $catalogItemId = (string) Str::uuid();
        DB::table('catalog_items')->insert([
            'id' => $catalogItemId, 'company_id' => self::COMPANY_ID, 'brand_id' => null,
            'code' => 'MANUAL-BREAD-150G', 'name' => 'Manual Bread 150g', 'item_type' => 'FINISHED_GOOD',
            'base_uom' => 'PCS', 'description' => 'Allocation regression catalog item.', 'shelf_life_days' => 3,
            'lot_controlled' => true, 'status' => 'ACTIVE', 'record_version' => 1,
            'status_reason' => 'Regression fixture.', 'status_changed_at' => $now,
            'status_changed_by' => self::ADMIN_ID, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('items')->insert([
            'id' => $itemId, 'company_id' => self::COMPANY_ID, 'catalog_item_id' => $catalogItemId,
            'code' => 'MANUAL-SKU-BREAD-150G', 'name' => 'Manual Bread 150g', 'barcode' => null,
            'description' => 'Allocation regression SKU.', 'item_type' => 'FINISHED_GOOD', 'base_uom' => 'PCS',
            'pack_quantity' => 1, 'pack_uom_code' => 'PCS', 'status' => 'ACTIVE', 'record_version' => 1,
            'status_reason' => 'Regression fixture.', 'status_changed_at' => $now,
            'status_changed_by' => self::ADMIN_ID, 'created_at' => $now, 'updated_at' => $now,
        ]);
        DB::table('sales_contract_lines')->insert([
            'id' => (string) Str::uuid(), 'sales_contract_id' => self::CONTRACT_ID,
            'company_id' => self::COMPANY_ID, 'plant_id' => self::PLANT_ID, 'line_number' => 2,
            'item_id' => $itemId, 'uom_code' => 'PCS', 'committed_quantity' => 5000,
            'consumed_quantity' => 0, 'unit_price' => 20, 'tax_rate' => 0,
            'created_at' => $now, 'updated_at' => $now,
        ]);

        $saleableLotId = (string) Str::uuid();
        $expiredLotId = (string) Str::uuid();
        DB::table('lots')->insert([
            [
                'id' => $saleableLotId, 'company_id' => self::COMPANY_ID, 'item_id' => $itemId,
                'supplier_party_id' => null, 'internal_lot_code' => 'MANUAL-FGLOT-BREAD-150G-221126',
                'supplier_lot_code' => null, 'origin_type' => 'PRODUCTION', 'manufacture_date' => '2026-11-22',
                'expiry_date' => '2026-11-25', 'status' => 'ACTIVE', 'notes' => 'Saleable regression lot.',
                'record_version' => 1, 'status_reason' => 'Regression fixture.', 'status_changed_at' => $now,
                'status_changed_by' => self::ADMIN_ID, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'id' => $expiredLotId, 'company_id' => self::COMPANY_ID, 'item_id' => $itemId,
                'supplier_party_id' => null, 'internal_lot_code' => 'MANUAL-FGLOT-BREAD-150G-EXPIRED',
                'supplier_lot_code' => null, 'origin_type' => 'PRODUCTION', 'manufacture_date' => '2026-11-20',
                'expiry_date' => '2026-11-22', 'status' => 'ACTIVE', 'notes' => 'Expired exclusion fixture.',
                'record_version' => 1, 'status_reason' => 'Regression fixture.', 'status_changed_at' => $now,
                'status_changed_by' => self::ADMIN_ID, 'created_at' => $now, 'updated_at' => $now,
            ],
        ]);

        $finishedGoodsLocationId = (string) DB::table('locations')->where('company_id', self::COMPANY_ID)
            ->where('plant_id', self::PLANT_ID)->where('location_type', 'FINISHED_GOODS')
            ->where('status', 'ACTIVE')->value('id');
        $nonFinishedGoodsLocationId = (string) DB::table('locations')->where('company_id', self::COMPANY_ID)
            ->where('plant_id', self::PLANT_ID)->where('location_type', '<>', 'FINISHED_GOODS')
            ->where('status', 'ACTIVE')->orderBy('code')->value('id');
        $otherPlantFinishedGoodsLocationId = (string) DB::table('locations')->where('company_id', self::COMPANY_ID)
            ->where('plant_id', self::OTHER_PLANT_ID)->where('location_type', 'FINISHED_GOODS')
            ->where('status', 'ACTIVE')->value('id');
        $otherItemLotId = (string) DB::table('lots')->where('company_id', self::COMPANY_ID)
            ->where('item_id', self::ITEM_ID)->where('status', 'ACTIVE')->orderBy('id')->value('id');
        $otherOwner = DB::table('inventory_owners')->where('company_id', self::COMPANY_ID)
            ->where('owner_type', 'PARTY')->where('status', 'ACTIVE')->first();
        self::assertNotNull($otherOwner);

        $saleablePositionId = (string) Str::uuid();
        $expiredPositionId = (string) Str::uuid();
        $heldPositionId = (string) Str::uuid();
        $otherOwnerPositionId = (string) Str::uuid();
        $wrongLocationPositionId = (string) Str::uuid();
        $wrongPlantPositionId = (string) Str::uuid();
        $wrongItemPositionId = (string) Str::uuid();
        $positionDefaults = [
            'company_id' => self::COMPANY_ID, 'plant_id' => self::PLANT_ID, 'item_id' => $itemId,
            'quantity_base' => 2000, 'reserved_quantity_base' => 0, 'uom_code' => 'PCS',
            'record_version' => 1, 'status_reason' => null, 'status_changed_at' => null,
            'status_changed_by' => null, 'created_at' => $now, 'updated_at' => $now,
        ];
        DB::table('stock_positions')->insert([
            [
                ...$positionDefaults, 'id' => $saleablePositionId, 'lot_id' => $saleableLotId,
                'owner_party_id' => null, 'inventory_owner_id' => $companyOwnerId,
                'location_id' => $finishedGoodsLocationId, 'quality_status' => 'RELEASED', 'quantity_base' => 1007,
            ],
            [
                ...$positionDefaults, 'id' => $expiredPositionId, 'lot_id' => $expiredLotId,
                'owner_party_id' => null, 'inventory_owner_id' => $companyOwnerId,
                'location_id' => $finishedGoodsLocationId, 'quality_status' => 'RELEASED',
            ],
            [
                ...$positionDefaults, 'id' => $heldPositionId, 'lot_id' => $saleableLotId,
                'owner_party_id' => null, 'inventory_owner_id' => $companyOwnerId,
                'location_id' => $finishedGoodsLocationId, 'quality_status' => 'QUALITY_HOLD',
            ],
            [
                ...$positionDefaults, 'id' => $otherOwnerPositionId, 'lot_id' => $saleableLotId,
                'owner_party_id' => $otherOwner->party_id, 'inventory_owner_id' => $otherOwner->id,
                'location_id' => $finishedGoodsLocationId, 'quality_status' => 'RELEASED',
            ],
            [
                ...$positionDefaults, 'id' => $wrongLocationPositionId, 'lot_id' => $saleableLotId,
                'owner_party_id' => null, 'inventory_owner_id' => $companyOwnerId,
                'location_id' => $nonFinishedGoodsLocationId, 'quality_status' => 'RELEASED',
            ],
            [
                ...$positionDefaults, 'id' => $wrongPlantPositionId, 'plant_id' => self::OTHER_PLANT_ID,
                'lot_id' => $saleableLotId, 'owner_party_id' => null, 'inventory_owner_id' => $companyOwnerId,
                'location_id' => $otherPlantFinishedGoodsLocationId, 'quality_status' => 'RELEASED',
            ],
            [
                ...$positionDefaults, 'id' => $wrongItemPositionId, 'item_id' => self::ITEM_ID,
                'lot_id' => $otherItemLotId, 'owner_party_id' => null, 'inventory_owner_id' => $companyOwnerId,
                'location_id' => $finishedGoodsLocationId, 'quality_status' => 'RELEASED', 'uom_code' => 'PACK',
            ],
        ]);

        return [
            'company_owner_id' => $companyOwnerId,
            'item_id' => $itemId,
            'saleable_lot_id' => $saleableLotId,
            'saleable_position_id' => $saleablePositionId,
            'excluded_position_ids' => [
                $expiredPositionId, $heldPositionId, $otherOwnerPositionId, $wrongLocationPositionId,
                $wrongPlantPositionId, $wrongItemPositionId,
            ],
        ];
    }

    private function signIn(string $userId, string $plantId = self::PLANT_ID): void
    {
        $this->actingAs(User::query()->findOrFail($userId))->withSession(['erp.company_id' => self::COMPANY_ID, 'erp.plant_id' => $plantId]);
    }

    private function command(): static { return $this->withHeader('Idempotency-Key', (string) Str::uuid()); }
    private function headers(int $version): array { return ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => (string) $version]; }
    private function decimal(mixed $value): string { return bcadd((string) ($value ?? 0), '0', 6); }
}

<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class UnsoldReturnLookupEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const PARTY_ID = '00000000-0000-4000-8000-000000000501';
    private const SKU_ID = '00000000-0000-4000-8000-000000000601';
    private const LOT_ID = '00000000-0000-4000-8000-000000000701';
    private const SHIPMENT_ID = '00000000-0000-4000-8000-000000001001';
    private const INVOICE_ID = '00000000-0000-4000-8000-000000001301';
    private const FINANCE_SHIPMENT_ID = '00000000-0000-4000-8000-000000001003';
    private const RETURN_POSITION_ID = '00000000-0000-4000-8000-000000001201';
    private const FINANCE_RETURN_POSITION_ID = '00000000-0000-4000-8000-000000001203';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $user = User::query()->where('email', 'demo.user@qtfoods.local')->firstOrFail();
        $this->actingAs($user)->withSession([
            'erp.company_id' => '00000000-0000-4000-8000-000000000001',
            'erp.plant_id' => '00000000-0000-4000-8000-000000000101',
        ]);
    }

    public function test_initial_lookups_return_active_reference_data_for_the_selected_context(): void
    {
        $this->getJson('/api/v1/sales/unsold-returns/lookups')
            ->assertOk()
            ->assertJsonCount(2, 'data.parties')
            ->assertJsonCount(2, 'data.shipments')
            ->assertJsonCount(0, 'data.invoices')
            ->assertJsonCount(0, 'data.shipment_lines')
            ->assertJsonCount(2, 'data.skus')
            ->assertJsonCount(0, 'data.lots')
            ->assertJsonCount(0, 'data.return_positions')
            ->assertJsonFragment(['number' => 'SHP-2026-0001'])
            ->assertJsonFragment(['code' => 'SKU-APPLE-100'])
            ->assertJsonMissing(['id' => self::FINANCE_SHIPMENT_ID]);
    }

    public function test_dependent_lookups_resolve_the_shipped_line_lot_and_quarantine_position(): void
    {
        $response = $this->getJson('/api/v1/sales/unsold-returns/lookups?'.http_build_query([
            'party_id' => self::PARTY_ID,
            'shipment_id' => self::SHIPMENT_ID,
            'sku_id' => self::SKU_ID,
            'lot_id' => self::LOT_ID,
        ]));

        $response
            ->assertOk()
            ->assertJsonCount(2, 'data.parties')
            ->assertJsonCount(1, 'data.shipments')
            ->assertJsonCount(1, 'data.invoices')
            ->assertJsonCount(1, 'data.shipment_lines')
            ->assertJsonCount(2, 'data.skus')
            ->assertJsonCount(1, 'data.lots')
            ->assertJsonCount(1, 'data.return_positions')
            ->assertJsonPath('data.shipment_lines.0.sku.id', self::SKU_ID)
            ->assertJsonPath('data.invoices.0.id', self::INVOICE_ID)
            ->assertJsonPath('data.invoices.0.number', 'INV-2026-0001')
            ->assertJsonPath('data.invoices.0.outstanding_amount', '4000')
            ->assertJsonPath('data.shipment_lines.0.fg_lot.id', self::LOT_ID)
            ->assertJsonPath('data.shipment_lines.0.available_to_return', '100.000000')
            ->assertJsonPath('data.return_positions.0.id', self::RETURN_POSITION_ID)
            ->assertJsonPath('data.return_positions.0.location.code', 'RET-QA')
            ->assertJsonPath('data.return_positions.0.quality_status', 'RETURN_QUARANTINE')
            ->assertJsonMissing(['id' => self::FINANCE_RETURN_POSITION_ID]);
    }

    public function test_an_out_of_scope_shipment_cannot_be_resolved_by_id(): void
    {
        $this->getJson('/api/v1/sales/unsold-returns/lookups?'.http_build_query([
            'shipment_id' => self::FINANCE_SHIPMENT_ID,
        ]))
            ->assertOk()
            ->assertJsonCount(2, 'data.shipments')
            ->assertJsonCount(0, 'data.invoices')
            ->assertJsonCount(0, 'data.shipment_lines')
            ->assertJsonMissing(['id' => self::FINANCE_SHIPMENT_ID]);
    }

    public function test_only_active_customer_role_parties_and_shipments_are_offered(): void
    {
        DB::table('party_roles')->where('party_id', self::PARTY_ID)
            ->update(['role_code' => 'SUPPLIER']);

        $this->getJson('/api/v1/sales/unsold-returns/lookups')
            ->assertOk()
            ->assertJsonCount(1, 'data.parties')
            ->assertJsonCount(1, 'data.shipments')
            ->assertJsonMissing(['id' => self::PARTY_ID])
            ->assertJsonMissing(['id' => self::SHIPMENT_ID]);
    }

    public function test_lookup_filters_are_validated(): void
    {
        $this->getJson('/api/v1/sales/unsold-returns/lookups?party_id=not-a-uuid&limit=101')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure([
                'error' => ['fields' => ['party_id', 'limit']],
            ]);
    }
}

<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UnsoldReturnEndpointTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_create_requires_an_idempotency_header(): void
    {
        $this->postJson('/api/v1/sales/unsold-returns', $this->payload())
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonPath(
                'error.fields.idempotency_key.0',
                'The Idempotency-Key header is required.'
            );
    }

    public function test_create_accepts_a_valid_idempotent_request(): void
    {
        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales/unsold-returns', $this->payload());

        $response
            ->assertCreated()
            ->assertJsonPath('data.status', 'REQUESTED')
            ->assertJsonPath('data.record_version', 1);

        $this->assertDatabaseHas('unsold_return_cases', [
            'id' => $response->json('data.return_case_id'),
            'company_id' => '00000000-0000-4000-8000-000000000001',
            'plant_id' => '00000000-0000-4000-8000-000000000101',
            'invoice_id' => '00000000-0000-4000-8000-000000001301',
        ]);
    }

    public function test_create_rejects_a_scope_different_from_the_active_context(): void
    {
        $payload = $this->payload() + [
            'company_id' => '00000000-0000-4000-8000-000000000001',
            'plant_id' => '00000000-0000-4000-8000-000000000102',
        ];

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales/unsold-returns', $payload)
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.fields.context.0',
                'The request company and plant must match the active ERP context.'
            );
    }

    public function test_create_rejects_a_shipment_reference_from_another_plant(): void
    {
        $payload = $this->payload();
        $payload['shipment_id'] = '00000000-0000-4000-8000-000000001003';
        $payload['lines'][0]['shipment_line_id'] = '00000000-0000-4000-8000-000000001103';

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales/unsold-returns', $payload)
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.fields.shipment_id.0',
                'Select an eligible shipment for this customer and plant.'
            )
            ->assertJsonFragment([
                'lines.0.shipment_line_id' => ['Select a line from the eligible shipment.'],
            ]);
    }

    public function test_create_rejects_a_mismatched_sku_and_excess_quantity(): void
    {
        $payload = $this->payload();
        $payload['lines'][0]['sku_id'] = '00000000-0000-4000-8000-000000000602';
        $payload['lines'][0]['fg_lot_id'] = '00000000-0000-4000-8000-000000000702';
        $payload['lines'][0]['requested_quantity'] = '101';

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales/unsold-returns', $payload)
            ->assertUnprocessable()
            ->assertJsonFragment([
                'lines.0.sku_id' => ['The SKU must match the selected shipment line.'],
                'lines.0.fg_lot_id' => ['The finished-goods lot must match the selected shipment line.'],
                'lines.0.requested_quantity' => ['Requested return quantity exceeds the shipment line quantity available to return.'],
            ]);
    }

    public function test_create_rejects_an_invoice_from_another_customer_or_shipment(): void
    {
        $payload = $this->payload();
        $payload['invoice_id'] = '00000000-0000-4000-8000-000000001302';

        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales/unsold-returns', $payload)
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.fields.invoice_id.0',
                'Select an eligible invoice for this customer and shipment.'
            );
    }

    private function payload(): array
    {
        return [
            'party_id' => '00000000-0000-4000-8000-000000000501',
            'shipment_id' => '00000000-0000-4000-8000-000000001001',
            'invoice_id' => '00000000-0000-4000-8000-000000001301',
            'reason_code' => 'UNSOLD_MARKET_RETURN',
            'lines' => [[
                'shipment_line_id' => '00000000-0000-4000-8000-000000001101',
                'sku_id' => '00000000-0000-4000-8000-000000000601',
                'fg_lot_id' => '00000000-0000-4000-8000-000000000701',
                'requested_quantity' => '10',
                'uom_code' => 'PACK',
            ]],
        ];
    }
}

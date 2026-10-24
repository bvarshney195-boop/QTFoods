<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use App\Shared\Approval\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UnsoldReturnCommandEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const ADMIN_USER_ID = '00000000-0000-4000-8000-000000000204';
    private const FINANCE_USER_ID = '00000000-0000-4000-8000-000000000203';
    private const RETURN_POSITION_ID = '00000000-0000-4000-8000-000000001201';
    private const RETURN_LOCATION_ID = '00000000-0000-4000-8000-000000000801';
    private const WRONG_SKU_POSITION_ID = '00000000-0000-4000-8000-000000001202';
    private const SHIPMENT_LINE_ID = '00000000-0000-4000-8000-000000001101';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->actingAs(User::query()->findOrFail(self::ADMIN_USER_ID))->withSession([
            'erp.company_id' => self::COMPANY_ID,
            'erp.plant_id' => self::PLANT_ID,
        ]);
    }

    public function test_receive_requires_version_and_idempotency_headers(): void
    {
        [$caseId, $lineId] = $this->createCase();

        $this->postJson("/api/v1/sales/unsold-returns/{$caseId}/receive", $this->receipt($lineId))
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.fields.if_match.0',
                'The If-Match header is required for this state-changing command.'
            );

        $this->withHeaders(['If-Match' => '1', 'Idempotency-Key' => ''])
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/receive", $this->receipt($lineId))
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.fields.idempotency_key.0',
                'The Idempotency-Key header is required.'
            );
    }

    public function test_receive_validates_and_posts_quarantine_stock_once(): void
    {
        [$caseId, $lineId] = $this->createCase();
        $key = (string) Str::uuid();
        $headers = ['If-Match' => '1', 'Idempotency-Key' => $key];

        $first = $this->withHeaders($headers)
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/receive", $this->receipt($lineId));

        $first
            ->assertOk()
            ->assertJsonPath('data.status', 'RETURN_QUARANTINE')
            ->assertJsonPath('data.record_version', 2)
            ->assertJsonCount(1, 'data.stock_movement_ids');

        $replay = $this->withHeaders($headers)
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/receive", $this->receipt($lineId));

        $replay
            ->assertOk()
            ->assertExactJson($first->json());

        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertEquals(
            10.0,
            (float) DB::table('stock_positions')->where('id', self::RETURN_POSITION_ID)->value('quantity_base')
        );
        $this->assertEquals(
            30.0,
            (float) DB::table('shipment_lines')->where('id', self::SHIPMENT_LINE_ID)->value('returned_quantity')
        );

        $this->withHeaders(['If-Match' => '1', 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/receive", $this->receipt($lineId))
            ->assertConflict()
            ->assertJsonPath('error.code', 'CONFLICT');
    }

    public function test_receive_rejects_a_position_for_a_different_sku(): void
    {
        [$caseId, $lineId] = $this->createCase();
        $payload = $this->receipt($lineId);
        $payload['lines'][0]['return_position_id'] = self::WRONG_SKU_POSITION_ID;

        $this->withHeaders(['If-Match' => '1', 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/receive", $payload)
            ->assertUnprocessable()
            ->assertJsonFragment([
                'lines.0.return_position_id' => [
                    'Select an active return-quarantine position for this case plant, SKU, lot, and UOM.',
                ],
            ]);

        $this->assertDatabaseCount('stock_movements', 0);
        $this->assertDatabaseHas('unsold_return_cases', [
            'id' => $caseId,
            'status' => 'REQUESTED',
            'record_version' => 1,
        ]);
    }

    public function test_missing_position_is_safely_provisioned_by_location_and_reconciles_through_finance(): void
    {
        // Keep the location eligible while making the seeded coordinate ineligible. The receipt
        // must create the exact plant/lot/SKU/owner/UOM quarantine coordinate, not reuse it loosely.
        DB::table('stock_positions')->where('id', self::RETURN_POSITION_ID)->update([
            'quality_status' => 'BLOCKED',
            'updated_at' => now(),
        ]);
        [$caseId, $lineId] = $this->createCase();

        $receipt = ['lines' => [[
            'line_id' => $lineId,
            'received_quantity' => '10',
            'return_location_id' => self::RETURN_LOCATION_ID,
        ]]];
        $this->withHeaders(['If-Match' => '1', 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/receive", $receipt)
            ->assertOk()->assertJsonPath('data.status', 'RETURN_QUARANTINE');

        $positionId = (string) DB::table('unsold_return_lines')->where('id', $lineId)->value('return_position_id');
        $this->assertNotSame(self::RETURN_POSITION_ID, $positionId);
        $this->assertDatabaseHas('stock_positions', [
            'id' => $positionId,
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::PLANT_ID,
            'item_id' => '00000000-0000-4000-8000-000000000601',
            'lot_id' => '00000000-0000-4000-8000-000000000701',
            'inventory_owner_id' => self::COMPANY_ID,
            'location_id' => self::RETURN_LOCATION_ID,
            'quality_status' => 'RETURN_QUARANTINE',
            'uom_code' => 'PACK',
            'quantity_base' => 10,
        ]);

        $disposition = $this->withHeaders(['If-Match' => '2', 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/disposition", ['lines' => [[
                'line_id' => $lineId,
                'restock_quantity' => '0',
                'repack_quantity' => '0',
                'rework_quantity' => '0',
                'destroy_quantity' => '10',
                'quality_reason_code' => 'SHORT_SHELF_LIFE',
            ]]])
            ->assertOk()->assertJsonPath('data.status', 'DISPOSITION_REVIEW');
        $this->app->make(ApprovalService::class)->decide(
            $disposition->json('data.approval_request_id'),
            self::FINANCE_USER_ID,
            'APPROVE',
        );

        $this->withHeaders(['If-Match' => '3', 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/post-loss", [
                'uom_code' => 'PACK', 'cost_amount' => '125.50', 'currency' => 'INR',
            ])
            ->assertOk()->assertJsonPath('data.status', 'LOSS_POSTED')
            ->assertJsonPath('data.loss_quantity', '10.000000');

        $this->assertSame('0.000000', $this->decimal(DB::table('stock_positions')->where('id', $positionId)->value('quantity_base')));
        $this->assertDatabaseHas('loss_events', [
            'source_type' => 'UNSOLD_RETURN',
            'source_id' => $caseId,
            'quantity_base' => 10,
            'cost_amount' => 125.5,
            'currency' => 'INR',
        ]);
    }

    public function test_disposition_and_loss_posting_are_idempotent_and_versioned(): void
    {
        [$caseId, $lineId] = $this->createCase();

        $this->withHeaders(['If-Match' => '1', 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/receive", $this->receipt($lineId))
            ->assertOk();

        $dispositionPayload = ['lines' => [[
            'line_id' => $lineId,
            'restock_quantity' => '0',
            'repack_quantity' => '2',
            'rework_quantity' => '0',
            'destroy_quantity' => '8',
            'quality_reason_code' => 'SHORT_SHELF_LIFE',
        ]]];
        $dispositionKey = (string) Str::uuid();
        $dispositionHeaders = ['If-Match' => '2', 'Idempotency-Key' => $dispositionKey];

        $firstDisposition = $this->withHeaders($dispositionHeaders)
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/disposition", $dispositionPayload);
        $firstDisposition
            ->assertOk()
            ->assertJsonPath('data.status', 'DISPOSITION_REVIEW')
            ->assertJsonPath('data.record_version', 3);

        $this->withHeaders($dispositionHeaders)
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/disposition", $dispositionPayload)
            ->assertOk()
            ->assertExactJson($firstDisposition->json());
        $this->assertDatabaseCount('approval_requests', 1);

        $this->app->make(ApprovalService::class)->decide(
            $firstDisposition->json('data.approval_request_id'),
            self::FINANCE_USER_ID,
            'APPROVE'
        );

        $lossPayload = ['uom_code' => 'PACK', 'cost_amount' => '125.50', 'currency' => 'INR'];
        $lossKey = (string) Str::uuid();
        $lossHeaders = ['If-Match' => '3', 'Idempotency-Key' => $lossKey];
        $firstLoss = $this->withHeaders($lossHeaders)
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/post-loss", $lossPayload);

        $firstLoss
            ->assertOk()
            ->assertJsonPath('data.status', 'LOSS_POSTED')
            ->assertJsonPath('data.record_version', 4)
            ->assertJsonPath('data.loss_quantity', '8.000000');

        $this->withHeaders($lossHeaders)
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/post-loss", $lossPayload)
            ->assertOk()
            ->assertExactJson($firstLoss->json());

        $this->assertDatabaseCount('loss_events', 1);
    }

    private function createCase(): array
    {
        $response = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/sales/unsold-returns', [
                'party_id' => '00000000-0000-4000-8000-000000000501',
                'shipment_id' => '00000000-0000-4000-8000-000000001001',
                'reason_code' => 'UNSOLD_MARKET_RETURN',
                'lines' => [[
                    'shipment_line_id' => self::SHIPMENT_LINE_ID,
                    'sku_id' => '00000000-0000-4000-8000-000000000601',
                    'fg_lot_id' => '00000000-0000-4000-8000-000000000701',
                    'requested_quantity' => '10',
                    'uom_code' => 'PACK',
                ]],
            ])
            ->assertCreated();

        $caseId = $response->json('data.return_case_id');
        $lineId = DB::table('unsold_return_lines')->where('return_case_id', $caseId)->value('id');

        return [$caseId, $lineId];
    }

    private function receipt(string $lineId): array
    {
        return ['lines' => [[
            'line_id' => $lineId,
            'received_quantity' => '10',
            'return_position_id' => self::RETURN_POSITION_ID,
        ]]];
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 6);
    }
}

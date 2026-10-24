<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use App\Modules\Sales\Application\UnsoldSalesReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UnsoldReturnApprovalEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const TRAINING_PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const FINANCE_PLANT_ID = '00000000-0000-4000-8000-000000000102';
    private const SALES_USER_ID = '00000000-0000-4000-8000-000000000201';
    private const OPERATIONS_USER_ID = '00000000-0000-4000-8000-000000000202';
    private const FINANCE_USER_ID = '00000000-0000-4000-8000-000000000203';
    private const ADMIN_USER_ID = '00000000-0000-4000-8000-000000000204';
    private const RETURN_POSITION_ID = '00000000-0000-4000-8000-000000001201';
    private const RESTOCK_POSITION_ID = '00000000-0000-4000-8000-000000001204';
    private const REPACK_POSITION_ID = '00000000-0000-4000-8000-000000001205';
    private const REWORK_POSITION_ID = '00000000-0000-4000-8000-000000001206';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->signIn(self::FINANCE_USER_ID, self::TRAINING_PLANT_ID);
    }

    public function test_reviewer_inbox_and_detail_are_scoped_and_enriched(): void
    {
        [$approvalId, $caseId] = $this->createPendingApproval();
        [$otherApprovalId] = $this->createPendingApproval(
            self::OPERATIONS_USER_ID,
            self::FINANCE_PLANT_ID
        );

        $this->getJson('/api/v1/sales/unsold-return-approvals?status=PENDING&sort=created_at')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $approvalId)
            ->assertJsonPath('data.0.return_case.id', $caseId)
            ->assertJsonPath('data.0.return_case.party.code', 'DIST-NORTH')
            ->assertJsonPath('data.0.maker.name', 'Demo Operations Manager')
            ->assertJsonPath('data.0.record_version', 1)
            ->assertJsonPath('data.0.can_decide', true)
            ->assertJsonMissing(['id' => $otherApprovalId]);

        $this->getJson("/api/v1/sales/unsold-return-approvals/{$approvalId}")
            ->assertOk()
            ->assertJsonPath('data.id', $approvalId)
            ->assertJsonPath('data.lines.0.destroy_quantity', '8')
            ->assertJsonPath('data.lines.0.repack_quantity', '2')
            ->assertJsonPath('data.lines.0.sku.code', 'SKU-APPLE-100')
            ->assertJsonPath('data.decisions', []);

        $this->getJson("/api/v1/sales/unsold-return-approvals/{$otherApprovalId}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_only_an_authorised_reviewer_can_open_the_inbox(): void
    {
        $this->createPendingApproval();
        $this->signIn(self::OPERATIONS_USER_ID, self::TRAINING_PLANT_ID);

        $this->getJson('/api/v1/sales/unsold-return-approvals')
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_approve_is_versioned_idempotent_and_unlocks_finance_posting(): void
    {
        [$approvalId, $caseId] = $this->createPendingApproval(
            self::OPERATIONS_USER_ID,
            self::TRAINING_PLANT_ID,
            [
                'restock_quantity' => '1',
                'repack_quantity' => '2',
                'rework_quantity' => '3',
                'destroy_quantity' => '4',
            ]
        );

        $this->postJson("/api/v1/sales/unsold-return-approvals/{$approvalId}/approve")
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.fields.if_match.0',
                'The If-Match header is required for this approval decision.'
            );

        $this->withHeaders(['If-Match' => '1', 'Idempotency-Key' => ''])
            ->postJson("/api/v1/sales/unsold-return-approvals/{$approvalId}/approve")
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.idempotency_key.0', 'The Idempotency-Key header is required.');

        $decisionKey = (string) Str::uuid();
        $headers = ['If-Match' => '1', 'Idempotency-Key' => $decisionKey];
        $first = $this->withHeaders($headers)
            ->postJson("/api/v1/sales/unsold-return-approvals/{$approvalId}/approve", [
                'reason' => 'Reviewed against the physical return and Quality disposition.',
            ]);

        $first
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'APPROVED')
            ->assertJsonPath('data.approval_record_version', 2)
            ->assertJsonPath('data.case_status', 'DISPOSITION_REVIEW')
            ->assertJsonPath('data.case_record_version', 3)
            ->assertJsonCount(3, 'data.stock_movement_ids');

        $this->assertSame(
            4.0,
            (float) DB::table('stock_positions')->where('id', self::RETURN_POSITION_ID)->value('quantity_base')
        );
        $this->assertSame(
            1.0,
            (float) DB::table('stock_positions')->where('id', self::RESTOCK_POSITION_ID)->value('quantity_base')
        );
        $this->assertSame(
            2.0,
            (float) DB::table('stock_positions')->where('id', self::REPACK_POSITION_ID)->value('quantity_base')
        );
        $this->assertSame(
            3.0,
            (float) DB::table('stock_positions')->where('id', self::REWORK_POSITION_ID)->value('quantity_base')
        );

        $this->withHeaders($headers)
            ->postJson("/api/v1/sales/unsold-return-approvals/{$approvalId}/approve", [
                'reason' => 'Reviewed against the physical return and Quality disposition.',
            ])
            ->assertOk()
            ->assertExactJson($first->json());

        $this->assertDatabaseHas('approval_requests', [
            'id' => $approvalId,
            'status' => 'APPROVED',
            'record_version' => 2,
        ]);
        $this->assertDatabaseHas('work_items', [
            'source_type' => 'approval_request',
            'source_id' => $approvalId,
            'status' => 'COMPLETED',
            'completed_by' => self::FINANCE_USER_ID,
        ]);
        $this->assertDatabaseCount('approval_decisions', 1);
        $this->assertDatabaseHas('audit_events', [
            'command' => 'DECIDE_UNSOLD_RETURN_LOSS_APPROVAL',
            'entity_id' => $approvalId,
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'approval.unsold_return.decided',
            'aggregate_id' => $approvalId,
        ]);

        $this->withHeaders(['If-Match' => '1', 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/sales/unsold-return-approvals/{$approvalId}/approve")
            ->assertConflict()
            ->assertJsonPath('error.code', 'CONFLICT');

        $loss = $this->withHeaders(['If-Match' => '3', 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/sales/unsold-returns/{$caseId}/post-loss", [
                'uom_code' => 'PACK',
                'currency' => 'INR',
            ]);

        $loss
            ->assertOk()
            ->assertJsonPath('data.status', 'LOSS_POSTED')
            ->assertJsonPath('data.loss_quantity', '4.000000')
            ->assertJsonCount(4, 'data.stock_movement_ids')
            ->assertJsonCount(1, 'data.destruction_stock_movement_ids');

        $this->assertSame(
            0.0,
            (float) DB::table('stock_positions')->where('id', self::RETURN_POSITION_ID)->value('quantity_base')
        );
        $this->assertDatabaseCount('stock_movements', 5);
        foreach ([
            'UNSOLD_RETURN_RESTOCK',
            'UNSOLD_RETURN_REPACK',
            'UNSOLD_RETURN_REWORK',
            'UNSOLD_RETURN_DESTROY',
        ] as $movementType) {
            $this->assertDatabaseHas('stock_movements', [
                'source_id' => $caseId,
                'movement_type' => $movementType,
            ]);
        }

        $detail = $this->getJson("/api/v1/sales/unsold-returns/{$caseId}")
            ->assertOk()
            ->assertJsonCount(5, 'data.stock_movements');
        $this->assertEqualsCanonicalizing(
            [
                'UNSOLD_RETURN_RECEIPT',
                'UNSOLD_RETURN_RESTOCK',
                'UNSOLD_RETURN_REPACK',
                'UNSOLD_RETURN_REWORK',
                'UNSOLD_RETURN_DESTROY',
            ],
            array_column($detail->json('data.stock_movements'), 'movement_type')
        );
    }

    public function test_reject_requires_a_reason_and_returns_the_case_to_quality(): void
    {
        [$approvalId, $caseId, $lineId] = $this->createPendingApproval();

        $this->withHeaders(['If-Match' => '1', 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/sales/unsold-return-approvals/{$approvalId}/reject")
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.reason.0', 'The reason field is required.');

        $response = $this->withHeaders([
            'If-Match' => '1',
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson("/api/v1/sales/unsold-return-approvals/{$approvalId}/reject", [
            'reason' => 'Recheck saleable quantity before recognising loss.',
        ]);

        $response
            ->assertOk()
            ->assertJsonPath('data.approval_status', 'REJECTED')
            ->assertJsonPath('data.case_status', 'RETURN_QUARANTINE')
            ->assertJsonPath('data.case_record_version', 4);

        $this->assertDatabaseHas('unsold_return_status_history', [
            'return_case_id' => $caseId,
            'from_status' => 'DISPOSITION_REVIEW',
            'to_status' => 'RETURN_QUARANTINE',
            'record_version' => 4,
        ]);
        $this->assertDatabaseHas('work_items', [
            'source_type' => 'approval_request',
            'source_id' => $approvalId,
            'status' => 'COMPLETED',
            'completed_by' => self::FINANCE_USER_ID,
        ]);
        $this->assertSame(
            10.0,
            (float) DB::table('stock_positions')->where('id', self::RETURN_POSITION_ID)->value('quantity_base')
        );
        $this->assertDatabaseMissing('stock_movements', [
            'source_id' => $caseId,
            'movement_type' => 'UNSOLD_RETURN_REPACK',
        ]);

        $secondApproval = $this->app->make(UnsoldSalesReturnService::class)->disposition($caseId, [
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::TRAINING_PLANT_ID,
            'actor_id' => self::OPERATIONS_USER_ID,
            'expected_version' => 4,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'line_id' => $lineId,
                'restock_quantity' => '1',
                'repack_quantity' => '1',
                'rework_quantity' => '0',
                'destroy_quantity' => '8',
                'quality_reason_code' => 'REVIEWED_AGAIN',
            ]],
        ]);

        $this->assertNotSame($approvalId, $secondApproval['approval_request_id']);
        $this->assertSame(5, $secondApproval['record_version']);
        $this->assertDatabaseHas('approval_requests', [
            'id' => $secondApproval['approval_request_id'],
            'status' => 'PENDING',
            'entity_version' => 5,
        ]);
    }

    public function test_approval_rolls_back_when_an_outcome_position_is_not_configured(): void
    {
        [$approvalId, $caseId] = $this->createPendingApproval();
        DB::table('stock_positions')->where('id', self::REPACK_POSITION_ID)->delete();

        $this->withHeaders([
            'If-Match' => '1',
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson("/api/v1/sales/unsold-return-approvals/{$approvalId}/approve")
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.fields.outcome_position.0',
                'Configure exactly one active repack stock position for each approved SKU, lot, and UOM in this plant.'
            );

        $this->assertDatabaseHas('approval_requests', [
            'id' => $approvalId,
            'status' => 'PENDING',
            'record_version' => 1,
        ]);
        $this->assertDatabaseCount('approval_decisions', 0);
        $this->assertSame(
            10.0,
            (float) DB::table('stock_positions')->where('id', self::RETURN_POSITION_ID)->value('quantity_base')
        );
        $this->assertDatabaseMissing('stock_movements', [
            'source_id' => $caseId,
            'movement_type' => 'UNSOLD_RETURN_REPACK',
        ]);
    }

    public function test_maker_cannot_decide_their_own_request_even_with_permission(): void
    {
        [$approvalId] = $this->createPendingApproval(self::ADMIN_USER_ID);
        $this->signIn(self::ADMIN_USER_ID, self::TRAINING_PLANT_ID);

        $this->withHeaders(['If-Match' => '1', 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/sales/unsold-return-approvals/{$approvalId}/approve")
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.fields.approval.0',
                'Maker cannot approve or reject the same controlled transaction.'
            );

        $this->assertDatabaseHas('approval_requests', [
            'id' => $approvalId,
            'status' => 'PENDING',
            'record_version' => 1,
        ]);
    }

    private function createPendingApproval(
        string $qualityActor = self::OPERATIONS_USER_ID,
        string $plantId = self::TRAINING_PLANT_ID,
        array $quantities = [],
    ): array {
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

        $service = $this->app->make(UnsoldSalesReturnService::class);
        $scope = ['company_id' => self::COMPANY_ID, 'plant_id' => $plantId];
        $case = $service->createRequest($scope + [
            'party_id' => '00000000-0000-4000-8000-000000000501',
            'shipment_id' => $shipmentId,
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
        $lineId = DB::table('unsold_return_lines')->where('return_case_id', $caseId)->value('id');

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
            'actor_id' => $qualityActor,
            'expected_version' => 2,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'line_id' => $lineId,
                'restock_quantity' => $quantities['restock_quantity'] ?? '0',
                'repack_quantity' => $quantities['repack_quantity'] ?? '2',
                'rework_quantity' => $quantities['rework_quantity'] ?? '0',
                'destroy_quantity' => $quantities['destroy_quantity'] ?? '8',
                'quality_reason_code' => 'SHORT_SHELF_LIFE',
            ]],
        ]);

        return [$disposition['approval_request_id'], $caseId, $lineId];
    }

    private function signIn(string $userId, string $plantId): void
    {
        $this->actingAs(User::query()->findOrFail($userId))->withSession([
            'erp.company_id' => self::COMPANY_ID,
            'erp.plant_id' => $plantId,
        ]);
    }
}

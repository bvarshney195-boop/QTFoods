<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class InventoryOperationsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const FINANCE_PLANT_ID = '00000000-0000-4000-8000-000000000102';
    private const OPERATIONS_ID = '00000000-0000-4000-8000-000000000202';
    private const SALES_ID = '00000000-0000-4000-8000-000000000201';
    private const ADMIN_ID = '00000000-0000-4000-8000-000000000204';
    private const AVAILABLE = '00000000-0000-4000-8000-000000001213';
    private const BLOCKED = '00000000-0000-4000-8000-000000001214';
    private const PARTY_OWNED = '00000000-0000-4000-8000-000000001215';
    private const RETURN_TARGET = '00000000-0000-4000-8000-000000001216';
    private const TRANSFER_TARGET = '00000000-0000-4000-8000-000000001217';
    private const EXPIRED_SOURCE = '00000000-0000-4000-8000-000000001218';
    private const EXPIRED_TARGET = '00000000-0000-4000-8000-000000001219';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->signIn(self::OPERATIONS_ID);
    }

    public function test_operation_workspaces_are_live_scoped_and_permission_aware(): void
    {
        $this->getJson('/api/v1/inventory/issues')
            ->assertOk()
            ->assertJsonPath('summary.total', 0)
            ->assertJsonPath('lookups.operation_types.0', 'ISSUE')
            ->assertJsonPath('lookups.operation_types.1', 'RETURN')
            ->assertJsonPath('allowed_actions.0', 'CREATE')
            ->assertJsonCount(16, 'lookups.positions');
        $this->getJson('/api/v1/inventory/transfers')->assertOk()
            ->assertJsonPath('lookups.operation_types.0', 'TRANSFER');
        $this->getJson('/api/v1/inventory/counts')->assertOk()
            ->assertJsonPath('lookups.operation_types.1', 'ADJUSTMENT');
        $this->getJson('/api/v1/inventory/expiry-disposals')->assertOk()
            ->assertJsonPath('lookups.operation_types.0', 'EXPIRY');

        $this->signIn(self::SALES_ID);
        $this->getJson('/api/v1/inventory/issues')->assertForbidden();
        $this->command()->postJson('/api/v1/inventory/issues', $this->issuePayload('ISS-FORBIDDEN'))
            ->assertForbidden();

        $this->signIn(self::ADMIN_ID, self::FINANCE_PLANT_ID);
        $this->getJson('/api/v1/inventory/issues')->assertOk()
            ->assertJsonPath('summary.total', 0)
            ->assertJsonFragment(['id' => '00000000-0000-4000-8000-000000003021'])
            ->assertJsonMissing(['id' => '00000000-0000-4000-8000-000000002602']);
    }

    public function test_issue_and_return_post_idempotent_ledger_movements_and_history(): void
    {
        $key = (string) Str::uuid();
        $created = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/inventory/issues', $this->issuePayload('ISS-TEST-001', '5'))
            ->assertCreated()->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.record_version', 1);
        $issueId = $created->json('data.id');
        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/inventory/issues', $this->issuePayload('ISS-TEST-001', '5'))
            ->assertCreated()->assertExactJson($created->json());

        $this->getJson('/api/v1/inventory/issues/'.$issueId)
            ->assertOk()->assertJsonPath('data.lines.0.quantity_base', '5.000000')
            ->assertJsonPath('data.lines.0.source_position.sku.code', 'SKU-APPLE-BASE')
            ->assertJsonPath('data.allowed_actions.1', 'POST');

        $updated = $this->withHeaders($this->headers(1))->postJson('/api/v1/inventory/issues/'.$issueId, [
            'reason_code' => 'PRODUCTION_ISSUE',
            'notes' => 'Reduced after staging verification.',
            'lines' => [[
                'source_position_id' => self::AVAILABLE,
                'quantity_base' => '4',
            ]],
        ])->assertOk()->assertJsonPath('data.record_version', 2);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/inventory/issues/'.$issueId, [
            'reason_code' => 'PRODUCTION_ISSUE', 'notes' => null,
            'lines' => [['source_position_id' => self::AVAILABLE, 'quantity_base' => '3']],
        ])->assertConflict()->assertJsonPath('error.code', 'CONFLICT');

        $postKey = (string) Str::uuid();
        $posted = $this->withHeaders(['Idempotency-Key' => $postKey, 'If-Match' => '2'])
            ->postJson('/api/v1/inventory/issues/'.$issueId.'/post')
            ->assertOk()->assertJsonPath('data.status', 'POSTED')
            ->assertJsonCount(1, 'data.movement_ids');
        $this->withHeaders(['Idempotency-Key' => $postKey, 'If-Match' => '2'])
            ->postJson('/api/v1/inventory/issues/'.$issueId.'/post')
            ->assertOk()->assertExactJson($posted->json());
        $this->assertDatabaseHas('stock_positions', ['id' => self::AVAILABLE, 'quantity_base' => 121]);

        $return = $this->create('issues', [
            'operation_number' => 'RET-TEST-001',
            'operation_type' => 'RETURN',
            'reason_code' => 'PRODUCTION_RETURN',
            'notes' => 'Unused material returned under quarantine.',
            'lines' => [['target_position_id' => self::RETURN_TARGET, 'quantity_base' => '3']],
        ]);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/inventory/issues/'.$return.'/post')
            ->assertOk()->assertJsonPath('data.status', 'POSTED');
        $this->assertDatabaseHas('stock_positions', ['id' => self::RETURN_TARGET, 'quantity_base' => 3]);

        $history = $this->getJson('/api/v1/inventory/movements')->assertOk()
            ->assertJsonPath('summary.total', 2)
            ->assertJsonPath('summary.outbound', 1)
            ->assertJsonPath('summary.inbound', 1)
            ->assertJsonPath('summary.transfer', 0);
        $movementId = $history->json('data.0.id');
        $this->getJson('/api/v1/inventory/movements/'.$movementId)
            ->assertOk()->assertJsonPath('data.source.type', 'INVENTORY_OPERATION');
        $this->assertDatabaseHas('audit_events', [
            'command' => 'POST_INVENTORY_OPERATION', 'entity_id' => $issueId, 'entity_version' => 3,
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'inventory.operation.posted', 'aggregate_id' => $issueId,
        ]);
        $this->assertSame(2, DB::table('stock_movements')->count());
    }

    public function test_transfer_posts_only_between_matching_controlled_coordinates(): void
    {
        $id = $this->create('transfers', [
            'operation_number' => 'TRF-TEST-001',
            'operation_type' => 'TRANSFER',
            'reason_code' => 'LINE_REPLENISHMENT',
            'notes' => null,
            'lines' => [[
                'source_position_id' => self::AVAILABLE,
                'target_position_id' => self::TRANSFER_TARGET,
                'quantity_base' => '10',
            ]],
        ]);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/inventory/transfers/'.$id.'/post')
            ->assertOk()->assertJsonPath('data.status', 'POSTED');
        $this->assertDatabaseHas('stock_positions', ['id' => self::AVAILABLE, 'quantity_base' => 115]);
        $this->assertDatabaseHas('stock_positions', ['id' => self::TRANSFER_TARGET, 'quantity_base' => 10]);
        $this->assertDatabaseHas('stock_movements', [
            'source_id' => $id,
            'movement_type' => 'INVENTORY_TRANSFER',
            'from_position_id' => self::AVAILABLE,
            'to_position_id' => self::TRANSFER_TARGET,
        ]);

        $badTransfer = $this->command()->postJson('/api/v1/inventory/transfers', [
            'operation_number' => 'TRF-BAD-OWNER',
            'operation_type' => 'TRANSFER',
            'reason_code' => 'BAD_COORDINATE',
            'notes' => null,
            'lines' => [[
                'source_position_id' => self::AVAILABLE,
                'target_position_id' => self::PARTY_OWNED,
                'quantity_base' => '1',
            ]],
        ])->assertUnprocessable();
        $this->assertSame(
            'Source and target must use the same SKU, lot, inventory owner, and UOM.',
            $badTransfer->json('error.fields')['lines.0.target_position_id'][0],
        );
    }

    public function test_counts_detect_stale_snapshots_and_adjustments_post_signed_variances(): void
    {
        $countId = $this->create('counts', [
            'operation_number' => 'CNT-TEST-001',
            'operation_type' => 'COUNT',
            'reason_code' => 'CYCLE_COUNT',
            'notes' => 'Quality-hold bin count.',
            'lines' => [[
                'source_position_id' => self::BLOCKED,
                'counted_quantity_base' => '12',
            ]],
        ]);
        $this->getJson('/api/v1/inventory/counts/'.$countId)->assertOk()
            ->assertJsonPath('data.lines.0.system_quantity_base', '15.000000')
            ->assertJsonPath('data.lines.0.variance_quantity_base', '-3.000000');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/inventory/counts/'.$countId.'/post')
            ->assertOk()->assertJsonPath('data.status', 'POSTED');
        $this->assertDatabaseHas('stock_positions', ['id' => self::BLOCKED, 'quantity_base' => 12]);
        $this->assertDatabaseHas('stock_movements', [
            'source_id' => $countId, 'movement_type' => 'INVENTORY_COUNT_LOSS', 'quantity_base' => 3,
        ]);

        $staleId = $this->create('counts', [
            'operation_number' => 'CNT-STALE-001',
            'operation_type' => 'COUNT',
            'reason_code' => 'CYCLE_COUNT',
            'notes' => null,
            'lines' => [['source_position_id' => self::AVAILABLE, 'counted_quantity_base' => '125']],
        ]);
        DB::table('stock_positions')->where('id', self::AVAILABLE)->increment('record_version');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/inventory/counts/'.$staleId.'/post')
            ->assertConflict()->assertJsonPath('error.code', 'CONFLICT');

        $adjustmentId = $this->create('counts', [
            'operation_number' => 'ADJ-TEST-001',
            'operation_type' => 'ADJUSTMENT',
            'reason_code' => 'SCALE_CORRECTION',
            'notes' => 'Approved scale correction.',
            'lines' => [[
                'source_position_id' => self::BLOCKED,
                'quantity_base' => '2',
                'adjustment_direction' => 'INCREASE',
            ]],
        ]);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/inventory/counts/'.$adjustmentId.'/post')
            ->assertOk();
        $this->assertDatabaseHas('stock_positions', ['id' => self::BLOCKED, 'quantity_base' => 14]);
        $this->assertDatabaseHas('stock_movements', [
            'source_id' => $adjustmentId, 'movement_type' => 'INVENTORY_ADJUSTMENT_GAIN', 'quantity_base' => 2,
        ]);
    }

    public function test_expiry_and_disposal_enforce_blocked_stock_controls(): void
    {
        $partial = $this->command()->postJson('/api/v1/inventory/expiry-disposals', [
            'operation_number' => 'EXP-PARTIAL-BAD',
            'operation_type' => 'EXPIRY',
            'reason_code' => 'SHELF_LIFE',
            'notes' => null,
            'lines' => [[
                'source_position_id' => self::EXPIRED_SOURCE,
                'target_position_id' => self::EXPIRED_TARGET,
                'quantity_base' => '7',
            ]],
        ])->assertUnprocessable();
        $this->assertSame(
            'Expiry must move the full quantity of the source position.',
            $partial->json('error.fields')['lines.0.quantity_base'][0],
        );

        $expiryId = $this->create('expiry-disposals', [
            'operation_number' => 'EXP-TEST-001',
            'operation_type' => 'EXPIRY',
            'reason_code' => 'SHELF_LIFE',
            'notes' => 'Daily expiry review.',
            'lines' => [[
                'source_position_id' => self::EXPIRED_SOURCE,
                'target_position_id' => self::EXPIRED_TARGET,
                'quantity_base' => '8',
            ]],
        ]);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/inventory/expiry-disposals/'.$expiryId.'/post')
            ->assertOk();
        $this->assertDatabaseHas('stock_positions', ['id' => self::EXPIRED_SOURCE, 'quantity_base' => 0]);
        $this->assertDatabaseHas('stock_positions', ['id' => self::EXPIRED_TARGET, 'quantity_base' => 8]);

        $disposalId = $this->create('expiry-disposals', [
            'operation_number' => 'DSP-TEST-001',
            'operation_type' => 'DISPOSAL',
            'reason_code' => 'APPROVED_DESTRUCTION',
            'notes' => 'Witnessed destruction lot.',
            'lines' => [['source_position_id' => self::EXPIRED_TARGET, 'quantity_base' => '3']],
        ]);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/inventory/expiry-disposals/'.$disposalId.'/post')
            ->assertOk();
        $this->assertDatabaseHas('stock_positions', ['id' => self::EXPIRED_TARGET, 'quantity_base' => 5]);
        $this->assertDatabaseHas('stock_movements', [
            'source_id' => $disposalId, 'movement_type' => 'INVENTORY_DISPOSAL', 'quantity_base' => 3,
        ]);

        $invalidDisposal = $this->command()->postJson('/api/v1/inventory/expiry-disposals', [
            'operation_number' => 'DSP-AVAILABLE-BAD',
            'operation_type' => 'DISPOSAL',
            'reason_code' => 'INVALID',
            'notes' => null,
            'lines' => [['source_position_id' => self::AVAILABLE, 'quantity_base' => '1']],
        ])->assertUnprocessable();
        $this->assertSame(
            'Only blocked, inactive-lot, or expired stock can be disposed.',
            $invalidDisposal->json('error.fields')['lines.0.source_position_id'][0],
        );
    }

    public function test_drafts_cancel_with_versions_and_cannot_cross_resource_boundaries(): void
    {
        $id = $this->create('issues', $this->issuePayload('ISS-CANCEL-001'));
        $this->getJson('/api/v1/inventory/transfers/'.$id)->assertNotFound();
        $this->withHeaders($this->headers(1))->postJson('/api/v1/inventory/issues/'.$id.'/cancel', [
            'reason' => 'Production order was cancelled.',
        ])->assertOk()->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.record_version', 2);
        $this->withHeaders($this->headers(2))->postJson('/api/v1/inventory/issues/'.$id.'/post')
            ->assertUnprocessable()->assertJsonPath(
                'error.fields.status.0', 'Only a draft inventory operation can be posted.',
            );

        $permission = DB::table('permissions')->where('code', 'ACTION:INV-ISS:POST')->value('id');
        $role = DB::table('roles')->where('code', 'OPERATIONS_MANAGER')->value('id');
        DB::table('role_permissions')->where('role_id', $role)->where('permission_id', $permission)->delete();
        $draft = $this->create('issues', $this->issuePayload('ISS-NO-POST-001'));
        $this->withHeaders($this->headers(1))->postJson('/api/v1/inventory/issues/'.$draft.'/post')
            ->assertForbidden();
    }

    private function issuePayload(string $number, string $quantity = '2'): array
    {
        return [
            'operation_number' => $number,
            'operation_type' => 'ISSUE',
            'reason_code' => 'PRODUCTION_ISSUE',
            'notes' => 'Issue against an authorised production requirement.',
            'lines' => [['source_position_id' => self::AVAILABLE, 'quantity_base' => $quantity]],
        ];
    }

    private function create(string $resource, array $payload): string
    {
        return (string) $this->command()->postJson('/api/v1/inventory/'.$resource, $payload)
            ->assertCreated()->json('data.id');
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
}

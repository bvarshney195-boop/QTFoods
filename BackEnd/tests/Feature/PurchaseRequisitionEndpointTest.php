<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class PurchaseRequisitionEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const FINANCE_PLANT_ID = '00000000-0000-4000-8000-000000000102';
    private const OPERATIONS_ID = '00000000-0000-4000-8000-000000000202';
    private const FINANCE_ID = '00000000-0000-4000-8000-000000000203';
    private const SALES_ID = '00000000-0000-4000-8000-000000000201';
    private const ADMIN_ID = '00000000-0000-4000-8000-000000000204';
    private const RAW_ITEM_ID = '00000000-0000-4000-8000-000000000603';
    private const FINISHED_ITEM_ID = '00000000-0000-4000-8000-000000000601';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->signIn(self::OPERATIONS_ID);
    }

    public function test_workspace_is_live_scoped_and_permission_aware(): void
    {
        $this->getJson('/api/v1/procurement/requisitions')
            ->assertOk()
            ->assertJsonPath('summary.total', 0)
            ->assertJsonPath('summary.estimated_total', '0.000000')
            ->assertJsonCount(3, 'lookups.items')
            ->assertJsonPath('lookups.statuses.0', 'DRAFT')
            ->assertJsonPath('lookups.currencies', ['INR'])
            ->assertJsonPath('allowed_actions.0', 'CREATE')
            ->assertJsonCount(0, 'approvals');

        $this->signIn(self::FINANCE_ID);
        $this->getJson('/api/v1/procurement/requisitions')
            ->assertOk()->assertJsonCount(0, 'allowed_actions');

        $this->signIn(self::SALES_ID);
        $this->getJson('/api/v1/procurement/requisitions')->assertForbidden();
        $this->command()->postJson('/api/v1/procurement/requisitions', $this->payload('REQ-FORBIDDEN'))
            ->assertForbidden();

        $this->signIn(self::ADMIN_ID, self::FINANCE_PLANT_ID);
        $this->getJson('/api/v1/procurement/requisitions')
            ->assertOk()->assertJsonPath('summary.total', 0)->assertJsonCount(3, 'lookups.items');
    }

    public function test_draft_create_update_and_reads_are_idempotent_versioned_and_relational(): void
    {
        $foreignCurrency = $this->payload('REQ-USD-001');
        $foreignCurrency['currency'] = 'USD';
        $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/v1/procurement/requisitions', $foreignCurrency)
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.currency.0', 'The selected currency is invalid.');

        $payload = $this->payload('REQ-TEST-001');
        $payload['lines'][0]['quantity'] = '10.000001';
        $payload['lines'][0]['estimated_unit_cost'] = '50.000001';
        $key = (string) Str::uuid();
        $created = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/procurement/requisitions', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.record_version', 1)
            ->assertJsonPath('data.line_count', 2)
            ->assertJsonPath('data.estimated_total', '650.000060');
        $id = (string) $created->json('data.id');
        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/procurement/requisitions', $payload)
            ->assertCreated()->assertExactJson($created->json());

        $this->getJson('/api/v1/procurement/requisitions/'.$id)
            ->assertOk()
            ->assertJsonPath('data.requisition_number', 'REQ-TEST-001')
            ->assertJsonPath('data.lines.0.item.code', 'SKU-APPLE-BASE')
            ->assertJsonPath('data.lines.0.uom_code', 'KG')
            ->assertJsonPath('data.lines.0.estimated_line_total', '500.000060')
            ->assertJsonPath('data.allowed_actions.0', 'UPDATE')
            ->assertJsonPath('data.allowed_actions.1', 'SUBMIT');

        $update = $this->payload('IGNORED');
        unset($update['requisition_number']);
        $update['purpose'] = 'Adjusted requirement after production review.';
        $update['lines'][0]['quantity'] = '12';
        $this->withHeaders($this->headers(1))
            ->postJson('/api/v1/procurement/requisitions/'.$id, $update)
            ->assertOk()->assertJsonPath('data.record_version', 2)
            ->assertJsonPath('data.estimated_total', '750.000000');
        $this->withHeaders($this->headers(1))
            ->postJson('/api/v1/procurement/requisitions/'.$id, $update)
            ->assertConflict()->assertJsonPath('error.code', 'CONFLICT');

        $this->assertDatabaseHas('requisitions', [
            'id' => $id, 'company_id' => self::COMPANY_ID, 'plant_id' => self::PLANT_ID,
            'estimated_total' => 750, 'record_version' => 2,
        ]);
        $this->assertDatabaseCount('requisition_lines', 2);
        $this->assertDatabaseHas('audit_events', [
            'command' => 'UPDATE_PURCHASE_REQUISITION', 'entity_id' => $id, 'entity_version' => 2,
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'procurement.requisition.updated', 'aggregate_id' => $id,
        ]);

        $this->command()->postJson('/api/v1/procurement/requisitions', $this->payload('REQ-TEST-001'))
            ->assertUnprocessable()->assertJsonPath(
                'error.fields.requisition_number.0',
                'That requisition number already exists in the selected plant.',
            );
        $invalid = $this->payload('REQ-BAD-ITEM');
        $invalid['lines'][0]['item_id'] = (string) Str::uuid();
        $invalidResponse = $this->command()
            ->postJson('/api/v1/procurement/requisitions', $invalid)
            ->assertUnprocessable();
        $this->assertSame(
            'Select an active item from the current company.',
            $invalidResponse->json('error.fields')['lines.0.item_id'][0],
        );
    }

    public function test_submission_routes_a_work_item_and_independent_reviewer_approval(): void
    {
        $id = $this->create('REQ-APPROVE-001');
        $submitKey = (string) Str::uuid();
        $submitted = $this->withHeaders(['Idempotency-Key' => $submitKey, 'If-Match' => '1'])
            ->postJson("/api/v1/procurement/requisitions/{$id}/submit")
            ->assertOk()->assertJsonPath('data.status', 'SUBMITTED')
            ->assertJsonPath('data.record_version', 2);
        $approvalId = (string) $submitted->json('data.approval_request_id');
        $this->withHeaders(['Idempotency-Key' => $submitKey, 'If-Match' => '1'])
            ->postJson("/api/v1/procurement/requisitions/{$id}/submit")
            ->assertOk()->assertExactJson($submitted->json());

        $this->assertDatabaseHas('approval_requests', [
            'id' => $approvalId,
            'entity_type' => 'purchase_requisition',
            'entity_id' => $id,
            'entity_version' => 2,
            'status' => 'PENDING',
            'required_permission' => 'ACTION:PUR-REQ:APPROVE',
        ]);
        $this->assertDatabaseHas('work_items', [
            'source_type' => 'approval_request', 'source_id' => $approvalId,
            'target_screen_code' => 'PUR-REQ', 'status' => 'OPEN',
        ]);

        $this->withHeaders($this->headers(1))
            ->postJson("/api/v1/procurement/requisition-approvals/{$approvalId}/approve")
            ->assertUnprocessable()->assertJsonPath(
                'error.fields.approval.0',
                'Maker cannot approve or reject the same purchase requisition.',
            );

        $this->signIn(self::FINANCE_ID);
        $this->getJson('/api/v1/procurement/requisitions')
            ->assertOk()->assertJsonCount(1, 'approvals')
            ->assertJsonPath('approvals.0.approval.allowed_actions.0', 'APPROVE')
            ->assertJsonPath('approvals.0.requisition_number', 'REQ-APPROVE-001');
        $decisionKey = (string) Str::uuid();
        $approved = $this->withHeaders(['Idempotency-Key' => $decisionKey, 'If-Match' => '1'])
            ->postJson("/api/v1/procurement/requisition-approvals/{$approvalId}/approve", [
                'reason' => 'Budget and requirement verified.',
            ])->assertOk()->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.record_version', 3)
            ->assertJsonPath('data.authority_source', 'DIRECT');
        $this->withHeaders(['Idempotency-Key' => $decisionKey, 'If-Match' => '1'])
            ->postJson("/api/v1/procurement/requisition-approvals/{$approvalId}/approve", [
                'reason' => 'Budget and requirement verified.',
            ])->assertOk()->assertExactJson($approved->json());

        $this->assertDatabaseHas('requisitions', [
            'id' => $id, 'status' => 'APPROVED', 'approved_by' => self::FINANCE_ID, 'record_version' => 3,
        ]);
        $this->assertDatabaseHas('approval_decisions', [
            'approval_request_id' => $approvalId, 'reviewer_id' => self::FINANCE_ID,
            'decision' => 'APPROVE', 'authority_source' => 'DIRECT',
        ]);
        $this->assertDatabaseHas('work_items', [
            'source_id' => $approvalId, 'status' => 'COMPLETED', 'completed_by' => self::FINANCE_ID,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'command' => 'APPROVE_PURCHASE_REQUISITION', 'entity_id' => $id, 'entity_version' => 3,
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'procurement.requisition.approved', 'aggregate_id' => $id,
        ]);
    }

    public function test_rejection_supports_corrected_resubmission_with_immutable_lineage(): void
    {
        $id = $this->create('REQ-RESUBMIT-001');
        $firstApproval = $this->submit($id, 1);

        $this->signIn(self::FINANCE_ID);
        $this->withHeaders($this->headers(1))
            ->postJson("/api/v1/procurement/requisition-approvals/{$firstApproval}/reject", [
                'reason' => 'Required date and estimated quantity need correction.',
            ])->assertOk()->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.record_version', 3);

        $this->signIn(self::OPERATIONS_ID);
        $update = $this->payload('IGNORED');
        unset($update['requisition_number']);
        $update['required_by_date'] = '2026-10-15';
        $update['lines'][0]['quantity'] = '8';
        $this->withHeaders($this->headers(3))
            ->postJson("/api/v1/procurement/requisitions/{$id}", $update)
            ->assertOk()->assertJsonPath('data.status', 'REJECTED')
            ->assertJsonPath('data.record_version', 4);
        $secondApproval = $this->submit($id, 4);
        $this->assertNotSame($firstApproval, $secondApproval);
        $this->assertDatabaseHas('approval_requests', [
            'id' => $secondApproval,
            'resubmission_of_id' => $firstApproval,
            'submission_number' => 2,
            'entity_version' => 5,
        ]);
        $this->getJson("/api/v1/procurement/requisitions/{$id}")
            ->assertOk()->assertJsonPath('data.status', 'SUBMITTED')
            ->assertJsonPath('data.rejection_reason', null)
            ->assertJsonPath('data.approval.submission_number', 2);
    }

    public function test_high_value_approval_requires_the_resolved_authority_band(): void
    {
        $payload = $this->payload('REQ-HIGH-001');
        $payload['lines'] = [[
            'item_id' => self::RAW_ITEM_ID,
            'quantity' => '1',
            'estimated_unit_cost' => '150000',
            'notes' => 'Annual contracted ingredient volume.',
        ]];
        $id = $this->createFrom($payload);
        $approvalId = $this->submit($id, 1);
        $this->assertDatabaseHas('approval_requests', [
            'id' => $approvalId,
            'required_permission' => 'ACTION:PUR-REQ:APPROVE-HIGH',
            'authority_value' => 150000,
        ]);

        $this->signIn(self::FINANCE_ID);
        $this->getJson('/api/v1/procurement/requisitions')->assertOk()->assertJsonCount(0, 'approvals');
        $this->withHeaders($this->headers(1))
            ->postJson("/api/v1/procurement/requisition-approvals/{$approvalId}/approve")
            ->assertUnprocessable()->assertJsonPath(
                'error.fields.approval.0',
                'This approval requires ACTION:PUR-REQ:APPROVE-HIGH authority in the selected plant.',
            );

        $this->signIn(self::ADMIN_ID);
        $this->withHeaders($this->headers(1))
            ->postJson("/api/v1/procurement/requisition-approvals/{$approvalId}/approve", [
                'reason' => 'High-value authority verified the annual requirement.',
            ])->assertOk()->assertJsonPath('data.status', 'APPROVED')
            ->assertJsonPath('data.authority_source', 'DIRECT');
    }

    public function test_repeated_seeding_preserves_live_policy_and_pending_approval(): void
    {
        $id = $this->create('REQ-RESEED-001');
        $approvalId = $this->submit($id, 1);
        $approval = DB::table('approval_requests')->where('id', $approvalId)->firstOrFail();
        DB::table('approval_rules')->where('id', $approval->approval_rule_id)->update([
            'name' => 'Versioned live requisition policy',
            'record_version' => 7,
        ]);

        $this->seed();
        $this->seed();

        $this->assertDatabaseHas('requisitions', [
            'id' => $id, 'status' => 'SUBMITTED', 'approval_request_id' => $approvalId,
        ]);
        $this->assertDatabaseHas('approval_requests', [
            'id' => $approvalId, 'status' => 'PENDING',
            'approval_rule_id' => $approval->approval_rule_id,
            'approval_rule_band_id' => $approval->approval_rule_band_id,
        ]);
        $this->assertDatabaseHas('work_items', [
            'source_id' => $approvalId, 'status' => 'OPEN', 'target_screen_code' => 'PUR-REQ',
        ]);
        $this->assertDatabaseHas('approval_rules', [
            'id' => $approval->approval_rule_id,
            'name' => 'Versioned live requisition policy',
            'record_version' => 7,
        ]);
        $this->assertDatabaseHas('approval_rule_bands', [
            'id' => $approval->approval_rule_band_id,
            'approval_rule_id' => $approval->approval_rule_id,
        ]);
    }

    public function test_cancellation_state_scope_and_action_controls_are_enforced(): void
    {
        $draftId = $this->create('REQ-CANCEL-001');
        $this->withHeaders($this->headers(1))
            ->postJson("/api/v1/procurement/requisitions/{$draftId}/cancel", [
                'reason' => 'Production plan no longer requires these materials.',
            ])->assertOk()->assertJsonPath('data.status', 'CANCELLED')
            ->assertJsonPath('data.record_version', 2);
        $this->withHeaders($this->headers(2))
            ->postJson("/api/v1/procurement/requisitions/{$draftId}/submit")
            ->assertUnprocessable();

        $submittedId = $this->create('REQ-SUBMITTED-LOCKED');
        $this->submit($submittedId, 1);
        $this->withHeaders($this->headers(2))
            ->postJson("/api/v1/procurement/requisitions/{$submittedId}/cancel", [
                'reason' => 'Attempt to bypass the active reviewer.',
            ])->assertUnprocessable()->assertJsonPath(
                'error.fields.status.0',
                'Only a draft, rejected, or approved requisition can be cancelled.',
            );

        $this->signIn(self::ADMIN_ID, self::FINANCE_PLANT_ID);
        $this->getJson("/api/v1/procurement/requisitions/{$submittedId}")->assertNotFound();

        $this->signIn(self::OPERATIONS_ID);
        $permissionId = DB::table('permissions')->where('code', 'ACTION:PUR-REQ:SUBMIT')->value('id');
        $roleId = DB::table('roles')->where('code', 'OPERATIONS_MANAGER')->value('id');
        DB::table('role_permissions')->where('role_id', $roleId)->where('permission_id', $permissionId)->delete();
        $locked = $this->create('REQ-NO-SUBMIT');
        $this->withHeaders($this->headers(1))
            ->postJson("/api/v1/procurement/requisitions/{$locked}/submit")
            ->assertForbidden();
    }

    private function payload(string $number): array
    {
        return [
            'requisition_number' => $number,
            'department' => 'Manufacturing',
            'purpose' => 'Replenish controlled materials for the October production plan.',
            'requested_date' => '2026-09-11',
            'required_by_date' => '2026-10-01',
            'currency' => 'INR',
            'lines' => [
                [
                    'item_id' => self::RAW_ITEM_ID,
                    'quantity' => '10',
                    'estimated_unit_cost' => '50',
                    'notes' => 'Use the approved ingredient specification.',
                ],
                [
                    'item_id' => self::FINISHED_ITEM_ID,
                    'quantity' => '5',
                    'estimated_unit_cost' => '30',
                    'notes' => null,
                ],
            ],
        ];
    }

    private function create(string $number): string
    {
        return $this->createFrom($this->payload($number));
    }

    private function createFrom(array $payload): string
    {
        return (string) $this->command()->postJson('/api/v1/procurement/requisitions', $payload)
            ->assertCreated()->json('data.id');
    }

    private function submit(string $id, int $version): string
    {
        return (string) $this->withHeaders($this->headers($version))
            ->postJson("/api/v1/procurement/requisitions/{$id}/submit")
            ->assertOk()->json('data.approval_request_id');
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

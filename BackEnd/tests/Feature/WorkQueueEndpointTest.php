<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use App\Modules\Work\Application\WorkItemService;
use App\Shared\Approval\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class WorkQueueEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const TRAINING_PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const FINANCE_PLANT_ID = '00000000-0000-4000-8000-000000000102';
    private const OPERATIONS_USER_ID = '00000000-0000-4000-8000-000000000202';
    private const FINANCE_USER_ID = '00000000-0000-4000-8000-000000000203';
    private const ADMIN_USER_ID = '00000000-0000-4000-8000-000000000204';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $this->signIn(self::FINANCE_USER_ID, self::TRAINING_PLANT_ID);
    }

    public function test_queue_contains_scoped_authorised_approvals_tasks_and_exceptions_with_real_counters(): void
    {
        $approvalId = $this->app->make(ApprovalService::class)->request(
            'unsold_return_loss',
            (string) Str::uuid(),
            3,
            self::OPERATIONS_USER_ID,
            self::COMPANY_ID,
            self::TRAINING_PLANT_ID,
            'UNSOLD_RETURN_LOSS_APPROVAL',
        );
        $exceptionId = $this->recordWorkItem([
            'kind' => 'EXCEPTION',
            'title' => 'Resolve blocked quarantine stock',
            'description' => 'A return position is missing an outcome route.',
            'priority' => 'URGENT',
            'assigned_user_id' => self::FINANCE_USER_ID,
            'due_at' => now()->subHour(),
            'target_screen_code' => 'RET-UNSOLD',
            'target_record_id' => (string) Str::uuid(),
        ]);
        $this->recordWorkItem([
            'kind' => 'TASK',
            'title' => 'Other plant task',
            'plant_id' => self::FINANCE_PLANT_ID,
        ]);

        $this->travel(73)->hours();

        $response = $this->getJson('/api/v1/work/tasks?sort=priority')
            ->assertOk()
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('summary.open_total', 2)
            ->assertJsonPath('summary.assigned_to_me', 1)
            ->assertJsonPath('summary.approvals', 1)
            ->assertJsonPath('summary.exceptions', 1)
            ->assertJsonPath('summary.overdue', 2)
            ->assertJsonPath('summary.created_7d', 2)
            ->assertJsonPath('summary.completed_7d', 0)
            ->assertJsonPath('summary.closure_rate_7d', 0)
            ->assertJsonPath('summary.older_than_three_days', 2)
            ->assertJsonPath('data.0.id', $exceptionId)
            ->assertJsonPath('data.0.kind', 'EXCEPTION')
            ->assertJsonPath('data.0.is_overdue', true)
            ->assertJsonPath('data.0.assignee.name', 'Demo Finance Manager')
            ->assertJsonPath('data.1.kind', 'APPROVAL')
            ->assertJsonPath('data.1.source.id', $approvalId)
            ->assertJsonPath('data.1.target.screen_code', 'RET-UNSOLD')
            ->assertJsonPath('data.1.age_bucket', 'OVER_THREE_DAYS');

        $this->assertStringStartsWith(
            'RET-UNSOLD?record=',
            $response->json('data.1.target.href')
        );

        $this->signIn(self::OPERATIONS_USER_ID, self::TRAINING_PLANT_ID);
        $this->getJson('/api/v1/work/tasks')
            ->assertOk()
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('summary.approvals', 0);
        $approvalWorkItemId = DB::table('work_items')
            ->where('source_id', $approvalId)
            ->value('id');
        $this->withHeaders([
            'If-Match' => '1',
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson("/api/v1/work/tasks/{$approvalWorkItemId}/claim")
            ->assertNotFound();
    }

    public function test_claim_is_versioned_idempotent_audited_and_updates_ownership(): void
    {
        $workItemId = $this->recordWorkItem([
            'kind' => 'TASK',
            'title' => 'Check settlement reference',
        ]);

        $this->postJson("/api/v1/work/tasks/{$workItemId}/claim")
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.fields.if_match.0',
                'The If-Match header is required for this work-item command.'
            );

        $headers = [
            'If-Match' => '1',
            'Idempotency-Key' => (string) Str::uuid(),
        ];
        $first = $this->withHeaders($headers)
            ->postJson("/api/v1/work/tasks/{$workItemId}/claim")
            ->assertOk()
            ->assertJsonPath('data.assigned_user_id', self::FINANCE_USER_ID)
            ->assertJsonPath('data.record_version', 2);

        $this->withHeaders($headers)
            ->postJson("/api/v1/work/tasks/{$workItemId}/claim")
            ->assertOk()
            ->assertExactJson($first->json());

        $this->assertDatabaseHas('work_items', [
            'id' => $workItemId,
            'assigned_user_id' => self::FINANCE_USER_ID,
            'record_version' => 2,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'command' => 'CLAIM_WORK_ITEM',
            'entity_id' => $workItemId,
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'work.item.claimed',
            'aggregate_id' => $workItemId,
        ]);
        $this->assertDatabaseCount('idempotency_keys', 1);

        $this->withHeaders([
            'If-Match' => '1',
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson("/api/v1/work/tasks/{$workItemId}/claim")
            ->assertConflict()
            ->assertJsonPath('error.code', 'CONFLICT');

        $this->getJson('/api/v1/work/tasks?assignment=MINE')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.allowed_actions.0', 'COMPLETE');
    }

    public function test_assignee_can_complete_regular_work_but_not_an_approval(): void
    {
        $taskId = $this->recordWorkItem([
            'kind' => 'TASK',
            'title' => 'Verify distributor response',
            'assigned_user_id' => self::FINANCE_USER_ID,
        ]);

        $headers = [
            'If-Match' => '1',
            'Idempotency-Key' => (string) Str::uuid(),
        ];
        $first = $this->withHeaders($headers)
            ->postJson("/api/v1/work/tasks/{$taskId}/complete", [
                'completion_note' => 'Distributor confirmation attached.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.completed_by', self::FINANCE_USER_ID)
            ->assertJsonPath('data.record_version', 2);

        $this->withHeaders($headers)
            ->postJson("/api/v1/work/tasks/{$taskId}/complete", [
                'completion_note' => 'Distributor confirmation attached.',
            ])
            ->assertOk()
            ->assertExactJson($first->json());

        $this->getJson('/api/v1/work/tasks')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/work/tasks?status=COMPLETED')
            ->assertOk()
            ->assertJsonPath('data.0.id', $taskId)
            ->assertJsonPath('data.0.completion_note', 'Distributor confirmation attached.');
        $this->assertDatabaseHas('audit_events', [
            'command' => 'COMPLETE_WORK_ITEM',
            'entity_id' => $taskId,
        ]);

        $approvalId = $this->app->make(ApprovalService::class)->request(
            'unsold_return_loss',
            (string) Str::uuid(),
            1,
            self::OPERATIONS_USER_ID,
            self::COMPANY_ID,
            self::TRAINING_PLANT_ID,
            'UNSOLD_RETURN_LOSS_APPROVAL',
        );
        $approvalWorkItemId = DB::table('work_items')
            ->where('source_id', $approvalId)
            ->value('id');

        $this->withHeaders([
            'If-Match' => '1',
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson("/api/v1/work/tasks/{$approvalWorkItemId}/complete")
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.fields.work_item.0',
                'Approval work must be completed through its approval decision.'
            );
    }

    public function test_approval_decision_closes_its_projected_work_item(): void
    {
        $approvalId = $this->app->make(ApprovalService::class)->request(
            'unsold_return_loss',
            (string) Str::uuid(),
            1,
            self::OPERATIONS_USER_ID,
            self::COMPANY_ID,
            self::TRAINING_PLANT_ID,
            'UNSOLD_RETURN_LOSS_APPROVAL',
        );

        $this->app->make(ApprovalService::class)->decide(
            $approvalId,
            self::FINANCE_USER_ID,
            'APPROVE',
            'Reviewed.'
        );

        $this->assertDatabaseHas('work_items', [
            'source_type' => 'approval_request',
            'source_id' => $approvalId,
            'status' => 'COMPLETED',
            'completed_by' => self::FINANCE_USER_ID,
            'record_version' => 2,
        ]);
        $this->getJson('/api/v1/work/tasks')->assertJsonPath('meta.total', 0);
    }

    public function test_only_an_erp_manager_can_assign_work_to_another_scoped_user(): void
    {
        $workItemId = $this->recordWorkItem([
            'kind' => 'EXCEPTION',
            'title' => 'Resolve invoice mismatch',
        ]);

        $this->withHeaders([
            'If-Match' => '1',
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson("/api/v1/work/tasks/{$workItemId}/assign", [
            'assigned_user_id' => self::OPERATIONS_USER_ID,
        ])->assertForbidden();

        $this->signIn(self::ADMIN_USER_ID, self::TRAINING_PLANT_ID);
        $this->withHeaders([
            'If-Match' => '1',
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson("/api/v1/work/tasks/{$workItemId}/assign", [
            'assigned_user_id' => self::OPERATIONS_USER_ID,
        ])
            ->assertOk()
            ->assertJsonPath('data.assigned_user_id', self::OPERATIONS_USER_ID)
            ->assertJsonPath('data.record_version', 2);

        $this->assertDatabaseHas('audit_events', [
            'command' => 'ASSIGN_WORK_ITEM',
            'entity_id' => $workItemId,
        ]);

        $approvalId = $this->app->make(ApprovalService::class)->request(
            'unsold_return_loss',
            (string) Str::uuid(),
            1,
            self::OPERATIONS_USER_ID,
            self::COMPANY_ID,
            self::TRAINING_PLANT_ID,
            'UNSOLD_RETURN_LOSS_APPROVAL',
        );
        $approvalWorkItemId = DB::table('work_items')
            ->where('source_id', $approvalId)
            ->value('id');

        $this->withHeaders([
            'If-Match' => '1',
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson("/api/v1/work/tasks/{$approvalWorkItemId}/assign", [
            'assigned_user_id' => self::OPERATIONS_USER_ID,
        ])
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.fields.assigned_user_id.0',
                'Select an active user with the required permission in this company and plant.'
            );
    }

    private function recordWorkItem(array $overrides): string
    {
        return $this->app->make(WorkItemService::class)->record($overrides + [
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::TRAINING_PLANT_ID,
            'priority' => 'NORMAL',
            'created_by' => self::OPERATIONS_USER_ID,
        ]);
    }

    private function signIn(string $userId, string $plantId): void
    {
        $this->actingAs(User::query()->findOrFail($userId))->withSession([
            'erp.company_id' => self::COMPANY_ID,
            'erp.plant_id' => $plantId,
        ]);
    }
}

<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use App\Modules\Sales\Application\UnsoldSalesReturnService;
use App\Shared\Approval\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ApprovalGovernanceEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const SALES_USER_ID = '00000000-0000-4000-8000-000000000201';
    private const OPERATIONS_USER_ID = '00000000-0000-4000-8000-000000000202';
    private const FINANCE_USER_ID = '00000000-0000-4000-8000-000000000203';
    private const ADMIN_USER_ID = '00000000-0000-4000-8000-000000000204';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->signIn(self::ADMIN_USER_ID);
    }

    public function test_workspace_exposes_effective_policy_bands_delegations_and_lookups(): void
    {
        $response = $this->getJson('/api/v1/admin/approvals')
            ->assertOk()
            ->assertJsonPath('summary.rules', 3)
            ->assertJsonPath('summary.plant_overrides', 2)
            ->assertJsonPath('summary.pending_approvals', 0)
            ->assertJsonFragment(['code' => 'ACTION:RET-UNSOLD:APPROVE-ESCALATED'])
            ->assertJsonFragment(['code' => 'ACTION:PUR-REQ:APPROVE-HIGH'])
            ->assertJsonFragment(['email' => 'finance.user@qtfoods.local'])
            ->assertJsonPath('allowed_actions', ['CREATE_RULE', 'CREATE_DELEGATION', 'ESCALATE_DUE']);

        $unsoldRules = collect($response->json('data'))
            ->where('code', 'UNSOLD_RETURN_LOSS_APPROVAL')
            ->keyBy('scope');
        $this->assertSame('100', $unsoldRules['PLANT']['bands'][0]['maximum_value']);
        $this->assertSame('ACTION:RET-UNSOLD:APPROVE-HIGH', $unsoldRules['PLANT']['bands'][1]['required_permission']);
        $this->assertTrue($unsoldRules['PLANT']['is_effective']);
        $this->assertTrue($unsoldRules['GLOBAL']['is_system']);

        $purchaseRule = collect($response->json('data'))
            ->firstWhere('code', 'PURCHASE_REQUISITION_APPROVAL');
        $this->assertSame('PLANT', $purchaseRule['scope']);
        $this->assertTrue($purchaseRule['is_effective']);
    }

    public function test_rule_override_create_update_replay_and_version_conflict_are_controlled(): void
    {
        $ruleId = $this->localRuleId();
        DB::table('approval_rule_bands')->where('approval_rule_id', $ruleId)->delete();
        DB::table('approval_rules')->where('id', $ruleId)->delete();

        $key = (string) Str::uuid();
        $body = $this->ruleBody(50);
        $first = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/v1/admin/approval-rules', $body)
            ->assertCreated()
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.record_version', 1)
            ->assertJsonPath('data.band_count', 2);

        $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/v1/admin/approval-rules', $body)
            ->assertCreated()
            ->assertExactJson($first->json());
        $this->assertSame(3, DB::table('approval_rules')
            ->where('code', 'UNSOLD_RETURN_LOSS_APPROVAL')->count());
        $this->assertSame(1, DB::table('approval_rules')
            ->where('code', 'UNSOLD_RETURN_LOSS_APPROVAL')
            ->where('company_id', self::COMPANY_ID)
            ->where('plant_id', self::PLANT_ID)
            ->count());
        $createdId = $first->json('data.id');

        $updatedBody = $this->ruleBody(25);
        $updatedBody['name'] = 'Plant loss approval authority';
        $this->withHeaders(['If-Match' => '1', 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/admin/approval-rules/{$createdId}", Arr::except($updatedBody, ['code']))
            ->assertOk()
            ->assertJsonPath('data.record_version', 2);

        $this->withHeaders(['If-Match' => '1', 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/admin/approval-rules/{$createdId}", Arr::except($updatedBody, ['code']))
            ->assertConflict()
            ->assertJsonPath('error.code', 'CONFLICT');
        $this->assertDatabaseHas('audit_events', [
            'command' => 'UPDATE_APPROVAL_RULE',
            'entity_id' => $createdId,
            'entity_version' => 2,
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'approval.rule.updated',
            'aggregate_id' => $createdId,
        ]);

        $purchaseRuleId = DB::table('approval_rules')
            ->where('company_id', self::COMPANY_ID)
            ->where('plant_id', self::PLANT_ID)
            ->where('code', 'PURCHASE_REQUISITION_APPROVAL')
            ->value('id');
        DB::table('approval_rule_bands')->where('approval_rule_id', $purchaseRuleId)->delete();
        DB::table('approval_rules')->where('id', $purchaseRuleId)->delete();

        $purchaseRule = $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/admin/approval-rules', $this->purchaseRuleBody())
            ->assertCreated()
            ->assertJsonPath('data.status', 'ACTIVE')
            ->assertJsonPath('data.band_count', 2);
        $this->assertDatabaseHas('approval_rules', [
            'id' => $purchaseRule->json('data.id'),
            'code' => 'PURCHASE_REQUISITION_APPROVAL',
            'entity_type' => 'purchase_requisition',
            'authority_metric' => 'ESTIMATED_TOTAL',
            'authority_uom' => 'INR',
        ]);
    }

    public function test_rule_update_preserves_bands_referenced_by_completed_approvals(): void
    {
        $approvals = app(ApprovalService::class);
        $approvalId = $approvals->request(
            'purchase_requisition',
            (string) Str::uuid(),
            1,
            self::OPERATIONS_USER_ID,
            self::COMPANY_ID,
            self::PLANT_ID,
            'PURCHASE_REQUISITION_APPROVAL',
            ['authority_value' => '50000'],
        );
        $approvals->decide($approvalId, self::ADMIN_USER_ID, 'APPROVE');

        $snapshot = DB::table('approval_requests')->where('id', $approvalId)->firstOrFail();
        $ruleId = (string) $snapshot->approval_rule_id;
        $bandId = (string) $snapshot->approval_rule_band_id;

        $this->withHeaders(['If-Match' => '1', 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/admin/approval-rules/{$ruleId}", Arr::except($this->purchaseRuleBody(), ['code']))
            ->assertOk()
            ->assertJsonPath('data.record_version', 2)
            ->assertJsonPath('data.band_count', 2);

        $this->assertDatabaseHas('approval_rule_bands', [
            'id' => $bandId,
            'approval_rule_id' => $ruleId,
            'sequence' => 1,
        ]);
        $this->assertDatabaseHas('approval_requests', [
            'id' => $approvalId,
            'status' => 'APPROVED',
            'approval_rule_id' => $ruleId,
            'approval_rule_version' => 1,
            'approval_rule_band_id' => $bandId,
            'band_name_snapshot' => $snapshot->band_name_snapshot,
            'required_permission' => $snapshot->required_permission,
        ]);
        $this->assertDatabaseHas('audit_events', [
            'command' => 'UPDATE_APPROVAL_RULE',
            'entity_id' => $ruleId,
            'entity_version' => 2,
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'approval.rule.updated',
            'aggregate_id' => $ruleId,
        ]);
        $this->getJson("/api/v1/admin/approval-rules/{$ruleId}")
            ->assertOk()
            ->assertJsonPath('data.record_version', 2)
            ->assertJsonPath('data.pending_request_count', 0)
            ->assertJsonCount(2, 'data.bands');
    }

    public function test_authority_band_is_snapshotted_and_enforced_for_the_decision(): void
    {
        $this->updateLocalRule($this->ruleBody(5));
        [$approvalId] = $this->createPendingApproval(self::OPERATIONS_USER_ID, '8');

        $this->assertDatabaseHas('approval_requests', [
            'id' => $approvalId,
            'authority_value' => 8,
            'required_permission' => 'ACTION:RET-UNSOLD:APPROVE-HIGH',
            'approval_rule_version' => 2,
        ]);
        $this->assertDatabaseHas('work_items', [
            'source_type' => 'approval_request',
            'source_id' => $approvalId,
            'required_permission' => 'ACTION:RET-UNSOLD:APPROVE-HIGH',
            'priority' => 'URGENT',
        ]);

        $this->signIn(self::FINANCE_USER_ID);
        $this->getJson("/api/v1/sales/unsold-return-approvals/{$approvalId}")
            ->assertOk()
            ->assertJsonPath('data.can_decide', false)
            ->assertJsonPath('data.authority.band_name', 'High loss authority');
        $this->decision($approvalId, 'approve', 1)->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->signIn(self::ADMIN_USER_ID);
        $this->decision($approvalId, 'approve', 1)
            ->assertOk()
            ->assertJsonPath('data.authority_source', 'DIRECT')
            ->assertJsonPath('data.authority_permission', 'ACTION:RET-UNSOLD:APPROVE-HIGH');
    }

    public function test_temporary_delegation_grants_decision_authority_and_revocation_removes_it(): void
    {
        $delegation = $this->createDelegation(
            self::FINANCE_USER_ID,
            self::SALES_USER_ID,
            'ACTION:RET-UNSOLD:APPROVE'
        )->assertCreated()->json('data');
        [$approvalId] = $this->createPendingApproval();

        $this->signIn(self::SALES_USER_ID);
        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonFragment(['ACTION:RET-UNSOLD:APPROVE'])
            ->assertJsonPath('data.delegated_authorities.0.id', $delegation['id'])
            ->assertJsonPath('data.delegated_authorities.0.delegator.name', 'Demo Finance Manager');
        $this->decision($approvalId, 'approve', 1)
            ->assertOk()
            ->assertJsonPath('data.authority_source', 'DELEGATION')
            ->assertJsonPath('data.delegation_id', $delegation['id']);
        $this->assertDatabaseHas('approval_decisions', [
            'approval_request_id' => $approvalId,
            'reviewer_id' => self::SALES_USER_ID,
            'authority_source' => 'DELEGATION',
            'delegation_id' => $delegation['id'],
        ]);

        $this->signIn(self::ADMIN_USER_ID);
        $this->withHeaders(['If-Match' => '1', 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/admin/approval-delegations/{$delegation['id']}/revoke")
            ->assertOk()
            ->assertJsonPath('data.status', 'REVOKED');

        $this->signIn(self::SALES_USER_ID);
        $this->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.delegated_authorities', [])
            ->assertJsonMissing(['ACTION:RET-UNSOLD:APPROVE']);
        $this->getJson('/api/v1/sales/unsold-return-approvals')->assertForbidden();
    }

    public function test_delegation_rejects_overlaps_and_authority_chains(): void
    {
        $body = $this->delegationBody(
            self::FINANCE_USER_ID,
            self::SALES_USER_ID,
            'ACTION:RET-UNSOLD:APPROVE'
        );
        $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/admin/approval-delegations', $body)->assertCreated();
        $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/admin/approval-delegations', $body)
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.fields.effective_from.0',
                'This approver already has an overlapping delegation for that authority.'
            );

        $invalid = $this->delegationBody(
            self::OPERATIONS_USER_ID,
            self::SALES_USER_ID,
            'ACTION:RET-UNSOLD:APPROVE'
        );
        $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/admin/approval-delegations', $invalid)
            ->assertUnprocessable()
            ->assertJsonPath(
                'error.fields.delegator_id.0',
                'The delegator does not directly hold this approval authority in the selected plant.'
            );
    }

    public function test_due_approval_escalation_is_idempotent_reassigns_authority_and_preserves_audit(): void
    {
        [$approvalId] = $this->createPendingApproval();
        DB::table('approval_requests')->where('id', $approvalId)->update([
            'escalate_at' => now()->subMinute(),
        ]);
        DB::table('work_items')->where('source_type', 'approval_request')
            ->where('source_id', $approvalId)->update([
                'assigned_user_id' => self::FINANCE_USER_ID,
            ]);

        $key = (string) Str::uuid();
        $first = $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/v1/admin/approvals/escalate-due')
            ->assertOk()
            ->assertJsonPath('data.escalated_count', 1)
            ->assertJsonPath('data.approval_request_ids.0', $approvalId);
        $this->withHeaders(['Idempotency-Key' => $key])
            ->postJson('/api/v1/admin/approvals/escalate-due')
            ->assertOk()->assertExactJson($first->json());
        $this->assertDatabaseHas('approval_requests', [
            'id' => $approvalId,
            'required_permission' => 'ACTION:RET-UNSOLD:APPROVE-ESCALATED',
            'escalation_count' => 1,
            'record_version' => 2,
        ]);
        $this->assertDatabaseHas('work_items', [
            'source_type' => 'approval_request',
            'source_id' => $approvalId,
            'assigned_user_id' => null,
            'priority' => 'URGENT',
            'required_permission' => 'ACTION:RET-UNSOLD:APPROVE-ESCALATED',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'command' => 'ESCALATE_APPROVAL',
            'entity_id' => $approvalId,
            'reason_code' => 'SLA_EXPIRED',
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'approval.request.escalated',
            'aggregate_id' => $approvalId,
        ]);

        $this->signIn(self::FINANCE_USER_ID);
        $this->getJson("/api/v1/sales/unsold-return-approvals/{$approvalId}")
            ->assertOk()
            ->assertJsonPath('data.can_decide', false)
            ->assertJsonPath('data.escalation_count', 1);
        $this->decision($approvalId, 'reject', 2)->assertForbidden();

        $this->signIn(self::ADMIN_USER_ID);
        $this->decision($approvalId, 'reject', 2, 'Escalated reviewer returned this to Quality.')
            ->assertOk()
            ->assertJsonPath('data.authority_permission', 'ACTION:RET-UNSOLD:APPROVE-ESCALATED');
    }

    public function test_rejected_submission_links_the_corrected_resubmission(): void
    {
        [$firstApprovalId, $caseId, $lineId] = $this->createPendingApproval();
        $this->signIn(self::FINANCE_USER_ID);
        $this->decision($firstApprovalId, 'reject', 1, 'Correct the proposed disposition.')->assertOk();

        $service = $this->app->make(UnsoldSalesReturnService::class);
        $resubmission = $service->disposition($caseId, [
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::PLANT_ID,
            'actor_id' => self::OPERATIONS_USER_ID,
            'expected_version' => 4,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'line_id' => $lineId,
                'restock_quantity' => '1',
                'repack_quantity' => '1',
                'rework_quantity' => '0',
                'destroy_quantity' => '8',
                'quality_reason_code' => 'CORRECTED_DISPOSITION',
            ]],
        ]);

        $this->assertDatabaseHas('approval_requests', [
            'id' => $resubmission['approval_request_id'],
            'resubmission_of_id' => $firstApprovalId,
            'submission_number' => 2,
        ]);
        $this->getJson('/api/v1/sales/unsold-return-approvals/'.$resubmission['approval_request_id'])
            ->assertOk()
            ->assertJsonPath('data.resubmission_of_id', $firstApprovalId)
            ->assertJsonPath('data.submission_number', 2);
    }

    private function updateLocalRule(array $body): void
    {
        $this->withHeaders(['If-Match' => '1', 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/admin/approval-rules/'.$this->localRuleId(), Arr::except($body, ['code']))
            ->assertOk();
    }

    private function localRuleId(): string
    {
        return (string) DB::table('approval_rules')
            ->where('company_id', self::COMPANY_ID)
            ->where('plant_id', self::PLANT_ID)
            ->where('code', 'UNSOLD_RETURN_LOSS_APPROVAL')
            ->value('id');
    }

    private function ruleBody(int $threshold): array
    {
        return [
            'code' => 'UNSOLD_RETURN_LOSS_APPROVAL',
            'name' => 'Unsold return loss approval',
            'description' => 'Tested plant authority routing.',
            'status' => 'ACTIVE',
            'bands' => [
                [
                    'name' => 'Standard loss authority',
                    'minimum_value' => '0',
                    'maximum_value' => (string) $threshold,
                    'required_permission' => 'ACTION:RET-UNSOLD:APPROVE',
                    'escalation_permission' => 'ACTION:RET-UNSOLD:APPROVE-ESCALATED',
                    'work_priority' => 'HIGH',
                    'due_hours' => 24,
                    'escalate_after_hours' => 12,
                ],
                [
                    'name' => 'High loss authority',
                    'minimum_value' => (string) $threshold,
                    'maximum_value' => null,
                    'required_permission' => 'ACTION:RET-UNSOLD:APPROVE-HIGH',
                    'escalation_permission' => 'ACTION:RET-UNSOLD:APPROVE-ESCALATED',
                    'work_priority' => 'URGENT',
                    'due_hours' => 12,
                    'escalate_after_hours' => 6,
                ],
            ],
        ];
    }

    private function purchaseRuleBody(): array
    {
        return [
            'code' => 'PURCHASE_REQUISITION_APPROVAL',
            'name' => 'Purchase requisition approval',
            'description' => 'Tested plant routing by estimated order value.',
            'status' => 'ACTIVE',
            'bands' => [
                [
                    'name' => 'Standard requisition authority',
                    'minimum_value' => '0',
                    'maximum_value' => '100000',
                    'required_permission' => 'ACTION:PUR-REQ:APPROVE',
                    'escalation_permission' => 'ACTION:PUR-REQ:APPROVE-ESCALATED',
                    'work_priority' => 'HIGH',
                    'due_hours' => 24,
                    'escalate_after_hours' => 24,
                ],
                [
                    'name' => 'High-value requisition authority',
                    'minimum_value' => '100000',
                    'maximum_value' => null,
                    'required_permission' => 'ACTION:PUR-REQ:APPROVE-HIGH',
                    'escalation_permission' => 'ACTION:PUR-REQ:APPROVE-ESCALATED',
                    'work_priority' => 'URGENT',
                    'due_hours' => 12,
                    'escalate_after_hours' => 12,
                ],
            ],
        ];
    }

    private function createDelegation(string $delegator, string $delegate, string $permission)
    {
        return $this->withHeaders(['Idempotency-Key' => (string) Str::uuid()])
            ->postJson('/api/v1/admin/approval-delegations',
                $this->delegationBody($delegator, $delegate, $permission));
    }

    private function delegationBody(string $delegator, string $delegate, string $permission): array
    {
        return [
            'delegator_id' => $delegator,
            'delegate_id' => $delegate,
            'permission_code' => $permission,
            'effective_from' => now()->subMinute()->toISOString(),
            'effective_to' => now()->addDay()->toISOString(),
            'reason' => 'Temporary reviewer cover for controlled testing.',
        ];
    }

    private function decision(
        string $approvalId,
        string $decision,
        int $version,
        ?string $reason = 'Reviewed against the configured authority rule.',
    ) {
        return $this->withHeaders([
            'If-Match' => (string) $version,
            'Idempotency-Key' => (string) Str::uuid(),
        ])->postJson("/api/v1/sales/unsold-return-approvals/{$approvalId}/{$decision}", [
            'reason' => $reason,
        ]);
    }

    private function createPendingApproval(
        string $qualityActor = self::OPERATIONS_USER_ID,
        string $destroyQuantity = '8',
    ): array {
        $service = $this->app->make(UnsoldSalesReturnService::class);
        $scope = ['company_id' => self::COMPANY_ID, 'plant_id' => self::PLANT_ID];
        $case = $service->createRequest($scope + [
            'party_id' => '00000000-0000-4000-8000-000000000501',
            'shipment_id' => '00000000-0000-4000-8000-000000001001',
            'reason_code' => 'UNSOLD_MARKET_RETURN',
            'actor_id' => self::SALES_USER_ID,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'shipment_line_id' => '00000000-0000-4000-8000-000000001101',
                'sku_id' => '00000000-0000-4000-8000-000000000601',
                'fg_lot_id' => '00000000-0000-4000-8000-000000000701',
                'requested_quantity' => '10',
                'uom_code' => 'PACK',
            ]],
        ]);
        $caseId = $case['return_case_id'];
        $lineId = (string) DB::table('unsold_return_lines')
            ->where('return_case_id', $caseId)->value('id');
        $service->receive($caseId, $scope + [
            'actor_id' => self::OPERATIONS_USER_ID,
            'expected_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'line_id' => $lineId,
                'received_quantity' => '10',
                'return_position_id' => '00000000-0000-4000-8000-000000001201',
            ]],
        ]);
        $repack = bcsub('10', $destroyQuantity, 6);
        $disposition = $service->disposition($caseId, $scope + [
            'actor_id' => $qualityActor,
            'expected_version' => 2,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'line_id' => $lineId,
                'restock_quantity' => '0',
                'repack_quantity' => $repack,
                'rework_quantity' => '0',
                'destroy_quantity' => $destroyQuantity,
                'quality_reason_code' => 'SHORT_SHELF_LIFE',
            ]],
        ]);

        return [$disposition['approval_request_id'], $caseId, $lineId];
    }

    private function signIn(string $userId): void
    {
        $this->actingAs(User::query()->findOrFail($userId))->withSession([
            'erp.company_id' => self::COMPANY_ID,
            'erp.plant_id' => self::PLANT_ID,
        ]);
    }
}

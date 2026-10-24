<?php

namespace App\Modules\Sales\Application;

use App\Modules\Work\Application\WorkItemService;
use App\Shared\Approval\ApprovalAuthorityService;
use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class UnsoldSalesReturnApprovalService
{
    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
        private readonly UnsoldSalesReturnOutcomePostingService $outcomes,
        private readonly WorkItemService $workItems,
        private readonly ApprovalAuthorityService $authority,
    ) {}

    public function decide(string $approvalId, string $decision, array $data): array
    {
        if (! in_array($decision, ['APPROVE', 'REJECT'], true)) {
            throw ValidationException::withMessages([
                'decision' => ['Decision must be APPROVE or REJECT.'],
            ]);
        }

        return DB::transaction(function () use ($approvalId, $decision, $data) {
            $namespace = "sales.unsold-return.approval.{$approvalId}";
            $key = $data['idempotency_key'];
            $payload = Arr::except($data + ['decision' => $decision], [
                'idempotency_key',
                'correlation_id',
                'permissions',
            ]);

            if ($existing = $this->idempotency->begin($namespace, $key, $payload)) {
                return $existing;
            }

            $approval = DB::table('approval_requests')
                ->where('id', $approvalId)
                ->where('entity_type', 'unsold_return_loss')
                ->where('rule_code', 'UNSOLD_RETURN_LOSS_APPROVAL')
                ->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])
                ->lockForUpdate()
                ->first();

            if (! $approval) {
                throw new NotFoundHttpException('Unsold return approval request not found.');
            }

            if ((int) $approval->record_version !== $data['expected_version']) {
                throw new ConflictHttpException(
                    "The approval changed from version {$data['expected_version']} to {$approval->record_version}. Refresh it before retrying."
                );
            }

            if ($approval->status !== 'PENDING') {
                throw ValidationException::withMessages([
                    'approval' => ['Approval request is no longer pending.'],
                ]);
            }

            if ((string) $approval->maker_id === $data['actor_id']) {
                throw ValidationException::withMessages([
                    'approval' => ['Maker cannot approve or reject the same controlled transaction.'],
                ]);
            }

            $requiredPermission = $approval->required_permission
                ?: 'ACTION:RET-UNSOLD:APPROVE';
            $authority = $this->authority->resolve(
                $data['actor_id'],
                $requiredPermission,
                $data['company_id'],
                $data['plant_id']
            );
            if ($authority === null) {
                throw new AuthorizationException(
                    "This approval requires {$requiredPermission} authority in the selected plant."
                );
            }

            $case = DB::table('unsold_return_cases')
                ->where('id', $approval->entity_id)
                ->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])
                ->lockForUpdate()
                ->first();

            if (
                ! $case
                || $case->status !== 'DISPOSITION_REVIEW'
                || (int) $case->record_version !== (int) $approval->entity_version
            ) {
                throw new ConflictHttpException(
                    'The return case no longer matches the version submitted for approval.'
                );
            }

            $decisionId = (string) Str::uuid();
            $approvalVersion = (int) $approval->record_version + 1;
            $approvalStatus = $decision === 'APPROVE' ? 'APPROVED' : 'REJECTED';

            DB::table('approval_decisions')->insert([
                'id' => $decisionId,
                'approval_request_id' => $approvalId,
                'reviewer_id' => $data['actor_id'],
                'decision' => $decision,
                'reason' => $data['reason'] ?? null,
                'authority_source' => $authority['source'],
                'authority_permission' => $authority['permission'],
                'delegation_id' => $authority['delegation_id'],
                'created_at' => now(),
            ]);

            DB::table('approval_requests')->where('id', $approvalId)->update([
                'status' => $approvalStatus,
                'record_version' => $approvalVersion,
                'updated_at' => now(),
            ]);

            $this->workItems->resolveApproval(
                $approvalId,
                $data['actor_id'],
                $decision
            );

            $stockMovementIds = $decision === 'APPROVE'
                ? $this->outcomes->postApproved($approvalId, $data['correlation_id'] ?? null)
                : [];

            $caseStatus = $case->status;
            $caseVersion = (int) $case->record_version;
            if ($decision === 'REJECT') {
                $caseStatus = 'RETURN_QUARANTINE';
                $caseVersion++;

                DB::table('unsold_return_cases')->where('id', $case->id)->update([
                    'status' => $caseStatus,
                    'record_version' => $caseVersion,
                    'updated_at' => now(),
                ]);

                DB::table('unsold_return_status_history')->insert([
                    'id' => (string) Str::uuid(),
                    'return_case_id' => $case->id,
                    'company_id' => $case->company_id,
                    'plant_id' => $case->plant_id,
                    'from_status' => $case->status,
                    'to_status' => $caseStatus,
                    'record_version' => $caseVersion,
                    'actor_id' => $data['actor_id'],
                    'created_at' => now(),
                ]);
            }

            $this->audit->record(
                'DECIDE_UNSOLD_RETURN_LOSS_APPROVAL',
                'approval_request',
                $approvalId,
                $data['actor_id'],
                $approval->company_id,
                $approval->plant_id,
                'SUCCESS',
                [
                    'entity_version' => $approvalVersion,
                    'correlation_id' => $data['correlation_id'] ?? null,
                    'reason_code' => $decision,
                    'safe_diff' => [
                        'status' => ['from' => 'PENDING', 'to' => $approvalStatus],
                        'authority_source' => $authority['source'],
                        'authority_permission' => $authority['permission'],
                        'delegation_id' => $authority['delegation_id'],
                    ],
                ]
            );

            $this->outbox->append(
                'approval.unsold_return.decided',
                'approval_request',
                $approvalId,
                $approvalId,
                [
                    'approval_request_id' => $approvalId,
                    'decision_id' => $decisionId,
                    'decision' => $decision,
                    'return_case_id' => (string) $case->id,
                    'case_status' => $caseStatus,
                    'case_record_version' => $caseVersion,
                    'stock_movement_ids' => $stockMovementIds,
                    'authority_source' => $authority['source'],
                    'authority_permission' => $authority['permission'],
                    'delegation_id' => $authority['delegation_id'],
                ],
                $data['correlation_id'] ?? null
            );

            $result = [
                'approval_request_id' => $approvalId,
                'decision_id' => $decisionId,
                'decision' => $decision,
                'approval_status' => $approvalStatus,
                'approval_record_version' => $approvalVersion,
                'return_case_id' => (string) $case->id,
                'case_status' => $caseStatus,
                'case_record_version' => $caseVersion,
                'stock_movement_ids' => $stockMovementIds,
                'authority_source' => $authority['source'],
                'authority_permission' => $authority['permission'],
                'delegation_id' => $authority['delegation_id'],
            ];

            $this->idempotency->complete($namespace, $key, $result);

            return $result;
        });
    }
}

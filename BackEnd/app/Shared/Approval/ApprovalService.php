<?php

namespace App\Shared\Approval;

use App\Modules\Work\Application\WorkItemService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class ApprovalService
{
    public function __construct(
        private readonly WorkItemService $workItems,
        private readonly ApprovalRuleResolver $rules,
    ) {}

    public function request(
        string $entityType,
        string $entityId,
        int $entityVersion,
        string $makerId,
        string $companyId,
        ?string $plantId,
        string $ruleCode,
        array $summary = [],
    ): string {
        return DB::transaction(function () use (
            $entityType,
            $entityId,
            $entityVersion,
            $makerId,
            $companyId,
            $plantId,
            $ruleCode,
            $summary,
        ) {
            $id = (string) Str::uuid();
            $authorityValue = (string) ($summary['authority_value'] ?? '0');
            $routing = $this->rules->resolve($ruleCode, $authorityValue, $companyId, $plantId);
            if ($routing['entity_type'] !== $entityType) {
                throw ValidationException::withMessages([
                    'approval_rule' => ['The resolved approval rule does not match this entity type.'],
                ]);
            }

            $previous = DB::table('approval_requests')
                ->where('entity_type', $entityType)
                ->where('entity_id', $entityId)
                ->where('rule_code', $ruleCode)
                ->orderByDesc('created_at')
                ->orderByDesc('id')
                ->first();
            $now = now();

            DB::table('approval_requests')->insert([
                'id' => $id,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'entity_version' => $entityVersion,
                'maker_id' => $makerId,
                'company_id' => $companyId,
                'plant_id' => $plantId,
                'rule_code' => $ruleCode,
                'status' => 'PENDING',
                'record_version' => 1,
                'summary_json' => json_encode($summary, JSON_THROW_ON_ERROR),
                'approval_rule_id' => $routing['rule_id'],
                'approval_rule_version' => $routing['rule_version'],
                'approval_rule_band_id' => $routing['band_id'],
                'rule_name_snapshot' => $routing['rule_name'],
                'band_name_snapshot' => $routing['band_name'],
                'authority_value' => $authorityValue,
                'authority_uom' => $routing['authority_uom'],
                'required_permission' => $routing['required_permission'],
                'escalation_permission' => $routing['escalation_permission'],
                'due_at' => $now->copy()->addHours($routing['due_hours']),
                'escalate_at' => $now->copy()->addHours($routing['escalate_after_hours']),
                'escalated_at' => null,
                'escalation_count' => 0,
                'resubmission_of_id' => $previous?->status === 'REJECTED' ? $previous->id : null,
                'submission_number' => $previous === null ? 1 : (int) $previous->submission_number + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->workItems->recordApproval(
                $id,
                $entityType,
                $entityId,
                $makerId,
                $companyId,
                $plantId,
                $ruleCode,
                $summary,
                [
                    'title' => $routing['rule_name'],
                    'priority' => $routing['work_priority'],
                    'required_permission' => $routing['required_permission'],
                    'due_at' => $now->copy()->addHours($routing['due_hours']),
                ]
            );

            return $id;
        });
    }

    public function decide(string $requestId, string $reviewerId, string $decision, ?string $reason = null): void
    {
        if (! in_array($decision, ['APPROVE', 'REJECT'], true)) {
            throw ValidationException::withMessages([
                'decision' => ['Decision must be APPROVE or REJECT.'],
            ]);
        }

        DB::transaction(function () use ($requestId, $reviewerId, $decision, $reason) {
            $request = DB::table('approval_requests')->where('id', $requestId)->lockForUpdate()->first();

            if (! $request || $request->status !== 'PENDING') {
                throw ValidationException::withMessages([
                    'approval' => ['Approval request is no longer pending.'],
                ]);
            }

            if ($request->maker_id === $reviewerId) {
                throw ValidationException::withMessages([
                    'approval' => ['Maker cannot approve the same controlled transaction.'],
                ]);
            }

            DB::table('approval_decisions')->insert([
                'id' => (string) Str::uuid(),
                'approval_request_id' => $requestId,
                'reviewer_id' => $reviewerId,
                'decision' => $decision,
                'reason' => $reason,
                'authority_source' => 'INTERNAL',
                'authority_permission' => $request->required_permission,
                'delegation_id' => null,
                'created_at' => now(),
            ]);

            DB::table('approval_requests')->where('id', $requestId)->update([
                'status' => $decision === 'APPROVE' ? 'APPROVED' : 'REJECTED',
                'record_version' => $request->record_version + 1,
                'updated_at' => now(),
            ]);

            $this->workItems->resolveApproval($requestId, $reviewerId, $decision);
        });
    }
}

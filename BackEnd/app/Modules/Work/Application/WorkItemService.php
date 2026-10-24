<?php

namespace App\Modules\Work\Application;

use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class WorkItemService
{
    public const KINDS = ['APPROVAL', 'TASK', 'EXCEPTION'];
    public const PRIORITIES = ['URGENT', 'HIGH', 'NORMAL', 'LOW'];

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    public function recordApproval(
        string $approvalId,
        string $entityType,
        string $entityId,
        string $makerId,
        string $companyId,
        ?string $plantId,
        string $ruleCode,
        array $summary = [],
        array $routing = [],
    ): string {
        return $this->record([
            'company_id' => $companyId,
            'plant_id' => $plantId,
            'kind' => 'APPROVAL',
            'title' => $routing['title'] ?? $this->approvalTitle($ruleCode),
            'description' => $summary['work_description']
                ?? 'Review the controlled transaction and record an approval decision.',
            'priority' => $routing['priority'] ?? $summary['work_priority'] ?? 'HIGH',
            'required_permission' => $routing['required_permission'] ?? $this->approvalPermission($ruleCode),
            'source_type' => 'approval_request',
            'source_id' => $approvalId,
            'target_screen_code' => $this->approvalScreen($entityType),
            'target_record_id' => $entityId,
            'due_at' => $routing['due_at']
                ?? now()->addHours(max(1, (int) config('qtfoods.approval_work_item_due_hours', 24))),
            'created_by' => $makerId,
        ]);
    }

    /**
     * Persist a task or exception emitted by another module.
     *
     * The source module owns the transaction, audit event and outbox event which
     * produced this projection. User-driven work-item changes are controlled below.
     */
    public function record(array $attributes): string
    {
        $kind = (string) ($attributes['kind'] ?? '');
        $priority = (string) ($attributes['priority'] ?? 'NORMAL');

        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidArgumentException('Unsupported work-item kind.');
        }

        if (! in_array($priority, self::PRIORITIES, true)) {
            throw new InvalidArgumentException('Unsupported work-item priority.');
        }

        foreach (['company_id', 'title', 'created_by'] as $required) {
            if (! is_string($attributes[$required] ?? null) || trim($attributes[$required]) === '') {
                throw new InvalidArgumentException("The {$required} work-item attribute is required.");
            }
        }

        $id = (string) Str::uuid();
        $now = now();

        DB::table('work_items')->insert([
            'id' => $id,
            'company_id' => $attributes['company_id'],
            'plant_id' => $attributes['plant_id'] ?? null,
            'kind' => $kind,
            'title' => trim($attributes['title']),
            'description' => isset($attributes['description'])
                ? trim((string) $attributes['description']) ?: null
                : null,
            'priority' => $priority,
            'status' => 'OPEN',
            'assigned_user_id' => $attributes['assigned_user_id'] ?? null,
            'required_permission' => $attributes['required_permission'] ?? null,
            'source_type' => $attributes['source_type'] ?? null,
            'source_id' => $attributes['source_id'] ?? null,
            'target_screen_code' => $attributes['target_screen_code'] ?? null,
            'target_record_id' => $attributes['target_record_id'] ?? null,
            'due_at' => $attributes['due_at'] ?? null,
            'completed_at' => null,
            'completed_by' => null,
            'completion_note' => null,
            'created_by' => $attributes['created_by'],
            'record_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $id;
    }

    public function resolveApproval(string $approvalId, string $reviewerId, string $decision): void
    {
        $item = DB::table('work_items')
            ->where('source_type', 'approval_request')
            ->where('source_id', $approvalId)
            ->lockForUpdate()
            ->first();

        if (! $item || $item->status !== 'OPEN') {
            return;
        }

        DB::table('work_items')->where('id', $item->id)->update([
            'status' => 'COMPLETED',
            'completed_at' => now(),
            'completed_by' => $reviewerId,
            'completion_note' => 'Approval '.($decision === 'APPROVE' ? 'approved' : 'rejected').'.',
            'record_version' => (int) $item->record_version + 1,
            'updated_at' => now(),
        ]);
    }

    public function claim(string $workItemId, array $data): array
    {
        return DB::transaction(function () use ($workItemId, $data) {
            $namespace = "work.item.claim.{$workItemId}";
            $key = $data['idempotency_key'];
            $payload = Arr::except($data, ['idempotency_key', 'correlation_id', 'permissions']);

            if ($existing = $this->idempotency->begin($namespace, $key, $payload)) {
                return $existing;
            }

            $item = $this->scopedItem($workItemId, $data, true);
            $this->assertExpectedVersion($item, $data['expected_version']);
            $this->assertOpen($item);

            if ($item->assigned_user_id !== null) {
                throw ValidationException::withMessages([
                    'assignment' => ['This work item is already assigned. Refresh the queue before retrying.'],
                ]);
            }

            $version = (int) $item->record_version + 1;
            DB::table('work_items')->where('id', $workItemId)->update([
                'assigned_user_id' => $data['actor_id'],
                'record_version' => $version,
                'updated_at' => now(),
            ]);

            $result = $this->mutationResult(
                $item,
                'OPEN',
                $version,
                $data['actor_id'],
                null,
                null
            );

            $this->recordMutation(
                'CLAIM_WORK_ITEM',
                'work.item.claimed',
                $item,
                $data,
                $version,
                ['assigned_user_id' => ['from' => null, 'to' => $data['actor_id']]],
                $result
            );
            $this->idempotency->complete($namespace, $key, $result);

            return $result;
        });
    }

    public function assign(string $workItemId, ?string $assigneeId, array $data): array
    {
        return DB::transaction(function () use ($workItemId, $assigneeId, $data) {
            $namespace = "work.item.assign.{$workItemId}";
            $key = $data['idempotency_key'];
            $payload = Arr::except($data + ['assigned_user_id' => $assigneeId], [
                'idempotency_key',
                'correlation_id',
                'permissions',
            ]);

            if ($existing = $this->idempotency->begin($namespace, $key, $payload)) {
                return $existing;
            }

            $item = $this->scopedItem($workItemId, $data, true);
            $this->assertExpectedVersion($item, $data['expected_version']);
            $this->assertOpen($item);
            $this->assertAssigneeInScope(
                $assigneeId,
                $item->required_permission,
                $data
            );

            if (($item->assigned_user_id ?? null) === $assigneeId) {
                throw ValidationException::withMessages([
                    'assigned_user_id' => ['The work item already has that assignment.'],
                ]);
            }

            $version = (int) $item->record_version + 1;
            DB::table('work_items')->where('id', $workItemId)->update([
                'assigned_user_id' => $assigneeId,
                'record_version' => $version,
                'updated_at' => now(),
            ]);

            $result = $this->mutationResult($item, 'OPEN', $version, $assigneeId, null, null);
            $this->recordMutation(
                'ASSIGN_WORK_ITEM',
                'work.item.assigned',
                $item,
                $data,
                $version,
                ['assigned_user_id' => ['from' => $item->assigned_user_id, 'to' => $assigneeId]],
                $result
            );
            $this->idempotency->complete($namespace, $key, $result);

            return $result;
        });
    }

    public function complete(string $workItemId, array $data): array
    {
        return DB::transaction(function () use ($workItemId, $data) {
            $namespace = "work.item.complete.{$workItemId}";
            $key = $data['idempotency_key'];
            $payload = Arr::except($data, [
                'idempotency_key',
                'correlation_id',
                'permissions',
                'can_manage',
            ]);

            if ($existing = $this->idempotency->begin($namespace, $key, $payload)) {
                return $existing;
            }

            $item = $this->scopedItem($workItemId, $data, true);
            $this->assertExpectedVersion($item, $data['expected_version']);
            $this->assertOpen($item);

            if ($item->kind === 'APPROVAL') {
                throw ValidationException::withMessages([
                    'work_item' => ['Approval work must be completed through its approval decision.'],
                ]);
            }

            if ($item->assigned_user_id === null) {
                throw ValidationException::withMessages([
                    'assignment' => ['Claim or assign this work item before completing it.'],
                ]);
            }

            if ((string) $item->assigned_user_id !== $data['actor_id'] && ! $data['can_manage']) {
                throw new AuthorizationException('Only the assignee can complete this work item.');
            }

            $version = (int) $item->record_version + 1;
            $completedAt = now();
            $note = isset($data['completion_note'])
                ? trim((string) $data['completion_note']) ?: null
                : null;

            DB::table('work_items')->where('id', $workItemId)->update([
                'status' => 'COMPLETED',
                'completed_at' => $completedAt,
                'completed_by' => $data['actor_id'],
                'completion_note' => $note,
                'record_version' => $version,
                'updated_at' => $completedAt,
            ]);

            $result = $this->mutationResult(
                $item,
                'COMPLETED',
                $version,
                $item->assigned_user_id,
                $data['actor_id'],
                $completedAt->toISOString()
            );
            $this->recordMutation(
                'COMPLETE_WORK_ITEM',
                'work.item.completed',
                $item,
                $data,
                $version,
                ['status' => ['from' => 'OPEN', 'to' => 'COMPLETED']],
                $result
            );
            $this->idempotency->complete($namespace, $key, $result);

            return $result;
        });
    }

    private function scopedItem(string $workItemId, array $data, bool $lock = false): object
    {
        $query = DB::table('work_items')
            ->where('id', $workItemId)
            ->where('company_id', $data['company_id'])
            ->when(
                $data['plant_id'] ?? null,
                fn ($query, string $plantId) => $query->where('plant_id', $plantId)
            )
            ->where(function ($query) use ($data) {
                $query->whereNull('required_permission');
                if (($data['permissions'] ?? []) !== []) {
                    $query->orWhereIn('required_permission', $data['permissions']);
                }
            })
            ->when(
                ! in_array('ACTION:WRK-HOME:MANAGE', $data['permissions'] ?? [], true),
                fn ($query) => $query->where(fn ($query) => $query
                    ->whereNull('assigned_user_id')
                    ->orWhere('assigned_user_id', $data['actor_id']))
            );

        if ($lock) {
            $query->lockForUpdate();
        }

        $item = $query->first();
        if (! $item) {
            throw new NotFoundHttpException('Work item not found.');
        }

        return $item;
    }

    private function assertAssigneeInScope(
        ?string $assigneeId,
        ?string $requiredPermission,
        array $data,
    ): void
    {
        if ($assigneeId === null) {
            return;
        }

        $exists = DB::table('role_assignments as assignment')
            ->join('users as user', 'user.id', '=', 'assignment.user_id')
            ->when($requiredPermission !== null, fn ($query) => $query
                ->join('role_permissions as role_permission', 'role_permission.role_id', '=', 'assignment.role_id')
                ->join('permissions as permission', 'permission.id', '=', 'role_permission.permission_id')
                ->where('permission.code', $requiredPermission))
            ->where('assignment.user_id', $assigneeId)
            ->where('assignment.is_active', true)
            ->where('user.status', 'ACTIVE')
            ->where(fn ($query) => $query
                ->whereNull('assignment.company_id')
                ->orWhere('assignment.company_id', $data['company_id']))
            ->when(
                $data['plant_id'] ?? null,
                fn ($query, string $plantId) => $query->where(fn ($query) => $query
                    ->whereNull('assignment.plant_id')
                    ->orWhere('assignment.plant_id', $plantId))
            )
            ->where(fn ($query) => $query
                ->whereNull('assignment.effective_from')
                ->orWhere('assignment.effective_from', '<=', now()))
            ->where(fn ($query) => $query
                ->whereNull('assignment.effective_to')
                ->orWhere('assignment.effective_to', '>', now()))
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages([
                'assigned_user_id' => [
                    'Select an active user with the required permission in this company and plant.',
                ],
            ]);
        }
    }

    private function assertExpectedVersion(object $item, int $expectedVersion): void
    {
        if ((int) $item->record_version !== $expectedVersion) {
            throw new ConflictHttpException(
                "The work item changed from version {$expectedVersion} to {$item->record_version}. Refresh it before retrying."
            );
        }
    }

    private function assertOpen(object $item): void
    {
        if ($item->status !== 'OPEN') {
            throw ValidationException::withMessages([
                'work_item' => ['This work item is no longer open.'],
            ]);
        }
    }

    private function mutationResult(
        object $item,
        string $status,
        int $version,
        ?string $assigneeId,
        ?string $completedBy,
        ?string $completedAt,
    ): array {
        return [
            'id' => (string) $item->id,
            'kind' => $item->kind,
            'status' => $status,
            'assigned_user_id' => $assigneeId,
            'completed_by' => $completedBy,
            'completed_at' => $completedAt,
            'record_version' => $version,
        ];
    }

    private function recordMutation(
        string $command,
        string $eventType,
        object $item,
        array $data,
        int $version,
        array $safeDiff,
        array $result,
    ): void {
        $this->audit->record(
            $command,
            'work_item',
            (string) $item->id,
            $data['actor_id'],
            $item->company_id,
            $item->plant_id,
            'SUCCESS',
            [
                'entity_version' => $version,
                'correlation_id' => $data['correlation_id'] ?? null,
                'safe_diff' => $safeDiff,
            ]
        );

        $this->outbox->append(
            $eventType,
            'work_item',
            (string) $item->id,
            $item->id.':'.$version,
            $result,
            $data['correlation_id'] ?? null
        );
    }

    private function approvalTitle(string $ruleCode): string
    {
        return match ($ruleCode) {
            'UNSOLD_RETURN_LOSS_APPROVAL' => 'Review unsold return loss disposition',
            'PURCHASE_REQUISITION_APPROVAL' => 'Review purchase requisition',
            default => 'Review '.Str::lower(Str::headline($ruleCode)),
        };
    }

    private function approvalPermission(string $ruleCode): ?string
    {
        return match ($ruleCode) {
            'UNSOLD_RETURN_LOSS_APPROVAL' => 'ACTION:RET-UNSOLD:APPROVE',
            'PURCHASE_REQUISITION_APPROVAL' => 'ACTION:PUR-REQ:APPROVE',
            default => null,
        };
    }

    private function approvalScreen(string $entityType): ?string
    {
        return match ($entityType) {
            'unsold_return_loss' => 'RET-UNSOLD',
            'purchase_requisition' => 'PUR-REQ',
            default => null,
        };
    }
}

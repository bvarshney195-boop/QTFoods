<?php

namespace App\Modules\Control\Application;

use App\Shared\Approval\ApprovalAuthorityService;
use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ApprovalRuleService
{
    public const STATUSES = ['ACTIVE', 'INACTIVE'];
    public const PRIORITIES = ['URGENT', 'HIGH', 'NORMAL', 'LOW'];
    public const SUPPORTED_RULES = [
        'UNSOLD_RETURN_LOSS_APPROVAL' => [
            'entity_type' => 'unsold_return_loss',
            'authority_metric' => 'DESTROY_QUANTITY',
            'authority_uom' => 'BASE',
        ],
        'PURCHASE_REQUISITION_APPROVAL' => [
            'entity_type' => 'purchase_requisition',
            'authority_metric' => 'ESTIMATED_TOTAL',
            'authority_uom' => 'INR',
        ],
    ];

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
        private readonly ApprovalAuthorityService $authority,
    ) {}

    public function createRule(array $data): array
    {
        return $this->execute('approval.rule.create.'.$data['company_id'].'.'.$data['plant_id'], $data,
            function () use ($data) {
                $plantId = $this->assertPlant($data);
                $definition = self::SUPPORTED_RULES[$data['code']] ?? null;
                if ($definition === null) {
                    throw ValidationException::withMessages([
                        'code' => ['This approval rule type is not supported by an implemented workflow.'],
                    ]);
                }
                if (DB::table('approval_rules')->where('scope_key', 'PLANT:'.$plantId)
                    ->where('code', $data['code'])->exists()) {
                    throw ValidationException::withMessages([
                        'code' => ['A plant override for this approval rule already exists.'],
                    ]);
                }

                $bands = $this->validateBands($data['bands'], $data);
                $id = (string) Str::uuid();
                $now = now();
                DB::table('approval_rules')->insert([
                    'id' => $id,
                    'company_id' => $data['company_id'],
                    'plant_id' => $plantId,
                    'scope_key' => 'PLANT:'.$plantId,
                    'code' => $data['code'],
                    'name' => trim($data['name']),
                    'description' => $this->nullableText($data['description'] ?? null),
                    ...$definition,
                    'status' => $data['status'],
                    'is_system' => false,
                    'record_version' => 1,
                    'created_by' => $data['actor_id'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $this->syncBands($id, $bands, $now);

                $result = $this->result('approval_rule', $id, $data['status'], 1, [
                    'band_count' => count($bands),
                ]);
                $this->record('CREATE_APPROVAL_RULE', 'approval.rule.created', 'approval_rule', $id,
                    $data, 1, ['created' => ['code' => $data['code'], 'bands' => count($bands)]], $result);

                return $result;
            });
    }

    public function updateRule(string $ruleId, array $data): array
    {
        return $this->execute("approval.rule.update.{$ruleId}", $data, function () use ($ruleId, $data) {
            $this->assertPlant($data);
            $rule = $this->scopedRule($ruleId, $data, true);
            $this->assertVersion($rule, $data['expected_version'], 'approval rule');
            $bands = $this->validateBands($data['bands'], $data);
            $version = (int) $rule->record_version + 1;
            $changes = [
                'name' => trim($data['name']),
                'description' => $this->nullableText($data['description'] ?? null),
                'status' => $data['status'],
            ];
            $now = now();
            DB::table('approval_rules')->where('id', $ruleId)->update($changes + [
                'record_version' => $version,
                'updated_at' => $now,
            ]);
            $this->syncBands($ruleId, $bands, $now);

            $result = $this->result('approval_rule', $ruleId, $data['status'], $version, [
                'band_count' => count($bands),
            ]);
            $this->record('UPDATE_APPROVAL_RULE', 'approval.rule.updated', 'approval_rule', $ruleId,
                $data, $version, [
                    'definition' => $this->diff($rule, $changes),
                    'bands_replaced' => count($bands),
                ], $result);

            return $result;
        });
    }

    public function createDelegation(array $data): array
    {
        return $this->execute('approval.delegation.create.'.$data['company_id'].'.'.$data['plant_id'], $data,
            function () use ($data) {
                $plantId = $this->assertPlant($data);
                if ($data['delegator_id'] === $data['delegate_id']) {
                    throw ValidationException::withMessages([
                        'delegate_id' => ['An approver cannot delegate authority to themselves.'],
                    ]);
                }
                $this->assertUserInScope($data['delegator_id'], $data, 'delegator_id');
                $this->assertUserInScope($data['delegate_id'], $data, 'delegate_id');
                $this->assertApprovalPermission($data['permission_code'], $data);
                if (! $this->authority->hasDirectPermission(
                    $data['delegator_id'],
                    $data['permission_code'],
                    $data['company_id'],
                    $plantId
                )) {
                    throw ValidationException::withMessages([
                        'delegator_id' => ['The delegator does not directly hold this approval authority in the selected plant.'],
                    ]);
                }

                $from = CarbonImmutable::parse($data['effective_from']);
                $to = CarbonImmutable::parse($data['effective_to']);
                if (! $to->greaterThan($from)) {
                    throw ValidationException::withMessages([
                        'effective_to' => ['Delegated authority must end after it begins.'],
                    ]);
                }
                if ($to->lessThanOrEqualTo(now())) {
                    throw ValidationException::withMessages([
                        'effective_to' => ['Delegated authority must end in the future.'],
                    ]);
                }

                $overlap = DB::table('approval_delegations')
                    ->where('company_id', $data['company_id'])
                    ->where('plant_id', $plantId)
                    ->where('delegator_id', $data['delegator_id'])
                    ->where('permission_code', $data['permission_code'])
                    ->where('status', 'ACTIVE')
                    ->where('effective_from', '<', $to)
                    ->where('effective_to', '>', $from)
                    ->exists();
                if ($overlap) {
                    throw ValidationException::withMessages([
                        'effective_from' => ['This approver already has an overlapping delegation for that authority.'],
                    ]);
                }

                $id = (string) Str::uuid();
                $now = now();
                DB::table('approval_delegations')->insert([
                    'id' => $id,
                    'company_id' => $data['company_id'],
                    'plant_id' => $plantId,
                    'delegator_id' => $data['delegator_id'],
                    'delegate_id' => $data['delegate_id'],
                    'permission_code' => $data['permission_code'],
                    'effective_from' => $from,
                    'effective_to' => $to,
                    'reason' => trim($data['reason']),
                    'status' => 'ACTIVE',
                    'revoked_at' => null,
                    'revoked_by' => null,
                    'created_by' => $data['actor_id'],
                    'record_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $result = $this->result('approval_delegation', $id, 'ACTIVE', 1, [
                    'delegator_id' => $data['delegator_id'],
                    'delegate_id' => $data['delegate_id'],
                    'permission_code' => $data['permission_code'],
                ]);
                $this->record('CREATE_APPROVAL_DELEGATION', 'approval.delegation.created',
                    'approval_delegation', $id, $data, 1, [
                        'created' => Arr::only($data, [
                            'delegator_id', 'delegate_id', 'permission_code',
                            'effective_from', 'effective_to',
                        ]),
                    ], $result);

                return $result;
            });
    }

    public function revokeDelegation(string $delegationId, array $data): array
    {
        return $this->execute("approval.delegation.revoke.{$delegationId}", $data,
            function () use ($delegationId, $data) {
                $this->assertPlant($data);
                $delegation = DB::table('approval_delegations')
                    ->where('id', $delegationId)
                    ->where('company_id', $data['company_id'])
                    ->where('plant_id', $data['plant_id'])
                    ->lockForUpdate()->first();
                if (! $delegation) {
                    throw new NotFoundHttpException('Approval delegation not found.');
                }
                $this->assertVersion($delegation, $data['expected_version'], 'approval delegation');
                if ($delegation->status !== 'ACTIVE') {
                    throw ValidationException::withMessages([
                        'delegation' => ['This approval delegation has already been revoked.'],
                    ]);
                }

                $version = (int) $delegation->record_version + 1;
                $now = now();
                DB::table('approval_delegations')->where('id', $delegationId)->update([
                    'status' => 'REVOKED',
                    'revoked_at' => $now,
                    'revoked_by' => $data['actor_id'],
                    'record_version' => $version,
                    'updated_at' => $now,
                ]);
                $result = $this->result('approval_delegation', $delegationId, 'REVOKED', $version);
                $this->record('REVOKE_APPROVAL_DELEGATION', 'approval.delegation.revoked',
                    'approval_delegation', $delegationId, $data, $version,
                    ['status' => ['from' => 'ACTIVE', 'to' => 'REVOKED']], $result);

                return $result;
            });
    }

    public function escalateDue(array $data): array
    {
        return $this->execute('approval.escalation.run.'.$data['company_id'].'.'.$data['plant_id'], $data,
            function () use ($data) {
                $plantId = $this->assertPlant($data);
                $requests = DB::table('approval_requests')
                    ->where('company_id', $data['company_id'])
                    ->where('plant_id', $plantId)
                    ->where('status', 'PENDING')
                    ->whereNull('escalated_at')
                    ->whereNotNull('escalation_permission')
                    ->whereNotNull('escalate_at')
                    ->where('escalate_at', '<=', now())
                    ->orderBy('escalate_at')->lockForUpdate()->get();
                $ids = [];

                foreach ($requests as $request) {
                    $version = (int) $request->record_version + 1;
                    $escalationCount = (int) $request->escalation_count + 1;
                    $now = now();
                    DB::table('approval_requests')->where('id', $request->id)->update([
                        'required_permission' => $request->escalation_permission,
                        'escalated_at' => $now,
                        'escalation_count' => $escalationCount,
                        'record_version' => $version,
                        'updated_at' => $now,
                    ]);

                    $workItem = DB::table('work_items')
                        ->where('source_type', 'approval_request')
                        ->where('source_id', $request->id)
                        ->where('status', 'OPEN')->lockForUpdate()->first();
                    if ($workItem) {
                        DB::table('work_items')->where('id', $workItem->id)->update([
                            'required_permission' => $request->escalation_permission,
                            'priority' => 'URGENT',
                            'assigned_user_id' => null,
                            'record_version' => (int) $workItem->record_version + 1,
                            'updated_at' => $now,
                        ]);
                    }

                    $result = [
                        'approval_request_id' => (string) $request->id,
                        'record_version' => $version,
                        'required_permission' => $request->escalation_permission,
                        'escalation_count' => $escalationCount,
                        'escalated_at' => $now->toISOString(),
                    ];
                    $this->audit->record('ESCALATE_APPROVAL', 'approval_request', (string) $request->id,
                        $data['actor_id'], $data['company_id'], $plantId, 'SUCCESS', [
                            'entity_version' => $version,
                            'correlation_id' => $data['correlation_id'] ?? null,
                            'reason_code' => 'SLA_EXPIRED',
                            'safe_diff' => [
                                'required_permission' => [
                                    'from' => $request->required_permission,
                                    'to' => $request->escalation_permission,
                                ],
                                'escalation_count' => ['from' => $request->escalation_count, 'to' => $escalationCount],
                            ],
                        ]);
                    $this->outbox->append('approval.request.escalated', 'approval_request',
                        (string) $request->id, $request->id.':'.$escalationCount, $result,
                        $data['correlation_id'] ?? null);
                    $ids[] = (string) $request->id;
                }

                return [
                    'entity_type' => 'approval_escalation_batch',
                    'id' => (string) Str::uuid(),
                    'status' => 'COMPLETED',
                    'record_version' => 1,
                    'escalated_count' => count($ids),
                    'approval_request_ids' => $ids,
                ];
            });
    }

    private function execute(string $namespace, array $data, Closure $command): array
    {
        return DB::transaction(function () use ($namespace, $data, $command) {
            $payload = Arr::except($data, ['permissions', 'idempotency_key', 'correlation_id']);
            $existing = $this->idempotency->begin($namespace, $data['idempotency_key'], $payload);
            if ($existing !== null) {
                return $existing;
            }

            $result = $command();
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        });
    }

    private function validateBands(array $input, array $data): array
    {
        if ($input === []) {
            throw ValidationException::withMessages([
                'bands' => ['Define at least one authority band.'],
            ]);
        }

        $bands = [];
        foreach (array_values($input) as $index => $band) {
            $minimum = $this->decimal($band['minimum_value']);
            $maximum = isset($band['maximum_value']) && $band['maximum_value'] !== ''
                ? $this->decimal($band['maximum_value'])
                : null;
            if ($maximum !== null && bccomp($maximum, $minimum, 6) <= 0) {
                throw ValidationException::withMessages([
                    "bands.{$index}.maximum_value" => ['Maximum authority must be greater than minimum authority.'],
                ]);
            }
            if (! in_array($band['work_priority'], self::PRIORITIES, true)) {
                throw ValidationException::withMessages([
                    "bands.{$index}.work_priority" => ['Select a supported work priority.'],
                ]);
            }
            if ((int) $band['escalate_after_hours'] > (int) $band['due_hours']) {
                throw ValidationException::withMessages([
                    "bands.{$index}.escalate_after_hours" => ['Escalation must occur on or before the approval due time.'],
                ]);
            }
            $this->assertApprovalPermission($band['required_permission'], $data,
                "bands.{$index}.required_permission");
            $this->assertApprovalPermission($band['escalation_permission'], $data,
                "bands.{$index}.escalation_permission");
            if ($band['required_permission'] === $band['escalation_permission']) {
                throw ValidationException::withMessages([
                    "bands.{$index}.escalation_permission" => ['Escalation authority must differ from the initial authority.'],
                ]);
            }

            $bands[] = [
                'sequence' => $index + 1,
                'name' => trim($band['name']),
                'minimum_value' => $minimum,
                'maximum_value' => $maximum,
                'required_permission' => $band['required_permission'],
                'escalation_permission' => $band['escalation_permission'],
                'work_priority' => $band['work_priority'],
                'due_hours' => (int) $band['due_hours'],
                'escalate_after_hours' => (int) $band['escalate_after_hours'],
            ];
        }

        if (bccomp($bands[0]['minimum_value'], '0', 6) !== 0) {
            throw ValidationException::withMessages([
                'bands.0.minimum_value' => ['The first authority band must begin at zero.'],
            ]);
        }
        foreach ($bands as $index => $band) {
            $last = $index === array_key_last($bands);
            if ($last && $band['maximum_value'] !== null) {
                throw ValidationException::withMessages([
                    "bands.{$index}.maximum_value" => ['The final authority band must have no maximum.'],
                ]);
            }
            if (! $last && $band['maximum_value'] === null) {
                throw ValidationException::withMessages([
                    "bands.{$index}.maximum_value" => ['Only the final authority band may have no maximum.'],
                ]);
            }
            if ($index > 0 && bccomp($bands[$index - 1]['maximum_value'], $band['minimum_value'], 6) !== 0) {
                throw ValidationException::withMessages([
                    "bands.{$index}.minimum_value" => ['Authority bands must be contiguous without gaps or overlaps.'],
                ]);
            }
        }

        return $bands;
    }

    private function syncBands(string $ruleId, array $bands, mixed $now): void
    {
        $existing = DB::table('approval_rule_bands')
            ->where('approval_rule_id', $ruleId)
            ->orderBy('sequence')
            ->lockForUpdate()
            ->get()
            ->keyBy(fn (object $band) => (int) $band->sequence);
        $sequences = array_column($bands, 'sequence');
        $obsoleteIds = $existing
            ->reject(fn (object $band) => in_array((int) $band->sequence, $sequences, true))
            ->pluck('id')
            ->all();

        if ($obsoleteIds !== [] && DB::table('approval_requests')
            ->whereIn('approval_rule_band_id', $obsoleteIds)->exists()) {
            throw ValidationException::withMessages([
                'bands' => [
                    'Authority bands used by existing approvals cannot be removed. Edit those bands in place instead.',
                ],
            ]);
        }

        foreach ($bands as $band) {
            $current = $existing->get($band['sequence']);
            if ($current) {
                DB::table('approval_rule_bands')
                    ->where('id', $current->id)
                    ->where('approval_rule_id', $ruleId)
                    ->update($band + ['updated_at' => $now]);

                continue;
            }

            DB::table('approval_rule_bands')->insert($band + [
                'id' => (string) Str::uuid(),
                'approval_rule_id' => $ruleId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if ($obsoleteIds !== []) {
            DB::table('approval_rule_bands')->whereIn('id', $obsoleteIds)->delete();
        }
    }

    private function scopedRule(string $ruleId, array $data, bool $lock = false): object
    {
        $query = DB::table('approval_rules')->where('id', $ruleId)
            ->where('company_id', $data['company_id'])
            ->where('plant_id', $data['plant_id'])
            ->where('is_system', false);
        if ($lock) {
            $query->lockForUpdate();
        }
        $rule = $query->first();
        if (! $rule) {
            throw new NotFoundHttpException('Editable plant approval rule not found.');
        }

        return $rule;
    }

    private function assertPlant(array $data): string
    {
        if (! is_string($data['plant_id'] ?? null)) {
            throw ValidationException::withMessages([
                'context' => ['Select a plant before administering approval controls.'],
            ]);
        }
        $active = DB::table('plants')->where('id', $data['plant_id'])
            ->where('company_id', $data['company_id'])->where('status', 'ACTIVE')->exists();
        if (! $active) {
            throw ValidationException::withMessages([
                'context' => ['The selected plant is not active in this organisation.'],
            ]);
        }

        return $data['plant_id'];
    }

    private function assertUserInScope(string $userId, array $data, string $field): void
    {
        $exists = DB::table('users as user')->where('user.id', $userId)->where('user.status', 'ACTIVE')
            ->whereExists(fn (Builder $query) => $query
                ->selectRaw('1')->from('role_assignments as assignment')
                ->whereColumn('assignment.user_id', 'user.id')
                ->where('assignment.is_active', true)
                ->where(fn (Builder $scope) => $scope
                    ->whereNull('assignment.company_id')->orWhere('assignment.company_id', $data['company_id']))
                ->where(fn (Builder $scope) => $scope
                    ->whereNull('assignment.plant_id')->orWhere('assignment.plant_id', $data['plant_id']))
                ->where(fn (Builder $window) => $window
                    ->whereNull('assignment.effective_from')->orWhere('assignment.effective_from', '<=', now()))
                ->where(fn (Builder $window) => $window
                    ->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>', now())))
            ->exists();
        if (! $exists) {
            throw ValidationException::withMessages([
                $field => ['Select an active user assigned to the selected company and plant.'],
            ]);
        }
    }

    private function assertApprovalPermission(string $code, array $data, string $field = 'permission_code'): void
    {
        $exists = DB::table('permissions')->where('code', $code)->where('status', 'ACTIVE')
            ->where('code', 'like', 'ACTION:%APPROVE%')
            ->where(fn (Builder $query) => $query
                ->whereNull('company_id')->orWhere('company_id', $data['company_id']))
            ->exists();
        if (! $exists) {
            throw ValidationException::withMessages([
                $field => ['Select an active approval action permission available to this organisation.'],
            ]);
        }
    }

    private function assertVersion(object $entity, int $expectedVersion, string $label): void
    {
        if ((int) $entity->record_version !== $expectedVersion) {
            throw new ConflictHttpException(
                "The {$label} changed from version {$expectedVersion} to {$entity->record_version}. Refresh it before retrying."
            );
        }
    }

    private function decimal(mixed $value): string
    {
        return number_format((float) $value, 6, '.', '');
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }

    private function diff(object $before, array $changes): array
    {
        $diff = [];
        foreach ($changes as $field => $value) {
            if ((string) ($before->{$field} ?? null) !== (string) $value) {
                $diff[$field] = ['from' => $before->{$field} ?? null, 'to' => $value];
            }
        }

        return $diff;
    }

    private function result(string $type, string $id, string $status, int $version, array $extra = []): array
    {
        return [
            'entity_type' => $type,
            'id' => $id,
            'status' => $status,
            'record_version' => $version,
        ] + $extra;
    }

    private function record(
        string $command,
        string $eventType,
        string $entityType,
        string $entityId,
        array $data,
        int $version,
        array $safeDiff,
        array $result,
    ): void {
        $this->audit->record($command, $entityType, $entityId, $data['actor_id'],
            $data['company_id'], $data['plant_id'], 'SUCCESS', [
                'entity_version' => $version,
                'correlation_id' => $data['correlation_id'] ?? null,
                'safe_diff' => $safeDiff,
            ]);
        $this->outbox->append($eventType, $entityType, $entityId, $entityId.':'.$version,
            $result, $data['correlation_id'] ?? null);
    }
}

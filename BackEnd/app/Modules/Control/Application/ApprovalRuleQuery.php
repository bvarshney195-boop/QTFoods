<?php

namespace App\Modules\Control\Application;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ApprovalRuleQuery
{
    public function workspace(array $scope, array $permissions): array
    {
        $plantId = $this->requirePlant($scope);
        $rules = $this->visibleRules($scope)->get();
        $effectiveIds = $rules->where('status', 'ACTIVE')->groupBy('code')
            ->map(fn ($group) => (string) $group->sortByDesc(
                fn (object $rule) => $this->scopeRank($rule, $scope)
            )->first()->id);
        $rulePayloads = $rules->map(fn (object $rule) => $this->rulePayload(
            $rule,
            $scope,
            $permissions,
            $effectiveIds->get($rule->code) === (string) $rule->id
        ))->values()->all();

        $delegations = DB::table('approval_delegations as delegation')
            ->join('users as delegator', 'delegator.id', '=', 'delegation.delegator_id')
            ->join('users as delegate', 'delegate.id', '=', 'delegation.delegate_id')
            ->where('delegation.company_id', $scope['company_id'])
            ->where('delegation.plant_id', $plantId)
            ->orderByDesc('delegation.created_at')
            ->get([
                'delegation.*', 'delegator.name as delegator_name',
                'delegator.email as delegator_email', 'delegate.name as delegate_name',
                'delegate.email as delegate_email',
            ])
            ->map(fn (object $delegation) => $this->delegationPayload($delegation, $permissions))
            ->values()->all();

        $pending = DB::table('approval_requests')
            ->where('company_id', $scope['company_id'])
            ->where('plant_id', $plantId)
            ->where('status', 'PENDING');

        return [
            'data' => $rulePayloads,
            'delegations' => $delegations,
            'summary' => [
                'rules' => count($rulePayloads),
                'plant_overrides' => collect($rulePayloads)->where('scope', 'PLANT')->count(),
                'active_rules' => collect($rulePayloads)->where('status', 'ACTIVE')->count(),
                'pending_approvals' => (clone $pending)->count(),
                'escalated_approvals' => (clone $pending)->whereNotNull('escalated_at')->count(),
                'active_delegations' => collect($delegations)->where('effective_status', 'ACTIVE')->count(),
            ],
            'lookups' => [
                'permissions' => $this->approvalPermissions($scope),
                'users' => $this->users($scope),
            ],
            'allowed_actions' => array_values(array_filter([
                $this->can($permissions, 'ACTION:ADM-RULE:CREATE') ? 'CREATE_RULE' : null,
                $this->can($permissions, 'ACTION:ADM-RULE:DELEGATE') ? 'CREATE_DELEGATION' : null,
                $this->can($permissions, 'ACTION:ADM-RULE:ESCALATE') ? 'ESCALATE_DUE' : null,
            ])),
        ];
    }

    public function rule(string $ruleId, array $scope, array $permissions): array
    {
        $rule = $this->visibleRules($scope)->where('id', $ruleId)->first();
        if (! $rule) {
            throw new NotFoundHttpException('Approval rule not found.');
        }

        $effective = $this->visibleRules($scope)->where('code', $rule->code)
            ->where('status', 'ACTIVE')->get()->sortByDesc(
                fn (object $candidate) => $this->scopeRank($candidate, $scope)
            )->first();

        return $this->rulePayload(
            $rule,
            $scope,
            $permissions,
            $effective !== null && (string) $effective->id === $ruleId
        );
    }

    private function visibleRules(array $scope): Builder
    {
        $plantId = $this->requirePlant($scope);

        return DB::table('approval_rules as rule')
            ->where(function (Builder $query) use ($scope, $plantId) {
                $query->where(fn (Builder $global) => $global
                    ->whereNull('rule.company_id')->whereNull('rule.plant_id'))
                    ->orWhere(fn (Builder $local) => $local
                        ->where('rule.company_id', $scope['company_id'])
                        ->where(fn (Builder $level) => $level
                            ->whereNull('rule.plant_id')->orWhere('rule.plant_id', $plantId)));
            })
            ->orderBy('rule.code')
            ->orderByRaw('CASE WHEN rule.plant_id IS NOT NULL THEN 0 WHEN rule.company_id IS NOT NULL THEN 1 ELSE 2 END');
    }

    private function rulePayload(
        object $rule,
        array $scope,
        array $permissions,
        bool $isEffective,
    ): array {
        $bands = DB::table('approval_rule_bands')->where('approval_rule_id', $rule->id)
            ->orderBy('sequence')->get()->map(fn (object $band) => [
                'id' => (string) $band->id,
                'sequence' => (int) $band->sequence,
                'name' => $band->name,
                'minimum_value' => $this->decimal($band->minimum_value),
                'maximum_value' => $band->maximum_value === null ? null : $this->decimal($band->maximum_value),
                'required_permission' => $band->required_permission,
                'escalation_permission' => $band->escalation_permission,
                'work_priority' => $band->work_priority,
                'due_hours' => (int) $band->due_hours,
                'escalate_after_hours' => (int) $band->escalate_after_hours,
            ])->values()->all();
        $scopeName = $rule->plant_id !== null ? 'PLANT' : ($rule->company_id !== null ? 'COMPANY' : 'GLOBAL');
        $editable = ! (bool) $rule->is_system
            && $rule->company_id === $scope['company_id']
            && $rule->plant_id === $scope['plant_id'];

        return [
            'id' => (string) $rule->id,
            'company_id' => $rule->company_id === null ? null : (string) $rule->company_id,
            'plant_id' => $rule->plant_id === null ? null : (string) $rule->plant_id,
            'scope' => $scopeName,
            'code' => $rule->code,
            'name' => $rule->name,
            'description' => $rule->description,
            'entity_type' => $rule->entity_type,
            'authority_metric' => $rule->authority_metric,
            'authority_uom' => $rule->authority_uom,
            'status' => $rule->status,
            'is_system' => (bool) $rule->is_system,
            'is_effective' => $isEffective,
            'record_version' => (int) $rule->record_version,
            'bands' => $bands,
            'pending_request_count' => DB::table('approval_requests')
                ->where('approval_rule_id', $rule->id)
                ->where('company_id', $scope['company_id'])
                ->where('plant_id', $scope['plant_id'])
                ->where('status', 'PENDING')->count(),
            'allowed_actions' => $editable && $this->can($permissions, 'ACTION:ADM-RULE:UPDATE')
                ? ['UPDATE']
                : [],
            'created_at' => $this->timestamp($rule->created_at),
            'updated_at' => $this->timestamp($rule->updated_at),
        ];
    }

    private function delegationPayload(object $delegation, array $permissions): array
    {
        $now = CarbonImmutable::now();
        $from = CarbonImmutable::parse((string) $delegation->effective_from);
        $to = CarbonImmutable::parse((string) $delegation->effective_to);
        $effectiveStatus = match (true) {
            $delegation->status === 'REVOKED' => 'REVOKED',
            $from->isFuture() => 'SCHEDULED',
            $to->isPast() || $to->equalTo($now) => 'EXPIRED',
            default => 'ACTIVE',
        };

        return [
            'id' => (string) $delegation->id,
            'permission_code' => $delegation->permission_code,
            'delegator' => [
                'id' => (string) $delegation->delegator_id,
                'name' => $delegation->delegator_name,
                'email' => $delegation->delegator_email,
            ],
            'delegate' => [
                'id' => (string) $delegation->delegate_id,
                'name' => $delegation->delegate_name,
                'email' => $delegation->delegate_email,
            ],
            'effective_from' => $from->toISOString(),
            'effective_to' => $to->toISOString(),
            'reason' => $delegation->reason,
            'status' => $delegation->status,
            'effective_status' => $effectiveStatus,
            'record_version' => (int) $delegation->record_version,
            'allowed_actions' => $delegation->status === 'ACTIVE'
                && $to->isFuture()
                && $this->can($permissions, 'ACTION:ADM-RULE:DELEGATE')
                    ? ['REVOKE']
                    : [],
            'created_at' => $this->timestamp($delegation->created_at),
            'updated_at' => $this->timestamp($delegation->updated_at),
        ];
    }

    private function approvalPermissions(array $scope): array
    {
        return DB::table('permissions')
            ->where('status', 'ACTIVE')
            ->where('code', 'like', 'ACTION:%APPROVE%')
            ->where(fn (Builder $query) => $query
                ->whereNull('company_id')->orWhere('company_id', $scope['company_id']))
            ->orderBy('code')->get(['id', 'code', 'name'])
            ->map(fn (object $permission) => [
                'id' => (string) $permission->id,
                'code' => $permission->code,
                'name' => $permission->name,
            ])->values()->all();
    }

    private function users(array $scope): array
    {
        $plantId = $this->requirePlant($scope);

        return DB::table('users as user')
            ->where('user.status', 'ACTIVE')
            ->whereExists(fn (Builder $query) => $query
                ->selectRaw('1')->from('role_assignments as assignment')
                ->whereColumn('assignment.user_id', 'user.id')
                ->where('assignment.is_active', true)
                ->where(fn (Builder $context) => $context
                    ->whereNull('assignment.company_id')
                    ->orWhere('assignment.company_id', $scope['company_id']))
                ->where(fn (Builder $context) => $context
                    ->whereNull('assignment.plant_id')
                    ->orWhere('assignment.plant_id', $plantId))
                ->where(fn (Builder $window) => $window
                    ->whereNull('assignment.effective_from')
                    ->orWhere('assignment.effective_from', '<=', now()))
                ->where(fn (Builder $window) => $window
                    ->whereNull('assignment.effective_to')
                    ->orWhere('assignment.effective_to', '>', now())))
            ->orderBy('user.name')->get(['user.id', 'user.name', 'user.email'])
            ->map(fn (object $user) => [
                'id' => (string) $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'approval_permissions' => $this->directApprovalPermissions(
                    (string) $user->id,
                    $scope['company_id'],
                    $plantId
                ),
            ])->values()->all();
    }

    private function directApprovalPermissions(string $userId, string $companyId, string $plantId): array
    {
        return DB::table('role_assignments as assignment')
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->join('role_permissions as role_permission', 'role_permission.role_id', '=', 'role.id')
            ->join('permissions as permission', 'permission.id', '=', 'role_permission.permission_id')
            ->where('assignment.user_id', $userId)
            ->where('assignment.is_active', true)
            ->where('role.status', 'ACTIVE')
            ->where('permission.status', 'ACTIVE')
            ->where('permission.code', 'like', 'ACTION:%APPROVE%')
            ->where(fn (Builder $query) => $query
                ->whereNull('assignment.company_id')->orWhere('assignment.company_id', $companyId))
            ->where(fn (Builder $query) => $query
                ->whereNull('assignment.plant_id')->orWhere('assignment.plant_id', $plantId))
            ->where(fn (Builder $query) => $query
                ->whereNull('assignment.effective_from')->orWhere('assignment.effective_from', '<=', now()))
            ->where(fn (Builder $query) => $query
                ->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>', now()))
            ->pluck('permission.code')->unique()->sort()->values()->all();
    }

    private function scopeRank(object $rule, array $scope): int
    {
        if ($rule->company_id === $scope['company_id'] && $rule->plant_id === $scope['plant_id']) {
            return 3;
        }
        if ($rule->company_id === $scope['company_id']) {
            return 2;
        }

        return 1;
    }

    private function requirePlant(array $scope): string
    {
        if (! is_string($scope['plant_id'] ?? null)) {
            throw ValidationException::withMessages([
                'context' => ['Select a plant before administering approval controls.'],
            ]);
        }

        return $scope['plant_id'];
    }

    private function can(array $permissions, string $permission): bool
    {
        return in_array($permission, $permissions, true);
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }

    private function decimal(mixed $value): string
    {
        $normalised = rtrim(rtrim(bcadd((string) ($value ?? 0), '0', 6), '0'), '.');

        return $normalised === '' ? '0' : $normalised;
    }
}

<?php

namespace App\Shared\Approval;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class ApprovalAuthorityService
{
    public function resolve(
        string $userId,
        string $permissionCode,
        string $companyId,
        ?string $plantId,
    ): ?array {
        if ($this->hasDirectPermission($userId, $permissionCode, $companyId, $plantId)) {
            return [
                'source' => 'DIRECT',
                'permission' => $permissionCode,
                'delegation_id' => null,
                'delegator_id' => null,
            ];
        }

        $delegation = collect($this->activeDelegations($userId, $companyId, $plantId))
            ->firstWhere('permission_code', $permissionCode);

        return $delegation === null ? null : [
            'source' => 'DELEGATION',
            'permission' => $permissionCode,
            'delegation_id' => $delegation['id'],
            'delegator_id' => $delegation['delegator']['id'],
        ];
    }

    public function delegatedPermissions(string $userId, string $companyId, ?string $plantId): array
    {
        return collect($this->activeDelegations($userId, $companyId, $plantId))
            ->pluck('permission_code')->unique()->sort()->values()->all();
    }

    public function activeDelegations(string $userId, string $companyId, ?string $plantId): array
    {
        if ($plantId === null) {
            return [];
        }

        return DB::table('approval_delegations as delegation')
            ->join('users as delegator', 'delegator.id', '=', 'delegation.delegator_id')
            ->join('permissions as permission', 'permission.code', '=', 'delegation.permission_code')
            ->where('delegation.company_id', $companyId)
            ->where('delegation.plant_id', $plantId)
            ->where('delegation.delegate_id', $userId)
            ->where('delegation.status', 'ACTIVE')
            ->where('delegation.effective_from', '<=', now())
            ->where('delegation.effective_to', '>', now())
            ->where('delegator.status', 'ACTIVE')
            ->where('permission.status', 'ACTIVE')
            ->where(fn (Builder $query) => $query
                ->whereNull('permission.company_id')
                ->orWhere('permission.company_id', $companyId))
            ->orderBy('delegation.effective_to')
            ->get([
                'delegation.id', 'delegation.permission_code', 'delegation.delegator_id',
                'delegator.name as delegator_name', 'delegation.effective_from',
                'delegation.effective_to', 'delegation.reason',
            ])
            ->filter(fn (object $delegation) => $this->hasDirectPermission(
                (string) $delegation->delegator_id,
                (string) $delegation->permission_code,
                $companyId,
                $plantId
            ))
            ->map(fn (object $delegation) => [
                'id' => (string) $delegation->id,
                'permission_code' => $delegation->permission_code,
                'delegator' => [
                    'id' => (string) $delegation->delegator_id,
                    'name' => $delegation->delegator_name,
                ],
                'effective_from' => CarbonImmutable::parse((string) $delegation->effective_from)->toISOString(),
                'effective_to' => CarbonImmutable::parse((string) $delegation->effective_to)->toISOString(),
                'reason' => $delegation->reason,
            ])
            ->values()->all();
    }

    public function hasDirectPermission(
        string $userId,
        string $permissionCode,
        string $companyId,
        ?string $plantId,
    ): bool {
        return DB::table('role_assignments as assignment')
            ->join('users as user', 'user.id', '=', 'assignment.user_id')
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->join('role_permissions as role_permission', 'role_permission.role_id', '=', 'role.id')
            ->join('permissions as permission', 'permission.id', '=', 'role_permission.permission_id')
            ->where('assignment.user_id', $userId)
            ->where('user.status', 'ACTIVE')
            ->where('assignment.is_active', true)
            ->where('role.status', 'ACTIVE')
            ->where('permission.status', 'ACTIVE')
            ->where('permission.code', $permissionCode)
            ->where(fn (Builder $query) => $query
                ->whereNull('assignment.company_id')
                ->orWhere('assignment.company_id', $companyId))
            ->where(fn (Builder $query) => $query
                ->whereNull('assignment.plant_id')
                ->orWhere('assignment.plant_id', $plantId))
            ->where(fn (Builder $query) => $query
                ->whereNull('assignment.effective_from')
                ->orWhere('assignment.effective_from', '<=', now()))
            ->where(fn (Builder $query) => $query
                ->whereNull('assignment.effective_to')
                ->orWhere('assignment.effective_to', '>', now()))
            ->exists();
    }
}

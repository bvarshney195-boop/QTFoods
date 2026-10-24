<?php

namespace App\Modules\Partner\Application;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final class PartnerAccessResolver
{
    private const INTERNAL_PERMISSIONS = [
        'ACTION:PORTAL-EXT:ACCESS-GRANT',
        'ACTION:PORTAL-EXT:ACCESS-UPDATE',
        'ACTION:PORTAL-EXT:ACCESS-REVOKE',
        'ACTION:PORTAL-EXT:DOCUMENT-PUBLISH',
        'ACTION:PORTAL-EXT:DOCUMENT-WITHDRAW',
    ];

    public function isInternal(array $permissions): bool
    {
        return array_intersect(self::INTERNAL_PERMISSIONS, $permissions) !== [];
    }

    public function activeGrant(array $scope, string $actorId): ?object
    {
        return $this->activeGrantQuery($scope, $actorId)->first();
    }

    public function requireGrant(array $scope, string $actorId, array $requiredEntitlements = []): object
    {
        $grant = $this->activeGrant($scope, $actorId);
        if (! $grant) {
            throw new AccessDeniedHttpException('No active partner access grant exists in the selected context.');
        }

        $entitlements = $this->entitlements((string) $grant->id);
        $missing = array_values(array_diff($requiredEntitlements, $entitlements));
        if ($missing !== []) {
            throw new AccessDeniedHttpException('The partner access grant does not include '.implode(', ', $missing).'.');
        }

        $grant->entitlements = $entitlements;

        return $grant;
    }

    public function entitlements(string $grantId): array
    {
        return DB::table('partner_access_entitlements')
            ->where('partner_access_grant_id', $grantId)
            ->orderBy('entitlement_code')
            ->pluck('entitlement_code')
            ->map(fn (mixed $code): string => (string) $code)
            ->all();
    }

    public function hasPermission(array $permissions, string $action): bool
    {
        return in_array('ACTION:PORTAL-EXT:'.$action, $permissions, true);
    }

    private function activeGrantQuery(array $scope, string $actorId): Builder
    {
        return DB::table('partner_access_grants as grant')
            ->join('role_assignments as assignment', function ($join): void {
                $join->on('assignment.id', '=', 'grant.role_assignment_id')
                    ->on('assignment.user_id', '=', 'grant.user_id')
                    ->on('assignment.company_id', '=', 'grant.company_id')
                    ->on('assignment.plant_id', '=', 'grant.plant_id')
                    ->on('assignment.party_id', '=', 'grant.party_id');
            })
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->join('parties as party', function ($join): void {
                $join->on('party.id', '=', 'grant.party_id')
                    ->on('party.company_id', '=', 'grant.company_id');
            })
            ->join('users as portal_user', 'portal_user.id', '=', 'grant.user_id')
            ->where('grant.company_id', $scope['company_id'])
            ->where('grant.plant_id', $scope['plant_id'])
            ->where('grant.user_id', $actorId)
            ->where('grant.status', 'ACTIVE')
            ->where('assignment.is_active', true)
            ->where('role.code', 'PARTNER_PORTAL')
            ->where('role.status', 'ACTIVE')
            ->where('party.status', 'ACTIVE')
            ->where('portal_user.status', 'ACTIVE')
            ->where(fn (Builder $query) => $query->whereNull('grant.effective_from')->orWhere('grant.effective_from', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('grant.effective_to')->orWhere('grant.effective_to', '>', now()))
            ->where(fn (Builder $query) => $query->whereNull('assignment.effective_from')->orWhere('assignment.effective_from', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>', now()))
            ->select([
                'grant.*',
                'party.code as party_code',
                'party.display_name as party_name',
                'portal_user.name as user_name',
                'portal_user.email as user_email',
            ]);
    }
}

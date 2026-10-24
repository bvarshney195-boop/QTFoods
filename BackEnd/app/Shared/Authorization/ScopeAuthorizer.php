<?php

namespace App\Shared\Authorization;

use App\Modules\Foundation\Domain\User;
use Illuminate\Auth\Access\AuthorizationException;

final class ScopeAuthorizer
{
    /**
     * Permission = action + scope + state + authority.
     *
     * This method is deliberately strict: a menu item is never authorization.
     */
    public function assert(
        User $actor,
        string $permission,
        ?string $companyId = null,
        ?string $plantId = null,
        ?string $partyId = null,
        ?string $state = null,
    ): void {
        $allowed = $actor->roleAssignments()
            ->where('is_active', true)
            ->whereHas('role.permissions', fn ($q) => $q->where('code', $permission))
            ->get()
            ->contains(function ($assignment) use ($companyId, $plantId, $partyId) {
                if ($companyId && $assignment->company_id && $assignment->company_id !== $companyId) {
                    return false;
                }
                if ($plantId && $assignment->plant_id && $assignment->plant_id !== $plantId) {
                    return false;
                }
                if ($partyId && $assignment->party_id && $assignment->party_id !== $partyId) {
                    return false;
                }
                return true;
            });

        if (! $allowed) {
            throw new AuthorizationException('You are not authorised for this action in the selected scope.');
        }
    }
}

<?php

namespace App\Modules\Foundation\Application;

use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FoundationAdminService
{
    public const COMPANY_STATUSES = ['DRAFT', 'ACTIVE', 'INACTIVE'];
    public const PLANT_STATUSES = ['DRAFT', 'ACTIVE', 'INACTIVE'];
    public const USER_STATUSES = ['ACTIVE', 'INACTIVE', 'INVITED'];
    public const DEFINITION_STATUSES = ['ACTIVE', 'INACTIVE'];
    public const LOCATION_STATUSES = ['ACTIVE', 'INACTIVE'];
    public const LOCATION_TYPES = [
        'WAREHOUSE', 'ZONE', 'BIN', 'RETURN_QUARANTINE', 'FINISHED_GOODS',
        'RAW_MATERIAL', 'QUALITY_HOLD', 'REPACK', 'REWORK', 'BLOCKED', 'OTHER',
    ];

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    public function createCompany(array $data): array
    {
        return $this->execute('foundation.company.create.'.$data['actor_id'], $data, function () use ($data) {
            $this->assertErpAdministrator($data);
            $this->assertUnique('companies', 'code', $data['code'], 'code',
                'An organisation with this code already exists.');
            $now = now();
            $companyId = (string) Str::uuid();
            $plantId = (string) Str::uuid();
            $assignmentId = (string) Str::uuid();
            $adminRoleId = DB::table('roles')
                ->where('code', 'ERP_ADMIN')->where('status', 'ACTIVE')->value('id');

            if (! is_string($adminRoleId)) {
                throw ValidationException::withMessages([
                    'role' => ['The protected ERP Administrator role is not available.'],
                ]);
            }

            DB::table('companies')->insert([
                'id' => $companyId,
                'code' => $data['code'],
                'legal_name' => $data['legal_name'],
                'display_name' => $data['display_name'],
                'status' => 'ACTIVE',
                'record_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('plants')->insert([
                'id' => $plantId,
                'company_id' => $companyId,
                'code' => $data['plant_code'],
                'name' => $data['plant_name'],
                'timezone' => $data['timezone'],
                'status' => 'ACTIVE',
                'record_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('role_assignments')->insert([
                'id' => $assignmentId,
                'user_id' => $data['actor_id'],
                'role_id' => $adminRoleId,
                'company_id' => $companyId,
                'plant_id' => $plantId,
                'party_id' => null,
                'is_active' => true,
                'effective_from' => null,
                'effective_to' => null,
                'record_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $result = [
                'company_id' => $companyId,
                'plant_id' => $plantId,
                'administrator_assignment_id' => $assignmentId,
                'status' => 'ACTIVE',
                'record_version' => 1,
            ];
            $this->record('CREATE_COMPANY', 'foundation.company.created', 'company', $companyId,
                $companyId, $plantId, $data, 1, ['created' => ['code' => $data['code']]], $result);
            $this->record('CREATE_PLANT', 'foundation.plant.created', 'plant', $plantId,
                $companyId, $plantId, $data, 1, ['created' => ['code' => $data['plant_code']]], $result);
            $this->record('ASSIGN_ROLE', 'foundation.role-assignment.created', 'role_assignment', $assignmentId,
                $companyId, $plantId, $data, 1, ['created' => ['role_id' => $adminRoleId]], $result);

            return $result;
        });
    }

    public function updateCompany(string $companyId, array $data): array
    {
        return $this->execute("foundation.company.update.{$companyId}", $data, function () use ($companyId, $data) {
            $company = $this->scopedCompany($companyId, $data, true);
            $this->assertVersion($company, $data['expected_version'], 'organisation');
            if ($data['status'] === 'INACTIVE' && DB::table('plants')
                ->where('company_id', $companyId)->where('status', 'ACTIVE')->exists()) {
                throw ValidationException::withMessages([
                    'status' => ['Deactivate every plant before deactivating the organisation.'],
                ]);
            }

            $version = (int) $company->record_version + 1;
            $changes = [
                'legal_name' => $data['legal_name'],
                'display_name' => $data['display_name'],
                'status' => $data['status'],
            ];
            DB::table('companies')->where('id', $companyId)->update($changes + [
                'record_version' => $version,
                'updated_at' => now(),
            ]);
            $result = $this->result('company', $companyId, $data['status'], $version);
            $this->record('UPDATE_COMPANY', 'foundation.company.updated', 'company', $companyId,
                $companyId, $data['plant_id'], $data, $version, $this->diff($company, $changes), $result);

            return $result;
        });
    }

    public function createPlant(array $data): array
    {
        return $this->execute('foundation.plant.create.'.$data['company_id'], $data, function () use ($data) {
            $this->assertErpAdministrator($data);
            $company = $this->scopedCompany($data['company_id'], $data, true);
            if ($company->status === 'INACTIVE') {
                throw ValidationException::withMessages([
                    'company' => ['A plant cannot be created for an inactive organisation.'],
                ]);
            }
            $this->assertUnique('plants', 'code', $data['code'], 'code',
                'A plant with this code already exists in the organisation.', [
                    'company_id' => $data['company_id'],
                ]);

            $now = now();
            $plantId = (string) Str::uuid();
            DB::table('plants')->insert([
                'id' => $plantId,
                'company_id' => $data['company_id'],
                'code' => $data['code'],
                'name' => $data['name'],
                'timezone' => $data['timezone'],
                'status' => $data['status'],
                'record_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $assignmentId = $this->grantCreatorPlantAdministration($plantId, $data, $now);
            $result = $this->result('plant', $plantId, $data['status'], 1, [
                'administrator_assignment_id' => $assignmentId,
            ]);
            $this->record('CREATE_PLANT', 'foundation.plant.created', 'plant', $plantId,
                $data['company_id'], $plantId, $data, 1, ['created' => ['code' => $data['code']]], $result);

            return $result;
        });
    }

    public function updatePlant(string $plantId, array $data): array
    {
        return $this->execute("foundation.plant.update.{$plantId}", $data, function () use ($plantId, $data) {
            $plant = $this->scopedPlant($plantId, $data, true);
            $this->assertVersion($plant, $data['expected_version'], 'plant');
            if ($data['status'] === 'INACTIVE' && $plant->status !== 'INACTIVE') {
                $dependencies = [
                    'locations' => DB::table('locations')->where('plant_id', $plantId)
                        ->where('status', 'ACTIVE')->exists(),
                    'assignments' => DB::table('role_assignments')->where('plant_id', $plantId)
                        ->where('is_active', true)->exists(),
                    'work' => DB::table('work_items')->where('plant_id', $plantId)
                        ->where('status', 'OPEN')->exists(),
                ];
                if (in_array(true, $dependencies, true)) {
                    throw ValidationException::withMessages([
                        'status' => ['Deactivate plant locations and role assignments, and close open work, before deactivating this plant.'],
                    ]);
                }
            }

            $changes = [
                'name' => $data['name'],
                'timezone' => $data['timezone'],
                'status' => $data['status'],
            ];
            $version = (int) $plant->record_version + 1;
            DB::table('plants')->where('id', $plantId)->update($changes + [
                'record_version' => $version,
                'updated_at' => now(),
            ]);
            $result = $this->result('plant', $plantId, $data['status'], $version);
            $this->record('UPDATE_PLANT', 'foundation.plant.updated', 'plant', $plantId,
                $data['company_id'], $plantId, $data, $version, $this->diff($plant, $changes), $result);

            return $result;
        });
    }

    public function createLocation(array $data): array
    {
        return $this->execute('foundation.location.create.'.$data['plant_id'], $data, function () use ($data) {
            $this->assertPlantContext($data);
            $this->assertUnique('locations', 'code', $data['code'], 'code',
                'A location with this code already exists in the selected plant.', [
                    'company_id' => $data['company_id'],
                    'plant_id' => $data['plant_id'],
                ]);
            $this->assertLocationParent($data['parent_location_id'] ?? null, null, $data);
            $id = (string) Str::uuid();
            $now = now();
            DB::table('locations')->insert([
                'id' => $id,
                'company_id' => $data['company_id'],
                'plant_id' => $data['plant_id'],
                'parent_location_id' => $data['parent_location_id'] ?? null,
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'location_type' => $data['location_type'],
                'status' => $data['status'],
                'record_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $result = $this->result('location', $id, $data['status'], 1);
            $this->record('CREATE_LOCATION', 'foundation.location.created', 'location', $id,
                $data['company_id'], $data['plant_id'], $data, 1, ['created' => ['code' => $data['code']]], $result);

            return $result;
        });
    }

    public function updateLocation(string $locationId, array $data): array
    {
        return $this->execute("foundation.location.update.{$locationId}", $data, function () use ($locationId, $data) {
            $location = $this->scopedLocation($locationId, $data, true);
            $this->assertVersion($location, $data['expected_version'], 'location');
            $this->assertLocationParent($data['parent_location_id'] ?? null, $locationId, $data);

            $positionQuery = DB::table('stock_positions')->where('location_id', $locationId);
            if ($data['location_type'] !== $location->location_type && (clone $positionQuery)->exists()) {
                throw ValidationException::withMessages([
                    'location_type' => ['Location type cannot change after stock positions have been configured.'],
                ]);
            }
            if ($data['status'] === 'INACTIVE' && $location->status !== 'INACTIVE') {
                if ((clone $positionQuery)->where('quantity_base', '<>', 0)->exists()) {
                    throw ValidationException::withMessages([
                        'status' => ['Move all on-hand stock before deactivating this location.'],
                    ]);
                }
                if (DB::table('locations')->where('parent_location_id', $locationId)
                    ->where('status', 'ACTIVE')->exists()) {
                    throw ValidationException::withMessages([
                        'status' => ['Deactivate or move active child locations first.'],
                    ]);
                }
            }

            $changes = [
                'parent_location_id' => $data['parent_location_id'] ?? null,
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'location_type' => $data['location_type'],
                'status' => $data['status'],
            ];
            $version = (int) $location->record_version + 1;
            DB::table('locations')->where('id', $locationId)->update($changes + [
                'record_version' => $version,
                'updated_at' => now(),
            ]);
            $result = $this->result('location', $locationId, $data['status'], $version);
            $this->record('UPDATE_LOCATION', 'foundation.location.updated', 'location', $locationId,
                $data['company_id'], $data['plant_id'], $data, $version, $this->diff($location, $changes), $result);

            return $result;
        });
    }

    public function createUser(array $data): array
    {
        return $this->execute('foundation.user.create.'.$data['company_id'].'.'.$data['plant_id'], $data,
            function () use ($data) {
                $this->assertPlantContext($data);
                $this->assertUnique('users', 'email', $data['email'], 'email',
                    'A user with this email address already exists.');
                $role = $this->scopedRole($data['role_id'], $data, true);
                if ($role->status !== 'ACTIVE') {
                    throw ValidationException::withMessages(['role_id' => ['Select an active role.']]);
                }

                $now = now();
                $userId = (string) Str::uuid();
                $assignmentId = (string) Str::uuid();
                DB::table('users')->insert([
                    'id' => $userId,
                    'email' => $data['email'],
                    'name' => $data['name'],
                    'password_hash' => Hash::make($data['temporary_password']),
                    'status' => 'ACTIVE',
                    'email_verified_at' => $now,
                    'password_changed_at' => null,
                    'last_login_at' => null,
                    'last_login_ip' => null,
                    'mfa_secret' => null,
                    'mfa_enabled_at' => null,
                    'record_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('role_assignments')->insert([
                    'id' => $assignmentId,
                    'user_id' => $userId,
                    'role_id' => $data['role_id'],
                    'company_id' => $data['company_id'],
                    'plant_id' => $data['plant_id'],
                    'party_id' => null,
                    'is_active' => true,
                    'effective_from' => $data['effective_from'] ?? null,
                    'effective_to' => $data['effective_to'] ?? null,
                    'record_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $result = $this->result('user', $userId, 'ACTIVE', 1, [
                    'role_assignment_id' => $assignmentId,
                ]);
                $this->record('CREATE_USER', 'foundation.user.created', 'user', $userId,
                    $data['company_id'], $data['plant_id'], $data, 1,
                    ['created' => ['email' => $data['email'], 'name' => $data['name']]], $result);
                $this->record('ASSIGN_ROLE', 'foundation.role-assignment.created', 'role_assignment', $assignmentId,
                    $data['company_id'], $data['plant_id'], $data, 1,
                    ['created' => ['user_id' => $userId, 'role_id' => $data['role_id']]], $result);

                return $result;
            });
    }

    public function updateUser(string $userId, array $data): array
    {
        return $this->execute("foundation.user.update.{$userId}", $data, function () use ($userId, $data) {
            $user = $this->scopedUser($userId, $data, true);
            $this->assertVersion($user, $data['expected_version'], 'user');
            if ($user->deleted_at !== null) {
                throw ValidationException::withMessages([
                    'user' => ['A deleted user cannot be edited.'],
                ]);
            }
            if ($data['status'] === 'INACTIVE' && $userId === $data['actor_id']) {
                throw ValidationException::withMessages([
                    'status' => ['You cannot deactivate your own signed-in account.'],
                ]);
            }
            if ($data['status'] === 'INVITED' && $user->status !== 'INVITED') {
                throw ValidationException::withMessages([
                    'status' => ['An existing account cannot be returned to invitation state.'],
                ]);
            }
            if ($data['status'] === 'ACTIVE' && $user->status === 'INVITED') {
                throw ValidationException::withMessages([
                    'status' => ['The user must accept the invitation before the account becomes active.'],
                ]);
            }
            if ($data['status'] === 'INACTIVE' && DB::table('work_items')
                ->where('assigned_user_id', $userId)->where('status', 'OPEN')->exists()) {
                throw ValidationException::withMessages([
                    'status' => ['Reassign or close this user\'s open work before deactivating the account.'],
                ]);
            }

            $emailChanged = mb_strtolower((string) $user->email) !== mb_strtolower($data['email']);
            if ($emailChanged && $user->status === 'INVITED') {
                throw ValidationException::withMessages([
                    'email' => ['Revoke this invitation and invite the corrected email address as a new user.'],
                ]);
            }
            $changes = ['email' => $data['email'], 'name' => $data['name'], 'status' => $data['status']];
            if ($emailChanged) {
                $changes['email_verified_at'] = null;
            }
            $version = (int) $user->record_version + 1;
            DB::table('users')->where('id', $userId)->update($changes + [
                'record_version' => $version,
                'updated_at' => now(),
            ]);
            if ($emailChanged || $data['status'] === 'INACTIVE') {
                $reason = $data['status'] === 'INACTIVE' ? 'ACCOUNT_INACTIVE' : 'EMAIL_CHANGED';
                DB::table('user_sessions')->where('user_id', $userId)->whereNull('revoked_at')->update([
                    'revoked_at' => now(),
                    'revoked_by_user_id' => $data['actor_id'],
                    'revoke_reason' => $reason,
                    'record_version' => DB::raw('record_version + 1'),
                    'updated_at' => now(),
                ]);
                DB::table('identity_tokens')->where('user_id', $userId)
                    ->whereNull('used_at')->whereNull('revoked_at')
                    ->update(['revoked_at' => now(), 'updated_at' => now()]);
            }
            if ($data['status'] === 'INACTIVE') {
                DB::table('identity_invitations')->where('user_id', $userId)
                    ->whereNull('accepted_at')->whereNull('revoked_at')
                    ->update([
                        'revoked_at' => now(),
                        'record_version' => DB::raw('record_version + 1'),
                        'updated_at' => now(),
                    ]);
            }
            $result = $this->result('user', $userId, $data['status'], $version);
            $this->record('UPDATE_USER', 'foundation.user.updated', 'user', $userId,
                $data['company_id'], $data['plant_id'], $data, $version, $this->diff($user, $changes), $result);

            return $result;
        });
    }

    public function resetUserPassword(string $userId, array $data): array
    {
        return $this->execute("foundation.user.password-reset.{$userId}", $data, function () use ($userId, $data) {
            $user = $this->scopedUser($userId, $data, true);
            $this->assertVersion($user, $data['expected_version'], 'user');
            if ($userId === $data['actor_id']) {
                throw ValidationException::withMessages([
                    'user' => ['Use Account security to change your own password.'],
                ]);
            }
            if ($user->status !== 'ACTIVE' || $user->deleted_at !== null) {
                throw ValidationException::withMessages([
                    'user' => ['Only an active user can receive a temporary password.'],
                ]);
            }
            if (Hash::check($data['temporary_password'], (string) $user->password_hash)) {
                throw ValidationException::withMessages([
                    'temporary_password' => ['Choose a temporary password different from the current password.'],
                ]);
            }

            $passwordChangeWasRequired = $user->password_changed_at === null;
            $now = now();
            $version = (int) $user->record_version + 1;
            DB::table('users')->where('id', $userId)->update([
                'password_hash' => Hash::make($data['temporary_password']),
                'password_changed_at' => null,
                'record_version' => $version,
                'updated_at' => $now,
            ]);
            $revokedSessions = DB::table('user_sessions')->where('user_id', $userId)
                ->whereNull('revoked_at')->update([
                    'revoked_at' => $now,
                    'revoked_by_user_id' => $data['actor_id'],
                    'revoke_reason' => 'TEMPORARY_PASSWORD_ISSUED',
                    'record_version' => DB::raw('record_version + 1'),
                    'updated_at' => $now,
                ]);
            $revokedTokens = DB::table('identity_tokens')->where('user_id', $userId)
                ->whereNull('used_at')->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'updated_at' => $now]);

            $result = $this->result('user', $userId, 'ACTIVE', $version, [
                'password_change_required' => true,
                'sessions_revoked' => $revokedSessions,
                'tokens_revoked' => $revokedTokens,
            ]);
            $this->record('RESET_USER_PASSWORD', 'foundation.user.temporary-password-issued', 'user', $userId,
                $data['company_id'], $data['plant_id'], $data, $version, [
                    'password_changed_at' => ['from' => $user->password_changed_at, 'to' => null],
                    'password_change_required' => ['from' => $passwordChangeWasRequired, 'to' => true],
                    'sessions_revoked' => ['from' => 0, 'to' => $revokedSessions],
                    'tokens_revoked' => ['from' => 0, 'to' => $revokedTokens],
                ], $result);

            return $result;
        });
    }

    public function setUserMfaRequirement(string $userId, array $data): array
    {
        return $this->execute("foundation.user.mfa.{$userId}", $data, function () use ($userId, $data) {
            $user = $this->scopedUser($userId, $data, true);
            $this->assertVersion($user, $data['expected_version'], 'user');
            if ($user->status !== 'ACTIVE' || $user->deleted_at !== null) {
                throw ValidationException::withMessages([
                    'user' => ['MFA policy can only be changed for an active user.'],
                ]);
            }

            $required = (bool) $data['required'];
            if (! $required) {
                if ($userId === $data['actor_id']) {
                    throw ValidationException::withMessages([
                        'user' => ['Use Account security to change MFA on your own account.'],
                    ]);
                }
                if ($this->userRequiresRoleMfa($userId)) {
                    throw ValidationException::withMessages([
                        'user' => ['MFA cannot be disabled while this user has an active ERP Administrator role.'],
                    ]);
                }
            }

            $now = now();
            $version = (int) $user->record_version + 1;
            $changes = [
                'mfa_required_by_admin' => $required,
                'record_version' => $version,
                'updated_at' => $now,
            ];
            if (! $required) {
                $changes['mfa_secret'] = null;
                $changes['mfa_enabled_at'] = null;
            }
            DB::table('users')->where('id', $userId)->update($changes);
            $recoveryCodesRemoved = $required ? 0 : DB::table('user_mfa_recovery_codes')
                ->where('user_id', $userId)->delete();
            $revokedSessions = DB::table('user_sessions')->where('user_id', $userId)
                ->whereNull('revoked_at')->update([
                    'revoked_at' => $now,
                    'revoked_by_user_id' => $data['actor_id'],
                    'revoke_reason' => $required ? 'ADMIN_MFA_REQUIRED' : 'ADMIN_MFA_DISABLED',
                    'record_version' => DB::raw('record_version + 1'),
                    'updated_at' => $now,
                ]);

            $command = $required ? 'ENABLE_USER_MFA' : 'DISABLE_USER_MFA';
            $result = $this->result('user', $userId, 'ACTIVE', $version, [
                'mfa_required' => $required,
                'mfa_enabled' => $required && $user->mfa_enabled_at !== null,
                'sessions_revoked' => $revokedSessions,
            ]);
            $this->record($command, 'foundation.user.mfa-policy.updated', 'user', $userId,
                $data['company_id'], $data['plant_id'], $data, $version, [
                    'mfa_required_by_admin' => ['from' => (bool) $user->mfa_required_by_admin, 'to' => $required],
                    'authenticator_enrolment_removed' => ['from' => false, 'to' => ! $required && $user->mfa_enabled_at !== null],
                    'recovery_codes_removed' => ['from' => 0, 'to' => $recoveryCodesRemoved],
                    'sessions_revoked' => ['from' => 0, 'to' => $revokedSessions],
                ], $result);

            return $result;
        });
    }

    public function deleteUser(string $userId, array $data): array
    {
        return $this->execute("foundation.user.delete.{$userId}", $data, function () use ($userId, $data) {
            $user = $this->scopedUser($userId, $data, true);
            $this->assertVersion($user, $data['expected_version'], 'user');
            if ($user->deleted_at !== null) {
                throw ValidationException::withMessages(['user' => ['This user is already deleted.']]);
            }
            if ($userId === $data['actor_id']) {
                throw ValidationException::withMessages(['user' => ['You cannot delete your own signed-in account.']]);
            }
            if (DB::table('work_items')->where('assigned_user_id', $userId)
                ->where('status', 'OPEN')->exists()) {
                throw ValidationException::withMessages([
                    'user' => ['Reassign or close this user\'s open work before deleting the account.'],
                ]);
            }
            $this->assertDeletionKeepsAdministratorCoverage($userId, (string) $user->status);

            $now = now();
            $version = (int) $user->record_version + 1;
            $revokedAssignments = DB::table('role_assignments')->where('user_id', $userId)
                ->where('is_active', true)->update([
                    'is_active' => false,
                    'record_version' => DB::raw('record_version + 1'),
                    'updated_at' => $now,
                ]);
            $revokedSessions = DB::table('user_sessions')->where('user_id', $userId)
                ->whereNull('revoked_at')->update([
                    'revoked_at' => $now,
                    'revoked_by_user_id' => $data['actor_id'],
                    'revoke_reason' => 'USER_DELETED',
                    'record_version' => DB::raw('record_version + 1'),
                    'updated_at' => $now,
                ]);
            $revokedTokens = DB::table('identity_tokens')->where('user_id', $userId)
                ->whereNull('used_at')->whereNull('revoked_at')
                ->update(['revoked_at' => $now, 'updated_at' => $now]);
            $revokedInvitations = DB::table('identity_invitations')->where('user_id', $userId)
                ->whereNull('accepted_at')->whereNull('revoked_at')->update([
                    'revoked_at' => $now,
                    'record_version' => DB::raw('record_version + 1'),
                    'updated_at' => $now,
                ]);
            $recoveryCodesRemoved = DB::table('user_mfa_recovery_codes')
                ->where('user_id', $userId)->delete();
            DB::table('users')->where('id', $userId)->update([
                'password_hash' => Hash::make(Str::random(64)),
                'status' => 'INACTIVE',
                'mfa_secret' => null,
                'mfa_enabled_at' => null,
                'mfa_required_by_admin' => false,
                'deleted_at' => $now,
                'record_version' => $version,
                'updated_at' => $now,
            ]);

            $result = $this->result('user', $userId, 'DELETED', $version, [
                'deleted' => true,
                'assignments_revoked' => $revokedAssignments,
                'sessions_revoked' => $revokedSessions,
            ]);
            $this->record('DELETE_USER', 'foundation.user.deleted', 'user', $userId,
                $data['company_id'], $data['plant_id'], $data, $version, [
                    'status' => ['from' => $user->status, 'to' => 'INACTIVE'],
                    'deleted_at' => ['from' => null, 'to' => $now->toISOString()],
                    'assignments_revoked' => ['from' => 0, 'to' => $revokedAssignments],
                    'sessions_revoked' => ['from' => 0, 'to' => $revokedSessions],
                    'tokens_revoked' => ['from' => 0, 'to' => $revokedTokens],
                    'invitations_revoked' => ['from' => 0, 'to' => $revokedInvitations],
                    'recovery_codes_removed' => ['from' => 0, 'to' => $recoveryCodesRemoved],
                ], $result);

            return $result;
        });
    }

    public function createRoleAssignment(string $userId, array $data): array
    {
        return $this->execute("foundation.role-assignment.create.{$userId}", $data,
            function () use ($userId, $data) {
                $this->scopedUser($userId, $data, true);
                $role = $this->scopedRole($data['role_id'], $data, true);
                if ($role->status !== 'ACTIVE') {
                    throw ValidationException::withMessages(['role_id' => ['Select an active role.']]);
                }
                $this->assertNoActiveAssignment($userId, $data['role_id'], null, $data);

                $id = (string) Str::uuid();
                $now = now();
                DB::table('role_assignments')->insert([
                    'id' => $id,
                    'user_id' => $userId,
                    'role_id' => $data['role_id'],
                    'company_id' => $data['company_id'],
                    'plant_id' => $data['plant_id'],
                    'party_id' => null,
                    'is_active' => true,
                    'effective_from' => $data['effective_from'] ?? null,
                    'effective_to' => $data['effective_to'] ?? null,
                    'record_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $result = $this->result('role_assignment', $id, 'ACTIVE', 1, ['user_id' => $userId]);
                $this->record('ASSIGN_ROLE', 'foundation.role-assignment.created', 'role_assignment', $id,
                    $data['company_id'], $data['plant_id'], $data, 1,
                    ['created' => ['user_id' => $userId, 'role_id' => $data['role_id']]], $result);

                return $result;
            });
    }

    public function updateRoleAssignment(string $assignmentId, array $data): array
    {
        return $this->execute("foundation.role-assignment.update.{$assignmentId}", $data,
            function () use ($assignmentId, $data) {
                $assignment = $this->scopedAssignment($assignmentId, $data, true);
                $this->assertVersion($assignment, $data['expected_version'], 'role assignment');
                $role = $this->scopedRole($data['role_id'], $data, true);
                if ($data['is_active'] && $role->status !== 'ACTIVE') {
                    throw ValidationException::withMessages(['role_id' => ['An active assignment requires an active role.']]);
                }
                if (! $data['is_active'] && (string) $assignment->user_id === $data['actor_id']) {
                    throw ValidationException::withMessages([
                        'is_active' => ['You cannot deactivate your own current role assignment.'],
                    ]);
                }
                if ($data['is_active']) {
                    $this->assertNoActiveAssignment(
                        (string) $assignment->user_id,
                        $data['role_id'],
                        $assignmentId,
                        $data
                    );
                }

                $changes = [
                    'role_id' => $data['role_id'],
                    'is_active' => $data['is_active'],
                    'effective_from' => $data['effective_from'] ?? null,
                    'effective_to' => $data['effective_to'] ?? null,
                ];
                $version = (int) $assignment->record_version + 1;
                DB::table('role_assignments')->where('id', $assignmentId)->update($changes + [
                    'record_version' => $version,
                    'updated_at' => now(),
                ]);
                $status = $data['is_active'] ? 'ACTIVE' : 'INACTIVE';
                $result = $this->result('role_assignment', $assignmentId, $status, $version, [
                    'user_id' => (string) $assignment->user_id,
                ]);
                $this->record('UPDATE_ROLE_ASSIGNMENT', 'foundation.role-assignment.updated',
                    'role_assignment', $assignmentId, $data['company_id'], $data['plant_id'], $data,
                    $version, $this->diff($assignment, $changes), $result);

                return $result;
            });
    }

    public function createRole(array $data): array
    {
        return $this->execute('foundation.role.create.'.$data['company_id'], $data, function () use ($data) {
            $this->assertUnique('roles', 'code', $data['code'], 'code',
                'A role with this code already exists.');
            $id = (string) Str::uuid();
            $now = now();
            DB::table('roles')->insert([
                'id' => $id,
                'company_id' => $data['company_id'],
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => 'ACTIVE',
                'is_system' => false,
                'record_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $result = $this->result('role', $id, 'ACTIVE', 1);
            $this->record('CREATE_ROLE', 'foundation.role.created', 'role', $id,
                $data['company_id'], $data['plant_id'], $data, 1, ['created' => ['code' => $data['code']]], $result);

            return $result;
        });
    }

    public function updateRole(string $roleId, array $data): array
    {
        return $this->execute("foundation.role.update.{$roleId}", $data, function () use ($roleId, $data) {
            $role = $this->scopedCustomRole($roleId, $data, true);
            $this->assertVersion($role, $data['expected_version'], 'role');
            if ($data['status'] === 'INACTIVE' && DB::table('role_assignments')
                ->where('role_id', $roleId)->where('company_id', $data['company_id'])
                ->where('is_active', true)->exists()) {
                throw ValidationException::withMessages([
                    'status' => ['Deactivate active assignments before deactivating this role.'],
                ]);
            }

            $changes = [
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'],
            ];
            $version = (int) $role->record_version + 1;
            DB::table('roles')->where('id', $roleId)->update($changes + [
                'record_version' => $version,
                'updated_at' => now(),
            ]);
            $result = $this->result('role', $roleId, $data['status'], $version);
            $this->record('UPDATE_ROLE', 'foundation.role.updated', 'role', $roleId,
                $data['company_id'], $data['plant_id'], $data, $version, $this->diff($role, $changes), $result);

            return $result;
        });
    }

    public function syncRolePermissions(string $roleId, array $data): array
    {
        return $this->execute("foundation.role.permissions.{$roleId}", $data, function () use ($roleId, $data) {
            $role = $this->scopedCustomRole($roleId, $data, true);
            $this->assertVersion($role, $data['expected_version'], 'role');
            $permissionIds = array_values(array_unique($data['permission_ids']));
            $visible = DB::table('permissions')->whereIn('id', $permissionIds)
                ->where('status', 'ACTIVE')
                ->where(fn ($query) => $query->whereNull('company_id')
                    ->orWhere('company_id', $data['company_id']))
                ->pluck('id')->map(fn ($id) => (string) $id)->all();
            if (count($visible) !== count($permissionIds)) {
                throw ValidationException::withMessages([
                    'permission_ids' => ['Every selected permission must be active and visible in this organisation.'],
                ]);
            }

            $before = DB::table('role_permissions')->where('role_id', $roleId)
                ->orderBy('permission_id')->pluck('permission_id')->map(fn ($id) => (string) $id)->all();
            DB::table('role_permissions')->where('role_id', $roleId)->delete();
            if ($permissionIds !== []) {
                DB::table('role_permissions')->insert(array_map(fn (string $permissionId) => [
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ], $permissionIds));
            }
            $version = (int) $role->record_version + 1;
            DB::table('roles')->where('id', $roleId)->update([
                'record_version' => $version,
                'updated_at' => now(),
            ]);
            $result = $this->result('role', $roleId, $role->status, $version, [
                'permission_ids' => $permissionIds,
            ]);
            $this->record('SYNC_ROLE_PERMISSIONS', 'foundation.role.permissions-updated', 'role', $roleId,
                $data['company_id'], $data['plant_id'], $data, $version,
                ['permission_ids' => ['from' => $before, 'to' => $permissionIds]], $result);

            return $result;
        });
    }

    public function createPermission(array $data): array
    {
        return $this->execute('foundation.permission.create.'.$data['company_id'], $data, function () use ($data) {
            $this->assertUnique('permissions', 'code', $data['code'], 'code',
                'A permission with this code already exists.');
            $id = (string) Str::uuid();
            $now = now();
            DB::table('permissions')->insert([
                'id' => $id,
                'company_id' => $data['company_id'],
                'code' => $data['code'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'status' => 'ACTIVE',
                'is_system' => false,
                'record_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $result = $this->result('permission', $id, 'ACTIVE', 1);
            $this->record('CREATE_PERMISSION', 'foundation.permission.created', 'permission', $id,
                $data['company_id'], $data['plant_id'], $data, 1, ['created' => ['code' => $data['code']]], $result);

            return $result;
        });
    }

    public function updatePermission(string $permissionId, array $data): array
    {
        return $this->execute("foundation.permission.update.{$permissionId}", $data,
            function () use ($permissionId, $data) {
                $permission = $this->scopedCustomPermission($permissionId, $data, true);
                $this->assertVersion($permission, $data['expected_version'], 'permission');
                if ($data['status'] === 'INACTIVE' && DB::table('role_permissions')
                    ->where('permission_id', $permissionId)->exists()) {
                    throw ValidationException::withMessages([
                        'status' => ['Remove this permission from every role before deactivating it.'],
                    ]);
                }

                $changes = [
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'status' => $data['status'],
                ];
                $version = (int) $permission->record_version + 1;
                DB::table('permissions')->where('id', $permissionId)->update($changes + [
                    'record_version' => $version,
                    'updated_at' => now(),
                ]);
                $result = $this->result('permission', $permissionId, $data['status'], $version);
                $this->record('UPDATE_PERMISSION', 'foundation.permission.updated', 'permission', $permissionId,
                    $data['company_id'], $data['plant_id'], $data, $version,
                    $this->diff($permission, $changes), $result);

                return $result;
            });
    }

    private function execute(string $namespace, array $data, Closure $command): array
    {
        return DB::transaction(function () use ($namespace, $data, $command) {
            $payload = Arr::except($data, [
                'permissions', 'idempotency_key', 'correlation_id',
            ]);
            $existing = $this->idempotency->begin($namespace, $data['idempotency_key'], $payload);
            if ($existing !== null) {
                return $existing;
            }

            $result = $command();
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        });
    }

    private function scopedCompany(string $companyId, array $data, bool $lock = false): object
    {
        $query = DB::table('companies')->where('id', $companyId)
            ->where('id', $data['company_id']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $company = $query->first();
        if (! $company) {
            throw new NotFoundHttpException('Organisation not found.');
        }

        return $company;
    }

    private function scopedPlant(string $plantId, array $data, bool $lock = false): object
    {
        $query = DB::table('plants')->where('id', $plantId)
            ->where('company_id', $data['company_id']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $plant = $query->first();
        if (! $plant) {
            throw new NotFoundHttpException('Plant not found.');
        }

        return $plant;
    }

    private function scopedLocation(string $locationId, array $data, bool $lock = false): object
    {
        $query = DB::table('locations')->where('id', $locationId)
            ->where('company_id', $data['company_id'])
            ->where('plant_id', $data['plant_id']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $location = $query->first();
        if (! $location) {
            throw new NotFoundHttpException('Location not found.');
        }

        return $location;
    }

    private function scopedUser(string $userId, array $data, bool $lock = false): object
    {
        $query = DB::table('users as user')->where('user.id', $userId)
            ->whereExists(fn ($assignment) => $assignment->selectRaw('1')
                ->from('role_assignments as scoped_assignment')
                ->whereColumn('scoped_assignment.user_id', 'user.id')
                ->where('scoped_assignment.company_id', $data['company_id'])
                ->where('scoped_assignment.plant_id', $data['plant_id']));
        if ($lock) {
            $query->lockForUpdate();
        }
        $user = $query->first();
        if (! $user) {
            throw new NotFoundHttpException('User not found.');
        }

        return $user;
    }

    private function scopedRole(string $roleId, array $data, bool $lock = false): object
    {
        $query = DB::table('roles')->where('id', $roleId)
            ->where(fn ($query) => $query->whereNull('company_id')
                ->orWhere('company_id', $data['company_id']));
        if ($lock) {
            $query->lockForUpdate();
        }
        $role = $query->first();
        if (! $role) {
            throw new NotFoundHttpException('Role not found.');
        }

        return $role;
    }

    private function scopedCustomRole(string $roleId, array $data, bool $lock = false): object
    {
        $role = $this->scopedRole($roleId, $data, $lock);
        if ((bool) $role->is_system || $role->company_id !== $data['company_id']) {
            throw ValidationException::withMessages([
                'role' => ['Protected system roles cannot be changed. Create a scoped custom role instead.'],
            ]);
        }

        return $role;
    }

    private function scopedCustomPermission(string $permissionId, array $data, bool $lock = false): object
    {
        $query = DB::table('permissions')->where('id', $permissionId)
            ->where(fn ($query) => $query->whereNull('company_id')
                ->orWhere('company_id', $data['company_id']));
        if ($lock) {
            $query->lockForUpdate();
        }
        $permission = $query->first();
        if (! $permission) {
            throw new NotFoundHttpException('Permission not found.');
        }
        if ((bool) $permission->is_system || $permission->company_id !== $data['company_id']) {
            throw ValidationException::withMessages([
                'permission' => ['Protected system permissions cannot be changed. Create a scoped custom permission instead.'],
            ]);
        }

        return $permission;
    }

    private function scopedAssignment(string $assignmentId, array $data, bool $lock = false): object
    {
        $query = DB::table('role_assignments')->where('id', $assignmentId)
            ->where('company_id', $data['company_id'])
            ->where('plant_id', $data['plant_id']);
        if ($lock) {
            $query->lockForUpdate();
        }
        $assignment = $query->first();
        if (! $assignment) {
            throw new NotFoundHttpException('Role assignment not found.');
        }

        return $assignment;
    }

    private function assertPlantContext(array $data): void
    {
        if (! is_string($data['plant_id'] ?? null)) {
            throw ValidationException::withMessages([
                'context' => ['Select a plant before administering locations, users, or assignments.'],
            ]);
        }
        $plant = DB::table('plants')->where('id', $data['plant_id'])
            ->where('company_id', $data['company_id'])->first();
        if (! $plant || $plant->status === 'INACTIVE') {
            throw ValidationException::withMessages([
                'context' => ['The selected plant is not active in this organisation.'],
            ]);
        }
    }

    private function assertLocationParent(?string $parentId, ?string $locationId, array $data): void
    {
        if ($parentId === null) {
            return;
        }
        if ($parentId === $locationId) {
            throw ValidationException::withMessages([
                'parent_location_id' => ['A location cannot be its own parent.'],
            ]);
        }
        $parent = DB::table('locations')->where('id', $parentId)
            ->where('company_id', $data['company_id'])
            ->where('plant_id', $data['plant_id'])
            ->where('status', 'ACTIVE')->first();
        if (! $parent) {
            throw ValidationException::withMessages([
                'parent_location_id' => ['Select an active parent in the current plant.'],
            ]);
        }

        $visited = [];
        $cursor = $parent;
        while ($cursor && $cursor->parent_location_id !== null) {
            if ($cursor->parent_location_id === $locationId) {
                throw ValidationException::withMessages([
                    'parent_location_id' => ['That parent would create a location hierarchy cycle.'],
                ]);
            }
            if (isset($visited[$cursor->parent_location_id])) {
                break;
            }
            $visited[$cursor->parent_location_id] = true;
            $cursor = DB::table('locations')->where('id', $cursor->parent_location_id)
                ->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])->first();
        }
    }

    private function assertNoActiveAssignment(
        string $userId,
        string $roleId,
        ?string $exceptAssignmentId,
        array $data,
    ): void {
        $duplicate = DB::table('role_assignments')
            ->where('user_id', $userId)
            ->where('role_id', $roleId)
            ->where('company_id', $data['company_id'])
            ->where('plant_id', $data['plant_id'])
            ->where('is_active', true)
            ->when($exceptAssignmentId, fn ($query, string $id) => $query->where('id', '<>', $id))
            ->exists();
        if ($duplicate) {
            throw ValidationException::withMessages([
                'role_id' => ['This user already has an active assignment for that role in the selected plant.'],
            ]);
        }
    }

    private function assertErpAdministrator(array $data): void
    {
        $allowed = DB::table('role_assignments as assignment')
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.user_id', $data['actor_id'])
            ->where('assignment.is_active', true)
            ->where('role.code', 'ERP_ADMIN')
            ->where('role.status', 'ACTIVE')
            ->where(fn ($query) => $query->whereNull('assignment.company_id')
                ->orWhere('assignment.company_id', $data['company_id']))
            ->where(fn ($query) => $query->whereNull('assignment.plant_id')
                ->orWhere('assignment.plant_id', $data['plant_id']))
            ->where(fn ($query) => $query->whereNull('assignment.effective_from')
                ->orWhere('assignment.effective_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('assignment.effective_to')
                ->orWhere('assignment.effective_to', '>', now()))
            ->exists();

        if (! $allowed) {
            throw new AuthorizationException('Creating organisations and plants requires the protected ERP Administrator role.');
        }
    }

    private function userRequiresRoleMfa(string $userId): bool
    {
        return DB::table('role_assignments as assignment')
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.user_id', $userId)
            ->where('assignment.is_active', true)
            ->where('role.code', 'ERP_ADMIN')
            ->where('role.status', 'ACTIVE')
            ->where(fn ($query) => $query->whereNull('assignment.effective_from')
                ->orWhere('assignment.effective_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('assignment.effective_to')
                ->orWhere('assignment.effective_to', '>', now()))
            ->exists();
    }

    private function assertDeletionKeepsAdministratorCoverage(string $userId, string $status): void
    {
        if ($status !== 'ACTIVE') {
            return;
        }

        $protectedAssignments = DB::table('role_assignments as assignment')
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.user_id', $userId)
            ->where('assignment.is_active', true)
            ->where('role.code', 'ERP_ADMIN')
            ->where('role.status', 'ACTIVE')
            ->where(fn ($query) => $query->whereNull('assignment.effective_from')
                ->orWhere('assignment.effective_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('assignment.effective_to')
                ->orWhere('assignment.effective_to', '>', now()))
            ->get(['assignment.company_id', 'assignment.plant_id']);

        foreach ($protectedAssignments as $protected) {
            $replacement = DB::table('role_assignments as assignment')
                ->join('roles as role', 'role.id', '=', 'assignment.role_id')
                ->join('users as user', 'user.id', '=', 'assignment.user_id')
                ->where('assignment.user_id', '<>', $userId)
                ->where('assignment.is_active', true)
                ->where('role.code', 'ERP_ADMIN')
                ->where('role.status', 'ACTIVE')
                ->where('user.status', 'ACTIVE')
                ->whereNull('user.deleted_at')
                ->where(fn ($query) => $query->whereNull('assignment.effective_from')
                    ->orWhere('assignment.effective_from', '<=', now()))
                ->where(fn ($query) => $query->whereNull('assignment.effective_to')
                    ->orWhere('assignment.effective_to', '>', now()));

            if ($protected->company_id === null) {
                $replacement->whereNull('assignment.company_id');
            } else {
                $replacement->where(fn ($query) => $query->whereNull('assignment.company_id')
                    ->orWhere('assignment.company_id', $protected->company_id));
            }
            if ($protected->plant_id === null) {
                $replacement->whereNull('assignment.plant_id');
            } else {
                $replacement->where(fn ($query) => $query->whereNull('assignment.plant_id')
                    ->orWhere('assignment.plant_id', $protected->plant_id));
            }

            if (! $replacement->exists()) {
                throw ValidationException::withMessages([
                    'user' => ['Assign another active ERP Administrator in every affected context before deleting this user.'],
                ]);
            }
        }
    }

    private function grantCreatorPlantAdministration(string $plantId, array $data, mixed $now): ?string
    {
        $roleId = DB::table('roles')->where('code', 'ERP_ADMIN')->where('status', 'ACTIVE')->value('id');
        if (! is_string($roleId)) {
            return null;
        }
        $existing = DB::table('role_assignments')->where('user_id', $data['actor_id'])
            ->where('role_id', $roleId)->where('company_id', $data['company_id'])
            ->where('plant_id', $plantId)->where('is_active', true)->value('id');
        if (is_string($existing)) {
            return $existing;
        }

        $id = (string) Str::uuid();
        DB::table('role_assignments')->insert([
            'id' => $id,
            'user_id' => $data['actor_id'],
            'role_id' => $roleId,
            'company_id' => $data['company_id'],
            'plant_id' => $plantId,
            'party_id' => null,
            'is_active' => true,
            'effective_from' => null,
            'effective_to' => null,
            'record_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $assignmentResult = $this->result('role_assignment', $id, 'ACTIVE', 1, [
            'user_id' => $data['actor_id'],
        ]);
        $this->record('ASSIGN_ROLE', 'foundation.role-assignment.created', 'role_assignment', $id,
            $data['company_id'], $plantId, $data, 1,
            ['created' => ['user_id' => $data['actor_id'], 'role_id' => $roleId]], $assignmentResult);

        return $id;
    }

    private function assertVersion(object $entity, int $expectedVersion, string $label): void
    {
        if ((int) $entity->record_version !== $expectedVersion) {
            throw new ConflictHttpException(
                "The {$label} changed from version {$expectedVersion} to {$entity->record_version}. Refresh it before retrying."
            );
        }
    }

    private function assertUnique(
        string $table,
        string $column,
        string $value,
        string $field,
        string $message,
        array $scope = [],
    ): void {
        $query = DB::table($table)->where($column, $value);
        foreach ($scope as $scopeColumn => $scopeValue) {
            $query->where($scopeColumn, $scopeValue);
        }
        if ($query->exists()) {
            throw ValidationException::withMessages([$field => [$message]]);
        }
    }

    private function diff(object $before, array $changes): array
    {
        $diff = [];
        foreach ($changes as $field => $value) {
            $old = $before->{$field} ?? null;
            if ((string) $old !== (string) $value) {
                $diff[$field] = ['from' => $old, 'to' => $value];
            }
        }

        return $diff;
    }

    private function result(
        string $entityType,
        string $id,
        string $status,
        int $version,
        array $extra = [],
    ): array {
        return [
            'entity_type' => $entityType,
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
        string $companyId,
        ?string $plantId,
        array $data,
        int $version,
        array $safeDiff,
        array $result,
    ): void {
        $this->audit->record($command, $entityType, $entityId, $data['actor_id'],
            $companyId, $plantId, 'SUCCESS', [
                'entity_version' => $version,
                'correlation_id' => $data['correlation_id'] ?? null,
                'safe_diff' => $safeDiff,
            ]);
        $this->outbox->append($eventType, $entityType, $entityId, $entityId.':'.$version,
            $result, $data['correlation_id'] ?? null);
    }
}

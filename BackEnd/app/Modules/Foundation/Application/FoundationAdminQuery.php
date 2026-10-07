<?php

namespace App\Modules\Foundation\Application;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FoundationAdminQuery
{
    public function organisation(array $scope, array $permissions): array
    {
        $company = $this->company($scope['company_id'], $scope, $permissions);
        $plants = DB::table('plants as plant')
            ->where('plant.company_id', $scope['company_id'])
            ->select([
                'plant.id', 'plant.company_id', 'plant.code', 'plant.name', 'plant.timezone',
                'plant.status', 'plant.record_version', 'plant.created_at', 'plant.updated_at',
            ])
            ->selectSub(
                DB::table('locations')->selectRaw('COUNT(*)')
                    ->whereColumn('locations.plant_id', 'plant.id'),
                'location_count'
            )
            ->selectSub(
                DB::table('role_assignments')->selectRaw('COUNT(DISTINCT user_id)')
                    ->whereColumn('role_assignments.plant_id', 'plant.id')
                    ->where('role_assignments.is_active', true),
                'active_user_count'
            )
            ->orderBy('plant.code')
            ->get()
            ->map(fn (object $plant) => $this->plantPayload($plant, $permissions))
            ->values()
            ->all();

        return [
            'data' => [
                'company' => $company,
                'plants' => $plants,
                'summary' => [
                    'plant_count' => count($plants),
                    'active_plants' => collect($plants)->where('status', 'ACTIVE')->count(),
                    'location_count' => collect($plants)->sum('location_count'),
                    'active_users' => DB::table('role_assignments')
                        ->where('company_id', $scope['company_id'])
                        ->where('is_active', true)
                        ->distinct()->count('user_id'),
                ],
                'allowed_actions' => array_values(array_filter([
                    $this->can($permissions, 'ACTION:ADM-ORG:CREATE') ? 'CREATE_COMPANY' : null,
                    $this->can($permissions, 'ACTION:ADM-ORG:CREATE') ? 'CREATE_PLANT' : null,
                ])),
            ],
        ];
    }

    public function company(string $companyId, array $scope, array $permissions): array
    {
        if ($companyId !== $scope['company_id']) {
            throw new NotFoundHttpException('Organisation not found.');
        }

        $company = DB::table('companies')->where('id', $companyId)->first();
        if (! $company) {
            throw new NotFoundHttpException('Organisation not found.');
        }

        return [
            'id' => (string) $company->id,
            'code' => $company->code,
            'legal_name' => $company->legal_name,
            'display_name' => $company->display_name,
            'status' => $company->status,
            'record_version' => (int) $company->record_version,
            'allowed_actions' => $this->can($permissions, 'ACTION:ADM-ORG:UPDATE') ? ['UPDATE'] : [],
            'created_at' => $this->timestamp($company->created_at),
            'updated_at' => $this->timestamp($company->updated_at),
        ];
    }

    public function plant(string $plantId, array $scope, array $permissions): array
    {
        $plant = DB::table('plants as plant')
            ->where('plant.id', $plantId)
            ->where('plant.company_id', $scope['company_id'])
            ->select('plant.*')
            ->selectSub(
                DB::table('locations')->selectRaw('COUNT(*)')
                    ->whereColumn('locations.plant_id', 'plant.id'),
                'location_count'
            )
            ->selectSub(
                DB::table('role_assignments')->selectRaw('COUNT(DISTINCT user_id)')
                    ->whereColumn('role_assignments.plant_id', 'plant.id')
                    ->where('role_assignments.is_active', true),
                'active_user_count'
            )
            ->first();

        if (! $plant) {
            throw new NotFoundHttpException('Plant not found.');
        }

        return $this->plantPayload($plant, $permissions);
    }

    public function locations(array $scope, array $filters, array $permissions): array
    {
        $plantId = $this->requirePlant($scope);
        $query = DB::table('locations as location')
            ->leftJoin('locations as parent', 'parent.id', '=', 'location.parent_location_id')
            ->where('location.company_id', $scope['company_id'])
            ->where('location.plant_id', $plantId)
            ->select([
                'location.id', 'location.company_id', 'location.plant_id',
                'location.parent_location_id', 'parent.code as parent_code', 'parent.name as parent_name',
                'location.code', 'location.name', 'location.description', 'location.location_type',
                'location.status', 'location.record_version', 'location.created_at', 'location.updated_at',
            ])
            ->selectSub(
                DB::table('locations as child')->selectRaw('COUNT(*)')
                    ->whereColumn('child.parent_location_id', 'location.id'),
                'child_count'
            )
            ->selectSub(
                DB::table('stock_positions as position')->selectRaw('COUNT(*)')
                    ->whereColumn('position.location_id', 'location.id'),
                'position_count'
            )
            ->selectSub(
                DB::table('stock_positions as position')->selectRaw('COALESCE(SUM(quantity_base), 0)')
                    ->whereColumn('position.location_id', 'location.id'),
                'quantity_on_hand'
            );

        $this->applyTextFilter($query, $filters['q'] ?? null, [
            'location.code', 'location.name', 'location.description',
        ]);
        $query->when($filters['status'] ?? null, fn (Builder $query, string $status) =>
            $query->where('location.status', $status));
        $query->when($filters['location_type'] ?? null, fn (Builder $query, string $type) =>
            $query->where('location.location_type', $type));

        $items = $query->orderBy('location.code')->get()
            ->map(fn (object $location) => $this->locationPayload($location, $permissions))
            ->values()->all();

        return [
            'data' => $items,
            'summary' => [
                'total' => count($items),
                'active' => collect($items)->where('status', 'ACTIVE')->count(),
                'with_stock_positions' => collect($items)->where('position_count', '>', 0)->count(),
                'root_locations' => collect($items)->whereNull('parent')->count(),
            ],
            'lookups' => [
                'parents' => collect($items)->where('status', 'ACTIVE')->map(fn (array $location) => [
                    'id' => $location['id'],
                    'code' => $location['code'],
                    'name' => $location['name'],
                ])->values()->all(),
            ],
            'allowed_actions' => $this->can($permissions, 'ACTION:ADM-LOC:CREATE') ? ['CREATE'] : [],
        ];
    }

    public function location(string $locationId, array $scope, array $permissions): array
    {
        $plantId = $this->requirePlant($scope);
        $location = DB::table('locations as location')
            ->leftJoin('locations as parent', 'parent.id', '=', 'location.parent_location_id')
            ->where('location.id', $locationId)
            ->where('location.company_id', $scope['company_id'])
            ->where('location.plant_id', $plantId)
            ->select([
                'location.id', 'location.company_id', 'location.plant_id',
                'location.parent_location_id', 'parent.code as parent_code', 'parent.name as parent_name',
                'location.code', 'location.name', 'location.description', 'location.location_type',
                'location.status', 'location.record_version', 'location.created_at', 'location.updated_at',
            ])
            ->selectSub(DB::table('locations as child')->selectRaw('COUNT(*)')
                ->whereColumn('child.parent_location_id', 'location.id'), 'child_count')
            ->selectSub(DB::table('stock_positions as position')->selectRaw('COUNT(*)')
                ->whereColumn('position.location_id', 'location.id'), 'position_count')
            ->selectSub(DB::table('stock_positions as position')->selectRaw('COALESCE(SUM(quantity_base), 0)')
                ->whereColumn('position.location_id', 'location.id'), 'quantity_on_hand')
            ->first();

        if (! $location) {
            throw new NotFoundHttpException('Location not found.');
        }

        return $this->locationPayload($location, $permissions);
    }

    public function users(array $scope, array $filters, array $permissions): array
    {
        $plantId = $this->requirePlant($scope);
        $query = DB::table('users as user')
            ->whereExists(fn (Builder $assignment) => $assignment
                ->selectRaw('1')->from('role_assignments as scoped_assignment')
                ->whereColumn('scoped_assignment.user_id', 'user.id')
                ->where('scoped_assignment.company_id', $scope['company_id'])
                ->where('scoped_assignment.plant_id', $plantId))
            ->select('user.*');

        $this->applyTextFilter($query, $filters['q'] ?? null, [
            'user.name', 'user.email', 'user.deleted_email',
        ]);
        $query->when($filters['status'] ?? null, fn (Builder $query, string $status) =>
            $query->where('user.status', $status));

        $items = $query->orderBy('user.name')->get()
            ->map(fn (object $user) => $this->userPayload($user, $scope, $permissions))
            ->values()->all();

        return [
            'data' => $items,
            'summary' => [
                'total' => count($items),
                'active' => collect($items)->where('status', 'ACTIVE')->count(),
                'invited' => collect($items)->where('status', 'INVITED')->count(),
                'unverified' => collect($items)->where('email_verified', false)->count(),
                'active_assignments' => collect($items)->sum('active_assignment_count'),
                'roles_in_use' => collect($items)->flatMap(fn (array $user) =>
                    collect($user['assignments'])->where('is_active', true)->pluck('role.id'))
                    ->unique()->count(),
            ],
            'lookups' => [
                'roles' => $this->roleLookups($scope),
            ],
            'allowed_actions' => array_values(array_filter([
                $this->can($permissions, 'ACTION:ADM-USER:CREATE') ? 'CREATE' : null,
                $this->can($permissions, 'ACTION:ADM-USER:INVITE') ? 'INVITE' : null,
            ])),
        ];
    }

    public function user(string $userId, array $scope, array $permissions): array
    {
        $plantId = $this->requirePlant($scope);
        $user = DB::table('users as user')
            ->where('user.id', $userId)
            ->whereExists(fn (Builder $assignment) => $assignment
                ->selectRaw('1')->from('role_assignments as scoped_assignment')
                ->whereColumn('scoped_assignment.user_id', 'user.id')
                ->where('scoped_assignment.company_id', $scope['company_id'])
                ->where('scoped_assignment.plant_id', $plantId))
            ->first();

        if (! $user) {
            throw new NotFoundHttpException('User not found.');
        }

        return $this->userPayload($user, $scope, $permissions);
    }

    public function assignment(string $assignmentId, array $scope, array $permissions): array
    {
        $assignment = $this->assignmentQuery($scope)
            ->where('assignment.id', $assignmentId)->first();
        if (! $assignment) {
            throw new NotFoundHttpException('Role assignment not found.');
        }

        return $this->assignmentPayload($assignment, $permissions);
    }

    public function roles(array $scope, array $filters, array $permissions): array
    {
        $roleQuery = $this->visibleRoles($scope)->select('role.*');
        $this->applyTextFilter($roleQuery, $filters['q'] ?? null, [
            'role.code', 'role.name', 'role.description',
        ]);
        $roleQuery->when($filters['status'] ?? null, fn (Builder $query, string $status) =>
            $query->where('role.status', $status));

        $roles = $roleQuery->orderByDesc('role.is_system')->orderBy('role.code')->get()
            ->map(fn (object $role) => $this->rolePayload($role, $scope, $permissions))
            ->values()->all();

        $permissionItems = $this->visiblePermissions($scope)->select('permission.*')
            ->orderBy('permission.code')->get()
            ->map(fn (object $permission) => $this->permissionPayload($permission, $scope, $permissions))
            ->values()->all();

        return [
            'data' => $roles,
            'permissions' => $permissionItems,
            'summary' => [
                'roles' => count($roles),
                'custom_roles' => collect($roles)->where('is_system', false)->count(),
                'permissions' => count($permissionItems),
                'active_assignments' => collect($roles)->sum('active_assignment_count'),
            ],
            'allowed_actions' => array_values(array_filter([
                $this->can($permissions, 'ACTION:ADM-ROLE:CREATE') ? 'CREATE_ROLE' : null,
                $this->can($permissions, 'ACTION:ADM-ROLE:CREATE') ? 'CREATE_PERMISSION' : null,
            ])),
        ];
    }

    public function role(string $roleId, array $scope, array $permissions): array
    {
        $role = $this->visibleRoles($scope)->where('role.id', $roleId)->select('role.*')->first();
        if (! $role) {
            throw new NotFoundHttpException('Role not found.');
        }

        return $this->rolePayload($role, $scope, $permissions);
    }

    public function permission(string $permissionId, array $scope, array $permissions): array
    {
        $permission = $this->visiblePermissions($scope)
            ->where('permission.id', $permissionId)->select('permission.*')->first();
        if (! $permission) {
            throw new NotFoundHttpException('Permission not found.');
        }

        return $this->permissionPayload($permission, $scope, $permissions);
    }

    public function permissions(array $scope, array $filters, array $permissions): array
    {
        $query = $this->visiblePermissions($scope)->select('permission.*');
        $this->applyTextFilter($query, $filters['q'] ?? null, [
            'permission.code', 'permission.name', 'permission.description',
        ]);
        $query->when($filters['status'] ?? null, fn (Builder $query, string $status) =>
            $query->where('permission.status', $status));

        $items = $query->orderByDesc('permission.is_system')->orderBy('permission.code')->get()
            ->map(fn (object $permission) => $this->permissionPayload($permission, $scope, $permissions))
            ->values()->all();

        return [
            'data' => $items,
            'summary' => [
                'total' => count($items),
                'custom' => collect($items)->where('is_system', false)->count(),
                'active' => collect($items)->where('status', 'ACTIVE')->count(),
            ],
            'allowed_actions' => $this->can($permissions, 'ACTION:ADM-ROLE:CREATE') ? ['CREATE'] : [],
        ];
    }

    private function plantPayload(object $plant, array $permissions): array
    {
        return [
            'id' => (string) $plant->id,
            'company_id' => (string) $plant->company_id,
            'code' => $plant->code,
            'name' => $plant->name,
            'timezone' => $plant->timezone,
            'status' => $plant->status,
            'location_count' => (int) ($plant->location_count ?? 0),
            'active_user_count' => (int) ($plant->active_user_count ?? 0),
            'record_version' => (int) $plant->record_version,
            'allowed_actions' => $this->can($permissions, 'ACTION:ADM-ORG:UPDATE') ? ['UPDATE'] : [],
            'created_at' => $this->timestamp($plant->created_at),
            'updated_at' => $this->timestamp($plant->updated_at),
        ];
    }

    private function locationPayload(object $location, array $permissions): array
    {
        return [
            'id' => (string) $location->id,
            'company_id' => (string) $location->company_id,
            'plant_id' => (string) $location->plant_id,
            'code' => $location->code,
            'name' => $location->name,
            'description' => $location->description,
            'location_type' => $location->location_type,
            'status' => $location->status,
            'parent' => $location->parent_location_id === null ? null : [
                'id' => (string) $location->parent_location_id,
                'code' => $location->parent_code,
                'name' => $location->parent_name,
            ],
            'child_count' => (int) $location->child_count,
            'position_count' => (int) $location->position_count,
            'quantity_on_hand' => number_format((float) $location->quantity_on_hand, 6, '.', ''),
            'record_version' => (int) $location->record_version,
            'allowed_actions' => $this->can($permissions, 'ACTION:ADM-LOC:UPDATE') ? ['UPDATE'] : [],
            'created_at' => $this->timestamp($location->created_at),
            'updated_at' => $this->timestamp($location->updated_at),
        ];
    }

    private function userPayload(object $user, array $scope, array $permissions): array
    {
        $assignments = $this->assignmentQuery($scope)
            ->where('assignment.user_id', $user->id)
            ->orderByDesc('assignment.is_active')->orderBy('role.name')->get()
            ->map(fn (object $assignment) => $this->assignmentPayload($assignment, $permissions))
            ->values()->all();

        $invitation = DB::table('identity_invitations')
            ->where('user_id', $user->id)
            ->where('company_id', $scope['company_id'])
            ->where('plant_id', $this->requirePlant($scope))
            ->orderByDesc('created_at')
            ->first();
        $activeSessions = DB::table('user_sessions')
            ->where('user_id', $user->id)
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->count();
        $deleted = $user->deleted_at !== null;
        $roleMfaRequired = $this->userRequiresRoleMfa((string) $user->id);
        $mfaRequired = (bool) $user->mfa_required_by_admin || $roleMfaRequired;

        return [
            'id' => (string) $user->id,
            'email' => $deleted && $user->deleted_email !== null
                ? $user->deleted_email
                : $user->email,
            'name' => $user->name,
            'status' => $user->status,
            'email_verified' => $user->email_verified_at !== null,
            'email_verified_at' => $this->timestamp($user->email_verified_at),
            'mfa_enabled' => $user->mfa_enabled_at !== null,
            'mfa_enabled_at' => $this->timestamp($user->mfa_enabled_at),
            'mfa_required' => $mfaRequired,
            'mfa_requirement_source' => $roleMfaRequired ? 'ROLE' : ((bool) $user->mfa_required_by_admin ? 'ADMIN' : 'NONE'),
            'password_change_required' => $user->password_changed_at === null,
            'deleted_at' => $this->timestamp($user->deleted_at),
            'last_login_at' => $this->timestamp($user->last_login_at),
            'active_session_count' => $activeSessions,
            'invitation' => $invitation ? [
                'id' => (string) $invitation->id,
                'status' => $invitation->accepted_at !== null
                    ? 'ACCEPTED'
                    : ($invitation->revoked_at !== null
                        ? 'REVOKED'
                        : (now()->greaterThanOrEqualTo($invitation->expires_at) ? 'EXPIRED' : 'PENDING')),
                'expires_at' => $this->timestamp($invitation->expires_at),
                'last_sent_at' => $this->timestamp($invitation->last_sent_at),
                'last_delivery_status' => $invitation->last_delivery_status,
                'delivery_count' => (int) $invitation->delivery_count,
                'record_version' => (int) $invitation->record_version,
            ] : null,
            'assignments' => $assignments,
            'active_assignment_count' => collect($assignments)->where('is_active', true)->count(),
            'record_version' => (int) $user->record_version,
            'allowed_actions' => array_values(array_filter([
                ! $deleted && $this->can($permissions, 'ACTION:ADM-USER:UPDATE') ? 'UPDATE' : null,
                ! $deleted && $this->can($permissions, 'ACTION:ADM-USER:ASSIGN') ? 'ASSIGN_ROLE' : null,
                $this->can($permissions, 'ACTION:ADM-USER:INVITE') && $user->status === 'INVITED'
                    && ! $deleted
                    ? 'MANAGE_INVITATION' : null,
                $this->can($permissions, 'ACTION:ADM-USER:INVITE')
                    && ! $deleted && $user->status === 'ACTIVE' && $user->email_verified_at === null
                    ? 'SEND_VERIFICATION' : null,
                ! $deleted && $this->can($permissions, 'ACTION:ADM-USER:SESSIONS') ? 'MANAGE_SESSIONS' : null,
                ! $deleted && $user->status === 'ACTIVE'
                    && $this->can($permissions, 'ACTION:ADM-USER:PASSWORD-RESET') ? 'RESET_PASSWORD' : null,
                ! $deleted && $user->status === 'ACTIVE'
                    && $this->can($permissions, 'ACTION:ADM-USER:MFA') ? 'MANAGE_MFA' : null,
                ! $deleted && $this->can($permissions, 'ACTION:ADM-USER:DELETE') ? 'DELETE' : null,
            ])),
            'created_at' => $this->timestamp($user->created_at),
            'updated_at' => $this->timestamp($user->updated_at),
        ];
    }

    private function assignmentQuery(array $scope): Builder
    {
        return DB::table('role_assignments as assignment')
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->join('users as user', 'user.id', '=', 'assignment.user_id')
            ->join('companies as company', 'company.id', '=', 'assignment.company_id')
            ->join('plants as plant', 'plant.id', '=', 'assignment.plant_id')
            ->where('assignment.company_id', $scope['company_id'])
            ->where('assignment.plant_id', $this->requirePlant($scope))
            ->select([
                'assignment.*', 'role.code as role_code', 'role.name as role_name',
                'role.status as role_status', 'user.name as user_name', 'user.email as user_email',
                'company.display_name as company_name', 'plant.code as plant_code', 'plant.name as plant_name',
            ]);
    }

    private function assignmentPayload(object $assignment, array $permissions): array
    {
        return [
            'id' => (string) $assignment->id,
            'user' => [
                'id' => (string) $assignment->user_id,
                'name' => $assignment->user_name,
                'email' => $assignment->user_email,
            ],
            'role' => [
                'id' => (string) $assignment->role_id,
                'code' => $assignment->role_code,
                'name' => $assignment->role_name,
                'status' => $assignment->role_status,
            ],
            'company' => [
                'id' => (string) $assignment->company_id,
                'name' => $assignment->company_name,
            ],
            'plant' => [
                'id' => (string) $assignment->plant_id,
                'code' => $assignment->plant_code,
                'name' => $assignment->plant_name,
            ],
            'is_active' => (bool) $assignment->is_active,
            'effective_from' => $this->timestamp($assignment->effective_from),
            'effective_to' => $this->timestamp($assignment->effective_to),
            'record_version' => (int) $assignment->record_version,
            'allowed_actions' => $this->can($permissions, 'ACTION:ADM-USER:ASSIGN') ? ['UPDATE'] : [],
            'created_at' => $this->timestamp($assignment->created_at),
            'updated_at' => $this->timestamp($assignment->updated_at),
        ];
    }

    private function rolePayload(object $role, array $scope, array $permissions): array
    {
        $permissionIds = DB::table('role_permissions as role_permission')
            ->join('permissions as permission', 'permission.id', '=', 'role_permission.permission_id')
            ->where('role_permission.role_id', $role->id)
            ->where(fn (Builder $query) => $query
                ->whereNull('permission.company_id')
                ->orWhere('permission.company_id', $scope['company_id']))
            ->orderBy('permission.code')
            ->pluck('permission.id')->map(fn ($id) => (string) $id)->all();

        $assignmentCount = DB::table('role_assignments')
            ->where('role_id', $role->id)
            ->where('company_id', $scope['company_id'])
            ->where('plant_id', $this->requirePlant($scope))
            ->where('is_active', true)->count();

        $custom = ! (bool) $role->is_system && $role->company_id === $scope['company_id'];

        return [
            'id' => (string) $role->id,
            'company_id' => $role->company_id === null ? null : (string) $role->company_id,
            'code' => $role->code,
            'name' => $role->name,
            'description' => $role->description,
            'status' => $role->status,
            'is_system' => (bool) $role->is_system,
            'permission_ids' => $permissionIds,
            'permission_count' => count($permissionIds),
            'active_assignment_count' => $assignmentCount,
            'record_version' => (int) $role->record_version,
            'allowed_actions' => array_values(array_filter([
                $custom && $this->can($permissions, 'ACTION:ADM-ROLE:UPDATE') ? 'UPDATE' : null,
                $custom && $this->can($permissions, 'ACTION:ADM-ROLE:PERMISSIONS') ? 'SYNC_PERMISSIONS' : null,
            ])),
            'created_at' => $this->timestamp($role->created_at),
            'updated_at' => $this->timestamp($role->updated_at),
        ];
    }

    private function permissionPayload(object $permission, array $scope, array $permissions): array
    {
        $roleCount = DB::table('role_permissions as role_permission')
            ->join('roles as role', 'role.id', '=', 'role_permission.role_id')
            ->where('role_permission.permission_id', $permission->id)
            ->where(fn (Builder $query) => $query
                ->whereNull('role.company_id')->orWhere('role.company_id', $scope['company_id']))
            ->count();
        $custom = ! (bool) $permission->is_system
            && $permission->company_id === $scope['company_id'];

        return [
            'id' => (string) $permission->id,
            'company_id' => $permission->company_id === null ? null : (string) $permission->company_id,
            'code' => $permission->code,
            'name' => $permission->name,
            'description' => $permission->description,
            'status' => $permission->status,
            'is_system' => (bool) $permission->is_system,
            'role_count' => $roleCount,
            'record_version' => (int) $permission->record_version,
            'allowed_actions' => $custom && $this->can($permissions, 'ACTION:ADM-ROLE:UPDATE')
                ? ['UPDATE'] : [],
            'created_at' => $this->timestamp($permission->created_at),
            'updated_at' => $this->timestamp($permission->updated_at),
        ];
    }

    private function roleLookups(array $scope): array
    {
        return $this->visibleRoles($scope)->where('role.status', 'ACTIVE')
            ->orderBy('role.name')->get(['role.id', 'role.code', 'role.name', 'role.is_system'])
            ->map(fn (object $role) => [
                'id' => (string) $role->id,
                'code' => $role->code,
                'name' => $role->name,
                'is_system' => (bool) $role->is_system,
            ])->values()->all();
    }

    private function visibleRoles(array $scope): Builder
    {
        return DB::table('roles as role')->where(fn (Builder $query) => $query
            ->whereNull('role.company_id')->orWhere('role.company_id', $scope['company_id']));
    }

    private function visiblePermissions(array $scope): Builder
    {
        return DB::table('permissions as permission')->where(fn (Builder $query) => $query
            ->whereNull('permission.company_id')->orWhere('permission.company_id', $scope['company_id']));
    }

    private function requirePlant(array $scope): string
    {
        $plantId = $scope['plant_id'] ?? null;
        if (! is_string($plantId) || $plantId === '') {
            throw new NotFoundHttpException('A plant context is required for this administration workspace.');
        }

        return $plantId;
    }

    private function applyTextFilter(Builder $query, mixed $search, array $columns): void
    {
        $search = trim((string) $search);
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $query) use ($columns, $search) {
            $pattern = '%'.Str::lower($search).'%';
            foreach ($columns as $index => $column) {
                $method = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                $wrappedColumn = $query->getGrammar()->wrap($column);
                $query->{$method}("LOWER(COALESCE({$wrappedColumn}, ?)) LIKE ?", ['', $pattern]);
            }
        });
    }

    private function userRequiresRoleMfa(string $userId): bool
    {
        return DB::table('role_assignments as assignment')
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->where('assignment.user_id', $userId)
            ->where('assignment.is_active', true)
            ->where('role.status', 'ACTIVE')
            ->where('role.code', 'ERP_ADMIN')
            ->where(fn (Builder $query) => $query->whereNull('assignment.effective_from')
                ->orWhere('assignment.effective_from', '<=', now()))
            ->where(fn (Builder $query) => $query->whereNull('assignment.effective_to')
                ->orWhere('assignment.effective_to', '>', now()))
            ->exists();
    }

    private function can(array $permissions, string $permission): bool
    {
        return in_array($permission, $permissions, true);
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }
}

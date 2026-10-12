<?php

namespace App\Shared\Deployment;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class SystemDefinitionInitializer
{
    public const CONFIRMATION = 'INITIALIZE_SYSTEM_DEFINITIONS';

    /**
     * Install the reviewed global role and permission catalogue without creating
     * any tenant, plant, identity, credential, assignment, or business fixture.
     */
    public function initialize(): array
    {
        return DB::transaction(function (): array {
            $now = now();
            $roleIds = [];
            $permissionIds = [];
            $changes = [
                'roles_created' => 0,
                'roles_updated' => 0,
                'permissions_created' => 0,
                'permissions_updated' => 0,
                'assignments_created' => 0,
                'assignments_removed' => 0,
            ];

            foreach (SystemDefinitionCatalogue::roles() as $code => $definition) {
                $role = DB::table('roles')->where('code', $code)->lockForUpdate()->first();
                if ($role === null) {
                    $this->assertIdentifierAvailable('roles', $definition['id'], $code);
                    DB::table('roles')->insert([
                        'id' => $definition['id'],
                        'code' => $code,
                        'name' => $definition['name'],
                        'company_id' => null,
                        'description' => $definition['description'],
                        'status' => 'ACTIVE',
                        'is_system' => true,
                        'record_version' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $roleIds[$code] = $definition['id'];
                    $changes['roles_created']++;
                    continue;
                }

                $this->assertGlobalSystemDefinition($role, 'role', $code);
                $roleIds[$code] = (string) $role->id;
                $desired = [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'status' => 'ACTIVE',
                ];
                if ($this->requiresUpdate($role, $desired)) {
                    DB::table('roles')->where('id', $role->id)->update($desired + [
                        'record_version' => DB::raw('record_version + 1'),
                        'updated_at' => $now,
                    ]);
                    $changes['roles_updated']++;
                }
            }

            foreach (SystemDefinitionCatalogue::permissions() as $code => $definition) {
                $permission = DB::table('permissions')->where('code', $code)->lockForUpdate()->first();
                if ($permission === null) {
                    $permissionId = (string) Str::uuid();
                    DB::table('permissions')->insert([
                        'id' => $permissionId,
                        'code' => $code,
                        'name' => $definition['name'],
                        'company_id' => null,
                        'description' => $definition['description'],
                        'status' => 'ACTIVE',
                        'is_system' => true,
                        'record_version' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $permissionIds[$code] = $permissionId;
                    $changes['permissions_created']++;
                    continue;
                }

                $this->assertGlobalSystemDefinition($permission, 'permission', $code);
                $permissionIds[$code] = (string) $permission->id;
                $desired = [
                    'name' => $definition['name'],
                    'description' => $definition['description'],
                    'status' => 'ACTIVE',
                ];
                if ($this->requiresUpdate($permission, $desired)) {
                    DB::table('permissions')->where('id', $permission->id)->update($desired + [
                        'record_version' => DB::raw('record_version + 1'),
                        'updated_at' => $now,
                    ]);
                    $changes['permissions_updated']++;
                }
            }

            $expectedAssignmentCount = 0;
            foreach (SystemDefinitionCatalogue::permissionsByRole() as $roleCode => $permissionCodes) {
                $roleId = $roleIds[$roleCode];
                $expectedIds = array_values(array_map(
                    static fn (string $code): string => $permissionIds[$code],
                    $permissionCodes,
                ));
                $expectedAssignmentCount += count($expectedIds);
                $currentIds = DB::table('role_permissions')
                    ->where('role_id', $roleId)
                    ->pluck('permission_id')
                    ->map(static fn (mixed $id): string => (string) $id)
                    ->all();

                $missingIds = array_values(array_diff($expectedIds, $currentIds));
                if ($missingIds !== []) {
                    DB::table('role_permissions')->insertOrIgnore(array_map(
                        static fn (string $permissionId): array => [
                            'role_id' => $roleId,
                            'permission_id' => $permissionId,
                        ],
                        $missingIds,
                    ));
                    $changes['assignments_created'] += count($missingIds);
                }

                $staleIds = array_values(array_diff($currentIds, $expectedIds));
                if ($staleIds !== []) {
                    $changes['assignments_removed'] += DB::table('role_permissions')
                        ->where('role_id', $roleId)
                        ->whereIn('permission_id', $staleIds)
                        ->delete();
                }
            }

            $mutationCount = array_sum($changes);

            return [
                'status' => $mutationCount === 0 ? 'already_current' : 'initialized',
                'roles' => count($roleIds),
                'permissions' => count($permissionIds),
                'role_permission_assignments' => $expectedAssignmentCount,
                'erp_admin_permissions' => count(SystemDefinitionCatalogue::permissionsByRole()['ERP_ADMIN']),
                'scope' => 'GLOBAL_SYSTEM',
                'changes' => $changes,
            ];
        });
    }

    private function assertIdentifierAvailable(string $table, string $id, string $code): void
    {
        $owner = DB::table($table)->where('id', $id)->value('code');
        if ($owner !== null && (string) $owner !== $code) {
            throw new RuntimeException("The approved {$table} identifier for {$code} is already owned by {$owner}.");
        }
    }

    private function assertGlobalSystemDefinition(object $definition, string $type, string $code): void
    {
        if ($definition->company_id !== null || ! (bool) $definition->is_system) {
            throw new RuntimeException(
                "Refusing to overwrite {$type} {$code}; its code is already owned by a scoped or non-system definition.",
            );
        }
    }

    private function requiresUpdate(object $current, array $desired): bool
    {
        foreach ($desired as $field => $value) {
            if ($current->{$field} !== $value) {
                return true;
            }
        }

        return false;
    }
}

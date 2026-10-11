<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * Opt-in only. Creates a read-only cross-workspace QA role in the existing
 * synthetic QTF training company. Does not create accounts or grant actions.
 */
final class TemporaryQaRoleSeeder extends Seeder
{
    public const ROLE_CODE = 'TEMP_QA_AUDITOR';

    public function run(): void
    {
        if (app()->environment('production') || ! config('deployment.allow_demo_seeders', false)) {
            throw new LogicException('Temporary QA roles are permitted only in an explicitly enabled non-production environment.');
        }

        DB::transaction(function (): void {
            $companyId = DB::table('companies')
                ->where('code', 'QTF')
                ->where('is_demo', true)
                ->value('id');

            if ($companyId === null) {
                throw new LogicException('The synthetic QTF training company must be present before provisioning QA access.');
            }

            $role = DB::table('roles')->where('code', self::ROLE_CODE)->first();
            if ($role !== null && ((string) $role->company_id !== (string) $companyId || (bool) $role->is_system)) {
                throw new LogicException('An existing role with the QA code is outside the permitted training-company scope.');
            }

            $permissions = DB::table('permissions')
                ->where('status', 'ACTIVE')
                ->where('code', 'like', 'SCREEN:%:VIEW')
                ->where(function ($query) use ($companyId): void {
                    $query->whereNull('company_id')->orWhere('company_id', $companyId);
                })
                ->pluck('id');

            if ($permissions->count() < 69) {
                throw new LogicException('Expected screen permissions are missing; seed the training fixtures first.');
            }

            $roleId = $role === null ? (string) Str::uuid() : (string) $role->id;

            if ($role !== null) {
                $actionCount = DB::table('role_permissions')
                    ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
                    ->where('role_permissions.role_id', $roleId)
                    ->where('permissions.code', 'like', 'ACTION:%')
                    ->count();
                if ($actionCount > 0) {
                    throw new LogicException('QA role contains action privileges; remove them through reviewed administration before proceeding.');
                }
            } else {
                $now = now();
                DB::table('roles')->insert([
                    'id' => $roleId,
                    'company_id' => $companyId,
                    'code' => self::ROLE_CODE,
                    'name' => 'Temporary QA Auditor (UAT only)',
                    'description' => 'Read-only screen navigation in synthetic UAT data. No business-action or approval privileges. Use named assignments with a 14-day effective_to date; disable after acceptance.',
                    'status' => 'ACTIVE',
                    'is_system' => false,
                    'record_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach ($permissions as $permissionId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => (string) $permissionId,
                ]);
            }
        });
    }
}

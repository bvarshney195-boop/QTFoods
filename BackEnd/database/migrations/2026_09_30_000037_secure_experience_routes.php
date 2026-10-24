<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PERMISSIONS = [
        'ACTION:WRK-HOME:PERSONALISE' => [
            'name' => 'Personalise work home',
            'roles' => ['SALES_MANAGER', 'OPERATIONS_MANAGER', 'FINANCE_REVIEWER', 'ERP_ADMIN'],
        ],
        'ACTION:WRK-HOME:IMPORT' => [
            'name' => 'Run governed work home imports',
            'roles' => ['OPERATIONS_MANAGER', 'ERP_ADMIN'],
        ],
    ];

    public function up(): void
    {
        $now = now();

        foreach (self::PERMISSIONS as $code => $definition) {
            $permissionId = DB::table('permissions')->where('code', $code)->value('id')
                ?: (string) Str::uuid();

            DB::table('permissions')->updateOrInsert(['code' => $code], [
                'id' => $permissionId,
                'name' => $definition['name'],
                'company_id' => null,
                'description' => null,
                'status' => 'ACTIVE',
                'is_system' => true,
                'record_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $roleIds = DB::table('roles')->whereIn('code', $definition['roles'])->pluck('id');
            foreach ($roleIds as $roleId) {
                DB::table('role_permissions')->insertOrIgnore([
                    'role_id' => $roleId,
                    'permission_id' => $permissionId,
                ]);
            }
        }
    }

    public function down(): void
    {
        $permissionIds = DB::table('permissions')
            ->whereIn('code', array_keys(self::PERMISSIONS))
            ->where('is_system', true)
            ->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $permissionIds)->delete();
        DB::table('permissions')->whereIn('id', $permissionIds)->delete();
    }
};

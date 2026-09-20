<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

return new class extends Migration {
    private const ROLE_ID = '00000000-0000-4000-8000-000000000306';
    private const ROLE_CODE = 'BI_ANALYST';

    public function up(): void
    {
        $now = now();

        $role = DB::table('roles')->where('code', self::ROLE_CODE)->first();
        if (! $role) {
            DB::table('roles')->insert([
                'id' => self::ROLE_ID,
                'code' => self::ROLE_CODE,
                'name' => 'BI Analyst',
                'company_id' => null,
                'description' => 'Read-only business intelligence access to controlled reports and order profitability, with permissioned report generation and export.',
                'status' => 'ACTIVE',
                'is_system' => true,
                'record_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $roleId = self::ROLE_ID;
        } else {
            $roleId = (string) $role->id;
            DB::table('roles')->where('id', $roleId)->update([
                'name' => 'BI Analyst',
                'description' => 'Read-only business intelligence access to controlled reports and order profitability, with permissioned report generation and export.',
                'status' => 'ACTIVE',
                'updated_at' => $now,
            ]);
        }

        $permissions = [
            'SCREEN:BI-REP:VIEW' => 'View controlled BI reports',
            'SCREEN:BI-PROFIT:VIEW' => 'View order profitability',
            'ACTION:BI-REP:RUN' => 'Run controlled BI reports',
            'ACTION:BI-REP:EXPORT' => 'Export controlled BI reports',
        ];

        foreach ($permissions as $code => $name) {
            $permissionId = DB::table('permissions')->where('code', $code)->value('id');
            if (! $permissionId) {
                $permissionId = (string) Str::uuid();
                DB::table('permissions')->insert([
                    'id' => $permissionId,
                    'code' => $code,
                    'name' => $name,
                    'company_id' => null,
                    'description' => null,
                    'status' => 'ACTIVE',
                    'is_system' => true,
                    'record_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('role_permissions')->insertOrIgnore([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }
    }

    public function down(): void
    {
        $role = DB::table('roles')->where('code', self::ROLE_CODE)->first();
        if (! $role) {
            return;
        }

        DB::table('role_permissions')->where('role_id', $role->id)->delete();

        if ((string) $role->id === self::ROLE_ID && (bool) $role->is_system) {
            DB::table('roles')->where('id', self::ROLE_ID)->delete();
        }
    }
};

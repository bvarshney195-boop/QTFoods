<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    private const PERMISSIONS = [
        'ACTION:ADM-USER:PASSWORD-RESET' => 'Issue a temporary user password',
        'ACTION:ADM-USER:DELETE' => 'Delete a user through retained deactivation',
        'ACTION:ADM-USER:MFA' => 'Manage user MFA policy',
    ];

    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('mfa_required_by_admin')->default(false)->after('mfa_enabled_at');
            $table->timestampTz('deleted_at')->nullable()->after('mfa_required_by_admin');
            $table->index('deleted_at', 'users_deleted_at_index');
        });

        $now = now();
        foreach (self::PERMISSIONS as $code => $name) {
            $permissionId = DB::table('permissions')->where('code', $code)->value('id')
                ?: (string) Str::uuid();
            DB::table('permissions')->updateOrInsert(['code' => $code], [
                'id' => $permissionId,
                'name' => $name,
                'company_id' => null,
                'description' => null,
                'status' => 'ACTIVE',
                'is_system' => true,
                'record_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $adminRoleIds = DB::table('roles')->where('code', 'ERP_ADMIN')->pluck('id');
            foreach ($adminRoleIds as $roleId) {
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

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_deleted_at_index');
            $table->dropColumn(['mfa_required_by_admin', 'deleted_at']);
        });
    }
};

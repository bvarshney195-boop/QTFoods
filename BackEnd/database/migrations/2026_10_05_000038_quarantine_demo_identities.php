<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('companies', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->index()->after('status');
        });
        Schema::table('plants', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->index()->after('status');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_demo')->default(false)->index()->after('status');
        });

        DB::table('companies')
            ->where('id', '00000000-0000-4000-8000-000000000001')
            ->update(['is_demo' => true, 'updated_at' => now()]);
        DB::table('plants')
            ->whereIn('id', [
                '00000000-0000-4000-8000-000000000101',
                '00000000-0000-4000-8000-000000000102',
            ])
            ->update(['is_demo' => true, 'updated_at' => now()]);

        $demoEmails = [
            'demo.user@qtfoods.local',
            'operations.user@qtfoods.local',
            'finance.user@qtfoods.local',
            'admin.user@qtfoods.local',
            'partner.user@qtfoods.local',
            'bi.user@qtfoods.local',
        ];
        $demoIds = DB::table('users')->whereIn('email', $demoEmails)->pluck('id');
        if ($demoIds->isEmpty()) {
            return;
        }

        // Invalidate every known shared secret during upgrade. Development/UAT
        // fixtures are deliberately recreated only by the guarded demo seeder.
        DB::table('users')->whereIn('id', $demoIds)->update([
            'is_demo' => true,
            'status' => 'INACTIVE',
            'password_hash' => Hash::make(Str::random(64)),
            'password_changed_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('user_sessions')
            ->whereIn('user_id', $demoIds)
            ->whereNull('revoked_at')
            ->update([
                'revoked_at' => now(),
                'revoke_reason' => 'DEMO_IDENTITY_QUARANTINED',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['is_demo']);
            $table->dropColumn('is_demo');
        });
        Schema::table('plants', function (Blueprint $table) {
            $table->dropIndex(['is_demo']);
            $table->dropColumn('is_demo');
        });
        Schema::table('companies', function (Blueprint $table) {
            $table->dropIndex(['is_demo']);
            $table->dropColumn('is_demo');
        });
    }
};

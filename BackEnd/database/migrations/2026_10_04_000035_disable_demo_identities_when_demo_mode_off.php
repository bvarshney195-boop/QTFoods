<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if ((bool) config('deployment.allow_demo_seeders', false)) {
            return;
        }

        $emails = array_values(array_filter(array_map(
            static fn (mixed $email): string => mb_strtolower(trim((string) $email)),
            (array) config('qtfoods.identity.demo_principals', []),
        )));

        if ($emails === []) {
            return;
        }

        $userIds = DB::table('users')
            ->whereIn('email', $emails)
            ->pluck('id')
            ->all();

        if ($userIds === []) {
            return;
        }

        DB::transaction(function () use ($userIds): void {
            DB::table('users')->whereIn('id', $userIds)->update([
                'status' => 'INACTIVE',
                'updated_at' => now(),
            ]);
            DB::table('user_sessions')
                ->whereIn('user_id', $userIds)
                ->whereNull('revoked_at')
                ->update([
                    'revoked_at' => now(),
                    'revoke_reason' => 'DEMO_DISABLED',
                    'updated_at' => now(),
                ]);
        });
    }

    public function down(): void
    {
        // Deliberately irreversible. Production hardening must never reactivate demo identities.
    }
};

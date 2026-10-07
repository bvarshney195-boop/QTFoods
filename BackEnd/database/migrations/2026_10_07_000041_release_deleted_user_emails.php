<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('deleted_email')->nullable()->after('email');
        });

        DB::table('users')
            ->whereNotNull('deleted_at')
            ->orderBy('id')
            ->get(['id', 'email'])
            ->each(function (object $user): void {
                DB::table('users')->where('id', $user->id)->update([
                    'deleted_email' => $user->email,
                    'email' => $this->tombstoneEmail((string) $user->id),
                ]);
            });
    }

    public function down(): void
    {
        // A released address may now belong to a replacement account, so a rollback
        // deliberately keeps the collision-safe tombstone instead of restoring it.
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('deleted_email');
        });
    }

    private function tombstoneEmail(string $userId): string
    {
        $email = 'deleted+'.str_replace('-', '', Str::lower($userId)).'@identity.invalid';
        while (DB::table('users')->where('email', $email)->exists()) {
            $email = 'deleted+'.Str::lower(Str::random(32)).'@identity.invalid';
        }

        return $email;
    }
};

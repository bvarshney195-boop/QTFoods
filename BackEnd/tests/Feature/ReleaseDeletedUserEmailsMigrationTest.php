<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class ReleaseDeletedUserEmailsMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_deleted_rows_are_backfilled_and_their_login_email_is_released(): void
    {
        $this->seed();
        $deletedUserId = '00000000-0000-4000-8000-000000000201';
        $activeUserId = '00000000-0000-4000-8000-000000000203';
        $deletedEmail = (string) DB::table('users')->where('id', $deletedUserId)->value('email');
        $activeEmail = (string) DB::table('users')->where('id', $activeUserId)->value('email');

        DB::table('users')->where('id', $deletedUserId)->update(['deleted_at' => now()]);
        Schema::table('users', fn ($table) => $table->dropColumn('deleted_email'));

        $migration = require database_path(
            'migrations/2026_10_07_000041_release_deleted_user_emails.php'
        );
        $migration->up();

        $backfilled = DB::table('users')->where('id', $deletedUserId)->first();
        self::assertSame($deletedEmail, $backfilled->deleted_email);
        self::assertNotSame($deletedEmail, $backfilled->email);
        self::assertStringEndsWith('@identity.invalid', $backfilled->email);
        self::assertSame($activeEmail, DB::table('users')->where('id', $activeUserId)->value('email'));
        self::assertFalse(DB::table('users')->where('email', $deletedEmail)->exists());
    }
}

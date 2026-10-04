<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE identity_tokens DROP CONSTRAINT IF EXISTS identity_tokens_type_check');
            DB::statement("ALTER TABLE identity_tokens ADD CONSTRAINT identity_tokens_type_check CHECK (type IN ('PASSWORD_RESET', 'EMAIL_VERIFICATION', 'LOGIN_EMAIL_OTP', 'MFA_EMAIL_OTP'))");
        }
    }

    public function down(): void
    {
        DB::table('identity_tokens')->whereIn('type', ['LOGIN_EMAIL_OTP', 'MFA_EMAIL_OTP'])->delete();

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE identity_tokens DROP CONSTRAINT IF EXISTS identity_tokens_type_check');
            DB::statement("ALTER TABLE identity_tokens ADD CONSTRAINT identity_tokens_type_check CHECK (type IN ('PASSWORD_RESET', 'EMAIL_VERIFICATION'))");
        }
    }
};

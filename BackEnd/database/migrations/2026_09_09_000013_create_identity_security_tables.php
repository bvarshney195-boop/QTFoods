<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');
        }

        Schema::table('users', function (Blueprint $table) {
            $table->timestampTz('email_verified_at')->nullable()->after('status');
            $table->timestampTz('password_changed_at')->nullable()->after('email_verified_at');
            $table->timestampTz('last_login_at')->nullable()->after('password_changed_at');
            $table->string('last_login_ip', 45)->nullable()->after('last_login_at');
            $table->text('mfa_secret')->nullable()->after('last_login_ip');
            $table->timestampTz('mfa_enabled_at')->nullable()->after('mfa_secret');
            $table->index(['status', 'email_verified_at'], 'users_identity_state_index');
        });

        DB::table('users')->update([
            'email_verified_at' => now(),
            'password_changed_at' => now(),
        ]);

        Schema::create('identity_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->uuid('company_id')->index();
            $table->uuid('plant_id')->index();
            $table->uuid('invited_by_user_id')->index();
            $table->char('token_hash', 64)->unique();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('accepted_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampTz('last_sent_at')->nullable();
            $table->string('last_delivery_status', 32)->nullable();
            $table->unsignedInteger('delivery_count')->default(0);
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestampsTz();

            $table->foreign('user_id', 'identity_invitation_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('company_id', 'identity_invitation_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('plant_id', 'identity_invitation_plant_fk')
                ->references('id')->on('plants')->restrictOnDelete();
            $table->foreign('invited_by_user_id', 'identity_invitation_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('identity_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->uuid('company_id')->nullable()->index();
            $table->uuid('plant_id')->nullable()->index();
            $table->string('type', 32)->index();
            $table->char('token_hash', 64)->unique();
            $table->string('requested_ip', 45)->nullable();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->foreign('user_id', 'identity_token_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('company_id', 'identity_token_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('plant_id', 'identity_token_plant_fk')
                ->references('id')->on('plants')->restrictOnDelete();
        });

        Schema::create('user_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 1024)->nullable();
            $table->timestampTz('last_seen_at')->index();
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('revoked_at')->nullable();
            $table->uuid('revoked_by_user_id')->nullable()->index();
            $table->string('revoke_reason', 80)->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestampsTz();

            $table->foreign('user_id', 'user_session_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('revoked_by_user_id', 'user_session_revoker_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('user_mfa_recovery_codes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->char('code_hash', 64)->unique();
            $table->timestampTz('used_at')->nullable();
            $table->timestampsTz();

            $table->foreign('user_id', 'mfa_recovery_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('ACTIVE', 'INACTIVE', 'INVITED'))");
            DB::statement("ALTER TABLE identity_tokens ADD CONSTRAINT identity_tokens_type_check CHECK (type IN ('PASSWORD_RESET', 'EMAIL_VERIFICATION'))");
            DB::statement('ALTER TABLE identity_invitations ADD CONSTRAINT identity_invitation_terminal_check CHECK (accepted_at IS NULL OR revoked_at IS NULL)');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE identity_invitations DROP CONSTRAINT IF EXISTS identity_invitation_terminal_check');
            DB::statement('ALTER TABLE identity_tokens DROP CONSTRAINT IF EXISTS identity_tokens_type_check');
            DB::statement('ALTER TABLE users DROP CONSTRAINT IF EXISTS users_status_check');
        }

        Schema::dropIfExists('user_mfa_recovery_codes');
        Schema::dropIfExists('user_sessions');
        Schema::dropIfExists('identity_tokens');
        Schema::dropIfExists('identity_invitations');

        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex('users_identity_state_index');
            $table->dropColumn([
                'email_verified_at', 'password_changed_at', 'last_login_at',
                'last_login_ip', 'mfa_secret', 'mfa_enabled_at',
            ]);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE users ADD CONSTRAINT users_status_check CHECK (status IN ('ACTIVE', 'INACTIVE'))");
        }
    }
};

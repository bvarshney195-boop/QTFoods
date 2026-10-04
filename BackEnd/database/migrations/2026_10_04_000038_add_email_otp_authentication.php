<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('auth_email_otp_challenges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->nullable()->index();
            $table->char('code_hash', 64);
            $table->string('purpose', 24);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('consumed_at')->nullable();
            $table->string('requested_ip', 45)->nullable();
            $table->timestampsTz();

            $table->foreign('user_id', 'auth_email_otp_user_fk')
                ->references('id')->on('users')->cascadeOnDelete();
            $table->index(['purpose', 'expires_at', 'consumed_at'], 'auth_email_otp_state_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auth_email_otp_challenges');
    }
};

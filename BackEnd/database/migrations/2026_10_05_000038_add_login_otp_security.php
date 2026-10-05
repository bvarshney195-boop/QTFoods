<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('identity_login_otps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->uuid('challenge_id')->nullable()->index();
            $table->string('purpose', 32)->index();
            $table->char('code_hash', 64);
            $table->string('requested_ip', 45)->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestampTz('expires_at')->index();
            $table->timestampTz('used_at')->nullable();
            $table->timestampTz('revoked_at')->nullable();
            $table->timestampsTz();

            $table->foreign('user_id', 'identity_login_otp_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->unique(['user_id', 'purpose', 'challenge_id', 'code_hash'], 'identity_login_otp_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('identity_login_otps');
    }
};

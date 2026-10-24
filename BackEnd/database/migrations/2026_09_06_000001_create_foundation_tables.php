<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 32)->unique();
            $table->string('legal_name');
            $table->string('display_name');
            $table->string('status', 32)->default('DRAFT');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestampsTz();
        });

        Schema::create('plants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->string('code', 32);
            $table->string('name');
            $table->string('timezone', 64)->default('Asia/Kolkata');
            $table->string('status', 32)->default('DRAFT');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestampsTz();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('users', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email')->unique();
            $table->string('name');
            $table->string('password_hash');
            $table->string('status', 32)->default('ACTIVE');
            $table->timestampsTz();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 64)->unique();
            $table->string('name');
            $table->timestampsTz();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 128)->unique();
            $table->string('name');
            $table->timestampsTz();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->uuid('role_id');
            $table->uuid('permission_id');
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('role_assignments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id')->index();
            $table->uuid('role_id')->index();
            $table->uuid('company_id')->nullable()->index();
            $table->uuid('plant_id')->nullable()->index();
            $table->uuid('party_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('effective_from')->nullable();
            $table->timestampTz('effective_to')->nullable();
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('role_assignments');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('users');
        Schema::dropIfExists('plants');
        Schema::dropIfExists('companies');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('user_workspace_settings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('company_id');
            $table->uuid('plant_id')->nullable();
            $table->string('setting_key', 80);
            $table->json('setting_value');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestampsTz();
            $table->unique(['user_id', 'company_id', 'plant_id', 'setting_key'], 'workspace_settings_scope_unique');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('plant_id')->references('id')->on('plants')->cascadeOnDelete();
        });

        Schema::create('user_saved_views', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('company_id');
            $table->uuid('plant_id')->nullable();
            $table->string('screen_code', 32);
            $table->string('name', 80);
            $table->json('filters_json');
            $table->timestampsTz();
            $table->unique(['user_id', 'company_id', 'plant_id', 'screen_code', 'name'], 'saved_views_scope_name_unique');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('plant_id')->references('id')->on('plants')->cascadeOnDelete();
        });

        Schema::create('user_notification_states', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('work_item_id');
            $table->timestampTz('read_at')->nullable();
            $table->timestampTz('dismissed_at')->nullable();
            $table->timestampsTz();
            $table->unique(['user_id', 'work_item_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('work_item_id')->references('id')->on('work_items')->cascadeOnDelete();
        });

        Schema::create('analytics_targets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('company_id');
            $table->uuid('plant_id')->nullable();
            $table->string('metric_code', 48);
            $table->decimal('target_value', 20, 6);
            $table->timestampsTz();
            $table->unique(['user_id', 'company_id', 'plant_id', 'metric_code'], 'analytics_targets_scope_unique');
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->cascadeOnDelete();
            $table->foreign('plant_id')->references('id')->on('plants')->cascadeOnDelete();
        });

        Schema::create('bulk_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('user_id');
            $table->uuid('company_id');
            $table->uuid('plant_id')->nullable();
            $table->string('entity_type', 32);
            $table->string('file_name');
            $table->string('status', 24)->default('PREVIEWED');
            $table->unsignedInteger('row_count')->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('rows_json');
            $table->json('errors_json');
            $table->json('created_entities_json')->nullable();
            $table->timestampTz('committed_at')->nullable();
            $table->timestampTz('rolled_back_at')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestampsTz();
            $table->index(['company_id', 'plant_id', 'status']);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('company_id')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('plant_id')->references('id')->on('plants')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bulk_import_batches');
        Schema::dropIfExists('analytics_targets');
        Schema::dropIfExists('user_notification_states');
        Schema::dropIfExists('user_saved_views');
        Schema::dropIfExists('user_workspace_settings');
    }
};

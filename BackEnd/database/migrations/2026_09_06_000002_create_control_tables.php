<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('approval_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('entity_type', 80);
            $table->uuid('entity_id')->index();
            $table->unsignedBigInteger('entity_version');
            $table->uuid('maker_id')->index();
            $table->uuid('company_id')->index();
            $table->uuid('plant_id')->nullable()->index();
            $table->string('rule_code', 80);
            $table->string('status', 32);
            $table->jsonb('summary_json')->nullable();
            $table->timestampsTz();
        });

        Schema::create('approval_decisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('approval_request_id')->index();
            $table->uuid('reviewer_id')->index();
            $table->string('decision', 32);
            $table->text('reason')->nullable();
            $table->timestampTz('created_at');
        });

        Schema::create('audit_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('request_id')->nullable()->index();
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('command', 128);
            $table->string('entity_type', 80);
            $table->uuid('entity_id')->index();
            $table->unsignedBigInteger('entity_version')->nullable();
            $table->uuid('actor_id')->index();
            $table->uuid('company_id')->index();
            $table->uuid('plant_id')->nullable()->index();
            $table->string('outcome', 32);
            $table->string('reason_code', 80)->nullable();
            $table->jsonb('safe_diff_json')->nullable();
            $table->timestampTz('event_at');
            $table->timestampTz('posted_at');
            $table->timestampTz('created_at');
        });

        Schema::create('idempotency_keys', function (Blueprint $table) {
            $table->string('namespace', 120);
            $table->string('key', 160);
            $table->char('payload_hash', 64);
            $table->string('status', 32);
            $table->jsonb('result_json')->nullable();
            $table->timestampsTz();
            $table->primary(['namespace', 'key']);
        });

        Schema::create('outbox_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('event_type', 128);
            $table->string('aggregate_type', 80);
            $table->uuid('aggregate_id')->index();
            $table->string('business_key', 180)->index();
            $table->jsonb('payload_json');
            $table->uuid('correlation_id')->nullable()->index();
            $table->string('status', 32)->default('PENDING');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('next_retry_at')->nullable();
            $table->timestampsTz();
            $table->unique(['event_type', 'business_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
        Schema::dropIfExists('idempotency_keys');
        Schema::dropIfExists('audit_events');
        Schema::dropIfExists('approval_decisions');
        Schema::dropIfExists('approval_requests');
    }
};

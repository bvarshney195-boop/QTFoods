<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('report_runs', function (Blueprint $table) {
            $table->string('run_number', 80)->nullable()->after('plant_id');
            $table->string('report_code', 48)->nullable()->after('run_number');
            $table->string('report_title', 160)->nullable()->after('report_code');
            $table->timestampTz('as_of_at')->nullable()->after('report_title');
            $table->timestampTz('source_freshness_at')->nullable()->after('as_of_at');
            $table->json('parameters_json')->nullable()->after('source_freshness_at');
            $table->json('columns_json')->nullable()->after('parameters_json');
            $table->unsignedInteger('row_count')->default(0)->after('columns_json');
            $table->json('totals_json')->nullable()->after('row_count');
            $table->char('sha256', 64)->nullable()->after('totals_json');
            $table->timestampTz('generated_at')->nullable()->after('sha256');

            $table->unique(['company_id', 'plant_id', 'run_number'], 'report_runs_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'report_runs_scope_identity_unique');
            $table->index(['company_id', 'plant_id', 'report_code', 'generated_at'], 'report_runs_scope_code_index');
            if (DB::getDriverName() !== 'pgsql') {
                $table->foreign('company_id', 'report_runs_company_fk')
                    ->references('id')->on('companies')->restrictOnDelete();
                $table->foreign(['plant_id', 'company_id'], 'report_runs_plant_fk')
                    ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
                $table->foreign('created_by', 'report_runs_creator_fk')
                    ->references('id')->on('users')->restrictOnDelete();
            }
        });

        Schema::create('report_run_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('report_run_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedInteger('row_number');
            $table->string('group_key', 160)->nullable();
            $table->json('data_json');
            $table->timestampTz('created_at');

            $table->unique(['report_run_id', 'row_number'], 'report_run_rows_number_unique');
            $table->index(['report_run_id', 'group_key'], 'report_run_rows_group_index');
            $table->foreign(
                ['report_run_id', 'company_id', 'plant_id'],
                'report_run_rows_run_fk',
            )->references(
                ['id', 'company_id', 'plant_id'],
            )->on('report_runs')->cascadeOnDelete();
        });

        Schema::create('report_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('report_run_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('format', 8);
            $table->string('file_name', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('row_count');
            $table->longText('payload_text');
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256', 64);
            $table->uuid('created_by');
            $table->timestampTz('created_at');

            $table->unique(['id', 'company_id', 'plant_id'], 'report_exports_scope_identity_unique');
            $table->index(['report_run_id', 'format', 'created_at'], 'report_exports_run_format_index');
            $table->foreign(
                ['report_run_id', 'company_id', 'plant_id'],
                'report_exports_run_fk',
            )->references(
                ['id', 'company_id', 'plant_id'],
            )->on('report_runs')->restrictOnDelete();
            $table->foreign('created_by', 'report_exports_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('help_articles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug', 120)->unique();
            $table->string('category', 32);
            $table->string('related_screen_code', 32)->nullable();
            $table->string('title', 180);
            $table->string('summary', 500);
            $table->json('body_json');
            $table->text('search_terms')->nullable();
            $table->string('status', 16)->default('PUBLISHED');
            $table->unsignedInteger('content_version')->default(1);
            $table->timestampTz('published_at');
            $table->timestampsTz();

            $table->index(['status', 'category', 'related_screen_code'], 'help_articles_catalog_index');
        });

        Schema::create('help_support_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('case_number', 80);
            $table->string('category', 32);
            $table->string('priority', 16);
            $table->string('affected_screen_code', 32)->nullable();
            $table->string('subject', 200);
            $table->text('description');
            $table->string('status', 20)->default('OPEN');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('requester_id');
            $table->uuid('assigned_to')->nullable();
            $table->text('resolution_summary')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'case_number'], 'help_cases_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'help_cases_scope_identity_unique');
            $table->index(['company_id', 'plant_id', 'status', 'priority', 'created_at'], 'help_cases_scope_status_index');
            $table->foreign('company_id', 'help_cases_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'help_cases_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign('requester_id', 'help_cases_requester_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('assigned_to', 'help_cases_assignee_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('resolved_by', 'help_cases_resolver_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('closed_by', 'help_cases_closer_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('help_support_case_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('help_support_case_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedInteger('sequence_number');
            $table->string('event_type', 20);
            $table->string('status_from', 20)->nullable();
            $table->string('status_to', 20)->nullable();
            $table->text('message');
            $table->uuid('actor_id');
            $table->timestampTz('created_at');

            $table->unique(['help_support_case_id', 'sequence_number'], 'help_case_events_sequence_unique');
            $table->foreign(
                ['help_support_case_id', 'company_id', 'plant_id'],
                'help_case_events_case_fk',
            )->references(
                ['id', 'company_id', 'plant_id'],
            )->on('help_support_cases')->cascadeOnDelete();
            $table->foreign('actor_id', 'help_case_events_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach ($this->postgresConstraints() as [$table, $name, $definition]) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition}");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach ($this->postgresConstraints() as [$table, $name]) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            }
        }

        Schema::dropIfExists('help_support_case_events');
        Schema::dropIfExists('help_support_cases');
        Schema::dropIfExists('help_articles');
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('report_run_rows');

        Schema::table('report_runs', function (Blueprint $table) {
            if (DB::getDriverName() !== 'pgsql') {
                $table->dropForeign('report_runs_creator_fk');
                $table->dropForeign('report_runs_plant_fk');
                $table->dropForeign('report_runs_company_fk');
            }
            $table->dropIndex('report_runs_scope_code_index');
            $table->dropUnique('report_runs_scope_identity_unique');
            $table->dropUnique('report_runs_scope_number_unique');
            $table->dropColumn([
                'run_number', 'report_code', 'report_title', 'as_of_at', 'source_freshness_at',
                'parameters_json', 'columns_json', 'row_count', 'totals_json', 'sha256', 'generated_at',
            ]);
        });
    }

    private function postgresConstraints(): array
    {
        return [
            ['report_runs', 'report_runs_values_check', "CHECK (status = 'DRAFT' OR (status = 'GENERATED' AND plant_id IS NOT NULL AND run_number IS NOT NULL AND report_code IN ('TRIAL_BALANCE', 'RECEIVABLE_AGING', 'INVENTORY_AVAILABILITY', 'ORDER_FULFILMENT') AND report_title IS NOT NULL AND as_of_at IS NOT NULL AND source_freshness_at IS NOT NULL AND parameters_json IS NOT NULL AND columns_json IS NOT NULL AND row_count >= 0 AND totals_json IS NOT NULL AND sha256 ~ '^[0-9a-f]{64}$' AND generated_at IS NOT NULL AND created_by IS NOT NULL AND record_version >= 1))"],
            ['report_run_rows', 'report_run_rows_values_check', 'CHECK (row_number >= 1)'],
            ['report_exports', 'report_exports_values_check', "CHECK (format IN ('CSV', 'JSON') AND row_count >= 0 AND size_bytes > 0 AND sha256 ~ '^[0-9a-f]{64}$')"],
            ['help_articles', 'help_articles_values_check', "CHECK (category IN ('GETTING_STARTED', 'WORKFLOWS', 'CONTROLS', 'SECURITY', 'REPORTING') AND status IN ('PUBLISHED', 'RETIRED') AND content_version >= 1 AND btrim(title) <> '' AND btrim(summary) <> '')"],
            ['help_support_cases', 'help_cases_values_check', "CHECK (category IN ('ACCESS', 'DATA', 'WORKFLOW', 'INTEGRATION', 'REPORTING', 'OTHER') AND priority IN ('LOW', 'NORMAL', 'HIGH', 'CRITICAL') AND status IN ('OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED') AND record_version >= 1 AND btrim(subject) <> '' AND btrim(description) <> '')"],
            ['help_support_cases', 'help_cases_resolution_check', "CHECK ((status IN ('OPEN', 'IN_PROGRESS') AND resolved_at IS NULL AND resolved_by IS NULL AND resolution_summary IS NULL AND closed_at IS NULL AND closed_by IS NULL) OR (status = 'RESOLVED' AND resolved_at IS NOT NULL AND resolved_by IS NOT NULL AND btrim(resolution_summary) <> '' AND closed_at IS NULL AND closed_by IS NULL) OR (status = 'CLOSED' AND resolved_at IS NOT NULL AND resolved_by IS NOT NULL AND btrim(resolution_summary) <> '' AND closed_at IS NOT NULL AND closed_by IS NOT NULL))"],
            ['help_support_case_events', 'help_case_events_values_check', "CHECK (sequence_number >= 1 AND event_type IN ('CREATED', 'COMMENTED', 'STARTED', 'RESOLVED', 'REOPENED', 'CLOSED') AND btrim(message) <> '')"],
        ];
    }
};

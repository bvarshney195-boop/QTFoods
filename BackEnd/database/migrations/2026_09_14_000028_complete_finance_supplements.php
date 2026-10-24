<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('finance_simulations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('simulation_number', 80);
            $table->string('name', 160);
            $table->text('description')->nullable();
            $table->date('as_of_date');
            $table->string('status', 16)->default('DRAFT');
            $table->decimal('total_debit', 20, 4)->default(0);
            $table->decimal('total_credit', 20, 4)->default(0);
            $table->decimal('projected_profit', 20, 4)->default(0);
            $table->json('result_json')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('run_at')->nullable();
            $table->uuid('run_by')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'simulation_number'], 'finance_simulations_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'finance_simulations_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'as_of_date'], 'finance_simulations_scope_status_index');
            $table->foreign('company_id', 'finance_simulations_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'finance_simulations_plant_fk')->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign('created_by', 'finance_simulations_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('run_by', 'finance_simulations_runner_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('finance_simulation_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('finance_simulation_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('account_id');
            $table->string('description', 255);
            $table->decimal('debit_amount', 20, 4)->default(0);
            $table->decimal('credit_amount', 20, 4)->default(0);
            $table->string('assumption', 500)->nullable();
            $table->timestampsTz();

            $table->unique(['finance_simulation_id', 'line_number'], 'finance_simulation_lines_number_unique');
            $table->foreign(['finance_simulation_id', 'company_id', 'plant_id'], 'finance_simulation_lines_simulation_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('finance_simulations')->cascadeOnDelete();
            $table->foreign(['account_id', 'company_id'], 'finance_simulation_lines_account_fk')
                ->references(['id', 'company_id'])->on('chart_accounts')->restrictOnDelete();
        });

        Schema::create('finance_adjustments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('adjustment_number', 80);
            $table->date('adjustment_date');
            $table->string('adjustment_type', 32);
            $table->text('reason');
            $table->string('status', 16)->default('DRAFT');
            $table->decimal('total_debit', 20, 4)->default(0);
            $table->decimal('total_credit', 20, 4)->default(0);
            $table->uuid('journal_id')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('submitted_at')->nullable();
            $table->uuid('submitted_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'adjustment_number'], 'finance_adjustments_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'finance_adjustments_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'adjustment_date'], 'finance_adjustments_scope_status_index');
            $table->foreign('company_id', 'finance_adjustments_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'finance_adjustments_plant_fk')->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['journal_id', 'company_id', 'plant_id'], 'finance_adjustments_journal_fk')->references(['id', 'company_id', 'plant_id'])->on('journals')->restrictOnDelete();
            $table->foreign('created_by', 'finance_adjustments_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('submitted_by', 'finance_adjustments_submitter_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by', 'finance_adjustments_approver_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('posted_by', 'finance_adjustments_poster_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by', 'finance_adjustments_canceller_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('finance_adjustment_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('finance_adjustment_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('account_id');
            $table->uuid('party_id')->nullable();
            $table->string('description', 255);
            $table->decimal('debit_amount', 20, 4)->default(0);
            $table->decimal('credit_amount', 20, 4)->default(0);
            $table->timestampsTz();

            $table->unique(['finance_adjustment_id', 'line_number'], 'finance_adjustment_lines_number_unique');
            $table->foreign(['finance_adjustment_id', 'company_id', 'plant_id'], 'finance_adjustment_lines_adjustment_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('finance_adjustments')->cascadeOnDelete();
            $table->foreign(['account_id', 'company_id'], 'finance_adjustment_lines_account_fk')
                ->references(['id', 'company_id'])->on('chart_accounts')->restrictOnDelete();
            $table->foreign(['party_id', 'company_id'], 'finance_adjustment_lines_party_fk')
                ->references(['id', 'company_id'])->on('parties')->restrictOnDelete();
        });

        Schema::create('legacy_import_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('batch_number', 80);
            $table->string('source_system', 120);
            $table->date('posting_date');
            $table->string('status', 16)->default('STAGED');
            $table->unsignedInteger('record_count')->default(0);
            $table->decimal('total_debit', 20, 4)->default(0);
            $table->decimal('total_credit', 20, 4)->default(0);
            $table->unsignedInteger('error_count')->default(0);
            $table->json('validation_json')->nullable();
            $table->uuid('journal_id')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('validated_at')->nullable();
            $table->uuid('validated_by')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'batch_number'], 'legacy_import_batches_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'legacy_import_batches_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'posting_date'], 'legacy_import_batches_scope_status_index');
            $table->foreign('company_id', 'legacy_import_batches_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'legacy_import_batches_plant_fk')->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['journal_id', 'company_id', 'plant_id'], 'legacy_import_batches_journal_fk')->references(['id', 'company_id', 'plant_id'])->on('journals')->restrictOnDelete();
            $table->foreign('created_by', 'legacy_import_batches_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('validated_by', 'legacy_import_batches_validator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('posted_by', 'legacy_import_batches_poster_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('legacy_import_rows', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('legacy_import_batch_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedInteger('row_number');
            $table->string('legacy_account_code', 80);
            $table->uuid('account_id')->nullable();
            $table->string('description', 255);
            $table->decimal('debit_amount', 20, 4)->default(0);
            $table->decimal('credit_amount', 20, 4)->default(0);
            $table->string('validation_status', 16)->default('PENDING');
            $table->text('validation_message')->nullable();
            $table->json('source_json')->nullable();
            $table->timestampsTz();

            $table->unique(['legacy_import_batch_id', 'row_number'], 'legacy_import_rows_number_unique');
            $table->foreign(['legacy_import_batch_id', 'company_id', 'plant_id'], 'legacy_import_rows_batch_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('legacy_import_batches')->cascadeOnDelete();
            $table->foreign(['account_id', 'company_id'], 'legacy_import_rows_account_fk')
                ->references(['id', 'company_id'])->on('chart_accounts')->restrictOnDelete();
        });

        Schema::create('bill_archive_documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('document_number', 80);
            $table->uuid('invoice_id')->nullable();
            $table->string('document_type', 32);
            $table->string('original_name', 255);
            $table->string('storage_disk', 40)->default('private');
            $table->string('storage_path', 1024);
            $table->string('mime_type', 120);
            $table->unsignedBigInteger('size_bytes');
            $table->char('sha256_checksum', 64);
            $table->date('document_date');
            $table->date('retain_until');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('uploaded_by');
            $table->timestampsTz();

            $table->unique(['company_id', 'document_number'], 'bill_archive_documents_company_number_unique');
            $table->unique(['company_id', 'sha256_checksum'], 'bill_archive_documents_checksum_unique');
            $table->index(['company_id', 'plant_id', 'document_date'], 'bill_archive_documents_scope_date_index');
            $table->foreign('company_id', 'bill_archive_documents_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'bill_archive_documents_plant_fk')->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['invoice_id', 'company_id', 'plant_id'], 'bill_archive_documents_invoice_fk')->references(['id', 'company_id', 'plant_id'])->on('invoices')->restrictOnDelete();
            $table->foreign('uploaded_by', 'bill_archive_documents_uploader_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('opening_balance_batches', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('batch_number', 80);
            $table->date('opening_date');
            $table->string('status', 16)->default('DRAFT');
            $table->decimal('total_debit', 20, 4)->default(0);
            $table->decimal('total_credit', 20, 4)->default(0);
            $table->decimal('difference_amount', 20, 4)->default(0);
            $table->text('notes')->nullable();
            $table->uuid('journal_id')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('reconciled_at')->nullable();
            $table->uuid('reconciled_by')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'batch_number'], 'opening_balance_batches_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'opening_balance_batches_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'opening_date'], 'opening_balance_batches_scope_status_index');
            $table->foreign('company_id', 'opening_balance_batches_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'opening_balance_batches_plant_fk')->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['journal_id', 'company_id', 'plant_id'], 'opening_balance_batches_journal_fk')->references(['id', 'company_id', 'plant_id'])->on('journals')->restrictOnDelete();
            $table->foreign('created_by', 'opening_balance_batches_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reconciled_by', 'opening_balance_batches_reconciler_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('posted_by', 'opening_balance_batches_poster_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('opening_balance_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('opening_balance_batch_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('account_id');
            $table->uuid('party_id')->nullable();
            $table->string('description', 255);
            $table->decimal('debit_amount', 20, 4)->default(0);
            $table->decimal('credit_amount', 20, 4)->default(0);
            $table->decimal('source_amount', 20, 4);
            $table->decimal('reconciled_amount', 20, 4)->default(0);
            $table->timestampsTz();

            $table->unique(['opening_balance_batch_id', 'line_number'], 'opening_balance_lines_number_unique');
            $table->foreign(['opening_balance_batch_id', 'company_id', 'plant_id'], 'opening_balance_lines_batch_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('opening_balance_batches')->cascadeOnDelete();
            $table->foreign(['account_id', 'company_id'], 'opening_balance_lines_account_fk')
                ->references(['id', 'company_id'])->on('chart_accounts')->restrictOnDelete();
            $table->foreign(['party_id', 'company_id'], 'opening_balance_lines_party_fk')
                ->references(['id', 'company_id'])->on('parties')->restrictOnDelete();
        });

        Schema::create('finance_support_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('case_number', 80);
            $table->string('category', 32);
            $table->string('severity', 16);
            $table->string('subject', 200);
            $table->text('description');
            $table->string('status', 16)->default('OPEN');
            $table->json('diagnostic_snapshot')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('closed_at')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'case_number'], 'finance_support_cases_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'finance_support_cases_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'severity'], 'finance_support_cases_scope_status_index');
            $table->foreign('company_id', 'finance_support_cases_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'finance_support_cases_plant_fk')->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign('created_by', 'finance_support_cases_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('closed_by', 'finance_support_cases_closer_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('finance_support_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('finance_support_case_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('action_type', 24);
            $table->text('notes');
            $table->json('diagnostic_json')->nullable();
            $table->uuid('actor_id');
            $table->timestampTz('created_at');

            $table->foreign(['finance_support_case_id', 'company_id', 'plant_id'], 'finance_support_actions_case_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('finance_support_cases')->cascadeOnDelete();
            $table->foreign('actor_id', 'finance_support_actions_actor_fk')->references('id')->on('users')->restrictOnDelete();
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
            foreach (array_reverse($this->postgresConstraints()) as [$table, $name]) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            }
        }

        Schema::dropIfExists('finance_support_actions');
        Schema::dropIfExists('finance_support_cases');
        Schema::dropIfExists('opening_balance_lines');
        Schema::dropIfExists('opening_balance_batches');
        Schema::dropIfExists('bill_archive_documents');
        Schema::dropIfExists('legacy_import_rows');
        Schema::dropIfExists('legacy_import_batches');
        Schema::dropIfExists('finance_adjustment_lines');
        Schema::dropIfExists('finance_adjustments');
        Schema::dropIfExists('finance_simulation_lines');
        Schema::dropIfExists('finance_simulations');
    }

    private function postgresConstraints(): array
    {
        return [
            ['finance_simulations', 'finance_simulations_values_check', "CHECK (status IN ('DRAFT', 'RUN') AND total_debit >= 0 AND total_credit >= 0 AND record_version >= 1 AND ((status = 'DRAFT' AND run_at IS NULL AND run_by IS NULL) OR (status = 'RUN' AND run_at IS NOT NULL AND run_by IS NOT NULL AND result_json IS NOT NULL)))"],
            ['finance_simulation_lines', 'finance_simulation_lines_values_check', 'CHECK (line_number >= 1 AND debit_amount >= 0 AND credit_amount >= 0 AND ((debit_amount > 0 AND credit_amount = 0) OR (credit_amount > 0 AND debit_amount = 0)))'],
            ['finance_adjustments', 'finance_adjustments_values_check', "CHECK (adjustment_type IN ('ACCRUAL', 'RECLASSIFICATION', 'CORRECTION', 'PROVISION') AND status IN ('DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'CANCELLED') AND total_debit >= 0 AND total_credit >= 0 AND total_debit = total_credit AND record_version >= 1 AND (status <> 'POSTED' OR (journal_id IS NOT NULL AND posted_at IS NOT NULL AND posted_by IS NOT NULL)))"],
            ['finance_adjustment_lines', 'finance_adjustment_lines_values_check', 'CHECK (line_number >= 1 AND debit_amount >= 0 AND credit_amount >= 0 AND ((debit_amount > 0 AND credit_amount = 0) OR (credit_amount > 0 AND debit_amount = 0)))'],
            ['legacy_import_batches', 'legacy_import_batches_values_check', "CHECK (status IN ('STAGED', 'VALID', 'INVALID', 'POSTED') AND record_count >= 1 AND total_debit >= 0 AND total_credit >= 0 AND error_count >= 0 AND error_count <= record_count AND record_version >= 1 AND (status <> 'POSTED' OR (journal_id IS NOT NULL AND posted_at IS NOT NULL AND posted_by IS NOT NULL)))"],
            ['legacy_import_rows', 'legacy_import_rows_values_check', "CHECK (row_number >= 1 AND debit_amount >= 0 AND credit_amount >= 0 AND ((debit_amount > 0 AND credit_amount = 0) OR (credit_amount > 0 AND debit_amount = 0)) AND validation_status IN ('PENDING', 'VALID', 'INVALID'))"],
            ['bill_archive_documents', 'bill_archive_documents_values_check', "CHECK (document_type IN ('SUPPLIER_INVOICE', 'CUSTOMER_INVOICE', 'EXPENSE', 'TAX', 'OTHER') AND size_bytes > 0 AND retain_until >= document_date AND record_version >= 1 AND storage_disk = 'private' AND length(sha256_checksum) = 64)"],
            ['opening_balance_batches', 'opening_balance_batches_values_check', "CHECK (status IN ('DRAFT', 'RECONCILED', 'POSTED') AND total_debit >= 0 AND total_credit >= 0 AND difference_amount = total_debit - total_credit AND record_version >= 1 AND (status <> 'POSTED' OR (journal_id IS NOT NULL AND posted_at IS NOT NULL AND posted_by IS NOT NULL)))"],
            ['opening_balance_lines', 'opening_balance_lines_values_check', 'CHECK (line_number >= 1 AND debit_amount >= 0 AND credit_amount >= 0 AND ((debit_amount > 0 AND credit_amount = 0) OR (credit_amount > 0 AND debit_amount = 0)) AND source_amount >= 0 AND reconciled_amount >= 0 AND reconciled_amount <= source_amount)'],
            ['finance_support_cases', 'finance_support_cases_values_check', "CHECK (category IN ('POSTING', 'RECONCILIATION', 'IMPORT', 'ACCESS', 'OTHER') AND severity IN ('LOW', 'MEDIUM', 'HIGH', 'CRITICAL') AND status IN ('OPEN', 'DIAGNOSED', 'CLOSED') AND record_version >= 1 AND ((status <> 'CLOSED' AND closed_at IS NULL AND closed_by IS NULL) OR (status = 'CLOSED' AND closed_at IS NOT NULL AND closed_by IS NOT NULL AND btrim(resolution_notes) <> '')))"],
            ['finance_support_actions', 'finance_support_actions_values_check', "CHECK (action_type IN ('DIAGNOSTIC', 'NOTE', 'CLOSE') AND btrim(notes) <> '')"],
        ];
    }
};

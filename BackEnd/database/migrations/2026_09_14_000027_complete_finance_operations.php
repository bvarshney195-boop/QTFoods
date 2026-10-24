<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('chart_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('account_code', 32);
            $table->string('name', 160);
            $table->string('account_type', 16);
            $table->string('control_type', 24)->nullable();
            $table->string('status', 16)->default('ACTIVE');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'account_code'], 'chart_accounts_company_code_unique');
            $table->unique(['id', 'company_id'], 'chart_accounts_id_company_unique');
            $table->index(['company_id', 'account_type', 'status'], 'chart_accounts_company_type_index');
            $table->foreign('company_id', 'chart_accounts_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('created_by', 'chart_accounts_creator_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('fiscal_periods', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('period_code', 16);
            $table->date('starts_on');
            $table->date('ends_on');
            $table->string('status', 16)->default('OPEN');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestampTz('closed_at')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->text('closure_notes')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'period_code'], 'fiscal_periods_company_code_unique');
            $table->unique(['id', 'company_id'], 'fiscal_periods_id_company_unique');
            $table->index(['company_id', 'status', 'starts_on'], 'fiscal_periods_company_status_index');
            $table->foreign('company_id', 'fiscal_periods_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('closed_by', 'fiscal_periods_closer_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('journals', function (Blueprint $table) {
            $table->string('journal_type', 24)->default('LEGACY')->after('plant_id');
            $table->string('journal_number', 80)->nullable()->after('journal_type');
            $table->uuid('fiscal_period_id')->nullable()->after('journal_number');
            $table->date('posting_date')->nullable()->after('fiscal_period_id');
            $table->string('description', 255)->nullable()->after('posting_date');
            $table->char('currency', 3)->nullable()->after('description');
            $table->decimal('total_debit', 20, 6)->nullable()->after('currency');
            $table->decimal('total_credit', 20, 6)->nullable()->after('total_debit');
            $table->uuid('reversal_of_journal_id')->nullable()->after('total_credit');
            $table->string('source_reference', 160)->nullable()->after('reversal_of_journal_id');
            $table->timestampTz('posted_at')->nullable()->after('source_reference');
            $table->uuid('posted_by')->nullable()->after('posted_at');
            $table->timestampTz('reversed_at')->nullable()->after('posted_by');
            $table->uuid('reversed_by')->nullable()->after('reversed_at');
            $table->text('reversal_reason')->nullable()->after('reversed_by');

            $table->unique(['company_id', 'plant_id', 'journal_number'], 'journals_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'journals_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'posting_date'], 'journals_scope_status_index');
            $table->foreign(['fiscal_period_id', 'company_id'], 'journals_period_fk')->references(['id', 'company_id'])->on('fiscal_periods')->restrictOnDelete();
            $table->foreign(['reversal_of_journal_id', 'company_id', 'plant_id'], 'journals_reversal_fk')->references(['id', 'company_id', 'plant_id'])->on('journals')->restrictOnDelete();
            $table->foreign('posted_by', 'journals_poster_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reversed_by', 'journals_reverser_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('journal_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('journal_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('account_id');
            $table->uuid('party_id')->nullable();
            $table->string('description', 255);
            $table->decimal('debit_amount', 20, 6)->default(0);
            $table->decimal('credit_amount', 20, 6)->default(0);
            $table->timestampsTz();

            $table->unique(['journal_id', 'line_number'], 'journal_lines_number_unique');
            $table->unique(['id', 'journal_id', 'company_id', 'plant_id'], 'journal_lines_identity_unique');
            $table->foreign(['journal_id', 'company_id', 'plant_id'], 'journal_lines_journal_fk')->references(['id', 'company_id', 'plant_id'])->on('journals')->cascadeOnDelete();
            $table->foreign(['account_id', 'company_id'], 'journal_lines_account_fk')->references(['id', 'company_id'])->on('chart_accounts')->restrictOnDelete();
            $table->foreign(['party_id', 'company_id'], 'journal_lines_party_fk')->references(['id', 'company_id'])->on('parties')->restrictOnDelete();
        });

        Schema::create('expense_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('expense_number', 80);
            $table->uuid('claimant_user_id');
            $table->date('expense_date');
            $table->string('category', 40);
            $table->char('currency', 3)->default('INR');
            $table->decimal('net_amount', 20, 6);
            $table->decimal('tax_amount', 20, 6);
            $table->decimal('total_amount', 20, 6);
            $table->text('description');
            $table->string('status', 16)->default('DRAFT');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('submitted_at')->nullable();
            $table->uuid('submitted_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->uuid('journal_id')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'expense_number'], 'expense_claims_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'expense_claims_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'expense_date'], 'expense_claims_scope_status_index');
            $table->foreign('company_id', 'expense_claims_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'expense_claims_plant_fk')->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign('claimant_user_id', 'expense_claims_claimant_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('created_by', 'expense_claims_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('submitted_by', 'expense_claims_submitter_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by', 'expense_claims_approver_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('posted_by', 'expense_claims_poster_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by', 'expense_claims_canceller_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['journal_id', 'company_id', 'plant_id'], 'expense_claims_journal_fk')->references(['id', 'company_id', 'plant_id'])->on('journals')->restrictOnDelete();
        });

        Schema::create('expense_claim_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('expense_claim_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('expense_account_id');
            $table->string('description', 255);
            $table->decimal('net_amount', 20, 6);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('tax_amount', 20, 6);
            $table->decimal('gross_amount', 20, 6);
            $table->timestampsTz();

            $table->unique(['expense_claim_id', 'line_number'], 'expense_claim_lines_number_unique');
            $table->foreign(['expense_claim_id', 'company_id', 'plant_id'], 'expense_claim_lines_claim_fk')->references(['id', 'company_id', 'plant_id'])->on('expense_claims')->cascadeOnDelete();
            $table->foreign(['expense_account_id', 'company_id'], 'expense_claim_lines_account_fk')->references(['id', 'company_id'])->on('chart_accounts')->restrictOnDelete();
        });

        Schema::create('overhead_pools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('pool_code', 40);
            $table->string('name', 160);
            $table->uuid('expense_account_id');
            $table->string('allocation_basis', 24);
            $table->decimal('rate', 20, 6);
            $table->string('status', 16)->default('ACTIVE');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'pool_code'], 'overhead_pools_scope_code_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'overhead_pools_id_scope_unique');
            $table->foreign('company_id', 'overhead_pools_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'overhead_pools_plant_fk')->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['expense_account_id', 'company_id'], 'overhead_pools_account_fk')->references(['id', 'company_id'])->on('chart_accounts')->restrictOnDelete();
            $table->foreign('created_by', 'overhead_pools_creator_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('overhead_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('overhead_pool_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('allocation_number', 80);
            $table->uuid('fiscal_period_id');
            $table->uuid('production_order_id')->nullable();
            $table->decimal('basis_quantity', 20, 6);
            $table->decimal('rate_snapshot', 20, 6);
            $table->decimal('allocated_amount', 20, 6);
            $table->uuid('journal_id');
            $table->string('status', 16)->default('POSTED');
            $table->uuid('created_by');
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'allocation_number'], 'overhead_allocations_scope_number_unique');
            $table->foreign(['overhead_pool_id', 'company_id', 'plant_id'], 'overhead_allocations_pool_fk')->references(['id', 'company_id', 'plant_id'])->on('overhead_pools')->restrictOnDelete();
            $table->foreign(['fiscal_period_id', 'company_id'], 'overhead_allocations_period_fk')->references(['id', 'company_id'])->on('fiscal_periods')->restrictOnDelete();
            $table->foreign(['production_order_id', 'company_id', 'plant_id'], 'overhead_allocations_order_fk')->references(['id', 'company_id', 'plant_id'])->on('production_orders')->restrictOnDelete();
            $table->foreign(['journal_id', 'company_id', 'plant_id'], 'overhead_allocations_journal_fk')->references(['id', 'company_id', 'plant_id'])->on('journals')->restrictOnDelete();
            $table->foreign('created_by', 'overhead_allocations_creator_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('assets', function (Blueprint $table) {
            $table->string('asset_type', 16)->default('LEGACY')->after('plant_id');
            $table->string('asset_number', 80)->nullable()->after('asset_type');
            $table->string('name', 160)->nullable()->after('asset_number');
            $table->string('category', 40)->nullable()->after('name');
            $table->date('acquisition_date')->nullable()->after('category');
            $table->decimal('acquisition_cost', 20, 6)->nullable()->after('acquisition_date');
            $table->decimal('residual_value', 20, 6)->nullable()->after('acquisition_cost');
            $table->unsignedInteger('useful_life_months')->nullable()->after('residual_value');
            $table->decimal('accumulated_depreciation', 20, 6)->default(0)->after('useful_life_months');
            $table->decimal('net_book_value', 20, 6)->nullable()->after('accumulated_depreciation');
            $table->uuid('asset_account_id')->nullable()->after('net_book_value');
            $table->uuid('depreciation_account_id')->nullable()->after('asset_account_id');
            $table->uuid('depreciation_expense_account_id')->nullable()->after('depreciation_account_id');
            $table->timestampTz('activated_at')->nullable()->after('depreciation_expense_account_id');
            $table->uuid('activated_by')->nullable()->after('activated_at');
            $table->timestampTz('disposed_at')->nullable()->after('activated_by');
            $table->uuid('disposed_by')->nullable()->after('disposed_at');
            $table->decimal('disposal_proceeds', 20, 6)->nullable()->after('disposed_by');
            $table->text('disposal_reason')->nullable()->after('disposal_proceeds');

            $table->unique(['company_id', 'plant_id', 'asset_number'], 'assets_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'assets_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'asset_type', 'status'], 'assets_scope_status_index');
            $table->foreign(['asset_account_id', 'company_id'], 'assets_asset_account_fk')->references(['id', 'company_id'])->on('chart_accounts')->restrictOnDelete();
            $table->foreign(['depreciation_account_id', 'company_id'], 'assets_depreciation_account_fk')->references(['id', 'company_id'])->on('chart_accounts')->restrictOnDelete();
            $table->foreign(['depreciation_expense_account_id', 'company_id'], 'assets_expense_account_fk')->references(['id', 'company_id'])->on('chart_accounts')->restrictOnDelete();
            $table->foreign('activated_by', 'assets_activator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('disposed_by', 'assets_disposer_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('asset_depreciation_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('asset_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->uuid('fiscal_period_id');
            $table->decimal('opening_book_value', 20, 6);
            $table->decimal('depreciation_amount', 20, 6);
            $table->decimal('closing_book_value', 20, 6);
            $table->uuid('journal_id');
            $table->uuid('created_by');
            $table->timestampTz('created_at');

            $table->unique(['asset_id', 'fiscal_period_id'], 'asset_depreciation_asset_period_unique');
            $table->foreign(['asset_id', 'company_id', 'plant_id'], 'asset_depreciation_asset_fk')->references(['id', 'company_id', 'plant_id'])->on('assets')->restrictOnDelete();
            $table->foreign(['fiscal_period_id', 'company_id'], 'asset_depreciation_period_fk')->references(['id', 'company_id'])->on('fiscal_periods')->restrictOnDelete();
            $table->foreign(['journal_id', 'company_id', 'plant_id'], 'asset_depreciation_journal_fk')->references(['id', 'company_id', 'plant_id'])->on('journals')->restrictOnDelete();
            $table->foreign('created_by', 'asset_depreciation_creator_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('employee_number', 40);
            $table->string('name', 160);
            $table->string('department', 80);
            $table->decimal('monthly_gross', 20, 6);
            $table->decimal('monthly_deductions', 20, 6)->default(0);
            $table->uuid('expense_account_id');
            $table->uuid('payable_account_id');
            $table->string('status', 16)->default('ACTIVE');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampsTz();

            $table->unique(['company_id', 'employee_number'], 'employees_company_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'employees_id_scope_unique');
            $table->foreign('company_id', 'employees_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'employees_plant_fk')->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['expense_account_id', 'company_id'], 'employees_expense_account_fk')->references(['id', 'company_id'])->on('chart_accounts')->restrictOnDelete();
            $table->foreign(['payable_account_id', 'company_id'], 'employees_payable_account_fk')->references(['id', 'company_id'])->on('chart_accounts')->restrictOnDelete();
            $table->foreign('created_by', 'employees_creator_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->string('run_type', 16)->default('LEGACY')->after('plant_id');
            $table->string('run_number', 80)->nullable()->after('run_type');
            $table->date('period_start')->nullable()->after('run_number');
            $table->date('period_end')->nullable()->after('period_start');
            $table->char('currency', 3)->nullable()->after('period_end');
            $table->decimal('gross_amount', 20, 6)->nullable()->after('currency');
            $table->decimal('deduction_amount', 20, 6)->nullable()->after('gross_amount');
            $table->decimal('net_amount', 20, 6)->nullable()->after('deduction_amount');
            $table->uuid('journal_id')->nullable()->after('net_amount');
            $table->timestampTz('approved_at')->nullable()->after('journal_id');
            $table->uuid('approved_by')->nullable()->after('approved_at');
            $table->timestampTz('posted_at')->nullable()->after('approved_by');
            $table->uuid('posted_by')->nullable()->after('posted_at');

            $table->unique(['company_id', 'plant_id', 'run_number'], 'payroll_runs_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'payroll_runs_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'run_type', 'status'], 'payroll_runs_scope_status_index');
            $table->foreign(['journal_id', 'company_id', 'plant_id'], 'payroll_runs_journal_fk')->references(['id', 'company_id', 'plant_id'])->on('journals')->restrictOnDelete();
            $table->foreign('approved_by', 'payroll_runs_approver_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('posted_by', 'payroll_runs_poster_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('payroll_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('payroll_run_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('employee_id');
            $table->decimal('gross_amount', 20, 6);
            $table->decimal('deduction_amount', 20, 6);
            $table->decimal('net_amount', 20, 6);
            $table->timestampsTz();

            $table->unique(['payroll_run_id', 'employee_id'], 'payroll_lines_employee_unique');
            $table->foreign(['payroll_run_id', 'company_id', 'plant_id'], 'payroll_lines_run_fk')->references(['id', 'company_id', 'plant_id'])->on('payroll_runs')->cascadeOnDelete();
            $table->foreign(['employee_id', 'company_id', 'plant_id'], 'payroll_lines_employee_fk')->references(['id', 'company_id', 'plant_id'])->on('employees')->restrictOnDelete();
        });

        Schema::table('maintenance_work_orders', function (Blueprint $table) {
            $table->string('work_type', 16)->default('LEGACY')->after('plant_id');
            $table->string('work_order_number', 80)->nullable()->after('work_type');
            $table->uuid('asset_id')->nullable()->after('work_order_number');
            $table->string('priority', 16)->nullable()->after('asset_id');
            $table->text('description')->nullable()->after('priority');
            $table->date('planned_date')->nullable()->after('description');
            $table->decimal('labour_cost', 20, 6)->nullable()->after('planned_date');
            $table->decimal('material_cost', 20, 6)->nullable()->after('labour_cost');
            $table->decimal('external_cost', 20, 6)->nullable()->after('material_cost');
            $table->decimal('total_cost', 20, 6)->nullable()->after('external_cost');
            $table->uuid('expense_account_id')->nullable()->after('total_cost');
            $table->uuid('journal_id')->nullable()->after('expense_account_id');
            $table->timestampTz('released_at')->nullable()->after('journal_id');
            $table->uuid('released_by')->nullable()->after('released_at');
            $table->timestampTz('completed_at')->nullable()->after('released_by');
            $table->uuid('completed_by')->nullable()->after('completed_at');

            $table->unique(['company_id', 'plant_id', 'work_order_number'], 'maintenance_work_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'maintenance_work_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'work_type', 'status'], 'maintenance_work_scope_status_index');
            $table->foreign(['asset_id', 'company_id', 'plant_id'], 'maintenance_work_asset_fk')->references(['id', 'company_id', 'plant_id'])->on('assets')->restrictOnDelete();
            $table->foreign(['expense_account_id', 'company_id'], 'maintenance_work_account_fk')->references(['id', 'company_id'])->on('chart_accounts')->restrictOnDelete();
            $table->foreign(['journal_id', 'company_id', 'plant_id'], 'maintenance_work_journal_fk')->references(['id', 'company_id', 'plant_id'])->on('journals')->restrictOnDelete();
            $table->foreign('released_by', 'maintenance_work_releaser_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('completed_by', 'maintenance_work_completer_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->string('account_code', 40);
            $table->string('bank_name', 160);
            $table->string('account_name', 160);
            $table->string('masked_account_number', 40);
            $table->string('ifsc_code', 20);
            $table->char('currency', 3)->default('INR');
            $table->string('status', 16)->default('ACTIVE');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampsTz();

            $table->unique(['company_id', 'account_code'], 'bank_accounts_company_code_unique');
            $table->unique(['id', 'company_id'], 'bank_accounts_id_company_unique');
            $table->foreign('company_id', 'bank_accounts_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('created_by', 'bank_accounts_creator_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('finance_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('export_number', 80);
            $table->string('export_type', 24);
            $table->uuid('bank_account_id')->nullable();
            $table->date('period_start');
            $table->date('period_end');
            $table->unsignedInteger('record_count');
            $table->decimal('total_amount', 20, 6);
            $table->json('payload_json');
            $table->char('sha256', 64);
            $table->string('status', 16)->default('GENERATED');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('acknowledged_at')->nullable();
            $table->uuid('acknowledged_by')->nullable();
            $table->string('acknowledgement_reference', 120)->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'export_number'], 'finance_exports_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'finance_exports_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'export_type', 'status'], 'finance_exports_scope_status_index');
            $table->foreign('company_id', 'finance_exports_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'finance_exports_plant_fk')->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['bank_account_id', 'company_id'], 'finance_exports_bank_account_fk')->references(['id', 'company_id'])->on('bank_accounts')->restrictOnDelete();
            $table->foreign('created_by', 'finance_exports_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('acknowledged_by', 'finance_exports_acknowledger_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('finance_export_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('finance_export_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedInteger('line_number');
            $table->uuid('supplier_payment_id')->nullable();
            $table->uuid('invoice_id')->nullable();
            $table->string('reference_number', 120);
            $table->decimal('amount', 20, 6);
            $table->timestampsTz();

            $table->unique(['finance_export_id', 'line_number'], 'finance_export_lines_number_unique');
            $table->foreign(['finance_export_id', 'company_id', 'plant_id'], 'finance_export_lines_export_fk')->references(['id', 'company_id', 'plant_id'])->on('finance_exports')->cascadeOnDelete();
            $table->foreign(['supplier_payment_id', 'company_id', 'plant_id'], 'finance_export_lines_supplier_payment_fk')->references(['id', 'company_id', 'plant_id'])->on('supplier_payments')->restrictOnDelete();
            $table->foreign(['invoice_id', 'company_id', 'plant_id'], 'finance_export_lines_invoice_fk')->references(['id', 'company_id', 'plant_id'])->on('invoices')->restrictOnDelete();
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

        Schema::dropIfExists('finance_export_lines');
        Schema::dropIfExists('finance_exports');
        Schema::dropIfExists('bank_accounts');

        Schema::table('maintenance_work_orders', function (Blueprint $table) {
            foreach (['maintenance_work_completer_fk', 'maintenance_work_releaser_fk', 'maintenance_work_journal_fk', 'maintenance_work_account_fk', 'maintenance_work_asset_fk'] as $name) $table->dropForeign($name);
            $table->dropIndex('maintenance_work_scope_status_index');
            $table->dropUnique('maintenance_work_id_scope_unique');
            $table->dropUnique('maintenance_work_scope_number_unique');
            $table->dropColumn(['work_type', 'work_order_number', 'asset_id', 'priority', 'description', 'planned_date', 'labour_cost', 'material_cost', 'external_cost', 'total_cost', 'expense_account_id', 'journal_id', 'released_at', 'released_by', 'completed_at', 'completed_by']);
        });

        Schema::dropIfExists('payroll_lines');
        Schema::table('payroll_runs', function (Blueprint $table) {
            $table->dropForeign('payroll_runs_poster_fk');
            $table->dropForeign('payroll_runs_approver_fk');
            $table->dropForeign('payroll_runs_journal_fk');
            $table->dropIndex('payroll_runs_scope_status_index');
            $table->dropUnique('payroll_runs_id_scope_unique');
            $table->dropUnique('payroll_runs_scope_number_unique');
            $table->dropColumn(['run_type', 'run_number', 'period_start', 'period_end', 'currency', 'gross_amount', 'deduction_amount', 'net_amount', 'journal_id', 'approved_at', 'approved_by', 'posted_at', 'posted_by']);
        });
        Schema::dropIfExists('employees');

        Schema::dropIfExists('asset_depreciation_entries');
        Schema::table('assets', function (Blueprint $table) {
            foreach (['assets_disposer_fk', 'assets_activator_fk', 'assets_expense_account_fk', 'assets_depreciation_account_fk', 'assets_asset_account_fk'] as $name) $table->dropForeign($name);
            $table->dropIndex('assets_scope_status_index');
            $table->dropUnique('assets_id_scope_unique');
            $table->dropUnique('assets_scope_number_unique');
            $table->dropColumn(['asset_type', 'asset_number', 'name', 'category', 'acquisition_date', 'acquisition_cost', 'residual_value', 'useful_life_months', 'accumulated_depreciation', 'net_book_value', 'asset_account_id', 'depreciation_account_id', 'depreciation_expense_account_id', 'activated_at', 'activated_by', 'disposed_at', 'disposed_by', 'disposal_proceeds', 'disposal_reason']);
        });

        Schema::dropIfExists('overhead_allocations');
        Schema::dropIfExists('overhead_pools');
        Schema::dropIfExists('expense_claim_lines');
        Schema::dropIfExists('expense_claims');
        Schema::dropIfExists('journal_lines');
        Schema::table('journals', function (Blueprint $table) {
            $table->dropForeign('journals_reverser_fk');
            $table->dropForeign('journals_poster_fk');
            $table->dropForeign('journals_reversal_fk');
            $table->dropForeign('journals_period_fk');
            $table->dropIndex('journals_scope_status_index');
            $table->dropUnique('journals_id_scope_unique');
            $table->dropUnique('journals_scope_number_unique');
            $table->dropColumn(['journal_type', 'journal_number', 'fiscal_period_id', 'posting_date', 'description', 'currency', 'total_debit', 'total_credit', 'reversal_of_journal_id', 'source_reference', 'posted_at', 'posted_by', 'reversed_at', 'reversed_by', 'reversal_reason']);
        });
        Schema::dropIfExists('fiscal_periods');
        Schema::dropIfExists('chart_accounts');
    }

    private function postgresConstraints(): array
    {
        return [
            ['chart_accounts', 'chart_accounts_values_check', "CHECK (account_type IN ('ASSET', 'LIABILITY', 'EQUITY', 'REVENUE', 'EXPENSE') AND (control_type IS NULL OR control_type IN ('AR', 'AP', 'CASH', 'TAX', 'INVENTORY', 'PAYROLL', 'DEPRECIATION')) AND status IN ('ACTIVE', 'INACTIVE') AND record_version >= 1)"],
            ['fiscal_periods', 'fiscal_periods_values_check', "CHECK (ends_on >= starts_on AND status IN ('OPEN', 'CLOSED') AND record_version >= 1 AND ((status = 'OPEN' AND closed_at IS NULL AND closed_by IS NULL) OR (status = 'CLOSED' AND closed_at IS NOT NULL AND closed_by IS NOT NULL)))"],
            ['journals', 'journals_p2_values_check', "CHECK (journal_type = 'LEGACY' OR (journal_number IS NOT NULL AND fiscal_period_id IS NOT NULL AND posting_date IS NOT NULL AND description IS NOT NULL AND currency = 'INR' AND total_debit >= 0 AND total_credit >= 0 AND total_debit = total_credit AND status IN ('DRAFT', 'POSTED', 'REVERSED')))"],
            ['journal_lines', 'journal_lines_values_check', 'CHECK (line_number >= 1 AND debit_amount >= 0 AND credit_amount >= 0 AND ((debit_amount > 0 AND credit_amount = 0) OR (credit_amount > 0 AND debit_amount = 0)))'],
            ['expense_claims', 'expense_claims_values_check', "CHECK (currency = 'INR' AND net_amount >= 0 AND tax_amount >= 0 AND total_amount = net_amount + tax_amount AND record_version >= 1 AND status IN ('DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'CANCELLED'))"],
            ['expense_claim_lines', 'expense_claim_lines_values_check', 'CHECK (line_number >= 1 AND net_amount > 0 AND tax_rate BETWEEN 0 AND 100 AND tax_amount >= 0 AND gross_amount = net_amount + tax_amount)'],
            ['overhead_pools', 'overhead_pools_values_check', "CHECK (allocation_basis IN ('MACHINE_MINUTE', 'LABOUR_MINUTE', 'OUTPUT_UNIT', 'FIXED') AND rate >= 0 AND status IN ('ACTIVE', 'INACTIVE') AND record_version >= 1)"],
            ['overhead_allocations', 'overhead_allocations_values_check', "CHECK (basis_quantity > 0 AND rate_snapshot >= 0 AND allocated_amount = round(basis_quantity * rate_snapshot, 6) AND status = 'POSTED')"],
            ['assets', 'assets_p2_values_check', "CHECK (asset_type <> 'FIXED_ASSET' OR (asset_number IS NOT NULL AND name IS NOT NULL AND acquisition_date IS NOT NULL AND acquisition_cost > 0 AND residual_value >= 0 AND residual_value <= acquisition_cost AND useful_life_months > 0 AND accumulated_depreciation >= 0 AND net_book_value = acquisition_cost - accumulated_depreciation AND status IN ('DRAFT', 'ACTIVE', 'DISPOSED')))"],
            ['asset_depreciation_entries', 'asset_depreciation_values_check', 'CHECK (opening_book_value >= 0 AND depreciation_amount > 0 AND closing_book_value >= 0 AND closing_book_value = opening_book_value - depreciation_amount)'],
            ['employees', 'employees_values_check', "CHECK (monthly_gross > 0 AND monthly_deductions >= 0 AND monthly_deductions <= monthly_gross AND status IN ('ACTIVE', 'INACTIVE') AND record_version >= 1)"],
            ['payroll_runs', 'payroll_runs_p2_values_check', "CHECK (run_type <> 'PAYROLL' OR (run_number IS NOT NULL AND period_end >= period_start AND currency = 'INR' AND gross_amount > 0 AND deduction_amount >= 0 AND net_amount = gross_amount - deduction_amount AND status IN ('DRAFT', 'APPROVED', 'POSTED')))"],
            ['payroll_lines', 'payroll_lines_values_check', 'CHECK (gross_amount > 0 AND deduction_amount >= 0 AND net_amount = gross_amount - deduction_amount)'],
            ['maintenance_work_orders', 'maintenance_work_p2_values_check', "CHECK (work_type <> 'MAINTENANCE' OR (work_order_number IS NOT NULL AND priority IN ('LOW', 'NORMAL', 'HIGH', 'CRITICAL') AND planned_date IS NOT NULL AND labour_cost >= 0 AND material_cost >= 0 AND external_cost >= 0 AND total_cost = labour_cost + material_cost + external_cost AND status IN ('DRAFT', 'RELEASED', 'COMPLETED', 'CANCELLED')))"],
            ['bank_accounts', 'bank_accounts_values_check', "CHECK (currency = 'INR' AND status IN ('ACTIVE', 'INACTIVE') AND record_version >= 1 AND btrim(masked_account_number) <> '' AND btrim(ifsc_code) <> '')"],
            ['finance_exports', 'finance_exports_values_check', "CHECK (export_type IN ('AP_BANK', 'GST_INPUT', 'GST_OUTPUT') AND period_end >= period_start AND record_count >= 0 AND total_amount >= 0 AND status IN ('GENERATED', 'ACKNOWLEDGED') AND record_version >= 1 AND ((status = 'GENERATED' AND acknowledged_at IS NULL AND acknowledged_by IS NULL) OR (status = 'ACKNOWLEDGED' AND acknowledged_at IS NOT NULL AND acknowledged_by IS NOT NULL AND btrim(acknowledgement_reference) <> '')))"],
            ['finance_export_lines', 'finance_export_lines_values_check', 'CHECK (line_number >= 1 AND amount >= 0)'],
        ];
    }
};

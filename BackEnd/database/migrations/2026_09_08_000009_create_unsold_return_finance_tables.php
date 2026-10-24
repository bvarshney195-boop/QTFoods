<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sales_invoice_financials', function (Blueprint $table) {
            $table->uuid('invoice_id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('plant_id')->index();
            $table->uuid('party_id')->index();
            $table->uuid('shipment_id')->index();
            $table->string('invoice_number', 80);
            $table->string('currency', 3);
            $table->decimal('net_amount', 20, 4);
            $table->decimal('tax_amount', 20, 4);
            $table->decimal('gross_amount', 20, 4);
            $table->decimal('outstanding_amount', 20, 4);
            $table->timestampTz('issued_at')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestampsTz();

            $table->unique(['company_id', 'invoice_number']);
        });

        Schema::create('unsold_return_finance_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('return_case_id')->index();
            $table->uuid('company_id')->index();
            $table->uuid('plant_id')->index();
            $table->uuid('invoice_id')->index();
            $table->string('action_type', 40);
            $table->unsignedBigInteger('case_record_version');
            $table->string('reference_number', 80);
            $table->string('reference_key', 80)->nullable();
            $table->decimal('amount', 20, 4)->nullable();
            $table->string('currency', 3);
            $table->string('tax_code', 32)->nullable();
            $table->decimal('balance_before', 20, 4)->nullable();
            $table->decimal('balance_after', 20, 4)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('actor_id')->index();
            $table->string('idempotency_key', 160);
            $table->timestampTz('posted_at');
            $table->timestampsTz();

            $table->unique(['return_case_id', 'action_type']);
            $table->unique(['return_case_id', 'case_record_version'], 'unsold_finance_case_version_unique');
            $table->unique(
                ['company_id', 'action_type', 'reference_key'],
                'unsold_finance_reference_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unsold_return_finance_actions');
        Schema::dropIfExists('sales_invoice_financials');
    }
};

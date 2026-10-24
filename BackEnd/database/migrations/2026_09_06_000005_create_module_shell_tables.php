<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Module-shell migration:
 * representative tables required by the current frontend routes.
 * Detailed fields should be expanded batch-by-batch from accepted field dictionaries.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach ([
            'requisitions',
            'purchase_orders',
            'receipts',
            'quality_tasks',
            'production_orders',
            'stage_events',
            'fg_lots',
            'sales_orders',
            'shipments',
            'invoices',
            'journals',
            'assets',
            'payroll_runs',
            'maintenance_work_orders',
            'integration_events',
            'report_runs',
        ] as $tableName) {
            Schema::create($tableName, function (Blueprint $table) {
                $table->uuid('id')->primary();
                $table->uuid('company_id')->index();
                $table->uuid('plant_id')->nullable()->index();
                $table->string('status', 40)->default('DRAFT');
                $table->unsignedBigInteger('record_version')->default(1);
                $table->uuid('created_by')->nullable()->index();
                $table->timestampsTz();
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse([
            'requisitions',
            'purchase_orders',
            'receipts',
            'quality_tasks',
            'production_orders',
            'stage_events',
            'fg_lots',
            'sales_orders',
            'shipments',
            'invoices',
            'journals',
            'assets',
            'payroll_runs',
            'maintenance_work_orders',
            'integration_events',
            'report_runs',
        ]) as $tableName) {
            Schema::dropIfExists($tableName);
        }
    }
};

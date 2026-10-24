<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('unsold_return_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('plant_id')->index();
            $table->uuid('party_id')->index();
            $table->uuid('sales_order_id')->nullable()->index();
            $table->uuid('shipment_id')->index();
            $table->uuid('invoice_id')->nullable()->index();
            $table->string('status', 40);
            $table->string('reason_code', 80);
            $table->date('expected_return_date')->nullable();
            $table->text('sales_note')->nullable();
            $table->uuid('maker_id')->index();
            $table->timestampTz('received_at')->nullable();
            $table->uuid('loss_event_id')->nullable()->index();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestampsTz();
        });

        Schema::create('unsold_return_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('return_case_id')->index();
            $table->uuid('shipment_line_id')->nullable()->index();
            $table->uuid('sku_id')->index();
            $table->uuid('fg_lot_id')->nullable()->index();
            $table->decimal('requested_quantity', 20, 6);
            $table->decimal('received_quantity', 20, 6)->default(0);
            $table->decimal('restock_quantity', 20, 6)->default(0);
            $table->decimal('repack_quantity', 20, 6)->default(0);
            $table->decimal('rework_quantity', 20, 6)->default(0);
            $table->decimal('destroy_quantity', 20, 6)->default(0);
            $table->string('uom_code', 16);
            $table->uuid('return_position_id')->nullable()->index();
            $table->string('quality_reason_code', 80)->nullable();
            $table->uuid('quality_reviewer_id')->nullable()->index();
            $table->timestampsTz();
        });

        Schema::create('loss_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('plant_id')->index();
            $table->string('source_type', 64);
            $table->uuid('source_id')->index();
            $table->decimal('quantity_base', 20, 6);
            $table->string('uom_code', 16);
            $table->decimal('cost_amount', 20, 4)->nullable();
            $table->string('currency', 3)->default('INR');
            $table->string('reason_code', 80);
            $table->uuid('approval_request_id')->index();
            $table->uuid('actor_id')->index();
            $table->timestampTz('posted_at');
            $table->timestampTz('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('loss_events');
        Schema::dropIfExists('unsold_return_lines');
        Schema::dropIfExists('unsold_return_cases');
    }
};

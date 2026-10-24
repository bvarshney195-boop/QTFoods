<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('parties', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->string('code', 64);
            $table->string('display_name');
            $table->string('status', 32)->default('DRAFT');
            $table->timestampsTz();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('uoms', function (Blueprint $table) {
            $table->string('code', 16)->primary();
            $table->string('name', 64);
            $table->unsignedSmallInteger('precision')->default(3);
        });

        Schema::create('items', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->string('code', 64);
            $table->string('name');
            $table->string('item_type', 32);
            $table->string('base_uom', 16);
            $table->string('status', 32)->default('DRAFT');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestampsTz();
            $table->unique(['company_id', 'code']);
        });

        Schema::create('lots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('item_id')->index();
            $table->string('internal_lot_code', 80);
            $table->string('supplier_lot_code', 120)->nullable();
            $table->date('manufacture_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->timestampsTz();
            $table->unique(['company_id', 'internal_lot_code']);
        });

        Schema::create('stock_positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('plant_id')->index();
            $table->uuid('item_id')->index();
            $table->uuid('lot_id')->index();
            $table->uuid('owner_party_id')->nullable()->index();
            $table->uuid('location_id')->index();
            $table->string('quality_status', 32);
            $table->decimal('quantity_base', 20, 6)->default(0);
            $table->string('uom_code', 16);
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestampsTz();
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('plant_id')->nullable()->index();
            $table->string('movement_type', 64);
            $table->string('source_type', 64);
            $table->uuid('source_id')->index();
            $table->unsignedBigInteger('source_version')->nullable();
            $table->uuid('from_position_id')->nullable()->index();
            $table->uuid('to_position_id')->nullable()->index();
            $table->decimal('quantity_base', 20, 6);
            $table->string('uom_code', 16);
            $table->uuid('actor_id')->index();
            $table->string('reason_code', 80)->nullable();
            $table->string('idempotency_key', 160);
            $table->timestampTz('event_at');
            $table->timestampTz('posted_at');
            $table->timestampTz('created_at');
            $table->unique(['movement_type', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_positions');
        Schema::dropIfExists('lots');
        Schema::dropIfExists('items');
        Schema::dropIfExists('uoms');
        Schema::dropIfExists('parties');
    }
};

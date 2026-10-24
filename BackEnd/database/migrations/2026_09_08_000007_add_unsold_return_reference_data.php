<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id')->index();
            $table->uuid('plant_id')->index();
            $table->string('code', 64);
            $table->string('name');
            $table->string('location_type', 40);
            $table->string('status', 32)->default('ACTIVE');
            $table->timestampsTz();
            $table->unique(['company_id', 'plant_id', 'code']);
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->string('shipment_number', 80)->nullable()->after('plant_id');
            $table->uuid('party_id')->nullable()->index()->after('shipment_number');
            $table->timestampTz('dispatched_at')->nullable()->after('status');
            $table->unique(
                ['company_id', 'plant_id', 'shipment_number'],
                'shipments_scope_number_unique'
            );
        });

        Schema::create('shipment_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shipment_id')->index();
            $table->uuid('company_id')->index();
            $table->uuid('plant_id')->index();
            $table->uuid('item_id')->index();
            $table->uuid('fg_lot_id')->nullable()->index();
            $table->decimal('shipped_quantity', 20, 6);
            $table->decimal('returned_quantity', 20, 6)->default(0);
            $table->string('uom_code', 16);
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestampsTz();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shipment_lines');

        Schema::table('shipments', function (Blueprint $table) {
            $table->dropUnique('shipments_scope_number_unique');
            $table->dropColumn(['shipment_number', 'party_id', 'dispatched_at']);
        });

        Schema::dropIfExists('locations');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('unsold_return_status_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('return_case_id')->index();
            $table->uuid('company_id')->index();
            $table->uuid('plant_id')->index();
            $table->string('from_status', 40)->nullable();
            $table->string('to_status', 40);
            $table->unsignedBigInteger('record_version');
            $table->uuid('actor_id')->index();
            $table->timestampTz('created_at');

            $table->index(
                ['company_id', 'plant_id', 'return_case_id', 'record_version'],
                'unsold_return_history_scope_case_index'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('unsold_return_status_history');
    }
};

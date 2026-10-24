<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('inventory_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('operation_number', 80);
            $table->string('operation_type', 24);
            $table->string('status', 16)->default('DRAFT');
            $table->string('reason_code', 80);
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->unique(
                ['company_id', 'plant_id', 'operation_number'],
                'inventory_operations_scope_number_unique',
            );
            $table->unique(['id', 'company_id', 'plant_id'], 'inventory_operations_id_scope_unique');
            $table->index(
                ['company_id', 'plant_id', 'operation_type', 'status', 'updated_at'],
                'inventory_operations_scope_type_status_index',
            );
            $table->foreign('company_id', 'inventory_operations_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'inventory_operations_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign('created_by', 'inventory_operations_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('posted_by', 'inventory_operations_poster_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('cancelled_by', 'inventory_operations_canceller_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('inventory_operation_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('operation_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('sequence_no');
            $table->uuid('source_position_id')->nullable();
            $table->uuid('target_position_id')->nullable();
            $table->decimal('quantity_base', 20, 6)->nullable();
            $table->decimal('counted_quantity_base', 20, 6)->nullable();
            $table->decimal('system_quantity_base', 20, 6)->nullable();
            $table->unsignedBigInteger('source_position_version')->nullable();
            $table->string('adjustment_direction', 16)->nullable();
            $table->string('uom_code', 16);
            $table->uuid('movement_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['operation_id', 'sequence_no'], 'inventory_operation_lines_sequence_unique');
            $table->unique('movement_id', 'inventory_operation_lines_movement_unique');
            $table->index(['company_id', 'plant_id', 'source_position_id'], 'inventory_operation_lines_source_index');
            $table->index(['company_id', 'plant_id', 'target_position_id'], 'inventory_operation_lines_target_index');
            $table->foreign(
                ['operation_id', 'company_id', 'plant_id'],
                'inventory_operation_lines_operation_fk',
            )->references(['id', 'company_id', 'plant_id'])->on('inventory_operations')->cascadeOnDelete();
            $table->foreign(
                ['source_position_id', 'company_id', 'plant_id'],
                'inventory_operation_lines_source_fk',
            )->references(['id', 'company_id', 'plant_id'])->on('stock_positions')->restrictOnDelete();
            $table->foreign(
                ['target_position_id', 'company_id', 'plant_id'],
                'inventory_operation_lines_target_fk',
            )->references(['id', 'company_id', 'plant_id'])->on('stock_positions')->restrictOnDelete();
            $table->foreign('uom_code', 'inventory_operation_lines_uom_fk')
                ->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('movement_id', 'inventory_operation_lines_movement_fk')
                ->references('id')->on('stock_movements')->restrictOnDelete();
        });

        $this->addPostgresConstraints();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach ([
                'inventory_operations_type_check' => 'inventory_operations',
                'inventory_operations_status_check' => 'inventory_operations',
                'inventory_operations_version_check' => 'inventory_operations',
                'inventory_operations_lifecycle_check' => 'inventory_operations',
                'inventory_operation_lines_positions_check' => 'inventory_operation_lines',
                'inventory_operation_lines_quantity_check' => 'inventory_operation_lines',
                'inventory_operation_lines_count_check' => 'inventory_operation_lines',
                'inventory_operation_lines_version_check' => 'inventory_operation_lines',
                'inventory_operation_lines_direction_check' => 'inventory_operation_lines',
            ] as $constraint => $table) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            }
        }

        Schema::dropIfExists('inventory_operation_lines');
        Schema::dropIfExists('inventory_operations');
    }

    private function addPostgresConstraints(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ([
            "ALTER TABLE inventory_operations ADD CONSTRAINT inventory_operations_type_check CHECK (operation_type IN ('ISSUE', 'RETURN', 'TRANSFER', 'COUNT', 'ADJUSTMENT', 'EXPIRY', 'DISPOSAL'))",
            "ALTER TABLE inventory_operations ADD CONSTRAINT inventory_operations_status_check CHECK (status IN ('DRAFT', 'POSTED', 'CANCELLED'))",
            'ALTER TABLE inventory_operations ADD CONSTRAINT inventory_operations_version_check CHECK (record_version >= 1)',
            "ALTER TABLE inventory_operations ADD CONSTRAINT inventory_operations_lifecycle_check CHECK ((status = 'DRAFT' AND posted_at IS NULL AND posted_by IS NULL AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL) OR (status = 'POSTED' AND posted_at IS NOT NULL AND posted_by IS NOT NULL AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL) OR (status = 'CANCELLED' AND posted_at IS NULL AND posted_by IS NULL AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND cancellation_reason IS NOT NULL))",
            'ALTER TABLE inventory_operation_lines ADD CONSTRAINT inventory_operation_lines_positions_check CHECK (source_position_id IS NOT NULL OR target_position_id IS NOT NULL)',
            'ALTER TABLE inventory_operation_lines ADD CONSTRAINT inventory_operation_lines_quantity_check CHECK (quantity_base IS NULL OR quantity_base > 0)',
            'ALTER TABLE inventory_operation_lines ADD CONSTRAINT inventory_operation_lines_count_check CHECK ((counted_quantity_base IS NULL OR counted_quantity_base >= 0) AND (system_quantity_base IS NULL OR system_quantity_base >= 0))',
            'ALTER TABLE inventory_operation_lines ADD CONSTRAINT inventory_operation_lines_version_check CHECK (source_position_version IS NULL OR source_position_version >= 1)',
            "ALTER TABLE inventory_operation_lines ADD CONSTRAINT inventory_operation_lines_direction_check CHECK (adjustment_direction IS NULL OR adjustment_direction IN ('INCREASE', 'DECREASE'))",
        ] as $statement) {
            DB::statement($statement);
        }
    }
};

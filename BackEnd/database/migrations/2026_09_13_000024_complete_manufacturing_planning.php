<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->unique(
                ['id', 'company_id', 'plant_id'],
                'stock_reservations_planning_scope_unique',
            );
        });

        Schema::create('demand_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('plan_number', 80);
            $table->string('name', 160);
            $table->date('horizon_start');
            $table->date('horizon_end');
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('DRAFT');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('released_at')->nullable();
            $table->uuid('released_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'plan_number'], 'demand_plans_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'demand_plans_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'horizon_start'], 'demand_plans_scope_status_index');
            $table->foreign('company_id', 'demand_plans_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'demand_plans_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign('created_by', 'demand_plans_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('released_by', 'demand_plans_releaser_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by', 'demand_plans_canceller_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('demand_plan_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('demand_plan_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('output_sku_id');
            $table->date('demand_date');
            $table->string('demand_type', 16);
            $table->decimal('quantity', 20, 6);
            $table->string('uom_code', 16);
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['demand_plan_id', 'line_number'], 'demand_plan_lines_number_unique');
            $table->unique(
                ['id', 'demand_plan_id', 'company_id', 'plant_id', 'output_sku_id', 'uom_code'],
                'demand_plan_lines_planning_identity_unique',
            );
            $table->index(['company_id', 'plant_id', 'demand_date'], 'demand_plan_lines_scope_date_index');
            $table->foreign(['demand_plan_id', 'company_id', 'plant_id'], 'demand_plan_lines_plan_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('demand_plans')->cascadeOnDelete();
            $table->foreign(['output_sku_id', 'company_id'], 'demand_plan_lines_sku_fk')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign('uom_code', 'demand_plan_lines_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
        });

        Schema::create('mrp_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('run_number', 80);
            $table->uuid('demand_plan_id');
            $table->unsignedBigInteger('demand_plan_version_snapshot');
            $table->date('run_date');
            $table->string('status', 16)->default('COMPLETED');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('completed_at');
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'run_number'], 'mrp_runs_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'mrp_runs_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'run_date'], 'mrp_runs_scope_status_index');
            $table->foreign('company_id', 'mrp_runs_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'mrp_runs_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['demand_plan_id', 'company_id', 'plant_id'], 'mrp_runs_demand_plan_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('demand_plans')->restrictOnDelete();
            $table->foreign('created_by', 'mrp_runs_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by', 'mrp_runs_canceller_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('mrp_planned_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('mrp_run_id');
            $table->uuid('demand_plan_id');
            $table->uuid('demand_plan_line_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('output_sku_id');
            $table->uuid('recipe_id');
            $table->unsignedInteger('recipe_revision_snapshot');
            $table->date('due_date');
            $table->decimal('planned_quantity', 20, 6);
            $table->string('uom_code', 16);
            $table->timestampsTz();

            $table->unique(['mrp_run_id', 'line_number'], 'mrp_planned_orders_number_unique');
            $table->unique(['mrp_run_id', 'demand_plan_line_id'], 'mrp_planned_orders_demand_line_unique');
            $table->unique(
                ['id', 'mrp_run_id', 'company_id', 'plant_id'],
                'mrp_planned_orders_id_run_scope_unique',
            );
            $table->foreign(['mrp_run_id', 'company_id', 'plant_id'], 'mrp_planned_orders_run_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('mrp_runs')->cascadeOnDelete();
            $table->foreign(
                ['demand_plan_line_id', 'demand_plan_id', 'company_id', 'plant_id', 'output_sku_id', 'uom_code'],
                'mrp_planned_orders_demand_line_fk',
            )->references(
                ['id', 'demand_plan_id', 'company_id', 'plant_id', 'output_sku_id', 'uom_code'],
            )->on('demand_plan_lines')->restrictOnDelete();
            $table->foreign(['recipe_id', 'company_id'], 'mrp_planned_orders_recipe_fk')
                ->references(['id', 'company_id'])->on('recipes')->restrictOnDelete();
            $table->foreign(['output_sku_id', 'company_id'], 'mrp_planned_orders_sku_fk')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign('uom_code', 'mrp_planned_orders_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
        });

        Schema::create('mrp_material_requirements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('mrp_run_id');
            $table->uuid('mrp_planned_order_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('recipe_component_id');
            $table->uuid('component_sku_id');
            $table->string('uom_code', 16);
            $table->decimal('gross_requirement', 20, 6);
            $table->decimal('on_hand_snapshot', 20, 6);
            $table->decimal('reserved_snapshot', 20, 6);
            $table->decimal('available_snapshot', 20, 6);
            $table->decimal('shortage_quantity', 20, 6);
            $table->timestampsTz();

            $table->unique(['mrp_planned_order_id', 'recipe_component_id'], 'mrp_material_requirements_component_unique');
            $table->unique(
                ['id', 'mrp_planned_order_id', 'mrp_run_id', 'company_id', 'plant_id'],
                'mrp_material_requirements_identity_unique',
            );
            $table->index(['mrp_run_id', 'component_sku_id'], 'mrp_material_requirements_run_sku_index');
            $table->foreign(
                ['mrp_planned_order_id', 'mrp_run_id', 'company_id', 'plant_id'],
                'mrp_material_requirements_order_fk',
            )->references(
                ['id', 'mrp_run_id', 'company_id', 'plant_id'],
            )->on('mrp_planned_orders')->cascadeOnDelete();
            $table->foreign('recipe_component_id', 'mrp_material_requirements_recipe_component_fk')
                ->references('id')->on('recipe_components')->restrictOnDelete();
            $table->foreign(['component_sku_id', 'company_id'], 'mrp_material_requirements_sku_fk')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign('uom_code', 'mrp_material_requirements_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
        });

        Schema::create('production_schedules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('schedule_number', 80);
            $table->uuid('mrp_run_id');
            $table->date('horizon_start');
            $table->date('horizon_end');
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('DRAFT');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('released_at')->nullable();
            $table->uuid('released_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'schedule_number'], 'production_schedules_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'production_schedules_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'horizon_start'], 'production_schedules_scope_status_index');
            $table->foreign('company_id', 'production_schedules_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'production_schedules_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['mrp_run_id', 'company_id', 'plant_id'], 'production_schedules_run_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('mrp_runs')->restrictOnDelete();
            $table->foreign('created_by', 'production_schedules_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('released_by', 'production_schedules_releaser_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by', 'production_schedules_canceller_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('production_schedule_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('production_schedule_id');
            $table->uuid('mrp_run_id');
            $table->uuid('mrp_planned_order_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('output_sku_id');
            $table->uuid('recipe_id');
            $table->uuid('route_id');
            $table->decimal('planned_quantity', 20, 6);
            $table->string('uom_code', 16);
            $table->date('planned_start_date');
            $table->date('planned_end_date');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->unique(['production_schedule_id', 'line_number'], 'production_schedule_lines_number_unique');
            $table->unique(['production_schedule_id', 'mrp_planned_order_id'], 'production_schedule_lines_order_unique');
            $table->unique(
                ['id', 'production_schedule_id', 'company_id', 'plant_id'],
                'production_schedule_lines_identity_unique',
            );
            $table->foreign(['production_schedule_id', 'company_id', 'plant_id'], 'production_schedule_lines_schedule_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('production_schedules')->cascadeOnDelete();
            $table->foreign(
                ['mrp_planned_order_id', 'mrp_run_id', 'company_id', 'plant_id'],
                'production_schedule_lines_order_fk',
            )->references(
                ['id', 'mrp_run_id', 'company_id', 'plant_id'],
            )->on('mrp_planned_orders')->restrictOnDelete();
            $table->foreign(['recipe_id', 'company_id'], 'production_schedule_lines_recipe_fk')
                ->references(['id', 'company_id'])->on('recipes')->restrictOnDelete();
            $table->foreign(['route_id', 'company_id'], 'production_schedule_lines_route_fk')
                ->references(['id', 'company_id'])->on('production_routes')->restrictOnDelete();
            $table->foreign(['output_sku_id', 'company_id'], 'production_schedule_lines_sku_fk')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign('uom_code', 'production_schedule_lines_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
        });

        Schema::create('production_schedule_operations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('production_schedule_id');
            $table->uuid('production_schedule_line_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->uuid('route_operation_id');
            $table->unsignedSmallInteger('sequence_no');
            $table->string('operation_name', 160);
            $table->string('work_center_code', 64);
            $table->decimal('setup_minutes_snapshot', 12, 3);
            $table->decimal('run_minutes_per_unit_snapshot', 12, 6);
            $table->decimal('required_minutes', 20, 6);
            $table->timestampsTz();

            $table->unique(['production_schedule_line_id', 'sequence_no'], 'production_schedule_operations_sequence_unique');
            $table->index(['production_schedule_id', 'work_center_code'], 'production_schedule_operations_center_index');
            $table->foreign(
                ['production_schedule_line_id', 'production_schedule_id', 'company_id', 'plant_id'],
                'production_schedule_operations_line_fk',
            )->references(
                ['id', 'production_schedule_id', 'company_id', 'plant_id'],
            )->on('production_schedule_lines')->cascadeOnDelete();
            $table->foreign('route_operation_id', 'production_schedule_operations_route_operation_fk')
                ->references('id')->on('route_operations')->restrictOnDelete();
        });

        Schema::create('production_schedule_capacities', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('production_schedule_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('work_center_code', 64);
            $table->decimal('daily_capacity_minutes', 20, 6);
            $table->unsignedSmallInteger('working_days');
            $table->decimal('available_minutes', 20, 6);
            $table->decimal('required_minutes', 20, 6);
            $table->decimal('utilisation_percent', 9, 3);
            $table->boolean('is_overloaded')->default(false);
            $table->timestampsTz();

            $table->unique(['production_schedule_id', 'work_center_code'], 'production_schedule_capacities_center_unique');
            $table->foreign(['production_schedule_id', 'company_id', 'plant_id'], 'production_schedule_capacities_schedule_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('production_schedules')->cascadeOnDelete();
        });

        Schema::create('production_material_reservations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('production_schedule_id');
            $table->uuid('production_schedule_line_id');
            $table->uuid('mrp_run_id');
            $table->uuid('mrp_planned_order_id');
            $table->uuid('mrp_material_requirement_id');
            $table->uuid('stock_reservation_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->decimal('quantity_base', 20, 6);
            $table->string('uom_code', 16);
            $table->timestampTz('released_at')->nullable();
            $table->timestampsTz();

            $table->unique('stock_reservation_id', 'production_material_reservations_stock_unique');
            $table->index(['production_schedule_id', 'mrp_material_requirement_id'], 'production_material_reservations_requirement_index');
            $table->foreign(['production_schedule_id', 'company_id', 'plant_id'], 'production_material_reservations_schedule_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('production_schedules')->restrictOnDelete();
            $table->foreign(
                ['production_schedule_line_id', 'production_schedule_id', 'company_id', 'plant_id'],
                'production_material_reservations_line_fk',
            )->references(
                ['id', 'production_schedule_id', 'company_id', 'plant_id'],
            )->on('production_schedule_lines')->restrictOnDelete();
            $table->foreign(
                ['mrp_material_requirement_id', 'mrp_planned_order_id', 'mrp_run_id', 'company_id', 'plant_id'],
                'production_material_reservations_requirement_fk',
            )->references(
                ['id', 'mrp_planned_order_id', 'mrp_run_id', 'company_id', 'plant_id'],
            )->on('mrp_material_requirements')->restrictOnDelete();
            $table->foreign(
                ['stock_reservation_id', 'company_id', 'plant_id'],
                'production_material_reservations_stock_fk',
            )->references(
                ['id', 'company_id', 'plant_id'],
            )->on('stock_reservations')->restrictOnDelete();
            $table->foreign('uom_code', 'production_material_reservations_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
        });

        $this->addPostgresControls();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS mrp_runs_one_active_plan_unique');
            DB::statement('DROP INDEX IF EXISTS production_schedule_lines_one_active_order_unique');
            foreach ($this->postgresConstraints() as [$table, $name]) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            }
        }

        Schema::dropIfExists('production_material_reservations');
        Schema::dropIfExists('production_schedule_capacities');
        Schema::dropIfExists('production_schedule_operations');
        Schema::dropIfExists('production_schedule_lines');
        Schema::dropIfExists('production_schedules');
        Schema::dropIfExists('mrp_material_requirements');
        Schema::dropIfExists('mrp_planned_orders');
        Schema::dropIfExists('mrp_runs');
        Schema::dropIfExists('demand_plan_lines');
        Schema::dropIfExists('demand_plans');
        Schema::table('stock_reservations', function (Blueprint $table) {
            $table->dropUnique('stock_reservations_planning_scope_unique');
        });
    }

    private function addPostgresControls(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->postgresConstraints() as [$table, $name, $definition]) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition}");
        }
        DB::statement(
            "CREATE UNIQUE INDEX mrp_runs_one_active_plan_unique ON mrp_runs (demand_plan_id) WHERE status = 'COMPLETED'"
        );
        DB::statement(
            'CREATE UNIQUE INDEX production_schedule_lines_one_active_order_unique ON production_schedule_lines (mrp_planned_order_id) WHERE is_active'
        );
    }

    private function postgresConstraints(): array
    {
        return [
            ['demand_plans', 'demand_plans_values_check', 'CHECK (horizon_end >= horizon_start AND record_version >= 1)'],
            ['demand_plans', 'demand_plans_status_check', "CHECK (status IN ('DRAFT', 'RELEASED', 'CANCELLED'))"],
            ['demand_plans', 'demand_plans_workflow_check', "CHECK ((status = 'DRAFT' AND released_at IS NULL AND cancelled_at IS NULL) OR (status = 'RELEASED' AND released_at IS NOT NULL AND released_by IS NOT NULL AND cancelled_at IS NULL) OR (status = 'CANCELLED' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND btrim(cancellation_reason) <> ''))"],
            ['demand_plan_lines', 'demand_plan_lines_values_check', "CHECK (line_number >= 1 AND quantity > 0 AND demand_type IN ('FORECAST', 'FIRM', 'SAFETY_STOCK'))"],
            ['mrp_runs', 'mrp_runs_values_check', 'CHECK (record_version >= 1 AND demand_plan_version_snapshot >= 1)'],
            ['mrp_runs', 'mrp_runs_status_check', "CHECK (status IN ('COMPLETED', 'CANCELLED'))"],
            ['mrp_runs', 'mrp_runs_workflow_check', "CHECK ((status = 'COMPLETED' AND completed_at IS NOT NULL AND cancelled_at IS NULL) OR (status = 'CANCELLED' AND completed_at IS NOT NULL AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND btrim(cancellation_reason) <> ''))"],
            ['mrp_planned_orders', 'mrp_planned_orders_values_check', 'CHECK (line_number >= 1 AND recipe_revision_snapshot >= 1 AND planned_quantity > 0)'],
            ['mrp_material_requirements', 'mrp_material_requirements_values_check', 'CHECK (line_number >= 1 AND gross_requirement > 0 AND on_hand_snapshot >= 0 AND reserved_snapshot >= 0 AND available_snapshot >= 0 AND shortage_quantity >= 0)'],
            ['production_schedules', 'production_schedules_values_check', 'CHECK (horizon_end >= horizon_start AND record_version >= 1)'],
            ['production_schedules', 'production_schedules_status_check', "CHECK (status IN ('DRAFT', 'RELEASED', 'CANCELLED'))"],
            ['production_schedules', 'production_schedules_workflow_check', "CHECK ((status = 'DRAFT' AND released_at IS NULL AND cancelled_at IS NULL) OR (status = 'RELEASED' AND released_at IS NOT NULL AND released_by IS NOT NULL AND cancelled_at IS NULL) OR (status = 'CANCELLED' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND btrim(cancellation_reason) <> ''))"],
            ['production_schedule_lines', 'production_schedule_lines_values_check', 'CHECK (line_number >= 1 AND planned_quantity > 0 AND planned_end_date >= planned_start_date)'],
            ['production_schedule_operations', 'production_schedule_operations_values_check', 'CHECK (sequence_no >= 1 AND setup_minutes_snapshot >= 0 AND run_minutes_per_unit_snapshot >= 0 AND required_minutes >= 0)'],
            ['production_schedule_capacities', 'production_schedule_capacities_values_check', 'CHECK (daily_capacity_minutes > 0 AND working_days >= 1 AND available_minutes > 0 AND required_minutes >= 0 AND utilisation_percent >= 0 AND is_overloaded = (required_minutes > available_minutes))'],
            ['production_material_reservations', 'production_material_reservations_values_check', 'CHECK (quantity_base > 0)'],
        ];
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('optimisation_plans', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('plan_number', 80);
            $table->string('name', 160);
            $table->date('horizon_start');
            $table->date('horizon_end');
            $table->string('status', 20)->default('DRAFT');
            $table->unsignedInteger('current_input_version')->default(1);
            $table->unsignedInteger('current_recommendation_version')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('generated_at')->nullable();
            $table->uuid('generated_by')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'plan_number'], 'optimisation_plans_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'optimisation_plans_scope_identity_unique');
            $table->index(['company_id', 'plant_id', 'status', 'horizon_start'], 'optimisation_plans_scope_status_index');
            $table->foreign('company_id', 'optimisation_plans_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'optimisation_plans_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            foreach (['created_by' => 'creator', 'generated_by' => 'generator', 'completed_by' => 'completer', 'cancelled_by' => 'canceller'] as $column => $name) {
                $table->foreign($column, 'optimisation_plans_'.$name.'_fk')
                    ->references('id')->on('users')->restrictOnDelete();
            }
        });

        Schema::create('optimisation_input_versions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('optimisation_plan_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedInteger('version_number');
            $table->uuid('demand_plan_id');
            $table->unsignedBigInteger('demand_plan_version_snapshot');
            $table->string('objective', 32);
            $table->decimal('service_level_target', 7, 3);
            $table->decimal('safety_stock_percent', 7, 3);
            $table->unsignedSmallInteger('planning_lead_days');
            $table->decimal('max_utilisation_percent', 7, 3);
            $table->decimal('holding_cost_rate', 9, 4);
            $table->decimal('shortage_penalty_rate', 20, 6);
            $table->string('currency', 3)->default('INR');
            $table->text('assumptions')->nullable();
            $table->char('input_checksum', 64);
            $table->uuid('created_by');
            $table->timestampTz('created_at');

            $table->unique(['optimisation_plan_id', 'version_number'], 'optimisation_inputs_plan_version_unique');
            $table->unique(
                ['id', 'optimisation_plan_id', 'company_id', 'plant_id'],
                'optimisation_inputs_scope_identity_unique',
            );
            $table->foreign(
                ['optimisation_plan_id', 'company_id', 'plant_id'],
                'optimisation_inputs_plan_fk',
            )->references(
                ['id', 'company_id', 'plant_id'],
            )->on('optimisation_plans')->cascadeOnDelete();
            $table->foreign(
                ['demand_plan_id', 'company_id', 'plant_id'],
                'optimisation_inputs_demand_fk',
            )->references(
                ['id', 'company_id', 'plant_id'],
            )->on('demand_plans')->restrictOnDelete();
            $table->foreign('created_by', 'optimisation_inputs_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('optimisation_input_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('input_version_id');
            $table->uuid('optimisation_plan_id');
            $table->uuid('demand_plan_id');
            $table->uuid('demand_plan_line_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('output_sku_id');
            $table->date('demand_date');
            $table->string('demand_type', 16);
            $table->decimal('demand_quantity', 20, 6);
            $table->decimal('available_quantity_snapshot', 20, 6);
            $table->unsignedInteger('excluded_stock_position_count')->default(0);
            $table->unsignedInteger('material_shortage_count')->default(0);
            $table->json('material_shortages_json')->nullable();
            $table->decimal('unit_cost_snapshot', 20, 6);
            $table->string('uom_code', 16);
            $table->timestampTz('created_at');

            $table->unique(['input_version_id', 'line_number'], 'optimisation_input_lines_number_unique');
            $table->unique(['input_version_id', 'demand_plan_line_id'], 'optimisation_input_lines_demand_unique');
            $table->unique(
                ['id', 'input_version_id', 'optimisation_plan_id', 'company_id', 'plant_id'],
                'optimisation_input_lines_identity_unique',
            );
            $table->foreign(
                ['input_version_id', 'optimisation_plan_id', 'company_id', 'plant_id'],
                'optimisation_input_lines_version_fk',
            )->references(
                ['id', 'optimisation_plan_id', 'company_id', 'plant_id'],
            )->on('optimisation_input_versions')->cascadeOnDelete();
            $table->foreign(
                ['demand_plan_line_id', 'demand_plan_id', 'company_id', 'plant_id', 'output_sku_id', 'uom_code'],
                'optimisation_input_lines_demand_fk',
            )->references(
                ['id', 'demand_plan_id', 'company_id', 'plant_id', 'output_sku_id', 'uom_code'],
            )->on('demand_plan_lines')->restrictOnDelete();
        });

        Schema::create('optimisation_recommendations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('optimisation_plan_id');
            $table->uuid('input_version_id');
            $table->uuid('input_line_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedInteger('recommendation_version');
            $table->unsignedSmallInteger('line_number');
            $table->string('action_type', 16);
            $table->decimal('target_quantity', 20, 6);
            $table->decimal('stock_allocation_quantity', 20, 6);
            $table->decimal('production_quantity', 20, 6);
            $table->string('uom_code', 16);
            $table->date('proposed_start_date');
            $table->date('proposed_end_date');
            $table->string('priority', 16);
            $table->decimal('expected_service_level', 7, 3);
            $table->decimal('estimated_cost', 20, 6);
            $table->string('currency', 3);
            $table->text('rationale');
            $table->string('algorithm_code', 48);
            $table->string('algorithm_version', 24);
            $table->uuid('generated_by');
            $table->timestampTz('generated_at');
            $table->timestampTz('created_at');

            $table->unique(
                ['optimisation_plan_id', 'recommendation_version', 'line_number'],
                'optimisation_recommendations_line_unique',
            );
            $table->unique(
                ['input_version_id', 'input_line_id'],
                'optimisation_recommendations_input_unique',
            );
            $table->unique(
                ['id', 'optimisation_plan_id', 'input_version_id', 'company_id', 'plant_id'],
                'optimisation_recommendations_identity_unique',
            );
            $table->foreign(
                ['input_line_id', 'input_version_id', 'optimisation_plan_id', 'company_id', 'plant_id'],
                'optimisation_recommendations_input_fk',
            )->references(
                ['id', 'input_version_id', 'optimisation_plan_id', 'company_id', 'plant_id'],
            )->on('optimisation_input_lines')->cascadeOnDelete();
            $table->foreign('uom_code', 'optimisation_recommendations_uom_fk')
                ->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('generated_by', 'optimisation_recommendations_generator_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('optimisation_recommendation_limitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recommendation_id');
            $table->uuid('optimisation_plan_id');
            $table->uuid('input_version_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('limitation_code', 48);
            $table->string('severity', 16);
            $table->text('description');
            $table->json('evidence_json')->nullable();
            $table->timestampTz('created_at');

            $table->unique(
                ['recommendation_id', 'limitation_code'],
                'optimisation_limitations_code_unique',
            );
            $table->index(
                ['optimisation_plan_id', 'severity'],
                'optimisation_limitations_plan_severity_index',
            );
            $table->foreign(
                ['recommendation_id', 'optimisation_plan_id', 'input_version_id', 'company_id', 'plant_id'],
                'optimisation_limitations_recommendation_fk',
            )->references(
                ['id', 'optimisation_plan_id', 'input_version_id', 'company_id', 'plant_id'],
            )->on('optimisation_recommendations')->cascadeOnDelete();
        });

        Schema::create('optimisation_plan_reviews', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('optimisation_plan_id');
            $table->uuid('input_version_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedInteger('review_round');
            $table->string('status', 16)->default('PENDING');
            $table->uuid('submitted_by');
            $table->timestampTz('submitted_at');
            $table->uuid('decided_by')->nullable();
            $table->timestampTz('decided_at')->nullable();
            $table->text('decision_notes')->nullable();
            $table->timestampsTz();

            $table->unique(['optimisation_plan_id', 'review_round'], 'optimisation_reviews_round_unique');
            $table->unique(['optimisation_plan_id', 'input_version_id'], 'optimisation_reviews_input_unique');
            $table->foreign(
                ['input_version_id', 'optimisation_plan_id', 'company_id', 'plant_id'],
                'optimisation_reviews_input_fk',
            )->references(
                ['id', 'optimisation_plan_id', 'company_id', 'plant_id'],
            )->on('optimisation_input_versions')->restrictOnDelete();
            $table->foreign('submitted_by', 'optimisation_reviews_submitter_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('decided_by', 'optimisation_reviews_decider_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('optimisation_outcomes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recommendation_id');
            $table->uuid('optimisation_plan_id');
            $table->uuid('input_version_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('result', 16);
            $table->decimal('actual_stock_quantity', 20, 6);
            $table->decimal('actual_production_quantity', 20, 6);
            $table->decimal('actual_service_level', 7, 3);
            $table->decimal('actual_cost', 20, 6);
            $table->string('currency', 3);
            $table->date('observed_on');
            $table->text('notes');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('recorded_by');
            $table->timestampTz('recorded_at');
            $table->uuid('updated_by')->nullable();
            $table->timestampsTz();

            $table->unique('recommendation_id', 'optimisation_outcomes_recommendation_unique');
            $table->foreign(
                ['recommendation_id', 'optimisation_plan_id', 'input_version_id', 'company_id', 'plant_id'],
                'optimisation_outcomes_recommendation_fk',
            )->references(
                ['id', 'optimisation_plan_id', 'input_version_id', 'company_id', 'plant_id'],
            )->on('optimisation_recommendations')->restrictOnDelete();
            $table->foreign('recorded_by', 'optimisation_outcomes_recorder_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('updated_by', 'optimisation_outcomes_updater_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        $this->addPostgresControls();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach ($this->postgresConstraints() as [$table, $name]) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            }
        }

        Schema::dropIfExists('optimisation_outcomes');
        Schema::dropIfExists('optimisation_plan_reviews');
        Schema::dropIfExists('optimisation_recommendation_limitations');
        Schema::dropIfExists('optimisation_recommendations');
        Schema::dropIfExists('optimisation_input_lines');
        Schema::dropIfExists('optimisation_input_versions');
        Schema::dropIfExists('optimisation_plans');
    }

    private function addPostgresControls(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }
        foreach ($this->postgresConstraints() as [$table, $name, $definition]) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition}");
        }
    }

    private function postgresConstraints(): array
    {
        return [
            ['optimisation_plans', 'optimisation_plans_values_check', 'CHECK (horizon_end >= horizon_start AND current_input_version >= 1 AND (current_recommendation_version IS NULL OR current_recommendation_version >= 1) AND record_version >= 1)'],
            ['optimisation_plans', 'optimisation_plans_status_check', "CHECK (status IN ('DRAFT', 'GENERATED', 'SUBMITTED', 'APPROVED', 'REJECTED', 'COMPLETED', 'CANCELLED'))"],
            ['optimisation_plans', 'optimisation_plans_generation_check', "CHECK ((status = 'DRAFT' AND current_recommendation_version IS NULL AND generated_at IS NULL AND generated_by IS NULL) OR (status IN ('GENERATED', 'SUBMITTED', 'APPROVED', 'REJECTED', 'COMPLETED') AND current_recommendation_version = current_input_version AND generated_at IS NOT NULL AND generated_by IS NOT NULL) OR status = 'CANCELLED')"],
            ['optimisation_plans', 'optimisation_plans_completion_check', "CHECK ((status = 'COMPLETED' AND completed_at IS NOT NULL AND completed_by IS NOT NULL AND cancelled_at IS NULL) OR (status <> 'COMPLETED' AND completed_at IS NULL AND completed_by IS NULL))"],
            ['optimisation_plans', 'optimisation_plans_cancellation_check', "CHECK ((status = 'CANCELLED' AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND btrim(cancellation_reason) <> '' AND completed_at IS NULL) OR (status <> 'CANCELLED' AND cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL))"],
            ['optimisation_input_versions', 'optimisation_inputs_values_check', "CHECK (version_number >= 1 AND demand_plan_version_snapshot >= 1 AND objective IN ('BALANCED', 'SERVICE', 'COST', 'INVENTORY') AND service_level_target > 0 AND service_level_target <= 100 AND safety_stock_percent >= 0 AND safety_stock_percent <= 100 AND planning_lead_days <= 365 AND max_utilisation_percent > 0 AND max_utilisation_percent <= 100 AND holding_cost_rate >= 0 AND shortage_penalty_rate >= 0 AND currency ~ '^[A-Z]{3}$' AND input_checksum ~ '^[0-9a-f]{64}$')"],
            ['optimisation_input_lines', 'optimisation_input_lines_values_check', "CHECK (line_number >= 1 AND demand_type IN ('FORECAST', 'FIRM', 'SAFETY_STOCK') AND demand_quantity > 0 AND available_quantity_snapshot >= 0 AND excluded_stock_position_count >= 0 AND material_shortage_count >= 0 AND unit_cost_snapshot >= 0)"],
            ['optimisation_recommendations', 'optimisation_recommendations_values_check', "CHECK (recommendation_version >= 1 AND line_number >= 1 AND action_type IN ('STOCK', 'MIXED', 'PRODUCE') AND target_quantity > 0 AND stock_allocation_quantity >= 0 AND production_quantity >= 0 AND target_quantity = stock_allocation_quantity + production_quantity AND proposed_end_date >= proposed_start_date AND priority IN ('NORMAL', 'HIGH', 'CRITICAL') AND expected_service_level > 0 AND expected_service_level <= 100 AND estimated_cost >= 0 AND currency ~ '^[A-Z]{3}$' AND ((action_type = 'STOCK' AND stock_allocation_quantity > 0 AND production_quantity = 0) OR (action_type = 'MIXED' AND stock_allocation_quantity > 0 AND production_quantity > 0) OR (action_type = 'PRODUCE' AND stock_allocation_quantity = 0 AND production_quantity > 0)))"],
            ['optimisation_recommendation_limitations', 'optimisation_limitations_values_check', "CHECK (severity IN ('INFO', 'WARNING', 'BLOCKER') AND btrim(description) <> '')"],
            ['optimisation_plan_reviews', 'optimisation_reviews_values_check', "CHECK (review_round >= 1 AND status IN ('PENDING', 'APPROVED', 'REJECTED') AND ((status = 'PENDING' AND decided_by IS NULL AND decided_at IS NULL AND decision_notes IS NULL) OR (status IN ('APPROVED', 'REJECTED') AND decided_by IS NOT NULL AND decided_at IS NOT NULL AND decision_notes IS NOT NULL AND btrim(decision_notes) <> '' AND decided_by <> submitted_by)))"],
            ['optimisation_outcomes', 'optimisation_outcomes_values_check', "CHECK (result IN ('ACHIEVED', 'PARTIAL', 'MISSED') AND actual_stock_quantity >= 0 AND actual_production_quantity >= 0 AND actual_service_level >= 0 AND actual_service_level <= 100 AND actual_cost >= 0 AND currency ~ '^[A-Z]{3}$' AND btrim(notes) <> '' AND record_version >= 1)"],
        ];
    }
};

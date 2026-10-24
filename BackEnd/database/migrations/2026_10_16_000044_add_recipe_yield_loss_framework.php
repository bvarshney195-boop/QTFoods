<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('recipes', function (Blueprint $table): void {
            $table->decimal('basis_quantity', 20, 6)->default(1);
            $table->string('basis_uom_code', 16)->nullable();
            $table->string('calculation_model', 32)->default('LEGACY_YIELD');
            $table->decimal('standard_piece_weight', 20, 6)->nullable();
            $table->string('piece_weight_uom_code', 16)->nullable();
            $table->decimal('target_finished_weight', 20, 6)->nullable();
            $table->string('target_weight_uom_code', 16)->nullable();
            $table->unsignedInteger('pieces_per_pack')->default(1);
            $table->decimal('handling_loss_percent', 9, 6)->default(0);
            $table->boolean('handling_loss_in_standard')->default(false);
            $table->decimal('transformation_loss_percent', 9, 6)->default(0);
            $table->boolean('transformation_loss_in_standard')->default(true);
            $table->decimal('rejection_allowance_percent', 9, 6)->default(0);
            $table->string('output_rounding_mode', 16)->default('DOWN');
            $table->decimal('unit_conversion_rate', 20, 9)->nullable();

            $table->foreign('basis_uom_code', 'recipes_basis_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('piece_weight_uom_code', 'recipes_piece_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('target_weight_uom_code', 'recipes_target_weight_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
        });
        DB::table('recipes')->whereNull('basis_uom_code')->update([
            'basis_uom_code' => DB::raw('output_uom_code'),
            'basis_quantity' => DB::raw('output_quantity'),
        ]);
        Schema::table('recipes', function (Blueprint $table): void {
            $table->string('basis_uom_code', 16)->nullable(false)->change();
        });

        Schema::table('recipe_components', function (Blueprint $table): void {
            $table->string('scaling_method', 20)->default('SCALABLE');
            $table->decimal('bakers_percentage', 12, 6)->nullable();
            $table->boolean('is_basis_component')->default(false);
            $table->string('condition_code', 80)->nullable();
            $table->decimal('quantity_per_unit', 20, 9)->nullable();
            $table->boolean('loss_included_in_standard')->default(false);
            $table->boolean('is_packaging')->default(false);
        });

        Schema::table('production_routes', function (Blueprint $table): void {
            $table->unsignedInteger('revision')->default(1);
        });

        Schema::create('stage_measurement_templates', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('recipe_id');
            $table->uuid('route_id');
            $table->uuid('route_operation_id');
            $table->unsignedInteger('recipe_revision');
            $table->unsignedInteger('route_revision');
            $table->unsignedInteger('revision');
            $table->string('name', 160);
            $table->string('status', 16)->default('DRAFT');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('retired_by')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->text('retirement_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['recipe_id', 'route_operation_id', 'revision'], 'stage_measure_templates_revision_unique');
            $table->unique(['id', 'company_id'], 'stage_measure_templates_scope_unique');
            $table->index(['company_id', 'recipe_id', 'route_id', 'status'], 'stage_measure_templates_lookup_index');
            $table->foreign('company_id', 'stage_measure_templates_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['recipe_id', 'company_id'], 'stage_measure_templates_recipe_fk')->references(['id', 'company_id'])->on('recipes')->restrictOnDelete();
            $table->foreign(['route_id', 'company_id'], 'stage_measure_templates_route_fk')->references(['id', 'company_id'])->on('production_routes')->restrictOnDelete();
            $table->foreign('route_operation_id', 'stage_measure_templates_operation_fk')->references('id')->on('route_operations')->restrictOnDelete();
            $table->foreign('created_by', 'stage_measure_templates_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by', 'stage_measure_templates_approver_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('retired_by', 'stage_measure_templates_retirer_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('stage_measurement_template_lines', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('template_id');
            $table->uuid('company_id');
            $table->unsignedSmallInteger('sequence_no');
            $table->string('measurement_kind', 40);
            $table->string('quantity_dimension', 16);
            $table->string('uom_code', 16);
            $table->boolean('is_required')->default(true);
            $table->string('default_value_source', 16)->default('MEASURED');
            $table->string('measurement_method', 120)->nullable();
            $table->decimal('balance_tolerance', 20, 6)->default(0);
            $table->timestampsTz();

            $table->unique(['template_id', 'sequence_no'], 'stage_measure_template_lines_sequence_unique');
            $table->unique(['template_id', 'measurement_kind', 'quantity_dimension'], 'stage_measure_template_lines_kind_unique');
            $table->foreign(['template_id', 'company_id'], 'stage_measure_template_lines_template_fk')->references(['id', 'company_id'])->on('stage_measurement_templates')->cascadeOnDelete();
            $table->foreign('uom_code', 'stage_measure_template_lines_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
        });

        Schema::table('production_orders', function (Blueprint $table): void {
            $table->unsignedInteger('recipe_revision_snapshot')->nullable();
            $table->unsignedInteger('route_revision_snapshot')->nullable();
            $table->string('shift_code', 40)->nullable();
            $table->json('recipe_standard_snapshot')->nullable();
            $table->string('calculation_model_snapshot', 32)->nullable();
            $table->decimal('recipe_basis_quantity_snapshot', 20, 6)->nullable();
            $table->decimal('planned_basis_quantity', 20, 6)->nullable();
            $table->decimal('scaling_factor', 20, 9)->nullable();
            $table->decimal('theoretical_input_mass', 20, 6)->nullable();
            $table->decimal('theoretical_complete_units', 20, 6)->nullable();
            $table->decimal('theoretical_residual_quantity', 20, 6)->nullable();
            $table->decimal('expected_formed_units', 20, 6)->nullable();
            $table->decimal('expected_good_units', 20, 6)->nullable();
            $table->string('expected_output_exception', 64)->nullable();
        });
        DB::statement('UPDATE production_orders SET recipe_revision_snapshot = (SELECT revision FROM recipes WHERE recipes.id = production_orders.recipe_id), route_revision_snapshot = (SELECT revision FROM production_routes WHERE production_routes.id = production_orders.route_id), calculation_model_snapshot = \'LEGACY_YIELD\', expected_good_units = planned_quantity, theoretical_complete_units = planned_quantity, expected_formed_units = planned_quantity, scaling_factor = 1');

        Schema::table('production_order_materials', function (Blueprint $table): void {
            $table->string('scaling_method_snapshot', 20)->default('SCALABLE');
            $table->boolean('is_packaging')->default(false);
            $table->decimal('original_plan_quantity', 20, 6)->nullable();
            $table->decimal('reserved_quantity', 20, 6)->default(0);
            $table->decimal('additional_issued_quantity', 20, 6)->default(0);
            $table->decimal('returned_quantity', 20, 6)->default(0);
            $table->decimal('wasted_quantity', 20, 6)->default(0);
            $table->decimal('rework_in_quantity', 20, 6)->default(0);
            $table->decimal('rework_out_quantity', 20, 6)->default(0);
            $table->decimal('net_consumed_quantity', 20, 6)->default(0);
            $table->decimal('normalised_standard_quantity', 20, 6)->nullable();
            $table->decimal('original_plan_variance', 20, 6)->default(0);
            $table->decimal('efficiency_variance', 20, 6)->default(0);
        });
        DB::statement('UPDATE production_order_materials SET original_plan_quantity = required_quantity, reserved_quantity = required_quantity, net_consumed_quantity = issued_quantity, normalised_standard_quantity = required_quantity, original_plan_variance = issued_quantity - required_quantity, efficiency_variance = issued_quantity - required_quantity');

        Schema::table('production_material_issues', function (Blueprint $table): void {
            $table->dropUnique('production_material_issues_reservation_unique');
            $table->index('production_material_reservation_id', 'production_material_issues_reservation_index');
        });

        Schema::table('production_order_stages', function (Blueprint $table): void {
            $table->uuid('measurement_template_id')->nullable();
            $table->unsignedInteger('measurement_template_revision')->nullable();
            $table->json('measurement_template_snapshot')->nullable();
            $table->foreign('measurement_template_id', 'production_stages_measure_template_fk')->references('id')->on('stage_measurement_templates')->restrictOnDelete();
        });

        Schema::create('production_stage_measurements', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('production_order_id');
            $table->uuid('production_order_stage_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedInteger('entry_sequence');
            $table->string('measurement_kind', 40);
            $table->string('quantity_dimension', 16);
            $table->decimal('quantity', 20, 6);
            $table->string('uom_code', 16);
            $table->string('value_source', 16);
            $table->string('measurement_source', 120);
            $table->string('measurement_method', 120);
            $table->decimal('target_quantity', 20, 6)->nullable();
            $table->string('reason_code', 80)->nullable();
            $table->string('disposition_code', 80)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('recorded_by');
            $table->timestampTz('recorded_at');
            $table->uuid('reversal_of_id')->nullable();
            $table->timestampTz('created_at');

            $table->unique(['production_order_stage_id', 'entry_sequence'], 'production_stage_measurements_sequence_unique');
            $table->index(['company_id', 'plant_id', 'measurement_kind', 'recorded_at'], 'production_stage_measurements_scope_index');
            $table->foreign(['production_order_id', 'company_id', 'plant_id'], 'production_stage_measurements_order_fk')->references(['id', 'company_id', 'plant_id'])->on('production_orders')->restrictOnDelete();
            $table->foreign(['production_order_stage_id', 'production_order_id', 'company_id', 'plant_id'], 'production_stage_measurements_stage_fk')->references(['id', 'production_order_id', 'company_id', 'plant_id'])->on('production_order_stages')->restrictOnDelete();
            $table->foreign('uom_code', 'production_stage_measurements_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('recorded_by', 'production_stage_measurements_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('production_stage_measurements', function (Blueprint $table): void {
            $table->foreign('reversal_of_id', 'production_stage_measurements_reversal_fk')
                ->references('id')->on('production_stage_measurements')->restrictOnDelete();
        });

        Schema::table('production_output_events', function (Blueprint $table): void {
            $table->string('loss_category', 40)->nullable();
            $table->string('disposition_code', 80)->nullable();
            $table->string('value_source', 16)->default('MEASURED');
            $table->string('quantity_dimension', 16)->default('COUNT');
            $table->decimal('weight_quantity', 20, 6)->nullable();
            $table->string('weight_uom_code', 16)->nullable();
            $table->foreign('weight_uom_code', 'production_output_events_weight_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
        });

        Schema::create('production_material_postings', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('production_order_id');
            $table->uuid('production_order_material_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('posting_type', 32);
            $table->decimal('quantity', 20, 6);
            $table->string('uom_code', 16);
            $table->uuid('lot_id');
            $table->uuid('inventory_owner_id');
            $table->uuid('from_position_id')->nullable();
            $table->uuid('to_position_id')->nullable();
            // Inventory-changing postings own one stock movement. WIP-only waste/rework
            // classifications are themselves the authoritative ledger effect and do
            // not decrement inventory a second time after the original issue.
            $table->uuid('stock_movement_id')->nullable();
            $table->uuid('parent_posting_id')->nullable();
            $table->string('quality_status', 32);
            $table->string('status', 20)->default('POSTED');
            $table->string('reason_code', 80);
            $table->string('disposition_code', 80)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('posted_by');
            $table->timestampTz('posted_at');
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('approved_at')->nullable();
            $table->timestampsTz();

            $table->unique('stock_movement_id', 'production_material_postings_movement_unique');
            $table->index(['production_order_id', 'production_order_material_id', 'posting_type'], 'production_material_postings_order_index');
            $table->foreign(['production_order_id', 'company_id', 'plant_id'], 'production_material_postings_order_fk')->references(['id', 'company_id', 'plant_id'])->on('production_orders')->restrictOnDelete();
            $table->foreign(['production_order_material_id', 'company_id', 'plant_id'], 'production_material_postings_material_fk')->references(['id', 'company_id', 'plant_id'])->on('production_order_materials')->restrictOnDelete();
            $table->foreign(['lot_id', 'company_id'], 'production_material_postings_lot_fk')->references(['id', 'company_id'])->on('lots')->restrictOnDelete();
            $table->foreign('inventory_owner_id', 'production_material_postings_owner_fk')->references('id')->on('inventory_owners')->restrictOnDelete();
            $table->foreign('from_position_id', 'production_material_postings_from_fk')->references('id')->on('stock_positions')->restrictOnDelete();
            $table->foreign('to_position_id', 'production_material_postings_to_fk')->references('id')->on('stock_positions')->restrictOnDelete();
            $table->foreign('stock_movement_id', 'production_material_postings_movement_fk')->references('id')->on('stock_movements')->restrictOnDelete();
            $table->foreign('uom_code', 'production_material_postings_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('posted_by', 'production_material_postings_actor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by', 'production_material_postings_approver_fk')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::table('production_material_postings', function (Blueprint $table): void {
            $table->foreign('parent_posting_id', 'production_material_postings_parent_fk')
                ->references('id')->on('production_material_postings')->restrictOnDelete();
        });

        Schema::table('lot_genealogy_edges', function (Blueprint $table): void {
            $table->uuid('production_material_issue_id')->nullable()->change();
            $table->uuid('production_material_posting_id')->nullable();
            $table->unique(['production_material_posting_id', 'output_lot_id'], 'lot_genealogy_posting_output_unique');
            $table->foreign('production_material_posting_id', 'lot_genealogy_posting_fk')->references('id')->on('production_material_postings')->restrictOnDelete();
        });

        Schema::create('production_batch_adjustments', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('production_order_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('adjustment_number', 80);
            $table->string('adjustment_type', 32);
            $table->json('payload');
            $table->text('reason');
            $table->string('status', 20)->default('PENDING');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('requested_by');
            $table->timestampTz('requested_at');
            $table->uuid('reviewed_by')->nullable();
            $table->timestampTz('reviewed_at')->nullable();
            $table->text('review_notes')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'adjustment_number'], 'production_batch_adjustments_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'production_batch_adjustments_scope_unique');
            $table->foreign(['production_order_id', 'company_id', 'plant_id'], 'production_batch_adjustments_order_fk')->references(['id', 'company_id', 'plant_id'])->on('production_orders')->restrictOnDelete();
            $table->foreign('requested_by', 'production_batch_adjustments_requester_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reviewed_by', 'production_batch_adjustments_reviewer_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('production_batch_adjustment_effects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('production_batch_adjustment_id');
            $table->uuid('production_order_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('metric_code', 64);
            $table->string('subject_type', 40);
            $table->uuid('subject_id')->nullable();
            $table->decimal('signed_quantity', 20, 6)->default(0);
            $table->string('uom_code', 16)->nullable();
            $table->decimal('signed_value', 20, 6)->default(0);
            $table->string('currency', 3)->nullable();
            $table->text('reason');
            $table->uuid('approved_by');
            $table->timestampTz('approved_at');
            $table->timestampTz('created_at');

            $table->index(['production_order_id', 'metric_code', 'subject_id'], 'production_batch_adjust_effects_lookup_index');
            $table->foreign(['production_batch_adjustment_id', 'company_id', 'plant_id'], 'production_batch_adjust_effects_adjustment_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('production_batch_adjustments')->restrictOnDelete();
            $table->foreign(['production_order_id', 'company_id', 'plant_id'], 'production_batch_adjust_effects_order_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('production_orders')->restrictOnDelete();
            $table->foreign('uom_code', 'production_batch_adjust_effects_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('approved_by', 'production_batch_adjust_effects_approver_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('packing_runs', function (Blueprint $table): void {
            $table->unsignedInteger('pieces_per_pack')->default(1);
            $table->decimal('target_finished_weight', 20, 6)->nullable();
            $table->decimal('average_sample_weight', 20, 6)->nullable();
            $table->string('weight_uom_code', 16)->nullable();
            $table->json('actual_weight_samples')->nullable();
            $table->json('packing_configuration')->nullable();
            $table->foreign('weight_uom_code', 'packing_runs_weight_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
        });

        Schema::table('batch_costs', function (Blueprint $table): void {
            $table->decimal('expected_good_quantity', 20, 6)->nullable();
            $table->decimal('output_variance', 20, 6)->nullable();
            $table->decimal('output_variance_percent', 12, 6)->nullable();
            $table->decimal('normal_loss_quantity', 20, 6)->default(0);
            $table->decimal('abnormal_loss_quantity', 20, 6)->default(0);
            $table->decimal('rework_quantity', 20, 6)->default(0);
            $table->decimal('rework_value', 20, 6)->default(0);
            $table->decimal('overweight_giveaway_quantity', 20, 6)->default(0);
            $table->decimal('overweight_giveaway_cost', 20, 6)->default(0);
            $table->decimal('packaging_variance_quantity', 20, 6)->default(0);
            $table->decimal('packaging_variance_cost', 20, 6)->default(0);
            $table->decimal('net_material_cost_per_good_unit', 20, 6)->nullable();
            $table->boolean('all_source_costs_included')->default(false);
            $table->decimal('full_manufacturing_cost_per_good_unit', 20, 6)->nullable();
        });

        Schema::table('batch_cost_material_lines', function (Blueprint $table): void {
            $table->boolean('is_packaging')->default(false);
            $table->decimal('normalised_standard_quantity', 20, 6)->nullable();
            $table->decimal('original_plan_quantity_variance', 20, 6)->default(0);
            $table->decimal('efficiency_quantity_variance', 20, 6)->default(0);
            $table->decimal('original_plan_cost_variance', 20, 6)->default(0);
            $table->decimal('efficiency_cost_variance', 20, 6)->default(0);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE recipes ADD CONSTRAINT recipes_yield_framework_check CHECK (basis_quantity > 0 AND pieces_per_pack >= 1 AND handling_loss_percent BETWEEN 0 AND 100 AND transformation_loss_percent BETWEEN 0 AND 100 AND rejection_allowance_percent BETWEEN 0 AND 100 AND calculation_model IN ('LEGACY_YIELD', 'WEIGHT_PIECE', 'UNIT_CONVERSION', 'PERCENT_YIELD') AND output_rounding_mode IN ('DOWN', 'UP', 'HALF_UP', 'NONE'))");
            DB::statement("ALTER TABLE recipe_components ADD CONSTRAINT recipe_components_scaling_check CHECK (scaling_method IN ('SCALABLE', 'FIXED', 'PER_UNIT', 'CONDITIONAL') AND (bakers_percentage IS NULL OR bakers_percentage >= 0) AND (quantity_per_unit IS NULL OR quantity_per_unit >= 0))");
            DB::statement("ALTER TABLE production_order_materials DROP CONSTRAINT IF EXISTS production_order_materials_values_check");
            DB::statement('ALTER TABLE production_order_materials ADD CONSTRAINT production_order_materials_values_check CHECK (line_number >= 1 AND required_quantity > 0 AND issued_quantity >= 0 AND issued_quantity <= required_quantity AND additional_issued_quantity >= 0 AND returned_quantity >= 0 AND wasted_quantity >= 0 AND rework_in_quantity >= 0 AND rework_out_quantity >= 0 AND net_consumed_quantity >= 0)');
            DB::statement("ALTER TABLE stage_measurement_templates ADD CONSTRAINT stage_measure_templates_status_check CHECK (status IN ('DRAFT', 'APPROVED', 'RETIRED') AND recipe_revision >= 1 AND route_revision >= 1 AND revision >= 1 AND record_version >= 1)");
            DB::statement("ALTER TABLE stage_measurement_template_lines ADD CONSTRAINT stage_measure_template_lines_values_check CHECK (sequence_no >= 1 AND quantity_dimension IN ('MASS', 'COUNT', 'VOLUME', 'PACK') AND default_value_source IN ('MEASURED', 'ESTIMATED', 'DERIVED') AND balance_tolerance >= 0)");
            DB::statement("ALTER TABLE production_stage_measurements ADD CONSTRAINT production_stage_measurements_values_check CHECK (entry_sequence >= 1 AND quantity >= 0 AND quantity_dimension IN ('MASS', 'COUNT', 'VOLUME', 'PACK') AND value_source IN ('MEASURED', 'ESTIMATED', 'DERIVED'))");
            DB::statement("ALTER TABLE production_material_postings ADD CONSTRAINT production_material_postings_values_check CHECK (quantity > 0 AND posting_type IN ('PLANNED_ISSUE', 'ADDITIONAL_ISSUE', 'RETURN_QUARANTINE', 'RETURN_RELEASE', 'RETURN_REJECT', 'WASTE', 'REWORK_IN', 'REWORK_OUT') AND status IN ('POSTED', 'QC_PENDING', 'RELEASED', 'REJECTED') AND ((posting_type IN ('WASTE', 'REWORK_IN', 'REWORK_OUT') AND stock_movement_id IS NULL) OR (posting_type NOT IN ('WASTE', 'REWORK_IN', 'REWORK_OUT') AND stock_movement_id IS NOT NULL)))");
            DB::statement("ALTER TABLE production_batch_adjustments ADD CONSTRAINT production_batch_adjustments_values_check CHECK (status IN ('PENDING', 'APPROVED', 'REJECTED') AND adjustment_type IN ('MATERIAL', 'OUTPUT', 'STAGE_MEASUREMENT', 'COST') AND record_version >= 1 AND (status = 'PENDING' OR reviewed_by IS NOT NULL AND reviewed_at IS NOT NULL))");
            DB::statement("ALTER TABLE production_batch_adjustment_effects ADD CONSTRAINT production_batch_adjust_effects_values_check CHECK (metric_code IN ('GOOD_OUTPUT', 'LOSS_OUTPUT', 'REWORK_OUTPUT', 'MATERIAL_NET_CONSUMPTION', 'STAGE_MEASUREMENT', 'COST_VALUE') AND (signed_quantity <> 0 OR signed_value <> 0) AND (currency IS NULL OR currency = 'INR'))");
            DB::statement("CREATE UNIQUE INDEX stage_measure_templates_one_approved ON stage_measurement_templates (recipe_id, route_operation_id) WHERE status = 'APPROVED'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS stage_measure_templates_one_approved');
            foreach ([
                ['production_batch_adjustments', 'production_batch_adjustments_values_check'],
                ['production_batch_adjustment_effects', 'production_batch_adjust_effects_values_check'],
                ['production_material_postings', 'production_material_postings_values_check'],
                ['production_stage_measurements', 'production_stage_measurements_values_check'],
                ['stage_measurement_template_lines', 'stage_measure_template_lines_values_check'],
                ['stage_measurement_templates', 'stage_measure_templates_status_check'],
                ['recipe_components', 'recipe_components_scaling_check'],
                ['recipes', 'recipes_yield_framework_check'],
            ] as [$table, $constraint]) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$constraint}");
            }
            DB::statement('ALTER TABLE production_order_materials DROP CONSTRAINT IF EXISTS production_order_materials_values_check');
            DB::statement('ALTER TABLE production_order_materials ADD CONSTRAINT production_order_materials_values_check CHECK (line_number >= 1 AND required_quantity > 0 AND issued_quantity >= 0 AND issued_quantity <= required_quantity)');
        }

        Schema::table('batch_cost_material_lines', function (Blueprint $table): void {
            $table->dropColumn(['is_packaging', 'normalised_standard_quantity', 'original_plan_quantity_variance',
                'efficiency_quantity_variance', 'original_plan_cost_variance', 'efficiency_cost_variance']);
        });
        Schema::table('batch_costs', function (Blueprint $table): void {
            $table->dropColumn(['expected_good_quantity', 'output_variance', 'output_variance_percent',
                'normal_loss_quantity', 'abnormal_loss_quantity', 'rework_quantity', 'rework_value',
                'overweight_giveaway_quantity', 'overweight_giveaway_cost', 'packaging_variance_quantity',
                'packaging_variance_cost', 'net_material_cost_per_good_unit',
                'all_source_costs_included', 'full_manufacturing_cost_per_good_unit']);
        });
        Schema::table('packing_runs', function (Blueprint $table): void {
            $table->dropForeign('packing_runs_weight_uom_fk');
            $table->dropColumn(['pieces_per_pack', 'target_finished_weight', 'average_sample_weight', 'weight_uom_code',
                'actual_weight_samples', 'packing_configuration']);
        });
        Schema::dropIfExists('production_batch_adjustment_effects');
        Schema::dropIfExists('production_batch_adjustments');
        Schema::table('lot_genealogy_edges', function (Blueprint $table): void {
            $table->dropForeign('lot_genealogy_posting_fk');
            $table->dropUnique('lot_genealogy_posting_output_unique');
            $table->dropColumn('production_material_posting_id');
            $table->uuid('production_material_issue_id')->nullable(false)->change();
        });
        Schema::dropIfExists('production_material_postings');
        Schema::table('production_output_events', function (Blueprint $table): void {
            $table->dropForeign('production_output_events_weight_uom_fk');
            $table->dropColumn(['loss_category', 'disposition_code', 'value_source', 'quantity_dimension', 'weight_quantity', 'weight_uom_code']);
        });
        Schema::dropIfExists('production_stage_measurements');
        Schema::table('production_order_stages', function (Blueprint $table): void {
            $table->dropForeign('production_stages_measure_template_fk');
            $table->dropColumn(['measurement_template_id', 'measurement_template_revision', 'measurement_template_snapshot']);
        });
        Schema::table('production_order_materials', function (Blueprint $table): void {
            $table->dropColumn(['scaling_method_snapshot', 'is_packaging', 'original_plan_quantity', 'reserved_quantity',
                'additional_issued_quantity', 'returned_quantity', 'wasted_quantity', 'rework_in_quantity',
                'rework_out_quantity', 'net_consumed_quantity', 'normalised_standard_quantity',
                'original_plan_variance', 'efficiency_variance']);
        });
        Schema::table('production_material_issues', function (Blueprint $table): void {
            $table->dropIndex('production_material_issues_reservation_index');
            $table->unique('production_material_reservation_id', 'production_material_issues_reservation_unique');
        });
        Schema::table('production_orders', function (Blueprint $table): void {
            $table->dropColumn(['recipe_revision_snapshot', 'route_revision_snapshot', 'shift_code', 'recipe_standard_snapshot',
                'calculation_model_snapshot', 'recipe_basis_quantity_snapshot', 'planned_basis_quantity', 'scaling_factor',
                'theoretical_input_mass', 'theoretical_complete_units', 'theoretical_residual_quantity',
                'expected_formed_units', 'expected_good_units', 'expected_output_exception']);
        });
        Schema::dropIfExists('stage_measurement_template_lines');
        Schema::dropIfExists('stage_measurement_templates');
        Schema::table('production_routes', function (Blueprint $table): void {
            $table->dropColumn('revision');
        });
        Schema::table('recipe_components', function (Blueprint $table): void {
            $table->dropColumn(['scaling_method', 'bakers_percentage', 'is_basis_component', 'condition_code',
                'quantity_per_unit', 'loss_included_in_standard', 'is_packaging']);
        });
        Schema::table('recipes', function (Blueprint $table): void {
            $table->dropForeign('recipes_target_weight_uom_fk');
            $table->dropForeign('recipes_piece_uom_fk');
            $table->dropForeign('recipes_basis_uom_fk');
            $table->dropColumn(['basis_quantity', 'basis_uom_code', 'calculation_model', 'standard_piece_weight',
                'piece_weight_uom_code', 'target_finished_weight', 'target_weight_uom_code', 'pieces_per_pack',
                'handling_loss_percent', 'handling_loss_in_standard', 'transformation_loss_percent',
                'transformation_loss_in_standard', 'rejection_allowance_percent', 'output_rounding_mode',
                'unit_conversion_rate']);
        });
    }
};

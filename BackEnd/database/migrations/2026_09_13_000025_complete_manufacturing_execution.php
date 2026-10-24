<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('production_material_reservations', function (Blueprint $table) {
            $table->unique(['id', 'company_id', 'plant_id'], 'prod_mat_res_execution_scope_unique');
        });

        Schema::table('production_orders', function (Blueprint $table) {
            // The original module shell is intentionally expanded in place so
            // downstream identifiers remain stable across the ERP rollout.
            $table->string('order_number', 80)->nullable();
            $table->string('batch_number', 80)->nullable();
            $table->uuid('production_schedule_id')->nullable();
            $table->uuid('production_schedule_line_id')->nullable();
            $table->uuid('output_sku_id')->nullable();
            $table->uuid('recipe_id')->nullable();
            $table->uuid('route_id')->nullable();
            $table->decimal('planned_quantity', 20, 6)->nullable();
            $table->string('uom_code', 16)->nullable();
            $table->date('planned_start_date')->nullable();
            $table->date('planned_end_date')->nullable();
            $table->string('quality_status', 20)->default('PENDING');
            $table->text('notes')->nullable();
            $table->timestampTz('released_at')->nullable();
            $table->uuid('released_by')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->timestampTz('quality_released_at')->nullable();
            $table->uuid('quality_released_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->unique(['company_id', 'plant_id', 'order_number'], 'production_orders_scope_number_unique');
            $table->unique(['company_id', 'plant_id', 'batch_number'], 'production_orders_scope_batch_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'production_orders_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'planned_start_date'], 'production_orders_scope_status_index');
            $table->foreign('company_id', 'production_orders_execution_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'production_orders_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(
                ['production_schedule_line_id', 'production_schedule_id', 'company_id', 'plant_id'],
                'production_orders_schedule_line_fk',
            )->references(
                ['id', 'production_schedule_id', 'company_id', 'plant_id'],
            )->on('production_schedule_lines')->restrictOnDelete();
            $table->foreign(['output_sku_id', 'company_id'], 'production_orders_sku_fk')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign(['recipe_id', 'company_id'], 'production_orders_recipe_fk')
                ->references(['id', 'company_id'])->on('recipes')->restrictOnDelete();
            $table->foreign(['route_id', 'company_id'], 'production_orders_route_fk')
                ->references(['id', 'company_id'])->on('production_routes')->restrictOnDelete();
            $table->foreign('uom_code', 'production_orders_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('created_by', 'production_orders_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('released_by', 'production_orders_releaser_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('completed_by', 'production_orders_completer_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('quality_released_by', 'production_orders_quality_releaser_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by', 'production_orders_canceller_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('production_order_materials', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('production_order_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('mrp_material_requirement_id');
            $table->uuid('component_sku_id');
            $table->decimal('required_quantity', 20, 6);
            $table->decimal('issued_quantity', 20, 6)->default(0);
            $table->string('uom_code', 16);
            $table->timestampsTz();

            $table->unique(['production_order_id', 'line_number'], 'production_order_materials_number_unique');
            $table->unique(['production_order_id', 'mrp_material_requirement_id'], 'production_order_materials_requirement_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'production_order_materials_id_scope_unique');
            $table->foreign(['production_order_id', 'company_id', 'plant_id'], 'production_order_materials_order_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('production_orders')->cascadeOnDelete();
            $table->foreign('mrp_material_requirement_id', 'production_order_materials_requirement_fk')
                ->references('id')->on('mrp_material_requirements')->restrictOnDelete();
            $table->foreign(['component_sku_id', 'company_id'], 'production_order_materials_sku_fk')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign('uom_code', 'production_order_materials_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
        });

        Schema::create('production_order_stages', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('production_order_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->uuid('production_schedule_operation_id');
            $table->unsignedSmallInteger('sequence_no');
            $table->string('operation_name', 160);
            $table->string('work_center_code', 64);
            $table->decimal('planned_minutes', 20, 6);
            $table->decimal('actual_minutes', 20, 6)->nullable();
            $table->string('status', 20)->default('PENDING');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestampTz('started_at')->nullable();
            $table->uuid('started_by')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['production_order_id', 'sequence_no'], 'production_order_stages_sequence_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'production_order_stages_id_scope_unique');
            $table->unique(['id', 'production_order_id', 'company_id', 'plant_id'], 'production_order_stages_identity_unique');
            $table->index(['company_id', 'plant_id', 'status', 'work_center_code'], 'production_order_stages_scope_status_index');
            $table->foreign(['production_order_id', 'company_id', 'plant_id'], 'production_order_stages_order_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('production_orders')->cascadeOnDelete();
            $table->foreign('production_schedule_operation_id', 'production_order_stages_schedule_op_fk')
                ->references('id')->on('production_schedule_operations')->restrictOnDelete();
            $table->foreign('started_by', 'production_order_stages_starter_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('completed_by', 'production_order_stages_completer_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('stage_events', function (Blueprint $table) {
            $table->uuid('production_order_id')->nullable();
            $table->uuid('production_order_stage_id')->nullable();
            $table->string('event_type', 16)->nullable();
            $table->timestampTz('event_at')->nullable();
            $table->decimal('actual_minutes', 20, 6)->nullable();
            $table->text('notes')->nullable();

            $table->unique(['id', 'company_id', 'plant_id'], 'stage_events_id_scope_unique');
            $table->index(['production_order_id', 'production_order_stage_id', 'event_at'], 'stage_events_order_stage_index');
            $table->foreign(['production_order_id', 'company_id', 'plant_id'], 'stage_events_order_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('production_orders')->restrictOnDelete();
            $table->foreign(
                ['production_order_stage_id', 'production_order_id', 'company_id', 'plant_id'],
                'stage_events_stage_fk',
            )->references(
                ['id', 'production_order_id', 'company_id', 'plant_id'],
            )->on('production_order_stages')->restrictOnDelete();
            $table->foreign('created_by', 'stage_events_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('production_material_issues', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('production_order_id');
            $table->uuid('production_order_material_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->uuid('production_material_reservation_id');
            $table->uuid('stock_reservation_id');
            $table->uuid('source_position_id');
            $table->uuid('input_lot_id');
            $table->uuid('stock_movement_id');
            $table->decimal('quantity', 20, 6);
            $table->string('uom_code', 16);
            $table->uuid('issued_by');
            $table->timestampTz('issued_at');
            $table->timestampTz('created_at');

            $table->unique('production_material_reservation_id', 'production_material_issues_reservation_unique');
            $table->unique('stock_movement_id', 'production_material_issues_movement_unique');
            $table->index(['production_order_id', 'production_order_material_id'], 'production_material_issues_order_material_index');
            $table->foreign(['production_order_id', 'company_id', 'plant_id'], 'production_material_issues_order_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('production_orders')->restrictOnDelete();
            $table->foreign(['production_order_material_id', 'company_id', 'plant_id'], 'production_material_issues_material_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('production_order_materials')->restrictOnDelete();
            $table->foreign(['production_material_reservation_id', 'company_id', 'plant_id'], 'production_material_issues_plan_res_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('production_material_reservations')->restrictOnDelete();
            $table->foreign(['stock_reservation_id', 'company_id', 'plant_id'], 'production_material_issues_stock_res_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('stock_reservations')->restrictOnDelete();
            $table->foreign(['source_position_id', 'company_id', 'plant_id'], 'production_material_issues_position_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('stock_positions')->restrictOnDelete();
            $table->foreign(['input_lot_id', 'company_id'], 'production_material_issues_lot_fk')
                ->references(['id', 'company_id'])->on('lots')->restrictOnDelete();
            $table->foreign('stock_movement_id', 'production_material_issues_movement_fk')
                ->references('id')->on('stock_movements')->restrictOnDelete();
            $table->foreign('uom_code', 'production_material_issues_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('issued_by', 'production_material_issues_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('production_output_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('production_order_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('sequence_no');
            $table->string('event_type', 16);
            $table->decimal('quantity', 20, 6);
            $table->string('uom_code', 16);
            $table->string('reason_code', 80)->nullable();
            $table->text('notes')->nullable();
            $table->string('rework_status', 16)->default('NA');
            $table->uuid('recorded_by');
            $table->timestampTz('recorded_at');
            $table->uuid('resolved_by')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->timestampsTz();

            $table->unique(['production_order_id', 'sequence_no'], 'production_output_events_sequence_unique');
            $table->unique(['id', 'production_order_id', 'company_id', 'plant_id'], 'production_output_events_identity_unique');
            $table->index(['company_id', 'plant_id', 'event_type', 'rework_status'], 'production_output_events_scope_index');
            $table->foreign(['production_order_id', 'company_id', 'plant_id'], 'production_output_events_order_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('production_orders')->cascadeOnDelete();
            $table->foreign('uom_code', 'production_output_events_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('recorded_by', 'production_output_events_recorder_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('resolved_by', 'production_output_events_resolver_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('lab_samples', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('sample_number', 80);
            $table->uuid('production_order_id');
            $table->uuid('specification_id');
            $table->unsignedBigInteger('specification_version_snapshot');
            $table->string('status', 16)->default('PENDING');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->text('notes')->nullable();
            $table->timestampTz('sampled_at');
            $table->uuid('sampled_by');
            $table->timestampTz('completed_at')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'sample_number'], 'lab_samples_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'lab_samples_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'sampled_at'], 'lab_samples_scope_status_index');
            $table->foreign(['production_order_id', 'company_id', 'plant_id'], 'lab_samples_order_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('production_orders')->restrictOnDelete();
            $table->foreign(['specification_id', 'company_id'], 'lab_samples_specification_fk')
                ->references(['id', 'company_id'])->on('quality_specifications')->restrictOnDelete();
            $table->foreign('sampled_by', 'lab_samples_sampler_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('completed_by', 'lab_samples_completer_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('lab_results', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('lab_sample_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->uuid('specification_parameter_id');
            $table->unsignedSmallInteger('sequence_no');
            $table->string('parameter_code', 64);
            $table->string('parameter_name', 160);
            $table->string('value_type', 16);
            $table->string('uom_code', 16)->nullable();
            $table->decimal('minimum_value', 20, 6)->nullable();
            $table->decimal('target_value', 20, 6)->nullable();
            $table->decimal('maximum_value', 20, 6)->nullable();
            $table->string('text_requirement', 255)->nullable();
            $table->boolean('is_required');
            $table->decimal('numeric_value', 20, 6)->nullable();
            $table->string('text_value', 255)->nullable();
            $table->boolean('boolean_value')->nullable();
            $table->string('result', 16)->default('PENDING');
            $table->text('notes')->nullable();
            $table->timestampTz('tested_at')->nullable();
            $table->uuid('tested_by')->nullable();
            $table->timestampsTz();

            $table->unique(['lab_sample_id', 'sequence_no'], 'lab_results_sequence_unique');
            $table->unique(['lab_sample_id', 'specification_parameter_id'], 'lab_results_parameter_unique');
            $table->unique(['id', 'lab_sample_id', 'company_id', 'plant_id'], 'lab_results_identity_unique');
            $table->foreign(['lab_sample_id', 'company_id', 'plant_id'], 'lab_results_sample_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('lab_samples')->cascadeOnDelete();
            $table->foreign('specification_parameter_id', 'lab_results_parameter_fk')
                ->references('id')->on('quality_spec_parameters')->restrictOnDelete();
            $table->foreign('uom_code', 'lab_results_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('tested_by', 'lab_results_tester_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('quality_deviations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('deviation_number', 80);
            $table->uuid('production_order_id');
            $table->uuid('lab_sample_id')->nullable();
            $table->uuid('lab_result_id')->nullable();
            $table->string('category', 32);
            $table->text('description');
            $table->string('status', 16)->default('OPEN');
            $table->string('disposition', 16)->nullable();
            $table->text('root_cause')->nullable();
            $table->text('corrective_action')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestampTz('raised_at');
            $table->uuid('raised_by');
            $table->timestampTz('resolved_at')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'deviation_number'], 'quality_deviations_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'quality_deviations_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'raised_at'], 'quality_deviations_scope_status_index');
            $table->foreign(['production_order_id', 'company_id', 'plant_id'], 'quality_deviations_order_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('production_orders')->restrictOnDelete();
            $table->foreign(['lab_sample_id', 'company_id', 'plant_id'], 'quality_deviations_sample_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('lab_samples')->restrictOnDelete();
            $table->foreign('lab_result_id', 'quality_deviations_result_fk')->references('id')->on('lab_results')->restrictOnDelete();
            $table->foreign('raised_by', 'quality_deviations_raiser_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('resolved_by', 'quality_deviations_resolver_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('food_safety_holds', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('hold_number', 80);
            $table->uuid('production_order_id');
            $table->string('hazard_type', 24);
            $table->text('reason');
            $table->string('status', 16)->default('ACTIVE');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestampTz('placed_at');
            $table->uuid('placed_by');
            $table->timestampTz('released_at')->nullable();
            $table->uuid('released_by')->nullable();
            $table->text('corrective_action')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'hold_number'], 'food_safety_holds_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'food_safety_holds_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'placed_at'], 'food_safety_holds_scope_status_index');
            $table->foreign(['production_order_id', 'company_id', 'plant_id'], 'food_safety_holds_order_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('production_orders')->restrictOnDelete();
            $table->foreign('placed_by', 'food_safety_holds_placer_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('released_by', 'food_safety_holds_releaser_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('packaging_artworks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->uuid('output_sku_id');
            $table->string('artwork_code', 80);
            $table->unsignedInteger('revision');
            $table->string('label_name', 160);
            $table->string('barcode', 80);
            $table->string('coding_template', 255);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('DRAFT');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->text('notes')->nullable();
            $table->uuid('created_by');
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->uuid('retired_by')->nullable();
            $table->text('retirement_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'artwork_code', 'revision'], 'packaging_artworks_scope_revision_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'packaging_artworks_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'output_sku_id', 'status'], 'packaging_artworks_scope_status_index');
            $table->foreign(['output_sku_id', 'company_id'], 'packaging_artworks_sku_fk')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'packaging_artworks_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign('created_by', 'packaging_artworks_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by', 'packaging_artworks_approver_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('retired_by', 'packaging_artworks_retirer_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('packing_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('run_number', 80);
            $table->uuid('production_order_id');
            $table->uuid('packaging_artwork_id');
            $table->decimal('packed_quantity', 20, 6);
            $table->string('uom_code', 16);
            $table->string('finished_lot_code', 80);
            $table->date('manufacture_date');
            $table->date('expiry_date');
            $table->uuid('target_location_id');
            $table->string('coding_value', 255);
            $table->string('status', 16)->default('DRAFT');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->text('notes')->nullable();
            $table->uuid('created_by');
            $table->uuid('finished_lot_id')->nullable();
            $table->uuid('finished_position_id')->nullable();
            $table->uuid('stock_movement_id')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'run_number'], 'packing_runs_scope_number_unique');
            $table->unique(['company_id', 'finished_lot_code'], 'packing_runs_scope_lot_code_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'packing_runs_id_scope_unique');
            $table->unique('finished_lot_id', 'packing_runs_finished_lot_unique');
            $table->unique('stock_movement_id', 'packing_runs_movement_unique');
            $table->index(['company_id', 'plant_id', 'status', 'manufacture_date'], 'packing_runs_scope_status_index');
            $table->foreign(['production_order_id', 'company_id', 'plant_id'], 'packing_runs_order_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('production_orders')->restrictOnDelete();
            $table->foreign(['packaging_artwork_id', 'company_id', 'plant_id'], 'packing_runs_artwork_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('packaging_artworks')->restrictOnDelete();
            $table->foreign(['target_location_id', 'company_id', 'plant_id'], 'packing_runs_location_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('locations')->restrictOnDelete();
            $table->foreign(['finished_lot_id', 'company_id'], 'packing_runs_lot_fk')
                ->references(['id', 'company_id'])->on('lots')->restrictOnDelete();
            $table->foreign(['finished_position_id', 'company_id', 'plant_id'], 'packing_runs_position_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('stock_positions')->restrictOnDelete();
            $table->foreign('stock_movement_id', 'packing_runs_stock_movement_fk')
                ->references('id')->on('stock_movements')->restrictOnDelete();
            $table->foreign('uom_code', 'packing_runs_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('created_by', 'packing_runs_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('completed_by', 'packing_runs_completer_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by', 'packing_runs_canceller_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('fg_lots', function (Blueprint $table) {
            $table->uuid('lot_id')->nullable();
            $table->uuid('production_order_id')->nullable();
            $table->uuid('packing_run_id')->nullable();
            $table->uuid('stock_position_id')->nullable();
            $table->uuid('stock_movement_id')->nullable();
            $table->decimal('packed_quantity', 20, 6)->nullable();
            $table->string('uom_code', 16)->nullable();
            $table->string('coding_value', 255)->nullable();
            $table->timestampTz('released_at')->nullable();

            $table->unique(['id', 'company_id', 'plant_id'], 'fg_lots_id_scope_unique');
            $table->unique('lot_id', 'fg_lots_lot_unique');
            $table->unique('packing_run_id', 'fg_lots_packing_run_unique');
            $table->unique('stock_movement_id', 'fg_lots_movement_unique');
            $table->index(['company_id', 'plant_id', 'status', 'released_at'], 'fg_lots_scope_status_index');
            $table->foreign(['lot_id', 'company_id'], 'fg_lots_lot_fk')
                ->references(['id', 'company_id'])->on('lots')->restrictOnDelete();
            $table->foreign(['production_order_id', 'company_id', 'plant_id'], 'fg_lots_order_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('production_orders')->restrictOnDelete();
            $table->foreign(['packing_run_id', 'company_id', 'plant_id'], 'fg_lots_packing_run_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('packing_runs')->restrictOnDelete();
            $table->foreign(['stock_position_id', 'company_id', 'plant_id'], 'fg_lots_position_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('stock_positions')->restrictOnDelete();
            $table->foreign('stock_movement_id', 'fg_lots_movement_fk')->references('id')->on('stock_movements')->restrictOnDelete();
            $table->foreign('uom_code', 'fg_lots_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('created_by', 'fg_lots_creator_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('lot_genealogy_edges', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->uuid('production_order_id');
            $table->uuid('packing_run_id');
            $table->uuid('production_material_issue_id');
            $table->uuid('input_lot_id');
            $table->uuid('output_lot_id');
            $table->decimal('input_quantity', 20, 6);
            $table->string('input_uom_code', 16);
            $table->decimal('output_quantity', 20, 6);
            $table->string('output_uom_code', 16);
            $table->timestampTz('created_at');

            $table->unique(['production_material_issue_id', 'output_lot_id'], 'lot_genealogy_issue_output_unique');
            $table->index(['company_id', 'input_lot_id'], 'lot_genealogy_input_index');
            $table->index(['company_id', 'output_lot_id'], 'lot_genealogy_output_index');
            $table->foreign(['production_order_id', 'company_id', 'plant_id'], 'lot_genealogy_order_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('production_orders')->restrictOnDelete();
            $table->foreign(['packing_run_id', 'company_id', 'plant_id'], 'lot_genealogy_packing_run_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('packing_runs')->restrictOnDelete();
            $table->foreign('production_material_issue_id', 'lot_genealogy_issue_fk')
                ->references('id')->on('production_material_issues')->restrictOnDelete();
            $table->foreign(['input_lot_id', 'company_id'], 'lot_genealogy_input_lot_fk')
                ->references(['id', 'company_id'])->on('lots')->restrictOnDelete();
            $table->foreign(['output_lot_id', 'company_id'], 'lot_genealogy_output_lot_fk')
                ->references(['id', 'company_id'])->on('lots')->restrictOnDelete();
            $table->foreign('input_uom_code', 'lot_genealogy_input_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('output_uom_code', 'lot_genealogy_output_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
        });

        Schema::create('recall_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('recall_number', 80);
            $table->uuid('source_lot_id');
            $table->string('classification', 16);
            $table->text('reason');
            $table->string('status', 16)->default('OPEN');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->timestampTz('initiated_at');
            $table->uuid('initiated_by');
            $table->timestampTz('closed_at')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->text('closure_action')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'recall_number'], 'recall_cases_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'recall_cases_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'initiated_at'], 'recall_cases_scope_status_index');
            $table->foreign(['source_lot_id', 'company_id'], 'recall_cases_source_lot_fk')
                ->references(['id', 'company_id'])->on('lots')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'recall_cases_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign('initiated_by', 'recall_cases_initiator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('closed_by', 'recall_cases_closer_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('recall_case_lots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('recall_case_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->uuid('lot_id');
            $table->string('relationship', 16);
            $table->unsignedSmallInteger('depth');
            $table->decimal('on_hand_quantity', 20, 6)->default(0);
            $table->string('action_status', 16)->default('BLOCKED');
            $table->timestampTz('created_at');

            $table->unique(['recall_case_id', 'lot_id'], 'recall_case_lots_case_lot_unique');
            $table->index(['company_id', 'plant_id', 'lot_id'], 'recall_case_lots_scope_lot_index');
            $table->foreign(['recall_case_id', 'company_id', 'plant_id'], 'recall_case_lots_case_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('recall_cases')->cascadeOnDelete();
            $table->foreign(['lot_id', 'company_id'], 'recall_case_lots_lot_fk')
                ->references(['id', 'company_id'])->on('lots')->restrictOnDelete();
        });

        Schema::create('batch_costs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('cost_number', 80);
            $table->uuid('production_order_id');
            $table->unsignedInteger('snapshot_version');
            $table->string('currency', 3)->default('INR');
            $table->decimal('labour_rate_per_minute', 20, 6);
            $table->decimal('overhead_rate_per_minute', 20, 6);
            $table->decimal('planned_material_cost', 20, 6);
            $table->decimal('actual_material_cost', 20, 6);
            $table->decimal('planned_conversion_cost', 20, 6);
            $table->decimal('actual_conversion_cost', 20, 6);
            $table->decimal('planned_total_cost', 20, 6);
            $table->decimal('actual_total_cost', 20, 6);
            $table->decimal('total_variance', 20, 6);
            $table->decimal('variance_percent', 12, 6);
            $table->decimal('good_quantity', 20, 6);
            $table->decimal('yield_percent', 12, 6);
            $table->decimal('cost_per_good_unit', 20, 6);
            $table->string('status', 16)->default('FINALIZED');
            $table->uuid('calculated_by');
            $table->timestampTz('calculated_at');
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'cost_number'], 'batch_costs_scope_number_unique');
            $table->unique(['production_order_id', 'snapshot_version'], 'batch_costs_order_version_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'batch_costs_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'calculated_at'], 'batch_costs_scope_date_index');
            $table->foreign(['production_order_id', 'company_id', 'plant_id'], 'batch_costs_order_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('production_orders')->restrictOnDelete();
            $table->foreign('calculated_by', 'batch_costs_calculator_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('batch_cost_material_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_cost_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->uuid('production_order_material_id');
            $table->uuid('component_sku_id');
            $table->decimal('planned_quantity', 20, 6);
            $table->decimal('actual_quantity', 20, 6);
            $table->string('uom_code', 16);
            $table->decimal('unit_cost', 20, 6);
            $table->decimal('planned_cost', 20, 6);
            $table->decimal('actual_cost', 20, 6);
            $table->decimal('usage_variance', 20, 6);
            $table->timestampTz('created_at');

            $table->unique(['batch_cost_id', 'production_order_material_id'], 'batch_cost_material_lines_material_unique');
            $table->foreign(['batch_cost_id', 'company_id', 'plant_id'], 'batch_cost_material_lines_cost_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('batch_costs')->cascadeOnDelete();
            $table->foreign(['production_order_material_id', 'company_id', 'plant_id'], 'batch_cost_material_lines_order_material_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('production_order_materials')->restrictOnDelete();
            $table->foreign(['component_sku_id', 'company_id'], 'batch_cost_material_lines_sku_fk')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign('uom_code', 'batch_cost_material_lines_uom_fk')->references('code')->on('uoms')->restrictOnDelete();
        });

        Schema::create('batch_cost_stage_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('batch_cost_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->uuid('production_order_stage_id');
            $table->string('work_center_code', 64);
            $table->decimal('planned_minutes', 20, 6);
            $table->decimal('actual_minutes', 20, 6);
            $table->decimal('planned_cost', 20, 6);
            $table->decimal('actual_cost', 20, 6);
            $table->decimal('time_variance', 20, 6);
            $table->timestampTz('created_at');

            $table->unique(['batch_cost_id', 'production_order_stage_id'], 'batch_cost_stage_lines_stage_unique');
            $table->foreign(['batch_cost_id', 'company_id', 'plant_id'], 'batch_cost_stage_lines_cost_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('batch_costs')->cascadeOnDelete();
            $table->foreign(['production_order_stage_id', 'company_id', 'plant_id'], 'batch_cost_stage_lines_order_stage_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('production_order_stages')->restrictOnDelete();
        });

        $this->addPostgresControls();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP INDEX IF EXISTS production_orders_active_schedule_line_unique');
            DB::statement('DROP INDEX IF EXISTS packaging_artworks_one_approved_sku_unique');
            foreach ($this->postgresConstraints() as [$table, $name]) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            }
        }

        Schema::dropIfExists('batch_cost_stage_lines');
        Schema::dropIfExists('batch_cost_material_lines');
        Schema::dropIfExists('batch_costs');
        Schema::dropIfExists('recall_case_lots');
        Schema::dropIfExists('recall_cases');
        Schema::dropIfExists('lot_genealogy_edges');
        Schema::table('fg_lots', function (Blueprint $table) {
            $table->dropForeign('fg_lots_creator_fk');
            $table->dropForeign('fg_lots_uom_fk');
            $table->dropForeign('fg_lots_movement_fk');
            $table->dropForeign('fg_lots_position_fk');
            $table->dropForeign('fg_lots_packing_run_fk');
            $table->dropForeign('fg_lots_order_fk');
            $table->dropForeign('fg_lots_lot_fk');
            $table->dropIndex('fg_lots_scope_status_index');
            $table->dropUnique('fg_lots_movement_unique');
            $table->dropUnique('fg_lots_packing_run_unique');
            $table->dropUnique('fg_lots_lot_unique');
            $table->dropUnique('fg_lots_id_scope_unique');
            $table->dropColumn(['lot_id', 'production_order_id', 'packing_run_id', 'stock_position_id',
                'stock_movement_id', 'packed_quantity', 'uom_code', 'coding_value', 'released_at']);
        });
        Schema::dropIfExists('packing_runs');
        Schema::dropIfExists('packaging_artworks');
        Schema::dropIfExists('food_safety_holds');
        Schema::dropIfExists('quality_deviations');
        Schema::dropIfExists('lab_results');
        Schema::dropIfExists('lab_samples');
        Schema::dropIfExists('production_output_events');
        Schema::dropIfExists('production_material_issues');
        Schema::table('stage_events', function (Blueprint $table) {
            $table->dropForeign('stage_events_stage_fk');
            $table->dropForeign('stage_events_order_fk');
            $table->dropForeign('stage_events_actor_fk');
            $table->dropIndex('stage_events_order_stage_index');
            $table->dropUnique('stage_events_id_scope_unique');
            $table->dropColumn(['production_order_id', 'production_order_stage_id', 'event_type', 'event_at', 'actual_minutes', 'notes']);
        });
        Schema::dropIfExists('production_order_stages');
        Schema::dropIfExists('production_order_materials');
        Schema::table('production_orders', function (Blueprint $table) {
            $table->dropForeign('production_orders_canceller_fk');
            $table->dropForeign('production_orders_quality_releaser_fk');
            $table->dropForeign('production_orders_completer_fk');
            $table->dropForeign('production_orders_releaser_fk');
            $table->dropForeign('production_orders_creator_fk');
            $table->dropForeign('production_orders_uom_fk');
            $table->dropForeign('production_orders_route_fk');
            $table->dropForeign('production_orders_recipe_fk');
            $table->dropForeign('production_orders_sku_fk');
            $table->dropForeign('production_orders_schedule_line_fk');
            $table->dropForeign('production_orders_plant_fk');
            $table->dropForeign('production_orders_execution_company_fk');
            $table->dropIndex('production_orders_scope_status_index');
            $table->dropUnique('production_orders_id_scope_unique');
            $table->dropUnique('production_orders_scope_batch_unique');
            $table->dropUnique('production_orders_scope_number_unique');
            $table->dropColumn(['order_number', 'batch_number', 'production_schedule_id', 'production_schedule_line_id',
                'output_sku_id', 'recipe_id', 'route_id', 'planned_quantity', 'uom_code', 'planned_start_date',
                'planned_end_date', 'quality_status', 'notes', 'released_at', 'released_by', 'started_at',
                'completed_at', 'completed_by', 'quality_released_at', 'quality_released_by', 'cancelled_at',
                'cancelled_by', 'cancellation_reason']);
        });

        Schema::table('production_material_reservations', function (Blueprint $table) {
            $table->dropUnique('prod_mat_res_execution_scope_unique');
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
        foreach (['plant_id', 'created_by', 'order_number', 'batch_number', 'production_schedule_id',
            'production_schedule_line_id', 'output_sku_id', 'recipe_id', 'route_id', 'planned_quantity',
            'uom_code', 'planned_start_date', 'planned_end_date'] as $column) {
            DB::statement("ALTER TABLE production_orders ALTER COLUMN {$column} SET NOT NULL");
        }
        foreach (['plant_id', 'created_by', 'production_order_id', 'production_order_stage_id', 'event_type', 'event_at'] as $column) {
            DB::statement("ALTER TABLE stage_events ALTER COLUMN {$column} SET NOT NULL");
        }
        foreach (['plant_id', 'created_by', 'lot_id', 'production_order_id', 'packing_run_id', 'stock_position_id',
            'stock_movement_id', 'packed_quantity', 'uom_code', 'coding_value', 'released_at'] as $column) {
            DB::statement("ALTER TABLE fg_lots ALTER COLUMN {$column} SET NOT NULL");
        }
        DB::statement("CREATE UNIQUE INDEX production_orders_active_schedule_line_unique ON production_orders (production_schedule_line_id) WHERE status <> 'CANCELLED'");
        DB::statement("CREATE UNIQUE INDEX packaging_artworks_one_approved_sku_unique ON packaging_artworks (company_id, plant_id, output_sku_id) WHERE status = 'APPROVED'");
    }

    private function postgresConstraints(): array
    {
        return [
            ['production_orders', 'production_orders_values_check', 'CHECK (planned_quantity > 0 AND planned_end_date >= planned_start_date AND record_version >= 1)'],
            ['production_orders', 'production_orders_status_check', "CHECK (status IN ('DRAFT', 'RELEASED', 'IN_PROCESS', 'COMPLETED', 'CANCELLED'))"],
            ['production_orders', 'production_orders_quality_check', "CHECK (quality_status IN ('PENDING', 'HELD', 'RELEASED', 'RECALLED'))"],
            ['production_orders', 'production_orders_workflow_check', "CHECK ((status = 'DRAFT' AND released_at IS NULL AND started_at IS NULL AND completed_at IS NULL AND cancelled_at IS NULL) OR (status = 'RELEASED' AND released_at IS NOT NULL AND released_by IS NOT NULL AND started_at IS NULL AND completed_at IS NULL AND cancelled_at IS NULL) OR (status = 'IN_PROCESS' AND released_at IS NOT NULL AND released_by IS NOT NULL AND started_at IS NOT NULL AND completed_at IS NULL AND cancelled_at IS NULL) OR (status = 'COMPLETED' AND released_at IS NOT NULL AND released_by IS NOT NULL AND started_at IS NOT NULL AND completed_at IS NOT NULL AND completed_by IS NOT NULL AND cancelled_at IS NULL) OR (status = 'CANCELLED' AND started_at IS NULL AND completed_at IS NULL AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND btrim(cancellation_reason) <> ''))"],
            ['production_order_materials', 'production_order_materials_values_check', 'CHECK (line_number >= 1 AND required_quantity > 0 AND issued_quantity >= 0 AND issued_quantity <= required_quantity)'],
            ['production_order_stages', 'production_order_stages_values_check', 'CHECK (sequence_no >= 1 AND planned_minutes >= 0 AND (actual_minutes IS NULL OR actual_minutes >= 0) AND record_version >= 1)'],
            ['production_order_stages', 'production_order_stages_workflow_check', "CHECK ((status = 'PENDING' AND started_at IS NULL AND completed_at IS NULL) OR (status = 'IN_PROGRESS' AND started_at IS NOT NULL AND started_by IS NOT NULL AND completed_at IS NULL) OR (status = 'COMPLETED' AND started_at IS NOT NULL AND started_by IS NOT NULL AND completed_at IS NOT NULL AND completed_by IS NOT NULL AND actual_minutes IS NOT NULL))"],
            ['stage_events', 'stage_events_values_check', "CHECK (status = 'POSTED' AND record_version = 1 AND event_type IN ('STARTED', 'COMPLETED') AND (event_type = 'STARTED' OR actual_minutes IS NOT NULL AND actual_minutes >= 0))"],
            ['production_material_issues', 'production_material_issues_values_check', 'CHECK (quantity > 0)'],
            ['production_output_events', 'production_output_events_values_check', "CHECK (sequence_no >= 1 AND quantity > 0 AND event_type IN ('GOOD', 'LOSS', 'REWORK') AND ((event_type = 'REWORK' AND rework_status IN ('OPEN', 'RECOVERED', 'SCRAPPED')) OR (event_type <> 'REWORK' AND rework_status = 'NA')) AND (event_type = 'GOOD' OR btrim(reason_code) <> '') AND ((rework_status IN ('RECOVERED', 'SCRAPPED') AND resolved_at IS NOT NULL AND resolved_by IS NOT NULL AND btrim(resolution_notes) <> '') OR (rework_status NOT IN ('RECOVERED', 'SCRAPPED') AND resolved_at IS NULL AND resolved_by IS NULL)))"],
            ['lab_samples', 'lab_samples_values_check', 'CHECK (record_version >= 1 AND specification_version_snapshot >= 1)'],
            ['lab_samples', 'lab_samples_workflow_check', "CHECK ((status = 'PENDING' AND completed_at IS NULL AND completed_by IS NULL) OR (status IN ('PASSED', 'FAILED') AND completed_at IS NOT NULL AND completed_by IS NOT NULL))"],
            ['lab_results', 'lab_results_value_check', "CHECK (value_type IN ('NUMERIC', 'TEXT', 'BOOLEAN') AND result IN ('PENDING', 'PASS', 'FAIL') AND ((result = 'PENDING' AND tested_at IS NULL AND tested_by IS NULL) OR (result IN ('PASS', 'FAIL') AND tested_at IS NOT NULL AND tested_by IS NOT NULL)))"],
            ['quality_deviations', 'quality_deviations_values_check', "CHECK (status IN ('OPEN', 'RESOLVED') AND category IN ('LAB', 'PROCESS', 'PACKAGING', 'FOOD_SAFETY') AND record_version >= 1 AND ((status = 'OPEN' AND disposition IS NULL AND resolved_at IS NULL AND resolved_by IS NULL) OR (status = 'RESOLVED' AND disposition IN ('ACCEPTED', 'REWORK', 'SCRAP') AND resolved_at IS NOT NULL AND resolved_by IS NOT NULL AND btrim(root_cause) <> '' AND btrim(corrective_action) <> '')))"],
            ['food_safety_holds', 'food_safety_holds_values_check', "CHECK (hazard_type IN ('BIOLOGICAL', 'CHEMICAL', 'PHYSICAL', 'ALLERGEN', 'REGULATORY') AND status IN ('ACTIVE', 'RELEASED') AND record_version >= 1 AND ((status = 'ACTIVE' AND released_at IS NULL AND released_by IS NULL) OR (status = 'RELEASED' AND released_at IS NOT NULL AND released_by IS NOT NULL AND btrim(corrective_action) <> '')))"],
            ['packaging_artworks', 'packaging_artworks_values_check', "CHECK (revision >= 1 AND (effective_to IS NULL OR effective_to >= effective_from))"],
            ['packaging_artworks', 'packaging_artworks_workflow_check', "CHECK (record_version >= 1 AND ((status = 'DRAFT' AND approved_at IS NULL AND retired_at IS NULL) OR (status = 'APPROVED' AND approved_at IS NOT NULL AND approved_by IS NOT NULL AND retired_at IS NULL) OR (status = 'RETIRED' AND approved_at IS NOT NULL AND approved_by IS NOT NULL AND retired_at IS NOT NULL AND retired_by IS NOT NULL AND btrim(retirement_reason) <> '')))"],
            ['packing_runs', 'packing_runs_values_check', 'CHECK (packed_quantity > 0 AND expiry_date >= manufacture_date AND record_version >= 1)'],
            ['packing_runs', 'packing_runs_workflow_check', "CHECK ((status = 'DRAFT' AND finished_lot_id IS NULL AND completed_at IS NULL AND cancelled_at IS NULL) OR (status = 'COMPLETED' AND finished_lot_id IS NOT NULL AND finished_position_id IS NOT NULL AND stock_movement_id IS NOT NULL AND completed_at IS NOT NULL AND completed_by IS NOT NULL AND cancelled_at IS NULL) OR (status = 'CANCELLED' AND finished_lot_id IS NULL AND completed_at IS NULL AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND btrim(cancellation_reason) <> ''))"],
            ['fg_lots', 'fg_lots_values_check', "CHECK (status IN ('ACTIVE', 'RECALLED') AND record_version >= 1 AND packed_quantity > 0)"],
            ['lot_genealogy_edges', 'lot_genealogy_edges_values_check', 'CHECK (input_lot_id <> output_lot_id AND input_quantity > 0 AND output_quantity > 0)'],
            ['recall_cases', 'recall_cases_values_check', "CHECK (classification IN ('CLASS_I', 'CLASS_II', 'CLASS_III', 'WITHDRAWAL') AND record_version >= 1 AND ((status = 'OPEN' AND closed_at IS NULL AND closed_by IS NULL) OR (status = 'CLOSED' AND closed_at IS NOT NULL AND closed_by IS NOT NULL AND btrim(closure_action) <> '')))"],
            ['recall_case_lots', 'recall_case_lots_values_check', "CHECK (relationship IN ('SOURCE', 'UPSTREAM', 'DOWNSTREAM') AND action_status IN ('BLOCKED', 'NOTIFIED', 'RECOVERED', 'DISPOSED') AND on_hand_quantity >= 0)"],
            ['batch_costs', 'batch_costs_values_check', "CHECK (snapshot_version >= 1 AND currency = 'INR' AND labour_rate_per_minute >= 0 AND overhead_rate_per_minute >= 0 AND planned_material_cost >= 0 AND actual_material_cost >= 0 AND planned_conversion_cost >= 0 AND actual_conversion_cost >= 0 AND planned_total_cost >= 0 AND actual_total_cost >= 0 AND good_quantity > 0 AND yield_percent >= 0 AND cost_per_good_unit >= 0 AND status = 'FINALIZED')"],
            ['batch_cost_material_lines', 'batch_cost_material_lines_values_check', 'CHECK (planned_quantity > 0 AND actual_quantity >= 0 AND unit_cost >= 0 AND planned_cost >= 0 AND actual_cost >= 0)'],
            ['batch_cost_stage_lines', 'batch_cost_stage_lines_values_check', 'CHECK (planned_minutes >= 0 AND actual_minutes >= 0 AND planned_cost >= 0 AND actual_cost >= 0)'],
        ];
    }
};

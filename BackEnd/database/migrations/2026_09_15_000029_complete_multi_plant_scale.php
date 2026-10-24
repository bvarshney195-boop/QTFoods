<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('consolidation_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('owner_company_id');
            $table->string('group_code', 64);
            $table->string('name', 160);
            $table->char('base_currency', 3)->default('INR');
            $table->string('status', 16)->default('DRAFT');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('activated_at')->nullable();
            $table->uuid('activated_by')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->uuid('retired_by')->nullable();
            $table->text('retirement_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['owner_company_id', 'group_code'], 'consolidation_groups_owner_code_unique');
            $table->unique(['id', 'owner_company_id'], 'consolidation_groups_id_owner_unique');
            $table->index(['owner_company_id', 'status', 'updated_at'], 'consolidation_groups_owner_status_index');
            $table->foreign('owner_company_id', 'consolidation_groups_owner_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign('created_by', 'consolidation_groups_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('activated_by', 'consolidation_groups_activator_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('retired_by', 'consolidation_groups_retirer_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('consolidation_group_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('consolidation_group_id');
            $table->uuid('owner_company_id');
            $table->uuid('company_id');
            $table->string('member_code', 32);
            $table->char('reporting_currency', 3)->default('INR');
            $table->decimal('ownership_percent', 7, 4)->default(100);
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->timestampsTz();

            $table->unique(['consolidation_group_id', 'company_id'], 'consolidation_group_members_company_unique');
            $table->unique(['consolidation_group_id', 'member_code'], 'consolidation_group_members_code_unique');
            $table->unique(['id', 'consolidation_group_id', 'company_id'], 'consolidation_group_members_identity_unique');
            $table->index(['company_id', 'effective_from', 'effective_to'], 'consolidation_group_members_company_dates_index');
            $table->foreign(
                ['consolidation_group_id', 'owner_company_id'],
                'consolidation_group_members_group_fk',
            )->references(['id', 'owner_company_id'])->on('consolidation_groups')->cascadeOnDelete();
            $table->foreign('company_id', 'consolidation_group_members_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
        });

        Schema::create('plant_transfer_routes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('source_company_id');
            $table->uuid('source_plant_id');
            $table->uuid('destination_company_id');
            $table->uuid('destination_plant_id');
            $table->uuid('consolidation_group_id')->nullable();
            $table->string('route_code', 64);
            $table->string('name', 160);
            $table->string('transfer_scope', 20);
            $table->char('currency', 3)->default('INR');
            $table->unsignedSmallInteger('transit_days')->default(1);
            $table->decimal('markup_percent', 9, 4)->default(0);
            $table->boolean('require_destination_acceptance')->default(false);
            $table->boolean('require_commercial_reference')->default(false);
            $table->string('status', 16)->default('DRAFT');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('activated_at')->nullable();
            $table->uuid('activated_by')->nullable();
            $table->timestampTz('deactivated_at')->nullable();
            $table->uuid('deactivated_by')->nullable();
            $table->text('deactivation_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['source_company_id', 'route_code'], 'plant_transfer_routes_source_code_unique');
            $table->unique(
                ['id', 'source_company_id', 'source_plant_id', 'destination_company_id', 'destination_plant_id'],
                'plant_transfer_routes_scope_identity_unique',
            );
            $table->index(
                ['source_company_id', 'source_plant_id', 'status', 'updated_at'],
                'plant_transfer_routes_source_status_index',
            );
            $table->index(
                ['destination_company_id', 'destination_plant_id', 'status', 'updated_at'],
                'plant_transfer_routes_destination_status_index',
            );
            $table->foreign(['source_plant_id', 'source_company_id'], 'plant_transfer_routes_source_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['destination_plant_id', 'destination_company_id'], 'plant_transfer_routes_destination_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign('consolidation_group_id', 'plant_transfer_routes_group_fk')
                ->references('id')->on('consolidation_groups')->restrictOnDelete();
            $table->foreign('created_by', 'plant_transfer_routes_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('activated_by', 'plant_transfer_routes_activator_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('deactivated_by', 'plant_transfer_routes_deactivator_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('plant_transfer_item_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('plant_transfer_route_id');
            $table->uuid('source_company_id');
            $table->uuid('source_plant_id');
            $table->uuid('destination_company_id');
            $table->uuid('destination_plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('source_item_id');
            $table->uuid('destination_item_id');
            $table->string('source_uom_code', 16);
            $table->string('destination_uom_code', 16);
            $table->decimal('conversion_rate', 20, 8)->default(1);
            $table->timestampsTz();

            $table->unique(['plant_transfer_route_id', 'line_number'], 'plant_transfer_item_mappings_line_unique');
            $table->unique(
                ['plant_transfer_route_id', 'source_item_id', 'source_uom_code'],
                'plant_transfer_item_mappings_source_unique',
            );
            $table->unique(
                ['id', 'plant_transfer_route_id', 'source_company_id', 'destination_company_id'],
                'plant_transfer_item_mappings_identity_unique',
            );
            $table->foreign(
                ['plant_transfer_route_id', 'source_company_id', 'source_plant_id', 'destination_company_id', 'destination_plant_id'],
                'plant_transfer_item_mappings_route_fk',
            )->references(
                ['id', 'source_company_id', 'source_plant_id', 'destination_company_id', 'destination_plant_id'],
            )->on('plant_transfer_routes')->cascadeOnDelete();
            $table->foreign(['source_item_id', 'source_company_id'], 'plant_transfer_item_mappings_source_item_fk')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign(['destination_item_id', 'destination_company_id'], 'plant_transfer_item_mappings_destination_item_fk')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign('source_uom_code', 'plant_transfer_item_mappings_source_uom_fk')
                ->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('destination_uom_code', 'plant_transfer_item_mappings_destination_uom_fk')
                ->references('code')->on('uoms')->restrictOnDelete();
        });

        Schema::create('interplant_transfer_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('plant_transfer_route_id');
            $table->uuid('source_company_id');
            $table->uuid('source_plant_id');
            $table->uuid('destination_company_id');
            $table->uuid('destination_plant_id');
            $table->string('transfer_number', 80);
            $table->date('transfer_date');
            $table->date('expected_arrival_date');
            $table->string('status', 24)->default('DRAFT');
            $table->string('commercial_reference', 160)->nullable();
            $table->string('destination_reference', 160)->nullable();
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('submitted_at')->nullable();
            $table->uuid('submitted_by')->nullable();
            $table->timestampTz('source_approved_at')->nullable();
            $table->uuid('source_approved_by')->nullable();
            $table->timestampTz('destination_accepted_at')->nullable();
            $table->uuid('destination_accepted_by')->nullable();
            $table->timestampTz('dispatched_at')->nullable();
            $table->uuid('dispatched_by')->nullable();
            $table->timestampTz('received_at')->nullable();
            $table->uuid('received_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['source_company_id', 'transfer_number'], 'interplant_transfer_orders_source_number_unique');
            $table->unique(
                ['id', 'source_company_id', 'source_plant_id', 'destination_company_id', 'destination_plant_id'],
                'interplant_transfer_orders_scope_identity_unique',
            );
            $table->index(
                ['source_company_id', 'source_plant_id', 'status', 'transfer_date'],
                'interplant_transfer_orders_source_status_index',
            );
            $table->index(
                ['destination_company_id', 'destination_plant_id', 'status', 'expected_arrival_date'],
                'interplant_transfer_orders_destination_status_index',
            );
            $table->foreign(
                ['plant_transfer_route_id', 'source_company_id', 'source_plant_id', 'destination_company_id', 'destination_plant_id'],
                'interplant_transfer_orders_route_fk',
            )->references(
                ['id', 'source_company_id', 'source_plant_id', 'destination_company_id', 'destination_plant_id'],
            )->on('plant_transfer_routes')->restrictOnDelete();
            $table->foreign(['source_plant_id', 'source_company_id'], 'interplant_transfer_orders_source_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['destination_plant_id', 'destination_company_id'], 'interplant_transfer_orders_destination_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            foreach ([
                'created_by' => 'creator',
                'submitted_by' => 'submitter',
                'source_approved_by' => 'source_approver',
                'destination_accepted_by' => 'destination_acceptor',
                'dispatched_by' => 'dispatcher',
                'received_by' => 'receiver',
                'cancelled_by' => 'canceller',
            ] as $column => $name) {
                $table->foreign($column, 'interplant_transfer_orders_'.$name.'_fk')
                    ->references('id')->on('users')->restrictOnDelete();
            }
        });

        Schema::create('interplant_transfer_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('interplant_transfer_order_id');
            $table->uuid('plant_transfer_route_id');
            $table->uuid('plant_transfer_item_mapping_id');
            $table->uuid('source_company_id');
            $table->uuid('source_plant_id');
            $table->uuid('destination_company_id');
            $table->uuid('destination_plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('source_position_id');
            $table->uuid('destination_position_id');
            $table->uuid('source_item_id');
            $table->uuid('destination_item_id');
            $table->uuid('source_lot_id');
            $table->uuid('destination_lot_id');
            $table->uuid('source_inventory_owner_id');
            $table->uuid('destination_inventory_owner_id');
            $table->string('source_uom_code', 16);
            $table->string('destination_uom_code', 16);
            $table->decimal('source_quantity', 20, 6);
            $table->decimal('destination_quantity', 20, 6);
            $table->decimal('dispatched_source_quantity', 20, 6)->default(0);
            $table->decimal('received_destination_quantity', 20, 6)->default(0);
            $table->uuid('outbound_movement_id')->nullable();
            $table->uuid('inbound_movement_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['interplant_transfer_order_id', 'line_number'], 'interplant_transfer_lines_number_unique');
            $table->unique(['interplant_transfer_order_id', 'source_position_id'], 'interplant_transfer_lines_source_unique');
            $table->unique(['interplant_transfer_order_id', 'destination_position_id'], 'interplant_transfer_lines_destination_unique');
            $table->unique('outbound_movement_id', 'interplant_transfer_lines_outbound_unique');
            $table->unique('inbound_movement_id', 'interplant_transfer_lines_inbound_unique');
            $table->foreign(
                ['interplant_transfer_order_id', 'source_company_id', 'source_plant_id', 'destination_company_id', 'destination_plant_id'],
                'interplant_transfer_lines_order_fk',
            )->references(
                ['id', 'source_company_id', 'source_plant_id', 'destination_company_id', 'destination_plant_id'],
            )->on('interplant_transfer_orders')->cascadeOnDelete();
            $table->foreign(
                ['plant_transfer_item_mapping_id', 'plant_transfer_route_id', 'source_company_id', 'destination_company_id'],
                'interplant_transfer_lines_mapping_fk',
            )->references(
                ['id', 'plant_transfer_route_id', 'source_company_id', 'destination_company_id'],
            )->on('plant_transfer_item_mappings')->restrictOnDelete();
            $table->foreign(['source_position_id', 'source_company_id', 'source_plant_id'], 'interplant_transfer_lines_source_position_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('stock_positions')->restrictOnDelete();
            $table->foreign(['destination_position_id', 'destination_company_id', 'destination_plant_id'], 'interplant_transfer_lines_destination_position_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('stock_positions')->restrictOnDelete();
            $table->foreign(['source_item_id', 'source_company_id'], 'interplant_transfer_lines_source_item_fk')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign(['destination_item_id', 'destination_company_id'], 'interplant_transfer_lines_destination_item_fk')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign(['source_lot_id', 'source_company_id'], 'interplant_transfer_lines_source_lot_fk')
                ->references(['id', 'company_id'])->on('lots')->restrictOnDelete();
            $table->foreign(['destination_lot_id', 'destination_company_id'], 'interplant_transfer_lines_destination_lot_fk')
                ->references(['id', 'company_id'])->on('lots')->restrictOnDelete();
            $table->foreign(['source_inventory_owner_id', 'source_company_id'], 'interplant_transfer_lines_source_owner_fk')
                ->references(['id', 'company_id'])->on('inventory_owners')->restrictOnDelete();
            $table->foreign(['destination_inventory_owner_id', 'destination_company_id'], 'interplant_transfer_lines_destination_owner_fk')
                ->references(['id', 'company_id'])->on('inventory_owners')->restrictOnDelete();
            $table->foreign('source_uom_code', 'interplant_transfer_lines_source_uom_fk')
                ->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('destination_uom_code', 'interplant_transfer_lines_destination_uom_fk')
                ->references('code')->on('uoms')->restrictOnDelete();
            $table->foreign('outbound_movement_id', 'interplant_transfer_lines_outbound_movement_fk')
                ->references('id')->on('stock_movements')->restrictOnDelete();
            $table->foreign('inbound_movement_id', 'interplant_transfer_lines_inbound_movement_fk')
                ->references('id')->on('stock_movements')->restrictOnDelete();
        });

        Schema::create('consolidation_runs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('consolidation_group_id');
            $table->uuid('reporting_company_id');
            $table->uuid('reporting_plant_id');
            $table->string('run_number', 80);
            $table->date('cutoff_date');
            $table->char('base_currency', 3);
            $table->string('status', 16)->default('DRAFT');
            $table->decimal('translated_debit', 24, 4)->default(0);
            $table->decimal('translated_credit', 24, 4)->default(0);
            $table->decimal('elimination_debit', 24, 4)->default(0);
            $table->decimal('elimination_credit', 24, 4)->default(0);
            $table->decimal('consolidated_debit', 24, 4)->default(0);
            $table->decimal('consolidated_credit', 24, 4)->default(0);
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('finalized_at')->nullable();
            $table->uuid('finalized_by')->nullable();
            $table->timestampsTz();

            $table->unique(['reporting_company_id', 'run_number'], 'consolidation_runs_company_number_unique');
            $table->unique(['id', 'reporting_company_id', 'reporting_plant_id'], 'consolidation_runs_scope_identity_unique');
            $table->unique(['id', 'consolidation_group_id'], 'consolidation_runs_group_identity_unique');
            $table->index(['reporting_company_id', 'reporting_plant_id', 'status', 'cutoff_date'], 'consolidation_runs_scope_status_index');
            $table->foreign('consolidation_group_id', 'consolidation_runs_group_fk')
                ->references('id')->on('consolidation_groups')->restrictOnDelete();
            $table->foreign(['reporting_plant_id', 'reporting_company_id'], 'consolidation_runs_reporting_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign('created_by', 'consolidation_runs_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('finalized_by', 'consolidation_runs_finalizer_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('consolidation_run_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('consolidation_run_id');
            $table->uuid('consolidation_group_id');
            $table->uuid('consolidation_group_member_id');
            $table->uuid('company_id');
            $table->decimal('exchange_rate', 20, 8);
            $table->decimal('source_debit', 24, 4)->default(0);
            $table->decimal('source_credit', 24, 4)->default(0);
            $table->decimal('translated_debit', 24, 4)->default(0);
            $table->decimal('translated_credit', 24, 4)->default(0);
            $table->timestampTz('captured_at');

            $table->unique(['consolidation_run_id', 'company_id'], 'consolidation_run_members_company_unique');
            $table->foreign(
                ['consolidation_run_id', 'consolidation_group_id'],
                'consolidation_run_members_run_group_fk',
            )->references(
                ['id', 'consolidation_group_id'],
            )->on('consolidation_runs')->cascadeOnDelete();
            $table->foreign(
                ['consolidation_group_member_id', 'consolidation_group_id', 'company_id'],
                'consolidation_run_members_group_member_fk',
            )->references(
                ['id', 'consolidation_group_id', 'company_id'],
            )->on('consolidation_group_members')->restrictOnDelete();
            $table->foreign('company_id', 'consolidation_run_members_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
        });

        Schema::create('consolidation_elimination_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('consolidation_run_id');
            $table->unsignedSmallInteger('line_number');
            $table->string('description', 255);
            $table->decimal('debit_amount', 24, 4)->default(0);
            $table->decimal('credit_amount', 24, 4)->default(0);
            $table->timestampsTz();

            $table->unique(['consolidation_run_id', 'line_number'], 'consolidation_elimination_lines_number_unique');
            $table->foreign('consolidation_run_id', 'consolidation_elimination_lines_run_fk')
                ->references('id')->on('consolidation_runs')->cascadeOnDelete();
        });

        $this->addPostgresConstraints();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach ($this->postgresConstraints() as [$table, $name]) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            }
        }

        Schema::dropIfExists('consolidation_elimination_lines');
        Schema::dropIfExists('consolidation_run_members');
        Schema::dropIfExists('consolidation_runs');
        Schema::dropIfExists('interplant_transfer_lines');
        Schema::dropIfExists('interplant_transfer_orders');
        Schema::dropIfExists('plant_transfer_item_mappings');
        Schema::dropIfExists('plant_transfer_routes');
        Schema::dropIfExists('consolidation_group_members');
        Schema::dropIfExists('consolidation_groups');
    }

    private function addPostgresConstraints(): void
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
            ['consolidation_groups', 'consolidation_groups_status_check', "CHECK (status IN ('DRAFT', 'ACTIVE', 'RETIRED'))"],
            ['consolidation_groups', 'consolidation_groups_version_check', 'CHECK (record_version >= 1)'],
            ['consolidation_groups', 'consolidation_groups_lifecycle_check', "CHECK ((status = 'DRAFT' AND activated_at IS NULL AND activated_by IS NULL AND retired_at IS NULL AND retired_by IS NULL AND retirement_reason IS NULL) OR (status = 'ACTIVE' AND activated_at IS NOT NULL AND activated_by IS NOT NULL AND retired_at IS NULL AND retired_by IS NULL AND retirement_reason IS NULL) OR (status = 'RETIRED' AND activated_at IS NOT NULL AND activated_by IS NOT NULL AND retired_at IS NOT NULL AND retired_by IS NOT NULL AND retirement_reason IS NOT NULL))"],
            ['consolidation_group_members', 'consolidation_group_members_values_check', 'CHECK (ownership_percent > 0 AND ownership_percent <= 100 AND (effective_to IS NULL OR effective_to >= effective_from))'],
            ['plant_transfer_routes', 'plant_transfer_routes_scope_check', "CHECK ((transfer_scope = 'INTER_PLANT' AND source_company_id = destination_company_id AND source_plant_id <> destination_plant_id AND NOT require_destination_acceptance) OR (transfer_scope = 'INTER_COMPANY' AND source_company_id <> destination_company_id AND source_plant_id <> destination_plant_id AND require_destination_acceptance AND require_commercial_reference))"],
            ['plant_transfer_routes', 'plant_transfer_routes_values_check', 'CHECK (transit_days <= 365 AND markup_percent >= 0 AND markup_percent <= 1000 AND record_version >= 1)'],
            ['plant_transfer_routes', 'plant_transfer_routes_status_check', "CHECK (status IN ('DRAFT', 'ACTIVE', 'INACTIVE'))"],
            ['plant_transfer_routes', 'plant_transfer_routes_lifecycle_check', "CHECK ((status = 'DRAFT' AND activated_at IS NULL AND activated_by IS NULL AND deactivated_at IS NULL AND deactivated_by IS NULL AND deactivation_reason IS NULL) OR (status = 'ACTIVE' AND activated_at IS NOT NULL AND activated_by IS NOT NULL AND deactivated_at IS NULL AND deactivated_by IS NULL AND deactivation_reason IS NULL) OR (status = 'INACTIVE' AND activated_at IS NOT NULL AND activated_by IS NOT NULL AND deactivated_at IS NOT NULL AND deactivated_by IS NOT NULL AND deactivation_reason IS NOT NULL))"],
            ['plant_transfer_item_mappings', 'plant_transfer_item_mappings_values_check', 'CHECK (line_number >= 1 AND conversion_rate > 0)'],
            ['interplant_transfer_orders', 'interplant_transfer_orders_status_check', "CHECK (status IN ('DRAFT', 'SUBMITTED', 'SOURCE_APPROVED', 'APPROVED', 'IN_TRANSIT', 'RECEIVED', 'CANCELLED'))"],
            ['interplant_transfer_orders', 'interplant_transfer_orders_dates_check', 'CHECK (expected_arrival_date >= transfer_date AND record_version >= 1)'],
            ['interplant_transfer_orders', 'interplant_transfer_orders_actor_evidence_check', 'CHECK ((submitted_at IS NULL) = (submitted_by IS NULL) AND (source_approved_at IS NULL) = (source_approved_by IS NULL) AND (destination_accepted_at IS NULL) = (destination_accepted_by IS NULL) AND (destination_accepted_at IS NULL) = (destination_reference IS NULL) AND (dispatched_at IS NULL) = (dispatched_by IS NULL) AND (received_at IS NULL) = (received_by IS NULL) AND ((cancelled_at IS NULL AND cancelled_by IS NULL AND cancellation_reason IS NULL) OR (cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND cancellation_reason IS NOT NULL)))'],
            ['interplant_transfer_orders', 'interplant_transfer_orders_lifecycle_check', "CHECK ((status = 'DRAFT' AND submitted_at IS NULL AND source_approved_at IS NULL AND destination_accepted_at IS NULL AND dispatched_at IS NULL AND received_at IS NULL AND cancelled_at IS NULL) OR (status = 'SUBMITTED' AND submitted_at IS NOT NULL AND source_approved_at IS NULL AND destination_accepted_at IS NULL AND dispatched_at IS NULL AND received_at IS NULL AND cancelled_at IS NULL) OR (status = 'SOURCE_APPROVED' AND submitted_at IS NOT NULL AND source_approved_at IS NOT NULL AND destination_accepted_at IS NULL AND dispatched_at IS NULL AND received_at IS NULL AND cancelled_at IS NULL) OR (status = 'APPROVED' AND submitted_at IS NOT NULL AND source_approved_at IS NOT NULL AND dispatched_at IS NULL AND received_at IS NULL AND cancelled_at IS NULL) OR (status = 'IN_TRANSIT' AND submitted_at IS NOT NULL AND source_approved_at IS NOT NULL AND dispatched_at IS NOT NULL AND received_at IS NULL AND cancelled_at IS NULL) OR (status = 'RECEIVED' AND submitted_at IS NOT NULL AND source_approved_at IS NOT NULL AND dispatched_at IS NOT NULL AND received_at IS NOT NULL AND cancelled_at IS NULL) OR (status = 'CANCELLED' AND dispatched_at IS NULL AND received_at IS NULL AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND cancellation_reason IS NOT NULL))"],
            ['interplant_transfer_lines', 'interplant_transfer_lines_values_check', 'CHECK (line_number >= 1 AND source_quantity > 0 AND destination_quantity > 0 AND dispatched_source_quantity >= 0 AND dispatched_source_quantity <= source_quantity AND received_destination_quantity >= 0 AND received_destination_quantity <= destination_quantity)'],
            ['consolidation_runs', 'consolidation_runs_status_check', "CHECK (status IN ('DRAFT', 'FINALIZED'))"],
            ['consolidation_runs', 'consolidation_runs_values_check', 'CHECK (translated_debit >= 0 AND translated_credit >= 0 AND translated_debit = translated_credit AND elimination_debit >= 0 AND elimination_credit >= 0 AND elimination_debit = elimination_credit AND consolidated_debit >= 0 AND consolidated_credit >= 0 AND consolidated_debit = consolidated_credit AND record_version >= 1)'],
            ['consolidation_runs', 'consolidation_runs_lifecycle_check', "CHECK ((status = 'DRAFT' AND finalized_at IS NULL AND finalized_by IS NULL) OR (status = 'FINALIZED' AND finalized_at IS NOT NULL AND finalized_by IS NOT NULL))"],
            ['consolidation_run_members', 'consolidation_run_members_values_check', 'CHECK (exchange_rate > 0 AND source_debit >= 0 AND source_credit >= 0 AND translated_debit >= 0 AND translated_credit >= 0)'],
            ['consolidation_elimination_lines', 'consolidation_elimination_lines_values_check', 'CHECK (line_number >= 1 AND debit_amount >= 0 AND credit_amount >= 0 AND ((debit_amount > 0 AND credit_amount = 0) OR (debit_amount = 0 AND credit_amount > 0)))'],
        ];
    }
};

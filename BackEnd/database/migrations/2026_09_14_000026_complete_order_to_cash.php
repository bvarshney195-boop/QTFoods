<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // Migration 020 installs these relational identities as PostgreSQL
        // constraints. SQLite needs equivalent native indexes before it can
        // enforce the P2 composite foreign keys created below.
        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('items', function (Blueprint $table) {
                $table->unique(['id', 'company_id', 'base_uom'], 'items_p2_uom_identity_unique');
            });
            Schema::table('lots', function (Blueprint $table) {
                $table->unique(['id', 'company_id', 'item_id'], 'lots_p2_item_identity_unique');
            });
            Schema::table('stock_positions', function (Blueprint $table) {
                $table->unique(['id', 'company_id', 'plant_id', 'item_id', 'uom_code'], 'stock_positions_p2_item_identity_unique');
                $table->unique(['id', 'company_id', 'plant_id', 'item_id', 'lot_id', 'uom_code'], 'stock_positions_p2_lot_identity_unique');
            });
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->unique(['id', 'company_id', 'plant_id'], 'sales_orders_p2_id_scope_unique');
            });
            Schema::table('shipments', function (Blueprint $table) {
                $table->unique(['id', 'company_id', 'plant_id'], 'shipments_p2_id_scope_unique');
                $table->unique(['id', 'company_id', 'plant_id', 'party_id'], 'shipments_p2_party_identity_unique');
            });
            Schema::table('shipment_lines', function (Blueprint $table) {
                $table->unique(['id', 'shipment_id', 'company_id', 'plant_id', 'item_id', 'uom_code'], 'shipment_lines_p2_identity_unique');
                $table->unique(['id', 'shipment_id', 'company_id', 'plant_id', 'item_id', 'fg_lot_id', 'uom_code'], 'shipment_lines_p2_lot_identity_unique');
            });
            Schema::table('sales_invoice_financials', function (Blueprint $table) {
                $table->unique(['invoice_id', 'company_id', 'plant_id'], 'sales_invoice_financials_p2_scope_unique');
            });
        }

        Schema::create('sales_leads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('lead_number', 80);
            $table->uuid('customer_party_id')->nullable();
            $table->string('company_name', 160);
            $table->string('contact_name', 160);
            $table->string('contact_email', 255)->nullable();
            $table->string('contact_phone', 40)->nullable();
            $table->string('source', 40);
            $table->date('enquiry_date');
            $table->date('expected_close_date')->nullable();
            $table->decimal('estimated_value', 20, 6)->default(0);
            $table->char('currency', 3)->default('INR');
            $table->string('status', 16)->default('NEW');
            $table->text('notes')->nullable();
            $table->text('lost_reason')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('qualified_at')->nullable();
            $table->uuid('qualified_by')->nullable();
            $table->timestampTz('converted_at')->nullable();
            $table->uuid('converted_by')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'lead_number'], 'sales_leads_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'sales_leads_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'expected_close_date'], 'sales_leads_scope_status_index');
            $table->foreign('company_id', 'sales_leads_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'sales_leads_plant_fk')->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['customer_party_id', 'company_id'], 'sales_leads_customer_fk')->references(['id', 'company_id'])->on('parties')->restrictOnDelete();
            $table->foreign('created_by', 'sales_leads_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('qualified_by', 'sales_leads_qualifier_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('converted_by', 'sales_leads_converter_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('closed_by', 'sales_leads_closer_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('sales_lead_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sales_lead_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('event_type', 24);
            $table->text('notes')->nullable();
            $table->uuid('actor_id');
            $table->timestampTz('occurred_at');
            $table->timestampTz('created_at');

            $table->index(['sales_lead_id', 'occurred_at'], 'sales_lead_events_timeline_index');
            $table->foreign(['sales_lead_id', 'company_id', 'plant_id'], 'sales_lead_events_lead_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('sales_leads')->cascadeOnDelete();
            $table->foreign('actor_id', 'sales_lead_events_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('sales_price_lists', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('list_number', 80);
            $table->string('name', 160);
            $table->char('currency', 3)->default('INR');
            $table->date('effective_from');
            $table->date('effective_to')->nullable();
            $table->string('status', 16)->default('DRAFT');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('activated_at')->nullable();
            $table->uuid('activated_by')->nullable();
            $table->timestampTz('retired_at')->nullable();
            $table->uuid('retired_by')->nullable();
            $table->text('retirement_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'list_number'], 'sales_price_lists_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'sales_price_lists_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'effective_from'], 'sales_price_lists_scope_status_index');
            $table->foreign('company_id', 'sales_price_lists_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'sales_price_lists_plant_fk')->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign('created_by', 'sales_price_lists_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('activated_by', 'sales_price_lists_activator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('retired_by', 'sales_price_lists_retirer_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('sales_price_list_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sales_price_list_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('item_id');
            $table->string('uom_code', 16);
            $table->decimal('minimum_quantity', 20, 6)->default(1);
            $table->decimal('unit_price', 20, 6);
            $table->decimal('maximum_discount_percent', 7, 4)->default(0);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->timestampsTz();

            $table->unique(['sales_price_list_id', 'line_number'], 'sales_price_list_lines_number_unique');
            $table->unique(['sales_price_list_id', 'item_id', 'uom_code', 'minimum_quantity'], 'sales_price_list_lines_break_unique');
            $table->unique(['id', 'sales_price_list_id', 'company_id', 'plant_id', 'item_id', 'uom_code'], 'sales_price_list_lines_identity_unique');
            $table->foreign(['sales_price_list_id', 'company_id', 'plant_id'], 'sales_price_list_lines_list_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('sales_price_lists')->cascadeOnDelete();
            $table->foreign(['item_id', 'company_id', 'uom_code'], 'sales_price_list_lines_item_uom_fk')
                ->references(['id', 'company_id', 'base_uom'])->on('items')->restrictOnDelete();
        });

        Schema::create('customer_credit_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('customer_party_id');
            $table->decimal('credit_limit', 20, 6);
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->boolean('is_on_hold')->default(false);
            $table->text('hold_reason')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('updated_by');
            $table->timestampsTz();

            $table->unique(['company_id', 'customer_party_id'], 'customer_credit_profiles_customer_unique');
            $table->unique(['id', 'company_id', 'customer_party_id'], 'customer_credit_profiles_identity_unique');
            $table->foreign('company_id', 'customer_credit_profiles_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['customer_party_id', 'company_id'], 'customer_credit_profiles_customer_fk')->references(['id', 'company_id'])->on('parties')->restrictOnDelete();
            $table->foreign('updated_by', 'customer_credit_profiles_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('sales_contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('contract_number', 80);
            $table->uuid('customer_party_id');
            $table->uuid('sales_price_list_id')->nullable();
            $table->date('effective_from');
            $table->date('effective_to');
            $table->decimal('committed_value', 20, 6)->default(0);
            $table->char('currency', 3)->default('INR');
            $table->string('status', 16)->default('DRAFT');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('activated_at')->nullable();
            $table->uuid('activated_by')->nullable();
            $table->timestampTz('closed_at')->nullable();
            $table->uuid('closed_by')->nullable();
            $table->text('closure_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'contract_number'], 'sales_contracts_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'sales_contracts_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'customer_party_id', 'status'], 'sales_contracts_customer_status_index');
            $table->foreign('company_id', 'sales_contracts_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'sales_contracts_plant_fk')->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['customer_party_id', 'company_id'], 'sales_contracts_customer_fk')->references(['id', 'company_id'])->on('parties')->restrictOnDelete();
            $table->foreign(['sales_price_list_id', 'company_id', 'plant_id'], 'sales_contracts_price_list_fk')->references(['id', 'company_id', 'plant_id'])->on('sales_price_lists')->restrictOnDelete();
            $table->foreign('created_by', 'sales_contracts_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('activated_by', 'sales_contracts_activator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('closed_by', 'sales_contracts_closer_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('sales_contract_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sales_contract_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('item_id');
            $table->string('uom_code', 16);
            $table->decimal('committed_quantity', 20, 6);
            $table->decimal('consumed_quantity', 20, 6)->default(0);
            $table->decimal('unit_price', 20, 6);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->timestampsTz();

            $table->unique(['sales_contract_id', 'line_number'], 'sales_contract_lines_number_unique');
            $table->unique(['sales_contract_id', 'item_id', 'uom_code'], 'sales_contract_lines_item_unique');
            $table->unique(['id', 'sales_contract_id', 'company_id', 'plant_id', 'item_id', 'uom_code'], 'sales_contract_lines_identity_unique');
            $table->foreign(['sales_contract_id', 'company_id', 'plant_id'], 'sales_contract_lines_contract_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('sales_contracts')->cascadeOnDelete();
            $table->foreign(['item_id', 'company_id', 'uom_code'], 'sales_contract_lines_item_uom_fk')
                ->references(['id', 'company_id', 'base_uom'])->on('items')->restrictOnDelete();
        });

        Schema::table('sales_orders', function (Blueprint $table) {
            $table->string('order_type', 16)->default('LEGACY')->after('plant_id');
            $table->string('order_number', 80)->nullable()->after('order_type');
            $table->uuid('customer_party_id')->nullable()->after('order_number');
            $table->uuid('sales_lead_id')->nullable()->after('customer_party_id');
            $table->uuid('sales_contract_id')->nullable()->after('sales_lead_id');
            $table->uuid('sales_price_list_id')->nullable()->after('sales_contract_id');
            $table->date('order_date')->nullable()->after('sales_price_list_id');
            $table->date('requested_delivery_date')->nullable()->after('order_date');
            $table->char('currency', 3)->nullable()->after('requested_delivery_date');
            $table->decimal('subtotal', 20, 6)->nullable()->after('currency');
            $table->decimal('discount_amount', 20, 6)->nullable()->after('subtotal');
            $table->decimal('tax_amount', 20, 6)->nullable()->after('discount_amount');
            $table->decimal('total_amount', 20, 6)->nullable()->after('tax_amount');
            $table->decimal('credit_limit_snapshot', 20, 6)->nullable()->after('total_amount');
            $table->decimal('credit_exposure_snapshot', 20, 6)->nullable()->after('credit_limit_snapshot');
            $table->text('notes')->nullable()->after('credit_exposure_snapshot');
            $table->timestampTz('confirmed_at')->nullable()->after('notes');
            $table->uuid('confirmed_by')->nullable()->after('confirmed_at');
            $table->timestampTz('cancelled_at')->nullable()->after('confirmed_by');
            $table->uuid('cancelled_by')->nullable()->after('cancelled_at');
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');

            $table->unique(['company_id', 'plant_id', 'order_number'], 'sales_orders_scope_number_unique');
            $table->index(['company_id', 'plant_id', 'order_type', 'status', 'requested_delivery_date'], 'sales_orders_scope_status_index');
            $table->foreign(['customer_party_id', 'company_id'], 'sales_orders_customer_fk')->references(['id', 'company_id'])->on('parties')->restrictOnDelete();
            $table->foreign(['sales_lead_id', 'company_id', 'plant_id'], 'sales_orders_lead_fk')->references(['id', 'company_id', 'plant_id'])->on('sales_leads')->restrictOnDelete();
            $table->foreign(['sales_contract_id', 'company_id', 'plant_id'], 'sales_orders_contract_fk')->references(['id', 'company_id', 'plant_id'])->on('sales_contracts')->restrictOnDelete();
            $table->foreign(['sales_price_list_id', 'company_id', 'plant_id'], 'sales_orders_price_list_fk')->references(['id', 'company_id', 'plant_id'])->on('sales_price_lists')->restrictOnDelete();
            $table->foreign('confirmed_by', 'sales_orders_confirmer_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by', 'sales_orders_canceller_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('sales_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sales_order_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('item_id');
            $table->string('description', 255);
            $table->string('uom_code', 16);
            $table->decimal('ordered_quantity', 20, 6);
            $table->decimal('allocated_quantity', 20, 6)->default(0);
            $table->decimal('dispatched_quantity', 20, 6)->default(0);
            $table->decimal('invoiced_quantity', 20, 6)->default(0);
            $table->decimal('unit_price', 20, 6);
            $table->decimal('discount_percent', 7, 4)->default(0);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('net_amount', 20, 6);
            $table->decimal('tax_amount', 20, 6);
            $table->decimal('gross_amount', 20, 6);
            $table->decimal('unit_cost_snapshot', 20, 6)->default(0);
            $table->uuid('sales_contract_id')->nullable();
            $table->uuid('sales_contract_line_id')->nullable();
            $table->timestampsTz();

            $table->unique(['sales_order_id', 'line_number'], 'sales_order_lines_number_unique');
            $table->unique(['sales_order_id', 'item_id', 'uom_code'], 'sales_order_lines_item_unique');
            $table->unique(['id', 'sales_order_id', 'company_id', 'plant_id', 'item_id', 'uom_code'], 'sales_order_lines_identity_unique');
            $table->foreign(['sales_order_id', 'company_id', 'plant_id'], 'sales_order_lines_order_fk')->references(['id', 'company_id', 'plant_id'])->on('sales_orders')->cascadeOnDelete();
            $table->foreign(['item_id', 'company_id', 'uom_code'], 'sales_order_lines_item_uom_fk')->references(['id', 'company_id', 'base_uom'])->on('items')->restrictOnDelete();
            $table->foreign(['sales_contract_line_id', 'sales_contract_id', 'company_id', 'plant_id', 'item_id', 'uom_code'], 'sales_order_lines_contract_line_fk')
                ->references(['id', 'sales_contract_id', 'company_id', 'plant_id', 'item_id', 'uom_code'])->on('sales_contract_lines')->restrictOnDelete();
        });

        Schema::create('sales_order_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sales_order_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedInteger('revision_number');
            $table->string('revision_type', 16);
            $table->text('reason');
            $table->json('snapshot_json');
            $table->uuid('created_by');
            $table->timestampTz('created_at');

            $table->unique(['sales_order_id', 'revision_number'], 'sales_order_revisions_number_unique');
            $table->foreign(['sales_order_id', 'company_id', 'plant_id'], 'sales_order_revisions_order_fk')->references(['id', 'company_id', 'plant_id'])->on('sales_orders')->cascadeOnDelete();
            $table->foreign('created_by', 'sales_order_revisions_creator_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('third_party_work_orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('work_number', 80);
            $table->uuid('sales_order_id')->nullable();
            $table->uuid('provider_party_id');
            $table->string('work_type', 40);
            $table->text('description');
            $table->date('expected_start_date');
            $table->date('expected_end_date');
            $table->decimal('agreed_cost', 20, 6)->default(0);
            $table->char('currency', 3)->default('INR');
            $table->string('status', 16)->default('DRAFT');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('released_at')->nullable();
            $table->uuid('released_by')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->uuid('completed_by')->nullable();
            $table->decimal('actual_cost', 20, 6)->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'work_number'], 'third_party_work_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'third_party_work_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'expected_end_date'], 'third_party_work_scope_status_index');
            $table->foreign('company_id', 'third_party_work_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'third_party_work_plant_fk')->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['sales_order_id', 'company_id', 'plant_id'], 'third_party_work_sales_order_fk')->references(['id', 'company_id', 'plant_id'])->on('sales_orders')->restrictOnDelete();
            $table->foreign(['provider_party_id', 'company_id'], 'third_party_work_provider_fk')->references(['id', 'company_id'])->on('parties')->restrictOnDelete();
            $table->foreign('created_by', 'third_party_work_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('released_by', 'third_party_work_releaser_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('completed_by', 'third_party_work_completer_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by', 'third_party_work_canceller_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('sales_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('allocation_number', 80);
            $table->uuid('sales_order_id');
            $table->string('status', 16)->default('RESERVED');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('picked_at')->nullable();
            $table->uuid('picked_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'allocation_number'], 'sales_allocations_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'sales_allocations_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'created_at'], 'sales_allocations_scope_status_index');
            $table->foreign('company_id', 'sales_allocations_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'sales_allocations_plant_fk')->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['sales_order_id', 'company_id', 'plant_id'], 'sales_allocations_order_fk')->references(['id', 'company_id', 'plant_id'])->on('sales_orders')->restrictOnDelete();
            $table->foreign('created_by', 'sales_allocations_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('picked_by', 'sales_allocations_picker_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by', 'sales_allocations_canceller_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('sales_allocation_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sales_allocation_id');
            $table->uuid('sales_order_id');
            $table->uuid('sales_order_line_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('item_id');
            $table->uuid('lot_id');
            $table->uuid('stock_position_id');
            $table->uuid('stock_reservation_id');
            $table->string('uom_code', 16);
            $table->decimal('allocated_quantity', 20, 6);
            $table->decimal('picked_quantity', 20, 6)->default(0);
            $table->uuid('stock_movement_id')->nullable();
            $table->timestampsTz();

            $table->unique(['sales_allocation_id', 'line_number'], 'sales_allocation_lines_number_unique');
            $table->unique(['stock_reservation_id'], 'sales_allocation_lines_reservation_unique');
            $table->unique(['id', 'sales_allocation_id', 'company_id', 'plant_id'], 'sales_allocation_lines_identity_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'sales_allocation_lines_id_scope_unique');
            $table->foreign(['sales_allocation_id', 'company_id', 'plant_id'], 'sales_allocation_lines_allocation_fk')->references(['id', 'company_id', 'plant_id'])->on('sales_allocations')->cascadeOnDelete();
            $table->foreign(['sales_order_line_id', 'sales_order_id', 'company_id', 'plant_id', 'item_id', 'uom_code'], 'sales_allocation_lines_order_line_fk')
                ->references(['id', 'sales_order_id', 'company_id', 'plant_id', 'item_id', 'uom_code'])->on('sales_order_lines')->restrictOnDelete();
            $table->foreign(['lot_id', 'company_id', 'item_id'], 'sales_allocation_lines_lot_fk')->references(['id', 'company_id', 'item_id'])->on('lots')->restrictOnDelete();
            $table->foreign(['stock_position_id', 'company_id', 'plant_id', 'item_id', 'lot_id', 'uom_code'], 'sales_allocation_lines_position_fk')
                ->references(['id', 'company_id', 'plant_id', 'item_id', 'lot_id', 'uom_code'])->on('stock_positions')->restrictOnDelete();
            $table->foreign(['stock_reservation_id', 'company_id', 'plant_id'], 'sales_allocation_lines_reservation_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('stock_reservations')->restrictOnDelete();
            $table->foreign('stock_movement_id', 'sales_allocation_lines_movement_fk')->references('id')->on('stock_movements')->restrictOnDelete();
        });

        Schema::table('shipments', function (Blueprint $table) {
            $table->string('shipment_type', 16)->default('LEGACY')->after('plant_id');
            $table->uuid('sales_order_id')->nullable()->after('party_id');
            $table->uuid('sales_allocation_id')->nullable()->after('sales_order_id');
            $table->string('carrier_name', 160)->nullable()->after('sales_allocation_id');
            $table->string('vehicle_number', 40)->nullable()->after('carrier_name');
            $table->string('driver_name', 160)->nullable()->after('vehicle_number');
            $table->text('notes')->nullable()->after('driver_name');
            $table->timestampTz('loaded_at')->nullable()->after('notes');
            $table->uuid('loaded_by')->nullable()->after('loaded_at');
            $table->uuid('dispatched_by')->nullable()->after('dispatched_at');
            $table->timestampTz('delivered_at')->nullable()->after('dispatched_by');
            $table->uuid('delivered_by')->nullable()->after('delivered_at');
            $table->timestampTz('cancelled_at')->nullable()->after('delivered_by');
            $table->uuid('cancelled_by')->nullable()->after('cancelled_at');
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');

            $table->index(['company_id', 'plant_id', 'shipment_type', 'status'], 'shipments_p2_scope_status_index');
            $table->foreign(['sales_order_id', 'company_id', 'plant_id'], 'shipments_sales_order_fk')->references(['id', 'company_id', 'plant_id'])->on('sales_orders')->restrictOnDelete();
            $table->foreign(['sales_allocation_id', 'company_id', 'plant_id'], 'shipments_allocation_fk')->references(['id', 'company_id', 'plant_id'])->on('sales_allocations')->restrictOnDelete();
            $table->foreign('loaded_by', 'shipments_loader_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('dispatched_by', 'shipments_dispatcher_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('delivered_by', 'shipments_deliverer_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by', 'shipments_canceller_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('shipment_lines', function (Blueprint $table) {
            $table->uuid('sales_order_id')->nullable()->after('shipment_id');
            $table->uuid('sales_order_line_id')->nullable()->after('shipment_id');
            $table->uuid('sales_allocation_line_id')->nullable()->after('sales_order_line_id');
            $table->uuid('stock_position_id')->nullable()->after('fg_lot_id');
            $table->uuid('stock_movement_id')->nullable()->after('stock_position_id');
            $table->decimal('unit_price', 20, 6)->nullable()->after('uom_code');
            $table->decimal('tax_rate', 7, 4)->nullable()->after('unit_price');

            $table->foreign(['sales_order_line_id', 'sales_order_id', 'company_id', 'plant_id', 'item_id', 'uom_code'], 'shipment_lines_sales_order_line_fk')
                ->references(['id', 'sales_order_id', 'company_id', 'plant_id', 'item_id', 'uom_code'])->on('sales_order_lines')->restrictOnDelete();
            $table->foreign(['sales_allocation_line_id', 'company_id', 'plant_id'], 'shipment_lines_allocation_line_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('sales_allocation_lines')->restrictOnDelete();
            $table->foreign(['stock_position_id', 'company_id', 'plant_id', 'item_id', 'fg_lot_id', 'uom_code'], 'shipment_lines_position_fk')
                ->references(['id', 'company_id', 'plant_id', 'item_id', 'lot_id', 'uom_code'])->on('stock_positions')->restrictOnDelete();
            $table->foreign('stock_movement_id', 'shipment_lines_movement_fk')->references('id')->on('stock_movements')->restrictOnDelete();
        });

        Schema::create('delivery_proofs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('shipment_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('proof_number', 80);
            $table->string('outcome', 16);
            $table->string('receiver_name', 160)->nullable();
            $table->timestampTz('event_at');
            $table->string('failure_reason', 160)->nullable();
            $table->text('notes')->nullable();
            $table->uuid('created_by');
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'proof_number'], 'delivery_proofs_scope_number_unique');
            $table->unique(['shipment_id'], 'delivery_proofs_shipment_unique');
            $table->foreign(['shipment_id', 'company_id', 'plant_id'], 'delivery_proofs_shipment_fk')->references(['id', 'company_id', 'plant_id'])->on('shipments')->cascadeOnDelete();
            $table->foreign('created_by', 'delivery_proofs_creator_fk')->references('id')->on('users')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_p2p_type_check');
            DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_p2p_type_check CHECK (invoice_type IN ('LEGACY', 'PAYABLE', 'RECEIVABLE'))");
        }
        Schema::table('sales_invoice_financials', function (Blueprint $table) {
            $table->uuid('sales_order_id')->nullable()->after('shipment_id');
            $table->date('due_date')->nullable()->after('issued_at');
            $table->decimal('paid_amount', 20, 4)->default(0)->after('outstanding_amount');
            $table->decimal('credited_amount', 20, 4)->default(0)->after('paid_amount');
            $table->index(['company_id', 'plant_id', 'due_date', 'outstanding_amount'], 'sales_invoice_financials_ageing_index');
            $table->foreign(['sales_order_id', 'company_id', 'plant_id'], 'sales_invoice_financials_order_fk')->references(['id', 'company_id', 'plant_id'])->on('sales_orders')->restrictOnDelete();
        });
        DB::table('sales_invoice_financials')->orderBy('invoice_id')->each(function (object $invoice): void {
            DB::table('sales_invoice_financials')->where('invoice_id', $invoice->invoice_id)->update([
                'due_date' => $invoice->issued_at ? date('Y-m-d', strtotime((string) $invoice->issued_at.' +30 days')) : now()->addDays(30)->toDateString(),
                'paid_amount' => bcsub((string) $invoice->gross_amount, (string) $invoice->outstanding_amount, 4),
                'credited_amount' => 0,
            ]);
        });

        Schema::create('customer_claims', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('claim_number', 80);
            $table->uuid('customer_party_id');
            $table->uuid('shipment_id');
            $table->uuid('invoice_id')->nullable();
            $table->string('claim_type', 24);
            $table->string('requested_resolution', 24);
            $table->text('reason');
            $table->string('status', 24)->default('OPEN');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('received_at')->nullable();
            $table->uuid('received_by')->nullable();
            $table->timestampTz('resolved_at')->nullable();
            $table->uuid('resolved_by')->nullable();
            $table->string('resolution_type', 24)->nullable();
            $table->text('resolution_notes')->nullable();
            $table->uuid('replacement_sales_order_id')->nullable();
            $table->decimal('credit_amount', 20, 4)->default(0);
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'claim_number'], 'customer_claims_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id', 'shipment_id'], 'customer_claims_identity_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'customer_claims_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'created_at'], 'customer_claims_scope_status_index');
            $table->foreign('company_id', 'customer_claims_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'customer_claims_plant_fk')->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['customer_party_id', 'company_id'], 'customer_claims_customer_fk')->references(['id', 'company_id'])->on('parties')->restrictOnDelete();
            $table->foreign(['shipment_id', 'company_id', 'plant_id', 'customer_party_id'], 'customer_claims_shipment_fk')
                ->references(['id', 'company_id', 'plant_id', 'party_id'])->on('shipments')->restrictOnDelete();
            $table->foreign(['invoice_id', 'company_id', 'plant_id'], 'customer_claims_invoice_fk')->references(['id', 'company_id', 'plant_id'])->on('invoices')->restrictOnDelete();
            $table->foreign(['replacement_sales_order_id', 'company_id', 'plant_id'], 'customer_claims_replacement_order_fk')->references(['id', 'company_id', 'plant_id'])->on('sales_orders')->restrictOnDelete();
            $table->foreign('created_by', 'customer_claims_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('received_by', 'customer_claims_receiver_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('resolved_by', 'customer_claims_resolver_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('customer_claim_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_claim_id');
            $table->uuid('shipment_id');
            $table->uuid('shipment_line_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('item_id');
            $table->uuid('lot_id')->nullable();
            $table->string('uom_code', 16);
            $table->decimal('claimed_quantity', 20, 6);
            $table->decimal('received_quantity', 20, 6)->default(0);
            $table->uuid('return_position_id')->nullable();
            $table->uuid('stock_movement_id')->nullable();
            $table->timestampsTz();

            $table->unique(['customer_claim_id', 'line_number'], 'customer_claim_lines_number_unique');
            $table->unique(['customer_claim_id', 'shipment_line_id'], 'customer_claim_lines_shipment_line_unique');
            $table->foreign(['customer_claim_id', 'company_id', 'plant_id', 'shipment_id'], 'customer_claim_lines_claim_fk')
                ->references(['id', 'company_id', 'plant_id', 'shipment_id'])->on('customer_claims')->cascadeOnDelete();
            $table->foreign(['shipment_line_id', 'shipment_id', 'company_id', 'plant_id', 'item_id', 'uom_code'], 'customer_claim_lines_shipment_line_fk')
                ->references(['id', 'shipment_id', 'company_id', 'plant_id', 'item_id', 'uom_code'])->on('shipment_lines')->restrictOnDelete();
            $table->foreign(['lot_id', 'company_id', 'item_id'], 'customer_claim_lines_lot_fk')->references(['id', 'company_id', 'item_id'])->on('lots')->restrictOnDelete();
            $table->foreign(['return_position_id', 'company_id', 'plant_id', 'item_id', 'uom_code'], 'customer_claim_lines_position_fk')
                ->references(['id', 'company_id', 'plant_id', 'item_id', 'uom_code'])->on('stock_positions')->restrictOnDelete();
            $table->foreign('stock_movement_id', 'customer_claim_lines_movement_fk')->references('id')->on('stock_movements')->restrictOnDelete();
        });

        Schema::create('customer_claim_actions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_claim_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('action_type', 24);
            $table->decimal('amount', 20, 4)->nullable();
            $table->uuid('invoice_id')->nullable();
            $table->uuid('replacement_sales_order_id')->nullable();
            $table->text('notes')->nullable();
            $table->uuid('actor_id');
            $table->timestampTz('created_at');

            $table->unique(['customer_claim_id', 'action_type'], 'customer_claim_actions_type_unique');
            $table->foreign(['customer_claim_id', 'company_id', 'plant_id'], 'customer_claim_actions_claim_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('customer_claims')->cascadeOnDelete();
            $table->foreign(['invoice_id', 'company_id', 'plant_id'], 'customer_claim_actions_invoice_fk')->references(['id', 'company_id', 'plant_id'])->on('invoices')->restrictOnDelete();
            $table->foreign(['replacement_sales_order_id', 'company_id', 'plant_id'], 'customer_claim_actions_replacement_fk')->references(['id', 'company_id', 'plant_id'])->on('sales_orders')->restrictOnDelete();
            $table->foreign('actor_id', 'customer_claim_actions_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('customer_receipts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('receipt_number', 80);
            $table->uuid('customer_party_id');
            $table->date('receipt_date');
            $table->string('payment_method', 16);
            $table->string('bank_reference', 120);
            $table->char('currency', 3)->default('INR');
            $table->decimal('total_amount', 20, 4);
            $table->string('status', 16)->default('POSTED');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampsTz();

            $table->unique(['company_id', 'receipt_number'], 'customer_receipts_company_number_unique');
            $table->unique(['company_id', 'bank_reference'], 'customer_receipts_bank_reference_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'customer_receipts_id_scope_unique');
            $table->foreign('company_id', 'customer_receipts_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'customer_receipts_plant_fk')->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['customer_party_id', 'company_id'], 'customer_receipts_customer_fk')->references(['id', 'company_id'])->on('parties')->restrictOnDelete();
            $table->foreign('created_by', 'customer_receipts_creator_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('customer_receipt_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('customer_receipt_id');
            $table->uuid('invoice_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->decimal('allocated_amount', 20, 4);
            $table->timestampTz('created_at');

            $table->unique(['customer_receipt_id', 'invoice_id'], 'customer_receipt_allocations_invoice_unique');
            $table->foreign(['customer_receipt_id', 'company_id', 'plant_id'], 'customer_receipt_allocations_receipt_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('customer_receipts')->cascadeOnDelete();
            $table->foreign(['invoice_id', 'company_id', 'plant_id'], 'customer_receipt_allocations_invoice_fk')
                ->references(['invoice_id', 'company_id', 'plant_id'])->on('sales_invoice_financials')->restrictOnDelete();
        });

        Schema::create('receivable_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('transaction_type', 24);
            $table->string('reference_number', 120);
            $table->decimal('amount', 20, 4);
            $table->decimal('balance_before', 20, 4);
            $table->decimal('balance_after', 20, 4);
            $table->uuid('customer_receipt_id')->nullable();
            $table->uuid('customer_claim_id')->nullable();
            $table->uuid('actor_id');
            $table->timestampTz('posted_at');
            $table->timestampTz('created_at');

            $table->unique(['company_id', 'transaction_type', 'reference_number'], 'receivable_transactions_reference_unique');
            $table->foreign(['invoice_id', 'company_id', 'plant_id'], 'receivable_transactions_invoice_fk')
                ->references(['invoice_id', 'company_id', 'plant_id'])->on('sales_invoice_financials')->restrictOnDelete();
            $table->foreign(['customer_receipt_id', 'company_id', 'plant_id'], 'receivable_transactions_receipt_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('customer_receipts')->restrictOnDelete();
            $table->foreign(['customer_claim_id', 'company_id', 'plant_id'], 'receivable_transactions_claim_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('customer_claims')->restrictOnDelete();
            $table->foreign('actor_id', 'receivable_transactions_actor_fk')->references('id')->on('users')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach ($this->postgresConstraints() as [$table, $name, $definition]) {
                DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition}");
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach ($this->postgresConstraints() as [$table, $name]) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            }
        }

        Schema::dropIfExists('receivable_transactions');
        Schema::dropIfExists('customer_receipt_allocations');
        Schema::dropIfExists('customer_receipts');
        Schema::dropIfExists('customer_claim_actions');
        Schema::dropIfExists('customer_claim_lines');
        Schema::dropIfExists('customer_claims');

        Schema::table('sales_invoice_financials', function (Blueprint $table) {
            $table->dropForeign('sales_invoice_financials_order_fk');
            $table->dropIndex('sales_invoice_financials_ageing_index');
            $table->dropColumn(['sales_order_id', 'due_date', 'paid_amount', 'credited_amount']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE invoices DROP CONSTRAINT IF EXISTS invoices_p2p_type_check');
            DB::statement("ALTER TABLE invoices ADD CONSTRAINT invoices_p2p_type_check CHECK (invoice_type IN ('LEGACY', 'PAYABLE'))");
        }

        Schema::dropIfExists('delivery_proofs');
        Schema::table('shipment_lines', function (Blueprint $table) {
            $table->dropForeign('shipment_lines_movement_fk');
            $table->dropForeign('shipment_lines_position_fk');
            $table->dropForeign('shipment_lines_allocation_line_fk');
            $table->dropForeign('shipment_lines_sales_order_line_fk');
            $table->dropColumn(['sales_order_id', 'sales_order_line_id', 'sales_allocation_line_id', 'stock_position_id', 'stock_movement_id', 'unit_price', 'tax_rate']);
        });
        Schema::table('shipments', function (Blueprint $table) {
            $table->dropForeign('shipments_canceller_fk');
            $table->dropForeign('shipments_deliverer_fk');
            $table->dropForeign('shipments_dispatcher_fk');
            $table->dropForeign('shipments_loader_fk');
            $table->dropForeign('shipments_allocation_fk');
            $table->dropForeign('shipments_sales_order_fk');
            $table->dropIndex('shipments_p2_scope_status_index');
            $table->dropColumn(['shipment_type', 'sales_order_id', 'sales_allocation_id', 'carrier_name', 'vehicle_number', 'driver_name', 'notes', 'loaded_at', 'loaded_by', 'dispatched_by', 'delivered_at', 'delivered_by', 'cancelled_at', 'cancelled_by', 'cancellation_reason']);
        });

        Schema::dropIfExists('sales_allocation_lines');
        Schema::dropIfExists('sales_allocations');
        Schema::dropIfExists('third_party_work_orders');
        Schema::dropIfExists('sales_order_revisions');
        Schema::dropIfExists('sales_order_lines');
        Schema::table('sales_orders', function (Blueprint $table) {
            $table->dropForeign('sales_orders_canceller_fk');
            $table->dropForeign('sales_orders_confirmer_fk');
            $table->dropForeign('sales_orders_price_list_fk');
            $table->dropForeign('sales_orders_contract_fk');
            $table->dropForeign('sales_orders_lead_fk');
            $table->dropForeign('sales_orders_customer_fk');
            $table->dropIndex('sales_orders_scope_status_index');
            $table->dropUnique('sales_orders_scope_number_unique');
            $table->dropColumn(['order_type', 'order_number', 'customer_party_id', 'sales_lead_id', 'sales_contract_id', 'sales_price_list_id', 'order_date', 'requested_delivery_date', 'currency', 'subtotal', 'discount_amount', 'tax_amount', 'total_amount', 'credit_limit_snapshot', 'credit_exposure_snapshot', 'notes', 'confirmed_at', 'confirmed_by', 'cancelled_at', 'cancelled_by', 'cancellation_reason']);
        });

        Schema::dropIfExists('sales_contract_lines');
        Schema::dropIfExists('sales_contracts');
        Schema::dropIfExists('customer_credit_profiles');
        Schema::dropIfExists('sales_price_list_lines');
        Schema::dropIfExists('sales_price_lists');
        Schema::dropIfExists('sales_lead_events');
        Schema::dropIfExists('sales_leads');

        if (DB::getDriverName() !== 'pgsql') {
            Schema::table('sales_invoice_financials', function (Blueprint $table) {
                $table->dropUnique('sales_invoice_financials_p2_scope_unique');
            });
            Schema::table('shipment_lines', function (Blueprint $table) {
                $table->dropUnique('shipment_lines_p2_lot_identity_unique');
                $table->dropUnique('shipment_lines_p2_identity_unique');
            });
            Schema::table('shipments', function (Blueprint $table) {
                $table->dropUnique('shipments_p2_party_identity_unique');
                $table->dropUnique('shipments_p2_id_scope_unique');
            });
            Schema::table('sales_orders', function (Blueprint $table) {
                $table->dropUnique('sales_orders_p2_id_scope_unique');
            });
            Schema::table('stock_positions', function (Blueprint $table) {
                $table->dropUnique('stock_positions_p2_lot_identity_unique');
                $table->dropUnique('stock_positions_p2_item_identity_unique');
            });
            Schema::table('lots', function (Blueprint $table) {
                $table->dropUnique('lots_p2_item_identity_unique');
            });
            Schema::table('items', function (Blueprint $table) {
                $table->dropUnique('items_p2_uom_identity_unique');
            });
        }
    }

    private function postgresConstraints(): array
    {
        return [
            ['sales_leads', 'sales_leads_values_check', "CHECK (status IN ('NEW', 'QUALIFIED', 'CONVERTED', 'WON', 'LOST') AND estimated_value >= 0 AND currency = 'INR' AND record_version >= 1 AND (contact_email IS NOT NULL OR contact_phone IS NOT NULL))"],
            ['sales_leads', 'sales_leads_workflow_check', "CHECK ((status = 'NEW' AND qualified_at IS NULL AND converted_at IS NULL AND closed_at IS NULL) OR (status = 'QUALIFIED' AND qualified_at IS NOT NULL AND qualified_by IS NOT NULL AND converted_at IS NULL AND closed_at IS NULL) OR (status = 'CONVERTED' AND qualified_at IS NOT NULL AND converted_at IS NOT NULL AND converted_by IS NOT NULL AND closed_at IS NULL) OR (status = 'WON' AND converted_at IS NOT NULL AND closed_at IS NOT NULL AND closed_by IS NOT NULL) OR (status = 'LOST' AND closed_at IS NOT NULL AND closed_by IS NOT NULL AND btrim(lost_reason) <> ''))"],
            ['sales_lead_events', 'sales_lead_events_type_check', "CHECK (event_type IN ('CREATED', 'UPDATED', 'QUALIFIED', 'CONVERTED', 'WON', 'LOST'))"],
            ['sales_price_lists', 'sales_price_lists_values_check', "CHECK (currency = 'INR' AND (effective_to IS NULL OR effective_to >= effective_from))"],
            ['sales_price_lists', 'sales_price_lists_workflow_check', "CHECK (record_version >= 1 AND ((status = 'DRAFT' AND activated_at IS NULL AND retired_at IS NULL) OR (status = 'ACTIVE' AND activated_at IS NOT NULL AND activated_by IS NOT NULL AND retired_at IS NULL) OR (status = 'RETIRED' AND activated_at IS NOT NULL AND retired_at IS NOT NULL AND retired_by IS NOT NULL AND btrim(retirement_reason) <> '')))"],
            ['sales_price_list_lines', 'sales_price_list_lines_values_check', 'CHECK (line_number >= 1 AND minimum_quantity > 0 AND unit_price >= 0 AND maximum_discount_percent BETWEEN 0 AND 100 AND tax_rate BETWEEN 0 AND 100)'],
            ['customer_credit_profiles', 'customer_credit_profiles_values_check', "CHECK (credit_limit >= 0 AND payment_terms_days BETWEEN 0 AND 3650 AND record_version >= 1 AND ((is_on_hold AND btrim(hold_reason) <> '') OR (NOT is_on_hold AND hold_reason IS NULL)))"],
            ['sales_contracts', 'sales_contracts_values_check', "CHECK (currency = 'INR' AND effective_to >= effective_from AND committed_value >= 0 AND record_version >= 1 AND status IN ('DRAFT', 'ACTIVE', 'CLOSED', 'CANCELLED'))"],
            ['sales_contract_lines', 'sales_contract_lines_values_check', 'CHECK (line_number >= 1 AND committed_quantity > 0 AND consumed_quantity >= 0 AND consumed_quantity <= committed_quantity AND unit_price >= 0 AND tax_rate BETWEEN 0 AND 100)'],
            ['sales_orders', 'sales_orders_p2_values_check', "CHECK (order_type <> 'SALES' OR (order_number IS NOT NULL AND customer_party_id IS NOT NULL AND order_date IS NOT NULL AND requested_delivery_date >= order_date AND currency = 'INR' AND subtotal >= 0 AND discount_amount >= 0 AND tax_amount >= 0 AND total_amount = subtotal - discount_amount + tax_amount AND credit_limit_snapshot >= 0 AND credit_exposure_snapshot >= 0 AND created_by IS NOT NULL AND status IN ('DRAFT', 'CONFIRMED', 'ALLOCATED', 'PICKED', 'LOADED', 'DISPATCHED', 'DELIVERED', 'COMPLETED', 'CANCELLED')))"],
            ['sales_order_lines', 'sales_order_lines_values_check', 'CHECK (line_number >= 1 AND ordered_quantity > 0 AND allocated_quantity >= 0 AND dispatched_quantity >= 0 AND invoiced_quantity >= 0 AND allocated_quantity <= ordered_quantity AND dispatched_quantity <= ordered_quantity AND invoiced_quantity <= ordered_quantity AND unit_price >= 0 AND discount_percent BETWEEN 0 AND 100 AND tax_rate BETWEEN 0 AND 100 AND net_amount >= 0 AND tax_amount >= 0 AND gross_amount = net_amount + tax_amount AND unit_cost_snapshot >= 0)'],
            ['sales_order_revisions', 'sales_order_revisions_values_check', "CHECK (revision_number >= 1 AND revision_type IN ('INITIAL', 'AMENDMENT') AND btrim(reason) <> '')"],
            ['third_party_work_orders', 'third_party_work_orders_values_check', "CHECK (expected_end_date >= expected_start_date AND agreed_cost >= 0 AND actual_cost >= 0 AND currency = 'INR' AND record_version >= 1 AND status IN ('DRAFT', 'RELEASED', 'COMPLETED', 'CANCELLED'))"],
            ['sales_allocations', 'sales_allocations_workflow_check', "CHECK (record_version >= 1 AND ((status = 'RESERVED' AND picked_at IS NULL AND cancelled_at IS NULL) OR (status = 'PICKED' AND picked_at IS NOT NULL AND picked_by IS NOT NULL AND cancelled_at IS NULL) OR (status = 'DISPATCHED' AND picked_at IS NOT NULL AND picked_by IS NOT NULL AND cancelled_at IS NULL) OR (status = 'CANCELLED' AND picked_at IS NULL AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND btrim(cancellation_reason) <> '')))"],
            ['sales_allocation_lines', 'sales_allocation_lines_values_check', 'CHECK (line_number >= 1 AND allocated_quantity > 0 AND picked_quantity >= 0 AND picked_quantity <= allocated_quantity)'],
            ['shipments', 'shipments_p2_values_check', "CHECK (shipment_type <> 'SALES' OR (shipment_number IS NOT NULL AND party_id IS NOT NULL AND sales_order_id IS NOT NULL AND sales_allocation_id IS NOT NULL AND status IN ('DRAFT', 'LOADED', 'DISPATCHED', 'DELIVERED', 'FAILED', 'CANCELLED')))"],
            ['delivery_proofs', 'delivery_proofs_values_check', "CHECK (outcome IN ('DELIVERED', 'FAILED') AND ((outcome = 'DELIVERED' AND receiver_name IS NOT NULL AND failure_reason IS NULL) OR (outcome = 'FAILED' AND failure_reason IS NOT NULL)))"],
            ['sales_invoice_financials', 'sales_invoice_financials_settlement_check', 'CHECK (paid_amount >= 0 AND credited_amount >= 0 AND paid_amount + credited_amount <= gross_amount AND outstanding_amount >= 0 AND outstanding_amount <= gross_amount)'],
            ['invoices', 'invoices_receivable_status_check', "CHECK (invoice_type <> 'RECEIVABLE' OR status IN ('POSTED', 'PARTIALLY_PAID', 'PAID', 'CREDITED', 'CANCELLED'))"],
            ['customer_claims', 'customer_claims_values_check', "CHECK (claim_type IN ('DAMAGE', 'SHORTAGE', 'QUALITY', 'RETURN') AND requested_resolution IN ('CREDIT', 'REPLACEMENT', 'RETURN_CREDIT', 'REJECT') AND status IN ('OPEN', 'RETURN_REQUIRED', 'RECEIVED', 'RESOLVED', 'REJECTED') AND record_version >= 1 AND credit_amount >= 0)"],
            ['customer_claim_lines', 'customer_claim_lines_values_check', 'CHECK (line_number >= 1 AND claimed_quantity > 0 AND received_quantity >= 0 AND received_quantity <= claimed_quantity)'],
            ['customer_claim_actions', 'customer_claim_actions_values_check', "CHECK (action_type IN ('RETURN_RECEIPT', 'CREDIT', 'REPLACEMENT', 'REJECT') AND (amount IS NULL OR amount >= 0))"],
            ['customer_receipts', 'customer_receipts_values_check', "CHECK (payment_method IN ('BANK', 'UPI', 'CHEQUE', 'CASH') AND currency = 'INR' AND total_amount > 0 AND status = 'POSTED' AND record_version >= 1)"],
            ['customer_receipt_allocations', 'customer_receipt_allocations_values_check', 'CHECK (allocated_amount > 0)'],
            ['receivable_transactions', 'receivable_transactions_values_check', "CHECK (transaction_type IN ('INVOICE', 'PAYMENT', 'CREDIT', 'WRITE_OFF') AND amount > 0 AND balance_before >= 0 AND balance_after >= 0)"],
        ];
    }
};

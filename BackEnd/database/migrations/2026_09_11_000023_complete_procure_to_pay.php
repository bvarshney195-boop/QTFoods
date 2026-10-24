<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->unique(
                ['id', 'purchase_order_id', 'company_id', 'plant_id', 'item_id', 'uom_code'],
                'po_lines_receiving_identity_unique',
            );
        });
        Schema::table('lots', function (Blueprint $table) {
            $table->unique(['id', 'company_id', 'item_id'], 'lots_p2p_item_identity_unique');
        });
        Schema::table('stock_positions', function (Blueprint $table) {
            $table->unique(
                ['id', 'company_id', 'plant_id', 'item_id', 'uom_code'],
                'stock_positions_p2p_item_uom_unique',
            );
        });

        Schema::create('gate_entries', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('gate_entry_number', 80);
            $table->uuid('purchase_order_id');
            $table->uuid('supplier_party_id');
            $table->string('vehicle_number', 40);
            $table->string('transporter_name')->nullable();
            $table->string('supplier_document_number', 120)->nullable();
            $table->timestampTz('arrived_at');
            $table->text('notes')->nullable();
            $table->string('status', 20)->default('ARRIVED');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('cleared_at')->nullable();
            $table->uuid('cleared_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'gate_entry_number'], 'gate_entries_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'gate_entries_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'arrived_at'], 'gate_entries_scope_status_index');
            $table->foreign('company_id', 'gate_entries_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'gate_entries_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['purchase_order_id', 'company_id', 'plant_id'], 'gate_entries_order_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('purchase_orders')->restrictOnDelete();
            $table->foreign(['supplier_party_id', 'company_id'], 'gate_entries_supplier_fk')
                ->references(['id', 'company_id'])->on('parties')->restrictOnDelete();
            $table->foreign('created_by', 'gate_entries_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cleared_by', 'gate_entries_clearer_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by', 'gate_entries_canceller_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::table('receipts', function (Blueprint $table) {
            $table->string('receipt_number', 80)->nullable()->after('plant_id');
            $table->uuid('gate_entry_id')->nullable()->after('receipt_number');
            $table->uuid('purchase_order_id')->nullable()->after('gate_entry_id');
            $table->uuid('supplier_party_id')->nullable()->after('purchase_order_id');
            $table->date('receipt_date')->nullable()->after('supplier_party_id');
            $table->string('supplier_document_number', 120)->nullable()->after('receipt_date');
            $table->text('notes')->nullable()->after('supplier_document_number');
            $table->timestampTz('posted_at')->nullable()->after('notes');
            $table->uuid('posted_by')->nullable()->after('posted_at');
            $table->timestampTz('cancelled_at')->nullable()->after('posted_by');
            $table->uuid('cancelled_by')->nullable()->after('cancelled_at');
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');

            $table->unique(['company_id', 'plant_id', 'receipt_number'], 'receipts_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'receipts_p2p_id_scope_unique');
            $table->unique('gate_entry_id', 'receipts_gate_entry_unique');
            $table->index(['company_id', 'plant_id', 'status', 'receipt_date'], 'receipts_scope_status_date_index');
            $table->foreign(['gate_entry_id', 'company_id', 'plant_id'], 'receipts_gate_entry_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('gate_entries')->restrictOnDelete();
            $table->foreign(['purchase_order_id', 'company_id', 'plant_id'], 'receipts_order_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('purchase_orders')->restrictOnDelete();
            $table->foreign(['supplier_party_id', 'company_id'], 'receipts_supplier_fk')
                ->references(['id', 'company_id'])->on('parties')->restrictOnDelete();
            $table->foreign('posted_by', 'receipts_poster_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by', 'receipts_canceller_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('receipt_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('receipt_id');
            $table->uuid('purchase_order_id');
            $table->uuid('purchase_order_line_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('item_id');
            $table->string('description');
            $table->decimal('ordered_quantity_snapshot', 20, 6);
            $table->decimal('received_quantity', 20, 6);
            $table->decimal('accepted_quantity', 20, 6)->default(0);
            $table->decimal('rejected_quantity', 20, 6)->default(0);
            $table->string('uom_code', 16);
            $table->string('internal_lot_code', 80);
            $table->string('supplier_lot_code', 120)->nullable();
            $table->date('manufacture_date')->nullable();
            $table->date('expiry_date')->nullable();
            $table->uuid('quality_hold_location_id');
            $table->uuid('released_location_id');
            $table->uuid('lot_id')->nullable();
            $table->uuid('quality_hold_position_id')->nullable();
            $table->uuid('stock_receipt_movement_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['receipt_id', 'line_number'], 'receipt_lines_number_unique');
            $table->unique(['receipt_id', 'purchase_order_line_id'], 'receipt_lines_order_line_unique');
            $table->unique(['id', 'receipt_id', 'company_id', 'plant_id', 'item_id', 'uom_code'], 'receipt_lines_identity_unique');
            $table->unique('stock_receipt_movement_id', 'receipt_lines_movement_unique');
            $table->foreign(['receipt_id', 'company_id', 'plant_id'], 'receipt_lines_receipt_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('receipts')->cascadeOnDelete();
            $table->foreign(
                ['purchase_order_line_id', 'purchase_order_id', 'company_id', 'plant_id', 'item_id', 'uom_code'],
                'receipt_lines_order_line_fk',
            )->references(
                ['id', 'purchase_order_id', 'company_id', 'plant_id', 'item_id', 'uom_code'],
            )->on('purchase_order_lines')->restrictOnDelete();
            $table->foreign(['lot_id', 'company_id', 'item_id'], 'receipt_lines_lot_fk')
                ->references(['id', 'company_id', 'item_id'])->on('lots')->restrictOnDelete();
            $table->foreign(['quality_hold_location_id', 'company_id', 'plant_id'], 'receipt_lines_hold_location_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('locations')->restrictOnDelete();
            $table->foreign(['released_location_id', 'company_id', 'plant_id'], 'receipt_lines_release_location_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('locations')->restrictOnDelete();
            $table->foreign(['quality_hold_position_id', 'company_id', 'plant_id', 'item_id', 'uom_code'], 'receipt_lines_hold_position_fk')
                ->references(['id', 'company_id', 'plant_id', 'item_id', 'uom_code'])->on('stock_positions')->restrictOnDelete();
            $table->foreign('stock_receipt_movement_id', 'receipt_lines_movement_fk')
                ->references('id')->on('stock_movements')->restrictOnDelete();
        });

        Schema::table('quality_tasks', function (Blueprint $table) {
            $table->string('task_number', 80)->nullable()->after('plant_id');
            $table->uuid('receipt_id')->nullable()->after('task_number');
            $table->text('notes')->nullable()->after('receipt_id');
            $table->timestampTz('completed_at')->nullable()->after('notes');
            $table->uuid('completed_by')->nullable()->after('completed_at');

            $table->unique(['company_id', 'plant_id', 'task_number'], 'quality_tasks_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'quality_tasks_p2p_id_scope_unique');
            $table->unique('receipt_id', 'quality_tasks_receipt_unique');
            $table->index(['company_id', 'plant_id', 'status', 'created_at'], 'quality_tasks_scope_status_index');
            $table->foreign(['receipt_id', 'company_id', 'plant_id'], 'quality_tasks_receipt_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('receipts')->restrictOnDelete();
            $table->foreign('completed_by', 'quality_tasks_completer_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('incoming_quality_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('quality_task_id');
            $table->uuid('receipt_id');
            $table->uuid('receipt_line_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('item_id');
            $table->uuid('lot_id');
            $table->string('uom_code', 16);
            $table->decimal('inspected_quantity', 20, 6);
            $table->decimal('accepted_quantity', 20, 6)->default(0);
            $table->decimal('rejected_quantity', 20, 6)->default(0);
            $table->string('result', 16)->default('PENDING');
            $table->text('rejection_reason')->nullable();
            $table->uuid('quality_hold_position_id');
            $table->uuid('released_position_id')->nullable();
            $table->uuid('rejected_position_id')->nullable();
            $table->uuid('accepted_movement_id')->nullable();
            $table->uuid('rejected_movement_id')->nullable();
            $table->timestampsTz();

            $table->unique(['quality_task_id', 'line_number'], 'incoming_quality_lines_number_unique');
            $table->unique('receipt_line_id', 'incoming_quality_lines_receipt_line_unique');
            $table->unique(['id', 'company_id', 'plant_id', 'item_id', 'lot_id', 'uom_code'], 'incoming_quality_lines_identity_unique');
            $table->foreign(['quality_task_id', 'company_id', 'plant_id'], 'incoming_quality_lines_task_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('quality_tasks')->cascadeOnDelete();
            $table->foreign(
                ['receipt_line_id', 'receipt_id', 'company_id', 'plant_id', 'item_id', 'uom_code'],
                'incoming_quality_lines_receipt_line_fk',
            )->references(
                ['id', 'receipt_id', 'company_id', 'plant_id', 'item_id', 'uom_code'],
            )->on('receipt_lines')->restrictOnDelete();
            $table->foreign(['lot_id', 'company_id', 'item_id'], 'incoming_quality_lines_lot_fk')
                ->references(['id', 'company_id', 'item_id'])->on('lots')->restrictOnDelete();
            $table->foreign(['quality_hold_position_id', 'company_id', 'plant_id', 'item_id', 'uom_code'], 'incoming_quality_lines_hold_position_fk')
                ->references(['id', 'company_id', 'plant_id', 'item_id', 'uom_code'])->on('stock_positions')->restrictOnDelete();
            $table->foreign(['released_position_id', 'company_id', 'plant_id', 'item_id', 'uom_code'], 'incoming_quality_lines_released_position_fk')
                ->references(['id', 'company_id', 'plant_id', 'item_id', 'uom_code'])->on('stock_positions')->restrictOnDelete();
            $table->foreign(['rejected_position_id', 'company_id', 'plant_id', 'item_id', 'uom_code'], 'incoming_quality_lines_rejected_position_fk')
                ->references(['id', 'company_id', 'plant_id', 'item_id', 'uom_code'])->on('stock_positions')->restrictOnDelete();
            $table->foreign('accepted_movement_id', 'incoming_quality_lines_accept_movement_fk')
                ->references('id')->on('stock_movements')->restrictOnDelete();
            $table->foreign('rejected_movement_id', 'incoming_quality_lines_reject_movement_fk')
                ->references('id')->on('stock_movements')->restrictOnDelete();
        });

        Schema::create('supplier_returns', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('return_number', 80);
            $table->uuid('supplier_party_id');
            $table->date('return_date');
            $table->text('reason');
            $table->string('status', 16)->default('DRAFT');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('posted_at')->nullable();
            $table->uuid('posted_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'return_number'], 'supplier_returns_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'supplier_returns_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'return_date'], 'supplier_returns_scope_status_index');
            $table->foreign('company_id', 'supplier_returns_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'supplier_returns_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['supplier_party_id', 'company_id'], 'supplier_returns_supplier_fk')
                ->references(['id', 'company_id'])->on('parties')->restrictOnDelete();
            $table->foreign('created_by', 'supplier_returns_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('posted_by', 'supplier_returns_poster_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by', 'supplier_returns_canceller_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('supplier_return_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('supplier_return_id');
            $table->uuid('incoming_quality_line_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('item_id');
            $table->uuid('lot_id');
            $table->string('uom_code', 16);
            $table->decimal('return_quantity', 20, 6);
            $table->uuid('rejected_position_id');
            $table->uuid('movement_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestampsTz();

            $table->unique(['supplier_return_id', 'line_number'], 'supplier_return_lines_number_unique');
            $table->unique(['supplier_return_id', 'incoming_quality_line_id'], 'supplier_return_lines_quality_unique');
            $table->unique('movement_id', 'supplier_return_lines_movement_unique');
            $table->foreign(['supplier_return_id', 'company_id', 'plant_id'], 'supplier_return_lines_return_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('supplier_returns')->cascadeOnDelete();
            $table->foreign(
                ['incoming_quality_line_id', 'company_id', 'plant_id', 'item_id', 'lot_id', 'uom_code'],
                'supplier_return_lines_quality_line_fk',
            )->references(
                ['id', 'company_id', 'plant_id', 'item_id', 'lot_id', 'uom_code'],
            )->on('incoming_quality_lines')->restrictOnDelete();
            $table->foreign(['rejected_position_id', 'company_id', 'plant_id', 'item_id', 'uom_code'], 'supplier_return_lines_position_fk')
                ->references(['id', 'company_id', 'plant_id', 'item_id', 'uom_code'])->on('stock_positions')->restrictOnDelete();
            $table->foreign('movement_id', 'supplier_return_lines_movement_fk')
                ->references('id')->on('stock_movements')->restrictOnDelete();
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->string('invoice_type', 16)->default('LEGACY')->after('plant_id');
            $table->string('ap_number', 80)->nullable()->after('invoice_type');
            $table->string('supplier_invoice_number', 120)->nullable()->after('ap_number');
            $table->uuid('purchase_order_id')->nullable()->after('supplier_invoice_number');
            $table->uuid('supplier_party_id')->nullable()->after('purchase_order_id');
            $table->date('invoice_date')->nullable()->after('supplier_party_id');
            $table->date('due_date')->nullable()->after('invoice_date');
            $table->char('currency', 3)->nullable()->after('due_date');
            $table->decimal('subtotal', 20, 6)->nullable()->after('currency');
            $table->decimal('tax_amount', 20, 6)->nullable()->after('subtotal');
            $table->decimal('total_amount', 20, 6)->nullable()->after('tax_amount');
            $table->decimal('paid_amount', 20, 6)->default(0)->after('total_amount');
            $table->json('match_summary')->nullable()->after('paid_amount');
            $table->text('notes')->nullable()->after('match_summary');
            $table->timestampTz('matched_at')->nullable()->after('notes');
            $table->uuid('matched_by')->nullable()->after('matched_at');
            $table->timestampTz('approved_at')->nullable()->after('matched_by');
            $table->uuid('approved_by')->nullable()->after('approved_at');
            $table->timestampTz('cancelled_at')->nullable()->after('approved_by');
            $table->uuid('cancelled_by')->nullable()->after('cancelled_at');
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');

            $table->unique(['company_id', 'plant_id', 'ap_number'], 'invoices_scope_ap_number_unique');
            $table->unique(['company_id', 'supplier_party_id', 'supplier_invoice_number'], 'invoices_supplier_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'invoices_p2p_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'invoice_type', 'status', 'due_date'], 'invoices_scope_type_status_index');
            $table->foreign(['purchase_order_id', 'company_id', 'plant_id'], 'invoices_purchase_order_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('purchase_orders')->restrictOnDelete();
            $table->foreign(['supplier_party_id', 'company_id'], 'invoices_supplier_fk')
                ->references(['id', 'company_id'])->on('parties')->restrictOnDelete();
            $table->foreign('matched_by', 'invoices_matcher_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by', 'invoices_approver_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by', 'invoices_canceller_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('payable_invoice_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('invoice_id');
            $table->uuid('purchase_order_id');
            $table->uuid('purchase_order_line_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('item_id');
            $table->string('description');
            $table->decimal('ordered_quantity_snapshot', 20, 6);
            $table->decimal('accepted_quantity_snapshot', 20, 6)->default(0);
            $table->decimal('invoice_quantity', 20, 6);
            $table->string('uom_code', 16);
            $table->decimal('po_unit_price_snapshot', 20, 6);
            $table->decimal('invoice_unit_price', 20, 6);
            $table->decimal('tax_rate', 7, 4)->default(0);
            $table->decimal('net_amount', 20, 6);
            $table->decimal('tax_amount', 20, 6);
            $table->decimal('gross_amount', 20, 6);
            $table->string('match_result', 16)->default('PENDING');
            $table->text('match_exception')->nullable();
            $table->timestampsTz();

            $table->unique(['invoice_id', 'line_number'], 'payable_invoice_lines_number_unique');
            $table->unique(['invoice_id', 'purchase_order_line_id'], 'payable_invoice_lines_order_line_unique');
            $table->foreign(['invoice_id', 'company_id', 'plant_id'], 'payable_invoice_lines_invoice_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('invoices')->cascadeOnDelete();
            $table->foreign(
                ['purchase_order_line_id', 'purchase_order_id', 'company_id', 'plant_id', 'item_id', 'uom_code'],
                'payable_invoice_lines_order_line_fk',
            )->references(
                ['id', 'purchase_order_id', 'company_id', 'plant_id', 'item_id', 'uom_code'],
            )->on('purchase_order_lines')->restrictOnDelete();
        });

        Schema::create('payment_proposals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('proposal_number', 80);
            $table->date('payment_date');
            $table->char('currency', 3)->default('INR');
            $table->decimal('total_amount', 20, 6);
            $table->text('notes')->nullable();
            $table->string('status', 16)->default('DRAFT');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('approved_at')->nullable();
            $table->uuid('approved_by')->nullable();
            $table->timestampTz('executed_at')->nullable();
            $table->uuid('executed_by')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'proposal_number'], 'payment_proposals_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'payment_proposals_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'payment_date'], 'payment_proposals_scope_status_index');
            $table->foreign('company_id', 'payment_proposals_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'payment_proposals_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign('created_by', 'payment_proposals_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('approved_by', 'payment_proposals_approver_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('executed_by', 'payment_proposals_executor_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by', 'payment_proposals_canceller_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('payment_proposal_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('payment_proposal_id');
            $table->uuid('invoice_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->decimal('proposed_amount', 20, 6);
            $table->timestampsTz();

            $table->unique(['payment_proposal_id', 'line_number'], 'payment_proposal_lines_number_unique');
            $table->unique(['payment_proposal_id', 'invoice_id'], 'payment_proposal_lines_invoice_unique');
            $table->foreign(['payment_proposal_id', 'company_id', 'plant_id'], 'payment_proposal_lines_proposal_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('payment_proposals')->cascadeOnDelete();
            $table->foreign(['invoice_id', 'company_id', 'plant_id'], 'payment_proposal_lines_invoice_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('invoices')->restrictOnDelete();
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->uuid('payment_proposal_id');
            $table->string('payment_number', 80);
            $table->date('payment_date');
            $table->string('method', 24);
            $table->string('bank_reference', 120);
            $table->char('currency', 3)->default('INR');
            $table->decimal('total_amount', 20, 6);
            $table->string('status', 16)->default('POSTED');
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('reconciled_at')->nullable();
            $table->uuid('reconciled_by')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'payment_number'], 'supplier_payments_scope_number_unique');
            $table->unique(['company_id', 'bank_reference'], 'supplier_payments_bank_reference_unique');
            $table->unique('payment_proposal_id', 'supplier_payments_proposal_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'supplier_payments_id_scope_unique');
            $table->index(['company_id', 'plant_id', 'status', 'payment_date'], 'supplier_payments_scope_status_index');
            $table->foreign('company_id', 'supplier_payments_company_fk')->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'supplier_payments_plant_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['payment_proposal_id', 'company_id', 'plant_id'], 'supplier_payments_proposal_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('payment_proposals')->restrictOnDelete();
            $table->foreign('created_by', 'supplier_payments_creator_fk')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('reconciled_by', 'supplier_payments_reconciler_fk')->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('supplier_payment_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('supplier_payment_id');
            $table->uuid('invoice_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->decimal('allocated_amount', 20, 6);
            $table->timestampTz('created_at');

            $table->unique(['supplier_payment_id', 'invoice_id'], 'supplier_payment_allocations_invoice_unique');
            $table->foreign(['supplier_payment_id', 'company_id', 'plant_id'], 'supplier_payment_allocations_payment_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('supplier_payments')->restrictOnDelete();
            $table->foreign(['invoice_id', 'company_id', 'plant_id'], 'supplier_payment_allocations_invoice_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('invoices')->restrictOnDelete();
        });

        Schema::create('payment_reconciliations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('supplier_payment_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->date('statement_date');
            $table->string('statement_reference', 120);
            $table->text('notes')->nullable();
            $table->uuid('reconciled_by');
            $table->timestampTz('created_at');

            $table->unique('supplier_payment_id', 'payment_reconciliations_payment_unique');
            $table->unique(['company_id', 'statement_reference'], 'payment_reconciliations_reference_unique');
            $table->foreign(['supplier_payment_id', 'company_id', 'plant_id'], 'payment_reconciliations_payment_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('supplier_payments')->restrictOnDelete();
            $table->foreign('reconciled_by', 'payment_reconciliations_actor_fk')
                ->references('id')->on('users')->restrictOnDelete();
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

        Schema::dropIfExists('payment_reconciliations');
        Schema::dropIfExists('supplier_payment_allocations');
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('payment_proposal_lines');
        Schema::dropIfExists('payment_proposals');
        Schema::dropIfExists('payable_invoice_lines');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign('invoices_canceller_fk');
            $table->dropForeign('invoices_approver_fk');
            $table->dropForeign('invoices_matcher_fk');
            $table->dropForeign('invoices_supplier_fk');
            $table->dropForeign('invoices_purchase_order_fk');
            $table->dropIndex('invoices_scope_type_status_index');
            $table->dropUnique('invoices_p2p_id_scope_unique');
            $table->dropUnique('invoices_supplier_number_unique');
            $table->dropUnique('invoices_scope_ap_number_unique');
            $table->dropColumn([
                'invoice_type', 'ap_number', 'supplier_invoice_number', 'purchase_order_id',
                'supplier_party_id', 'invoice_date', 'due_date', 'currency', 'subtotal',
                'tax_amount', 'total_amount', 'paid_amount', 'match_summary', 'notes',
                'matched_at', 'matched_by', 'approved_at', 'approved_by', 'cancelled_at',
                'cancelled_by', 'cancellation_reason',
            ]);
        });
        Schema::dropIfExists('supplier_return_lines');
        Schema::dropIfExists('supplier_returns');
        Schema::dropIfExists('incoming_quality_lines');
        Schema::table('quality_tasks', function (Blueprint $table) {
            $table->dropForeign('quality_tasks_completer_fk');
            $table->dropForeign('quality_tasks_receipt_fk');
            $table->dropIndex('quality_tasks_scope_status_index');
            $table->dropUnique('quality_tasks_receipt_unique');
            $table->dropUnique('quality_tasks_p2p_id_scope_unique');
            $table->dropUnique('quality_tasks_scope_number_unique');
            $table->dropColumn(['task_number', 'receipt_id', 'notes', 'completed_at', 'completed_by']);
        });
        Schema::dropIfExists('receipt_lines');
        Schema::table('receipts', function (Blueprint $table) {
            $table->dropForeign('receipts_canceller_fk');
            $table->dropForeign('receipts_poster_fk');
            $table->dropForeign('receipts_supplier_fk');
            $table->dropForeign('receipts_order_fk');
            $table->dropForeign('receipts_gate_entry_fk');
            $table->dropIndex('receipts_scope_status_date_index');
            $table->dropUnique('receipts_gate_entry_unique');
            $table->dropUnique('receipts_p2p_id_scope_unique');
            $table->dropUnique('receipts_scope_number_unique');
            $table->dropColumn([
                'receipt_number', 'gate_entry_id', 'purchase_order_id', 'supplier_party_id',
                'receipt_date', 'supplier_document_number', 'notes', 'posted_at', 'posted_by',
                'cancelled_at', 'cancelled_by', 'cancellation_reason',
            ]);
        });
        Schema::dropIfExists('gate_entries');
        Schema::table('purchase_order_lines', function (Blueprint $table) {
            $table->dropUnique('po_lines_receiving_identity_unique');
        });
        Schema::table('stock_positions', function (Blueprint $table) {
            $table->dropUnique('stock_positions_p2p_item_uom_unique');
        });
        Schema::table('lots', function (Blueprint $table) {
            $table->dropUnique('lots_p2p_item_identity_unique');
        });
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
            ['gate_entries', 'gate_entries_status_check', "CHECK (status IN ('ARRIVED', 'CLEARED', 'CANCELLED'))"],
            ['gate_entries', 'gate_entries_version_check', 'CHECK (record_version >= 1)'],
            ['gate_entries', 'gate_entries_workflow_check', "CHECK ((status = 'ARRIVED' AND cleared_at IS NULL AND cancelled_at IS NULL) OR (status = 'CLEARED' AND cleared_at IS NOT NULL AND cleared_by IS NOT NULL AND cancelled_at IS NULL) OR (status = 'CANCELLED' AND cleared_at IS NULL AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND btrim(cancellation_reason) <> ''))"],
            ['receipts', 'receipts_p2p_status_check', "CHECK (receipt_number IS NULL OR status IN ('DRAFT', 'QC_PENDING', 'COMPLETED', 'CANCELLED'))"],
            ['receipts', 'receipts_p2p_values_check', "CHECK (receipt_number IS NULL OR (gate_entry_id IS NOT NULL AND purchase_order_id IS NOT NULL AND supplier_party_id IS NOT NULL AND receipt_date IS NOT NULL AND created_by IS NOT NULL))"],
            ['receipts', 'receipts_p2p_workflow_check', "CHECK (receipt_number IS NULL OR (status = 'DRAFT' AND posted_at IS NULL AND cancelled_at IS NULL) OR (status IN ('QC_PENDING', 'COMPLETED') AND posted_at IS NOT NULL AND posted_by IS NOT NULL AND cancelled_at IS NULL) OR (status = 'CANCELLED' AND posted_at IS NULL AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND btrim(cancellation_reason) <> ''))"],
            ['receipt_lines', 'receipt_lines_values_check', 'CHECK (line_number >= 1 AND ordered_quantity_snapshot > 0 AND received_quantity > 0 AND accepted_quantity >= 0 AND rejected_quantity >= 0 AND accepted_quantity + rejected_quantity <= received_quantity AND (expiry_date IS NULL OR manufacture_date IS NULL OR expiry_date >= manufacture_date))'],
            ['quality_tasks', 'quality_tasks_p2p_status_check', "CHECK (task_number IS NULL OR status IN ('PENDING', 'COMPLETED'))"],
            ['quality_tasks', 'quality_tasks_p2p_workflow_check', "CHECK (task_number IS NULL OR (status = 'PENDING' AND completed_at IS NULL AND completed_by IS NULL) OR (status = 'COMPLETED' AND completed_at IS NOT NULL AND completed_by IS NOT NULL))"],
            ['incoming_quality_lines', 'incoming_quality_lines_values_check', "CHECK (line_number >= 1 AND inspected_quantity > 0 AND accepted_quantity >= 0 AND rejected_quantity >= 0 AND accepted_quantity + rejected_quantity <= inspected_quantity AND result IN ('PENDING', 'PASS', 'PARTIAL', 'FAIL') AND ((result = 'PENDING' AND accepted_quantity = 0 AND rejected_quantity = 0) OR (result = 'PASS' AND accepted_quantity = inspected_quantity AND rejected_quantity = 0) OR (result = 'PARTIAL' AND accepted_quantity > 0 AND rejected_quantity > 0 AND accepted_quantity + rejected_quantity = inspected_quantity) OR (result = 'FAIL' AND accepted_quantity = 0 AND rejected_quantity = inspected_quantity)) AND (rejected_quantity = 0 OR btrim(rejection_reason) <> ''))"],
            ['supplier_returns', 'supplier_returns_status_check', "CHECK (status IN ('DRAFT', 'POSTED', 'CANCELLED'))"],
            ['supplier_returns', 'supplier_returns_version_check', 'CHECK (record_version >= 1)'],
            ['supplier_returns', 'supplier_returns_values_check', "CHECK (btrim(reason) <> '' AND ((status = 'DRAFT' AND posted_at IS NULL AND cancelled_at IS NULL) OR (status = 'POSTED' AND posted_at IS NOT NULL AND posted_by IS NOT NULL AND cancelled_at IS NULL) OR (status = 'CANCELLED' AND posted_at IS NULL AND cancelled_at IS NOT NULL AND cancelled_by IS NOT NULL AND btrim(cancellation_reason) <> '')))"],
            ['supplier_return_lines', 'supplier_return_lines_values_check', 'CHECK (line_number >= 1 AND return_quantity > 0)'],
            ['invoices', 'invoices_p2p_type_check', "CHECK (invoice_type IN ('LEGACY', 'PAYABLE'))"],
            ['invoices', 'invoices_p2p_status_check', "CHECK (invoice_type <> 'PAYABLE' OR status IN ('DRAFT', 'MATCH_EXCEPTION', 'MATCHED', 'APPROVED', 'PARTIALLY_PAID', 'PAID', 'CANCELLED'))"],
            ['invoices', 'invoices_p2p_values_check', "CHECK (invoice_type <> 'PAYABLE' OR (ap_number IS NOT NULL AND supplier_invoice_number IS NOT NULL AND purchase_order_id IS NOT NULL AND supplier_party_id IS NOT NULL AND invoice_date IS NOT NULL AND due_date >= invoice_date AND currency = 'INR' AND subtotal >= 0 AND tax_amount >= 0 AND total_amount = subtotal + tax_amount AND paid_amount >= 0 AND paid_amount <= total_amount AND created_by IS NOT NULL))"],
            ['payable_invoice_lines', 'payable_invoice_lines_values_check', "CHECK (line_number >= 1 AND ordered_quantity_snapshot > 0 AND accepted_quantity_snapshot >= 0 AND invoice_quantity > 0 AND po_unit_price_snapshot >= 0 AND invoice_unit_price >= 0 AND tax_rate BETWEEN 0 AND 100 AND net_amount = round(invoice_quantity * invoice_unit_price, 6) AND tax_amount = round(net_amount * tax_rate / 100, 6) AND gross_amount = net_amount + tax_amount AND match_result IN ('PENDING', 'PASS', 'EXCEPTION'))"],
            ['payment_proposals', 'payment_proposals_status_check', "CHECK (status IN ('DRAFT', 'APPROVED', 'EXECUTED', 'CANCELLED'))"],
            ['payment_proposals', 'payment_proposals_values_check', "CHECK (record_version >= 1 AND currency = 'INR' AND total_amount > 0)"],
            ['payment_proposal_lines', 'payment_proposal_lines_values_check', 'CHECK (line_number >= 1 AND proposed_amount > 0)'],
            ['supplier_payments', 'supplier_payments_status_check', "CHECK (status IN ('POSTED', 'RECONCILED') AND method IN ('BANK_TRANSFER', 'UPI', 'CHEQUE') AND currency = 'INR' AND total_amount > 0 AND record_version >= 1)"],
            ['supplier_payment_allocations', 'supplier_payment_allocations_values_check', 'CHECK (allocated_amount > 0)'],
        ];
    }
};

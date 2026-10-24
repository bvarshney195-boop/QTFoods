<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('requisition_lines', function (Blueprint $table) {
            $table->unique(
                ['id', 'requisition_id', 'company_id', 'plant_id'],
                'requisition_lines_id_source_unique'
            );
        });

        Schema::create('requests_for_quotation', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->string('rfq_number', 80);
            $table->uuid('requisition_id');
            $table->string('status', 24)->default('DRAFT');
            $table->char('currency', 3);
            $table->decimal('estimated_total_snapshot', 20, 6);
            $table->date('response_due_date');
            $table->date('required_by_date');
            $table->text('commercial_terms')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('created_by');
            $table->timestampTz('issued_at')->nullable();
            $table->uuid('issued_by')->nullable();
            $table->timestampTz('awarded_at')->nullable();
            $table->uuid('awarded_by')->nullable();
            $table->uuid('awarded_supplier_id')->nullable();
            $table->uuid('awarded_quote_id')->nullable();
            $table->text('award_reason')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->uuid('cancelled_by')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestampsTz();

            $table->unique(['company_id', 'plant_id', 'rfq_number'], 'rfqs_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'rfqs_id_scope_unique');
            $table->index(
                ['company_id', 'plant_id', 'status', 'response_due_date'],
                'rfqs_scope_status_due_index'
            );
            $table->foreign('company_id', 'rfqs_company_fk')
                ->references('id')->on('companies')->restrictOnDelete();
            $table->foreign(['plant_id', 'company_id'], 'rfqs_plant_scope_fk')
                ->references(['id', 'company_id'])->on('plants')->restrictOnDelete();
            $table->foreign(['requisition_id', 'company_id', 'plant_id'], 'rfqs_requisition_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('requisitions')->restrictOnDelete();
            $table->foreign('created_by', 'rfqs_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('issued_by', 'rfqs_issuer_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('awarded_by', 'rfqs_awarder_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign(['awarded_supplier_id', 'company_id'], 'rfqs_awarded_supplier_fk')
                ->references(['id', 'company_id'])->on('parties')->restrictOnDelete();
            $table->foreign('cancelled_by', 'rfqs_canceller_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('rfq_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('rfq_id');
            $table->uuid('requisition_id');
            $table->uuid('requisition_line_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('item_id');
            $table->string('description');
            $table->decimal('quantity', 20, 6);
            $table->string('uom_code', 16);
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['rfq_id', 'line_number'], 'rfq_lines_number_unique');
            $table->unique(['rfq_id', 'requisition_line_id'], 'rfq_lines_source_unique');
            $table->unique(['id', 'rfq_id', 'company_id', 'plant_id'], 'rfq_lines_id_source_unique');
            $table->foreign(['rfq_id', 'company_id', 'plant_id'], 'rfq_lines_rfq_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('requests_for_quotation')->cascadeOnDelete();
            $table->foreign(
                ['requisition_line_id', 'requisition_id', 'company_id', 'plant_id'],
                'rfq_lines_requisition_line_fk'
            )->references(
                ['id', 'requisition_id', 'company_id', 'plant_id']
            )->on('requisition_lines')->restrictOnDelete();
            $table->foreign(['item_id', 'company_id'], 'rfq_lines_item_fk')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign('uom_code', 'rfq_lines_uom_fk')
                ->references('code')->on('uoms')->restrictOnDelete();
        });

        Schema::create('rfq_suppliers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('rfq_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->uuid('supplier_party_id');
            $table->string('status', 24)->default('SELECTED');
            $table->timestampTz('invited_at')->nullable();
            $table->timestampTz('responded_at')->nullable();
            $table->timestampsTz();

            $table->unique(['rfq_id', 'supplier_party_id'], 'rfq_suppliers_party_unique');
            $table->unique(
                ['rfq_id', 'supplier_party_id', 'company_id', 'plant_id'],
                'rfq_suppliers_identity_unique'
            );
            $table->foreign(['rfq_id', 'company_id', 'plant_id'], 'rfq_suppliers_rfq_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('requests_for_quotation')->cascadeOnDelete();
            $table->foreign(['supplier_party_id', 'company_id'], 'rfq_suppliers_party_fk')
                ->references(['id', 'company_id'])->on('parties')->restrictOnDelete();
        });

        Schema::create('supplier_quotes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('rfq_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->uuid('supplier_party_id');
            $table->string('quote_number', 100);
            $table->date('quote_date');
            $table->date('valid_until');
            $table->date('promised_delivery_date');
            $table->unsignedSmallInteger('payment_terms_days');
            $table->char('currency', 3);
            $table->decimal('subtotal', 20, 6);
            $table->decimal('freight_amount', 20, 6)->default(0);
            $table->decimal('other_charges', 20, 6)->default(0);
            $table->decimal('discount_amount', 20, 6)->default(0);
            $table->decimal('total_amount', 20, 6);
            $table->string('status', 24)->default('SUBMITTED');
            $table->text('notes')->nullable();
            $table->unsignedBigInteger('record_version')->default(1);
            $table->uuid('submitted_by');
            $table->timestampTz('submitted_at');
            $table->timestampsTz();

            $table->unique(['rfq_id', 'supplier_party_id'], 'supplier_quotes_rfq_supplier_unique');
            $table->unique(['id', 'rfq_id', 'company_id', 'plant_id'], 'supplier_quotes_id_source_unique');
            $table->index(['company_id', 'plant_id', 'total_amount'], 'supplier_quotes_scope_total_index');
            $table->foreign(['rfq_id', 'company_id', 'plant_id'], 'supplier_quotes_rfq_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('requests_for_quotation')->cascadeOnDelete();
            $table->foreign(
                ['rfq_id', 'supplier_party_id', 'company_id', 'plant_id'],
                'supplier_quotes_invitation_fk'
            )->references(
                ['rfq_id', 'supplier_party_id', 'company_id', 'plant_id']
            )->on('rfq_suppliers')->restrictOnDelete();
            $table->foreign('submitted_by', 'supplier_quotes_submitter_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('supplier_quote_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('supplier_quote_id');
            $table->uuid('rfq_id');
            $table->uuid('rfq_line_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->decimal('quantity', 20, 6);
            $table->string('uom_code', 16);
            $table->decimal('unit_price', 20, 6);
            $table->decimal('line_total', 20, 6);
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['supplier_quote_id', 'line_number'], 'supplier_quote_lines_number_unique');
            $table->unique(['supplier_quote_id', 'rfq_line_id'], 'supplier_quote_lines_rfq_line_unique');
            $table->unique(
                ['id', 'supplier_quote_id', 'rfq_id', 'company_id', 'plant_id'],
                'supplier_quote_lines_id_source_unique'
            );
            $table->foreign(
                ['supplier_quote_id', 'rfq_id', 'company_id', 'plant_id'],
                'supplier_quote_lines_quote_fk'
            )->references(
                ['id', 'rfq_id', 'company_id', 'plant_id']
            )->on('supplier_quotes')->cascadeOnDelete();
            $table->foreign(
                ['rfq_line_id', 'rfq_id', 'company_id', 'plant_id'],
                'supplier_quote_lines_rfq_line_fk'
            )->references(
                ['id', 'rfq_id', 'company_id', 'plant_id']
            )->on('rfq_lines')->restrictOnDelete();
            $table->foreign('uom_code', 'supplier_quote_lines_uom_fk')
                ->references('code')->on('uoms')->restrictOnDelete();
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE requests_for_quotation ADD CONSTRAINT rfqs_awarded_quote_fk '
                .'FOREIGN KEY (awarded_quote_id) REFERENCES supplier_quotes(id) ON DELETE RESTRICT'
            );
        }

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->string('po_number', 80)->nullable()->after('plant_id');
            $table->uuid('rfq_id')->nullable()->after('po_number');
            $table->uuid('requisition_id')->nullable()->after('rfq_id');
            $table->uuid('supplier_party_id')->nullable()->after('requisition_id');
            $table->uuid('supplier_quote_id')->nullable()->after('supplier_party_id');
            $table->date('order_date')->nullable()->after('supplier_quote_id');
            $table->date('required_by_date')->nullable()->after('order_date');
            $table->char('currency', 3)->nullable()->after('required_by_date');
            $table->decimal('subtotal', 20, 6)->nullable()->after('currency');
            $table->decimal('freight_amount', 20, 6)->nullable()->after('subtotal');
            $table->decimal('other_charges', 20, 6)->nullable()->after('freight_amount');
            $table->decimal('discount_amount', 20, 6)->nullable()->after('other_charges');
            $table->decimal('total_amount', 20, 6)->nullable()->after('discount_amount');
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('total_amount');
            $table->string('incoterm_code', 12)->nullable()->after('payment_terms_days');
            $table->text('delivery_terms')->nullable()->after('incoterm_code');
            $table->text('notes')->nullable()->after('delivery_terms');
            $table->unsignedInteger('revision_number')->default(1)->after('record_version');
            $table->timestampTz('issued_at')->nullable()->after('revision_number');
            $table->uuid('issued_by')->nullable()->after('issued_at');
            $table->timestampTz('cancelled_at')->nullable()->after('issued_by');
            $table->uuid('cancelled_by')->nullable()->after('cancelled_at');
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');

            $table->unique(['company_id', 'plant_id', 'po_number'], 'purchase_orders_scope_number_unique');
            $table->unique(['id', 'company_id', 'plant_id'], 'purchase_orders_id_scope_unique');
            $table->index(
                ['company_id', 'plant_id', 'status', 'required_by_date'],
                'purchase_orders_scope_status_due_index'
            );
            $table->foreign(['rfq_id', 'company_id', 'plant_id'], 'purchase_orders_rfq_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('requests_for_quotation')->restrictOnDelete();
            $table->foreign(['requisition_id', 'company_id', 'plant_id'], 'purchase_orders_requisition_fk')
                ->references(['id', 'company_id', 'plant_id'])->on('requisitions')->restrictOnDelete();
            $table->foreign(['supplier_party_id', 'company_id'], 'purchase_orders_supplier_fk')
                ->references(['id', 'company_id'])->on('parties')->restrictOnDelete();
            $table->foreign('supplier_quote_id', 'purchase_orders_quote_fk')
                ->references('id')->on('supplier_quotes')->restrictOnDelete();
            $table->foreign('issued_by', 'purchase_orders_issuer_fk')
                ->references('id')->on('users')->restrictOnDelete();
            $table->foreign('cancelled_by', 'purchase_orders_canceller_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('purchase_order_id');
            $table->uuid('supplier_quote_id');
            $table->uuid('supplier_quote_line_id');
            $table->uuid('rfq_id');
            $table->uuid('rfq_line_id');
            $table->uuid('requisition_id');
            $table->uuid('requisition_line_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedSmallInteger('line_number');
            $table->uuid('item_id');
            $table->string('description');
            $table->decimal('ordered_quantity', 20, 6);
            $table->string('uom_code', 16);
            $table->decimal('unit_price', 20, 6);
            $table->decimal('line_total', 20, 6);
            $table->text('notes')->nullable();
            $table->timestampsTz();

            $table->unique(['purchase_order_id', 'line_number'], 'purchase_order_lines_number_unique');
            $table->unique(['purchase_order_id', 'rfq_line_id'], 'purchase_order_lines_rfq_line_unique');
            $table->foreign(
                ['purchase_order_id', 'company_id', 'plant_id'],
                'purchase_order_lines_order_fk'
            )->references(
                ['id', 'company_id', 'plant_id']
            )->on('purchase_orders')->cascadeOnDelete();
            $table->foreign(
                ['supplier_quote_line_id', 'supplier_quote_id', 'rfq_id', 'company_id', 'plant_id'],
                'purchase_order_lines_quote_line_fk'
            )->references(
                ['id', 'supplier_quote_id', 'rfq_id', 'company_id', 'plant_id']
            )->on('supplier_quote_lines')->restrictOnDelete();
            $table->foreign(
                ['rfq_line_id', 'rfq_id', 'company_id', 'plant_id'],
                'purchase_order_lines_rfq_line_fk'
            )->references(
                ['id', 'rfq_id', 'company_id', 'plant_id']
            )->on('rfq_lines')->restrictOnDelete();
            $table->foreign(
                ['requisition_line_id', 'requisition_id', 'company_id', 'plant_id'],
                'purchase_order_lines_requisition_line_fk'
            )->references(
                ['id', 'requisition_id', 'company_id', 'plant_id']
            )->on('requisition_lines')->restrictOnDelete();
            $table->foreign(['item_id', 'company_id'], 'purchase_order_lines_item_fk')
                ->references(['id', 'company_id'])->on('items')->restrictOnDelete();
            $table->foreign('uom_code', 'purchase_order_lines_uom_fk')
                ->references('code')->on('uoms')->restrictOnDelete();
        });

        Schema::create('purchase_order_revisions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('purchase_order_id');
            $table->uuid('company_id');
            $table->uuid('plant_id');
            $table->unsignedInteger('revision_number');
            $table->text('reason');
            $table->json('snapshot');
            $table->uuid('created_by');
            $table->timestampTz('created_at');

            $table->unique(
                ['purchase_order_id', 'revision_number'],
                'purchase_order_revisions_number_unique'
            );
            $table->foreign(
                ['purchase_order_id', 'company_id', 'plant_id'],
                'purchase_order_revisions_order_fk'
            )->references(
                ['id', 'company_id', 'plant_id']
            )->on('purchase_orders')->cascadeOnDelete();
            $table->foreign('created_by', 'purchase_order_revisions_creator_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->foreignKeyConstraints() as [$table, $name, $definition]) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition}");
        }
        foreach ($this->checkConstraints() as [$table, $name, $definition]) {
            DB::statement("ALTER TABLE {$table} ADD CONSTRAINT {$name} {$definition}");
        }
        foreach ($this->partialIndexes() as $statement) {
            DB::statement($statement);
        }
        $this->createConstraintTriggers();
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            $this->dropConstraintTriggers();
            foreach ($this->partialIndexNames() as $name) {
                DB::statement("DROP INDEX IF EXISTS {$name}");
            }
            foreach (array_reverse($this->checkConstraints()) as [$table, $name]) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            }
            foreach (array_reverse($this->foreignKeyConstraints()) as [$table, $name]) {
                DB::statement("ALTER TABLE {$table} DROP CONSTRAINT IF EXISTS {$name}");
            }
        }

        Schema::dropIfExists('purchase_order_revisions');
        Schema::dropIfExists('purchase_order_lines');
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropForeign('purchase_orders_canceller_fk');
            $table->dropForeign('purchase_orders_issuer_fk');
            $table->dropForeign('purchase_orders_quote_fk');
            $table->dropForeign('purchase_orders_supplier_fk');
            $table->dropForeign('purchase_orders_requisition_fk');
            $table->dropForeign('purchase_orders_rfq_fk');
            $table->dropIndex('purchase_orders_scope_status_due_index');
            $table->dropUnique('purchase_orders_id_scope_unique');
            $table->dropUnique('purchase_orders_scope_number_unique');
            $table->dropColumn([
                'po_number', 'rfq_id', 'requisition_id', 'supplier_party_id', 'supplier_quote_id',
                'order_date', 'required_by_date', 'currency', 'subtotal', 'freight_amount',
                'other_charges', 'discount_amount', 'total_amount', 'payment_terms_days',
                'incoterm_code', 'delivery_terms', 'notes', 'revision_number', 'issued_at',
                'issued_by', 'cancelled_at', 'cancelled_by', 'cancellation_reason',
            ]);
        });

        if (DB::getDriverName() === 'pgsql') {
            DB::statement(
                'ALTER TABLE requests_for_quotation DROP CONSTRAINT IF EXISTS rfqs_awarded_quote_fk'
            );
        }
        Schema::dropIfExists('supplier_quote_lines');
        Schema::dropIfExists('supplier_quotes');
        Schema::dropIfExists('rfq_suppliers');
        Schema::dropIfExists('rfq_lines');
        Schema::dropIfExists('requests_for_quotation');
        Schema::table('requisition_lines', function (Blueprint $table) {
            $table->dropUnique('requisition_lines_id_source_unique');
        });
    }

    private function checkConstraints(): array
    {
        return [
            ['requests_for_quotation', 'rfqs_status_check', "CHECK (status IN ('DRAFT', 'ISSUED', 'AWARDED', 'CANCELLED'))"],
            ['requests_for_quotation', 'rfqs_values_check', "CHECK (currency = 'INR' AND estimated_total_snapshot >= 0 AND record_version >= 1 AND response_due_date <= required_by_date)"],
            ['requests_for_quotation', 'rfqs_workflow_check', <<<'SQL'
CHECK (
    (issued_at IS NULL) = (issued_by IS NULL)
    AND (awarded_at IS NULL) = (awarded_by IS NULL)
    AND (cancelled_at IS NULL) = (cancelled_by IS NULL)
    AND (
        (status = 'DRAFT' AND issued_at IS NULL AND awarded_at IS NULL AND awarded_supplier_id IS NULL AND awarded_quote_id IS NULL AND award_reason IS NULL AND cancelled_at IS NULL AND cancellation_reason IS NULL)
        OR (status = 'ISSUED' AND issued_at IS NOT NULL AND awarded_at IS NULL AND awarded_supplier_id IS NULL AND awarded_quote_id IS NULL AND award_reason IS NULL AND cancelled_at IS NULL AND cancellation_reason IS NULL)
        OR (status = 'AWARDED' AND issued_at IS NOT NULL AND awarded_at IS NOT NULL AND awarded_supplier_id IS NOT NULL AND awarded_quote_id IS NOT NULL AND cancelled_at IS NULL AND cancellation_reason IS NULL)
        OR (status = 'CANCELLED' AND awarded_at IS NULL AND awarded_supplier_id IS NULL AND awarded_quote_id IS NULL AND award_reason IS NULL AND cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL AND btrim(cancellation_reason) <> '')
    )
)
SQL],
            ['rfq_lines', 'rfq_lines_values_check', 'CHECK (line_number >= 1 AND quantity > 0)'],
            ['rfq_suppliers', 'rfq_suppliers_status_check', "CHECK (status IN ('SELECTED', 'INVITED', 'RESPONDED', 'AWARDED', 'NOT_SELECTED'))"],
            ['rfq_suppliers', 'rfq_suppliers_timeline_check', "CHECK ((status = 'SELECTED' AND invited_at IS NULL AND responded_at IS NULL) OR (status = 'INVITED' AND invited_at IS NOT NULL AND responded_at IS NULL) OR (status IN ('RESPONDED', 'AWARDED', 'NOT_SELECTED') AND invited_at IS NOT NULL))"],
            ['supplier_quotes', 'supplier_quotes_status_check', "CHECK (status = 'SUBMITTED')"],
            ['supplier_quotes', 'supplier_quotes_values_check', "CHECK (currency = 'INR' AND record_version >= 1 AND payment_terms_days <= 3650 AND valid_until >= quote_date AND subtotal >= 0 AND freight_amount >= 0 AND other_charges >= 0 AND discount_amount >= 0 AND discount_amount <= subtotal + freight_amount + other_charges AND total_amount = subtotal + freight_amount + other_charges - discount_amount)"],
            ['supplier_quote_lines', 'supplier_quote_lines_values_check', 'CHECK (line_number >= 1 AND quantity > 0 AND unit_price >= 0 AND line_total = round(quantity * unit_price, 6))'],
            ['purchase_orders', 'purchase_orders_live_values_check', <<<'SQL'
CHECK (
    po_number IS NULL
    OR (
        rfq_id IS NOT NULL AND requisition_id IS NOT NULL AND supplier_party_id IS NOT NULL
        AND supplier_quote_id IS NOT NULL AND order_date IS NOT NULL AND required_by_date IS NOT NULL
        AND currency = 'INR' AND subtotal >= 0 AND freight_amount >= 0 AND other_charges >= 0
        AND discount_amount >= 0 AND discount_amount <= subtotal + freight_amount + other_charges
        AND total_amount = subtotal + freight_amount + other_charges - discount_amount
        AND payment_terms_days BETWEEN 0 AND 3650 AND revision_number >= 1
        AND record_version >= 1 AND created_by IS NOT NULL
    )
)
SQL],
            ['purchase_orders', 'purchase_orders_status_check', "CHECK (po_number IS NULL OR status IN ('DRAFT', 'ISSUED', 'CANCELLED'))"],
            ['purchase_orders', 'purchase_orders_workflow_check', <<<'SQL'
CHECK (
    po_number IS NULL
    OR (
        (issued_at IS NULL) = (issued_by IS NULL)
        AND (cancelled_at IS NULL) = (cancelled_by IS NULL)
        AND (
            (status = 'DRAFT' AND issued_at IS NULL AND cancelled_at IS NULL AND cancellation_reason IS NULL)
            OR (status = 'ISSUED' AND issued_at IS NOT NULL AND cancelled_at IS NULL AND cancellation_reason IS NULL)
            OR (status = 'CANCELLED' AND cancelled_at IS NOT NULL AND cancellation_reason IS NOT NULL AND btrim(cancellation_reason) <> '')
        )
    )
)
SQL],
            ['purchase_order_lines', 'purchase_order_lines_values_check', 'CHECK (line_number >= 1 AND ordered_quantity > 0 AND unit_price >= 0 AND line_total = round(ordered_quantity * unit_price, 6))'],
            ['purchase_order_revisions', 'purchase_order_revisions_values_check', 'CHECK (revision_number >= 1 AND btrim(reason) <> \'\')'],
        ];
    }

    private function foreignKeyConstraints(): array
    {
        return [
            ['rfq_lines', 'rfq_lines_item_uom_fk', 'FOREIGN KEY (item_id, company_id, uom_code) REFERENCES items(id, company_id, base_uom) ON DELETE RESTRICT'],
            ['purchase_order_lines', 'purchase_order_lines_item_uom_fk', 'FOREIGN KEY (item_id, company_id, uom_code) REFERENCES items(id, company_id, base_uom) ON DELETE RESTRICT'],
        ];
    }

    private function partialIndexes(): array
    {
        return [
            "CREATE UNIQUE INDEX rfqs_active_requisition_unique ON requests_for_quotation (requisition_id) WHERE status <> 'CANCELLED'",
            "CREATE UNIQUE INDEX purchase_orders_active_rfq_unique ON purchase_orders (rfq_id) WHERE po_number IS NOT NULL AND status <> 'CANCELLED'",
        ];
    }

    private function partialIndexNames(): array
    {
        return ['purchase_orders_active_rfq_unique', 'rfqs_active_requisition_unique'];
    }

    private function createConstraintTriggers(): void
    {
        DB::unprepared(<<<'SQL'
CREATE OR REPLACE FUNCTION qtfoods_check_rfq_source() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    source requisitions%ROWTYPE;
BEGIN
    SELECT * INTO source FROM requisitions WHERE id = NEW.requisition_id;
    IF NOT FOUND OR source.company_id <> NEW.company_id OR source.plant_id <> NEW.plant_id
       OR source.status <> 'APPROVED' OR source.currency <> NEW.currency
       OR source.estimated_total <> NEW.estimated_total_snapshot
       OR source.required_by_date <> NEW.required_by_date THEN
        RAISE EXCEPTION 'RFQ must snapshot the same approved requisition in scope'
            USING ERRCODE = '23514', CONSTRAINT = 'rfqs_requisition_identity_guard';
    END IF;
    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER rfqs_requisition_identity_guard
AFTER INSERT OR UPDATE OF requisition_id, company_id, plant_id, currency, estimated_total_snapshot, required_by_date
ON requests_for_quotation DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION qtfoods_check_rfq_source();

CREATE OR REPLACE FUNCTION qtfoods_guard_sourced_requisition() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
    IF EXISTS (
        SELECT 1 FROM requests_for_quotation rfq
        WHERE rfq.requisition_id = NEW.id AND rfq.status <> 'CANCELLED'
    ) AND (
        NEW.status <> 'APPROVED' OR NEW.currency IS DISTINCT FROM OLD.currency
        OR NEW.estimated_total IS DISTINCT FROM OLD.estimated_total
        OR NEW.required_by_date IS DISTINCT FROM OLD.required_by_date
    ) THEN
        RAISE EXCEPTION 'an actively sourced requisition must remain approved and unchanged'
            USING ERRCODE = '23514', CONSTRAINT = 'requisitions_active_rfq_guard';
    END IF;
    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER requisitions_active_rfq_guard
AFTER UPDATE OF status, currency, estimated_total, required_by_date ON requisitions
DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION qtfoods_guard_sourced_requisition();

CREATE OR REPLACE FUNCTION qtfoods_check_rfq_line_source() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    header requests_for_quotation%ROWTYPE;
    source requisition_lines%ROWTYPE;
BEGIN
    SELECT * INTO header FROM requests_for_quotation WHERE id = NEW.rfq_id;
    SELECT * INTO source FROM requisition_lines WHERE id = NEW.requisition_line_id;
    IF NOT FOUND OR source.requisition_id <> header.requisition_id
       OR source.company_id <> NEW.company_id OR source.plant_id <> NEW.plant_id
       OR source.item_id <> NEW.item_id OR source.uom_code <> NEW.uom_code
       OR source.quantity <> NEW.quantity OR source.description <> NEW.description
       OR source.line_number <> NEW.line_number THEN
        RAISE EXCEPTION 'RFQ line must be an exact requisition-line snapshot'
            USING ERRCODE = '23514', CONSTRAINT = 'rfq_lines_source_identity_guard';
    END IF;
    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER rfq_lines_source_identity_guard
AFTER INSERT OR UPDATE ON rfq_lines DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION qtfoods_check_rfq_line_source();

CREATE OR REPLACE FUNCTION qtfoods_check_quote_line_source() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    source rfq_lines%ROWTYPE;
BEGIN
    SELECT * INTO source FROM rfq_lines WHERE id = NEW.rfq_line_id;
    IF NOT FOUND OR source.rfq_id <> NEW.rfq_id OR source.company_id <> NEW.company_id
       OR source.plant_id <> NEW.plant_id OR source.line_number <> NEW.line_number
       OR source.quantity <> NEW.quantity OR source.uom_code <> NEW.uom_code THEN
        RAISE EXCEPTION 'supplier quote line must price the same RFQ line and quantity'
            USING ERRCODE = '23514', CONSTRAINT = 'supplier_quote_lines_source_guard';
    END IF;
    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER supplier_quote_lines_source_guard
AFTER INSERT OR UPDATE ON supplier_quote_lines DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION qtfoods_check_quote_line_source();

CREATE OR REPLACE FUNCTION qtfoods_check_quote_total() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    target_id uuid;
    header_subtotal numeric(20, 6);
    line_subtotal numeric(20, 6);
BEGIN
    IF TG_TABLE_NAME = 'supplier_quotes' THEN
        target_id := COALESCE(NEW.id, OLD.id);
    ELSE
        target_id := COALESCE(NEW.supplier_quote_id, OLD.supplier_quote_id);
    END IF;
    SELECT subtotal INTO header_subtotal FROM supplier_quotes WHERE id = target_id;
    IF FOUND THEN
        SELECT COALESCE(SUM(line_total), 0) INTO line_subtotal
        FROM supplier_quote_lines WHERE supplier_quote_id = target_id;
        IF header_subtotal IS DISTINCT FROM line_subtotal THEN
            RAISE EXCEPTION 'supplier quote subtotal must equal the sum of its lines'
                USING ERRCODE = '23514', CONSTRAINT = 'supplier_quotes_line_total_guard';
        END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER supplier_quotes_line_total_guard
AFTER INSERT OR UPDATE OF subtotal ON supplier_quotes
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION qtfoods_check_quote_total();

CREATE CONSTRAINT TRIGGER supplier_quote_lines_total_guard
AFTER INSERT OR UPDATE OR DELETE ON supplier_quote_lines
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION qtfoods_check_quote_total();

CREATE OR REPLACE FUNCTION qtfoods_check_rfq_award() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    selected supplier_quotes%ROWTYPE;
BEGIN
    IF NEW.status <> 'AWARDED' THEN RETURN NEW; END IF;
    SELECT * INTO selected FROM supplier_quotes WHERE id = NEW.awarded_quote_id;
    IF NOT FOUND OR selected.rfq_id <> NEW.id OR selected.supplier_party_id <> NEW.awarded_supplier_id
       OR selected.company_id <> NEW.company_id OR selected.plant_id <> NEW.plant_id
       OR selected.status <> 'SUBMITTED' OR selected.total_amount > NEW.estimated_total_snapshot THEN
        RAISE EXCEPTION 'RFQ award must identify an eligible in-budget quote from an invited supplier'
            USING ERRCODE = '23514', CONSTRAINT = 'rfqs_award_identity_guard';
    END IF;
    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER rfqs_award_identity_guard
AFTER INSERT OR UPDATE OF status, awarded_quote_id, awarded_supplier_id ON requests_for_quotation
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION qtfoods_check_rfq_award();

CREATE OR REPLACE FUNCTION qtfoods_check_po_source() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    source requests_for_quotation%ROWTYPE;
    selected supplier_quotes%ROWTYPE;
BEGIN
    IF NEW.po_number IS NULL THEN RETURN NEW; END IF;
    SELECT * INTO source FROM requests_for_quotation WHERE id = NEW.rfq_id;
    SELECT * INTO selected FROM supplier_quotes WHERE id = NEW.supplier_quote_id;
    IF NOT FOUND OR source.status <> 'AWARDED' OR source.company_id <> NEW.company_id
       OR source.plant_id <> NEW.plant_id OR source.requisition_id <> NEW.requisition_id
       OR source.awarded_supplier_id <> NEW.supplier_party_id
       OR source.awarded_quote_id <> NEW.supplier_quote_id
       OR selected.rfq_id <> NEW.rfq_id OR selected.supplier_party_id <> NEW.supplier_party_id
       OR selected.currency <> NEW.currency OR NEW.total_amount > source.estimated_total_snapshot THEN
        RAISE EXCEPTION 'purchase order must preserve its awarded RFQ, quote, supplier, and authority ceiling'
            USING ERRCODE = '23514', CONSTRAINT = 'purchase_orders_source_identity_guard';
    END IF;
    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER purchase_orders_source_identity_guard
AFTER INSERT OR UPDATE ON purchase_orders DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION qtfoods_check_po_source();

CREATE OR REPLACE FUNCTION qtfoods_check_po_line_source() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    rfq_source rfq_lines%ROWTYPE;
    quote_source supplier_quote_lines%ROWTYPE;
BEGIN
    SELECT * INTO rfq_source FROM rfq_lines WHERE id = NEW.rfq_line_id;
    SELECT * INTO quote_source FROM supplier_quote_lines WHERE id = NEW.supplier_quote_line_id;
    IF NOT FOUND OR rfq_source.rfq_id <> NEW.rfq_id
       OR rfq_source.requisition_line_id <> NEW.requisition_line_id
       OR rfq_source.item_id <> NEW.item_id OR rfq_source.uom_code <> NEW.uom_code
       OR quote_source.supplier_quote_id <> NEW.supplier_quote_id
       OR quote_source.rfq_id <> NEW.rfq_id OR quote_source.rfq_line_id <> NEW.rfq_line_id
       OR NEW.ordered_quantity > rfq_source.quantity OR NEW.line_number <> rfq_source.line_number THEN
        RAISE EXCEPTION 'purchase order line must remain within its awarded source line'
            USING ERRCODE = '23514', CONSTRAINT = 'purchase_order_lines_source_guard';
    END IF;
    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER purchase_order_lines_source_guard
AFTER INSERT OR UPDATE ON purchase_order_lines DEFERRABLE INITIALLY DEFERRED
FOR EACH ROW EXECUTE FUNCTION qtfoods_check_po_line_source();

CREATE OR REPLACE FUNCTION qtfoods_check_po_total() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
    target_id uuid;
    header_subtotal numeric(20, 6);
    line_subtotal numeric(20, 6);
BEGIN
    IF TG_TABLE_NAME = 'purchase_orders' THEN
        target_id := COALESCE(NEW.id, OLD.id);
    ELSE
        target_id := COALESCE(NEW.purchase_order_id, OLD.purchase_order_id);
    END IF;
    SELECT subtotal INTO header_subtotal FROM purchase_orders WHERE id = target_id AND po_number IS NOT NULL;
    IF FOUND THEN
        SELECT COALESCE(SUM(line_total), 0) INTO line_subtotal
        FROM purchase_order_lines WHERE purchase_order_id = target_id;
        IF header_subtotal IS DISTINCT FROM line_subtotal THEN
            RAISE EXCEPTION 'purchase order subtotal must equal the sum of its lines'
                USING ERRCODE = '23514', CONSTRAINT = 'purchase_orders_line_total_guard';
        END IF;
    END IF;
    IF TG_OP = 'DELETE' THEN RETURN OLD; END IF;
    RETURN NEW;
END;
$$;

CREATE CONSTRAINT TRIGGER purchase_orders_line_total_guard
AFTER INSERT OR UPDATE OF subtotal ON purchase_orders
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION qtfoods_check_po_total();

CREATE CONSTRAINT TRIGGER purchase_order_lines_total_guard
AFTER INSERT OR UPDATE OR DELETE ON purchase_order_lines
DEFERRABLE INITIALLY DEFERRED FOR EACH ROW EXECUTE FUNCTION qtfoods_check_po_total();
SQL);
    }

    private function dropConstraintTriggers(): void
    {
        DB::unprepared(<<<'SQL'
DROP TRIGGER IF EXISTS purchase_order_lines_total_guard ON purchase_order_lines;
DROP TRIGGER IF EXISTS purchase_orders_line_total_guard ON purchase_orders;
DROP TRIGGER IF EXISTS purchase_order_lines_source_guard ON purchase_order_lines;
DROP TRIGGER IF EXISTS purchase_orders_source_identity_guard ON purchase_orders;
DROP TRIGGER IF EXISTS rfqs_award_identity_guard ON requests_for_quotation;
DROP TRIGGER IF EXISTS supplier_quote_lines_total_guard ON supplier_quote_lines;
DROP TRIGGER IF EXISTS supplier_quotes_line_total_guard ON supplier_quotes;
DROP TRIGGER IF EXISTS supplier_quote_lines_source_guard ON supplier_quote_lines;
DROP TRIGGER IF EXISTS rfq_lines_source_identity_guard ON rfq_lines;
DROP TRIGGER IF EXISTS requisitions_active_rfq_guard ON requisitions;
DROP TRIGGER IF EXISTS rfqs_requisition_identity_guard ON requests_for_quotation;
DROP FUNCTION IF EXISTS qtfoods_check_po_total();
DROP FUNCTION IF EXISTS qtfoods_check_po_line_source();
DROP FUNCTION IF EXISTS qtfoods_check_po_source();
DROP FUNCTION IF EXISTS qtfoods_check_rfq_award();
DROP FUNCTION IF EXISTS qtfoods_check_quote_total();
DROP FUNCTION IF EXISTS qtfoods_check_quote_line_source();
DROP FUNCTION IF EXISTS qtfoods_check_rfq_line_source();
DROP FUNCTION IF EXISTS qtfoods_guard_sourced_requisition();
DROP FUNCTION IF EXISTS qtfoods_check_rfq_source();
SQL);
    }
};

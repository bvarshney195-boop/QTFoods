<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class MasterTransactionRelationalIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const TRAINING_PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const FINANCE_PLANT_ID = '00000000-0000-4000-8000-000000000102';
    private const ACTOR_ID = '00000000-0000-4000-8000-000000000202';

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Relational catalog assertions require PostgreSQL.');
        }

        $this->seed();
    }

    public function test_every_relational_identifier_is_constrained_or_explicitly_polymorphic(): void
    {
        $unconstrained = collect(DB::select(<<<'SQL'
WITH id_columns AS (
    SELECT table_name, column_name
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND right(column_name, 3) IN ('_id', '_by')
), fk_columns AS (
    SELECT constraint_row.conrelid::regclass::text AS table_name, attribute.attname AS column_name
    FROM pg_constraint constraint_row
    CROSS JOIN LATERAL unnest(constraint_row.conkey) key_column(attnum)
    JOIN pg_attribute attribute
      ON attribute.attrelid = constraint_row.conrelid
     AND attribute.attnum = key_column.attnum
    WHERE constraint_row.contype = 'f'
      AND constraint_row.connamespace = 'public'::regnamespace
)
SELECT id_columns.table_name, id_columns.column_name
FROM id_columns
LEFT JOIN fk_columns USING (table_name, column_name)
WHERE fk_columns.column_name IS NULL
ORDER BY id_columns.table_name, id_columns.column_name
SQL))->map(fn (object $column): string => "{$column->table_name}.{$column->column_name}")->all();

        $this->assertSame([
            'approval_requests.entity_id',
            'audit_events.correlation_id',
            'audit_events.entity_id',
            'audit_events.request_id',
            'audit_events.span_id',
            'audit_events.trace_id',
            'loss_events.source_id',
            'outbox_delivery_attempts.acknowledgement_id',
            'outbox_delivery_attempts.worker_id',
            'outbox_events.acknowledgement_id',
            'outbox_events.aggregate_id',
            'outbox_events.correlation_id',
            'outbox_events.locked_by',
            'outbox_events.request_id',
            'outbox_events.span_id',
            'outbox_events.trace_id',
            'stock_movements.source_id',
            'work_items.source_id',
            'work_items.target_record_id',
        ], $unconstrained);

        $requiredConstraints = [
            'locations_parent_scope_fk',
            'role_assignments_party_scope_fk',
            'approval_requests_band_rule_fk',
            'items_catalog_identity_fk',
            'stock_positions_lot_item_fk',
            'inventory_operation_lines_type_fk',
            'shipment_lines_shipment_scope_fk',
            'unsold_return_cases_shipment_fk',
            'unsold_return_lines_shipment_line_fk',
            'unsold_finance_case_invoice_fk',
            'unsold_evidence_audit_fk',
            'requisitions_approval_fk',
            'requisition_lines_item_uom_fk',
            'rfqs_requisition_fk',
            'rfq_lines_requisition_line_fk',
            'rfq_lines_item_uom_fk',
            'supplier_quotes_invitation_fk',
            'supplier_quote_lines_rfq_line_fk',
            'purchase_orders_rfq_fk',
            'purchase_order_lines_quote_line_fk',
            'purchase_order_lines_item_uom_fk',
            'purchase_order_revisions_order_fk',
            'gate_entries_order_fk',
            'receipts_gate_entry_fk',
            'receipt_lines_order_line_fk',
            'receipt_lines_lot_fk',
            'receipt_lines_hold_position_fk',
            'quality_tasks_receipt_fk',
            'incoming_quality_lines_receipt_line_fk',
            'incoming_quality_lines_released_position_fk',
            'incoming_quality_lines_rejected_position_fk',
            'supplier_return_lines_quality_line_fk',
            'supplier_return_lines_position_fk',
            'invoices_purchase_order_fk',
            'payable_invoice_lines_order_line_fk',
            'payment_proposal_lines_invoice_fk',
            'supplier_payments_proposal_fk',
            'supplier_payment_allocations_invoice_fk',
            'payment_reconciliations_payment_fk',
            'demand_plan_lines_plan_fk',
            'mrp_runs_demand_plan_fk',
            'mrp_planned_orders_demand_line_fk',
            'mrp_material_requirements_order_fk',
            'production_schedules_run_fk',
            'production_schedule_lines_order_fk',
            'production_schedule_operations_line_fk',
            'production_schedule_capacities_schedule_fk',
            'production_material_reservations_requirement_fk',
            'production_material_reservations_stock_fk',
            'production_orders_schedule_line_fk',
            'production_order_materials_order_fk',
            'production_order_stages_order_fk',
            'stage_events_stage_fk',
            'production_material_issues_plan_res_fk',
            'production_material_issues_stock_res_fk',
            'production_output_events_order_fk',
            'lab_samples_order_fk',
            'lab_results_sample_fk',
            'quality_deviations_sample_fk',
            'food_safety_holds_order_fk',
            'packing_runs_artwork_fk',
            'fg_lots_packing_run_fk',
            'lot_genealogy_input_lot_fk',
            'lot_genealogy_output_lot_fk',
            'recall_case_lots_case_fk',
            'batch_costs_order_fk',
            'batch_cost_material_lines_cost_fk',
            'batch_cost_stage_lines_cost_fk',
            'sales_order_lines_cost_source_fk',
            'demand_plans_workflow_check',
            'mrp_runs_workflow_check',
            'production_schedules_workflow_check',
            'production_schedule_capacities_values_check',
            'production_orders_workflow_check',
            'production_order_stages_workflow_check',
            'production_output_events_values_check',
            'lab_samples_workflow_check',
            'packaging_artworks_workflow_check',
            'packing_runs_workflow_check',
            'lot_genealogy_edges_values_check',
            'recall_cases_values_check',
            'batch_costs_values_check',
            'sales_order_lines_cost_provenance_check',
            'incoming_quality_lines_values_check',
            'payable_invoice_lines_values_check',
            'supplier_payments_status_check',
        ];
        $actualConstraints = DB::table('pg_constraint')
            ->whereIn('conname', $requiredConstraints)
            ->pluck('conname')->sort()->values()->all();

        sort($requiredConstraints);
        $this->assertSame($requiredConstraints, $actualConstraints);

        $requiredTriggers = [
            'requisition_lines_total_guard',
            'requisitions_approval_identity_guard',
            'requisitions_line_total_guard',
            'rfqs_requisition_identity_guard',
            'requisitions_active_rfq_guard',
            'rfq_lines_source_identity_guard',
            'supplier_quote_lines_source_guard',
            'supplier_quotes_line_total_guard',
            'supplier_quote_lines_total_guard',
            'rfqs_award_identity_guard',
            'purchase_orders_source_identity_guard',
            'purchase_order_lines_source_guard',
            'purchase_orders_line_total_guard',
            'purchase_order_lines_total_guard',
        ];
        $actualTriggers = DB::table('pg_trigger')
            ->whereIn('tgname', $requiredTriggers)
            ->where('tgisinternal', false)
            ->pluck('tgname')->sort()->values()->all();

        sort($requiredTriggers);
        $this->assertSame($requiredTriggers, $actualTriggers);
    }

    public function test_foundation_scope_and_single_active_record_rules_are_database_enforced(): void
    {
        $this->assertDatabaseRejects(function (): void {
            DB::table('locations')
                ->where('id', '00000000-0000-4000-8000-000000000801')
                ->update(['parent_location_id' => '00000000-0000-4000-8000-000000000802']);
        }, 'locations_parent_scope_fk');

        $assignment = (array) DB::table('role_assignments')
            ->where('company_id', self::COMPANY_ID)
            ->where('plant_id', self::TRAINING_PLANT_ID)
            ->where('is_active', true)
            ->firstOrFail();
        $assignment['id'] = (string) Str::uuid();
        $this->assertDatabaseRejects(
            fn () => DB::table('role_assignments')->insert($assignment),
            'role_assignments_active_unique',
        );

        $this->assertDatabaseRejects(function (): void {
            DB::table('requisitions')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => (string) Str::uuid(),
                'plant_id' => self::TRAINING_PLANT_ID,
                'requisition_number' => 'INVALID-SCOPE-'.Str::upper(Str::random(8)),
                'status' => 'DRAFT',
                'record_version' => 1,
                'created_by' => self::ACTOR_ID,
                'requested_by' => self::ACTOR_ID,
                'department' => 'Production',
                'purpose' => 'Invalid company scope guard test.',
                'requested_date' => now()->toDateString(),
                'required_by_date' => now()->addDay()->toDateString(),
                'currency' => 'INR',
                'estimated_total' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 'requisitions_company_fk');
    }

    public function test_master_identity_dimensions_and_primary_uniqueness_are_database_enforced(): void
    {
        $primaryAddress = (array) DB::table('party_addresses')->where('is_primary', true)->firstOrFail();
        $primaryAddress['id'] = (string) Str::uuid();
        $primaryAddress['label'] = 'Duplicate primary';
        $this->assertDatabaseRejects(
            fn () => DB::table('party_addresses')->insert($primaryAddress),
            'party_addresses_primary_type_unique',
        );

        $sku = (array) DB::table('items')->firstOrFail();
        $sku['id'] = (string) Str::uuid();
        $sku['code'] = 'BAD-IDENTITY-'.Str::upper(Str::random(8));
        $sku['barcode'] = null;
        $sku['base_uom'] = $sku['base_uom'] === 'KG' ? 'EA' : 'KG';
        $this->assertDatabaseRejects(
            fn () => DB::table('items')->insert($sku),
            'items_catalog_identity_fk',
        );

        $position = DB::table('stock_positions')->firstOrFail();
        $foreignLot = DB::table('lots')->where('item_id', '<>', $position->item_id)->firstOrFail();
        $this->assertDatabaseRejects(function () use ($position, $foreignLot): void {
            DB::table('stock_positions')->where('id', $position->id)->update([
                'lot_id' => $foreignLot->id,
            ]);
        }, 'stock_positions_lot_item_fk');

        $companyOwned = DB::table('stock_positions')->whereNull('owner_party_id')->firstOrFail();
        $partyId = DB::table('parties')->where('company_id', $companyOwned->company_id)->value('id');
        $this->assertDatabaseRejects(function () use ($companyOwned, $partyId): void {
            DB::table('stock_positions')->where('id', $companyOwned->id)->update([
                'owner_party_id' => $partyId,
            ]);
        }, 'stock position owner_party_id must match its inventory owner');
    }

    public function test_transaction_scope_quantities_and_type_specific_shapes_are_database_enforced(): void
    {
        $position = DB::table('stock_positions')
            ->where('company_id', self::COMPANY_ID)
            ->where('plant_id', self::TRAINING_PLANT_ID)
            ->firstOrFail();
        $operationId = (string) Str::uuid();
        DB::table('inventory_operations')->insert([
            'id' => $operationId,
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::TRAINING_PLANT_ID,
            'operation_number' => 'DB-GUARD-'.Str::upper(Str::random(8)),
            'operation_type' => 'ISSUE',
            'status' => 'DRAFT',
            'reason_code' => 'RELATIONAL_TEST',
            'record_version' => 1,
            'created_by' => self::ACTOR_ID,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertDatabaseRejects(function () use ($operationId, $position): void {
            DB::table('inventory_operation_lines')->insert([
                'id' => (string) Str::uuid(),
                'operation_id' => $operationId,
                'company_id' => self::COMPANY_ID,
                'plant_id' => self::TRAINING_PLANT_ID,
                'operation_type' => 'ISSUE',
                'sequence_no' => 1,
                'source_position_id' => null,
                'target_position_id' => $position->id,
                'quantity_base' => 1,
                'uom_code' => $position->uom_code,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 'inventory_operation_lines_shape_check');

        $shipmentLine = DB::table('shipment_lines')->firstOrFail();
        $this->assertDatabaseRejects(function () use ($shipmentLine): void {
            DB::table('shipment_lines')->where('id', $shipmentLine->id)->update([
                'returned_quantity' => DB::raw('shipped_quantity + 1'),
            ]);
        }, 'shipment_lines_quantity_check');

        $invoice = DB::table('sales_invoice_financials')->firstOrFail();
        $this->assertDatabaseRejects(function () use ($invoice): void {
            DB::table('sales_invoice_financials')->where('invoice_id', $invoice->invoice_id)->update([
                'gross_amount' => DB::raw('gross_amount + 1'),
            ]);
        }, 'sales_invoice_financials_amounts_check');

        $shipment = DB::table('shipments')->whereNotNull('party_id')->firstOrFail();
        $otherPartyId = DB::table('parties')
            ->where('company_id', $shipment->company_id)
            ->where('id', '<>', $shipment->party_id)
            ->value('id');
        $this->assertDatabaseRejects(function () use ($shipment, $otherPartyId): void {
            DB::table('unsold_return_cases')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $shipment->company_id,
                'plant_id' => $shipment->plant_id,
                'party_id' => $otherPartyId,
                'sales_order_id' => null,
                'shipment_id' => $shipment->id,
                'invoice_id' => null,
                'status' => 'REQUESTED',
                'reason_code' => 'RELATIONAL_TEST',
                'maker_id' => self::ACTOR_ID,
                'record_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 'unsold_return_cases_shipment_fk');

        $item = DB::table('items')->where('company_id', self::COMPANY_ID)->firstOrFail();
        $wrongUom = DB::table('uoms')->where('code', '<>', $item->base_uom)->value('code');
        $requisitionId = (string) Str::uuid();
        $this->assertDatabaseRejects(function () use ($item, $wrongUom, $requisitionId): void {
            DB::table('requisitions')->insert([
                'id' => $requisitionId,
                'company_id' => self::COMPANY_ID,
                'plant_id' => self::TRAINING_PLANT_ID,
                'requisition_number' => 'DB-REQ-'.Str::upper(Str::random(8)),
                'status' => 'DRAFT',
                'record_version' => 1,
                'created_by' => self::ACTOR_ID,
                'requested_by' => self::ACTOR_ID,
                'department' => 'Production',
                'purpose' => 'Relational item and UOM guard test.',
                'requested_date' => now()->toDateString(),
                'required_by_date' => now()->addDay()->toDateString(),
                'currency' => 'INR',
                'estimated_total' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('requisition_lines')->insert([
                'id' => (string) Str::uuid(),
                'requisition_id' => $requisitionId,
                'company_id' => self::COMPANY_ID,
                'plant_id' => self::TRAINING_PLANT_ID,
                'line_number' => 1,
                'item_id' => $item->id,
                'description' => $item->name,
                'quantity' => 1,
                'uom_code' => $wrongUom,
                'estimated_unit_cost' => 1,
                'estimated_line_total' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }, 'requisition_lines_item_uom_fk');
    }

    private function assertDatabaseRejects(callable $operation, string $constraint): void
    {
        $savepoint = 'relational_guard_'.Str::lower(Str::random(8));
        DB::statement("SAVEPOINT {$savepoint}");

        try {
            $operation();
            $this->fail("Expected database constraint {$constraint} to reject the write.");
        } catch (QueryException $exception) {
            DB::statement("ROLLBACK TO SAVEPOINT {$savepoint}");
            $this->assertStringContainsString($constraint, $exception->getMessage());
        } finally {
            DB::statement("RELEASE SAVEPOINT {$savepoint}");
        }
    }
}

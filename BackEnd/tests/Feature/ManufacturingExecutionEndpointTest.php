<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ManufacturingExecutionEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const OTHER_PLANT_ID = '00000000-0000-4000-8000-000000000102';
    private const OPERATIONS_ID = '00000000-0000-4000-8000-000000000202';
    private const ADMIN_ID = '00000000-0000-4000-8000-000000000204';
    private const SALES_ID = '00000000-0000-4000-8000-000000000201';
    private const CUSTOMER_ID = '00000000-0000-4000-8000-000000000501';
    private const CONTRACT_ID = '00000000-0000-4000-8000-000000002501';
    private const FINISHED_SKU_ID = '00000000-0000-4000-8000-000000000601';
    private const RAW_LOT_ID = '00000000-0000-4000-8000-000000000703';
    private const RAW_POSITION_ID = '00000000-0000-4000-8000-000000001213';
    private const RAW_QUARANTINE_POSITION_ID = '00000000-0000-4000-8000-000000001216';
    private const RAW_REUSABLE_POSITION_ID = '00000000-0000-4000-8000-000000001217';
    private const FINISHED_LOCATION_ID = '00000000-0000-4000-8000-000000000803';
    private const SPECIFICATION_ID = '00000000-0000-4000-8000-000000000931';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->signIn(self::OPERATIONS_ID);
    }

    public function test_complete_production_quality_packing_trace_recall_and_cost_chain(): void
    {
        $lineId = $this->releasedScheduleLine('P1-FULL');
        $created = $this->command()->postJson('/api/v1/manufacturing/orders', [
            'order_number' => 'PRO-P1-FULL', 'batch_number' => 'BATCH-P1-FULL',
            'production_schedule_line_id' => $lineId, 'shift_code' => 'SHIFT-A',
            'notes' => 'P1 integrated production batch.',
        ])->assertCreated()->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.record_version', 1);
        $orderId = (string) $created->json('data.id');
        $this->getJson('/api/v1/manufacturing/orders/'.$orderId)->assertOk()
            ->assertJsonPath('data.output_sku.code', 'SKU-APPLE-100')
            ->assertJsonCount(1, 'data.materials')->assertJsonCount(3, 'data.stages');

        $releaseKey = (string) Str::uuid();
        $released = $this->withHeaders(['Idempotency-Key' => $releaseKey, 'If-Match' => '1'])
            ->postJson('/api/v1/manufacturing/orders/'.$orderId.'/release')
            ->assertOk()->assertJsonPath('data.status', 'RELEASED')->assertJsonPath('data.record_version', 2);
        $this->withHeaders(['Idempotency-Key' => $releaseKey, 'If-Match' => '1'])
            ->postJson('/api/v1/manufacturing/orders/'.$orderId.'/release')->assertOk()->assertExactJson($released->json());

        $issued = $this->withHeaders($this->headers(2))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/issue-materials')
            ->assertOk()->assertJsonPath('data.status', 'IN_PROCESS')->assertJsonPath('data.record_version', 3)
            ->assertJsonPath('data.issue_count', 1)->assertJsonPath('data.issued_quantity', '10.304569');
        $this->assertSame('114.695431', $this->decimal(DB::table('stock_positions')->where('id', self::RAW_POSITION_ID)->value('quantity_base')));
        $this->assertSame('20.000000', $this->decimal(DB::table('stock_positions')->where('id', self::RAW_POSITION_ID)->value('reserved_quantity_base')));
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'PRODUCTION_ISSUE', 'source_id' => $orderId]);

        $stages = $this->getJson('/api/v1/manufacturing/orders/'.$orderId)->assertOk()->json('data.stages');
        foreach ($stages as $index => $stage) {
            $this->withHeaders($this->headers(1))->postJson('/api/v1/manufacturing/stages/'.$stage['id'].'/start')
                ->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS')->assertJsonPath('data.record_version', 2);
            $this->withHeaders($this->headers(2))->postJson('/api/v1/manufacturing/stages/'.$stage['id'].'/complete', [
                'actual_minutes' => (string) ((float) $stage['planned_minutes'] + $index + 1),
                'notes' => 'Stage completed against the approved work instruction.',
            ])->assertOk()->assertJsonPath('data.status', 'COMPLETED')->assertJsonPath('data.record_version', 3);
        }
        $this->assertDatabaseCount('stage_events', 6);

        $this->withHeaders($this->headers(3))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/outputs', [
            'event_type' => 'GOOD', 'quantity' => '94', 'notes' => 'First-pass good output.',
        ])->assertCreated()->assertJsonPath('data.good_quantity', '94.000000')->assertJsonPath('data.record_version', 4);
        $this->withHeaders($this->headers(4))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/outputs', [
            'event_type' => 'LOSS', 'quantity' => '3', 'reason_code' => 'BAKE_LOSS', 'notes' => 'Measured process loss.',
        ])->assertCreated()->assertJsonPath('data.loss_quantity', '3.000000')->assertJsonPath('data.record_version', 5);
        $rework = $this->withHeaders($this->headers(5))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/outputs', [
            'event_type' => 'REWORK', 'quantity' => '2', 'reason_code' => 'SEAL_REWORK', 'notes' => 'Re-seal before release.',
        ])->assertCreated()->assertJsonPath('data.open_rework_count', 1)->assertJsonPath('data.record_version', 6);
        $eventId = (string) $rework->json('data.output_event_id');
        $this->command()->postJson('/api/v1/manufacturing/output-events/'.$eventId.'/resolve', [
            'disposition' => 'RECOVERED', 'notes' => 'Re-seal verification passed.',
        ])->assertOk()->assertJsonPath('data.good_quantity', '96.000000')->assertJsonPath('data.record_version', 7);
        $scrapRework = $this->withHeaders($this->headers(7))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/outputs', [
            'event_type' => 'REWORK', 'quantity' => '1', 'reason_code' => 'SEAL_REWORK', 'notes' => 'Damaged seal held for disposition.',
        ])->assertCreated()->assertJsonPath('data.open_rework_count', 1)->assertJsonPath('data.record_version', 8);
        $this->command()->postJson('/api/v1/manufacturing/output-events/'.$scrapRework->json('data.output_event_id').'/resolve', [
            'disposition' => 'SCRAPPED', 'notes' => 'Package could not be safely recovered.',
        ])->assertOk()->assertJsonPath('data.loss_quantity', '4.000000')->assertJsonPath('data.record_version', 9);
        $this->withHeaders($this->headers(9))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/complete')
            ->assertOk()->assertJsonPath('data.status', 'COMPLETED')->assertJsonPath('data.good_quantity', '96.000000')
            ->assertJsonPath('data.loss_quantity', '4.000000')->assertJsonPath('data.record_version', 10);
        $this->getJson('/api/v1/manufacturing/orders/'.$orderId)->assertOk()
            ->assertJsonPath('data.first_pass_good_quantity', '94.000000')
            ->assertJsonPath('data.recovered_quantity', '2.000000')
            ->assertJsonPath('data.final_good_quantity', '96.000000')
            ->assertJsonPath('data.output_variance', '-4.000000')
            ->assertJsonPath('data.output_variance_percent', '-4.000000')
            ->assertJsonPath('data.scrapped_rework_quantity', '1.000000')
            ->assertJsonPath('data.first_pass_yield_percent', '94.0000')
            ->assertJsonPath('data.final_yield_percent', '96.0000')
            ->assertJsonPath('data.yield_reconciliation.planned_quantity', '100.000000')
            ->assertJsonPath('data.yield_reconciliation.reconciled_quantity', '100.000000')
            ->assertJsonPath('data.yield_reconciliation.is_reconciled', true);

        $sample = $this->command()->postJson('/api/v1/quality/lab-samples', [
            'sample_number' => 'LAB-P1-FULL-01', 'production_order_id' => $orderId,
            'specification_id' => self::SPECIFICATION_ID, 'sampled_at' => now()->toISOString(),
            'notes' => 'Finished batch release sample.',
        ])->assertCreated()->assertJsonPath('data.result_count', 1);
        $sampleId = (string) $sample->json('data.id');
        $sampleDetail = $this->getJson('/api/v1/quality/lab-samples/'.$sampleId)->assertOk()
            ->assertJsonPath('data.results.0.code', 'NET-WEIGHT')->json('data');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/quality/lab-samples/'.$sampleId.'/complete', [
            'results' => [['result_id' => $sampleDetail['results'][0]['id'], 'numeric_value' => '100.2', 'notes' => 'Within specification.']],
        ])->assertOk()->assertJsonPath('data.status', 'PASSED')->assertJsonPath('data.failure_count', 0);

        $hold = $this->withHeaders($this->headers(10))->postJson('/api/v1/quality/food-safety-holds', [
            'hold_number' => 'HOLD-P1-FULL-01', 'production_order_id' => $orderId,
            'hazard_type' => 'ALLERGEN', 'reason' => 'Allergen clean-down evidence awaiting verification.',
        ])->assertCreated()->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.order_record_version', 11);
        $holdId = (string) $hold->json('data.id');
        $this->withHeaders($this->headers(11))->postJson('/api/v1/quality/production-orders/'.$orderId.'/release')
            ->assertUnprocessable()->assertJsonPath('error.fields.holds.0', 'Release every active food-safety hold before batch release.');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/quality/food-safety-holds/'.$holdId.'/release', [
            'disposition' => 'ACCEPTED',
            'root_cause' => 'Cleaning record was awaiting independent verification.',
            'corrective_action' => 'QA verified the signed allergen clean-down checklist.',
        ])->assertOk()->assertJsonPath('data.status', 'RELEASED');
        $order = $this->getJson('/api/v1/manufacturing/orders/'.$orderId)->assertOk()->json('data');
        $this->withHeaders($this->headers($order['record_version']))
            ->postJson('/api/v1/quality/production-orders/'.$orderId.'/release')
            ->assertOk()->assertJsonPath('data.quality_status', 'RELEASED');

        $artwork = $this->command()->postJson('/api/v1/packing/artworks', [
            'output_sku_id' => self::FINISHED_SKU_ID, 'artwork_code' => 'ART-APPLE-100', 'revision' => 1,
            'label_name' => 'Apple Snack 100g India label', 'barcode' => '8901000000011',
            'coding_template' => 'LOT {LOT} MFG {MFG} EXP {EXP} BATCH {BATCH}',
            'effective_from' => now()->subDay()->toDateString(), 'effective_to' => null, 'notes' => 'Approved retail label.',
        ])->assertCreated()->assertJsonPath('data.status', 'DRAFT');
        $artworkId = (string) $artwork->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/packing/artworks/'.$artworkId.'/approve')
            ->assertOk()->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.record_version', 2);

        $packing = $this->command()->postJson('/api/v1/packing/runs', [
            'run_number' => 'PACK-P1-FULL-01', 'production_order_id' => $orderId,
            'packaging_artwork_id' => $artworkId, 'packed_quantity' => '96',
            'finished_lot_code' => 'FG-P1-FULL-01', 'manufacture_date' => now()->toDateString(),
            'expiry_date' => now()->addMonth()->toDateString(), 'target_location_id' => self::FINISHED_LOCATION_ID,
            'actual_weight_samples' => ['100', '101', '99'], 'weight_uom_code' => 'G',
            'packing_configuration' => ['cartons' => 8, 'packs_per_carton' => 12],
            'notes' => 'Full recovered output packed.',
        ])->assertCreated()->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.coding_value', 'LOT FG-P1-FULL-01 MFG '.now()->toDateString().' EXP '.now()->addMonth()->toDateString().' BATCH BATCH-P1-FULL');
        $packingId = (string) $packing->json('data.id');
        $packed = $this->withHeaders($this->headers(1))->postJson('/api/v1/packing/runs/'.$packingId.'/complete')
            ->assertOk()->assertJsonPath('data.status', 'COMPLETED')->assertJsonPath('data.genealogy_edge_count', 1);
        $finishedLotId = (string) $packed->json('data.finished_lot_id');
        $this->assertDatabaseHas('fg_lots', ['lot_id' => $finishedLotId, 'status' => 'ACTIVE', 'packed_quantity' => 96]);
        $this->getJson('/api/v1/manufacturing/finished-goods/'.$finishedLotId)->assertOk()
            ->assertJsonPath('data.lot_code', 'FG-P1-FULL-01')->assertJsonPath('data.inputs.0.lot.code', 'RM-APPLE-2609A')
            ->assertJsonPath('data.position.quantity', '96.000000');
        $this->getJson('/api/v1/packing/runs/'.$packingId)->assertOk()
            ->assertJsonPath('data.pieces_per_pack', 1)
            ->assertJsonPath('data.target_finished_weight', '100.000000')
            ->assertJsonPath('data.average_sample_weight', '100.000000')
            ->assertJsonPath('data.weight_uom_code', 'G')
            ->assertJsonPath('data.actual_weight_samples.1', '101.000000')
            ->assertJsonPath('data.packing_configuration.packs_per_carton', 12);
        $this->signIn(self::SALES_ID);
        $salesOrder = $this->command()->postJson('/api/v1/sales/orders', [
            'order_number' => 'SO-P1-TRACE-01', 'customer_party_id' => self::CUSTOMER_ID,
            'sales_lead_id' => null, 'sales_contract_id' => self::CONTRACT_ID, 'sales_price_list_id' => null,
            'order_date' => now()->toDateString(), 'requested_delivery_date' => now()->addDays(2)->toDateString(),
            'notes' => 'Traceability acceptance dispatch from the manufactured lot.',
            'lines' => [['item_id' => self::FINISHED_SKU_ID, 'uom_code' => 'PACK',
                'quantity' => '5', 'discount_percent' => '0']],
        ])->assertCreated()->assertJsonPath('data.status', 'DRAFT');
        $salesOrderId = (string) $salesOrder->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/sales/orders/'.$salesOrderId.'/confirm')
            ->assertOk()->assertJsonPath('data.status', 'CONFIRMED');
        $allocation = $this->withHeaders($this->headers(2))->postJson('/api/v1/dispatch/orders/'.$salesOrderId.'/allocations', [
            'allocation_number' => 'ALLOC-P1-TRACE-01',
        ])->assertCreated()->assertJsonPath('data.status', 'RESERVED');
        $allocationId = (string) $allocation->json('data.id');
        self::assertSame($finishedLotId, DB::table('sales_allocation_lines')
            ->where('sales_allocation_id', $allocationId)->value('lot_id'));

        $this->signIn(self::OPERATIONS_ID);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/dispatch/allocations/'.$allocationId.'/pick')
            ->assertOk()->assertJsonPath('data.status', 'PICKED');
        $shipment = $this->command()->postJson('/api/v1/dispatch/shipments', [
            'shipment_number' => 'SHP-P1-TRACE-01', 'sales_allocation_id' => $allocationId,
            'carrier_name' => 'QT trace carrier', 'vehicle_number' => 'MH01QTTRACE',
            'driver_name' => 'Trace Driver', 'notes' => 'Acceptance trace shipment.',
        ])->assertCreated()->assertJsonPath('data.status', 'DRAFT');
        $shipmentId = (string) $shipment->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/dispatch/shipments/'.$shipmentId.'/load')
            ->assertOk()->assertJsonPath('data.status', 'LOADED');
        $this->withHeaders($this->headers(2))->postJson('/api/v1/dispatch/shipments/'.$shipmentId.'/dispatch', [
            'invoice_number' => 'INV-P1-TRACE-01',
        ])->assertOk()->assertJsonPath('data.status', 'DISPATCHED');

        $this->getJson('/api/v1/trace/lots/'.self::RAW_LOT_ID)->assertOk()
            ->assertJsonCount(2, 'data.nodes')->assertJsonPath('data.edges.0.output_lot_id', $finishedLotId)
            ->assertJsonPath('data.shipments.0.id', $shipmentId)
            ->assertJsonPath('data.shipments.0.number', 'SHP-P1-TRACE-01')
            ->assertJsonPath('data.shipments.0.lot_id', $finishedLotId);

        $material = $this->getJson('/api/v1/manufacturing/orders/'.$orderId)->assertOk()->json('data.materials.0');
        $cost = $this->command()->postJson('/api/v1/costing/batches', [
            'cost_number' => 'COST-P1-FULL-01', 'production_order_id' => $orderId,
            'labour_rate_per_minute' => '2.5', 'overhead_rate_per_minute' => '1.25',
            'material_costs' => [['production_order_material_id' => $material['id'], 'unit_cost' => '50']],
        ])->assertCreated()->assertJsonPath('data.status', 'FINALIZED')->assertJsonPath('data.snapshot_version', 1);
        $costId = (string) $cost->json('data.id');
        $this->getJson('/api/v1/costing/batches/'.$costId)->assertOk()
            ->assertJsonPath('data.materials.0.item_code', 'SKU-APPLE-BASE')
            ->assertJsonPath('data.good_quantity', '96.000000')->assertJsonCount(3, 'data.stages');
        $this->getJson('/api/v1/costing/batches?recipe_version=1&output_sku_id='.self::FINISHED_SKU_ID
            .'&shift_code=SHIFT-A&operator_id='.self::OPERATIONS_ID.'&date_from='.now()->toDateString()
            .'&date_to='.now()->toDateString())
            ->assertOk()->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.dimensions.recipe_version', 1)
            ->assertJsonPath('data.0.dimensions.shift_code', 'SHIFT-A')
            ->assertJsonPath('data.0.dimensions.operator.id', self::OPERATIONS_ID);

        $recall = $this->command()->postJson('/api/v1/trace/cases', [
            'recall_number' => 'RECALL-P1-FULL-01', 'source_lot_id' => self::RAW_LOT_ID,
            'classification' => 'CLASS_II', 'reason' => 'Supplier notified a possible raw-lot contamination.',
        ])->assertCreated()->assertJsonPath('data.status', 'OPEN')->assertJsonPath('data.affected_lot_count', 2);
        $recallId = (string) $recall->json('data.id');
        $this->assertDatabaseHas('lots', ['id' => $finishedLotId, 'status' => 'RECALLED']);
        $this->assertDatabaseHas('fg_lots', ['lot_id' => $finishedLotId, 'status' => 'RECALLED']);
        $this->assertDatabaseHas('stock_movements', ['movement_type' => 'RECALL_BLOCK', 'source_id' => $recallId]);
        $this->getJson('/api/v1/trace/cases/'.$recallId)->assertOk()
            ->assertJsonCount(2, 'data.lots')->assertJsonCount(2, 'data.trace.nodes');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/trace/cases/'.$recallId.'/close', [
            'closure_action' => 'All affected stock was contained; regulatory and customer notifications were completed.',
        ])->assertOk()->assertJsonPath('data.status', 'CLOSED');

        $this->assertDatabaseHas('audit_events', ['command' => 'COMPLETE_PRODUCTION_ORDER', 'entity_id' => $orderId]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'packing.run.completed', 'aggregate_id' => $packingId]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'trace.recall.closed', 'aggregate_id' => $recallId]);
    }

    public function test_failed_lab_deviation_and_scope_permission_controls_block_release(): void
    {
        $orderId = $this->completedOrder('P1-QA');
        $sample = $this->command()->postJson('/api/v1/quality/lab-samples', [
            'sample_number' => 'LAB-P1-QA-FAIL', 'production_order_id' => $orderId,
            'specification_id' => self::SPECIFICATION_ID, 'sampled_at' => now()->toISOString(), 'notes' => null,
        ])->assertCreated();
        $sampleId = (string) $sample->json('data.id');
        $resultId = (string) $this->getJson('/api/v1/quality/lab-samples/'.$sampleId)->json('data.results.0.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/quality/lab-samples/'.$sampleId.'/complete', [
            'results' => [['result_id' => $resultId, 'numeric_value' => '90']],
        ])->assertOk()->assertJsonPath('data.status', 'FAILED')->assertJsonPath('data.failure_count', 1);
        $safety = $this->getJson('/api/v1/quality/safety')->assertOk()
            ->assertJsonPath('summary.open_deviations', 1)->assertJsonPath('data.0.kind', 'DEVIATION')->json('data.0');
        $order = $this->getJson('/api/v1/manufacturing/orders/'.$orderId)->assertOk()->assertJsonPath('data.quality_status', 'HELD')->json('data');
        $this->withHeaders($this->headers($order['record_version']))
            ->postJson('/api/v1/quality/production-orders/'.$orderId.'/release')
            ->assertUnprocessable()->assertJsonPath('error.fields.lab_sample.0', 'The latest lab sample must pass before quality release.');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/quality/deviations/'.$safety['id'].'/resolve', [
            'disposition' => 'REWORK', 'root_cause' => 'Underweight filler calibration drift.',
            'corrective_action' => 'Filler recalibrated and retained output reworked for retest.',
        ])->assertOk()->assertJsonPath('data.status', 'RESOLVED');

        $this->signIn(self::SALES_ID);
        $this->getJson('/api/v1/manufacturing/orders')->assertForbidden();
        $this->postJson('/api/v1/trace/cases', [])->assertForbidden();
        $this->signIn(self::ADMIN_ID, self::OTHER_PLANT_ID);
        $this->getJson('/api/v1/manufacturing/orders/'.$orderId)->assertNotFound();
        $this->getJson('/api/v1/quality/lab-samples/'.$sampleId)->assertNotFound();
    }

    public function test_rejected_output_requires_reason_and_disposition_and_stays_distinct_from_normal_loss(): void
    {
        $lineId = $this->releasedScheduleLine('P1-REJECTION');
        $order = $this->command()->postJson('/api/v1/manufacturing/orders', [
            'order_number' => 'PRO-P1-REJECTION', 'batch_number' => 'BATCH-P1-REJECTION',
            'production_schedule_line_id' => $lineId, 'notes' => 'Rejected-output classification acceptance batch.',
        ])->assertCreated();
        $orderId = (string) $order->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/release')->assertOk();
        $this->withHeaders($this->headers(2))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/issue-materials')->assertOk();

        $this->withHeaders($this->headers(3))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/outputs', [
            'event_type' => 'LOSS', 'quantity' => '1', 'loss_category' => 'REJECTED_OUTPUT',
            'quantity_dimension' => 'COUNT', 'reason_code' => 'UNDERWEIGHT', 'disposition_code' => null,
        ])->assertUnprocessable()->assertJsonPath('error.fields.disposition_code.0',
            'Rejected output requires a disposition.');

        $accepted = $this->withHeaders($this->headers(3))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/outputs', [
            'event_type' => 'LOSS', 'quantity' => '1', 'loss_category' => 'REJECTED_OUTPUT',
            'quantity_dimension' => 'COUNT', 'value_source' => 'MEASURED', 'reason_code' => 'UNDERWEIGHT',
            'disposition_code' => 'SCRAP', 'notes' => 'Measured underweight piece rejected from saleable output.',
        ])->assertCreated()->assertJsonPath('data.rejected_output_quantity', '1.000000')
            ->assertJsonPath('data.normal_process_loss_quantity', '0.000000');
        $this->assertDatabaseHas('production_output_events', [
            'id' => $accepted->json('data.output_event_id'), 'loss_category' => 'REJECTED_OUTPUT',
            'reason_code' => 'UNDERWEIGHT', 'disposition_code' => 'SCRAP',
        ]);
    }

    public function test_closed_batch_corrections_are_additive_independently_approved_and_scope_safe(): void
    {
        DB::table('recipe_components')->where('recipe_id', '00000000-0000-4000-8000-000000000911')
            ->update(['is_packaging' => true]);
        $orderId = $this->completedOrder('P1-ADJUST');
        $before = $this->getJson('/api/v1/manufacturing/orders/'.$orderId)->assertOk()
            ->assertJsonPath('data.status', 'COMPLETED')->assertJsonPath('data.final_good_quantity', '100.000000')
            ->json('data');
        $material = $before['materials'][0];
        $recordedMaterial = $material['net_consumed_quantity'];

        $requested = $this->withHeaders($this->headers($before['record_version']))
            ->postJson('/api/v1/manufacturing/orders/'.$orderId.'/adjustments', [
                'adjustment_number' => 'ADJ-P1-OUTPUT-01', 'adjustment_type' => 'OUTPUT',
                'reason' => 'Verified post-count correction supported by signed batch documentation.',
                'effects' => [['metric_code' => 'GOOD_OUTPUT', 'subject_id' => null,
                    'signed_quantity' => '-2', 'uom_code' => 'PACK', 'signed_value' => '0', 'currency' => null]],
            ])->assertCreated()->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.record_version', 1);
        $adjustmentId = (string) $requested->json('data.id');

        $this->withHeaders($this->headers(1))->postJson('/api/v1/manufacturing/batch-adjustments/'.$adjustmentId.'/review', [
            'decision' => 'APPROVED', 'review_notes' => 'Requester attempted self-approval.',
        ])->assertUnprocessable()->assertJsonPath('error.fields.reviewer.0',
            'The requester cannot approve or reject their own batch correction.');

        $this->signIn(self::ADMIN_ID, self::OTHER_PLANT_ID);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/manufacturing/batch-adjustments/'.$adjustmentId.'/review', [
            'decision' => 'APPROVED', 'review_notes' => 'Wrong-plant attempt.',
        ])->assertNotFound();

        $this->signIn(self::ADMIN_ID);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/manufacturing/batch-adjustments/'.$adjustmentId.'/review', [
            'decision' => 'APPROVED', 'review_notes' => 'Independent evidence review confirms a two-pack overstatement.',
        ])->assertOk()->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.effect_count', 1);

        $afterOutput = $this->getJson('/api/v1/manufacturing/orders/'.$orderId)->assertOk()
            ->assertJsonPath('data.final_good_quantity', '98.000000')
            ->assertJsonPath('data.recorded_final_good_quantity', '100.000000')
            ->assertJsonPath('data.approved_output_adjustments.good', '-2.000000')
            ->assertJsonPath('data.adjustments.0.status', 'APPROVED')
            ->assertJsonPath('data.adjustments.0.effective_effects.0.signed_quantity', '-2.000000')
            ->json('data');
        self::assertSame('100.000000', $this->decimal(DB::table('production_output_events')
            ->where('production_order_id', $orderId)->where('event_type', 'GOOD')->sum('quantity')));

        $materialAdjustment = $this->withHeaders($this->headers($afterOutput['record_version']))
            ->postJson('/api/v1/manufacturing/orders/'.$orderId.'/adjustments', [
                'adjustment_number' => 'ADJ-P1-MATERIAL-01', 'adjustment_type' => 'MATERIAL',
                'reason' => 'Approved scale-ticket reconciliation identified an additional half kilogram consumed.',
                'effects' => [['metric_code' => 'MATERIAL_NET_CONSUMPTION', 'subject_id' => $material['id'],
                    'signed_quantity' => '0.5', 'uom_code' => $material['uom_code'], 'signed_value' => '0', 'currency' => null]],
            ])->assertCreated()->assertJsonPath('data.status', 'PENDING');

        $this->signIn(self::OPERATIONS_ID);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/manufacturing/batch-adjustments/'
            .$materialAdjustment->json('data.id').'/review', [
                'decision' => 'APPROVED', 'review_notes' => 'Independent reviewer matched scale ticket to the source lot.',
            ])->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->getJson('/api/v1/manufacturing/orders/'.$orderId)->assertOk()
            ->assertJsonPath('data.materials.0.net_consumed_quantity', $recordedMaterial)
            ->assertJsonPath('data.materials.0.approved_adjustment_quantity', '0.500000')
            ->assertJsonPath('data.materials.0.adjusted_net_consumed_quantity',
                bcadd($recordedMaterial, '0.5', 6));
        self::assertSame($recordedMaterial, $this->decimal(DB::table('production_order_materials')
            ->where('id', $material['id'])->value('net_consumed_quantity')));
        $this->assertDatabaseCount('production_batch_adjustment_effects', 2);
        $this->assertDatabaseHas('audit_events', ['command' => 'REVIEW_PRODUCTION_BATCH_ADJUSTMENT',
            'entity_id' => $adjustmentId]);

        $currentOrder = $this->getJson('/api/v1/manufacturing/orders/'.$orderId)->assertOk()->json('data');
        $cost = $this->command()->postJson('/api/v1/costing/batches', [
            'cost_number' => 'COST-P1-ADJUST-01', 'production_order_id' => $orderId,
            'labour_rate_per_minute' => '2', 'overhead_rate_per_minute' => '1',
            'all_source_costs_included' => true,
            'material_costs' => [['production_order_material_id' => $material['id'], 'unit_cost' => '50']],
        ])->assertCreated()->assertJsonPath('data.status', 'FINALIZED');
        $costId = (string) $cost->json('data.id');
        $costDetail = $this->getJson('/api/v1/costing/batches/'.$costId)->assertOk()
            ->assertJsonPath('data.good_quantity', '98.000000')
            ->assertJsonPath('data.materials.0.actual_quantity', bcadd($recordedMaterial, '0.5', 6))
            ->assertJsonPath('data.materials.0.is_packaging', true)
            ->assertJsonPath('data.packaging_variance_quantity', '0.500000')
            ->assertJsonPath('data.packaging_variance_cost', '25.000000')
            ->assertJsonPath('data.all_source_costs_included', true)->json('data');
        self::assertNotNull($costDetail['full_manufacturing_cost_per_good_unit']);

        $costCorrection = $this->withHeaders($this->headers($currentOrder['record_version']))
            ->postJson('/api/v1/manufacturing/orders/'.$orderId.'/adjustments', [
                'adjustment_number' => 'ADJ-P1-COST-01', 'adjustment_type' => 'COST',
                'reason' => 'Approved utility invoice arrived after the immutable cost snapshot was finalized.',
                'effects' => [['metric_code' => 'COST_VALUE', 'subject_id' => $costId,
                    'signed_quantity' => '0', 'uom_code' => null, 'signed_value' => '10', 'currency' => 'INR']],
            ])->assertCreated();
        $this->signIn(self::ADMIN_ID);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/manufacturing/batch-adjustments/'
            .$costCorrection->json('data.id').'/review', [
                'decision' => 'APPROVED', 'review_notes' => 'Finance source document and batch allocation independently verified.',
            ])->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $this->getJson('/api/v1/costing/batches/'.$costId)->assertOk()
            ->assertJsonPath('data.actual_total_cost', $costDetail['actual_total_cost'])
            ->assertJsonPath('data.approved_cost_adjustment_value', '10.000000')
            ->assertJsonPath('data.adjusted_actual_total_cost', bcadd($costDetail['actual_total_cost'], '10', 6));
        $this->assertDatabaseCount('production_batch_adjustment_effects', 3);

        $this->signIn(self::OPERATIONS_ID);
        $recipe = $this->getJson('/api/v1/manufacturing/recipes/'.$afterOutput['recipe']['id'])
            ->assertOk()->json('data');
        $snapshotBefore = DB::table('production_orders')->where('id', $orderId)->value('recipe_standard_snapshot');
        $recipeUpdate = collect($recipe)->only([
            'name', 'revision', 'output_sku_id', 'output_quantity', 'output_uom_code', 'yield_percent',
            'basis_quantity', 'basis_uom_code', 'calculation_model', 'standard_piece_weight',
            'piece_weight_uom_code', 'target_finished_weight', 'target_weight_uom_code', 'pieces_per_pack',
            'handling_loss_percent', 'handling_loss_in_standard', 'transformation_loss_percent',
            'transformation_loss_in_standard', 'rejection_allowance_percent', 'output_rounding_mode',
            'unit_conversion_rate', 'effective_from', 'effective_to', 'notes', 'components',
        ])->all();
        $recipeUpdate['notes'] = 'Attempted historical mutation.';
        $immutableResponse = $this->withHeaders($this->headers($recipe['record_version']))
            ->postJson('/api/v1/manufacturing/recipes/'.$recipe['id'], $recipeUpdate)
            ->assertUnprocessable();
        self::assertSame('A referenced recipe version is immutable. Create a new recipe revision instead.',
            $immutableResponse->json('error.fields.revision.0'), json_encode($immutableResponse->json()));
        self::assertSame($snapshotBefore, DB::table('production_orders')->where('id', $orderId)
            ->value('recipe_standard_snapshot'));
    }

    public function test_partial_additional_issue_return_waste_rework_and_qc_release_have_one_ledger_effect_each(): void
    {
        $lineId = $this->releasedScheduleLine('P1-MATERIAL-REC');
        $order = $this->command()->postJson('/api/v1/manufacturing/orders', [
            'order_number' => 'PRO-P1-MATERIAL-REC', 'batch_number' => 'BATCH-P1-MATERIAL-REC',
            'production_schedule_line_id' => $lineId, 'notes' => 'Material reconciliation acceptance batch.',
        ])->assertCreated();
        $orderId = (string) $order->json('data.id');
        $material = $this->getJson('/api/v1/manufacturing/orders/'.$orderId)->assertOk()->json('data.materials.0');
        $standardSnapshot = json_decode((string) DB::table('production_orders')->where('id', $orderId)
            ->value('recipe_standard_snapshot'), true, 512, JSON_THROW_ON_ERROR);
        $standardSnapshot['recipe']['basis_uom_code'] = 'KG';
        $standardSnapshot['components'][0]['is_basis_component'] = true;
        $standardSnapshot['components'][0]['mass_conversion_factor'] = '1.000000000000';
        DB::table('production_orders')->where('id', $orderId)->update([
            'planned_basis_quantity' => $material['required_quantity'],
            'recipe_standard_snapshot' => json_encode($standardSnapshot, JSON_THROW_ON_ERROR),
        ]);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/release')->assertOk();

        $planned = $this->withHeaders($this->headers(2))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/material-issues', [
            'production_order_material_id' => $material['id'], 'issue_kind' => 'PLANNED',
            'quantity' => '5', 'source_position_id' => null, 'reason_code' => null,
            'notes' => 'First partial issue.',
        ])->assertCreated()->assertJsonPath('data.status', 'IN_PROCESS')->assertJsonPath('data.record_version', 3);
        $plannedPostingId = (string) $planned->json('data.posting_ids.0');

        $additional = $this->withHeaders($this->headers(3))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/material-issues', [
            'production_order_material_id' => $material['id'], 'issue_kind' => 'ADDITIONAL',
            'quantity' => '1', 'source_position_id' => self::RAW_POSITION_ID,
            'reason_code' => 'SCALE_RECON', 'notes' => 'Scale ticket required one additional kilogram.',
        ])->assertCreated()->assertJsonPath('data.record_version', 4);
        $additionalPostingId = (string) $additional->json('data.posting_ids.0');

        $returned = $this->withHeaders($this->headers(4))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/material-returns', [
            'production_material_posting_id' => $plannedPostingId, 'quantity' => '2',
            'quarantine_position_id' => self::RAW_QUARANTINE_POSITION_ID,
            'reason_code' => 'UNUSED_RETURN', 'notes' => 'Sealed unused material returned for QC.',
        ])->assertCreated()->assertJsonPath('data.return_status', 'QC_PENDING')->assertJsonPath('data.record_version', 5);
        $returnPostingId = (string) $returned->json('data.posting_id');

        $waste = $this->withHeaders($this->headers(5))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/material-dispositions', [
            'parent_posting_id' => $additionalPostingId, 'disposition_kind' => 'WASTE', 'quantity' => '0.5',
            'reason_code' => 'LINE_SPILL', 'disposition_code' => 'DISCARD', 'notes' => 'Measured line-side spill.',
        ])->assertCreated()->assertJsonPath('data.record_version', 6)
            ->assertJsonPath('data.ledger_effect', 'WIP_MATERIAL_POSTING')
            ->assertJsonPath('data.stock_movement_id', null);
        $wastePostingId = (string) $waste->json('data.posting_id');
        $reworkOut = $this->withHeaders($this->headers(6))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/material-dispositions', [
            'parent_posting_id' => $plannedPostingId, 'disposition_kind' => 'REWORK_OUT', 'quantity' => '1',
            'reason_code' => 'MIX_REWORK', 'disposition_code' => 'REWORK_HOLD', 'notes' => 'Transferred out for approved rework.',
        ])->assertCreated()->assertJsonPath('data.record_version', 7);
        $reworkOutPostingId = (string) $reworkOut->json('data.posting_id');
        $reworkIn = $this->withHeaders($this->headers(7))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/material-dispositions', [
            'parent_posting_id' => $reworkOutPostingId, 'disposition_kind' => 'REWORK_IN', 'quantity' => '0.25',
            'reason_code' => 'MIX_REWORK', 'disposition_code' => 'RETURN_TO_BATCH', 'notes' => 'QC-approved portion returned to the batch.',
        ])->assertCreated()->assertJsonPath('data.record_version', 8);
        $reworkInPostingId = (string) $reworkIn->json('data.posting_id');

        $detail = $this->getJson('/api/v1/manufacturing/orders/'.$orderId)->assertOk()
            ->assertJsonPath('data.materials.0.issued_quantity', '5.000000')
            ->assertJsonPath('data.materials.0.additional_issued_quantity', '1.000000')
            ->assertJsonPath('data.materials.0.returned_quantity', '2.000000')
            ->assertJsonPath('data.materials.0.wasted_quantity', '0.500000')
            ->assertJsonPath('data.materials.0.rework_out_quantity', '1.000000')
            ->assertJsonPath('data.materials.0.rework_in_quantity', '0.250000')
            ->assertJsonPath('data.materials.0.net_consumed_quantity', '3.250000')
            ->assertJsonPath('data.material_normalisation.actual_quantity', '3.250000000000')
            ->assertJsonPath('data.material_normalisation.exception_code', null)
            ->assertJsonPath('data.materials.0.normalised_standard_quantity', '3.250000')
            ->assertJsonPath('data.materials.0.original_plan_variance', bcsub('3.25', $material['required_quantity'], 6))
            ->assertJsonPath('data.materials.0.efficiency_variance', '0.000000')->json('data');
        $this->assertDatabaseHas('production_material_postings', ['id' => $returnPostingId,
            'posting_type' => 'RETURN_QUARANTINE', 'status' => 'QC_PENDING',
            'lot_id' => self::RAW_LOT_ID, 'inventory_owner_id' => self::COMPANY_ID]);
        foreach ([[$wastePostingId, 'WASTE'], [$reworkOutPostingId, 'REWORK_OUT'], [$reworkInPostingId, 'REWORK_IN']] as [$postingId, $type]) {
            $this->assertDatabaseHas('production_material_postings', [
                'id' => $postingId, 'posting_type' => $type, 'stock_movement_id' => null,
                'lot_id' => self::RAW_LOT_ID, 'inventory_owner_id' => self::COMPANY_ID,
            ]);
        }

        $this->withHeaders($this->headers($detail['record_version']))
            ->postJson('/api/v1/manufacturing/material-returns/'.$returnPostingId.'/release', [
                'reusable_position_id' => self::RAW_REUSABLE_POSITION_ID,
                'notes' => 'Requester may not approve the reusable-stock release.',
            ])->assertUnprocessable()->assertJsonPath('error.fields.reviewer.0',
                'The return recorder cannot approve its own reusable-stock release.');
        $this->signIn(self::ADMIN_ID);
        $this->withHeaders($this->headers($detail['record_version']))
            ->postJson('/api/v1/manufacturing/material-returns/'.$returnPostingId.'/release', [
                'reusable_position_id' => self::RAW_REUSABLE_POSITION_ID,
                'notes' => 'Independent QC inspection passed; original lot and owner verified.',
            ])->assertOk()->assertJsonPath('data.return_status', 'RELEASED')->assertJsonPath('data.record_version', 9);

        $movementIds = DB::table('production_material_postings')->whereIn('id', [
            $plannedPostingId, $additionalPostingId, $returnPostingId,
        ])->pluck('stock_movement_id');
        $releasePosting = DB::table('production_material_postings')->where('parent_posting_id', $returnPostingId)
            ->where('posting_type', 'RETURN_RELEASE')->first();
        self::assertNotNull($releasePosting);
        $movementIds->push($releasePosting->stock_movement_id);
        self::assertCount(4, $movementIds->unique());
        self::assertSame(4, DB::table('stock_movements')->whereIn('id', $movementIds)->count());
        self::assertSame(3, DB::table('production_material_postings')->whereIn('id', [
            $wastePostingId, $reworkOutPostingId, $reworkInPostingId,
        ])->whereNull('stock_movement_id')->count());
        $this->assertDatabaseHas('production_material_postings', ['id' => $returnPostingId,
            'status' => 'RELEASED', 'approved_by' => self::ADMIN_ID]);
    }

    public function test_stage_templates_preserve_measured_and_estimated_mass_balance_with_independent_approval(): void
    {
        $lineId = $this->releasedScheduleLine('P1-STAGE-MASS');
        $order = $this->command()->postJson('/api/v1/manufacturing/orders', [
            'order_number' => 'PRO-P1-STAGE-MASS', 'batch_number' => 'BATCH-P1-STAGE-MASS',
            'production_schedule_line_id' => $lineId, 'notes' => 'Stage measurement acceptance batch.',
        ])->assertCreated();
        $orderId = (string) $order->json('data.id');
        $stage = $this->getJson('/api/v1/manufacturing/orders/'.$orderId)->assertOk()->json('data.stages.0');
        $template = $this->command()->postJson('/api/v1/manufacturing/stages/'.$stage['id'].'/measurement-templates', [
            'name' => 'Mix mass balance', 'lines' => [
                ['measurement_kind' => 'INCOMING', 'quantity_dimension' => 'MASS', 'uom_code' => 'KG',
                    'is_required' => true, 'default_value_source' => 'MEASURED',
                    'measurement_method' => 'Calibrated floor scale', 'balance_tolerance' => '0'],
                ['measurement_kind' => 'OUTPUT', 'quantity_dimension' => 'MASS', 'uom_code' => 'KG',
                    'is_required' => true, 'default_value_source' => 'MEASURED',
                    'measurement_method' => 'Calibrated floor scale', 'balance_tolerance' => '0'],
                ['measurement_kind' => 'NORMAL_TRANSFORMATION_LOSS', 'quantity_dimension' => 'MASS', 'uom_code' => 'KG',
                    'is_required' => false, 'default_value_source' => 'ESTIMATED',
                    'measurement_method' => 'Approved process estimate', 'balance_tolerance' => '0'],
            ],
        ])->assertCreated()->assertJsonPath('data.status', 'DRAFT');
        $templateId = (string) $template->json('data.id');
        $this->command()->postJson('/api/v1/manufacturing/measurement-templates/'.$templateId.'/approve')
            ->assertUnprocessable()->assertJsonPath('error.fields.approver.0',
                'Template approval must be performed by a different authorised user.');
        $this->signIn(self::ADMIN_ID);
        $this->command()->postJson('/api/v1/manufacturing/measurement-templates/'.$templateId.'/approve')
            ->assertOk()->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.revision', 1);

        $this->signIn(self::OPERATIONS_ID);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/release')->assertOk();
        $this->withHeaders($this->headers(2))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/issue-materials')->assertOk();
        $this->withHeaders($this->headers(1))->postJson('/api/v1/manufacturing/stages/'.$stage['id'].'/start')->assertOk();
        $this->withHeaders($this->headers(3))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/outputs', [
            'event_type' => 'LOSS', 'quantity' => '0.25', 'loss_category' => 'OVERWEIGHT_GIVEAWAY',
            'quantity_dimension' => 'MASS', 'value_source' => 'MEASURED', 'weight_uom_code' => 'KG',
            'reason_code' => 'WEIGHT_SAMPLE', 'notes' => 'Measured giveaway remains separate from piece rejection.',
        ])->assertCreated()->assertJsonPath('data.loss_quantity', '0.000000')
            ->assertJsonPath('data.overweight_giveaway_quantity', '0.250000');
        $measurements = [
            ['measurement_kind' => 'INCOMING', 'quantity_dimension' => 'MASS', 'quantity' => '10', 'uom_code' => 'KG',
                'value_source' => 'MEASURED', 'measurement_source' => 'Scale ticket IN-1',
                'measurement_method' => 'Calibrated floor scale', 'target_quantity' => '10',
                'reason_code' => null, 'disposition_code' => null, 'notes' => null],
            ['measurement_kind' => 'OUTPUT', 'quantity_dimension' => 'MASS', 'quantity' => '8', 'uom_code' => 'KG',
                'value_source' => 'MEASURED', 'measurement_source' => 'Scale ticket OUT-1',
                'measurement_method' => 'Calibrated floor scale', 'target_quantity' => '8',
                'reason_code' => null, 'disposition_code' => null, 'notes' => null],
            ['measurement_kind' => 'NORMAL_TRANSFORMATION_LOSS', 'quantity_dimension' => 'MASS', 'quantity' => '1', 'uom_code' => 'KG',
                'value_source' => 'ESTIMATED', 'measurement_source' => 'Approved route standard',
                'measurement_method' => 'Process evaporation model', 'target_quantity' => '1',
                'reason_code' => null, 'disposition_code' => null, 'notes' => 'Normal transformation, not rejected output.'],
            ['measurement_kind' => 'UNEXPLAINED', 'quantity_dimension' => 'MASS', 'quantity' => '1', 'uom_code' => 'KG',
                'value_source' => 'ESTIMATED', 'measurement_source' => 'Mass-balance calculation',
                'measurement_method' => 'Input less accounted output', 'target_quantity' => null,
                'reason_code' => 'BALANCE_GAP', 'disposition_code' => null, 'notes' => 'Investigate at batch review.'],
        ];
        $this->command()->postJson('/api/v1/manufacturing/stages/'.$stage['id'].'/measurements', [
            'measurements' => $measurements,
        ])->assertCreated()->assertJsonPath('data.measurement_count', 4)
            ->assertJsonPath('data.mass_balance.0.input_quantity', '10.000000')
            ->assertJsonPath('data.mass_balance.0.accounted_output_quantity', '9.000000')
            ->assertJsonPath('data.mass_balance.0.calculated_unexplained_quantity', '1.000000')
            ->assertJsonPath('data.mass_balance.0.recorded_unexplained_quantity', '1.000000')
            ->assertJsonPath('data.mass_balance.0.measured_quantity', '18.000000')
            ->assertJsonPath('data.mass_balance.0.estimated_quantity', '2.000000');
        $this->getJson('/api/v1/manufacturing/stages/'.$stage['id'])->assertOk()
            ->assertJsonPath('data.measurement_template.revision', 1)
            ->assertJsonPath('data.measurement_template.recipe_revision', 1)
            ->assertJsonPath('data.measurement_template.route_revision', 1)
            ->assertJsonCount(4, 'data.measurements')
            ->assertJsonPath('data.measurements.2.value_source', 'ESTIMATED');
        $this->withHeaders($this->headers(2))->postJson('/api/v1/manufacturing/stages/'.$stage['id'].'/complete', [
            'actual_minutes' => $stage['planned_minutes'], 'notes' => 'Required readings and explained balance recorded.',
        ])->assertOk()->assertJsonPath('data.status', 'COMPLETED');
    }

    private function completedOrder(string $suffix): string
    {
        $lineId = $this->releasedScheduleLine($suffix);
        $order = $this->command()->postJson('/api/v1/manufacturing/orders', [
            'order_number' => 'PRO-'.$suffix, 'batch_number' => 'BATCH-'.$suffix,
            'production_schedule_line_id' => $lineId, 'notes' => null,
        ])->assertCreated();
        $id = (string) $order->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/manufacturing/orders/'.$id.'/release')->assertOk();
        $this->withHeaders($this->headers(2))->postJson('/api/v1/manufacturing/orders/'.$id.'/issue-materials')->assertOk();
        $stages = $this->getJson('/api/v1/manufacturing/orders/'.$id)->json('data.stages');
        foreach ($stages as $stage) {
            $this->withHeaders($this->headers(1))->postJson('/api/v1/manufacturing/stages/'.$stage['id'].'/start')->assertOk();
            $this->withHeaders($this->headers(2))->postJson('/api/v1/manufacturing/stages/'.$stage['id'].'/complete', [
                'actual_minutes' => $stage['planned_minutes'], 'notes' => null,
            ])->assertOk();
        }
        $this->withHeaders($this->headers(3))->postJson('/api/v1/manufacturing/orders/'.$id.'/outputs', [
            'event_type' => 'GOOD', 'quantity' => '100', 'notes' => null,
        ])->assertCreated();
        $this->withHeaders($this->headers(4))->postJson('/api/v1/manufacturing/orders/'.$id.'/complete')->assertOk();

        return $id;
    }

    private function releasedScheduleLine(string $suffix): string
    {
        $demand = $this->command()->postJson('/api/v1/planning/demand', [
            'plan_number' => 'PLAN-'.$suffix, 'name' => 'Apple demand '.$suffix,
            'horizon_start' => now()->toDateString(), 'horizon_end' => now()->addDays(14)->toDateString(),
            'notes' => null, 'lines' => [['output_sku_id' => self::FINISHED_SKU_ID,
                'demand_date' => now()->addDays(7)->toDateString(), 'demand_type' => 'FIRM', 'quantity' => '100', 'notes' => null]],
        ])->assertCreated();
        $demandId = (string) $demand->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/planning/demand/'.$demandId.'/release')->assertOk();
        $mrp = $this->command()->postJson('/api/v1/planning/mrp/runs', [
            'run_number' => 'MRP-'.$suffix, 'demand_plan_id' => $demandId, 'run_date' => now()->toDateString(),
        ])->assertCreated();
        $mrpId = (string) $mrp->json('data.id');
        $plannedId = (string) $this->getJson('/api/v1/planning/mrp/'.$mrpId)->json('data.planned_orders.0.id');
        $start = now()->addDay()->toDateString();
        $end = now()->addDays(7)->toDateString();
        $schedule = $this->command()->postJson('/api/v1/planning/schedules', [
            'schedule_number' => 'SCH-'.$suffix, 'mrp_run_id' => $mrpId,
            'horizon_start' => $start, 'horizon_end' => $end, 'notes' => null,
            'lines' => [['mrp_planned_order_id' => $plannedId, 'planned_start_date' => $start, 'planned_end_date' => $end]],
            'capacities' => array_map(fn (string $center) => ['work_center_code' => $center, 'daily_capacity_minutes' => '480'],
                ['MIX-01', 'OVEN-01', 'PACK-01']),
        ])->assertCreated();
        $scheduleId = (string) $schedule->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/planning/schedules/'.$scheduleId.'/release')->assertOk();

        return (string) $this->getJson('/api/v1/planning/schedules/'.$scheduleId)->json('data.lines.0.id');
    }

    private function signIn(string $userId, string $plantId = self::PLANT_ID): void
    {
        $this->actingAs(User::query()->findOrFail($userId))
            ->withSession(['erp.company_id' => self::COMPANY_ID, 'erp.plant_id' => $plantId]);
    }

    private function command(): static
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid());
    }

    private function headers(int $version): array
    {
        return ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => (string) $version];
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 6);
    }
}

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
    private const FINISHED_SKU_ID = '00000000-0000-4000-8000-000000000601';
    private const RAW_LOT_ID = '00000000-0000-4000-8000-000000000703';
    private const RAW_POSITION_ID = '00000000-0000-4000-8000-000000001213';
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
            'production_schedule_line_id' => $lineId, 'notes' => 'P1 integrated production batch.',
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
            'event_type' => 'GOOD', 'quantity' => '95', 'notes' => 'First-pass good output.',
        ])->assertCreated()->assertJsonPath('data.good_quantity', '95.000000')->assertJsonPath('data.record_version', 4);
        $this->withHeaders($this->headers(4))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/outputs', [
            'event_type' => 'LOSS', 'quantity' => '3', 'reason_code' => 'BAKE_LOSS', 'notes' => 'Measured process loss.',
        ])->assertCreated()->assertJsonPath('data.loss_quantity', '3.000000')->assertJsonPath('data.record_version', 5);
        $rework = $this->withHeaders($this->headers(5))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/outputs', [
            'event_type' => 'REWORK', 'quantity' => '2', 'reason_code' => 'SEAL_REWORK', 'notes' => 'Re-seal before release.',
        ])->assertCreated()->assertJsonPath('data.open_rework_count', 1)->assertJsonPath('data.record_version', 6);
        $eventId = (string) $rework->json('data.output_event_id');
        $this->command()->postJson('/api/v1/manufacturing/output-events/'.$eventId.'/resolve', [
            'disposition' => 'RECOVERED', 'notes' => 'Re-seal verification passed.',
        ])->assertOk()->assertJsonPath('data.good_quantity', '97.000000')->assertJsonPath('data.record_version', 7);
        $this->withHeaders($this->headers(7))->postJson('/api/v1/manufacturing/orders/'.$orderId.'/complete')
            ->assertOk()->assertJsonPath('data.status', 'COMPLETED')->assertJsonPath('data.good_quantity', '97.000000')
            ->assertJsonPath('data.loss_quantity', '3.000000')->assertJsonPath('data.record_version', 8);

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

        $hold = $this->withHeaders($this->headers(8))->postJson('/api/v1/quality/food-safety-holds', [
            'hold_number' => 'HOLD-P1-FULL-01', 'production_order_id' => $orderId,
            'hazard_type' => 'ALLERGEN', 'reason' => 'Allergen clean-down evidence awaiting verification.',
        ])->assertCreated()->assertJsonPath('data.status', 'ACTIVE')->assertJsonPath('data.order_record_version', 9);
        $holdId = (string) $hold->json('data.id');
        $this->withHeaders($this->headers(9))->postJson('/api/v1/quality/production-orders/'.$orderId.'/release')
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
            'packaging_artwork_id' => $artworkId, 'packed_quantity' => '97',
            'finished_lot_code' => 'FG-P1-FULL-01', 'manufacture_date' => now()->toDateString(),
            'expiry_date' => now()->addMonths(6)->toDateString(), 'target_location_id' => self::FINISHED_LOCATION_ID,
            'notes' => 'Full recovered output packed.',
        ])->assertCreated()->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.coding_value', 'LOT FG-P1-FULL-01 MFG '.now()->toDateString().' EXP '.now()->addMonths(6)->toDateString().' BATCH BATCH-P1-FULL');
        $packingId = (string) $packing->json('data.id');
        $packed = $this->withHeaders($this->headers(1))->postJson('/api/v1/packing/runs/'.$packingId.'/complete')
            ->assertOk()->assertJsonPath('data.status', 'COMPLETED')->assertJsonPath('data.genealogy_edge_count', 1);
        $finishedLotId = (string) $packed->json('data.finished_lot_id');
        $this->assertDatabaseHas('fg_lots', ['lot_id' => $finishedLotId, 'status' => 'ACTIVE', 'packed_quantity' => 97]);
        $this->getJson('/api/v1/manufacturing/finished-goods/'.$finishedLotId)->assertOk()
            ->assertJsonPath('data.lot_code', 'FG-P1-FULL-01')->assertJsonPath('data.inputs.0.lot.code', 'RM-APPLE-2609A')
            ->assertJsonPath('data.position.quantity', '97.000000');
        $this->getJson('/api/v1/trace/lots/'.self::RAW_LOT_ID)->assertOk()
            ->assertJsonCount(2, 'data.nodes')->assertJsonPath('data.edges.0.output_lot_id', $finishedLotId);

        $material = $this->getJson('/api/v1/manufacturing/orders/'.$orderId)->assertOk()->json('data.materials.0');
        $cost = $this->command()->postJson('/api/v1/costing/batches', [
            'cost_number' => 'COST-P1-FULL-01', 'production_order_id' => $orderId,
            'labour_rate_per_minute' => '2.5', 'overhead_rate_per_minute' => '1.25',
            'material_costs' => [['production_order_material_id' => $material['id'], 'unit_cost' => '50']],
        ])->assertCreated()->assertJsonPath('data.status', 'FINALIZED')->assertJsonPath('data.snapshot_version', 1);
        $costId = (string) $cost->json('data.id');
        $this->getJson('/api/v1/costing/batches/'.$costId)->assertOk()
            ->assertJsonPath('data.materials.0.item_code', 'SKU-APPLE-BASE')
            ->assertJsonPath('data.good_quantity', '97.000000')->assertJsonCount(3, 'data.stages');

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

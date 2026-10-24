<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ManufacturingPlanningEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const OTHER_PLANT_ID = '00000000-0000-4000-8000-000000000102';
    private const OPERATIONS_ID = '00000000-0000-4000-8000-000000000202';
    private const ADMIN_ID = '00000000-0000-4000-8000-000000000204';
    private const SALES_ID = '00000000-0000-4000-8000-000000000201';
    private const FINISHED_SKU_ID = '00000000-0000-4000-8000-000000000601';
    private const RAW_POSITION_ID = '00000000-0000-4000-8000-000000001213';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->signIn(self::OPERATIONS_ID);
    }

    public function test_demand_mrp_capacity_schedule_and_material_reservation_complete_the_planning_chain(): void
    {
        $this->getJson('/api/v1/planning/demand')->assertOk()
            ->assertJsonPath('allowed_actions.0', 'CREATE')
            ->assertJsonPath('lookups.output_skus.0.id', self::FINISHED_SKU_ID);

        $demandPayload = $this->demandPayload('PLAN-MFG-001', '100');
        $key = (string) Str::uuid();
        $created = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/planning/demand', $demandPayload)
            ->assertCreated()->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.line_count', 1);
        $demandId = (string) $created->json('data.id');
        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/planning/demand', $demandPayload)
            ->assertCreated()->assertExactJson($created->json());

        $this->withHeaders($this->headers(1))->postJson('/api/v1/planning/demand/'.$demandId.'/release')
            ->assertOk()->assertJsonPath('data.status', 'RELEASED')->assertJsonPath('data.record_version', 2);
        $this->getJson('/api/v1/planning/demand/'.$demandId)->assertOk()
            ->assertJsonPath('data.lines.0.output_sku.code', 'SKU-APPLE-100')
            ->assertJsonPath('data.allowed_actions.0', 'CANCEL');

        $mrpPayload = [
            'run_number' => 'MRP-MFG-001', 'demand_plan_id' => $demandId,
            'run_date' => now()->toDateString(),
        ];
        $mrp = $this->command()->postJson('/api/v1/planning/mrp/runs', $mrpPayload)
            ->assertCreated()->assertJsonPath('data.status', 'COMPLETED')
            ->assertJsonPath('data.planned_order_count', 1)
            ->assertJsonPath('data.material_requirement_count', 1)
            ->assertJsonPath('data.shortage_quantity', '0.000000');
        $mrpId = (string) $mrp->json('data.id');
        $detail = $this->getJson('/api/v1/planning/mrp/'.$mrpId)->assertOk()
            ->assertJsonPath('data.planned_orders.0.output_sku.code', 'SKU-APPLE-100')
            ->assertJsonPath('data.planned_orders.0.materials.0.component_sku.code', 'SKU-APPLE-BASE')
            ->assertJsonPath('data.planned_orders.0.materials.0.gross_requirement', '10.304569')
            ->assertJsonPath('data.planned_orders.0.materials.0.on_hand_snapshot', '125.000000')
            ->assertJsonPath('data.planned_orders.0.materials.0.reserved_snapshot', '20.000000')
             ->assertJsonPath('data.planned_orders.0.materials.0.available_snapshot', '105.000000');
        $plannedOrderId = (string) $detail->json('data.planned_orders.0.id');

        $primaryRoute = (array) DB::table('production_routes')->where('code', 'ROUTE-APPLE-SNACK')->firstOrFail();
        $alternateRouteId = (string) Str::uuid();
        DB::table('production_routes')->insert(array_merge($primaryRoute, [
            'id' => $alternateRouteId,
            'code' => 'ZZ-ALTERNATE-APPLE',
            'name' => 'Alternate Apple Route',
        ]));
        DB::table('route_operations')->insert([
            'id' => (string) Str::uuid(), 'company_id' => self::COMPANY_ID,
            'route_id' => $alternateRouteId, 'sequence_no' => 1,
            'name' => 'Alternate processing', 'work_center_code' => 'ALT-01',
            'setup_minutes' => 5, 'run_minutes_per_unit' => 0.1,
            'instructions' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->getJson('/api/v1/planning/schedules')->assertOk()->assertJsonPath(
            'lookups.mrp_runs.0.planned_orders.0.work_centers',
            ['MIX-01', 'OVEN-01', 'PACK-01'],
        );

        $start = now()->addDay()->toDateString();
        $end = now()->addDays(5)->toDateString();
        $schedule = $this->command()->postJson('/api/v1/planning/schedules', [
            'schedule_number' => 'SCH-MFG-001', 'mrp_run_id' => $mrpId,
            'horizon_start' => $start, 'horizon_end' => $end,
            'notes' => 'Capacity-approved production window.',
            'lines' => [[
                'mrp_planned_order_id' => $plannedOrderId,
                'planned_start_date' => $start, 'planned_end_date' => $end,
            ]],
            'capacities' => $this->capacities('480'),
        ])->assertCreated()->assertJsonPath('data.status', 'DRAFT')
            ->assertJsonPath('data.line_count', 1)->assertJsonPath('data.work_center_count', 3)
            ->assertJsonPath('data.overloaded_work_centers', 0);
        $scheduleId = (string) $schedule->json('data.id');
        $this->getJson('/api/v1/planning/schedules/'.$scheduleId)->assertOk()
            ->assertJsonPath('data.lines.0.route.code', 'ROUTE-APPLE-SNACK')
            ->assertJsonCount(3, 'data.lines.0.operations')
            ->assertJsonPath('data.capacities.0.is_overloaded', false);

        $before = $this->decimal(DB::table('stock_positions')->where('id', self::RAW_POSITION_ID)->value('reserved_quantity_base'));
        $releaseKey = (string) Str::uuid();
        $released = $this->withHeaders(['Idempotency-Key' => $releaseKey, 'If-Match' => '1'])
            ->postJson('/api/v1/planning/schedules/'.$scheduleId.'/release')
            ->assertOk()->assertJsonPath('data.status', 'RELEASED')
            ->assertJsonPath('data.reservation_count', 1)
            ->assertJsonPath('data.reserved_quantity', '10.304569');
        $this->withHeaders(['Idempotency-Key' => $releaseKey, 'If-Match' => '1'])
            ->postJson('/api/v1/planning/schedules/'.$scheduleId.'/release')
            ->assertOk()->assertExactJson($released->json());
        $this->assertSame('20.000000', $before);
        $this->assertSame('30.304569', $this->decimal(DB::table('stock_positions')->where('id', self::RAW_POSITION_ID)->value('reserved_quantity_base')));
        $this->assertDatabaseHas('stock_reservations', ['status' => 'ACTIVE', 'quantity_base' => 10.304569]);
        $this->assertDatabaseHas('production_material_reservations', ['production_schedule_id' => $scheduleId, 'released_at' => null]);

        $this->withHeaders($this->headers(2))->postJson('/api/v1/planning/schedules/'.$scheduleId.'/cancel', [
            'reason' => 'Production window withdrawn after the planning review.',
        ])->assertOk()->assertJsonPath('data.status', 'CANCELLED')->assertJsonPath('data.released_reservation_count', 1);
        $this->assertSame('20.000000', $this->decimal(DB::table('stock_positions')->where('id', self::RAW_POSITION_ID)->value('reserved_quantity_base')));
        $this->assertDatabaseHas('stock_reservations', ['status' => 'RELEASED', 'quantity_base' => 10.304569]);

        $this->withHeaders($this->headers(1))->postJson('/api/v1/planning/mrp/'.$mrpId.'/cancel', [
            'reason' => 'The related production schedule was cancelled.',
        ])->assertOk()->assertJsonPath('data.status', 'CANCELLED');
        $this->withHeaders($this->headers(2))->postJson('/api/v1/planning/demand/'.$demandId.'/cancel', [
            'reason' => 'Demand is no longer required in this horizon.',
        ])->assertOk()->assertJsonPath('data.status', 'CANCELLED');

        $this->assertDatabaseHas('audit_events', ['command' => 'RELEASE_PRODUCTION_SCHEDULE', 'entity_id' => $scheduleId]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'manufacturing.production-schedule.cancelled', 'aggregate_id' => $scheduleId]);
    }

    public function test_mrp_and_schedule_use_time_phased_fefo_stock_without_double_counting(): void
    {
        $earlyDemandDate = now()->addDays(3)->toDateString();
        $lateDemandDate = now()->addDays(10)->toDateString();
        DB::table('lots')->where('id', '00000000-0000-4000-8000-000000000703')->update([
            'expiry_date' => now()->addDays(5)->toDateString(),
        ]);
        $sourceLot = (array) DB::table('lots')->where('id', '00000000-0000-4000-8000-000000000703')->firstOrFail();
        $longLifeLotId = (string) Str::uuid();
        DB::table('lots')->insert(array_merge($sourceLot, [
            'id' => $longLifeLotId,
            'internal_lot_code' => 'RM-APPLE-LONG-LIFE',
            'supplier_lot_code' => 'SUP-APPLE-LONG-LIFE',
            'expiry_date' => now()->addDays(30)->toDateString(),
            'created_at' => now(), 'updated_at' => now(),
        ]));
        $sourcePosition = (array) DB::table('stock_positions')->where('id', self::RAW_POSITION_ID)->firstOrFail();
        DB::table('stock_positions')->insert(array_merge($sourcePosition, [
            'id' => (string) Str::uuid(), 'lot_id' => $longLifeLotId,
            'quantity_base' => 15, 'reserved_quantity_base' => 0, 'record_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]));

        $payload = $this->demandPayload('PLAN-TIME-PHASED', '100');
        $payload['lines'] = [
            [
                'output_sku_id' => self::FINISHED_SKU_ID, 'demand_date' => $lateDemandDate,
                'demand_type' => 'FIRM', 'quantity' => '100', 'notes' => 'Later demand submitted first.',
            ],
            [
                'output_sku_id' => self::FINISHED_SKU_ID, 'demand_date' => $earlyDemandDate,
                'demand_type' => 'FORECAST', 'quantity' => '900', 'notes' => 'Earlier demand must net first.',
            ],
        ];
        $demand = $this->command()->postJson('/api/v1/planning/demand', $payload)->assertCreated();
        $demandId = (string) $demand->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/planning/demand/'.$demandId.'/release')->assertOk();
        $mrp = $this->command()->postJson('/api/v1/planning/mrp/runs', [
            'run_number' => 'MRP-TIME-PHASED', 'demand_plan_id' => $demandId,
            'run_date' => now()->toDateString(),
        ])->assertCreated()->assertJsonPath('data.shortage_quantity', '0.000000');
        $mrpId = (string) $mrp->json('data.id');
        $detail = $this->getJson('/api/v1/planning/mrp/'.$mrpId)->assertOk()
            ->assertJsonPath('data.planned_orders.0.due_date', $earlyDemandDate)
            ->assertJsonPath('data.planned_orders.0.materials.0.on_hand_snapshot', '140.000000')
            ->assertJsonPath('data.planned_orders.0.materials.0.reserved_snapshot', '20.000000')
            ->assertJsonPath('data.planned_orders.0.materials.0.available_snapshot', '120.000000')
            ->assertJsonPath('data.planned_orders.1.due_date', $lateDemandDate)
            ->assertJsonPath('data.planned_orders.1.materials.0.on_hand_snapshot', '15.000000')
            ->assertJsonPath('data.planned_orders.1.materials.0.reserved_snapshot', '0.000000')
            ->assertJsonPath('data.planned_orders.1.materials.0.available_snapshot', '15.000000');
        $orders = $detail->json('data.planned_orders');

        $schedule = $this->command()->postJson('/api/v1/planning/schedules', [
            'schedule_number' => 'SCH-TIME-PHASED', 'mrp_run_id' => $mrpId,
            'horizon_start' => now()->addDay()->toDateString(),
            'horizon_end' => now()->addDays(12)->toDateString(), 'notes' => null,
            'lines' => [
                ['mrp_planned_order_id' => $orders[0]['id'], 'planned_start_date' => $earlyDemandDate, 'planned_end_date' => $earlyDemandDate],
                ['mrp_planned_order_id' => $orders[1]['id'], 'planned_start_date' => $lateDemandDate, 'planned_end_date' => $lateDemandDate],
            ],
            'capacities' => $this->capacities('480'),
        ])->assertCreated();
        $scheduleId = (string) $schedule->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/planning/schedules/'.$scheduleId.'/release')
            ->assertOk()->assertJsonPath('data.reservation_count', 2);
        $this->getJson('/api/v1/planning/schedules/'.$scheduleId)->assertOk()
            ->assertJsonPath('data.reservations.0.lot_code', 'RM-APPLE-2609A')
            ->assertJsonPath('data.reservations.0.quantity', '92.741117')
            ->assertJsonPath('data.reservations.1.lot_code', 'RM-APPLE-LONG-LIFE')
            ->assertJsonPath('data.reservations.1.quantity', '10.304569');
    }

    public function test_capacity_and_current_material_shortages_block_schedule_release(): void
    {
        [$demandId, $mrpId, $plannedOrderId] = $this->releasedPlanAndMrp('CONTROL', '100');
        $start = now()->addDay()->toDateString();
        $end = now()->addDays(5)->toDateString();
        $overloaded = $this->command()->postJson('/api/v1/planning/schedules', [
            'schedule_number' => 'SCH-OVERLOAD-001', 'mrp_run_id' => $mrpId,
            'horizon_start' => $start, 'horizon_end' => $end, 'notes' => null,
            'lines' => [['mrp_planned_order_id' => $plannedOrderId, 'planned_start_date' => $start, 'planned_end_date' => $end]],
            'capacities' => $this->capacities('1'),
        ])->assertCreated()->assertJsonPath('data.overloaded_work_centers', 3);
        $overloadedId = (string) $overloaded->json('data.id');
        $this->getJson('/api/v1/planning/schedules/'.$overloadedId)->assertOk()
            ->assertJsonMissing(['allowed_actions' => ['RELEASE']]);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/planning/schedules/'.$overloadedId.'/release')
            ->assertUnprocessable()->assertJsonPath('error.fields.capacities.0', 'Resolve every overloaded work center before releasing the schedule.');

        $this->withHeaders($this->headers(1))->postJson('/api/v1/planning/schedules/'.$overloadedId, [
            'horizon_start' => $start, 'horizon_end' => $end, 'notes' => 'Capacity corrected.',
            'lines' => [['mrp_planned_order_id' => $plannedOrderId, 'planned_start_date' => $start, 'planned_end_date' => $end]],
            'capacities' => $this->capacities('480'),
        ])->assertOk()->assertJsonPath('data.overloaded_work_centers', 0);

        DB::table('stock_positions')->where('id', self::RAW_POSITION_ID)->update(['reserved_quantity_base' => 125]);
        $this->withHeaders($this->headers(2))->postJson('/api/v1/planning/schedules/'.$overloadedId.'/release')
            ->assertUnprocessable()->assertJsonPath('error.fields.materials.0', 'SKU-APPLE-BASE is short by 10.304569 KG for the planned start date.');
        $this->assertDatabaseCount('production_material_reservations', 0);
        $this->assertDatabaseHas('production_schedules', ['id' => $overloadedId, 'status' => 'DRAFT']);

        $this->withHeaders($this->headers(1))->postJson('/api/v1/planning/mrp/'.$mrpId.'/cancel', [
            'reason' => 'Attempt to bypass the active schedule guard.',
        ])->assertUnprocessable()->assertJsonPath('error.fields.status.0', 'Cancel every active production schedule before cancelling this MRP run.');
        $this->assertDatabaseHas('demand_plans', ['id' => $demandId, 'status' => 'RELEASED']);
    }

    public function test_planning_is_permissioned_scoped_versioned_and_validates_master_readiness(): void
    {
        $this->signIn(self::SALES_ID);
        $this->getJson('/api/v1/planning/demand')->assertForbidden();
        $this->postJson('/api/v1/planning/mrp/runs', [])->assertForbidden();

        $this->signIn(self::OPERATIONS_ID);
        DB::table('production_routes')->where('code', 'ROUTE-APPLE-SNACK')->update(['status' => 'INACTIVE']);
        $created = $this->command()->postJson('/api/v1/planning/demand', $this->demandPayload('PLAN-NO-ROUTE', '10'))
            ->assertCreated();
        $id = (string) $created->json('data.id');
        $routeFailure = $this->withHeaders($this->headers(1))
            ->postJson('/api/v1/planning/demand/'.$id.'/release')->assertUnprocessable();
        $this->assertSame(
            'No active production route exists for this output SKU.',
            $routeFailure->json('error.fields')['lines.0.output_sku_id'][0] ?? null,
        );
        DB::table('production_routes')->where('code', 'ROUTE-APPLE-SNACK')->update(['status' => 'ACTIVE']);
        $this->withHeaders($this->headers(99))->postJson('/api/v1/planning/demand/'.$id.'/release')
            ->assertConflict()->assertJsonPath('error.code', 'CONFLICT');

        $this->signIn(self::ADMIN_ID, self::OTHER_PLANT_ID);
        $this->getJson('/api/v1/planning/demand/'.$id)->assertNotFound();
        $this->signIn(self::OPERATIONS_ID);
        $this->getJson('/api/v1/planning/demand?q=NO-ROUTE&status=DRAFT')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    }

    private function releasedPlanAndMrp(string $suffix, string $quantity): array
    {
        $demand = $this->command()->postJson('/api/v1/planning/demand', $this->demandPayload('PLAN-'.$suffix, $quantity))
            ->assertCreated();
        $demandId = (string) $demand->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/planning/demand/'.$demandId.'/release')->assertOk();
        $mrp = $this->command()->postJson('/api/v1/planning/mrp/runs', [
            'run_number' => 'MRP-'.$suffix, 'demand_plan_id' => $demandId, 'run_date' => now()->toDateString(),
        ])->assertCreated();
        $mrpId = (string) $mrp->json('data.id');
        $plannedOrderId = (string) $this->getJson('/api/v1/planning/mrp/'.$mrpId)->assertOk()->json('data.planned_orders.0.id');

        return [$demandId, $mrpId, $plannedOrderId];
    }

    private function demandPayload(string $number, string $quantity): array
    {
        return [
            'plan_number' => $number, 'name' => 'Apple snack demand plan',
            'horizon_start' => now()->toDateString(), 'horizon_end' => now()->addDays(14)->toDateString(),
            'notes' => 'Approved commercial forecast plus firm customer demand.',
            'lines' => [[
                'output_sku_id' => self::FINISHED_SKU_ID, 'demand_date' => now()->addDays(7)->toDateString(),
                'demand_type' => 'FIRM', 'quantity' => $quantity, 'notes' => 'Priority production requirement.',
            ]],
        ];
    }

    private function capacities(string $minutes): array
    {
        return array_map(fn (string $center) => [
            'work_center_code' => $center, 'daily_capacity_minutes' => $minutes,
        ], ['MIX-01', 'OVEN-01', 'PACK-01']);
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

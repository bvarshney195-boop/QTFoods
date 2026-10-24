<?php

namespace Tests\Feature;

use App\Modules\Sales\Application\UnsoldSalesReturnService;
use App\Shared\Approval\ApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class UnsoldSalesReturnTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_approved_destroy_quantity_becomes_loss(): void
    {
        [$case, $lineId, $scope, $positionId] = $this->createCase('60');
        $service = $this->app->make(UnsoldSalesReturnService::class);

        $receipt = $service->receive($case['return_case_id'], $scope + [
            'actor_id' => $this->createUser(),
            'expected_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'line_id' => $lineId,
                'received_quantity' => '60',
                'return_position_id' => $positionId,
            ]],
        ]);

        $this->assertSame('RETURN_QUARANTINE', $receipt['status']);

        $qualityActor = (string) Str::uuid();
        $this->createUser($qualityActor);
        $disposition = $service->disposition($case['return_case_id'], $scope + [
            'actor_id' => $qualityActor,
            'expected_version' => 2,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'line_id' => $lineId,
                'restock_quantity' => '0',
                'repack_quantity' => '15',
                'rework_quantity' => '0',
                'destroy_quantity' => '45',
                'quality_reason_code' => 'SHORT_SHELF_LIFE',
            ]],
        ]);

        $this->assertDatabaseCount('loss_events', 0);

        $reviewerId = (string) Str::uuid();
        $this->createUser($reviewerId);
        $this->app->make(ApprovalService::class)->decide(
            $disposition['approval_request_id'],
            $reviewerId,
            'APPROVE'
        );

        $loss = $service->postLossAfterApproval($case['return_case_id'], $scope + [
            'actor_id' => $this->createUser(),
            'expected_version' => 3,
            'idempotency_key' => (string) Str::uuid(),
            'uom_code' => 'PACK',
            'currency' => 'INR',
        ]);

        $this->assertSame('45.000000', $loss['loss_quantity']);
        $this->assertDatabaseHas('loss_events', [
            'source_id' => $case['return_case_id'],
            'quantity_base' => 45,
            'uom_code' => 'PACK',
        ]);
        $this->assertSame(
            0.0,
            (float) DB::table('stock_positions')->where('id', $positionId)->value('quantity_base')
        );
        $this->assertSame(
            15.0,
            (float) DB::table('stock_positions')->where('quality_status', 'REPACK_HOLD')->value('quantity_base')
        );
        $this->assertDatabaseHas('stock_movements', [
            'source_id' => $case['return_case_id'],
            'movement_type' => 'UNSOLD_RETURN_REPACK',
            'quantity_base' => 15,
        ]);
        $this->assertDatabaseHas('stock_movements', [
            'source_id' => $case['return_case_id'],
            'movement_type' => 'UNSOLD_RETURN_DESTROY',
            'quantity_base' => 45,
            'to_position_id' => null,
        ]);
    }

    public function test_partial_receipt_stays_open_until_all_quantity_arrives(): void
    {
        [$case, $lineId, $scope, $positionId] = $this->createCase('60');
        $service = $this->app->make(UnsoldSalesReturnService::class);

        $partial = $service->receive($case['return_case_id'], $scope + [
            'actor_id' => $this->createUser(),
            'expected_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'line_id' => $lineId,
                'received_quantity' => '20',
                'return_position_id' => $positionId,
            ]],
        ]);

        $this->assertSame('PARTIALLY_RECEIVED', $partial['status']);
        $this->assertDatabaseHas('unsold_return_cases', [
            'id' => $case['return_case_id'],
            'status' => 'PARTIALLY_RECEIVED',
            'received_at' => null,
        ]);

        $complete = $service->receive($case['return_case_id'], $scope + [
            'actor_id' => $this->createUser(),
            'expected_version' => 2,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'line_id' => $lineId,
                'received_quantity' => '40',
                'return_position_id' => $positionId,
            ]],
        ]);

        $this->assertSame('RETURN_QUARANTINE', $complete['status']);
        $this->assertNotNull(
            DB::table('unsold_return_cases')->where('id', $case['return_case_id'])->value('received_at')
        );
    }

    public function test_disposition_must_equal_physically_received_quantity(): void
    {
        [$case, $lineId, $scope, $positionId] = $this->createCase('60');
        $service = $this->app->make(UnsoldSalesReturnService::class);

        $service->receive($case['return_case_id'], $scope + [
            'actor_id' => $this->createUser(),
            'expected_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'line_id' => $lineId,
                'received_quantity' => '60',
                'return_position_id' => $positionId,
            ]],
        ]);

        $this->expectException(ValidationException::class);

        $service->disposition($case['return_case_id'], $scope + [
            'actor_id' => $this->createUser(),
            'expected_version' => 2,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'line_id' => $lineId,
                'repack_quantity' => '10',
                'destroy_quantity' => '40',
            ]],
        ]);
    }

    public function test_maker_cannot_self_approve_loss(): void
    {
        [$case, $lineId, $scope, $positionId] = $this->createCase('10');
        $service = $this->app->make(UnsoldSalesReturnService::class);
        $qualityActor = (string) Str::uuid();
        $this->createUser($qualityActor);

        $service->receive($case['return_case_id'], $scope + [
            'actor_id' => $this->createUser(),
            'expected_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'line_id' => $lineId,
                'received_quantity' => '10',
                'return_position_id' => $positionId,
            ]],
        ]);

        $disposition = $service->disposition($case['return_case_id'], $scope + [
            'actor_id' => $qualityActor,
            'expected_version' => 2,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'line_id' => $lineId,
                'destroy_quantity' => '10',
            ]],
        ]);

        $this->expectException(ValidationException::class);
        $this->app->make(ApprovalService::class)->decide(
            $disposition['approval_request_id'],
            $qualityActor,
            'APPROVE'
        );
    }

    private function createCase(string $requestedQuantity): array
    {
        $service = $this->app->make(UnsoldSalesReturnService::class);
        $scope = [
            'company_id' => (string) Str::uuid(),
            'plant_id' => (string) Str::uuid(),
        ];
        $now = now();
        DB::table('companies')->insert([
            'id' => $scope['company_id'],
            'code' => 'TEST-'.Str::upper(Str::random(8)),
            'legal_name' => 'Test company',
            'display_name' => 'Test company',
            'status' => 'ACTIVE',
            'record_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('plants')->insert([
            'id' => $scope['plant_id'],
            'company_id' => $scope['company_id'],
            'code' => 'TEST',
            'name' => 'Test plant',
            'timezone' => 'Asia/Kolkata',
            'status' => 'ACTIVE',
            'record_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $skuId = (string) Str::uuid();
        $lotId = (string) Str::uuid();
        $ownerId = (string) Str::uuid();
        $case = $service->createRequest($scope + [
            'party_id' => (string) Str::uuid(),
            'shipment_id' => (string) Str::uuid(),
            'reason_code' => 'UNSOLD_MARKET_RETURN',
            'actor_id' => $this->createUser(),
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'sku_id' => $skuId,
                'fg_lot_id' => $lotId,
                'requested_quantity' => $requestedQuantity,
                'uom_code' => 'PACK',
            ]],
        ]);

        DB::table('uoms')->updateOrInsert(['code' => 'PACK'], [
            'name' => 'Pack',
            'precision' => 3,
        ]);
        DB::table('items')->insert([
            'id' => $skuId,
            'company_id' => $scope['company_id'],
            'code' => 'SKU-'.Str::upper(Str::random(8)),
            'name' => 'Test finished good',
            'item_type' => 'FINISHED_GOOD',
            'base_uom' => 'PACK',
            'status' => 'ACTIVE',
            'record_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('lots')->insert([
            'id' => $lotId,
            'company_id' => $scope['company_id'],
            'item_id' => $skuId,
            'internal_lot_code' => 'LOT-'.Str::upper(Str::random(8)),
            'origin_type' => 'RETURN',
            'status' => 'ACTIVE',
            'record_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('inventory_owners')->insert([
            'id' => $ownerId,
            'company_id' => $scope['company_id'],
            'code' => 'OWN',
            'name' => 'Company-owned stock',
            'owner_type' => 'COMPANY',
            'status' => 'ACTIVE',
            'record_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $lineId = DB::table('unsold_return_lines')
            ->where('return_case_id', $case['return_case_id'])
            ->value('id');

        $locationId = (string) Str::uuid();
        DB::table('locations')->insert([
            'id' => $locationId,
            'company_id' => $scope['company_id'],
            'plant_id' => $scope['plant_id'],
            'code' => 'RET-QA',
            'name' => 'Return Quarantine',
            'location_type' => 'RETURN_QUARANTINE',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $positionId = (string) Str::uuid();
        DB::table('stock_positions')->insert([
            'id' => $positionId,
            'company_id' => $scope['company_id'],
            'plant_id' => $scope['plant_id'],
            'item_id' => $skuId,
            'lot_id' => $lotId,
            'inventory_owner_id' => $ownerId,
            'location_id' => $locationId,
            'quality_status' => 'RETURN_QUARANTINE',
            'quantity_base' => 0,
            'reserved_quantity_base' => 0,
            'uom_code' => 'PACK',
            'record_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ([
            ['FG-SALE', 'Saleable Finished Goods', 'FINISHED_GOODS', 'RELEASED'],
            ['REP-HOLD', 'Repack Hold', 'REPACK', 'REPACK_HOLD'],
            ['REW-HOLD', 'Rework Hold', 'REWORK', 'REWORK_HOLD'],
        ] as [$code, $name, $locationType, $qualityStatus]) {
            $outcomeLocationId = (string) Str::uuid();
            DB::table('locations')->insert([
                'id' => $outcomeLocationId,
                'company_id' => $scope['company_id'],
                'plant_id' => $scope['plant_id'],
                'code' => $code,
                'name' => $name,
                'location_type' => $locationType,
                'status' => 'ACTIVE',
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('stock_positions')->insert([
                'id' => (string) Str::uuid(),
                'company_id' => $scope['company_id'],
                'plant_id' => $scope['plant_id'],
                'item_id' => $skuId,
                'lot_id' => $lotId,
                'inventory_owner_id' => $ownerId,
                'location_id' => $outcomeLocationId,
                'quality_status' => $qualityStatus,
                'quantity_base' => 0,
                'reserved_quantity_base' => 0,
                'uom_code' => 'PACK',
                'record_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return [$case, $lineId, $scope, $positionId];
    }

    private function createUser(?string $userId = null): string
    {
        $userId ??= (string) Str::uuid();
        DB::table('users')->insert([
            'id' => $userId,
            'email' => $userId.'@test.local',
            'name' => 'Test workflow actor',
            'password_hash' => 'not-used',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $userId;
    }
}

<?php

namespace Tests\Feature;

use App\Modules\Inventory\Application\StockPostingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

final class StockConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const TRAINING_PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const FINANCE_PLANT_ID = '00000000-0000-4000-8000-000000000102';
    private const ACTOR_ID = '00000000-0000-4000-8000-000000000202';
    private const ITEM_ID = '00000000-0000-4000-8000-000000000603';
    private const LOT_ID = '00000000-0000-4000-8000-000000000703';
    private const SOURCE_ID = '00000000-0000-4000-8000-000000001213';
    private const TARGET_LOCATION_ID = '00000000-0000-4000-8000-000000000810';
    private const FINANCE_LOCATION_ID = '00000000-0000-4000-8000-000000000806';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_two_commands_cannot_consume_more_than_available_stock(): void
    {
        [$command, $sourceId, $targetId] = $this->stockCommand('7');
        $service = $this->app->make(StockPostingService::class);

        $service->move($command);

        try {
            $service->move([
                ...$command,
                'idempotency_key' => (string) Str::uuid(),
            ]);
            $this->fail('The second movement should have been rejected.');
        } catch (ValidationException) {
            $this->assertSame(3.0, (float) DB::table('stock_positions')->where('id', $sourceId)->value('quantity_base'));
            $this->assertSame(7.0, (float) DB::table('stock_positions')->where('id', $targetId)->value('quantity_base'));
        }
    }

    public function test_movement_quantity_must_be_positive(): void
    {
        [$command] = $this->stockCommand('-1');

        $this->expectException(ValidationException::class);
        $this->app->make(StockPostingService::class)->move($command);
    }

    public function test_source_and_target_must_be_different(): void
    {
        [$command, $sourceId] = $this->stockCommand('1');
        $command['target_position_id'] = $sourceId;

        $this->expectException(ValidationException::class);
        $this->app->make(StockPostingService::class)->move($command);
    }

    public function test_positions_must_match_the_command_plant(): void
    {
        [$command, , $targetId] = $this->stockCommand('1');
        DB::table('stock_positions')->where('id', $targetId)->update([
            'plant_id' => self::FINANCE_PLANT_ID,
            'location_id' => self::FINANCE_LOCATION_ID,
        ]);

        $this->expectException(ValidationException::class);
        $this->app->make(StockPostingService::class)->move($command);
    }

    public function test_issue_posts_one_idempotent_outbound_movement(): void
    {
        [$command, $sourceId] = $this->stockCommand('6');
        unset($command['target_position_id']);
        $command['movement_type'] = 'DISPOSAL';
        $service = $this->app->make(StockPostingService::class);

        $first = $service->issue($command);
        $replay = $service->issue([
            ...$command,
            'correlation_id' => (string) Str::uuid(),
        ]);

        $this->assertSame($first['movement_id'], $replay['movement_id']);
        $this->assertSame(4.0, (float) DB::table('stock_positions')->where('id', $sourceId)->value('quantity_base'));
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseHas('stock_movements', [
            'id' => $first['movement_id'],
            'from_position_id' => $sourceId,
            'to_position_id' => null,
            'quantity_base' => 6,
        ]);
    }

    private function stockCommand(string $quantity): array
    {
        $sourceId = self::SOURCE_ID;
        $targetId = (string) Str::uuid();
        $now = now();

        DB::table('stock_reservations')->where('stock_position_id', $sourceId)->delete();
        DB::table('stock_positions')->where('id', $sourceId)->update([
            'quantity_base' => 10,
            'reserved_quantity_base' => 0,
            'record_version' => 1,
            'updated_at' => $now,
        ]);
        DB::table('stock_positions')->insert([
            'id' => $targetId,
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::TRAINING_PLANT_ID,
            'item_id' => self::ITEM_ID,
            'lot_id' => self::LOT_ID,
            'owner_party_id' => null,
            'inventory_owner_id' => self::COMPANY_ID,
            'location_id' => self::TARGET_LOCATION_ID,
            'quality_status' => 'RELEASED',
            'quantity_base' => 0,
            'reserved_quantity_base' => 0,
            'uom_code' => 'KG',
            'record_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return [[
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::TRAINING_PLANT_ID,
            'source_position_id' => $sourceId,
            'target_position_id' => $targetId,
            'quantity_base' => $quantity,
            'uom_code' => 'KG',
            'movement_type' => 'TRANSFER',
            'source_type' => 'TEST',
            'source_id' => (string) Str::uuid(),
            'actor_id' => self::ACTOR_ID,
            'idempotency_key' => (string) Str::uuid(),
        ], $sourceId, $targetId];
    }
}

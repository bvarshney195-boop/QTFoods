<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use App\Modules\Sales\Application\UnsoldSalesReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UnsoldReturnReadEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const TRAINING_PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const FINANCE_PLANT_ID = '00000000-0000-4000-8000-000000000102';
    private const SALES_USER_ID = '00000000-0000-4000-8000-000000000201';
    private const OPERATIONS_USER_ID = '00000000-0000-4000-8000-000000000202';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        $user = User::query()->where('email', 'demo.user@qtfoods.local')->firstOrFail();
        $this->actingAs($user)->withSession([
            'erp.company_id' => self::COMPANY_ID,
            'erp.plant_id' => self::TRAINING_PLANT_ID,
        ]);
    }

    public function test_list_is_scoped_filterable_and_paginated(): void
    {
        $targetPartyId = $this->createParty('DIST-A', 'Distributor Alpha');
        $otherPartyId = $this->createParty('DIST-B', 'Distributor Beta');

        $first = $this->createCase($targetPartyId, self::TRAINING_PLANT_ID);
        $second = $this->createCase($targetPartyId, self::TRAINING_PLANT_ID);
        $this->createCase($otherPartyId, self::TRAINING_PLANT_ID);
        $outOfScope = $this->createCase($targetPartyId, self::FINANCE_PLANT_ID);

        $response = $this->getJson('/api/v1/sales/unsold-returns?'.http_build_query([
            'status' => 'REQUESTED',
            'party_id' => $targetPartyId,
            'per_page' => 1,
            'sort' => 'created_at',
        ]));

        $response
            ->assertOk()
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 1)
            ->assertJsonPath('meta.total', 2)
            ->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.party.name', 'Distributor Alpha')
            ->assertJsonPath('data.0.line_count', 1)
            ->assertJsonPath('data.0.quantities.requested', '10')
            ->assertJsonMissing(['id' => $outOfScope]);

        $listedId = $response->json('data.0.id');
        $this->assertContains($listedId, [$first, $second]);
        $nextUrl = $response->json('links.next');
        $this->assertNotNull($nextUrl);
        parse_str((string) parse_url($nextUrl, PHP_URL_QUERY), $nextQuery);
        $this->assertSame('REQUESTED', $nextQuery['status']);
        $this->assertSame($targetPartyId, $nextQuery['party_id']);
        $this->assertSame('1', $nextQuery['per_page']);
        $this->assertSame('2', $nextQuery['page']);

        $next = $this->getJson('/api/v1/sales/unsold-returns?'.http_build_query([
            'status' => 'REQUESTED',
            'party_id' => $targetPartyId,
            'per_page' => 1,
            'page' => 2,
            'sort' => 'created_at',
        ]));

        $next->assertOk()->assertJsonPath('meta.current_page', 2);
        $this->assertContains($next->json('data.0.id'), [$first, $second]);
        $this->assertNotSame($listedId, $next->json('data.0.id'));
    }

    public function test_detail_includes_enriched_lines_approval_and_ordered_status_history(): void
    {
        $partyId = $this->createParty('DIST-A', 'Distributor Alpha');
        $skuId = $this->createItem('SKU-APPLE', 'Apple Snack Pack');
        $lotId = $this->createLot($skuId, 'FG-LOT-24A');
        $positionId = $this->createReturnPosition($skuId, $lotId);

        $caseId = $this->createCase($partyId, self::TRAINING_PLANT_ID, [
            'sku_id' => $skuId,
            'fg_lot_id' => $lotId,
        ]);
        $lineId = DB::table('unsold_return_lines')->where('return_case_id', $caseId)->value('id');

        $this->app->make(UnsoldSalesReturnService::class)->receive($caseId, [
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::TRAINING_PLANT_ID,
            'actor_id' => self::OPERATIONS_USER_ID,
            'expected_version' => 1,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'line_id' => $lineId,
                'received_quantity' => '10',
                'return_position_id' => $positionId,
            ]],
        ]);

        $this->app->make(UnsoldSalesReturnService::class)->disposition($caseId, [
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::TRAINING_PLANT_ID,
            'actor_id' => self::OPERATIONS_USER_ID,
            'expected_version' => 2,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'line_id' => $lineId,
                'destroy_quantity' => '10',
                'quality_reason_code' => 'SHORT_SHELF_LIFE',
            ]],
        ]);

        $this->getJson("/api/v1/sales/unsold-returns/{$caseId}")
            ->assertOk()
            ->assertJsonPath('data.id', $caseId)
            ->assertJsonPath('data.status', 'DISPOSITION_REVIEW')
            ->assertJsonPath('data.record_version', 3)
            ->assertJsonPath('data.party.code', 'DIST-A')
            ->assertJsonPath('data.party.name', 'Distributor Alpha')
            ->assertJsonPath('data.lines.0.sku.code', 'SKU-APPLE')
            ->assertJsonPath('data.lines.0.sku.name', 'Apple Snack Pack')
            ->assertJsonPath('data.lines.0.fg_lot.code', 'FG-LOT-24A')
            ->assertJsonPath('data.lines.0.return_position.quality_status', 'RETURN_QUARANTINE')
            ->assertJsonPath('data.status_history.0.from_status', null)
            ->assertJsonPath('data.status_history.0.to_status', 'REQUESTED')
            ->assertJsonPath('data.status_history.0.record_version', 1)
            ->assertJsonPath('data.status_history.1.from_status', 'REQUESTED')
            ->assertJsonPath('data.status_history.1.to_status', 'RETURN_QUARANTINE')
            ->assertJsonPath('data.status_history.1.record_version', 2)
            ->assertJsonPath('data.status_history.1.actor.name', 'Demo Operations Manager')
            ->assertJsonPath('data.status_history.2.from_status', 'RETURN_QUARANTINE')
            ->assertJsonPath('data.status_history.2.to_status', 'DISPOSITION_REVIEW')
            ->assertJsonPath('data.status_history.2.record_version', 3)
            ->assertJsonPath('data.approval.status', 'PENDING')
            ->assertJsonPath('data.approval.entity_version', 3)
            ->assertJsonPath('data.approval.maker.name', 'Demo Operations Manager');
    }

    public function test_detail_hides_a_case_from_another_plant(): void
    {
        $partyId = $this->createParty('DIST-A', 'Distributor Alpha');
        $caseId = $this->createCase($partyId, self::FINANCE_PLANT_ID);

        $this->getJson("/api/v1/sales/unsold-returns/{$caseId}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');
    }

    public function test_detail_does_not_enrich_reference_data_from_another_company(): void
    {
        $otherCompanyId = (string) Str::uuid();
        DB::table('companies')->insert([
            'id' => $otherCompanyId,
            'code' => 'OTHER',
            'legal_name' => 'Other Foods Ltd',
            'display_name' => 'Other Foods Ltd',
            'status' => 'ACTIVE',
            'record_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $foreignPartyId = $this->createParty('PRIVATE-DIST', 'Private Distributor', $otherCompanyId);
        $foreignSkuId = $this->createItem('PRIVATE-SKU', 'Private Product', $otherCompanyId);
        $caseId = $this->createCase($foreignPartyId, self::TRAINING_PLANT_ID, ['sku_id' => $foreignSkuId]);

        $this->getJson("/api/v1/sales/unsold-returns/{$caseId}")
            ->assertOk()
            ->assertJsonPath('data.party.id', $foreignPartyId)
            ->assertJsonPath('data.party.code', null)
            ->assertJsonPath('data.party.name', null)
            ->assertJsonPath('data.lines.0.sku.id', $foreignSkuId)
            ->assertJsonPath('data.lines.0.sku.code', null)
            ->assertJsonPath('data.lines.0.sku.name', null)
            ->assertJsonMissing(['name' => 'Private Distributor'])
            ->assertJsonMissing(['name' => 'Private Product']);
    }

    public function test_list_rejects_invalid_filters(): void
    {
        $this->getJson('/api/v1/sales/unsold-returns?status=UNKNOWN&per_page=101')
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure([
                'error' => ['fields' => ['status', 'per_page']],
            ]);
    }

    private function createParty(string $code, string $name, string $companyId = self::COMPANY_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('parties')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'code' => $code,
            'display_name' => $name,
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createItem(string $code, string $name, string $companyId = self::COMPANY_ID): string
    {
        $id = (string) Str::uuid();
        DB::table('items')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'code' => $code,
            'name' => $name,
            'item_type' => 'FINISHED_GOOD',
            'base_uom' => 'PACK',
            'status' => 'ACTIVE',
            'record_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createLot(string $itemId, string $code): string
    {
        $id = (string) Str::uuid();
        DB::table('lots')->insert([
            'id' => $id,
            'company_id' => self::COMPANY_ID,
            'item_id' => $itemId,
            'internal_lot_code' => $code,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createReturnPosition(string $itemId, string $lotId): string
    {
        $locationId = (string) Str::uuid();
        DB::table('locations')->insert([
            'id' => $locationId,
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::TRAINING_PLANT_ID,
            'code' => 'RET-TEST',
            'name' => 'Return Test Quarantine',
            'location_type' => 'RETURN_QUARANTINE',
            'status' => 'ACTIVE',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $id = (string) Str::uuid();
        DB::table('stock_positions')->insert([
            'id' => $id,
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::TRAINING_PLANT_ID,
            'item_id' => $itemId,
            'lot_id' => $lotId,
            'inventory_owner_id' => self::COMPANY_ID,
            'location_id' => $locationId,
            'quality_status' => 'RETURN_QUARANTINE',
            'quantity_base' => 0,
            'uom_code' => 'PACK',
            'record_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function createCase(string $partyId, string $plantId, array $lineOverrides = []): string
    {
        $case = $this->app->make(UnsoldSalesReturnService::class)->createRequest([
            'company_id' => self::COMPANY_ID,
            'plant_id' => $plantId,
            'party_id' => $partyId,
            'shipment_id' => (string) Str::uuid(),
            'reason_code' => 'UNSOLD_MARKET_RETURN',
            'expected_return_date' => '2026-09-30',
            'actor_id' => self::SALES_USER_ID,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                ...[
                    'sku_id' => (string) Str::uuid(),
                    'requested_quantity' => '10',
                    'uom_code' => 'PACK',
                ],
                ...$lineOverrides,
            ]],
        ]);

        return $case['return_case_id'];
    }
}

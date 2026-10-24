<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class StandardUomReferenceDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_standard_uoms_are_available_without_demo_seeders(): void
    {
        $codes = ['CASE', 'EA', 'G', 'KG', 'L', 'LTR', 'ML', 'PACK', 'PCS'];
        $this->assertSame(
            $codes,
            DB::table('uoms')->whereIn('code', $codes)->orderBy('code')->pluck('code')->all(),
        );
        $this->assertDatabaseHas('uoms', [
            'code' => 'EA',
            'name' => 'Each',
            'precision' => 0,
        ]);
        $this->assertDatabaseHas('uoms', [
            'code' => 'KG',
            'name' => 'Kilogram',
            'precision' => 6,
        ]);
        $this->assertDatabaseHas('uoms', [
            'code' => 'PCS',
            'name' => 'Pieces',
            'precision' => 0,
        ]);
        $this->assertDatabaseHas('uoms', [
            'code' => 'LTR',
            'name' => 'Litres',
            'precision' => 6,
        ]);
    }
}

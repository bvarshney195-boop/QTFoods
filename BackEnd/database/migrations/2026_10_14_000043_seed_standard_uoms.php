<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('uoms')->insertOrIgnore([
            ['code' => 'EA', 'name' => 'Each', 'precision' => 0],
            ['code' => 'PACK', 'name' => 'Pack', 'precision' => 0],
            ['code' => 'CASE', 'name' => 'Case', 'precision' => 0],
            ['code' => 'G', 'name' => 'Gram', 'precision' => 3],
            ['code' => 'KG', 'name' => 'Kilogram', 'precision' => 6],
            ['code' => 'ML', 'name' => 'Millilitre', 'precision' => 3],
            ['code' => 'L', 'name' => 'Litre', 'precision' => 6],
            ['code' => 'PCS', 'name' => 'Pieces', 'precision' => 0],
            ['code' => 'LTR', 'name' => 'Litres', 'precision' => 6],
        ]);
    }

    public function down(): void
    {
        // Reference UOMs may already be used by business records and must survive rollback.
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('uoms', function (Blueprint $table): void {
            $table->string('dimension_code', 32)->nullable();
            $table->decimal('base_conversion_factor', 24, 12)->nullable();
        });

        foreach ([
            'G' => ['MASS', '0.001'],
            'KG' => ['MASS', '1'],
            'ML' => ['VOLUME', '0.001'],
            'L' => ['VOLUME', '1'],
            'LTR' => ['VOLUME', '1'],
        ] as $code => [$dimension, $factor]) {
            DB::table('uoms')->where('code', $code)->update([
                'dimension_code' => $dimension,
                'base_conversion_factor' => $factor,
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('uoms', function (Blueprint $table): void {
            $table->dropColumn(['dimension_code', 'base_conversion_factor']);
        });
    }
};

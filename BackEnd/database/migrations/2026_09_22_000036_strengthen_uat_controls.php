<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('food_safety_holds', function (Blueprint $table): void {
            $table->string('disposition', 32)->nullable()->after('status');
            $table->text('root_cause')->nullable()->after('disposition');
        });

        DB::table('food_safety_holds')->where('status', 'RELEASED')->update([
            'disposition' => 'ACCEPTED',
            'root_cause' => 'Legacy release completed before closure governance fields were introduced.',
        ]);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE food_safety_holds DROP CONSTRAINT IF EXISTS food_safety_holds_values_check');
            DB::statement("ALTER TABLE food_safety_holds ADD CONSTRAINT food_safety_holds_values_check CHECK (hazard_type IN ('BIOLOGICAL', 'CHEMICAL', 'PHYSICAL', 'ALLERGEN', 'REGULATORY') AND status IN ('ACTIVE', 'RELEASED') AND record_version >= 1 AND ((status = 'ACTIVE' AND disposition IS NULL AND root_cause IS NULL AND released_at IS NULL AND released_by IS NULL) OR (status = 'RELEASED' AND disposition IN ('ACCEPTED', 'REWORK', 'SCRAP') AND released_at IS NOT NULL AND released_by IS NOT NULL AND btrim(root_cause) <> '' AND btrim(corrective_action) <> '')))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE food_safety_holds DROP CONSTRAINT IF EXISTS food_safety_holds_values_check');
        }
        Schema::table('food_safety_holds', function (Blueprint $table): void {
            $table->dropColumn(['disposition', 'root_cause']);
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE food_safety_holds ADD CONSTRAINT food_safety_holds_values_check CHECK (hazard_type IN ('BIOLOGICAL', 'CHEMICAL', 'PHYSICAL', 'ALLERGEN', 'REGULATORY') AND status IN ('ACTIVE', 'RELEASED') AND record_version >= 1 AND ((status = 'ACTIVE' AND released_at IS NULL AND released_by IS NULL) OR (status = 'RELEASED' AND released_at IS NOT NULL AND released_by IS NOT NULL AND btrim(corrective_action) <> '')))");
        }
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->string('cost_snapshot_status', 16)->default('MISSING')->after('unit_cost_snapshot');
            $table->string('cost_snapshot_source_type', 32)->nullable()->after('cost_snapshot_status');
            $table->uuid('cost_snapshot_source_id')->nullable()->after('cost_snapshot_source_type');
            $table->timestampTz('cost_snapshot_at')->nullable()->after('cost_snapshot_source_id');
            $table->uuid('cost_backfilled_by')->nullable()->after('cost_snapshot_at');
            $table->timestampTz('cost_backfilled_at')->nullable()->after('cost_backfilled_by');
            $table->index(['company_id', 'plant_id', 'cost_snapshot_status'], 'sales_order_lines_cost_coverage_index');
            $table->foreign(
                ['cost_snapshot_source_id', 'company_id', 'plant_id'],
                'sales_order_lines_cost_source_fk',
            )->references(['id', 'company_id', 'plant_id'])->on('batch_costs')->restrictOnDelete();
            $table->foreign('cost_backfilled_by', 'sales_order_lines_cost_backfill_user_fk')
                ->references('id')->on('users')->restrictOnDelete();
        });

        DB::table('sales_order_lines')->where('unit_cost_snapshot', '>', 0)->update([
            'cost_snapshot_status' => 'AVAILABLE',
            'cost_snapshot_source_type' => 'LEGACY_SNAPSHOT',
            'cost_snapshot_at' => now(),
        ]);

        $permissionId = DB::table('permissions')->where('code', 'ACTION:BI-PROFIT:COST-BACKFILL')->value('id')
            ?: (string) Str::uuid();
        DB::table('permissions')->updateOrInsert(['code' => 'ACTION:BI-PROFIT:COST-BACKFILL'], [
            'id' => $permissionId,
            'name' => 'Backfill missing profitability costs',
            'company_id' => null,
            'description' => 'Apply a finalized batch-cost snapshot with recorded provenance.',
            'status' => 'ACTIVE',
            'is_system' => true,
            'record_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $roleIds = DB::table('roles')->whereIn('code', ['FINANCE_REVIEWER', 'ERP_ADMIN'])->pluck('id');
        foreach ($roleIds as $roleId) {
            DB::table('role_permissions')->updateOrInsert([
                'role_id' => $roleId,
                'permission_id' => $permissionId,
            ]);
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement("ALTER TABLE sales_order_lines ADD CONSTRAINT sales_order_lines_cost_status_check CHECK (cost_snapshot_status IN ('MISSING', 'AVAILABLE', 'BACKFILLED'))");
            DB::statement("ALTER TABLE sales_order_lines ADD CONSTRAINT sales_order_lines_cost_provenance_check CHECK ((cost_snapshot_status = 'MISSING' AND cost_snapshot_source_type IS NULL AND cost_snapshot_source_id IS NULL AND cost_snapshot_at IS NULL) OR (cost_snapshot_status IN ('AVAILABLE', 'BACKFILLED') AND cost_snapshot_source_type = 'BATCH_COST' AND cost_snapshot_source_id IS NOT NULL AND cost_snapshot_at IS NOT NULL) OR (cost_snapshot_status = 'AVAILABLE' AND cost_snapshot_source_type = 'LEGACY_SNAPSHOT' AND cost_snapshot_source_id IS NULL AND cost_snapshot_at IS NOT NULL))");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE sales_order_lines DROP CONSTRAINT IF EXISTS sales_order_lines_cost_provenance_check');
            DB::statement('ALTER TABLE sales_order_lines DROP CONSTRAINT IF EXISTS sales_order_lines_cost_status_check');
        }
        $permissionId = DB::table('permissions')->where('code', 'ACTION:BI-PROFIT:COST-BACKFILL')->value('id');
        if ($permissionId) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
        Schema::table('sales_order_lines', function (Blueprint $table) {
            $table->dropForeign('sales_order_lines_cost_source_fk');
            $table->dropForeign('sales_order_lines_cost_backfill_user_fk');
            $table->dropIndex('sales_order_lines_cost_coverage_index');
            $table->dropColumn([
                'cost_snapshot_status', 'cost_snapshot_source_type', 'cost_snapshot_source_id',
                'cost_snapshot_at', 'cost_backfilled_by', 'cost_backfilled_at',
            ]);
        });
    }
};

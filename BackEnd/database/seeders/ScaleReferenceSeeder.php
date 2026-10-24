<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class ScaleReferenceSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('plant_transfer_routes')) {
            return;
        }

        $now = now();
        $companyId = '00000000-0000-4000-8000-000000000001';
        $sourcePlantId = '00000000-0000-4000-8000-000000000101';
        $destinationPlantId = '00000000-0000-4000-8000-000000000102';
        $adminId = '00000000-0000-4000-8000-000000000204';
        $groupId = '00000000-0000-4000-8000-000000003001';
        $routeId = '00000000-0000-4000-8000-000000003011';
        $itemId = '00000000-0000-4000-8000-000000000601';
        $lotId = '00000000-0000-4000-8000-000000002601';

        DB::table('consolidation_groups')->updateOrInsert(['id' => $groupId], [
            'owner_company_id' => $companyId,
            'group_code' => 'QTF-GROUP',
            'name' => 'Q & T Foods reporting group',
            'base_currency' => 'INR',
            'status' => 'ACTIVE',
            'record_version' => 1,
            'created_by' => $adminId,
            'activated_at' => $now,
            'activated_by' => $adminId,
            'retired_at' => null,
            'retired_by' => null,
            'retirement_reason' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('consolidation_group_members')->updateOrInsert([
            'id' => '00000000-0000-4000-8000-000000003002',
        ], [
            'consolidation_group_id' => $groupId,
            'owner_company_id' => $companyId,
            'company_id' => $companyId,
            'member_code' => 'QTF',
            'reporting_currency' => 'INR',
            'ownership_percent' => 100,
            'effective_from' => '2026-04-01',
            'effective_to' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('plant_transfer_routes')->updateOrInsert(['id' => $routeId], [
            'source_company_id' => $companyId,
            'source_plant_id' => $sourcePlantId,
            'destination_company_id' => $companyId,
            'destination_plant_id' => $destinationPlantId,
            'consolidation_group_id' => $groupId,
            'route_code' => 'TRAINING-TO-FIN',
            'name' => 'Training plant to Finance Review lane',
            'transfer_scope' => 'INTER_PLANT',
            'currency' => 'INR',
            'transit_days' => 1,
            'markup_percent' => 0,
            'require_destination_acceptance' => false,
            'require_commercial_reference' => false,
            'status' => 'ACTIVE',
            'record_version' => 1,
            'created_by' => $adminId,
            'activated_at' => $now,
            'activated_by' => $adminId,
            'deactivated_at' => null,
            'deactivated_by' => null,
            'deactivation_reason' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('plant_transfer_item_mappings')->updateOrInsert([
            'id' => '00000000-0000-4000-8000-000000003012',
        ], [
            'plant_transfer_route_id' => $routeId,
            'source_company_id' => $companyId,
            'source_plant_id' => $sourcePlantId,
            'destination_company_id' => $companyId,
            'destination_plant_id' => $destinationPlantId,
            'line_number' => 1,
            'source_item_id' => $itemId,
            'destination_item_id' => $itemId,
            'source_uom_code' => 'PACK',
            'destination_uom_code' => 'PACK',
            'conversion_rate' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('stock_positions')->updateOrInsert([
            'id' => '00000000-0000-4000-8000-000000003021',
        ], [
            'company_id' => $companyId,
            'plant_id' => $destinationPlantId,
            'item_id' => $itemId,
            'lot_id' => $lotId,
            'owner_party_id' => null,
            'inventory_owner_id' => $companyId,
            'location_id' => '00000000-0000-4000-8000-000000000806',
            'quality_status' => 'RELEASED',
            'quantity_base' => 0,
            'reserved_quantity_base' => 0,
            'uom_code' => 'PACK',
            'record_version' => 1,
            'status_reason' => null,
            'status_changed_at' => null,
            'status_changed_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

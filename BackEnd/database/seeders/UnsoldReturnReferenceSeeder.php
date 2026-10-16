<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class UnsoldReturnReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $now = now();
        $companyId = '00000000-0000-4000-8000-000000000001';
        $trainingPlantId = '00000000-0000-4000-8000-000000000101';
        $financePlantId = '00000000-0000-4000-8000-000000000102';

        foreach ([
            ['EA', 'Each', 0],
            ['PACK', 'Pack', 0],
            ['CASE', 'Case', 0],
            ['G', 'Gram', 3],
            ['KG', 'Kilogram', 6],
            ['ML', 'Millilitre', 3],
            ['L', 'Litre', 6],
        ] as [$code, $name, $precision]) {
            DB::table('uoms')->updateOrInsert(['code' => $code], [
                'name' => $name,
                'precision' => $precision,
            ]);
        }

        $parties = [
            ['00000000-0000-4000-8000-000000000501', 'DIST-NORTH', 'North Market Distributor'],
            ['00000000-0000-4000-8000-000000000502', 'DIST-CENTRAL', 'Central Retail Distribution'],
        ];
        foreach ($parties as [$id, $code, $name]) {
            DB::table('parties')->updateOrInsert(['id' => $id], [
                'company_id' => $companyId,
                'code' => $code,
                'display_name' => $name,
                'legal_name' => $name,
                'party_kind' => 'ORGANISATION',
                'notes' => 'Seeded customer master used by the Unsold Return workflow.',
                'status' => 'ACTIVE',
                'record_version' => 1,
                'status_reason' => null,
                'status_changed_at' => null,
                'status_changed_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ($parties as $index => [$partyId]) {
            $suffix = $index + 1;
            DB::table('party_roles')->updateOrInsert([
                'party_id' => $partyId,
                'role_code' => 'CUSTOMER',
            ], [
                'id' => sprintf('00000000-0000-4000-8000-%012d', 540 + $suffix),
                'company_id' => $companyId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('party_addresses')->updateOrInsert([
                'id' => sprintf('00000000-0000-4000-8000-%012d', 550 + $suffix),
            ], [
                'company_id' => $companyId,
                'party_id' => $partyId,
                'label' => 'Registered office',
                'address_type' => 'REGISTERED',
                'line_1' => $index === 0 ? '12 North Market Road' : '48 Central Distribution Avenue',
                'line_2' => null,
                'city' => $index === 0 ? 'Mumbai' : 'Bengaluru',
                'district' => null,
                'region' => $index === 0 ? 'Maharashtra' : 'Karnataka',
                'postal_code' => $index === 0 ? '400001' : '560001',
                'country_code' => 'IN',
                'is_primary' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('party_contacts')->updateOrInsert([
                'id' => sprintf('00000000-0000-4000-8000-%012d', 560 + $suffix),
            ], [
                'company_id' => $companyId,
                'party_id' => $partyId,
                'name' => $index === 0 ? 'North Market Desk' : 'Central Distribution Desk',
                'job_title' => 'Commercial contact',
                'department' => 'Procurement',
                'email' => $index === 0 ? 'orders@north-market.example' : 'orders@central-distribution.example',
                'phone' => $index === 0 ? '+91 22 4000 1001' : '+91 80 4000 1002',
                'mobile' => null,
                'is_primary' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('party_tax_registrations')->updateOrInsert([
                'id' => sprintf('00000000-0000-4000-8000-%012d', 570 + $suffix),
            ], [
                'company_id' => $companyId,
                'party_id' => $partyId,
                'registration_type' => 'GSTIN',
                'registration_number' => $index === 0 ? '27ABCDE1234F1Z5' : '29ABCDE1234F1Z6',
                'country_code' => 'IN',
                'is_primary' => true,
                'valid_from' => '2020-04-01',
                'valid_to' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('party_commercial_terms')->updateOrInsert([
                'party_id' => $partyId,
            ], [
                'id' => sprintf('00000000-0000-4000-8000-%012d', 580 + $suffix),
                'company_id' => $companyId,
                'currency_code' => 'INR',
                'payment_terms_days' => $index === 0 ? 30 : 21,
                'credit_limit' => $index === 0 ? 500000 : 300000,
                'credit_hold' => false,
                'incoterm_code' => 'DAP',
                'delivery_terms' => 'Delivery to the registered distribution facility.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('party_roles')->updateOrInsert([
            'party_id' => $parties[1][0],
            'role_code' => 'SUPPLIER',
        ], [
            'id' => '00000000-0000-4000-8000-000000000543',
            'company_id' => $companyId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach ([
            [$companyId, null, 'OWN', 'Q & T FOODS owned stock', 'COMPANY'],
            [$parties[0][0], $parties[0][0], 'OWN-NORTH', 'North Market consignment stock', 'PARTY'],
        ] as [$id, $partyId, $code, $name, $ownerType]) {
            DB::table('inventory_owners')->updateOrInsert(['id' => $id], [
                'company_id' => $companyId,
                'party_id' => $partyId,
                'code' => $code,
                'name' => $name,
                'owner_type' => $ownerType,
                'status' => 'ACTIVE',
                'record_version' => 1,
                'status_reason' => 'Seeded active inventory owner.',
                'status_changed_at' => $now,
                'status_changed_by' => '00000000-0000-4000-8000-000000000202',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $brandId = '00000000-0000-4000-8000-000000000901';
        DB::table('brands')->updateOrInsert(['id' => $brandId], [
            'company_id' => $companyId,
            'code' => 'QT-NATURALS',
            'name' => 'Q&T Naturals',
            'description' => 'Core packaged-food brand used by the seeded manufacturing master.',
            'status' => 'ACTIVE',
            'record_version' => 1,
            'status_reason' => null,
            'status_changed_at' => null,
            'status_changed_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('brand_agreements')->updateOrInsert([
            'id' => '00000000-0000-4000-8000-000000000902',
        ], [
            'company_id' => $companyId,
            'brand_id' => $brandId,
            'party_id' => $parties[0][0],
            'agreement_number' => 'DIST-2026-001',
            'agreement_type' => 'DISTRIBUTION',
            'effective_from' => '2026-04-01',
            'effective_to' => '2027-03-31',
            'currency_code' => 'INR',
            'minimum_commitment' => 250000,
            'status' => 'ACTIVE',
            'notes' => 'Seeded regional distribution agreement.',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $catalogItems = [
            ['00000000-0000-4000-8000-000000000591', 'ITEM-APPLE-SNACK', 'Apple Snack', 'FINISHED_GOOD', 'PACK', 180, true, $brandId],
            ['00000000-0000-4000-8000-000000000592', 'ITEM-MILLET-CRUNCH', 'Millet Crunch', 'FINISHED_GOOD', 'PACK', 180, true, $brandId],
            ['00000000-0000-4000-8000-000000000593', 'ITEM-APPLE-BASE', 'Apple Ingredient Base', 'RAW_MATERIAL', 'KG', 90, true, null],
        ];
        foreach ($catalogItems as [$id, $code, $name, $type, $baseUom, $shelfLife, $lotControlled, $itemBrandId]) {
            DB::table('catalog_items')->updateOrInsert(['id' => $id], [
                'company_id' => $companyId,
                'brand_id' => $itemBrandId,
                'code' => $code,
                'name' => $name,
                'item_type' => $type,
                'base_uom' => $baseUom,
                'description' => 'Seeded catalog item used by product and manufacturing workflows.',
                'shelf_life_days' => $shelfLife,
                'lot_controlled' => $lotControlled,
                'status' => 'ACTIVE',
                'record_version' => 1,
                'status_reason' => null,
                'status_changed_at' => null,
                'status_changed_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        foreach ([
            ['00000000-0000-4000-8000-000000000903', $catalogItems[0][0], 'CASE', 'PACK', 12],
            ['00000000-0000-4000-8000-000000000904', $catalogItems[1][0], 'CASE', 'PACK', 10],
            ['00000000-0000-4000-8000-000000000905', $catalogItems[2][0], 'KG', 'G', 1000],
        ] as [$id, $catalogItemId, $fromUom, $toUom, $multiplier]) {
            DB::table('item_uom_conversions')->updateOrInsert(['id' => $id], [
                'company_id' => $companyId,
                'catalog_item_id' => $catalogItemId,
                'from_uom_code' => $fromUom,
                'to_uom_code' => $toUom,
                'multiplier' => $multiplier,
                'rounding_mode' => 'HALF_UP',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $items = [
            ['00000000-0000-4000-8000-000000000601', $catalogItems[0][0], 'SKU-APPLE-100', 'Apple Snack Pack 100g', 'FINISHED_GOOD', 'PACK', '8901000000011'],
            ['00000000-0000-4000-8000-000000000602', $catalogItems[1][0], 'SKU-MILLET-150', 'Millet Crunch Pack 150g', 'FINISHED_GOOD', 'PACK', '8901000000028'],
            ['00000000-0000-4000-8000-000000000603', $catalogItems[2][0], 'SKU-APPLE-BASE', 'Apple Ingredient Base', 'RAW_MATERIAL', 'KG', '8901000000035'],
        ];
        foreach ($items as [$id, $catalogItemId, $code, $name, $type, $baseUom, $barcode]) {
            DB::table('items')->updateOrInsert(['id' => $id], [
                'company_id' => $companyId,
                'catalog_item_id' => $catalogItemId,
                'code' => $code,
                'name' => $name,
                'barcode' => $barcode,
                'description' => 'Seeded stock-bearing SKU.',
                'item_type' => $type,
                'base_uom' => $baseUom,
                'pack_quantity' => 1,
                'pack_uom_code' => $baseUom,
                'status' => 'ACTIVE',
                'record_version' => 1,
                'status_reason' => null,
                'status_changed_at' => null,
                'status_changed_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // A product-master rollback removes the catalog link while preserving legacy stock items.
        // Reapplying the migration creates one temporary catalog row per preserved SKU; once the
        // deterministic fixture links those SKUs back to their canonical item families, remove only
        // those now-unreferenced migration rows so rollback/reapply/seed remains repeatable.
        DB::table('catalog_items')
            ->where('company_id', $companyId)
            ->whereIn('id', array_column($items, 0))
            ->where('description', 'Migrated from the legacy stock-item master.')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('items')
                    ->whereColumn('items.catalog_item_id', 'catalog_items.id');
            })
            ->delete();

        foreach ([
            ['00000000-0000-4000-8000-000000000906', $items[0][0], 'PACK-APPLE-100', 'Apple 100g retail pack', 'PACK', 1, '8901000000110'],
            ['00000000-0000-4000-8000-000000000907', $items[1][0], 'PACK-MILLET-150', 'Millet 150g retail pack', 'PACK', 1, '8901000000127'],
            ['00000000-0000-4000-8000-000000000908', $items[2][0], 'PACK-APPLE-BASE', 'Apple base bulk kilogram', 'KG', 1, '8901000000134'],
        ] as [$id, $skuId, $code, $name, $uomCode, $quantity, $barcode]) {
            DB::table('sku_packs')->updateOrInsert(['id' => $id], [
                'company_id' => $companyId,
                'sku_id' => $skuId,
                'code' => $code,
                'name' => $name,
                'uom_code' => $uomCode,
                'quantity' => $quantity,
                'barcode' => $barcode,
                'is_default' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $recipeId = '00000000-0000-4000-8000-000000000911';
        DB::table('recipes')->updateOrInsert(['id' => $recipeId], [
            'company_id' => $companyId,
            'code' => 'REC-APPLE-100',
            'name' => 'Apple Snack 100g Recipe',
            'revision' => 1,
            'output_sku_id' => $items[0][0],
            'output_quantity' => 1,
            'output_uom_code' => 'PACK',
            'yield_percent' => 98.5,
            'basis_quantity' => 1,
            'basis_uom_code' => 'PACK',
            'calculation_model' => 'LEGACY_YIELD',
            'standard_piece_weight' => null,
            'piece_weight_uom_code' => null,
            'target_finished_weight' => 100,
            'target_weight_uom_code' => 'G',
            'pieces_per_pack' => 1,
            'handling_loss_percent' => 0,
            'handling_loss_in_standard' => true,
            'transformation_loss_percent' => 0,
            'transformation_loss_in_standard' => true,
            'rejection_allowance_percent' => 0,
            'output_rounding_mode' => 'DOWN',
            'unit_conversion_rate' => null,
            'effective_from' => '2026-04-01',
            'effective_to' => null,
            'notes' => 'Seeded recipe/BOM.',
            'status' => 'ACTIVE',
            'record_version' => 1,
            'status_reason' => null,
            'status_changed_at' => null,
            'status_changed_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('recipe_components')->updateOrInsert([
            'id' => '00000000-0000-4000-8000-000000000912',
        ], [
            'company_id' => $companyId,
            'recipe_id' => $recipeId,
            'component_sku_id' => $items[2][0],
            'sequence_no' => 10,
            'quantity' => 0.1,
            'uom_code' => 'KG',
            'waste_percent' => 1.5,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $routeId = '00000000-0000-4000-8000-000000000921';
        DB::table('production_routes')->updateOrInsert(['id' => $routeId], [
            'company_id' => $companyId,
            'code' => 'ROUTE-APPLE-SNACK',
            'name' => 'Apple Snack Processing Route',
            'catalog_item_id' => $catalogItems[0][0],
            'description' => 'Mix, form, bake, cool, and pack.',
            'status' => 'ACTIVE',
            'record_version' => 1,
            'status_reason' => null,
            'status_changed_at' => null,
            'status_changed_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([
            ['00000000-0000-4000-8000-000000000922', 10, 'Mix ingredients', 'MIX-01', 15, 0.05],
            ['00000000-0000-4000-8000-000000000923', 20, 'Bake and cool', 'OVEN-01', 20, 0.1],
            ['00000000-0000-4000-8000-000000000924', 30, 'Primary packing', 'PACK-01', 10, 0.03],
        ] as [$id, $sequence, $name, $workCenter, $setup, $run]) {
            DB::table('route_operations')->updateOrInsert(['id' => $id], [
                'company_id' => $companyId,
                'route_id' => $routeId,
                'sequence_no' => $sequence,
                'name' => $name,
                'work_center_code' => $workCenter,
                'setup_minutes' => $setup,
                'run_minutes_per_unit' => $run,
                'instructions' => 'Follow the approved work instruction for this operation.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $specificationId = '00000000-0000-4000-8000-000000000931';
        DB::table('quality_specifications')->updateOrInsert(['id' => $specificationId], [
            'company_id' => $companyId,
            'code' => 'SPEC-APPLE-100',
            'name' => 'Apple Snack Finished Pack Standard',
            'target_type' => 'SKU',
            'catalog_item_id' => null,
            'sku_id' => $items[0][0],
            'effective_from' => '2026-04-01',
            'effective_to' => null,
            'sampling_plan' => '5 packs per production lot',
            'notes' => 'Seeded release specification.',
            'status' => 'ACTIVE',
            'record_version' => 1,
            'status_reason' => null,
            'status_changed_at' => null,
            'status_changed_by' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('quality_spec_parameters')->updateOrInsert([
            'id' => '00000000-0000-4000-8000-000000000932',
        ], [
            'company_id' => $companyId,
            'specification_id' => $specificationId,
            'sequence_no' => 10,
            'code' => 'NET-WEIGHT',
            'name' => 'Net weight',
            'value_type' => 'NUMERIC',
            'uom_code' => 'G',
            'minimum_value' => 98,
            'target_value' => 100,
            'maximum_value' => 102,
            'text_requirement' => null,
            'test_method' => 'Calibrated bench scale',
            'is_required' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $lots = [
            ['00000000-0000-4000-8000-000000000701', $items[0][0], null, 'FG-APPLE-2608A', null, 'PRODUCTION', '2026-08-15', '2027-02-15'],
            ['00000000-0000-4000-8000-000000000702', $items[1][0], null, 'FG-MILLET-2608B', null, 'PRODUCTION', '2026-08-18', '2027-02-18'],
            ['00000000-0000-4000-8000-000000000703', $items[2][0], $parties[1][0], 'RM-APPLE-2609A', 'SUP-APPLE-441', 'PURCHASE', '2026-09-01', '2026-12-01'],
            [
                '00000000-0000-4000-8000-000000000704', $items[2][0], $parties[1][0],
                'RM-APPLE-EXPIRED', 'SUP-APPLE-OLD-19', 'PURCHASE',
                now()->subDays(120)->toDateString(), now()->subDay()->toDateString(),
            ],
        ];
        foreach ($lots as [$id, $itemId, $supplierId, $code, $supplierCode, $origin, $manufactured, $expires]) {
            DB::table('lots')->updateOrInsert(['id' => $id], [
                'company_id' => $companyId,
                'item_id' => $itemId,
                'supplier_party_id' => $supplierId,
                'internal_lot_code' => $code,
                'supplier_lot_code' => $supplierCode,
                'origin_type' => $origin,
                'manufacture_date' => $manufactured,
                'expiry_date' => $expires,
                'status' => 'ACTIVE',
                'notes' => 'Seeded traceable inventory lot.',
                'record_version' => 1,
                'status_reason' => 'Seeded active inventory lot.',
                'status_changed_at' => $now,
                'status_changed_by' => '00000000-0000-4000-8000-000000000202',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $locations = [
            ['00000000-0000-4000-8000-000000000801', $trainingPlantId, 'RET-QA', 'Return Quarantine', 'RETURN_QUARANTINE'],
            ['00000000-0000-4000-8000-000000000802', $financePlantId, 'RET-FIN', 'Finance Review Return Hold', 'RETURN_QUARANTINE'],
            ['00000000-0000-4000-8000-000000000803', $trainingPlantId, 'FG-SALE', 'Saleable Finished Goods', 'FINISHED_GOODS'],
            ['00000000-0000-4000-8000-000000000804', $trainingPlantId, 'REP-HOLD', 'Repack Hold', 'REPACK'],
            ['00000000-0000-4000-8000-000000000805', $trainingPlantId, 'REW-HOLD', 'Rework Hold', 'REWORK'],
            ['00000000-0000-4000-8000-000000000806', $financePlantId, 'FG-SALE', 'Saleable Finished Goods', 'FINISHED_GOODS'],
            ['00000000-0000-4000-8000-000000000807', $financePlantId, 'REP-HOLD', 'Repack Hold', 'REPACK'],
            ['00000000-0000-4000-8000-000000000808', $financePlantId, 'REW-HOLD', 'Rework Hold', 'REWORK'],
            ['00000000-0000-4000-8000-000000000809', $trainingPlantId, 'RAW-STORE', 'Raw Material Store', 'RAW_MATERIAL'],
            ['00000000-0000-4000-8000-000000000810', $trainingPlantId, 'QC-HOLD', 'Quality Hold', 'QUALITY_HOLD'],
            ['00000000-0000-4000-8000-000000000811', $trainingPlantId, 'LINE-SIDE', 'Production Line-side Store', 'OTHER'],
            ['00000000-0000-4000-8000-000000000812', $trainingPlantId, 'EXP-HOLD', 'Expired Stock Hold', 'BLOCKED'],
        ];
        foreach ($locations as [$id, $plantId, $code, $name, $locationType]) {
            DB::table('locations')->updateOrInsert(['id' => $id], [
                'company_id' => $companyId,
                'plant_id' => $plantId,
                'code' => $code,
                'name' => $name,
                'location_type' => $locationType,
                'status' => 'ACTIVE',
                'parent_location_id' => null,
                'description' => null,
                'record_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $shipments = [
            ['00000000-0000-4000-8000-000000001001', $trainingPlantId, 'SHP-2026-0001', $parties[0][0], 'DELIVERED', '2026-09-01 08:30:00'],
            ['00000000-0000-4000-8000-000000001002', $trainingPlantId, 'SHP-2026-0002', $parties[1][0], 'DISPATCHED', '2026-09-05 09:15:00'],
            ['00000000-0000-4000-8000-000000001003', $financePlantId, 'SHP-2026-0003', $parties[0][0], 'DELIVERED', '2026-09-02 10:00:00'],
        ];
        foreach ($shipments as [$id, $plantId, $number, $partyId, $status, $dispatchedAt]) {
            DB::table('shipments')->updateOrInsert(['id' => $id], [
                'company_id' => $companyId,
                'plant_id' => $plantId,
                'shipment_number' => $number,
                'party_id' => $partyId,
                'status' => $status,
                'dispatched_at' => $dispatchedAt,
                'record_version' => 1,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $invoices = [
            ['00000000-0000-4000-8000-000000001301', $trainingPlantId, $parties[0][0], $shipments[0][0], 'INV-2026-0001', 'POSTED', '10000', '1800', '11800', '4000', '2026-09-01 12:00:00'],
            ['00000000-0000-4000-8000-000000001302', $trainingPlantId, $parties[1][0], $shipments[1][0], 'INV-2026-0002', 'POSTED', '6000', '1080', '7080', '7080', '2026-09-05 13:00:00'],
            ['00000000-0000-4000-8000-000000001303', $financePlantId, $parties[0][0], $shipments[2][0], 'INV-2026-0003', 'PAID', '3000', '540', '3540', '0', '2026-09-02 14:00:00'],
        ];
        foreach ($invoices as [$id, $plantId, $partyId, $shipmentId, $number, $status, $net, $tax, $gross, $outstanding, $issuedAt]) {
            DB::table('invoices')->updateOrInsert(['id' => $id], [
                'company_id' => $companyId,
                'plant_id' => $plantId,
                'status' => $status,
                'record_version' => 1,
                'created_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('sales_invoice_financials')->updateOrInsert(['invoice_id' => $id], [
                'company_id' => $companyId,
                'plant_id' => $plantId,
                'party_id' => $partyId,
                'shipment_id' => $shipmentId,
                'invoice_number' => $number,
                'currency' => 'INR',
                'net_amount' => $net,
                'tax_amount' => $tax,
                'gross_amount' => $gross,
                'outstanding_amount' => $outstanding,
                'issued_at' => $issuedAt,
                'record_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $shipmentLines = [
            ['00000000-0000-4000-8000-000000001101', $shipments[0][0], $trainingPlantId, $items[0][0], $lots[0][0], '120', '20'],
            ['00000000-0000-4000-8000-000000001102', $shipments[1][0], $trainingPlantId, $items[1][0], $lots[1][0], '80', '0'],
            ['00000000-0000-4000-8000-000000001103', $shipments[2][0], $financePlantId, $items[0][0], $lots[0][0], '40', '0'],
        ];
        foreach ($shipmentLines as [$id, $shipmentId, $plantId, $itemId, $lotId, $shipped, $returned]) {
            DB::table('shipment_lines')->updateOrInsert(['id' => $id], [
                'shipment_id' => $shipmentId,
                'company_id' => $companyId,
                'plant_id' => $plantId,
                'item_id' => $itemId,
                'fg_lot_id' => $lotId,
                'shipped_quantity' => $shipped,
                'returned_quantity' => $returned,
                'uom_code' => 'PACK',
                'record_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $positions = [
            ['00000000-0000-4000-8000-000000001201', $trainingPlantId, $items[0][0], $lots[0][0], $locations[0][0], 'RETURN_QUARANTINE'],
            ['00000000-0000-4000-8000-000000001202', $trainingPlantId, $items[1][0], $lots[1][0], $locations[0][0], 'RETURN_QUARANTINE'],
            ['00000000-0000-4000-8000-000000001203', $financePlantId, $items[0][0], $lots[0][0], $locations[1][0], 'RETURN_QUARANTINE'],
            ['00000000-0000-4000-8000-000000001204', $trainingPlantId, $items[0][0], $lots[0][0], $locations[2][0], 'RELEASED'],
            ['00000000-0000-4000-8000-000000001205', $trainingPlantId, $items[0][0], $lots[0][0], $locations[3][0], 'REPACK_HOLD'],
            ['00000000-0000-4000-8000-000000001206', $trainingPlantId, $items[0][0], $lots[0][0], $locations[4][0], 'REWORK_HOLD'],
            ['00000000-0000-4000-8000-000000001207', $trainingPlantId, $items[1][0], $lots[1][0], $locations[2][0], 'RELEASED'],
            ['00000000-0000-4000-8000-000000001208', $trainingPlantId, $items[1][0], $lots[1][0], $locations[3][0], 'REPACK_HOLD'],
            ['00000000-0000-4000-8000-000000001209', $trainingPlantId, $items[1][0], $lots[1][0], $locations[4][0], 'REWORK_HOLD'],
            ['00000000-0000-4000-8000-000000001210', $financePlantId, $items[0][0], $lots[0][0], $locations[5][0], 'RELEASED'],
            ['00000000-0000-4000-8000-000000001211', $financePlantId, $items[0][0], $lots[0][0], $locations[6][0], 'REPACK_HOLD'],
            ['00000000-0000-4000-8000-000000001212', $financePlantId, $items[0][0], $lots[0][0], $locations[7][0], 'REWORK_HOLD'],
            ['00000000-0000-4000-8000-000000001213', $trainingPlantId, $items[2][0], $lots[2][0], $locations[8][0], 'RELEASED', $companyId, 125, 20, 'KG'],
            ['00000000-0000-4000-8000-000000001214', $trainingPlantId, $items[2][0], $lots[2][0], $locations[9][0], 'QUALITY_HOLD', $companyId, 15, 0, 'KG'],
            ['00000000-0000-4000-8000-000000001215', $trainingPlantId, $items[2][0], $lots[2][0], $locations[8][0], 'RELEASED', $parties[0][0], 12, 0, 'KG'],
            ['00000000-0000-4000-8000-000000001216', $trainingPlantId, $items[2][0], $lots[2][0], $locations[0][0], 'RETURN_QUARANTINE', $companyId, 0, 0, 'KG'],
            ['00000000-0000-4000-8000-000000001217', $trainingPlantId, $items[2][0], $lots[2][0], $locations[10][0], 'RELEASED', $companyId, 0, 0, 'KG'],
            ['00000000-0000-4000-8000-000000001218', $trainingPlantId, $items[2][0], $lots[3][0], $locations[8][0], 'RELEASED', $companyId, 8, 0, 'KG'],
            ['00000000-0000-4000-8000-000000001219', $trainingPlantId, $items[2][0], $lots[3][0], $locations[11][0], 'EXPIRED', $companyId, 0, 0, 'KG'],
        ];
        foreach ($positions as $position) {
            [$id, $plantId, $itemId, $lotId, $locationId, $qualityStatus] = $position;
            $inventoryOwnerId = $position[6] ?? $companyId;
            $quantity = $position[7] ?? 0;
            $reservedQuantity = $position[8] ?? 0;
            $uomCode = $position[9] ?? 'PACK';
            DB::table('stock_positions')->updateOrInsert(['id' => $id], [
                'company_id' => $companyId,
                'plant_id' => $plantId,
                'item_id' => $itemId,
                'lot_id' => $lotId,
                'owner_party_id' => $inventoryOwnerId === $companyId ? null : $inventoryOwnerId,
                'inventory_owner_id' => $inventoryOwnerId,
                'location_id' => $locationId,
                'quality_status' => $qualityStatus,
                'quantity_base' => $quantity,
                'reserved_quantity_base' => $reservedQuantity,
                'uom_code' => $uomCode,
                'record_version' => 1,
                'status_reason' => null,
                'status_changed_at' => null,
                'status_changed_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('stock_reservations')->updateOrInsert([
            'id' => '00000000-0000-4000-8000-000000001251',
        ], [
            'company_id' => $companyId,
            'plant_id' => $trainingPlantId,
            'stock_position_id' => '00000000-0000-4000-8000-000000001213',
            'reservation_number' => 'RSV-MRP-2609-001',
            'quantity_base' => 20,
            'status' => 'ACTIVE',
            'purpose' => 'Material requirement for the next apple snack production run.',
            'record_version' => 1,
            'created_by' => '00000000-0000-4000-8000-000000000202',
            'released_at' => null,
            'released_by' => null,
            'release_reason' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}

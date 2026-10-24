<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class P2ReferenceSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('chart_accounts')) {
            return;
        }

        $now = now();
        $companyId = '00000000-0000-4000-8000-000000000001';
        $plantId = '00000000-0000-4000-8000-000000000101';
        $adminId = '00000000-0000-4000-8000-000000000204';

        $accounts = [
            ['00000000-0000-4000-8000-000000002001', '110000', 'Operating bank', 'ASSET', 'CASH'],
            ['00000000-0000-4000-8000-000000002002', '120000', 'Trade receivables', 'ASSET', 'AR'],
            ['00000000-0000-4000-8000-000000002003', '130000', 'Finished goods inventory', 'ASSET', 'INVENTORY'],
            ['00000000-0000-4000-8000-000000002004', '140000', 'Input tax recoverable', 'ASSET', 'TAX'],
            ['00000000-0000-4000-8000-000000002005', '150000', 'Plant and equipment', 'ASSET', null],
            ['00000000-0000-4000-8000-000000002006', '159000', 'Accumulated depreciation', 'ASSET', 'DEPRECIATION'],
            ['00000000-0000-4000-8000-000000002007', '200000', 'Trade payables', 'LIABILITY', 'AP'],
            ['00000000-0000-4000-8000-000000002008', '210000', 'Output tax payable', 'LIABILITY', 'TAX'],
            ['00000000-0000-4000-8000-000000002009', '220000', 'Payroll payable', 'LIABILITY', 'PAYROLL'],
            ['00000000-0000-4000-8000-000000002010', '300000', 'Opening equity', 'EQUITY', null],
            ['00000000-0000-4000-8000-000000002011', '400000', 'Product sales', 'REVENUE', null],
            ['00000000-0000-4000-8000-000000002012', '500000', 'Cost of goods sold', 'EXPENSE', null],
            ['00000000-0000-4000-8000-000000002013', '510000', 'General operating expense', 'EXPENSE', null],
            ['00000000-0000-4000-8000-000000002014', '520000', 'Manufacturing overhead', 'EXPENSE', null],
            ['00000000-0000-4000-8000-000000002015', '530000', 'Depreciation expense', 'EXPENSE', null],
            ['00000000-0000-4000-8000-000000002016', '540000', 'Payroll expense', 'EXPENSE', null],
            ['00000000-0000-4000-8000-000000002017', '550000', 'Maintenance expense', 'EXPENSE', null],
            ['00000000-0000-4000-8000-000000002018', '999000', 'Migration suspense', 'ASSET', null],
        ];
        foreach ($accounts as [$id, $code, $name, $type, $control]) {
            DB::table('chart_accounts')->updateOrInsert(['id' => $id], [
                'company_id' => $companyId,
                'account_code' => $code,
                'name' => $name,
                'account_type' => $type,
                'control_type' => $control,
                'status' => 'ACTIVE',
                'record_version' => 1,
                'created_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        foreach ([
            ['00000000-0000-4000-8000-000000002101', '2026-09', '2026-09-01', '2026-09-30'],
            ['00000000-0000-4000-8000-000000002102', '2026-10', '2026-10-01', '2026-10-31'],
        ] as [$id, $code, $startsOn, $endsOn]) {
            DB::table('fiscal_periods')->updateOrInsert(['id' => $id], [
                'company_id' => $companyId,
                'period_code' => $code,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'status' => 'OPEN',
                'record_version' => 1,
                'closed_at' => null,
                'closed_by' => null,
                'closure_notes' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('bank_accounts')->updateOrInsert([
            'id' => '00000000-0000-4000-8000-000000002201',
        ], [
            'company_id' => $companyId,
            'account_code' => 'BANK-OPERATING',
            'bank_name' => 'Q&T Demonstration Bank',
            'account_name' => 'Q & T FOODS LTD',
            'masked_account_number' => 'XXXXXXXX4102',
            'ifsc_code' => 'QTFO0000101',
            'currency' => 'INR',
            'status' => 'ACTIVE',
            'record_version' => 1,
            'created_by' => $adminId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if (Schema::hasTable('customer_credit_profiles')) {
            foreach ([
                ['00000000-0000-4000-8000-000000002301', '00000000-0000-4000-8000-000000000501', 500000, 30],
                ['00000000-0000-4000-8000-000000002302', '00000000-0000-4000-8000-000000000502', 300000, 21],
            ] as [$id, $customerId, $limit, $terms]) {
                DB::table('customer_credit_profiles')->updateOrInsert(['id' => $id], [
                    'company_id' => $companyId,
                    'customer_party_id' => $customerId,
                    'credit_limit' => $limit,
                    'payment_terms_days' => $terms,
                    'is_on_hold' => false,
                    'hold_reason' => null,
                    'record_version' => 1,
                    'updated_by' => $adminId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('sales_price_lists')->updateOrInsert([
                'id' => '00000000-0000-4000-8000-000000002401',
            ], [
                'company_id' => $companyId,
                'plant_id' => $plantId,
                'list_number' => 'PL-2026-BASE',
                'name' => '2026 base distributor price list',
                'currency' => 'INR',
                'effective_from' => '2026-04-01',
                'effective_to' => '2027-03-31',
                'status' => 'ACTIVE',
                'notes' => 'Seeded P2 commercial reference.',
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
            foreach ([
                ['00000000-0000-4000-8000-000000002411', 1, '00000000-0000-4000-8000-000000000601', 100, 10, 18],
                ['00000000-0000-4000-8000-000000002412', 2, '00000000-0000-4000-8000-000000000602', 120, 8, 18],
            ] as [$id, $line, $itemId, $price, $discount, $tax]) {
                DB::table('sales_price_list_lines')->updateOrInsert(['id' => $id], [
                    'sales_price_list_id' => '00000000-0000-4000-8000-000000002401',
                    'company_id' => $companyId,
                    'plant_id' => $plantId,
                    'line_number' => $line,
                    'item_id' => $itemId,
                    'uom_code' => 'PACK',
                    'minimum_quantity' => 1,
                    'unit_price' => $price,
                    'maximum_discount_percent' => $discount,
                    'tax_rate' => $tax,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            DB::table('sales_contracts')->updateOrInsert([
                'id' => '00000000-0000-4000-8000-000000002501',
            ], [
                'company_id' => $companyId,
                'plant_id' => $plantId,
                'contract_number' => 'CON-2026-NORTH',
                'customer_party_id' => '00000000-0000-4000-8000-000000000501',
                'sales_price_list_id' => '00000000-0000-4000-8000-000000002401',
                'effective_from' => '2026-04-01',
                'effective_to' => '2027-03-31',
                'committed_value' => 250000,
                'currency' => 'INR',
                'status' => 'ACTIVE',
                'notes' => 'Seeded North Market sales contract.',
                'record_version' => 1,
                'created_by' => $adminId,
                'activated_at' => $now,
                'activated_by' => $adminId,
                'closed_at' => null,
                'closed_by' => null,
                'closure_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('sales_contract_lines')->updateOrInsert([
                'id' => '00000000-0000-4000-8000-000000002511',
            ], [
                'sales_contract_id' => '00000000-0000-4000-8000-000000002501',
                'company_id' => $companyId,
                'plant_id' => $plantId,
                'line_number' => 1,
                'item_id' => '00000000-0000-4000-8000-000000000601',
                'uom_code' => 'PACK',
                'committed_quantity' => 2500,
                'consumed_quantity' => 0,
                'unit_price' => 95,
                'tax_rate' => 18,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('lots')->updateOrInsert([
                'id' => '00000000-0000-4000-8000-000000002601',
            ], [
                'company_id' => $companyId,
                'item_id' => '00000000-0000-4000-8000-000000000601',
                'supplier_party_id' => null,
                'internal_lot_code' => 'FG-APPLE-P2-2609',
                'supplier_lot_code' => null,
                'origin_type' => 'PRODUCTION',
                'manufacture_date' => '2026-09-10',
                'expiry_date' => '2027-03-09',
                'status' => 'ACTIVE',
                'notes' => 'Saleable stock dedicated to the order-to-cash walkthrough.',
                'record_version' => 1,
                'status_reason' => 'Seeded active P2 lot.',
                'status_changed_at' => $now,
                'status_changed_by' => $adminId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('stock_positions')->updateOrInsert([
                'id' => '00000000-0000-4000-8000-000000002602',
            ], [
                'company_id' => $companyId,
                'plant_id' => $plantId,
                'item_id' => '00000000-0000-4000-8000-000000000601',
                'lot_id' => '00000000-0000-4000-8000-000000002601',
                'owner_party_id' => null,
                'inventory_owner_id' => $companyId,
                'location_id' => '00000000-0000-4000-8000-000000000803',
                'quality_status' => 'RELEASED',
                'quantity_base' => 500,
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
}

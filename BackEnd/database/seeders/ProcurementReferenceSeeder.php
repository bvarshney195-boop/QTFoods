<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class ProcurementReferenceSeeder extends Seeder
{
    public function run(): void
    {
        $companyId = '00000000-0000-4000-8000-000000000001';
        $now = now();
        $suppliers = [
            [
                'id' => '00000000-0000-4000-8000-000000000503',
                'role_id' => '00000000-0000-4000-8000-000000000544',
                'address_id' => '00000000-0000-4000-8000-000000000553',
                'contact_id' => '00000000-0000-4000-8000-000000000563',
                'tax_id' => '00000000-0000-4000-8000-000000000573',
                'terms_id' => '00000000-0000-4000-8000-000000000583',
                'code' => 'SUP-WESTERN',
                'name' => 'Western Ingredients Pvt Ltd',
                'city' => 'Pune',
                'region' => 'Maharashtra',
                'postal_code' => '411001',
                'email' => 'quotes@western-ingredients.example',
                'phone' => '+91 20 4100 2001',
                'gstin' => '27AAACW1234F1Z7',
                'payment_terms_days' => 30,
                'incoterm_code' => 'DAP',
            ],
            [
                'id' => '00000000-0000-4000-8000-000000000504',
                'role_id' => '00000000-0000-4000-8000-000000000545',
                'address_id' => '00000000-0000-4000-8000-000000000554',
                'contact_id' => '00000000-0000-4000-8000-000000000564',
                'tax_id' => '00000000-0000-4000-8000-000000000574',
                'terms_id' => '00000000-0000-4000-8000-000000000584',
                'code' => 'SUP-DECCAN',
                'name' => 'Deccan Supply Cooperative',
                'city' => 'Hyderabad',
                'region' => 'Telangana',
                'postal_code' => '500001',
                'email' => 'procurement@deccan-supply.example',
                'phone' => '+91 40 4100 2002',
                'gstin' => '36AAACD5678G1Z3',
                'payment_terms_days' => 21,
                'incoterm_code' => 'DDP',
            ],
        ];

        foreach ($suppliers as $supplier) {
            DB::table('parties')->insertOrIgnore([
                'id' => $supplier['id'],
                'company_id' => $companyId,
                'code' => $supplier['code'],
                'display_name' => $supplier['name'],
                'legal_name' => $supplier['name'],
                'party_kind' => 'ORGANISATION',
                'notes' => 'Seeded procurement supplier available for governed RFQ comparison.',
                'status' => 'ACTIVE',
                'record_version' => 1,
                'status_reason' => null,
                'status_changed_at' => null,
                'status_changed_by' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('party_roles')->insertOrIgnore([
                'id' => $supplier['role_id'],
                'company_id' => $companyId,
                'party_id' => $supplier['id'],
                'role_code' => 'SUPPLIER',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('party_addresses')->insertOrIgnore([
                'id' => $supplier['address_id'],
                'company_id' => $companyId,
                'party_id' => $supplier['id'],
                'label' => 'Registered office',
                'address_type' => 'REGISTERED',
                'line_1' => 'Procurement supplier registered facility',
                'line_2' => null,
                'city' => $supplier['city'],
                'district' => null,
                'region' => $supplier['region'],
                'postal_code' => $supplier['postal_code'],
                'country_code' => 'IN',
                'is_primary' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('party_contacts')->insertOrIgnore([
                'id' => $supplier['contact_id'],
                'company_id' => $companyId,
                'party_id' => $supplier['id'],
                'name' => $supplier['name'].' Commercial Desk',
                'job_title' => 'Sales contact',
                'department' => 'Commercial',
                'email' => $supplier['email'],
                'phone' => $supplier['phone'],
                'mobile' => null,
                'is_primary' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('party_tax_registrations')->insertOrIgnore([
                'id' => $supplier['tax_id'],
                'company_id' => $companyId,
                'party_id' => $supplier['id'],
                'registration_type' => 'GSTIN',
                'registration_number' => $supplier['gstin'],
                'country_code' => 'IN',
                'is_primary' => true,
                'valid_from' => '2020-04-01',
                'valid_to' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            DB::table('party_commercial_terms')->insertOrIgnore([
                'id' => $supplier['terms_id'],
                'company_id' => $companyId,
                'party_id' => $supplier['id'],
                'currency_code' => 'INR',
                'payment_terms_days' => $supplier['payment_terms_days'],
                'credit_limit' => 0,
                'credit_hold' => false,
                'incoterm_code' => $supplier['incoterm_code'],
                'delivery_terms' => 'Delivery to the selected Q & T Foods plant.',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}

<?php

namespace Database\Seeders;

use App\Modules\Partner\Application\PartnerPortalService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class PortalReferenceSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('partner_access_grants')) {
            return;
        }

        $companyId = '00000000-0000-4000-8000-000000000001';
        $plantId = '00000000-0000-4000-8000-000000000101';
        $partyId = '00000000-0000-4000-8000-000000000501';
        $partnerId = '00000000-0000-4000-8000-000000000205';
        $adminId = '00000000-0000-4000-8000-000000000204';
        $roleId = '00000000-0000-4000-8000-000000000305';
        $assignmentId = '00000000-0000-4000-8000-000000004001';
        $grantId = '00000000-0000-4000-8000-000000004002';
        $now = now();

        DB::table('role_assignments')->updateOrInsert(['id' => $assignmentId], [
            'user_id' => $partnerId,
            'role_id' => $roleId,
            'company_id' => $companyId,
            'plant_id' => $plantId,
            'party_id' => $partyId,
            'is_active' => true,
            'effective_from' => '2026-04-01 00:00:00',
            'effective_to' => null,
            'record_version' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('partner_access_grants')->updateOrInsert(['id' => $grantId], [
            'company_id' => $companyId,
            'plant_id' => $plantId,
            'party_id' => $partyId,
            'user_id' => $partnerId,
            'role_assignment_id' => $assignmentId,
            'status' => 'ACTIVE',
            'effective_from' => '2026-04-01 00:00:00',
            'effective_to' => null,
            'record_version' => 1,
            'created_by' => $adminId,
            'revoked_at' => null,
            'revoked_by' => null,
            'revocation_reason' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('partner_access_entitlements')->where('partner_access_grant_id', $grantId)->delete();
        foreach (PartnerPortalService::ENTITLEMENTS as $index => $entitlement) {
            DB::table('partner_access_entitlements')->insert([
                'id' => sprintf('00000000-0000-4000-8000-%012d', 4011 + $index),
                'partner_access_grant_id' => $grantId,
                'company_id' => $companyId,
                'plant_id' => $plantId,
                'party_id' => $partyId,
                'entitlement_code' => $entitlement,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }
}

<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;

final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (! config('deployment.allow_demo_seeders', false)) {
            throw new LogicException('DatabaseSeeder contains development/UAT fixtures and is disabled in this environment.');
        }

        DB::transaction(function () {
            $now = now();
            $companyId = '00000000-0000-4000-8000-000000000001';
            $trainingPlantId = '00000000-0000-4000-8000-000000000101';
            $financePlantId = '00000000-0000-4000-8000-000000000102';

            DB::table('companies')->updateOrInsert(['id' => $companyId], [
                'code' => 'QTF',
                'legal_name' => 'Q & T FOODS LTD',
                'display_name' => 'Q & T FOODS LTD',
                'status' => 'ACTIVE',
                'record_version' => 1,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ([
                [$trainingPlantId, 'TRAINING', 'Training Plant'],
                [$financePlantId, 'FIN-REVIEW', 'Finance Review'],
            ] as [$plantId, $code, $name]) {
                DB::table('plants')->updateOrInsert(['id' => $plantId], [
                    'company_id' => $companyId,
                    'code' => $code,
                    'name' => $name,
                    'timezone' => 'Asia/Kolkata',
                    'status' => 'ACTIVE',
                    'record_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $users = [
                'sales' => ['00000000-0000-4000-8000-000000000201', 'demo.user@qtfoods.local', 'Demo Sales Manager'],
                'operations' => ['00000000-0000-4000-8000-000000000202', 'operations.user@qtfoods.local', 'Demo Operations Manager'],
                'finance' => ['00000000-0000-4000-8000-000000000203', 'finance.user@qtfoods.local', 'Demo Finance Manager'],
                'admin' => ['00000000-0000-4000-8000-000000000204', 'admin.user@qtfoods.local', 'Demo ERP Administrator'],
                'partner' => ['00000000-0000-4000-8000-000000000205', 'partner.user@qtfoods.local', 'North Market Portal User'],
            ];

            foreach ($users as [$id, $email, $name]) {
                DB::table('users')->updateOrInsert(['id' => $id], [
                    'email' => $email,
                    'name' => $name,
                    'password_hash' => Hash::make('prototype'),
                    'status' => 'ACTIVE',
                    'email_verified_at' => $now,
                    'password_changed_at' => $now,
                    'last_login_at' => null,
                    'last_login_ip' => null,
                    'mfa_secret' => null,
                    'mfa_enabled_at' => null,
                    'record_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $roles = [
                'SALES_MANAGER' => ['00000000-0000-4000-8000-000000000301', 'Sales Manager', 'Customer, pricing, order, delivery, claim, and receivable work.'],
                'OPERATIONS_MANAGER' => ['00000000-0000-4000-8000-000000000302', 'Operations Manager', 'Purchasing, stock, production, quality, packing, maintenance, and warehouse work.'],
                'FINANCE_REVIEWER' => ['00000000-0000-4000-8000-000000000303', 'Finance Manager', 'Approvals, supplier and customer accounts, accounting, payroll, reporting, and finance controls.'],
                'ERP_ADMIN' => ['00000000-0000-4000-8000-000000000304', 'ERP Administrator', 'Organisation setup, users, roles, controls, support, and full-system administration.'],
                'PARTNER_PORTAL' => ['00000000-0000-4000-8000-000000000305', 'Partner User', 'Access only to records and documents explicitly shared with the assigned customer.'],
            ];

            foreach ($roles as $code => [$id, $name, $description]) {
                DB::table('roles')->updateOrInsert(['id' => $id], [
                    'code' => $code,
                    'name' => $name,
                    'company_id' => null,
                    'description' => $description,
                    'status' => 'ACTIVE',
                    'is_system' => true,
                    'record_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            $allScreens = [
                'WRK-HOME',
                'ADM-ORG', 'ADM-LOC', 'ADM-USER', 'ADM-ROLE', 'ADM-RULE', 'ADM-AUD', 'ADM-INT', 'ADM-HELP', 'BI-REP',
                'MD-PARTY', 'MD-BRAND', 'MD-ITEM', 'MD-SKU', 'PUR-REQ', 'PUR-RFQ', 'PUR-PO', 'INB-GATE', 'INB-GRN',
                'QC-IN', 'INB-RETURN', 'INV-STK', 'INV-ISS', 'INV-TRF', 'INV-COUNT', 'INV-EXP', 'MD-REC', 'MD-ROUTE', 'MD-SPEC',
                'PLAN-DEM', 'PLAN-MRP', 'PLAN-SCH', 'PRO-ORDER', 'PRO-STAGE', 'PRO-LOSS', 'QC-LAB', 'QC-SAFE', 'PACK-ART',
                'PACK-RUN', 'FG-LOT', 'TRACE-CASE', 'COST-BATCH', 'CRM-LEAD', 'CRM-PRICE', 'CRM-ORDER', 'CON-WORK',
                'DSP-PICK', 'DSP-LOAD', 'DSP-POD', 'RET-CASE', 'RET-UNSOLD', 'FIN-AR', 'BI-PROFIT', 'FIN-AP', 'FIN-EXP',
                'FIN-GL', 'COST-OH', 'ASSET-REG', 'HR-PAY', 'ENG-MNT', 'SCALE-PLANT', 'PORTAL-EXT', 'OPT-PLAN',
                'FIN-SIM', 'FIN-ADJ', 'FIN-LEGACY', 'FIN-ARCH', 'FIN-OPEN', 'FIN-SUP',
            ];

            $roleScreens = [
                'SALES_MANAGER' => [
                    'WRK-HOME', 'CRM-LEAD', 'CRM-PRICE', 'CRM-ORDER', 'CON-WORK', 'DSP-PICK', 'DSP-LOAD',
                    'DSP-POD', 'RET-CASE', 'RET-UNSOLD', 'FIN-AR', 'BI-PROFIT', 'ADM-HELP',
                ],
                'OPERATIONS_MANAGER' => [
                    'WRK-HOME', 'MD-PARTY', 'MD-BRAND', 'MD-ITEM', 'MD-SKU', 'PUR-REQ', 'PUR-RFQ', 'PUR-PO',
                    'INB-GATE', 'INB-GRN', 'QC-IN', 'INB-RETURN', 'INV-STK', 'INV-ISS', 'INV-TRF', 'INV-COUNT',
                    'INV-EXP', 'MD-REC', 'MD-ROUTE', 'MD-SPEC', 'PLAN-DEM', 'PLAN-MRP', 'PLAN-SCH', 'PRO-ORDER',
                    'PRO-STAGE', 'PRO-LOSS', 'QC-LAB', 'QC-SAFE', 'PACK-ART', 'PACK-RUN', 'FG-LOT', 'TRACE-CASE',
                    'COST-BATCH', 'DSP-PICK', 'DSP-LOAD', 'DSP-POD', 'RET-CASE', 'RET-UNSOLD',
                    'ENG-MNT', 'SCALE-PLANT', 'OPT-PLAN', 'ADM-HELP',
                ],
                'FINANCE_REVIEWER' => [
                    'WRK-HOME', 'PUR-REQ', 'CRM-PRICE', 'BI-REP', 'BI-PROFIT', 'FIN-AR', 'FIN-AP', 'FIN-EXP', 'FIN-GL', 'COST-OH',
                    'COST-BATCH', 'ASSET-REG', 'HR-PAY', 'FIN-SIM', 'FIN-ADJ', 'FIN-LEGACY', 'FIN-ARCH',
                    'FIN-OPEN', 'FIN-SUP', 'ENG-MNT', 'RET-UNSOLD', 'SCALE-PLANT', 'OPT-PLAN', 'ADM-HELP',
                ],
                'ERP_ADMIN' => $allScreens,
                'PARTNER_PORTAL' => ['PORTAL-EXT'],
            ];

            $permissionIds = [];
            foreach ($allScreens as $screenCode) {
                $permissionCode = "SCREEN:{$screenCode}:VIEW";
                $permissionId = DB::table('permissions')->where('code', $permissionCode)->value('id')
                    ?: (string) Str::uuid();

                DB::table('permissions')->updateOrInsert(['code' => $permissionCode], [
                    'id' => $permissionId,
                    'name' => "View {$screenCode}",
                    'company_id' => null,
                    'description' => null,
                    'status' => 'ACTIVE',
                    'is_system' => true,
                    'record_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                $permissionIds[$screenCode] = $permissionId;
            }

            foreach ($roleScreens as $roleCode => $screens) {
                $roleId = $roles[$roleCode][0];
                DB::table('role_permissions')->where('role_id', $roleId)->delete();
                DB::table('role_permissions')->insert(array_map(
                    fn (string $screenCode) => [
                        'role_id' => $roleId,
                        'permission_id' => $permissionIds[$screenCode],
                    ],
                    $screens
                ));
            }

            $roleActions = [
                'SALES_MANAGER' => [
                    'ACTION:WRK-HOME:CLAIM',
                    'ACTION:WRK-HOME:COMPLETE',
                    'ACTION:RET-UNSOLD:CREATE',
                    'ACTION:RET-UNSOLD:EVIDENCE',
                ],
                'OPERATIONS_MANAGER' => [
                    'ACTION:WRK-HOME:CLAIM',
                    'ACTION:WRK-HOME:COMPLETE',
                    'ACTION:PUR-REQ:CREATE',
                    'ACTION:PUR-REQ:UPDATE',
                    'ACTION:PUR-REQ:SUBMIT',
                    'ACTION:PUR-REQ:CANCEL',
                    'ACTION:PUR-RFQ:CREATE',
                    'ACTION:PUR-RFQ:UPDATE',
                    'ACTION:PUR-RFQ:ISSUE',
                    'ACTION:PUR-RFQ:QUOTE',
                    'ACTION:PUR-RFQ:AWARD',
                    'ACTION:PUR-RFQ:CANCEL',
                    'ACTION:PUR-PO:CREATE',
                    'ACTION:PUR-PO:AMEND',
                    'ACTION:PUR-PO:ISSUE',
                    'ACTION:PUR-PO:CANCEL',
                    'ACTION:INB-GATE:CREATE',
                    'ACTION:INB-GATE:UPDATE',
                    'ACTION:INB-GATE:CANCEL',
                    'ACTION:INB-GRN:CREATE',
                    'ACTION:INB-GRN:UPDATE',
                    'ACTION:INB-GRN:POST',
                    'ACTION:INB-GRN:CANCEL',
                    'ACTION:QC-IN:COMPLETE',
                    'ACTION:INB-RETURN:CREATE',
                    'ACTION:INB-RETURN:UPDATE',
                    'ACTION:INB-RETURN:POST',
                    'ACTION:INB-RETURN:CANCEL',
                    'ACTION:MD-PARTY:CREATE',
                    'ACTION:MD-PARTY:UPDATE',
                    'ACTION:MD-PARTY:LIFECYCLE',
                    'ACTION:MD-BRAND:CREATE',
                    'ACTION:MD-BRAND:UPDATE',
                    'ACTION:MD-BRAND:LIFECYCLE',
                    'ACTION:MD-ITEM:CREATE',
                    'ACTION:MD-ITEM:UPDATE',
                    'ACTION:MD-ITEM:LIFECYCLE',
                    'ACTION:MD-SKU:CREATE',
                    'ACTION:MD-SKU:UPDATE',
                    'ACTION:MD-SKU:LIFECYCLE',
                    'ACTION:MD-REC:CREATE',
                    'ACTION:MD-REC:UPDATE',
                    'ACTION:MD-REC:LIFECYCLE',
                    'ACTION:MD-ROUTE:CREATE',
                    'ACTION:MD-ROUTE:UPDATE',
                    'ACTION:MD-ROUTE:LIFECYCLE',
                    'ACTION:MD-SPEC:CREATE',
                    'ACTION:MD-SPEC:UPDATE',
                    'ACTION:MD-SPEC:LIFECYCLE',
                    'ACTION:INV-STK:OWNER-CREATE',
                    'ACTION:INV-STK:OWNER-UPDATE',
                    'ACTION:INV-STK:OWNER-LIFECYCLE',
                    'ACTION:INV-STK:LOT-CREATE',
                    'ACTION:INV-STK:LOT-UPDATE',
                    'ACTION:INV-STK:LOT-LIFECYCLE',
                    'ACTION:INV-STK:RESERVE',
                    'ACTION:INV-STK:RELEASE',
                    'ACTION:INV-ISS:CREATE',
                    'ACTION:INV-ISS:UPDATE',
                    'ACTION:INV-ISS:POST',
                    'ACTION:INV-ISS:CANCEL',
                    'ACTION:INV-TRF:CREATE',
                    'ACTION:INV-TRF:UPDATE',
                    'ACTION:INV-TRF:POST',
                    'ACTION:INV-TRF:CANCEL',
                    'ACTION:INV-COUNT:CREATE',
                    'ACTION:INV-COUNT:UPDATE',
                    'ACTION:INV-COUNT:POST',
                    'ACTION:INV-COUNT:CANCEL',
                    'ACTION:INV-EXP:CREATE',
                    'ACTION:INV-EXP:UPDATE',
                    'ACTION:INV-EXP:POST',
                    'ACTION:INV-EXP:CANCEL',
                    'ACTION:PLAN-DEM:CREATE',
                    'ACTION:PLAN-DEM:UPDATE',
                    'ACTION:PLAN-DEM:RELEASE',
                    'ACTION:PLAN-DEM:CANCEL',
                    'ACTION:PLAN-MRP:RUN',
                    'ACTION:PLAN-MRP:CANCEL',
                    'ACTION:PLAN-SCH:CREATE',
                    'ACTION:PLAN-SCH:UPDATE',
                    'ACTION:PLAN-SCH:RELEASE',
                    'ACTION:PLAN-SCH:CANCEL',
                    'ACTION:PRO-ORDER:CREATE',
                    'ACTION:PRO-ORDER:RELEASE',
                    'ACTION:PRO-ORDER:ISSUE',
                    'ACTION:PRO-ORDER:COMPLETE',
                    'ACTION:PRO-ORDER:CANCEL',
                    'ACTION:PRO-STAGE:START',
                    'ACTION:PRO-STAGE:COMPLETE',
                    'ACTION:PRO-LOSS:RECORD',
                    'ACTION:PRO-LOSS:RESOLVE',
                    'ACTION:QC-LAB:CREATE',
                    'ACTION:QC-LAB:COMPLETE',
                    'ACTION:QC-SAFE:HOLD',
                    'ACTION:QC-SAFE:RELEASE-HOLD',
                    'ACTION:QC-SAFE:RESOLVE',
                    'ACTION:QC-SAFE:RELEASE',
                    'ACTION:PACK-ART:CREATE',
                    'ACTION:PACK-ART:UPDATE',
                    'ACTION:PACK-ART:APPROVE',
                    'ACTION:PACK-ART:RETIRE',
                    'ACTION:PACK-RUN:CREATE',
                    'ACTION:PACK-RUN:COMPLETE',
                    'ACTION:PACK-RUN:CANCEL',
                    'ACTION:TRACE-CASE:CREATE',
                    'ACTION:TRACE-CASE:CLOSE',
                    'ACTION:COST-BATCH:CALCULATE',
                    'ACTION:RET-UNSOLD:RECEIVE',
                    'ACTION:RET-UNSOLD:DISPOSITION',
                    'ACTION:RET-UNSOLD:EVIDENCE',
                ],
                'FINANCE_REVIEWER' => [
                    'ACTION:WRK-HOME:CLAIM',
                    'ACTION:WRK-HOME:COMPLETE',
                    'ACTION:PUR-REQ:APPROVE',
                    'ACTION:FIN-AP:INVOICE-CREATE',
                    'ACTION:FIN-AP:INVOICE-UPDATE',
                    'ACTION:FIN-AP:MATCH',
                    'ACTION:FIN-AP:INVOICE-APPROVE',
                    'ACTION:FIN-AP:INVOICE-CANCEL',
                    'ACTION:FIN-AP:PROPOSAL-CREATE',
                    'ACTION:FIN-AP:PROPOSAL-UPDATE',
                    'ACTION:FIN-AP:PROPOSAL-APPROVE',
                    'ACTION:FIN-AP:PROPOSAL-CANCEL',
                    'ACTION:FIN-AP:PAY',
                    'ACTION:FIN-AP:RECONCILE',
                    'ACTION:RET-UNSOLD:APPROVE',
                    'ACTION:RET-UNSOLD:POST-LOSS',
                    'ACTION:RET-UNSOLD:FINANCE',
                    'ACTION:RET-UNSOLD:EVIDENCE',
                ],
                'PARTNER_PORTAL' => [
                    'ACTION:PORTAL-EXT:CLAIM-CREATE',
                    'ACTION:PORTAL-EXT:DOCUMENT-UPLOAD',
                    'ACTION:PORTAL-EXT:DOCUMENT-DOWNLOAD',
                    'ACTION:PORTAL-EXT:DOCUMENT-ACKNOWLEDGE',
                ],
                'ERP_ADMIN' => [
                    'ACTION:WRK-HOME:CLAIM',
                    'ACTION:WRK-HOME:COMPLETE',
                    'ACTION:WRK-HOME:MANAGE',
                    'ACTION:PUR-REQ:CREATE',
                    'ACTION:PUR-REQ:UPDATE',
                    'ACTION:PUR-REQ:SUBMIT',
                    'ACTION:PUR-REQ:CANCEL',
                    'ACTION:PUR-REQ:APPROVE',
                    'ACTION:PUR-REQ:APPROVE-HIGH',
                    'ACTION:PUR-REQ:APPROVE-ESCALATED',
                    'ACTION:PUR-RFQ:CREATE',
                    'ACTION:PUR-RFQ:UPDATE',
                    'ACTION:PUR-RFQ:ISSUE',
                    'ACTION:PUR-RFQ:QUOTE',
                    'ACTION:PUR-RFQ:AWARD',
                    'ACTION:PUR-RFQ:CANCEL',
                    'ACTION:PUR-PO:CREATE',
                    'ACTION:PUR-PO:AMEND',
                    'ACTION:PUR-PO:ISSUE',
                    'ACTION:PUR-PO:CANCEL',
                    'ACTION:INB-GATE:CREATE',
                    'ACTION:INB-GATE:UPDATE',
                    'ACTION:INB-GATE:CANCEL',
                    'ACTION:INB-GRN:CREATE',
                    'ACTION:INB-GRN:UPDATE',
                    'ACTION:INB-GRN:POST',
                    'ACTION:INB-GRN:CANCEL',
                    'ACTION:QC-IN:COMPLETE',
                    'ACTION:INB-RETURN:CREATE',
                    'ACTION:INB-RETURN:UPDATE',
                    'ACTION:INB-RETURN:POST',
                    'ACTION:INB-RETURN:CANCEL',
                    'ACTION:FIN-AP:INVOICE-CREATE',
                    'ACTION:FIN-AP:INVOICE-UPDATE',
                    'ACTION:FIN-AP:MATCH',
                    'ACTION:FIN-AP:INVOICE-APPROVE',
                    'ACTION:FIN-AP:INVOICE-CANCEL',
                    'ACTION:FIN-AP:PROPOSAL-CREATE',
                    'ACTION:FIN-AP:PROPOSAL-UPDATE',
                    'ACTION:FIN-AP:PROPOSAL-APPROVE',
                    'ACTION:FIN-AP:PROPOSAL-CANCEL',
                    'ACTION:FIN-AP:PAY',
                    'ACTION:FIN-AP:RECONCILE',
                    'ACTION:ADM-ORG:CREATE',
                    'ACTION:ADM-ORG:UPDATE',
                    'ACTION:ADM-LOC:CREATE',
                    'ACTION:ADM-LOC:UPDATE',
                    'ACTION:ADM-USER:CREATE',
                    'ACTION:ADM-USER:UPDATE',
                    'ACTION:ADM-USER:ASSIGN',
                    'ACTION:ADM-USER:INVITE',
                    'ACTION:ADM-USER:SESSIONS',
                    'ACTION:ADM-ROLE:CREATE',
                    'ACTION:ADM-ROLE:UPDATE',
                    'ACTION:ADM-ROLE:PERMISSIONS',
                    'ACTION:ADM-RULE:CREATE',
                    'ACTION:ADM-RULE:UPDATE',
                    'ACTION:ADM-RULE:DELEGATE',
                    'ACTION:ADM-RULE:ESCALATE',
                    'ACTION:ADM-AUD:EVIDENCE',
                    'ACTION:ADM-INT:PROCESS',
                    'ACTION:ADM-INT:RETRY',
                    'ACTION:ADM-INT:QUARANTINE',
                    'ACTION:MD-PARTY:CREATE',
                    'ACTION:MD-PARTY:UPDATE',
                    'ACTION:MD-PARTY:LIFECYCLE',
                    'ACTION:MD-BRAND:CREATE',
                    'ACTION:MD-BRAND:UPDATE',
                    'ACTION:MD-BRAND:LIFECYCLE',
                    'ACTION:MD-ITEM:CREATE',
                    'ACTION:MD-ITEM:UPDATE',
                    'ACTION:MD-ITEM:LIFECYCLE',
                    'ACTION:MD-SKU:CREATE',
                    'ACTION:MD-SKU:UPDATE',
                    'ACTION:MD-SKU:LIFECYCLE',
                    'ACTION:MD-REC:CREATE',
                    'ACTION:MD-REC:UPDATE',
                    'ACTION:MD-REC:LIFECYCLE',
                    'ACTION:MD-ROUTE:CREATE',
                    'ACTION:MD-ROUTE:UPDATE',
                    'ACTION:MD-ROUTE:LIFECYCLE',
                    'ACTION:MD-SPEC:CREATE',
                    'ACTION:MD-SPEC:UPDATE',
                    'ACTION:MD-SPEC:LIFECYCLE',
                    'ACTION:INV-STK:OWNER-CREATE',
                    'ACTION:INV-STK:OWNER-UPDATE',
                    'ACTION:INV-STK:OWNER-LIFECYCLE',
                    'ACTION:INV-STK:LOT-CREATE',
                    'ACTION:INV-STK:LOT-UPDATE',
                    'ACTION:INV-STK:LOT-LIFECYCLE',
                    'ACTION:INV-STK:RESERVE',
                    'ACTION:INV-STK:RELEASE',
                    'ACTION:INV-ISS:CREATE',
                    'ACTION:INV-ISS:UPDATE',
                    'ACTION:INV-ISS:POST',
                    'ACTION:INV-ISS:CANCEL',
                    'ACTION:INV-TRF:CREATE',
                    'ACTION:INV-TRF:UPDATE',
                    'ACTION:INV-TRF:POST',
                    'ACTION:INV-TRF:CANCEL',
                    'ACTION:INV-COUNT:CREATE',
                    'ACTION:INV-COUNT:UPDATE',
                    'ACTION:INV-COUNT:POST',
                    'ACTION:INV-COUNT:CANCEL',
                    'ACTION:INV-EXP:CREATE',
                    'ACTION:INV-EXP:UPDATE',
                    'ACTION:INV-EXP:POST',
                    'ACTION:INV-EXP:CANCEL',
                    'ACTION:PLAN-DEM:CREATE',
                    'ACTION:PLAN-DEM:UPDATE',
                    'ACTION:PLAN-DEM:RELEASE',
                    'ACTION:PLAN-DEM:CANCEL',
                    'ACTION:PLAN-MRP:RUN',
                    'ACTION:PLAN-MRP:CANCEL',
                    'ACTION:PLAN-SCH:CREATE',
                    'ACTION:PLAN-SCH:UPDATE',
                    'ACTION:PLAN-SCH:RELEASE',
                    'ACTION:PLAN-SCH:CANCEL',
                    'ACTION:PRO-ORDER:CREATE',
                    'ACTION:PRO-ORDER:RELEASE',
                    'ACTION:PRO-ORDER:ISSUE',
                    'ACTION:PRO-ORDER:COMPLETE',
                    'ACTION:PRO-ORDER:CANCEL',
                    'ACTION:PRO-STAGE:START',
                    'ACTION:PRO-STAGE:COMPLETE',
                    'ACTION:PRO-LOSS:RECORD',
                    'ACTION:PRO-LOSS:RESOLVE',
                    'ACTION:QC-LAB:CREATE',
                    'ACTION:QC-LAB:COMPLETE',
                    'ACTION:QC-SAFE:HOLD',
                    'ACTION:QC-SAFE:RELEASE-HOLD',
                    'ACTION:QC-SAFE:RESOLVE',
                    'ACTION:QC-SAFE:RELEASE',
                    'ACTION:PACK-ART:CREATE',
                    'ACTION:PACK-ART:UPDATE',
                    'ACTION:PACK-ART:APPROVE',
                    'ACTION:PACK-ART:RETIRE',
                    'ACTION:PACK-RUN:CREATE',
                    'ACTION:PACK-RUN:COMPLETE',
                    'ACTION:PACK-RUN:CANCEL',
                    'ACTION:TRACE-CASE:CREATE',
                    'ACTION:TRACE-CASE:CLOSE',
                    'ACTION:COST-BATCH:CALCULATE',
                    'ACTION:RET-UNSOLD:CREATE',
                    'ACTION:RET-UNSOLD:RECEIVE',
                    'ACTION:RET-UNSOLD:DISPOSITION',
                    'ACTION:RET-UNSOLD:APPROVE',
                    'ACTION:RET-UNSOLD:APPROVE-HIGH',
                    'ACTION:RET-UNSOLD:APPROVE-ESCALATED',
                    'ACTION:RET-UNSOLD:POST-LOSS',
                    'ACTION:RET-UNSOLD:FINANCE',
                    'ACTION:RET-UNSOLD:EVIDENCE',
                ],
            ];

            $p2SalesActions = [
                'ACTION:CRM-LEAD:CREATE', 'ACTION:CRM-LEAD:UPDATE', 'ACTION:CRM-LEAD:QUALIFY',
                'ACTION:CRM-LEAD:CONVERT', 'ACTION:CRM-LEAD:CLOSE',
                'ACTION:CRM-PRICE:CREATE', 'ACTION:CRM-PRICE:UPDATE', 'ACTION:CRM-PRICE:ACTIVATE',
                'ACTION:CRM-PRICE:RETIRE',
                'ACTION:CRM-ORDER:CREATE', 'ACTION:CRM-ORDER:UPDATE', 'ACTION:CRM-ORDER:CONFIRM',
                'ACTION:CRM-ORDER:AMEND', 'ACTION:CRM-ORDER:CANCEL',
                'ACTION:CON-WORK:CREATE', 'ACTION:CON-WORK:RELEASE', 'ACTION:CON-WORK:COMPLETE',
                'ACTION:CON-WORK:CANCEL',
                'ACTION:DSP-PICK:ALLOCATE', 'ACTION:DSP-PICK:PICK', 'ACTION:DSP-PICK:CANCEL',
                'ACTION:DSP-LOAD:CREATE', 'ACTION:DSP-LOAD:LOAD', 'ACTION:DSP-LOAD:DISPATCH',
                'ACTION:DSP-LOAD:CANCEL', 'ACTION:DSP-POD:COMPLETE',
                'ACTION:RET-CASE:CREATE', 'ACTION:RET-CASE:RECEIVE', 'ACTION:RET-CASE:RESOLVE',
                'ACTION:FIN-AR:COLLECT',
            ];
            $p2OperationsActions = [
                'ACTION:DSP-PICK:ALLOCATE', 'ACTION:DSP-PICK:PICK', 'ACTION:DSP-PICK:CANCEL',
                'ACTION:DSP-LOAD:CREATE', 'ACTION:DSP-LOAD:LOAD', 'ACTION:DSP-LOAD:DISPATCH',
                'ACTION:DSP-LOAD:CANCEL', 'ACTION:DSP-POD:COMPLETE',
                'ACTION:RET-CASE:RECEIVE',
                'ACTION:ENG-MNT:CREATE', 'ACTION:ENG-MNT:UPDATE', 'ACTION:ENG-MNT:RELEASE',
                'ACTION:ENG-MNT:COMPLETE', 'ACTION:ENG-MNT:CANCEL',
            ];
            $p2FinanceActions = [
                'ACTION:CRM-PRICE:CREDIT', 'ACTION:FIN-AR:COLLECT',
                'ACTION:FIN-EXP:CREATE', 'ACTION:FIN-EXP:UPDATE', 'ACTION:FIN-EXP:SUBMIT',
                'ACTION:FIN-EXP:APPROVE', 'ACTION:FIN-EXP:POST', 'ACTION:FIN-EXP:CANCEL',
                'ACTION:FIN-GL:JOURNAL-CREATE', 'ACTION:FIN-GL:JOURNAL-POST',
                'ACTION:FIN-GL:JOURNAL-REVERSE', 'ACTION:FIN-GL:PERIOD-CLOSE',
                'ACTION:COST-OH:CREATE', 'ACTION:COST-OH:UPDATE', 'ACTION:COST-OH:ALLOCATE',
                'ACTION:ASSET-REG:CREATE', 'ACTION:ASSET-REG:UPDATE', 'ACTION:ASSET-REG:ACTIVATE',
                'ACTION:ASSET-REG:DEPRECIATE', 'ACTION:ASSET-REG:DISPOSE',
                'ACTION:HR-PAY:EMPLOYEE-CREATE', 'ACTION:HR-PAY:EMPLOYEE-UPDATE',
                'ACTION:HR-PAY:RUN-CREATE', 'ACTION:HR-PAY:APPROVE', 'ACTION:HR-PAY:POST',
                'ACTION:ENG-MNT:COMPLETE',
                'ACTION:FIN-AP:BANK-MANAGE', 'ACTION:FIN-AP:EXPORT', 'ACTION:FIN-AP:ACK',
                'ACTION:FIN-SIM:CREATE', 'ACTION:FIN-SIM:RUN',
                'ACTION:FIN-ADJ:CREATE', 'ACTION:FIN-ADJ:SUBMIT', 'ACTION:FIN-ADJ:APPROVE',
                'ACTION:FIN-ADJ:POST', 'ACTION:FIN-ADJ:CANCEL',
                'ACTION:FIN-LEGACY:CREATE', 'ACTION:FIN-LEGACY:VALIDATE', 'ACTION:FIN-LEGACY:POST',
                'ACTION:FIN-ARCH:UPLOAD', 'ACTION:FIN-ARCH:DOWNLOAD',
                'ACTION:FIN-OPEN:CREATE', 'ACTION:FIN-OPEN:RECONCILE', 'ACTION:FIN-OPEN:POST',
                'ACTION:FIN-SUP:CREATE', 'ACTION:FIN-SUP:DIAGNOSE', 'ACTION:FIN-SUP:CLOSE',
            ];
            $p3TransferActions = [
                'ACTION:SCALE-PLANT:TRANSFER-CREATE', 'ACTION:SCALE-PLANT:TRANSFER-UPDATE',
                'ACTION:SCALE-PLANT:TRANSFER-SUBMIT', 'ACTION:SCALE-PLANT:TRANSFER-DISPATCH',
                'ACTION:SCALE-PLANT:TRANSFER-RECEIVE', 'ACTION:SCALE-PLANT:TRANSFER-CANCEL',
            ];
            $p3ConsolidationActions = [
                'ACTION:SCALE-PLANT:CONSOLIDATE', 'ACTION:SCALE-PLANT:CONSOLIDATION-FINALIZE',
            ];
            $p3AdministrationActions = [
                'ACTION:SCALE-PLANT:GROUP-CREATE', 'ACTION:SCALE-PLANT:GROUP-UPDATE',
                'ACTION:SCALE-PLANT:GROUP-ACTIVATE', 'ACTION:SCALE-PLANT:GROUP-RETIRE',
                'ACTION:SCALE-PLANT:ROUTE-CREATE', 'ACTION:SCALE-PLANT:ROUTE-UPDATE',
                'ACTION:SCALE-PLANT:ROUTE-ACTIVATE', 'ACTION:SCALE-PLANT:ROUTE-DEACTIVATE',
                'ACTION:SCALE-PLANT:TRANSFER-APPROVE', 'ACTION:SCALE-PLANT:TRANSFER-ACCEPT',
            ];
            $p3OptimisationMakerActions = [
                'ACTION:OPT-PLAN:CREATE', 'ACTION:OPT-PLAN:REVISE',
                'ACTION:OPT-PLAN:GENERATE', 'ACTION:OPT-PLAN:SUBMIT',
                'ACTION:OPT-PLAN:OUTCOME', 'ACTION:OPT-PLAN:COMPLETE',
                'ACTION:OPT-PLAN:CANCEL',
            ];
            $p3OptimisationReviewActions = [
                'ACTION:OPT-PLAN:APPROVE', 'ACTION:OPT-PLAN:REJECT',
            ];
            $helpUserActions = [
                'ACTION:ADM-HELP:CREATE', 'ACTION:ADM-HELP:COMMENT',
                'ACTION:ADM-HELP:REOPEN', 'ACTION:ADM-HELP:CLOSE',
            ];
            $reportActions = ['ACTION:BI-REP:RUN', 'ACTION:BI-REP:EXPORT'];
            $roleActions['SALES_MANAGER'] = array_values(array_unique([
                ...$roleActions['SALES_MANAGER'], ...array_values(array_filter($p2SalesActions, fn (string $action) => ! str_starts_with($action, 'ACTION:DSP-')
                    && $action !== 'ACTION:RET-CASE:RECEIVE')),
                'ACTION:DSP-PICK:ALLOCATE', ...$helpUserActions,
            ]));
            $roleActions['OPERATIONS_MANAGER'] = array_values(array_unique([
                ...$roleActions['OPERATIONS_MANAGER'], ...$p2OperationsActions, ...$p3TransferActions,
                ...$p3OptimisationMakerActions, ...$helpUserActions,
            ]));
            $roleActions['FINANCE_REVIEWER'] = array_values(array_unique([
                ...$roleActions['FINANCE_REVIEWER'], ...$p2FinanceActions, ...$p3ConsolidationActions,
                ...$p3OptimisationReviewActions, ...$helpUserActions, ...$reportActions,
            ]));
            $roleActions['ERP_ADMIN'] = array_values(array_unique([
                ...$roleActions['ERP_ADMIN'], ...$p2SalesActions, ...$p2OperationsActions, ...$p2FinanceActions,
                ...$p3TransferActions, ...$p3ConsolidationActions, ...$p3AdministrationActions,
                ...$p3OptimisationMakerActions, ...$p3OptimisationReviewActions, ...$helpUserActions, ...$reportActions,
                'ACTION:ADM-HELP:MANAGE',
                'ACTION:CRM-PRICE:CREDIT',
                'ACTION:PORTAL-EXT:ACCESS-GRANT', 'ACTION:PORTAL-EXT:ACCESS-UPDATE',
                'ACTION:PORTAL-EXT:ACCESS-REVOKE', 'ACTION:PORTAL-EXT:DOCUMENT-PUBLISH',
                'ACTION:PORTAL-EXT:DOCUMENT-DOWNLOAD', 'ACTION:PORTAL-EXT:DOCUMENT-WITHDRAW',
            ]));

            foreach ($roleActions as $roleCode => $permissions) {
                $rows = [];
                foreach ($permissions as $permissionCode) {
                    $permissionId = DB::table('permissions')->where('code', $permissionCode)->value('id')
                        ?: (string) Str::uuid();

                    DB::table('permissions')->updateOrInsert(['code' => $permissionCode], [
                        'id' => $permissionId,
                        'name' => str_replace(['ACTION:', ':', '-'], ['', ' ', ' '], $permissionCode),
                        'company_id' => null,
                        'description' => null,
                        'status' => 'ACTIVE',
                        'is_system' => true,
                        'record_version' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $rows[] = [
                        'role_id' => $roles[$roleCode][0],
                        'permission_id' => $permissionId,
                    ];
                }
                DB::table('role_permissions')->insert($rows);
            }

            $assignments = [
                ['00000000-0000-4000-8000-000000000401', $users['sales'][0], $roles['SALES_MANAGER'][0], $trainingPlantId],
                ['00000000-0000-4000-8000-000000000402', $users['operations'][0], $roles['OPERATIONS_MANAGER'][0], $trainingPlantId],
                ['00000000-0000-4000-8000-000000000403', $users['finance'][0], $roles['FINANCE_REVIEWER'][0], $financePlantId],
                ['00000000-0000-4000-8000-000000000404', $users['admin'][0], $roles['ERP_ADMIN'][0], $trainingPlantId],
                ['00000000-0000-4000-8000-000000000405', $users['admin'][0], $roles['ERP_ADMIN'][0], $financePlantId],
                ['00000000-0000-4000-8000-000000000406', $users['finance'][0], $roles['FINANCE_REVIEWER'][0], $trainingPlantId],
            ];

            foreach ($assignments as [$id, $userId, $roleId, $plantId]) {
                DB::table('role_assignments')->updateOrInsert(['id' => $id], [
                    'user_id' => $userId,
                    'role_id' => $roleId,
                    'company_id' => $companyId,
                    'plant_id' => $plantId,
                    'party_id' => null,
                    'is_active' => true,
                    'effective_from' => null,
                    'effective_to' => null,
                    'record_version' => 1,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }

            foreach ([
                [$trainingPlantId, '00000000-0000-4000-8000-000000001421', [
                    '00000000-0000-4000-8000-000000001521',
                    '00000000-0000-4000-8000-000000001522',
                ]],
                [$financePlantId, '00000000-0000-4000-8000-000000001422', [
                    '00000000-0000-4000-8000-000000001523',
                    '00000000-0000-4000-8000-000000001524',
                ]],
            ] as [$plantId, $defaultRuleId, $bandIds]) {
                $scopeKey = 'PLANT:'.$plantId;
                $ruleId = DB::table('approval_rules')->where('scope_key', $scopeKey)
                    ->where('code', 'UNSOLD_RETURN_LOSS_APPROVAL')->value('id');
                if ($ruleId) {
                    continue;
                }
                $ruleId = $defaultRuleId;
                DB::table('approval_rules')->insert([
                    'scope_key' => $scopeKey,
                    'code' => 'UNSOLD_RETURN_LOSS_APPROVAL',
                    'id' => $ruleId,
                    'company_id' => $companyId,
                    'plant_id' => $plantId,
                    'name' => 'Unsold return loss approval',
                    'description' => 'Plant policy routing loss disposition by destroyed base quantity.',
                    'entity_type' => 'unsold_return_loss',
                    'authority_metric' => 'DESTROY_QUANTITY',
                    'authority_uom' => 'BASE',
                    'status' => 'ACTIVE',
                    'is_system' => false,
                    'record_version' => 1,
                    'created_by' => $users['admin'][0],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('approval_rule_bands')->insert([
                    [
                        'id' => $bandIds[0],
                        'approval_rule_id' => $ruleId,
                        'sequence' => 1,
                        'name' => 'Standard loss authority',
                        'minimum_value' => 0,
                        'maximum_value' => 100,
                        'required_permission' => 'ACTION:RET-UNSOLD:APPROVE',
                        'escalation_permission' => 'ACTION:RET-UNSOLD:APPROVE-ESCALATED',
                        'work_priority' => 'HIGH',
                        'due_hours' => 24,
                        'escalate_after_hours' => 24,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    [
                        'id' => $bandIds[1],
                        'approval_rule_id' => $ruleId,
                        'sequence' => 2,
                        'name' => 'High loss authority',
                        'minimum_value' => 100,
                        'maximum_value' => null,
                        'required_permission' => 'ACTION:RET-UNSOLD:APPROVE-HIGH',
                        'escalation_permission' => 'ACTION:RET-UNSOLD:APPROVE-ESCALATED',
                        'work_priority' => 'URGENT',
                        'due_hours' => 12,
                        'escalate_after_hours' => 12,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                ]);
            }

            foreach ([
                [$trainingPlantId, '00000000-0000-4000-8000-000000001423', [
                    '00000000-0000-4000-8000-000000001525',
                    '00000000-0000-4000-8000-000000001526',
                ]],
                [$financePlantId, '00000000-0000-4000-8000-000000001424', [
                    '00000000-0000-4000-8000-000000001527',
                    '00000000-0000-4000-8000-000000001528',
                ]],
            ] as [$plantId, $defaultRuleId, $bandIds]) {
                $scopeKey = 'PLANT:'.$plantId;
                $ruleId = DB::table('approval_rules')->where('scope_key', $scopeKey)
                    ->where('code', 'PURCHASE_REQUISITION_APPROVAL')->value('id');
                if ($ruleId) {
                    continue;
                }
                $ruleId = $defaultRuleId;
                DB::table('approval_rules')->insert([
                    'scope_key' => $scopeKey,
                    'code' => 'PURCHASE_REQUISITION_APPROVAL',
                    'id' => $ruleId,
                    'company_id' => $companyId,
                    'plant_id' => $plantId,
                    'name' => 'Purchase requisition approval',
                    'description' => 'Plant policy routing purchase requisitions by estimated order value.',
                    'entity_type' => 'purchase_requisition',
                    'authority_metric' => 'ESTIMATED_TOTAL',
                    'authority_uom' => 'INR',
                    'status' => 'ACTIVE',
                    'is_system' => false,
                    'record_version' => 1,
                    'created_by' => $users['admin'][0],
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
                DB::table('approval_rule_bands')->insert([
                    [
                        'id' => $bandIds[0],
                        'approval_rule_id' => $ruleId,
                        'sequence' => 1,
                        'name' => 'Standard requisition authority',
                        'minimum_value' => 0,
                        'maximum_value' => 100000,
                        'required_permission' => 'ACTION:PUR-REQ:APPROVE',
                        'escalation_permission' => 'ACTION:PUR-REQ:APPROVE-ESCALATED',
                        'work_priority' => 'HIGH',
                        'due_hours' => 24,
                        'escalate_after_hours' => 24,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    [
                        'id' => $bandIds[1],
                        'approval_rule_id' => $ruleId,
                        'sequence' => 2,
                        'name' => 'High-value requisition authority',
                        'minimum_value' => 100000,
                        'maximum_value' => null,
                        'required_permission' => 'ACTION:PUR-REQ:APPROVE-HIGH',
                        'escalation_permission' => 'ACTION:PUR-REQ:APPROVE-ESCALATED',
                        'work_priority' => 'URGENT',
                        'due_hours' => 12,
                        'escalate_after_hours' => 12,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                ]);
            }

            $this->call(UnsoldReturnReferenceSeeder::class);
            $this->call(ProcurementReferenceSeeder::class);
            $this->call(P2ReferenceSeeder::class);
            $this->call(ScaleReferenceSeeder::class);
            $this->call(PortalReferenceSeeder::class);
            $this->call(HelpReferenceSeeder::class);
        });
    }
}

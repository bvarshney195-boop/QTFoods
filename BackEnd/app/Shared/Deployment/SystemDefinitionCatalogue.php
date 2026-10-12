<?php

namespace App\Shared\Deployment;

final class SystemDefinitionCatalogue
{
    /**
     * Approved global system roles. IDs are stable for fresh installations;
     * an existing global system role is retained by code.
     *
     * @return array<string, array{id:string,name:string,description:string}>
     */
    public static function roles(): array
    {
        return [
            'SALES_MANAGER' => [
                'id' => '00000000-0000-4000-8000-000000000301',
                'name' => 'Sales Manager',
                'description' => 'Customer, pricing, order, delivery, claim, and receivable work.',
            ],
            'OPERATIONS_MANAGER' => [
                'id' => '00000000-0000-4000-8000-000000000302',
                'name' => 'Operations Manager',
                'description' => 'Purchasing, stock, production, quality, packing, maintenance, and warehouse work.',
            ],
            'FINANCE_REVIEWER' => [
                'id' => '00000000-0000-4000-8000-000000000303',
                'name' => 'Finance Manager',
                'description' => 'Approvals, supplier and customer accounts, accounting, payroll, reporting, and finance controls.',
            ],
            'ERP_ADMIN' => [
                'id' => '00000000-0000-4000-8000-000000000304',
                'name' => 'ERP Administrator',
                'description' => 'Organisation setup, users, roles, controls, support, and full-system administration.',
            ],
            'PARTNER_PORTAL' => [
                'id' => '00000000-0000-4000-8000-000000000305',
                'name' => 'Partner User',
                'description' => 'Access only to records and documents explicitly shared with the assigned customer.',
            ],
            'BI_ANALYST' => [
                'id' => '00000000-0000-4000-8000-000000000306',
                'name' => 'BI Analyst',
                'description' => 'Read-only business intelligence access to controlled reports and order profitability, with permissioned report generation and export.',
            ],
        ];
    }

    /** @return list<string> */
    public static function screens(): array
    {
        return [
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
    }

    /**
     * Exact approved permission assignment for every protected system role.
     *
     * @return array<string, list<string>>
     */
    public static function permissionsByRole(): array
    {
        $allScreens = self::screens();
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
            'BI_ANALYST' => ['BI-REP', 'BI-PROFIT'],
        ];

        $roleActions = self::actionsByRole();
        $permissions = [];
        foreach (array_keys(self::roles()) as $roleCode) {
            $screenPermissions = array_map(
                static fn (string $screen): string => "SCREEN:{$screen}:VIEW",
                $roleScreens[$roleCode],
            );
            $permissions[$roleCode] = array_values(array_unique([
                ...$screenPermissions,
                ...$roleActions[$roleCode],
            ]));
        }

        return $permissions;
    }

    /**
     * @return array<string, array{name:string,description:null}>
     */
    public static function permissions(): array
    {
        $definitions = [];
        foreach (self::permissionsByRole() as $permissionCodes) {
            foreach ($permissionCodes as $code) {
                $definitions[$code] ??= [
                    'name' => str_starts_with($code, 'SCREEN:')
                        ? 'View '.explode(':', $code, 3)[1]
                        : str_replace(['ACTION:', ':', '-'], ['', ' ', ' '], $code),
                    'description' => null,
                ];
            }
        }
        ksort($definitions);

        return $definitions;
    }

    /** @return array<string, list<string>> */
    private static function actionsByRole(): array
    {
        $sales = [
            'ACTION:WRK-HOME:CLAIM',
            'ACTION:WRK-HOME:COMPLETE',
            'ACTION:WRK-HOME:PERSONALISE',
            'ACTION:RET-UNSOLD:CREATE',
            'ACTION:RET-UNSOLD:EVIDENCE',
        ];
        $operations = [
            'ACTION:WRK-HOME:CLAIM',
            'ACTION:WRK-HOME:COMPLETE',
            'ACTION:WRK-HOME:PERSONALISE',
            'ACTION:WRK-HOME:IMPORT',
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
        ];
        $finance = [
            'ACTION:WRK-HOME:CLAIM',
            'ACTION:WRK-HOME:COMPLETE',
            'ACTION:WRK-HOME:PERSONALISE',
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
        ];
        $partner = [
            'ACTION:PORTAL-EXT:CLAIM-CREATE',
            'ACTION:PORTAL-EXT:DOCUMENT-UPLOAD',
            'ACTION:PORTAL-EXT:DOCUMENT-DOWNLOAD',
            'ACTION:PORTAL-EXT:DOCUMENT-ACKNOWLEDGE',
        ];

        $p2Sales = [
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
        $p2Operations = [
            'ACTION:DSP-PICK:ALLOCATE', 'ACTION:DSP-PICK:PICK', 'ACTION:DSP-PICK:CANCEL',
            'ACTION:DSP-LOAD:CREATE', 'ACTION:DSP-LOAD:LOAD', 'ACTION:DSP-LOAD:DISPATCH',
            'ACTION:DSP-LOAD:CANCEL', 'ACTION:DSP-POD:COMPLETE',
            'ACTION:RET-CASE:RECEIVE',
            'ACTION:ENG-MNT:CREATE', 'ACTION:ENG-MNT:UPDATE', 'ACTION:ENG-MNT:RELEASE',
            'ACTION:ENG-MNT:COMPLETE', 'ACTION:ENG-MNT:CANCEL',
        ];
        $p2Finance = [
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
        $p3Transfer = [
            'ACTION:SCALE-PLANT:TRANSFER-CREATE', 'ACTION:SCALE-PLANT:TRANSFER-UPDATE',
            'ACTION:SCALE-PLANT:TRANSFER-SUBMIT', 'ACTION:SCALE-PLANT:TRANSFER-DISPATCH',
            'ACTION:SCALE-PLANT:TRANSFER-RECEIVE', 'ACTION:SCALE-PLANT:TRANSFER-CANCEL',
        ];
        $p3Consolidation = [
            'ACTION:SCALE-PLANT:CONSOLIDATE', 'ACTION:SCALE-PLANT:CONSOLIDATION-FINALIZE',
        ];
        $p3Administration = [
            'ACTION:SCALE-PLANT:GROUP-CREATE', 'ACTION:SCALE-PLANT:GROUP-UPDATE',
            'ACTION:SCALE-PLANT:GROUP-ACTIVATE', 'ACTION:SCALE-PLANT:GROUP-RETIRE',
            'ACTION:SCALE-PLANT:ROUTE-CREATE', 'ACTION:SCALE-PLANT:ROUTE-UPDATE',
            'ACTION:SCALE-PLANT:ROUTE-ACTIVATE', 'ACTION:SCALE-PLANT:ROUTE-DEACTIVATE',
            'ACTION:SCALE-PLANT:TRANSFER-APPROVE', 'ACTION:SCALE-PLANT:TRANSFER-ACCEPT',
        ];
        $optimisationMaker = [
            'ACTION:OPT-PLAN:CREATE', 'ACTION:OPT-PLAN:REVISE',
            'ACTION:OPT-PLAN:GENERATE', 'ACTION:OPT-PLAN:SUBMIT',
            'ACTION:OPT-PLAN:OUTCOME', 'ACTION:OPT-PLAN:COMPLETE',
            'ACTION:OPT-PLAN:CANCEL',
        ];
        $optimisationReview = ['ACTION:OPT-PLAN:APPROVE', 'ACTION:OPT-PLAN:REJECT'];
        $helpUser = [
            'ACTION:ADM-HELP:CREATE', 'ACTION:ADM-HELP:COMMENT',
            'ACTION:ADM-HELP:REOPEN', 'ACTION:ADM-HELP:CLOSE',
        ];
        $reports = ['ACTION:BI-REP:RUN', 'ACTION:BI-REP:EXPORT'];
        $adminOnly = [
            'ACTION:WRK-HOME:MANAGE',
            'ACTION:PUR-REQ:APPROVE-HIGH',
            'ACTION:PUR-REQ:APPROVE-ESCALATED',
            'ACTION:ADM-ORG:CREATE',
            'ACTION:ADM-ORG:UPDATE',
            'ACTION:ADM-LOC:CREATE',
            'ACTION:ADM-LOC:UPDATE',
            'ACTION:ADM-USER:CREATE',
            'ACTION:ADM-USER:UPDATE',
            'ACTION:ADM-USER:ASSIGN',
            'ACTION:ADM-USER:INVITE',
            'ACTION:ADM-USER:SESSIONS',
            'ACTION:ADM-USER:PASSWORD-RESET',
            'ACTION:ADM-USER:DELETE',
            'ACTION:ADM-USER:MFA',
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
            'ACTION:RET-UNSOLD:APPROVE-HIGH',
            'ACTION:RET-UNSOLD:APPROVE-ESCALATED',
        ];

        $sales = self::unique([
            ...$sales,
            ...array_values(array_filter(
                $p2Sales,
                static fn (string $action): bool => ! str_starts_with($action, 'ACTION:DSP-')
                    && $action !== 'ACTION:RET-CASE:RECEIVE',
            )),
            'ACTION:DSP-PICK:ALLOCATE',
            ...$helpUser,
        ]);
        $operations = self::unique([
            ...$operations, ...$p2Operations, ...$p3Transfer, ...$optimisationMaker, ...$helpUser,
        ]);
        $finance = self::unique([
            ...$finance, ...$p2Finance, ...$p3Consolidation, ...$optimisationReview, ...$helpUser, ...$reports,
            'ACTION:BI-PROFIT:COST-BACKFILL',
        ]);
        $admin = self::unique([
            ...$sales,
            ...$operations,
            ...$finance,
            ...$p2Sales,
            ...$p2Operations,
            ...$p2Finance,
            ...$p3Transfer,
            ...$p3Consolidation,
            ...$p3Administration,
            ...$optimisationMaker,
            ...$optimisationReview,
            ...$helpUser,
            ...$reports,
            ...$adminOnly,
            'ACTION:ADM-HELP:MANAGE',
            'ACTION:CRM-PRICE:CREDIT',
            'ACTION:PORTAL-EXT:ACCESS-GRANT',
            'ACTION:PORTAL-EXT:ACCESS-UPDATE',
            'ACTION:PORTAL-EXT:ACCESS-REVOKE',
            'ACTION:PORTAL-EXT:DOCUMENT-PUBLISH',
            'ACTION:PORTAL-EXT:DOCUMENT-DOWNLOAD',
            'ACTION:PORTAL-EXT:DOCUMENT-WITHDRAW',
            'ACTION:BI-PROFIT:COST-BACKFILL',
        ]);

        return [
            'SALES_MANAGER' => $sales,
            'OPERATIONS_MANAGER' => $operations,
            'FINANCE_REVIEWER' => $finance,
            'ERP_ADMIN' => $admin,
            'PARTNER_PORTAL' => $partner,
            'BI_ANALYST' => $reports,
        ];
    }

    /** @param list<string> $values @return list<string> */
    private static function unique(array $values): array
    {
        return array_values(array_unique($values));
    }
}

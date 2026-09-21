<?php

use App\Http\Controllers\ObservabilityController;
use App\Modules\Control\Http\Controllers\ApprovalRuleController;
use App\Modules\Control\Http\Controllers\AuditController;
use App\Modules\Control\Http\Controllers\OutboxController;
use App\Modules\Finance\Http\Controllers\AccountsPayableController;
use App\Modules\Finance\Http\Controllers\FinanceOperationsController;
use App\Modules\Finance\Http\Controllers\FinanceSupplementController;
use App\Modules\Foundation\Http\Controllers\AuthController;
use App\Modules\Foundation\Http\Controllers\ContextController;
use App\Modules\Foundation\Http\Controllers\DeviceSessionController;
use App\Modules\Foundation\Http\Controllers\HelpSupportController;
use App\Modules\Foundation\Http\Controllers\IdentityAdminController;
use App\Modules\Foundation\Http\Controllers\IdentityController;
use App\Modules\Foundation\Http\Controllers\LocationAdminController;
use App\Modules\Foundation\Http\Controllers\MfaController;
use App\Modules\Foundation\Http\Controllers\OrganisationAdminController;
use App\Modules\Foundation\Http\Controllers\RoleAdminController;
use App\Modules\Foundation\Http\Controllers\UserAdminController;
use App\Modules\Inventory\Http\Controllers\InventoryFoundationController;
use App\Modules\Inventory\Http\Controllers\InventoryOperationController;
use App\Modules\Manufacturing\Http\Controllers\ManufacturingPlanningController;
use App\Modules\Manufacturing\Http\Controllers\ManufacturingExecutionController;
use App\Modules\MasterData\Http\Controllers\PartyController;
use App\Modules\MasterData\Http\Controllers\ProductMasterController;
use App\Modules\Partner\Http\Controllers\PartnerPortalController;
use App\Modules\Procurement\Http\Controllers\PurchaseOrderController;
use App\Modules\Procurement\Http\Controllers\PurchaseRequisitionController;
use App\Modules\Procurement\Http\Controllers\RequestForQuotationController;
use App\Modules\Procurement\Http\Controllers\InboundProcurementController;
use App\Modules\Reporting\Http\Controllers\ReportingController;
use App\Modules\Sales\Http\Controllers\UnsoldSalesReturnApprovalController;
use App\Modules\Sales\Http\Controllers\UnsoldSalesReturnController;
use App\Modules\Sales\Http\Controllers\UnsoldSalesReturnEvidenceController;
use App\Modules\Sales\Http\Controllers\UnsoldSalesReturnFinanceController;
use App\Modules\Sales\Http\Controllers\OrderToCashController;
use App\Modules\Scale\Http\Controllers\MultiPlantController;
use App\Modules\Scale\Http\Controllers\OptimisationController;
use App\Modules\Work\Http\Controllers\WorkQueueController;
use App\Modules\Experience\Http\Controllers\ExperienceController;
use Illuminate\Support\Facades\Route;

Route::get('/health', fn () => [
    'status' => 'ok',
    'service' => 'qt-foods-erp-crm',
    'architecture' => 'modular-monolith',
]);
Route::get('/ready', [ObservabilityController::class, 'readiness']);
Route::get('/metrics', [ObservabilityController::class, 'metrics'])
    ->middleware('observability.metrics');

Route::middleware('web')->prefix('v1')->group(function () {
    Route::get('/auth/csrf', [AuthController::class, 'csrf']);
    Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:identity-login');
    Route::post('/auth/mfa/challenge', [AuthController::class, 'mfaChallenge'])->middleware('throttle:identity-mfa-challenge');
    Route::get('/auth/invitations/{token}', [IdentityController::class, 'invitation'])
        ->where('token', '[A-Fa-f0-9]{64}')->middleware('throttle:identity-link-read');
    Route::post('/auth/invitations/accept', [IdentityController::class, 'acceptInvitation'])
        ->middleware('throttle:identity-link-consume');
    Route::post('/auth/password/forgot', [IdentityController::class, 'forgotPassword'])
        ->middleware('throttle:identity-email');
    Route::post('/auth/password/reset', [IdentityController::class, 'resetPassword'])
        ->middleware('throttle:identity-link-consume');
    Route::post('/auth/email/verification/request', [IdentityController::class, 'requestVerification'])
        ->middleware('throttle:identity-email');
    Route::post('/auth/email/verify', [IdentityController::class, 'verifyEmail'])
        ->middleware('throttle:identity-link-consume');

    Route::middleware(['auth', 'erp.device'])->group(function () {
        Route::get('/me', [AuthController::class, 'me']);
        Route::post('/auth/logout', [AuthController::class, 'logout']);
        Route::post('/auth/password/change', [IdentityController::class, 'changePassword'])
            ->middleware('throttle:identity-account-security');
        Route::post('/auth/mfa/setup', [MfaController::class, 'setup'])->middleware('throttle:identity-account-security');
        Route::post('/auth/mfa/confirm', [MfaController::class, 'confirm'])->middleware('throttle:identity-account-security');
        Route::post('/auth/mfa/disable', [MfaController::class, 'disable'])->middleware('throttle:identity-account-security');
        Route::post('/auth/mfa/recovery-codes', [MfaController::class, 'recoveryCodes'])
            ->middleware('throttle:identity-account-security');
        Route::get('/auth/sessions', [DeviceSessionController::class, 'index']);
        Route::post('/auth/sessions/revoke-others', [DeviceSessionController::class, 'revokeOthers']);
        Route::post('/auth/sessions/{sessionId}/revoke', [DeviceSessionController::class, 'revoke'])
            ->whereUuid('sessionId');
        Route::get('/contexts', [ContextController::class, 'index']);
        Route::post('/contexts/select', [ContextController::class, 'select']);

        Route::middleware('erp.context')->group(function () {
            Route::get('/experience/search', [ExperienceController::class, 'search']);
            Route::get('/experience/workspace', [ExperienceController::class, 'workspace']);
            Route::put('/experience/workspace/settings', [ExperienceController::class, 'saveSetting']);
            Route::post('/experience/workspace/views', [ExperienceController::class, 'createView']);
            Route::delete('/experience/workspace/views/{viewId}', [ExperienceController::class, 'deleteView'])->whereUuid('viewId');
            Route::get('/experience/notifications', [ExperienceController::class, 'notifications']);
            Route::post('/experience/notifications/{workItemId}/read', [ExperienceController::class, 'readNotification'])->whereUuid('workItemId');
            Route::post('/experience/notifications/{workItemId}/dismiss', [ExperienceController::class, 'dismissNotification'])->whereUuid('workItemId');
            Route::put('/experience/notification-preferences', [ExperienceController::class, 'saveNotificationPreferences']);
            Route::get('/experience/analytics', [ExperienceController::class, 'analytics']);
            Route::put('/experience/analytics/targets', [ExperienceController::class, 'saveTarget']);
            Route::post('/experience/imports/preview', [ExperienceController::class, 'previewImport']);
            Route::post('/experience/imports/{batchId}/commit', [ExperienceController::class, 'commitImport'])->whereUuid('batchId');
            Route::post('/experience/imports/{batchId}/rollback', [ExperienceController::class, 'rollbackImport'])->whereUuid('batchId');
            Route::get('/experience/imports/{batchId}/errors', [ExperienceController::class, 'importErrors'])->whereUuid('batchId');
            Route::get('/work/tasks', [WorkQueueController::class, 'index'])
                ->middleware('erp.screen:WRK-HOME');
            Route::post('/work/tasks/{workItemId}/claim', [WorkQueueController::class, 'claim'])
                ->whereUuid('workItemId')
                ->middleware(['erp.screen:WRK-HOME', 'erp.permission:ACTION:WRK-HOME:CLAIM']);
            Route::post('/work/tasks/{workItemId}/assign', [WorkQueueController::class, 'assign'])
                ->whereUuid('workItemId')
                ->middleware(['erp.screen:WRK-HOME', 'erp.permission:ACTION:WRK-HOME:MANAGE']);
            Route::post('/work/tasks/{workItemId}/complete', [WorkQueueController::class, 'complete'])
                ->whereUuid('workItemId')
                ->middleware(['erp.screen:WRK-HOME', 'erp.permission:ACTION:WRK-HOME:COMPLETE']);

            Route::get('/admin/organisation', [OrganisationAdminController::class, 'index'])
                ->middleware('erp.screen:ADM-ORG');
            Route::get('/admin/companies/{companyId}', [OrganisationAdminController::class, 'showCompany'])
                ->whereUuid('companyId')->middleware('erp.screen:ADM-ORG');
            Route::post('/admin/companies', [OrganisationAdminController::class, 'createCompany'])
                ->middleware(['erp.screen:ADM-ORG', 'erp.permission:ACTION:ADM-ORG:CREATE']);
            Route::post('/admin/companies/{companyId}', [OrganisationAdminController::class, 'updateCompany'])
                ->whereUuid('companyId')
                ->middleware(['erp.screen:ADM-ORG', 'erp.permission:ACTION:ADM-ORG:UPDATE']);
            Route::get('/admin/plants/{plantId}', [OrganisationAdminController::class, 'showPlant'])
                ->whereUuid('plantId')->middleware('erp.screen:ADM-ORG');
            Route::post('/admin/plants', [OrganisationAdminController::class, 'createPlant'])
                ->middleware(['erp.screen:ADM-ORG', 'erp.permission:ACTION:ADM-ORG:CREATE']);
            Route::post('/admin/plants/{plantId}', [OrganisationAdminController::class, 'updatePlant'])
                ->whereUuid('plantId')
                ->middleware(['erp.screen:ADM-ORG', 'erp.permission:ACTION:ADM-ORG:UPDATE']);

            Route::get('/admin/locations', [LocationAdminController::class, 'index'])
                ->middleware('erp.screen:ADM-LOC');
            Route::get('/admin/locations/{locationId}', [LocationAdminController::class, 'show'])
                ->whereUuid('locationId')->middleware('erp.screen:ADM-LOC');
            Route::post('/admin/locations', [LocationAdminController::class, 'create'])
                ->middleware(['erp.screen:ADM-LOC', 'erp.permission:ACTION:ADM-LOC:CREATE']);
            Route::post('/admin/locations/{locationId}', [LocationAdminController::class, 'update'])
                ->whereUuid('locationId')
                ->middleware(['erp.screen:ADM-LOC', 'erp.permission:ACTION:ADM-LOC:UPDATE']);

            Route::get('/admin/users', [UserAdminController::class, 'index'])
                ->middleware('erp.screen:ADM-USER');
            Route::get('/admin/users/{userId}', [UserAdminController::class, 'show'])
                ->whereUuid('userId')->middleware('erp.screen:ADM-USER');
            Route::post('/admin/users', [UserAdminController::class, 'create'])
                ->middleware(['erp.screen:ADM-USER', 'erp.permission:ACTION:ADM-USER:CREATE']);
            Route::post('/admin/users/{userId}', [UserAdminController::class, 'update'])
                ->whereUuid('userId')
                ->middleware(['erp.screen:ADM-USER', 'erp.permission:ACTION:ADM-USER:UPDATE']);
            Route::post('/admin/user-invitations', [IdentityAdminController::class, 'invite'])
                ->middleware(['erp.screen:ADM-USER', 'erp.permission:ACTION:ADM-USER:INVITE']);
            Route::post('/admin/user-invitations/{invitationId}/resend', [IdentityAdminController::class, 'resend'])
                ->whereUuid('invitationId')
                ->middleware(['erp.screen:ADM-USER', 'erp.permission:ACTION:ADM-USER:INVITE']);
            Route::post('/admin/user-invitations/{invitationId}/revoke', [IdentityAdminController::class, 'revoke'])
                ->whereUuid('invitationId')
                ->middleware(['erp.screen:ADM-USER', 'erp.permission:ACTION:ADM-USER:INVITE']);
            Route::post('/admin/users/{userId}/verification', [IdentityAdminController::class, 'sendVerification'])
                ->whereUuid('userId')
                ->middleware(['erp.screen:ADM-USER', 'erp.permission:ACTION:ADM-USER:INVITE']);
            Route::get('/admin/users/{userId}/sessions', [IdentityAdminController::class, 'sessions'])
                ->whereUuid('userId')
                ->middleware(['erp.screen:ADM-USER', 'erp.permission:ACTION:ADM-USER:SESSIONS']);
            Route::post('/admin/users/{userId}/sessions/{sessionId}/revoke', [IdentityAdminController::class, 'revokeSession'])
                ->whereUuid('userId')->whereUuid('sessionId')
                ->middleware(['erp.screen:ADM-USER', 'erp.permission:ACTION:ADM-USER:SESSIONS']);
            Route::post('/admin/users/{userId}/assignments', [UserAdminController::class, 'createAssignment'])
                ->whereUuid('userId')
                ->middleware(['erp.screen:ADM-USER', 'erp.permission:ACTION:ADM-USER:ASSIGN']);
            Route::get('/admin/role-assignments/{assignmentId}', [UserAdminController::class, 'showAssignment'])
                ->whereUuid('assignmentId')->middleware('erp.screen:ADM-USER');
            Route::post('/admin/role-assignments/{assignmentId}', [UserAdminController::class, 'updateAssignment'])
                ->whereUuid('assignmentId')
                ->middleware(['erp.screen:ADM-USER', 'erp.permission:ACTION:ADM-USER:ASSIGN']);

            Route::get('/admin/roles', [RoleAdminController::class, 'index'])
                ->middleware('erp.screen:ADM-ROLE');
            Route::get('/admin/roles/{roleId}', [RoleAdminController::class, 'show'])
                ->whereUuid('roleId')->middleware('erp.screen:ADM-ROLE');
            Route::post('/admin/roles', [RoleAdminController::class, 'create'])
                ->middleware(['erp.screen:ADM-ROLE', 'erp.permission:ACTION:ADM-ROLE:CREATE']);
            Route::post('/admin/roles/{roleId}', [RoleAdminController::class, 'update'])
                ->whereUuid('roleId')
                ->middleware(['erp.screen:ADM-ROLE', 'erp.permission:ACTION:ADM-ROLE:UPDATE']);
            Route::post('/admin/roles/{roleId}/permissions', [RoleAdminController::class, 'syncPermissions'])
                ->whereUuid('roleId')
                ->middleware(['erp.screen:ADM-ROLE', 'erp.permission:ACTION:ADM-ROLE:PERMISSIONS']);
            Route::get('/admin/permissions', [RoleAdminController::class, 'permissions'])
                ->middleware('erp.screen:ADM-ROLE');
            Route::get('/admin/permissions/{permissionId}', [RoleAdminController::class, 'showPermission'])
                ->whereUuid('permissionId')->middleware('erp.screen:ADM-ROLE');
            Route::post('/admin/permissions', [RoleAdminController::class, 'createPermission'])
                ->middleware(['erp.screen:ADM-ROLE', 'erp.permission:ACTION:ADM-ROLE:CREATE']);
            Route::post('/admin/permissions/{permissionId}', [RoleAdminController::class, 'updatePermission'])
                ->whereUuid('permissionId')
                ->middleware(['erp.screen:ADM-ROLE', 'erp.permission:ACTION:ADM-ROLE:UPDATE']);
            Route::get('/admin/approvals', [ApprovalRuleController::class, 'index'])
                ->middleware('erp.screen:ADM-RULE');
            Route::post('/admin/approvals/escalate-due', [ApprovalRuleController::class, 'escalateDue'])
                ->middleware(['erp.screen:ADM-RULE', 'erp.permission:ACTION:ADM-RULE:ESCALATE']);
            Route::post('/admin/approval-rules', [ApprovalRuleController::class, 'create'])
                ->middleware(['erp.screen:ADM-RULE', 'erp.permission:ACTION:ADM-RULE:CREATE']);
            Route::get('/admin/approval-rules/{ruleId}', [ApprovalRuleController::class, 'show'])
                ->whereUuid('ruleId')->middleware('erp.screen:ADM-RULE');
            Route::post('/admin/approval-rules/{ruleId}', [ApprovalRuleController::class, 'update'])
                ->whereUuid('ruleId')
                ->middleware(['erp.screen:ADM-RULE', 'erp.permission:ACTION:ADM-RULE:UPDATE']);
            Route::post('/admin/approval-delegations', [ApprovalRuleController::class, 'delegate'])
                ->middleware(['erp.screen:ADM-RULE', 'erp.permission:ACTION:ADM-RULE:DELEGATE']);
            Route::post('/admin/approval-delegations/{delegationId}/revoke', [ApprovalRuleController::class, 'revokeDelegation'])
                ->whereUuid('delegationId')
                ->middleware(['erp.screen:ADM-RULE', 'erp.permission:ACTION:ADM-RULE:DELEGATE']);
            Route::get('/admin/audit', [AuditController::class, 'index'])
                ->middleware('erp.screen:ADM-AUD');
            Route::get('/admin/audit/{auditId}', [AuditController::class, 'show'])
                ->whereUuid('auditId')->middleware('erp.screen:ADM-AUD');
            Route::get('/admin/audit/{auditId}/evidence/{evidenceId}', [AuditController::class, 'evidence'])
                ->whereUuid('auditId')->whereUuid('evidenceId')
                ->middleware(['erp.screen:ADM-AUD', 'erp.permission:ACTION:ADM-AUD:EVIDENCE']);
            Route::get('/admin/integrations', [OutboxController::class, 'index'])
                ->middleware('erp.screen:ADM-INT');
            Route::post('/admin/integrations/process-due', [OutboxController::class, 'process'])
                ->middleware(['erp.screen:ADM-INT', 'erp.permission:ACTION:ADM-INT:PROCESS']);
            Route::get('/admin/outbox-events/{eventId}', [OutboxController::class, 'show'])
                ->whereUuid('eventId')->middleware('erp.screen:ADM-INT');
            Route::post('/admin/outbox-events/{eventId}/retry', [OutboxController::class, 'retry'])
                ->whereUuid('eventId')
                ->middleware(['erp.screen:ADM-INT', 'erp.permission:ACTION:ADM-INT:RETRY']);
            Route::post('/admin/outbox-events/{eventId}/quarantine', [OutboxController::class, 'quarantine'])
                ->whereUuid('eventId')
                ->middleware(['erp.screen:ADM-INT', 'erp.permission:ACTION:ADM-INT:QUARANTINE']);

            Route::get('/admin/help', [HelpSupportController::class, 'index'])
                ->middleware('erp.screen:ADM-HELP');
            Route::get('/admin/help/articles/{slug}', [HelpSupportController::class, 'article'])
                ->where('slug', '[a-z0-9-]+')->middleware('erp.screen:ADM-HELP');
            Route::get('/admin/help/cases/{caseId}', [HelpSupportController::class, 'supportCase'])
                ->whereUuid('caseId')->middleware('erp.screen:ADM-HELP');
            Route::post('/admin/help/cases', [HelpSupportController::class, 'create'])
                ->middleware(['erp.screen:ADM-HELP', 'erp.permission:ACTION:ADM-HELP:CREATE']);
            Route::post('/admin/help/cases/{caseId}/comments', [HelpSupportController::class, 'comment'])
                ->whereUuid('caseId')->middleware(['erp.screen:ADM-HELP', 'erp.permission:ACTION:ADM-HELP:COMMENT']);
            Route::post('/admin/help/cases/{caseId}/start', [HelpSupportController::class, 'start'])
                ->whereUuid('caseId')->middleware(['erp.screen:ADM-HELP', 'erp.permission:ACTION:ADM-HELP:MANAGE']);
            Route::post('/admin/help/cases/{caseId}/resolve', [HelpSupportController::class, 'resolve'])
                ->whereUuid('caseId')->middleware(['erp.screen:ADM-HELP', 'erp.permission:ACTION:ADM-HELP:MANAGE']);
            Route::post('/admin/help/cases/{caseId}/reopen', [HelpSupportController::class, 'reopen'])
                ->whereUuid('caseId')->middleware(['erp.screen:ADM-HELP', 'erp.permission:ACTION:ADM-HELP:REOPEN']);
            Route::post('/admin/help/cases/{caseId}/close', [HelpSupportController::class, 'close'])
                ->whereUuid('caseId')->middleware(['erp.screen:ADM-HELP', 'erp.permission:ACTION:ADM-HELP:CLOSE']);

            Route::get('/reports', [ReportingController::class, 'index'])
                ->middleware('erp.screen:BI-REP');
            Route::get('/reports/runs/{runId}', [ReportingController::class, 'show'])
                ->whereUuid('runId')->middleware('erp.screen:BI-REP');
            Route::post('/reports/runs', [ReportingController::class, 'generate'])
                ->middleware(['erp.screen:BI-REP', 'erp.permission:ACTION:BI-REP:RUN']);
            Route::post('/reports/runs/{runId}/exports', [ReportingController::class, 'createExport'])
                ->whereUuid('runId')->middleware(['erp.screen:BI-REP', 'erp.permission:ACTION:BI-REP:EXPORT']);
            Route::get('/reports/exports/{exportId}/download', [ReportingController::class, 'download'])
                ->whereUuid('exportId')->middleware(['erp.screen:BI-REP', 'erp.permission:ACTION:BI-REP:EXPORT']);

            Route::get('/master/parties', [PartyController::class, 'index'])
                ->middleware('erp.screen:MD-PARTY');
            Route::get('/master/parties/{partyId}', [PartyController::class, 'show'])
                ->whereUuid('partyId')->middleware('erp.screen:MD-PARTY');
            Route::post('/master/parties', [PartyController::class, 'create'])
                ->middleware(['erp.screen:MD-PARTY', 'erp.permission:ACTION:MD-PARTY:CREATE']);
            Route::post('/master/parties/{partyId}', [PartyController::class, 'update'])
                ->whereUuid('partyId')
                ->middleware(['erp.screen:MD-PARTY', 'erp.permission:ACTION:MD-PARTY:UPDATE']);
            Route::post('/master/parties/{partyId}/status', [PartyController::class, 'changeStatus'])
                ->whereUuid('partyId')
                ->middleware(['erp.screen:MD-PARTY', 'erp.permission:ACTION:MD-PARTY:LIFECYCLE']);
            $productMasterRoutes = [
                ['master/brands', 'brands', 'MD-BRAND'],
                ['master/items', 'items', 'MD-ITEM'],
                ['master/skus', 'skus', 'MD-SKU'],
                ['manufacturing/recipes', 'recipes', 'MD-REC'],
                ['manufacturing/routes', 'routes', 'MD-ROUTE'],
                ['quality/specifications', 'specifications', 'MD-SPEC'],
            ];
            foreach ($productMasterRoutes as [$path, $resource, $screen]) {
                Route::get('/'.$path, [ProductMasterController::class, 'index'])
                    ->defaults('resource', $resource)->middleware("erp.screen:{$screen}");
                Route::get('/'.$path.'/{recordId}', [ProductMasterController::class, 'show'])
                    ->whereUuid('recordId')->defaults('resource', $resource)->middleware("erp.screen:{$screen}");
                Route::post('/'.$path, [ProductMasterController::class, 'create'])
                    ->defaults('resource', $resource)
                    ->middleware(["erp.screen:{$screen}", "erp.permission:ACTION:{$screen}:CREATE"]);
                Route::post('/'.$path.'/{recordId}', [ProductMasterController::class, 'update'])
                    ->whereUuid('recordId')->defaults('resource', $resource)
                    ->middleware(["erp.screen:{$screen}", "erp.permission:ACTION:{$screen}:UPDATE"]);
                Route::post('/'.$path.'/{recordId}/status', [ProductMasterController::class, 'changeStatus'])
                    ->whereUuid('recordId')->defaults('resource', $resource)
                    ->middleware(["erp.screen:{$screen}", "erp.permission:ACTION:{$screen}:LIFECYCLE"]);
            }

            Route::get('/procurement/requisitions', [PurchaseRequisitionController::class, 'index'])
                ->middleware('erp.screen:PUR-REQ');
            Route::get('/procurement/requisitions/{requisitionId}', [PurchaseRequisitionController::class, 'show'])
                ->whereUuid('requisitionId')->middleware('erp.screen:PUR-REQ');
            Route::post('/procurement/requisitions', [PurchaseRequisitionController::class, 'create'])
                ->middleware(['erp.screen:PUR-REQ', 'erp.permission:ACTION:PUR-REQ:CREATE']);
            Route::post('/procurement/requisitions/{requisitionId}', [PurchaseRequisitionController::class, 'update'])
                ->whereUuid('requisitionId')
                ->middleware(['erp.screen:PUR-REQ', 'erp.permission:ACTION:PUR-REQ:UPDATE']);
            Route::post('/procurement/requisitions/{requisitionId}/submit', [PurchaseRequisitionController::class, 'submit'])
                ->whereUuid('requisitionId')
                ->middleware(['erp.screen:PUR-REQ', 'erp.permission:ACTION:PUR-REQ:SUBMIT']);
            Route::post('/procurement/requisitions/{requisitionId}/cancel', [PurchaseRequisitionController::class, 'cancel'])
                ->whereUuid('requisitionId')
                ->middleware(['erp.screen:PUR-REQ', 'erp.permission:ACTION:PUR-REQ:CANCEL']);
            Route::post('/procurement/requisition-approvals/{approvalId}/approve', [PurchaseRequisitionController::class, 'approve'])
                ->whereUuid('approvalId')->middleware('erp.screen:PUR-REQ');
            Route::post('/procurement/requisition-approvals/{approvalId}/reject', [PurchaseRequisitionController::class, 'reject'])
                ->whereUuid('approvalId')->middleware('erp.screen:PUR-REQ');
            Route::get('/procurement/rfqs', [RequestForQuotationController::class, 'index'])
                ->middleware('erp.screen:PUR-RFQ');
            Route::get('/procurement/rfqs/{rfqId}', [RequestForQuotationController::class, 'show'])
                ->whereUuid('rfqId')->middleware('erp.screen:PUR-RFQ');
            Route::post('/procurement/rfqs', [RequestForQuotationController::class, 'create'])
                ->middleware(['erp.screen:PUR-RFQ', 'erp.permission:ACTION:PUR-RFQ:CREATE']);
            Route::post('/procurement/rfqs/{rfqId}', [RequestForQuotationController::class, 'update'])
                ->whereUuid('rfqId')
                ->middleware(['erp.screen:PUR-RFQ', 'erp.permission:ACTION:PUR-RFQ:UPDATE']);
            Route::post('/procurement/rfqs/{rfqId}/issue', [RequestForQuotationController::class, 'issue'])
                ->whereUuid('rfqId')
                ->middleware(['erp.screen:PUR-RFQ', 'erp.permission:ACTION:PUR-RFQ:ISSUE']);
            Route::post('/procurement/rfqs/{rfqId}/quotes', [RequestForQuotationController::class, 'recordQuote'])
                ->whereUuid('rfqId')
                ->middleware(['erp.screen:PUR-RFQ', 'erp.permission:ACTION:PUR-RFQ:QUOTE']);
            Route::post('/procurement/rfqs/{rfqId}/award', [RequestForQuotationController::class, 'award'])
                ->whereUuid('rfqId')
                ->middleware(['erp.screen:PUR-RFQ', 'erp.permission:ACTION:PUR-RFQ:AWARD']);
            Route::post('/procurement/rfqs/{rfqId}/cancel', [RequestForQuotationController::class, 'cancel'])
                ->whereUuid('rfqId')
                ->middleware(['erp.screen:PUR-RFQ', 'erp.permission:ACTION:PUR-RFQ:CANCEL']);

            Route::get('/procurement/purchase-orders', [PurchaseOrderController::class, 'index'])
                ->middleware('erp.screen:PUR-PO');
            Route::get('/procurement/purchase-orders/{purchaseOrderId}', [PurchaseOrderController::class, 'show'])
                ->whereUuid('purchaseOrderId')->middleware('erp.screen:PUR-PO');
            Route::post('/procurement/purchase-orders', [PurchaseOrderController::class, 'create'])
                ->middleware(['erp.screen:PUR-PO', 'erp.permission:ACTION:PUR-PO:CREATE']);
            Route::post('/procurement/purchase-orders/{purchaseOrderId}/amend', [PurchaseOrderController::class, 'amend'])
                ->whereUuid('purchaseOrderId')
                ->middleware(['erp.screen:PUR-PO', 'erp.permission:ACTION:PUR-PO:AMEND']);
            Route::post('/procurement/purchase-orders/{purchaseOrderId}/issue', [PurchaseOrderController::class, 'issue'])
                ->whereUuid('purchaseOrderId')
                ->middleware(['erp.screen:PUR-PO', 'erp.permission:ACTION:PUR-PO:ISSUE']);
            Route::post('/procurement/purchase-orders/{purchaseOrderId}/cancel', [PurchaseOrderController::class, 'cancel'])
                ->whereUuid('purchaseOrderId')
                ->middleware(['erp.screen:PUR-PO', 'erp.permission:ACTION:PUR-PO:CANCEL']);
            Route::get('/procurement/gate-entries', [InboundProcurementController::class, 'gates'])
                ->middleware('erp.screen:INB-GATE');
            Route::get('/procurement/gate-entries/{gateEntryId}', [InboundProcurementController::class, 'gate'])
                ->whereUuid('gateEntryId')->middleware('erp.screen:INB-GATE');
            Route::post('/procurement/gate-entries', [InboundProcurementController::class, 'createGate'])
                ->middleware(['erp.screen:INB-GATE', 'erp.permission:ACTION:INB-GATE:CREATE']);
            Route::post('/procurement/gate-entries/{gateEntryId}', [InboundProcurementController::class, 'updateGate'])
                ->whereUuid('gateEntryId')->middleware(['erp.screen:INB-GATE', 'erp.permission:ACTION:INB-GATE:UPDATE']);
            Route::post('/procurement/gate-entries/{gateEntryId}/cancel', [InboundProcurementController::class, 'cancelGate'])
                ->whereUuid('gateEntryId')->middleware(['erp.screen:INB-GATE', 'erp.permission:ACTION:INB-GATE:CANCEL']);

            Route::get('/procurement/receipts', [InboundProcurementController::class, 'receipts'])
                ->middleware('erp.screen:INB-GRN');
            Route::get('/procurement/receipts/{receiptId}', [InboundProcurementController::class, 'receipt'])
                ->whereUuid('receiptId')->middleware('erp.screen:INB-GRN');
            Route::post('/procurement/receipts', [InboundProcurementController::class, 'createReceipt'])
                ->middleware(['erp.screen:INB-GRN', 'erp.permission:ACTION:INB-GRN:CREATE']);
            Route::post('/procurement/receipts/{receiptId}', [InboundProcurementController::class, 'updateReceipt'])
                ->whereUuid('receiptId')->middleware(['erp.screen:INB-GRN', 'erp.permission:ACTION:INB-GRN:UPDATE']);
            Route::post('/procurement/receipts/{receiptId}/post', [InboundProcurementController::class, 'postReceipt'])
                ->whereUuid('receiptId')->middleware(['erp.screen:INB-GRN', 'erp.permission:ACTION:INB-GRN:POST']);
            Route::post('/procurement/receipts/{receiptId}/cancel', [InboundProcurementController::class, 'cancelReceipt'])
                ->whereUuid('receiptId')->middleware(['erp.screen:INB-GRN', 'erp.permission:ACTION:INB-GRN:CANCEL']);

            Route::get('/quality/incoming', [InboundProcurementController::class, 'qualityTasks'])
                ->middleware('erp.screen:QC-IN');
            Route::get('/quality/incoming/{qualityTaskId}', [InboundProcurementController::class, 'qualityTask'])
                ->whereUuid('qualityTaskId')->middleware('erp.screen:QC-IN');
            Route::post('/quality/incoming/{qualityTaskId}/complete', [InboundProcurementController::class, 'completeQuality'])
                ->whereUuid('qualityTaskId')->middleware(['erp.screen:QC-IN', 'erp.permission:ACTION:QC-IN:COMPLETE']);

            Route::get('/procurement/supplier-returns', [InboundProcurementController::class, 'supplierReturns'])
                ->middleware('erp.screen:INB-RETURN');
            Route::get('/procurement/supplier-returns/{supplierReturnId}', [InboundProcurementController::class, 'supplierReturn'])
                ->whereUuid('supplierReturnId')->middleware('erp.screen:INB-RETURN');
            Route::post('/procurement/supplier-returns', [InboundProcurementController::class, 'createSupplierReturn'])
                ->middleware(['erp.screen:INB-RETURN', 'erp.permission:ACTION:INB-RETURN:CREATE']);
            Route::post('/procurement/supplier-returns/{supplierReturnId}', [InboundProcurementController::class, 'updateSupplierReturn'])
                ->whereUuid('supplierReturnId')->middleware(['erp.screen:INB-RETURN', 'erp.permission:ACTION:INB-RETURN:UPDATE']);
            Route::post('/procurement/supplier-returns/{supplierReturnId}/post', [InboundProcurementController::class, 'postSupplierReturn'])
                ->whereUuid('supplierReturnId')->middleware(['erp.screen:INB-RETURN', 'erp.permission:ACTION:INB-RETURN:POST']);
            Route::post('/procurement/supplier-returns/{supplierReturnId}/cancel', [InboundProcurementController::class, 'cancelSupplierReturn'])
                ->whereUuid('supplierReturnId')->middleware(['erp.screen:INB-RETURN', 'erp.permission:ACTION:INB-RETURN:CANCEL']);

            Route::get('/inventory/stock', [InventoryFoundationController::class, 'stock'])
                ->middleware('erp.screen:INV-STK');
            Route::get('/inventory/movements', [InventoryOperationController::class, 'movements'])
                ->middleware('erp.screen:INV-STK');
            Route::get('/inventory/movements/{movementId}', [InventoryOperationController::class, 'movement'])
                ->whereUuid('movementId')->middleware('erp.screen:INV-STK');
            Route::get('/inventory/stock/{positionId}', [InventoryFoundationController::class, 'stockPosition'])
                ->whereUuid('positionId')->middleware('erp.screen:INV-STK');
            Route::post('/inventory/stock/{positionId}/reservations', [InventoryFoundationController::class, 'reserve'])
                ->whereUuid('positionId')
                ->middleware(['erp.screen:INV-STK', 'erp.permission:ACTION:INV-STK:RESERVE']);
            Route::get('/inventory/owners', [InventoryFoundationController::class, 'owners'])
                ->middleware('erp.screen:INV-STK');
            Route::get('/inventory/owners/{ownerId}', [InventoryFoundationController::class, 'owner'])
                ->whereUuid('ownerId')->middleware('erp.screen:INV-STK');
            Route::post('/inventory/owners', [InventoryFoundationController::class, 'createOwner'])
                ->middleware(['erp.screen:INV-STK', 'erp.permission:ACTION:INV-STK:OWNER-CREATE']);
            Route::post('/inventory/owners/{ownerId}', [InventoryFoundationController::class, 'updateOwner'])
                ->whereUuid('ownerId')
                ->middleware(['erp.screen:INV-STK', 'erp.permission:ACTION:INV-STK:OWNER-UPDATE']);
            Route::post('/inventory/owners/{ownerId}/status', [InventoryFoundationController::class, 'changeOwnerStatus'])
                ->whereUuid('ownerId')
                ->middleware(['erp.screen:INV-STK', 'erp.permission:ACTION:INV-STK:OWNER-LIFECYCLE']);
            Route::get('/inventory/lots', [InventoryFoundationController::class, 'lots'])
                ->middleware('erp.screen:INV-STK');
            Route::get('/inventory/lots/{lotId}', [InventoryFoundationController::class, 'lot'])
                ->whereUuid('lotId')->middleware('erp.screen:INV-STK');
            Route::post('/inventory/lots', [InventoryFoundationController::class, 'createLot'])
                ->middleware(['erp.screen:INV-STK', 'erp.permission:ACTION:INV-STK:LOT-CREATE']);
            Route::post('/inventory/lots/{lotId}', [InventoryFoundationController::class, 'updateLot'])
                ->whereUuid('lotId')
                ->middleware(['erp.screen:INV-STK', 'erp.permission:ACTION:INV-STK:LOT-UPDATE']);
            Route::post('/inventory/lots/{lotId}/status', [InventoryFoundationController::class, 'changeLotStatus'])
                ->whereUuid('lotId')
                ->middleware(['erp.screen:INV-STK', 'erp.permission:ACTION:INV-STK:LOT-LIFECYCLE']);
            Route::post('/inventory/reservations/{reservationId}/release', [InventoryFoundationController::class, 'releaseReservation'])
                ->whereUuid('reservationId')
                ->middleware(['erp.screen:INV-STK', 'erp.permission:ACTION:INV-STK:RELEASE']);
            foreach ([
                ['issues', 'issues', 'INV-ISS'],
                ['transfers', 'transfers', 'INV-TRF'],
                ['counts', 'counts', 'INV-COUNT'],
                ['expiry-disposals', 'expiry-disposals', 'INV-EXP'],
            ] as [$path, $resource, $screen]) {
                Route::get('/inventory/'.$path, [InventoryOperationController::class, 'index'])
                    ->defaults('resource', $resource)->middleware("erp.screen:{$screen}");
                Route::get('/inventory/'.$path.'/{operationId}', [InventoryOperationController::class, 'show'])
                    ->whereUuid('operationId')->defaults('resource', $resource)->middleware("erp.screen:{$screen}");
                Route::post('/inventory/'.$path, [InventoryOperationController::class, 'create'])
                    ->defaults('resource', $resource)
                    ->middleware(["erp.screen:{$screen}", "erp.permission:ACTION:{$screen}:CREATE"]);
                Route::post('/inventory/'.$path.'/{operationId}', [InventoryOperationController::class, 'update'])
                    ->whereUuid('operationId')->defaults('resource', $resource)
                    ->middleware(["erp.screen:{$screen}", "erp.permission:ACTION:{$screen}:UPDATE"]);
                Route::post('/inventory/'.$path.'/{operationId}/post', [InventoryOperationController::class, 'post'])
                    ->whereUuid('operationId')->defaults('resource', $resource)
                    ->middleware(["erp.screen:{$screen}", "erp.permission:ACTION:{$screen}:POST"]);
                Route::post('/inventory/'.$path.'/{operationId}/cancel', [InventoryOperationController::class, 'cancel'])
                    ->whereUuid('operationId')->defaults('resource', $resource)
                    ->middleware(["erp.screen:{$screen}", "erp.permission:ACTION:{$screen}:CANCEL"]);
            }

            Route::get('/planning/demand', [ManufacturingPlanningController::class, 'demandIndex'])
                ->middleware('erp.screen:PLAN-DEM');
            Route::get('/planning/demand/{demandPlanId}', [ManufacturingPlanningController::class, 'demandShow'])
                ->whereUuid('demandPlanId')->middleware('erp.screen:PLAN-DEM');
            Route::post('/planning/demand', [ManufacturingPlanningController::class, 'demandCreate'])
                ->middleware(['erp.screen:PLAN-DEM', 'erp.permission:ACTION:PLAN-DEM:CREATE']);
            Route::post('/planning/demand/{demandPlanId}', [ManufacturingPlanningController::class, 'demandUpdate'])
                ->whereUuid('demandPlanId')
                ->middleware(['erp.screen:PLAN-DEM', 'erp.permission:ACTION:PLAN-DEM:UPDATE']);
            Route::post('/planning/demand/{demandPlanId}/release', [ManufacturingPlanningController::class, 'demandRelease'])
                ->whereUuid('demandPlanId')
                ->middleware(['erp.screen:PLAN-DEM', 'erp.permission:ACTION:PLAN-DEM:RELEASE']);
            Route::post('/planning/demand/{demandPlanId}/cancel', [ManufacturingPlanningController::class, 'demandCancel'])
                ->whereUuid('demandPlanId')
                ->middleware(['erp.screen:PLAN-DEM', 'erp.permission:ACTION:PLAN-DEM:CANCEL']);

            Route::get('/planning/mrp', [ManufacturingPlanningController::class, 'mrpIndex'])
                ->middleware('erp.screen:PLAN-MRP');
            Route::get('/planning/mrp/{mrpRunId}', [ManufacturingPlanningController::class, 'mrpShow'])
                ->whereUuid('mrpRunId')->middleware('erp.screen:PLAN-MRP');
            Route::post('/planning/mrp/runs', [ManufacturingPlanningController::class, 'mrpRun'])
                ->middleware(['erp.screen:PLAN-MRP', 'erp.permission:ACTION:PLAN-MRP:RUN']);
            Route::post('/planning/mrp/{mrpRunId}/cancel', [ManufacturingPlanningController::class, 'mrpCancel'])
                ->whereUuid('mrpRunId')
                ->middleware(['erp.screen:PLAN-MRP', 'erp.permission:ACTION:PLAN-MRP:CANCEL']);

            Route::get('/planning/schedules', [ManufacturingPlanningController::class, 'scheduleIndex'])
                ->middleware('erp.screen:PLAN-SCH');
            Route::get('/planning/schedules/{scheduleId}', [ManufacturingPlanningController::class, 'scheduleShow'])
                ->whereUuid('scheduleId')->middleware('erp.screen:PLAN-SCH');
            Route::post('/planning/schedules', [ManufacturingPlanningController::class, 'scheduleCreate'])
                ->middleware(['erp.screen:PLAN-SCH', 'erp.permission:ACTION:PLAN-SCH:CREATE']);
            Route::post('/planning/schedules/{scheduleId}', [ManufacturingPlanningController::class, 'scheduleUpdate'])
                ->whereUuid('scheduleId')
                ->middleware(['erp.screen:PLAN-SCH', 'erp.permission:ACTION:PLAN-SCH:UPDATE']);
            Route::post('/planning/schedules/{scheduleId}/release', [ManufacturingPlanningController::class, 'scheduleRelease'])
                ->whereUuid('scheduleId')
                ->middleware(['erp.screen:PLAN-SCH', 'erp.permission:ACTION:PLAN-SCH:RELEASE']);
            Route::post('/planning/schedules/{scheduleId}/cancel', [ManufacturingPlanningController::class, 'scheduleCancel'])
                ->whereUuid('scheduleId')
                ->middleware(['erp.screen:PLAN-SCH', 'erp.permission:ACTION:PLAN-SCH:CANCEL']);
            Route::get('/manufacturing/orders', [ManufacturingExecutionController::class, 'orderIndex'])->middleware('erp.screen:PRO-ORDER');
            Route::get('/manufacturing/orders/{productionOrderId}', [ManufacturingExecutionController::class, 'orderShow'])
                ->whereUuid('productionOrderId')->middleware('erp.screen:PRO-ORDER');
            Route::post('/manufacturing/orders', [ManufacturingExecutionController::class, 'orderCreate'])
                ->middleware(['erp.screen:PRO-ORDER', 'erp.permission:ACTION:PRO-ORDER:CREATE']);
            Route::post('/manufacturing/orders/{productionOrderId}/release', [ManufacturingExecutionController::class, 'orderRelease'])
                ->whereUuid('productionOrderId')->middleware(['erp.screen:PRO-ORDER', 'erp.permission:ACTION:PRO-ORDER:RELEASE']);
            Route::post('/manufacturing/orders/{productionOrderId}/issue-materials', [ManufacturingExecutionController::class, 'orderIssue'])
                ->whereUuid('productionOrderId')->middleware(['erp.screen:PRO-ORDER', 'erp.permission:ACTION:PRO-ORDER:ISSUE']);
            Route::post('/manufacturing/orders/{productionOrderId}/outputs', [ManufacturingExecutionController::class, 'orderOutput'])
                ->whereUuid('productionOrderId')->middleware(['erp.screen:PRO-LOSS', 'erp.permission:ACTION:PRO-LOSS:RECORD']);
            Route::post('/manufacturing/output-events/{outputEventId}/resolve', [ManufacturingExecutionController::class, 'reworkResolve'])
                ->whereUuid('outputEventId')->middleware(['erp.screen:PRO-LOSS', 'erp.permission:ACTION:PRO-LOSS:RESOLVE']);
            Route::post('/manufacturing/orders/{productionOrderId}/complete', [ManufacturingExecutionController::class, 'orderComplete'])
                ->whereUuid('productionOrderId')->middleware(['erp.screen:PRO-ORDER', 'erp.permission:ACTION:PRO-ORDER:COMPLETE']);
            Route::post('/manufacturing/orders/{productionOrderId}/cancel', [ManufacturingExecutionController::class, 'orderCancel'])
                ->whereUuid('productionOrderId')->middleware(['erp.screen:PRO-ORDER', 'erp.permission:ACTION:PRO-ORDER:CANCEL']);

            Route::get('/manufacturing/stages', [ManufacturingExecutionController::class, 'stageIndex'])->middleware('erp.screen:PRO-STAGE');
            Route::get('/manufacturing/stages/{productionStageId}', [ManufacturingExecutionController::class, 'stageShow'])
                ->whereUuid('productionStageId')->middleware('erp.screen:PRO-STAGE');
            Route::post('/manufacturing/stages/{productionStageId}/start', [ManufacturingExecutionController::class, 'stageStart'])
                ->whereUuid('productionStageId')->middleware(['erp.screen:PRO-STAGE', 'erp.permission:ACTION:PRO-STAGE:START']);
            Route::post('/manufacturing/stages/{productionStageId}/complete', [ManufacturingExecutionController::class, 'stageComplete'])
                ->whereUuid('productionStageId')->middleware(['erp.screen:PRO-STAGE', 'erp.permission:ACTION:PRO-STAGE:COMPLETE']);
            Route::get('/manufacturing/pro-loss', [ManufacturingExecutionController::class, 'lossIndex'])->middleware('erp.screen:PRO-LOSS');

            Route::get('/quality/lab-samples', [ManufacturingExecutionController::class, 'labIndex'])->middleware('erp.screen:QC-LAB');
            Route::get('/quality/lab-samples/{labSampleId}', [ManufacturingExecutionController::class, 'labShow'])
                ->whereUuid('labSampleId')->middleware('erp.screen:QC-LAB');
            Route::post('/quality/lab-samples', [ManufacturingExecutionController::class, 'labCreate'])
                ->middleware(['erp.screen:QC-LAB', 'erp.permission:ACTION:QC-LAB:CREATE']);
            Route::post('/quality/lab-samples/{labSampleId}/complete', [ManufacturingExecutionController::class, 'labComplete'])
                ->whereUuid('labSampleId')->middleware(['erp.screen:QC-LAB', 'erp.permission:ACTION:QC-LAB:COMPLETE']);

            Route::get('/quality/safety', [ManufacturingExecutionController::class, 'safetyIndex'])->middleware('erp.screen:QC-SAFE');
            Route::get('/quality/safety/{kind}/{safetyRecordId}', [ManufacturingExecutionController::class, 'safetyShow'])
                ->whereIn('kind', ['DEVIATION', 'HOLD'])->whereUuid('safetyRecordId')->middleware('erp.screen:QC-SAFE');
            Route::post('/quality/food-safety-holds', [ManufacturingExecutionController::class, 'holdCreate'])
                ->middleware(['erp.screen:QC-SAFE', 'erp.permission:ACTION:QC-SAFE:HOLD']);
            Route::post('/quality/food-safety-holds/{foodSafetyHoldId}/release', [ManufacturingExecutionController::class, 'holdRelease'])
                ->whereUuid('foodSafetyHoldId')->middleware(['erp.screen:QC-SAFE', 'erp.permission:ACTION:QC-SAFE:RELEASE-HOLD']);
            Route::post('/quality/deviations/{qualityDeviationId}/resolve', [ManufacturingExecutionController::class, 'deviationResolve'])
                ->whereUuid('qualityDeviationId')->middleware(['erp.screen:QC-SAFE', 'erp.permission:ACTION:QC-SAFE:RESOLVE']);
            Route::post('/quality/production-orders/{productionOrderId}/release', [ManufacturingExecutionController::class, 'qualityRelease'])
                ->whereUuid('productionOrderId')->middleware(['erp.screen:QC-SAFE', 'erp.permission:ACTION:QC-SAFE:RELEASE']);

            Route::get('/packing/artworks', [ManufacturingExecutionController::class, 'artworkIndex'])->middleware('erp.screen:PACK-ART');
            Route::get('/packing/artworks/{packagingArtworkId}', [ManufacturingExecutionController::class, 'artworkShow'])
                ->whereUuid('packagingArtworkId')->middleware('erp.screen:PACK-ART');
            Route::post('/packing/artworks', [ManufacturingExecutionController::class, 'artworkCreate'])
                ->middleware(['erp.screen:PACK-ART', 'erp.permission:ACTION:PACK-ART:CREATE']);
            Route::post('/packing/artworks/{packagingArtworkId}', [ManufacturingExecutionController::class, 'artworkUpdate'])
                ->whereUuid('packagingArtworkId')->middleware(['erp.screen:PACK-ART', 'erp.permission:ACTION:PACK-ART:UPDATE']);
            Route::post('/packing/artworks/{packagingArtworkId}/approve', [ManufacturingExecutionController::class, 'artworkApprove'])
                ->whereUuid('packagingArtworkId')->middleware(['erp.screen:PACK-ART', 'erp.permission:ACTION:PACK-ART:APPROVE']);
            Route::post('/packing/artworks/{packagingArtworkId}/retire', [ManufacturingExecutionController::class, 'artworkRetire'])
                ->whereUuid('packagingArtworkId')->middleware(['erp.screen:PACK-ART', 'erp.permission:ACTION:PACK-ART:RETIRE']);

            Route::get('/packing/runs', [ManufacturingExecutionController::class, 'packingIndex'])->middleware('erp.screen:PACK-RUN');
            Route::get('/packing/runs/{packingRunId}', [ManufacturingExecutionController::class, 'packingShow'])
                ->whereUuid('packingRunId')->middleware('erp.screen:PACK-RUN');
            Route::post('/packing/runs', [ManufacturingExecutionController::class, 'packingCreate'])
                ->middleware(['erp.screen:PACK-RUN', 'erp.permission:ACTION:PACK-RUN:CREATE']);
            Route::post('/packing/runs/{packingRunId}/complete', [ManufacturingExecutionController::class, 'packingComplete'])
                ->whereUuid('packingRunId')->middleware(['erp.screen:PACK-RUN', 'erp.permission:ACTION:PACK-RUN:COMPLETE']);
            Route::post('/packing/runs/{packingRunId}/cancel', [ManufacturingExecutionController::class, 'packingCancel'])
                ->whereUuid('packingRunId')->middleware(['erp.screen:PACK-RUN', 'erp.permission:ACTION:PACK-RUN:CANCEL']);
            Route::get('/manufacturing/finished-goods', [ManufacturingExecutionController::class, 'finishedGoodsIndex'])->middleware('erp.screen:FG-LOT');
            Route::get('/manufacturing/finished-goods/{finishedLotId}', [ManufacturingExecutionController::class, 'finishedGoodsShow'])
                ->whereUuid('finishedLotId')->middleware('erp.screen:FG-LOT');

            Route::get('/trace/cases', [ManufacturingExecutionController::class, 'traceIndex'])->middleware('erp.screen:TRACE-CASE');
            Route::get('/trace/cases/{recallCaseId}', [ManufacturingExecutionController::class, 'traceShow'])
                ->whereUuid('recallCaseId')->middleware('erp.screen:TRACE-CASE');
            Route::get('/trace/lots/{lotId}', [ManufacturingExecutionController::class, 'lotTrace'])
                ->whereUuid('lotId')->middleware('erp.screen:TRACE-CASE');
            Route::post('/trace/cases', [ManufacturingExecutionController::class, 'recallCreate'])
                ->middleware(['erp.screen:TRACE-CASE', 'erp.permission:ACTION:TRACE-CASE:CREATE']);
            Route::post('/trace/cases/{recallCaseId}/close', [ManufacturingExecutionController::class, 'recallClose'])
                ->whereUuid('recallCaseId')->middleware(['erp.screen:TRACE-CASE', 'erp.permission:ACTION:TRACE-CASE:CLOSE']);

            Route::get('/costing/batches', [ManufacturingExecutionController::class, 'costIndex'])->middleware('erp.screen:COST-BATCH');
            Route::get('/costing/batches/{batchCostId}', [ManufacturingExecutionController::class, 'costShow'])
                ->whereUuid('batchCostId')->middleware('erp.screen:COST-BATCH');
            Route::post('/costing/batches', [ManufacturingExecutionController::class, 'costCalculate'])
                ->middleware(['erp.screen:COST-BATCH', 'erp.permission:ACTION:COST-BATCH:CALCULATE']);

            Route::get('/sales/leads', [OrderToCashController::class, 'leads'])->middleware('erp.screen:CRM-LEAD');
            Route::get('/sales/leads/{leadId}', [OrderToCashController::class, 'lead'])->whereUuid('leadId')->middleware('erp.screen:CRM-LEAD');
            Route::post('/sales/leads', [OrderToCashController::class, 'createLead'])->middleware(['erp.screen:CRM-LEAD', 'erp.permission:ACTION:CRM-LEAD:CREATE']);
            Route::post('/sales/leads/{leadId}', [OrderToCashController::class, 'updateLead'])->whereUuid('leadId')->middleware(['erp.screen:CRM-LEAD', 'erp.permission:ACTION:CRM-LEAD:UPDATE']);
            Route::post('/sales/leads/{leadId}/qualify', [OrderToCashController::class, 'qualifyLead'])->whereUuid('leadId')->middleware(['erp.screen:CRM-LEAD', 'erp.permission:ACTION:CRM-LEAD:QUALIFY']);
            Route::post('/sales/leads/{leadId}/convert', [OrderToCashController::class, 'convertLead'])->whereUuid('leadId')->middleware(['erp.screen:CRM-LEAD', 'erp.permission:ACTION:CRM-LEAD:CONVERT']);
            Route::post('/sales/leads/{leadId}/close', [OrderToCashController::class, 'closeLead'])->whereUuid('leadId')->middleware(['erp.screen:CRM-LEAD', 'erp.permission:ACTION:CRM-LEAD:CLOSE']);

            Route::get('/sales/pricing', [OrderToCashController::class, 'pricing'])->middleware('erp.screen:CRM-PRICE');
            Route::get('/sales/price-lists/{priceListId}', [OrderToCashController::class, 'priceList'])->whereUuid('priceListId')->middleware('erp.screen:CRM-PRICE');
            Route::post('/sales/price-lists', [OrderToCashController::class, 'createPriceList'])->middleware(['erp.screen:CRM-PRICE', 'erp.permission:ACTION:CRM-PRICE:CREATE']);
            Route::post('/sales/price-lists/{priceListId}', [OrderToCashController::class, 'updatePriceList'])->whereUuid('priceListId')->middleware(['erp.screen:CRM-PRICE', 'erp.permission:ACTION:CRM-PRICE:UPDATE']);
            Route::post('/sales/price-lists/{priceListId}/activate', [OrderToCashController::class, 'activatePriceList'])->whereUuid('priceListId')->middleware(['erp.screen:CRM-PRICE', 'erp.permission:ACTION:CRM-PRICE:ACTIVATE']);
            Route::post('/sales/price-lists/{priceListId}/retire', [OrderToCashController::class, 'retirePriceList'])->whereUuid('priceListId')->middleware(['erp.screen:CRM-PRICE', 'erp.permission:ACTION:CRM-PRICE:RETIRE']);
            Route::post('/sales/credit-profiles', [OrderToCashController::class, 'upsertCredit'])->middleware(['erp.screen:CRM-PRICE', 'erp.permission:ACTION:CRM-PRICE:CREDIT']);
            Route::get('/sales/contracts/{contractId}', [OrderToCashController::class, 'contract'])->whereUuid('contractId')->middleware('erp.screen:CRM-PRICE');
            Route::post('/sales/contracts', [OrderToCashController::class, 'createContract'])->middleware(['erp.screen:CRM-PRICE', 'erp.permission:ACTION:CRM-PRICE:CREATE']);
            Route::post('/sales/contracts/{contractId}', [OrderToCashController::class, 'updateContract'])->whereUuid('contractId')->middleware(['erp.screen:CRM-PRICE', 'erp.permission:ACTION:CRM-PRICE:UPDATE']);
            Route::post('/sales/contracts/{contractId}/activate', [OrderToCashController::class, 'activateContract'])->whereUuid('contractId')->middleware(['erp.screen:CRM-PRICE', 'erp.permission:ACTION:CRM-PRICE:ACTIVATE']);
            Route::post('/sales/contracts/{contractId}/close', [OrderToCashController::class, 'closeContract'])->whereUuid('contractId')->middleware(['erp.screen:CRM-PRICE', 'erp.permission:ACTION:CRM-PRICE:RETIRE']);

            Route::get('/sales/orders', [OrderToCashController::class, 'orders'])->middleware('erp.screen:CRM-ORDER');
            Route::get('/sales/orders/{orderId}', [OrderToCashController::class, 'order'])->whereUuid('orderId')->middleware('erp.screen:CRM-ORDER');
            Route::post('/sales/orders', [OrderToCashController::class, 'createOrder'])->middleware(['erp.screen:CRM-ORDER', 'erp.permission:ACTION:CRM-ORDER:CREATE']);
            Route::post('/sales/orders/{orderId}', [OrderToCashController::class, 'updateOrder'])->whereUuid('orderId')->middleware(['erp.screen:CRM-ORDER', 'erp.permission:ACTION:CRM-ORDER:UPDATE']);
            Route::post('/sales/orders/{orderId}/confirm', [OrderToCashController::class, 'confirmOrder'])->whereUuid('orderId')->middleware(['erp.screen:CRM-ORDER', 'erp.permission:ACTION:CRM-ORDER:CONFIRM']);
            Route::post('/sales/orders/{orderId}/amend', [OrderToCashController::class, 'amendOrder'])->whereUuid('orderId')->middleware(['erp.screen:CRM-ORDER', 'erp.permission:ACTION:CRM-ORDER:AMEND']);
            Route::post('/sales/orders/{orderId}/cancel', [OrderToCashController::class, 'cancelOrder'])->whereUuid('orderId')->middleware(['erp.screen:CRM-ORDER', 'erp.permission:ACTION:CRM-ORDER:CANCEL']);

            Route::get('/sales/third-party-work', [OrderToCashController::class, 'work'])->middleware('erp.screen:CON-WORK');
            Route::get('/sales/third-party-work/{workId}', [OrderToCashController::class, 'workDetail'])->whereUuid('workId')->middleware('erp.screen:CON-WORK');
            Route::post('/sales/third-party-work', [OrderToCashController::class, 'createWork'])->middleware(['erp.screen:CON-WORK', 'erp.permission:ACTION:CON-WORK:CREATE']);
            Route::post('/sales/third-party-work/{workId}/release', [OrderToCashController::class, 'releaseWork'])->whereUuid('workId')->middleware(['erp.screen:CON-WORK', 'erp.permission:ACTION:CON-WORK:RELEASE']);
            Route::post('/sales/third-party-work/{workId}/complete', [OrderToCashController::class, 'completeWork'])->whereUuid('workId')->middleware(['erp.screen:CON-WORK', 'erp.permission:ACTION:CON-WORK:COMPLETE']);
            Route::post('/sales/third-party-work/{workId}/cancel', [OrderToCashController::class, 'cancelWork'])->whereUuid('workId')->middleware(['erp.screen:CON-WORK', 'erp.permission:ACTION:CON-WORK:CANCEL']);

            Route::get('/dispatch/allocations', [OrderToCashController::class, 'allocations'])->middleware('erp.screen:DSP-PICK');
            Route::get('/dispatch/allocations/{allocationId}', [OrderToCashController::class, 'allocation'])->whereUuid('allocationId')->middleware('erp.screen:DSP-PICK');
            Route::post('/dispatch/orders/{orderId}/allocations', [OrderToCashController::class, 'allocateOrder'])->whereUuid('orderId')->middleware(['erp.screen:DSP-PICK', 'erp.permission:ACTION:DSP-PICK:ALLOCATE']);
            Route::post('/dispatch/allocations/{allocationId}/pick', [OrderToCashController::class, 'pickAllocation'])->whereUuid('allocationId')->middleware(['erp.screen:DSP-PICK', 'erp.permission:ACTION:DSP-PICK:PICK']);
            Route::post('/dispatch/allocations/{allocationId}/cancel', [OrderToCashController::class, 'cancelAllocation'])->whereUuid('allocationId')->middleware(['erp.screen:DSP-PICK', 'erp.permission:ACTION:DSP-PICK:CANCEL']);

            Route::get('/dispatch/shipments', [OrderToCashController::class, 'shipments'])->middleware('erp.screen:DSP-LOAD');
            Route::get('/dispatch/shipments/{shipmentId}', [OrderToCashController::class, 'shipment'])->whereUuid('shipmentId')->middleware('erp.screen:DSP-LOAD');
            Route::post('/dispatch/shipments', [OrderToCashController::class, 'createShipment'])->middleware(['erp.screen:DSP-LOAD', 'erp.permission:ACTION:DSP-LOAD:CREATE']);
            Route::post('/dispatch/shipments/{shipmentId}/load', [OrderToCashController::class, 'loadShipment'])->whereUuid('shipmentId')->middleware(['erp.screen:DSP-LOAD', 'erp.permission:ACTION:DSP-LOAD:LOAD']);
            Route::post('/dispatch/shipments/{shipmentId}/dispatch', [OrderToCashController::class, 'dispatchShipment'])->whereUuid('shipmentId')->middleware(['erp.screen:DSP-LOAD', 'erp.permission:ACTION:DSP-LOAD:DISPATCH']);
            Route::post('/dispatch/shipments/{shipmentId}/cancel', [OrderToCashController::class, 'cancelShipment'])->whereUuid('shipmentId')->middleware(['erp.screen:DSP-LOAD', 'erp.permission:ACTION:DSP-LOAD:CANCEL']);
            Route::get('/dispatch/pod', [OrderToCashController::class, 'pods'])->middleware('erp.screen:DSP-POD');
            Route::post('/dispatch/shipments/{shipmentId}/pod', [OrderToCashController::class, 'completePod'])->whereUuid('shipmentId')->middleware(['erp.screen:DSP-POD', 'erp.permission:ACTION:DSP-POD:COMPLETE']);

            Route::get('/sales/customer-claims', [OrderToCashController::class, 'claims'])->middleware('erp.screen:RET-CASE');
            Route::get('/sales/customer-claims/{claimId}', [OrderToCashController::class, 'claim'])->whereUuid('claimId')->middleware('erp.screen:RET-CASE');
            Route::post('/sales/customer-claims', [OrderToCashController::class, 'createClaim'])->middleware(['erp.screen:RET-CASE', 'erp.permission:ACTION:RET-CASE:CREATE']);
            Route::post('/sales/customer-claims/{claimId}/receive', [OrderToCashController::class, 'receiveClaim'])->whereUuid('claimId')->middleware(['erp.screen:RET-CASE', 'erp.permission:ACTION:RET-CASE:RECEIVE']);
            Route::post('/sales/customer-claims/{claimId}/resolve', [OrderToCashController::class, 'resolveClaim'])->whereUuid('claimId')->middleware(['erp.screen:RET-CASE', 'erp.permission:ACTION:RET-CASE:RESOLVE']);
            Route::get('/reports/profitability', [OrderToCashController::class, 'profitability'])->middleware('erp.screen:BI-PROFIT');

            Route::get('/sales/unsold-returns', [UnsoldSalesReturnController::class, 'index'])
                ->middleware('erp.screen:RET-UNSOLD');
            Route::get('/sales/unsold-returns/lookups', [UnsoldSalesReturnController::class, 'lookups'])
                ->middleware('erp.screen:RET-UNSOLD');
            Route::get('/sales/unsold-return-approvals', [UnsoldSalesReturnApprovalController::class, 'index'])
                ->middleware('erp.screen:RET-UNSOLD');
            Route::get('/sales/unsold-return-approvals/{approvalId}', [UnsoldSalesReturnApprovalController::class, 'show'])
                ->whereUuid('approvalId')
                ->middleware('erp.screen:RET-UNSOLD');
            Route::post('/sales/unsold-return-approvals/{approvalId}/approve', [UnsoldSalesReturnApprovalController::class, 'approve'])
                ->whereUuid('approvalId')
                ->middleware('erp.screen:RET-UNSOLD');
            Route::post('/sales/unsold-return-approvals/{approvalId}/reject', [UnsoldSalesReturnApprovalController::class, 'reject'])
                ->whereUuid('approvalId')
                ->middleware('erp.screen:RET-UNSOLD');
            Route::get('/sales/unsold-returns/{caseId}', [UnsoldSalesReturnController::class, 'show'])
                ->whereUuid('caseId')
                ->middleware('erp.screen:RET-UNSOLD');
            Route::post('/sales/unsold-returns/{caseId}/evidence', [UnsoldSalesReturnEvidenceController::class, 'store'])
                ->whereUuid('caseId')
                ->middleware(['erp.screen:RET-UNSOLD', 'erp.permission:ACTION:RET-UNSOLD:EVIDENCE']);
            Route::get('/sales/unsold-returns/{caseId}/evidence/{evidenceId}', [UnsoldSalesReturnEvidenceController::class, 'download'])
                ->whereUuid('caseId')
                ->whereUuid('evidenceId')
                ->middleware(['erp.screen:RET-UNSOLD', 'erp.permission:ACTION:RET-UNSOLD:EVIDENCE']);
            Route::post('/sales/unsold-returns', [UnsoldSalesReturnController::class, 'store'])
                ->middleware(['erp.screen:RET-UNSOLD', 'erp.permission:ACTION:RET-UNSOLD:CREATE']);
            Route::post('/sales/unsold-returns/{caseId}/receive', [UnsoldSalesReturnController::class, 'receive'])
                ->middleware(['erp.screen:RET-UNSOLD', 'erp.permission:ACTION:RET-UNSOLD:RECEIVE']);
            Route::post('/sales/unsold-returns/{caseId}/disposition', [UnsoldSalesReturnController::class, 'disposition'])
                ->middleware(['erp.screen:RET-UNSOLD', 'erp.permission:ACTION:RET-UNSOLD:DISPOSITION']);
            Route::post('/sales/unsold-returns/{caseId}/post-loss', [UnsoldSalesReturnController::class, 'postLoss'])
                ->middleware(['erp.screen:RET-UNSOLD', 'erp.permission:ACTION:RET-UNSOLD:POST-LOSS']);
            Route::post('/sales/unsold-returns/{caseId}/finance/invoice', [UnsoldSalesReturnFinanceController::class, 'linkInvoice'])
                ->whereUuid('caseId')
                ->middleware(['erp.screen:RET-UNSOLD', 'erp.permission:ACTION:RET-UNSOLD:FINANCE']);
            Route::post('/sales/unsold-returns/{caseId}/finance/credit-note', [UnsoldSalesReturnFinanceController::class, 'creditNote'])
                ->whereUuid('caseId')
                ->middleware(['erp.screen:RET-UNSOLD', 'erp.permission:ACTION:RET-UNSOLD:FINANCE']);
            Route::post('/sales/unsold-returns/{caseId}/finance/tax-adjustment', [UnsoldSalesReturnFinanceController::class, 'taxAdjustment'])
                ->whereUuid('caseId')
                ->middleware(['erp.screen:RET-UNSOLD', 'erp.permission:ACTION:RET-UNSOLD:FINANCE']);
            Route::post('/sales/unsold-returns/{caseId}/finance/receivable-adjustment', [UnsoldSalesReturnFinanceController::class, 'receivableAdjustment'])
                ->whereUuid('caseId')
                ->middleware(['erp.screen:RET-UNSOLD', 'erp.permission:ACTION:RET-UNSOLD:FINANCE']);
            Route::post('/sales/unsold-returns/{caseId}/finance/refund', [UnsoldSalesReturnFinanceController::class, 'refund'])
                ->whereUuid('caseId')
                ->middleware(['erp.screen:RET-UNSOLD', 'erp.permission:ACTION:RET-UNSOLD:FINANCE']);
            Route::post('/sales/unsold-returns/{caseId}/finance/replacement', [UnsoldSalesReturnFinanceController::class, 'replacement'])
                ->whereUuid('caseId')
                ->middleware(['erp.screen:RET-UNSOLD', 'erp.permission:ACTION:RET-UNSOLD:FINANCE']);

            Route::get('/finance/receivables', [OrderToCashController::class, 'receivables'])->middleware('erp.screen:FIN-AR');
            Route::get('/finance/receivables/{invoiceId}', [OrderToCashController::class, 'receivable'])->whereUuid('invoiceId')->middleware('erp.screen:FIN-AR');
            Route::post('/finance/receivables/collections', [OrderToCashController::class, 'collectReceivable'])->middleware(['erp.screen:FIN-AR', 'erp.permission:ACTION:FIN-AR:COLLECT']);
            Route::get('/finance/payables', [AccountsPayableController::class, 'index'])->middleware('erp.screen:FIN-AP');
            Route::get('/finance/payables/invoices/{invoiceId}', [AccountsPayableController::class, 'invoice'])
                ->whereUuid('invoiceId')->middleware('erp.screen:FIN-AP');
            Route::post('/finance/payables/invoices', [AccountsPayableController::class, 'createInvoice'])
                ->middleware(['erp.screen:FIN-AP', 'erp.permission:ACTION:FIN-AP:INVOICE-CREATE']);
            Route::post('/finance/payables/invoices/{invoiceId}', [AccountsPayableController::class, 'updateInvoice'])
                ->whereUuid('invoiceId')->middleware(['erp.screen:FIN-AP', 'erp.permission:ACTION:FIN-AP:INVOICE-UPDATE']);
            Route::post('/finance/payables/invoices/{invoiceId}/match', [AccountsPayableController::class, 'matchInvoice'])
                ->whereUuid('invoiceId')->middleware(['erp.screen:FIN-AP', 'erp.permission:ACTION:FIN-AP:MATCH']);
            Route::post('/finance/payables/invoices/{invoiceId}/approve', [AccountsPayableController::class, 'approveInvoice'])
                ->whereUuid('invoiceId')->middleware(['erp.screen:FIN-AP', 'erp.permission:ACTION:FIN-AP:INVOICE-APPROVE']);
            Route::post('/finance/payables/invoices/{invoiceId}/cancel', [AccountsPayableController::class, 'cancelInvoice'])
                ->whereUuid('invoiceId')->middleware(['erp.screen:FIN-AP', 'erp.permission:ACTION:FIN-AP:INVOICE-CANCEL']);
            Route::get('/finance/payables/proposals/{proposalId}', [AccountsPayableController::class, 'proposal'])
                ->whereUuid('proposalId')->middleware('erp.screen:FIN-AP');
            Route::post('/finance/payables/proposals', [AccountsPayableController::class, 'createProposal'])
                ->middleware(['erp.screen:FIN-AP', 'erp.permission:ACTION:FIN-AP:PROPOSAL-CREATE']);
            Route::post('/finance/payables/proposals/{proposalId}', [AccountsPayableController::class, 'updateProposal'])
                ->whereUuid('proposalId')->middleware(['erp.screen:FIN-AP', 'erp.permission:ACTION:FIN-AP:PROPOSAL-UPDATE']);
            Route::post('/finance/payables/proposals/{proposalId}/approve', [AccountsPayableController::class, 'approveProposal'])
                ->whereUuid('proposalId')->middleware(['erp.screen:FIN-AP', 'erp.permission:ACTION:FIN-AP:PROPOSAL-APPROVE']);
            Route::post('/finance/payables/proposals/{proposalId}/execute', [AccountsPayableController::class, 'executeProposal'])
                ->whereUuid('proposalId')->middleware(['erp.screen:FIN-AP', 'erp.permission:ACTION:FIN-AP:PAY']);
            Route::post('/finance/payables/proposals/{proposalId}/cancel', [AccountsPayableController::class, 'cancelProposal'])
                ->whereUuid('proposalId')->middleware(['erp.screen:FIN-AP', 'erp.permission:ACTION:FIN-AP:PROPOSAL-CANCEL']);
            Route::get('/finance/payables/payments/{paymentId}', [AccountsPayableController::class, 'payment'])
                ->whereUuid('paymentId')->middleware('erp.screen:FIN-AP');
            Route::post('/finance/payables/payments/{paymentId}/reconcile', [AccountsPayableController::class, 'reconcilePayment'])
                ->whereUuid('paymentId')->middleware(['erp.screen:FIN-AP', 'erp.permission:ACTION:FIN-AP:RECONCILE']);
            Route::get('/finance/expenses', [FinanceOperationsController::class, 'expenses'])->middleware('erp.screen:FIN-EXP');
            Route::get('/finance/expenses/{expenseId}', [FinanceOperationsController::class, 'expense'])->whereUuid('expenseId')->middleware('erp.screen:FIN-EXP');
            Route::post('/finance/expenses', [FinanceOperationsController::class, 'createExpense'])->middleware(['erp.screen:FIN-EXP', 'erp.permission:ACTION:FIN-EXP:CREATE']);
            Route::post('/finance/expenses/{expenseId}', [FinanceOperationsController::class, 'updateExpense'])->whereUuid('expenseId')->middleware(['erp.screen:FIN-EXP', 'erp.permission:ACTION:FIN-EXP:UPDATE']);
            Route::post('/finance/expenses/{expenseId}/submit', [FinanceOperationsController::class, 'submitExpense'])->whereUuid('expenseId')->middleware(['erp.screen:FIN-EXP', 'erp.permission:ACTION:FIN-EXP:SUBMIT']);
            Route::post('/finance/expenses/{expenseId}/approve', [FinanceOperationsController::class, 'approveExpense'])->whereUuid('expenseId')->middleware(['erp.screen:FIN-EXP', 'erp.permission:ACTION:FIN-EXP:APPROVE']);
            Route::post('/finance/expenses/{expenseId}/post', [FinanceOperationsController::class, 'postExpense'])->whereUuid('expenseId')->middleware(['erp.screen:FIN-EXP', 'erp.permission:ACTION:FIN-EXP:POST']);
            Route::post('/finance/expenses/{expenseId}/cancel', [FinanceOperationsController::class, 'cancelExpense'])->whereUuid('expenseId')->middleware(['erp.screen:FIN-EXP', 'erp.permission:ACTION:FIN-EXP:CANCEL']);

            Route::get('/finance/ledger', [FinanceOperationsController::class, 'ledger'])->middleware('erp.screen:FIN-GL');
            Route::get('/finance/journals/{journalId}', [FinanceOperationsController::class, 'journal'])->whereUuid('journalId')->middleware('erp.screen:FIN-GL');
            Route::post('/finance/journals', [FinanceOperationsController::class, 'createJournal'])->middleware(['erp.screen:FIN-GL', 'erp.permission:ACTION:FIN-GL:JOURNAL-CREATE']);
            Route::post('/finance/journals/{journalId}/post', [FinanceOperationsController::class, 'postJournal'])->whereUuid('journalId')->middleware(['erp.screen:FIN-GL', 'erp.permission:ACTION:FIN-GL:JOURNAL-POST']);
            Route::post('/finance/journals/{journalId}/reverse', [FinanceOperationsController::class, 'reverseJournal'])->whereUuid('journalId')->middleware(['erp.screen:FIN-GL', 'erp.permission:ACTION:FIN-GL:JOURNAL-REVERSE']);
            Route::post('/finance/fiscal-periods/{periodId}/close', [FinanceOperationsController::class, 'closePeriod'])->whereUuid('periodId')->middleware(['erp.screen:FIN-GL', 'erp.permission:ACTION:FIN-GL:PERIOD-CLOSE']);
            Route::get('/finance/period-close', [FinanceOperationsController::class, 'ledger'])->middleware('erp.screen:FIN-GL');

            Route::get('/costing/overheads', [FinanceOperationsController::class, 'overheads'])->middleware('erp.screen:COST-OH');
            Route::get('/costing/overheads/{poolId}', [FinanceOperationsController::class, 'overhead'])->whereUuid('poolId')->middleware('erp.screen:COST-OH');
            Route::post('/costing/overheads', [FinanceOperationsController::class, 'createOverhead'])->middleware(['erp.screen:COST-OH', 'erp.permission:ACTION:COST-OH:CREATE']);
            Route::post('/costing/overheads/{poolId}', [FinanceOperationsController::class, 'updateOverhead'])->whereUuid('poolId')->middleware(['erp.screen:COST-OH', 'erp.permission:ACTION:COST-OH:UPDATE']);
            Route::post('/costing/overheads/{poolId}/allocate', [FinanceOperationsController::class, 'allocateOverhead'])->whereUuid('poolId')->middleware(['erp.screen:COST-OH', 'erp.permission:ACTION:COST-OH:ALLOCATE']);

            Route::get('/finance/assets', [FinanceOperationsController::class, 'assets'])->middleware('erp.screen:ASSET-REG');
            Route::get('/finance/assets/{assetId}', [FinanceOperationsController::class, 'asset'])->whereUuid('assetId')->middleware('erp.screen:ASSET-REG');
            Route::post('/finance/assets', [FinanceOperationsController::class, 'createAsset'])->middleware(['erp.screen:ASSET-REG', 'erp.permission:ACTION:ASSET-REG:CREATE']);
            Route::post('/finance/assets/{assetId}', [FinanceOperationsController::class, 'updateAsset'])->whereUuid('assetId')->middleware(['erp.screen:ASSET-REG', 'erp.permission:ACTION:ASSET-REG:UPDATE']);
            Route::post('/finance/assets/{assetId}/activate', [FinanceOperationsController::class, 'activateAsset'])->whereUuid('assetId')->middleware(['erp.screen:ASSET-REG', 'erp.permission:ACTION:ASSET-REG:ACTIVATE']);
            Route::post('/finance/assets/{assetId}/depreciate', [FinanceOperationsController::class, 'depreciateAsset'])->whereUuid('assetId')->middleware(['erp.screen:ASSET-REG', 'erp.permission:ACTION:ASSET-REG:DEPRECIATE']);
            Route::post('/finance/assets/{assetId}/dispose', [FinanceOperationsController::class, 'disposeAsset'])->whereUuid('assetId')->middleware(['erp.screen:ASSET-REG', 'erp.permission:ACTION:ASSET-REG:DISPOSE']);

            Route::get('/finance/payroll', [FinanceOperationsController::class, 'payroll'])->middleware('erp.screen:HR-PAY');
            Route::get('/finance/payroll/{payrollId}', [FinanceOperationsController::class, 'payrollRun'])->whereUuid('payrollId')->middleware('erp.screen:HR-PAY');
            Route::post('/finance/employees', [FinanceOperationsController::class, 'createEmployee'])->middleware(['erp.screen:HR-PAY', 'erp.permission:ACTION:HR-PAY:EMPLOYEE-CREATE']);
            Route::post('/finance/employees/{employeeId}', [FinanceOperationsController::class, 'updateEmployee'])->whereUuid('employeeId')->middleware(['erp.screen:HR-PAY', 'erp.permission:ACTION:HR-PAY:EMPLOYEE-UPDATE']);
            Route::post('/finance/payroll', [FinanceOperationsController::class, 'createPayroll'])->middleware(['erp.screen:HR-PAY', 'erp.permission:ACTION:HR-PAY:RUN-CREATE']);
            Route::post('/finance/payroll/{payrollId}/approve', [FinanceOperationsController::class, 'approvePayroll'])->whereUuid('payrollId')->middleware(['erp.screen:HR-PAY', 'erp.permission:ACTION:HR-PAY:APPROVE']);
            Route::post('/finance/payroll/{payrollId}/post', [FinanceOperationsController::class, 'postPayroll'])->whereUuid('payrollId')->middleware(['erp.screen:HR-PAY', 'erp.permission:ACTION:HR-PAY:POST']);

            Route::get('/engineering/maintenance', [FinanceOperationsController::class, 'maintenance'])->middleware('erp.screen:ENG-MNT');
            Route::get('/engineering/maintenance/{workId}', [FinanceOperationsController::class, 'maintenanceWork'])->whereUuid('workId')->middleware('erp.screen:ENG-MNT');
            Route::post('/engineering/maintenance', [FinanceOperationsController::class, 'createMaintenance'])->middleware(['erp.screen:ENG-MNT', 'erp.permission:ACTION:ENG-MNT:CREATE']);
            Route::post('/engineering/maintenance/{workId}', [FinanceOperationsController::class, 'updateMaintenance'])->whereUuid('workId')->middleware(['erp.screen:ENG-MNT', 'erp.permission:ACTION:ENG-MNT:UPDATE']);
            Route::post('/engineering/maintenance/{workId}/release', [FinanceOperationsController::class, 'releaseMaintenance'])->whereUuid('workId')->middleware(['erp.screen:ENG-MNT', 'erp.permission:ACTION:ENG-MNT:RELEASE']);
            Route::post('/engineering/maintenance/{workId}/complete', [FinanceOperationsController::class, 'completeMaintenance'])->whereUuid('workId')->middleware(['erp.screen:ENG-MNT', 'erp.permission:ACTION:ENG-MNT:COMPLETE']);
            Route::post('/engineering/maintenance/{workId}/cancel', [FinanceOperationsController::class, 'cancelMaintenance'])->whereUuid('workId')->middleware(['erp.screen:ENG-MNT', 'erp.permission:ACTION:ENG-MNT:CANCEL']);

            Route::get('/finance/payables/integrations', [FinanceOperationsController::class, 'integrations'])->middleware('erp.screen:FIN-AP');
            Route::get('/finance/payables/exports/{exportId}', [FinanceOperationsController::class, 'export'])->whereUuid('exportId')->middleware('erp.screen:FIN-AP');
            Route::post('/finance/payables/bank-accounts', [FinanceOperationsController::class, 'createBank'])->middleware(['erp.screen:FIN-AP', 'erp.permission:ACTION:FIN-AP:BANK-MANAGE']);
            Route::post('/finance/payables/bank-accounts/{bankAccountId}', [FinanceOperationsController::class, 'updateBank'])->whereUuid('bankAccountId')->middleware(['erp.screen:FIN-AP', 'erp.permission:ACTION:FIN-AP:BANK-MANAGE']);
            Route::post('/finance/payables/exports', [FinanceOperationsController::class, 'createExport'])->middleware(['erp.screen:FIN-AP', 'erp.permission:ACTION:FIN-AP:EXPORT']);
            Route::post('/finance/payables/exports/{exportId}/acknowledge', [FinanceOperationsController::class, 'acknowledgeExport'])->whereUuid('exportId')->middleware(['erp.screen:FIN-AP', 'erp.permission:ACTION:FIN-AP:ACK']);

            Route::get('/finance/simulation', [FinanceSupplementController::class, 'simulations'])->middleware('erp.screen:FIN-SIM');
            Route::get('/finance/simulations/{simulationId}', [FinanceSupplementController::class, 'simulation'])->whereUuid('simulationId')->middleware('erp.screen:FIN-SIM');
            Route::post('/finance/simulations', [FinanceSupplementController::class, 'createSimulation'])->middleware(['erp.screen:FIN-SIM', 'erp.permission:ACTION:FIN-SIM:CREATE']);
            Route::post('/finance/simulations/{simulationId}/run', [FinanceSupplementController::class, 'runSimulation'])->whereUuid('simulationId')->middleware(['erp.screen:FIN-SIM', 'erp.permission:ACTION:FIN-SIM:RUN']);

            Route::get('/finance/adjustments', [FinanceSupplementController::class, 'adjustments'])->middleware('erp.screen:FIN-ADJ');
            Route::get('/finance/adjustments/{adjustmentId}', [FinanceSupplementController::class, 'adjustment'])->whereUuid('adjustmentId')->middleware('erp.screen:FIN-ADJ');
            Route::post('/finance/adjustments', [FinanceSupplementController::class, 'createAdjustment'])->middleware(['erp.screen:FIN-ADJ', 'erp.permission:ACTION:FIN-ADJ:CREATE']);
            Route::post('/finance/adjustments/{adjustmentId}/submit', [FinanceSupplementController::class, 'submitAdjustment'])->whereUuid('adjustmentId')->middleware(['erp.screen:FIN-ADJ', 'erp.permission:ACTION:FIN-ADJ:SUBMIT']);
            Route::post('/finance/adjustments/{adjustmentId}/approve', [FinanceSupplementController::class, 'approveAdjustment'])->whereUuid('adjustmentId')->middleware(['erp.screen:FIN-ADJ', 'erp.permission:ACTION:FIN-ADJ:APPROVE']);
            Route::post('/finance/adjustments/{adjustmentId}/post', [FinanceSupplementController::class, 'postAdjustment'])->whereUuid('adjustmentId')->middleware(['erp.screen:FIN-ADJ', 'erp.permission:ACTION:FIN-ADJ:POST']);
            Route::post('/finance/adjustments/{adjustmentId}/cancel', [FinanceSupplementController::class, 'cancelAdjustment'])->whereUuid('adjustmentId')->middleware(['erp.screen:FIN-ADJ', 'erp.permission:ACTION:FIN-ADJ:CANCEL']);

            Route::get('/finance/legacy-imports', [FinanceSupplementController::class, 'legacyImports'])->middleware('erp.screen:FIN-LEGACY');
            Route::get('/finance/legacy-imports/{batchId}', [FinanceSupplementController::class, 'legacyImport'])->whereUuid('batchId')->middleware('erp.screen:FIN-LEGACY');
            Route::post('/finance/legacy-imports', [FinanceSupplementController::class, 'createLegacyImport'])->middleware(['erp.screen:FIN-LEGACY', 'erp.permission:ACTION:FIN-LEGACY:CREATE']);
            Route::post('/finance/legacy-imports/{batchId}/validate', [FinanceSupplementController::class, 'validateLegacyImport'])->whereUuid('batchId')->middleware(['erp.screen:FIN-LEGACY', 'erp.permission:ACTION:FIN-LEGACY:VALIDATE']);
            Route::post('/finance/legacy-imports/{batchId}/post', [FinanceSupplementController::class, 'postLegacyImport'])->whereUuid('batchId')->middleware(['erp.screen:FIN-LEGACY', 'erp.permission:ACTION:FIN-LEGACY:POST']);

            Route::get('/finance/archive', [FinanceSupplementController::class, 'archiveDocuments'])->middleware('erp.screen:FIN-ARCH');
            Route::get('/finance/archive/{documentId}', [FinanceSupplementController::class, 'archiveDocument'])->whereUuid('documentId')->middleware('erp.screen:FIN-ARCH');
            Route::post('/finance/archive', [FinanceSupplementController::class, 'uploadArchive'])->middleware(['erp.screen:FIN-ARCH', 'erp.permission:ACTION:FIN-ARCH:UPLOAD']);
            Route::get('/finance/archive/{documentId}/download', [FinanceSupplementController::class, 'downloadArchive'])->whereUuid('documentId')->middleware(['erp.screen:FIN-ARCH', 'erp.permission:ACTION:FIN-ARCH:DOWNLOAD']);

            Route::get('/finance/opening-balances', [FinanceSupplementController::class, 'openingBalances'])->middleware('erp.screen:FIN-OPEN');
            Route::get('/finance/opening-balances/{batchId}', [FinanceSupplementController::class, 'openingBalance'])->whereUuid('batchId')->middleware('erp.screen:FIN-OPEN');
            Route::post('/finance/opening-balances', [FinanceSupplementController::class, 'createOpeningBalance'])->middleware(['erp.screen:FIN-OPEN', 'erp.permission:ACTION:FIN-OPEN:CREATE']);
            Route::post('/finance/opening-balances/{batchId}/reconcile', [FinanceSupplementController::class, 'reconcileOpeningBalance'])->whereUuid('batchId')->middleware(['erp.screen:FIN-OPEN', 'erp.permission:ACTION:FIN-OPEN:RECONCILE']);
            Route::post('/finance/opening-balances/{batchId}/post', [FinanceSupplementController::class, 'postOpeningBalance'])->whereUuid('batchId')->middleware(['erp.screen:FIN-OPEN', 'erp.permission:ACTION:FIN-OPEN:POST']);

            Route::get('/finance/support-cases', [FinanceSupplementController::class, 'supportCases'])->middleware('erp.screen:FIN-SUP');
            Route::get('/finance/support-cases/{caseId}', [FinanceSupplementController::class, 'supportCase'])->whereUuid('caseId')->middleware('erp.screen:FIN-SUP');
            Route::post('/finance/support-cases', [FinanceSupplementController::class, 'createSupportCase'])->middleware(['erp.screen:FIN-SUP', 'erp.permission:ACTION:FIN-SUP:CREATE']);
            Route::post('/finance/support-cases/{caseId}/diagnose', [FinanceSupplementController::class, 'diagnoseSupportCase'])->whereUuid('caseId')->middleware(['erp.screen:FIN-SUP', 'erp.permission:ACTION:FIN-SUP:DIAGNOSE']);
            Route::post('/finance/support-cases/{caseId}/close', [FinanceSupplementController::class, 'closeSupportCase'])->whereUuid('caseId')->middleware(['erp.screen:FIN-SUP', 'erp.permission:ACTION:FIN-SUP:CLOSE']);

            Route::get('/scale/plants', [MultiPlantController::class, 'index'])->middleware('erp.screen:SCALE-PLANT');
            Route::get('/scale/transfers/{transferId}', [MultiPlantController::class, 'transfer'])->whereUuid('transferId')->middleware('erp.screen:SCALE-PLANT');
            Route::get('/scale/transfer-routes/{routeId}', [MultiPlantController::class, 'route'])->whereUuid('routeId')->middleware('erp.screen:SCALE-PLANT');
            Route::get('/scale/consolidation-groups/{groupId}', [MultiPlantController::class, 'group'])->whereUuid('groupId')->middleware('erp.screen:SCALE-PLANT');
            Route::get('/scale/consolidations/{runId}', [MultiPlantController::class, 'consolidation'])->whereUuid('runId')->middleware('erp.screen:SCALE-PLANT');

            Route::post('/scale/consolidation-groups', [MultiPlantController::class, 'createGroup'])->middleware(['erp.screen:SCALE-PLANT', 'erp.permission:ACTION:SCALE-PLANT:GROUP-CREATE']);
            Route::post('/scale/consolidation-groups/{groupId}', [MultiPlantController::class, 'updateGroup'])->whereUuid('groupId')->middleware(['erp.screen:SCALE-PLANT', 'erp.permission:ACTION:SCALE-PLANT:GROUP-UPDATE']);
            Route::post('/scale/consolidation-groups/{groupId}/activate', [MultiPlantController::class, 'activateGroup'])->whereUuid('groupId')->middleware(['erp.screen:SCALE-PLANT', 'erp.permission:ACTION:SCALE-PLANT:GROUP-ACTIVATE']);
            Route::post('/scale/consolidation-groups/{groupId}/retire', [MultiPlantController::class, 'retireGroup'])->whereUuid('groupId')->middleware(['erp.screen:SCALE-PLANT', 'erp.permission:ACTION:SCALE-PLANT:GROUP-RETIRE']);

            Route::post('/scale/transfer-routes', [MultiPlantController::class, 'createRoute'])->middleware(['erp.screen:SCALE-PLANT', 'erp.permission:ACTION:SCALE-PLANT:ROUTE-CREATE']);
            Route::post('/scale/transfer-routes/{routeId}', [MultiPlantController::class, 'updateRoute'])->whereUuid('routeId')->middleware(['erp.screen:SCALE-PLANT', 'erp.permission:ACTION:SCALE-PLANT:ROUTE-UPDATE']);
            Route::post('/scale/transfer-routes/{routeId}/activate', [MultiPlantController::class, 'activateRoute'])->whereUuid('routeId')->middleware(['erp.screen:SCALE-PLANT', 'erp.permission:ACTION:SCALE-PLANT:ROUTE-ACTIVATE']);
            Route::post('/scale/transfer-routes/{routeId}/deactivate', [MultiPlantController::class, 'deactivateRoute'])->whereUuid('routeId')->middleware(['erp.screen:SCALE-PLANT', 'erp.permission:ACTION:SCALE-PLANT:ROUTE-DEACTIVATE']);

            Route::post('/scale/transfers', [MultiPlantController::class, 'createTransfer'])->middleware(['erp.screen:SCALE-PLANT', 'erp.permission:ACTION:SCALE-PLANT:TRANSFER-CREATE']);
            Route::post('/scale/transfers/{transferId}', [MultiPlantController::class, 'updateTransfer'])->whereUuid('transferId')->middleware(['erp.screen:SCALE-PLANT', 'erp.permission:ACTION:SCALE-PLANT:TRANSFER-UPDATE']);
            Route::post('/scale/transfers/{transferId}/submit', [MultiPlantController::class, 'submitTransfer'])->whereUuid('transferId')->middleware(['erp.screen:SCALE-PLANT', 'erp.permission:ACTION:SCALE-PLANT:TRANSFER-SUBMIT']);
            Route::post('/scale/transfers/{transferId}/approve', [MultiPlantController::class, 'approveTransfer'])->whereUuid('transferId')->middleware(['erp.screen:SCALE-PLANT', 'erp.permission:ACTION:SCALE-PLANT:TRANSFER-APPROVE']);
            Route::post('/scale/transfers/{transferId}/accept', [MultiPlantController::class, 'acceptTransfer'])->whereUuid('transferId')->middleware(['erp.screen:SCALE-PLANT', 'erp.permission:ACTION:SCALE-PLANT:TRANSFER-ACCEPT']);
            Route::post('/scale/transfers/{transferId}/dispatch', [MultiPlantController::class, 'dispatchTransfer'])->whereUuid('transferId')->middleware(['erp.screen:SCALE-PLANT', 'erp.permission:ACTION:SCALE-PLANT:TRANSFER-DISPATCH']);
            Route::post('/scale/transfers/{transferId}/receive', [MultiPlantController::class, 'receiveTransfer'])->whereUuid('transferId')->middleware(['erp.screen:SCALE-PLANT', 'erp.permission:ACTION:SCALE-PLANT:TRANSFER-RECEIVE']);
            Route::post('/scale/transfers/{transferId}/cancel', [MultiPlantController::class, 'cancelTransfer'])->whereUuid('transferId')->middleware(['erp.screen:SCALE-PLANT', 'erp.permission:ACTION:SCALE-PLANT:TRANSFER-CANCEL']);

            Route::post('/scale/consolidations', [MultiPlantController::class, 'createConsolidation'])->middleware(['erp.screen:SCALE-PLANT', 'erp.permission:ACTION:SCALE-PLANT:CONSOLIDATE']);
            Route::post('/scale/consolidations/{runId}/finalize', [MultiPlantController::class, 'finalizeConsolidation'])->whereUuid('runId')->middleware(['erp.screen:SCALE-PLANT', 'erp.permission:ACTION:SCALE-PLANT:CONSOLIDATION-FINALIZE']);
            Route::get('/partner/workspaces', [PartnerPortalController::class, 'index'])->middleware('erp.screen:PORTAL-EXT');
            Route::get('/partner/access-grants/{grantId}', [PartnerPortalController::class, 'grant'])
                ->whereUuid('grantId')->middleware('erp.screen:PORTAL-EXT');
            Route::post('/partner/access-grants', [PartnerPortalController::class, 'createGrant'])
                ->middleware(['erp.screen:PORTAL-EXT', 'erp.permission:ACTION:PORTAL-EXT:ACCESS-GRANT']);
            Route::post('/partner/access-grants/{grantId}', [PartnerPortalController::class, 'updateGrant'])
                ->whereUuid('grantId')->middleware(['erp.screen:PORTAL-EXT', 'erp.permission:ACTION:PORTAL-EXT:ACCESS-UPDATE']);
            Route::post('/partner/access-grants/{grantId}/revoke', [PartnerPortalController::class, 'revokeGrant'])
                ->whereUuid('grantId')->middleware(['erp.screen:PORTAL-EXT', 'erp.permission:ACTION:PORTAL-EXT:ACCESS-REVOKE']);

            Route::get('/partner/orders/{orderId}', [PartnerPortalController::class, 'order'])
                ->whereUuid('orderId')->middleware('erp.screen:PORTAL-EXT');
            Route::get('/partner/shipments/{shipmentId}', [PartnerPortalController::class, 'shipment'])
                ->whereUuid('shipmentId')->middleware('erp.screen:PORTAL-EXT');
            Route::get('/partner/invoices/{invoiceId}', [PartnerPortalController::class, 'invoice'])
                ->whereUuid('invoiceId')->middleware('erp.screen:PORTAL-EXT');
            Route::get('/partner/claims/{claimId}', [PartnerPortalController::class, 'claim'])
                ->whereUuid('claimId')->middleware('erp.screen:PORTAL-EXT');
            Route::post('/partner/claims', [PartnerPortalController::class, 'createClaim'])
                ->middleware(['erp.screen:PORTAL-EXT', 'erp.permission:ACTION:PORTAL-EXT:CLAIM-CREATE']);

            Route::post('/partner/documents/publish', [PartnerPortalController::class, 'publishDocument'])
                ->middleware(['erp.screen:PORTAL-EXT', 'erp.permission:ACTION:PORTAL-EXT:DOCUMENT-PUBLISH']);
            Route::post('/partner/documents', [PartnerPortalController::class, 'uploadDocument'])
                ->middleware(['erp.screen:PORTAL-EXT', 'erp.permission:ACTION:PORTAL-EXT:DOCUMENT-UPLOAD']);
            Route::get('/partner/documents/{documentId}', [PartnerPortalController::class, 'document'])
                ->whereUuid('documentId')->middleware('erp.screen:PORTAL-EXT');
            Route::get('/partner/documents/{documentId}/download', [PartnerPortalController::class, 'downloadDocument'])
                ->whereUuid('documentId')->middleware(['erp.screen:PORTAL-EXT', 'erp.permission:ACTION:PORTAL-EXT:DOCUMENT-DOWNLOAD']);
            Route::post('/partner/documents/{documentId}/acknowledge', [PartnerPortalController::class, 'acknowledgeDocument'])
                ->whereUuid('documentId')->middleware(['erp.screen:PORTAL-EXT', 'erp.permission:ACTION:PORTAL-EXT:DOCUMENT-ACKNOWLEDGE']);
            Route::post('/partner/documents/{documentId}/withdraw', [PartnerPortalController::class, 'withdrawDocument'])
                ->whereUuid('documentId')->middleware(['erp.screen:PORTAL-EXT', 'erp.permission:ACTION:PORTAL-EXT:DOCUMENT-WITHDRAW']);
            Route::get('/optimisation/plans', [OptimisationController::class, 'index'])
                ->middleware('erp.screen:OPT-PLAN');
            Route::get('/optimisation/plans/{planId}', [OptimisationController::class, 'show'])
                ->whereUuid('planId')->middleware('erp.screen:OPT-PLAN');
            Route::post('/optimisation/plans', [OptimisationController::class, 'create'])
                ->middleware(['erp.screen:OPT-PLAN', 'erp.permission:ACTION:OPT-PLAN:CREATE']);
            Route::post('/optimisation/plans/{planId}/inputs', [OptimisationController::class, 'revise'])
                ->whereUuid('planId')->middleware(['erp.screen:OPT-PLAN', 'erp.permission:ACTION:OPT-PLAN:REVISE']);
            Route::post('/optimisation/plans/{planId}/generate', [OptimisationController::class, 'generate'])
                ->whereUuid('planId')->middleware(['erp.screen:OPT-PLAN', 'erp.permission:ACTION:OPT-PLAN:GENERATE']);
            Route::post('/optimisation/plans/{planId}/submit', [OptimisationController::class, 'submit'])
                ->whereUuid('planId')->middleware(['erp.screen:OPT-PLAN', 'erp.permission:ACTION:OPT-PLAN:SUBMIT']);
            Route::post('/optimisation/plans/{planId}/approve', [OptimisationController::class, 'approve'])
                ->whereUuid('planId')->middleware(['erp.screen:OPT-PLAN', 'erp.permission:ACTION:OPT-PLAN:APPROVE']);
            Route::post('/optimisation/plans/{planId}/reject', [OptimisationController::class, 'reject'])
                ->whereUuid('planId')->middleware(['erp.screen:OPT-PLAN', 'erp.permission:ACTION:OPT-PLAN:REJECT']);
            Route::post('/optimisation/plans/{planId}/recommendations/{recommendationId}/outcome', [OptimisationController::class, 'outcome'])
                ->whereUuid('planId')->whereUuid('recommendationId')
                ->middleware(['erp.screen:OPT-PLAN', 'erp.permission:ACTION:OPT-PLAN:OUTCOME']);
            Route::post('/optimisation/plans/{planId}/complete', [OptimisationController::class, 'complete'])
                ->whereUuid('planId')->middleware(['erp.screen:OPT-PLAN', 'erp.permission:ACTION:OPT-PLAN:COMPLETE']);
            Route::post('/optimisation/plans/{planId}/cancel', [OptimisationController::class, 'cancel'])
                ->whereUuid('planId')->middleware(['erp.screen:OPT-PLAN', 'erp.permission:ACTION:OPT-PLAN:CANCEL']);
        });
    });
});

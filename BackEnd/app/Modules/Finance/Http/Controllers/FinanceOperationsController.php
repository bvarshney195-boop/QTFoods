<?php

namespace App\Modules\Finance\Http\Controllers;

use App\Modules\Finance\Application\FinanceOperationsQuery;
use App\Modules\Finance\Application\FinanceOperationsService;
use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use App\Shared\Visibility\OperationalVisibilityScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class FinanceOperationsController
{
    use BuildsAdminContext;

    public function __construct(private readonly FinanceOperationsQuery $query, private readonly FinanceOperationsService $service, private readonly SessionService $sessions) {}

    public function expenses(Request $request): JsonResponse { return $this->workspace('expenses', $request); }
    public function ledger(Request $request): JsonResponse { return $this->workspace('ledger', $request); }
    public function overheads(Request $request): JsonResponse { return $this->workspace('overheads', $request); }
    public function assets(Request $request): JsonResponse { return $this->workspace('assets', $request); }
    public function payroll(Request $request): JsonResponse { return $this->workspace('payroll', $request); }
    public function maintenance(Request $request): JsonResponse { return $this->workspace('maintenance', $request); }
    public function integrations(Request $request): JsonResponse { return $this->workspace('integrations', $request); }

    public function expense(string $expenseId, Request $request): JsonResponse { return $this->show('expense', $expenseId, $request); }
    public function journal(string $journalId, Request $request): JsonResponse { return $this->show('journal', $journalId, $request); }
    public function overhead(string $poolId, Request $request): JsonResponse { return $this->show('overhead', $poolId, $request); }
    public function asset(string $assetId, Request $request): JsonResponse { return $this->show('asset', $assetId, $request); }
    public function payrollRun(string $payrollId, Request $request): JsonResponse { return $this->show('payroll', $payrollId, $request); }
    public function maintenanceWork(string $workId, Request $request): JsonResponse { return $this->show('maintenance', $workId, $request); }
    public function export(string $exportId, Request $request): JsonResponse { return $this->show('export', $exportId, $request); }

    public function createJournal(Request $request): JsonResponse
    {
        $this->normalise($request, ['journal_number']); $validated = $request->validate(['journal_number' => $this->code(), 'fiscal_period_id' => ['required', 'uuid'], 'posting_date' => ['required', 'date_format:Y-m-d'], 'description' => ['required', 'string', 'max:255'], 'source_reference' => ['nullable', 'string', 'max:160'], 'lines' => ['required', 'array', 'between:2,200'], 'lines.*.account_id' => ['required', 'uuid'], 'lines.*.party_id' => ['nullable', 'uuid'], 'lines.*.description' => ['required', 'string', 'max:255'], 'lines.*.debit_amount' => $this->amount(), 'lines.*.credit_amount' => $this->amount()]); return $this->created($this->service->createJournal($validated + $this->commandContext($request, false)));
    }

    public function postJournal(string $journalId, Request $request): JsonResponse { return $this->ok($this->service->postJournal($journalId, $this->commandContext($request, true))); }
    public function reverseJournal(string $journalId, Request $request): JsonResponse { $this->normalise($request, ['reversal_number']); $v = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000'], 'reversal_number' => $this->code(), 'posting_date' => ['required', 'date_format:Y-m-d']]); return $this->ok($this->service->reverseJournal($journalId, $v['reason'], $v['reversal_number'], $v['posting_date'], $this->commandContext($request, true))); }
    public function closePeriod(string $periodId, Request $request): JsonResponse { $v = $request->validate(['notes' => ['required', 'string', 'min:3', 'max:4000']]); return $this->ok($this->service->closePeriod($periodId, $v['notes'], $this->commandContext($request, true))); }

    public function createExpense(Request $request): JsonResponse { $this->normalise($request, ['expense_number', 'category']); $v = $request->validate($this->expenseRules(true)); return $this->created($this->service->createExpense($v + $this->commandContext($request, false))); }
    public function updateExpense(string $expenseId, Request $request): JsonResponse { $this->normalise($request, ['category']); $v = $request->validate($this->expenseRules(false)); return $this->ok($this->service->updateExpense($expenseId, $v + $this->commandContext($request, true))); }
    public function submitExpense(string $expenseId, Request $request): JsonResponse { return $this->ok($this->service->transitionExpense($expenseId, 'SUBMIT', null, $this->commandContext($request, true))); }
    public function approveExpense(string $expenseId, Request $request): JsonResponse { return $this->ok($this->service->transitionExpense($expenseId, 'APPROVE', null, $this->commandContext($request, true))); }
    public function postExpense(string $expenseId, Request $request): JsonResponse { return $this->ok($this->service->transitionExpense($expenseId, 'POST', null, $this->commandContext($request, true))); }
    public function cancelExpense(string $expenseId, Request $request): JsonResponse { $v = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:2000']]); return $this->ok($this->service->transitionExpense($expenseId, 'CANCEL', $v['reason'], $this->commandContext($request, true))); }

    public function createOverhead(Request $request): JsonResponse { $this->normalise($request, ['pool_code', 'allocation_basis']); $v = $request->validate(['pool_code' => $this->shortCode(), 'name' => ['required', 'string', 'max:160'], 'expense_account_id' => ['required', 'uuid'], 'allocation_basis' => ['required', Rule::in(FinanceOperationsService::ALLOCATION_BASES)], 'rate' => $this->positiveAmount()]); return $this->created($this->service->createOverheadPool($v + $this->commandContext($request, false))); }
    public function updateOverhead(string $poolId, Request $request): JsonResponse { $this->normalise($request, ['allocation_basis', 'status']); $v = $request->validate(['name' => ['required', 'string', 'max:160'], 'expense_account_id' => ['required', 'uuid'], 'allocation_basis' => ['required', Rule::in(FinanceOperationsService::ALLOCATION_BASES)], 'rate' => $this->positiveAmount(), 'status' => ['required', Rule::in(['ACTIVE', 'INACTIVE'])]]); return $this->ok($this->service->updateOverheadPool($poolId, $v + $this->commandContext($request, true))); }
    public function allocateOverhead(string $poolId, Request $request): JsonResponse { $this->normalise($request, ['allocation_number']); $v = $request->validate(['allocation_number' => $this->code(), 'fiscal_period_id' => ['required', 'uuid'], 'posting_date' => ['required', 'date_format:Y-m-d'], 'production_order_id' => ['nullable', 'uuid'], 'target_account_id' => ['required', 'uuid'], 'basis_quantity' => $this->positiveAmount()]); return $this->created($this->service->allocateOverhead($poolId, $v + $this->commandContext($request, false))); }

    public function createAsset(Request $request): JsonResponse { $this->normalise($request, ['asset_number', 'category']); $v = $request->validate($this->assetRules(true)); return $this->created($this->service->createAsset($v + $this->commandContext($request, false))); }
    public function updateAsset(string $assetId, Request $request): JsonResponse { $this->normalise($request, ['category']); $v = $request->validate($this->assetRules(false)); return $this->ok($this->service->updateAsset($assetId, $v + $this->commandContext($request, true))); }
    public function activateAsset(string $assetId, Request $request): JsonResponse { $v = $request->validate(['posting_date' => ['required', 'date_format:Y-m-d']]); return $this->ok($this->service->activateAsset($assetId, $v['posting_date'], $this->commandContext($request, true))); }
    public function depreciateAsset(string $assetId, Request $request): JsonResponse { $v = $request->validate(['fiscal_period_id' => ['required', 'uuid']]); return $this->ok($this->service->depreciateAsset($assetId, $v['fiscal_period_id'], $this->commandContext($request, true))); }
    public function disposeAsset(string $assetId, Request $request): JsonResponse { $v = $request->validate(['posting_date' => ['required', 'date_format:Y-m-d'], 'disposal_proceeds' => $this->amount(), 'reason' => ['required', 'string', 'min:3', 'max:2000']]); return $this->ok($this->service->disposeAsset($assetId, $v['posting_date'], $v['disposal_proceeds'], $v['reason'], $this->commandContext($request, true))); }

    public function createEmployee(Request $request): JsonResponse { $this->normalise($request, ['employee_number']); $v = $request->validate($this->employeeRules(true)); return $this->created($this->service->createEmployee($v + $this->commandContext($request, false))); }
    public function updateEmployee(string $employeeId, Request $request): JsonResponse { $this->normalise($request, ['status']); $v = $request->validate($this->employeeRules(false)); return $this->ok($this->service->updateEmployee($employeeId, $v + $this->commandContext($request, true))); }
    public function createPayroll(Request $request): JsonResponse { $this->normalise($request, ['run_number']); $v = $request->validate(['run_number' => $this->code(), 'period_start' => ['required', 'date_format:Y-m-d'], 'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start'], 'employee_ids' => ['nullable', 'array', 'between:1,500'], 'employee_ids.*' => ['uuid', 'distinct']]); return $this->created($this->service->createPayroll($v + $this->commandContext($request, false))); }
    public function approvePayroll(string $payrollId, Request $request): JsonResponse { return $this->ok($this->service->transitionPayroll($payrollId, 'APPROVE', $this->commandContext($request, true))); }
    public function postPayroll(string $payrollId, Request $request): JsonResponse { return $this->ok($this->service->transitionPayroll($payrollId, 'POST', $this->commandContext($request, true))); }

    public function createMaintenance(Request $request): JsonResponse { $this->normalise($request, ['work_order_number', 'priority']); $v = $request->validate($this->maintenanceRules(true)); return $this->created($this->service->createMaintenance($v + $this->commandContext($request, false))); }
    public function updateMaintenance(string $workId, Request $request): JsonResponse { $this->normalise($request, ['priority']); $v = $request->validate($this->maintenanceRules(false)); return $this->ok($this->service->updateMaintenance($workId, $v + $this->commandContext($request, true))); }
    public function releaseMaintenance(string $workId, Request $request): JsonResponse { return $this->ok($this->service->transitionMaintenance($workId, 'RELEASE', null, $this->commandContext($request, true))); }
    public function completeMaintenance(string $workId, Request $request): JsonResponse { $v = $request->validate(['posting_date' => ['required', 'date_format:Y-m-d'], 'labour_cost' => $this->amount(), 'material_cost' => $this->amount(), 'external_cost' => $this->amount()]); return $this->ok($this->service->transitionMaintenance($workId, 'COMPLETE', $v, $this->commandContext($request, true))); }
    public function cancelMaintenance(string $workId, Request $request): JsonResponse { return $this->ok($this->service->transitionMaintenance($workId, 'CANCEL', null, $this->commandContext($request, true))); }

    public function createBank(Request $request): JsonResponse { $this->normalise($request, ['account_code', 'ifsc_code']); $v = $request->validate($this->bankRules(true)); return $this->created($this->service->upsertBankAccount(null, $v + $this->commandContext($request, false))); }
    public function updateBank(string $bankAccountId, Request $request): JsonResponse { $this->normalise($request, ['ifsc_code', 'status']); $v = $request->validate($this->bankRules(false)); return $this->ok($this->service->upsertBankAccount($bankAccountId, $v + $this->commandContext($request, true))); }
    public function createExport(Request $request): JsonResponse { $this->normalise($request, ['export_number', 'export_type']); $v = $request->validate(['export_number' => $this->code(), 'export_type' => ['required', Rule::in(FinanceOperationsService::EXPORT_TYPES)], 'bank_account_id' => ['nullable', 'uuid', 'required_if:export_type,AP_BANK'], 'period_start' => ['required', 'date_format:Y-m-d'], 'period_end' => ['required', 'date_format:Y-m-d', 'after_or_equal:period_start']]); return $this->created($this->service->createExport($v + $this->commandContext($request, false))); }
    public function acknowledgeExport(string $exportId, Request $request): JsonResponse { $v = $request->validate(['acknowledgement_reference' => ['required', 'string', 'max:120']]); return $this->ok($this->service->acknowledgeExport($exportId, $v['acknowledgement_reference'], $this->commandContext($request, true))); }

    protected function sessionService(): SessionService { return $this->sessions; }
    private function workspace(string $area, Request $request): JsonResponse { $filters = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', 'string', 'max:24'], 'visibility' => ['nullable', Rule::in(OperationalVisibilityScope::FILTERS)]]); return response()->json($this->query->workspace($area, $this->selectedScope($request, true), $this->currentPermissions($request), $filters)); }
    private function show(string $area, string $id, Request $request): JsonResponse { return response()->json(['data' => $this->query->detail($area, $id, $this->selectedScope($request, true), $this->currentPermissions($request))]); }
    private function created(array $data): JsonResponse { return response()->json(['data' => $data], 201); }
    private function ok(array $data): JsonResponse { return response()->json(['data' => $data]); }
    private function code(): array { return ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/']; }
    private function shortCode(): array { return ['required', 'string', 'max:40', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/']; }
    private function amount(): array { return ['required', 'numeric', 'min:0', 'max:99999999999999', 'decimal:0,6']; }
    private function positiveAmount(): array { return ['required', 'numeric', 'gt:0', 'max:99999999999999', 'decimal:0,6']; }
    private function expenseRules(bool $create): array { return ['expense_number' => $create ? $this->code() : ['prohibited'], 'claimant_user_id' => ['required', 'uuid'], 'expense_date' => ['required', 'date_format:Y-m-d'], 'category' => ['required', Rule::in(FinanceOperationsService::EXPENSE_CATEGORIES)], 'description' => ['required', 'string', 'max:4000'], 'lines' => ['required', 'array', 'between:1,100'], 'lines.*.expense_account_id' => ['required', 'uuid'], 'lines.*.description' => ['required', 'string', 'max:255'], 'lines.*.net_amount' => $this->positiveAmount(), 'lines.*.tax_rate' => ['required', 'numeric', 'between:0,100', 'decimal:0,4']]; }
    private function assetRules(bool $create): array { return ['asset_number' => $create ? $this->code() : ['prohibited'], 'name' => ['required', 'string', 'max:160'], 'category' => ['required', 'string', 'max:40'], 'acquisition_date' => ['required', 'date_format:Y-m-d'], 'acquisition_cost' => $this->positiveAmount(), 'residual_value' => $this->amount(), 'useful_life_months' => ['required', 'integer', 'between:1,1200'], 'asset_account_id' => ['required', 'uuid'], 'depreciation_account_id' => ['required', 'uuid'], 'depreciation_expense_account_id' => ['required', 'uuid']]; }
    private function employeeRules(bool $create): array { return ['employee_number' => $create ? $this->shortCode() : ['prohibited'], 'name' => ['required', 'string', 'max:160'], 'department' => ['required', 'string', 'max:80'], 'monthly_gross' => $this->positiveAmount(), 'monthly_deductions' => $this->amount(), 'expense_account_id' => ['required', 'uuid'], 'payable_account_id' => ['required', 'uuid'], 'status' => $create ? ['prohibited'] : ['required', Rule::in(['ACTIVE', 'INACTIVE'])]]; }
    private function maintenanceRules(bool $create): array { return ['work_order_number' => $create ? $this->code() : ['prohibited'], 'asset_id' => ['nullable', 'uuid'], 'priority' => ['required', Rule::in(FinanceOperationsService::PRIORITIES)], 'description' => ['required', 'string', 'max:4000'], 'planned_date' => ['required', 'date_format:Y-m-d'], 'labour_cost' => $this->amount(), 'material_cost' => $this->amount(), 'external_cost' => $this->amount(), 'expense_account_id' => ['required', 'uuid']]; }
    private function bankRules(bool $create): array { return ['account_code' => $create ? $this->shortCode() : ['prohibited'], 'bank_name' => ['required', 'string', 'max:160'], 'account_name' => ['required', 'string', 'max:160'], 'masked_account_number' => ['required', 'string', 'max:40'], 'ifsc_code' => ['required', 'string', 'max:20'], 'status' => $create ? ['prohibited'] : ['required', Rule::in(['ACTIVE', 'INACTIVE'])]]; }
    private function normalise(Request $request, array $upper): void { $input = $request->all(); foreach ($upper as $field) if (is_string($input[$field] ?? null)) $input[$field] = Str::upper(trim($input[$field])); $request->replace($input); }
}

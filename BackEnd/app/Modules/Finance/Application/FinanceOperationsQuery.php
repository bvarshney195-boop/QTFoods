<?php

namespace App\Modules\Finance\Application;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FinanceOperationsQuery
{
    public function workspace(string $area, array $scope, array $permissions, array $filters = []): array
    {
        return match ($area) {
            'expenses' => $this->expenses($scope, $permissions, $filters),
            'ledger' => $this->ledger($scope, $permissions, $filters),
            'overheads' => $this->overheads($scope, $permissions),
            'assets' => $this->assets($scope, $permissions, $filters),
            'payroll' => $this->payroll($scope, $permissions),
            'maintenance' => $this->maintenance($scope, $permissions, $filters),
            'integrations' => $this->integrations($scope, $permissions),
            default => throw new NotFoundHttpException('Finance workspace not found.'),
        };
    }

    public function detail(string $area, string $id, array $scope, array $permissions): array
    {
        [$table, $label, $lineTable, $foreignKey] = match ($area) {
            'expense' => ['expense_claims', 'Expense claim', 'expense_claim_lines', 'expense_claim_id'],
            'journal' => ['journals', 'Journal', 'journal_lines', 'journal_id'],
            'overhead' => ['overhead_pools', 'Overhead pool', 'overhead_allocations', 'overhead_pool_id'],
            'asset' => ['assets', 'Asset', 'asset_depreciation_entries', 'asset_id'],
            'payroll' => ['payroll_runs', 'Payroll run', 'payroll_lines', 'payroll_run_id'],
            'maintenance' => ['maintenance_work_orders', 'Maintenance work order', null, null],
            'export' => ['finance_exports', 'Finance export', 'finance_export_lines', 'finance_export_id'],
            default => throw new NotFoundHttpException('Finance record type not found.'),
        };
        $record = DB::table($table)->where('id', $id)->where($scope)->first();
        if (! $record) throw new NotFoundHttpException($label.' not found.');
        $payload = $this->payload($record);
        if ($lineTable) {
            $lineQuery = DB::table($lineTable)->where($foreignKey, $id);
            $payload['lines'] = (in_array($lineTable, ['overhead_allocations', 'asset_depreciation_entries'], true) ? $lineQuery->orderBy('created_at') : $lineQuery->orderBy('line_number'))
                ->get()->map(fn (object $row) => $this->payload($row))->all();
        }
        $payload['allowed_actions'] = $this->recordActions($area, (string) $record->status, $permissions, $record);
        return $payload;
    }

    private function expenses(array $scope, array $permissions, array $filters): array
    {
        $query = DB::table('expense_claims')->where($scope); $this->filter($query, $filters, ['expense_number', 'description']);
        $data = $query->orderByDesc('expense_date')->limit(100)->get()->map(fn (object $row) => $this->payload($row) + ['allowed_actions' => $this->recordActions('expense', $row->status, $permissions, $row)])->all();
        return $this->base($data, ['count' => DB::table('expense_claims')->where($scope)->count(), 'pending_approval' => DB::table('expense_claims')->where($scope)->where('status', 'SUBMITTED')->count(), 'approved_value' => $this->decimal(DB::table('expense_claims')->where($scope)->where('status', 'APPROVED')->sum('total_amount'))], $scope, $permissions, 'FIN-EXP', ['categories' => FinanceOperationsService::EXPENSE_CATEGORIES, 'users' => $this->users(), 'accounts' => $this->accounts($scope)]);
    }

    private function ledger(array $scope, array $permissions, array $filters): array
    {
        $query = DB::table('journals')->where($scope)->where('journal_type', '<>', 'LEGACY'); $this->filter($query, $filters, ['journal_number', 'description', 'source_reference']);
        $data = $query->orderByDesc('posting_date')->limit(150)->get()->map(fn (object $row) => $this->payload($row) + ['allowed_actions' => $this->recordActions('journal', $row->status, $permissions, $row)])->all();
        $periods = DB::table('fiscal_periods')->where('company_id', $scope['company_id'])->orderByDesc('starts_on')->get()->map(fn (object $row) => $this->payload($row) + ['allowed_actions' => $row->status === 'OPEN' && $this->can($permissions, 'ACTION:FIN-GL:PERIOD-CLOSE') ? ['CLOSE'] : []])->all();
        return $this->base($data, ['journal_count' => count($data), 'draft_count' => collect($data)->where('status', 'DRAFT')->count(), 'posted_debits' => $this->decimal(DB::table('journals')->where($scope)->where('status', 'POSTED')->sum('total_debit'))], $scope, $permissions, 'FIN-GL', ['periods' => $periods, 'accounts' => $this->accounts($scope), 'journal_types' => FinanceOperationsService::JOURNAL_TYPES]);
    }

    private function overheads(array $scope, array $permissions): array
    {
        $data = DB::table('overhead_pools')->where($scope)->orderBy('pool_code')->get()->map(fn (object $row) => $this->payload($row) + ['allowed_actions' => $this->recordActions('overhead', $row->status, $permissions, $row)])->all();
        $allocations = DB::table('overhead_allocations')->where($scope)->orderByDesc('created_at')->limit(100)->get()->map(fn (object $row) => $this->payload($row))->all();
        return $this->base($data, ['pool_count' => count($data), 'allocated_total' => $this->decimal(DB::table('overhead_allocations')->where($scope)->sum('allocated_amount'))], $scope, $permissions, 'COST-OH', ['allocation_bases' => FinanceOperationsService::ALLOCATION_BASES, 'accounts' => $this->accounts($scope), 'periods' => $this->periods($scope), 'allocations' => $allocations]);
    }

    private function assets(array $scope, array $permissions, array $filters): array
    {
        $query = DB::table('assets')->where($scope)->where('asset_type', 'FIXED_ASSET'); $this->filter($query, $filters, ['asset_number', 'name', 'category']);
        $data = $query->orderBy('asset_number')->get()->map(fn (object $row) => $this->payload($row) + ['allowed_actions' => $this->recordActions('asset', $row->status, $permissions, $row)])->all();
        return $this->base($data, ['asset_count' => count($data), 'acquisition_cost' => $this->decimal(DB::table('assets')->where($scope)->where('asset_type', 'FIXED_ASSET')->sum('acquisition_cost')), 'net_book_value' => $this->decimal(DB::table('assets')->where($scope)->where('asset_type', 'FIXED_ASSET')->sum('net_book_value'))], $scope, $permissions, 'ASSET-REG', ['accounts' => $this->accounts($scope), 'periods' => $this->periods($scope)]);
    }

    private function payroll(array $scope, array $permissions): array
    {
        $runs = DB::table('payroll_runs')->where($scope)->where('run_type', 'PAYROLL')->orderByDesc('period_end')->get()->map(fn (object $row) => $this->payload($row) + ['allowed_actions' => $this->recordActions('payroll', $row->status, $permissions, $row)])->all();
        $employees = DB::table('employees')->where($scope)->orderBy('employee_number')->get()->map(fn (object $row) => $this->payload($row) + ['allowed_actions' => $this->can($permissions, 'ACTION:HR-PAY:EMPLOYEE-UPDATE') ? ['UPDATE'] : []])->all();
        return $this->base($runs, ['run_count' => count($runs), 'active_employees' => collect($employees)->where('status', 'ACTIVE')->count(), 'latest_net_pay' => $runs[0]['net_amount'] ?? '0.000000'], $scope, $permissions, 'HR-PAY', ['employees' => $employees, 'accounts' => $this->accounts($scope)]);
    }

    private function maintenance(array $scope, array $permissions, array $filters): array
    {
        $query = DB::table('maintenance_work_orders')->where($scope)->where('work_type', 'MAINTENANCE'); $this->filter($query, $filters, ['work_order_number', 'description']);
        $data = $query->orderByDesc('planned_date')->get()->map(fn (object $row) => $this->payload($row) + ['allowed_actions' => $this->recordActions('maintenance', $row->status, $permissions, $row)])->all();
        return $this->base($data, ['work_count' => count($data), 'released_count' => collect($data)->where('status', 'RELEASED')->count(), 'completed_cost' => $this->decimal(DB::table('maintenance_work_orders')->where($scope)->where('work_type', 'MAINTENANCE')->where('status', 'COMPLETED')->sum('total_cost'))], $scope, $permissions, 'ENG-MNT', ['priorities' => FinanceOperationsService::PRIORITIES, 'assets' => DB::table('assets')->where($scope)->where('asset_type', 'FIXED_ASSET')->get(['id', 'asset_number', 'name', 'status'])->map(fn (object $row) => $this->payload($row))->all(), 'accounts' => $this->accounts($scope)]);
    }

    private function integrations(array $scope, array $permissions): array
    {
        $exports = DB::table('finance_exports')->where($scope)->orderByDesc('created_at')->get()->map(fn (object $row) => $this->payload($row) + ['allowed_actions' => $this->recordActions('export', $row->status, $permissions, $row)])->all();
        $banks = DB::table('bank_accounts')->where('company_id', $scope['company_id'])->orderBy('account_code')->get()->map(fn (object $row) => $this->payload($row) + ['allowed_actions' => $this->can($permissions, 'ACTION:FIN-AP:BANK-MANAGE') ? ['UPDATE'] : []])->all();
        return $this->base($exports, ['export_count' => count($exports), 'generated_count' => collect($exports)->where('status', 'GENERATED')->count()], $scope, $permissions, 'FIN-AP', ['banks' => $banks, 'export_types' => FinanceOperationsService::EXPORT_TYPES]);
    }

    private function base(array $data, array $summary, array $scope, array $permissions, string $screen, array $lookups): array
    {
        $prefix = 'ACTION:'.$screen.':'; return ['data' => $data, 'meta' => ['total' => count($data)], 'summary' => $summary, 'lookups' => $lookups, 'allowed_actions' => collect($permissions)->filter(fn (string $permission) => str_starts_with($permission, $prefix))->map(fn (string $permission) => substr($permission, strlen($prefix)))->values()->all(), 'scope' => $scope];
    }

    private function recordActions(string $area, string $status, array $permissions, object $row): array
    {
        $rules = match ($area) {
            'expense' => ['DRAFT' => [['UPDATE', 'ACTION:FIN-EXP:UPDATE'], ['SUBMIT', 'ACTION:FIN-EXP:SUBMIT'], ['CANCEL', 'ACTION:FIN-EXP:CANCEL']], 'SUBMITTED' => [['APPROVE', 'ACTION:FIN-EXP:APPROVE'], ['CANCEL', 'ACTION:FIN-EXP:CANCEL']], 'APPROVED' => [['POST', 'ACTION:FIN-EXP:POST'], ['CANCEL', 'ACTION:FIN-EXP:CANCEL']]],
            'journal' => ['DRAFT' => [['POST', 'ACTION:FIN-GL:JOURNAL-POST']], 'POSTED' => [['REVERSE', 'ACTION:FIN-GL:JOURNAL-REVERSE']]],
            'overhead' => ['ACTIVE' => [['UPDATE', 'ACTION:COST-OH:UPDATE'], ['ALLOCATE', 'ACTION:COST-OH:ALLOCATE']], 'INACTIVE' => [['UPDATE', 'ACTION:COST-OH:UPDATE']]],
            'asset' => ['DRAFT' => [['UPDATE', 'ACTION:ASSET-REG:UPDATE'], ['ACTIVATE', 'ACTION:ASSET-REG:ACTIVATE']], 'ACTIVE' => [['DEPRECIATE', 'ACTION:ASSET-REG:DEPRECIATE'], ['DISPOSE', 'ACTION:ASSET-REG:DISPOSE']]],
            'payroll' => ['DRAFT' => [['APPROVE', 'ACTION:HR-PAY:APPROVE']], 'APPROVED' => [['POST', 'ACTION:HR-PAY:POST']]],
            'maintenance' => ['DRAFT' => [['UPDATE', 'ACTION:ENG-MNT:UPDATE'], ['RELEASE', 'ACTION:ENG-MNT:RELEASE'], ['CANCEL', 'ACTION:ENG-MNT:CANCEL']], 'RELEASED' => [['COMPLETE', 'ACTION:ENG-MNT:COMPLETE'], ['CANCEL', 'ACTION:ENG-MNT:CANCEL']]],
            'export' => ['GENERATED' => [['ACKNOWLEDGE', 'ACTION:FIN-AP:ACK']]],
            default => [],
        };
        return collect($rules[$status] ?? [])->filter(fn (array $rule) => $this->can($permissions, $rule[1]))->pluck(0)->values()->all();
    }

    private function accounts(array $scope): array { return DB::table('chart_accounts')->where('company_id', $scope['company_id'])->where('status', 'ACTIVE')->orderBy('account_code')->get(['id', 'account_code', 'name', 'account_type', 'control_type'])->map(fn (object $row) => $this->payload($row))->all(); }
    private function periods(array $scope): array { return DB::table('fiscal_periods')->where('company_id', $scope['company_id'])->orderByDesc('starts_on')->get()->map(fn (object $row) => $this->payload($row))->all(); }
    private function users(): array { return DB::table('users')->where('status', 'ACTIVE')->orderBy('name')->get(['id', 'name', 'email'])->map(fn (object $row) => $this->payload($row))->all(); }
    private function can(array $permissions, string $permission): bool { return in_array($permission, $permissions, true); }
    private function decimal(mixed $value): string { return bcadd((string) ($value ?? 0), '0', 6); }
    private function filter(object $query, array $filters, array $columns): void { if (! empty($filters['status'])) $query->where('status', $filters['status']); if (! empty($filters['q'])) $query->where(function ($nested) use ($filters, $columns): void { foreach ($columns as $index => $column) $index === 0 ? $nested->where($column, 'like', '%'.$filters['q'].'%') : $nested->orWhere($column, 'like', '%'.$filters['q'].'%'); }); }
    private function payload(object $row): array { $values = (array) $row; foreach ($values as $key => $value) { if (is_string($value) && (str_ends_with($key, '_json') || $key === 'payload_json')) { try { $values[$key] = json_decode($value, true, flags: JSON_THROW_ON_ERROR); } catch (\Throwable) {} } } if (($values['asset_type'] ?? null) === 'FIXED_ASSET') { $values['historical_acquisition_cost'] = $this->decimal($values['acquisition_cost'] ?? 0); $values['current_carrying_amount'] = $this->decimal($values['net_book_value'] ?? 0); $values['carrying_amount_basis'] = ($values['status'] ?? null) === 'DISPOSED' ? 'DERECOGNISED_AFTER_DISPOSAL' : 'ACQUISITION_LESS_ACCUMULATED_DEPRECIATION'; } return $values; }
}

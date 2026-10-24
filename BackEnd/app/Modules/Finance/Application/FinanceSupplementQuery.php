<?php

namespace App\Modules\Finance\Application;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FinanceSupplementQuery
{
    public function workspace(string $area, array $scope, array $permissions, array $filters = []): array
    {
        [$table, $screen, $number, $date] = match ($area) {
            'simulations' => ['finance_simulations', 'FIN-SIM', 'simulation_number', 'as_of_date'],
            'adjustments' => ['finance_adjustments', 'FIN-ADJ', 'adjustment_number', 'adjustment_date'],
            'legacy' => ['legacy_import_batches', 'FIN-LEGACY', 'batch_number', 'posting_date'],
            'archive' => ['bill_archive_documents', 'FIN-ARCH', 'document_number', 'document_date'],
            'opening' => ['opening_balance_batches', 'FIN-OPEN', 'batch_number', 'opening_date'],
            'support' => ['finance_support_cases', 'FIN-SUP', 'case_number', 'created_at'],
            default => throw new NotFoundHttpException('Finance supplement workspace not found.'),
        };
        $query = DB::table($table)->where($scope); if (! empty($filters['status']) && $area !== 'archive') $query->where('status', $filters['status']); if (! empty($filters['q'])) $query->where(function ($nested) use ($filters, $number): void { $nested->where($number, 'like', '%'.$filters['q'].'%'); });
        $data = $query->orderByDesc($date)->limit(150)->get()->map(fn (object $row) => $this->payload($row) + ['allowed_actions' => $this->actions($area, (string) ($row->status ?? 'ARCHIVED'), $permissions)])->all();
        return ['data' => $data, 'meta' => ['total' => count($data)], 'summary' => $this->summary($area, $scope), 'lookups' => $this->lookups($area, $scope), 'allowed_actions' => $this->screenActions($screen, $permissions), 'scope' => $scope];
    }

    public function detail(string $area, string $id, array $scope, array $permissions): array
    {
        [$table, $label, $lineTable, $foreignKey] = match ($area) {
            'simulation' => ['finance_simulations', 'Finance simulation', 'finance_simulation_lines', 'finance_simulation_id'],
            'adjustment' => ['finance_adjustments', 'Finance adjustment', 'finance_adjustment_lines', 'finance_adjustment_id'],
            'legacy' => ['legacy_import_batches', 'Legacy import batch', 'legacy_import_rows', 'legacy_import_batch_id'],
            'archive' => ['bill_archive_documents', 'Archived document', null, null],
            'opening' => ['opening_balance_batches', 'Opening balance batch', 'opening_balance_lines', 'opening_balance_batch_id'],
            'support' => ['finance_support_cases', 'Finance support case', 'finance_support_actions', 'finance_support_case_id'],
            default => throw new NotFoundHttpException('Finance supplement record type not found.'),
        };
        $row = DB::table($table)->where('id', $id)->where($scope)->first(); if (! $row) throw new NotFoundHttpException($label.' not found.'); $payload = $this->payload($row); if ($lineTable) { $order = $lineTable === 'finance_support_actions' ? 'created_at' : ($lineTable === 'legacy_import_rows' ? 'row_number' : 'line_number'); $payload['lines'] = DB::table($lineTable)->where($foreignKey, $id)->orderBy($order)->get()->map(fn (object $line) => $this->payload($line))->all(); } $actionArea = match ($area) { 'simulation' => 'simulations', 'adjustment' => 'adjustments', default => $area }; $payload['allowed_actions'] = $this->actions($actionArea, (string) ($row->status ?? 'ARCHIVED'), $permissions); return $payload;
    }

    public function archiveDocument(string $id, array $scope): object
    {
        $document = DB::table('bill_archive_documents')->where('id', $id)->where($scope)->first(); if (! $document) throw new NotFoundHttpException('Archived document not found.'); return $document;
    }

    private function summary(string $area, array $scope): array
    {
        return match ($area) {
            'simulations' => ['count' => DB::table('finance_simulations')->where($scope)->count(), 'run_count' => DB::table('finance_simulations')->where($scope)->where('status', 'RUN')->count(), 'ledger_effect' => 'NONE'],
            'adjustments' => ['count' => DB::table('finance_adjustments')->where($scope)->count(), 'awaiting_approval' => DB::table('finance_adjustments')->where($scope)->where('status', 'SUBMITTED')->count(), 'posted_value' => $this->decimal(DB::table('finance_adjustments')->where($scope)->where('status', 'POSTED')->sum('total_debit'))],
            'legacy' => ['count' => DB::table('legacy_import_batches')->where($scope)->count(), 'invalid_count' => DB::table('legacy_import_batches')->where($scope)->where('status', 'INVALID')->count(), 'posted_count' => DB::table('legacy_import_batches')->where($scope)->where('status', 'POSTED')->count()],
            'archive' => ['count' => DB::table('bill_archive_documents')->where($scope)->count(), 'size_bytes' => (int) DB::table('bill_archive_documents')->where($scope)->sum('size_bytes')],
            'opening' => ['count' => DB::table('opening_balance_batches')->where($scope)->count(), 'unbalanced_count' => DB::table('opening_balance_batches')->where($scope)->where('difference_amount', '<>', 0)->count(), 'posted_count' => DB::table('opening_balance_batches')->where($scope)->where('status', 'POSTED')->count()],
            'support' => ['count' => DB::table('finance_support_cases')->where($scope)->count(), 'open_count' => DB::table('finance_support_cases')->where($scope)->whereIn('status', ['OPEN', 'DIAGNOSED'])->count(), 'critical_count' => DB::table('finance_support_cases')->where($scope)->where('severity', 'CRITICAL')->whereIn('status', ['OPEN', 'DIAGNOSED'])->count()],
        };
    }

    private function lookups(string $area, array $scope): array
    {
        $accounts = DB::table('chart_accounts')->where('company_id', $scope['company_id'])->where('status', 'ACTIVE')->orderBy('account_code')->get(['id', 'account_code', 'name', 'account_type'])->map(fn (object $row) => $this->payload($row))->all();
        return match ($area) {
            'simulations', 'adjustments', 'legacy', 'opening' => ['accounts' => $accounts, 'adjustment_types' => FinanceSupplementService::ADJUSTMENT_TYPES],
            'archive' => ['document_types' => FinanceSupplementService::DOCUMENT_TYPES],
            'support' => ['categories' => FinanceSupplementService::SUPPORT_CATEGORIES, 'severities' => FinanceSupplementService::SEVERITIES],
        };
    }

    private function actions(string $area, string $status, array $permissions): array
    {
        $rules = match ($area) {
            'simulations' => ['DRAFT' => [['RUN', 'ACTION:FIN-SIM:RUN']], 'RUN' => [['RUN', 'ACTION:FIN-SIM:RUN']]],
            'adjustments' => ['DRAFT' => [['SUBMIT', 'ACTION:FIN-ADJ:SUBMIT'], ['CANCEL', 'ACTION:FIN-ADJ:CANCEL']], 'SUBMITTED' => [['APPROVE', 'ACTION:FIN-ADJ:APPROVE'], ['CANCEL', 'ACTION:FIN-ADJ:CANCEL']], 'APPROVED' => [['POST', 'ACTION:FIN-ADJ:POST'], ['CANCEL', 'ACTION:FIN-ADJ:CANCEL']]],
            'legacy' => ['STAGED' => [['VALIDATE', 'ACTION:FIN-LEGACY:VALIDATE']], 'INVALID' => [['VALIDATE', 'ACTION:FIN-LEGACY:VALIDATE']], 'VALID' => [['POST', 'ACTION:FIN-LEGACY:POST']]],
            'archive' => ['ARCHIVED' => [['DOWNLOAD', 'ACTION:FIN-ARCH:DOWNLOAD']]],
            'opening' => ['DRAFT' => [['RECONCILE', 'ACTION:FIN-OPEN:RECONCILE']], 'RECONCILED' => [['POST', 'ACTION:FIN-OPEN:POST']]],
            'support' => ['OPEN' => [['DIAGNOSE', 'ACTION:FIN-SUP:DIAGNOSE'], ['CLOSE', 'ACTION:FIN-SUP:CLOSE']], 'DIAGNOSED' => [['DIAGNOSE', 'ACTION:FIN-SUP:DIAGNOSE'], ['CLOSE', 'ACTION:FIN-SUP:CLOSE']]],
            default => [],
        };
        return collect($rules[$status] ?? [])->filter(fn (array $rule) => in_array($rule[1], $permissions, true))->pluck(0)->values()->all();
    }

    private function screenActions(string $screen, array $permissions): array { $prefix = 'ACTION:'.$screen.':'; return collect($permissions)->filter(fn (string $permission) => str_starts_with($permission, $prefix))->map(fn (string $permission) => substr($permission, strlen($prefix)))->values()->all(); }
    private function decimal(mixed $value): string { return bcadd((string) ($value ?? 0), '0', 4); }
    private function payload(object $row): array { $values = (array) $row; foreach ($values as $key => $value) if (is_string($value) && (str_ends_with($key, '_json') || in_array($key, ['result_json', 'validation_json', 'diagnostic_snapshot'], true))) { try { $values[$key] = json_decode($value, true, flags: JSON_THROW_ON_ERROR); } catch (\Throwable) {} } return $values; }
}

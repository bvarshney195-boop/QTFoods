<?php

namespace App\Modules\Finance\Application;

use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class FinanceOperationsService
{
    public const JOURNAL_STATUSES = ['DRAFT', 'POSTED', 'REVERSED'];
    public const JOURNAL_TYPES = ['MANUAL', 'EXPENSE', 'OVERHEAD', 'ASSET', 'DEPRECIATION', 'DISPOSAL', 'PAYROLL', 'MAINTENANCE', 'ADJUSTMENT', 'LEGACY_IMPORT', 'OPENING_BALANCE'];
    public const EXPENSE_STATUSES = ['DRAFT', 'SUBMITTED', 'APPROVED', 'POSTED', 'CANCELLED'];
    public const EXPENSE_CATEGORIES = ['TRAVEL', 'MEALS', 'SUPPLIES', 'UTILITIES', 'PROFESSIONAL', 'OTHER'];
    public const ALLOCATION_BASES = ['MACHINE_MINUTE', 'LABOUR_MINUTE', 'OUTPUT_UNIT', 'FIXED'];
    public const ASSET_STATUSES = ['DRAFT', 'ACTIVE', 'DISPOSED'];
    public const PAYROLL_STATUSES = ['DRAFT', 'APPROVED', 'POSTED'];
    public const MAINTENANCE_STATUSES = ['DRAFT', 'RELEASED', 'COMPLETED', 'CANCELLED'];
    public const PRIORITIES = ['LOW', 'NORMAL', 'HIGH', 'CRITICAL'];
    public const EXPORT_TYPES = ['AP_BANK', 'GST_INPUT', 'GST_OUTPUT'];

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    public function createJournal(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'finance.journal.create';
            if ($replay = $this->begin($namespace, $data)) return $replay;
            $this->unique('journals', 'journal_number', $data['journal_number'], $data);
            $period = $this->period($data['posting_date'], $data, $data['fiscal_period_id']);
            [$lines, $debit, $credit] = $this->prepareLines($data['lines'], $data, 6);
            $id = $this->insertJournal('MANUAL', $data['journal_number'], $period->id, $data['posting_date'], $data['description'], $data['source_reference'] ?? null, $lines, $debit, $credit, $data, false);
            $result = $this->result('journal', $id, 'DRAFT', 1, ['total_debit' => $debit, 'total_credit' => $credit]);
            $this->record('CREATE_JOURNAL', 'finance.journal.created', 'journal', $id, $data, 1, ['line_count' => count($lines)], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function postJournal(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'finance.journal.post.'.$id;
            if ($replay = $this->begin($namespace, $data + ['journal_id' => $id])) return $replay;
            $journal = $this->find('journals', $id, $data, 'Journal', true);
            $this->version($journal, $data, 'journal');
            $this->status($journal, ['DRAFT'], 'Only a draft journal can be posted.');
            $this->period((string) $journal->posting_date, $data, (string) $journal->fiscal_period_id);
            $version = (int) $journal->record_version + 1;
            DB::table('journals')->where('id', $id)->update(['status' => 'POSTED', 'posted_at' => now(), 'posted_by' => $data['actor_id'], 'record_version' => $version, 'updated_at' => now()]);
            $result = $this->result('journal', $id, 'POSTED', $version, ['total_debit' => $this->decimal($journal->total_debit)]);
            $this->record('POST_JOURNAL', 'finance.journal.posted', 'journal', $id, $data, $version, ['status' => ['from' => 'DRAFT', 'to' => 'POSTED']], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function reverseJournal(string $id, string $reason, string $number, string $date, array $data): array
    {
        return DB::transaction(function () use ($id, $reason, $number, $date, $data): array {
            $namespace = 'finance.journal.reverse.'.$id;
            if ($replay = $this->begin($namespace, $data + ['journal_id' => $id, 'reason' => $reason, 'reversal_number' => $number, 'posting_date' => $date])) return $replay;
            $journal = $this->find('journals', $id, $data, 'Journal', true);
            $this->version($journal, $data, 'journal');
            $this->status($journal, ['POSTED'], 'Only a posted journal can be reversed.');
            if (DB::table('journals')->where('reversal_of_journal_id', $id)->exists()) throw ValidationException::withMessages(['status' => ['This journal already has a reversal.']]);
            $this->unique('journals', 'journal_number', $number, $data);
            $period = $this->period($date, $data);
            $original = DB::table('journal_lines')->where('journal_id', $id)->orderBy('line_number')->get();
            $lines = $original->map(fn (object $line) => ['account_id' => $line->account_id, 'party_id' => $line->party_id, 'description' => 'Reversal: '.$line->description, 'debit_amount' => $this->decimal($line->credit_amount), 'credit_amount' => $this->decimal($line->debit_amount)])->all();
            $reversalId = $this->insertJournal('MANUAL', $number, $period->id, $date, 'Reversal of '.$journal->journal_number.': '.trim($reason), 'REVERSAL:'.$id, $lines, $this->decimal($journal->total_credit), $this->decimal($journal->total_debit), $data, true, $id);
            $version = (int) $journal->record_version + 1;
            DB::table('journals')->where('id', $id)->update(['status' => 'REVERSED', 'reversed_at' => now(), 'reversed_by' => $data['actor_id'], 'reversal_reason' => trim($reason), 'record_version' => $version, 'updated_at' => now()]);
            $result = $this->result('journal', $id, 'REVERSED', $version, ['reversal_journal_id' => $reversalId]);
            $this->record('REVERSE_JOURNAL', 'finance.journal.reversed', 'journal', $id, $data, $version, ['reversal_journal_id' => $reversalId], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function closePeriod(string $id, string $notes, array $data): array
    {
        return DB::transaction(function () use ($id, $notes, $data): array {
            $namespace = 'finance.period.close.'.$id;
            if ($replay = $this->begin($namespace, $data + ['period_id' => $id, 'notes' => $notes])) return $replay;
            $period = DB::table('fiscal_periods')->where('id', $id)->where('company_id', $data['company_id'])->lockForUpdate()->first();
            if (! $period) throw new NotFoundHttpException('Fiscal period not found.');
            $this->version($period, $data, 'fiscal period');
            $this->status($period, ['OPEN'], 'Only an open fiscal period can be closed.');
            if (DB::table('journals')->where('company_id', $data['company_id'])->where('fiscal_period_id', $id)->where('status', 'DRAFT')->exists()) {
                throw ValidationException::withMessages(['period' => ['Post or remove all draft journals before closing the period.']]);
            }
            $version = (int) $period->record_version + 1;
            DB::table('fiscal_periods')->where('id', $id)->update(['status' => 'CLOSED', 'closed_at' => now(), 'closed_by' => $data['actor_id'], 'closure_notes' => trim($notes), 'record_version' => $version, 'updated_at' => now()]);
            $result = $this->result('fiscal_period', $id, 'CLOSED', $version);
            $this->record('CLOSE_FISCAL_PERIOD', 'finance.period.closed', 'fiscal_period', $id, $data, $version, ['status' => ['from' => 'OPEN', 'to' => 'CLOSED']], $result);
            $this->complete($namespace, $data, $result);
            return $result;
        }, 3);
    }

    public function createExpense(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'finance.expense.create';
            if ($replay = $this->begin($namespace, $data)) return $replay;
            $this->unique('expense_claims', 'expense_number', $data['expense_number'], $data);
            $this->assertUser($data['claimant_user_id']);
            [$lines, $net, $tax, $total] = $this->expenseLines($data['lines'], $data);
            $id = (string) Str::uuid(); $now = CarbonImmutable::now();
            DB::table('expense_claims')->insert(['id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'expense_number' => $data['expense_number'], 'claimant_user_id' => $data['claimant_user_id'], 'expense_date' => $data['expense_date'], 'category' => $data['category'], 'currency' => 'INR', 'net_amount' => $net, 'tax_amount' => $tax, 'total_amount' => $total, 'description' => trim($data['description']), 'status' => 'DRAFT', 'record_version' => 1, 'created_by' => $data['actor_id'], 'submitted_at' => null, 'submitted_by' => null, 'approved_at' => null, 'approved_by' => null, 'posted_at' => null, 'posted_by' => null, 'journal_id' => null, 'cancelled_at' => null, 'cancelled_by' => null, 'cancellation_reason' => null, 'created_at' => $now, 'updated_at' => $now]);
            $this->insertExpenseLines($id, $lines, $data, $now);
            $result = $this->result('expense_claim', $id, 'DRAFT', 1, ['total_amount' => $total]);
            $this->record('CREATE_EXPENSE_CLAIM', 'finance.expense.created', 'expense_claim', $id, $data, 1, ['line_count' => count($lines), 'total_amount' => $total], $result);
            $this->complete($namespace, $data, $result); return $result;
        }, 3);
    }

    public function updateExpense(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'finance.expense.update.'.$id;
            if ($replay = $this->begin($namespace, $data + ['expense_id' => $id])) return $replay;
            $claim = $this->find('expense_claims', $id, $data, 'Expense claim', true); $this->version($claim, $data, 'expense claim'); $this->status($claim, ['DRAFT'], 'Only a draft expense claim can be edited.');
            $this->assertUser($data['claimant_user_id']); [$lines, $net, $tax, $total] = $this->expenseLines($data['lines'], $data); $version = (int) $claim->record_version + 1; $now = CarbonImmutable::now();
            DB::table('expense_claims')->where('id', $id)->update(['claimant_user_id' => $data['claimant_user_id'], 'expense_date' => $data['expense_date'], 'category' => $data['category'], 'net_amount' => $net, 'tax_amount' => $tax, 'total_amount' => $total, 'description' => trim($data['description']), 'record_version' => $version, 'updated_at' => $now]);
            DB::table('expense_claim_lines')->where('expense_claim_id', $id)->delete(); $this->insertExpenseLines($id, $lines, $data, $now);
            $result = $this->result('expense_claim', $id, 'DRAFT', $version, ['total_amount' => $total]);
            $this->record('UPDATE_EXPENSE_CLAIM', 'finance.expense.updated', 'expense_claim', $id, $data, $version, ['total_amount' => $total], $result); $this->complete($namespace, $data, $result); return $result;
        }, 3);
    }

    public function transitionExpense(string $id, string $action, ?string $reason, array $data): array
    {
        return DB::transaction(function () use ($id, $action, $reason, $data): array {
            $action = Str::upper($action); $namespace = 'finance.expense.'.Str::lower($action).'.'.$id;
            if ($replay = $this->begin($namespace, $data + ['expense_id' => $id, 'action' => $action, 'reason' => $reason])) return $replay;
            $claim = $this->find('expense_claims', $id, $data, 'Expense claim', true); $this->version($claim, $data, 'expense claim'); $now = CarbonImmutable::now(); $extra = []; $journalId = null;
            if ($action === 'SUBMIT') { $this->status($claim, ['DRAFT'], 'Only a draft claim can be submitted.'); $to = 'SUBMITTED'; $extra = ['submitted_at' => $now, 'submitted_by' => $data['actor_id']]; }
            elseif ($action === 'APPROVE') { $this->status($claim, ['SUBMITTED'], 'Only a submitted claim can be approved.'); if ((string) $claim->created_by === (string) $data['actor_id'] || (string) $claim->claimant_user_id === (string) $data['actor_id']) throw ValidationException::withMessages(['actor' => ['Maker-checker control prevents the claimant or creator from approving this expense.']]); $to = 'APPROVED'; $extra = ['approved_at' => $now, 'approved_by' => $data['actor_id']]; }
            elseif ($action === 'POST') { $this->status($claim, ['APPROVED'], 'Only an approved claim can be posted.'); $lines = DB::table('expense_claim_lines')->where('expense_claim_id', $id)->get()->map(fn (object $line) => ['account_id' => $line->expense_account_id, 'party_id' => null, 'description' => $line->description, 'debit_amount' => $this->decimal($line->gross_amount), 'credit_amount' => '0'])->all(); $lines[] = ['account_id' => $this->controlAccount('AP', $data), 'party_id' => null, 'description' => 'Expense claim payable '.$claim->expense_number, 'debit_amount' => '0', 'credit_amount' => $this->decimal($claim->total_amount)]; $journalId = $this->postSourceJournal('EXPENSE', 'EXP-'.$claim->expense_number, (string) $claim->expense_date, 'Expense claim '.$claim->expense_number, 'EXPENSE:'.$id, $lines, $data); $to = 'POSTED'; $extra = ['posted_at' => $now, 'posted_by' => $data['actor_id'], 'journal_id' => $journalId]; }
            elseif ($action === 'CANCEL') { $this->status($claim, ['DRAFT', 'SUBMITTED', 'APPROVED'], 'A posted or cancelled claim cannot be cancelled.'); if (trim((string) $reason) === '') throw ValidationException::withMessages(['reason' => ['A cancellation reason is required.']]); $to = 'CANCELLED'; $extra = ['cancelled_at' => $now, 'cancelled_by' => $data['actor_id'], 'cancellation_reason' => trim((string) $reason)]; }
            else throw ValidationException::withMessages(['action' => ['Unsupported expense action.']]);
            $version = (int) $claim->record_version + 1; DB::table('expense_claims')->where('id', $id)->update(['status' => $to, 'record_version' => $version, 'updated_at' => $now] + $extra);
            $result = $this->result('expense_claim', $id, $to, $version, $journalId ? ['journal_id' => $journalId] : []); $this->record($action.'_EXPENSE_CLAIM', 'finance.expense.'.Str::lower($action).'d', 'expense_claim', $id, $data, $version, ['status' => ['from' => $claim->status, 'to' => $to]], $result); $this->complete($namespace, $data, $result); return $result;
        }, 3);
    }

    public function createOverheadPool(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'finance.overhead-pool.create'; if ($replay = $this->begin($namespace, $data)) return $replay; $this->unique('overhead_pools', 'pool_code', $data['pool_code'], $data); $this->account($data['expense_account_id'], $data);
            $id = (string) Str::uuid(); $now = CarbonImmutable::now(); DB::table('overhead_pools')->insert(['id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'pool_code' => $data['pool_code'], 'name' => trim($data['name']), 'expense_account_id' => $data['expense_account_id'], 'allocation_basis' => $data['allocation_basis'], 'rate' => $this->positive($data['rate'], 'rate'), 'status' => 'ACTIVE', 'record_version' => 1, 'created_by' => $data['actor_id'], 'created_at' => $now, 'updated_at' => $now]);
            $result = $this->result('overhead_pool', $id, 'ACTIVE', 1); $this->record('CREATE_OVERHEAD_POOL', 'finance.overhead-pool.created', 'overhead_pool', $id, $data, 1, ['pool_code' => $data['pool_code']], $result); $this->complete($namespace, $data, $result); return $result;
        }, 3);
    }

    public function updateOverheadPool(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'finance.overhead-pool.update.'.$id; if ($replay = $this->begin($namespace, $data + ['pool_id' => $id])) return $replay; $pool = $this->find('overhead_pools', $id, $data, 'Overhead pool', true); $this->version($pool, $data, 'overhead pool'); $this->account($data['expense_account_id'], $data); $version = (int) $pool->record_version + 1;
            DB::table('overhead_pools')->where('id', $id)->update(['name' => trim($data['name']), 'expense_account_id' => $data['expense_account_id'], 'allocation_basis' => $data['allocation_basis'], 'rate' => $this->positive($data['rate'], 'rate'), 'status' => $data['status'], 'record_version' => $version, 'updated_at' => now()]);
            $result = $this->result('overhead_pool', $id, $data['status'], $version); $this->record('UPDATE_OVERHEAD_POOL', 'finance.overhead-pool.updated', 'overhead_pool', $id, $data, $version, ['rate' => $data['rate']], $result); $this->complete($namespace, $data, $result); return $result;
        }, 3);
    }

    public function allocateOverhead(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'finance.overhead-pool.allocate.'.$id; if ($replay = $this->begin($namespace, $data + ['pool_id' => $id])) return $replay; $pool = $this->find('overhead_pools', $id, $data, 'Overhead pool', true); $this->status($pool, ['ACTIVE'], 'Only an active overhead pool can be allocated.'); $period = $this->period($data['posting_date'], $data, $data['fiscal_period_id']); $target = $this->account($data['target_account_id'], $data);
            if (! empty($data['production_order_id']) && ! DB::table('production_orders')->where('id', $data['production_order_id'])->where($this->scope($data))->exists()) throw ValidationException::withMessages(['production_order_id' => ['Production order not found in the selected plant.']]);
            $basis = $this->positive($data['basis_quantity'], 'basis_quantity'); $amount = bcadd(bcmul($basis, $this->decimal($pool->rate), 12), '0.0000005', 6); $allocationId = (string) Str::uuid();
            $journalId = $this->postSourceJournal('OVERHEAD', 'OH-'.$data['allocation_number'], $data['posting_date'], 'Overhead allocation '.$data['allocation_number'], 'OVERHEAD:'.$allocationId, [['account_id' => $target->id, 'party_id' => null, 'description' => 'Allocated overhead', 'debit_amount' => $amount, 'credit_amount' => '0'], ['account_id' => $pool->expense_account_id, 'party_id' => null, 'description' => 'Overhead absorption', 'debit_amount' => '0', 'credit_amount' => $amount]], $data);
            DB::table('overhead_allocations')->insert(['id' => $allocationId, 'overhead_pool_id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'allocation_number' => $data['allocation_number'], 'fiscal_period_id' => $period->id, 'production_order_id' => $data['production_order_id'] ?? null, 'basis_quantity' => $basis, 'rate_snapshot' => $this->decimal($pool->rate), 'allocated_amount' => $amount, 'journal_id' => $journalId, 'status' => 'POSTED', 'created_by' => $data['actor_id'], 'created_at' => now(), 'updated_at' => now()]);
            $result = $this->result('overhead_allocation', $allocationId, 'POSTED', 1, ['allocated_amount' => $amount, 'journal_id' => $journalId]); $this->record('ALLOCATE_OVERHEAD', 'finance.overhead.allocated', 'overhead_allocation', $allocationId, $data, 1, ['amount' => $amount], $result); $this->complete($namespace, $data, $result); return $result;
        }, 3);
    }

    public function createAsset(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'finance.asset.create'; if ($replay = $this->begin($namespace, $data)) return $replay; $this->unique('assets', 'asset_number', $data['asset_number'], $data); foreach (['asset_account_id', 'depreciation_account_id', 'depreciation_expense_account_id'] as $field) $this->account($data[$field], $data); $cost = $this->positive($data['acquisition_cost'], 'acquisition_cost'); $residual = $this->amount($data['residual_value']); if (bccomp($residual, $cost, 6) > 0) throw ValidationException::withMessages(['residual_value' => ['Residual value cannot exceed acquisition cost.']]);
            $id = (string) Str::uuid(); $now = CarbonImmutable::now(); DB::table('assets')->insert(['id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'asset_type' => 'FIXED_ASSET', 'asset_number' => $data['asset_number'], 'name' => trim($data['name']), 'category' => $data['category'], 'acquisition_date' => $data['acquisition_date'], 'acquisition_cost' => $cost, 'residual_value' => $residual, 'useful_life_months' => $data['useful_life_months'], 'accumulated_depreciation' => 0, 'net_book_value' => $cost, 'asset_account_id' => $data['asset_account_id'], 'depreciation_account_id' => $data['depreciation_account_id'], 'depreciation_expense_account_id' => $data['depreciation_expense_account_id'], 'activated_at' => null, 'activated_by' => null, 'disposed_at' => null, 'disposed_by' => null, 'disposal_proceeds' => null, 'disposal_reason' => null, 'status' => 'DRAFT', 'record_version' => 1, 'created_by' => $data['actor_id'], 'created_at' => $now, 'updated_at' => $now]);
            $result = $this->result('asset', $id, 'DRAFT', 1, ['net_book_value' => $cost]); $this->record('CREATE_ASSET', 'finance.asset.created', 'asset', $id, $data, 1, ['asset_number' => $data['asset_number']], $result); $this->complete($namespace, $data, $result); return $result;
        }, 3);
    }

    public function updateAsset(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'finance.asset.update.'.$id; if ($replay = $this->begin($namespace, $data + ['asset_id' => $id])) return $replay; $asset = $this->find('assets', $id, $data, 'Asset', true); $this->version($asset, $data, 'asset'); $this->status($asset, ['DRAFT'], 'Only a draft asset can be edited.'); foreach (['asset_account_id', 'depreciation_account_id', 'depreciation_expense_account_id'] as $field) $this->account($data[$field], $data); $cost = $this->positive($data['acquisition_cost'], 'acquisition_cost'); $residual = $this->amount($data['residual_value']); if (bccomp($residual, $cost, 6) > 0) throw ValidationException::withMessages(['residual_value' => ['Residual value cannot exceed acquisition cost.']]); $version = (int) $asset->record_version + 1;
            DB::table('assets')->where('id', $id)->update(['name' => trim($data['name']), 'category' => $data['category'], 'acquisition_date' => $data['acquisition_date'], 'acquisition_cost' => $cost, 'residual_value' => $residual, 'useful_life_months' => $data['useful_life_months'], 'net_book_value' => $cost, 'asset_account_id' => $data['asset_account_id'], 'depreciation_account_id' => $data['depreciation_account_id'], 'depreciation_expense_account_id' => $data['depreciation_expense_account_id'], 'record_version' => $version, 'updated_at' => now()]);
            $result = $this->result('asset', $id, 'DRAFT', $version, ['net_book_value' => $cost]); $this->record('UPDATE_ASSET', 'finance.asset.updated', 'asset', $id, $data, $version, ['acquisition_cost' => $cost], $result); $this->complete($namespace, $data, $result); return $result;
        }, 3);
    }

    public function activateAsset(string $id, string $postingDate, array $data): array
    {
        return DB::transaction(function () use ($id, $postingDate, $data): array {
            $namespace = 'finance.asset.activate.'.$id; if ($replay = $this->begin($namespace, $data + ['asset_id' => $id, 'posting_date' => $postingDate])) return $replay; $asset = $this->find('assets', $id, $data, 'Asset', true); $this->version($asset, $data, 'asset'); $this->status($asset, ['DRAFT'], 'Only a draft asset can be activated.');
            $journalId = $this->postSourceJournal('ASSET', 'AST-'.$asset->asset_number, $postingDate, 'Asset activation '.$asset->asset_number, 'ASSET:'.$id, [['account_id' => $asset->asset_account_id, 'party_id' => null, 'description' => $asset->name, 'debit_amount' => $this->decimal($asset->acquisition_cost), 'credit_amount' => '0'], ['account_id' => $this->controlAccount('CASH', $data), 'party_id' => null, 'description' => 'Asset acquisition funding', 'debit_amount' => '0', 'credit_amount' => $this->decimal($asset->acquisition_cost)]], $data); $version = (int) $asset->record_version + 1;
            DB::table('assets')->where('id', $id)->update(['status' => 'ACTIVE', 'activated_at' => now(), 'activated_by' => $data['actor_id'], 'record_version' => $version, 'updated_at' => now()]); $result = $this->result('asset', $id, 'ACTIVE', $version, ['journal_id' => $journalId]); $this->record('ACTIVATE_ASSET', 'finance.asset.activated', 'asset', $id, $data, $version, ['journal_id' => $journalId], $result); $this->complete($namespace, $data, $result); return $result;
        }, 3);
    }

    public function depreciateAsset(string $id, string $periodId, array $data): array
    {
        return DB::transaction(function () use ($id, $periodId, $data): array {
            $namespace = 'finance.asset.depreciate.'.$id.'.'.$periodId; if ($replay = $this->begin($namespace, $data + ['asset_id' => $id, 'period_id' => $periodId])) return $replay; $asset = $this->find('assets', $id, $data, 'Asset', true); $this->version($asset, $data, 'asset'); $this->status($asset, ['ACTIVE'], 'Only an active asset can be depreciated.'); $period = DB::table('fiscal_periods')->where('id', $periodId)->where('company_id', $data['company_id'])->where('status', 'OPEN')->first(); if (! $period) throw ValidationException::withMessages(['fiscal_period_id' => ['Open fiscal period not found.']]); if (DB::table('asset_depreciation_entries')->where('asset_id', $id)->where('fiscal_period_id', $periodId)->exists()) throw ValidationException::withMessages(['fiscal_period_id' => ['Depreciation is already posted for this period.']]);
            $depreciable = bcsub($this->decimal($asset->acquisition_cost), $this->decimal($asset->residual_value), 6); $monthly = bcdiv($depreciable, (string) $asset->useful_life_months, 6); $available = bcsub($this->decimal($asset->net_book_value), $this->decimal($asset->residual_value), 6); $amount = bccomp($monthly, $available, 6) > 0 ? $available : $monthly; if (bccomp($amount, '0', 6) <= 0) throw ValidationException::withMessages(['asset' => ['The asset is already fully depreciated.']]); $closing = bcsub($this->decimal($asset->net_book_value), $amount, 6);
            $journalId = $this->postSourceJournal('DEPRECIATION', 'DEP-'.$asset->asset_number.'-'.$period->period_code, (string) $period->ends_on, 'Depreciation '.$asset->asset_number.' '.$period->period_code, 'DEPRECIATION:'.$id.':'.$periodId, [['account_id' => $asset->depreciation_expense_account_id, 'party_id' => null, 'description' => 'Depreciation expense', 'debit_amount' => $amount, 'credit_amount' => '0'], ['account_id' => $asset->depreciation_account_id, 'party_id' => null, 'description' => 'Accumulated depreciation', 'debit_amount' => '0', 'credit_amount' => $amount]], $data);
            DB::table('asset_depreciation_entries')->insert(['id' => (string) Str::uuid(), 'asset_id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'fiscal_period_id' => $periodId, 'opening_book_value' => $this->decimal($asset->net_book_value), 'depreciation_amount' => $amount, 'closing_book_value' => $closing, 'journal_id' => $journalId, 'created_by' => $data['actor_id'], 'created_at' => now()]); $version = (int) $asset->record_version + 1; DB::table('assets')->where('id', $id)->update(['accumulated_depreciation' => bcadd($this->decimal($asset->accumulated_depreciation), $amount, 6), 'net_book_value' => $closing, 'record_version' => $version, 'updated_at' => now()]);
            $result = $this->result('asset', $id, 'ACTIVE', $version, ['depreciation_amount' => $amount, 'net_book_value' => $closing, 'journal_id' => $journalId]); $this->record('DEPRECIATE_ASSET', 'finance.asset.depreciated', 'asset', $id, $data, $version, ['amount' => $amount], $result); $this->complete($namespace, $data, $result); return $result;
        }, 3);
    }

    public function disposeAsset(string $id, string $date, mixed $proceedsValue, string $reason, array $data): array
    {
        return DB::transaction(function () use ($id, $date, $proceedsValue, $reason, $data): array {
            $namespace = 'finance.asset.dispose.'.$id; if ($replay = $this->begin($namespace, $data + ['asset_id' => $id, 'posting_date' => $date, 'proceeds' => $proceedsValue, 'reason' => $reason])) return $replay; $asset = $this->find('assets', $id, $data, 'Asset', true); $this->version($asset, $data, 'asset'); $this->status($asset, ['ACTIVE'], 'Only an active asset can be disposed.'); $proceeds = $this->amount($proceedsValue); $cost = $this->decimal($asset->acquisition_cost); $accumulated = $this->decimal($asset->accumulated_depreciation); $lines = [];
            if (bccomp($proceeds, '0', 6) > 0) $lines[] = ['account_id' => $this->controlAccount('CASH', $data), 'party_id' => null, 'description' => 'Disposal proceeds', 'debit_amount' => $proceeds, 'credit_amount' => '0']; if (bccomp($accumulated, '0', 6) > 0) $lines[] = ['account_id' => $asset->depreciation_account_id, 'party_id' => null, 'description' => 'Clear accumulated depreciation', 'debit_amount' => $accumulated, 'credit_amount' => '0']; $difference = bcsub($cost, bcadd($proceeds, $accumulated, 6), 6); if (bccomp($difference, '0', 6) > 0) $lines[] = ['account_id' => $asset->depreciation_expense_account_id, 'party_id' => null, 'description' => 'Loss on disposal', 'debit_amount' => $difference, 'credit_amount' => '0']; elseif (bccomp($difference, '0', 6) < 0) $lines[] = ['account_id' => $this->accountByType('REVENUE', $data), 'party_id' => null, 'description' => 'Gain on disposal', 'debit_amount' => '0', 'credit_amount' => ltrim($difference, '-')]; $lines[] = ['account_id' => $asset->asset_account_id, 'party_id' => null, 'description' => 'Derecognise asset', 'debit_amount' => '0', 'credit_amount' => $cost];
            $carryingBefore = $this->decimal($asset->net_book_value); $journalId = $this->postSourceJournal('DISPOSAL', 'DSP-'.$asset->asset_number, $date, 'Asset disposal '.$asset->asset_number, 'ASSET-DISPOSAL:'.$id, $lines, $data); $version = (int) $asset->record_version + 1; DB::table('assets')->where('id', $id)->update(['status' => 'DISPOSED', 'net_book_value' => 0, 'disposed_at' => now(), 'disposed_by' => $data['actor_id'], 'disposal_proceeds' => $proceeds, 'disposal_reason' => trim($reason), 'record_version' => $version, 'updated_at' => now()]);
            $result = $this->result('asset', $id, 'DISPOSED', $version, ['journal_id' => $journalId, 'disposal_proceeds' => $proceeds, 'carrying_amount_before_disposal' => $carryingBefore, 'current_carrying_amount' => '0.000000']); $this->record('DISPOSE_ASSET', 'finance.asset.disposed', 'asset', $id, $data, $version, ['journal_id' => $journalId, 'current_carrying_amount' => ['from' => $carryingBefore, 'to' => '0.000000']], $result); $this->complete($namespace, $data, $result); return $result;
        }, 3);
    }

    public function createEmployee(array $data): array
    {
        return DB::transaction(function () use ($data): array { $namespace = 'finance.employee.create'; if ($replay = $this->begin($namespace, $data)) return $replay; $this->unique('employees', 'employee_number', $data['employee_number'], ['company_id' => $data['company_id']]); foreach (['expense_account_id', 'payable_account_id'] as $field) $this->account($data[$field], $data); $gross = $this->positive($data['monthly_gross'], 'monthly_gross'); $deductions = $this->amount($data['monthly_deductions']); if (bccomp($deductions, $gross, 6) >= 0) throw ValidationException::withMessages(['monthly_deductions' => ['Deductions must be less than gross pay.']]); $id = (string) Str::uuid(); DB::table('employees')->insert(['id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'employee_number' => $data['employee_number'], 'name' => trim($data['name']), 'department' => trim($data['department']), 'monthly_gross' => $gross, 'monthly_deductions' => $deductions, 'expense_account_id' => $data['expense_account_id'], 'payable_account_id' => $data['payable_account_id'], 'status' => 'ACTIVE', 'record_version' => 1, 'created_by' => $data['actor_id'], 'created_at' => now(), 'updated_at' => now()]); $result = $this->result('employee', $id, 'ACTIVE', 1); $this->record('CREATE_EMPLOYEE', 'finance.employee.created', 'employee', $id, $data, 1, ['employee_number' => $data['employee_number']], $result); $this->complete($namespace, $data, $result); return $result; }, 3);
    }

    public function updateEmployee(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array { $namespace = 'finance.employee.update.'.$id; if ($replay = $this->begin($namespace, $data + ['employee_id' => $id])) return $replay; $employee = $this->find('employees', $id, $data, 'Employee', true); $this->version($employee, $data, 'employee'); foreach (['expense_account_id', 'payable_account_id'] as $field) $this->account($data[$field], $data); $gross = $this->positive($data['monthly_gross'], 'monthly_gross'); $deductions = $this->amount($data['monthly_deductions']); if (bccomp($deductions, $gross, 6) >= 0) throw ValidationException::withMessages(['monthly_deductions' => ['Deductions must be less than gross pay.']]); $version = (int) $employee->record_version + 1; DB::table('employees')->where('id', $id)->update(['name' => trim($data['name']), 'department' => trim($data['department']), 'monthly_gross' => $gross, 'monthly_deductions' => $deductions, 'expense_account_id' => $data['expense_account_id'], 'payable_account_id' => $data['payable_account_id'], 'status' => $data['status'], 'record_version' => $version, 'updated_at' => now()]); $result = $this->result('employee', $id, $data['status'], $version); $this->record('UPDATE_EMPLOYEE', 'finance.employee.updated', 'employee', $id, $data, $version, ['status' => $data['status']], $result); $this->complete($namespace, $data, $result); return $result; }, 3);
    }

    public function createPayroll(array $data): array
    {
        return DB::transaction(function () use ($data): array { $namespace = 'finance.payroll.create'; if ($replay = $this->begin($namespace, $data)) return $replay; $this->unique('payroll_runs', 'run_number', $data['run_number'], $data); if ($data['period_end'] < $data['period_start']) throw ValidationException::withMessages(['period_end' => ['Payroll period end cannot precede its start.']]); $query = DB::table('employees')->where($this->scope($data))->where('status', 'ACTIVE'); if (! empty($data['employee_ids'])) $query->whereIn('id', $data['employee_ids']); $employees = $query->orderBy('employee_number')->get(); if ($employees->isEmpty()) throw ValidationException::withMessages(['employee_ids' => ['No active employees were selected.']]); $gross = '0.000000'; $deductions = '0.000000'; foreach ($employees as $employee) { $gross = bcadd($gross, $this->decimal($employee->monthly_gross), 6); $deductions = bcadd($deductions, $this->decimal($employee->monthly_deductions), 6); } $net = bcsub($gross, $deductions, 6); $id = (string) Str::uuid(); $now = CarbonImmutable::now(); DB::table('payroll_runs')->insert(['id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'run_type' => 'PAYROLL', 'run_number' => $data['run_number'], 'period_start' => $data['period_start'], 'period_end' => $data['period_end'], 'currency' => 'INR', 'gross_amount' => $gross, 'deduction_amount' => $deductions, 'net_amount' => $net, 'journal_id' => null, 'approved_at' => null, 'approved_by' => null, 'posted_at' => null, 'posted_by' => null, 'status' => 'DRAFT', 'record_version' => 1, 'created_by' => $data['actor_id'], 'created_at' => $now, 'updated_at' => $now]); foreach ($employees as $index => $employee) DB::table('payroll_lines')->insert(['id' => (string) Str::uuid(), 'payroll_run_id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'line_number' => $index + 1, 'employee_id' => $employee->id, 'gross_amount' => $this->decimal($employee->monthly_gross), 'deduction_amount' => $this->decimal($employee->monthly_deductions), 'net_amount' => bcsub($this->decimal($employee->monthly_gross), $this->decimal($employee->monthly_deductions), 6), 'created_at' => $now, 'updated_at' => $now]); $result = $this->result('payroll_run', $id, 'DRAFT', 1, ['gross_amount' => $gross, 'net_amount' => $net, 'employee_count' => $employees->count()]); $this->record('CREATE_PAYROLL_RUN', 'finance.payroll.created', 'payroll_run', $id, $data, 1, ['employee_count' => $employees->count()], $result); $this->complete($namespace, $data, $result); return $result; }, 3);
    }

    public function transitionPayroll(string $id, string $action, array $data): array
    {
        return DB::transaction(function () use ($id, $action, $data): array { $action = Str::upper($action); $namespace = 'finance.payroll.'.Str::lower($action).'.'.$id; if ($replay = $this->begin($namespace, $data + ['payroll_id' => $id, 'action' => $action])) return $replay; $run = $this->find('payroll_runs', $id, $data, 'Payroll run', true); $this->version($run, $data, 'payroll run'); $extra = []; $journalId = null; if ($action === 'APPROVE') { $this->status($run, ['DRAFT'], 'Only a draft payroll run can be approved.'); if ((string) $run->created_by === (string) $data['actor_id']) throw ValidationException::withMessages(['actor' => ['Maker-checker control prevents the payroll creator from approving it.']]); $to = 'APPROVED'; $extra = ['approved_at' => now(), 'approved_by' => $data['actor_id']]; } elseif ($action === 'POST') { $this->status($run, ['APPROVED'], 'Only an approved payroll run can be posted.'); $expenseLines = DB::table('payroll_lines as line')->join('employees as employee', 'employee.id', '=', 'line.employee_id')->where('line.payroll_run_id', $id)->select('employee.expense_account_id', DB::raw('SUM(line.gross_amount) as amount'))->groupBy('employee.expense_account_id')->get()->map(fn (object $row) => ['account_id' => $row->expense_account_id, 'party_id' => null, 'description' => 'Payroll expense '.$run->run_number, 'debit_amount' => $this->decimal($row->amount), 'credit_amount' => '0'])->all(); $expenseLines[] = ['account_id' => $this->controlAccount('PAYROLL', $data), 'party_id' => null, 'description' => 'Payroll payable '.$run->run_number, 'debit_amount' => '0', 'credit_amount' => $this->decimal($run->gross_amount)]; $journalId = $this->postSourceJournal('PAYROLL', 'PAY-'.$run->run_number, (string) $run->period_end, 'Payroll '.$run->run_number, 'PAYROLL:'.$id, $expenseLines, $data); $to = 'POSTED'; $extra = ['posted_at' => now(), 'posted_by' => $data['actor_id'], 'journal_id' => $journalId]; } else throw ValidationException::withMessages(['action' => ['Unsupported payroll action.']]); $version = (int) $run->record_version + 1; DB::table('payroll_runs')->where('id', $id)->update(['status' => $to, 'record_version' => $version, 'updated_at' => now()] + $extra); $result = $this->result('payroll_run', $id, $to, $version, $journalId ? ['journal_id' => $journalId] : []); $this->record($action.'_PAYROLL_RUN', 'finance.payroll.'.Str::lower($action).'d', 'payroll_run', $id, $data, $version, ['status' => ['from' => $run->status, 'to' => $to]], $result); $this->complete($namespace, $data, $result); return $result; }, 3);
    }

    public function createMaintenance(array $data): array
    {
        return DB::transaction(function () use ($data): array { $namespace = 'finance.maintenance.create'; if ($replay = $this->begin($namespace, $data)) return $replay; $this->unique('maintenance_work_orders', 'work_order_number', $data['work_order_number'], $data); if (! empty($data['asset_id'])) $this->find('assets', $data['asset_id'], $data, 'Asset'); $this->account($data['expense_account_id'], $data); [$labour, $material, $external, $total] = $this->costs($data); $id = (string) Str::uuid(); DB::table('maintenance_work_orders')->insert(['id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'work_type' => 'MAINTENANCE', 'work_order_number' => $data['work_order_number'], 'asset_id' => $data['asset_id'] ?? null, 'priority' => $data['priority'], 'description' => trim($data['description']), 'planned_date' => $data['planned_date'], 'labour_cost' => $labour, 'material_cost' => $material, 'external_cost' => $external, 'total_cost' => $total, 'expense_account_id' => $data['expense_account_id'], 'journal_id' => null, 'released_at' => null, 'released_by' => null, 'completed_at' => null, 'completed_by' => null, 'status' => 'DRAFT', 'record_version' => 1, 'created_by' => $data['actor_id'], 'created_at' => now(), 'updated_at' => now()]); $result = $this->result('maintenance_work_order', $id, 'DRAFT', 1, ['total_cost' => $total]); $this->record('CREATE_MAINTENANCE_WORK', 'finance.maintenance.created', 'maintenance_work_order', $id, $data, 1, ['total_cost' => $total], $result); $this->complete($namespace, $data, $result); return $result; }, 3);
    }

    public function updateMaintenance(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array { $namespace = 'finance.maintenance.update.'.$id; if ($replay = $this->begin($namespace, $data + ['work_id' => $id])) return $replay; $work = $this->find('maintenance_work_orders', $id, $data, 'Maintenance work order', true); $this->version($work, $data, 'maintenance work order'); $this->status($work, ['DRAFT'], 'Only draft maintenance work can be edited.'); if (! empty($data['asset_id'])) $this->find('assets', $data['asset_id'], $data, 'Asset'); $this->account($data['expense_account_id'], $data); [$labour, $material, $external, $total] = $this->costs($data); $version = (int) $work->record_version + 1; DB::table('maintenance_work_orders')->where('id', $id)->update(['asset_id' => $data['asset_id'] ?? null, 'priority' => $data['priority'], 'description' => trim($data['description']), 'planned_date' => $data['planned_date'], 'labour_cost' => $labour, 'material_cost' => $material, 'external_cost' => $external, 'total_cost' => $total, 'expense_account_id' => $data['expense_account_id'], 'record_version' => $version, 'updated_at' => now()]); $result = $this->result('maintenance_work_order', $id, 'DRAFT', $version, ['total_cost' => $total]); $this->record('UPDATE_MAINTENANCE_WORK', 'finance.maintenance.updated', 'maintenance_work_order', $id, $data, $version, ['total_cost' => $total], $result); $this->complete($namespace, $data, $result); return $result; }, 3);
    }

    public function transitionMaintenance(string $id, string $action, ?array $costData, array $data): array
    {
        return DB::transaction(function () use ($id, $action, $costData, $data): array { $action = Str::upper($action); $namespace = 'finance.maintenance.'.Str::lower($action).'.'.$id; if ($replay = $this->begin($namespace, $data + ['work_id' => $id, 'action' => $action, 'costs' => $costData])) return $replay; $work = $this->find('maintenance_work_orders', $id, $data, 'Maintenance work order', true); $this->version($work, $data, 'maintenance work order'); $extra = []; $journalId = null; if ($action === 'RELEASE') { $this->status($work, ['DRAFT'], 'Only draft maintenance work can be released.'); $to = 'RELEASED'; $extra = ['released_at' => now(), 'released_by' => $data['actor_id']]; } elseif ($action === 'COMPLETE') { $this->status($work, ['RELEASED'], 'Only released maintenance work can be completed.'); [$labour, $material, $external, $total] = $this->costs($costData ?? []); if (bccomp($total, '0', 6) > 0) $journalId = $this->postSourceJournal('MAINTENANCE', 'MNT-'.$work->work_order_number, (string) ($costData['posting_date'] ?? now()->toDateString()), 'Maintenance '.$work->work_order_number, 'MAINTENANCE:'.$id, [['account_id' => $work->expense_account_id, 'party_id' => null, 'description' => $work->description, 'debit_amount' => $total, 'credit_amount' => '0'], ['account_id' => $this->controlAccount('CASH', $data), 'party_id' => null, 'description' => 'Maintenance settlement', 'debit_amount' => '0', 'credit_amount' => $total]], $data); $to = 'COMPLETED'; $extra = ['labour_cost' => $labour, 'material_cost' => $material, 'external_cost' => $external, 'total_cost' => $total, 'journal_id' => $journalId, 'completed_at' => now(), 'completed_by' => $data['actor_id']]; } elseif ($action === 'CANCEL') { $this->status($work, ['DRAFT', 'RELEASED'], 'Completed or cancelled maintenance work cannot be cancelled.'); $to = 'CANCELLED'; } else throw ValidationException::withMessages(['action' => ['Unsupported maintenance action.']]); $version = (int) $work->record_version + 1; DB::table('maintenance_work_orders')->where('id', $id)->update(['status' => $to, 'record_version' => $version, 'updated_at' => now()] + $extra); $result = $this->result('maintenance_work_order', $id, $to, $version, $journalId ? ['journal_id' => $journalId] : []); $this->record($action.'_MAINTENANCE_WORK', 'finance.maintenance.'.Str::lower($action).'d', 'maintenance_work_order', $id, $data, $version, ['status' => ['from' => $work->status, 'to' => $to]], $result); $this->complete($namespace, $data, $result); return $result; }, 3);
    }

    public function upsertBankAccount(?string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array { $creating = $id === null; $namespace = 'finance.bank-account.'.($creating ? 'create' : 'update.'.$id); if ($replay = $this->begin($namespace, $data + ['bank_account_id' => $id])) return $replay; if ($creating) { if (DB::table('bank_accounts')->where('company_id', $data['company_id'])->where('account_code', $data['account_code'])->exists()) throw ValidationException::withMessages(['account_code' => ['That bank account code already exists.']]); $id = (string) Str::uuid(); $version = 1; DB::table('bank_accounts')->insert(['id' => $id, 'company_id' => $data['company_id'], 'account_code' => $data['account_code'], 'bank_name' => trim($data['bank_name']), 'account_name' => trim($data['account_name']), 'masked_account_number' => $data['masked_account_number'], 'ifsc_code' => $data['ifsc_code'], 'currency' => 'INR', 'status' => 'ACTIVE', 'record_version' => 1, 'created_by' => $data['actor_id'], 'created_at' => now(), 'updated_at' => now()]); } else { $account = DB::table('bank_accounts')->where('id', $id)->where('company_id', $data['company_id'])->lockForUpdate()->first(); if (! $account) throw new NotFoundHttpException('Bank account not found.'); $this->version($account, $data, 'bank account'); $version = (int) $account->record_version + 1; DB::table('bank_accounts')->where('id', $id)->update(['bank_name' => trim($data['bank_name']), 'account_name' => trim($data['account_name']), 'masked_account_number' => $data['masked_account_number'], 'ifsc_code' => $data['ifsc_code'], 'status' => $data['status'], 'record_version' => $version, 'updated_at' => now()]); } $status = $creating ? 'ACTIVE' : $data['status']; $result = $this->result('bank_account', $id, $status, $version); $this->record(($creating ? 'CREATE' : 'UPDATE').'_BANK_ACCOUNT', 'finance.bank-account.'.($creating ? 'created' : 'updated'), 'bank_account', $id, $data, $version, ['account_code' => $data['account_code']], $result); $this->complete($namespace, $data, $result); return $result; }, 3);
    }

    public function createExport(array $data): array
    {
        return DB::transaction(function () use ($data): array { $namespace = 'finance.export.create'; if ($replay = $this->begin($namespace, $data)) return $replay; $this->unique('finance_exports', 'export_number', $data['export_number'], $data); if ($data['period_end'] < $data['period_start']) throw ValidationException::withMessages(['period_end' => ['Export period end cannot precede its start.']]); $bank = null; if ($data['export_type'] === 'AP_BANK') { $bank = DB::table('bank_accounts')->where('id', $data['bank_account_id'])->where('company_id', $data['company_id'])->where('status', 'ACTIVE')->first(); if (! $bank) throw ValidationException::withMessages(['bank_account_id' => ['Active bank account not found.']]); $rows = DB::table('supplier_payments as payment')->where('payment.company_id', $data['company_id'])->where('payment.plant_id', $data['plant_id'])->whereBetween('payment.payment_date', [$data['period_start'], $data['period_end']])->whereNotExists(fn ($query) => $query->selectRaw('1')->from('finance_export_lines as exported')->whereColumn('exported.supplier_payment_id', 'payment.id'))->orderBy('payment.payment_number')->get(['payment.id', 'payment.payment_number as reference_number', 'payment.total_amount as amount']); } else { $invoiceType = $data['export_type'] === 'GST_INPUT' ? 'PAYABLE' : 'LEGACY'; $rows = DB::table('invoices as invoice')->where('invoice.company_id', $data['company_id'])->where('invoice.plant_id', $data['plant_id'])->where('invoice.invoice_type', $invoiceType)->whereBetween('invoice.invoice_date', [$data['period_start'], $data['period_end']])->whereNotExists(fn ($query) => $query->selectRaw('1')->from('finance_export_lines as exported')->whereColumn('exported.invoice_id', 'invoice.id'))->orderBy('invoice.id')->get(['invoice.id', DB::raw("COALESCE(invoice.ap_number, invoice.id) as reference_number"), 'invoice.tax_amount as amount']); } if ($rows->isEmpty()) throw ValidationException::withMessages(['period' => ['No eligible, unexported records exist in this period.']]); $payloadLines = $rows->map(fn (object $row) => ['reference' => $row->reference_number, 'amount' => $this->decimal($row->amount)])->all(); $total = $rows->reduce(fn (string $sum, object $row) => bcadd($sum, $this->decimal($row->amount), 6), '0.000000'); $payload = ['version' => 1, 'type' => $data['export_type'], 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'period' => ['from' => $data['period_start'], 'to' => $data['period_end']], 'bank' => $bank ? ['code' => $bank->account_code, 'ifsc' => $bank->ifsc_code, 'account' => $bank->masked_account_number] : null, 'records' => $payloadLines]; $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES); $id = (string) Str::uuid(); $now = CarbonImmutable::now(); DB::table('finance_exports')->insert(['id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'export_number' => $data['export_number'], 'export_type' => $data['export_type'], 'bank_account_id' => $bank?->id, 'period_start' => $data['period_start'], 'period_end' => $data['period_end'], 'record_count' => $rows->count(), 'total_amount' => $total, 'payload_json' => $json, 'sha256' => hash('sha256', $json), 'status' => 'GENERATED', 'record_version' => 1, 'created_by' => $data['actor_id'], 'acknowledged_at' => null, 'acknowledged_by' => null, 'acknowledgement_reference' => null, 'created_at' => $now, 'updated_at' => $now]); foreach ($rows as $index => $row) DB::table('finance_export_lines')->insert(['id' => (string) Str::uuid(), 'finance_export_id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'line_number' => $index + 1, 'supplier_payment_id' => $data['export_type'] === 'AP_BANK' ? $row->id : null, 'invoice_id' => $data['export_type'] !== 'AP_BANK' ? $row->id : null, 'reference_number' => $row->reference_number, 'amount' => $this->decimal($row->amount), 'created_at' => $now, 'updated_at' => $now]); $result = $this->result('finance_export', $id, 'GENERATED', 1, ['record_count' => $rows->count(), 'total_amount' => $total, 'sha256' => hash('sha256', $json)]); $this->record('CREATE_FINANCE_EXPORT', 'finance.export.generated', 'finance_export', $id, $data, 1, ['type' => $data['export_type'], 'record_count' => $rows->count()], $result); $this->complete($namespace, $data, $result); return $result; }, 3);
    }

    public function acknowledgeExport(string $id, string $reference, array $data): array
    {
        return DB::transaction(function () use ($id, $reference, $data): array { $namespace = 'finance.export.acknowledge.'.$id; if ($replay = $this->begin($namespace, $data + ['export_id' => $id, 'reference' => $reference])) return $replay; $export = $this->find('finance_exports', $id, $data, 'Finance export', true); $this->version($export, $data, 'finance export'); $this->status($export, ['GENERATED'], 'Only a generated export can be acknowledged.'); $version = (int) $export->record_version + 1; DB::table('finance_exports')->where('id', $id)->update(['status' => 'ACKNOWLEDGED', 'acknowledged_at' => now(), 'acknowledged_by' => $data['actor_id'], 'acknowledgement_reference' => trim($reference), 'record_version' => $version, 'updated_at' => now()]); $result = $this->result('finance_export', $id, 'ACKNOWLEDGED', $version); $this->record('ACKNOWLEDGE_FINANCE_EXPORT', 'finance.export.acknowledged', 'finance_export', $id, $data, $version, ['reference' => $reference], $result); $this->complete($namespace, $data, $result); return $result; }, 3);
    }

    /** Used by other finance workflows while their surrounding database transaction is active. */
    public function postSourceJournal(string $type, string $number, string $date, string $description, string $source, array $lines, array $data): string
    {
        if (! in_array($type, self::JOURNAL_TYPES, true)) throw ValidationException::withMessages(['journal_type' => ['Unsupported journal type.']]); $this->unique('journals', 'journal_number', $number, $data); $period = $this->period($date, $data); [$prepared, $debit, $credit] = $this->prepareLines($lines, $data, 6); $id = $this->insertJournal($type, $number, $period->id, $date, $description, $source, $prepared, $debit, $credit, $data, true); $result = $this->result('journal', $id, 'POSTED', 1, ['total_debit' => $debit]); $this->record('POST_SOURCE_JOURNAL', 'finance.journal.posted', 'journal', $id, $data, 1, ['journal_type' => $type, 'source_reference' => $source], $result); return $id;
    }

    private function insertJournal(string $type, string $number, string $periodId, string $date, string $description, ?string $source, array $lines, string $debit, string $credit, array $data, bool $posted, ?string $reversalOf = null): string
    {
        $id = (string) Str::uuid(); $now = CarbonImmutable::now(); DB::table('journals')->insert(['id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'journal_type' => $type, 'journal_number' => $number, 'fiscal_period_id' => $periodId, 'posting_date' => $date, 'description' => trim($description), 'currency' => 'INR', 'total_debit' => $debit, 'total_credit' => $credit, 'reversal_of_journal_id' => $reversalOf, 'source_reference' => $source, 'posted_at' => $posted ? $now : null, 'posted_by' => $posted ? $data['actor_id'] : null, 'reversed_at' => null, 'reversed_by' => null, 'reversal_reason' => null, 'status' => $posted ? 'POSTED' : 'DRAFT', 'record_version' => 1, 'created_by' => $data['actor_id'], 'created_at' => $now, 'updated_at' => $now]); foreach ($lines as $index => $line) DB::table('journal_lines')->insert(['id' => (string) Str::uuid(), 'journal_id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'line_number' => $index + 1, 'account_id' => $line['account_id'], 'party_id' => $line['party_id'] ?? null, 'description' => trim($line['description']), 'debit_amount' => $line['debit_amount'], 'credit_amount' => $line['credit_amount'], 'created_at' => $now, 'updated_at' => $now]); return $id;
    }

    private function prepareLines(array $lines, array $data, int $scale): array
    {
        $prepared = []; $debit = $credit = $this->decimal(0, $scale); foreach ($lines as $line) { $this->account($line['account_id'], $data); if (! empty($line['party_id']) && ! DB::table('parties')->where('id', $line['party_id'])->where('company_id', $data['company_id'])->exists()) throw ValidationException::withMessages(['party_id' => ['Party not found in the selected company.']]); $dr = $this->amount($line['debit_amount'] ?? 0, $scale); $cr = $this->amount($line['credit_amount'] ?? 0, $scale); if ((bccomp($dr, '0', $scale) > 0) === (bccomp($cr, '0', $scale) > 0)) throw ValidationException::withMessages(['lines' => ['Each journal line must contain exactly one positive debit or credit amount.']]); $prepared[] = ['account_id' => $line['account_id'], 'party_id' => $line['party_id'] ?? null, 'description' => trim($line['description']), 'debit_amount' => $dr, 'credit_amount' => $cr]; $debit = bcadd($debit, $dr, $scale); $credit = bcadd($credit, $cr, $scale); } if (count($prepared) < 2 || bccomp($debit, $credit, $scale) !== 0 || bccomp($debit, '0', $scale) <= 0) throw ValidationException::withMessages(['lines' => ['A journal requires at least two lines with equal, positive debit and credit totals.']]); return [$prepared, $debit, $credit];
    }

    private function expenseLines(array $lines, array $data): array
    {
        $prepared = []; $net = $tax = $total = '0.000000'; foreach ($lines as $line) { $this->account($line['expense_account_id'], $data); $lineNet = $this->positive($line['net_amount'], 'lines'); $rate = $this->amount($line['tax_rate'] ?? 0, 4); if (bccomp($rate, '100', 4) > 0) throw ValidationException::withMessages(['tax_rate' => ['Tax rate cannot exceed 100.']]); $lineTax = bcdiv(bcmul($lineNet, $rate, 10), '100', 6); $gross = bcadd($lineNet, $lineTax, 6); $prepared[] = ['expense_account_id' => $line['expense_account_id'], 'description' => trim($line['description']), 'net_amount' => $lineNet, 'tax_rate' => $rate, 'tax_amount' => $lineTax, 'gross_amount' => $gross]; $net = bcadd($net, $lineNet, 6); $tax = bcadd($tax, $lineTax, 6); $total = bcadd($total, $gross, 6); } return [$prepared, $net, $tax, $total];
    }

    private function insertExpenseLines(string $id, array $lines, array $data, CarbonImmutable $now): void { foreach ($lines as $index => $line) DB::table('expense_claim_lines')->insert(['id' => (string) Str::uuid(), 'expense_claim_id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'], 'line_number' => $index + 1, 'expense_account_id' => $line['expense_account_id'], 'description' => $line['description'], 'net_amount' => $line['net_amount'], 'tax_rate' => $line['tax_rate'], 'tax_amount' => $line['tax_amount'], 'gross_amount' => $line['gross_amount'], 'created_at' => $now, 'updated_at' => $now]); }
    private function costs(array $data): array { $labour = $this->amount($data['labour_cost'] ?? 0); $material = $this->amount($data['material_cost'] ?? 0); $external = $this->amount($data['external_cost'] ?? 0); return [$labour, $material, $external, bcadd(bcadd($labour, $material, 6), $external, 6)]; }

    private function period(string $date, array $data, ?string $id = null): object
    {
        $query = DB::table('fiscal_periods')->where('company_id', $data['company_id'])->where('starts_on', '<=', $date)->where('ends_on', '>=', $date); if ($id) $query->where('id', $id); $period = $query->first(); if (! $period) throw ValidationException::withMessages(['posting_date' => ['No fiscal period contains the posting date.']]); if ($period->status !== 'OPEN') throw ValidationException::withMessages(['posting_date' => ['The fiscal period is closed.']]); return $period;
    }

    private function account(string $id, array $data): object { $account = DB::table('chart_accounts')->where('id', $id)->where('company_id', $data['company_id'])->where('status', 'ACTIVE')->first(); if (! $account) throw ValidationException::withMessages(['account_id' => ['Active chart account not found.']]); return $account; }
    private function controlAccount(string $type, array $data): string { $id = DB::table('chart_accounts')->where('company_id', $data['company_id'])->where('control_type', $type)->where('status', 'ACTIVE')->value('id'); if (! $id) throw ValidationException::withMessages(['account' => ["No active {$type} control account is configured."]]); return (string) $id; }
    private function accountByType(string $type, array $data): string { $id = DB::table('chart_accounts')->where('company_id', $data['company_id'])->where('account_type', $type)->where('status', 'ACTIVE')->orderBy('account_code')->value('id'); if (! $id) throw ValidationException::withMessages(['account' => ["No active {$type} account is configured."]]); return (string) $id; }
    private function assertUser(string $id): void { if (! DB::table('users')->where('id', $id)->where('status', 'ACTIVE')->exists()) throw ValidationException::withMessages(['user_id' => ['Active user not found.']]); }
    private function find(string $table, string $id, array $data, string $label, bool $lock = false): object { $query = DB::table($table)->where('id', $id)->where($this->scope($data)); $record = ($lock ? $query->lockForUpdate() : $query)->first(); if (! $record) throw new NotFoundHttpException($label.' not found.'); return $record; }
    private function unique(string $table, string $column, string $value, array $data): void { $query = DB::table($table)->where('company_id', $data['company_id'])->where($column, $value); if (! empty($data['plant_id'])) $query->where('plant_id', $data['plant_id']); if ($query->exists()) throw ValidationException::withMessages([$column => ['That identifier already exists in the selected scope.']]); }
    private function scope(array $data): array { return ['company_id' => $data['company_id'], 'plant_id' => $data['plant_id']]; }
    private function version(object $record, array $data, string $label): void { if ((int) $record->record_version !== (int) $data['expected_version']) throw new ConflictHttpException("The {$label} changed. Refresh it before continuing."); }
    private function status(object $record, array $allowed, string $message): void { if (! in_array($record->status, $allowed, true)) throw ValidationException::withMessages(['status' => [$message]]); }
    private function positive(mixed $value, string $field, int $scale = 6): string { $value = $this->amount($value, $scale); if (bccomp($value, '0', $scale) <= 0) throw ValidationException::withMessages([$field => ['The value must be positive.']]); return $value; }
    private function amount(mixed $value, int $scale = 6): string { $text = (string) ($value ?? 0); if (! preg_match('/^\d{1,14}(?:\.\d{1,6})?$/', $text)) throw ValidationException::withMessages(['amount' => ['Amounts must be non-negative with at most six decimal places.']]); return bcadd($text, '0', $scale); }
    private function decimal(mixed $value, int $scale = 6): string { return bcadd((string) ($value ?? 0), '0', $scale); }
    private function result(string $entity, string $id, string $status, int $version, array $extra = []): array { return ['entity_type' => $entity, 'id' => $id, 'status' => $status, 'record_version' => $version] + $extra; }
    private function begin(string $namespace, array $data): ?array { return $this->idempotency->begin($namespace, $data['idempotency_key'], Arr::except($data, ['actor_id', 'permissions', 'idempotency_key', 'correlation_id'])); }
    private function complete(string $namespace, array $data, array $result): void { $this->idempotency->complete($namespace, $data['idempotency_key'], $result); }
    private function record(string $command, string $event, string $entity, string $id, array $data, int $version, array $diff, array $result): void { $this->audit->record($command, $entity, $id, $data['actor_id'], $data['company_id'], $data['plant_id'], 'SUCCESS', ['entity_version' => $version, 'correlation_id' => $data['correlation_id'] ?? null, 'safe_diff' => $diff]); $this->outbox->append($event, $entity, $id, $id.':'.$version, $result + ['company_id' => $data['company_id'], 'plant_id' => $data['plant_id']], $data['correlation_id'] ?? null, $data['company_id'], $data['plant_id']); }
}

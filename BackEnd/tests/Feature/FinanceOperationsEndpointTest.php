<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FinanceOperationsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = '00000000-0000-4000-8000-000000000001';
    private const PLANT = '00000000-0000-4000-8000-000000000101';
    private const OTHER_PLANT = '00000000-0000-4000-8000-000000000102';
    private const OPERATIONS = '00000000-0000-4000-8000-000000000202';
    private const FINANCE = '00000000-0000-4000-8000-000000000203';
    private const ADMIN = '00000000-0000-4000-8000-000000000204';
    private const PERIOD = '00000000-0000-4000-8000-000000002101';
    private const EXPENSE = '00000000-0000-4000-8000-000000002013';
    private const OVERHEAD = '00000000-0000-4000-8000-000000002014';
    private const ASSET = '00000000-0000-4000-8000-000000002005';
    private const ACC_DEP = '00000000-0000-4000-8000-000000002006';
    private const DEP_EXP = '00000000-0000-4000-8000-000000002015';
    private const PAY_EXP = '00000000-0000-4000-8000-000000002016';
    private const PAYABLE = '00000000-0000-4000-8000-000000002009';
    private const MAINT_EXP = '00000000-0000-4000-8000-000000002017';

    protected function setUp(): void { parent::setUp(); $this->seed(); $this->signIn(self::FINANCE); }

    public function test_expense_manual_ledger_overhead_asset_and_payroll_are_posted_with_controls(): void
    {
        $expense = $this->command()->postJson('/api/v1/finance/expenses', ['expense_number' => 'EXP-P2-001', 'claimant_user_id' => self::FINANCE, 'expense_date' => '2026-09-13', 'category' => 'SUPPLIES', 'description' => 'Packaging design supplies.', 'lines' => [['expense_account_id' => self::EXPENSE, 'description' => 'Supplies', 'net_amount' => '1000', 'tax_rate' => '18']]])->assertCreated()->assertJsonPath('data.total_amount', '1180.000000');
        $expenseId = (string) $expense->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/finance/expenses/'.$expenseId.'/submit')->assertOk()->assertJsonPath('data.status', 'SUBMITTED');
        $this->withHeaders($this->headers(2))->postJson('/api/v1/finance/expenses/'.$expenseId.'/approve')->assertUnprocessable();
        $this->signIn(self::ADMIN);
        $this->withHeaders($this->headers(2))->postJson('/api/v1/finance/expenses/'.$expenseId.'/approve')->assertOk()->assertJsonPath('data.status', 'APPROVED');
        $postedExpense = $this->withHeaders($this->headers(3))->postJson('/api/v1/finance/expenses/'.$expenseId.'/post')->assertOk()->assertJsonPath('data.status', 'POSTED');
        $this->assertBalanced((string) $postedExpense->json('data.journal_id'), '1180.000000');

        $journal = $this->command()->postJson('/api/v1/finance/journals', ['journal_number' => 'JV-P2-001', 'fiscal_period_id' => self::PERIOD, 'posting_date' => '2026-09-13', 'description' => 'Manual reclassification.', 'source_reference' => 'TEST-P2', 'lines' => [['account_id' => self::EXPENSE, 'description' => 'Debit', 'debit_amount' => '250', 'credit_amount' => '0'], ['account_id' => self::OVERHEAD, 'description' => 'Credit', 'debit_amount' => '0', 'credit_amount' => '250']]])->assertCreated();
        $journalId = (string) $journal->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/finance/journals/'.$journalId.'/post')->assertOk()->assertJsonPath('data.status', 'POSTED');
        $reversed = $this->withHeaders($this->headers(2))->postJson('/api/v1/finance/journals/'.$journalId.'/reverse', ['reason' => 'Test reversal required.', 'reversal_number' => 'RV-P2-001', 'posting_date' => '2026-09-13'])->assertOk()->assertJsonPath('data.status', 'REVERSED');
        $this->assertBalanced((string) $reversed->json('data.reversal_journal_id'), '250.000000');

        $pool = $this->command()->postJson('/api/v1/costing/overheads', ['pool_code' => 'OH-P2', 'name' => 'Packaging overhead', 'expense_account_id' => self::OVERHEAD, 'allocation_basis' => 'OUTPUT_UNIT', 'rate' => '2.5'])->assertCreated();
        $allocation = $this->command()->postJson('/api/v1/costing/overheads/'.$pool->json('data.id').'/allocate', ['allocation_number' => 'OHA-P2-001', 'fiscal_period_id' => self::PERIOD, 'posting_date' => '2026-09-13', 'production_order_id' => null, 'target_account_id' => self::EXPENSE, 'basis_quantity' => '40'])->assertCreated()->assertJsonPath('data.allocated_amount', '100.000000');
        $this->assertBalanced((string) $allocation->json('data.journal_id'), '100.000000');

        $asset = $this->command()->postJson('/api/v1/finance/assets', ['asset_number' => 'AST-P2-001', 'name' => 'Packing conveyor', 'category' => 'EQUIPMENT', 'acquisition_date' => '2026-09-01', 'acquisition_cost' => '120000', 'residual_value' => '12000', 'useful_life_months' => 36, 'asset_account_id' => self::ASSET, 'depreciation_account_id' => self::ACC_DEP, 'depreciation_expense_account_id' => self::DEP_EXP])->assertCreated();
        $assetId = (string) $asset->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/finance/assets/'.$assetId.'/activate', ['posting_date' => '2026-09-01'])->assertOk()->assertJsonPath('data.status', 'ACTIVE');
        $depreciation = $this->withHeaders($this->headers(2))->postJson('/api/v1/finance/assets/'.$assetId.'/depreciate', ['fiscal_period_id' => self::PERIOD])->assertOk()->assertJsonPath('data.depreciation_amount', '3000.000000');
        $this->assertBalanced((string) $depreciation->json('data.journal_id'), '3000.000000');
        $this->getJson('/api/v1/finance/assets/'.$assetId)->assertOk()
            ->assertJsonPath('data.historical_acquisition_cost', '120000.000000')
            ->assertJsonPath('data.current_carrying_amount', '117000.000000')
            ->assertJsonPath('data.carrying_amount_basis', 'ACQUISITION_LESS_ACCUMULATED_DEPRECIATION');
        $disposal = $this->withHeaders($this->headers(3))->postJson('/api/v1/finance/assets/'.$assetId.'/dispose', [
            'posting_date' => '2026-09-13',
            'disposal_proceeds' => '100000',
            'reason' => 'Governed disposal acceptance test.',
        ])->assertOk()
            ->assertJsonPath('data.status', 'DISPOSED')
            ->assertJsonPath('data.carrying_amount_before_disposal', '117000.000000')
            ->assertJsonPath('data.current_carrying_amount', '0.000000');
        // The journal clears the historical asset cost (120,000); the API separately
        // proves that the carrying amount immediately before disposal was 117,000.
        $this->assertBalanced((string) $disposal->json('data.journal_id'), '120000.000000');
        $this->getJson('/api/v1/finance/assets/'.$assetId)->assertOk()
            ->assertJsonPath('data.historical_acquisition_cost', '120000.000000')
            ->assertJsonPath('data.current_carrying_amount', '0.000000')
            ->assertJsonPath('data.carrying_amount_basis', 'DERECOGNISED_AFTER_DISPOSAL');

        $employee = $this->command()->postJson('/api/v1/finance/employees', ['employee_number' => 'EMP-P2-001', 'name' => 'P2 Operator', 'department' => 'PACKING', 'monthly_gross' => '50000', 'monthly_deductions' => '5000', 'expense_account_id' => self::PAY_EXP, 'payable_account_id' => self::PAYABLE])->assertCreated();
        $payroll = $this->command()->postJson('/api/v1/finance/payroll', ['run_number' => 'PAYROLL-P2-001', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'employee_ids' => [$employee->json('data.id')]])->assertCreated()->assertJsonPath('data.net_amount', '45000.000000');
        $payrollId = (string) $payroll->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/finance/payroll/'.$payrollId.'/approve')->assertUnprocessable();
        $this->signIn(self::FINANCE); $this->withHeaders($this->headers(1))->postJson('/api/v1/finance/payroll/'.$payrollId.'/approve')->assertOk();
        $payrollPost = $this->withHeaders($this->headers(2))->postJson('/api/v1/finance/payroll/'.$payrollId.'/post')->assertOk()->assertJsonPath('data.status', 'POSTED');
        $this->assertBalanced((string) $payrollPost->json('data.journal_id'), '50000.000000');
        $this->getJson('/api/v1/finance/ledger')->assertOk()->assertJsonPath('summary.draft_count', 0);
        $this->getJson('/api/v1/finance/assets/'.$assetId)->assertOk()->assertJsonCount(1, 'data.lines');
        $this->assertGreaterThanOrEqual(9, DB::table('audit_events')->where('company_id', self::COMPANY)->count());
    }

    public function test_maintenance_period_close_scope_and_permissions_are_enforced(): void
    {
        $this->signIn(self::OPERATIONS);
        $work = $this->command()->postJson('/api/v1/engineering/maintenance', ['work_order_number' => 'MNT-P2-001', 'asset_id' => null, 'priority' => 'HIGH', 'description' => 'Inspect sealing line.', 'planned_date' => '2026-09-13', 'labour_cost' => '0', 'material_cost' => '0', 'external_cost' => '0', 'expense_account_id' => self::MAINT_EXP])->assertCreated();
        $workId = (string) $work->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/engineering/maintenance/'.$workId.'/release')->assertOk();
        $this->signIn(self::FINANCE);
        $completed = $this->withHeaders($this->headers(2))->postJson('/api/v1/engineering/maintenance/'.$workId.'/complete', ['posting_date' => '2026-09-13', 'labour_cost' => '800', 'material_cost' => '200', 'external_cost' => '0'])->assertOk()->assertJsonPath('data.status', 'COMPLETED');
        $this->assertBalanced((string) $completed->json('data.journal_id'), '1000.000000');

        $this->signIn(self::ADMIN, self::OTHER_PLANT);
        $this->getJson('/api/v1/engineering/maintenance/'.$workId)->assertNotFound();
        $this->signIn(self::ADMIN);
        $period = '00000000-0000-4000-8000-000000002102';
        $this->withHeaders($this->headers(1))->postJson('/api/v1/finance/fiscal-periods/'.$period.'/close', ['notes' => 'October period closed after reconciliation.'])->assertOk()->assertJsonPath('data.status', 'CLOSED');
        $this->command()->postJson('/api/v1/finance/journals', ['journal_number' => 'JV-CLOSED-001', 'fiscal_period_id' => $period, 'posting_date' => '2026-10-10', 'description' => 'Must be rejected.', 'lines' => [['account_id' => self::EXPENSE, 'description' => 'Debit', 'debit_amount' => '1', 'credit_amount' => '0'], ['account_id' => self::OVERHEAD, 'description' => 'Credit', 'debit_amount' => '0', 'credit_amount' => '1']]])->assertUnprocessable();
    }

    public function test_ap_bank_export_is_deterministic_single_use_and_acknowledged(): void
    {
        $proposalId = (string) Str::uuid(); $paymentId = (string) Str::uuid();
        DB::table('payment_proposals')->insert(['id' => $proposalId, 'company_id' => self::COMPANY, 'plant_id' => self::PLANT, 'proposal_number' => 'PROP-EXPORT-P2', 'payment_date' => '2026-09-13', 'currency' => 'INR', 'total_amount' => 1500, 'notes' => null, 'status' => 'EXECUTED', 'record_version' => 3, 'created_by' => self::ADMIN, 'approved_at' => now(), 'approved_by' => self::FINANCE, 'executed_at' => now(), 'executed_by' => self::ADMIN, 'cancelled_at' => null, 'cancelled_by' => null, 'cancellation_reason' => null, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('supplier_payments')->insert(['id' => $paymentId, 'company_id' => self::COMPANY, 'plant_id' => self::PLANT, 'payment_proposal_id' => $proposalId, 'payment_number' => 'PAY-EXPORT-P2', 'payment_date' => '2026-09-13', 'method' => 'BANK_TRANSFER', 'bank_reference' => 'UTR-EXPORT-P2', 'currency' => 'INR', 'total_amount' => 1500, 'status' => 'POSTED', 'record_version' => 1, 'created_by' => self::ADMIN, 'reconciled_at' => null, 'reconciled_by' => null, 'created_at' => now(), 'updated_at' => now()]);

        $export = $this->command()->postJson('/api/v1/finance/payables/exports', ['export_number' => 'APBANK-P2-001', 'export_type' => 'AP_BANK', 'bank_account_id' => '00000000-0000-4000-8000-000000002201', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30'])->assertCreated()->assertJsonPath('data.record_count', 1)->assertJsonPath('data.total_amount', '1500.000000');
        $exportId = (string) $export->json('data.id'); $hash = (string) $export->json('data.sha256'); $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $hash);
        $this->getJson('/api/v1/finance/payables/exports/'.$exportId)->assertOk()->assertJsonPath('data.sha256', $hash)->assertJsonCount(1, 'data.lines');
        $this->command()->postJson('/api/v1/finance/payables/exports', ['export_number' => 'APBANK-P2-002', 'export_type' => 'AP_BANK', 'bank_account_id' => '00000000-0000-4000-8000-000000002201', 'period_start' => '2026-09-01', 'period_end' => '2026-09-30'])->assertUnprocessable();
        $this->withHeaders($this->headers(1))->postJson('/api/v1/finance/payables/exports/'.$exportId.'/acknowledge', ['acknowledgement_reference' => 'BANK-ACK-P2-001'])->assertOk()->assertJsonPath('data.status', 'ACKNOWLEDGED');
        $this->getJson('/api/v1/finance/payables/integrations')->assertOk()->assertJsonPath('summary.generated_count', 0);
    }

    private function assertBalanced(string $journalId, string $expected): void { $journal = DB::table('journals')->where('id', $journalId)->first(); $this->assertNotNull($journal); $this->assertSame($expected, $this->decimal($journal->total_debit)); $this->assertSame($expected, $this->decimal($journal->total_credit)); $this->assertSame('POSTED', $journal->status); }
    private function signIn(string $id, string $plant = self::PLANT): void { $this->actingAs(User::query()->findOrFail($id))->withSession(['erp.company_id' => self::COMPANY, 'erp.plant_id' => $plant]); }
    private function command(): static { return $this->withHeader('Idempotency-Key', (string) Str::uuid()); }
    private function headers(int $version): array { return ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => (string) $version]; }
    private function decimal(mixed $value): string { return bcadd((string) ($value ?? 0), '0', 6); }
}

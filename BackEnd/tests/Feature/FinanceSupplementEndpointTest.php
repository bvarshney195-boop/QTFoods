<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class FinanceSupplementEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = '00000000-0000-4000-8000-000000000001';
    private const PLANT = '00000000-0000-4000-8000-000000000101';
    private const OTHER_PLANT = '00000000-0000-4000-8000-000000000102';
    private const FINANCE = '00000000-0000-4000-8000-000000000203';
    private const ADMIN = '00000000-0000-4000-8000-000000000204';
    private const EXPENSE = '00000000-0000-4000-8000-000000002013';
    private const OVERHEAD = '00000000-0000-4000-8000-000000002014';
    private const REVENUE = '00000000-0000-4000-8000-000000002011';
    private const EQUITY = '00000000-0000-4000-8000-000000002010';
    private const CASH = '00000000-0000-4000-8000-000000002001';

    protected function setUp(): void { parent::setUp(); config()->set('qtfoods.private_document_disk', 'private'); Storage::fake('private'); $this->seed(); $this->signIn(self::FINANCE); }

    public function test_simulation_is_isolated_and_adjustment_legacy_and_opening_balances_post_governed_journals(): void
    {
        $journalCount = DB::table('journals')->count();
        $simulation = $this->command()->postJson('/api/v1/finance/simulations', ['simulation_number' => 'SIM-P2-001', 'name' => 'Price elasticity scenario', 'description' => 'Projected revenue and marketing spend.', 'as_of_date' => '2026-09-13', 'lines' => [['account_id' => self::REVENUE, 'description' => 'Projected sales', 'debit_amount' => '0', 'credit_amount' => '50000', 'assumption' => 'Volume grows 10 percent.'], ['account_id' => self::EXPENSE, 'description' => 'Campaign expense', 'debit_amount' => '12000', 'credit_amount' => '0', 'assumption' => 'Campaign held at budget.']]])->assertCreated();
        $simulationId = (string) $simulation->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/finance/simulations/'.$simulationId.'/run')->assertOk()->assertJsonPath('data.status', 'RUN')->assertJsonPath('data.projected_profit', '38000.0000')->assertJsonPath('data.result.ledger_isolated', true);
        $this->assertSame($journalCount, DB::table('journals')->count());

        $adjustment = $this->command()->postJson('/api/v1/finance/adjustments', ['adjustment_number' => 'ADJ-P2-001', 'adjustment_date' => '2026-09-13', 'adjustment_type' => 'RECLASSIFICATION', 'reason' => 'Reclassify packaging overhead.', 'lines' => $this->balancedLines('400')])->assertCreated();
        $adjustmentId = (string) $adjustment->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/finance/adjustments/'.$adjustmentId.'/submit')->assertOk();
        $this->withHeaders($this->headers(2))->postJson('/api/v1/finance/adjustments/'.$adjustmentId.'/approve')->assertUnprocessable();
        $this->signIn(self::ADMIN);
        $this->withHeaders($this->headers(2))->postJson('/api/v1/finance/adjustments/'.$adjustmentId.'/approve')->assertOk();
        $adjustmentPost = $this->withHeaders($this->headers(3))->postJson('/api/v1/finance/adjustments/'.$adjustmentId.'/post')->assertOk()->assertJsonPath('data.status', 'POSTED');
        $this->assertBalanced((string) $adjustmentPost->json('data.journal_id'), '400.000000');

        $legacy = $this->command()->postJson('/api/v1/finance/legacy-imports', ['batch_number' => 'LEG-P2-001', 'source_system' => 'LEGACY-TALLY', 'posting_date' => '2026-09-13', 'rows' => [['legacy_account_code' => '510000', 'description' => 'Imported debit', 'debit_amount' => '700', 'credit_amount' => '0', 'source' => ['row' => 1]], ['legacy_account_code' => '520000', 'description' => 'Imported credit', 'debit_amount' => '0', 'credit_amount' => '700', 'source' => ['row' => 2]]]])->assertCreated();
        $legacyId = (string) $legacy->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/finance/legacy-imports/'.$legacyId.'/validate')->assertOk()->assertJsonPath('data.status', 'VALID')->assertJsonPath('data.error_count', 0);
        $legacyPost = $this->withHeaders($this->headers(2))->postJson('/api/v1/finance/legacy-imports/'.$legacyId.'/post')->assertOk()->assertJsonPath('data.status', 'POSTED');
        $this->assertBalanced((string) $legacyPost->json('data.journal_id'), '700.000000');

        $opening = $this->command()->postJson('/api/v1/finance/opening-balances', ['batch_number' => 'OPEN-P2-001', 'opening_date' => '2026-09-01', 'notes' => 'Validated migration opening position.', 'lines' => [['account_id' => self::CASH, 'description' => 'Opening cash', 'debit_amount' => '10000', 'credit_amount' => '0'], ['account_id' => self::EQUITY, 'description' => 'Opening equity', 'debit_amount' => '0', 'credit_amount' => '10000']]])->assertCreated()->assertJsonPath('data.difference_amount', '0.0000');
        $openingId = (string) $opening->json('data.id');
        $detail = $this->getJson('/api/v1/finance/opening-balances/'.$openingId)->assertOk()->json('data.lines');
        $reconciliations = collect($detail)->map(fn (array $line) => ['line_id' => $line['id'], 'reconciled_amount' => $line['source_amount']])->all();
        $this->withHeaders($this->headers(1))->postJson('/api/v1/finance/opening-balances/'.$openingId.'/reconcile', ['lines' => $reconciliations])->assertOk()->assertJsonPath('data.status', 'RECONCILED');
        $openingPost = $this->withHeaders($this->headers(2))->postJson('/api/v1/finance/opening-balances/'.$openingId.'/post')->assertOk()->assertJsonPath('data.status', 'POSTED');
        $this->assertBalanced((string) $openingPost->json('data.journal_id'), '10000.000000');
        $this->getJson('/api/v1/finance/simulation')->assertOk()->assertJsonPath('summary.ledger_effect', 'NONE');
    }

    public function test_private_archive_support_diagnostics_and_scope_are_operational(): void
    {
        $archive = $this->command()->postJson('/api/v1/finance/archive', ['document_number' => 'DOC-P2-001', 'document_type' => 'SUPPLIER_INVOICE', 'document_date' => '2026-09-13', 'retain_until' => '2034-09-13', 'notes' => 'Statutory retention copy.', 'file' => UploadedFile::fake()->createWithContent('supplier-bill.pdf', '%PDF-1.4 P2 private bill')])->assertCreated()->assertJsonPath('data.status', 'ARCHIVED');
        $documentId = (string) $archive->json('data.id'); $document = DB::table('bill_archive_documents')->where('id', $documentId)->first(); $this->assertNotNull($document); $this->assertSame('private', $document->storage_disk); Storage::disk('private')->assertExists($document->storage_path);
        $this->get('/api/v1/finance/archive/'.$documentId.'/download')->assertOk()->assertHeader('X-Content-SHA256', $document->sha256_checksum);
        $this->command()->postJson('/api/v1/finance/archive', ['document_number' => 'DOC-P2-002', 'document_type' => 'SUPPLIER_INVOICE', 'document_date' => '2026-09-13', 'retain_until' => '2034-09-13', 'file' => UploadedFile::fake()->createWithContent('same-bill.pdf', '%PDF-1.4 P2 private bill')])->assertUnprocessable();

        $case = $this->command()->postJson('/api/v1/finance/support-cases', ['case_number' => 'FS-P2-001', 'category' => 'RECONCILIATION', 'severity' => 'HIGH', 'subject' => 'Ledger diagnostic request', 'description' => 'Investigate the finance workspace without rewriting ledger rows.'])->assertCreated();
        $caseId = (string) $case->json('data.id');
        $diagnosed = $this->withHeaders($this->headers(1))->postJson('/api/v1/finance/support-cases/'.$caseId.'/diagnose', ['notes' => 'Captured immutable diagnostic counters.'])->assertOk()->assertJsonPath('data.status', 'DIAGNOSED');
        $this->assertArrayHasKey('draft_journals', $diagnosed->json('data.diagnostic_snapshot'));
        $this->withHeaders($this->headers(2))->postJson('/api/v1/finance/support-cases/'.$caseId.'/close', ['resolution_notes' => 'Reconciliation reviewed and user guidance provided.'])->assertOk()->assertJsonPath('data.status', 'CLOSED');
        $this->getJson('/api/v1/finance/support-cases/'.$caseId)->assertOk()->assertJsonCount(3, 'data.lines');

        $this->signIn(self::ADMIN, self::OTHER_PLANT);
        $this->getJson('/api/v1/finance/archive/'.$documentId)->assertNotFound();
        $this->getJson('/api/v1/finance/support-cases/'.$caseId)->assertNotFound();
    }

    private function balancedLines(string $amount): array { return [['account_id' => self::EXPENSE, 'description' => 'Debit adjustment', 'debit_amount' => $amount, 'credit_amount' => '0'], ['account_id' => self::OVERHEAD, 'description' => 'Credit adjustment', 'debit_amount' => '0', 'credit_amount' => $amount]]; }
    private function assertBalanced(string $id, string $amount): void { $journal = DB::table('journals')->where('id', $id)->first(); $this->assertNotNull($journal); $this->assertSame($amount, bcadd((string) $journal->total_debit, '0', 6)); $this->assertSame($amount, bcadd((string) $journal->total_credit, '0', 6)); $this->assertSame('POSTED', $journal->status); }
    private function signIn(string $id, string $plant = self::PLANT): void { $this->actingAs(User::query()->findOrFail($id))->withSession(['erp.company_id' => self::COMPANY, 'erp.plant_id' => $plant]); }
    private function command(): static { return $this->withHeader('Idempotency-Key', (string) Str::uuid()); }
    private function headers(int $version): array { return ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => (string) $version]; }
}

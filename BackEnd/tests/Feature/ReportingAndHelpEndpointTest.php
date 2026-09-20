<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ReportingAndHelpEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = '00000000-0000-4000-8000-000000000001';
    private const PLANT = '00000000-0000-4000-8000-000000000101';
    private const OTHER_PLANT = '00000000-0000-4000-8000-000000000102';
    private const SALES = '00000000-0000-4000-8000-000000000201';
    private const OPERATIONS = '00000000-0000-4000-8000-000000000202';
    private const FINANCE = '00000000-0000-4000-8000-000000000203';
    private const ADMIN = '00000000-0000-4000-8000-000000000204';
    private const BI = '00000000-0000-4000-8000-000000000206';
    private const PERIOD = '00000000-0000-4000-8000-000000002101';
    private const EXPENSE = '00000000-0000-4000-8000-000000002013';
    private const OVERHEAD = '00000000-0000-4000-8000-000000002014';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    public function test_report_run_is_cutoff_bound_immutable_idempotent_and_exportable(): void
    {
        $this->signIn(self::FINANCE);
        $journal = $this->command()->postJson('/api/v1/finance/journals', [
            'journal_number' => 'JV-REPORT-001',
            'fiscal_period_id' => self::PERIOD,
            'posting_date' => '2026-09-13',
            'description' => 'Reporting control journal.',
            'source_reference' => 'REPORT-TEST',
            'lines' => [
                ['account_id' => self::EXPENSE, 'description' => 'Report debit', 'debit_amount' => '1250', 'credit_amount' => '0'],
                ['account_id' => self::OVERHEAD, 'description' => 'Report credit', 'debit_amount' => '0', 'credit_amount' => '1250'],
            ],
        ])->assertCreated();
        $this->withHeaders($this->headers(1))->postJson('/api/v1/finance/journals/'.$journal->json('data.id').'/post')
            ->assertOk()->assertJsonPath('data.status', 'POSTED');

        $workspace = $this->getJson('/api/v1/reports')->assertOk()->assertJsonCount(4, 'definitions');
        $this->assertContains('RUN', $workspace->json('allowed_actions'));
        $this->assertContains('EXPORT', $workspace->json('allowed_actions'));
        $payload = [
            'run_number' => 'rep-trial-001',
            'report_code' => 'trial_balance',
            'as_of_date' => '2026-09-13',
            'parameters' => ['include_zero' => false],
        ];
        $key = (string) Str::uuid();
        $created = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/reports/runs', $payload)
            ->assertCreated()->assertJsonPath('data.report_code', 'TRIAL_BALANCE')
            ->assertJsonPath('data.run_number', 'REP-TRIAL-001')
            ->assertJsonPath('data.row_count', 2);
        $runId = (string) $created->json('data.id');
        $checksum = (string) $created->json('data.sha256');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $checksum);
        $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/reports/runs', $payload)
            ->assertCreated()->assertExactJson($created->json());

        $detail = $this->getJson('/api/v1/reports/runs/'.$runId)->assertOk()
            ->assertJsonPath('data.sha256', $checksum)->assertJsonPath('data.totals.debit', '1250.000000')
            ->assertJsonPath('data.totals.credit', '1250.000000')->assertJsonCount(2, 'data.rows');
        $this->assertSame(['510000', '520000'], collect($detail->json('data.rows'))->pluck('data.account_code')->all());

        $export = $this->command()->postJson('/api/v1/reports/runs/'.$runId.'/exports', ['format' => 'csv'])
            ->assertCreated()->assertJsonPath('data.format', 'CSV')->assertJsonPath('data.row_count', 2);
        $exportId = (string) $export->json('data.id');
        $this->get('/api/v1/reports/exports/'.$exportId.'/download')->assertOk()
            ->assertHeader('X-Content-SHA256', $export->json('data.sha256'))
            ->assertHeader('Content-Disposition', 'attachment; filename="rep-trial-001.csv"')
            ->assertSee('General operating expense')->assertSee('1250.000000');
        $this->assertDatabaseHas('audit_events', ['command' => 'GENERATE_REPORT', 'entity_id' => $runId]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'reporting.export.created', 'aggregate_id' => $exportId]);
    }

    public function test_report_permissions_scope_filters_and_current_inventory_cutoff_are_enforced(): void
    {
        $this->signIn(self::OPERATIONS);
        $this->getJson('/api/v1/reports')->assertForbidden();
        $this->command()->postJson('/api/v1/reports/runs', [])->assertForbidden();

        $this->signIn(self::FINANCE);
        $this->command()->postJson('/api/v1/reports/runs', [
            'run_number' => 'REP-ORDER-BAD-PARAM',
            'report_code' => 'ORDER_FULFILMENT',
            'as_of_date' => '2026-09-14',
            'parameters' => ['include_zero' => true],
        ])->assertUnprocessable()->assertJsonPath(
            'error.fields.parameters.0',
            'Unsupported parameter(s) for this report: include_zero.',
        );
        $run = $this->command()->postJson('/api/v1/reports/runs', [
            'run_number' => 'REP-ORDER-001',
            'report_code' => 'ORDER_FULFILMENT',
            'as_of_date' => '2026-09-14',
            'parameters' => ['include_closed' => true],
        ])->assertCreated();
        $runId = (string) $run->json('data.id');
        $this->getJson('/api/v1/reports?report_code=ORDER_FULFILMENT&q=ORDER')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $runId);

        $pastDate = now('Asia/Kolkata')->subDay()->toDateString();
        $this->command()->postJson('/api/v1/reports/runs', [
            'run_number' => 'REP-STOCK-PAST',
            'report_code' => 'INVENTORY_AVAILABILITY',
            'as_of_date' => $pastDate,
        ])->assertUnprocessable()->assertJsonPath(
            'error.fields.as_of_date.0',
            'Inventory availability is a current ledger projection and must use today as its cutoff.',
        );

        $this->signIn(self::ADMIN, self::OTHER_PLANT);
        $this->getJson('/api/v1/reports/runs/'.$runId)->assertNotFound();
    }

    public function test_bi_analyst_role_is_read_only_and_can_run_export_and_profitability_reports(): void
    {
        $this->signIn(self::BI);

        $session = $this->getJson('/api/v1/me')->assertOk();
        $this->assertSame(['BI_ANALYST'], $session->json('data.roles'));
        $this->assertSame(['BI-PROFIT', 'BI-REP'], $session->json('data.allowed_screens'));
        $this->assertSame(
            ['ACTION:BI-REP:EXPORT', 'ACTION:BI-REP:RUN'],
            $session->json('data.allowed_actions'),
        );

        $workspace = $this->getJson('/api/v1/reports')->assertOk();
        $this->assertContains('RUN', $workspace->json('allowed_actions'));
        $this->assertContains('EXPORT', $workspace->json('allowed_actions'));

        $run = $this->command()->postJson('/api/v1/reports/runs', [
            'run_number' => 'REP-BI-ANALYST-001',
            'report_code' => 'INVENTORY_AVAILABILITY',
            'as_of_date' => now('Asia/Kolkata')->toDateString(),
            'parameters' => ['include_zero' => false],
        ])->assertCreated()->assertJsonPath('data.report_code', 'INVENTORY_AVAILABILITY');

        $runId = (string) $run->json('data.id');
        $this->command()->postJson('/api/v1/reports/runs/'.$runId.'/exports', ['format' => 'CSV'])
            ->assertCreated()->assertJsonPath('data.format', 'CSV');

        $this->getJson('/api/v1/reports/profitability')->assertOk();

        $this->getJson('/api/v1/finance/journals')->assertForbidden();
        $this->command()->postJson('/api/v1/sales/leads', [])->assertForbidden();
        $this->command()->postJson('/api/v1/inventory/issues', [])->assertForbidden();
    }

    public function test_help_articles_are_role_filtered_and_support_cases_complete_the_handoff(): void
    {
        $this->signIn(self::SALES);
        $workspace = $this->getJson('/api/v1/admin/help')->assertOk()
            ->assertJsonPath('is_support_manager', false)
            ->assertJsonFragment(['slug' => 'getting-started-with-your-context'])
            ->assertJsonFragment(['slug' => 'work-queue-ownership-and-escalation']);
        $this->assertNotContains('controlled-report-snapshots-and-exports', collect($workspace->json('articles'))->pluck('slug')->all());
        $this->getJson('/api/v1/admin/help/articles/controlled-report-snapshots-and-exports')->assertNotFound();
        $this->getJson('/api/v1/admin/help/articles/getting-started-with-your-context')
            ->assertOk()->assertJsonCount(3, 'data.sections');

        $created = $this->command()->postJson('/api/v1/admin/help/cases', [
            'category' => 'workflow',
            'priority' => 'high',
            'affected_screen_code' => 'CRM-ORDER',
            'subject' => 'Confirmed order does not appear in picking',
            'description' => 'The confirmed sales order is visible, but no allocation task is available for picking.',
        ])->assertCreated()->assertJsonPath('data.status', 'OPEN');
        $caseId = (string) $created->json('data.id');
        $caseNumber = (string) $created->json('data.case_number');
        $this->assertMatchesRegularExpression('/^HLP-\d{8}-[A-F0-9]{8}$/', $caseNumber);
        $this->getJson('/api/v1/admin/help')->assertOk()->assertJsonCount(1, 'cases');

        $this->signIn(self::OPERATIONS);
        $this->getJson('/api/v1/admin/help/cases/'.$caseId)->assertNotFound();
        $this->signIn(self::ADMIN);
        $this->getJson('/api/v1/admin/help')->assertOk()->assertJsonPath('is_support_manager', true)
            ->assertJsonFragment(['case_number' => $caseNumber]);
        $this->withHeaders($this->headers(1))->postJson('/api/v1/admin/help/cases/'.$caseId.'/start')
            ->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS')->assertJsonPath('data.record_version', 2);
        $this->withHeaders($this->headers(2))->postJson('/api/v1/admin/help/cases/'.$caseId.'/resolve', [
            'resolution_summary' => 'The order was credit-held. Finance released the hold and allocation is now available.',
        ])->assertOk()->assertJsonPath('data.status', 'RESOLVED')->assertJsonPath('data.record_version', 3);

        $this->signIn(self::SALES);
        $this->withHeaders($this->headers(2))->postJson('/api/v1/admin/help/cases/'.$caseId.'/close', [
            'confirmation' => 'Allocation is now visible and the issue is resolved.',
        ])->assertConflict();
        $this->withHeaders($this->headers(3))->postJson('/api/v1/admin/help/cases/'.$caseId.'/close', [
            'confirmation' => 'Allocation is now visible and the issue is resolved.',
        ])->assertOk()->assertJsonPath('data.status', 'CLOSED')->assertJsonPath('data.record_version', 4);
        $this->getJson('/api/v1/admin/help/cases/'.$caseId)->assertOk()->assertJsonCount(4, 'data.events')
            ->assertJsonPath('data.events.3.event_type', 'CLOSED');
        $this->assertDatabaseHas('audit_events', ['command' => 'CLOSED_HELP_SUPPORT_CASE', 'entity_id' => $caseId]);
        $this->assertDatabaseHas('outbox_events', ['event_type' => 'help.support.resolved', 'aggregate_id' => $caseId]);

        $this->signIn(self::ADMIN, self::OTHER_PLANT);
        $this->getJson('/api/v1/admin/help/cases/'.$caseId)->assertNotFound();
    }

    public function test_help_case_screen_scope_and_state_permissions_are_enforced(): void
    {
        $this->signIn(self::SALES);
        $this->command()->postJson('/api/v1/admin/help/cases', [
            'category' => 'REPORTING',
            'priority' => 'NORMAL',
            'affected_screen_code' => 'BI-REP',
            'subject' => 'Report help request',
            'description' => 'This role must not open support cases for a screen it cannot access.',
        ])->assertUnprocessable()->assertJsonPath(
            'error.fields.affected_screen_code.0',
            'Choose a screen available in your current role and context.',
        );
        $case = $this->command()->postJson('/api/v1/admin/help/cases', [
            'category' => 'ACCESS',
            'priority' => 'NORMAL',
            'affected_screen_code' => 'CRM-ORDER',
            'subject' => 'Order access question',
            'description' => 'Please confirm the correct action authority for sales-order amendments.',
        ])->assertCreated();
        $caseId = (string) $case->json('data.id');
        $this->withHeaders($this->headers(1))->postJson('/api/v1/admin/help/cases/'.$caseId.'/start')->assertForbidden();
        $this->withHeaders($this->headers(1))->postJson('/api/v1/admin/help/cases/'.$caseId.'/comments', [
            'message' => 'The order is still in draft state.',
        ])->assertOk()->assertJsonPath('data.record_version', 2);
        $this->withHeaders($this->headers(2))->postJson('/api/v1/admin/help/cases/'.$caseId.'/close', [
            'confirmation' => 'Trying to close an unresolved request.',
        ])->assertUnprocessable()->assertJsonPath(
            'error.fields.status.0', 'Only a resolved support case can be closed.',
        );
    }

    private function signIn(string $id, string $plant = self::PLANT): void
    {
        $this->actingAs(User::query()->findOrFail($id))->withSession([
            'erp.company_id' => self::COMPANY,
            'erp.plant_id' => $plant,
        ]);
    }

    private function command(): static
    {
        return $this->withHeader('Idempotency-Key', (string) Str::uuid());
    }

    private function headers(int $version): array
    {
        return ['Idempotency-Key' => (string) Str::uuid(), 'If-Match' => (string) $version];
    }
}

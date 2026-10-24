<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use App\Modules\Sales\Application\UnsoldSalesReturnService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class UnsoldReturnEvidenceEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const TRAINING_PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const FINANCE_PLANT_ID = '00000000-0000-4000-8000-000000000102';
    private const SALES_USER_ID = '00000000-0000-4000-8000-000000000201';
    private const FINANCE_USER_ID = '00000000-0000-4000-8000-000000000203';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed();
        Storage::fake('evidence');
        config([
            'qtfoods.evidence_disk' => 'evidence',
            'qtfoods.evidence_retention_years' => 7,
            'qtfoods.evidence_retention_policy' => 'UNSOLD_RETURN_7Y',
            'qtfoods.evidence_max_upload_kilobytes' => 10240,
        ]);
        $this->travelTo('2026-09-08 09:30:00');
        $this->signIn(self::SALES_USER_ID, self::TRAINING_PLANT_ID);
    }

    public function test_evidence_upload_is_private_versioned_retained_audited_and_downloadable(): void
    {
        $caseId = $this->createCase(self::TRAINING_PLANT_ID);
        $content = "%PDF-1.7\n".str_repeat('return receipt evidence ', 80);
        $idempotencyKey = (string) Str::uuid();
        $headers = $this->headers(1, $idempotencyKey);
        $payload = [
            'category' => 'RETURN_CONFIRMATION',
            'notes' => 'Distributor signed collection note.',
            'file' => UploadedFile::fake()->createWithContent('signed return receipt.pdf', $content),
        ];

        $first = $this->withHeaders($headers)
            ->post("/api/v1/sales/unsold-returns/{$caseId}/evidence", $payload);

        $first
            ->assertCreated()
            ->assertJsonPath('data.return_case_id', $caseId)
            ->assertJsonPath('data.category', 'RETURN_CONFIRMATION')
            ->assertJsonPath('data.original_name', 'signed return receipt.pdf')
            ->assertJsonPath('data.mime_type', 'application/pdf')
            ->assertJsonPath('data.case_record_version', 2)
            ->assertJsonPath('data.record_version', 2)
            ->assertJsonPath('data.retention_policy', 'UNSOLD_RETURN_7Y')
            ->assertJsonPath('data.retention_until', '2033-09-08')
            ->assertJsonPath('data.legal_hold', false)
            ->assertJsonPath('data.sha256', hash('sha256', $content));

        $evidenceId = (string) $first->json('data.evidence_id');
        $auditId = (string) $first->json('data.audit_event_id');
        $storedPath = (string) DB::table('unsold_return_evidence')
            ->where('id', $evidenceId)
            ->value('storage_path');

        Storage::disk('evidence')->assertExists($storedPath);
        $this->assertStringStartsWith(
            "unsold-returns/".self::COMPANY_ID.'/'.self::TRAINING_PLANT_ID."/{$caseId}/",
            $storedPath
        );
        $this->assertDatabaseHas('unsold_return_cases', [
            'id' => $caseId,
            'record_version' => 2,
        ]);
        $this->assertDatabaseHas('idempotency_keys', [
            'namespace' => "sales.unsold-return.evidence.{$caseId}",
            'key' => $idempotencyKey,
            'status' => 'COMPLETED',
        ]);
        $this->assertDatabaseHas('unsold_return_evidence', [
            'id' => $evidenceId,
            'return_case_id' => $caseId,
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::TRAINING_PLANT_ID,
            'case_record_version' => 2,
            'upload_audit_event_id' => $auditId,
            'retention_until' => '2033-09-08',
        ]);
        $this->assertDatabaseHas('audit_events', [
            'id' => $auditId,
            'command' => 'UPLOAD_UNSOLD_RETURN_EVIDENCE',
            'entity_type' => 'unsold_return_evidence',
            'entity_id' => $evidenceId,
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'event_type' => 'sales.unsold_return.evidence_uploaded',
            'aggregate_id' => $caseId,
            'business_key' => $evidenceId,
        ]);

        $detail = $this->getJson("/api/v1/sales/unsold-returns/{$caseId}")
            ->assertOk()
            ->assertJsonCount(1, 'data.evidence')
            ->assertJsonPath('data.evidence.0.id', $evidenceId)
            ->assertJsonPath('data.evidence.0.uploader.name', 'Demo Sales Manager')
            ->assertJsonPath('data.evidence.0.audit_event_id', $auditId)
            ->assertJsonPath('data.evidence.0.retention_until', '2033-09-08');
        $this->assertArrayNotHasKey('storage_disk', $detail->json('data.evidence.0'));
        $this->assertArrayNotHasKey('storage_path', $detail->json('data.evidence.0'));

        $download = $this->withHeader('X-Correlation-ID', (string) Str::uuid())
            ->get("/api/v1/sales/unsold-returns/{$caseId}/evidence/{$evidenceId}");
        $download
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('cache-control', 'max-age=0, no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('attachment;', (string) $download->headers->get('content-disposition'));
        $this->assertSame($content, $download->streamedContent());
        $this->assertDatabaseHas('audit_events', [
            'command' => 'DOWNLOAD_UNSOLD_RETURN_EVIDENCE',
            'entity_id' => $evidenceId,
            'actor_id' => self::SALES_USER_ID,
        ]);
    }

    public function test_exact_upload_replay_returns_the_first_result_without_duplicate_file_or_events(): void
    {
        $caseId = $this->createCase(self::TRAINING_PLANT_ID);
        $content = "%PDF-1.7\n".str_repeat('same evidence ', 100);
        $key = (string) Str::uuid();
        $headers = $this->headers(1, $key);
        $request = fn () => $this->withHeaders($headers)->post(
            "/api/v1/sales/unsold-returns/{$caseId}/evidence",
            [
                'category' => 'QUALITY_REPORT',
                'file' => UploadedFile::fake()->createWithContent('quality-report.pdf', $content),
            ]
        );

        $first = $request()->assertCreated();
        $second = $request()->assertCreated();

        $second->assertExactJson($first->json());
        $this->assertDatabaseCount('unsold_return_evidence', 1);
        $this->assertSame(1, DB::table('audit_events')->where('command', 'UPLOAD_UNSOLD_RETURN_EVIDENCE')->count());
        $this->assertSame(1, DB::table('outbox_events')->where('event_type', 'sales.unsold_return.evidence_uploaded')->count());
        $this->assertCount(1, Storage::disk('evidence')->allFiles());
        $this->assertSame(2, (int) DB::table('unsold_return_cases')->where('id', $caseId)->value('record_version'));
    }

    public function test_upload_rejects_missing_controls_stale_versions_and_unsafe_types_without_orphans(): void
    {
        $caseId = $this->createCase(self::TRAINING_PLANT_ID);

        $this->post("/api/v1/sales/unsold-returns/{$caseId}/evidence", [
            'category' => 'OTHER',
            'file' => $this->pdf('missing-version.pdf'),
        ])
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.if_match.0', 'The If-Match header is required for an evidence upload.');

        $this->withHeaders(['If-Match' => '1', 'Idempotency-Key' => ''])
            ->post("/api/v1/sales/unsold-returns/{$caseId}/evidence", [
                'category' => 'OTHER',
                'file' => $this->pdf('missing-key.pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.fields.idempotency_key.0', 'The Idempotency-Key header is required.');

        $this->withHeaders($this->headers(99))
            ->post("/api/v1/sales/unsold-returns/{$caseId}/evidence", [
                'category' => 'OTHER',
                'file' => $this->pdf('stale.pdf'),
            ])
            ->assertConflict()
            ->assertJsonPath('error.code', 'CONFLICT');

        $this->withHeaders($this->headers(1))
            ->post("/api/v1/sales/unsold-returns/{$caseId}/evidence", [
                'category' => 'OTHER',
                'file' => UploadedFile::fake()->createWithContent(
                    'active-content.html',
                    str_repeat('<script>alert(1)</script>', 80)
                ),
            ])
            ->assertUnprocessable()
            ->assertJsonPath('error.code', 'VALIDATION_FAILED')
            ->assertJsonStructure(['error' => ['fields' => ['file']]]);

        $this->assertDatabaseCount('unsold_return_evidence', 0);
        $this->assertSame([], Storage::disk('evidence')->allFiles());
        $this->assertSame(1, (int) DB::table('unsold_return_cases')->where('id', $caseId)->value('record_version'));
    }

    public function test_download_hides_out_of_scope_evidence_and_enforces_the_dedicated_permission(): void
    {
        $caseId = $this->createCase(self::TRAINING_PLANT_ID);
        $upload = $this->withHeaders($this->headers(1))
            ->post("/api/v1/sales/unsold-returns/{$caseId}/evidence", [
                'category' => 'RECEIPT_PHOTO',
                'file' => $this->pdf('receipt.pdf'),
            ])
            ->assertCreated();
        $evidenceId = (string) $upload->json('data.evidence_id');

        $this->signIn(self::FINANCE_USER_ID, self::FINANCE_PLANT_ID);
        $this->get("/api/v1/sales/unsold-returns/{$caseId}/evidence/{$evidenceId}")
            ->assertNotFound()
            ->assertJsonPath('error.code', 'NOT_FOUND');

        $permissionId = DB::table('permissions')
            ->where('code', 'ACTION:RET-UNSOLD:EVIDENCE')
            ->value('id');
        $salesRoleId = DB::table('roles')->where('code', 'SALES_MANAGER')->value('id');
        DB::table('role_permissions')
            ->where('role_id', $salesRoleId)
            ->where('permission_id', $permissionId)
            ->delete();
        $this->signIn(self::SALES_USER_ID, self::TRAINING_PLANT_ID);

        $this->get("/api/v1/sales/unsold-returns/{$caseId}/evidence/{$evidenceId}")
            ->assertForbidden()
            ->assertJsonPath('error.code', 'FORBIDDEN');

        $this->assertSame(
            0,
            DB::table('audit_events')->where('command', 'DOWNLOAD_UNSOLD_RETURN_EVIDENCE')->count()
        );
    }

    private function createCase(string $plantId): string
    {
        $financePlant = $plantId === self::FINANCE_PLANT_ID;
        $result = $this->app->make(UnsoldSalesReturnService::class)->createRequest([
            'company_id' => self::COMPANY_ID,
            'plant_id' => $plantId,
            'party_id' => '00000000-0000-4000-8000-000000000501',
            'shipment_id' => $financePlant
                ? '00000000-0000-4000-8000-000000001003'
                : '00000000-0000-4000-8000-000000001001',
            'reason_code' => 'UNSOLD_MARKET_RETURN',
            'actor_id' => self::SALES_USER_ID,
            'idempotency_key' => (string) Str::uuid(),
            'lines' => [[
                'shipment_line_id' => $financePlant
                    ? '00000000-0000-4000-8000-000000001103'
                    : '00000000-0000-4000-8000-000000001101',
                'sku_id' => '00000000-0000-4000-8000-000000000601',
                'fg_lot_id' => '00000000-0000-4000-8000-000000000701',
                'requested_quantity' => '10',
                'uom_code' => 'PACK',
            ]],
        ]);

        return $result['return_case_id'];
    }

    private function pdf(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent(
            $name,
            "%PDF-1.7\n".str_repeat('test evidence ', 100)
        );
    }

    private function headers(int $version, ?string $idempotencyKey = null): array
    {
        return [
            'If-Match' => (string) $version,
            'Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid(),
            'X-Correlation-ID' => (string) Str::uuid(),
        ];
    }

    private function signIn(string $userId, string $plantId): void
    {
        $this->actingAs(User::query()->findOrFail($userId))->withSession([
            'erp.company_id' => self::COMPANY_ID,
            'erp.plant_id' => $plantId,
        ]);
    }
}

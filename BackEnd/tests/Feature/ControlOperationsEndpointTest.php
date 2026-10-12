<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use App\Shared\Audit\AuditService;
use App\Shared\Observability\OperationalAlertEvaluator;
use App\Shared\Observability\OperationalSnapshot;
use App\Shared\Outbox\OutboxProcessor;
use App\Shared\Outbox\OutboxService;
use App\Shared\Outbox\OutboxTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class ControlOperationsEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const TRAINING_PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const FINANCE_PLANT_ID = '00000000-0000-4000-8000-000000000102';
    private const ADMIN_USER_ID = '00000000-0000-4000-8000-000000000204';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        Storage::fake('evidence');
        config([
            'qtfoods.evidence_disk' => 'evidence',
            'qtfoods.outbox.max_attempts' => 2,
            'qtfoods.outbox.base_retry_seconds' => 10,
            'qtfoods.outbox.max_retry_seconds' => 60,
            'qtfoods.outbox.transport' => 'log',
        ]);
        $this->travelTo('2026-09-09 10:00:00');
        $this->signIn(self::TRAINING_PLANT_ID);
    }

    public function test_audit_search_detail_and_private_evidence_viewer_are_live_and_scoped(): void
    {
        $auditId = $this->audit('UPLOAD_UNSOLD_RETURN_EVIDENCE', self::TRAINING_PLANT_ID, [
            'field' => ['from' => null, 'to' => 'receipt.pdf'],
        ]);
        $otherAuditId = $this->audit('UPDATE_PLANT', self::FINANCE_PLANT_ID, ['name' => 'Hidden']);
        $evidenceId = (string) Str::uuid();
        $caseId = (string) Str::uuid();
        $contents = "%PDF-1.7\nretained audit evidence";
        $path = "unsold-returns/".self::COMPANY_ID.'/'.self::TRAINING_PLANT_ID."/{$caseId}/{$evidenceId}.pdf";
        Storage::disk('evidence')->put($path, $contents);
        DB::table('unsold_return_evidence')->insert([
            'id' => $evidenceId,
            'return_case_id' => $caseId,
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::TRAINING_PLANT_ID,
            'category' => 'RETURN_CONFIRMATION',
            'case_record_version' => 2,
            'original_name' => 'receipt.pdf',
            'storage_disk' => 'evidence',
            'storage_path' => $path,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($contents),
            'sha256' => hash('sha256', $contents),
            'notes' => 'Signed receipt.',
            'retention_policy' => 'UNSOLD_RETURN_7Y',
            'retention_until' => '2033-09-09',
            'legal_hold' => false,
            'uploaded_by' => self::ADMIN_USER_ID,
            'upload_audit_event_id' => $auditId,
            'idempotency_key' => (string) Str::uuid(),
            'uploaded_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->getJson('/api/v1/admin/audit?outcome=SUCCESS&q=evidence&sort=OLDEST')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $auditId)
            ->assertJsonPath('data.0.actor.name', 'Demo ERP Administrator')
            ->assertJsonPath('data.0.evidence_count', 1)
            ->assertJsonPath('summary.evidence', 1)
            ->assertJsonPath('allowed_actions.0', 'VIEW_EVIDENCE');

        $detail = $this->getJson("/api/v1/admin/audit/{$auditId}")
            ->assertOk()
            ->assertJsonPath('data.safe_diff.field.to', 'receipt.pdf')
            ->assertJsonPath('data.evidence.0.id', $evidenceId)
            ->assertJsonPath('data.evidence.0.sha256', hash('sha256', $contents));
        $this->assertArrayNotHasKey('storage_path', $detail->json('data.evidence.0'));
        $this->assertArrayNotHasKey('storage_disk', $detail->json('data.evidence.0'));

        $response = $this->withHeader('X-Correlation-ID', (string) Str::uuid())
            ->get("/api/v1/admin/audit/{$auditId}/evidence/{$evidenceId}");
        $response->assertOk()
            ->assertHeader('content-type', 'application/pdf')
            ->assertHeader('cache-control', 'max-age=0, no-store, private')
            ->assertHeader('x-content-type-options', 'nosniff');
        $this->assertStringContainsString('inline;', (string) $response->headers->get('content-disposition'));
        $this->assertSame($contents, $response->streamedContent());
        $this->assertDatabaseHas('audit_events', [
            'command' => 'VIEW_AUDIT_EVIDENCE',
            'entity_id' => $evidenceId,
            'actor_id' => self::ADMIN_USER_ID,
        ]);

        $this->getJson("/api/v1/admin/audit/{$otherAuditId}")->assertNotFound();
    }

    public function test_audit_evidence_requires_its_action_permission(): void
    {
        $auditId = $this->audit('UPLOAD_UNSOLD_RETURN_EVIDENCE', self::TRAINING_PLANT_ID, []);
        $permission = DB::table('permissions')->where('code', 'ACTION:ADM-AUD:EVIDENCE')->value('id');
        $role = DB::table('roles')->where('code', 'ERP_ADMIN')->value('id');
        DB::table('role_permissions')->where('role_id', $role)->where('permission_id', $permission)->delete();
        $this->signIn(self::TRAINING_PLANT_ID);

        $this->get("/api/v1/admin/audit/{$auditId}/evidence/".(string) Str::uuid())
            ->assertForbidden()->assertJsonPath('error.code', 'FORBIDDEN');
    }

    public function test_outbox_worker_records_acknowledgements_retries_and_automatic_quarantine(): void
    {
        $successId = $this->outbox('sales.return.created', 'success');
        $this->app->instance(OutboxTransport::class, new class implements OutboxTransport {
            public function name(): string { return 'test'; }
            public function deliveryEnabled(): bool { return true; }
            public function deliver(array $event): array
            {
                return ['acknowledgement_id' => 'ack-'.$event['id'], 'response' => ['accepted' => true]];
            }
        });
        $result = $this->app->make(OutboxProcessor::class)->process(10, 'test-worker', [
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::TRAINING_PLANT_ID,
        ]);
        $this->assertSame(1, $result['delivered']);
        $this->assertDatabaseHas('outbox_events', [
            'id' => $successId,
            'status' => 'DELIVERED',
            'attempts' => 1,
            'acknowledgement_id' => 'ack-'.$successId,
        ]);
        $this->assertDatabaseHas('outbox_delivery_attempts', [
            'outbox_event_id' => $successId,
            'attempt_number' => 1,
            'outcome' => 'DELIVERED',
        ]);

        $failureId = $this->outbox('sales.return.failed', 'failure');
        $this->app->instance(OutboxTransport::class, new class implements OutboxTransport {
            public function name(): string { return 'test'; }
            public function deliveryEnabled(): bool { return true; }
            public function deliver(array $event): array { throw new RuntimeException('Receiver unavailable.'); }
        });
        $processor = $this->app->make(OutboxProcessor::class);
        $processor->process(1, 'failure-worker', [
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::TRAINING_PLANT_ID,
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'id' => $failureId,
            'status' => 'RETRY',
            'attempts' => 1,
            'last_error_code' => 'RuntimeException',
        ]);

        $this->travel(11)->seconds();
        $processor->process(1, 'failure-worker', [
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::TRAINING_PLANT_ID,
        ]);
        $this->assertDatabaseHas('outbox_events', [
            'id' => $failureId,
            'status' => 'QUARANTINED',
            'attempts' => 2,
            'quarantine_reason' => 'Automatic quarantine after exhausting the configured retry policy.',
        ]);
        $this->assertSame(2, DB::table('outbox_delivery_attempts')->where('outbox_event_id', $failureId)->count());
    }

    public function test_standalone_outbox_retains_events_and_audit_without_delivery_attempts(): void
    {
        $auditId = $this->audit('STANDALONE_RETENTION_TEST', self::TRAINING_PLANT_ID, [
            'delivery' => ['from' => null, 'to' => 'retained'],
        ]);
        $eventId = $this->outbox('sales.return.retained', 'standalone-retention');
        config()->set('qtfoods.outbox.transport', 'standalone');
        $this->app->forgetInstance(OutboxTransport::class);

        $result = $this->app->make(OutboxProcessor::class)->process(10, 'standalone-worker', [
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::TRAINING_PLANT_ID,
        ]);

        self::assertFalse($result['delivery_enabled']);
        self::assertSame('standalone', $result['transport']);
        self::assertSame(0, $result['claimed']);
        self::assertSame(0, $result['delivered']);
        $this->assertDatabaseHas('outbox_events', [
            'id' => $eventId,
            'status' => 'PENDING',
            'attempts' => 0,
            'delivered_at' => null,
            'acknowledged_at' => null,
            'acknowledgement_id' => null,
        ]);
        $this->assertDatabaseHas('audit_events', ['id' => $auditId, 'command' => 'STANDALONE_RETENTION_TEST']);
        self::assertSame(0, DB::table('outbox_delivery_attempts')->where('outbox_event_id', $eventId)->count());

        $snapshot = $this->app->make(OperationalSnapshot::class)->capture();
        self::assertFalse($snapshot['outbox']['delivery_enabled']);
        self::assertSame('standalone', $snapshot['outbox']['transport']);
        self::assertNull($snapshot['outbox']['oldest_due_age_seconds']);
        self::assertNotContains(
            'outbox.oldest_due',
            collect($this->app->make(OperationalAlertEvaluator::class)->evaluate($snapshot))->pluck('code')->all(),
        );

        $this->getJson('/api/v1/admin/integrations')
            ->assertOk()
            ->assertJsonPath('runtime.transport', 'standalone')
            ->assertJsonPath('runtime.delivery_enabled', false)
            ->assertJsonPath('runtime.oldest_due_at', null)
            ->assertJsonCount(0, 'allowed_actions');
    }

    public function test_operator_workspace_is_scoped_and_retry_quarantine_commands_are_versioned_and_idempotent(): void
    {
        $retryId = $this->outbox('sales.return.retry', 'retry');
        DB::table('outbox_events')->where('id', $retryId)->update([
            'status' => 'QUARANTINED',
            'attempts' => 2,
            'quarantined_at' => now(),
            'quarantine_reason' => 'Automatic quarantine.',
            'record_version' => 3,
        ]);
        $hiddenId = $this->outbox('sales.return.hidden', 'hidden', self::FINANCE_PLANT_ID);

        $this->getJson('/api/v1/admin/integrations?status=QUARANTINED&q=retry')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $retryId)
            ->assertJsonPath('data.0.allowed_actions.0', 'RETRY')
            ->assertJsonPath('runtime.evidence_driver', 's3');
        $this->getJson("/api/v1/admin/outbox-events/{$hiddenId}")->assertNotFound();

        $key = (string) Str::uuid();
        $headers = ['If-Match' => '3', 'Idempotency-Key' => $key];
        $first = $this->withHeaders($headers)->postJson("/api/v1/admin/outbox-events/{$retryId}/retry", [])
            ->assertOk()->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.record_version', 4);
        $this->withHeaders($headers)->postJson("/api/v1/admin/outbox-events/{$retryId}/retry", [])
            ->assertOk()->assertExactJson($first->json());
        $this->assertDatabaseHas('outbox_events', ['id' => $retryId, 'status' => 'PENDING', 'attempts' => 2]);
        $this->assertSame(1, DB::table('audit_events')->where('command', 'RETRY_OUTBOX_EVENT')->count());

        $pendingId = $this->outbox('sales.return.pending', 'pending');
        $this->withHeaders(['If-Match' => '1', 'Idempotency-Key' => (string) Str::uuid()])
            ->postJson("/api/v1/admin/outbox-events/{$pendingId}/quarantine", ['reason' => 'Receiver contract retired.'])
            ->assertOk()->assertJsonPath('data.status', 'QUARANTINED');
        $this->assertDatabaseHas('outbox_events', [
            'id' => $pendingId,
            'status' => 'QUARANTINED',
            'quarantined_by' => self::ADMIN_USER_ID,
            'quarantine_reason' => 'Receiver contract retired.',
        ]);
        $this->assertDatabaseHas('audit_events', ['command' => 'QUARANTINE_OUTBOX_EVENT', 'entity_id' => $pendingId]);
    }

    public function test_operator_process_command_is_scoped_and_idempotent(): void
    {
        $visibleId = $this->outbox('sales.visible', 'visible');
        $hiddenId = $this->outbox('sales.hidden', 'hidden', self::FINANCE_PLANT_ID);
        $this->app->instance(OutboxTransport::class, new class implements OutboxTransport {
            public function name(): string { return 'test'; }
            public function deliveryEnabled(): bool { return true; }
            public function deliver(array $event): array
            {
                return ['acknowledgement_id' => 'operator-'.$event['id'], 'response' => []];
            }
        });

        $key = (string) Str::uuid();
        $first = $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/admin/integrations/process-due', ['limit' => 10])
            ->assertOk()->assertJsonPath('data.delivered', 1);
        $this->withHeader('Idempotency-Key', $key)
            ->postJson('/api/v1/admin/integrations/process-due', ['limit' => 10])
            ->assertOk()->assertExactJson($first->json());
        $this->assertDatabaseHas('outbox_events', ['id' => $visibleId, 'status' => 'DELIVERED']);
        $this->assertDatabaseHas('outbox_events', ['id' => $hiddenId, 'status' => 'PENDING']);
        $this->assertSame(1, DB::table('audit_events')->where('command', 'PROCESS_OUTBOX_DUE')->count());
    }

    private function audit(string $command, string $plantId, array $diff): string
    {
        return $this->app->make(AuditService::class)->record(
            $command,
            'unsold_return_evidence',
            (string) Str::uuid(),
            self::ADMIN_USER_ID,
            self::COMPANY_ID,
            $plantId,
            'SUCCESS',
            ['entity_version' => 1, 'safe_diff' => $diff, 'correlation_id' => (string) Str::uuid()]
        );
    }

    private function outbox(string $eventType, string $key, string $plantId = self::TRAINING_PLANT_ID): string
    {
        return $this->app->make(OutboxService::class)->append(
            $eventType,
            'unsold_return_case',
            (string) Str::uuid(),
            $key,
            ['safe' => true],
            (string) Str::uuid(),
            self::COMPANY_ID,
            $plantId,
        );
    }

    private function signIn(string $plantId): void
    {
        $this->actingAs(User::query()->findOrFail(self::ADMIN_USER_ID))->withSession([
            'erp.company_id' => self::COMPANY_ID,
            'erp.plant_id' => $plantId,
        ]);
    }
}

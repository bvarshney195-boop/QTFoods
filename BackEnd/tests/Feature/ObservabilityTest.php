<?php

namespace Tests\Feature;

use App\Modules\Foundation\Domain\User;
use App\Shared\Observability\OperationalMonitor;
use App\Shared\Outbox\OutboxProcessor;
use App\Shared\Outbox\OutboxTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class ObservabilityTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY = '00000000-0000-4000-8000-000000000001';
    private const PLANT = '00000000-0000-4000-8000-000000000101';
    private const ADMIN = '00000000-0000-4000-8000-000000000204';

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'observability.readiness.database' => true,
            'observability.readiness.redis' => false,
            'observability.readiness.object_storage' => false,
            'observability.metrics.token' => null,
            'observability.alerts.transport' => 'log',
        ]);
    }

    public function test_request_correlation_and_w3c_trace_context_are_safe_and_returned(): void
    {
        $requestId = (string) Str::uuid();
        $correlationId = (string) Str::uuid();
        $traceId = '4bf92f3577b34da6a3ce929d0e0e4736';
        $parentSpanId = '00f067aa0ba902b7';

        $response = $this->withHeaders([
            'X-Request-ID' => $requestId,
            'X-Correlation-ID' => $correlationId,
            'traceparent' => "00-{$traceId}-{$parentSpanId}-01",
        ])->getJson('/api/health')->assertOk();

        $response->assertHeader('X-Request-ID', $requestId)
            ->assertHeader('X-Correlation-ID', $correlationId);
        $returnedTrace = (string) $response->headers->get('traceparent');
        self::assertMatchesRegularExpression('/^00-'.$traceId.'-[0-9a-f]{16}-01$/', $returnedTrace);
        self::assertStringNotContainsString($parentSpanId, $returnedTrace);

        $replaced = $this->withHeaders([
            'X-Request-ID' => 'not-a-uuid-unsafe',
            'X-Correlation-ID' => 'also-invalid',
            'traceparent' => 'invalid',
        ])->getJson('/api/health')->assertOk();
        self::assertTrue(Str::isUuid((string) $replaced->headers->get('X-Request-ID')));
        self::assertSame(
            $replaced->headers->get('X-Request-ID'),
            $replaced->headers->get('X-Correlation-ID'),
        );
        self::assertMatchesRegularExpression(
            '/^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/',
            (string) $replaced->headers->get('traceparent'),
        );
    }

    public function test_generated_context_is_persisted_on_audit_and_outbox_records(): void
    {
        $this->seed();
        $this->actingAs(User::query()->findOrFail(self::ADMIN))->withSession([
            'erp.company_id' => self::COMPANY,
            'erp.plant_id' => self::PLANT,
        ]);
        $requestId = (string) Str::uuid();
        $correlationId = (string) Str::uuid();
        $traceId = '0af7651916cd43dd8448eb211c80319c';

        $created = $this->withHeaders([
            'Idempotency-Key' => (string) Str::uuid(),
            'X-Request-ID' => $requestId,
            'X-Correlation-ID' => $correlationId,
            'traceparent' => "00-{$traceId}-b7ad6b7169203331-01",
        ])->postJson('/api/v1/admin/companies', [
            'code' => 'OBS',
            'legal_name' => 'Observability Foods Private Limited',
            'display_name' => 'Observability Foods',
            'plant_code' => 'TRACE',
            'plant_name' => 'Trace Plant',
            'timezone' => 'Asia/Kolkata',
        ])->assertCreated();
        $companyId = (string) $created->json('data.company_id');

        $audit = DB::table('audit_events')->where('command', 'CREATE_COMPANY')
            ->where('entity_id', $companyId)->first();
        $outbox = DB::table('outbox_events')->where('event_type', 'foundation.company.created')
            ->where('aggregate_id', $companyId)->first();
        self::assertNotNull($audit);
        self::assertNotNull($outbox);
        self::assertSame($requestId, $audit->request_id);
        self::assertSame($correlationId, $audit->correlation_id);
        self::assertSame($traceId, $audit->trace_id);
        self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', (string) $audit->span_id);
        self::assertSame($requestId, $outbox->request_id);
        self::assertSame($correlationId, $outbox->correlation_id);
        self::assertSame($traceId, $outbox->trace_id);
        self::assertSame($audit->span_id, $outbox->span_id);

        $transport = new class implements OutboxTransport {
            public array $events = [];
            public function name(): string { return 'capture'; }
            public function deliveryEnabled(): bool { return true; }
            public function deliver(array $event): array
            {
                $this->events[] = $event;

                return ['acknowledgement_id' => 'capture:'.$event['id'], 'response' => []];
            }
        };
        $this->app->instance(OutboxTransport::class, $transport);
        app(OutboxProcessor::class)->process(1, 'observability-test-worker');
        self::assertCount(1, $transport->events);
        self::assertSame($traceId, $transport->events[0]['trace_id']);
        self::assertSame($outbox->span_id, $transport->events[0]['parent_span_id']);
        self::assertNotSame($outbox->span_id, $transport->events[0]['span_id']);
        self::assertMatchesRegularExpression(
            '/^00-'.$traceId.'-[0-9a-f]{16}-01$/',
            $transport->events[0]['traceparent'],
        );
    }

    public function test_readiness_checks_dependencies_and_returns_service_unavailable_on_failure(): void
    {
        Storage::fake('evidence');
        config([
            'qtfoods.evidence_disk' => 'evidence',
            'observability.readiness.object_storage' => true,
        ]);
        $this->getJson('/api/ready')->assertOk()
            ->assertJsonPath('status', 'ready')
            ->assertJsonPath('checks.database.status', 'up')
            ->assertJsonPath('checks.object_storage.status', 'up');

        config([
            'database.default' => 'broken',
            'database.connections.broken' => [
                'driver' => 'sqlite',
                'database' => '/definitely/missing/qtfoods/observability.sqlite',
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
            'observability.readiness.object_storage' => false,
        ]);
        DB::purge('broken');
        $failure = $this->getJson('/api/ready');
        config(['database.default' => 'sqlite']);
        DB::purge('broken');
        $failure->assertStatus(503)
            ->assertJsonPath('status', 'unavailable')
            ->assertJsonPath('checks.database.status', 'down');
    }

    public function test_metrics_require_a_bearer_token_and_export_low_cardinality_prometheus_data(): void
    {
        $token = 'observability-test-token-32-characters-long';
        config(['observability.metrics.token' => $token]);

        $this->getJson('/api/v1/contexts')->assertUnauthorized();
        $this->getJson('/api/metrics')->assertUnauthorized()
            ->assertJsonPath('error.code', 'METRICS_UNAUTHENTICATED');

        $metrics = $this->withToken($token)->get('/api/metrics')->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private');
        $body = $metrics->getContent();
        self::assertStringContainsString('qtfoods_build_info{service="qt-foods-erp-crm"', $body);
        self::assertStringContainsString('qtfoods_dependency_up{name="database"} 1', $body);
        self::assertStringContainsString('qtfoods_http_requests_total{method="GET"} 1', $body);
        self::assertStringContainsString('qtfoods_http_responses_total{status_class="4xx"} 1', $body);
        self::assertStringContainsString('qtfoods_outbox_events{status="PENDING"} 0', $body);
        self::assertStringNotContainsString($token, $body);
    }

    public function test_operational_monitor_alerts_deduplicates_and_reports_recovery(): void
    {
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'synthetic test failure',
            'failed_at' => now(),
        ]);

        $first = app(OperationalMonitor::class)->check();
        self::assertSame('critical', $first['status']);
        self::assertTrue($first['notification_sent']);
        self::assertContains('queue.failed_jobs', collect($first['alerts'])->pluck('code')->all());

        $second = app(OperationalMonitor::class)->check();
        self::assertFalse($second['notification_sent']);
        $this->artisan('qt:observability:check')->assertExitCode(2);

        DB::table('failed_jobs')->delete();
        $recovered = app(OperationalMonitor::class)->check();
        self::assertSame('ok', $recovered['status']);
        self::assertTrue($recovered['notification_sent']);
        self::assertSame([], $recovered['alerts']);
    }

    public function test_signed_http_alert_delivery_retries_after_receiver_failure(): void
    {
        $endpoint = 'https://alerts.secure.test/qtfoods';
        $secret = 'alert-signing-secret-with-32-characters';
        config([
            'observability.alerts.transport' => 'http',
            'observability.alerts.http_endpoint' => $endpoint,
            'observability.alerts.signing_secret' => $secret,
        ]);
        Http::fake([
            $endpoint => Http::sequence()->push([], 500)->push([], 204),
        ]);
        DB::table('failed_jobs')->insert([
            'uuid' => (string) Str::uuid(),
            'connection' => 'redis',
            'queue' => 'default',
            'payload' => '{}',
            'exception' => 'synthetic test failure',
            'failed_at' => now(),
        ]);

        $failed = app(OperationalMonitor::class)->check();
        self::assertFalse($failed['notification_sent']);
        self::assertSame('RuntimeException', $failed['notification_error']);
        $delivered = app(OperationalMonitor::class)->check();
        self::assertTrue($delivered['notification_sent']);
        self::assertNull($delivered['notification_error']);
        $deduplicated = app(OperationalMonitor::class)->check();
        self::assertFalse($deduplicated['notification_sent']);
        Http::assertSentCount(2);
        Http::assertSent(static function ($request) use ($secret): bool {
            $expected = 'sha256='.hash_hmac('sha256', $request->body(), $secret);

            return hash_equals($expected, (string) $request->header('X-QT-Alert-Signature')[0]);
        });
    }
}

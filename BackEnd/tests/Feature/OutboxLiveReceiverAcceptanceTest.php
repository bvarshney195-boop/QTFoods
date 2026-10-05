<?php

namespace Tests\Feature;

use App\Modules\Control\Application\OutboxOperationsService;
use App\Shared\Observability\OperationalSnapshot;
use App\Shared\Outbox\OutboxProcessor;
use App\Shared\Outbox\OutboxReadinessVerifier;
use App\Shared\Outbox\OutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

final class OutboxLiveReceiverAcceptanceTest extends TestCase
{
    use RefreshDatabase;

    private const COMPANY_ID = '00000000-0000-4000-8000-000000000001';
    private const PLANT_ID = '00000000-0000-4000-8000-000000000101';
    private const ADMIN_ID = '00000000-0000-4000-8000-000000000204';
    private const SECRET = 'LiveReceiverAcceptanceSecret-2026-10-05';

    private mixed $receiver = null;
    private mixed $receiverLog = null;
    private string $temporaryDirectory;
    private string $receiverState;
    private int $receiverPort;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
        $this->temporaryDirectory = sys_get_temp_dir().'/qtfoods-outbox-'.Str::lower(Str::random(12));
        if (! mkdir($this->temporaryDirectory, 0700, true) && ! is_dir($this->temporaryDirectory)) {
            throw new RuntimeException('Unable to create the live receiver test directory.');
        }
        $this->receiverState = $this->temporaryDirectory.'/receiver-state.json';
        $this->receiverPort = $this->availablePort();
        config()->set([
            'qtfoods.outbox.transport' => 'http',
            'qtfoods.outbox.http_endpoint' => "http://127.0.0.1:{$this->receiverPort}/events",
            'qtfoods.outbox.signing_secret' => self::SECRET,
            'qtfoods.outbox.require_acknowledgement' => true,
            'qtfoods.outbox.require_bound_acknowledgement' => true,
            'qtfoods.outbox.max_attempts' => 2,
            'qtfoods.outbox.base_retry_seconds' => 1,
            'qtfoods.outbox.max_retry_seconds' => 1,
            'qtfoods.evidence_disk' => 'release_acceptance',
            'filesystems.disks.release_acceptance' => [
                'driver' => 'local',
                'root' => $this->temporaryDirectory.'/evidence',
                'throw' => true,
            ],
            'observability.readiness.database' => true,
            'observability.readiness.redis' => false,
            'observability.readiness.object_storage' => false,
            'observability.monitoring.thresholds.outbox_oldest_due_seconds' => 300,
        ]);
        $this->app->forgetInstance(\App\Shared\Outbox\OutboxTransport::class);
        $this->app->forgetInstance(OutboxProcessor::class);
        $this->startReceiver();
    }

    protected function tearDown(): void
    {
        $this->stopReceiver();
        if (isset($this->temporaryDirectory) && is_dir($this->temporaryDirectory)) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->temporaryDirectory, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($files as $file) {
                $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            }
            rmdir($this->temporaryDirectory);
        }
        parent::tearDown();
    }

    public function test_real_receiver_failure_lifecycle_idempotent_replay_monitoring_and_restart_survival(): void
    {
        $eventId = app(OutboxService::class)->append(
            'release.readiness.live_receiver',
            'release_probe',
            (string) Str::uuid(),
            'live-receiver-'.Str::lower(Str::random(12)),
            ['failures_before_accept' => 2, 'purpose' => 'OPS-01 acceptance'],
            (string) Str::uuid(),
            self::COMPANY_ID,
            self::PLANT_ID,
        );
        DB::table('outbox_events')->where('id', $eventId)->update([
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ]);

        $evidencePath = 'release-readiness/'.$eventId.'.json';
        $evidence = json_encode(['event_id' => $eventId, 'created_before_restart' => true], JSON_THROW_ON_ERROR);
        Storage::disk('release_acceptance')->put($evidencePath, $evidence);
        $evidenceSha256 = hash('sha256', $evidence);

        $snapshot = app(OperationalSnapshot::class)->capture();
        self::assertGreaterThanOrEqual(
            599,
            $snapshot['outbox']['oldest_due_age_seconds'],
            json_encode($snapshot, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );

        $processor = app(OutboxProcessor::class);
        $first = $processor->process(1, 'worker-before-restart', $this->scope());
        self::assertSame(1, $first['retry_scheduled']);
        $this->assertDatabaseHas('outbox_events', ['id' => $eventId, 'status' => 'RETRY', 'attempts' => 1]);

        DB::table('outbox_events')->where('id', $eventId)->update(['next_retry_at' => now()->subSecond()]);
        $second = $processor->process(1, 'worker-before-restart', $this->scope());
        self::assertSame(1, $second['quarantined']);
        $this->assertDatabaseHas('outbox_events', ['id' => $eventId, 'status' => 'QUARANTINED', 'attempts' => 2]);

        $version = (int) DB::table('outbox_events')->where('id', $eventId)->value('record_version');
        $retryKey = (string) Str::uuid();
        $retryData = [
            'actor_id' => self::ADMIN_ID,
            'company_id' => self::COMPANY_ID,
            'plant_id' => self::PLANT_ID,
            'expected_version' => $version,
            'idempotency_key' => $retryKey,
            'correlation_id' => (string) Str::uuid(),
        ];
        $retried = app(OutboxOperationsService::class)->retry($eventId, $retryData);
        self::assertSame('PENDING', $retried['status']);
        self::assertEquals($retried, app(OutboxOperationsService::class)->retry($eventId, $retryData));

        $this->stopReceiver();
        Storage::forgetDisk('release_acceptance');
        $this->startReceiver();
        self::assertTrue(Storage::disk('release_acceptance')->exists($evidencePath));
        self::assertSame($evidenceSha256, hash('sha256', Storage::disk('release_acceptance')->get($evidencePath)));

        $third = app(OutboxProcessor::class)->process(1, 'worker-after-restart', $this->scope());
        self::assertSame(1, $third['delivered']);
        $acknowledgement = (string) DB::table('outbox_events')->where('id', $eventId)->value('acknowledgement_id');
        self::assertSame('external-ack-'.$eventId, $acknowledgement);

        // Simulate acknowledgement persistence loss. Re-delivery must use the
        // same event/idempotency key and must not repeat the receiver side effect.
        DB::table('outbox_events')->where('id', $eventId)->update([
            'status' => 'PENDING',
            'delivered_at' => null,
            'acknowledged_at' => null,
            'acknowledgement_id' => null,
            'transport_response_json' => null,
            'record_version' => DB::raw('record_version + 1'),
            'updated_at' => now(),
        ]);
        $fourth = app(OutboxProcessor::class)->process(1, 'worker-after-second-restart', $this->scope());
        self::assertSame(1, $fourth['delivered']);

        $state = json_decode((string) file_get_contents($this->receiverState), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(4, $state['events'][$eventId]['attempts']);
        self::assertSame(1, $state['events'][$eventId]['side_effect_count']);
        self::assertSame(1, $state['events'][$eventId]['replay_count']);

        $verification = app(OutboxReadinessVerifier::class)->verify($eventId, [
            'failure_lifecycle' => true,
            'idempotent_replay' => true,
            'worker_restart' => true,
            'evidence_path' => $evidencePath,
            'evidence_sha256' => $evidenceSha256,
        ]);
        self::assertSame('pass', $verification['status'], json_encode($verification['issues']));
        self::assertSame(['RETRY', 'QUARANTINED', 'DELIVERED', 'DELIVERED'], $verification['evidence']['outcomes']);
        self::assertSame(3, $verification['evidence']['worker_instances']);

        $this->artisan('qt:release:verify-outbox', [
            'eventId' => $eventId,
            '--require-failure-lifecycle' => true,
            '--require-idempotent-replay' => true,
            '--require-worker-restart' => true,
            '--evidence-path' => $evidencePath,
            '--evidence-sha256' => $evidenceSha256,
        ])->assertExitCode(0);
    }

    public function test_receiver_cannot_acknowledge_a_different_event_identifier(): void
    {
        $eventId = app(OutboxService::class)->append(
            'release.readiness.wrong_ack',
            'release_probe',
            (string) Str::uuid(),
            'wrong-ack-'.Str::lower(Str::random(12)),
            ['acknowledged_event_id_override' => (string) Str::uuid()],
            (string) Str::uuid(),
            self::COMPANY_ID,
            self::PLANT_ID,
        );

        $result = app(OutboxProcessor::class)->process(1, 'bound-ack-worker', $this->scope());

        self::assertSame(0, $result['delivered']);
        self::assertSame(1, $result['retry_scheduled']);
        $event = DB::table('outbox_events')->where('id', $eventId)->first();
        self::assertSame('RETRY', $event->status);
        self::assertNull($event->acknowledgement_id);
        self::assertStringContainsString('not bound to the delivered event identifier', (string) $event->last_error_message);
    }

    private function scope(): array
    {
        return ['company_id' => self::COMPANY_ID, 'plant_id' => self::PLANT_ID];
    }

    private function availablePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        if ($socket === false) {
            throw new RuntimeException("Unable to reserve receiver port: {$errorCode} {$errorMessage}");
        }
        $address = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr(strrchr($address, ':'), 1);
    }

    private function startReceiver(): void
    {
        $logPath = $this->temporaryDirectory.'/receiver.log';
        $this->receiverLog = fopen($logPath, 'ab');
        $environment = array_merge(getenv(), [
            'QT_TEST_RECEIVER_STATE' => $this->receiverState,
            'QT_TEST_RECEIVER_SECRET' => self::SECRET,
        ]);
        $this->receiver = proc_open([
            PHP_BINARY,
            '-S',
            "127.0.0.1:{$this->receiverPort}",
            base_path('tests/Fixtures/outbox_receiver.php'),
        ], [
            0 => ['pipe', 'r'],
            1 => $this->receiverLog,
            2 => $this->receiverLog,
        ], $pipes, base_path(), $environment);
        if (! is_resource($this->receiver)) {
            throw new RuntimeException('Unable to start the live outbox receiver.');
        }
        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }
        for ($attempt = 0; $attempt < 100; $attempt++) {
            if (@file_get_contents("http://127.0.0.1:{$this->receiverPort}/health") !== false) {
                return;
            }
            usleep(20_000);
        }

        throw new RuntimeException('The live outbox receiver did not become ready.');
    }

    private function stopReceiver(): void
    {
        if (is_resource($this->receiver)) {
            proc_terminate($this->receiver);
            proc_close($this->receiver);
        }
        $this->receiver = null;
        if (is_resource($this->receiverLog)) {
            fclose($this->receiverLog);
        }
        $this->receiverLog = null;
    }
}

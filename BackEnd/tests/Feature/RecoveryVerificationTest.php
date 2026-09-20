<?php

namespace Tests\Feature;

use App\Shared\Recovery\RecoveryVerifier;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RecoveryVerificationTest extends TestCase
{
    use RefreshDatabase;

    private const STORAGE_DISK = 'recovery_verification_test';

    private ?string $storageRoot = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storageRoot = storage_path('framework/testing/recovery-verification-'.Str::uuid());
        config(['filesystems.disks.'.self::STORAGE_DISK => [
            'driver' => 'local',
            'root' => $this->storageRoot,
            'throw' => true,
        ]]);
        Schema::create('recovery_test_objects', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('storage_disk');
            $table->string('storage_path');
            $table->char('checksum', 64);
            $table->unsignedBigInteger('size_bytes');
        });
        config(['recovery.object_sources' => [[
            'table' => 'recovery_test_objects',
            'disk_column' => 'storage_disk',
            'path_column' => 'storage_path',
            'checksum_column' => 'checksum',
            'size_column' => 'size_bytes',
        ]]]);
    }

    protected function tearDown(): void
    {
        if ($this->storageRoot !== null) {
            File::deleteDirectory($this->storageRoot);
        }

        parent::tearDown();
    }

    public function test_recovery_verifier_checks_every_database_backed_object_and_detects_corruption(): void
    {
        $first = $this->storeObject('first-object');
        $this->storeObject('second-object');

        $healthy = app(RecoveryVerifier::class)->verify(0);
        self::assertSame('ok', $healthy['status']);
        self::assertTrue($healthy['object_storage']['complete']);
        self::assertGreaterThan(0, $healthy['object_storage']['total']);
        self::assertSame($healthy['object_storage']['total'], $healthy['object_storage']['verified']);
        self::assertSame([], $healthy['issues']);
        self::assertStringEndsWith('add_bi_analyst_role', (string) $healthy['database']['latest_migration']);

        Storage::disk(self::STORAGE_DISK)->put($first['storage_path'], 'corrupt');
        $corrupt = app(RecoveryVerifier::class)->verify(0);
        self::assertSame('critical', $corrupt['status']);
        self::assertSame(1, $corrupt['object_storage']['checksum_mismatches']);
        self::assertSame(1, $corrupt['object_storage']['size_mismatches']);
        self::assertContains(
            'recovery.objects_checksum_mismatches',
            collect($corrupt['issues'])->pluck('code')->all(),
        );
    }

    public function test_stale_outbox_release_requires_confirmation_and_preserves_delivery_history(): void
    {
        $id = (string) Str::uuid();
        DB::table('outbox_events')->insert([
            'id' => $id,
            'event_type' => 'recovery.test',
            'aggregate_type' => 'recovery_probe',
            'aggregate_id' => (string) Str::uuid(),
            'business_key' => 'recovery-test-'.Str::uuid(),
            'payload_json' => '{}',
            'correlation_id' => null,
            'status' => 'PROCESSING',
            'attempts' => 1,
            'next_retry_at' => null,
            'company_id' => null,
            'plant_id' => null,
            'record_version' => 3,
            'locked_at' => null,
            'locked_by' => 'lost-worker',
            'last_attempt_at' => CarbonImmutable::now()->subHour(),
            'created_at' => CarbonImmutable::now()->subHours(2),
            'updated_at' => CarbonImmutable::now()->subHour(),
        ]);

        $before = app(RecoveryVerifier::class)->verify(0);
        self::assertSame('warning', $before['status']);
        self::assertSame(1, $before['outbox']['stale_processing']);

        $this->artisan('qt:recovery:verify', ['--release-stale-outbox' => true])
            ->expectsOutputToContain('requires --confirmation=RELEASE_STALE_OUTBOX')
            ->assertExitCode(2);
        $this->assertDatabaseHas('outbox_events', [
            'id' => $id,
            'status' => 'PROCESSING',
            'record_version' => 3,
        ]);

        $this->artisan('qt:recovery:verify', [
            '--object-limit' => '0',
            '--release-stale-outbox' => true,
            '--confirmation' => 'RELEASE_STALE_OUTBOX',
        ])->assertExitCode(0);
        $this->assertDatabaseHas('outbox_events', [
            'id' => $id,
            'status' => 'RETRY',
            'record_version' => 4,
            'locked_at' => null,
            'locked_by' => null,
            'last_error_code' => 'RECOVERY_STALE_LOCK',
        ]);
        self::assertSame(0, DB::table('outbox_delivery_attempts')->where('outbox_event_id', $id)->count());
    }

    public function test_object_limit_cannot_report_an_incomplete_restore_as_healthy(): void
    {
        $this->storeObject('limit-one');
        $this->storeObject('limit-two');

        $limited = app(RecoveryVerifier::class)->verify(1);
        self::assertSame('critical', $limited['status']);
        self::assertFalse($limited['object_storage']['complete']);
        self::assertSame(1, $limited['object_storage']['checked']);
        self::assertContains(
            'recovery.object_verification_incomplete',
            collect($limited['issues'])->pluck('code')->all(),
        );
    }

    private function storeObject(string $name): array
    {
        $id = (string) Str::uuid();
        $path = 'recovery/'.$id.'.bin';
        $contents = 'recovery-integrity:'.$name;
        Storage::disk(self::STORAGE_DISK)->put($path, $contents);
        $record = [
            'id' => $id,
            'storage_disk' => self::STORAGE_DISK,
            'storage_path' => $path,
            'checksum' => hash('sha256', $contents),
            'size_bytes' => strlen($contents),
        ];
        DB::table('recovery_test_objects')->insert($record);

        return $record;
    }
}

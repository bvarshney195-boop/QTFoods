<?php

use App\Jobs\ProcessOutboxBatch;
use App\Modules\Foundation\Application\IdentityLifecycleService;
use App\Shared\Deployment\ProductionAdminBootstrapper;
use App\Shared\Deployment\ProductionEnvironmentGuard;
use App\Shared\Deployment\ProductionIdentityVerifier;
use App\Shared\Observability\OperationalMonitor;
use App\Shared\Outbox\OutboxProcessor;
use App\Shared\Outbox\OutboxReadinessVerifier;
use App\Shared\Outbox\OutboxService;
use App\Shared\Recovery\RecoveryVerifier;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

Artisan::command('qt:status', function () { $this->info('Q & T FOODS ERP backend ready.'); });

Artisan::command('qt:deployment:verify', function () {
    $violations = app(ProductionEnvironmentGuard::class)->violations('production');
    if ($violations !== []) {
        $this->error('Production configuration is not safe:');
        foreach ($violations as $violation) {
            $this->line(' - '.$violation);
        }

        return 1;
    }

    $this->info('Production configuration passed all fail-closed checks.');
    return 0;
})->purpose('Fail unless the current configuration satisfies the production security baseline.');

Artisan::command('qt:security:verify-identities', function () {
    $result = app(ProductionIdentityVerifier::class)->verify();
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $result['status'] === 'pass' ? 0 : 2;
})->purpose('Fail if demo principals, shared preset credentials, active demo sessions, or synthetic-context leaks remain.');

Artisan::command('qt:identity:bootstrap-admin
    {email : Registered mailbox for the first real ERP administrator}
    {--name=ERP Administrator : Administrator display name}
    {--company-code=QTF-LIVE : New or existing non-demo company code}
    {--company-name=Q & T FOODS LTD : Legal and display name when creating the company}
    {--plant-code=HQ : New or existing non-demo plant code}
    {--plant-name=Head Office : Plant name when creating the plant}
    {--timezone=Asia/Kolkata : IANA timezone when creating the plant}
    {--confirmation= : Must equal CREATE_PRODUCTION_ADMIN}
    {--send-password-reset : Send another password-setup link when the account already exists}',
    function (ProductionAdminBootstrapper $bootstrapper, IdentityLifecycleService $identity) {
        try {
            $result = $bootstrapper->bootstrap([
                'email' => $this->argument('email'),
                'name' => $this->option('name'),
                'company_code' => $this->option('company-code'),
                'company_name' => $this->option('company-name'),
                'plant_code' => $this->option('plant-code'),
                'plant_name' => $this->option('plant-name'),
                'timezone' => $this->option('timezone'),
                'confirmation' => $this->option('confirmation'),
            ]);
        } catch (\InvalidArgumentException|\RuntimeException $exception) {
            $this->error($exception->getMessage());

            return 2;
        }

        if ($result['password_setup_required'] || (bool) $this->option('send-password-reset')) {
            $delivery = $identity->requestPasswordReset($result['email'], null, true);
            $result['password_setup_delivery'] = $delivery['delivery'] ?? null;
        }
        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if (($result['password_setup_delivery']['status'] ?? 'SENT') !== 'SENT') {
            $this->warn('The administrator was provisioned, but the password-setup email failed. Correct mail delivery and rerun with --send-password-reset.');

            return 1;
        }

        return 0;
    }
)->purpose('One-time, audited provisioning of a real non-demo ERP administrator with mandatory MFA.');

Artisan::command('qt:outbox:process {--limit=}', function () {
    $limit = $this->option('limit');
    $result = app(OutboxProcessor::class)->process(
        $limit === null || $limit === '' ? null : (int) $limit
    );
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
})->purpose('Synchronously deliver due transactional-outbox events.');

Artisan::command('qt:release:create-outbox-probe {--company-id=} {--plant-id=}', function () {
    $companyId = trim((string) $this->option('company-id')) ?: null;
    $plantId = trim((string) $this->option('plant-id')) ?: null;
    if (($companyId !== null && ! Str::isUuid($companyId))
        || ($plantId !== null && ! Str::isUuid($plantId))
        || ($plantId !== null && $companyId === null)) {
        $this->error('Company and plant options must be UUIDs, and a plant requires a company.');

        return 2;
    }

    $probeId = (string) Str::uuid();
    $eventId = app(OutboxService::class)->append(
        'release.readiness.probe',
        'release_probe',
        $probeId,
        'release-readiness-'.$probeId,
        ['probe_id' => $probeId, 'purpose' => 'External receiver and restart acceptance'],
        (string) Str::uuid(),
        $companyId,
        $plantId,
    );
    $evidence = json_encode([
        'probe_id' => $probeId,
        'event_id' => $eventId,
        'created_at' => now()->utc()->toIso8601String(),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    $path = 'release-readiness/'.$probeId.'.json';
    $disk = (string) config('qtfoods.evidence_disk', 'evidence');
    Storage::disk($disk)->put($path, $evidence, ['visibility' => 'private']);
    $this->line(json_encode([
        'status' => 'created',
        'probe_id' => $probeId,
        'event_id' => $eventId,
        'evidence_disk' => $disk,
        'evidence_path' => $path,
        'evidence_sha256' => hash('sha256', $evidence),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return 0;
})->purpose('Create a synthetic HTTP outbox event and durable evidence object for restart acceptance testing.');

Artisan::command('qt:release:verify-outbox {eventId} {--require-failure-lifecycle} {--require-idempotent-replay} {--require-worker-restart} {--evidence-path=} {--evidence-sha256=}', function () {
    $eventId = (string) $this->argument('eventId');
    if (! Str::isUuid($eventId)) {
        $this->error('eventId must be a UUID.');

        return 2;
    }
    $result = app(OutboxReadinessVerifier::class)->verify($eventId, [
        'failure_lifecycle' => (bool) $this->option('require-failure-lifecycle'),
        'idempotent_replay' => (bool) $this->option('require-idempotent-replay'),
        'worker_restart' => (bool) $this->option('require-worker-restart'),
        'evidence_path' => $this->option('evidence-path'),
        'evidence_sha256' => $this->option('evidence-sha256'),
    ]);
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return $result['status'] === 'pass' ? 0 : 2;
})->purpose('Verify real HTTP ACK, retry/quarantine/replay, worker restart, backlog monitoring, and durable evidence for a release probe.');

Artisan::command('qt:observability:check', function () {
    $result = app(OperationalMonitor::class)->check();
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    return match ($result['status']) {
        'critical' => 2,
        'warning' => 1,
        default => 0,
    };
})->purpose('Evaluate dependency, queue, outbox, and audit thresholds and dispatch operational alerts.');

Artisan::command('qt:recovery:verify {--object-limit=} {--release-stale-outbox} {--confirmation=}', function () {
    $verifier = app(RecoveryVerifier::class);
    $configuredLimit = (int) config('recovery.object_verification_limit', 100000);
    $requestedLimit = $this->option('object-limit');
    if ($requestedLimit !== null && $requestedLimit !== ''
        && (! ctype_digit((string) $requestedLimit) || (int) $requestedLimit > 1_000_000)) {
        $this->error('The object limit must be an integer between 0 and 1000000; zero verifies every object.');

        return 2;
    }
    $released = 0;
    if ((bool) $this->option('release-stale-outbox')) {
        if (! hash_equals('RELEASE_STALE_OUTBOX', (string) $this->option('confirmation'))) {
            $this->error('Releasing stale outbox locks requires --confirmation=RELEASE_STALE_OUTBOX.');

            return 2;
        }
        $released = $verifier->releaseStaleOutbox();
        Log::warning('recovery_stale_outbox_released', [
            'event' => 'recovery_stale_outbox_released',
            'released_count' => $released,
        ]);
    }
    $result = $verifier->verify(
        $requestedLimit === null || $requestedLimit === '' ? $configuredLimit : (int) $requestedLimit,
    );
    $result['released_stale_outbox'] = $released;
    $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    Log::log($result['status'] === 'critical' ? 'critical' : ($result['status'] === 'warning' ? 'warning' : 'info'),
        'recovery_verification_completed', [
            'event' => 'recovery_verification_completed',
            'status' => $result['status'],
            'object_total' => $result['object_storage']['total'],
            'object_verified' => $result['object_storage']['verified'],
            'issue_codes' => collect($result['issues'])->pluck('code')->all(),
            'released_stale_outbox' => $released,
        ]);

    return match ($result['status']) {
        'critical' => 2,
        'warning' => 1,
        default => 0,
    };
})->purpose('Verify restored database/object integrity and optionally release stale outbox locks.');

Artisan::command('qt:evidence:migrate-object-storage {--source=evidence_legacy}', function () {
    $source = (string) $this->option('source');
    $target = (string) config('qtfoods.evidence_disk', 'evidence');
    if ($source === $target) {
        $this->error('Source and target evidence disks must be different.');
        return 1;
    }

    $copied = 0;
    $verified = 0;
    $missing = [];
    DB::table('unsold_return_evidence')->orderBy('uploaded_at')->get()
        ->each(function (object $evidence) use ($source, $target, &$copied, &$verified, &$missing): void {
            $path = (string) $evidence->storage_path;
            if (! Storage::disk($target)->exists($path)) {
                if (! Storage::disk($source)->exists($path)) {
                    $missing[] = $path;
                    return;
                }
                $stream = Storage::disk($source)->readStream($path);
                try {
                    Storage::disk($target)->writeStream($path, $stream, ['visibility' => 'private']);
                } finally {
                    if (is_resource($stream)) {
                        fclose($stream);
                    }
                }
                $copied++;
            }

            $actual = hash('sha256', Storage::disk($target)->get($path));
            if (! hash_equals((string) $evidence->sha256, $actual)) {
                Storage::disk($target)->delete($path);
                throw new RuntimeException('Integrity verification failed for evidence '.$evidence->id.'.');
            }
            $verified++;
        });

    if ($missing !== []) {
        $this->error(count($missing).' evidence object(s) were absent from both disks.');
        foreach ($missing as $path) {
            $this->line($path);
        }
        return 1;
    }
    $this->info("Evidence migration complete: {$copied} copied, {$verified} verified.");
    return 0;
})->purpose('Copy legacy private evidence into the configured object-storage disk and verify SHA-256 hashes.');

Schedule::job(new ProcessOutboxBatch())
    ->name('qt-outbox-delivery')
    ->everyMinute()
    ->withoutOverlapping(5);

Schedule::command('qt:observability:check')
    ->name('qt-observability-check')
    ->everyFiveMinutes()
    ->withoutOverlapping(10);

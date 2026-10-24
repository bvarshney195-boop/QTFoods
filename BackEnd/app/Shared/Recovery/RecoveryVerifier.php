<?php

namespace App\Shared\Recovery;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

final class RecoveryVerifier
{
    private const OUTBOX_STATUSES = ['PENDING', 'PROCESSING', 'RETRY', 'DELIVERED', 'QUARANTINED'];

    public function verify(?int $objectLimit = null): array
    {
        $checkedAt = CarbonImmutable::now('UTC');
        $issues = [];
        $objectLimit ??= (int) config('recovery.object_verification_limit', 100000);
        $objectLimit = max(0, min($objectLimit, 1_000_000));

        try {
            DB::select('SELECT 1');
            $database = $this->databaseSnapshot($checkedAt);
            $objects = $this->verifyObjects($objectLimit);
        } catch (Throwable $exception) {
            return [
                'status' => 'critical',
                'checked_at' => $checkedAt->toIso8601String(),
                'database' => [
                    'status' => 'down',
                    'error_type' => class_basename($exception),
                ],
                'object_storage' => $this->emptyObjectResult(),
                'outbox' => array_fill_keys(self::OUTBOX_STATUSES, 0) + [
                    'stale_processing' => 0,
                    'delivered_without_acknowledgement' => 0,
                ],
                'failed_jobs' => 0,
                'issues' => [[
                    'code' => 'recovery.database_unavailable',
                    'severity' => 'critical',
                    'message' => 'The recovery database or required schema is unavailable.',
                    'error_type' => class_basename($exception),
                ]],
            ];
        }

        if (! $objects['complete']) {
            $issues[] = $this->issue(
                'recovery.object_verification_incomplete',
                'critical',
                'The configured object limit prevented a complete restored-object verification.',
                $objects['total'] - $objects['checked'],
            );
        }
        foreach (['missing', 'checksum_mismatches', 'size_mismatches', 'read_failures'] as $failure) {
            if ($objects[$failure] > 0) {
                $issues[] = $this->issue(
                    'recovery.objects_'.$failure,
                    'critical',
                    'Restored object integrity verification failed.',
                    $objects[$failure],
                );
            }
        }
        if ($database['outbox']['stale_processing'] > 0) {
            $issues[] = $this->issue(
                'recovery.outbox_stale_processing',
                'warning',
                'Stale outbox processing locks require release before workers resume.',
                $database['outbox']['stale_processing'],
            );
        }
        if ($database['outbox']['delivered_without_acknowledgement'] > 0) {
            $issues[] = $this->issue(
                'recovery.outbox_missing_acknowledgement',
                'critical',
                'Delivered outbox records are missing receiver acknowledgement evidence.',
                $database['outbox']['delivered_without_acknowledgement'],
            );
        }
        if ($database['failed_jobs'] > 0) {
            $issues[] = $this->issue(
                'recovery.failed_jobs_pending_review',
                'warning',
                'Restored failed jobs require explicit operator review before selective retry.',
                $database['failed_jobs'],
            );
        }

        $status = collect($issues)->contains('severity', 'critical')
            ? 'critical'
            : ($issues === [] ? 'ok' : 'warning');

        return [
            'status' => $status,
            'checked_at' => $checkedAt->toIso8601String(),
            'database' => [
                'status' => 'up',
                'migration_count' => $database['migration_count'],
                'latest_migration' => $database['latest_migration'],
            ],
            'object_storage' => $objects,
            'outbox' => $database['outbox'],
            'failed_jobs' => $database['failed_jobs'],
            'issues' => $issues,
        ];
    }

    public function releaseStaleOutbox(): int
    {
        $now = CarbonImmutable::now('UTC');
        $staleBefore = $now->subSeconds(max(30, (int) config('qtfoods.outbox.lock_timeout_seconds', 300)));

        return DB::table('outbox_events')
            ->where('status', 'PROCESSING')
            ->where(function ($query) use ($staleBefore): void {
                $query->whereNull('locked_at')->orWhere('locked_at', '<=', $staleBefore);
            })
            ->update([
                'status' => 'RETRY',
                'next_retry_at' => $now,
                'locked_at' => null,
                'locked_by' => null,
                'last_error_code' => 'RECOVERY_STALE_LOCK',
                'last_error_message' => 'Stale processing lock released by the recovery verifier.',
                'record_version' => DB::raw('record_version + 1'),
                'updated_at' => $now,
            ]);
    }

    private function databaseSnapshot(CarbonImmutable $now): array
    {
        foreach (['migrations', 'outbox_events', 'failed_jobs'] as $table) {
            if (! Schema::hasTable($table)) {
                throw new RuntimeException("Required recovery table {$table} is missing.");
            }
        }

        $counts = DB::table('outbox_events')
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');
        $outbox = array_fill_keys(self::OUTBOX_STATUSES, 0);
        foreach (self::OUTBOX_STATUSES as $status) {
            $outbox[$status] = (int) ($counts[$status] ?? 0);
        }
        $staleBefore = $now->subSeconds(max(30, (int) config('qtfoods.outbox.lock_timeout_seconds', 300)));
        $outbox['stale_processing'] = DB::table('outbox_events')
            ->where('status', 'PROCESSING')
            ->where(function ($query) use ($staleBefore): void {
                $query->whereNull('locked_at')->orWhere('locked_at', '<=', $staleBefore);
            })->count();
        $outbox['delivered_without_acknowledgement'] = DB::table('outbox_events')
            ->where('status', 'DELIVERED')
            ->where(function ($query): void {
                $query->whereNull('acknowledged_at')
                    ->orWhereNull('acknowledgement_id')
                    ->orWhere('acknowledgement_id', '');
            })->count();

        return [
            'migration_count' => DB::table('migrations')->count(),
            'latest_migration' => DB::table('migrations')->orderByDesc('migration')->value('migration'),
            'outbox' => $outbox,
            'failed_jobs' => DB::table('failed_jobs')->count(),
        ];
    }

    private function verifyObjects(int $limit): array
    {
        $result = $this->emptyObjectResult();
        $sources = (array) config('recovery.object_sources', []);
        foreach ($sources as $source) {
            if (! Schema::hasTable($source['table'])) {
                throw new RuntimeException("Required object metadata table {$source['table']} is missing.");
            }
            $result['by_source'][$source['table']] = [
                'total' => DB::table($source['table'])->count(),
                'checked' => 0,
                'verified' => 0,
                'failed' => 0,
            ];
            $result['total'] += $result['by_source'][$source['table']]['total'];
        }

        foreach ($sources as $source) {
            $remaining = $limit === 0 ? null : max(0, $limit - $result['checked']);
            if ($remaining === 0) {
                break;
            }
            $query = DB::table($source['table'])->orderBy('id');
            if ($remaining !== null) {
                $query->limit($remaining);
            }
            foreach ($query->cursor() as $record) {
                $this->verifyObject($source, $record, $result);
            }
        }
        $result['complete'] = $result['checked'] === $result['total'];

        return $result;
    }

    private function verifyObject(array $source, object $record, array &$result): void
    {
        $table = $source['table'];
        $disk = (string) $record->{$source['disk_column']};
        $path = (string) $record->{$source['path_column']};
        $expectedChecksum = strtolower((string) $record->{$source['checksum_column']});
        $expectedSize = (int) $record->{$source['size_column']};
        $result['checked']++;
        $result['by_source'][$table]['checked']++;

        try {
            if (! Storage::disk($disk)->exists($path)) {
                $result['missing']++;
                $result['by_source'][$table]['failed']++;
                $this->recordFailure($result, $table, (string) $record->id, 'missing');

                return;
            }
            $stream = Storage::disk($disk)->readStream($path);
            if (! is_resource($stream)) {
                throw new RuntimeException('The storage adapter did not return a readable stream.');
            }
            try {
                $hash = hash_init('sha256');
                $bytes = hash_update_stream($hash, $stream);
                $checksum = hash_final($hash);
            } finally {
                fclose($stream);
            }
            $failed = false;
            if (! is_int($bytes) || $bytes !== $expectedSize) {
                $result['size_mismatches']++;
                $this->recordFailure($result, $table, (string) $record->id, 'size_mismatch');
                $failed = true;
            }
            if (! hash_equals($expectedChecksum, $checksum)) {
                $result['checksum_mismatches']++;
                $this->recordFailure($result, $table, (string) $record->id, 'checksum_mismatch');
                $failed = true;
            }
            if (! $failed) {
                $result['verified']++;
                $result['by_source'][$table]['verified']++;
            } else {
                $result['by_source'][$table]['failed']++;
            }
        } catch (Throwable $exception) {
            $result['read_failures']++;
            $result['by_source'][$table]['failed']++;
            $this->recordFailure(
                $result,
                $table,
                (string) $record->id,
                'read_failure',
                class_basename($exception),
            );
        }
    }

    private function emptyObjectResult(): array
    {
        return [
            'total' => 0,
            'checked' => 0,
            'verified' => 0,
            'complete' => true,
            'missing' => 0,
            'checksum_mismatches' => 0,
            'size_mismatches' => 0,
            'read_failures' => 0,
            'by_source' => [],
            'failures' => [],
        ];
    }

    private function recordFailure(
        array &$result,
        string $table,
        string $recordId,
        string $failure,
        ?string $errorType = null,
    ): void {
        if (count($result['failures']) < 50) {
            $result['failures'][] = array_filter([
                'source' => $table,
                'record_id' => $recordId,
                'failure' => $failure,
                'error_type' => $errorType,
            ], static fn (mixed $value): bool => $value !== null);
        }
    }

    private function issue(string $code, string $severity, string $message, int $value): array
    {
        return compact('code', 'severity', 'message', 'value');
    }
}

<?php

namespace App\Modules\Control\Application;

use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxProcessor;
use App\Shared\Outbox\OutboxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OutboxOperationsService
{
    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
        private readonly OutboxProcessor $processor,
    ) {}

    public function retry(string $eventId, array $data): array
    {
        return DB::transaction(function () use ($eventId, $data) {
            $namespace = 'control.outbox.retry.'.$eventId;
            $replay = $this->idempotency->begin($namespace, $data['idempotency_key'], [
                'event_id' => $eventId,
                'expected_version' => $data['expected_version'],
            ]);
            if ($replay !== null) {
                return $replay;
            }

            $event = $this->findLocked($eventId, $data);
            $this->assertVersion($event, $data['expected_version']);
            if (! in_array($event->status, ['RETRY', 'QUARANTINED'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only retrying or quarantined events can be manually retried.'],
                ]);
            }

            $version = (int) $event->record_version + 1;
            DB::table('outbox_events')->where('id', $eventId)->update([
                'status' => 'PENDING',
                'next_retry_at' => null,
                'last_error_code' => null,
                'last_error_message' => null,
                'quarantined_at' => null,
                'quarantined_by' => null,
                'quarantine_reason' => null,
                'locked_at' => null,
                'locked_by' => null,
                'record_version' => $version,
                'updated_at' => now(),
            ]);
            $result = $this->result($eventId, 'PENDING', $version);
            $this->record('RETRY_OUTBOX_EVENT', 'control.outbox.event_retried', $eventId, $data, $version, [
                'status' => ['from' => $event->status, 'to' => 'PENDING'],
                'attempts_preserved' => (int) $event->attempts,
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function quarantine(string $eventId, string $reason, array $data): array
    {
        return DB::transaction(function () use ($eventId, $reason, $data) {
            $namespace = 'control.outbox.quarantine.'.$eventId;
            $replay = $this->idempotency->begin($namespace, $data['idempotency_key'], [
                'event_id' => $eventId,
                'expected_version' => $data['expected_version'],
                'reason' => $reason,
            ]);
            if ($replay !== null) {
                return $replay;
            }

            $event = $this->findLocked($eventId, $data);
            $this->assertVersion($event, $data['expected_version']);
            if (! in_array($event->status, ['PENDING', 'RETRY'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only pending or retrying events can be manually quarantined.'],
                ]);
            }

            $version = (int) $event->record_version + 1;
            DB::table('outbox_events')->where('id', $eventId)->update([
                'status' => 'QUARANTINED',
                'next_retry_at' => null,
                'quarantined_at' => now(),
                'quarantined_by' => $data['actor_id'],
                'quarantine_reason' => $reason,
                'locked_at' => null,
                'locked_by' => null,
                'record_version' => $version,
                'updated_at' => now(),
            ]);
            $result = $this->result($eventId, 'QUARANTINED', $version);
            $this->record('QUARANTINE_OUTBOX_EVENT', 'control.outbox.event_quarantined', $eventId, $data, $version, [
                'status' => ['from' => $event->status, 'to' => 'QUARANTINED'],
                'reason' => $reason,
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function processDue(int $limit, array $data): array
    {
        $namespace = 'control.outbox.process-due';
        $payload = ['limit' => $limit, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id']];
        $replay = DB::transaction(fn () => $this->idempotency->begin(
            $namespace,
            $data['idempotency_key'],
            $payload,
        ), 3);
        if ($replay !== null) {
            return $replay;
        }

        $processed = $this->processor->process(
            $limit,
            'operator:'.$data['actor_id'].':'.Str::lower(Str::random(8)),
            ['company_id' => $data['company_id'], 'plant_id' => $data['plant_id']],
        );
        $batchId = (string) Str::uuid();
        $result = [
            'entity_type' => 'outbox_processing_batch',
            'id' => $batchId,
            'status' => 'COMPLETED',
            'record_version' => 1,
        ] + $processed;

        DB::transaction(function () use ($namespace, $data, $result): void {
            $this->audit->record('PROCESS_OUTBOX_DUE', 'outbox_processing_batch', $result['id'],
                $data['actor_id'], $data['company_id'], $data['plant_id'], 'SUCCESS', [
                    'entity_version' => 1,
                    'correlation_id' => $data['correlation_id'] ?? null,
                    'safe_diff' => [
                        'claimed' => $result['claimed'],
                        'delivered' => $result['delivered'],
                        'retry_scheduled' => $result['retry_scheduled'],
                        'quarantined' => $result['quarantined'],
                    ],
                ]);
            $this->outbox->append(
                'control.outbox.batch_processed',
                'outbox_processing_batch',
                $result['id'],
                $result['id'],
                $result,
                $data['correlation_id'] ?? null,
                $data['company_id'],
                $data['plant_id'],
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
        }, 3);

        return $result;
    }

    private function findLocked(string $eventId, array $data): object
    {
        $event = DB::table('outbox_events')->where('id', $eventId)
            ->where('company_id', $data['company_id'])
            ->where('plant_id', $data['plant_id'])
            ->lockForUpdate()->first();
        if (! $event) {
            throw new NotFoundHttpException('Outbox event not found.');
        }

        return $event;
    }

    private function assertVersion(object $event, int $expectedVersion): void
    {
        if ((int) $event->record_version !== $expectedVersion) {
            throw new ConflictHttpException(
                "The outbox event changed from version {$expectedVersion} to {$event->record_version}. Refresh it before retrying."
            );
        }
    }

    private function result(string $eventId, string $status, int $version): array
    {
        return [
            'entity_type' => 'outbox_event',
            'id' => $eventId,
            'status' => $status,
            'record_version' => $version,
        ];
    }

    private function record(
        string $command,
        string $eventType,
        string $eventId,
        array $data,
        int $version,
        array $safeDiff,
        array $result,
    ): void {
        $this->audit->record($command, 'outbox_event', $eventId, $data['actor_id'],
            $data['company_id'], $data['plant_id'], 'SUCCESS', [
                'entity_version' => $version,
                'correlation_id' => $data['correlation_id'] ?? null,
                'safe_diff' => $safeDiff,
            ]);
        $this->outbox->append($eventType, 'outbox_event', $eventId, $eventId.':'.$version,
            $result, $data['correlation_id'] ?? null, $data['company_id'], $data['plant_id']);
    }
}

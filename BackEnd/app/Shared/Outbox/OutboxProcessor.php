<?php

namespace App\Shared\Outbox;

use App\Shared\Observability\TraceContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

final class OutboxProcessor
{
    public function __construct(private readonly OutboxTransport $transport) {}

    public function process(?int $limit = null, ?string $workerId = null, ?array $scope = null): array
    {
        $limit = min(max(1, $limit ?? (int) config('qtfoods.outbox.batch_size', 50)), 500);
        $workerId ??= gethostname().':'.getmypid().':'.Str::lower(Str::random(8));
        $transport = $this->transport->name();
        $result = [
            'claimed' => 0,
            'delivered' => 0,
            'retry_scheduled' => 0,
            'quarantined' => 0,
            'event_ids' => [],
        ];

        if (! $this->transport->deliveryEnabled()) {
            return $result + [
                'worker_id' => $workerId,
                'transport' => $transport,
                'delivery_enabled' => false,
            ];
        }

        for ($index = 0; $index < $limit; $index++) {
            $event = $this->claim($workerId, $scope);
            if (! $event) {
                break;
            }
            $result['claimed']++;
            $result['event_ids'][] = (string) $event->id;
            $envelope = null;
            $deliveryStartedAt = hrtime(true);

            try {
                $envelope = $this->envelope($event);
                $delivery = $this->transport->deliver($envelope);
                if ($this->markDelivered($event, $workerId, $delivery)) {
                    $result['delivered']++;
                    Log::info('outbox_delivery_completed', [
                        'event' => 'outbox_delivery_completed',
                        'outbox_event_id' => (string) $event->id,
                        'event_type' => (string) $event->event_type,
                        'attempt' => (int) $event->attempts,
                        'duration_ms' => max(0, (int) round((hrtime(true) - $deliveryStartedAt) / 1_000_000)),
                        'transport' => $this->transport->name(),
                        'correlation_id' => $envelope['correlation_id'],
                        'trace_id' => $envelope['trace_id'],
                        'span_id' => $envelope['span_id'],
                        'parent_span_id' => $envelope['parent_span_id'],
                    ]);
                }
            } catch (Throwable $exception) {
                $outcome = $this->markFailed($event, $workerId, $exception);
                if ($outcome === 'QUARANTINED') {
                    $result['quarantined']++;
                } elseif ($outcome === 'RETRY') {
                    $result['retry_scheduled']++;
                }
                Log::log($outcome === 'QUARANTINED' ? 'critical' : 'warning', 'outbox_delivery_failed', [
                    'event' => 'outbox_delivery_failed',
                    'outbox_event_id' => (string) $event->id,
                    'event_type' => (string) $event->event_type,
                    'attempt' => (int) $event->attempts,
                    'outcome' => $outcome,
                    'duration_ms' => max(0, (int) round((hrtime(true) - $deliveryStartedAt) / 1_000_000)),
                    'error_type' => class_basename($exception),
                    'correlation_id' => $event->correlation_id ? (string) $event->correlation_id : null,
                    'trace_id' => $envelope['trace_id'] ?? ($event->trace_id ? (string) $event->trace_id : null),
                    'span_id' => $envelope['span_id'] ?? null,
                    'parent_span_id' => $envelope['parent_span_id'] ?? ($event->span_id ? (string) $event->span_id : null),
                ]);
            }
        }

        return $result + [
            'worker_id' => $workerId,
            'transport' => $transport,
            'delivery_enabled' => true,
        ];
    }

    private function claim(string $workerId, ?array $scope): ?object
    {
        return DB::transaction(function () use ($workerId, $scope) {
            $now = CarbonImmutable::now();
            $staleBefore = $now->subSeconds(max(30, (int) config('qtfoods.outbox.lock_timeout_seconds', 300)));
            $query = DB::table('outbox_events')
                ->where(function (Builder $query) use ($now, $staleBefore): void {
                    $query->where('status', 'PENDING')
                        ->orWhere(function (Builder $query) use ($now): void {
                            $query->where('status', 'RETRY')
                                ->where(function (Builder $query) use ($now): void {
                                    $query->whereNull('next_retry_at')->orWhere('next_retry_at', '<=', $now);
                                });
                        })
                        ->orWhere(function (Builder $query) use ($staleBefore): void {
                            $query->where('status', 'PROCESSING')
                                ->where('locked_at', '<=', $staleBefore);
                        });
                })
                ->orderByRaw("CASE status WHEN 'PROCESSING' THEN 0 WHEN 'RETRY' THEN 1 ELSE 2 END")
                ->orderBy('created_at');
            if ($scope !== null) {
                $query->where('company_id', $scope['company_id'])
                    ->where('plant_id', $scope['plant_id']);
            }

            DB::getDriverName() === 'pgsql'
                ? $query->lock('FOR UPDATE SKIP LOCKED')
                : $query->lockForUpdate();
            $event = $query->first();
            if (! $event) {
                return null;
            }

            $attempt = (int) $event->attempts + 1;
            DB::table('outbox_events')->where('id', $event->id)->update([
                'status' => 'PROCESSING',
                'attempts' => $attempt,
                'last_attempt_at' => $now,
                'locked_at' => $now,
                'locked_by' => $workerId,
                'next_retry_at' => null,
                'record_version' => (int) $event->record_version + 1,
                'updated_at' => $now,
            ]);

            $event->status = 'PROCESSING';
            $event->attempts = $attempt;
            $event->last_attempt_at = $now;
            $event->locked_at = $now;
            $event->locked_by = $workerId;

            return $event;
        }, 3);
    }

    private function markDelivered(object $event, string $workerId, array $delivery): bool
    {
        return DB::transaction(function () use ($event, $workerId, $delivery) {
            $locked = DB::table('outbox_events')->where('id', $event->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'PROCESSING' || $locked->locked_by !== $workerId) {
                return false;
            }
            $now = CarbonImmutable::now();
            $acknowledgement = (string) $delivery['acknowledgement_id'];
            $response = $delivery['response'] ?? [];

            $this->recordAttempt($locked, $workerId, 'DELIVERED', $now, [
                'acknowledgement_id' => $acknowledgement,
                'response_json' => $response,
            ]);
            DB::table('outbox_events')->where('id', $locked->id)->update([
                'status' => 'DELIVERED',
                'delivered_at' => $now,
                'acknowledged_at' => $now,
                'acknowledgement_id' => mb_substr($acknowledgement, 0, 255),
                'transport_response_json' => json_encode($response, JSON_THROW_ON_ERROR),
                'last_error_code' => null,
                'last_error_message' => null,
                'locked_at' => null,
                'locked_by' => null,
                'record_version' => (int) $locked->record_version + 1,
                'updated_at' => $now,
            ]);

            return true;
        }, 3);
    }

    private function markFailed(object $event, string $workerId, Throwable $exception): ?string
    {
        return DB::transaction(function () use ($event, $workerId, $exception) {
            $locked = DB::table('outbox_events')->where('id', $event->id)->lockForUpdate()->first();
            if (! $locked || $locked->status !== 'PROCESSING' || $locked->locked_by !== $workerId) {
                return null;
            }
            $now = CarbonImmutable::now();
            $maxAttempts = max(1, (int) config('qtfoods.outbox.max_attempts', 8));
            $quarantine = (int) $locked->attempts >= $maxAttempts;
            $outcome = $quarantine ? 'QUARANTINED' : 'RETRY';
            $errorCode = class_basename($exception);
            $errorMessage = $this->safeError($exception);
            $nextRetry = $quarantine ? null : $now->addSeconds($this->retryDelay((int) $locked->attempts));

            $this->recordAttempt($locked, $workerId, $outcome, $now, [
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
            ]);
            DB::table('outbox_events')->where('id', $locked->id)->update([
                'status' => $outcome,
                'next_retry_at' => $nextRetry,
                'last_error_code' => $errorCode,
                'last_error_message' => $errorMessage,
                'quarantined_at' => $quarantine ? $now : null,
                'quarantined_by' => null,
                'quarantine_reason' => $quarantine
                    ? 'Automatic quarantine after exhausting the configured retry policy.'
                    : null,
                'locked_at' => null,
                'locked_by' => null,
                'record_version' => (int) $locked->record_version + 1,
                'updated_at' => $now,
            ]);

            return $outcome;
        }, 3);
    }

    private function recordAttempt(
        object $event,
        string $workerId,
        string $outcome,
        CarbonImmutable $completedAt,
        array $details,
    ): void {
        DB::table('outbox_delivery_attempts')->insert([
            'id' => (string) Str::uuid(),
            'outbox_event_id' => $event->id,
            'attempt_number' => (int) $event->attempts,
            'worker_id' => $workerId,
            'transport' => $this->transport->name(),
            'outcome' => $outcome,
            'error_code' => $details['error_code'] ?? null,
            'error_message' => $details['error_message'] ?? null,
            'acknowledgement_id' => $details['acknowledgement_id'] ?? null,
            'response_json' => isset($details['response_json'])
                ? json_encode($details['response_json'], JSON_THROW_ON_ERROR)
                : null,
            'started_at' => $event->locked_at,
            'completed_at' => $completedAt,
            'created_at' => $completedAt,
        ]);
    }

    private function envelope(object $event): array
    {
        $trace = TraceContext::child(
            $event->trace_id ? (string) $event->trace_id : null,
            $event->span_id ? (string) $event->span_id : null,
        );

        return [
            'id' => (string) $event->id,
            'event_type' => (string) $event->event_type,
            'aggregate_type' => (string) $event->aggregate_type,
            'aggregate_id' => (string) $event->aggregate_id,
            'business_key' => (string) $event->business_key,
            'company_id' => $event->company_id ? (string) $event->company_id : null,
            'plant_id' => $event->plant_id ? (string) $event->plant_id : null,
            'request_id' => $event->request_id ? (string) $event->request_id : null,
            'correlation_id' => $event->correlation_id ? (string) $event->correlation_id : null,
            'trace_id' => $trace['trace_id'],
            'span_id' => $trace['span_id'],
            'parent_span_id' => $trace['parent_span_id'],
            'traceparent' => $trace['traceparent'],
            'occurred_at' => CarbonImmutable::parse((string) $event->created_at)->utc()->toIso8601String(),
            'attempt' => (int) $event->attempts,
            'payload' => $this->json($event->payload_json),
        ];
    }

    private function retryDelay(int $attempt): int
    {
        $base = max(1, (int) config('qtfoods.outbox.base_retry_seconds', 30));
        $maximum = max($base, (int) config('qtfoods.outbox.max_retry_seconds', 3600));

        return min($maximum, $base * (2 ** min(max(0, $attempt - 1), 16)));
    }

    private function safeError(Throwable $exception): string
    {
        $message = preg_replace('/\s+/u', ' ', trim($exception->getMessage())) ?: 'Delivery failed.';

        return mb_substr($message, 0, 2000);
    }

    private function json(mixed $value): mixed
    {
        return is_array($value)
            ? $value
            : json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }
}

<?php

namespace App\Shared\Observability;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;
use Throwable;

final class OperationalSnapshot
{
    private const OUTBOX_STATUSES = ['PENDING', 'PROCESSING', 'RETRY', 'DELIVERED', 'QUARANTINED'];
    private const AUDIT_OUTCOMES = ['SUCCESS', 'FAILURE', 'DENIED'];

    public function __construct(private readonly DependencyHealth $dependencies) {}

    public function capture(): array
    {
        $now = CarbonImmutable::now('UTC');
        $health = $this->dependencies->check();
        $outboxTransport = strtolower(trim((string) config('qtfoods.outbox.transport', 'log')));
        $outboxDeliveryEnabled = $outboxTransport !== 'standalone';
        $snapshot = [
            'checked_at' => $now->toIso8601String(),
            'dependencies' => $health['checks'],
            'outbox' => array_fill_keys(self::OUTBOX_STATUSES, 0) + [
                'transport' => $outboxTransport,
                'delivery_enabled' => $outboxDeliveryEnabled,
                'oldest_due_age_seconds' => $outboxDeliveryEnabled ? 0 : null,
                'failed_attempts_in_window' => 0,
            ],
            'failed_jobs' => 0,
            'queues' => [],
            'audit' => array_fill_keys(self::AUDIT_OUTCOMES, 0) + [
                'events_in_window' => 0,
                'missing_request_context_in_window' => 0,
                'last_event_at' => null,
            ],
        ];

        if (($health['checks']['database']['status'] ?? null) === 'up') {
            try {
                $snapshot = $this->databaseMetrics($snapshot, $now);
            } catch (Throwable $exception) {
                $snapshot['dependencies']['operational_schema'] = [
                    'status' => 'down',
                    'latency_ms' => 0,
                    'error_type' => class_basename($exception),
                ];
            }
        }

        if ((string) config('queue.default') === 'redis'
            && ($health['checks']['redis']['status'] ?? null) === 'up') {
            try {
                foreach ((array) config('observability.monitoring.queue_names', ['outbox', 'default']) as $queue) {
                    $queue = trim((string) $queue);
                    if ($queue !== '') {
                        $connection = (string) config('queue.connections.redis.connection', 'default');
                        $snapshot['queues'][$queue] = max(0, (int) Redis::connection($connection)
                            ->command('llen', ['queues:'.$queue]));
                    }
                }
            } catch (Throwable $exception) {
                $snapshot['dependencies']['queue_metrics'] = [
                    'status' => 'down',
                    'latency_ms' => 0,
                    'error_type' => class_basename($exception),
                ];
            }
        }

        return $snapshot;
    }

    private function databaseMetrics(array $snapshot, CarbonImmutable $now): array
    {
        $outboxCounts = DB::table('outbox_events')
            ->selectRaw('status, COUNT(*) AS aggregate')
            ->groupBy('status')->pluck('aggregate', 'status');
        foreach (self::OUTBOX_STATUSES as $status) {
            $snapshot['outbox'][$status] = (int) ($outboxCounts[$status] ?? 0);
        }
        if ($snapshot['outbox']['delivery_enabled']) {
            $oldestDue = DB::table('outbox_events')->whereIn('status', ['PENDING', 'RETRY'])
                ->min(DB::raw('COALESCE(next_retry_at, created_at)'));
            if ($oldestDue !== null) {
                $snapshot['outbox']['oldest_due_age_seconds'] = max(
                    0,
                    $now->getTimestamp() - CarbonImmutable::parse((string) $oldestDue)->getTimestamp(),
                );
            }
        }

        $windowMinutes = max(1, (int) config('observability.monitoring.audit_window_minutes', 60));
        $windowStart = $now->subMinutes($windowMinutes);
        $snapshot['outbox']['failed_attempts_in_window'] = DB::table('outbox_delivery_attempts')
            ->whereIn('outcome', ['RETRY', 'QUARANTINED'])
            ->where('completed_at', '>=', $windowStart)->count();
        $snapshot['failed_jobs'] = DB::table('failed_jobs')->count();

        $auditCounts = DB::table('audit_events')->selectRaw('outcome, COUNT(*) AS aggregate')
            ->groupBy('outcome')->pluck('aggregate', 'outcome');
        foreach (self::AUDIT_OUTCOMES as $outcome) {
            $snapshot['audit'][$outcome] = (int) ($auditCounts[$outcome] ?? 0);
        }
        $recent = DB::table('audit_events')->where('event_at', '>=', $windowStart);
        $snapshot['audit']['events_in_window'] = (clone $recent)->count();
        $snapshot['audit']['missing_request_context_in_window'] = (clone $recent)
            ->where(function ($query): void {
                $query->whereNull('request_id')->orWhereNull('correlation_id')->orWhereNull('trace_id');
            })->count();
        $lastEventAt = DB::table('audit_events')->max('event_at');
        $snapshot['audit']['last_event_at'] = $lastEventAt === null
            ? null
            : CarbonImmutable::parse((string) $lastEventAt)->utc()->toIso8601String();

        return $snapshot;
    }
}

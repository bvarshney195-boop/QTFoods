<?php

namespace App\Modules\Control\Application;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OutboxQuery
{
    public const STATUSES = ['PENDING', 'PROCESSING', 'RETRY', 'DELIVERED', 'QUARANTINED'];
    public const SORTS = ['NEWEST', 'OLDEST', 'NEXT_RETRY'];

    public function workspace(array $scope, array $filters, array $permissions): array
    {
        $deliveryEnabled = $this->deliveryEnabled();
        $base = $this->scoped($scope);
        $query = clone $base;
        $this->applyFilters($query, $filters);
        $sort = $filters['sort'] ?? 'NEWEST';
        if ($sort === 'NEXT_RETRY') {
            $query->orderByRaw('CASE WHEN outbox.next_retry_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('outbox.next_retry_at')->orderBy('outbox.created_at');
        } else {
            $direction = $sort === 'OLDEST' ? 'asc' : 'desc';
            $query->orderBy('outbox.created_at', $direction)->orderBy('outbox.id', $direction);
        }

        $events = $query->select('outbox.*')
            ->selectSub(
                DB::table('outbox_delivery_attempts')->selectRaw('COUNT(*)')
                    ->whereColumn('outbox_event_id', 'outbox.id'),
                'attempt_history_count'
            )
            ->paginate((int) ($filters['per_page'] ?? 25));

        $summary = ['total' => (clone $base)->count()];
        foreach (self::STATUSES as $status) {
            $summary[strtolower($status)] = (clone $base)->where('outbox.status', $status)->count();
        }

        return [
            'data' => collect($events->items())->map(fn (object $event) =>
                $this->eventPayload($event, $permissions, false))->all(),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
            'summary' => $summary,
            'runtime' => $this->runtime($base, $scope),
            'lookups' => [
                'statuses' => self::STATUSES,
                'event_types' => (clone $base)->distinct()->orderBy('outbox.event_type')
                    ->pluck('outbox.event_type')->all(),
            ],
            'allowed_actions' => $deliveryEnabled && in_array('ACTION:ADM-INT:PROCESS', $permissions, true)
                ? ['PROCESS_DUE']
                : [],
        ];
    }

    public function detail(string $eventId, array $scope, array $permissions): array
    {
        $event = $this->scoped($scope)->where('outbox.id', $eventId)->first('outbox.*');
        if (! $event) {
            throw new NotFoundHttpException('Outbox event not found.');
        }

        $attempts = DB::table('outbox_delivery_attempts')
            ->where('outbox_event_id', $eventId)
            ->orderByDesc('attempt_number')->get()
            ->map(fn (object $attempt) => [
                'id' => (string) $attempt->id,
                'attempt_number' => (int) $attempt->attempt_number,
                'worker_id' => (string) $attempt->worker_id,
                'transport' => (string) $attempt->transport,
                'outcome' => (string) $attempt->outcome,
                'error_code' => $attempt->error_code,
                'error_message' => $attempt->error_message,
                'acknowledgement_id' => $attempt->acknowledgement_id,
                'response' => $this->json($attempt->response_json),
                'started_at' => $this->timestamp($attempt->started_at),
                'completed_at' => $this->timestamp($attempt->completed_at),
            ])->all();

        return $this->eventPayload($event, $permissions, true) + ['attempt_history' => $attempts];
    }

    private function scoped(array $scope): Builder
    {
        return DB::table('outbox_events as outbox')
            ->where('outbox.company_id', $scope['company_id'])
            ->where('outbox.plant_id', $scope['plant_id']);
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $needle = '%'.mb_strtolower($q).'%';
            $query->where(function (Builder $query) use ($needle): void {
                foreach (['event_type', 'aggregate_type', 'aggregate_id', 'business_key', 'request_id', 'correlation_id', 'trace_id', 'acknowledgement_id', 'last_error_code'] as $column) {
                    $query->orWhereRaw(
                        "LOWER(COALESCE(CAST(outbox.{$column} AS TEXT), '')) LIKE ?",
                        [$needle]
                    );
                }
            });
        }
        foreach (['status', 'event_type'] as $filter) {
            if (! empty($filters[$filter])) {
                $query->where('outbox.'.$filter, $filters[$filter]);
            }
        }
    }

    private function eventPayload(object $event, array $permissions, bool $detail): array
    {
        $allowed = [];
        if (in_array($event->status, ['RETRY', 'QUARANTINED'], true)
            && in_array('ACTION:ADM-INT:RETRY', $permissions, true)) {
            $allowed[] = 'RETRY';
        }
        if (in_array($event->status, ['PENDING', 'RETRY'], true)
            && in_array('ACTION:ADM-INT:QUARANTINE', $permissions, true)) {
            $allowed[] = 'QUARANTINE';
        }

        $payload = [
            'id' => (string) $event->id,
            'event_type' => (string) $event->event_type,
            'aggregate_type' => (string) $event->aggregate_type,
            'aggregate_id' => (string) $event->aggregate_id,
            'business_key' => (string) $event->business_key,
            'request_id' => $event->request_id ? (string) $event->request_id : null,
            'correlation_id' => $event->correlation_id ? (string) $event->correlation_id : null,
            'trace_id' => $event->trace_id ? (string) $event->trace_id : null,
            'span_id' => $event->span_id ? (string) $event->span_id : null,
            'status' => (string) $event->status,
            'attempts' => (int) $event->attempts,
            'record_version' => (int) $event->record_version,
            'next_retry_at' => $this->timestamp($event->next_retry_at),
            'last_attempt_at' => $this->timestamp($event->last_attempt_at),
            'delivered_at' => $this->timestamp($event->delivered_at),
            'acknowledged_at' => $this->timestamp($event->acknowledged_at),
            'acknowledgement_id' => $event->acknowledgement_id,
            'last_error_code' => $event->last_error_code,
            'last_error_message' => $event->last_error_message,
            'quarantined_at' => $this->timestamp($event->quarantined_at),
            'quarantine_reason' => $event->quarantine_reason,
            'created_at' => $this->timestamp($event->created_at),
            'updated_at' => $this->timestamp($event->updated_at),
            'attempt_history_count' => isset($event->attempt_history_count)
                ? (int) $event->attempt_history_count
                : DB::table('outbox_delivery_attempts')->where('outbox_event_id', $event->id)->count(),
            'allowed_actions' => $allowed,
        ];
        if ($detail) {
            $payload['payload'] = $this->json($event->payload_json);
            $payload['transport_response'] = $this->json($event->transport_response_json);
            $payload['worker_lock'] = $event->locked_at ? [
                'worker_id' => $event->locked_by,
                'locked_at' => $this->timestamp($event->locked_at),
            ] : null;
        }

        return $payload;
    }

    private function runtime(Builder $base, array $scope): array
    {
        $deliveryEnabled = $this->deliveryEnabled();
        $lastAttempt = DB::table('outbox_delivery_attempts as attempt')
            ->join('outbox_events as event', 'event.id', '=', 'attempt.outbox_event_id')
            ->where('event.company_id', $scope['company_id'])
            ->where('event.plant_id', $scope['plant_id'])
            ->orderByDesc('attempt.completed_at')->value('attempt.completed_at');

        return [
            'queue_connection' => (string) config('queue.default', env('QUEUE_CONNECTION', 'sync')),
            'transport' => $this->transport(),
            'delivery_enabled' => $deliveryEnabled,
            'batch_size' => (int) config('qtfoods.outbox.batch_size', 50),
            'max_attempts' => (int) config('qtfoods.outbox.max_attempts', 8),
            'base_retry_seconds' => (int) config('qtfoods.outbox.base_retry_seconds', 30),
            'lock_timeout_seconds' => (int) config('qtfoods.outbox.lock_timeout_seconds', 300),
            'last_attempt_at' => $this->timestamp($lastAttempt),
            'oldest_due_at' => $deliveryEnabled
                ? $this->timestamp((clone $base)
                    ->whereIn('outbox.status', ['PENDING', 'RETRY'])
                    ->min(DB::raw('COALESCE(outbox.next_retry_at, outbox.created_at)')))
                : null,
            'evidence_disk' => (string) config('qtfoods.evidence_disk', 'evidence'),
            'evidence_driver' => (string) config('filesystems.disks.'.config('qtfoods.evidence_disk', 'evidence').'.driver', 'unknown'),
        ];
    }

    private function deliveryEnabled(): bool
    {
        return $this->transport() !== 'standalone';
    }

    private function transport(): string
    {
        return strtolower(trim((string) config('qtfoods.outbox.transport', 'log')));
    }

    private function json(mixed $value): mixed
    {
        if ($value === null || is_array($value)) {
            return $value;
        }

        return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->utc()->toIso8601String();
    }
}

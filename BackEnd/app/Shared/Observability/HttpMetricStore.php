<?php

namespace App\Shared\Observability;

use Illuminate\Contracts\Cache\Repository;

final class HttpMetricStore
{
    private const METHODS = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS', 'OTHER'];
    private const STATUS_CLASSES = ['1xx', '2xx', '3xx', '4xx', '5xx'];

    public function __construct(private readonly Repository $cache) {}

    public function record(string $method, int $status, int $durationMilliseconds): void
    {
        $method = strtoupper($method);
        if (! in_array($method, self::METHODS, true)) {
            $method = 'OTHER';
        }
        $statusClass = min(5, max(1, intdiv(max(100, $status), 100))).'xx';

        $this->increment("requests:method:{$method}");
        $this->increment("requests:status:{$statusClass}");
        $this->increment('duration:count');
        $this->increment('duration:sum_ms', max(0, $durationMilliseconds));
        foreach ($this->buckets() as $bucket) {
            if ($durationMilliseconds <= $bucket) {
                $this->increment("duration:bucket:{$bucket}");
            }
        }
    }

    public function snapshot(): array
    {
        $methods = [];
        foreach (self::METHODS as $method) {
            $methods[$method] = $this->value("requests:method:{$method}");
        }
        $statuses = [];
        foreach (self::STATUS_CLASSES as $statusClass) {
            $statuses[$statusClass] = $this->value("requests:status:{$statusClass}");
        }
        $buckets = [];
        foreach ($this->buckets() as $bucket) {
            $buckets[$bucket] = $this->value("duration:bucket:{$bucket}");
        }

        return [
            'methods' => $methods,
            'status_classes' => $statuses,
            'duration_count' => $this->value('duration:count'),
            'duration_sum_ms' => $this->value('duration:sum_ms'),
            'duration_buckets_ms' => $buckets,
        ];
    }

    private function increment(string $key, int $amount = 1): void
    {
        $ttl = max(3600, (int) config('observability.http_metrics.retention_seconds', 2592000));
        $key = $this->key($key);
        $this->cache->add($key, 0, $ttl);
        $this->cache->increment($key, $amount);
    }

    private function value(string $key): int
    {
        return max(0, (int) $this->cache->get($this->key($key), 0));
    }

    private function key(string $suffix): string
    {
        return (string) config('observability.http_metrics.cache_prefix', 'qtfoods:observability:http:v1:').$suffix;
    }

    private function buckets(): array
    {
        $buckets = array_values(array_unique(array_filter(
            array_map('intval', (array) config('observability.http_metrics.duration_buckets_ms', [])),
            static fn (int $bucket): bool => $bucket > 0,
        )));
        sort($buckets, SORT_NUMERIC);

        return $buckets;
    }
}

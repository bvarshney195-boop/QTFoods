<?php

$production = (string) env('APP_ENV', 'production') === 'production';
$csv = static function (mixed $value, array $default = []): array {
    if (! is_string($value)) {
        return $default;
    }

    return array_values(array_filter(array_map('trim', explode(',', $value))));
};

return [
    'service' => env('OTEL_SERVICE_NAME', 'qt-foods-erp-crm'),
    'request_id_header' => 'X-Request-ID',
    'correlation_id_header' => 'X-Correlation-ID',
    'traceparent_header' => 'traceparent',
    'slow_request_milliseconds' => (int) env('QT_SLOW_REQUEST_MS', 1000),
    'ignored_http_paths' => ['up', 'api/health', 'api/ready', 'api/metrics'],

    'http_metrics' => [
        'cache_prefix' => 'qtfoods:observability:http:v1:',
        'retention_seconds' => (int) env('QT_HTTP_METRICS_RETENTION_SECONDS', 2592000),
        'duration_buckets_ms' => [50, 100, 250, 500, 1000, 2500, 5000, 10000],
    ],

    'metrics' => [
        'token' => env('QT_METRICS_TOKEN'),
    ],

    'readiness' => [
        'database' => (bool) env('QT_READINESS_DATABASE', true),
        'redis' => (bool) env('QT_READINESS_REDIS', $production),
        'object_storage' => (bool) env('QT_READINESS_OBJECT_STORAGE', $production),
        'object_storage_probe' => '.qtfoods-readiness-probe',
    ],

    'monitoring' => [
        'audit_window_minutes' => (int) env('QT_AUDIT_MONITOR_WINDOW_MINUTES', 60),
        'queue_names' => $csv(env('QT_MONITORED_QUEUES'), ['outbox', 'default']),
        'thresholds' => [
            'failed_jobs' => (int) env('QT_ALERT_FAILED_JOBS_MAX', 0),
            'outbox_quarantined' => (int) env('QT_ALERT_OUTBOX_QUARANTINED_MAX', 0),
            'outbox_retry' => (int) env('QT_ALERT_OUTBOX_RETRY_MAX', 20),
            'outbox_oldest_due_seconds' => (int) env('QT_ALERT_OUTBOX_OLDEST_DUE_SECONDS', 300),
            'queue_depth' => (int) env('QT_ALERT_QUEUE_DEPTH_MAX', 1000),
            'audit_missing_request_context' => (int) env('QT_ALERT_AUDIT_MISSING_CONTEXT_MAX', 0),
        ],
    ],

    'alerts' => [
        'transport' => strtolower((string) env('QT_ALERT_TRANSPORT', 'log')),
        'http_endpoint' => env('QT_ALERT_HTTP_ENDPOINT'),
        'signing_secret' => env('QT_ALERT_SIGNING_SECRET'),
        'timeout_seconds' => (int) env('QT_ALERT_TIMEOUT_SECONDS', 10),
        'renotify_seconds' => (int) env('QT_ALERT_RENOTIFY_SECONDS', 3600),
        'state_cache_key' => 'qtfoods:observability:alerts:v1:state',
    ],
];

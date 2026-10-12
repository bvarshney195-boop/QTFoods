<?php

namespace App\Shared\Observability;

use Throwable;

final class PrometheusExporter
{
    public function __construct(
        private readonly HttpMetricStore $httpMetrics,
        private readonly OperationalSnapshot $snapshots,
        private readonly OperationalAlertEvaluator $alerts,
    ) {}

    public function render(): string
    {
        $snapshot = $this->snapshots->capture();
        try {
            $http = $this->httpMetrics->snapshot();
            $httpAvailable = 1;
        } catch (Throwable) {
            $http = [
                'methods' => [], 'status_classes' => [], 'duration_count' => 0,
                'duration_sum_ms' => 0, 'duration_buckets_ms' => [],
            ];
            $httpAvailable = 0;
        }
        $alerts = $this->alerts->evaluate($snapshot);
        $lines = [
            '# HELP qtfoods_build_info Static service build information.',
            '# TYPE qtfoods_build_info gauge',
            'qtfoods_build_info{service="'.$this->label((string) config('observability.service')).'",environment="'.$this->label(app()->environment()).'",architecture="modular-monolith"} 1',
            '# HELP qtfoods_dependency_up Whether an operational dependency is available.',
            '# TYPE qtfoods_dependency_up gauge',
        ];
        foreach ($snapshot['dependencies'] as $name => $dependency) {
            $lines[] = 'qtfoods_dependency_up{name="'.$this->label((string) $name).'"} '.(($dependency['status'] ?? null) === 'up' ? '1' : '0');
        }
        $lines[] = '# HELP qtfoods_dependency_latency_milliseconds Dependency probe latency.';
        $lines[] = '# TYPE qtfoods_dependency_latency_milliseconds gauge';
        foreach ($snapshot['dependencies'] as $name => $dependency) {
            $lines[] = 'qtfoods_dependency_latency_milliseconds{name="'.$this->label((string) $name).'"} '.max(0, (int) ($dependency['latency_ms'] ?? 0));
        }
        $lines[] = '# HELP qtfoods_http_metrics_available Whether the HTTP metric cache is available.';
        $lines[] = '# TYPE qtfoods_http_metrics_available gauge';
        $lines[] = 'qtfoods_http_metrics_available '.$httpAvailable;
        $lines[] = '# HELP qtfoods_http_requests_total Application HTTP requests by method.';
        $lines[] = '# TYPE qtfoods_http_requests_total counter';
        foreach ($http['methods'] as $method => $count) {
            $lines[] = 'qtfoods_http_requests_total{method="'.$this->label((string) $method).'"} '.(int) $count;
        }
        $lines[] = '# HELP qtfoods_http_responses_total Application HTTP responses by status class.';
        $lines[] = '# TYPE qtfoods_http_responses_total counter';
        foreach ($http['status_classes'] as $status => $count) {
            $lines[] = 'qtfoods_http_responses_total{status_class="'.$this->label((string) $status).'"} '.(int) $count;
        }
        $lines[] = '# HELP qtfoods_http_request_duration_seconds Aggregate application HTTP request duration.';
        $lines[] = '# TYPE qtfoods_http_request_duration_seconds histogram';
        foreach ($http['duration_buckets_ms'] as $bucket => $count) {
            $lines[] = 'qtfoods_http_request_duration_seconds_bucket{le="'.($bucket / 1000).'"} '.(int) $count;
        }
        $lines[] = 'qtfoods_http_request_duration_seconds_bucket{le="+Inf"} '.(int) $http['duration_count'];
        $lines[] = 'qtfoods_http_request_duration_seconds_sum '.number_format(((int) $http['duration_sum_ms']) / 1000, 3, '.', '');
        $lines[] = 'qtfoods_http_request_duration_seconds_count '.(int) $http['duration_count'];
        $lines[] = '# HELP qtfoods_outbox_events Current transactional outbox events by status.';
        $lines[] = '# TYPE qtfoods_outbox_events gauge';
        foreach (self::outboxStatuses() as $status) {
            $lines[] = 'qtfoods_outbox_events{status="'.$status.'"} '.(int) $snapshot['outbox'][$status];
        }
        $lines[] = '# HELP qtfoods_outbox_delivery_enabled Whether external outbox delivery is enabled.';
        $lines[] = '# TYPE qtfoods_outbox_delivery_enabled gauge';
        $lines[] = 'qtfoods_outbox_delivery_enabled{transport="'.$this->label((string) $snapshot['outbox']['transport']).'"} '.($snapshot['outbox']['delivery_enabled'] ? '1' : '0');
        $lines[] = '# HELP qtfoods_outbox_oldest_due_age_seconds Age of the oldest due outbox event.';
        $lines[] = '# TYPE qtfoods_outbox_oldest_due_age_seconds gauge';
        $lines[] = 'qtfoods_outbox_oldest_due_age_seconds '.(int) $snapshot['outbox']['oldest_due_age_seconds'];
        $lines[] = '# HELP qtfoods_outbox_failed_attempts Recent retry or quarantine delivery attempts.';
        $lines[] = '# TYPE qtfoods_outbox_failed_attempts gauge';
        $lines[] = 'qtfoods_outbox_failed_attempts '.(int) $snapshot['outbox']['failed_attempts_in_window'];
        $lines[] = '# HELP qtfoods_queue_depth Current queue depth.';
        $lines[] = '# TYPE qtfoods_queue_depth gauge';
        foreach ($snapshot['queues'] as $queue => $depth) {
            $lines[] = 'qtfoods_queue_depth{queue="'.$this->label((string) $queue).'"} '.(int) $depth;
        }
        $lines[] = '# HELP qtfoods_failed_jobs Current failed queue job records.';
        $lines[] = '# TYPE qtfoods_failed_jobs gauge';
        $lines[] = 'qtfoods_failed_jobs '.(int) $snapshot['failed_jobs'];
        $lines[] = '# HELP qtfoods_audit_events Audit events by outcome.';
        $lines[] = '# TYPE qtfoods_audit_events gauge';
        foreach (['SUCCESS', 'FAILURE', 'DENIED'] as $outcome) {
            $lines[] = 'qtfoods_audit_events{outcome="'.$outcome.'"} '.(int) $snapshot['audit'][$outcome];
        }
        $lines[] = '# HELP qtfoods_audit_missing_request_context Recent audit events missing traceability identifiers.';
        $lines[] = '# TYPE qtfoods_audit_missing_request_context gauge';
        $lines[] = 'qtfoods_audit_missing_request_context '.(int) $snapshot['audit']['missing_request_context_in_window'];
        $lines[] = '# HELP qtfoods_operational_alerts Current alerts by severity.';
        $lines[] = '# TYPE qtfoods_operational_alerts gauge';
        foreach (['warning', 'critical'] as $severity) {
            $count = collect($alerts)->where('severity', $severity)->count();
            $lines[] = 'qtfoods_operational_alerts{severity="'.$severity.'"} '.$count;
        }

        return implode("\n", $lines)."\n";
    }

    private static function outboxStatuses(): array
    {
        return ['PENDING', 'PROCESSING', 'RETRY', 'DELIVERED', 'QUARANTINED'];
    }

    private function label(string $value): string
    {
        return addcslashes($value, "\\\"\n");
    }
}

<?php

namespace App\Shared\Observability;

final class OperationalAlertEvaluator
{
    public function evaluate(array $snapshot): array
    {
        $alerts = [];
        foreach ($snapshot['dependencies'] as $name => $dependency) {
            if (($dependency['status'] ?? null) !== 'up') {
                $alerts[] = $this->alert(
                    'dependency.'.$name,
                    'critical',
                    "Operational dependency {$name} is unavailable.",
                    0,
                    1,
                );
            }
        }

        $thresholds = (array) config('observability.monitoring.thresholds', []);
        $this->above($alerts, 'queue.failed_jobs', 'critical',
            (int) $snapshot['failed_jobs'], (int) ($thresholds['failed_jobs'] ?? 0),
            'Failed queue jobs require operator recovery.');
        $this->above($alerts, 'outbox.quarantined', 'critical',
            (int) $snapshot['outbox']['QUARANTINED'], (int) ($thresholds['outbox_quarantined'] ?? 0),
            'Transactional outbox events are quarantined.');
        $this->above($alerts, 'outbox.retry', 'warning',
            (int) $snapshot['outbox']['RETRY'], (int) ($thresholds['outbox_retry'] ?? 20),
            'Transactional outbox retry volume exceeded policy.');
        if (($snapshot['outbox']['delivery_enabled'] ?? true) === true) {
            $this->above($alerts, 'outbox.oldest_due', 'warning',
                (int) $snapshot['outbox']['oldest_due_age_seconds'],
                (int) ($thresholds['outbox_oldest_due_seconds'] ?? 300),
                'The oldest due outbox event exceeded its delivery-age objective.');
        }
        $this->above($alerts, 'audit.missing_request_context', 'warning',
            (int) $snapshot['audit']['missing_request_context_in_window'],
            (int) ($thresholds['audit_missing_request_context'] ?? 0),
            'Recent audit events are missing request, correlation, or trace context.');
        foreach ($snapshot['queues'] as $queue => $depth) {
            $this->above($alerts, 'queue.depth.'.$queue, 'warning', (int) $depth,
                (int) ($thresholds['queue_depth'] ?? 1000),
                "Queue {$queue} depth exceeded policy.");
        }

        return $alerts;
    }

    private function above(
        array &$alerts,
        string $code,
        string $severity,
        int $value,
        int $threshold,
        string $message,
    ): void {
        if ($value > max(0, $threshold)) {
            $alerts[] = $this->alert($code, $severity, $message, $value, max(0, $threshold));
        }
    }

    private function alert(string $code, string $severity, string $message, int $value, int $threshold): array
    {
        return compact('code', 'severity', 'message', 'value', 'threshold');
    }
}

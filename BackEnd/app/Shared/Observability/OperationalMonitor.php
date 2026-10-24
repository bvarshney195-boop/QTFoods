<?php

namespace App\Shared\Observability;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Log;
use Throwable;

final class OperationalMonitor
{
    public function __construct(
        private readonly OperationalSnapshot $snapshots,
        private readonly OperationalAlertEvaluator $evaluator,
        private readonly OperationalAlertNotifier $notifier,
        private readonly Repository $cache,
    ) {}

    public function check(): array
    {
        $snapshot = $this->snapshots->capture();
        $alerts = $this->evaluator->evaluate($snapshot);
        $severity = collect($alerts)->contains(fn (array $alert): bool => $alert['severity'] === 'critical')
            ? 'critical'
            : ($alerts === [] ? 'ok' : 'warning');
        $fingerprint = hash('sha256', json_encode(
            collect($alerts)->pluck('code')->sort()->values()->all(),
            JSON_THROW_ON_ERROR,
        ));
        $previous = $this->previousState();
        $renotifyAfter = max(60, (int) config('observability.alerts.renotify_seconds', 3600));
        $lastNotifiedAt = isset($previous['notified_at'])
            ? CarbonImmutable::parse((string) $previous['notified_at'])
            : null;
        $renotifyDue = $lastNotifiedAt === null || $lastNotifiedAt->addSeconds($renotifyAfter)->isPast();
        $changed = ($previous['fingerprint'] ?? null) !== $fingerprint;
        $state = $alerts === [] ? 'resolved' : 'firing';
        $shouldNotify = ($alerts !== [] && ($changed || $renotifyDue))
            || ($alerts === [] && ! empty($previous['had_alerts']));
        $notificationError = null;
        $notificationSent = false;

        if ($shouldNotify) {
            try {
                $this->notifier->notify($state, $severity, $alerts, $snapshot);
                $notificationSent = true;
            } catch (Throwable $exception) {
                $notificationError = class_basename($exception);
                Log::critical('operational_alert_delivery_failed', [
                    'event' => 'operational_alert_delivery_failed',
                    'error_type' => $notificationError,
                    'alert_codes' => collect($alerts)->pluck('code')->all(),
                ]);
            }
        }

        if ($notificationError === null) {
            $notifiedAt = $shouldNotify ? $snapshot['checked_at'] : ($previous['notified_at'] ?? null);
            $this->storeState([
                'fingerprint' => $fingerprint,
                'had_alerts' => $alerts !== [],
                'notified_at' => $notifiedAt,
            ]);
        }
        Log::info('operational_health_checked', [
            'event' => 'operational_health_checked',
            'status' => $severity,
            'alert_count' => count($alerts),
            'notification_sent' => $notificationSent,
            'notification_error' => $notificationError,
            'snapshot' => $snapshot,
        ]);

        return [
            'status' => $severity,
            'alerts' => $alerts,
            'notification_sent' => $notificationSent,
            'notification_error' => $notificationError,
            'snapshot' => $snapshot,
        ];
    }

    private function previousState(): array
    {
        try {
            $state = $this->cache->get((string) config('observability.alerts.state_cache_key'));

            return is_array($state) ? $state : [];
        } catch (Throwable $exception) {
            Log::warning('operational_alert_state_unavailable', [
                'event' => 'operational_alert_state_unavailable',
                'operation' => 'read',
                'error_type' => class_basename($exception),
            ]);

            return [];
        }
    }

    private function storeState(array $state): void
    {
        try {
            $this->cache->put(
                (string) config('observability.alerts.state_cache_key'),
                $state,
                max(86400, (int) config('observability.alerts.renotify_seconds', 3600) * 4),
            );
        } catch (Throwable $exception) {
            Log::warning('operational_alert_state_unavailable', [
                'event' => 'operational_alert_state_unavailable',
                'operation' => 'write',
                'error_type' => class_basename($exception),
            ]);
        }
    }
}

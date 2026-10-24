<?php

namespace App\Shared\Observability;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class OperationalAlertNotifier
{
    public function notify(string $state, string $severity, array $alerts, array $snapshot): void
    {
        $payload = [
            'event' => 'operational_alert',
            'state' => $state,
            'severity' => $severity,
            'service' => (string) config('observability.service', 'qt-foods-erp-crm'),
            'environment' => app()->environment(),
            'checked_at' => $snapshot['checked_at'],
            'alerts' => $alerts,
            'snapshot' => $snapshot,
        ];

        if ($severity === 'critical') {
            Log::critical('operational_alert', $payload);
        } elseif ($severity === 'warning') {
            Log::warning('operational_alert', $payload);
        } else {
            Log::info('operational_alert', $payload);
        }

        if ((string) config('observability.alerts.transport', 'log') !== 'http') {
            return;
        }

        $endpoint = trim((string) config('observability.alerts.http_endpoint'));
        $secret = (string) config('observability.alerts.signing_secret');
        if ($endpoint === '' || $secret === '') {
            throw new RuntimeException('HTTP alert transport requires an endpoint and signing secret.');
        }
        $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $response = Http::timeout(max(1, (int) config('observability.alerts.timeout_seconds', 10)))
            ->acceptJson()
            ->withHeaders([
                'X-QT-Alert-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret),
                'X-QT-Alert-State' => $state,
            ])
            ->withBody($body, 'application/json')
            ->post($endpoint);
        if (! $response->successful()) {
            throw new RuntimeException('Alert receiver returned status '.$response->status().'.');
        }
    }
}

<?php

namespace App\Shared\Outbox;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

final class ConfiguredOutboxTransport implements OutboxTransport
{
    public function name(): string
    {
        return strtolower((string) config('qtfoods.outbox.transport', 'log'));
    }

    public function deliver(array $event): array
    {
        return match ($this->name()) {
            'log' => $this->log($event),
            'http' => $this->http($event),
            default => throw new RuntimeException('Unsupported configured outbox transport.'),
        };
    }

    private function log(array $event): array
    {
        Log::info('Transactional outbox event delivered.', ['outbox_event' => $event]);

        return [
            'acknowledgement_id' => 'log:'.$event['id'],
            'response' => ['transport' => 'log', 'accepted' => true],
        ];
    }

    private function http(array $event): array
    {
        $endpoint = trim((string) config('qtfoods.outbox.http_endpoint'));
        $secret = (string) config('qtfoods.outbox.signing_secret');
        if ($endpoint === '' || $secret === '') {
            throw new RuntimeException('HTTP outbox transport requires an endpoint and signing secret.');
        }

        $body = json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $response = Http::timeout(max(1, (int) config('qtfoods.outbox.timeout_seconds', 10)))
            ->acceptJson()
            ->withHeaders([
                'X-QT-Event-ID' => $event['id'],
                'X-QT-Event-Type' => $event['event_type'],
                'X-QT-Signature' => 'sha256='.hash_hmac('sha256', $body, $secret),
                'Idempotency-Key' => $event['id'],
                'X-Correlation-ID' => $event['correlation_id'] ?? $event['id'],
                'traceparent' => $event['traceparent'],
            ])
            ->withBody($body, 'application/json')
            ->post($endpoint);

        if (! $response->successful()) {
            throw new RuntimeException('Outbox HTTP transport returned status '.$response->status().'.');
        }

        $ackHeader = trim((string) config('qtfoods.outbox.acknowledgement_header', 'X-Acknowledgement-ID'));
        $acknowledgement = trim((string) $response->header($ackHeader));
        $json = $response->json();
        if ($acknowledgement === '' && is_array($json)) {
            $acknowledgement = trim((string) ($json['acknowledgement_id'] ?? $json['ack_id'] ?? ''));
        }
        if ($acknowledgement === '' && config('qtfoods.outbox.require_acknowledgement', true)) {
            throw new RuntimeException('Outbox receiver did not return an acknowledgement identifier.');
        }
        $acknowledgement = $acknowledgement !== '' ? $acknowledgement : 'http:'.$event['id'];

        $eventHeader = trim((string) config('qtfoods.outbox.acknowledged_event_header', 'X-Acknowledged-Event-ID'));
        $acknowledgedEvent = trim((string) $response->header($eventHeader));
        if ($acknowledgedEvent === '' && is_array($json)) {
            $acknowledgedEvent = trim((string) ($json['acknowledged_event_id'] ?? $json['event_id'] ?? ''));
        }
        if (config('qtfoods.outbox.require_bound_acknowledgement', true)
            && ($acknowledgedEvent === '' || ! hash_equals((string) $event['id'], $acknowledgedEvent))) {
            throw new RuntimeException('Outbox receiver acknowledgement was not bound to the delivered event identifier.');
        }

        return [
            'acknowledgement_id' => mb_substr($acknowledgement, 0, 255),
            'response' => [
                'transport' => 'http',
                'http_status' => $response->status(),
                'acknowledgement_received' => true,
                'acknowledged_event_id' => $acknowledgedEvent,
                'receiver' => $this->receiverEvidence($json),
            ],
        ];
    }

    private function receiverEvidence(mixed $json): array
    {
        if (! is_array($json)) {
            return [];
        }

        return collect($json)
            ->only(['accepted', 'idempotent_replay', 'receiver', 'processed_at'])
            ->filter(static fn (mixed $value): bool => is_scalar($value) || $value === null)
            ->all();
    }
}

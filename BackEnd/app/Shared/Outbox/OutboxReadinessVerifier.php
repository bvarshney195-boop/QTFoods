<?php

namespace App\Shared\Outbox;

use App\Shared\Observability\OperationalSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class OutboxReadinessVerifier
{
    public function __construct(private readonly OperationalSnapshot $snapshot) {}

    public function verify(string $eventId, array $requirements = []): array
    {
        $issues = [];
        $event = DB::table('outbox_events')->where('id', $eventId)->first();
        $attempts = DB::table('outbox_delivery_attempts')->where('outbox_event_id', $eventId)
            ->orderBy('attempt_number')->get();

        if (! $event) {
            return $this->result($eventId, [
                $this->issue('outbox.event_missing', 'The synthetic outbox event was not found.'),
            ], []);
        }

        if ($event->status !== 'DELIVERED' || ! $event->acknowledged_at || trim((string) $event->acknowledgement_id) === '') {
            $issues[] = $this->issue('outbox.external_ack_missing', 'The event does not have retained delivery and receiver acknowledgement evidence.');
        }
        if ($attempts->isEmpty() || $attempts->contains(static fn (object $attempt): bool => $attempt->transport !== 'http')) {
            $issues[] = $this->issue('outbox.non_http_attempt', 'Every probe attempt must use the configured HTTP receiver.');
        }

        $delivered = $attempts->where('outcome', 'DELIVERED')->values();
        $bound = $delivered->every(function (object $attempt) use ($eventId): bool {
            $response = $this->json($attempt->response_json);

            return ($response['acknowledged_event_id'] ?? null) === $eventId;
        });
        if ($delivered->isEmpty() || ! $bound) {
            $issues[] = $this->issue('outbox.ack_not_bound', 'A delivered attempt is missing an acknowledgement bound to this event identifier.');
        }

        if ((bool) ($requirements['failure_lifecycle'] ?? false)) {
            $outcomes = $attempts->pluck('outcome')->all();
            foreach (['RETRY', 'QUARANTINED', 'DELIVERED'] as $requiredOutcome) {
                if (! in_array($requiredOutcome, $outcomes, true)) {
                    $issues[] = $this->issue('outbox.failure_lifecycle_incomplete', "The probe has no {$requiredOutcome} attempt.");
                }
            }
        }

        if ((bool) ($requirements['idempotent_replay'] ?? false)) {
            $acknowledgements = $delivered->pluck('acknowledgement_id')->filter()->unique()->values();
            $receiverReportedReplay = $delivered->contains(function (object $attempt): bool {
                $response = $this->json($attempt->response_json);

                return ($response['receiver']['idempotent_replay'] ?? false) === true;
            });
            if ($delivered->count() < 2 || $acknowledgements->count() !== 1 || ! $receiverReportedReplay) {
                $issues[] = $this->issue('outbox.idempotent_replay_unproven', 'Replay must retain one acknowledgement and receiver-confirmed idempotency across at least two deliveries.');
            }
        }

        if ((bool) ($requirements['worker_restart'] ?? false) && $attempts->pluck('worker_id')->unique()->count() < 2) {
            $issues[] = $this->issue('outbox.worker_restart_unproven', 'The retained attempts do not show processing by distinct worker instances.');
        }

        $operational = $this->snapshot->capture();
        $oldestDue = $operational['outbox']['oldest_due_age_seconds'] ?? null;
        $threshold = (int) config('observability.monitoring.thresholds.outbox_oldest_due_seconds', 0);
        if (! is_int($oldestDue) || $threshold < 1) {
            $issues[] = $this->issue('outbox.backlog_age_unmonitored', 'Oldest-due backlog age monitoring is not active with a positive alert threshold.');
        }

        $evidencePath = trim((string) ($requirements['evidence_path'] ?? ''));
        $evidenceSha256 = strtolower(trim((string) ($requirements['evidence_sha256'] ?? '')));
        if ($evidencePath !== '' || $evidenceSha256 !== '') {
            $disk = (string) config('qtfoods.evidence_disk', 'evidence');
            if ($evidencePath === '' || preg_match('/^[0-9a-f]{64}$/', $evidenceSha256) !== 1
                || ! Storage::disk($disk)->exists($evidencePath)) {
                $issues[] = $this->issue('outbox.durable_evidence_missing', 'The durable evidence probe is absent or its expected SHA-256 is invalid.');
            } else {
                $actualSha256 = hash('sha256', Storage::disk($disk)->get($evidencePath));
                if (! hash_equals($evidenceSha256, $actualSha256)) {
                    $issues[] = $this->issue('outbox.durable_evidence_mismatch', 'The durable evidence probe did not survive with the same SHA-256.');
                }
            }
        }

        return $this->result($eventId, $issues, [
            'event_status' => $event->status,
            'acknowledgement_id' => $event->acknowledgement_id,
            'attempts' => $attempts->count(),
            'outcomes' => $attempts->pluck('outcome')->values()->all(),
            'worker_instances' => $attempts->pluck('worker_id')->unique()->count(),
            'http_attempts' => $attempts->where('transport', 'http')->count(),
            'oldest_due_age_seconds' => $oldestDue,
            'oldest_due_alert_threshold_seconds' => $threshold,
            'evidence_path' => $evidencePath !== '' ? $evidencePath : null,
            'evidence_sha256' => $evidenceSha256 !== '' ? $evidenceSha256 : null,
        ]);
    }

    private function json(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (! is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function issue(string $code, string $message): array
    {
        return compact('code', 'message');
    }

    private function result(string $eventId, array $issues, array $evidence): array
    {
        return [
            'status' => $issues === [] ? 'pass' : 'fail',
            'checked_at' => now()->utc()->toIso8601String(),
            'event_id' => $eventId,
            'evidence' => $evidence,
            'issues' => $issues,
        ];
    }
}

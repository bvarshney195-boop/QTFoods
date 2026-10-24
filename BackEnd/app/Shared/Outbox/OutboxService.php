<?php

namespace App\Shared\Outbox;

use App\Shared\Observability\RequestContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class OutboxService
{
    public function __construct(private readonly RequestContext $requestContext) {}

    public function append(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        string $businessKey,
        array $payload,
        ?string $correlationId = null,
        ?string $companyId = null,
        ?string $plantId = null,
    ): string {
        $id = (string) Str::uuid();
        $correlationId ??= $this->requestContext->correlationId();
        [$companyId, $plantId] = $this->resolveScope(
            $aggregateId,
            $payload,
            $correlationId,
            $companyId,
            $plantId,
        );

        DB::table('outbox_events')->insert([
            'id' => $id,
            'event_type' => $eventType,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'business_key' => $businessKey,
            'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR),
            'request_id' => $this->requestContext->requestId(),
            'correlation_id' => $correlationId,
            'trace_id' => $this->requestContext->traceId(),
            'span_id' => $this->requestContext->spanId(),
            'company_id' => $companyId,
            'plant_id' => $plantId,
            'status' => 'PENDING',
            'attempts' => 0,
            'record_version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function resolveScope(
        string $aggregateId,
        array $payload,
        ?string $correlationId,
        ?string $companyId,
        ?string $plantId,
    ): array {
        $companyId ??= $this->findPayloadValue($payload, 'company_id');
        $plantId ??= $this->findPayloadValue($payload, 'plant_id');

        if ($companyId !== null && $plantId !== null) {
            return [$companyId, $plantId];
        }

        $audit = null;
        if ($correlationId !== null) {
            $audit = DB::table('audit_events')
                ->where('correlation_id', $correlationId)
                ->orderByDesc('event_at')
                ->first(['company_id', 'plant_id']);
        }
        if (! $audit) {
            $audit = DB::table('audit_events')
                ->where('entity_id', $aggregateId)
                ->orderByDesc('event_at')
                ->first(['company_id', 'plant_id']);
        }

        return [
            $companyId ?? (is_string($audit?->company_id ?? null) ? $audit->company_id : null),
            $plantId ?? (is_string($audit?->plant_id ?? null) ? $audit->plant_id : null),
        ];
    }

    private function findPayloadValue(mixed $payload, string $key): ?string
    {
        if (! is_array($payload)) {
            return null;
        }
        if (isset($payload[$key]) && is_string($payload[$key])) {
            return $payload[$key];
        }
        foreach ($payload as $value) {
            $found = $this->findPayloadValue($value, $key);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }
}

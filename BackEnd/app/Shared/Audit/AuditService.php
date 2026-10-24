<?php

namespace App\Shared\Audit;

use App\Shared\Observability\RequestContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AuditService
{
    public function __construct(private readonly RequestContext $requestContext) {}

    public function record(
        string $command,
        string $entityType,
        string $entityId,
        string $actorId,
        string $companyId,
        ?string $plantId,
        string $outcome,
        array $context = [],
    ): string {
        $id = (string) Str::uuid();

        DB::table('audit_events')->insert([
            'id' => $id,
            'request_id' => $context['request_id'] ?? $this->requestContext->requestId(),
            'correlation_id' => $context['correlation_id'] ?? $this->requestContext->correlationId(),
            'trace_id' => $context['trace_id'] ?? $this->requestContext->traceId(),
            'span_id' => $context['span_id'] ?? $this->requestContext->spanId(),
            'command' => $command,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_version' => $context['entity_version'] ?? null,
            'actor_id' => $actorId,
            'company_id' => $companyId,
            'plant_id' => $plantId,
            'outcome' => $outcome,
            'reason_code' => $context['reason_code'] ?? null,
            'safe_diff_json' => isset($context['safe_diff'])
                ? json_encode($context['safe_diff'], JSON_THROW_ON_ERROR)
                : null,
            'event_at' => $context['event_at'] ?? now(),
            'posted_at' => now(),
            'created_at' => now(),
        ]);

        return $id;
    }
}

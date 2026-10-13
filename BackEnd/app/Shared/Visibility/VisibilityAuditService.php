<?php

namespace App\Shared\Visibility;

use App\Shared\Audit\AuditService;
use App\Shared\Observability\RequestContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class VisibilityAuditService
{
    public function __construct(
        private readonly AuditService $audit,
        private readonly RequestContext $requestContext,
    ) {}

    public function record(array $change): string
    {
        $id = (string) Str::uuid();
        $now = now();
        DB::table('transaction_visibility_events')->insert([
            'id' => $id,
            'visibility_id' => $change['visibility_id'],
            'transaction_type' => $change['transaction_type'],
            'transaction_id' => $change['transaction_id'],
            'module' => $change['module'],
            'previous_visibility' => $change['previous_visibility'],
            'new_visibility' => $change['new_visibility'],
            'actor_id' => $change['actor_id'],
            'company_id' => $change['company_id'],
            'plant_id' => $change['plant_id'],
            'change_source' => $change['change_source'],
            'reason' => $change['reason'],
            'request_id' => $this->requestContext->requestId(),
            'correlation_id' => $change['correlation_id'] ?? $this->requestContext->correlationId(),
            'changed_at' => $now,
            'created_at' => $now,
        ]);

        $this->audit->record(
            'CHANGE_TRANSACTION_VISIBILITY',
            strtolower($change['transaction_type']),
            $change['transaction_id'],
            $change['actor_id'],
            $change['company_id'],
            $change['plant_id'],
            'SUCCEEDED',
            [
                'entity_version' => $change['record_version'],
                'correlation_id' => $change['correlation_id'] ?? null,
                'safe_diff' => [
                    'module' => $change['module'],
                    'visibility_status' => [
                        'from' => $change['previous_visibility'],
                        'to' => $change['new_visibility'],
                    ],
                    'change_source' => $change['change_source'],
                    'reason' => $change['reason'],
                    'visibility_event_id' => $id,
                ],
            ],
        );

        return $id;
    }
}

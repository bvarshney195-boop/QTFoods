<?php

namespace App\Modules\Control\Application;

use App\Shared\Audit\AuditService;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AuditQuery
{
    public const OUTCOMES = ['SUCCESS', 'FAILURE', 'DENIED'];
    public const SORTS = ['NEWEST', 'OLDEST'];

    public function __construct(private readonly AuditService $audit) {}

    public function search(array $scope, array $filters, array $permissions): array
    {
        $base = $this->scoped($scope);
        $filtered = clone $base;
        $this->applyFilters($filtered, $filters);

        $summaryQuery = clone $base;
        $summary = [
            'total' => (clone $summaryQuery)->count(),
            'success' => (clone $summaryQuery)->where('audit.outcome', 'SUCCESS')->count(),
            'failure' => (clone $summaryQuery)->where('audit.outcome', '<>', 'SUCCESS')->count(),
            'evidence' => DB::table('unsold_return_evidence')
                ->where('company_id', $scope['company_id'])
                ->where('plant_id', $scope['plant_id'])
                ->count(),
        ];

        $events = $filtered
            ->select([
                'audit.id', 'audit.request_id', 'audit.correlation_id', 'audit.trace_id', 'audit.span_id', 'audit.command',
                'audit.entity_type', 'audit.entity_id', 'audit.entity_version', 'audit.actor_id',
                'actor.name as actor_name', 'actor.email as actor_email', 'audit.company_id',
                'audit.plant_id', 'audit.outcome', 'audit.reason_code', 'audit.event_at',
                'audit.posted_at', 'audit.created_at',
            ])
            ->selectSub($this->evidenceCountSubquery(), 'evidence_count')
            ->orderBy('audit.event_at', ($filters['sort'] ?? 'NEWEST') === 'OLDEST' ? 'asc' : 'desc')
            ->orderBy('audit.id', ($filters['sort'] ?? 'NEWEST') === 'OLDEST' ? 'asc' : 'desc')
            ->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($events->items())->map(fn (object $event) => $this->listPayload($event))->all(),
            'meta' => [
                'current_page' => $events->currentPage(),
                'last_page' => $events->lastPage(),
                'per_page' => $events->perPage(),
                'total' => $events->total(),
            ],
            'summary' => $summary,
            'lookups' => [
                'commands' => (clone $base)->distinct()->orderBy('audit.command')->pluck('audit.command')->all(),
                'entity_types' => (clone $base)->distinct()->orderBy('audit.entity_type')->pluck('audit.entity_type')->all(),
                'outcomes' => self::OUTCOMES,
            ],
            'allowed_actions' => $this->can($permissions, 'ACTION:ADM-AUD:EVIDENCE')
                ? ['VIEW_EVIDENCE']
                : [],
        ];
    }

    public function detail(string $auditId, array $scope, array $permissions): array
    {
        $event = $this->scoped($scope)
            ->where('audit.id', $auditId)
            ->first([
                'audit.*', 'actor.name as actor_name', 'actor.email as actor_email',
                'company.code as company_code', 'company.display_name as company_name',
                'plant.code as plant_code', 'plant.name as plant_name',
            ]);

        if (! $event) {
            throw new NotFoundHttpException('Audit event not found.');
        }

        return $this->detailPayload($event, $permissions);
    }

    public function evidence(
        string $auditId,
        string $evidenceId,
        array $scope,
        string $actorId,
        ?string $correlationId,
    ): array {
        $event = $this->scoped($scope)->where('audit.id', $auditId)
            ->first(['audit.id', 'audit.entity_type', 'audit.entity_id']);
        if (! $event) {
            throw new NotFoundHttpException('Audit event not found.');
        }

        $evidence = $this->linkedEvidenceQuery($event, $scope)
            ->where('evidence.id', $evidenceId)->first();
        if (! $evidence || ! Storage::disk($evidence->storage_disk)->exists($evidence->storage_path)) {
            throw new NotFoundHttpException('Audit evidence not found.');
        }

        $this->audit->record(
            'VIEW_AUDIT_EVIDENCE',
            'unsold_return_evidence',
            (string) $evidence->id,
            $actorId,
            (string) $evidence->company_id,
            (string) $evidence->plant_id,
            'SUCCESS',
            [
                'entity_version' => (int) $evidence->case_record_version,
                'correlation_id' => $correlationId,
                'reason_code' => (string) $evidence->category,
                'safe_diff' => [
                    'source_audit_event_id' => $auditId,
                    'return_case_id' => (string) $evidence->return_case_id,
                    'sha256' => (string) $evidence->sha256,
                ],
            ]
        );

        return [
            'storage_disk' => (string) $evidence->storage_disk,
            'storage_path' => (string) $evidence->storage_path,
            'original_name' => (string) $evidence->original_name,
            'mime_type' => (string) $evidence->mime_type,
        ];
    }

    private function scoped(array $scope): Builder
    {
        return DB::table('audit_events as audit')
            ->leftJoin('users as actor', 'actor.id', '=', 'audit.actor_id')
            ->leftJoin('companies as company', 'company.id', '=', 'audit.company_id')
            ->leftJoin('plants as plant', 'plant.id', '=', 'audit.plant_id')
            ->where('audit.company_id', $scope['company_id'])
            ->where('audit.plant_id', $scope['plant_id']);
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $q = trim((string) ($filters['q'] ?? ''));
        if ($q !== '') {
            $needle = '%'.mb_strtolower($q).'%';
            $query->where(function (Builder $query) use ($needle): void {
                foreach ([
                    'audit.command', 'audit.entity_type', 'audit.entity_id', 'audit.request_id',
                    'audit.correlation_id', 'audit.trace_id', 'audit.reason_code', 'actor.name', 'actor.email',
                ] as $column) {
                    $query->orWhereRaw('LOWER(COALESCE(CAST('.$column." AS TEXT), '')) LIKE ?", [$needle]);
                }
            });
        }

        foreach (['command', 'entity_type', 'outcome', 'actor_id', 'entity_id', 'request_id', 'correlation_id', 'trace_id'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where('audit.'.$field, $filters[$field]);
            }
        }
        if (! empty($filters['from'])) {
            $query->where('audit.event_at', '>=', CarbonImmutable::parse($filters['from'])->startOfDay());
        }
        if (! empty($filters['to'])) {
            $query->where('audit.event_at', '<=', CarbonImmutable::parse($filters['to'])->endOfDay());
        }
    }

    private function listPayload(object $event): array
    {
        return [
            'id' => (string) $event->id,
            'request_id' => $event->request_id ? (string) $event->request_id : null,
            'correlation_id' => $event->correlation_id ? (string) $event->correlation_id : null,
            'trace_id' => $event->trace_id ? (string) $event->trace_id : null,
            'span_id' => $event->span_id ? (string) $event->span_id : null,
            'command' => (string) $event->command,
            'entity_type' => (string) $event->entity_type,
            'entity_id' => (string) $event->entity_id,
            'entity_version' => $event->entity_version === null ? null : (int) $event->entity_version,
            'actor' => [
                'id' => (string) $event->actor_id,
                'name' => $event->actor_name ?: 'Unknown actor',
                'email' => $event->actor_email,
            ],
            'outcome' => (string) $event->outcome,
            'reason_code' => $event->reason_code,
            'evidence_count' => (int) $event->evidence_count,
            'event_at' => $this->timestamp($event->event_at),
            'posted_at' => $this->timestamp($event->posted_at),
        ];
    }

    private function detailPayload(object $event, array $permissions): array
    {
        $evidence = $this->linkedEvidenceQuery($event, [
            'company_id' => (string) $event->company_id,
            'plant_id' => (string) $event->plant_id,
        ])->orderBy('evidence.uploaded_at')->get()->map(fn (object $item) => [
            'id' => (string) $item->id,
            'return_case_id' => (string) $item->return_case_id,
            'category' => (string) $item->category,
            'original_name' => (string) $item->original_name,
            'mime_type' => (string) $item->mime_type,
            'size_bytes' => (int) $item->size_bytes,
            'sha256' => (string) $item->sha256,
            'notes' => $item->notes,
            'retention_policy' => (string) $item->retention_policy,
            'retention_until' => CarbonImmutable::parse((string) $item->retention_until)->toDateString(),
            'legal_hold' => (bool) $item->legal_hold,
            'uploaded_at' => $this->timestamp($item->uploaded_at),
            'uploaded_by' => [
                'id' => (string) $item->uploaded_by,
                'name' => $item->uploader_name ?: 'Unknown actor',
            ],
            'allowed_actions' => $this->can($permissions, 'ACTION:ADM-AUD:EVIDENCE')
                ? ['VIEW', 'DOWNLOAD']
                : [],
        ])->all();

        return $this->listPayload((object) array_merge((array) $event, [
            'evidence_count' => count($evidence),
        ])) + [
            'company' => [
                'id' => (string) $event->company_id,
                'code' => $event->company_code,
                'name' => $event->company_name,
            ],
            'plant' => [
                'id' => (string) $event->plant_id,
                'code' => $event->plant_code,
                'name' => $event->plant_name,
            ],
            'safe_diff' => $this->json($event->safe_diff_json),
            'evidence' => $evidence,
            'created_at' => $this->timestamp($event->created_at),
        ];
    }

    private function linkedEvidenceQuery(object $event, array $scope): Builder
    {
        return DB::table('unsold_return_evidence as evidence')
            ->leftJoin('users as uploader', 'uploader.id', '=', 'evidence.uploaded_by')
            ->where('evidence.company_id', $scope['company_id'])
            ->where('evidence.plant_id', $scope['plant_id'])
            ->where(function (Builder $query) use ($event): void {
                $query->where('evidence.upload_audit_event_id', $event->id);
                if ($event->entity_type === 'unsold_return_evidence') {
                    $query->orWhere('evidence.id', $event->entity_id);
                }
                if (in_array($event->entity_type, ['unsold_return_case', 'unsold_return_loss'], true)) {
                    $query->orWhere('evidence.return_case_id', $event->entity_id);
                }
            })
            ->select(['evidence.*', 'uploader.name as uploader_name']);
    }

    private function evidenceCountSubquery(): Builder
    {
        return DB::table('unsold_return_evidence as related_evidence')
            ->selectRaw('COUNT(*)')
            ->whereColumn('related_evidence.company_id', 'audit.company_id')
            ->whereColumn('related_evidence.plant_id', 'audit.plant_id')
            ->where(function (Builder $query): void {
                $query->whereColumn('related_evidence.upload_audit_event_id', 'audit.id')
                    ->orWhere(function (Builder $query): void {
                        $query->where('audit.entity_type', 'unsold_return_evidence')
                            ->whereColumn('related_evidence.id', 'audit.entity_id');
                    })
                    ->orWhere(function (Builder $query): void {
                        $query->whereIn('audit.entity_type', ['unsold_return_case', 'unsold_return_loss'])
                            ->whereColumn('related_evidence.return_case_id', 'audit.entity_id');
                    });
            });
    }

    private function json(mixed $value): mixed
    {
        if ($value === null || is_array($value)) {
            return $value;
        }

        return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }

    private function timestamp(mixed $value): string
    {
        return CarbonImmutable::parse((string) $value)->utc()->toIso8601String();
    }

    private function can(array $permissions, string $permission): bool
    {
        return in_array($permission, $permissions, true);
    }
}

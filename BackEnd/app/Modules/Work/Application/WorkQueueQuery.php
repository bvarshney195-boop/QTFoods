<?php

namespace App\Modules\Work\Application;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WorkQueueQuery
{
    public const STATUSES = ['OPEN', 'COMPLETED', 'CANCELLED', 'ALL'];
    public const ASSIGNMENTS = ['ALL', 'MINE', 'UNASSIGNED'];
    public const SORTS = [
        'priority', '-priority',
        'due_at', '-due_at',
        'created_at', '-created_at',
    ];

    public function paginate(
        array $scope,
        array $filters,
        string $actorId,
        array $permissions,
    ): array {
        $query = $this->visible($scope, $actorId, $permissions);
        $this->applyFilters($query, $filters, $actorId);
        $this->applySort($query, (string) ($filters['sort'] ?? 'priority'));

        $paginator = $query->paginate(
            (int) ($filters['per_page'] ?? 25),
            ['*'],
            'page',
            (int) ($filters['page'] ?? 1)
        );
        $paginator->appends(Arr::except($filters, ['page']));

        return [
            'data' => collect($paginator->items())
                ->map(fn (object $item) => $this->item($item, $actorId, $permissions))
                ->values()
                ->all(),
            'summary' => $this->summary($scope, $actorId, $permissions),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ];
    }

    private function visible(array $scope, string $actorId, array $permissions): Builder
    {
        $canManage = in_array('ACTION:WRK-HOME:MANAGE', $permissions, true);

        return DB::table('work_items as work')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'work.assigned_user_id')
            ->leftJoin('users as creator', 'creator.id', '=', 'work.created_by')
            ->leftJoin('users as completer', 'completer.id', '=', 'work.completed_by')
            ->where('work.company_id', $scope['company_id'])
            ->when(
                $scope['plant_id'] ?? null,
                fn (Builder $query, string $plantId) => $query->where('work.plant_id', $plantId)
            )
            ->where(function (Builder $query) use ($permissions) {
                $query->whereNull('work.required_permission');
                if ($permissions !== []) {
                    $query->orWhereIn('work.required_permission', $permissions);
                }
            })
            ->when(! $canManage, fn (Builder $query) => $query->where(
                fn (Builder $query) => $query
                    ->whereNull('work.assigned_user_id')
                    ->orWhere('work.assigned_user_id', $actorId)
            ))
            ->select([
                'work.id',
                'work.company_id',
                'work.plant_id',
                'work.kind',
                'work.title',
                'work.description',
                'work.priority',
                'work.status',
                'work.assigned_user_id',
                'assignee.name as assignee_name',
                'assignee.email as assignee_email',
                'work.required_permission',
                'work.source_type',
                'work.source_id',
                'work.target_screen_code',
                'work.target_record_id',
                'work.due_at',
                'work.completed_at',
                'work.completed_by',
                'completer.name as completer_name',
                'work.completion_note',
                'work.created_by',
                'creator.name as creator_name',
                'work.record_version',
                'work.created_at',
                'work.updated_at',
            ]);
    }

    private function applyFilters(Builder $query, array $filters, string $actorId): void
    {
        $status = (string) ($filters['status'] ?? 'OPEN');
        if ($status !== 'ALL') {
            $query->where('work.status', $status);
        }

        $query->when(
            $filters['kind'] ?? null,
            fn (Builder $query, string $kind) => $query->where('work.kind', $kind)
        );
        $query->when(
            $filters['priority'] ?? null,
            fn (Builder $query, string $priority) => $query->where('work.priority', $priority)
        );

        $assignment = (string) ($filters['assignment'] ?? 'ALL');
        if ($assignment === 'MINE') {
            $query->where('work.assigned_user_id', $actorId);
        } elseif ($assignment === 'UNASSIGNED') {
            $query->whereNull('work.assigned_user_id');
        }

        if (($filters['overdue'] ?? false) === true) {
            $query
                ->where('work.status', 'OPEN')
                ->whereNotNull('work.due_at')
                ->where('work.due_at', '<', now());
        }

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $query) use ($search) {
            $pattern = '%'.Str::lower($search).'%';
            $query
                ->whereRaw('LOWER(work.title) LIKE ?', [$pattern])
                ->orWhereRaw('LOWER(COALESCE(work.description, ?)) LIKE ?', ['', $pattern])
                ->orWhereRaw('LOWER(COALESCE(work.source_type, ?)) LIKE ?', ['', $pattern]);

            if (Str::isUuid($search)) {
                $query
                    ->orWhere('work.id', $search)
                    ->orWhere('work.source_id', $search)
                    ->orWhere('work.target_record_id', $search);
            }
        });
    }

    private function applySort(Builder $query, string $sort): void
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $field = ltrim($sort, '-');

        if ($field === 'priority') {
            $query->orderByRaw(
                "CASE work.priority WHEN 'URGENT' THEN 0 WHEN 'HIGH' THEN 1 WHEN 'NORMAL' THEN 2 ELSE 3 END {$direction}"
            );
            $query
                ->orderByRaw('CASE WHEN work.due_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('work.due_at')
                ->orderBy('work.created_at');
        } elseif ($field === 'due_at') {
            $query
                ->orderByRaw('CASE WHEN work.due_at IS NULL THEN 1 ELSE 0 END')
                ->orderBy('work.due_at', $direction)
                ->orderBy('work.created_at', $direction);
        } else {
            $query->orderBy('work.created_at', $direction);
        }

        $query->orderBy('work.id', $direction);
    }

    private function summary(array $scope, string $actorId, array $permissions): array
    {
        $open = $this->visible($scope, $actorId, $permissions)->where('work.status', 'OPEN');
        $visible = $this->visible($scope, $actorId, $permissions);
        $sevenDaysAgo = now()->subDays(7);
        $createdSevenDays = (clone $visible)->where('work.created_at', '>=', $sevenDaysAgo)->count();
        $completedSevenDays = (clone $visible)
            ->where('work.status', 'COMPLETED')
            ->where('work.completed_at', '>=', $sevenDaysAgo)
            ->count();

        return [
            'open_total' => (clone $open)->count(),
            'assigned_to_me' => (clone $open)->where('work.assigned_user_id', $actorId)->count(),
            'unassigned' => (clone $open)->whereNull('work.assigned_user_id')->count(),
            'approvals' => (clone $open)->where('work.kind', 'APPROVAL')->count(),
            'exceptions' => (clone $open)->where('work.kind', 'EXCEPTION')->count(),
            'overdue' => (clone $open)
                ->whereNotNull('work.due_at')
                ->where('work.due_at', '<', now())
                ->count(),
            'high_priority' => (clone $open)->whereIn('work.priority', ['URGENT', 'HIGH'])->count(),
            'older_than_three_days' => (clone $open)->where('work.created_at', '<', now()->subDays(3))->count(),
            'created_7d' => $createdSevenDays,
            'completed_7d' => $completedSevenDays,
            'closure_rate_7d' => $createdSevenDays === 0
                ? ($completedSevenDays > 0 ? 100 : 0)
                : min(100, (int) round(($completedSevenDays / $createdSevenDays) * 100)),
        ];
    }

    private function item(object $item, string $actorId, array $permissions): array
    {
        $now = CarbonImmutable::now();
        $createdAt = CarbonImmutable::parse((string) $item->created_at);
        $dueAt = $item->due_at === null ? null : CarbonImmutable::parse((string) $item->due_at);
        $ageMinutes = max(0, (int) floor($createdAt->diffInMinutes($now, true)));
        $isOpen = $item->status === 'OPEN';
        $canManage = in_array('ACTION:WRK-HOME:MANAGE', $permissions, true);
        $actions = [];

        if (
            $isOpen
            && $item->assigned_user_id === null
            && in_array('ACTION:WRK-HOME:CLAIM', $permissions, true)
        ) {
            $actions[] = 'CLAIM';
        }

        if (
            $isOpen
            && $item->kind !== 'APPROVAL'
            && $item->assigned_user_id !== null
            && ((string) $item->assigned_user_id === $actorId || $canManage)
            && in_array('ACTION:WRK-HOME:COMPLETE', $permissions, true)
        ) {
            $actions[] = 'COMPLETE';
        }

        if ($isOpen && $canManage) {
            $actions[] = 'ASSIGN';
        }

        $target = null;
        $screenPermission = $item->target_screen_code === null
            ? null
            : 'SCREEN:'.$item->target_screen_code.':VIEW';
        if ($item->target_screen_code !== null && in_array($screenPermission, $permissions, true)) {
            $href = (string) $item->target_screen_code;
            if ($item->target_record_id !== null) {
                $href .= '?record='.rawurlencode((string) $item->target_record_id);
            }

            $target = [
                'screen_code' => $item->target_screen_code,
                'record_id' => $item->target_record_id === null ? null : (string) $item->target_record_id,
                'href' => $href,
            ];
            $actions[] = 'OPEN';
        }

        return [
            'id' => (string) $item->id,
            'kind' => $item->kind,
            'title' => $item->title,
            'description' => $item->description,
            'priority' => $item->priority,
            'status' => $item->status,
            'assignee' => $item->assigned_user_id === null ? null : [
                'id' => (string) $item->assigned_user_id,
                'name' => $item->assignee_name,
                'email' => $item->assignee_email,
            ],
            'source' => $item->source_type === null ? null : [
                'type' => $item->source_type,
                'id' => $item->source_id === null ? null : (string) $item->source_id,
            ],
            'target' => $target,
            'due_at' => $this->timestamp($item->due_at),
            'is_overdue' => $isOpen && $dueAt !== null && $dueAt->isPast(),
            'age_minutes' => $ageMinutes,
            'age_bucket' => $this->ageBucket($ageMinutes),
            'creator' => [
                'id' => (string) $item->created_by,
                'name' => $item->creator_name,
            ],
            'completed_at' => $this->timestamp($item->completed_at),
            'completed_by' => $item->completed_by === null ? null : [
                'id' => (string) $item->completed_by,
                'name' => $item->completer_name,
            ],
            'completion_note' => $item->completion_note,
            'record_version' => (int) $item->record_version,
            'allowed_actions' => $actions,
            'created_at' => $createdAt->toISOString(),
            'updated_at' => $this->timestamp($item->updated_at),
        ];
    }

    private function ageBucket(int $ageMinutes): string
    {
        return match (true) {
            $ageMinutes < 240 => 'UNDER_4_HOURS',
            $ageMinutes < 1440 => 'TODAY',
            $ageMinutes < 4320 => 'ONE_TO_THREE_DAYS',
            default => 'OVER_THREE_DAYS',
        };
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }
}

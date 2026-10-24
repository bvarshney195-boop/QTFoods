<?php

namespace App\Modules\Foundation\Application;

use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class HelpSupportQuery
{
    public const CATEGORIES = ['ACCESS', 'DATA', 'WORKFLOW', 'INTEGRATION', 'REPORTING', 'OTHER'];
    public const PRIORITIES = ['LOW', 'NORMAL', 'HIGH', 'CRITICAL'];
    public const STATUSES = ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED'];
    public const ARTICLE_CATEGORIES = ['GETTING_STARTED', 'WORKFLOWS', 'CONTROLS', 'SECURITY', 'REPORTING'];

    public function workspace(
        array $scope,
        string $actorId,
        array $permissions,
        array $filters = [],
    ): array {
        $manage = in_array('ACTION:ADM-HELP:MANAGE', $permissions, true);
        $screenCodes = $this->screenCodes($permissions);
        $articles = DB::table('help_articles')->where('status', 'PUBLISHED')
            ->where(function ($query) use ($screenCodes): void {
                $query->whereNull('related_screen_code');
                if ($screenCodes !== []) $query->orWhereIn('related_screen_code', $screenCodes);
            });
        if (! empty($filters['article_category'])) $articles->where('category', $filters['article_category']);
        if (! empty($filters['q'])) {
            $search = '%'.$filters['q'].'%';
            $articles->where(function ($query) use ($search): void {
                $query->where('title', 'like', $search)->orWhere('summary', 'like', $search)
                    ->orWhere('search_terms', 'like', $search);
            });
        }

        $cases = DB::table('help_support_cases as support_case')
            ->join('users as requester', 'requester.id', '=', 'support_case.requester_id')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'support_case.assigned_to')
            ->where('support_case.company_id', $scope['company_id'])
            ->where('support_case.plant_id', $scope['plant_id']);
        if (! $manage) $cases->where('support_case.requester_id', $actorId);
        if (! empty($filters['case_status'])) $cases->where('support_case.status', $filters['case_status']);
        if (! empty($filters['q'])) {
            $search = '%'.$filters['q'].'%';
            $cases->where(function ($query) use ($search): void {
                $query->where('support_case.case_number', 'like', $search)
                    ->orWhere('support_case.subject', 'like', $search)
                    ->orWhere('support_case.description', 'like', $search);
            });
        }

        $caseRows = $cases->orderByRaw("CASE support_case.priority WHEN 'CRITICAL' THEN 1 WHEN 'HIGH' THEN 2 WHEN 'NORMAL' THEN 3 ELSE 4 END")
            ->orderByDesc('support_case.created_at')->limit(150)->get([
                'support_case.*', 'requester.name as requester_name', 'assignee.name as assignee_name',
            ])->map(fn (object $row): array => $this->casePayload($row, $actorId, $permissions))->all();
        $visibleCases = DB::table('help_support_cases')->where($scope);
        if (! $manage) $visibleCases->where('requester_id', $actorId);

        return [
            'articles' => $articles->orderBy('category')->orderBy('title')->get()->map(
                fn (object $row): array => $this->articlePayload($row, false),
            )->all(),
            'cases' => $caseRows,
            'summary' => [
                'published_articles' => DB::table('help_articles')->where('status', 'PUBLISHED')
                    ->where(function ($query) use ($screenCodes): void {
                        $query->whereNull('related_screen_code');
                        if ($screenCodes !== []) $query->orWhereIn('related_screen_code', $screenCodes);
                    })->count(),
                'visible_cases' => (clone $visibleCases)->count(),
                'open_cases' => (clone $visibleCases)->whereIn('status', ['OPEN', 'IN_PROGRESS'])->count(),
                'critical_open_cases' => (clone $visibleCases)->where('priority', 'CRITICAL')
                    ->whereIn('status', ['OPEN', 'IN_PROGRESS'])->count(),
            ],
            'lookups' => [
                'case_categories' => self::CATEGORIES,
                'priorities' => self::PRIORITIES,
                'statuses' => self::STATUSES,
                'article_categories' => self::ARTICLE_CATEGORIES,
                'screen_codes' => $screenCodes,
            ],
            'allowed_actions' => $this->screenActions($permissions),
            'is_support_manager' => $manage,
        ];
    }

    public function article(string $slug, array $permissions): array
    {
        $article = DB::table('help_articles')->where('slug', $slug)->where('status', 'PUBLISHED')->first();
        if (! $article || ($article->related_screen_code && ! in_array(
            'SCREEN:'.$article->related_screen_code.':VIEW', $permissions, true,
        ))) {
            throw new NotFoundHttpException('Help article not found.');
        }

        return $this->articlePayload($article, true);
    }

    public function supportCase(string $id, array $scope, string $actorId, array $permissions): array
    {
        $manage = in_array('ACTION:ADM-HELP:MANAGE', $permissions, true);
        $query = DB::table('help_support_cases as support_case')
            ->join('users as requester', 'requester.id', '=', 'support_case.requester_id')
            ->leftJoin('users as assignee', 'assignee.id', '=', 'support_case.assigned_to')
            ->leftJoin('users as resolver', 'resolver.id', '=', 'support_case.resolved_by')
            ->leftJoin('users as closer', 'closer.id', '=', 'support_case.closed_by')
            ->where('support_case.id', $id)->where('support_case.company_id', $scope['company_id'])
            ->where('support_case.plant_id', $scope['plant_id']);
        if (! $manage) $query->where('support_case.requester_id', $actorId);
        $case = $query->first([
            'support_case.*', 'requester.name as requester_name', 'assignee.name as assignee_name',
            'resolver.name as resolver_name', 'closer.name as closer_name',
        ]);
        if (! $case) throw new NotFoundHttpException('Support case not found.');

        $payload = $this->casePayload($case, $actorId, $permissions);
        $payload['resolved_by'] = $case->resolved_by ? ['id' => $case->resolved_by, 'name' => $case->resolver_name] : null;
        $payload['closed_by'] = $case->closed_by ? ['id' => $case->closed_by, 'name' => $case->closer_name] : null;
        $payload['events'] = DB::table('help_support_case_events as event')
            ->join('users as actor', 'actor.id', '=', 'event.actor_id')
            ->where('event.help_support_case_id', $id)->orderBy('event.sequence_number')
            ->get([
                'event.id', 'event.sequence_number', 'event.event_type', 'event.status_from', 'event.status_to',
                'event.message', 'event.actor_id', 'event.created_at', 'actor.name as actor_name',
            ])->map(fn (object $event): array => [
                'id' => $event->id,
                'sequence_number' => (int) $event->sequence_number,
                'event_type' => $event->event_type,
                'status_from' => $event->status_from,
                'status_to' => $event->status_to,
                'message' => $event->message,
                'actor' => ['id' => $event->actor_id, 'name' => $event->actor_name],
                'created_at' => $event->created_at,
            ])->all();

        return $payload;
    }

    private function articlePayload(object $article, bool $withBody): array
    {
        $payload = [
            'id' => $article->id,
            'slug' => $article->slug,
            'category' => $article->category,
            'related_screen_code' => $article->related_screen_code,
            'title' => $article->title,
            'summary' => $article->summary,
            'content_version' => (int) $article->content_version,
            'published_at' => $article->published_at,
            'updated_at' => $article->updated_at,
        ];
        if ($withBody) $payload['sections'] = $this->json($article->body_json);

        return $payload;
    }

    private function casePayload(object $case, string $actorId, array $permissions): array
    {
        return [
            'id' => $case->id,
            'case_number' => $case->case_number,
            'category' => $case->category,
            'priority' => $case->priority,
            'affected_screen_code' => $case->affected_screen_code,
            'subject' => $case->subject,
            'description' => $case->description,
            'status' => $case->status,
            'record_version' => (int) $case->record_version,
            'requester' => ['id' => $case->requester_id, 'name' => $case->requester_name],
            'assigned_to' => $case->assigned_to ? ['id' => $case->assigned_to, 'name' => $case->assignee_name] : null,
            'resolution_summary' => $case->resolution_summary,
            'resolved_at' => $case->resolved_at,
            'closed_at' => $case->closed_at,
            'created_at' => $case->created_at,
            'updated_at' => $case->updated_at,
            'allowed_actions' => $this->caseActions($case, $actorId, $permissions),
        ];
    }

    private function caseActions(object $case, string $actorId, array $permissions): array
    {
        $manage = in_array('ACTION:ADM-HELP:MANAGE', $permissions, true);
        $owner = $case->requester_id === $actorId;
        $actions = [];
        if ($case->status !== 'CLOSED' && ($owner || $manage) && in_array('ACTION:ADM-HELP:COMMENT', $permissions, true)) {
            $actions[] = 'COMMENT';
        }
        if ($case->status === 'OPEN' && $manage) $actions[] = 'START';
        if ($case->status === 'IN_PROGRESS' && $manage) $actions[] = 'RESOLVE';
        if ($case->status === 'RESOLVED' && ($owner || $manage)) {
            if (in_array('ACTION:ADM-HELP:REOPEN', $permissions, true)) $actions[] = 'REOPEN';
            if (in_array('ACTION:ADM-HELP:CLOSE', $permissions, true)) $actions[] = 'CLOSE';
        }

        return $actions;
    }

    private function screenCodes(array $permissions): array
    {
        return collect($permissions)->filter(
            fn (string $permission): bool => str_starts_with($permission, 'SCREEN:') && str_ends_with($permission, ':VIEW'),
        )->map(fn (string $permission): string => substr($permission, 7, -5))->sort()->values()->all();
    }

    private function screenActions(array $permissions): array
    {
        $prefix = 'ACTION:ADM-HELP:';

        return collect($permissions)->filter(fn (string $permission): bool => str_starts_with($permission, $prefix))
            ->map(fn (string $permission): string => substr($permission, strlen($prefix)))->values()->all();
    }

    private function json(mixed $value): array
    {
        if (is_array($value)) return $value;

        return json_decode((string) $value, true, 512, JSON_THROW_ON_ERROR);
    }
}

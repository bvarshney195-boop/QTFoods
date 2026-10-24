<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Modules\Foundation\Application\HelpSupportQuery;
use App\Modules\Foundation\Application\HelpSupportService;
use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Domain\User;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class HelpSupportController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly HelpSupportQuery $query,
        private readonly HelpSupportService $service,
        private readonly SessionService $sessions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'article_category' => ['nullable', Rule::in(HelpSupportQuery::ARTICLE_CATEGORIES)],
            'case_status' => ['nullable', Rule::in(HelpSupportQuery::STATUSES)],
        ]);
        /** @var User $user */
        $user = $request->user();

        return response()->json($this->query->workspace(
            $this->selectedScope($request, true),
            (string) $user->id,
            $this->currentPermissions($request),
            $filters,
        ));
    }

    public function article(string $slug, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->article($slug, $this->currentPermissions($request))]);
    }

    public function supportCase(string $caseId, Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->query->supportCase(
            $caseId,
            $this->selectedScope($request, true),
            (string) $user->id,
            $this->currentPermissions($request),
        )]);
    }

    public function create(Request $request): JsonResponse
    {
        $input = $request->all();
        foreach (['category', 'priority', 'affected_screen_code'] as $field) {
            if (is_string($input[$field] ?? null)) $input[$field] = Str::upper(trim($input[$field]));
        }
        $request->replace($input);
        $validated = $request->validate([
            'category' => ['required', Rule::in(HelpSupportQuery::CATEGORIES)],
            'priority' => ['required', Rule::in(HelpSupportQuery::PRIORITIES)],
            'affected_screen_code' => ['nullable', 'string', 'max:32', 'regex:/^[A-Z0-9-]+$/'],
            'subject' => ['required', 'string', 'min:3', 'max:200'],
            'description' => ['required', 'string', 'min:10', 'max:8000'],
        ]);

        return response()->json(['data' => $this->service->create(
            $validated + $this->commandContext($request, false),
        )], 201);
    }

    public function comment(string $caseId, Request $request): JsonResponse
    {
        $validated = $request->validate(['message' => ['required', 'string', 'min:2', 'max:4000']]);

        return $this->ok($this->service->comment($caseId, $validated['message'], $this->commandContext($request, true)));
    }

    public function start(string $caseId, Request $request): JsonResponse
    {
        return $this->ok($this->service->start($caseId, $this->commandContext($request, true)));
    }

    public function resolve(string $caseId, Request $request): JsonResponse
    {
        $validated = $request->validate(['resolution_summary' => ['required', 'string', 'min:5', 'max:4000']]);

        return $this->ok($this->service->resolve(
            $caseId, $validated['resolution_summary'], $this->commandContext($request, true),
        ));
    }

    public function reopen(string $caseId, Request $request): JsonResponse
    {
        $validated = $request->validate(['reason' => ['required', 'string', 'min:5', 'max:4000']]);

        return $this->ok($this->service->reopen($caseId, $validated['reason'], $this->commandContext($request, true)));
    }

    public function close(string $caseId, Request $request): JsonResponse
    {
        $validated = $request->validate(['confirmation' => ['required', 'string', 'min:5', 'max:4000']]);

        return $this->ok($this->service->close($caseId, $validated['confirmation'], $this->commandContext($request, true)));
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }

    private function ok(array $data): JsonResponse
    {
        return response()->json(['data' => $data]);
    }
}

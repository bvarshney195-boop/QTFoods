<?php

namespace App\Modules\Control\Http\Controllers;

use App\Modules\Control\Application\OutboxOperationsService;
use App\Modules\Control\Application\OutboxQuery;
use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class OutboxController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly OutboxQuery $query,
        private readonly OutboxOperationsService $service,
        private readonly SessionService $sessions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'q' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(OutboxQuery::STATUSES)],
            'event_type' => ['sometimes', 'string', 'max:128'],
            'sort' => ['sometimes', 'string', Rule::in(OutboxQuery::SORTS)],
        ]);

        return response()->json($this->query->workspace(
            $this->selectedScope($request, true),
            $filters,
            $this->currentPermissions($request),
        ));
    }

    public function show(string $eventId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->detail(
            $eventId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request),
        )]);
    }

    public function retry(string $eventId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->retry(
            $eventId,
            $this->commandContext($request, true),
        )]);
    }

    public function quarantine(string $eventId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        return response()->json(['data' => $this->service->quarantine(
            $eventId,
            trim($validated['reason']),
            $this->commandContext($request, true),
        )]);
    }

    public function process(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'between:1,500'],
        ]);

        return response()->json(['data' => $this->service->processDue(
            (int) ($validated['limit'] ?? config('qtfoods.outbox.batch_size', 50)),
            $this->commandContext($request, false),
        )]);
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }
}

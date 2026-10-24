<?php

namespace App\Modules\Work\Http\Controllers;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Domain\User;
use App\Modules\Work\Application\WorkItemService;
use App\Modules\Work\Application\WorkQueueQuery;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class WorkQueueController
{
    public function __construct(
        private readonly WorkQueueQuery $query,
        private readonly WorkItemService $service,
        private readonly SessionService $sessions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'status' => ['sometimes', 'string', Rule::in(WorkQueueQuery::STATUSES)],
            'kind' => ['sometimes', 'string', Rule::in(WorkItemService::KINDS)],
            'priority' => ['sometimes', 'string', Rule::in(WorkItemService::PRIORITIES)],
            'assignment' => ['sometimes', 'string', Rule::in(WorkQueueQuery::ASSIGNMENTS)],
            'overdue' => ['sometimes', 'boolean'],
            'q' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', 'string', Rule::in(WorkQueueQuery::SORTS)],
        ]);

        if (array_key_exists('overdue', $filters)) {
            $filters['overdue'] = filter_var($filters['overdue'], FILTER_VALIDATE_BOOL);
        }

        /** @var User $user */
        $user = $request->user();

        return response()->json($this->query->paginate(
            $this->selectedContext($request),
            $filters,
            (string) $user->id,
            $this->sessions->permissions($user, $request)
        ));
    }

    public function claim(string $workItemId, Request $request): JsonResponse
    {
        return response()->json([
            'data' => $this->service->claim(
                $workItemId,
                $this->commandData($request)
            ),
        ]);
    }

    public function assign(string $workItemId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'assigned_user_id' => ['present', 'nullable', 'uuid'],
        ]);

        return response()->json([
            'data' => $this->service->assign(
                $workItemId,
                $validated['assigned_user_id'] ?? null,
                $this->commandData($request)
            ),
        ]);
    }

    public function complete(string $workItemId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'completion_note' => ['nullable', 'string', 'max:2000'],
        ]);
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'data' => $this->service->complete(
                $workItemId,
                $this->commandData($request) + [
                    'completion_note' => $validated['completion_note'] ?? null,
                    'can_manage' => $this->sessions->can(
                        $user,
                        $request,
                        'ACTION:WRK-HOME:MANAGE'
                    ),
                ]
            ),
        ]);
    }

    private function commandData(Request $request): array
    {
        $context = $this->selectedContext($request);
        /** @var User $user */
        $user = $request->user();

        return $context + [
            'actor_id' => (string) $user->id,
            'permissions' => $this->sessions->permissions($user, $request),
            'expected_version' => $this->expectedVersion($request),
            'idempotency_key' => $this->idempotencyKey($request),
            'correlation_id' => $this->correlationId($request),
        ];
    }

    private function selectedContext(Request $request): array
    {
        $context = $request->attributes->get('erp.context');

        if (
            ! is_array($context)
            || ! is_string($context['company_id'] ?? null)
            || ! (is_string($context['plant_id'] ?? null) || ($context['plant_id'] ?? null) === null)
        ) {
            throw ValidationException::withMessages([
                'context' => ['Work queue access requires an active company context.'],
            ]);
        }

        return [
            'company_id' => $context['company_id'],
            'plant_id' => $context['plant_id'],
        ];
    }

    private function expectedVersion(Request $request): int
    {
        $value = trim((string) $request->header('If-Match'));

        if ($value === '') {
            throw ValidationException::withMessages([
                'if_match' => ['The If-Match header is required for this work-item command.'],
            ]);
        }

        if (preg_match('/^(?:W\/)?"(\d+)"$/', $value, $matches)) {
            $value = $matches[1];
        }

        if (! ctype_digit($value) || (int) $value < 1) {
            throw ValidationException::withMessages([
                'if_match' => ['The If-Match header must contain a positive work-item record version.'],
            ]);
        }

        return (int) $value;
    }

    private function idempotencyKey(Request $request): string
    {
        $key = trim((string) $request->header('Idempotency-Key'));

        if ($key === '' && config('qtfoods.require_idempotency', true)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['The Idempotency-Key header is required.'],
            ]);
        }

        if (strlen($key) > 160) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['The Idempotency-Key header may not exceed 160 characters.'],
            ]);
        }

        return $key !== '' ? $key : (string) Str::uuid();
    }

    private function correlationId(Request $request): ?string
    {
        $value = trim((string) $request->header('X-Correlation-ID'));

        if ($value !== '' && ! Str::isUuid($value)) {
            throw ValidationException::withMessages([
                'correlation_id' => ['The X-Correlation-ID header must be a UUID.'],
            ]);
        }

        return $value !== '' ? $value : null;
    }
}

<?php

namespace App\Modules\Sales\Http\Controllers;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Domain\User;
use App\Modules\Sales\Application\UnsoldSalesReturnApprovalQuery;
use App\Modules\Sales\Application\UnsoldSalesReturnApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class UnsoldSalesReturnApprovalController
{
    public function __construct(
        private readonly UnsoldSalesReturnApprovalQuery $query,
        private readonly UnsoldSalesReturnApprovalService $service,
        private readonly SessionService $sessions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'status' => ['sometimes', 'string', Rule::in(UnsoldSalesReturnApprovalQuery::STATUSES)],
            'q' => ['sometimes', 'string', 'max:100'],
            'sort' => ['sometimes', 'string', Rule::in([
                'created_at',
                '-created_at',
                'status',
                '-status',
            ])],
        ]);

        $permissions = $this->approvalPermissions($request);

        return response()->json($this->query->paginate(
            $this->selectedContext($request),
            $filters,
            (string) $request->user()->id,
            $permissions
        ));
    }

    public function show(string $approvalId, Request $request): JsonResponse
    {
        $permissions = $this->approvalPermissions($request);
        $approval = $this->query->find(
            $approvalId,
            $this->selectedContext($request),
            (string) $request->user()->id,
            $permissions
        );

        abort_if(! $approval, 404, 'Unsold return approval request not found.');

        return response()->json(['data' => $approval]);
    }

    public function approve(string $approvalId, Request $request): JsonResponse
    {
        return $this->decision($approvalId, 'APPROVE', $request);
    }

    public function reject(string $approvalId, Request $request): JsonResponse
    {
        return $this->decision($approvalId, 'REJECT', $request);
    }

    private function decision(string $approvalId, string $decision, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'reason' => $decision === 'REJECT'
                ? ['required', 'string', 'min:3', 'max:2000']
                : ['nullable', 'string', 'max:2000'],
        ]);
        $context = $this->selectedContext($request);
        $validated['company_id'] = $context['company_id'];
        $validated['plant_id'] = $context['plant_id'];
        $validated['actor_id'] = (string) $request->user()->id;
        $validated['permissions'] = $this->approvalPermissions($request);
        $validated['expected_version'] = $this->expectedVersion($request);
        $validated['idempotency_key'] = $this->idempotencyKey($request);
        $validated['correlation_id'] = $this->correlationId($request);

        return response()->json([
            'data' => $this->service->decide($approvalId, $decision, $validated),
        ]);
    }

    private function selectedContext(Request $request): array
    {
        $context = $request->attributes->get('erp.context');

        if (
            ! is_array($context)
            || ! is_string($context['company_id'] ?? null)
            || ! is_string($context['plant_id'] ?? null)
        ) {
            throw ValidationException::withMessages([
                'context' => ['Approval review requires an active plant context.'],
            ]);
        }

        return [
            'company_id' => $context['company_id'],
            'plant_id' => $context['plant_id'],
        ];
    }

    private function approvalPermissions(Request $request): array
    {
        /** @var User $user */
        $user = $request->user();
        $permissions = $this->sessions->permissions($user, $request);
        if (! collect($permissions)->contains(
            fn (string $permission) => str_starts_with($permission, 'ACTION:RET-UNSOLD:APPROVE')
        )) {
            throw new AuthorizationException('You are not authorised to review unsold-return approvals.');
        }

        return $permissions;
    }

    private function expectedVersion(Request $request): int
    {
        $value = trim((string) $request->header('If-Match'));

        if ($value === '') {
            throw ValidationException::withMessages([
                'if_match' => ['The If-Match header is required for this approval decision.'],
            ]);
        }

        if (preg_match('/^(?:W\/)?"(\d+)"$/', $value, $matches)) {
            $value = $matches[1];
        }

        if (! ctype_digit($value) || (int) $value < 1) {
            throw ValidationException::withMessages([
                'if_match' => ['The If-Match header must contain a positive approval record version.'],
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

<?php

namespace App\Modules\Foundation\Http\Controllers;

use App\Modules\Foundation\Application\FoundationAdminQuery;
use App\Modules\Foundation\Application\FoundationAdminService;
use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class UserAdminController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly FoundationAdminQuery $query,
        private readonly FoundationAdminService $service,
        private readonly SessionService $sessions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(FoundationAdminService::USER_STATUSES)],
        ]);

        return response()->json($this->query->users(
            $this->selectedScope($request, true),
            $filters,
            $this->currentPermissions($request)
        ));
    }

    public function show(string $userId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->user(
            $userId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request)
        )]);
    }

    public function create(Request $request): JsonResponse
    {
        $this->normaliseEmail($request);
        $validated = $request->validate($this->createRules());

        return response()->json([
            'data' => $this->service->createUser($validated + $this->commandContext($request, false)),
        ], 201);
    }

    public function update(string $userId, Request $request): JsonResponse
    {
        $this->normaliseEmail($request);
        $validated = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(FoundationAdminService::USER_STATUSES)],
        ]);

        return response()->json(['data' => $this->service->updateUser(
            $userId,
            $validated + $this->commandContext($request, true)
        )]);
    }

    public function resetPassword(string $userId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'temporary_password' => [
                'required', 'string', 'confirmed', 'max:1024',
                Password::min(9)->letters()->numbers()->mixedCase(),
            ],
        ]);

        return response()->json(['data' => $this->service->resetUserPassword(
            $userId,
            $validated + $this->commandContext($request, true)
        )]);
    }

    public function delete(string $userId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->service->deleteUser(
            $userId,
            $this->commandContext($request, true)
        )]);
    }

    public function setMfaRequirement(string $userId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'required' => ['required', 'boolean'],
        ]);

        return response()->json(['data' => $this->service->setUserMfaRequirement(
            $userId,
            $validated + $this->commandContext($request, true)
        )]);
    }

    public function showAssignment(string $assignmentId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->assignment(
            $assignmentId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request)
        )]);
    }

    public function createAssignment(string $userId, Request $request): JsonResponse
    {
        $validated = $request->validate($this->assignmentRules(false));

        return response()->json([
            'data' => $this->service->createRoleAssignment(
                $userId,
                $validated + $this->commandContext($request, false)
            ),
        ], 201);
    }

    public function updateAssignment(string $assignmentId, Request $request): JsonResponse
    {
        $validated = $request->validate($this->assignmentRules(true));

        return response()->json(['data' => $this->service->updateRoleAssignment(
            $assignmentId,
            $validated + $this->commandContext($request, true)
        )]);
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }

    private function createRules(): array
    {
        return [
            'email' => ['required', 'email:rfc', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'temporary_password' => [
                'required', 'string', 'max:1024', Password::min(9)->letters()->numbers()->mixedCase(),
            ],
            'role_id' => ['required', 'uuid'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
        ];
    }

    private function assignmentRules(bool $updating): array
    {
        return [
            'role_id' => ['required', 'uuid'],
            'is_active' => [$updating ? 'required' : 'sometimes', 'boolean'],
            'effective_from' => ['nullable', 'date'],
            'effective_to' => ['nullable', 'date', 'after:effective_from'],
        ];
    }

    private function normaliseEmail(Request $request): void
    {
        if (is_string($request->input('email'))) {
            $request->merge(['email' => Str::lower(trim((string) $request->input('email')))]);
        }
    }
}

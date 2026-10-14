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

final class RoleAdminController
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
            'status' => ['sometimes', 'string', Rule::in(FoundationAdminService::DEFINITION_STATUSES)],
        ]);

        return response()->json($this->query->roles(
            $this->selectedScope($request, true),
            $filters,
            $this->currentPermissions($request)
        ));
    }

    public function show(string $roleId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->role(
            $roleId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request)
        )]);
    }

    public function create(Request $request): JsonResponse
    {
        $this->normaliseCode($request);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:64', 'regex:/^[A-Z][A-Z0-9_-]*$/'],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => $this->service->createRole($validated + $this->commandContext($request, false)),
        ], 201);
    }

    public function update(string $roleId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'string', Rule::in(FoundationAdminService::DEFINITION_STATUSES)],
        ]);

        return response()->json(['data' => $this->service->updateRole(
            $roleId,
            $validated + $this->commandContext($request, true)
        )]);
    }

    public function delete(string $roleId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate(['confirmed' => ['required', 'accepted']]);

        return response()->json(['data' => $this->service->deleteRole(
            $roleId,
            $validated + $this->commandContext($request, true)
        )]);
    }

    public function syncPermissions(string $roleId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'permission_ids' => ['present', 'array', 'max:1000'],
            'permission_ids.*' => ['uuid', 'distinct'],
        ]);

        return response()->json(['data' => $this->service->syncRolePermissions(
            $roleId,
            $validated + $this->commandContext($request, true)
        )]);
    }

    public function permissions(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(FoundationAdminService::DEFINITION_STATUSES)],
        ]);

        return response()->json($this->query->permissions(
            $this->selectedScope($request, true),
            $filters,
            $this->currentPermissions($request)
        ));
    }

    public function showPermission(string $permissionId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->permission(
            $permissionId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request)
        )]);
    }

    public function createPermission(Request $request): JsonResponse
    {
        $this->normaliseCode($request);
        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:128',
                'regex:/^(?:SCREEN|ACTION):[A-Z0-9][A-Z0-9-]*:[A-Z0-9][A-Z0-9-]*$/',
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
        ]);

        return response()->json([
            'data' => $this->service->createPermission($validated + $this->commandContext($request, false)),
        ], 201);
    }

    public function updatePermission(string $permissionId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'status' => ['required', 'string', Rule::in(FoundationAdminService::DEFINITION_STATUSES)],
        ]);

        return response()->json(['data' => $this->service->updatePermission(
            $permissionId,
            $validated + $this->commandContext($request, true)
        )]);
    }

    public function deletePermission(string $permissionId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate(['confirmed' => ['required', 'accepted']]);

        return response()->json(['data' => $this->service->deletePermission(
            $permissionId,
            $validated + $this->commandContext($request, true)
        )]);
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }

    private function normaliseCode(Request $request): void
    {
        if (is_string($request->input('code'))) {
            $request->merge(['code' => Str::upper(trim((string) $request->input('code')))]);
        }
    }
}

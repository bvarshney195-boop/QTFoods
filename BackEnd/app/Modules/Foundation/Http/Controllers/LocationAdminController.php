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

final class LocationAdminController
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
            'status' => ['sometimes', 'string', Rule::in(FoundationAdminService::LOCATION_STATUSES)],
            'location_type' => ['sometimes', 'string', Rule::in(FoundationAdminService::LOCATION_TYPES)],
        ]);

        return response()->json($this->query->locations(
            $this->selectedScope($request, true),
            $filters,
            $this->currentPermissions($request)
        ));
    }

    public function show(string $locationId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->location(
            $locationId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request)
        )]);
    }

    public function create(Request $request): JsonResponse
    {
        if (is_string($request->input('code'))) {
            $request->merge(['code' => Str::upper(trim((string) $request->input('code')))]);
        }
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/',
            ],
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'location_type' => ['required', 'string', Rule::in(FoundationAdminService::LOCATION_TYPES)],
            'parent_location_id' => ['present', 'nullable', 'uuid'],
            'status' => ['required', 'string', Rule::in(FoundationAdminService::LOCATION_STATUSES)],
        ]);

        return response()->json([
            'data' => $this->service->createLocation($validated + $this->commandContext($request, false)),
        ], 201);
    }

    public function update(string $locationId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'location_type' => ['required', 'string', Rule::in(FoundationAdminService::LOCATION_TYPES)],
            'parent_location_id' => ['present', 'nullable', 'uuid'],
            'status' => ['required', 'string', Rule::in(FoundationAdminService::LOCATION_STATUSES)],
        ]);

        return response()->json(['data' => $this->service->updateLocation(
            $locationId,
            $validated + $this->commandContext($request, true)
        )]);
    }

    public function delete(string $locationId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate(['confirmed' => ['required', 'accepted']]);

        return response()->json(['data' => $this->service->deleteLocation(
            $locationId,
            $validated + $this->commandContext($request, true)
        )]);
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }
}

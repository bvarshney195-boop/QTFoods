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

final class OrganisationAdminController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly FoundationAdminQuery $query,
        private readonly FoundationAdminService $service,
        private readonly SessionService $sessions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json($this->query->organisation(
            $this->selectedScope($request),
            $this->currentPermissions($request)
        ));
    }

    public function showCompany(string $companyId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->company(
            $companyId,
            $this->selectedScope($request),
            $this->currentPermissions($request)
        )]);
    }

    public function createCompany(Request $request): JsonResponse
    {
        $this->normaliseCodes($request, ['code', 'plant_code']);
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/'],
            'legal_name' => ['required', 'string', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
            'plant_code' => ['required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/'],
            'plant_name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
        ]);

        return response()->json([
            'data' => $this->service->createCompany($validated + $this->commandContext($request, false)),
        ], 201);
    }

    public function updateCompany(string $companyId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'legal_name' => ['required', 'string', 'max:255'],
            'display_name' => ['required', 'string', 'max:255'],
            'status' => ['required', 'string', Rule::in(FoundationAdminService::COMPANY_STATUSES)],
        ]);

        return response()->json(['data' => $this->service->updateCompany(
            $companyId,
            $validated + $this->commandContext($request, true)
        )]);
    }

    public function showPlant(string $plantId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->plant(
            $plantId,
            $this->selectedScope($request),
            $this->currentPermissions($request)
        )]);
    }

    public function createPlant(Request $request): JsonResponse
    {
        $this->normaliseCodes($request, ['code']);
        $validated = $request->validate([
            'code' => [
                'required', 'string', 'max:32', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/',
            ],
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
            'status' => ['required', 'string', Rule::in(FoundationAdminService::PLANT_STATUSES)],
        ]);

        return response()->json([
            'data' => $this->service->createPlant($validated + $this->commandContext($request, false)),
        ], 201);
    }

    public function updatePlant(string $plantId, Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
            'status' => ['required', 'string', Rule::in(FoundationAdminService::PLANT_STATUSES)],
        ]);

        return response()->json(['data' => $this->service->updatePlant(
            $plantId,
            $validated + $this->commandContext($request, true)
        )]);
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }

    private function normaliseCodes(Request $request, array $fields): void
    {
        foreach ($fields as $field) {
            if (is_string($request->input($field))) {
                $request->merge([$field => Str::upper(trim((string) $request->input($field)))]);
            }
        }
    }
}

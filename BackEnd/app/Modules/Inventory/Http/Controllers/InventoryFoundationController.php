<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use App\Modules\Inventory\Application\InventoryFoundationQuery;
use App\Modules\Inventory\Application\InventoryFoundationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class InventoryFoundationController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly InventoryFoundationQuery $query,
        private readonly InventoryFoundationService $service,
        private readonly SessionService $sessions,
    ) {}

    public function stock(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'q' => ['sometimes', 'string', 'max:100'],
            'include_zero' => ['sometimes', 'boolean'],
            'item_id' => ['sometimes', 'uuid'],
            'lot_id' => ['sometimes', 'uuid'],
            'owner_id' => ['sometimes', 'uuid'],
            'location_id' => ['sometimes', 'uuid'],
            'quality_status' => ['sometimes', 'string', 'max:32'],
            'availability' => ['sometimes', 'string', Rule::in(InventoryFoundationQuery::AVAILABILITY_FILTERS)],
            'sort' => ['sometimes', 'string', Rule::in(InventoryFoundationQuery::STOCK_SORTS)],
        ]);
        if ($request->has('include_zero')) {
            $filters['include_zero'] = $request->boolean('include_zero');
        }

        return response()->json($this->query->stockWorkspace(
            $this->selectedScope($request, true),
            $filters,
            $this->currentPermissions($request),
        ));
    }

    public function stockPosition(string $positionId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->stockDetail(
            $positionId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request),
        )]);
    }

    public function owners(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'q' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(InventoryFoundationService::OWNER_STATUSES)],
            'owner_type' => ['sometimes', 'string', Rule::in(InventoryFoundationService::OWNER_TYPES)],
            'sort' => ['sometimes', 'string', Rule::in(InventoryFoundationQuery::OWNER_SORTS)],
        ]);

        return response()->json($this->query->ownerWorkspace(
            $this->selectedScope($request, true),
            $filters,
            $this->currentPermissions($request),
        ));
    }

    public function owner(string $ownerId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->ownerDetail(
            $ownerId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request),
        )]);
    }

    public function createOwner(Request $request): JsonResponse
    {
        $this->normalise($request, ['code', 'owner_type', 'status']);
        $this->selectedScope($request, true);
        $validated = $request->validate($this->ownerRules(true));

        return response()->json(['data' => $this->service->createOwner(
            $validated + $this->commandContext($request, false),
        )], 201);
    }

    public function updateOwner(string $ownerId, Request $request): JsonResponse
    {
        $this->normalise($request, ['owner_type']);
        $this->selectedScope($request, true);
        $validated = $request->validate($this->ownerRules(false));

        return response()->json(['data' => $this->service->updateOwner(
            $ownerId,
            $validated + $this->commandContext($request, true),
        )]);
    }

    public function changeOwnerStatus(string $ownerId, Request $request): JsonResponse
    {
        $this->normalise($request, ['target_status']);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'target_status' => ['required', 'string', Rule::in(InventoryFoundationService::OWNER_STATUSES)],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        return response()->json(['data' => $this->service->changeOwnerStatus(
            $ownerId,
            $validated['target_status'],
            trim($validated['reason']),
            $this->commandContext($request, true),
        )]);
    }

    public function deleteOwner(string $ownerId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate(['confirmed' => ['required', 'accepted']]);

        return response()->json(['data' => $this->service->deleteOwner(
            $ownerId,
            $validated + $this->commandContext($request, true),
        )]);
    }

    public function lots(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'q' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(InventoryFoundationService::LOT_STATUSES)],
            'origin_type' => ['sometimes', 'string', Rule::in(InventoryFoundationService::LOT_ORIGINS)],
            'item_id' => ['sometimes', 'uuid'],
            'expiry' => ['sometimes', 'string', Rule::in(['EXPIRED', 'EXPIRING_30', 'VALID'])],
            'sort' => ['sometimes', 'string', Rule::in(InventoryFoundationQuery::LOT_SORTS)],
        ]);

        return response()->json($this->query->lotWorkspace(
            $this->selectedScope($request, true),
            $filters,
            $this->currentPermissions($request),
        ));
    }

    public function lot(string $lotId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->lotDetail(
            $lotId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request),
        )]);
    }

    public function createLot(Request $request): JsonResponse
    {
        $this->normalise($request, ['internal_lot_code', 'supplier_lot_code', 'origin_type', 'status']);
        $this->selectedScope($request, true);
        $validated = $request->validate($this->lotRules(true));

        return response()->json(['data' => $this->service->createLot(
            $validated + $this->commandContext($request, false),
        )], 201);
    }

    public function updateLot(string $lotId, Request $request): JsonResponse
    {
        $this->normalise($request, ['supplier_lot_code', 'origin_type']);
        $this->selectedScope($request, true);
        $validated = $request->validate($this->lotRules(false));

        return response()->json(['data' => $this->service->updateLot(
            $lotId,
            $validated + $this->commandContext($request, true),
        )]);
    }

    public function changeLotStatus(string $lotId, Request $request): JsonResponse
    {
        $this->normalise($request, ['target_status']);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'target_status' => ['required', 'string', Rule::in(InventoryFoundationService::LOT_STATUSES)],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        return response()->json(['data' => $this->service->changeLotStatus(
            $lotId,
            $validated['target_status'],
            trim($validated['reason']),
            $this->commandContext($request, true),
        )]);
    }

    public function deleteLot(string $lotId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate(['confirmed' => ['required', 'accepted']]);

        return response()->json(['data' => $this->service->deleteLot(
            $lotId,
            $validated + $this->commandContext($request, true),
        )]);
    }

    public function reserve(string $positionId, Request $request): JsonResponse
    {
        $this->normalise($request, ['reservation_number']);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'reservation_number' => ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/'],
            'quantity_base' => ['required', 'numeric', 'gt:0', 'max:99999999999999'],
            'purpose' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        return response()->json(['data' => $this->service->reserve(
            $positionId,
            $validated + $this->commandContext($request, true),
        )], 201);
    }

    public function releaseReservation(string $reservationId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        return response()->json(['data' => $this->service->releaseReservation(
            $reservationId,
            trim($validated['reason']),
            $this->commandContext($request, true),
        )]);
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }

    private function ownerRules(bool $creating): array
    {
        return [
            'code' => $creating
                ? ['required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/']
                : ['prohibited'],
            'name' => ['required', 'string', 'max:255'],
            'owner_type' => ['required', 'string', Rule::in(InventoryFoundationService::OWNER_TYPES)],
            'party_id' => ['nullable', 'uuid'],
            'status' => $creating
                ? ['required', 'string', Rule::in(['DRAFT', 'ACTIVE'])]
                : ['prohibited'],
        ];
    }

    private function lotRules(bool $creating): array
    {
        return [
            'internal_lot_code' => $creating
                ? ['required', 'string', 'max:80', 'regex:/^[A-Z0-9][A-Z0-9_\/-]*$/']
                : ['prohibited'],
            'item_id' => ['required', 'uuid'],
            'supplier_party_id' => ['nullable', 'uuid'],
            'supplier_lot_code' => ['nullable', 'string', 'max:120'],
            'origin_type' => ['required', 'string', Rule::in(InventoryFoundationService::LOT_ORIGINS)],
            'manufacture_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:4000'],
            'status' => $creating
                ? ['required', 'string', Rule::in(['DRAFT', 'ACTIVE'])]
                : ['prohibited'],
        ];
    }

    private function normalise(Request $request, array $fields): void
    {
        $input = $request->all();
        foreach ($fields as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = Str::upper(trim($input[$field]));
            }
        }
        $request->replace($input);
    }
}

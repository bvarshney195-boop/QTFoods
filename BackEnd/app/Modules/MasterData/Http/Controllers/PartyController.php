<?php

namespace App\Modules\MasterData\Http\Controllers;

use App\Modules\Foundation\Application\SessionService;
use App\Modules\Foundation\Http\Controllers\Concerns\BuildsAdminContext;
use App\Modules\MasterData\Application\PartyQuery;
use App\Modules\MasterData\Application\PartyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class PartyController
{
    use BuildsAdminContext;

    public function __construct(
        private readonly PartyQuery $query,
        private readonly PartyService $service,
        private readonly SessionService $sessions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'q' => ['sometimes', 'string', 'max:100'],
            'status' => ['sometimes', 'string', Rule::in(PartyService::STATUSES)],
            'party_kind' => ['sometimes', 'string', Rule::in(PartyService::PARTY_KINDS)],
            'role' => ['sometimes', 'string', Rule::in(PartyService::ROLE_CODES)],
            'country' => ['sometimes', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'sort' => ['sometimes', 'string', Rule::in(PartyQuery::SORTS)],
        ]);

        return response()->json($this->query->workspace(
            $this->selectedScope($request, true),
            $filters,
            $this->currentPermissions($request),
        ));
    }

    public function show(string $partyId, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->query->detail(
            $partyId,
            $this->selectedScope($request, true),
            $this->currentPermissions($request),
        )]);
    }

    public function create(Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate($this->aggregateRules(true));

        return response()->json(['data' => $this->service->create(
            $validated + $this->commandContext($request, false),
        )], 201);
    }

    public function update(string $partyId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate($this->aggregateRules(false));

        return response()->json(['data' => $this->service->update(
            $partyId,
            $validated + $this->commandContext($request, true),
        )]);
    }

    public function changeStatus(string $partyId, Request $request): JsonResponse
    {
        $this->normalise($request);
        $this->selectedScope($request, true);
        $validated = $request->validate([
            'target_status' => ['required', 'string', Rule::in(PartyService::STATUSES)],
            'reason' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        return response()->json(['data' => $this->service->changeStatus(
            $partyId,
            $validated['target_status'],
            trim($validated['reason']),
            $this->commandContext($request, true),
        )]);
    }

    public function delete(string $partyId, Request $request): JsonResponse
    {
        $this->selectedScope($request, true);
        $validated = $request->validate(['confirmed' => ['required', 'accepted']]);

        return response()->json(['data' => $this->service->delete(
            $partyId,
            $validated + $this->commandContext($request, true),
        )]);
    }

    protected function sessionService(): SessionService
    {
        return $this->sessions;
    }

    private function aggregateRules(bool $creating): array
    {
        return [
            'code' => $creating
                ? ['required', 'string', 'max:64', 'regex:/^[A-Z0-9][A-Z0-9_-]*$/']
                : ['prohibited'],
            'display_name' => ['required', 'string', 'max:255'],
            'legal_name' => ['required', 'string', 'max:255'],
            'party_kind' => ['required', 'string', Rule::in(PartyService::PARTY_KINDS)],
            'notes' => ['nullable', 'string', 'max:4000'],
            'status' => $creating
                ? ['required', 'string', Rule::in(['DRAFT', 'ACTIVE'])]
                : ['prohibited'],
            'roles' => ['required', 'array', 'between:1,4'],
            'roles.*' => ['required', 'string', 'distinct', Rule::in(PartyService::ROLE_CODES)],
            'addresses' => ['present', 'array', 'max:20'],
            'addresses.*.id' => ['sometimes', 'nullable', 'uuid'],
            'addresses.*.label' => ['required', 'string', 'max:80'],
            'addresses.*.address_type' => ['required', 'string', Rule::in(PartyService::ADDRESS_TYPES)],
            'addresses.*.line_1' => ['required', 'string', 'max:255'],
            'addresses.*.line_2' => ['nullable', 'string', 'max:255'],
            'addresses.*.city' => ['required', 'string', 'max:120'],
            'addresses.*.district' => ['nullable', 'string', 'max:120'],
            'addresses.*.region' => ['required', 'string', 'max:120'],
            'addresses.*.postal_code' => ['required', 'string', 'max:24'],
            'addresses.*.country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'addresses.*.is_primary' => ['required', 'boolean'],
            'contacts' => ['present', 'array', 'max:20'],
            'contacts.*.id' => ['sometimes', 'nullable', 'uuid'],
            'contacts.*.name' => ['required', 'string', 'max:160'],
            'contacts.*.job_title' => ['nullable', 'string', 'max:120'],
            'contacts.*.department' => ['nullable', 'string', 'max:120'],
            'contacts.*.email' => ['nullable', 'email:rfc', 'max:255'],
            'contacts.*.phone' => ['nullable', 'string', 'max:40'],
            'contacts.*.mobile' => ['nullable', 'string', 'max:40'],
            'contacts.*.is_primary' => ['required', 'boolean'],
            'tax_registrations' => ['present', 'array', 'max:20'],
            'tax_registrations.*.id' => ['sometimes', 'nullable', 'uuid'],
            'tax_registrations.*.registration_type' => ['required', 'string', Rule::in(PartyService::TAX_TYPES)],
            'tax_registrations.*.registration_number' => ['required', 'string', 'max:80'],
            'tax_registrations.*.country_code' => ['required', 'string', 'size:2', 'regex:/^[A-Z]{2}$/'],
            'tax_registrations.*.is_primary' => ['required', 'boolean'],
            'tax_registrations.*.valid_from' => ['nullable', 'date'],
            'tax_registrations.*.valid_to' => ['nullable', 'date'],
            'commercial_terms' => ['required', 'array'],
            'commercial_terms.currency_code' => ['required', 'string', 'size:3', 'regex:/^[A-Z]{3}$/'],
            'commercial_terms.payment_terms_days' => ['required', 'integer', 'between:0,3650'],
            'commercial_terms.credit_limit' => ['required', 'numeric', 'min:0', 'max:999999999999999999'],
            'commercial_terms.credit_hold' => ['required', 'boolean'],
            'commercial_terms.incoterm_code' => ['nullable', 'string', 'max:10', 'regex:/^[A-Z0-9]*$/'],
            'commercial_terms.delivery_terms' => ['nullable', 'string', 'max:2000'],
        ];
    }

    private function normalise(Request $request): void
    {
        $input = $request->all();
        foreach (['code', 'party_kind', 'status', 'target_status'] as $field) {
            if (is_string($input[$field] ?? null)) {
                $input[$field] = Str::upper(trim($input[$field]));
            }
        }
        if (is_array($input['roles'] ?? null)) {
            $input['roles'] = array_map(
                fn (mixed $role) => is_string($role) ? Str::upper(trim($role)) : $role,
                $input['roles'],
            );
        }
        if (is_array($input['addresses'] ?? null)) {
            foreach ($input['addresses'] as &$address) {
                if (! is_array($address)) {
                    continue;
                }
                foreach (['address_type', 'country_code'] as $field) {
                    if (is_string($address[$field] ?? null)) {
                        $address[$field] = Str::upper(trim($address[$field]));
                    }
                }
            }
            unset($address);
        }
        if (is_array($input['tax_registrations'] ?? null)) {
            foreach ($input['tax_registrations'] as &$registration) {
                if (! is_array($registration)) {
                    continue;
                }
                foreach (['registration_type', 'registration_number', 'country_code'] as $field) {
                    if (is_string($registration[$field] ?? null)) {
                        $registration[$field] = Str::upper(trim($registration[$field]));
                    }
                }
            }
            unset($registration);
        }
        if (is_array($input['commercial_terms'] ?? null)) {
            foreach (['currency_code', 'incoterm_code'] as $field) {
                if (is_string($input['commercial_terms'][$field] ?? null)) {
                    $input['commercial_terms'][$field] = Str::upper(trim($input['commercial_terms'][$field]));
                }
            }
        }
        $request->replace($input);
    }
}

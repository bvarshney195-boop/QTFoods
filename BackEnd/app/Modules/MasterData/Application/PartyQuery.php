<?php

namespace App\Modules\MasterData\Application;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PartyQuery
{
    public const SORTS = ['CODE', 'NAME', 'NEWEST', 'OLDEST'];

    public function workspace(array $scope, array $filters, array $permissions): array
    {
        $base = $this->scoped($scope['company_id']);
        $query = clone $base;
        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters['sort'] ?? 'CODE');

        $parties = $query->select([
            'party.*', 'status_actor.name as status_actor_name',
        ])->paginate((int) ($filters['per_page'] ?? 25));
        $items = collect($parties->items());
        $relations = $this->relations($items->pluck('id')->all(), false);

        return [
            'data' => $items->map(fn (object $party) => $this->payload(
                $party,
                $relations,
                $permissions,
                false,
            ))->all(),
            'meta' => [
                'current_page' => $parties->currentPage(),
                'last_page' => $parties->lastPage(),
                'per_page' => $parties->perPage(),
                'total' => $parties->total(),
            ],
            'summary' => $this->summary($base),
            'lookups' => [
                'statuses' => PartyService::STATUSES,
                'party_kinds' => PartyService::PARTY_KINDS,
                'role_codes' => PartyService::ROLE_CODES,
                'address_types' => PartyService::ADDRESS_TYPES,
                'tax_types' => PartyService::TAX_TYPES,
                'countries' => $this->countryLookups($scope['company_id']),
            ],
            'allowed_actions' => $this->can($permissions, 'ACTION:MD-PARTY:CREATE')
                ? ['CREATE']
                : [],
        ];
    }

    public function detail(string $partyId, array $scope, array $permissions): array
    {
        $party = $this->scoped($scope['company_id'])->where('party.id', $partyId)
            ->first(['party.*', 'status_actor.name as status_actor_name']);
        if (! $party) {
            throw new NotFoundHttpException('Party not found.');
        }
        $relations = $this->relations([$partyId], true);

        return $this->payload($party, $relations, $permissions, true);
    }

    private function scoped(string $companyId): Builder
    {
        return DB::table('parties as party')
            ->leftJoin('users as status_actor', 'status_actor.id', '=', 'party.status_changed_by')
            ->where('party.company_id', $companyId);
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $query) use ($needle): void {
                foreach (['party.code', 'party.display_name', 'party.legal_name'] as $column) {
                    $query->orWhereRaw("LOWER(COALESCE({$column}, '')) LIKE ?", [$needle]);
                }
                $query->orWhereExists(fn (Builder $child) => $child->from('party_contacts as contact')
                    ->whereColumn('contact.party_id', 'party.id')
                    ->where(function (Builder $contact) use ($needle): void {
                        foreach (['contact.name', 'contact.email', 'contact.phone', 'contact.mobile'] as $column) {
                            $contact->orWhereRaw("LOWER(COALESCE({$column}, '')) LIKE ?", [$needle]);
                        }
                    }))
                    ->orWhereExists(fn (Builder $child) => $child->from('party_tax_registrations as tax')
                        ->whereColumn('tax.party_id', 'party.id')
                        ->whereRaw("LOWER(COALESCE(tax.registration_number, '')) LIKE ?", [$needle]));
            });
        }
        if (! empty($filters['status'])) {
            $query->where('party.status', $filters['status']);
        }
        if (! empty($filters['party_kind'])) {
            $query->where('party.party_kind', $filters['party_kind']);
        }
        if (! empty($filters['role'])) {
            $query->whereExists(fn (Builder $child) => $child->from('party_roles as role')
                ->whereColumn('role.party_id', 'party.id')
                ->where('role.role_code', $filters['role']));
        }
        if (! empty($filters['country'])) {
            $query->whereExists(fn (Builder $child) => $child->from('party_addresses as address')
                ->whereColumn('address.party_id', 'party.id')
                ->where('address.country_code', $filters['country']));
        }
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'NAME' => $query->orderBy('party.display_name')->orderBy('party.code'),
            'NEWEST' => $query->orderByDesc('party.created_at')->orderByDesc('party.id'),
            'OLDEST' => $query->orderBy('party.created_at')->orderBy('party.id'),
            default => $query->orderBy('party.code')->orderBy('party.id'),
        };
    }

    private function relations(array $partyIds, bool $includeAll): array
    {
        if ($partyIds === []) {
            return [
                'roles' => collect(), 'addresses' => collect(), 'contacts' => collect(),
                'tax' => collect(), 'terms' => collect(),
            ];
        }

        $addresses = DB::table('party_addresses')->whereIn('party_id', $partyIds)
            ->orderByDesc('is_primary')->orderBy('address_type')->orderBy('label')->get()->groupBy('party_id');
        $contacts = DB::table('party_contacts')->whereIn('party_id', $partyIds)
            ->orderByDesc('is_primary')->orderBy('name')->get()->groupBy('party_id');
        $tax = DB::table('party_tax_registrations')->whereIn('party_id', $partyIds)
            ->orderByDesc('is_primary')->orderBy('registration_type')->get()->groupBy('party_id');

        return [
            'roles' => DB::table('party_roles')->whereIn('party_id', $partyIds)
                ->orderBy('role_code')->get()->groupBy('party_id'),
            'addresses' => $includeAll ? $addresses : $addresses->map(fn (Collection $rows) => $rows->take(1)),
            'address_counts' => $addresses->map->count(),
            'contacts' => $includeAll ? $contacts : $contacts->map(fn (Collection $rows) => $rows->take(1)),
            'contact_counts' => $contacts->map->count(),
            'tax' => $includeAll ? $tax : collect(),
            'tax_counts' => $tax->map->count(),
            'terms' => DB::table('party_commercial_terms')->whereIn('party_id', $partyIds)
                ->get()->keyBy('party_id'),
        ];
    }

    private function payload(object $party, array $relations, array $permissions, bool $detail): array
    {
        $partyId = (string) $party->id;
        $addresses = $relations['addresses']->get($partyId, collect());
        $contacts = $relations['contacts']->get($partyId, collect());
        $terms = $relations['terms']->get($partyId);
        $roles = $relations['roles']->get($partyId, collect())->pluck('role_code')->values()->all();
        $allowed = [];
        if ($this->can($permissions, 'ACTION:MD-PARTY:UPDATE')) {
            $allowed[] = 'UPDATE';
        }
        if ($this->can($permissions, 'ACTION:MD-PARTY:LIFECYCLE')) {
            $allowed[] = 'CHANGE_STATUS';
        }

        $payload = [
            'id' => $partyId,
            'company_id' => (string) $party->company_id,
            'code' => (string) $party->code,
            'display_name' => (string) $party->display_name,
            'legal_name' => (string) $party->legal_name,
            'party_kind' => (string) $party->party_kind,
            'status' => (string) $party->status,
            'roles' => $roles,
            'primary_address' => $addresses->isEmpty() ? null : $this->address($addresses->first()),
            'primary_contact' => $contacts->isEmpty() ? null : $this->contact($contacts->first()),
            'address_count' => (int) ($relations['address_counts']->get($partyId) ?? 0),
            'contact_count' => (int) ($relations['contact_counts']->get($partyId) ?? 0),
            'tax_registration_count' => (int) ($relations['tax_counts']->get($partyId) ?? 0),
            'commercial_terms' => $terms ? $this->commercialTerms($terms) : null,
            'status_change' => $party->status_changed_at ? [
                'reason' => $party->status_reason,
                'changed_at' => $this->timestamp($party->status_changed_at),
                'changed_by' => $party->status_changed_by ? [
                    'id' => (string) $party->status_changed_by,
                    'name' => $party->status_actor_name ?: 'Unknown actor',
                ] : null,
            ] : null,
            'record_version' => (int) $party->record_version,
            'allowed_actions' => $allowed,
            'allowed_statuses' => PartyService::allowedTransitions((string) $party->status),
            'created_at' => $this->timestamp($party->created_at),
            'updated_at' => $this->timestamp($party->updated_at),
        ];

        if ($detail) {
            $payload['notes'] = $party->notes;
            $payload['addresses'] = $addresses->map(fn (object $row) => $this->address($row))->all();
            $payload['contacts'] = $contacts->map(fn (object $row) => $this->contact($row))->all();
            $payload['tax_registrations'] = $relations['tax']->get($partyId, collect())
                ->map(fn (object $row) => $this->taxRegistration($row))->all();
        }

        return $payload;
    }

    private function address(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'label' => (string) $row->label,
            'address_type' => (string) $row->address_type,
            'line_1' => (string) $row->line_1,
            'line_2' => $row->line_2,
            'city' => (string) $row->city,
            'district' => $row->district,
            'region' => (string) $row->region,
            'postal_code' => (string) $row->postal_code,
            'country_code' => (string) $row->country_code,
            'is_primary' => (bool) $row->is_primary,
        ];
    }

    private function contact(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'name' => (string) $row->name,
            'job_title' => $row->job_title,
            'department' => $row->department,
            'email' => $row->email,
            'phone' => $row->phone,
            'mobile' => $row->mobile,
            'is_primary' => (bool) $row->is_primary,
        ];
    }

    private function taxRegistration(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'registration_type' => (string) $row->registration_type,
            'registration_number' => (string) $row->registration_number,
            'country_code' => (string) $row->country_code,
            'is_primary' => (bool) $row->is_primary,
            'valid_from' => $row->valid_from ? CarbonImmutable::parse((string) $row->valid_from)->toDateString() : null,
            'valid_to' => $row->valid_to ? CarbonImmutable::parse((string) $row->valid_to)->toDateString() : null,
        ];
    }

    private function commercialTerms(object $row): array
    {
        return [
            'id' => (string) $row->id,
            'currency_code' => (string) $row->currency_code,
            'payment_terms_days' => (int) $row->payment_terms_days,
            'credit_limit' => (string) $row->credit_limit,
            'credit_hold' => (bool) $row->credit_hold,
            'incoterm_code' => $row->incoterm_code,
            'delivery_terms' => $row->delivery_terms,
        ];
    }

    private function summary(Builder $base): array
    {
        $summary = ['total' => (clone $base)->count()];
        foreach (PartyService::STATUSES as $status) {
            $summary[strtolower($status)] = (clone $base)->where('party.status', $status)->count();
        }
        foreach (['CUSTOMER', 'SUPPLIER'] as $role) {
            $summary[strtolower($role).'s'] = (clone $base)->whereExists(
                fn (Builder $child) => $child->from('party_roles as role')
                    ->whereColumn('role.party_id', 'party.id')->where('role.role_code', $role)
            )->count();
        }

        return $summary;
    }

    private function countryLookups(string $companyId): array
    {
        return DB::table('party_addresses')->where('company_id', $companyId)
            ->distinct()->orderBy('country_code')->pluck('country_code')->all();
    }

    private function timestamp(mixed $value): string
    {
        return CarbonImmutable::parse((string) $value)->utc()->toIso8601String();
    }

    private function can(array $permissions, string $permission): bool
    {
        return in_array($permission, $permissions, true);
    }
}

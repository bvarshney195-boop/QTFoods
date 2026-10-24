<?php

namespace App\Modules\MasterData\Application;

use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PartyService
{
    public const STATUSES = ['DRAFT', 'ACTIVE', 'ON_HOLD', 'INACTIVE'];
    public const PARTY_KINDS = ['ORGANISATION', 'INDIVIDUAL'];
    public const ROLE_CODES = ['CUSTOMER', 'SUPPLIER', 'CARRIER', 'SERVICE_PROVIDER'];
    public const ADDRESS_TYPES = ['REGISTERED', 'BILLING', 'SHIPPING', 'REMITTANCE', 'OTHER'];
    public const TAX_TYPES = ['GSTIN', 'PAN', 'VAT', 'TIN', 'OTHER'];

    private const TRANSITIONS = [
        'DRAFT' => ['ACTIVE', 'INACTIVE'],
        'ACTIVE' => ['ON_HOLD', 'INACTIVE'],
        'ON_HOLD' => ['ACTIVE', 'INACTIVE'],
        'INACTIVE' => ['ACTIVE'],
    ];

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $namespace = 'master.party.create';
            $payload = $this->idempotencyPayload($data);
            $replay = $this->idempotency->begin($namespace, $data['idempotency_key'], $payload);
            if ($replay !== null) {
                return $replay;
            }

            $this->validateAggregate($data);
            if (DB::table('parties')->where('company_id', $data['company_id'])
                ->where('code', $data['code'])->exists()) {
                throw ValidationException::withMessages([
                    'code' => ['That party code already exists in the selected company.'],
                ]);
            }
            $this->assertTaxNumbersAvailable($data['tax_registrations'], $data['company_id']);

            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('parties')->insert([
                'id' => $id,
                'company_id' => $data['company_id'],
                'code' => $data['code'],
                'display_name' => trim($data['display_name']),
                'legal_name' => trim($data['legal_name']),
                'party_kind' => $data['party_kind'],
                'notes' => $this->nullable($data['notes'] ?? null),
                'status' => $data['status'],
                'record_version' => 1,
                'status_reason' => $data['status'] === 'ACTIVE' ? 'Activated during party creation.' : null,
                'status_changed_at' => $data['status'] === 'ACTIVE' ? $now : null,
                'status_changed_by' => $data['status'] === 'ACTIVE' ? $data['actor_id'] : null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->replaceAggregate($id, $data, $now, false);
            $result = $this->result($id, $data['status'], 1, $data);
            $this->record(
                'CREATE_PARTY',
                'master.party.created',
                $id,
                $data,
                1,
                [
                    'created' => [
                        'code' => $data['code'],
                        'party_kind' => $data['party_kind'],
                        'status' => $data['status'],
                        'roles' => $data['roles'],
                        'address_count' => count($data['addresses']),
                        'contact_count' => count($data['contacts']),
                        'tax_registration_count' => count($data['tax_registrations']),
                    ],
                ],
                $result,
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function update(string $partyId, array $data): array
    {
        return DB::transaction(function () use ($partyId, $data) {
            $namespace = 'master.party.update.'.$partyId;
            $payload = $this->idempotencyPayload($data) + ['party_id' => $partyId];
            $replay = $this->idempotency->begin($namespace, $data['idempotency_key'], $payload);
            if ($replay !== null) {
                return $replay;
            }

            $party = $this->findLocked($partyId, $data['company_id']);
            $this->assertVersion($party, $data['expected_version']);
            $data['status'] = (string) $party->status;
            $this->validateAggregate($data);
            $this->assertOwnedChildIds($partyId, $data['company_id'], $data);
            $this->assertTaxNumbersAvailable($data['tax_registrations'], $data['company_id'], $partyId);

            $version = (int) $party->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table('parties')->where('id', $partyId)->update([
                'display_name' => trim($data['display_name']),
                'legal_name' => trim($data['legal_name']),
                'party_kind' => $data['party_kind'],
                'notes' => $this->nullable($data['notes'] ?? null),
                'record_version' => $version,
                'updated_at' => $now,
            ]);
            $this->replaceAggregate($partyId, $data, $now, true);

            $result = $this->result($partyId, (string) $party->status, $version, $data);
            $this->record(
                'UPDATE_PARTY',
                'master.party.updated',
                $partyId,
                $data,
                $version,
                [
                    'display_name' => ['from' => $party->display_name, 'to' => trim($data['display_name'])],
                    'legal_name' => ['from' => $party->legal_name, 'to' => trim($data['legal_name'])],
                    'party_kind' => ['from' => $party->party_kind, 'to' => $data['party_kind']],
                    'roles' => $data['roles'],
                    'address_count' => count($data['addresses']),
                    'contact_count' => count($data['contacts']),
                    'tax_registration_count' => count($data['tax_registrations']),
                    'commercial_terms_changed' => true,
                ],
                $result,
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function changeStatus(string $partyId, string $targetStatus, string $reason, array $data): array
    {
        return DB::transaction(function () use ($partyId, $targetStatus, $reason, $data) {
            $namespace = 'master.party.status.'.$partyId;
            $payload = [
                'party_id' => $partyId,
                'target_status' => $targetStatus,
                'reason' => $reason,
                'expected_version' => $data['expected_version'],
            ];
            $replay = $this->idempotency->begin($namespace, $data['idempotency_key'], $payload);
            if ($replay !== null) {
                return $replay;
            }

            $party = $this->findLocked($partyId, $data['company_id']);
            $this->assertVersion($party, $data['expected_version']);
            $allowed = self::TRANSITIONS[$party->status] ?? [];
            if (! in_array($targetStatus, $allowed, true)) {
                throw ValidationException::withMessages([
                    'target_status' => ["A party cannot move from {$party->status} to {$targetStatus}."],
                ]);
            }
            if ($targetStatus === 'ACTIVE') {
                $this->assertReadyForActivation($partyId);
            }
            if ($targetStatus === 'INACTIVE') {
                $this->assertSafeToDeactivate($partyId);
            }

            $version = (int) $party->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table('parties')->where('id', $partyId)->update([
                'status' => $targetStatus,
                'status_reason' => $reason,
                'status_changed_at' => $now,
                'status_changed_by' => $data['actor_id'],
                'record_version' => $version,
                'updated_at' => $now,
            ]);

            $result = [
                'entity_type' => 'party',
                'id' => $partyId,
                'status' => $targetStatus,
                'record_version' => $version,
            ];
            $this->record(
                'CHANGE_PARTY_STATUS',
                'master.party.status_changed',
                $partyId,
                $data,
                $version,
                [
                    'status' => ['from' => $party->status, 'to' => $targetStatus],
                    'reason' => $reason,
                ],
                $result,
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function delete(string $partyId, array $data): array
    {
        return DB::transaction(function () use ($partyId, $data) {
            $namespace = 'master.party.delete.'.$partyId;
            $payload = ['party_id' => $partyId, 'expected_version' => $data['expected_version'], 'confirmed' => true];
            $replay = $this->idempotency->begin($namespace, $data['idempotency_key'], $payload);
            if ($replay !== null) {
                return $replay;
            }

            $party = $this->findLocked($partyId, $data['company_id']);
            $this->assertVersion($party, $data['expected_version']);
            try {
                DB::table('parties')->where('id', $partyId)->delete();
            } catch (QueryException) {
                throw ValidationException::withMessages([
                    'party' => ['This party is used by another ERP entry and cannot be deleted. Deactivate it instead.'],
                ]);
            }

            $version = (int) $party->record_version + 1;
            $result = [
                'entity_type' => 'party', 'id' => $partyId, 'status' => 'DELETED',
                'record_version' => $version, 'deleted' => true,
            ];
            $this->record('DELETE_PARTY', 'master.party.deleted', $partyId, $data, $version, [
                'deleted' => ['code' => $party->code, 'display_name' => $party->display_name],
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public static function allowedTransitions(string $status): array
    {
        return self::TRANSITIONS[$status] ?? [];
    }

    private function validateAggregate(array $data): void
    {
        if ($data['roles'] === []) {
            throw ValidationException::withMessages(['roles' => ['Select at least one party role.']]);
        }
        $this->assertAtMostOnePrimary($data['addresses'], 'address_type', 'addresses');
        $this->assertAtMostOnePrimary($data['contacts'], null, 'contacts');
        $this->assertAtMostOnePrimary($data['tax_registrations'], 'registration_type', 'tax_registrations');

        foreach ($data['contacts'] as $index => $contact) {
            if ($this->nullable($contact['email'] ?? null) === null
                && $this->nullable($contact['phone'] ?? null) === null
                && $this->nullable($contact['mobile'] ?? null) === null) {
                throw ValidationException::withMessages([
                    "contacts.{$index}.email" => ['Provide an email, phone, or mobile number for each contact.'],
                ]);
            }
        }
        foreach ($data['tax_registrations'] as $index => $registration) {
            if (! empty($registration['valid_from']) && ! empty($registration['valid_to'])
                && CarbonImmutable::parse($registration['valid_to'])->lt(CarbonImmutable::parse($registration['valid_from']))) {
                throw ValidationException::withMessages([
                    "tax_registrations.{$index}.valid_to" => ['The valid-to date must be on or after valid-from.'],
                ]);
            }
        }
        if (($data['status'] ?? null) === 'ACTIVE') {
            if ($data['addresses'] === []) {
                throw ValidationException::withMessages(['addresses' => ['An active party requires at least one address.']]);
            }
            if ($data['contacts'] === []) {
                throw ValidationException::withMessages(['contacts' => ['An active party requires at least one reachable contact.']]);
            }
        }
    }

    private function assertAtMostOnePrimary(array $rows, ?string $groupField, string $field): void
    {
        $seen = [];
        foreach ($rows as $index => $row) {
            if (! ($row['is_primary'] ?? false)) {
                continue;
            }
            $group = $groupField === null ? 'all' : (string) $row[$groupField];
            if (isset($seen[$group])) {
                throw ValidationException::withMessages([
                    "{$field}.{$index}.is_primary" => [
                        $groupField === null
                            ? 'Only one contact can be primary.'
                            : 'Only one primary record is allowed for each type.',
                    ],
                ]);
            }
            $seen[$group] = true;
        }
    }

    private function assertOwnedChildIds(string $partyId, string $companyId, array $data): void
    {
        foreach ([
            'addresses' => 'party_addresses',
            'contacts' => 'party_contacts',
            'tax_registrations' => 'party_tax_registrations',
        ] as $field => $table) {
            $ids = collect($data[$field])->pluck('id')->filter()->values();
            if ($ids->isEmpty()) {
                continue;
            }
            if ($ids->count() !== $ids->unique()->count()) {
                throw ValidationException::withMessages([
                    $field => ['A child record cannot be submitted more than once.'],
                ]);
            }
            $owned = DB::table($table)->where('party_id', $partyId)->where('company_id', $companyId)
                ->whereIn('id', $ids)->count();
            if ($owned !== $ids->unique()->count()) {
                throw ValidationException::withMessages([
                    $field => ['One or more submitted child records do not belong to this party.'],
                ]);
            }
        }
    }

    private function assertTaxNumbersAvailable(array $registrations, string $companyId, ?string $partyId = null): void
    {
        $seen = [];
        foreach ($registrations as $index => $registration) {
            $key = $registration['registration_type'].'|'.$registration['registration_number'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages([
                    "tax_registrations.{$index}.registration_number" => ['That tax registration is duplicated in this party.'],
                ]);
            }
            $seen[$key] = true;
            $query = DB::table('party_tax_registrations')
                ->where('company_id', $companyId)
                ->where('registration_type', $registration['registration_type'])
                ->where('registration_number', $registration['registration_number']);
            if ($partyId !== null) {
                $query->where('party_id', '<>', $partyId);
            }
            if ($query->exists()) {
                throw ValidationException::withMessages([
                    "tax_registrations.{$index}.registration_number" => [
                        'That tax registration already belongs to another party in this company.',
                    ],
                ]);
            }
        }
    }

    private function replaceAggregate(string $partyId, array $data, CarbonImmutable $now, bool $preserveIds): void
    {
        DB::table('party_roles')->where('party_id', $partyId)->delete();
        DB::table('party_roles')->insert(array_map(fn (string $role) => [
            'id' => (string) Str::uuid(),
            'company_id' => $data['company_id'],
            'party_id' => $partyId,
            'role_code' => $role,
            'created_at' => $now,
            'updated_at' => $now,
        ], $data['roles']));

        $this->replaceRows('party_addresses', $partyId, $data['company_id'], $data['addresses'], $now, $preserveIds,
            fn (array $row) => [
                'label' => trim($row['label']),
                'address_type' => $row['address_type'],
                'line_1' => trim($row['line_1']),
                'line_2' => $this->nullable($row['line_2'] ?? null),
                'city' => trim($row['city']),
                'district' => $this->nullable($row['district'] ?? null),
                'region' => trim($row['region']),
                'postal_code' => trim($row['postal_code']),
                'country_code' => $row['country_code'],
                'is_primary' => (bool) $row['is_primary'],
            ]);
        $this->replaceRows('party_contacts', $partyId, $data['company_id'], $data['contacts'], $now, $preserveIds,
            fn (array $row) => [
                'name' => trim($row['name']),
                'job_title' => $this->nullable($row['job_title'] ?? null),
                'department' => $this->nullable($row['department'] ?? null),
                'email' => $this->nullable(isset($row['email']) ? mb_strtolower(trim($row['email'])) : null),
                'phone' => $this->nullable($row['phone'] ?? null),
                'mobile' => $this->nullable($row['mobile'] ?? null),
                'is_primary' => (bool) $row['is_primary'],
            ]);
        $this->replaceRows('party_tax_registrations', $partyId, $data['company_id'], $data['tax_registrations'], $now, $preserveIds,
            fn (array $row) => [
                'registration_type' => $row['registration_type'],
                'registration_number' => trim($row['registration_number']),
                'country_code' => $row['country_code'],
                'is_primary' => (bool) $row['is_primary'],
                'valid_from' => $this->nullable($row['valid_from'] ?? null),
                'valid_to' => $this->nullable($row['valid_to'] ?? null),
            ]);

        $terms = $data['commercial_terms'];
        $existingTerms = DB::table('party_commercial_terms')->where('party_id', $partyId)->first();
        DB::table('party_commercial_terms')->updateOrInsert(['party_id' => $partyId], [
            'id' => $existingTerms?->id ?? (string) Str::uuid(),
            'company_id' => $data['company_id'],
            'currency_code' => $terms['currency_code'],
            'payment_terms_days' => (int) $terms['payment_terms_days'],
            'credit_limit' => $terms['credit_limit'],
            'credit_hold' => (bool) $terms['credit_hold'],
            'incoterm_code' => $this->nullable($terms['incoterm_code'] ?? null),
            'delivery_terms' => $this->nullable($terms['delivery_terms'] ?? null),
            'created_at' => $existingTerms?->created_at ?? $now,
            'updated_at' => $now,
        ]);
    }

    private function replaceRows(
        string $table,
        string $partyId,
        string $companyId,
        array $rows,
        CarbonImmutable $now,
        bool $preserveIds,
        callable $map,
    ): void {
        $existing = DB::table($table)->where('party_id', $partyId)->get()->keyBy('id');
        DB::table($table)->where('party_id', $partyId)->delete();
        if ($rows === []) {
            return;
        }
        DB::table($table)->insert(array_map(function (array $row) use (
            $existing, $partyId, $companyId, $now, $preserveIds, $map
        ): array {
            $id = $preserveIds && isset($row['id']) && $row['id'] !== null
                ? (string) $row['id']
                : (string) Str::uuid();

            return [
                'id' => $id,
                'company_id' => $companyId,
                'party_id' => $partyId,
                'created_at' => $existing->get($id)?->created_at ?? $now,
                'updated_at' => $now,
            ] + $map($row);
        }, $rows));
    }

    private function assertReadyForActivation(string $partyId): void
    {
        if (! DB::table('party_roles')->where('party_id', $partyId)->exists()) {
            throw ValidationException::withMessages(['target_status' => ['Add at least one party role before activation.']]);
        }
        if (! DB::table('party_addresses')->where('party_id', $partyId)->exists()) {
            throw ValidationException::withMessages(['target_status' => ['Add at least one address before activation.']]);
        }
        if (! DB::table('party_contacts')->where('party_id', $partyId)
            ->where(function ($query): void {
                $query->whereNotNull('email')->orWhereNotNull('phone')->orWhereNotNull('mobile');
            })->exists()) {
            throw ValidationException::withMessages(['target_status' => ['Add a reachable contact before activation.']]);
        }
    }

    private function assertSafeToDeactivate(string $partyId): void
    {
        if (DB::table('inventory_owners')->where('party_id', $partyId)->where('status', 'ACTIVE')->exists()) {
            throw ValidationException::withMessages([
                'target_status' => ['Deactivate the party inventory owner before deactivating this party.'],
            ]);
        }
        if (DB::table('lots')->where('supplier_party_id', $partyId)->where('status', 'ACTIVE')->exists()) {
            throw ValidationException::withMessages([
                'target_status' => ['Close or recall every active lot supplied by this party before deactivation.'],
            ]);
        }
        if (DB::table('unsold_return_cases')->where('party_id', $partyId)
            ->where('status', '<>', 'FINANCE_RESOLVED')->exists()) {
            throw ValidationException::withMessages([
                'target_status' => ['Resolve all open Unsold Return cases before deactivating this party.'],
            ]);
        }
        if (DB::table('stock_positions')->where('owner_party_id', $partyId)
            ->where('quantity_base', '>', 0)->exists()) {
            throw ValidationException::withMessages([
                'target_status' => ['Move all party-owned stock before deactivating this party.'],
            ]);
        }
        if (DB::table('role_assignments')->where('party_id', $partyId)->where('is_active', true)
            ->where(function ($query): void {
                $query->whereNull('effective_to')->orWhere('effective_to', '>', now());
            })->exists()) {
            throw ValidationException::withMessages([
                'target_status' => ['End active portal role assignments before deactivating this party.'],
            ]);
        }
    }

    private function findLocked(string $partyId, string $companyId): object
    {
        $party = DB::table('parties')->where('id', $partyId)->where('company_id', $companyId)
            ->lockForUpdate()->first();
        if (! $party) {
            throw new NotFoundHttpException('Party not found.');
        }

        return $party;
    }

    private function assertVersion(object $party, int $expectedVersion): void
    {
        if ((int) $party->record_version !== $expectedVersion) {
            throw new ConflictHttpException(
                "The party changed from version {$expectedVersion} to {$party->record_version}. Refresh it before saving."
            );
        }
    }

    private function result(string $partyId, string $status, int $version, array $data): array
    {
        return [
            'entity_type' => 'party',
            'id' => $partyId,
            'status' => $status,
            'record_version' => $version,
            'role_count' => count($data['roles']),
            'address_count' => count($data['addresses']),
            'contact_count' => count($data['contacts']),
            'tax_registration_count' => count($data['tax_registrations']),
        ];
    }

    private function record(
        string $command,
        string $eventType,
        string $partyId,
        array $data,
        int $version,
        array $safeDiff,
        array $result,
    ): void {
        $this->audit->record($command, 'party', $partyId, $data['actor_id'],
            $data['company_id'], $data['plant_id'], 'SUCCESS', [
                'entity_version' => $version,
                'correlation_id' => $data['correlation_id'] ?? null,
                'safe_diff' => $safeDiff,
            ]);
        $this->outbox->append($eventType, 'party', $partyId, $partyId.':'.$version,
            $result, $data['correlation_id'] ?? null, $data['company_id'], $data['plant_id']);
    }

    private function idempotencyPayload(array $data): array
    {
        return collect($data)->except(['actor_id', 'permissions', 'idempotency_key', 'correlation_id'])->all();
    }

    private function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}

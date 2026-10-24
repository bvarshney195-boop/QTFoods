<?php

namespace App\Modules\Scale\Application;

use App\Modules\Inventory\Application\StockPostingService;
use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class MultiPlantService
{
    public const GROUP_STATUSES = ['DRAFT', 'ACTIVE', 'RETIRED'];
    public const ROUTE_STATUSES = ['DRAFT', 'ACTIVE', 'INACTIVE'];
    public const TRANSFER_STATUSES = ['DRAFT', 'SUBMITTED', 'SOURCE_APPROVED', 'APPROVED', 'IN_TRANSIT', 'RECEIVED', 'CANCELLED'];

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
        private readonly StockPostingService $stock,
    ) {}

    public function createGroup(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'scale.group.create';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }

            $members = $this->prepareMembers($data['members'], $data, 'GROUP-CREATE');
            $id = (string) Str::uuid();
            $now = now();
            DB::table('consolidation_groups')->insert([
                'id' => $id,
                'owner_company_id' => $data['company_id'],
                'group_code' => $data['group_code'],
                'name' => trim($data['name']),
                'base_currency' => $data['base_currency'],
                'status' => 'DRAFT',
                'record_version' => 1,
                'created_by' => $data['actor_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->insertMembers($id, $data['company_id'], $members);

            $result = $this->result($id, 'consolidation_group', 'DRAFT', 1) + ['member_count' => count($members)];
            $this->record('CREATE_CONSOLIDATION_GROUP', 'scale.consolidation-group.created', 'consolidation_group', $id, $data, 1, [
                'group_code' => $data['group_code'], 'member_count' => count($members),
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function updateGroup(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'scale.group.update.'.$id;
            if ($replay = $this->begin($namespace, $data + ['id' => $id])) {
                return $replay;
            }
            $group = $this->groupLocked($id, $data);
            $this->assertVersion($group, $data['expected_version']);
            $this->assertState($group, ['DRAFT'], 'updated');
            $members = $this->prepareMembers($data['members'], $data, 'GROUP-UPDATE');
            $version = (int) $group->record_version + 1;
            DB::table('consolidation_groups')->where('id', $id)->update([
                'name' => trim($data['name']),
                'base_currency' => $data['base_currency'],
                'record_version' => $version,
                'updated_at' => now(),
            ]);
            DB::table('consolidation_group_members')->where('consolidation_group_id', $id)->delete();
            $this->insertMembers($id, $data['company_id'], $members);
            $result = $this->result($id, 'consolidation_group', 'DRAFT', $version) + ['member_count' => count($members)];
            $this->record('UPDATE_CONSOLIDATION_GROUP', 'scale.consolidation-group.updated', 'consolidation_group', $id, $data, $version, [
                'member_count' => count($members),
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function activateGroup(string $id, array $data): array
    {
        return $this->transitionGroup($id, 'ACTIVE', null, $data);
    }

    public function retireGroup(string $id, string $reason, array $data): array
    {
        return $this->transitionGroup($id, 'RETIRED', $reason, $data);
    }

    public function createRoute(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'scale.route.create';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }

            $route = $this->prepareRoute($data, $data);
            $mappings = $this->prepareMappings($route, $data['mappings']);
            $id = (string) Str::uuid();
            $now = now();
            DB::table('plant_transfer_routes')->insert([
                'id' => $id,
                ...Arr::only($route, [
                    'source_company_id', 'source_plant_id', 'destination_company_id', 'destination_plant_id',
                    'consolidation_group_id', 'route_code', 'name', 'transfer_scope', 'currency', 'transit_days',
                    'markup_percent', 'require_destination_acceptance', 'require_commercial_reference',
                ]),
                'status' => 'DRAFT',
                'record_version' => 1,
                'created_by' => $data['actor_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->insertMappings($id, $route, $mappings);
            $result = $this->result($id, 'plant_transfer_route', 'DRAFT', 1) + [
                'transfer_scope' => $route['transfer_scope'], 'mapping_count' => count($mappings),
            ];
            $this->record('CREATE_PLANT_TRANSFER_ROUTE', 'scale.transfer-route.created', 'plant_transfer_route', $id, $data, 1, [
                'destination_company_id' => $route['destination_company_id'],
                'destination_plant_id' => $route['destination_plant_id'],
                'transfer_scope' => $route['transfer_scope'],
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function updateRoute(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'scale.route.update.'.$id;
            if ($replay = $this->begin($namespace, $data + ['id' => $id])) {
                return $replay;
            }
            $existing = $this->routeLocked($id, $data);
            $this->assertVersion($existing, $data['expected_version']);
            $this->assertState($existing, ['DRAFT'], 'updated');
            $route = [
                'source_company_id' => (string) $existing->source_company_id,
                'source_plant_id' => (string) $existing->source_plant_id,
                'destination_company_id' => (string) $existing->destination_company_id,
                'destination_plant_id' => (string) $existing->destination_plant_id,
                'consolidation_group_id' => $existing->consolidation_group_id,
                'route_code' => (string) $existing->route_code,
                'name' => trim($data['name']),
                'transfer_scope' => (string) $existing->transfer_scope,
                'currency' => $data['currency'],
                'transit_days' => (int) $data['transit_days'],
                'markup_percent' => $this->decimal($data['markup_percent'], 4),
                'require_destination_acceptance' => (bool) $existing->require_destination_acceptance,
                'require_commercial_reference' => (bool) $existing->require_commercial_reference,
            ];
            $mappings = $this->prepareMappings($route, $data['mappings']);
            $version = (int) $existing->record_version + 1;
            DB::table('plant_transfer_routes')->where('id', $id)->update([
                'name' => $route['name'], 'currency' => $route['currency'], 'transit_days' => $route['transit_days'],
                'markup_percent' => $route['markup_percent'], 'record_version' => $version, 'updated_at' => now(),
            ]);
            DB::table('plant_transfer_item_mappings')->where('plant_transfer_route_id', $id)->delete();
            $this->insertMappings($id, $route, $mappings);
            $result = $this->result($id, 'plant_transfer_route', 'DRAFT', $version) + ['mapping_count' => count($mappings)];
            $this->record('UPDATE_PLANT_TRANSFER_ROUTE', 'scale.transfer-route.updated', 'plant_transfer_route', $id, $data, $version, [
                'mapping_count' => count($mappings),
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function activateRoute(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'scale.route.activate.'.$id;
            if ($replay = $this->begin($namespace, $data + ['id' => $id])) {
                return $replay;
            }
            $route = $this->routeLocked($id, $data);
            $this->assertVersion($route, $data['expected_version']);
            $this->assertState($route, ['DRAFT'], 'activated');
            $this->assertRouteReady($route, $data);
            if (! DB::table('plant_transfer_item_mappings')->where('plant_transfer_route_id', $id)->exists()) {
                throw new ConflictHttpException('A transfer route needs at least one governed item mapping.');
            }
            $version = (int) $route->record_version + 1;
            DB::table('plant_transfer_routes')->where('id', $id)->update([
                'status' => 'ACTIVE', 'record_version' => $version,
                'activated_at' => now(), 'activated_by' => $data['actor_id'], 'updated_at' => now(),
            ]);
            $result = $this->result($id, 'plant_transfer_route', 'ACTIVE', $version);
            $this->record('ACTIVATE_PLANT_TRANSFER_ROUTE', 'scale.transfer-route.activated', 'plant_transfer_route', $id, $data, $version, [
                'status' => ['from' => 'DRAFT', 'to' => 'ACTIVE'],
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function deactivateRoute(string $id, string $reason, array $data): array
    {
        return DB::transaction(function () use ($id, $reason, $data): array {
            $namespace = 'scale.route.deactivate.'.$id;
            if ($replay = $this->begin($namespace, $data + ['id' => $id, 'reason' => $reason])) {
                return $replay;
            }
            $route = $this->routeLocked($id, $data);
            $this->assertVersion($route, $data['expected_version']);
            $this->assertState($route, ['ACTIVE'], 'deactivated');
            if (DB::table('interplant_transfer_orders')->where('plant_transfer_route_id', $id)
                ->whereIn('status', ['DRAFT', 'SUBMITTED', 'SOURCE_APPROVED', 'APPROVED', 'IN_TRANSIT'])->exists()) {
                throw new ConflictHttpException('The route has open transfers and cannot be deactivated.');
            }
            $version = (int) $route->record_version + 1;
            DB::table('plant_transfer_routes')->where('id', $id)->update([
                'status' => 'INACTIVE', 'record_version' => $version, 'deactivated_at' => now(),
                'deactivated_by' => $data['actor_id'], 'deactivation_reason' => $reason, 'updated_at' => now(),
            ]);
            $result = $this->result($id, 'plant_transfer_route', 'INACTIVE', $version);
            $this->record('DEACTIVATE_PLANT_TRANSFER_ROUTE', 'scale.transfer-route.deactivated', 'plant_transfer_route', $id, $data, $version, [
                'status' => ['from' => 'ACTIVE', 'to' => 'INACTIVE'], 'reason' => $reason,
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function createTransfer(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'scale.transfer.create';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }
            $route = $this->activeSourceRoute($data['plant_transfer_route_id'], $data);
            $this->assertTransferDates($data['transfer_date'], $data['expected_arrival_date']);
            $this->assertCommercialReference($route, $data['commercial_reference'] ?? null);
            $lines = $this->prepareTransferLines($route, $data['lines']);
            $id = (string) Str::uuid();
            $now = now();
            DB::table('interplant_transfer_orders')->insert([
                'id' => $id,
                'plant_transfer_route_id' => $route->id,
                'source_company_id' => $route->source_company_id,
                'source_plant_id' => $route->source_plant_id,
                'destination_company_id' => $route->destination_company_id,
                'destination_plant_id' => $route->destination_plant_id,
                'transfer_number' => $data['transfer_number'],
                'transfer_date' => $data['transfer_date'],
                'expected_arrival_date' => $data['expected_arrival_date'],
                'status' => 'DRAFT',
                'commercial_reference' => $this->nullable($data['commercial_reference'] ?? null),
                'notes' => $this->nullable($data['notes'] ?? null),
                'record_version' => 1,
                'created_by' => $data['actor_id'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->insertTransferLines($id, $route, $lines);
            $result = $this->result($id, 'interplant_transfer', 'DRAFT', 1) + [
                'transfer_number' => $data['transfer_number'], 'line_count' => count($lines),
                'transfer_scope' => $route->transfer_scope,
            ];
            $this->record('CREATE_INTERPLANT_TRANSFER', 'scale.interplant-transfer.created', 'interplant_transfer', $id, $data, 1, [
                'route_id' => $route->id, 'destination_company_id' => $route->destination_company_id,
                'destination_plant_id' => $route->destination_plant_id, 'line_count' => count($lines),
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function updateTransfer(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'scale.transfer.update.'.$id;
            if ($replay = $this->begin($namespace, $data + ['id' => $id])) {
                return $replay;
            }
            $transfer = $this->sourceTransferLocked($id, $data);
            $this->assertVersion($transfer, $data['expected_version']);
            $this->assertState($transfer, ['DRAFT'], 'updated');
            $route = $this->activeSourceRoute((string) $transfer->plant_transfer_route_id, $data);
            $this->assertTransferDates($data['transfer_date'], $data['expected_arrival_date']);
            $this->assertCommercialReference($route, $data['commercial_reference'] ?? null);
            $lines = $this->prepareTransferLines($route, $data['lines']);
            $version = (int) $transfer->record_version + 1;
            DB::table('interplant_transfer_orders')->where('id', $id)->update([
                'transfer_date' => $data['transfer_date'], 'expected_arrival_date' => $data['expected_arrival_date'],
                'commercial_reference' => $this->nullable($data['commercial_reference'] ?? null),
                'notes' => $this->nullable($data['notes'] ?? null), 'record_version' => $version, 'updated_at' => now(),
            ]);
            DB::table('interplant_transfer_lines')->where('interplant_transfer_order_id', $id)->delete();
            $this->insertTransferLines($id, $route, $lines);
            $result = $this->result($id, 'interplant_transfer', 'DRAFT', $version) + ['line_count' => count($lines)];
            $this->record('UPDATE_INTERPLANT_TRANSFER', 'scale.interplant-transfer.updated', 'interplant_transfer', $id, $data, $version, [
                'line_count' => count($lines),
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function submitTransfer(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'scale.transfer.submit.'.$id;
            if ($replay = $this->begin($namespace, $data + ['id' => $id])) {
                return $replay;
            }
            $transfer = $this->sourceTransferLocked($id, $data);
            $this->assertVersion($transfer, $data['expected_version']);
            $this->assertState($transfer, ['DRAFT'], 'submitted');
            $this->assertCommercialReference($transfer, $transfer->commercial_reference);
            $this->revalidateTransferLines($transfer);
            $version = (int) $transfer->record_version + 1;
            DB::table('interplant_transfer_orders')->where('id', $id)->update([
                'status' => 'SUBMITTED', 'record_version' => $version,
                'submitted_at' => now(), 'submitted_by' => $data['actor_id'], 'updated_at' => now(),
            ]);
            $result = $this->result($id, 'interplant_transfer', 'SUBMITTED', $version);
            $this->record('SUBMIT_INTERPLANT_TRANSFER', 'scale.interplant-transfer.submitted', 'interplant_transfer', $id, $data, $version, [
                'status' => ['from' => 'DRAFT', 'to' => 'SUBMITTED'],
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function approveTransfer(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'scale.transfer.approve.'.$id;
            if ($replay = $this->begin($namespace, $data + ['id' => $id])) {
                return $replay;
            }
            $transfer = $this->sourceTransferLocked($id, $data);
            $this->assertVersion($transfer, $data['expected_version']);
            $this->assertState($transfer, ['SUBMITTED'], 'approved');
            if (in_array($data['actor_id'], [$transfer->created_by, $transfer->submitted_by], true)) {
                throw ValidationException::withMessages(['actor' => ['The transfer maker cannot approve the same transfer.']]);
            }
            $crossCompany = $transfer->source_company_id !== $transfer->destination_company_id;
            $status = $crossCompany && (bool) $transfer->require_destination_acceptance ? 'SOURCE_APPROVED' : 'APPROVED';
            $version = (int) $transfer->record_version + 1;
            DB::table('interplant_transfer_orders')->where('id', $id)->update([
                'status' => $status, 'record_version' => $version,
                'source_approved_at' => now(), 'source_approved_by' => $data['actor_id'], 'updated_at' => now(),
            ]);
            $result = $this->result($id, 'interplant_transfer', $status, $version);
            $this->record('APPROVE_INTERPLANT_TRANSFER', 'scale.interplant-transfer.source-approved', 'interplant_transfer', $id, $data, $version, [
                'status' => ['from' => 'SUBMITTED', 'to' => $status],
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function acceptTransfer(string $id, string $reference, array $data): array
    {
        return DB::transaction(function () use ($id, $reference, $data): array {
            $namespace = 'scale.transfer.accept.'.$id;
            if ($replay = $this->begin($namespace, $data + ['id' => $id, 'destination_reference' => $reference])) {
                return $replay;
            }
            $transfer = $this->destinationTransferLocked($id, $data);
            $this->assertVersion($transfer, $data['expected_version']);
            $this->assertState($transfer, ['SOURCE_APPROVED'], 'accepted');
            if (! (bool) $transfer->require_destination_acceptance || $transfer->source_company_id === $transfer->destination_company_id) {
                throw new ConflictHttpException('Destination acceptance is reserved for controlled cross-company routes.');
            }
            if (in_array($data['actor_id'], [$transfer->created_by, $transfer->submitted_by, $transfer->source_approved_by], true)) {
                throw ValidationException::withMessages(['actor' => ['Destination acceptance requires an independent authorised actor.']]);
            }
            $version = (int) $transfer->record_version + 1;
            DB::table('interplant_transfer_orders')->where('id', $id)->update([
                'status' => 'APPROVED', 'record_version' => $version,
                'destination_reference' => $reference, 'destination_accepted_at' => now(),
                'destination_accepted_by' => $data['actor_id'], 'updated_at' => now(),
            ]);
            $result = $this->result($id, 'interplant_transfer', 'APPROVED', $version);
            $this->record('ACCEPT_INTERCOMPANY_TRANSFER', 'scale.interplant-transfer.destination-accepted', 'interplant_transfer', $id, $data, $version, [
                'status' => ['from' => 'SOURCE_APPROVED', 'to' => 'APPROVED'], 'destination_reference' => $reference,
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function dispatchTransfer(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'scale.transfer.dispatch.'.$id;
            if ($replay = $this->begin($namespace, $data + ['id' => $id])) {
                return $replay;
            }
            $transfer = $this->sourceTransferLocked($id, $data);
            $this->assertVersion($transfer, $data['expected_version']);
            $this->assertState($transfer, ['APPROVED'], 'dispatched');
            $this->revalidateTransferLines($transfer);
            $lines = DB::table('interplant_transfer_lines')->where('interplant_transfer_order_id', $id)
                ->orderBy('source_position_id')->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw new ConflictHttpException('The transfer has no stock lines to dispatch.');
            }
            $version = (int) $transfer->record_version + 1;
            $movements = [];
            foreach ($lines as $line) {
                $movement = $this->stock->issue([
                    'company_id' => (string) $transfer->source_company_id,
                    'plant_id' => (string) $transfer->source_plant_id,
                    'source_position_id' => (string) $line->source_position_id,
                    'quantity_base' => (string) $line->source_quantity,
                    'uom_code' => (string) $line->source_uom_code,
                    'movement_type' => 'INTERPLANT_DISPATCH',
                    'source_type' => 'INTERPLANT_TRANSFER',
                    'source_id' => $id,
                    'source_version' => $version,
                    'actor_id' => $data['actor_id'],
                    'reason_code' => 'PLANT_TRANSFER',
                    'idempotency_key' => 'scale-transfer:'.$id.':dispatch:'.$line->id,
                    'correlation_id' => $data['correlation_id'] ?? null,
                    'expected_item_id' => (string) $line->source_item_id,
                    'expected_lot_id' => (string) $line->source_lot_id,
                    'expected_owner_id' => (string) $line->source_inventory_owner_id,
                    'expected_quality_status' => 'RELEASED',
                ]);
                $movements[] = $movement['movement_id'];
                DB::table('interplant_transfer_lines')->where('id', $line->id)->update([
                    'dispatched_source_quantity' => $line->source_quantity,
                    'outbound_movement_id' => $movement['movement_id'], 'updated_at' => now(),
                ]);
            }
            DB::table('interplant_transfer_orders')->where('id', $id)->update([
                'status' => 'IN_TRANSIT', 'record_version' => $version,
                'dispatched_at' => now(), 'dispatched_by' => $data['actor_id'], 'updated_at' => now(),
            ]);
            $result = $this->result($id, 'interplant_transfer', 'IN_TRANSIT', $version) + ['movement_ids' => $movements];
            $this->record('DISPATCH_INTERPLANT_TRANSFER', 'scale.interplant-transfer.dispatched', 'interplant_transfer', $id, $data, $version, [
                'status' => ['from' => 'APPROVED', 'to' => 'IN_TRANSIT'], 'movement_ids' => $movements,
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function receiveTransfer(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'scale.transfer.receive.'.$id;
            if ($replay = $this->begin($namespace, $data + ['id' => $id])) {
                return $replay;
            }
            $transfer = $this->destinationTransferLocked($id, $data);
            $this->assertVersion($transfer, $data['expected_version']);
            $this->assertState($transfer, ['IN_TRANSIT'], 'received');
            $lines = DB::table('interplant_transfer_lines')->where('interplant_transfer_order_id', $id)
                ->orderBy('destination_position_id')->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw new ConflictHttpException('The transfer has no stock lines to receive.');
            }
            $version = (int) $transfer->record_version + 1;
            $movements = [];
            foreach ($lines as $line) {
                if (bccomp((string) $line->dispatched_source_quantity, (string) $line->source_quantity, 6) !== 0) {
                    throw new ConflictHttpException('Every line must be fully dispatched before destination receipt.');
                }
                $this->validatedTransferPosition($line, $transfer, 'destination');
                $movement = $this->stock->receive([
                    'company_id' => (string) $transfer->destination_company_id,
                    'plant_id' => (string) $transfer->destination_plant_id,
                    'target_position_id' => (string) $line->destination_position_id,
                    'quantity_base' => (string) $line->destination_quantity,
                    'uom_code' => (string) $line->destination_uom_code,
                    'movement_type' => 'INTERPLANT_RECEIPT',
                    'source_type' => 'INTERPLANT_TRANSFER',
                    'source_id' => $id,
                    'source_version' => $version,
                    'actor_id' => $data['actor_id'],
                    'reason_code' => 'PLANT_TRANSFER',
                    'idempotency_key' => 'scale-transfer:'.$id.':receive:'.$line->id,
                    'correlation_id' => $data['correlation_id'] ?? null,
                    'expected_item_id' => (string) $line->destination_item_id,
                    'expected_lot_id' => (string) $line->destination_lot_id,
                    'expected_owner_id' => (string) $line->destination_inventory_owner_id,
                    'expected_quality_status' => 'RELEASED',
                ]);
                $movements[] = $movement['movement_id'];
                DB::table('interplant_transfer_lines')->where('id', $line->id)->update([
                    'received_destination_quantity' => $line->destination_quantity,
                    'inbound_movement_id' => $movement['movement_id'], 'updated_at' => now(),
                ]);
            }
            DB::table('interplant_transfer_orders')->where('id', $id)->update([
                'status' => 'RECEIVED', 'record_version' => $version,
                'received_at' => now(), 'received_by' => $data['actor_id'], 'updated_at' => now(),
            ]);
            $result = $this->result($id, 'interplant_transfer', 'RECEIVED', $version) + ['movement_ids' => $movements];
            $this->record('RECEIVE_INTERPLANT_TRANSFER', 'scale.interplant-transfer.received', 'interplant_transfer', $id, $data, $version, [
                'status' => ['from' => 'IN_TRANSIT', 'to' => 'RECEIVED'], 'movement_ids' => $movements,
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function cancelTransfer(string $id, string $reason, array $data): array
    {
        return DB::transaction(function () use ($id, $reason, $data): array {
            $namespace = 'scale.transfer.cancel.'.$id;
            if ($replay = $this->begin($namespace, $data + ['id' => $id, 'reason' => $reason])) {
                return $replay;
            }
            $transfer = $this->sourceTransferLocked($id, $data);
            $this->assertVersion($transfer, $data['expected_version']);
            $this->assertState($transfer, ['DRAFT', 'SUBMITTED', 'SOURCE_APPROVED', 'APPROVED'], 'cancelled');
            $from = (string) $transfer->status;
            $version = (int) $transfer->record_version + 1;
            DB::table('interplant_transfer_orders')->where('id', $id)->update([
                'status' => 'CANCELLED', 'record_version' => $version, 'cancelled_at' => now(),
                'cancelled_by' => $data['actor_id'], 'cancellation_reason' => $reason, 'updated_at' => now(),
            ]);
            $result = $this->result($id, 'interplant_transfer', 'CANCELLED', $version);
            $this->record('CANCEL_INTERPLANT_TRANSFER', 'scale.interplant-transfer.cancelled', 'interplant_transfer', $id, $data, $version, [
                'status' => ['from' => $from, 'to' => 'CANCELLED'], 'reason' => $reason,
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function createConsolidation(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'scale.consolidation.create';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }
            $group = DB::table('consolidation_groups')->where('id', $data['consolidation_group_id'])
                ->where('owner_company_id', $data['company_id'])->where('status', 'ACTIVE')->lockForUpdate()->first();
            if (! $group) {
                throw ValidationException::withMessages(['consolidation_group_id' => ['Select an active consolidation group owned by this company.']]);
            }
            $members = DB::table('consolidation_group_members')->where('consolidation_group_id', $group->id)
                ->where('effective_from', '<=', $data['cutoff_date'])
                ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', $data['cutoff_date']))
                ->orderBy('company_id')->get();
            if ($members->isEmpty()) {
                throw new ConflictHttpException('The group has no effective members at the requested cutoff.');
            }
            $rates = collect($data['member_rates'])->keyBy('company_id');
            if ($rates->count() !== $members->count() || $members->contains(fn (object $member): bool => ! $rates->has($member->company_id))) {
                throw ValidationException::withMessages(['member_rates' => ['Provide exactly one positive exchange rate for every effective group member.']]);
            }

            $memberSnapshots = [];
            $translatedDebit = $translatedCredit = '0.0000';
            foreach ($members as $member) {
                $this->assertPermissionAt($data['actor_id'], (string) $member->company_id, null, 'CONSOLIDATE');
                $rate = $this->positive($rates->get($member->company_id)['exchange_rate'], 'member_rates.exchange_rate', 8);
                $totals = DB::table('journal_lines as line')->join('journals as journal', 'journal.id', '=', 'line.journal_id')
                    ->where('journal.company_id', $member->company_id)->where('journal.status', 'POSTED')
                    ->whereDate('journal.posting_date', '<=', $data['cutoff_date'])
                    ->selectRaw('COALESCE(SUM(line.debit_amount), 0) as debit, COALESCE(SUM(line.credit_amount), 0) as credit')->first();
                $sourceDebit = $this->decimal($totals?->debit ?? 0, 4);
                $sourceCredit = $this->decimal($totals?->credit ?? 0, 4);
                if (bccomp($sourceDebit, $sourceCredit, 4) !== 0) {
                    throw new ConflictHttpException('A member trial balance is not balanced at the requested cutoff.');
                }
                $memberDebit = bcmul($sourceDebit, $rate, 4);
                $memberCredit = bcmul($sourceCredit, $rate, 4);
                $translatedDebit = bcadd($translatedDebit, $memberDebit, 4);
                $translatedCredit = bcadd($translatedCredit, $memberCredit, 4);
                $memberSnapshots[] = compact('member', 'rate', 'sourceDebit', 'sourceCredit', 'memberDebit', 'memberCredit');
            }

            [$eliminations, $eliminationDebit, $eliminationCredit] = $this->prepareEliminations($data['eliminations'] ?? []);
            $consolidatedDebit = bcadd($translatedDebit, $eliminationDebit, 4);
            $consolidatedCredit = bcadd($translatedCredit, $eliminationCredit, 4);
            $id = (string) Str::uuid();
            $now = now();
            DB::table('consolidation_runs')->insert([
                'id' => $id, 'consolidation_group_id' => $group->id,
                'reporting_company_id' => $data['company_id'], 'reporting_plant_id' => $data['plant_id'],
                'run_number' => $data['run_number'], 'cutoff_date' => $data['cutoff_date'],
                'base_currency' => $group->base_currency, 'status' => 'DRAFT',
                'translated_debit' => $translatedDebit, 'translated_credit' => $translatedCredit,
                'elimination_debit' => $eliminationDebit, 'elimination_credit' => $eliminationCredit,
                'consolidated_debit' => $consolidatedDebit, 'consolidated_credit' => $consolidatedCredit,
                'record_version' => 1, 'created_by' => $data['actor_id'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($memberSnapshots as $snapshot) {
                DB::table('consolidation_run_members')->insert([
                    'id' => (string) Str::uuid(), 'consolidation_run_id' => $id,
                    'consolidation_group_id' => $group->id,
                    'consolidation_group_member_id' => $snapshot['member']->id,
                    'company_id' => $snapshot['member']->company_id, 'exchange_rate' => $snapshot['rate'],
                    'source_debit' => $snapshot['sourceDebit'], 'source_credit' => $snapshot['sourceCredit'],
                    'translated_debit' => $snapshot['memberDebit'], 'translated_credit' => $snapshot['memberCredit'],
                    'captured_at' => $now,
                ]);
            }
            foreach ($eliminations as $index => $line) {
                DB::table('consolidation_elimination_lines')->insert([
                    'id' => (string) Str::uuid(), 'consolidation_run_id' => $id, 'line_number' => $index + 1,
                    ...$line, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $result = $this->result($id, 'consolidation_run', 'DRAFT', 1) + [
                'member_count' => count($memberSnapshots), 'consolidated_debit' => $consolidatedDebit,
                'consolidated_credit' => $consolidatedCredit,
            ];
            $this->record('CREATE_CONSOLIDATION_RUN', 'scale.consolidation.created', 'consolidation_run', $id, $data, 1, [
                'cutoff_date' => $data['cutoff_date'], 'member_count' => count($memberSnapshots),
                'elimination_line_count' => count($eliminations),
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function finalizeConsolidation(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'scale.consolidation.finalize.'.$id;
            if ($replay = $this->begin($namespace, $data + ['id' => $id])) {
                return $replay;
            }
            $run = DB::table('consolidation_runs')->where('id', $id)->where('reporting_company_id', $data['company_id'])
                ->where('reporting_plant_id', $data['plant_id'])->lockForUpdate()->first();
            if (! $run) {
                throw new ConflictHttpException('Consolidation run not found in the selected scope.');
            }
            $this->assertVersion($run, $data['expected_version']);
            $this->assertState($run, ['DRAFT'], 'finalized');
            if ($run->created_by === $data['actor_id']) {
                throw ValidationException::withMessages(['actor' => ['The consolidation maker cannot finalize the same snapshot.']]);
            }
            if (bccomp((string) $run->translated_debit, (string) $run->translated_credit, 4) !== 0
                || bccomp((string) $run->elimination_debit, (string) $run->elimination_credit, 4) !== 0
                || bccomp((string) $run->consolidated_debit, (string) $run->consolidated_credit, 4) !== 0) {
                throw new ConflictHttpException('Only a balanced source, elimination, and consolidated snapshot can be finalized.');
            }
            $version = (int) $run->record_version + 1;
            DB::table('consolidation_runs')->where('id', $id)->update([
                'status' => 'FINALIZED', 'record_version' => $version,
                'finalized_at' => now(), 'finalized_by' => $data['actor_id'], 'updated_at' => now(),
            ]);
            $result = $this->result($id, 'consolidation_run', 'FINALIZED', $version) + [
                'consolidated_debit' => $this->decimal($run->consolidated_debit, 4),
                'consolidated_credit' => $this->decimal($run->consolidated_credit, 4),
            ];
            $this->record('FINALIZE_CONSOLIDATION_RUN', 'scale.consolidation.finalized', 'consolidation_run', $id, $data, $version, [
                'status' => ['from' => 'DRAFT', 'to' => 'FINALIZED'],
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    private function transitionGroup(string $id, string $target, ?string $reason, array $data): array
    {
        return DB::transaction(function () use ($id, $target, $reason, $data): array {
            $namespace = 'scale.group.'.strtolower($target).'.'.$id;
            if ($replay = $this->begin($namespace, $data + ['id' => $id, 'reason' => $reason])) {
                return $replay;
            }
            $group = $this->groupLocked($id, $data);
            $this->assertVersion($group, $data['expected_version']);
            $this->assertState($group, [$target === 'ACTIVE' ? 'DRAFT' : 'ACTIVE'], strtolower($target));
            $members = DB::table('consolidation_group_members')->where('consolidation_group_id', $id)->get();
            if ($target === 'ACTIVE') {
                if ($members->isEmpty()) {
                    throw new ConflictHttpException('A consolidation group needs at least one legal-entity member.');
                }
                foreach ($members as $member) {
                    $this->assertPermissionAt($data['actor_id'], (string) $member->company_id, null, 'GROUP-ACTIVATE');
                    if (! DB::table('companies')->where('id', $member->company_id)->where('status', 'ACTIVE')->exists()) {
                        throw new ConflictHttpException('Every consolidation member must be an active company.');
                    }
                }
            } elseif (DB::table('plant_transfer_routes')->where('consolidation_group_id', $id)->where('status', 'ACTIVE')->exists()) {
                throw new ConflictHttpException('Deactivate every governed transfer route before retiring its consolidation group.');
            }
            $version = (int) $group->record_version + 1;
            $changes = ['status' => $target, 'record_version' => $version, 'updated_at' => now()];
            if ($target === 'ACTIVE') {
                $changes += ['activated_at' => now(), 'activated_by' => $data['actor_id']];
            } else {
                $changes += ['retired_at' => now(), 'retired_by' => $data['actor_id'], 'retirement_reason' => $reason];
            }
            DB::table('consolidation_groups')->where('id', $id)->update($changes);
            $result = $this->result($id, 'consolidation_group', $target, $version);
            $event = $target === 'ACTIVE' ? 'activated' : 'retired';
            $this->record(strtoupper($event).'_CONSOLIDATION_GROUP', 'scale.consolidation-group.'.$event, 'consolidation_group', $id, $data, $version, [
                'status' => ['from' => $group->status, 'to' => $target], 'reason' => $reason,
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    private function prepareMembers(array $input, array $data, string $requiredAction): array
    {
        $seenCompanies = $seenCodes = [];
        $members = [];
        foreach (array_values($input) as $index => $member) {
            $companyId = $member['company_id'];
            $code = Str::upper(trim($member['member_code']));
            if (isset($seenCompanies[$companyId]) || isset($seenCodes[$code])) {
                throw ValidationException::withMessages(["members.{$index}" => ['Company and member code must each be unique in a group.']]);
            }
            if (! DB::table('companies')->where('id', $companyId)->where('status', 'ACTIVE')->exists()) {
                throw ValidationException::withMessages(["members.{$index}.company_id" => ['Select an active legal entity.']]);
            }
            $this->assertPermissionAt($data['actor_id'], $companyId, null, $requiredAction);
            $seenCompanies[$companyId] = $seenCodes[$code] = true;
            $members[] = [
                'company_id' => $companyId, 'member_code' => $code,
                'reporting_currency' => $member['reporting_currency'],
                'ownership_percent' => $this->positive($member['ownership_percent'], "members.{$index}.ownership_percent", 4, '100'),
                'effective_from' => $member['effective_from'], 'effective_to' => $member['effective_to'] ?? null,
            ];
        }
        if (! isset($seenCompanies[$data['company_id']])) {
            throw ValidationException::withMessages(['members' => ['The owner company must be a member of its consolidation group.']]);
        }

        return $members;
    }

    private function insertMembers(string $groupId, string $ownerCompanyId, array $members): void
    {
        $now = now();
        foreach ($members as $member) {
            DB::table('consolidation_group_members')->insert([
                'id' => (string) Str::uuid(), 'consolidation_group_id' => $groupId,
                'owner_company_id' => $ownerCompanyId, ...$member, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function prepareRoute(array $input, array $data): array
    {
        $sourceCompany = $data['company_id'];
        $sourcePlant = $data['plant_id'];
        $destinationCompany = $input['destination_company_id'];
        $destinationPlant = $input['destination_plant_id'];
        if ($sourcePlant === $destinationPlant) {
            throw ValidationException::withMessages(['destination_plant_id' => ['Source and destination plants must be different.']]);
        }
        if (! DB::table('plants')->where('id', $sourcePlant)->where('company_id', $sourceCompany)->where('status', 'ACTIVE')->exists()
            || ! DB::table('plants')->where('id', $destinationPlant)->where('company_id', $destinationCompany)->where('status', 'ACTIVE')->exists()) {
            throw ValidationException::withMessages(['destination_plant_id' => ['Both route plants must be active and belong to their declared companies.']]);
        }
        $transferScope = $sourceCompany === $destinationCompany ? 'INTER_PLANT' : 'INTER_COMPANY';
        if ($input['transfer_scope'] !== $transferScope) {
            throw ValidationException::withMessages(['transfer_scope' => ["Use {$transferScope} for the selected legal entities."]]);
        }
        $groupId = $input['consolidation_group_id'] ?? null;
        if ($transferScope === 'INTER_COMPANY') {
            $this->assertPermissionAt($data['actor_id'], $destinationCompany, $destinationPlant, 'ROUTE-CREATE');
            if (! $groupId || ! $this->activeGroupContains($groupId, [$sourceCompany, $destinationCompany])) {
                throw ValidationException::withMessages(['consolidation_group_id' => ['Cross-company routes require an active group containing both legal entities.']]);
            }
        } elseif ($groupId && ! $this->activeGroupContains($groupId, [$sourceCompany])) {
            throw ValidationException::withMessages(['consolidation_group_id' => ['The selected active group does not contain the source company.']]);
        }

        return [
            'source_company_id' => $sourceCompany, 'source_plant_id' => $sourcePlant,
            'destination_company_id' => $destinationCompany, 'destination_plant_id' => $destinationPlant,
            'consolidation_group_id' => $groupId, 'route_code' => $input['route_code'], 'name' => trim($input['name']),
            'transfer_scope' => $transferScope, 'currency' => $input['currency'],
            'transit_days' => (int) $input['transit_days'], 'markup_percent' => $this->decimal($input['markup_percent'], 4),
            'require_destination_acceptance' => $transferScope === 'INTER_COMPANY',
            'require_commercial_reference' => $transferScope === 'INTER_COMPANY' || (bool) ($input['require_commercial_reference'] ?? false),
        ];
    }

    private function prepareMappings(array $route, array $input): array
    {
        $seen = [];
        $mappings = [];
        foreach (array_values($input) as $index => $mapping) {
            $source = DB::table('items')->where('id', $mapping['source_item_id'])
                ->where('company_id', $route['source_company_id'])->where('status', 'ACTIVE')->first();
            $destination = DB::table('items')->where('id', $mapping['destination_item_id'])
                ->where('company_id', $route['destination_company_id'])->where('status', 'ACTIVE')->first();
            if (! $source || ! $destination) {
                throw ValidationException::withMessages(["mappings.{$index}" => ['Mapped items must be active in the declared source and destination companies.']]);
            }
            $key = $mapping['source_item_id'].'|'.$mapping['source_uom_code'];
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["mappings.{$index}" => ['A source item and UOM may be mapped only once per route.']]);
            }
            if (! DB::table('uoms')->where('code', $mapping['source_uom_code'])->exists()
                || ! DB::table('uoms')->where('code', $mapping['destination_uom_code'])->exists()) {
                throw ValidationException::withMessages(["mappings.{$index}" => ['Select recognised source and destination UOMs.']]);
            }
            $rate = $this->positive($mapping['conversion_rate'], "mappings.{$index}.conversion_rate", 8);
            if ($route['transfer_scope'] === 'INTER_PLANT' && ($source->id !== $destination->id
                || $mapping['source_uom_code'] !== $mapping['destination_uom_code'] || bccomp($rate, '1', 8) !== 0)) {
                throw ValidationException::withMessages(["mappings.{$index}" => ['Same-company routes must preserve item identity, UOM, and a 1:1 base quantity.']]);
            }
            $seen[$key] = true;
            $mappings[] = [
                'source_item_id' => $source->id, 'destination_item_id' => $destination->id,
                'source_uom_code' => $mapping['source_uom_code'],
                'destination_uom_code' => $mapping['destination_uom_code'], 'conversion_rate' => $rate,
            ];
        }

        return $mappings;
    }

    private function insertMappings(string $routeId, array $route, array $mappings): void
    {
        $now = now();
        foreach ($mappings as $index => $mapping) {
            DB::table('plant_transfer_item_mappings')->insert([
                'id' => (string) Str::uuid(), 'plant_transfer_route_id' => $routeId,
                'source_company_id' => $route['source_company_id'], 'source_plant_id' => $route['source_plant_id'],
                'destination_company_id' => $route['destination_company_id'], 'destination_plant_id' => $route['destination_plant_id'],
                'line_number' => $index + 1, ...$mapping, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function prepareTransferLines(object $route, array $input): array
    {
        $seenSource = $seenDestination = [];
        $lines = [];
        foreach (array_values($input) as $index => $line) {
            $source = $this->position((string) $line['source_position_id'], (string) $route->source_company_id, (string) $route->source_plant_id);
            $destination = $this->position((string) $line['destination_position_id'], (string) $route->destination_company_id, (string) $route->destination_plant_id);
            if (! $source || ! $destination) {
                throw ValidationException::withMessages(["lines.{$index}" => ['Select positions in the exact source and destination route scopes.']]);
            }
            if (isset($seenSource[$source->id]) || isset($seenDestination[$destination->id])) {
                throw ValidationException::withMessages(["lines.{$index}" => ['A stock position may appear only once in a transfer.']]);
            }
            $this->assertEligiblePosition($source, "lines.{$index}.source_position_id");
            $this->assertEligiblePosition($destination, "lines.{$index}.destination_position_id");
            $mapping = DB::table('plant_transfer_item_mappings')->where('plant_transfer_route_id', $route->id)
                ->where('source_item_id', $source->item_id)->where('source_uom_code', $source->uom_code)
                ->where('destination_item_id', $destination->item_id)->where('destination_uom_code', $destination->uom_code)->first();
            if (! $mapping) {
                throw ValidationException::withMessages(["lines.{$index}" => ['No active route mapping joins the selected source and destination stock identities.']]);
            }
            if ($route->transfer_scope === 'INTER_PLANT'
                && ($source->lot_id !== $destination->lot_id || $source->inventory_owner_id !== $destination->inventory_owner_id)) {
                throw ValidationException::withMessages(["lines.{$index}" => ['Same-company plant transfers must preserve lot and inventory-owner identity.']]);
            }
            $quantity = $this->positive($line['quantity_base'], "lines.{$index}.quantity_base", 6);
            $available = bcsub((string) $source->quantity_base, (string) $source->reserved_quantity_base, 6);
            if (bccomp($available, $quantity, 6) < 0) {
                throw ValidationException::withMessages(["lines.{$index}.quantity_base" => ['Requested quantity exceeds current unreserved source stock.']]);
            }
            $destinationQuantity = bcmul($quantity, (string) $mapping->conversion_rate, 6);
            if (bccomp($destinationQuantity, '0', 6) <= 0) {
                throw ValidationException::withMessages(["lines.{$index}.quantity_base" => ['The mapped destination quantity rounds to zero.']]);
            }
            $seenSource[$source->id] = $seenDestination[$destination->id] = true;
            $lines[] = [
                'plant_transfer_item_mapping_id' => $mapping->id,
                'source_position_id' => $source->id, 'destination_position_id' => $destination->id,
                'source_item_id' => $source->item_id, 'destination_item_id' => $destination->item_id,
                'source_lot_id' => $source->lot_id, 'destination_lot_id' => $destination->lot_id,
                'source_inventory_owner_id' => $source->inventory_owner_id,
                'destination_inventory_owner_id' => $destination->inventory_owner_id,
                'source_uom_code' => $source->uom_code, 'destination_uom_code' => $destination->uom_code,
                'source_quantity' => $quantity, 'destination_quantity' => $destinationQuantity,
                'notes' => $this->nullable($line['notes'] ?? null),
            ];
        }

        return $lines;
    }

    private function insertTransferLines(string $transferId, object $route, array $lines): void
    {
        $now = now();
        foreach ($lines as $index => $line) {
            DB::table('interplant_transfer_lines')->insert([
                'id' => (string) Str::uuid(), 'interplant_transfer_order_id' => $transferId,
                'plant_transfer_route_id' => $route->id,
                'source_company_id' => $route->source_company_id, 'source_plant_id' => $route->source_plant_id,
                'destination_company_id' => $route->destination_company_id, 'destination_plant_id' => $route->destination_plant_id,
                'line_number' => $index + 1, ...$line,
                'dispatched_source_quantity' => 0, 'received_destination_quantity' => 0,
                'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function revalidateTransferLines(object $transfer): void
    {
        $lines = DB::table('interplant_transfer_lines')->where('interplant_transfer_order_id', $transfer->id)
            ->orderBy('source_position_id')->get();
        if ($lines->isEmpty()) {
            throw new ConflictHttpException('The transfer has no stock lines.');
        }
        foreach ($lines as $line) {
            $source = $this->validatedTransferPosition($line, $transfer, 'source');
            $this->validatedTransferPosition($line, $transfer, 'destination');
            if (bccomp(bcsub((string) $source->quantity_base, (string) $source->reserved_quantity_base, 6), (string) $line->source_quantity, 6) < 0) {
                throw new ConflictHttpException('A transfer line no longer has enough unreserved source stock.');
            }
        }
    }

    private function validatedTransferPosition(object $line, object $transfer, string $side): object
    {
        $source = $side === 'source';
        $position = $this->position(
            (string) ($source ? $line->source_position_id : $line->destination_position_id),
            (string) ($source ? $transfer->source_company_id : $transfer->destination_company_id),
            (string) ($source ? $transfer->source_plant_id : $transfer->destination_plant_id),
            true,
        );
        $itemId = (string) ($source ? $line->source_item_id : $line->destination_item_id);
        $lotId = (string) ($source ? $line->source_lot_id : $line->destination_lot_id);
        $ownerId = (string) ($source ? $line->source_inventory_owner_id : $line->destination_inventory_owner_id);
        $uomCode = (string) ($source ? $line->source_uom_code : $line->destination_uom_code);
        if (! $position || $position->item_id !== $itemId || $position->lot_id !== $lotId
            || $position->inventory_owner_id !== $ownerId || $position->uom_code !== $uomCode) {
            throw new ConflictHttpException('A transfer stock identity changed after draft capture.');
        }
        $this->assertEligiblePosition($position, $source ? 'source_position_id' : 'destination_position_id');

        return $position;
    }

    private function prepareEliminations(array $input): array
    {
        $lines = [];
        $debit = $credit = '0.0000';
        foreach (array_values($input) as $index => $line) {
            $dr = $this->decimal($line['debit_amount'] ?? 0, 4);
            $cr = $this->decimal($line['credit_amount'] ?? 0, 4);
            if ((bccomp($dr, '0', 4) > 0) === (bccomp($cr, '0', 4) > 0)) {
                throw ValidationException::withMessages(["eliminations.{$index}" => ['Each elimination line must contain exactly one positive debit or credit.']]);
            }
            $lines[] = ['description' => trim($line['description']), 'debit_amount' => $dr, 'credit_amount' => $cr];
            $debit = bcadd($debit, $dr, 4);
            $credit = bcadd($credit, $cr, 4);
        }
        if (bccomp($debit, $credit, 4) !== 0) {
            throw ValidationException::withMessages(['eliminations' => ['Consolidation eliminations must balance exactly.']]);
        }

        return [$lines, $debit, $credit];
    }

    private function groupLocked(string $id, array $data): object
    {
        $group = DB::table('consolidation_groups')->where('id', $id)
            ->where('owner_company_id', $data['company_id'])->lockForUpdate()->first();
        if (! $group) {
            throw new ConflictHttpException('Consolidation group not found in the selected owner company.');
        }

        return $group;
    }

    private function routeLocked(string $id, array $data): object
    {
        $route = DB::table('plant_transfer_routes')->where('id', $id)
            ->where('source_company_id', $data['company_id'])->where('source_plant_id', $data['plant_id'])
            ->lockForUpdate()->first();
        if (! $route) {
            throw new ConflictHttpException('Transfer route not found in the selected source scope.');
        }

        return $route;
    }

    private function activeSourceRoute(string $id, array $data): object
    {
        $route = DB::table('plant_transfer_routes')->where('id', $id)->where('status', 'ACTIVE')
            ->where('source_company_id', $data['company_id'])->where('source_plant_id', $data['plant_id'])->first();
        if (! $route) {
            throw ValidationException::withMessages(['plant_transfer_route_id' => ['Select an active route from the current source context.']]);
        }

        return $route;
    }

    private function sourceTransferLocked(string $id, array $data): object
    {
        return $this->transferLocked($id, $data, 'source');
    }

    private function destinationTransferLocked(string $id, array $data): object
    {
        return $this->transferLocked($id, $data, 'destination');
    }

    private function transferLocked(string $id, array $data, string $side): object
    {
        $query = DB::table('interplant_transfer_orders as transfer')
            ->join('plant_transfer_routes as route', 'route.id', '=', 'transfer.plant_transfer_route_id')
            ->where('transfer.id', $id);
        if ($side === 'source') {
            $query->where('transfer.source_company_id', $data['company_id'])->where('transfer.source_plant_id', $data['plant_id']);
        } else {
            $query->where('transfer.destination_company_id', $data['company_id'])->where('transfer.destination_plant_id', $data['plant_id']);
        }
        $transfer = $query->select(['transfer.*', 'route.transfer_scope', 'route.require_destination_acceptance', 'route.require_commercial_reference'])
            ->lockForUpdate()->first();
        if (! $transfer) {
            throw new ConflictHttpException('Transfer not found on the selected '.$side.' side.');
        }

        return $transfer;
    }

    private function position(string $id, string $companyId, string $plantId, bool $lock = false): ?object
    {
        $query = DB::table('stock_positions as position')
            ->join('items as item', 'item.id', '=', 'position.item_id')
            ->join('lots as lot', 'lot.id', '=', 'position.lot_id')
            ->join('inventory_owners as owner', 'owner.id', '=', 'position.inventory_owner_id')
            ->join('locations as location', 'location.id', '=', 'position.location_id')
            ->where('position.id', $id)->where('position.company_id', $companyId)->where('position.plant_id', $plantId)
            ->select(['position.*', 'item.status as item_status', 'lot.status as lot_status', 'lot.expiry_date',
                'owner.status as owner_status', 'location.status as location_status']);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    private function assertEligiblePosition(object $position, string $field): void
    {
        if ($position->quality_status !== 'RELEASED' || $position->item_status !== 'ACTIVE'
            || $position->lot_status !== 'ACTIVE' || $position->owner_status !== 'ACTIVE'
            || $position->location_status !== 'ACTIVE'
            || ($position->expiry_date && CarbonImmutable::parse($position->expiry_date)->isBefore(CarbonImmutable::today()))) {
            throw ValidationException::withMessages([$field => ['Transfer stock must be released, active, located in an active store, and unexpired.']]);
        }
    }

    private function assertRouteReady(object $route, array $data): void
    {
        if (! DB::table('plants')->where('id', $route->source_plant_id)->where('company_id', $route->source_company_id)->where('status', 'ACTIVE')->exists()
            || ! DB::table('plants')->where('id', $route->destination_plant_id)->where('company_id', $route->destination_company_id)->where('status', 'ACTIVE')->exists()) {
            throw new ConflictHttpException('Both plants must remain active before route activation.');
        }
        if ($route->transfer_scope === 'INTER_COMPANY') {
            $this->assertPermissionAt($data['actor_id'], $route->destination_company_id, $route->destination_plant_id, 'ROUTE-ACTIVATE');
            if (! $route->consolidation_group_id
                || ! $this->activeGroupContains($route->consolidation_group_id, [$route->source_company_id, $route->destination_company_id])) {
                throw new ConflictHttpException('The cross-company governance group is no longer active for both legal entities.');
            }
        }
    }

    private function activeGroupContains(string $groupId, array $companyIds): bool
    {
        if (! DB::table('consolidation_groups')->where('id', $groupId)->where('status', 'ACTIVE')->exists()) {
            return false;
        }
        $members = DB::table('consolidation_group_members')->where('consolidation_group_id', $groupId)
            ->whereIn('company_id', $companyIds)->where('effective_from', '<=', now()->toDateString())
            ->where(fn ($query) => $query->whereNull('effective_to')->orWhere('effective_to', '>=', now()->toDateString()))
            ->distinct()->count('company_id');

        return $members === count(array_unique($companyIds));
    }

    private function assertPermissionAt(string $actorId, string $companyId, ?string $plantId, string $action): void
    {
        $permission = 'ACTION:SCALE-PLANT:'.$action;
        $allowed = DB::table('role_assignments as assignment')
            ->join('users as user', 'user.id', '=', 'assignment.user_id')
            ->join('roles as role', 'role.id', '=', 'assignment.role_id')
            ->join('role_permissions as link', 'link.role_id', '=', 'role.id')
            ->join('permissions as permission', 'permission.id', '=', 'link.permission_id')
            ->where('assignment.user_id', $actorId)->where('user.status', 'ACTIVE')
            ->where('assignment.is_active', true)->where('role.status', 'ACTIVE')->where('permission.status', 'ACTIVE')
            ->where('permission.code', $permission)
            ->where(fn ($query) => $query->whereNull('assignment.company_id')->orWhere('assignment.company_id', $companyId))
            ->when($plantId !== null, fn ($query) => $query->where(fn ($nested) => $nested->whereNull('assignment.plant_id')->orWhere('assignment.plant_id', $plantId)))
            ->where(fn ($query) => $query->whereNull('assignment.effective_from')->orWhere('assignment.effective_from', '<=', now()))
            ->where(fn ($query) => $query->whereNull('assignment.effective_to')->orWhere('assignment.effective_to', '>', now()))
            ->exists();
        if (! $allowed) {
            throw ValidationException::withMessages(['authority' => ["The actor lacks {$permission} in a required legal-entity or plant scope."]]);
        }
    }

    private function assertVersion(object $record, int $expected): void
    {
        if ((int) $record->record_version !== $expected) {
            throw new ConflictHttpException("Record version conflict: expected {$expected}, current {$record->record_version}.");
        }
    }

    private function assertState(object $record, array $allowed, string $action): void
    {
        if (! in_array($record->status, $allowed, true)) {
            throw new ConflictHttpException("A {$record->status} record cannot be {$action}.");
        }
    }

    private function assertTransferDates(string $transferDate, string $arrivalDate): void
    {
        if ($arrivalDate < $transferDate) {
            throw ValidationException::withMessages(['expected_arrival_date' => ['Expected arrival cannot precede the transfer date.']]);
        }
    }

    private function assertCommercialReference(object $route, mixed $reference): void
    {
        if ((bool) $route->require_commercial_reference && $this->nullable($reference) === null) {
            throw ValidationException::withMessages(['commercial_reference' => ['This governed route requires a commercial document reference.']]);
        }
    }

    private function positive(mixed $value, string $field, int $scale, ?string $maximum = null): string
    {
        $decimal = $this->decimal($value, $scale);
        if (bccomp($decimal, '0', $scale) <= 0 || ($maximum !== null && bccomp($decimal, $maximum, $scale) > 0)) {
            throw ValidationException::withMessages([$field => ['Enter a positive value'.($maximum ? " not exceeding {$maximum}" : '').'.']]);
        }

        return $decimal;
    }

    private function decimal(mixed $value, int $scale): string
    {
        $text = (string) ($value ?? 0);
        if (! preg_match('/^\d{1,16}(?:\.\d{1,8})?$/', $text)) {
            throw ValidationException::withMessages(['number' => ['Values must be non-negative decimals with at most eight fractional digits.']]);
        }

        return bcadd($text, '0', $scale);
    }

    private function nullable(mixed $value): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : $text;
    }

    private function result(string $id, string $type, string $status, int $version): array
    {
        return ['id' => $id, 'entity_type' => $type, 'status' => $status, 'record_version' => $version];
    }

    private function begin(string $namespace, array $data): ?array
    {
        return $this->idempotency->begin($namespace, $data['idempotency_key'], Arr::except($data, [
            'actor_id', 'permissions', 'idempotency_key', 'correlation_id',
        ]));
    }

    private function complete(string $namespace, array $data, array $result): void
    {
        $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
    }

    private function record(string $command, string $event, string $entity, string $id, array $data, int $version, array $diff, array $result): void
    {
        $this->audit->record($command, $entity, $id, $data['actor_id'], $data['company_id'], $data['plant_id'], 'SUCCESS', [
            'entity_version' => $version, 'correlation_id' => $data['correlation_id'] ?? null, 'safe_diff' => $diff,
        ]);
        $this->outbox->append($event, $entity, $id, $id.':'.$version, $result + [
            'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
        ], $data['correlation_id'] ?? null, $data['company_id'], $data['plant_id']);
    }
}

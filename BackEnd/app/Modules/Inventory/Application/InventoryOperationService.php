<?php

namespace App\Modules\Inventory\Application;

use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use App\Shared\Visibility\OperationalVisibilityScope;
use App\Shared\Visibility\TransactionVisibilityPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class InventoryOperationService
{
    public const TYPES = ['ISSUE', 'RETURN', 'TRANSFER', 'COUNT', 'ADJUSTMENT', 'EXPIRY', 'DISPOSAL'];
    public const STATUSES = ['DRAFT', 'POSTED', 'CANCELLED'];
    public const ADJUSTMENT_DIRECTIONS = ['INCREASE', 'DECREASE'];

    public const RESOURCES = [
        'issues' => ['screen' => 'INV-ISS', 'types' => ['ISSUE', 'RETURN']],
        'transfers' => ['screen' => 'INV-TRF', 'types' => ['TRANSFER']],
        'counts' => ['screen' => 'INV-COUNT', 'types' => ['COUNT', 'ADJUSTMENT']],
        'expiry-disposals' => ['screen' => 'INV-EXP', 'types' => ['EXPIRY', 'DISPOSAL']],
    ];

    public function __construct(
        private readonly StockPostingService $stock,
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
        private readonly OperationalVisibilityScope $visibility,
    ) {}

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $namespace = 'inventory.operation.create';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }

            if (DB::table('inventory_operations')->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])->where('operation_number', $data['operation_number'])->exists()) {
                throw ValidationException::withMessages([
                    'operation_number' => ['That operation number already exists in the selected plant.'],
                ]);
            }

            $lines = $this->prepareLines($data['operation_type'], $data['lines'], $data);
            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('inventory_operations')->insert([
                'id' => $id,
                'company_id' => $data['company_id'],
                'plant_id' => $data['plant_id'],
                'operation_number' => $data['operation_number'],
                'operation_type' => $data['operation_type'],
                'status' => 'DRAFT',
                'reason_code' => $data['reason_code'],
                'notes' => $this->nullable($data['notes'] ?? null),
                'record_version' => 1,
                'created_by' => $data['actor_id'],
                'posted_at' => null,
                'posted_by' => null,
                'cancelled_at' => null,
                'cancelled_by' => null,
                'cancellation_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->insertLines($id, $data['operation_type'], $lines, $data, $now);

            $result = $this->result($id, $data['operation_type'], 'DRAFT', 1, count($lines));
            $this->record(
                'CREATE_INVENTORY_OPERATION',
                'inventory.operation.created',
                $id,
                $data,
                1,
                ['created' => [
                    'operation_number' => $data['operation_number'],
                    'operation_type' => $data['operation_type'],
                    'line_count' => count($lines),
                ]],
                $result,
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function update(string $operationId, array $data): array
    {
        return DB::transaction(function () use ($operationId, $data) {
            $namespace = 'inventory.operation.update.'.$operationId;
            $payload = $data + ['operation_id' => $operationId];
            if ($replay = $this->begin($namespace, $payload)) {
                return $replay;
            }

            $operation = $this->findLocked($operationId, $data);
            $this->assertVersion($operation, $data['expected_version']);
            $this->assertDraft($operation, 'updated');
            $lines = $this->prepareLines((string) $operation->operation_type, $data['lines'], $data);
            $version = (int) $operation->record_version + 1;
            $now = CarbonImmutable::now();

            DB::table('inventory_operations')->where('id', $operationId)->update([
                'reason_code' => $data['reason_code'],
                'notes' => $this->nullable($data['notes'] ?? null),
                'record_version' => $version,
                'updated_at' => $now,
            ]);
            DB::table('inventory_operation_lines')->where('operation_id', $operationId)->delete();
            $this->insertLines(
                $operationId,
                (string) $operation->operation_type,
                $lines,
                $data,
                $now,
            );

            $result = $this->result($operationId, (string) $operation->operation_type, 'DRAFT', $version, count($lines));
            $this->record(
                'UPDATE_INVENTORY_OPERATION',
                'inventory.operation.updated',
                $operationId,
                $data,
                $version,
                [
                    'reason_code' => ['from' => $operation->reason_code, 'to' => $data['reason_code']],
                    'line_count' => count($lines),
                ],
                $result,
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function post(string $operationId, array $data): array
    {
        return DB::transaction(function () use ($operationId, $data) {
            $namespace = 'inventory.operation.post.'.$operationId;
            $payload = $data + ['operation_id' => $operationId];
            if ($replay = $this->begin($namespace, $payload)) {
                return $replay;
            }

            $operation = $this->findLocked($operationId, $data);
            $this->assertVersion($operation, $data['expected_version']);
            $this->assertDraft($operation, 'posted');
            $lines = DB::table('inventory_operation_lines')->where('operation_id', $operationId)
                ->orderBy('sequence_no')->get();
            if ($lines->isEmpty()) {
                throw new ConflictHttpException('The inventory operation has no lines to post.');
            }

            $positionIds = $lines->flatMap(fn (object $line) => [
                $line->source_position_id,
                $line->target_position_id,
            ])->filter()->unique()->sort()->values()->all();
            $positions = $this->positionMap($positionIds, $data, true);
            if ($positions->count() !== count($positionIds)) {
                throw new ConflictHttpException('One or more stock positions are no longer available in this scope.');
            }

            $movementIds = [];
            $postingVersion = (int) $operation->record_version + 1;
            foreach ($lines as $line) {
                $source = $line->source_position_id ? $positions->get((string) $line->source_position_id) : null;
                $target = $line->target_position_id ? $positions->get((string) $line->target_position_id) : null;
                $this->validateLine((string) $operation->operation_type, (array) $line, $source, $target, true);
                $movement = $this->postLine($operation, $line, $source, $target, $postingVersion, $data);
                if ($movement !== null) {
                    $movementIds[] = $movement['movement_id'];
                    DB::table('inventory_operation_lines')->where('id', $line->id)->update([
                        'movement_id' => $movement['movement_id'],
                        'updated_at' => now(),
                    ]);
                }
            }

            $now = CarbonImmutable::now();
            DB::table('inventory_operations')->where('id', $operationId)->update([
                'status' => 'POSTED',
                'posted_at' => $now,
                'posted_by' => $data['actor_id'],
                'record_version' => $postingVersion,
                'updated_at' => $now,
            ]);
            $result = $this->result(
                $operationId,
                (string) $operation->operation_type,
                'POSTED',
                $postingVersion,
                $lines->count(),
            ) + ['movement_ids' => $movementIds, 'posted_at' => $now->toISOString()];
            $this->record(
                'POST_INVENTORY_OPERATION',
                'inventory.operation.posted',
                $operationId,
                $data,
                $postingVersion,
                [
                    'status' => ['from' => 'DRAFT', 'to' => 'POSTED'],
                    'movement_ids' => $movementIds,
                ],
                $result,
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function cancel(string $operationId, string $reason, array $data): array
    {
        return DB::transaction(function () use ($operationId, $reason, $data) {
            $namespace = 'inventory.operation.cancel.'.$operationId;
            $payload = $data + ['operation_id' => $operationId, 'reason' => $reason];
            if ($replay = $this->begin($namespace, $payload)) {
                return $replay;
            }

            $operation = $this->findLocked($operationId, $data);
            $this->assertVersion($operation, $data['expected_version']);
            $this->assertDraft($operation, 'cancelled');
            $version = (int) $operation->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table('inventory_operations')->where('id', $operationId)->update([
                'status' => 'CANCELLED',
                'cancelled_at' => $now,
                'cancelled_by' => $data['actor_id'],
                'cancellation_reason' => $reason,
                'record_version' => $version,
                'updated_at' => $now,
            ]);
            $lineCount = DB::table('inventory_operation_lines')->where('operation_id', $operationId)->count();
            $result = $this->result(
                $operationId,
                (string) $operation->operation_type,
                'CANCELLED',
                $version,
                $lineCount,
            );
            $this->record(
                'CANCEL_INVENTORY_OPERATION',
                'inventory.operation.cancelled',
                $operationId,
                $data,
                $version,
                ['status' => ['from' => 'DRAFT', 'to' => 'CANCELLED'], 'reason' => $reason],
                $result,
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    private function prepareLines(string $type, array $input, array $scope): array
    {
        $ids = collect($input)->flatMap(fn (array $line) => [
            $line['source_position_id'] ?? null,
            $line['target_position_id'] ?? null,
        ])->filter()->unique()->values()->all();
        $positions = $this->positionMap($ids, $scope, false);
        $seen = [];
        $prepared = [];

        foreach (array_values($input) as $index => $line) {
            $sourceId = $line['source_position_id'] ?? null;
            $targetId = $line['target_position_id'] ?? null;
            $source = $sourceId ? $positions->get($sourceId) : null;
            $target = $targetId ? $positions->get($targetId) : null;
            if (($sourceId && ! $source) || ($targetId && ! $target)) {
                throw ValidationException::withMessages([
                    "lines.{$index}.position" => ['Select stock positions from the current company and plant.'],
                ]);
            }
            foreach (array_filter([$sourceId, $targetId]) as $positionId) {
                if (isset($seen[$positionId])) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.position" => ['A stock position may appear only once in an operation.'],
                    ]);
                }
                $seen[$positionId] = true;
            }

            $candidate = [
                'source_position_id' => $sourceId,
                'target_position_id' => $targetId,
                'quantity_base' => $line['quantity_base'] ?? null,
                'counted_quantity_base' => $line['counted_quantity_base'] ?? null,
                'adjustment_direction' => $line['adjustment_direction'] ?? null,
                'notes' => $this->nullable($line['notes'] ?? null),
            ];
            $this->validateLine($type, $candidate, $source, $target, false, $index);
            $position = $source ?? $target;
            $prepared[] = [
                ...$candidate,
                'sequence_no' => $index + 1,
                'quantity_base' => $type === 'COUNT' ? null : $this->positive($candidate['quantity_base']),
                'counted_quantity_base' => $type === 'COUNT'
                    ? $this->nonNegative($candidate['counted_quantity_base']) : null,
                'system_quantity_base' => $type === 'COUNT'
                    ? $this->decimal($source->quantity_base) : null,
                'source_position_version' => $type === 'COUNT'
                    ? (int) $source->record_version : null,
                'adjustment_direction' => $type === 'ADJUSTMENT'
                    ? $candidate['adjustment_direction'] : null,
                'uom_code' => (string) $position->uom_code,
            ];
        }

        return $prepared;
    }

    private function validateLine(
        string $type,
        array $line,
        ?object $source,
        ?object $target,
        bool $posting,
        int $index = 0,
    ): void {
        $prefix = "lines.{$index}";
        $sourceRequired = in_array($type, ['ISSUE', 'TRANSFER', 'COUNT', 'ADJUSTMENT', 'EXPIRY', 'DISPOSAL'], true);
        $targetRequired = in_array($type, ['RETURN', 'TRANSFER', 'EXPIRY'], true);
        if ($sourceRequired !== ($source !== null)) {
            throw ValidationException::withMessages([
                "{$prefix}.source_position_id" => [$sourceRequired
                    ? 'A source stock position is required for this operation type.'
                    : 'A source stock position is not allowed for this operation type.'],
            ]);
        }
        if ($targetRequired !== ($target !== null)) {
            throw ValidationException::withMessages([
                "{$prefix}.target_position_id" => [$targetRequired
                    ? 'A target stock position is required for this operation type.'
                    : 'A target stock position is not allowed for this operation type.'],
            ]);
        }
        if ($source && $target) {
            if ((string) $source->id === (string) $target->id) {
                throw ValidationException::withMessages([
                    "{$prefix}.target_position_id" => ['Source and target stock positions must be different.'],
                ]);
            }
            foreach (['item_id', 'lot_id', 'inventory_owner_id', 'uom_code'] as $field) {
                if ((string) $source->{$field} !== (string) $target->{$field}) {
                    throw ValidationException::withMessages([
                        "{$prefix}.target_position_id" => [
                            'Source and target must use the same SKU, lot, inventory owner, and UOM.',
                        ],
                    ]);
                }
            }
        }

        if ($type === 'COUNT') {
            $this->nonNegative($line['counted_quantity_base'] ?? null);
            if ($posting && (int) $line['source_position_version'] !== (int) $source->record_version) {
                throw new ConflictHttpException(
                    "Count line {$line['sequence_no']} was captured at stock version {$line['source_position_version']}, "
                    ."but the position is now version {$source->record_version}. Refresh the draft before posting."
                );
            }
        } else {
            $this->positive($line['quantity_base'] ?? null);
        }
        if ($type === 'ADJUSTMENT' && ! in_array($line['adjustment_direction'] ?? null, self::ADJUSTMENT_DIRECTIONS, true)) {
            throw ValidationException::withMessages([
                "{$prefix}.adjustment_direction" => ['Choose INCREASE or DECREASE for an adjustment.'],
            ]);
        }

        match ($type) {
            'ISSUE' => $this->assertUsable($source, $prefix),
            'RETURN' => $this->assertReturnTarget($target, $prefix),
            'TRANSFER' => $this->assertTransfer($source, $target, $prefix),
            'ADJUSTMENT' => $this->assertAdjustment($source, (string) $line['adjustment_direction'], $prefix),
            'EXPIRY' => $this->assertExpiry($source, $target, (string) $line['quantity_base'], $prefix),
            'DISPOSAL' => $this->assertDisposal($source, $prefix),
            default => null,
        };
    }

    private function postLine(
        object $operation,
        object $line,
        ?object $source,
        ?object $target,
        int $postingVersion,
        array $data,
    ): ?array {
        $base = [
            'company_id' => $data['company_id'],
            'plant_id' => $data['plant_id'],
            'source_type' => 'INVENTORY_OPERATION',
            'source_id' => (string) $operation->id,
            'source_version' => $postingVersion,
            'actor_id' => $data['actor_id'],
            'reason_code' => (string) $operation->reason_code,
            'uom_code' => (string) $line->uom_code,
            'idempotency_key' => 'operation:'.$operation->id.':'.$line->id.':'.$postingVersion,
            'correlation_id' => $data['correlation_id'] ?? null,
            'event_at' => now(),
        ];
        $expected = $source ?? $target;
        $base += [
            'expected_item_id' => (string) $expected->item_id,
            'expected_lot_id' => (string) $expected->lot_id,
            'expected_owner_id' => (string) $expected->inventory_owner_id,
        ];

        return match ((string) $operation->operation_type) {
            'ISSUE' => $this->stock->issue($base + [
                'movement_type' => 'INVENTORY_ISSUE',
                'source_position_id' => (string) $source->id,
                'quantity_base' => (string) $line->quantity_base,
                'expected_quality_status' => (string) $source->quality_status,
            ]),
            'RETURN' => $this->stock->receive($base + [
                'movement_type' => 'INVENTORY_RETURN',
                'target_position_id' => (string) $target->id,
                'quantity_base' => (string) $line->quantity_base,
                'expected_quality_status' => (string) $target->quality_status,
            ]),
            'TRANSFER' => $this->stock->move($base + [
                'movement_type' => 'INVENTORY_TRANSFER',
                'source_position_id' => (string) $source->id,
                'target_position_id' => (string) $target->id,
                'quantity_base' => (string) $line->quantity_base,
                'expected_source_quality_status' => (string) $source->quality_status,
                'expected_target_quality_status' => (string) $target->quality_status,
            ]),
            'COUNT' => $this->postCount($base, $line, $source),
            'ADJUSTMENT' => $this->postAdjustment($base, $line, $source),
            'EXPIRY' => $this->stock->move($base + [
                'movement_type' => 'INVENTORY_EXPIRY',
                'source_position_id' => (string) $source->id,
                'target_position_id' => (string) $target->id,
                'quantity_base' => (string) $line->quantity_base,
                'expected_source_quality_status' => (string) $source->quality_status,
                'expected_target_quality_status' => 'EXPIRED',
            ]),
            'DISPOSAL' => $this->stock->issue($base + [
                'movement_type' => 'INVENTORY_DISPOSAL',
                'source_position_id' => (string) $source->id,
                'quantity_base' => (string) $line->quantity_base,
                'expected_quality_status' => (string) $source->quality_status,
            ]),
            default => throw new ConflictHttpException('Unsupported inventory operation type.'),
        };
    }

    private function postCount(array $base, object $line, object $position): ?array
    {
        $variance = bcsub((string) $line->counted_quantity_base, (string) $position->quantity_base, 6);
        if (bccomp($variance, '0', 6) === 0) {
            return null;
        }
        $common = $base + [
            'quantity_base' => ltrim($variance, '-'),
            'expected_quality_status' => (string) $position->quality_status,
        ];

        return bccomp($variance, '0', 6) > 0
            ? $this->stock->receive($common + [
                'movement_type' => 'INVENTORY_COUNT_GAIN',
                'target_position_id' => (string) $position->id,
            ])
            : $this->stock->issue($common + [
                'movement_type' => 'INVENTORY_COUNT_LOSS',
                'source_position_id' => (string) $position->id,
            ]);
    }

    private function postAdjustment(array $base, object $line, object $position): array
    {
        $common = $base + [
            'quantity_base' => (string) $line->quantity_base,
            'expected_quality_status' => (string) $position->quality_status,
        ];

        return $line->adjustment_direction === 'INCREASE'
            ? $this->stock->receive($common + [
                'movement_type' => 'INVENTORY_ADJUSTMENT_GAIN',
                'target_position_id' => (string) $position->id,
            ])
            : $this->stock->issue($common + [
                'movement_type' => 'INVENTORY_ADJUSTMENT_LOSS',
                'source_position_id' => (string) $position->id,
            ]);
    }

    private function assertUsable(object $position, string $prefix): void
    {
        if ($position->availability_bucket !== 'AVAILABLE'
            || $position->lot_status !== 'ACTIVE'
            || $this->expired($position->expiry_date)
            || $position->owner_status !== 'ACTIVE') {
            throw ValidationException::withMessages([
                "{$prefix}.source_position_id" => [
                    'Issue stock must be available, actively owned, and belong to an active, unexpired lot.',
                ],
            ]);
        }
    }

    private function assertReturnTarget(object $target, string $prefix): void
    {
        if ($target->quality_status !== 'RETURN_QUARANTINE' || $target->owner_status !== 'ACTIVE') {
            throw ValidationException::withMessages([
                "{$prefix}.target_position_id" => [
                    'Returned stock must enter an actively owned RETURN_QUARANTINE position.',
                ],
            ]);
        }
    }

    private function assertTransfer(object $source, object $target, string $prefix): void
    {
        if ($source->quality_status !== $target->quality_status) {
            throw ValidationException::withMessages([
                "{$prefix}.target_position_id" => ['A transfer cannot change the stock quality status.'],
            ]);
        }
        if ($source->owner_status !== 'ACTIVE') {
            throw ValidationException::withMessages([
                "{$prefix}.source_position_id" => ['A transfer requires an active inventory owner.'],
            ]);
        }
        if ($source->availability_bucket === 'AVAILABLE'
            && ($source->lot_status !== 'ACTIVE' || $this->expired($source->expiry_date))) {
            throw ValidationException::withMessages([
                "{$prefix}.source_position_id" => ['Expired or inactive available stock must use the expiry workflow.'],
            ]);
        }
    }

    private function assertAdjustment(object $position, string $direction, string $prefix): void
    {
        if ($direction === 'INCREASE' && $position->availability_bucket === 'AVAILABLE') {
            $this->assertUsable($position, $prefix);
        }
    }

    private function assertExpiry(object $source, object $target, string $quantity, string $prefix): void
    {
        if (! $this->expired($source->expiry_date)) {
            throw ValidationException::withMessages([
                "{$prefix}.source_position_id" => ['Only stock whose lot expiry date has passed can be marked expired.'],
            ]);
        }
        if ($source->quality_status === 'EXPIRED' || $target->quality_status !== 'EXPIRED') {
            throw ValidationException::withMessages([
                "{$prefix}.target_position_id" => ['Expiry must move stock from a non-expired position to EXPIRED quality.'],
            ]);
        }
        if (bccomp((string) $source->reserved_quantity_base, '0', 6) !== 0) {
            throw ValidationException::withMessages([
                "{$prefix}.source_position_id" => ['Release all reservations before marking a stock position expired.'],
            ]);
        }
        if (bccomp($this->decimal($quantity), $this->decimal($source->quantity_base), 6) !== 0) {
            throw ValidationException::withMessages([
                "{$prefix}.quantity_base" => ['Expiry must move the full quantity of the source position.'],
            ]);
        }
    }

    private function assertDisposal(object $position, string $prefix): void
    {
        $blocked = $position->availability_bucket === 'BLOCKED'
            || $position->lot_status !== 'ACTIVE'
            || $this->expired($position->expiry_date);
        if (! $blocked) {
            throw ValidationException::withMessages([
                "{$prefix}.source_position_id" => ['Only blocked, inactive-lot, or expired stock can be disposed.'],
            ]);
        }
    }

    private function positionMap(array $ids, array $scope, bool $lock): Collection
    {
        if ($ids === []) {
            return collect();
        }
        $query = DB::table('stock_positions as position')
            ->join('lots as lot', 'lot.id', '=', 'position.lot_id')
            ->join('inventory_owners as owner', 'owner.id', '=', 'position.inventory_owner_id')
            ->join('inventory_quality_statuses as quality', 'quality.code', '=', 'position.quality_status')
            ->where('position.company_id', $scope['company_id'])
            ->where('position.plant_id', $scope['plant_id'])
            ->whereIn('position.id', $ids)
            ->orderBy('position.id')
            ->select([
                'position.*', 'lot.status as lot_status', 'lot.expiry_date',
                'owner.status as owner_status', 'quality.availability_bucket',
            ]);
        $this->visibility->applyInventoryAttribution(
            $query,
            'position.lot_id',
            $scope['permissions'] ?? [TransactionVisibilityPolicy::SHOW_PERMISSION],
        );
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()->keyBy('id');
    }

    private function insertLines(
        string $operationId,
        string $operationType,
        array $lines,
        array $data,
        CarbonImmutable $now,
    ): void
    {
        foreach ($lines as $line) {
            DB::table('inventory_operation_lines')->insert([
                'id' => (string) Str::uuid(),
                'operation_id' => $operationId,
                'company_id' => $data['company_id'],
                'plant_id' => $data['plant_id'],
                'operation_type' => $operationType,
                'sequence_no' => $line['sequence_no'],
                'source_position_id' => $line['source_position_id'],
                'target_position_id' => $line['target_position_id'],
                'quantity_base' => $line['quantity_base'],
                'counted_quantity_base' => $line['counted_quantity_base'],
                'system_quantity_base' => $line['system_quantity_base'],
                'source_position_version' => $line['source_position_version'],
                'adjustment_direction' => $line['adjustment_direction'],
                'uom_code' => $line['uom_code'],
                'movement_id' => null,
                'notes' => $line['notes'],
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function findLocked(string $operationId, array $scope): object
    {
        $operation = DB::table('inventory_operations')->where('id', $operationId)
            ->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])
            ->lockForUpdate()->first();
        if (! $operation) {
            throw new NotFoundHttpException('Inventory operation not found.');
        }
        if (isset($scope['allowed_operation_types'])
            && ! in_array($operation->operation_type, $scope['allowed_operation_types'], true)) {
            throw new NotFoundHttpException('Inventory operation not found.');
        }

        return $operation;
    }

    private function assertVersion(object $operation, int $expected): void
    {
        if ((int) $operation->record_version !== $expected) {
            throw new ConflictHttpException(
                "The inventory operation changed from version {$expected} to {$operation->record_version}. Refresh it before continuing."
            );
        }
    }

    private function assertDraft(object $operation, string $action): void
    {
        if ($operation->status !== 'DRAFT') {
            throw ValidationException::withMessages([
                'status' => ["Only a draft inventory operation can be {$action}."],
            ]);
        }
    }

    private function positive(mixed $value): string
    {
        $value = (string) $value;
        if (! preg_match('/^\d+(?:\.\d{1,6})?$/', $value) || bccomp($value, '0', 6) <= 0) {
            throw ValidationException::withMessages([
                'quantity_base' => ['Quantity must be positive with at most 6 decimal places.'],
            ]);
        }

        return $this->decimal($value);
    }

    private function nonNegative(mixed $value): string
    {
        $value = (string) $value;
        if (! preg_match('/^\d+(?:\.\d{1,6})?$/', $value) || bccomp($value, '0', 6) < 0) {
            throw ValidationException::withMessages([
                'counted_quantity_base' => ['Counted quantity must be non-negative with at most 6 decimal places.'],
            ]);
        }

        return $this->decimal($value);
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 6);
    }

    private function expired(mixed $date): bool
    {
        return $date !== null && CarbonImmutable::parse((string) $date)->isBefore(today());
    }

    private function result(string $id, string $type, string $status, int $version, int $lineCount): array
    {
        return [
            'entity_type' => 'inventory_operation',
            'id' => $id,
            'operation_type' => $type,
            'status' => $status,
            'record_version' => $version,
            'line_count' => $lineCount,
        ];
    }

    private function record(
        string $command,
        string $event,
        string $id,
        array $data,
        int $version,
        array $safeDiff,
        array $result,
    ): void {
        $this->audit->record($command, 'inventory_operation', $id, $data['actor_id'],
            $data['company_id'], $data['plant_id'], 'SUCCESS', [
                'entity_version' => $version,
                'correlation_id' => $data['correlation_id'] ?? null,
                'reason_code' => $data['reason_code'] ?? null,
                'safe_diff' => $safeDiff,
            ]);
        $this->outbox->append($event, 'inventory_operation', $id, $id.':'.$version, $result,
            $data['correlation_id'] ?? null, $data['company_id'], $data['plant_id']);
    }

    private function begin(string $namespace, array $data): ?array
    {
        return $this->idempotency->begin(
            $namespace,
            $data['idempotency_key'],
            Arr::except($data, ['actor_id', 'permissions', 'idempotency_key', 'correlation_id']),
        );
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

<?php

namespace App\Modules\Sales\Application;

use App\Modules\Inventory\Application\StockPostingService;
use App\Shared\Approval\ApprovalService;
use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

final class UnsoldSalesReturnService
{
    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly ApprovalService $approvals,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
        private readonly StockPostingService $stock,
        private readonly UnsoldSalesReturnOutcomePostingService $outcomes,
    ) {}

    public function createRequest(array $data): array
    {
        return DB::transaction(function () use ($data) {
            $namespace = 'sales.unsold-return.create';
            $key = $data['idempotency_key'];

            if ($existing = $this->idempotency->begin($namespace, $key, $data)) {
                return $existing;
            }

            $id = (string) Str::uuid();

            DB::table('unsold_return_cases')->insert([
                'id' => $id,
                'company_id' => $data['company_id'],
                'plant_id' => $data['plant_id'],
                'party_id' => $data['party_id'],
                'sales_order_id' => $data['sales_order_id'] ?? null,
                'shipment_id' => $data['shipment_id'],
                'invoice_id' => $data['invoice_id'] ?? null,
                'status' => 'REQUESTED',
                'reason_code' => $data['reason_code'],
                'expected_return_date' => $data['expected_return_date'] ?? null,
                'sales_note' => $data['sales_note'] ?? null,
                'maker_id' => $data['actor_id'],
                'record_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            foreach ($data['lines'] as $line) {
                $returnPositionId = $this->ensureReturnPosition(
                    (string) $data['company_id'],
                    (string) $data['plant_id'],
                    (string) $line['sku_id'],
                    isset($line['fg_lot_id']) ? (string) $line['fg_lot_id'] : null,
                    (string) $line['uom_code'],
                    (string) $data['actor_id'],
                );
                DB::table('unsold_return_lines')->insert([
                    'id' => (string) Str::uuid(),
                    'return_case_id' => $id,
                    'company_id' => $data['company_id'],
                    'plant_id' => $data['plant_id'],
                    'shipment_id' => $data['shipment_id'],
                    'shipment_line_id' => $line['shipment_line_id'] ?? null,
                    'sku_id' => $line['sku_id'],
                    'fg_lot_id' => $line['fg_lot_id'] ?? null,
                    'requested_quantity' => $line['requested_quantity'],
                    'uom_code' => $line['uom_code'],
                    'received_quantity' => '0',
                    'return_position_id' => $returnPositionId,
                    'restock_quantity' => '0',
                    'repack_quantity' => '0',
                    'rework_quantity' => '0',
                    'destroy_quantity' => '0',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->recordStatusTransition(
                $id,
                $data['company_id'],
                $data['plant_id'],
                null,
                'REQUESTED',
                1,
                $data['actor_id']
            );

            $this->audit->record(
                'CREATE_UNSOLD_RETURN',
                'unsold_return_case',
                $id,
                $data['actor_id'],
                $data['company_id'],
                $data['plant_id'],
                'SUCCESS'
            );

            $result = ['return_case_id' => $id, 'status' => 'REQUESTED', 'record_version' => 1];
            $this->idempotency->complete($namespace, $key, $result);

            return $result;
        });
    }

    public function receive(string $caseId, array $data): array
    {
        return DB::transaction(function () use ($caseId, $data) {
            $namespace = "sales.unsold-return.receive.{$caseId}";
            $key = $data['idempotency_key'];

            if ($existing = $this->idempotency->begin($namespace, $key, $this->idempotencyPayload($data))) {
                return $existing;
            }

            $case = DB::table('unsold_return_cases')
                ->where('id', $caseId)
                ->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])
                ->lockForUpdate()
                ->first();

            if (! $case) {
                throw ValidationException::withMessages(['return_case' => ['Return case not found.']]);
            }

            $this->assertExpectedVersion($case, $data['expected_version']);

            if (! in_array($case->status, ['REQUESTED', 'IN_TRANSIT', 'PARTIALLY_RECEIVED'], true)) {
                throw ValidationException::withMessages(['state' => ['Return case is not eligible for receipt.']]);
            }

            $movementIds = [];
            $seenLineIds = [];
            foreach ($data['lines'] as $index => $lineInput) {
                if (isset($seenLineIds[$lineInput['line_id']])) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.line_id" => ['Each return line may be received only once per command.'],
                    ]);
                }
                $seenLineIds[$lineInput['line_id']] = true;

                $line = DB::table('unsold_return_lines')
                    ->where('id', $lineInput['line_id'])
                    ->where('return_case_id', $caseId)
                    ->lockForUpdate()
                    ->first();

                if (! $line) {
                    throw ValidationException::withMessages(['line' => ['Return line not found.']]);
                }

                $newReceived = bcadd((string) $line->received_quantity, (string) $lineInput['received_quantity'], 6);
                if (bccomp($newReceived, (string) $line->requested_quantity, 6) > 0) {
                    throw ValidationException::withMessages(['quantity' => ['Received quantity exceeds requested return quantity.']]);
                }

                $lineInput['return_position_id'] = $lineInput['return_position_id'] ?? $line->return_position_id;
                if (! $lineInput['return_position_id']) {
                    $lineInput['return_position_id'] = $this->ensureReturnPosition(
                        (string) $case->company_id,
                        (string) $case->plant_id,
                        (string) $line->sku_id,
                        $line->fg_lot_id ? (string) $line->fg_lot_id : null,
                        (string) $line->uom_code,
                        (string) $data['actor_id'],
                    );
                }
                $position = $this->validatedReturnPosition($line, $lineInput, $case, $index);
                $this->recordShipmentReturn($line, $lineInput, $case, $index);
                $movementIds[] = $this->postQuarantineReceipt(
                    $case,
                    $line,
                    $position,
                    (string) $lineInput['received_quantity'],
                    $data,
                    $key
                );

                DB::table('unsold_return_lines')->where('id', $line->id)->update([
                    'received_quantity' => $newReceived,
                    'return_position_id' => $lineInput['return_position_id'],
                    'updated_at' => now(),
                ]);
            }

            $fullyReceived = DB::table('unsold_return_lines')
                ->where('return_case_id', $caseId)
                ->get(['requested_quantity', 'received_quantity'])
                ->every(fn ($line) => bccomp(
                    (string) $line->received_quantity,
                    (string) $line->requested_quantity,
                    6
                ) === 0);

            $status = $fullyReceived ? 'RETURN_QUARANTINE' : 'PARTIALLY_RECEIVED';

            DB::table('unsold_return_cases')->where('id', $caseId)->update([
                'status' => $status,
                'received_at' => $fullyReceived ? now() : null,
                'record_version' => $case->record_version + 1,
                'updated_at' => now(),
            ]);

            $this->recordStatusTransition(
                $caseId,
                $case->company_id,
                $case->plant_id,
                $case->status,
                $status,
                $case->record_version + 1,
                $data['actor_id']
            );

            $this->audit->record(
                'RECEIVE_UNSOLD_RETURN',
                'unsold_return_case',
                $caseId,
                $data['actor_id'],
                $case->company_id,
                $case->plant_id,
                'SUCCESS'
            );

            $result = [
                'return_case_id' => $caseId,
                'status' => $status,
                'record_version' => $case->record_version + 1,
                'stock_movement_ids' => $movementIds,
            ];

            $this->idempotency->complete($namespace, $key, $result);

            return $result;
        });
    }

    public function disposition(string $caseId, array $data): array
    {
        return DB::transaction(function () use ($caseId, $data) {
            $namespace = "sales.unsold-return.disposition.{$caseId}";
            $key = $data['idempotency_key'];

            if ($existing = $this->idempotency->begin($namespace, $key, $this->idempotencyPayload($data))) {
                return $existing;
            }

            $case = DB::table('unsold_return_cases')
                ->where('id', $caseId)
                ->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])
                ->lockForUpdate()
                ->first();

            if (! $case) {
                throw ValidationException::withMessages(['return_case' => ['Return case not found.']]);
            }

            $this->assertExpectedVersion($case, $data['expected_version']);

            if ($case->status !== 'RETURN_QUARANTINE') {
                throw ValidationException::withMessages(['state' => ['Return must be in quarantine before Quality disposition.']]);
            }

            $seenLineIds = [];
            foreach ($data['lines'] as $index => $lineInput) {
                if (isset($seenLineIds[$lineInput['line_id']])) {
                    throw ValidationException::withMessages([
                        "lines.{$index}.line_id" => ['Each return line may be dispositioned only once per command.'],
                    ]);
                }
                $seenLineIds[$lineInput['line_id']] = true;

                $line = DB::table('unsold_return_lines')
                    ->where('id', $lineInput['line_id'])
                    ->where('return_case_id', $caseId)
                    ->lockForUpdate()
                    ->first();

                if (! $line) {
                    throw ValidationException::withMessages(['line' => ['Return line not found.']]);
                }

                $sum = '0';
                foreach (['restock_quantity','repack_quantity','rework_quantity','destroy_quantity'] as $field) {
                    $sum = bcadd($sum, (string) ($lineInput[$field] ?? '0'), 6);
                }

                if (bccomp($sum, (string) $line->received_quantity, 6) !== 0) {
                    throw ValidationException::withMessages([
                        'disposition' => ['Disposition quantities must equal the physically received quantity.'],
                    ]);
                }

                DB::table('unsold_return_lines')->where('id', $line->id)->update([
                    'restock_quantity' => $lineInput['restock_quantity'] ?? '0',
                    'repack_quantity' => $lineInput['repack_quantity'] ?? '0',
                    'rework_quantity' => $lineInput['rework_quantity'] ?? '0',
                    'destroy_quantity' => $lineInput['destroy_quantity'] ?? '0',
                    'quality_reason_code' => $lineInput['quality_reason_code'] ?? null,
                    'quality_reviewer_id' => $data['actor_id'],
                    'updated_at' => now(),
                ]);
            }

            $allLinesDispositioned = DB::table('unsold_return_lines')
                ->where('return_case_id', $caseId)
                ->get([
                    'received_quantity',
                    'restock_quantity',
                    'repack_quantity',
                    'rework_quantity',
                    'destroy_quantity',
                ])
                ->every(function ($line) {
                    $dispositioned = '0';
                    foreach (['restock_quantity', 'repack_quantity', 'rework_quantity', 'destroy_quantity'] as $field) {
                        $dispositioned = bcadd($dispositioned, (string) $line->{$field}, 6);
                    }

                    return bccomp($dispositioned, (string) $line->received_quantity, 6) === 0;
                });

            if (! $allLinesDispositioned) {
                throw ValidationException::withMessages([
                    'disposition' => ['Every received return line must be dispositioned.'],
                ]);
            }

            $authorityValue = (string) DB::table('unsold_return_lines')
                ->where('return_case_id', $caseId)
                ->sum('destroy_quantity');

            $approvalId = $this->approvals->request(
                'unsold_return_loss',
                $caseId,
                $case->record_version + 1,
                $data['actor_id'],
                $case->company_id,
                $case->plant_id,
                'UNSOLD_RETURN_LOSS_APPROVAL',
                [
                    'return_case_id' => $caseId,
                    'authority_metric' => 'DESTROY_QUANTITY',
                    'authority_value' => $authorityValue,
                    'authority_uom' => 'BASE',
                ]
            );

            DB::table('unsold_return_cases')->where('id', $caseId)->update([
                'status' => 'DISPOSITION_REVIEW',
                'record_version' => $case->record_version + 1,
                'updated_at' => now(),
            ]);

            $this->recordStatusTransition(
                $caseId,
                $case->company_id,
                $case->plant_id,
                $case->status,
                'DISPOSITION_REVIEW',
                $case->record_version + 1,
                $data['actor_id']
            );

            $this->audit->record(
                'DISPOSITION_UNSOLD_RETURN',
                'unsold_return_case',
                $caseId,
                $data['actor_id'],
                $case->company_id,
                $case->plant_id,
                'SUCCESS'
            );

            $result = [
                'return_case_id' => $caseId,
                'status' => 'DISPOSITION_REVIEW',
                'approval_request_id' => $approvalId,
                'record_version' => $case->record_version + 1,
            ];

            $this->idempotency->complete($namespace, $key, $result);

            return $result;
        });
    }

    public function postLossAfterApproval(string $caseId, array $data): array
    {
        return DB::transaction(function () use ($caseId, $data) {
            $namespace = "sales.unsold-return.post-loss.{$caseId}";
            $key = $data['idempotency_key'];

            if ($existing = $this->idempotency->begin($namespace, $key, $this->idempotencyPayload($data))) {
                return $existing;
            }

            $case = DB::table('unsold_return_cases')
                ->where('id', $caseId)
                ->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])
                ->lockForUpdate()
                ->first();

            if (! $case) {
                throw ValidationException::withMessages(['return_case' => ['Return case not found.']]);
            }

            $this->assertExpectedVersion($case, $data['expected_version']);

            if ($case->status !== 'DISPOSITION_REVIEW') {
                throw ValidationException::withMessages(['state' => ['Return case is not ready for loss posting.']]);
            }

            $approval = DB::table('approval_requests')
                ->where('entity_type', 'unsold_return_loss')
                ->where('entity_id', $caseId)
                ->where('entity_version', $case->record_version)
                ->where('status', 'APPROVED')
                ->latest('created_at')
                ->first();

            if (! $approval) {
                throw ValidationException::withMessages(['approval' => ['Approved loss disposition is required.']]);
            }

            // This is an idempotent fallback for approvals recorded through the shared
            // approval service before this workflow owned its dedicated reviewer API.
            $outcomeMovementIds = $this->outcomes->postApproved(
                (string) $approval->id,
                $data['correlation_id'] ?? null
            );

            $destroyedLines = DB::table('unsold_return_lines')
                ->where('return_case_id', $caseId)
                ->where('destroy_quantity', '>', 0)
                ->orderBy('id')
                ->get([
                    'id',
                    'sku_id',
                    'fg_lot_id',
                    'return_position_id',
                    'destroy_quantity',
                    'uom_code',
                ]);

            if ($destroyedLines->isEmpty()) {
                throw ValidationException::withMessages([
                    'loss' => ['A positive approved destroy quantity is required for loss posting.'],
                ]);
            }

            $uoms = $destroyedLines->pluck('uom_code')->unique();
            if ($uoms->count() !== 1 || $uoms->first() !== $data['uom_code']) {
                throw ValidationException::withMessages([
                    'uom_code' => ['The loss UOM must match every destroyed return line.'],
                ]);
            }

            $lossQty = $destroyedLines->reduce(
                fn (string $total, $line) => bcadd($total, (string) $line->destroy_quantity, 6),
                '0'
            );

            $destructionMovementIds = [];
            foreach ($destroyedLines as $line) {
                $movement = $this->stock->issue([
                    'company_id' => (string) $case->company_id,
                    'plant_id' => (string) $case->plant_id,
                    'source_position_id' => (string) $line->return_position_id,
                    'quantity_base' => (string) $line->destroy_quantity,
                    'uom_code' => $line->uom_code,
                    'movement_type' => 'UNSOLD_RETURN_DESTROY',
                    'source_type' => 'UNSOLD_RETURN',
                    'source_id' => $caseId,
                    'source_version' => (int) $case->record_version,
                    'actor_id' => $data['actor_id'],
                    'reason_code' => 'UNSOLD_RETURN_DESTROYED',
                    'expected_item_id' => (string) $line->sku_id,
                    'expected_lot_id' => (string) $line->fg_lot_id,
                    'expected_quality_status' => 'RETURN_QUARANTINE',
                    'idempotency_key' => 'return-destroy:'.hash(
                        'sha256',
                        $approval->id.'|'.$line->id
                    ),
                    'correlation_id' => $data['correlation_id'] ?? null,
                ]);
                $destructionMovementIds[] = $movement['movement_id'];
            }

            $lossId = (string) Str::uuid();

            DB::table('loss_events')->insert([
                'id' => $lossId,
                'company_id' => $case->company_id,
                'plant_id' => $case->plant_id,
                'source_type' => 'UNSOLD_RETURN',
                'source_id' => $caseId,
                'quantity_base' => $lossQty,
                'uom_code' => $data['uom_code'],
                'cost_amount' => $data['cost_amount'] ?? null,
                'currency' => $data['currency'] ?? 'INR',
                'reason_code' => 'UNSOLD_RETURN_DESTROYED',
                'approval_request_id' => $approval->id,
                'actor_id' => $data['actor_id'],
                'posted_at' => now(),
                'created_at' => now(),
            ]);

            DB::table('unsold_return_cases')->where('id', $caseId)->update([
                'status' => 'LOSS_POSTED',
                'loss_event_id' => $lossId,
                'record_version' => $case->record_version + 1,
                'updated_at' => now(),
            ]);

            $this->recordStatusTransition(
                $caseId,
                $case->company_id,
                $case->plant_id,
                $case->status,
                'LOSS_POSTED',
                $case->record_version + 1,
                $data['actor_id']
            );

            $this->outbox->append(
                'sales.unsold_return.loss_posted',
                'unsold_return_case',
                $caseId,
                $lossId,
                ['loss_event_id' => $lossId, 'quantity' => (string) $lossQty],
                $data['correlation_id'] ?? null
            );

            $this->audit->record(
                'POST_UNSOLD_RETURN_LOSS',
                'unsold_return_case',
                $caseId,
                $data['actor_id'],
                $case->company_id,
                $case->plant_id,
                'SUCCESS'
            );

            $result = [
                'return_case_id' => $caseId,
                'loss_event_id' => $lossId,
                'loss_quantity' => (string) $lossQty,
                'status' => 'LOSS_POSTED',
                'record_version' => $case->record_version + 1,
                'stock_movement_ids' => [...$outcomeMovementIds, ...$destructionMovementIds],
                'destruction_stock_movement_ids' => $destructionMovementIds,
            ];

            $this->idempotency->complete($namespace, $key, $result);

            return $result;
        });
    }

    private function idempotencyPayload(array $data): array
    {
        return Arr::except($data, ['idempotency_key', 'correlation_id']);
    }

    private function assertExpectedVersion(object $case, int $expectedVersion): void
    {
        if ((int) $case->record_version !== $expectedVersion) {
            throw new ConflictHttpException(
                "The return case changed from version {$expectedVersion} to {$case->record_version}. Refresh it before retrying."
            );
        }
    }

    private function ensureReturnPosition(
        string $companyId,
        string $plantId,
        string $skuId,
        ?string $lotId,
        string $uomCode,
        string $actorId,
    ): string {
        $location = DB::table('locations')
            ->where('company_id', $companyId)
            ->where('plant_id', $plantId)
            ->where('location_type', 'RETURN_QUARANTINE')
            ->where('status', 'ACTIVE')
            ->orderBy('code')
            ->first(['id', 'code', 'name']);

        if (! $location) {
            throw ValidationException::withMessages([
                'return_quarantine' => [
                    'No active Return Quarantine location is configured for this plant. Configure one in Locations before receiving unsold returns.',
                ],
            ]);
        }

        $owner = DB::table('inventory_owners')
            ->where('company_id', $companyId)
            ->where('owner_type', 'COMPANY')
            ->where('status', 'ACTIVE')
            ->orderBy('code')
            ->first(['id']);
        if (! $owner) {
            throw ValidationException::withMessages([
                'return_quarantine' => [
                    'The company inventory owner is not active. Restore the owner master before receiving unsold returns.',
                ],
            ]);
        }

        $query = DB::table('stock_positions')
            ->where('company_id', $companyId)
            ->where('plant_id', $plantId)
            ->where('item_id', $skuId)
            ->where('inventory_owner_id', $owner->id)
            ->where('location_id', $location->id)
            ->where('quality_status', 'RETURN_QUARANTINE')
            ->where('uom_code', $uomCode);
        $lotId === null ? $query->whereNull('lot_id') : $query->where('lot_id', $lotId);
        $existing = $query->lockForUpdate()->first(['id']);
        if ($existing) {
            return (string) $existing->id;
        }

        $id = (string) Str::uuid();
        DB::table('stock_positions')->insert([
            'id' => $id,
            'company_id' => $companyId,
            'plant_id' => $plantId,
            'item_id' => $skuId,
            'lot_id' => $lotId,
            'owner_party_id' => null,
            'inventory_owner_id' => $owner->id,
            'location_id' => $location->id,
            'quality_status' => 'RETURN_QUARANTINE',
            'quantity_base' => '0',
            'reserved_quantity_base' => '0',
            'uom_code' => $uomCode,
            'record_version' => 1,
            'status_reason' => 'Provisioned for an unsold-return quarantine receipt.',
            'status_changed_at' => now(),
            'status_changed_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function validatedReturnPosition(
        object $line,
        array $lineInput,
        object $case,
        int $index,
    ): object {
        $position = DB::table('stock_positions as position')
            ->join('locations as location', function ($join) {
                $join
                    ->on('location.id', '=', 'position.location_id')
                    ->on('location.company_id', '=', 'position.company_id')
                    ->on('location.plant_id', '=', 'position.plant_id');
            })
            ->where('position.id', $lineInput['return_position_id'])
            ->where('position.company_id', $case->company_id)
            ->where('position.plant_id', $case->plant_id)
            ->where('position.quality_status', 'RETURN_QUARANTINE')
            ->where('location.location_type', 'RETURN_QUARANTINE')
            ->where('location.status', 'ACTIVE')
            ->lockForUpdate()
            ->first([
                'position.id',
                'position.item_id',
                'position.lot_id',
                'position.uom_code',
                'position.quantity_base',
                'position.record_version',
            ]);

        if (
            ! $position
            || (string) $position->item_id !== (string) $line->sku_id
            || (string) $position->lot_id !== (string) ($line->fg_lot_id ?? '')
            || $position->uom_code !== $line->uom_code
        ) {
            throw ValidationException::withMessages([
                "lines.{$index}.return_position_id" => [
                    'Select an active return-quarantine position for this case plant, SKU, lot, and UOM.',
                ],
            ]);
        }

        if ($line->return_position_id && (string) $line->return_position_id !== (string) $position->id) {
            throw ValidationException::withMessages([
                "lines.{$index}.return_position_id" => [
                    'A partial return must continue into the quarantine position used for its first receipt.',
                ],
            ]);
        }

        return $position;
    }

    private function recordShipmentReturn(object $line, array $lineInput, object $case, int $index): void
    {
        if (! $line->shipment_line_id) {
            return;
        }

        $shipmentLine = DB::table('shipment_lines')
            ->where('id', $line->shipment_line_id)
            ->where('shipment_id', $case->shipment_id)
            ->where('company_id', $case->company_id)
            ->where('plant_id', $case->plant_id)
            ->lockForUpdate()
            ->first(['id', 'shipped_quantity', 'returned_quantity', 'record_version']);

        if (! $shipmentLine) {
            throw ValidationException::withMessages([
                "lines.{$index}.line_id" => ['The original shipment line is no longer available in this context.'],
            ]);
        }

        $newReturned = bcadd(
            (string) $shipmentLine->returned_quantity,
            (string) $lineInput['received_quantity'],
            6
        );
        if (bccomp($newReturned, (string) $shipmentLine->shipped_quantity, 6) > 0) {
            throw ValidationException::withMessages([
                "lines.{$index}.received_quantity" => ['Receipt would exceed the shipment quantity available to return.'],
            ]);
        }

        DB::table('shipment_lines')->where('id', $shipmentLine->id)->update([
            'returned_quantity' => $newReturned,
            'record_version' => $shipmentLine->record_version + 1,
            'updated_at' => now(),
        ]);
    }

    private function postQuarantineReceipt(
        object $case,
        object $line,
        object $position,
        string $quantity,
        array $data,
        string $commandKey,
    ): string {
        $movementId = (string) Str::uuid();
        $movementKey = 'return-receipt:'.hash('sha256', $commandKey.'|'.$line->id);

        DB::table('stock_positions')->where('id', $position->id)->update([
            'quantity_base' => bcadd((string) $position->quantity_base, $quantity, 6),
            'record_version' => $position->record_version + 1,
            'updated_at' => now(),
        ]);

        DB::table('stock_movements')->insert([
            'id' => $movementId,
            'company_id' => $case->company_id,
            'plant_id' => $case->plant_id,
            'movement_type' => 'UNSOLD_RETURN_RECEIPT',
            'source_type' => 'UNSOLD_RETURN',
            'source_id' => $case->id,
            'source_version' => $data['expected_version'],
            'from_position_id' => null,
            'to_position_id' => $position->id,
            'quantity_base' => $quantity,
            'uom_code' => $line->uom_code,
            'actor_id' => $data['actor_id'],
            'reason_code' => 'RETURN_QUARANTINE_RECEIPT',
            'idempotency_key' => $movementKey,
            'event_at' => now(),
            'posted_at' => now(),
            'created_at' => now(),
        ]);

        $this->audit->record(
            'POST_STOCK_MOVEMENT',
            'stock_movement',
            $movementId,
            $data['actor_id'],
            $case->company_id,
            $case->plant_id,
            'SUCCESS',
            [
                'entity_version' => 1,
                'correlation_id' => $data['correlation_id'] ?? null,
                'reason_code' => 'RETURN_QUARANTINE_RECEIPT',
            ]
        );

        $this->outbox->append(
            'inventory.movement.posted',
            'stock_movement',
            $movementId,
            $movementId,
            [
                'movement_id' => $movementId,
                'source_type' => 'UNSOLD_RETURN',
                'source_id' => $case->id,
                'to_position_id' => $position->id,
                'quantity_base' => $quantity,
                'uom_code' => $line->uom_code,
            ],
            $data['correlation_id'] ?? null
        );

        return $movementId;
    }

    private function recordStatusTransition(
        string $caseId,
        string $companyId,
        string $plantId,
        ?string $fromStatus,
        string $toStatus,
        int $recordVersion,
        string $actorId,
    ): void {
        if ($fromStatus === $toStatus) {
            return;
        }

        DB::table('unsold_return_status_history')->insert([
            'id' => (string) Str::uuid(),
            'return_case_id' => $caseId,
            'company_id' => $companyId,
            'plant_id' => $plantId,
            'from_status' => $fromStatus,
            'to_status' => $toStatus,
            'record_version' => $recordVersion,
            'actor_id' => $actorId,
            'created_at' => now(),
        ]);
    }
}

<?php

namespace App\Modules\Procurement\Application;

use App\Modules\Inventory\Application\StockPostingService;
use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use App\Shared\Visibility\TransactionVisibilityPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class InboundProcurementService
{
    public const GATE_STATUSES = ['ARRIVED', 'CLEARED', 'CANCELLED'];
    public const RECEIPT_STATUSES = ['DRAFT', 'QC_PENDING', 'COMPLETED', 'CANCELLED'];
    public const QUALITY_STATUSES = ['PENDING', 'COMPLETED'];
    public const RETURN_STATUSES = ['DRAFT', 'POSTED', 'CANCELLED'];

    public function __construct(
        private readonly StockPostingService $stock,
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
        private readonly TransactionVisibilityPolicy $visibility,
    ) {}

    public function createGateEntry(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'procurement.gate-entry.create';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }
            $this->uniqueNumber('gate_entries', 'gate_entry_number', $data['gate_entry_number'], $data);
            $this->visibility->assertOperationallyVisible(
                $data['permissions'] ?? [TransactionVisibilityPolicy::SHOW_PERMISSION],
                'PURCHASE_ORDER',
                $data['purchase_order_id'],
                $data,
            );
            $order = $this->issuedOrder($data['purchase_order_id'], $data);
            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('gate_entries')->insert([
                'id' => $id,
                'company_id' => $data['company_id'],
                'plant_id' => $data['plant_id'],
                'gate_entry_number' => $data['gate_entry_number'],
                'purchase_order_id' => $order->id,
                'supplier_party_id' => $order->supplier_party_id,
                'vehicle_number' => $data['vehicle_number'],
                'transporter_name' => $this->nullable($data['transporter_name'] ?? null),
                'supplier_document_number' => $this->nullable($data['supplier_document_number'] ?? null),
                'arrived_at' => CarbonImmutable::parse($data['arrived_at']),
                'notes' => $this->nullable($data['notes'] ?? null),
                'status' => 'ARRIVED',
                'record_version' => 1,
                'created_by' => $data['actor_id'],
                'cleared_at' => null,
                'cleared_by' => null,
                'cancelled_at' => null,
                'cancelled_by' => null,
                'cancellation_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $result = $this->result($id, 'ARRIVED', 1);
            $this->record('CREATE_GATE_ENTRY', 'procurement.gate-entry.created', 'gate_entry', $id, $data, 1, [
                'gate_entry_number' => $data['gate_entry_number'],
                'purchase_order_id' => (string) $order->id,
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function updateGateEntry(string $gateEntryId, array $data): array
    {
        return DB::transaction(function () use ($gateEntryId, $data): array {
            $namespace = 'procurement.gate-entry.update.'.$gateEntryId;
            if ($replay = $this->begin($namespace, $data + ['gate_entry_id' => $gateEntryId])) {
                return $replay;
            }
            $gate = $this->findLocked('gate_entries', $gateEntryId, $data, 'Gate entry');
            $this->assertVersion($gate, $data['expected_version']);
            $this->assertStatus($gate, ['ARRIVED'], 'Only an arrived gate entry can be edited.');
            $version = (int) $gate->record_version + 1;
            DB::table('gate_entries')->where('id', $gateEntryId)->update([
                'vehicle_number' => $data['vehicle_number'],
                'transporter_name' => $this->nullable($data['transporter_name'] ?? null),
                'supplier_document_number' => $this->nullable($data['supplier_document_number'] ?? null),
                'arrived_at' => CarbonImmutable::parse($data['arrived_at']),
                'notes' => $this->nullable($data['notes'] ?? null),
                'record_version' => $version,
                'updated_at' => now(),
            ]);
            $result = $this->result($gateEntryId, 'ARRIVED', $version);
            $this->record('UPDATE_GATE_ENTRY', 'procurement.gate-entry.updated', 'gate_entry', $gateEntryId, $data, $version, [
                'vehicle_number' => ['from' => $gate->vehicle_number, 'to' => $data['vehicle_number']],
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function cancelGateEntry(string $gateEntryId, string $reason, array $data): array
    {
        return DB::transaction(function () use ($gateEntryId, $reason, $data): array {
            $namespace = 'procurement.gate-entry.cancel.'.$gateEntryId;
            if ($replay = $this->begin($namespace, $data + ['gate_entry_id' => $gateEntryId, 'reason' => $reason])) {
                return $replay;
            }
            $gate = $this->findLocked('gate_entries', $gateEntryId, $data, 'Gate entry');
            $this->assertVersion($gate, $data['expected_version']);
            $this->assertStatus($gate, ['ARRIVED'], 'Only an arrived gate entry can be cancelled.');
            if (DB::table('receipts')->where('gate_entry_id', $gateEntryId)->whereNotNull('receipt_number')->where('status', '<>', 'CANCELLED')->exists()) {
                throw ValidationException::withMessages(['status' => ['Cancel the linked draft GRN first.']]);
            }
            $version = (int) $gate->record_version + 1;
            DB::table('gate_entries')->where('id', $gateEntryId)->update([
                'status' => 'CANCELLED', 'record_version' => $version,
                'cancelled_at' => now(), 'cancelled_by' => $data['actor_id'],
                'cancellation_reason' => trim($reason), 'updated_at' => now(),
            ]);
            $result = $this->result($gateEntryId, 'CANCELLED', $version);
            $this->record('CANCEL_GATE_ENTRY', 'procurement.gate-entry.cancelled', 'gate_entry', $gateEntryId, $data, $version, ['reason' => $reason], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function createReceipt(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'procurement.receipt.create';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }
            $this->uniqueNumber('receipts', 'receipt_number', $data['receipt_number'], $data);
            $this->visibility->assertOperationallyVisible(
                $data['permissions'] ?? [TransactionVisibilityPolicy::SHOW_PERMISSION],
                'GATE_ENTRY',
                $data['gate_entry_id'],
                $data,
            );
            $gate = $this->findLocked('gate_entries', $data['gate_entry_id'], $data, 'Gate entry');
            $this->assertStatus($gate, ['ARRIVED'], 'Select an arrived gate entry.');
            if (DB::table('receipts')->where('gate_entry_id', $gate->id)->whereNotNull('receipt_number')->exists()) {
                throw ValidationException::withMessages(['gate_entry_id' => ['That gate entry already has a GRN.']]);
            }
            $order = $this->issuedOrder($gate->purchase_order_id, $data);
            $lines = $this->prepareReceiptLines($order, $data['lines'], $data);
            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('receipts')->insert([
                'id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'receipt_number' => $data['receipt_number'], 'gate_entry_id' => $gate->id,
                'purchase_order_id' => $order->id, 'supplier_party_id' => $order->supplier_party_id,
                'receipt_date' => $data['receipt_date'],
                'supplier_document_number' => $this->nullable($data['supplier_document_number'] ?? $gate->supplier_document_number),
                'notes' => $this->nullable($data['notes'] ?? null), 'status' => 'DRAFT',
                'record_version' => 1, 'created_by' => $data['actor_id'],
                'posted_at' => null, 'posted_by' => null, 'cancelled_at' => null,
                'cancelled_by' => null, 'cancellation_reason' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->replaceReceiptLines($id, $order, $lines, $data, $now);
            $result = $this->result($id, 'DRAFT', 1, ['line_count' => count($lines)]);
            $this->record('CREATE_GOODS_RECEIPT', 'procurement.receipt.created', 'goods_receipt', $id, $data, 1, [
                'receipt_number' => $data['receipt_number'], 'purchase_order_id' => (string) $order->id,
                'line_count' => count($lines),
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function updateReceipt(string $receiptId, array $data): array
    {
        return DB::transaction(function () use ($receiptId, $data): array {
            $namespace = 'procurement.receipt.update.'.$receiptId;
            if ($replay = $this->begin($namespace, $data + ['receipt_id' => $receiptId])) {
                return $replay;
            }
            $receipt = $this->findLocked('receipts', $receiptId, $data, 'GRN', 'receipt_number');
            $this->assertVersion($receipt, $data['expected_version']);
            $this->assertStatus($receipt, ['DRAFT'], 'Only a draft GRN can be edited.');
            $order = $this->issuedOrder($receipt->purchase_order_id, $data);
            $lines = $this->prepareReceiptLines($order, $data['lines'], $data, $receiptId);
            $version = (int) $receipt->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table('receipts')->where('id', $receiptId)->update([
                'receipt_date' => $data['receipt_date'],
                'supplier_document_number' => $this->nullable($data['supplier_document_number'] ?? null),
                'notes' => $this->nullable($data['notes'] ?? null),
                'record_version' => $version, 'updated_at' => $now,
            ]);
            DB::table('receipt_lines')->where('receipt_id', $receiptId)->delete();
            $this->replaceReceiptLines($receiptId, $order, $lines, $data, $now);
            $result = $this->result($receiptId, 'DRAFT', $version, ['line_count' => count($lines)]);
            $this->record('UPDATE_GOODS_RECEIPT', 'procurement.receipt.updated', 'goods_receipt', $receiptId, $data, $version, ['line_count' => count($lines)], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function postReceipt(string $receiptId, array $data): array
    {
        return DB::transaction(function () use ($receiptId, $data): array {
            $namespace = 'procurement.receipt.post.'.$receiptId;
            if ($replay = $this->begin($namespace, $data + ['receipt_id' => $receiptId])) {
                return $replay;
            }
            $receipt = $this->findLocked('receipts', $receiptId, $data, 'GRN', 'receipt_number');
            $this->assertVersion($receipt, $data['expected_version']);
            $this->assertStatus($receipt, ['DRAFT'], 'Only a draft GRN can be posted.');
            $gate = $this->findLocked('gate_entries', $receipt->gate_entry_id, $data, 'Gate entry');
            $this->assertStatus($gate, ['ARRIVED'], 'The linked gate entry is not available for receipt.');
            $order = $this->issuedOrder($receipt->purchase_order_id, $data);
            $lines = DB::table('receipt_lines')->where('receipt_id', $receiptId)
                ->orderBy('line_number')->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw new ConflictHttpException('The GRN has no lines to post.');
            }
            $owner = DB::table('inventory_owners')->where('company_id', $data['company_id'])
                ->where('owner_type', 'COMPANY')->where('status', 'ACTIVE')->lockForUpdate()->first();
            if (! $owner) {
                throw new ConflictHttpException('Activate the company inventory owner before receiving stock.');
            }
            foreach ($lines as $line) {
                $poLine = DB::table('purchase_order_lines')->where('id', $line->purchase_order_line_id)
                    ->where('purchase_order_id', $order->id)->lockForUpdate()->first();
                if (! $poLine) {
                    throw new ConflictHttpException('A source purchase-order line is no longer available.');
                }
                $already = $this->postedReceiptQuantity((string) $line->purchase_order_line_id, $receiptId);
                if (bccomp(bcadd($already, $this->decimal($line->received_quantity), 6), $this->decimal($poLine->ordered_quantity), 6) > 0) {
                    throw ValidationException::withMessages([
                        'lines' => ["Receipt line {$line->line_number} exceeds the remaining purchase-order quantity."],
                    ]);
                }
                $lot = $this->resolvePurchaseLot($line, $receipt, $data);
                $hold = $this->resolvePosition(
                    $data, (string) $line->item_id, (string) $lot->id, (string) $owner->id,
                    (string) $line->quality_hold_location_id, 'QUALITY_HOLD', (string) $line->uom_code,
                );
                $movement = $this->stock->receive([
                    'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                    'target_position_id' => (string) $hold->id,
                    'quantity_base' => $this->decimal($line->received_quantity), 'uom_code' => $line->uom_code,
                    'movement_type' => 'PURCHASE_RECEIPT', 'source_type' => 'goods_receipt_line',
                    'source_id' => (string) $line->id, 'source_version' => 1,
                    'expected_item_id' => (string) $line->item_id, 'expected_lot_id' => (string) $lot->id,
                    'expected_owner_id' => (string) $owner->id, 'expected_quality_status' => 'QUALITY_HOLD',
                    'actor_id' => $data['actor_id'], 'reason_code' => 'PO_RECEIPT',
                    'idempotency_key' => $data['idempotency_key'].':receive:'.$line->line_number,
                    'correlation_id' => $data['correlation_id'], 'event_at' => now(),
                ]);
                DB::table('receipt_lines')->where('id', $line->id)->update([
                    'lot_id' => $lot->id, 'quality_hold_position_id' => $hold->id,
                    'stock_receipt_movement_id' => $movement['movement_id'], 'updated_at' => now(),
                ]);
            }
            $taskId = (string) Str::uuid();
            $taskNumber = 'QC-'.$receipt->receipt_number;
            $now = CarbonImmutable::now();
            DB::table('quality_tasks')->insert([
                'id' => $taskId, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'task_number' => $taskNumber, 'receipt_id' => $receiptId, 'notes' => null,
                'status' => 'PENDING', 'record_version' => 1, 'created_by' => $data['actor_id'],
                'completed_at' => null, 'completed_by' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $postedLines = DB::table('receipt_lines')->where('receipt_id', $receiptId)->orderBy('line_number')->get();
            foreach ($postedLines as $line) {
                DB::table('incoming_quality_lines')->insert([
                    'id' => (string) Str::uuid(), 'quality_task_id' => $taskId, 'receipt_id' => $receiptId,
                    'receipt_line_id' => $line->id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                    'line_number' => $line->line_number, 'item_id' => $line->item_id, 'lot_id' => $line->lot_id,
                    'uom_code' => $line->uom_code, 'inspected_quantity' => $this->decimal($line->received_quantity),
                    'accepted_quantity' => 0, 'rejected_quantity' => 0, 'result' => 'PENDING',
                    'rejection_reason' => null, 'quality_hold_position_id' => $line->quality_hold_position_id,
                    'released_position_id' => null, 'rejected_position_id' => null,
                    'accepted_movement_id' => null, 'rejected_movement_id' => null,
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $version = (int) $receipt->record_version + 1;
            DB::table('receipts')->where('id', $receiptId)->update([
                'status' => 'QC_PENDING', 'record_version' => $version,
                'posted_at' => $now, 'posted_by' => $data['actor_id'], 'updated_at' => $now,
            ]);
            DB::table('gate_entries')->where('id', $gate->id)->update([
                'status' => 'CLEARED', 'record_version' => (int) $gate->record_version + 1,
                'cleared_at' => $now, 'cleared_by' => $data['actor_id'], 'updated_at' => $now,
            ]);
            $result = $this->result($receiptId, 'QC_PENDING', $version, [
                'quality_task_id' => $taskId, 'quality_task_number' => $taskNumber,
                'line_count' => $lines->count(),
            ]);
            $this->record('POST_GOODS_RECEIPT', 'procurement.receipt.posted', 'goods_receipt', $receiptId, $data, $version, [
                'status' => ['from' => 'DRAFT', 'to' => 'QC_PENDING'], 'quality_task_id' => $taskId,
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function cancelReceipt(string $receiptId, string $reason, array $data): array
    {
        return DB::transaction(function () use ($receiptId, $reason, $data): array {
            $namespace = 'procurement.receipt.cancel.'.$receiptId;
            if ($replay = $this->begin($namespace, $data + ['receipt_id' => $receiptId, 'reason' => $reason])) {
                return $replay;
            }
            $receipt = $this->findLocked('receipts', $receiptId, $data, 'GRN', 'receipt_number');
            $this->assertVersion($receipt, $data['expected_version']);
            $this->assertStatus($receipt, ['DRAFT'], 'Only a draft GRN can be cancelled.');
            $version = (int) $receipt->record_version + 1;
            DB::table('receipts')->where('id', $receiptId)->update([
                'status' => 'CANCELLED', 'record_version' => $version,
                'cancelled_at' => now(), 'cancelled_by' => $data['actor_id'],
                'cancellation_reason' => trim($reason), 'updated_at' => now(),
            ]);
            $result = $this->result($receiptId, 'CANCELLED', $version);
            $this->record('CANCEL_GOODS_RECEIPT', 'procurement.receipt.cancelled', 'goods_receipt', $receiptId, $data, $version, ['reason' => $reason], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function completeIncomingQuality(string $qualityTaskId, array $data): array
    {
        return DB::transaction(function () use ($qualityTaskId, $data): array {
            $namespace = 'quality.incoming.complete.'.$qualityTaskId;
            if ($replay = $this->begin($namespace, $data + ['quality_task_id' => $qualityTaskId])) {
                return $replay;
            }
            $task = $this->findLocked('quality_tasks', $qualityTaskId, $data, 'Incoming QC task', 'task_number');
            $this->assertVersion($task, $data['expected_version']);
            $this->assertStatus($task, ['PENDING'], 'Only a pending incoming QC task can be completed.');
            $qualityLines = DB::table('incoming_quality_lines')->where('quality_task_id', $qualityTaskId)
                ->orderBy('line_number')->lockForUpdate()->get()->keyBy('id');
            if (count($data['lines']) !== $qualityLines->count()) {
                throw ValidationException::withMessages(['lines' => ['Decide every incoming QC line exactly once.']]);
            }
            $seen = [];
            $acceptedTotal = '0.000000';
            $rejectedTotal = '0.000000';
            foreach (array_values($data['lines']) as $index => $decision) {
                $lineId = (string) $decision['quality_line_id'];
                $line = $qualityLines->get($lineId);
                if (! $line || isset($seen[$lineId])) {
                    throw ValidationException::withMessages(["lines.{$index}.quality_line_id" => ['Select every incoming QC line exactly once.']]);
                }
                $seen[$lineId] = true;
                $accepted = $this->nonNegative($decision['accepted_quantity'], "lines.{$index}.accepted_quantity");
                $rejected = $this->nonNegative($decision['rejected_quantity'], "lines.{$index}.rejected_quantity");
                if (bccomp(bcadd($accepted, $rejected, 6), $this->decimal($line->inspected_quantity), 6) !== 0) {
                    throw ValidationException::withMessages(["lines.{$index}.accepted_quantity" => ['Accepted plus rejected quantity must equal the inspected quantity.']]);
                }
                $reason = $this->nullable($decision['rejection_reason'] ?? null);
                if (bccomp($rejected, '0', 6) > 0 && $reason === null) {
                    throw ValidationException::withMessages(["lines.{$index}.rejection_reason" => ['Explain every rejected quantity.']]);
                }
                $source = DB::table('stock_positions')->where('id', $line->quality_hold_position_id)->lockForUpdate()->first();
                if (! $source || $source->quality_status !== 'QUALITY_HOLD') {
                    throw new ConflictHttpException('The quality-hold stock position is no longer valid.');
                }
                $receiptLine = DB::table('receipt_lines')->where('id', $line->receipt_line_id)->firstOrFail();
                $releasedPositionId = null;
                $rejectedPositionId = null;
                $acceptedMovementId = null;
                $rejectedMovementId = null;
                if (bccomp($accepted, '0', 6) > 0) {
                    $target = $this->resolvePosition($data, (string) $line->item_id, (string) $line->lot_id,
                        (string) $source->inventory_owner_id, (string) $receiptLine->released_location_id, 'RELEASED', (string) $line->uom_code);
                    $movement = $this->stock->move([
                        'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                        'source_position_id' => (string) $source->id, 'target_position_id' => (string) $target->id,
                        'quantity_base' => $accepted, 'uom_code' => $line->uom_code,
                        'movement_type' => 'INCOMING_QC_ACCEPT', 'source_type' => 'incoming_quality_line',
                        'source_id' => $lineId, 'source_version' => 1,
                        'expected_item_id' => (string) $line->item_id, 'expected_lot_id' => (string) $line->lot_id,
                        'expected_owner_id' => (string) $source->inventory_owner_id,
                        'expected_source_quality_status' => 'QUALITY_HOLD', 'expected_target_quality_status' => 'RELEASED',
                        'actor_id' => $data['actor_id'], 'reason_code' => 'QC_ACCEPTED',
                        'idempotency_key' => $data['idempotency_key'].':accept:'.$line->line_number,
                        'correlation_id' => $data['correlation_id'], 'event_at' => now(),
                    ]);
                    $releasedPositionId = $target->id;
                    $acceptedMovementId = $movement['movement_id'];
                }
                if (bccomp($rejected, '0', 6) > 0) {
                    $target = $this->resolvePosition($data, (string) $line->item_id, (string) $line->lot_id,
                        (string) $source->inventory_owner_id, (string) $receiptLine->quality_hold_location_id, 'REJECTED', (string) $line->uom_code);
                    $movement = $this->stock->move([
                        'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                        'source_position_id' => (string) $source->id, 'target_position_id' => (string) $target->id,
                        'quantity_base' => $rejected, 'uom_code' => $line->uom_code,
                        'movement_type' => 'INCOMING_QC_REJECT', 'source_type' => 'incoming_quality_line',
                        'source_id' => $lineId, 'source_version' => 1,
                        'expected_item_id' => (string) $line->item_id, 'expected_lot_id' => (string) $line->lot_id,
                        'expected_owner_id' => (string) $source->inventory_owner_id,
                        'expected_source_quality_status' => 'QUALITY_HOLD', 'expected_target_quality_status' => 'REJECTED',
                        'actor_id' => $data['actor_id'], 'reason_code' => 'QC_REJECTED',
                        'idempotency_key' => $data['idempotency_key'].':reject:'.$line->line_number,
                        'correlation_id' => $data['correlation_id'], 'event_at' => now(),
                    ]);
                    $rejectedPositionId = $target->id;
                    $rejectedMovementId = $movement['movement_id'];
                }
                $result = bccomp($rejected, '0', 6) === 0 ? 'PASS'
                    : (bccomp($accepted, '0', 6) === 0 ? 'FAIL' : 'PARTIAL');
                DB::table('incoming_quality_lines')->where('id', $lineId)->update([
                    'accepted_quantity' => $accepted, 'rejected_quantity' => $rejected,
                    'result' => $result, 'rejection_reason' => $reason,
                    'released_position_id' => $releasedPositionId, 'rejected_position_id' => $rejectedPositionId,
                    'accepted_movement_id' => $acceptedMovementId, 'rejected_movement_id' => $rejectedMovementId,
                    'updated_at' => now(),
                ]);
                DB::table('receipt_lines')->where('id', $line->receipt_line_id)->update([
                    'accepted_quantity' => $accepted, 'rejected_quantity' => $rejected, 'updated_at' => now(),
                ]);
                $acceptedTotal = bcadd($acceptedTotal, $accepted, 6);
                $rejectedTotal = bcadd($rejectedTotal, $rejected, 6);
            }
            $version = (int) $task->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table('quality_tasks')->where('id', $qualityTaskId)->update([
                'status' => 'COMPLETED', 'record_version' => $version,
                'notes' => $this->nullable($data['notes'] ?? null),
                'completed_at' => $now, 'completed_by' => $data['actor_id'], 'updated_at' => $now,
            ]);
            $receipt = DB::table('receipts')->where('id', $task->receipt_id)->lockForUpdate()->firstOrFail();
            DB::table('receipts')->where('id', $receipt->id)->update([
                'status' => 'COMPLETED', 'record_version' => (int) $receipt->record_version + 1, 'updated_at' => $now,
            ]);
            $result = $this->result($qualityTaskId, 'COMPLETED', $version, [
                'receipt_id' => (string) $receipt->id, 'accepted_quantity' => $acceptedTotal,
                'rejected_quantity' => $rejectedTotal,
            ]);
            $this->record('COMPLETE_INCOMING_QUALITY', 'quality.incoming.completed', 'incoming_quality_task', $qualityTaskId, $data, $version, [
                'accepted_quantity' => $acceptedTotal, 'rejected_quantity' => $rejectedTotal,
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function createSupplierReturn(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'procurement.supplier-return.create';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }
            $this->uniqueNumber('supplier_returns', 'return_number', $data['return_number'], $data);
            [$supplierId, $lines] = $this->prepareSupplierReturnLines($data['lines'], $data);
            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('supplier_returns')->insert([
                'id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'return_number' => $data['return_number'], 'supplier_party_id' => $supplierId,
                'return_date' => $data['return_date'], 'reason' => trim($data['reason']),
                'status' => 'DRAFT', 'record_version' => 1, 'created_by' => $data['actor_id'],
                'posted_at' => null, 'posted_by' => null, 'cancelled_at' => null,
                'cancelled_by' => null, 'cancellation_reason' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->replaceSupplierReturnLines($id, $lines, $data, $now);
            $result = $this->result($id, 'DRAFT', 1, ['line_count' => count($lines)]);
            $this->record('CREATE_SUPPLIER_RETURN', 'procurement.supplier-return.created', 'supplier_return', $id, $data, 1, [
                'return_number' => $data['return_number'], 'line_count' => count($lines),
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function updateSupplierReturn(string $supplierReturnId, array $data): array
    {
        return DB::transaction(function () use ($supplierReturnId, $data): array {
            $namespace = 'procurement.supplier-return.update.'.$supplierReturnId;
            if ($replay = $this->begin($namespace, $data + ['supplier_return_id' => $supplierReturnId])) {
                return $replay;
            }
            $return = $this->findLocked('supplier_returns', $supplierReturnId, $data, 'Supplier return');
            $this->assertVersion($return, $data['expected_version']);
            $this->assertStatus($return, ['DRAFT'], 'Only a draft supplier return can be edited.');
            [$supplierId, $lines] = $this->prepareSupplierReturnLines($data['lines'], $data, $supplierReturnId);
            $version = (int) $return->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table('supplier_returns')->where('id', $supplierReturnId)->update([
                'supplier_party_id' => $supplierId, 'return_date' => $data['return_date'],
                'reason' => trim($data['reason']), 'record_version' => $version, 'updated_at' => $now,
            ]);
            DB::table('supplier_return_lines')->where('supplier_return_id', $supplierReturnId)->delete();
            $this->replaceSupplierReturnLines($supplierReturnId, $lines, $data, $now);
            $result = $this->result($supplierReturnId, 'DRAFT', $version, ['line_count' => count($lines)]);
            $this->record('UPDATE_SUPPLIER_RETURN', 'procurement.supplier-return.updated', 'supplier_return', $supplierReturnId, $data, $version, ['line_count' => count($lines)], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function postSupplierReturn(string $supplierReturnId, array $data): array
    {
        return DB::transaction(function () use ($supplierReturnId, $data): array {
            $namespace = 'procurement.supplier-return.post.'.$supplierReturnId;
            if ($replay = $this->begin($namespace, $data + ['supplier_return_id' => $supplierReturnId])) {
                return $replay;
            }
            $return = $this->findLocked('supplier_returns', $supplierReturnId, $data, 'Supplier return');
            $this->assertVersion($return, $data['expected_version']);
            $this->assertStatus($return, ['DRAFT'], 'Only a draft supplier return can be posted.');
            $lines = DB::table('supplier_return_lines')->where('supplier_return_id', $supplierReturnId)
                ->orderBy('line_number')->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw new ConflictHttpException('The supplier return has no lines to post.');
            }
            foreach ($lines as $line) {
                $quality = DB::table('incoming_quality_lines')->where('id', $line->incoming_quality_line_id)
                    ->lockForUpdate()->first();
                if (! $quality || bccomp($this->decimal($quality->rejected_quantity), '0', 6) <= 0) {
                    throw new ConflictHttpException('A rejected QC source is no longer available.');
                }
                $already = $this->postedSupplierReturnQuantity((string) $quality->id, $supplierReturnId);
                if (bccomp(bcadd($already, $this->decimal($line->return_quantity), 6), $this->decimal($quality->rejected_quantity), 6) > 0) {
                    throw ValidationException::withMessages(['lines' => ["Return line {$line->line_number} exceeds rejected stock still returnable."]]);
                }
                $movement = $this->stock->issue([
                    'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                    'source_position_id' => (string) $line->rejected_position_id,
                    'quantity_base' => $this->decimal($line->return_quantity), 'uom_code' => $line->uom_code,
                    'movement_type' => 'SUPPLIER_RETURN', 'source_type' => 'supplier_return_line',
                    'source_id' => (string) $line->id, 'source_version' => 1,
                    'expected_item_id' => (string) $line->item_id, 'expected_lot_id' => (string) $line->lot_id,
                    'expected_owner_id' => $data['company_id'], 'expected_quality_status' => 'REJECTED',
                    'actor_id' => $data['actor_id'], 'reason_code' => 'RETURN_TO_SUPPLIER',
                    'idempotency_key' => $data['idempotency_key'].':return:'.$line->line_number,
                    'correlation_id' => $data['correlation_id'], 'event_at' => now(),
                ]);
                DB::table('supplier_return_lines')->where('id', $line->id)->update([
                    'movement_id' => $movement['movement_id'], 'updated_at' => now(),
                ]);
            }
            $version = (int) $return->record_version + 1;
            DB::table('supplier_returns')->where('id', $supplierReturnId)->update([
                'status' => 'POSTED', 'record_version' => $version,
                'posted_at' => now(), 'posted_by' => $data['actor_id'], 'updated_at' => now(),
            ]);
            $result = $this->result($supplierReturnId, 'POSTED', $version, ['line_count' => $lines->count()]);
            $this->record('POST_SUPPLIER_RETURN', 'procurement.supplier-return.posted', 'supplier_return', $supplierReturnId, $data, $version, ['line_count' => $lines->count()], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function cancelSupplierReturn(string $supplierReturnId, string $reason, array $data): array
    {
        return DB::transaction(function () use ($supplierReturnId, $reason, $data): array {
            $namespace = 'procurement.supplier-return.cancel.'.$supplierReturnId;
            if ($replay = $this->begin($namespace, $data + ['supplier_return_id' => $supplierReturnId, 'reason' => $reason])) {
                return $replay;
            }
            $return = $this->findLocked('supplier_returns', $supplierReturnId, $data, 'Supplier return');
            $this->assertVersion($return, $data['expected_version']);
            $this->assertStatus($return, ['DRAFT'], 'Only a draft supplier return can be cancelled.');
            $version = (int) $return->record_version + 1;
            DB::table('supplier_returns')->where('id', $supplierReturnId)->update([
                'status' => 'CANCELLED', 'record_version' => $version,
                'cancelled_at' => now(), 'cancelled_by' => $data['actor_id'],
                'cancellation_reason' => trim($reason), 'updated_at' => now(),
            ]);
            $result = $this->result($supplierReturnId, 'CANCELLED', $version);
            $this->record('CANCEL_SUPPLIER_RETURN', 'procurement.supplier-return.cancelled', 'supplier_return', $supplierReturnId, $data, $version, ['reason' => $reason], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    private function prepareReceiptLines(object $order, array $input, array $scope, ?string $excludeReceiptId = null): array
    {
        $sources = DB::table('purchase_order_lines')->where('purchase_order_id', $order->id)
            ->orderBy('line_number')->get()->keyBy('id');
        $seen = [];
        $lines = [];
        foreach (array_values($input) as $index => $line) {
            $sourceId = (string) ($line['purchase_order_line_id'] ?? '');
            $source = $sources->get($sourceId);
            if (! $source || isset($seen[$sourceId])) {
                throw ValidationException::withMessages(["lines.{$index}.purchase_order_line_id" => ['Select each purchase-order line at most once.']]);
            }
            $seen[$sourceId] = true;
            $quantity = $this->positive($line['received_quantity'], "lines.{$index}.received_quantity");
            $already = $this->postedReceiptQuantity($sourceId, $excludeReceiptId);
            $remaining = bcsub($this->decimal($source->ordered_quantity), $already, 6);
            if (bccomp($quantity, $remaining, 6) > 0) {
                throw ValidationException::withMessages(["lines.{$index}.received_quantity" => ['Received quantity exceeds the purchase-order quantity still open.']]);
            }
            $hold = $this->activeLocation((string) $line['quality_hold_location_id'], $scope);
            if ($hold->location_type !== 'QUALITY_HOLD') {
                throw ValidationException::withMessages(["lines.{$index}.quality_hold_location_id" => ['Select an active Quality Hold location.']]);
            }
            $released = $this->activeLocation((string) $line['released_location_id'], $scope);
            if ((string) $released->id === (string) $hold->id || in_array($released->location_type, ['QUALITY_HOLD', 'BLOCKED', 'RETURN_QUARANTINE'], true)) {
                throw ValidationException::withMessages(["lines.{$index}.released_location_id" => ['Select an active released-stock location distinct from Quality Hold.']]);
            }
            $manufactured = $line['manufacture_date'] ?? null;
            $expires = $line['expiry_date'] ?? null;
            if ($manufactured && $expires && CarbonImmutable::parse($expires)->isBefore(CarbonImmutable::parse($manufactured))) {
                throw ValidationException::withMessages(["lines.{$index}.expiry_date" => ['Expiry date cannot be before manufacture date.']]);
            }
            $lines[] = [
                'source' => $source, 'received_quantity' => $quantity,
                'internal_lot_code' => Str::upper(trim($line['internal_lot_code'])),
                'supplier_lot_code' => $this->nullable($line['supplier_lot_code'] ?? null),
                'manufacture_date' => $manufactured, 'expiry_date' => $expires,
                'quality_hold_location_id' => (string) $hold->id,
                'released_location_id' => (string) $released->id,
                'notes' => $this->nullable($line['notes'] ?? null),
            ];
        }

        return $lines;
    }

    private function replaceReceiptLines(string $receiptId, object $order, array $lines, array $scope, CarbonImmutable $now): void
    {
        foreach (array_values($lines) as $index => $line) {
            $source = $line['source'];
            DB::table('receipt_lines')->insert([
                'id' => (string) Str::uuid(), 'receipt_id' => $receiptId,
                'purchase_order_id' => $order->id, 'purchase_order_line_id' => $source->id,
                'company_id' => $scope['company_id'], 'plant_id' => $scope['plant_id'],
                'line_number' => $index + 1, 'item_id' => $source->item_id,
                'description' => $source->description, 'ordered_quantity_snapshot' => $this->decimal($source->ordered_quantity),
                'received_quantity' => $line['received_quantity'], 'accepted_quantity' => 0, 'rejected_quantity' => 0,
                'uom_code' => $source->uom_code, 'internal_lot_code' => $line['internal_lot_code'],
                'supplier_lot_code' => $line['supplier_lot_code'], 'manufacture_date' => $line['manufacture_date'],
                'expiry_date' => $line['expiry_date'], 'quality_hold_location_id' => $line['quality_hold_location_id'],
                'released_location_id' => $line['released_location_id'], 'lot_id' => null,
                'quality_hold_position_id' => null, 'stock_receipt_movement_id' => null,
                'notes' => $line['notes'], 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function prepareSupplierReturnLines(array $input, array $scope, ?string $excludeReturnId = null): array
    {
        $seen = [];
        $supplierId = null;
        $lines = [];
        foreach (array_values($input) as $index => $line) {
            $qualityLineId = (string) $line['quality_line_id'];
            if (isset($seen[$qualityLineId])) {
                throw ValidationException::withMessages(["lines.{$index}.quality_line_id" => ['Select each rejected QC line at most once.']]);
            }
            $seen[$qualityLineId] = true;
            $quality = DB::table('incoming_quality_lines as quality')
                ->join('quality_tasks as task', 'task.id', '=', 'quality.quality_task_id')
                ->join('receipts as receipt', 'receipt.id', '=', 'quality.receipt_id')
                ->where('quality.id', $qualityLineId)
                ->where('quality.company_id', $scope['company_id'])->where('quality.plant_id', $scope['plant_id'])
                ->where('task.status', 'COMPLETED')->where('quality.rejected_quantity', '>', 0)
                ->first(['quality.*', 'receipt.supplier_party_id']);
            if (! $quality || ! $quality->rejected_position_id) {
                throw ValidationException::withMessages(["lines.{$index}.quality_line_id" => ['Select a completed incoming-QC line with rejected stock.']]);
            }
            $this->visibility->assertOperationallyVisible(
                $scope['permissions'] ?? [TransactionVisibilityPolicy::SHOW_PERMISSION],
                'INCOMING_QUALITY_TASK',
                (string) $quality->quality_task_id,
                $scope,
            );
            if ($supplierId !== null && $supplierId !== (string) $quality->supplier_party_id) {
                throw ValidationException::withMessages(['lines' => ['A supplier return can contain rejected lines for one supplier only.']]);
            }
            $supplierId = (string) $quality->supplier_party_id;
            $quantity = $this->positive($line['return_quantity'], "lines.{$index}.return_quantity");
            $already = $this->postedSupplierReturnQuantity($qualityLineId, $excludeReturnId);
            if (bccomp(bcadd($already, $quantity, 6), $this->decimal($quality->rejected_quantity), 6) > 0) {
                throw ValidationException::withMessages(["lines.{$index}.return_quantity" => ['Return quantity exceeds rejected stock still returnable.']]);
            }
            $lines[] = ['source' => $quality, 'return_quantity' => $quantity, 'reason' => $this->nullable($line['reason'] ?? null)];
        }

        return [$supplierId, $lines];
    }

    private function replaceSupplierReturnLines(string $returnId, array $lines, array $scope, CarbonImmutable $now): void
    {
        foreach (array_values($lines) as $index => $line) {
            $source = $line['source'];
            DB::table('supplier_return_lines')->insert([
                'id' => (string) Str::uuid(), 'supplier_return_id' => $returnId,
                'incoming_quality_line_id' => $source->id, 'company_id' => $scope['company_id'],
                'plant_id' => $scope['plant_id'], 'line_number' => $index + 1,
                'item_id' => $source->item_id, 'lot_id' => $source->lot_id, 'uom_code' => $source->uom_code,
                'return_quantity' => $line['return_quantity'], 'rejected_position_id' => $source->rejected_position_id,
                'movement_id' => null, 'reason' => $line['reason'], 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function issuedOrder(string $id, array $scope): object
    {
        $order = DB::table('purchase_orders')->where('id', $id)
            ->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])
            ->whereNotNull('po_number')->lockForUpdate()->first();
        if (! $order || $order->status !== 'ISSUED') {
            throw ValidationException::withMessages(['purchase_order_id' => ['Select an issued purchase order from the current plant.']]);
        }

        return $order;
    }

    private function activeLocation(string $id, array $scope): object
    {
        $location = DB::table('locations')->where('id', $id)
            ->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])
            ->where('status', 'ACTIVE')->first();
        if (! $location) {
            throw ValidationException::withMessages(['location_id' => ['Select an active location from the current plant.']]);
        }

        return $location;
    }

    private function resolvePurchaseLot(object $line, object $receipt, array $data): object
    {
        $lot = DB::table('lots')->where('company_id', $data['company_id'])
            ->where('internal_lot_code', $line->internal_lot_code)->lockForUpdate()->first();
        if ($lot) {
            $same = (string) $lot->item_id === (string) $line->item_id
                && (string) ($lot->supplier_party_id ?? '') === (string) $receipt->supplier_party_id
                && (string) ($lot->supplier_lot_code ?? '') === (string) ($line->supplier_lot_code ?? '')
                && (string) ($lot->manufacture_date ?? '') === (string) ($line->manufacture_date ?? '')
                && (string) ($lot->expiry_date ?? '') === (string) ($line->expiry_date ?? '')
                && $lot->status === 'ACTIVE';
            if (! $same) {
                throw ValidationException::withMessages(['internal_lot_code' => ["Lot {$line->internal_lot_code} already exists with different traceability data."]]);
            }

            return $lot;
        }
        $id = (string) Str::uuid();
        DB::table('lots')->insert([
            'id' => $id, 'company_id' => $data['company_id'], 'item_id' => $line->item_id,
            'supplier_party_id' => $receipt->supplier_party_id, 'internal_lot_code' => $line->internal_lot_code,
            'supplier_lot_code' => $line->supplier_lot_code, 'origin_type' => 'PURCHASE',
            'manufacture_date' => $line->manufacture_date, 'expiry_date' => $line->expiry_date,
            'status' => 'ACTIVE', 'notes' => 'Created by GRN '.$receipt->receipt_number.'.',
            'record_version' => 1, 'status_reason' => 'Activated by posted goods receipt.',
            'status_changed_at' => now(), 'status_changed_by' => $data['actor_id'],
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('lots')->where('id', $id)->firstOrFail();
    }

    private function resolvePosition(array $scope, string $itemId, string $lotId, string $ownerId, string $locationId, string $quality, string $uom): object
    {
        $position = DB::table('stock_positions')->where([
            'company_id' => $scope['company_id'], 'plant_id' => $scope['plant_id'],
            'item_id' => $itemId, 'lot_id' => $lotId, 'inventory_owner_id' => $ownerId,
            'location_id' => $locationId, 'quality_status' => $quality, 'uom_code' => $uom,
        ])->lockForUpdate()->first();
        if ($position) {
            return $position;
        }
        $id = (string) Str::uuid();
        DB::table('stock_positions')->insert([
            'id' => $id, 'company_id' => $scope['company_id'], 'plant_id' => $scope['plant_id'],
            'item_id' => $itemId, 'lot_id' => $lotId, 'owner_party_id' => $ownerId === $scope['company_id'] ? null : $ownerId,
            'inventory_owner_id' => $ownerId, 'location_id' => $locationId, 'quality_status' => $quality,
            'quantity_base' => 0, 'reserved_quantity_base' => 0, 'uom_code' => $uom,
            'record_version' => 1, 'status_reason' => null, 'status_changed_at' => null,
            'status_changed_by' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('stock_positions')->where('id', $id)->firstOrFail();
    }

    private function postedReceiptQuantity(string $poLineId, ?string $excludeReceiptId = null): string
    {
        $query = DB::table('receipt_lines as line')->join('receipts as receipt', 'receipt.id', '=', 'line.receipt_id')
            ->where('line.purchase_order_line_id', $poLineId)->whereIn('receipt.status', ['QC_PENDING', 'COMPLETED']);
        if ($excludeReceiptId) {
            $query->where('receipt.id', '<>', $excludeReceiptId);
        }

        return $this->decimal($query->sum('line.received_quantity'));
    }

    private function postedSupplierReturnQuantity(string $qualityLineId, ?string $excludeReturnId = null): string
    {
        $query = DB::table('supplier_return_lines as line')
            ->join('supplier_returns as supplier_return', 'supplier_return.id', '=', 'line.supplier_return_id')
            ->where('line.incoming_quality_line_id', $qualityLineId)->where('supplier_return.status', 'POSTED');
        if ($excludeReturnId) {
            $query->where('supplier_return.id', '<>', $excludeReturnId);
        }

        return $this->decimal($query->sum('line.return_quantity'));
    }

    private function uniqueNumber(string $table, string $column, string $number, array $scope): void
    {
        if (DB::table($table)->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])
            ->where($column, $number)->exists()) {
            throw ValidationException::withMessages([$column => ['That document number already exists in the selected plant.']]);
        }
    }

    private function findLocked(string $table, string $id, array $scope, string $label, ?string $liveColumn = null): object
    {
        $query = DB::table($table)->where('id', $id)->where('company_id', $scope['company_id'])
            ->where('plant_id', $scope['plant_id']);
        if ($liveColumn) {
            $query->whereNotNull($liveColumn);
        }
        $row = $query->lockForUpdate()->first();
        if (! $row) {
            throw new NotFoundHttpException($label.' not found.');
        }

        return $row;
    }

    private function assertVersion(object $record, int $expected): void
    {
        if ((int) $record->record_version !== $expected) {
            throw new ConflictHttpException('The record changed after it was loaded. Refresh and retry.');
        }
    }

    private function assertStatus(object $record, array $statuses, string $message): void
    {
        if (! in_array($record->status, $statuses, true)) {
            throw ValidationException::withMessages(['status' => [$message]]);
        }
    }

    private function positive(mixed $value, string $field): string
    {
        $decimal = $this->decimal($value);
        if (! preg_match('/^\d+(?:\.\d{1,6})?$/', (string) $value) || bccomp($decimal, '0', 6) <= 0) {
            throw ValidationException::withMessages([$field => ['Enter a positive quantity with at most six decimal places.']]);
        }

        return $decimal;
    }

    private function nonNegative(mixed $value, string $field): string
    {
        if (! preg_match('/^\d+(?:\.\d{1,6})?$/', (string) $value)) {
            throw ValidationException::withMessages([$field => ['Enter a non-negative quantity with at most six decimal places.']]);
        }

        return $this->decimal($value);
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 6);
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function result(string $id, string $status, int $version, array $extra = []): array
    {
        return ['id' => $id, 'status' => $status, 'record_version' => $version] + $extra;
    }

    private function begin(string $namespace, array $data): ?array
    {
        return $this->idempotency->begin(
            $namespace,
            $data['idempotency_key'],
            Arr::except($data, ['permissions', 'correlation_id']),
        );
    }

    private function record(string $command, string $event, string $entityType, string $id, array $data, int $version, array $diff, array $result): void
    {
        $this->audit->record($command, $entityType, $id, $data['actor_id'], $data['company_id'], $data['plant_id'], 'SUCCESS', [
            'entity_version' => $version, 'correlation_id' => $data['correlation_id'], 'safe_diff' => $diff,
        ]);
        $this->outbox->append($event, $entityType, $id, $data['idempotency_key'], $result, $data['correlation_id'], $data['company_id'], $data['plant_id']);
    }
}

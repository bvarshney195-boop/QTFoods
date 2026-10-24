<?php

namespace App\Modules\Procurement\Application;

use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PurchaseOrderService
{
    public const STATUSES = ['DRAFT', 'ISSUED', 'CANCELLED'];

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'procurement.purchase-order.create';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }
            if (DB::table('purchase_orders')->whereNotNull('po_number')
                ->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])
                ->where('po_number', $data['po_number'])->exists()) {
                throw ValidationException::withMessages([
                    'po_number' => ['That purchase-order number already exists in the selected plant.'],
                ]);
            }

            $rfq = DB::table('requests_for_quotation')->where('id', $data['rfq_id'])
                ->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])->lockForUpdate()->first();
            if (! $rfq || $rfq->status !== 'AWARDED' || ! $rfq->awarded_quote_id) {
                throw ValidationException::withMessages([
                    'rfq_id' => ['Select an awarded RFQ from the current plant.'],
                ]);
            }
            if (DB::table('purchase_orders')->where('rfq_id', $rfq->id)
                ->whereNotNull('po_number')->where('status', '<>', 'CANCELLED')->exists()) {
                throw ValidationException::withMessages([
                    'rfq_id' => ['That RFQ already has an active purchase order.'],
                ]);
            }
            $quote = DB::table('supplier_quotes')->where('id', $rfq->awarded_quote_id)
                ->where('rfq_id', $rfq->id)->lockForUpdate()->first();
            if (! $quote || (string) $quote->supplier_party_id !== (string) $rfq->awarded_supplier_id) {
                throw new ConflictHttpException('The awarded supplier quote no longer matches the RFQ.');
            }
            if (bccomp($this->decimal($quote->total_amount), $this->decimal($rfq->estimated_total_snapshot), 6) > 0) {
                throw new ConflictHttpException('The awarded value exceeds the approved requisition ceiling.');
            }

            $orderDate = CarbonImmutable::parse($data['order_date'])->startOfDay();
            $requiredBy = CarbonImmutable::parse((string) $quote->promised_delivery_date)->startOfDay();
            if ($requiredBy->isBefore($orderDate)) {
                throw ValidationException::withMessages([
                    'order_date' => ['Order date cannot be after the promised delivery date.'],
                ]);
            }
            $sourceLines = DB::table('supplier_quote_lines as quote_line')
                ->join('rfq_lines as rfq_line', 'rfq_line.id', '=', 'quote_line.rfq_line_id')
                ->where('quote_line.supplier_quote_id', $quote->id)
                ->orderBy('quote_line.line_number')
                ->get([
                    'quote_line.id as supplier_quote_line_id', 'quote_line.rfq_line_id',
                    'quote_line.line_number', 'quote_line.quantity', 'quote_line.uom_code',
                    'quote_line.unit_price', 'quote_line.line_total', 'quote_line.notes',
                    'rfq_line.requisition_line_id', 'rfq_line.item_id', 'rfq_line.description',
                ]);
            if ($sourceLines->isEmpty()) {
                throw new ConflictHttpException('The awarded quote has no lines to order.');
            }

            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('purchase_orders')->insert([
                'id' => $id,
                'company_id' => $data['company_id'],
                'plant_id' => $data['plant_id'],
                'po_number' => $data['po_number'],
                'rfq_id' => $rfq->id,
                'requisition_id' => $rfq->requisition_id,
                'supplier_party_id' => $quote->supplier_party_id,
                'supplier_quote_id' => $quote->id,
                'order_date' => $orderDate->toDateString(),
                'required_by_date' => $requiredBy->toDateString(),
                'currency' => 'INR',
                'subtotal' => $this->decimal($quote->subtotal),
                'freight_amount' => $this->decimal($quote->freight_amount),
                'other_charges' => $this->decimal($quote->other_charges),
                'discount_amount' => $this->decimal($quote->discount_amount),
                'total_amount' => $this->decimal($quote->total_amount),
                'payment_terms_days' => (int) $quote->payment_terms_days,
                'incoterm_code' => $this->nullableUpper($data['incoterm_code'] ?? null),
                'delivery_terms' => $this->nullable($data['delivery_terms'] ?? null),
                'notes' => $this->nullable($data['notes'] ?? null),
                'status' => 'DRAFT',
                'record_version' => 1,
                'revision_number' => 1,
                'created_by' => $data['actor_id'],
                'issued_at' => null,
                'issued_by' => null,
                'cancelled_at' => null,
                'cancelled_by' => null,
                'cancellation_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($sourceLines as $line) {
                $this->insertLine($id, $rfq, $quote, $line, [
                    'ordered_quantity' => $this->decimal($line->quantity),
                    'unit_price' => $this->decimal($line->unit_price),
                    'line_total' => $this->decimal($line->line_total),
                    'notes' => $line->notes,
                ], $data, $now);
            }
            $this->recordRevision($id, 1, 'Initial order generated from awarded RFQ.', $data['actor_id'], $now);

            $result = $this->result(
                $id,
                'DRAFT',
                1,
                1,
                $sourceLines->count(),
                $this->decimal($quote->total_amount),
            );
            $this->record(
                'CREATE_PURCHASE_ORDER',
                'procurement.purchase-order.created',
                $id,
                $data,
                1,
                ['created' => [
                    'po_number' => $data['po_number'],
                    'rfq_id' => (string) $rfq->id,
                    'supplier_party_id' => (string) $quote->supplier_party_id,
                    'total_amount' => $this->decimal($quote->total_amount),
                ]],
                $result,
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function amend(string $purchaseOrderId, array $data): array
    {
        return DB::transaction(function () use ($purchaseOrderId, $data): array {
            $namespace = 'procurement.purchase-order.amend.'.$purchaseOrderId;
            $payload = $data + ['purchase_order_id' => $purchaseOrderId];
            if ($replay = $this->begin($namespace, $payload)) {
                return $replay;
            }

            $order = $this->findLocked($purchaseOrderId, $data);
            $this->assertVersion($order, $data['expected_version']);
            if (! in_array($order->status, ['DRAFT', 'ISSUED'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only a draft or issued purchase order can be amended.'],
                ]);
            }
            $requiredBy = CarbonImmutable::parse($data['required_by_date'])->startOfDay();
            if ($requiredBy->isBefore(CarbonImmutable::parse((string) $order->order_date))) {
                throw ValidationException::withMessages([
                    'required_by_date' => ['Required-by date cannot be before the order date.'],
                ]);
            }
            [$lines, $subtotal] = $this->prepareAmendmentLines($order, $data['lines']);
            $freight = $this->nonNegative($data['freight_amount'], 'freight_amount', 'Freight amount');
            $other = $this->nonNegative($data['other_charges'], 'other_charges', 'Other charges');
            $discount = $this->nonNegative($data['discount_amount'], 'discount_amount', 'Discount amount');
            $beforeDiscount = bcadd(bcadd($subtotal, $freight, 6), $other, 6);
            if (bccomp($discount, $beforeDiscount, 6) > 0) {
                throw ValidationException::withMessages([
                    'discount_amount' => ['Discount cannot exceed the order subtotal and charges.'],
                ]);
            }
            $total = bcsub($beforeDiscount, $discount, 6);
            $authorityCeiling = $this->decimal(DB::table('requests_for_quotation')
                ->where('id', $order->rfq_id)->value('estimated_total_snapshot'));
            if (bccomp($total, $authorityCeiling, 6) > 0) {
                throw ValidationException::withMessages([
                    'lines' => ['The amended order exceeds the approved requisition value. Revise and reapprove the requirement first.'],
                ]);
            }

            $version = (int) $order->record_version + 1;
            $revision = (int) $order->revision_number + 1;
            $now = CarbonImmutable::now();
            DB::table('purchase_orders')->where('id', $purchaseOrderId)->update([
                'required_by_date' => $requiredBy->toDateString(),
                'subtotal' => $subtotal,
                'freight_amount' => $freight,
                'other_charges' => $other,
                'discount_amount' => $discount,
                'total_amount' => $total,
                'payment_terms_days' => $data['payment_terms_days'],
                'incoterm_code' => $this->nullableUpper($data['incoterm_code'] ?? null),
                'delivery_terms' => $this->nullable($data['delivery_terms'] ?? null),
                'notes' => $this->nullable($data['notes'] ?? null),
                'record_version' => $version,
                'revision_number' => $revision,
                'updated_at' => $now,
            ]);
            DB::table('purchase_order_lines')->where('purchase_order_id', $purchaseOrderId)->delete();
            $rfq = DB::table('requests_for_quotation')->where('id', $order->rfq_id)->firstOrFail();
            $quote = DB::table('supplier_quotes')->where('id', $order->supplier_quote_id)->firstOrFail();
            foreach ($lines as $line) {
                $this->insertLine($purchaseOrderId, $rfq, $quote, $line['source'], $line, $data, $now);
            }
            $this->recordRevision(
                $purchaseOrderId,
                $revision,
                trim($data['reason']),
                $data['actor_id'],
                $now,
            );

            $result = $this->result(
                $purchaseOrderId,
                (string) $order->status,
                $version,
                $revision,
                count($lines),
                $total,
            );
            $this->record(
                'AMEND_PURCHASE_ORDER',
                'procurement.purchase-order.amended',
                $purchaseOrderId,
                $data,
                $version,
                [
                    'revision_number' => ['from' => $order->revision_number, 'to' => $revision],
                    'required_by_date' => ['from' => $order->required_by_date, 'to' => $requiredBy->toDateString()],
                    'total_amount' => ['from' => $this->decimal($order->total_amount), 'to' => $total],
                    'reason' => trim($data['reason']),
                ],
                $result,
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function issue(string $purchaseOrderId, array $data): array
    {
        return DB::transaction(function () use ($purchaseOrderId, $data): array {
            $namespace = 'procurement.purchase-order.issue.'.$purchaseOrderId;
            $payload = $data + ['purchase_order_id' => $purchaseOrderId];
            if ($replay = $this->begin($namespace, $payload)) {
                return $replay;
            }

            $order = $this->findLocked($purchaseOrderId, $data);
            $this->assertVersion($order, $data['expected_version']);
            if ($order->status !== 'DRAFT') {
                throw ValidationException::withMessages([
                    'status' => ['Only a draft purchase order can be issued.'],
                ]);
            }
            if (CarbonImmutable::parse((string) $order->required_by_date)->isBefore(CarbonImmutable::today())) {
                throw ValidationException::withMessages([
                    'required_by_date' => ['Amend the required-by date before issuing this purchase order.'],
                ]);
            }
            $lineCount = DB::table('purchase_order_lines')->where('purchase_order_id', $purchaseOrderId)->count();
            if ($lineCount === 0) {
                throw new ConflictHttpException('The purchase order has no lines to issue.');
            }

            $version = (int) $order->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table('purchase_orders')->where('id', $purchaseOrderId)->update([
                'status' => 'ISSUED',
                'record_version' => $version,
                'issued_at' => $now,
                'issued_by' => $data['actor_id'],
                'updated_at' => $now,
            ]);
            $result = $this->result(
                $purchaseOrderId,
                'ISSUED',
                $version,
                (int) $order->revision_number,
                $lineCount,
                $this->decimal($order->total_amount),
            );
            $this->record(
                'ISSUE_PURCHASE_ORDER',
                'procurement.purchase-order.issued',
                $purchaseOrderId,
                $data,
                $version,
                ['status' => ['from' => 'DRAFT', 'to' => 'ISSUED']],
                $result,
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function cancel(string $purchaseOrderId, string $reason, array $data): array
    {
        return DB::transaction(function () use ($purchaseOrderId, $reason, $data): array {
            $namespace = 'procurement.purchase-order.cancel.'.$purchaseOrderId;
            $payload = $data + ['purchase_order_id' => $purchaseOrderId, 'reason' => $reason];
            if ($replay = $this->begin($namespace, $payload)) {
                return $replay;
            }

            $order = $this->findLocked($purchaseOrderId, $data);
            $this->assertVersion($order, $data['expected_version']);
            if (! in_array($order->status, ['DRAFT', 'ISSUED'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only a draft or issued purchase order can be cancelled.'],
                ]);
            }
            $version = (int) $order->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table('purchase_orders')->where('id', $purchaseOrderId)->update([
                'status' => 'CANCELLED',
                'record_version' => $version,
                'cancelled_at' => $now,
                'cancelled_by' => $data['actor_id'],
                'cancellation_reason' => trim($reason),
                'updated_at' => $now,
            ]);

            $result = $this->result(
                $purchaseOrderId,
                'CANCELLED',
                $version,
                (int) $order->revision_number,
                DB::table('purchase_order_lines')->where('purchase_order_id', $purchaseOrderId)->count(),
                $this->decimal($order->total_amount),
            );
            $this->record(
                'CANCEL_PURCHASE_ORDER',
                'procurement.purchase-order.cancelled',
                $purchaseOrderId,
                $data,
                $version,
                ['status' => ['from' => $order->status, 'to' => 'CANCELLED'], 'reason' => $reason],
                $result,
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    private function prepareAmendmentLines(object $order, array $input): array
    {
        $sources = DB::table('purchase_order_lines as po_line')
            ->join('rfq_lines as rfq_line', 'rfq_line.id', '=', 'po_line.rfq_line_id')
            ->where('po_line.purchase_order_id', $order->id)
            ->orderBy('po_line.line_number')
            ->get([
                'po_line.supplier_quote_line_id', 'po_line.rfq_line_id', 'po_line.requisition_line_id',
                'po_line.line_number', 'po_line.item_id', 'po_line.description', 'po_line.uom_code',
                'rfq_line.quantity as maximum_quantity',
            ])->keyBy('rfq_line_id');
        if (count($input) !== $sources->count()) {
            throw ValidationException::withMessages(['lines' => ['Amend every purchase-order line exactly once.']]);
        }
        $seen = [];
        $lines = [];
        $subtotal = '0.000000';
        foreach (array_values($input) as $index => $line) {
            $rfqLineId = (string) ($line['rfq_line_id'] ?? '');
            $source = $sources->get($rfqLineId);
            if (! $source || isset($seen[$rfqLineId])) {
                throw ValidationException::withMessages([
                    "lines.{$index}.rfq_line_id" => ['Select each sourced order line exactly once.'],
                ]);
            }
            $seen[$rfqLineId] = true;
            $quantity = $this->positive(
                $line['ordered_quantity'] ?? null,
                "lines.{$index}.ordered_quantity",
                'Ordered quantity',
            );
            if (bccomp($quantity, $this->decimal($source->maximum_quantity), 6) > 0) {
                throw ValidationException::withMessages([
                    "lines.{$index}.ordered_quantity" => ['Ordered quantity cannot exceed the sourced requisition quantity.'],
                ]);
            }
            $unitPrice = $this->nonNegative(
                $line['unit_price'] ?? null,
                "lines.{$index}.unit_price",
                'Unit price',
            );
            $lineTotal = bcround(bcmul($quantity, $unitPrice, 12), 6);
            $subtotal = bcadd($subtotal, $lineTotal, 6);
            $lines[] = [
                'source' => $source,
                'ordered_quantity' => $quantity,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'notes' => $this->nullable($line['notes'] ?? null),
            ];
        }

        return [$lines, $subtotal];
    }

    private function insertLine(
        string $purchaseOrderId,
        object $rfq,
        object $quote,
        object $source,
        array $line,
        array $scope,
        CarbonImmutable $now,
    ): void {
        DB::table('purchase_order_lines')->insert([
            'id' => (string) Str::uuid(),
            'purchase_order_id' => $purchaseOrderId,
            'supplier_quote_id' => $quote->id,
            'supplier_quote_line_id' => $source->supplier_quote_line_id,
            'rfq_id' => $rfq->id,
            'rfq_line_id' => $source->rfq_line_id,
            'requisition_id' => $rfq->requisition_id,
            'requisition_line_id' => $source->requisition_line_id,
            'company_id' => $scope['company_id'],
            'plant_id' => $scope['plant_id'],
            'line_number' => $source->line_number,
            'item_id' => $source->item_id,
            'description' => $source->description,
            'ordered_quantity' => $line['ordered_quantity'],
            'uom_code' => $source->uom_code,
            'unit_price' => $line['unit_price'],
            'line_total' => $line['line_total'],
            'notes' => $line['notes'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function recordRevision(
        string $purchaseOrderId,
        int $revision,
        string $reason,
        string $actorId,
        CarbonImmutable $now,
    ): void {
        $order = DB::table('purchase_orders')->where('id', $purchaseOrderId)->firstOrFail();
        $lines = DB::table('purchase_order_lines')->where('purchase_order_id', $purchaseOrderId)
            ->orderBy('line_number')->get()->map(fn (object $line): array => [
                'line_number' => (int) $line->line_number,
                'rfq_line_id' => (string) $line->rfq_line_id,
                'item_id' => (string) $line->item_id,
                'description' => $line->description,
                'ordered_quantity' => $this->decimal($line->ordered_quantity),
                'uom_code' => $line->uom_code,
                'unit_price' => $this->decimal($line->unit_price),
                'line_total' => $this->decimal($line->line_total),
                'notes' => $line->notes,
            ])->all();
        $snapshot = [
            'po_number' => $order->po_number,
            'status' => $order->status,
            'order_date' => (string) $order->order_date,
            'required_by_date' => (string) $order->required_by_date,
            'currency' => $order->currency,
            'subtotal' => $this->decimal($order->subtotal),
            'freight_amount' => $this->decimal($order->freight_amount),
            'other_charges' => $this->decimal($order->other_charges),
            'discount_amount' => $this->decimal($order->discount_amount),
            'total_amount' => $this->decimal($order->total_amount),
            'payment_terms_days' => (int) $order->payment_terms_days,
            'incoterm_code' => $order->incoterm_code,
            'delivery_terms' => $order->delivery_terms,
            'notes' => $order->notes,
            'lines' => $lines,
        ];
        DB::table('purchase_order_revisions')->insert([
            'id' => (string) Str::uuid(),
            'purchase_order_id' => $purchaseOrderId,
            'company_id' => $order->company_id,
            'plant_id' => $order->plant_id,
            'revision_number' => $revision,
            'reason' => $reason,
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'created_by' => $actorId,
            'created_at' => $now,
        ]);
    }

    private function findLocked(string $purchaseOrderId, array $scope): object
    {
        $order = DB::table('purchase_orders')->where('id', $purchaseOrderId)
            ->whereNotNull('po_number')
            ->where('company_id', $scope['company_id'])
            ->where('plant_id', $scope['plant_id'])->lockForUpdate()->first();
        if (! $order) {
            throw new NotFoundHttpException('Purchase order not found.');
        }

        return $order;
    }

    private function assertVersion(object $order, int $expected): void
    {
        if ((int) $order->record_version !== $expected) {
            throw new ConflictHttpException(
                "The purchase order changed from version {$expected} to {$order->record_version}. Refresh it before continuing."
            );
        }
    }

    private function result(
        string $id,
        string $status,
        int $version,
        int $revision,
        int $lineCount,
        string $total,
    ): array {
        return [
            'entity_type' => 'purchase_order',
            'id' => $id,
            'status' => $status,
            'record_version' => $version,
            'revision_number' => $revision,
            'line_count' => $lineCount,
            'total_amount' => $total,
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
        $this->audit->record(
            $command,
            'purchase_order',
            $id,
            $data['actor_id'],
            $data['company_id'],
            $data['plant_id'],
            'SUCCESS',
            [
                'entity_version' => $version,
                'correlation_id' => $data['correlation_id'] ?? null,
                'reason_code' => $command === 'CANCEL_PURCHASE_ORDER' ? 'CANCELLED' : null,
                'safe_diff' => $safeDiff,
            ],
        );
        $this->outbox->append(
            $event,
            'purchase_order',
            $id,
            $id.':'.$version,
            $result + ['company_id' => $data['company_id'], 'plant_id' => $data['plant_id']],
            $data['correlation_id'] ?? null,
            $data['company_id'],
            $data['plant_id'],
        );
    }

    private function begin(string $namespace, array $data): ?array
    {
        return $this->idempotency->begin(
            $namespace,
            $data['idempotency_key'],
            Arr::except($data, ['actor_id', 'permissions', 'idempotency_key', 'correlation_id']),
        );
    }

    private function positive(mixed $value, string $field, string $label): string
    {
        $decimal = $this->validatedDecimal($value, $field, $label);
        if (bccomp($decimal, '0', 6) <= 0) {
            throw ValidationException::withMessages([$field => ["{$label} must be greater than zero."]]);
        }

        return $decimal;
    }

    private function nonNegative(mixed $value, string $field, string $label): string
    {
        $decimal = $this->validatedDecimal($value, $field, $label);
        if (bccomp($decimal, '0', 6) < 0) {
            throw ValidationException::withMessages([$field => ["{$label} cannot be negative."]]);
        }

        return $decimal;
    }

    private function validatedDecimal(mixed $value, string $field, string $label): string
    {
        $value = (string) $value;
        if (! preg_match('/^\d{1,14}(?:\.\d{1,6})?$/', $value)) {
            throw ValidationException::withMessages([
                $field => ["{$label} must use at most 14 whole digits and 6 decimal places."],
            ]);
        }

        return $this->decimal($value);
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 6);
    }

    private function nullable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableUpper(mixed $value): ?string
    {
        $value = $this->nullable($value);

        return $value === null ? null : Str::upper($value);
    }
}

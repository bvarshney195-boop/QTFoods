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

final class RequestForQuotationService
{
    public const STATUSES = ['DRAFT', 'ISSUED', 'AWARDED', 'CANCELLED'];

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'procurement.rfq.create';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }

            if (DB::table('requests_for_quotation')
                ->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])
                ->where('rfq_number', $data['rfq_number'])->exists()) {
                throw ValidationException::withMessages([
                    'rfq_number' => ['That RFQ number already exists in the selected plant.'],
                ]);
            }

            $requisition = DB::table('requisitions')
                ->where('id', $data['requisition_id'])
                ->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])
                ->lockForUpdate()->first();
            if (! $requisition || $requisition->status !== 'APPROVED') {
                throw ValidationException::withMessages([
                    'requisition_id' => ['Select an approved purchase requisition from the current plant.'],
                ]);
            }
            if (DB::table('requests_for_quotation')->where('requisition_id', $requisition->id)
                ->where('status', '<>', 'CANCELLED')->exists()) {
                throw ValidationException::withMessages([
                    'requisition_id' => ['That requisition already has an active sourcing event.'],
                ]);
            }

            $responseDue = CarbonImmutable::parse($data['response_due_date'])->startOfDay();
            if ($responseDue->isBefore(CarbonImmutable::today())) {
                throw ValidationException::withMessages([
                    'response_due_date' => ['The response due date cannot be in the past.'],
                ]);
            }
            if ($responseDue->isAfter(CarbonImmutable::parse((string) $requisition->required_by_date))) {
                throw ValidationException::withMessages([
                    'response_due_date' => ['The response due date must be on or before the requisition required-by date.'],
                ]);
            }

            $suppliers = $this->suppliers($data['supplier_ids'], $data);
            $sourceLines = DB::table('requisition_lines')
                ->where('requisition_id', $requisition->id)->orderBy('line_number')->get();
            if ($sourceLines->isEmpty()) {
                throw new ConflictHttpException('The approved requisition has no lines to source.');
            }

            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('requests_for_quotation')->insert([
                'id' => $id,
                'company_id' => $data['company_id'],
                'plant_id' => $data['plant_id'],
                'rfq_number' => $data['rfq_number'],
                'requisition_id' => $requisition->id,
                'status' => 'DRAFT',
                'currency' => $requisition->currency,
                'estimated_total_snapshot' => $this->decimal($requisition->estimated_total),
                'response_due_date' => $responseDue->toDateString(),
                'required_by_date' => $requisition->required_by_date,
                'commercial_terms' => $this->nullable($data['commercial_terms'] ?? null),
                'record_version' => 1,
                'created_by' => $data['actor_id'],
                'issued_at' => null,
                'issued_by' => null,
                'awarded_at' => null,
                'awarded_by' => null,
                'awarded_supplier_id' => null,
                'awarded_quote_id' => null,
                'award_reason' => null,
                'cancelled_at' => null,
                'cancelled_by' => null,
                'cancellation_reason' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            foreach ($sourceLines as $line) {
                DB::table('rfq_lines')->insert([
                    'id' => (string) Str::uuid(),
                    'rfq_id' => $id,
                    'requisition_id' => $requisition->id,
                    'requisition_line_id' => $line->id,
                    'company_id' => $data['company_id'],
                    'plant_id' => $data['plant_id'],
                    'line_number' => $line->line_number,
                    'item_id' => $line->item_id,
                    'description' => $line->description,
                    'quantity' => $this->decimal($line->quantity),
                    'uom_code' => $line->uom_code,
                    'notes' => $line->notes,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            $this->replaceSuppliers($id, $suppliers, $data, $now);

            $result = $this->result(
                $id,
                'DRAFT',
                1,
                $sourceLines->count(),
                count($suppliers),
                0,
            );
            $this->record(
                'CREATE_REQUEST_FOR_QUOTATION',
                'procurement.rfq.created',
                $id,
                $data,
                1,
                ['created' => [
                    'rfq_number' => $data['rfq_number'],
                    'requisition_id' => (string) $requisition->id,
                    'supplier_count' => count($suppliers),
                    'line_count' => $sourceLines->count(),
                ]],
                $result,
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function update(string $rfqId, array $data): array
    {
        return DB::transaction(function () use ($rfqId, $data): array {
            $namespace = 'procurement.rfq.update.'.$rfqId;
            $payload = $data + ['rfq_id' => $rfqId];
            if ($replay = $this->begin($namespace, $payload)) {
                return $replay;
            }

            $rfq = $this->findLocked($rfqId, $data);
            $this->assertVersion($rfq, $data['expected_version']);
            if ($rfq->status !== 'DRAFT') {
                throw ValidationException::withMessages([
                    'status' => ['Only a draft RFQ can be edited.'],
                ]);
            }
            $responseDue = CarbonImmutable::parse($data['response_due_date'])->startOfDay();
            if ($responseDue->isBefore(CarbonImmutable::today())) {
                throw ValidationException::withMessages([
                    'response_due_date' => ['The response due date cannot be in the past.'],
                ]);
            }
            if ($responseDue->isAfter(CarbonImmutable::parse((string) $rfq->required_by_date))) {
                throw ValidationException::withMessages([
                    'response_due_date' => ['The response due date must be on or before the requisition required-by date.'],
                ]);
            }
            $suppliers = $this->suppliers($data['supplier_ids'], $data);
            $version = (int) $rfq->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table('requests_for_quotation')->where('id', $rfqId)->update([
                'response_due_date' => $responseDue->toDateString(),
                'commercial_terms' => $this->nullable($data['commercial_terms'] ?? null),
                'record_version' => $version,
                'updated_at' => $now,
            ]);
            DB::table('rfq_suppliers')->where('rfq_id', $rfqId)->delete();
            $this->replaceSuppliers($rfqId, $suppliers, $data, $now);

            $result = $this->result(
                $rfqId,
                'DRAFT',
                $version,
                DB::table('rfq_lines')->where('rfq_id', $rfqId)->count(),
                count($suppliers),
                0,
            );
            $this->record(
                'UPDATE_REQUEST_FOR_QUOTATION',
                'procurement.rfq.updated',
                $rfqId,
                $data,
                $version,
                [
                    'response_due_date' => ['from' => $rfq->response_due_date, 'to' => $responseDue->toDateString()],
                    'supplier_count' => count($suppliers),
                ],
                $result,
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function issue(string $rfqId, array $data): array
    {
        return DB::transaction(function () use ($rfqId, $data): array {
            $namespace = 'procurement.rfq.issue.'.$rfqId;
            $payload = $data + ['rfq_id' => $rfqId];
            if ($replay = $this->begin($namespace, $payload)) {
                return $replay;
            }

            $rfq = $this->findLocked($rfqId, $data);
            $this->assertVersion($rfq, $data['expected_version']);
            if ($rfq->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => ['Only a draft RFQ can be issued.']]);
            }
            if (CarbonImmutable::parse((string) $rfq->response_due_date)->isBefore(CarbonImmutable::today())) {
                throw ValidationException::withMessages([
                    'response_due_date' => ['Move the response due date forward before issuing this RFQ.'],
                ]);
            }
            $supplierCount = DB::table('rfq_suppliers')->where('rfq_id', $rfqId)->count();
            if ($supplierCount < 2) {
                throw new ConflictHttpException('At least two eligible suppliers are required before issue.');
            }

            $version = (int) $rfq->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table('requests_for_quotation')->where('id', $rfqId)->update([
                'status' => 'ISSUED',
                'record_version' => $version,
                'issued_at' => $now,
                'issued_by' => $data['actor_id'],
                'updated_at' => $now,
            ]);
            DB::table('rfq_suppliers')->where('rfq_id', $rfqId)->update([
                'status' => 'INVITED',
                'invited_at' => $now,
                'updated_at' => $now,
            ]);

            $result = $this->result(
                $rfqId,
                'ISSUED',
                $version,
                DB::table('rfq_lines')->where('rfq_id', $rfqId)->count(),
                $supplierCount,
                0,
            );
            $this->record(
                'ISSUE_REQUEST_FOR_QUOTATION',
                'procurement.rfq.issued',
                $rfqId,
                $data,
                $version,
                ['status' => ['from' => 'DRAFT', 'to' => 'ISSUED'], 'supplier_count' => $supplierCount],
                $result,
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function recordQuote(string $rfqId, array $data): array
    {
        return DB::transaction(function () use ($rfqId, $data): array {
            $namespace = 'procurement.rfq.quote.'.$rfqId.'.'.$data['supplier_party_id'];
            $payload = $data + ['rfq_id' => $rfqId];
            if ($replay = $this->begin($namespace, $payload)) {
                return $replay;
            }

            $rfq = $this->findLocked($rfqId, $data);
            $this->assertVersion($rfq, $data['expected_version']);
            if ($rfq->status !== 'ISSUED') {
                throw ValidationException::withMessages([
                    'status' => ['Supplier quotes can be recorded only while the RFQ is issued.'],
                ]);
            }
            $invitation = DB::table('rfq_suppliers')->where('rfq_id', $rfqId)
                ->where('supplier_party_id', $data['supplier_party_id'])->lockForUpdate()->first();
            if (! $invitation) {
                throw ValidationException::withMessages([
                    'supplier_party_id' => ['Select a supplier invited to this RFQ.'],
                ]);
            }

            $quoteDate = CarbonImmutable::parse($data['quote_date'])->startOfDay();
            $validUntil = CarbonImmutable::parse($data['valid_until'])->startOfDay();
            if ($validUntil->isBefore($quoteDate)) {
                throw ValidationException::withMessages([
                    'valid_until' => ['Quote validity cannot end before the quote date.'],
                ]);
            }
            [$lines, $subtotal] = $this->prepareQuoteLines($rfqId, $data['lines']);
            $freight = $this->nonNegative($data['freight_amount'], 'freight_amount', 'Freight amount');
            $other = $this->nonNegative($data['other_charges'], 'other_charges', 'Other charges');
            $discount = $this->nonNegative($data['discount_amount'], 'discount_amount', 'Discount amount');
            $beforeDiscount = bcadd(bcadd($subtotal, $freight, 6), $other, 6);
            if (bccomp($discount, $beforeDiscount, 6) > 0) {
                throw ValidationException::withMessages([
                    'discount_amount' => ['Discount cannot exceed the quoted subtotal and charges.'],
                ]);
            }
            $total = bcsub($beforeDiscount, $discount, 6);
            $existing = DB::table('supplier_quotes')->where('rfq_id', $rfqId)
                ->where('supplier_party_id', $data['supplier_party_id'])->lockForUpdate()->first();
            $quoteId = $existing ? (string) $existing->id : (string) Str::uuid();
            $quoteVersion = $existing ? (int) $existing->record_version + 1 : 1;
            $now = CarbonImmutable::now();
            $values = [
                'rfq_id' => $rfqId,
                'company_id' => $data['company_id'],
                'plant_id' => $data['plant_id'],
                'supplier_party_id' => $data['supplier_party_id'],
                'quote_number' => trim($data['quote_number']),
                'quote_date' => $quoteDate->toDateString(),
                'valid_until' => $validUntil->toDateString(),
                'promised_delivery_date' => $data['promised_delivery_date'],
                'payment_terms_days' => $data['payment_terms_days'],
                'currency' => 'INR',
                'subtotal' => $subtotal,
                'freight_amount' => $freight,
                'other_charges' => $other,
                'discount_amount' => $discount,
                'total_amount' => $total,
                'status' => 'SUBMITTED',
                'notes' => $this->nullable($data['notes'] ?? null),
                'record_version' => $quoteVersion,
                'submitted_by' => $data['actor_id'],
                'submitted_at' => $now,
                'updated_at' => $now,
            ];
            if ($existing) {
                DB::table('supplier_quotes')->where('id', $quoteId)->update($values);
                DB::table('supplier_quote_lines')->where('supplier_quote_id', $quoteId)->delete();
            } else {
                DB::table('supplier_quotes')->insert(['id' => $quoteId, 'created_at' => $now] + $values);
            }
            foreach ($lines as $line) {
                DB::table('supplier_quote_lines')->insert([
                    'id' => (string) Str::uuid(),
                    'supplier_quote_id' => $quoteId,
                    'rfq_id' => $rfqId,
                    'company_id' => $data['company_id'],
                    'plant_id' => $data['plant_id'],
                    ...$line,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
            DB::table('rfq_suppliers')->where('id', $invitation->id)->update([
                'status' => 'RESPONDED',
                'responded_at' => $now,
                'updated_at' => $now,
            ]);
            $rfqVersion = (int) $rfq->record_version + 1;
            DB::table('requests_for_quotation')->where('id', $rfqId)->update([
                'record_version' => $rfqVersion,
                'updated_at' => $now,
            ]);

            $quoteCount = DB::table('supplier_quotes')->where('rfq_id', $rfqId)->count();
            $result = $this->result(
                $rfqId,
                'ISSUED',
                $rfqVersion,
                count($lines),
                DB::table('rfq_suppliers')->where('rfq_id', $rfqId)->count(),
                $quoteCount,
            ) + [
                'quote_id' => $quoteId,
                'quote_record_version' => $quoteVersion,
                'quote_total' => $total,
            ];
            $this->record(
                $existing ? 'UPDATE_SUPPLIER_QUOTE' : 'RECORD_SUPPLIER_QUOTE',
                $existing ? 'procurement.rfq.quote-updated' : 'procurement.rfq.quote-recorded',
                $rfqId,
                $data,
                $rfqVersion,
                [
                    'supplier_party_id' => $data['supplier_party_id'],
                    'quote_id' => $quoteId,
                    'quote_total' => $total,
                    'quote_record_version' => $quoteVersion,
                ],
                $result,
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function award(string $rfqId, array $data): array
    {
        return DB::transaction(function () use ($rfqId, $data): array {
            $namespace = 'procurement.rfq.award.'.$rfqId;
            $payload = $data + ['rfq_id' => $rfqId];
            if ($replay = $this->begin($namespace, $payload)) {
                return $replay;
            }

            $rfq = $this->findLocked($rfqId, $data);
            $this->assertVersion($rfq, $data['expected_version']);
            if ($rfq->status !== 'ISSUED') {
                throw ValidationException::withMessages(['status' => ['Only an issued RFQ can be awarded.']]);
            }
            $quote = DB::table('supplier_quotes')->where('id', $data['supplier_quote_id'])
                ->where('rfq_id', $rfqId)->where('status', 'SUBMITTED')->lockForUpdate()->first();
            if (! $quote) {
                throw ValidationException::withMessages([
                    'supplier_quote_id' => ['Select a submitted quote from this RFQ.'],
                ]);
            }
            if (CarbonImmutable::parse((string) $quote->valid_until)->isBefore(CarbonImmutable::today())) {
                throw ValidationException::withMessages([
                    'supplier_quote_id' => ['The selected supplier quote has expired.'],
                ]);
            }
            if (bccomp($this->decimal($quote->total_amount), $this->decimal($rfq->estimated_total_snapshot), 6) > 0) {
                throw ValidationException::withMessages([
                    'supplier_quote_id' => ['The selected quote exceeds the approved requisition value. Revise and reapprove the requirement before award.'],
                ]);
            }
            $lowest = $this->decimal(DB::table('supplier_quotes')->where('rfq_id', $rfqId)->min('total_amount'));
            $nonLowest = bccomp($this->decimal($quote->total_amount), $lowest, 6) > 0;
            $late = CarbonImmutable::parse((string) $quote->promised_delivery_date)
                ->isAfter(CarbonImmutable::parse((string) $rfq->required_by_date));
            $reason = $this->nullable($data['award_reason'] ?? null);
            if (($nonLowest || $late) && ($reason === null || mb_strlen($reason) < 3)) {
                throw ValidationException::withMessages([
                    'award_reason' => ['Explain a non-lowest-price or late-delivery award.'],
                ]);
            }

            $version = (int) $rfq->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table('requests_for_quotation')->where('id', $rfqId)->update([
                'status' => 'AWARDED',
                'record_version' => $version,
                'awarded_at' => $now,
                'awarded_by' => $data['actor_id'],
                'awarded_supplier_id' => $quote->supplier_party_id,
                'awarded_quote_id' => $quote->id,
                'award_reason' => $reason,
                'updated_at' => $now,
            ]);
            DB::table('rfq_suppliers')->where('rfq_id', $rfqId)->update([
                'status' => 'NOT_SELECTED',
                'updated_at' => $now,
            ]);
            DB::table('rfq_suppliers')->where('rfq_id', $rfqId)
                ->where('supplier_party_id', $quote->supplier_party_id)->update([
                    'status' => 'AWARDED',
                    'updated_at' => $now,
                ]);

            $result = $this->result(
                $rfqId,
                'AWARDED',
                $version,
                DB::table('rfq_lines')->where('rfq_id', $rfqId)->count(),
                DB::table('rfq_suppliers')->where('rfq_id', $rfqId)->count(),
                DB::table('supplier_quotes')->where('rfq_id', $rfqId)->count(),
            ) + [
                'awarded_supplier_id' => (string) $quote->supplier_party_id,
                'awarded_quote_id' => (string) $quote->id,
                'awarded_total' => $this->decimal($quote->total_amount),
            ];
            $this->record(
                'AWARD_REQUEST_FOR_QUOTATION',
                'procurement.rfq.awarded',
                $rfqId,
                $data,
                $version,
                [
                    'status' => ['from' => 'ISSUED', 'to' => 'AWARDED'],
                    'supplier_party_id' => (string) $quote->supplier_party_id,
                    'supplier_quote_id' => (string) $quote->id,
                    'selected_total' => $this->decimal($quote->total_amount),
                    'lowest_total' => $lowest,
                    'award_reason' => $reason,
                ],
                $result,
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function cancel(string $rfqId, string $reason, array $data): array
    {
        return DB::transaction(function () use ($rfqId, $reason, $data): array {
            $namespace = 'procurement.rfq.cancel.'.$rfqId;
            $payload = $data + ['rfq_id' => $rfqId, 'reason' => $reason];
            if ($replay = $this->begin($namespace, $payload)) {
                return $replay;
            }

            $rfq = $this->findLocked($rfqId, $data);
            $this->assertVersion($rfq, $data['expected_version']);
            if (! in_array($rfq->status, ['DRAFT', 'ISSUED'], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Only a draft or issued RFQ can be cancelled.'],
                ]);
            }
            $version = (int) $rfq->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table('requests_for_quotation')->where('id', $rfqId)->update([
                'status' => 'CANCELLED',
                'record_version' => $version,
                'cancelled_at' => $now,
                'cancelled_by' => $data['actor_id'],
                'cancellation_reason' => trim($reason),
                'updated_at' => $now,
            ]);

            $result = $this->result(
                $rfqId,
                'CANCELLED',
                $version,
                DB::table('rfq_lines')->where('rfq_id', $rfqId)->count(),
                DB::table('rfq_suppliers')->where('rfq_id', $rfqId)->count(),
                DB::table('supplier_quotes')->where('rfq_id', $rfqId)->count(),
            );
            $this->record(
                'CANCEL_REQUEST_FOR_QUOTATION',
                'procurement.rfq.cancelled',
                $rfqId,
                $data,
                $version,
                ['status' => ['from' => $rfq->status, 'to' => 'CANCELLED'], 'reason' => $reason],
                $result,
            );
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    private function suppliers(array $supplierIds, array $scope): array
    {
        $ids = array_values(array_unique(array_map('strval', $supplierIds)));
        if (count($ids) < 2) {
            throw ValidationException::withMessages([
                'supplier_ids' => ['Select at least two distinct active suppliers for comparison.'],
            ]);
        }
        $found = DB::table('parties as party')
            ->join('party_roles as role', function ($join): void {
                $join->on('role.party_id', '=', 'party.id')
                    ->on('role.company_id', '=', 'party.company_id');
            })
            ->where('party.company_id', $scope['company_id'])
            ->where('party.status', 'ACTIVE')
            ->where('role.role_code', 'SUPPLIER')
            ->whereIn('party.id', $ids)
            ->get(['party.id', 'party.code', 'party.display_name'])->keyBy('id');
        foreach ($ids as $index => $id) {
            if (! $found->has($id)) {
                throw ValidationException::withMessages([
                    "supplier_ids.{$index}" => ['Select an active supplier from the current company.'],
                ]);
            }
        }

        return $ids;
    }

    private function replaceSuppliers(
        string $rfqId,
        array $supplierIds,
        array $scope,
        CarbonImmutable $now,
    ): void {
        foreach ($supplierIds as $supplierId) {
            DB::table('rfq_suppliers')->insert([
                'id' => (string) Str::uuid(),
                'rfq_id' => $rfqId,
                'company_id' => $scope['company_id'],
                'plant_id' => $scope['plant_id'],
                'supplier_party_id' => $supplierId,
                'status' => 'SELECTED',
                'invited_at' => null,
                'responded_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    private function prepareQuoteLines(string $rfqId, array $input): array
    {
        $sources = DB::table('rfq_lines')->where('rfq_id', $rfqId)
            ->orderBy('line_number')->get()->keyBy('id');
        if (count($input) !== $sources->count()) {
            throw ValidationException::withMessages([
                'lines' => ['Price every RFQ line exactly once.'],
            ]);
        }
        $seen = [];
        $lines = [];
        $subtotal = '0.000000';
        foreach (array_values($input) as $index => $line) {
            $sourceId = (string) ($line['rfq_line_id'] ?? '');
            $source = $sources->get($sourceId);
            if (! $source || isset($seen[$sourceId])) {
                throw ValidationException::withMessages([
                    "lines.{$index}.rfq_line_id" => ['Select each line from this RFQ exactly once.'],
                ]);
            }
            $seen[$sourceId] = true;
            $unitPrice = $this->nonNegative(
                $line['unit_price'] ?? null,
                "lines.{$index}.unit_price",
                'Unit price',
            );
            $quantity = $this->decimal($source->quantity);
            $lineTotal = bcround(bcmul($quantity, $unitPrice, 12), 6);
            $subtotal = bcadd($subtotal, $lineTotal, 6);
            $lines[] = [
                'rfq_line_id' => $sourceId,
                'line_number' => (int) $source->line_number,
                'quantity' => $quantity,
                'uom_code' => (string) $source->uom_code,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'notes' => $this->nullable($line['notes'] ?? null),
            ];
        }

        return [$lines, $subtotal];
    }

    private function findLocked(string $rfqId, array $scope): object
    {
        $rfq = DB::table('requests_for_quotation')->where('id', $rfqId)
            ->where('company_id', $scope['company_id'])
            ->where('plant_id', $scope['plant_id'])->lockForUpdate()->first();
        if (! $rfq) {
            throw new NotFoundHttpException('Request for quotation not found.');
        }

        return $rfq;
    }

    private function assertVersion(object $rfq, int $expected): void
    {
        if ((int) $rfq->record_version !== $expected) {
            throw new ConflictHttpException(
                "The RFQ changed from version {$expected} to {$rfq->record_version}. Refresh it before continuing."
            );
        }
    }

    private function result(
        string $id,
        string $status,
        int $version,
        int $lineCount,
        int $supplierCount,
        int $quoteCount,
    ): array {
        return [
            'entity_type' => 'request_for_quotation',
            'id' => $id,
            'status' => $status,
            'record_version' => $version,
            'line_count' => $lineCount,
            'supplier_count' => $supplierCount,
            'quote_count' => $quoteCount,
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
            'request_for_quotation',
            $id,
            $data['actor_id'],
            $data['company_id'],
            $data['plant_id'],
            'SUCCESS',
            [
                'entity_version' => $version,
                'correlation_id' => $data['correlation_id'] ?? null,
                'reason_code' => $command === 'CANCEL_REQUEST_FOR_QUOTATION' ? 'CANCELLED' : null,
                'safe_diff' => $safeDiff,
            ],
        );
        $this->outbox->append(
            $event,
            'request_for_quotation',
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
}

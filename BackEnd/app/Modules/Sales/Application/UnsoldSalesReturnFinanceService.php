<?php

namespace App\Modules\Sales\Application;

use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class UnsoldSalesReturnFinanceService
{
    private const SETTLEMENT_TYPES = [
        'RECEIVABLE_ADJUSTMENT',
        'REFUND',
        'REPLACEMENT',
    ];

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    public function linkInvoice(string $caseId, array $data): array
    {
        return DB::transaction(function () use ($caseId, $data) {
            [$namespace, $key, $existing] = $this->begin($caseId, 'INVOICE_LINK', $data);
            if ($existing) {
                return $existing;
            }

            $case = $this->lockedCase($caseId, $data);
            $this->assertFinanceOpen($case, $data['expected_version']);
            $this->assertNoAction($caseId, ['INVOICE_LINK']);

            if ($case->invoice_id && (string) $case->invoice_id !== $data['invoice_id']) {
                throw ValidationException::withMessages([
                    'invoice_id' => ['This return is already tied to a different source invoice.'],
                ]);
            }

            $invoice = $this->invoice($case, $data['invoice_id']);
            $actionId = $this->insertAction($case, 'INVOICE_LINK', [
                'invoice_id' => $invoice->invoice_id,
                'reference_number' => $invoice->invoice_number,
                'currency' => $invoice->currency,
                'notes' => $data['notes'] ?? null,
                'actor_id' => $data['actor_id'],
                'idempotency_key' => $key,
            ]);
            $version = (int) $case->record_version + 1;

            DB::table('unsold_return_cases')->where('id', $caseId)->update([
                'invoice_id' => $invoice->invoice_id,
                'record_version' => $version,
                'updated_at' => now(),
            ]);

            $result = $this->result($caseId, $actionId, 'INVOICE_LINK', $invoice, $version, [
                'reference_number' => $invoice->invoice_number,
            ]);
            $this->sideEffects($case, $actionId, 'INVOICE_LINK', $data, $result);
            $this->idempotency->complete($namespace, $key, $result);

            return $result;
        }, 3);
    }

    public function postCreditNote(string $caseId, array $data): array
    {
        return DB::transaction(function () use ($caseId, $data) {
            [$namespace, $key, $existing] = $this->begin($caseId, 'CREDIT_NOTE', $data);
            if ($existing) {
                return $existing;
            }

            $case = $this->lockedCase($caseId, $data);
            $this->assertFinanceOpen($case, $data['expected_version']);
            $this->requiredAction($caseId, 'INVOICE_LINK');
            $this->assertNoAction($caseId, ['CREDIT_NOTE']);
            $invoice = $this->invoice($case, (string) $case->invoice_id);
            $amount = $this->money($data['amount']);
            $currency = $this->currency($data['currency'], $invoice->currency);
            $documentNumber = $this->reference($data['document_number'], 'document_number');

            if (bccomp($amount, (string) $invoice->net_amount, 4) > 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Credit-note net amount cannot exceed the source invoice net amount.'],
                ]);
            }

            $this->assertReferenceAvailable($case, 'CREDIT_NOTE', $documentNumber, 'document_number');
            $actionId = $this->insertAction($case, 'CREDIT_NOTE', [
                'invoice_id' => $invoice->invoice_id,
                'reference_number' => $documentNumber,
                'amount' => $amount,
                'currency' => $currency,
                'notes' => $data['notes'] ?? null,
                'actor_id' => $data['actor_id'],
                'idempotency_key' => $key,
            ]);
            $version = $this->incrementCaseVersion($case);

            $result = $this->result($caseId, $actionId, 'CREDIT_NOTE', $invoice, $version, [
                'reference_number' => $documentNumber,
                'amount' => $amount,
            ]);
            $this->sideEffects($case, $actionId, 'CREDIT_NOTE', $data, $result);
            $this->idempotency->complete($namespace, $key, $result);

            return $result;
        }, 3);
    }

    public function postTaxAdjustment(string $caseId, array $data): array
    {
        return DB::transaction(function () use ($caseId, $data) {
            [$namespace, $key, $existing] = $this->begin($caseId, 'TAX_ADJUSTMENT', $data);
            if ($existing) {
                return $existing;
            }

            $case = $this->lockedCase($caseId, $data);
            $this->assertFinanceOpen($case, $data['expected_version']);
            $this->requiredAction($caseId, 'CREDIT_NOTE');
            $this->assertNoAction($caseId, ['TAX_ADJUSTMENT']);
            $invoice = $this->invoice($case, (string) $case->invoice_id);
            $amount = $this->money($data['amount'], true);
            $currency = $this->currency($data['currency'], $invoice->currency);
            $documentNumber = $this->reference($data['document_number'], 'document_number');
            $taxCode = strtoupper($this->reference($data['tax_code'], 'tax_code'));

            if (bccomp($amount, (string) $invoice->tax_amount, 4) > 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Tax adjustment cannot exceed the source invoice tax amount.'],
                ]);
            }

            $this->assertReferenceAvailable($case, 'TAX_ADJUSTMENT', $documentNumber, 'document_number');
            $actionId = $this->insertAction($case, 'TAX_ADJUSTMENT', [
                'invoice_id' => $invoice->invoice_id,
                'reference_number' => $documentNumber,
                'amount' => $amount,
                'currency' => $currency,
                'tax_code' => $taxCode,
                'notes' => $data['notes'] ?? null,
                'actor_id' => $data['actor_id'],
                'idempotency_key' => $key,
            ]);
            $version = $this->incrementCaseVersion($case);

            $result = $this->result($caseId, $actionId, 'TAX_ADJUSTMENT', $invoice, $version, [
                'reference_number' => $documentNumber,
                'amount' => $amount,
                'tax_code' => $taxCode,
            ]);
            $this->sideEffects($case, $actionId, 'TAX_ADJUSTMENT', $data, $result);
            $this->idempotency->complete($namespace, $key, $result);

            return $result;
        }, 3);
    }

    public function settle(string $caseId, string $actionType, array $data): array
    {
        if (! in_array($actionType, self::SETTLEMENT_TYPES, true)) {
            throw ValidationException::withMessages([
                'action_type' => ['Settlement must be a receivable adjustment, refund, or replacement.'],
            ]);
        }

        return DB::transaction(function () use ($caseId, $actionType, $data) {
            [$namespace, $key, $existing] = $this->begin($caseId, $actionType, $data);
            if ($existing) {
                return $existing;
            }

            $case = $this->lockedCase($caseId, $data);
            $this->assertFinanceOpen($case, $data['expected_version']);
            $credit = $this->requiredAction($caseId, 'CREDIT_NOTE');
            $tax = $this->requiredAction($caseId, 'TAX_ADJUSTMENT');
            $this->assertNoAction($caseId, self::SETTLEMENT_TYPES);
            $invoice = $this->invoice($case, (string) $case->invoice_id, true);
            $referenceNumber = $this->reference($data['reference_number'], 'reference_number');
            $amount = bcadd((string) $credit->amount, (string) $tax->amount, 4);
            $balanceBefore = (string) $invoice->outstanding_amount;
            $balanceAfter = $balanceBefore;

            if ($actionType === 'RECEIVABLE_ADJUSTMENT') {
                if (bccomp($balanceBefore, $amount, 4) < 0) {
                    throw ValidationException::withMessages([
                        'settlement' => ['The open receivable is lower than the approved credit and tax total. Use the paid-invoice refund or replacement path after reconciling the invoice.'],
                    ]);
                }

                $balanceAfter = bcsub($balanceBefore, $amount, 4);
                DB::table('sales_invoice_financials')->where('invoice_id', $invoice->invoice_id)->update([
                    'outstanding_amount' => $balanceAfter,
                    'record_version' => (int) $invoice->record_version + 1,
                    'updated_at' => now(),
                ]);
            } elseif (bccomp($balanceBefore, '0', 4) !== 0) {
                throw ValidationException::withMessages([
                    'settlement' => ['Refund and replacement settlement require a fully paid source invoice. Use receivable adjustment for an open invoice.'],
                ]);
            }

            $this->assertReferenceAvailable($case, $actionType, $referenceNumber, 'reference_number');
            $actionId = $this->insertAction($case, $actionType, [
                'invoice_id' => $invoice->invoice_id,
                'reference_number' => $referenceNumber,
                'amount' => $amount,
                'currency' => $invoice->currency,
                'balance_before' => $balanceBefore,
                'balance_after' => $balanceAfter,
                'notes' => $data['notes'] ?? null,
                'actor_id' => $data['actor_id'],
                'idempotency_key' => $key,
            ]);
            $version = (int) $case->record_version + 1;

            DB::table('unsold_return_cases')->where('id', $caseId)->update([
                'status' => 'FINANCE_RESOLVED',
                'record_version' => $version,
                'updated_at' => now(),
            ]);
            DB::table('unsold_return_status_history')->insert([
                'id' => (string) Str::uuid(),
                'return_case_id' => $caseId,
                'company_id' => $case->company_id,
                'plant_id' => $case->plant_id,
                'from_status' => $case->status,
                'to_status' => 'FINANCE_RESOLVED',
                'record_version' => $version,
                'actor_id' => $data['actor_id'],
                'created_at' => now(),
            ]);

            $result = $this->result($caseId, $actionId, $actionType, $invoice, $version, [
                'status' => 'FINANCE_RESOLVED',
                'reference_number' => $referenceNumber,
                'amount' => $amount,
                'outstanding_amount' => $balanceAfter,
                'invoice_record_version' => $actionType === 'RECEIVABLE_ADJUSTMENT'
                    ? (int) $invoice->record_version + 1
                    : (int) $invoice->record_version,
            ]);
            $this->sideEffects($case, $actionId, $actionType, $data, $result);
            $this->idempotency->complete($namespace, $key, $result);

            return $result;
        }, 3);
    }

    private function begin(string $caseId, string $actionType, array $data): array
    {
        $namespace = "sales.unsold-return.finance.{$caseId}.".strtolower($actionType);
        $key = $data['idempotency_key'];
        $payload = Arr::except($data + ['action_type' => $actionType], [
            'idempotency_key',
            'correlation_id',
        ]);

        return [$namespace, $key, $this->idempotency->begin($namespace, $key, $payload)];
    }

    private function lockedCase(string $caseId, array $data): object
    {
        $case = DB::table('unsold_return_cases')
            ->where('id', $caseId)
            ->where('company_id', $data['company_id'])
            ->where('plant_id', $data['plant_id'])
            ->lockForUpdate()
            ->first();

        if (! $case) {
            throw new NotFoundHttpException('Unsold return case not found.');
        }

        return $case;
    }

    private function assertFinanceOpen(object $case, int $expectedVersion): void
    {
        if ((int) $case->record_version !== $expectedVersion) {
            throw new ConflictHttpException(
                "The return case changed from version {$expectedVersion} to {$case->record_version}. Refresh it before retrying."
            );
        }

        if ($case->status !== 'LOSS_POSTED') {
            throw ValidationException::withMessages([
                'state' => ['Finance treatment is available only after the approved inventory loss is posted.'],
            ]);
        }
    }

    private function invoice(object $case, string $invoiceId, bool $lock = false): object
    {
        $query = DB::table('sales_invoice_financials as financial')
            ->join('invoices as invoice', function ($join) {
                $join
                    ->on('invoice.id', '=', 'financial.invoice_id')
                    ->on('invoice.company_id', '=', 'financial.company_id')
                    ->on('invoice.plant_id', '=', 'financial.plant_id');
            })
            ->where('financial.invoice_id', $invoiceId)
            ->where('financial.company_id', $case->company_id)
            ->where('financial.plant_id', $case->plant_id)
            ->where('financial.party_id', $case->party_id)
            ->where('financial.shipment_id', $case->shipment_id)
            ->whereIn('invoice.status', ['POSTED', 'PAID'])
            ->select(['financial.*', 'invoice.status as invoice_status']);

        if ($lock) {
            $query->lockForUpdate();
        }

        $invoice = $query->first();
        if (! $invoice) {
            throw ValidationException::withMessages([
                'invoice_id' => ['Select a posted invoice for this return customer, shipment, company, and plant.'],
            ]);
        }

        return $invoice;
    }

    private function requiredAction(string $caseId, string $actionType): object
    {
        $action = DB::table('unsold_return_finance_actions')
            ->where('return_case_id', $caseId)
            ->where('action_type', $actionType)
            ->first();

        if (! $action) {
            throw ValidationException::withMessages([
                'finance_action' => ["Complete {$actionType} before continuing."],
            ]);
        }

        return $action;
    }

    private function assertNoAction(string $caseId, array $actionTypes): void
    {
        if (DB::table('unsold_return_finance_actions')
            ->where('return_case_id', $caseId)
            ->whereIn('action_type', $actionTypes)
            ->exists()) {
            throw ValidationException::withMessages([
                'finance_action' => ['This finance step has already been recorded. Refresh the case before continuing.'],
            ]);
        }
    }

    private function assertReferenceAvailable(
        object $case,
        string $actionType,
        string $reference,
        string $field,
    ): void
    {
        if (DB::table('unsold_return_finance_actions')
            ->where('company_id', $case->company_id)
            ->where('action_type', $actionType)
            ->where('reference_number', $reference)
            ->exists()) {
            throw ValidationException::withMessages([
                $field => ['This finance reference has already been used in the company.'],
            ]);
        }
    }

    private function insertAction(object $case, string $actionType, array $values): string
    {
        $id = (string) Str::uuid();
        DB::table('unsold_return_finance_actions')->insert([
            'id' => $id,
            'return_case_id' => $case->id,
            'company_id' => $case->company_id,
            'plant_id' => $case->plant_id,
            'invoice_id' => $values['invoice_id'],
            'action_type' => $actionType,
            'case_record_version' => (int) $case->record_version + 1,
            'reference_number' => $values['reference_number'],
            'reference_key' => $actionType === 'INVOICE_LINK' ? null : $values['reference_number'],
            'amount' => $values['amount'] ?? null,
            'currency' => $values['currency'],
            'tax_code' => $values['tax_code'] ?? null,
            'balance_before' => $values['balance_before'] ?? null,
            'balance_after' => $values['balance_after'] ?? null,
            'notes' => $values['notes'] ?? null,
            'actor_id' => $values['actor_id'],
            'idempotency_key' => $values['idempotency_key'],
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    private function incrementCaseVersion(object $case): int
    {
        $version = (int) $case->record_version + 1;
        DB::table('unsold_return_cases')->where('id', $case->id)->update([
            'record_version' => $version,
            'updated_at' => now(),
        ]);

        return $version;
    }

    private function result(
        string $caseId,
        string $actionId,
        string $actionType,
        object $invoice,
        int $version,
        array $extra = [],
    ): array {
        return $extra + [
            'return_case_id' => $caseId,
            'finance_action_id' => $actionId,
            'action_type' => $actionType,
            'invoice_id' => (string) $invoice->invoice_id,
            'currency' => $invoice->currency,
            'status' => 'LOSS_POSTED',
            'record_version' => $version,
        ];
    }

    private function sideEffects(
        object $case,
        string $actionId,
        string $actionType,
        array $data,
        array $result,
    ): void {
        $command = match ($actionType) {
            'INVOICE_LINK' => 'LINK_UNSOLD_RETURN_INVOICE',
            'CREDIT_NOTE' => 'POST_UNSOLD_RETURN_CREDIT_NOTE',
            'TAX_ADJUSTMENT' => 'POST_UNSOLD_RETURN_TAX_ADJUSTMENT',
            'RECEIVABLE_ADJUSTMENT' => 'APPLY_UNSOLD_RETURN_RECEIVABLE',
            'REFUND' => 'POST_UNSOLD_RETURN_REFUND',
            'REPLACEMENT' => 'AUTHORISE_UNSOLD_RETURN_REPLACEMENT',
        };

        $this->audit->record(
            $command,
            'unsold_return_finance_action',
            $actionId,
            $data['actor_id'],
            $case->company_id,
            $case->plant_id,
            'SUCCESS',
            [
                'entity_version' => 1,
                'correlation_id' => $data['correlation_id'] ?? null,
                'reason_code' => $actionType,
            ]
        );

        $this->outbox->append(
            'sales.unsold_return.finance_action_posted',
            'unsold_return_case',
            (string) $case->id,
            $actionId,
            $result,
            $data['correlation_id'] ?? null
        );
    }

    private function money(mixed $value, bool $allowZero = false): string
    {
        $amount = (string) $value;
        $valid = preg_match('/^\d+(?:\.\d{1,4})?$/', $amount)
            && bccomp($amount, '0', 4) >= ($allowZero ? 0 : 1);

        if (! $valid) {
            throw ValidationException::withMessages([
                'amount' => [$allowZero
                    ? 'Amount must be zero or positive with at most four decimal places.'
                    : 'Amount must be positive with at most four decimal places.'],
            ]);
        }

        return bcadd($amount, '0', 4);
    }

    private function reference(mixed $value, string $field): string
    {
        $reference = trim((string) $value);
        if ($reference === '') {
            throw ValidationException::withMessages([
                $field => ['The finance reference cannot be blank.'],
            ]);
        }

        return $reference;
    }

    private function currency(string $currency, string $invoiceCurrency): string
    {
        $currency = strtoupper($currency);
        if ($currency !== $invoiceCurrency) {
            throw ValidationException::withMessages([
                'currency' => ['Currency must match the source invoice.'],
            ]);
        }

        return $currency;
    }
}

<?php

namespace App\Modules\Finance\Application;

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

final class AccountsPayableService
{
    public const INVOICE_STATUSES = ['DRAFT', 'MATCH_EXCEPTION', 'MATCHED', 'APPROVED', 'PARTIALLY_PAID', 'PAID', 'CANCELLED'];
    public const PROPOSAL_STATUSES = ['DRAFT', 'APPROVED', 'EXECUTED', 'CANCELLED'];
    public const PAYMENT_STATUSES = ['POSTED', 'RECONCILED'];
    public const PAYMENT_METHODS = ['BANK_TRANSFER', 'UPI', 'CHEQUE'];

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
        private readonly TransactionVisibilityPolicy $visibility,
    ) {}

    public function createInvoice(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $this->visibility->assertOperationallyVisible(
                $data['permissions'] ?? [TransactionVisibilityPolicy::SHOW_PERMISSION], 'PURCHASE_ORDER', $data['purchase_order_id'], $data,
            );
            $namespace = 'finance.payable-invoice.create';
            if ($replay = $this->begin($namespace, $data)) return $replay;
            $this->uniqueScoped('invoices', 'ap_number', $data['ap_number'], $data);
            $order = $this->issuedOrder($data['purchase_order_id'], $data);
            if (DB::table('invoices')->where('company_id', $data['company_id'])
                ->where('supplier_party_id', $order->supplier_party_id)
                ->where('supplier_invoice_number', $data['supplier_invoice_number'])->exists()) {
                throw ValidationException::withMessages(['supplier_invoice_number' => ['That supplier invoice is already recorded for this supplier.']]);
            }
            $this->validateDates($data['invoice_date'], $data['due_date']);
            [$lines, $subtotal, $tax, $total] = $this->prepareInvoiceLines($order, $data['lines'], $data);
            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('invoices')->insert([
                'id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'invoice_type' => 'PAYABLE', 'ap_number' => $data['ap_number'],
                'supplier_invoice_number' => $data['supplier_invoice_number'], 'purchase_order_id' => $order->id,
                'supplier_party_id' => $order->supplier_party_id, 'invoice_date' => $data['invoice_date'],
                'due_date' => $data['due_date'], 'currency' => 'INR', 'subtotal' => $subtotal,
                'tax_amount' => $tax, 'total_amount' => $total, 'paid_amount' => 0,
                'match_summary' => null, 'notes' => $this->nullable($data['notes'] ?? null),
                'status' => 'DRAFT', 'record_version' => 1, 'created_by' => $data['actor_id'],
                'matched_at' => null, 'matched_by' => null, 'approved_at' => null, 'approved_by' => null,
                'cancelled_at' => null, 'cancelled_by' => null, 'cancellation_reason' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->replaceInvoiceLines($id, $order, $lines, $data, $now);
            $result = $this->result($id, 'DRAFT', 1, ['subtotal' => $subtotal, 'tax_amount' => $tax, 'total_amount' => $total]);
            $this->record('CREATE_PAYABLE_INVOICE', 'finance.payable-invoice.created', 'payable_invoice', $id, $data, 1, [
                'ap_number' => $data['ap_number'], 'supplier_invoice_number' => $data['supplier_invoice_number'],
                'purchase_order_id' => (string) $order->id, 'total_amount' => $total,
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
            return $result;
        }, 3);
    }

    public function updateInvoice(string $invoiceId, array $data): array
    {
        return DB::transaction(function () use ($invoiceId, $data): array {
            $namespace = 'finance.payable-invoice.update.'.$invoiceId;
            if ($replay = $this->begin($namespace, $data + ['invoice_id' => $invoiceId])) return $replay;
            $invoice = $this->invoiceLocked($invoiceId, $data);
            $this->assertVersion($invoice, $data['expected_version']);
            $this->assertStatus($invoice, ['DRAFT', 'MATCH_EXCEPTION'], 'Only a draft or match-exception invoice can be edited.');
            $this->validateDates($data['invoice_date'], $data['due_date']);
            $order = $this->issuedOrder($invoice->purchase_order_id, $data);
            [$lines, $subtotal, $tax, $total] = $this->prepareInvoiceLines($order, $data['lines'], $data, $invoiceId);
            $version = (int) $invoice->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table('invoices')->where('id', $invoiceId)->update([
                'invoice_date' => $data['invoice_date'], 'due_date' => $data['due_date'],
                'subtotal' => $subtotal, 'tax_amount' => $tax, 'total_amount' => $total,
                'match_summary' => null, 'notes' => $this->nullable($data['notes'] ?? null),
                'status' => 'DRAFT', 'record_version' => $version, 'matched_at' => null,
                'matched_by' => null, 'updated_at' => $now,
            ]);
            DB::table('payable_invoice_lines')->where('invoice_id', $invoiceId)->delete();
            $this->replaceInvoiceLines($invoiceId, $order, $lines, $data, $now);
            $result = $this->result($invoiceId, 'DRAFT', $version, ['subtotal' => $subtotal, 'tax_amount' => $tax, 'total_amount' => $total]);
            $this->record('UPDATE_PAYABLE_INVOICE', 'finance.payable-invoice.updated', 'payable_invoice', $invoiceId, $data, $version, ['total_amount' => ['from' => $this->decimal($invoice->total_amount), 'to' => $total]], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
            return $result;
        }, 3);
    }

    public function matchInvoice(string $invoiceId, array $data): array
    {
        return DB::transaction(function () use ($invoiceId, $data): array {
            $namespace = 'finance.payable-invoice.match.'.$invoiceId;
            if ($replay = $this->begin($namespace, $data + ['invoice_id' => $invoiceId])) return $replay;
            $invoice = $this->invoiceLocked($invoiceId, $data);
            $this->assertVersion($invoice, $data['expected_version']);
            $this->assertStatus($invoice, ['DRAFT', 'MATCH_EXCEPTION'], 'Only a draft or exception invoice can be matched.');
            $order = $this->issuedOrder($invoice->purchase_order_id, $data);
            $lines = DB::table('payable_invoice_lines')->where('invoice_id', $invoiceId)->orderBy('line_number')->lockForUpdate()->get();
            if ($lines->isEmpty()) throw new ConflictHttpException('The payable invoice has no lines to match.');
            $exceptions = [];
            foreach ($lines as $line) {
                $poLine = DB::table('purchase_order_lines')->where('id', $line->purchase_order_line_id)
                    ->where('purchase_order_id', $order->id)->lockForUpdate()->first();
                if (! $poLine) throw new ConflictHttpException('A purchase-order line is no longer available.');
                $accepted = $this->acceptedQuantity((string) $poLine->id);
                $committed = $this->committedInvoiceQuantity((string) $poLine->id, $invoiceId);
                $available = bcsub($accepted, $committed, 6);
                $lineExceptions = [];
                if (bccomp($this->decimal($line->invoice_quantity), $available, 6) > 0) {
                    $lineExceptions[] = "Invoice quantity {$this->decimal($line->invoice_quantity)} exceeds accepted, uninvoiced quantity {$available}.";
                }
                if (bccomp($this->decimal($line->invoice_unit_price), $this->decimal($poLine->unit_price), 6) !== 0) {
                    $lineExceptions[] = "Invoice unit price {$this->decimal($line->invoice_unit_price)} differs from PO price {$this->decimal($poLine->unit_price)}.";
                }
                $matchResult = $lineExceptions === [] ? 'PASS' : 'EXCEPTION';
                DB::table('payable_invoice_lines')->where('id', $line->id)->update([
                    'ordered_quantity_snapshot' => $this->decimal($poLine->ordered_quantity),
                    'accepted_quantity_snapshot' => $accepted, 'po_unit_price_snapshot' => $this->decimal($poLine->unit_price),
                    'match_result' => $matchResult, 'match_exception' => $lineExceptions === [] ? null : implode(' ', $lineExceptions),
                    'updated_at' => now(),
                ]);
                if ($lineExceptions !== []) $exceptions[] = ['line_number' => (int) $line->line_number, 'messages' => $lineExceptions];
            }
            $status = $exceptions === [] ? 'MATCHED' : 'MATCH_EXCEPTION';
            $version = (int) $invoice->record_version + 1;
            DB::table('invoices')->where('id', $invoiceId)->update([
                'status' => $status, 'record_version' => $version,
                'match_summary' => json_encode(['result' => $exceptions === [] ? 'PASS' : 'EXCEPTION', 'exceptions' => $exceptions], JSON_THROW_ON_ERROR),
                'matched_at' => $exceptions === [] ? now() : null, 'matched_by' => $exceptions === [] ? $data['actor_id'] : null,
                'updated_at' => now(),
            ]);
            $result = $this->result($invoiceId, $status, $version, ['match_result' => $exceptions === [] ? 'PASS' : 'EXCEPTION', 'exceptions' => $exceptions]);
            $this->record('MATCH_PAYABLE_INVOICE', 'finance.payable-invoice.matched', 'payable_invoice', $invoiceId, $data, $version, ['match_result' => $result['match_result'], 'exceptions' => $exceptions], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
            return $result;
        }, 3);
    }

    public function approveInvoice(string $invoiceId, array $data): array
    {
        return DB::transaction(function () use ($invoiceId, $data): array {
            $namespace = 'finance.payable-invoice.approve.'.$invoiceId;
            if ($replay = $this->begin($namespace, $data + ['invoice_id' => $invoiceId])) return $replay;
            $invoice = $this->invoiceLocked($invoiceId, $data);
            $this->assertVersion($invoice, $data['expected_version']);
            $this->assertStatus($invoice, ['MATCHED'], 'Only a successfully matched invoice can be approved.');
            if ((string) $invoice->created_by === (string) $data['actor_id']) {
                throw ValidationException::withMessages(['actor' => ['Maker-checker control prevents the invoice creator from approving it.']]);
            }
            $version = (int) $invoice->record_version + 1;
            DB::table('invoices')->where('id', $invoiceId)->update([
                'status' => 'APPROVED', 'record_version' => $version,
                'approved_at' => now(), 'approved_by' => $data['actor_id'], 'updated_at' => now(),
            ]);
            $result = $this->result($invoiceId, 'APPROVED', $version, ['total_amount' => $this->decimal($invoice->total_amount)]);
            $this->record('APPROVE_PAYABLE_INVOICE', 'finance.payable-invoice.approved', 'payable_invoice', $invoiceId, $data, $version, ['status' => ['from' => 'MATCHED', 'to' => 'APPROVED']], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
            return $result;
        }, 3);
    }

    public function cancelInvoice(string $invoiceId, string $reason, array $data): array
    {
        return DB::transaction(function () use ($invoiceId, $reason, $data): array {
            $namespace = 'finance.payable-invoice.cancel.'.$invoiceId;
            if ($replay = $this->begin($namespace, $data + ['invoice_id' => $invoiceId, 'reason' => $reason])) return $replay;
            $invoice = $this->invoiceLocked($invoiceId, $data);
            $this->assertVersion($invoice, $data['expected_version']);
            $this->assertStatus($invoice, ['DRAFT', 'MATCH_EXCEPTION', 'MATCHED', 'APPROVED'], 'A paid or cancelled invoice cannot be cancelled.');
            if (DB::table('payment_proposal_lines as line')->join('payment_proposals as proposal', 'proposal.id', '=', 'line.payment_proposal_id')
                ->where('line.invoice_id', $invoiceId)->whereIn('proposal.status', ['DRAFT', 'APPROVED', 'EXECUTED'])->exists()) {
                throw ValidationException::withMessages(['status' => ['Cancel the active payment proposal before cancelling this invoice.']]);
            }
            $version = (int) $invoice->record_version + 1;
            DB::table('invoices')->where('id', $invoiceId)->update([
                'status' => 'CANCELLED', 'record_version' => $version, 'cancelled_at' => now(),
                'cancelled_by' => $data['actor_id'], 'cancellation_reason' => trim($reason), 'updated_at' => now(),
            ]);
            $result = $this->result($invoiceId, 'CANCELLED', $version);
            $this->record('CANCEL_PAYABLE_INVOICE', 'finance.payable-invoice.cancelled', 'payable_invoice', $invoiceId, $data, $version, ['reason' => $reason], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
            return $result;
        }, 3);
    }

    public function createProposal(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'finance.payment-proposal.create';
            if ($replay = $this->begin($namespace, $data)) return $replay;
            $this->uniqueScoped('payment_proposals', 'proposal_number', $data['proposal_number'], $data);
            [$lines, $total] = $this->prepareProposalLines($data['lines'], $data);
            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('payment_proposals')->insert([
                'id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'proposal_number' => $data['proposal_number'], 'payment_date' => $data['payment_date'],
                'currency' => 'INR', 'total_amount' => $total, 'notes' => $this->nullable($data['notes'] ?? null),
                'status' => 'DRAFT', 'record_version' => 1, 'created_by' => $data['actor_id'],
                'approved_at' => null, 'approved_by' => null, 'executed_at' => null, 'executed_by' => null,
                'cancelled_at' => null, 'cancelled_by' => null, 'cancellation_reason' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->replaceProposalLines($id, $lines, $data, $now);
            $result = $this->result($id, 'DRAFT', 1, ['total_amount' => $total, 'line_count' => count($lines)]);
            $this->record('CREATE_PAYMENT_PROPOSAL', 'finance.payment-proposal.created', 'payment_proposal', $id, $data, 1, ['proposal_number' => $data['proposal_number'], 'total_amount' => $total], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
            return $result;
        }, 3);
    }

    public function updateProposal(string $proposalId, array $data): array
    {
        return DB::transaction(function () use ($proposalId, $data): array {
            $namespace = 'finance.payment-proposal.update.'.$proposalId;
            if ($replay = $this->begin($namespace, $data + ['proposal_id' => $proposalId])) return $replay;
            $proposal = $this->proposalLocked($proposalId, $data);
            $this->assertVersion($proposal, $data['expected_version']);
            $this->assertStatus($proposal, ['DRAFT'], 'Only a draft payment proposal can be edited.');
            [$lines, $total] = $this->prepareProposalLines($data['lines'], $data, $proposalId);
            $version = (int) $proposal->record_version + 1;
            $now = CarbonImmutable::now();
            DB::table('payment_proposals')->where('id', $proposalId)->update([
                'payment_date' => $data['payment_date'], 'total_amount' => $total,
                'notes' => $this->nullable($data['notes'] ?? null), 'record_version' => $version, 'updated_at' => $now,
            ]);
            DB::table('payment_proposal_lines')->where('payment_proposal_id', $proposalId)->delete();
            $this->replaceProposalLines($proposalId, $lines, $data, $now);
            $result = $this->result($proposalId, 'DRAFT', $version, ['total_amount' => $total, 'line_count' => count($lines)]);
            $this->record('UPDATE_PAYMENT_PROPOSAL', 'finance.payment-proposal.updated', 'payment_proposal', $proposalId, $data, $version, ['total_amount' => ['from' => $this->decimal($proposal->total_amount), 'to' => $total]], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
            return $result;
        }, 3);
    }

    public function approveProposal(string $proposalId, array $data): array
    {
        return DB::transaction(function () use ($proposalId, $data): array {
            $namespace = 'finance.payment-proposal.approve.'.$proposalId;
            if ($replay = $this->begin($namespace, $data + ['proposal_id' => $proposalId])) return $replay;
            $proposal = $this->proposalLocked($proposalId, $data);
            $this->assertVersion($proposal, $data['expected_version']);
            $this->assertStatus($proposal, ['DRAFT'], 'Only a draft payment proposal can be approved.');
            if ((string) $proposal->created_by === (string) $data['actor_id']) {
                throw ValidationException::withMessages(['actor' => ['Maker-checker control prevents the proposal creator from approving it.']]);
            }
            $this->assertProposalStillPayable($proposalId);
            $version = (int) $proposal->record_version + 1;
            DB::table('payment_proposals')->where('id', $proposalId)->update([
                'status' => 'APPROVED', 'record_version' => $version, 'approved_at' => now(),
                'approved_by' => $data['actor_id'], 'updated_at' => now(),
            ]);
            $result = $this->result($proposalId, 'APPROVED', $version, ['total_amount' => $this->decimal($proposal->total_amount)]);
            $this->record('APPROVE_PAYMENT_PROPOSAL', 'finance.payment-proposal.approved', 'payment_proposal', $proposalId, $data, $version, ['status' => ['from' => 'DRAFT', 'to' => 'APPROVED']], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
            return $result;
        }, 3);
    }

    public function cancelProposal(string $proposalId, string $reason, array $data): array
    {
        return DB::transaction(function () use ($proposalId, $reason, $data): array {
            $namespace = 'finance.payment-proposal.cancel.'.$proposalId;
            if ($replay = $this->begin($namespace, $data + ['proposal_id' => $proposalId, 'reason' => $reason])) return $replay;
            $proposal = $this->proposalLocked($proposalId, $data);
            $this->assertVersion($proposal, $data['expected_version']);
            $this->assertStatus($proposal, ['DRAFT', 'APPROVED'], 'An executed or cancelled proposal cannot be cancelled.');
            $version = (int) $proposal->record_version + 1;
            DB::table('payment_proposals')->where('id', $proposalId)->update([
                'status' => 'CANCELLED', 'record_version' => $version, 'cancelled_at' => now(),
                'cancelled_by' => $data['actor_id'], 'cancellation_reason' => trim($reason), 'updated_at' => now(),
            ]);
            $result = $this->result($proposalId, 'CANCELLED', $version);
            $this->record('CANCEL_PAYMENT_PROPOSAL', 'finance.payment-proposal.cancelled', 'payment_proposal', $proposalId, $data, $version, ['reason' => $reason], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
            return $result;
        }, 3);
    }

    public function executeProposal(string $proposalId, array $data): array
    {
        return DB::transaction(function () use ($proposalId, $data): array {
            $namespace = 'finance.payment-proposal.execute.'.$proposalId;
            if ($replay = $this->begin($namespace, $data + ['proposal_id' => $proposalId])) return $replay;
            $proposal = $this->proposalLocked($proposalId, $data);
            $this->assertVersion($proposal, $data['expected_version']);
            $this->assertStatus($proposal, ['APPROVED'], 'Only an approved payment proposal can be executed.');
            $this->uniqueScoped('supplier_payments', 'payment_number', $data['payment_number'], $data);
            if (DB::table('supplier_payments')->where('company_id', $data['company_id'])->where('bank_reference', $data['bank_reference'])->exists()) {
                throw ValidationException::withMessages(['bank_reference' => ['That bank reference is already recorded.']]);
            }
            $this->assertProposalStillPayable($proposalId);
            $paymentId = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('supplier_payments')->insert([
                'id' => $paymentId, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'payment_proposal_id' => $proposalId, 'payment_number' => $data['payment_number'],
                'payment_date' => $data['payment_date'], 'method' => $data['method'], 'bank_reference' => $data['bank_reference'],
                'currency' => 'INR', 'total_amount' => $this->decimal($proposal->total_amount),
                'status' => 'POSTED', 'record_version' => 1, 'created_by' => $data['actor_id'],
                'reconciled_at' => null, 'reconciled_by' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $lines = DB::table('payment_proposal_lines')->where('payment_proposal_id', $proposalId)->orderBy('line_number')->lockForUpdate()->get();
            foreach ($lines as $line) {
                $invoice = DB::table('invoices')->where('id', $line->invoice_id)->lockForUpdate()->firstOrFail();
                $newPaid = bcadd($this->decimal($invoice->paid_amount), $this->decimal($line->proposed_amount), 6);
                if (bccomp($newPaid, $this->decimal($invoice->total_amount), 6) > 0) throw new ConflictHttpException('The proposal would overpay an invoice.');
                $status = bccomp($newPaid, $this->decimal($invoice->total_amount), 6) === 0 ? 'PAID' : 'PARTIALLY_PAID';
                DB::table('invoices')->where('id', $invoice->id)->update([
                    'paid_amount' => $newPaid, 'status' => $status,
                    'record_version' => (int) $invoice->record_version + 1, 'updated_at' => $now,
                ]);
                DB::table('supplier_payment_allocations')->insert([
                    'id' => (string) Str::uuid(), 'supplier_payment_id' => $paymentId,
                    'invoice_id' => $invoice->id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                    'allocated_amount' => $this->decimal($line->proposed_amount), 'created_at' => $now,
                ]);
            }
            $version = (int) $proposal->record_version + 1;
            DB::table('payment_proposals')->where('id', $proposalId)->update([
                'status' => 'EXECUTED', 'record_version' => $version,
                'executed_at' => $now, 'executed_by' => $data['actor_id'], 'updated_at' => $now,
            ]);
            $result = $this->result($proposalId, 'EXECUTED', $version, [
                'payment_id' => $paymentId, 'payment_status' => 'POSTED', 'total_amount' => $this->decimal($proposal->total_amount),
            ]);
            $this->record('EXECUTE_PAYMENT_PROPOSAL', 'finance.supplier-payment.posted', 'payment_proposal', $proposalId, $data, $version, ['payment_id' => $paymentId, 'total_amount' => $result['total_amount']], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
            return $result;
        }, 3);
    }

    public function reconcilePayment(string $paymentId, array $data): array
    {
        return DB::transaction(function () use ($paymentId, $data): array {
            $namespace = 'finance.supplier-payment.reconcile.'.$paymentId;
            if ($replay = $this->begin($namespace, $data + ['payment_id' => $paymentId])) return $replay;
            $payment = $this->findLocked('supplier_payments', $paymentId, $data, 'Supplier payment');
            $this->assertVersion($payment, $data['expected_version']);
            $this->assertStatus($payment, ['POSTED'], 'Only a posted payment can be reconciled.');
            if (DB::table('payment_reconciliations')->where('company_id', $data['company_id'])
                ->where('statement_reference', $data['statement_reference'])->exists()) {
                throw ValidationException::withMessages(['statement_reference' => ['That bank statement reference is already reconciled.']]);
            }
            $reconciliationId = (string) Str::uuid();
            DB::table('payment_reconciliations')->insert([
                'id' => $reconciliationId, 'supplier_payment_id' => $paymentId,
                'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'statement_date' => $data['statement_date'], 'statement_reference' => $data['statement_reference'],
                'notes' => $this->nullable($data['notes'] ?? null), 'reconciled_by' => $data['actor_id'], 'created_at' => now(),
            ]);
            $version = (int) $payment->record_version + 1;
            DB::table('supplier_payments')->where('id', $paymentId)->update([
                'status' => 'RECONCILED', 'record_version' => $version,
                'reconciled_at' => now(), 'reconciled_by' => $data['actor_id'], 'updated_at' => now(),
            ]);
            $result = $this->result($paymentId, 'RECONCILED', $version, ['reconciliation_id' => $reconciliationId]);
            $this->record('RECONCILE_SUPPLIER_PAYMENT', 'finance.supplier-payment.reconciled', 'supplier_payment', $paymentId, $data, $version, ['statement_reference' => $data['statement_reference']], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
            return $result;
        }, 3);
    }

    private function prepareInvoiceLines(object $order, array $input, array $scope, ?string $excludeInvoiceId = null): array
    {
        $sources = DB::table('purchase_order_lines')->where('purchase_order_id', $order->id)->get()->keyBy('id');
        $seen = []; $lines = []; $subtotal = '0.000000'; $taxTotal = '0.000000';
        foreach (array_values($input) as $index => $line) {
            $sourceId = (string) $line['purchase_order_line_id'];
            $source = $sources->get($sourceId);
            if (! $source || isset($seen[$sourceId])) throw ValidationException::withMessages(["lines.{$index}.purchase_order_line_id" => ['Select each purchase-order line at most once.']]);
            $seen[$sourceId] = true;
            $quantity = $this->positive($line['invoice_quantity'], "lines.{$index}.invoice_quantity");
            $price = $this->nonNegative($line['invoice_unit_price'], "lines.{$index}.invoice_unit_price");
            $taxRate = $this->rate($line['tax_rate'], "lines.{$index}.tax_rate");
            $net = bcround(bcmul($quantity, $price, 12), 6);
            $tax = bcround(bcdiv(bcmul($net, $taxRate, 10), '100', 10), 6);
            $gross = bcadd($net, $tax, 6);
            $accepted = $this->acceptedQuantity($sourceId);
            $lines[] = ['source' => $source, 'invoice_quantity' => $quantity, 'invoice_unit_price' => $price,
                'tax_rate' => $taxRate, 'net_amount' => $net, 'tax_amount' => $tax, 'gross_amount' => $gross,
                'accepted_quantity_snapshot' => $accepted];
            $subtotal = bcadd($subtotal, $net, 6); $taxTotal = bcadd($taxTotal, $tax, 6);
        }
        return [$lines, $subtotal, $taxTotal, bcadd($subtotal, $taxTotal, 6)];
    }

    private function replaceInvoiceLines(string $invoiceId, object $order, array $lines, array $scope, CarbonImmutable $now): void
    {
        foreach (array_values($lines) as $index => $line) {
            $source = $line['source'];
            DB::table('payable_invoice_lines')->insert([
                'id' => (string) Str::uuid(), 'invoice_id' => $invoiceId, 'purchase_order_id' => $order->id,
                'purchase_order_line_id' => $source->id, 'company_id' => $scope['company_id'], 'plant_id' => $scope['plant_id'],
                'line_number' => $index + 1, 'item_id' => $source->item_id, 'description' => $source->description,
                'ordered_quantity_snapshot' => $this->decimal($source->ordered_quantity),
                'accepted_quantity_snapshot' => $line['accepted_quantity_snapshot'], 'invoice_quantity' => $line['invoice_quantity'],
                'uom_code' => $source->uom_code, 'po_unit_price_snapshot' => $this->decimal($source->unit_price),
                'invoice_unit_price' => $line['invoice_unit_price'], 'tax_rate' => $line['tax_rate'],
                'net_amount' => $line['net_amount'], 'tax_amount' => $line['tax_amount'], 'gross_amount' => $line['gross_amount'],
                'match_result' => 'PENDING', 'match_exception' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
    }

    private function prepareProposalLines(array $input, array $scope, ?string $excludeProposalId = null): array
    {
        $seen = []; $lines = []; $total = '0.000000'; $supplierId = null;
        foreach (array_values($input) as $index => $line) {
            $invoiceId = (string) $line['invoice_id'];
            if (isset($seen[$invoiceId])) throw ValidationException::withMessages(["lines.{$index}.invoice_id" => ['Select each invoice at most once.']]);
            $seen[$invoiceId] = true;
            $this->visibility->assertOperationallyVisible(
                $scope['permissions'] ?? [TransactionVisibilityPolicy::SHOW_PERMISSION], 'PAYABLE_INVOICE', $invoiceId, $scope,
            );
            $invoice = DB::table('invoices')->where('id', $invoiceId)->where('company_id', $scope['company_id'])
                ->where('plant_id', $scope['plant_id'])->where('invoice_type', 'PAYABLE')->whereIn('status', ['APPROVED', 'PARTIALLY_PAID'])
                ->lockForUpdate()->first();
            if (! $invoice) throw ValidationException::withMessages(["lines.{$index}.invoice_id" => ['Select an approved payable invoice from the current plant.']]);
            if ($supplierId !== null && $supplierId !== (string) $invoice->supplier_party_id) {
                throw ValidationException::withMessages(["lines.{$index}.invoice_id" => ['A payment proposal can contain invoices for only one supplier.']]);
            }
            $supplierId = (string) $invoice->supplier_party_id;
            $amount = $this->positive($line['proposed_amount'], "lines.{$index}.proposed_amount");
            $outstanding = bcsub($this->decimal($invoice->total_amount), $this->decimal($invoice->paid_amount), 6);
            $committedQuery = DB::table('payment_proposal_lines as proposal_line')
                ->join('payment_proposals as proposal', 'proposal.id', '=', 'proposal_line.payment_proposal_id')
                ->where('proposal_line.invoice_id', $invoiceId)->whereIn('proposal.status', ['DRAFT', 'APPROVED']);
            if ($excludeProposalId) $committedQuery->where('proposal.id', '<>', $excludeProposalId);
            $available = bcsub($outstanding, $this->decimal($committedQuery->sum('proposal_line.proposed_amount')), 6);
            if (bccomp($amount, $available, 6) > 0) throw ValidationException::withMessages(["lines.{$index}.proposed_amount" => ['Proposed amount exceeds the uncommitted invoice balance.']]);
            $lines[] = ['invoice' => $invoice, 'proposed_amount' => $amount];
            $total = bcadd($total, $amount, 6);
        }
        return [$lines, $total];
    }

    private function replaceProposalLines(string $proposalId, array $lines, array $scope, CarbonImmutable $now): void
    {
        foreach (array_values($lines) as $index => $line) DB::table('payment_proposal_lines')->insert([
            'id' => (string) Str::uuid(), 'payment_proposal_id' => $proposalId, 'invoice_id' => $line['invoice']->id,
            'company_id' => $scope['company_id'], 'plant_id' => $scope['plant_id'], 'line_number' => $index + 1,
            'proposed_amount' => $line['proposed_amount'], 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function assertProposalStillPayable(string $proposalId): void
    {
        $lines = DB::table('payment_proposal_lines')->where('payment_proposal_id', $proposalId)->orderBy('line_number')->lockForUpdate()->get();
        if ($lines->isEmpty()) throw new ConflictHttpException('The payment proposal has no lines.');
        foreach ($lines as $line) {
            $invoice = DB::table('invoices')->where('id', $line->invoice_id)->lockForUpdate()->first();
            if (! $invoice || ! in_array($invoice->status, ['APPROVED', 'PARTIALLY_PAID'], true)) throw new ConflictHttpException('A proposed invoice is no longer payable.');
            $outstanding = bcsub($this->decimal($invoice->total_amount), $this->decimal($invoice->paid_amount), 6);
            if (bccomp($this->decimal($line->proposed_amount), $outstanding, 6) > 0) throw new ConflictHttpException('A proposed amount now exceeds its invoice balance.');
        }
    }

    private function acceptedQuantity(string $poLineId): string
    {
        return $this->decimal(DB::table('receipt_lines as line')->join('receipts as receipt', 'receipt.id', '=', 'line.receipt_id')
            ->where('line.purchase_order_line_id', $poLineId)->where('receipt.status', 'COMPLETED')->sum('line.accepted_quantity'));
    }

    private function committedInvoiceQuantity(string $poLineId, ?string $excludeInvoiceId = null): string
    {
        $query = DB::table('payable_invoice_lines as line')->join('invoices as invoice', 'invoice.id', '=', 'line.invoice_id')
            ->where('line.purchase_order_line_id', $poLineId)->where('invoice.invoice_type', 'PAYABLE')
            ->whereIn('invoice.status', ['MATCHED', 'APPROVED', 'PARTIALLY_PAID', 'PAID']);
        if ($excludeInvoiceId) $query->where('invoice.id', '<>', $excludeInvoiceId);
        return $this->decimal($query->sum('line.invoice_quantity'));
    }

    private function issuedOrder(string $id, array $scope): object
    {
        $order = DB::table('purchase_orders')->where('id', $id)->where('company_id', $scope['company_id'])
            ->where('plant_id', $scope['plant_id'])->whereNotNull('po_number')->lockForUpdate()->first();
        if (! $order || $order->status !== 'ISSUED') throw ValidationException::withMessages(['purchase_order_id' => ['Select an issued purchase order from the current plant.']]);
        return $order;
    }

    private function invoiceLocked(string $id, array $scope): object
    {
        $row = $this->findLocked('invoices', $id, $scope, 'Payable invoice');
        if ($row->invoice_type !== 'PAYABLE') throw new NotFoundHttpException('Payable invoice not found.');
        return $row;
    }

    private function proposalLocked(string $id, array $scope): object { return $this->findLocked('payment_proposals', $id, $scope, 'Payment proposal'); }

    private function findLocked(string $table, string $id, array $scope, string $label): object
    {
        $row = DB::table($table)->where('id', $id)->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])->lockForUpdate()->first();
        if (! $row) throw new NotFoundHttpException($label.' not found.');
        return $row;
    }

    private function uniqueScoped(string $table, string $column, string $value, array $scope): void
    {
        if (DB::table($table)->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])->where($column, $value)->exists()) {
            throw ValidationException::withMessages([$column => ['That document number already exists in the selected plant.']]);
        }
    }

    private function validateDates(string $invoiceDate, string $dueDate): void
    {
        if (CarbonImmutable::parse($dueDate)->isBefore(CarbonImmutable::parse($invoiceDate))) throw ValidationException::withMessages(['due_date' => ['Due date cannot be before invoice date.']]);
    }

    private function assertVersion(object $record, int $expected): void
    {
        if ((int) $record->record_version !== $expected) throw new ConflictHttpException('The record changed after it was loaded. Refresh and retry.');
    }

    private function assertStatus(object $record, array $statuses, string $message): void
    {
        if (! in_array($record->status, $statuses, true)) throw ValidationException::withMessages(['status' => [$message]]);
    }

    private function positive(mixed $value, string $field): string
    {
        if (! preg_match('/^\d+(?:\.\d{1,6})?$/', (string) $value) || bccomp((string) $value, '0', 6) <= 0) throw ValidationException::withMessages([$field => ['Enter a positive amount with at most six decimal places.']]);
        return $this->decimal($value);
    }

    private function nonNegative(mixed $value, string $field): string
    {
        if (! preg_match('/^\d+(?:\.\d{1,6})?$/', (string) $value)) throw ValidationException::withMessages([$field => ['Enter a non-negative amount with at most six decimal places.']]);
        return $this->decimal($value);
    }

    private function rate(mixed $value, string $field): string
    {
        if (! preg_match('/^\d+(?:\.\d{1,4})?$/', (string) $value) || bccomp((string) $value, '100', 4) > 0) throw ValidationException::withMessages([$field => ['Tax rate must be between 0 and 100 with at most four decimal places.']]);
        return bcadd((string) $value, '0', 4);
    }

    private function decimal(mixed $value): string { return bcadd((string) ($value ?? 0), '0', 6); }
    private function nullable(mixed $value): ?string { $value = trim((string) ($value ?? '')); return $value === '' ? null : $value; }
    private function result(string $id, string $status, int $version, array $extra = []): array { return ['id' => $id, 'status' => $status, 'record_version' => $version] + $extra; }
    private function begin(string $namespace, array $data): ?array { return $this->idempotency->begin($namespace, $data['idempotency_key'], Arr::except($data, ['permissions', 'correlation_id'])); }

    private function record(string $command, string $event, string $entityType, string $id, array $data, int $version, array $diff, array $result): void
    {
        $this->audit->record($command, $entityType, $id, $data['actor_id'], $data['company_id'], $data['plant_id'], 'SUCCESS', [
            'entity_version' => $version, 'correlation_id' => $data['correlation_id'], 'safe_diff' => $diff,
        ]);
        $this->outbox->append($event, $entityType, $id, $data['idempotency_key'], $result, $data['correlation_id'], $data['company_id'], $data['plant_id']);
    }
}

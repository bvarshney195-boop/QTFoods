<?php

namespace App\Modules\Finance\Application;

use App\Shared\Visibility\OperationalVisibilityScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class AccountsPayableQuery
{
    public function __construct(private readonly OperationalVisibilityScope $visibility) {}

    public function workspace(array $scope, array $filters, array $permissions): array
    {
        $invoiceBase = $this->invoiceBase($scope, $permissions, $filters['visibility'] ?? null);
        $invoiceQuery = clone $invoiceBase;
        $this->filters($invoiceQuery, $filters, ['invoice.ap_number', 'invoice.supplier_invoice_number', 'po.po_number', 'supplier.display_name'], 'invoice.status');
        $invoices = $invoiceQuery->orderByDesc('invoice.updated_at')->paginate((int) ($filters['per_page'] ?? 25));

        $proposalQuery = $this->proposalBase($scope, $permissions);
        $this->filters(
            $proposalQuery,
            ['q' => $filters['q'] ?? null, 'status' => $filters['proposal_status'] ?? null],
            ['proposal.proposal_number', 'creator.name'],
            'proposal.status',
        );
        $proposals = $proposalQuery->orderByDesc('proposal.updated_at')->limit(100)->get()->map(fn (object $row) => $this->proposalPayload($row, $permissions))->all();
        $paymentQuery = $this->paymentBase($scope, $permissions);
        $this->filters(
            $paymentQuery,
            ['q' => $filters['q'] ?? null, 'status' => $filters['payment_status'] ?? null],
            ['payment.payment_number', 'proposal.proposal_number', 'payment.bank_reference', 'creator.name'],
            'payment.status',
        );
        $payments = $paymentQuery->orderByDesc('payment.updated_at')->limit(100)->get()->map(fn (object $row) => $this->paymentPayload($row, $permissions))->all();

        return [
            'data' => collect($invoices->items())->map(fn (object $row) => $this->invoicePayload($row, $permissions))->all(),
            'meta' => ['current_page' => $invoices->currentPage(), 'last_page' => $invoices->lastPage(), 'per_page' => $invoices->perPage(), 'total' => $invoices->total()],
            'proposals' => $proposals, 'payments' => $payments,
            'summary' => [
                'invoice_count' => (clone $invoiceBase)->count(),
                'approved_outstanding' => $this->decimal((clone $invoiceBase)->whereIn('invoice.status', ['APPROVED', 'PARTIALLY_PAID'])->sum(DB::raw('invoice.total_amount - invoice.paid_amount'))),
                'match_exceptions' => (clone $invoiceBase)->where('invoice.status', 'MATCH_EXCEPTION')->count(),
                'draft_proposals' => DB::table('payment_proposals')->where($scope)->where('status', 'DRAFT')->count(),
                'unreconciled_payments' => DB::table('supplier_payments')->where($scope)->where('status', 'POSTED')->count(),
            ],
            'lookups' => [
                'invoice_statuses' => AccountsPayableService::INVOICE_STATUSES,
                'proposal_statuses' => AccountsPayableService::PROPOSAL_STATUSES,
                'payment_statuses' => AccountsPayableService::PAYMENT_STATUSES,
                'payment_methods' => AccountsPayableService::PAYMENT_METHODS,
                'currency' => 'INR', 'eligible_orders' => $this->eligibleOrders($scope, $permissions),
                'payable_invoices' => $this->payableInvoices($scope, $permissions),
                'visibility_filters' => $this->visibility->filterOptions($permissions),
            ],
            'operational_view' => $this->visibility->viewDescriptor($permissions),
            'allowed_actions' => array_values(array_filter([
                $this->can($permissions, 'ACTION:FIN-AP:INVOICE-CREATE') ? 'CREATE_INVOICE' : null,
                $this->can($permissions, 'ACTION:FIN-AP:PROPOSAL-CREATE') ? 'CREATE_PROPOSAL' : null,
            ])),
        ];
    }

    public function invoiceDetail(string $id, array $scope, array $permissions): array
    {
        $row = $this->invoiceBase($scope, $permissions)->where('invoice.id', $id)->first();
        if (! $row) throw new NotFoundHttpException('Payable invoice not found.');
        $payload = $this->invoicePayload($row, $permissions);
        $payload['lines'] = DB::table('payable_invoice_lines as line')->join('items as item', 'item.id', '=', 'line.item_id')
            ->where('line.invoice_id', $id)->orderBy('line.line_number')
            ->get(['line.*', 'item.code as item_code', 'item.name as item_name'])->map(fn (object $line) => [
                'id' => (string) $line->id, 'purchase_order_line_id' => (string) $line->purchase_order_line_id,
                'line_number' => (int) $line->line_number,
                'item' => ['id' => (string) $line->item_id, 'code' => $line->item_code, 'name' => $line->item_name],
                'description' => $line->description, 'ordered_quantity' => $this->decimal($line->ordered_quantity_snapshot),
                'accepted_quantity' => $this->decimal($line->accepted_quantity_snapshot),
                'invoice_quantity' => $this->decimal($line->invoice_quantity), 'uom_code' => $line->uom_code,
                'po_unit_price' => $this->decimal($line->po_unit_price_snapshot),
                'invoice_unit_price' => $this->decimal($line->invoice_unit_price), 'tax_rate' => $this->rate($line->tax_rate),
                'net_amount' => $this->decimal($line->net_amount), 'tax_amount' => $this->decimal($line->tax_amount),
                'gross_amount' => $this->decimal($line->gross_amount), 'match_result' => $line->match_result,
                'match_exception' => $line->match_exception,
            ])->all();
        $payload['allocations'] = DB::table('supplier_payment_allocations as allocation')
            ->join('supplier_payments as payment', 'payment.id', '=', 'allocation.supplier_payment_id')
            ->where('allocation.invoice_id', $id)->orderBy('payment.payment_date')
            ->get(['payment.id', 'payment.payment_number', 'payment.payment_date', 'payment.status', 'allocation.allocated_amount'])
            ->map(fn (object $row) => ['payment_id' => (string) $row->id, 'payment_number' => $row->payment_number,
                'payment_date' => (string) $row->payment_date, 'payment_status' => $row->status,
                'allocated_amount' => $this->decimal($row->allocated_amount)])->all();
        return $payload;
    }

    public function proposalDetail(string $id, array $scope, array $permissions): array
    {
        $row = $this->proposalBase($scope, $permissions)->where('proposal.id', $id)->first();
        if (! $row) throw new NotFoundHttpException('Payment proposal not found.');
        $payload = $this->proposalPayload($row, $permissions);
        $payload['lines'] = DB::table('payment_proposal_lines as line')->join('invoices as invoice', 'invoice.id', '=', 'line.invoice_id')
            ->join('parties as supplier', 'supplier.id', '=', 'invoice.supplier_party_id')
            ->where('line.payment_proposal_id', $id)->orderBy('line.line_number')
            ->get(['line.*', 'invoice.ap_number', 'invoice.supplier_invoice_number', 'invoice.total_amount', 'invoice.paid_amount',
                'supplier.code as supplier_code', 'supplier.display_name as supplier_name'])
            ->map(fn (object $line) => [
                'id' => (string) $line->id, 'invoice_id' => (string) $line->invoice_id, 'line_number' => (int) $line->line_number,
                'ap_number' => $line->ap_number, 'supplier_invoice_number' => $line->supplier_invoice_number,
                'supplier' => ['code' => $line->supplier_code, 'name' => $line->supplier_name],
                'invoice_total' => $this->decimal($line->total_amount), 'invoice_paid' => $this->decimal($line->paid_amount),
                'proposed_amount' => $this->decimal($line->proposed_amount),
            ])->all();
        $payment = DB::table('supplier_payments')->where('payment_proposal_id', $id)->first(['id', 'payment_number', 'status']);
        $payload['payment'] = $payment ? ['id' => (string) $payment->id, 'number' => $payment->payment_number, 'status' => $payment->status] : null;
        return $payload;
    }

    public function paymentDetail(string $id, array $scope, array $permissions): array
    {
        $row = $this->paymentBase($scope, $permissions)->where('payment.id', $id)->first();
        if (! $row) throw new NotFoundHttpException('Supplier payment not found.');
        $payload = $this->paymentPayload($row, $permissions);
        $payload['allocations'] = DB::table('supplier_payment_allocations as allocation')
            ->join('invoices as invoice', 'invoice.id', '=', 'allocation.invoice_id')
            ->join('parties as supplier', 'supplier.id', '=', 'invoice.supplier_party_id')
            ->where('allocation.supplier_payment_id', $id)->orderBy('invoice.ap_number')
            ->get(['invoice.id', 'invoice.ap_number', 'invoice.supplier_invoice_number', 'allocation.allocated_amount',
                'supplier.code as supplier_code', 'supplier.display_name as supplier_name'])
            ->map(fn (object $row) => ['invoice_id' => (string) $row->id, 'ap_number' => $row->ap_number,
                'supplier_invoice_number' => $row->supplier_invoice_number,
                'supplier' => ['code' => $row->supplier_code, 'name' => $row->supplier_name],
                'allocated_amount' => $this->decimal($row->allocated_amount)])->all();
        $reconciliation = DB::table('payment_reconciliations as reconciliation')
            ->join('users as actor', 'actor.id', '=', 'reconciliation.reconciled_by')
            ->where('reconciliation.supplier_payment_id', $id)
            ->first(['reconciliation.*', 'actor.name as actor_name']);
        $payload['reconciliation'] = $reconciliation ? [
            'id' => (string) $reconciliation->id, 'statement_date' => (string) $reconciliation->statement_date,
            'statement_reference' => $reconciliation->statement_reference, 'notes' => $reconciliation->notes,
            'reconciled_by' => ['id' => (string) $reconciliation->reconciled_by, 'name' => $reconciliation->actor_name],
            'created_at' => $this->timestamp($reconciliation->created_at),
        ] : null;
        return $payload;
    }

    private function invoiceBase(array $scope, array $permissions, ?string $visibilityFilter = null): Builder
    {
        $query = DB::table('invoices as invoice')->join('purchase_orders as po', 'po.id', '=', 'invoice.purchase_order_id')
            ->join('parties as supplier', 'supplier.id', '=', 'invoice.supplier_party_id')
            ->join('users as creator', 'creator.id', '=', 'invoice.created_by')
            ->leftJoin('users as matcher', 'matcher.id', '=', 'invoice.matched_by')
            ->leftJoin('users as approver', 'approver.id', '=', 'invoice.approved_by')
            ->where('invoice.company_id', $scope['company_id'])->where('invoice.plant_id', $scope['plant_id'])->where('invoice.invoice_type', 'PAYABLE')
            ->select(['invoice.*', 'po.po_number', 'supplier.code as supplier_code', 'supplier.display_name as supplier_name',
                'creator.name as creator_name', 'matcher.name as matcher_name', 'approver.name as approver_name'])
            ->selectSub(fn (Builder $q) => $q->from('payable_invoice_lines as line')->whereColumn('line.invoice_id', 'invoice.id')->selectRaw('COUNT(*)'), 'line_count');
        $this->visibility->apply($query, 'PAYABLE_INVOICE', 'invoice.id', $permissions, $visibilityFilter, 'payable_invoice_visibility');
        $this->visibility->apply($query, 'PURCHASE_ORDER', 'po.id', $permissions, null, 'payable_po_visibility', false);

        return $query;
    }

    private function proposalBase(array $scope, array $permissions): Builder
    {
        $query = DB::table('payment_proposals as proposal')->join('users as creator', 'creator.id', '=', 'proposal.created_by')
            ->leftJoin('users as approver', 'approver.id', '=', 'proposal.approved_by')
            ->where('proposal.company_id', $scope['company_id'])->where('proposal.plant_id', $scope['plant_id'])
            ->select(['proposal.*', 'creator.name as creator_name', 'approver.name as approver_name'])
            ->selectSub(fn (Builder $q) => $q->from('payment_proposal_lines as line')->whereColumn('line.payment_proposal_id', 'proposal.id')->selectRaw('COUNT(*)'), 'line_count');
        $this->excludeRestrictedProposalSources($query, 'proposal.id', $permissions);

        return $query;
    }

    private function paymentBase(array $scope, array $permissions): Builder
    {
        $query = DB::table('supplier_payments as payment')->join('payment_proposals as proposal', 'proposal.id', '=', 'payment.payment_proposal_id')
            ->join('users as creator', 'creator.id', '=', 'payment.created_by')
            ->leftJoin('users as reconciler', 'reconciler.id', '=', 'payment.reconciled_by')
            ->where('payment.company_id', $scope['company_id'])->where('payment.plant_id', $scope['plant_id'])
            ->select(['payment.*', 'proposal.proposal_number', 'creator.name as creator_name', 'reconciler.name as reconciler_name']);
        $this->excludeRestrictedProposalSources($query, 'proposal.id', $permissions);

        return $query;
    }

    private function invoicePayload(object $row, array $permissions): array
    {
        $actions = [];
        if (in_array($row->status, ['DRAFT', 'MATCH_EXCEPTION'], true) && $this->can($permissions, 'ACTION:FIN-AP:INVOICE-UPDATE')) $actions[] = 'UPDATE';
        if (in_array($row->status, ['DRAFT', 'MATCH_EXCEPTION'], true) && $this->can($permissions, 'ACTION:FIN-AP:MATCH')) $actions[] = 'MATCH';
        if ($row->status === 'MATCHED' && $this->can($permissions, 'ACTION:FIN-AP:INVOICE-APPROVE')) $actions[] = 'APPROVE';
        if (in_array($row->status, ['DRAFT', 'MATCH_EXCEPTION', 'MATCHED', 'APPROVED'], true) && $this->can($permissions, 'ACTION:FIN-AP:INVOICE-CANCEL')) $actions[] = 'CANCEL';
        $summary = is_string($row->match_summary) ? json_decode($row->match_summary, true) : ($row->match_summary ? (array) $row->match_summary : null);
        return [
            'id' => (string) $row->id, 'ap_number' => $row->ap_number, 'supplier_invoice_number' => $row->supplier_invoice_number,
            'purchase_order' => ['id' => (string) $row->purchase_order_id, 'number' => $row->po_number],
            'supplier' => ['id' => (string) $row->supplier_party_id, 'code' => $row->supplier_code, 'name' => $row->supplier_name],
            'invoice_date' => (string) $row->invoice_date, 'due_date' => (string) $row->due_date, 'currency' => $row->currency,
            'subtotal' => $this->decimal($row->subtotal), 'tax_amount' => $this->decimal($row->tax_amount),
            'total_amount' => $this->decimal($row->total_amount), 'paid_amount' => $this->decimal($row->paid_amount),
            'outstanding_amount' => bcsub($this->decimal($row->total_amount), $this->decimal($row->paid_amount), 6),
            'match_summary' => $summary, 'notes' => $row->notes, 'status' => $row->status,
            'record_version' => (int) $row->record_version, 'line_count' => (int) $row->line_count,
            'created_by' => ['id' => (string) $row->created_by, 'name' => $row->creator_name],
            'matched_by' => $row->matched_by ? ['id' => (string) $row->matched_by, 'name' => $row->matcher_name] : null,
            'approved_by' => $row->approved_by ? ['id' => (string) $row->approved_by, 'name' => $row->approver_name] : null,
            'matched_at' => $this->timestamp($row->matched_at), 'approved_at' => $this->timestamp($row->approved_at),
            'cancelled_at' => $this->timestamp($row->cancelled_at), 'cancellation_reason' => $row->cancellation_reason,
            'visibility' => $this->visibility->metadata($row),
            'allowed_actions' => $actions, 'created_at' => $this->timestamp($row->created_at), 'updated_at' => $this->timestamp($row->updated_at),
        ];
    }

    private function proposalPayload(object $row, array $permissions): array
    {
        $actions = [];
        if ($row->status === 'DRAFT' && $this->can($permissions, 'ACTION:FIN-AP:PROPOSAL-UPDATE')) $actions[] = 'UPDATE';
        if ($row->status === 'DRAFT' && $this->can($permissions, 'ACTION:FIN-AP:PROPOSAL-APPROVE')) $actions[] = 'APPROVE';
        if ($row->status === 'APPROVED' && $this->can($permissions, 'ACTION:FIN-AP:PAY')) $actions[] = 'EXECUTE';
        if (in_array($row->status, ['DRAFT', 'APPROVED'], true) && $this->can($permissions, 'ACTION:FIN-AP:PROPOSAL-CANCEL')) $actions[] = 'CANCEL';
        return ['id' => (string) $row->id, 'proposal_number' => $row->proposal_number, 'payment_date' => (string) $row->payment_date,
            'currency' => $row->currency, 'total_amount' => $this->decimal($row->total_amount), 'notes' => $row->notes,
            'status' => $row->status, 'record_version' => (int) $row->record_version, 'line_count' => (int) $row->line_count,
            'created_by' => ['id' => (string) $row->created_by, 'name' => $row->creator_name],
            'approved_by' => $row->approved_by ? ['id' => (string) $row->approved_by, 'name' => $row->approver_name] : null,
            'approved_at' => $this->timestamp($row->approved_at), 'executed_at' => $this->timestamp($row->executed_at),
            'cancelled_at' => $this->timestamp($row->cancelled_at), 'cancellation_reason' => $row->cancellation_reason,
            'allowed_actions' => $actions, 'created_at' => $this->timestamp($row->created_at), 'updated_at' => $this->timestamp($row->updated_at)];
    }

    private function paymentPayload(object $row, array $permissions): array
    {
        return ['id' => (string) $row->id, 'payment_number' => $row->payment_number,
            'proposal' => ['id' => (string) $row->payment_proposal_id, 'number' => $row->proposal_number],
            'payment_date' => (string) $row->payment_date, 'method' => $row->method, 'bank_reference' => $row->bank_reference,
            'currency' => $row->currency, 'total_amount' => $this->decimal($row->total_amount), 'status' => $row->status,
            'record_version' => (int) $row->record_version,
            'created_by' => ['id' => (string) $row->created_by, 'name' => $row->creator_name],
            'reconciled_by' => $row->reconciled_by ? ['id' => (string) $row->reconciled_by, 'name' => $row->reconciler_name] : null,
            'reconciled_at' => $this->timestamp($row->reconciled_at),
            'allowed_actions' => $row->status === 'POSTED' && $this->can($permissions, 'ACTION:FIN-AP:RECONCILE') ? ['RECONCILE'] : [],
            'created_at' => $this->timestamp($row->created_at), 'updated_at' => $this->timestamp($row->updated_at)];
    }

    private function eligibleOrders(array $scope, array $permissions): array
    {
        $query = DB::table('purchase_orders as po')->join('parties as supplier', 'supplier.id', '=', 'po.supplier_party_id')
            ->where('po.company_id', $scope['company_id'])->where('po.plant_id', $scope['plant_id'])->where('po.status', 'ISSUED')
            ->orderBy('po.po_number')->select(['po.id', 'po.po_number', 'po.supplier_party_id', 'po.payment_terms_days',
                'supplier.code as supplier_code', 'supplier.display_name as supplier_name'])
            ;
        $this->visibility->apply($query, 'PURCHASE_ORDER', 'po.id', $permissions, null, 'eligible_po_visibility', false);

        return $query->get()
            ->map(function (object $po): array {
                $lines = DB::table('purchase_order_lines as line')->join('items as item', 'item.id', '=', 'line.item_id')
                    ->where('line.purchase_order_id', $po->id)->orderBy('line.line_number')
                    ->get(['line.id', 'line.line_number', 'line.item_id', 'line.description', 'line.ordered_quantity', 'line.uom_code', 'line.unit_price',
                        'item.code as item_code', 'item.name as item_name'])
                    ->map(function (object $line): array {
                        $accepted = $this->acceptedQuantity((string) $line->id);
                        $invoiced = $this->committedQuantity((string) $line->id);
                        return ['id' => (string) $line->id, 'line_number' => (int) $line->line_number,
                            'item' => ['id' => (string) $line->item_id, 'code' => $line->item_code, 'name' => $line->item_name],
                            'description' => $line->description, 'ordered_quantity' => $this->decimal($line->ordered_quantity),
                            'accepted_quantity' => $accepted, 'invoiced_quantity' => $invoiced,
                            'available_quantity' => bcsub($accepted, $invoiced, 6), 'uom_code' => $line->uom_code,
                            'unit_price' => $this->decimal($line->unit_price)];
                    })->all();
                return ['id' => (string) $po->id, 'number' => $po->po_number, 'payment_terms_days' => (int) $po->payment_terms_days,
                    'supplier' => ['id' => (string) $po->supplier_party_id, 'code' => $po->supplier_code, 'name' => $po->supplier_name], 'lines' => $lines];
            })->all();
    }

    private function payableInvoices(array $scope, array $permissions): array
    {
        $query = DB::table('invoices as invoice')->join('parties as supplier', 'supplier.id', '=', 'invoice.supplier_party_id')
            ->join('purchase_orders as po', 'po.id', '=', 'invoice.purchase_order_id')
            ->where('invoice.company_id', $scope['company_id'])->where('invoice.plant_id', $scope['plant_id'])
            ->where('invoice.invoice_type', 'PAYABLE')->whereIn('invoice.status', ['APPROVED', 'PARTIALLY_PAID'])
            ->orderBy('invoice.due_date')->select(['invoice.id', 'invoice.ap_number', 'invoice.supplier_invoice_number', 'invoice.due_date',
                'invoice.total_amount', 'invoice.paid_amount', 'supplier.code as supplier_code', 'supplier.display_name as supplier_name'])
            ;
        $this->visibility->apply($query, 'PAYABLE_INVOICE', 'invoice.id', $permissions, null, 'payable_lookup_visibility', false);
        $this->visibility->apply($query, 'PURCHASE_ORDER', 'po.id', $permissions, null, 'payable_lookup_po_visibility', false);

        return $query->get()
            ->map(function (object $row): array {
                $outstanding = bcsub($this->decimal($row->total_amount), $this->decimal($row->paid_amount), 6);
                $committed = $this->decimal(DB::table('payment_proposal_lines as line')->join('payment_proposals as proposal', 'proposal.id', '=', 'line.payment_proposal_id')
                    ->where('line.invoice_id', $row->id)->whereIn('proposal.status', ['DRAFT', 'APPROVED'])->sum('line.proposed_amount'));
                return ['id' => (string) $row->id, 'ap_number' => $row->ap_number, 'supplier_invoice_number' => $row->supplier_invoice_number,
                    'due_date' => (string) $row->due_date, 'supplier' => ['code' => $row->supplier_code, 'name' => $row->supplier_name],
                    'total_amount' => $this->decimal($row->total_amount), 'paid_amount' => $this->decimal($row->paid_amount),
                    'outstanding_amount' => $outstanding, 'uncommitted_amount' => bcsub($outstanding, $committed, 6)];
            })->filter(fn (array $row) => bccomp($row['uncommitted_amount'], '0', 6) > 0)->values()->all();
    }

    private function acceptedQuantity(string $poLineId): string
    {
        return $this->decimal(DB::table('receipt_lines as line')->join('receipts as receipt', 'receipt.id', '=', 'line.receipt_id')
            ->where('line.purchase_order_line_id', $poLineId)->where('receipt.status', 'COMPLETED')->sum('line.accepted_quantity'));
    }

    private function committedQuantity(string $poLineId): string
    {
        return $this->decimal(DB::table('payable_invoice_lines as line')->join('invoices as invoice', 'invoice.id', '=', 'line.invoice_id')
            ->where('line.purchase_order_line_id', $poLineId)->whereIn('invoice.status', ['MATCHED', 'APPROVED', 'PARTIALLY_PAID', 'PAID'])->sum('line.invoice_quantity'));
    }

    private function excludeRestrictedProposalSources(Builder $query, string $qualifiedProposalId, array $permissions): void
    {
        if (in_array('ShowEntries', $permissions, true)) return;

        $query->whereNotExists(function (Builder $source) use ($qualifiedProposalId): void {
            $source->from('payment_proposal_lines as visibility_proposal_line')
                ->join('invoices as visibility_invoice', 'visibility_invoice.id', '=', 'visibility_proposal_line.invoice_id')
                ->whereColumn('visibility_proposal_line.payment_proposal_id', $qualifiedProposalId)
                ->where(function (Builder $restricted): void {
                    $restricted->whereExists(fn (Builder $visibility) => $visibility
                        ->from('transaction_visibilities as proposal_invoice_visibility')
                        ->whereColumn('proposal_invoice_visibility.transaction_id', 'visibility_invoice.id')
                        ->where('proposal_invoice_visibility.transaction_type', 'PAYABLE_INVOICE')
                        ->where('proposal_invoice_visibility.visibility_status', 'RESTRICTED'))
                        ->orWhereExists(fn (Builder $visibility) => $visibility
                            ->from('transaction_visibilities as proposal_po_visibility')
                            ->whereColumn('proposal_po_visibility.transaction_id', 'visibility_invoice.purchase_order_id')
                            ->where('proposal_po_visibility.transaction_type', 'PURCHASE_ORDER')
                            ->where('proposal_po_visibility.visibility_status', 'RESTRICTED'));
                });
        });
    }

    private function filters(Builder $query, array $filters, array $columns, string $statusColumn): void
    {
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') { $needle = '%'.mb_strtolower($search).'%'; $query->where(function (Builder $q) use ($columns, $needle): void { foreach ($columns as $column) $q->orWhereRaw("LOWER(COALESCE({$column}, '')) LIKE ?", [$needle]); }); }
        if (! empty($filters['status'])) $query->where($statusColumn, $filters['status']);
    }

    private function can(array $permissions, string $permission): bool { return in_array($permission, $permissions, true); }
    private function decimal(mixed $value): string { return bcadd((string) ($value ?? 0), '0', 6); }
    private function rate(mixed $value): string { return bcadd((string) ($value ?? 0), '0', 4); }
    private function timestamp(mixed $value): ?string { return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString(); }
}

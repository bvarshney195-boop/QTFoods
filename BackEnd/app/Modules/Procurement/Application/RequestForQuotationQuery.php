<?php

namespace App\Modules\Procurement\Application;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class RequestForQuotationQuery
{
    public const SORTS = ['NEWEST', 'OLDEST', 'NUMBER', 'RESPONSE_DUE', 'STATUS', 'VALUE'];

    public function workspace(array $scope, array $filters, array $permissions): array
    {
        $base = $this->base($scope);
        $query = clone $base;
        $this->applyFilters($query, $filters);
        $this->applySort($query, $filters['sort'] ?? 'NEWEST');
        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($page->items())->map(
                fn (object $row): array => $this->payload($row, $permissions)
            )->all(),
            'meta' => $this->meta($page),
            'summary' => [
                'total' => (clone $base)->count(),
                'draft' => (clone $base)->where('rfq.status', 'DRAFT')->count(),
                'issued' => (clone $base)->where('rfq.status', 'ISSUED')->count(),
                'awarded' => (clone $base)->where('rfq.status', 'AWARDED')->count(),
                'cancelled' => (clone $base)->where('rfq.status', 'CANCELLED')->count(),
                'awarded_total' => $this->decimal(DB::table('requests_for_quotation as rfq')
                    ->join('supplier_quotes as quote', 'quote.id', '=', 'rfq.awarded_quote_id')
                    ->where('rfq.company_id', $scope['company_id'])
                    ->where('rfq.plant_id', $scope['plant_id'])
                    ->where('rfq.status', 'AWARDED')->sum('quote.total_amount')),
            ],
            'lookups' => [
                'statuses' => RequestForQuotationService::STATUSES,
                'sorts' => self::SORTS,
                'approved_requisitions' => $this->approvedRequisitions($scope),
                'suppliers' => $this->suppliers($scope),
                'currency' => 'INR',
            ],
            'allowed_actions' => $this->can($permissions, 'ACTION:PUR-RFQ:CREATE') ? ['CREATE'] : [],
        ];
    }

    public function detail(string $rfqId, array $scope, array $permissions): array
    {
        $row = $this->base($scope)->where('rfq.id', $rfqId)->first();
        if (! $row) {
            throw new NotFoundHttpException('Request for quotation not found.');
        }
        $payload = $this->payload($row, $permissions);
        $payload['lines'] = DB::table('rfq_lines as line')
            ->join('items as item', 'item.id', '=', 'line.item_id')
            ->where('line.rfq_id', $rfqId)->orderBy('line.line_number')
            ->get([
                'line.*', 'item.code as item_code', 'item.name as item_name',
                'item.item_type', 'item.status as item_status',
            ])->map(fn (object $line): array => [
                'id' => (string) $line->id,
                'requisition_line_id' => (string) $line->requisition_line_id,
                'line_number' => (int) $line->line_number,
                'item' => [
                    'id' => (string) $line->item_id,
                    'code' => $line->item_code,
                    'name' => $line->item_name,
                    'item_type' => $line->item_type,
                    'status' => $line->item_status,
                ],
                'description' => $line->description,
                'quantity' => $this->decimal($line->quantity),
                'uom_code' => $line->uom_code,
                'notes' => $line->notes,
            ])->all();

        $quotes = DB::table('supplier_quotes as quote')
            ->join('parties as supplier', 'supplier.id', '=', 'quote.supplier_party_id')
            ->where('quote.rfq_id', $rfqId)
            ->orderBy('quote.total_amount')->orderBy('quote.promised_delivery_date')
            ->get([
                'quote.*', 'supplier.code as supplier_code', 'supplier.display_name as supplier_name',
            ]);
        $quoteLines = DB::table('supplier_quote_lines')->whereIn('supplier_quote_id', $quotes->pluck('id'))
            ->orderBy('line_number')->get()->groupBy('supplier_quote_id');
        $quotePayloads = $quotes->map(fn (object $quote): array => [
            'id' => (string) $quote->id,
            'supplier' => [
                'id' => (string) $quote->supplier_party_id,
                'code' => $quote->supplier_code,
                'name' => $quote->supplier_name,
            ],
            'quote_number' => $quote->quote_number,
            'quote_date' => (string) $quote->quote_date,
            'valid_until' => (string) $quote->valid_until,
            'promised_delivery_date' => (string) $quote->promised_delivery_date,
            'payment_terms_days' => (int) $quote->payment_terms_days,
            'currency' => $quote->currency,
            'subtotal' => $this->decimal($quote->subtotal),
            'freight_amount' => $this->decimal($quote->freight_amount),
            'other_charges' => $this->decimal($quote->other_charges),
            'discount_amount' => $this->decimal($quote->discount_amount),
            'total_amount' => $this->decimal($quote->total_amount),
            'status' => $quote->status,
            'notes' => $quote->notes,
            'record_version' => (int) $quote->record_version,
            'submitted_at' => $this->timestamp($quote->submitted_at),
            'lines' => $quoteLines->get($quote->id, collect())->map(fn (object $line): array => [
                'id' => (string) $line->id,
                'rfq_line_id' => (string) $line->rfq_line_id,
                'line_number' => (int) $line->line_number,
                'quantity' => $this->decimal($line->quantity),
                'uom_code' => $line->uom_code,
                'unit_price' => $this->decimal($line->unit_price),
                'line_total' => $this->decimal($line->line_total),
                'notes' => $line->notes,
            ])->all(),
        ])->values();
        $payload['suppliers'] = $this->supplierResponses($rfqId, $quotePayloads);
        $payload['comparison'] = $this->comparison($quotePayloads, (string) $row->required_by_date);

        return $payload;
    }

    private function base(array $scope): Builder
    {
        return DB::table('requests_for_quotation as rfq')
            ->join('requisitions as requisition', 'requisition.id', '=', 'rfq.requisition_id')
            ->join('users as creator', 'creator.id', '=', 'rfq.created_by')
            ->leftJoin('users as issuer', 'issuer.id', '=', 'rfq.issued_by')
            ->leftJoin('users as awarder', 'awarder.id', '=', 'rfq.awarded_by')
            ->leftJoin('users as canceller', 'canceller.id', '=', 'rfq.cancelled_by')
            ->leftJoin('parties as awarded_supplier', 'awarded_supplier.id', '=', 'rfq.awarded_supplier_id')
            ->leftJoin('supplier_quotes as awarded_quote', 'awarded_quote.id', '=', 'rfq.awarded_quote_id')
            ->where('rfq.company_id', $scope['company_id'])
            ->where('rfq.plant_id', $scope['plant_id'])
            ->select([
                'rfq.*', 'requisition.requisition_number', 'requisition.department',
                'requisition.purpose', 'creator.name as creator_name', 'issuer.name as issuer_name',
                'awarder.name as awarder_name', 'canceller.name as canceller_name',
                'awarded_supplier.code as awarded_supplier_code',
                'awarded_supplier.display_name as awarded_supplier_name',
                'awarded_quote.total_amount as awarded_total',
            ])
            ->selectSub(fn (Builder $query) => $query->from('rfq_lines as line')
                ->whereColumn('line.rfq_id', 'rfq.id')->selectRaw('COUNT(*)'), 'line_count')
            ->selectSub(fn (Builder $query) => $query->from('rfq_suppliers as supplier')
                ->whereColumn('supplier.rfq_id', 'rfq.id')->selectRaw('COUNT(*)'), 'supplier_count')
            ->selectSub(fn (Builder $query) => $query->from('supplier_quotes as quote')
                ->whereColumn('quote.rfq_id', 'rfq.id')->selectRaw('COUNT(*)'), 'quote_count')
            ->selectSub(fn (Builder $query) => $query->from('purchase_orders as po')
                ->whereColumn('po.rfq_id', 'rfq.id')->whereNotNull('po.po_number')
                ->where('po.status', '<>', 'CANCELLED')->selectRaw('COUNT(*)'), 'active_order_count');
    }

    private function payload(object $row, array $permissions): array
    {
        return [
            'id' => (string) $row->id,
            'company_id' => (string) $row->company_id,
            'plant_id' => (string) $row->plant_id,
            'rfq_number' => $row->rfq_number,
            'requisition' => [
                'id' => (string) $row->requisition_id,
                'number' => $row->requisition_number,
                'department' => $row->department,
                'purpose' => $row->purpose,
            ],
            'status' => $row->status,
            'currency' => $row->currency,
            'estimated_total_snapshot' => $this->decimal($row->estimated_total_snapshot),
            'response_due_date' => (string) $row->response_due_date,
            'required_by_date' => (string) $row->required_by_date,
            'commercial_terms' => $row->commercial_terms,
            'record_version' => (int) $row->record_version,
            'line_count' => (int) $row->line_count,
            'supplier_count' => (int) $row->supplier_count,
            'quote_count' => (int) $row->quote_count,
            'created_by' => ['id' => (string) $row->created_by, 'name' => $row->creator_name],
            'issued_at' => $this->timestamp($row->issued_at),
            'issued_by' => $row->issued_by ? ['id' => (string) $row->issued_by, 'name' => $row->issuer_name] : null,
            'awarded_at' => $this->timestamp($row->awarded_at),
            'awarded_by' => $row->awarded_by ? ['id' => (string) $row->awarded_by, 'name' => $row->awarder_name] : null,
            'award' => $row->awarded_supplier_id ? [
                'supplier' => [
                    'id' => (string) $row->awarded_supplier_id,
                    'code' => $row->awarded_supplier_code,
                    'name' => $row->awarded_supplier_name,
                ],
                'quote_id' => (string) $row->awarded_quote_id,
                'total_amount' => $this->decimal($row->awarded_total),
                'reason' => $row->award_reason,
            ] : null,
            'cancelled_at' => $this->timestamp($row->cancelled_at),
            'cancelled_by' => $row->cancelled_by ? [
                'id' => (string) $row->cancelled_by, 'name' => $row->canceller_name,
            ] : null,
            'cancellation_reason' => $row->cancellation_reason,
            'has_active_purchase_order' => (int) $row->active_order_count > 0,
            'allowed_actions' => $this->allowedActions($row, $permissions),
            'created_at' => $this->timestamp($row->created_at),
            'updated_at' => $this->timestamp($row->updated_at),
        ];
    }

    private function allowedActions(object $row, array $permissions): array
    {
        $actions = [];
        if ($row->status === 'DRAFT' && $this->can($permissions, 'ACTION:PUR-RFQ:UPDATE')) {
            $actions[] = 'UPDATE';
        }
        if ($row->status === 'DRAFT' && $this->can($permissions, 'ACTION:PUR-RFQ:ISSUE')) {
            $actions[] = 'ISSUE';
        }
        if ($row->status === 'ISSUED' && $this->can($permissions, 'ACTION:PUR-RFQ:QUOTE')) {
            $actions[] = 'RECORD_QUOTE';
        }
        if ($row->status === 'ISSUED' && (int) $row->quote_count > 0
            && $this->can($permissions, 'ACTION:PUR-RFQ:AWARD')) {
            $actions[] = 'AWARD';
        }
        if (in_array($row->status, ['DRAFT', 'ISSUED'], true)
            && $this->can($permissions, 'ACTION:PUR-RFQ:CANCEL')) {
            $actions[] = 'CANCEL';
        }
        if ($row->status === 'AWARDED' && (int) $row->active_order_count === 0
            && $this->can($permissions, 'ACTION:PUR-PO:CREATE')) {
            $actions[] = 'CREATE_PO';
        }

        return $actions;
    }

    private function approvedRequisitions(array $scope): array
    {
        return DB::table('requisitions as requisition')
            ->where('requisition.company_id', $scope['company_id'])
            ->where('requisition.plant_id', $scope['plant_id'])
            ->where('requisition.status', 'APPROVED')
            ->whereNotExists(fn (Builder $query) => $query->from('requests_for_quotation as rfq')
                ->whereColumn('rfq.requisition_id', 'requisition.id')
                ->where('rfq.status', '<>', 'CANCELLED'))
            ->orderBy('requisition.required_by_date')->orderBy('requisition.requisition_number')
            ->get([
                'requisition.id', 'requisition.requisition_number', 'requisition.department',
                'requisition.purpose', 'requisition.required_by_date', 'requisition.currency',
                'requisition.estimated_total', 'requisition.record_version',
            ])->map(fn (object $requisition): array => [
                'id' => (string) $requisition->id,
                'number' => $requisition->requisition_number,
                'department' => $requisition->department,
                'purpose' => $requisition->purpose,
                'required_by_date' => (string) $requisition->required_by_date,
                'currency' => $requisition->currency,
                'estimated_total' => $this->decimal($requisition->estimated_total),
                'record_version' => (int) $requisition->record_version,
            ])->all();
    }

    private function suppliers(array $scope): array
    {
        return DB::table('parties as supplier')
            ->join('party_roles as role', function ($join): void {
                $join->on('role.party_id', '=', 'supplier.id')
                    ->on('role.company_id', '=', 'supplier.company_id');
            })
            ->leftJoin('party_commercial_terms as terms', 'terms.party_id', '=', 'supplier.id')
            ->where('supplier.company_id', $scope['company_id'])
            ->where('supplier.status', 'ACTIVE')->where('role.role_code', 'SUPPLIER')
            ->orderBy('supplier.display_name')
            ->get([
                'supplier.id', 'supplier.code', 'supplier.display_name',
                'terms.currency_code', 'terms.payment_terms_days', 'terms.incoterm_code',
            ])->map(fn (object $supplier): array => [
                'id' => (string) $supplier->id,
                'code' => $supplier->code,
                'name' => $supplier->display_name,
                'currency' => $supplier->currency_code,
                'payment_terms_days' => $supplier->payment_terms_days === null
                    ? null : (int) $supplier->payment_terms_days,
                'incoterm_code' => $supplier->incoterm_code,
            ])->all();
    }

    private function supplierResponses(string $rfqId, Collection $quotes): array
    {
        $quotesBySupplier = $quotes->keyBy(fn (array $quote): string => $quote['supplier']['id']);

        return DB::table('rfq_suppliers as invitation')
            ->join('parties as supplier', 'supplier.id', '=', 'invitation.supplier_party_id')
            ->where('invitation.rfq_id', $rfqId)->orderBy('supplier.display_name')
            ->get([
                'invitation.supplier_party_id', 'invitation.status', 'invitation.invited_at',
                'invitation.responded_at', 'supplier.code', 'supplier.display_name',
            ])->map(fn (object $row): array => [
                'supplier' => [
                    'id' => (string) $row->supplier_party_id,
                    'code' => $row->code,
                    'name' => $row->display_name,
                ],
                'status' => $row->status,
                'invited_at' => $this->timestamp($row->invited_at),
                'responded_at' => $this->timestamp($row->responded_at),
                'quote' => $quotesBySupplier->get((string) $row->supplier_party_id),
            ])->all();
    }

    private function comparison(Collection $quotes, string $requiredByDate): array
    {
        if ($quotes->isEmpty()) {
            return [];
        }
        $lowest = $this->decimal($quotes->min(fn (array $quote): string => $quote['total_amount']));

        return $quotes->sortBy([
            ['total_amount', 'asc'],
            ['promised_delivery_date', 'asc'],
        ])->values()->map(fn (array $quote, int $index): array => [
            'rank' => $index + 1,
            'quote_id' => $quote['id'],
            'supplier' => $quote['supplier'],
            'total_amount' => $quote['total_amount'],
            'variance_from_lowest' => bcsub($quote['total_amount'], $lowest, 6),
            'promised_delivery_date' => $quote['promised_delivery_date'],
            'meets_required_date' => $quote['promised_delivery_date'] <= $requiredByDate,
            'payment_terms_days' => $quote['payment_terms_days'],
            'valid_until' => $quote['valid_until'],
        ])->all();
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $query) use ($needle): void {
                foreach ([
                    'rfq.rfq_number', 'requisition.requisition_number',
                    'requisition.department', 'requisition.purpose',
                    'awarded_supplier.display_name',
                ] as $column) {
                    $query->orWhereRaw("LOWER(COALESCE({$column}, '')) LIKE ?", [$needle]);
                }
            });
        }
        if (! empty($filters['status'])) {
            $query->where('rfq.status', $filters['status']);
        }
        if (! empty($filters['response_from'])) {
            $query->whereDate('rfq.response_due_date', '>=', $filters['response_from']);
        }
        if (! empty($filters['response_to'])) {
            $query->whereDate('rfq.response_due_date', '<=', $filters['response_to']);
        }
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'OLDEST' => $query->orderBy('rfq.created_at')->orderBy('rfq.id'),
            'NUMBER' => $query->orderBy('rfq.rfq_number')->orderBy('rfq.id'),
            'RESPONSE_DUE' => $query->orderBy('rfq.response_due_date')->orderBy('rfq.id'),
            'STATUS' => $query->orderBy('rfq.status')->orderByDesc('rfq.updated_at'),
            'VALUE' => $query->orderByDesc('rfq.estimated_total_snapshot')->orderBy('rfq.id'),
            default => $query->orderByDesc('rfq.updated_at')->orderByDesc('rfq.id'),
        };
    }

    private function meta(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(),
            'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(),
            'total' => $page->total(),
        ];
    }

    private function can(array $permissions, string $permission): bool
    {
        return in_array($permission, $permissions, true);
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 6);
    }
}

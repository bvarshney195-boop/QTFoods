<?php

namespace App\Modules\Procurement\Application;

use App\Shared\Visibility\OperationalVisibilityScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class PurchaseOrderQuery
{
    public const SORTS = ['NEWEST', 'OLDEST', 'NUMBER', 'REQUIRED_DATE', 'STATUS', 'VALUE', 'SUPPLIER'];

    public function __construct(private readonly OperationalVisibilityScope $visibility) {}

    public function workspace(array $scope, array $filters, array $permissions): array
    {
        $base = $this->base($scope, $permissions, $filters['visibility'] ?? null);
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
                'draft' => (clone $base)->where('po.status', 'DRAFT')->count(),
                'issued' => (clone $base)->where('po.status', 'ISSUED')->count(),
                'cancelled' => (clone $base)->where('po.status', 'CANCELLED')->count(),
                'committed_total' => $this->committedTotal($scope, $permissions, $filters['visibility'] ?? null),
            ],
            'lookups' => [
                'statuses' => PurchaseOrderService::STATUSES,
                'sorts' => self::SORTS,
                'awarded_rfqs' => $this->awardedRfqs($scope),
                'currency' => 'INR',
                'visibility_filters' => $this->visibility->filterOptions($permissions),
            ],
            'operational_view' => $this->visibility->viewDescriptor($permissions),
            'allowed_actions' => $this->can($permissions, 'ACTION:PUR-PO:CREATE') ? ['CREATE'] : [],
        ];
    }

    public function detail(string $purchaseOrderId, array $scope, array $permissions): array
    {
        $row = $this->base($scope, $permissions)->where('po.id', $purchaseOrderId)->first();
        if (! $row) {
            throw new NotFoundHttpException('Purchase order not found.');
        }
        $payload = $this->payload($row, $permissions);
        $payload['lines'] = DB::table('purchase_order_lines as line')
            ->join('items as item', 'item.id', '=', 'line.item_id')
            ->where('line.purchase_order_id', $purchaseOrderId)->orderBy('line.line_number')
            ->get([
                'line.*', 'item.code as item_code', 'item.name as item_name',
                'item.item_type', 'item.status as item_status',
            ])->map(fn (object $line): array => [
                'id' => (string) $line->id,
                'supplier_quote_line_id' => (string) $line->supplier_quote_line_id,
                'rfq_line_id' => (string) $line->rfq_line_id,
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
                'ordered_quantity' => $this->decimal($line->ordered_quantity),
                'uom_code' => $line->uom_code,
                'unit_price' => $this->decimal($line->unit_price),
                'line_total' => $this->decimal($line->line_total),
                'notes' => $line->notes,
            ])->all();
        $payload['revisions'] = DB::table('purchase_order_revisions as revision')
            ->join('users as actor', 'actor.id', '=', 'revision.created_by')
            ->where('revision.purchase_order_id', $purchaseOrderId)
            ->orderByDesc('revision.revision_number')
            ->get([
                'revision.id', 'revision.revision_number', 'revision.reason', 'revision.snapshot',
                'revision.created_by', 'revision.created_at', 'actor.name as actor_name',
            ])->map(fn (object $revision): array => [
                'id' => (string) $revision->id,
                'revision_number' => (int) $revision->revision_number,
                'reason' => $revision->reason,
                'snapshot' => is_string($revision->snapshot)
                    ? json_decode($revision->snapshot, true, flags: JSON_THROW_ON_ERROR)
                    : (array) $revision->snapshot,
                'created_by' => ['id' => (string) $revision->created_by, 'name' => $revision->actor_name],
                'created_at' => $this->timestamp($revision->created_at),
            ])->all();

        return $payload;
    }

    private function base(array $scope, array $permissions, ?string $visibilityFilter = null): Builder
    {
        $query = DB::table('purchase_orders as po')
            ->join('requests_for_quotation as rfq', 'rfq.id', '=', 'po.rfq_id')
            ->join('requisitions as requisition', 'requisition.id', '=', 'po.requisition_id')
            ->join('parties as supplier', 'supplier.id', '=', 'po.supplier_party_id')
            ->join('users as creator', 'creator.id', '=', 'po.created_by')
            ->leftJoin('users as issuer', 'issuer.id', '=', 'po.issued_by')
            ->leftJoin('users as canceller', 'canceller.id', '=', 'po.cancelled_by')
            ->whereNotNull('po.po_number')
            ->where('po.company_id', $scope['company_id'])
            ->where('po.plant_id', $scope['plant_id'])
            ->select([
                'po.*', 'rfq.rfq_number', 'rfq.estimated_total_snapshot',
                'requisition.requisition_number', 'requisition.department', 'requisition.purpose',
                'supplier.code as supplier_code', 'supplier.display_name as supplier_name',
                'creator.name as creator_name', 'issuer.name as issuer_name',
                'canceller.name as canceller_name',
            ])->selectSub(fn (Builder $query) => $query->from('purchase_order_lines as line')
                ->whereColumn('line.purchase_order_id', 'po.id')->selectRaw('COUNT(*)'), 'line_count');

        return $this->visibility->apply(
            $query,
            'PURCHASE_ORDER',
            'po.id',
            $permissions,
            $visibilityFilter,
            'po_visibility',
        );
    }

    private function payload(object $row, array $permissions): array
    {
        return [
            'id' => (string) $row->id,
            'company_id' => (string) $row->company_id,
            'plant_id' => (string) $row->plant_id,
            'po_number' => $row->po_number,
            'rfq' => [
                'id' => (string) $row->rfq_id,
                'number' => $row->rfq_number,
                'authority_ceiling' => $this->decimal($row->estimated_total_snapshot),
            ],
            'requisition' => [
                'id' => (string) $row->requisition_id,
                'number' => $row->requisition_number,
                'department' => $row->department,
                'purpose' => $row->purpose,
            ],
            'supplier' => [
                'id' => (string) $row->supplier_party_id,
                'code' => $row->supplier_code,
                'name' => $row->supplier_name,
            ],
            'supplier_quote_id' => (string) $row->supplier_quote_id,
            'order_date' => (string) $row->order_date,
            'required_by_date' => (string) $row->required_by_date,
            'currency' => $row->currency,
            'subtotal' => $this->decimal($row->subtotal),
            'freight_amount' => $this->decimal($row->freight_amount),
            'other_charges' => $this->decimal($row->other_charges),
            'discount_amount' => $this->decimal($row->discount_amount),
            'total_amount' => $this->decimal($row->total_amount),
            'payment_terms_days' => (int) $row->payment_terms_days,
            'incoterm_code' => $row->incoterm_code,
            'delivery_terms' => $row->delivery_terms,
            'notes' => $row->notes,
            'status' => $row->status,
            'record_version' => (int) $row->record_version,
            'revision_number' => (int) $row->revision_number,
            'line_count' => (int) $row->line_count,
            'created_by' => ['id' => (string) $row->created_by, 'name' => $row->creator_name],
            'issued_at' => $this->timestamp($row->issued_at),
            'issued_by' => $row->issued_by ? ['id' => (string) $row->issued_by, 'name' => $row->issuer_name] : null,
            'cancelled_at' => $this->timestamp($row->cancelled_at),
            'cancelled_by' => $row->cancelled_by ? [
                'id' => (string) $row->cancelled_by, 'name' => $row->canceller_name,
            ] : null,
            'cancellation_reason' => $row->cancellation_reason,
            'visibility' => $this->visibility->metadata($row),
            'allowed_actions' => $this->allowedActions($row, $permissions),
            'created_at' => $this->timestamp($row->created_at),
            'updated_at' => $this->timestamp($row->updated_at),
        ];
    }

    private function committedTotal(array $scope, array $permissions, ?string $visibilityFilter): string
    {
        $query = DB::table('purchase_orders as committed_po')
            ->whereNotNull('committed_po.po_number')
            ->where('committed_po.company_id', $scope['company_id'])
            ->where('committed_po.plant_id', $scope['plant_id'])
            ->where('committed_po.status', '<>', 'CANCELLED');
        $this->visibility->apply(
            $query,
            'PURCHASE_ORDER',
            'committed_po.id',
            $permissions,
            $visibilityFilter,
            'committed_po_visibility',
            false,
        );

        return $this->decimal($query->sum('committed_po.total_amount'));
    }

    private function allowedActions(object $row, array $permissions): array
    {
        $actions = [];
        if (in_array($row->status, ['DRAFT', 'ISSUED'], true)
            && $this->can($permissions, 'ACTION:PUR-PO:AMEND')) {
            $actions[] = 'AMEND';
        }
        if ($row->status === 'DRAFT' && $this->can($permissions, 'ACTION:PUR-PO:ISSUE')) {
            $actions[] = 'ISSUE';
        }
        if (in_array($row->status, ['DRAFT', 'ISSUED'], true)
            && $this->can($permissions, 'ACTION:PUR-PO:CANCEL')) {
            $actions[] = 'CANCEL';
        }

        return $actions;
    }

    private function awardedRfqs(array $scope): array
    {
        return DB::table('requests_for_quotation as rfq')
            ->join('requisitions as requisition', 'requisition.id', '=', 'rfq.requisition_id')
            ->join('supplier_quotes as quote', 'quote.id', '=', 'rfq.awarded_quote_id')
            ->join('parties as supplier', 'supplier.id', '=', 'rfq.awarded_supplier_id')
            ->where('rfq.company_id', $scope['company_id'])
            ->where('rfq.plant_id', $scope['plant_id'])
            ->where('rfq.status', 'AWARDED')
            ->whereNotExists(fn (Builder $query) => $query->from('purchase_orders as po')
                ->whereColumn('po.rfq_id', 'rfq.id')->whereNotNull('po.po_number')
                ->where('po.status', '<>', 'CANCELLED'))
            ->orderBy('rfq.awarded_at')->orderBy('rfq.rfq_number')
            ->get([
                'rfq.id', 'rfq.rfq_number', 'rfq.requisition_id', 'rfq.required_by_date',
                'rfq.estimated_total_snapshot', 'requisition.requisition_number',
                'supplier.id as supplier_id', 'supplier.code as supplier_code',
                'supplier.display_name as supplier_name', 'quote.id as quote_id',
                'quote.total_amount', 'quote.promised_delivery_date', 'quote.payment_terms_days',
            ])->map(fn (object $rfq): array => [
                'id' => (string) $rfq->id,
                'number' => $rfq->rfq_number,
                'requisition_id' => (string) $rfq->requisition_id,
                'requisition_number' => $rfq->requisition_number,
                'supplier' => [
                    'id' => (string) $rfq->supplier_id,
                    'code' => $rfq->supplier_code,
                    'name' => $rfq->supplier_name,
                ],
                'quote_id' => (string) $rfq->quote_id,
                'total_amount' => $this->decimal($rfq->total_amount),
                'authority_ceiling' => $this->decimal($rfq->estimated_total_snapshot),
                'promised_delivery_date' => (string) $rfq->promised_delivery_date,
                'payment_terms_days' => (int) $rfq->payment_terms_days,
            ])->all();
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $query) use ($needle): void {
                foreach ([
                    'po.po_number', 'rfq.rfq_number', 'requisition.requisition_number',
                    'supplier.code', 'supplier.display_name',
                ] as $column) {
                    $query->orWhereRaw("LOWER(COALESCE({$column}, '')) LIKE ?", [$needle]);
                }
            });
        }
        if (! empty($filters['status'])) {
            $query->where('po.status', $filters['status']);
        }
        if (! empty($filters['supplier_party_id'])) {
            $query->where('po.supplier_party_id', $filters['supplier_party_id']);
        }
        if (! empty($filters['required_from'])) {
            $query->whereDate('po.required_by_date', '>=', $filters['required_from']);
        }
        if (! empty($filters['required_to'])) {
            $query->whereDate('po.required_by_date', '<=', $filters['required_to']);
        }
    }

    private function applySort(Builder $query, string $sort): void
    {
        match ($sort) {
            'OLDEST' => $query->orderBy('po.created_at')->orderBy('po.id'),
            'NUMBER' => $query->orderBy('po.po_number')->orderBy('po.id'),
            'REQUIRED_DATE' => $query->orderBy('po.required_by_date')->orderBy('po.id'),
            'STATUS' => $query->orderBy('po.status')->orderByDesc('po.updated_at'),
            'VALUE' => $query->orderByDesc('po.total_amount')->orderBy('po.id'),
            'SUPPLIER' => $query->orderBy('supplier.display_name')->orderBy('po.po_number'),
            default => $query->orderByDesc('po.updated_at')->orderByDesc('po.id'),
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

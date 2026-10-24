<?php

namespace App\Modules\Sales\Application;

use App\Shared\Visibility\OperationalVisibilityScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class UnsoldSalesReturnQuery
{
    public const STATUSES = [
        'REQUESTED',
        'IN_TRANSIT',
        'PARTIALLY_RECEIVED',
        'RETURN_QUARANTINE',
        'DISPOSITION_REVIEW',
        'LOSS_POSTED',
        'FINANCE_RESOLVED',
    ];

    public function __construct(private readonly OperationalVisibilityScope $visibility) {}

    public function paginate(array $scope, array $filters, array $permissions): array
    {
        $query = $this->scopedCases($scope)
            ->leftJoin('parties as p', function (JoinClause $join) {
                $join
                    ->on('p.id', '=', 'c.party_id')
                    ->on('p.company_id', '=', 'c.company_id');
            })
            ->leftJoin('users as maker', 'maker.id', '=', 'c.maker_id')
            ->select([
                'c.id',
                'c.party_id',
                'p.code as party_code',
                'p.display_name as party_name',
                'c.shipment_id',
                'c.status',
                'c.reason_code',
                'c.expected_return_date',
                'c.maker_id',
                'maker.name as maker_name',
                'c.record_version',
                'c.created_at',
                'c.updated_at',
            ]);
        $this->visibility->apply($query, 'UNSOLD_RETURN', 'c.id', $permissions, $filters['visibility'] ?? null, 'unsold_return_visibility');
        $this->visibility->apply($query, 'SHIPMENT', 'c.shipment_id', $permissions, null, 'unsold_shipment_visibility', false);
        $this->visibility->apply($query, 'SALES_ORDER', 'c.sales_order_id', $permissions, null, 'unsold_order_visibility', false);

        $this->applyFilters($query, $filters);

        [$sortColumn, $sortDirection] = $this->sort($filters['sort'] ?? '-created_at');
        $query->orderBy($sortColumn, $sortDirection)->orderBy('c.id', $sortDirection);

        $paginator = $query->paginate(
            (int) ($filters['per_page'] ?? 25),
            ['*'],
            'page',
            (int) ($filters['page'] ?? 1)
        );
        $paginator->appends(Arr::except($filters, ['page']));

        $rows = collect($paginator->items());
        $totals = $this->lineTotals($rows->pluck('id'));

        return [
            'data' => $rows
                ->map(fn (object $row) => $this->listItem($row, $totals->get((string) $row->id)))
                ->values()
                ->all(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
                'from' => $paginator->firstItem(),
                'to' => $paginator->lastItem(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
            'lookups' => ['visibility_filters' => $this->visibility->filterOptions($permissions)],
            'operational_view' => $this->visibility->viewDescriptor($permissions),
        ];
    }

    public function find(string $caseId, array $scope, array $permissions): ?array
    {
        $case = $this->scopedCases($scope)
            ->where('c.id', $caseId)
            ->leftJoin('parties as p', function (JoinClause $join) {
                $join
                    ->on('p.id', '=', 'c.party_id')
                    ->on('p.company_id', '=', 'c.company_id');
            })
            ->leftJoin('users as maker', 'maker.id', '=', 'c.maker_id')
            ->select([
                'c.*',
                'p.code as party_code',
                'p.display_name as party_name',
                'maker.name as maker_name',
            ]);
        $this->visibility->apply($case, 'UNSOLD_RETURN', 'c.id', $permissions, null, 'unsold_detail_visibility');
        $this->visibility->apply($case, 'SHIPMENT', 'c.shipment_id', $permissions, null, 'unsold_detail_shipment_visibility', false);
        $this->visibility->apply($case, 'SALES_ORDER', 'c.sales_order_id', $permissions, null, 'unsold_detail_order_visibility', false);
        $case = $case->first();

        if (! $case) {
            return null;
        }

        $lines = DB::table('unsold_return_lines as l')
            ->leftJoin('items as sku', function (JoinClause $join) use ($case) {
                $join
                    ->on('sku.id', '=', 'l.sku_id')
                    ->where('sku.company_id', (string) $case->company_id);
            })
            ->leftJoin('lots as lot', function (JoinClause $join) use ($case) {
                $join
                    ->on('lot.id', '=', 'l.fg_lot_id')
                    ->where('lot.company_id', (string) $case->company_id);
            })
            ->leftJoin('stock_positions as position', function (JoinClause $join) use ($case) {
                $join
                    ->on('position.id', '=', 'l.return_position_id')
                    ->where('position.company_id', (string) $case->company_id)
                    ->where('position.plant_id', (string) $case->plant_id);
            })
            ->where('l.return_case_id', $caseId)
            ->select([
                'l.*',
                'sku.code as sku_code',
                'sku.name as sku_name',
                'lot.internal_lot_code as lot_code',
                'position.location_id as return_location_id',
                'position.quality_status as return_quality_status',
            ])
            ->orderBy('l.created_at')
            ->orderBy('l.id')
            ->get()
            ->map(fn (object $line) => $this->line($line))
            ->values()
            ->all();

        $history = DB::table('unsold_return_status_history as history')
            ->leftJoin('users as actor', 'actor.id', '=', 'history.actor_id')
            ->where('history.return_case_id', $caseId)
            ->where('history.company_id', $scope['company_id'])
            ->when(
                $scope['plant_id'] ?? null,
                fn (Builder $query, string $plantId) => $query->where('history.plant_id', $plantId)
            )
            ->select([
                'history.id',
                'history.from_status',
                'history.to_status',
                'history.record_version',
                'history.actor_id',
                'actor.name as actor_name',
                'history.created_at',
            ])
            ->orderBy('history.record_version')
            ->orderBy('history.created_at')
            ->orderBy('history.id')
            ->get()
            ->map(fn (object $entry) => [
                'id' => (string) $entry->id,
                'from_status' => $entry->from_status,
                'to_status' => $entry->to_status,
                'record_version' => (int) $entry->record_version,
                'actor' => $this->reference($entry->actor_id, null, $entry->actor_name),
                'changed_at' => $this->timestamp($entry->created_at),
            ])
            ->values()
            ->all();

        $approval = DB::table('approval_requests as approval')
            ->leftJoin('users as approval_maker', 'approval_maker.id', '=', 'approval.maker_id')
            ->where('approval.entity_type', 'unsold_return_loss')
            ->where('approval.entity_id', $caseId)
            ->where('approval.company_id', $scope['company_id'])
            ->when(
                $scope['plant_id'] ?? null,
                fn (Builder $query, string $plantId) => $query->where('approval.plant_id', $plantId)
            )
            ->select([
                'approval.id',
                'approval.entity_version',
                'approval.record_version',
                'approval.maker_id',
                'approval_maker.name as maker_name',
                'approval.rule_code',
                'approval.status',
                'approval.summary_json',
                'approval.created_at',
                'approval.updated_at',
            ])
            ->orderByDesc('approval.created_at')
            ->orderByDesc('approval.id')
            ->first();

        $stockMovements = DB::table('stock_movements as movement')
            ->leftJoin('stock_positions as from_position', function (JoinClause $join) use ($case) {
                $join
                    ->on('from_position.id', '=', 'movement.from_position_id')
                    ->where('from_position.company_id', (string) $case->company_id)
                    ->where('from_position.plant_id', (string) $case->plant_id);
            })
            ->leftJoin('locations as from_location', function (JoinClause $join) use ($case) {
                $join
                    ->on('from_location.id', '=', 'from_position.location_id')
                    ->where('from_location.company_id', (string) $case->company_id)
                    ->where('from_location.plant_id', (string) $case->plant_id);
            })
            ->leftJoin('stock_positions as to_position', function (JoinClause $join) use ($case) {
                $join
                    ->on('to_position.id', '=', 'movement.to_position_id')
                    ->where('to_position.company_id', (string) $case->company_id)
                    ->where('to_position.plant_id', (string) $case->plant_id);
            })
            ->leftJoin('locations as to_location', function (JoinClause $join) use ($case) {
                $join
                    ->on('to_location.id', '=', 'to_position.location_id')
                    ->where('to_location.company_id', (string) $case->company_id)
                    ->where('to_location.plant_id', (string) $case->plant_id);
            })
            ->leftJoin('users as movement_actor', 'movement_actor.id', '=', 'movement.actor_id')
            ->where('movement.company_id', $scope['company_id'])
            ->where('movement.plant_id', $case->plant_id)
            ->where('movement.source_type', 'UNSOLD_RETURN')
            ->where('movement.source_id', $caseId)
            ->select([
                'movement.id',
                'movement.movement_type',
                'movement.source_version',
                'movement.quantity_base',
                'movement.uom_code',
                'movement.reason_code',
                'movement.event_at',
                'movement.posted_at',
                'movement.actor_id',
                'movement_actor.name as actor_name',
                'movement.from_position_id',
                'from_position.quality_status as from_quality_status',
                'from_position.location_id as from_location_id',
                'from_location.code as from_location_code',
                'from_location.name as from_location_name',
                'movement.to_position_id',
                'to_position.quality_status as to_quality_status',
                'to_position.location_id as to_location_id',
                'to_location.code as to_location_code',
                'to_location.name as to_location_name',
            ])
            ->orderBy('movement.posted_at')
            ->orderBy('movement.id')
            ->get()
            ->map(fn (object $movement) => [
                'id' => (string) $movement->id,
                'movement_type' => $movement->movement_type,
                'source_version' => $movement->source_version === null
                    ? null
                    : (int) $movement->source_version,
                'quantity' => (string) $movement->quantity_base,
                'uom_code' => $movement->uom_code,
                'from_position' => $this->positionReference(
                    $movement->from_position_id,
                    $movement->from_quality_status,
                    $movement->from_location_id,
                    $movement->from_location_code,
                    $movement->from_location_name,
                ),
                'to_position' => $this->positionReference(
                    $movement->to_position_id,
                    $movement->to_quality_status,
                    $movement->to_location_id,
                    $movement->to_location_code,
                    $movement->to_location_name,
                ),
                'actor' => $this->reference($movement->actor_id, null, $movement->actor_name),
                'reason_code' => $movement->reason_code,
                'event_at' => $this->timestamp($movement->event_at),
                'posted_at' => $this->timestamp($movement->posted_at),
            ])
            ->values()
            ->all();

        $invoiceRecord = $case->invoice_id
            ? DB::table('sales_invoice_financials as financial')
                ->join('invoices as invoice', function (JoinClause $join) {
                    $join
                        ->on('invoice.id', '=', 'financial.invoice_id')
                        ->on('invoice.company_id', '=', 'financial.company_id')
                        ->on('invoice.plant_id', '=', 'financial.plant_id');
                })
                ->where('financial.invoice_id', $case->invoice_id)
                ->where('financial.company_id', $scope['company_id'])
                ->where('financial.plant_id', $case->plant_id)
                ->where('financial.party_id', $case->party_id)
                ->where('financial.shipment_id', $case->shipment_id)
                ->first([
                    'financial.invoice_id',
                    'financial.invoice_number',
                    'invoice.status',
                    'financial.currency',
                    'financial.net_amount',
                    'financial.tax_amount',
                    'financial.gross_amount',
                    'financial.outstanding_amount',
                    'financial.issued_at',
                    'financial.record_version',
                ])
            : null;

        $financeActions = DB::table('unsold_return_finance_actions as finance_action')
            ->leftJoin('users as finance_actor', 'finance_actor.id', '=', 'finance_action.actor_id')
            ->where('finance_action.return_case_id', $caseId)
            ->where('finance_action.company_id', $scope['company_id'])
            ->where('finance_action.plant_id', $case->plant_id)
            ->select([
                'finance_action.id',
                'finance_action.action_type',
                'finance_action.case_record_version',
                'finance_action.invoice_id',
                'finance_action.reference_number',
                'finance_action.amount',
                'finance_action.currency',
                'finance_action.tax_code',
                'finance_action.balance_before',
                'finance_action.balance_after',
                'finance_action.notes',
                'finance_action.actor_id',
                'finance_actor.name as actor_name',
                'finance_action.posted_at',
            ])
            ->orderBy('finance_action.case_record_version')
            ->orderBy('finance_action.id')
            ->get()
            ->map(fn (object $action) => [
                'id' => (string) $action->id,
                'action_type' => $action->action_type,
                'case_record_version' => (int) $action->case_record_version,
                'invoice_id' => (string) $action->invoice_id,
                'reference_number' => $action->reference_number,
                'amount' => $action->amount === null ? null : (string) $action->amount,
                'currency' => $action->currency,
                'tax_code' => $action->tax_code,
                'balance_before' => $action->balance_before === null ? null : (string) $action->balance_before,
                'balance_after' => $action->balance_after === null ? null : (string) $action->balance_after,
                'notes' => $action->notes,
                'actor' => $this->reference($action->actor_id, null, $action->actor_name),
                'posted_at' => $this->timestamp($action->posted_at),
            ]);

        $financeActionTypes = $financeActions->pluck('action_type');
        $settlementType = $financeActionTypes->first(
            fn (string $type) => in_array($type, ['RECEIVABLE_ADJUSTMENT', 'REFUND', 'REPLACEMENT'], true)
        );
        $creditAction = $financeActions->firstWhere('action_type', 'CREDIT_NOTE');
        $taxAction = $financeActions->firstWhere('action_type', 'TAX_ADJUSTMENT');
        $creditTotal = $creditAction
            ? bcadd((string) $creditAction['amount'], (string) ($taxAction['amount'] ?? '0'), 4)
            : null;
        $financeStage = match (true) {
            $settlementType !== null => 'RESOLVED',
            $financeActionTypes->contains('TAX_ADJUSTMENT') => 'TAX_REVIEWED',
            $financeActionTypes->contains('CREDIT_NOTE') => 'CREDIT_NOTE_POSTED',
            $financeActionTypes->contains('INVOICE_LINK') => 'INVOICE_LINKED',
            $case->status === 'LOSS_POSTED' => 'NOT_STARTED',
            default => 'NOT_READY',
        };

        $evidence = DB::table('unsold_return_evidence as evidence')
            ->leftJoin('users as evidence_uploader', 'evidence_uploader.id', '=', 'evidence.uploaded_by')
            ->where('evidence.return_case_id', $caseId)
            ->where('evidence.company_id', $scope['company_id'])
            ->where('evidence.plant_id', $case->plant_id)
            ->select([
                'evidence.id',
                'evidence.category',
                'evidence.case_record_version',
                'evidence.original_name',
                'evidence.mime_type',
                'evidence.size_bytes',
                'evidence.sha256',
                'evidence.notes',
                'evidence.retention_policy',
                'evidence.retention_until',
                'evidence.legal_hold',
                'evidence.uploaded_by',
                'evidence_uploader.name as uploader_name',
                'evidence.upload_audit_event_id',
                'evidence.uploaded_at',
            ])
            ->orderBy('evidence.case_record_version')
            ->orderBy('evidence.id')
            ->get()
            ->map(fn (object $item) => [
                'id' => (string) $item->id,
                'category' => $item->category,
                'case_record_version' => (int) $item->case_record_version,
                'original_name' => $item->original_name,
                'mime_type' => $item->mime_type,
                'size_bytes' => (int) $item->size_bytes,
                'sha256' => $item->sha256,
                'notes' => $item->notes,
                'retention_policy' => $item->retention_policy,
                'retention_until' => CarbonImmutable::parse((string) $item->retention_until)->toDateString(),
                'legal_hold' => (bool) $item->legal_hold,
                'uploader' => $this->reference($item->uploaded_by, null, $item->uploader_name),
                'audit_event_id' => (string) $item->upload_audit_event_id,
                'uploaded_at' => $this->timestamp($item->uploaded_at),
            ])
            ->values()
            ->all();

        return [
            'id' => (string) $case->id,
            'company_id' => (string) $case->company_id,
            'plant_id' => (string) $case->plant_id,
            'party' => $this->reference($case->party_id, $case->party_code, $case->party_name),
            'sales_order_id' => $case->sales_order_id ? (string) $case->sales_order_id : null,
            'shipment_id' => (string) $case->shipment_id,
            'invoice_id' => $case->invoice_id ? (string) $case->invoice_id : null,
            'invoice' => $invoiceRecord ? [
                'id' => (string) $invoiceRecord->invoice_id,
                'number' => $invoiceRecord->invoice_number,
                'status' => $invoiceRecord->status,
                'currency' => $invoiceRecord->currency,
                'net_amount' => (string) $invoiceRecord->net_amount,
                'tax_amount' => (string) $invoiceRecord->tax_amount,
                'gross_amount' => (string) $invoiceRecord->gross_amount,
                'outstanding_amount' => (string) $invoiceRecord->outstanding_amount,
                'issued_at' => $this->timestamp($invoiceRecord->issued_at),
                'record_version' => (int) $invoiceRecord->record_version,
            ] : null,
            'status' => $case->status,
            'reason_code' => $case->reason_code,
            'expected_return_date' => $case->expected_return_date,
            'sales_note' => $case->sales_note,
            'maker' => $this->reference($case->maker_id, null, $case->maker_name),
            'received_at' => $this->timestamp($case->received_at),
            'loss_event_id' => $case->loss_event_id ? (string) $case->loss_event_id : null,
            'record_version' => (int) $case->record_version,
            'created_at' => $this->timestamp($case->created_at),
            'updated_at' => $this->timestamp($case->updated_at),
            'visibility' => $this->visibility->metadata($case),
            'lines' => $lines,
            'status_history' => $history,
            'stock_movements' => $stockMovements,
            'evidence' => $evidence,
            'finance' => [
                'stage' => $financeStage,
                'credit_total' => $creditTotal,
                'settlement_type' => $settlementType,
                'actions' => $financeActions->values()->all(),
            ],
            'approval' => $approval ? [
                'id' => (string) $approval->id,
                'entity_version' => (int) $approval->entity_version,
                'record_version' => (int) $approval->record_version,
                'maker' => $this->reference($approval->maker_id, null, $approval->maker_name),
                'rule_code' => $approval->rule_code,
                'status' => $approval->status,
                'summary' => $this->json($approval->summary_json),
                'created_at' => $this->timestamp($approval->created_at),
                'updated_at' => $this->timestamp($approval->updated_at),
            ] : null,
        ];
    }

    private function scopedCases(array $scope): Builder
    {
        return DB::table('unsold_return_cases as c')
            ->where('c.company_id', $scope['company_id'])
            ->when(
                $scope['plant_id'] ?? null,
                fn (Builder $query, string $plantId) => $query->where('c.plant_id', $plantId)
            );
    }

    private function applyFilters(Builder $query, array $filters): void
    {
        $query
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('c.status', $status))
            ->when($filters['party_id'] ?? null, fn (Builder $query, string $partyId) => $query->where('c.party_id', $partyId))
            ->when($filters['shipment_id'] ?? null, fn (Builder $query, string $shipmentId) => $query->where('c.shipment_id', $shipmentId))
            ->when($filters['created_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('c.created_at', '>=', $date))
            ->when($filters['created_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('c.created_at', '<=', $date))
            ->when($filters['expected_from'] ?? null, fn (Builder $query, string $date) => $query->whereDate('c.expected_return_date', '>=', $date))
            ->when($filters['expected_to'] ?? null, fn (Builder $query, string $date) => $query->whereDate('c.expected_return_date', '<=', $date));

        $search = trim((string) ($filters['q'] ?? ''));
        if ($search === '') {
            return;
        }

        $query->where(function (Builder $query) use ($search) {
            $pattern = '%'.strtolower($search).'%';
            $query
                ->whereRaw('LOWER(c.reason_code) LIKE ?', [$pattern])
                ->orWhereRaw('LOWER(p.code) LIKE ?', [$pattern])
                ->orWhereRaw('LOWER(p.display_name) LIKE ?', [$pattern]);

            if (Str::isUuid($search)) {
                $query->orWhere('c.id', $search)->orWhere('c.shipment_id', $search);
            }
        });
    }

    private function sort(string $sort): array
    {
        $direction = str_starts_with($sort, '-') ? 'desc' : 'asc';
        $field = ltrim($sort, '-');
        $columns = [
            'created_at' => 'c.created_at',
            'expected_return_date' => 'c.expected_return_date',
            'status' => 'c.status',
        ];

        return [$columns[$field], $direction];
    }

    private function lineTotals(Collection $caseIds): Collection
    {
        if ($caseIds->isEmpty()) {
            return collect();
        }

        return DB::table('unsold_return_lines')
            ->whereIn('return_case_id', $caseIds->all())
            ->selectRaw(
                'return_case_id, COUNT(*) as line_count, '
                .'SUM(requested_quantity) as requested_quantity, '
                .'SUM(received_quantity) as received_quantity, '
                .'SUM(destroy_quantity) as destroy_quantity'
            )
            ->groupBy('return_case_id')
            ->get()
            ->keyBy(fn (object $row) => (string) $row->return_case_id);
    }

    private function listItem(object $row, ?object $totals): array
    {
        return [
            'id' => (string) $row->id,
            'party' => $this->reference($row->party_id, $row->party_code, $row->party_name),
            'shipment_id' => (string) $row->shipment_id,
            'status' => $row->status,
            'reason_code' => $row->reason_code,
            'expected_return_date' => $row->expected_return_date,
            'maker' => $this->reference($row->maker_id, null, $row->maker_name),
            'record_version' => (int) $row->record_version,
            'line_count' => (int) ($totals->line_count ?? 0),
            'quantities' => [
                'requested' => (string) ($totals->requested_quantity ?? '0'),
                'received' => (string) ($totals->received_quantity ?? '0'),
                'destroy' => (string) ($totals->destroy_quantity ?? '0'),
            ],
            'created_at' => $this->timestamp($row->created_at),
            'updated_at' => $this->timestamp($row->updated_at),
            'visibility' => $this->visibility->metadata($row),
        ];
    }

    private function line(object $line): array
    {
        return [
            'id' => (string) $line->id,
            'shipment_line_id' => $line->shipment_line_id ? (string) $line->shipment_line_id : null,
            'sku' => $this->reference($line->sku_id, $line->sku_code, $line->sku_name),
            'fg_lot' => $line->fg_lot_id ? $this->reference($line->fg_lot_id, $line->lot_code, null) : null,
            'requested_quantity' => (string) $line->requested_quantity,
            'received_quantity' => (string) $line->received_quantity,
            'restock_quantity' => (string) $line->restock_quantity,
            'repack_quantity' => (string) $line->repack_quantity,
            'rework_quantity' => (string) $line->rework_quantity,
            'destroy_quantity' => (string) $line->destroy_quantity,
            'uom_code' => $line->uom_code,
            'return_position' => $line->return_position_id ? [
                'id' => (string) $line->return_position_id,
                'location_id' => $line->return_location_id ? (string) $line->return_location_id : null,
                'quality_status' => $line->return_quality_status,
            ] : null,
            'quality_reason_code' => $line->quality_reason_code,
            'quality_reviewer_id' => $line->quality_reviewer_id ? (string) $line->quality_reviewer_id : null,
        ];
    }

    private function reference(mixed $id, ?string $code, ?string $name): array
    {
        return [
            'id' => (string) $id,
            'code' => $code,
            'name' => $name,
        ];
    }

    private function positionReference(
        mixed $positionId,
        ?string $qualityStatus,
        mixed $locationId,
        ?string $locationCode,
        ?string $locationName,
    ): ?array {
        if (! $positionId) {
            return null;
        }

        return [
            'id' => (string) $positionId,
            'quality_status' => $qualityStatus,
            'location' => $locationId
                ? $this->reference($locationId, $locationCode, $locationName)
                : null,
        ];
    }

    private function json(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        $decoded = json_decode($value, true);

        return json_last_error() === JSON_ERROR_NONE ? $decoded : null;
    }

    private function timestamp(mixed $value): ?string
    {
        return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString();
    }
}

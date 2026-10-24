<?php

namespace App\Modules\Procurement\Application;

use App\Shared\Visibility\OperationalVisibilityScope;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class InboundProcurementQuery
{
    public function __construct(private readonly OperationalVisibilityScope $visibility) {}

    public function gateWorkspace(array $scope, array $filters, array $permissions): array
    {
        $base = $this->gateBase($scope, $permissions, $filters['visibility'] ?? null);
        $page = $this->page($base, $filters, ['gate.gate_entry_number', 'gate.vehicle_number', 'po.po_number', 'supplier.display_name'], 'gate.status');

        return [
            'data' => collect($page->items())->map(fn (object $row) => $this->gatePayload($row, $permissions))->all(),
            'meta' => $this->meta($page),
            'summary' => $this->statusSummary($base, 'gate.status', InboundProcurementService::GATE_STATUSES),
            'lookups' => ['statuses' => InboundProcurementService::GATE_STATUSES, 'issued_orders' => $this->issuedOrders($scope, $permissions), 'visibility_filters' => $this->visibility->filterOptions($permissions)],
            'operational_view' => $this->visibility->viewDescriptor($permissions),
            'allowed_actions' => $this->can($permissions, 'ACTION:INB-GATE:CREATE') ? ['CREATE'] : [],
        ];
    }

    public function gateDetail(string $id, array $scope, array $permissions): array
    {
        $query = $this->gateBase($scope, $permissions)
            ->leftJoin('receipts as receipt', 'receipt.gate_entry_id', '=', 'gate.id')
            ->addSelect(['receipt.id as receipt_id', 'receipt.receipt_number', 'receipt.status as receipt_status']);
        $row = $query->where('gate.id', $id)->first();
        if (! $row) {
            throw new NotFoundHttpException('Gate entry not found.');
        }
        $payload = $this->gatePayload($row, $permissions);
        $payload['receipt'] = $row->receipt_id ? ['id' => (string) $row->receipt_id, 'number' => $row->receipt_number, 'status' => $row->receipt_status] : null;

        return $payload;
    }

    public function receiptWorkspace(array $scope, array $filters, array $permissions): array
    {
        $base = $this->receiptBase($scope, $permissions, $filters['visibility'] ?? null);
        $page = $this->page($base, $filters, ['receipt.receipt_number', 'gate.gate_entry_number', 'po.po_number', 'supplier.display_name'], 'receipt.status');

        return [
            'data' => collect($page->items())->map(fn (object $row) => $this->receiptPayload($row, $permissions))->all(),
            'meta' => $this->meta($page),
            'summary' => $this->statusSummary($base, 'receipt.status', InboundProcurementService::RECEIPT_STATUSES),
            'lookups' => [
                'statuses' => InboundProcurementService::RECEIPT_STATUSES,
                'arrived_gate_entries' => $this->arrivedGates($scope, $permissions),
                'quality_hold_locations' => $this->locations($scope, true),
                'released_locations' => $this->locations($scope, false),
                'visibility_filters' => $this->visibility->filterOptions($permissions),
            ],
            'operational_view' => $this->visibility->viewDescriptor($permissions),
            'allowed_actions' => $this->can($permissions, 'ACTION:INB-GRN:CREATE') ? ['CREATE'] : [],
        ];
    }

    public function receiptDetail(string $id, array $scope, array $permissions): array
    {
        $row = $this->receiptBase($scope, $permissions)->where('receipt.id', $id)->first();
        if (! $row) {
            throw new NotFoundHttpException('GRN not found.');
        }
        $payload = $this->receiptPayload($row, $permissions);
        $payload['lines'] = DB::table('receipt_lines as line')
            ->join('items as item', 'item.id', '=', 'line.item_id')
            ->join('locations as hold_location', 'hold_location.id', '=', 'line.quality_hold_location_id')
            ->join('locations as release_location', 'release_location.id', '=', 'line.released_location_id')
            ->leftJoin('lots as lot', 'lot.id', '=', 'line.lot_id')
            ->where('line.receipt_id', $id)->orderBy('line.line_number')
            ->get(['line.*', 'item.code as item_code', 'item.name as item_name',
                'hold_location.code as hold_location_code', 'release_location.code as release_location_code',
                'lot.status as lot_status'])
            ->map(fn (object $line) => [
                'id' => (string) $line->id, 'purchase_order_line_id' => (string) $line->purchase_order_line_id,
                'line_number' => (int) $line->line_number,
                'item' => ['id' => (string) $line->item_id, 'code' => $line->item_code, 'name' => $line->item_name],
                'description' => $line->description,
                'ordered_quantity' => $this->decimal($line->ordered_quantity_snapshot),
                'received_quantity' => $this->decimal($line->received_quantity),
                'accepted_quantity' => $this->decimal($line->accepted_quantity),
                'rejected_quantity' => $this->decimal($line->rejected_quantity), 'uom_code' => $line->uom_code,
                'internal_lot_code' => $line->internal_lot_code, 'supplier_lot_code' => $line->supplier_lot_code,
                'manufacture_date' => $line->manufacture_date, 'expiry_date' => $line->expiry_date,
                'quality_hold_location' => ['id' => (string) $line->quality_hold_location_id, 'code' => $line->hold_location_code],
                'released_location' => ['id' => (string) $line->released_location_id, 'code' => $line->release_location_code],
                'lot_id' => $line->lot_id ? (string) $line->lot_id : null, 'lot_status' => $line->lot_status,
                'quality_hold_position_id' => $line->quality_hold_position_id ? (string) $line->quality_hold_position_id : null,
                'stock_receipt_movement_id' => $line->stock_receipt_movement_id ? (string) $line->stock_receipt_movement_id : null,
                'notes' => $line->notes,
            ])->all();
        $payload['quality_task'] = DB::table('quality_tasks')->where('receipt_id', $id)->whereNotNull('task_number')
            ->first(['id', 'task_number', 'status', 'record_version']);

        return $payload;
    }

    public function qualityWorkspace(array $scope, array $filters, array $permissions): array
    {
        $base = $this->qualityBase($scope, $permissions, $filters['visibility'] ?? null);
        $page = $this->page($base, $filters, ['task.task_number', 'receipt.receipt_number', 'po.po_number', 'supplier.display_name'], 'task.status');

        return [
            'data' => collect($page->items())->map(fn (object $row) => $this->qualityPayload($row, $permissions))->all(),
            'meta' => $this->meta($page),
            'summary' => $this->statusSummary($base, 'task.status', InboundProcurementService::QUALITY_STATUSES),
            'lookups' => ['statuses' => InboundProcurementService::QUALITY_STATUSES, 'visibility_filters' => $this->visibility->filterOptions($permissions)],
            'operational_view' => $this->visibility->viewDescriptor($permissions),
            'allowed_actions' => [],
        ];
    }

    public function qualityDetail(string $id, array $scope, array $permissions): array
    {
        $row = $this->qualityBase($scope, $permissions)->where('task.id', $id)->first();
        if (! $row) {
            throw new NotFoundHttpException('Incoming QC task not found.');
        }
        $payload = $this->qualityPayload($row, $permissions);
        $payload['lines'] = DB::table('incoming_quality_lines as line')
            ->join('items as item', 'item.id', '=', 'line.item_id')
            ->join('lots as lot', 'lot.id', '=', 'line.lot_id')
            ->where('line.quality_task_id', $id)->orderBy('line.line_number')
            ->get(['line.*', 'item.code as item_code', 'item.name as item_name', 'lot.internal_lot_code', 'lot.supplier_lot_code'])
            ->map(fn (object $line) => [
                'id' => (string) $line->id, 'line_number' => (int) $line->line_number,
                'item' => ['id' => (string) $line->item_id, 'code' => $line->item_code, 'name' => $line->item_name],
                'lot' => ['id' => (string) $line->lot_id, 'internal_code' => $line->internal_lot_code, 'supplier_code' => $line->supplier_lot_code],
                'inspected_quantity' => $this->decimal($line->inspected_quantity),
                'accepted_quantity' => $this->decimal($line->accepted_quantity),
                'rejected_quantity' => $this->decimal($line->rejected_quantity),
                'uom_code' => $line->uom_code, 'result' => $line->result,
                'rejection_reason' => $line->rejection_reason,
                'quality_hold_position_id' => (string) $line->quality_hold_position_id,
                'released_position_id' => $line->released_position_id ? (string) $line->released_position_id : null,
                'rejected_position_id' => $line->rejected_position_id ? (string) $line->rejected_position_id : null,
                'accepted_movement_id' => $line->accepted_movement_id ? (string) $line->accepted_movement_id : null,
                'rejected_movement_id' => $line->rejected_movement_id ? (string) $line->rejected_movement_id : null,
            ])->all();

        return $payload;
    }

    public function supplierReturnWorkspace(array $scope, array $filters, array $permissions): array
    {
        $base = $this->supplierReturnBase($scope, $permissions, $filters['visibility'] ?? null);
        $page = $this->page($base, $filters, ['supplier_return.return_number', 'supplier.display_name', 'supplier_return.reason'], 'supplier_return.status');

        return [
            'data' => collect($page->items())->map(fn (object $row) => $this->supplierReturnPayload($row, $permissions))->all(),
            'meta' => $this->meta($page),
            'summary' => $this->statusSummary($base, 'supplier_return.status', InboundProcurementService::RETURN_STATUSES),
            'lookups' => ['statuses' => InboundProcurementService::RETURN_STATUSES, 'rejected_lines' => $this->rejectedLines($scope, $permissions), 'visibility_filters' => $this->visibility->filterOptions($permissions)],
            'operational_view' => $this->visibility->viewDescriptor($permissions),
            'allowed_actions' => $this->can($permissions, 'ACTION:INB-RETURN:CREATE') ? ['CREATE'] : [],
        ];
    }

    public function supplierReturnDetail(string $id, array $scope, array $permissions): array
    {
        $row = $this->supplierReturnBase($scope, $permissions)->where('supplier_return.id', $id)->first();
        if (! $row) {
            throw new NotFoundHttpException('Supplier return not found.');
        }
        $payload = $this->supplierReturnPayload($row, $permissions);
        $payload['lines'] = DB::table('supplier_return_lines as line')
            ->join('items as item', 'item.id', '=', 'line.item_id')
            ->join('lots as lot', 'lot.id', '=', 'line.lot_id')
            ->where('line.supplier_return_id', $id)->orderBy('line.line_number')
            ->get(['line.*', 'item.code as item_code', 'item.name as item_name', 'lot.internal_lot_code'])
            ->map(fn (object $line) => [
                'id' => (string) $line->id, 'quality_line_id' => (string) $line->incoming_quality_line_id,
                'line_number' => (int) $line->line_number,
                'item' => ['id' => (string) $line->item_id, 'code' => $line->item_code, 'name' => $line->item_name],
                'lot' => ['id' => (string) $line->lot_id, 'internal_code' => $line->internal_lot_code],
                'return_quantity' => $this->decimal($line->return_quantity), 'uom_code' => $line->uom_code,
                'rejected_position_id' => (string) $line->rejected_position_id,
                'movement_id' => $line->movement_id ? (string) $line->movement_id : null, 'reason' => $line->reason,
            ])->all();

        return $payload;
    }

    private function gateBase(array $scope, array $permissions, ?string $visibilityFilter = null): Builder
    {
        $query = DB::table('gate_entries as gate')
            ->join('purchase_orders as po', 'po.id', '=', 'gate.purchase_order_id')
            ->join('parties as supplier', 'supplier.id', '=', 'gate.supplier_party_id')
            ->join('users as creator', 'creator.id', '=', 'gate.created_by')
            ->where('gate.company_id', $scope['company_id'])->where('gate.plant_id', $scope['plant_id'])
            ->select(['gate.*', 'po.po_number', 'supplier.code as supplier_code', 'supplier.display_name as supplier_name', 'creator.name as creator_name']);
        $this->visibility->apply($query, 'GATE_ENTRY', 'gate.id', $permissions, $visibilityFilter, 'gate_visibility');
        $this->visibility->apply($query, 'PURCHASE_ORDER', 'po.id', $permissions, null, 'gate_po_visibility', false);

        return $query;
    }

    private function receiptBase(array $scope, array $permissions, ?string $visibilityFilter = null): Builder
    {
        $query = DB::table('receipts as receipt')
            ->join('gate_entries as gate', 'gate.id', '=', 'receipt.gate_entry_id')
            ->join('purchase_orders as po', 'po.id', '=', 'receipt.purchase_order_id')
            ->join('parties as supplier', 'supplier.id', '=', 'receipt.supplier_party_id')
            ->join('users as creator', 'creator.id', '=', 'receipt.created_by')
            ->whereNotNull('receipt.receipt_number')
            ->where('receipt.company_id', $scope['company_id'])->where('receipt.plant_id', $scope['plant_id'])
            ->select(['receipt.*', 'gate.gate_entry_number', 'po.po_number',
                'supplier.code as supplier_code', 'supplier.display_name as supplier_name', 'creator.name as creator_name'])
            ->selectSub(fn (Builder $q) => $q->from('receipt_lines as line')->whereColumn('line.receipt_id', 'receipt.id')->selectRaw('COUNT(*)'), 'line_count')
            ->selectSub(fn (Builder $q) => $q->from('receipt_lines as line')->whereColumn('line.receipt_id', 'receipt.id')->selectRaw('COALESCE(SUM(received_quantity), 0)'), 'received_total');
        $this->visibility->apply($query, 'GOODS_RECEIPT', 'receipt.id', $permissions, $visibilityFilter, 'receipt_visibility');
        $this->visibility->apply($query, 'GATE_ENTRY', 'gate.id', $permissions, null, 'receipt_gate_visibility', false);
        $this->visibility->apply($query, 'PURCHASE_ORDER', 'po.id', $permissions, null, 'receipt_po_visibility', false);

        return $query;
    }

    private function qualityBase(array $scope, array $permissions, ?string $visibilityFilter = null): Builder
    {
        $query = DB::table('quality_tasks as task')
            ->join('receipts as receipt', 'receipt.id', '=', 'task.receipt_id')
            ->join('purchase_orders as po', 'po.id', '=', 'receipt.purchase_order_id')
            ->join('parties as supplier', 'supplier.id', '=', 'receipt.supplier_party_id')
            ->join('users as creator', 'creator.id', '=', 'task.created_by')
            ->leftJoin('users as completer', 'completer.id', '=', 'task.completed_by')
            ->whereNotNull('task.task_number')->where('task.company_id', $scope['company_id'])->where('task.plant_id', $scope['plant_id'])
            ->select(['task.*', 'receipt.receipt_number', 'po.id as purchase_order_id', 'po.po_number',
                'supplier.id as supplier_id', 'supplier.code as supplier_code', 'supplier.display_name as supplier_name',
                'creator.name as creator_name', 'completer.name as completer_name'])
            ->selectSub(fn (Builder $q) => $q->from('incoming_quality_lines as line')->whereColumn('line.quality_task_id', 'task.id')->selectRaw('COUNT(*)'), 'line_count');
        $this->visibility->apply($query, 'INCOMING_QUALITY_TASK', 'task.id', $permissions, $visibilityFilter, 'quality_visibility');
        $this->visibility->apply($query, 'GOODS_RECEIPT', 'receipt.id', $permissions, null, 'quality_receipt_visibility', false);
        $this->visibility->apply($query, 'PURCHASE_ORDER', 'po.id', $permissions, null, 'quality_po_visibility', false);

        return $query;
    }

    private function supplierReturnBase(array $scope, array $permissions, ?string $visibilityFilter = null): Builder
    {
        $query = DB::table('supplier_returns as supplier_return')
            ->join('parties as supplier', 'supplier.id', '=', 'supplier_return.supplier_party_id')
            ->join('users as creator', 'creator.id', '=', 'supplier_return.created_by')
            ->where('supplier_return.company_id', $scope['company_id'])->where('supplier_return.plant_id', $scope['plant_id'])
            ->select(['supplier_return.*', 'supplier.code as supplier_code', 'supplier.display_name as supplier_name', 'creator.name as creator_name'])
            ->selectSub(fn (Builder $q) => $q->from('supplier_return_lines as line')->whereColumn('line.supplier_return_id', 'supplier_return.id')->selectRaw('COUNT(*)'), 'line_count')
            ->selectSub(fn (Builder $q) => $q->from('supplier_return_lines as line')->whereColumn('line.supplier_return_id', 'supplier_return.id')->selectRaw('COALESCE(SUM(return_quantity), 0)'), 'return_total');
        $this->visibility->apply($query, 'SUPPLIER_RETURN', 'supplier_return.id', $permissions, $visibilityFilter, 'supplier_return_visibility');

        return $this->visibility->excludeRestrictedSupplierReturnSources($query, 'supplier_return.id', $permissions);
    }

    private function gatePayload(object $row, array $permissions): array
    {
        $actions = [];
        if ($row->status === 'ARRIVED' && $this->can($permissions, 'ACTION:INB-GATE:UPDATE')) $actions[] = 'UPDATE';
        if ($row->status === 'ARRIVED' && $this->can($permissions, 'ACTION:INB-GATE:CANCEL')) $actions[] = 'CANCEL';
        if ($row->status === 'ARRIVED' && $this->can($permissions, 'ACTION:INB-GRN:CREATE')) $actions[] = 'CREATE_GRN';

        return [
            'id' => (string) $row->id, 'gate_entry_number' => $row->gate_entry_number,
            'purchase_order' => ['id' => (string) $row->purchase_order_id, 'number' => $row->po_number],
            'supplier' => ['id' => (string) $row->supplier_party_id, 'code' => $row->supplier_code, 'name' => $row->supplier_name],
            'vehicle_number' => $row->vehicle_number, 'transporter_name' => $row->transporter_name,
            'supplier_document_number' => $row->supplier_document_number,
            'arrived_at' => $this->timestamp($row->arrived_at), 'notes' => $row->notes,
            'status' => $row->status, 'record_version' => (int) $row->record_version,
            'created_by' => ['id' => (string) $row->created_by, 'name' => $row->creator_name],
            'cleared_at' => $this->timestamp($row->cleared_at), 'cancelled_at' => $this->timestamp($row->cancelled_at),
            'cancellation_reason' => $row->cancellation_reason, 'allowed_actions' => $actions,
            'visibility' => $this->visibility->metadata($row),
            'created_at' => $this->timestamp($row->created_at), 'updated_at' => $this->timestamp($row->updated_at),
        ];
    }

    private function receiptPayload(object $row, array $permissions): array
    {
        $actions = [];
        if ($row->status === 'DRAFT' && $this->can($permissions, 'ACTION:INB-GRN:UPDATE')) $actions[] = 'UPDATE';
        if ($row->status === 'DRAFT' && $this->can($permissions, 'ACTION:INB-GRN:POST')) $actions[] = 'POST';
        if ($row->status === 'DRAFT' && $this->can($permissions, 'ACTION:INB-GRN:CANCEL')) $actions[] = 'CANCEL';

        return [
            'id' => (string) $row->id, 'receipt_number' => $row->receipt_number,
            'gate_entry' => ['id' => (string) $row->gate_entry_id, 'number' => $row->gate_entry_number],
            'purchase_order' => ['id' => (string) $row->purchase_order_id, 'number' => $row->po_number],
            'supplier' => ['id' => (string) $row->supplier_party_id, 'code' => $row->supplier_code, 'name' => $row->supplier_name],
            'receipt_date' => (string) $row->receipt_date, 'supplier_document_number' => $row->supplier_document_number,
            'notes' => $row->notes, 'status' => $row->status, 'record_version' => (int) $row->record_version,
            'line_count' => (int) $row->line_count, 'received_total' => $this->decimal($row->received_total),
            'created_by' => ['id' => (string) $row->created_by, 'name' => $row->creator_name],
            'posted_at' => $this->timestamp($row->posted_at), 'cancelled_at' => $this->timestamp($row->cancelled_at),
            'cancellation_reason' => $row->cancellation_reason, 'allowed_actions' => $actions,
            'visibility' => $this->visibility->metadata($row),
            'created_at' => $this->timestamp($row->created_at), 'updated_at' => $this->timestamp($row->updated_at),
        ];
    }

    private function qualityPayload(object $row, array $permissions): array
    {
        return [
            'id' => (string) $row->id, 'task_number' => $row->task_number,
            'receipt' => ['id' => (string) $row->receipt_id, 'number' => $row->receipt_number],
            'purchase_order' => ['id' => (string) $row->purchase_order_id, 'number' => $row->po_number],
            'supplier' => ['id' => (string) $row->supplier_id, 'code' => $row->supplier_code, 'name' => $row->supplier_name],
            'status' => $row->status, 'record_version' => (int) $row->record_version,
            'line_count' => (int) $row->line_count, 'notes' => $row->notes,
            'created_by' => ['id' => (string) $row->created_by, 'name' => $row->creator_name],
            'completed_by' => $row->completed_by ? ['id' => (string) $row->completed_by, 'name' => $row->completer_name] : null,
            'completed_at' => $this->timestamp($row->completed_at),
            'allowed_actions' => $row->status === 'PENDING' && $this->can($permissions, 'ACTION:QC-IN:COMPLETE') ? ['COMPLETE'] : [],
            'visibility' => $this->visibility->metadata($row),
            'created_at' => $this->timestamp($row->created_at), 'updated_at' => $this->timestamp($row->updated_at),
        ];
    }

    private function supplierReturnPayload(object $row, array $permissions): array
    {
        $actions = [];
        if ($row->status === 'DRAFT' && $this->can($permissions, 'ACTION:INB-RETURN:UPDATE')) $actions[] = 'UPDATE';
        if ($row->status === 'DRAFT' && $this->can($permissions, 'ACTION:INB-RETURN:POST')) $actions[] = 'POST';
        if ($row->status === 'DRAFT' && $this->can($permissions, 'ACTION:INB-RETURN:CANCEL')) $actions[] = 'CANCEL';

        return [
            'id' => (string) $row->id, 'return_number' => $row->return_number,
            'supplier' => ['id' => (string) $row->supplier_party_id, 'code' => $row->supplier_code, 'name' => $row->supplier_name],
            'return_date' => (string) $row->return_date, 'reason' => $row->reason,
            'status' => $row->status, 'record_version' => (int) $row->record_version,
            'line_count' => (int) $row->line_count, 'return_total' => $this->decimal($row->return_total),
            'created_by' => ['id' => (string) $row->created_by, 'name' => $row->creator_name],
            'posted_at' => $this->timestamp($row->posted_at), 'cancelled_at' => $this->timestamp($row->cancelled_at),
            'cancellation_reason' => $row->cancellation_reason, 'allowed_actions' => $actions,
            'visibility' => $this->visibility->metadata($row),
            'created_at' => $this->timestamp($row->created_at), 'updated_at' => $this->timestamp($row->updated_at),
        ];
    }

    private function issuedOrders(array $scope, array $permissions): array
    {
        $query = DB::table('purchase_orders as po')->join('parties as supplier', 'supplier.id', '=', 'po.supplier_party_id')
            ->where('po.company_id', $scope['company_id'])->where('po.plant_id', $scope['plant_id'])->where('po.status', 'ISSUED')
            ->orderBy('po.required_by_date')->select(['po.id', 'po.po_number', 'po.required_by_date', 'supplier.id as supplier_id',
                'supplier.code as supplier_code', 'supplier.display_name as supplier_name'])
            ;
        $this->visibility->apply($query, 'PURCHASE_ORDER', 'po.id', $permissions, null, 'issued_po_visibility', false);

        return $query->get()
            ->map(fn (object $row) => ['id' => (string) $row->id, 'number' => $row->po_number,
                'required_by_date' => (string) $row->required_by_date,
                'supplier' => ['id' => (string) $row->supplier_id, 'code' => $row->supplier_code, 'name' => $row->supplier_name]])->all();
    }

    private function arrivedGates(array $scope, array $permissions): array
    {
        $query = DB::table('gate_entries as gate')->join('purchase_orders as po', 'po.id', '=', 'gate.purchase_order_id')
            ->join('parties as supplier', 'supplier.id', '=', 'gate.supplier_party_id')
            ->where('gate.company_id', $scope['company_id'])->where('gate.plant_id', $scope['plant_id'])
            ->where('gate.status', 'ARRIVED')->whereNotExists(fn (Builder $q) => $q->from('receipts as receipt')
                ->whereColumn('receipt.gate_entry_id', 'gate.id')->whereNotNull('receipt.receipt_number'))
            ->orderBy('gate.arrived_at')->select(['gate.id', 'gate.gate_entry_number', 'gate.purchase_order_id', 'po.po_number',
                'gate.supplier_document_number', 'supplier.id as supplier_id', 'supplier.code as supplier_code', 'supplier.display_name as supplier_name'])
            ;
        $this->visibility->apply($query, 'GATE_ENTRY', 'gate.id', $permissions, null, 'arrived_gate_visibility', false);
        $this->visibility->apply($query, 'PURCHASE_ORDER', 'po.id', $permissions, null, 'arrived_po_visibility', false);

        return $query->get()
            ->map(function (object $row): array {
                $lines = DB::table('purchase_order_lines as po_line')->join('items as item', 'item.id', '=', 'po_line.item_id')
                    ->where('po_line.purchase_order_id', $row->purchase_order_id)->orderBy('po_line.line_number')
                    ->get(['po_line.id', 'po_line.line_number', 'po_line.item_id', 'po_line.description', 'po_line.ordered_quantity',
                        'po_line.uom_code', 'item.code as item_code', 'item.name as item_name'])
                    ->map(function (object $line): array {
                        $received = $this->decimal(DB::table('receipt_lines as receipt_line')
                            ->join('receipts as receipt', 'receipt.id', '=', 'receipt_line.receipt_id')
                            ->where('receipt_line.purchase_order_line_id', $line->id)
                            ->whereIn('receipt.status', ['QC_PENDING', 'COMPLETED'])->sum('receipt_line.received_quantity'));
                        return ['id' => (string) $line->id, 'line_number' => (int) $line->line_number,
                            'item' => ['id' => (string) $line->item_id, 'code' => $line->item_code, 'name' => $line->item_name],
                            'description' => $line->description, 'ordered_quantity' => $this->decimal($line->ordered_quantity),
                            'received_quantity' => $received, 'remaining_quantity' => bcsub($this->decimal($line->ordered_quantity), $received, 6),
                            'uom_code' => $line->uom_code];
                    })->filter(fn (array $line) => bccomp($line['remaining_quantity'], '0', 6) > 0)->values()->all();
                return ['id' => (string) $row->id, 'number' => $row->gate_entry_number,
                    'purchase_order' => ['id' => (string) $row->purchase_order_id, 'number' => $row->po_number],
                    'supplier' => ['id' => (string) $row->supplier_id, 'code' => $row->supplier_code, 'name' => $row->supplier_name],
                    'supplier_document_number' => $row->supplier_document_number, 'lines' => $lines];
            })->filter(fn (array $gate) => count($gate['lines']) > 0)->values()->all();
    }

    private function locations(array $scope, bool $hold): array
    {
        $query = DB::table('locations')->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])->where('status', 'ACTIVE');
        $hold ? $query->where('location_type', 'QUALITY_HOLD') : $query->whereNotIn('location_type', ['QUALITY_HOLD', 'BLOCKED', 'RETURN_QUARANTINE']);
        return $query->orderBy('code')->get(['id', 'code', 'name', 'location_type'])
            ->map(fn (object $row) => ['id' => (string) $row->id, 'code' => $row->code, 'name' => $row->name, 'location_type' => $row->location_type])->all();
    }

    private function rejectedLines(array $scope, array $permissions): array
    {
        $query = DB::table('incoming_quality_lines as quality')
            ->join('quality_tasks as task', 'task.id', '=', 'quality.quality_task_id')
            ->join('receipts as receipt', 'receipt.id', '=', 'quality.receipt_id')
            ->join('items as item', 'item.id', '=', 'quality.item_id')->join('lots as lot', 'lot.id', '=', 'quality.lot_id')
            ->join('parties as supplier', 'supplier.id', '=', 'receipt.supplier_party_id')
            ->where('quality.company_id', $scope['company_id'])->where('quality.plant_id', $scope['plant_id'])
            ->where('task.status', 'COMPLETED')->where('quality.rejected_quantity', '>', 0)->whereNotNull('quality.rejected_position_id')
            ->orderBy('task.completed_at')->select(['quality.*', 'task.task_number', 'receipt.supplier_party_id', 'receipt.receipt_number',
                'item.code as item_code', 'item.name as item_name', 'lot.internal_lot_code',
                'supplier.code as supplier_code', 'supplier.display_name as supplier_name'])
            ;
        $this->visibility->apply($query, 'INCOMING_QUALITY_TASK', 'task.id', $permissions, null, 'rejected_quality_visibility', false);
        $this->visibility->apply($query, 'GOODS_RECEIPT', 'receipt.id', $permissions, null, 'rejected_receipt_visibility', false);

        return $query->get()
            ->map(function (object $row): array {
                $returned = $this->decimal(DB::table('supplier_return_lines as line')->join('supplier_returns as supplier_return', 'supplier_return.id', '=', 'line.supplier_return_id')
                    ->where('line.incoming_quality_line_id', $row->id)->where('supplier_return.status', 'POSTED')->sum('line.return_quantity'));
                return ['id' => (string) $row->id, 'task_number' => $row->task_number, 'receipt_number' => $row->receipt_number,
                    'supplier' => ['id' => (string) $row->supplier_party_id, 'code' => $row->supplier_code, 'name' => $row->supplier_name],
                    'item' => ['id' => (string) $row->item_id, 'code' => $row->item_code, 'name' => $row->item_name],
                    'lot' => ['id' => (string) $row->lot_id, 'internal_code' => $row->internal_lot_code],
                    'rejected_quantity' => $this->decimal($row->rejected_quantity), 'returned_quantity' => $returned,
                    'returnable_quantity' => bcsub($this->decimal($row->rejected_quantity), $returned, 6), 'uom_code' => $row->uom_code,
                    'rejected_position_id' => (string) $row->rejected_position_id];
            })->filter(fn (array $row) => bccomp($row['returnable_quantity'], '0', 6) > 0)->values()->all();
    }

    private function page(Builder $base, array $filters, array $searchColumns, string $statusColumn): LengthAwarePaginator
    {
        $query = clone $base;
        $search = trim((string) ($filters['q'] ?? ''));
        if ($search !== '') {
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(function (Builder $q) use ($searchColumns, $needle): void {
                foreach ($searchColumns as $column) $q->orWhereRaw("LOWER(COALESCE({$column}, '')) LIKE ?", [$needle]);
            });
        }
        if (! empty($filters['status'])) $query->where($statusColumn, $filters['status']);
        $query->orderByDesc(str_replace('.status', '.updated_at', $statusColumn))->orderByDesc(str_replace('.status', '.id', $statusColumn));
        return $query->paginate((int) ($filters['per_page'] ?? 25));
    }

    private function statusSummary(Builder $base, string $statusColumn, array $statuses): array
    {
        $summary = ['total' => (clone $base)->count()];
        foreach ($statuses as $status) $summary[strtolower($status)] = (clone $base)->where($statusColumn, $status)->count();
        return $summary;
    }

    private function meta(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
    }

    private function can(array $permissions, string $permission): bool { return in_array($permission, $permissions, true); }
    private function decimal(mixed $value): string { return bcadd((string) ($value ?? 0), '0', 6); }
    private function timestamp(mixed $value): ?string { return $value === null ? null : CarbonImmutable::parse((string) $value)->toISOString(); }
}

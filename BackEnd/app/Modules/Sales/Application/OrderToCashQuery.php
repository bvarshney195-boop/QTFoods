<?php

namespace App\Modules\Sales\Application;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OrderToCashQuery
{
    public const SORTS = ['NEWEST', 'OLDEST', 'NUMBER', 'STATUS'];

    public function leads(array $scope, array $filters, array $permissions): array
    {
        $base = DB::table('sales_leads as lead')->leftJoin('parties as customer', 'customer.id', '=', 'lead.customer_party_id')
            ->where('lead.company_id', $scope['company_id'])->where('lead.plant_id', $scope['plant_id'])
            ->select(['lead.*', 'customer.code as customer_code', 'customer.display_name as customer_name']);
        $page = $this->page($base, $filters, ['lead.lead_number', 'lead.company_name', 'lead.contact_name', 'lead.contact_email'], 'lead.status', 'lead.lead_number', 'lead');
        return [
            'data' => collect($page->items())->map(fn (object $row) => $this->leadPayload($row, $permissions))->all(),
            'meta' => $this->meta($page),
            'summary' => $this->statusSummary('sales_leads', $scope, OrderToCashService::LEAD_STATUSES),
            'lookups' => ['statuses' => OrderToCashService::LEAD_STATUSES, 'sources' => OrderToCashService::LEAD_SOURCES,
                'sorts' => self::SORTS, 'customers' => $this->customers($scope)],
            'allowed_actions' => $this->can($permissions, 'ACTION:CRM-LEAD:CREATE') ? ['CREATE'] : [],
        ];
    }

    public function pricing(array $scope, array $filters, array $permissions): array
    {
        $base = DB::table('sales_price_lists as price')->where('price.company_id', $scope['company_id'])
            ->where('price.plant_id', $scope['plant_id']);
        $page = $this->page($base, $filters, ['price.list_number', 'price.name'], 'price.status', 'price.list_number', 'price');
        $prices = collect($page->items())->map(function (object $row) use ($permissions): array {
            $payload = $this->row($row);
            $payload['line_count'] = DB::table('sales_price_list_lines')->where('sales_price_list_id', $row->id)->count();
            $payload['allowed_actions'] = $this->actions($permissions, [
                'UPDATE' => ['ACTION:CRM-PRICE:UPDATE', ['DRAFT']], 'ACTIVATE' => ['ACTION:CRM-PRICE:ACTIVATE', ['DRAFT']],
                'RETIRE' => ['ACTION:CRM-PRICE:RETIRE', ['ACTIVE']],
            ], $row->status);
            return $payload;
        })->all();
        $credits = DB::table('customer_credit_profiles as credit')->join('parties as customer', 'customer.id', '=', 'credit.customer_party_id')
            ->where('credit.company_id', $scope['company_id'])->orderBy('customer.display_name')
            ->get(['credit.*', 'customer.code as customer_code', 'customer.display_name as customer_name'])->map(fn ($row) => $this->row($row))->all();
        $contracts = DB::table('sales_contracts as contract')->join('parties as customer', 'customer.id', '=', 'contract.customer_party_id')
            ->leftJoin('sales_price_lists as price', 'price.id', '=', 'contract.sales_price_list_id')
            ->where('contract.company_id', $scope['company_id'])->where('contract.plant_id', $scope['plant_id'])
            ->orderByDesc('contract.updated_at')->limit(100)->get(['contract.*', 'customer.code as customer_code',
                'customer.display_name as customer_name', 'price.list_number as price_list_number'])->map(function ($row) use ($permissions) {
                    $payload = $this->row($row);
                    $payload['allowed_actions'] = $this->actions($permissions, [
                        'UPDATE' => ['ACTION:CRM-PRICE:UPDATE', ['DRAFT']], 'ACTIVATE' => ['ACTION:CRM-PRICE:ACTIVATE', ['DRAFT']],
                        'CLOSE' => ['ACTION:CRM-PRICE:RETIRE', ['DRAFT', 'ACTIVE']],
                    ], $row->status);
                    return $payload;
                })->all();
        return [
            'data' => $prices, 'meta' => $this->meta($page), 'credit_profiles' => $credits, 'contracts' => $contracts,
            'summary' => ['price_lists' => count($prices), 'active_price_lists' => collect($prices)->where('status', 'ACTIVE')->count(),
                'credit_holds' => collect($credits)->where('is_on_hold', true)->count(), 'active_contracts' => collect($contracts)->where('status', 'ACTIVE')->count()],
            'lookups' => ['price_statuses' => OrderToCashService::PRICE_STATUSES, 'contract_statuses' => OrderToCashService::CONTRACT_STATUSES,
                'sorts' => self::SORTS, 'customers' => $this->customers($scope), 'items' => $this->items($scope),
                'price_lists' => $this->activePriceLists($scope)],
            'allowed_actions' => array_values(array_filter([
                $this->can($permissions, 'ACTION:CRM-PRICE:CREATE') ? 'CREATE_PRICE_LIST' : null,
                $this->can($permissions, 'ACTION:CRM-PRICE:CREATE') ? 'CREATE_CONTRACT' : null,
                $this->can($permissions, 'ACTION:CRM-PRICE:CREDIT') ? 'MANAGE_CREDIT' : null,
            ])),
        ];
    }

    public function orders(array $scope, array $filters, array $permissions): array
    {
        $base = DB::table('sales_orders as orders')->join('parties as customer', 'customer.id', '=', 'orders.customer_party_id')
            ->leftJoin('sales_contracts as contract', 'contract.id', '=', 'orders.sales_contract_id')
            ->where('orders.company_id', $scope['company_id'])->where('orders.plant_id', $scope['plant_id'])->where('orders.order_type', 'SALES')
            ->select(['orders.*', 'customer.code as customer_code', 'customer.display_name as customer_name', 'contract.contract_number']);
        $page = $this->page($base, $filters, ['orders.order_number', 'customer.display_name', 'contract.contract_number'], 'orders.status', 'orders.order_number', 'orders');
        return [
            'data' => collect($page->items())->map(fn ($row) => $this->orderPayload($row, $permissions, false))->all(),
            'meta' => $this->meta($page), 'summary' => $this->statusSummary('sales_orders', $scope + ['order_type' => 'SALES'], OrderToCashService::ORDER_STATUSES),
            'lookups' => ['statuses' => OrderToCashService::ORDER_STATUSES, 'sorts' => self::SORTS, 'customers' => $this->customers($scope),
                'items' => $this->items($scope), 'price_lists' => $this->activePriceLists($scope), 'contracts' => $this->activeContracts($scope),
                'leads' => DB::table('sales_leads')->where($scope)->whereIn('status', ['QUALIFIED', 'CONVERTED'])->orderBy('lead_number')->get(['id', 'lead_number', 'company_name', 'customer_party_id'])->all()],
            'allowed_actions' => $this->can($permissions, 'ACTION:CRM-ORDER:CREATE') ? ['CREATE'] : [],
        ];
    }

    public function thirdPartyWork(array $scope, array $filters, array $permissions): array
    {
        $base = DB::table('third_party_work_orders as work')->join('parties as provider', 'provider.id', '=', 'work.provider_party_id')
            ->leftJoin('sales_orders as orders', 'orders.id', '=', 'work.sales_order_id')->where('work.company_id', $scope['company_id'])
            ->where('work.plant_id', $scope['plant_id'])->select(['work.*', 'provider.code as provider_code',
                'provider.display_name as provider_name', 'orders.order_number']);
        $page = $this->page($base, $filters, ['work.work_number', 'provider.display_name', 'orders.order_number'], 'work.status', 'work.work_number', 'work');
        return ['data' => collect($page->items())->map(function ($row) use ($permissions) {
                $payload = $this->row($row); $payload['allowed_actions'] = $this->actions($permissions, [
                    'RELEASE' => ['ACTION:CON-WORK:RELEASE', ['DRAFT']], 'COMPLETE' => ['ACTION:CON-WORK:COMPLETE', ['RELEASED']],
                    'CANCEL' => ['ACTION:CON-WORK:CANCEL', ['DRAFT', 'RELEASED']],
                ], $row->status); return $payload;
            })->all(), 'meta' => $this->meta($page),
            'summary' => $this->statusSummary('third_party_work_orders', $scope, OrderToCashService::WORK_STATUSES),
            'lookups' => ['statuses' => OrderToCashService::WORK_STATUSES, 'sorts' => self::SORTS,
                'providers' => $this->parties($scope), 'sales_orders' => $this->orderLookup($scope)],
            'allowed_actions' => $this->can($permissions, 'ACTION:CON-WORK:CREATE') ? ['CREATE'] : []];
    }

    public function allocations(array $scope, array $filters, array $permissions): array
    {
        $base = DB::table('sales_allocations as allocation')->join('sales_orders as orders', 'orders.id', '=', 'allocation.sales_order_id')
            ->join('parties as customer', 'customer.id', '=', 'orders.customer_party_id')->where('allocation.company_id', $scope['company_id'])
            ->where('allocation.plant_id', $scope['plant_id'])->select(['allocation.*', 'orders.order_number', 'orders.total_amount',
                'customer.code as customer_code', 'customer.display_name as customer_name']);
        $page = $this->page($base, $filters, ['allocation.allocation_number', 'orders.order_number', 'customer.display_name'], 'allocation.status', 'allocation.allocation_number', 'allocation');
        return ['data' => collect($page->items())->map(fn ($row) => $this->allocationPayload($row, $permissions, true))->all(),
            'meta' => $this->meta($page), 'summary' => $this->statusSummary('sales_allocations', $scope, OrderToCashService::ALLOCATION_STATUSES),
            'lookups' => ['statuses' => OrderToCashService::ALLOCATION_STATUSES, 'sorts' => self::SORTS,
                'orders' => DB::table('sales_orders')->where($scope)->where('order_type', 'SALES')->whereIn('status', ['CONFIRMED', 'ALLOCATED'])->orderBy('order_number')->get(['id', 'order_number', 'record_version', 'total_amount'])->all()],
            'allowed_actions' => $this->can($permissions, 'ACTION:DSP-PICK:ALLOCATE') ? ['ALLOCATE'] : []];
    }

    public function shipments(array $scope, array $filters, array $permissions): array
    {
        $base = $this->shipmentBase($scope);
        $page = $this->page($base, $filters, ['shipment.shipment_number', 'orders.order_number', 'customer.display_name', 'shipment.vehicle_number'], 'shipment.status', 'shipment.shipment_number', 'shipment');
        return ['data' => collect($page->items())->map(fn ($row) => $this->shipmentPayload($row, $permissions))->all(),
            'meta' => $this->meta($page), 'summary' => $this->statusSummary('shipments', $scope + ['shipment_type' => 'SALES'], OrderToCashService::SHIPMENT_STATUSES),
            'lookups' => ['statuses' => OrderToCashService::SHIPMENT_STATUSES, 'sorts' => self::SORTS,
                'allocations' => DB::table('sales_allocations as allocation')->join('sales_orders as orders', 'orders.id', '=', 'allocation.sales_order_id')
                    ->where('allocation.company_id', $scope['company_id'])->where('allocation.plant_id', $scope['plant_id'])->where('allocation.status', 'PICKED')
                    ->whereNotExists(function ($query): void { $query->selectRaw('1')->from('shipments as used_shipment')
                        ->whereColumn('used_shipment.sales_allocation_id', 'allocation.id')->where('used_shipment.shipment_type', 'SALES')->whereNot('used_shipment.status', 'CANCELLED'); })
                    ->orderBy('allocation.allocation_number')->get(['allocation.id', 'allocation.allocation_number', 'allocation.record_version', 'orders.order_number'])->all()],
            'allowed_actions' => $this->can($permissions, 'ACTION:DSP-LOAD:CREATE') ? ['CREATE'] : []];
    }

    public function deliveryProofs(array $scope, array $filters, array $permissions): array
    {
        $base = $this->shipmentBase($scope)->leftJoin('delivery_proofs as proof', 'proof.shipment_id', '=', 'shipment.id')
            ->addSelect(['proof.id as proof_id', 'proof.proof_number', 'proof.outcome', 'proof.receiver_name', 'proof.event_at', 'proof.failure_reason']);
        $page = $this->page($base, $filters, ['shipment.shipment_number', 'orders.order_number', 'proof.proof_number', 'customer.display_name'], 'shipment.status', 'shipment.shipment_number', 'shipment');
        return ['data' => collect($page->items())->map(fn ($row) => $this->shipmentPayload($row, $permissions))->all(),
            'meta' => $this->meta($page), 'summary' => ['awaiting_pod' => DB::table('shipments')->where($scope)->where('shipment_type', 'SALES')->where('status', 'DISPATCHED')->count(),
                'delivered' => DB::table('shipments')->where($scope)->where('shipment_type', 'SALES')->where('status', 'DELIVERED')->count(),
                'failed' => DB::table('shipments')->where($scope)->where('shipment_type', 'SALES')->where('status', 'FAILED')->count()],
            'lookups' => ['statuses' => ['DISPATCHED', 'DELIVERED', 'FAILED'], 'outcomes' => ['DELIVERED', 'FAILED'], 'sorts' => self::SORTS],
            'allowed_actions' => []];
    }

    public function claims(array $scope, array $filters, array $permissions): array
    {
        $base = DB::table('customer_claims as claim')->join('shipments as shipment', 'shipment.id', '=', 'claim.shipment_id')
            ->join('parties as customer', 'customer.id', '=', 'claim.customer_party_id')
            ->leftJoin('sales_invoice_financials as invoice', 'invoice.invoice_id', '=', 'claim.invoice_id')
            ->where('claim.company_id', $scope['company_id'])->where('claim.plant_id', $scope['plant_id'])
            ->select(['claim.*', 'shipment.shipment_number', 'customer.code as customer_code', 'customer.display_name as customer_name', 'invoice.invoice_number']);
        $page = $this->page($base, $filters, ['claim.claim_number', 'shipment.shipment_number', 'customer.display_name', 'invoice.invoice_number'], 'claim.status', 'claim.claim_number', 'claim');
        return ['data' => collect($page->items())->map(fn ($row) => $this->claimPayload($row, $permissions, false))->all(),
            'meta' => $this->meta($page), 'summary' => $this->statusSummary('customer_claims', $scope, OrderToCashService::CLAIM_STATUSES),
            'lookups' => ['statuses' => OrderToCashService::CLAIM_STATUSES, 'claim_types' => OrderToCashService::CLAIM_TYPES,
                'resolutions' => OrderToCashService::CLAIM_RESOLUTIONS, 'sorts' => self::SORTS,
                'shipments' => DB::table('shipments as shipment')->join('parties as customer', 'customer.id', '=', 'shipment.party_id')
                    ->where('shipment.company_id', $scope['company_id'])->where('shipment.plant_id', $scope['plant_id'])
                    ->where('shipment.shipment_type', 'SALES')->whereIn('shipment.status', ['DISPATCHED', 'DELIVERED'])
                    ->orderByDesc('shipment.dispatched_at')->get(['shipment.id', 'shipment.shipment_number', 'shipment.status', 'customer.display_name as customer_name'])
                    ->map(function (object $shipment): array {
                        $line = DB::table('shipment_lines')->where('shipment_id', $shipment->id)->orderBy('created_at')->first(['id', 'shipped_quantity']);
                        return $this->row($shipment) + ['first_line_id' => $line?->id, 'first_line_quantity' => $line?->shipped_quantity];
                    })->all()],
            'allowed_actions' => $this->can($permissions, 'ACTION:RET-CASE:CREATE') ? ['CREATE'] : []];
    }

    public function receivables(array $scope, array $filters, array $permissions): array
    {
        $base = DB::table('sales_invoice_financials as invoice')->join('parties as customer', 'customer.id', '=', 'invoice.party_id')
            ->join('shipments as shipment', 'shipment.id', '=', 'invoice.shipment_id')
            ->leftJoin('sales_orders as orders', 'orders.id', '=', 'invoice.sales_order_id')
            ->where('invoice.company_id', $scope['company_id'])->where('invoice.plant_id', $scope['plant_id'])
            ->select(['invoice.*', 'customer.code as customer_code', 'customer.display_name as customer_name',
                'shipment.shipment_number', 'orders.order_number']);
        $page = $this->page($base, $filters, ['invoice.invoice_number', 'customer.display_name', 'shipment.shipment_number', 'orders.order_number'], null, 'invoice.invoice_number', 'invoice');
        $today = CarbonImmutable::today();
        $invoices = collect($page->items())->map(function ($row) use ($today): array {
            $payload = $this->receivableRow($row);
            $days = $row->due_date && $today->greaterThan(CarbonImmutable::parse($row->due_date)) ? CarbonImmutable::parse($row->due_date)->diffInDays($today) : 0;
            $payload['ageing_bucket'] = bccomp((string) $row->outstanding_amount, '0', 4) === 0 ? 'SETTLED'
                : ($days === 0 ? 'CURRENT' : ($days <= 30 ? '1-30' : ($days <= 60 ? '31-60' : ($days <= 90 ? '61-90' : '90+'))));
            $payload['days_overdue'] = $days;
            return $payload;
        })->all();
        $openBase = DB::table('sales_invoice_financials')->where($scope)->where('outstanding_amount', '>', 0);
        $receipts = DB::table('customer_receipts as receipt')->join('parties as customer', 'customer.id', '=', 'receipt.customer_party_id')
            ->where('receipt.company_id', $scope['company_id'])->where('receipt.plant_id', $scope['plant_id'])
            ->orderByDesc('receipt.receipt_date')->limit(100)->get(['receipt.*', 'customer.code as customer_code', 'customer.display_name as customer_name'])
            ->map(fn ($row) => $this->row($row))->all();
        return ['data' => $invoices, 'meta' => $this->meta($page), 'receipts' => $receipts,
            'summary' => ['open_invoices' => (clone $openBase)->count(), 'outstanding_amount' => $this->decimal((clone $openBase)->sum('outstanding_amount'), 4),
                'overdue_amount' => $this->decimal((clone $openBase)->whereDate('due_date', '<', $today->toDateString())->sum('outstanding_amount'), 4),
                'collected_amount' => $this->decimal(DB::table('customer_receipts')->where($scope)->sum('total_amount'), 4)],
            'lookups' => ['payment_methods' => ['BANK', 'UPI', 'CHEQUE', 'CASH'], 'sorts' => self::SORTS,
                'customers' => $this->customers($scope), 'open_invoices' => DB::table('sales_invoice_financials as invoice')
                    ->join('parties as customer', 'customer.id', '=', 'invoice.party_id')->where('invoice.company_id', $scope['company_id'])
                    ->where('invoice.plant_id', $scope['plant_id'])->where('invoice.outstanding_amount', '>', 0)->orderBy('invoice.due_date')
                    ->get(['invoice.invoice_id as id', 'invoice.invoice_number', 'invoice.party_id as customer_party_id', 'invoice.outstanding_amount', 'invoice.due_date', 'customer.display_name as customer_name'])->all()],
            'allowed_actions' => $this->can($permissions, 'ACTION:FIN-AR:COLLECT') ? ['COLLECT'] : []];
    }

    public function profitability(array $scope, array $filters): array
    {
        $query = DB::table('sales_orders as orders')->join('parties as customer', 'customer.id', '=', 'orders.customer_party_id')
            ->join('sales_order_lines as line', 'line.sales_order_id', '=', 'orders.id')
            ->where('orders.company_id', $scope['company_id'])->where('orders.plant_id', $scope['plant_id'])->where('orders.order_type', 'SALES');
        if ($q = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function ($inner) use ($q): void { $inner->where('orders.order_number', 'like', "%{$q}%")->orWhere('customer.display_name', 'like', "%{$q}%"); });
        }
        if ($from = $filters['date_from'] ?? null) $query->whereDate('orders.order_date', '>=', $from);
        if ($to = $filters['date_to'] ?? null) $query->whereDate('orders.order_date', '<=', $to);
        $rows = $query->groupBy('orders.id', 'orders.order_number', 'orders.order_date', 'orders.status', 'customer.id', 'customer.code', 'customer.display_name')
            ->orderByDesc('orders.order_date')->limit(500)->get([
                'orders.id', 'orders.order_number', 'orders.order_date', 'orders.status', 'customer.id as customer_id',
                'customer.code as customer_code', 'customer.display_name as customer_name',
                DB::raw('SUM(line.net_amount) as revenue'), DB::raw('SUM(line.ordered_quantity * line.unit_cost_snapshot) as cost'),
            ])->map(function ($row): array {
                $revenue = $this->decimal($row->revenue); $cost = $this->decimal($row->cost); $margin = bcsub($revenue, $cost, 6);
                $costAvailable = bccomp($cost, '0', 6) > 0;
                return ['id' => (string) $row->id, 'order_number' => $row->order_number, 'order_date' => (string) $row->order_date,
                    'status' => $row->status, 'customer' => ['id' => (string) $row->customer_id, 'code' => $row->customer_code, 'name' => $row->customer_name],
                    'revenue' => $revenue, 'cost' => $costAvailable ? $cost : null, 'gross_margin' => $costAvailable ? $margin : null,
                    'margin_percent' => $costAvailable && bccomp($revenue, '0', 6) > 0 ? bcmul(bcdiv($margin, $revenue, 8), '100', 4) : null,
                    'cost_status' => $costAvailable ? 'AVAILABLE' : 'MISSING_COST_SNAPSHOT'];
            });
        return ['data' => $rows->all(), 'meta' => ['total' => $rows->count()],
            'summary' => ['revenue' => $this->decimal($rows->sum(fn ($row) => $row['revenue'])),
                'cost' => $this->decimal($rows->sum(fn ($row) => $row['cost'] ?? 0)),
                'gross_margin' => $this->decimal($rows->sum(fn ($row) => $row['gross_margin'] ?? 0)),
                'missing_cost_snapshots' => $rows->where('cost_status', 'MISSING_COST_SNAPSHOT')->count()],
            'lookups' => ['customers' => $this->customers($scope)], 'allowed_actions' => []];
    }

    public function detail(string $resource, string $id, array $scope, array $permissions): array
    {
        return match ($resource) {
            'lead' => $this->leadDetail($id, $scope, $permissions),
            'price-list' => $this->priceDetail($id, $scope, $permissions),
            'contract' => $this->contractDetail($id, $scope, $permissions),
            'order' => $this->orderDetail($id, $scope, $permissions),
            'work' => $this->genericDetail('third_party_work_orders', $id, $scope, 'Third-party work order'),
            'allocation' => $this->allocationDetail($id, $scope, $permissions),
            'shipment' => $this->shipmentDetail($id, $scope, $permissions),
            'claim' => $this->claimDetail($id, $scope, $permissions),
            'receivable' => $this->receivableDetail($id, $scope),
            default => throw new NotFoundHttpException('Order-to-cash resource not found.'),
        };
    }

    private function leadDetail(string $id, array $scope, array $permissions): array
    {
        $row = DB::table('sales_leads as lead')->leftJoin('parties as customer', 'customer.id', '=', 'lead.customer_party_id')
            ->where('lead.id', $id)->where('lead.company_id', $scope['company_id'])->where('lead.plant_id', $scope['plant_id'])
            ->first(['lead.*', 'customer.code as customer_code', 'customer.display_name as customer_name']);
        if (! $row) throw new NotFoundHttpException('Sales lead not found.');
        $payload = $this->leadPayload($row, $permissions);
        $payload['events'] = DB::table('sales_lead_events as event')->join('users as actor', 'actor.id', '=', 'event.actor_id')
            ->where('event.sales_lead_id', $id)->orderBy('event.occurred_at')->get(['event.*', 'actor.name as actor_name'])->map(fn ($record) => $this->row($record))->all();
        return $payload;
    }

    private function priceDetail(string $id, array $scope, array $permissions): array
    {
        $row = DB::table('sales_price_lists')->where('id', $id)->where($scope)->first();
        if (! $row) throw new NotFoundHttpException('Price list not found.');
        $payload = $this->row($row);
        $payload['lines'] = DB::table('sales_price_list_lines as line')->join('items as item', 'item.id', '=', 'line.item_id')
            ->where('line.sales_price_list_id', $id)->orderBy('line.line_number')->get(['line.*', 'item.code as item_code', 'item.name as item_name'])->map(fn ($record) => $this->row($record))->all();
        $payload['allowed_actions'] = $this->actions($permissions, ['UPDATE' => ['ACTION:CRM-PRICE:UPDATE', ['DRAFT']],
            'ACTIVATE' => ['ACTION:CRM-PRICE:ACTIVATE', ['DRAFT']], 'RETIRE' => ['ACTION:CRM-PRICE:RETIRE', ['ACTIVE']]], $row->status);
        return $payload;
    }

    private function contractDetail(string $id, array $scope, array $permissions): array
    {
        $row = DB::table('sales_contracts')->where('id', $id)->where($scope)->first();
        if (! $row) throw new NotFoundHttpException('Sales contract not found.');
        $payload = $this->row($row);
        $payload['lines'] = DB::table('sales_contract_lines as line')->join('items as item', 'item.id', '=', 'line.item_id')
            ->where('line.sales_contract_id', $id)->orderBy('line.line_number')->get(['line.*', 'item.code as item_code', 'item.name as item_name'])->map(fn ($record) => $this->row($record))->all();
        $payload['allowed_actions'] = $this->actions($permissions, ['UPDATE' => ['ACTION:CRM-PRICE:UPDATE', ['DRAFT']],
            'ACTIVATE' => ['ACTION:CRM-PRICE:ACTIVATE', ['DRAFT']], 'CLOSE' => ['ACTION:CRM-PRICE:RETIRE', ['DRAFT', 'ACTIVE']]], $row->status);
        return $payload;
    }

    private function orderDetail(string $id, array $scope, array $permissions): array
    {
        $row = DB::table('sales_orders as orders')->join('parties as customer', 'customer.id', '=', 'orders.customer_party_id')
            ->leftJoin('sales_contracts as contract', 'contract.id', '=', 'orders.sales_contract_id')
            ->where('orders.id', $id)->where('orders.company_id', $scope['company_id'])->where('orders.plant_id', $scope['plant_id'])->where('orders.order_type', 'SALES')
            ->first(['orders.*', 'customer.code as customer_code', 'customer.display_name as customer_name', 'contract.contract_number']);
        if (! $row) throw new NotFoundHttpException('Sales order not found.');
        return $this->orderPayload($row, $permissions, true);
    }

    private function allocationDetail(string $id, array $scope, array $permissions): array
    {
        $row = DB::table('sales_allocations as allocation')->join('sales_orders as orders', 'orders.id', '=', 'allocation.sales_order_id')
            ->join('parties as customer', 'customer.id', '=', 'orders.customer_party_id')->where('allocation.id', $id)
            ->where('allocation.company_id', $scope['company_id'])->where('allocation.plant_id', $scope['plant_id'])
            ->first(['allocation.*', 'orders.order_number', 'orders.total_amount', 'customer.code as customer_code', 'customer.display_name as customer_name']);
        if (! $row) throw new NotFoundHttpException('Sales allocation not found.');
        return $this->allocationPayload($row, $permissions, true);
    }

    private function shipmentDetail(string $id, array $scope, array $permissions): array
    {
        $row = $this->shipmentBase($scope)->where('shipment.id', $id)->first();
        if (! $row) throw new NotFoundHttpException('Shipment not found.');
        $payload = $this->shipmentPayload($row, $permissions);
        $payload['lines'] = DB::table('shipment_lines as line')->join('items as item', 'item.id', '=', 'line.item_id')->leftJoin('lots as lot', 'lot.id', '=', 'line.fg_lot_id')
            ->where('line.shipment_id', $id)->orderBy('line.created_at')->get(['line.*', 'item.code as item_code', 'item.name as item_name', 'lot.internal_lot_code'])->map(fn ($record) => $this->row($record))->all();
        $payload['proof'] = DB::table('delivery_proofs')->where('shipment_id', $id)->first();
        $payload['invoice'] = DB::table('sales_invoice_financials')->where('shipment_id', $id)->first();
        return $payload;
    }

    private function claimDetail(string $id, array $scope, array $permissions): array
    {
        $row = DB::table('customer_claims as claim')->join('shipments as shipment', 'shipment.id', '=', 'claim.shipment_id')
            ->join('parties as customer', 'customer.id', '=', 'claim.customer_party_id')->leftJoin('sales_invoice_financials as invoice', 'invoice.invoice_id', '=', 'claim.invoice_id')
            ->where('claim.id', $id)->where('claim.company_id', $scope['company_id'])->where('claim.plant_id', $scope['plant_id'])
            ->first(['claim.*', 'shipment.shipment_number', 'customer.code as customer_code', 'customer.display_name as customer_name', 'invoice.invoice_number']);
        if (! $row) throw new NotFoundHttpException('Customer claim not found.');
        return $this->claimPayload($row, $permissions, true);
    }

    private function receivableDetail(string $id, array $scope): array
    {
        $row = DB::table('sales_invoice_financials as invoice')->join('parties as customer', 'customer.id', '=', 'invoice.party_id')
            ->join('shipments as shipment', 'shipment.id', '=', 'invoice.shipment_id')->where('invoice.invoice_id', $id)
            ->where('invoice.company_id', $scope['company_id'])->where('invoice.plant_id', $scope['plant_id'])
            ->first(['invoice.*', 'customer.code as customer_code', 'customer.display_name as customer_name', 'shipment.shipment_number']);
        if (! $row) throw new NotFoundHttpException('Receivable invoice not found.');
        $payload = $this->receivableRow($row);
        $payload['transactions'] = DB::table('receivable_transactions')->where('invoice_id', $id)->orderBy('posted_at')->get()->map(function ($record): array {
            $transaction = $this->row($record);
            foreach (['amount', 'balance_before', 'balance_after'] as $field) {
                $transaction[$field] = $this->decimal($transaction[$field] ?? 0, 4);
            }
            return $transaction;
        })->all();
        return $payload;
    }

    private function leadPayload(object $row, array $permissions): array
    {
        $payload = $this->row($row);
        $payload['customer'] = $row->customer_party_id ? ['id' => (string) $row->customer_party_id, 'code' => $row->customer_code, 'name' => $row->customer_name] : null;
        $payload['allowed_actions'] = $this->actions($permissions, [
            'UPDATE' => ['ACTION:CRM-LEAD:UPDATE', ['NEW', 'QUALIFIED']], 'QUALIFY' => ['ACTION:CRM-LEAD:QUALIFY', ['NEW']],
            'CONVERT' => ['ACTION:CRM-LEAD:CONVERT', ['QUALIFIED']], 'CLOSE_WON' => ['ACTION:CRM-LEAD:CLOSE', ['CONVERTED']],
            'CLOSE_LOST' => ['ACTION:CRM-LEAD:CLOSE', ['NEW', 'QUALIFIED', 'CONVERTED']],
        ], $row->status);
        return $payload;
    }

    private function orderPayload(object $row, array $permissions, bool $detail): array
    {
        $payload = $this->row($row);
        $payload['customer'] = ['id' => (string) $row->customer_party_id, 'code' => $row->customer_code, 'name' => $row->customer_name];
        $payload['allowed_actions'] = $this->actions($permissions, [
            'UPDATE' => ['ACTION:CRM-ORDER:UPDATE', ['DRAFT']], 'CONFIRM' => ['ACTION:CRM-ORDER:CONFIRM', ['DRAFT']],
            'AMEND' => ['ACTION:CRM-ORDER:AMEND', ['CONFIRMED']], 'CANCEL' => ['ACTION:CRM-ORDER:CANCEL', ['DRAFT', 'CONFIRMED']],
            'ALLOCATE' => ['ACTION:DSP-PICK:ALLOCATE', ['CONFIRMED', 'ALLOCATED']],
        ], $row->status);
        if ($detail) {
            $payload['lines'] = DB::table('sales_order_lines as line')->join('items as item', 'item.id', '=', 'line.item_id')
                ->where('line.sales_order_id', $row->id)->orderBy('line.line_number')->get(['line.*', 'item.code as item_code', 'item.name as item_name'])->map(fn ($record) => $this->row($record))->all();
            $payload['revisions'] = DB::table('sales_order_revisions as revision')->join('users as creator', 'creator.id', '=', 'revision.created_by')
                ->where('revision.sales_order_id', $row->id)->orderByDesc('revision.revision_number')->get(['revision.*', 'creator.name as creator_name'])->map(fn ($record) => $this->row($record))->all();
        }
        return $payload;
    }

    private function allocationPayload(object $row, array $permissions, bool $lines): array
    {
        $payload = $this->row($row);
        $payload['allowed_actions'] = $this->actions($permissions, [
            'PICK' => ['ACTION:DSP-PICK:PICK', ['RESERVED']], 'CANCEL' => ['ACTION:DSP-PICK:CANCEL', ['RESERVED', 'PICKED']],
            'CREATE_SHIPMENT' => ['ACTION:DSP-LOAD:CREATE', ['PICKED']],
        ], $row->status);
        if ($lines) $payload['lines'] = DB::table('sales_allocation_lines as line')->join('items as item', 'item.id', '=', 'line.item_id')
            ->join('lots as lot', 'lot.id', '=', 'line.lot_id')->join('stock_positions as position', 'position.id', '=', 'line.stock_position_id')
            ->join('locations as location', 'location.id', '=', 'position.location_id')->where('line.sales_allocation_id', $row->id)->orderBy('line.line_number')
            ->get(['line.*', 'item.code as item_code', 'item.name as item_name', 'lot.internal_lot_code', 'lot.expiry_date', 'location.code as location_code'])
            ->map(fn ($record) => $this->row($record))->all();
        return $payload;
    }

    private function shipmentPayload(object $row, array $permissions): array
    {
        $payload = $this->row($row);
        $payload['allowed_actions'] = $this->actions($permissions, [
            'LOAD' => ['ACTION:DSP-LOAD:LOAD', ['DRAFT']], 'DISPATCH' => ['ACTION:DSP-LOAD:DISPATCH', ['LOADED']],
            'CANCEL' => ['ACTION:DSP-LOAD:CANCEL', ['DRAFT', 'LOADED']], 'POD' => ['ACTION:DSP-POD:COMPLETE', ['DISPATCHED']],
            'CLAIM' => ['ACTION:RET-CASE:CREATE', ['DISPATCHED', 'DELIVERED']],
        ], $row->status);
        return $payload;
    }

    private function claimPayload(object $row, array $permissions, bool $detail): array
    {
        $payload = $this->row($row);
        $payload['allowed_actions'] = $this->actions($permissions, [
            'RECEIVE' => ['ACTION:RET-CASE:RECEIVE', ['RETURN_REQUIRED']],
            'RESOLVE' => ['ACTION:RET-CASE:RESOLVE', ['OPEN', 'RECEIVED']],
        ], $row->status);
        if ($detail) {
            $payload['lines'] = DB::table('customer_claim_lines as line')->join('items as item', 'item.id', '=', 'line.item_id')
                ->leftJoin('lots as lot', 'lot.id', '=', 'line.lot_id')->where('line.customer_claim_id', $row->id)->orderBy('line.line_number')
                ->get(['line.*', 'item.code as item_code', 'item.name as item_name', 'lot.internal_lot_code'])->map(fn ($record) => $this->row($record))->all();
            $payload['actions'] = DB::table('customer_claim_actions as action')->join('users as actor', 'actor.id', '=', 'action.actor_id')
                ->where('action.customer_claim_id', $row->id)->orderBy('action.created_at')->get(['action.*', 'actor.name as actor_name'])->map(fn ($record) => $this->row($record))->all();
        }
        return $payload;
    }

    private function shipmentBase(array $scope): Builder
    {
        return DB::table('shipments as shipment')->join('sales_orders as orders', 'orders.id', '=', 'shipment.sales_order_id')
            ->join('parties as customer', 'customer.id', '=', 'shipment.party_id')
            ->join('sales_allocations as allocation', 'allocation.id', '=', 'shipment.sales_allocation_id')
            ->where('shipment.company_id', $scope['company_id'])->where('shipment.plant_id', $scope['plant_id'])->where('shipment.shipment_type', 'SALES')
            ->select(['shipment.*', 'orders.order_number', 'orders.total_amount', 'allocation.allocation_number',
                'customer.code as customer_code', 'customer.display_name as customer_name']);
    }

    private function genericDetail(string $table, string $id, array $scope, string $label): array
    {
        $record = DB::table($table)->where('id', $id)->where($scope)->first();
        if (! $record) throw new NotFoundHttpException($label.' not found.');
        return $this->row($record);
    }

    private function page(Builder $base, array $filters, array $searchColumns, ?string $statusColumn, string $numberColumn, string $alias): LengthAwarePaginator
    {
        $query = clone $base;
        if ($q = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function ($inner) use ($q, $searchColumns): void {
                foreach ($searchColumns as $index => $column) $index === 0 ? $inner->where($column, 'like', "%{$q}%") : $inner->orWhere($column, 'like', "%{$q}%");
            });
        }
        if ($statusColumn && ($status = $filters['status'] ?? null)) $query->where($statusColumn, $status);
        $sort = $filters['sort'] ?? 'NEWEST';
        if ($sort === 'OLDEST') $query->orderBy("{$alias}.created_at")->orderBy($numberColumn);
        elseif ($sort === 'NUMBER') $query->orderBy($numberColumn);
        elseif ($sort === 'STATUS' && $statusColumn) $query->orderBy($statusColumn)->orderBy($numberColumn);
        else $query->orderByDesc("{$alias}.updated_at")->orderBy($numberColumn);
        return $query->paginate((int) ($filters['per_page'] ?? 25));
    }

    private function statusSummary(string $table, array $scope, array $statuses): array
    {
        $base = DB::table($table)->where($scope); $summary = ['total' => (clone $base)->count()];
        foreach ($statuses as $status) $summary[strtolower($status)] = (clone $base)->where('status', $status)->count();
        return $summary;
    }

    private function customers(array $scope): array
    {
        return DB::table('parties as party')->join('party_roles as role', function ($join): void { $join->on('role.party_id', '=', 'party.id')->where('role.role_code', 'CUSTOMER'); })
            ->leftJoin('customer_credit_profiles as credit', function ($join): void { $join->on('credit.customer_party_id', '=', 'party.id')->on('credit.company_id', '=', 'party.company_id'); })
            ->where('party.company_id', $scope['company_id'])->where('party.status', 'ACTIVE')->orderBy('party.display_name')
            ->get(['party.id', 'party.code', 'party.display_name as name', 'credit.credit_limit', 'credit.payment_terms_days', 'credit.is_on_hold'])->all();
    }

    private function parties(array $scope): array
    {
        return DB::table('parties')->where('company_id', $scope['company_id'])->where('status', 'ACTIVE')->orderBy('display_name')->get(['id', 'code', 'display_name as name'])->all();
    }

    private function items(array $scope): array
    {
        return DB::table('items')->where('company_id', $scope['company_id'])->where('status', 'ACTIVE')
            ->whereIn('item_type', ['FINISHED_GOOD', 'SERVICE'])->orderBy('code')->get(['id', 'code', 'name', 'item_type', 'base_uom'])->all();
    }

    private function activePriceLists(array $scope): array
    {
        return DB::table('sales_price_lists')->where($scope)->where('status', 'ACTIVE')->orderBy('list_number')->get(['id', 'list_number', 'name', 'effective_from', 'effective_to'])->all();
    }

    private function activeContracts(array $scope): array
    {
        return DB::table('sales_contracts as contract')->join('parties as customer', 'customer.id', '=', 'contract.customer_party_id')->where('contract.company_id', $scope['company_id'])
            ->where('contract.plant_id', $scope['plant_id'])->where('contract.status', 'ACTIVE')->orderBy('contract.contract_number')
            ->get(['contract.id', 'contract.contract_number', 'contract.customer_party_id', 'contract.effective_from', 'contract.effective_to', 'customer.display_name as customer_name'])->all();
    }

    private function orderLookup(array $scope): array
    {
        return DB::table('sales_orders')->where($scope)->where('order_type', 'SALES')->whereNot('status', 'CANCELLED')->orderByDesc('order_date')->get(['id', 'order_number', 'status'])->all();
    }

    private function actions(array $permissions, array $definitions, string $status): array
    {
        $actions = [];
        foreach ($definitions as $action => [$permission, $statuses]) if ($this->can($permissions, $permission) && in_array($status, $statuses, true)) $actions[] = $action;
        return $actions;
    }

    private function can(array $permissions, string $permission): bool
    {
        return in_array($permission, $permissions, true);
    }

    private function meta(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'per_page' => $page->perPage(), 'total' => $page->total()];
    }

    private function row(object $row): array
    {
        return collect((array) $row)->map(function ($value) {
            if (is_string($value) && ((str_starts_with($value, '{') && str_ends_with($value, '}')) || (str_starts_with($value, '[') && str_ends_with($value, ']')))) {
                try { return json_decode($value, true, 512, JSON_THROW_ON_ERROR); } catch (\Throwable) {}
            }
            return $value;
        })->all();
    }

    private function receivableRow(object $row): array
    {
        $payload = $this->row($row);
        foreach (['net_amount', 'tax_amount', 'gross_amount', 'outstanding_amount', 'paid_amount', 'credited_amount'] as $field) {
            if (array_key_exists($field, $payload)) {
                $payload[$field] = $this->decimal($payload[$field], 4);
            }
        }
        return $payload;
    }

    private function decimal(mixed $value, int $scale = 6): string
    {
        return bcadd((string) ($value ?? 0), '0', $scale);
    }
}

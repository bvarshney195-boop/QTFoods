<?php

namespace App\Modules\Manufacturing\Application;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ManufacturingExecutionQuery
{
    public const SORTS = ['NEWEST', 'OLDEST', 'NUMBER', 'STATUS'];

    public function __construct(
        private readonly ManufacturingMeasurementService $measurements,
        private readonly ManufacturingMaterialReconciliationService $materialReconciliation,
    ) {}

    public function orderWorkspace(array $scope, array $filters, array $permissions): array
    {
        $base = $this->orderBase($scope);
        $query = clone $base;
        $this->filters($query, $filters, 'orders.order_number', ['orders.batch_number', 'item.code', 'item.name'], 'orders.status');
        $this->sort($query, $filters, 'orders', 'order_number');
        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($page->items())->map(fn (object $row) => $this->orderPayload($row, $permissions, false))->all(),
            'meta' => $this->meta($page), 'summary' => $this->orderSummary($scope),
            'lookups' => [
                'statuses' => ManufacturingExecutionService::ORDER_STATUSES, 'sorts' => self::SORTS,
                'schedule_lines' => $this->releasableScheduleLines($scope),
            ],
            'allowed_actions' => $this->can($permissions, 'ACTION:PRO-ORDER:CREATE') ? ['CREATE'] : [],
        ];
    }

    public function orderDetail(string $id, array $scope, array $permissions): array
    {
        $row = $this->orderBase($scope)->where('orders.id', $id)->first();
        if (! $row) {
            throw new NotFoundHttpException('Production order not found.');
        }

        return $this->orderPayload($row, $permissions, true);
    }

    public function stageWorkspace(array $scope, array $filters, array $permissions): array
    {
        $base = DB::table('production_order_stages as stage')
            ->join('production_orders as orders', 'orders.id', '=', 'stage.production_order_id')
            ->where('stage.company_id', $scope['company_id'])->where('stage.plant_id', $scope['plant_id'])
            ->select(['stage.*', 'orders.order_number', 'orders.batch_number', 'orders.status as order_status']);
        $query = clone $base;
        $this->filters($query, $filters, 'orders.order_number', ['orders.batch_number', 'stage.operation_name', 'stage.work_center_code'], 'stage.status');
        $sort = $filters['sort'] ?? 'NEWEST';
        if ($sort === 'OLDEST') {
            $query->orderBy('stage.created_at')->orderBy('stage.sequence_no');
        } elseif ($sort === 'NUMBER') {
            $query->orderBy('orders.order_number')->orderBy('stage.sequence_no');
        } elseif ($sort === 'STATUS') {
            $query->orderBy('stage.status')->orderBy('orders.order_number')->orderBy('stage.sequence_no');
        } else {
            $query->orderByDesc('stage.created_at')->orderBy('orders.order_number')->orderBy('stage.sequence_no');
        }
        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($page->items())->map(fn (object $row) => $this->stagePayload($row, $permissions))->all(),
            'meta' => $this->meta($page),
            'summary' => [
                'total' => (clone $base)->count(),
                'pending' => (clone $base)->where('stage.status', 'PENDING')->count(),
                'in_progress' => (clone $base)->where('stage.status', 'IN_PROGRESS')->count(),
                'completed' => (clone $base)->where('stage.status', 'COMPLETED')->count(),
            ],
            'lookups' => ['statuses' => ['PENDING', 'IN_PROGRESS', 'COMPLETED'], 'sorts' => self::SORTS,
                'measurement_kinds' => ManufacturingMeasurementService::KINDS,
                'quantity_dimensions' => ManufacturingMeasurementService::DIMENSIONS,
                'value_sources' => ManufacturingMeasurementService::VALUE_SOURCES,
                'uoms' => DB::table('uoms')->orderBy('code')->get(['code', 'name'])->map(fn (object $uom): array => [
                    'code' => (string) $uom->code, 'name' => (string) $uom->name,
                ])->all()],
            'allowed_actions' => [],
        ];
    }

    public function stageDetail(string $id, array $scope, array $permissions): array
    {
        $row = DB::table('production_order_stages as stage')
            ->join('production_orders as orders', 'orders.id', '=', 'stage.production_order_id')
            ->where('stage.id', $id)->where('stage.company_id', $scope['company_id'])
            ->where('stage.plant_id', $scope['plant_id'])->first([
                'stage.*', 'orders.order_number', 'orders.batch_number', 'orders.status as order_status',
            ]);
        if (! $row) {
            throw new NotFoundHttpException('Production stage not found.');
        }

        return $this->stagePayload($row, $permissions);
    }

    public function lossWorkspace(array $scope, array $filters, array $permissions): array
    {
        $base = $this->orderBase($scope)->whereIn('orders.status', ['IN_PROCESS', 'COMPLETED']);
        $query = clone $base;
        $this->filters($query, $filters, 'orders.order_number', ['orders.batch_number', 'item.code'], 'orders.status');
        $this->sort($query, $filters, 'orders', 'order_number');
        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($page->items())->map(fn (object $row) => $this->orderPayload($row, $permissions, true))->all(),
            'meta' => $this->meta($page), 'summary' => $this->outputSummary($scope),
            'lookups' => ['statuses' => ['IN_PROCESS', 'COMPLETED'], 'sorts' => self::SORTS,
                'event_types' => ManufacturingExecutionService::OUTPUT_TYPES,
                'rework_dispositions' => ManufacturingExecutionService::REWORK_DISPOSITIONS,
                'loss_categories' => ManufacturingExecutionService::LOSS_CATEGORIES,
                'quantity_dimensions' => ManufacturingExecutionService::QUANTITY_DIMENSIONS,
                'value_sources' => ManufacturingExecutionService::VALUE_SOURCES,
                'uoms' => DB::table('uoms')->orderBy('code')->get(['code', 'name'])->map(fn (object $uom): array => [
                    'code' => (string) $uom->code, 'name' => (string) $uom->name,
                ])->all()],
            'allowed_actions' => [],
        ];
    }

    public function labWorkspace(array $scope, array $filters, array $permissions): array
    {
        $base = DB::table('lab_samples as sample')
            ->join('production_orders as orders', 'orders.id', '=', 'sample.production_order_id')
            ->join('quality_specifications as spec', 'spec.id', '=', 'sample.specification_id')
            ->where('sample.company_id', $scope['company_id'])->where('sample.plant_id', $scope['plant_id'])
            ->select(['sample.*', 'orders.order_number', 'orders.batch_number', 'orders.output_sku_id',
                'spec.code as specification_code', 'spec.name as specification_name']);
        $query = clone $base;
        $this->filters($query, $filters, 'sample.sample_number', ['orders.order_number', 'orders.batch_number', 'spec.code'], 'sample.status');
        $this->sort($query, $filters, 'sample', 'sample_number', 'sampled_at');
        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($page->items())->map(fn (object $row) => $this->samplePayload($row, $permissions, false))->all(),
            'meta' => $this->meta($page),
            'summary' => [
                'total' => (clone $base)->count(), 'pending' => (clone $base)->where('sample.status', 'PENDING')->count(),
                'passed' => (clone $base)->where('sample.status', 'PASSED')->count(),
                'failed' => (clone $base)->where('sample.status', 'FAILED')->count(),
            ],
            'lookups' => ['statuses' => ManufacturingQualityService::SAMPLE_STATUSES, 'sorts' => self::SORTS,
                'production_orders' => $this->qualityOrders($scope), 'specifications' => $this->specifications($scope)],
            'allowed_actions' => $this->can($permissions, 'ACTION:QC-LAB:CREATE') ? ['CREATE'] : [],
        ];
    }

    public function labDetail(string $id, array $scope, array $permissions): array
    {
        $row = DB::table('lab_samples as sample')
            ->join('production_orders as orders', 'orders.id', '=', 'sample.production_order_id')
            ->join('quality_specifications as spec', 'spec.id', '=', 'sample.specification_id')
            ->where('sample.id', $id)->where('sample.company_id', $scope['company_id'])
            ->where('sample.plant_id', $scope['plant_id'])->first([
                'sample.*', 'orders.order_number', 'orders.batch_number', 'orders.output_sku_id',
                'spec.code as specification_code', 'spec.name as specification_name',
            ]);
        if (! $row) {
            throw new NotFoundHttpException('Lab sample not found.');
        }

        return $this->samplePayload($row, $permissions, true);
    }

    public function safetyWorkspace(array $scope, array $filters, array $permissions): array
    {
        $deviations = DB::table('quality_deviations as record')
            ->join('production_orders as orders', 'orders.id', '=', 'record.production_order_id')
            ->where('record.company_id', $scope['company_id'])->where('record.plant_id', $scope['plant_id'])
            ->get(['record.*', 'orders.order_number', 'orders.batch_number'])
            ->map(fn (object $row) => $this->deviationPayload($row, $permissions));
        $holds = DB::table('food_safety_holds as record')
            ->join('production_orders as orders', 'orders.id', '=', 'record.production_order_id')
            ->where('record.company_id', $scope['company_id'])->where('record.plant_id', $scope['plant_id'])
            ->get(['record.*', 'orders.order_number', 'orders.batch_number'])
            ->map(fn (object $row) => $this->holdPayload($row, $permissions));
        $records = $deviations->concat($holds);
        if ($status = $filters['status'] ?? null) {
            $records = $records->where('status', $status);
        }
        if ($q = mb_strtolower(trim((string) ($filters['q'] ?? '')))) {
            $records = $records->filter(fn (array $row) => str_contains(mb_strtolower(implode(' ', [
                $row['number'], $row['production_order']['number'], $row['production_order']['batch_number'], $row['description'],
            ])), $q));
        }
        $records = ($filters['sort'] ?? 'NEWEST') === 'OLDEST'
            ? $records->sortBy('occurred_at')->values()
            : $records->sortByDesc('occurred_at')->values();
        $page = $this->collectionPage($records, $filters);

        return [
            'data' => $page->items(), 'meta' => $this->meta($page),
            'summary' => ['total' => $records->count(), 'open_deviations' => $deviations->where('status', 'OPEN')->count(),
                'active_holds' => $holds->where('status', 'ACTIVE')->count(), 'released_holds' => $holds->where('status', 'RELEASED')->count()],
            'lookups' => ['statuses' => ['OPEN', 'RESOLVED', 'ACTIVE', 'RELEASED'], 'sorts' => self::SORTS,
                'hazard_types' => ManufacturingQualityService::HAZARD_TYPES,
                'deviation_dispositions' => ManufacturingQualityService::DEVIATION_DISPOSITIONS,
                'production_orders' => $this->holdableOrders($scope)],
            'allowed_actions' => $this->can($permissions, 'ACTION:QC-SAFE:HOLD') ? ['HOLD'] : [],
        ];
    }

    public function safetyDetail(string $kind, string $id, array $scope, array $permissions): array
    {
        if ($kind === 'DEVIATION') {
            $row = DB::table('quality_deviations as record')->join('production_orders as orders', 'orders.id', '=', 'record.production_order_id')
                ->where('record.id', $id)->where('record.company_id', $scope['company_id'])->where('record.plant_id', $scope['plant_id'])
                ->first(['record.*', 'orders.order_number', 'orders.batch_number']);
            if ($row) {
                return $this->deviationPayload($row, $permissions);
            }
        } else {
            $row = DB::table('food_safety_holds as record')->join('production_orders as orders', 'orders.id', '=', 'record.production_order_id')
                ->where('record.id', $id)->where('record.company_id', $scope['company_id'])->where('record.plant_id', $scope['plant_id'])
                ->first(['record.*', 'orders.order_number', 'orders.batch_number']);
            if ($row) {
                return $this->holdPayload($row, $permissions);
            }
        }
        throw new NotFoundHttpException('Quality-safety record not found.');
    }

    public function artworkWorkspace(array $scope, array $filters, array $permissions): array
    {
        $base = DB::table('packaging_artworks as artwork')->join('items as item', 'item.id', '=', 'artwork.output_sku_id')
            ->where('artwork.company_id', $scope['company_id'])->where('artwork.plant_id', $scope['plant_id'])
            ->select(['artwork.*', 'item.code as item_code', 'item.name as item_name']);
        $query = clone $base;
        $this->filters($query, $filters, 'artwork.artwork_code', ['artwork.label_name', 'artwork.barcode', 'item.code'], 'artwork.status');
        $this->sort($query, $filters, 'artwork', 'artwork_code', 'effective_from');
        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($page->items())->map(fn (object $row) => $this->artworkPayload($row, $permissions))->all(),
            'meta' => $this->meta($page),
            'summary' => ['total' => (clone $base)->count(), 'draft' => (clone $base)->where('artwork.status', 'DRAFT')->count(),
                'approved' => (clone $base)->where('artwork.status', 'APPROVED')->count(), 'retired' => (clone $base)->where('artwork.status', 'RETIRED')->count()],
            'lookups' => ['statuses' => ManufacturingQualityService::ARTWORK_STATUSES, 'sorts' => self::SORTS,
                'output_skus' => $this->outputSkus($scope)],
            'allowed_actions' => $this->can($permissions, 'ACTION:PACK-ART:CREATE') ? ['CREATE'] : [],
        ];
    }

    public function artworkDetail(string $id, array $scope, array $permissions): array
    {
        $row = DB::table('packaging_artworks as artwork')->join('items as item', 'item.id', '=', 'artwork.output_sku_id')
            ->where('artwork.id', $id)->where('artwork.company_id', $scope['company_id'])->where('artwork.plant_id', $scope['plant_id'])
            ->first(['artwork.*', 'item.code as item_code', 'item.name as item_name']);
        if (! $row) {
            throw new NotFoundHttpException('Packaging artwork not found.');
        }

        return $this->artworkPayload($row, $permissions);
    }

    public function packingWorkspace(array $scope, array $filters, array $permissions): array
    {
        $base = $this->packingBase($scope);
        $query = clone $base;
        $this->filters($query, $filters, 'run.run_number', ['run.finished_lot_code', 'orders.order_number', 'orders.batch_number'], 'run.status');
        $this->sort($query, $filters, 'run', 'run_number', 'manufacture_date');
        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($page->items())->map(fn (object $row) => $this->packingPayload($row, $permissions, false))->all(),
            'meta' => $this->meta($page),
            'summary' => ['total' => (clone $base)->count(), 'draft' => (clone $base)->where('run.status', 'DRAFT')->count(),
                'completed' => (clone $base)->where('run.status', 'COMPLETED')->count(), 'packed_quantity' => $this->decimal((clone $base)->where('run.status', 'COMPLETED')->sum('run.packed_quantity'))],
            'lookups' => ['statuses' => ManufacturingQualityService::PACKING_STATUSES, 'sorts' => self::SORTS,
                'production_orders' => $this->packableOrders($scope), 'artworks' => $this->approvedArtworks($scope),
                'locations' => $this->finishedLocations($scope),
                'uoms' => DB::table('uoms')->orderBy('code')->get(['code', 'name'])->map(fn (object $uom): array => (array) $uom)->all()],
            'allowed_actions' => $this->can($permissions, 'ACTION:PACK-RUN:CREATE') ? ['CREATE'] : [],
        ];
    }

    public function packingDetail(string $id, array $scope, array $permissions): array
    {
        $row = $this->packingBase($scope)->where('run.id', $id)->first();
        if (! $row) {
            throw new NotFoundHttpException('Packing run not found.');
        }

        return $this->packingPayload($row, $permissions, true);
    }

    public function finishedGoodsWorkspace(array $scope, array $filters): array
    {
        $base = DB::table('packing_runs as run')
            ->join('production_orders as orders', 'orders.id', '=', 'run.production_order_id')
            ->join('lots as lot', 'lot.id', '=', 'run.finished_lot_id')
            ->join('items as item', 'item.id', '=', 'lot.item_id')
            ->join('stock_positions as position', 'position.id', '=', 'run.finished_position_id')
            ->join('locations as location', 'location.id', '=', 'position.location_id')
            ->where('run.company_id', $scope['company_id'])->where('run.plant_id', $scope['plant_id'])
            ->where('run.status', 'COMPLETED')
            ->select(['run.id as packing_run_id', 'run.run_number', 'run.coding_value', 'orders.id as production_order_id',
                'orders.order_number', 'orders.batch_number', 'lot.*', 'item.code as item_code', 'item.name as item_name',
                'position.id as position_id', 'position.quantity_base', 'position.quality_status', 'position.uom_code',
                'location.code as location_code', 'location.name as location_name']);
        $query = clone $base;
        $this->filters($query, $filters, 'lot.internal_lot_code', ['item.code', 'item.name', 'orders.order_number', 'orders.batch_number'], 'lot.status');
        $this->sort($query, $filters, 'lot', 'internal_lot_code', 'manufacture_date');
        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return ['data' => collect($page->items())->map(fn (object $row) => $this->finishedLotPayload($row, false))->all(),
            'meta' => $this->meta($page),
            'summary' => ['total' => (clone $base)->count(), 'active' => (clone $base)->where('lot.status', 'ACTIVE')->count(),
                'recalled' => (clone $base)->where('lot.status', 'RECALLED')->count(), 'on_hand_quantity' => $this->decimal((clone $base)->sum('position.quantity_base'))],
            'lookups' => ['statuses' => ['ACTIVE', 'CLOSED', 'RECALLED'], 'sorts' => self::SORTS], 'allowed_actions' => []];
    }

    public function finishedGoodsDetail(string $lotId, array $scope): array
    {
        $row = DB::table('packing_runs as run')
            ->join('production_orders as orders', 'orders.id', '=', 'run.production_order_id')
            ->join('lots as lot', 'lot.id', '=', 'run.finished_lot_id')
            ->join('items as item', 'item.id', '=', 'lot.item_id')
            ->join('stock_positions as position', 'position.id', '=', 'run.finished_position_id')
            ->join('locations as location', 'location.id', '=', 'position.location_id')
            ->where('lot.id', $lotId)->where('run.company_id', $scope['company_id'])->where('run.plant_id', $scope['plant_id'])
            ->first(['run.id as packing_run_id', 'run.run_number', 'run.coding_value', 'orders.id as production_order_id',
                'orders.order_number', 'orders.batch_number', 'lot.*', 'item.code as item_code', 'item.name as item_name',
                'position.id as position_id', 'position.quantity_base', 'position.quality_status', 'position.uom_code',
                'location.code as location_code', 'location.name as location_name']);
        if (! $row) {
            throw new NotFoundHttpException('Finished-goods lot not found.');
        }

        return $this->finishedLotPayload($row, true);
    }

    public function traceWorkspace(array $scope, array $filters, array $permissions): array
    {
        $base = DB::table('recall_cases as recall')->join('lots as lot', 'lot.id', '=', 'recall.source_lot_id')
            ->where('recall.company_id', $scope['company_id'])->where('recall.plant_id', $scope['plant_id'])
            ->select(['recall.*', 'lot.internal_lot_code as source_lot_code']);
        $query = clone $base;
        $this->filters($query, $filters, 'recall.recall_number', ['lot.internal_lot_code', 'recall.reason'], 'recall.status');
        $this->sort($query, $filters, 'recall', 'recall_number', 'initiated_at');
        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return ['data' => collect($page->items())->map(fn (object $row) => $this->recallPayload($row, $permissions, false))->all(),
            'meta' => $this->meta($page),
            'summary' => ['total' => (clone $base)->count(), 'open' => (clone $base)->where('recall.status', 'OPEN')->count(),
                'closed' => (clone $base)->where('recall.status', 'CLOSED')->count()],
            'lookups' => ['statuses' => ['OPEN', 'CLOSED'], 'sorts' => self::SORTS,
                'classifications' => ManufacturingTraceCostService::RECALL_CLASSIFICATIONS, 'lots' => $this->traceableLots($scope)],
            'allowed_actions' => $this->can($permissions, 'ACTION:TRACE-CASE:CREATE') ? ['CREATE'] : []];
    }

    public function recallDetail(string $id, array $scope, array $permissions): array
    {
        $row = DB::table('recall_cases as recall')->join('lots as lot', 'lot.id', '=', 'recall.source_lot_id')
            ->where('recall.id', $id)->where('recall.company_id', $scope['company_id'])->where('recall.plant_id', $scope['plant_id'])
            ->first(['recall.*', 'lot.internal_lot_code as source_lot_code']);
        if (! $row) {
            throw new NotFoundHttpException('Recall case not found.');
        }

        return $this->recallPayload($row, $permissions, true);
    }

    public function lotTrace(string $lotId, array $scope): array
    {
        $lot = DB::table('lots as lot')->join('items as item', 'item.id', '=', 'lot.item_id')
            ->where('lot.id', $lotId)->where('lot.company_id', $scope['company_id'])
            ->first(['lot.*', 'item.code as item_code', 'item.name as item_name']);
        if (! $lot) {
            throw new NotFoundHttpException('Traceable lot not found.');
        }
        [$nodes, $edges] = $this->genealogy($lotId, $scope);
        $shipments = DB::table('shipment_lines as line')->join('shipments as shipment', 'shipment.id', '=', 'line.shipment_id')
            ->leftJoin('parties as party', 'party.id', '=', 'shipment.party_id')
            ->where('line.company_id', $scope['company_id'])->where('line.plant_id', $scope['plant_id'])
            ->whereIn('line.fg_lot_id', array_keys($nodes))->get([
                'shipment.id', 'shipment.shipment_number', 'shipment.status', 'shipment.dispatched_at',
                'party.id as party_id', 'party.code as party_code', 'party.display_name as party_name',
                'line.fg_lot_id', 'line.shipped_quantity', 'line.returned_quantity', 'line.uom_code',
            ])->map(fn (object $row) => [
                'id' => (string) $row->id, 'number' => (string) $row->shipment_number, 'status' => (string) $row->status,
                'dispatched_at' => $row->dispatched_at, 'lot_id' => (string) $row->fg_lot_id,
                'shipped_quantity' => $this->decimal($row->shipped_quantity), 'returned_quantity' => $this->decimal($row->returned_quantity),
                'uom_code' => (string) $row->uom_code, 'customer' => $row->party_id ? ['id' => (string) $row->party_id,
                    'code' => (string) $row->party_code, 'name' => (string) $row->party_name] : null,
            ])->all();

        return ['lot' => $this->lotIdentity($lot), 'nodes' => array_values($nodes), 'edges' => $edges, 'shipments' => $shipments];
    }

    public function costWorkspace(array $scope, array $filters, array $permissions): array
    {
        $base = DB::table('batch_costs as cost')->join('production_orders as orders', 'orders.id', '=', 'cost.production_order_id')
            ->join('items as output_item', 'output_item.id', '=', 'orders.output_sku_id')
            ->join('users as calculator', 'calculator.id', '=', 'cost.calculated_by')
            ->where('cost.company_id', $scope['company_id'])->where('cost.plant_id', $scope['plant_id'])
            ->select(['cost.*', 'orders.order_number', 'orders.batch_number', 'orders.output_sku_id',
                'orders.recipe_revision_snapshot', 'orders.shift_code', 'output_item.code as output_sku_code',
                'output_item.name as output_sku_name', 'calculator.name as calculator_name']);
        $query = clone $base;
        $this->filters($query, $filters, 'cost.cost_number', ['orders.order_number', 'orders.batch_number', 'output_item.code', 'orders.shift_code'], 'cost.status');
        if ($filters['recipe_version'] ?? null) $query->where('orders.recipe_revision_snapshot', $filters['recipe_version']);
        if ($filters['output_sku_id'] ?? null) $query->where('orders.output_sku_id', $filters['output_sku_id']);
        if ($filters['shift_code'] ?? null) $query->where('orders.shift_code', $filters['shift_code']);
        if ($filters['operator_id'] ?? null) $query->where('cost.calculated_by', $filters['operator_id']);
        if ($filters['date_from'] ?? null) $query->whereDate('cost.calculated_at', '>=', $filters['date_from']);
        if ($filters['date_to'] ?? null) $query->whereDate('cost.calculated_at', '<=', $filters['date_to']);
        $this->sort($query, $filters, 'cost', 'cost_number', 'calculated_at');
        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return ['data' => collect($page->items())->map(fn (object $row) => $this->costPayload($row, false))->all(),
            'meta' => $this->meta($page),
            'summary' => ['total' => (clone $base)->count(), 'actual_total_cost' => $this->decimal((clone $base)->sum('cost.actual_total_cost')),
                'total_variance' => $this->decimal((clone $base)->sum('cost.total_variance'))],
            'lookups' => ['statuses' => ['FINALIZED'], 'sorts' => self::SORTS, 'production_orders' => $this->costableOrders($scope),
                'output_skus' => DB::table('production_orders as orders')->join('items as item', 'item.id', '=', 'orders.output_sku_id')
                    ->where('orders.company_id', $scope['company_id'])->where('orders.plant_id', $scope['plant_id'])
                    ->distinct()->orderBy('item.code')->get(['item.id', 'item.code', 'item.name'])->map(fn (object $item): array => (array) $item)->all(),
                'shifts' => DB::table('production_orders')->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])
                    ->whereNotNull('shift_code')->distinct()->orderBy('shift_code')->pluck('shift_code')->all(),
                'operators' => DB::table('batch_costs as cost')->join('users as user', 'user.id', '=', 'cost.calculated_by')
                    ->where('cost.company_id', $scope['company_id'])->where('cost.plant_id', $scope['plant_id'])
                    ->distinct()->orderBy('user.name')->get(['user.id', 'user.name'])->map(fn (object $user): array => (array) $user)->all()],
            'allowed_actions' => $this->can($permissions, 'ACTION:COST-BATCH:CALCULATE') ? ['CALCULATE'] : []];
    }

    public function costDetail(string $id, array $scope): array
    {
        $row = DB::table('batch_costs as cost')->join('production_orders as orders', 'orders.id', '=', 'cost.production_order_id')
            ->join('items as output_item', 'output_item.id', '=', 'orders.output_sku_id')
            ->join('users as calculator', 'calculator.id', '=', 'cost.calculated_by')
            ->where('cost.id', $id)->where('cost.company_id', $scope['company_id'])->where('cost.plant_id', $scope['plant_id'])
            ->first(['cost.*', 'orders.order_number', 'orders.batch_number', 'orders.output_sku_id',
                'orders.recipe_revision_snapshot', 'orders.shift_code', 'output_item.code as output_sku_code',
                'output_item.name as output_sku_name', 'calculator.name as calculator_name']);
        if (! $row) {
            throw new NotFoundHttpException('Batch cost snapshot not found.');
        }

        return $this->costPayload($row, true);
    }

    private function orderBase(array $scope): Builder
    {
        return DB::table('production_orders as orders')
            ->join('items as item', 'item.id', '=', 'orders.output_sku_id')
            ->join('recipes as recipe', 'recipe.id', '=', 'orders.recipe_id')
            ->join('production_routes as route', 'route.id', '=', 'orders.route_id')
            ->join('production_schedules as schedule', 'schedule.id', '=', 'orders.production_schedule_id')
            ->join('users as creator', 'creator.id', '=', 'orders.created_by')
            ->where('orders.company_id', $scope['company_id'])->where('orders.plant_id', $scope['plant_id'])
            ->select(['orders.*', 'item.code as item_code', 'item.name as item_name', 'recipe.code as recipe_code',
                'recipe.revision as recipe_revision', 'route.code as route_code', 'route.name as route_name',
                'schedule.schedule_number', 'creator.name as creator_name']);
    }

    private function packingBase(array $scope): Builder
    {
        return DB::table('packing_runs as run')->join('production_orders as orders', 'orders.id', '=', 'run.production_order_id')
            ->join('packaging_artworks as artwork', 'artwork.id', '=', 'run.packaging_artwork_id')
            ->join('locations as location', 'location.id', '=', 'run.target_location_id')
            ->where('run.company_id', $scope['company_id'])->where('run.plant_id', $scope['plant_id'])
            ->select(['run.*', 'orders.order_number', 'orders.batch_number', 'orders.output_sku_id',
                'artwork.artwork_code', 'artwork.revision as artwork_revision', 'location.code as location_code']);
    }

    private function orderPayload(object $row, array $permissions, bool $detail): array
    {
        $metrics = $this->productionMetrics($row->id);
        $actions = [];
        foreach ([
            'DRAFT' => ['RELEASE' => 'ACTION:PRO-ORDER:RELEASE', 'CANCEL' => 'ACTION:PRO-ORDER:CANCEL'],
            'RELEASED' => ['ISSUE_MATERIALS' => 'ACTION:PRO-ORDER:ISSUE', 'CANCEL' => 'ACTION:PRO-ORDER:CANCEL'],
            'IN_PROCESS' => ['RECORD_OUTPUT' => 'ACTION:PRO-LOSS:RECORD', 'COMPLETE' => 'ACTION:PRO-ORDER:COMPLETE'],
        ][$row->status] ?? [] as $action => $permission) {
            if ($this->can($permissions, $permission)) {
                $actions[] = $action;
            }
        }
        if (in_array($row->status, ['RELEASED', 'IN_PROCESS'], true)
            && $this->can($permissions, 'ACTION:PRO-ORDER:ISSUE')) {
            $actions[] = 'ISSUE_MATERIAL';
            if ($row->status === 'IN_PROCESS') {
                $actions[] = 'RETURN_MATERIAL';
                $actions[] = 'DISPOSE_MATERIAL';
            }
        }
        if ($row->status === 'IN_PROCESS' && $this->can($permissions, 'ACTION:QC-SAFE:RELEASE')
            && DB::table('production_material_postings')->where('production_order_id', $row->id)
                ->where('posting_type', 'RETURN_QUARANTINE')->where('status', 'QC_PENDING')->exists()) {
            $actions[] = 'APPROVE_MATERIAL_RETURN';
        }
        if ($row->status === 'COMPLETED' && $row->quality_status !== 'RELEASED'
            && $this->can($permissions, 'ACTION:QC-SAFE:RELEASE')) {
            $actions[] = 'QUALITY_RELEASE';
        }
        if ($row->status === 'COMPLETED' && $this->can($permissions, 'ACTION:PRO-ORDER:COMPLETE')) {
            $actions[] = 'REQUEST_ADJUSTMENT';
        }
        $payload = [
            'id' => (string) $row->id, 'order_number' => (string) $row->order_number,
            'batch_number' => (string) $row->batch_number, 'schedule' => ['id' => (string) $row->production_schedule_id,
                'number' => (string) $row->schedule_number, 'line_id' => (string) $row->production_schedule_line_id],
            'output_sku' => ['id' => (string) $row->output_sku_id, 'code' => (string) $row->item_code, 'name' => (string) $row->item_name],
            'recipe' => ['id' => (string) $row->recipe_id, 'code' => (string) $row->recipe_code, 'revision' => (int) $row->recipe_revision],
            'route' => ['id' => (string) $row->route_id, 'code' => (string) $row->route_code, 'name' => (string) $row->route_name],
            'planned_quantity' => $this->decimal($row->planned_quantity), 'uom_code' => (string) $row->uom_code,
            'planned_start_date' => (string) $row->planned_start_date, 'planned_end_date' => (string) $row->planned_end_date,
            'shift_code' => $row->shift_code,
            'status' => (string) $row->status, 'quality_status' => (string) $row->quality_status,
            'record_version' => (int) $row->record_version, 'notes' => $row->notes,
            'created_by' => ['id' => (string) $row->created_by, 'name' => (string) $row->creator_name],
            'released_at' => $row->released_at, 'started_at' => $row->started_at, 'completed_at' => $row->completed_at,
            'quality_released_at' => $row->quality_released_at, 'cancelled_at' => $row->cancelled_at,
            'cancellation_reason' => $row->cancellation_reason, 'allowed_actions' => $actions,
        ] + $metrics;
        if (! $detail) {
            return $payload;
        }
        $materialReconciliation = $this->materialReconciliation->forOrder((string) $row->id);
        $materialMetrics = collect($materialReconciliation['materials'])->keyBy('id');
        $payload['material_normalisation'] = $materialReconciliation['basis'];
        $payload['materials'] = DB::table('production_order_materials as material')
            ->join('items as item', 'item.id', '=', 'material.component_sku_id')
            ->where('material.production_order_id', $row->id)->orderBy('material.line_number')
            ->get(['material.*', 'item.code as item_code', 'item.name as item_name'])
            ->map(function (object $material) use ($materialMetrics): array {
                $reconciled = $materialMetrics->get((string) $material->id, []);
                $approvedAdjustment = $this->decimal($reconciled['approved_adjustment_quantity'] ?? 0);
                $rawNetConsumed = $this->decimal($reconciled['recorded_net_consumed_quantity'] ?? $material->net_consumed_quantity);
                $adjustedNetConsumed = $this->decimal($reconciled['adjusted_net_consumed_quantity']
                    ?? bcadd($rawNetConsumed, $approvedAdjustment, 6));
                $issues = DB::table('production_material_issues as issue')->join('lots as lot', 'lot.id', '=', 'issue.input_lot_id')
                    ->where('issue.production_order_material_id', $material->id)->orderBy('issue.issued_at')
                    ->get(['issue.*', 'lot.internal_lot_code'])->map(fn (object $issue) => [
                        'id' => (string) $issue->id, 'lot_id' => (string) $issue->input_lot_id,
                        'lot_code' => (string) $issue->internal_lot_code, 'quantity' => $this->decimal($issue->quantity),
                        'uom_code' => (string) $issue->uom_code, 'movement_id' => (string) $issue->stock_movement_id,
                        'issued_at' => $issue->issued_at,
                    ])->all();
                $postings = DB::table('production_material_postings as posting')
                    ->join('lots as lot', 'lot.id', '=', 'posting.lot_id')
                    ->where('posting.production_order_material_id', $material->id)->orderBy('posting.posted_at')
                    ->get(['posting.*', 'lot.internal_lot_code'])->map(fn (object $posting): array => [
                        'id' => (string) $posting->id, 'posting_type' => (string) $posting->posting_type,
                        'quantity' => $this->decimal($posting->quantity), 'uom_code' => (string) $posting->uom_code,
                        'lot_id' => (string) $posting->lot_id, 'lot_code' => (string) $posting->internal_lot_code,
                        'owner_id' => (string) $posting->inventory_owner_id, 'quality_status' => (string) $posting->quality_status,
                        'status' => (string) $posting->status, 'reason_code' => (string) $posting->reason_code,
                        'disposition_code' => $posting->disposition_code,
                        'stock_movement_id' => $posting->stock_movement_id === null ? null : (string) $posting->stock_movement_id,
                        'parent_posting_id' => $posting->parent_posting_id,
                        'from_position_id' => $posting->from_position_id, 'to_position_id' => $posting->to_position_id,
                        'posted_at' => $posting->posted_at,
                    ])->all();
                $positions = DB::table('stock_positions as position')
                    ->join('lots as lot', 'lot.id', '=', 'position.lot_id')
                    ->join('locations as location', 'location.id', '=', 'position.location_id')
                    ->join('inventory_quality_statuses as quality', 'quality.code', '=', 'position.quality_status')
                    ->where('position.company_id', $material->company_id)->where('position.plant_id', $material->plant_id)
                    ->where('position.item_id', $material->component_sku_id)->where('position.uom_code', $material->uom_code)
                    ->orderByDesc('quality.is_reservable')->orderBy('lot.expiry_date')->orderBy('lot.internal_lot_code')
                    ->get(['position.id', 'position.lot_id', 'position.inventory_owner_id', 'position.quality_status',
                        'position.quantity_base', 'position.reserved_quantity_base', 'position.uom_code',
                        'quality.is_reservable', 'lot.internal_lot_code', 'location.code as location_code'])
                    ->map(fn (object $position): array => [
                        'id' => (string) $position->id, 'lot_id' => (string) $position->lot_id,
                        'lot_code' => (string) $position->internal_lot_code,
                        'inventory_owner_id' => (string) $position->inventory_owner_id,
                        'quality_status' => (string) $position->quality_status,
                        'is_reservable' => (bool) $position->is_reservable,
                        'quantity' => $this->decimal($position->quantity_base),
                        'reserved_quantity' => $this->decimal($position->reserved_quantity_base),
                        'available_quantity' => bcsub($this->decimal($position->quantity_base), $this->decimal($position->reserved_quantity_base), 6),
                        'uom_code' => (string) $position->uom_code, 'location_code' => (string) $position->location_code,
                    ])->all();

                return ['id' => (string) $material->id, 'line_number' => (int) $material->line_number,
                    'component_sku' => ['id' => (string) $material->component_sku_id, 'code' => (string) $material->item_code, 'name' => (string) $material->item_name],
                    'required_quantity' => $this->decimal($material->required_quantity), 'issued_quantity' => $this->decimal($material->issued_quantity),
                    'reserved_quantity' => $this->decimal($material->reserved_quantity),
                    'additional_issued_quantity' => $this->decimal($material->additional_issued_quantity),
                    'returned_quantity' => $this->decimal($material->returned_quantity),
                    'wasted_quantity' => $this->decimal($material->wasted_quantity),
                    'rework_in_quantity' => $this->decimal($material->rework_in_quantity),
                    'rework_out_quantity' => $this->decimal($material->rework_out_quantity),
                    'net_consumed_quantity' => $rawNetConsumed,
                    'approved_adjustment_quantity' => $approvedAdjustment,
                    'adjusted_net_consumed_quantity' => $adjustedNetConsumed,
                    'original_plan_quantity' => $this->decimal($material->original_plan_quantity ?? $material->required_quantity),
                    'normalised_standard_quantity' => $this->decimal($reconciled['normalised_standard_quantity']
                        ?? $material->normalised_standard_quantity ?? $material->required_quantity),
                    'original_plan_variance' => $this->decimal($reconciled['original_plan_variance'] ?? $material->original_plan_variance),
                    'efficiency_variance' => $this->decimal($reconciled['efficiency_variance'] ?? $material->efficiency_variance),
                    'adjusted_original_plan_variance' => $this->decimal($reconciled['original_plan_variance']
                        ?? bcsub($adjustedNetConsumed, $this->decimal($material->original_plan_quantity ?? $material->required_quantity), 6)),
                    'adjusted_efficiency_variance' => $this->decimal($reconciled['efficiency_variance']
                        ?? bcsub($adjustedNetConsumed, $this->decimal($material->normalised_standard_quantity ?? $material->required_quantity), 6)),
                    'scaling_method' => (string) $material->scaling_method_snapshot,
                    'is_packaging' => (bool) $material->is_packaging,
                    'uom_code' => (string) $material->uom_code, 'issues' => $issues,
                    'postings' => $postings, 'stock_positions' => $positions];
            })->all();
        $payload['stages'] = DB::table('production_order_stages')->where('production_order_id', $row->id)
            ->orderBy('sequence_no')->get()->map(fn (object $stage) => $this->stagePayload((object) ((array) $stage + [
                'order_number' => $row->order_number, 'batch_number' => $row->batch_number, 'order_status' => $row->status,
            ]), $permissions))->all();
        $payload['outputs'] = $this->outputEvents($row->id, $permissions);
        $payload['adjustments'] = $this->batchAdjustments((string) $row->id, $permissions);
        $payload['cost_snapshots'] = DB::table('batch_costs')->where('production_order_id', $row->id)
            ->orderByDesc('snapshot_version')->get(['id', 'cost_number', 'snapshot_version', 'actual_total_cost'])
            ->map(fn (object $cost): array => ['id' => (string) $cost->id, 'cost_number' => (string) $cost->cost_number,
                'snapshot_version' => (int) $cost->snapshot_version,
                'actual_total_cost' => $this->decimal($cost->actual_total_cost)])->all();

        return $payload;
    }

    private function stagePayload(object $row, array $permissions): array
    {
        $actions = [];
        if ($row->order_status === 'IN_PROCESS' && $row->status === 'PENDING' && $this->can($permissions, 'ACTION:PRO-STAGE:START')) {
            $actions[] = 'START';
        }
        if ($row->order_status === 'IN_PROCESS' && $row->status === 'IN_PROGRESS' && $this->can($permissions, 'ACTION:PRO-STAGE:COMPLETE')) {
            $actions[] = 'COMPLETE';
            $actions[] = 'RECORD_MEASUREMENTS';
        }
        if ($row->status === 'PENDING' && $this->can($permissions, 'ACTION:MD-REC:UPDATE')) {
            $actions[] = 'CONFIGURE_MEASUREMENTS';
        }

        $template = $row->measurement_template_snapshot ?? null;
        if (is_string($template) && $template !== '') {
            $template = json_decode($template, true, 512, JSON_THROW_ON_ERROR);
        }
        $stageContext = DB::table('production_order_stages as stage')
            ->join('production_orders as orders', 'orders.id', '=', 'stage.production_order_id')
            ->join('production_schedule_operations as operation', 'operation.id', '=', 'stage.production_schedule_operation_id')
            ->where('stage.id', $row->id)->first(['orders.recipe_id', 'orders.recipe_revision_snapshot',
                'orders.route_revision_snapshot', 'operation.route_operation_id']);
        $templates = $stageContext ? DB::table('stage_measurement_templates as template')
            ->join('users as creator', 'creator.id', '=', 'template.created_by')
            ->leftJoin('users as approver', 'approver.id', '=', 'template.approved_by')
            ->where('template.recipe_id', $stageContext->recipe_id)
            ->where('template.route_operation_id', $stageContext->route_operation_id)
            ->where('template.recipe_revision', $stageContext->recipe_revision_snapshot)
            ->where('template.route_revision', $stageContext->route_revision_snapshot)
            ->orderByDesc('template.revision')->get(['template.*', 'creator.name as creator_name', 'approver.name as approver_name'])
            ->map(function (object $candidate) use ($permissions): array {
                return ['id' => (string) $candidate->id, 'revision' => (int) $candidate->revision,
                    'recipe_revision' => (int) $candidate->recipe_revision,
                    'route_revision' => (int) $candidate->route_revision,
                    'name' => (string) $candidate->name, 'status' => (string) $candidate->status,
                    'record_version' => (int) $candidate->record_version,
                    'created_by' => ['id' => (string) $candidate->created_by, 'name' => (string) $candidate->creator_name],
                    'approved_by' => $candidate->approved_by ? ['id' => (string) $candidate->approved_by,
                        'name' => (string) $candidate->approver_name] : null,
                    'approved_at' => $candidate->approved_at,
                    'lines' => DB::table('stage_measurement_template_lines')->where('template_id', $candidate->id)
                        ->orderBy('sequence_no')->get()->map(fn (object $line): array => [
                            'id' => (string) $line->id, 'sequence_no' => (int) $line->sequence_no,
                            'measurement_kind' => (string) $line->measurement_kind,
                            'quantity_dimension' => (string) $line->quantity_dimension,
                            'uom_code' => (string) $line->uom_code, 'is_required' => (bool) $line->is_required,
                            'default_value_source' => (string) $line->default_value_source,
                            'measurement_method' => $line->measurement_method,
                            'balance_tolerance' => $this->decimal($line->balance_tolerance),
                        ])->all(),
                    'allowed_actions' => $candidate->status === 'DRAFT'
                        && $this->can($permissions, 'ACTION:MD-REC:LIFECYCLE') ? ['APPROVE'] : [],
                ];
            })->all() : [];
        $measurementAdjustments = DB::table('production_batch_adjustment_effects as effect')
            ->join('production_batch_adjustments as adjustment', 'adjustment.id', '=', 'effect.production_batch_adjustment_id')
            ->where('effect.metric_code', 'STAGE_MEASUREMENT')->where('effect.subject_id', $row->id)
            ->orderBy('effect.approved_at')->get(['effect.id', 'effect.signed_quantity', 'effect.uom_code',
                'effect.reason', 'effect.approved_at', 'adjustment.adjustment_number'])
            ->map(fn (object $effect): array => ['id' => (string) $effect->id,
                'adjustment_number' => (string) $effect->adjustment_number,
                'signed_quantity' => $this->decimal($effect->signed_quantity),
                'uom_code' => (string) $effect->uom_code, 'reason' => (string) $effect->reason,
                'approved_at' => $effect->approved_at])->all();

        return ['id' => (string) $row->id, 'production_order' => ['id' => (string) $row->production_order_id,
            'number' => (string) $row->order_number, 'batch_number' => (string) $row->batch_number, 'status' => (string) $row->order_status],
            'sequence_no' => (int) $row->sequence_no, 'operation_name' => (string) $row->operation_name,
            'work_center_code' => (string) $row->work_center_code, 'planned_minutes' => $this->decimal($row->planned_minutes),
            'actual_minutes' => $row->actual_minutes === null ? null : $this->decimal($row->actual_minutes),
            'status' => (string) $row->status, 'record_version' => (int) $row->record_version,
            'started_at' => $row->started_at, 'completed_at' => $row->completed_at, 'notes' => $row->notes,
            'measurement_template' => $template, 'measurement_templates' => $templates,
            'measurements' => $this->measurements->measurements((string) $row->id),
            'mass_balance' => $this->measurements->balance((string) $row->id),
            'measurement_adjustments' => $measurementAdjustments,
            'allowed_actions' => $actions];
    }

    private function samplePayload(object $row, array $permissions, bool $detail): array
    {
        $payload = ['id' => (string) $row->id, 'sample_number' => (string) $row->sample_number,
            'production_order' => ['id' => (string) $row->production_order_id, 'number' => (string) $row->order_number,
                'batch_number' => (string) $row->batch_number, 'output_sku_id' => (string) $row->output_sku_id],
            'specification' => ['id' => (string) $row->specification_id, 'code' => (string) $row->specification_code,
                'name' => (string) $row->specification_name, 'version' => (int) $row->specification_version_snapshot],
            'status' => (string) $row->status, 'record_version' => (int) $row->record_version,
            'notes' => $row->notes, 'sampled_at' => $row->sampled_at, 'completed_at' => $row->completed_at,
            'allowed_actions' => $row->status === 'PENDING' && $this->can($permissions, 'ACTION:QC-LAB:COMPLETE') ? ['COMPLETE'] : []];
        if ($detail) {
            $payload['results'] = DB::table('lab_results')->where('lab_sample_id', $row->id)->orderBy('sequence_no')->get()
                ->map(fn (object $result) => ['id' => (string) $result->id, 'sequence_no' => (int) $result->sequence_no,
                    'code' => (string) $result->parameter_code, 'name' => (string) $result->parameter_name,
                    'value_type' => (string) $result->value_type, 'uom_code' => $result->uom_code,
                    'minimum_value' => $result->minimum_value === null ? null : $this->decimal($result->minimum_value),
                    'target_value' => $result->target_value === null ? null : $this->decimal($result->target_value),
                    'maximum_value' => $result->maximum_value === null ? null : $this->decimal($result->maximum_value),
                    'text_requirement' => $result->text_requirement, 'is_required' => (bool) $result->is_required,
                    'numeric_value' => $result->numeric_value === null ? null : $this->decimal($result->numeric_value),
                    'text_value' => $result->text_value, 'boolean_value' => $result->boolean_value === null ? null : (bool) $result->boolean_value,
                    'result' => (string) $result->result, 'notes' => $result->notes])->all();
        }

        return $payload;
    }

    private function deviationPayload(object $row, array $permissions): array
    {
        return ['kind' => 'DEVIATION', 'id' => (string) $row->id, 'number' => (string) $row->deviation_number,
            'production_order' => ['id' => (string) $row->production_order_id, 'number' => (string) $row->order_number, 'batch_number' => (string) $row->batch_number],
            'category' => (string) $row->category, 'description' => (string) $row->description,
            'status' => (string) $row->status, 'disposition' => $row->disposition, 'root_cause' => $row->root_cause,
            'corrective_action' => $row->corrective_action, 'record_version' => (int) $row->record_version,
            'occurred_at' => $row->raised_at, 'resolved_at' => $row->resolved_at,
            'allowed_actions' => $row->status === 'OPEN' && $this->can($permissions, 'ACTION:QC-SAFE:RESOLVE') ? ['RESOLVE'] : []];
    }

    private function holdPayload(object $row, array $permissions): array
    {
        return ['kind' => 'HOLD', 'id' => (string) $row->id, 'number' => (string) $row->hold_number,
            'production_order' => ['id' => (string) $row->production_order_id, 'number' => (string) $row->order_number, 'batch_number' => (string) $row->batch_number],
            'category' => (string) $row->hazard_type, 'description' => (string) $row->reason,
            'status' => (string) $row->status, 'disposition' => $row->disposition, 'root_cause' => $row->root_cause,
            'corrective_action' => $row->corrective_action,
            'record_version' => (int) $row->record_version, 'occurred_at' => $row->placed_at, 'resolved_at' => $row->released_at,
            'allowed_actions' => $row->status === 'ACTIVE' && $this->can($permissions, 'ACTION:QC-SAFE:RELEASE-HOLD') ? ['RELEASE'] : []];
    }

    private function artworkPayload(object $row, array $permissions): array
    {
        $actions = [];
        if ($row->status === 'DRAFT') {
            if ($this->can($permissions, 'ACTION:PACK-ART:UPDATE')) $actions[] = 'UPDATE';
            if ($this->can($permissions, 'ACTION:PACK-ART:APPROVE')) $actions[] = 'APPROVE';
        } elseif ($row->status === 'APPROVED' && $this->can($permissions, 'ACTION:PACK-ART:RETIRE')) {
            $actions[] = 'RETIRE';
        }

        return ['id' => (string) $row->id, 'artwork_code' => (string) $row->artwork_code,
            'revision' => (int) $row->revision, 'output_sku' => ['id' => (string) $row->output_sku_id,
                'code' => (string) $row->item_code, 'name' => (string) $row->item_name],
            'label_name' => (string) $row->label_name, 'barcode' => (string) $row->barcode,
            'coding_template' => (string) $row->coding_template, 'effective_from' => (string) $row->effective_from,
            'effective_to' => $row->effective_to, 'status' => (string) $row->status,
            'record_version' => (int) $row->record_version, 'notes' => $row->notes,
            'approved_at' => $row->approved_at, 'retired_at' => $row->retired_at,
            'retirement_reason' => $row->retirement_reason, 'allowed_actions' => $actions];
    }

    private function packingPayload(object $row, array $permissions, bool $detail): array
    {
        $actions = [];
        if ($row->status === 'DRAFT') {
            if ($this->can($permissions, 'ACTION:PACK-RUN:COMPLETE')) $actions[] = 'COMPLETE';
            if ($this->can($permissions, 'ACTION:PACK-RUN:CANCEL')) $actions[] = 'CANCEL';
        }
        $payload = ['id' => (string) $row->id, 'run_number' => (string) $row->run_number,
            'production_order' => ['id' => (string) $row->production_order_id, 'number' => (string) $row->order_number,
                'batch_number' => (string) $row->batch_number, 'output_sku_id' => (string) $row->output_sku_id],
            'artwork' => ['id' => (string) $row->packaging_artwork_id, 'code' => (string) $row->artwork_code, 'revision' => (int) $row->artwork_revision],
            'packed_quantity' => $this->decimal($row->packed_quantity), 'uom_code' => (string) $row->uom_code,
            'pieces_per_pack' => (int) $row->pieces_per_pack,
            'target_finished_weight' => $row->target_finished_weight === null ? null : $this->decimal($row->target_finished_weight),
            'average_sample_weight' => $row->average_sample_weight === null ? null : $this->decimal($row->average_sample_weight),
            'weight_uom_code' => $row->weight_uom_code,
            'actual_weight_samples' => is_string($row->actual_weight_samples)
                ? json_decode($row->actual_weight_samples, true, 512, JSON_THROW_ON_ERROR) : $row->actual_weight_samples,
            'packing_configuration' => is_string($row->packing_configuration)
                ? json_decode($row->packing_configuration, true, 512, JSON_THROW_ON_ERROR) : $row->packing_configuration,
            'finished_lot_code' => (string) $row->finished_lot_code, 'manufacture_date' => (string) $row->manufacture_date,
            'expiry_date' => (string) $row->expiry_date, 'target_location' => ['id' => (string) $row->target_location_id, 'code' => (string) $row->location_code],
            'coding_value' => (string) $row->coding_value, 'status' => (string) $row->status,
            'record_version' => (int) $row->record_version, 'notes' => $row->notes,
            'finished_lot_id' => $row->finished_lot_id, 'finished_position_id' => $row->finished_position_id,
            'stock_movement_id' => $row->stock_movement_id, 'completed_at' => $row->completed_at,
            'cancelled_at' => $row->cancelled_at, 'cancellation_reason' => $row->cancellation_reason,
            'allowed_actions' => $actions];
        if ($detail && $row->finished_lot_id) {
            $payload['genealogy_edges'] = DB::table('lot_genealogy_edges as edge')->join('lots as lot', 'lot.id', '=', 'edge.input_lot_id')
                ->where('edge.packing_run_id', $row->id)->orderBy('lot.internal_lot_code')->get([
                    'edge.*', 'lot.internal_lot_code as input_lot_code',
                ])->map(fn (object $edge) => ['id' => (string) $edge->id, 'input_lot_id' => (string) $edge->input_lot_id,
                    'input_lot_code' => (string) $edge->input_lot_code, 'input_quantity' => $this->decimal($edge->input_quantity),
                    'input_uom_code' => (string) $edge->input_uom_code, 'output_quantity' => $this->decimal($edge->output_quantity),
                    'output_uom_code' => (string) $edge->output_uom_code])->all();
        }

        return $payload;
    }

    private function finishedLotPayload(object $row, bool $detail): array
    {
        $payload = ['id' => (string) $row->id, 'lot_code' => (string) $row->internal_lot_code,
            'sku' => ['id' => (string) $row->item_id, 'code' => (string) $row->item_code, 'name' => (string) $row->item_name],
            'production_order' => ['id' => (string) $row->production_order_id, 'number' => (string) $row->order_number, 'batch_number' => (string) $row->batch_number],
            'packing_run' => ['id' => (string) $row->packing_run_id, 'number' => (string) $row->run_number],
            'manufacture_date' => $row->manufacture_date, 'expiry_date' => $row->expiry_date,
            'status' => (string) $row->status, 'record_version' => (int) $row->record_version,
            'position' => ['id' => (string) $row->position_id, 'quantity' => $this->decimal($row->quantity_base),
                'uom_code' => (string) $row->uom_code, 'quality_status' => (string) $row->quality_status,
                'location_code' => (string) $row->location_code, 'location_name' => (string) $row->location_name],
            'coding_value' => (string) $row->coding_value];
        if ($detail) {
            $payload['inputs'] = DB::table('lot_genealogy_edges as edge')->join('lots as lot', 'lot.id', '=', 'edge.input_lot_id')
                ->join('items as item', 'item.id', '=', 'lot.item_id')->where('edge.output_lot_id', $row->id)
                ->get(['edge.*', 'lot.internal_lot_code', 'item.code as item_code', 'item.name as item_name'])
                ->map(fn (object $edge) => ['lot' => ['id' => (string) $edge->input_lot_id,
                    'code' => (string) $edge->internal_lot_code, 'item_code' => (string) $edge->item_code, 'item_name' => (string) $edge->item_name],
                    'quantity' => $this->decimal($edge->input_quantity), 'uom_code' => (string) $edge->input_uom_code])->all();
        }

        return $payload;
    }

    private function recallPayload(object $row, array $permissions, bool $detail): array
    {
        $payload = ['id' => (string) $row->id, 'recall_number' => (string) $row->recall_number,
            'source_lot' => ['id' => (string) $row->source_lot_id, 'code' => (string) $row->source_lot_code],
            'classification' => (string) $row->classification, 'reason' => (string) $row->reason,
            'status' => (string) $row->status, 'record_version' => (int) $row->record_version,
            'initiated_at' => $row->initiated_at, 'closed_at' => $row->closed_at,
            'closure_action' => $row->closure_action,
            'affected_lot_count' => DB::table('recall_case_lots')->where('recall_case_id', $row->id)->count(),
            'allowed_actions' => $row->status === 'OPEN' && $this->can($permissions, 'ACTION:TRACE-CASE:CLOSE') ? ['CLOSE'] : []];
        if ($detail) {
            $payload['lots'] = DB::table('recall_case_lots as affected')->join('lots as lot', 'lot.id', '=', 'affected.lot_id')
                ->join('items as item', 'item.id', '=', 'lot.item_id')->where('affected.recall_case_id', $row->id)
                ->orderBy('affected.depth')->orderBy('lot.internal_lot_code')->get([
                    'affected.*', 'lot.internal_lot_code', 'lot.status as lot_status', 'item.code as item_code', 'item.name as item_name',
                ])->map(fn (object $lot) => ['id' => (string) $lot->lot_id, 'code' => (string) $lot->internal_lot_code,
                    'item_code' => (string) $lot->item_code, 'item_name' => (string) $lot->item_name,
                    'relationship' => (string) $lot->relationship, 'depth' => (int) $lot->depth,
                    'on_hand_quantity' => $this->decimal($lot->on_hand_quantity), 'action_status' => (string) $lot->action_status,
                    'status' => (string) $lot->lot_status])->all();
            $payload['trace'] = $this->lotTrace($row->source_lot_id, ['company_id' => $row->company_id, 'plant_id' => $row->plant_id]);
        }

        return $payload;
    }

    private function costPayload(object $row, bool $detail): array
    {
        $approvedCostAdjustment = $this->decimal(DB::table('production_batch_adjustment_effects')
            ->where('metric_code', 'COST_VALUE')->where('subject_id', $row->id)->sum('signed_value'));
        $adjustedActualTotal = bcadd($this->decimal($row->actual_total_cost), $approvedCostAdjustment, 6);
        $adjustedVariance = bcsub($adjustedActualTotal, $this->decimal($row->planned_total_cost), 6);
        $adjustedVariancePercent = bccomp($this->decimal($row->planned_total_cost), '0', 6) === 0 ? null
            : bcmul(bcdiv($adjustedVariance, $this->decimal($row->planned_total_cost), 8), '100', 6);
        $adjustedCostPerGood = bccomp($this->decimal($row->good_quantity), '0', 6) === 0 ? null
            : bcdiv($adjustedActualTotal, $this->decimal($row->good_quantity), 6);
        $payload = ['id' => (string) $row->id, 'cost_number' => (string) $row->cost_number,
            'production_order' => ['id' => (string) $row->production_order_id, 'number' => (string) $row->order_number,
                'batch_number' => (string) $row->batch_number, 'output_sku_id' => (string) $row->output_sku_id],
            'snapshot_version' => (int) $row->snapshot_version, 'currency' => (string) $row->currency,
            'labour_rate_per_minute' => $this->decimal($row->labour_rate_per_minute),
            'overhead_rate_per_minute' => $this->decimal($row->overhead_rate_per_minute),
            'planned_material_cost' => $this->decimal($row->planned_material_cost), 'actual_material_cost' => $this->decimal($row->actual_material_cost),
            'planned_conversion_cost' => $this->decimal($row->planned_conversion_cost), 'actual_conversion_cost' => $this->decimal($row->actual_conversion_cost),
            'planned_total_cost' => $this->decimal($row->planned_total_cost), 'actual_total_cost' => $this->decimal($row->actual_total_cost),
            'total_variance' => $this->decimal($row->total_variance), 'variance_percent' => $this->decimal($row->variance_percent),
            'approved_cost_adjustment_value' => $approvedCostAdjustment,
            'adjusted_actual_total_cost' => $adjustedActualTotal,
            'adjusted_total_variance' => $adjustedVariance,
            'adjusted_variance_percent' => $adjustedVariancePercent,
            'adjusted_cost_per_good_unit' => $adjustedCostPerGood,
            'good_quantity' => $this->decimal($row->good_quantity), 'yield_percent' => $this->decimal($row->yield_percent),
            'cost_per_good_unit' => $this->decimal($row->cost_per_good_unit), 'status' => (string) $row->status,
            'expected_good_quantity' => $this->decimal($row->expected_good_quantity),
            'output_variance' => $this->decimal($row->output_variance),
            'output_variance_percent' => $row->output_variance_percent === null ? null : $this->decimal($row->output_variance_percent),
            'normal_loss_quantity' => $this->decimal($row->normal_loss_quantity),
            'abnormal_loss_quantity' => $this->decimal($row->abnormal_loss_quantity),
            'rework_quantity' => $this->decimal($row->rework_quantity), 'rework_value' => $this->decimal($row->rework_value),
            'overweight_giveaway_quantity' => $this->decimal($row->overweight_giveaway_quantity),
            'overweight_giveaway_cost' => $this->decimal($row->overweight_giveaway_cost),
            'packaging_variance_quantity' => $this->decimal($row->packaging_variance_quantity),
            'packaging_variance_cost' => $this->decimal($row->packaging_variance_cost),
            'net_material_cost_per_good_unit' => $row->net_material_cost_per_good_unit === null ? null : $this->decimal($row->net_material_cost_per_good_unit),
            'all_source_costs_included' => (bool) $row->all_source_costs_included,
            'full_manufacturing_cost_per_good_unit' => $row->full_manufacturing_cost_per_good_unit === null
                ? null : $this->decimal($row->full_manufacturing_cost_per_good_unit),
            'dimensions' => ['recipe_version' => $row->recipe_revision_snapshot === null ? null : (int) $row->recipe_revision_snapshot,
                'output_sku' => ['id' => (string) $row->output_sku_id, 'code' => (string) $row->output_sku_code,
                    'name' => (string) $row->output_sku_name], 'batch_number' => (string) $row->batch_number,
                'shift_code' => $row->shift_code, 'plant_id' => (string) $row->plant_id,
                'operator' => ['id' => (string) $row->calculated_by, 'name' => (string) $row->calculator_name]],
            'calculated_at' => $row->calculated_at];
        if ($detail) {
            $payload['materials'] = DB::table('batch_cost_material_lines as line')->join('items as item', 'item.id', '=', 'line.component_sku_id')
                ->where('line.batch_cost_id', $row->id)->get(['line.*', 'item.code as item_code', 'item.name as item_name'])
                ->map(fn (object $line) => ['id' => (string) $line->id, 'item_code' => (string) $line->item_code,
                    'item_name' => (string) $line->item_name, 'planned_quantity' => $this->decimal($line->planned_quantity),
                    'actual_quantity' => $this->decimal($line->actual_quantity), 'uom_code' => (string) $line->uom_code,
                    'unit_cost' => $this->decimal($line->unit_cost), 'planned_cost' => $this->decimal($line->planned_cost),
                    'actual_cost' => $this->decimal($line->actual_cost), 'usage_variance' => $this->decimal($line->usage_variance),
                    'is_packaging' => (bool) $line->is_packaging,
                    'normalised_standard_quantity' => $this->decimal($line->normalised_standard_quantity ?? $line->planned_quantity),
                    'original_plan_quantity_variance' => $this->decimal($line->original_plan_quantity_variance),
                    'efficiency_quantity_variance' => $this->decimal($line->efficiency_quantity_variance),
                    'original_plan_cost_variance' => $this->decimal($line->original_plan_cost_variance),
                    'efficiency_cost_variance' => $this->decimal($line->efficiency_cost_variance)])->all();
            $payload['stages'] = DB::table('batch_cost_stage_lines')->where('batch_cost_id', $row->id)->get()
                ->map(fn (object $line) => ['id' => (string) $line->id, 'work_center_code' => (string) $line->work_center_code,
                    'planned_minutes' => $this->decimal($line->planned_minutes), 'actual_minutes' => $this->decimal($line->actual_minutes),
                    'planned_cost' => $this->decimal($line->planned_cost), 'actual_cost' => $this->decimal($line->actual_cost),
                    'time_variance' => $this->decimal($line->time_variance)])->all();
        }

        return $payload;
    }

    private function batchAdjustments(string $orderId, array $permissions): array
    {
        return DB::table('production_batch_adjustments as adjustment')
            ->join('users as requester', 'requester.id', '=', 'adjustment.requested_by')
            ->leftJoin('users as reviewer', 'reviewer.id', '=', 'adjustment.reviewed_by')
            ->where('adjustment.production_order_id', $orderId)->orderByDesc('adjustment.requested_at')
            ->get(['adjustment.*', 'requester.name as requester_name', 'reviewer.name as reviewer_name'])
            ->map(function (object $row) use ($permissions): array {
                $payload = is_string($row->payload)
                    ? json_decode($row->payload, true, 512, JSON_THROW_ON_ERROR) : (array) $row->payload;
                $format = function (array|object $effect): array {
                    $values = (array) $effect;

                    return ['id' => isset($values['id']) ? (string) $values['id'] : null,
                        'metric_code' => (string) $values['metric_code'],
                        'subject_type' => (string) $values['subject_type'],
                        'subject_id' => $values['subject_id'] ?? null,
                        'signed_quantity' => $this->decimal($values['signed_quantity'] ?? 0),
                        'uom_code' => $values['uom_code'] ?? null,
                        'signed_value' => $this->decimal($values['signed_value'] ?? 0),
                        'currency' => $values['currency'] ?? null];
                };
                $proposed = collect($payload['effects'] ?? [])->map($format)->all();
                $effective = DB::table('production_batch_adjustment_effects')
                    ->where('production_batch_adjustment_id', $row->id)->orderBy('created_at')->get()
                    ->map($format)->all();

                return ['id' => (string) $row->id, 'adjustment_number' => (string) $row->adjustment_number,
                    'adjustment_type' => (string) $row->adjustment_type, 'reason' => (string) $row->reason,
                    'status' => (string) $row->status, 'record_version' => (int) $row->record_version,
                    'requested_by' => ['id' => (string) $row->requested_by, 'name' => (string) $row->requester_name],
                    'requested_at' => $row->requested_at,
                    'reviewed_by' => $row->reviewed_by ? ['id' => (string) $row->reviewed_by,
                        'name' => (string) $row->reviewer_name] : null,
                    'reviewed_at' => $row->reviewed_at, 'review_notes' => $row->review_notes,
                    'proposed_effects' => $proposed, 'effective_effects' => $effective,
                    'allowed_actions' => $row->status === 'PENDING'
                        && $this->can($permissions, 'ACTION:QC-SAFE:RELEASE') ? ['REVIEW'] : []];
            })->all();
    }

    private function productionMetrics(string $orderId): array
    {
        $outputs = DB::table('production_output_events')->where('production_order_id', $orderId)->get();
        $countOutputs = $outputs->where('quantity_dimension', 'COUNT');
        $order = DB::table('production_orders')->where('id', $orderId)->first([
            'planned_quantity', 'expected_good_units', 'quality_status', 'calculation_model_snapshot',
            'planned_basis_quantity', 'scaling_factor', 'theoretical_input_mass', 'theoretical_complete_units',
            'theoretical_residual_quantity', 'expected_formed_units', 'expected_output_exception',
        ]);
        $planned = $this->decimal($order?->planned_quantity);
        $expected = $this->decimal($order?->expected_good_units ?? $planned);
        $directGood = $this->decimal($countOutputs->where('event_type', 'GOOD')->sum('quantity'));
        $loss = $this->decimal($countOutputs->where('event_type', 'LOSS')->sum('quantity'));
        $rework = $this->decimal($countOutputs->where('event_type', 'REWORK')->sum('quantity'));
        $recovered = $this->decimal($countOutputs->where('rework_status', 'RECOVERED')->sum('quantity'));
        $scrapped = $this->decimal($countOutputs->where('rework_status', 'SCRAPPED')->sum('quantity'));
        $adjustments = DB::table('production_batch_adjustment_effects')->where('production_order_id', $orderId)
            ->whereIn('metric_code', ['GOOD_OUTPUT', 'LOSS_OUTPUT', 'REWORK_OUTPUT'])
            ->selectRaw('metric_code, SUM(signed_quantity) as signed_quantity')->groupBy('metric_code')
            ->pluck('signed_quantity', 'metric_code');
        $goodAdjustment = $this->decimal($adjustments->get('GOOD_OUTPUT', 0));
        $lossAdjustment = $this->decimal($adjustments->get('LOSS_OUTPUT', 0));
        $reworkAdjustment = $this->decimal($adjustments->get('REWORK_OUTPUT', 0));

        $recordedFinalGood = bcadd($directGood, $recovered, 6);
        $recordedFinalLoss = bcadd($loss, $scrapped, 6);
        $finalGood = bcadd($recordedFinalGood, $goodAdjustment, 6);
        $finalLoss = bcadd($recordedFinalLoss, $lossAdjustment, 6);
        $adjustedRework = bcadd($rework, $reworkAdjustment, 6);
        $recordedOpenRework = $this->decimal($countOutputs->where('event_type', 'REWORK')->where('rework_status', 'OPEN')->sum('quantity'));
        $openRework = bcadd($recordedOpenRework, $reworkAdjustment, 6);
        $reconciled = bcadd(bcadd($finalGood, $finalLoss, 6), $openRework, 6);
        $variance = bcsub($finalGood, $expected, 6);

        return ['material_count' => DB::table('production_order_materials')->where('production_order_id', $orderId)->count(),
            'issued_material_count' => DB::table('production_order_materials')->where('production_order_id', $orderId)->whereColumn('issued_quantity', '>=', 'required_quantity')->count(),
            'stage_count' => DB::table('production_order_stages')->where('production_order_id', $orderId)->count(),
            'completed_stage_count' => DB::table('production_order_stages')->where('production_order_id', $orderId)->where('status', 'COMPLETED')->count(),
            'accounted_quantity' => $reconciled,
            'first_pass_good_quantity' => $directGood,
            'recovered_quantity' => $recovered,
            'recorded_final_good_quantity' => $recordedFinalGood,
            'final_good_quantity' => $finalGood,
            'qc_approved_good_quantity' => $order?->quality_status === 'RELEASED' ? $finalGood : '0.000000',
            'good_quantity' => $finalGood,
            'scrapped_rework_quantity' => $scrapped,
            'loss_quantity' => $finalLoss,
            'recorded_loss_quantity' => $recordedFinalLoss,
            'rework_quantity' => $adjustedRework,
            'recorded_rework_quantity' => $rework,
            'approved_output_adjustments' => ['good' => $goodAdjustment, 'loss' => $lossAdjustment,
                'rework' => $reworkAdjustment],
            'calculation_model' => (string) ($order?->calculation_model_snapshot ?? 'LEGACY_YIELD'),
            'planned_basis_quantity' => $order?->planned_basis_quantity === null ? null : $this->decimal($order->planned_basis_quantity),
            'scaling_factor' => $order?->scaling_factor === null ? null : $this->decimal($order->scaling_factor, 9),
            'theoretical_input_mass' => $order?->theoretical_input_mass === null ? null : $this->decimal($order->theoretical_input_mass),
            'theoretical_complete_units' => $order?->theoretical_complete_units === null ? null : $this->decimal($order->theoretical_complete_units),
            'theoretical_residual_quantity' => $order?->theoretical_residual_quantity === null ? null : $this->decimal($order->theoretical_residual_quantity),
            'expected_formed_units' => $order?->expected_formed_units === null ? null : $this->decimal($order->expected_formed_units),
            'expected_good_quantity' => $expected,
            'output_variance' => $variance,
            'output_variance_percent' => bccomp($expected, '0', 6) === 0 ? null : bcmul(bcdiv($variance, $expected, 8), '100', 6),
            'output_variance_exception' => bccomp($expected, '0', 6) === 0 ? 'ZERO_EXPECTED_OUTPUT' : $order?->expected_output_exception,
            'normal_process_loss_quantity' => $this->decimal($countOutputs->where('loss_category', 'NORMAL_PROCESS_LOSS')->sum('quantity')),
            'handling_waste_quantity' => $this->decimal($countOutputs->where('loss_category', 'HANDLING_WASTE')->sum('quantity')),
            'rejected_output_quantity' => $this->decimal($countOutputs->where('loss_category', 'REJECTED_OUTPUT')->sum('quantity')),
            'overweight_giveaway_quantity' => $this->decimal($outputs->where('quantity_dimension', 'MASS')->where('loss_category', 'OVERWEIGHT_GIVEAWAY')->sum('quantity')),
            'unexplained_variance_quantity' => $this->decimal($countOutputs->where('loss_category', 'UNEXPLAINED_VARIANCE')->sum('quantity')),
            'loss_classification_breakdown' => $outputs->whereNotNull('loss_category')
                ->groupBy(fn (object $event): string => $event->loss_category.'|'.$event->quantity_dimension.'|'.$event->uom_code)
                ->map(function (Collection $events, string $key): array {
                    [$category, $dimension, $uom] = explode('|', $key, 3);
                    return ['loss_category' => $category, 'quantity_dimension' => $dimension,
                        'uom_code' => $uom, 'quantity' => $this->decimal($events->sum('quantity'))];
                })->values()->all(),
            'first_pass_yield_percent' => bccomp($planned, '0', 6) > 0 ? bcmul(bcdiv($directGood, $planned, 8), '100', 4) : null,
            'final_yield_percent' => bccomp($planned, '0', 6) > 0 ? bcmul(bcdiv($finalGood, $planned, 8), '100', 4) : null,
            'yield_reconciliation' => ['planned_quantity' => $planned, 'final_good_quantity' => $finalGood,
                'loss_quantity' => $finalLoss, 'open_rework_quantity' => $this->decimal($openRework),
                'reconciled_quantity' => $reconciled, 'is_reconciled' => bccomp($planned, $reconciled, 6) === 0],
            'open_rework_count' => $countOutputs->where('event_type', 'REWORK')->where('rework_status', 'OPEN')->count(),
            'packed_quantity' => $this->decimal(DB::table('packing_runs')->where('production_order_id', $orderId)->where('status', 'COMPLETED')->sum('packed_quantity'))];
    }

    private function outputEvents(string $orderId, array $permissions): array
    {
        return DB::table('production_output_events')->where('production_order_id', $orderId)->orderBy('sequence_no')->get()
            ->map(fn (object $row) => ['id' => (string) $row->id, 'sequence_no' => (int) $row->sequence_no,
                'event_type' => (string) $row->event_type, 'quantity' => $this->decimal($row->quantity),
                'uom_code' => (string) $row->uom_code, 'reason_code' => $row->reason_code, 'notes' => $row->notes,
                'loss_category' => $row->loss_category, 'disposition_code' => $row->disposition_code,
                'value_source' => (string) $row->value_source, 'quantity_dimension' => (string) $row->quantity_dimension,
                'weight_quantity' => $row->weight_quantity === null ? null : $this->decimal($row->weight_quantity),
                'weight_uom_code' => $row->weight_uom_code,
                'rework_status' => (string) $row->rework_status, 'recorded_at' => $row->recorded_at,
                'resolved_at' => $row->resolved_at, 'resolution_notes' => $row->resolution_notes,
                'allowed_actions' => $row->rework_status === 'OPEN' && $this->can($permissions, 'ACTION:PRO-LOSS:RESOLVE') ? ['RESOLVE'] : []])->all();
    }

    private function orderSummary(array $scope): array
    {
        $base = DB::table('production_orders')->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id']);

        return ['total' => (clone $base)->count(), 'draft' => (clone $base)->where('status', 'DRAFT')->count(),
            'released' => (clone $base)->where('status', 'RELEASED')->count(), 'in_process' => (clone $base)->where('status', 'IN_PROCESS')->count(),
            'completed' => (clone $base)->where('status', 'COMPLETED')->count(), 'quality_held' => (clone $base)->where('quality_status', 'HELD')->count()];
    }

    private function outputSummary(array $scope): array
    {
        $base = DB::table('production_output_events')->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id']);
        $count = (clone $base)->where('quantity_dimension', 'COUNT');
        $effects = DB::table('production_batch_adjustment_effects')->where('company_id', $scope['company_id'])
            ->where('plant_id', $scope['plant_id']);
        $good = bcadd(bcadd($this->decimal((clone $count)->where('event_type', 'GOOD')->sum('quantity')),
            $this->decimal((clone $count)->where('rework_status', 'RECOVERED')->sum('quantity')), 6),
            $this->decimal((clone $effects)->where('metric_code', 'GOOD_OUTPUT')->sum('signed_quantity')), 6);
        $loss = bcadd(bcadd($this->decimal((clone $count)->where('event_type', 'LOSS')->sum('quantity')),
            $this->decimal((clone $count)->where('rework_status', 'SCRAPPED')->sum('quantity')), 6),
            $this->decimal((clone $effects)->where('metric_code', 'LOSS_OUTPUT')->sum('signed_quantity')), 6);
        $rework = bcadd($this->decimal((clone $count)->where('event_type', 'REWORK')->sum('quantity')),
            $this->decimal((clone $effects)->where('metric_code', 'REWORK_OUTPUT')->sum('signed_quantity')), 6);

        return ['event_count' => (clone $base)->count(), 'good_quantity' => $good,
            'loss_quantity' => $loss, 'rework_quantity' => $rework,
            'open_rework_count' => (clone $base)->where('rework_status', 'OPEN')->count()];
    }

    private function releasableScheduleLines(array $scope): array
    {
        return DB::table('production_schedule_lines as line')->join('production_schedules as schedule', 'schedule.id', '=', 'line.production_schedule_id')
            ->join('items as item', 'item.id', '=', 'line.output_sku_id')
            ->where('line.company_id', $scope['company_id'])->where('line.plant_id', $scope['plant_id'])
            ->where('schedule.status', 'RELEASED')->where('line.is_active', true)
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('production_orders as orders')
                ->whereColumn('orders.production_schedule_line_id', 'line.id')->where('orders.status', '<>', 'CANCELLED'))
            ->orderBy('line.planned_start_date')->get(['line.id', 'schedule.schedule_number', 'line.line_number',
                'line.planned_quantity', 'line.uom_code', 'line.planned_start_date', 'line.planned_end_date',
                'item.id as output_sku_id', 'item.code as item_code', 'item.name as item_name'])
            ->map(fn (object $row) => ['id' => (string) $row->id, 'schedule_number' => (string) $row->schedule_number,
                'line_number' => (int) $row->line_number, 'planned_quantity' => $this->decimal($row->planned_quantity),
                'uom_code' => (string) $row->uom_code, 'planned_start_date' => (string) $row->planned_start_date,
                'planned_end_date' => (string) $row->planned_end_date, 'output_sku' => ['id' => (string) $row->output_sku_id,
                    'code' => (string) $row->item_code, 'name' => (string) $row->item_name]])->all();
    }

    private function qualityOrders(array $scope): array
    {
        return DB::table('production_orders as orders')->join('items as item', 'item.id', '=', 'orders.output_sku_id')
            ->where('orders.company_id', $scope['company_id'])->where('orders.plant_id', $scope['plant_id'])
            ->where('orders.status', 'COMPLETED')->where('orders.quality_status', '<>', 'RECALLED')
            ->orderByDesc('orders.completed_at')->get(['orders.id', 'orders.order_number', 'orders.batch_number',
                'orders.output_sku_id', 'orders.record_version', 'orders.quality_status', 'item.code as item_code', 'item.name as item_name'])
            ->map(fn (object $row) => ['id' => (string) $row->id, 'number' => (string) $row->order_number,
                'batch_number' => (string) $row->batch_number, 'record_version' => (int) $row->record_version,
                'quality_status' => (string) $row->quality_status, 'output_sku' => ['id' => (string) $row->output_sku_id,
                    'code' => (string) $row->item_code, 'name' => (string) $row->item_name]])->all();
    }

    private function holdableOrders(array $scope): array
    {
        return DB::table('production_orders')->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])
            ->whereIn('status', ['RELEASED', 'IN_PROCESS', 'COMPLETED'])->where('quality_status', '<>', 'RECALLED')
            ->orderByDesc('created_at')->get(['id', 'order_number', 'batch_number', 'record_version', 'status', 'quality_status'])
            ->map(fn (object $row) => ['id' => (string) $row->id, 'number' => (string) $row->order_number,
                'batch_number' => (string) $row->batch_number, 'record_version' => (int) $row->record_version,
                'status' => (string) $row->status, 'quality_status' => (string) $row->quality_status])->all();
    }

    private function specifications(array $scope): array
    {
        return DB::table('quality_specifications')->where('company_id', $scope['company_id'])->where('status', 'ACTIVE')
            ->orderBy('code')->get(['id', 'code', 'name', 'target_type', 'catalog_item_id', 'sku_id', 'effective_from', 'effective_to'])
            ->map(fn (object $row) => (array) $row)->all();
    }

    private function outputSkus(array $scope): array
    {
        return DB::table('items')->where('company_id', $scope['company_id'])->where('item_type', 'FINISHED_GOOD')->where('status', 'ACTIVE')
            ->orderBy('code')->get(['id', 'code', 'name', 'barcode'])->map(fn (object $row) => (array) $row)->all();
    }

    private function packableOrders(array $scope): array
    {
        return collect($this->qualityOrders($scope))->where('quality_status', 'RELEASED')->map(function (array $row): array {
            $metrics = $this->productionMetrics($row['id']);
            return $row + ['good_quantity' => $metrics['good_quantity'], 'packed_quantity' => $metrics['packed_quantity']];
        })->values()->all();
    }

    private function approvedArtworks(array $scope): array
    {
        return DB::table('packaging_artworks')->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])
            ->where('status', 'APPROVED')->orderBy('artwork_code')->get(['id', 'output_sku_id', 'artwork_code', 'revision',
                'label_name', 'effective_from', 'effective_to'])->map(fn (object $row) => (array) $row)->all();
    }

    private function finishedLocations(array $scope): array
    {
        return DB::table('locations')->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])
            ->where('location_type', 'FINISHED_GOODS')->where('status', 'ACTIVE')->orderBy('code')
            ->get(['id', 'code', 'name'])->map(fn (object $row) => (array) $row)->all();
    }

    private function traceableLots(array $scope): array
    {
        return DB::table('lots as lot')->join('items as item', 'item.id', '=', 'lot.item_id')
            ->where('lot.company_id', $scope['company_id'])
            ->where(function ($query) use ($scope): void {
                $query->whereExists(fn ($sub) => $sub->selectRaw('1')->from('stock_positions as position')
                    ->whereColumn('position.lot_id', 'lot.id')->where('position.plant_id', $scope['plant_id']))
                    ->orWhereExists(fn ($sub) => $sub->selectRaw('1')->from('lot_genealogy_edges as edge')
                        ->where('edge.plant_id', $scope['plant_id'])->where(function ($nested): void {
                            $nested->whereColumn('edge.input_lot_id', 'lot.id')->orWhereColumn('edge.output_lot_id', 'lot.id');
                        }));
            })->orderByDesc('lot.created_at')->limit(500)->get(['lot.id', 'lot.internal_lot_code', 'lot.status',
                'item.code as item_code', 'item.name as item_name'])->map(fn (object $row) => ['id' => (string) $row->id,
                    'code' => (string) $row->internal_lot_code, 'status' => (string) $row->status,
                    'item_code' => (string) $row->item_code, 'item_name' => (string) $row->item_name])->all();
    }

    private function costableOrders(array $scope): array
    {
        return DB::table('production_orders')->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])
            ->where('status', 'COMPLETED')->orderByDesc('completed_at')->get(['id', 'order_number', 'batch_number'])
            ->map(function (object $row): array {
                $materials = DB::table('production_order_materials as material')->join('items as item', 'item.id', '=', 'material.component_sku_id')
                    ->where('material.production_order_id', $row->id)->orderBy('material.line_number')
                    ->get(['material.id', 'material.required_quantity', 'material.issued_quantity', 'material.net_consumed_quantity',
                        'material.original_plan_quantity', 'material.normalised_standard_quantity', 'material.is_packaging', 'material.uom_code',
                        'item.code as item_code', 'item.name as item_name'])->map(fn (object $material) => ['id' => (string) $material->id,
                            'item_code' => (string) $material->item_code, 'item_name' => (string) $material->item_name,
                            'required_quantity' => $this->decimal($material->original_plan_quantity ?? $material->required_quantity),
                            'normalised_standard_quantity' => $this->decimal($material->normalised_standard_quantity ?? $material->required_quantity),
                            'issued_quantity' => $this->decimal($material->issued_quantity),
                            'net_consumed_quantity' => $this->decimal($material->net_consumed_quantity ?? $material->issued_quantity),
                            'is_packaging' => (bool) $material->is_packaging, 'uom_code' => (string) $material->uom_code])->all();
                return ['id' => (string) $row->id, 'number' => (string) $row->order_number,
                    'batch_number' => (string) $row->batch_number, 'materials' => $materials];
            })->all();
    }

    private function genealogy(string $root, array $scope): array
    {
        $directions = [$root => 0];
        $frontier = [$root];
        $edgeRows = collect();
        for ($depth = 0; $frontier !== [] && count($directions) < 1000; $depth++) {
            $found = DB::table('lot_genealogy_edges')->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])
                ->where(function ($query) use ($frontier): void {
                    $query->whereIn('input_lot_id', $frontier)->orWhereIn('output_lot_id', $frontier);
                })->get();
            $next = [];
            foreach ($found as $edge) {
                $edgeRows->put($edge->id, $edge);
                foreach ([$edge->input_lot_id, $edge->output_lot_id] as $lotId) {
                    if (! array_key_exists($lotId, $directions)) {
                        $directions[$lotId] = $depth + 1;
                        $next[] = $lotId;
                    }
                }
            }
            $frontier = array_values(array_unique($next));
        }
        $lots = DB::table('lots as lot')->join('items as item', 'item.id', '=', 'lot.item_id')
            ->whereIn('lot.id', array_keys($directions))->get(['lot.*', 'item.code as item_code', 'item.name as item_name']);
        $nodes = [];
        foreach ($lots as $lot) {
            $nodes[$lot->id] = $this->lotIdentity($lot) + ['distance' => $directions[$lot->id]];
        }
        $edges = $edgeRows->values()->map(fn (object $edge) => ['id' => (string) $edge->id,
            'input_lot_id' => (string) $edge->input_lot_id, 'output_lot_id' => (string) $edge->output_lot_id,
            'production_order_id' => (string) $edge->production_order_id, 'packing_run_id' => (string) $edge->packing_run_id,
            'input_quantity' => $this->decimal($edge->input_quantity), 'input_uom_code' => (string) $edge->input_uom_code,
            'output_quantity' => $this->decimal($edge->output_quantity), 'output_uom_code' => (string) $edge->output_uom_code])->all();

        return [$nodes, $edges];
    }

    private function lotIdentity(object $lot): array
    {
        return ['id' => (string) $lot->id, 'code' => (string) $lot->internal_lot_code,
            'item' => ['id' => (string) $lot->item_id, 'code' => (string) $lot->item_code, 'name' => (string) $lot->item_name],
            'origin_type' => (string) $lot->origin_type, 'status' => (string) $lot->status,
            'manufacture_date' => $lot->manufacture_date, 'expiry_date' => $lot->expiry_date];
    }

    private function filters(Builder $query, array $filters, string $primary, array $secondary, string $status): void
    {
        if ($q = trim((string) ($filters['q'] ?? ''))) {
            $query->where(function ($nested) use ($q, $primary, $secondary): void {
                $nested->whereRaw('LOWER('.$primary.') LIKE ?', ['%'.mb_strtolower($q).'%']);
                foreach ($secondary as $column) {
                    $nested->orWhereRaw('LOWER('.$column.') LIKE ?', ['%'.mb_strtolower($q).'%']);
                }
            });
        }
        if ($value = $filters['status'] ?? null) {
            $query->where($status, $value);
        }
    }

    private function sort(Builder $query, array $filters, string $alias, string $number, string $date = 'created_at'): void
    {
        match ($filters['sort'] ?? 'NEWEST') {
            'OLDEST' => $query->orderBy("{$alias}.{$date}")->orderBy("{$alias}.{$number}"),
            'NUMBER' => $query->orderBy("{$alias}.{$number}"),
            'STATUS' => $query->orderBy("{$alias}.status")->orderBy("{$alias}.{$number}"),
            default => $query->orderByDesc("{$alias}.{$date}")->orderBy("{$alias}.{$number}"),
        };
    }

    private function collectionPage(Collection $records, array $filters): LengthAwarePaginator
    {
        $perPage = (int) ($filters['per_page'] ?? 25);
        $page = max(1, (int) ($filters['page'] ?? 1));

        return new LengthAwarePaginator($records->slice(($page - 1) * $perPage, $perPage)->values()->all(), $records->count(), $perPage, $page);
    }

    private function meta(LengthAwarePaginator $page): array
    {
        return ['current_page' => $page->currentPage(), 'last_page' => max(1, $page->lastPage()),
            'per_page' => $page->perPage(), 'total' => $page->total()];
    }

    private function can(array $permissions, string $permission): bool
    {
        return in_array($permission, $permissions, true);
    }

    private function decimal(mixed $value, int $scale = 6): string
    {
        return bcadd((string) ($value ?? 0), '0', $scale);
    }
}

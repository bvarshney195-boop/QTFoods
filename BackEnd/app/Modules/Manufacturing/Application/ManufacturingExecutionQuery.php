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
            'lookups' => ['statuses' => ['PENDING', 'IN_PROGRESS', 'COMPLETED'], 'sorts' => self::SORTS],
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
                'rework_dispositions' => ManufacturingExecutionService::REWORK_DISPOSITIONS],
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
                'locations' => $this->finishedLocations($scope)],
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
            ->where('cost.company_id', $scope['company_id'])->where('cost.plant_id', $scope['plant_id'])
            ->select(['cost.*', 'orders.order_number', 'orders.batch_number', 'orders.output_sku_id']);
        $query = clone $base;
        $this->filters($query, $filters, 'cost.cost_number', ['orders.order_number', 'orders.batch_number'], 'cost.status');
        $this->sort($query, $filters, 'cost', 'cost_number', 'calculated_at');
        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return ['data' => collect($page->items())->map(fn (object $row) => $this->costPayload($row, false))->all(),
            'meta' => $this->meta($page),
            'summary' => ['total' => (clone $base)->count(), 'actual_total_cost' => $this->decimal((clone $base)->sum('cost.actual_total_cost')),
                'total_variance' => $this->decimal((clone $base)->sum('cost.total_variance'))],
            'lookups' => ['statuses' => ['FINALIZED'], 'sorts' => self::SORTS, 'production_orders' => $this->costableOrders($scope)],
            'allowed_actions' => $this->can($permissions, 'ACTION:COST-BATCH:CALCULATE') ? ['CALCULATE'] : []];
    }

    public function costDetail(string $id, array $scope): array
    {
        $row = DB::table('batch_costs as cost')->join('production_orders as orders', 'orders.id', '=', 'cost.production_order_id')
            ->where('cost.id', $id)->where('cost.company_id', $scope['company_id'])->where('cost.plant_id', $scope['plant_id'])
            ->first(['cost.*', 'orders.order_number', 'orders.batch_number', 'orders.output_sku_id']);
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
        if ($row->status === 'COMPLETED' && $row->quality_status !== 'RELEASED'
            && $this->can($permissions, 'ACTION:QC-SAFE:RELEASE')) {
            $actions[] = 'QUALITY_RELEASE';
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
        $payload['materials'] = DB::table('production_order_materials as material')
            ->join('items as item', 'item.id', '=', 'material.component_sku_id')
            ->where('material.production_order_id', $row->id)->orderBy('material.line_number')
            ->get(['material.*', 'item.code as item_code', 'item.name as item_name'])
            ->map(function (object $material): array {
                $issues = DB::table('production_material_issues as issue')->join('lots as lot', 'lot.id', '=', 'issue.input_lot_id')
                    ->where('issue.production_order_material_id', $material->id)->orderBy('issue.issued_at')
                    ->get(['issue.*', 'lot.internal_lot_code'])->map(fn (object $issue) => [
                        'id' => (string) $issue->id, 'lot_id' => (string) $issue->input_lot_id,
                        'lot_code' => (string) $issue->internal_lot_code, 'quantity' => $this->decimal($issue->quantity),
                        'uom_code' => (string) $issue->uom_code, 'movement_id' => (string) $issue->stock_movement_id,
                        'issued_at' => $issue->issued_at,
                    ])->all();

                return ['id' => (string) $material->id, 'line_number' => (int) $material->line_number,
                    'component_sku' => ['id' => (string) $material->component_sku_id, 'code' => (string) $material->item_code, 'name' => (string) $material->item_name],
                    'required_quantity' => $this->decimal($material->required_quantity), 'issued_quantity' => $this->decimal($material->issued_quantity),
                    'uom_code' => (string) $material->uom_code, 'issues' => $issues];
            })->all();
        $payload['stages'] = DB::table('production_order_stages')->where('production_order_id', $row->id)
            ->orderBy('sequence_no')->get()->map(fn (object $stage) => $this->stagePayload((object) ((array) $stage + [
                'order_number' => $row->order_number, 'batch_number' => $row->batch_number, 'order_status' => $row->status,
            ]), $permissions))->all();
        $payload['outputs'] = $this->outputEvents($row->id, $permissions);

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
        }

        return ['id' => (string) $row->id, 'production_order' => ['id' => (string) $row->production_order_id,
            'number' => (string) $row->order_number, 'batch_number' => (string) $row->batch_number, 'status' => (string) $row->order_status],
            'sequence_no' => (int) $row->sequence_no, 'operation_name' => (string) $row->operation_name,
            'work_center_code' => (string) $row->work_center_code, 'planned_minutes' => $this->decimal($row->planned_minutes),
            'actual_minutes' => $row->actual_minutes === null ? null : $this->decimal($row->actual_minutes),
            'status' => (string) $row->status, 'record_version' => (int) $row->record_version,
            'started_at' => $row->started_at, 'completed_at' => $row->completed_at, 'notes' => $row->notes,
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
            'good_quantity' => $this->decimal($row->good_quantity), 'yield_percent' => $this->decimal($row->yield_percent),
            'cost_per_good_unit' => $this->decimal($row->cost_per_good_unit), 'status' => (string) $row->status,
            'calculated_at' => $row->calculated_at];
        if ($detail) {
            $payload['materials'] = DB::table('batch_cost_material_lines as line')->join('items as item', 'item.id', '=', 'line.component_sku_id')
                ->where('line.batch_cost_id', $row->id)->get(['line.*', 'item.code as item_code', 'item.name as item_name'])
                ->map(fn (object $line) => ['id' => (string) $line->id, 'item_code' => (string) $line->item_code,
                    'item_name' => (string) $line->item_name, 'planned_quantity' => $this->decimal($line->planned_quantity),
                    'actual_quantity' => $this->decimal($line->actual_quantity), 'uom_code' => (string) $line->uom_code,
                    'unit_cost' => $this->decimal($line->unit_cost), 'planned_cost' => $this->decimal($line->planned_cost),
                    'actual_cost' => $this->decimal($line->actual_cost), 'usage_variance' => $this->decimal($line->usage_variance)])->all();
            $payload['stages'] = DB::table('batch_cost_stage_lines')->where('batch_cost_id', $row->id)->get()
                ->map(fn (object $line) => ['id' => (string) $line->id, 'work_center_code' => (string) $line->work_center_code,
                    'planned_minutes' => $this->decimal($line->planned_minutes), 'actual_minutes' => $this->decimal($line->actual_minutes),
                    'planned_cost' => $this->decimal($line->planned_cost), 'actual_cost' => $this->decimal($line->actual_cost),
                    'time_variance' => $this->decimal($line->time_variance)])->all();
        }

        return $payload;
    }

    private function productionMetrics(string $orderId): array
    {
        $outputs = DB::table('production_output_events')->where('production_order_id', $orderId)->get();
        $directGood = $this->decimal($outputs->where('event_type', 'GOOD')->sum('quantity'));
        $loss = $this->decimal($outputs->where('event_type', 'LOSS')->sum('quantity'));
        $rework = $this->decimal($outputs->where('event_type', 'REWORK')->sum('quantity'));
        $recovered = $this->decimal($outputs->where('rework_status', 'RECOVERED')->sum('quantity'));
        $scrapped = $this->decimal($outputs->where('rework_status', 'SCRAPPED')->sum('quantity'));

        return ['material_count' => DB::table('production_order_materials')->where('production_order_id', $orderId)->count(),
            'issued_material_count' => DB::table('production_order_materials')->where('production_order_id', $orderId)->whereColumn('issued_quantity', '>=', 'required_quantity')->count(),
            'stage_count' => DB::table('production_order_stages')->where('production_order_id', $orderId)->count(),
            'completed_stage_count' => DB::table('production_order_stages')->where('production_order_id', $orderId)->where('status', 'COMPLETED')->count(),
            'accounted_quantity' => bcadd(bcadd($directGood, $loss, 6), $rework, 6), 'good_quantity' => bcadd($directGood, $recovered, 6),
            'loss_quantity' => bcadd($loss, $scrapped, 6), 'rework_quantity' => $rework,
            'open_rework_count' => $outputs->where('event_type', 'REWORK')->where('rework_status', 'OPEN')->count(),
            'packed_quantity' => $this->decimal(DB::table('packing_runs')->where('production_order_id', $orderId)->where('status', 'COMPLETED')->sum('packed_quantity'))];
    }

    private function outputEvents(string $orderId, array $permissions): array
    {
        return DB::table('production_output_events')->where('production_order_id', $orderId)->orderBy('sequence_no')->get()
            ->map(fn (object $row) => ['id' => (string) $row->id, 'sequence_no' => (int) $row->sequence_no,
                'event_type' => (string) $row->event_type, 'quantity' => $this->decimal($row->quantity),
                'uom_code' => (string) $row->uom_code, 'reason_code' => $row->reason_code, 'notes' => $row->notes,
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

        return ['event_count' => (clone $base)->count(), 'good_quantity' => $this->decimal((clone $base)->where('event_type', 'GOOD')->sum('quantity')),
            'loss_quantity' => $this->decimal((clone $base)->where('event_type', 'LOSS')->sum('quantity')),
            'rework_quantity' => $this->decimal((clone $base)->where('event_type', 'REWORK')->sum('quantity')),
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
                    ->get(['material.id', 'material.required_quantity', 'material.issued_quantity', 'material.uom_code',
                        'item.code as item_code', 'item.name as item_name'])->map(fn (object $material) => ['id' => (string) $material->id,
                            'item_code' => (string) $material->item_code, 'item_name' => (string) $material->item_name,
                            'required_quantity' => $this->decimal($material->required_quantity),
                            'issued_quantity' => $this->decimal($material->issued_quantity), 'uom_code' => (string) $material->uom_code])->all();
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

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 6);
    }
}

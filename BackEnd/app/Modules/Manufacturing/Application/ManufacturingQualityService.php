<?php

namespace App\Modules\Manufacturing\Application;

use App\Modules\Inventory\Application\StockPostingService;
use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class ManufacturingQualityService
{
    public const SAMPLE_STATUSES = ['PENDING', 'PASSED', 'FAILED'];
    public const DEVIATION_DISPOSITIONS = ['ACCEPTED', 'REWORK', 'SCRAP'];
    public const HAZARD_TYPES = ['BIOLOGICAL', 'CHEMICAL', 'PHYSICAL', 'ALLERGEN', 'REGULATORY'];
    public const ARTWORK_STATUSES = ['DRAFT', 'APPROVED', 'RETIRED'];
    public const PACKING_STATUSES = ['DRAFT', 'COMPLETED', 'CANCELLED'];

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
        private readonly StockPostingService $stock,
        private readonly ManufacturingExecutionService $execution,
    ) {}

    public function createSample(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'quality.lab-sample.create';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }
            $this->assertUnique('lab_samples', 'sample_number', $data['sample_number'], $data);
            $order = $this->findOrder($data['production_order_id'], $data, true);
            if ($order->status !== 'COMPLETED') {
                throw ValidationException::withMessages(['production_order_id' => ['Lab release samples require a completed production order.']]);
            }
            $spec = DB::table('quality_specifications')->where('id', $data['specification_id'])
                ->where('company_id', $data['company_id'])->first();
            $sku = DB::table('items')->where('id', $order->output_sku_id)->where('company_id', $data['company_id'])->first();
            $sampleDate = CarbonImmutable::parse($data['sampled_at'])->toDateString();
            if (! $spec || ! $sku || $spec->status !== 'ACTIVE'
                || ($spec->target_type === 'SKU' && $spec->sku_id !== $order->output_sku_id)
                || ($spec->target_type === 'ITEM' && $spec->catalog_item_id !== $sku->catalog_item_id)
                || ($spec->effective_from && $sampleDate < $spec->effective_from)
                || ($spec->effective_to && $sampleDate > $spec->effective_to)) {
                throw ValidationException::withMessages(['specification_id' => ['Choose an active, effective specification that applies to the production output SKU.']]);
            }
            $parameters = DB::table('quality_spec_parameters')->where('specification_id', $spec->id)
                ->orderBy('sequence_no')->get();
            if ($parameters->isEmpty()) {
                throw ValidationException::withMessages(['specification_id' => ['The selected specification has no test parameters.']]);
            }

            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('lab_samples')->insert([
                'id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'sample_number' => $data['sample_number'], 'production_order_id' => $order->id,
                'specification_id' => $spec->id, 'specification_version_snapshot' => $spec->record_version,
                'status' => 'PENDING', 'record_version' => 1, 'notes' => $this->nullable($data['notes'] ?? null),
                'sampled_at' => CarbonImmutable::parse($data['sampled_at']), 'sampled_by' => $data['actor_id'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($parameters as $parameter) {
                DB::table('lab_results')->insert([
                    'id' => (string) Str::uuid(), 'lab_sample_id' => $id,
                    'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                    'specification_parameter_id' => $parameter->id, 'sequence_no' => $parameter->sequence_no,
                    'parameter_code' => $parameter->code, 'parameter_name' => $parameter->name,
                    'value_type' => $parameter->value_type, 'uom_code' => $parameter->uom_code,
                    'minimum_value' => $parameter->minimum_value, 'target_value' => $parameter->target_value,
                    'maximum_value' => $parameter->maximum_value, 'text_requirement' => $parameter->text_requirement,
                    'is_required' => $parameter->is_required, 'result' => 'PENDING',
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $result = ['entity_type' => 'lab_sample', 'id' => $id, 'status' => 'PENDING', 'record_version' => 1, 'result_count' => $parameters->count()];
            $this->record('CREATE_LAB_SAMPLE', 'quality.lab-sample.created', 'lab_sample', $id, $data, 1, [
                'production_order_id' => $order->id, 'specification_id' => $spec->id, 'result_count' => $parameters->count(),
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function completeSample(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'quality.lab-sample.complete.'.$id;
            if ($replay = $this->begin($namespace, $data + ['lab_sample_id' => $id])) {
                return $replay;
            }
            $sample = $this->findSample($id, $data, true);
            $this->assertVersion($sample, $data['expected_version'], 'lab sample');
            if ($sample->status !== 'PENDING') {
                throw ValidationException::withMessages(['status' => ['Only a pending lab sample can receive results.']]);
            }
            $stored = DB::table('lab_results')->where('lab_sample_id', $id)->orderBy('sequence_no')->lockForUpdate()->get();
            $submitted = collect($data['results'])->keyBy('result_id');
            if ($stored->count() !== $submitted->count() || $stored->contains(fn (object $row) => ! $submitted->has($row->id))) {
                throw ValidationException::withMessages(['results' => ['Submit one result for every snapshotted specification parameter.']]);
            }

            $now = CarbonImmutable::now();
            $failures = [];
            foreach ($stored as $row) {
                $input = (array) $submitted->get($row->id);
                [$numeric, $text, $boolean, $passed] = $this->evaluateResult($row, $input);
                DB::table('lab_results')->where('id', $row->id)->update([
                    'numeric_value' => $numeric, 'text_value' => $text, 'boolean_value' => $boolean,
                    'result' => $passed ? 'PASS' : 'FAIL', 'notes' => $this->nullable($input['notes'] ?? null),
                    'tested_at' => $now, 'tested_by' => $data['actor_id'], 'updated_at' => $now,
                ]);
                if (! $passed) {
                    $failures[] = $row;
                }
            }
            $status = $failures === [] ? 'PASSED' : 'FAILED';
            $version = (int) $sample->record_version + 1;
            DB::table('lab_samples')->where('id', $id)->update([
                'status' => $status, 'record_version' => $version,
                'completed_at' => $now, 'completed_by' => $data['actor_id'], 'updated_at' => $now,
            ]);
            foreach ($failures as $failure) {
                DB::table('quality_deviations')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                    'deviation_number' => $this->deviationNumber($sample->sample_number, (int) $failure->sequence_no),
                    'production_order_id' => $sample->production_order_id, 'lab_sample_id' => $id,
                    'lab_result_id' => $failure->id, 'category' => 'LAB',
                    'description' => "{$failure->parameter_code} failed its approved specification snapshot.",
                    'status' => 'OPEN', 'record_version' => 1, 'raised_at' => $now,
                    'raised_by' => $data['actor_id'], 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            if ($status === 'FAILED') {
                $this->setOrderQuality($sample->production_order_id, 'HELD', $now);
            } else {
                $this->refreshOrderQuality($sample->production_order_id, $now);
            }
            $result = ['entity_type' => 'lab_sample', 'id' => $id, 'production_order_id' => $sample->production_order_id,
                'status' => $status, 'record_version' => $version, 'failure_count' => count($failures)];
            $this->record('COMPLETE_LAB_SAMPLE', 'quality.lab-sample.completed', 'lab_sample', $id, $data, $version, [
                'status' => ['from' => 'PENDING', 'to' => $status], 'failure_count' => count($failures),
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function resolveDeviation(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'quality.deviation.resolve.'.$id;
            if ($replay = $this->begin($namespace, $data + ['quality_deviation_id' => $id])) {
                return $replay;
            }
            $deviation = DB::table('quality_deviations')->where('id', $id)
                ->where('company_id', $data['company_id'])->where('plant_id', $data['plant_id'])->lockForUpdate()->first();
            if (! $deviation) {
                throw new NotFoundHttpException('Quality deviation not found.');
            }
            $this->assertVersion($deviation, $data['expected_version'], 'quality deviation');
            if ($deviation->status !== 'OPEN') {
                throw ValidationException::withMessages(['status' => ['Only an open deviation can be resolved.']]);
            }
            $now = CarbonImmutable::now();
            $version = (int) $deviation->record_version + 1;
            DB::table('quality_deviations')->where('id', $id)->update([
                'status' => 'RESOLVED', 'disposition' => Str::upper($data['disposition']),
                'root_cause' => trim($data['root_cause']), 'corrective_action' => trim($data['corrective_action']),
                'record_version' => $version, 'resolved_at' => $now, 'resolved_by' => $data['actor_id'], 'updated_at' => $now,
            ]);
            $this->refreshOrderQuality($deviation->production_order_id, $now);
            $result = ['entity_type' => 'quality_deviation', 'id' => $id, 'production_order_id' => $deviation->production_order_id,
                'status' => 'RESOLVED', 'record_version' => $version, 'disposition' => Str::upper($data['disposition'])];
            $this->record('RESOLVE_QUALITY_DEVIATION', 'quality.deviation.resolved', 'quality_deviation', $id, $data, $version, [
                'status' => ['from' => 'OPEN', 'to' => 'RESOLVED'], 'disposition' => Str::upper($data['disposition']),
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function placeHold(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'quality.food-safety-hold.create';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }
            $this->assertUnique('food_safety_holds', 'hold_number', $data['hold_number'], $data);
            $order = $this->findOrder($data['production_order_id'], $data, true);
            if (in_array($order->status, ['DRAFT', 'CANCELLED'], true) || $order->quality_status === 'RECALLED') {
                throw ValidationException::withMessages(['production_order_id' => ['A food-safety hold requires an active released, in-process, or completed batch.']]);
            }
            $this->assertVersion($order, $data['expected_version'], 'production order');
            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('food_safety_holds')->insert([
                'id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'hold_number' => $data['hold_number'], 'production_order_id' => $order->id,
                'hazard_type' => Str::upper($data['hazard_type']), 'reason' => trim($data['reason']),
                'status' => 'ACTIVE', 'record_version' => 1, 'placed_at' => $now,
                'placed_by' => $data['actor_id'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $this->setOrderQuality($order->id, 'HELD', $now);
            $result = ['entity_type' => 'food_safety_hold', 'id' => $id, 'production_order_id' => $order->id,
                'status' => 'ACTIVE', 'record_version' => 1, 'order_record_version' => (int) $order->record_version + 1];
            $this->record('PLACE_FOOD_SAFETY_HOLD', 'quality.food-safety-hold.placed', 'food_safety_hold', $id, $data, 1, [
                'production_order_id' => $order->id, 'hazard_type' => Str::upper($data['hazard_type']),
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function releaseHold(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'quality.food-safety-hold.release.'.$id;
            if ($replay = $this->begin($namespace, $data + ['food_safety_hold_id' => $id])) {
                return $replay;
            }
            $hold = DB::table('food_safety_holds')->where('id', $id)
                ->where('company_id', $data['company_id'])->where('plant_id', $data['plant_id'])->lockForUpdate()->first();
            if (! $hold) {
                throw new NotFoundHttpException('Food-safety hold not found.');
            }
            $this->assertVersion($hold, $data['expected_version'], 'food-safety hold');
            if ($hold->status !== 'ACTIVE') {
                throw ValidationException::withMessages(['status' => ['Only an active food-safety hold can be released.']]);
            }
            $now = CarbonImmutable::now();
            $version = (int) $hold->record_version + 1;
            DB::table('food_safety_holds')->where('id', $id)->update([
                'status' => 'RELEASED', 'record_version' => $version,
                'released_at' => $now, 'released_by' => $data['actor_id'],
                'disposition' => Str::upper($data['disposition']), 'root_cause' => trim($data['root_cause']),
                'corrective_action' => trim($data['corrective_action']), 'updated_at' => $now,
            ]);
            $this->refreshOrderQuality($hold->production_order_id, $now);
            $result = ['entity_type' => 'food_safety_hold', 'id' => $id, 'production_order_id' => $hold->production_order_id,
                'status' => 'RELEASED', 'record_version' => $version];
            $this->record('RELEASE_FOOD_SAFETY_HOLD', 'quality.food-safety-hold.released', 'food_safety_hold', $id, $data, $version, [
                'status' => ['from' => 'ACTIVE', 'to' => 'RELEASED'], 'disposition' => Str::upper($data['disposition']),
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function releaseBatchQuality(string $orderId, array $data): array
    {
        return DB::transaction(function () use ($orderId, $data): array {
            $namespace = 'quality.production-order.release.'.$orderId;
            if ($replay = $this->begin($namespace, $data + ['production_order_id' => $orderId])) {
                return $replay;
            }
            $order = $this->findOrder($orderId, $data, true);
            $this->assertVersion($order, $data['expected_version'], 'production order');
            if ($order->status !== 'COMPLETED' || $order->quality_status === 'RECALLED') {
                throw ValidationException::withMessages(['status' => ['Only a completed, non-recalled batch can receive quality release.']]);
            }
            $latest = DB::table('lab_samples')->where('production_order_id', $orderId)->orderByDesc('sampled_at')->orderByDesc('created_at')->first();
            if (! $latest || $latest->status !== 'PASSED') {
                throw ValidationException::withMessages(['lab_sample' => ['The latest lab sample must pass before quality release.']]);
            }
            if (DB::table('quality_deviations')->where('production_order_id', $orderId)->where('status', 'OPEN')->exists()) {
                throw ValidationException::withMessages(['deviations' => ['Resolve every open quality deviation before release.']]);
            }
            if (DB::table('food_safety_holds')->where('production_order_id', $orderId)->where('status', 'ACTIVE')->exists()) {
                throw ValidationException::withMessages(['holds' => ['Release every active food-safety hold before batch release.']]);
            }
            $now = CarbonImmutable::now();
            $version = (int) $order->record_version + 1;
            DB::table('production_orders')->where('id', $orderId)->update([
                'quality_status' => 'RELEASED', 'quality_released_at' => $now,
                'quality_released_by' => $data['actor_id'], 'record_version' => $version, 'updated_at' => $now,
            ]);
            $result = ['entity_type' => 'production_order', 'id' => $orderId, 'status' => $order->status,
                'quality_status' => 'RELEASED', 'record_version' => $version];
            $this->record('RELEASE_PRODUCTION_QUALITY', 'quality.production-batch.released', 'production_order', $orderId, $data, $version, [
                'quality_status' => ['from' => $order->quality_status, 'to' => 'RELEASED'], 'lab_sample_id' => $latest->id,
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function createArtwork(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'packing.artwork.create';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }
            $this->assertArtworkSku($data['output_sku_id'], $data);
            if (DB::table('packaging_artworks')->where('company_id', $data['company_id'])->where('plant_id', $data['plant_id'])
                ->where('artwork_code', $data['artwork_code'])->where('revision', $data['revision'])->exists()) {
                throw ValidationException::withMessages(['artwork_code' => ['That artwork code and revision already exist in the selected plant.']]);
            }
            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('packaging_artworks')->insert([
                'id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'output_sku_id' => $data['output_sku_id'], 'artwork_code' => $data['artwork_code'],
                'revision' => $data['revision'], 'label_name' => trim($data['label_name']),
                'barcode' => trim($data['barcode']), 'coding_template' => trim($data['coding_template']),
                'effective_from' => $data['effective_from'], 'effective_to' => $data['effective_to'] ?? null,
                'status' => 'DRAFT', 'record_version' => 1, 'notes' => $this->nullable($data['notes'] ?? null),
                'created_by' => $data['actor_id'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $result = ['entity_type' => 'packaging_artwork', 'id' => $id, 'status' => 'DRAFT', 'record_version' => 1];
            $this->record('CREATE_PACKAGING_ARTWORK', 'packing.artwork.created', 'packaging_artwork', $id, $data, 1, [
                'output_sku_id' => $data['output_sku_id'], 'revision' => $data['revision'],
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function updateArtwork(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'packing.artwork.update.'.$id;
            if ($replay = $this->begin($namespace, $data + ['packaging_artwork_id' => $id])) {
                return $replay;
            }
            $artwork = $this->findArtwork($id, $data, true);
            $this->assertVersion($artwork, $data['expected_version'], 'packaging artwork');
            if ($artwork->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => ['Only draft artwork can be edited.']]);
            }
            $now = CarbonImmutable::now();
            $version = (int) $artwork->record_version + 1;
            DB::table('packaging_artworks')->where('id', $id)->update([
                'label_name' => trim($data['label_name']), 'barcode' => trim($data['barcode']),
                'coding_template' => trim($data['coding_template']), 'effective_from' => $data['effective_from'],
                'effective_to' => $data['effective_to'] ?? null, 'notes' => $this->nullable($data['notes'] ?? null),
                'record_version' => $version, 'updated_at' => $now,
            ]);
            $result = ['entity_type' => 'packaging_artwork', 'id' => $id, 'status' => 'DRAFT', 'record_version' => $version];
            $this->record('UPDATE_PACKAGING_ARTWORK', 'packing.artwork.updated', 'packaging_artwork', $id, $data, $version, [
                'record_version' => ['from' => (int) $artwork->record_version, 'to' => $version],
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function approveArtwork(string $id, array $data): array
    {
        return $this->artworkTransition($id, 'APPROVED', null, $data);
    }

    public function retireArtwork(string $id, string $reason, array $data): array
    {
        return $this->artworkTransition($id, 'RETIRED', $reason, $data);
    }

    public function createPackingRun(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'packing.run.create';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }
            $this->assertUnique('packing_runs', 'run_number', $data['run_number'], $data);
            if (DB::table('lots')->where('company_id', $data['company_id'])->where('internal_lot_code', $data['finished_lot_code'])->exists()
                || DB::table('packing_runs')->where('company_id', $data['company_id'])->where('finished_lot_code', $data['finished_lot_code'])->exists()) {
                throw ValidationException::withMessages(['finished_lot_code' => ['That internal finished-goods lot code already exists.']]);
            }
            $order = $this->findOrder($data['production_order_id'], $data, true);
            if ($order->status !== 'COMPLETED' || $order->quality_status !== 'RELEASED') {
                throw ValidationException::withMessages(['production_order_id' => ['Packing requires a completed production order with quality release.']]);
            }
            $artwork = $this->findArtwork($data['packaging_artwork_id'], $data, false);
            $manufactureDate = $data['manufacture_date'];
            if ($artwork->status !== 'APPROVED' || $artwork->output_sku_id !== $order->output_sku_id
                || $manufactureDate < $artwork->effective_from || ($artwork->effective_to && $manufactureDate > $artwork->effective_to)) {
                throw ValidationException::withMessages(['packaging_artwork_id' => ['Choose approved artwork for this SKU that is effective on the manufacture date.']]);
            }
            $location = DB::table('locations')->where('id', $data['target_location_id'])
                ->where('company_id', $data['company_id'])->where('plant_id', $data['plant_id'])->where('status', 'ACTIVE')->first();
            if (! $location || $location->location_type !== 'FINISHED_GOODS') {
                throw ValidationException::withMessages(['target_location_id' => ['Choose an active finished-goods location in the selected plant.']]);
            }
            $quantity = $this->positive($data['packed_quantity'], 'packed_quantity', 'Packed quantity');
            $good = $this->execution->outputMetrics($order->id)['good_quantity'];
            $allocated = $this->decimal(DB::table('packing_runs')->where('production_order_id', $order->id)
                ->whereIn('status', ['DRAFT', 'COMPLETED'])->sum('packed_quantity'));
            if (bccomp(bcadd($allocated, $quantity, 6), $good, 6) > 0) {
                throw ValidationException::withMessages(['packed_quantity' => ['Draft and completed packing runs cannot exceed the batch good output.']]);
            }
            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            $coding = $this->codingValue($artwork->coding_template, $data, $order);
            DB::table('packing_runs')->insert([
                'id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'run_number' => $data['run_number'], 'production_order_id' => $order->id,
                'packaging_artwork_id' => $artwork->id, 'packed_quantity' => $quantity, 'uom_code' => $order->uom_code,
                'finished_lot_code' => $data['finished_lot_code'], 'manufacture_date' => $manufactureDate,
                'expiry_date' => $data['expiry_date'], 'target_location_id' => $location->id,
                'coding_value' => $coding, 'status' => 'DRAFT', 'record_version' => 1,
                'notes' => $this->nullable($data['notes'] ?? null), 'created_by' => $data['actor_id'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $result = ['entity_type' => 'packing_run', 'id' => $id, 'status' => 'DRAFT', 'record_version' => 1,
                'coding_value' => $coding, 'packed_quantity' => $quantity];
            $this->record('CREATE_PACKING_RUN', 'packing.run.created', 'packing_run', $id, $data, 1, [
                'production_order_id' => $order->id, 'artwork_id' => $artwork->id, 'packed_quantity' => $quantity,
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function completePackingRun(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'packing.run.complete.'.$id;
            if ($replay = $this->begin($namespace, $data + ['packing_run_id' => $id])) {
                return $replay;
            }
            $run = $this->findPackingRun($id, $data, true);
            $this->assertVersion($run, $data['expected_version'], 'packing run');
            if ($run->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => ['Only a draft packing run can be completed.']]);
            }
            $order = $this->findOrder($run->production_order_id, $data, true);
            $artwork = $this->findArtwork($run->packaging_artwork_id, $data, false);
            if ($order->status !== 'COMPLETED' || $order->quality_status !== 'RELEASED' || $artwork->status !== 'APPROVED') {
                throw new ConflictHttpException('The source batch or artwork lost release eligibility before packing completion.');
            }
            if (DB::table('lots')->where('company_id', $data['company_id'])->where('internal_lot_code', $run->finished_lot_code)->exists()) {
                throw new ConflictHttpException('The finished-goods lot code was claimed before packing completion.');
            }
            $owner = DB::table('inventory_owners')->where('company_id', $data['company_id'])
                ->where('owner_type', 'COMPANY')->where('status', 'ACTIVE')->first();
            if (! $owner) {
                throw new ConflictHttpException('No active company inventory owner is configured.');
            }
            $now = CarbonImmutable::now();
            $lotId = (string) Str::uuid();
            DB::table('lots')->insert([
                'id' => $lotId, 'company_id' => $data['company_id'], 'item_id' => $order->output_sku_id,
                'supplier_party_id' => null, 'internal_lot_code' => $run->finished_lot_code,
                'supplier_lot_code' => null, 'origin_type' => 'PRODUCTION',
                'manufacture_date' => $run->manufacture_date, 'expiry_date' => $run->expiry_date,
                'status' => 'ACTIVE', 'notes' => "Produced by {$order->order_number}; packed by {$run->run_number}.",
                'record_version' => 1, 'status_reason' => 'Created from a completed quality-released packing run.',
                'status_changed_at' => $now, 'status_changed_by' => $data['actor_id'],
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $positionId = (string) Str::uuid();
            DB::table('stock_positions')->insert([
                'id' => $positionId, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'item_id' => $order->output_sku_id, 'lot_id' => $lotId, 'owner_party_id' => null,
                'inventory_owner_id' => $owner->id, 'location_id' => $run->target_location_id,
                'quality_status' => 'RELEASED', 'quantity_base' => '0.000000', 'reserved_quantity_base' => '0.000000',
                'uom_code' => $run->uom_code, 'record_version' => 1,
                'status_reason' => 'Awaiting completed packing receipt.', 'status_changed_at' => $now,
                'status_changed_by' => $data['actor_id'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $movement = $this->stock->receive([
                'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'target_position_id' => $positionId, 'quantity_base' => $this->decimal($run->packed_quantity),
                'uom_code' => $run->uom_code, 'expected_item_id' => $order->output_sku_id,
                'expected_lot_id' => $lotId, 'expected_owner_id' => $owner->id,
                'expected_quality_status' => 'RELEASED', 'movement_type' => 'PRODUCTION_RECEIPT',
                'source_type' => 'packing_run', 'source_id' => $id, 'source_version' => (int) $run->record_version,
                'actor_id' => $data['actor_id'], 'reason_code' => 'PACKING_COMPLETION',
                'idempotency_key' => "packing-run:{$id}:completion", 'correlation_id' => $data['correlation_id'] ?? null,
            ]);
            $issues = DB::table('production_material_issues')->where('production_order_id', $order->id)->get();
            foreach ($issues as $issue) {
                DB::table('lot_genealogy_edges')->insert([
                    'id' => (string) Str::uuid(), 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                    'production_order_id' => $order->id, 'packing_run_id' => $id,
                    'production_material_issue_id' => $issue->id, 'input_lot_id' => $issue->input_lot_id,
                    'output_lot_id' => $lotId, 'input_quantity' => $issue->quantity,
                    'input_uom_code' => $issue->uom_code, 'output_quantity' => $run->packed_quantity,
                    'output_uom_code' => $run->uom_code, 'created_at' => $now,
                ]);
            }
            $version = (int) $run->record_version + 1;
            DB::table('packing_runs')->where('id', $id)->update([
                'status' => 'COMPLETED', 'record_version' => $version, 'finished_lot_id' => $lotId,
                'finished_position_id' => $positionId, 'stock_movement_id' => $movement['movement_id'],
                'completed_at' => $now, 'completed_by' => $data['actor_id'], 'updated_at' => $now,
            ]);
            DB::table('fg_lots')->insert([
                'id' => (string) Str::uuid(), 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'status' => 'ACTIVE', 'record_version' => 1, 'created_by' => $data['actor_id'],
                'lot_id' => $lotId, 'production_order_id' => $order->id, 'packing_run_id' => $id,
                'stock_position_id' => $positionId, 'stock_movement_id' => $movement['movement_id'],
                'packed_quantity' => $run->packed_quantity, 'uom_code' => $run->uom_code,
                'coding_value' => $run->coding_value, 'released_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $result = ['entity_type' => 'packing_run', 'id' => $id, 'status' => 'COMPLETED', 'record_version' => $version,
                'finished_lot_id' => $lotId, 'finished_position_id' => $positionId,
                'stock_movement_id' => $movement['movement_id'], 'genealogy_edge_count' => $issues->count()];
            $this->record('COMPLETE_PACKING_RUN', 'packing.run.completed', 'packing_run', $id, $data, $version, [
                'status' => ['from' => 'DRAFT', 'to' => 'COMPLETED'], 'finished_lot_id' => $lotId,
                'genealogy_edge_count' => $issues->count(),
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    public function cancelPackingRun(string $id, string $reason, array $data): array
    {
        return DB::transaction(function () use ($id, $reason, $data): array {
            $namespace = 'packing.run.cancel.'.$id;
            if ($replay = $this->begin($namespace, $data + ['packing_run_id' => $id, 'reason' => $reason])) {
                return $replay;
            }
            $run = $this->findPackingRun($id, $data, true);
            $this->assertVersion($run, $data['expected_version'], 'packing run');
            if ($run->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => ['Only a draft packing run can be cancelled.']]);
            }
            $now = CarbonImmutable::now();
            $version = (int) $run->record_version + 1;
            DB::table('packing_runs')->where('id', $id)->update([
                'status' => 'CANCELLED', 'record_version' => $version, 'cancelled_at' => $now,
                'cancelled_by' => $data['actor_id'], 'cancellation_reason' => trim($reason), 'updated_at' => $now,
            ]);
            $result = ['entity_type' => 'packing_run', 'id' => $id, 'status' => 'CANCELLED', 'record_version' => $version];
            $this->record('CANCEL_PACKING_RUN', 'packing.run.cancelled', 'packing_run', $id, $data, $version, [
                'status' => ['from' => 'DRAFT', 'to' => 'CANCELLED'], 'reason' => trim($reason),
            ], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    private function artworkTransition(string $id, string $target, ?string $reason, array $data): array
    {
        return DB::transaction(function () use ($id, $target, $reason, $data): array {
            $namespace = 'packing.artwork.'.strtolower($target).'.'.$id;
            if ($replay = $this->begin($namespace, $data + ['packaging_artwork_id' => $id, 'target' => $target, 'reason' => $reason])) {
                return $replay;
            }
            $artwork = $this->findArtwork($id, $data, true);
            $this->assertVersion($artwork, $data['expected_version'], 'packaging artwork');
            if (($target === 'APPROVED' && $artwork->status !== 'DRAFT') || ($target === 'RETIRED' && $artwork->status !== 'APPROVED')) {
                throw ValidationException::withMessages(['status' => ["The artwork cannot transition from {$artwork->status} to {$target}."]]);
            }
            if ($target === 'APPROVED' && DB::table('packaging_artworks')->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])->where('output_sku_id', $artwork->output_sku_id)
                ->where('status', 'APPROVED')->where('id', '<>', $id)->exists()) {
                throw ValidationException::withMessages(['status' => ['Retire the currently approved artwork for this SKU before approving another revision.']]);
            }
            $now = CarbonImmutable::now();
            $version = (int) $artwork->record_version + 1;
            $values = ['status' => $target, 'record_version' => $version, 'updated_at' => $now];
            if ($target === 'APPROVED') {
                $values += ['approved_at' => $now, 'approved_by' => $data['actor_id']];
            } else {
                $values += ['retired_at' => $now, 'retired_by' => $data['actor_id'], 'retirement_reason' => trim((string) $reason)];
            }
            DB::table('packaging_artworks')->where('id', $id)->update($values);
            $result = ['entity_type' => 'packaging_artwork', 'id' => $id, 'status' => $target, 'record_version' => $version];
            $this->record($target === 'APPROVED' ? 'APPROVE_PACKAGING_ARTWORK' : 'RETIRE_PACKAGING_ARTWORK',
                $target === 'APPROVED' ? 'packing.artwork.approved' : 'packing.artwork.retired',
                'packaging_artwork', $id, $data, $version, ['status' => ['from' => $artwork->status, 'to' => $target]], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);

            return $result;
        }, 3);
    }

    private function evaluateResult(object $row, array $input): array
    {
        if ($row->value_type === 'NUMERIC') {
            if (! array_key_exists('numeric_value', $input) || ! is_numeric($input['numeric_value'])) {
                throw ValidationException::withMessages(["results.{$row->sequence_no}.numeric_value" => ['A numeric result is required.']]);
            }
            $value = $this->decimal($input['numeric_value']);
            $passed = ($row->minimum_value === null || bccomp($value, $this->decimal($row->minimum_value), 6) >= 0)
                && ($row->maximum_value === null || bccomp($value, $this->decimal($row->maximum_value), 6) <= 0);

            return [$value, null, null, $passed];
        }
        if ($row->value_type === 'TEXT') {
            $value = trim((string) ($input['text_value'] ?? ''));
            if ($value === '' && $row->is_required) {
                throw ValidationException::withMessages(["results.{$row->sequence_no}.text_value" => ['A text result is required.']]);
            }

            return [null, $value === '' ? null : $value, null, strcasecmp($value, trim((string) $row->text_requirement)) === 0];
        }
        if (! array_key_exists('boolean_value', $input) || ! is_bool($input['boolean_value'])) {
            throw ValidationException::withMessages(["results.{$row->sequence_no}.boolean_value" => ['A boolean result is required.']]);
        }

        return [null, null, $input['boolean_value'], $input['boolean_value'] === true];
    }

    private function refreshOrderQuality(string $orderId, CarbonImmutable $now): void
    {
        $order = DB::table('production_orders')->where('id', $orderId)->lockForUpdate()->first();
        if (! $order || in_array($order->quality_status, ['RELEASED', 'RECALLED'], true)) {
            return;
        }
        $held = DB::table('food_safety_holds')->where('production_order_id', $orderId)->where('status', 'ACTIVE')->exists()
            || DB::table('quality_deviations')->where('production_order_id', $orderId)->where('status', 'OPEN')->exists();
        $latest = DB::table('lab_samples')->where('production_order_id', $orderId)->orderByDesc('sampled_at')->orderByDesc('created_at')->first();
        $target = $held || ($latest && $latest->status === 'FAILED') ? 'HELD' : 'PENDING';
        if ($order->quality_status !== $target) {
            $this->setOrderQuality($orderId, $target, $now);
        }
    }

    private function setOrderQuality(string $orderId, string $status, CarbonImmutable $now): void
    {
        DB::table('production_orders')->where('id', $orderId)->update([
            'quality_status' => $status, 'record_version' => DB::raw('record_version + 1'), 'updated_at' => $now,
        ]);
    }

    private function assertArtworkSku(string $skuId, array $scope): void
    {
        $sku = DB::table('items')->where('id', $skuId)->where('company_id', $scope['company_id'])
            ->where('item_type', 'FINISHED_GOOD')->where('status', 'ACTIVE')->first();
        if (! $sku) {
            throw ValidationException::withMessages(['output_sku_id' => ['Choose an active finished-goods SKU in the selected company.']]);
        }
    }

    private function codingValue(string $template, array $data, object $order): string
    {
        return str_replace(
            ['{LOT}', '{MFG}', '{EXP}', '{BATCH}'],
            [$data['finished_lot_code'], $data['manufacture_date'], $data['expiry_date'], $order->batch_number],
            $template,
        );
    }

    private function findOrder(string $id, array $scope, bool $lock): object
    {
        $query = DB::table('production_orders')->where('id', $id)
            ->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id']);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $row) {
            throw new NotFoundHttpException('Production order not found.');
        }

        return $row;
    }

    private function findSample(string $id, array $scope, bool $lock): object
    {
        $query = DB::table('lab_samples')->where('id', $id)
            ->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id']);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $row) {
            throw new NotFoundHttpException('Lab sample not found.');
        }

        return $row;
    }

    private function findArtwork(string $id, array $scope, bool $lock): object
    {
        $query = DB::table('packaging_artworks')->where('id', $id)
            ->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id']);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $row) {
            throw new NotFoundHttpException('Packaging artwork not found.');
        }

        return $row;
    }

    private function findPackingRun(string $id, array $scope, bool $lock): object
    {
        $query = DB::table('packing_runs')->where('id', $id)
            ->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id']);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $row) {
            throw new NotFoundHttpException('Packing run not found.');
        }

        return $row;
    }

    private function assertUnique(string $table, string $column, string $number, array $scope): void
    {
        if (DB::table($table)->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])
            ->where($column, $number)->exists()) {
            throw ValidationException::withMessages([$column => ['That document number already exists in the selected plant.']]);
        }
    }

    private function assertVersion(object $row, int $expected, string $label): void
    {
        if ((int) $row->record_version !== $expected) {
            throw new ConflictHttpException("The {$label} changed from version {$expected} to {$row->record_version}. Refresh it before continuing.");
        }
    }

    private function deviationNumber(string $sampleNumber, int $sequence): string
    {
        $safe = preg_replace('/[^A-Z0-9_-]/', '-', Str::upper($sampleNumber)) ?: 'SAMPLE';

        return 'DEV-'.substr($safe, 0, 69).'-'.str_pad((string) $sequence, 2, '0', STR_PAD_LEFT);
    }

    private function record(string $command, string $event, string $entityType, string $id, array $data, int $version, array $safeDiff, array $result): void
    {
        $this->audit->record($command, $entityType, $id, $data['actor_id'], $data['company_id'], $data['plant_id'], 'SUCCESS', [
            'entity_version' => $version, 'correlation_id' => $data['correlation_id'] ?? null, 'safe_diff' => $safeDiff,
        ]);
        $this->outbox->append($event, $entityType, $id, $id.':'.$version, $result + [
            'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
        ], $data['correlation_id'] ?? null, $data['company_id'], $data['plant_id']);
    }

    private function begin(string $namespace, array $data): ?array
    {
        return $this->idempotency->begin($namespace, $data['idempotency_key'], Arr::except($data, [
            'actor_id', 'permissions', 'idempotency_key', 'correlation_id',
        ]));
    }

    private function positive(mixed $value, string $field, string $label): string
    {
        $value = (string) $value;
        if (! preg_match('/^\d{1,14}(?:\.\d{1,6})?$/', $value) || bccomp($value, '0', 6) <= 0) {
            throw ValidationException::withMessages([$field => ["{$label} must be positive with at most 6 decimal places."]]);
        }

        return $this->decimal($value);
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 6);
    }

    private function nullable(mixed $value): ?string
    {
        $value = $value === null ? '' : trim((string) $value);

        return $value === '' ? null : $value;
    }
}

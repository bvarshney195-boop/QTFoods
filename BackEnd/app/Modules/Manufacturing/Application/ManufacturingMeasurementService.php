<?php

namespace App\Modules\Manufacturing\Application;

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

final class ManufacturingMeasurementService
{
    public const KINDS = ['INCOMING', 'ADDITIONAL', 'OUTPUT', 'PHYSICAL_LOSS', 'NORMAL_TRANSFORMATION_LOSS',
        'REJECTED', 'HELD', 'REWORK_IN', 'REWORK_OUT', 'RESIDUAL', 'UNEXPLAINED', 'WEIGHT_SAMPLE'];
    public const DIMENSIONS = ['MASS', 'COUNT', 'VOLUME', 'PACK'];
    public const VALUE_SOURCES = ['MEASURED', 'ESTIMATED', 'DERIVED'];

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    /** @return array<string, mixed> */
    public function createTemplateForStage(string $stageId, array $data): array
    {
        return DB::transaction(function () use ($stageId, $data): array {
            $namespace = 'manufacturing.stage-measurement-template.create.'.$stageId;
            if ($replay = $this->begin($namespace, $data + ['production_stage_id' => $stageId])) {
                return $replay;
            }
            $stage = $this->stage($stageId, $data, true);
            if ($stage->stage_status !== 'PENDING' || ! in_array($stage->order_status, ['DRAFT', 'RELEASED', 'IN_PROCESS'], true)) {
                throw ValidationException::withMessages(['status' => ['Measurement templates can only be proposed before the stage starts.']]);
            }
            $this->validateTemplateLines($data['lines']);
            $revision = (int) DB::table('stage_measurement_templates')
                ->where('recipe_id', $stage->recipe_id)->where('route_operation_id', $stage->route_operation_id)
                ->max('revision') + 1;
            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('stage_measurement_templates')->insert([
                'id' => $id, 'company_id' => $data['company_id'], 'recipe_id' => $stage->recipe_id,
                'route_id' => $stage->route_id, 'route_operation_id' => $stage->route_operation_id,
                'recipe_revision' => (int) $stage->recipe_revision_snapshot,
                'route_revision' => (int) $stage->route_revision_snapshot,
                'revision' => $revision, 'name' => trim($data['name']), 'status' => 'DRAFT', 'record_version' => 1,
                'created_by' => $data['actor_id'], 'approved_by' => null, 'approved_at' => null,
                'retired_by' => null, 'retired_at' => null, 'retirement_reason' => null,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            foreach ($data['lines'] as $index => $line) {
                DB::table('stage_measurement_template_lines')->insert([
                    'id' => (string) Str::uuid(), 'template_id' => $id, 'company_id' => $data['company_id'],
                    'sequence_no' => $index + 1, 'measurement_kind' => Str::upper($line['measurement_kind']),
                    'quantity_dimension' => Str::upper($line['quantity_dimension']), 'uom_code' => Str::upper($line['uom_code']),
                    'is_required' => (bool) $line['is_required'],
                    'default_value_source' => Str::upper($line['default_value_source']),
                    'measurement_method' => $this->nullable($line['measurement_method'] ?? null),
                    'balance_tolerance' => $this->nonNegative($line['balance_tolerance'] ?? 0, "lines.{$index}.balance_tolerance"),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
            }
            $result = ['entity_type' => 'stage_measurement_template', 'id' => $id, 'status' => 'DRAFT',
                'record_version' => 1, 'revision' => $revision, 'production_stage_id' => $stageId];
            $this->record('CREATE_STAGE_MEASUREMENT_TEMPLATE', 'manufacturing.stage-measurement-template.created',
                'stage_measurement_template', $id, $data, 1, ['recipe_id' => $stage->recipe_id,
                    'route_operation_id' => $stage->route_operation_id, 'line_count' => count($data['lines'])], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
            return $result;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function approveTemplate(string $templateId, array $data): array
    {
        return DB::transaction(function () use ($templateId, $data): array {
            $namespace = 'manufacturing.stage-measurement-template.approve.'.$templateId;
            if ($replay = $this->begin($namespace, $data + ['template_id' => $templateId])) {
                return $replay;
            }
            $template = DB::table('stage_measurement_templates')->where('id', $templateId)
                ->where('company_id', $data['company_id'])->lockForUpdate()->first();
            if (! $template) {
                throw new NotFoundHttpException('Stage measurement template not found.');
            }
            if ($template->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => ['Only a draft measurement template can be approved.']]);
            }
            if ($template->created_by === $data['actor_id']) {
                throw ValidationException::withMessages(['approver' => ['Template approval must be performed by a different authorised user.']]);
            }
            $lines = DB::table('stage_measurement_template_lines')->where('template_id', $templateId)->orderBy('sequence_no')->get();
            if ($lines->isEmpty()) {
                throw new ConflictHttpException('A measurement template requires at least one line before approval.');
            }
            $now = CarbonImmutable::now();
            DB::table('stage_measurement_templates')->where('recipe_id', $template->recipe_id)
                ->where('route_operation_id', $template->route_operation_id)->where('status', 'APPROVED')
                ->update(['status' => 'RETIRED', 'retired_by' => $data['actor_id'], 'retired_at' => $now,
                    'retirement_reason' => "Superseded by revision {$template->revision}.", 'updated_at' => $now]);
            $version = (int) $template->record_version + 1;
            DB::table('stage_measurement_templates')->where('id', $templateId)->update([
                'status' => 'APPROVED', 'record_version' => $version, 'approved_by' => $data['actor_id'],
                'approved_at' => $now, 'updated_at' => $now,
            ]);
            $snapshot = json_encode(['id' => $templateId, 'revision' => (int) $template->revision,
                'recipe_revision' => (int) $template->recipe_revision,
                'route_revision' => (int) $template->route_revision,
                'name' => (string) $template->name, 'lines' => $lines->map(fn (object $line): array => (array) $line)->all()], JSON_THROW_ON_ERROR);
            $stageIds = DB::table('production_order_stages as stage')
                ->join('production_orders as orders', 'orders.id', '=', 'stage.production_order_id')
                ->join('production_schedule_operations as operation', 'operation.id', '=', 'stage.production_schedule_operation_id')
                ->where('stage.company_id', $data['company_id'])->where('stage.status', 'PENDING')
                ->where('orders.recipe_id', $template->recipe_id)->where('operation.route_operation_id', $template->route_operation_id)
                ->where('orders.recipe_revision_snapshot', $template->recipe_revision)
                ->where('orders.route_revision_snapshot', $template->route_revision)
                ->pluck('stage.id');
            if ($stageIds->isNotEmpty()) {
                DB::table('production_order_stages')->whereIn('id', $stageIds)->update([
                    'measurement_template_id' => $templateId, 'measurement_template_revision' => $template->revision,
                    'measurement_template_snapshot' => $snapshot, 'updated_at' => $now,
                ]);
            }
            $result = ['entity_type' => 'stage_measurement_template', 'id' => $templateId, 'status' => 'APPROVED',
                'record_version' => $version, 'revision' => (int) $template->revision];
            $this->record('APPROVE_STAGE_MEASUREMENT_TEMPLATE', 'manufacturing.stage-measurement-template.approved',
                'stage_measurement_template', $templateId, $data, $version, ['status' => ['from' => 'DRAFT', 'to' => 'APPROVED']], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
            return $result;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function recordMeasurements(string $stageId, array $data): array
    {
        return DB::transaction(function () use ($stageId, $data): array {
            $namespace = 'manufacturing.stage-measurements.record.'.$stageId;
            if ($replay = $this->begin($namespace, $data + ['production_stage_id' => $stageId])) {
                return $replay;
            }
            $stage = $this->stage($stageId, $data, true);
            if ($stage->order_status !== 'IN_PROCESS' || $stage->stage_status !== 'IN_PROGRESS') {
                throw ValidationException::withMessages(['status' => ['Measurements can only be recorded for an in-progress stage.']]);
            }
            $now = CarbonImmutable::now();
            $sequence = (int) DB::table('production_stage_measurements')->where('production_order_stage_id', $stageId)->max('entry_sequence');
            $ids = [];
            foreach ($data['measurements'] as $index => $line) {
                $kind = Str::upper($line['measurement_kind']);
                $dimension = Str::upper($line['quantity_dimension']);
                $source = Str::upper($line['value_source']);
                if (! in_array($kind, self::KINDS, true) || ! in_array($dimension, self::DIMENSIONS, true)
                    || ! in_array($source, self::VALUE_SOURCES, true)) {
                    throw ValidationException::withMessages(["measurements.{$index}" => ['Invalid measurement kind, dimension or value source.']]);
                }
                if (in_array($kind, ['PHYSICAL_LOSS', 'REJECTED', 'HELD', 'UNEXPLAINED'], true)
                    && trim((string) ($line['reason_code'] ?? '')) === '') {
                    throw ValidationException::withMessages(["measurements.{$index}.reason_code" => ['This measurement requires a reason code.']]);
                }
                if (in_array($kind, ['REJECTED', 'HELD', 'REWORK_OUT'], true)
                    && trim((string) ($line['disposition_code'] ?? '')) === '') {
                    throw ValidationException::withMessages(["measurements.{$index}.disposition_code" => ['This measurement requires a disposition.']]);
                }
                if (! DB::table('uoms')->where('code', Str::upper($line['uom_code']))->exists()) {
                    throw ValidationException::withMessages(["measurements.{$index}.uom_code" => ['Unknown UOM code.']]);
                }
                $id = (string) Str::uuid();
                $ids[] = $id;
                DB::table('production_stage_measurements')->insert([
                    'id' => $id, 'production_order_id' => $stage->production_order_id,
                    'production_order_stage_id' => $stageId, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                    'entry_sequence' => ++$sequence, 'measurement_kind' => $kind, 'quantity_dimension' => $dimension,
                    'quantity' => $this->nonNegative($line['quantity'], "measurements.{$index}.quantity"),
                    'uom_code' => Str::upper($line['uom_code']), 'value_source' => $source,
                    'measurement_source' => trim($line['measurement_source']),
                    'measurement_method' => trim($line['measurement_method']),
                    'target_quantity' => isset($line['target_quantity']) && $line['target_quantity'] !== null
                        ? $this->nonNegative($line['target_quantity'], "measurements.{$index}.target_quantity") : null,
                    'reason_code' => $this->upperNullable($line['reason_code'] ?? null),
                    'disposition_code' => $this->upperNullable($line['disposition_code'] ?? null),
                    'notes' => $this->nullable($line['notes'] ?? null), 'recorded_by' => $data['actor_id'],
                    'recorded_at' => isset($line['recorded_at']) ? CarbonImmutable::parse($line['recorded_at']) : $now,
                    'reversal_of_id' => null, 'created_at' => $now,
                ]);
            }
            $balance = $this->balance($stageId);
            $result = ['entity_type' => 'production_stage', 'id' => $stageId,
                'production_order_id' => (string) $stage->production_order_id, 'measurement_ids' => $ids,
                'measurement_count' => count($ids), 'mass_balance' => $balance];
            $this->record('RECORD_STAGE_MEASUREMENTS', 'manufacturing.stage-measurements.recorded',
                'production_stage', $stageId, $data, (int) $stage->stage_version,
                ['measurement_count' => count($ids), 'kinds' => collect($data['measurements'])->pluck('measurement_kind')->all()], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
            return $result;
        }, 3);
    }

    /** @return array<int, array<string, mixed>> */
    public function measurements(string $stageId): array
    {
        return DB::table('production_stage_measurements as measurement')
            ->join('users as actor', 'actor.id', '=', 'measurement.recorded_by')
            ->where('measurement.production_order_stage_id', $stageId)->orderBy('measurement.entry_sequence')
            ->get(['measurement.*', 'actor.name as actor_name'])->map(fn (object $row): array => [
                'id' => (string) $row->id, 'entry_sequence' => (int) $row->entry_sequence,
                'measurement_kind' => (string) $row->measurement_kind, 'quantity_dimension' => (string) $row->quantity_dimension,
                'quantity' => $this->decimal($row->quantity), 'uom_code' => (string) $row->uom_code,
                'value_source' => (string) $row->value_source, 'measurement_source' => (string) $row->measurement_source,
                'measurement_method' => (string) $row->measurement_method,
                'target_quantity' => $row->target_quantity === null ? null : $this->decimal($row->target_quantity),
                'reason_code' => $row->reason_code, 'disposition_code' => $row->disposition_code, 'notes' => $row->notes,
                'operator' => ['id' => (string) $row->recorded_by, 'name' => (string) $row->actor_name],
                'recorded_at' => $row->recorded_at, 'reversal_of_id' => $row->reversal_of_id,
            ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function balance(string $stageId): array
    {
        $rows = DB::table('production_stage_measurements')->where('production_order_stage_id', $stageId)
            ->whereNull('reversal_of_id')->where('measurement_kind', '<>', 'WEIGHT_SAMPLE')->get();
        return $rows->groupBy(fn (object $row): string => $row->quantity_dimension.'|'.$row->uom_code)
            ->map(function ($group, string $key): array {
                [$dimension, $uom] = explode('|', $key, 2);
                $sum = fn (array $kinds): string => $this->decimal($group->whereIn('measurement_kind', $kinds)->sum('quantity'));
                $inputs = $sum(['INCOMING', 'ADDITIONAL', 'REWORK_IN']);
                $outputs = $sum(['OUTPUT', 'PHYSICAL_LOSS', 'NORMAL_TRANSFORMATION_LOSS', 'REJECTED', 'HELD', 'REWORK_OUT', 'RESIDUAL']);
                $recordedUnexplained = $sum(['UNEXPLAINED']);
                $calculated = bcsub($inputs, $outputs, 6);
                return ['quantity_dimension' => $dimension, 'uom_code' => $uom, 'input_quantity' => $inputs,
                    'accounted_output_quantity' => $outputs, 'calculated_unexplained_quantity' => $calculated,
                    'recorded_unexplained_quantity' => $recordedUnexplained,
                    'measured_quantity' => $this->decimal($group->where('value_source', 'MEASURED')->sum('quantity')),
                    'estimated_quantity' => $this->decimal($group->where('value_source', 'ESTIMATED')->sum('quantity'))];
            })->values()->all();
    }

    public function assertStageReadyForCompletion(string $stageId): void
    {
        $stage = DB::table('production_order_stages')->where('id', $stageId)->first();
        if (! $stage || $stage->measurement_template_id === null) {
            return;
        }
        $required = DB::table('stage_measurement_template_lines')->where('template_id', $stage->measurement_template_id)
            ->where('is_required', true)->get();
        foreach ($required as $line) {
            if (! DB::table('production_stage_measurements')->where('production_order_stage_id', $stageId)
                ->where('measurement_kind', $line->measurement_kind)->where('quantity_dimension', $line->quantity_dimension)
                ->where('uom_code', $line->uom_code)->exists()) {
                throw ValidationException::withMessages(['measurements' => ["Record required {$line->measurement_kind} {$line->quantity_dimension} in {$line->uom_code} before completion."]]);
            }
        }
        foreach ($this->balance($stageId) as $balance) {
            $absoluteDifference = $this->absolute($balance['calculated_unexplained_quantity']);
            if (bccomp($absoluteDifference, '0', 6) === 0) {
                continue;
            }
            $tolerance = $this->decimal($required->where('quantity_dimension', $balance['quantity_dimension'])
                ->where('uom_code', $balance['uom_code'])->max('balance_tolerance') ?? 0);
            if (bccomp($absoluteDifference, $tolerance, 6) > 0
                && bccomp($balance['recorded_unexplained_quantity'], '0', 6) <= 0) {
                throw ValidationException::withMessages(['measurements' => ['Record and explain the stage mass/count imbalance before completion.']]);
            }
        }
    }

    private function stage(string $stageId, array $scope, bool $lock): object
    {
        $query = DB::table('production_order_stages as stage')
            ->join('production_orders as orders', 'orders.id', '=', 'stage.production_order_id')
            ->join('production_schedule_operations as operation', 'operation.id', '=', 'stage.production_schedule_operation_id')
            ->where('stage.id', $stageId)->where('stage.company_id', $scope['company_id'])->where('stage.plant_id', $scope['plant_id']);
        $row = ($lock ? $query->lockForUpdate() : $query)->first(['stage.id', 'stage.production_order_id',
            'stage.status as stage_status', 'stage.record_version as stage_version', 'orders.status as order_status',
            'orders.recipe_id', 'orders.route_id', 'orders.recipe_revision_snapshot', 'orders.route_revision_snapshot',
            'orders.production_schedule_line_id', 'operation.route_operation_id']);
        if (! $row) {
            throw new NotFoundHttpException('Production stage not found.');
        }
        return $row;
    }

    private function validateTemplateLines(array $lines): void
    {
        $seen = [];
        foreach ($lines as $index => $line) {
            $kind = Str::upper($line['measurement_kind']);
            $dimension = Str::upper($line['quantity_dimension']);
            $source = Str::upper($line['default_value_source']);
            if (! in_array($kind, self::KINDS, true) || ! in_array($dimension, self::DIMENSIONS, true)
                || ! in_array($source, self::VALUE_SOURCES, true)) {
                throw ValidationException::withMessages(["lines.{$index}" => ['Invalid template measurement kind, dimension or source.']]);
            }
            $key = $kind.'|'.$dimension;
            if (isset($seen[$key])) {
                throw ValidationException::withMessages(["lines.{$index}.measurement_kind" => ['That kind and dimension is duplicated.']]);
            }
            $seen[$key] = true;
            if (! DB::table('uoms')->where('code', Str::upper($line['uom_code']))->exists()) {
                throw ValidationException::withMessages(["lines.{$index}.uom_code" => ['Unknown UOM code.']]);
            }
        }
    }

    private function record(string $command, string $event, string $type, string $id, array $data, int $version, array $diff, array $result): void
    {
        $this->audit->record($command, $type, $id, $data['actor_id'], $data['company_id'], $data['plant_id'], 'SUCCESS', [
            'entity_version' => $version, 'correlation_id' => $data['correlation_id'] ?? null, 'safe_diff' => $diff,
        ]);
        $this->outbox->append($event, $type, $id, $id.':'.$version, $result + [
            'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
        ], $data['correlation_id'] ?? null, $data['company_id'], $data['plant_id']);
    }

    private function begin(string $namespace, array $data): ?array
    {
        return $this->idempotency->begin($namespace, $data['idempotency_key'], Arr::except($data, [
            'actor_id', 'permissions', 'idempotency_key', 'correlation_id',
        ]));
    }

    private function nonNegative(mixed $value, string $field): string
    {
        $value = (string) $value;
        if (! preg_match('/^\d{1,14}(?:\.\d{1,6})?$/', $value)) {
            throw ValidationException::withMessages([$field => ['Enter a non-negative quantity with at most 6 decimals.']]);
        }
        return $this->decimal($value);
    }

    private function decimal(mixed $value): string
    {
        return bcadd((string) ($value ?? 0), '0', 6);
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));
        return $value === '' ? null : $value;
    }

    private function upperNullable(mixed $value): ?string
    {
        $value = $this->nullable($value);
        return $value === null ? null : Str::upper($value);
    }

    private function absolute(string $value): string
    {
        return str_starts_with($value, '-') ? substr($value, 1) : $value;
    }
}

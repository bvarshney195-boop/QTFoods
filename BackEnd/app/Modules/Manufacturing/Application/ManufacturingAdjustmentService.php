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

/**
 * Additive correction journal for completed production batches.
 *
 * Historical postings are never edited or deleted. Approved effects are
 * applied by read models as signed deltas and retain independent approval.
 */
final class ManufacturingAdjustmentService
{
    public const TYPES = ['MATERIAL', 'OUTPUT', 'STAGE_MEASUREMENT', 'COST'];
    public const METRICS = ['GOOD_OUTPUT', 'LOSS_OUTPUT', 'REWORK_OUTPUT',
        'MATERIAL_NET_CONSUMPTION', 'STAGE_MEASUREMENT', 'COST_VALUE'];

    private const TYPE_METRICS = [
        'MATERIAL' => ['MATERIAL_NET_CONSUMPTION'],
        'OUTPUT' => ['GOOD_OUTPUT', 'LOSS_OUTPUT', 'REWORK_OUTPUT'],
        'STAGE_MEASUREMENT' => ['STAGE_MEASUREMENT'],
        'COST' => ['COST_VALUE'],
    ];

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
    ) {}

    /** @return array<string, mixed> */
    public function request(string $orderId, array $data): array
    {
        return DB::transaction(function () use ($orderId, $data): array {
            $namespace = 'manufacturing.batch-adjustment.request.'.$orderId;
            if ($replay = $this->begin($namespace, $data + ['production_order_id' => $orderId])) {
                return $replay;
            }
            $order = $this->order($orderId, $data, true);
            if ($order->status !== 'COMPLETED') {
                throw ValidationException::withMessages(['production_order_id' => ['Only a completed batch uses the correction journal.']]);
            }
            if ((int) $order->record_version !== (int) $data['expected_version']) {
                throw new ConflictHttpException('The production order changed after it was opened. Refresh and retry.');
            }
            if (DB::table('production_batch_adjustments')->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])->where('adjustment_number', $data['adjustment_number'])->exists()) {
                throw ValidationException::withMessages(['adjustment_number' => ['That adjustment number already exists in this plant.']]);
            }
            $type = Str::upper($data['adjustment_type']);
            $effects = $this->validatedEffects($orderId, $type, $data['effects'], $data);
            $id = (string) Str::uuid();
            $now = CarbonImmutable::now();
            DB::table('production_batch_adjustments')->insert([
                'id' => $id, 'production_order_id' => $orderId, 'company_id' => $data['company_id'],
                'plant_id' => $data['plant_id'], 'adjustment_number' => $data['adjustment_number'],
                'adjustment_type' => $type, 'payload' => json_encode(['effects' => $effects], JSON_THROW_ON_ERROR),
                'reason' => trim($data['reason']), 'status' => 'PENDING', 'record_version' => 1,
                'requested_by' => $data['actor_id'], 'requested_at' => $now, 'reviewed_by' => null,
                'reviewed_at' => null, 'review_notes' => null, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $result = ['entity_type' => 'production_batch_adjustment', 'id' => $id,
                'production_order_id' => $orderId, 'status' => 'PENDING', 'record_version' => 1,
                'effect_count' => count($effects)];
            $this->record('REQUEST_PRODUCTION_BATCH_ADJUSTMENT', 'manufacturing.batch-adjustment.requested',
                $id, $data, 1, ['adjustment_type' => $type, 'effect_count' => count($effects)], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
            return $result;
        }, 3);
    }

    /** @return array<string, mixed> */
    public function review(string $adjustmentId, string $decision, string $notes, array $data): array
    {
        return DB::transaction(function () use ($adjustmentId, $decision, $notes, $data): array {
            $decision = Str::upper($decision);
            $namespace = 'manufacturing.batch-adjustment.'.Str::lower($decision).'.'.$adjustmentId;
            if ($replay = $this->begin($namespace, $data + ['adjustment_id' => $adjustmentId,
                'decision' => $decision, 'review_notes' => $notes])) {
                return $replay;
            }
            $adjustment = DB::table('production_batch_adjustments')->where('id', $adjustmentId)
                ->where('company_id', $data['company_id'])->where('plant_id', $data['plant_id'])
                ->lockForUpdate()->first();
            if (! $adjustment) {
                throw new NotFoundHttpException('Production batch adjustment not found.');
            }
            if ((int) $adjustment->record_version !== (int) $data['expected_version']) {
                throw new ConflictHttpException('The adjustment changed after it was opened. Refresh and retry.');
            }
            if ($adjustment->status !== 'PENDING') {
                throw ValidationException::withMessages(['status' => ['Only a pending adjustment can be reviewed.']]);
            }
            if ($adjustment->requested_by === $data['actor_id']) {
                throw ValidationException::withMessages(['reviewer' => ['The requester cannot approve or reject their own batch correction.']]);
            }
            if (! in_array($decision, ['APPROVED', 'REJECTED'], true)) {
                throw ValidationException::withMessages(['decision' => ['Decision must be APPROVED or REJECTED.']]);
            }
            $order = $this->order((string) $adjustment->production_order_id, $data, true);
            if ($order->status !== 'COMPLETED') {
                throw new ConflictHttpException('The referenced batch is no longer completed.');
            }
            $payload = is_string($adjustment->payload)
                ? json_decode($adjustment->payload, true, 512, JSON_THROW_ON_ERROR) : (array) $adjustment->payload;
            $effects = $this->validatedEffects((string) $order->id, (string) $adjustment->adjustment_type,
                $payload['effects'] ?? [], $data);
            $now = CarbonImmutable::now();
            if ($decision === 'APPROVED') {
                $this->assertProjectedBalances((string) $order->id, $effects);
                foreach ($effects as $effect) {
                    DB::table('production_batch_adjustment_effects')->insert([
                        'id' => (string) Str::uuid(), 'production_batch_adjustment_id' => $adjustmentId,
                        'production_order_id' => $order->id, 'company_id' => $data['company_id'],
                        'plant_id' => $data['plant_id'], 'metric_code' => $effect['metric_code'],
                        'subject_type' => $effect['subject_type'], 'subject_id' => $effect['subject_id'],
                        'signed_quantity' => $effect['signed_quantity'], 'uom_code' => $effect['uom_code'],
                        'signed_value' => $effect['signed_value'], 'currency' => $effect['currency'],
                        'reason' => trim($adjustment->reason), 'approved_by' => $data['actor_id'],
                        'approved_at' => $now, 'created_at' => $now,
                    ]);
                }
            }
            $version = (int) $adjustment->record_version + 1;
            DB::table('production_batch_adjustments')->where('id', $adjustmentId)->update([
                'status' => $decision, 'record_version' => $version, 'reviewed_by' => $data['actor_id'],
                'reviewed_at' => $now, 'review_notes' => trim($notes), 'updated_at' => $now,
            ]);
            DB::table('production_orders')->where('id', $order->id)->update([
                'record_version' => (int) $order->record_version + 1, 'updated_at' => $now,
            ]);
            $result = ['entity_type' => 'production_batch_adjustment', 'id' => $adjustmentId,
                'production_order_id' => (string) $order->id, 'status' => $decision,
                'record_version' => $version, 'effect_count' => $decision === 'APPROVED' ? count($effects) : 0];
            $this->record('REVIEW_PRODUCTION_BATCH_ADJUSTMENT', 'manufacturing.batch-adjustment.'.Str::lower($decision),
                $adjustmentId, $data, $version, ['status' => ['from' => 'PENDING', 'to' => $decision],
                    'effect_count' => $result['effect_count']], $result);
            $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
            return $result;
        }, 3);
    }

    /** @return array<int, array<string, mixed>> */
    private function validatedEffects(string $orderId, string $type, array $effects, array $scope): array
    {
        if (! in_array($type, self::TYPES, true) || $effects === [] || count($effects) > 50) {
            throw ValidationException::withMessages(['effects' => ['Provide 1 to 50 effects for a supported adjustment type.']]);
        }
        $validated = [];
        foreach ($effects as $index => $effect) {
            $metric = Str::upper((string) ($effect['metric_code'] ?? ''));
            if (! in_array($metric, self::TYPE_METRICS[$type], true)) {
                throw ValidationException::withMessages(["effects.{$index}.metric_code" => ['Metric does not match the adjustment type.']]);
            }
            $quantity = $this->signed($effect['signed_quantity'] ?? 0, "effects.{$index}.signed_quantity");
            $value = $this->signed($effect['signed_value'] ?? 0, "effects.{$index}.signed_value");
            if (bccomp($quantity, '0', 6) === 0 && bccomp($value, '0', 6) === 0) {
                throw ValidationException::withMessages(["effects.{$index}" => ['At least one signed quantity or value must be non-zero.']]);
            }
            $subjectId = $effect['subject_id'] ?? null;
            $subjectType = match ($metric) {
                'MATERIAL_NET_CONSUMPTION' => 'PRODUCTION_MATERIAL',
                'STAGE_MEASUREMENT' => 'PRODUCTION_STAGE',
                'COST_VALUE' => 'BATCH_COST',
                default => 'PRODUCTION_ORDER',
            };
            $this->assertSubject($metric, $subjectId, $orderId, $scope, $index);
            $uom = trim((string) ($effect['uom_code'] ?? ''));
            if (bccomp($quantity, '0', 6) !== 0 && ($uom === '' || ! DB::table('uoms')->where('code', Str::upper($uom))->exists())) {
                throw ValidationException::withMessages(["effects.{$index}.uom_code" => ['A configured UOM is required for quantity corrections.']]);
            }
            $currency = bccomp($value, '0', 6) === 0 ? null : Str::upper((string) ($effect['currency'] ?? 'INR'));
            if ($currency !== null && $currency !== 'INR') {
                throw ValidationException::withMessages(["effects.{$index}.currency" => ['Only INR correction values are supported.']]);
            }
            if ($metric === 'COST_VALUE' && (bccomp($quantity, '0', 6) !== 0 || bccomp($value, '0', 6) === 0)) {
                throw ValidationException::withMessages(["effects.{$index}" => ['Cost corrections require a non-zero signed value and no quantity.']]);
            }
            if ($metric !== 'COST_VALUE' && (bccomp($quantity, '0', 6) === 0 || bccomp($value, '0', 6) !== 0)) {
                throw ValidationException::withMessages(["effects.{$index}" => ['Quantity corrections require a non-zero signed quantity and no value.']]);
            }
            $this->assertUom($metric, $subjectId, $orderId, $uom, $index);
            $validated[] = ['metric_code' => $metric, 'subject_type' => $subjectType,
                'subject_id' => $subjectId, 'signed_quantity' => $quantity,
                'uom_code' => $uom === '' ? null : Str::upper($uom), 'signed_value' => $value,
                'currency' => $currency];
        }
        return $validated;
    }

    private function assertSubject(string $metric, mixed $subjectId, string $orderId, array $scope, int $index): void
    {
        $valid = match ($metric) {
            'MATERIAL_NET_CONSUMPTION' => $subjectId && DB::table('production_order_materials')->where('id', $subjectId)
                ->where('production_order_id', $orderId)->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])->exists(),
            'STAGE_MEASUREMENT' => $subjectId && DB::table('production_order_stages')->where('id', $subjectId)
                ->where('production_order_id', $orderId)->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])->exists(),
            'COST_VALUE' => $subjectId && DB::table('batch_costs')->where('id', $subjectId)
                ->where('production_order_id', $orderId)->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])->exists(),
            default => $subjectId === null || $subjectId === '' || $subjectId === $orderId,
        };
        if (! $valid) {
            throw ValidationException::withMessages(["effects.{$index}.subject_id" => ['Correction subject is not part of this production batch.']]);
        }
    }

    private function assertUom(string $metric, mixed $subjectId, string $orderId, string $uom, int $index): void
    {
        if ($metric === 'COST_VALUE') {
            return;
        }
        $expected = match ($metric) {
            'MATERIAL_NET_CONSUMPTION' => DB::table('production_order_materials')->where('id', $subjectId)->value('uom_code'),
            'GOOD_OUTPUT', 'LOSS_OUTPUT', 'REWORK_OUTPUT' => DB::table('production_orders')->where('id', $orderId)->value('uom_code'),
            default => null,
        };
        if ($expected !== null && Str::upper($uom) !== Str::upper((string) $expected)) {
            throw ValidationException::withMessages(["effects.{$index}.uom_code" => ["The correction UOM must be {$expected}."]]);
        }
    }

    /** @param array<int, array<string, mixed>> $effects */
    private function assertProjectedBalances(string $orderId, array $effects): void
    {
        $grouped = collect($effects)->groupBy(fn (array $effect): string =>
            $effect['metric_code'].'|'.($effect['subject_id'] ?? '').'|'.($effect['uom_code'] ?? ''));
        foreach ($grouped as $key => $rows) {
            /** @var array<string, mixed> $sample */
            $sample = $rows->first();
            $metric = (string) $sample['metric_code'];
            $subjectId = $sample['subject_id'];
            $uom = $sample['uom_code'];
            $existing = DB::table('production_batch_adjustment_effects')
                ->where('production_order_id', $orderId)->where('metric_code', $metric)
                ->when($subjectId, fn ($query) => $query->where('subject_id', $subjectId),
                    fn ($query) => $query->whereNull('subject_id'))
                ->when($metric !== 'COST_VALUE', fn ($query) => $query->where('uom_code', $uom))
                ->sum($metric === 'COST_VALUE' ? 'signed_value' : 'signed_quantity');
            $proposed = $rows->reduce(fn (string $carry, array $effect): string => bcadd($carry,
                $metric === 'COST_VALUE' ? $effect['signed_value'] : $effect['signed_quantity'], 6), '0.000000');
            $base = $this->baseBalance($orderId, $metric, $subjectId, $uom);
            if (bccomp(bcadd(bcadd($base, (string) $existing, 6), $proposed, 6), '0', 6) < 0) {
                throw ValidationException::withMessages(['effects' => ["The approved correction would make {$key} negative."]]);
            }
        }
    }

    private function baseBalance(string $orderId, string $metric, mixed $subjectId, mixed $uom): string
    {
        $outputs = DB::table('production_output_events')->where('production_order_id', $orderId)
            ->where('quantity_dimension', 'COUNT');
        $value = match ($metric) {
            'GOOD_OUTPUT' => bcadd(
                (string) (clone $outputs)->where('event_type', 'GOOD')->sum('quantity'),
                (string) (clone $outputs)->where('rework_status', 'RECOVERED')->sum('quantity'),
                6,
            ),
            'LOSS_OUTPUT' => bcadd(
                (string) (clone $outputs)->where('event_type', 'LOSS')->sum('quantity'),
                (string) (clone $outputs)->where('rework_status', 'SCRAPPED')->sum('quantity'),
                6,
            ),
            'REWORK_OUTPUT' => (string) (clone $outputs)->where('event_type', 'REWORK')
                ->where('rework_status', 'OPEN')->sum('quantity'),
            'MATERIAL_NET_CONSUMPTION' => (string) DB::table('production_order_materials')->where('id', $subjectId)
                ->value('net_consumed_quantity'),
            'STAGE_MEASUREMENT' => (string) DB::table('production_stage_measurements')
                ->where('production_order_stage_id', $subjectId)->where('uom_code', $uom)->sum('quantity'),
            'COST_VALUE' => (string) DB::table('batch_costs')->where('id', $subjectId)->value('actual_total_cost'),
            default => '0',
        };

        return bcadd($value === '' ? '0' : $value, '0', 6);
    }

    private function order(string $id, array $scope, bool $lock): object
    {
        $query = DB::table('production_orders')->where('id', $id)->where('company_id', $scope['company_id'])
            ->where('plant_id', $scope['plant_id']);
        $order = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $order) {
            throw new NotFoundHttpException('Production order not found.');
        }
        return $order;
    }

    private function signed(mixed $value, string $field): string
    {
        $text = trim((string) $value);
        if (! preg_match('/^-?\d{1,14}(?:\.\d{1,6})?$/', $text)) {
            throw ValidationException::withMessages([$field => ['Enter a signed value with at most 6 decimal places.']]);
        }
        return bcadd($text, '0', 6);
    }

    private function begin(string $namespace, array $data): ?array
    {
        return $this->idempotency->begin($namespace, $data['idempotency_key'], Arr::except($data,
            ['actor_id', 'permissions', 'idempotency_key', 'correlation_id']));
    }

    private function record(string $command, string $event, string $id, array $data, int $version, array $diff, array $result): void
    {
        $this->audit->record($command, 'production_batch_adjustment', $id, $data['actor_id'],
            $data['company_id'], $data['plant_id'], 'SUCCESS', ['entity_version' => $version,
                'correlation_id' => $data['correlation_id'] ?? null, 'safe_diff' => $diff]);
        $this->outbox->append($event, 'production_batch_adjustment', $id, $id.':'.$version,
            $result + ['company_id' => $data['company_id'], 'plant_id' => $data['plant_id']],
            $data['correlation_id'] ?? null, $data['company_id'], $data['plant_id']);
    }
}

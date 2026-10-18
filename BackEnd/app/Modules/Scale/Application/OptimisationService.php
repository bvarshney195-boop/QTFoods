<?php

namespace App\Modules\Scale\Application;

use App\Shared\Audit\AuditService;
use App\Shared\Idempotency\IdempotencyService;
use App\Shared\Outbox\OutboxService;
use App\Shared\Units\UomQuantityService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OptimisationService
{
    public const STATUSES = ['DRAFT', 'GENERATED', 'SUBMITTED', 'APPROVED', 'REJECTED', 'COMPLETED', 'CANCELLED'];
    public const OBJECTIVES = ['BALANCED', 'SERVICE', 'COST', 'INVENTORY'];
    public const OUTCOMES = ['ACHIEVED', 'PARTIAL', 'MISSED'];
    public const ALGORITHM_CODE = 'DETERMINISTIC_NET_REQUIREMENTS';
    public const ALGORITHM_VERSION = '1.0.0';

    public function __construct(
        private readonly IdempotencyService $idempotency,
        private readonly AuditService $audit,
        private readonly OutboxService $outbox,
        private readonly UomQuantityService $uomQuantities,
    ) {}

    public function create(array $data): array
    {
        return DB::transaction(function () use ($data): array {
            $namespace = 'optimisation.plan.create';
            if ($replay = $this->begin($namespace, $data)) {
                return $replay;
            }
            $this->assertUniqueNumber($data['plan_number'], $data);
            $demand = $this->releasedDemand($data['demand_plan_id'], $data, true);
            $id = (string) Str::uuid();
            $now = now();
            DB::table('optimisation_plans')->insert([
                'id' => $id, 'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'plan_number' => $data['plan_number'], 'name' => trim($data['name']),
                'horizon_start' => $demand->horizon_start, 'horizon_end' => $demand->horizon_end,
                'status' => 'DRAFT', 'current_input_version' => 1,
                'current_recommendation_version' => null, 'record_version' => 1,
                'created_by' => $data['actor_id'], 'created_at' => $now, 'updated_at' => $now,
            ]);
            $snapshot = $this->insertInputVersion($id, 1, $demand, $data, $now);
            $result = $this->result($id, 'DRAFT', 1) + $snapshot;
            $this->record('CREATE_OPTIMISATION_PLAN', 'optimisation.plan.created', $id, $data, 1, [
                'plan_number' => $data['plan_number'], 'demand_plan_id' => $demand->id,
                'input_version' => 1, 'input_checksum' => $snapshot['input_checksum'],
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function revise(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'optimisation.plan.revise.'.$id;
            if ($replay = $this->begin($namespace, $data + ['optimisation_plan_id' => $id])) {
                return $replay;
            }
            $plan = $this->plan($id, $data, true);
            $this->assertVersion($plan, $data['expected_version']);
            $this->assertStatus($plan, ['DRAFT', 'GENERATED', 'REJECTED'], 'Only a draft, generated, or rejected plan can receive a new input version.');
            $demand = $this->releasedDemand($data['demand_plan_id'], $data, true);
            $inputVersion = (int) $plan->current_input_version + 1;
            $recordVersion = (int) $plan->record_version + 1;
            $now = now();
            $snapshot = $this->insertInputVersion($id, $inputVersion, $demand, $data, $now);
            DB::table('optimisation_plans')->where('id', $id)->update([
                'horizon_start' => $demand->horizon_start, 'horizon_end' => $demand->horizon_end,
                'status' => 'DRAFT', 'current_input_version' => $inputVersion,
                'current_recommendation_version' => null, 'record_version' => $recordVersion,
                'generated_at' => null, 'generated_by' => null, 'updated_at' => $now,
            ]);
            $result = $this->result($id, 'DRAFT', $recordVersion) + $snapshot;
            $this->record('REVISE_OPTIMISATION_INPUT', 'optimisation.input.revised', $id, $data, $recordVersion, [
                'status' => ['from' => $plan->status, 'to' => 'DRAFT'],
                'input_version' => ['from' => (int) $plan->current_input_version, 'to' => $inputVersion],
                'input_checksum' => $snapshot['input_checksum'],
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function generate(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'optimisation.plan.generate.'.$id;
            if ($replay = $this->begin($namespace, $data + ['optimisation_plan_id' => $id])) {
                return $replay;
            }
            $plan = $this->plan($id, $data, true);
            $this->assertVersion($plan, $data['expected_version']);
            $this->assertStatus($plan, ['DRAFT'], 'Only a draft plan can generate recommendations.');
            $input = $this->currentInput($plan, true);
            $lines = DB::table('optimisation_input_lines')
                ->where('input_version_id', $input->id)
                ->orderBy('demand_date')->orderBy('line_number')->lockForUpdate()->get();
            if ($lines->isEmpty()) {
                throw new ConflictHttpException('The current input snapshot has no demand lines.');
            }

            $source = DB::table('demand_plans')->where('id', $input->demand_plan_id)
                ->where('company_id', $data['company_id'])->where('plant_id', $data['plant_id'])->first();
            $sourceChanged = ! $source || $source->status !== 'RELEASED'
                || (int) $source->record_version !== (int) $input->demand_plan_version_snapshot;
            $now = now();
            $allocated = [];
            $estimatedCost = '0.000000';
            $productionQuantity = '0.000000';
            $warningCount = 0;
            foreach ($lines as $sequence => $line) {
                $key = $line->output_sku_id.'|'.$line->uom_code;
                $alreadyAllocated = $allocated[$key] ?? '0.000000';
                $available = bcsub($this->decimal($line->available_quantity_snapshot), $alreadyAllocated, 6);
                if (bccomp($available, '0', 6) < 0) {
                    $available = '0.000000';
                }
                $available = $this->uomQuantities->roundDown($available, $line->uom_code, 'recommendations.available_quantity');
                $demandQuantity = $this->decimal($line->demand_quantity);
                $safety = $line->demand_type === 'SAFETY_STOCK'
                    ? '0.000000'
                    : bcdiv($this->decimal($input->safety_stock_percent), '100', 6);
                $target = $this->uomQuantities->roundUp(
                    bcmul($demandQuantity, bcadd('1', $safety, 6), 12),
                    $line->uom_code,
                    'recommendations.target_quantity',
                );
                $stock = bccomp($available, $target, 6) >= 0 ? $target : $available;
                $production = bcsub($target, $stock, 6);
                $allocated[$key] = bcadd($alreadyAllocated, $stock, 6);
                $action = bccomp($production, '0', 6) === 0 ? 'STOCK'
                    : (bccomp($stock, '0', 6) > 0 ? 'MIXED' : 'PRODUCE');
                $priority = (int) $line->material_shortage_count > 0 ? 'CRITICAL'
                    : ($line->demand_type === 'FIRM' || bccomp($production, '0', 6) > 0 ? 'HIGH' : 'NORMAL');
                $start = CarbonImmutable::parse((string) $line->demand_date)
                    ->subDays((int) $input->planning_lead_days);
                if ($start->lt(CarbonImmutable::parse((string) $plan->horizon_start))) {
                    $start = CarbonImmutable::parse((string) $plan->horizon_start);
                }
                $unitCost = $this->decimal($line->unit_cost_snapshot);
                $productionCost = bcmul($production, $unitCost, 12);
                $holdingFraction = bcmul(
                    bcdiv($this->decimal($input->holding_cost_rate, 4), '100', 12),
                    bcdiv((string) max(1, (int) $input->planning_lead_days), '365', 12),
                    12,
                );
                $holdingCost = bcmul(bcmul($stock, $unitCost, 12), $holdingFraction, 12);
                $shortageRiskCost = (int) $line->material_shortage_count > 0
                    ? bcmul($production, $this->decimal($input->shortage_penalty_rate), 12)
                    : '0.000000';
                $cost = bcround(bcadd(bcadd($productionCost, $holdingCost, 12), $shortageRiskCost, 12), 6);
                $estimatedCost = bcadd($estimatedCost, $cost, 6);
                $productionQuantity = bcadd($productionQuantity, $production, 6);
                $recommendationId = (string) Str::uuid();
                DB::table('optimisation_recommendations')->insert([
                    'id' => $recommendationId, 'optimisation_plan_id' => $id,
                    'input_version_id' => $input->id, 'input_line_id' => $line->id,
                    'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                    'recommendation_version' => (int) $input->version_number,
                    'line_number' => $sequence + 1, 'action_type' => $action,
                    'target_quantity' => $target, 'stock_allocation_quantity' => $stock,
                    'production_quantity' => $production, 'uom_code' => $line->uom_code,
                    'proposed_start_date' => $start->toDateString(), 'proposed_end_date' => $line->demand_date,
                    'priority' => $priority, 'expected_service_level' => $this->decimal($input->service_level_target, 3),
                    'estimated_cost' => $cost, 'currency' => $input->currency,
                    'rationale' => $this->rationale($action, $line, $target, $stock, $production),
                    'algorithm_code' => self::ALGORITHM_CODE, 'algorithm_version' => self::ALGORITHM_VERSION,
                    'generated_by' => $data['actor_id'], 'generated_at' => $now, 'created_at' => $now,
                ]);
                $warningCount += $this->insertLimitations(
                    $recommendationId, $id, (string) $input->id, $line, $input, $production, $sourceChanged, $data, $now,
                );
            }
            $version = (int) $plan->record_version + 1;
            DB::table('optimisation_plans')->where('id', $id)->update([
                'status' => 'GENERATED', 'current_recommendation_version' => (int) $input->version_number,
                'record_version' => $version, 'generated_at' => $now, 'generated_by' => $data['actor_id'],
                'updated_at' => $now,
            ]);
            $result = $this->result($id, 'GENERATED', $version) + [
                'input_version' => (int) $input->version_number, 'recommendation_count' => $lines->count(),
                'warning_count' => $warningCount, 'production_quantity' => $productionQuantity,
                'estimated_cost' => $estimatedCost,
            ];
            $this->record('GENERATE_OPTIMISATION_RECOMMENDATIONS', 'optimisation.recommendations.generated', $id, $data, $version, [
                'input_version' => (int) $input->version_number, 'recommendation_count' => $lines->count(),
                'algorithm' => self::ALGORITHM_CODE.'@'.self::ALGORITHM_VERSION,
                'source_demand_changed' => $sourceChanged,
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function submit(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'optimisation.plan.submit.'.$id;
            if ($replay = $this->begin($namespace, $data + ['optimisation_plan_id' => $id])) {
                return $replay;
            }
            $plan = $this->plan($id, $data, true);
            $this->assertVersion($plan, $data['expected_version']);
            $this->assertStatus($plan, ['GENERATED'], 'Only a generated plan can be submitted for review.');
            $input = $this->currentInput($plan, true);
            $this->assertSourceCurrent($input, $data);
            if (DB::table('optimisation_recommendation_limitations')
                ->where('optimisation_plan_id', $id)->where('input_version_id', $input->id)
                ->where('severity', 'BLOCKER')->exists()) {
                throw ValidationException::withMessages([
                    'limitations' => ['Resolve every blocking limitation by creating a fresh input version before review.'],
                ]);
            }
            $count = DB::table('optimisation_recommendations')
                ->where('optimisation_plan_id', $id)->where('input_version_id', $input->id)->count();
            if ($count < 1) {
                throw new ConflictHttpException('No current recommendations exist to review.');
            }
            $round = (int) DB::table('optimisation_plan_reviews')
                ->where('optimisation_plan_id', $id)->max('review_round') + 1;
            $now = now();
            DB::table('optimisation_plan_reviews')->insert([
                'id' => (string) Str::uuid(), 'optimisation_plan_id' => $id,
                'input_version_id' => $input->id, 'company_id' => $data['company_id'],
                'plant_id' => $data['plant_id'], 'review_round' => $round, 'status' => 'PENDING',
                'submitted_by' => $data['actor_id'], 'submitted_at' => $now,
                'created_at' => $now, 'updated_at' => $now,
            ]);
            $version = (int) $plan->record_version + 1;
            DB::table('optimisation_plans')->where('id', $id)->update([
                'status' => 'SUBMITTED', 'record_version' => $version, 'updated_at' => $now,
            ]);
            $result = $this->result($id, 'SUBMITTED', $version) + ['review_round' => $round];
            $this->record('SUBMIT_OPTIMISATION_PLAN', 'optimisation.plan.submitted', $id, $data, $version, [
                'status' => ['from' => 'GENERATED', 'to' => 'SUBMITTED'], 'review_round' => $round,
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function decide(string $id, string $decision, string $notes, array $data): array
    {
        return DB::transaction(function () use ($id, $decision, $notes, $data): array {
            $namespace = 'optimisation.plan.'.strtolower($decision).'.'.$id;
            if ($replay = $this->begin($namespace, $data + [
                'optimisation_plan_id' => $id, 'decision' => $decision, 'decision_notes' => $notes,
            ])) {
                return $replay;
            }
            $plan = $this->plan($id, $data, true);
            $this->assertVersion($plan, $data['expected_version']);
            $this->assertStatus($plan, ['SUBMITTED'], 'Only a submitted plan can be approved or rejected.');
            $input = $this->currentInput($plan, true);
            $review = DB::table('optimisation_plan_reviews')
                ->where('optimisation_plan_id', $id)->where('input_version_id', $input->id)
                ->where('status', 'PENDING')->lockForUpdate()->first();
            if (! $review) {
                throw new ConflictHttpException('The current recommendation set has no pending review.');
            }
            if ((string) $review->submitted_by === $data['actor_id']) {
                throw ValidationException::withMessages([
                    'actor' => ['The optimisation submitter cannot decide the same recommendation set.'],
                ]);
            }
            $now = now();
            DB::table('optimisation_plan_reviews')->where('id', $review->id)->update([
                'status' => $decision, 'decided_by' => $data['actor_id'], 'decided_at' => $now,
                'decision_notes' => trim($notes), 'updated_at' => $now,
            ]);
            $version = (int) $plan->record_version + 1;
            DB::table('optimisation_plans')->where('id', $id)->update([
                'status' => $decision, 'record_version' => $version, 'updated_at' => $now,
            ]);
            $result = $this->result($id, $decision, $version) + ['review_round' => (int) $review->review_round];
            $verb = $decision === 'APPROVED' ? 'APPROVE' : 'REJECT';
            $this->record($verb.'_OPTIMISATION_PLAN', 'optimisation.plan.'.strtolower($decision), $id, $data, $version, [
                'status' => ['from' => 'SUBMITTED', 'to' => $decision],
                'review_round' => (int) $review->review_round, 'decision_notes' => trim($notes),
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function recordOutcome(string $id, string $recommendationId, array $data): array
    {
        return DB::transaction(function () use ($id, $recommendationId, $data): array {
            $namespace = 'optimisation.plan.outcome.'.$id.'.'.$recommendationId;
            if ($replay = $this->begin($namespace, $data + [
                'optimisation_plan_id' => $id, 'recommendation_id' => $recommendationId,
            ])) {
                return $replay;
            }
            $plan = $this->plan($id, $data, true);
            $this->assertVersion($plan, $data['expected_version']);
            $this->assertStatus($plan, ['APPROVED'], 'Outcomes can only be recorded against an approved plan.');
            $input = $this->currentInput($plan, true);
            $recommendation = DB::table('optimisation_recommendations')
                ->where('id', $recommendationId)->where('optimisation_plan_id', $id)
                ->where('input_version_id', $input->id)->where('company_id', $data['company_id'])
                ->where('plant_id', $data['plant_id'])->lockForUpdate()->first();
            if (! $recommendation) {
                throw new NotFoundHttpException('Current optimisation recommendation not found.');
            }
            $existing = DB::table('optimisation_outcomes')->where('recommendation_id', $recommendationId)
                ->lockForUpdate()->first();
            $expectedOutcome = $data['expected_outcome_version'] ?? null;
            if ($existing && $expectedOutcome === null) {
                throw ValidationException::withMessages([
                    'expected_outcome_version' => ['The current outcome version is required when replacing an observation.'],
                ]);
            }
            if ($existing && (int) $existing->record_version !== (int) $expectedOutcome) {
                throw new ConflictHttpException(
                    "Outcome version conflict: expected {$expectedOutcome}, current {$existing->record_version}."
                );
            }
            if (! $existing && $expectedOutcome !== null) {
                throw ValidationException::withMessages([
                    'expected_outcome_version' => ['Do not send an outcome version for the first observation.'],
                ]);
            }
            $now = now();
            $values = [
                'result' => $data['result'],
                'actual_stock_quantity' => $this->decimal($data['actual_stock_quantity']),
                'actual_production_quantity' => $this->decimal($data['actual_production_quantity']),
                'actual_service_level' => $this->decimal($data['actual_service_level'], 3),
                'actual_cost' => $this->decimal($data['actual_cost']), 'currency' => $data['currency'],
                'observed_on' => $data['observed_on'], 'notes' => trim($data['notes']), 'updated_at' => $now,
            ];
            if ($existing) {
                $outcomeVersion = (int) $existing->record_version + 1;
                DB::table('optimisation_outcomes')->where('id', $existing->id)->update($values + [
                    'record_version' => $outcomeVersion, 'updated_by' => $data['actor_id'],
                ]);
                $outcomeId = (string) $existing->id;
            } else {
                $outcomeVersion = 1;
                $outcomeId = (string) Str::uuid();
                DB::table('optimisation_outcomes')->insert($values + [
                    'id' => $outcomeId, 'recommendation_id' => $recommendationId,
                    'optimisation_plan_id' => $id, 'input_version_id' => $input->id,
                    'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                    'record_version' => 1, 'recorded_by' => $data['actor_id'], 'recorded_at' => $now,
                    'updated_by' => null, 'created_at' => $now,
                ]);
            }
            $version = (int) $plan->record_version + 1;
            DB::table('optimisation_plans')->where('id', $id)->update([
                'record_version' => $version, 'updated_at' => $now,
            ]);
            $recorded = DB::table('optimisation_outcomes')->where('optimisation_plan_id', $id)
                ->where('input_version_id', $input->id)->count();
            $total = DB::table('optimisation_recommendations')->where('optimisation_plan_id', $id)
                ->where('input_version_id', $input->id)->count();
            $result = $this->result($id, 'APPROVED', $version) + [
                'outcome_id' => $outcomeId, 'outcome_version' => $outcomeVersion,
                'outcomes_recorded' => $recorded, 'recommendation_count' => $total,
            ];
            $this->record($existing ? 'UPDATE_OPTIMISATION_OUTCOME' : 'RECORD_OPTIMISATION_OUTCOME',
                $existing ? 'optimisation.outcome.updated' : 'optimisation.outcome.recorded',
                $id, $data, $version, [
                    'recommendation_id' => $recommendationId, 'result' => $data['result'],
                    'outcome_version' => $outcomeVersion,
                ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function completePlan(string $id, array $data): array
    {
        return DB::transaction(function () use ($id, $data): array {
            $namespace = 'optimisation.plan.complete.'.$id;
            if ($replay = $this->begin($namespace, $data + ['optimisation_plan_id' => $id])) {
                return $replay;
            }
            $plan = $this->plan($id, $data, true);
            $this->assertVersion($plan, $data['expected_version']);
            $this->assertStatus($plan, ['APPROVED'], 'Only an approved plan can be completed.');
            $input = $this->currentInput($plan, true);
            $recommendations = DB::table('optimisation_recommendations')
                ->where('optimisation_plan_id', $id)->where('input_version_id', $input->id)->count();
            $outcomes = DB::table('optimisation_outcomes')
                ->where('optimisation_plan_id', $id)->where('input_version_id', $input->id)->count();
            if ($recommendations < 1 || $outcomes !== $recommendations) {
                throw ValidationException::withMessages([
                    'outcomes' => ['Record an outcome for every current recommendation before completing the plan.'],
                ]);
            }
            $version = (int) $plan->record_version + 1;
            $now = now();
            DB::table('optimisation_plans')->where('id', $id)->update([
                'status' => 'COMPLETED', 'record_version' => $version,
                'completed_at' => $now, 'completed_by' => $data['actor_id'], 'updated_at' => $now,
            ]);
            $result = $this->result($id, 'COMPLETED', $version) + [
                'recommendation_count' => $recommendations, 'outcome_count' => $outcomes,
            ];
            $this->record('COMPLETE_OPTIMISATION_PLAN', 'optimisation.plan.completed', $id, $data, $version, [
                'status' => ['from' => 'APPROVED', 'to' => 'COMPLETED'], 'outcome_count' => $outcomes,
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    public function cancel(string $id, string $reason, array $data): array
    {
        return DB::transaction(function () use ($id, $reason, $data): array {
            $namespace = 'optimisation.plan.cancel.'.$id;
            if ($replay = $this->begin($namespace, $data + ['optimisation_plan_id' => $id, 'reason' => $reason])) {
                return $replay;
            }
            $plan = $this->plan($id, $data, true);
            $this->assertVersion($plan, $data['expected_version']);
            $this->assertStatus($plan, ['DRAFT', 'GENERATED', 'REJECTED'], 'Only a draft, generated, or rejected plan can be cancelled.');
            $version = (int) $plan->record_version + 1;
            $now = now();
            DB::table('optimisation_plans')->where('id', $id)->update([
                'status' => 'CANCELLED', 'record_version' => $version, 'cancelled_at' => $now,
                'cancelled_by' => $data['actor_id'], 'cancellation_reason' => trim($reason), 'updated_at' => $now,
            ]);
            $result = $this->result($id, 'CANCELLED', $version);
            $this->record('CANCEL_OPTIMISATION_PLAN', 'optimisation.plan.cancelled', $id, $data, $version, [
                'status' => ['from' => $plan->status, 'to' => 'CANCELLED'], 'reason' => trim($reason),
            ], $result);
            $this->complete($namespace, $data, $result);

            return $result;
        }, 3);
    }

    private function insertInputVersion(string $planId, int $version, object $demand, array $data, mixed $now): array
    {
        $sourceLines = DB::table('demand_plan_lines as line')
            ->join('items as sku', 'sku.id', '=', 'line.output_sku_id')
            ->where('line.demand_plan_id', $demand->id)
            ->orderBy('line.line_number')
            ->get(['line.*', 'sku.code as sku_code', 'sku.name as sku_name']);
        if ($sourceLines->isEmpty()) {
            throw ValidationException::withMessages(['demand_plan_id' => ['The released demand plan has no lines to snapshot.']]);
        }
        $inputId = (string) Str::uuid();
        $lineRows = [];
        $checksumLines = [];
        foreach ($sourceLines as $line) {
            $stock = $this->availableStock($line, $data);
            $shortages = $this->materialShortages($demand, $line, $data);
            $unitCost = $this->latestUnitCost($line, $data);
            $lineId = (string) Str::uuid();
            $lineRows[] = [
                'id' => $lineId, 'input_version_id' => $inputId, 'optimisation_plan_id' => $planId,
                'demand_plan_id' => $demand->id, 'demand_plan_line_id' => $line->id,
                'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
                'line_number' => (int) $line->line_number, 'output_sku_id' => $line->output_sku_id,
                'demand_date' => $line->demand_date, 'demand_type' => $line->demand_type,
                'demand_quantity' => $this->decimal($line->quantity),
                'available_quantity_snapshot' => $stock['available'],
                'excluded_stock_position_count' => $stock['excluded'],
                'material_shortage_count' => count($shortages),
                'material_shortages_json' => $shortages ? json_encode($shortages, JSON_THROW_ON_ERROR) : null,
                'unit_cost_snapshot' => $unitCost, 'uom_code' => $line->uom_code, 'created_at' => $now,
            ];
            $checksumLines[] = [
                'source_line_id' => (string) $line->id, 'line_number' => (int) $line->line_number,
                'sku_id' => (string) $line->output_sku_id, 'date' => (string) $line->demand_date,
                'type' => (string) $line->demand_type, 'quantity' => $this->decimal($line->quantity),
                'uom' => (string) $line->uom_code, 'available' => $stock['available'],
                'excluded_positions' => $stock['excluded'], 'material_shortages' => $shortages,
                'unit_cost' => $unitCost,
            ];
        }
        $parameters = [
            'demand_plan_id' => (string) $demand->id,
            'demand_plan_version' => (int) $demand->record_version,
            'objective' => $data['objective'],
            'service_level_target' => $this->decimal($data['service_level_target'], 3),
            'safety_stock_percent' => $this->decimal($data['safety_stock_percent'], 3),
            'planning_lead_days' => (int) $data['planning_lead_days'],
            'max_utilisation_percent' => $this->decimal($data['max_utilisation_percent'], 3),
            'holding_cost_rate' => $this->decimal($data['holding_cost_rate'], 4),
            'shortage_penalty_rate' => $this->decimal($data['shortage_penalty_rate']),
            'currency' => $data['currency'], 'assumptions' => $this->nullable($data['assumptions'] ?? null),
        ];
        $checksum = hash('sha256', json_encode([
            'parameters' => $parameters, 'lines' => $checksumLines,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        DB::table('optimisation_input_versions')->insert([
            'id' => $inputId, 'optimisation_plan_id' => $planId,
            'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
            'version_number' => $version, 'demand_plan_id' => $demand->id,
            'demand_plan_version_snapshot' => $demand->record_version,
            ...Arr::only($parameters, [
                'objective', 'service_level_target', 'safety_stock_percent', 'planning_lead_days',
                'max_utilisation_percent', 'holding_cost_rate', 'shortage_penalty_rate', 'currency', 'assumptions',
            ]),
            'input_checksum' => $checksum, 'created_by' => $data['actor_id'], 'created_at' => $now,
        ]);
        DB::table('optimisation_input_lines')->insert($lineRows);

        return ['input_version' => $version, 'input_checksum' => $checksum, 'input_line_count' => count($lineRows)];
    }

    private function availableStock(object $line, array $scope): array
    {
        $all = DB::table('stock_positions')->where('company_id', $scope['company_id'])
            ->where('plant_id', $scope['plant_id'])->where('item_id', $line->output_sku_id)->count();
        $eligible = DB::table('stock_positions as position')
            ->join('inventory_owners as owner', 'owner.id', '=', 'position.inventory_owner_id')
            ->join('inventory_quality_statuses as quality', 'quality.code', '=', 'position.quality_status')
            ->join('lots as lot', 'lot.id', '=', 'position.lot_id')
            ->where('position.company_id', $scope['company_id'])->where('position.plant_id', $scope['plant_id'])
            ->where('position.item_id', $line->output_sku_id)->where('position.uom_code', $line->uom_code)
            ->where('owner.owner_type', 'COMPANY')->where('owner.status', 'ACTIVE')
            ->where('quality.is_reservable', true)->where('lot.status', 'ACTIVE')
            ->where(function ($query) use ($line): void {
                $query->whereNull('lot.expiry_date')->orWhereDate('lot.expiry_date', '>=', $line->demand_date);
            })->get(['position.quantity_base', 'position.reserved_quantity_base']);
        $available = '0.000000';
        foreach ($eligible as $position) {
            $free = bcsub($this->decimal($position->quantity_base), $this->decimal($position->reserved_quantity_base), 6);
            if (bccomp($free, '0', 6) > 0) {
                $available = bcadd($available, $free, 6);
            }
        }

        return ['available' => $available, 'excluded' => max(0, $all - $eligible->count())];
    }

    private function materialShortages(object $demand, object $line, array $scope): array
    {
        $runId = DB::table('mrp_runs')->where('demand_plan_id', $demand->id)
            ->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])
            ->where('status', 'COMPLETED')->orderByDesc('completed_at')->value('id');
        if (! is_string($runId)) {
            return [];
        }

        return DB::table('mrp_material_requirements as requirement')
            ->join('mrp_planned_orders as planned', 'planned.id', '=', 'requirement.mrp_planned_order_id')
            ->join('items as component', 'component.id', '=', 'requirement.component_sku_id')
            ->where('requirement.mrp_run_id', $runId)->where('planned.demand_plan_line_id', $line->id)
            ->where('requirement.shortage_quantity', '>', 0)->orderBy('requirement.line_number')
            ->get(['component.code', 'component.name', 'requirement.shortage_quantity', 'requirement.uom_code'])
            ->map(fn (object $row) => [
                'component_code' => $row->code, 'component_name' => $row->name,
                'quantity' => $this->decimal($row->shortage_quantity), 'uom_code' => $row->uom_code,
            ])->all();
    }

    private function latestUnitCost(object $line, array $scope): string
    {
        $cost = DB::table('batch_costs as cost')
            ->join('production_orders as production', 'production.id', '=', 'cost.production_order_id')
            ->where('cost.company_id', $scope['company_id'])->where('cost.plant_id', $scope['plant_id'])
            ->where('production.output_sku_id', $line->output_sku_id)
            ->where('production.uom_code', $line->uom_code)->where('cost.status', 'FINALIZED')
            ->orderByDesc('cost.calculated_at')->value('cost.cost_per_good_unit');

        return $this->decimal($cost);
    }

    private function insertLimitations(
        string $recommendationId,
        string $planId,
        string $inputId,
        object $line,
        object $input,
        string $production,
        bool $sourceChanged,
        array $scope,
        mixed $now,
    ): int {
        $limitations = [
            ['HEURISTIC_SCOPE', 'INFO', 'This is deterministic net-requirements guidance, not a stochastic forecast or globally optimal solver result.', [
                'algorithm_code' => self::ALGORITHM_CODE, 'algorithm_version' => self::ALGORITHM_VERSION,
            ]],
            ['SERVICE_LEVEL_ASSUMPTION', 'INFO', 'Expected service level is a scenario target, not a guaranteed operational result.', [
                'target_percent' => $this->decimal($input->service_level_target, 3),
            ]],
            ['COST_MODEL_SCOPE', 'INFO', 'Estimated cost combines the latest matching finalized batch unit cost, annualised holding-rate proxy, and configured shortage-risk proxy; it is not a posted financial valuation.', [
                'holding_cost_rate' => $this->decimal($input->holding_cost_rate, 4),
                'shortage_penalty_rate' => $this->decimal($input->shortage_penalty_rate),
                'currency' => $input->currency,
            ]],
        ];
        if (bccomp($production, '0', 6) > 0) {
            $limitations[] = ['CAPACITY_NOT_RESERVED', 'WARNING', 'Suggested production is not a capacity booking; release it through MRP and production scheduling before execution.', [
                'maximum_utilisation_percent' => $this->decimal($input->max_utilisation_percent, 3),
            ]];
        }
        if ((int) $line->material_shortage_count > 0) {
            $limitations[] = ['MATERIAL_SHORTAGE', 'WARNING', 'The latest completed MRP snapshot reported one or more component shortages for this demand line.', [
                'shortages' => $this->json($line->material_shortages_json),
            ]];
        }
        if (bccomp($this->decimal($line->unit_cost_snapshot), '0', 6) === 0) {
            $limitations[] = ['COST_UNAVAILABLE', 'WARNING', 'No finalized batch unit cost in the demand UOM was available; estimated production cost is zero and must not be treated as a quotation.', null];
        }
        if ((int) $line->excluded_stock_position_count > 0) {
            $limitations[] = ['STOCK_EXCLUDED', 'WARNING', 'Some stock positions were excluded because UOM, ownership, quality, lot status, or shelf-life eligibility did not match the demand snapshot.', [
                'excluded_position_count' => (int) $line->excluded_stock_position_count,
            ]];
        }
        if ($sourceChanged) {
            $limitations[] = ['SOURCE_DEMAND_CHANGED', 'BLOCKER', 'The released demand source no longer matches the version captured by this immutable input.', [
                'demand_plan_version_snapshot' => (int) $input->demand_plan_version_snapshot,
            ]];
        }
        foreach ($limitations as [$code, $severity, $description, $evidence]) {
            DB::table('optimisation_recommendation_limitations')->insert([
                'id' => (string) Str::uuid(), 'recommendation_id' => $recommendationId,
                'optimisation_plan_id' => $planId, 'input_version_id' => $inputId,
                'company_id' => $scope['company_id'], 'plant_id' => $scope['plant_id'],
                'limitation_code' => $code, 'severity' => $severity, 'description' => $description,
                'evidence_json' => $evidence === null ? null : json_encode($evidence, JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);
        }

        return count(array_filter($limitations, fn (array $value) => $value[1] !== 'INFO'));
    }

    private function rationale(string $action, object $line, string $target, string $stock, string $production): string
    {
        $prefix = match ($action) {
            'STOCK' => 'Eligible stock covers the complete scenario target.',
            'MIXED' => 'Eligible stock covers part of the scenario target; produce the residual quantity.',
            default => 'No eligible stock remains after time-phased netting; produce the complete scenario target.',
        };

        return sprintf(
            '%s Target %s %s, stock allocation %s, suggested production %s. Review every recorded limitation before approval.',
            $prefix, $target, $line->uom_code, $stock, $production,
        );
    }

    private function releasedDemand(string $id, array $scope, bool $lock = false): object
    {
        $query = DB::table('demand_plans')->where('id', $id)
            ->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id']);
        $demand = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $demand) {
            throw new NotFoundHttpException('Demand plan not found in the selected plant.');
        }
        if ($demand->status !== 'RELEASED') {
            throw ValidationException::withMessages([
                'demand_plan_id' => ['Optimisation inputs must snapshot a released demand plan.'],
            ]);
        }

        return $demand;
    }

    private function assertSourceCurrent(object $input, array $scope): void
    {
        $source = DB::table('demand_plans')->where('id', $input->demand_plan_id)
            ->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])
            ->lockForUpdate()->first();
        if (! $source || $source->status !== 'RELEASED'
            || (int) $source->record_version !== (int) $input->demand_plan_version_snapshot) {
            throw ValidationException::withMessages([
                'demand_plan_id' => ['The source demand plan changed after snapshotting. Create a new input version before review.'],
            ]);
        }
    }

    private function plan(string $id, array $scope, bool $lock = false): object
    {
        $query = DB::table('optimisation_plans')->where('id', $id)
            ->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id']);
        $plan = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $plan) {
            throw new NotFoundHttpException('Optimisation plan not found.');
        }

        return $plan;
    }

    private function currentInput(object $plan, bool $lock = false): object
    {
        $query = DB::table('optimisation_input_versions')
            ->where('optimisation_plan_id', $plan->id)
            ->where('version_number', $plan->current_input_version);
        $input = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $input) {
            throw new ConflictHttpException('The plan current input version is missing.');
        }

        return $input;
    }

    private function assertUniqueNumber(string $number, array $scope): void
    {
        if (DB::table('optimisation_plans')->where('company_id', $scope['company_id'])
            ->where('plant_id', $scope['plant_id'])->where('plan_number', $number)->exists()) {
            throw ValidationException::withMessages(['plan_number' => ['That optimisation plan number already exists in the selected plant.']]);
        }
    }

    private function assertVersion(object $plan, int $expected): void
    {
        if ((int) $plan->record_version !== $expected) {
            throw new ConflictHttpException(
                "Optimisation plan version conflict: expected {$expected}, current {$plan->record_version}."
            );
        }
    }

    private function assertStatus(object $plan, array $allowed, string $message): void
    {
        if (! in_array($plan->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => [$message]]);
        }
    }

    private function result(string $id, string $status, int $version): array
    {
        return ['id' => $id, 'entity_type' => 'optimisation_plan', 'status' => $status, 'record_version' => $version];
    }

    private function begin(string $namespace, array $data): ?array
    {
        return $this->idempotency->begin($namespace, $data['idempotency_key'], Arr::except($data, [
            'actor_id', 'permissions', 'idempotency_key', 'correlation_id',
        ]));
    }

    private function complete(string $namespace, array $data, array $result): void
    {
        $this->idempotency->complete($namespace, $data['idempotency_key'], $result);
    }

    private function record(string $command, string $event, string $id, array $data, int $version, array $diff, array $result): void
    {
        $this->audit->record($command, 'optimisation_plan', $id, $data['actor_id'], $data['company_id'], $data['plant_id'], 'SUCCESS', [
            'entity_version' => $version, 'correlation_id' => $data['correlation_id'] ?? null, 'safe_diff' => $diff,
        ]);
        $this->outbox->append($event, 'optimisation_plan', $id, $id.':'.$version, $result + [
            'company_id' => $data['company_id'], 'plant_id' => $data['plant_id'],
        ], $data['correlation_id'] ?? null, $data['company_id'], $data['plant_id']);
    }

    private function decimal(mixed $value, int $scale = 6): string
    {
        return bcadd((string) ($value ?? 0), '0', $scale);
    }

    private function nullable(mixed $value): ?string
    {
        $value = trim((string) ($value ?? ''));

        return $value === '' ? null : $value;
    }

    private function json(mixed $value): mixed
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
    }
}

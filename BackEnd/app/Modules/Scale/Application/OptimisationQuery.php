<?php

namespace App\Modules\Scale\Application;

use Illuminate\Database\Query\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

final class OptimisationQuery
{
    public const SORTS = ['NEWEST', 'OLDEST', 'NUMBER', 'HORIZON', 'STATUS'];

    public function workspace(array $scope, array $filters, array $permissions): array
    {
        $base = $this->base($scope);
        $query = clone $base;
        if (($filters['q'] ?? null) !== null) {
            $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], trim($filters['q'])).'%';
            $query->where(function (Builder $nested) use ($term): void {
                $nested->where('plan.plan_number', 'like', $term)
                    ->orWhere('plan.name', 'like', $term)
                    ->orWhere('demand.plan_number', 'like', $term)
                    ->orWhere('demand.name', 'like', $term);
            });
        }
        if (($filters['status'] ?? null) !== null) {
            $query->where('plan.status', $filters['status']);
        }
        if (($filters['objective'] ?? null) !== null) {
            $query->where('input.objective', $filters['objective']);
        }
        $this->sort($query, $filters['sort'] ?? 'NEWEST');
        $page = $query->paginate((int) ($filters['per_page'] ?? 25));

        return [
            'data' => collect($page->items())->map(fn (object $row) => $this->payload($row, $permissions))->all(),
            'meta' => $this->meta($page),
            'summary' => [
                'total' => (clone $base)->count(),
                'generated' => (clone $base)->where('plan.status', 'GENERATED')->count(),
                'awaiting_review' => (clone $base)->where('plan.status', 'SUBMITTED')->count(),
                'approved' => (clone $base)->where('plan.status', 'APPROVED')->count(),
                'completed' => (clone $base)->where('plan.status', 'COMPLETED')->count(),
                'warning_limitations' => DB::table('optimisation_recommendation_limitations')
                    ->where('company_id', $scope['company_id'])->where('plant_id', $scope['plant_id'])
                    ->whereIn('severity', ['WARNING', 'BLOCKER'])->count(),
            ],
            'lookups' => [
                'statuses' => OptimisationService::STATUSES,
                'objectives' => OptimisationService::OBJECTIVES,
                'outcomes' => OptimisationService::OUTCOMES,
                'sorts' => self::SORTS,
                'released_demand_plans' => $this->releasedDemandPlans($scope),
            ],
            'allowed_actions' => $this->can($permissions, 'ACTION:OPT-PLAN:CREATE') ? ['CREATE'] : [],
        ];
    }

    public function detail(string $id, array $scope, array $permissions): array
    {
        $row = $this->base($scope)->where('plan.id', $id)->first();
        if (! $row) {
            throw new NotFoundHttpException('Optimisation plan not found.');
        }
        $payload = $this->payload($row, $permissions);
        $payload['input_versions'] = DB::table('optimisation_input_versions as input')
            ->join('demand_plans as demand', 'demand.id', '=', 'input.demand_plan_id')
            ->join('users as creator', 'creator.id', '=', 'input.created_by')
            ->where('input.optimisation_plan_id', $id)->orderByDesc('input.version_number')
            ->get([
                'input.*', 'demand.plan_number as demand_number', 'demand.name as demand_name',
                'demand.status as demand_status', 'demand.record_version as current_demand_version',
                'creator.name as input_creator_name',
            ])->map(fn (object $input) => $this->inputPayload($input))->all();
        $payload['current_input']['lines'] = DB::table('optimisation_input_lines as line')
            ->join('items as sku', 'sku.id', '=', 'line.output_sku_id')
            ->where('line.input_version_id', $row->input_id)->orderBy('line.line_number')
            ->get(['line.*', 'sku.code as sku_code', 'sku.name as sku_name'])
            ->map(fn (object $line) => [
                'id' => (string) $line->id, 'line_number' => (int) $line->line_number,
                'source_demand_line_id' => (string) $line->demand_plan_line_id,
                'output_sku' => ['id' => (string) $line->output_sku_id, 'code' => $line->sku_code, 'name' => $line->sku_name],
                'demand_date' => (string) $line->demand_date, 'demand_type' => $line->demand_type,
                'demand_quantity' => $this->decimal($line->demand_quantity),
                'available_quantity_snapshot' => $this->decimal($line->available_quantity_snapshot),
                'excluded_stock_position_count' => (int) $line->excluded_stock_position_count,
                'material_shortage_count' => (int) $line->material_shortage_count,
                'material_shortages' => $this->json($line->material_shortages_json) ?? [],
                'unit_cost_snapshot' => $this->decimal($line->unit_cost_snapshot), 'uom_code' => $line->uom_code,
            ])->all();
        $payload['recommendations'] = $this->recommendations($id, (string) $row->input_id, $row->status, $permissions);
        $payload['reviews'] = DB::table('optimisation_plan_reviews as review')
            ->join('users as submitter', 'submitter.id', '=', 'review.submitted_by')
            ->leftJoin('users as decider', 'decider.id', '=', 'review.decided_by')
            ->where('review.optimisation_plan_id', $id)->orderByDesc('review.review_round')
            ->get([
                'review.*', 'submitter.name as submitter_name', 'decider.name as decider_name',
            ])->map(fn (object $review) => [
                'id' => (string) $review->id, 'review_round' => (int) $review->review_round,
                'status' => $review->status,
                'submitted_by' => ['id' => (string) $review->submitted_by, 'name' => $review->submitter_name],
                'submitted_at' => (string) $review->submitted_at,
                'decided_by' => $review->decided_by ? ['id' => (string) $review->decided_by, 'name' => $review->decider_name] : null,
                'decided_at' => $review->decided_at ? (string) $review->decided_at : null,
                'decision_notes' => $review->decision_notes,
            ])->all();

        return $payload;
    }

    private function base(array $scope): Builder
    {
        return DB::table('optimisation_plans as plan')
            ->join('optimisation_input_versions as input', function ($join): void {
                $join->on('input.optimisation_plan_id', '=', 'plan.id')
                    ->on('input.version_number', '=', 'plan.current_input_version');
            })
            ->join('demand_plans as demand', 'demand.id', '=', 'input.demand_plan_id')
            ->join('users as creator', 'creator.id', '=', 'plan.created_by')
            ->join('users as input_creator', 'input_creator.id', '=', 'input.created_by')
            ->where('plan.company_id', $scope['company_id'])->where('plan.plant_id', $scope['plant_id'])
            ->select([
                'plan.*', 'input.id as input_id', 'input.objective', 'input.service_level_target',
                'input.safety_stock_percent', 'input.planning_lead_days', 'input.max_utilisation_percent',
                'input.holding_cost_rate', 'input.shortage_penalty_rate', 'input.currency', 'input.assumptions',
                'input.input_checksum', 'input.demand_plan_id', 'input.demand_plan_version_snapshot',
                'input.created_by as input_created_by', 'input.created_at as input_created_at',
                'input_creator.name as input_creator_name', 'demand.plan_number as demand_number',
                'demand.name as demand_name', 'demand.status as demand_status',
                'demand.record_version as current_demand_version', 'creator.name as creator_name',
            ])
            ->selectSub(fn (Builder $value) => $value->from('optimisation_input_lines')
                ->whereColumn('input_version_id', 'input.id')->selectRaw('COUNT(*)'), 'input_line_count')
            ->selectSub(fn (Builder $value) => $value->from('optimisation_recommendations')
                ->whereColumn('input_version_id', 'input.id')->selectRaw('COUNT(*)'), 'recommendation_count')
            ->selectSub(fn (Builder $value) => $value->from('optimisation_recommendation_limitations')
                ->whereColumn('input_version_id', 'input.id')->whereIn('severity', ['WARNING', 'BLOCKER'])
                ->selectRaw('COUNT(*)'), 'warning_count')
            ->selectSub(fn (Builder $value) => $value->from('optimisation_outcomes')
                ->whereColumn('input_version_id', 'input.id')->selectRaw('COUNT(*)'), 'outcome_count')
            ->selectSub(fn (Builder $value) => $value->from('optimisation_recommendations')
                ->whereColumn('input_version_id', 'input.id')->selectRaw('COALESCE(SUM(production_quantity), 0)'), 'production_quantity')
            ->selectSub(fn (Builder $value) => $value->from('optimisation_recommendations')
                ->whereColumn('input_version_id', 'input.id')->selectRaw('COALESCE(SUM(estimated_cost), 0)'), 'estimated_cost');
    }

    private function payload(object $row, array $permissions): array
    {
        $input = $this->inputPayload($row);
        $actions = $this->actions($row, $permissions);

        return [
            'id' => (string) $row->id, 'plan_number' => $row->plan_number, 'name' => $row->name,
            'horizon_start' => (string) $row->horizon_start, 'horizon_end' => (string) $row->horizon_end,
            'status' => $row->status, 'record_version' => (int) $row->record_version,
            'current_input_version' => (int) $row->current_input_version,
            'current_recommendation_version' => $row->current_recommendation_version === null ? null : (int) $row->current_recommendation_version,
            'objective' => $row->objective, 'currency' => $row->currency,
            'input_line_count' => (int) $row->input_line_count,
            'recommendation_count' => (int) $row->recommendation_count,
            'warning_count' => (int) $row->warning_count, 'outcome_count' => (int) $row->outcome_count,
            'production_quantity' => $this->decimal($row->production_quantity),
            'estimated_cost' => $this->decimal($row->estimated_cost),
            'created_by' => ['id' => (string) $row->created_by, 'name' => $row->creator_name],
            'created_at' => (string) $row->created_at,
            'generated_at' => $row->generated_at ? (string) $row->generated_at : null,
            'completed_at' => $row->completed_at ? (string) $row->completed_at : null,
            'cancelled_at' => $row->cancelled_at ? (string) $row->cancelled_at : null,
            'cancellation_reason' => $row->cancellation_reason,
            'current_input' => $input, 'allowed_actions' => $actions,
        ];
    }

    private function inputPayload(object $input): array
    {
        return [
            'id' => (string) ($input->input_id ?? $input->id),
            'version_number' => (int) ($input->version_number ?? $input->current_input_version),
            'demand_plan' => [
                'id' => (string) $input->demand_plan_id, 'number' => $input->demand_number,
                'name' => $input->demand_name, 'version_snapshot' => (int) $input->demand_plan_version_snapshot,
                'current_version' => (int) $input->current_demand_version, 'status' => $input->demand_status,
            ],
            'objective' => $input->objective,
            'service_level_target' => $this->decimal($input->service_level_target, 3),
            'safety_stock_percent' => $this->decimal($input->safety_stock_percent, 3),
            'planning_lead_days' => (int) $input->planning_lead_days,
            'max_utilisation_percent' => $this->decimal($input->max_utilisation_percent, 3),
            'holding_cost_rate' => $this->decimal($input->holding_cost_rate, 4),
            'shortage_penalty_rate' => $this->decimal($input->shortage_penalty_rate),
            'currency' => $input->currency, 'assumptions' => $input->assumptions,
            'input_checksum' => $input->input_checksum,
            'line_count' => isset($input->input_line_count) ? (int) $input->input_line_count
                : DB::table('optimisation_input_lines')->where('input_version_id', $input->id)->count(),
            'created_by' => isset($input->input_creator_name)
                ? ['id' => (string) ($input->input_created_by ?? $input->created_by), 'name' => $input->input_creator_name]
                : null,
            'created_at' => (string) ($input->input_created_at ?? $input->created_at),
        ];
    }

    private function recommendations(string $planId, string $inputId, string $status, array $permissions): array
    {
        return DB::table('optimisation_recommendations as recommendation')
            ->join('optimisation_input_lines as input_line', 'input_line.id', '=', 'recommendation.input_line_id')
            ->join('items as sku', 'sku.id', '=', 'input_line.output_sku_id')
            ->where('recommendation.optimisation_plan_id', $planId)
            ->where('recommendation.input_version_id', $inputId)->orderBy('recommendation.line_number')
            ->get([
                'recommendation.*', 'input_line.output_sku_id', 'input_line.demand_date',
                'input_line.demand_type', 'input_line.demand_quantity', 'sku.code as sku_code', 'sku.name as sku_name',
            ])->map(function (object $row) use ($status, $permissions): array {
                $limitations = DB::table('optimisation_recommendation_limitations')
                    ->where('recommendation_id', $row->id)->orderByRaw("CASE severity WHEN 'BLOCKER' THEN 1 WHEN 'WARNING' THEN 2 ELSE 3 END")
                    ->orderBy('limitation_code')->get()->map(fn (object $limitation) => [
                        'id' => (string) $limitation->id, 'code' => $limitation->limitation_code,
                        'severity' => $limitation->severity, 'description' => $limitation->description,
                        'evidence' => $this->json($limitation->evidence_json),
                    ])->all();
                $outcome = DB::table('optimisation_outcomes as outcome')
                    ->join('users as recorder', 'recorder.id', '=', 'outcome.recorded_by')
                    ->leftJoin('users as updater', 'updater.id', '=', 'outcome.updated_by')
                    ->where('outcome.recommendation_id', $row->id)
                    ->first(['outcome.*', 'recorder.name as recorder_name', 'updater.name as updater_name']);

                return [
                    'id' => (string) $row->id, 'line_number' => (int) $row->line_number,
                    'output_sku' => ['id' => (string) $row->output_sku_id, 'code' => $row->sku_code, 'name' => $row->sku_name],
                    'demand_date' => (string) $row->demand_date, 'demand_type' => $row->demand_type,
                    'demand_quantity' => $this->decimal($row->demand_quantity), 'action_type' => $row->action_type,
                    'target_quantity' => $this->decimal($row->target_quantity),
                    'stock_allocation_quantity' => $this->decimal($row->stock_allocation_quantity),
                    'production_quantity' => $this->decimal($row->production_quantity), 'uom_code' => $row->uom_code,
                    'proposed_start_date' => (string) $row->proposed_start_date,
                    'proposed_end_date' => (string) $row->proposed_end_date, 'priority' => $row->priority,
                    'expected_service_level' => $this->decimal($row->expected_service_level, 3),
                    'estimated_cost' => $this->decimal($row->estimated_cost), 'currency' => $row->currency,
                    'rationale' => $row->rationale,
                    'algorithm' => ['code' => $row->algorithm_code, 'version' => $row->algorithm_version],
                    'generated_at' => (string) $row->generated_at, 'limitations' => $limitations,
                    'outcome' => $outcome ? [
                        'id' => (string) $outcome->id, 'result' => $outcome->result,
                        'actual_stock_quantity' => $this->decimal($outcome->actual_stock_quantity),
                        'actual_production_quantity' => $this->decimal($outcome->actual_production_quantity),
                        'actual_service_level' => $this->decimal($outcome->actual_service_level, 3),
                        'actual_cost' => $this->decimal($outcome->actual_cost), 'currency' => $outcome->currency,
                        'observed_on' => (string) $outcome->observed_on, 'notes' => $outcome->notes,
                        'record_version' => (int) $outcome->record_version,
                        'recorded_by' => ['id' => (string) $outcome->recorded_by, 'name' => $outcome->recorder_name],
                        'recorded_at' => (string) $outcome->recorded_at,
                        'updated_by' => $outcome->updated_by ? ['id' => (string) $outcome->updated_by, 'name' => $outcome->updater_name] : null,
                    ] : null,
                    'allowed_actions' => $status === 'APPROVED' && $this->can($permissions, 'ACTION:OPT-PLAN:OUTCOME')
                        ? ['RECORD_OUTCOME'] : [],
                ];
            })->all();
    }

    private function actions(object $row, array $permissions): array
    {
        $actions = [];
        $add = function (string $action) use (&$actions, $permissions): void {
            if ($this->can($permissions, 'ACTION:OPT-PLAN:'.$action)) {
                $actions[] = $action;
            }
        };
        if (in_array($row->status, ['DRAFT', 'GENERATED', 'REJECTED'], true)) {
            $add('REVISE');
            $add('CANCEL');
        }
        if ($row->status === 'DRAFT') {
            $add('GENERATE');
        } elseif ($row->status === 'GENERATED') {
            $add('SUBMIT');
        } elseif ($row->status === 'SUBMITTED') {
            $add('APPROVE');
            $add('REJECT');
        } elseif ($row->status === 'APPROVED') {
            $add('OUTCOME');
            if ((int) $row->recommendation_count > 0 && (int) $row->recommendation_count === (int) $row->outcome_count) {
                $add('COMPLETE');
            }
        }

        return $actions;
    }

    private function releasedDemandPlans(array $scope): array
    {
        return DB::table('demand_plans as plan')
            ->where('plan.company_id', $scope['company_id'])->where('plan.plant_id', $scope['plant_id'])
            ->where('plan.status', 'RELEASED')->orderByDesc('plan.horizon_start')->orderBy('plan.plan_number')
            ->select(['plan.id', 'plan.plan_number', 'plan.name', 'plan.horizon_start', 'plan.horizon_end', 'plan.record_version'])
            ->selectSub(fn (Builder $line) => $line->from('demand_plan_lines')
                ->whereColumn('demand_plan_id', 'plan.id')->selectRaw('COUNT(*)'), 'line_count')
            ->get()->map(fn (object $plan) => [
                'id' => (string) $plan->id, 'number' => $plan->plan_number, 'name' => $plan->name,
                'horizon_start' => (string) $plan->horizon_start, 'horizon_end' => (string) $plan->horizon_end,
                'record_version' => (int) $plan->record_version, 'line_count' => (int) $plan->line_count,
            ])->all();
    }

    private function sort(Builder $query, string $sort): void
    {
        match ($sort) {
            'OLDEST' => $query->orderBy('plan.created_at')->orderBy('plan.id'),
            'NUMBER' => $query->orderBy('plan.plan_number')->orderBy('plan.id'),
            'HORIZON' => $query->orderBy('plan.horizon_start')->orderBy('plan.plan_number'),
            'STATUS' => $query->orderBy('plan.status')->orderByDesc('plan.created_at'),
            default => $query->orderByDesc('plan.created_at')->orderByDesc('plan.id'),
        };
    }

    private function meta(LengthAwarePaginator $page): array
    {
        return [
            'current_page' => $page->currentPage(), 'last_page' => $page->lastPage(),
            'per_page' => $page->perPage(), 'total' => $page->total(),
        ];
    }

    private function can(array $permissions, string $permission): bool
    {
        return in_array($permission, $permissions, true);
    }

    private function decimal(mixed $value, int $scale = 6): string
    {
        return bcadd((string) ($value ?? 0), '0', $scale);
    }

    private function json(mixed $value): mixed
    {
        return is_string($value) && $value !== ''
            ? json_decode($value, true, 512, JSON_THROW_ON_ERROR)
            : null;
    }
}

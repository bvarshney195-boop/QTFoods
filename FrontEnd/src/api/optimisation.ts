import { apiMutation, apiRequest } from './client';

export type OptimisationActor = { id: string; name: string };
export type DemandPlanLookup = {
  id: string; number: string; name: string; horizon_start: string; horizon_end: string;
  record_version: number; line_count: number;
};
export type InputLine = {
  id: string; line_number: number; source_demand_line_id: string;
  output_sku: { id: string; code: string; name: string };
  demand_date: string; demand_type: string; demand_quantity: string;
  available_quantity_snapshot: string; excluded_stock_position_count: number;
  material_shortage_count: number;
  material_shortages: Array<{ component_code: string; component_name: string; quantity: string; uom_code: string }>;
  unit_cost_snapshot: string; uom_code: string;
};
export type InputVersion = {
  id: string; version_number: number;
  demand_plan: { id: string; number: string; name: string; version_snapshot: number; current_version: number; status: string };
  objective: string; service_level_target: string; safety_stock_percent: string;
  planning_lead_days: number; max_utilisation_percent: string; holding_cost_rate: string;
  shortage_penalty_rate: string; currency: string; assumptions: string | null;
  input_checksum: string; line_count: number; created_by: OptimisationActor | null; created_at: string;
  lines?: InputLine[];
};
export type RecommendationLimitation = {
  id: string; code: string; severity: string; description: string; evidence: Record<string, unknown> | null;
};
export type OptimisationOutcome = {
  id: string; result: string; actual_stock_quantity: string; actual_production_quantity: string;
  actual_service_level: string; actual_cost: string; currency: string; observed_on: string;
  notes: string; record_version: number; recorded_by: OptimisationActor; recorded_at: string;
  updated_by: OptimisationActor | null;
};
export type Recommendation = {
  id: string; line_number: number; output_sku: { id: string; code: string; name: string };
  demand_date: string; demand_type: string; demand_quantity: string; action_type: string;
  target_quantity: string; stock_allocation_quantity: string; production_quantity: string; uom_code: string;
  proposed_start_date: string; proposed_end_date: string; priority: string; expected_service_level: string;
  estimated_cost: string; currency: string; rationale: string;
  algorithm: { code: string; version: string }; generated_at: string;
  limitations: RecommendationLimitation[]; outcome: OptimisationOutcome | null; allowed_actions: string[];
};
export type OptimisationReview = {
  id: string; review_round: number; status: string; submitted_by: OptimisationActor; submitted_at: string;
  decided_by: OptimisationActor | null; decided_at: string | null; decision_notes: string | null;
};
export type OptimisationPlan = {
  id: string; plan_number: string; name: string; horizon_start: string; horizon_end: string;
  status: string; record_version: number; current_input_version: number;
  current_recommendation_version: number | null; objective: string; currency: string;
  input_line_count: number; recommendation_count: number; warning_count: number; outcome_count: number;
  production_quantity: string; estimated_cost: string; created_by: OptimisationActor; created_at: string;
  generated_at: string | null; completed_at: string | null; cancelled_at: string | null;
  cancellation_reason: string | null; current_input: InputVersion; allowed_actions: string[];
  input_versions?: InputVersion[]; recommendations?: Recommendation[]; reviews?: OptimisationReview[];
};
export type OptimisationWorkspace = {
  data: OptimisationPlan[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
  summary: Record<string, number | string>;
  lookups: {
    statuses: string[]; objectives: string[]; outcomes: string[]; sorts: string[];
    released_demand_plans: DemandPlanLookup[];
  };
  allowed_actions: string[];
};
export type OptimisationInputCommand = {
  demand_plan_id: string; objective: string; service_level_target: string;
  safety_stock_percent: string; planning_lead_days: number; max_utilisation_percent: string;
  holding_cost_rate: string; shortage_penalty_rate: string; currency: string; assumptions: string | null;
};
export type OptimisationCommandResult = {
  id: string; entity_type: string; status: string; record_version: number;
  input_version?: number; input_checksum?: string; outcome_id?: string; outcome_version?: number;
};

const path = '/api/v1/optimisation/plans';

export function listOptimisationPlans(filters: Record<string, unknown> = {}) {
  return apiRequest<OptimisationWorkspace>(withQuery(path, filters));
}

export async function getOptimisationPlan(id: string) {
  return (await apiRequest<{ data: OptimisationPlan }>(`${path}/${id}`)).data;
}

export async function createOptimisationPlan(body: OptimisationInputCommand & { plan_number: string; name: string }, key: string) {
  return (await apiMutation<{ data: OptimisationCommandResult }>(path, body, { idempotencyKey: key })).data;
}

export async function reviseOptimisationInput(record: Pick<OptimisationPlan, 'id' | 'record_version'>, body: OptimisationInputCommand, key: string) {
  return (await apiMutation<{ data: OptimisationCommandResult }>(`${path}/${record.id}/inputs`, body, {
    idempotencyKey: key, expectedVersion: record.record_version,
  })).data;
}

export async function runOptimisationCommand(record: Pick<OptimisationPlan, 'id' | 'record_version'>, action: 'generate' | 'submit' | 'complete', key: string) {
  return (await apiMutation<{ data: OptimisationCommandResult }>(`${path}/${record.id}/${action}`, {}, {
    idempotencyKey: key, expectedVersion: record.record_version,
  })).data;
}

export async function decideOptimisation(record: Pick<OptimisationPlan, 'id' | 'record_version'>, action: 'approve' | 'reject', decisionNotes: string, key: string) {
  return (await apiMutation<{ data: OptimisationCommandResult }>(`${path}/${record.id}/${action}`, {
    decision_notes: decisionNotes,
  }, { idempotencyKey: key, expectedVersion: record.record_version })).data;
}

export async function cancelOptimisation(record: Pick<OptimisationPlan, 'id' | 'record_version'>, reason: string, key: string) {
  return (await apiMutation<{ data: OptimisationCommandResult }>(`${path}/${record.id}/cancel`, { reason }, {
    idempotencyKey: key, expectedVersion: record.record_version,
  })).data;
}

export async function saveOptimisationOutcome(
  record: Pick<OptimisationPlan, 'id' | 'record_version'>,
  recommendationId: string,
  body: Record<string, unknown>,
  key: string,
) {
  return (await apiMutation<{ data: OptimisationCommandResult }>(
    `${path}/${record.id}/recommendations/${recommendationId}/outcome`, body,
    { idempotencyKey: key, expectedVersion: record.record_version },
  )).data;
}

function withQuery(base: string, filters: Record<string, unknown>) {
  const search = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') search.set(key, String(value));
  });
  const query = search.toString();
  return query ? `${base}?${query}` : base;
}

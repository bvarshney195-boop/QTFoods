import { apiMutation, apiRequest } from './client';

export type PageMeta = { current_page: number; last_page: number; per_page: number; total: number };
export type Actor = { id: string; name: string };
export type PlanningCommandResult = {
  entity_type: string; id: string; status: string; record_version: number;
  line_count?: number; planned_order_count?: number; material_requirement_count?: number;
  shortage_quantity?: string; work_center_count?: number; overloaded_work_centers?: number;
  reservation_count?: number; released_reservation_count?: number; reserved_quantity?: string;
};
export type OutputSku = { id: string; code: string; name: string; item_type?: string; uom_code?: string; eligible?: boolean; eligibility_issues?: string[] };

export type DemandPlanLine = {
  id: string; line_number: number; output_sku: OutputSku; demand_date: string;
  demand_type: string; quantity: string; uom_code: string; notes: string | null;
};
export type DemandPlan = {
  id: string; plan_number: string; name: string; horizon_start: string; horizon_end: string;
  notes: string | null; status: string; record_version: number; line_count: number;
  active_mrp_count: number; created_by: Actor; released_at: string | null; cancelled_at: string | null;
  cancellation_reason: string | null; allowed_actions: string[]; lines?: DemandPlanLine[];
};
export type DemandWorkspace = {
  data: DemandPlan[]; meta: PageMeta; summary: Record<string, number | string>;
  lookups: { statuses: string[]; demand_types: string[]; sorts: string[]; output_skus: OutputSku[] };
  allowed_actions: string[];
};

export type MaterialRequirement = {
  id: string; line_number?: number; component_sku: OutputSku; gross_requirement: string;
  on_hand_snapshot?: string; reserved_snapshot?: string; available_snapshot?: string;
  shortage_quantity?: string; mrp_shortage_quantity?: string; reserved_quantity?: string; uom_code: string;
};
export type PlannedOrder = {
  id: string; line_number: number; output_sku: OutputSku;
  recipe: { id: string; code: string; name: string; revision: number };
  due_date: string; planned_quantity: string; uom_code: string; materials: MaterialRequirement[];
};
export type MrpRun = {
  id: string; run_number: string; demand_plan: { id: string; number: string; name: string; version: number };
  run_date: string; status: string; record_version: number; planned_order_count: number;
  material_requirement_count: number; shortage_quantity: string; active_schedule_count: number;
  created_by: Actor; completed_at: string; cancelled_at: string | null; cancellation_reason: string | null;
  allowed_actions: string[]; planned_orders?: PlannedOrder[];
};
export type DemandPlanLookup = {
  id: string; number: string; name: string; horizon_start: string; horizon_end: string; record_version: number;
};
export type MrpWorkspace = {
  data: MrpRun[]; meta: PageMeta; summary: Record<string, number | string>;
  lookups: { statuses: string[]; sorts: string[]; released_demand_plans: DemandPlanLookup[] };
  allowed_actions: string[];
};

export type ScheduleOperation = {
  id: string; sequence_no: number; name: string; work_center_code: string;
  setup_minutes: string; run_minutes_per_unit: string; required_minutes: string;
};
export type ScheduleLine = {
  id: string; mrp_planned_order_id: string; line_number: number; output_sku: OutputSku;
  recipe: { id: string; code: string }; route: { id: string; code: string; name: string };
  planned_quantity: string; uom_code: string; planned_start_date: string; planned_end_date: string;
  operations: ScheduleOperation[]; materials: MaterialRequirement[];
};
export type ScheduleCapacity = {
  id: string; work_center_code: string; daily_capacity_minutes: string; working_days: number;
  available_minutes: string; required_minutes: string; utilisation_percent: string; is_overloaded: boolean;
};
export type MaterialReservation = {
  id: string; number: string; status: string; stock_position_id: string; item_code: string;
  lot_code: string; expiry_date: string | null; quantity: string; uom_code: string;
};
export type ProductionSchedule = {
  id: string; schedule_number: string; mrp_run: { id: string; number: string }; demand_plan_number: string;
  horizon_start: string; horizon_end: string; notes: string | null; status: string; record_version: number;
  line_count: number; overloaded_work_centers: number; active_reservation_count: number;
  reserved_quantity: string; created_by: Actor; released_at: string | null; cancelled_at: string | null;
  cancellation_reason: string | null; allowed_actions: string[]; lines?: ScheduleLine[];
  capacities?: ScheduleCapacity[]; reservations?: MaterialReservation[];
};
export type SchedulableOrder = {
  id: string; output_sku: { code: string; name: string }; due_date: string; planned_quantity: string;
  uom_code: string; work_centers: string[];
};
export type SchedulableMrpRun = {
  id: string; number: string; run_date: string; demand_plan_number: string; planned_orders: SchedulableOrder[];
};
export type ScheduleWorkspace = {
  data: ProductionSchedule[]; meta: PageMeta; summary: Record<string, number | string>;
  lookups: { statuses: string[]; sorts: string[]; mrp_runs: SchedulableMrpRun[] };
  allowed_actions: string[];
};

const demandPath = '/api/v1/planning/demand';
const mrpPath = '/api/v1/planning/mrp';
const schedulePath = '/api/v1/planning/schedules';

export const listDemandPlans = (filters: Record<string, unknown> = {}) => apiRequest<DemandWorkspace>(withQuery(demandPath, filters));
export const getDemandPlan = async (id: string) => (await apiRequest<{ data: DemandPlan }>(`${demandPath}/${id}`)).data;
export const createDemandPlan = async (body: unknown, key: string) => (await apiMutation<{ data: PlanningCommandResult }>(demandPath, body, { idempotencyKey: key })).data;
export const updateDemandPlan = async (record: Pick<DemandPlan, 'id' | 'record_version'>, body: unknown, key: string) => (await apiMutation<{ data: PlanningCommandResult }>(`${demandPath}/${record.id}`, body, { idempotencyKey: key, expectedVersion: record.record_version })).data;
export const releaseDemandPlan = async (record: Pick<DemandPlan, 'id' | 'record_version'>, key: string) => (await apiMutation<{ data: PlanningCommandResult }>(`${demandPath}/${record.id}/release`, {}, { idempotencyKey: key, expectedVersion: record.record_version })).data;
export const cancelDemandPlan = async (record: Pick<DemandPlan, 'id' | 'record_version'>, reason: string, key: string) => (await apiMutation<{ data: PlanningCommandResult }>(`${demandPath}/${record.id}/cancel`, { reason }, { idempotencyKey: key, expectedVersion: record.record_version })).data;

export const listMrpRuns = (filters: Record<string, unknown> = {}) => apiRequest<MrpWorkspace>(withQuery(mrpPath, filters));
export const getMrpRun = async (id: string) => (await apiRequest<{ data: MrpRun }>(`${mrpPath}/${id}`)).data;
export const runMrp = async (body: unknown, key: string) => (await apiMutation<{ data: PlanningCommandResult }>(`${mrpPath}/runs`, body, { idempotencyKey: key })).data;
export const cancelMrpRun = async (record: Pick<MrpRun, 'id' | 'record_version'>, reason: string, key: string) => (await apiMutation<{ data: PlanningCommandResult }>(`${mrpPath}/${record.id}/cancel`, { reason }, { idempotencyKey: key, expectedVersion: record.record_version })).data;

export const listProductionSchedules = (filters: Record<string, unknown> = {}) => apiRequest<ScheduleWorkspace>(withQuery(schedulePath, filters));
export const getProductionSchedule = async (id: string) => (await apiRequest<{ data: ProductionSchedule }>(`${schedulePath}/${id}`)).data;
export const createProductionSchedule = async (body: unknown, key: string) => (await apiMutation<{ data: PlanningCommandResult }>(schedulePath, body, { idempotencyKey: key })).data;
export const updateProductionSchedule = async (record: Pick<ProductionSchedule, 'id' | 'record_version'>, body: unknown, key: string) => (await apiMutation<{ data: PlanningCommandResult }>(`${schedulePath}/${record.id}`, body, { idempotencyKey: key, expectedVersion: record.record_version })).data;
export const releaseProductionSchedule = async (record: Pick<ProductionSchedule, 'id' | 'record_version'>, key: string) => (await apiMutation<{ data: PlanningCommandResult }>(`${schedulePath}/${record.id}/release`, {}, { idempotencyKey: key, expectedVersion: record.record_version })).data;
export const cancelProductionSchedule = async (record: Pick<ProductionSchedule, 'id' | 'record_version'>, reason: string, key: string) => (await apiMutation<{ data: PlanningCommandResult }>(`${schedulePath}/${record.id}/cancel`, { reason }, { idempotencyKey: key, expectedVersion: record.record_version })).data;

function withQuery(path: string, filters: Record<string, unknown>) {
  const search = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== null && value !== '') search.set(key, String(value));
  });
  const query = search.toString();
  return query ? `${path}?${query}` : path;
}

import { apiMutation, apiRequest } from './client';

export type PageMeta = { current_page: number; last_page: number; per_page: number; total: number };
export type NamedSku = { id: string; code: string; name: string };
export type CommandResult = {
  entity_type: string; id: string; status: string; record_version?: number;
  order_record_version?: number; output_event_id?: string; finished_lot_id?: string;
  good_quantity?: string; loss_quantity?: string; rework_quantity?: string; open_rework_count?: number;
  issue_count?: number; issued_quantity?: string; genealogy_edge_count?: number; affected_lot_count?: number;
  snapshot_version?: number;
};

export type MaterialIssue = { id: string; lot_id: string; lot_code: string; quantity: string; uom_code: string; movement_id: string; issued_at: string };
export type ProductionMaterial = {
  id: string; line_number: number; component_sku: NamedSku; required_quantity: string;
  issued_quantity: string; uom_code: string; issues: MaterialIssue[];
};
export type ProductionStage = {
  id: string; production_order: { id: string; number: string; batch_number: string; status: string };
  sequence_no: number; operation_name: string; work_center_code: string; planned_minutes: string;
  actual_minutes: string | null; status: string; record_version: number; started_at: string | null;
  completed_at: string | null; notes: string | null; allowed_actions: string[];
};
export type OutputEvent = {
  id: string; sequence_no: number; event_type: string; quantity: string; uom_code: string;
  reason_code: string | null; notes: string | null; rework_status: string; recorded_at: string;
  resolved_at: string | null; resolution_notes: string | null; allowed_actions: string[];
};
export type ProductionOrder = {
  id: string; order_number: string; batch_number: string;
  schedule: { id: string; number: string; line_id: string }; output_sku: NamedSku;
  recipe: { id: string; code: string; revision: number }; route: { id: string; code: string; name: string };
  planned_quantity: string; uom_code: string; planned_start_date: string; planned_end_date: string;
  status: string; quality_status: string; record_version: number; notes: string | null;
  created_by: { id: string; name: string }; released_at: string | null; started_at: string | null;
  completed_at: string | null; quality_released_at: string | null; cancelled_at: string | null;
  cancellation_reason: string | null; allowed_actions: string[]; material_count: number;
  issued_material_count: number; stage_count: number; completed_stage_count: number;
  accounted_quantity: string; good_quantity: string; loss_quantity: string; rework_quantity: string;
  open_rework_count: number; packed_quantity: string; materials?: ProductionMaterial[];
  stages?: ProductionStage[]; outputs?: OutputEvent[];
};
export type ScheduleLineLookup = {
  id: string; schedule_number: string; line_number: number; planned_quantity: string; uom_code: string;
  planned_start_date: string; planned_end_date: string; output_sku: NamedSku;
};
export type ProductionOrderWorkspace = {
  data: ProductionOrder[]; meta: PageMeta; summary: Record<string, number | string>;
  lookups: { statuses: string[]; sorts: string[]; schedule_lines: ScheduleLineLookup[] }; allowed_actions: string[];
};
export type ProductionStageWorkspace = {
  data: ProductionStage[]; meta: PageMeta; summary: Record<string, number | string>;
  lookups: { statuses: string[]; sorts: string[] }; allowed_actions: string[];
};
export type ProductionLossWorkspace = {
  data: ProductionOrder[]; meta: PageMeta; summary: Record<string, number | string>;
  lookups: { statuses: string[]; sorts: string[]; event_types: string[]; rework_dispositions: string[] };
  allowed_actions: string[];
};

export type QualityOrderLookup = {
  id: string; number: string; batch_number: string; record_version: number; status?: string;
  quality_status: string; output_sku?: NamedSku; good_quantity?: string; packed_quantity?: string;
};
export type SpecificationLookup = {
  id: string; code: string; name: string; target_type: string; catalog_item_id: string | null;
  sku_id: string | null; effective_from: string; effective_to: string | null;
};
export type LabResult = {
  id: string; sequence_no: number; code: string; name: string; value_type: string; uom_code: string | null;
  minimum_value: string | null; target_value: string | null; maximum_value: string | null;
  text_requirement: string | null; is_required: boolean; numeric_value: string | null;
  text_value: string | null; boolean_value: boolean | null; result: string; notes: string | null;
};
export type LabSample = {
  id: string; sample_number: string; production_order: { id: string; number: string; batch_number: string; output_sku_id: string };
  specification: { id: string; code: string; name: string; version: number }; status: string;
  record_version: number; notes: string | null; sampled_at: string; completed_at: string | null;
  allowed_actions: string[]; results?: LabResult[]; result_count?: number; failure_count?: number;
};
export type LabWorkspace = {
  data: LabSample[]; meta: PageMeta; summary: Record<string, number | string>;
  lookups: { statuses: string[]; sorts: string[]; production_orders: QualityOrderLookup[]; specifications: SpecificationLookup[] };
  allowed_actions: string[];
};
export type SafetyRecord = {
  kind: 'DEVIATION' | 'HOLD'; id: string; number: string;
  production_order: { id: string; number: string; batch_number: string };
  category: string; description: string; status: string; disposition?: string | null;
  root_cause?: string | null; corrective_action: string | null; record_version: number;
  occurred_at: string; resolved_at: string | null; allowed_actions: string[];
};
export type SafetyWorkspace = {
  data: SafetyRecord[]; meta: PageMeta; summary: Record<string, number | string>;
  lookups: { statuses: string[]; sorts: string[]; hazard_types: string[]; deviation_dispositions: string[]; production_orders: QualityOrderLookup[] };
  allowed_actions: string[];
};

export type Artwork = {
  id: string; artwork_code: string; revision: number; output_sku: NamedSku; label_name: string;
  barcode: string; coding_template: string; effective_from: string; effective_to: string | null;
  status: string; record_version: number; notes: string | null; approved_at: string | null;
  retired_at: string | null; retirement_reason: string | null; allowed_actions: string[];
};
export type ArtworkWorkspace = {
  data: Artwork[]; meta: PageMeta; summary: Record<string, number | string>;
  lookups: { statuses: string[]; sorts: string[]; output_skus: (NamedSku & { barcode?: string })[] }; allowed_actions: string[];
};
export type ArtworkLookup = { id: string; output_sku_id: string; artwork_code: string; revision: number; label_name: string; effective_from: string; effective_to: string | null };
export type LocationLookup = { id: string; code: string; name: string };
export type PackingRun = {
  id: string; run_number: string; production_order: { id: string; number: string; batch_number: string; output_sku_id: string };
  artwork: { id: string; code: string; revision: number }; packed_quantity: string; uom_code: string;
  finished_lot_code: string; manufacture_date: string; expiry_date: string;
  target_location: { id: string; code: string }; coding_value: string; status: string; record_version: number;
  notes: string | null; finished_lot_id: string | null; finished_position_id: string | null;
  stock_movement_id: string | null; completed_at: string | null; cancelled_at: string | null;
  cancellation_reason: string | null; allowed_actions: string[];
  genealogy_edges?: { id: string; input_lot_id: string; input_lot_code: string; input_quantity: string; input_uom_code: string; output_quantity: string; output_uom_code: string }[];
};
export type PackingWorkspace = {
  data: PackingRun[]; meta: PageMeta; summary: Record<string, number | string>;
  lookups: { statuses: string[]; sorts: string[]; production_orders: QualityOrderLookup[]; artworks: ArtworkLookup[]; locations: LocationLookup[] };
  allowed_actions: string[];
};
export type FinishedLot = {
  id: string; lot_code: string; sku: NamedSku;
  production_order: { id: string; number: string; batch_number: string };
  packing_run: { id: string; number: string }; manufacture_date: string; expiry_date: string;
  status: string; record_version: number; position: { id: string; quantity: string; uom_code: string; quality_status: string; location_code: string; location_name: string };
  coding_value: string; inputs?: { lot: { id: string; code: string; item_code: string; item_name: string }; quantity: string; uom_code: string }[];
};
export type FinishedGoodsWorkspace = {
  data: FinishedLot[]; meta: PageMeta; summary: Record<string, number | string>;
  lookups: { statuses: string[]; sorts: string[] }; allowed_actions: string[];
};

export type TraceLot = { id: string; code: string; status: string; item?: NamedSku; item_code?: string; item_name?: string };
export type TraceGraph = {
  lot: TraceLot; nodes: (TraceLot & { depth?: number })[];
  edges: { id: string; input_lot_id: string; output_lot_id: string; input_quantity: string; output_quantity: string; production_order_id: string; packing_run_id: string }[];
  shipments: { id: string; number: string; status: string; dispatched_at: string | null; lot_id: string; shipped_quantity: string; returned_quantity: string; uom_code: string; customer: { id: string; code: string; name: string } | null }[];
};
export type RecallCase = {
  id: string; recall_number: string; source_lot: { id: string; code: string }; classification: string;
  reason: string; status: string; record_version: number; initiated_at: string; closed_at: string | null;
  closure_action: string | null; affected_lot_count: number; allowed_actions: string[];
  lots?: (TraceLot & { relationship: string; depth: number; on_hand_quantity: string; action_status: string })[];
  trace?: TraceGraph;
};
export type RecallWorkspace = {
  data: RecallCase[]; meta: PageMeta; summary: Record<string, number | string>;
  lookups: { statuses: string[]; sorts: string[]; classifications: string[]; lots: TraceLot[] }; allowed_actions: string[];
};
export type CostOrderLookup = { id: string; number: string; batch_number: string; materials: { id: string; item_code: string; item_name: string; required_quantity: string; issued_quantity: string; uom_code: string }[] };
export type BatchCost = {
  id: string; cost_number: string; production_order: { id: string; number: string; batch_number: string; output_sku_id: string };
  snapshot_version: number; currency: string; labour_rate_per_minute: string; overhead_rate_per_minute: string;
  planned_material_cost: string; actual_material_cost: string; planned_conversion_cost: string; actual_conversion_cost: string;
  planned_total_cost: string; actual_total_cost: string; total_variance: string; variance_percent: string;
  good_quantity: string; yield_percent: string; cost_per_good_unit: string; status: string; calculated_at: string;
  materials?: { id: string; item_code: string; item_name: string; planned_quantity: string; actual_quantity: string; uom_code: string; unit_cost: string; planned_cost: string; actual_cost: string; usage_variance: string }[];
  stages?: { id: string; work_center_code: string; planned_minutes: string; actual_minutes: string; planned_cost: string; actual_cost: string; time_variance: string }[];
};
export type BatchCostWorkspace = {
  data: BatchCost[]; meta: PageMeta; summary: Record<string, number | string>;
  lookups: { statuses: string[]; sorts: string[]; production_orders: CostOrderLookup[] }; allowed_actions: string[];
};

const orderPath = '/api/v1/manufacturing/orders';
const stagePath = '/api/v1/manufacturing/stages';
const labPath = '/api/v1/quality/lab-samples';
const safetyPath = '/api/v1/quality/safety';
const artworkPath = '/api/v1/packing/artworks';
const packingPath = '/api/v1/packing/runs';
const tracePath = '/api/v1/trace/cases';
const costPath = '/api/v1/costing/batches';

export const listProductionOrders = (filters: Record<string, unknown> = {}) => apiRequest<ProductionOrderWorkspace>(withQuery(orderPath, filters));
export const getProductionOrder = async (id: string) => (await apiRequest<{ data: ProductionOrder }>(`${orderPath}/${id}`)).data;
export const createProductionOrder = async (body: unknown, key: string) => unwrap(apiMutation<{ data: CommandResult }>(orderPath, body, { idempotencyKey: key }));
export const releaseProductionOrder = (row: Pick<ProductionOrder, 'id' | 'record_version'>, key: string) => command(`${orderPath}/${row.id}/release`, {}, key, row.record_version);
export const issueProductionMaterials = (row: Pick<ProductionOrder, 'id' | 'record_version'>, key: string) => command(`${orderPath}/${row.id}/issue-materials`, {}, key, row.record_version);
export const completeProductionOrder = (row: Pick<ProductionOrder, 'id' | 'record_version'>, key: string) => command(`${orderPath}/${row.id}/complete`, {}, key, row.record_version);
export const cancelProductionOrder = (row: Pick<ProductionOrder, 'id' | 'record_version'>, reason: string, key: string) => command(`${orderPath}/${row.id}/cancel`, { reason }, key, row.record_version);
export const recordProductionOutput = (row: Pick<ProductionOrder, 'id' | 'record_version'>, body: unknown, key: string) => command(`${orderPath}/${row.id}/outputs`, body, key, row.record_version);
export const resolveProductionRework = (id: string, body: unknown, key: string) => command(`/api/v1/manufacturing/output-events/${id}/resolve`, body, key);
export const listProductionLosses = (filters: Record<string, unknown> = {}) => apiRequest<ProductionLossWorkspace>(withQuery('/api/v1/manufacturing/pro-loss', filters));

export const listProductionStages = (filters: Record<string, unknown> = {}) => apiRequest<ProductionStageWorkspace>(withQuery(stagePath, filters));
export const getProductionStage = async (id: string) => (await apiRequest<{ data: ProductionStage }>(`${stagePath}/${id}`)).data;
export const startProductionStage = (row: Pick<ProductionStage, 'id' | 'record_version'>, key: string) => command(`${stagePath}/${row.id}/start`, {}, key, row.record_version);
export const completeProductionStage = (row: Pick<ProductionStage, 'id' | 'record_version'>, body: unknown, key: string) => command(`${stagePath}/${row.id}/complete`, body, key, row.record_version);

export const listLabSamples = (filters: Record<string, unknown> = {}) => apiRequest<LabWorkspace>(withQuery(labPath, filters));
export const getLabSample = async (id: string) => (await apiRequest<{ data: LabSample }>(`${labPath}/${id}`)).data;
export const createLabSample = async (body: unknown, key: string) => unwrap(apiMutation<{ data: CommandResult }>(labPath, body, { idempotencyKey: key }));
export const completeLabSample = (row: Pick<LabSample, 'id' | 'record_version'>, body: unknown, key: string) => command(`${labPath}/${row.id}/complete`, body, key, row.record_version);

export const listSafetyRecords = (filters: Record<string, unknown> = {}) => apiRequest<SafetyWorkspace>(withQuery(safetyPath, filters));
export const getSafetyRecord = async (kind: string, id: string) => (await apiRequest<{ data: SafetyRecord }>(`${safetyPath}/${kind}/${id}`)).data;
export const createFoodSafetyHold = async (order: Pick<QualityOrderLookup, 'record_version'>, body: unknown, key: string) => unwrap(apiMutation<{ data: CommandResult }>('/api/v1/quality/food-safety-holds', body, { idempotencyKey: key, expectedVersion: order.record_version }));
export const releaseFoodSafetyHold = (row: Pick<SafetyRecord, 'id' | 'record_version'>, body: { disposition: string; root_cause: string; corrective_action: string }, key: string) => command(`/api/v1/quality/food-safety-holds/${row.id}/release`, body, key, row.record_version);
export const resolveQualityDeviation = (row: Pick<SafetyRecord, 'id' | 'record_version'>, body: unknown, key: string) => command(`/api/v1/quality/deviations/${row.id}/resolve`, body, key, row.record_version);
export const releaseBatchQuality = (order: Pick<QualityOrderLookup, 'id' | 'record_version'>, key: string) => command(`/api/v1/quality/production-orders/${order.id}/release`, {}, key, order.record_version);

export const listArtworks = (filters: Record<string, unknown> = {}) => apiRequest<ArtworkWorkspace>(withQuery(artworkPath, filters));
export const getArtwork = async (id: string) => (await apiRequest<{ data: Artwork }>(`${artworkPath}/${id}`)).data;
export const createArtwork = async (body: unknown, key: string) => unwrap(apiMutation<{ data: CommandResult }>(artworkPath, body, { idempotencyKey: key }));
export const updateArtwork = (row: Pick<Artwork, 'id' | 'record_version'>, body: unknown, key: string) => command(`${artworkPath}/${row.id}`, body, key, row.record_version);
export const approveArtwork = (row: Pick<Artwork, 'id' | 'record_version'>, key: string) => command(`${artworkPath}/${row.id}/approve`, {}, key, row.record_version);
export const retireArtwork = (row: Pick<Artwork, 'id' | 'record_version'>, reason: string, key: string) => command(`${artworkPath}/${row.id}/retire`, { reason }, key, row.record_version);

export const listPackingRuns = (filters: Record<string, unknown> = {}) => apiRequest<PackingWorkspace>(withQuery(packingPath, filters));
export const getPackingRun = async (id: string) => (await apiRequest<{ data: PackingRun }>(`${packingPath}/${id}`)).data;
export const createPackingRun = async (body: unknown, key: string) => unwrap(apiMutation<{ data: CommandResult }>(packingPath, body, { idempotencyKey: key }));
export const completePackingRun = (row: Pick<PackingRun, 'id' | 'record_version'>, key: string) => command(`${packingPath}/${row.id}/complete`, {}, key, row.record_version);
export const cancelPackingRun = (row: Pick<PackingRun, 'id' | 'record_version'>, reason: string, key: string) => command(`${packingPath}/${row.id}/cancel`, { reason }, key, row.record_version);
export const listFinishedGoods = (filters: Record<string, unknown> = {}) => apiRequest<FinishedGoodsWorkspace>(withQuery('/api/v1/manufacturing/finished-goods', filters));
export const getFinishedLot = async (id: string) => (await apiRequest<{ data: FinishedLot }>(`/api/v1/manufacturing/finished-goods/${id}`)).data;

export const listRecallCases = (filters: Record<string, unknown> = {}) => apiRequest<RecallWorkspace>(withQuery(tracePath, filters));
export const getRecallCase = async (id: string) => (await apiRequest<{ data: RecallCase }>(`${tracePath}/${id}`)).data;
export const getLotTrace = async (id: string) => (await apiRequest<{ data: TraceGraph }>(`/api/v1/trace/lots/${id}`)).data;
export const createRecallCase = async (body: unknown, key: string) => unwrap(apiMutation<{ data: CommandResult }>(tracePath, body, { idempotencyKey: key }));
export const closeRecallCase = (row: Pick<RecallCase, 'id' | 'record_version'>, closure_action: string, key: string) => command(`${tracePath}/${row.id}/close`, { closure_action }, key, row.record_version);

export const listBatchCosts = (filters: Record<string, unknown> = {}) => apiRequest<BatchCostWorkspace>(withQuery(costPath, filters));
export const getBatchCost = async (id: string) => (await apiRequest<{ data: BatchCost }>(`${costPath}/${id}`)).data;
export const calculateBatchCost = async (body: unknown, key: string) => unwrap(apiMutation<{ data: CommandResult }>(costPath, body, { idempotencyKey: key }));

async function command(path: string, body: unknown, key: string, version?: number) {
  return unwrap(apiMutation<{ data: CommandResult }>(path, body, { idempotencyKey: key, expectedVersion: version }));
}
async function unwrap(promise: Promise<{ data: CommandResult }>) { return (await promise).data; }
function withQuery(path: string, filters: Record<string, unknown>) {
  const search = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => { if (value !== undefined && value !== null && value !== '') search.set(key, String(value)); });
  const query = search.toString();
  return query ? `${path}?${query}` : path;
}

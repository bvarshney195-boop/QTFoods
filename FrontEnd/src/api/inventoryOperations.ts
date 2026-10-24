import { apiMutation, apiRequest } from './client';
import type { PageMeta, Reference } from './inventoryFoundation';

export type InventoryOperationResource = 'issues' | 'transfers' | 'counts' | 'expiry-disposals';
export type InventoryOperationType = 'ISSUE' | 'RETURN' | 'TRANSFER' | 'COUNT' | 'ADJUSTMENT' | 'EXPIRY' | 'DISPOSAL';
export type InventoryOperationStatus = 'DRAFT' | 'POSTED' | 'CANCELLED';
export type AdjustmentDirection = 'INCREASE' | 'DECREASE';

export type OperationPosition = {
  id: string;
  label: string;
  sku: Reference;
  lot: { id: string; code: string; status: string; expiry_date: string | null };
  owner: Reference;
  location: Reference;
  quality_status: { code: string; name: string; availability_bucket: 'AVAILABLE' | 'BLOCKED' };
  quantity: { total: string; available: string; reserved: string; uom_code: string };
  record_version: number;
};

export type InventoryMovement = {
  id: string;
  company_id: string;
  plant_id: string | null;
  movement_type: string;
  direction: 'OUTBOUND' | 'INBOUND' | 'TRANSFER';
  source: {
    type: string;
    id: string;
    version: number | null;
    operation_number: string | null;
    operation_type: InventoryOperationType | null;
    operation_status: InventoryOperationStatus | null;
  };
  from_position: OperationPosition | null;
  to_position: OperationPosition | null;
  quantity_base: string;
  uom_code: string;
  reason_code: string | null;
  actor: { id: string; name: string };
  event_at: string;
  posted_at: string;
};

export type InventoryOperationLine = {
  id: string;
  sequence_no: number;
  source_position_id: string | null;
  source_position: OperationPosition | null;
  target_position_id: string | null;
  target_position: OperationPosition | null;
  quantity_base: string | null;
  counted_quantity_base: string | null;
  system_quantity_base: string | null;
  variance_quantity_base: string | null;
  source_position_version: number | null;
  adjustment_direction: AdjustmentDirection | null;
  uom_code: string;
  movement_id: string | null;
  notes: string | null;
};

export type InventoryOperation = {
  id: string;
  company_id: string;
  plant_id: string;
  operation_number: string;
  operation_type: InventoryOperationType;
  status: InventoryOperationStatus;
  reason_code: string;
  notes: string | null;
  record_version: number;
  line_count: number;
  created_by: { id: string; name: string };
  posted_at: string | null;
  posted_by: { id: string; name: string } | null;
  cancelled_at: string | null;
  cancelled_by: { id: string; name: string } | null;
  cancellation_reason: string | null;
  allowed_actions: ('UPDATE' | 'POST' | 'CANCEL')[];
  created_at: string;
  updated_at: string;
  lines?: InventoryOperationLine[];
  movements?: InventoryMovement[];
};

export type InventoryOperationWorkspace = {
  data: InventoryOperation[];
  meta: PageMeta;
  summary: {
    total: number;
    draft: number;
    posted: number;
    cancelled: number;
    by_type: Partial<Record<InventoryOperationType, number>>;
  };
  lookups: {
    operation_types: InventoryOperationType[];
    statuses: InventoryOperationStatus[];
    adjustment_directions: AdjustmentDirection[];
    sorts: string[];
    positions: OperationPosition[];
  };
  allowed_actions: ('CREATE')[];
};

export type InventoryMovementWorkspace = {
  data: InventoryMovement[];
  meta: PageMeta;
  summary: { total: number; outbound: number; inbound: number; transfer: number };
  lookups: { movement_types: string[]; directions: InventoryMovement['direction'][]; sorts: string[] };
  allowed_actions: never[];
};

export type InventoryOperationLineWrite = {
  source_position_id?: string | null;
  target_position_id?: string | null;
  quantity_base?: string | null;
  counted_quantity_base?: string | null;
  adjustment_direction?: AdjustmentDirection | null;
  notes?: string | null;
};

export type InventoryOperationWrite = {
  operation_number?: string;
  operation_type?: InventoryOperationType;
  reason_code: string;
  notes: string | null;
  lines: InventoryOperationLineWrite[];
};

export type InventoryOperationCommandResult = {
  entity_type: 'inventory_operation';
  id: string;
  operation_type: InventoryOperationType;
  status: InventoryOperationStatus;
  record_version: number;
  line_count: number;
  movement_ids?: string[];
  posted_at?: string;
};

function withQuery(path: string, filters: Record<string, unknown>): string {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== '' && value !== false) query.set(key, String(value));
  }
  return `${path}${query.size ? `?${query}` : ''}`;
}

function resourcePath(resource: InventoryOperationResource): string {
  return `/api/v1/inventory/${resource}`;
}

export function listInventoryOperations(
  resource: InventoryOperationResource,
  filters: Record<string, unknown> = {},
): Promise<InventoryOperationWorkspace> {
  return apiRequest<InventoryOperationWorkspace>(withQuery(resourcePath(resource), filters));
}

export async function getInventoryOperation(resource: InventoryOperationResource, operationId: string) {
  return (await apiRequest<{ data: InventoryOperation }>(`${resourcePath(resource)}/${operationId}`)).data;
}

export async function createInventoryOperation(
  resource: InventoryOperationResource,
  body: InventoryOperationWrite & { operation_number: string; operation_type: InventoryOperationType },
  key: string,
) {
  return (await apiMutation<{ data: InventoryOperationCommandResult }>(resourcePath(resource), body, {
    idempotencyKey: key,
  })).data;
}

export async function updateInventoryOperation(
  resource: InventoryOperationResource,
  operation: InventoryOperation,
  body: InventoryOperationWrite,
  key: string,
) {
  return (await apiMutation<{ data: InventoryOperationCommandResult }>(
    `${resourcePath(resource)}/${operation.id}`,
    body,
    { idempotencyKey: key, expectedVersion: operation.record_version },
  )).data;
}

export async function postInventoryOperation(
  resource: InventoryOperationResource,
  operation: InventoryOperation,
  key: string,
) {
  return (await apiMutation<{ data: InventoryOperationCommandResult }>(
    `${resourcePath(resource)}/${operation.id}/post`,
    {},
    { idempotencyKey: key, expectedVersion: operation.record_version },
  )).data;
}

export async function cancelInventoryOperation(
  resource: InventoryOperationResource,
  operation: InventoryOperation,
  reason: string,
  key: string,
) {
  return (await apiMutation<{ data: InventoryOperationCommandResult }>(
    `${resourcePath(resource)}/${operation.id}/cancel`,
    { reason },
    { idempotencyKey: key, expectedVersion: operation.record_version },
  )).data;
}

export function listInventoryMovements(filters: Record<string, unknown> = {}): Promise<InventoryMovementWorkspace> {
  return apiRequest<InventoryMovementWorkspace>(withQuery('/api/v1/inventory/movements', filters));
}

export async function getInventoryMovement(movementId: string): Promise<InventoryMovement> {
  return (await apiRequest<{ data: InventoryMovement }>(`/api/v1/inventory/movements/${movementId}`)).data;
}

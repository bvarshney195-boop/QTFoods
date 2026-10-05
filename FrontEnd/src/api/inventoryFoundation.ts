import { apiMutation, apiRequest } from './client';

export type Reference = { id: string; code: string; name: string; status?: string };
export type PageMeta = { current_page: number; last_page: number; per_page: number; total: number };
export type InventoryCommandResult = {
  entity_type: string;
  id: string;
  status: string;
  record_version: number;
  stock_position_id?: string;
  position_record_version?: number;
  reserved_quantity?: string;
};

export type QualityStatus = {
  code: string;
  name: string;
  availability_bucket: 'AVAILABLE' | 'BLOCKED';
  is_reservable: boolean;
};

export type StockReservation = {
  id: string;
  reservation_number: string;
  quantity_base: string;
  status: 'ACTIVE' | 'RELEASED';
  purpose: string;
  record_version: number;
  created_by: { id: string; name: string };
  released_at: string | null;
  released_by: { id: string; name: string } | null;
  release_reason: string | null;
  allowed_actions: ('RELEASE')[];
  created_at: string;
  updated_at: string;
};

export type StockPosition = {
  id: string;
  company_id: string;
  plant_id: string;
  sku: Reference;
  lot: {
    id: string;
    code: string;
    status: string;
    manufacture_date: string | null;
    expiry_date: string | null;
    is_expired: boolean;
    days_to_expiry: number | null;
  };
  owner: {
    id: string;
    code: string;
    name: string;
    type: 'COMPANY' | 'PARTY';
    status: string;
    party: Reference | null;
  };
  location: Reference;
  quality_status: QualityStatus;
  stock_bucket: 'AVAILABLE' | 'BLOCKED';
  quantity: {
    total: string;
    available: string;
    blocked: string;
    reserved: string;
    uom_code: string;
  };
  record_version: number;
  allowed_actions: ('RESERVE')[];
  reservations?: StockReservation[];
  created_at: string;
  updated_at: string;
};

export type StockWorkspace = {
  data: StockPosition[];
  meta: PageMeta;
  summary: {
    position_count: number;
    sku_count: number;
    lot_count: number;
    total: string;
    available: string;
    blocked: string;
    reserved: string;
    uom_count: number;
    quantities_by_uom: Array<{ uom_code: string; total: string; available: string; blocked: string; reserved: string }>;
    expired_lots: number;
    expiring_30_lots: number;
  };
  lookups: {
    availability_filters: string[];
    sorts: string[];
    quality_statuses: QualityStatus[];
    owners: Reference[];
    skus: Reference[];
    lots: Reference[];
    locations: Reference[];
  };
  allowed_actions: never[];
};

type StatusChange = {
  reason: string | null;
  changed_at: string;
  changed_by: { id: string; name: string } | null;
} | null;

export type QualityTotal = {
  code: string;
  name: string;
  availability_bucket: string;
  total_quantity: string;
  reserved_quantity: string;
};

export type InventoryOwner = {
  id: string;
  company_id: string;
  code: string;
  name: string;
  owner_type: 'COMPANY' | 'PARTY';
  party_id: string | null;
  party: Reference | null;
  status: 'DRAFT' | 'ACTIVE' | 'INACTIVE';
  record_version: number;
  position_count: number;
  total_quantity: string;
  reserved_quantity: string;
  status_change: StatusChange;
  allowed_actions: ('UPDATE' | 'CHANGE_STATUS')[];
  allowed_statuses: ('DRAFT' | 'ACTIVE' | 'INACTIVE')[];
  quality_totals?: QualityTotal[];
  created_at: string;
  updated_at: string;
};

export type OwnerWorkspace = {
  data: InventoryOwner[];
  meta: PageMeta;
  summary: { total: number; draft: number; active: number; inactive: number; party_owned: number };
  lookups: {
    statuses: InventoryOwner['status'][];
    owner_types: InventoryOwner['owner_type'][];
    parties: Reference[];
  };
  allowed_actions: ('CREATE')[];
};

export type InventoryLot = {
  id: string;
  company_id: string;
  internal_lot_code: string;
  supplier_lot_code: string | null;
  item_id: string;
  sku: Reference;
  supplier_party_id: string | null;
  supplier: Reference | null;
  origin_type: 'PURCHASE' | 'PRODUCTION' | 'RETURN' | 'OPENING';
  manufacture_date: string | null;
  expiry_date: string | null;
  is_expired: boolean;
  days_to_expiry: number | null;
  status: 'DRAFT' | 'ACTIVE' | 'CLOSED' | 'RECALLED';
  notes: string | null;
  record_version: number;
  position_count: number;
  total_quantity: string;
  reserved_quantity: string;
  status_change: StatusChange;
  allowed_actions: ('UPDATE' | 'CHANGE_STATUS')[];
  allowed_statuses: ('DRAFT' | 'ACTIVE' | 'CLOSED' | 'RECALLED')[];
  quality_totals?: QualityTotal[];
  created_at: string;
  updated_at: string;
};

export type LotWorkspace = {
  data: InventoryLot[];
  meta: PageMeta;
  summary: {
    total: number;
    draft: number;
    active: number;
    closed: number;
    recalled: number;
    expired: number;
    expiring_30: number;
  };
  lookups: {
    statuses: InventoryLot['status'][];
    origin_types: InventoryLot['origin_type'][];
    skus: Reference[];
    suppliers: Reference[];
  };
  allowed_actions: ('CREATE')[];
};

export type OwnerWrite = {
  code?: string;
  name: string;
  owner_type: InventoryOwner['owner_type'];
  party_id: string | null;
  status?: 'DRAFT' | 'ACTIVE';
};

export type LotWrite = {
  internal_lot_code?: string;
  item_id: string;
  supplier_party_id: string | null;
  supplier_lot_code: string | null;
  origin_type: InventoryLot['origin_type'];
  manufacture_date: string | null;
  expiry_date: string | null;
  notes: string | null;
  status?: 'DRAFT' | 'ACTIVE';
};

function withQuery(path: string, filters: Record<string, unknown>): string {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== '' && value !== false) query.set(key, String(value));
  }
  return `${path}${query.size ? `?${query}` : ''}`;
}

export function listStock(filters: Record<string, unknown> = {}): Promise<StockWorkspace> {
  return apiRequest<StockWorkspace>(withQuery('/api/v1/inventory/stock', filters));
}

export async function getStockPosition(positionId: string): Promise<StockPosition> {
  return (await apiRequest<{ data: StockPosition }>(`/api/v1/inventory/stock/${positionId}`)).data;
}

export function listInventoryOwners(filters: Record<string, unknown> = {}): Promise<OwnerWorkspace> {
  return apiRequest<OwnerWorkspace>(withQuery('/api/v1/inventory/owners', filters));
}

export async function getInventoryOwner(ownerId: string): Promise<InventoryOwner> {
  return (await apiRequest<{ data: InventoryOwner }>(`/api/v1/inventory/owners/${ownerId}`)).data;
}

export function listInventoryLots(filters: Record<string, unknown> = {}): Promise<LotWorkspace> {
  return apiRequest<LotWorkspace>(withQuery('/api/v1/inventory/lots', filters));
}

export async function getInventoryLot(lotId: string): Promise<InventoryLot> {
  return (await apiRequest<{ data: InventoryLot }>(`/api/v1/inventory/lots/${lotId}`)).data;
}

export async function createInventoryOwner(body: OwnerWrite & { code: string; status: 'DRAFT' | 'ACTIVE' }, key: string) {
  return (await apiMutation<{ data: InventoryCommandResult }>('/api/v1/inventory/owners', body, {
    idempotencyKey: key,
  })).data;
}

export async function updateInventoryOwner(owner: InventoryOwner, body: OwnerWrite, key: string) {
  return (await apiMutation<{ data: InventoryCommandResult }>(`/api/v1/inventory/owners/${owner.id}`, body, {
    idempotencyKey: key,
    expectedVersion: owner.record_version,
  })).data;
}

export async function changeInventoryOwnerStatus(
  owner: InventoryOwner,
  targetStatus: InventoryOwner['status'],
  reason: string,
  key: string,
) {
  return (await apiMutation<{ data: InventoryCommandResult }>(`/api/v1/inventory/owners/${owner.id}/status`, {
    target_status: targetStatus,
    reason,
  }, { idempotencyKey: key, expectedVersion: owner.record_version })).data;
}

export async function createInventoryLot(body: LotWrite & { internal_lot_code: string; status: 'DRAFT' | 'ACTIVE' }, key: string) {
  return (await apiMutation<{ data: InventoryCommandResult }>('/api/v1/inventory/lots', body, {
    idempotencyKey: key,
  })).data;
}

export async function updateInventoryLot(lot: InventoryLot, body: LotWrite, key: string) {
  return (await apiMutation<{ data: InventoryCommandResult }>(`/api/v1/inventory/lots/${lot.id}`, body, {
    idempotencyKey: key,
    expectedVersion: lot.record_version,
  })).data;
}

export async function changeInventoryLotStatus(
  lot: InventoryLot,
  targetStatus: InventoryLot['status'],
  reason: string,
  key: string,
) {
  return (await apiMutation<{ data: InventoryCommandResult }>(`/api/v1/inventory/lots/${lot.id}/status`, {
    target_status: targetStatus,
    reason,
  }, { idempotencyKey: key, expectedVersion: lot.record_version })).data;
}

export async function reserveStock(
  position: StockPosition,
  body: { reservation_number: string; quantity_base: string; purpose: string },
  key: string,
) {
  return (await apiMutation<{ data: InventoryCommandResult }>(
    `/api/v1/inventory/stock/${position.id}/reservations`,
    body,
    { idempotencyKey: key, expectedVersion: position.record_version },
  )).data;
}

export async function releaseStockReservation(reservation: StockReservation, reason: string, key: string) {
  return (await apiMutation<{ data: InventoryCommandResult }>(
    `/api/v1/inventory/reservations/${reservation.id}/release`,
    { reason },
    { idempotencyKey: key, expectedVersion: reservation.record_version },
  )).data;
}

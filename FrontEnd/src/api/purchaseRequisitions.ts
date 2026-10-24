import { apiMutation, apiRequest } from './client';

export type PurchaseRequisitionStatus = 'DRAFT' | 'SUBMITTED' | 'APPROVED' | 'REJECTED' | 'CANCELLED';
export type PurchaseRequisitionAction = 'UPDATE' | 'SUBMIT' | 'CANCEL';

export type RequisitionItem = {
  id: string;
  code: string;
  name: string;
  item_type: string;
  uom_code: string;
  status?: string;
};

export type PurchaseRequisitionLine = {
  id: string;
  line_number: number;
  item: RequisitionItem;
  description: string;
  quantity: string;
  uom_code: string;
  estimated_unit_cost: string;
  estimated_line_total: string;
  notes: string | null;
};

export type RequisitionApproval = {
  id: string;
  status: 'PENDING' | 'APPROVED' | 'REJECTED';
  record_version: number;
  rule_name: string;
  band_name: string;
  authority_value: string;
  authority_uom: string;
  required_permission: string;
  due_at: string;
  submission_number: number;
  allowed_actions: ('APPROVE' | 'REJECT')[];
  decision?: {
    decision: 'APPROVE' | 'REJECT';
    reason: string | null;
    authority_source: 'DIRECT' | 'DELEGATION' | 'INTERNAL';
    authority_permission: string;
    reviewer: { id: string; name: string };
    decided_at: string;
  } | null;
};

export type PurchaseRequisition = {
  id: string;
  company_id: string;
  plant_id: string;
  requisition_number: string;
  status: PurchaseRequisitionStatus;
  record_version: number;
  requested_by: { id: string; name: string };
  department: string;
  purpose: string;
  requested_date: string;
  required_by_date: string;
  currency: string;
  estimated_total: string;
  line_count: number;
  approval: RequisitionApproval | null;
  submitted_at: string | null;
  submitted_by: { id: string; name: string } | null;
  approved_at: string | null;
  approved_by: { id: string; name: string } | null;
  rejected_at: string | null;
  rejected_by: { id: string; name: string } | null;
  rejection_reason: string | null;
  cancelled_at: string | null;
  cancelled_by: { id: string; name: string } | null;
  cancellation_reason: string | null;
  allowed_actions: PurchaseRequisitionAction[];
  created_at: string;
  updated_at: string;
  lines?: PurchaseRequisitionLine[];
};

export type PurchaseRequisitionWorkspace = {
  data: PurchaseRequisition[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
  summary: {
    total: number;
    draft: number;
    submitted: number;
    approved: number;
    rejected: number;
    cancelled: number;
    estimated_total: string;
  };
  lookups: {
    statuses: PurchaseRequisitionStatus[];
    sorts: string[];
    currencies: string[];
    items: RequisitionItem[];
  };
  approvals: PurchaseRequisition[];
  allowed_actions: ('CREATE')[];
};

export type PurchaseRequisitionLineWrite = {
  item_id: string;
  quantity: string;
  estimated_unit_cost: string;
  notes: string | null;
};

export type PurchaseRequisitionWrite = {
  requisition_number?: string;
  department: string;
  purpose: string;
  requested_date: string;
  required_by_date: string;
  currency: string;
  lines: PurchaseRequisitionLineWrite[];
};

export type PurchaseRequisitionCommandResult = {
  entity_type: 'purchase_requisition';
  id: string;
  status: PurchaseRequisitionStatus;
  record_version: number;
  line_count: number;
  estimated_total: string;
  approval_request_id: string | null;
  approval_status?: 'APPROVED' | 'REJECTED';
  approval_record_version?: number;
  authority_source?: 'DIRECT' | 'DELEGATION';
};

const basePath = '/api/v1/procurement/requisitions';

export function listPurchaseRequisitions(filters: Record<string, unknown> = {}): Promise<PurchaseRequisitionWorkspace> {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== '') query.set(key, String(value));
  }
  return apiRequest<PurchaseRequisitionWorkspace>(`${basePath}${query.size ? `?${query}` : ''}`);
}

export async function getPurchaseRequisition(requisitionId: string): Promise<PurchaseRequisition> {
  return (await apiRequest<{ data: PurchaseRequisition }>(`${basePath}/${requisitionId}`)).data;
}

export async function createPurchaseRequisition(
  body: PurchaseRequisitionWrite & { requisition_number: string },
  idempotencyKey: string,
): Promise<PurchaseRequisitionCommandResult> {
  return (await apiMutation<{ data: PurchaseRequisitionCommandResult }>(basePath, body, {
    idempotencyKey,
  })).data;
}

export async function updatePurchaseRequisition(
  requisition: Pick<PurchaseRequisition, 'id' | 'record_version'>,
  body: PurchaseRequisitionWrite,
  idempotencyKey: string,
): Promise<PurchaseRequisitionCommandResult> {
  return (await apiMutation<{ data: PurchaseRequisitionCommandResult }>(`${basePath}/${requisition.id}`, body, {
    expectedVersion: requisition.record_version,
    idempotencyKey,
  })).data;
}

export async function submitPurchaseRequisition(
  requisition: Pick<PurchaseRequisition, 'id' | 'record_version'>,
  idempotencyKey: string,
): Promise<PurchaseRequisitionCommandResult> {
  return (await apiMutation<{ data: PurchaseRequisitionCommandResult }>(`${basePath}/${requisition.id}/submit`, {}, {
    expectedVersion: requisition.record_version,
    idempotencyKey,
  })).data;
}

export async function cancelPurchaseRequisition(
  requisition: Pick<PurchaseRequisition, 'id' | 'record_version'>,
  reason: string,
  idempotencyKey: string,
): Promise<PurchaseRequisitionCommandResult> {
  return (await apiMutation<{ data: PurchaseRequisitionCommandResult }>(`${basePath}/${requisition.id}/cancel`, {
    reason,
  }, {
    expectedVersion: requisition.record_version,
    idempotencyKey,
  })).data;
}

export async function decidePurchaseRequisition(
  approval: Pick<RequisitionApproval, 'id' | 'record_version'>,
  decision: 'approve' | 'reject',
  reason: string | null,
  idempotencyKey: string,
): Promise<PurchaseRequisitionCommandResult> {
  return (await apiMutation<{ data: PurchaseRequisitionCommandResult }>(
    `/api/v1/procurement/requisition-approvals/${approval.id}/${decision}`,
    { reason },
    { expectedVersion: approval.record_version, idempotencyKey },
  )).data;
}

import { apiDownload, apiMutation, apiRequest } from './client';

type DataEnvelope<T> = { data: T };

export type LookupReference = {
  id: string;
  code: string | null;
  name: string | null;
};

export type ShipmentLookup = {
  id: string;
  number: string;
  party: LookupReference;
  status: string;
  dispatched_at: string | null;
};

export type ShipmentLineLookup = {
  id: string;
  sku: LookupReference;
  fg_lot: { id: string; code: string | null; expiry_date: string | null } | null;
  shipped_quantity: string;
  returned_quantity: string;
  available_to_return: string;
  uom_code: string;
};

export type SkuLookup = LookupReference & { uom_code: string };

export type LotLookup = {
  id: string;
  sku_id: string;
  code: string;
  manufacture_date: string | null;
  expiry_date: string | null;
};

export type ReturnPositionLookup = {
  id: string;
  sku_id: string;
  lot: { id: string; code: string | null };
  location: LookupReference;
  quality_status: string;
  quantity: string;
  uom_code: string;
};

export type InvoiceLookup = {
  id: string;
  number: string;
  status: string;
  currency: string;
  net_amount: string;
  tax_amount: string;
  gross_amount: string;
  outstanding_amount: string;
  issued_at: string | null;
  record_version: number;
};

export type UnsoldReturnLookups = {
  parties: LookupReference[];
  shipments: ShipmentLookup[];
  invoices: InvoiceLookup[];
  shipment_lines: ShipmentLineLookup[];
  skus: SkuLookup[];
  lots: LotLookup[];
  return_positions: ReturnPositionLookup[];
};

export type UnsoldReturnListItem = {
  id: string;
  party: LookupReference;
  shipment_id: string;
  status: string;
  reason_code: string;
  expected_return_date: string | null;
  maker: LookupReference;
  record_version: number;
  line_count: number;
  quantities: { requested: string; received: string; destroy: string };
  created_at: string;
  updated_at: string;
};

export type UnsoldReturnList = {
  data: UnsoldReturnListItem[];
  meta: {
    current_page: number;
    per_page: number;
    total: number;
    last_page: number;
    from: number | null;
    to: number | null;
  };
  links: {
    first: string;
    last: string;
    prev: string | null;
    next: string | null;
  };
};

export type CreateUnsoldReturnPayload = {
  party_id: string;
  shipment_id: string;
  invoice_id?: string;
  reason_code: string;
  expected_return_date?: string;
  sales_note?: string;
  lines: Array<{
    shipment_line_id: string;
    sku_id: string;
    fg_lot_id?: string;
    requested_quantity: string;
    uom_code: string;
  }>;
};

export type UnsoldReturnCommandResult = {
  return_case_id: string;
  status: string;
  record_version: number;
  stock_movement_ids?: string[];
  destruction_stock_movement_ids?: string[];
  approval_request_id?: string;
  loss_event_id?: string;
  loss_quantity?: string;
};

export type UnsoldReturnLine = {
  id: string;
  shipment_line_id: string | null;
  sku: LookupReference;
  fg_lot: LookupReference | null;
  requested_quantity: string;
  received_quantity: string;
  restock_quantity: string;
  repack_quantity: string;
  rework_quantity: string;
  destroy_quantity: string;
  uom_code: string;
  return_position: {
    id: string;
    location_id: string | null;
    quality_status: string | null;
  } | null;
  quality_reason_code: string | null;
  quality_reviewer_id: string | null;
};

export type UnsoldReturnApproval = {
  id: string;
  entity_version: number;
  record_version: number;
  maker: LookupReference;
  rule_code: string;
  status: string;
  summary: Record<string, unknown> | null;
  created_at: string;
  updated_at: string;
};

export type UnsoldReturnApprovalListItem = {
  id: string;
  entity_type: string;
  entity_version: number;
  record_version: number;
  rule_code: string;
  status: string;
  maker: LookupReference;
  return_case: {
    id: string;
    status: string;
    record_version: number;
    party: LookupReference;
    reason_code: string;
    expected_return_date: string | null;
  };
  destroy_quantity: string;
  authority: {
    rule_id: string | null;
    rule_version: number | null;
    rule_name: string | null;
    band_id: string | null;
    band_name: string | null;
    value: string | null;
    uom: string | null;
    required_permission: string;
    escalation_permission: string | null;
  };
  submission_number: number;
  resubmission_of_id: string | null;
  due_at: string | null;
  escalate_at: string | null;
  escalated_at: string | null;
  escalation_count: number;
  can_decide: boolean;
  created_at: string;
  updated_at: string;
};

export type UnsoldReturnApprovalDetail = UnsoldReturnApprovalListItem & {
  summary: Record<string, unknown> | null;
  lines: Array<{
    id: string;
    sku: LookupReference;
    fg_lot: LookupReference | null;
    received_quantity: string;
    restock_quantity: string;
    repack_quantity: string;
    rework_quantity: string;
    destroy_quantity: string;
    uom_code: string;
    quality_reason_code: string | null;
  }>;
  decisions: Array<{
    id: string;
    reviewer: LookupReference;
    decision: string;
    reason: string | null;
    authority_source: 'DIRECT' | 'DELEGATION' | 'INTERNAL' | null;
    authority_permission: string | null;
    delegation_id: string | null;
    decided_at: string;
  }>;
};

export type UnsoldReturnApprovalList = {
  data: UnsoldReturnApprovalListItem[];
  meta: UnsoldReturnList['meta'];
  links: UnsoldReturnList['links'];
};

export type UnsoldReturnApprovalDecisionResult = {
  approval_request_id: string;
  decision: 'APPROVE' | 'REJECT';
  approval_status: 'APPROVED' | 'REJECTED';
  approval_record_version: number;
  return_case_id: string;
  case_status: string;
  case_record_version: number;
  stock_movement_ids: string[];
  authority_source: 'DIRECT' | 'DELEGATION';
  authority_permission: string;
  delegation_id: string | null;
};

export type UnsoldReturnStockMovement = {
  id: string;
  movement_type: string;
  source_version: number | null;
  quantity: string;
  uom_code: string;
  from_position: {
    id: string;
    quality_status: string | null;
    location: LookupReference | null;
  } | null;
  to_position: {
    id: string;
    quality_status: string | null;
    location: LookupReference | null;
  } | null;
  actor: LookupReference;
  reason_code: string | null;
  event_at: string;
  posted_at: string;
};

export type UnsoldReturnFinanceActionType =
  | 'INVOICE_LINK'
  | 'CREDIT_NOTE'
  | 'TAX_ADJUSTMENT'
  | 'RECEIVABLE_ADJUSTMENT'
  | 'REFUND'
  | 'REPLACEMENT';

export type UnsoldReturnFinanceAction = {
  id: string;
  action_type: UnsoldReturnFinanceActionType;
  case_record_version: number;
  invoice_id: string;
  reference_number: string;
  amount: string | null;
  currency: string;
  tax_code: string | null;
  balance_before: string | null;
  balance_after: string | null;
  notes: string | null;
  actor: LookupReference;
  posted_at: string;
};

export type UnsoldReturnFinanceResult = {
  return_case_id: string;
  finance_action_id: string;
  action_type: UnsoldReturnFinanceActionType;
  invoice_id: string;
  reference_number: string;
  amount?: string;
  currency: string;
  status: string;
  record_version: number;
  tax_code?: string;
  outstanding_amount?: string;
  invoice_record_version?: number;
};

export type UnsoldReturnEvidenceCategory =
  | 'RETURN_CONFIRMATION'
  | 'RECEIPT_PHOTO'
  | 'QUALITY_REPORT'
  | 'FINANCE_DOCUMENT'
  | 'OTHER';

export type UnsoldReturnEvidence = {
  id: string;
  category: UnsoldReturnEvidenceCategory;
  case_record_version: number;
  original_name: string;
  mime_type: string;
  size_bytes: number;
  sha256: string;
  notes: string | null;
  retention_policy: string;
  retention_until: string;
  legal_hold: boolean;
  uploader: LookupReference;
  audit_event_id: string;
  uploaded_at: string;
};

export type UnsoldReturnEvidenceUploadResult = Omit<
  UnsoldReturnEvidence,
  'id' | 'uploader'
> & {
  return_case_id: string;
  evidence_id: string;
  record_version: number;
};

export type UnsoldReturnDetail = {
  id: string;
  company_id: string;
  plant_id: string;
  party: LookupReference;
  sales_order_id: string | null;
  shipment_id: string;
  invoice_id: string | null;
  invoice: InvoiceLookup | null;
  status: string;
  reason_code: string;
  expected_return_date: string | null;
  sales_note: string | null;
  maker: LookupReference;
  received_at: string | null;
  loss_event_id: string | null;
  record_version: number;
  created_at: string;
  updated_at: string;
  lines: UnsoldReturnLine[];
  stock_movements: UnsoldReturnStockMovement[];
  evidence: UnsoldReturnEvidence[];
  finance: {
    stage: 'NOT_READY' | 'NOT_STARTED' | 'INVOICE_LINKED' | 'CREDIT_NOTE_POSTED' | 'TAX_REVIEWED' | 'RESOLVED';
    credit_total: string | null;
    settlement_type: 'RECEIVABLE_ADJUSTMENT' | 'REFUND' | 'REPLACEMENT' | null;
    actions: UnsoldReturnFinanceAction[];
  };
  status_history: Array<{
    id: string;
    from_status: string | null;
    to_status: string;
    record_version: number;
    actor: LookupReference;
    changed_at: string;
  }>;
  approval: UnsoldReturnApproval | null;
};

export async function getUnsoldReturnLookups(filters: {
  party_id?: string;
  shipment_id?: string;
  sku_id?: string;
  lot_id?: string;
  q?: string;
  limit?: number;
} = {}): Promise<UnsoldReturnLookups> {
  const query = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '') query.set(key, String(value));
  });

  const suffix = query.size ? `?${query.toString()}` : '';
  return (await apiRequest<DataEnvelope<UnsoldReturnLookups>>(
    `/api/v1/sales/unsold-returns/lookups${suffix}`
  )).data;
}

export async function listUnsoldReturns(perPage = 10): Promise<UnsoldReturnList> {
  return apiRequest<UnsoldReturnList>(
    `/api/v1/sales/unsold-returns?per_page=${perPage}&sort=-created_at`
  );
}

export async function getUnsoldReturn(caseId: string): Promise<UnsoldReturnDetail> {
  return (await apiRequest<DataEnvelope<UnsoldReturnDetail>>(
    `/api/v1/sales/unsold-returns/${caseId}`
  )).data;
}

export async function uploadUnsoldReturnEvidence(
  caseId: string,
  payload: {
    category: UnsoldReturnEvidenceCategory;
    file: File;
    notes?: string;
  },
  expectedVersion: number,
  idempotencyKey: string
): Promise<UnsoldReturnEvidenceUploadResult> {
  const formData = new FormData();
  formData.set('category', payload.category);
  formData.set('file', payload.file);
  if (payload.notes) formData.set('notes', payload.notes);

  return (await apiMutation<DataEnvelope<UnsoldReturnEvidenceUploadResult>>(
    `/api/v1/sales/unsold-returns/${caseId}/evidence`,
    formData,
    {
      expectedVersion,
      idempotencyKey,
      correlationId: globalThis.crypto.randomUUID(),
    }
  )).data;
}

export function downloadUnsoldReturnEvidence(
  caseId: string,
  evidenceId: string
): Promise<Blob> {
  return apiDownload(
    `/api/v1/sales/unsold-returns/${caseId}/evidence/${evidenceId}`,
    globalThis.crypto.randomUUID()
  );
}

export async function listUnsoldReturnApprovals(filters: {
  status?: 'PENDING' | 'APPROVED' | 'REJECTED';
  q?: string;
  per_page?: number;
} = {}): Promise<UnsoldReturnApprovalList> {
  const query = new URLSearchParams({ sort: '-created_at' });
  Object.entries(filters).forEach(([key, value]) => {
    if (value !== undefined && value !== '') query.set(key, String(value));
  });

  return apiRequest<UnsoldReturnApprovalList>(
    `/api/v1/sales/unsold-return-approvals?${query.toString()}`
  );
}

export async function getUnsoldReturnApproval(
  approvalId: string
): Promise<UnsoldReturnApprovalDetail> {
  return (await apiRequest<DataEnvelope<UnsoldReturnApprovalDetail>>(
    `/api/v1/sales/unsold-return-approvals/${approvalId}`
  )).data;
}

export async function createUnsoldReturn(
  payload: CreateUnsoldReturnPayload,
  idempotencyKey: string
): Promise<UnsoldReturnCommandResult> {
  return (await apiMutation<DataEnvelope<UnsoldReturnCommandResult>>(
    '/api/v1/sales/unsold-returns',
    payload,
    { idempotencyKey }
  )).data;
}

export async function receiveUnsoldReturn(
  caseId: string,
  payload: {
    lines: Array<{
      line_id: string;
      received_quantity: string;
      return_position_id?: string;
    }>;
  },
  expectedVersion: number,
  idempotencyKey: string
): Promise<UnsoldReturnCommandResult> {
  return (await apiMutation<DataEnvelope<UnsoldReturnCommandResult>>(
    `/api/v1/sales/unsold-returns/${caseId}/receive`,
    payload,
    { expectedVersion, idempotencyKey }
  )).data;
}

export async function dispositionUnsoldReturn(
  caseId: string,
  payload: {
    lines: Array<{
      line_id: string;
      restock_quantity: string;
      repack_quantity: string;
      rework_quantity: string;
      destroy_quantity: string;
      quality_reason_code?: string;
    }>;
  },
  expectedVersion: number,
  idempotencyKey: string
): Promise<UnsoldReturnCommandResult> {
  return (await apiMutation<DataEnvelope<UnsoldReturnCommandResult>>(
    `/api/v1/sales/unsold-returns/${caseId}/disposition`,
    payload,
    { expectedVersion, idempotencyKey }
  )).data;
}

export async function postUnsoldReturnLoss(
  caseId: string,
  payload: { uom_code: string; cost_amount?: string; currency: string },
  expectedVersion: number,
  idempotencyKey: string
): Promise<UnsoldReturnCommandResult> {
  return (await apiMutation<DataEnvelope<UnsoldReturnCommandResult>>(
    `/api/v1/sales/unsold-returns/${caseId}/post-loss`,
    payload,
    { expectedVersion, idempotencyKey, correlationId: globalThis.crypto.randomUUID() }
  )).data;
}

export async function linkUnsoldReturnInvoice(
  caseId: string,
  invoiceId: string,
  notes: string | undefined,
  expectedVersion: number,
  idempotencyKey: string
): Promise<UnsoldReturnFinanceResult> {
  return financeMutation(caseId, 'invoice', { invoice_id: invoiceId, notes }, expectedVersion, idempotencyKey);
}

export async function postUnsoldReturnCreditNote(
  caseId: string,
  payload: { document_number: string; amount: string; currency: string; notes?: string },
  expectedVersion: number,
  idempotencyKey: string
): Promise<UnsoldReturnFinanceResult> {
  return financeMutation(caseId, 'credit-note', payload, expectedVersion, idempotencyKey);
}

export async function postUnsoldReturnTaxAdjustment(
  caseId: string,
  payload: { document_number: string; amount: string; currency: string; tax_code: string; notes?: string },
  expectedVersion: number,
  idempotencyKey: string
): Promise<UnsoldReturnFinanceResult> {
  return financeMutation(caseId, 'tax-adjustment', payload, expectedVersion, idempotencyKey);
}

export async function settleUnsoldReturnFinance(
  caseId: string,
  settlement: 'receivable-adjustment' | 'refund' | 'replacement',
  referenceNumber: string,
  notes: string | undefined,
  expectedVersion: number,
  idempotencyKey: string
): Promise<UnsoldReturnFinanceResult> {
  return financeMutation(
    caseId,
    settlement,
    { reference_number: referenceNumber, notes },
    expectedVersion,
    idempotencyKey
  );
}

async function financeMutation(
  caseId: string,
  action: string,
  payload: Record<string, unknown>,
  expectedVersion: number,
  idempotencyKey: string
): Promise<UnsoldReturnFinanceResult> {
  return (await apiMutation<DataEnvelope<UnsoldReturnFinanceResult>>(
    `/api/v1/sales/unsold-returns/${caseId}/finance/${action}`,
    payload,
    {
      expectedVersion,
      idempotencyKey,
      correlationId: globalThis.crypto.randomUUID(),
    }
  )).data;
}

export async function approveUnsoldReturnApproval(
  approvalId: string,
  reason: string | undefined,
  expectedVersion: number,
  idempotencyKey: string
): Promise<UnsoldReturnApprovalDecisionResult> {
  return decideUnsoldReturnApproval(
    approvalId,
    'approve',
    reason,
    expectedVersion,
    idempotencyKey
  );
}

export async function rejectUnsoldReturnApproval(
  approvalId: string,
  reason: string,
  expectedVersion: number,
  idempotencyKey: string
): Promise<UnsoldReturnApprovalDecisionResult> {
  return decideUnsoldReturnApproval(
    approvalId,
    'reject',
    reason,
    expectedVersion,
    idempotencyKey
  );
}

async function decideUnsoldReturnApproval(
  approvalId: string,
  decision: 'approve' | 'reject',
  reason: string | undefined,
  expectedVersion: number,
  idempotencyKey: string
): Promise<UnsoldReturnApprovalDecisionResult> {
  return (await apiMutation<DataEnvelope<UnsoldReturnApprovalDecisionResult>>(
    `/api/v1/sales/unsold-return-approvals/${approvalId}/${decision}`,
    { reason },
    {
      expectedVersion,
      idempotencyKey,
      correlationId: globalThis.crypto.randomUUID(),
    }
  )).data;
}

import { apiMutation, apiRequest } from './client';

export type SourcingActor = { id: string; name: string };
export type SourcingSupplier = {
  id: string;
  code: string;
  name: string;
  currency?: string | null;
  payment_terms_days?: number | null;
  incoterm_code?: string | null;
};

export type RfqStatus = 'DRAFT' | 'ISSUED' | 'AWARDED' | 'CANCELLED';
export type RfqAction = 'UPDATE' | 'ISSUE' | 'RECORD_QUOTE' | 'AWARD' | 'CANCEL' | 'CREATE_PO';
export type RfqLine = {
  id: string;
  requisition_line_id: string;
  line_number: number;
  item: { id: string; code: string; name: string; item_type: string; status: string };
  description: string;
  quantity: string;
  uom_code: string;
  notes: string | null;
};
export type SupplierQuoteLine = {
  id: string;
  rfq_line_id: string;
  line_number: number;
  quantity: string;
  uom_code: string;
  unit_price: string;
  line_total: string;
  notes: string | null;
};
export type SupplierQuote = {
  id: string;
  supplier: SourcingSupplier;
  quote_number: string;
  quote_date: string;
  valid_until: string;
  promised_delivery_date: string;
  payment_terms_days: number;
  currency: string;
  subtotal: string;
  freight_amount: string;
  other_charges: string;
  discount_amount: string;
  total_amount: string;
  status: 'SUBMITTED';
  notes: string | null;
  record_version: number;
  submitted_at: string;
  lines: SupplierQuoteLine[];
};
export type RfqSupplierResponse = {
  supplier: SourcingSupplier;
  status: 'SELECTED' | 'INVITED' | 'RESPONDED' | 'AWARDED' | 'NOT_SELECTED';
  invited_at: string | null;
  responded_at: string | null;
  quote: SupplierQuote | null;
};
export type RfqComparison = {
  rank: number;
  quote_id: string;
  supplier: SourcingSupplier;
  total_amount: string;
  variance_from_lowest: string;
  promised_delivery_date: string;
  meets_required_date: boolean;
  payment_terms_days: number;
  valid_until: string;
};
export type RequestForQuotation = {
  id: string;
  company_id: string;
  plant_id: string;
  rfq_number: string;
  requisition: { id: string; number: string; department: string; purpose: string };
  status: RfqStatus;
  currency: string;
  estimated_total_snapshot: string;
  response_due_date: string;
  required_by_date: string;
  commercial_terms: string | null;
  record_version: number;
  line_count: number;
  supplier_count: number;
  quote_count: number;
  created_by: SourcingActor;
  issued_at: string | null;
  issued_by: SourcingActor | null;
  awarded_at: string | null;
  awarded_by: SourcingActor | null;
  award: {
    supplier: SourcingSupplier;
    quote_id: string;
    total_amount: string;
    reason: string | null;
  } | null;
  cancelled_at: string | null;
  cancelled_by: SourcingActor | null;
  cancellation_reason: string | null;
  has_active_purchase_order: boolean;
  allowed_actions: RfqAction[];
  created_at: string;
  updated_at: string;
  lines?: RfqLine[];
  suppliers?: RfqSupplierResponse[];
  comparison?: RfqComparison[];
};
export type ApprovedRequisitionLookup = {
  id: string;
  number: string;
  department: string;
  purpose: string;
  required_by_date: string;
  currency: string;
  estimated_total: string;
  record_version: number;
};
export type RfqWorkspace = {
  data: RequestForQuotation[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
  summary: { total: number; draft: number; issued: number; awarded: number; cancelled: number; awarded_total: string };
  lookups: {
    statuses: RfqStatus[];
    sorts: string[];
    approved_requisitions: ApprovedRequisitionLookup[];
    suppliers: SourcingSupplier[];
    currency: 'INR';
  };
  allowed_actions: ('CREATE')[];
};
export type RfqWrite = {
  rfq_number?: string;
  requisition_id?: string;
  response_due_date: string;
  commercial_terms: string | null;
  supplier_ids: string[];
};
export type SupplierQuoteWrite = {
  supplier_party_id: string;
  quote_number: string;
  quote_date: string;
  valid_until: string;
  promised_delivery_date: string;
  payment_terms_days: number;
  freight_amount: string;
  other_charges: string;
  discount_amount: string;
  notes: string | null;
  lines: { rfq_line_id: string; unit_price: string; notes: string | null }[];
};
export type RfqCommandResult = {
  entity_type: 'request_for_quotation';
  id: string;
  status: RfqStatus;
  record_version: number;
  line_count: number;
  supplier_count: number;
  quote_count: number;
  quote_id?: string;
  quote_record_version?: number;
  quote_total?: string;
  awarded_supplier_id?: string;
  awarded_quote_id?: string;
  awarded_total?: string;
};

const rfqPath = '/api/v1/procurement/rfqs';

export function listRfqs(filters: Record<string, unknown> = {}): Promise<RfqWorkspace> {
  return apiRequest<RfqWorkspace>(withQuery(rfqPath, filters));
}

export async function getRfq(rfqId: string): Promise<RequestForQuotation> {
  return (await apiRequest<{ data: RequestForQuotation }>(`${rfqPath}/${rfqId}`)).data;
}

export async function createRfq(body: RfqWrite & { rfq_number: string; requisition_id: string }, key: string) {
  return (await apiMutation<{ data: RfqCommandResult }>(rfqPath, body, { idempotencyKey: key })).data;
}

export async function updateRfq(rfq: Pick<RequestForQuotation, 'id' | 'record_version'>, body: RfqWrite, key: string) {
  return (await apiMutation<{ data: RfqCommandResult }>(`${rfqPath}/${rfq.id}`, body, {
    idempotencyKey: key,
    expectedVersion: rfq.record_version,
  })).data;
}

export async function issueRfq(rfq: Pick<RequestForQuotation, 'id' | 'record_version'>, key: string) {
  return (await apiMutation<{ data: RfqCommandResult }>(`${rfqPath}/${rfq.id}/issue`, {}, {
    idempotencyKey: key,
    expectedVersion: rfq.record_version,
  })).data;
}

export async function recordSupplierQuote(
  rfq: Pick<RequestForQuotation, 'id' | 'record_version'>,
  body: SupplierQuoteWrite,
  key: string,
) {
  return (await apiMutation<{ data: RfqCommandResult }>(`${rfqPath}/${rfq.id}/quotes`, body, {
    idempotencyKey: key,
    expectedVersion: rfq.record_version,
  })).data;
}

export async function awardRfq(
  rfq: Pick<RequestForQuotation, 'id' | 'record_version'>,
  quoteId: string,
  awardReason: string | null,
  key: string,
) {
  return (await apiMutation<{ data: RfqCommandResult }>(`${rfqPath}/${rfq.id}/award`, {
    supplier_quote_id: quoteId,
    award_reason: awardReason,
  }, { idempotencyKey: key, expectedVersion: rfq.record_version })).data;
}

export async function cancelRfq(
  rfq: Pick<RequestForQuotation, 'id' | 'record_version'>,
  reason: string,
  key: string,
) {
  return (await apiMutation<{ data: RfqCommandResult }>(`${rfqPath}/${rfq.id}/cancel`, { reason }, {
    idempotencyKey: key,
    expectedVersion: rfq.record_version,
  })).data;
}

export type PurchaseOrderStatus = 'DRAFT' | 'ISSUED' | 'CANCELLED';
export type PurchaseOrderAction = 'AMEND' | 'ISSUE' | 'CANCEL';
export type PurchaseOrderLine = {
  id: string;
  supplier_quote_line_id: string;
  rfq_line_id: string;
  requisition_line_id: string;
  line_number: number;
  item: { id: string; code: string; name: string; item_type: string; status: string };
  description: string;
  ordered_quantity: string;
  uom_code: string;
  unit_price: string;
  line_total: string;
  notes: string | null;
};
export type PurchaseOrderRevision = {
  id: string;
  revision_number: number;
  reason: string;
  snapshot: Record<string, unknown>;
  created_by: SourcingActor;
  created_at: string;
};
export type PurchaseOrder = {
  id: string;
  company_id: string;
  plant_id: string;
  po_number: string;
  rfq: { id: string; number: string; authority_ceiling: string };
  requisition: { id: string; number: string; department: string; purpose: string };
  supplier: SourcingSupplier;
  supplier_quote_id: string;
  order_date: string;
  required_by_date: string;
  currency: string;
  subtotal: string;
  freight_amount: string;
  other_charges: string;
  discount_amount: string;
  total_amount: string;
  payment_terms_days: number;
  incoterm_code: string | null;
  delivery_terms: string | null;
  notes: string | null;
  status: PurchaseOrderStatus;
  record_version: number;
  revision_number: number;
  line_count: number;
  created_by: SourcingActor;
  issued_at: string | null;
  issued_by: SourcingActor | null;
  cancelled_at: string | null;
  cancelled_by: SourcingActor | null;
  cancellation_reason: string | null;
  visibility?: import('./p2Operations').VisibilityMetadata;
  allowed_actions: PurchaseOrderAction[];
  created_at: string;
  updated_at: string;
  lines?: PurchaseOrderLine[];
  revisions?: PurchaseOrderRevision[];
};
export type AwardedRfqLookup = {
  id: string;
  number: string;
  requisition_id: string;
  requisition_number: string;
  supplier: SourcingSupplier;
  quote_id: string;
  total_amount: string;
  authority_ceiling: string;
  promised_delivery_date: string;
  payment_terms_days: number;
};
export type PurchaseOrderWorkspace = {
  data: PurchaseOrder[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
  summary: { total: number; draft: number; issued: number; cancelled: number; committed_total: string };
  lookups: { statuses: PurchaseOrderStatus[]; sorts: string[]; awarded_rfqs: AwardedRfqLookup[]; currency: 'INR'; visibility_filters?: ('ALL' | 'NORMAL' | 'RESTRICTED')[] };
  operational_view?: { label: string; includes_restricted_entries: boolean; permission_filtered: boolean };
  allowed_actions: ('CREATE')[];
};
export type PurchaseOrderCreate = {
  po_number: string;
  rfq_id: string;
  order_date: string;
  incoterm_code: string | null;
  delivery_terms: string | null;
  notes: string | null;
};
export type PurchaseOrderAmendment = {
  reason: string;
  required_by_date: string;
  payment_terms_days: number;
  freight_amount: string;
  other_charges: string;
  discount_amount: string;
  incoterm_code: string | null;
  delivery_terms: string | null;
  notes: string | null;
  lines: { rfq_line_id: string; ordered_quantity: string; unit_price: string; notes: string | null }[];
};
export type PurchaseOrderCommandResult = {
  entity_type: 'purchase_order';
  id: string;
  status: PurchaseOrderStatus;
  record_version: number;
  revision_number: number;
  line_count: number;
  total_amount: string;
};

const orderPath = '/api/v1/procurement/purchase-orders';

export function listPurchaseOrders(filters: Record<string, unknown> = {}): Promise<PurchaseOrderWorkspace> {
  return apiRequest<PurchaseOrderWorkspace>(withQuery(orderPath, filters));
}

export async function getPurchaseOrder(id: string): Promise<PurchaseOrder> {
  return (await apiRequest<{ data: PurchaseOrder }>(`${orderPath}/${id}`)).data;
}

export async function createPurchaseOrder(body: PurchaseOrderCreate, key: string) {
  return (await apiMutation<{ data: PurchaseOrderCommandResult }>(orderPath, body, {
    idempotencyKey: key,
  })).data;
}

export async function amendPurchaseOrder(
  order: Pick<PurchaseOrder, 'id' | 'record_version'>,
  body: PurchaseOrderAmendment,
  key: string,
) {
  return (await apiMutation<{ data: PurchaseOrderCommandResult }>(`${orderPath}/${order.id}/amend`, body, {
    idempotencyKey: key,
    expectedVersion: order.record_version,
  })).data;
}

export async function issuePurchaseOrder(order: Pick<PurchaseOrder, 'id' | 'record_version'>, key: string) {
  return (await apiMutation<{ data: PurchaseOrderCommandResult }>(`${orderPath}/${order.id}/issue`, {}, {
    idempotencyKey: key,
    expectedVersion: order.record_version,
  })).data;
}

export async function cancelPurchaseOrder(
  order: Pick<PurchaseOrder, 'id' | 'record_version'>,
  reason: string,
  key: string,
) {
  return (await apiMutation<{ data: PurchaseOrderCommandResult }>(`${orderPath}/${order.id}/cancel`, { reason }, {
    idempotencyKey: key,
    expectedVersion: order.record_version,
  })).data;
}

function withQuery(path: string, filters: Record<string, unknown>): string {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== '') query.set(key, String(value));
  }
  return `${path}${query.size ? `?${query}` : ''}`;
}

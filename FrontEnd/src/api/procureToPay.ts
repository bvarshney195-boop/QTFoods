import { apiMutation, apiRequest } from './client';

export type Actor = { id: string; name: string };
export type Supplier = { id: string; code: string; name: string };
export type Item = { id: string; code: string; name: string };
export type PageMeta = { current_page: number; last_page: number; per_page: number; total: number };
export type CommandResult = { id: string; status: string; record_version: number; [key: string]: unknown };

export type GateEntry = {
  id: string; gate_entry_number: string; purchase_order: { id: string; number: string }; supplier: Supplier;
  vehicle_number: string; transporter_name: string | null; supplier_document_number: string | null;
  arrived_at: string; notes: string | null; status: string; record_version: number; created_by: Actor;
  cleared_at: string | null; cancelled_at: string | null; cancellation_reason: string | null;
  allowed_actions: string[]; receipt?: { id: string; number: string; status: string } | null;
};
export type IssuedOrderLookup = { id: string; number: string; required_by_date: string; supplier: Supplier };
export type GateWorkspace = { data: GateEntry[]; meta: PageMeta; summary: Record<string, number>; lookups: { statuses: string[]; issued_orders: IssuedOrderLookup[] }; allowed_actions: string[] };

export type ReceiptLine = {
  id: string; purchase_order_line_id: string; line_number: number; item: Item; description: string;
  ordered_quantity: string; received_quantity: string; accepted_quantity: string; rejected_quantity: string; uom_code: string;
  internal_lot_code: string; supplier_lot_code: string | null; manufacture_date: string | null; expiry_date: string | null;
  quality_hold_location: { id: string; code: string }; released_location: { id: string; code: string };
  lot_id: string | null; quality_hold_position_id: string | null; stock_receipt_movement_id: string | null; notes: string | null;
};
export type GoodsReceipt = {
  id: string; receipt_number: string; gate_entry: { id: string; number: string };
  purchase_order: { id: string; number: string }; supplier: Supplier; receipt_date: string;
  supplier_document_number: string | null; notes: string | null; status: string; record_version: number;
  line_count: number; received_total: string; created_by: Actor; posted_at: string | null; cancelled_at: string | null;
  cancellation_reason: string | null; allowed_actions: string[]; lines?: ReceiptLine[];
  quality_task?: { id: string; task_number: string; status: string; record_version: number } | null;
};
export type LocationLookup = { id: string; code: string; name: string; location_type: string };
export type OpenOrderLineLookup = { id: string; line_number: number; item: Item; description: string; ordered_quantity: string; received_quantity: string; remaining_quantity: string; uom_code: string };
export type ArrivedGateLookup = { id: string; number: string; purchase_order: { id: string; number: string }; supplier: Supplier; supplier_document_number: string | null; lines: OpenOrderLineLookup[] };
export type ReceiptWorkspace = { data: GoodsReceipt[]; meta: PageMeta; summary: Record<string, number>; lookups: { statuses: string[]; arrived_gate_entries: ArrivedGateLookup[]; quality_hold_locations: LocationLookup[]; released_locations: LocationLookup[] }; allowed_actions: string[] };

export type QualityLine = { id: string; line_number: number; item: Item; lot: { id: string; internal_code: string; supplier_code: string | null }; inspected_quantity: string; accepted_quantity: string; rejected_quantity: string; uom_code: string; result: string; rejection_reason: string | null; quality_hold_position_id: string; released_position_id: string | null; rejected_position_id: string | null; accepted_movement_id: string | null; rejected_movement_id: string | null };
export type IncomingQualityTask = { id: string; task_number: string; receipt: { id: string; number: string }; purchase_order: { id: string; number: string }; supplier: Supplier; status: string; record_version: number; line_count: number; notes: string | null; created_by: Actor; completed_by: Actor | null; completed_at: string | null; allowed_actions: string[]; lines?: QualityLine[] };
export type QualityWorkspace = { data: IncomingQualityTask[]; meta: PageMeta; summary: Record<string, number>; lookups: { statuses: string[] }; allowed_actions: string[] };

export type RejectedLineLookup = { id: string; task_number: string; receipt_number: string; supplier: Supplier; item: Item; lot: { id: string; internal_code: string }; rejected_quantity: string; returned_quantity: string; returnable_quantity: string; uom_code: string; rejected_position_id: string };
export type SupplierReturn = { id: string; return_number: string; supplier: Supplier; return_date: string; reason: string; status: string; record_version: number; line_count: number; return_total: string; created_by: Actor; posted_at: string | null; cancelled_at: string | null; cancellation_reason: string | null; allowed_actions: string[]; lines?: { id: string; quality_line_id: string; line_number: number; item: Item; lot: { id: string; internal_code: string }; return_quantity: string; uom_code: string; rejected_position_id: string; movement_id: string | null; reason: string | null }[] };
export type ReturnWorkspace = { data: SupplierReturn[]; meta: PageMeta; summary: Record<string, number>; lookups: { statuses: string[]; rejected_lines: RejectedLineLookup[] }; allowed_actions: string[] };

const gatesPath = '/api/v1/procurement/gate-entries';
const receiptsPath = '/api/v1/procurement/receipts';
const qualityPath = '/api/v1/quality/incoming';
const returnsPath = '/api/v1/procurement/supplier-returns';
export const listGateEntries = (filters: Record<string, unknown> = {}) => apiRequest<GateWorkspace>(withQuery(gatesPath, filters));
export const getGateEntry = async (id: string) => (await apiRequest<{ data: GateEntry }>(`${gatesPath}/${id}`)).data;
export const createGateEntry = async (body: unknown, key: string) => (await apiMutation<{ data: CommandResult }>(gatesPath, body, { idempotencyKey: key })).data;
export const updateGateEntry = async (record: Pick<GateEntry, 'id' | 'record_version'>, body: unknown, key: string) => (await apiMutation<{ data: CommandResult }>(`${gatesPath}/${record.id}`, body, { idempotencyKey: key, expectedVersion: record.record_version })).data;
export const cancelGateEntry = async (record: Pick<GateEntry, 'id' | 'record_version'>, reason: string, key: string) => (await apiMutation<{ data: CommandResult }>(`${gatesPath}/${record.id}/cancel`, { reason }, { idempotencyKey: key, expectedVersion: record.record_version })).data;
export const listReceipts = (filters: Record<string, unknown> = {}) => apiRequest<ReceiptWorkspace>(withQuery(receiptsPath, filters));
export const getReceipt = async (id: string) => (await apiRequest<{ data: GoodsReceipt }>(`${receiptsPath}/${id}`)).data;
export const createReceipt = async (body: unknown, key: string) => (await apiMutation<{ data: CommandResult }>(receiptsPath, body, { idempotencyKey: key })).data;
export const updateReceipt = async (record: Pick<GoodsReceipt, 'id' | 'record_version'>, body: unknown, key: string) => (await apiMutation<{ data: CommandResult }>(`${receiptsPath}/${record.id}`, body, { idempotencyKey: key, expectedVersion: record.record_version })).data;
export const postReceipt = async (record: Pick<GoodsReceipt, 'id' | 'record_version'>, key: string) => (await apiMutation<{ data: CommandResult }>(`${receiptsPath}/${record.id}/post`, {}, { idempotencyKey: key, expectedVersion: record.record_version })).data;
export const cancelReceipt = async (record: Pick<GoodsReceipt, 'id' | 'record_version'>, reason: string, key: string) => (await apiMutation<{ data: CommandResult }>(`${receiptsPath}/${record.id}/cancel`, { reason }, { idempotencyKey: key, expectedVersion: record.record_version })).data;
export const listIncomingQuality = (filters: Record<string, unknown> = {}) => apiRequest<QualityWorkspace>(withQuery(qualityPath, filters));
export const getIncomingQuality = async (id: string) => (await apiRequest<{ data: IncomingQualityTask }>(`${qualityPath}/${id}`)).data;
export const completeIncomingQuality = async (record: Pick<IncomingQualityTask, 'id' | 'record_version'>, body: unknown, key: string) => (await apiMutation<{ data: CommandResult }>(`${qualityPath}/${record.id}/complete`, body, { idempotencyKey: key, expectedVersion: record.record_version })).data;
export const listSupplierReturns = (filters: Record<string, unknown> = {}) => apiRequest<ReturnWorkspace>(withQuery(returnsPath, filters));
export const getSupplierReturn = async (id: string) => (await apiRequest<{ data: SupplierReturn }>(`${returnsPath}/${id}`)).data;
export const createSupplierReturn = async (body: unknown, key: string) => (await apiMutation<{ data: CommandResult }>(returnsPath, body, { idempotencyKey: key })).data;
export const updateSupplierReturn = async (record: Pick<SupplierReturn, 'id' | 'record_version'>, body: unknown, key: string) => (await apiMutation<{ data: CommandResult }>(`${returnsPath}/${record.id}`, body, { idempotencyKey: key, expectedVersion: record.record_version })).data;
export const postSupplierReturn = async (record: Pick<SupplierReturn, 'id' | 'record_version'>, key: string) => (await apiMutation<{ data: CommandResult }>(`${returnsPath}/${record.id}/post`, {}, { idempotencyKey: key, expectedVersion: record.record_version })).data;
export const cancelSupplierReturn = async (record: Pick<SupplierReturn, 'id' | 'record_version'>, reason: string, key: string) => (await apiMutation<{ data: CommandResult }>(`${returnsPath}/${record.id}/cancel`, { reason }, { idempotencyKey: key, expectedVersion: record.record_version })).data;

export type PayableLine = { id: string; purchase_order_line_id: string; line_number: number; item: Item; description: string; ordered_quantity: string; accepted_quantity: string; invoice_quantity: string; uom_code: string; po_unit_price: string; invoice_unit_price: string; tax_rate: string; net_amount: string; tax_amount: string; gross_amount: string; match_result: string; match_exception: string | null };
export type PayableInvoice = { id: string; ap_number: string; supplier_invoice_number: string; purchase_order: { id: string; number: string }; supplier: Supplier; invoice_date: string; due_date: string; currency: string; subtotal: string; tax_amount: string; total_amount: string; paid_amount: string; outstanding_amount: string; match_summary: { result: string; exceptions: unknown[] } | null; notes: string | null; status: string; record_version: number; line_count: number; created_by: Actor; matched_by: Actor | null; approved_by: Actor | null; matched_at: string | null; approved_at: string | null; cancelled_at: string | null; cancellation_reason: string | null; allowed_actions: string[]; lines?: PayableLine[]; allocations?: unknown[] };
export type PaymentProposal = { id: string; proposal_number: string; payment_date: string; currency: string; total_amount: string; notes: string | null; status: string; record_version: number; line_count: number; created_by: Actor; approved_by: Actor | null; approved_at: string | null; executed_at: string | null; cancelled_at: string | null; cancellation_reason: string | null; allowed_actions: string[]; lines?: { id: string; invoice_id: string; line_number: number; ap_number: string; supplier_invoice_number: string; supplier: { code: string; name: string }; invoice_total: string; invoice_paid: string; proposed_amount: string }[]; payment?: { id: string; number: string; status: string } | null };
export type SupplierPayment = { id: string; payment_number: string; proposal: { id: string; number: string }; payment_date: string; method: string; bank_reference: string; currency: string; total_amount: string; status: string; record_version: number; created_by: Actor; reconciled_by: Actor | null; reconciled_at: string | null; allowed_actions: string[]; allocations?: unknown[]; reconciliation?: { id: string; statement_date: string; statement_reference: string; notes: string | null; reconciled_by: Actor; created_at: string } | null };
export type EligibleOrder = { id: string; number: string; payment_terms_days: number; supplier: Supplier; lines: { id: string; line_number: number; item: Item; description: string; ordered_quantity: string; accepted_quantity: string; invoiced_quantity: string; available_quantity: string; uom_code: string; unit_price: string }[] };
export type PayableInvoiceLookup = { id: string; ap_number: string; supplier_invoice_number: string; due_date: string; supplier: { code: string; name: string }; total_amount: string; paid_amount: string; outstanding_amount: string; uncommitted_amount: string };
export type PayablesWorkspace = { data: PayableInvoice[]; meta: PageMeta; proposals: PaymentProposal[]; payments: SupplierPayment[]; summary: { invoice_count: number; approved_outstanding: string; match_exceptions: number; draft_proposals: number; unreconciled_payments: number }; lookups: { invoice_statuses: string[]; proposal_statuses: string[]; payment_statuses: string[]; payment_methods: string[]; currency: 'INR'; eligible_orders: EligibleOrder[]; payable_invoices: PayableInvoiceLookup[] }; allowed_actions: string[] };

const payablesPath = '/api/v1/finance/payables';
export const listPayables = (filters: Record<string, unknown> = {}) => apiRequest<PayablesWorkspace>(withQuery(payablesPath, filters));
export const getPayableInvoice = async (id: string) => (await apiRequest<{ data: PayableInvoice }>(`${payablesPath}/invoices/${id}`)).data;
export const createPayableInvoice = async (body: unknown, key: string) => (await apiMutation<{ data: CommandResult }>(`${payablesPath}/invoices`, body, { idempotencyKey: key })).data;
export const updatePayableInvoice = async (record: Pick<PayableInvoice, 'id' | 'record_version'>, body: unknown, key: string) => (await apiMutation<{ data: CommandResult }>(`${payablesPath}/invoices/${record.id}`, body, { idempotencyKey: key, expectedVersion: record.record_version })).data;
export const matchPayableInvoice = async (record: Pick<PayableInvoice, 'id' | 'record_version'>, key: string) => (await apiMutation<{ data: CommandResult }>(`${payablesPath}/invoices/${record.id}/match`, {}, { idempotencyKey: key, expectedVersion: record.record_version })).data;
export const approvePayableInvoice = async (record: Pick<PayableInvoice, 'id' | 'record_version'>, key: string) => (await apiMutation<{ data: CommandResult }>(`${payablesPath}/invoices/${record.id}/approve`, {}, { idempotencyKey: key, expectedVersion: record.record_version })).data;
export const cancelPayableInvoice = async (record: Pick<PayableInvoice, 'id' | 'record_version'>, reason: string, key: string) => (await apiMutation<{ data: CommandResult }>(`${payablesPath}/invoices/${record.id}/cancel`, { reason }, { idempotencyKey: key, expectedVersion: record.record_version })).data;
export const getPaymentProposal = async (id: string) => (await apiRequest<{ data: PaymentProposal }>(`${payablesPath}/proposals/${id}`)).data;
export const createPaymentProposal = async (body: unknown, key: string) => (await apiMutation<{ data: CommandResult }>(`${payablesPath}/proposals`, body, { idempotencyKey: key })).data;
export const updatePaymentProposal = async (record: Pick<PaymentProposal, 'id' | 'record_version'>, body: unknown, key: string) => (await apiMutation<{ data: CommandResult }>(`${payablesPath}/proposals/${record.id}`, body, { idempotencyKey: key, expectedVersion: record.record_version })).data;
export const approvePaymentProposal = async (record: Pick<PaymentProposal, 'id' | 'record_version'>, key: string) => (await apiMutation<{ data: CommandResult }>(`${payablesPath}/proposals/${record.id}/approve`, {}, { idempotencyKey: key, expectedVersion: record.record_version })).data;
export const cancelPaymentProposal = async (record: Pick<PaymentProposal, 'id' | 'record_version'>, reason: string, key: string) => (await apiMutation<{ data: CommandResult }>(`${payablesPath}/proposals/${record.id}/cancel`, { reason }, { idempotencyKey: key, expectedVersion: record.record_version })).data;
export const executePaymentProposal = async (record: Pick<PaymentProposal, 'id' | 'record_version'>, body: unknown, key: string) => (await apiMutation<{ data: CommandResult }>(`${payablesPath}/proposals/${record.id}/execute`, body, { idempotencyKey: key, expectedVersion: record.record_version })).data;
export const getSupplierPayment = async (id: string) => (await apiRequest<{ data: SupplierPayment }>(`${payablesPath}/payments/${id}`)).data;
export const reconcileSupplierPayment = async (record: Pick<SupplierPayment, 'id' | 'record_version'>, body: unknown, key: string) => (await apiMutation<{ data: CommandResult }>(`${payablesPath}/payments/${record.id}/reconcile`, body, { idempotencyKey: key, expectedVersion: record.record_version })).data;

function withQuery(path: string, filters: Record<string, unknown>) {
  const search = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => { if (value !== undefined && value !== null && value !== '') search.set(key, String(value)); });
  const query = search.toString();
  return query ? `${path}?${query}` : path;
}

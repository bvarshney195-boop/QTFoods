import type { P2Record, P2Workspace } from '../api/p2Operations';
import {
  GovernedP2Workspace,
  type GovernedP2Config,
  type P2ActionSpec,
} from './GovernedP2Workspace';
import type { StructuredCommandSchema } from './StructuredCommandForm';

export type CommercialP2Code =
  | 'CRM-LEAD' | 'CRM-PRICE' | 'CRM-ORDER' | 'CON-WORK' | 'DSP-PICK'
  | 'DSP-LOAD' | 'DSP-POD' | 'RET-CASE' | 'FIN-AR' | 'BI-PROFIT';

export function CommercialP2Workspace({ screen }: { screen: CommercialP2Code }) {
  return <GovernedP2Workspace config={commercialP2Configs[screen]} />;
}

const today = () => new Date().toISOString().slice(0, 10);
const future = (days: number) => { const value = new Date(); value.setUTCDate(value.getUTCDate() + days); return value.toISOString().slice(0, 10); };
const number = (prefix: string) => `${prefix}-${new Date().toISOString().replace(/\D/g, '').slice(2, 14)}`;
const value = (record: Record<string, unknown> | undefined, key: string, fallback: unknown = null) => record?.[key] ?? fallback;
const id = (record: Record<string, unknown> | undefined) => String(record?.id ?? '');
const rows = (workspace: P2Workspace, key: string): Record<string, unknown>[] => {
  const lookups = workspace.lookups;
  if (!lookups || typeof lookups !== 'object') return [];
  const result = (lookups as Record<string, unknown>)[key];
  return Array.isArray(result) ? result.filter((row): row is Record<string, unknown> => Boolean(row) && typeof row === 'object') : [];
};
const choices = (workspace: P2Workspace, key: string): unknown[] => {
  const lookups = workspace.lookups;
  if (!lookups || typeof lookups !== 'object') return [];
  const result = (lookups as Record<string, unknown>)[key];
  return Array.isArray(result) ? result : [];
};
const first = (workspace: P2Workspace, key: string) => rows(workspace, key)[0];
const detailRows = (record: P2Record, key = 'lines') => Array.isArray(record[key]) ? (record[key] as Record<string, unknown>[]) : [];
const edit = (label: string, help: string, path: string, body: unknown, record: P2Record, schema: StructuredCommandSchema): P2ActionSpec => ({ editor: { label, help, path, body, schema, expectedVersion: record.record_version } });
const run = (path: string, success: string, record: P2Record, body: unknown = {}): P2ActionSpec => ({ path, body, expectedVersion: record.record_version, success });

const noRequiredFields: StructuredCommandSchema = { required: [] };
const commercialSchemas = {
  leadCreate: { required: ['lead_number', 'company_name', 'contact_name', 'source', 'enquiry_date', 'estimated_value'], atLeastOne: [{ paths: ['contact_email', 'contact_phone'], message: 'Enter a contact email or contact phone.' }] },
  leadUpdate: { required: ['company_name', 'contact_name', 'source', 'enquiry_date', 'estimated_value'], atLeastOne: [{ paths: ['contact_email', 'contact_phone'], message: 'Enter a contact email or contact phone.' }] },
  leadConvert: { required: ['conversion_path'], requiredWhen: [{ path: 'conversion_path', equals: 'EXISTING_CUSTOMER', required: ['customer_party_id'] }, { path: 'conversion_path', equals: 'CREATE_CUSTOMER', required: ['customer_code', 'customer_name', 'contact_name'] }] },
  leadClose: { required: ['outcome'] },
  priceCreate: { required: ['list_number', 'name', 'effective_from', 'lines[].item_id', 'lines[].uom_code', 'lines[].minimum_quantity', 'lines[].unit_price', 'lines[].maximum_discount_percent', 'lines[].tax_rate'], minItems: { lines: 1 } },
  priceUpdate: { required: ['name', 'effective_from', 'lines[].item_id', 'lines[].uom_code', 'lines[].minimum_quantity', 'lines[].unit_price', 'lines[].maximum_discount_percent', 'lines[].tax_rate'], minItems: { lines: 1 } },
  contractCreate: { required: ['contract_number', 'customer_party_id', 'effective_from', 'effective_to', 'committed_value', 'lines[].item_id', 'lines[].uom_code', 'lines[].committed_quantity', 'lines[].unit_price', 'lines[].tax_rate'], minItems: { lines: 1 } },
  contractUpdate: { required: ['customer_party_id', 'effective_from', 'effective_to', 'committed_value', 'lines[].item_id', 'lines[].uom_code', 'lines[].committed_quantity', 'lines[].unit_price', 'lines[].tax_rate'], minItems: { lines: 1 } },
  credit: { required: ['customer_party_id', 'credit_limit', 'payment_terms_days', 'is_on_hold'] },
  reason: { required: ['reason'] },
  orderCreate: { required: ['order_number', 'customer_party_id', 'order_date', 'requested_delivery_date', 'lines[].item_id', 'lines[].uom_code', 'lines[].quantity'], minItems: { lines: 1 } },
  orderUpdate: { required: ['customer_party_id', 'order_date', 'requested_delivery_date', 'lines[].item_id', 'lines[].uom_code', 'lines[].quantity'], minItems: { lines: 1 } },
  orderAmend: { required: ['reason', 'customer_party_id', 'order_date', 'requested_delivery_date', 'lines[].item_id', 'lines[].uom_code', 'lines[].quantity'], minItems: { lines: 1 } },
  allocation: { required: ['allocation_number', 'lines[].sales_order_line_id', 'lines[].quantity'] },
  work: { required: ['work_number', 'provider_party_id', 'work_type', 'description', 'expected_start_date', 'expected_end_date', 'agreed_cost'] },
  workComplete: { required: ['actual_cost'] },
  shipment: { required: ['shipment_number', 'sales_allocation_id', 'carrier_name', 'vehicle_number', 'driver_name'] },
  dispatch: { required: ['invoice_number'] },
  pod: { required: ['proof_number', 'outcome', 'event_at'], requiredWhen: [{ path: 'outcome', equals: 'FAILED', required: ['failure_reason'] }] },
  claim: { required: ['claim_number', 'shipment_id', 'claim_type', 'requested_resolution', 'reason', 'lines[].shipment_line_id', 'lines[].quantity'], minItems: { lines: 1 } },
  claimResolve: { required: ['resolution_type', 'notes'], requiredWhen: [{ path: 'resolution_type', equals: 'CREDIT', required: ['credit_amount'] }] },
  collection: { required: ['receipt_number', 'customer_party_id', 'receipt_date', 'payment_method', 'bank_reference', 'total_amount', 'allocations[].invoice_id', 'allocations[].amount'], minItems: { allocations: 1 } },
} satisfies Record<string, StructuredCommandSchema>;

function leadBody(record: P2Record) {
  return {
    customer_party_id: value(record, 'customer_party_id'), company_name: value(record, 'company_name', ''),
    contact_name: value(record, 'contact_name', ''), contact_email: value(record, 'contact_email'),
    contact_phone: value(record, 'contact_phone'), source: value(record, 'source', 'DIRECT'),
    enquiry_date: value(record, 'enquiry_date', today()), expected_close_date: value(record, 'expected_close_date'),
    estimated_value: value(record, 'estimated_value', '0'), notes: value(record, 'notes'),
  };
}

function priceBody(record: P2Record) {
  return {
    name: value(record, 'name', ''), effective_from: value(record, 'effective_from', today()),
    effective_to: value(record, 'effective_to'), notes: value(record, 'notes'),
    lines: detailRows(record).map((line) => ({
      item_id: value(line, 'item_id'), uom_code: value(line, 'uom_code'), minimum_quantity: value(line, 'minimum_quantity'),
      unit_price: value(line, 'unit_price'), maximum_discount_percent: value(line, 'maximum_discount_percent'), tax_rate: value(line, 'tax_rate'),
    })),
  };
}

function contractBody(record: P2Record) {
  return {
    customer_party_id: value(record, 'customer_party_id'), sales_price_list_id: value(record, 'sales_price_list_id'),
    effective_from: value(record, 'effective_from', today()), effective_to: value(record, 'effective_to', future(30)),
    committed_value: value(record, 'committed_value', '0'), notes: value(record, 'notes'),
    lines: detailRows(record).map((line) => ({
      item_id: value(line, 'item_id'), uom_code: value(line, 'uom_code'), committed_quantity: value(line, 'committed_quantity'),
      unit_price: value(line, 'unit_price'), tax_rate: value(line, 'tax_rate'),
    })),
  };
}

function orderBody(record: P2Record) {
  return {
    customer_party_id: value(record, 'customer_party_id'), sales_lead_id: value(record, 'sales_lead_id'),
    sales_contract_id: value(record, 'sales_contract_id'), sales_price_list_id: value(record, 'sales_price_list_id'),
    order_date: value(record, 'order_date', today()), requested_delivery_date: value(record, 'requested_delivery_date', future(7)),
    notes: value(record, 'notes'), lines: detailRows(record).map((line) => ({
      item_id: value(line, 'item_id'), uom_code: value(line, 'uom_code'), quantity: value(line, 'ordered_quantity'),
      discount_percent: value(line, 'discount_percent', '0'),
    })),
  };
}

function shipmentCommand(allocationId: string) {
  return {
    shipment_number: number('SHP'), sales_allocation_id: allocationId, carrier_name: '', vehicle_number: '',
    driver_name: '', notes: null,
  };
}

function podCommand(record: P2Record) {
  return { proof_number: number('POD'), outcome: 'DELIVERED', receiver_name: '', event_at: new Date().toISOString(), failure_reason: null, notes: null };
}

function claimCommand(shipment: Record<string, unknown>, shipmentLineId?: string) {
  return {
    claim_number: number('CLM'), shipment_id: id(shipment), claim_type: 'DAMAGE', requested_resolution: 'CREDIT',
    reason: '', lines: [{ shipment_line_id: shipmentLineId ?? '', quantity: '1' }],
  };
}

export const commercialP2Configs: Record<CommercialP2Code, GovernedP2Config> = {
  'CRM-LEAD': {
    code: 'CRM-LEAD', title: 'Leads & Enquiries', description: 'Qualify customer demand and preserve the commercial decision trail.',
    notice: 'Live scoped leads, customer references and immutable lifecycle events. Conversion and close outcomes are permissioned and version checked.',
    listPath: '/api/v1/sales/leads',
    collections: [{ key: 'data', label: 'Leads', kind: 'lead', detailPath: (record) => `/api/v1/sales/leads/${record.id}`, columns: [
      { label: 'Lead', key: 'lead_number' }, { label: 'Company', key: 'company_name' }, { label: 'Contact', key: 'contact_name' },
      { label: 'Enquiry', key: 'enquiry_date', format: 'date' }, { label: 'Estimated value', key: 'estimated_value', format: 'money' },
    ] }],
    creators: [{ action: 'CREATE', label: 'New lead', path: '/api/v1/sales/leads', help: 'Complete the enquiry and contact facts. Server policy owns status and event history.', schema: commercialSchemas.leadCreate, template: (workspace) => {
      const customer = first(workspace, 'customers'); return { lead_number: number('LEAD'), customer_party_id: id(customer) || null, company_name: String(value(customer, 'name', '')), contact_name: '', contact_email: null, contact_phone: null, source: String(choices(workspace, 'sources')[0] ?? 'DIRECT'), enquiry_date: today(), expected_close_date: future(14), estimated_value: '0', notes: null };
    } }],
    resolveAction: (action, record, workspace) => {
      const base = `/api/v1/sales/leads/${record.id}`;
      if (action === 'UPDATE') return edit('Update lead', 'Only editable enquiry facts are sent; identity and history remain immutable.', base, leadBody(record), record, commercialSchemas.leadUpdate);
      if (action === 'QUALIFY') return run(`${base}/qualify`, 'Lead qualified.', record);
      if (action === 'CONVERT') return edit('Convert lead', 'Link an existing customer or create a governed customer directly from the qualified lead.', `${base}/convert`, {
        conversion_path: value(record, 'customer_party_id') ? 'EXISTING_CUSTOMER' : 'CREATE_CUSTOMER',
        customer_party_id: value(record, 'customer_party_id', id(first(workspace, 'customers'))) || null,
        customer_code: `CUST-${String(value(record, 'lead_number', '')).replace(/^LEAD-?/i, '')}`,
        customer_name: value(record, 'company_name', ''), contact_name: value(record, 'contact_name', ''),
        contact_email: value(record, 'contact_email'), contact_phone: value(record, 'contact_phone'),
      }, record, commercialSchemas.leadConvert);
      if (action === 'CLOSE_WON' || action === 'CLOSE_LOST') return edit(action === 'CLOSE_WON' ? 'Close lead as won' : 'Close lead as lost', 'Record the outcome and optional commercial reason.', `${base}/close`, { outcome: action === 'CLOSE_WON' ? 'WON' : 'LOST', reason: '' }, record, commercialSchemas.leadClose);
      return null;
    },
  },
  'CRM-PRICE': {
    code: 'CRM-PRICE', title: 'Pricing, Credit & Contracts', description: 'Govern selling prices, discounts, credit exposure and customer commitments.',
    notice: 'Price and contract lines are server validated against active items. Credit controls are company scoped and applied again when an order is confirmed.',
    listPath: '/api/v1/sales/pricing', collections: [
      { key: 'data', label: 'Price lists', kind: 'price', detailPath: (record) => `/api/v1/sales/price-lists/${record.id}`, columns: [{ label: 'List', key: 'list_number' }, { label: 'Name', key: 'name' }, { label: 'From', key: 'effective_from', format: 'date' }, { label: 'Lines', key: 'line_count', format: 'number' }] },
      { key: 'contracts', label: 'Contracts', kind: 'contract', detailPath: (record) => `/api/v1/sales/contracts/${record.id}`, columns: [{ label: 'Contract', key: 'contract_number' }, { label: 'Customer', key: 'customer_name' }, { label: 'From', key: 'effective_from', format: 'date' }, { label: 'Committed', key: 'committed_value', format: 'money' }] },
      { key: 'credit_profiles', label: 'Credit control', kind: 'credit', columns: [{ label: 'Customer', key: 'customer_name' }, { label: 'Limit', key: 'credit_limit', format: 'money' }, { label: 'Terms (days)', key: 'payment_terms_days', format: 'number' }, { label: 'On hold', key: 'is_on_hold' }] },
    ],
    creators: [
      { action: 'CREATE_PRICE_LIST', label: 'New price list', path: '/api/v1/sales/price-lists', available: (workspace) => Boolean(first(workspace, 'items')), help: 'Start with an active catalog item, then add or update price lines in the form.', schema: commercialSchemas.priceCreate, template: (workspace) => { const item = first(workspace, 'items'); return { list_number: number('PL'), name: '', effective_from: today(), effective_to: future(30), notes: null, lines: [{ item_id: id(item), uom_code: value(item, 'base_uom', 'EA'), minimum_quantity: '1', unit_price: '0', maximum_discount_percent: '0', tax_rate: '0' }] }; } },
      { action: 'CREATE_CONTRACT', label: 'New contract', path: '/api/v1/sales/contracts', available: (workspace) => Boolean(first(workspace, 'customers') && first(workspace, 'items')), help: 'Contract value and every committed item are validated against the selected customer and plant.', schema: commercialSchemas.contractCreate, template: (workspace) => { const customer = first(workspace, 'customers'); const item = first(workspace, 'items'); return { contract_number: number('CON'), customer_party_id: id(customer), sales_price_list_id: null, effective_from: today(), effective_to: future(90), committed_value: '0', notes: null, lines: [{ item_id: id(item), uom_code: value(item, 'base_uom', 'EA'), committed_quantity: '1', unit_price: '0', tax_rate: '0' }] }; } },
      { action: 'MANAGE_CREDIT', label: 'Manage credit', path: '/api/v1/sales/credit-profiles', available: (workspace) => Boolean(first(workspace, 'customers')), help: 'Set the limit, terms and hold state for one active customer. Supply the displayed version when changing an existing profile.', schema: commercialSchemas.credit, template: (workspace) => { const customer = first(workspace, 'customers'); return { customer_party_id: id(customer), credit_limit: value(customer, 'credit_limit', '0'), payment_terms_days: value(customer, 'payment_terms_days', 30), is_on_hold: Boolean(value(customer, 'is_on_hold', false)), hold_reason: null, record_version: null }; } },
    ],
    resolveAction: (action, record) => {
      const contract = record._kind === 'contract'; const base = contract ? `/api/v1/sales/contracts/${record.id}` : `/api/v1/sales/price-lists/${record.id}`;
      if (action === 'UPDATE') return edit(contract ? 'Update contract' : 'Update price list', 'Submit the complete replacement line set under optimistic locking.', base, contract ? contractBody(record) : priceBody(record), record, contract ? commercialSchemas.contractUpdate : commercialSchemas.priceUpdate);
      if (action === 'ACTIVATE') return run(`${base}/activate`, contract ? 'Contract activated.' : 'Price list activated.', record);
      if (action === 'RETIRE' || action === 'CLOSE') return edit(contract ? 'Close contract' : 'Retire price list', 'A reason is required and retained in the audit event.', `${base}/${contract ? 'close' : 'retire'}`, { reason: '' }, record, commercialSchemas.reason);
      return null;
    },
  },
  'CRM-ORDER': {
    code: 'CRM-ORDER', title: 'Sales Orders', description: 'Price, confirm, amend and cancel customer orders under credit and contract controls.',
    notice: 'Order totals, prices, discounts, tax, credit availability and immutable revisions are calculated and enforced by the server.', listPath: '/api/v1/sales/orders',
    collections: [{ key: 'data', label: 'Orders', kind: 'order', detailPath: (record) => `/api/v1/sales/orders/${record.id}`, columns: [{ label: 'Order', key: 'order_number' }, { label: 'Customer', key: 'customer_name' }, { label: 'Order date', key: 'order_date', format: 'date' }, { label: 'Delivery', key: 'requested_delivery_date', format: 'date' }, { label: 'Total', key: 'total_amount', format: 'money' }] }],
    creators: [{ action: 'CREATE', label: 'New sales order', path: '/api/v1/sales/orders', available: (workspace) => Boolean(first(workspace, 'customers') && first(workspace, 'items')), help: 'Choose the customer and items from the available business records. Pricing and credit checks are applied automatically.', schema: commercialSchemas.orderCreate, template: (workspace) => { const contract = first(workspace, 'contracts'); const customer = contract ? { id: value(contract, 'customer_party_id') } : first(workspace, 'customers'); const item = first(workspace, 'items'); return { order_number: number('SO'), customer_party_id: id(customer), sales_lead_id: null, sales_contract_id: id(contract) || null, sales_price_list_id: null, order_date: today(), requested_delivery_date: future(7), notes: null, lines: [{ item_id: id(item), uom_code: value(item, 'base_uom', 'EA'), quantity: '1', discount_percent: '0' }] }; } }],
    resolveAction: (action, record) => { const base = `/api/v1/sales/orders/${record.id}`;
      if (action === 'UPDATE') return edit('Update sales order', 'Replace editable header and line facts while the order remains draft.', base, orderBody(record), record, commercialSchemas.orderUpdate);
      if (action === 'CONFIRM') return run(`${base}/confirm`, 'Sales order confirmed after pricing and credit checks.', record);
      if (action === 'AMEND') return edit('Amend confirmed order', 'A new immutable revision is created from this complete replacement payload.', `${base}/amend`, { reason: '', ...orderBody(record) }, record, commercialSchemas.orderAmend);
      if (action === 'CANCEL') return edit('Cancel sales order', 'Cancellation releases active reservations and records the reason.', `${base}/cancel`, { reason: '' }, record, commercialSchemas.reason);
      if (action === 'ALLOCATE') return edit('Allocate sales order', 'FEFO stock selection and reservation quantities remain server authoritative.', `/api/v1/dispatch/orders/${record.id}/allocations`, { allocation_number: number('ALLOC') }, record, commercialSchemas.allocation);
      return null;
    },
  },
  'CON-WORK': {
    code: 'CON-WORK', title: 'Third-party Work', description: 'Control contracted processing linked to suppliers and sales demand.',
    notice: 'Live provider and sales-order references, governed release/completion, actual-cost capture and cancellation evidence.', listPath: '/api/v1/sales/third-party-work',
    collections: [{ key: 'data', label: 'Work orders', kind: 'work', detailPath: (record) => `/api/v1/sales/third-party-work/${record.id}`, columns: [{ label: 'Work', key: 'work_number' }, { label: 'Provider', key: 'provider_name' }, { label: 'Type', key: 'work_type' }, { label: 'Start', key: 'expected_start_date', format: 'date' }, { label: 'Agreed cost', key: 'agreed_cost', format: 'money' }] }],
    creators: [{ action: 'CREATE', label: 'New contracted work', path: '/api/v1/sales/third-party-work', available: (workspace) => Boolean(first(workspace, 'providers')), help: 'Create a provider engagement; release and completion remain separate controlled steps.', schema: commercialSchemas.work, template: (workspace) => ({ work_number: number('TPW'), sales_order_id: id(first(workspace, 'sales_orders')) || null, provider_party_id: id(first(workspace, 'providers')), work_type: 'CO_PACKING', description: '', expected_start_date: today(), expected_end_date: future(7), agreed_cost: '0' }) }],
    resolveAction: (action, record) => { const base = `/api/v1/sales/third-party-work/${record.id}`; if (action === 'RELEASE') return run(`${base}/release`, 'Third-party work released.', record); if (action === 'COMPLETE') return edit('Complete contracted work', 'Record the final actual cost before closing the engagement.', `${base}/complete`, { actual_cost: value(record, 'agreed_cost', '0') }, record, commercialSchemas.workComplete); if (action === 'CANCEL') return edit('Cancel contracted work', 'Record a reviewable cancellation reason.', `${base}/cancel`, { reason: '' }, record, commercialSchemas.reason); return null; },
  },
  'DSP-PICK': {
    code: 'DSP-PICK', title: 'Allocation & Picking', description: 'Reserve FEFO stock against confirmed orders and record the pick hand-off.',
    notice: 'Reservations lock the stock ledger, respect quality/expiry/availability, and remain linked to exact lots and positions.', listPath: '/api/v1/dispatch/allocations',
    collections: [{ key: 'data', label: 'Allocations', kind: 'allocation', detailPath: (record) => `/api/v1/dispatch/allocations/${record.id}`, columns: [{ label: 'Allocation', key: 'allocation_number' }, { label: 'Order', key: 'order_number' }, { label: 'Customer', key: 'customer_name' }, { label: 'Quantity', key: 'allocated_quantity', format: 'number' }, { label: 'FEFO breaks', key: 'fefo_break_count', format: 'number' }] }],
    creators: [{ action: 'ALLOCATE', label: 'Allocate order', path: (workspace) => `/api/v1/dispatch/orders/${id(first(workspace, 'orders'))}/allocations`, expectedVersion: (workspace) => Number(value(first(workspace, 'orders'), 'record_version')) || undefined, available: (workspace) => Boolean(first(workspace, 'orders')), help: 'The server reserves FEFO stock for the selected confirmed order. Optional line quantities may be added.', schema: commercialSchemas.allocation, template: () => ({ allocation_number: number('ALLOC') }) }],
    resolveAction: (action, record) => { const base = `/api/v1/dispatch/allocations/${record.id}`; if (action === 'PICK') return run(`${base}/pick`, 'Allocation picked and stock evidence retained.', record); if (action === 'CANCEL') return edit('Cancel allocation', 'Cancellation releases all active reservations.', `${base}/cancel`, { reason: '' }, record, commercialSchemas.reason); if (action === 'CREATE_SHIPMENT') return edit('Create shipment', 'Create the controlled load from this picked allocation.', '/api/v1/dispatch/shipments', shipmentCommand(record.id), record, commercialSchemas.shipment); return null; },
  },
  'DSP-LOAD': {
    code: 'DSP-LOAD', title: 'Loading & Dispatch', description: 'Build, load and dispatch customer shipments with invoice and stock posting.',
    notice: 'Dispatch consumes the exact reserved lots, writes immutable movements, and creates the receivable invoice in one transaction.', listPath: '/api/v1/dispatch/shipments',
    collections: [{ key: 'data', label: 'Shipments', kind: 'shipment', detailPath: (record) => `/api/v1/dispatch/shipments/${record.id}`, columns: [{ label: 'Shipment', key: 'shipment_number' }, { label: 'Order', key: 'order_number' }, { label: 'Customer', key: 'customer_name' }, { label: 'Vehicle', key: 'vehicle_number' }, { label: 'Dispatched', key: 'dispatched_at', format: 'date' }] }],
    creators: [{ action: 'CREATE', label: 'New shipment', path: '/api/v1/dispatch/shipments', available: (workspace) => Boolean(first(workspace, 'allocations')), help: 'Only a picked allocation can become a shipment.', schema: commercialSchemas.shipment, template: (workspace) => shipmentCommand(id(first(workspace, 'allocations'))) }],
    resolveAction: shipmentAction,
  },
  'DSP-POD': {
    code: 'DSP-POD', title: 'Proof of Delivery', description: 'Complete delivery evidence and surface failed delivery outcomes.',
    notice: 'Proof numbers are unique, delivery timestamps and receivers are retained, and failed outcomes remain visible for follow-up.', listPath: '/api/v1/dispatch/pod',
    collections: [{ key: 'data', label: 'Delivery proof queue', kind: 'shipment', detailPath: (record) => `/api/v1/dispatch/shipments/${record.id}`, columns: [{ label: 'Shipment', key: 'shipment_number' }, { label: 'Order', key: 'order_number' }, { label: 'Customer', key: 'customer_name' }, { label: 'Proof', key: 'proof_number' }, { label: 'Event', key: 'event_at', format: 'date' }] }],
    resolveAction: shipmentAction,
  },
  'RET-CASE': {
    code: 'RET-CASE', title: 'Customer Claims & Returns', description: 'Receive, assess and resolve shipment claims through credit, replacement or rejection.',
    notice: 'Claim quantities are tied to exact shipment lines. Credits post to receivables; replacements remain explicit governed outcomes.', listPath: '/api/v1/sales/customer-claims',
    collections: [{ key: 'data', label: 'Claims', kind: 'claim', detailPath: (record) => `/api/v1/sales/customer-claims/${record.id}`, columns: [{ label: 'Claim', key: 'claim_number' }, { label: 'Shipment', key: 'shipment_number' }, { label: 'Customer', key: 'customer_name' }, { label: 'Type', key: 'claim_type' }, { label: 'Requested', key: 'requested_resolution' }, { label: 'Credit', key: 'credit_amount', format: 'money' }] }],
    creators: [{ action: 'CREATE', label: 'New customer claim', path: '/api/v1/sales/customer-claims', available: (workspace) => Boolean(value(first(workspace, 'shipments'), 'first_line_id')), help: 'The first eligible shipment line is selected automatically. Add or update return lines in the form.', schema: commercialSchemas.claim, template: (workspace) => { const shipment = first(workspace, 'shipments'); return claimCommand(shipment, String(value(shipment, 'first_line_id', ''))); } }],
    resolveAction: (action, record) => { const base = `/api/v1/sales/customer-claims/${record.id}`; if (action === 'RECEIVE') return edit('Receive returned goods', 'Record optional return receipt notes; stock quarantine remains server controlled.', `${base}/receive`, { notes: null }, record, noRequiredFields); if (action === 'RESOLVE') return edit('Resolve customer claim', 'Choose CREDIT, REPLACEMENT or REJECT and record the decision evidence.', `${base}/resolve`, { resolution_type: value(record, 'requested_resolution', 'CREDIT'), credit_amount: value(record, 'requested_resolution') === 'CREDIT' ? value(record, 'requested_amount', '0') : null, notes: '' }, record, commercialSchemas.claimResolve); return null; },
  },
  'FIN-AR': {
    code: 'FIN-AR', title: 'Receivables & Collections', description: 'Monitor invoice ageing and allocate customer receipts without rewriting invoice history.',
    notice: 'Outstanding balances, ageing and customer exposure are server derived. Receipts post atomically against selected open invoices.', listPath: '/api/v1/finance/receivables', supportsStatus: false,
    collections: [
      { key: 'data', label: 'Invoices', kind: 'invoice', detailPath: (record) => `/api/v1/finance/receivables/${record.id}`, columns: [{ label: 'Invoice', key: 'invoice_number' }, { label: 'Customer', key: 'customer_name' }, { label: 'Due', key: 'due_date', format: 'date' }, { label: 'Outstanding', key: 'outstanding_amount', format: 'money' }, { label: 'Ageing', key: 'ageing_bucket' }] },
      { key: 'receipts', label: 'Receipts', kind: 'receipt', columns: [{ label: 'Receipt', key: 'receipt_number' }, { label: 'Customer', key: 'customer_name' }, { label: 'Date', key: 'receipt_date', format: 'date' }, { label: 'Method', key: 'payment_method' }, { label: 'Amount', key: 'total_amount', format: 'money' }] },
    ],
    creators: [{ action: 'COLLECT', label: 'Record collection', path: '/api/v1/finance/receivables/collections', available: (workspace) => Boolean(first(workspace, 'open_invoices')), help: 'Allocate the receipt exactly across open invoices for one customer.', schema: commercialSchemas.collection, template: (workspace) => { const invoice = first(workspace, 'open_invoices'); const amount = value(invoice, 'outstanding_amount', '0'); return { receipt_number: number('RCPT'), customer_party_id: value(invoice, 'customer_party_id'), receipt_date: today(), payment_method: 'BANK', bank_reference: number('BANK'), total_amount: amount, allocations: [{ invoice_id: id(invoice), amount }] }; } }],
  },
  'BI-PROFIT': {
    code: 'BI-PROFIT', title: 'Order Profitability', description: 'Compare recognized order revenue with immutable item-cost snapshots.',
    notice: 'This read model is calculated from live sales lines and cost snapshots; it never posts or changes the ledger.', listPath: '/api/v1/reports/profitability', supportsStatus: false,
    collections: [{ key: 'data', label: 'Order margins', kind: 'margin', showStatus: false, columns: [{ label: 'Order', key: 'order_number' }, { label: 'Customer', key: 'customer.name' }, { label: 'Date', key: 'order_date', format: 'date' }, { label: 'Revenue', key: 'revenue', format: 'money' }, { label: 'Cost', key: 'cost', format: 'money' }, { label: 'Gross margin', key: 'gross_margin', format: 'money' }, { label: 'Margin %', key: 'margin_percent', format: 'number' }, { label: 'Cost status', key: 'cost_status' }] }],
  },
};

function shipmentAction(action: string, record: P2Record): P2ActionSpec | null {
  const base = `/api/v1/dispatch/shipments/${record.id}`;
  if (action === 'LOAD') return run(`${base}/load`, 'Shipment loaded.', record);
  if (action === 'DISPATCH') return edit('Dispatch shipment', 'Dispatch posts stock and creates the controlled sales invoice.', `${base}/dispatch`, { invoice_number: number('INV') }, record, commercialSchemas.dispatch);
  if (action === 'CANCEL') return edit('Cancel shipment', 'Record the cancellation reason before loading is finalized.', `${base}/cancel`, { reason: '' }, record, commercialSchemas.reason);
  if (action === 'POD') return edit('Complete proof of delivery', 'Record delivered or failed outcome and its evidence.', `${base}/pod`, podCommand(record), record, commercialSchemas.pod);
  if (action === 'CLAIM') return edit('Create customer claim', 'Tie the claim to exact shipment-line quantities.', '/api/v1/sales/customer-claims', claimCommand(record, String(value(detailRows(record)[0], 'id', ''))), record, commercialSchemas.claim);
  return null;
}

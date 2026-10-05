import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
  PurchaseOrder,
  PurchaseOrderCommandResult,
  PurchaseOrderWorkspace as Workspace,
} from '../api/procurementSourcing';
import { ErpSessionContext } from '../app/ErpSessionContext';
import type { ErpSession } from '../types/session';
import { PurchaseOrderWorkspace } from './PurchaseOrderWorkspace';

const apiMocks = vi.hoisted(() => ({
  listPurchaseOrders: vi.fn(),
  getPurchaseOrder: vi.fn(),
  createPurchaseOrder: vi.fn(),
  amendPurchaseOrder: vi.fn(),
  issuePurchaseOrder: vi.fn(),
  cancelPurchaseOrder: vi.fn(),
}));

vi.mock('../api/procurementSourcing', async () => {
  const actual = await vi.importActual<typeof import('../api/procurementSourcing')>('../api/procurementSourcing');
  return { ...actual, ...apiMocks };
});

describe('purchase order workspace', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    apiMocks.listPurchaseOrders.mockResolvedValue(workspace());
    apiMocks.getPurchaseOrder.mockResolvedValue(order());
    apiMocks.createPurchaseOrder.mockResolvedValue(result('DRAFT', 1, 1));
    apiMocks.amendPurchaseOrder.mockResolvedValue(result('DRAFT', 2, 2));
    apiMocks.issuePurchaseOrder.mockResolvedValue(result('ISSUED', 2, 1));
    apiMocks.cancelPurchaseOrder.mockResolvedValue(result('CANCELLED', 3, 1));
  });

  it('creates a draft from an awarded RFQ and its governed supplier quote', async () => {
    const user = userEvent.setup();
    renderPage();

    expect(await screen.findByRole('heading', { name: 'Purchase orders' })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: '+ New' }));
    await user.type(screen.getByLabelText('Purchase-order number'), 'po-ui-001');
    await user.selectOptions(screen.getByLabelText('Awarded RFQ'), 'rfq-1');
    fireEvent.change(screen.getByLabelText('Order date'), { target: { value: '2026-09-11' } });
    await user.type(screen.getByLabelText('Delivery terms'), 'Deliver to the raw-material receiving bay.');
    await user.type(screen.getByLabelText('Order notes'), 'Attach the batch certificate.');
    await user.click(screen.getByRole('button', { name: 'Create draft order' }));

    await waitFor(() => expect(apiMocks.createPurchaseOrder).toHaveBeenCalledWith({
      po_number: 'PO-UI-001', rfq_id: 'rfq-1', order_date: '2026-09-11', incoterm_code: 'DAP',
      delivery_terms: 'Deliver to the raw-material receiving bay.', notes: 'Attach the batch certificate.',
    }, expect.any(String)));
    expect(await screen.findByText('Draft purchase order created from the awarded supplier quote.')).toBeInTheDocument();
  });

  it('records a complete commercial amendment as the next immutable revision', async () => {
    const user = userEvent.setup();
    const issued = order({ status: 'ISSUED', record_version: 2, allowed_actions: ['AMEND', 'CANCEL'], issued_at: '2026-09-11T12:00:00Z', issued_by: actor() });
    const amended = order({ ...issued, record_version: 3, revision_number: 2, freight_amount: '25.00', total_amount: '1015.00', revisions: [...issued.revisions!, revision(2, 'Supplier confirmed revised freight.')] });
    apiMocks.listPurchaseOrders.mockResolvedValue(workspace([issued]));
    apiMocks.getPurchaseOrder.mockResolvedValueOnce(issued).mockResolvedValueOnce(amended);
    apiMocks.amendPurchaseOrder.mockResolvedValue(result('ISSUED', 3, 2));
    renderPage();

    await user.click(await screen.findByRole('button', { name: 'Open' }));
    await user.click(await screen.findByRole('button', { name: 'Amend order' }));
    await user.type(screen.getByLabelText('Amendment reason'), 'Supplier confirmed revised freight.');
    fireEvent.change(screen.getByLabelText('Freight'), { target: { value: '25' } });
    fireEvent.change(screen.getByLabelText('Line 1 ordered quantity'), { target: { value: '20' } });
    fireEvent.change(screen.getByLabelText('Line 1 order unit price'), { target: { value: '49.5' } });
    await user.click(screen.getByRole('button', { name: 'Record amendment' }));

    await waitFor(() => expect(apiMocks.amendPurchaseOrder).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'order-1', record_version: 2 }),
      expect.objectContaining({
        reason: 'Supplier confirmed revised freight.', freight_amount: '25',
        lines: [{ rfq_line_id: 'rfq-line-1', ordered_quantity: '20', unit_price: '49.5', notes: null }],
      }),
      expect.any(String),
    ));
    expect(await screen.findByText('Purchase-order amendment recorded as a new immutable revision.')).toBeInTheDocument();
  });

  it('issues the current revision and captures cancellation evidence', async () => {
    const user = userEvent.setup();
    const draft = order();
    const issued = order({ status: 'ISSUED', record_version: 2, allowed_actions: ['AMEND', 'CANCEL'], issued_at: '2026-09-11T12:00:00Z', issued_by: actor() });
    const cancelled = order({ ...issued, status: 'CANCELLED', record_version: 3, allowed_actions: [], cancelled_at: '2026-09-11T13:00:00Z', cancelled_by: actor(), cancellation_reason: 'Supplier cannot meet the confirmed delivery date.' });
    apiMocks.listPurchaseOrders.mockResolvedValue(workspace([draft]));
    apiMocks.getPurchaseOrder.mockResolvedValueOnce(draft).mockResolvedValueOnce(issued).mockResolvedValueOnce(cancelled);
    renderPage();

    await user.click(await screen.findByRole('button', { name: 'Open' }));
    await user.click(await screen.findByRole('button', { name: 'Issue purchase order' }));
    await waitFor(() => expect(apiMocks.issuePurchaseOrder).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'order-1', record_version: 1 }), expect.any(String),
    ));

    await user.type(await screen.findByLabelText('Cancellation reason'), 'Supplier cannot meet the confirmed delivery date.');
    await user.click(screen.getByRole('button', { name: 'Cancel purchase order' }));
    await waitFor(() => expect(apiMocks.cancelPurchaseOrder).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'order-1', record_version: 2 }),
      'Supplier cannot meet the confirmed delivery date.', expect.any(String),
    ));
    expect(await screen.findByText('Purchase order cancelled with reason evidence.')).toBeInTheDocument();
  });
});

function renderPage(value: ErpSession = operationsSession()) {
  return render(<ErpSessionContext.Provider value={value}><PurchaseOrderWorkspace /></ErpSessionContext.Provider>);
}

function workspace(data: PurchaseOrder[] = []): Workspace {
  return {
    data,
    meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length },
    summary: { total: data.length, draft: data.filter((item) => item.status === 'DRAFT').length, issued: data.filter((item) => item.status === 'ISSUED').length, cancelled: data.filter((item) => item.status === 'CANCELLED').length, committed_total: data.filter((item) => item.status !== 'CANCELLED').reduce((total, item) => total + Number(item.total_amount), 0).toFixed(2) },
    lookups: {
      statuses: ['DRAFT', 'ISSUED', 'CANCELLED'], sorts: ['NEWEST', 'OLDEST', 'NUMBER', 'STATUS', 'REQUIRED_DATE'], currency: 'INR',
      awarded_rfqs: [{ id: 'rfq-1', number: 'RFQ-UI-001', requisition_id: 'requisition-1', requisition_number: 'REQ-UI-001', supplier: supplier(), quote_id: 'quote-1', total_amount: '990.00', authority_ceiling: '1250.00', promised_delivery_date: '2026-09-24', payment_terms_days: 30 }],
    },
    allowed_actions: ['CREATE'],
  };
}

function order(overrides: Partial<PurchaseOrder> = {}): PurchaseOrder {
  return {
    id: 'order-1', company_id: 'company-1', plant_id: 'plant-1', po_number: 'PO-UI-001',
    rfq: { id: 'rfq-1', number: 'RFQ-UI-001', authority_ceiling: '1250.00' },
    requisition: { id: 'requisition-1', number: 'REQ-UI-001', department: 'Production', purpose: 'Replenish ingredient stock.' },
    supplier: supplier(), supplier_quote_id: 'quote-1', order_date: '2026-09-11', required_by_date: '2026-09-24',
    currency: 'INR', subtotal: '990.00', freight_amount: '0.00', other_charges: '0.00', discount_amount: '0.00',
    total_amount: '990.00', payment_terms_days: 30, incoterm_code: 'DAP', delivery_terms: 'Deliver to receiving bay.',
    notes: null, status: 'DRAFT', record_version: 1, revision_number: 1, line_count: 1, created_by: actor(),
    issued_at: null, issued_by: null, cancelled_at: null, cancelled_by: null, cancellation_reason: null,
    allowed_actions: ['AMEND', 'ISSUE', 'CANCEL'], created_at: '2026-09-11T11:00:00Z', updated_at: '2026-09-11T11:00:00Z',
    lines: [{ id: 'order-line-1', supplier_quote_line_id: 'quote-line-1', rfq_line_id: 'rfq-line-1', requisition_line_id: 'req-line-1', line_number: 1, item: { id: 'item-1', code: 'RAW-APPLE', name: 'Apple concentrate', item_type: 'RAW_MATERIAL', status: 'ACTIVE' }, description: 'Apple concentrate', ordered_quantity: '20.000000', uom_code: 'KG', unit_price: '49.500000', line_total: '990.00', notes: null }],
    revisions: [revision(1, 'Initial order from awarded supplier quote.')],
    ...overrides,
  };
}

function revision(number: number, reason: string) {
  return { id: `revision-${number}`, revision_number: number, reason, snapshot: {}, created_by: actor(), created_at: `2026-09-11T1${number}:00:00Z` };
}

function supplier() { return { id: 'supplier-1', code: 'SUP-WEST', name: 'Western Ingredients', currency: 'INR', payment_terms_days: 30, incoterm_code: 'DAP' }; }
function actor() { return { id: 'manager-1', name: 'Operations Manager' }; }

function result(status: PurchaseOrderCommandResult['status'], version: number, revisionNumber: number): PurchaseOrderCommandResult {
  return { entity_type: 'purchase_order', id: 'order-1', status, record_version: version, revision_number: revisionNumber, line_count: 1, total_amount: version > 2 ? '1015.00' : '990.00' };
}

function operationsSession(): ErpSession {
  return {
    user: { ...actor(), email: 'operations@example.com' }, roles: ['OPERATIONS_MANAGER'],
    allowed_screens: ['PUR-PO'],
    allowed_actions: ['ACTION:PUR-PO:CREATE', 'ACTION:PUR-PO:AMEND', 'ACTION:PUR-PO:ISSUE', 'ACTION:PUR-PO:CANCEL'],
    contexts: [], selected_context: { company_id: 'company-1', company_name: 'Q & T Foods Ltd', plant_id: 'plant-1', plant_name: 'Training Plant' },
  };
}

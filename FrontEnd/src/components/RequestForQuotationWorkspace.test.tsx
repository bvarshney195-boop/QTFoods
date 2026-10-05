import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
  RequestForQuotation,
  RfqCommandResult,
  RfqWorkspace,
  SupplierQuote,
} from '../api/procurementSourcing';
import { ErpSessionContext } from '../app/ErpSessionContext';
import type { ErpSession } from '../types/session';
import { RequestForQuotationWorkspace } from './RequestForQuotationWorkspace';

const apiMocks = vi.hoisted(() => ({
  listRfqs: vi.fn(),
  getRfq: vi.fn(),
  createRfq: vi.fn(),
  updateRfq: vi.fn(),
  issueRfq: vi.fn(),
  recordSupplierQuote: vi.fn(),
  awardRfq: vi.fn(),
  cancelRfq: vi.fn(),
}));

vi.mock('../api/procurementSourcing', async () => {
  const actual = await vi.importActual<typeof import('../api/procurementSourcing')>('../api/procurementSourcing');
  return { ...actual, ...apiMocks };
});

describe('request for quotation workspace', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    apiMocks.listRfqs.mockResolvedValue(workspace());
    apiMocks.getRfq.mockResolvedValue(rfq());
    apiMocks.createRfq.mockResolvedValue(result('DRAFT', 1));
    apiMocks.updateRfq.mockResolvedValue(result('DRAFT', 2));
    apiMocks.issueRfq.mockResolvedValue(result('ISSUED', 2));
    apiMocks.recordSupplierQuote.mockResolvedValue({ ...result('ISSUED', 3), quote_id: 'quote-1', quote_total: '990.00' });
    apiMocks.awardRfq.mockResolvedValue({
      ...result('AWARDED', 4), awarded_supplier_id: 'supplier-2', awarded_quote_id: 'quote-2', awarded_total: '1010.00',
    });
    apiMocks.cancelRfq.mockResolvedValue(result('CANCELLED', 2));
  });

  it('creates a draft from approved requisition lines and at least two governed suppliers', async () => {
    const user = userEvent.setup();
    renderPage();

    expect(await screen.findByRole('heading', { name: 'Supplier quotations' })).toBeInTheDocument();
    await user.click(await screen.findByRole('button', { name: '+ New' }));
    await user.type(screen.getByLabelText('RFQ number'), 'rfq-ui-001');
    await user.selectOptions(screen.getByLabelText('Approved requisition'), 'requisition-1');
    fireEvent.change(screen.getByLabelText('Response due date'), { target: { value: '2026-09-14' } });
    await user.click(screen.getByLabelText(/Western Ingredients/));
    await user.click(screen.getByLabelText(/Deccan Packaging/));
    await user.type(screen.getByLabelText('Commercial instructions'), 'Quote landed cost and batch certificate details.');
    await user.click(screen.getByRole('button', { name: 'Create draft RFQ' }));

    await waitFor(() => expect(apiMocks.createRfq).toHaveBeenCalledWith({
      rfq_number: 'RFQ-UI-001',
      requisition_id: 'requisition-1',
      response_due_date: '2026-09-14',
      commercial_terms: 'Quote landed cost and batch certificate details.',
      supplier_ids: ['supplier-1', 'supplier-2'],
    }, expect.any(String)));
    expect(await screen.findByText('Draft RFQ created from the approved requisition.')).toBeInTheDocument();
  });

  it('issues the current version and records a complete supplier response', async () => {
    const user = userEvent.setup();
    const draft = rfq();
    const issued = rfq({ status: 'ISSUED', record_version: 2, allowed_actions: ['RECORD_QUOTE', 'CANCEL'], issued_at: '2026-09-11T10:00:00Z', issued_by: actor() });
    const responded = rfq({
      ...issued,
      record_version: 3,
      quote_count: 1,
      suppliers: [
        { supplier: supplier('supplier-1', 'SUP-WEST', 'Western Ingredients'), status: 'RESPONDED', invited_at: '2026-09-11T10:00:00Z', responded_at: '2026-09-11T11:00:00Z', quote: quote() },
        { supplier: supplier('supplier-2', 'SUP-DECCAN', 'Deccan Packaging'), status: 'INVITED', invited_at: '2026-09-11T10:00:00Z', responded_at: null, quote: null },
      ],
    });
    apiMocks.listRfqs.mockResolvedValue(workspace([draft]));
    apiMocks.getRfq.mockResolvedValueOnce(draft).mockResolvedValueOnce(issued).mockResolvedValueOnce(responded);
    renderPage();

    await user.click(await screen.findByRole('button', { name: 'Open' }));
    await user.click(await screen.findByRole('button', { name: 'Issue RFQ' }));
    await waitFor(() => expect(apiMocks.issueRfq).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'rfq-1', record_version: 1 }), expect.any(String),
    ));

    await user.click(await screen.findByRole('button', { name: 'Record supplier quote' }));
    await user.selectOptions(screen.getByLabelText('Quote supplier'), 'supplier-1');
    await user.type(screen.getByLabelText('Supplier quote number'), 'west-1001');
    fireEvent.change(screen.getByLabelText('Quote date'), { target: { value: '2026-09-11' } });
    fireEvent.change(screen.getByLabelText('Valid until'), { target: { value: '2026-09-30' } });
    fireEvent.change(screen.getByLabelText('Promised delivery'), { target: { value: '2026-09-24' } });
    fireEvent.change(screen.getByLabelText('Line 1 unit price'), { target: { value: '49.5' } });
    await user.click(screen.getByRole('button', { name: 'Record supplier quote' }));

    await waitFor(() => expect(apiMocks.recordSupplierQuote).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'rfq-1', record_version: 2 }),
      expect.objectContaining({
        supplier_party_id: 'supplier-1', quote_number: 'WEST-1001', quote_date: '2026-09-11',
        valid_until: '2026-09-30', promised_delivery_date: '2026-09-24',
        lines: [{ rfq_line_id: 'rfq-line-1', unit_price: '49.5', notes: null }],
      }),
      expect.any(String),
    ));
    expect(await screen.findByText('Supplier quote recorded and comparison refreshed.')).toBeInTheDocument();
  });

  it('awards the selected comparison row with non-lowest-offer evidence', async () => {
    const user = userEvent.setup();
    const comparable = rfq({
      status: 'ISSUED', record_version: 3, quote_count: 2, allowed_actions: ['RECORD_QUOTE', 'AWARD', 'CANCEL'],
      comparison: [
        { rank: 1, quote_id: 'quote-1', supplier: supplier('supplier-1', 'SUP-WEST', 'Western Ingredients'), total_amount: '990.00', variance_from_lowest: '0.00', promised_delivery_date: '2026-09-28', meets_required_date: false, payment_terms_days: 30, valid_until: '2026-09-30' },
        { rank: 2, quote_id: 'quote-2', supplier: supplier('supplier-2', 'SUP-DECCAN', 'Deccan Packaging'), total_amount: '1010.00', variance_from_lowest: '20.00', promised_delivery_date: '2026-09-24', meets_required_date: true, payment_terms_days: 45, valid_until: '2026-10-01' },
      ],
    });
    apiMocks.listRfqs.mockResolvedValue(workspace([comparable]));
    apiMocks.getRfq.mockResolvedValueOnce(comparable).mockResolvedValueOnce(rfq({ ...comparable, status: 'AWARDED', record_version: 4, allowed_actions: ['CREATE_PO'] }));
    renderPage();

    await user.click(await screen.findByRole('button', { name: 'Open' }));
    await user.click((await screen.findAllByRole('button', { name: 'Select' }))[1]);
    await user.type(screen.getByLabelText('Award reason'), 'Earlier compliant delivery protects the production plan.');
    await user.click(screen.getByRole('button', { name: 'Award selected quote' }));

    await waitFor(() => expect(apiMocks.awardRfq).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'rfq-1', record_version: 3 }),
      'quote-2', 'Earlier compliant delivery protects the production plan.', expect.any(String),
    ));
    expect(await screen.findByText('Supplier award recorded with comparison evidence.')).toBeInTheDocument();
  });
});

function renderPage(value: ErpSession = operationsSession()) {
  return render(<ErpSessionContext.Provider value={value}><RequestForQuotationWorkspace /></ErpSessionContext.Provider>);
}

function workspace(data: RequestForQuotation[] = []): RfqWorkspace {
  return {
    data,
    meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length },
    summary: { total: data.length, draft: data.filter((item) => item.status === 'DRAFT').length, issued: data.filter((item) => item.status === 'ISSUED').length, awarded: data.filter((item) => item.status === 'AWARDED').length, cancelled: data.filter((item) => item.status === 'CANCELLED').length, awarded_total: '0.00' },
    lookups: {
      statuses: ['DRAFT', 'ISSUED', 'AWARDED', 'CANCELLED'], sorts: ['NEWEST', 'OLDEST', 'NUMBER', 'STATUS', 'DUE_DATE'],
      approved_requisitions: [{ id: 'requisition-1', number: 'REQ-UI-001', department: 'Production', purpose: 'Replenish ingredient stock.', required_by_date: '2026-09-25', currency: 'INR', estimated_total: '1250.00', record_version: 3 }],
      suppliers: [supplier('supplier-1', 'SUP-WEST', 'Western Ingredients'), supplier('supplier-2', 'SUP-DECCAN', 'Deccan Packaging')],
      currency: 'INR',
    },
    allowed_actions: ['CREATE'],
  };
}

function rfq(overrides: Partial<RequestForQuotation> = {}): RequestForQuotation {
  return {
    id: 'rfq-1', company_id: 'company-1', plant_id: 'plant-1', rfq_number: 'RFQ-UI-001',
    requisition: { id: 'requisition-1', number: 'REQ-UI-001', department: 'Production', purpose: 'Replenish ingredient stock.' },
    status: 'DRAFT', currency: 'INR', estimated_total_snapshot: '1250.00', response_due_date: '2026-09-14',
    required_by_date: '2026-09-25', commercial_terms: 'Quote landed cost.', record_version: 1,
    line_count: 1, supplier_count: 2, quote_count: 0, created_by: actor(), issued_at: null, issued_by: null,
    awarded_at: null, awarded_by: null, award: null, cancelled_at: null, cancelled_by: null,
    cancellation_reason: null, has_active_purchase_order: false, allowed_actions: ['UPDATE', 'ISSUE', 'CANCEL'],
    created_at: '2026-09-11T09:00:00Z', updated_at: '2026-09-11T09:00:00Z',
    lines: [{ id: 'rfq-line-1', requisition_line_id: 'req-line-1', line_number: 1, item: { id: 'item-1', code: 'RAW-APPLE', name: 'Apple concentrate', item_type: 'RAW_MATERIAL', status: 'ACTIVE' }, description: 'Apple concentrate', quantity: '20.000000', uom_code: 'KG', notes: null }],
    suppliers: [
      { supplier: supplier('supplier-1', 'SUP-WEST', 'Western Ingredients'), status: 'SELECTED', invited_at: null, responded_at: null, quote: null },
      { supplier: supplier('supplier-2', 'SUP-DECCAN', 'Deccan Packaging'), status: 'SELECTED', invited_at: null, responded_at: null, quote: null },
    ],
    comparison: [],
    ...overrides,
  };
}

function supplier(id: string, code: string, name: string) {
  return { id, code, name, currency: 'INR', payment_terms_days: 30, incoterm_code: 'DAP' };
}

function quote(): SupplierQuote {
  return {
    id: 'quote-1', supplier: supplier('supplier-1', 'SUP-WEST', 'Western Ingredients'), quote_number: 'WEST-1001',
    quote_date: '2026-09-11', valid_until: '2026-09-30', promised_delivery_date: '2026-09-24', payment_terms_days: 30,
    currency: 'INR', subtotal: '990.00', freight_amount: '0.00', other_charges: '0.00', discount_amount: '0.00',
    total_amount: '990.00', status: 'SUBMITTED', notes: null, record_version: 1, submitted_at: '2026-09-11T11:00:00Z',
    lines: [{ id: 'quote-line-1', rfq_line_id: 'rfq-line-1', line_number: 1, quantity: '20.000000', uom_code: 'KG', unit_price: '49.500000', line_total: '990.00', notes: null }],
  };
}

function result(status: RfqCommandResult['status'], version: number): RfqCommandResult {
  return { entity_type: 'request_for_quotation', id: 'rfq-1', status, record_version: version, line_count: 1, supplier_count: 2, quote_count: 0 };
}

function actor() { return { id: 'manager-1', name: 'Operations Manager' }; }

function operationsSession(): ErpSession {
  return {
    user: { ...actor(), email: 'operations@example.com' }, roles: ['OPERATIONS_MANAGER'],
    allowed_screens: ['PUR-RFQ'],
    allowed_actions: ['ACTION:PUR-RFQ:CREATE', 'ACTION:PUR-RFQ:UPDATE', 'ACTION:PUR-RFQ:ISSUE', 'ACTION:PUR-RFQ:QUOTE', 'ACTION:PUR-RFQ:AWARD', 'ACTION:PUR-RFQ:CANCEL'],
    contexts: [], selected_context: { company_id: 'company-1', company_name: 'Q & T Foods Ltd', plant_id: 'plant-1', plant_name: 'Training Plant' },
  };
}

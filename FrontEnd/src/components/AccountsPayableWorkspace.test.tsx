import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { PayableInvoice, PayablesWorkspace, PaymentProposal, SupplierPayment } from '../api/procureToPay';
import { ErpSessionContext } from '../app/ErpSessionContext';
import type { ErpSession } from '../types/session';
import { AccountsPayableWorkspace } from './AccountsPayableWorkspace';

const apiMocks = vi.hoisted(() => ({
  listPayables: vi.fn(), getPayableInvoice: vi.fn(), createPayableInvoice: vi.fn(), updatePayableInvoice: vi.fn(), matchPayableInvoice: vi.fn(), approvePayableInvoice: vi.fn(), cancelPayableInvoice: vi.fn(),
  getPaymentProposal: vi.fn(), createPaymentProposal: vi.fn(), updatePaymentProposal: vi.fn(), approvePaymentProposal: vi.fn(), cancelPaymentProposal: vi.fn(), executePaymentProposal: vi.fn(),
  getSupplierPayment: vi.fn(), reconcileSupplierPayment: vi.fn(),
}));

vi.mock('../api/procureToPay', async () => {
  const actual = await vi.importActual<typeof import('../api/procureToPay')>('../api/procureToPay');
  return { ...actual, ...apiMocks };
});

describe('accounts payable workspace', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    apiMocks.listPayables.mockResolvedValue(workspace());
  });

  it('creates a tax-calculated payable and runs the three-way match', async () => {
    const user = userEvent.setup();
    const draft = invoice();
    const matched = invoice({ status: 'MATCHED', record_version: 2, allowed_actions: ['APPROVE', 'CANCEL'], matched_by: actor(), matched_at: '2026-09-11T11:00:00Z', match_summary: { result: 'PASS', exceptions: [] }, lines: invoice().lines!.map((line) => ({ ...line, match_result: 'PASS' })) });
    apiMocks.createPayableInvoice.mockResolvedValue(command('invoice-1', 'DRAFT'));
    apiMocks.matchPayableInvoice.mockResolvedValue(command('invoice-1', 'MATCHED', 2));
    apiMocks.getPayableInvoice.mockResolvedValueOnce(draft).mockResolvedValueOnce(matched);
    renderPage();

    await user.click(await screen.findByRole('button', { name: '+ New' }));
    await user.selectOptions(screen.getByLabelText('Issued purchase order'), 'po-1');
    await user.type(screen.getByLabelText('AP number'), 'ap-ui-001');
    await user.type(screen.getByLabelText('Supplier invoice'), 'WEST-INV-001');
    fireEvent.change(screen.getByLabelText('Invoice line 1 quantity'), { target: { value: '8' } });
    fireEvent.change(screen.getByLabelText('Invoice line 1 tax rate'), { target: { value: '18' } });
    expect(screen.getAllByText(/₹377\.60/)).toHaveLength(2);
    await user.click(screen.getByRole('button', { name: 'Create draft invoice' }));

    await waitFor(() => expect(apiMocks.createPayableInvoice).toHaveBeenCalledWith(expect.objectContaining({
      ap_number: 'AP-UI-001', supplier_invoice_number: 'WEST-INV-001', purchase_order_id: 'po-1',
      lines: [{ purchase_order_line_id: 'po-line-1', invoice_quantity: '8', invoice_unit_price: '40.000000', tax_rate: '18' }],
    }), expect.any(String)));
    await user.click(await screen.findByRole('button', { name: 'Run three-way match' }));
    await waitFor(() => expect(apiMocks.matchPayableInvoice).toHaveBeenCalledWith(expect.objectContaining({ id: 'invoice-1', record_version: 1 }), expect.any(String)));
    expect(await screen.findByText('Three-way match completed.')).toBeInTheDocument();
  });

  it('creates a governed payment proposal from an approved outstanding invoice', async () => {
    const user = userEvent.setup();
    const draft = proposal();
    apiMocks.createPaymentProposal.mockResolvedValue(command('proposal-1', 'DRAFT'));
    apiMocks.getPaymentProposal.mockResolvedValue(draft);
    renderPage();

    await user.click(await screen.findByRole('button', { name: 'Payment proposals' }));
    await user.click(await screen.findByRole('button', { name: '+ New' }));
    await user.selectOptions(screen.getByLabelText('Approved invoice'), 'invoice-1');
    await user.type(screen.getByLabelText('Proposal number'), 'payprop-ui-001');
    await user.type(screen.getByLabelText('Notes'), 'Include in the next controlled payment run.');
    await user.click(screen.getByRole('button', { name: 'Create payment proposal' }));

    await waitFor(() => expect(apiMocks.createPaymentProposal).toHaveBeenCalledWith(expect.objectContaining({
      proposal_number: 'PAYPROP-UI-001', notes: 'Include in the next controlled payment run.',
      lines: [{ invoice_id: 'invoice-1', proposed_amount: '377.600000' }],
    }), expect.any(String)));
    expect(await screen.findByText('Draft payment proposal created.')).toBeInTheDocument();
  });

  it('opens an editable payable invoice directly in edit mode and retains its historical order', async () => {
    const user = userEvent.setup();
    const draft = invoice();
    const source = workspace({ data: [draft] });
    source.lookups.eligible_orders = [];
    apiMocks.listPayables.mockResolvedValue(source);
    apiMocks.getPayableInvoice.mockResolvedValue(draft);
    apiMocks.updatePayableInvoice.mockResolvedValue(command('invoice-1', 'DRAFT', 2));
    renderPage();

    await user.click(await screen.findByRole('button', { name: 'Open' }));
    expect(await screen.findByRole('button', { name: 'Save and rematch' })).toBeInTheDocument();
    expect(screen.getByLabelText('Issued purchase order')).toHaveValue('po-1');
    expect(screen.getByRole('option', { name: 'PO-UI-001 · Western Ingredients' })).toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Due date'), { target: { value: '2026-10-20' } });
    await user.click(screen.getByRole('button', { name: 'Save and rematch' }));

    await waitFor(() => expect(apiMocks.updatePayableInvoice).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'invoice-1', record_version: 1 }),
      expect.objectContaining({ due_date: '2026-10-20' }),
      expect.any(String),
    ));
  });

  it('opens an editable payment proposal directly and retains its selected invoice', async () => {
    const user = userEvent.setup();
    const draft = proposal();
    const source = workspace({ proposals: [draft] });
    source.lookups.payable_invoices = [];
    apiMocks.listPayables.mockResolvedValue(source);
    apiMocks.getPaymentProposal.mockResolvedValue(draft);
    renderPage();

    await user.click(await screen.findByRole('button', { name: 'Payment proposals' }));
    await user.click(await screen.findByRole('button', { name: 'Open' }));
    expect(await screen.findByRole('button', { name: 'Save proposal' })).toBeInTheDocument();
    expect(screen.getByLabelText('Approved invoice')).toHaveValue('invoice-1');
    expect(screen.getByRole('option', { name: /AP-UI-001 · Western Ingredients/ })).toBeInTheDocument();
  });

  it('posts an approved proposal, filters payments, and reconciles the bank statement', async () => {
    const user = userEvent.setup();
    const approved = proposal({ status: 'APPROVED', record_version: 2, approved_by: { id: 'admin-1', name: 'Administrator' }, approved_at: '2026-09-11T12:00:00Z', allowed_actions: ['EXECUTE', 'CANCEL'] });
    const posted = payment();
    const reconciled = payment({ status: 'RECONCILED', record_version: 2, allowed_actions: [], reconciled_by: actor(), reconciled_at: '2026-09-12T09:00:00Z', reconciliation: { id: 'reconciliation-1', statement_date: '2026-09-12', statement_reference: 'STMT-UI-001', notes: 'Value and UTR agree.', reconciled_by: actor(), created_at: '2026-09-12T09:00:00Z' } });
    apiMocks.listPayables.mockResolvedValue(workspace({ proposals: [approved], payments: [posted] }));
    apiMocks.getPaymentProposal.mockResolvedValue(approved);
    apiMocks.executePaymentProposal.mockResolvedValue({ ...command('proposal-1', 'EXECUTED', 3), payment_id: 'payment-1', payment_status: 'POSTED' });
    apiMocks.getSupplierPayment.mockResolvedValueOnce(posted).mockResolvedValueOnce(posted).mockResolvedValueOnce(reconciled);
    apiMocks.reconcileSupplierPayment.mockResolvedValue(command('payment-1', 'RECONCILED', 2));
    renderPage();

    await user.click(await screen.findByRole('button', { name: 'Payment proposals' }));
    await user.click(await screen.findByRole('button', { name: 'Open' }));
    await user.type(screen.getByLabelText('Payment number'), 'PAY-UI-001');
    await user.type(screen.getByLabelText('Bank reference'), 'UTR-UI-001');
    await user.click(screen.getByRole('button', { name: 'Post payment' }));
    await waitFor(() => expect(apiMocks.executePaymentProposal).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'proposal-1', record_version: 2 }),
      expect.objectContaining({ payment_number: 'PAY-UI-001', method: 'BANK_TRANSFER', bank_reference: 'UTR-UI-001' }),
      expect.any(String),
    ));

    await user.selectOptions(screen.getByLabelText('Status'), 'POSTED');
    await user.type(screen.getByLabelText('Search'), 'UTR-UI-001');
    await waitFor(() => expect(apiMocks.listPayables).toHaveBeenCalledWith(expect.objectContaining({ q: 'UTR-UI-001', payment_status: 'POSTED' })));
    fireEvent.change(screen.getByLabelText('Statement date'), { target: { value: '2026-09-12' } });
    await user.type(screen.getByLabelText('Statement reference'), 'STMT-UI-001');
    await user.type(screen.getByLabelText('Notes'), 'Value and UTR agree.');
    await user.click(screen.getByRole('button', { name: 'Reconcile payment' }));

    await waitFor(() => expect(apiMocks.reconcileSupplierPayment).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'payment-1', record_version: 1 }),
      { statement_date: '2026-09-12', statement_reference: 'STMT-UI-001', notes: 'Value and UTR agree.' },
      expect.any(String),
    ));
    expect(await screen.findByText('Payment reconciled to the bank statement.')).toBeInTheDocument();
  });
});

function renderPage() {
  return render(<ErpSessionContext.Provider value={session()}><AccountsPayableWorkspace /></ErpSessionContext.Provider>);
}

function actor() { return { id: 'finance-1', name: 'Finance Reviewer' }; }
function supplier() { return { id: 'supplier-1', code: 'SUP-WEST', name: 'Western Ingredients' }; }
function command(id: string, status: string, recordVersion = 1) { return { id, status, record_version: recordVersion }; }

function workspace(overrides: Partial<PayablesWorkspace> = {}): PayablesWorkspace {
  return {
    data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 }, proposals: [], payments: [],
    summary: { invoice_count: 0, approved_outstanding: '377.600000', match_exceptions: 0, draft_proposals: 0, unreconciled_payments: 1 },
    lookups: {
      invoice_statuses: ['DRAFT', 'MATCH_EXCEPTION', 'MATCHED', 'APPROVED', 'PARTIALLY_PAID', 'PAID', 'CANCELLED'],
      proposal_statuses: ['DRAFT', 'APPROVED', 'EXECUTED', 'CANCELLED'], payment_statuses: ['POSTED', 'RECONCILED'],
      payment_methods: ['BANK_TRANSFER', 'CHEQUE', 'UPI'], currency: 'INR',
      eligible_orders: [{ id: 'po-1', number: 'PO-UI-001', payment_terms_days: 30, supplier: supplier(), lines: [{ id: 'po-line-1', line_number: 1, item: { id: 'item-1', code: 'RAW-APPLE', name: 'Apple concentrate' }, description: 'Apple concentrate', ordered_quantity: '10.000000', accepted_quantity: '8.000000', invoiced_quantity: '0.000000', available_quantity: '8.000000', uom_code: 'KG', unit_price: '40.000000' }] }],
      payable_invoices: [{ id: 'invoice-1', ap_number: 'AP-UI-001', supplier_invoice_number: 'WEST-INV-001', due_date: '2026-10-11', supplier: { code: 'SUP-WEST', name: 'Western Ingredients' }, total_amount: '377.600000', paid_amount: '0.000000', outstanding_amount: '377.600000', uncommitted_amount: '377.600000' }],
    },
    allowed_actions: ['CREATE_INVOICE', 'CREATE_PROPOSAL'], ...overrides,
  };
}

function invoice(overrides: Partial<PayableInvoice> = {}): PayableInvoice {
  return {
    id: 'invoice-1', ap_number: 'AP-UI-001', supplier_invoice_number: 'WEST-INV-001', purchase_order: { id: 'po-1', number: 'PO-UI-001' }, supplier: supplier(), invoice_date: '2026-09-11', due_date: '2026-10-11', currency: 'INR', subtotal: '320.000000', tax_amount: '57.600000', total_amount: '377.600000', paid_amount: '0.000000', outstanding_amount: '377.600000', match_summary: null, notes: null, status: 'DRAFT', record_version: 1, line_count: 1, created_by: actor(), matched_by: null, approved_by: null, matched_at: null, approved_at: null, cancelled_at: null, cancellation_reason: null, allowed_actions: ['UPDATE', 'MATCH', 'CANCEL'], allocations: [], lines: [{ id: 'invoice-line-1', purchase_order_line_id: 'po-line-1', line_number: 1, item: { id: 'item-1', code: 'RAW-APPLE', name: 'Apple concentrate' }, description: 'Apple concentrate', ordered_quantity: '10.000000', accepted_quantity: '8.000000', invoice_quantity: '8.000000', uom_code: 'KG', po_unit_price: '40.000000', invoice_unit_price: '40.000000', tax_rate: '18.0000', net_amount: '320.000000', tax_amount: '57.600000', gross_amount: '377.600000', match_result: 'PENDING', match_exception: null }], ...overrides,
  };
}

function proposal(overrides: Partial<PaymentProposal> = {}): PaymentProposal {
  return { id: 'proposal-1', proposal_number: 'PAYPROP-UI-001', payment_date: '2026-09-12', currency: 'INR', total_amount: '377.600000', notes: 'Controlled payment run.', status: 'DRAFT', record_version: 1, line_count: 1, created_by: actor(), approved_by: null, approved_at: null, executed_at: null, cancelled_at: null, cancellation_reason: null, allowed_actions: ['UPDATE', 'APPROVE', 'CANCEL'], lines: [{ id: 'proposal-line-1', invoice_id: 'invoice-1', line_number: 1, ap_number: 'AP-UI-001', supplier_invoice_number: 'WEST-INV-001', supplier: { code: 'SUP-WEST', name: 'Western Ingredients' }, invoice_total: '377.600000', invoice_paid: '0.000000', proposed_amount: '377.600000' }], payment: null, ...overrides };
}

function payment(overrides: Partial<SupplierPayment> = {}): SupplierPayment {
  return { id: 'payment-1', payment_number: 'PAY-UI-001', proposal: { id: 'proposal-1', number: 'PAYPROP-UI-001' }, payment_date: '2026-09-12', method: 'BANK_TRANSFER', bank_reference: 'UTR-UI-001', currency: 'INR', total_amount: '377.600000', status: 'POSTED', record_version: 1, created_by: actor(), reconciled_by: null, reconciled_at: null, allowed_actions: ['RECONCILE'], allocations: [], reconciliation: null, ...overrides };
}

function session(): ErpSession {
  return { user: { ...actor(), email: 'finance@example.com' }, roles: ['FINANCE_REVIEWER'], allowed_screens: ['FIN-AP'], allowed_actions: [], contexts: [], selected_context: { company_id: 'company-1', company_name: 'Q & T Foods', plant_id: 'plant-1', plant_name: 'Training Plant' } };
}

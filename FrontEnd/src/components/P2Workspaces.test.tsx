import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ErpSessionContext } from '../app/ErpSessionContext';
import type { ErpSession } from '../types/session';
import { CommercialP2Workspace, commercialP2Configs, type CommercialP2Code } from './CommercialP2Workspaces';
import { FinanceArchiveWorkspace } from './FinanceArchiveWorkspace';
import { FinanceP2Workspace, PayablesIntegrationWorkspace, financeP2Configs, type FinanceP2Code } from './FinanceP2Workspaces';

const api = vi.hoisted(() => ({ listP2: vi.fn(), getP2: vi.fn(), commandP2: vi.fn(), uploadP2: vi.fn(), downloadP2: vi.fn() }));
vi.mock('../api/p2Operations', () => api);

describe('P2 commercial and finance workspaces', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    api.listP2.mockResolvedValue(emptyWorkspace());
    api.commandP2.mockResolvedValue({ id: 'result-1', status: 'POSTED', record_version: 2 });
    api.uploadP2.mockResolvedValue({ id: 'document-1', status: 'ARCHIVED', record_version: 1 });
  });

  it('renders every P2 screen from live workspace configuration with no prototype surface', async () => {
    for (const code of Object.keys(commercialP2Configs) as CommercialP2Code[]) {
      const view = renderPage(<CommercialP2Workspace screen={code} />);
      expect(await screen.findByRole('heading', { level: 1, name: commercialP2Configs[code].title })).toBeInTheDocument();
      expect(screen.queryByText(/prototype action only/i)).not.toBeInTheDocument();
      view.unmount();
    }
    for (const code of Object.keys(financeP2Configs) as FinanceP2Code[]) {
      const view = renderPage(<FinanceP2Workspace screen={code} />);
      expect(await screen.findByRole('heading', { level: 1, name: financeP2Configs[code].title })).toBeInTheDocument();
      expect(screen.queryByText(/prototype action only/i)).not.toBeInTheDocument();
      view.unmount();
    }
    const payables = renderPage(<PayablesIntegrationWorkspace />);
    expect(await screen.findByRole('heading', { level: 1, name: 'Payables Bank & Statutory Integrations' })).toBeInTheDocument();
    payables.unmount();
    expect(api.listP2).toHaveBeenCalledTimes(22);
  });

  it('executes a record action with its optimistic version and reloads detail', async () => {
    const user = userEvent.setup();
    const lead = { id: 'lead-1', lead_number: 'LEAD-UI-001', company_name: 'North Market', contact_name: 'Commercial Desk', enquiry_date: '2026-09-13', estimated_value: '25000', status: 'NEW', record_version: 1, allowed_actions: ['QUALIFY'] };
    api.listP2.mockResolvedValue({ ...emptyWorkspace(), data: [lead] });
    api.getP2.mockResolvedValue(lead);
    renderPage(<CommercialP2Workspace screen="CRM-LEAD" />);
    await user.click(await screen.findByRole('button', { name: 'Open' }));
    await user.click(await screen.findByRole('button', { name: 'Qualify' }));
    await waitFor(() => expect(api.commandP2).toHaveBeenCalledWith('/api/v1/sales/leads/lead-1/qualify', {}, 1));
    expect(await screen.findByText('Lead qualified.')).toBeInTheDocument();
  });

  it('creates an allocation from a live eligible order and forwards its source version', async () => {
    const user = userEvent.setup();
    api.listP2.mockResolvedValue({ ...emptyWorkspace(), lookups: { orders: [{ id: 'order-1', order_number: 'SO-UI-001', record_version: 7 }] }, allowed_actions: ['ALLOCATE'] });
    renderPage(<CommercialP2Workspace screen="DSP-PICK" />);
    await user.click(await screen.findByRole('button', { name: '+ New' }));
    await user.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(api.commandP2).toHaveBeenCalledWith('/api/v1/dispatch/orders/order-1/allocations', expect.objectContaining({ allocation_number: expect.stringMatching(/^ALLOC-/) }), 7));
  });

  it('opens the credit form from the Credit control tab header action', async () => {
    const user = userEvent.setup();
    api.listP2.mockResolvedValue({ ...emptyWorkspace(), lookups: { customers: [{ id: 'customer-1', name: 'Retail customer' }], items: [{ id: 'item-1', code: 'SKU-1', name: 'Item', base_uom: 'EA' }] }, allowed_actions: ['CREATE_PRICE_LIST', 'CREATE_CONTRACT', 'MANAGE_CREDIT'] });
    renderPage(<CommercialP2Workspace screen="CRM-PRICE" />);
    await user.click(await screen.findByRole('button', { name: 'Credit control' }));
    await user.click(screen.getByRole('button', { name: '+ New' }));
    expect(screen.getByRole('heading', { level: 2, name: 'Manage credit' })).toBeInTheDocument();
    expect(screen.getByLabelText('Credit Limit')).toBeInTheDocument();
  });

  it('refreshes receipt allocation and total when the selected invoice changes', async () => {
    const user = userEvent.setup();
    api.listP2.mockResolvedValue({ ...emptyWorkspace(), lookups: { open_invoices: [
      { id: 'invoice-1', invoice_number: 'INV-1', customer_party_id: 'customer-1', customer_name: 'Customer A', outstanding_amount: '2242' },
      { id: 'invoice-2', invoice_number: 'INV-2', customer_party_id: 'customer-2', customer_name: 'Customer B', outstanding_amount: '950' },
    ], customers: [{ id: 'customer-1', name: 'Customer A' }, { id: 'customer-2', name: 'Customer B' }], payment_methods: ['BANK', 'CASH'] }, allowed_actions: ['COLLECT'] });
    renderPage(<CommercialP2Workspace screen="FIN-AR" />);
    await user.click(await screen.findByRole('button', { name: '+ New' }));
    await user.selectOptions(screen.getByLabelText('Invoice'), 'invoice-2');
    expect(screen.getByLabelText('Amount')).toHaveValue(950);
    expect(screen.getByLabelText('Total Amount')).toHaveValue(950);
  });

  it('uses a labelled employee form and sends the same structured payroll payload', async () => {
    const user = userEvent.setup();
    api.listP2.mockResolvedValue({
      ...emptyWorkspace(),
      lookups: {
        accounts: [
          { id: 'expense-1', account_code: '6100', name: 'Payroll expense', account_type: 'EXPENSE' },
          { id: 'payable-1', account_code: '2200', name: 'Payroll payable', account_type: 'LIABILITY' },
        ],
        employees: [],
      },
      allowed_actions: ['EMPLOYEE-CREATE'],
    });
    renderPage(<FinanceP2Workspace screen="HR-PAY" />);

    await user.click(await screen.findByRole('button', { name: '+ New employee profile' }));
    expect(screen.queryByText('Command payload')).not.toBeInTheDocument();
    expect(screen.queryByRole('textbox', { name: /command payload/i })).not.toBeInTheDocument();

    await user.clear(screen.getByLabelText('Employee number'));
    await user.type(screen.getByLabelText('Employee number'), 'EMP-UI-001');
    await user.type(screen.getByLabelText('Name'), 'Packing Operator');
    await user.type(screen.getByLabelText('Department'), 'Packing');
    await user.clear(screen.getByLabelText('Monthly gross pay'));
    await user.type(screen.getByLabelText('Monthly gross pay'), '50000');
    await user.clear(screen.getByLabelText('Monthly deductions'));
    await user.type(screen.getByLabelText('Monthly deductions'), '50000');
    await user.selectOptions(screen.getByLabelText('Expense account'), 'expense-1');
    await user.selectOptions(screen.getByLabelText('Payroll payable account'), 'payable-1');
    await user.click(screen.getByRole('button', { name: 'Save' }));
    expect(await screen.findByText('Monthly deductions must be less than monthly gross pay.')).toBeInTheDocument();
    expect(api.commandP2).not.toHaveBeenCalled();

    await user.clear(screen.getByLabelText('Monthly deductions'));
    await user.type(screen.getByLabelText('Monthly deductions'), '5000');
    await user.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(api.commandP2).toHaveBeenCalledWith('/api/v1/finance/employees', {
      employee_number: 'EMP-UI-001',
      name: 'Packing Operator',
      department: 'Packing',
      monthly_gross: '50000',
      monthly_deductions: '5000',
      expense_account_id: 'expense-1',
      payable_account_id: 'payable-1',
    }, undefined));
  });

  it('uses fixed assets rather than ledger accounts for maintenance asset selection', async () => {
    const user = userEvent.setup();
    api.listP2.mockResolvedValue({ ...emptyWorkspace(), lookups: {
      assets: [{ id: 'asset-1', asset_number: 'AST-001', name: 'Packing conveyor', status: 'ACTIVE' }],
      accounts: [{ id: 'account-1', account_code: '550000', name: 'Maintenance expense', account_type: 'EXPENSE' }],
      priorities: ['LOW', 'MEDIUM', 'HIGH'],
    }, allowed_actions: ['CREATE'] });
    renderPage(<FinanceP2Workspace screen="ENG-MNT" />);
    await user.click(await screen.findByRole('button', { name: '+ New' }));
    const asset = screen.getByLabelText('Asset') as HTMLSelectElement;
    expect(Array.from(asset.options).map((option) => option.text)).toContain('AST-001 · Packing conveyor');
    expect(Array.from(asset.options).map((option) => option.text)).not.toContain('550000 · Maintenance expense');
  });

  it('uploads an archive document as multipart private evidence', async () => {
    const user = userEvent.setup();
    api.listP2.mockResolvedValue({ ...emptyWorkspace(), summary: { count: 0, size_bytes: 0 }, lookups: { document_types: ['SUPPLIER_INVOICE'] }, allowed_actions: ['UPLOAD'] });
    renderPage(<FinanceArchiveWorkspace />);
    await user.click(await screen.findByRole('button', { name: '+ New' }));
    const file = new File(['%PDF-1.4 private bill'], 'supplier-bill.pdf', { type: 'application/pdf' });
    await user.upload(screen.getByLabelText('Archive private document'), file);
    await user.click(screen.getByRole('button', { name: 'Upload privately' }));
    await waitFor(() => expect(api.uploadP2).toHaveBeenCalledTimes(1));
    const body = api.uploadP2.mock.calls[0][1] as FormData;
    expect(api.uploadP2.mock.calls[0][0]).toBe('/api/v1/finance/archive');
    expect(body.get('file')).toBe(file);
    expect(body.get('document_type')).toBe('SUPPLIER_INVOICE');
    expect(await screen.findByText(/Document archived with verified private metadata/)).toBeInTheDocument();
  });
});

function renderPage(node: React.ReactNode) { return render(<ErpSessionContext.Provider value={session()}>{node}</ErpSessionContext.Provider>); }
function emptyWorkspace() { return { data: [], meta: { total: 0 }, summary: { count: 0 }, lookups: {}, allowed_actions: [] }; }
function session(): ErpSession { return { user: { id: 'user-1', name: 'ERP Administrator', email: 'admin@example.com' }, roles: ['ERP_ADMIN'], allowed_screens: [], allowed_actions: [], contexts: [], selected_context: { company_id: 'company-1', company_name: 'Q & T Foods', plant_id: 'plant-1', plant_name: 'Training Plant' } }; }

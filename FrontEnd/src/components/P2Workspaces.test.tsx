import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ErpSessionContext } from '../app/ErpSessionContext';
import type { P2Workspace } from '../api/p2Operations';
import type { ErpSession } from '../types/session';
import { screenLabel } from '../utils/displayText';
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
      expect(await screen.findByRole('heading', { level: 1, name: screenLabel(code, commercialP2Configs[code].title) })).toBeInTheDocument();
      expect(screen.queryByText(/prototype action only/i)).not.toBeInTheDocument();
      view.unmount();
    }
    for (const code of Object.keys(financeP2Configs) as FinanceP2Code[]) {
      const view = renderPage(<FinanceP2Workspace screen={code} />);
      expect(await screen.findByRole('heading', { level: 1, name: screenLabel(code, financeP2Configs[code].title) })).toBeInTheDocument();
      expect(screen.queryByText(/prototype action only/i)).not.toBeInTheDocument();
      view.unmount();
    }
    const payables = renderPage(<PayablesIntegrationWorkspace />);
    expect(await screen.findByRole('heading', { level: 1, name: 'Supplier invoices & payments' })).toBeInTheDocument();
    payables.unmount();
    expect(api.listP2).toHaveBeenCalledTimes(22);
  });

  it('executes a record action with its optimistic version and reloads detail', async () => {
    const user = userEvent.setup();
    const lead = { id: 'lead-1', lead_number: 'LEAD-UI-001', company_name: 'North Market', contact_name: 'Commercial Desk', enquiry_date: '2026-09-13', estimated_value: '25000', status: 'NEW', record_version: 1, allowed_actions: ['QUALIFY'] };
    api.listP2.mockResolvedValue({ ...emptyWorkspace(), data: [lead] });
    let resolveReload!: (value: typeof lead) => void;
    api.getP2
      .mockResolvedValueOnce(lead)
      .mockReturnValueOnce(new Promise((resolve) => { resolveReload = resolve; }));
    renderPage(<CommercialP2Workspace screen="CRM-LEAD" />);
    await user.click(await screen.findByRole('button', { name: 'Open' }));
    await user.click(await screen.findByRole('button', { name: 'Qualify' }));
    await waitFor(() => expect(api.commandP2).toHaveBeenCalledWith('/api/v1/sales/leads/lead-1/qualify', {}, 1));
    expect(screen.queryByText('Lead qualified.')).not.toBeInTheDocument();
    resolveReload({ ...lead, status: 'QUALIFIED', record_version: 2, allowed_actions: [] });
    expect(await screen.findByText('Lead qualified.')).toBeInTheDocument();
  });

  it('opens record details first and keeps lifecycle actions beside an explicit update action', async () => {
    const user = userEvent.setup();
    const lead = { id: 'lead-edit', lead_number: 'LEAD-EDIT-001', company_name: 'North Market', contact_name: 'Commercial Desk', contact_email: 'buyer@example.test', source: 'DIRECT', enquiry_date: '2026-09-13', estimated_value: '25000', status: 'NEW', record_version: 4, allowed_actions: ['UPDATE', 'QUALIFY'] };
    api.listP2.mockResolvedValue({ ...emptyWorkspace(), data: [lead], lookups: { sources: ['DIRECT', 'REFERRAL'], customers: [] } });
    api.getP2.mockResolvedValue(lead);
    renderPage(<CommercialP2Workspace screen="CRM-LEAD" />);

    await user.click(await screen.findByRole('button', { name: 'Open' }));

    expect(await screen.findByRole('dialog', { name: 'Record details' })).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Qualify' })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Update' }));
    expect(await screen.findByRole('dialog', { name: 'Update lead' })).toBeInTheDocument();
    expect(screen.getByLabelText('Company Name')).toHaveValue('North Market');
  });

  it('creates an allocation from a live eligible order and forwards its source version', async () => {
    const user = userEvent.setup();
    api.listP2.mockResolvedValue({
      ...emptyWorkspace(),
      lookups: { orders: [
        { id: 'order-1', order_number: 'SO-UI-001', customer_name: 'Adnan Traders', record_version: 7 },
        { id: 'order-2', order_number: 'SO-UI-002', customer_name: 'North Retail', record_version: 9 },
      ] },
      allowed_actions: ['ALLOCATE'],
    });
    renderPage(<CommercialP2Workspace screen="DSP-PICK" />);
    await user.click(await screen.findByRole('button', { name: '+ New' }));
    const order = screen.getByLabelText('Sales Order') as HTMLSelectElement;
    expect(order).toHaveValue('');
    expect(Array.from(order.options).map((option) => option.text)).toEqual([
      'Select sales order', 'SO-UI-001 · Adnan Traders', 'SO-UI-002 · North Retail',
    ]);
    await user.selectOptions(order, 'order-2');
    await user.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(api.commandP2).toHaveBeenCalledWith('/api/v1/dispatch/orders/order-2/allocations', expect.objectContaining({ sales_order_id: 'order-2', allocation_number: expect.stringMatching(/^ALLOC-/) }), 9));
  });

  it('shows the exact allocation failure instead of claiming an invisible field is highlighted', async () => {
    const user = userEvent.setup();
    api.listP2.mockResolvedValue({
      ...emptyWorkspace(),
      lookups: { orders: [{ id: 'order-1', order_number: 'SO-UI-001', customer_name: 'Adnan Traders', record_version: 7 }] },
      allowed_actions: ['ALLOCATE'],
    });
    api.commandP2.mockRejectedValueOnce({
      code: 'VALIDATION_ERROR', message: 'The submitted data was invalid.',
      fields: { stock: ['Insufficient released FEFO stock for order line 1.'] },
    });
    renderPage(<CommercialP2Workspace screen="DSP-PICK" />);

    await user.click(await screen.findByRole('button', { name: '+ New' }));
    await user.selectOptions(screen.getByLabelText('Sales Order'), 'order-1');
    await user.click(screen.getByRole('button', { name: 'Save' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('Insufficient released FEFO stock for order line 1.');
    expect(screen.getByRole('alert')).not.toHaveTextContent('Check the highlighted field');
  });

  it('opens the credit form from the Credit control tab header action', async () => {
    const user = userEvent.setup();
    api.listP2.mockResolvedValue({ ...emptyWorkspace(), lookups: { customers: [{ id: 'customer-1', name: 'Retail customer' }], items: [{ id: 'item-1', code: 'SKU-1', name: 'Item', base_uom: 'EA' }] }, allowed_actions: ['CREATE_PRICE_LIST', 'CREATE_CONTRACT', 'MANAGE_CREDIT'] });
    renderPage(<CommercialP2Workspace screen="CRM-PRICE" />);
    await user.click(await screen.findByRole('button', { name: 'Credit control' }));
    await user.click(screen.getByRole('button', { name: '+ New' }));
    expect(screen.getByRole('heading', { level: 2, name: 'Manage credit' })).toBeInTheDocument();
    expect(screen.getByLabelText('Credit Limit')).toBeInTheDocument();
    expect(screen.getByLabelText('Customer')).toHaveValue('');
  });

  it('keeps each pricing tab wired to its own creator when catalog prerequisites are missing', async () => {
    const user = userEvent.setup();
    api.listP2.mockResolvedValue({
      ...emptyWorkspace(),
      lookups: { customers: [{ id: 'customer-1', name: 'Retail customer' }], items: [] },
      allowed_actions: ['CREATE_PRICE_LIST', 'CREATE_CONTRACT', 'MANAGE_CREDIT'],
    });
    renderPage(<CommercialP2Workspace screen="CRM-PRICE" />);

    await user.click(await screen.findByRole('button', { name: '+ New' }));
    expect(screen.getByRole('heading', { level: 2, name: 'New price list' })).toBeInTheDocument();
    expect(screen.queryByRole('heading', { level: 2, name: 'Manage credit' })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Close' }));

    await user.click(screen.getByRole('button', { name: 'Contracts' }));
    await user.click(screen.getByRole('button', { name: '+ New' }));
    expect(screen.getByRole('heading', { level: 2, name: 'New contract' })).toBeInTheDocument();
    expect(screen.queryByRole('heading', { level: 2, name: 'Manage credit' })).not.toBeInTheDocument();
  });

  it('shows permissioned create actions even before order and shipment prerequisites exist', async () => {
    api.listP2.mockResolvedValue({ ...emptyWorkspace(), allowed_actions: ['CREATE'] });
    const orders = renderPage(<CommercialP2Workspace screen="CRM-ORDER" />);
    expect(await screen.findByRole('button', { name: '+ New' })).toBeInTheDocument();
    orders.unmount();

    const dispatch = renderPage(<CommercialP2Workspace screen="DSP-LOAD" />);
    expect(await screen.findByRole('button', { name: '+ New' })).toBeInTheDocument();
    dispatch.unmount();
  });

  it('starts lead, credit and contracted-work forms without silently selecting the first party', async () => {
    const user = userEvent.setup();
    api.listP2.mockResolvedValue({
      ...emptyWorkspace(),
      lookups: { customers: [{ id: 'customer-adnan', code: 'ADNAN', name: 'Adnan Traders' }], sources: ['DIRECT'] },
      allowed_actions: ['CREATE'],
    });
    const leads = renderPage(<CommercialP2Workspace screen="CRM-LEAD" />);
    await user.click(await screen.findByRole('button', { name: '+ New' }));
    expect(screen.getByLabelText('Customer')).toHaveValue('');
    expect(screen.getByLabelText('Company Name')).toHaveValue('');
    leads.unmount();

    api.listP2.mockResolvedValue({
      ...emptyWorkspace(),
      lookups: {
        statuses: ['DRAFT', 'RELEASED', 'COMPLETED', 'CANCELLED'], work_types: ['CO_PACKING'],
        providers: [{ id: 'provider-adnan', code: 'ADNAN', name: 'Adnan Traders' }],
        sales_orders: [{ id: 'order-1', order_number: 'SO-UI-001', customer_name: 'North Retail', status: 'CONFIRMED' }],
      },
      allowed_actions: ['CREATE'],
    });
    renderPage(<CommercialP2Workspace screen="CON-WORK" />);
    await user.click(await screen.findByRole('button', { name: '+ New' }));

    expect(screen.getByLabelText('Provider Party')).toHaveValue('');
    const salesOrder = screen.getByLabelText('Sales Order') as HTMLSelectElement;
    expect(salesOrder).toHaveValue('');
    expect(Array.from(salesOrder.options).map((option) => option.text)).toContain('SO-UI-001 · North Retail');
    expect(Array.from(salesOrder.options).map((option) => option.text)).not.toContain('ADNAN · Adnan Traders');

    const workType = screen.getByLabelText('Work Type') as HTMLSelectElement;
    expect(workType).toBeInstanceOf(HTMLSelectElement);
    expect(workType).toHaveValue('CO_PACKING');
    const workTypeOptions = Array.from(workType.options).map((option) => option.text);
    expect(workTypeOptions).not.toEqual(expect.arrayContaining(['Draft', 'Released', 'Completed', 'Cancelled']));

    const description = screen.getByLabelText('Description');
    const documentKeyDown = vi.fn();
    document.addEventListener('keydown', documentKeyDown);
    await user.type(description, 'Contract packing batch');
    document.removeEventListener('keydown', documentKeyDown);
    expect(description).toHaveValue('Contract packing batch');
    expect(documentKeyDown.mock.calls.some(([event]) => (event as KeyboardEvent).key === ' ')).toBe(false);
  });

  it('starts sales orders and customer contracts without silently selecting a customer', async () => {
    const user = userEvent.setup();
    const lookups = {
      customers: [{ id: 'customer-adnan', code: 'ADNAN', name: 'Adnan Traders' }],
      items: [{ id: 'item-1', code: 'BREAD', name: 'Bread', base_uom: 'EA' }],
      contracts: [{ id: 'contract-1', contract_number: 'CON-1', customer_party_id: 'customer-adnan', customer_name: 'Adnan Traders' }],
      price_lists: [], leads: [],
    };
    api.listP2.mockResolvedValue({ ...emptyWorkspace(), lookups, allowed_actions: ['CREATE'] });
    const orders = renderPage(<CommercialP2Workspace screen="CRM-ORDER" />);
    await user.click(await screen.findByRole('button', { name: '+ New' }));
    const orderCustomer = screen.getByLabelText('Customer') as HTMLSelectElement;
    expect(orderCustomer).toHaveValue('');
    expect(Array.from(orderCustomer.options).map((option) => option.text)).toContain('ADNAN · Adnan Traders');
    expect(screen.getByLabelText('Sales contract')).toHaveValue('');
    orders.unmount();

    api.listP2.mockResolvedValue({
      ...emptyWorkspace(), lookups, allowed_actions: ['CREATE_PRICE_LIST', 'CREATE_CONTRACT', 'MANAGE_CREDIT'],
    });
    renderPage(<CommercialP2Workspace screen="CRM-PRICE" />);
    await user.click(await screen.findByRole('button', { name: 'Contracts' }));
    await user.click(screen.getByRole('button', { name: '+ New' }));
    expect(screen.getByLabelText('Customer')).toHaveValue('');
  });

  it('uses the contracted-work identifier as the record heading', async () => {
    const user = userEvent.setup();
    const work = {
      id: 'work-1', work_number: 'TPW-UI-009', sales_order_id: 'order-1', order_number: 'SO-UI-001',
      provider_name: 'Packing Partner', work_type: 'CO_PACKING', status: 'DRAFT', record_version: 1,
      allowed_actions: [],
    };
    api.listP2.mockResolvedValue({ ...emptyWorkspace(), data: [work] });
    api.getP2.mockResolvedValue(work);
    renderPage(<CommercialP2Workspace screen="CON-WORK" />);

    await user.click(await screen.findByRole('button', { name: 'Open' }));
    expect(await screen.findByRole('heading', { level: 2, name: 'TPW-UI-009' })).toBeInTheDocument();
    expect(screen.queryByRole('heading', { level: 2, name: 'SO-UI-001' })).not.toBeInTheDocument();
  });

  it('limits lead conversion paths to conversion methods', async () => {
    const user = userEvent.setup();
    const lead = {
      id: 'lead-convert', lead_number: 'LEAD-CONVERT-001', company_name: 'New Retailer', contact_name: 'Buyer',
      enquiry_date: '2026-10-16', estimated_value: '25000', status: 'QUALIFIED', record_version: 1, allowed_actions: ['CONVERT'],
    };
    api.listP2.mockResolvedValue({
      ...emptyWorkspace(), data: [lead],
      lookups: { statuses: ['NEW', 'QUALIFIED', 'CONVERTED', 'WON', 'LOST'], customers: [{ id: 'customer-1', name: 'Retail customer' }] },
    });
    api.getP2.mockResolvedValue(lead);
    renderPage(<CommercialP2Workspace screen="CRM-LEAD" />);

    await user.click(await screen.findByRole('button', { name: 'Open' }));
    await user.click(await screen.findByRole('button', { name: 'Convert' }));
    const conversionPath = screen.getByLabelText('Conversion Path') as HTMLSelectElement;
    expect(Array.from(conversionPath.options).map((option) => option.text)).toEqual(['Existing Customer', 'Create Customer']);
    expect(conversionPath).toHaveValue('CREATE_CUSTOMER');
    expect(screen.getByLabelText('Customer')).toHaveValue('');
  });

  it('uses sales orders, not customer parties, for profitability cost backfill', async () => {
    const user = userEvent.setup();
    api.listP2.mockResolvedValue({
      ...emptyWorkspace(),
      data: [
        { id: 'order-1', order_number: 'SO-UI-001', customer: { id: 'customer-1', name: 'Adnan Traders' } },
        { id: 'order-2', order_number: 'SO-UI-002', customer: { id: 'customer-2', name: 'North Retail' } },
      ],
      lookups: { customers: [{ id: 'customer-1', code: 'ADNAN', name: 'Adnan Traders' }] },
      allowed_actions: ['COST-BACKFILL'],
    });
    renderPage(<CommercialP2Workspace screen="BI-PROFIT" />);

    await user.click(await screen.findByRole('button', { name: '+ New' }));
    const order = screen.getByLabelText('Order') as HTMLSelectElement;
    expect(Array.from(order.options).map((option) => option.text)).toEqual(['No order', 'SO-UI-001 · Adnan Traders', 'SO-UI-002 · North Retail']);
  });

  it('completes profitability cost backfill when the command result has counts but no status', async () => {
    const user = userEvent.setup();
    api.listP2.mockResolvedValue({ ...emptyWorkspace(), data: [], allowed_actions: ['COST-BACKFILL'] });
    api.commandP2.mockResolvedValueOnce({
      updated_count: 0,
      updated_line_ids: [],
      unavailable_count: 1,
      unavailable_line_ids: ['line-1'],
    });
    renderPage(<CommercialP2Workspace screen="BI-PROFIT" />);

    await user.click(await screen.findByRole('button', { name: '+ New' }));
    await user.click(screen.getByRole('button', { name: 'Save' }));

    expect(await screen.findByText('Backfill finalized costs completed: 0 lines updated; 1 line had no finalized cost.')).toBeInTheDocument();
  });

  it('keeps a permissioned finance create action visible and explains missing setup', async () => {
    const user = userEvent.setup();
    api.listP2.mockResolvedValue({ ...emptyWorkspace(), lookups: { users: [], accounts: [] }, allowed_actions: ['CREATE'] });
    renderPage(<FinanceP2Workspace screen="FIN-EXP" />);

    const action = await screen.findByRole('button', { name: '+ New expense claim' });
    expect(action).toHaveAttribute('aria-disabled', 'true');
    await user.click(action);
    expect(await screen.findByRole('alert')).toHaveTextContent('Add an eligible claimant before creating an expense claim.');
  });

  it('keeps sales-order UOM mapped to the selected item master', async () => {
    const user = userEvent.setup();
    api.listP2.mockResolvedValue({
      ...emptyWorkspace(),
      lookups: {
        statuses: ['DRAFT', 'CONFIRMED', 'PICKED'],
        customers: [{ id: 'customer-1', name: 'Retail customer' }],
        items: [
          { id: 'item-pack', code: 'SKU-MILLET-150', name: 'Millet Crunch Pack 150g', base_uom: 'PACK' },
          { id: 'item-service', code: 'SERVICE-DELIVERY', name: 'Delivery service', base_uom: 'EA' },
        ],
      },
      allowed_actions: ['CREATE'],
    });
    renderPage(<CommercialP2Workspace screen="CRM-ORDER" />);

    await user.click(await screen.findByRole('button', { name: '+ New' }));
    const unit = screen.getByLabelText('Unit of measure') as HTMLInputElement;
    expect(unit).toHaveValue('PACK');
    expect(unit).toHaveAttribute('readonly');
    expect(screen.queryByRole('option', { name: 'Picked' })).not.toBeInTheDocument();

    await user.selectOptions(screen.getByLabelText('Item'), 'item-service');
    await waitFor(() => expect(unit).toHaveValue('EA'));
  });

  it('announces loading, preserves the current register and filters on failure, and retries', async () => {
    const user = userEvent.setup();
    const lead = { id: 'lead-retry', lead_number: 'LEAD-RETRY-001', company_name: 'Recovery Retail', contact_name: 'Commercial Desk', enquiry_date: '2026-10-05', estimated_value: '25000', status: 'NEW', record_version: 1, allowed_actions: [] };
    let resolveInitial!: (value: P2Workspace) => void;
    api.listP2.mockReturnValueOnce(new Promise((resolve) => { resolveInitial = resolve; }));

    renderPage(<CommercialP2Workspace screen="CRM-LEAD" />);
    expect(screen.getByLabelText('Loading records')).toBeInTheDocument();
    expect(document.querySelector('.p2-workspace')).toHaveAttribute('aria-busy', 'true');

    resolveInitial({ ...emptyWorkspace(), data: [lead] });
    expect(await screen.findByText('LEAD-RETRY-001')).toBeInTheDocument();
    const search = screen.getByLabelText('Leads & Enquiries search');
    api.listP2.mockResolvedValueOnce({ ...emptyWorkspace(), data: [lead] });
    fireEvent.change(search, { target: { value: 'LEAD-RETRY' } });
    await waitFor(() => expect(api.listP2).toHaveBeenLastCalledWith('/api/v1/sales/leads', expect.objectContaining({ q: 'LEAD-RETRY' })));

    api.listP2.mockRejectedValueOnce(new Error('Service temporarily unavailable.'));
    await user.click(screen.getByRole('button', { name: 'Refresh' }));
    const alert = await screen.findByRole('alert');
    expect(alert).toHaveTextContent('Service temporarily unavailable.');
    expect(alert).toHaveTextContent('Your filters and entered values have been kept.');
    expect(search).toHaveValue('LEAD-RETRY');
    expect(screen.getByText('LEAD-RETRY-001')).toBeInTheDocument();

    api.listP2.mockResolvedValueOnce({ ...emptyWorkspace(), data: [lead] });
    await user.click(screen.getByRole('button', { name: 'Retry loading' }));
    await waitFor(() => expect(screen.queryByRole('alert')).not.toBeInTheDocument());
    expect(search).toHaveValue('LEAD-RETRY');
  });

  it('disables the command form while saving so duplicate submissions cannot occur', async () => {
    const user = userEvent.setup();
    let resolveCommand!: (value: { id: string; status: string; record_version: number }) => void;
    api.listP2.mockResolvedValue({ ...emptyWorkspace(), lookups: { orders: [{ id: 'order-1', order_number: 'SO-UI-001', record_version: 7 }] }, allowed_actions: ['ALLOCATE'] });
    api.commandP2.mockReturnValueOnce(new Promise((resolve) => { resolveCommand = resolve; }));
    renderPage(<CommercialP2Workspace screen="DSP-PICK" />);
    await user.click(await screen.findByRole('button', { name: '+ New' }));
    await user.selectOptions(screen.getByLabelText('Sales Order'), 'order-1');
    await user.click(screen.getByRole('button', { name: 'Save' }));

    const saving = await screen.findByRole('button', { name: 'Saving…' });
    expect(saving).toBeDisabled();
    expect(api.commandP2).toHaveBeenCalledTimes(1);
    await user.click(saving);
    expect(api.commandP2).toHaveBeenCalledTimes(1);

    resolveCommand({ id: 'allocation-1', status: 'ALLOCATED', record_version: 1 });
    expect(await screen.findByText(/saved successfully/i)).toBeInTheDocument();
  });

  it('uses business quantity wording, links the inline error and focuses the invalid field', async () => {
    const user = userEvent.setup();
    api.listP2.mockResolvedValue({
      ...emptyWorkspace(),
      lookups: {
        statuses: ['DRAFT', 'CONFIRMED'],
        customers: [{ id: 'customer-1', name: 'Retail customer' }],
        items: [{ id: 'item-pack', code: 'SKU-PACK', name: 'Retail pack', base_uom: 'PACK' }],
        contracts: [],
        price_lists: [],
        leads: [],
      },
      allowed_actions: ['CREATE'],
    });
    renderPage(<CommercialP2Workspace screen="CRM-ORDER" />);

    await user.click(await screen.findByRole('button', { name: '+ New' }));
    await user.selectOptions(screen.getByLabelText('Customer'), 'customer-1');
    const quantity = screen.getByLabelText('Quantity');
    await user.clear(quantity);
    await user.type(quantity, '0');
    await user.click(screen.getByRole('button', { name: 'Save' }));

    expect(screen.getByRole('alert')).toHaveTextContent('Quantity must be greater than 0.');
    expect(screen.getByText('Quantity must be greater than 0.')).toBeInTheDocument();
    expect(quantity).toHaveAttribute('aria-invalid', 'true');
    expect(quantity).toHaveAttribute('aria-describedby', expect.stringContaining('field-error-lines-0-quantity'));
    await waitFor(() => expect(quantity).toHaveFocus());
    expect(screen.queryByText(/lines\.0\.quantity/i)).not.toBeInTheDocument();
    expect(api.commandP2).not.toHaveBeenCalled();

    await user.click(screen.getByRole('button', { name: 'Close' }));
    await user.click(screen.getByRole('button', { name: '+ New' }));
    expect(screen.queryByText('Quantity must be greater than 0.')).not.toBeInTheDocument();
    expect(screen.getByLabelText('Quantity')).not.toHaveAttribute('aria-invalid', 'true');
  });

  it('clears selected detail when the record leaves the filtered result set', async () => {
    const lead = { id: 'lead-stale', lead_number: 'LEAD-STALE-001', company_name: 'Filtered Customer', contact_name: 'Commercial Desk', enquiry_date: '2026-10-05', estimated_value: '25000', status: 'NEW', record_version: 1, allowed_actions: [] };
    api.listP2.mockResolvedValueOnce({ ...emptyWorkspace(), data: [lead] });
    api.getP2.mockResolvedValueOnce(lead);
    const user = userEvent.setup();
    renderPage(<CommercialP2Workspace screen="CRM-LEAD" />);
    await user.click(await screen.findByRole('button', { name: 'Open' }));
    expect(await screen.findByRole('dialog', { name: 'Record details' })).toBeInTheDocument();

    api.listP2.mockResolvedValueOnce(emptyWorkspace());
    fireEvent.change(screen.getByLabelText('Leads & Enquiries search'), { target: { value: 'NO-MATCH' } });
    await waitFor(() => expect(screen.queryByRole('dialog', { name: 'Record details' })).not.toBeInTheDocument());
    expect(screen.getByText('No live records match the selected context and filters.')).toBeInTheDocument();
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

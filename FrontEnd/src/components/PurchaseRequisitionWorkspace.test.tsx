import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
  PurchaseRequisition,
  PurchaseRequisitionCommandResult,
  PurchaseRequisitionStatus,
  PurchaseRequisitionWorkspace as Workspace,
} from '../api/purchaseRequisitions';
import { ErpSessionContext } from '../app/ErpSessionContext';
import type { ErpSession } from '../types/session';
import { PurchaseRequisitionWorkspace } from './PurchaseRequisitionWorkspace';

const apiMocks = vi.hoisted(() => ({
  listPurchaseRequisitions: vi.fn(),
  getPurchaseRequisition: vi.fn(),
  createPurchaseRequisition: vi.fn(),
  updatePurchaseRequisition: vi.fn(),
  submitPurchaseRequisition: vi.fn(),
  cancelPurchaseRequisition: vi.fn(),
  decidePurchaseRequisition: vi.fn(),
}));

vi.mock('../api/purchaseRequisitions', async () => {
  const actual = await vi.importActual<typeof import('../api/purchaseRequisitions')>('../api/purchaseRequisitions');
  return { ...actual, ...apiMocks };
});

describe('purchase requisition workspace', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    apiMocks.listPurchaseRequisitions.mockResolvedValue(workspace());
    apiMocks.getPurchaseRequisition.mockResolvedValue(requisition());
    apiMocks.createPurchaseRequisition.mockResolvedValue(result('DRAFT', 1));
    apiMocks.updatePurchaseRequisition.mockResolvedValue(result('DRAFT', 2));
    apiMocks.submitPurchaseRequisition.mockResolvedValue({ ...result('SUBMITTED', 3), approval_request_id: 'approval-1' });
    apiMocks.cancelPurchaseRequisition.mockResolvedValue(result('CANCELLED', 2));
    apiMocks.decidePurchaseRequisition.mockResolvedValue({ ...result('APPROVED', 3), approval_status: 'APPROVED' });
  });

  it('creates a costed draft from governed item lookups', async () => {
    const user = userEvent.setup();
    renderPage();

    expect(await screen.findByRole('heading', { name: 'Purchase requisitions' })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: '+ New' }));
    fireEvent.change(screen.getByLabelText('Requisition number'), { target: { value: 'req-ui-001' } });
    fireEvent.change(screen.getByLabelText('Department'), { target: { value: 'Production' } });
    fireEvent.change(screen.getByLabelText('Purpose'), { target: { value: 'Replenish the raw material buffer.' } });
    await user.selectOptions(screen.getByLabelText('Line 1 item'), 'item-1');
    fireEvent.change(screen.getByLabelText('Line 1 quantity'), { target: { value: '25' } });
    fireEvent.change(screen.getByLabelText('Line 1 estimated unit cost'), { target: { value: '50' } });

    expect(screen.getAllByText(/1,250\.00/)).toHaveLength(2);
    await user.click(screen.getByRole('button', { name: 'Create draft' }));

    await waitFor(() => expect(apiMocks.createPurchaseRequisition).toHaveBeenCalledWith(
      expect.objectContaining({
        requisition_number: 'REQ-UI-001', department: 'Production', currency: 'INR',
        lines: [{ item_id: 'item-1', quantity: '25', estimated_unit_cost: '50', notes: null }],
      }),
      expect.any(String),
    ));
    expect(await screen.findByText('Draft requisition created.')).toBeInTheDocument();
  });

  it('rotates the idempotency key after correcting a rejected payload', async () => {
    const user = userEvent.setup();
    apiMocks.createPurchaseRequisition
      .mockRejectedValueOnce({
        code: 'VALIDATION_FAILED', message: 'Correct the submitted requisition.',
        fields: { department: ['Use the governed department name.'] },
      })
      .mockResolvedValueOnce(result('DRAFT', 1));
    renderPage();

    await screen.findByRole('heading', { name: 'Purchase requisitions' });
    await user.click(screen.getByRole('button', { name: '+ New' }));
    fireEvent.change(screen.getByLabelText('Requisition number'), { target: { value: 'REQ-RETRY-001' } });
    fireEvent.change(screen.getByLabelText('Department'), { target: { value: 'Production' } });
    fireEvent.change(screen.getByLabelText('Purpose'), { target: { value: 'Replenish the governed material buffer.' } });
    await user.selectOptions(screen.getByLabelText('Line 1 item'), 'item-1');
    fireEvent.change(screen.getByLabelText('Line 1 quantity'), { target: { value: '10' } });
    fireEvent.change(screen.getByLabelText('Line 1 estimated unit cost'), { target: { value: '50' } });
    await user.click(screen.getByRole('button', { name: 'Create draft' }));
    expect(await screen.findByText('Use the governed department name.')).toBeInTheDocument();

    const firstKey = apiMocks.createPurchaseRequisition.mock.calls[0][1];
    fireEvent.change(screen.getByLabelText('Department'), { target: { value: 'Manufacturing' } });
    await user.click(screen.getByRole('button', { name: 'Create draft' }));

    await waitFor(() => expect(apiMocks.createPurchaseRequisition).toHaveBeenCalledTimes(2));
    expect(apiMocks.createPurchaseRequisition.mock.calls[1][0]).toEqual(expect.objectContaining({
      department: 'Manufacturing',
    }));
    expect(apiMocks.createPurchaseRequisition.mock.calls[1][1]).not.toBe(firstKey);
  });

  it('submits the refreshed version after editing a draft', async () => {
    const user = userEvent.setup();
    const versionTwo = requisition({ department: 'Manufacturing', record_version: 2 });
    const submitted = requisition({
      department: 'Manufacturing', status: 'SUBMITTED', record_version: 3,
      allowed_actions: [], approval: approval(), submitted_at: '2026-09-11T10:00:00Z',
      submitted_by: actor(),
    });
    apiMocks.listPurchaseRequisitions.mockResolvedValue(workspace([requisition()]));
    apiMocks.getPurchaseRequisition
      .mockResolvedValueOnce(requisition())
      .mockResolvedValueOnce(versionTwo)
      .mockResolvedValueOnce(submitted);
    renderPage();

    await user.click(await screen.findByRole('button', { name: 'Open' }));
    await user.click(await screen.findByRole('button', { name: 'Edit requisition' }));
    const department = screen.getByLabelText('Department');
    await user.clear(department);
    await user.type(department, 'Manufacturing');
    await user.click(screen.getByRole('button', { name: 'Save changes' }));
    await waitFor(() => expect(apiMocks.updatePurchaseRequisition).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'requisition-1', record_version: 1 }),
      expect.objectContaining({ department: 'Manufacturing' }),
      expect.any(String),
    ));

    await user.click(await screen.findByRole('button', { name: 'Submit requisition' }));
    await waitFor(() => expect(apiMocks.submitPurchaseRequisition).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'requisition-1', record_version: 2 }),
      expect.any(String),
    ));
    expect(await screen.findByText('Requisition submitted to the governed approval queue.')).toBeInTheDocument();
  });

  it('approves an actionable request from the independent inbox', async () => {
    const user = userEvent.setup();
    const pending = submittedRequisition();
    const approved = requisition({
      status: 'APPROVED', record_version: 3, allowed_actions: [], approval: {
        ...approval(), status: 'APPROVED', record_version: 2, allowed_actions: [],
        decision: {
          decision: 'APPROVE', reason: 'Budget and demand confirmed.', authority_source: 'DIRECT',
          authority_permission: 'ACTION:PUR-REQ:APPROVE', reviewer: actor('reviewer-1', 'Finance Reviewer'),
          decided_at: '2026-09-11T11:00:00Z',
        },
      },
      approved_at: '2026-09-11T11:00:00Z', approved_by: actor('reviewer-1', 'Finance Reviewer'),
    });
    apiMocks.listPurchaseRequisitions.mockResolvedValue(workspace([pending], [pending]));
    apiMocks.getPurchaseRequisition.mockResolvedValueOnce(pending).mockResolvedValueOnce(approved);
    renderPage(reviewerSession());

    const inbox = await screen.findByRole('heading', { name: 'Requisition approval inbox' });
    await user.click(within(inbox.closest('section')!).getByRole('button', { name: /REQ-UI-001/ }));
    fireEvent.change(await screen.findByLabelText('Approval reason'), { target: { value: 'Budget and demand confirmed.' } });
    await user.click(screen.getByRole('button', { name: 'Approve requisition' }));

    await waitFor(() => expect(apiMocks.decidePurchaseRequisition).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'approval-1', record_version: 1 }),
      'approve', 'Budget and demand confirmed.', expect.any(String),
    ));
    expect(await screen.findByText('Purchase requisition approved.')).toBeInTheDocument();
  });

  it('requires rejection evidence before returning a request for correction', async () => {
    const user = userEvent.setup();
    const pending = submittedRequisition();
    const rejected = requisition({
      status: 'REJECTED', record_version: 3, allowed_actions: ['UPDATE', 'SUBMIT', 'CANCEL'],
      approval: { ...approval(), status: 'REJECTED', record_version: 2, allowed_actions: [] },
      rejected_at: '2026-09-11T11:00:00Z', rejected_by: actor('reviewer-1', 'Finance Reviewer'),
      rejection_reason: 'Attach the revised demand forecast.',
    });
    apiMocks.listPurchaseRequisitions.mockResolvedValue(workspace([pending], [pending]));
    apiMocks.getPurchaseRequisition.mockResolvedValueOnce(pending).mockResolvedValueOnce(rejected);
    apiMocks.decidePurchaseRequisition.mockResolvedValue({ ...result('REJECTED', 3), approval_status: 'REJECTED' });
    renderPage(reviewerSession());

    const inbox = await screen.findByRole('heading', { name: 'Requisition approval inbox' });
    await user.click(within(inbox.closest('section')!).getByRole('button', { name: /REQ-UI-001/ }));
    await user.click(await screen.findByRole('button', { name: 'Reject for correction' }));
    expect(screen.getByText('Explain why the requisition is being rejected.')).toBeInTheDocument();
    expect(apiMocks.decidePurchaseRequisition).not.toHaveBeenCalled();

    await user.type(screen.getByLabelText('Approval reason'), 'Attach the revised demand forecast.');
    await user.click(screen.getByRole('button', { name: 'Reject for correction' }));
    const confirmation = screen.getByRole('alertdialog', { name: 'Reject requisition REQ-UI-001?' });
    expect(within(confirmation).getByText('Operations Manager')).toBeInTheDocument();
    expect(within(confirmation).getByText('₹1,250.00')).toBeInTheDocument();
    expect(within(confirmation).getByText('Attach the revised demand forecast.')).toBeInTheDocument();
    await user.click(within(confirmation).getByRole('button', { name: 'Reject requisition' }));
    await waitFor(() => expect(apiMocks.decidePurchaseRequisition).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'approval-1' }), 'reject', 'Attach the revised demand forecast.', expect.any(String),
    ));
  });
});

function renderPage(value: ErpSession = operationsSession()) {
  return render(<ErpSessionContext.Provider value={value}><PurchaseRequisitionWorkspace /></ErpSessionContext.Provider>);
}

function workspace(data: PurchaseRequisition[] = [], approvals: PurchaseRequisition[] = []): Workspace {
  return {
    data,
    meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length },
    summary: {
      total: data.length, draft: data.filter((item) => item.status === 'DRAFT').length,
      submitted: data.filter((item) => item.status === 'SUBMITTED').length,
      approved: data.filter((item) => item.status === 'APPROVED').length,
      rejected: data.filter((item) => item.status === 'REJECTED').length,
      cancelled: data.filter((item) => item.status === 'CANCELLED').length,
      estimated_total: data.reduce((total, item) => total + Number(item.estimated_total), 0).toFixed(2),
    },
    lookups: {
      statuses: ['DRAFT', 'SUBMITTED', 'APPROVED', 'REJECTED', 'CANCELLED'],
      sorts: ['NEWEST', 'OLDEST', 'NUMBER', 'STATUS', 'REQUIRED_DATE'], currencies: ['INR'],
      items: [{ id: 'item-1', code: 'RAW-APPLE', name: 'Apple concentrate', item_type: 'RAW_MATERIAL', uom_code: 'KG', status: 'ACTIVE' }],
    },
    approvals,
    allowed_actions: ['CREATE'],
  };
}

function requisition(overrides: Partial<PurchaseRequisition> = {}): PurchaseRequisition {
  return {
    id: 'requisition-1', company_id: 'company-1', plant_id: 'plant-1', requisition_number: 'REQ-UI-001',
    status: 'DRAFT', record_version: 1, requested_by: actor(), department: 'Production',
    purpose: 'Replenish the raw material buffer.', requested_date: '2026-09-11', required_by_date: '2026-09-25',
    currency: 'INR', estimated_total: '1250.00', line_count: 1, approval: null,
    submitted_at: null, submitted_by: null, approved_at: null, approved_by: null,
    rejected_at: null, rejected_by: null, rejection_reason: null, cancelled_at: null,
    cancelled_by: null, cancellation_reason: null, allowed_actions: ['UPDATE', 'SUBMIT', 'CANCEL'],
    created_at: '2026-09-11T09:00:00Z', updated_at: '2026-09-11T09:00:00Z',
    lines: [{
      id: 'line-1', line_number: 1,
      item: { id: 'item-1', code: 'RAW-APPLE', name: 'Apple concentrate', item_type: 'RAW_MATERIAL', uom_code: 'KG' },
      description: 'Apple concentrate', quantity: '25.000000', uom_code: 'KG', estimated_unit_cost: '50.000000',
      estimated_line_total: '1250.00', notes: null,
    }],
    ...overrides,
  };
}

function submittedRequisition(): PurchaseRequisition {
  return requisition({
    status: 'SUBMITTED', record_version: 2, allowed_actions: [], approval: approval(),
    submitted_at: '2026-09-11T10:00:00Z', submitted_by: actor(),
  });
}

function approval() {
  return {
    id: 'approval-1', status: 'PENDING' as const, record_version: 1,
    rule_name: 'Purchase requisition standard approval', band_name: 'Standard value',
    authority_value: '1250.00', authority_uom: 'INR', required_permission: 'ACTION:PUR-REQ:APPROVE',
    due_at: '2026-09-13T10:00:00Z', submission_number: 1, allowed_actions: ['APPROVE', 'REJECT'] as ('APPROVE' | 'REJECT')[],
    decision: null,
  };
}

function actor(id = 'manager-1', name = 'Operations Manager') {
  return { id, name };
}

function result(status: PurchaseRequisitionStatus, version: number): PurchaseRequisitionCommandResult {
  return {
    entity_type: 'purchase_requisition', id: 'requisition-1', status, record_version: version,
    line_count: 1, estimated_total: '1250.00', approval_request_id: null,
  };
}

function operationsSession(): ErpSession {
  return {
    user: { ...actor(), email: 'operations@example.com' }, roles: ['OPERATIONS_MANAGER'],
    allowed_screens: ['PUR-REQ'],
    allowed_actions: ['ACTION:PUR-REQ:CREATE', 'ACTION:PUR-REQ:UPDATE', 'ACTION:PUR-REQ:SUBMIT', 'ACTION:PUR-REQ:CANCEL'],
    contexts: [], selected_context: {
      company_id: 'company-1', company_name: 'Q & T Foods Ltd', plant_id: 'plant-1', plant_name: 'Training Plant',
    },
  };
}

function reviewerSession(): ErpSession {
  return {
    ...operationsSession(), user: { id: 'reviewer-1', name: 'Finance Reviewer', email: 'finance@example.com' },
    roles: ['FINANCE_REVIEWER'], allowed_actions: ['ACTION:PUR-REQ:APPROVE'],
  };
}

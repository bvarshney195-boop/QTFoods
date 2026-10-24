import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ApprovalGovernanceWorkspace } from '../api/approvalGovernance';
import { ErpSessionContext } from '../app/ErpSessionContext';
import type { ErpSession } from '../types/session';
import ADM_RULE from './ADM_RULE';

const apiMocks = vi.hoisted(() => ({
  getApprovalGovernance: vi.fn(),
  createApprovalRule: vi.fn(),
  updateApprovalRule: vi.fn(),
  createApprovalDelegation: vi.fn(),
  revokeApprovalDelegation: vi.fn(),
  escalateDueApprovals: vi.fn(),
}));

vi.mock('../api/approvalGovernance', async () => {
  const actual = await vi.importActual<typeof import('../api/approvalGovernance')>('../api/approvalGovernance');
  return { ...actual, ...apiMocks };
});

describe('approval governance workspace', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    apiMocks.getApprovalGovernance.mockResolvedValue(workspace());
  });

  it('edits contiguous authority bands as a new policy version', async () => {
    apiMocks.updateApprovalRule.mockResolvedValue({
      entity_type: 'approval_rule', id: 'rule-plant', status: 'ACTIVE', record_version: 2, band_count: 2,
    });
    renderPage();

    expect(await screen.findByRole('heading', { name: 'Plant loss approval' })).toBeInTheDocument();
    const dueHours = screen.getByLabelText('Band 1 due hours');
    await userEvent.setup().clear(dueHours);
    await userEvent.setup().type(dueHours, '18');
    await userEvent.setup().click(screen.getByRole('button', { name: 'Save policy version' }));

    await waitFor(() => expect(apiMocks.updateApprovalRule).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'rule-plant', record_version: 1 }),
      expect.objectContaining({
        bands: expect.arrayContaining([expect.objectContaining({ due_hours: 18 })]),
      }),
      expect.any(String)
    ));
    expect(await screen.findByRole('status')).toHaveTextContent('policy version 2');
  });

  it('creates the missing purchase-requisition policy from its governed template', async () => {
    apiMocks.createApprovalRule.mockResolvedValue({
      entity_type: 'approval_rule', id: 'purchase-rule', status: 'ACTIVE', record_version: 1, band_count: 2,
    });
    renderPage();

    await screen.findByRole('heading', { name: 'Plant loss approval' });
    await userEvent.setup().click(screen.getByRole('button', { name: /New/ }));

    expect(screen.getByLabelText('Rule code')).toHaveValue('PURCHASE_REQUISITION_APPROVAL');
    expect(screen.getByLabelText('Band 1 initial authority')).toHaveValue('ACTION:PUR-REQ:APPROVE');
    expect(screen.getByLabelText('Band 2 initial authority')).toHaveValue('ACTION:PUR-REQ:APPROVE-HIGH');
    await userEvent.setup().click(screen.getByRole('button', { name: 'Create plant override' }));

    await waitFor(() => expect(apiMocks.createApprovalRule).toHaveBeenCalledWith(
      expect.objectContaining({
        code: 'PURCHASE_REQUISITION_APPROVAL',
        bands: expect.arrayContaining([
          expect.objectContaining({ required_permission: 'ACTION:PUR-REQ:APPROVE' }),
          expect.objectContaining({ required_permission: 'ACTION:PUR-REQ:APPROVE-HIGH' }),
        ]),
      }),
      expect.any(String)
    ));
  });

  it('creates a time-bounded delegation from a direct approver', async () => {
    apiMocks.createApprovalDelegation.mockResolvedValue({
      entity_type: 'approval_delegation', id: 'delegation-new', status: 'ACTIVE', record_version: 1,
    });
    renderPage();

    await screen.findByRole('heading', { name: 'Plant loss approval' });
    await userEvent.setup().selectOptions(screen.getByLabelText('Delegator'), 'finance-user');
    await userEvent.setup().selectOptions(screen.getByLabelText('Delegate'), 'sales-user');
    await userEvent.setup().type(screen.getByLabelText('Delegation reason'), 'Annual leave reviewer cover');
    await userEvent.setup().click(screen.getByRole('button', { name: 'Create delegation' }));

    await waitFor(() => expect(apiMocks.createApprovalDelegation).toHaveBeenCalledWith(
      expect.objectContaining({
        delegator_id: 'finance-user',
        delegate_id: 'sales-user',
        permission_code: 'ACTION:RET-UNSOLD:APPROVE',
        reason: 'Annual leave reviewer cover',
      }),
      expect.any(String)
    ));
    expect(await screen.findByRole('status')).toHaveTextContent('authority delegated');
  });

  it('runs controlled overdue escalation and reports the affected count', async () => {
    apiMocks.escalateDueApprovals.mockResolvedValue({
      entity_type: 'approval_escalation_batch', id: 'batch-1', status: 'COMPLETED', record_version: 1,
      escalated_count: 2, approval_request_ids: ['approval-1', 'approval-2'],
    });
    renderPage();

    await screen.findByRole('heading', { name: 'Plant loss approval' });
    await userEvent.setup().click(screen.getByRole('button', { name: 'Escalate due approvals' }));

    await waitFor(() => expect(apiMocks.escalateDueApprovals).toHaveBeenCalledWith(expect.any(String)));
    expect(await screen.findByRole('status')).toHaveTextContent('2 overdue approvals routed');
  });
});

function renderPage() {
  return render(<ErpSessionContext.Provider value={session()}><ADM_RULE /></ErpSessionContext.Provider>);
}

function session(): ErpSession {
  return {
    user: { id: 'admin-user', name: 'ERP Administrator', email: 'admin@qtfoods.local' },
    roles: ['ERP_ADMIN'],
    allowed_screens: ['ADM-RULE'],
    allowed_actions: [
      'ACTION:ADM-RULE:CREATE', 'ACTION:ADM-RULE:UPDATE',
      'ACTION:ADM-RULE:DELEGATE', 'ACTION:ADM-RULE:ESCALATE',
    ],
    contexts: [],
    selected_context: {
      company_id: 'company-1', company_name: 'Q & T Foods Ltd',
      plant_id: 'plant-1', plant_name: 'Training Plant',
    },
  };
}

function workspace(): ApprovalGovernanceWorkspace {
  return {
    data: [{
      id: 'rule-plant', company_id: 'company-1', plant_id: 'plant-1', scope: 'PLANT',
      code: 'UNSOLD_RETURN_LOSS_APPROVAL', name: 'Plant loss approval',
      description: 'Routes destroyed quantity.', entity_type: 'unsold_return_loss',
      authority_metric: 'DESTROY_QUANTITY', authority_uom: 'BASE', status: 'ACTIVE',
      is_system: false, is_effective: true, record_version: 1, pending_request_count: 1,
      allowed_actions: ['UPDATE'], created_at: now, updated_at: now,
      bands: [
        { id: 'band-1', sequence: 1, name: 'Standard authority', minimum_value: '0', maximum_value: '100', required_permission: 'ACTION:RET-UNSOLD:APPROVE', escalation_permission: 'ACTION:RET-UNSOLD:APPROVE-ESCALATED', work_priority: 'HIGH', due_hours: 24, escalate_after_hours: 12 },
        { id: 'band-2', sequence: 2, name: 'High authority', minimum_value: '100', maximum_value: null, required_permission: 'ACTION:RET-UNSOLD:APPROVE-HIGH', escalation_permission: 'ACTION:RET-UNSOLD:APPROVE-ESCALATED', work_priority: 'URGENT', due_hours: 12, escalate_after_hours: 6 },
      ],
    }],
    delegations: [],
    summary: { rules: 2, plant_overrides: 1, active_rules: 2, pending_approvals: 1, escalated_approvals: 0, active_delegations: 0 },
    lookups: {
      permissions: [
        { id: 'permission-normal', code: 'ACTION:RET-UNSOLD:APPROVE', name: 'Approve returns' },
        { id: 'permission-high', code: 'ACTION:RET-UNSOLD:APPROVE-HIGH', name: 'Approve high returns' },
        { id: 'permission-escalated', code: 'ACTION:RET-UNSOLD:APPROVE-ESCALATED', name: 'Approve escalated returns' },
      ],
      users: [
        { id: 'finance-user', name: 'Finance Reviewer', email: 'finance@qtfoods.local', approval_permissions: ['ACTION:RET-UNSOLD:APPROVE'] },
        { id: 'sales-user', name: 'Sales Manager', email: 'sales@qtfoods.local', approval_permissions: [] },
      ],
    },
    allowed_actions: ['CREATE_RULE', 'CREATE_DELEGATION', 'ESCALATE_DUE'],
  };
}

const now = '2026-09-09T10:00:00.000Z';

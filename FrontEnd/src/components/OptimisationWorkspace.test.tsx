import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ErpSessionContext } from '../app/ErpSessionContext';
import type { OptimisationOutcome, OptimisationPlan, OptimisationWorkspace as OptimisationWorkspaceResponse } from '../api/optimisation';
import type { ErpSession } from '../types/session';
import { OptimisationWorkspace } from './OptimisationWorkspace';

const api = vi.hoisted(() => ({
  listOptimisationPlans: vi.fn(), getOptimisationPlan: vi.fn(), createOptimisationPlan: vi.fn(),
  reviseOptimisationInput: vi.fn(), runOptimisationCommand: vi.fn(), decideOptimisation: vi.fn(),
  cancelOptimisation: vi.fn(), saveOptimisationOutcome: vi.fn(),
}));
vi.mock('../api/optimisation', () => api);

describe('Forecast & Optimisation workspace', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    api.listOptimisationPlans.mockResolvedValue(workspace());
    api.getOptimisationPlan.mockResolvedValue(plan('DRAFT', ['REVISE', 'GENERATE', 'CANCEL']));
    api.createOptimisationPlan.mockResolvedValue({ id: 'plan-1', status: 'DRAFT', record_version: 1, input_version: 1 });
    api.runOptimisationCommand.mockResolvedValue({ id: 'plan-1', status: 'SUBMITTED', record_version: 3 });
    api.decideOptimisation.mockResolvedValue({ id: 'plan-1', status: 'APPROVED', record_version: 4 });
    api.saveOptimisationOutcome.mockResolvedValue({ id: 'plan-1', status: 'APPROVED', record_version: 5, outcome_version: 1 });
  });

  it('captures a governed scenario from a released demand-plan version', async () => {
    const user = userEvent.setup();
    renderPage();

    expect(await screen.findByRole('heading', { level: 1, name: 'Planning recommendations' })).toBeInTheDocument();
    expect(screen.getByText(/Inputs are checksum-versioned/i)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: '+ New' }));
    await user.type(screen.getByLabelText('Plan number'), 'opt-ui-002');
    await user.type(screen.getByLabelText('Name'), 'UI governed scenario');
    expect(screen.getByLabelText('Released demand plan')).toHaveValue('demand-1');
    await user.clear(screen.getByLabelText('Safety stock %'));
    await user.type(screen.getByLabelText('Safety stock %'), '12');
    await user.type(screen.getByLabelText('Assumptions'), 'Stable commercial demand through the selected horizon.');
    await user.click(screen.getByRole('button', { name: 'Capture input version 1' }));

    await waitFor(() => expect(api.createOptimisationPlan).toHaveBeenCalledWith(
      expect.objectContaining({
        plan_number: 'OPT-UI-002', name: 'UI governed scenario', demand_plan_id: 'demand-1',
        objective: 'BALANCED', safety_stock_percent: '12', currency: 'INR',
      }),
      expect.any(String),
    ));
    expect(await screen.findByText(/Immutable input version 1 captured/i)).toBeInTheDocument();
  });

  it('uses permission-aware prerequisite guidance and never offers an unavailable create action', async () => {
    const missingPrerequisite = workspace();
    missingPrerequisite.lookups.released_demand_plans = [];
    api.listOptimisationPlans.mockResolvedValueOnce(missingPrerequisite);
    const first = renderPage();

    expect(await screen.findByText(/Ask the operations planning team to release a plan in Demand planning/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: '+ New' })).not.toBeInTheDocument();
    expect(screen.queryByText(/PLAN-DEM/)).not.toBeInTheDocument();
    first.unmount();

    const readOnly = workspace();
    readOnly.allowed_actions = [];
    api.listOptimisationPlans.mockResolvedValueOnce(readOnly);
    renderPage();
    expect(await screen.findByText(/No optimisation plans are available to your role/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: '+ New' })).not.toBeInTheDocument();
  });

  it('shows recommendation limitations and submits the exact record version for review', async () => {
    const user = userEvent.setup();
    const generated = plan('GENERATED', ['REVISE', 'CANCEL', 'SUBMIT']);
    api.listOptimisationPlans.mockResolvedValue(workspace([generated]));
    api.getOptimisationPlan.mockResolvedValue(generated);
    renderPage();

    await user.click(await screen.findByRole('button', { name: 'Open' }));
    expect(screen.getByText('Current recommendations and limitations')).toBeInTheDocument();
    expect(screen.getByText(/deterministic net-requirements guidance/i)).toBeInTheDocument();
    expect(screen.getByText(/not a capacity booking/i)).toBeInTheDocument();
    expect(screen.getByText('abc123checksum')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Submit for review' }));

    await waitFor(() => expect(api.runOptimisationCommand).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'plan-1', record_version: 2 }), 'submit', expect.any(String),
    ));
    expect(await screen.findByText(/submitted for an independent human decision/i)).toBeInTheDocument();
  });

  it('records and optimistically replaces a recommendation outcome', async () => {
    const user = userEvent.setup();
    const approved = plan('APPROVED', ['OUTCOME']);
    approved.record_version = 4;
    approved.recommendations![0].allowed_actions = ['RECORD_OUTCOME'];
    api.listOptimisationPlans.mockResolvedValue(workspace([approved]));
    api.getOptimisationPlan.mockResolvedValue(approved);
    const first = renderPage();

    await user.click(await screen.findByRole('button', { name: 'Open' }));
    await user.click(screen.getByRole('button', { name: 'Record execution outcome' }));
    await user.type(screen.getByLabelText('Outcome evidence'), 'Measured after the planning horizon closed.');
    await user.click(screen.getByRole('button', { name: 'Record outcome' }));

    await waitFor(() => expect(api.saveOptimisationOutcome).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'plan-1', record_version: 4 }), 'recommendation-1',
      expect.objectContaining({ result: 'ACHIEVED', actual_stock_quantity: '40.000000', actual_production_quantity: '15.000000' }),
      expect.any(String),
    ));
    first.unmount();

    const withOutcome = plan('APPROVED', ['OUTCOME', 'COMPLETE']);
    withOutcome.record_version = 5;
    withOutcome.recommendations![0].allowed_actions = ['RECORD_OUTCOME'];
    withOutcome.recommendations![0].outcome = outcome();
    api.listOptimisationPlans.mockResolvedValue(workspace([withOutcome]));
    api.getOptimisationPlan.mockResolvedValue(withOutcome);
    const second = renderPage();
    await user.click(await screen.findByRole('button', { name: 'Open' }));
    await user.click(screen.getByRole('button', { name: 'Replace outcome observation' }));
    await user.clear(screen.getByLabelText('Actual service %'));
    await user.type(screen.getByLabelText('Actual service %'), '96');
    await user.click(screen.getByRole('button', { name: 'Replace outcome v1' }));
    await waitFor(() => expect(api.saveOptimisationOutcome).toHaveBeenLastCalledWith(
      expect.objectContaining({ record_version: 5 }), 'recommendation-1',
      expect.objectContaining({ expected_outcome_version: 1, actual_service_level: '96' }), expect.any(String),
    ));
    second.unmount();
  });
});

function renderPage() {
  return render(<ErpSessionContext.Provider value={session()}><OptimisationWorkspace /></ErpSessionContext.Provider>);
}

function workspace(data: OptimisationPlan[] = []): OptimisationWorkspaceResponse {
  return {
    data, meta: { current_page: 1, last_page: 1, per_page: 25, total: data.length },
    summary: { total: data.length, generated: 0, awaiting_review: 0, approved: 0, completed: 0, warning_limitations: 0 },
    lookups: {
      statuses: ['DRAFT', 'GENERATED', 'SUBMITTED', 'APPROVED', 'REJECTED', 'COMPLETED', 'CANCELLED'],
      objectives: ['BALANCED', 'SERVICE', 'COST', 'INVENTORY'], outcomes: ['ACHIEVED', 'PARTIAL', 'MISSED'],
      sorts: ['NEWEST', 'OLDEST', 'NUMBER', 'HORIZON', 'STATUS'],
      released_demand_plans: [{ id: 'demand-1', number: 'DEMAND-001', name: 'Released demand', horizon_start: '2026-09-01', horizon_end: '2026-09-30', record_version: 2, line_count: 1 }],
    }, allowed_actions: ['CREATE'],
  };
}

function plan(status: string, allowedActions: string[]): OptimisationPlan {
  return {
    id: 'plan-1', plan_number: 'OPT-UI-001', name: 'UI scenario', horizon_start: '2026-09-01', horizon_end: '2026-09-30',
    status, record_version: status === 'GENERATED' ? 2 : 1, current_input_version: 1,
    current_recommendation_version: status === 'DRAFT' ? null : 1, objective: 'BALANCED', currency: 'INR',
    input_line_count: 1, recommendation_count: status === 'DRAFT' ? 0 : 1, warning_count: status === 'DRAFT' ? 0 : 1,
    outcome_count: 0, production_quantity: status === 'DRAFT' ? '0.000000' : '15.000000', estimated_cost: '180.000000',
    created_by: { id: 'operations-1', name: 'Operations Manager' }, created_at: '2026-09-01T09:00:00Z',
    generated_at: status === 'DRAFT' ? null : '2026-09-01T10:00:00Z', completed_at: null, cancelled_at: null,
    cancellation_reason: null, allowed_actions: allowedActions,
    current_input: {
      id: 'input-1', version_number: 1,
      demand_plan: { id: 'demand-1', number: 'DEMAND-001', name: 'Released demand', version_snapshot: 2, current_version: 2, status: 'RELEASED' },
      objective: 'BALANCED', service_level_target: '97.000', safety_stock_percent: '10.000', planning_lead_days: 7,
      max_utilisation_percent: '85.000', holding_cost_rate: '18.0000', shortage_penalty_rate: '12.000000',
      currency: 'INR', assumptions: 'Stable demand.', input_checksum: 'abc123checksum', line_count: 1,
      created_by: { id: 'operations-1', name: 'Operations Manager' }, created_at: '2026-09-01T09:00:00Z',
      lines: [{
        id: 'input-line-1', line_number: 1, source_demand_line_id: 'demand-line-1',
        output_sku: { id: 'sku-1', code: 'SKU-APPLE-100', name: 'Apple Snack Pack' },
        demand_date: '2026-09-15', demand_type: 'FIRM', demand_quantity: '50.000000',
        available_quantity_snapshot: '40.000000', excluded_stock_position_count: 0, material_shortage_count: 0,
        material_shortages: [], unit_cost_snapshot: '12.000000', uom_code: 'PACK',
      }],
    },
    input_versions: [], reviews: [], recommendations: status === 'DRAFT' ? [] : [{
      id: 'recommendation-1', line_number: 1,
      output_sku: { id: 'sku-1', code: 'SKU-APPLE-100', name: 'Apple Snack Pack' },
      demand_date: '2026-09-15', demand_type: 'FIRM', demand_quantity: '50.000000', action_type: 'MIXED',
      target_quantity: '55.000000', stock_allocation_quantity: '40.000000', production_quantity: '15.000000', uom_code: 'PACK',
      proposed_start_date: '2026-09-08', proposed_end_date: '2026-09-15', priority: 'HIGH', expected_service_level: '97.000',
      estimated_cost: '180.000000', currency: 'INR', rationale: 'Use stock and produce the residual quantity.',
      algorithm: { code: 'DETERMINISTIC_NET_REQUIREMENTS', version: '1.0.0' }, generated_at: '2026-09-01T10:00:00Z',
      limitations: [
        { id: 'limitation-1', code: 'HEURISTIC_SCOPE', severity: 'INFO', description: 'This is deterministic net-requirements guidance, not a globally optimal solver result.', evidence: null },
        { id: 'limitation-2', code: 'CAPACITY_NOT_RESERVED', severity: 'WARNING', description: 'Suggested production is not a capacity booking.', evidence: null },
      ], outcome: null, allowed_actions: [],
    }],
  };
}

function outcome(): OptimisationOutcome {
  return {
    id: 'outcome-1', result: 'PARTIAL', actual_stock_quantity: '40.000000', actual_production_quantity: '14.000000',
    actual_service_level: '95.000', actual_cost: '175.000000', currency: 'INR', observed_on: '2026-09-30',
    notes: 'Provisional measured outcome.', record_version: 1,
    recorded_by: { id: 'operations-1', name: 'Operations Manager' }, recorded_at: '2026-09-30T12:00:00Z', updated_by: null,
  };
}

function session(): ErpSession {
  return {
    user: { id: 'admin-1', name: 'ERP Administrator', email: 'admin@example.com' }, roles: ['ERP_ADMIN'],
    allowed_screens: ['OPT-PLAN'], allowed_actions: ['ACTION:OPT-PLAN:CREATE'],
    contexts: [{ company_id: 'company-1', company_name: 'Q & T Foods', plant_id: 'plant-1', plant_name: 'Training Plant' }],
    selected_context: { company_id: 'company-1', company_name: 'Q & T Foods', plant_id: 'plant-1', plant_name: 'Training Plant' },
  };
}

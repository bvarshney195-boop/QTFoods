import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { DemandPlan, DemandWorkspace, MrpRun, MrpWorkspace, ProductionSchedule, ScheduleWorkspace } from '../api/manufacturingPlanning';
import { ErpSessionContext } from '../app/ErpSessionContext';
import type { ErpSession } from '../types/session';
import { DemandPlanningWorkspace, MrpPlanningWorkspace, ProductionScheduleWorkspace } from './ManufacturingPlanningWorkspaces';

const apiMocks = vi.hoisted(() => ({
  listDemandPlans: vi.fn(), getDemandPlan: vi.fn(), createDemandPlan: vi.fn(), updateDemandPlan: vi.fn(), releaseDemandPlan: vi.fn(), cancelDemandPlan: vi.fn(),
  listMrpRuns: vi.fn(), getMrpRun: vi.fn(), runMrp: vi.fn(), cancelMrpRun: vi.fn(),
  listProductionSchedules: vi.fn(), getProductionSchedule: vi.fn(), createProductionSchedule: vi.fn(), updateProductionSchedule: vi.fn(), releaseProductionSchedule: vi.fn(), cancelProductionSchedule: vi.fn(),
}));

vi.mock('../api/manufacturingPlanning', async () => {
  const actual = await vi.importActual<typeof import('../api/manufacturingPlanning')>('../api/manufacturingPlanning');
  return { ...actual, ...apiMocks };
});

describe('manufacturing planning workspaces', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    apiMocks.listDemandPlans.mockResolvedValue(demandWorkspace());
    apiMocks.listMrpRuns.mockResolvedValue(mrpWorkspace());
    apiMocks.listProductionSchedules.mockResolvedValue(scheduleWorkspace());
  });

  it('creates and releases a time-phased demand plan', async () => {
    const user = userEvent.setup();
    apiMocks.createDemandPlan.mockResolvedValue(command('demand-1', 'DRAFT'));
    apiMocks.releaseDemandPlan.mockResolvedValue(command('demand-1', 'RELEASED', 2));
    apiMocks.getDemandPlan.mockResolvedValueOnce(demand()).mockResolvedValueOnce(demand({ status: 'RELEASED', record_version: 2, allowed_actions: ['CANCEL'], released_at: '2026-09-13T10:00:00Z' }));
    renderPage(<DemandPlanningWorkspace />);

    await user.selectOptions(await screen.findByLabelText('Sort'), 'OLDEST');
    await waitFor(() => expect(apiMocks.listDemandPlans).toHaveBeenLastCalledWith(expect.objectContaining({ sort: 'OLDEST', page: 1 })));
    await user.click(await screen.findByRole('button', { name: '+ New' }));
    await user.type(screen.getByLabelText('Plan number'), 'plan-ui-001');
    await user.type(screen.getByLabelText('Plan name'), 'Weekly apple snack demand');
    await user.selectOptions(screen.getByLabelText('Demand line 1 output SKU'), 'finished-1');
    fireEvent.change(screen.getByLabelText('Demand line 1 quantity'), { target: { value: '100' } });
    await user.click(screen.getByRole('button', { name: 'Create demand plan' }));

    await waitFor(() => expect(apiMocks.createDemandPlan).toHaveBeenCalledWith(expect.objectContaining({
      plan_number: 'PLAN-UI-001', name: 'Weekly apple snack demand',
      lines: [expect.objectContaining({ output_sku_id: 'finished-1', demand_type: 'FIRM', quantity: '100' })],
    }), expect.any(String)));
    await user.click(await screen.findByRole('button', { name: 'Release to MRP' }));
    await waitFor(() => expect(apiMocks.releaseDemandPlan).toHaveBeenCalledWith(expect.objectContaining({ id: 'demand-1', record_version: 1 }), expect.any(String)));
    expect(await screen.findByText('Demand released to MRP.')).toBeInTheDocument();
  });

  it('runs MRP and exposes the recipe, inventory, and shortage snapshots', async () => {
    const user = userEvent.setup();
    apiMocks.runMrp.mockResolvedValue(command('mrp-1', 'COMPLETED'));
    apiMocks.getMrpRun.mockResolvedValue(mrp());
    renderPage(<MrpPlanningWorkspace />);

    await user.click(await screen.findByRole('button', { name: '+ New' }));
    await user.type(screen.getByLabelText('MRP run number'), 'mrp-ui-001');
    await user.selectOptions(screen.getByLabelText('Released demand plan'), 'demand-1');
    await user.click(screen.getByRole('button', { name: 'Run MRP' }));

    await waitFor(() => expect(apiMocks.runMrp).toHaveBeenCalledWith(expect.objectContaining({
      run_number: 'MRP-UI-001', demand_plan_id: 'demand-1',
    }), expect.any(String)));
    expect(await screen.findByText('MRP completed with immutable recipe and inventory snapshots.')).toBeInTheDocument();
    expect(screen.getByText('10.304569 KG')).toBeInTheDocument();
    expect(screen.getByText('105')).toBeInTheDocument();
  });

  it('creates a finite-capacity schedule and releases FEFO reservations', async () => {
    const user = userEvent.setup();
    apiMocks.createProductionSchedule.mockResolvedValue(command('schedule-1', 'DRAFT'));
    apiMocks.releaseProductionSchedule.mockResolvedValue(command('schedule-1', 'RELEASED', 2));
    apiMocks.getProductionSchedule.mockResolvedValueOnce(schedule()).mockResolvedValueOnce(schedule({ status: 'RELEASED', record_version: 2, allowed_actions: ['CANCEL'], active_reservation_count: 1, reserved_quantity: '10.304569', reservations: [{ id: 'link-1', number: 'MRP-SCH-UI-001-001', status: 'ACTIVE', stock_position_id: 'position-1', item_code: 'SKU-APPLE-BASE', lot_code: 'RM-APPLE-2609A', expiry_date: '2026-12-01', quantity: '10.304569', uom_code: 'KG' }] }));
    renderPage(<ProductionScheduleWorkspace />);

    await user.click(await screen.findByRole('button', { name: '+ New' }));
    await user.selectOptions(screen.getByLabelText('Completed MRP run'), 'mrp-1');
    await user.type(screen.getByLabelText('Schedule number'), 'sch-ui-001');
    fireEvent.change(screen.getByLabelText('MIX-01 daily capacity minutes'), { target: { value: '600' } });
    await user.click(screen.getByRole('button', { name: 'Create schedule' }));

    await waitFor(() => expect(apiMocks.createProductionSchedule).toHaveBeenCalledWith(expect.objectContaining({
      schedule_number: 'SCH-UI-001', mrp_run_id: 'mrp-1',
      lines: [expect.objectContaining({ mrp_planned_order_id: 'planned-1' })],
      capacities: expect.arrayContaining([{ work_center_code: 'MIX-01', daily_capacity_minutes: '600' }]),
    }), expect.any(String)));
    await user.click(await screen.findByRole('button', { name: 'Release and reserve' }));
    await waitFor(() => expect(apiMocks.releaseProductionSchedule).toHaveBeenCalledWith(expect.objectContaining({ id: 'schedule-1', record_version: 1 }), expect.any(String)));
    expect(await screen.findByText('Schedule released and materials reserved by FEFO.')).toBeInTheDocument();
    expect(screen.getByText(/RM-APPLE-2609A/)).toBeInTheDocument();
  });

  it('opens editable planning drafts directly in their edit forms', async () => {
    const user = userEvent.setup();

    apiMocks.listDemandPlans.mockResolvedValue(demandWorkspace([demand()]));
    apiMocks.getDemandPlan.mockResolvedValue(demand());
    const demandView = renderPage(<DemandPlanningWorkspace />);
    await user.click(await screen.findByRole('button', { name: 'Open' }));
    expect(await screen.findByRole('button', { name: 'Save demand plan' })).toBeInTheDocument();
    expect(screen.getByLabelText('Demand line 1 output SKU')).toHaveValue('finished-1');
    demandView.unmount();

    apiMocks.listProductionSchedules.mockResolvedValue(scheduleWorkspace([schedule()]));
    apiMocks.getProductionSchedule.mockResolvedValue(schedule());
    renderPage(<ProductionScheduleWorkspace />);
    await user.click(await screen.findByRole('button', { name: 'Open' }));
    expect(await screen.findByRole('button', { name: 'Recalculate schedule' })).toBeInTheDocument();
    expect(screen.getByLabelText('Completed MRP run')).toHaveValue('mrp-1');
  });
});

function renderPage(component: React.ReactNode) {
  return render(<ErpSessionContext.Provider value={session()}>{component}</ErpSessionContext.Provider>);
}

function meta(total = 0) { return { current_page: 1, last_page: 1, per_page: 25, total }; }
function actor() { return { id: 'operations-1', name: 'Operations Manager' }; }
function command(id: string, status: string, recordVersion = 1) { return { entity_type: 'planning', id, status, record_version: recordVersion }; }
const output = { id: 'finished-1', code: 'SKU-APPLE-100', name: 'Apple Snack Pack 100g', item_type: 'FINISHED_GOOD', uom_code: 'PACK' };

function demandWorkspace(data: DemandPlan[] = []): DemandWorkspace {
  return { data, meta: meta(data.length), summary: {}, lookups: { statuses: ['DRAFT', 'RELEASED', 'CANCELLED'], demand_types: ['FORECAST', 'FIRM', 'SAFETY_STOCK'], sorts: sorts(), output_skus: [output] }, allowed_actions: ['CREATE'] };
}
function demand(overrides: Partial<DemandPlan> = {}): DemandPlan {
  return { id: 'demand-1', plan_number: 'PLAN-UI-001', name: 'Weekly apple snack demand', horizon_start: '2026-09-13', horizon_end: '2026-09-27', notes: null, status: 'DRAFT', record_version: 1, line_count: 1, active_mrp_count: 0, created_by: actor(), released_at: null, cancelled_at: null, cancellation_reason: null, allowed_actions: ['UPDATE', 'RELEASE', 'CANCEL'], lines: [{ id: 'demand-line-1', line_number: 1, output_sku: output, demand_date: '2026-09-20', demand_type: 'FIRM', quantity: '100.000000', uom_code: 'PACK', notes: null }], ...overrides };
}

function mrpWorkspace(data: MrpRun[] = []): MrpWorkspace {
  return { data, meta: meta(data.length), summary: {}, lookups: { statuses: ['COMPLETED', 'CANCELLED'], sorts: sorts(), released_demand_plans: [{ id: 'demand-1', number: 'PLAN-UI-001', name: 'Weekly apple snack demand', horizon_start: '2026-09-13', horizon_end: '2026-09-27', record_version: 2 }] }, allowed_actions: ['RUN'] };
}
function mrp(overrides: Partial<MrpRun> = {}): MrpRun {
  return { id: 'mrp-1', run_number: 'MRP-UI-001', demand_plan: { id: 'demand-1', number: 'PLAN-UI-001', name: 'Weekly apple snack demand', version: 2 }, run_date: '2026-09-13', status: 'COMPLETED', record_version: 1, planned_order_count: 1, material_requirement_count: 1, shortage_quantity: '0.000000', active_schedule_count: 0, created_by: actor(), completed_at: '2026-09-13T10:00:00Z', cancelled_at: null, cancellation_reason: null, allowed_actions: ['CANCEL'], planned_orders: [{ id: 'planned-1', line_number: 1, output_sku: output, recipe: { id: 'recipe-1', code: 'REC-APPLE-100', name: 'Apple Snack Recipe', revision: 1 }, due_date: '2026-09-20', planned_quantity: '100.000000', uom_code: 'PACK', materials: [{ id: 'material-1', line_number: 1, component_sku: { id: 'raw-1', code: 'SKU-APPLE-BASE', name: 'Apple Ingredient Base' }, gross_requirement: '10.304569', on_hand_snapshot: '125.000000', reserved_snapshot: '20.000000', available_snapshot: '105.000000', shortage_quantity: '0.000000', uom_code: 'KG' }] }], ...overrides };
}

function scheduleWorkspace(data: ProductionSchedule[] = []): ScheduleWorkspace {
  return { data, meta: meta(data.length), summary: {}, lookups: { statuses: ['DRAFT', 'RELEASED', 'CANCELLED'], sorts: sorts(), mrp_runs: [{ id: 'mrp-1', number: 'MRP-UI-001', run_date: '2026-09-13', demand_plan_number: 'PLAN-UI-001', planned_orders: [{ id: 'planned-1', output_sku: { code: 'SKU-APPLE-100', name: 'Apple Snack Pack 100g' }, due_date: '2026-09-20', planned_quantity: '100.000000', uom_code: 'PACK', work_centers: ['MIX-01', 'OVEN-01', 'PACK-01'] }] }] }, allowed_actions: ['CREATE'] };
}
function sorts() { return ['NEWEST', 'OLDEST', 'NUMBER', 'HORIZON', 'STATUS']; }
function schedule(overrides: Partial<ProductionSchedule> = {}): ProductionSchedule {
  return { id: 'schedule-1', schedule_number: 'SCH-UI-001', mrp_run: { id: 'mrp-1', number: 'MRP-UI-001' }, demand_plan_number: 'PLAN-UI-001', horizon_start: '2026-09-13', horizon_end: '2026-09-20', notes: null, status: 'DRAFT', record_version: 1, line_count: 1, overloaded_work_centers: 0, active_reservation_count: 0, reserved_quantity: '0.000000', created_by: actor(), released_at: null, cancelled_at: null, cancellation_reason: null, allowed_actions: ['UPDATE', 'RELEASE', 'CANCEL'], capacities: ['MIX-01', 'OVEN-01', 'PACK-01'].map((center, index) => ({ id: `capacity-${index}`, work_center_code: center, daily_capacity_minutes: '480.000000', working_days: 6, available_minutes: '2880.000000', required_minutes: index === 0 ? '20.000000' : '30.000000', utilisation_percent: '1.000000', is_overloaded: false })), lines: [{ id: 'schedule-line-1', mrp_planned_order_id: 'planned-1', line_number: 1, output_sku: output, recipe: { id: 'recipe-1', code: 'REC-APPLE-100' }, route: { id: 'route-1', code: 'ROUTE-APPLE-SNACK', name: 'Apple Snack Route' }, planned_quantity: '100.000000', uom_code: 'PACK', planned_start_date: '2026-09-13', planned_end_date: '2026-09-20', operations: [], materials: [{ id: 'material-1', component_sku: { id: 'raw-1', code: 'SKU-APPLE-BASE', name: 'Apple Ingredient Base' }, gross_requirement: '10.304569', mrp_shortage_quantity: '0.000000', reserved_quantity: '0.000000', uom_code: 'KG' }] }], reservations: [], ...overrides };
}

function session(): ErpSession {
  return { user: { ...actor(), email: 'operations@example.com' }, roles: ['OPERATIONS_MANAGER'], allowed_screens: ['PLAN-DEM', 'PLAN-MRP', 'PLAN-SCH'], allowed_actions: [], contexts: [], selected_context: { company_id: 'company-1', company_name: 'Q & T Foods', plant_id: 'plant-1', plant_name: 'Training Plant' } };
}

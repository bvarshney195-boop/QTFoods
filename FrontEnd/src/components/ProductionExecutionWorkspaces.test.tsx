import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ErpSessionContext } from '../app/ErpSessionContext';
import type { ErpSession } from '../types/session';
import { ProductionLossWorkspace, ProductionOrderWorkspace, ProductionStageWorkspace } from './ProductionExecutionWorkspaces';

const api = vi.hoisted(() => ({
  listProductionOrders: vi.fn(), getProductionOrder: vi.fn(), createProductionOrder: vi.fn(), releaseProductionOrder: vi.fn(), issueProductionMaterials: vi.fn(), completeProductionOrder: vi.fn(), cancelProductionOrder: vi.fn(), releaseBatchQuality: vi.fn(),
  listProductionStages: vi.fn(), getProductionStage: vi.fn(), startProductionStage: vi.fn(), completeProductionStage: vi.fn(),
  listProductionLosses: vi.fn(), recordProductionOutput: vi.fn(), resolveProductionRework: vi.fn(),
}));
vi.mock('../api/manufacturingExecution', () => api);

describe('production execution workspaces', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    api.listProductionOrders.mockResolvedValue(orderWorkspace());
    api.listProductionStages.mockResolvedValue(stageWorkspace());
    api.listProductionLosses.mockResolvedValue(lossWorkspace());
  });

  it('creates a scheduled production order and releases it', async () => {
    const user = userEvent.setup(); api.createProductionOrder.mockResolvedValue({ id: 'order-1' }); api.releaseProductionOrder.mockResolvedValue({ id: 'order-1' });
    api.getProductionOrder.mockResolvedValueOnce(order()).mockResolvedValueOnce(order({ status: 'RELEASED', record_version: 2, allowed_actions: ['ISSUE_MATERIALS', 'CANCEL'] }));
    renderPage(<ProductionOrderWorkspace />);
    await user.click(await screen.findByRole('button', { name: '+ New' }));
    await user.type(screen.getByLabelText('Order number'), 'pro-ui-001'); await user.type(screen.getByLabelText('Batch number'), 'batch-ui-001');
    await user.selectOptions(screen.getByLabelText('Released schedule line'), 'schedule-line-1');
    await user.click(screen.getByRole('button', { name: 'Create production order' }));
    await waitFor(() => expect(api.createProductionOrder).toHaveBeenCalledWith(expect.objectContaining({ order_number: 'PRO-UI-001', batch_number: 'BATCH-UI-001', production_schedule_line_id: 'schedule-line-1' }), expect.any(String)));
    await user.click(await screen.findByRole('button', { name: 'Release order' }));
    await waitFor(() => expect(api.releaseProductionOrder).toHaveBeenCalledWith(expect.objectContaining({ id: 'order-1', record_version: 1 }), expect.any(String)));
    expect(await screen.findByText('Production order released.')).toBeInTheDocument();
  });

  it('starts and completes an ordered route stage with actual time', async () => {
    const user = userEvent.setup(); api.startProductionStage.mockResolvedValue({ id: 'stage-1' }); api.completeProductionStage.mockResolvedValue({ id: 'stage-1' });
    api.getProductionStage.mockResolvedValueOnce(stage()).mockResolvedValueOnce(stage({ status: 'IN_PROGRESS', record_version: 2, allowed_actions: ['COMPLETE'], started_at: '2026-09-13T10:00:00Z' })).mockResolvedValueOnce(stage({ status: 'COMPLETED', record_version: 3, allowed_actions: [], actual_minutes: '24.000000', completed_at: '2026-09-13T10:24:00Z' }));
    renderPage(<ProductionStageWorkspace />); await user.click((await screen.findAllByRole('button', { name: 'Open' }))[0]);
    await user.click(await screen.findByRole('button', { name: 'Start stage' }));
    fireEvent.change(await screen.findByLabelText('Actual minutes'), { target: { value: '24' } });
    await user.click(screen.getByRole('button', { name: 'Complete stage' }));
    await waitFor(() => expect(api.completeProductionStage).toHaveBeenCalledWith(expect.objectContaining({ id: 'stage-1', record_version: 2 }), expect.objectContaining({ actual_minutes: '24' }), expect.any(String)));
    expect(await screen.findByText('Stage completed with actual time.')).toBeInTheDocument();
  });

  it('records output directly from the production-order action without treating it as cancellation', async () => {
    const user = userEvent.setup();
    api.getProductionOrder.mockResolvedValueOnce(order({ status: 'IN_PROCESS', record_version: 3, allowed_actions: ['RECORD_OUTPUT', 'CANCEL'] })).mockResolvedValueOnce(order({ status: 'IN_PROCESS', record_version: 4, allowed_actions: ['RECORD_OUTPUT', 'CANCEL'], good_quantity: '10.000000', accounted_quantity: '10.000000' }));
    api.recordProductionOutput.mockResolvedValue({ id: 'order-1' });
    api.listProductionOrders.mockResolvedValue({ ...orderWorkspace(), data: [order({ status: 'IN_PROCESS', record_version: 3, allowed_actions: ['RECORD_OUTPUT', 'CANCEL'] })] });
    renderPage(<ProductionOrderWorkspace />);
    await user.click((await screen.findAllByRole('button', { name: 'Open' }))[0]);
    fireEvent.change(await screen.findByLabelText('Direct output quantity'), { target: { value: '10' } });
    await user.click(screen.getByRole('button', { name: 'Record output' }));
    await waitFor(() => expect(api.recordProductionOutput).toHaveBeenCalledWith(expect.objectContaining({ id: 'order-1', record_version: 3 }), expect.objectContaining({ event_type: 'GOOD', quantity: '10', reason_code: null }), expect.any(String)));
    expect(api.cancelProductionOrder).not.toHaveBeenCalled();
  });

  it('records accountable output and resolves open rework', async () => {
    const user = userEvent.setup(); api.recordProductionOutput.mockResolvedValue({ id: 'order-1' }); api.resolveProductionRework.mockResolvedValue({ id: 'order-1' });
    vi.spyOn(window, 'prompt').mockReturnValue('Re-seal passed inspection.'); renderPage(<ProductionLossWorkspace />);
    await user.click((await screen.findAllByRole('button', { name: 'Open' }))[0]);
    await user.selectOptions(screen.getByLabelText('Output type'), 'LOSS'); fireEvent.change(screen.getByLabelText('Output quantity'), { target: { value: '3' } }); await user.type(screen.getByLabelText('Reason code'), 'bake-loss');
    await user.click(screen.getByRole('button', { name: 'Record output' }));
    await waitFor(() => expect(api.recordProductionOutput).toHaveBeenCalledWith(expect.objectContaining({ id: 'order-1' }), expect.objectContaining({ event_type: 'LOSS', quantity: '3', reason_code: 'BAKE-LOSS' }), expect.any(String)));
    await user.click(screen.getByRole('button', { name: 'Recover' }));
    await waitFor(() => expect(api.resolveProductionRework).toHaveBeenCalledWith('output-1', { disposition: 'RECOVERED', notes: 'Re-seal passed inspection.' }, expect.any(String)));
  });
});

function renderPage(node: React.ReactNode) { return render(<ErpSessionContext.Provider value={session()}>{node}</ErpSessionContext.Provider>); }
function meta(total = 0) { return { current_page: 1, last_page: 1, per_page: 25, total }; }
function sorts() { return ['NEWEST', 'OLDEST', 'NUMBER', 'STATUS']; }
function order(overrides: Record<string, unknown> = {}) { return { id: 'order-1', order_number: 'PRO-UI-001', batch_number: 'BATCH-UI-001', schedule: { id: 'schedule-1', number: 'SCH-UI-001', line_id: 'schedule-line-1' }, output_sku: { id: 'finished-1', code: 'SKU-APPLE-100', name: 'Apple Snack Pack 100g' }, recipe: { id: 'recipe-1', code: 'REC-APPLE', revision: 1 }, route: { id: 'route-1', code: 'ROUTE-APPLE', name: 'Apple Route' }, planned_quantity: '100.000000', uom_code: 'PACK', planned_start_date: '2026-09-14', planned_end_date: '2026-09-20', status: 'DRAFT', quality_status: 'PENDING', record_version: 1, notes: null, created_by: { id: 'user-1', name: 'Operations Manager' }, released_at: null, started_at: null, completed_at: null, quality_released_at: null, cancelled_at: null, cancellation_reason: null, allowed_actions: ['RELEASE', 'CANCEL'], material_count: 1, issued_material_count: 0, stage_count: 1, completed_stage_count: 0, accounted_quantity: '0.000000', good_quantity: '0.000000', loss_quantity: '0.000000', rework_quantity: '0.000000', open_rework_count: 0, packed_quantity: '0.000000', materials: [{ id: 'material-1', line_number: 1, component_sku: { id: 'raw-1', code: 'SKU-APPLE-BASE', name: 'Apple base' }, required_quantity: '10.304569', issued_quantity: '0.000000', uom_code: 'KG', issues: [] }], stages: [stage()], outputs: [], ...overrides }; }
function stage(overrides: Record<string, unknown> = {}) { return { id: 'stage-1', production_order: { id: 'order-1', number: 'PRO-UI-001', batch_number: 'BATCH-UI-001', status: 'IN_PROCESS' }, sequence_no: 1, operation_name: 'Mix ingredients', work_center_code: 'MIX-01', planned_minutes: '20.000000', actual_minutes: null, status: 'PENDING', record_version: 1, started_at: null, completed_at: null, notes: null, allowed_actions: ['START'], ...overrides }; }
function orderWorkspace() { return { data: [], meta: meta(), summary: { total: 0, draft: 0 }, lookups: { statuses: ['DRAFT', 'RELEASED', 'IN_PROCESS', 'COMPLETED', 'CANCELLED'], sorts: sorts(), schedule_lines: [{ id: 'schedule-line-1', schedule_number: 'SCH-UI-001', line_number: 1, planned_quantity: '100.000000', uom_code: 'PACK', planned_start_date: '2026-09-14', planned_end_date: '2026-09-20', output_sku: { id: 'finished-1', code: 'SKU-APPLE-100', name: 'Apple Snack Pack 100g' } }] }, allowed_actions: ['CREATE'] }; }
function stageWorkspace() { const row = stage(); return { data: [row], meta: meta(1), summary: { total: 1, pending: 1 }, lookups: { statuses: ['PENDING', 'IN_PROGRESS', 'COMPLETED'], sorts: sorts() }, allowed_actions: [] }; }
function lossWorkspace() { const row = order({ status: 'IN_PROCESS', record_version: 3, allowed_actions: ['RECORD_OUTPUT'], outputs: [{ id: 'output-1', sequence_no: 1, event_type: 'REWORK', quantity: '2.000000', uom_code: 'PACK', reason_code: 'SEAL', notes: null, rework_status: 'OPEN', recorded_at: '2026-09-13T10:00:00Z', resolved_at: null, resolution_notes: null, allowed_actions: ['RESOLVE'] }] }); return { data: [row], meta: meta(1), summary: { event_count: 1, open_rework_count: 1 }, lookups: { statuses: ['IN_PROCESS', 'COMPLETED'], sorts: sorts(), event_types: ['GOOD', 'LOSS', 'REWORK'], rework_dispositions: ['RECOVERED', 'SCRAPPED'] }, allowed_actions: [] }; }
function session(): ErpSession { return { user: { id: 'user-1', name: 'Operations Manager', email: 'operations@example.com' }, roles: ['OPERATIONS_MANAGER'], allowed_screens: ['PRO-ORDER', 'PRO-STAGE', 'PRO-LOSS'], allowed_actions: [], contexts: [], selected_context: { company_id: 'company-1', company_name: 'Q & T Foods', plant_id: 'plant-1', plant_name: 'Training Plant' } }; }

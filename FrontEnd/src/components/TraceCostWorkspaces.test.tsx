import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ErpSessionContext } from '../app/ErpSessionContext';
import type { ErpSession } from '../types/session';
import { BatchCostWorkspace, TraceRecallWorkspace } from './TraceCostWorkspaces';

const api = vi.hoisted(() => ({
  listRecallCases: vi.fn(), getRecallCase: vi.fn(), getLotTrace: vi.fn(), createRecallCase: vi.fn(), closeRecallCase: vi.fn(),
  listBatchCosts: vi.fn(), getBatchCost: vi.fn(), calculateBatchCost: vi.fn(),
}));
vi.mock('../api/manufacturingExecution', () => api);

describe('traceability and batch cost workspaces', () => {
  beforeEach(() => { vi.resetAllMocks(); api.listRecallCases.mockResolvedValue(recallWorkspace()); api.listBatchCosts.mockResolvedValue(costWorkspace()); });

  it('inspects genealogy and opens a recall from its source lot', async () => {
    const user = userEvent.setup(); api.getLotTrace.mockResolvedValue(trace()); api.createRecallCase.mockResolvedValue({ id: 'recall-1' }); api.getRecallCase.mockResolvedValue(recall());
    renderPage(<TraceRecallWorkspace />); await user.selectOptions(await screen.findByLabelText('Traceable lot'), 'raw-lot-1'); await user.click(screen.getByRole('button', { name: 'Trace lot' }));
    expect(await screen.findByText('No shipment exposure for this connected lot graph.')).toBeInTheDocument(); expect(screen.getByText('FG-UI-001')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: '+ New' })); await user.type(screen.getByLabelText('Recall number'), 'recall-ui-001'); await user.selectOptions(screen.getByLabelText('Recall source lot'), 'raw-lot-1'); await user.type(screen.getByLabelText('Recall reason'), 'Supplier contamination notification received.'); await user.click(screen.getByRole('button', { name: 'Open recall and block stock' }));
    await waitFor(() => expect(api.createRecallCase).toHaveBeenCalledWith(expect.objectContaining({ recall_number: 'RECALL-UI-001', source_lot_id: 'raw-lot-1', classification: 'CLASS_II' }), expect.any(String))); expect(await screen.findByText('Recall opened; all connected lots were snapshotted and on-hand stock blocked.')).toBeInTheDocument();
  });

  it('finalizes and displays material, time, yield and unit-cost variance', async () => {
    const user = userEvent.setup(); api.calculateBatchCost.mockResolvedValue({ id: 'cost-1' }); api.getBatchCost.mockResolvedValue(cost());
    renderPage(<BatchCostWorkspace />); await user.click(await screen.findByRole('button', { name: '+ New' })); await user.type(screen.getByLabelText('Cost number'), 'cost-ui-001'); await user.selectOptions(screen.getByLabelText('Cost production batch'), 'order-1'); fireEvent.change(screen.getByLabelText('Labour rate per minute'), { target: { value: '2.5' } }); fireEvent.change(screen.getByLabelText('Overhead rate per minute'), { target: { value: '1.25' } }); fireEvent.change(screen.getByLabelText('SKU-APPLE-BASE unit cost'), { target: { value: '50' } }); await user.click(screen.getByRole('button', { name: 'Finalize cost snapshot' }));
    await waitFor(() => expect(api.calculateBatchCost).toHaveBeenCalledWith(expect.objectContaining({ cost_number: 'COST-UI-001', production_order_id: 'order-1', material_costs: [{ production_order_material_id: 'material-1', unit_cost: '50' }] }), expect.any(String))); expect(await screen.findByText('Material usage variance')).toBeInTheDocument(); expect(screen.getByText('Stage time variance')).toBeInTheDocument();
  });
});

function renderPage(node: React.ReactNode) { return render(<ErpSessionContext.Provider value={session()}>{node}</ErpSessionContext.Provider>); }
function meta(total = 0) { return { current_page: 1, last_page: 1, per_page: 25, total }; } function sorts() { return ['NEWEST', 'OLDEST', 'NUMBER', 'STATUS']; }
const raw = { id: 'raw-lot-1', code: 'RM-APPLE-2609A', status: 'ACTIVE', item_code: 'SKU-APPLE-BASE', item_name: 'Apple base' }; const finished = { id: 'finished-lot-1', code: 'FG-UI-001', status: 'ACTIVE', item: { id: 'finished-1', code: 'SKU-APPLE-100', name: 'Apple Snack Pack 100g' }, depth: 1 };
function trace() { return { lot: raw, nodes: [{ ...raw, depth: 0 }, finished], edges: [{ id: 'edge-1', input_lot_id: 'raw-lot-1', output_lot_id: 'finished-lot-1', input_quantity: '10.304569', output_quantity: '97.000000', production_order_id: 'order-1', packing_run_id: 'pack-1' }], shipments: [] }; }
function recall() { return { id: 'recall-1', recall_number: 'RECALL-UI-001', source_lot: { id: 'raw-lot-1', code: 'RM-APPLE-2609A' }, classification: 'CLASS_II', reason: 'Supplier contamination notification received.', status: 'OPEN', record_version: 1, initiated_at: '2026-09-13T10:00:00Z', closed_at: null, closure_action: null, affected_lot_count: 2, allowed_actions: ['CLOSE'], lots: [{ ...raw, relationship: 'SOURCE', depth: 0, on_hand_quantity: '114.695431', action_status: 'BLOCKED' }, { ...finished, relationship: 'DOWNSTREAM', on_hand_quantity: '97.000000', action_status: 'BLOCKED' }], trace: trace() }; }
function recallWorkspace() { return { data: [], meta: meta(), summary: { total: 0, open: 0 }, lookups: { statuses: ['OPEN', 'CLOSED'], sorts: sorts(), classifications: ['CLASS_I', 'CLASS_II', 'CLASS_III', 'WITHDRAWAL'], lots: [raw, finished] }, allowed_actions: ['CREATE'] }; }
function cost() { return { id: 'cost-1', cost_number: 'COST-UI-001', production_order: { id: 'order-1', number: 'PRO-UI-001', batch_number: 'BATCH-UI-001', output_sku_id: 'finished-1' }, snapshot_version: 1, currency: 'INR', labour_rate_per_minute: '2.500000', overhead_rate_per_minute: '1.250000', planned_material_cost: '515.228450', actual_material_cost: '515.228450', planned_conversion_cost: '300.000000', actual_conversion_cost: '315.000000', planned_total_cost: '815.228450', actual_total_cost: '830.228450', total_variance: '15.000000', variance_percent: '1.839975', good_quantity: '97.000000', yield_percent: '97.000000', cost_per_good_unit: '8.558025', status: 'FINALIZED', calculated_at: '2026-09-13T10:00:00Z', materials: [{ id: 'cost-material-1', item_code: 'SKU-APPLE-BASE', item_name: 'Apple base', planned_quantity: '10.304569', actual_quantity: '10.304569', uom_code: 'KG', unit_cost: '50.000000', planned_cost: '515.228450', actual_cost: '515.228450', usage_variance: '0.000000' }], stages: [{ id: 'cost-stage-1', work_center_code: 'MIX-01', planned_minutes: '20.000000', actual_minutes: '24.000000', planned_cost: '75.000000', actual_cost: '90.000000', time_variance: '15.000000' }] }; }
function costWorkspace() { return { data: [], meta: meta(), summary: { total: 0, actual_total_cost: '0.000000', total_variance: '0.000000' }, lookups: { statuses: ['FINALIZED'], sorts: sorts(), production_orders: [{ id: 'order-1', number: 'PRO-UI-001', batch_number: 'BATCH-UI-001', materials: [{ id: 'material-1', item_code: 'SKU-APPLE-BASE', item_name: 'Apple base', required_quantity: '10.304569', issued_quantity: '10.304569', uom_code: 'KG' }] }] }, allowed_actions: ['CALCULATE'] }; }
function session(): ErpSession { return { user: { id: 'user-1', name: 'Operations Manager', email: 'operations@example.com' }, roles: ['OPERATIONS_MANAGER'], allowed_screens: ['TRACE-CASE', 'COST-BATCH'], allowed_actions: [], contexts: [], selected_context: { company_id: 'company-1', company_name: 'Q & T Foods', plant_id: 'plant-1', plant_name: 'Training Plant' } }; }

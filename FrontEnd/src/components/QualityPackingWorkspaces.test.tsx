import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ErpSessionContext } from '../app/ErpSessionContext';
import type { ErpSession } from '../types/session';
import { FinishedGoodsWorkspace, PackagingArtworkWorkspace, PackingRunWorkspace } from './PackingExecutionWorkspaces';
import { LabSampleWorkspace, QualitySafetyWorkspace } from './QualityExecutionWorkspaces';

const api = vi.hoisted(() => ({
  listLabSamples: vi.fn(), getLabSample: vi.fn(), createLabSample: vi.fn(), completeLabSample: vi.fn(),
  listSafetyRecords: vi.fn(), getSafetyRecord: vi.fn(), createFoodSafetyHold: vi.fn(), releaseFoodSafetyHold: vi.fn(), resolveQualityDeviation: vi.fn(), releaseBatchQuality: vi.fn(),
  listArtworks: vi.fn(), getArtwork: vi.fn(), createArtwork: vi.fn(), updateArtwork: vi.fn(), approveArtwork: vi.fn(), retireArtwork: vi.fn(),
  listPackingRuns: vi.fn(), getPackingRun: vi.fn(), createPackingRun: vi.fn(), completePackingRun: vi.fn(), cancelPackingRun: vi.fn(),
  listFinishedGoods: vi.fn(), getFinishedLot: vi.fn(),
}));
vi.mock('../api/manufacturingExecution', () => api);

describe('quality and packing workspaces', () => {
  beforeEach(() => {
    vi.resetAllMocks(); api.listLabSamples.mockResolvedValue(labWorkspace()); api.listSafetyRecords.mockResolvedValue(safetyWorkspace()); api.listArtworks.mockResolvedValue(artworkWorkspace()); api.listPackingRuns.mockResolvedValue(packingWorkspace()); api.listFinishedGoods.mockResolvedValue(finishedWorkspace());
  });

  it('creates and evaluates a versioned lab sample', async () => {
    const user = userEvent.setup(); api.createLabSample.mockResolvedValue({ id: 'sample-1' }); api.completeLabSample.mockResolvedValue({ id: 'sample-1' }); api.getLabSample.mockResolvedValue(sample());
    renderPage(<LabSampleWorkspace />); await user.click(await screen.findByRole('button', { name: '+ New' }));
    await user.type(screen.getByLabelText('Sample number'), 'lab-ui-001'); await user.selectOptions(screen.getByLabelText('Completed production order'), 'order-1'); await user.selectOptions(screen.getByLabelText('Specification'), 'spec-1'); await user.click(screen.getByRole('button', { name: 'Create lab sample' }));
    await waitFor(() => expect(api.createLabSample).toHaveBeenCalledWith(expect.objectContaining({ sample_number: 'LAB-UI-001', production_order_id: 'order-1', specification_id: 'spec-1' }), expect.any(String)));
    fireEvent.change(await screen.findByLabelText('NET-WEIGHT result'), { target: { value: '100.2' } }); await user.click(screen.getByRole('button', { name: 'Complete and evaluate sample' }));
    await waitFor(() => expect(api.completeLabSample).toHaveBeenCalledWith(expect.objectContaining({ id: 'sample-1', record_version: 1 }), { results: [expect.objectContaining({ result_id: 'result-1', numeric_value: '100.2' })] }, expect.any(String)));
  });

  it('releases an active food-safety hold with corrective evidence', async () => {
    const user = userEvent.setup(); api.getSafetyRecord.mockResolvedValue(hold()); api.releaseFoodSafetyHold.mockResolvedValue({ id: 'hold-1' }); vi.spyOn(window, 'prompt').mockReturnValueOnce('Verified allergen clean-down evidence.').mockReturnValueOnce('Cleaning record was awaiting verification.').mockReturnValueOnce('ACCEPTED');
    renderPage(<QualitySafetyWorkspace />); await user.click((await screen.findAllByRole('button', { name: 'Open' }))[0]); await user.click(await screen.findByRole('button', { name: 'Release hold' }));
    await waitFor(() => expect(api.releaseFoodSafetyHold).toHaveBeenCalledWith(expect.objectContaining({ id: 'hold-1', record_version: 1 }), { disposition: 'ACCEPTED', root_cause: 'Cleaning record was awaiting verification.', corrective_action: 'Verified allergen clean-down evidence.' }, expect.any(String)));
  });

  it('creates and approves an effective artwork revision', async () => {
    const user = userEvent.setup(); api.createArtwork.mockResolvedValue({ id: 'art-1' }); api.approveArtwork.mockResolvedValue({ id: 'art-1' }); api.getArtwork.mockResolvedValueOnce(artwork()).mockResolvedValueOnce(artwork({ status: 'APPROVED', record_version: 2, allowed_actions: ['RETIRE'], approved_at: '2026-09-13T10:00:00Z' }));
    renderPage(<PackagingArtworkWorkspace />); await user.click(await screen.findByRole('button', { name: '+ New' })); await user.selectOptions(screen.getByLabelText('Artwork finished SKU'), 'finished-1'); await user.type(screen.getByLabelText('Artwork code'), 'art-ui-001'); await user.type(screen.getByLabelText('Label name'), 'Apple retail label'); fireEvent.change(screen.getByLabelText('Barcode'), { target: { value: '8901000000011' } }); await user.click(screen.getByRole('button', { name: 'Create artwork revision' }));
    await waitFor(() => expect(api.createArtwork).toHaveBeenCalledWith(expect.objectContaining({ artwork_code: 'ART-UI-001', output_sku_id: 'finished-1', revision: 1 }), expect.any(String))); await user.click(await screen.findByRole('button', { name: 'Approve artwork' })); await waitFor(() => expect(api.approveArtwork).toHaveBeenCalled());
  });

  it('completes a packing run into stock and exposes finished-lot genealogy', async () => {
    const user = userEvent.setup(); api.createPackingRun.mockResolvedValue({ id: 'pack-1' }); api.completePackingRun.mockResolvedValue({ id: 'pack-1' }); api.getPackingRun.mockResolvedValueOnce(packing()).mockResolvedValueOnce(packing({ status: 'COMPLETED', record_version: 2, allowed_actions: [], finished_lot_id: 'lot-fg-1', completed_at: '2026-09-13T10:00:00Z', genealogy_edges: [{ id: 'edge-1', input_lot_id: 'raw-lot-1', input_lot_code: 'RM-APPLE-2609A', input_quantity: '10.304569', input_uom_code: 'KG', output_quantity: '97.000000', output_uom_code: 'PACK' }] }));
    renderPage(<PackingRunWorkspace />); await user.click(await screen.findByRole('button', { name: '+ New' })); await user.type(screen.getByLabelText('Run number'), 'pack-ui-001'); await user.type(screen.getByLabelText('Finished lot code'), 'fg-ui-001'); await user.selectOptions(screen.getByLabelText('Quality-released batch'), 'order-1'); await user.selectOptions(screen.getByLabelText('Approved artwork'), 'art-1'); await user.selectOptions(screen.getByLabelText('Finished-goods location'), 'location-1'); await user.click(screen.getByRole('button', { name: 'Create packing run' }));
    await waitFor(() => expect(api.createPackingRun).toHaveBeenCalledWith(expect.objectContaining({ run_number: 'PACK-UI-001', finished_lot_code: 'FG-UI-001', packed_quantity: '97' }), expect.any(String))); await user.click(await screen.findByRole('button', { name: 'Complete and receive stock' })); await waitFor(() => expect(api.completePackingRun).toHaveBeenCalled()); expect(await screen.findByText('RM-APPLE-2609A')).toBeInTheDocument();
  });

  it('opens a finished lot with its upstream input provenance', async () => {
    const user = userEvent.setup(); api.getFinishedLot.mockResolvedValue(finishedLot()); renderPage(<FinishedGoodsWorkspace />); await user.click((await screen.findAllByRole('button', { name: 'Open' }))[0]); expect(await screen.findByText('RM-APPLE-2609A')).toBeInTheDocument(); expect(screen.getByText(/LOT FG-UI-001/)).toBeInTheDocument();
  });
});

function renderPage(node: React.ReactNode) { return render(<ErpSessionContext.Provider value={session()}>{node}</ErpSessionContext.Provider>); }
function meta(total = 0) { return { current_page: 1, last_page: 1, per_page: 25, total }; } function sorts() { return ['NEWEST', 'OLDEST', 'NUMBER', 'STATUS']; }
const output = { id: 'finished-1', code: 'SKU-APPLE-100', name: 'Apple Snack Pack 100g' }; const qualityOrder = { id: 'order-1', number: 'PRO-UI-001', batch_number: 'BATCH-UI-001', record_version: 8, status: 'COMPLETED', quality_status: 'PENDING', output_sku: output, good_quantity: '97.000000', packed_quantity: '0.000000' };
function sample() { return { id: 'sample-1', sample_number: 'LAB-UI-001', production_order: { id: 'order-1', number: 'PRO-UI-001', batch_number: 'BATCH-UI-001', output_sku_id: 'finished-1' }, specification: { id: 'spec-1', code: 'SPEC-APPLE-100', name: 'Apple pack release', version: 1 }, status: 'PENDING', record_version: 1, notes: null, sampled_at: '2026-09-13T09:00:00Z', completed_at: null, allowed_actions: ['COMPLETE'], results: [{ id: 'result-1', sequence_no: 1, code: 'NET-WEIGHT', name: 'Net weight', value_type: 'NUMERIC', uom_code: 'G', minimum_value: '98.000000', target_value: '100.000000', maximum_value: '102.000000', text_requirement: null, is_required: true, numeric_value: null, text_value: null, boolean_value: null, result: 'PENDING', notes: null }] }; }
function labWorkspace() { return { data: [], meta: meta(), summary: { total: 0, pending: 0 }, lookups: { statuses: ['PENDING', 'PASSED', 'FAILED'], sorts: sorts(), production_orders: [qualityOrder], specifications: [{ id: 'spec-1', code: 'SPEC-APPLE-100', name: 'Apple pack release', target_type: 'SKU', catalog_item_id: null, sku_id: 'finished-1', effective_from: '2026-01-01', effective_to: null }] }, allowed_actions: ['CREATE'] }; }
function hold() { return { kind: 'HOLD', id: 'hold-1', number: 'HOLD-UI-001', production_order: { id: 'order-1', number: 'PRO-UI-001', batch_number: 'BATCH-UI-001' }, category: 'ALLERGEN', description: 'Awaiting allergen verification.', status: 'ACTIVE', corrective_action: null, record_version: 1, occurred_at: '2026-09-13T09:00:00Z', resolved_at: null, allowed_actions: ['RELEASE'] }; }
function safetyWorkspace() { return { data: [hold()], meta: meta(1), summary: { total: 1, active_holds: 1 }, lookups: { statuses: ['OPEN', 'RESOLVED', 'ACTIVE', 'RELEASED'], sorts: sorts(), hazard_types: ['ALLERGEN', 'MICROBIOLOGICAL'], deviation_dispositions: ['REWORK', 'SCRAP', 'USE_AS_IS'], production_orders: [{ ...qualityOrder, quality_status: 'HELD' }] }, allowed_actions: ['HOLD'] }; }
function artwork(overrides: Record<string, unknown> = {}) { return { id: 'art-1', artwork_code: 'ART-UI-001', revision: 1, output_sku: output, label_name: 'Apple retail label', barcode: '8901000000011', coding_template: 'LOT {LOT} MFG {MFG} EXP {EXP} BATCH {BATCH}', effective_from: '2026-09-13', effective_to: null, status: 'DRAFT', record_version: 1, notes: null, approved_at: null, retired_at: null, retirement_reason: null, allowed_actions: ['UPDATE', 'APPROVE'], ...overrides }; }
function artworkWorkspace() { return { data: [], meta: meta(), summary: { total: 0 }, lookups: { statuses: ['DRAFT', 'APPROVED', 'RETIRED'], sorts: sorts(), output_skus: [{ ...output, barcode: '8901000000011' }] }, allowed_actions: ['CREATE'] }; }
function packing(overrides: Record<string, unknown> = {}) { return { id: 'pack-1', run_number: 'PACK-UI-001', production_order: { id: 'order-1', number: 'PRO-UI-001', batch_number: 'BATCH-UI-001', output_sku_id: 'finished-1' }, artwork: { id: 'art-1', code: 'ART-UI-001', revision: 1 }, packed_quantity: '97.000000', uom_code: 'PACK', finished_lot_code: 'FG-UI-001', manufacture_date: '2026-09-13', expiry_date: '2027-03-13', target_location: { id: 'location-1', code: 'FG-01' }, coding_value: 'LOT FG-UI-001 MFG 2026-09-13 EXP 2027-03-13 BATCH BATCH-UI-001', status: 'DRAFT', record_version: 1, notes: null, finished_lot_id: null, finished_position_id: null, stock_movement_id: null, completed_at: null, cancelled_at: null, cancellation_reason: null, allowed_actions: ['COMPLETE', 'CANCEL'], ...overrides }; }
function packingWorkspace() { return { data: [], meta: meta(), summary: { total: 0 }, lookups: { statuses: ['DRAFT', 'COMPLETED', 'CANCELLED'], sorts: sorts(), production_orders: [{ ...qualityOrder, quality_status: 'RELEASED' }], artworks: [{ id: 'art-1', output_sku_id: 'finished-1', artwork_code: 'ART-UI-001', revision: 1, label_name: 'Apple retail label', effective_from: '2026-09-13', effective_to: null }], locations: [{ id: 'location-1', code: 'FG-01', name: 'Finished goods' }] }, allowed_actions: ['CREATE'] }; }
function finishedLot() { return { id: 'lot-fg-1', lot_code: 'FG-UI-001', sku: output, production_order: { id: 'order-1', number: 'PRO-UI-001', batch_number: 'BATCH-UI-001' }, packing_run: { id: 'pack-1', number: 'PACK-UI-001' }, manufacture_date: '2026-09-13', expiry_date: '2027-03-13', status: 'ACTIVE', record_version: 1, position: { id: 'position-1', quantity: '97.000000', uom_code: 'PACK', quality_status: 'RELEASED', location_code: 'FG-01', location_name: 'Finished goods' }, coding_value: 'LOT FG-UI-001 MFG 2026-09-13 EXP 2027-03-13 BATCH BATCH-UI-001', inputs: [{ lot: { id: 'raw-lot-1', code: 'RM-APPLE-2609A', item_code: 'SKU-APPLE-BASE', item_name: 'Apple base' }, quantity: '10.304569', uom_code: 'KG' }] }; }
function finishedWorkspace() { const row = finishedLot(); return { data: [row], meta: meta(1), summary: { total: 1, active: 1, on_hand_quantity: '97.000000' }, lookups: { statuses: ['ACTIVE', 'CLOSED', 'RECALLED'], sorts: sorts() }, allowed_actions: [] }; }
function session(): ErpSession { return { user: { id: 'user-1', name: 'Operations Manager', email: 'operations@example.com' }, roles: ['OPERATIONS_MANAGER'], allowed_screens: ['QC-LAB', 'QC-SAFE', 'PACK-ART', 'PACK-RUN', 'FG-LOT'], allowed_actions: [], contexts: [], selected_context: { company_id: 'company-1', company_name: 'Q & T Foods', plant_id: 'plant-1', plant_name: 'Training Plant' } }; }

import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
  GateEntry, GateWorkspace, GoodsReceipt, IncomingQualityTask, QualityWorkspace,
  ReceiptWorkspace, ReturnWorkspace, SupplierReturn,
} from '../api/procureToPay';
import { ErpSessionContext } from '../app/ErpSessionContext';
import type { ErpSession } from '../types/session';
import {
  GateEntryWorkspace, GoodsReceiptWorkspace, IncomingQualityWorkspace, SupplierReturnWorkspace,
} from './InboundProcurementWorkspaces';

const apiMocks = vi.hoisted(() => ({
  listGateEntries: vi.fn(), getGateEntry: vi.fn(), createGateEntry: vi.fn(), updateGateEntry: vi.fn(), cancelGateEntry: vi.fn(),
  listReceipts: vi.fn(), getReceipt: vi.fn(), createReceipt: vi.fn(), updateReceipt: vi.fn(), postReceipt: vi.fn(), cancelReceipt: vi.fn(),
  listIncomingQuality: vi.fn(), getIncomingQuality: vi.fn(), completeIncomingQuality: vi.fn(),
  listSupplierReturns: vi.fn(), getSupplierReturn: vi.fn(), createSupplierReturn: vi.fn(), updateSupplierReturn: vi.fn(), postSupplierReturn: vi.fn(), cancelSupplierReturn: vi.fn(),
}));

vi.mock('../api/procureToPay', async () => {
  const actual = await vi.importActual<typeof import('../api/procureToPay')>('../api/procureToPay');
  return { ...actual, ...apiMocks };
});

describe('inbound procure-to-pay workspaces', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    apiMocks.listGateEntries.mockResolvedValue(gateWorkspace());
    apiMocks.listReceipts.mockResolvedValue(receiptWorkspace());
    apiMocks.listIncomingQuality.mockResolvedValue(qualityWorkspace());
    apiMocks.listSupplierReturns.mockResolvedValue(returnWorkspace());
  });

  it('records a supplier vehicle arrival against an issued purchase order', async () => {
    const user = userEvent.setup();
    apiMocks.createGateEntry.mockResolvedValue(command('gate-1', 'ARRIVED'));
    apiMocks.getGateEntry.mockResolvedValue(gate());
    renderPage(<GateEntryWorkspace />);

    await user.click(await screen.findByRole('button', { name: '+ New' }));
    await user.type(screen.getByLabelText('Gate-entry number'), 'gate-ui-001');
    await user.selectOptions(screen.getByLabelText('Issued purchase order'), 'po-1');
    await user.type(screen.getByLabelText('Vehicle number'), 'mh12qt1234');
    await user.type(screen.getByLabelText('Transporter'), 'Controlled Logistics');
    await user.type(screen.getByLabelText('Supplier document'), 'CHALLAN-001');
    await user.click(screen.getByRole('button', { name: 'Record arrival' }));

    await waitFor(() => expect(apiMocks.createGateEntry).toHaveBeenCalledWith(expect.objectContaining({
      gate_entry_number: 'GATE-UI-001', purchase_order_id: 'po-1', vehicle_number: 'MH12QT1234',
      transporter_name: 'Controlled Logistics', supplier_document_number: 'CHALLAN-001',
    }), expect.any(String)));
    expect(await screen.findByText('Vehicle arrival recorded.')).toBeInTheDocument();
  });

  it('creates and posts a partial GRN into Quality Hold', async () => {
    const user = userEvent.setup();
    const draft = receipt();
    const posted = receipt({ status: 'QC_PENDING', record_version: 2, allowed_actions: [], posted_at: '2026-09-11T10:00:00Z', quality_task: { id: 'qc-1', task_number: 'IQC-UI-001', status: 'PENDING', record_version: 1 } });
    apiMocks.createReceipt.mockResolvedValue(command('receipt-1', 'DRAFT'));
    apiMocks.postReceipt.mockResolvedValue(command('receipt-1', 'QC_PENDING', 2));
    apiMocks.getReceipt.mockResolvedValueOnce(draft).mockResolvedValueOnce(posted);
    renderPage(<GoodsReceiptWorkspace />);

    await user.click(await screen.findByRole('button', { name: '+ New' }));
    await user.selectOptions(screen.getByLabelText('Arrived gate entry'), 'gate-1');
    await user.type(screen.getByLabelText('GRN number'), 'grn-ui-001');
    fireEvent.change(screen.getByLabelText('Line 1 received quantity'), { target: { value: '4' } });
    await user.type(screen.getByLabelText('Line 1 internal lot'), 'LOT-UI-001');
    await user.click(screen.getByRole('button', { name: 'Create draft GRN' }));

    await waitFor(() => expect(apiMocks.createReceipt).toHaveBeenCalledWith(expect.objectContaining({
      receipt_number: 'GRN-UI-001', gate_entry_id: 'gate-1',
      lines: [expect.objectContaining({ purchase_order_line_id: 'po-line-1', received_quantity: '4', internal_lot_code: 'LOT-UI-001', quality_hold_location_id: 'qc-hold', released_location_id: 'raw-store' })],
    }), expect.any(String)));
    await user.click(await screen.findByRole('button', { name: 'Post document' }));
    await waitFor(() => expect(apiMocks.postReceipt).toHaveBeenCalledWith(expect.objectContaining({ id: 'receipt-1', record_version: 1 }), expect.any(String)));
    expect(await screen.findByText('GRN posted to Quality Hold and incoming QC created.')).toBeInTheDocument();
  });

  it('splits held material into accepted and rejected stock during incoming QC', async () => {
    const user = userEvent.setup();
    const pending = qualityTask();
    const completed = qualityTask({ status: 'COMPLETED', record_version: 2, allowed_actions: [], completed_at: '2026-09-11T11:00:00Z', completed_by: actor() });
    apiMocks.listIncomingQuality.mockResolvedValue(qualityWorkspace([pending]));
    apiMocks.getIncomingQuality.mockResolvedValueOnce(pending).mockResolvedValueOnce(completed);
    apiMocks.completeIncomingQuality.mockResolvedValue(command('qc-1', 'COMPLETED', 2));
    renderPage(<IncomingQualityWorkspace />);

    await user.click(await screen.findByRole('button', { name: 'Open' }));
    fireEvent.change(screen.getByLabelText('Line 1 accepted quantity'), { target: { value: '8' } });
    fireEvent.change(screen.getByLabelText('Line 1 rejected quantity'), { target: { value: '2' } });
    await user.type(screen.getByLabelText('Rejection reason'), 'Moisture outside specification.');
    await user.type(screen.getByLabelText('Inspection notes'), 'Sampling plan completed.');
    await user.click(screen.getByRole('button', { name: 'Complete incoming QC' }));

    await waitFor(() => expect(apiMocks.completeIncomingQuality).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'qc-1', record_version: 1 }),
      { notes: 'Sampling plan completed.', lines: [{ quality_line_id: 'qc-line-1', accepted_quantity: '8', rejected_quantity: '2', rejection_reason: 'Moisture outside specification.' }] },
      expect.any(String),
    ));
    expect(await screen.findByText('Incoming QC completed and accepted/rejected stock posted.')).toBeInTheDocument();
  });

  it('creates and posts a supplier return only from rejected stock', async () => {
    const user = userEvent.setup();
    const draft = supplierReturn();
    const posted = supplierReturn({ status: 'POSTED', record_version: 2, allowed_actions: [], posted_at: '2026-09-11T12:00:00Z', lines: [{ ...supplierReturn().lines![0]!, movement_id: 'movement-return-1' }] });
    apiMocks.createSupplierReturn.mockResolvedValue(command('return-1', 'DRAFT'));
    apiMocks.postSupplierReturn.mockResolvedValue(command('return-1', 'POSTED', 2));
    apiMocks.getSupplierReturn.mockResolvedValueOnce(draft).mockResolvedValueOnce(posted);
    renderPage(<SupplierReturnWorkspace />);

    await user.click(await screen.findByRole('button', { name: '+ New' }));
    await user.selectOptions(screen.getByLabelText('Rejected QC lot'), 'qc-line-1');
    await user.type(screen.getByLabelText('Return number'), 'srt-ui-001');
    await user.type(screen.getByLabelText('Return reason'), 'Return rejected incoming stock to supplier.');
    await user.click(screen.getByRole('button', { name: 'Create draft return' }));

    await waitFor(() => expect(apiMocks.createSupplierReturn).toHaveBeenCalledWith(expect.objectContaining({
      return_number: 'SRT-UI-001', reason: 'Return rejected incoming stock to supplier.',
      lines: [{ quality_line_id: 'qc-line-1', return_quantity: '2.000000', reason: null }],
    }), expect.any(String)));
    await user.click(await screen.findByRole('button', { name: 'Post document' }));
    await waitFor(() => expect(apiMocks.postSupplierReturn).toHaveBeenCalledWith(expect.objectContaining({ id: 'return-1', record_version: 1 }), expect.any(String)));
    expect(await screen.findByText('Rejected stock returned to the supplier.')).toBeInTheDocument();
  });
});

function renderPage(component: React.ReactNode) {
  return render(<ErpSessionContext.Provider value={session()}>{component}</ErpSessionContext.Provider>);
}

function pageMeta(total = 0) { return { current_page: 1, last_page: 1, per_page: 25, total }; }
function actor() { return { id: 'operations-1', name: 'Operations Manager' }; }
function supplier() { return { id: 'supplier-1', code: 'SUP-WEST', name: 'Western Ingredients' }; }
function item() { return { id: 'item-1', code: 'RAW-APPLE', name: 'Apple concentrate' }; }
function command(id: string, status: string, recordVersion = 1) { return { id, status, record_version: recordVersion }; }

function gateWorkspace(data: GateEntry[] = []): GateWorkspace {
  return { data, meta: pageMeta(data.length), summary: {}, lookups: { statuses: ['ARRIVED', 'CLEARED', 'CANCELLED'], issued_orders: [{ id: 'po-1', number: 'PO-UI-001', required_by_date: '2026-09-25', supplier: supplier() }] }, allowed_actions: ['CREATE'] };
}

function gate(overrides: Partial<GateEntry> = {}): GateEntry {
  return { id: 'gate-1', gate_entry_number: 'GATE-UI-001', purchase_order: { id: 'po-1', number: 'PO-UI-001' }, supplier: supplier(), vehicle_number: 'MH12QT1234', transporter_name: 'Controlled Logistics', supplier_document_number: 'CHALLAN-001', arrived_at: '2026-09-11T09:00:00Z', notes: null, status: 'ARRIVED', record_version: 1, created_by: actor(), cleared_at: null, cancelled_at: null, cancellation_reason: null, allowed_actions: ['UPDATE', 'CANCEL'], receipt: null, ...overrides };
}

function receiptWorkspace(data: GoodsReceipt[] = []): ReceiptWorkspace {
  return { data, meta: pageMeta(data.length), summary: {}, lookups: { statuses: ['DRAFT', 'QC_PENDING', 'COMPLETED', 'CANCELLED'], arrived_gate_entries: [{ id: 'gate-1', number: 'GATE-UI-001', purchase_order: { id: 'po-1', number: 'PO-UI-001' }, supplier: supplier(), supplier_document_number: 'CHALLAN-001', lines: [{ id: 'po-line-1', line_number: 1, item: item(), description: 'Apple concentrate', ordered_quantity: '10.000000', received_quantity: '0.000000', remaining_quantity: '10.000000', uom_code: 'KG' }] }], quality_hold_locations: [{ id: 'qc-hold', code: 'QC-HOLD', name: 'Quality Hold', location_type: 'QUALITY_HOLD' }], released_locations: [{ id: 'raw-store', code: 'RAW-STORE', name: 'Raw Store', location_type: 'RAW_MATERIAL' }] }, allowed_actions: ['CREATE'] };
}

function receipt(overrides: Partial<GoodsReceipt> = {}): GoodsReceipt {
  return { id: 'receipt-1', receipt_number: 'GRN-UI-001', gate_entry: { id: 'gate-1', number: 'GATE-UI-001' }, purchase_order: { id: 'po-1', number: 'PO-UI-001' }, supplier: supplier(), receipt_date: '2026-09-11', supplier_document_number: 'CHALLAN-001', notes: null, status: 'DRAFT', record_version: 1, line_count: 1, received_total: '4.000000', created_by: actor(), posted_at: null, cancelled_at: null, cancellation_reason: null, allowed_actions: ['UPDATE', 'POST', 'CANCEL'], quality_task: null, lines: [{ id: 'receipt-line-1', purchase_order_line_id: 'po-line-1', line_number: 1, item: item(), description: 'Apple concentrate', ordered_quantity: '10.000000', received_quantity: '4.000000', accepted_quantity: '0.000000', rejected_quantity: '0.000000', uom_code: 'KG', internal_lot_code: 'LOT-UI-001', supplier_lot_code: null, manufacture_date: null, expiry_date: null, quality_hold_location: { id: 'qc-hold', code: 'QC-HOLD' }, released_location: { id: 'raw-store', code: 'RAW-STORE' }, lot_id: null, quality_hold_position_id: null, stock_receipt_movement_id: null, notes: null }], ...overrides };
}

function qualityWorkspace(data: IncomingQualityTask[] = []): QualityWorkspace {
  return { data, meta: pageMeta(data.length), summary: {}, lookups: { statuses: ['PENDING', 'COMPLETED'] }, allowed_actions: [] };
}

function qualityTask(overrides: Partial<IncomingQualityTask> = {}): IncomingQualityTask {
  return { id: 'qc-1', task_number: 'IQC-UI-001', receipt: { id: 'receipt-1', number: 'GRN-UI-001' }, purchase_order: { id: 'po-1', number: 'PO-UI-001' }, supplier: supplier(), status: 'PENDING', record_version: 1, line_count: 1, notes: null, created_by: actor(), completed_by: null, completed_at: null, allowed_actions: ['COMPLETE'], lines: [{ id: 'qc-line-1', line_number: 1, item: item(), lot: { id: 'lot-1', internal_code: 'LOT-UI-001', supplier_code: null }, inspected_quantity: '10.000000', accepted_quantity: '0.000000', rejected_quantity: '0.000000', uom_code: 'KG', result: 'PENDING', rejection_reason: null, quality_hold_position_id: 'hold-position', released_position_id: null, rejected_position_id: null, accepted_movement_id: null, rejected_movement_id: null }], ...overrides };
}

function returnWorkspace(data: SupplierReturn[] = []): ReturnWorkspace {
  return { data, meta: pageMeta(data.length), summary: {}, lookups: { statuses: ['DRAFT', 'POSTED', 'CANCELLED'], rejected_lines: [{ id: 'qc-line-1', task_number: 'IQC-UI-001', receipt_number: 'GRN-UI-001', supplier: supplier(), item: item(), lot: { id: 'lot-1', internal_code: 'LOT-UI-001' }, rejected_quantity: '2.000000', returned_quantity: '0.000000', returnable_quantity: '2.000000', uom_code: 'KG', rejected_position_id: 'rejected-position' }] }, allowed_actions: ['CREATE'] };
}

function supplierReturn(overrides: Partial<SupplierReturn> = {}): SupplierReturn {
  return { id: 'return-1', return_number: 'SRT-UI-001', supplier: supplier(), return_date: '2026-09-11', reason: 'Return rejected incoming stock to supplier.', status: 'DRAFT', record_version: 1, line_count: 1, return_total: '2.000000', created_by: actor(), posted_at: null, cancelled_at: null, cancellation_reason: null, allowed_actions: ['UPDATE', 'POST', 'CANCEL'], lines: [{ id: 'return-line-1', quality_line_id: 'qc-line-1', line_number: 1, item: item(), lot: { id: 'lot-1', internal_code: 'LOT-UI-001' }, return_quantity: '2.000000', uom_code: 'KG', rejected_position_id: 'rejected-position', movement_id: null, reason: null }], ...overrides };
}

function session(): ErpSession {
  return { user: { ...actor(), email: 'operations@example.com' }, roles: ['OPERATIONS_MANAGER'], allowed_screens: ['INB-GATE', 'INB-GRN', 'QC-IN', 'INB-RETURN'], allowed_actions: [], contexts: [], selected_context: { company_id: 'company-1', company_name: 'Q & T Foods', plant_id: 'plant-1', plant_name: 'Training Plant' } };
}

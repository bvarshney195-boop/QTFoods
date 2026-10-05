import { fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
  InventoryOperation,
  InventoryOperationResource,
  InventoryOperationType,
  InventoryOperationWorkspace,
  OperationPosition,
} from '../api/inventoryOperations';
import { ErpSessionContext } from '../app/ErpSessionContext';
import type { ErpSession } from '../types/session';
import { InventoryOperationsWorkspace } from './InventoryOperationsWorkspace';

const apiMocks = vi.hoisted(() => ({
  listInventoryOperations: vi.fn(),
  getInventoryOperation: vi.fn(),
  createInventoryOperation: vi.fn(),
  updateInventoryOperation: vi.fn(),
  postInventoryOperation: vi.fn(),
  cancelInventoryOperation: vi.fn(),
}));

vi.mock('../api/inventoryOperations', async () => {
  const actual = await vi.importActual<typeof import('../api/inventoryOperations')>('../api/inventoryOperations');
  return { ...actual, ...apiMocks };
});

describe('inventory operation workspaces', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    apiMocks.listInventoryOperations.mockImplementation(async (resource: InventoryOperationResource) => workspace(resource));
    apiMocks.getInventoryOperation.mockResolvedValue(operation('operation-1', 'ISSUE'));
    apiMocks.createInventoryOperation.mockResolvedValue(result('operation-1', 'ISSUE', 'DRAFT', 1));
    apiMocks.updateInventoryOperation.mockResolvedValue(result('operation-1', 'ISSUE', 'DRAFT', 2));
    apiMocks.postInventoryOperation.mockResolvedValue({ ...result('operation-1', 'ISSUE', 'POSTED', 2), movement_ids: ['movement-1'] });
    apiMocks.cancelInventoryOperation.mockResolvedValue(result('operation-1', 'ISSUE', 'CANCELLED', 2));
  });

  it('creates and posts a controlled issue from available stock', async () => {
    const user = userEvent.setup();
    apiMocks.getInventoryOperation
      .mockResolvedValueOnce(operation('operation-1', 'ISSUE'))
      .mockResolvedValueOnce({ ...operation('operation-1', 'ISSUE'), status: 'POSTED', record_version: 2, allowed_actions: [], posted_at: '2026-09-11T10:00:00Z', posted_by: { id: 'user-1', name: 'Operations Manager' } });
    renderPage('issues');

    expect(await screen.findByRole('heading', { name: 'Stock issues & returns' })).toBeInTheDocument();
    await user.click(await screen.findByRole('button', { name: '+ New' }));
    fireEvent.change(screen.getByLabelText('Operation number'), { target: { value: 'iss-ui-001' } });
    fireEvent.change(screen.getByLabelText('Operation reason code'), { target: { value: 'production_issue' } });
    await user.selectOptions(screen.getByLabelText('Line 1 source position'), 'available');
    fireEvent.change(screen.getByLabelText('Line 1 quantity'), { target: { value: '5' } });
    await user.click(screen.getByRole('button', { name: 'Create draft' }));

    await waitFor(() => expect(apiMocks.createInventoryOperation).toHaveBeenCalledWith('issues', expect.objectContaining({
      operation_number: 'ISS-UI-001', operation_type: 'ISSUE', reason_code: 'PRODUCTION_ISSUE',
      lines: [expect.objectContaining({ source_position_id: 'available', quantity_base: '5' })],
    }), expect.any(String)));
    await user.click(await screen.findByRole('button', { name: 'Post operation' }));
    await waitFor(() => expect(apiMocks.postInventoryOperation).toHaveBeenCalledWith(
      'issues', expect.objectContaining({ id: 'operation-1', record_version: 1 }), expect.any(String),
    ));
    expect(await screen.findByText('Operation posted with 1 ledger movement.')).toBeInTheDocument();
  });

  it('routes a return only into quarantine and records draft cancellation', async () => {
    const user = userEvent.setup();
    const draft = operation('return-1', 'RETURN');
    apiMocks.createInventoryOperation.mockResolvedValue(result('return-1', 'RETURN', 'DRAFT', 1));
    apiMocks.getInventoryOperation
      .mockResolvedValueOnce(draft)
      .mockResolvedValueOnce({ ...draft, status: 'CANCELLED', record_version: 2, allowed_actions: [], cancelled_at: '2026-09-11T10:00:00Z', cancelled_by: { id: 'user-1', name: 'Operations Manager' }, cancellation_reason: 'Demand was withdrawn.' });
    apiMocks.cancelInventoryOperation.mockResolvedValue(result('return-1', 'RETURN', 'CANCELLED', 2));
    renderPage('issues');

    await user.click(await screen.findByRole('button', { name: '+ New' }));
    await user.selectOptions(screen.getByLabelText('Operation type'), 'RETURN');
    const target = screen.getByLabelText('Line 1 target position');
    expect(within(target).getByRole('option', { name: /Return quarantine/ })).toBeInTheDocument();
    expect(within(target).queryByRole('option', { name: /Raw store/ })).not.toBeInTheDocument();
    fireEvent.change(screen.getByLabelText('Operation number'), { target: { value: 'RET-UI-001' } });
    fireEvent.change(screen.getByLabelText('Operation reason code'), { target: { value: 'production_return' } });
    await user.selectOptions(target, 'return-target');
    fireEvent.change(screen.getByLabelText('Line 1 quantity'), { target: { value: '2' } });
    await user.click(screen.getByRole('button', { name: 'Create draft' }));
    fireEvent.change(await screen.findByLabelText('Cancellation reason'), { target: { value: 'Demand was withdrawn.' } });
    await user.click(screen.getByRole('button', { name: 'Cancel operation' }));
    await waitFor(() => expect(apiMocks.cancelInventoryOperation).toHaveBeenCalledWith(
      'issues', expect.objectContaining({ id: 'return-1' }), 'Demand was withdrawn.', expect.any(String),
    ));
  });

  it('limits transfer targets to the same lot, owner, quality, and UOM', async () => {
    const user = userEvent.setup();
    renderPage('transfers');
    await user.click(await screen.findByRole('button', { name: '+ New' }));
    await user.selectOptions(screen.getByLabelText('Line 1 source position'), 'available');
    const target = screen.getByLabelText('Line 1 target position');
    expect(within(target).getByRole('option', { name: /Line side/ })).toBeInTheDocument();
    expect(within(target).queryByRole('option', { name: /Party owned/ })).not.toBeInTheDocument();
    expect(within(target).queryByRole('option', { name: /Quality hold/ })).not.toBeInTheDocument();
  });

  it('submits a count as a snapshot quantity and exposes its variance', async () => {
    const user = userEvent.setup();
    apiMocks.createInventoryOperation.mockResolvedValue(result('count-1', 'COUNT', 'DRAFT', 1));
    apiMocks.getInventoryOperation.mockResolvedValue(operation('count-1', 'COUNT'));
    renderPage('counts');
    await user.click(await screen.findByRole('button', { name: '+ New' }));
    fireEvent.change(screen.getByLabelText('Operation number'), { target: { value: 'cnt-ui-001' } });
    fireEvent.change(screen.getByLabelText('Operation reason code'), { target: { value: 'cycle_count' } });
    await user.selectOptions(screen.getByLabelText('Line 1 source position'), 'blocked');
    fireEvent.change(screen.getByLabelText('Line 1 counted quantity'), { target: { value: '12' } });
    await user.click(screen.getByRole('button', { name: 'Create draft' }));
    await waitFor(() => expect(apiMocks.createInventoryOperation).toHaveBeenCalledWith('counts', expect.objectContaining({
      operation_type: 'COUNT', lines: [{ source_position_id: 'blocked', counted_quantity_base: '12', notes: null }],
    }), expect.any(String)));
    expect(await screen.findByText('Snapshot')).toBeInTheDocument();
    expect(screen.getByText('-3')).toBeInTheDocument();
  });

  it('offers the expired segregation pair and a signed direct adjustment', async () => {
    const user = userEvent.setup();
    const rendered = renderPage('expiry-disposals');
    await user.click(await screen.findByRole('button', { name: '+ New' }));
    await user.selectOptions(screen.getByLabelText('Line 1 source position'), 'expired-source');
    expect(within(screen.getByLabelText('Line 1 target position')).getByRole('option', { name: /Expired hold/ })).toBeInTheDocument();

    rendered.unmount();
    renderPage('counts');
    await user.click(await screen.findByRole('button', { name: '+ New' }));
    await user.selectOptions(screen.getByLabelText('Operation type'), 'ADJUSTMENT');
    expect(screen.getByLabelText('Line 1 adjustment direction')).toHaveValue('INCREASE');
  });
});

function renderPage(resource: InventoryOperationResource) {
  return render(<ErpSessionContext.Provider value={session()}><InventoryOperationsWorkspace resource={resource} /></ErpSessionContext.Provider>);
}

function workspace(resource: InventoryOperationResource): InventoryOperationWorkspace {
  const types: Record<InventoryOperationResource, InventoryOperationType[]> = {
    issues: ['ISSUE', 'RETURN'], transfers: ['TRANSFER'], counts: ['COUNT', 'ADJUSTMENT'],
    'expiry-disposals': ['EXPIRY', 'DISPOSAL'],
  };
  return {
    data: [], meta: { current_page: 1, last_page: 1, per_page: 25, total: 0 },
    summary: { total: 0, draft: 0, posted: 0, cancelled: 0, by_type: {} },
    lookups: {
      operation_types: types[resource], statuses: ['DRAFT', 'POSTED', 'CANCELLED'],
      adjustment_directions: ['INCREASE', 'DECREASE'], sorts: ['NEWEST', 'OLDEST', 'NUMBER', 'STATUS'],
      positions: positions(),
    },
    allowed_actions: ['CREATE'],
  };
}

function positions(): OperationPosition[] {
  return [
    position('available', 'Raw store', 'RELEASED', 'AVAILABLE', '125', '105'),
    position('transfer-target', 'Line side', 'RELEASED', 'AVAILABLE', '0', '0'),
    position('party-owned', 'Party owned', 'RELEASED', 'AVAILABLE', '12', '12', { ownerId: 'owner-2' }),
    position('blocked', 'Quality hold', 'QUALITY_HOLD', 'BLOCKED', '15', '0'),
    position('return-target', 'Return quarantine', 'RETURN_QUARANTINE', 'BLOCKED', '0', '0'),
    position('expired-source', 'Expired raw store', 'RELEASED', 'AVAILABLE', '8', '0', { lotId: 'expired-lot', expiry: '2026-09-01' }),
    position('expired-target', 'Expired hold', 'EXPIRED', 'BLOCKED', '0', '0', { lotId: 'expired-lot', expiry: '2026-09-01' }),
  ];
}

function position(
  id: string,
  locationName: string,
  quality: string,
  bucket: 'AVAILABLE' | 'BLOCKED',
  total: string,
  available: string,
  overrides: { ownerId?: string; lotId?: string; expiry?: string } = {},
): OperationPosition {
  const lotId = overrides.lotId ?? 'lot-1';
  const ownerId = overrides.ownerId ?? 'owner-1';
  return {
    id, label: `SKU-APPLE-BASE · ${lotId} · ${locationName} · ${quality} · ${ownerId}`,
    sku: { id: 'sku-1', code: 'SKU-APPLE-BASE', name: 'Apple Ingredient Base' },
    lot: { id: lotId, code: lotId, status: 'ACTIVE', expiry_date: overrides.expiry ?? '2099-12-01' },
    owner: { id: ownerId, code: ownerId, name: ownerId === 'owner-1' ? 'Company stock' : 'Party owned' },
    location: { id: `${id}-location`, code: locationName.toUpperCase().replaceAll(' ', '-'), name: locationName },
    quality_status: { code: quality, name: quality, availability_bucket: bucket },
    quantity: { total, available, reserved: id === 'available' ? '20' : '0', uom_code: 'KG' },
    record_version: 1,
  };
}

function operation(id: string, type: InventoryOperationType): InventoryOperation {
  const source = type === 'RETURN' ? null : type === 'EXPIRY' ? positions()[5] : type === 'COUNT' ? positions()[3] : positions()[0];
  const target = type === 'RETURN' ? positions()[4] : type === 'TRANSFER' ? positions()[1] : type === 'EXPIRY' ? positions()[6] : null;
  return {
    id, company_id: 'company-1', plant_id: 'plant-1', operation_number: `${type}-UI-001`, operation_type: type,
    status: 'DRAFT', reason_code: type === 'COUNT' ? 'CYCLE_COUNT' : 'CONTROLLED_OPERATION', notes: 'Test operation',
    record_version: 1, line_count: 1, created_by: { id: 'user-1', name: 'Operations Manager' },
    posted_at: null, posted_by: null, cancelled_at: null, cancelled_by: null, cancellation_reason: null,
    allowed_actions: ['UPDATE', 'POST', 'CANCEL'], created_at: '2026-09-11T09:00:00Z', updated_at: '2026-09-11T09:00:00Z',
    lines: [{
      id: 'line-1', sequence_no: 1, source_position_id: source?.id ?? null, source_position: source,
      target_position_id: target?.id ?? null, target_position: target,
      quantity_base: type === 'COUNT' ? null : type === 'EXPIRY' ? '8.000000' : '5.000000',
      counted_quantity_base: type === 'COUNT' ? '12.000000' : null,
      system_quantity_base: type === 'COUNT' ? '15.000000' : null,
      variance_quantity_base: type === 'COUNT' ? '-3.000000' : null,
      source_position_version: type === 'COUNT' ? 1 : null, adjustment_direction: null,
      uom_code: 'KG', movement_id: null, notes: null,
    }], movements: [],
  };
}

function result(id: string, type: InventoryOperationType, status: 'DRAFT' | 'POSTED' | 'CANCELLED', version: number) {
  return { entity_type: 'inventory_operation' as const, id, operation_type: type, status, record_version: version, line_count: 1 };
}

function session(): ErpSession {
  return {
    user: { id: 'user-1', name: 'Operations Manager', email: 'operations@example.com' }, roles: ['OPERATIONS_MANAGER'],
    allowed_screens: ['INV-ISS', 'INV-TRF', 'INV-COUNT', 'INV-EXP'],
    allowed_actions: [
      'ACTION:INV-ISS:CREATE', 'ACTION:INV-ISS:UPDATE', 'ACTION:INV-ISS:POST', 'ACTION:INV-ISS:CANCEL',
      'ACTION:INV-TRF:CREATE', 'ACTION:INV-TRF:UPDATE', 'ACTION:INV-TRF:POST', 'ACTION:INV-TRF:CANCEL',
      'ACTION:INV-COUNT:CREATE', 'ACTION:INV-COUNT:UPDATE', 'ACTION:INV-COUNT:POST', 'ACTION:INV-COUNT:CANCEL',
      'ACTION:INV-EXP:CREATE', 'ACTION:INV-EXP:UPDATE', 'ACTION:INV-EXP:POST', 'ACTION:INV-EXP:CANCEL',
    ], contexts: [], selected_context: { company_id: 'company-1', company_name: 'Q & T Foods', plant_id: 'plant-1', plant_name: 'Training Plant' },
  };
}

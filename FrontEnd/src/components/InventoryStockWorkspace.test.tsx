import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
  InventoryLot,
  InventoryOwner,
  LotWorkspace,
  OwnerWorkspace,
  StockPosition,
  StockWorkspace,
} from '../api/inventoryFoundation';
import type { InventoryMovement, InventoryMovementWorkspace } from '../api/inventoryOperations';
import { ErpSessionContext } from '../app/ErpSessionContext';
import type { ErpSession } from '../types/session';
import { InventoryStockWorkspace } from './InventoryStockWorkspace';

const apiMocks = vi.hoisted(() => ({
  listStock: vi.fn(),
  getStockPosition: vi.fn(),
  listInventoryOwners: vi.fn(),
  getInventoryOwner: vi.fn(),
  listInventoryLots: vi.fn(),
  getInventoryLot: vi.fn(),
  createInventoryOwner: vi.fn(),
  updateInventoryOwner: vi.fn(),
  changeInventoryOwnerStatus: vi.fn(),
  createInventoryLot: vi.fn(),
  updateInventoryLot: vi.fn(),
  changeInventoryLotStatus: vi.fn(),
  reserveStock: vi.fn(),
  releaseStockReservation: vi.fn(),
  listInventoryMovements: vi.fn(),
  getInventoryMovement: vi.fn(),
}));

vi.mock('../api/inventoryFoundation', async () => {
  const actual = await vi.importActual<typeof import('../api/inventoryFoundation')>('../api/inventoryFoundation');
  return { ...actual, ...apiMocks };
});

vi.mock('../api/inventoryOperations', async () => {
  const actual = await vi.importActual<typeof import('../api/inventoryOperations')>('../api/inventoryOperations');
  return { ...actual, listInventoryMovements: apiMocks.listInventoryMovements, getInventoryMovement: apiMocks.getInventoryMovement };
});

describe('inventory stock workspace', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    apiMocks.listStock.mockResolvedValue(stockWorkspace());
    apiMocks.getStockPosition.mockResolvedValue(stockDetail());
    apiMocks.listInventoryOwners.mockResolvedValue(ownerWorkspace());
    apiMocks.getInventoryOwner.mockImplementation(async (id: string) => ownerDetail({
      id,
      code: id === 'owner-created' ? 'OWN-CENTRAL' : 'OWN-NORTH',
      name: id === 'owner-created' ? 'Central consignment stock' : 'North Market consignment stock',
    }));
    apiMocks.listInventoryLots.mockResolvedValue(lotWorkspace());
    apiMocks.getInventoryLot.mockImplementation(async (id: string) => lotDetail({
      id,
      internal_lot_code: id === 'lot-created' ? 'RM-APPLE-NEW' : 'RM-APPLE-2609A',
    }));
    apiMocks.createInventoryOwner.mockResolvedValue({ entity_type: 'inventory_owner', id: 'owner-created', status: 'ACTIVE', record_version: 1 });
    apiMocks.updateInventoryOwner.mockResolvedValue({ entity_type: 'inventory_owner', id: 'owner-1', status: 'ACTIVE', record_version: 3 });
    apiMocks.changeInventoryOwnerStatus.mockResolvedValue({ entity_type: 'inventory_owner', id: 'owner-1', status: 'INACTIVE', record_version: 3 });
    apiMocks.createInventoryLot.mockResolvedValue({ entity_type: 'inventory_lot', id: 'lot-created', status: 'ACTIVE', record_version: 1 });
    apiMocks.updateInventoryLot.mockResolvedValue({ entity_type: 'inventory_lot', id: 'lot-1', status: 'ACTIVE', record_version: 3 });
    apiMocks.changeInventoryLotStatus.mockResolvedValue({ entity_type: 'inventory_lot', id: 'lot-1', status: 'RECALLED', record_version: 3 });
    apiMocks.reserveStock.mockResolvedValue({ entity_type: 'stock_reservation', id: 'reservation-new', status: 'ACTIVE', record_version: 1, position_record_version: 3 });
    apiMocks.releaseStockReservation.mockResolvedValue({ entity_type: 'stock_reservation', id: 'reservation-1', status: 'RELEASED', record_version: 2, position_record_version: 3 });
    apiMocks.listInventoryMovements.mockResolvedValue(movementWorkspace());
    apiMocks.getInventoryMovement.mockResolvedValue(movement());
  });

  it('loads derived stock buckets, filters the server list, and opens reservation detail', async () => {
    const user = userEvent.setup();
    renderPage();

    expect(await screen.findByText('Raw Material Store')).toBeInTheDocument();
    expect(screen.getByText('105 KG')).toBeInTheDocument();
    expect(screen.getByText('105')).toBeInTheDocument();
    await user.selectOptions(screen.getByLabelText('Filter stock availability'), 'BLOCKED');
    await waitFor(() => expect(apiMocks.listStock).toHaveBeenLastCalledWith(expect.objectContaining({ availability: 'BLOCKED' })));

    await user.click(screen.getByRole('button', { name: 'Open' }));
    expect(await screen.findByText('RSV-MRP-001')).toBeInTheDocument();
    expect(apiMocks.getStockPosition).toHaveBeenCalledWith('position-1');
    expect(screen.getAllByText('Quality hold').length).toBeGreaterThan(0);
  });

  it('creates and releases a versioned stock reservation', async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByText('Raw Material Store');
    await user.click(screen.getByRole('button', { name: 'Open' }));
    await screen.findByText('RSV-MRP-001');

    fireEvent.change(screen.getByLabelText('Reservation number'), { target: { value: 'rsv-test-002' } });
    fireEvent.change(screen.getByLabelText('Reservation quantity'), { target: { value: '5' } });
    fireEvent.change(screen.getByLabelText('Reservation purpose'), { target: { value: 'Next production schedule' } });
    await user.click(screen.getByRole('button', { name: 'Reserve stock' }));
    await waitFor(() => expect(apiMocks.reserveStock).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'position-1', record_version: 2 }),
      { reservation_number: 'RSV-TEST-002', quantity_base: '5', purpose: 'Next production schedule' },
      expect.any(String),
    ));

    fireEvent.change(screen.getByLabelText('Reservation release reason'), { target: { value: 'Schedule cancelled' } });
    await user.click(screen.getByRole('button', { name: 'Release reservation' }));
    await waitFor(() => expect(apiMocks.releaseStockReservation).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'reservation-1', record_version: 1 }),
      'Schedule cancelled',
      expect.any(String),
    ));
  });

  it('creates an inventory owner and applies a lifecycle transition', async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByText('Raw Material Store');
    await user.click(screen.getByRole('button', { name: 'Owners' }));
    expect(await screen.findByText('North Market consignment stock')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: '+ New' }));
    fireEvent.change(screen.getByLabelText('Owner code'), { target: { value: 'own-central' } });
    fireEvent.change(screen.getByLabelText('Owner name'), { target: { value: 'Central consignment stock' } });
    await user.selectOptions(screen.getByLabelText('Owner party'), 'party-2');
    await user.selectOptions(screen.getByLabelText('Owner initial status'), 'ACTIVE');
    await user.click(screen.getByRole('button', { name: 'Create owner' }));
    await waitFor(() => expect(apiMocks.createInventoryOwner).toHaveBeenCalledWith({
      code: 'OWN-CENTRAL', name: 'Central consignment stock', owner_type: 'PARTY',
      party_id: 'party-2', status: 'ACTIVE',
    }, expect.any(String)));

    await user.click(screen.getByRole('button', { name: 'Open' }));
    await screen.findByDisplayValue('OWN-NORTH');
    await user.selectOptions(screen.getByLabelText('Target owner status'), 'INACTIVE');
    fireEvent.change(screen.getByLabelText('Owner status reason'), { target: { value: 'Consignment ended' } });
    await user.click(screen.getByRole('button', { name: 'Apply status' }));
    await waitFor(() => expect(apiMocks.changeInventoryOwnerStatus).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'owner-1', record_version: 2 }),
      'INACTIVE',
      'Consignment ended',
      expect.any(String),
    ));
  });

  it('creates and updates a supplier-traceable lot', async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByText('Raw Material Store');
    await user.click(screen.getByRole('button', { name: 'Lots' }));
    expect(await screen.findByText('RM-APPLE-2609A')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: '+ New' }));
    fireEvent.change(screen.getByLabelText('Internal lot code'), { target: { value: 'rm-apple-new' } });
    await user.selectOptions(screen.getByLabelText('Lot SKU'), 'sku-1');
    await user.selectOptions(screen.getByLabelText('Lot supplier'), 'party-2');
    fireEvent.change(screen.getByLabelText('Supplier lot code'), { target: { value: 'sup-new-1' } });
    fireEvent.change(screen.getByLabelText('Manufacture date'), { target: { value: '2026-09-01' } });
    fireEvent.change(screen.getByLabelText('Expiry date'), { target: { value: '2026-12-01' } });
    await user.selectOptions(screen.getByLabelText('Lot initial status'), 'ACTIVE');
    await user.click(screen.getByRole('button', { name: 'Create lot' }));
    await waitFor(() => expect(apiMocks.createInventoryLot).toHaveBeenCalledWith(expect.objectContaining({
      internal_lot_code: 'RM-APPLE-NEW', item_id: 'sku-1', supplier_party_id: 'party-2',
      supplier_lot_code: 'SUP-NEW-1', status: 'ACTIVE',
    }), expect.any(String)));

    await user.click(screen.getByRole('button', { name: 'Open' }));
    await screen.findByDisplayValue('RM-APPLE-2609A');
    fireEvent.change(screen.getByLabelText('Lot notes'), { target: { value: 'Updated trace note' } });
    await user.click(screen.getByRole('button', { name: 'Save lot' }));
    await waitFor(() => expect(apiMocks.updateInventoryLot).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'lot-1', record_version: 2 }),
      expect.objectContaining({ notes: 'Updated trace note', item_id: 'sku-1' }),
      expect.any(String),
    ));
  });

  it('browses immutable movement history and opens posting evidence', async () => {
    const user = userEvent.setup();
    renderPage();
    await screen.findByText('Raw Material Store');
    await user.click(screen.getByRole('button', { name: 'Movement history' }));
    expect((await screen.findAllByText('Inventory issue')).length).toBeGreaterThan(0);
    await user.click(screen.getByRole('button', { name: 'Open' }));
    expect((await screen.findAllByText('Production issue')).length).toBeGreaterThan(0);
    expect(screen.getAllByText('ISS-UI-001').length).toBeGreaterThan(0);
    expect(apiMocks.getInventoryMovement).toHaveBeenCalledWith('movement-1');
  });
});

function renderPage() {
  return render(<ErpSessionContext.Provider value={session()}><InventoryStockWorkspace /></ErpSessionContext.Provider>);
}

function session(): ErpSession {
  return {
    user: { id: 'operations-1', name: 'Operations Manager', email: 'operations@example.com' },
    roles: ['OPERATIONS_MANAGER'],
    allowed_screens: ['INV-STK'],
    allowed_actions: [
      'ACTION:INV-STK:OWNER-CREATE', 'ACTION:INV-STK:OWNER-UPDATE', 'ACTION:INV-STK:OWNER-LIFECYCLE',
      'ACTION:INV-STK:LOT-CREATE', 'ACTION:INV-STK:LOT-UPDATE', 'ACTION:INV-STK:LOT-LIFECYCLE',
      'ACTION:INV-STK:RESERVE', 'ACTION:INV-STK:RELEASE',
    ],
    contexts: [],
    selected_context: { company_id: 'company-1', company_name: 'Q & T Foods', plant_id: 'plant-1', plant_name: 'Training Plant' },
  };
}

function stockWorkspace(): StockWorkspace {
  return {
    data: [stockDetail({ reservations: undefined })],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    summary: {
      position_count: 3, sku_count: 1, lot_count: 1, total: '152.000000', available: '105.000000',
      blocked: '27.000000', reserved: '20.000000', uom_code: 'KG', quantity_summary_status: 'SINGLE_UOM',
      quantities_by_uom: [{ uom_code: 'KG', total: '152.000000', available: '105.000000', blocked: '27.000000', reserved: '20.000000' }],
      expired_lots: 0, expiring_30_lots: 0,
    },
    lookups: {
      availability_filters: ['AVAILABLE', 'BLOCKED', 'RESERVED', 'EXPIRED', 'EXPIRING_30'],
      sorts: ['SKU', 'LOT', 'EXPIRY', 'QUANTITY_DESC', 'UPDATED'],
      quality_statuses: [
        { code: 'RELEASED', name: 'Released', availability_bucket: 'AVAILABLE', is_reservable: true },
        { code: 'QUALITY_HOLD', name: 'Quality hold', availability_bucket: 'BLOCKED', is_reservable: false },
      ],
      owners: [{ id: 'owner-1', code: 'OWN', name: 'Company owned stock', status: 'ACTIVE' }],
      skus: [{ id: 'sku-1', code: 'SKU-APPLE-BASE', name: 'Apple Ingredient Base', status: 'ACTIVE' }],
      lots: [{ id: 'lot-1', code: 'RM-APPLE-2609A', name: 'RM-APPLE-2609A', status: 'ACTIVE' }],
      locations: [{ id: 'location-1', code: 'RAW-STORE', name: 'Raw Material Store' }],
    },
    allowed_actions: [],
  };
}

function stockDetail(overrides: Partial<StockPosition> = {}): StockPosition {
  return {
    id: 'position-1', company_id: 'company-1', plant_id: 'plant-1',
    sku: { id: 'sku-1', code: 'SKU-APPLE-BASE', name: 'Apple Ingredient Base' },
    lot: { id: 'lot-1', code: 'RM-APPLE-2609A', status: 'ACTIVE', manufacture_date: '2026-09-01', expiry_date: '2026-12-01', is_expired: false, days_to_expiry: 82 },
    owner: { id: 'owner-1', code: 'OWN', name: 'Company owned stock', type: 'COMPANY', status: 'ACTIVE', party: null },
    location: { id: 'location-1', code: 'RAW-STORE', name: 'Raw Material Store' },
    quality_status: { code: 'RELEASED', name: 'Quality hold', availability_bucket: 'AVAILABLE', is_reservable: true },
    stock_bucket: 'AVAILABLE', quantity: { total: '125.000000', available: '105.000000', blocked: '0.000000', reserved: '20.000000', uom_code: 'KG' },
    record_version: 2, allowed_actions: ['RESERVE'],
    reservations: [{
      id: 'reservation-1', reservation_number: 'RSV-MRP-001', quantity_base: '20.000000', status: 'ACTIVE',
      purpose: 'Production plan', record_version: 1, created_by: { id: 'operations-1', name: 'Operations Manager' },
      released_at: null, released_by: null, release_reason: null, allowed_actions: ['RELEASE'],
      created_at: '2026-09-01T00:00:00Z', updated_at: '2026-09-01T00:00:00Z',
    }],
    created_at: '2026-09-01T00:00:00Z', updated_at: '2026-09-01T00:00:00Z', ...overrides,
  };
}

function movementWorkspace(): InventoryMovementWorkspace {
  return {
    data: [movement()], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    summary: { total: 1, outbound: 1, inbound: 0, transfer: 0 },
    lookups: { movement_types: ['INVENTORY_ISSUE'], directions: ['OUTBOUND', 'INBOUND', 'TRANSFER'], sorts: ['NEWEST', 'OLDEST', 'TYPE'] },
    allowed_actions: [],
  };
}

function movement(): InventoryMovement {
  const position = {
    id: 'position-1', label: 'SKU-APPLE-BASE · RM-APPLE-2609A · RAW-STORE · RELEASED · OWN',
    sku: { id: 'sku-1', code: 'SKU-APPLE-BASE', name: 'Apple Ingredient Base' },
    lot: { id: 'lot-1', code: 'RM-APPLE-2609A', status: 'ACTIVE', expiry_date: '2026-12-01' },
    owner: { id: 'owner-1', code: 'OWN', name: 'Company owned stock' },
    location: { id: 'location-1', code: 'RAW-STORE', name: 'Raw Material Store' },
    quality_status: { code: 'RELEASED', name: 'Released', availability_bucket: 'AVAILABLE' as const },
    quantity: { total: '121.000000', available: '101.000000', reserved: '20.000000', uom_code: 'KG' },
    record_version: 3,
  };
  return {
    id: 'movement-1', company_id: 'company-1', plant_id: 'plant-1', movement_type: 'INVENTORY_ISSUE', direction: 'OUTBOUND',
    source: { type: 'INVENTORY_OPERATION', id: 'operation-1', version: 2, operation_number: 'ISS-UI-001', operation_type: 'ISSUE', operation_status: 'POSTED' },
    from_position: position, to_position: null, quantity_base: '4.000000', uom_code: 'KG', reason_code: 'PRODUCTION_ISSUE',
    actor: { id: 'operations-1', name: 'Operations Manager' }, event_at: '2026-09-11T09:00:00Z', posted_at: '2026-09-11T09:00:00Z',
  };
}

function ownerWorkspace(): OwnerWorkspace {
  return {
    data: [ownerDetail()], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    summary: { total: 2, draft: 0, active: 2, inactive: 0, party_owned: 1 },
    lookups: {
      statuses: ['DRAFT', 'ACTIVE', 'INACTIVE'], owner_types: ['COMPANY', 'PARTY'],
      parties: [
        { id: 'party-1', code: 'DIST-NORTH', name: 'North Market', status: 'ACTIVE' },
        { id: 'party-2', code: 'DIST-CENTRAL', name: 'Central Distribution', status: 'ACTIVE' },
      ],
    }, allowed_actions: ['CREATE'],
  };
}

function ownerDetail(overrides: Partial<InventoryOwner> = {}): InventoryOwner {
  return {
    id: 'owner-1', company_id: 'company-1', code: 'OWN-NORTH', name: 'North Market consignment stock',
    owner_type: 'PARTY', party_id: 'party-1', party: { id: 'party-1', code: 'DIST-NORTH', name: 'North Market' },
    status: 'ACTIVE', record_version: 2, position_count: 1, total_quantity: '12.000000', reserved_quantity: '0.000000',
    status_change: null, allowed_actions: ['UPDATE', 'CHANGE_STATUS'], allowed_statuses: ['INACTIVE'],
    quality_totals: [{ code: 'RELEASED', name: 'Released', availability_bucket: 'AVAILABLE', total_quantity: '12.000000', reserved_quantity: '0.000000' }],
    created_at: '2026-09-01T00:00:00Z', updated_at: '2026-09-01T00:00:00Z', ...overrides,
  };
}

function lotWorkspace(): LotWorkspace {
  return {
    data: [lotDetail()], meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    summary: { total: 3, draft: 0, active: 3, closed: 0, recalled: 0, expired: 0, expiring_30: 0 },
    lookups: {
      statuses: ['DRAFT', 'ACTIVE', 'CLOSED', 'RECALLED'], origin_types: ['PURCHASE', 'PRODUCTION', 'RETURN', 'OPENING'],
      skus: [{ id: 'sku-1', code: 'SKU-APPLE-BASE', name: 'Apple Ingredient Base', status: 'ACTIVE' }],
      suppliers: [{ id: 'party-2', code: 'DIST-CENTRAL', name: 'Central Distribution', status: 'ACTIVE' }],
    }, allowed_actions: ['CREATE'],
  };
}

function lotDetail(overrides: Partial<InventoryLot> = {}): InventoryLot {
  return {
    id: 'lot-1', company_id: 'company-1', internal_lot_code: 'RM-APPLE-2609A', supplier_lot_code: 'SUP-APPLE-441',
    item_id: 'sku-1', sku: { id: 'sku-1', code: 'SKU-APPLE-BASE', name: 'Apple Ingredient Base', status: 'ACTIVE' },
    supplier_party_id: 'party-2', supplier: { id: 'party-2', code: 'DIST-CENTRAL', name: 'Central Distribution' },
    origin_type: 'PURCHASE', manufacture_date: '2026-09-01', expiry_date: '2026-12-01', is_expired: false,
    days_to_expiry: 82, status: 'ACTIVE', notes: 'Incoming ingredient lot.', record_version: 2, position_count: 3,
    total_quantity: '152.000000', reserved_quantity: '20.000000', status_change: null,
    allowed_actions: ['UPDATE', 'CHANGE_STATUS'], allowed_statuses: ['CLOSED', 'RECALLED'],
    quality_totals: [{ code: 'RELEASED', name: 'Released', availability_bucket: 'AVAILABLE', total_quantity: '137.000000', reserved_quantity: '20.000000' }],
    created_at: '2026-09-01T00:00:00Z', updated_at: '2026-09-01T00:00:00Z', ...overrides,
  };
}

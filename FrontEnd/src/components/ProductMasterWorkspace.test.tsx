import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
  ProductMasterDetail,
  ProductMasterResource,
  ProductMasterWorkspace as Workspace,
} from '../api/productMasters';
import { ErpSessionContext } from '../app/ErpSessionContext';
import type { ErpSession } from '../types/session';
import { ProductMasterWorkspace } from './ProductMasterWorkspace';

const apiMocks = vi.hoisted(() => ({
  listProductMasters: vi.fn(),
  getProductMaster: vi.fn(),
  createProductMaster: vi.fn(),
  updateProductMaster: vi.fn(),
  changeProductMasterStatus: vi.fn(),
}));

vi.mock('../api/productMasters', async () => {
  const actual = await vi.importActual<typeof import('../api/productMasters')>('../api/productMasters');
  return { ...actual, ...apiMocks };
});

const resources: ProductMasterResource[] = ['brands', 'items', 'skus', 'recipes', 'routes', 'specifications'];

describe('product master workspaces', () => {
  beforeEach(() => {
    window.history.replaceState(null, '', '#');
    vi.resetAllMocks();
    apiMocks.listProductMasters.mockImplementation(async (resource: ProductMasterResource) => workspace(resource));
    apiMocks.getProductMaster.mockImplementation(async (resource: ProductMasterResource) => detail(resource));
    apiMocks.createProductMaster.mockResolvedValue({ id: 'created-1', entity_type: 'master', status: 'ACTIVE', record_version: 1 });
    apiMocks.updateProductMaster.mockResolvedValue({ id: 'items-1', entity_type: 'catalog_item', status: 'ACTIVE', record_version: 3 });
    apiMocks.changeProductMasterStatus.mockResolvedValue({ id: 'items-1', entity_type: 'catalog_item', status: 'INACTIVE', record_version: 4 });
  });

  it.each(resources)('loads, filters, and opens the complete %s aggregate', async (resource) => {
    const user = userEvent.setup();
    renderPage(resource);

    expect(await screen.findByText(`${label(resource)} Seed`)).toBeInTheDocument();
    await user.selectOptions(screen.getByLabelText(new RegExp(`Filter .* status`, 'i')), 'ACTIVE');
    await waitFor(() => expect(apiMocks.listProductMasters).toHaveBeenLastCalledWith(
      resource,
      expect.objectContaining({ status: 'ACTIVE' }),
    ));
    await user.click(screen.getByRole('button', { name: new RegExp(`Expand .* ${label(resource)} Seed`, 'i') }));
    await user.click(screen.getByRole('button', { name: 'Open edit card' }));
    expect(await screen.findByDisplayValue(`${resource.toUpperCase()}-001`)).toBeInTheDocument();
    expect(apiMocks.getProductMaster).toHaveBeenCalledWith(resource, `${resource}-1`);
  });

  it('creates a brand with an effective-dated party agreement', async () => {
    const user = userEvent.setup();
    renderPage('brands');
    await screen.findByText('Brand Seed');
    await user.click(screen.getByRole('button', { name: '+ New' }));
    fireEvent.change(screen.getByLabelText('Brand code'), { target: { value: 'fresh-brand' } });
    fireEvent.change(screen.getByLabelText('Brand name'), { target: { value: 'Fresh Brand' } });
    await user.selectOptions(screen.getByLabelText('Initial status'), 'ACTIVE');
    await user.click(screen.getByRole('button', { name: 'Add agreement' }));
    fireEvent.change(screen.getByLabelText('Agreement number 1'), { target: { value: 'agr-100' } });
    await user.selectOptions(screen.getByLabelText('Agreement status 1'), 'ACTIVE');
    fireEvent.change(screen.getByLabelText('Agreement effective from 1'), { target: { value: '2026-04-01' } });
    await user.click(screen.getByRole('button', { name: 'Create brand' }));

    await waitFor(() => expect(apiMocks.createProductMaster).toHaveBeenCalledWith(
      'brands',
      expect.objectContaining({
        code: 'FRESH-BRAND', status: 'ACTIVE', name: 'Fresh Brand',
        agreements: [expect.objectContaining({ agreement_number: 'AGR-100', party_id: 'party-1', status: 'ACTIVE' })],
      }),
      expect.any(String),
    ));
  });

  it('creates an item with an explicit base-UOM conversion', async () => {
    const user = userEvent.setup();
    renderPage('items');
    await screen.findByText('Item Seed');
    await user.click(screen.getByRole('button', { name: '+ New' }));
    fireEvent.change(screen.getByLabelText('Item code'), { target: { value: 'item-oat' } });
    fireEvent.change(screen.getByLabelText('Item name'), { target: { value: 'Oat Base' } });
    await user.selectOptions(screen.getByLabelText('Initial status'), 'ACTIVE');
    await user.selectOptions(screen.getByLabelText('Base UOM'), 'KG');
    await user.click(screen.getByRole('button', { name: 'Add conversion' }));
    await user.selectOptions(screen.getByLabelText('Conversion from UOM 1'), 'KG');
    await user.selectOptions(screen.getByLabelText('Conversion to UOM 1'), 'G');
    fireEvent.change(screen.getByLabelText('Conversion multiplier 1'), { target: { value: '1000' } });
    await user.click(screen.getByRole('button', { name: 'Create item' }));

    await waitFor(() => expect(apiMocks.createProductMaster).toHaveBeenCalledWith(
      'items',
      expect.objectContaining({
        code: 'ITEM-OAT', base_uom: 'KG',
        conversions: [expect.objectContaining({ from_uom_code: 'KG', to_uom_code: 'G', multiplier: '1000' })],
      }),
      expect.any(String),
    ));
  });

  it('shows governed product classifications and UOMs as read-only expandable references', async () => {
    const user = userEvent.setup();
    renderPage('items');
    await screen.findByText('Item Seed');

    await user.click(screen.getByRole('button', { name: 'Expand Raw material' }));
    expect(screen.getByText('Database-enforced item type')).toBeInTheDocument();
    expect(screen.getByText(/no independent edit card/i)).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Expand KG Kilogram' }));
    expect(screen.getByText(/no UOM record version/i)).toBeInTheDocument();
    expect(screen.getByText(/migration-controlled/i)).toBeInTheDocument();
  });

  it('initialises new items from loaded UOMs and blocks creation when UOM reference data is missing', async () => {
    const user = userEvent.setup();
    const loaded = renderPage('items');
    await screen.findByText('Item Seed');
    await user.click(screen.getByRole('button', { name: '+ New' }));
    await waitFor(() => expect(screen.getByLabelText('Base UOM')).toHaveValue('KG'));
    loaded.unmount();

    const withoutUoms = workspace('items');
    withoutUoms.lookups.uoms = [];
    apiMocks.listProductMasters.mockResolvedValue(withoutUoms);
    renderPage('items');

    await screen.findByText('Item Seed');
    await user.click(screen.getByRole('button', { name: '+ New' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('Units of measure are not configured');
    expect(screen.getByLabelText('Base UOM')).toHaveValue('');
    expect(screen.getByRole('button', { name: 'Create item' })).toBeDisabled();
  });

  it('creates a stock-bearing SKU with one default pack', async () => {
    const user = userEvent.setup();
    renderPage('skus');
    await screen.findByText('Sku Seed');
    await user.click(screen.getByRole('button', { name: '+ New' }));
    fireEvent.change(screen.getByLabelText('SKU code'), { target: { value: 'sku-oat-100' } });
    fireEvent.change(screen.getByLabelText('SKU name'), { target: { value: 'Oat Pack 100g' } });
    await user.selectOptions(screen.getByLabelText('Initial status'), 'ACTIVE');
    fireEvent.change(screen.getByLabelText('SKU barcode'), { target: { value: '8900000000001' } });
    fireEvent.change(screen.getByLabelText('Pack code 1'), { target: { value: 'pack-oat-100' } });
    fireEvent.change(screen.getByLabelText('Pack name 1'), { target: { value: 'Retail pack' } });
    await user.click(screen.getByRole('button', { name: 'Create SKU' }));

    await waitFor(() => expect(apiMocks.createProductMaster).toHaveBeenCalledWith(
      'skus',
      expect.objectContaining({
        code: 'SKU-OAT-100', catalog_item_id: 'item-1', barcode: '8900000000001',
        packs: [expect.objectContaining({ code: 'PACK-OAT-100', is_default: true })],
      }),
      expect.any(String),
    ));
  });

  it('creates a recipe with an ordered BOM component', async () => {
    const user = userEvent.setup();
    renderPage('recipes');
    await screen.findByText('Recipe Seed');
    await user.click(screen.getByRole('button', { name: '+ New' }));
    fireEvent.change(screen.getByLabelText('Recipe code'), { target: { value: 'rec-oat' } });
    fireEvent.change(screen.getByLabelText('Recipe name'), { target: { value: 'Oat Recipe' } });
    await user.selectOptions(screen.getByLabelText('Initial status'), 'ACTIVE');
    await user.click(screen.getByRole('button', { name: 'Add component' }));
    await user.selectOptions(screen.getByLabelText('Component SKU 1'), 'sku-raw');
    fireEvent.change(screen.getByLabelText('Component quantity 1'), { target: { value: '0.1' } });
    await user.selectOptions(screen.getByLabelText('Component UOM 1'), 'KG');
    await user.click(screen.getByRole('button', { name: 'Create recipe' }));

    await waitFor(() => expect(apiMocks.createProductMaster).toHaveBeenCalledWith(
      'recipes',
      expect.objectContaining({
        code: 'REC-OAT', output_sku_id: 'sku-1',
        components: [expect.objectContaining({ component_sku_id: 'sku-raw', sequence_no: 10, quantity: '0.1' })],
      }),
      expect.any(String),
    ));
  });

  it('creates a production route with ordered work-centre operations', async () => {
    const user = userEvent.setup();
    renderPage('routes');
    await screen.findByText('Route Seed');
    await user.click(screen.getByRole('button', { name: '+ New' }));
    fireEvent.change(screen.getByLabelText('Route code'), { target: { value: 'route-oat' } });
    fireEvent.change(screen.getByLabelText('Route name'), { target: { value: 'Oat Route' } });
    await user.selectOptions(screen.getByLabelText('Initial status'), 'ACTIVE');
    await user.click(screen.getByRole('button', { name: 'Add operation' }));
    fireEvent.change(screen.getByLabelText('Operation name 1'), { target: { value: 'Mix' } });
    fireEvent.change(screen.getByLabelText('Operation work centre 1'), { target: { value: 'mix-01' } });
    await user.click(screen.getByRole('button', { name: 'Create route' }));

    await waitFor(() => expect(apiMocks.createProductMaster).toHaveBeenCalledWith(
      'routes',
      expect.objectContaining({
        code: 'ROUTE-OAT', catalog_item_id: 'item-1',
        operations: [expect.objectContaining({ sequence_no: 10, work_center_code: 'MIX-01' })],
      }),
      expect.any(String),
    ));
  });

  it('creates a typed quality specification and preserves parameter IDs on update', async () => {
    const user = userEvent.setup();
    renderPage('specifications');
    await screen.findByText('Specification Seed');
    await user.click(screen.getByRole('button', { name: '+ New' }));
    fireEvent.change(screen.getByLabelText('Specification code'), { target: { value: 'spec-oat' } });
    fireEvent.change(screen.getByLabelText('Specification name'), { target: { value: 'Oat Standard' } });
    await user.selectOptions(screen.getByLabelText('Initial status'), 'ACTIVE');
    await user.click(screen.getByRole('button', { name: 'Add parameter' }));
    fireEvent.change(screen.getByLabelText('Parameter code 1'), { target: { value: 'moisture' } });
    fireEvent.change(screen.getByLabelText('Parameter name 1'), { target: { value: 'Moisture' } });
    fireEvent.change(screen.getByLabelText('Parameter maximum 1'), { target: { value: '5' } });
    await user.click(screen.getByRole('button', { name: 'Create specification' }));
    await waitFor(() => expect(apiMocks.createProductMaster).toHaveBeenCalledWith(
      'specifications',
      expect.objectContaining({
        code: 'SPEC-OAT', target_type: 'ITEM', catalog_item_id: 'item-1',
        parameters: [expect.objectContaining({ code: 'MOISTURE', maximum_value: '5' })],
      }),
      expect.any(String),
    ));

    apiMocks.getProductMaster.mockResolvedValue(detail('specifications'));
    await user.click(screen.getByRole('button', { name: 'Back to register' }));
    await user.click(screen.getByRole('button', { name: /Expand SPECIFICATIONS-001 Specification Seed/i }));
    await user.click(screen.getByRole('button', { name: 'Open edit card' }));
    const parameterName = await screen.findByLabelText('Parameter name 1');
    await user.clear(parameterName);
    await user.type(parameterName, 'Net weight updated');
    await user.click(screen.getByRole('button', { name: 'Save specification' }));
    await waitFor(() => expect(apiMocks.updateProductMaster).toHaveBeenCalledWith(
      'specifications',
      expect.objectContaining({ id: 'specifications-1', record_version: 2 }),
      expect.objectContaining({ parameters: [expect.objectContaining({ id: 'parameter-1', name: 'Net weight updated' })] }),
      expect.any(String),
    ));
  });

  it('uses the refreshed version for a lifecycle command', async () => {
    const user = userEvent.setup();
    apiMocks.getProductMaster
      .mockResolvedValueOnce(detail('items'))
      .mockResolvedValueOnce(detail('items', { description: 'Updated', record_version: 3 }))
      .mockResolvedValueOnce(detail('items', { status: 'INACTIVE', record_version: 4, allowed_statuses: ['ACTIVE'] }));
    renderPage('items');
    await screen.findByText('Item Seed');
    await user.click(screen.getByRole('button', { name: /Expand ITEMS-001 Item Seed/i }));
    await user.click(screen.getByRole('button', { name: 'Open edit card' }));
    fireEvent.change(await screen.findByLabelText('Item description'), { target: { value: 'Updated' } });
    await user.click(screen.getByRole('button', { name: 'Save item' }));
    await waitFor(() => expect(apiMocks.updateProductMaster).toHaveBeenCalled());
    fireEvent.change(screen.getByLabelText('Item status reason'), { target: { value: 'Retired safely' } });
    await user.click(screen.getByRole('button', { name: 'Apply status' }));
    await waitFor(() => expect(apiMocks.changeProductMasterStatus).toHaveBeenCalledWith(
      'items',
      expect.objectContaining({ id: 'items-1', record_version: 3 }),
      'INACTIVE',
      'Retired safely',
      expect.any(String),
    ));
  });

  it('supports accessible accordion movement and retains register state around an edit card', async () => {
    const user = userEvent.setup();
    const loaded = workspace('items');
    loaded.data.push(detail('items', { id: 'items-2', code: 'ITEMS-002', name: 'Second Item' }));
    loaded.meta.total = 2;
    apiMocks.listProductMasters.mockResolvedValue(loaded);
    renderPage('items');

    const firstToggle = await screen.findByRole('button', { name: /Expand ITEMS-001 Item Seed/i });
    const secondToggle = screen.getByRole('button', { name: /Expand ITEMS-002 Second Item/i });
    await user.click(firstToggle);
    expect(firstToggle).toHaveAttribute('aria-expanded', 'true');
    await user.keyboard('{ArrowDown}');
    expect(secondToggle).toHaveFocus();
    await user.click(secondToggle);
    expect(firstToggle).toHaveAttribute('aria-expanded', 'false');
    expect(secondToggle).toHaveAttribute('aria-expanded', 'true');

    await user.selectOptions(screen.getByLabelText('Filter item status'), 'ACTIVE');
    await user.selectOptions(screen.getByLabelText('Sort items'), 'NAME');
    await user.click(screen.getByRole('button', { name: 'Open edit card' }));
    expect(window.location.hash).toContain('id=items-2');
    expect(window.location.hash).toContain('status=ACTIVE');
    expect(window.location.hash).toContain('sort=NAME');
    await user.click(await screen.findByRole('button', { name: 'Cancel' }));
    expect(window.location.hash).not.toContain('id=');
    expect(screen.getByLabelText('Filter item status')).toHaveValue('ACTIVE');
    expect(screen.getByLabelText('Sort items')).toHaveValue('NAME');
  });

  it('opens a direct record-ID route with its retained filters and supports browser Back', async () => {
    window.history.replaceState(null, '', '#MD-ITEM?q=oat&status=ACTIVE&sort=NAME&page=2&id=items-1');
    renderPage('items');

    expect(await screen.findByDisplayValue('ITEMS-001')).toBeInTheDocument();
    expect(apiMocks.getProductMaster).toHaveBeenCalledWith('items', 'items-1');
    expect(apiMocks.listProductMasters).toHaveBeenCalledWith('items', expect.objectContaining({
      q: 'oat', status: 'ACTIVE', sort: 'NAME', page: 2,
    }));

    await userEvent.setup().click(screen.getByRole('button', { name: 'Back to register' }));
    expect(window.location.hash).toContain('q=oat');
    expect(window.location.hash).toContain('page=2');
    expect(window.location.hash).not.toContain('id=');

    await userEvent.setup().click(screen.getByRole('button', { name: /Expand ITEMS-001 Item Seed/i }));
    await userEvent.setup().click(screen.getByRole('button', { name: 'Open edit card' }));
    expect(await screen.findByRole('button', { name: 'Cancel' })).toBeInTheDocument();
    window.history.back();
    await waitFor(() => expect(screen.queryByRole('button', { name: 'Cancel' })).not.toBeInTheDocument());
    expect(screen.getByLabelText('Search items')).toHaveValue('oat');
  });

  it('keeps read-only cards non-editable and surfaces version-conflict feedback', async () => {
    const readOnlyWorkspace = workspace('items');
    readOnlyWorkspace.allowed_actions = [];
    readOnlyWorkspace.data[0] = { ...readOnlyWorkspace.data[0], allowed_actions: [] };
    apiMocks.listProductMasters.mockResolvedValue(readOnlyWorkspace);
    apiMocks.getProductMaster.mockResolvedValue(detail('items', { allowed_actions: [] }));
    const readOnlySession = { ...session(), roles: ['WAREHOUSE_VIEWER'], allowed_actions: [] };
    renderPage('items', readOnlySession);

    await userEvent.setup().click(await screen.findByRole('button', { name: /Expand ITEMS-001 Item Seed/i }));
    await userEvent.setup().click(screen.getByRole('button', { name: 'Open read-only card' }));
    expect(await screen.findByLabelText('Item name')).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Save item' })).toBeDisabled();
    expect(screen.getByRole('button', { name: 'Cancel' })).toBeEnabled();

    screen.getByRole('button', { name: 'Cancel' }).click();
    const writableWorkspace = workspace('items');
    apiMocks.listProductMasters.mockResolvedValue(writableWorkspace);
  });

  it('shows the backend version-conflict message without discarding the loaded card', async () => {
    const user = userEvent.setup();
    apiMocks.updateProductMaster.mockRejectedValue({
      code: 'VERSION_CONFLICT',
      message: 'This item changed after you opened it. Reload the latest version before saving.',
    });
    renderPage('items');
    await user.click(await screen.findByRole('button', { name: /Expand ITEMS-001 Item Seed/i }));
    await user.click(screen.getByRole('button', { name: 'Open edit card' }));
    fireEvent.change(await screen.findByLabelText('Item name'), { target: { value: 'Conflicting edit' } });
    await user.click(screen.getByRole('button', { name: 'Save item' }));

    expect(await screen.findByRole('alert')).toHaveTextContent('changed after you opened it');
    expect(screen.getByLabelText('Item code')).toHaveValue('ITEMS-001');
    expect(window.location.hash).toContain('id=items-1');
  });
});

function renderPage(resource: ProductMasterResource, value: ErpSession = session()) {
  return render(<ErpSessionContext.Provider value={value}><ProductMasterWorkspace resource={resource} /></ErpSessionContext.Provider>);
}

function session(): ErpSession {
  const screens = ['MD-BRAND', 'MD-ITEM', 'MD-SKU', 'MD-REC', 'MD-ROUTE', 'MD-SPEC'];
  return {
    user: { id: 'manager-1', name: 'Operations Manager', email: 'operations@example.com' },
    roles: ['OPERATIONS_MANAGER'],
    allowed_screens: screens,
    allowed_actions: screens.flatMap((screenCode) => [
      `ACTION:${screenCode}:CREATE`, `ACTION:${screenCode}:UPDATE`, `ACTION:${screenCode}:LIFECYCLE`,
    ]),
    contexts: [],
    selected_context: {
      company_id: 'company-1', company_name: 'Q & T Foods Ltd', plant_id: 'plant-1', plant_name: 'Training Plant',
    },
  };
}

function workspace(resource: ProductMasterResource): Workspace {
  return {
    resource,
    data: [detail(resource)],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    summary: { total: 1, draft: 0, active: 1, inactive: 0 },
    lookups: {
      statuses: ['DRAFT', 'ACTIVE', 'INACTIVE'],
      item_types: ['RAW_MATERIAL', 'FINISHED_GOOD'],
      agreement_types: ['LICENSE', 'DISTRIBUTION', 'MANUFACTURING', 'SUPPLY'],
      agreement_statuses: ['DRAFT', 'ACTIVE', 'EXPIRED', 'TERMINATED'],
      rounding_modes: ['HALF_UP', 'UP', 'DOWN', 'NONE'],
      spec_target_types: ['ITEM', 'SKU'], spec_value_types: ['NUMERIC', 'TEXT', 'BOOLEAN'],
      uoms: [
        { code: 'EA', name: 'Each', precision: 0 }, { code: 'PACK', name: 'Pack', precision: 0 },
        { code: 'G', name: 'Gram', precision: 3 }, { code: 'KG', name: 'Kilogram', precision: 6 },
      ],
      brands: [{ id: 'brand-1', code: 'BRAND-1', name: 'Brand One', status: 'ACTIVE' }],
      items: [{ id: 'item-1', code: 'ITEM-1', name: 'Item One', status: 'ACTIVE' }],
      skus: [
        { id: 'sku-1', code: 'SKU-1', name: 'Output SKU', status: 'ACTIVE' },
        { id: 'sku-raw', code: 'SKU-RAW', name: 'Raw SKU', status: 'ACTIVE' },
      ],
      parties: [{ id: 'party-1', code: 'PARTY-1', name: 'Party One', status: 'ACTIVE' }],
    },
    allowed_actions: ['CREATE'],
  };
}

function detail(resource: ProductMasterResource, overrides: Partial<ProductMasterDetail> = {}): ProductMasterDetail {
  const base: ProductMasterDetail = {
    id: `${resource}-1`, company_id: 'company-1', code: `${resource.toUpperCase()}-001`,
    name: `${label(resource)} Seed`, status: 'ACTIVE', record_version: 2, child_count: 1,
    child_label: childLabel(resource), parent: null, status_change: null,
    allowed_actions: ['UPDATE', 'CHANGE_STATUS'], allowed_statuses: ['INACTIVE'],
    created_at: '2026-04-01T00:00:00Z', updated_at: '2026-04-02T00:00:00Z',
  };
  const specifics: Record<ProductMasterResource, Partial<ProductMasterDetail>> = {
    brands: {
      description: 'Brand description', agreements: [{
        id: 'agreement-1', party_id: 'party-1', agreement_number: 'AGR-001', agreement_type: 'DISTRIBUTION',
        effective_from: '2026-04-01', effective_to: null, currency_code: 'INR', minimum_commitment: '1000.00',
        status: 'ACTIVE', notes: null,
      }],
    },
    items: {
      brand_id: 'brand-1', item_type: 'RAW_MATERIAL', base_uom: 'KG', description: 'Item description',
      shelf_life_days: 90, lot_controlled: true, conversions: [{
        id: 'conversion-1', from_uom_code: 'KG', to_uom_code: 'G', multiplier: '1000.000000000',
        rounding_mode: 'HALF_UP',
      }],
    },
    skus: {
      catalog_item_id: 'item-1', barcode: '890000000001', description: 'SKU description',
      item_type: 'FINISHED_GOOD', base_uom: 'PACK', pack_quantity: '1.000000', pack_uom_code: 'PACK',
      parent: { code: 'ITEM-1', name: 'Item One' }, packs: [{
        id: 'pack-1', code: 'PACK-1', name: 'Retail pack', uom_code: 'PACK', quantity: '1.000000',
        barcode: '890000000002', is_default: true,
      }],
    },
    recipes: {
      revision: 1, output_sku_id: 'sku-1', output_quantity: '1.000000', output_uom_code: 'PACK',
      yield_percent: '98.500', effective_from: '2026-04-01', effective_to: null, notes: 'Recipe notes',
      parent: { code: 'SKU-1', name: 'Output SKU' }, components: [{
        id: 'component-1', component_sku_id: 'sku-raw', sequence_no: 10, quantity: '0.100000',
        uom_code: 'KG', waste_percent: '1.500',
      }],
    },
    routes: {
      catalog_item_id: 'item-1', description: 'Route description', parent: { code: 'ITEM-1', name: 'Item One' },
      operations: [{ id: 'operation-1', sequence_no: 10, name: 'Mix', work_center_code: 'MIX-01',
        setup_minutes: '10.000', run_minutes_per_unit: '0.050000', instructions: null }],
    },
    specifications: {
      target_type: 'SKU', catalog_item_id: null, sku_id: 'sku-1', effective_from: '2026-04-01',
      effective_to: null, sampling_plan: 'Five per lot', notes: 'Spec notes',
      parent: { code: 'SKU-1', name: 'Output SKU' }, parameters: [{
        id: 'parameter-1', sequence_no: 10, code: 'NET-WEIGHT', name: 'Net weight', value_type: 'NUMERIC',
        uom_code: 'G', minimum_value: '98.000000', target_value: '100.000000', maximum_value: '102.000000',
        text_requirement: null, test_method: 'Bench scale', is_required: true,
      }],
    },
  };
  return { ...base, ...specifics[resource], ...overrides };
}

function label(resource: ProductMasterResource) {
  return resource === 'skus' ? 'Sku' : resource.slice(0, -1).replace(/^./, (letter) => letter.toUpperCase());
}

function childLabel(resource: ProductMasterResource) {
  return ({
    brands: 'agreements', items: 'conversions', skus: 'packs', recipes: 'components',
    routes: 'operations', specifications: 'parameters',
  } as const)[resource];
}

import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ErpSessionContext } from '../app/ErpSessionContext';
import type { ErpSession } from '../types/session';
import { ScalePlantWorkspace } from './ScalePlantWorkspace';

const api = vi.hoisted(() => ({ listP2: vi.fn(), getP2: vi.fn(), commandP2: vi.fn(), downloadP2: vi.fn() }));
vi.mock('../api/p2Operations', () => api);

describe('Multi-Plant Control workspace', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    api.listP2.mockResolvedValue(workspace());
    api.commandP2.mockResolvedValue({ id: 'transfer-1', status: 'DRAFT', record_version: 1 });
  });

  it('renders the live multi-register workspace and prefills a mapped transfer command', async () => {
    const user = userEvent.setup();
    renderPage();

    expect(await screen.findByRole('heading', { level: 1, name: 'Multi-Plant Control' })).toBeInTheDocument();
    expect(screen.queryByText(/prototype action only/i)).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Transfer routes' }));
    expect(screen.getByText('TRAINING-TO-FIN')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: '+ New transfer' }));
    expect((screen.getByLabelText('Transfer route') as HTMLSelectElement).value).toBe('route-1');
    expect((screen.getByLabelText('Source stock position') as HTMLSelectElement).value).toBe('source-position-1');
    expect((screen.getByLabelText('Destination stock position') as HTMLSelectElement).value).toBe('destination-position-1');
    expect(screen.getByLabelText('Source stock position')).toHaveAccessibleName('Source stock position');
    expect(screen.getByRole('option', { name: /SKU-APPLE-100 · lot FG-APPLE-001 · FG-A1 · RELEASED · available 10 PACK/ })).toBeInTheDocument();
    expect(screen.queryByRole('textbox', { name: /command payload/i })).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(api.commandP2).toHaveBeenCalledWith(
      '/api/v1/scale/transfers',
      expect.objectContaining({ plant_transfer_route_id: 'route-1' }),
      undefined,
    ));
  });

  it('forwards the record version for source approval and destination receipt actions', async () => {
    const user = userEvent.setup();
    const submitted = transfer('SUBMITTED', 2, ['APPROVE']);
    api.listP2.mockResolvedValue({ ...workspace(), data: [submitted] });
    api.getP2.mockResolvedValue(submitted);
    api.commandP2.mockResolvedValue({ id: submitted.id, status: 'APPROVED', record_version: 3 });
    const sourceView = renderPage();

    await user.click(await screen.findByRole('button', { name: 'Open' }));
    await user.click(screen.getByRole('button', { name: 'Approve' }));
    await waitFor(() => expect(api.commandP2).toHaveBeenCalledWith('/api/v1/scale/transfers/transfer-1/approve', {}, 2));
    expect(await screen.findByText('Transfer independently approved at source.')).toBeInTheDocument();

    sourceView.unmount();
    vi.clearAllMocks();
    const inTransit = transfer('IN_TRANSIT', 4, ['RECEIVE']);
    api.listP2.mockResolvedValue({ ...workspace(), data: [inTransit] });
    api.getP2.mockResolvedValue(inTransit);
    api.commandP2.mockResolvedValue({ id: inTransit.id, status: 'RECEIVED', record_version: 5 });
    renderPage();
    await user.click(await screen.findByRole('button', { name: 'Open' }));
    await user.click(screen.getByRole('button', { name: 'Receive' }));
    await waitFor(() => expect(api.commandP2).toHaveBeenCalledWith('/api/v1/scale/transfers/transfer-1/receive', {}, 4));
  });
});

function renderPage() {
  return render(<ErpSessionContext.Provider value={session()}>
    <ScalePlantWorkspace />
  </ErpSessionContext.Provider>);
}

function workspace() {
  return {
    data: [], routes: [{
      id: 'route-1', route_code: 'TRAINING-TO-FIN', name: 'Training to finance', transfer_scope: 'INTER_PLANT',
      source_company_id: 'company-1', source_plant_id: 'plant-1', destination_company_id: 'company-1',
      destination_plant_id: 'plant-2', source_plant_name: 'Training Plant', destination_plant_name: 'Finance Review',
      status: 'ACTIVE', mapping_count: 1, record_version: 1, allowed_actions: [],
    }], groups: [], consolidation_runs: [], summary: { active_routes: 1, open_transfers: 0, in_transit: 0 },
    scope: { company_id: 'company-1', plant_id: 'plant-1' },
    lookups: {
      routes: [{
        id: 'route-1', transfer_scope: 'INTER_PLANT', source_company_id: 'company-1', source_plant_id: 'plant-1',
        destination_company_id: 'company-1', destination_plant_id: 'plant-2', require_commercial_reference: false,
      }],
      source_positions: [{
        id: 'source-position-1', company_id: 'company-1', plant_id: 'plant-1', item_id: 'item-1',
        lot_id: 'lot-1', inventory_owner_id: 'owner-1', item_code: 'SKU-APPLE-100', lot_code: 'FG-APPLE-001',
        location_code: 'FG-A1', quality_status: 'RELEASED', available_quantity: '10', uom_code: 'PACK',
      }],
      destination_positions: [{
        id: 'destination-position-1', company_id: 'company-1', plant_id: 'plant-2', item_id: 'item-1',
        lot_id: 'lot-1', inventory_owner_id: 'owner-1', item_code: 'SKU-APPLE-100', lot_code: 'FG-APPLE-001',
        location_code: 'FG-B1', quality_status: 'RELEASED', quantity_base: '0', available_quantity: '0', uom_code: 'PACK',
      }],
      plants: [], groups: [], group_members: [], items: [],
    },
    allowed_actions: ['TRANSFER-CREATE'],
  };
}

function transfer(status: string, recordVersion: number, allowedActions: string[]) {
  return {
    id: 'transfer-1', transfer_number: 'XFER-UI-001', route_code: 'TRAINING-TO-FIN', side: 'SOURCE',
    source_plant_name: 'Training Plant', destination_plant_name: 'Finance Review', expected_arrival_date: '2026-09-16',
    status, record_version: recordVersion, allowed_actions: allowedActions,
  };
}

function session(): ErpSession {
  return {
    user: { id: 'admin-1', name: 'ERP Administrator', email: 'admin@example.com' },
    roles: ['ERP_ADMIN'], allowed_screens: ['SCALE-PLANT'],
    allowed_actions: ['ACTION:SCALE-PLANT:TRANSFER-CREATE'],
    contexts: [{ company_id: 'company-1', company_name: 'Q & T Foods', plant_id: 'plant-1', plant_name: 'Training Plant' }],
    selected_context: { company_id: 'company-1', company_name: 'Q & T Foods', plant_id: 'plant-1', plant_name: 'Training Plant' },
  };
}

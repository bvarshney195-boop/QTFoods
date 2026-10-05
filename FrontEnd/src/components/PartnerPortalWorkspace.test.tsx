import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ErpSessionContext } from '../app/ErpSessionContext';
import type { ErpSession } from '../types/session';
import { PartnerPortalWorkspace } from './PartnerPortalWorkspace';

const api = vi.hoisted(() => ({
  listP2: vi.fn(), getP2: vi.fn(), commandP2: vi.fn(), uploadP2: vi.fn(), downloadP2: vi.fn(),
}));
vi.mock('../api/p2Operations', () => api);

describe('Partner Portal workspace', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    api.commandP2.mockResolvedValue({ id: 'result-1', status: 'OPEN', record_version: 1 });
    api.uploadP2.mockResolvedValue({ id: 'document-2', status: 'AVAILABLE', record_version: 1 });
  });

  it('renders an exact external tenant and submits a claim from entitled shipment lines', async () => {
    const user = userEvent.setup();
    api.listP2.mockResolvedValue(partnerWorkspace());
    renderPage(partnerSession());

    expect(await screen.findByRole('heading', { level: 1, name: 'Partner portal' })).toBeInTheDocument();
    expect(screen.getByText(/External organisation: North Market Distributor/i)).toBeInTheDocument();
    expect(screen.getByText(/acknowledgements record receipt only/i)).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Access grants/i })).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: '+ New claim' }));
    expect(screen.getByLabelText('Partner claim shipment')).toHaveValue('shipment-1');
    expect(screen.getByLabelText('Partner claim shipment line')).toHaveValue('shipment-line-1');
    await user.type(screen.getByLabelText('Partner claim reason'), 'Delivered carton was visibly damaged.');
    await user.click(screen.getByRole('button', { name: 'Save' }));

    await waitFor(() => expect(api.commandP2).toHaveBeenCalledWith(
      '/api/v1/partner/claims',
      expect.objectContaining({
        shipment_id: 'shipment-1',
        reason: 'Delivered carton was visibly damaged.',
        lines: [{ shipment_line_id: 'shipment-line-1', quantity: '1' }],
      }),
    ));
    expect(await screen.findByText('Customer claim submitted for internal review.')).toBeInTheDocument();
  });

  it('publishes from the internal view and acknowledges from the external view with record version', async () => {
    const user = userEvent.setup();
    api.listP2.mockResolvedValue(internalWorkspace());
    const internal = renderPage(adminSession());

    expect(await screen.findByText(/Internal administration view/i)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: '+ Publish document' }));
    await user.selectOptions(screen.getByLabelText('Partner document link type'), 'sales_order_id');
    expect(screen.getByRole('option', { name: 'SO-ENTITLED-001 · Confirmed' })).toBeInTheDocument();
    await user.selectOptions(screen.getByLabelText('Partner document entitled business record'), 'order-1');
    expect(screen.queryByRole('textbox', { name: /UUID|record ID/i })).not.toBeInTheDocument();
    await user.type(screen.getByLabelText('Partner document title'), 'September delivery packet');
    await user.upload(screen.getByLabelText('Partner private document'), new File(['private packet'], 'packet.pdf', { type: 'application/pdf' }));
    await user.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(api.uploadP2).toHaveBeenCalledWith('/api/v1/partner/documents/publish', expect.any(FormData)));
    const published = api.uploadP2.mock.calls[0][1] as FormData;
    expect(published.get('party_id')).toBe('party-1');
    expect(published.get('title')).toBe('September delivery packet');
    expect(published.get('sales_order_id')).toBe('order-1');
    expect(published.get('company_id')).toBeNull();
    expect(published.get('plant_id')).toBeNull();

    internal.unmount();
    vi.clearAllMocks();
    const workspace = partnerWorkspace();
    api.listP2.mockResolvedValue(workspace);
    api.getP2.mockResolvedValue(workspace.documents[0]);
    api.commandP2.mockResolvedValue({ id: 'document-1', status: 'ACKNOWLEDGED', record_version: 2 });
    renderPage(partnerSession());

    await user.click(await screen.findByRole('button', { name: 'Open' }));
    await user.click(screen.getByRole('button', { name: 'Acknowledge' }));
    await user.type(screen.getByLabelText('Acknowledgement reference'), 'RECEIPT-UI-001');
    await user.click(screen.getByRole('button', { name: 'Save' }));
    await waitFor(() => expect(api.commandP2).toHaveBeenCalledWith(
      '/api/v1/partner/documents/document-1/acknowledge',
      { acknowledgement_reference: 'RECEIPT-UI-001' },
      1,
    ));
  });
});

function renderPage(session: ErpSession) {
  return render(<ErpSessionContext.Provider value={session}><PartnerPortalWorkspace /></ErpSessionContext.Provider>);
}

function partnerWorkspace() {
  return {
    data: [], mode: 'PARTNER', identity: { user_id: 'partner-1', party_id: 'party-1', party_name: 'North Market Distributor' },
    entitlements: ['ORDERS_VIEW', 'SHIPMENTS_VIEW', 'INVOICES_VIEW', 'CLAIMS_VIEW', 'CLAIMS_CREATE', 'DOCUMENTS_VIEW', 'DOCUMENT_UPLOAD', 'DOCUMENT_DOWNLOAD', 'DOCUMENT_ACKNOWLEDGE'],
    access_grants: [], orders: [], invoices: [], claims: [],
    shipments: [{ id: 'shipment-1', shipment_number: 'SHP-001', status: 'DELIVERED', dispatched_at: '2026-09-01', record_version: 1 }],
    documents: [{ id: 'document-1', document_number: 'DOC-001', title: 'Delivery packet', direction: 'OUTBOUND', document_type: 'SHIPPING_DOCUMENT', original_name: 'packet.pdf', status: 'AVAILABLE', record_version: 1, allowed_actions: ['DOWNLOAD', 'ACKNOWLEDGE'] }],
    summary: { shipments: 1, available_documents: 1 }, allowed_actions: ['CLAIM-CREATE', 'DOCUMENT-UPLOAD', 'DOCUMENT-DOWNLOAD', 'DOCUMENT-ACKNOWLEDGE'],
    lookups: {
      claim_types: ['DAMAGE', 'SHORTAGE'], claim_resolutions: ['CREDIT', 'REPLACEMENT'], document_types: ['GENERAL', 'CLAIM_EVIDENCE'],
      claimable_shipments: [{ id: 'shipment-1', shipment_number: 'SHP-001', status: 'DELIVERED', lines: [{ id: 'shipment-line-1', item_code: 'APPLE', item_name: 'Apple pack', shipped_quantity: '12', returned_quantity: '0', uom_code: 'PACK' }] }],
      customers: [], entitlements: [],
    },
  };
}

function internalWorkspace() {
  return {
    data: [], mode: 'INTERNAL', identity: { user_id: 'admin-1' }, entitlements: [],
    access_grants: [{ id: 'grant-1', user_id: 'partner-1', user_name: 'Partner User', user_email: 'partner@example.com', party_id: 'party-1', party_name: 'North Market Distributor', status: 'ACTIVE', effective_status: 'ACTIVE', record_version: 1, entitlements: ['DOCUMENTS_VIEW'], allowed_actions: ['UPDATE', 'REVOKE'] }],
    documents: [], orders: [{ id: 'order-1', order_number: 'SO-ENTITLED-001', status: 'CONFIRMED' }], shipments: [], invoices: [], claims: [], summary: { active_grants: 1, available_documents: 0 },
    allowed_actions: ['ACCESS-GRANT', 'ACCESS-UPDATE', 'ACCESS-REVOKE', 'DOCUMENT-PUBLISH', 'DOCUMENT-DOWNLOAD', 'DOCUMENT-WITHDRAW'],
    lookups: {
      users: [{ id: 'partner-2', name: 'Second Partner', email: 'second@example.com', portal_eligible: true }],
      customers: [{ id: 'party-1', code: 'DIST-NORTH', name: 'North Market Distributor' }],
      entitlements: ['DOCUMENTS_VIEW', 'DOCUMENT_DOWNLOAD'], document_types: ['GENERAL', 'SHIPPING_DOCUMENT'],
      claim_types: [], claim_resolutions: [],
    },
  };
}

function partnerSession(): ErpSession {
  return {
    user: { id: 'partner-1', name: 'Partner User', email: 'partner@example.com' }, roles: ['PARTNER_PORTAL'],
    allowed_screens: ['PORTAL-EXT'], allowed_actions: ['ACTION:PORTAL-EXT:CLAIM-CREATE', 'ACTION:PORTAL-EXT:DOCUMENT-ACKNOWLEDGE'],
    contexts: [{ company_id: 'company-1', company_name: 'Q & T Foods', plant_id: 'plant-1', plant_name: 'Training Plant' }],
    selected_context: { company_id: 'company-1', company_name: 'Q & T Foods', plant_id: 'plant-1', plant_name: 'Training Plant' },
  };
}

function adminSession(): ErpSession {
  return {
    user: { id: 'admin-1', name: 'ERP Administrator', email: 'admin@example.com' }, roles: ['ERP_ADMIN'],
    allowed_screens: ['PORTAL-EXT'], allowed_actions: ['ACTION:PORTAL-EXT:ACCESS-GRANT', 'ACTION:PORTAL-EXT:DOCUMENT-PUBLISH'],
    contexts: [{ company_id: 'company-1', company_name: 'Q & T Foods', plant_id: 'plant-1', plant_name: 'Training Plant' }],
    selected_context: { company_id: 'company-1', company_name: 'Q & T Foods', plant_id: 'plant-1', plant_name: 'Training Plant' },
  };
}

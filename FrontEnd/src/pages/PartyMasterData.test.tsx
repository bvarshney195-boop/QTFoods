import { fireEvent, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { PartyDetail, PartyWorkspace } from '../api/parties';
import { ErpSessionContext } from '../app/ErpSessionContext';
import type { ErpSession } from '../types/session';
import MD_PARTY from './MD_PARTY';

const apiMocks = vi.hoisted(() => ({
  listParties: vi.fn(),
  getParty: vi.fn(),
  createParty: vi.fn(),
  updateParty: vi.fn(),
  changePartyStatus: vi.fn(),
}));

vi.mock('../api/parties', async () => {
  const actual = await vi.importActual<typeof import('../api/parties')>('../api/parties');
  return { ...actual, ...apiMocks };
});

describe('party master workspace', () => {
  beforeEach(() => {
    window.history.replaceState(null, '', '#');
    vi.resetAllMocks();
    apiMocks.listParties.mockResolvedValue(workspace());
    apiMocks.getParty.mockResolvedValue(detail());
  });

  it('filters the company register and opens the complete party aggregate', async () => {
    const user = userEvent.setup();
    renderPage();

    expect(await screen.findByText('West Distributor')).toBeInTheDocument();
    await user.selectOptions(screen.getByLabelText('Filter party role'), 'CUSTOMER');
    await waitFor(() => expect(apiMocks.listParties).toHaveBeenLastCalledWith(
      expect.objectContaining({ role: 'CUSTOMER' }),
    ));
    await user.type(screen.getByLabelText('Search parties'), 'west');
    await user.click(screen.getByRole('button', { name: 'Search' }));
    await waitFor(() => expect(apiMocks.listParties).toHaveBeenLastCalledWith(
      expect.objectContaining({ q: 'west', role: 'CUSTOMER' }),
    ));

    await user.click(screen.getByRole('button', { name: /Expand DIST-WEST West Distributor/i }));
    await user.click(screen.getByRole('button', { name: 'Open edit card' }));
    expect(await screen.findByDisplayValue('24ABCDE1234F1Z3')).toBeInTheDocument();
    expect(screen.getByLabelText('Commercial currency')).toHaveValue('INR');
    expect(screen.getByLabelText('Credit limit')).toHaveValue(250000);
    expect(apiMocks.getParty).toHaveBeenCalledWith('party-1');
  });

  it('creates an active party with its address, contact, tax, and commercial terms', async () => {
    const user = userEvent.setup();
    const created = detail({ id: 'party-2', code: 'FRESH-CUST', display_name: 'Fresh Customer', legal_name: 'Fresh Customer Private Limited', record_version: 1 });
    apiMocks.createParty.mockResolvedValue({ entity_type: 'party', id: 'party-2', status: 'ACTIVE', record_version: 1 });
    apiMocks.getParty.mockImplementation(async (id: string) => id === 'party-2' ? created : detail());
    renderPage();

    await screen.findByText('West Distributor');
    await user.click(screen.getByRole('button', { name: '+ New' }));
    const partyCode = screen.getByLabelText('Party code');
    expect(partyCode).toHaveValue('');
    expect(partyCode).not.toHaveAttribute('placeholder');
    fireEvent.change(partyCode, { target: { value: 'fresh-cust' } });
    fireEvent.change(screen.getByLabelText('Display name'), { target: { value: 'Fresh Customer' } });
    fireEvent.change(screen.getByLabelText('Legal name'), { target: { value: 'Fresh Customer Private Limited' } });
    fireEvent.change(screen.getByLabelText('Initial status'), { target: { value: 'ACTIVE' } });
    fireEvent.change(screen.getByLabelText('Address line 1 1'), { target: { value: '12 Market Road' } });
    fireEvent.change(screen.getByLabelText('Address city 1'), { target: { value: 'Ahmedabad' } });
    fireEvent.change(screen.getByLabelText('Address region 1'), { target: { value: 'Gujarat' } });
    fireEvent.change(screen.getByLabelText('Address postal code 1'), { target: { value: '380001' } });
    fireEvent.change(screen.getByLabelText('Contact name 1'), { target: { value: 'Priya Shah' } });
    fireEvent.change(screen.getByLabelText('Contact email 1'), { target: { value: 'PRIYA@EXAMPLE.COM' } });
    await user.click(screen.getByRole('button', { name: 'Add registration' }));
    fireEvent.change(screen.getByLabelText('Tax number 1'), { target: { value: '24AACCF1234A1Z5' } });
    fireEvent.change(screen.getByLabelText('Payment terms days'), { target: { value: '30' } });
    fireEvent.change(screen.getByLabelText('Credit limit'), { target: { value: '50000' } });
    await user.click(screen.getByRole('button', { name: 'Create party' }));

    await waitFor(() => expect(apiMocks.createParty).toHaveBeenCalledWith(
      expect.objectContaining({
        code: 'FRESH-CUST', status: 'ACTIVE', roles: ['CUSTOMER'],
        addresses: [expect.objectContaining({ line_1: '12 Market Road', city: 'Ahmedabad', country_code: 'IN' })],
        contacts: [expect.objectContaining({ name: 'Priya Shah', email: 'priya@example.com' })],
        tax_registrations: [expect.objectContaining({ registration_type: 'GSTIN', registration_number: '24AACCF1234A1Z5' })],
        commercial_terms: expect.objectContaining({ currency_code: 'INR', payment_terms_days: 30, credit_limit: '50000' }),
      }),
      expect.any(String),
    ));
    expect(await screen.findByRole('status')).toHaveTextContent('FRESH-CUST was created as ACTIVE');
  });

  it('preserves child identities on update and uses the refreshed version for lifecycle control', async () => {
    const user = userEvent.setup();
    apiMocks.getParty
      .mockResolvedValueOnce(detail())
      .mockResolvedValueOnce(detail({ display_name: 'West Distributor Updated', record_version: 3 }))
      .mockResolvedValueOnce(detail({ display_name: 'West Distributor Updated', status: 'ON_HOLD', record_version: 4, allowed_statuses: ['ACTIVE', 'INACTIVE'] }));
    apiMocks.updateParty.mockResolvedValue({ entity_type: 'party', id: 'party-1', status: 'ACTIVE', record_version: 3 });
    apiMocks.changePartyStatus.mockResolvedValue({ entity_type: 'party', id: 'party-1', status: 'ON_HOLD', record_version: 4 });
    renderPage();

    await screen.findByText('West Distributor');
    await user.click(screen.getByRole('button', { name: /Expand DIST-WEST West Distributor/i }));
    await user.click(screen.getByRole('button', { name: 'Open edit card' }));
    const displayName = await screen.findByLabelText('Display name');
    await user.clear(displayName);
    await user.type(displayName, 'West Distributor Updated');
    await user.click(screen.getByRole('button', { name: 'Save party' }));

    await waitFor(() => expect(apiMocks.updateParty).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'party-1', record_version: 2 }),
      expect.objectContaining({
        display_name: 'West Distributor Updated',
        addresses: [expect.objectContaining({ id: 'address-1' })],
        contacts: [expect.objectContaining({ id: 'contact-1' })],
        tax_registrations: [expect.objectContaining({ id: 'tax-1' })],
      }),
      expect.any(String),
    ));

    await user.type(screen.getByLabelText('Party status reason'), 'Credit review required');
    await user.click(screen.getByRole('button', { name: 'Apply status' }));
    await waitFor(() => expect(apiMocks.changePartyStatus).toHaveBeenCalledWith(
      expect.objectContaining({ id: 'party-1', record_version: 3 }),
      'ON_HOLD',
      'Credit review required',
      expect.any(String),
    ));
    expect(await screen.findByRole('status')).toHaveTextContent('moved to On hold at version 4');
  });
});

function renderPage() {
  return render(<ErpSessionContext.Provider value={session()}><MD_PARTY /></ErpSessionContext.Provider>);
}

function session(): ErpSession {
  return {
    user: { id: 'manager-1', name: 'Operations Manager', email: 'operations@qtfoods.local' },
    roles: ['OPERATIONS_MANAGER'],
    allowed_screens: ['MD-PARTY'],
    allowed_actions: ['ACTION:MD-PARTY:CREATE', 'ACTION:MD-PARTY:UPDATE', 'ACTION:MD-PARTY:LIFECYCLE'],
    contexts: [],
    selected_context: {
      company_id: 'company-1', company_name: 'Q & T Foods Ltd',
      plant_id: 'plant-1', plant_name: 'Training Plant',
    },
  };
}

function workspace(): PartyWorkspace {
  const party = detail();
  return {
    data: [party],
    meta: { current_page: 1, last_page: 1, per_page: 25, total: 1 },
    summary: { total: 1, draft: 0, active: 1, on_hold: 0, inactive: 0, customers: 1, suppliers: 0 },
    lookups: {
      statuses: ['DRAFT', 'ACTIVE', 'ON_HOLD', 'INACTIVE'],
      party_kinds: ['ORGANISATION', 'INDIVIDUAL'],
      role_codes: ['CUSTOMER', 'SUPPLIER', 'CARRIER', 'SERVICE_PROVIDER'],
      address_types: ['REGISTERED', 'BILLING', 'SHIPPING', 'REMITTANCE', 'OTHER'],
      tax_types: ['GSTIN', 'PAN', 'VAT', 'TIN', 'OTHER'], countries: ['IN'],
    },
    allowed_actions: ['CREATE'],
  };
}

function detail(overrides: Partial<PartyDetail> = {}): PartyDetail {
  const party: PartyDetail = {
    id: 'party-1', company_id: 'company-1', code: 'DIST-WEST',
    display_name: 'West Distributor', legal_name: 'West Distributor Private Limited',
    party_kind: 'ORGANISATION', status: 'ACTIVE', roles: ['CUSTOMER'], notes: 'Priority account',
    primary_address: {
      id: 'address-1', label: 'Registered office', address_type: 'REGISTERED', line_1: '44 Trade Road',
      line_2: null, city: 'Surat', district: null, region: 'Gujarat', postal_code: '395003', country_code: 'IN', is_primary: true,
    },
    primary_contact: {
      id: 'contact-1', name: 'Raj Mehta', job_title: 'Buyer', department: 'Procurement',
      email: 'raj@example.com', phone: null, mobile: '+919900000001', is_primary: true,
    },
    address_count: 1, contact_count: 1, tax_registration_count: 1,
    commercial_terms: {
      id: 'terms-1', currency_code: 'INR', payment_terms_days: 30, credit_limit: '250000.00',
      credit_hold: false, incoterm_code: 'DAP', delivery_terms: 'Door delivery',
    },
    status_change: null, record_version: 2, allowed_actions: ['UPDATE', 'CHANGE_STATUS'],
    allowed_statuses: ['ON_HOLD', 'INACTIVE'], created_at: now, updated_at: now,
    addresses: [], contacts: [], tax_registrations: [],
  };
  party.addresses = [party.primary_address!];
  party.contacts = [party.primary_contact!];
  party.tax_registrations = [{
    id: 'tax-1', registration_type: 'GSTIN', registration_number: '24ABCDE1234F1Z3',
    country_code: 'IN', is_primary: true, valid_from: '2025-04-01', valid_to: null,
  }];
  return { ...party, ...overrides };
}

const now = '2026-09-10T10:00:00.000Z';

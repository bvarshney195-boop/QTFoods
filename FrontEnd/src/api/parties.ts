import { apiMutation, apiRequest } from './client';

export type PartyStatus = 'DRAFT' | 'ACTIVE' | 'ON_HOLD' | 'INACTIVE';
export type PartyKind = 'ORGANISATION' | 'INDIVIDUAL';
export type PartyRole = 'CUSTOMER' | 'SUPPLIER' | 'CARRIER' | 'SERVICE_PROVIDER';
export type PartyAddressType = 'REGISTERED' | 'BILLING' | 'SHIPPING' | 'REMITTANCE' | 'OTHER';
export type PartyTaxType = 'GSTIN' | 'PAN' | 'VAT' | 'TIN' | 'OTHER';

export type PartyAddress = {
  id: string;
  label: string;
  address_type: PartyAddressType;
  line_1: string;
  line_2: string | null;
  city: string;
  district: string | null;
  region: string;
  postal_code: string;
  country_code: string;
  is_primary: boolean;
};

export type PartyContact = {
  id: string;
  name: string;
  job_title: string | null;
  department: string | null;
  email: string | null;
  phone: string | null;
  mobile: string | null;
  is_primary: boolean;
};

export type PartyTaxRegistration = {
  id: string;
  registration_type: PartyTaxType;
  registration_number: string;
  country_code: string;
  is_primary: boolean;
  valid_from: string | null;
  valid_to: string | null;
};

export type PartyCommercialTerms = {
  id: string;
  currency_code: string;
  payment_terms_days: number;
  credit_limit: string;
  credit_hold: boolean;
  incoterm_code: string | null;
  delivery_terms: string | null;
};

export type PartyListItem = {
  id: string;
  company_id: string;
  code: string;
  display_name: string;
  legal_name: string;
  party_kind: PartyKind;
  status: PartyStatus;
  roles: PartyRole[];
  primary_address: PartyAddress | null;
  primary_contact: PartyContact | null;
  address_count: number;
  contact_count: number;
  tax_registration_count: number;
  commercial_terms: PartyCommercialTerms | null;
  status_change: {
    reason: string | null;
    changed_at: string;
    changed_by: { id: string; name: string } | null;
  } | null;
  record_version: number;
  allowed_actions: ('UPDATE' | 'CHANGE_STATUS')[];
  allowed_statuses: PartyStatus[];
  created_at: string;
  updated_at: string;
};

export type PartyDetail = PartyListItem & {
  notes: string | null;
  addresses: PartyAddress[];
  contacts: PartyContact[];
  tax_registrations: PartyTaxRegistration[];
};

export type PartyWorkspace = {
  data: PartyListItem[];
  meta: { current_page: number; last_page: number; per_page: number; total: number };
  summary: {
    total: number;
    draft: number;
    active: number;
    on_hold: number;
    inactive: number;
    customers: number;
    suppliers: number;
  };
  lookups: {
    statuses: PartyStatus[];
    party_kinds: PartyKind[];
    role_codes: PartyRole[];
    address_types: PartyAddressType[];
    tax_types: PartyTaxType[];
    countries: string[];
  };
  allowed_actions: ('CREATE')[];
};

export type PartyAddressWrite = Omit<PartyAddress, 'id'> & { id?: string };
export type PartyContactWrite = Omit<PartyContact, 'id'> & { id?: string };
export type PartyTaxRegistrationWrite = Omit<PartyTaxRegistration, 'id'> & { id?: string };
export type PartyCommercialTermsWrite = Omit<PartyCommercialTerms, 'id'>;

export type PartyWrite = {
  display_name: string;
  legal_name: string;
  party_kind: PartyKind;
  notes: string | null;
  roles: PartyRole[];
  addresses: PartyAddressWrite[];
  contacts: PartyContactWrite[];
  tax_registrations: PartyTaxRegistrationWrite[];
  commercial_terms: PartyCommercialTermsWrite;
};

export type PartyCreate = PartyWrite & { code: string; status: 'DRAFT' | 'ACTIVE' };

export type PartyCommandResult = {
  entity_type: 'party';
  id: string;
  status: PartyStatus;
  record_version: number;
  role_count?: number;
  address_count?: number;
  contact_count?: number;
  tax_registration_count?: number;
};

export async function listParties(filters: {
  page?: number;
  per_page?: number;
  q?: string;
  status?: PartyStatus | '';
  party_kind?: PartyKind | '';
  role?: PartyRole | '';
  country?: string;
  sort?: 'CODE' | 'NAME' | 'NEWEST' | 'OLDEST';
} = {}): Promise<PartyWorkspace> {
  const query = new URLSearchParams();
  for (const [key, value] of Object.entries(filters)) {
    if (value !== undefined && value !== '') query.set(key, String(value));
  }
  return apiRequest<PartyWorkspace>(`/api/v1/master/parties${query.size ? `?${query}` : ''}`);
}

export async function getParty(partyId: string): Promise<PartyDetail> {
  return (await apiRequest<{ data: PartyDetail }>(`/api/v1/master/parties/${partyId}`)).data;
}

export async function createParty(body: PartyCreate, idempotencyKey: string): Promise<PartyCommandResult> {
  return (await apiMutation<{ data: PartyCommandResult }>('/api/v1/master/parties', body, {
    idempotencyKey,
  })).data;
}

export async function updateParty(
  party: Pick<PartyDetail, 'id' | 'record_version'>,
  body: PartyWrite,
  idempotencyKey: string,
): Promise<PartyCommandResult> {
  return (await apiMutation<{ data: PartyCommandResult }>(`/api/v1/master/parties/${party.id}`, body, {
    expectedVersion: party.record_version,
    idempotencyKey,
  })).data;
}

export async function changePartyStatus(
  party: Pick<PartyDetail, 'id' | 'record_version'>,
  targetStatus: PartyStatus,
  reason: string,
  idempotencyKey: string,
): Promise<PartyCommandResult> {
  return (await apiMutation<{ data: PartyCommandResult }>(`/api/v1/master/parties/${party.id}/status`, {
    target_status: targetStatus,
    reason,
  }, {
    expectedVersion: party.record_version,
    idempotencyKey,
  })).data;
}

export async function deleteParty(
  party: Pick<PartyDetail, 'id' | 'record_version'>,
  idempotencyKey: string,
): Promise<Omit<PartyCommandResult, 'status'> & { status: 'DELETED'; deleted: true }> {
  return (await apiMutation<{ data: Omit<PartyCommandResult, 'status'> & { status: 'DELETED'; deleted: true } }>(`/api/v1/master/parties/${party.id}/delete`, {
    confirmed: true,
  }, {
    expectedVersion: party.record_version,
    idempotencyKey,
  })).data;
}

import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { isApiError } from '../api/client';
import {
  changePartyStatus,
  createParty,
  deleteParty,
  getParty,
  listParties,
  updateParty,
  type PartyAddressType,
  type PartyAddressWrite,
  type PartyContactWrite,
  type PartyCreate,
  type PartyDetail,
  type PartyKind,
  type PartyListItem,
  type PartyRole,
  type PartyStatus,
  type PartyTaxRegistrationWrite,
  type PartyTaxType,
  type PartyWorkspace,
  type PartyWrite,
} from '../api/parties';
import { useErpSession } from '../app/ErpSessionContext';
import {
  readRegisterRouteParams,
  readRouteChoice,
  readRoutePage,
  readRouteString,
  revealEditCard,
  revealRegister,
  useEditCardNavigation,
} from '../app/useEditCardNavigation';
import { PageHeader } from '../components/PageHeader';
import { DeleteEntryDialog } from '../components/DeleteEntryDialog';
import { ExpandableRegister, RegisterDetailGrid } from '../components/ExpandableRegister';
import { StatusBadge } from '../components/StatusBadge';

const roles: PartyRole[] = ['CUSTOMER', 'SUPPLIER', 'CARRIER', 'SERVICE_PROVIDER'];
const addressTypes: PartyAddressType[] = ['REGISTERED', 'BILLING', 'SHIPPING', 'REMITTANCE', 'OTHER'];
const taxTypes: PartyTaxType[] = ['GSTIN', 'PAN', 'VAT', 'TIN', 'OTHER'];
const partyStatuses: readonly PartyStatus[] = ['DRAFT', 'ACTIVE', 'ON_HOLD', 'INACTIVE'];
const partyKinds: readonly PartyKind[] = ['ORGANISATION', 'INDIVIDUAL'];
const partySorts = ['CODE', 'NAME', 'NEWEST', 'OLDEST'] as const;

type PartyForm = PartyCreate;

function blankAddress(): PartyAddressWrite {
  return {
    label: 'Registered office', address_type: 'REGISTERED', line_1: '', line_2: null,
    city: '', district: null, region: '', postal_code: '', country_code: 'IN', is_primary: true,
  };
}

function blankContact(): PartyContactWrite {
  return {
    name: '', job_title: null, department: null, email: null,
    phone: null, mobile: null, is_primary: true,
  };
}

function blankTax(): PartyTaxRegistrationWrite {
  return {
    registration_type: 'GSTIN', registration_number: '', country_code: 'IN',
    is_primary: true, valid_from: null, valid_to: null,
  };
}

function blankForm(): PartyForm {
  return {
    code: '', display_name: '', legal_name: '', party_kind: 'ORGANISATION', notes: null,
    status: 'DRAFT', roles: ['CUSTOMER'], addresses: [blankAddress()], contacts: [blankContact()],
    tax_registrations: [],
    commercial_terms: {
      currency_code: 'INR', payment_terms_days: 0, credit_limit: '0.00', credit_hold: false,
      incoterm_code: null, delivery_terms: null,
    },
  };
}

export default function MD_PARTY() {
  const initialRoute = useRef(readRegisterRouteParams('MD-PARTY')).current;
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const [workspace, setWorkspace] = useState<PartyWorkspace | null>(null);
  const [selected, setSelected] = useState<PartyDetail | null>(null);
  const [form, setForm] = useState<PartyForm>(blankForm);
  const initialSearch = readRouteString(initialRoute, 'q');
  const [searchDraft, setSearchDraft] = useState(initialSearch);
  const [search, setSearch] = useState(initialSearch);
  const [status, setStatus] = useState<PartyStatus | ''>(() => readRouteChoice(initialRoute, 'status', [...partyStatuses, ''] as const, ''));
  const [kind, setKind] = useState<PartyKind | ''>(() => readRouteChoice(initialRoute, 'kind', [...partyKinds, ''] as const, ''));
  const [role, setRole] = useState<PartyRole | ''>(() => readRouteChoice(initialRoute, 'role', [...roles, ''] as const, ''));
  const [country, setCountry] = useState(() => readRouteString(initialRoute, 'country'));
  const [sort, setSort] = useState<(typeof partySorts)[number]>(() => readRouteChoice(initialRoute, 'sort', partySorts, 'CODE'));
  const [page, setPage] = useState(() => readRoutePage(initialRoute));
  const [loading, setLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [targetStatus, setTargetStatus] = useState<PartyStatus>('ACTIVE');
  const [statusReason, setStatusReason] = useState('');
  const [pendingDelete, setPendingDelete] = useState<PartyDetail | null>(null);
  const commandKey = useRef<string | null>(null);
  const lifecycleKey = useRef<string | null>(null);
  const selectedId = useRef<string | null>(null);
  const mountedContextKey = useRef(contextKey);
  const handledCardRoute = useRef('');
  const registerRef = useRef<HTMLElement>(null);
  const editorRef = useRef<HTMLElement>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await listParties({
        page, q: search || undefined, status, party_kind: kind, role,
        country: country || undefined, sort,
      });
      setWorkspace(result);
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to load the company party register.'));
    } finally {
      setLoading(false);
    }
  }, [contextKey, page, search, status, kind, role, country, sort]);

  useEffect(() => {
    if (mountedContextKey.current === contextKey) return;
    mountedContextKey.current = contextKey;
    setWorkspace(null);
    setSelected(null);
    selectedId.current = null;
    setForm(blankForm());
    setPage(1);
    setSuccess(null);
  }, [contextKey]);

  useEffect(() => { void refresh(); }, [refresh]);

  const cardNavigation = useEditCardNavigation('MD-PARTY', {
    q: search || undefined,
    status: status || undefined,
    kind: kind || undefined,
    role: role || undefined,
    country: country || undefined,
    sort: sort === 'CODE' ? undefined : sort,
    page: page === 1 ? undefined : page,
  });

  useEffect(() => {
    const routeKey = `${contextKey}:${cardNavigation.route.mode}:${cardNavigation.route.recordId ?? ''}`;
    if (handledCardRoute.current === routeKey) return;
    const hadPriorRoute = handledCardRoute.current !== '';
    handledCardRoute.current = routeKey;

    if (cardNavigation.route.mode === 'edit') {
      if (selectedId.current !== cardNavigation.route.recordId) void choose(cardNavigation.route.recordId);
      window.requestAnimationFrame(() => revealEditCard(editorRef.current));
      return;
    }
    if (cardNavigation.route.mode === 'create') {
      startCreate();
      window.requestAnimationFrame(() => revealEditCard(editorRef.current));
      return;
    }

    selectedId.current = null;
    setSelected(null);
    setForm(blankForm());
    clearFeedback();
    if (hadPriorRoute && window.location.hash.startsWith('#MD-PARTY')) {
      window.requestAnimationFrame(() => revealRegister(registerRef.current));
    }
  }, [cardNavigation.route.mode, cardNavigation.route.recordId, contextKey]);

  async function choose(partyId: string) {
    selectedId.current = partyId;
    setDetailLoading(true);
    clearFeedback();
    try {
      const detail = await getParty(partyId);
      if (selectedId.current !== partyId) return;
      setSelected(detail);
      setForm(toForm(detail));
      setTargetStatus(detail.allowed_statuses[0] ?? 'ACTIVE');
      setStatusReason('');
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to load this party.'));
    } finally {
      setDetailLoading(false);
    }
  }

  function startCreate() {
    selectedId.current = null;
    setSelected(null);
    setForm(blankForm());
    setTargetStatus('ACTIVE');
    setStatusReason('');
    clearFeedback();
  }

  function change(patch: Partial<PartyForm>) {
    setForm((current) => ({ ...current, ...patch }));
    resetCommandFeedback();
  }

  function changeAddress(index: number, patch: Partial<PartyAddressWrite>) {
    setForm((current) => {
      const addresses = current.addresses.map((item, itemIndex) => itemIndex === index ? { ...item, ...patch } : item);
      if (patch.is_primary) {
        const type = patch.address_type ?? addresses[index].address_type;
        addresses.forEach((item, itemIndex) => {
          if (itemIndex !== index && item.address_type === type) addresses[itemIndex] = { ...item, is_primary: false };
        });
      }
      return { ...current, addresses };
    });
    resetCommandFeedback();
  }

  function changeContact(index: number, patch: Partial<PartyContactWrite>) {
    setForm((current) => ({
      ...current,
      contacts: current.contacts.map((item, itemIndex) => ({
        ...item,
        ...(itemIndex === index ? patch : patch.is_primary ? { is_primary: false } : {}),
      })),
    }));
    resetCommandFeedback();
  }

  function changeTax(index: number, patch: Partial<PartyTaxRegistrationWrite>) {
    setForm((current) => {
      const tax = current.tax_registrations.map((item, itemIndex) => itemIndex === index ? { ...item, ...patch } : item);
      if (patch.is_primary) {
        const type = patch.registration_type ?? tax[index].registration_type;
        tax.forEach((item, itemIndex) => {
          if (itemIndex !== index && item.registration_type === type) tax[itemIndex] = { ...item, is_primary: false };
        });
      }
      return { ...current, tax_registrations: tax };
    });
    resetCommandFeedback();
  }

  async function save(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setError(null);
    setSuccess(null);
    setFieldErrors({});
    commandKey.current ??= globalThis.crypto.randomUUID();
    const wasEditing = Boolean(selected);
    try {
      const body = writePayload(form);
      const result = selected
        ? await updateParty(selected, body, commandKey.current)
        : await createParty({ ...body, code: form.code.trim().toUpperCase(), status: form.status }, commandKey.current);
      commandKey.current = null;
      const message = selected
        ? `${selected.code} was saved with record version ${result.record_version}.`
        : `${form.code.trim().toUpperCase()} was created as ${result.status}.`;
      await refresh();
      await chooseAfterSave(result.id);
      if (!wasEditing) cardNavigation.replaceWithEditCard(result.id);
      setSuccess(message);
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to save this party.'));
      setFieldErrors(apiFields(caught));
    } finally {
      setBusy(false);
    }
  }

  async function submitLifecycle(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selected || busy) return;
    setBusy(true);
    setError(null);
    setSuccess(null);
    setFieldErrors({});
    lifecycleKey.current ??= globalThis.crypto.randomUUID();
    try {
      const result = await changePartyStatus(selected, targetStatus, statusReason.trim(), lifecycleKey.current);
      lifecycleKey.current = null;
      setStatusReason('');
      await refresh();
      await chooseAfterSave(selected.id);
      setSuccess(`${selected.code} moved to ${display(result.status)} at version ${result.record_version}.`);
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to change this party status.'));
      setFieldErrors(apiFields(caught));
    } finally {
      setBusy(false);
    }
  }

  async function confirmDelete() {
    if (!pendingDelete || busy) return;
    const target = pendingDelete;
    setBusy(true);
    setError(null);
    setFieldErrors({});
    try {
      await deleteParty(target, globalThis.crypto.randomUUID());
      setPendingDelete(null);
      selectedId.current = null;
      setSelected(null);
      setForm(blankForm());
      await refresh();
      cardNavigation.returnToRegister();
      setSuccess(`${target.code} was deleted.`);
    } catch (caught) {
      setPendingDelete(null);
      setError(apiMessage(caught, 'Unable to delete this party.'));
      setFieldErrors(apiFields(caught));
    } finally {
      setBusy(false);
    }
  }

  async function chooseAfterSave(partyId: string) {
    selectedId.current = partyId;
    const detail = await getParty(partyId);
    setSelected(detail);
    setForm(toForm(detail));
    setTargetStatus(detail.allowed_statuses[0] ?? 'ACTIVE');
  }

  function submitSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPage(1);
    setSearch(searchDraft.trim());
  }

  const canSave = cardNavigation.route.mode === 'create'
    ? workspace?.allowed_actions.includes('CREATE')
    : selected?.allowed_actions.includes('UPDATE') ?? false;

  return (
    <>
      <PageHeader
        code="MD-PARTY"
        batch="B05"
        title="Party Master"
        description="Company-scoped customers, suppliers, carriers, addresses, contacts, tax registrations, and commercial terms."
        onNew={workspace?.allowed_actions.includes('CREATE') ? cardNavigation.openCreateCard : undefined}
      />
      <div className="live-notice"><span></span><b>Controlled party aggregate</b> Codes are immutable; lifecycle, version, tax uniqueness, and operational dependencies are enforced by the ERP service.</div>

      <div className="kpi-grid party-kpis">
        <div className="kpi"><span>Parties</span><b>{loading && !workspace ? '-' : workspace?.summary.total ?? 0}</b><small>selected company</small></div>
        <div className="kpi"><span>Active</span><b>{loading && !workspace ? '-' : workspace?.summary.active ?? 0}</b><small>operational relationships</small></div>
        <div className="kpi"><span>On hold</span><b>{loading && !workspace ? '-' : workspace?.summary.on_hold ?? 0}</b><small>temporarily blocked</small></div>
        <div className="kpi"><span>Customers</span><b>{loading && !workspace ? '-' : workspace?.summary.customers ?? 0}</b><small>{workspace?.summary.suppliers ?? 0} suppliers</small></div>
      </div>

      <div className="module-grid admin-workspace party-workspace">
        <section className="panel" ref={registerRef}>
          <div className="panel-head"><div><h3>Party register</h3><span>{session.selected_context?.company_name}</span></div><button className="secondary compact-button" type="button" onClick={() => void refresh()} disabled={loading}>Refresh</button></div>
          <form className="party-toolbar" onSubmit={submitSearch}>
            <label>Search<span><input aria-label="Search parties" value={searchDraft} onChange={(event) => setSearchDraft(event.target.value)} placeholder="Code, name, contact, or tax ID" /><button className="secondary" type="submit">Search</button></span></label>
            <label>Status<select aria-label="Filter party status" value={status} onChange={(event) => { setStatus(event.target.value as PartyStatus | ''); setPage(1); }}><option value="">All statuses</option>{workspace?.lookups.statuses.map((item) => <option key={item}>{item}</option>)}</select></label>
            <label>Role<select aria-label="Filter party role" value={role} onChange={(event) => { setRole(event.target.value as PartyRole | ''); setPage(1); }}><option value="">All roles</option>{roles.map((item) => <option key={item}>{item}</option>)}</select></label>
            <label>Kind<select aria-label="Filter party kind" value={kind} onChange={(event) => { setKind(event.target.value as PartyKind | ''); setPage(1); }}><option value="">All kinds</option><option>ORGANISATION</option><option>INDIVIDUAL</option></select></label>
            <label>Country<input aria-label="Filter party country" maxLength={2} value={country} onChange={(event) => { setCountry(event.target.value.toUpperCase()); setPage(1); }} placeholder="IN" /></label>
            <label>Order<select aria-label="Sort parties" value={sort} onChange={(event) => { setSort(event.target.value as typeof sort); setPage(1); }}><option value="CODE">Code</option><option value="NAME">Name</option><option value="NEWEST">Newest</option><option value="OLDEST">Oldest</option></select></label>
          </form>
          {error && !workspace && <div className="form-error panel-message" role="alert"><span>{error}</span><button type="button" onClick={() => void refresh()}>Retry</button></div>}
          {loading && !workspace && <div className="empty-state">Loading party master...</div>}
          {!loading && workspace && !workspace.data.length && <div className="empty-state">No party matches the current filters.</div>}
          {workspace && Boolean(workspace.data.length) && <div className={loading ? 'is-refreshing' : undefined}>
            <ExpandableRegister
              ariaLabel="Party register"
              records={workspace.data}
              getRecordId={(party) => party.id}
              getRecordLabel={(party) => `${party.code} ${party.display_name}`}
              selectedId={selected?.id}
              columnsTemplate="minmax(190px,1.7fr) minmax(155px,1.2fr) minmax(175px,1.35fr) minmax(145px,1fr) minmax(100px,.7fr)"
              columns={[
                { key: 'party', heading: 'Party', render: (party) => <><b>{party.display_name}</b><small>{party.code} · {display(party.party_kind)}</small></> },
                { key: 'roles', heading: 'Roles', render: (party) => <>{party.roles.map(display).join(', ') || 'Unassigned'}<small>{party.tax_registration_count} tax registration(s)</small></> },
                { key: 'contact', heading: 'Primary contact', render: (party) => <>{party.primary_contact?.name ?? 'Not recorded'}<small>{party.primary_contact?.email ?? party.primary_contact?.phone ?? 'No channel'}</small></> },
                { key: 'location', heading: 'Location', render: (party) => <>{party.primary_address?.city ?? 'Not recorded'}<small>{party.primary_address ? `${party.primary_address.region}, ${party.primary_address.country_code}` : `${party.address_count} addresses`}</small></> },
                { key: 'status', heading: 'Status', render: (party) => <StatusBadge status={party.status} /> },
              ]}
              renderExpanded={(party) => <PartyRegisterDetail party={party} onOpen={() => cardNavigation.openEditCard(party.id)} />}
            />
          </div>}
          {workspace && <div className="pagination"><button type="button" disabled={page <= 1 || loading} onClick={() => setPage((value) => value - 1)}>Previous</button><span>Page {workspace.meta.current_page} of {workspace.meta.last_page} · {workspace.meta.total} records</span><button type="button" disabled={page >= workspace.meta.last_page || loading} onClick={() => setPage((value) => value + 1)}>Next</button></div>}
        </section>

        {cardNavigation.route.mode !== 'register' && <aside className="panel admin-editor party-editor edit-card" ref={editorRef}>
          <div className="panel-head"><div><h3 tabIndex={-1}>{selected ? `Edit ${selected.code}` : cardNavigation.route.mode === 'edit' ? 'Open party' : 'New party'}</h3><span>{detailLoading ? 'Loading detail...' : selected ? `Version ${selected.record_version}` : 'Company scoped'}</span></div><div className="edit-card-navigation">{selected && <StatusBadge status={selected.status} />}<button className="secondary compact-button" type="button" onClick={cardNavigation.returnToRegister}>Back to register</button></div></div>
          <form className="panel-body party-form" onSubmit={save} noValidate>
            {error && workspace && <div className="form-error" role="alert"><span>{error}</span></div>}
            {success && <div className="form-success" role="status"><span></span>{success}</div>}

            <fieldset className="party-form-fields" disabled={busy || detailLoading || !canSave}>
            <section className="party-subsection">
              <h4>Identity and roles</h4>
              <div className="party-card-grid">
                <label>Party code<input aria-label="Party code" value={form.code} readOnly={Boolean(selected)} className={selected ? 'read-only' : ''} onChange={(event) => change({ code: event.target.value.toUpperCase() })} placeholder="DIST-WEST" /><FieldError value={fieldErrors.code} /></label>
                <label>Kind<select aria-label="Party kind" value={form.party_kind} onChange={(event) => change({ party_kind: event.target.value as PartyKind })}><option>ORGANISATION</option><option>INDIVIDUAL</option></select></label>
                {!selected && <label>Initial status<select aria-label="Initial status" value={form.status} onChange={(event) => change({ status: event.target.value as 'DRAFT' | 'ACTIVE' })}><option>DRAFT</option><option>ACTIVE</option></select></label>}
                <label className="wide">Display name<input aria-label="Display name" value={form.display_name} onChange={(event) => change({ display_name: event.target.value })} /><FieldError value={fieldErrors.display_name} /></label>
                <label className="wide">Legal name<input aria-label="Legal name" value={form.legal_name} onChange={(event) => change({ legal_name: event.target.value })} /><FieldError value={fieldErrors.legal_name} /></label>
              </div>
              <div className="role-selector"><span>Party roles</span>{roles.map((item) => <label key={item}><input aria-label={`Role ${item}`} type="checkbox" checked={form.roles.includes(item)} onChange={(event) => change({ roles: event.target.checked ? [...form.roles, item] : form.roles.filter((roleCode) => roleCode !== item) })} />{display(item)}</label>)}</div>
              <FieldError value={fieldErrors.roles} />
              <label className="block-label">Internal notes<textarea aria-label="Internal notes" rows={3} maxLength={4000} value={form.notes ?? ''} onChange={(event) => change({ notes: event.target.value || null })} /><span className="field-hint">{form.notes?.length ?? 0}/4000 characters</span></label>
            </section>

            <section className="party-subsection">
              <div className="subsection-head"><h4>Addresses</h4><button className="secondary compact-button" type="button" onClick={() => change({ addresses: [...form.addresses, { ...blankAddress(), is_primary: false }] })}>Add address</button></div>
              <FieldError value={fieldErrors.addresses} />
              {!form.addresses.length && <div className="empty-state compact">No addresses recorded. Drafts may be saved, but activation requires one.</div>}
              {form.addresses.map((address, index) => <div className="party-repeat-card" key={address.id ?? `address-${index}`}>
                <div className="subsection-head"><b>Address {index + 1}</b><button type="button" onClick={() => change({ addresses: form.addresses.filter((_, itemIndex) => itemIndex !== index) })}>Remove</button></div>
                <div className="party-card-grid three">
                  <label>Label<input aria-label={`Address label ${index + 1}`} value={address.label} onChange={(event) => changeAddress(index, { label: event.target.value })} /></label>
                  <label>Type<select aria-label={`Address type ${index + 1}`} value={address.address_type} onChange={(event) => changeAddress(index, { address_type: event.target.value as PartyAddressType })}>{addressTypes.map((item) => <option key={item}>{item}</option>)}</select></label>
                  <label className="check-label"><input aria-label={`Primary address ${index + 1}`} type="checkbox" checked={address.is_primary} onChange={(event) => changeAddress(index, { is_primary: event.target.checked })} />Primary for type</label>
                  <label className="wide">Address line 1<input aria-label={`Address line 1 ${index + 1}`} value={address.line_1} onChange={(event) => changeAddress(index, { line_1: event.target.value })} /></label>
                  <label className="wide">Address line 2<input aria-label={`Address line 2 ${index + 1}`} value={address.line_2 ?? ''} onChange={(event) => changeAddress(index, { line_2: event.target.value || null })} /></label>
                  <label>City<input aria-label={`Address city ${index + 1}`} value={address.city} onChange={(event) => changeAddress(index, { city: event.target.value })} /></label>
                  <label>District<input aria-label={`Address district ${index + 1}`} value={address.district ?? ''} onChange={(event) => changeAddress(index, { district: event.target.value || null })} /></label>
                  <label>Region / state<input aria-label={`Address region ${index + 1}`} value={address.region} onChange={(event) => changeAddress(index, { region: event.target.value })} /></label>
                  <label>Postal code<input aria-label={`Address postal code ${index + 1}`} value={address.postal_code} onChange={(event) => changeAddress(index, { postal_code: event.target.value })} /></label>
                  <label>Country code<input aria-label={`Address country ${index + 1}`} maxLength={2} value={address.country_code} onChange={(event) => changeAddress(index, { country_code: event.target.value.toUpperCase() })} /></label>
                </div>
              </div>)}
            </section>

            <section className="party-subsection">
              <div className="subsection-head"><h4>Contacts</h4><button className="secondary compact-button" type="button" onClick={() => change({ contacts: [...form.contacts, { ...blankContact(), is_primary: false }] })}>Add contact</button></div>
              <FieldError value={fieldErrors.contacts} />
              {!form.contacts.length && <div className="empty-state compact">No contacts recorded. Activation requires a reachable contact.</div>}
              {form.contacts.map((contact, index) => <div className="party-repeat-card" key={contact.id ?? `contact-${index}`}>
                <div className="subsection-head"><b>Contact {index + 1}</b><button type="button" onClick={() => change({ contacts: form.contacts.filter((_, itemIndex) => itemIndex !== index) })}>Remove</button></div>
                <div className="party-card-grid three">
                  <label>Name<input aria-label={`Contact name ${index + 1}`} value={contact.name} onChange={(event) => changeContact(index, { name: event.target.value })} /></label>
                  <label>Job title<input aria-label={`Contact job title ${index + 1}`} value={contact.job_title ?? ''} onChange={(event) => changeContact(index, { job_title: event.target.value || null })} /></label>
                  <label>Department<input aria-label={`Contact department ${index + 1}`} value={contact.department ?? ''} onChange={(event) => changeContact(index, { department: event.target.value || null })} /></label>
                  <label>Email<input aria-label={`Contact email ${index + 1}`} type="email" value={contact.email ?? ''} onChange={(event) => changeContact(index, { email: event.target.value || null })} /></label>
                  <label>Phone<input aria-label={`Contact phone ${index + 1}`} value={contact.phone ?? ''} onChange={(event) => changeContact(index, { phone: event.target.value || null })} /></label>
                  <label>Mobile<input aria-label={`Contact mobile ${index + 1}`} value={contact.mobile ?? ''} onChange={(event) => changeContact(index, { mobile: event.target.value || null })} /></label>
                  <label className="check-label"><input aria-label={`Primary contact ${index + 1}`} type="checkbox" checked={contact.is_primary} onChange={(event) => changeContact(index, { is_primary: event.target.checked })} />Primary contact</label>
                </div>
              </div>)}
            </section>

            <section className="party-subsection">
              <div className="subsection-head"><h4>Tax registrations</h4><button className="secondary compact-button" type="button" onClick={() => change({ tax_registrations: [...form.tax_registrations, { ...blankTax(), is_primary: !form.tax_registrations.some((item) => item.registration_type === 'GSTIN' && item.is_primary) }] })}>Add registration</button></div>
              {form.tax_registrations.map((tax, index) => <div className="party-repeat-card" key={tax.id ?? `tax-${index}`}>
                <div className="subsection-head"><b>Registration {index + 1}</b><button type="button" onClick={() => change({ tax_registrations: form.tax_registrations.filter((_, itemIndex) => itemIndex !== index) })}>Remove</button></div>
                <div className="party-card-grid three">
                  <label>Type<select aria-label={`Tax type ${index + 1}`} value={tax.registration_type} onChange={(event) => changeTax(index, { registration_type: event.target.value as PartyTaxType })}>{taxTypes.map((item) => <option key={item}>{item}</option>)}</select></label>
                  <label>Registration number<input aria-label={`Tax number ${index + 1}`} value={tax.registration_number} onChange={(event) => changeTax(index, { registration_number: event.target.value.toUpperCase() })} /></label>
                  <label>Country code<input aria-label={`Tax country ${index + 1}`} maxLength={2} value={tax.country_code} onChange={(event) => changeTax(index, { country_code: event.target.value.toUpperCase() })} /></label>
                  <label>Valid from<input aria-label={`Tax valid from ${index + 1}`} type="date" value={tax.valid_from ?? ''} onChange={(event) => changeTax(index, { valid_from: event.target.value || null })} /></label>
                  <label>Valid to<input aria-label={`Tax valid to ${index + 1}`} type="date" value={tax.valid_to ?? ''} onChange={(event) => changeTax(index, { valid_to: event.target.value || null })} /></label>
                  <label className="check-label"><input aria-label={`Primary tax registration ${index + 1}`} type="checkbox" checked={tax.is_primary} onChange={(event) => changeTax(index, { is_primary: event.target.checked })} />Primary for type</label>
                </div>
                <FieldError value={fieldErrors[`tax_registrations.${index}.registration_number`]} />
              </div>)}
            </section>

            <section className="party-subsection">
              <h4>Commercial terms</h4>
              <div className="party-card-grid three">
                <label>Currency<input aria-label="Commercial currency" maxLength={3} value={form.commercial_terms.currency_code} onChange={(event) => change({ commercial_terms: { ...form.commercial_terms, currency_code: event.target.value.toUpperCase() } })} /></label>
                <label>Payment days<input aria-label="Payment terms days" type="number" min={0} max={3650} value={form.commercial_terms.payment_terms_days} onChange={(event) => change({ commercial_terms: { ...form.commercial_terms, payment_terms_days: Number(event.target.value) } })} /></label>
                <label>Credit limit<input aria-label="Credit limit" type="number" min={0} step="0.01" value={form.commercial_terms.credit_limit} onChange={(event) => change({ commercial_terms: { ...form.commercial_terms, credit_limit: event.target.value } })} /></label>
                <label>Incoterm<input aria-label="Incoterm" maxLength={10} value={form.commercial_terms.incoterm_code ?? ''} onChange={(event) => change({ commercial_terms: { ...form.commercial_terms, incoterm_code: event.target.value.toUpperCase() || null } })} /></label>
                <label className="check-label"><input aria-label="Credit hold" type="checkbox" checked={form.commercial_terms.credit_hold} onChange={(event) => change({ commercial_terms: { ...form.commercial_terms, credit_hold: event.target.checked } })} />Credit hold</label>
                <label className="wide">Delivery terms<textarea aria-label="Delivery terms" rows={2} maxLength={2000} value={form.commercial_terms.delivery_terms ?? ''} onChange={(event) => change({ commercial_terms: { ...form.commercial_terms, delivery_terms: event.target.value || null } })} /></label>
              </div>
            </section>
            </fieldset>
            <div className="form-actions">
              {selected && session.roles.includes('ERP_ADMIN') && <button className="danger-button" type="button" disabled={busy || detailLoading} onClick={() => { clearFeedback(); setPendingDelete(selected); }}>Delete entry</button>}
              <button className="secondary" type="button" disabled={busy} onClick={cardNavigation.returnToRegister}>Cancel</button>
              <button className="primary" type="submit" disabled={busy || detailLoading || !canSave}>{busy ? 'Saving...' : selected ? 'Save party' : 'Create party'}</button>
            </div>
          </form>

          {selected && selected.allowed_actions.includes('CHANGE_STATUS') && selected.allowed_statuses.length > 0 && <form className="party-lifecycle" onSubmit={submitLifecycle}>
            <div><h4>Lifecycle control</h4><small>{selected.status_change ? `${selected.status_change.reason ?? 'Status changed'} · ${formatDate(selected.status_change.changed_at)}` : 'No prior lifecycle transition recorded.'}</small></div>
            <label>Target status<select aria-label="Target party status" value={targetStatus} disabled={busy} onChange={(event) => { setTargetStatus(event.target.value as PartyStatus); lifecycleKey.current = null; }}>{selected.allowed_statuses.map((item) => <option key={item}>{item}</option>)}</select></label>
            <label>Reason<textarea aria-label="Party status reason" rows={2} minLength={3} maxLength={2000} value={statusReason} disabled={busy} onChange={(event) => { setStatusReason(event.target.value); lifecycleKey.current = null; }} /></label>
            <FieldError value={fieldErrors.target_status ?? fieldErrors.reason} />
            <button className="secondary" type="submit" disabled={busy || statusReason.trim().length < 3}>Apply status</button>
          </form>}
        </aside>}
      </div>
      {pendingDelete && <DeleteEntryDialog entryLabel={pendingDelete.display_name} entryCode={pendingDelete.code} busy={busy} onCancel={() => setPendingDelete(null)} onConfirm={() => void confirmDelete()} />}
    </>
  );

  function clearFeedback() {
    commandKey.current = null;
    lifecycleKey.current = null;
    setError(null);
    setSuccess(null);
    setFieldErrors({});
  }

  function resetCommandFeedback() {
    commandKey.current = null;
    setError(null);
    setSuccess(null);
    setFieldErrors({});
  }
}

function PartyRegisterDetail({ party, onOpen }: { party: PartyListItem; onOpen: () => void }) {
  const canUpdate = party.allowed_actions.includes('UPDATE');
  const lifecycle = party.status_change
    ? `${party.status_change.reason ?? 'Status changed'} · ${formatDate(party.status_change.changed_at)}${party.status_change.changed_by ? ` · ${party.status_change.changed_by.name}` : ''}`
    : 'No lifecycle transition recorded';
  const terms = party.commercial_terms
    ? `${party.commercial_terms.currency_code} ${formatMoney(party.commercial_terms.credit_limit)} credit · ${party.commercial_terms.payment_terms_days} day terms${party.commercial_terms.credit_hold ? ' · On credit hold' : ''}`
    : 'No commercial terms recorded';

  return (
    <>
      <div className="register-detail-heading">
        <div><span>{display(party.party_kind)}</span><h4>{party.display_name}</h4><p>{party.code} · {party.legal_name}</p></div>
        <StatusBadge status={party.status} />
      </div>
      <RegisterDetailGrid facts={[
        { label: 'Roles', value: party.roles.map(display).join(', ') || 'Unassigned' },
        { label: 'Commercial terms', value: terms },
        { label: 'Primary contact', value: party.primary_contact ? `${party.primary_contact.name} · ${party.primary_contact.email ?? party.primary_contact.phone ?? 'No channel'}` : 'Not recorded' },
        { label: 'Primary address', value: party.primary_address ? `${party.primary_address.city}, ${party.primary_address.region}, ${party.primary_address.country_code}` : 'Not recorded' },
        { label: 'Linked records', value: `${party.address_count} addresses · ${party.contact_count} contacts · ${party.tax_registration_count} tax registrations` },
        { label: 'Record version', value: `v${party.record_version}` },
        { label: 'Last updated', value: formatDate(party.updated_at) },
        { label: 'Lifecycle history', value: lifecycle, wide: true },
      ]} />
      <div className="register-detail-actions">
        <span>{canUpdate ? 'Editing is permitted for your role.' : 'Your role has read-only access to this party.'}</span>
        <button className="primary" type="button" onClick={onOpen}>{canUpdate ? 'Open edit card' : 'Open read-only card'}</button>
      </div>
    </>
  );
}

function toForm(party: PartyDetail): PartyForm {
  return {
    code: party.code,
    display_name: party.display_name,
    legal_name: party.legal_name,
    party_kind: party.party_kind,
    notes: party.notes,
    status: party.status === 'ACTIVE' ? 'ACTIVE' : 'DRAFT',
    roles: [...party.roles],
    addresses: party.addresses.map((item) => ({ ...item })),
    contacts: party.contacts.map((item) => ({ ...item })),
    tax_registrations: party.tax_registrations.map((item) => ({ ...item })),
    commercial_terms: party.commercial_terms ? {
      currency_code: party.commercial_terms.currency_code,
      payment_terms_days: party.commercial_terms.payment_terms_days,
      credit_limit: party.commercial_terms.credit_limit,
      credit_hold: party.commercial_terms.credit_hold,
      incoterm_code: party.commercial_terms.incoterm_code,
      delivery_terms: party.commercial_terms.delivery_terms,
    } : blankForm().commercial_terms,
  };
}

function writePayload(form: PartyForm): PartyWrite {
  return {
    display_name: form.display_name.trim(),
    legal_name: form.legal_name.trim(),
    party_kind: form.party_kind,
    notes: nullable(form.notes),
    roles: form.roles,
    addresses: form.addresses.map((item) => ({
      ...item,
      label: item.label.trim(), line_1: item.line_1.trim(), line_2: nullable(item.line_2),
      city: item.city.trim(), district: nullable(item.district), region: item.region.trim(),
      postal_code: item.postal_code.trim(), country_code: item.country_code.trim().toUpperCase(),
    })),
    contacts: form.contacts.map((item) => ({
      ...item,
      name: item.name.trim(), job_title: nullable(item.job_title), department: nullable(item.department),
      email: nullable(item.email)?.toLowerCase() ?? null, phone: nullable(item.phone), mobile: nullable(item.mobile),
    })),
    tax_registrations: form.tax_registrations.map((item) => ({
      ...item,
      registration_number: item.registration_number.trim().toUpperCase(),
      country_code: item.country_code.trim().toUpperCase(),
    })),
    commercial_terms: {
      ...form.commercial_terms,
      currency_code: form.commercial_terms.currency_code.trim().toUpperCase(),
      incoterm_code: nullable(form.commercial_terms.incoterm_code)?.toUpperCase() ?? null,
      delivery_terms: nullable(form.commercial_terms.delivery_terms),
    },
  };
}

function FieldError({ value }: { value?: string }) {
  return value ? <span className="field-error">{value}</span> : null;
}

function apiMessage(error: unknown, fallback: string) {
  return isApiError(error) ? error.message : fallback;
}

function apiFields(error: unknown): Record<string, string> {
  if (!isApiError(error) || !error.fields) return {};
  return Object.fromEntries(Object.entries(error.fields).map(([field, values]) => [field, values[0] ?? 'Invalid value.']));
}

function nullable(value: string | null | undefined): string | null {
  const trimmed = value?.trim() ?? '';
  return trimmed || null;
}

function display(value: string) {
  return value.replaceAll('_', ' ').toLowerCase().replace(/^./, (letter) => letter.toUpperCase());
}

function formatMoney(value?: string) {
  if (value === undefined) return '';
  const numeric = Number(value);
  return Number.isFinite(numeric) ? numeric.toLocaleString(undefined, { maximumFractionDigits: 2 }) : value;
}

function formatDate(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

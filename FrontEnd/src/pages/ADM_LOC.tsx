import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { isApiError } from '../api/client';
import {
  createLocation,
  deleteLocation,
  getLocation,
  listLocations,
  updateLocation,
  type LifecycleStatus,
  type LocationAdmin,
  type LocationType,
  type LocationWorkspace,
} from '../api/foundationAdmin';
import { useErpSession } from '../app/ErpSessionContext';
import {
  readRegisterRouteParams,
  readRouteChoice,
  readRouteString,
  revealEditCard,
  revealRegister,
  useEditCardNavigation,
} from '../app/useEditCardNavigation';
import { PageHeader } from '../components/PageHeader';
import { DeleteEntryDialog } from '../components/DeleteEntryDialog';
import { ExpandableRegister, RegisterDetailGrid } from '../components/ExpandableRegister';
import { StatusBadge } from '../components/StatusBadge';

type LocationForm = {
  code: string;
  name: string;
  description: string;
  location_type: LocationType;
  parent_location_id: string;
  status: LifecycleStatus;
};

const locationTypes: LocationType[] = [
  'WAREHOUSE', 'ZONE', 'BIN', 'RETURN_QUARANTINE', 'FINISHED_GOODS',
  'RAW_MATERIAL', 'QUALITY_HOLD', 'REPACK', 'REWORK', 'BLOCKED', 'OTHER',
];

function blankForm(): LocationForm {
  return { code: '', name: '', description: '', location_type: 'WAREHOUSE', parent_location_id: '', status: 'ACTIVE' };
}

export default function ADM_LOC() {
  const initialRoute = useRef(readRegisterRouteParams('ADM-LOC')).current;
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const [workspace, setWorkspace] = useState<LocationWorkspace | null>(null);
  const [selected, setSelected] = useState<LocationAdmin | null>(null);
  const [form, setForm] = useState<LocationForm>(blankForm);
  const initialSearch = readRouteString(initialRoute, 'q');
  const [searchDraft, setSearchDraft] = useState(initialSearch);
  const [search, setSearch] = useState(initialSearch);
  const [status, setStatus] = useState<LifecycleStatus | ''>(() => readRouteChoice(initialRoute, 'status', ['ACTIVE', 'INACTIVE', ''] as const, ''));
  const [type, setType] = useState<LocationType | ''>(() => readRouteChoice(initialRoute, 'type', [...locationTypes, ''] as const, ''));
  const [loading, setLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [pendingDelete, setPendingDelete] = useState<LocationAdmin | null>(null);
  const commandKey = useRef<string | null>(null);
  const selectedId = useRef<string | null>(null);
  const mountedContextKey = useRef(contextKey);
  const handledCardRoute = useRef('');
  const registerRef = useRef<HTMLElement>(null);
  const editorRef = useRef<HTMLElement>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await listLocations({ q: search || undefined, status: status || undefined, location_type: type || undefined });
      setWorkspace(result);
      if (selectedId.current) {
        const updated = result.data.find((location) => location.id === selectedId.current);
        if (updated) {
          setSelected(updated);
          setForm(toForm(updated));
        }
      }
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to load the selected plant locations.'));
    } finally {
      setLoading(false);
    }
  }, [contextKey, search, status, type]);

  useEffect(() => {
    if (mountedContextKey.current === contextKey) return;
    mountedContextKey.current = contextKey;
    setWorkspace(null);
    setSelected(null);
    selectedId.current = null;
    setForm(blankForm());
    setSuccess(null);
  }, [contextKey]);

  useEffect(() => { void refresh(); }, [refresh]);

  const cardNavigation = useEditCardNavigation('ADM-LOC', {
    q: search || undefined,
    status: status || undefined,
    type: type || undefined,
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
    if (hadPriorRoute && window.location.hash.startsWith('#ADM-LOC')) {
      window.requestAnimationFrame(() => revealRegister(registerRef.current));
    }
  }, [cardNavigation.route.mode, cardNavigation.route.recordId, contextKey]);

  async function choose(locationId: string) {
    selectedId.current = locationId;
    setDetailLoading(true);
    clearFeedback();
    try {
      const location = await getLocation(locationId);
      if (selectedId.current !== locationId) return;
      setSelected(location);
      setForm(toForm(location));
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to load this location.'));
    } finally {
      setDetailLoading(false);
    }
  }

  function startCreate() {
    selectedId.current = null;
    setSelected(null);
    setForm(blankForm());
    clearFeedback();
  }

  function change(patch: Partial<LocationForm>) {
    setForm((current) => ({ ...current, ...patch }));
    commandKey.current = null;
    setError(null);
    setSuccess(null);
    setFieldErrors({});
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    setError(null);
    setFieldErrors({});
    commandKey.current ??= globalThis.crypto.randomUUID();
    const body = {
      name: form.name.trim(),
      description: form.description.trim() || null,
      location_type: form.location_type,
      parent_location_id: form.parent_location_id || null,
      status: form.status,
    };
    const wasEditing = Boolean(selected);

    try {
      const result = selected
        ? await updateLocation(selected, body, commandKey.current)
        : await createLocation({ ...body, code: form.code.trim().toUpperCase() }, commandKey.current);
      const message = selected
        ? `${selected.code} was saved with a new record version.`
        : `${form.code.trim().toUpperCase()} was created in this plant.`;
      commandKey.current = null;
      await refresh();
      await choose(result.id);
      if (!wasEditing) cardNavigation.replaceWithEditCard(result.id);
      setSuccess(message);
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to save this location.'));
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
    try {
      await deleteLocation(target, globalThis.crypto.randomUUID());
      setPendingDelete(null);
      selectedId.current = null;
      setSelected(null);
      setForm(blankForm());
      await refresh();
      cardNavigation.returnToRegister();
      setSuccess(`${target.code} was deleted.`);
    } catch (caught) {
      setPendingDelete(null);
      setError(apiMessage(caught, 'Unable to delete this location.'));
      setFieldErrors(apiFields(caught));
    } finally {
      setBusy(false);
    }
  }

  function submitSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSearch(searchDraft.trim());
  }

  const canEdit = cardNavigation.route.mode === 'create'
    ? workspace?.allowed_actions.includes('CREATE') ?? false
    : selected?.allowed_actions.includes('UPDATE') ?? false;

  return (
    <>
      <PageHeader
        code="ADM-LOC"
        batch="B01"
        title="Plant Locations"
        description="A controlled warehouse, zone, bin, quarantine, and outcome-location hierarchy for the selected plant."
        onNew={workspace?.allowed_actions.includes('CREATE') ? cardNavigation.openCreateCard : undefined}
      />
      <div className="live-notice"><span></span><b>Plant-scoped hierarchy</b> Parent relationships, stock dependencies, and lifecycle rules are validated by the ERP service.</div>

      <div className="kpi-grid">
        <div className="kpi"><span>Locations</span><b>{loading && !workspace ? '-' : workspace?.summary.total ?? 0}</b><small>matching current filters</small></div>
        <div className="kpi"><span>Active</span><b>{loading && !workspace ? '-' : workspace?.summary.active ?? 0}</b><small>available for configuration</small></div>
        <div className="kpi"><span>Root locations</span><b>{loading && !workspace ? '-' : workspace?.summary.root_locations ?? 0}</b><small>top-level facilities</small></div>
        <div className="kpi"><span>With stock positions</span><b>{loading && !workspace ? '-' : workspace?.summary.with_stock_positions ?? 0}</b><small>type-protected records</small></div>
      </div>

      <div className="module-grid admin-workspace">
        <section className="panel" ref={registerRef}>
          <div className="panel-head"><div><h3>Location register</h3><span>{session.selected_context?.plant_name}</span></div><button className="secondary compact-button" type="button" onClick={() => void refresh()} disabled={loading}>Refresh</button></div>
          <form className="admin-toolbar" onSubmit={submitSearch}>
            <label>Search<span><input aria-label="Search locations" value={searchDraft} onChange={(event) => setSearchDraft(event.target.value)} placeholder="Code, name, or description" /><button className="secondary" type="submit">Search</button></span></label>
            <label>Status<select aria-label="Filter location status" value={status} onChange={(event) => setStatus(event.target.value as LifecycleStatus | '')}><option value="">All statuses</option><option>ACTIVE</option><option>INACTIVE</option></select></label>
            <label>Type<select aria-label="Filter location type" value={type} onChange={(event) => setType(event.target.value as LocationType | '')}><option value="">All types</option>{locationTypes.map((item) => <option key={item}>{item}</option>)}</select></label>
          </form>
          {error && !workspace && <div className="form-error panel-message" role="alert"><span>{error}</span><button type="button" onClick={() => void refresh()}>Retry</button></div>}
          {loading && !workspace && <div className="empty-state">Loading plant locations...</div>}
          {!loading && workspace && !workspace.data.length && <div className="empty-state">No location matches the current filters.</div>}
          {workspace && Boolean(workspace.data.length) && <div className={loading ? 'is-refreshing' : undefined}>
            <ExpandableRegister
              ariaLabel="Location register"
              records={workspace.data}
              getRecordId={(location) => location.id}
              getRecordLabel={(location) => `${location.code} ${location.name}`}
              selectedId={selected?.id}
              columnsTemplate="minmax(190px,1.7fr) minmax(135px,1fr) minmax(160px,1.25fr) minmax(100px,.75fr) minmax(130px,.9fr)"
              columns={[
                { key: 'location', heading: 'Location', render: (location) => <><b>{location.name}</b><small>{location.code}</small></> },
                { key: 'type', heading: 'Type', render: (location) => display(location.location_type) },
                { key: 'parent', heading: 'Parent', render: (location) => <>{location.parent?.code ?? 'Root'}<small>{location.parent?.name ?? 'Top level'}</small></> },
                { key: 'status', heading: 'Status', render: (location) => <StatusBadge status={location.status} /> },
                { key: 'stock', heading: 'Stock links', render: (location) => <>{location.position_count} positions<small>{location.child_count} child locations</small></> },
              ]}
              renderExpanded={(location) => <LocationRegisterDetail location={location} onOpen={() => cardNavigation.openEditCard(location.id)} />}
            />
          </div>}
        </section>

        {cardNavigation.route.mode !== 'register' && <aside className="panel admin-editor edit-card" ref={editorRef}>
          <div className="panel-head"><div><h3 tabIndex={-1}>{selected ? `Edit ${selected.code}` : cardNavigation.route.mode === 'edit' ? 'Open location' : 'New location'}</h3><span>{detailLoading ? 'Loading detail...' : selected ? `v${selected.record_version}` : 'Plant scoped'}</span></div><div className="edit-card-navigation">{selected && <StatusBadge status={selected.status} />}<button className="secondary compact-button" type="button" onClick={cardNavigation.returnToRegister}>Back to register</button></div></div>
          <form className="panel-body form-grid admin-form" onSubmit={submit} noValidate>
            {error && workspace && <div className="form-error full" role="alert"><span>{error}</span></div>}
            {success && <div className="form-success full" role="status"><span></span>{success}</div>}
            <fieldset className="admin-form-fields" disabled={busy || detailLoading || !canEdit}>
            <label>Location code<input value={form.code} readOnly={Boolean(selected)} className={selected ? 'read-only' : ''} onChange={(event) => change({ code: event.target.value.toUpperCase() })} placeholder="WH-01" /><FieldError value={fieldErrors.code} /></label>
            <label>Lifecycle status<select value={form.status} onChange={(event) => change({ status: event.target.value as LifecycleStatus })}><option>ACTIVE</option><option>INACTIVE</option></select><FieldError value={fieldErrors.status} /></label>
            <label className="full">Name<input value={form.name} onChange={(event) => change({ name: event.target.value })} /><FieldError value={fieldErrors.name} /></label>
            <label>Location type<select value={form.location_type} onChange={(event) => change({ location_type: event.target.value as LocationType })}>{locationTypes.map((item) => <option key={item}>{item}</option>)}</select><FieldError value={fieldErrors.location_type} /></label>
            <label>Parent location<select value={form.parent_location_id} onChange={(event) => change({ parent_location_id: event.target.value })}><option value="">Root / no parent</option>{workspace?.lookups.parents.filter((parent) => parent.id !== selected?.id).map((parent) => <option key={parent.id} value={parent.id}>{parent.code} - {parent.name}</option>)}</select><FieldError value={fieldErrors.parent_location_id} /></label>
            <label className="full">Description<textarea rows={3} maxLength={2000} value={form.description} onChange={(event) => change({ description: event.target.value })} /><span className="field-hint">{form.description.length}/2000 characters</span><FieldError value={fieldErrors.description} /></label>
            {selected && selected.position_count > 0 && <div className="callout full">This location has {selected.position_count} stock position(s). Its type is locked, and deactivation requires zero on-hand stock.</div>}
            </fieldset>
            <div className="form-actions full">
              {selected && session.roles.includes('ERP_ADMIN') && <button className="danger-button" type="button" disabled={busy} onClick={() => { clearFeedback(); setPendingDelete(selected); }}>Delete entry</button>}
              <button className="secondary" type="button" disabled={busy} onClick={cardNavigation.returnToRegister}>Cancel</button>
              <button className="primary" type="submit" disabled={busy || detailLoading || !canEdit}>{busy ? 'Saving...' : selected ? 'Save changes' : 'Create location'}</button>
            </div>
          </form>
        </aside>}
      </div>
      {pendingDelete && <DeleteEntryDialog entryLabel={pendingDelete.name} entryCode={pendingDelete.code} busy={busy} onCancel={() => setPendingDelete(null)} onConfirm={() => void confirmDelete()} />}
    </>
  );

  function clearFeedback() {
    commandKey.current = null;
    setError(null);
    setSuccess(null);
    setFieldErrors({});
  }
}

function LocationRegisterDetail({ location, onOpen }: { location: LocationAdmin; onOpen: () => void }) {
  const canUpdate = location.allowed_actions.includes('UPDATE');
  return (
    <>
      <div className="register-detail-heading">
        <div><span>{display(location.location_type)}</span><h4>{location.name}</h4><p>{location.code} · {location.parent ? `under ${location.parent.code}` : 'root location'}</p></div>
        <StatusBadge status={location.status} />
      </div>
      <RegisterDetailGrid facts={[
        { label: 'Parent location', value: location.parent ? `${location.parent.code} · ${location.parent.name}` : 'Root / no parent' },
        { label: 'Child locations', value: location.child_count },
        { label: 'Stock positions', value: location.position_count },
        { label: 'Quantity on hand', value: formatQuantity(location.quantity_on_hand) },
        { label: 'Record version', value: `v${location.record_version}` },
        { label: 'Last updated', value: formatDate(location.updated_at) },
        { label: 'Description', value: location.description || 'No description recorded.', wide: true },
        { label: 'Dependency control', value: location.position_count > 0 ? 'Location type is locked; deactivation requires zero on-hand stock.' : 'No stock-position dependency currently blocks structural changes.', wide: true },
      ]} />
      <div className="register-detail-actions">
        <span>{canUpdate ? 'Editing is permitted for your role.' : 'Your role has read-only access to this location.'}</span>
        <button className="primary" type="button" onClick={onOpen}>{canUpdate ? 'Open edit card' : 'Open read-only card'}</button>
      </div>
    </>
  );
}

function toForm(location: LocationAdmin): LocationForm {
  return {
    code: location.code,
    name: location.name,
    description: location.description ?? '',
    location_type: location.location_type,
    parent_location_id: location.parent?.id ?? '',
    status: location.status,
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

function display(value: string) {
  return value.replaceAll('_', ' ').toLowerCase().replace(/^./, (letter) => letter.toUpperCase());
}

function formatQuantity(value: string) {
  const numeric = Number(value);
  return Number.isFinite(numeric) ? numeric.toLocaleString(undefined, { maximumFractionDigits: 6 }) : value;
}

function formatDate(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(value));
}

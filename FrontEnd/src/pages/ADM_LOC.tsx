import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { isApiError } from '../api/client';
import {
  createLocation,
  deleteLocation,
  listLocations,
  updateLocation,
  type LifecycleStatus,
  type LocationAdmin,
  type LocationType,
  type LocationWorkspace,
} from '../api/foundationAdmin';
import { useErpSession } from '../app/ErpSessionContext';
import { PageHeader } from '../components/PageHeader';
import { DeleteEntryDialog } from '../components/DeleteEntryDialog';
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
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const [workspace, setWorkspace] = useState<LocationWorkspace | null>(null);
  const [selected, setSelected] = useState<LocationAdmin | null>(null);
  const [form, setForm] = useState<LocationForm>(blankForm);
  const [searchDraft, setSearchDraft] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [type, setType] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [pendingDelete, setPendingDelete] = useState<LocationAdmin | null>(null);
  const commandKey = useRef<string | null>(null);
  const selectedId = useRef<string | null>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = await listLocations({ q: search || undefined, status: status || undefined, location_type: type || undefined });
      setWorkspace(result);
      if (selectedId.current) {
        const updated = result.data.find((location) => location.id === selectedId.current) ?? null;
        setSelected(updated);
        if (updated) setForm(toForm(updated));
      }
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to load the selected plant locations.'));
    } finally {
      setLoading(false);
    }
  }, [contextKey, search, status, type]);

  useEffect(() => {
    setWorkspace(null);
    setSelected(null);
    selectedId.current = null;
    setForm(blankForm());
    setSuccess(null);
  }, [contextKey]);

  useEffect(() => { void refresh(); }, [refresh]);

  function choose(location: LocationAdmin) {
    selectedId.current = location.id;
    setSelected(location);
    setForm(toForm(location));
    clearFeedback();
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

    try {
      if (selected) {
        await updateLocation(selected, body, commandKey.current);
        setSuccess(`${selected.code} was saved with a new record version.`);
      } else {
        await createLocation({ ...body, code: form.code.trim().toUpperCase() }, commandKey.current);
        setSuccess(`${form.code.trim().toUpperCase()} was created in this plant.`);
        setForm(blankForm());
      }
      commandKey.current = null;
      await refresh();
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

  return (
    <>
      <PageHeader
        code="ADM-LOC"
        batch="B01"
        title="Plant Locations"
        description="A controlled warehouse, zone, bin, quarantine, and outcome-location hierarchy for the selected plant."
        onNew={workspace?.allowed_actions.includes('CREATE') ? startCreate : undefined}
      />
      <div className="live-notice"><span></span><b>Plant-scoped hierarchy</b> Parent relationships, stock dependencies, and lifecycle rules are validated by the ERP service.</div>

      <div className="kpi-grid">
        <div className="kpi"><span>Locations</span><b>{loading && !workspace ? '-' : workspace?.summary.total ?? 0}</b><small>matching current filters</small></div>
        <div className="kpi"><span>Active</span><b>{loading && !workspace ? '-' : workspace?.summary.active ?? 0}</b><small>available for configuration</small></div>
        <div className="kpi"><span>Root locations</span><b>{loading && !workspace ? '-' : workspace?.summary.root_locations ?? 0}</b><small>top-level facilities</small></div>
        <div className="kpi"><span>With stock positions</span><b>{loading && !workspace ? '-' : workspace?.summary.with_stock_positions ?? 0}</b><small>type-protected records</small></div>
      </div>

      <div className="module-grid admin-workspace">
        <section className="panel">
          <div className="panel-head"><div><h3>Location register</h3><span>{session.selected_context?.plant_name}</span></div><button className="secondary compact-button" type="button" onClick={() => void refresh()} disabled={loading}>Refresh</button></div>
          <form className="admin-toolbar" onSubmit={submitSearch}>
            <label>Search<span><input aria-label="Search locations" value={searchDraft} onChange={(event) => setSearchDraft(event.target.value)} placeholder="Code, name, or description" /><button className="secondary" type="submit">Search</button></span></label>
            <label>Status<select aria-label="Filter location status" value={status} onChange={(event) => setStatus(event.target.value)}><option value="">All statuses</option><option>ACTIVE</option><option>INACTIVE</option></select></label>
            <label>Type<select aria-label="Filter location type" value={type} onChange={(event) => setType(event.target.value)}><option value="">All types</option>{locationTypes.map((item) => <option key={item}>{item}</option>)}</select></label>
          </form>
          {error && !workspace && <div className="form-error panel-message" role="alert"><span>{error}</span><button type="button" onClick={() => void refresh()}>Retry</button></div>}
          {loading && !workspace && <div className="empty-state">Loading plant locations...</div>}
          {!loading && workspace && !workspace.data.length && <div className="empty-state">No location matches the current filters.</div>}
          {workspace && Boolean(workspace.data.length) && <div className={`table-wrap ${loading ? 'is-refreshing' : ''}`}>
            <table className="admin-table"><thead><tr><th>Location</th><th>Type</th><th>Parent</th><th>Status</th><th>Positions</th><th>On hand</th><th>Version</th><th>Action</th></tr></thead><tbody>
              {workspace.data.map((location) => <tr key={location.id}>
                <td><b>{location.name}</b><small>{location.code}</small></td>
                <td>{display(location.location_type)}</td>
                <td>{location.parent?.code ?? 'Root'}<small>{location.parent?.name ?? 'Top level'}</small></td>
                <td><StatusBadge status={location.status} /></td>
                <td>{location.position_count}<small>{location.child_count} children</small></td>
                <td>{formatQuantity(location.quantity_on_hand)}</td>
                <td>v{location.record_version}</td>
                <td><button className="secondary compact-button" type="button" onClick={() => choose(location)}>Open</button></td>
              </tr>)}
            </tbody></table>
          </div>}
        </section>

        <aside className="panel admin-editor">
          <div className="panel-head"><h3>{selected ? `Edit ${selected.code}` : 'New location'}</h3><span>{selected ? `v${selected.record_version}` : 'Plant scoped'}</span></div>
          <form className="panel-body form-grid admin-form" onSubmit={submit} noValidate>
            {error && workspace && <div className="form-error full" role="alert"><span>{error}</span></div>}
            {success && <div className="form-success full" role="status"><span></span>{success}</div>}
            <label>Location code<input value={form.code} readOnly={Boolean(selected)} className={selected ? 'read-only' : ''} onChange={(event) => change({ code: event.target.value.toUpperCase() })} placeholder="WH-01" /><FieldError value={fieldErrors.code} /></label>
            <label>Lifecycle status<select value={form.status} onChange={(event) => change({ status: event.target.value as LifecycleStatus })}><option>ACTIVE</option><option>INACTIVE</option></select><FieldError value={fieldErrors.status} /></label>
            <label className="full">Name<input value={form.name} onChange={(event) => change({ name: event.target.value })} /><FieldError value={fieldErrors.name} /></label>
            <label>Location type<select value={form.location_type} onChange={(event) => change({ location_type: event.target.value as LocationType })}>{locationTypes.map((item) => <option key={item}>{item}</option>)}</select><FieldError value={fieldErrors.location_type} /></label>
            <label>Parent location<select value={form.parent_location_id} onChange={(event) => change({ parent_location_id: event.target.value })}><option value="">Root / no parent</option>{workspace?.lookups.parents.filter((parent) => parent.id !== selected?.id).map((parent) => <option key={parent.id} value={parent.id}>{parent.code} - {parent.name}</option>)}</select><FieldError value={fieldErrors.parent_location_id} /></label>
            <label className="full">Description<textarea rows={3} maxLength={2000} value={form.description} onChange={(event) => change({ description: event.target.value })} /><span className="field-hint">{form.description.length}/2000 characters</span><FieldError value={fieldErrors.description} /></label>
            {selected && selected.position_count > 0 && <div className="callout full">This location has {selected.position_count} stock position(s). Its type is locked, and deactivation requires zero on-hand stock.</div>}
            <div className="form-actions full">
              {selected && session.roles.includes('ERP_ADMIN') && <button className="danger-button" type="button" disabled={busy} onClick={() => { clearFeedback(); setPendingDelete(selected); }}>Delete entry</button>}
              <button className="primary" type="submit" disabled={busy || (selected ? !selected.allowed_actions.includes('UPDATE') : !workspace?.allowed_actions.includes('CREATE'))}>{busy ? 'Saving...' : selected ? 'Save changes' : 'Create location'}</button>
            </div>
          </form>
        </aside>
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

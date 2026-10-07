import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { isApiError } from '../api/client';
import {
  createCompany,
  createPlant,
  getOrganisation,
  updateCompany,
  updatePlant,
  type OrganisationWorkspace,
  type PlantAdmin,
} from '../api/foundationAdmin';
import { useErpSession } from '../app/ErpSessionContext';
import { PageHeader } from '../components/PageHeader';
import { StatusBadge } from '../components/StatusBadge';

type Editor = 'company' | 'new-company' | 'new-plant' | 'plant';

type PlantForm = Pick<PlantAdmin, 'code' | 'name' | 'timezone' | 'status'>;

const emptyPlant: PlantForm = { code: '', name: '', timezone: 'Asia/Kolkata', status: 'ACTIVE' };
const emptyCompany = {
  code: '', legal_name: '', display_name: '', plant_code: '', plant_name: '', timezone: 'Asia/Kolkata',
};

export default function ADM_ORG() {
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const [workspace, setWorkspace] = useState<OrganisationWorkspace['data'] | null>(null);
  const [editor, setEditor] = useState<Editor>('company');
  const [selectedPlantId, setSelectedPlantId] = useState<string | null>(null);
  const [companyForm, setCompanyForm] = useState({ legal_name: '', display_name: '', status: 'ACTIVE' as 'DRAFT' | 'ACTIVE' | 'INACTIVE' });
  const [plantForm, setPlantForm] = useState<PlantForm>({ ...emptyPlant });
  const [newCompanyForm, setNewCompanyForm] = useState({ ...emptyCompany });
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const commandKey = useRef<string | null>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const result = (await getOrganisation()).data;
      setWorkspace(result);
      setCompanyForm({
        legal_name: result.company.legal_name,
        display_name: result.company.display_name,
        status: result.company.status,
      });
      if (selectedPlantId) {
        const selected = result.plants.find((plant) => plant.id === selectedPlantId);
        if (selected) setPlantForm(toPlantForm(selected));
      }
    } catch (caught) {
      setError(message(caught, 'Unable to load organisation administration.'));
    } finally {
      setLoading(false);
    }
  }, [contextKey, selectedPlantId]);

  useEffect(() => {
    setWorkspace(null);
    setEditor('company');
    setSelectedPlantId(null);
    setSuccess(null);
    void refresh();
  }, [contextKey]); // eslint-disable-line react-hooks/exhaustive-deps

  function editPlant(plant: PlantAdmin) {
    setSelectedPlantId(plant.id);
    setPlantForm(toPlantForm(plant));
    setEditor('plant');
    clearFeedback();
  }

  function change(mutator: () => void) {
    mutator();
    commandKey.current = null;
    setFieldErrors({});
    setError(null);
    setSuccess(null);
  }

  async function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!workspace || busy) return;
    setBusy(true);
    setError(null);
    setFieldErrors({});
    commandKey.current ??= globalThis.crypto.randomUUID();

    try {
      if (editor === 'company') {
        await updateCompany(workspace.company, companyForm, commandKey.current);
        setSuccess('Organisation details were saved with a new record version.');
      } else if (editor === 'new-company') {
        await createCompany({
          ...newCompanyForm,
          code: newCompanyForm.code.toUpperCase(),
          plant_code: newCompanyForm.plant_code.toUpperCase(),
        }, commandKey.current);
        setSuccess('Organisation, first plant, and your administrator assignment were created.');
        setNewCompanyForm({ ...emptyCompany });
        window.dispatchEvent(new Event('erp:session-refresh'));
      } else if (editor === 'new-plant') {
        await createPlant({ ...plantForm, code: plantForm.code.toUpperCase() }, commandKey.current);
        setSuccess('Plant created and added to your authorised contexts.');
        setPlantForm({ ...emptyPlant });
        window.dispatchEvent(new Event('erp:session-refresh'));
      } else {
        const plant = workspace.plants.find((item) => item.id === selectedPlantId);
        if (!plant) throw new Error('Selected plant is no longer available.');
        await updatePlant(plant, {
          name: plantForm.name,
          timezone: plantForm.timezone,
          status: plantForm.status,
        }, commandKey.current);
        setSuccess(`${plant.code} was saved with a new record version.`);
      }
      commandKey.current = null;
      await refresh();
    } catch (caught) {
      setError(message(caught, 'Unable to save the organisation change.'));
      setFieldErrors(apiFields(caught));
    } finally {
      setBusy(false);
    }
  }

  const canCreate = workspace?.allowed_actions.includes('CREATE_PLANT') ?? false;
  const selectedPlant = workspace?.plants.find((plant) => plant.id === selectedPlantId) ?? null;
  const canSave = editor === 'company'
    ? workspace?.company.allowed_actions.includes('UPDATE') ?? false
    : editor === 'plant'
      ? selectedPlant?.allowed_actions.includes('UPDATE') ?? false
      : workspace?.allowed_actions.includes(editor === 'new-company' ? 'CREATE_COMPANY' : 'CREATE_PLANT') ?? false;

  return (
    <>
      <PageHeader
        code="ADM-ORG"
        batch="B01"
        title="Organisation & Plants"
        description="Versioned organisation and plant records that define the operating contexts available across the ERP."
        onNew={canCreate ? () => {
          setEditor('new-plant'); setSelectedPlantId(null); setPlantForm({ ...emptyPlant }); clearFeedback();
        } : undefined}
      />
      <div className="live-notice"><span></span><b>Live foundation data</b> Changes are scoped, audited, idempotent, and published through the transactional outbox.</div>

      <div className="kpi-grid">
        <div className="kpi"><span>Plants</span><b>{loading && !workspace ? '-' : workspace?.summary.plant_count ?? 0}</b><small>in this organisation</small></div>
        <div className="kpi"><span>Active plants</span><b>{loading && !workspace ? '-' : workspace?.summary.active_plants ?? 0}</b><small>selectable contexts</small></div>
        <div className="kpi"><span>Locations</span><b>{loading && !workspace ? '-' : workspace?.summary.location_count ?? 0}</b><small>across all plants</small></div>
        <div className="kpi"><span>Assigned users</span><b>{loading && !workspace ? '-' : workspace?.summary.active_users ?? 0}</b><small>distinct enabled assignments</small></div>
      </div>

      {error && !workspace && <div className="form-error" role="alert"><span>{error}</span><button type="button" onClick={() => void refresh()}>Retry</button></div>}
      {loading && !workspace && <section className="panel empty-state">Loading organisation and plant records...</section>}

      {workspace && (
        <div className="module-grid admin-workspace organisation-workspace" data-testid="organisation-workspace">
          <section className="panel">
            <div className="panel-head">
              <div><h3>{workspace.company.display_name}</h3><span>{workspace.company.code} · version {workspace.company.record_version}</span></div>
              <div className="admin-inline-actions">
                <button className="secondary compact-button" type="button" onClick={() => { setEditor('company'); clearFeedback(); }}>Organisation</button>
                {workspace.allowed_actions.includes('CREATE_COMPANY') && <button className="secondary compact-button" type="button" onClick={() => { setEditor('new-company'); setNewCompanyForm({ ...emptyCompany }); clearFeedback(); }}>New organisation</button>}
                <button className="secondary compact-button" type="button" onClick={() => void refresh()} disabled={loading}>Refresh</button>
              </div>
            </div>
            <div className={`table-wrap ${loading ? 'is-refreshing' : ''}`}>
              <table className="admin-table">
                <thead><tr><th>Plant</th><th>Timezone</th><th>Status</th><th>Locations</th><th>Users</th><th>Version</th><th>Action</th></tr></thead>
                <tbody>
                  {workspace.plants.map((plant) => (
                    <tr key={plant.id}>
                      <td><b>{plant.name}</b><small>{plant.code}</small></td>
                      <td>{plant.timezone}</td>
                      <td><StatusBadge status={plant.status} /></td>
                      <td>{plant.location_count}</td>
                      <td>{plant.active_user_count}</td>
                      <td>v{plant.record_version}</td>
                      <td><button className="secondary compact-button" type="button" onClick={() => editPlant(plant)}>Open</button></td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>

          <aside className="panel admin-editor organisation-editor">
            <div className="panel-head"><h3>{editorTitle(editor, selectedPlant)}</h3><span>{editor === 'plant' ? `Version ${selectedPlant?.record_version ?? '-'}` : 'Controlled change'}</span></div>
            <form className="panel-body form-grid admin-form" onSubmit={submit} noValidate>
              {error && <div className="form-error full" role="alert"><span>{error}</span></div>}
              {success && <div className="form-success full" role="status"><span></span>{success}</div>}

              {editor === 'company' && <>
                <ReadOnly label="Organisation code" value={workspace.company.code} />
                <label>Lifecycle status<select value={companyForm.status} onChange={(event) => change(() => setCompanyForm({ ...companyForm, status: event.target.value as typeof companyForm.status }))}><option>DRAFT</option><option>ACTIVE</option><option>INACTIVE</option></select><FieldError value={fieldErrors.status} /></label>
                <label className="full">Legal name<input value={companyForm.legal_name} onChange={(event) => change(() => setCompanyForm({ ...companyForm, legal_name: event.target.value }))} /><FieldError value={fieldErrors.legal_name} /></label>
                <label className="full">Display name<input value={companyForm.display_name} onChange={(event) => change(() => setCompanyForm({ ...companyForm, display_name: event.target.value }))} /><FieldError value={fieldErrors.display_name} /></label>
              </>}

              {(editor === 'new-plant' || editor === 'plant') && <>
                {editor === 'new-plant'
                  ? <label>Plant code<input value={plantForm.code} onChange={(event) => change(() => setPlantForm({ ...plantForm, code: event.target.value.toUpperCase() }))} placeholder="PLANT-01" /><FieldError value={fieldErrors.code} /></label>
                  : <ReadOnly label="Plant code" value={selectedPlant?.code ?? ''} />}
                <label>Lifecycle status<select value={plantForm.status} onChange={(event) => change(() => setPlantForm({ ...plantForm, status: event.target.value as typeof plantForm.status }))}><option>DRAFT</option><option>ACTIVE</option><option>INACTIVE</option></select><FieldError value={fieldErrors.status} /></label>
                <label className="full">Plant name<input value={plantForm.name} onChange={(event) => change(() => setPlantForm({ ...plantForm, name: event.target.value }))} /><FieldError value={fieldErrors.name} /></label>
                <label className="full">IANA timezone<input value={plantForm.timezone} onChange={(event) => change(() => setPlantForm({ ...plantForm, timezone: event.target.value }))} list="admin-timezones" /><FieldError value={fieldErrors.timezone} /></label>
              </>}

              {editor === 'new-company' && <>
                <label>Organisation code<input value={newCompanyForm.code} onChange={(event) => change(() => setNewCompanyForm({ ...newCompanyForm, code: event.target.value.toUpperCase() }))} /><FieldError value={fieldErrors.code} /></label>
                <label>First plant code<input value={newCompanyForm.plant_code} onChange={(event) => change(() => setNewCompanyForm({ ...newCompanyForm, plant_code: event.target.value.toUpperCase() }))} /><FieldError value={fieldErrors.plant_code} /></label>
                <label className="full">Legal name<input value={newCompanyForm.legal_name} onChange={(event) => change(() => setNewCompanyForm({ ...newCompanyForm, legal_name: event.target.value }))} /><FieldError value={fieldErrors.legal_name} /></label>
                <label className="full">Display name<input value={newCompanyForm.display_name} onChange={(event) => change(() => setNewCompanyForm({ ...newCompanyForm, display_name: event.target.value }))} /><FieldError value={fieldErrors.display_name} /></label>
                <label className="full">First plant name<input value={newCompanyForm.plant_name} onChange={(event) => change(() => setNewCompanyForm({ ...newCompanyForm, plant_name: event.target.value }))} /><FieldError value={fieldErrors.plant_name} /></label>
                <label className="full">IANA timezone<input value={newCompanyForm.timezone} onChange={(event) => change(() => setNewCompanyForm({ ...newCompanyForm, timezone: event.target.value }))} list="admin-timezones" /><FieldError value={fieldErrors.timezone} /></label>
                <div className="callout full">The first plant and your ERP Administrator assignment are created atomically, so the new context is immediately usable.</div>
              </>}

              <datalist id="admin-timezones"><option value="Asia/Kolkata" /><option value="UTC" /><option value="Asia/Dubai" /><option value="Europe/London" /></datalist>
              {canSave && <div className="form-actions full"><button className="primary" type="submit" disabled={busy}>{busy ? 'Saving...' : editor.startsWith('new-') ? 'Create' : 'Save changes'}</button></div>}
            </form>
          </aside>
        </div>
      )}
    </>
  );

  function clearFeedback() {
    commandKey.current = null;
    setError(null);
    setSuccess(null);
    setFieldErrors({});
  }
}

function ReadOnly({ label, value }: { label: string; value: string }) {
  return <label>{label}<input value={value} readOnly className="read-only" /></label>;
}

function FieldError({ value }: { value?: string }) {
  return value ? <span className="field-error">{value}</span> : null;
}

function toPlantForm(plant: PlantAdmin) {
  return { code: plant.code, name: plant.name, timezone: plant.timezone, status: plant.status };
}

function editorTitle(editor: Editor, plant: PlantAdmin | null): string {
  if (editor === 'company') return 'Organisation record';
  if (editor === 'new-company') return 'New organisation';
  if (editor === 'new-plant') return 'New plant';
  return plant ? `Edit ${plant.code}` : 'Plant record';
}

function message(error: unknown, fallback: string): string {
  return isApiError(error) ? error.message : fallback;
}

function apiFields(error: unknown): Record<string, string> {
  if (!isApiError(error) || !error.fields) return {};
  return Object.fromEntries(Object.entries(error.fields).map(([field, values]) => [field, values[0] ?? 'Invalid value.']));
}

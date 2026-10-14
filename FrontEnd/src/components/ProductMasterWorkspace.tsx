import {
  useCallback,
  useEffect,
  useRef,
  useState,
  type Dispatch,
  type FormEvent,
  type SetStateAction,
} from 'react';
import { isApiError } from '../api/client';
import {
  changeProductMasterStatus,
  createProductMaster,
  deleteProductMaster,
  getProductMaster,
  listProductMasters,
  updateProductMaster,
  type BrandAgreement,
  type ProductMasterDetail,
  type ProductMasterLookups,
  type ProductMasterResource,
  type ProductMasterStatus,
  type ProductMasterWorkspace as ProductMasterWorkspaceData,
  type RecipeComponent,
  type RouteOperation,
  type SkuPack,
  type SpecificationParameter,
  type UomConversion,
} from '../api/productMasters';
import { useErpSession } from '../app/ErpSessionContext';
import { PageHeader } from './PageHeader';
import { DeleteEntryDialog } from './DeleteEntryDialog';
import { StatusBadge } from './StatusBadge';

type MasterForm = {
  code: string;
  name: string;
  status: ProductMasterStatus;
  description: string | null;
  brand_id: string | null;
  item_type: string;
  base_uom: string;
  shelf_life_days: string;
  lot_controlled: boolean;
  catalog_item_id: string;
  barcode: string | null;
  pack_quantity: string;
  pack_uom_code: string;
  revision: string;
  output_sku_id: string;
  output_quantity: string;
  output_uom_code: string;
  yield_percent: string;
  effective_from: string | null;
  effective_to: string | null;
  notes: string | null;
  target_type: 'ITEM' | 'SKU';
  sku_id: string | null;
  sampling_plan: string | null;
  agreements: BrandAgreement[];
  conversions: UomConversion[];
  packs: SkuPack[];
  components: RecipeComponent[];
  operations: RouteOperation[];
  parameters: SpecificationParameter[];
};

type WorkspaceConfig = {
  screen: string;
  batch: string;
  title: string;
  noun: string;
  plural: string;
  description: string;
  notice: string;
};

const configs: Record<ProductMasterResource, WorkspaceConfig> = {
  brands: {
    screen: 'MD-BRAND', batch: 'B05', title: 'Brands & Agreements', noun: 'brand', plural: 'brands',
    description: 'Company brands and effective-dated commercial agreements with registered parties.',
    notice: 'Immutable brand codes, scoped agreement numbers, effective dates, versions, and dependencies are server enforced.',
  },
  items: {
    screen: 'MD-ITEM', batch: 'B06', title: 'Items & UOM', noun: 'item', plural: 'items',
    description: 'Catalog items, classification, lot policy, base units, and explicit UOM conversions.',
    notice: 'The catalog layer preserves the stock SKU contract while enforcing unit conversion and lifecycle integrity.',
  },
  skus: {
    screen: 'MD-SKU', batch: 'B07', title: 'SKUs & Packs', noun: 'SKU', plural: 'SKUs',
    description: 'Stock-bearing SKUs, barcodes, selling units, and controlled pack definitions.',
    notice: 'SKU identity remains compatible with inventory and Unsold Return; parent, barcode, pack, and stock safeguards are live.',
  },
  recipes: {
    screen: 'MD-REC', batch: 'B08', title: 'Recipes / BOM', noun: 'recipe', plural: 'recipes',
    description: 'Versioned output definitions, yields, effective dates, and component bills of material.',
    notice: 'Active recipes require active output/component SKUs and reject self-consumption or duplicate BOM lines.',
  },
  routes: {
    screen: 'MD-ROUTE', batch: 'B09', title: 'Production Routes', noun: 'route', plural: 'routes',
    description: 'Item-specific production routes with ordered work-centre operations and standard times.',
    notice: 'Operation sequence, work centres, non-negative standard times, parent state, and versions are controlled.',
  },
  specifications: {
    screen: 'MD-SPEC', batch: 'B10', title: 'Quality Standards', noun: 'specification', plural: 'specifications',
    description: 'Effective-dated item and SKU quality standards with typed test parameters and acceptance limits.',
    notice: 'Exactly one target and complete numeric, text, or boolean acceptance criteria are required for activation.',
  },
};

export function ProductMasterWorkspace({ resource }: { resource: ProductMasterResource }) {
  const config = configs[resource];
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const [workspace, setWorkspace] = useState<ProductMasterWorkspaceData | null>(null);
  const [selected, setSelected] = useState<ProductMasterDetail | null>(null);
  const [form, setForm] = useState<MasterForm>(() => blankForm());
  const [searchDraft, setSearchDraft] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState<ProductMasterStatus | ''>('');
  const [type, setType] = useState('');
  const [parentId, setParentId] = useState('');
  const [sort, setSort] = useState<'CODE' | 'NAME' | 'NEWEST' | 'OLDEST'>('CODE');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [targetStatus, setTargetStatus] = useState<ProductMasterStatus>('ACTIVE');
  const [statusReason, setStatusReason] = useState('');
  const [pendingDelete, setPendingDelete] = useState<ProductMasterDetail | null>(null);
  const commandKey = useRef<string | null>(null);
  const lifecycleKey = useRef<string | null>(null);
  const selectedId = useRef<string | null>(null);
  const initialisedFormKey = useRef<string | null>(null);
  const loadedWorkspaceKey = useRef<string | null>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      const loadedWorkspace = await listProductMasters(resource, {
        page,
        q: search || undefined,
        status,
        type: type || undefined,
        parent_id: parentId || undefined,
        sort,
      });
      loadedWorkspaceKey.current = `${contextKey}:${resource}`;
      setWorkspace(loadedWorkspace);
    } catch (caught) {
      setError(apiMessage(caught, `Unable to load ${config.plural}.`));
    } finally {
      setLoading(false);
    }
  }, [contextKey, resource, page, search, status, type, parentId, sort, config.plural]);

  useEffect(() => {
    initialisedFormKey.current = null;
    loadedWorkspaceKey.current = null;
    setWorkspace(null);
    setSelected(null);
    selectedId.current = null;
    setForm(blankForm());
    setPage(1);
    setSearch('');
    setSearchDraft('');
    setStatus('');
    setType('');
    setParentId('');
    setSuccess(null);
  }, [contextKey, resource]);

  useEffect(() => { void refresh(); }, [refresh]);

  useEffect(() => {
    if (!workspace || workspace.resource !== resource) return;
    const formKey = `${contextKey}:${resource}`;
    if (loadedWorkspaceKey.current !== formKey) return;
    if (initialisedFormKey.current === formKey) return;
    initialisedFormKey.current = formKey;
    if (selectedId.current === null) setForm(blankForm(resource, workspace.lookups));
  }, [contextKey, resource, workspace]);

  async function choose(recordId: string) {
    selectedId.current = recordId;
    setDetailLoading(true);
    clearFeedback();
    try {
      const detail = await getProductMaster(resource, recordId);
      if (selectedId.current !== recordId) return;
      setSelected(detail);
      setForm(toForm(detail));
      setTargetStatus(detail.allowed_statuses[0] ?? 'ACTIVE');
      setStatusReason('');
    } catch (caught) {
      setError(apiMessage(caught, `Unable to load this ${config.noun}.`));
    } finally {
      setDetailLoading(false);
    }
  }

  function startCreate() {
    selectedId.current = null;
    setSelected(null);
    setForm(blankForm(resource, workspace?.lookups));
    setTargetStatus('ACTIVE');
    setStatusReason('');
    clearFeedback();
  }

  function change(patch: Partial<MasterForm>) {
    setForm((current) => ({ ...current, ...patch }));
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
    try {
      const body = writePayload(resource, form);
      const result = selected
        ? await updateProductMaster(resource, selected, body, commandKey.current)
        : await createProductMaster(resource, {
          ...body,
          code: form.code.trim().toUpperCase(),
          status: form.status,
        }, commandKey.current);
      commandKey.current = null;
      const message = selected
        ? `${selected.code} was saved with record version ${result.record_version}.`
        : `${form.code.trim().toUpperCase()} was created as ${result.status}.`;
      await refresh();
      await chooseAfterSave(result.id);
      setSuccess(message);
    } catch (caught) {
      setError(apiMessage(caught, `Unable to save this ${config.noun}.`));
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
      const result = await changeProductMasterStatus(
        resource,
        selected,
        targetStatus,
        statusReason.trim(),
        lifecycleKey.current,
      );
      lifecycleKey.current = null;
      setStatusReason('');
      await refresh();
      await chooseAfterSave(selected.id);
      setSuccess(`${selected.code} moved to ${display(result.status)} at version ${result.record_version}.`);
    } catch (caught) {
      setError(apiMessage(caught, `Unable to change this ${config.noun} status.`));
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
      await deleteProductMaster(resource, target, globalThis.crypto.randomUUID());
      setPendingDelete(null);
      selectedId.current = null;
      setSelected(null);
      setForm(blankForm(resource, workspace?.lookups));
      await refresh();
      setSuccess(`${target.code} was deleted.`);
    } catch (caught) {
      setPendingDelete(null);
      setError(apiMessage(caught, `Unable to delete this ${config.noun}.`));
      setFieldErrors(apiFields(caught));
    } finally {
      setBusy(false);
    }
  }

  async function chooseAfterSave(recordId: string) {
    selectedId.current = recordId;
    const detail = await getProductMaster(resource, recordId);
    setSelected(detail);
    setForm(toForm(detail));
    setTargetStatus(detail.allowed_statuses[0] ?? 'ACTIVE');
  }

  function submitSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPage(1);
    setSearch(searchDraft.trim());
  }

  const hasSavePermission = selected
    ? selected.allowed_actions.includes('UPDATE')
    : workspace?.allowed_actions.includes('CREATE');
  const requiresUoms = resource === 'items' || resource === 'skus' || resource === 'recipes';
  const missingRequiredUoms = Boolean(workspace && requiresUoms && workspace.lookups.uoms.length === 0);
  const canSave = Boolean(hasSavePermission && !missingRequiredUoms);
  const typeOptions = resource === 'specifications'
    ? workspace?.lookups.spec_target_types ?? []
    : resource === 'items' || resource === 'skus'
      ? workspace?.lookups.item_types ?? []
      : [];
  const parentOptions = parentFilterOptions(resource, workspace?.lookups);

  return (
    <>
      <PageHeader
        code={config.screen}
        batch={config.batch}
        title={config.title}
        description={config.description}
        onNew={workspace?.allowed_actions.includes('CREATE') ? startCreate : undefined}
      />
      <div className="live-notice product-master-notice"><span></span><b>Controlled product master</b>{config.notice}</div>

      <div className="kpi-grid party-kpis">
        <div className="kpi"><span>Total</span><b>{loading && !workspace ? '-' : workspace?.summary.total ?? 0}</b><small>{config.plural} in company</small></div>
        <div className="kpi"><span>Active</span><b>{loading && !workspace ? '-' : workspace?.summary.active ?? 0}</b><small>available downstream</small></div>
        <div className="kpi"><span>Draft</span><b>{loading && !workspace ? '-' : workspace?.summary.draft ?? 0}</b><small>not operational</small></div>
        <div className="kpi"><span>Inactive</span><b>{loading && !workspace ? '-' : workspace?.summary.inactive ?? 0}</b><small>retained history</small></div>
      </div>

      <div className="module-grid admin-workspace party-workspace product-master-workspace">
        <section className="panel">
          <div className="panel-head"><div><h3>{display(config.plural)} register</h3><span>{session.selected_context?.company_name}</span></div><button className="secondary compact-button" type="button" onClick={() => void refresh()} disabled={loading}>Refresh</button></div>
          <form className="party-toolbar product-master-toolbar" onSubmit={submitSearch}>
            <label>Search<span><input aria-label={`Search ${config.plural}`} value={searchDraft} onChange={(event) => setSearchDraft(event.target.value)} placeholder="Code or name" /><button className="secondary" type="submit">Search</button></span></label>
            <label>Status<select aria-label={`Filter ${config.noun} status`} value={status} onChange={(event) => { setStatus(event.target.value as ProductMasterStatus | ''); setPage(1); }}><option value="">All statuses</option>{workspace?.lookups.statuses.map((item) => <option key={item}>{item}</option>)}</select></label>
            {typeOptions.length > 0 && <label>Type<select aria-label={`Filter ${config.noun} type`} value={type} onChange={(event) => { setType(event.target.value); setPage(1); }}><option value="">All types</option>{typeOptions.map((item) => <option key={item}>{item}</option>)}</select></label>}
            {parentOptions.length > 0 && <label>Parent<select aria-label={`Filter ${config.noun} parent`} value={parentId} onChange={(event) => { setParentId(event.target.value); setPage(1); }}><option value="">All parents</option>{parentOptions.map((item) => <option key={item.id} value={item.id}>{item.code} · {item.name}</option>)}</select></label>}
            <label>Order<select aria-label={`Sort ${config.plural}`} value={sort} onChange={(event) => { setSort(event.target.value as typeof sort); setPage(1); }}><option value="CODE">Code</option><option value="NAME">Name</option><option value="NEWEST">Newest</option><option value="OLDEST">Oldest</option></select></label>
          </form>
          {error && !workspace && <div className="form-error panel-message" role="alert"><span>{error}</span><button type="button" onClick={() => void refresh()}>Retry</button></div>}
          {loading && !workspace && <div className="empty-state">Loading {config.plural}...</div>}
          {!loading && workspace && workspace.data.length === 0 && <div className="empty-state">No {config.noun} matches the current filters.</div>}
          {workspace && workspace.data.length > 0 && <div className={`table-wrap ${loading ? 'is-refreshing' : ''}`}>
            <table className="admin-table product-master-table"><thead><tr><th>{display(config.noun)}</th><th>Parent / target</th><th>Structure</th><th>Status</th><th>Updated</th><th>Version</th><th>Action</th></tr></thead><tbody>
              {workspace.data.map((record) => <tr key={record.id} className={selected?.id === record.id ? 'selected-row' : ''}>
                <td><b>{record.name}</b><small>{record.code}</small></td>
                <td>{record.parent?.name ?? '—'}<small>{record.parent?.code ?? resourceSummary(resource, record)}</small></td>
                <td>{record.child_count} {display(record.child_label)}<small>{structureSummary(resource, record)}</small></td>
                <td><StatusBadge status={record.status} /></td>
                <td>{formatDate(record.updated_at)}</td>
                <td>v{record.record_version}</td>
                <td><button className="secondary compact-button" type="button" onClick={() => void choose(record.id)}>Open</button></td>
              </tr>)}
            </tbody></table>
          </div>}
          {workspace && <div className="pagination"><button type="button" disabled={page <= 1 || loading} onClick={() => setPage((value) => value - 1)}>Previous</button><span>Page {workspace.meta.current_page} of {workspace.meta.last_page} · {workspace.meta.total} records</span><button type="button" disabled={page >= workspace.meta.last_page || loading} onClick={() => setPage((value) => value + 1)}>Next</button></div>}
        </section>

        <aside className="panel admin-editor party-editor product-master-editor">
          <div className="panel-head"><div><h3>{selected ? `Edit ${selected.code}` : `New ${config.noun}`}</h3><span>{detailLoading ? 'Loading detail...' : selected ? `Version ${selected.record_version}` : 'Company scoped'}</span></div>{selected && <StatusBadge status={selected.status} />}</div>
          <form className="panel-body party-form" onSubmit={save} noValidate>
            {error && workspace && <div className="form-error" role="alert"><span>{error}</span></div>}
            {missingRequiredUoms && <div className="form-error" role="alert"><span>Units of measure are not configured. Ask an administrator to apply the required reference data before creating this {config.noun}.</span></div>}
            {success && <div className="form-success" role="status"><span></span>{success}</div>}
            <fieldset className="party-form-fields" disabled={busy || detailLoading}>
              <section className="party-subsection">
                <h4>Identity and lifecycle</h4>
                <div className="party-card-grid">
                  <label>Code<input aria-label={`${display(config.noun)} code`} value={form.code} readOnly={Boolean(selected)} className={selected ? 'read-only' : ''} onChange={(event) => change({ code: event.target.value.toUpperCase() })} /><FieldError value={fieldErrors.code} /></label>
                  {!selected && <label>Initial status<select aria-label="Initial status" value={form.status} onChange={(event) => change({ status: event.target.value as ProductMasterStatus })}><option>DRAFT</option><option>ACTIVE</option></select></label>}
                  <label className="wide">Name<input aria-label={`${display(config.noun)} name`} value={form.name} onChange={(event) => change({ name: event.target.value })} /><FieldError value={fieldErrors.name} /></label>
                </div>
              </section>

              <ResourceEditor
                resource={resource}
                form={form}
                setForm={setForm}
                lookups={workspace?.lookups}
                fieldErrors={fieldErrors}
                resetFeedback={resetCommandFeedback}
              />

              <div className="form-actions">
                {selected && session.roles.includes('ERP_ADMIN') && <button className="danger-button" type="button" disabled={busy || detailLoading} onClick={() => { clearFeedback(); setPendingDelete(selected); }}>Delete entry</button>}
                <button className="primary" type="submit" disabled={busy || detailLoading || !canSave}>{busy ? 'Saving...' : selected ? `Save ${config.noun}` : `Create ${config.noun}`}</button>
              </div>
            </fieldset>
          </form>

          {selected && selected.allowed_actions.includes('CHANGE_STATUS') && selected.allowed_statuses.length > 0 && <form className="party-lifecycle" onSubmit={submitLifecycle}>
            <div><h4>Lifecycle control</h4><small>{selected.status_change ? `${selected.status_change.reason ?? 'Status changed'} · ${formatDate(selected.status_change.changed_at)}` : 'No prior lifecycle transition recorded.'}</small></div>
            <label>Target status<select aria-label={`Target ${config.noun} status`} value={targetStatus} disabled={busy} onChange={(event) => { setTargetStatus(event.target.value as ProductMasterStatus); lifecycleKey.current = null; }}>{selected.allowed_statuses.map((item) => <option key={item}>{item}</option>)}</select></label>
            <label>Reason<textarea aria-label={`${display(config.noun)} status reason`} rows={2} minLength={3} maxLength={2000} value={statusReason} disabled={busy} onChange={(event) => { setStatusReason(event.target.value); lifecycleKey.current = null; }} /></label>
            <FieldError value={fieldErrors.target_status ?? fieldErrors.reason} />
            <button className="secondary" type="submit" disabled={busy || statusReason.trim().length < 3}>Apply status</button>
          </form>}
        </aside>
      </div>
      {pendingDelete && <DeleteEntryDialog entryLabel={pendingDelete.name} entryCode={pendingDelete.code} busy={busy} onCancel={() => setPendingDelete(null)} onConfirm={() => void confirmDelete()} />}
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

function ResourceEditor({
  resource,
  form,
  setForm,
  lookups,
  fieldErrors,
  resetFeedback,
}: {
  resource: ProductMasterResource;
  form: MasterForm;
  setForm: Dispatch<SetStateAction<MasterForm>>;
  lookups?: ProductMasterLookups;
  fieldErrors: Record<string, string>;
  resetFeedback: () => void;
}) {
  const change = (patch: Partial<MasterForm>) => {
    setForm((current) => ({ ...current, ...patch }));
    resetFeedback();
  };
  const update = <T,>(rows: T[], index: number, patch: Partial<T>) => rows.map(
    (row, rowIndex) => rowIndex === index ? { ...row, ...patch } : row,
  );
  const options = (rows: { id: string; code: string; name: string; status: string }[] = []) => rows.map(
    (item) => <option key={item.id} value={item.id}>{item.code} · {item.name}{item.status !== 'ACTIVE' ? ` (${item.status})` : ''}</option>,
  );
  const uoms = lookups?.uoms ?? [];
  const requiredUomOptions = () => uoms.length > 0
    ? uoms.map((item) => <option key={item.code}>{item.code}</option>)
    : <option value="">No units configured</option>;

  if (resource === 'brands') return <>
    <section className="party-subsection">
      <h4>Brand definition</h4>
      <label className="block-label">Description<textarea aria-label="Brand description" rows={3} value={form.description ?? ''} onChange={(event) => change({ description: nullable(event.target.value) })} /></label>
    </section>
    <section className="party-subsection">
      <div className="subsection-head"><h4>Commercial agreements</h4><button className="secondary compact-button" type="button" onClick={() => change({ agreements: [...form.agreements, blankAgreement(lookups)] })}>Add agreement</button></div>
      <FieldError value={fieldErrors.agreements} />
      {form.agreements.length === 0 && <div className="empty-state compact">No agreements recorded.</div>}
      {form.agreements.map((row, index) => <div className="party-repeat-card" key={row.id ?? `agreement-${index}`}>
        <RepeatHeader label={`Agreement ${index + 1}`} onRemove={() => change({ agreements: form.agreements.filter((_, itemIndex) => itemIndex !== index) })} />
        <div className="party-card-grid three">
          <label>Number<input aria-label={`Agreement number ${index + 1}`} value={row.agreement_number} onChange={(event) => change({ agreements: update(form.agreements, index, { agreement_number: event.target.value.toUpperCase() }) })} /><FieldError value={fieldErrors[`agreements.${index}.agreement_number`]} /></label>
          <label>Party<select aria-label={`Agreement party ${index + 1}`} value={row.party_id} onChange={(event) => change({ agreements: update(form.agreements, index, { party_id: event.target.value }) })}><option value="">Select party</option>{options(lookups?.parties)}</select></label>
          <label>Type<select aria-label={`Agreement type ${index + 1}`} value={row.agreement_type} onChange={(event) => change({ agreements: update(form.agreements, index, { agreement_type: event.target.value as BrandAgreement['agreement_type'] }) })}>{lookups?.agreement_types.map((item) => <option key={item}>{item}</option>)}</select></label>
          <label>Effective from<input aria-label={`Agreement effective from ${index + 1}`} type="date" value={row.effective_from} onChange={(event) => change({ agreements: update(form.agreements, index, { effective_from: event.target.value }) })} /></label>
          <label>Effective to<input aria-label={`Agreement effective to ${index + 1}`} type="date" value={row.effective_to ?? ''} onChange={(event) => change({ agreements: update(form.agreements, index, { effective_to: nullable(event.target.value) }) })} /></label>
          <label>Status<select aria-label={`Agreement status ${index + 1}`} value={row.status} onChange={(event) => change({ agreements: update(form.agreements, index, { status: event.target.value as BrandAgreement['status'] }) })}>{lookups?.agreement_statuses.map((item) => <option key={item}>{item}</option>)}</select></label>
          <label>Currency<input aria-label={`Agreement currency ${index + 1}`} maxLength={3} value={row.currency_code} onChange={(event) => change({ agreements: update(form.agreements, index, { currency_code: event.target.value.toUpperCase() }) })} /></label>
          <label>Minimum commitment<input aria-label={`Agreement minimum commitment ${index + 1}`} type="number" min="0" step="0.01" value={row.minimum_commitment} onChange={(event) => change({ agreements: update(form.agreements, index, { minimum_commitment: event.target.value }) })} /></label>
          <label className="wide">Notes<textarea aria-label={`Agreement notes ${index + 1}`} rows={2} value={row.notes ?? ''} onChange={(event) => change({ agreements: update(form.agreements, index, { notes: nullable(event.target.value) }) })} /></label>
        </div>
      </div>)}
    </section>
  </>;

  if (resource === 'items') return <>
    <section className="party-subsection">
      <h4>Catalog definition</h4>
      <div className="party-card-grid three">
        <label>Brand<select aria-label="Item brand" value={form.brand_id ?? ''} onChange={(event) => change({ brand_id: nullable(event.target.value) })}><option value="">Unbranded</option>{options(lookups?.brands)}</select></label>
        <label>Item type<select aria-label="Item type" value={form.item_type} onChange={(event) => change({ item_type: event.target.value })}>{lookups?.item_types.map((item) => <option key={item}>{item}</option>)}</select></label>
        <label>Base UOM<select aria-label="Base UOM" value={form.base_uom} onChange={(event) => change({ base_uom: event.target.value })}>{requiredUomOptions()}</select><FieldError value={fieldErrors.base_uom ?? fieldErrors.uom} /></label>
        <label>Shelf life days<input aria-label="Shelf life days" type="number" min="0" value={form.shelf_life_days} onChange={(event) => change({ shelf_life_days: event.target.value })} /></label>
        <label className="check-label"><input aria-label="Lot controlled" type="checkbox" checked={form.lot_controlled} onChange={(event) => change({ lot_controlled: event.target.checked })} />Lot controlled</label>
        <label className="wide">Description<textarea aria-label="Item description" rows={3} value={form.description ?? ''} onChange={(event) => change({ description: nullable(event.target.value) })} /></label>
      </div>
    </section>
    <section className="party-subsection">
      <div className="subsection-head"><h4>UOM conversions</h4><button className="secondary compact-button" type="button" onClick={() => change({ conversions: [...form.conversions, blankConversion(form.base_uom, uoms)] })}>Add conversion</button></div>
      <FieldError value={fieldErrors.conversions ?? fieldErrors.uom} />
      {form.conversions.length === 0 && <div className="empty-state compact">No alternate units configured.</div>}
      {form.conversions.map((row, index) => <div className="party-repeat-card" key={row.id ?? `conversion-${index}`}>
        <RepeatHeader label={`Conversion ${index + 1}`} onRemove={() => change({ conversions: form.conversions.filter((_, itemIndex) => itemIndex !== index) })} />
        <div className="party-card-grid three">
          <label>From UOM<select aria-label={`Conversion from UOM ${index + 1}`} value={row.from_uom_code} onChange={(event) => change({ conversions: update(form.conversions, index, { from_uom_code: event.target.value }) })}>{requiredUomOptions()}</select></label>
          <label>To UOM<select aria-label={`Conversion to UOM ${index + 1}`} value={row.to_uom_code} onChange={(event) => change({ conversions: update(form.conversions, index, { to_uom_code: event.target.value }) })}>{requiredUomOptions()}</select></label>
          <label>Multiplier<input aria-label={`Conversion multiplier ${index + 1}`} type="number" min="0" step="any" value={row.multiplier} onChange={(event) => change({ conversions: update(form.conversions, index, { multiplier: event.target.value }) })} /></label>
          <label>Rounding<select aria-label={`Conversion rounding ${index + 1}`} value={row.rounding_mode} onChange={(event) => change({ conversions: update(form.conversions, index, { rounding_mode: event.target.value as UomConversion['rounding_mode'] }) })}>{lookups?.rounding_modes.map((item) => <option key={item}>{item}</option>)}</select></label>
        </div>
      </div>)}
    </section>
  </>;

  if (resource === 'skus') return <>
    <section className="party-subsection">
      <h4>Stock-keeping definition</h4>
      <div className="party-card-grid three">
        <label className="wide">Catalog item<select aria-label="SKU catalog item" value={form.catalog_item_id} onChange={(event) => change({ catalog_item_id: event.target.value })}><option value="">Select item</option>{options(lookups?.items)}</select><FieldError value={fieldErrors.catalog_item_id} /></label>
        <label>Barcode<input aria-label="SKU barcode" value={form.barcode ?? ''} onChange={(event) => change({ barcode: nullable(event.target.value) })} /><FieldError value={fieldErrors.barcode} /></label>
        <label>Pack quantity<input aria-label="SKU pack quantity" type="number" min="0" step="any" value={form.pack_quantity} onChange={(event) => change({ pack_quantity: event.target.value })} /></label>
        <label>Pack UOM<select aria-label="SKU pack UOM" value={form.pack_uom_code} onChange={(event) => change({ pack_uom_code: event.target.value })}>{requiredUomOptions()}</select></label>
        <label className="wide">Description<textarea aria-label="SKU description" rows={3} value={form.description ?? ''} onChange={(event) => change({ description: nullable(event.target.value) })} /></label>
      </div>
    </section>
    <section className="party-subsection">
      <div className="subsection-head"><h4>Packs</h4><button className="secondary compact-button" type="button" onClick={() => change({ packs: [...form.packs, blankPack(form.packs.length === 0, form.pack_uom_code)] })}>Add pack</button></div>
      <FieldError value={fieldErrors.packs} />
      {form.packs.length === 0 && <div className="empty-state compact">Drafts may omit packs; activation requires one default pack.</div>}
      {form.packs.map((row, index) => <div className="party-repeat-card" key={row.id ?? `pack-${index}`}>
        <RepeatHeader label={`Pack ${index + 1}`} onRemove={() => change({ packs: form.packs.filter((_, itemIndex) => itemIndex !== index) })} />
        <div className="party-card-grid three">
          <label>Code<input aria-label={`Pack code ${index + 1}`} value={row.code} onChange={(event) => change({ packs: update(form.packs, index, { code: event.target.value.toUpperCase() }) })} /></label>
          <label>Name<input aria-label={`Pack name ${index + 1}`} value={row.name} onChange={(event) => change({ packs: update(form.packs, index, { name: event.target.value }) })} /></label>
          <label>UOM<select aria-label={`Pack UOM ${index + 1}`} value={row.uom_code} onChange={(event) => change({ packs: update(form.packs, index, { uom_code: event.target.value }) })}>{requiredUomOptions()}</select></label>
          <label>Quantity<input aria-label={`Pack quantity ${index + 1}`} type="number" min="0" step="any" value={row.quantity} onChange={(event) => change({ packs: update(form.packs, index, { quantity: event.target.value }) })} /></label>
          <label>Barcode<input aria-label={`Pack barcode ${index + 1}`} value={row.barcode ?? ''} onChange={(event) => change({ packs: update(form.packs, index, { barcode: nullable(event.target.value) }) })} /></label>
          <label className="check-label"><input aria-label={`Default pack ${index + 1}`} type="checkbox" checked={row.is_default} onChange={(event) => change({ packs: form.packs.map((item, itemIndex) => ({ ...item, is_default: itemIndex === index ? event.target.checked : event.target.checked ? false : item.is_default })) })} />Default pack</label>
        </div>
      </div>)}
    </section>
  </>;

  if (resource === 'recipes') return <>
    <section className="party-subsection">
      <h4>Output and yield</h4>
      <div className="party-card-grid three">
        <label className="wide">Output SKU<select aria-label="Recipe output SKU" value={form.output_sku_id} onChange={(event) => change({ output_sku_id: event.target.value })}><option value="">Select SKU</option>{options(lookups?.skus)}</select></label>
        <label>Revision<input aria-label="Recipe revision" type="number" min="1" value={form.revision} onChange={(event) => change({ revision: event.target.value })} /></label>
        <label>Output quantity<input aria-label="Recipe output quantity" type="number" min="0" step="any" value={form.output_quantity} onChange={(event) => change({ output_quantity: event.target.value })} /></label>
        <label>Output UOM<select aria-label="Recipe output UOM" value={form.output_uom_code} onChange={(event) => change({ output_uom_code: event.target.value })}>{requiredUomOptions()}</select></label>
        <label>Yield %<input aria-label="Recipe yield percent" type="number" min="0" max="100" step="any" value={form.yield_percent} onChange={(event) => change({ yield_percent: event.target.value })} /></label>
        <label>Effective from<input aria-label="Recipe effective from" type="date" value={form.effective_from ?? ''} onChange={(event) => change({ effective_from: nullable(event.target.value) })} /></label>
        <label>Effective to<input aria-label="Recipe effective to" type="date" value={form.effective_to ?? ''} onChange={(event) => change({ effective_to: nullable(event.target.value) })} /></label>
        <label className="wide">Notes<textarea aria-label="Recipe notes" rows={3} value={form.notes ?? ''} onChange={(event) => change({ notes: nullable(event.target.value) })} /></label>
      </div>
    </section>
    <section className="party-subsection">
      <div className="subsection-head"><h4>BOM components</h4><button className="secondary compact-button" type="button" onClick={() => change({ components: [...form.components, blankComponent(form.components, lookups)] })}>Add component</button></div>
      <FieldError value={fieldErrors.components} />
      {form.components.length === 0 && <div className="empty-state compact">Activation requires at least one component.</div>}
      {form.components.map((row, index) => <div className="party-repeat-card" key={row.id ?? `component-${index}`}>
        <RepeatHeader label={`Component ${index + 1}`} onRemove={() => change({ components: form.components.filter((_, itemIndex) => itemIndex !== index) })} />
        <div className="party-card-grid three">
          <label className="wide">Component SKU<select aria-label={`Component SKU ${index + 1}`} value={row.component_sku_id} onChange={(event) => change({ components: update(form.components, index, { component_sku_id: event.target.value }) })}><option value="">Select SKU</option>{options(lookups?.skus)}</select></label>
          <label>Sequence<input aria-label={`Component sequence ${index + 1}`} type="number" min="1" value={row.sequence_no} onChange={(event) => change({ components: update(form.components, index, { sequence_no: Number(event.target.value) }) })} /></label>
          <label>Quantity<input aria-label={`Component quantity ${index + 1}`} type="number" min="0" step="any" value={row.quantity} onChange={(event) => change({ components: update(form.components, index, { quantity: event.target.value }) })} /></label>
          <label>UOM<select aria-label={`Component UOM ${index + 1}`} value={row.uom_code} onChange={(event) => change({ components: update(form.components, index, { uom_code: event.target.value }) })}>{requiredUomOptions()}</select></label>
          <label>Waste %<input aria-label={`Component waste percent ${index + 1}`} type="number" min="0" max="100" step="any" value={row.waste_percent} onChange={(event) => change({ components: update(form.components, index, { waste_percent: event.target.value }) })} /></label>
        </div>
      </div>)}
    </section>
  </>;

  if (resource === 'routes') return <>
    <section className="party-subsection">
      <h4>Route definition</h4>
      <div className="party-card-grid">
        <label className="wide">Catalog item<select aria-label="Route catalog item" value={form.catalog_item_id} onChange={(event) => change({ catalog_item_id: event.target.value })}><option value="">Select item</option>{options(lookups?.items)}</select></label>
        <label className="wide">Description<textarea aria-label="Route description" rows={3} value={form.description ?? ''} onChange={(event) => change({ description: nullable(event.target.value) })} /></label>
      </div>
    </section>
    <section className="party-subsection">
      <div className="subsection-head"><h4>Operations</h4><button className="secondary compact-button" type="button" onClick={() => change({ operations: [...form.operations, blankOperation(form.operations)] })}>Add operation</button></div>
      <FieldError value={fieldErrors.operations} />
      {form.operations.length === 0 && <div className="empty-state compact">Activation requires at least one operation.</div>}
      {form.operations.map((row, index) => <div className="party-repeat-card" key={row.id ?? `operation-${index}`}>
        <RepeatHeader label={`Operation ${index + 1}`} onRemove={() => change({ operations: form.operations.filter((_, itemIndex) => itemIndex !== index) })} />
        <div className="party-card-grid three">
          <label>Sequence<input aria-label={`Operation sequence ${index + 1}`} type="number" min="1" value={row.sequence_no} onChange={(event) => change({ operations: update(form.operations, index, { sequence_no: Number(event.target.value) }) })} /></label>
          <label>Name<input aria-label={`Operation name ${index + 1}`} value={row.name} onChange={(event) => change({ operations: update(form.operations, index, { name: event.target.value }) })} /></label>
          <label>Work centre<input aria-label={`Operation work centre ${index + 1}`} value={row.work_center_code} onChange={(event) => change({ operations: update(form.operations, index, { work_center_code: event.target.value.toUpperCase() }) })} /></label>
          <label>Setup minutes<input aria-label={`Operation setup minutes ${index + 1}`} type="number" min="0" step="any" value={row.setup_minutes} onChange={(event) => change({ operations: update(form.operations, index, { setup_minutes: event.target.value }) })} /></label>
          <label>Run minutes / unit<input aria-label={`Operation run minutes ${index + 1}`} type="number" min="0" step="any" value={row.run_minutes_per_unit} onChange={(event) => change({ operations: update(form.operations, index, { run_minutes_per_unit: event.target.value }) })} /></label>
          <label className="wide">Instructions<textarea aria-label={`Operation instructions ${index + 1}`} rows={2} value={row.instructions ?? ''} onChange={(event) => change({ operations: update(form.operations, index, { instructions: nullable(event.target.value) }) })} /></label>
        </div>
      </div>)}
    </section>
  </>;

  return <>
    <section className="party-subsection">
      <h4>Standard target and validity</h4>
      <div className="party-card-grid three">
        <label>Target type<select aria-label="Specification target type" value={form.target_type} onChange={(event) => change({ target_type: event.target.value as 'ITEM' | 'SKU', catalog_item_id: '', sku_id: null })}>{lookups?.spec_target_types.map((item) => <option key={item}>{item}</option>)}</select></label>
        {form.target_type === 'ITEM' ? <label className="wide">Catalog item<select aria-label="Specification catalog item" value={form.catalog_item_id} onChange={(event) => change({ catalog_item_id: event.target.value })}><option value="">Select item</option>{options(lookups?.items)}</select></label> : <label className="wide">SKU<select aria-label="Specification SKU" value={form.sku_id ?? ''} onChange={(event) => change({ sku_id: nullable(event.target.value) })}><option value="">Select SKU</option>{options(lookups?.skus)}</select></label>}
        <label>Effective from<input aria-label="Specification effective from" type="date" value={form.effective_from ?? ''} onChange={(event) => change({ effective_from: nullable(event.target.value) })} /></label>
        <label>Effective to<input aria-label="Specification effective to" type="date" value={form.effective_to ?? ''} onChange={(event) => change({ effective_to: nullable(event.target.value) })} /></label>
        <label>Sampling plan<input aria-label="Specification sampling plan" value={form.sampling_plan ?? ''} onChange={(event) => change({ sampling_plan: nullable(event.target.value) })} /></label>
        <label className="wide">Notes<textarea aria-label="Specification notes" rows={3} value={form.notes ?? ''} onChange={(event) => change({ notes: nullable(event.target.value) })} /></label>
      </div>
    </section>
    <section className="party-subsection">
      <div className="subsection-head"><h4>Acceptance parameters</h4><button className="secondary compact-button" type="button" onClick={() => change({ parameters: [...form.parameters, blankParameter(form.parameters)] })}>Add parameter</button></div>
      <FieldError value={fieldErrors.parameters} />
      {form.parameters.length === 0 && <div className="empty-state compact">Activation requires at least one typed acceptance parameter.</div>}
      {form.parameters.map((row, index) => <div className="party-repeat-card" key={row.id ?? `parameter-${index}`}>
        <RepeatHeader label={`Parameter ${index + 1}`} onRemove={() => change({ parameters: form.parameters.filter((_, itemIndex) => itemIndex !== index) })} />
        <div className="party-card-grid three">
          <label>Sequence<input aria-label={`Parameter sequence ${index + 1}`} type="number" min="1" value={row.sequence_no} onChange={(event) => change({ parameters: update(form.parameters, index, { sequence_no: Number(event.target.value) }) })} /></label>
          <label>Code<input aria-label={`Parameter code ${index + 1}`} value={row.code} onChange={(event) => change({ parameters: update(form.parameters, index, { code: event.target.value.toUpperCase() }) })} /></label>
          <label>Name<input aria-label={`Parameter name ${index + 1}`} value={row.name} onChange={(event) => change({ parameters: update(form.parameters, index, { name: event.target.value }) })} /></label>
          <label>Value type<select aria-label={`Parameter value type ${index + 1}`} value={row.value_type} onChange={(event) => change({ parameters: update(form.parameters, index, resetParameterType(row, event.target.value as SpecificationParameter['value_type'])) })}>{lookups?.spec_value_types.map((item) => <option key={item}>{item}</option>)}</select></label>
          <label>UOM<select aria-label={`Parameter UOM ${index + 1}`} value={row.uom_code ?? ''} onChange={(event) => change({ parameters: update(form.parameters, index, { uom_code: nullable(event.target.value) }) })}><option value="">No UOM</option>{uoms.map((item) => <option key={item.code}>{item.code}</option>)}</select></label>
          <label className="check-label"><input aria-label={`Parameter required ${index + 1}`} type="checkbox" checked={row.is_required} onChange={(event) => change({ parameters: update(form.parameters, index, { is_required: event.target.checked }) })} />Required</label>
          {row.value_type === 'NUMERIC' && <>
            <label>Minimum<input aria-label={`Parameter minimum ${index + 1}`} type="number" step="any" value={row.minimum_value ?? ''} onChange={(event) => change({ parameters: update(form.parameters, index, { minimum_value: nullable(event.target.value) }) })} /></label>
            <label>Target<input aria-label={`Parameter target ${index + 1}`} type="number" step="any" value={row.target_value ?? ''} onChange={(event) => change({ parameters: update(form.parameters, index, { target_value: nullable(event.target.value) }) })} /></label>
            <label>Maximum<input aria-label={`Parameter maximum ${index + 1}`} type="number" step="any" value={row.maximum_value ?? ''} onChange={(event) => change({ parameters: update(form.parameters, index, { maximum_value: nullable(event.target.value) }) })} /></label>
          </>}
          {row.value_type === 'TEXT' && <label className="wide">Text requirement<input aria-label={`Parameter text requirement ${index + 1}`} value={row.text_requirement ?? ''} onChange={(event) => change({ parameters: update(form.parameters, index, { text_requirement: nullable(event.target.value) }) })} /></label>}
          <label className="wide">Test method<input aria-label={`Parameter test method ${index + 1}`} value={row.test_method ?? ''} onChange={(event) => change({ parameters: update(form.parameters, index, { test_method: nullable(event.target.value) }) })} /></label>
        </div>
      </div>)}
    </section>
  </>;
}

function RepeatHeader({ label, onRemove }: { label: string; onRemove: () => void }) {
  return <div className="subsection-head"><b>{label}</b><button type="button" onClick={onRemove}>Remove</button></div>;
}

function blankForm(resource?: ProductMasterResource, lookups?: ProductMasterLookups): MasterForm {
  const activeItems = lookups?.items.filter((item) => item.status === 'ACTIVE') ?? [];
  const activeSkus = lookups?.skus.filter((item) => item.status === 'ACTIVE') ?? [];
  const eachUom = lookups?.uoms.find((item) => item.code === 'EA')?.code ?? lookups?.uoms[0]?.code ?? '';
  const weightUom = lookups?.uoms.find((item) => item.code === 'KG')?.code ?? eachUom;
  const packUom = lookups?.uoms.find((item) => item.code === 'PACK')?.code ?? eachUom;
  return {
    code: '', name: '', status: 'DRAFT', description: null,
    brand_id: null, item_type: 'RAW_MATERIAL', base_uom: weightUom,
    shelf_life_days: '', lot_controlled: true,
    catalog_item_id: activeItems[0]?.id ?? '', barcode: null, pack_quantity: '1', pack_uom_code: packUom,
    revision: '1', output_sku_id: activeSkus[0]?.id ?? '', output_quantity: '1',
    output_uom_code: packUom, yield_percent: '100', effective_from: null, effective_to: null, notes: null,
    target_type: 'ITEM', sku_id: null, sampling_plan: null,
    agreements: [], conversions: [], packs: resource === 'skus' ? [blankPack(true, packUom)] : [],
    components: [], operations: [], parameters: [],
  };
}

function blankAgreement(lookups?: ProductMasterLookups): BrandAgreement {
  return {
    party_id: lookups?.parties.find((item) => item.status === 'ACTIVE')?.id ?? '',
    agreement_number: '', agreement_type: 'DISTRIBUTION', effective_from: '', effective_to: null,
    currency_code: 'INR', minimum_commitment: '0', status: 'DRAFT', notes: null,
  };
}

function blankConversion(baseUom: string, uoms: ProductMasterLookups['uoms']): UomConversion {
  return {
    from_uom_code: baseUom,
    to_uom_code: uoms.find((item) => item.code !== baseUom)?.code ?? baseUom,
    multiplier: '1', rounding_mode: 'HALF_UP',
  };
}

function blankPack(isDefault: boolean, uom: string): SkuPack {
  return { code: '', name: '', uom_code: uom, quantity: '1', barcode: null, is_default: isDefault };
}

function blankComponent(rows: RecipeComponent[], lookups?: ProductMasterLookups): RecipeComponent {
  return {
    component_sku_id: lookups?.skus.find((item) => item.status === 'ACTIVE')?.id ?? '',
    sequence_no: (rows.length + 1) * 10, quantity: '1', uom_code: lookups?.uoms[0]?.code ?? '',
    waste_percent: '0',
  };
}

function blankOperation(rows: RouteOperation[]): RouteOperation {
  return {
    sequence_no: (rows.length + 1) * 10, name: '', work_center_code: '', setup_minutes: '0',
    run_minutes_per_unit: '0', instructions: null,
  };
}

function blankParameter(rows: SpecificationParameter[]): SpecificationParameter {
  return {
    sequence_no: (rows.length + 1) * 10, code: '', name: '', value_type: 'NUMERIC', uom_code: null,
    minimum_value: null, target_value: '0', maximum_value: null, text_requirement: null,
    test_method: null, is_required: true,
  };
}

function resetParameterType(
  parameter: SpecificationParameter,
  valueType: SpecificationParameter['value_type'],
): Partial<SpecificationParameter> {
  if (valueType === 'NUMERIC') return {
    value_type: valueType, minimum_value: null, target_value: '0', maximum_value: null, text_requirement: null,
  };
  if (valueType === 'TEXT') return {
    value_type: valueType, minimum_value: null, target_value: null, maximum_value: null, text_requirement: '',
  };
  return {
    value_type: valueType, minimum_value: null, target_value: null, maximum_value: null, text_requirement: null,
  };
}

function toForm(detail: ProductMasterDetail): MasterForm {
  return {
    ...blankForm(),
    code: detail.code,
    name: detail.name,
    status: detail.status,
    description: detail.description ?? null,
    brand_id: detail.brand_id ?? null,
    item_type: detail.item_type ?? 'RAW_MATERIAL',
    base_uom: detail.base_uom ?? 'EA',
    shelf_life_days: detail.shelf_life_days === null || detail.shelf_life_days === undefined ? '' : String(detail.shelf_life_days),
    lot_controlled: detail.lot_controlled ?? true,
    catalog_item_id: detail.catalog_item_id ?? '',
    barcode: detail.barcode ?? null,
    pack_quantity: detail.pack_quantity ?? '1',
    pack_uom_code: detail.pack_uom_code ?? detail.base_uom ?? 'EA',
    revision: String(detail.revision ?? 1),
    output_sku_id: detail.output_sku_id ?? '',
    output_quantity: detail.output_quantity ?? '1',
    output_uom_code: detail.output_uom_code ?? 'EA',
    yield_percent: detail.yield_percent ?? '100',
    effective_from: detail.effective_from ?? null,
    effective_to: detail.effective_to ?? null,
    notes: detail.notes ?? null,
    target_type: detail.target_type ?? 'ITEM',
    sku_id: detail.sku_id ?? null,
    sampling_plan: detail.sampling_plan ?? null,
    agreements: detail.agreements?.map((row) => ({ ...row })) ?? [],
    conversions: detail.conversions?.map((row) => ({ ...row })) ?? [],
    packs: detail.packs?.map((row) => ({ ...row })) ?? [],
    components: detail.components?.map((row) => ({ ...row })) ?? [],
    operations: detail.operations?.map((row) => ({ ...row })) ?? [],
    parameters: detail.parameters?.map((row) => ({ ...row })) ?? [],
  };
}

function writePayload(resource: ProductMasterResource, form: MasterForm) {
  const common = { name: form.name.trim() };
  if (resource === 'brands') return {
    ...common,
    description: nullable(form.description),
    agreements: form.agreements.map((row) => ({
      ...row,
      agreement_number: row.agreement_number.trim().toUpperCase(),
      currency_code: row.currency_code.trim().toUpperCase(),
      notes: nullable(row.notes),
    })),
  };
  if (resource === 'items') return {
    ...common,
    brand_id: form.brand_id,
    item_type: form.item_type,
    base_uom: form.base_uom,
    description: nullable(form.description),
    shelf_life_days: form.shelf_life_days === '' ? null : Number(form.shelf_life_days),
    lot_controlled: form.lot_controlled,
    conversions: form.conversions.map((row) => ({ ...row })),
  };
  if (resource === 'skus') return {
    ...common,
    catalog_item_id: form.catalog_item_id,
    barcode: nullable(form.barcode),
    description: nullable(form.description),
    pack_quantity: form.pack_quantity,
    pack_uom_code: form.pack_uom_code,
    packs: form.packs.map((row) => ({ ...row, code: row.code.trim().toUpperCase(), barcode: nullable(row.barcode) })),
  };
  if (resource === 'recipes') return {
    ...common,
    revision: Number(form.revision),
    output_sku_id: form.output_sku_id,
    output_quantity: form.output_quantity,
    output_uom_code: form.output_uom_code,
    yield_percent: form.yield_percent,
    effective_from: form.effective_from,
    effective_to: form.effective_to,
    notes: nullable(form.notes),
    components: form.components.map((row) => ({ ...row })),
  };
  if (resource === 'routes') return {
    ...common,
    catalog_item_id: form.catalog_item_id,
    description: nullable(form.description),
    operations: form.operations.map((row) => ({
      ...row, name: row.name.trim(), work_center_code: row.work_center_code.trim().toUpperCase(),
      instructions: nullable(row.instructions),
    })),
  };
  return {
    ...common,
    target_type: form.target_type,
    catalog_item_id: form.target_type === 'ITEM' ? form.catalog_item_id : null,
    sku_id: form.target_type === 'SKU' ? form.sku_id : null,
    effective_from: form.effective_from,
    effective_to: form.effective_to,
    sampling_plan: nullable(form.sampling_plan),
    notes: nullable(form.notes),
    parameters: form.parameters.map((row) => ({
      ...row,
      code: row.code.trim().toUpperCase(),
      name: row.name.trim(),
      text_requirement: nullable(row.text_requirement),
      test_method: nullable(row.test_method),
    })),
  };
}

function parentFilterOptions(resource: ProductMasterResource, lookups?: ProductMasterLookups) {
  if (!lookups) return [];
  if (resource === 'items') return lookups.brands;
  if (resource === 'skus' || resource === 'routes') return lookups.items;
  if (resource === 'recipes') return lookups.skus;
  if (resource === 'specifications') return [...lookups.items, ...lookups.skus];
  return [];
}

function resourceSummary(resource: ProductMasterResource, record: ProductMasterDetail) {
  if (resource === 'items' || resource === 'skus') return display(record.item_type ?? 'unclassified');
  if (resource === 'specifications') return `${record.target_type ?? '—'} target`;
  if (resource === 'recipes') return `Revision ${record.revision ?? 1}`;
  return 'Company master';
}

function structureSummary(resource: ProductMasterResource, record: ProductMasterDetail) {
  if (resource === 'items') return `${record.base_uom ?? '—'} base UOM`;
  if (resource === 'skus') return `${record.pack_quantity ?? '—'} ${record.pack_uom_code ?? ''}`.trim();
  if (resource === 'recipes') return `${record.yield_percent ?? '—'}% yield`;
  return `version ${record.record_version}`;
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
  if (value.toUpperCase() === 'SKU') return 'SKU';
  if (value.toUpperCase() === 'SKUS') return 'SKUs';
  if (value.toUpperCase() === 'UOM') return 'UOM';
  return value.replaceAll('_', ' ').toLowerCase().replace(/^./, (letter) => letter.toUpperCase());
}

function formatDate(value: string) {
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(value));
}

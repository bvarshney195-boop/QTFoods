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
  previewRecipe,
  updateProductMaster,
  type BrandAgreement,
  type ProductMasterDetail,
  type ProductMasterLookups,
  type ProductMasterResource,
  type ProductMasterStatus,
  type ProductMasterWorkspace as ProductMasterWorkspaceData,
  type RecipeComponent,
  type RecipeCalculation,
  type RouteOperation,
  type SkuPack,
  type SpecificationParameter,
  type UomConversion,
} from '../api/productMasters';
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
import { ExpandableRegister, RegisterDetailGrid } from './ExpandableRegister';
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
  basis_quantity: string;
  basis_uom_code: string;
  calculation_model: 'LEGACY_YIELD' | 'WEIGHT_PIECE' | 'UNIT_CONVERSION' | 'PERCENT_YIELD';
  standard_piece_weight: string | null;
  piece_weight_uom_code: string | null;
  target_finished_weight: string | null;
  target_weight_uom_code: string | null;
  pieces_per_pack: string;
  handling_loss_percent: string;
  handling_loss_in_standard: boolean;
  transformation_loss_percent: string;
  transformation_loss_in_standard: boolean;
  rejection_allowance_percent: string;
  output_rounding_mode: 'DOWN' | 'UP' | 'HALF_UP' | 'NONE';
  unit_conversion_rate: string | null;
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

const productMasterStatuses: readonly ProductMasterStatus[] = ['DRAFT', 'ACTIVE', 'INACTIVE'];
const productMasterSorts = ['CODE', 'NAME', 'NEWEST', 'OLDEST'] as const;

export function ProductMasterWorkspace({ resource }: { resource: ProductMasterResource }) {
  const config = configs[resource];
  const initialRoute = useRef(readRegisterRouteParams(config.screen)).current;
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const scopeKey = `${contextKey}:${resource}`;
  const [workspace, setWorkspace] = useState<ProductMasterWorkspaceData | null>(null);
  const [selected, setSelected] = useState<ProductMasterDetail | null>(null);
  const [form, setForm] = useState<MasterForm>(() => blankForm());
  const initialSearch = readRouteString(initialRoute, 'q');
  const [searchDraft, setSearchDraft] = useState(initialSearch);
  const [search, setSearch] = useState(initialSearch);
  const [status, setStatus] = useState<ProductMasterStatus | ''>(() => readRouteChoice(initialRoute, 'status', [...productMasterStatuses, ''] as const, ''));
  const [type, setType] = useState(() => readRouteString(initialRoute, 'type'));
  const [parentId, setParentId] = useState(() => readRouteString(initialRoute, 'parent'));
  const [sort, setSort] = useState<(typeof productMasterSorts)[number]>(() => readRouteChoice(initialRoute, 'sort', productMasterSorts, 'CODE'));
  const [page, setPage] = useState(() => readRoutePage(initialRoute));
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
  const mountedContextKey = useRef(scopeKey);
  const registerRef = useRef<HTMLElement>(null);
  const editorRef = useRef<HTMLElement>(null);
  const handledCardRoute = useRef('');

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
    if (mountedContextKey.current === scopeKey) return;
    mountedContextKey.current = scopeKey;
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
  }, [scopeKey, resource]);

  useEffect(() => { void refresh(); }, [refresh]);

  useEffect(() => {
    if (!workspace || workspace.resource !== resource) return;
    const formKey = `${contextKey}:${resource}`;
    if (loadedWorkspaceKey.current !== formKey) return;
    if (initialisedFormKey.current === formKey) return;
    initialisedFormKey.current = formKey;
    if (selectedId.current === null) setForm(blankForm(resource, workspace.lookups));
  }, [contextKey, resource, workspace]);

  const cardNavigation = useEditCardNavigation(config.screen, {
    q: search || undefined,
    status: status || undefined,
    type: type || undefined,
    parent: parentId || undefined,
    sort: sort === 'CODE' ? undefined : sort,
    page: page === 1 ? undefined : page,
  });

  useEffect(() => {
    const routeKey = `${scopeKey}:${cardNavigation.route.mode}:${cardNavigation.route.recordId ?? ''}`;
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
    setForm(blankForm(resource, workspace?.lookups));
    clearFeedback();
    if (hadPriorRoute && window.location.hash.startsWith(`#${config.screen}`)) {
      window.requestAnimationFrame(() => revealRegister(registerRef.current));
    }
  }, [cardNavigation.route.mode, cardNavigation.route.recordId, scopeKey]);

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
    const wasEditing = Boolean(selected);
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
      if (!wasEditing) cardNavigation.replaceWithEditCard(result.id);
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
      cardNavigation.returnToRegister();
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

  const hasSavePermission = cardNavigation.route.mode === 'create'
    ? workspace?.allowed_actions.includes('CREATE')
    : selected?.allowed_actions.includes('UPDATE') ?? false;
  const requiresUoms = resource === 'items' || resource === 'skus' || resource === 'recipes';
  const missingRequiredUoms = Boolean(workspace && requiresUoms && workspace.lookups.uoms.length === 0);
  const typeOptions = resource === 'specifications'
    ? workspace?.lookups.spec_target_types ?? []
    : resource === 'items' || resource === 'skus'
      ? workspace?.lookups.item_types ?? []
      : [];
  const parentOptions = parentFilterOptions(resource, workspace?.lookups);
  const missingRequiredSkus = Boolean(resource === 'recipes' && workspace && workspace.lookups.skus.length === 0);
  const sessionPermissions = session.granted_permissions ?? session.allowed_actions;
  const canCreateSku = session.allowed_screens.includes('MD-SKU')
    && sessionPermissions.includes('ACTION:MD-SKU:CREATE');
  const canSave = Boolean(hasSavePermission && !missingRequiredUoms && !missingRequiredSkus);

  return (
    <>
      <PageHeader
        code={config.screen}
        batch={config.batch}
        title={config.title}
        description={config.description}
        onNew={workspace?.allowed_actions.includes('CREATE') ? cardNavigation.openCreateCard : undefined}
      />
      <div className="live-notice product-master-notice"><span></span><b>Controlled product master</b>{config.notice}</div>

      <div className="kpi-grid party-kpis">
        <div className="kpi"><span>Total</span><b>{loading && !workspace ? '-' : workspace?.summary.total ?? 0}</b><small>{config.plural} in company</small></div>
        <div className="kpi"><span>Active</span><b>{loading && !workspace ? '-' : workspace?.summary.active ?? 0}</b><small>available downstream</small></div>
        <div className="kpi"><span>Draft</span><b>{loading && !workspace ? '-' : workspace?.summary.draft ?? 0}</b><small>not operational</small></div>
        <div className="kpi"><span>Inactive</span><b>{loading && !workspace ? '-' : workspace?.summary.inactive ?? 0}</b><small>retained history</small></div>
      </div>

      <div className="module-grid admin-workspace party-workspace product-master-workspace">
        <section className="panel" ref={registerRef}>
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
          {workspace && workspace.data.length > 0 && <div className={loading ? 'is-refreshing' : undefined}>
            <ExpandableRegister
              ariaLabel={`${display(config.plural)} register`}
              records={workspace.data}
              getRecordId={(record) => record.id}
              getRecordLabel={(record) => `${record.code} ${record.name}`}
              selectedId={selected?.id}
              columnsTemplate="minmax(190px,2fr) minmax(160px,1.4fr) minmax(130px,1fr) minmax(100px,.75fr) minmax(110px,.8fr)"
              columns={[
                { key: 'record', heading: display(config.noun), render: (record) => <><b>{record.name}</b><small>{record.code}</small></> },
                { key: 'parent', heading: 'Parent / target', render: (record) => <>{record.parent?.name ?? '—'}<small>{record.parent?.code ?? resourceSummary(resource, record)}</small></> },
                { key: 'structure', heading: 'Structure', render: (record) => <>{record.child_count} {display(record.child_label)}<small>{structureSummary(resource, record)}</small></> },
                { key: 'status', heading: 'Status', render: (record) => <StatusBadge status={record.status} /> },
                { key: 'updated', heading: 'Updated', render: (record) => formatDate(record.updated_at) },
              ]}
              renderExpanded={(record) => <ProductMasterRegisterDetail resource={resource} record={record} onOpen={() => cardNavigation.openEditCard(record.id)} />}
            />
          </div>}
          {workspace && <div className="pagination"><button type="button" disabled={page <= 1 || loading} onClick={() => setPage((value) => value - 1)}>Previous</button><span>Page {workspace.meta.current_page} of {workspace.meta.last_page} · {workspace.meta.total} records</span><button type="button" disabled={page >= workspace.meta.last_page || loading} onClick={() => setPage((value) => value + 1)}>Next</button></div>}
        </section>

        {cardNavigation.route.mode !== 'register' && <aside className="panel admin-editor party-editor product-master-editor edit-card" ref={editorRef}>
          <div className="panel-head"><div><h3 tabIndex={-1}>{selected ? `Edit ${selected.code}` : cardNavigation.route.mode === 'edit' ? `Open ${config.noun}` : `New ${config.noun}`}</h3><span>{detailLoading ? 'Loading detail...' : selected ? `Version ${selected.record_version}` : 'Company scoped'}</span></div><div className="edit-card-navigation">{selected && <StatusBadge status={selected.status} />}<button className="secondary compact-button" type="button" onClick={cardNavigation.returnToRegister}>Back to register</button></div></div>
          <form className="panel-body party-form" onSubmit={save} noValidate>
            {error && workspace && <div className="form-error" role="alert"><span>{error}</span></div>}
            {missingRequiredUoms && <div className="form-error" role="alert"><span>Units of measure are not configured. Ask an administrator to apply the required reference data before creating this {config.noun}.</span></div>}
            {success && <div className="form-success" role="status"><span></span>{success}</div>}
            <fieldset className="party-form-fields" disabled={busy || detailLoading || !hasSavePermission}>
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
                recordId={selected?.id}
                form={form}
                setForm={setForm}
                lookups={workspace?.lookups}
                fieldErrors={fieldErrors}
                lookupLoading={loading}
                canCreateSku={canCreateSku}
                onRefreshLookups={() => void refresh()}
                resetFeedback={resetCommandFeedback}
              />
            </fieldset>
              <div className="form-actions">
                {selected && session.roles.includes('ERP_ADMIN') && <button className="danger-button" type="button" disabled={busy || detailLoading} onClick={() => { clearFeedback(); setPendingDelete(selected); }}>Delete entry</button>}
                <button className="secondary" type="button" disabled={busy} onClick={cardNavigation.returnToRegister}>Cancel</button>
                <button className="primary" type="submit" disabled={busy || detailLoading || !canSave}>{busy ? 'Saving...' : selected ? `Save ${config.noun}` : `Create ${config.noun}`}</button>
              </div>
          </form>

          {selected && selected.allowed_actions.includes('CHANGE_STATUS') && selected.allowed_statuses.length > 0 && <form className="party-lifecycle" onSubmit={submitLifecycle}>
            <div><h4>Lifecycle control</h4><small>{selected.status_change ? `${selected.status_change.reason ?? 'Status changed'} · ${formatDate(selected.status_change.changed_at)}` : 'No prior lifecycle transition recorded.'}</small></div>
            <label>Target status<select aria-label={`Target ${config.noun} status`} value={targetStatus} disabled={busy} onChange={(event) => { setTargetStatus(event.target.value as ProductMasterStatus); lifecycleKey.current = null; }}>{selected.allowed_statuses.map((item) => <option key={item}>{item}</option>)}</select></label>
            <label>Reason<textarea aria-label={`${display(config.noun)} status reason`} rows={2} minLength={3} maxLength={2000} value={statusReason} disabled={busy} onChange={(event) => { setStatusReason(event.target.value); lifecycleKey.current = null; }} /></label>
            <FieldError value={fieldErrors.target_status ?? fieldErrors.reason} />
            <button className="secondary" type="submit" disabled={busy || statusReason.trim().length < 3}>Apply status</button>
          </form>}
        </aside>}

        {resource === 'items' && workspace && <ItemReferenceRegisters
          itemTypes={workspace.lookups.item_types}
          uoms={workspace.lookups.uoms}
          onFilterType={(itemType) => {
            setType(itemType);
            setPage(1);
            window.requestAnimationFrame(() => revealRegister(registerRef.current));
          }}
        />}
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

function ProductMasterRegisterDetail({
  resource,
  record,
  onOpen,
}: {
  resource: ProductMasterResource;
  record: ProductMasterDetail;
  onOpen: () => void;
}) {
  const canUpdate = record.allowed_actions.includes('UPDATE');
  const lifecycle = record.status_change
    ? `${record.status_change.reason ?? 'Status changed'} · ${formatDate(record.status_change.changed_at)}${record.status_change.changed_by ? ` · ${record.status_change.changed_by.name}` : ''}`
    : 'No lifecycle transition recorded';
  const note = record.description ?? record.notes ?? 'No description recorded.';

  return (
    <>
      <div className="register-detail-heading">
        <div><span>{display(resource.slice(0, -1))} record</span><h4>{record.name}</h4><p>{record.code}</p></div>
        <StatusBadge status={record.status} />
      </div>
      <RegisterDetailGrid facts={[
        { label: 'Classification', value: resourceSummary(resource, record) },
        { label: 'Linked entity', value: record.parent ? `${record.parent.code} · ${record.parent.name}` : 'No parent / target' },
        { label: 'Structure', value: `${record.child_count} ${display(record.child_label)} · ${structureSummary(resource, record)}` },
        ...productSpecificFacts(resource, record),
        { label: 'Record version', value: `v${record.record_version}` },
        { label: 'Created', value: formatDate(record.created_at) },
        { label: 'Last updated', value: formatDate(record.updated_at) },
        { label: 'Lifecycle history', value: lifecycle, wide: true },
        { label: 'Description / notes', value: note, wide: true },
      ]} />
      <div className="register-detail-actions">
        <span>{canUpdate ? 'Editing is permitted for your role.' : 'Your role has read-only access to this record.'}</span>
        <button className="primary" type="button" onClick={onOpen}>{canUpdate ? 'Open edit card' : 'Open read-only card'}</button>
      </div>
    </>
  );
}

function ItemReferenceRegisters({
  itemTypes,
  uoms,
  onFilterType,
}: {
  itemTypes: string[];
  uoms: ProductMasterLookups['uoms'];
  onFilterType: (itemType: string) => void;
}) {
  return (
    <section className="panel item-reference-registers">
      <div className="panel-head"><div><h3>Product categories & UOM reference</h3><span>Protected database reference data used by item cards</span></div></div>
      <div className="reference-register-block">
        <div className="subsection-head"><div><b>Product categories / classifications</b><small>Raw materials, finished goods, packaging, intermediates, and services</small></div></div>
        <ExpandableRegister
          ariaLabel="Product category reference register"
          records={itemTypes}
          getRecordId={(itemType) => itemType}
          getRecordLabel={(itemType) => display(itemType)}
          columnsTemplate="minmax(230px,1.6fr) minmax(180px,1fr)"
          columns={[
            { key: 'classification', heading: 'Classification', render: (itemType) => <><b>{display(itemType)}</b><small>{itemType}</small></> },
            { key: 'scope', heading: 'Scope', render: () => 'Catalog items and SKUs' },
          ]}
          renderExpanded={(itemType) => <>
            <div className="register-detail-heading"><div><span>Governed classification</span><h4>{display(itemType)}</h4><p>Database-enforced item type</p></div><span className="status status-ok">Available</span></div>
            <RegisterDetailGrid facts={[
              { label: 'Stored value', value: itemType },
              { label: 'Linked entities', value: 'Catalog items and stock-bearing SKUs' },
              { label: 'Lifecycle', value: 'Controlled through each linked item or SKU' },
              { label: 'Edit policy', value: 'Reference choices are database constrained and have no independent edit card' },
            ]} />
            <div className="register-detail-actions"><span>Use this category as the current Item register filter.</span><button className="secondary" type="button" onClick={() => onFilterType(itemType)}>View matching items</button></div>
          </>}
        />
      </div>
      <div className="reference-register-block">
        <div className="subsection-head"><div><b>Units of measure</b><small>Global UOM codes available to item, pack, recipe, and quality records</small></div></div>
        <ExpandableRegister
          ariaLabel="UOM reference register"
          records={uoms}
          getRecordId={(uom) => uom.code}
          getRecordLabel={(uom) => `${uom.code} ${uom.name}`}
          columnsTemplate="minmax(150px,.8fr) minmax(230px,1.4fr) minmax(150px,.8fr)"
          columns={[
            { key: 'code', heading: 'UOM', render: (uom) => <b>{uom.code}</b> },
            { key: 'name', heading: 'Name', render: (uom) => uom.name },
            { key: 'precision', heading: 'Precision', render: (uom) => `${uom.precision} decimal places` },
          ]}
          renderExpanded={(uom) => <>
            <div className="register-detail-heading"><div><span>Global reference</span><h4>{uom.name}</h4><p>{uom.code}</p></div><span className="status status-ok">Available</span></div>
            <RegisterDetailGrid facts={[
              { label: 'Code', value: uom.code },
              { label: 'Decimal precision', value: uom.precision },
              { label: 'Linked entities', value: 'Items, SKU packs, recipes, specifications, and inventory positions', wide: true },
              { label: 'Edit policy', value: 'Reference-only: this API has no UOM record version or authorised edit-card endpoint', wide: true },
            ]} />
            <div className="register-detail-actions"><span>UOM changes remain migration-controlled to protect inventory and conversion integrity.</span></div>
          </>}
        />
      </div>
    </section>
  );
}

function ResourceEditor({
  resource,
  recordId,
  form,
  setForm,
  lookups,
  fieldErrors,
  lookupLoading,
  canCreateSku,
  onRefreshLookups,
  resetFeedback,
}: {
  resource: ProductMasterResource;
  recordId?: string;
  form: MasterForm;
  setForm: Dispatch<SetStateAction<MasterForm>>;
  lookups?: ProductMasterLookups;
  fieldErrors: Record<string, string>;
  lookupLoading: boolean;
  canCreateSku: boolean;
  onRefreshLookups: () => void;
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
      <h4>Basis, output and yield model</h4>
      <div className="party-card-grid three">
        {lookups?.skus.length === 0 && <div className="routing-warning recipe-sku-prerequisite" role="status">
          <b>No output SKU is configured for this company.</b>
          <span>An output SKU is the stock-bearing finished or intermediate product made by this recipe. Create it in SKUs &amp; Packs, then refresh this list.</span>
          <div>
            <button className="secondary compact-button" type="button" disabled={lookupLoading} onClick={onRefreshLookups}>{lookupLoading ? 'Refreshing...' : 'Refresh SKU list'}</button>
            {canCreateSku && <a className="secondary compact-button" href="#MD-SKU?card=new" target="_blank" rel="noreferrer">Create SKU in new tab</a>}
          </div>
        </div>}
        <label className="wide">Output SKU<select aria-label="Recipe output SKU" value={form.output_sku_id} onChange={(event) => change({ output_sku_id: event.target.value })}><option value="">{lookups?.skus.length === 0 ? 'No SKUs configured' : 'Select output SKU'}</option>{options(lookups?.skus)}</select><small>The finished or intermediate stock unit produced by this recipe.</small><FieldError value={fieldErrors.output_sku_id} /></label>
        <label>Revision<input aria-label="Recipe revision" type="number" min="1" value={form.revision} onChange={(event) => change({ revision: event.target.value })} /></label>
        <label>Calculation model<select aria-label="Recipe calculation model" value={form.calculation_model} onChange={(event) => change({ calculation_model: event.target.value as MasterForm['calculation_model'] })}><option value="LEGACY_YIELD">Legacy yield</option><option value="WEIGHT_PIECE">Weight-based pieces</option><option value="UNIT_CONVERSION">Approved unit conversion</option><option value="PERCENT_YIELD">Percentage yield</option></select></label>
        <label>Recipe basis quantity<input aria-label="Recipe basis quantity" type="number" min="0" step="any" value={form.basis_quantity} onChange={(event) => change({ basis_quantity: event.target.value })} /><FieldError value={fieldErrors.basis_quantity} /></label>
        <label>Recipe basis UOM<select aria-label="Recipe basis UOM" value={form.basis_uom_code} onChange={(event) => change({ basis_uom_code: event.target.value })}>{requiredUomOptions()}</select></label>
        <label>Output quantity<input aria-label="Recipe output quantity" type="number" min="0" step="any" value={form.output_quantity} onChange={(event) => change({ output_quantity: event.target.value })} /></label>
        <label>Output UOM<select aria-label="Recipe output UOM" value={form.output_uom_code} onChange={(event) => change({ output_uom_code: event.target.value })}>{requiredUomOptions()}</select></label>
        <label>Yield %<input aria-label="Recipe yield percent" type="number" min="0" max="100" step="any" value={form.yield_percent} onChange={(event) => change({ yield_percent: event.target.value })} /></label>
        {form.calculation_model === 'WEIGHT_PIECE' && <>
          <label>Pre-process piece weight<input aria-label="Standard pre-process piece weight" type="number" min="0" step="any" value={form.standard_piece_weight ?? ''} onChange={(event) => change({ standard_piece_weight: nullable(event.target.value) })} /></label>
          <label>Piece-weight UOM<select aria-label="Piece weight UOM" value={form.piece_weight_uom_code ?? ''} onChange={(event) => change({ piece_weight_uom_code: nullable(event.target.value) })}>{requiredUomOptions()}</select></label>
        </>}
        {(form.calculation_model === 'UNIT_CONVERSION' || form.calculation_model === 'PERCENT_YIELD') && <label>Approved conversion / yield rate<input aria-label="Recipe unit conversion rate" type="number" min="0" step="any" value={form.unit_conversion_rate ?? ''} onChange={(event) => change({ unit_conversion_rate: nullable(event.target.value) })} /></label>}
        <label>Finished target weight<input aria-label="Finished target weight" type="number" min="0" step="any" value={form.target_finished_weight ?? ''} onChange={(event) => change({ target_finished_weight: nullable(event.target.value) })} /></label>
        <label>Target-weight UOM<select aria-label="Target weight UOM" value={form.target_weight_uom_code ?? ''} onChange={(event) => change({ target_weight_uom_code: nullable(event.target.value) })}>{requiredUomOptions()}</select></label>
        <label>Pieces per pack<input aria-label="Pieces per pack" type="number" min="1" step="1" value={form.pieces_per_pack} onChange={(event) => change({ pieces_per_pack: event.target.value })} /></label>
        <label>Handling loss %<input aria-label="Handling loss percent" type="number" min="0" max="100" step="any" value={form.handling_loss_percent} onChange={(event) => change({ handling_loss_percent: event.target.value })} /></label>
        <label className="check-label"><input aria-label="Handling loss included in standard" type="checkbox" checked={form.handling_loss_in_standard} onChange={(event) => change({ handling_loss_in_standard: event.target.checked })} />Handling loss already in standard</label>
        <label>Transformation / moisture loss %<input aria-label="Transformation loss percent" type="number" min="0" max="100" step="any" value={form.transformation_loss_percent} onChange={(event) => change({ transformation_loss_percent: event.target.value })} /></label>
        <label className="check-label"><input aria-label="Transformation loss included in standard" type="checkbox" checked={form.transformation_loss_in_standard} onChange={(event) => change({ transformation_loss_in_standard: event.target.checked })} />Transformation loss already in standard</label>
        <label>Rejection allowance %<input aria-label="Rejection allowance percent" type="number" min="0" max="100" step="any" value={form.rejection_allowance_percent} onChange={(event) => change({ rejection_allowance_percent: event.target.value })} /></label>
        <label>Output rounding<select aria-label="Output rounding mode" value={form.output_rounding_mode} onChange={(event) => change({ output_rounding_mode: event.target.value as MasterForm['output_rounding_mode'] })}><option>DOWN</option><option>UP</option><option>HALF_UP</option><option>NONE</option></select></label>
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
          <label>Scaling method<select aria-label={`Component scaling method ${index + 1}`} value={row.scaling_method} onChange={(event) => change({ components: update(form.components, index, { scaling_method: event.target.value as RecipeComponent['scaling_method'] }) })}><option>SCALABLE</option><option>FIXED</option><option>PER_UNIT</option><option>CONDITIONAL</option></select></label>
          <label>Baker's %<input aria-label={`Component baker percentage ${index + 1}`} type="number" min="0" step="any" value={row.bakers_percentage ?? ''} onChange={(event) => change({ components: update(form.components, index, { bakers_percentage: nullable(event.target.value) }) })} /></label>
          {(row.scaling_method === 'PER_UNIT' || row.is_packaging) && <label>Quantity per output unit<input aria-label={`Component quantity per unit ${index + 1}`} type="number" min="0" step="any" value={row.quantity_per_unit ?? ''} onChange={(event) => change({ components: update(form.components, index, { quantity_per_unit: nullable(event.target.value) }) })} /></label>}
          {row.scaling_method === 'CONDITIONAL' && <label>Condition code<input aria-label={`Component condition code ${index + 1}`} value={row.condition_code ?? ''} onChange={(event) => change({ components: update(form.components, index, { condition_code: nullable(event.target.value.toUpperCase()) }) })} /></label>}
          <label className="check-label"><input aria-label={`Component basis ingredient ${index + 1}`} type="checkbox" checked={row.is_basis_component} onChange={(event) => change({ components: update(form.components, index, { is_basis_component: event.target.checked }) })} />Baker's-percentage basis ingredient</label>
          <label className="check-label"><input aria-label={`Component loss included ${index + 1}`} type="checkbox" checked={row.loss_included_in_standard} onChange={(event) => change({ components: update(form.components, index, { loss_included_in_standard: event.target.checked }) })} />Loss already included</label>
          <label className="check-label"><input aria-label={`Component packaging ${index + 1}`} type="checkbox" checked={row.is_packaging} onChange={(event) => change({ components: update(form.components, index, { is_packaging: event.target.checked }) })} />Packaging material</label>
        </div>
      </div>)}
    </section>
    <RecipePreviewPanel recordId={recordId} form={form} />
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

function RecipePreviewPanel({ recordId, form }: { recordId?: string; form: MasterForm }) {
  const [batchBasis, setBatchBasis] = useState(form.basis_quantity);
  const [plannedUnits, setPlannedUnits] = useState(form.output_quantity);
  const [conditions, setConditions] = useState('');
  const [addition, setAddition] = useState('0');
  const [result, setResult] = useState<RecipeCalculation | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  useEffect(() => {
    setBatchBasis(form.basis_quantity);
    setPlannedUnits(form.output_quantity);
    setResult(null);
    setError(null);
  }, [recordId]);

  async function calculate() {
    if (!recordId) return;
    setBusy(true);
    setError(null);
    try {
      setResult(await previewRecipe(recordId, {
        batch_basis_quantity: batchBasis,
        planned_units: plannedUnits || null,
        active_conditions: conditions.split(',').map((value) => value.trim().toUpperCase()).filter(Boolean),
        measured_additions: Number(addition) > 0
          ? [{ quantity: addition, uom_code: form.basis_uom_code }]
          : [],
      }));
    } catch (caught) {
      setResult(null);
      setError(apiMessage(caught, 'Unable to calculate this recipe preview.'));
    } finally {
      setBusy(false);
    }
  }

  return <section className="party-subsection">
    <div className="subsection-head"><div><h4>Deterministic yield preview</h4><small>Uses the last saved recipe revision; save edits before recalculating.</small></div></div>
    {!recordId ? <div className="empty-state compact">Create the recipe before running a batch-scale preview.</div> : <>
      <div className="party-card-grid three">
        <label>Batch basis ({form.basis_uom_code})<input aria-label="Preview batch basis quantity" type="number" min="0" step="any" value={batchBasis} onChange={(event) => setBatchBasis(event.target.value)} /></label>
        <label>Planned output units<input aria-label="Preview planned output units" type="number" min="0" step="any" value={plannedUnits} onChange={(event) => setPlannedUnits(event.target.value)} /></label>
        <label>Measured additions ({form.basis_uom_code})<input aria-label="Preview measured additions" type="number" min="0" step="any" value={addition} onChange={(event) => setAddition(event.target.value)} /></label>
        <label className="wide">Active condition codes<input aria-label="Preview active condition codes" placeholder="e.g. VEGAN, EXPORT" value={conditions} onChange={(event) => setConditions(event.target.value)} /></label>
        <button className="secondary" type="button" disabled={busy || Number(batchBasis) <= 0} onClick={() => void calculate()}>{busy ? 'Calculating…' : 'Calculate preview'}</button>
      </div>
      {error && <div className="form-error" role="alert"><span>{error}</span></div>}
      {result && <>
        <RegisterDetailGrid facts={[
          { label: 'Scaling factor', value: result.scaling_factor },
          { label: 'Theoretical input mass', value: `${result.theoretical_input_mass} ${result.recipe.basis_uom_code}` },
          { label: 'Theoretical complete / residual', value: `${result.theoretical_complete_units} / ${result.theoretical_residual_quantity}` },
          { label: 'Expected formed units', value: result.expected_formed_units },
          { label: 'Expected good units', value: result.expected_good_units },
          { label: 'Rounding / exception', value: `${result.rounding_policy}${result.exception_code ? ` · ${result.exception_code}` : ''}` },
        ]} />
        <div className="table-scroll"><table className="data-table"><thead><tr><th>Component</th><th>Method</th><th>Requirement</th><th>Input mass</th><th>Flags</th></tr></thead><tbody>{result.requirements.map((line) => <tr key={line.component_id}><td><b>{line.component_code}</b><small>{line.component_name}</small></td><td>{display(line.scaling_method)}</td><td>{line.calculated_quantity} {line.uom_code}</td><td>{line.normalised_input_mass}</td><td>{[line.is_packaging ? 'Packaging' : null, line.condition_applied ? 'Condition active' : null, line.loss_gross_up_applied ? 'Loss gross-up' : null].filter(Boolean).join(' · ') || 'Standard'}</td></tr>)}</tbody></table></div>
      </>}
    </>}
  </section>;
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
    basis_quantity: '1', basis_uom_code: weightUom, calculation_model: 'LEGACY_YIELD',
    standard_piece_weight: null, piece_weight_uom_code: weightUom, target_finished_weight: null,
    target_weight_uom_code: weightUom, pieces_per_pack: '1', handling_loss_percent: '0',
    handling_loss_in_standard: false, transformation_loss_percent: '0', transformation_loss_in_standard: true,
    rejection_allowance_percent: '0', output_rounding_mode: 'DOWN', unit_conversion_rate: null,
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
    waste_percent: '0', scaling_method: 'SCALABLE', bakers_percentage: null, is_basis_component: false,
    condition_code: null, quantity_per_unit: null, loss_included_in_standard: false, is_packaging: false,
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
    basis_quantity: detail.basis_quantity ?? detail.output_quantity ?? '1',
    basis_uom_code: detail.basis_uom_code ?? detail.output_uom_code ?? 'KG',
    calculation_model: detail.calculation_model ?? 'LEGACY_YIELD',
    standard_piece_weight: detail.standard_piece_weight ?? null,
    piece_weight_uom_code: detail.piece_weight_uom_code ?? detail.basis_uom_code ?? 'KG',
    target_finished_weight: detail.target_finished_weight ?? null,
    target_weight_uom_code: detail.target_weight_uom_code ?? detail.basis_uom_code ?? 'KG',
    pieces_per_pack: String(detail.pieces_per_pack ?? 1),
    handling_loss_percent: detail.handling_loss_percent ?? '0',
    handling_loss_in_standard: detail.handling_loss_in_standard ?? false,
    transformation_loss_percent: detail.transformation_loss_percent ?? '0',
    transformation_loss_in_standard: detail.transformation_loss_in_standard ?? true,
    rejection_allowance_percent: detail.rejection_allowance_percent ?? '0',
    output_rounding_mode: detail.output_rounding_mode ?? 'DOWN',
    unit_conversion_rate: detail.unit_conversion_rate ?? null,
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
    basis_quantity: form.basis_quantity,
    basis_uom_code: form.basis_uom_code,
    calculation_model: form.calculation_model,
    standard_piece_weight: form.calculation_model === 'WEIGHT_PIECE' ? form.standard_piece_weight : null,
    piece_weight_uom_code: form.calculation_model === 'WEIGHT_PIECE' ? form.piece_weight_uom_code : null,
    target_finished_weight: form.target_finished_weight,
    target_weight_uom_code: form.target_finished_weight ? form.target_weight_uom_code : null,
    pieces_per_pack: Number(form.pieces_per_pack),
    handling_loss_percent: form.handling_loss_percent,
    handling_loss_in_standard: form.handling_loss_in_standard,
    transformation_loss_percent: form.transformation_loss_percent,
    transformation_loss_in_standard: form.transformation_loss_in_standard,
    rejection_allowance_percent: form.rejection_allowance_percent,
    output_rounding_mode: form.output_rounding_mode,
    unit_conversion_rate: form.calculation_model === 'UNIT_CONVERSION' || form.calculation_model === 'PERCENT_YIELD'
      ? form.unit_conversion_rate : null,
    effective_from: form.effective_from,
    effective_to: form.effective_to,
    notes: nullable(form.notes),
    components: form.components.map((row) => ({ ...row, condition_code: nullable(row.condition_code) })),
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
  if (resource === 'recipes') return `${display(record.calculation_model ?? 'LEGACY_YIELD')} · ${record.basis_quantity ?? '—'} ${record.basis_uom_code ?? ''}`.trim();
  return `version ${record.record_version}`;
}

function productSpecificFacts(
  resource: ProductMasterResource,
  record: ProductMasterDetail,
): Array<{ label: string; value: string; wide?: boolean }> {
  if (resource === 'brands') return [
    { label: 'Commercial links', value: `${record.child_count} governed agreement(s)` },
  ];
  if (resource === 'items') return [
    { label: 'Base UOM', value: record.base_uom ?? 'Not assigned' },
    {
      label: 'Shelf life & lot control',
      value: `${record.shelf_life_days ?? 'No'} days shelf life · ${record.lot_controlled ? 'Lot controlled' : 'Not lot controlled'}`,
    },
  ];
  if (resource === 'skus') return [
    { label: 'Selling pack', value: `${record.pack_quantity ?? '—'} ${record.pack_uom_code ?? record.base_uom ?? ''}`.trim() },
    { label: 'Barcode', value: record.barcode ?? 'Not recorded' },
  ];
  if (resource === 'recipes') return [
    { label: 'Output', value: `${record.output_quantity ?? '—'} ${record.output_uom_code ?? ''}`.trim() },
    { label: 'Basis / model', value: `${record.basis_quantity ?? '—'} ${record.basis_uom_code ?? ''} · ${display(record.calculation_model ?? 'LEGACY_YIELD')}`.trim() },
    { label: 'Yield / loss standards', value: `${record.yield_percent ?? '—'}% yield · ${record.handling_loss_percent ?? '0'}% handling · ${record.rejection_allowance_percent ?? '0'}% rejection` },
    { label: 'Effective period', value: `${record.effective_from ?? 'Open'} to ${record.effective_to ?? 'open ended'}` },
  ];
  if (resource === 'routes') return [
    { label: 'Production steps', value: `${record.child_count} ordered operation(s)` },
  ];
  return [
    { label: 'Target type', value: record.target_type ?? 'Not assigned' },
    { label: 'Effective period', value: `${record.effective_from ?? 'Open'} to ${record.effective_to ?? 'open ended'}` },
    { label: 'Sampling plan', value: record.sampling_plan ?? 'Not recorded' },
  ];
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

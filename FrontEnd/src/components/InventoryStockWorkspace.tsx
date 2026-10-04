import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { isApiError } from '../api/client';
import {
  changeInventoryLotStatus,
  changeInventoryOwnerStatus,
  createInventoryLot,
  createInventoryOwner,
  getInventoryLot,
  getInventoryOwner,
  getStockPosition,
  listInventoryLots,
  listInventoryOwners,
  listStock,
  releaseStockReservation,
  reserveStock,
  updateInventoryLot,
  updateInventoryOwner,
  type InventoryLot,
  type InventoryOwner,
  type LotWorkspace,
  type OwnerWorkspace,
  type PageMeta,
  type Reference,
  type StockPosition,
  type StockReservation,
  type StockWorkspace,
} from '../api/inventoryFoundation';
import {
  getInventoryMovement,
  listInventoryMovements,
  type InventoryMovement,
  type InventoryMovementWorkspace,
} from '../api/inventoryOperations';
import { useErpSession } from '../app/ErpSessionContext';
import { PageHeader } from './PageHeader';
import { StatusBadge } from './StatusBadge';

type Tab = 'stock' | 'owners' | 'lots' | 'movements';
type OwnerForm = {
  code: string;
  name: string;
  owner_type: InventoryOwner['owner_type'];
  party_id: string;
  status: 'DRAFT' | 'ACTIVE';
};
type LotForm = {
  internal_lot_code: string;
  item_id: string;
  supplier_party_id: string;
  supplier_lot_code: string;
  origin_type: InventoryLot['origin_type'];
  manufacture_date: string;
  expiry_date: string;
  notes: string;
  status: 'DRAFT' | 'ACTIVE';
};

export function InventoryStockWorkspace() {
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const [tab, setTab] = useState<Tab>('stock');
  const [stock, setStock] = useState<StockWorkspace | null>(null);
  const [owners, setOwners] = useState<OwnerWorkspace | null>(null);
  const [lots, setLots] = useState<LotWorkspace | null>(null);
  const [movements, setMovements] = useState<InventoryMovementWorkspace | null>(null);
  const [selectedStock, setSelectedStock] = useState<StockPosition | null>(null);
  const [selectedOwner, setSelectedOwner] = useState<InventoryOwner | null>(null);
  const [selectedLot, setSelectedLot] = useState<InventoryLot | null>(null);
  const [selectedMovement, setSelectedMovement] = useState<InventoryMovement | null>(null);
  const [ownerForm, setOwnerForm] = useState<OwnerForm>(() => blankOwner());
  const [lotForm, setLotForm] = useState<LotForm>(() => blankLot());
  const [searchDraft, setSearchDraft] = useState('');
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [type, setType] = useState('');
  const [secondary, setSecondary] = useState('');
  const [sort, setSort] = useState('');
  const [includeZero, setIncludeZero] = useState(false);
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [targetStatus, setTargetStatus] = useState('ACTIVE');
  const [statusReason, setStatusReason] = useState('');
  const [reservationNumber, setReservationNumber] = useState('');
  const [reservationQuantity, setReservationQuantity] = useState('');
  const [reservationPurpose, setReservationPurpose] = useState('');
  const [releaseReason, setReleaseReason] = useState('');
  const commandKey = useRef<string | null>(null);
  const lifecycleKey = useRef<string | null>(null);
  const reservationKey = useRef<string | null>(null);
  const releaseKey = useRef<string | null>(null);
  const selectedId = useRef<string | null>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      if (tab === 'stock') {
        setStock(await listStock({
          page,
          q: search || undefined,
          availability: status || undefined,
          quality_status: type || undefined,
          owner_id: secondary || undefined,
          sort: sort || 'SKU',
          include_zero: includeZero ? 1 : undefined,
        }));
      } else if (tab === 'owners') {
        setOwners(await listInventoryOwners({
          page, q: search || undefined, status: status || undefined,
          owner_type: type || undefined, sort: sort || 'CODE',
        }));
      } else if (tab === 'lots') {
        setLots(await listInventoryLots({
          page, q: search || undefined, status: status || undefined,
          origin_type: type || undefined, expiry: secondary || undefined, sort: sort || 'CODE',
        }));
      } else {
        setMovements(await listInventoryMovements({
          page, q: search || undefined, direction: status || undefined,
          movement_type: type || undefined, sort: sort || 'NEWEST',
        }));
      }
    } catch (caught) {
      setError(apiMessage(caught, `Unable to load ${tab}.`));
    } finally {
      setLoading(false);
    }
  }, [contextKey, tab, page, search, status, type, secondary, sort, includeZero]);

  useEffect(() => { void refresh(); }, [refresh]);

  useEffect(() => {
    setStock(null);
    setOwners(null);
    setLots(null);
    setMovements(null);
    resetSelection();
    resetFilters();
  }, [contextKey]);

  function switchTab(next: Tab) {
    if (next === tab) return;
    setTab(next);
    resetSelection();
    resetFilters();
  }

  function resetSelection() {
    selectedId.current = null;
    setSelectedStock(null);
    setSelectedOwner(null);
    setSelectedLot(null);
    setSelectedMovement(null);
    setOwnerForm(blankOwner(owners?.lookups.parties));
    setLotForm(blankLot(lots));
    setTargetStatus('ACTIVE');
    setStatusReason('');
    setReservationNumber('');
    setReservationQuantity('');
    setReservationPurpose('');
    setReleaseReason('');
    clearFeedback();
  }

  function resetFilters() {
    setPage(1);
    setSearch('');
    setSearchDraft('');
    setStatus('');
    setType('');
    setSecondary('');
    setSort('');
    setIncludeZero(false);
  }

  async function chooseStock(id: string) {
    selectedId.current = id;
    setDetailLoading(true);
    clearFeedback();
    try {
      const detail = await getStockPosition(id);
      if (selectedId.current === id) setSelectedStock(detail);
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to load this stock position.'));
    } finally {
      setDetailLoading(false);
    }
  }

  async function chooseOwner(id: string) {
    selectedId.current = id;
    setDetailLoading(true);
    clearFeedback();
    try {
      const detail = await getInventoryOwner(id);
      if (selectedId.current !== id) return;
      setSelectedOwner(detail);
      setOwnerForm(toOwnerForm(detail));
      setTargetStatus(detail.allowed_statuses[0] ?? 'ACTIVE');
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to load this inventory owner.'));
    } finally {
      setDetailLoading(false);
    }
  }

  async function chooseLot(id: string) {
    selectedId.current = id;
    setDetailLoading(true);
    clearFeedback();
    try {
      const detail = await getInventoryLot(id);
      if (selectedId.current !== id) return;
      setSelectedLot(detail);
      setLotForm(toLotForm(detail));
      setTargetStatus(detail.allowed_statuses[0] ?? 'ACTIVE');
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to load this lot.'));
    } finally {
      setDetailLoading(false);
    }
  }

  async function chooseMovement(id: string) {
    selectedId.current = id;
    setDetailLoading(true);
    clearFeedback();
    try {
      const detail = await getInventoryMovement(id);
      if (selectedId.current === id) setSelectedMovement(detail);
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to load this stock movement.'));
    } finally {
      setDetailLoading(false);
    }
  }

  function startNew() {
    selectedId.current = null;
    clearFeedback();
    if (tab === 'owners') {
      setSelectedOwner(null);
      setOwnerForm(blankOwner(owners?.lookups.parties));
    } else if (tab === 'lots') {
      setSelectedLot(null);
      setLotForm(blankLot(lots));
    }
  }

  async function saveOwner(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    resetMessages();
    commandKey.current ??= globalThis.crypto.randomUUID();
    try {
      const body = {
        name: ownerForm.name.trim(), owner_type: ownerForm.owner_type,
        party_id: ownerForm.owner_type === 'PARTY' ? nullable(ownerForm.party_id) : null,
      };
      const result = selectedOwner
        ? await updateInventoryOwner(selectedOwner, body, commandKey.current)
        : await createInventoryOwner({
          ...body, code: ownerForm.code.trim().toUpperCase(), status: ownerForm.status,
        }, commandKey.current);
      commandKey.current = null;
      await refresh();
      await chooseOwner(result.id);
      setSuccess(selectedOwner ? `Owner saved at version ${result.record_version}.` : 'Inventory owner created.');
    } catch (caught) {
      captureError(caught, 'Unable to save this inventory owner.');
    } finally {
      setBusy(false);
    }
  }

  async function saveLot(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy) return;
    setBusy(true);
    resetMessages();
    commandKey.current ??= globalThis.crypto.randomUUID();
    try {
      const body = {
        item_id: lotForm.item_id,
        supplier_party_id: nullable(lotForm.supplier_party_id),
        supplier_lot_code: nullable(lotForm.supplier_lot_code?.toUpperCase()),
        origin_type: lotForm.origin_type,
        manufacture_date: nullable(lotForm.manufacture_date),
        expiry_date: nullable(lotForm.expiry_date),
        notes: nullable(lotForm.notes),
      };
      const result = selectedLot
        ? await updateInventoryLot(selectedLot, body, commandKey.current)
        : await createInventoryLot({
          ...body, internal_lot_code: lotForm.internal_lot_code.trim().toUpperCase(), status: lotForm.status,
        }, commandKey.current);
      commandKey.current = null;
      await refresh();
      await chooseLot(result.id);
      setSuccess(selectedLot ? `Lot saved at version ${result.record_version}.` : 'Inventory lot created.');
    } catch (caught) {
      captureError(caught, 'Unable to save this lot.');
    } finally {
      setBusy(false);
    }
  }

  async function changeStatus(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy || (!selectedOwner && !selectedLot)) return;
    setBusy(true);
    resetMessages();
    lifecycleKey.current ??= globalThis.crypto.randomUUID();
    try {
      if (selectedOwner) {
        const result = await changeInventoryOwnerStatus(
          selectedOwner,
          targetStatus as InventoryOwner['status'],
          statusReason.trim(),
          lifecycleKey.current,
        );
        lifecycleKey.current = null;
        await refresh();
        await chooseOwner(selectedOwner.id);
        setSuccess(`Owner moved to ${display(result.status)}.`);
      } else if (selectedLot) {
        const result = await changeInventoryLotStatus(
          selectedLot,
          targetStatus as InventoryLot['status'],
          statusReason.trim(),
          lifecycleKey.current,
        );
        lifecycleKey.current = null;
        await refresh();
        await chooseLot(selectedLot.id);
        setSuccess(`Lot moved to ${display(result.status)}.`);
      }
      setStatusReason('');
    } catch (caught) {
      captureError(caught, 'Unable to change lifecycle status.');
    } finally {
      setBusy(false);
    }
  }

  async function createReservation(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!selectedStock || busy) return;
    setBusy(true);
    resetMessages();
    reservationKey.current ??= globalThis.crypto.randomUUID();
    try {
      await reserveStock(selectedStock, {
        reservation_number: reservationNumber.trim().toUpperCase(),
        quantity_base: reservationQuantity,
        purpose: reservationPurpose.trim(),
      }, reservationKey.current);
      reservationKey.current = null;
      setReservationNumber('');
      setReservationQuantity('');
      setReservationPurpose('');
      await refresh();
      await chooseStock(selectedStock.id);
      setSuccess('Stock reservation created.');
    } catch (caught) {
      captureError(caught, 'Unable to reserve this stock.');
    } finally {
      setBusy(false);
    }
  }

  async function releaseReservation(reservation: StockReservation) {
    if (busy || releaseReason.trim().length < 3) return;
    setBusy(true);
    resetMessages();
    releaseKey.current ??= globalThis.crypto.randomUUID();
    try {
      await releaseStockReservation(reservation, releaseReason.trim(), releaseKey.current);
      releaseKey.current = null;
      setReleaseReason('');
      await refresh();
      if (selectedStock) await chooseStock(selectedStock.id);
      setSuccess(`${reservation.reservation_number} was released.`);
    } catch (caught) {
      captureError(caught, 'Unable to release this reservation.');
    } finally {
      setBusy(false);
    }
  }

  function submitSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setPage(1);
    setSearch(searchDraft.trim());
  }

  function changeOwnerForm(patch: Partial<OwnerForm>) {
    setOwnerForm((value) => ({ ...value, ...patch }));
    commandKey.current = null;
    resetMessages();
  }

  function changeLotForm(patch: Partial<LotForm>) {
    setLotForm((value) => ({ ...value, ...patch }));
    commandKey.current = null;
    resetMessages();
  }

  function captureError(caught: unknown, fallback: string) {
    setError(apiMessage(caught, fallback));
    setFieldErrors(apiFields(caught));
  }

  function resetMessages() {
    setError(null);
    setSuccess(null);
    setFieldErrors({});
  }

  function clearFeedback() {
    commandKey.current = null;
    lifecycleKey.current = null;
    reservationKey.current = null;
    releaseKey.current = null;
    resetMessages();
  }

  const workspace = tab === 'stock' ? stock : tab === 'owners' ? owners : tab === 'lots' ? lots : movements;
  const allowNew = tab === 'owners'
    ? owners?.allowed_actions.includes('CREATE')
    : tab === 'lots' ? lots?.allowed_actions.includes('CREATE') : false;

  return <>
    <PageHeader
      code="INV-STK"
      batch="B11"
      title="Stock, Lots & Ownership"
      description="Plant stock visibility with controlled lot traceability, ownership, quality availability, and reservations."
      onNew={allowNew ? startNew : undefined}
      onHistory={tab !== 'movements' ? () => switchTab('movements') : undefined}
    />
    <div className="live-notice inventory-notice"><span></span><b>Controlled inventory foundation</b>Available, blocked, and reserved quantities are derived from server-owned stock, lot, owner, and quality state.</div>

    <nav className="inventory-tabs" aria-label="Inventory workspace">
      <button type="button" className={tab === 'stock' ? 'active' : ''} onClick={() => switchTab('stock')}>Stock positions</button>
      <button type="button" className={tab === 'owners' ? 'active' : ''} onClick={() => switchTab('owners')}>Owners</button>
      <button type="button" className={tab === 'lots' ? 'active' : ''} onClick={() => switchTab('lots')}>Lots</button>
      <button type="button" className={tab === 'movements' ? 'active' : ''} onClick={() => switchTab('movements')}>Movement history</button>
    </nav>

    <InventoryKpis tab={tab} stock={stock} owners={owners} lots={lots} movements={movements} loading={loading} />

    <div className="module-grid inventory-workspace">
      <section className="panel">
        <div className="panel-head"><div><h3>{tabTitle(tab)}</h3><span>{session.selected_context?.plant_name}</span></div><button className="secondary compact-button" type="button" onClick={() => void refresh()} disabled={loading}>Refresh</button></div>
        <InventoryFilters
          tab={tab}
          searchDraft={searchDraft}
          setSearchDraft={setSearchDraft}
          status={status}
          setStatus={setStatus}
          type={type}
          setType={setType}
          secondary={secondary}
          setSecondary={setSecondary}
          sort={sort}
          setSort={setSort}
          includeZero={includeZero}
          setIncludeZero={setIncludeZero}
          stock={stock}
          owners={owners}
          lots={lots}
          movements={movements}
          onSearch={submitSearch}
          resetPage={() => setPage(1)}
        />
        {error && !workspace && <div className="form-error panel-message" role="alert"><span>{error}</span><button type="button" onClick={() => void refresh()}>Retry</button></div>}
        {loading && !workspace && <div className="empty-state">Loading {tab}...</div>}
        {!loading && workspace && workspace.data.length === 0 && <div className="empty-state">No records match the current filters.</div>}
        {tab === 'stock' && stock && stock.data.length > 0 && <StockTable rows={stock.data} selectedId={selectedStock?.id} loading={loading} onOpen={chooseStock} />}
        {tab === 'owners' && owners && owners.data.length > 0 && <OwnerTable rows={owners.data} selectedId={selectedOwner?.id} loading={loading} onOpen={chooseOwner} />}
        {tab === 'lots' && lots && lots.data.length > 0 && <LotTable rows={lots.data} selectedId={selectedLot?.id} loading={loading} onOpen={chooseLot} />}
        {tab === 'movements' && movements && movements.data.length > 0 && <MovementTable rows={movements.data} selectedId={selectedMovement?.id} loading={loading} onOpen={chooseMovement} />}
        {workspace && <Pagination meta={workspace.meta} page={page} loading={loading} setPage={setPage} />}
      </section>

      <aside className="panel admin-editor inventory-editor">
        {tab === 'stock' && <StockDetail
          detail={selectedStock}
          loading={detailLoading}
          busy={busy}
          error={error && stock ? error : null}
          success={success}
          fieldErrors={fieldErrors}
          reservationNumber={reservationNumber}
          reservationQuantity={reservationQuantity}
          reservationPurpose={reservationPurpose}
          releaseReason={releaseReason}
          onReservationNumber={(value) => { setReservationNumber(value.toUpperCase()); reservationKey.current = null; }}
          onReservationQuantity={(value) => { setReservationQuantity(value); reservationKey.current = null; }}
          onReservationPurpose={(value) => { setReservationPurpose(value); reservationKey.current = null; }}
          onReleaseReason={(value) => { setReleaseReason(value); releaseKey.current = null; }}
          onReserve={createReservation}
          onRelease={releaseReservation}
        />}
        {tab === 'owners' && <OwnerEditor
          detail={selectedOwner}
          form={ownerForm}
          parties={owners?.lookups.parties ?? []}
          loading={detailLoading}
          busy={busy}
          canCreate={Boolean(owners?.allowed_actions.includes('CREATE'))}
          error={error && owners ? error : null}
          success={success}
          fieldErrors={fieldErrors}
          targetStatus={targetStatus}
          statusReason={statusReason}
          onChange={changeOwnerForm}
          onSave={saveOwner}
          onTargetStatus={(value) => { setTargetStatus(value); lifecycleKey.current = null; }}
          onStatusReason={(value) => { setStatusReason(value); lifecycleKey.current = null; }}
          onLifecycle={changeStatus}
        />}
        {tab === 'lots' && <LotEditor
          detail={selectedLot}
          form={lotForm}
          lookups={lots}
          loading={detailLoading}
          busy={busy}
          canCreate={Boolean(lots?.allowed_actions.includes('CREATE'))}
          error={error && lots ? error : null}
          success={success}
          fieldErrors={fieldErrors}
          targetStatus={targetStatus}
          statusReason={statusReason}
          onChange={changeLotForm}
          onSave={saveLot}
          onTargetStatus={(value) => { setTargetStatus(value); lifecycleKey.current = null; }}
          onStatusReason={(value) => { setStatusReason(value); lifecycleKey.current = null; }}
          onLifecycle={changeStatus}
        />}
        {tab === 'movements' && <MovementDetail detail={selectedMovement} loading={detailLoading} error={error && movements ? error : null} />}
      </aside>
    </div>
  </>;
}

function InventoryKpis({ tab, stock, owners, lots, movements, loading }: {
  tab: Tab; stock: StockWorkspace | null; owners: OwnerWorkspace | null; lots: LotWorkspace | null;
  movements: InventoryMovementWorkspace | null; loading: boolean;
}) {
  const empty = loading && !(tab === 'stock' ? stock : tab === 'owners' ? owners : tab === 'lots' ? lots : movements);
  if (tab === 'stock') {
    const metrics = [
      ['Total stock', 'total', 'all quality states'],
      ['Available', 'available', 'unreserved and released'],
      ['Blocked', 'blocked', 'quality, lot, or expiry hold'],
      ['Reserved', 'reserved', 'active reservations'],
    ] as const;
    return <div className="kpi-grid inventory-kpis">{metrics.map(([label, key, note]) => (
      <div className="kpi" key={label}>
        <span>{label}</span>
        {empty ? <b>-</b> : (
          <div className="uom-kpi-values" aria-label={`${label} by unit of measure`}>
            {(stock?.summary.totals_by_uom ?? []).map((row) => <b key={row.uom_code}>{quantity(row[key])} <small>{row.uom_code}</small></b>)}
            {!stock?.summary.totals_by_uom?.length && <b>0</b>}
          </div>
        )}
        <small>{stock?.summary.uom_count && stock.summary.uom_count > 1 ? `${note} · ${stock.summary.uom_count} UOMs kept separate` : note}</small>
      </div>
    ))}</div>;
  }

  const values = tab === 'owners' ? [
    ['Owners', owners?.summary.total ?? 0, 'company scoped'],
    ['Active', owners?.summary.active ?? 0, 'available for stock'],
    ['Party owned', owners?.summary.party_owned ?? 0, 'consignment identities'],
    ['Inactive', owners?.summary.inactive ?? 0, 'retained history'],
  ] : tab === 'lots' ? [
    ['Lots', lots?.summary.total ?? 0, 'company scoped'],
    ['Active', lots?.summary.active ?? 0, 'eligible by lifecycle'],
    ['Expiring 30 days', lots?.summary.expiring_30 ?? 0, 'requires attention'],
    ['Expired', lots?.summary.expired ?? 0, 'cannot reserve'],
  ] : [
    ['Movements', movements?.summary.total ?? 0, 'immutable ledger entries'],
    ['Outbound', movements?.summary.outbound ?? 0, 'issues, losses, disposal'],
    ['Inbound', movements?.summary.inbound ?? 0, 'returns and gains'],
    ['Transfers', movements?.summary.transfer ?? 0, 'position-to-position'],
  ];
  return <div className="kpi-grid inventory-kpis">{values.map(([label, value, note]) => <div className="kpi" key={label}><span>{label}</span><b>{empty ? '-' : value}</b><small>{note}</small></div>)}</div>;
}

function InventoryFilters(props: {
  tab: Tab;
  searchDraft: string;
  setSearchDraft: (value: string) => void;
  status: string;
  setStatus: (value: string) => void;
  type: string;
  setType: (value: string) => void;
  secondary: string;
  setSecondary: (value: string) => void;
  sort: string;
  setSort: (value: string) => void;
  includeZero: boolean;
  setIncludeZero: (value: boolean) => void;
  stock: StockWorkspace | null;
  owners: OwnerWorkspace | null;
  lots: LotWorkspace | null;
  movements: InventoryMovementWorkspace | null;
  onSearch: (event: FormEvent<HTMLFormElement>) => void;
  resetPage: () => void;
}) {
  const change = (setter: (value: string) => void) => (event: React.ChangeEvent<HTMLSelectElement>) => {
    setter(event.target.value);
    props.resetPage();
  };
  return <form className="inventory-toolbar" onSubmit={props.onSearch}>
    <label>Search<span><input aria-label={`Search ${props.tab}`} value={props.searchDraft} onChange={(event) => props.setSearchDraft(event.target.value)} placeholder="SKU, lot, owner, or location" /><button className="secondary" type="submit">Search</button></span></label>
    {props.tab === 'stock' && <>
      <label>Availability<select aria-label="Filter stock availability" value={props.status} onChange={change(props.setStatus)}><option value="">All stock</option>{props.stock?.lookups.availability_filters.map((value) => <option key={value}>{value}</option>)}</select></label>
      <label>Quality<select aria-label="Filter stock quality" value={props.type} onChange={change(props.setType)}><option value="">All quality</option>{props.stock?.lookups.quality_statuses.map((value) => <option key={value.code} value={value.code}>{value.name}</option>)}</select></label>
      <label>Owner<select aria-label="Filter stock owner" value={props.secondary} onChange={change(props.setSecondary)}><option value="">All owners</option>{options(props.stock?.lookups.owners)}</select></label>
      <label>Order<select aria-label="Sort stock" value={props.sort || 'SKU'} onChange={change(props.setSort)}>{props.stock?.lookups.sorts.map((value) => <option key={value} value={value}>{display(value)}</option>)}</select></label>
      <label className="inventory-check"><input aria-label="Include zero stock" type="checkbox" checked={props.includeZero} onChange={(event) => { props.setIncludeZero(event.target.checked); props.resetPage(); }} />Include zero</label>
    </>}
    {props.tab === 'owners' && <>
      <label>Status<select aria-label="Filter owner status" value={props.status} onChange={change(props.setStatus)}><option value="">All statuses</option>{props.owners?.lookups.statuses.map((value) => <option key={value}>{value}</option>)}</select></label>
      <label>Type<select aria-label="Filter owner type" value={props.type} onChange={change(props.setType)}><option value="">All types</option>{props.owners?.lookups.owner_types.map((value) => <option key={value}>{value}</option>)}</select></label>
      <label>Order<select aria-label="Sort owners" value={props.sort || 'CODE'} onChange={change(props.setSort)}><option>CODE</option><option>NAME</option><option>NEWEST</option><option>OLDEST</option></select></label>
    </>}
    {props.tab === 'lots' && <>
      <label>Status<select aria-label="Filter lot status" value={props.status} onChange={change(props.setStatus)}><option value="">All statuses</option>{props.lots?.lookups.statuses.map((value) => <option key={value}>{value}</option>)}</select></label>
      <label>Origin<select aria-label="Filter lot origin" value={props.type} onChange={change(props.setType)}><option value="">All origins</option>{props.lots?.lookups.origin_types.map((value) => <option key={value}>{value}</option>)}</select></label>
      <label>Expiry<select aria-label="Filter lot expiry" value={props.secondary} onChange={change(props.setSecondary)}><option value="">Any expiry</option><option value="VALID">Valid</option><option value="EXPIRING_30">Expiring 30 days</option><option value="EXPIRED">Expired</option></select></label>
      <label>Order<select aria-label="Sort lots" value={props.sort || 'CODE'} onChange={change(props.setSort)}><option>CODE</option><option>SKU</option><option>EXPIRY</option><option>NEWEST</option><option>OLDEST</option></select></label>
    </>}
    {props.tab === 'movements' && <>
      <label>Direction<select aria-label="Filter movement direction" value={props.status} onChange={change(props.setStatus)}><option value="">All directions</option>{props.movements?.lookups.directions.map((value) => <option key={value}>{value}</option>)}</select></label>
      <label>Movement type<select aria-label="Filter movement type" value={props.type} onChange={change(props.setType)}><option value="">All movement types</option>{props.movements?.lookups.movement_types.map((value) => <option key={value} value={value}>{display(value)}</option>)}</select></label>
      <label>Order<select aria-label="Sort movements" value={props.sort || 'NEWEST'} onChange={change(props.setSort)}>{(props.movements?.lookups.sorts ?? ['NEWEST']).map((value) => <option key={value}>{value}</option>)}</select></label>
    </>}
  </form>;
}

function StockTable({ rows, selectedId, loading, onOpen }: { rows: StockPosition[]; selectedId?: string; loading: boolean; onOpen: (id: string) => Promise<void> }) {
  return <div className={`table-wrap ${loading ? 'is-refreshing' : ''}`}><table className="inventory-table"><thead><tr><th>SKU / lot</th><th>Owner</th><th>Location / quality</th><th>Total</th><th>Available</th><th>Blocked</th><th>Reserved</th><th>Version</th><th>Action</th></tr></thead><tbody>{rows.map((row) => <tr key={row.id} className={selectedId === row.id ? 'selected-row' : ''}>
    <td><b>{row.sku.name}</b><small>{row.sku.code} · {row.lot.code}</small></td>
    <td><b>{row.owner.name}</b><small>{row.owner.code} · {display(row.owner.type)}</small></td>
    <td><b>{row.location.name}</b><small>{row.location.code} · {row.quality_status.name}</small></td>
    <td>{quantity(row.quantity.total)} {row.quantity.uom_code}</td><td className="text-good">{quantity(row.quantity.available)}</td><td className="text-bad">{quantity(row.quantity.blocked)}</td><td>{quantity(row.quantity.reserved)}</td><td>v{row.record_version}</td>
    <td><button className="secondary compact-button" type="button" onClick={() => void onOpen(row.id)}>Open</button></td>
  </tr>)}</tbody></table></div>;
}

function OwnerTable({ rows, selectedId, loading, onOpen }: { rows: InventoryOwner[]; selectedId?: string; loading: boolean; onOpen: (id: string) => Promise<void> }) {
  return <div className={`table-wrap ${loading ? 'is-refreshing' : ''}`}><table className="inventory-table"><thead><tr><th>Owner</th><th>Identity</th><th>Positions</th><th>Total</th><th>Reserved</th><th>Status</th><th>Version</th><th>Action</th></tr></thead><tbody>{rows.map((row) => <tr key={row.id} className={selectedId === row.id ? 'selected-row' : ''}>
    <td><b>{row.name}</b><small>{row.code}</small></td><td>{display(row.owner_type)}<small>{row.party?.name ?? 'Company identity'}</small></td><td>{row.position_count}</td><td>{quantity(row.total_quantity)}</td><td>{quantity(row.reserved_quantity)}</td><td><StatusBadge status={row.status} /></td><td>v{row.record_version}</td><td><button className="secondary compact-button" type="button" onClick={() => void onOpen(row.id)}>Open</button></td>
  </tr>)}</tbody></table></div>;
}

function LotTable({ rows, selectedId, loading, onOpen }: { rows: InventoryLot[]; selectedId?: string; loading: boolean; onOpen: (id: string) => Promise<void> }) {
  return <div className={`table-wrap ${loading ? 'is-refreshing' : ''}`}><table className="inventory-table"><thead><tr><th>Lot</th><th>SKU</th><th>Origin / supplier</th><th>Expiry</th><th>Positions</th><th>Total</th><th>Reserved</th><th>Status</th><th>Action</th></tr></thead><tbody>{rows.map((row) => <tr key={row.id} className={selectedId === row.id ? 'selected-row' : ''}>
    <td><b>{row.internal_lot_code}</b><small>{row.supplier_lot_code ?? 'No supplier lot'}</small></td><td><b>{row.sku.name}</b><small>{row.sku.code}</small></td><td>{display(row.origin_type)}<small>{row.supplier?.name ?? 'Internal'}</small></td><td className={row.is_expired ? 'text-bad' : ''}>{date(row.expiry_date)}<small>{expiryNote(row)}</small></td><td>{row.position_count}</td><td>{quantity(row.total_quantity)}</td><td>{quantity(row.reserved_quantity)}</td><td><StatusBadge status={row.status} /></td><td><button className="secondary compact-button" type="button" onClick={() => void onOpen(row.id)}>Open</button></td>
  </tr>)}</tbody></table></div>;
}

function MovementTable({ rows, selectedId, loading, onOpen }: { rows: InventoryMovement[]; selectedId?: string; loading: boolean; onOpen: (id: string) => Promise<void> }) {
  return <div className={`table-wrap ${loading ? 'is-refreshing' : ''}`}><table className="inventory-table movement-ledger-table"><thead><tr><th>Movement</th><th>Direction</th><th>SKU / lot</th><th>From</th><th>To</th><th>Quantity</th><th>Source</th><th>Posted</th><th>Action</th></tr></thead><tbody>{rows.map((row) => {
    const position = row.from_position ?? row.to_position;
    return <tr key={row.id} className={selectedId === row.id ? 'selected-row' : ''}>
      <td><b>{display(row.movement_type)}</b><small className="mono">{shortId(row.id)}</small></td>
      <td><StatusBadge status={row.direction} /></td>
      <td><b>{position?.sku.name ?? 'Referenced stock'}</b><small>{position?.sku.code} Â· {position?.lot.code}</small></td>
      <td>{movementPosition(row.from_position)}</td><td>{movementPosition(row.to_position)}</td>
      <td><b>{quantity(row.quantity_base)} {row.uom_code}</b><small>{display(row.reason_code ?? 'No reason')}</small></td>
      <td><b>{row.source.operation_number ?? display(row.source.type)}</b><small>v{row.source.version ?? 'â€”'}</small></td>
      <td>{dateTime(row.posted_at)}</td>
      <td><button className="secondary compact-button" type="button" onClick={() => void onOpen(row.id)}>Open</button></td>
    </tr>;
  })}</tbody></table></div>;
}

function MovementDetail({ detail, loading, error }: { detail: InventoryMovement | null; loading: boolean; error: string | null }) {
  const position = detail?.from_position ?? detail?.to_position;
  return <>
    <div className="panel-head"><div><h3>{detail ? display(detail.movement_type) : 'Movement evidence'}</h3><span>{loading ? 'Loading movement...' : detail ? shortId(detail.id) : 'Open a ledger movement to inspect its controls.'}</span></div>{detail && <StatusBadge status={detail.direction} />}</div>
    {!detail && !loading && <div className="empty-state">Select a movement to inspect its source, actor, stock coordinates, and timestamps.</div>}
    {loading && !detail && <div className="empty-state">Loading stock movement...</div>}
    {detail && <div className="panel-body inventory-detail-body">
      <Feedback error={error} success={null} />
      <div className="stock-quantity-grid">
        <Fact label="Quantity" value={`${quantity(detail.quantity_base)} ${detail.uom_code}`} />
        <Fact label="Direction" value={detail.direction} />
        <Fact label="Reason" value={display(detail.reason_code ?? 'Not supplied')} />
        <Fact label="Source version" value={`v${detail.source.version ?? 'â€”'}`} />
      </div>
      <dl className="control-definition">
        <FactDefinition label="SKU / lot" value={position?.sku.name ?? 'Referenced stock'} note={`${position?.sku.code ?? 'â€”'} Â· ${position?.lot.code ?? 'â€”'}`} />
        <FactDefinition label="Inventory owner" value={position?.owner.name ?? 'â€”'} note={position?.owner.code ?? 'â€”'} />
        <FactDefinition label="From position" value={detail.from_position?.location.name ?? 'External source'} note={movementPosition(detail.from_position)} />
        <FactDefinition label="To position" value={detail.to_position?.location.name ?? 'External destination'} note={movementPosition(detail.to_position)} />
        <FactDefinition label="Business source" value={detail.source.operation_number ?? display(detail.source.type)} note={`${detail.source.id} Â· ${detail.source.operation_type ?? 'External workflow'}`} />
        <FactDefinition label="Posted by" value={detail.actor.name} note={dateTime(detail.posted_at)} />
      </dl>
      <section className="reservation-history"><h4>Immutable timestamps</h4><div className="quality-total-list"><div><span><b>Business event</b><small>{dateTime(detail.event_at)}</small></span><span>{detail.source.operation_status ?? 'Posted source'}</span></div><div><span><b>Ledger posting</b><small>{dateTime(detail.posted_at)}</small></span><span className="mono">{detail.id}</span></div></div></section>
    </div>}
  </>;
}

function StockDetail(props: {
  detail: StockPosition | null; loading: boolean; busy: boolean; error: string | null; success: string | null;
  fieldErrors: Record<string, string>; reservationNumber: string; reservationQuantity: string; reservationPurpose: string; releaseReason: string;
  onReservationNumber: (value: string) => void; onReservationQuantity: (value: string) => void; onReservationPurpose: (value: string) => void; onReleaseReason: (value: string) => void;
  onReserve: (event: FormEvent<HTMLFormElement>) => Promise<void>; onRelease: (reservation: StockReservation) => Promise<void>;
}) {
  const active = props.detail?.reservations?.filter((row) => row.status === 'ACTIVE') ?? [];
  return <>
    <div className="panel-head"><div><h3>{props.detail ? `${props.detail.sku.code} · ${props.detail.lot.code}` : 'Stock position detail'}</h3><span>{props.loading ? 'Loading detail...' : props.detail ? `Version ${props.detail.record_version}` : 'Open a position to inspect availability'}</span></div>{props.detail && <StatusBadge status={props.detail.stock_bucket} />}</div>
    {!props.detail && !props.loading && <div className="empty-state">Select a stock position to inspect its owner, lot, quality, and reservations.</div>}
    {props.loading && !props.detail && <div className="empty-state">Loading stock position...</div>}
    {props.detail && <div className="panel-body inventory-detail-body">
      <Feedback error={props.error} success={props.success} />
      <div className="stock-quantity-grid">
        <Fact label="Total" value={`${quantity(props.detail.quantity.total)} ${props.detail.quantity.uom_code}`} />
        <Fact label="Available" value={quantity(props.detail.quantity.available)} />
        <Fact label="Blocked" value={quantity(props.detail.quantity.blocked)} />
        <Fact label="Reserved" value={quantity(props.detail.quantity.reserved)} />
      </div>
      <dl className="control-definition">
        <FactDefinition label="Owner" value={props.detail.owner.name} note={`${props.detail.owner.code} · ${display(props.detail.owner.type)}`} />
        <FactDefinition label="Location" value={props.detail.location.name} note={props.detail.location.code} />
        <FactDefinition label="Quality" value={props.detail.quality_status.name} note={display(props.detail.quality_status.availability_bucket)} />
        <FactDefinition label="Expiry" value={date(props.detail.lot.expiry_date)} note={props.detail.lot.is_expired ? 'Expired' : expiryNote(props.detail.lot)} />
      </dl>
      {props.detail.allowed_actions.includes('RESERVE') && <form className="inventory-command" onSubmit={props.onReserve}>
        <h4>Create reservation</h4>
        <p>Reservation cannot exceed the server-derived available quantity.</p>
        <div className="party-card-grid">
          <label>Reservation number<input aria-label="Reservation number" value={props.reservationNumber} onChange={(event) => props.onReservationNumber(event.target.value)} /><FieldError value={props.fieldErrors.reservation_number} /></label>
          <label>Quantity<input aria-label="Reservation quantity" type="number" min="0" step="any" value={props.reservationQuantity} onChange={(event) => props.onReservationQuantity(event.target.value)} /><FieldError value={props.fieldErrors.quantity_base} /></label>
          <label className="wide">Purpose<textarea aria-label="Reservation purpose" rows={2} value={props.reservationPurpose} onChange={(event) => props.onReservationPurpose(event.target.value)} /><FieldError value={props.fieldErrors.purpose} /></label>
        </div>
        <button className="primary" type="submit" disabled={props.busy || !props.reservationNumber.trim() || !props.reservationQuantity || props.reservationPurpose.trim().length < 3}>{props.busy ? 'Saving...' : 'Reserve stock'}</button>
      </form>}
      <section className="reservation-history">
        <h4>Reservations</h4>
        {active.length > 0 && <label>Release reason<textarea aria-label="Reservation release reason" rows={2} value={props.releaseReason} onChange={(event) => props.onReleaseReason(event.target.value)} /></label>}
        {props.detail.reservations?.length === 0 && <div className="empty-state compact">No reservations recorded.</div>}
        {props.detail.reservations?.map((row) => <article className="reservation-card" key={row.id}><div><b>{row.reservation_number}</b><StatusBadge status={row.status} /></div><p>{row.purpose}</p><small>{quantity(row.quantity_base)} {props.detail?.quantity.uom_code} · v{row.record_version} · {dateTime(row.created_at)}</small>{row.allowed_actions.includes('RELEASE') && <button className="secondary compact-button" type="button" disabled={props.busy || props.releaseReason.trim().length < 3} onClick={() => void props.onRelease(row)}>Release reservation</button>}</article>)}
      </section>
    </div>}
  </>;
}

function OwnerEditor(props: {
  detail: InventoryOwner | null; form: OwnerForm; parties: Reference[]; loading: boolean; busy: boolean; canCreate: boolean;
  error: string | null; success: string | null; fieldErrors: Record<string, string>; targetStatus: string; statusReason: string;
  onChange: (patch: Partial<OwnerForm>) => void; onSave: (event: FormEvent<HTMLFormElement>) => Promise<void>;
  onTargetStatus: (value: string) => void; onStatusReason: (value: string) => void; onLifecycle: (event: FormEvent<HTMLFormElement>) => Promise<void>;
}) {
  const canSave = props.detail ? props.detail.allowed_actions.includes('UPDATE') : props.canCreate;
  return <>
    <EditorHead title={props.detail ? `Edit ${props.detail.code}` : 'New inventory owner'} detail={props.detail} loading={props.loading} />
    <form className="panel-body party-form" onSubmit={props.onSave} noValidate>
      <Feedback error={props.error} success={props.success} />
      <fieldset className="party-form-fields" disabled={props.busy || props.loading}>
        <section className="party-subsection"><h4>Owner identity</h4><div className="party-card-grid">
          <label>Code<input aria-label="Owner code" className={props.detail ? 'read-only' : ''} readOnly={Boolean(props.detail)} value={props.form.code} onChange={(event) => props.onChange({ code: event.target.value.toUpperCase() })} /><FieldError value={props.fieldErrors.code} /></label>
          {!props.detail && <label>Initial status<select aria-label="Owner initial status" value={props.form.status} onChange={(event) => props.onChange({ status: event.target.value as OwnerForm['status'] })}><option>DRAFT</option><option>ACTIVE</option></select></label>}
          <label className="wide">Name<input aria-label="Owner name" value={props.form.name} onChange={(event) => props.onChange({ name: event.target.value })} /><FieldError value={props.fieldErrors.name} /></label>
          <label>Owner type<select aria-label="Owner type" value={props.form.owner_type} onChange={(event) => props.onChange({ owner_type: event.target.value as OwnerForm['owner_type'], party_id: event.target.value === 'COMPANY' ? '' : props.form.party_id })}><option value="PARTY">Party</option><option value="COMPANY">Company</option></select><FieldError value={props.fieldErrors.owner_type} /></label>
          {props.form.owner_type === 'PARTY' && <label>Party<select aria-label="Owner party" value={props.form.party_id} onChange={(event) => props.onChange({ party_id: event.target.value })}><option value="">Select party</option>{options(props.parties)}</select><FieldError value={props.fieldErrors.party_id} /></label>}
        </div></section>
        {props.detail && <QualityTotals rows={props.detail.quality_totals ?? []} />}
        <div className="form-actions"><button className="primary" type="submit" disabled={!canSave || props.busy || !props.form.code.trim() || !props.form.name.trim()}>{props.busy ? 'Saving...' : props.detail ? 'Save owner' : 'Create owner'}</button></div>
      </fieldset>
    </form>
    <Lifecycle detail={props.detail} busy={props.busy} targetStatus={props.targetStatus} statusReason={props.statusReason} fieldErrors={props.fieldErrors} noun="owner" onTargetStatus={props.onTargetStatus} onStatusReason={props.onStatusReason} onSubmit={props.onLifecycle} />
  </>;
}

function LotEditor(props: {
  detail: InventoryLot | null; form: LotForm; lookups: LotWorkspace | null; loading: boolean; busy: boolean; canCreate: boolean;
  error: string | null; success: string | null; fieldErrors: Record<string, string>; targetStatus: string; statusReason: string;
  onChange: (patch: Partial<LotForm>) => void; onSave: (event: FormEvent<HTMLFormElement>) => Promise<void>;
  onTargetStatus: (value: string) => void; onStatusReason: (value: string) => void; onLifecycle: (event: FormEvent<HTMLFormElement>) => Promise<void>;
}) {
  const canSave = props.detail ? props.detail.allowed_actions.includes('UPDATE') : props.canCreate;
  return <>
    <EditorHead title={props.detail ? `Edit ${props.detail.internal_lot_code}` : 'New inventory lot'} detail={props.detail} loading={props.loading} />
    <form className="panel-body party-form" onSubmit={props.onSave} noValidate>
      <Feedback error={props.error} success={props.success} />
      <fieldset className="party-form-fields" disabled={props.busy || props.loading}>
        <section className="party-subsection"><h4>Traceable lot identity</h4><div className="party-card-grid">
          <label>Internal lot code<input aria-label="Internal lot code" className={props.detail ? 'read-only' : ''} readOnly={Boolean(props.detail)} value={props.form.internal_lot_code} onChange={(event) => props.onChange({ internal_lot_code: event.target.value.toUpperCase() })} /><FieldError value={props.fieldErrors.internal_lot_code} /></label>
          {!props.detail && <label>Initial status<select aria-label="Lot initial status" value={props.form.status} onChange={(event) => props.onChange({ status: event.target.value as LotForm['status'] })}><option>DRAFT</option><option>ACTIVE</option></select></label>}
          <label className="wide">SKU<select aria-label="Lot SKU" value={props.form.item_id} onChange={(event) => props.onChange({ item_id: event.target.value })}><option value="">Select lot-controlled SKU</option>{options(props.lookups?.lookups.skus)}</select><FieldError value={props.fieldErrors.item_id} /></label>
          <label>Origin<select aria-label="Lot origin" value={props.form.origin_type} onChange={(event) => props.onChange({ origin_type: event.target.value as LotForm['origin_type'] })}>{props.lookups?.lookups.origin_types.map((value) => <option key={value}>{value}</option>)}</select></label>
          <label>Supplier<select aria-label="Lot supplier" value={props.form.supplier_party_id} onChange={(event) => props.onChange({ supplier_party_id: event.target.value })}><option value="">No supplier</option>{options(props.lookups?.lookups.suppliers)}</select><FieldError value={props.fieldErrors.supplier_party_id} /></label>
          <label>Supplier lot code<input aria-label="Supplier lot code" value={props.form.supplier_lot_code} onChange={(event) => props.onChange({ supplier_lot_code: event.target.value.toUpperCase() })} /></label>
          <label>Manufacture date<input aria-label="Manufacture date" type="date" value={props.form.manufacture_date} onChange={(event) => props.onChange({ manufacture_date: event.target.value })} /></label>
          <label>Expiry date<input aria-label="Expiry date" type="date" value={props.form.expiry_date} onChange={(event) => props.onChange({ expiry_date: event.target.value })} /><FieldError value={props.fieldErrors.expiry_date} /></label>
          <label className="wide">Notes<textarea aria-label="Lot notes" rows={3} value={props.form.notes} onChange={(event) => props.onChange({ notes: event.target.value })} /></label>
        </div></section>
        {props.detail && <QualityTotals rows={props.detail.quality_totals ?? []} />}
        <div className="form-actions"><button className="primary" type="submit" disabled={!canSave || props.busy || !props.form.internal_lot_code.trim() || !props.form.item_id}>{props.busy ? 'Saving...' : props.detail ? 'Save lot' : 'Create lot'}</button></div>
      </fieldset>
    </form>
    <Lifecycle detail={props.detail} busy={props.busy} targetStatus={props.targetStatus} statusReason={props.statusReason} fieldErrors={props.fieldErrors} noun="lot" onTargetStatus={props.onTargetStatus} onStatusReason={props.onStatusReason} onSubmit={props.onLifecycle} />
  </>;
}

function Lifecycle(props: {
  detail: InventoryOwner | InventoryLot | null; busy: boolean; targetStatus: string; statusReason: string;
  fieldErrors: Record<string, string>; noun: string; onTargetStatus: (value: string) => void;
  onStatusReason: (value: string) => void; onSubmit: (event: FormEvent<HTMLFormElement>) => Promise<void>;
}) {
  if (!props.detail?.allowed_actions.includes('CHANGE_STATUS') || props.detail.allowed_statuses.length === 0) return null;
  return <form className="party-lifecycle" onSubmit={props.onSubmit}><div><h4>Lifecycle control</h4><small>{props.detail.status_change ? `${props.detail.status_change.reason ?? 'Status changed'} · ${dateTime(props.detail.status_change.changed_at)}` : 'No prior transition recorded.'}</small></div><label>Target status<select aria-label={`Target ${props.noun} status`} value={props.targetStatus} disabled={props.busy} onChange={(event) => props.onTargetStatus(event.target.value)}>{props.detail.allowed_statuses.map((value) => <option key={value}>{value}</option>)}</select></label><label>Reason<textarea aria-label={`${display(props.noun)} status reason`} rows={2} value={props.statusReason} disabled={props.busy} onChange={(event) => props.onStatusReason(event.target.value)} /></label><FieldError value={props.fieldErrors.target_status ?? props.fieldErrors.reason} /><button className="secondary" type="submit" disabled={props.busy || props.statusReason.trim().length < 3}>Apply status</button></form>;
}

function QualityTotals({ rows }: { rows: { code: string; name: string; total_quantity: string; reserved_quantity: string }[] }) {
  return <section className="party-subsection"><h4>Stock by quality</h4>{rows.length === 0 ? <div className="empty-state compact">No stock positions use this record in the selected plant.</div> : <div className="quality-total-list">{rows.map((row) => <div key={row.code}><span><b>{row.name}</b><small>{row.code}</small></span><span>{quantity(row.total_quantity)} total<small>{quantity(row.reserved_quantity)} reserved</small></span></div>)}</div>}</section>;
}

function EditorHead({ title, detail, loading }: { title: string; detail: InventoryOwner | InventoryLot | null; loading: boolean }) {
  return <div className="panel-head"><div><h3>{title}</h3><span>{loading ? 'Loading detail...' : detail ? `Version ${detail.record_version}` : 'Company scoped'}</span></div>{detail && <StatusBadge status={detail.status} />}</div>;
}

function Feedback({ error, success }: { error: string | null; success: string | null }) {
  return <>{error && <div className="form-error" role="alert"><span>{error}</span></div>}{success && <div className="form-success" role="status"><span></span>{success}</div>}</>;
}

function Fact({ label, value }: { label: string; value: string }) {
  return <div><span>{label}</span><b>{value}</b></div>;
}

function FactDefinition({ label, value, note }: { label: string; value: string; note: string }) {
  return <div><dt>{label}</dt><dd>{value}<small>{note}</small></dd></div>;
}

function Pagination({ meta, page, loading, setPage }: { meta: PageMeta; page: number; loading: boolean; setPage: React.Dispatch<React.SetStateAction<number>> }) {
  return <div className="pagination"><button type="button" disabled={page <= 1 || loading} onClick={() => setPage((value) => value - 1)}>Previous</button><span>Page {meta.current_page} of {meta.last_page} · {meta.total} records</span><button type="button" disabled={page >= meta.last_page || loading} onClick={() => setPage((value) => value + 1)}>Next</button></div>;
}

function FieldError({ value }: { value?: string }) {
  return value ? <span className="field-error">{value}</span> : null;
}

function options(rows: Reference[] = []) {
  return rows.map((row) => <option key={row.id} value={row.id}>{row.code} · {row.name}{row.status && row.status !== 'ACTIVE' ? ` (${row.status})` : ''}</option>);
}

function blankOwner(parties: Reference[] = []): OwnerForm {
  return { code: '', name: '', owner_type: 'PARTY', party_id: parties[0]?.id ?? '', status: 'DRAFT' };
}

function blankLot(workspace?: LotWorkspace | null): LotForm {
  return {
    internal_lot_code: '', item_id: workspace?.lookups.skus[0]?.id ?? '',
    supplier_party_id: workspace?.lookups.suppliers[0]?.id ?? '', supplier_lot_code: '',
    origin_type: workspace?.lookups.origin_types[0] ?? 'PURCHASE', manufacture_date: '', expiry_date: '',
    notes: '', status: 'DRAFT',
  };
}

function toOwnerForm(owner: InventoryOwner): OwnerForm {
  return { code: owner.code, name: owner.name, owner_type: owner.owner_type, party_id: owner.party_id ?? '', status: owner.status === 'ACTIVE' ? 'ACTIVE' : 'DRAFT' };
}

function toLotForm(lot: InventoryLot): LotForm {
  return {
    internal_lot_code: lot.internal_lot_code, item_id: lot.item_id,
    supplier_party_id: lot.supplier_party_id ?? '', supplier_lot_code: lot.supplier_lot_code ?? '',
    origin_type: lot.origin_type, manufacture_date: lot.manufacture_date ?? '', expiry_date: lot.expiry_date ?? '',
    notes: lot.notes ?? '', status: lot.status === 'ACTIVE' ? 'ACTIVE' : 'DRAFT',
  };
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

function quantity(value: string): string {
  const number = Number(value);
  return Number.isFinite(number) ? new Intl.NumberFormat(undefined, { maximumFractionDigits: 6 }).format(number) : value;
}

function date(value: string | null): string {
  return value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`)) : '—';
}

function dateTime(value: string): string {
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

function expiryNote(lot: { days_to_expiry: number | null; is_expired?: boolean }): string {
  if (lot.days_to_expiry === null) return 'No expiry';
  if (lot.is_expired) return `${Math.abs(lot.days_to_expiry)} days overdue`;
  return `${lot.days_to_expiry} days remaining`;
}

function display(value: string): string {
  return value.replaceAll('_', ' ').toLowerCase().replace(/^./, (letter) => letter.toUpperCase());
}

function tabTitle(tab: Tab): string {
  return tab === 'stock' ? 'Plant stock register'
    : tab === 'owners' ? 'Inventory owner register'
      : tab === 'lots' ? 'Lot traceability register' : 'Immutable movement history';
}

function movementPosition(position: InventoryMovement['from_position']): string {
  return position ? `${position.location.code} · ${position.quality_status.code}` : 'External';
}

function shortId(value: string): string {
  return `${value.slice(0, 8)}…${value.slice(-4)}`;
}

import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { isApiError } from '../api/client';
import {
  amendPurchaseOrder,
  cancelPurchaseOrder,
  createPurchaseOrder,
  getPurchaseOrder,
  issuePurchaseOrder,
  listPurchaseOrders,
  type PurchaseOrder,
  type PurchaseOrderAmendment,
  type PurchaseOrderWorkspace as Workspace,
} from '../api/procurementSourcing';
import { useErpSession } from '../app/ErpSessionContext';
import { changeTransactionVisibility } from '../api/transactionVisibility';
import { PageHeader } from './PageHeader';
import { StatusBadge } from './StatusBadge';
import { TransactionVisibilityControl, TRANSACTION_VISIBILITY_HELPER } from './TransactionVisibilityControl';

type CreateForm = {
  po_number: string;
  rfq_id: string;
  order_date: string;
  incoterm_code: string;
  delivery_terms: string;
  notes: string;
};
type AmendmentForm = {
  reason: string;
  required_by_date: string;
  payment_terms_days: string;
  freight_amount: string;
  other_charges: string;
  discount_amount: string;
  incoterm_code: string;
  delivery_terms: string;
  notes: string;
  lines: { rfq_line_id: string; ordered_quantity: string; unit_price: string; notes: string }[];
};

export function PurchaseOrderWorkspace() {
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const [workspace, setWorkspace] = useState<Workspace | null>(null);
  const [selected, setSelected] = useState<PurchaseOrder | null>(null);
  const [mode, setMode] = useState<'NONE' | 'CREATE' | 'AMEND'>('NONE');
  const [createForm, setCreateForm] = useState<CreateForm>(blankCreateForm);
  const [amendmentForm, setAmendmentForm] = useState<AmendmentForm>(blankAmendmentForm());
  const [cancellationReason, setCancellationReason] = useState('');
  const [searchDraft, setSearchDraft] = useState('');
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [visibilityFilter, setVisibilityFilter] = useState('');
  const [restrictOnCreate, setRestrictOnCreate] = useState(false);
  const [sort, setSort] = useState('NEWEST');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const createKey = useRef<string | null>(null);
  const amendKey = useRef<string | null>(null);
  const issueKey = useRef<string | null>(null);
  const cancelKey = useRef<string | null>(null);
  const permissions = session.granted_permissions ?? session.allowed_actions;
  const canRestrict = permissions.includes('DidntShowCheckbox');
  const canShowRestricted = permissions.includes('ShowEntries');

  const refresh = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      setWorkspace(await listPurchaseOrders({ page, q: search || undefined, status: statusFilter || undefined, visibility: visibilityFilter || undefined, sort }));
    } catch (caught) {
      setError(message(caught, 'Unable to load purchase orders.'));
    } finally {
      setLoading(false);
    }
  }, [contextKey, page, search, sort, statusFilter, visibilityFilter]);

  useEffect(() => { void refresh(); }, [refresh]);
  useEffect(() => {
    setSelected(null);
    setMode('NONE');
    setSearchDraft('');
    setSearch('');
    setStatusFilter('');
    setVisibilityFilter('');
    setRestrictOnCreate(false);
    setSort('NEWEST');
    setPage(1);
    clearFeedback();
  }, [contextKey]);
  useEffect(() => { createKey.current = null; }, [createForm]);
  useEffect(() => { amendKey.current = null; }, [amendmentForm]);
  useEffect(() => { cancelKey.current = null; }, [cancellationReason]);
  useEffect(() => {
    amendKey.current = null;
    issueKey.current = null;
    cancelKey.current = null;
  }, [selected?.id, selected?.record_version]);

  const amendmentTotal = useMemo(() => amendmentForm.lines.reduce((total, line) => {
    return total + number(line.ordered_quantity) * number(line.unit_price);
  }, 0) + number(amendmentForm.freight_amount) + number(amendmentForm.other_charges)
    - number(amendmentForm.discount_amount), [amendmentForm]);

  async function choose(id: string) {
    setDetailLoading(true);
    setMode('NONE');
    clearFeedback();
    try {
      setSelected(await getPurchaseOrder(id));
      setCancellationReason('');
    } catch (caught) {
      setError(message(caught, 'Unable to load purchase-order detail.'));
    } finally {
      setDetailLoading(false);
    }
  }

  function startNew() {
    setSelected(null);
    setCreateForm(blankCreateForm());
    setRestrictOnCreate(false);
    setMode('CREATE');
    clearFeedback();
  }

  function startAmendment() {
    if (!selected?.lines) return;
    setAmendmentForm({
      reason: '',
      required_by_date: selected.required_by_date,
      payment_terms_days: String(selected.payment_terms_days),
      freight_amount: selected.freight_amount,
      other_charges: selected.other_charges,
      discount_amount: selected.discount_amount,
      incoterm_code: selected.incoterm_code ?? '',
      delivery_terms: selected.delivery_terms ?? '',
      notes: selected.notes ?? '',
      lines: selected.lines.map((line) => ({
        rfq_line_id: line.rfq_line_id,
        ordered_quantity: line.ordered_quantity,
        unit_price: line.unit_price,
        notes: line.notes ?? '',
      })),
    });
    setMode('AMEND');
    clearFeedback();
  }

  async function create(event: FormEvent) {
    event.preventDefault();
    clearFeedback();
    const errors: Record<string, string> = {};
    if (!createForm.po_number.trim()) errors.po_number = 'Purchase-order number is required.';
    if (!createForm.rfq_id) errors.rfq_id = 'Choose an awarded RFQ.';
    if (!createForm.order_date) errors.order_date = 'Order date is required.';
    if (Object.keys(errors).length) {
      setFieldErrors(errors);
      setError('Correct the highlighted order fields.');
      return;
    }
    if (restrictOnCreate && !canShowRestricted && !window.confirm('Restricting this entry will remove it from your operational view immediately. A user with both visibility permissions must recover it. Continue?')) return;
    setBusy(true);
    try {
      createKey.current ??= crypto.randomUUID();
      const result = await createPurchaseOrder({
        po_number: createForm.po_number.trim().toUpperCase(),
        rfq_id: createForm.rfq_id,
        order_date: createForm.order_date,
        incoterm_code: createForm.incoterm_code.trim().toUpperCase() || null,
        delivery_terms: createForm.delivery_terms.trim() || null,
        notes: createForm.notes.trim() || null,
      }, createKey.current);
      createKey.current = null;
      setMode('NONE');
      if (restrictOnCreate) {
        try {
          await changeTransactionVisibility('PURCHASE_ORDER', result.id, 'RESTRICTED', 0);
        } catch (caught) {
          await refreshAndChoose(result.id);
          setError(message(caught, 'The order was created, but its visibility stayed normal.'));
          return;
        }
      }
      await refresh();
      if (restrictOnCreate && !canShowRestricted) {
        setSelected(null);
        setSuccess('Draft purchase order created and restricted. It is now hidden from this operational view.');
      } else {
        setSelected(await getPurchaseOrder(result.id));
        setSuccess('Draft purchase order created from the awarded supplier quote.');
      }
    } catch (caught) {
      capture(caught, 'Unable to create the purchase order.');
    } finally {
      setBusy(false);
    }
  }

  async function amend(event: FormEvent) {
    event.preventDefault();
    if (!selected) return;
    clearFeedback();
    const errors: Record<string, string> = {};
    if (amendmentForm.reason.trim().length < 3) errors.reason = 'Explain this amendment.';
    if (amendmentForm.lines.some((line) => !line.ordered_quantity || line.unit_price === '')) errors.lines = 'Complete every order line.';
    if (Object.keys(errors).length) {
      setFieldErrors(errors);
      setError('Correct the highlighted amendment fields.');
      return;
    }
    setBusy(true);
    try {
      amendKey.current ??= crypto.randomUUID();
      const body: PurchaseOrderAmendment = {
        reason: amendmentForm.reason.trim(),
        required_by_date: amendmentForm.required_by_date,
        payment_terms_days: Number(amendmentForm.payment_terms_days),
        freight_amount: amendmentForm.freight_amount || '0',
        other_charges: amendmentForm.other_charges || '0',
        discount_amount: amendmentForm.discount_amount || '0',
        incoterm_code: amendmentForm.incoterm_code.trim().toUpperCase() || null,
        delivery_terms: amendmentForm.delivery_terms.trim() || null,
        notes: amendmentForm.notes.trim() || null,
        lines: amendmentForm.lines.map((line) => ({
          rfq_line_id: line.rfq_line_id,
          ordered_quantity: line.ordered_quantity,
          unit_price: line.unit_price,
          notes: line.notes.trim() || null,
        })),
      };
      await amendPurchaseOrder(selected, body, amendKey.current);
      amendKey.current = null;
      setMode('NONE');
      await refreshAndChoose(selected.id);
      setSuccess('Purchase-order amendment recorded as a new immutable revision.');
    } catch (caught) {
      capture(caught, 'Unable to amend the purchase order.');
    } finally {
      setBusy(false);
    }
  }

  async function issue() {
    if (!selected) return;
    clearFeedback();
    setBusy(true);
    try {
      issueKey.current ??= crypto.randomUUID();
      await issuePurchaseOrder(selected, issueKey.current);
      issueKey.current = null;
      await refreshAndChoose(selected.id);
      setSuccess('Purchase order issued to the selected supplier.');
    } catch (caught) {
      capture(caught, 'Unable to issue the purchase order.');
    } finally {
      setBusy(false);
    }
  }

  async function cancel() {
    if (!selected) return;
    if (cancellationReason.trim().length < 3) {
      setFieldErrors({ reason: 'Enter a cancellation reason.' });
      setError('Cancellation evidence is required.');
      return;
    }
    clearFeedback();
    setBusy(true);
    try {
      cancelKey.current ??= crypto.randomUUID();
      await cancelPurchaseOrder(selected, cancellationReason.trim(), cancelKey.current);
      cancelKey.current = null;
      await refreshAndChoose(selected.id);
      setSuccess('Purchase order cancelled with reason evidence.');
    } catch (caught) {
      capture(caught, 'Unable to cancel the purchase order.');
    } finally {
      setBusy(false);
    }
  }

  async function refreshAndChoose(id: string) {
    await refresh();
    setSelected(await getPurchaseOrder(id));
  }

  async function visibilityChanged() {
    await refresh();
    if (!selected) return;
    if (!canShowRestricted) {
      setSelected(null);
      setSuccess('Visibility saved. This order is now hidden from your operational view.');
      return;
    }
    setSelected(await getPurchaseOrder(selected.id));
  }

  function clearFeedback() {
    setError(null);
    setSuccess(null);
    setFieldErrors({});
  }

  function capture(caught: unknown, fallback: string) {
    setError(message(caught, fallback));
    if (isApiError(caught) && caught.fields) {
      setFieldErrors(Object.fromEntries(Object.entries(caught.fields).map(([key, values]) => [key, values[0]])));
    }
  }

  function applySearch(event: FormEvent) {
    event.preventDefault();
    setPage(1);
    setSearch(searchDraft.trim());
  }

  return <>
    <PageHeader
      code="PUR-PO"
      batch="P1 Procure-to-pay"
      title="Purchase Orders"
      description="Convert awarded supplier quotes into controlled orders with immutable commercial revisions."
      onNew={workspace?.allowed_actions.includes('CREATE') ? startNew : undefined}
    />
    <div className="live-notice procurement-notice"><span>LIVE</span>Every order remains linked to its approved requisition, awarded RFQ and supplier quote.</div>
    {workspace?.operational_view ? <div className="live-notice visibility-view-label"><span />{workspace.operational_view.label}</div> : null}
    <div className="kpi-grid procurement-kpis">
      <Kpi text="Orders" value={workspace?.summary.total ?? 0} detail="selected plant" />
      <Kpi text="Draft" value={workspace?.summary.draft ?? 0} detail="not yet issued" />
      <Kpi text="Issued" value={workspace?.summary.issued ?? 0} detail="supplier commitment" />
      <Kpi text="Committed value" value={money(workspace?.summary.committed_total ?? '0', 'INR')} detail="excluding cancellations" compact />
    </div>
    <div className="module-grid requisition-workspace">
      <section className="panel">
        <form className="requisition-toolbar" onSubmit={applySearch}><label>Search<span><input value={searchDraft} onChange={(event) => setSearchDraft(event.target.value)} placeholder="PO, RFQ, requisition or supplier" /><button className="secondary" type="submit">Search</button></span></label><label>Status<select value={statusFilter} onChange={(event) => { setStatusFilter(event.target.value); setPage(1); }}><option value="">All statuses</option>{workspace?.lookups.statuses.map((status) => <option key={status}>{status}</option>)}</select></label>{canShowRestricted ? <label>Visibility<select value={visibilityFilter} onChange={(event) => { setVisibilityFilter(event.target.value); setPage(1); }}><option value="">All entries</option><option value="NORMAL">Normal only</option><option value="RESTRICTED">Restricted only</option></select></label> : null}<label>Order<select value={sort} onChange={(event) => setSort(event.target.value)}>{workspace?.lookups.sorts.map((item) => <option key={item} value={item}>{label(item)}</option>)}</select></label></form>
        {loading && !workspace ? <div className="empty-state">Loading purchase orders…</div> : null}
        {workspace ? <><div className="table-wrap"><table className="requisition-table"><thead><tr><th>Purchase order</th><th>Supplier</th><th>Source</th><th>Required</th><th>Total</th><th>Status</th><th /></tr></thead><tbody>{workspace.data.map((order) => <tr key={order.id} className={selected?.id === order.id ? 'selected-row' : ''}><td><b className="link">{order.po_number}</b><small>revision {order.revision_number} · v{order.record_version}</small></td><td><b>{order.supplier.name}</b><small>{order.supplier.code}</small></td><td><b>{order.rfq.number}</b><small>{order.requisition.number}</small></td><td>{date(order.required_by_date)}</td><td>{money(order.total_amount, order.currency)}</td><td><StatusBadge status={order.status} />{order.visibility?.is_restricted ? <StatusBadge status="RESTRICTED" /> : null}</td><td><button className="secondary compact-button" type="button" onClick={() => void choose(order.id)}>Open</button></td></tr>)}</tbody></table></div>{!workspace.data.length ? <div className="empty-state">No purchase orders match the current filters.</div> : null}<div className="pagination"><button disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>Previous</button><span>Page {workspace.meta.current_page} of {workspace.meta.last_page}</span><button disabled={page >= workspace.meta.last_page} onClick={() => setPage((value) => value + 1)}>Next</button></div></> : null}
      </section>
      <aside className="panel requisition-editor"><div className="panel-head"><h3>{mode === 'CREATE' ? 'New purchase order' : mode === 'AMEND' ? `Amend ${selected?.po_number}` : selected?.po_number ?? 'Purchase-order detail'}</h3>{selected?.allowed_actions.includes('AMEND') && mode === 'NONE' ? <button className="secondary compact-button" onClick={startAmendment}>Amend order</button> : null}</div><div className="requisition-detail-body">
        {detailLoading ? <div className="empty-state">Loading purchase-order detail…</div> : null}
        {error ? <div className="form-error" role="alert"><span>{error}</span></div> : null}
        {success ? <div className="form-success" role="status"><span />{success}</div> : null}
        {mode === 'CREATE' ? <CreateEditor form={createForm} setForm={setCreateForm} workspace={workspace} busy={busy} errors={fieldErrors} restrictEntry={restrictOnCreate} canRestrict={canRestrict} onRestrict={setRestrictOnCreate} onSave={create} onClose={() => setMode('NONE')} /> : null}
        {mode !== 'CREATE' && selected ? <TransactionVisibilityControl transactionType="PURCHASE_ORDER" transactionId={selected.id} visibility={selected.visibility} permissions={permissions} onChanged={visibilityChanged} /> : null}
        {mode === 'AMEND' && selected ? <AmendmentEditor form={amendmentForm} setForm={setAmendmentForm} order={selected} total={amendmentTotal} busy={busy} errors={fieldErrors} onSave={amend} onClose={() => setMode('NONE')} /> : null}
        {mode === 'NONE' && selected ? <OrderDetail order={selected} busy={busy} reason={cancellationReason} errors={fieldErrors} onReason={setCancellationReason} onIssue={() => void issue()} onCancel={() => void cancel()} /> : null}
        {mode === 'NONE' && !selected && !detailLoading ? <div className="empty-state">Choose an order to inspect its lines and revision history, or create one from an awarded RFQ.</div> : null}
      </div></aside>
    </div>
  </>;
}

function CreateEditor({ form, setForm, workspace, busy, errors, restrictEntry, canRestrict, onRestrict, onSave, onClose }: {
  form: CreateForm; setForm: React.Dispatch<React.SetStateAction<CreateForm>>; workspace: Workspace | null;
  busy: boolean; errors: Record<string, string>; restrictEntry: boolean; canRestrict: boolean; onRestrict: (value: boolean) => void; onSave: (event: FormEvent) => void; onClose: () => void;
}) {
  const selected = workspace?.lookups.awarded_rfqs.find((rfq) => rfq.id === form.rfq_id);
  return <form className="requisition-form" onSubmit={onSave}><fieldset disabled={busy}><div className="requisition-field-grid"><label>Purchase-order number<input maxLength={80} value={form.po_number} onChange={(event) => setForm((value) => ({ ...value, po_number: event.target.value }))} /><Field text={errors.po_number} /></label><label>Awarded RFQ<select value={form.rfq_id} onChange={(event) => setForm((value) => ({ ...value, rfq_id: event.target.value }))}><option value="">Select awarded RFQ</option>{workspace?.lookups.awarded_rfqs.map((rfq) => <option key={rfq.id} value={rfq.id}>{rfq.number} · {rfq.supplier.name} · {money(rfq.total_amount, 'INR')}</option>)}</select><Field text={errors.rfq_id} /></label><label>Order date<input type="date" value={form.order_date} onChange={(event) => setForm((value) => ({ ...value, order_date: event.target.value }))} /><Field text={errors.order_date} /></label><label>Incoterm<input maxLength={12} value={form.incoterm_code} onChange={(event) => setForm((value) => ({ ...value, incoterm_code: event.target.value }))} /></label><label className="wide">Delivery terms<textarea rows={3} maxLength={4000} value={form.delivery_terms} onChange={(event) => setForm((value) => ({ ...value, delivery_terms: event.target.value }))} /></label><label className="wide">Order notes<textarea rows={3} maxLength={4000} value={form.notes} onChange={(event) => setForm((value) => ({ ...value, notes: event.target.value }))} /></label></div>{selected ? <div className="source-award-card"><b>{selected.supplier.name}</b><span>{selected.requisition_number} → {selected.number}</span><strong>{money(selected.total_amount, 'INR')}</strong><small>authority ceiling {money(selected.authority_ceiling, 'INR')} · delivery {date(selected.promised_delivery_date)}</small></div> : null}{canRestrict ? <label className="create-visibility-option"><span><input type="checkbox" checked={restrictEntry} onChange={(event) => onRestrict(event.target.checked)} /> Restrict Entry</span><small>{TRANSACTION_VISIBILITY_HELPER}</small></label> : null}<div className="form-actions requisition-save-actions"><button className="secondary" type="button" onClick={onClose}>Close</button><button className="primary" type="submit">Create draft order</button></div></fieldset></form>;
}

function AmendmentEditor({ form, setForm, order, total, busy, errors, onSave, onClose }: {
  form: AmendmentForm; setForm: React.Dispatch<React.SetStateAction<AmendmentForm>>; order: PurchaseOrder;
  total: number; busy: boolean; errors: Record<string, string>; onSave: (event: FormEvent) => void; onClose: () => void;
}) {
  return <form className="requisition-form" onSubmit={onSave}><fieldset disabled={busy}><div className="requisition-field-grid"><label className="wide">Amendment reason<textarea rows={3} maxLength={2000} value={form.reason} onChange={(event) => setForm((value) => ({ ...value, reason: event.target.value }))} /><Field text={errors.reason} /></label><label>Required by<input type="date" value={form.required_by_date} onChange={(event) => setForm((value) => ({ ...value, required_by_date: event.target.value }))} /></label><label>Payment terms (days)<input type="number" min="0" max="3650" value={form.payment_terms_days} onChange={(event) => setForm((value) => ({ ...value, payment_terms_days: event.target.value }))} /></label><label>Freight<input type="number" min="0" step="0.000001" value={form.freight_amount} onChange={(event) => setForm((value) => ({ ...value, freight_amount: event.target.value }))} /></label><label>Other charges<input type="number" min="0" step="0.000001" value={form.other_charges} onChange={(event) => setForm((value) => ({ ...value, other_charges: event.target.value }))} /></label><label>Discount<input type="number" min="0" step="0.000001" value={form.discount_amount} onChange={(event) => setForm((value) => ({ ...value, discount_amount: event.target.value }))} /></label><div className="requisition-form-total"><span>Amended total</span><b>{money(total, 'INR')}</b><small>ceiling {money(order.rfq.authority_ceiling, 'INR')}</small></div><label>Incoterm<input maxLength={12} value={form.incoterm_code} onChange={(event) => setForm((value) => ({ ...value, incoterm_code: event.target.value }))} /></label><label className="wide">Delivery terms<textarea rows={2} maxLength={4000} value={form.delivery_terms} onChange={(event) => setForm((value) => ({ ...value, delivery_terms: event.target.value }))} /></label><label className="wide">Order notes<textarea rows={2} maxLength={4000} value={form.notes} onChange={(event) => setForm((value) => ({ ...value, notes: event.target.value }))} /></label></div><div className="requisition-lines-head"><div><h4>Revision {order.revision_number + 1} lines</h4><small>Quantities cannot exceed the sourced requirement.</small></div></div>{order.lines?.map((line, index) => <div className="requisition-line-card" key={line.rfq_line_id}><div className="operation-line-title"><b>Line {line.line_number} · {line.item.code}</b><span>{line.description}</span></div><div className="requisition-line-grid"><label>Ordered quantity<input aria-label={`Line ${line.line_number} ordered quantity`} type="number" min="0.000001" step="0.000001" value={form.lines[index]?.ordered_quantity ?? ''} onChange={(event) => setForm((value) => ({ ...value, lines: replace(value.lines, index, { ...value.lines[index], ordered_quantity: event.target.value }) }))} /></label><label>Unit price<input aria-label={`Line ${line.line_number} order unit price`} type="number" min="0" step="0.000001" value={form.lines[index]?.unit_price ?? ''} onChange={(event) => setForm((value) => ({ ...value, lines: replace(value.lines, index, { ...value.lines[index], unit_price: event.target.value }) }))} /></label><label className="wide">Line notes<textarea rows={2} maxLength={1000} value={form.lines[index]?.notes ?? ''} onChange={(event) => setForm((value) => ({ ...value, lines: replace(value.lines, index, { ...value.lines[index], notes: event.target.value }) }))} /></label></div></div>)}<Field text={errors.lines} /><div className="form-actions requisition-save-actions"><button className="secondary" type="button" onClick={onClose}>Close without saving</button><button className="primary" type="submit">Record amendment</button></div></fieldset></form>;
}

function OrderDetail({ order, busy, reason, errors, onReason, onIssue, onCancel }: {
  order: PurchaseOrder; busy: boolean; reason: string; errors: Record<string, string>;
  onReason: (value: string) => void; onIssue: () => void; onCancel: () => void;
}) {
  return <div className="requisition-detail"><div className="detail-status"><StatusBadge status={order.status} /><b>{money(order.total_amount, order.currency)}</b><span>revision {order.revision_number} · v{order.record_version}</span></div><dl className="control-definition"><Datum text="Supplier" value={order.supplier.name} /><Datum text="RFQ" value={order.rfq.number} /><Datum text="Requisition" value={order.requisition.number} /><Datum text="Department" value={order.requisition.department} /><Datum text="Order date" value={date(order.order_date)} /><Datum text="Required by" value={date(order.required_by_date)} /><Datum text="Payment terms" value={`${order.payment_terms_days} days`} /><Datum text="Incoterm" value={order.incoterm_code ?? 'Not recorded'} /><Datum wide text="Purpose" value={order.requisition.purpose} /><Datum wide text="Delivery terms" value={order.delivery_terms ?? 'None recorded'} /></dl><section className="requisition-line-detail"><h4>Ordered lines</h4><div className="table-wrap"><table><thead><tr><th>Line</th><th>Item</th><th>Quantity</th><th>Unit price</th><th>Total</th></tr></thead><tbody>{order.lines?.map((line) => <tr key={line.id}><td>{line.line_number}</td><td><b>{line.item.code}</b><small>{line.description}</small></td><td>{decimal(line.ordered_quantity)} {line.uom_code}</td><td>{money(line.unit_price, order.currency)}</td><td>{money(line.line_total, order.currency)}</td></tr>)}</tbody></table></div><dl className="commercial-totals"><div><dt>Subtotal</dt><dd>{money(order.subtotal, order.currency)}</dd></div><div><dt>Freight + other</dt><dd>{money(number(order.freight_amount) + number(order.other_charges), order.currency)}</dd></div><div><dt>Discount</dt><dd>− {money(order.discount_amount, order.currency)}</dd></div><div><dt>Order total</dt><dd>{money(order.total_amount, order.currency)}</dd></div></dl></section><section className="requisition-line-detail"><h4>Immutable revisions</h4><div className="revision-list">{order.revisions?.map((revision) => <div key={revision.id}><span>Revision {revision.revision_number}</span><b>{revision.reason}</b><small>{revision.created_by.name} · {timestamp(revision.created_at)}</small></div>)}</div></section>{order.allowed_actions.includes('ISSUE') ? <div className="inventory-command"><b>Issue purchase order</b><p>Confirms this revision as the supplier commitment.</p><button className="primary" disabled={busy} onClick={onIssue}>Issue purchase order</button></div> : null}{order.cancellation_reason ? <div className="form-error"><span>Cancelled: {order.cancellation_reason}</span></div> : null}{order.allowed_actions.includes('CANCEL') ? <div className="inventory-command destructive-command"><b>Cancel purchase order</b><label>Cancellation reason<textarea rows={3} maxLength={2000} value={reason} onChange={(event) => onReason(event.target.value)} /></label><Field text={errors.reason} /><button className="secondary" disabled={busy} onClick={onCancel}>Cancel purchase order</button></div> : null}</div>;
}

function blankCreateForm(): CreateForm { return { po_number: '', rfq_id: '', order_date: offsetDate(0), incoterm_code: 'DAP', delivery_terms: '', notes: '' }; }
function blankAmendmentForm(): AmendmentForm { return { reason: '', required_by_date: offsetDate(30), payment_terms_days: '30', freight_amount: '0', other_charges: '0', discount_amount: '0', incoterm_code: '', delivery_terms: '', notes: '', lines: [] }; }
function Kpi({ text, value, detail, compact = false }: { text: string; value: string | number; detail: string; compact?: boolean }) { return <div className="kpi"><span>{text}</span><b className={compact ? 'compact' : undefined}>{value}</b><small>{detail}</small></div>; }
function Datum({ text, value, wide = false }: { text: string; value: string; wide?: boolean }) { return <div className={wide ? 'wide' : undefined}><dt>{text}</dt><dd>{value}</dd></div>; }
function Field({ text }: { text?: string }) { return text ? <small className="field-error">{text}</small> : null; }
function message(error: unknown, fallback: string) { return isApiError(error) ? error.message : error instanceof Error ? error.message : fallback; }
function number(value: unknown) { const result = Number(value); return Number.isFinite(result) ? result : 0; }
function money(value: string | number, currency: string) { return new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 2 }).format(number(value)); }
function decimal(value: string | number) { return new Intl.NumberFormat('en-IN', { maximumFractionDigits: 6 }).format(number(value)); }
function date(value: string) { return new Intl.DateTimeFormat('en-IN', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`)); }
function timestamp(value: string) { return new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)); }
function label(value: string) { return value.toLowerCase().replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()); }
function replace<T>(values: T[], index: number, value: T) { return values.map((item, position) => position === index ? value : item); }
function offsetDate(days: number) { const value = new Date(); value.setUTCDate(value.getUTCDate() + days); return value.toISOString().slice(0, 10); }

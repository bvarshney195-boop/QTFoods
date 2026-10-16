import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { isApiError } from '../api/client';
import {
  awardRfq,
  cancelRfq,
  createRfq,
  getRfq,
  issueRfq,
  listRfqs,
  recordSupplierQuote,
  updateRfq,
  type RequestForQuotation,
  type RfqWorkspace,
  type SupplierQuoteWrite,
} from '../api/procurementSourcing';
import { useErpSession } from '../app/ErpSessionContext';
import { PageHeader } from './PageHeader';
import { StatusBadge } from './StatusBadge';

type RfqForm = {
  rfq_number: string;
  requisition_id: string;
  response_due_date: string;
  commercial_terms: string;
  supplier_ids: string[];
};

type QuoteForm = {
  supplier_party_id: string;
  quote_number: string;
  quote_date: string;
  valid_until: string;
  promised_delivery_date: string;
  payment_terms_days: string;
  freight_amount: string;
  other_charges: string;
  discount_amount: string;
  notes: string;
  lines: { rfq_line_id: string; unit_price: string; notes: string }[];
};

export function RequestForQuotationWorkspace() {
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const [workspace, setWorkspace] = useState<RfqWorkspace | null>(null);
  const [selected, setSelected] = useState<RequestForQuotation | null>(null);
  const [editing, setEditing] = useState(false);
  const [quoteEditing, setQuoteEditing] = useState(false);
  const [form, setForm] = useState<RfqForm>(blankRfqForm);
  const [quoteForm, setQuoteForm] = useState<QuoteForm>(blankQuoteForm());
  const [awardQuoteId, setAwardQuoteId] = useState('');
  const [awardReason, setAwardReason] = useState('');
  const [cancellationReason, setCancellationReason] = useState('');
  const [searchDraft, setSearchDraft] = useState('');
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [sort, setSort] = useState('NEWEST');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const saveKey = useRef<string | null>(null);
  const issueKey = useRef<string | null>(null);
  const quoteKey = useRef<string | null>(null);
  const awardKey = useRef<string | null>(null);
  const cancelKey = useRef<string | null>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      setWorkspace(await listRfqs({ page, q: search || undefined, status: statusFilter || undefined, sort }));
    } catch (caught) {
      setError(message(caught, 'Unable to load requests for quotation.'));
    } finally {
      setLoading(false);
    }
  }, [contextKey, page, search, sort, statusFilter]);

  useEffect(() => { void refresh(); }, [refresh]);
  useEffect(() => {
    setSelected(null);
    setEditing(false);
    setQuoteEditing(false);
    setForm(blankRfqForm());
    setSearchDraft('');
    setSearch('');
    setStatusFilter('');
    setSort('NEWEST');
    setPage(1);
    clearFeedback();
  }, [contextKey]);
  useEffect(() => { saveKey.current = null; }, [form]);
  useEffect(() => { quoteKey.current = null; }, [quoteForm]);
  useEffect(() => { awardKey.current = null; }, [awardQuoteId, awardReason]);
  useEffect(() => { cancelKey.current = null; }, [cancellationReason]);
  useEffect(() => {
    issueKey.current = null;
    quoteKey.current = null;
    awardKey.current = null;
    cancelKey.current = null;
  }, [selected?.id, selected?.record_version]);

  const quoteTotal = useMemo(() => {
    const lineTotal = quoteForm.lines.reduce((total, line, index) => {
      const quantity = Number(selected?.lines?.[index]?.quantity ?? 0);
      const price = Number(line.unit_price);
      return total + (Number.isFinite(quantity) && Number.isFinite(price) ? quantity * price : 0);
    }, 0);
    return lineTotal + number(quoteForm.freight_amount) + number(quoteForm.other_charges)
      - number(quoteForm.discount_amount);
  }, [quoteForm, selected?.lines]);

  async function choose(id: string, openEditor = true) {
    setDetailLoading(true);
    setEditing(false);
    setQuoteEditing(false);
    clearFeedback();
    try {
      const detail = await getRfq(id);
      setSelected(detail);
      setForm(toRfqForm(detail));
      setEditing(openEditor && detail.allowed_actions.includes('UPDATE'));
      setAwardQuoteId('');
      setAwardReason('');
      setCancellationReason('');
    } catch (caught) {
      setError(message(caught, 'Unable to load RFQ detail.'));
    } finally {
      setDetailLoading(false);
    }
  }

  function startNew() {
    setSelected(null);
    setEditing(true);
    setQuoteEditing(false);
    setForm(blankRfqForm());
    clearFeedback();
  }

  function startEdit() {
    if (!selected) return;
    setForm(toRfqForm(selected));
    setEditing(true);
    setQuoteEditing(false);
    clearFeedback();
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    clearFeedback();
    const errors: Record<string, string> = {};
    if (!selected && !form.rfq_number.trim()) errors.rfq_number = 'RFQ number is required.';
    if (!selected && !form.requisition_id) errors.requisition_id = 'Choose an approved requisition.';
    if (!form.response_due_date) errors.response_due_date = 'Response due date is required.';
    if (form.supplier_ids.length < 2) errors.supplier_ids = 'Choose at least two suppliers.';
    if (Object.keys(errors).length) {
      setFieldErrors(errors);
      setError('Correct the highlighted RFQ fields.');
      return;
    }
    setBusy(true);
    try {
      saveKey.current ??= crypto.randomUUID();
      const write = {
        response_due_date: form.response_due_date,
        commercial_terms: form.commercial_terms.trim() || null,
        supplier_ids: form.supplier_ids,
      };
      const result = selected
        ? await updateRfq(selected, write, saveKey.current)
        : await createRfq({
          ...write,
          rfq_number: form.rfq_number.trim().toUpperCase(),
          requisition_id: form.requisition_id,
        }, saveKey.current);
      saveKey.current = null;
      setEditing(false);
      setSuccess(selected ? 'Draft RFQ updated.' : 'Draft RFQ created from the approved requisition.');
      await refresh();
      await choose(result.id, false);
      setSuccess(selected ? 'Draft RFQ updated.' : 'Draft RFQ created from the approved requisition.');
    } catch (caught) {
      capture(caught, 'Unable to save the RFQ.');
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
      await issueRfq(selected, issueKey.current);
      issueKey.current = null;
      await refreshAndChoose(selected.id);
      setSuccess('RFQ issued to all selected suppliers.');
    } catch (caught) {
      capture(caught, 'Unable to issue the RFQ.');
    } finally {
      setBusy(false);
    }
  }

  function startQuote(supplierId = '') {
    if (!selected?.lines) return;
    const existing = selected.suppliers?.find((entry) => entry.supplier.id === supplierId)?.quote;
    setQuoteForm(existing ? {
      supplier_party_id: supplierId,
      quote_number: existing.quote_number,
      quote_date: existing.quote_date,
      valid_until: existing.valid_until,
      promised_delivery_date: existing.promised_delivery_date,
      payment_terms_days: String(existing.payment_terms_days),
      freight_amount: existing.freight_amount,
      other_charges: existing.other_charges,
      discount_amount: existing.discount_amount,
      notes: existing.notes ?? '',
      lines: selected.lines.map((line) => {
        const quoted = existing.lines.find((item) => item.rfq_line_id === line.id);
        return { rfq_line_id: line.id, unit_price: quoted?.unit_price ?? '', notes: quoted?.notes ?? '' };
      }),
    } : blankQuoteForm(selected, supplierId));
    setQuoteEditing(true);
    setEditing(false);
    clearFeedback();
  }

  async function saveQuote(event: FormEvent) {
    event.preventDefault();
    if (!selected) return;
    clearFeedback();
    const errors: Record<string, string> = {};
    if (!quoteForm.supplier_party_id) errors.supplier_party_id = 'Choose an invited supplier.';
    if (!quoteForm.quote_number.trim()) errors.quote_number = 'Supplier quote number is required.';
    if (quoteForm.lines.some((line) => line.unit_price === '')) errors.quote_lines = 'Price every RFQ line.';
    if (Object.keys(errors).length) {
      setFieldErrors(errors);
      setError('Correct the highlighted supplier-quote fields.');
      return;
    }
    setBusy(true);
    try {
      quoteKey.current ??= crypto.randomUUID();
      const body: SupplierQuoteWrite = {
        supplier_party_id: quoteForm.supplier_party_id,
        quote_number: quoteForm.quote_number.trim().toUpperCase(),
        quote_date: quoteForm.quote_date,
        valid_until: quoteForm.valid_until,
        promised_delivery_date: quoteForm.promised_delivery_date,
        payment_terms_days: Number(quoteForm.payment_terms_days),
        freight_amount: quoteForm.freight_amount || '0',
        other_charges: quoteForm.other_charges || '0',
        discount_amount: quoteForm.discount_amount || '0',
        notes: quoteForm.notes.trim() || null,
        lines: quoteForm.lines.map((line) => ({
          rfq_line_id: line.rfq_line_id,
          unit_price: line.unit_price,
          notes: line.notes.trim() || null,
        })),
      };
      await recordSupplierQuote(selected, body, quoteKey.current);
      quoteKey.current = null;
      setQuoteEditing(false);
      await refreshAndChoose(selected.id);
      setSuccess('Supplier quote recorded and comparison refreshed.');
    } catch (caught) {
      capture(caught, 'Unable to record the supplier quote.');
    } finally {
      setBusy(false);
    }
  }

  async function award() {
    if (!selected || !awardQuoteId) {
      setError('Choose a supplier quote to award.');
      return;
    }
    clearFeedback();
    setBusy(true);
    try {
      awardKey.current ??= crypto.randomUUID();
      await awardRfq(selected, awardQuoteId, awardReason.trim() || null, awardKey.current);
      awardKey.current = null;
      await refreshAndChoose(selected.id);
      setSuccess('Supplier award recorded with comparison evidence.');
    } catch (caught) {
      capture(caught, 'Unable to award the RFQ.');
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
      await cancelRfq(selected, cancellationReason.trim(), cancelKey.current);
      cancelKey.current = null;
      await refreshAndChoose(selected.id);
      setSuccess('RFQ cancelled. The requisition is available for a new sourcing event.');
    } catch (caught) {
      capture(caught, 'Unable to cancel the RFQ.');
    } finally {
      setBusy(false);
    }
  }

  async function refreshAndChoose(id: string) {
    await refresh();
    const detail = await getRfq(id);
    setSelected(detail);
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
      code="PUR-RFQ"
      batch="P1 Procure-to-pay"
      title="RFQ & Supplier Comparison"
      description="Source approved requirements through invited supplier quotes, comparable landed values and evidence-backed award."
      onNew={workspace?.allowed_actions.includes('CREATE') ? startNew : undefined}
    />
    <div className="live-notice procurement-notice">
      <span>LIVE</span>
      Approved requisition lines, supplier eligibility, quote totals and award ceilings are enforced by the server.
    </div>
    <div className="kpi-grid procurement-kpis">
      <Kpi text="RFQs" value={workspace?.summary.total ?? 0} detail="selected plant" />
      <Kpi text="Issued" value={workspace?.summary.issued ?? 0} detail="accepting responses" />
      <Kpi text="Awarded" value={workspace?.summary.awarded ?? 0} detail="supplier selected" />
      <Kpi text="Awarded value" value={money(workspace?.summary.awarded_total ?? '0', 'INR')} detail="within approved ceilings" compact />
    </div>
    <div className="module-grid requisition-workspace">
      <section className="panel">
        <form className="requisition-toolbar" onSubmit={applySearch}>
          <label>Search
            <span><input value={searchDraft} onChange={(event) => setSearchDraft(event.target.value)} placeholder="RFQ, requisition or supplier" /><button className="secondary" type="submit">Search</button></span>
          </label>
          <label>Status<select value={statusFilter} onChange={(event) => { setStatusFilter(event.target.value); setPage(1); }}><option value="">All statuses</option>{workspace?.lookups.statuses.map((status) => <option key={status}>{status}</option>)}</select></label>
          <label>Order<select value={sort} onChange={(event) => setSort(event.target.value)}>{workspace?.lookups.sorts.map((item) => <option key={item} value={item}>{label(item)}</option>)}</select></label>
        </form>
        {loading && !workspace ? <div className="empty-state">Loading sourcing events…</div> : null}
        {workspace ? <>
          <div className="table-wrap"><table className="requisition-table"><thead><tr><th>RFQ</th><th>Requirement</th><th>Due</th><th>Responses</th><th>Status</th><th /></tr></thead><tbody>
            {workspace.data.map((rfq) => <tr key={rfq.id} className={selected?.id === rfq.id ? 'selected-row' : ''}>
              <td><b className="link">{rfq.rfq_number}</b><small>v{rfq.record_version} · {rfq.supplier_count} suppliers</small></td>
              <td><b>{rfq.requisition.number}</b><small>{money(rfq.estimated_total_snapshot, rfq.currency)}</small></td>
              <td>{date(rfq.response_due_date)}<small>delivery {date(rfq.required_by_date)}</small></td>
              <td><b>{rfq.quote_count} / {rfq.supplier_count}</b><small>quotes received</small></td>
              <td><StatusBadge status={rfq.status} /></td>
              <td><button className="secondary compact-button" type="button" onClick={() => void choose(rfq.id)}>Open</button></td>
            </tr>)}</tbody></table></div>
          {!workspace.data.length ? <div className="empty-state">No RFQs match the current filters.</div> : null}
          <div className="pagination"><button disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>Previous</button><span>Page {workspace.meta.current_page} of {workspace.meta.last_page}</span><button disabled={page >= workspace.meta.last_page} onClick={() => setPage((value) => value + 1)}>Next</button></div>
        </> : null}
      </section>
      <aside className="panel requisition-editor">
        <div className="panel-head"><h3>{editing ? (selected ? 'Edit draft RFQ' : 'New RFQ') : quoteEditing ? 'Supplier quote' : selected?.rfq_number ?? 'RFQ detail'}</h3>{selected?.allowed_actions.includes('UPDATE') && !editing && !quoteEditing ? <button className="secondary compact-button" onClick={startEdit}>Edit RFQ</button> : null}</div>
        <div className="requisition-detail-body">
          {detailLoading ? <div className="empty-state">Loading RFQ detail…</div> : null}
          {error ? <div className="form-error" role="alert"><span>{error}</span></div> : null}
          {success ? <div className="form-success" role="status"><span />{success}</div> : null}
          {editing ? <RfqEditor form={form} setForm={setForm} workspace={workspace} selected={selected} busy={busy} errors={fieldErrors} onSave={save} onClose={() => setEditing(false)} /> : null}
          {quoteEditing && selected ? <QuoteEditor form={quoteForm} setForm={setQuoteForm} rfq={selected} total={quoteTotal} busy={busy} errors={fieldErrors} onSave={saveQuote} onClose={() => setQuoteEditing(false)} /> : null}
          {!editing && !quoteEditing && selected ? <RfqDetail
            rfq={selected} busy={busy} awardQuoteId={awardQuoteId} awardReason={awardReason}
            cancellationReason={cancellationReason} errors={fieldErrors}
            onIssue={() => void issue()} onStartQuote={startQuote} onAwardQuote={setAwardQuoteId}
            onAwardReason={setAwardReason} onAward={() => void award()}
            onCancellationReason={setCancellationReason} onCancel={() => void cancel()}
          /> : null}
          {!editing && !quoteEditing && !selected && !detailLoading ? <div className="empty-state">Choose an RFQ to inspect sourcing evidence, or create one from an approved requisition.</div> : null}
        </div>
      </aside>
    </div>
  </>;
}

function RfqEditor({ form, setForm, workspace, selected, busy, errors, onSave, onClose }: {
  form: RfqForm; setForm: React.Dispatch<React.SetStateAction<RfqForm>>; workspace: RfqWorkspace | null;
  selected: RequestForQuotation | null; busy: boolean; errors: Record<string, string>;
  onSave: (event: FormEvent) => void; onClose: () => void;
}) {
  const requisitions = workspace ? editorRequisitions(workspace, selected) : [];
  const suppliers = workspace ? editorSuppliers(workspace, selected) : [];
  return <form className="requisition-form" onSubmit={onSave}><fieldset disabled={busy}><div className="requisition-field-grid">
    <label>RFQ number<input maxLength={80} readOnly={Boolean(selected)} className={selected ? 'read-only' : ''} value={form.rfq_number} onChange={(event) => setForm((value) => ({ ...value, rfq_number: event.target.value }))} /><Field text={errors.rfq_number} /></label>
    <label>Approved requisition<select disabled={Boolean(selected)} value={form.requisition_id} onChange={(event) => setForm((value) => ({ ...value, requisition_id: event.target.value }))}><option value="">Select requisition</option>{requisitions.map((item) => <option key={item.id} value={item.id}>{item.number} · {money(item.estimated_total, item.currency)} · due {date(item.required_by_date)}</option>)}</select><Field text={errors.requisition_id} /></label>
    <label>Response due date<input type="date" value={form.response_due_date} onChange={(event) => setForm((value) => ({ ...value, response_due_date: event.target.value }))} /><Field text={errors.response_due_date} /></label>
    <label className="wide">Commercial instructions<textarea rows={3} maxLength={4000} value={form.commercial_terms} onChange={(event) => setForm((value) => ({ ...value, commercial_terms: event.target.value }))} /></label>
  </div>
  <div className="requisition-lines-head"><div><h4>Invited suppliers</h4><small>Select at least two active supplier masters.</small></div></div>
  <div className="supplier-choice-grid">{suppliers.map((supplier) => <label key={supplier.id} className={form.supplier_ids.includes(supplier.id) ? 'selected' : ''}><input type="checkbox" checked={form.supplier_ids.includes(supplier.id)} onChange={() => setForm((value) => ({ ...value, supplier_ids: toggle(value.supplier_ids, supplier.id) }))} /><span><b>{supplier.name}</b><small>{supplier.code} · {supplier.payment_terms_days ?? 0} days · {supplier.incoterm_code ?? 'terms pending'}</small></span></label>)}</div><Field text={errors.supplier_ids} />
  <div className="form-actions requisition-save-actions"><button className="secondary" type="button" onClick={onClose}>{selected ? 'Close without saving' : 'Close'}</button><button className="primary" type="submit">{selected ? 'Save RFQ changes' : 'Create draft RFQ'}</button></div>
  </fieldset></form>;
}

function QuoteEditor({ form, setForm, rfq, total, busy, errors, onSave, onClose }: {
  form: QuoteForm; setForm: React.Dispatch<React.SetStateAction<QuoteForm>>; rfq: RequestForQuotation;
  total: number; busy: boolean; errors: Record<string, string>; onSave: (event: FormEvent) => void; onClose: () => void;
}) {
  const selectSupplier = (supplierId: string) => {
    const existing = rfq.suppliers?.find((entry) => entry.supplier.id === supplierId)?.quote;
    setForm(existing ? {
      supplier_party_id: supplierId, quote_number: existing.quote_number, quote_date: existing.quote_date,
      valid_until: existing.valid_until, promised_delivery_date: existing.promised_delivery_date,
      payment_terms_days: String(existing.payment_terms_days), freight_amount: existing.freight_amount,
      other_charges: existing.other_charges, discount_amount: existing.discount_amount, notes: existing.notes ?? '',
      lines: (rfq.lines ?? []).map((line) => { const quoted = existing.lines.find((item) => item.rfq_line_id === line.id); return { rfq_line_id: line.id, unit_price: quoted?.unit_price ?? '', notes: quoted?.notes ?? '' }; }),
    } : blankQuoteForm(rfq, supplierId));
  };
  return <form className="requisition-form" onSubmit={onSave}><fieldset disabled={busy}><div className="requisition-field-grid">
    <label>Invited supplier<select aria-label="Quote supplier" value={form.supplier_party_id} onChange={(event) => selectSupplier(event.target.value)}><option value="">Select supplier</option>{rfq.suppliers?.map((entry) => <option key={entry.supplier.id} value={entry.supplier.id}>{entry.supplier.name} · {entry.status}</option>)}</select><Field text={errors.supplier_party_id} /></label>
    <label>Supplier quote number<input maxLength={100} value={form.quote_number} onChange={(event) => setForm((value) => ({ ...value, quote_number: event.target.value }))} /><Field text={errors.quote_number} /></label>
    <label>Quote date<input type="date" value={form.quote_date} onChange={(event) => setForm((value) => ({ ...value, quote_date: event.target.value }))} /></label>
    <label>Valid until<input type="date" value={form.valid_until} onChange={(event) => setForm((value) => ({ ...value, valid_until: event.target.value }))} /></label>
    <label>Promised delivery<input type="date" value={form.promised_delivery_date} onChange={(event) => setForm((value) => ({ ...value, promised_delivery_date: event.target.value }))} /></label>
    <label>Payment terms (days)<input type="number" min="0" max="3650" value={form.payment_terms_days} onChange={(event) => setForm((value) => ({ ...value, payment_terms_days: event.target.value }))} /></label>
    <label>Freight<input type="number" min="0" step="0.000001" value={form.freight_amount} onChange={(event) => setForm((value) => ({ ...value, freight_amount: event.target.value }))} /></label>
    <label>Other charges<input type="number" min="0" step="0.000001" value={form.other_charges} onChange={(event) => setForm((value) => ({ ...value, other_charges: event.target.value }))} /></label>
    <label>Discount<input type="number" min="0" step="0.000001" value={form.discount_amount} onChange={(event) => setForm((value) => ({ ...value, discount_amount: event.target.value }))} /></label>
    <div className="requisition-form-total"><span>Comparable total</span><b>{money(total, 'INR')}</b></div>
    <label className="wide">Quote notes<textarea rows={2} maxLength={4000} value={form.notes} onChange={(event) => setForm((value) => ({ ...value, notes: event.target.value }))} /></label>
  </div><div className="requisition-lines-head"><div><h4>Quoted lines</h4><small>Every source line must be priced.</small></div></div>
  {(rfq.lines ?? []).map((line, index) => <div className="requisition-line-card" key={line.id}><div className="operation-line-title"><b>Line {line.line_number} · {line.item.code}</b><span>{decimal(line.quantity)} {line.uom_code}</span></div><div className="requisition-line-grid"><label>Unit price<input aria-label={`Line ${line.line_number} unit price`} type="number" min="0" step="0.000001" value={form.lines[index]?.unit_price ?? ''} onChange={(event) => setForm((value) => ({ ...value, lines: replace(value.lines, index, { ...value.lines[index], unit_price: event.target.value }) }))} /></label><label>Line total<input readOnly className="read-only" value={money(number(form.lines[index]?.unit_price) * number(line.quantity), 'INR')} /></label><label className="wide">Line notes<textarea rows={2} maxLength={1000} value={form.lines[index]?.notes ?? ''} onChange={(event) => setForm((value) => ({ ...value, lines: replace(value.lines, index, { ...value.lines[index], notes: event.target.value }) }))} /></label></div></div>)}
  <Field text={errors.quote_lines} /><div className="form-actions requisition-save-actions"><button className="secondary" type="button" onClick={onClose}>Close without saving</button><button className="primary" type="submit">Record supplier quote</button></div></fieldset></form>;
}

function RfqDetail({ rfq, busy, awardQuoteId, awardReason, cancellationReason, errors, onIssue, onStartQuote, onAwardQuote, onAwardReason, onAward, onCancellationReason, onCancel }: {
  rfq: RequestForQuotation; busy: boolean; awardQuoteId: string; awardReason: string; cancellationReason: string;
  errors: Record<string, string>; onIssue: () => void; onStartQuote: (supplierId?: string) => void;
  onAwardQuote: (value: string) => void; onAwardReason: (value: string) => void; onAward: () => void;
  onCancellationReason: (value: string) => void; onCancel: () => void;
}) {
  return <div className="requisition-detail"><div className="detail-status"><StatusBadge status={rfq.status} /><b>{money(rfq.estimated_total_snapshot, rfq.currency)}</b><span>v{rfq.record_version}</span></div>
    <dl className="control-definition"><Datum text="Requisition" value={rfq.requisition.number} /><Datum text="Department" value={rfq.requisition.department} /><Datum text="Response due" value={date(rfq.response_due_date)} /><Datum text="Required by" value={date(rfq.required_by_date)} /><Datum text="Suppliers / quotes" value={`${rfq.supplier_count} / ${rfq.quote_count}`} /><Datum text="Created by" value={rfq.created_by.name} /><Datum wide text="Purpose" value={rfq.requisition.purpose} /><Datum wide text="Commercial terms" value={rfq.commercial_terms ?? 'None recorded'} /></dl>
    <section className="requisition-line-detail"><h4>Source lines</h4><div className="table-wrap"><table><thead><tr><th>Line</th><th>Item</th><th>Quantity</th></tr></thead><tbody>{rfq.lines?.map((line) => <tr key={line.id}><td>{line.line_number}</td><td><b>{line.item.code}</b><small>{line.description}</small></td><td>{decimal(line.quantity)} {line.uom_code}</td></tr>)}</tbody></table></div></section>
    <section className="requisition-line-detail"><h4>Invited suppliers</h4><div className="supplier-response-list">{rfq.suppliers?.map((entry) => <div key={entry.supplier.id}><span><b>{entry.supplier.name}</b><small>{entry.supplier.code}</small></span><StatusBadge status={entry.status} />{entry.quote ? <button className="secondary compact-button" disabled={busy || !rfq.allowed_actions.includes('RECORD_QUOTE')} onClick={() => onStartQuote(entry.supplier.id)}>Edit quote</button> : null}</div>)}</div>{rfq.allowed_actions.includes('RECORD_QUOTE') ? <button className="secondary" disabled={busy} onClick={() => onStartQuote()}>Record supplier quote</button> : null}</section>
    {rfq.comparison?.length ? <section className="requisition-line-detail"><h4>Comparable supplier responses</h4><div className="table-wrap"><table className="comparison-table"><thead><tr><th>Rank</th><th>Supplier</th><th>Total</th><th>Delivery</th><th>Terms</th><th /></tr></thead><tbody>{rfq.comparison.map((entry) => <tr key={entry.quote_id} className={awardQuoteId === entry.quote_id ? 'selected-row' : ''}><td>#{entry.rank}</td><td><b>{entry.supplier.name}</b><small>{entry.supplier.code}</small></td><td>{money(entry.total_amount, 'INR')}<small>+ {money(entry.variance_from_lowest, 'INR')} vs lowest</small></td><td>{date(entry.promised_delivery_date)}<small>{entry.meets_required_date ? 'On time' : 'After required date'}</small></td><td>{entry.payment_terms_days} days</td><td>{rfq.allowed_actions.includes('AWARD') ? <button className="secondary compact-button" onClick={() => onAwardQuote(entry.quote_id)}>Select</button> : null}</td></tr>)}</tbody></table></div></section> : null}
    {rfq.allowed_actions.includes('ISSUE') ? <div className="inventory-command"><b>Issue sourcing event</b><p>Locks the requirement and invites every selected supplier.</p><button className="primary" disabled={busy} onClick={onIssue}>Issue RFQ</button></div> : null}
    {rfq.allowed_actions.includes('AWARD') ? <div className="inventory-command"><b>Award supplier</b><p>Non-lowest or late awards require an explicit explanation.</p><label>Award reason<textarea rows={3} maxLength={2000} value={awardReason} onChange={(event) => onAwardReason(event.target.value)} /></label><Field text={errors.award_reason} /><button className="primary" disabled={busy || !awardQuoteId} onClick={onAward}>Award selected quote</button></div> : null}
    {rfq.award ? <section className="requisition-approval-evidence"><h4>Award evidence</h4><dl className="control-definition"><Datum text="Supplier" value={rfq.award.supplier.name} /><Datum text="Awarded total" value={money(rfq.award.total_amount, 'INR')} /><Datum wide text="Reason" value={rfq.award.reason ?? 'Lowest compliant on-time offer'} /></dl></section> : null}
    {rfq.cancellation_reason ? <div className="form-error"><span>Cancelled: {rfq.cancellation_reason}</span></div> : null}
    {rfq.allowed_actions.includes('CANCEL') ? <div className="inventory-command destructive-command"><b>Cancel RFQ</b><label>Cancellation reason<textarea rows={3} maxLength={2000} value={cancellationReason} onChange={(event) => onCancellationReason(event.target.value)} /></label><Field text={errors.reason} /><button className="secondary" disabled={busy} onClick={onCancel}>Cancel RFQ</button></div> : null}
  </div>;
}

function blankRfqForm(): RfqForm {
  return { rfq_number: '', requisition_id: '', response_due_date: offsetDate(3), commercial_terms: '', supplier_ids: [] };
}

function toRfqForm(record: RequestForQuotation): RfqForm {
  return {
    rfq_number: record.rfq_number,
    requisition_id: record.requisition.id,
    response_due_date: record.response_due_date,
    commercial_terms: record.commercial_terms ?? '',
    supplier_ids: record.suppliers?.map((item) => item.supplier.id) ?? [],
  };
}

function editorRequisitions(workspace: RfqWorkspace, selected: RequestForQuotation | null): RfqWorkspace['lookups']['approved_requisitions'] {
  const records = [...workspace.lookups.approved_requisitions];
  if (selected && !records.some((item) => item.id === selected.requisition.id)) {
    records.unshift({
      id: selected.requisition.id,
      number: selected.requisition.number,
      department: selected.requisition.department,
      purpose: selected.requisition.purpose,
      required_by_date: selected.required_by_date,
      currency: selected.currency,
      estimated_total: selected.estimated_total_snapshot,
      record_version: selected.record_version,
    });
  }
  return records;
}

function editorSuppliers(workspace: RfqWorkspace, selected: RequestForQuotation | null): RfqWorkspace['lookups']['suppliers'] {
  const records = [...workspace.lookups.suppliers];
  const known = new Set(records.map((item) => item.id));
  for (const response of selected?.suppliers ?? []) {
    if (!known.has(response.supplier.id)) {
      records.push(response.supplier);
      known.add(response.supplier.id);
    }
  }
  return records;
}

function blankQuoteForm(rfq?: RequestForQuotation, supplierId = ''): QuoteForm {
  return {
    supplier_party_id: supplierId, quote_number: '', quote_date: offsetDate(0), valid_until: offsetDate(15),
    promised_delivery_date: rfq?.required_by_date ?? offsetDate(25), payment_terms_days: '30',
    freight_amount: '0', other_charges: '0', discount_amount: '0', notes: '',
    lines: (rfq?.lines ?? []).map((line) => ({ rfq_line_id: line.id, unit_price: '', notes: '' })),
  };
}

function Kpi({ text, value, detail, compact = false }: { text: string; value: string | number; detail: string; compact?: boolean }) { return <div className="kpi"><span>{text}</span><b className={compact ? 'compact' : undefined}>{value}</b><small>{detail}</small></div>; }
function Datum({ text, value, wide = false }: { text: string; value: string; wide?: boolean }) { return <div className={wide ? 'wide' : undefined}><dt>{text}</dt><dd>{value}</dd></div>; }
function Field({ text }: { text?: string }) { return text ? <small className="field-error">{text}</small> : null; }
function message(error: unknown, fallback: string) { return isApiError(error) ? error.message : error instanceof Error ? error.message : fallback; }
function number(value: unknown) { const result = Number(value); return Number.isFinite(result) ? result : 0; }
function money(value: string | number, currency: string) { return new Intl.NumberFormat('en-IN', { style: 'currency', currency, maximumFractionDigits: 2 }).format(number(value)); }
function decimal(value: string | number) { return new Intl.NumberFormat('en-IN', { maximumFractionDigits: 6 }).format(number(value)); }
function date(value: string) { return new Intl.DateTimeFormat('en-IN', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`)); }
function label(value: string) { return value.toLowerCase().replaceAll('_', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()); }
function toggle(values: string[], value: string) { return values.includes(value) ? values.filter((item) => item !== value) : [...values, value]; }
function replace<T>(values: T[], index: number, value: T) { return values.map((item, position) => position === index ? value : item); }
function offsetDate(days: number) { const value = new Date(); value.setUTCDate(value.getUTCDate() + days); return value.toISOString().slice(0, 10); }

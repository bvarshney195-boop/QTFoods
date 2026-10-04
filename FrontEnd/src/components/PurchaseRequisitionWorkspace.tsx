import {
  useCallback,
  useEffect,
  useMemo,
  useRef,
  useState,
  type Dispatch,
  type FormEvent,
  type SetStateAction,
} from 'react';
import { isApiError } from '../api/client';
import {
  cancelPurchaseRequisition,
  createPurchaseRequisition,
  decidePurchaseRequisition,
  getPurchaseRequisition,
  listPurchaseRequisitions,
  submitPurchaseRequisition,
  updatePurchaseRequisition,
  type PurchaseRequisition,
  type PurchaseRequisitionLineWrite,
  type PurchaseRequisitionWorkspace as Workspace,
} from '../api/purchaseRequisitions';
import { useErpSession } from '../app/ErpSessionContext';
import { PageHeader } from './PageHeader';
import { StatusBadge } from './StatusBadge';

type LineForm = {
  item_id: string;
  quantity: string;
  estimated_unit_cost: string;
  notes: string;
};

type RequisitionForm = {
  requisition_number: string;
  department: string;
  purpose: string;
  requested_date: string;
  required_by_date: string;
  currency: string;
  lines: LineForm[];
};

export function PurchaseRequisitionWorkspace() {
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const [workspace, setWorkspace] = useState<Workspace | null>(null);
  const [selected, setSelected] = useState<PurchaseRequisition | null>(null);
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<RequisitionForm>(blankForm);
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
  const [cancellationReason, setCancellationReason] = useState('');
  const [approvalReason, setApprovalReason] = useState('');
  const selectedId = useRef<string | null>(null);
  const saveKey = useRef<string | null>(null);
  const submitKey = useRef<string | null>(null);
  const cancelKey = useRef<string | null>(null);
  const decisionKey = useRef<string | null>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      setWorkspace(await listPurchaseRequisitions({
        page,
        q: search || undefined,
        status: statusFilter || undefined,
        sort,
      }));
    } catch (caught) {
      setError(message(caught, 'Unable to load purchase requisitions.'));
    } finally {
      setLoading(false);
    }
  }, [contextKey, page, search, sort, statusFilter]);

  useEffect(() => { void refresh(); }, [refresh]);
  useEffect(() => {
    selectedId.current = null;
    setSelected(null);
    setEditing(false);
    setForm(blankForm());
    setSearchDraft('');
    setSearch('');
    setStatusFilter('');
    setSort('NEWEST');
    setPage(1);
    clearFeedback();
  }, [contextKey]);
  useEffect(() => { saveKey.current = null; }, [form]);
  useEffect(() => { cancelKey.current = null; }, [cancellationReason]);
  useEffect(() => { decisionKey.current = null; }, [approvalReason]);
  useEffect(() => {
    submitKey.current = null;
    cancelKey.current = null;
    decisionKey.current = null;
  }, [selected?.id, selected?.record_version, selected?.approval?.record_version]);

  const formTotal = useMemo(() => form.lines.reduce((total, line) => {
    const quantity = Number(line.quantity);
    const cost = Number(line.estimated_unit_cost);
    return total + (Number.isFinite(quantity) && Number.isFinite(cost) ? quantity * cost : 0);
  }, 0), [form.lines]);

  async function choose(id: string) {
    selectedId.current = id;
    setDetailLoading(true);
    setEditing(false);
    clearFeedback();
    try {
      const detail = await getPurchaseRequisition(id);
      if (selectedId.current !== id) return;
      setSelected(detail);
      setForm(toForm(detail));
      setCancellationReason('');
      setApprovalReason(detail.approval?.decision?.reason ?? '');
    } catch (caught) {
      setError(message(caught, 'Unable to load this purchase requisition.'));
    } finally {
      setDetailLoading(false);
    }
  }

  function startNew() {
    selectedId.current = null;
    setSelected(null);
    setEditing(true);
    setForm(blankForm());
    setCancellationReason('');
    setApprovalReason('');
    clearFeedback();
  }

  function startEdit() {
    if (!selected) return;
    setForm(toForm(selected));
    setEditing(true);
    clearFeedback();
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    clearFeedback();
    const localErrors = validate(form, selected === null);
    if (Object.keys(localErrors).length) {
      setFieldErrors(localErrors);
      setError('Correct the highlighted requisition fields.');
      return;
    }

    const body = {
      department: form.department.trim(),
      purpose: form.purpose.trim(),
      requested_date: form.requested_date,
      required_by_date: form.required_by_date,
      currency: form.currency,
      lines: form.lines.map((line): PurchaseRequisitionLineWrite => ({
        item_id: line.item_id,
        quantity: line.quantity,
        estimated_unit_cost: line.estimated_unit_cost,
        notes: nullable(line.notes),
      })),
    };

    setBusy(true);
    try {
      const result = selected
        ? await updatePurchaseRequisition(selected, body, commandKey(saveKey))
        : await createPurchaseRequisition({
          ...body,
          requisition_number: form.requisition_number.trim().toUpperCase(),
        }, commandKey(saveKey));
      saveKey.current = null;
      await refresh();
      const detail = await getPurchaseRequisition(result.id);
      selectedId.current = result.id;
      setSelected(detail);
      setForm(toForm(detail));
      setEditing(false);
      setSuccess(selected ? 'Requisition changes saved.' : 'Draft requisition created.');
    } catch (caught) {
      setError(message(caught, 'Unable to save the purchase requisition.'));
      setFieldErrors(fields(caught));
    } finally {
      setBusy(false);
    }
  }

  async function submit() {
    if (!selected) return;
    clearFeedback();
    setBusy(true);
    try {
      const result = await submitPurchaseRequisition(selected, commandKey(submitKey));
      submitKey.current = null;
      await reload(result.id);
      await refresh();
      setSuccess('Requisition submitted to the governed approval queue.');
    } catch (caught) {
      setError(message(caught, 'Unable to submit the requisition.'));
      setFieldErrors(fields(caught));
    } finally {
      setBusy(false);
    }
  }

  async function cancel() {
    if (!selected) return;
    clearFeedback();
    if (cancellationReason.trim().length < 3) {
      setFieldErrors({ cancellation_reason: 'Enter a cancellation reason of at least 3 characters.' });
      return;
    }
    setBusy(true);
    try {
      const result = await cancelPurchaseRequisition(
        selected,
        cancellationReason.trim(),
        commandKey(cancelKey),
      );
      cancelKey.current = null;
      await reload(result.id);
      await refresh();
      setSuccess('Purchase requisition cancelled with reason evidence.');
    } catch (caught) {
      setError(message(caught, 'Unable to cancel the requisition.'));
      setFieldErrors(renameReasonField(fields(caught), 'cancellation_reason'));
    } finally {
      setBusy(false);
    }
  }

  async function decide(decision: 'approve' | 'reject') {
    if (!selected?.approval) return;
    clearFeedback();
    if (decision === 'reject' && approvalReason.trim().length < 3) {
      setFieldErrors({ approval_reason: 'Explain why the requisition is being rejected.' });
      return;
    }
    setBusy(true);
    try {
      const result = await decidePurchaseRequisition(
        selected.approval,
        decision,
        nullable(approvalReason),
        commandKey(decisionKey),
      );
      decisionKey.current = null;
      await reload(result.id);
      await refresh();
      setSuccess(decision === 'approve'
        ? 'Purchase requisition approved.'
        : 'Purchase requisition rejected for correction.');
    } catch (caught) {
      setError(message(caught, `Unable to ${decision} the requisition.`));
      setFieldErrors(renameReasonField(fields(caught), 'approval_reason'));
    } finally {
      setBusy(false);
    }
  }

  async function reload(id: string) {
    const detail = await getPurchaseRequisition(id);
    selectedId.current = id;
    setSelected(detail);
    setForm(toForm(detail));
    setEditing(false);
  }

  function updateLine(index: number, patch: Partial<LineForm>) {
    setForm((current) => ({
      ...current,
      lines: current.lines.map((line, lineIndex) => lineIndex === index ? { ...line, ...patch } : line),
    }));
  }

  function addLine() {
    setForm((current) => ({ ...current, lines: [...current.lines, blankLine()] }));
  }

  function removeLine(index: number) {
    setForm((current) => ({
      ...current,
      lines: current.lines.length === 1
        ? current.lines
        : current.lines.filter((_, lineIndex) => lineIndex !== index),
    }));
  }

  function clearFeedback() {
    setError(null);
    setSuccess(null);
    setFieldErrors({});
  }

  function applySearch(event: FormEvent) {
    event.preventDefault();
    setPage(1);
    setSearch(searchDraft.trim());
  }

  return (
    <>
      <PageHeader
        code="PUR-REQ"
        batch="B05-B07"
        title="Purchase Requisitions"
        description="Versioned material requests with scoped item costing, policy-routed approval, maker-checker decisions, and immutable control evidence."
        onNew={workspace?.allowed_actions.includes('CREATE') ? startNew : undefined}
      />

      <div className="live-notice procurement-notice">
        <span />
        <b>Governed requisition workflow</b>
        Drafts remain editable until submission. Approval authority is resolved from the plant policy and the maker cannot decide their own request.
      </div>

      <div className="kpi-grid procurement-kpis">
        <Kpi label="Requisitions" value={workspace?.summary.total ?? 0} detail="Current plant" />
        <Kpi label="Awaiting approval" value={workspace?.summary.submitted ?? 0} detail="Policy-routed" />
        <Kpi label="Approved" value={workspace?.summary.approved ?? 0} detail="Ready for sourcing" />
        <Kpi
          label="Open estimate"
          value={money(workspace?.summary.estimated_total ?? '0', 'INR')}
          detail={`${workspace?.summary.draft ?? 0} draft · ${workspace?.summary.rejected ?? 0} rejected`}
          compact
        />
      </div>

      <section className="panel requisition-approval-inbox">
        <div className="panel-head">
          <div>
            <h3>Requisition approval inbox</h3>
            <span>Only requests matching your direct or delegated authority are shown.</span>
          </div>
          <b>{workspace?.approvals.length ?? 0} actionable</b>
        </div>
        {workspace?.approvals.length ? (
          <div className="requisition-approval-list">
            {workspace.approvals.map((requisition) => (
              <button key={requisition.id} type="button" onClick={() => void choose(requisition.id)}>
                <span>
                  <b>{requisition.requisition_number}</b>
                  <StatusBadge status={requisition.approval?.band_name ?? 'PENDING'} />
                </span>
                <strong>{requisition.department}</strong>
                <small>{money(requisition.estimated_total, requisition.currency)} · due {dateTime(requisition.approval?.due_at)}</small>
              </button>
            ))}
          </div>
        ) : (
          <div className="empty-state compact">No requisitions currently require your approval in this plant.</div>
        )}
      </section>

      <div className="module-grid requisition-workspace">
        <section className="panel">
          <form className="requisition-toolbar" onSubmit={applySearch}>
            <label>
              Search
              <span>
                <input
                  aria-label="Search requisitions"
                  value={searchDraft}
                  onChange={(event) => setSearchDraft(event.target.value)}
                  placeholder="Number, purpose, department, requester"
                />
                <button className="secondary" type="submit">Search</button>
              </span>
            </label>
            <label>
              Status
              <select
                aria-label="Requisition status filter"
                value={statusFilter}
                onChange={(event) => { setStatusFilter(event.target.value); setPage(1); }}
              >
                <option value="">All statuses</option>
                {workspace?.lookups.statuses.map((status) => <option key={status}>{status}</option>)}
              </select>
            </label>
            <label>
              Sort
              <select aria-label="Requisition sort" value={sort} onChange={(event) => setSort(event.target.value)}>
                {workspace?.lookups.sorts.map((option) => (
                  <option key={option} value={option}>{label(option)}</option>
                ))}
              </select>
            </label>
          </form>

          {loading && !workspace ? <div className="empty-state">Loading purchase requisitions…</div> : null}
          {!loading && error && !workspace ? <ErrorMessage text={error} onRetry={() => void refresh()} /> : null}
          {workspace ? (
            <>
              <div className="table-wrap">
                <table className="requisition-table">
                  <thead>
                    <tr>
                      <th>Requisition</th>
                      <th>Purpose</th>
                      <th>Status</th>
                      <th>Requester</th>
                      <th>Required</th>
                      <th>Estimate</th>
                      <th />
                    </tr>
                  </thead>
                  <tbody>
                    {workspace.data.map((requisition) => (
                      <tr key={requisition.id} className={selected?.id === requisition.id ? 'selected-row' : ''}>
                        <td><b className="link">{requisition.requisition_number}</b><small>v{requisition.record_version} · {requisition.line_count} lines</small></td>
                        <td><b>{requisition.department}</b><small>{requisition.purpose}</small></td>
                        <td><StatusBadge status={requisition.status} /></td>
                        <td>{requisition.requested_by.name}</td>
                        <td>{date(requisition.required_by_date)}</td>
                        <td>{money(requisition.estimated_total, requisition.currency)}</td>
                        <td><button className="secondary compact-button" type="button" onClick={() => void choose(requisition.id)}>Open</button></td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
              {!workspace.data.length ? <div className="empty-state">No requisitions match the current filters.</div> : null}
              <div className="pagination">
                <button type="button" disabled={page <= 1} onClick={() => setPage((value) => value - 1)}>Previous</button>
                <span>Page {workspace.meta.current_page} of {workspace.meta.last_page}</span>
                <button type="button" disabled={page >= workspace.meta.last_page} onClick={() => setPage((value) => value + 1)}>Next</button>
              </div>
            </>
          ) : null}
        </section>

        <aside className="panel requisition-editor">
          <div className="panel-head">
            <div>
              <h3>{selected ? selected.requisition_number : editing ? 'New requisition' : 'Requisition detail'}</h3>
              <span>{selected ? `Version ${selected.record_version} · ${selected.department}` : 'Select a row or create a controlled request.'}</span>
            </div>
            {selected && !editing && selected.allowed_actions.includes('UPDATE') ? (
              <button className="secondary compact-button" type="button" onClick={startEdit}>Edit requisition</button>
            ) : null}
          </div>
          <div className="requisition-detail-body">
            {detailLoading ? <div className="empty-state">Loading requisition detail…</div> : null}
            {error ? <ErrorMessage text={error} onRetry={selected ? () => void choose(selected.id) : undefined} /> : null}
            {success ? <div className="form-success" role="status"><span />{success}</div> : null}
            {!detailLoading && editing ? (
              <RequisitionEditor
                form={form}
                setForm={setForm}
                items={workspace?.lookups.items ?? []}
                currencies={workspace?.lookups.currencies ?? ['INR']}
                errors={fieldErrors}
                busy={busy}
                existing={selected !== null}
                total={formTotal}
                onSave={save}
                onCancel={() => { setEditing(false); if (selected) setForm(toForm(selected)); }}
                onAddLine={addLine}
                onRemoveLine={removeLine}
                onUpdateLine={updateLine}
              />
            ) : null}
            {!detailLoading && !editing && selected ? (
              <RequisitionDetail
                requisition={selected}
                cancellationReason={cancellationReason}
                setCancellationReason={setCancellationReason}
                approvalReason={approvalReason}
                setApprovalReason={setApprovalReason}
                errors={fieldErrors}
                busy={busy}
                onSubmit={() => void submit()}
                onCancel={() => void cancel()}
                onDecide={(decision) => void decide(decision)}
              />
            ) : null}
            {!detailLoading && !editing && !selected ? (
              <div className="empty-state">Choose a requisition to inspect its lines and approval evidence.</div>
            ) : null}
          </div>
        </aside>
      </div>
    </>
  );
}

function RequisitionEditor({
  form,
  setForm,
  items,
  currencies,
  errors,
  busy,
  existing,
  total,
  onSave,
  onCancel,
  onAddLine,
  onRemoveLine,
  onUpdateLine,
}: {
  form: RequisitionForm;
  setForm: Dispatch<SetStateAction<RequisitionForm>>;
  items: Workspace['lookups']['items'];
  currencies: string[];
  errors: Record<string, string>;
  busy: boolean;
  existing: boolean;
  total: number;
  onSave: (event: FormEvent) => void;
  onCancel: () => void;
  onAddLine: () => void;
  onRemoveLine: (index: number) => void;
  onUpdateLine: (index: number, patch: Partial<LineForm>) => void;
}) {
  return (
    <form className="requisition-form" onSubmit={onSave}>
      <fieldset disabled={busy}>
        <div className="requisition-field-grid">
          <label>
            Requisition number
            <input
              aria-label="Requisition number"
              value={form.requisition_number}
              maxLength={80}
              readOnly={existing}
              className={existing ? 'read-only' : ''}
              aria-invalid={Boolean(errors.requisition_number)}
              onChange={(event) => setForm((current) => ({ ...current, requisition_number: event.target.value.toUpperCase() }))}
            />
            <FieldError text={errors.requisition_number} />
          </label>
          <label>
            Department
            <input
              aria-label="Department"
              value={form.department}
              maxLength={120}
              aria-invalid={Boolean(errors.department)}
              onChange={(event) => setForm((current) => ({ ...current, department: event.target.value }))}
            />
            <FieldError text={errors.department} />
          </label>
          <label className="wide">
            Purpose
            <textarea
              aria-label="Purpose"
              rows={3}
              maxLength={4000}
              value={form.purpose}
              aria-invalid={Boolean(errors.purpose)}
              onChange={(event) => setForm((current) => ({ ...current, purpose: event.target.value }))}
            />
            <FieldError text={errors.purpose} />
          </label>
          <label>
            Requested date
            <input
              aria-label="Requested date"
              type="date"
              value={form.requested_date}
              aria-invalid={Boolean(errors.requested_date)}
              onChange={(event) => setForm((current) => ({ ...current, requested_date: event.target.value }))}
            />
            <FieldError text={errors.requested_date} />
          </label>
          <label>
            Required by date
            <input
              aria-label="Required by date"
              type="date"
              value={form.required_by_date}
              min={form.requested_date}
              aria-invalid={Boolean(errors.required_by_date)}
              onChange={(event) => setForm((current) => ({ ...current, required_by_date: event.target.value }))}
            />
            <FieldError text={errors.required_by_date} />
          </label>
          <label>
            Currency
            <select
              aria-label="Currency"
              value={form.currency}
              onChange={(event) => setForm((current) => ({ ...current, currency: event.target.value }))}
            >
              {currencies.map((currency) => <option key={currency}>{currency}</option>)}
            </select>
          </label>
          <div className="requisition-form-total">
            <span>Estimated total</span>
            <b>{money(String(total), form.currency)}</b>
          </div>
        </div>

        <div className="requisition-lines-head">
          <div><h4>Requested items</h4><small>Active company items use their governed base UOM.</small></div>
          <button className="secondary compact-button" type="button" onClick={onAddLine}>Add line</button>
        </div>
        {form.lines.map((line, index) => {
          const item = items.find((candidate) => candidate.id === line.item_id);
          const lineTotal = Number(line.quantity || 0) * Number(line.estimated_unit_cost || 0);
          return (
            <div className="requisition-line-card" key={`${index}-${line.item_id}`}>
              <div className="operation-line-title">
                <b>Line {index + 1}</b>
                <button type="button" disabled={form.lines.length === 1} onClick={() => onRemoveLine(index)}>Remove</button>
              </div>
              <div className="requisition-line-grid">
                <label className="wide">
                  Item
                  <select
                    aria-label={`Line ${index + 1} item`}
                    value={line.item_id}
                    aria-invalid={Boolean(errors[`lines.${index}.item_id`])}
                    onChange={(event) => onUpdateLine(index, { item_id: event.target.value })}
                  >
                    <option value="">Select active item</option>
                    {items.map((option) => (
                      <option key={option.id} value={option.id}>{option.code} · {option.name} · {option.uom_code}</option>
                    ))}
                  </select>
                  <FieldError text={errors[`lines.${index}.item_id`]} />
                </label>
                <label>
                  Quantity {item ? `(${item.uom_code})` : ''}
                  <input
                    aria-label={`Line ${index + 1} quantity`}
                    inputMode="decimal"
                    value={line.quantity}
                    aria-invalid={Boolean(errors[`lines.${index}.quantity`])}
                    onChange={(event) => onUpdateLine(index, { quantity: event.target.value })}
                  />
                  <FieldError text={errors[`lines.${index}.quantity`]} />
                </label>
                <label>
                  Estimated unit cost
                  <input
                    aria-label={`Line ${index + 1} estimated unit cost`}
                    inputMode="decimal"
                    value={line.estimated_unit_cost}
                    aria-invalid={Boolean(errors[`lines.${index}.estimated_unit_cost`])}
                    onChange={(event) => onUpdateLine(index, { estimated_unit_cost: event.target.value })}
                  />
                  <FieldError text={errors[`lines.${index}.estimated_unit_cost`]} />
                </label>
                <label className="wide">
                  Line notes
                  <textarea
                    aria-label={`Line ${index + 1} notes`}
                    rows={2}
                    maxLength={1000}
                    value={line.notes}
                    onChange={(event) => onUpdateLine(index, { notes: event.target.value })}
                  />
                </label>
              </div>
              <div className="line-snapshot">
                <span>UOM <b>{item?.uom_code ?? '—'}</b></span>
                <span>Line estimate <b>{money(Number.isFinite(lineTotal) ? String(lineTotal) : '0', form.currency)}</b></span>
              </div>
            </div>
          );
        })}
        <FieldError text={errors.lines} />
      </fieldset>
      <div className="form-actions requisition-save-actions">
        {existing ? <button className="secondary" type="button" disabled={busy} onClick={onCancel}>Close without saving</button> : null}
        <button className="primary" type="submit" disabled={busy}>{existing ? 'Save changes' : 'Create draft'}</button>
      </div>
    </form>
  );
}

function RequisitionDetail({
  requisition,
  cancellationReason,
  setCancellationReason,
  approvalReason,
  setApprovalReason,
  errors,
  busy,
  onSubmit,
  onCancel,
  onDecide,
}: {
  requisition: PurchaseRequisition;
  cancellationReason: string;
  setCancellationReason: (value: string) => void;
  approvalReason: string;
  setApprovalReason: (value: string) => void;
  errors: Record<string, string>;
  busy: boolean;
  onSubmit: () => void;
  onCancel: () => void;
  onDecide: (decision: 'approve' | 'reject') => void;
}) {
  const approvalActions = requisition.approval?.allowed_actions ?? [];
  const [confirmReject, setConfirmReject] = useState(false);
  return (
    <div className="requisition-detail">
      <div className="detail-status"><StatusBadge status={requisition.status} /><b>{money(requisition.estimated_total, requisition.currency)}</b><span>v{requisition.record_version}</span></div>
      <dl className="control-definition">
        <Fact label="Requester" value={requisition.requested_by.name} />
        <Fact label="Department" value={requisition.department} />
        <Fact label="Requested" value={date(requisition.requested_date)} />
        <Fact label="Required by" value={date(requisition.required_by_date)} />
        <Fact label="Purpose" value={requisition.purpose} wide />
      </dl>

      <section className="requisition-line-detail">
        <h4>Requested items</h4>
        <div className="table-wrap">
          <table>
            <thead><tr><th>Line</th><th>Item</th><th>Quantity</th><th>Unit cost</th><th>Estimate</th></tr></thead>
            <tbody>
              {requisition.lines?.map((line) => (
                <tr key={line.id}>
                  <td>{line.line_number}</td>
                  <td><b>{line.item.code}</b><small>{line.description}{line.notes ? ` · ${line.notes}` : ''}</small></td>
                  <td>{quantity(line.quantity)} {line.uom_code}</td>
                  <td>{money(line.estimated_unit_cost, requisition.currency)}</td>
                  <td>{money(line.estimated_line_total, requisition.currency)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </section>

      {requisition.rejection_reason ? <div className="form-error"><span>Rejected: {requisition.rejection_reason}</span></div> : null}
      {requisition.cancellation_reason ? <div className="form-error"><span>Cancelled: {requisition.cancellation_reason}</span></div> : null}

      {requisition.approval ? (
        <section className="requisition-approval-evidence">
          <h4>Approval evidence</h4>
          <dl className="control-definition">
            <Fact label="Policy" value={requisition.approval.rule_name} />
            <Fact label="Authority band" value={requisition.approval.band_name} />
            <Fact label="Submission" value={`#${requisition.approval.submission_number}`} />
            <Fact label="Due" value={dateTime(requisition.approval.due_at)} />
            {requisition.approval.decision ? <>
              <Fact label="Reviewer" value={requisition.approval.decision.reviewer.name} />
              <Fact label="Authority" value={label(requisition.approval.decision.authority_source)} />
              <Fact label="Decision" value={requisition.approval.decision.decision} />
              <Fact label="Reason" value={requisition.approval.decision.reason ?? 'No reason supplied'} />
            </> : null}
          </dl>
        </section>
      ) : null}

      {requisition.allowed_actions.includes('SUBMIT') ? (
        <div className="inventory-command">
          <h4>Submit for approval</h4>
          <p>The current version and estimated total will be frozen into the approval request.</p>
          <button className="primary" type="button" disabled={busy} onClick={onSubmit}>Submit requisition</button>
        </div>
      ) : null}

      {approvalActions.length ? (
        <div className="inventory-command requisition-decision">
          <h4>Independent approval decision</h4>
          <p>{requisition.approval?.required_permission} · maker-checker enforced</p>
          <label>
            Approval reason
            <textarea
              aria-label="Approval reason"
              rows={3}
              maxLength={2000}
              value={approvalReason}
              aria-invalid={Boolean(errors.approval_reason)}
              onChange={(event) => setApprovalReason(event.target.value)}
            />
            <FieldError text={errors.approval_reason} />
          </label>
          <div className="form-actions">
            <button className="secondary" type="button" disabled={busy} onClick={() => {
              if (approvalReason.trim().length < 3) { onDecide('reject'); return; }
              setConfirmReject(true);
            }}>Reject for correction</button>
            <button className="primary" type="button" disabled={busy} onClick={() => onDecide('approve')}>Approve requisition</button>
          </div>
        </div>
      ) : null}

      {confirmReject ? <div className="modal-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) setConfirmReject(false); }}>
        <section className="confirmation-modal" role="dialog" aria-modal="true" aria-labelledby="reject-requisition-title">
          <h3 id="reject-requisition-title">Reject requisition {requisition.requisition_number}?</h3>
          <p>This decision sends the request back for correction and is recorded in the audit trail.</p>
          <dl className="control-definition">
            <Fact label="Requester" value={requisition.requested_by.name} />
            <Fact label="Amount" value={money(requisition.estimated_total, requisition.currency)} />
            <Fact label="Reason" value={approvalReason.trim()} wide />
          </dl>
          <div className="form-actions">
            <button className="secondary" type="button" autoFocus onClick={() => setConfirmReject(false)}>Keep reviewing</button>
            <button className="destructive-button" type="button" disabled={busy} onClick={() => { setConfirmReject(false); onDecide('reject'); }}>Reject requisition</button>
          </div>
        </section>
      </div> : null}

      {requisition.allowed_actions.includes('CANCEL') ? (
        <div className="inventory-command destructive-command">
          <h4>Cancel requisition</h4>
          <p>Cancellation is terminal and records the actor, reason, version, audit event, and outbox event.</p>
          <label>
            Cancellation reason
            <textarea
              aria-label="Cancellation reason"
              rows={3}
              maxLength={2000}
              value={cancellationReason}
              aria-invalid={Boolean(errors.cancellation_reason)}
              onChange={(event) => setCancellationReason(event.target.value)}
            />
            <FieldError text={errors.cancellation_reason} />
          </label>
          <button className="secondary" type="button" disabled={busy} onClick={onCancel}>Cancel requisition</button>
        </div>
      ) : null}
    </div>
  );
}

function Kpi({ label: text, value, detail, compact = false }: { label: string; value: string | number; detail: string; compact?: boolean }) {
  return <div className="kpi"><span>{text}</span><b className={compact ? 'compact' : undefined}>{value}</b><small>{detail}</small></div>;
}

function Fact({ label: text, value, wide = false }: { label: string; value: string; wide?: boolean }) {
  return <div className={wide ? 'wide' : undefined}><dt>{text}</dt><dd>{value}</dd></div>;
}

function FieldError({ text }: { text?: string }) {
  return text ? <small className="field-error">{text}</small> : null;
}

function ErrorMessage({ text, onRetry }: { text: string; onRetry?: () => void }) {
  return <div className="form-error" role="alert"><span>{text}</span>{onRetry ? <button type="button" onClick={onRetry}>Retry</button> : null}</div>;
}

function blankLine(): LineForm {
  return { item_id: '', quantity: '', estimated_unit_cost: '', notes: '' };
}

function blankForm(): RequisitionForm {
  const requested = inputDate(new Date());
  const required = new Date();
  required.setDate(required.getDate() + 14);
  return {
    requisition_number: '',
    department: '',
    purpose: '',
    requested_date: requested,
    required_by_date: inputDate(required),
    currency: 'INR',
    lines: [blankLine()],
  };
}

function toForm(requisition: PurchaseRequisition): RequisitionForm {
  return {
    requisition_number: requisition.requisition_number,
    department: requisition.department,
    purpose: requisition.purpose,
    requested_date: requisition.requested_date,
    required_by_date: requisition.required_by_date,
    currency: requisition.currency,
    lines: (requisition.lines ?? []).map((line) => ({
      item_id: line.item.id,
      quantity: trimDecimal(line.quantity),
      estimated_unit_cost: trimDecimal(line.estimated_unit_cost),
      notes: line.notes ?? '',
    })),
  };
}

function validate(form: RequisitionForm, creating: boolean): Record<string, string> {
  const errors: Record<string, string> = {};
  if (creating && !/^[A-Z0-9][A-Z0-9_/-]*$/.test(form.requisition_number.trim().toUpperCase())) {
    errors.requisition_number = 'Use an uppercase requisition number with letters, numbers, slash, dash, or underscore.';
  }
  if (form.department.trim().length < 2) errors.department = 'Enter the requesting department.';
  if (form.purpose.trim().length < 3) errors.purpose = 'Explain the business purpose.';
  if (!form.requested_date) errors.requested_date = 'Choose the requested date.';
  if (!form.required_by_date) errors.required_by_date = 'Choose the required-by date.';
  if (form.requested_date && form.required_by_date && form.required_by_date < form.requested_date) {
    errors.required_by_date = 'Required-by date cannot be before the requested date.';
  }
  if (!form.lines.length) errors.lines = 'Add at least one requested item.';
  const seen = new Set<string>();
  form.lines.forEach((line, index) => {
    if (!line.item_id) errors[`lines.${index}.item_id`] = 'Select an item.';
    else if (seen.has(line.item_id)) errors[`lines.${index}.item_id`] = 'Each item may appear only once.';
    else seen.add(line.item_id);
    if (!positiveDecimal(line.quantity)) errors[`lines.${index}.quantity`] = 'Enter a quantity greater than zero.';
    if (!nonNegativeDecimal(line.estimated_unit_cost)) {
      errors[`lines.${index}.estimated_unit_cost`] = 'Enter a non-negative estimated unit cost.';
    }
  });
  return errors;
}

function positiveDecimal(value: string): boolean {
  return /^\d{1,14}(?:\.\d{1,6})?$/.test(value) && Number(value) > 0;
}

function nonNegativeDecimal(value: string): boolean {
  return /^\d{1,14}(?:\.\d{1,6})?$/.test(value) && Number(value) >= 0;
}

function fields(error: unknown): Record<string, string> {
  if (!isApiError(error) || !error.fields) return {};
  return Object.fromEntries(Object.entries(error.fields).map(([field, values]) => [field, values[0] ?? 'Invalid value.']));
}

function renameReasonField(values: Record<string, string>, target: string): Record<string, string> {
  if (!values.reason) return values;
  const { reason, ...rest } = values;
  return { ...rest, [target]: reason };
}

function message(error: unknown, fallback: string): string {
  return isApiError(error) ? error.message : fallback;
}

function commandKey(ref: { current: string | null }): string {
  ref.current ??= crypto.randomUUID();
  return ref.current;
}

function nullable(value: string): string | null {
  const trimmed = value.trim();
  return trimmed || null;
}

function label(value: string): string {
  return value.replaceAll('_', ' ').toLowerCase().replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function trimDecimal(value: string): string {
  return value.replace(/\.0+$/, '').replace(/(\.\d*?)0+$/, '$1');
}

function quantity(value: string): string {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? new Intl.NumberFormat(undefined, { maximumFractionDigits: 6 }).format(parsed) : value;
}

function money(value: string, currency: string): string {
  const parsed = Number(value);
  if (!Number.isFinite(parsed)) return `${currency} ${value}`;
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency, maximumFractionDigits: 2 }).format(parsed);
  } catch {
    return `${currency} ${quantity(value)}`;
  }
}

function date(value: string): string {
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium' }).format(new Date(`${value}T00:00:00`));
}

function dateTime(value?: string | null): string {
  return value ? new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—';
}

function inputDate(value: Date): string {
  const year = value.getFullYear();
  const month = String(value.getMonth() + 1).padStart(2, '0');
  const day = String(value.getDate()).padStart(2, '0');
  return `${year}-${month}-${day}`;
}

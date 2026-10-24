import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { isApiError } from '../api/client';
import {
  cancelInventoryOperation,
  createInventoryOperation,
  getInventoryOperation,
  listInventoryOperations,
  postInventoryOperation,
  updateInventoryOperation,
  type AdjustmentDirection,
  type InventoryOperation,
  type InventoryOperationLineWrite,
  type InventoryOperationResource,
  type InventoryOperationType,
  type InventoryOperationWorkspace,
  type OperationPosition,
} from '../api/inventoryOperations';
import { useErpSession } from '../app/ErpSessionContext';
import { PageHeader } from './PageHeader';
import { StatusBadge } from './StatusBadge';

type LineForm = {
  source_position_id: string;
  target_position_id: string;
  quantity_base: string;
  counted_quantity_base: string;
  adjustment_direction: AdjustmentDirection;
  notes: string;
};

type OperationForm = {
  operation_number: string;
  operation_type: InventoryOperationType;
  reason_code: string;
  notes: string;
  lines: LineForm[];
};

const configs: Record<InventoryOperationResource, {
  code: string;
  title: string;
  description: string;
  notice: string;
}> = {
  issues: {
    code: 'INV-ISS',
    title: 'Issue / Return',
    description: 'Controlled material issues and quarantine returns with immutable stock-ledger evidence.',
    notice: 'Issues consume only available, unreserved stock. Returns enter RETURN_QUARANTINE so they cannot bypass quality disposition.',
  },
  transfers: {
    code: 'INV-TRF',
    title: 'Stock Transfers',
    description: 'Plant-scoped stock transfers that preserve SKU, lot, owner, quality status, and UOM.',
    notice: 'A transfer changes location only. Ownership, lot identity, quality status, and UOM remain fixed across both positions.',
  },
  counts: {
    code: 'INV-COUNT',
    title: 'Stock Counts & Adjustments',
    description: 'Snapshot-safe physical counts and reasoned inventory adjustments with variance postings.',
    notice: 'Count drafts capture the current stock version and reject stale posting. Direct adjustments require an explicit direction and reason.',
  },
  'expiry-disposals': {
    code: 'INV-EXP',
    title: 'Expiry / Disposal',
    description: 'Segregate expired lots and dispose only controlled blocked stock with full movement traceability.',
    notice: 'Expiry moves the entire unreserved position into EXPIRED quality. Disposal is restricted to blocked, inactive-lot, or expired stock.',
  },
};

export function InventoryOperationsWorkspace({ resource }: { resource: InventoryOperationResource }) {
  const config = configs[resource];
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const [workspace, setWorkspace] = useState<InventoryOperationWorkspace | null>(null);
  const [selected, setSelected] = useState<InventoryOperation | null>(null);
  const [editing, setEditing] = useState(false);
  const [form, setForm] = useState<OperationForm>(() => blankForm(resource));
  const [searchDraft, setSearchDraft] = useState('');
  const [search, setSearch] = useState('');
  const [statusFilter, setStatusFilter] = useState('');
  const [typeFilter, setTypeFilter] = useState('');
  const [sort, setSort] = useState('NEWEST');
  const [page, setPage] = useState(1);
  const [loading, setLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const [cancelReason, setCancelReason] = useState('');
  const selectedId = useRef<string | null>(null);
  const saveKey = useRef<string | null>(null);
  const postKey = useRef<string | null>(null);
  const cancelKey = useRef<string | null>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    setError(null);
    try {
      setWorkspace(await listInventoryOperations(resource, {
        page,
        q: search || undefined,
        status: statusFilter || undefined,
        operation_type: typeFilter || undefined,
        sort,
      }));
    } catch (caught) {
      setError(message(caught, `Unable to load ${config.title.toLowerCase()}.`));
    } finally {
      setLoading(false);
    }
  }, [config.title, contextKey, page, resource, search, sort, statusFilter, typeFilter]);

  useEffect(() => { void refresh(); }, [refresh]);
  useEffect(() => {
    selectedId.current = null;
    setSelected(null);
    setEditing(false);
    setForm(blankForm(resource));
    setSearchDraft('');
    setSearch('');
    setStatusFilter('');
    setTypeFilter('');
    setSort('NEWEST');
    setPage(1);
    clearFeedback();
  }, [contextKey, resource]);

  const positions = workspace?.lookups.positions ?? [];

  async function choose(id: string) {
    selectedId.current = id;
    setDetailLoading(true);
    clearFeedback();
    try {
      const detail = await getInventoryOperation(resource, id);
      if (selectedId.current !== id) return;
      setSelected(detail);
      setForm(toForm(detail));
      setEditing(detail.status === 'DRAFT' && detail.allowed_actions.includes('UPDATE'));
    } catch (caught) {
      setError(message(caught, 'Unable to load this inventory operation.'));
    } finally {
      setDetailLoading(false);
    }
  }

  function startNew() {
    selectedId.current = null;
    setSelected(null);
    setEditing(true);
    setForm(blankForm(resource, workspace?.lookups.operation_types[0]));
    setCancelReason('');
    clearFeedback();
  }

  async function save(event: FormEvent) {
    event.preventDefault();
    clearFeedback();
    const localErrors = validate(form);
    if (Object.keys(localErrors).length) {
      setFieldErrors(localErrors);
      setError('Complete the required operation controls.');
      return;
    }
    setBusy(true);
    try {
      const body = writePayload(form);
      const key = commandKey(saveKey);
      const result = selected
        ? await updateInventoryOperation(resource, selected, body, key)
        : await createInventoryOperation(resource, {
            ...body,
            operation_number: form.operation_number.trim().toUpperCase(),
            operation_type: form.operation_type,
          }, key);
      saveKey.current = null;
      selectedId.current = result.id;
      const detail = await getInventoryOperation(resource, result.id);
      setSelected(detail);
      setForm(toForm(detail));
      setEditing(detail.allowed_actions.includes('UPDATE'));
      setSuccess(selected ? 'Draft operation updated.' : 'Draft operation created.');
      await refresh();
    } catch (caught) {
      setError(message(caught, 'Unable to save this inventory operation.'));
      setFieldErrors(fields(caught));
    } finally {
      setBusy(false);
    }
  }

  async function post() {
    if (!selected) return;
    clearFeedback();
    setBusy(true);
    try {
      const result = await postInventoryOperation(resource, selected, commandKey(postKey));
      postKey.current = null;
      const detail = await getInventoryOperation(resource, result.id);
      setSelected(detail);
      setForm(toForm(detail));
      setEditing(false);
      setSuccess(`Operation posted with ${result.movement_ids?.length ?? 0} ledger movement${result.movement_ids?.length === 1 ? '' : 's'}.`);
      await refresh();
    } catch (caught) {
      setError(message(caught, 'Unable to post this inventory operation.'));
      setFieldErrors(fields(caught));
    } finally {
      setBusy(false);
    }
  }

  async function cancel(event: FormEvent) {
    event.preventDefault();
    if (!selected) return;
    clearFeedback();
    if (cancelReason.trim().length < 3) {
      setFieldErrors({ reason: 'Enter a cancellation reason.' });
      return;
    }
    setBusy(true);
    try {
      const result = await cancelInventoryOperation(resource, selected, cancelReason.trim(), commandKey(cancelKey));
      cancelKey.current = null;
      const detail = await getInventoryOperation(resource, result.id);
      setSelected(detail);
      setForm(toForm(detail));
      setEditing(false);
      setSuccess('Draft operation cancelled.');
      await refresh();
    } catch (caught) {
      setError(message(caught, 'Unable to cancel this inventory operation.'));
      setFieldErrors(fields(caught));
    } finally {
      setBusy(false);
    }
  }

  function updateLine(index: number, patch: Partial<LineForm>) {
    setForm((current) => ({
      ...current,
      lines: current.lines.map((line, lineIndex) => lineIndex === index ? { ...line, ...patch } : line),
    }));
    setFieldErrors({});
  }

  function addLine() {
    setForm((current) => ({ ...current, lines: [...current.lines, blankLine()] }));
  }

  function removeLine(index: number) {
    setForm((current) => ({
      ...current,
      lines: current.lines.length === 1 ? current.lines : current.lines.filter((_, lineIndex) => lineIndex !== index),
    }));
  }

  function changeType(type: InventoryOperationType) {
    setForm((current) => ({ ...current, operation_type: type, lines: [blankLine()] }));
    setFieldErrors({});
  }

  function clearFeedback() {
    setError(null);
    setSuccess(null);
    setFieldErrors({});
  }

  return (
    <>
      <PageHeader
        code={config.code}
        batch="B05-B07"
        title={config.title}
        description={config.description}
        onNew={workspace?.allowed_actions.includes('CREATE') ? startNew : undefined}
      />
      <div className="live-notice inventory-operation-notice"><span /><b>Live controlled workflow</b>{config.notice}</div>

      <div className="kpi-grid inventory-operation-kpis">
        <Kpi label="All operations" value={workspace?.summary.total ?? '—'} note="Current plant" />
        <Kpi label="Draft" value={workspace?.summary.draft ?? '—'} note="Editable, not posted" />
        <Kpi label="Posted" value={workspace?.summary.posted ?? '—'} note="Ledger committed" />
        <Kpi label="Cancelled" value={workspace?.summary.cancelled ?? '—'} note="No stock effect" />
      </div>

      <div className="module-grid inventory-operation-workspace">
        <section className="panel">
          <form className="inventory-operation-toolbar" onSubmit={(event) => {
            event.preventDefault(); setPage(1); setSearch(searchDraft.trim());
          }}>
            <label>Search
              <span><input aria-label="Search operations" value={searchDraft} onChange={(event) => setSearchDraft(event.target.value)} placeholder="Number, reason, actor" /><button className="secondary compact-button">Search</button></span>
            </label>
            <label>Status<select aria-label="Filter operation status" value={statusFilter} onChange={(event) => { setPage(1); setStatusFilter(event.target.value); }}><option value="">All statuses</option>{workspace?.lookups.statuses.map((value) => <option key={value}>{value}</option>)}</select></label>
            <label>Type<select aria-label="Filter operation type" value={typeFilter} onChange={(event) => { setPage(1); setTypeFilter(event.target.value); }}><option value="">All types</option>{workspace?.lookups.operation_types.map((value) => <option key={value}>{label(value)}</option>)}</select></label>
            <label>Sort<select aria-label="Sort operations" value={sort} onChange={(event) => { setPage(1); setSort(event.target.value); }}>{(workspace?.lookups.sorts ?? ['NEWEST']).map((value) => <option key={value} value={value}>{label(value)}</option>)}</select></label>
          </form>
          {error && !selected && <ErrorMessage value={error} />}
          <div className="table-wrap">
            <table className="inventory-operation-table">
              <thead><tr><th>Operation</th><th>Type</th><th>Status</th><th>Reason</th><th>Lines</th><th>Updated</th><th /></tr></thead>
              <tbody>
                {workspace?.data.map((operation) => <tr key={operation.id} className={selected?.id === operation.id ? 'selected-row' : ''}>
                  <td><b className="link">{operation.operation_number}</b><small>v{operation.record_version} · {operation.created_by.name}</small></td>
                  <td><b>{label(operation.operation_type)}</b><small>{operation.operation_type}</small></td>
                  <td><StatusBadge status={operation.status} /></td>
                  <td><b>{label(operation.reason_code)}</b><small>{operation.notes || 'No note'}</small></td>
                  <td>{operation.line_count}</td>
                  <td>{dateTime(operation.updated_at)}</td>
                  <td><button className="secondary compact-button" type="button" onClick={() => void choose(operation.id)}>Open</button></td>
                </tr>)}
              </tbody>
            </table>
            {!loading && workspace?.data.length === 0 && <div className="empty-state">No operations match this plant and filter.</div>}
            {loading && <div className="empty-state">Loading controlled inventory operations…</div>}
          </div>
          {workspace && <Pagination meta={workspace.meta} page={page} onPage={setPage} />}
        </section>

        <aside className="panel inventory-operation-editor">
          <div className="panel-head"><div><h3>{selected ? selected.operation_number : editing ? `New ${config.title} draft` : 'Operation detail'}</h3><span>{selected ? `${label(selected.operation_type)} · v${selected.record_version}` : 'Select an operation or create a draft.'}</span></div>{selected && <StatusBadge status={selected.status} />}</div>
          {detailLoading ? <div className="empty-state">Loading operation detail…</div> : (!selected && !editing) ? <div className="empty-state">Open an operation to inspect its lines and ledger evidence.</div> : (
            <div className="inventory-operation-detail">
              {error && <ErrorMessage value={error} />}
              {success && <div className="form-success" role="status"><span />{success}</div>}
              <form className="operation-form" onSubmit={save}>
                <fieldset disabled={busy || !editing}>
                  <div className="operation-field-grid">
                    <Field label="Operation number" error={fieldErrors.operation_number}><input aria-label="Operation number" readOnly={Boolean(selected)} className={selected ? 'read-only' : ''} value={form.operation_number} onChange={(event) => setForm({ ...form, operation_number: event.target.value.toUpperCase() })} /></Field>
                    <Field label="Operation type" error={fieldErrors.operation_type}><select aria-label="Operation type" disabled={Boolean(selected)} value={form.operation_type} onChange={(event) => changeType(event.target.value as InventoryOperationType)}>{(workspace?.lookups.operation_types ?? typesFor(resource)).map((value) => <option key={value} value={value}>{label(value)}</option>)}</select></Field>
                    <Field label="Reason code" error={fieldErrors.reason_code}><input aria-label="Operation reason code" value={form.reason_code} onChange={(event) => setForm({ ...form, reason_code: event.target.value.toUpperCase() })} /></Field>
                    <Field label="Notes" wide error={fieldErrors.notes}><textarea aria-label="Operation notes" rows={2} value={form.notes} onChange={(event) => setForm({ ...form, notes: event.target.value })} /></Field>
                  </div>

                  <div className="operation-lines-head"><div><h4>Controlled lines</h4><small>Each position can appear once per operation.</small></div>{editing && <button className="secondary compact-button" type="button" onClick={addLine}>+ Add line</button>}</div>
                  <div className="operation-lines">
                    {form.lines.map((lineForm, index) => <OperationLineEditor
                      key={index}
                      index={index}
                      type={form.operation_type}
                      line={lineForm}
                      detail={selected?.lines?.[index]}
                      positions={positions}
                      errors={fieldErrors}
                      disabled={!editing || busy}
                      onChange={(patch) => updateLine(index, patch)}
                      onRemove={() => removeLine(index)}
                      removable={form.lines.length > 1}
                    />)}
                  </div>
                </fieldset>
                {editing && <div className="form-actions operation-save-actions"><button className="primary" type="submit" disabled={busy}>{busy ? 'Saving…' : selected ? 'Save draft' : 'Create draft'}</button></div>}
              </form>

              {selected?.status === 'DRAFT' && <div className="operation-command-grid">
                {selected.allowed_actions.includes('POST') && <section className="inventory-command"><h4>Post to stock ledger</h4><p>Revalidates scope, position eligibility, reservations, and count versions under lock.</p><button className="primary" type="button" disabled={busy} onClick={() => void post()}>{busy ? 'Posting…' : 'Post operation'}</button></section>}
                {selected.allowed_actions.includes('CANCEL') && <form className="inventory-command destructive-command" onSubmit={cancel}><h4>Cancel draft</h4><p>Cancellation leaves stock unchanged and records the reason.</p><label>Cancellation reason<textarea aria-label="Cancellation reason" rows={2} value={cancelReason} onChange={(event) => setCancelReason(event.target.value)} /></label><FieldError value={fieldErrors.reason} /><button className="secondary" disabled={busy || cancelReason.trim().length < 3}>Cancel operation</button></form>}
              </div>}

              {selected && <OperationEvidence operation={selected} />}
            </div>
          )}
        </aside>
      </div>
    </>
  );
}

function OperationLineEditor(props: {
  index: number;
  type: InventoryOperationType;
  line: LineForm;
  detail?: InventoryOperation['lines'] extends (infer T)[] | undefined ? T : never;
  positions: OperationPosition[];
  errors: Record<string, string>;
  disabled: boolean;
  removable: boolean;
  onChange: (patch: Partial<LineForm>) => void;
  onRemove: () => void;
}) {
  const number = props.index + 1;
  const usesSource = props.type !== 'RETURN';
  const usesTarget = props.type === 'RETURN' || props.type === 'TRANSFER' || props.type === 'EXPIRY';
  const sourceOptions = sourcePositions(props.type, props.positions);
  const selectedSource = props.positions.find((position) => position.id === props.line.source_position_id);
  const targetOptions = targetPositions(props.type, props.positions, selectedSource);
  const error = (field: string) => props.errors[`lines.${props.index}.${field}`] ?? props.errors[field];

  return <section className="operation-line-card">
    <div className="operation-line-title"><b>Line {number}</b>{props.removable && !props.disabled && <button type="button" onClick={props.onRemove}>Remove</button>}</div>
    <div className="operation-line-grid">
      {usesSource && <Field label="Source position" wide error={error('source_position_id') ?? error('position')}><select aria-label={`Line ${number} source position`} value={props.line.source_position_id} onChange={(event) => props.onChange({ source_position_id: event.target.value, target_position_id: '' })}><option value="">Select source position</option>{positionOptions(sourceOptions)}</select></Field>}
      {usesTarget && <Field label="Target position" wide error={error('target_position_id') ?? error('position')}><select aria-label={`Line ${number} target position`} value={props.line.target_position_id} onChange={(event) => props.onChange({ target_position_id: event.target.value })}><option value="">Select target position</option>{positionOptions(targetOptions)}</select></Field>}
      {props.type === 'COUNT' ? <Field label="Counted quantity" error={error('counted_quantity_base')}><input aria-label={`Line ${number} counted quantity`} type="number" min="0" step="any" value={props.line.counted_quantity_base} onChange={(event) => props.onChange({ counted_quantity_base: event.target.value })} /></Field> : <Field label="Quantity" error={error('quantity_base')}><input aria-label={`Line ${number} quantity`} type="number" min="0" step="any" value={props.line.quantity_base} onChange={(event) => props.onChange({ quantity_base: event.target.value })} /></Field>}
      {props.type === 'ADJUSTMENT' && <Field label="Direction" error={error('adjustment_direction')}><select aria-label={`Line ${number} adjustment direction`} value={props.line.adjustment_direction} onChange={(event) => props.onChange({ adjustment_direction: event.target.value as AdjustmentDirection })}><option value="INCREASE">Increase</option><option value="DECREASE">Decrease</option></select></Field>}
      <Field label="Line note" wide error={error('notes')}><input aria-label={`Line ${number} note`} value={props.line.notes} onChange={(event) => props.onChange({ notes: event.target.value })} /></Field>
    </div>
    {props.detail && <div className="line-snapshot"><span>UOM <b>{props.detail.uom_code}</b></span>{props.detail.system_quantity_base !== null && <><span>Snapshot <b>{quantity(props.detail.system_quantity_base)}</b></span><span>Variance <b className={Number(props.detail.variance_quantity_base) < 0 ? 'text-bad' : 'text-good'}>{quantity(props.detail.variance_quantity_base ?? '0')}</b></span><span>Position version <b>v{props.detail.source_position_version}</b></span></>}{props.detail.movement_id && <span>Movement <b className="mono">{shortId(props.detail.movement_id)}</b></span>}</div>}
  </section>;
}

function OperationEvidence({ operation }: { operation: InventoryOperation }) {
  return <section className="operation-evidence">
    <h4>Posting evidence</h4>
    <dl className="control-definition">
      <div><dt>Created by</dt><dd>{operation.created_by.name}<small>{dateTime(operation.created_at)}</small></dd></div>
      <div><dt>Record version</dt><dd>v{operation.record_version}<small>{operation.status}</small></dd></div>
      {operation.posted_by && <div><dt>Posted by</dt><dd>{operation.posted_by.name}<small>{dateTime(operation.posted_at!)}</small></dd></div>}
      {operation.cancelled_by && <div><dt>Cancelled by</dt><dd>{operation.cancelled_by.name}<small>{operation.cancellation_reason}</small></dd></div>}
    </dl>
    {operation.movements?.length ? <div className="table-wrap operation-movements"><table><thead><tr><th>Movement</th><th>Direction</th><th>Quantity</th><th>From / To</th><th>Posted</th></tr></thead><tbody>{operation.movements.map((movement) => <tr key={movement.id}><td><b>{label(movement.movement_type)}</b><small className="mono">{shortId(movement.id)}</small></td><td><StatusBadge status={movement.direction} /></td><td><b>{quantity(movement.quantity_base)} {movement.uom_code}</b><small>{label(movement.reason_code ?? '')}</small></td><td><b>{movement.from_position?.location.code ?? 'External'} → {movement.to_position?.location.code ?? 'External'}</b><small>{movement.from_position?.lot.code ?? movement.to_position?.lot.code}</small></td><td>{dateTime(movement.posted_at)}</td></tr>)}</tbody></table></div> : <div className="empty-state compact">No stock movement has been posted for this draft.</div>}
  </section>;
}

function Field({ label: fieldLabel, error, wide, children }: { label: string; error?: string; wide?: boolean; children: React.ReactNode }) {
  return <label className={wide ? 'wide' : ''}>{fieldLabel}{children}<FieldError value={error} /></label>;
}

function FieldError({ value }: { value?: string }) {
  return value ? <span className="field-error">{value}</span> : null;
}

function ErrorMessage({ value }: { value: string }) {
  return <div className="form-error" role="alert"><span>{value}</span></div>;
}

function Kpi({ label: kpiLabel, value, note }: { label: string; value: string | number; note: string }) {
  return <div className="kpi"><span>{kpiLabel}</span><b>{value}</b><small>{note}</small></div>;
}

function Pagination({ meta, page, onPage }: { meta: InventoryOperationWorkspace['meta']; page: number; onPage: (value: number) => void }) {
  return <div className="pagination"><button disabled={page <= 1} onClick={() => onPage(page - 1)}>Previous</button><span>Page {meta.current_page} of {meta.last_page} · {meta.total} records</span><button disabled={page >= meta.last_page} onClick={() => onPage(page + 1)}>Next</button></div>;
}

function blankForm(resource: InventoryOperationResource, preferred?: InventoryOperationType): OperationForm {
  return { operation_number: '', operation_type: preferred ?? typesFor(resource)[0], reason_code: '', notes: '', lines: [blankLine()] };
}

function blankLine(): LineForm {
  return { source_position_id: '', target_position_id: '', quantity_base: '', counted_quantity_base: '', adjustment_direction: 'INCREASE', notes: '' };
}

function toForm(operation: InventoryOperation): OperationForm {
  return {
    operation_number: operation.operation_number,
    operation_type: operation.operation_type,
    reason_code: operation.reason_code,
    notes: operation.notes ?? '',
    lines: operation.lines?.map((line) => ({
      source_position_id: line.source_position_id ?? '',
      target_position_id: line.target_position_id ?? '',
      quantity_base: line.quantity_base ?? '',
      counted_quantity_base: line.counted_quantity_base ?? '',
      adjustment_direction: line.adjustment_direction ?? 'INCREASE',
      notes: line.notes ?? '',
    })) ?? [blankLine()],
  };
}

function writePayload(form: OperationForm) {
  return {
    reason_code: form.reason_code.trim().toUpperCase(),
    notes: nullable(form.notes),
    lines: form.lines.map((line): InventoryOperationLineWrite => {
      if (form.operation_type === 'COUNT') return { source_position_id: line.source_position_id, counted_quantity_base: line.counted_quantity_base, notes: nullable(line.notes) };
      if (form.operation_type === 'RETURN') return { target_position_id: line.target_position_id, quantity_base: line.quantity_base, notes: nullable(line.notes) };
      if (form.operation_type === 'TRANSFER' || form.operation_type === 'EXPIRY') return { source_position_id: line.source_position_id, target_position_id: line.target_position_id, quantity_base: line.quantity_base, notes: nullable(line.notes) };
      return { source_position_id: line.source_position_id, quantity_base: line.quantity_base, adjustment_direction: form.operation_type === 'ADJUSTMENT' ? line.adjustment_direction : null, notes: nullable(line.notes) };
    }),
  };
}

function validate(form: OperationForm): Record<string, string> {
  const errors: Record<string, string> = {};
  if (!form.operation_number.trim()) errors.operation_number = 'Operation number is required.';
  if (!form.reason_code.trim()) errors.reason_code = 'Reason code is required.';
  form.lines.forEach((line, index) => {
    if (form.operation_type !== 'RETURN' && !line.source_position_id) errors[`lines.${index}.source_position_id`] = 'Select a source position.';
    if (['RETURN', 'TRANSFER', 'EXPIRY'].includes(form.operation_type) && !line.target_position_id) errors[`lines.${index}.target_position_id`] = 'Select a target position.';
    if (form.operation_type === 'COUNT') {
      if (line.counted_quantity_base === '' || Number(line.counted_quantity_base) < 0) errors[`lines.${index}.counted_quantity_base`] = 'Enter a non-negative count.';
    } else if (!line.quantity_base || Number(line.quantity_base) <= 0) errors[`lines.${index}.quantity_base`] = 'Enter a positive quantity.';
  });
  return errors;
}

function sourcePositions(type: InventoryOperationType, positions: OperationPosition[]): OperationPosition[] {
  const today = new Date().toISOString().slice(0, 10);
  return positions.filter((position) => {
    if (type === 'ISSUE') return Number(position.quantity.available) > 0;
    if (type === 'EXPIRY') return Number(position.quantity.total) > 0 && Boolean(position.lot.expiry_date && position.lot.expiry_date < today) && position.quality_status.code !== 'EXPIRED';
    if (type === 'DISPOSAL') return Number(position.quantity.total) > 0 && (position.quality_status.availability_bucket === 'BLOCKED' || Boolean(position.lot.expiry_date && position.lot.expiry_date < today) || position.lot.status !== 'ACTIVE');
    if (type === 'TRANSFER') return Number(position.quantity.total) > 0;
    return positions.includes(position);
  });
}

function targetPositions(type: InventoryOperationType, positions: OperationPosition[], source?: OperationPosition): OperationPosition[] {
  if (type === 'RETURN') return positions.filter((position) => position.quality_status.code === 'RETURN_QUARANTINE');
  if (!source) return [];
  return positions.filter((position) => position.id !== source.id
    && position.sku.id === source.sku.id
    && position.lot.id === source.lot.id
    && position.owner.id === source.owner.id
    && position.quantity.uom_code === source.quantity.uom_code
    && (type !== 'TRANSFER' || position.quality_status.code === source.quality_status.code)
    && (type !== 'EXPIRY' || position.quality_status.code === 'EXPIRED'));
}

function positionOptions(positions: OperationPosition[]) {
  return positions.map((position) => <option key={position.id} value={position.id}>{position.label} · {quantity(position.quantity.total)} {position.quantity.uom_code}</option>);
}

function typesFor(resource: InventoryOperationResource): InventoryOperationType[] {
  return resource === 'issues' ? ['ISSUE', 'RETURN']
    : resource === 'transfers' ? ['TRANSFER']
      : resource === 'counts' ? ['COUNT', 'ADJUSTMENT'] : ['EXPIRY', 'DISPOSAL'];
}

function commandKey(ref: React.MutableRefObject<string | null>): string {
  ref.current ??= crypto.randomUUID();
  return ref.current;
}

function message(error: unknown, fallback: string): string {
  return isApiError(error) ? error.message : fallback;
}

function fields(error: unknown): Record<string, string> {
  if (!isApiError(error) || !error.fields) return {};
  return Object.fromEntries(Object.entries(error.fields).map(([field, values]) => [field, values[0] ?? 'Invalid value.']));
}

function label(value: string): string {
  return value.replaceAll('_', ' ').replaceAll('-', ' ').toLowerCase().replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function nullable(value: string): string | null {
  const trimmed = value.trim();
  return trimmed || null;
}

function quantity(value: string): string {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? new Intl.NumberFormat(undefined, { maximumFractionDigits: 6 }).format(parsed) : value;
}

function dateTime(value: string): string {
  return new Intl.DateTimeFormat(undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value));
}

function shortId(value: string): string {
  return `${value.slice(0, 8)}…${value.slice(-4)}`;
}

import { useCallback, useEffect, useMemo, useState, type FormEvent, type ReactNode } from 'react';
import { createPortal } from 'react-dom';
import { isApiError } from '../api/client';
import { commandP2, downloadP2, getP2, listP2, type P2Record, type P2Workspace } from '../api/p2Operations';
import { useErpSession } from '../app/ErpSessionContext';
import { PageHeader } from './PageHeader';
import { StatusBadge } from './StatusBadge';
import { FeedbackToast } from './FeedbackToast';
import { SummaryStrip } from './ManufacturingWorkspaceShell';
import { StructuredCommandForm, validateStructuredCommand, type StructuredCommandSchema } from './StructuredCommandForm';
import { formatBusinessDate, formatZonedDateTime } from '../utils/dateTime';

export type P2Column = { label: string; key: string; format?: 'date' | 'datetime' | 'money' | 'unitCost' | 'number' | 'text' };
export type P2Collection = { key: string; label: string; columns: P2Column[]; detailPath?: (record: P2Record) => string; kind?: string; showStatus?: boolean };
export type P2Creator = {
  action: string;
  label: string;
  path: string | ((workspace: P2Workspace) => string);
  help: string;
  template: (workspace: P2Workspace) => unknown;
  schema: StructuredCommandSchema;
  expectedVersion?: (workspace: P2Workspace) => number | undefined;
  available?: (workspace: P2Workspace) => boolean;
};
export type P2EditorSpec = { label: string; help: string; path: string; body: unknown; schema: StructuredCommandSchema; expectedVersion?: number };
export type P2RunSpec = { path: string; body?: unknown; expectedVersion?: number; success: string };
export type P2ActionSpec = P2RunSpec | { editor: P2EditorSpec } | { downloadPath: string; filename: string };

export type GovernedP2Config = {
  code: string;
  batch?: string;
  title: string;
  description: string;
  notice: string;
  listPath: string;
  collections: P2Collection[];
  creators?: P2Creator[];
  supportsStatus?: boolean;
  resolveAction?: (action: string, record: P2Record, workspace: P2Workspace) => P2ActionSpec | null;
};

type EditorState = P2EditorSpec;
type Feedback = { error: string | null; success: string | null; fields: Record<string, string> };

export function GovernedP2Workspace({ config }: { config: GovernedP2Config }) {
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const [workspace, setWorkspace] = useState<P2Workspace | null>(null);
  const [collectionKey, setCollectionKey] = useState(config.collections[0]?.key ?? 'data');
  const [selected, setSelected] = useState<P2Record | null>(null);
  const [editor, setEditor] = useState<EditorState | null>(null);
  const [search, setSearch] = useState('');
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [feedback, setFeedback] = useState<Feedback>({ error: null, success: null, fields: {} });

  const refresh = useCallback(async () => {
    setLoading(true);
    try {
      const next = await listP2(config.listPath, { q: search || undefined, status: config.supportsStatus === false ? undefined : status || undefined });
      setWorkspace(next);
      setLoadError(null);
      setSelected((current) => current && config.collections.some((item) => collectionContainsId(workspacePath(next, item.key), current.id)) ? current : null);
    } catch (error) {
      setLoadError(failureMessage(error, `Unable to load ${config.title.toLowerCase()}.`));
    } finally {
      setLoading(false);
    }
  }, [config.listPath, config.supportsStatus, config.title, contextKey, search, status]);

  useEffect(() => { void refresh(); }, [refresh]);
  useEffect(() => { setCollectionKey(config.collections[0]?.key ?? 'data'); setSelected(null); setEditor(null); setSearch(''); setStatus(''); setFeedback(clear()); }, [contextKey, config.code, config.collections]);

  const collection = config.collections.find((item) => item.key === collectionKey) ?? config.collections[0];
  const records = useMemo(() => {
    const values = workspacePath(workspace, collection?.key ?? 'data');
    return Array.isArray(values) ? values.map((value) => ({ ...(value as P2Record), _kind: collection?.kind ?? collection?.key })) : [];
  }, [collection, workspace]);
  const statuses = useMemo(() => Array.from(new Set(records.map((record) => String(record.status ?? '')).filter(Boolean))).sort(), [records]);
  const availableCreators = (config.creators ?? []).filter((creator) => Boolean(workspace?.allowed_actions?.includes(creator.action) && (!creator.available || creator.available(workspace!))));
  const collectionIndex = config.collections.findIndex((item) => item.key === collectionKey);
  const contextualCreator = availableCreators[collectionIndex] ?? availableCreators[0];

  async function open(record: P2Record) {
    setEditor(null); setFeedback(clear());
    if (!collection?.detailPath) { setSelected(record); return; }
    setSelected(null);
    try { setSelected({ ...(await getP2(collection.detailPath(record))), _kind: collection.kind ?? collection.key }); }
    catch (error) { setFailure(setFeedback, error, 'Unable to load record detail.'); }
  }

  function startCreator(creator: P2Creator) {
    if (!workspace) return;
    const body = creator.template(workspace);
    setSelected(null); setFeedback(clear());
    setEditor({ label: creator.label, help: creator.help, path: typeof creator.path === 'function' ? creator.path(workspace) : creator.path, expectedVersion: creator.expectedVersion?.(workspace), body, schema: creator.schema });
  }

  async function submit(event: FormEvent) {
    event.preventDefault(); if (!editor) return;
    const fields = validateStructuredCommand(editor.body, editor.schema);
    if (Object.keys(fields).length) {
      setFeedback({ error: 'Check the highlighted field.', success: null, fields });
      focusFirstInvalid(fields);
      return;
    }
    setBusy(true);
    try {
      const result = await commandP2(editor.path, editor.body, editor.expectedVersion);
      setEditor(null); setSelected(null); setFeedback({ error: null, success: `${editor.label} saved successfully. Current status: ${label(result.status)}.`, fields: {} });
      await refresh();
    } catch (error) { setFailure(setFeedback, error, `Unable to complete ${editor.label.toLowerCase()}.`); }
    finally { setBusy(false); }
  }

  async function act(action: string) {
    if (!selected || !workspace || !config.resolveAction) return;
    const spec = config.resolveAction(action, selected, workspace); if (!spec) return;
    if ('editor' in spec) { const value = spec.editor; setFeedback(clear()); setEditor(value); return; }
    if ('downloadPath' in spec) {
      setBusy(true);
      try { const blob = await downloadP2(spec.downloadPath); saveBlob(blob, spec.filename); setFeedback({ error: null, success: 'Private document downloaded after scope and permission verification.', fields: {} }); }
      catch (error) { setFailure(setFeedback, error, 'Unable to download the private document.'); }
      finally { setBusy(false); }
      return;
    }
    setBusy(true);
    try {
      await commandP2(spec.path, spec.body ?? {}, spec.expectedVersion ?? selected.record_version);
      setFeedback({ error: null, success: spec.success, fields: {} });
      await refresh();
      if (collection?.detailPath) setSelected({ ...(await getP2(collection.detailPath(selected))), _kind: selected._kind });
    } catch (error) { setFailure(setFeedback, error, `Unable to ${action.toLowerCase().replaceAll('_', ' ')}.`); }
    finally { setBusy(false); }
  }

  const drawer = (editor || selected || feedback.error) ? <div className="p2-drawer-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) { setEditor(null); setSelected(null); setFeedback(clear()); } }}><aside className="panel requisition-editor p2-drawer" role="dialog" aria-modal="true" aria-label={editor?.label ?? (selected ? 'Record details' : 'Action error')}><div className="requisition-detail-body">
    {feedback.error ? <div className="form-error" role="alert"><span>{feedback.error}</span></div> : null}
    {editor ? <CommandEditor editor={editor} setEditor={setEditor} workspace={workspace} busy={busy} submit={submit} close={() => { setEditor(null); setFeedback(clear()); }} fields={feedback.fields} /> : selected ? <><button className="drawer-close" type="button" aria-label="Close record details" onClick={() => { setSelected(null); setFeedback(clear()); }}>×</button><RecordDetail record={selected} busy={busy} onAction={act} /></> : <button className="secondary" type="button" onClick={() => setFeedback(clear())}>Close</button>}
  </div></aside></div> : null;

  return <>
    <PageHeader code={config.code} batch={config.batch ?? 'P2 operational'} title={config.title} description={config.description} onNew={contextualCreator ? () => startCreator(contextualCreator) : undefined} />
    <details className="business-guidance"><summary>How this workspace works</summary><p>{config.notice}</p></details>
    {workspace?.summary ? <SummaryStrip summary={workspace.summary} /> : null}
    {config.collections.length > 1 || availableCreators.length > 1 ? <div className="workspace-tabs p2-command-tabs">
      {config.collections.map((item) => <button key={item.key} className={item.key === collectionKey ? 'active' : ''} onClick={() => { setCollectionKey(item.key); setSelected(null); setEditor(null); setStatus(''); }}>{item.label}</button>)}
      <i />
      {availableCreators.map((creator) => <button key={`${creator.action}:${creator.label}`} className="p2-create-command" onClick={() => startCreator(creator)}>+ {creator.label}</button>)}
    </div> : null}
    <div className="p2-workspace" aria-busy={loading}>
      <section className="panel p2-register-panel">
        <div className="requisition-toolbar p2-toolbar">
          <label>Search<input aria-label={`${config.title} search`} value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Number, customer, reference or description" /></label>
          {config.supportsStatus === false ? <div /> : <label>Status<select aria-label={`${config.title} status`} value={status} onChange={(event) => setStatus(event.target.value)}><option value="">All statuses</option>{statuses.map((item) => <option key={item}>{item}</option>)}</select></label>}
          <button className="secondary compact-button" type="button" disabled={loading} onClick={() => void refresh()}>Refresh</button>
        </div>
        {loading && workspace ? <div className="register-updating" role="status"><span aria-hidden="true" />Updating records… Current data remains visible.</div> : null}
        {loadError ? <div className="form-error register-load-error" role="alert"><span>{loadError} Your filters and entered values have been kept.</span><button className="secondary compact-button" type="button" disabled={loading} onClick={() => void refresh()}>Retry loading</button></div> : null}
        {loading && !workspace ? <RegisterSkeleton columns={collection?.columns.length ?? 4} /> : <Register records={records} columns={collection?.columns ?? []} showStatus={collection?.showStatus !== false} selectedId={selected?.id} onOpen={open} />}
      </section>
    </div>
    {drawer ? createPortal(drawer, document.body) : null}
    <FeedbackToast message={feedback.success} onDismiss={() => setFeedback((current) => ({ ...current, success: null }))} />
  </>;
}

function Register({ records, columns, showStatus, selectedId, onOpen }: { records: P2Record[]; columns: P2Column[]; showStatus: boolean; selectedId?: string; onOpen: (record: P2Record) => void }) {
  if (!records.length) return <Empty text="No live records match the selected context and filters." />;
  return <div className="table-wrap"><table className="requisition-table p2-register"><thead><tr>{columns.map((column) => <th key={column.key}>{column.label}</th>)}{showStatus ? <th>Status</th> : null}<th>Action</th></tr></thead><tbody>{records.map((record) => <tr key={`${record._kind}:${record.id}`} className={selectedId === record.id ? 'selected-row' : ''}>{columns.map((column) => <td className={column.format === 'money' || column.format === 'number' || column.format === 'unitCost' ? 'numeric-cell' : undefined} key={column.key}>{renderValue(read(record, column.key), column.format)}</td>)}{showStatus ? <td>{record.status ? <StatusBadge status={String(record.status)} /> : <span className="muted">Not applicable</span>}</td> : null}<td><button className="secondary compact-button" type="button" onClick={() => void onOpen(record)}>Open</button></td></tr>)}</tbody></table></div>;
}

function RegisterSkeleton({ columns }: { columns: number }) {
  return <div className="register-skeleton" aria-label="Loading records">{Array.from({ length: 5 }, (_, row) => <div key={row}>{Array.from({ length: columns + 1 }, (_, cell) => <span key={cell} />)}</div>)}</div>;
}

function CommandEditor({ editor, setEditor, workspace, busy, submit, close, fields }: { editor: EditorState; setEditor: (value: EditorState) => void; workspace: P2Workspace | null; busy: boolean; submit: (event: FormEvent) => void; close: () => void; fields: Record<string, string> }) {
  return <form className="p2-command-editor" onSubmit={submit} noValidate><fieldset disabled={busy}><div className="detail-status"><StatusBadge status="DRAFT ENTRY" /><span>* Required fields</span></div><h2>{editor.label}</h2><p>{editor.help}</p><StructuredCommandForm value={editor.body} workspace={workspace} errors={fields} schema={editor.schema} onChange={(body) => setEditor({ ...editor, body: refreshCollectionAllocation(body, editor.body, workspace) })} /><div className="callout">Available choices come from your selected company and workplace. Totals, stock, credit, accounting periods, and approvals are checked automatically when you save.</div><div className="form-actions sticky-form-actions"><button className="secondary" type="button" onClick={close}>Close</button><button className="primary" type="submit" disabled={busy}>{busy ? 'Saving…' : 'Save'}</button></div></fieldset></form>;
}

function refreshCollectionAllocation(body: unknown, previous: unknown, workspace: P2Workspace | null): unknown {
  if (!body || typeof body !== 'object' || Array.isArray(body) || !previous || typeof previous !== 'object' || Array.isArray(previous)) return body;
  const next = refreshItemUnits(body as Record<string, unknown>, previous as Record<string, unknown>, workspace);
  const before = previous as Record<string, unknown>;
  const allocations = Array.isArray(next.allocations) ? next.allocations : [];
  const priorAllocations = Array.isArray(before.allocations) ? before.allocations : [];
  if (allocations.length !== 1 || !allocations[0] || typeof allocations[0] !== 'object') return next;
  const allocation = allocations[0] as Record<string, unknown>;
  const prior = priorAllocations[0] && typeof priorAllocations[0] === 'object' ? priorAllocations[0] as Record<string, unknown> : {};
  if (allocation.invoice_id === prior.invoice_id) return next;
  const lookups = workspace?.lookups && typeof workspace.lookups === 'object' ? workspace.lookups as Record<string, unknown> : {};
  const invoices = Array.isArray(lookups.open_invoices) ? lookups.open_invoices : [];
  const invoice = invoices.find((item) => item && typeof item === 'object' && String((item as Record<string, unknown>).id ?? '') === String(allocation.invoice_id ?? '')) as Record<string, unknown> | undefined;
  if (!invoice) return next;
  const amount = String(invoice.outstanding_amount ?? '0');
  return { ...next, customer_party_id: invoice.customer_party_id, total_amount: amount, allocations: [{ ...allocation, amount }] };
}

function refreshItemUnits(next: Record<string, unknown>, previous: Record<string, unknown>, workspace: P2Workspace | null): Record<string, unknown> {
  const lookupRoot = workspace?.lookups && typeof workspace.lookups === 'object' ? workspace.lookups as Record<string, unknown> : {};
  const items = Array.isArray(lookupRoot.items) ? lookupRoot.items.filter(isRecord) : [];
  if (!items.length) return next;

  function sync(current: unknown, before: unknown): unknown {
    if (Array.isArray(current)) {
      const prior = Array.isArray(before) ? before : [];
      return current.map((row, index) => sync(row, prior[index]));
    }
    if (!isRecord(current)) return current;
    const prior = isRecord(before) ? before : {};
    let result: Record<string, unknown> = Object.fromEntries(
      Object.entries(current).map(([key, value]) => [key, sync(value, prior[key])]),
    );
    if ('item_id' in result && 'uom_code' in result && String(result.item_id ?? '') !== String(prior.item_id ?? '')) {
      const item = items.find((row) => String(row.id ?? '') === String(result.item_id ?? ''));
      const uom = item?.base_uom ?? item?.uom_code;
      if (typeof uom === 'string' && uom.trim()) result = { ...result, uom_code: uom };
    }
    return result;
  }

  return sync(next, previous) as Record<string, unknown>;
}

function isRecord(value: unknown): value is Record<string, unknown> {
  return Boolean(value) && typeof value === 'object' && !Array.isArray(value);
}

function RecordDetail({ record, busy, onAction }: { record: P2Record; busy: boolean; onAction: (action: string) => void }) {
  const hidden = ['allowed_actions', '_kind', 'lines', 'events', 'revisions', 'transactions', 'proof', 'invoice', 'result_json', 'diagnostic_snapshot', 'validation_json', 'payload_json'];
  const facts = Object.entries(record).filter(([key, value]) => !hidden.includes(key) && !technicalKey(key) && (value === null || ['string', 'number', 'boolean'].includes(typeof value))).slice(0, 22);
  const technical = Object.entries(record).filter(([key, value]) => !hidden.includes(key) && technicalKey(key) && (value === null || ['string', 'number', 'boolean'].includes(typeof value)));
  const collections = Object.entries(record).filter(([, value]) => Array.isArray(value)) as [string, unknown[]][];
  const objects = Object.entries(record).filter(([key, value]) => !['allowed_actions'].includes(key) && value && typeof value === 'object' && !Array.isArray(value));
  const identifier = businessIdentifier(record);
  const customer = read(record, 'customer.name') ?? record.customer_name;
  return <div className="requisition-detail p2-detail">
    <header className="business-record-header"><div><small>{label(String(record._kind ?? 'record'))}</small><h2>{identifier}</h2></div>{record.status ? <StatusBadge status={String(record.status)} /> : null}</header>
    {(customer || record.requested_delivery_date || record.total_amount) ? <div className="business-record-summary">{customer ? <span><small>Customer</small><b>{String(customer)}</b></span> : null}{record.requested_delivery_date ? <span><small>Due</small><b>{formatBusinessDate(String(record.requested_delivery_date))}</b></span> : null}{record.total_amount ? <span><small>Total</small><b>{renderValue(record.total_amount, 'money')}</b></span> : null}</div> : null}
    <dl className="control-definition">{facts.map(([key, value]) => <div key={key}><dt>{label(key)}</dt><dd>{renderValue(value, moneyKey(key) ? (key.includes('unit_cost') ? 'unitCost' : 'money') : key.endsWith('_at') ? 'datetime' : key.includes('date') ? 'date' : 'text')}</dd></div>)}</dl>
    {objects.map(([key, value]) => <section className="p2-object-evidence" key={key}><h4>{label(key)}</h4><ObjectFacts value={value as Record<string, unknown>} /></section>)}
    {collections.map(([key, values]) => <section className="p2-related" key={key}><h4>{label(key)}</h4><RelatedRows values={values} /></section>)}
    {technical.length ? <details className="technical-details"><summary>Technical details</summary><dl>{technical.map(([key, value]) => <div key={key}><dt>{label(key)}</dt><dd><code>{String(value ?? '—')}</code>{value ? <button type="button" onClick={() => void navigator.clipboard?.writeText(String(value))}>Copy</button> : null}</dd></div>)}</dl></details> : null}
    {(record.allowed_actions?.length ?? 0) > 0 ? <div className="p2-action-grid">{record.allowed_actions!.map((action) => <button className={action.includes('CANCEL') || action.includes('REJECT') || action === 'CLOSE' ? 'secondary' : 'primary'} type="button" disabled={busy} key={action} onClick={() => onAction(action)}>{label(action)}</button>)}</div> : <div className="callout">{record._kind === 'claim' ? 'No action is available for this claim in its current state and your assigned role. Sales users create and resolve claims; Operations users receive goods after a claim reaches Return Required.' : 'This record is read-only in its current state or for your assigned role.'}</div>}
  </div>;
}

function RelatedRows({ values }: { values: unknown[] }) {
  if (!values.length) return <Empty text="No related evidence has been recorded." />;
  const rows = values.filter((value): value is Record<string, unknown> => Boolean(value) && typeof value === 'object'); const available = Array.from(new Set(rows.flatMap((row) => Object.keys(row)))).filter((key) => !key.endsWith('_id') && !['id', 'company_id', 'plant_id', 'created_at', 'updated_at'].includes(key)); const priority = ['item_code', 'item_name', 'internal_lot_code', 'lot_code', 'location_code', 'quality_status', 'allocated_quantity', 'picked_quantity', 'uom_code']; const keys = [...priority.filter((key) => available.includes(key)), ...available.filter((key) => !priority.includes(key))].slice(0, 8);
  return <div className="table-wrap"><table><thead><tr>{keys.map((key) => <th key={key}>{label(key)}</th>)}</tr></thead><tbody>{rows.map((row, index) => <tr key={String(row.id ?? index)}>{keys.map((key) => <td key={key}>{renderValue(row[key], moneyKey(key) ? 'money' : 'text')}</td>)}</tr>)}</tbody></table></div>;
}

function ObjectFacts({ value }: { value: Record<string, unknown> }) {
  const facts = Object.entries(value).filter(([, item]) => item === null || ['string', 'number', 'boolean'].includes(typeof item));
  const collections = Object.entries(value).filter(([, item]) => Array.isArray(item)) as [string, unknown[]][];
  return <>{facts.length ? <dl className="control-definition">{facts.filter(([key]) => !technicalKey(key)).map(([key, item]) => <div key={key}><dt>{label(key)}</dt><dd>{renderValue(item, moneyKey(key) ? (key.includes('unit_cost') ? 'unitCost' : 'money') : key.endsWith('_at') ? 'datetime' : key.includes('date') ? 'date' : 'text')}</dd></div>)}</dl> : <div className="empty-state compact">No additional details were recorded.</div>}{collections.map(([key, items]) => <section className="p2-related" key={key}><h4>{label(key)}</h4><RelatedRows values={items} /></section>)}</>;
}

function Empty({ text }: { text: string }) { return <div className="empty-state">{text}</div>; }
function read(record: P2Record, path: string): unknown { return path.split('.').reduce<unknown>((value, key) => value && typeof value === 'object' ? (value as Record<string, unknown>)[key] : undefined, record); }
function workspacePath(workspace: P2Workspace | null, path: string): unknown { return path.split('.').reduce<unknown>((value, key) => value && typeof value === 'object' ? (value as Record<string, unknown>)[key] : undefined, workspace); }
function label(value: string) { return value.toLowerCase().replaceAll('_', ' ').replaceAll('-', ' ').replace(/\b\w/g, (character) => character.toUpperCase()); }
function moneyKey(key: string) { return !key.includes('percent') && /(amount|cost|price|value|revenue|margin|debit|credit)$/i.test(key); }
function technicalKey(key: string) { return key === 'id' || key.endsWith('_id') || key.endsWith('_by') || key.endsWith('_uuid') || key === 'record_version' || key === 'company_id' || key === 'plant_id'; }
function businessIdentifier(record: P2Record): string { for (const key of ['order_number', 'invoice_number', 'allocation_number', 'shipment_number', 'claim_number', 'asset_number', 'number', 'code', 'name']) { if (record[key]) return String(record[key]); } return 'Record details'; }
function renderValue(value: unknown, format: P2Column['format'] = 'text'): ReactNode { if (value === null || value === undefined || value === '') return '—'; if (typeof value === 'object') return Array.isArray(value) ? `${value.length} records` : Object.values(value as Record<string, unknown>).filter((item) => typeof item === 'string').slice(0, 2).join(' · '); if (format === 'money') return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(Number(value) || 0); if (format === 'unitCost') return new Intl.NumberFormat('en-IN', { minimumFractionDigits: 2, maximumFractionDigits: 4 }).format(Number(value) || 0); if (format === 'number') return new Intl.NumberFormat('en-IN', { maximumFractionDigits: 4 }).format(Number(value) || 0); if (format === 'date') return formatBusinessDate(String(value)); if (format === 'datetime') return formatZonedDateTime(String(value)); if (typeof value === 'boolean') return value ? 'Yes' : 'No'; return String(value); }
function clear(): Feedback { return { error: null, success: null, fields: {} }; }
function failureMessage(error: unknown, fallback: string): string { const api = isApiError(error) ? error : null; return api?.message ?? (error instanceof Error ? error.message : fallback); }
function setFailure(setter: (value: Feedback) => void, error: unknown, fallback: string) { const api = isApiError(error) ? error : null; const fields: Record<string, string> = {}; Object.entries(api?.fields ?? {}).forEach(([key, values]) => { fields[key] = quantityError(key, values[0] ?? ''); }); const hasFields = Object.keys(fields).length > 0; setter({ error: hasFields ? 'Check the highlighted field.' : api?.message ?? (error instanceof Error ? error.message : fallback), success: null, fields }); if (hasFields) focusFirstInvalid(fields); }
function quantityError(path: string, message: string): string { return /(?:^|\.)quantity$/.test(path) && /(greater than|gt|at least|positive)/i.test(message) ? 'Quantity must be greater than 0.' : message.replace(/(?:lines\.)?\d+\./g, ''); }
function focusFirstInvalid(fields: Record<string, string>) { const path = Object.keys(fields)[0]; if (!path) return; window.requestAnimationFrame(() => { const escaped = typeof CSS !== 'undefined' && CSS.escape ? CSS.escape(path) : path.replace(/["\\]/g, '\\$&'); const target = document.querySelector<HTMLElement>(`[data-field-path="${escaped}"]`); target?.focus(); }); }
function collectionContainsId(value: unknown, id: string): boolean { return Array.isArray(value) && value.some((item) => item && typeof item === 'object' && String((item as Record<string, unknown>).id ?? '') === id); }
function saveBlob(blob: Blob, filename: string) { const url = URL.createObjectURL(blob); const anchor = document.createElement('a'); anchor.href = url; anchor.download = filename; document.body.append(anchor); anchor.click(); anchor.remove(); URL.revokeObjectURL(url); }

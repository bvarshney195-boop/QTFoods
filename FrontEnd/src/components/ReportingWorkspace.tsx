import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import {
  createReportExport,
  downloadReportExport,
  generateReport,
  getReportRun,
  listReportRuns,
  type ReportColumn,
  type ReportDefinition,
  type ReportRun,
  type ReportingWorkspace as ReportingWorkspaceResponse,
} from '../api/reporting';
import { isApiError } from '../api/client';
import { useErpSession } from '../app/ErpSessionContext';
import { PageHeader } from './PageHeader';
import { StatusBadge } from './StatusBadge';

type RunDraft = {
  run_number: string;
  report_code: string;
  as_of_date: string;
  parameters: Record<string, boolean>;
};

export function ReportingWorkspace() {
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const [workspace, setWorkspace] = useState<ReportingWorkspaceResponse | null>(null);
  const [selected, setSelected] = useState<ReportRun | null>(null);
  const [draft, setDraft] = useState<RunDraft | null>(null);
  const [search, setSearch] = useState('');
  const [reportCode, setReportCode] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const runKey = useRef<string | null>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    try {
      const next = await listReportRuns({ q: search || undefined, report_code: reportCode || undefined });
      setWorkspace(next);
      setSelected((current) => current && next.data.some((run) => run.id === current.id) ? current : null);
      setError(null);
    } catch (caught) {
      setError(message(caught, 'Unable to load report runs.'));
    } finally {
      setLoading(false);
    }
  }, [contextKey, search, reportCode]);

  useEffect(() => { void refresh(); }, [refresh]);
  useEffect(() => {
    if (!success) return;
    const timer = window.setTimeout(() => setSuccess(null), 5000);
    return () => window.clearTimeout(timer);
  }, [success]);
  useEffect(() => {
    setSelected(null); setDraft(null); setSearch(''); setReportCode('');
    setError(null); setSuccess(null); setFieldErrors({}); runKey.current = null;
  }, [contextKey]);

  const definitions = workspace?.definitions ?? [];
  const canRun = Boolean(workspace?.allowed_actions.includes('RUN'));
  const summary = workspace?.summary;

  function startRun() {
    const definition = definitions[0];
    if (!definition) return;
    setSelected(null); setError(null); setSuccess(null); setFieldErrors({}); runKey.current = null;
    setDraft({
      run_number: nextRunNumber(), report_code: definition.code, as_of_date: today(),
      parameters: defaultParameters(definition),
    });
  }

  function selectDefinition(code: string) {
    if (!draft) return;
    const definition = definitions.find((value) => value.code === code);
    if (definition) setDraft({ ...draft, report_code: code, as_of_date: today(), parameters: defaultParameters(definition) });
  }

  async function open(run: ReportRun) {
    setDraft(null); setError(null); setSuccess(null); setFieldErrors({});
    try { setSelected(await getReportRun(run.id)); }
    catch (caught) { setError(message(caught, 'Unable to open the immutable report snapshot.')); }
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!draft) return;
    const fields: Record<string, string> = {};
    if (!draft.run_number.trim()) fields.run_number = 'Run number is required.';
    if (!draft.report_code) fields.report_code = 'Choose a report definition.';
    if (!draft.as_of_date) fields.as_of_date = 'Choose a cutoff date.';
    if (Object.keys(fields).length) { setFieldErrors(fields); setError('Correct the highlighted report fields.'); return; }
    setBusy(true); setError(null); setSuccess(null); setFieldErrors({});
    try {
      runKey.current ??= crypto.randomUUID();
      const result = await generateReport({
        ...draft,
        run_number: draft.run_number.trim().toUpperCase(),
      }, runKey.current);
      runKey.current = null;
      await refresh();
      setSelected(await getReportRun(result.id));
      setDraft(null);
      setSuccess(`Immutable ${result.report_code} snapshot generated with ${result.row_count} rows.`);
    } catch (caught) {
      capture(caught, setError, setFieldErrors, 'Unable to generate the report snapshot.');
    } finally {
      setBusy(false);
    }
  }

  async function exportRun(format: 'CSV' | 'JSON') {
    if (!selected) return;
    setBusy(true); setError(null); setSuccess(null);
    try {
      const result = await createReportExport(selected.id, format, crypto.randomUUID());
      const blob = await downloadReportExport(result.id);
      save(blob, result.file_name ?? `${selected.run_number.toLowerCase()}.${format.toLowerCase()}`);
      setSelected(await getReportRun(selected.id));
      await refresh();
      setSuccess(`${format} export created from stored snapshot rows and downloaded.`);
    } catch (caught) {
      setError(message(caught, `Unable to create the ${format} export.`));
    } finally {
      setBusy(false);
    }
  }

  return <>
    <PageHeader code="BI-REP" batch="B04 live" title="Controlled Reports" description="Generate immutable scoped read-model snapshots with explicit cutoffs, source freshness, totals, and checksum-backed exports." onNew={canRun ? startRun : undefined} />
    <div className="live-notice reporting-notice"><span />Report runs never refresh in place. CSV and JSON exports are rebuilt only from the stored rows and carry their own SHA-256 integrity metadata.</div>
    {summary ? <div className="manufacturing-summary reporting-summary">
      <Metric label="Report runs" value={summary.total_runs} />
      <Metric label="Rows snapshotted" value={summary.rows_snapshotted} />
      <Metric label="Exports" value={summary.exports_created} />
      <Metric label="Latest source freshness" value={summary.latest_freshness_at ? dateTime(summary.latest_freshness_at) : 'No source yet'} />
    </div> : null}
    {success ? <div className="toast success-toast" role="status" aria-live="polite"><span aria-hidden="true">✓</span><div><b>Success</b><p>{success}</p></div><button type="button" aria-label="Dismiss success notification" onClick={() => setSuccess(null)}>×</button></div> : null}
    <div className={`module-grid requisition-workspace reporting-workspace ${draft || selected || error ? 'has-detail' : 'register-only'}`}>
      <section className="panel">
        <div className="requisition-toolbar reporting-toolbar">
          <label>Search runs<input aria-label="Report search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Run number or report" /></label>
          <label>Definition<select aria-label="Report definition filter" value={reportCode} onChange={(event) => setReportCode(event.target.value)}><option value="">All definitions</option>{definitions.map((definition) => <option key={definition.code} value={definition.code}>{definition.title}</option>)}</select></label>
          <button type="button" className="secondary compact-button" disabled={loading} onClick={() => void refresh()}>Refresh</button>
        </div>
        {loading && !workspace ? <Empty text="Loading controlled report history..." /> : workspace?.data.length ? <div className="table-wrap"><table className="requisition-table reporting-register"><thead><tr><th>Run</th><th>Definition</th><th>Cutoff</th><th>Freshness</th><th>Rows</th><th>Checksum</th><th /></tr></thead><tbody>{workspace.data.map((run) => <tr key={run.id} className={selected?.id === run.id ? 'selected-row' : ''}>
          <td><b>{run.run_number}</b><small>{run.created_by.name} · {dateTime(run.generated_at)}</small></td>
          <td><StatusBadge status={run.report_code} /><small>{run.report_title}</small></td>
          <td>{dateTime(run.as_of_at)}</td><td>{dateTime(run.source_freshness_at)}</td><td>{run.row_count}</td>
          <td className="report-checksum">{run.sha256.slice(0, 14)}…</td><td><button type="button" className="secondary compact-button" onClick={() => void open(run)}>Open</button></td>
        </tr>)}</tbody></table></div> : <Empty text="No immutable report runs match this scope and filter." />}
      </section>
      {(draft || selected || error) ? <aside className="panel requisition-editor reporting-editor"><div className="requisition-detail-body">
        {error ? <div className="form-error" role="alert"><span>{error}</span><button type="button" onClick={() => setError(null)}>Dismiss</button></div> : null}
        {draft ? <ReportRunForm draft={draft} definitions={definitions} busy={busy} errors={fieldErrors} setDraft={setDraft} selectDefinition={selectDefinition} submit={submit} close={() => { setDraft(null); setError(null); setFieldErrors({}); runKey.current = null; }} />
          : selected ? <ReportDetail run={selected} busy={busy} exportRun={exportRun} />
            : null}
      </div></aside> : null}
    </div>
  </>;
}

function ReportRunForm({ draft, definitions, busy, errors, setDraft, selectDefinition, submit, close }: {
  draft: RunDraft; definitions: ReportDefinition[]; busy: boolean; errors: Record<string, string>;
  setDraft: (value: RunDraft) => void; selectDefinition: (code: string) => void;
  submit: (event: FormEvent) => void; close: () => void;
}) {
  const definition = definitions.find((value) => value.code === draft.report_code);
  return <form className="requisition-form reporting-form" onSubmit={submit}><fieldset disabled={busy}>
    <div className="detail-status"><StatusBadge status="NEW SNAPSHOT" /><span>Immutable after generation</span></div>
    <div className="requisition-field-grid">
      <label>Run number<input aria-label="Report run number" value={draft.run_number} onChange={(event) => setDraft({ ...draft, run_number: event.target.value.toUpperCase() })} /><FieldError value={errors.run_number} /></label>
      <label>Report definition<select aria-label="Report definition" value={draft.report_code} onChange={(event) => selectDefinition(event.target.value)}>{definitions.map((item) => <option key={item.code} value={item.code}>{item.title}</option>)}</select><FieldError value={errors.report_code} /></label>
      <label>As-of cutoff<input aria-label="Report cutoff date" type="date" max={today()} value={draft.as_of_date} disabled={definition?.historical_cutoff === false} onChange={(event) => setDraft({ ...draft, as_of_date: event.target.value })} /><FieldError value={errors.as_of_date} /></label>
      <div className="report-freshness-source"><span>Freshness source</span><b>{definition?.freshness_source ?? '—'}</b></div>
    </div>
    {definition ? <div className="callout reporting-definition-note"><b>{definition.title}</b>{definition.description}{!definition.historical_cutoff ? ' This read model is current-only and requires today.' : ''}</div> : null}
    {definition?.parameters.length ? <fieldset className="report-parameters"><legend>Snapshot parameters</legend>{definition.parameters.map((parameter) => <label key={parameter.key}><input type="checkbox" checked={Boolean(draft.parameters[parameter.key])} onChange={(event) => setDraft({ ...draft, parameters: { ...draft.parameters, [parameter.key]: event.target.checked } })} />{parameter.label}</label>)}</fieldset> : null}
    <div className="form-actions"><button type="button" className="secondary" onClick={close}>Close</button><button type="submit" className="primary">Generate immutable snapshot</button></div>
  </fieldset></form>;
}

function ReportDetail({ run, busy, exportRun }: { run: ReportRun; busy: boolean; exportRun: (format: 'CSV' | 'JSON') => Promise<void> }) {
  const rows = run.rows ?? [];
  const columns = run.columns ?? [];
  return <div className="requisition-detail reporting-detail">
    <div className="detail-status"><StatusBadge status={run.status} /><b>{run.report_number ?? run.run_number}</b><span>{run.row_count} rows</span></div>
    <div className="report-primary-actions">
      <div><b>{run.report_title}</b><small>{dateTime(run.as_of_at)} · {run.row_count} rows</small></div>
      {run.allowed_actions?.includes('EXPORT') ? <div className="p2-action-grid"><button type="button" className="primary" disabled={busy} onClick={() => void exportRun('CSV')}>Create & download CSV</button><button type="button" className="secondary" disabled={busy} onClick={() => void exportRun('JSON')}>Create & download JSON</button></div> : null}
    </div>
    <section className="report-totals"><h4>Stored totals</h4><div>{Object.entries(run.totals).map(([key, value]) => <span key={key}>{label(key)}<b>{formatTotal(key, value)}</b></span>)}</div></section>
    <section className="report-rows"><h4>Immutable snapshot rows</h4>{rows.length ? <div className="table-wrap"><table><thead><tr>{columns.map((column) => <th key={column.key}>{column.label}</th>)}</tr></thead><tbody>{rows.map((row) => <tr key={row.id}>{columns.map((column) => <td key={column.key}>{formatCell(row.data[column.key], column)}</td>)}</tr>)}</tbody></table></div> : <Empty text="The controlled source contained no rows at this cutoff." />}</section>
    <details className="technical-details report-integrity"><summary>Export & integrity details</summary>
      <dl className="control-definition">
        <Datum label="Definition" value={`${run.report_title} (${run.report_code})`} />
        <Datum label="Source freshness" value={dateTime(run.source_freshness_at)} />
        <Datum label="Generated" value={`${dateTime(run.generated_at)} by ${run.created_by.name}`} />
        <Datum label="Snapshot SHA-256" value={run.sha256} wide mono />
        <Datum label="Parameters" value={Object.entries(run.parameters).map(([key, value]) => `${label(key)}: ${value ? 'Yes' : 'No'}`).join(' · ') || 'None'} wide />
      </dl>
      {run.exports?.length ? <section className="report-exports"><h4>Export evidence</h4>{run.exports.map((item) => <div key={item.id}><StatusBadge status={item.format} /><span><b>{item.file_name}</b><small>{formatBytes(item.size_bytes)} · {item.created_by.name} · {dateTime(item.created_at)}</small><code>{item.sha256}</code></span></div>)}</section> : null}
    </details>
  </div>;
}

function Metric({ label: text, value }: { label: string; value: string | number }) { return <div><span>{text}</span><b>{value}</b></div>; }
function Datum({ label: text, value, wide, mono }: { label: string; value: string; wide?: boolean; mono?: boolean }) { return <div className={wide ? 'wide' : ''}><dt>{text}</dt><dd className={mono ? 'report-checksum full' : ''}>{value}</dd></div>; }
function FieldError({ value }: { value?: string }) { return value ? <span className="field-error">{value}</span> : null; }
function Empty({ text }: { text: string }) { return <div className="empty-state">{text}</div>; }
function defaultParameters(definition: ReportDefinition) { return Object.fromEntries(definition.parameters.map((parameter) => [parameter.key, parameter.default])); }
function today() { const now = new Date(); return new Date(now.getTime() - now.getTimezoneOffset() * 60000).toISOString().slice(0, 10); }
function nextRunNumber() { return `REP-${new Date().toISOString().replace(/\D/g, '').slice(0, 14)}`; }
function dateTime(value: string) { const parsed = new Date(value); return Number.isNaN(parsed.valueOf()) ? value : new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium', timeStyle: 'short' }).format(parsed); }
function date(value: unknown) { if (!value) return '—'; const parsed = new Date(`${String(value).slice(0, 10)}T00:00:00Z`); return Number.isNaN(parsed.valueOf()) ? String(value) : new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium', timeZone: 'UTC' }).format(parsed); }
function label(value: string) { return value.toLowerCase().replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()); }
function number(value: unknown, digits = 2) { const parsed = Number(value); return Number.isFinite(parsed) ? new Intl.NumberFormat('en-IN', { minimumFractionDigits: digits, maximumFractionDigits: digits }).format(parsed) : String(value ?? '—'); }
function formatCell(value: unknown, column: ReportColumn) { if (value === null || value === undefined || value === '') return '—'; if (column.type === 'DATE') return date(value); if (column.type === 'MONEY') return number(value, 2); if (column.type === 'QUANTITY') return number(value, 3); if (column.type === 'PERCENT') return `${number(value, 2)}%`; return String(value); }
function formatTotal(key: string, value: unknown) { if (key.includes('count')) return String(value); return number(value, key.includes('amount') || key === 'debit' || key === 'credit' || key === 'balance' || key === 'order_value' ? 2 : 3); }
function formatBytes(value: number) { if (value < 1024) return `${value} B`; if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`; return `${(value / 1024 / 1024).toFixed(1)} MB`; }
function message(error: unknown, fallback: string) { return isApiError(error) ? error.message : error instanceof Error ? error.message : fallback; }
function capture(error: unknown, setError: (value: string) => void, setFields: (value: Record<string, string>) => void, fallback: string) { setError(message(error, fallback)); setFields(isApiError(error) && error.fields ? Object.fromEntries(Object.entries(error.fields).map(([key, values]) => [key, values[0]])) : {}); }
function save(blob: Blob, filename: string) { const url = URL.createObjectURL(blob); const anchor = document.createElement('a'); anchor.href = url; anchor.download = filename; document.body.append(anchor); anchor.click(); anchor.remove(); URL.revokeObjectURL(url); }

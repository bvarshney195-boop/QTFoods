import { type FormEvent, useEffect, useState } from 'react';
import {
  getAuditEvidence,
  getAuditEvent,
  listAuditEvents,
  type AuditEvent,
  type AuditEventDetail,
  type AuditEvidence,
  type AuditFilters,
  type AuditWorkspace,
} from '../api/controlOperations';
import { isApiError } from '../api/client';
import { PageHeader } from '../components/PageHeader';
import { StatusBadge } from '../components/StatusBadge';
import { formatZonedDateTime } from '../utils/dateTime';

type Preview = { evidenceId: string; url: string | null; text: string | null; mimeType: string };

export default function ADM_AUD() {
  const [workspace, setWorkspace] = useState<AuditWorkspace | null>(null);
  const [detail, setDetail] = useState<AuditEventDetail | null>(null);
  const [draft, setDraft] = useState<AuditFilters>({ sort: 'NEWEST', per_page: 25 });
  const [filters, setFilters] = useState<AuditFilters>({ sort: 'NEWEST', per_page: 25 });
  const [loading, setLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [preview, setPreview] = useState<Preview | null>(null);
  const [fileBusy, setFileBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => { void refresh(); }, [filters]);
  useEffect(() => () => revokePreview(preview), [preview]);

  async function refresh() {
    setLoading(true); setError(null);
    try {
      const next = await listAuditEvents(filters);
      setWorkspace(next);
      if (detail && !next.data.some((event) => event.id === detail.id)) {
        setDetail(null); setPreview(null);
      }
    } catch (caught) {
      setError(message(caught, 'Unable to load scoped audit events.'));
    } finally { setLoading(false); }
  }

  async function open(event: AuditEvent) {
    setDetailLoading(true); setError(null); setPreview(null);
    try { setDetail(await getAuditEvent(event.id)); }
    catch (caught) { setError(message(caught, 'Unable to load the audit event.')); }
    finally { setDetailLoading(false); }
  }

  function search(event: FormEvent) {
    event.preventDefault();
    setFilters({ ...draft, q: draft.q?.trim(), page: 1 });
  }

  function reset() {
    const blank: AuditFilters = { sort: 'NEWEST', per_page: 25 };
    setDraft(blank); setFilters(blank); setDetail(null); setPreview(null);
  }

  async function viewEvidence(evidence: AuditEvidence) {
    if (!detail) return;
    setFileBusy(evidence.id); setError(null); setPreview(null);
    try {
      const blob = await getAuditEvidence(detail.id, evidence.id);
      if (evidence.mime_type === 'text/plain') {
        setPreview({ evidenceId: evidence.id, url: null, text: await blob.text(), mimeType: evidence.mime_type });
      } else {
        setPreview({ evidenceId: evidence.id, url: URL.createObjectURL(blob), text: null, mimeType: evidence.mime_type });
      }
    } catch (caught) { setError(message(caught, 'Unable to open this evidence object.')); }
    finally { setFileBusy(null); }
  }

  async function downloadEvidence(evidence: AuditEvidence) {
    if (!detail) return;
    setFileBusy(evidence.id); setError(null);
    try {
      const blob = await getAuditEvidence(detail.id, evidence.id, true);
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url; anchor.download = evidence.original_name; anchor.click();
      URL.revokeObjectURL(url);
    } catch (caught) { setError(message(caught, 'Unable to download this evidence object.')); }
    finally { setFileBusy(null); }
  }

  const summary = workspace?.summary;
  return <>
    <PageHeader code="ADM-AUD" batch="B04" title="Audit & Evidence" description="Scoped control history with request correlation, safe change detail, and authenticated evidence review." />
    <div className="live-notice"><span></span><b>Immutable control evidence</b> Search results and file access are restricted to the active company and plant; every evidence view is audited.</div>
    <div className="kpi-grid control-kpis">
      <div className="kpi"><span>Events</span><b>{loading && !workspace ? '-' : summary?.total ?? 0}</b><small>active plant</small></div>
      <div className="kpi"><span>Successful</span><b>{loading && !workspace ? '-' : summary?.success ?? 0}</b><small>completed controls</small></div>
      <div className="kpi"><span>Exceptions</span><b>{loading && !workspace ? '-' : summary?.failure ?? 0}</b><small>failed or denied</small></div>
      <div className="kpi"><span>Evidence</span><b>{loading && !workspace ? '-' : summary?.evidence ?? 0}</b><small>retained objects</small></div>
    </div>
    {error && <div className="form-error panel-message" role="alert"><span></span>{error}</div>}
    <form className="panel control-filter" onSubmit={search}>
      <label>Search<input aria-label="Search audit" value={draft.q ?? ''} onChange={(e) => setDraft({ ...draft, q: e.target.value })} placeholder="Command, actor, request, entity…" /></label>
      <label>Outcome<select aria-label="Audit outcome" value={draft.outcome ?? ''} onChange={(e) => setDraft({ ...draft, outcome: e.target.value })}><option value="">All outcomes</option>{workspace?.lookups.outcomes.map((value) => <option key={value}>{value}</option>)}</select></label>
      <label>Command<select aria-label="Audit command" value={draft.command ?? ''} onChange={(e) => setDraft({ ...draft, command: e.target.value })}><option value="">All commands</option>{workspace?.lookups.commands.map((value) => <option key={value}>{value}</option>)}</select></label>
      <label>Entity type<select aria-label="Audit entity type" value={draft.entity_type ?? ''} onChange={(e) => setDraft({ ...draft, entity_type: e.target.value })}><option value="">All entities</option>{workspace?.lookups.entity_types.map((value) => <option key={value}>{value}</option>)}</select></label>
      <label>From<input aria-label="Audit from" type="date" value={draft.from ?? ''} onChange={(e) => setDraft({ ...draft, from: e.target.value })} /></label>
      <label>To<input aria-label="Audit to" type="date" value={draft.to ?? ''} onChange={(e) => setDraft({ ...draft, to: e.target.value })} /></label>
      <label>Order<select aria-label="Audit order" value={draft.sort ?? 'NEWEST'} onChange={(e) => setDraft({ ...draft, sort: e.target.value as AuditFilters['sort'] })}><option value="NEWEST">Newest first</option><option value="OLDEST">Oldest first</option></select></label>
      <div className="control-filter-actions"><button className="primary" type="submit">Search</button><button className="secondary" type="button" onClick={reset}>Reset</button></div>
    </form>
    <div className="module-grid admin-workspace control-workspace">
      <section className="panel">
        <div className="panel-head"><div><h3>Audit register</h3><span>{workspace?.meta.total ?? 0} matching event{workspace?.meta.total === 1 ? '' : 's'}</span></div><button className="secondary compact-button" type="button" onClick={() => void refresh()} disabled={loading}>Refresh</button></div>
        {loading && !workspace && <div className="empty-state">Loading audit events…</div>}
        {workspace && !workspace.data.length && <div className="empty-state">No audit event matches these filters.</div>}
        {Boolean(workspace?.data.length) && <div className={`table-wrap ${loading ? 'is-refreshing' : ''}`}><table className="admin-table control-table"><thead><tr><th>Event</th><th>Actor</th><th>Entity</th><th>Outcome</th><th>Evidence</th><th>Time</th><th></th></tr></thead><tbody>{workspace?.data.map((event) => <tr key={event.id} className={detail?.id === event.id ? 'selected-row' : ''}><td><b>{humanise(event.command)}</b><small>{event.command}</small></td><td><b>{event.actor.name}</b><small>{event.actor.email ?? event.actor.id}</small></td><td>{humanise(event.entity_type)}<small>{shortId(event.entity_id)}{event.entity_version ? ` · v${event.entity_version}` : ''}</small></td><td><StatusBadge status={event.outcome} />{event.reason_code && <small>{event.reason_code}</small>}</td><td>{event.evidence_count}</td><td>{formatDate(event.event_at)}</td><td><button className="secondary compact-button" type="button" onClick={() => void open(event)}>Open</button></td></tr>)}</tbody></table></div>}
        <Pagination meta={workspace?.meta} onPage={(page) => setFilters({ ...filters, page })} />
      </section>
      <aside className="panel admin-editor control-detail">
        <div className="panel-head"><div><h3>{detail ? humanise(detail.command) : 'Event detail'}</h3><span>{detail ? detail.id : 'Select an audit event'}</span></div></div>
        {detailLoading && <div className="empty-state">Loading event detail…</div>}
        {detail && !detailLoading && <div className="panel-body control-detail-body">
          <div className="detail-status"><StatusBadge status={detail.outcome} /><b>v{detail.entity_version ?? '—'}</b><span>{formatDate(detail.event_at)}</span></div>
          <dl className="control-definition"><div><dt>Actor</dt><dd>{detail.actor.name}<small>{detail.actor.email}</small></dd></div><div><dt>Scope</dt><dd>{detail.company.code} / {detail.plant.code}<small>{detail.plant.name}</small></dd></div><div><dt>Entity</dt><dd>{detail.entity_type}<small>{detail.entity_id}</small></dd></div><div><dt>Reason</dt><dd>{detail.reason_code ?? '—'}</dd></div><div><dt>Request ID</dt><dd className="mono">{detail.request_id ?? '—'}</dd></div><div><dt>Correlation ID</dt><dd className="mono">{detail.correlation_id ?? '—'}</dd></div><div><dt>Trace ID</dt><dd className="mono">{detail.trace_id ?? '—'}<small>{detail.span_id ? `Span ${detail.span_id}` : null}</small></dd></div></dl>
          <section><h4>Safe change detail</h4><pre className="json-view">{formatJson(detail.safe_diff)}</pre></section>
          <section><h4>Linked evidence ({detail.evidence.length})</h4>{!detail.evidence.length && <div className="empty-state compact">No retained file is linked to this event.</div>}{detail.evidence.map((evidence) => <article className="evidence-card" key={evidence.id}><div><b>{evidence.original_name}</b><small>{evidence.category} · {formatBytes(evidence.size_bytes)} · retained to {evidence.retention_until}</small><code>SHA-256 {evidence.sha256}</code></div><div className="row-actions">{evidence.allowed_actions.includes('VIEW') && <button className="secondary compact-button" type="button" onClick={() => void viewEvidence(evidence)} disabled={fileBusy === evidence.id}>{fileBusy === evidence.id ? 'Opening…' : 'View'}</button>}{evidence.allowed_actions.includes('DOWNLOAD') && <button className="secondary compact-button" type="button" onClick={() => void downloadEvidence(evidence)} disabled={fileBusy === evidence.id}>Download</button>}</div>{preview?.evidenceId === evidence.id && <div className="evidence-preview">{preview.text !== null ? <pre>{preview.text}</pre> : preview.mimeType.startsWith('image/') ? <img src={preview.url ?? ''} alt={evidence.original_name} /> : <object data={preview.url ?? ''} type={preview.mimeType} aria-label={evidence.original_name}><p>Preview is unavailable; use Download.</p></object>}</div>}</article>)}</section>
        </div>}
      </aside>
    </div>
  </>;
}

function Pagination({ meta, onPage }: { meta?: AuditWorkspace['meta']; onPage: (page: number) => void }) {
  if (!meta || meta.last_page <= 1) return null;
  return <div className="pagination"><button type="button" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>Previous</button><span>Page {meta.current_page} of {meta.last_page}</span><button type="button" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>Next</button></div>;
}
function revokePreview(preview: Preview | null) { if (preview?.url) URL.revokeObjectURL(preview.url); }
function humanise(value: string) { return value.toLowerCase().replaceAll('_', ' ').replace(/(^|\s)\S/g, (letter) => letter.toUpperCase()); }
function shortId(value: string) { return value.length > 18 ? `${value.slice(0, 8)}…${value.slice(-6)}` : value; }
function formatDate(value: string | null) { return formatZonedDateTime(value); }
function formatBytes(value: number) { return value < 1024 ? `${value} B` : value < 1048576 ? `${(value / 1024).toFixed(1)} KiB` : `${(value / 1048576).toFixed(1)} MiB`; }
function formatJson(value: unknown) { return value === null ? 'No safe change detail was recorded.' : JSON.stringify(value, null, 2); }
function message(error: unknown, fallback: string) { return isApiError(error) ? error.message : fallback; }

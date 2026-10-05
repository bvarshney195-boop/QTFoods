import { type FormEvent, useEffect, useState } from 'react';
import {
  getOutboxEvent,
  listOutboxEvents,
  processDueOutbox,
  quarantineOutboxEvent,
  retryOutboxEvent,
  type OutboxEvent,
  type OutboxEventDetail,
  type OutboxFilters,
  type OutboxWorkspace,
} from '../api/controlOperations';
import { isApiError } from '../api/client';
import { PageHeader } from '../components/PageHeader';
import { StatusBadge } from '../components/StatusBadge';
import { formatZonedDateTime } from '../utils/dateTime';

export default function ADM_INT() {
  const [workspace, setWorkspace] = useState<OutboxWorkspace | null>(null);
  const [detail, setDetail] = useState<OutboxEventDetail | null>(null);
  const [draft, setDraft] = useState<OutboxFilters>({ sort: 'NEWEST', per_page: 25 });
  const [filters, setFilters] = useState<OutboxFilters>({ sort: 'NEWEST', per_page: 25 });
  const [reason, setReason] = useState('');
  const [loading, setLoading] = useState(true);
  const [detailLoading, setDetailLoading] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  useEffect(() => { void refresh(); }, [filters]);

  async function refresh(openId?: string) {
    setLoading(true); setError(null);
    try {
      const next = await listOutboxEvents(filters);
      setWorkspace(next);
      const id = openId ?? detail?.id;
      if (id && next.data.some((event) => event.id === id)) await openById(id);
      else if (id) setDetail(null);
    } catch (caught) { setError(message(caught, 'Unable to load transactional outbox operations.')); }
    finally { setLoading(false); }
  }

  async function open(event: OutboxEvent) { await openById(event.id); }
  async function openById(id: string) {
    setDetailLoading(true); setError(null); setReason('');
    try { setDetail(await getOutboxEvent(id)); }
    catch (caught) { setError(message(caught, 'Unable to load this outbox event.')); }
    finally { setDetailLoading(false); }
  }

  function search(event: FormEvent) { event.preventDefault(); setFilters({ ...draft, q: draft.q?.trim(), page: 1 }); }
  function reset() { const blank: OutboxFilters = { sort: 'NEWEST', per_page: 25 }; setDraft(blank); setFilters(blank); setDetail(null); }

  async function processDue() {
    if (busy) return;
    setBusy(true); clearFeedback();
    try {
      const result = await processDueOutbox(workspace?.runtime.batch_size ?? 50, crypto.randomUUID());
      setSuccess(`Processing complete: ${result.delivered ?? 0} delivered, ${result.retry_scheduled ?? 0} scheduled for retry, ${result.quarantined ?? 0} quarantined.`);
      await refresh();
    } catch (caught) { setError(message(caught, 'Unable to process due outbox events.')); }
    finally { setBusy(false); }
  }

  async function retry() {
    if (!detail || busy) return;
    setBusy(true); clearFeedback();
    try {
      await retryOutboxEvent(detail, crypto.randomUUID());
      setSuccess('Event returned to the delivery queue with its attempt history preserved.');
      await refresh(detail.id);
    } catch (caught) { setError(message(caught, 'Unable to retry this outbox event.')); }
    finally { setBusy(false); }
  }

  async function quarantine(event: FormEvent) {
    event.preventDefault();
    if (!detail || busy || reason.trim().length < 3) return;
    setBusy(true); clearFeedback();
    try {
      await quarantineOutboxEvent(detail, reason.trim(), crypto.randomUUID());
      setSuccess('Event moved to quarantine with an operator reason and audit record.');
      await refresh(detail.id);
    } catch (caught) { setError(message(caught, 'Unable to quarantine this outbox event.')); }
    finally { setBusy(false); }
  }

  function clearFeedback() { setError(null); setSuccess(null); }
  const summary = workspace?.summary;
  return <>
    <PageHeader code="ADM-INT" batch="B04" title="Integration Operations" description="Transactional-outbox delivery, receiver acknowledgements, bounded retry, and operator quarantine controls." />
    <div className="live-notice integration-notice"><span></span><b>Durable delivery control</b> The scheduler dispatches outbox batches to Redis workers; receiver acknowledgements and every attempt remain queryable.{workspace?.allowed_actions.includes('PROCESS_DUE') && <button className="secondary compact-button" type="button" onClick={() => void processDue()} disabled={busy}>{busy ? 'Processing…' : 'Process due now'}</button>}</div>
    <div className="kpi-grid control-kpis integration-kpis">
      <div className="kpi"><span>Pending</span><b>{loading && !workspace ? '-' : summary?.pending ?? 0}</b><small>ready for delivery</small></div>
      <div className="kpi"><span>Retry</span><b>{loading && !workspace ? '-' : summary?.retry ?? 0}</b><small>backoff active</small></div>
      <div className="kpi"><span>Delivered</span><b>{loading && !workspace ? '-' : summary?.delivered ?? 0}</b><small>acknowledged</small></div>
      <div className="kpi"><span>Quarantined</span><b>{loading && !workspace ? '-' : summary?.quarantined ?? 0}</b><small>operator review</small></div>
    </div>
    {(error || success) && <div className={error ? 'form-error panel-message' : 'form-success panel-message'} role={error ? 'alert' : 'status'}><span></span>{error ?? success}</div>}
    {workspace && <section className="panel runtime-strip"><div><span>Queue</span><b>{workspace.runtime.queue_connection}</b></div><div><span>Transport</span><b>{workspace.runtime.transport}</b></div><div><span>Retry policy</span><b>{workspace.runtime.max_attempts} attempts</b><small>{workspace.runtime.base_retry_seconds}s exponential base</small></div><div><span>Last attempt</span><b>{formatDate(workspace.runtime.last_attempt_at)}</b></div><div><span>Evidence store</span><b>{workspace.runtime.evidence_driver}</b><small>{workspace.runtime.evidence_disk}</small></div></section>}
    <form className="panel control-filter integration-filter" onSubmit={search}>
      <label>Search<input aria-label="Search outbox" value={draft.q ?? ''} onChange={(e) => setDraft({ ...draft, q: e.target.value })} placeholder="Type, aggregate, business key, ack…" /></label>
      <label>Status<select aria-label="Outbox status" value={draft.status ?? ''} onChange={(e) => setDraft({ ...draft, status: e.target.value })}><option value="">All statuses</option>{workspace?.lookups.statuses.map((value) => <option key={value}>{value}</option>)}</select></label>
      <label>Event type<select aria-label="Outbox event type" value={draft.event_type ?? ''} onChange={(e) => setDraft({ ...draft, event_type: e.target.value })}><option value="">All event types</option>{workspace?.lookups.event_types.map((value) => <option key={value}>{value}</option>)}</select></label>
      <label>Order<select aria-label="Outbox order" value={draft.sort ?? 'NEWEST'} onChange={(e) => setDraft({ ...draft, sort: e.target.value as OutboxFilters['sort'] })}><option value="NEWEST">Newest first</option><option value="OLDEST">Oldest first</option><option value="NEXT_RETRY">Next retry</option></select></label>
      <div className="control-filter-actions"><button className="primary" type="submit">Search</button><button className="secondary" type="button" onClick={reset}>Reset</button></div>
    </form>
    <div className="module-grid admin-workspace control-workspace">
      <section className="panel">
        <div className="panel-head"><div><h3>Outbox register</h3><span>{workspace?.meta.total ?? 0} matching event{workspace?.meta.total === 1 ? '' : 's'}</span></div><button className="secondary compact-button" type="button" onClick={() => void refresh()} disabled={loading}>Refresh</button></div>
        {loading && !workspace && <div className="empty-state">Loading integration events…</div>}
        {workspace && !workspace.data.length && <div className="empty-state">No outbox event matches these filters.</div>}
        {Boolean(workspace?.data.length) && <div className={`table-wrap ${loading ? 'is-refreshing' : ''}`}><table className="admin-table control-table"><thead><tr><th>Event</th><th>Aggregate</th><th>Status</th><th>Attempts</th><th>Acknowledgement / error</th><th>Created</th><th></th></tr></thead><tbody>{workspace?.data.map((event) => <tr key={event.id} className={detail?.id === event.id ? 'selected-row' : ''}><td><b>{event.event_type}</b><small>{event.business_key}</small></td><td>{event.aggregate_type}<small>{shortId(event.aggregate_id)}</small></td><td><StatusBadge status={event.status} />{event.next_retry_at && <small>Retry {formatDate(event.next_retry_at)}</small>}</td><td>{event.attempts}<small>{event.attempt_history_count} recorded</small></td><td>{event.acknowledgement_id ?? event.last_error_code ?? '—'}{event.last_error_message && <small>{event.last_error_message}</small>}</td><td>{formatDate(event.created_at)}</td><td><button className="secondary compact-button" type="button" onClick={() => void open(event)}>Open</button></td></tr>)}</tbody></table></div>}
        <Pagination meta={workspace?.meta} onPage={(page) => setFilters({ ...filters, page })} />
      </section>
      <aside className="panel admin-editor control-detail">
        <div className="panel-head"><div><h3>{detail?.event_type ?? 'Outbox event detail'}</h3><span>{detail?.id ?? 'Select an event'}</span></div></div>
        {detailLoading && <div className="empty-state">Loading delivery detail…</div>}
        {detail && !detailLoading && <div className="panel-body control-detail-body">
          <div className="detail-status"><StatusBadge status={detail.status} /><b>v{detail.record_version}</b><span>{detail.attempts} delivery attempt{detail.attempts === 1 ? '' : 's'}</span></div>
          <dl className="control-definition"><div><dt>Aggregate</dt><dd>{detail.aggregate_type}<small>{detail.aggregate_id}</small></dd></div><div><dt>Business key</dt><dd className="mono">{detail.business_key}</dd></div><div><dt>Request</dt><dd className="mono">{detail.request_id ?? '—'}</dd></div><div><dt>Correlation</dt><dd className="mono">{detail.correlation_id ?? '—'}</dd></div><div><dt>Trace</dt><dd className="mono">{detail.trace_id ?? '—'}<small>{detail.span_id ? `Span ${detail.span_id}` : null}</small></dd></div><div><dt>Acknowledgement</dt><dd className="mono">{detail.acknowledgement_id ?? '—'}<small>{formatDate(detail.acknowledged_at)}</small></dd></div><div><dt>Last error</dt><dd>{detail.last_error_code ?? '—'}<small>{detail.last_error_message}</small></dd></div><div><dt>Quarantine</dt><dd>{detail.quarantine_reason ?? '—'}<small>{formatDate(detail.quarantined_at)}</small></dd></div></dl>
          <div className="row-actions control-command-actions">{detail.allowed_actions.includes('RETRY') && <button className="primary" type="button" onClick={() => void retry()} disabled={busy}>{busy ? 'Working…' : 'Retry event'}</button>}</div>
          {detail.allowed_actions.includes('QUARANTINE') && <form className="quarantine-form" onSubmit={quarantine}><label>Quarantine reason<textarea aria-label="Quarantine reason" rows={3} minLength={3} maxLength={2000} required value={reason} onChange={(e) => setReason(e.target.value)} /></label><button className="danger-button" type="submit" disabled={busy || reason.trim().length < 3}>Move to quarantine</button></form>}
          <section><h4>Safe event payload</h4><pre className="json-view">{formatJson(detail.payload)}</pre></section>
          <section><h4>Delivery attempt history ({detail.attempt_history.length})</h4>{!detail.attempt_history.length && <div className="empty-state compact">No delivery has been attempted yet.</div>}{detail.attempt_history.map((attempt) => <article className="attempt-card" key={attempt.id}><div><StatusBadge status={attempt.outcome} /><b>Attempt {attempt.attempt_number} · {attempt.transport}</b><small>{formatDate(attempt.completed_at)}</small></div><code>{attempt.worker_id}</code>{(attempt.acknowledgement_id || attempt.error_message) && <p>{attempt.acknowledgement_id ? `Acknowledged: ${attempt.acknowledgement_id}` : `${attempt.error_code}: ${attempt.error_message}`}</p>}</article>)}</section>
        </div>}
      </aside>
    </div>
  </>;
}

function Pagination({ meta, onPage }: { meta?: OutboxWorkspace['meta']; onPage: (page: number) => void }) { if (!meta || meta.last_page <= 1) return null; return <div className="pagination"><button type="button" disabled={meta.current_page <= 1} onClick={() => onPage(meta.current_page - 1)}>Previous</button><span>Page {meta.current_page} of {meta.last_page}</span><button type="button" disabled={meta.current_page >= meta.last_page} onClick={() => onPage(meta.current_page + 1)}>Next</button></div>; }
function shortId(value: string) { return value.length > 18 ? `${value.slice(0, 8)}…${value.slice(-6)}` : value; }
function formatDate(value: string | null) { return formatZonedDateTime(value); }
function formatJson(value: unknown) { return value === null ? 'No payload recorded.' : JSON.stringify(value, null, 2); }
function message(error: unknown, fallback: string) { return isApiError(error) ? error.message : fallback; }

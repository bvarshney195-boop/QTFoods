import { useCallback, useEffect, useState, type CSSProperties, type FormEvent } from 'react';
import { isApiError } from '../api/client';
import {
  claimWorkItem,
  completeWorkItem,
  listWorkItems,
  type WorkItem,
  type WorkItemKind,
  type WorkQueue,
  type WorkQueueFilters,
} from '../api/workQueue';
import { useErpSession } from '../app/ErpSessionContext';
import { PageHeader } from '../components/PageHeader';

type AssignmentFilter = 'ALL' | 'MINE' | 'UNASSIGNED';

export default function WRK_HOME() {
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const [queue, setQueue] = useState<WorkQueue | null>(null);
  const [kind, setKind] = useState<'' | WorkItemKind>('');
  const [assignment, setAssignment] = useState<AssignmentFilter>('ALL');
  const [overdueOnly, setOverdueOnly] = useState(false);
  const [searchDraft, setSearchDraft] = useState('');
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [busyItemId, setBusyItemId] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    setError(null);

    const filters: WorkQueueFilters = {
      assignment,
      overdue: overdueOnly,
      q: search || undefined,
    };
    if (kind) filters.kind = kind;

    try {
      setQueue(await listWorkItems(filters));
    } catch (caught) {
      setError(isApiError(caught) ? caught.message : 'Unable to load the work queue.');
    } finally {
      setLoading(false);
    }
  }, [assignment, contextKey, kind, overdueOnly, search]);

  useEffect(() => {
    setQueue(null);
  }, [contextKey]);

  useEffect(() => {
    setSuccess(null);
    void refresh();
  }, [refresh]);

  function submitSearch(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setSearch(searchDraft.trim());
  }

  async function claim(item: WorkItem) {
    if (busyItemId || !item.allowed_actions.includes('CLAIM')) return;
    setBusyItemId(item.id);
    setError(null);
    setSuccess(null);

    try {
      await claimWorkItem(item.id, item.record_version, globalThis.crypto.randomUUID());
      setSuccess(`“${item.title}” is now assigned to you.`);
      await refresh();
    } catch (caught) {
      setError(isApiError(caught) ? caught.message : 'Unable to claim the work item.');
    } finally {
      setBusyItemId(null);
    }
  }

  async function complete(item: WorkItem) {
    if (busyItemId || !item.allowed_actions.includes('COMPLETE')) return;
    setBusyItemId(item.id);
    setError(null);
    setSuccess(null);

    try {
      await completeWorkItem(item.id, item.record_version, globalThis.crypto.randomUUID());
      setSuccess(`“${item.title}” was completed.`);
      await refresh();
    } catch (caught) {
      setError(isApiError(caught) ? caught.message : 'Unable to complete the work item.');
    } finally {
      setBusyItemId(null);
    }
  }

  function open(item: WorkItem) {
    if (!item.target || !item.allowed_actions.includes('OPEN')) return;
    window.location.hash = item.target.href;
  }

  const summary = queue?.summary;
  const workloadTotal = Math.max(summary?.open_total ?? 0, 1);
  const quickLinks = [
    { code: 'PUR-REQ', label: 'Purchase requests', detail: 'Create and review material requirements', icon: 'PR' },
    { code: 'INV-STK', label: 'Stock overview', detail: 'Review lots, availability and movement', icon: 'ST' },
    { code: 'PLAN-SCH', label: 'Production schedule', detail: 'Check capacity and upcoming batches', icon: 'PS' },
    { code: 'BI-REP', label: 'Reports', detail: 'Open controlled operational reports', icon: 'BI' },
  ].filter((link) => session.allowed_screens.includes(link.code));

  function clearFilters() {
    setKind('');
    setAssignment('ALL');
    setOverdueOnly(false);
    setSearchDraft('');
    setSearch('');
  }

  return (
    <>
      <PageHeader
        code="WRK-HOME"
        batch="B04"
        title="My ERP workspace"
        description="Persisted approvals, assigned work and operational exceptions for the selected company and plant."
      />
      <div className="live-notice">
        <span></span>
        <b>Live control queue</b>
        Deadlines, ownership and completion state are read from workflow records in the ERP database.
      </div>

      <div className="kpi-grid">
        <div className="kpi"><span>Open work</span><b>{loading && !queue ? '—' : summary?.open_total ?? 0}</b><small>visible in this scope</small></div>
        <div className="kpi"><span>Assigned to me</span><b>{loading && !queue ? '—' : summary?.assigned_to_me ?? 0}</b><small>owned actions</small></div>
        <div className="kpi"><span>Pending approvals</span><b>{loading && !queue ? '—' : summary?.approvals ?? 0}</b><small>within your authority</small></div>
        <div className="kpi"><span>Overdue</span><b className={summary?.overdue ? 'text-bad' : ''}>{loading && !queue ? '—' : summary?.overdue ?? 0}</b><small>past deadline</small></div>
      </div>

      <div className="executive-grid">
        <section className="panel executive-pulse" aria-labelledby="workload-pulse-title">
          <div className="panel-head">
            <div><h3 id="workload-pulse-title">Workload pulse</h3><span>Live distribution of visible open work</span></div>
            <span className={`pulse-health ${(summary?.overdue ?? 0) > 0 ? 'needs-attention' : ''}`}>
              {(summary?.overdue ?? 0) > 0 ? 'Needs attention' : 'On track'}
            </span>
          </div>
          <div className="pulse-body">
            <div className="pulse-ring" style={{ '--pulse': `${Math.min(100, ((summary?.assigned_to_me ?? 0) / workloadTotal) * 100)}%` } as CSSProperties}>
              <b>{summary?.open_total ?? 0}</b><span>open</span>
            </div>
            <div className="pulse-bars">
              <WorkloadBar label="Approvals" value={summary?.approvals ?? 0} total={workloadTotal} tone="approval" />
              <WorkloadBar label="Exceptions" value={summary?.exceptions ?? 0} total={workloadTotal} tone="exception" />
              <WorkloadBar label="Unassigned" value={summary?.unassigned ?? 0} total={workloadTotal} tone="unassigned" />
            </div>
          </div>
          <div className="operational-trend" aria-label="Seven-day operational trend">
            <div><span>New work · 7 days</span><b>{summary?.created_7d ?? 0}</b></div>
            <div><span>Completed · 7 days</span><b>{summary?.completed_7d ?? 0}</b></div>
            <div><span>Closure rate</span><b>{summary?.closure_rate_7d ?? 0}%</b></div>
            <div><span>Aged over 3 days</span><b className={(summary?.older_than_three_days ?? 0) > 0 ? 'text-bad' : ''}>{summary?.older_than_three_days ?? 0}</b></div>
          </div>
        </section>

        <section className="panel quick-access" aria-labelledby="quick-access-title">
          <div className="panel-head"><div><h3 id="quick-access-title">Quick access</h3><span>Your most-used operational areas</span></div></div>
          <div className="quick-link-grid">
            {quickLinks.map((link) => (
              <button key={link.code} type="button" onClick={() => { window.location.hash = link.code; }}>
                <i aria-hidden="true">{link.icon}</i><span><b>{link.label}</b><small>{link.detail}</small></span><em aria-hidden="true">→</em>
              </button>
            ))}
          </div>
        </section>
      </div>

      <section className="panel work-queue-panel">
        <div className="panel-head">
          <div><h3>Priority work</h3><span>{summary?.exceptions ?? 0} open exceptions · {summary?.unassigned ?? 0} unassigned</span></div>
          <button className="secondary compact-button" type="button" onClick={() => void refresh()} disabled={loading}>Refresh</button>
        </div>

        <form className="work-toolbar" onSubmit={submitSearch}>
          <label>
            Work type
            <select value={kind} onChange={(event) => setKind(event.target.value as '' | WorkItemKind)}>
              <option value="">All types</option>
              <option value="APPROVAL">Approvals</option>
              <option value="TASK">Tasks</option>
              <option value="EXCEPTION">Exceptions</option>
            </select>
          </label>
          <label>
            Ownership
            <select value={assignment} onChange={(event) => setAssignment(event.target.value as AssignmentFilter)}>
              <option value="ALL">All visible work</option>
              <option value="MINE">Assigned to me</option>
              <option value="UNASSIGNED">Unassigned</option>
            </select>
          </label>
          <label className="work-overdue-filter">
            <input type="checkbox" checked={overdueOnly} onChange={(event) => setOverdueOnly(event.target.checked)} />
            Overdue only
          </label>
          <label className="work-search">
            Search queue
            <span><input value={searchDraft} onChange={(event) => setSearchDraft(event.target.value)} placeholder="Title, source or record ID" /><button className="secondary" type="submit">Search</button></span>
          </label>
        </form>

        {error && <div className="form-error panel-message" role="alert"><span>{error}</span><button type="button" onClick={() => void refresh()}>Retry</button></div>}
        {success && <div className="form-success panel-message" role="status"><span></span>{success}</div>}
        {loading && !queue && <div className="empty-state">Loading your persisted work queue…</div>}
        {!loading && !error && !queue?.data.length && (
          <div className="empty-state enhanced-empty-state">
            <span aria-hidden="true">✓</span>
            <b>{search || kind || assignment !== 'ALL' || overdueOnly ? 'No matching work found' : 'You are all caught up'}</b>
            <p>{search || kind || assignment !== 'ALL' || overdueOnly ? 'Try clearing the filters to see the full work queue.' : 'There are no open actions in this company and plant right now.'}</p>
            {(search || kind || assignment !== 'ALL' || overdueOnly) && <button className="secondary" type="button" onClick={clearFilters}>Clear filters</button>}
          </div>
        )}

        {Boolean(queue?.data.length) && (
          <div className={`table-wrap work-table ${loading ? 'is-refreshing' : ''}`}>
            <table>
              <thead><tr><th>Type</th><th>Work item</th><th>Priority</th><th>Age</th><th>Deadline</th><th>Owner</th><th>Actions</th></tr></thead>
              <tbody>
                {queue!.data.map((item) => (
                  <tr key={item.id} className={item.is_overdue ? 'work-overdue' : ''}>
                    <td data-label="Type"><span className={`work-kind work-kind-${item.kind.toLowerCase()}`}>{kindLabel(item.kind)}</span></td>
                    <td data-label="Work item">
                      <b>{item.title}</b>
                      <small>{item.description ?? sourceLabel(item)}</small>
                      {item.source && <small>{sourceLabel(item)} · v{item.record_version}</small>}
                    </td>
                    <td data-label="Priority"><span className={`status ${priorityClass(item.priority)}`}>{item.priority}</span></td>
                    <td data-label="Age">{ageLabel(item.age_minutes)}<small>{item.age_bucket.replaceAll('_', ' ').toLowerCase()}</small></td>
                    <td data-label="Deadline" className={item.is_overdue ? 'text-bad' : ''}>{dueLabel(item.due_at)}<small>{item.is_overdue ? 'Overdue' : 'Active deadline'}</small></td>
                    <td data-label="Owner">{item.assignee?.name ?? 'Unassigned'}<small>{item.assignee ? item.assignee.email : 'Available to claim'}</small></td>
                    <td data-label="Actions">
                      <div className="work-actions">
                        {item.allowed_actions.includes('CLAIM') && <button className="secondary" type="button" onClick={() => void claim(item)} disabled={busyItemId === item.id}>Claim</button>}
                        {item.allowed_actions.includes('COMPLETE') && <button className="secondary" type="button" onClick={() => void complete(item)} disabled={busyItemId === item.id}>Complete</button>}
                        {item.allowed_actions.includes('OPEN') && <button className="primary" type="button" onClick={() => open(item)}>Open</button>}
                      </div>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </section>
    </>
  );
}

function WorkloadBar({ label, value, total, tone }: { label: string; value: number; total: number; tone: string }) {
  const width = value === 0 ? 0 : Math.max(8, Math.min(100, (value / total) * 100));
  return (
    <div className="pulse-bar">
      <div><span>{label}</span><b>{value}</b></div>
      <div className="pulse-track"><span className={`pulse-fill ${tone}`} style={{ width: `${width}%` }} /></div>
    </div>
  );
}

function kindLabel(kind: WorkItemKind): string {
  return kind === 'APPROVAL' ? 'Approval' : kind === 'EXCEPTION' ? 'Exception' : 'Task';
}

function priorityClass(priority: WorkItem['priority']): string {
  if (priority === 'URGENT') return 'status-bad';
  if (priority === 'HIGH') return 'status-warn';
  return 'status-info';
}

function ageLabel(minutes: number): string {
  if (minutes < 60) return `${minutes}m`;
  if (minutes < 1440) return `${Math.floor(minutes / 60)}h`;
  return `${Math.floor(minutes / 1440)}d`;
}

function dueLabel(value: string | null): string {
  if (!value) return 'No deadline';
  return new Intl.DateTimeFormat(undefined, {
    day: '2-digit',
    month: 'short',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(value));
}

function sourceLabel(item: WorkItem): string {
  if (!item.source) return `Work item ${shortId(item.id)}`;
  return `${item.source.type.replaceAll('_', ' ')} ${shortId(item.source.id ?? item.id)}`;
}

function shortId(id: string): string {
  return id.split('-').at(-1)?.slice(-8).toUpperCase() ?? id.slice(-8).toUpperCase();
}

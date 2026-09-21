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
import { BusinessKpiDashboard } from '../components/BusinessKpiDashboard';

type AssignmentFilter = 'ALL' | 'MINE' | 'UNASSIGNED';
type SavedView = { id: string; name: string; kind: '' | WorkItemKind; assignment: AssignmentFilter; overdueOnly: boolean; search: string };
type DashboardPreferences = { business: boolean; pulse: boolean; quickAccess: boolean; dataExchange: boolean; workQueue: boolean };
const defaultPreferences: DashboardPreferences = { business: true, pulse: true, quickAccess: true, dataExchange: true, workQueue: true };

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
  const preferenceKey = `qtfoods:dashboard:${session.user.id}:${contextKey}`;
  const viewKey = `qtfoods:work-views:${session.user.id}:${contextKey}`;
  const [preferences, setPreferences] = useState<DashboardPreferences>(() => readStored(preferenceKey, defaultPreferences));
  const [savedViews, setSavedViews] = useState<SavedView[]>(() => readStored(viewKey, []));
  const [viewName, setViewName] = useState('');
  const [savingView, setSavingView] = useState(false);
  const [personalising, setPersonalising] = useState(false);

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
    setPreferences(readStored(preferenceKey, defaultPreferences));
    setSavedViews(readStored(viewKey, []));
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
  const workspaceProfile = roleWorkspace(session.roles, session.allowed_screens);

  function clearFilters() {
    setKind('');
    setAssignment('ALL');
    setOverdueOnly(false);
    setSearchDraft('');
    setSearch('');
  }

  function applyView(view: SavedView) {
    setKind(view.kind); setAssignment(view.assignment); setOverdueOnly(view.overdueOnly);
    setSearchDraft(view.search); setSearch(view.search);
  }

  function saveView() {
    const name = viewName.trim();
    if (!name) return;
    const next = [{ id: crypto.randomUUID(), name, kind, assignment, overdueOnly, search }, ...savedViews].slice(0, 8);
    setSavedViews(next); localStorage.setItem(viewKey, JSON.stringify(next)); setViewName(''); setSavingView(false);
  }

  function removeView(id: string) {
    const next = savedViews.filter((view) => view.id !== id);
    setSavedViews(next); localStorage.setItem(viewKey, JSON.stringify(next));
  }

  function updatePreference(key: keyof DashboardPreferences) {
    const next = { ...preferences, [key]: !preferences[key] };
    setPreferences(next); localStorage.setItem(preferenceKey, JSON.stringify(next));
  }

  function exportQueue() {
    if (!queue?.data.length) return;
    const cells = [['Type', 'Title', 'Priority', 'Deadline', 'Owner', 'Status'], ...queue.data.map((item) => [item.kind, item.title, item.priority, item.due_at ?? '', item.assignee?.name ?? 'Unassigned', item.is_overdue ? 'OVERDUE' : item.status])];
    const csv = cells.map((row) => row.map((value) => `"${String(value).replaceAll('"', '""')}"`).join(',')).join('\r\n');
    const url = URL.createObjectURL(new Blob([csv], { type: 'text/csv;charset=utf-8' }));
    const anchor = document.createElement('a'); anchor.href = url; anchor.download = `qtfoods-work-queue-${new Date().toISOString().slice(0, 10)}.csv`; anchor.click(); URL.revokeObjectURL(url);
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

      <section className="role-workspace-banner" aria-label="Role-personalised workspace">
        <div><span>{workspaceProfile.eyebrow}</span><h2>{workspaceProfile.title}</h2><p>{workspaceProfile.detail}</p></div>
        <div className="role-workspace-actions">{workspaceProfile.actions.map((action) => <button className="secondary compact-button" type="button" key={action.code} onClick={() => { window.location.hash = action.code; }}>{action.label}<span>→</span></button>)}</div>
      </section>

      <div className="workspace-personalisation">
        <span>Workspace view</span>
        <button className="secondary compact-button" type="button" aria-expanded={personalising} onClick={() => setPersonalising((value) => !value)}>Personalise</button>
        {personalising && <div className="personalisation-popover" role="group" aria-label="Dashboard sections">
          {([['business', 'Business performance'], ['pulse', 'Workload pulse'], ['quickAccess', 'Quick access'], ['dataExchange', 'Data exchange'], ['workQueue', 'Priority work']] as const).map(([key, label]) => <label key={key}><input type="checkbox" checked={preferences[key]} onChange={() => updatePreference(key)} />{label}</label>)}
        </div>}
      </div>

      {preferences.business && <BusinessKpiDashboard allowedScreens={session.allowed_screens} />}

      <div className="kpi-grid">
        <button className="kpi" type="button" onClick={clearFilters}><span>Open work</span><b>{loading && !queue ? '—' : summary?.open_total ?? 0}</b><small>view complete queue →</small></button>
        <button className="kpi" type="button" onClick={() => setAssignment('MINE')}><span>Assigned to me</span><b>{loading && !queue ? '—' : summary?.assigned_to_me ?? 0}</b><small>filter owned actions →</small></button>
        <button className="kpi" type="button" onClick={() => setKind('APPROVAL')}><span>Pending approvals</span><b>{loading && !queue ? '—' : summary?.approvals ?? 0}</b><small>filter approvals →</small></button>
        <button className="kpi" type="button" onClick={() => setOverdueOnly(true)}><span>Overdue</span><b className={summary?.overdue ? 'text-bad' : ''}>{loading && !queue ? '—' : summary?.overdue ?? 0}</b><small>filter deadlines →</small></button>
      </div>

      {(preferences.pulse || preferences.quickAccess) && <div className={`executive-grid ${!preferences.pulse || !preferences.quickAccess ? 'single-panel' : ''}`}>
        {preferences.pulse &&
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
        </section>}

        {preferences.quickAccess &&
        <section className="panel quick-access" aria-labelledby="quick-access-title">
          <div className="panel-head"><div><h3 id="quick-access-title">Quick access</h3><span>Your most-used operational areas</span></div></div>
          <div className="quick-link-grid">
            {quickLinks.map((link) => (
              <button key={link.code} type="button" onClick={() => { window.location.hash = link.code; }}>
                <i aria-hidden="true">{link.icon}</i><span><b>{link.label}</b><small>{link.detail}</small></span><em aria-hidden="true">→</em>
              </button>
            ))}
          </div>
        </section>}
      </div>}

      {preferences.dataExchange && <section className="panel data-exchange" aria-labelledby="data-exchange-title">
        <div className="panel-head"><div><h3 id="data-exchange-title">Data exchange</h3><span>Permission-aware exports and governed import workspaces</span></div></div>
        <div className="data-exchange-grid">
          <button type="button" onClick={exportQueue} disabled={!queue?.data.length}><i>CSV</i><span><b>Export current work view</b><small>Uses the filters currently applied to your queue.</small></span><em>Download</em></button>
          {session.allowed_screens.includes('BI-REP') && <button type="button" onClick={() => { window.location.hash = 'BI-REP'; }}><i>BI</i><span><b>Controlled report exports</b><small>Create traceable CSV or PDF evidence from completed report runs.</small></span><em>Open</em></button>}
          {session.allowed_screens.includes('FIN-LEGACY') && <button type="button" onClick={() => { window.location.hash = 'FIN-LEGACY'; }}><i>IMP</i><span><b>Legacy finance import</b><small>Stage, validate and post balanced historical accounting batches.</small></span><em>Open</em></button>}
        </div>
      </section>}

      {preferences.workQueue &&
      <section className="panel work-queue-panel">
        <div className="panel-head">
          <div><h3>Priority work</h3><span>{summary?.exceptions ?? 0} open exceptions · {summary?.unassigned ?? 0} unassigned</span></div>
          <button className="secondary compact-button" type="button" onClick={() => void refresh()} disabled={loading}>Refresh</button>
        </div>

        <div className="saved-view-bar">
          <div><b>Saved views</b>{savedViews.length ? savedViews.map((view) => <span className="saved-view-chip" key={view.id}><button type="button" onClick={() => applyView(view)}>{view.name}</button><button type="button" aria-label={`Delete ${view.name} view`} onClick={() => removeView(view.id)}>×</button></span>) : <small>No personal views saved yet.</small>}</div>
          {savingView ? <div className="save-view-form"><input aria-label="Saved view name" autoFocus value={viewName} maxLength={40} placeholder="View name" onChange={(event) => setViewName(event.target.value)} /><button className="primary compact-button" type="button" disabled={!viewName.trim()} onClick={saveView}>Save</button><button className="secondary compact-button" type="button" onClick={() => setSavingView(false)}>Cancel</button></div> : <button className="secondary compact-button" type="button" onClick={() => setSavingView(true)}>Save current view</button>}
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
      </section>}
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

function readStored<T>(key: string, fallback: T): T {
  try {
    const value = localStorage.getItem(key);
    if (!value) return fallback;
    const parsed = JSON.parse(value) as T;
    return typeof parsed === 'object' && parsed && typeof fallback === 'object' && fallback
      ? { ...fallback, ...parsed }
      : parsed;
  }
  catch { return fallback; }
}

function roleWorkspace(roles: string[], allowedScreens: string[]) {
  const role = roles.join(' ').toLowerCase();
  const candidates = role.includes('finance') || role.includes('account')
    ? [['FIN-AR', 'Review receivables'], ['FIN-AP', 'Review payables'], ['BI-PROFIT', 'Profitability']]
    : role.includes('sales')
      ? [['CRM-ORDER', 'Sales orders'], ['DSP-LOAD', 'Dispatch'], ['FIN-AR', 'Collections']]
      : role.includes('production') || role.includes('plant')
        ? [['PLAN-SCH', 'Production schedule'], ['PRO-ORDER', 'Production orders'], ['QC-LAB', 'Quality results']]
        : role.includes('purchase') || role.includes('procure')
          ? [['PUR-REQ', 'Requisitions'], ['PUR-RFQ', 'RFQ comparison'], ['PUR-PO', 'Purchase orders']]
          : [['WRK-HOME', 'Priority work'], ['BI-REP', 'Controlled reports'], ['INV-STK', 'Stock overview']];
  const primary = roles[0]?.replaceAll('_', ' ').replaceAll('-', ' ') || 'ERP user';
  return {
    eyebrow: 'PERSONALISED FOR YOUR ROLE',
    title: `${primary.replace(/\b\w/g, (letter) => letter.toUpperCase())} workspace`,
    detail: 'Your shortcuts, live work and business indicators respect the selected company, plant and assigned permissions.',
    actions: candidates.filter(([code]) => allowedScreens.includes(code)).slice(0, 3).map(([code, label]) => ({ code, label })),
  };
}

import { useCallback, useEffect, useState } from 'react';
import { loadBusinessDashboard, type BusinessDashboard } from '../api/businessDashboard';
import { getAnalytics, saveAnalyticsTarget, type AnalyticsPayload } from '../api/experience';

export function BusinessKpiDashboard({ allowedScreens }: { allowedScreens: string[] }) {
  const [dashboard, setDashboard] = useState<BusinessDashboard | null>(null);
  const [loading, setLoading] = useState(true);
  const [period, setPeriod] = useState('30d');
  const [analytics, setAnalytics] = useState<AnalyticsPayload | null>(null);
  const [editingTarget, setEditingTarget] = useState<string | null>(null);
  const [targetDraft, setTargetDraft] = useState('');

  const refresh = useCallback(async () => {
    setLoading(true);
    try { setDashboard(await loadBusinessDashboard(allowedScreens)); }
    finally { setLoading(false); }
  }, [allowedScreens]);

  useEffect(() => { void refresh(); }, [refresh]);
  useEffect(() => { if (allowedScreens.includes('CRM-ORDER')) void getAnalytics(period).then(setAnalytics).catch(() => setAnalytics(null)); }, [allowedScreens, period]);
  if (!loading && !dashboard?.metrics.length) return null;

  return <section className="business-dashboard" aria-labelledby="business-dashboard-title">
    <div className="business-dashboard-head">
      <div><h2 id="business-dashboard-title">Business performance</h2><p>Live, permission-scoped measures with direct drill-through to source records.</p></div>
      <div><label className="analytics-period">Period<select aria-label="Analytics period" value={period} onChange={(event) => setPeriod(event.target.value)}><option value="7d">7 days</option><option value="30d">30 days</option><option value="90d">90 days</option><option value="365d">12 months</option></select></label><small>{dashboard ? `Updated ${time(dashboard.refreshed_at)}` : 'Loading live measures'}</small><button type="button" className="secondary compact-button" onClick={() => void refresh()} disabled={loading}>Refresh</button></div>
    </div>
    {analytics && <div className="analytics-comparison" aria-label="Period trend and targets">{analytics.metrics.map((metric) => <article key={metric.code}>
      <div><span>{metric.label} trend</span><b className={(metric.change_percent ?? 0) < 0 ? 'text-bad' : 'text-good'}>{metric.change_percent === null ? 'New' : `${metric.change_percent >= 0 ? '+' : ''}${metric.change_percent}%`}</b></div>
      <small>{formatMetric(metric.previous, metric.format)} previous period</small>
      <div className="analytics-target"><span>Target: {metric.target === null ? 'Not set' : formatMetric(metric.target, metric.format)}</span>{editingTarget === metric.code ? <form onSubmit={(event) => { event.preventDefault(); const value = Number(targetDraft); if (Number.isFinite(value) && value >= 0) void saveAnalyticsTarget(metric.code, value).then(() => getAnalytics(period).then(setAnalytics)); setEditingTarget(null); }}><input autoFocus aria-label={`${metric.label} target`} type="number" min="0" step="0.01" value={targetDraft} onChange={(event) => setTargetDraft(event.target.value)} /><button type="submit">Save</button></form> : <button type="button" onClick={() => { setEditingTarget(metric.code); setTargetDraft(String(metric.target ?? '')); }}>Set target</button>}</div>
    </article>)}</div>}
    <div className="business-kpi-grid" aria-busy={loading}>
      {loading && !dashboard ? Array.from({ length: 4 }, (_, index) => <div className="business-kpi skeleton-kpi" key={index} aria-hidden="true" />) : dashboard?.metrics.map((item) => (
        <button className={`business-kpi ${item.tone ? `tone-${item.tone}` : ''}`} type="button" key={item.key} onClick={() => { window.location.hash = item.screen; }}>
          <span>{item.label}</span><b>{item.value}</b><small>{item.note}</small><em>View source →</em>
        </button>
      ))}
    </div>
    {dashboard?.unavailable ? <p className="dashboard-partial">{dashboard.unavailable} permitted data source could not be refreshed. Available measures remain current.</p> : null}
  </section>;
}

function formatMetric(value: number, format: 'currency' | 'number') { return format === 'currency' ? new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(value) : new Intl.NumberFormat('en-IN').format(value); }

function time(value: string): string {
  return new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}

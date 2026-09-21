import { useCallback, useEffect, useState } from 'react';
import { loadBusinessDashboard, type BusinessDashboard } from '../api/businessDashboard';

export function BusinessKpiDashboard({ allowedScreens }: { allowedScreens: string[] }) {
  const [dashboard, setDashboard] = useState<BusinessDashboard | null>(null);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback(async () => {
    setLoading(true);
    try { setDashboard(await loadBusinessDashboard(allowedScreens)); }
    finally { setLoading(false); }
  }, [allowedScreens]);

  useEffect(() => { void refresh(); }, [refresh]);
  if (!loading && !dashboard?.metrics.length) return null;

  return <section className="business-dashboard" aria-labelledby="business-dashboard-title">
    <div className="business-dashboard-head">
      <div><h2 id="business-dashboard-title">Business performance</h2><p>Live, permission-scoped measures with direct drill-through to source records.</p></div>
      <div><small>{dashboard ? `Updated ${time(dashboard.refreshed_at)}` : 'Loading live measures'}</small><button type="button" className="secondary compact-button" onClick={() => void refresh()} disabled={loading}>Refresh</button></div>
    </div>
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

function time(value: string): string {
  return new Intl.DateTimeFormat(undefined, { hour: '2-digit', minute: '2-digit' }).format(new Date(value));
}

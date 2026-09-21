import { useCallback, useEffect, useState } from 'react';
import { listWorkItems, type WorkItem } from '../api/workQueue';

export function NotificationCentre({ onNavigate }: { onNavigate: (href: string) => void }) {
  const [open, setOpen] = useState(false);
  const [items, setItems] = useState<WorkItem[]>([]);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback(async () => {
    setLoading(true);
    try {
      const queue = await listWorkItems();
      setItems(queue.data.filter((item) => item.is_overdue || item.priority === 'URGENT' || item.priority === 'HIGH').slice(0, 8));
    } catch { setItems([]); }
    finally { setLoading(false); }
  }, []);

  useEffect(() => { void refresh(); }, [refresh]);
  const overdue = items.filter((item) => item.is_overdue).length;

  return <div className="notification-wrap">
    <button className="icon notification-button" type="button" aria-label={`${items.length} priority notifications`} aria-expanded={open} onClick={() => { setOpen((value) => !value); if (!open) void refresh(); }}>
      <span aria-hidden="true">♢</span>{items.length > 0 && <b>{items.length > 9 ? '9+' : items.length}</b>}
    </button>
    {open && <section className="notification-centre" aria-label="Notification and escalation centre">
      <div className="notification-head"><div><b>Priority centre</b><small>{overdue} overdue · {items.length - overdue} high priority</small></div><button type="button" onClick={() => void refresh()} disabled={loading}>Refresh</button></div>
      {loading && !items.length ? <div className="notification-empty">Checking live workflow records…</div> : items.length ? items.map((item) => <button type="button" className="notification-item" key={item.id} onClick={() => { if (item.target) onNavigate(item.target.href); setOpen(false); }}>
        <i className={item.is_overdue ? 'overdue' : ''}></i><span><b>{item.title}</b><small>{item.is_overdue ? 'Overdue' : item.priority.toLowerCase().replace(/^./, (letter) => letter.toUpperCase())} · {item.assignee?.name ?? 'Unassigned'}</small></span><em>→</em>
      </button>) : <div className="notification-empty"><b>All clear</b><span>No overdue or high-priority work is visible.</span></div>}
      <button className="notification-footer" type="button" onClick={() => { onNavigate('WRK-HOME'); setOpen(false); }}>Open complete work queue →</button>
    </section>}
  </div>;
}

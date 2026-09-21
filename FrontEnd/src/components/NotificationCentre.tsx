import { useCallback, useEffect, useState } from 'react';
import { dismissNotification, getNotifications, readNotification, saveNotificationPreferences, type NotificationItem, type NotificationPreferences } from '../api/experience';

const defaults: NotificationPreferences = { urgent: true, high: true, overdue: true, include_completed: true };

export function NotificationCentre({ onNavigate }: { onNavigate: (href: string) => void }) {
  const [open, setOpen] = useState(false);
  const [settingsOpen, setSettingsOpen] = useState(false);
  const [items, setItems] = useState<NotificationItem[]>([]);
  const [preferences, setPreferences] = useState(defaults);
  const [unread, setUnread] = useState(0);
  const [loading, setLoading] = useState(true);
  const refresh = useCallback(async () => { setLoading(true); try { const payload = await getNotifications(); setItems(payload.data); setUnread(payload.summary.unread); setPreferences(payload.preferences); } catch { setItems([]); setUnread(0); } finally { setLoading(false); } }, []);
  useEffect(() => { void refresh(); const timer = window.setInterval(() => void refresh(), 60_000); return () => window.clearInterval(timer); }, [refresh]);

  async function openItem(item: NotificationItem) { if (!item.read_at) { await readNotification(item.id); setUnread((value) => Math.max(0, value - 1)); } if (item.target) onNavigate(item.target.href); setOpen(false); }
  async function dismiss(item: NotificationItem) { await dismissNotification(item.id); setItems((current) => current.filter((candidate) => candidate.id !== item.id)); if (!item.read_at) setUnread((value) => Math.max(0, value - 1)); }
  async function changePreference(key: keyof NotificationPreferences) { const next = { ...preferences, [key]: !preferences[key] }; setPreferences(next); await saveNotificationPreferences(next); await refresh(); }

  return <div className="notification-wrap">
    <button className="icon notification-button" type="button" aria-label={`${unread} unread priority notifications`} aria-expanded={open} onClick={() => { setOpen((value) => !value); if (!open) void refresh(); }}><span aria-hidden="true">♢</span>{unread > 0 && <b>{unread > 9 ? '9+' : unread}</b>}</button>
    {open && <section className="notification-centre" aria-label="Notification and escalation centre">
      <div className="notification-head"><div><b>Priority centre</b><small>{unread} unread · {items.length} in history</small></div><div><button type="button" onClick={() => setSettingsOpen((value) => !value)}>Preferences</button><button type="button" onClick={() => void refresh()} disabled={loading}>Refresh</button></div></div>
      {settingsOpen && <div className="notification-preferences">{([['urgent', 'Urgent'], ['high', 'High priority'], ['overdue', 'Overdue'], ['include_completed', 'Completed history']] as const).map(([key, label]) => <label key={key}><input type="checkbox" checked={preferences[key]} onChange={() => void changePreference(key)} />{label}</label>)}</div>}
      {loading && !items.length ? <div className="notification-empty">Checking live workflow records…</div> : items.length ? items.map((item) => <div className={`notification-item ${item.read_at ? 'is-read' : ''}`} key={item.id}>
        <button type="button" onClick={() => void openItem(item)}><i className={item.is_overdue ? 'overdue' : ''}></i><span><b>{item.title}</b><small>{item.is_overdue ? 'Overdue' : item.priority.toLowerCase()} · {item.assignee_name ?? 'Unassigned'} · {item.status.toLowerCase()}</small></span><em>→</em></button>
        <button className="notification-dismiss" type="button" aria-label={`Dismiss ${item.title}`} onClick={() => void dismiss(item)}>×</button>
      </div>) : <div className="notification-empty"><b>All clear</b><span>No matching priority notifications.</span></div>}
      <button className="notification-footer" type="button" onClick={() => { onNavigate('WRK-HOME'); setOpen(false); }}>Open complete work queue →</button>
    </section>}
  </div>;
}

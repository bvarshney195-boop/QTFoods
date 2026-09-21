import { useEffect, useMemo, useRef, useState } from 'react';
import { listWorkItems, type WorkItem } from '../api/workQueue';
import { screenRegistry } from '../data/screenRegistry';
import { screenLabel } from '../utils/displayText';

type Props = {
  allowedScreens: Set<string>;
  open: boolean;
  onClose: () => void;
  onNavigate: (code: string) => void;
};

export function GlobalSearch({ allowedScreens, open, onClose, onNavigate }: Props) {
  const [query, setQuery] = useState('');
  const [records, setRecords] = useState<WorkItem[]>([]);
  const [loading, setLoading] = useState(false);
  const inputRef = useRef<HTMLInputElement>(null);

  const screens = useMemo(() => {
    const term = query.trim().toLowerCase();
    return screenRegistry
      .filter((screen) => allowedScreens.has(screen.code) && !screen.code.startsWith('ACC-'))
      .filter((screen) => !term || `${screen.title} ${screen.description} ${screen.code}`.toLowerCase().includes(term))
      .slice(0, 7);
  }, [allowedScreens, query]);

  useEffect(() => {
    if (!open) return;
    setQuery('');
    setRecords([]);
    const frame = requestAnimationFrame(() => inputRef.current?.focus());
    return () => cancelAnimationFrame(frame);
  }, [open]);

  useEffect(() => {
    if (!open || query.trim().length < 2) {
      setRecords([]);
      return;
    }
    const controller = new AbortController();
    const timer = window.setTimeout(async () => {
      setLoading(true);
      try {
        const result = await listWorkItems({ q: query.trim() });
        if (!controller.signal.aborted) setRecords(result.data.slice(0, 6));
      } catch {
        if (!controller.signal.aborted) setRecords([]);
      } finally {
        if (!controller.signal.aborted) setLoading(false);
      }
    }, 250);
    return () => { controller.abort(); window.clearTimeout(timer); };
  }, [open, query]);

  useEffect(() => {
    if (!open) return;
    const close = (event: KeyboardEvent) => { if (event.key === 'Escape') onClose(); };
    document.addEventListener('keydown', close);
    return () => document.removeEventListener('keydown', close);
  }, [onClose, open]);

  if (!open) return null;
  const navigate = (code: string) => { onNavigate(code); onClose(); };

  return <div className="command-scrim" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}>
    <section className="command-centre" role="dialog" aria-modal="true" aria-labelledby="global-search-title">
      <div className="command-search">
        <span aria-hidden="true">⌕</span>
        <input ref={inputRef} value={query} onChange={(event) => setQuery(event.target.value)} placeholder="Search pages, tasks, approvals or record IDs" aria-label="Search across Q & T Foods" />
        <kbd>Esc</kbd>
      </div>
      <div className="command-results">
        <div className="command-section"><b id="global-search-title">Pages</b><small>{screens.length} accessible result{screens.length === 1 ? '' : 's'}</small></div>
        {screens.map((screen) => <button type="button" key={screen.code} onClick={() => navigate(screen.code)}>
          <i>{screen.code.split('-')[0]}</i><span><b>{screenLabel(screen.code, screen.title)}</b><small>{screen.description}</small></span><em>Open →</em>
        </button>)}
        {query.trim().length >= 2 && <><div className="command-section"><b>Live work records</b><small>{loading ? 'Searching…' : `${records.length} result${records.length === 1 ? '' : 's'}`}</small></div>
          {records.map((item) => <button type="button" key={item.id} onClick={() => item.target && navigate(item.target.screen_code)}>
            <i className={item.is_overdue ? 'alert' : ''}>{item.kind.slice(0, 2)}</i><span><b>{item.title}</b><small>{item.description ?? item.source?.type ?? 'Workflow record'} · {item.priority}</small></span><em>{item.is_overdue ? 'Overdue' : 'Open'} →</em>
          </button>)}
        </>}
        {!screens.length && !records.length && !loading && <div className="command-empty">No accessible page or work record matches “{query}”.</div>}
      </div>
    </section>
  </div>;
}

import { Suspense, useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { screenRegistry } from '../data/screenRegistry';
import type { ErpSession } from '../types/session';
import { ErpSessionContext } from './ErpSessionContext';
import { pageMap } from './lazyPageMap';
import { AccountSecurityPanel } from '../components/AccountSecurityPanel';
import { useEditorActionReveal } from './useEditorActionReveal';
import { useKeyboardScrollableRegions } from './useKeyboardScrollableRegions';
import { roleLabel, screenLabel } from '../utils/displayText';

const navigationSections = [
  { key: 'Foundation / Admin', label: 'Home & Administration' },
  { key: 'Master / Procurement / Stock', label: 'Purchasing & Inventory' },
  { key: 'Manufacturing / Quality', label: 'Production & Quality' },
  { key: 'Sales / Dispatch', label: 'Sales & Delivery' },
  { key: 'Finance / Support', label: 'Finance & People' },
  { key: 'Scale', label: 'Multi-Plant & Planning' },
  { key: 'Finance Supplement', label: 'Finance Tools' },
];

type AppShellProps = {
  session: ErpSession;
  onChooseContext: () => void;
  onLogout: () => Promise<void> | void;
};

export default function AppShell({ session, onChooseContext, onLogout }: AppShellProps) {
  const allowedKey = session.allowed_screens.join('|');
  const allowedScreens = useMemo(() => new Set(session.allowed_screens), [allowedKey]);
  const navigation = useMemo(
    () => screenRegistry.filter((screen) => allowedScreens.has(screen.code) && !screen.code.startsWith('ACC-')),
    [allowedScreens]
  );
  const defaultCode = allowedScreens.has('WRK-HOME') ? 'WRK-HOME' : navigation[0]?.code;
  const defaultArea = navigation.find((screen) => screen.code === defaultCode)?.area;
  const [screenCode, setScreenCode] = useState(defaultCode ?? '');
  const [search, setSearch] = useState('');
  const [favourites, setFavourites] = useState<string[]>(() => readCodes('qtfoods:favourite-screens'));
  const [recent, setRecent] = useState<string[]>(() => readCodes('qtfoods:recent-screens'));
  const [expandedAreas, setExpandedAreas] = useState<Set<string>>(
    () => new Set(defaultArea ? [defaultArea] : [])
  );
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [securityOpen, setSecurityOpen] = useState(false);
  const [compactNavigation, setCompactNavigation] = useState(() => window.matchMedia('(max-width: 820px)').matches);
  const menuButtonRef = useRef<HTMLButtonElement>(null);
  const sidebarSearchRef = useRef<HTMLInputElement>(null);
  const securityButtonRef = useRef<HTMLButtonElement>(null);
  const mainContentRef = useRef<HTMLElement>(null);

  useKeyboardScrollableRegions(mainContentRef);
  useEditorActionReveal(mainContentRef);

  const closeSidebar = useCallback(() => {
    setSidebarOpen(false);
    window.requestAnimationFrame(() => menuButtonRef.current?.focus());
  }, []);

  const closeSecurity = useCallback(() => {
    setSecurityOpen(false);
    window.requestAnimationFrame(() => securityButtonRef.current?.focus());
  }, []);

  useEffect(() => {
    const media = window.matchMedia('(max-width: 820px)');
    const update = (matches: boolean) => {
      setCompactNavigation(matches);
      if (!matches) setSidebarOpen(false);
    };
    const handleChange = (event: MediaQueryListEvent) => update(event.matches);

    update(media.matches);
    media.addEventListener('change', handleChange);
    return () => media.removeEventListener('change', handleChange);
  }, []);

  useEffect(() => {
    if (!compactNavigation || !sidebarOpen) return;

    const frame = window.requestAnimationFrame(() => sidebarSearchRef.current?.focus());
    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key !== 'Escape') return;
      event.preventDefault();
      closeSidebar();
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => {
      window.cancelAnimationFrame(frame);
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [closeSidebar, compactNavigation, sidebarOpen]);

  useEffect(() => {
    const handleShortcut = (event: KeyboardEvent) => {
      if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        if (compactNavigation) setSidebarOpen(true);
        window.requestAnimationFrame(() => sidebarSearchRef.current?.focus());
      }
    };
    document.addEventListener('keydown', handleShortcut);
    return () => document.removeEventListener('keydown', handleShortcut);
  }, [compactNavigation]);

  useEffect(() => {
    const syncHash = () => {
      const route = window.location.hash.replace(/^#/, '');
      const requested = route.split('?', 1)[0];
      const permitted = Boolean(requested && requested in pageMap && allowedScreens.has(requested));
      const resolved = permitted ? requested : defaultCode;

      setScreenCode(resolved ?? '');
      setSidebarOpen(false);

      if (!permitted && resolved) {
        window.history.replaceState(null, '', `#${resolved}`);
      }
    };

    syncHash();
    window.addEventListener('hashchange', syncHash);
    return () => window.removeEventListener('hashchange', syncHash);
  }, [allowedScreens, defaultCode]);

  const current = navigation.find((screen) => screen.code === screenCode);
  const CurrentPage = current ? pageMap[current.code as keyof typeof pageMap] : null;

  useEffect(() => {
    if (!current?.area) return;
    const area = current.area;
    setExpandedAreas((areas) => areas.has(area) ? areas : new Set([area]));
  }, [current?.area]);

  const filtered = useMemo(() => {
    const query = search.trim().toLowerCase();
    return navigation.filter((screen) =>
      !query || `${screenLabel(screen.code, screen.title)} ${screen.description}`.toLowerCase().includes(query)
    );
  }, [navigation, search]);

  function go(code: string) {
    if (!allowedScreens.has(code)) return;
    const nextRecent = [code, ...recent.filter((item) => item !== code)].slice(0, 4);
    setRecent(nextRecent);
    localStorage.setItem('qtfoods:recent-screens', JSON.stringify(nextRecent));
    window.location.hash = code;
    setSidebarOpen(false);
  }

  function toggleFavourite() {
    if (!current) return;
    const next = favourites.includes(current.code)
      ? favourites.filter((code) => code !== current.code)
      : [current.code, ...favourites].slice(0, 6);
    setFavourites(next);
    localStorage.setItem('qtfoods:favourite-screens', JSON.stringify(next));
  }

  function toggleArea(area: string) {
    setExpandedAreas((areas) => {
      if (areas.has(area)) return new Set();
      return new Set([area]);
    });
  }

  const context = session.selected_context;
  const primaryRole = session.roles[0] ? roleLabel(session.roles[0]) : 'Assigned user';

  return (
    <div className="app-shell">
      <a
        className="skip-link"
        href="#erp-main-content"
        onClick={(event) => {
          event.preventDefault();
          mainContentRef.current?.focus();
        }}
      >
        Skip to main content
      </a>
      <aside
        id="erp-sidebar"
        className={`sidebar ${sidebarOpen ? 'open' : ''}`}
        aria-hidden={compactNavigation && !sidebarOpen ? true : undefined}
        inert={compactNavigation && !sidebarOpen ? true : undefined}
      >
        <div className="side-brand">
          <span className="brand-mark">Q&T</span>
          <div><b>Q & T FOODS LTD</b><small>Business workspace</small></div>
        </div>
        <div className="side-search">
          <input ref={sidebarSearchRef} value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Search menu" aria-label="Search menu" />
          <kbd>Ctrl K</kbd>
        </div>
        <nav aria-label="Main menu">
          {!search.trim() && (favourites.length > 0 || recent.length > 0) && (
            <section className="smart-navigation" aria-label="Pinned and recent pages">
              {favourites.length > 0 && <div><b>Pinned</b>{favourites.filter((code) => allowedScreens.has(code)).map((code) => {
                const item = navigation.find((screen) => screen.code === code);
                return item ? <button type="button" key={code} onClick={() => go(code)}><span>★</span>{screenLabel(code, item.title)}</button> : null;
              })}</div>}
              {recent.length > 0 && <div><b>Recent</b>{recent.filter((code) => allowedScreens.has(code) && !favourites.includes(code)).slice(0, 3).map((code) => {
                const item = navigation.find((screen) => screen.code === code);
                return item ? <button type="button" key={code} onClick={() => go(code)}><span>↗</span>{screenLabel(code, item.title)}</button> : null;
              })}</div>}
            </section>
          )}
          {navigationSections.map(({ key, label }) => {
            const items = filtered.filter((screen) => screen.area === key);
            if (!items.length) return null;
            const expanded = Boolean(search.trim()) || expandedAreas.has(key);
            return (
              <section key={key} className="nav-group">
                <button className="nav-group-toggle" type="button" aria-expanded={expanded} onClick={() => toggleArea(key)}>
                  <span>{label}</span><small>{items.length}</small><i aria-hidden="true">{expanded ? '−' : '+'}</i>
                </button>
                {expanded && <div className="nav-group-items">{items.map((item) => (
                  <button key={item.code} data-screen-code={item.code} aria-label={screenLabel(item.code, item.title)} className={item.code === current?.code ? 'active' : ''} aria-current={item.code === current?.code ? 'page' : undefined} onClick={() => go(item.code)}>
                    <span>{screenLabel(item.code, item.title)}</span>
                  </button>
                ))}</div>}
              </section>
            );
          })}
          {!filtered.length && <div className="nav-empty">No menu item matches your search.</div>}
        </nav>
        <div className="side-user">
          <span className="avatar">{initials(session.user.name)}</span>
          <div className="side-user-copy"><b>{session.user.name}</b><small>{primaryRole}</small></div>
          <button className="side-logout" type="button" aria-label="Sign out" title="Sign out" onClick={() => void onLogout()}>↪</button>
        </div>
      </aside>

      {sidebarOpen && <button className="sidebar-scrim" type="button" aria-label="Close navigation" onClick={() => closeSidebar()} />}

      <section className="main">
        <header className="topbar">
          <button
            ref={menuButtonRef}
            className="icon mobile-menu"
            type="button"
            aria-label="Toggle navigation"
            aria-controls="erp-sidebar"
            aria-expanded={sidebarOpen}
            onClick={() => sidebarOpen ? closeSidebar() : setSidebarOpen(true)}
          >☰</button>
          <button className="context-button" type="button" aria-label="Change company or plant" onClick={onChooseContext}>
            <i></i>
            <span><b>{context?.company_name}</b><small>{context?.plant_name ?? 'All plants'} · {primaryRole}</small></span>
            ⌄
          </button>
          <div className="spacer"></div>
          {current && <button className={`icon favourite-button ${favourites.includes(current.code) ? 'is-favourite' : ''}`} type="button" aria-label={favourites.includes(current.code) ? 'Remove current page from favourites' : 'Add current page to favourites'} title="Pin page" onClick={toggleFavourite}>★</button>}
          <button ref={securityButtonRef} className="security-button" type="button" aria-label="Account security" aria-haspopup="dialog" aria-controls="account-security-dialog" aria-expanded={securityOpen} onClick={() => setSecurityOpen(true)}><span>My account</span><b>{session.security?.mfa_enabled ? '2-step on' : '2-step off'}</b></button>
          {allowedScreens.has('ADM-HELP') && <button className="icon" type="button" aria-label="Open help" title="Help" onClick={() => go('ADM-HELP')}>?</button>}
        </header>

        <main ref={mainContentRef} id="erp-main-content" className="content" tabIndex={-1}>
          <ErpSessionContext.Provider value={session}>
            <Suspense fallback={<section className="panel page-loading"><span></span><b>Opening workspace…</b></section>}>
              {CurrentPage ? <CurrentPage /> : (
                <section className="panel empty-state">No ERP screens are assigned to this role in the selected context.</section>
              )}
            </Suspense>
          </ErpSessionContext.Provider>
        </main>
      </section>
      {securityOpen && <AccountSecurityPanel session={session} onClose={closeSecurity} />}
    </div>
  );
}

function initials(name: string): string {
  return name.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
}

function readCodes(key: string): string[] {
  try {
    const value = JSON.parse(localStorage.getItem(key) ?? '[]');
    return Array.isArray(value) ? value.filter((item): item is string => typeof item === 'string') : [];
  } catch {
    return [];
  }
}

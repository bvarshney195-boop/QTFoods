import type { ErpSession } from '../types/session';
import { roleLabel } from '../utils/displayText';

type ContextProps = {
  session?: ErpSession;
  onSelect?: (companyId: string, plantId: string | null) => Promise<void> | void;
  onCancel?: () => void;
  onLogout?: () => Promise<void> | void;
  busy?: boolean;
  error?: string | null;
};

export default function ACC_CTX({ session, onSelect, onCancel, onLogout, busy = false, error }: ContextProps = {}) {
  if (!session) return null;

  return (
    <div className="context-flow">
      <header className="context-flow-header">
        <div className="brand-lockup dark"><span className="brand-mark">Q&T</span><div><b>Q & T FOODS LTD</b><small>Business workspace</small></div></div>
        <button className="secondary" type="button" onClick={() => void onLogout?.()} disabled={busy}>Sign out</button>
      </header>

      <main className="context-flow-main">
        <div className="eyebrow">CHOOSE YOUR WORKSPACE</div>
        <h1>Choose where you are working</h1>
        <p>You will only see the work assigned to your role for the company and plant you choose.</p>

        <div className="session-summary">
          <span className="avatar">{initials(session.user.name)}</span>
          <div><b>{session.user.name}</b><small>{session.roles.map(roleLabel).join(' · ')}</small></div>
        </div>

        {error && <div className="form-error" role="alert"><span>{error}</span></div>}

        <div className="context-grid context-options">
          {session.contexts.map((context) => {
            const selected = context.company_id === session.selected_context?.company_id
              && context.plant_id === session.selected_context?.plant_id;
            return (
              <button
                type="button"
                className={`context-card ${selected ? 'selected' : ''}`}
                key={`${context.company_id}:${context.plant_id ?? 'all'}`}
                onClick={() => void onSelect?.(context.company_id, context.plant_id)}
                disabled={busy}
              >
                <span className="context-status">{selected ? 'Current' : 'Available'}</span>
                <b>{context.plant_name ?? 'All plants'}</b>
                <span>{context.company_name}</span>
                <small>{busy ? 'Please wait…' : 'Open workspace →'}</small>
              </button>
            );
          })}
        </div>

        {!session.contexts.length && <div className="empty-state">No active company or plant assignment was found. Contact an ERP administrator.</div>}

        {onCancel && <button type="button" className="secondary context-cancel" onClick={onCancel} disabled={busy}>Return to workspace</button>}
      </main>
    </div>
  );
}

function initials(name: string): string {
  return name.split(/\s+/).slice(0, 2).map((part) => part[0]).join('').toUpperCase();
}

import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import { isApiError } from '../api/client';
import {
  beginMfaSetup,
  changePassword,
  confirmMfa,
  disableMfa,
  listDeviceSessions,
  regenerateRecoveryCodes,
  requestEmailVerification,
  revokeDeviceSession,
  revokeOtherDeviceSessions,
  type DeviceSession,
  type MfaSetup,
} from '../api/identity';
import type { ErpSession } from '../types/session';
import { StatusBadge } from './StatusBadge';

export function AccountSecurityPanel({ session, onClose }: { session: ErpSession; onClose: () => void }) {
  const [devices, setDevices] = useState<DeviceSession[]>([]);
  const [loadingDevices, setLoadingDevices] = useState(true);
  const [busy, setBusy] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [mfaPassword, setMfaPassword] = useState('');
  const [mfaCode, setMfaCode] = useState('');
  const [setup, setSetup] = useState<MfaSetup | null>(null);
  const [recoveryCodes, setRecoveryCodes] = useState<string[]>([]);
  const [showSessionHistory, setShowSessionHistory] = useState(false);
  const [historyPage, setHistoryPage] = useState(1);
  const panelRef = useRef<HTMLElement>(null);
  const closeButtonRef = useRef<HTMLButtonElement>(null);

  const refreshDevices = useCallback(async () => {
    setLoadingDevices(true);
    try {
      setDevices((await listDeviceSessions()).data);
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to load device sessions.'));
    } finally {
      setLoadingDevices(false);
    }
  }, []);

  useEffect(() => { void refreshDevices(); }, [refreshDevices]);

  useEffect(() => {
    const previousOverflow = document.body.style.overflow;
    document.body.style.overflow = 'hidden';
    const frame = window.requestAnimationFrame(() => closeButtonRef.current?.focus());

    const handleKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        event.preventDefault();
        onClose();
        return;
      }
      if (event.key !== 'Tab') return;

      const panel = panelRef.current;
      if (!panel) return;
      const focusable = Array.from(panel.querySelectorAll<HTMLElement>(
        'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])'
      )).filter((element) => element.getClientRects().length > 0);
      if (!focusable.length) {
        event.preventDefault();
        panel.focus();
        return;
      }

      const first = focusable[0];
      const last = focusable[focusable.length - 1];
      const active = document.activeElement;
      if (event.shiftKey && (active === first || !panel.contains(active))) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && (active === last || !panel.contains(active))) {
        event.preventDefault();
        first.focus();
      }
    };

    document.addEventListener('keydown', handleKeyDown);
    return () => {
      window.cancelAnimationFrame(frame);
      document.body.style.overflow = previousOverflow;
      document.removeEventListener('keydown', handleKeyDown);
    };
  }, [onClose]);

  async function submitPassword(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setBusy('password'); clearFeedback();
    try {
      const result = await changePassword(currentPassword, newPassword, confirmation);
      setSuccess(`Password changed. ${result.other_sessions_revoked} other device session(s) revoked.`);
      setCurrentPassword(''); setNewPassword(''); setConfirmation('');
      window.dispatchEvent(new Event('erp:session-refresh'));
      await refreshDevices();
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to change the password.'));
    } finally {
      setBusy(null);
    }
  }

  async function startMfa() {
    setBusy('mfa'); clearFeedback(); setRecoveryCodes([]);
    try {
      setSetup(await beginMfaSetup(mfaPassword));
      setSuccess('Authenticator secret created. Verify one code to finish enabling MFA.');
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to start MFA setup.'));
    } finally {
      setBusy(null);
    }
  }

  async function finishMfa() {
    setBusy('mfa'); clearFeedback();
    try {
      const result = await confirmMfa(mfaCode);
      setRecoveryCodes(result.recovery_codes);
      setSetup(null); setMfaCode(''); setMfaPassword('');
      setSuccess('MFA enabled. Save every recovery code now; they are shown only once.');
      window.dispatchEvent(new Event('erp:session-refresh'));
      await refreshDevices();
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to confirm MFA.'));
    } finally {
      setBusy(null);
    }
  }

  async function turnOffMfa() {
    setBusy('mfa'); clearFeedback();
    try {
      await disableMfa(mfaPassword, mfaCode);
      setMfaPassword(''); setMfaCode(''); setRecoveryCodes([]);
      setSuccess('MFA disabled. Other device sessions were revoked.');
      window.dispatchEvent(new Event('erp:session-refresh'));
      await refreshDevices();
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to disable MFA.'));
    } finally {
      setBusy(null);
    }
  }

  async function rotateRecoveryCodes() {
    setBusy('mfa'); clearFeedback();
    try {
      const result = await regenerateRecoveryCodes(mfaPassword, mfaCode);
      setRecoveryCodes(result.recovery_codes);
      setMfaPassword(''); setMfaCode('');
      setSuccess('Earlier recovery codes were invalidated. Save this replacement set now.');
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to replace recovery codes.'));
    } finally {
      setBusy(null);
    }
  }

  async function sendVerification() {
    setBusy('verification'); clearFeedback();
    try {
      const result = await requestEmailVerification(session.user.email);
      setSuccess(result.message);
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to send a verification link.'));
    } finally {
      setBusy(null);
    }
  }

  async function revoke(device: DeviceSession) {
    const name = deviceName(device.user_agent);
    if (!window.confirm(`${device.current ? 'Sign out this device' : 'Revoke ' + name}?\n\nLast seen: ${new Date(device.last_seen_at).toLocaleString()}\n\nChoose Cancel to keep the session active.`)) return;
    setBusy(device.id); clearFeedback();
    try {
      const result = await revokeDeviceSession(device.id);
      if (result.current) {
        window.dispatchEvent(new Event('erp:session-expired'));
        return;
      }
      setSuccess('Device session revoked.');
      await refreshDevices();
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to revoke this device session.'));
    } finally {
      setBusy(null);
    }
  }

  async function revokeOthers() {
    setBusy('other-devices'); clearFeedback();
    try {
      const result = await revokeOtherDeviceSessions();
      setSuccess(`${result.revoked_count} other device session(s) revoked.`);
      await refreshDevices();
    } catch (caught) {
      setError(apiMessage(caught, 'Unable to revoke other device sessions.'));
    } finally {
      setBusy(null);
    }
  }

  function clearFeedback() { setError(null); setSuccess(null); }

  return (
    <div className="security-overlay" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onClose(); }}>
      <section ref={panelRef} id="account-security-dialog" className="security-panel" role="dialog" aria-modal="true" aria-labelledby="security-title" aria-describedby="security-account-email" tabIndex={-1}>
        <header><div><div className="eyebrow">ACCOUNT SECURITY</div><h2 id="security-title">Identity & devices</h2><p id="security-account-email">{session.user.email}</p></div><button ref={closeButtonRef} className="icon" type="button" aria-label="Close account security" onClick={onClose}>×</button></header>
        <div className="security-body">
          {error && <div className="form-error" role="alert"><span>{error}</span></div>}
          {success && <div className="form-success" role="status"><span></span>{success}</div>}

          <section className="security-section">
            <div className="subsection-head"><div><b>Email identity</b><small>Required before password authentication</small></div><StatusBadge status={session.security?.email_verified ? 'VERIFIED' : 'UNVERIFIED'} /></div>
            {!session.security?.email_verified && <button className="secondary" type="button" disabled={busy === 'verification'} onClick={() => void sendVerification()}>Send verification link</button>}
          </section>

          <section className="security-section">
            <div className="subsection-head"><div><b>Multi-factor authentication</b><small>TOTP authenticator with one-time recovery codes</small></div><StatusBadge status={session.security?.mfa_enabled ? 'ENABLED' : 'DISABLED'} /></div>
            {!session.security?.mfa_enabled && !setup && <div className="security-inline-form"><label>Current password<input type="password" value={mfaPassword} onChange={(event) => setMfaPassword(event.target.value)} autoComplete="current-password" /></label><button className="secondary" type="button" disabled={busy === 'mfa' || !mfaPassword} onClick={() => void startMfa()}>Set up MFA</button></div>}
            {setup && <div className="mfa-setup"><p>Add this account to a TOTP authenticator using the URI or manual secret.</p><a href={setup.otpauth_uri}>Open authenticator URI</a><code>{setup.secret}</code><div className="security-inline-form"><label>Six-digit code<input aria-label="MFA setup code" inputMode="numeric" autoComplete="one-time-code" value={mfaCode} onChange={(event) => setMfaCode(event.target.value)} /></label><button className="primary" type="button" disabled={busy === 'mfa' || !mfaCode} onClick={() => void finishMfa()}>Enable MFA</button></div></div>}
            {session.security?.mfa_enabled && <div className="security-inline-form security-mfa-actions"><label>Current password<input type="password" value={mfaPassword} onChange={(event) => setMfaPassword(event.target.value)} autoComplete="current-password" /></label><label>Authenticator code<input inputMode="numeric" value={mfaCode} onChange={(event) => setMfaCode(event.target.value)} autoComplete="one-time-code" /></label><div><button className="secondary" type="button" disabled={busy === 'mfa' || !mfaPassword || !mfaCode} onClick={() => void rotateRecoveryCodes()}>Replace recovery codes</button><button className="danger-button" type="button" disabled={busy === 'mfa' || !mfaPassword || !mfaCode} onClick={() => void turnOffMfa()}>Disable MFA</button></div></div>}
            {recoveryCodes.length > 0 && <div className="recovery-codes" aria-label="One-time MFA recovery codes">{recoveryCodes.map((recoveryCode) => <code key={recoveryCode}>{recoveryCode}</code>)}</div>}
          </section>

          <section className="security-section">
            <div className="subsection-head"><div><b>Change password</b><small>Keeps this device and revokes all others</small></div></div>
            <form className="form-grid security-password" onSubmit={submitPassword}>
              <label className="full">Current password<input type="password" value={currentPassword} onChange={(event) => setCurrentPassword(event.target.value)} autoComplete="current-password" required /></label>
              <label>New password<input type="password" value={newPassword} onChange={(event) => setNewPassword(event.target.value)} autoComplete="new-password" minLength={12} required /></label>
              <label>Confirm password<input type="password" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} autoComplete="new-password" minLength={12} required /></label>
              <button className="primary full" type="submit" disabled={busy === 'password'}>{busy === 'password' ? 'Changing…' : 'Change password'}</button>
            </form>
          </section>

          <section className="security-section">
            <div className="subsection-head"><div><b>Device sessions</b><small>Active devices are shown first. Technical session identifiers stay hidden.</small></div><button className="secondary compact-button" type="button" onClick={() => void revokeOthers()} disabled={busy === 'other-devices'}>Revoke others</button></div>
            <div className="workspace-tabs" role="tablist" aria-label="Device session view">
              <button type="button" role="tab" aria-selected={!showSessionHistory} className={!showSessionHistory ? 'active' : ''} onClick={() => { setShowSessionHistory(false); setHistoryPage(1); }}>Active devices</button>
              <button type="button" role="tab" aria-selected={showSessionHistory} className={showSessionHistory ? 'active' : ''} onClick={() => { setShowSessionHistory(true); setHistoryPage(1); }}>History</button>
            </div>
            {loadingDevices && <div className="empty-state">Loading device sessions…</div>}
            {!loadingDevices && (() => {
              const active = devices.filter((device) => device.status === 'ACTIVE');
              const history = devices.filter((device) => device.status !== 'ACTIVE');
              const pageSize = 5;
              const pages = Math.max(1, Math.ceil(history.length / pageSize));
              const visible = showSessionHistory ? history.slice((historyPage - 1) * pageSize, historyPage * pageSize) : active;
              return <>
                {visible.length === 0 ? <div className="empty-state compact">{showSessionHistory ? 'No expired or revoked device sessions.' : 'No active device session records.'}</div> : visible.map((device) => <div className="device-row" key={device.id}>
                  <div><b>{deviceName(device.user_agent)}{device.current ? ' · This device' : ''}</b><small>{device.ip_address ?? 'Unknown IP'} · Last seen {new Date(device.last_seen_at).toLocaleString()}</small></div>
                  <div><StatusBadge status={device.status} />{device.status === 'ACTIVE' && <button className={device.current ? 'danger-button' : 'secondary'} type="button" disabled={busy === device.id} onClick={() => void revoke(device)}>{device.current ? 'Sign out' : 'Revoke'}</button>}</div>
                </div>)}
                {showSessionHistory && pages > 1 ? <div className="pagination"><button type="button" disabled={historyPage <= 1} onClick={() => setHistoryPage((page) => page - 1)}>Previous</button><span>Page {historyPage} of {pages}</span><button type="button" disabled={historyPage >= pages} onClick={() => setHistoryPage((page) => page + 1)}>Next</button></div> : null}
              </>;
            })()}
          </section>
        </div>
      </section>
    </div>
  );
}

function apiMessage(error: unknown, fallback: string): string {
  return isApiError(error) ? error.message : fallback;
}

function deviceName(agent: string | null): string {
  if (!agent) return 'Unknown browser';
  if (/Edg\//.test(agent)) return 'Microsoft Edge';
  if (/Chrome\//.test(agent)) return 'Google Chrome';
  if (/Firefox\//.test(agent)) return 'Mozilla Firefox';
  if (/Safari\//.test(agent)) return 'Safari';
  return agent.length > 52 ? `${agent.slice(0, 49)}…` : agent;
}

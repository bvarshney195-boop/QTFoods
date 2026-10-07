import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import QRCode from 'qrcode';
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
import { formatZonedDateTime } from '../utils/dateTime';

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
  const [setupQr, setSetupQr] = useState('');
  const [deviceTab, setDeviceTab] = useState<'active' | 'history'>('active');
  const [devicePage, setDevicePage] = useState(1);
  const [pendingRevoke, setPendingRevoke] = useState<DeviceSession | null>(null);
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
    let live = true;
    if (!setup?.otpauth_uri) {
      setSetupQr('');
      return () => { live = false; };
    }
    void QRCode.toDataURL(setup.otpauth_uri, { width: 220, margin: 1, errorCorrectionLevel: 'M' })
      .then((value) => { if (live) setSetupQr(value); })
      .catch(() => { if (live) setSetupQr(''); });
    return () => { live = false; };
  }, [setup?.otpauth_uri]);

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
    setPendingRevoke(null);
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

  const visibleDevices = useMemo(() => devices.filter((device) => (
    deviceTab === 'active' ? device.status === 'ACTIVE' : device.status !== 'ACTIVE'
  )), [deviceTab, devices]);
  const pageSize = 5;
  const pageCount = Math.max(1, Math.ceil(visibleDevices.length / pageSize));
  const pageDevices = visibleDevices.slice((devicePage - 1) * pageSize, devicePage * pageSize);

  useEffect(() => {
    setDevicePage(1);
  }, [deviceTab]);

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
            <div className="subsection-head"><div><b>Multi-factor authentication</b><small>{session.security?.mfa_required ? 'Required by account policy; TOTP authenticator with one-time recovery codes' : 'TOTP authenticator with one-time recovery codes'}</small></div><StatusBadge status={session.security?.mfa_enabled ? 'ENABLED' : session.security?.mfa_required ? 'REQUIRED' : 'DISABLED'} /></div>
            {!session.security?.mfa_enabled && !setup && <div className="security-inline-form"><label>Current password<input type="password" value={mfaPassword} onChange={(event) => setMfaPassword(event.target.value)} autoComplete="current-password" /></label><button className="secondary" type="button" disabled={busy === 'mfa' || !mfaPassword} onClick={() => void startMfa()}>Set up MFA</button></div>}
            {setup && <div className="mfa-setup"><p>Scan this QR code with Google Authenticator or another TOTP app, then enter its six-digit code.</p>{setupQr ? <img className="mfa-qr" src={setupQr} alt="Authenticator setup QR code" /> : <div className="skeleton-block" role="status">Preparing QR code…</div>}<details><summary>Cannot scan the QR code?</summary><p>Enter this one-time setup key manually. Do not share it.</p><code>{setup.secret}</code><a href={setup.otpauth_uri}>Open authenticator link</a></details><div className="security-inline-form"><label>Six-digit code<input aria-label="MFA setup code" inputMode="numeric" pattern="[0-9]{6}" maxLength={6} autoComplete="one-time-code" value={mfaCode} onChange={(event) => setMfaCode(event.target.value.replace(/\D/g, '').slice(0, 6))} /></label><button className="primary" type="button" disabled={busy === 'mfa' || mfaCode.length !== 6} onClick={() => void finishMfa()}>Enable MFA</button></div></div>}
            {session.security?.mfa_enabled && <div className="security-inline-form security-mfa-actions"><label>Current password<input type="password" value={mfaPassword} onChange={(event) => setMfaPassword(event.target.value)} autoComplete="current-password" /></label><label>Authenticator code<input inputMode="numeric" value={mfaCode} onChange={(event) => setMfaCode(event.target.value)} autoComplete="one-time-code" /></label><div><button className="secondary" type="button" disabled={busy === 'mfa' || !mfaPassword || !mfaCode} onClick={() => void rotateRecoveryCodes()}>Replace recovery codes</button>{!session.security?.mfa_required && <button className="danger-button" type="button" disabled={busy === 'mfa' || !mfaPassword || !mfaCode} onClick={() => void turnOffMfa()}>Disable MFA</button>}</div></div>}
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
            <div className="subsection-head"><div><b>Device sessions</b><small>Logical sessions; no cookie or raw framework session ID is stored</small></div><button className="secondary compact-button" type="button" onClick={() => void revokeOthers()} disabled={busy === 'other-devices'}>Revoke others</button></div>
            <div className="security-tabs" role="tablist" aria-label="Device session status">
              <button type="button" role="tab" aria-selected={deviceTab === 'active'} className={deviceTab === 'active' ? 'active' : ''} onClick={() => setDeviceTab('active')}>Active ({devices.filter((device) => device.status === 'ACTIVE').length})</button>
              <button type="button" role="tab" aria-selected={deviceTab === 'history'} className={deviceTab === 'history' ? 'active' : ''} onClick={() => setDeviceTab('history')}>History ({devices.filter((device) => device.status !== 'ACTIVE').length})</button>
            </div>
            {loadingDevices && <div className="empty-state">Loading device sessions…</div>}
            {!loadingDevices && !pageDevices.length && <div className="empty-state">No {deviceTab} device sessions.</div>}
            {!loadingDevices && pageDevices.map((device) => <div className="device-row" key={device.id}>
              <div><span className="device-name"><b>{deviceName(device.user_agent)}</b>{device.current && <span className="current-device-badge">Current device</span>}</span><small>{device.ip_address ?? 'Unknown IP'} · Last seen {formatZonedDateTime(device.last_seen_at)}</small><small className="technical-identifier">Session reference {device.id.slice(0, 8)}</small></div>
              <div><StatusBadge status={device.status} />{device.status === 'ACTIVE' && <button className={device.current ? 'danger-button' : 'secondary'} type="button" disabled={busy === device.id} onClick={() => setPendingRevoke(device)}>{device.current ? 'Sign out' : 'Revoke'}</button>}</div>
            </div>)}
            {!loadingDevices && visibleDevices.length > pageSize && <div className="pagination"><button type="button" disabled={devicePage === 1} onClick={() => setDevicePage((value) => value - 1)}>Previous</button><span>Page {devicePage} of {pageCount}</span><button type="button" disabled={devicePage === pageCount} onClick={() => setDevicePage((value) => value + 1)}>Next</button></div>}
          </section>
        </div>
        {pendingRevoke && <div className="confirm-overlay" role="presentation"><section className="confirmation-dialog" role="alertdialog" aria-modal="true" aria-labelledby="revoke-session-title"><h3 id="revoke-session-title">{pendingRevoke.current ? 'Sign out this device?' : `Revoke ${deviceName(pendingRevoke.user_agent)}?`}</h3><p>{pendingRevoke.current ? 'Your current session will end immediately and you will need to authenticate again.' : `Access from ${pendingRevoke.ip_address ?? 'this unknown IP'} will end immediately.`}</p><div className="form-actions"><button className="secondary" type="button" autoFocus onClick={() => setPendingRevoke(null)}>Keep session</button><button className="danger-button" type="button" onClick={() => void revoke(pendingRevoke)}>{pendingRevoke.current ? 'Sign out now' : 'Revoke session'}</button></div></section></div>}
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

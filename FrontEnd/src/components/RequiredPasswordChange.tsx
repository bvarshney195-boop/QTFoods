import { useState, type FormEvent } from 'react';
import type { ErpSession } from '../types/session';

type RequiredPasswordChangeProps = {
  session: ErpSession;
  busy: boolean;
  error: string | null;
  onSubmit: (currentPassword: string, password: string, confirmation: string) => Promise<void> | void;
  onLogout: () => Promise<void> | void;
};

export function RequiredPasswordChange({
  session,
  busy,
  error,
  onSubmit,
  onLogout,
}: RequiredPasswordChangeProps) {
  const [currentPassword, setCurrentPassword] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');

  function submit(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (busy || password !== confirmation) return;
    void onSubmit(currentPassword, password, confirmation);
  }

  return (
    <div className="context-flow required-password-flow">
      <header className="context-flow-header">
        <div className="brand-lockup dark"><span className="brand-mark">Q&T</span><div><b>Q & T FOODS LTD</b><small>Secure account recovery</small></div></div>
        <button className="secondary" type="button" onClick={() => void onLogout()} disabled={busy}>Sign out</button>
      </header>
      <main className="context-flow-main">
        <section className="auth-card required-password-card" aria-labelledby="required-password-title">
          <div className="eyebrow">PASSWORD REPLACEMENT REQUIRED</div>
          <h1 id="required-password-title">Replace your temporary password</h1>
          <p>{session.user.email}</p>
          <div className="live-notice"><span></span><b>Access is locked</b> No ERP business data is available until the temporary password is replaced.</div>
          {error && <div className="form-error" role="alert"><span>{error}</span></div>}
          <form className="form-grid security-password" onSubmit={submit}>
            <label className="full">Temporary password<input autoFocus type="password" autoComplete="current-password" value={currentPassword} onChange={(event) => setCurrentPassword(event.target.value)} required /></label>
            <label>New permanent password<input type="password" autoComplete="new-password" minLength={12} value={password} onChange={(event) => setPassword(event.target.value)} required /><span className="field-hint">At least 12 characters with upper/lowercase letters and a number</span></label>
            <label>Confirm permanent password<input type="password" autoComplete="new-password" minLength={12} value={confirmation} onChange={(event) => setConfirmation(event.target.value)} required /></label>
            {confirmation && password !== confirmation && <span className="field-error full" role="alert">The new passwords do not match.</span>}
            <button className="primary full" type="submit" disabled={busy || !currentPassword || password.length < 12 || password !== confirmation}>{busy ? 'Replacing…' : 'Replace password and continue'}</button>
          </form>
        </section>
      </main>
    </div>
  );
}

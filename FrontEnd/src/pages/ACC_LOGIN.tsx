import { useEffect, useMemo, useState, type FormEvent } from 'react';
import type { AuthMethod, EmailOtpChallenge, MfaChallenge, MfaMethod } from '../api/auth';
import { isApiError } from '../api/client';
import {
  acceptInvitation,
  forgotPassword,
  getInvitation,
  requestEmailVerification,
  resetPassword,
  verifyEmail,
  type PublicInvitation,
} from '../api/identity';

type AccessFlow = 'signin' | 'forgot' | 'verification' | 'reset' | 'verify' | 'invite';

type LoginProps = {
  onPrimaryAuth?: (method: AuthMethod, email: string, credential: string) => Promise<void> | void;
  onRequestEmailOtp?: (email: string) => Promise<void> | void;
  emailOtpChallenge?: EmailOtpChallenge | null;
  onMfa?: (method: MfaMethod, code: string) => Promise<void> | void;
  onRequestMfaEmailOtp?: () => Promise<void> | void;
  onCancelMfa?: () => void;
  mfaChallenge?: MfaChallenge | null;
  onRetry?: () => Promise<void> | void;
  busy?: boolean;
  error?: string | null;
};

const demoAccounts = [
  ['Sales', 'demo.user@qtfoods.local'],
  ['Operations', 'operations.user@qtfoods.local'],
  ['Finance', 'finance.user@qtfoods.local'],
  ['ERP Admin', 'admin.user@qtfoods.local'],
  ['BI Analyst', 'bi.user@qtfoods.local'],
  ['Partner', 'partner.user@qtfoods.local'],
] as const;

const showDemoAccounts = import.meta.env.DEV || import.meta.env.VITE_ALLOW_DEMO_LOGIN === 'true';

export default function ACC_LOGIN({
  onPrimaryAuth,
  onRequestEmailOtp,
  emailOtpChallenge,
  onMfa,
  onRequestMfaEmailOtp,
  onCancelMfa,
  mfaChallenge,
  onRetry,
  busy = false,
  error,
}: LoginProps = {}) {
  const parameters = new URLSearchParams(window.location.search);
  const initialFlow = validFlow(parameters.get('flow'));
  const [flow, setFlow] = useState<AccessFlow>(initialFlow);
  const [method, setMethod] = useState<AuthMethod>('PASSWORD');
  const [mfaMethod, setMfaMethod] = useState<MfaMethod>('TOTP');
  const [email, setEmail] = useState(parameters.get('email') ?? (showDemoAccounts ? 'demo.user@qtfoods.local' : ''));
  const [password, setPassword] = useState(showDemoAccounts ? 'prototype' : '');
  const [confirmation, setConfirmation] = useState('');
  const [code, setCode] = useState('');
  const [token] = useState(parameters.get('token') ?? '');
  const [invitation, setInvitation] = useState<PublicInvitation | null>(null);
  const [localBusy, setLocalBusy] = useState(false);
  const [localError, setLocalError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [mfaEmailSent, setMfaEmailSent] = useState(false);

  useEffect(() => {
    if (flow !== 'invite') return;
    if (!token) {
      setLocalError('This invitation link is missing its one-time token.');
      return;
    }
    setLocalBusy(true);
    getInvitation(token)
      .then((result) => {
        setInvitation(result);
        setEmail(result.email);
      })
      .catch((caught) => setLocalError(message(caught, 'Unable to open this invitation.')))
      .finally(() => setLocalBusy(false));
  }, [flow, token]);

  useEffect(() => {
    if (!mfaChallenge) return;
    const preferred = mfaChallenge.allowed_methods.includes('TOTP')
      ? 'TOTP'
      : mfaChallenge.allowed_methods.includes('EMAIL_OTP')
        ? 'EMAIL_OTP'
        : 'RECOVERY_CODE';
    setMfaMethod(preferred);
    setCode('');
    setMfaEmailSent(false);
  }, [mfaChallenge?.challenge_id]);

  const working = busy || localBusy;
  const methodHint = useMemo(() => ({
    PASSWORD: 'Sign in with your account password.',
    EMAIL_OTP: 'Receive a one-time code at your verified work email.',
    AUTHENTICATOR: 'Use the current six-digit code from Google Authenticator or another TOTP app.',
  })[method], [method]);

  function submitSignIn(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setNotice(null);
    if (mfaChallenge) {
      void onMfa?.(mfaMethod, code.trim());
      return;
    }
    if (method === 'EMAIL_OTP' && !emailOtpChallenge) {
      void onRequestEmailOtp?.(email.trim().toLowerCase());
      return;
    }
    void onPrimaryAuth?.(method, email.trim().toLowerCase(), method === 'PASSWORD' ? password : code.trim());
  }

  async function requestSecondFactorEmail() {
    setMfaEmailSent(false);
    await onRequestMfaEmailOtp?.();
    setMfaEmailSent(true);
  }

  async function submitRecovery(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setLocalBusy(true);
    clearFeedback();
    try {
      const result = flow === 'forgot'
        ? await forgotPassword(email.trim().toLowerCase())
        : await requestEmailVerification(email.trim().toLowerCase());
      setNotice(result.message);
      setPreviewUrl(result.delivery?.preview_url ?? null);
    } catch (caught) {
      setLocalError(message(caught, 'Unable to send the account email.'));
    } finally {
      setLocalBusy(false);
    }
  }

  async function submitCompletion(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setLocalBusy(true);
    clearFeedback();
    try {
      if (flow === 'invite') {
        const result = await acceptInvitation(token, password, confirmation);
        setEmail(result.email);
        finish('Invitation accepted. Sign in with your new password.');
      } else if (flow === 'reset') {
        await resetPassword(email.trim().toLowerCase(), token, password, confirmation);
        finish('Password changed. All earlier device sessions were revoked.');
      } else {
        const result = await verifyEmail(email.trim().toLowerCase(), token);
        setEmail(result.email);
        finish('Email verified. You can now sign in.');
      }
      setPassword('');
      setConfirmation('');
    } catch (caught) {
      setLocalError(message(caught, 'Unable to complete this account request.'));
    } finally {
      setLocalBusy(false);
    }
  }

  function finish(text: string) {
    setFlow('signin');
    setNotice(text);
    window.history.replaceState(null, '', `${window.location.pathname}${window.location.hash}`);
  }

  function switchFlow(next: AccessFlow) {
    setFlow(next);
    setLocalError(null);
    setNotice(null);
    setPreviewUrl(null);
    setCode('');
  }

  function changeMethod(next: AuthMethod) {
    setMethod(next);
    setCode('');
    setLocalError(null);
    setNotice(null);
  }

  function clearFeedback() {
    setLocalError(null);
    setNotice(null);
    setPreviewUrl(null);
  }

  return (
    <div className="auth-page auth-full-page">
      <section className="auth-brand">
        <div className="brand-lockup"><span className="brand-mark">Q&T</span><div><b>Q & T FOODS LTD</b><small>Business management</small></div></div>
        <div>
          <div className="eyebrow light">YOUR WORKSPACE</div>
          <h1>The right work.<br />For the right people.</h1>
          <p>Sign in to see only the companies, locations, and work assigned to your role.</p>
        </div>
        <small>Secure sign-in · protected account · role-based access</small>
      </section>
      <section className="auth-form">
        {flow === 'signin' && (
          <form className="auth-card" onSubmit={submitSignIn}>
            <div className="eyebrow">SECURE SIGN IN</div>
            <h2>{mfaChallenge ? 'Two-step verification' : 'Welcome back'}</h2>
            <p className="auth-intro">{mfaChallenge
              ? `Your primary ${mfaChallenge.primary_method.toLowerCase().replace('_', ' ')} sign-in succeeded. Complete an approved second factor before access is granted.`
              : 'Choose an authentication method for your work account.'}</p>

            {(error || localError) && (
              <div className="form-error" role="alert">
                <span>{error ?? localError}</span>
                {onRetry && <button type="button" onClick={() => void onRetry()}>Retry connection</button>}
              </div>
            )}
            {notice && <div className="form-success" role="status"><span></span>{notice}</div>}

            {mfaChallenge ? <>
              <div className="auth-methods" role="group" aria-label="Second-factor method">
                {mfaChallenge.allowed_methods.includes('TOTP') && <button type="button" className={mfaMethod === 'TOTP' ? 'selected' : ''} onClick={() => { setMfaMethod('TOTP'); setCode(''); }}>Authenticator</button>}
                {mfaChallenge.allowed_methods.includes('EMAIL_OTP') && <button type="button" className={mfaMethod === 'EMAIL_OTP' ? 'selected' : ''} onClick={() => { setMfaMethod('EMAIL_OTP'); setCode(''); }}>Email OTP</button>}
                {mfaChallenge.allowed_methods.includes('RECOVERY_CODE') && <button type="button" className={mfaMethod === 'RECOVERY_CODE' ? 'selected' : ''} onClick={() => { setMfaMethod('RECOVERY_CODE'); setCode(''); }}>Recovery code</button>}
              </div>
              {mfaMethod === 'EMAIL_OTP' && (
                <button className="secondary auth-secondary-action" type="button" disabled={working} onClick={() => void requestSecondFactorEmail()}>
                  {working ? 'Sending…' : mfaEmailSent ? 'Resend email code' : 'Send email code'}
                </button>
              )}
              <label htmlFor="login-code">{mfaMethod === 'TOTP' ? 'Authenticator code' : mfaMethod === 'EMAIL_OTP' ? 'Email verification code' : 'Recovery code'}</label>
              <input id="login-code" inputMode={mfaMethod === 'RECOVERY_CODE' ? undefined : 'numeric'} value={code} onChange={(event) => setCode(event.target.value)} autoComplete="one-time-code" autoFocus required />
              <p className="fine">This second factor cannot be skipped for accounts whose role policy requires MFA.</p>
              <button className="primary" type="submit" disabled={working || !code.trim()}>{working ? 'Verifying…' : 'Verify and sign in'}</button>
              <button className="auth-link centered" type="button" onClick={onCancelMfa}>Start over</button>
            </> : <>
              <div className="auth-methods" role="group" aria-label="Authentication method">
                <button type="button" className={method === 'PASSWORD' ? 'selected' : ''} onClick={() => changeMethod('PASSWORD')}>Password</button>
                <button type="button" className={method === 'EMAIL_OTP' ? 'selected' : ''} onClick={() => changeMethod('EMAIL_OTP')}>Email OTP</button>
                <button type="button" className={method === 'AUTHENTICATOR' ? 'selected' : ''} onClick={() => changeMethod('AUTHENTICATOR')}>Authenticator</button>
              </div>
              <p className="auth-method-hint">{methodHint}</p>
              <label htmlFor="login-email">Email</label>
              <input id="login-email" type="email" value={email} onChange={(event) => setEmail(event.target.value)} autoComplete="username" required />

              {method === 'PASSWORD' && <>
                <label htmlFor="login-password">Password</label>
                <input id="login-password" type="password" value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="current-password" required />
              </>}

              {method === 'EMAIL_OTP' && emailOtpChallenge && <>
                <label htmlFor="primary-email-code">Email sign-in code</label>
                <input id="primary-email-code" inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(event) => setCode(event.target.value)} required />
                <small className="auth-expiry">Code expires {new Date(emailOtpChallenge.expires_at).toLocaleTimeString()}.</small>
                {emailOtpChallenge.delivery?.preview_code && <small className="dev-preview-code">Development preview code: {emailOtpChallenge.delivery.preview_code}</small>}
              </>}

              {method === 'AUTHENTICATOR' && <>
                <label htmlFor="primary-totp-code">Authenticator code</label>
                <input id="primary-totp-code" inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(event) => setCode(event.target.value)} required />
              </>}

              <button className="primary" type="submit" disabled={working || !email.trim() || (method !== 'EMAIL_OTP' && !(method === 'PASSWORD' ? password : code.trim()))}>
                {working ? 'Working…' : method === 'EMAIL_OTP' && !emailOtpChallenge ? 'Send email code' : 'Sign in'}
              </button>
              {method === 'EMAIL_OTP' && emailOtpChallenge && <button className="auth-link centered" type="button" disabled={working} onClick={() => void onRequestEmailOtp?.(email.trim().toLowerCase())}>Resend code</button>}

              <div className="auth-links">
                <button type="button" onClick={() => switchFlow('forgot')}>Forgot password?</button>
                <button type="button" onClick={() => switchFlow('verification')}>Resend verification</button>
              </div>

              {showDemoAccounts && (
                <div className="demo-accounts">
                  <span>Development/UAT demo roles · password: <b>prototype</b></span>
                  <div>{demoAccounts.map(([label, account]) => (
                    <button type="button" key={account} className={email === account ? 'selected' : ''} onClick={() => { setEmail(account); setMethod('PASSWORD'); }}>{label}</button>
                  ))}</div>
                </div>
              )}
            </>}
          </form>
        )}

        {(flow === 'forgot' || flow === 'verification') && (
          <form className="auth-card" onSubmit={submitRecovery}>
            <div className="eyebrow">ACCOUNT RECOVERY</div>
            <h2>{flow === 'forgot' ? 'Reset password' : 'Verify email'}</h2>
            <p className="auth-intro">For privacy, the response is the same whether or not an eligible account exists.</p>
            {localError && <div className="form-error" role="alert"><span>{localError}</span></div>}
            {notice && <div className="form-success stacked-success" role="status"><span></span><div>{notice}{previewUrl && <a href={previewUrl}>Open development email link</a>}</div></div>}
            <label htmlFor="recovery-email">Email</label>
            <input id="recovery-email" type="email" value={email} onChange={(event) => setEmail(event.target.value)} autoComplete="email" required />
            <button className="primary" type="submit" disabled={working}>{working ? 'Sending…' : 'Send secure link'}</button>
            <button className="auth-link centered" type="button" onClick={() => switchFlow('signin')}>Back to sign in</button>
          </form>
        )}

        {(flow === 'invite' || flow === 'reset' || flow === 'verify') && (
          <form className="auth-card" onSubmit={submitCompletion}>
            <div className="eyebrow">ACCOUNT SETUP</div>
            <h2>{flow === 'invite' ? 'Accept invitation' : flow === 'reset' ? 'Choose a new password' : 'Verify your email'}</h2>
            {localError && <div className="form-error" role="alert"><span>{localError}</span></div>}
            {flow === 'invite' && invitation && <div className="identity-summary"><b>{invitation.name}</b><span>{invitation.email}</span><small>{invitation.company_name} · {invitation.plant_name}</small></div>}
            {flow !== 'invite' && <label htmlFor="completion-email">Email<input id="completion-email" type="email" value={email} onChange={(event) => setEmail(event.target.value)} readOnly={Boolean(parameters.get('email'))} required /></label>}
            {(flow === 'invite' || flow === 'reset') && <>
              <label htmlFor="new-password">New password</label>
              <input id="new-password" type="password" value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="new-password" minLength={12} required />
              <label htmlFor="confirm-password">Confirm new password</label>
              <input id="confirm-password" type="password" value={confirmation} onChange={(event) => setConfirmation(event.target.value)} autoComplete="new-password" minLength={12} required />
              <p className="fine">Use at least 12 characters with uppercase, lowercase, and a number.</p>
            </>}
            <button className="primary" type="submit" disabled={working || !token || (flow === 'invite' && !invitation)}>{working ? 'Completing…' : flow === 'verify' ? 'Verify email' : 'Save and continue'}</button>
            <button className="auth-link centered" type="button" onClick={() => switchFlow('signin')}>Back to sign in</button>
          </form>
        )}
      </section>
    </div>
  );
}

function validFlow(value: string | null): AccessFlow {
  return value === 'invite' || value === 'reset' || value === 'verify' ? value : 'signin';
}

function message(error: unknown, fallback: string): string {
  return isApiError(error) ? error.message : fallback;
}

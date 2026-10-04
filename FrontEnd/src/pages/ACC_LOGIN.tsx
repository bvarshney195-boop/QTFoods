import { useEffect, useState, type FormEvent } from 'react';
import type { AuthenticationMethod, EmailOtpChallenge, MfaChallenge, MfaMethod } from '../api/auth';
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
  onLogin?: (email: string, password: string, method: 'PASSWORD' | 'TOTP', code?: string) => Promise<void> | void;
  onRequestEmailOtp?: (email: string) => Promise<EmailOtpChallenge | void> | EmailOtpChallenge | void;
  onVerifyEmailOtp?: (challengeId: string, email: string, code: string) => Promise<void> | void;
  onMfa?: (code: string, method: MfaMethod) => Promise<void> | void;
  onCancelMfa?: () => void;
  mfaChallenge?: MfaChallenge | null;
  emailOtpChallenge?: EmailOtpChallenge | null;
  onRetry?: () => Promise<void> | void;
  busy?: boolean;
  error?: string | null;
};

const demoAccounts = [
  ['Sales', 'demo.user@qtfoods.local'],
  ['Operations', 'operations.user@qtfoods.local'],
  ['Finance', 'finance.user@qtfoods.local'],
  ['ERP Admin', 'admin.user@qtfoods.local'],
] as const;

const showDemoAccounts = import.meta.env.DEV || import.meta.env.VITE_SHOW_DEMO_LOGIN === 'true';

export default function ACC_LOGIN({
  onLogin,
  onRequestEmailOtp,
  onVerifyEmailOtp,
  onMfa,
  onCancelMfa,
  mfaChallenge,
  emailOtpChallenge,
  onRetry,
  busy = false,
  error,
}: LoginProps = {}) {
  const parameters = new URLSearchParams(window.location.search);
  const initialFlow = validFlow(parameters.get('flow'));
  const [flow, setFlow] = useState<AccessFlow>(initialFlow);
  const [authMethod, setAuthMethod] = useState<AuthenticationMethod>('PASSWORD');
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
    const available = mfaChallenge.available_methods?.length ? mfaChallenge.available_methods : ['TOTP', 'RECOVERY_CODE'];
    const next = available.includes('TOTP')
      ? 'TOTP'
      : available.includes('EMAIL_OTP')
        ? 'EMAIL_OTP'
        : 'RECOVERY_CODE';
    setMfaMethod(next);
    setCode('');
  }, [mfaChallenge]);

  const working = busy || localBusy;

  async function submitSignIn(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setNotice(null);
    setLocalError(null);

    if (mfaChallenge) {
      await onMfa?.(code.trim(), mfaMethod);
      return;
    }

    if (authMethod === 'EMAIL_OTP') {
      if (emailOtpChallenge?.challenge_id) {
        await onVerifyEmailOtp?.(emailOtpChallenge.challenge_id, email.trim(), code.trim());
      } else {
        const result = await onRequestEmailOtp?.(email.trim());
        if (result?.message) setNotice(result.message);
      }
      return;
    }

    if (authMethod === 'TOTP') {
      await onLogin?.(email.trim(), password, 'TOTP', code.trim());
      return;
    }

    await onLogin?.(email.trim(), password, 'PASSWORD');
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

  function chooseMethod(method: AuthenticationMethod) {
    setAuthMethod(method);
    setCode('');
    setNotice(null);
    setLocalError(null);
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
            <h2>{mfaChallenge ? 'Complete two-step verification' : 'Welcome back'}</h2>
            <p className="auth-intro">{mfaChallenge
              ? `Your primary sign-in succeeded. Complete an approved second factor before access is granted. Challenge expires ${new Date(mfaChallenge.expires_at).toLocaleTimeString()}.`
              : 'Choose an authentication method for your work account.'}</p>

            {(error || localError) && (
              <div className="form-error" role="alert">
                <span>{error ?? localError}</span>
                {onRetry && <button type="button" onClick={() => void onRetry()}>Retry connection</button>}
              </div>
            )}
            {notice && <div className="form-info" role="status">{notice}</div>}

            {mfaChallenge ? <>
              <div className="auth-methods" role="group" aria-label="Second factor method">
                {(mfaChallenge.available_methods?.length ? mfaChallenge.available_methods : ['TOTP', 'RECOVERY_CODE']).includes('EMAIL_OTP') && <button type="button" className={mfaMethod === 'EMAIL_OTP' ? 'selected' : ''} onClick={() => setMfaMethod('EMAIL_OTP')}>Email OTP</button>}
                {(mfaChallenge.available_methods?.length ? mfaChallenge.available_methods : ['TOTP', 'RECOVERY_CODE']).includes('TOTP') && <button type="button" className={mfaMethod === 'TOTP' ? 'selected' : ''} onClick={() => setMfaMethod('TOTP')}>Google Authenticator</button>}
                {(mfaChallenge.available_methods?.length ? mfaChallenge.available_methods : ['TOTP', 'RECOVERY_CODE']).includes('RECOVERY_CODE') && <button type="button" className={mfaMethod === 'RECOVERY_CODE' ? 'selected' : ''} onClick={() => setMfaMethod('RECOVERY_CODE')}>Recovery code</button>}
              </div>
              <label htmlFor="login-code">{!mfaChallenge.available_methods?.length ? 'Authenticator or recovery code' : mfaMethod === 'EMAIL_OTP' ? 'Email OTP' : mfaMethod === 'TOTP' ? 'Google Authenticator code' : 'Recovery code'}</label>
              <input id="login-code" inputMode={mfaMethod === 'RECOVERY_CODE' ? 'text' : 'numeric'} value={code} onChange={(event) => setCode(event.target.value)} autoComplete="one-time-code" autoFocus required />
              <button className="primary" type="submit" disabled={working || !code.trim()}>{working ? 'Verifying…' : 'Verify and sign in'}</button>
              <button className="auth-link centered" type="button" onClick={onCancelMfa}>Back to sign in</button>
            </> : <>
              <div className="auth-methods" role="group" aria-label="Authentication method">
                <button type="button" className={authMethod === 'PASSWORD' ? 'selected' : ''} onClick={() => chooseMethod('PASSWORD')}>Password</button>
                <button type="button" className={authMethod === 'EMAIL_OTP' ? 'selected' : ''} onClick={() => chooseMethod('EMAIL_OTP')}>Email OTP</button>
                <button type="button" className={authMethod === 'TOTP' ? 'selected' : ''} onClick={() => chooseMethod('TOTP')}>Google Authenticator</button>
              </div>

              <label htmlFor="login-email">Email</label>
              <input id="login-email" type="email" value={email} onChange={(event) => setEmail(event.target.value)} autoComplete="username" required />

              {(authMethod === 'PASSWORD' || authMethod === 'TOTP') && <>
                <label htmlFor="login-password">Password</label>
                <input id="login-password" type="password" value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="current-password" required />
              </>}

              {authMethod === 'TOTP' && <>
                <label htmlFor="login-totp">Google Authenticator code</label>
                <input id="login-totp" inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(event) => setCode(event.target.value)} required />
              </>}

              {authMethod === 'EMAIL_OTP' && emailOtpChallenge?.challenge_id && <>
                <label htmlFor="login-email-otp">Email OTP</label>
                <input id="login-email-otp" inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(event) => setCode(event.target.value)} autoFocus required />
                <button className="auth-link" type="button" onClick={() => void onRequestEmailOtp?.(email.trim())}>Send a new code</button>
              </>}

              <button className="primary" type="submit" disabled={working || (authMethod === 'TOTP' && !code.trim())}>
                {working ? 'Please wait…' : authMethod === 'EMAIL_OTP' && !emailOtpChallenge?.challenge_id ? 'Send email OTP' : 'Sign in'}
              </button>

              <div className="auth-links">
                <button type="button" onClick={() => switchFlow('forgot')}>Forgot password?</button>
                <button type="button" onClick={() => switchFlow('verification')}>Resend verification</button>
              </div>

              <p className="auth-security-note">Privileged roles cannot use password-only authentication. After the primary sign-in step, an approved second factor is required by policy.</p>

              {showDemoAccounts && <div className="demo-accounts">
                <span>Development demo roles · password: <b>prototype</b></span>
                <div>{demoAccounts.map(([label, account]) => (
                  <button type="button" key={account} className={email === account ? 'selected' : ''} onClick={() => setEmail(account)}>{label}</button>
                ))}</div>
              </div>}
            </>}
          </form>
        )}

        {(flow === 'forgot' || flow === 'verification') && (
          <form className="auth-card" onSubmit={submitRecovery}>
            <div className="eyebrow">ACCOUNT RECOVERY</div>
            <h2>{flow === 'forgot' ? 'Reset password' : 'Verify email'}</h2>
            <p className="auth-intro">For privacy, the response is the same whether or not an eligible account exists.</p>
            {localError && <div className="form-error" role="alert"><span>{localError}</span></div>}
            {notice && <div className="form-success stacked-success" role="status"><span /><div>{notice}{previewUrl && <a href={previewUrl}>Open development email link</a>}</div></div>}
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

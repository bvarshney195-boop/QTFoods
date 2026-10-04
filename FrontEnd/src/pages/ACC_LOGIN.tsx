import { useEffect, useState, type FormEvent } from 'react';
import type { AuthMethod, EmailOtpChallenge, MfaChallenge, SecondFactorMethod } from '../api/auth';
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
  onPasswordLogin?: (email: string, password: string) => Promise<void> | void;
  onTotpLogin?: (email: string, password: string, code: string) => Promise<void> | void;
  onEmailOtpRequest?: (email: string) => Promise<void> | void;
  onEmailOtpVerify?: (email: string, challengeId: string, code: string) => Promise<void> | void;
  emailOtpChallenge?: EmailOtpChallenge | null;
  onMfa?: (method: SecondFactorMethod, code: string) => Promise<void> | void;
  onMfaEmailOtpRequest?: () => Promise<void> | void;
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
] as const;

const showDemoAccounts = import.meta.env.DEV || import.meta.env.VITE_SHOW_DEMO_ACCOUNTS === 'true';

export default function ACC_LOGIN({
  onPasswordLogin,
  onTotpLogin,
  onEmailOtpRequest,
  onEmailOtpVerify,
  emailOtpChallenge,
  onMfa,
  onMfaEmailOtpRequest,
  onCancelMfa,
  mfaChallenge,
  onRetry,
  busy = false,
  error,
}: LoginProps = {}) {
  const parameters = new URLSearchParams(window.location.search);
  const initialFlow = validFlow(parameters.get('flow'));
  const [flow, setFlow] = useState<AccessFlow>(initialFlow);
  const [authMethod, setAuthMethod] = useState<AuthMethod>('PASSWORD');
  const [secondFactor, setSecondFactor] = useState<SecondFactorMethod>('EMAIL_OTP');
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
    const preferred = mfaChallenge.available_methods.includes('AUTHENTICATOR') ? 'AUTHENTICATOR' : 'EMAIL_OTP';
    setSecondFactor(preferred);
    setCode('');
  }, [mfaChallenge]);

  const working = busy || localBusy;

  function chooseMethod(method: AuthMethod) {
    setAuthMethod(method);
    setCode('');
    setLocalError(null);
    setNotice(null);
  }

  function submitSignIn(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setNotice(null);
    if (mfaChallenge) {
      void onMfa?.(secondFactor, code.trim());
      return;
    }
    if (authMethod === 'PASSWORD') {
      void onPasswordLogin?.(email.trim(), password);
    } else if (authMethod === 'AUTHENTICATOR') {
      void onTotpLogin?.(email.trim(), password, code.trim());
    } else if (emailOtpChallenge) {
      void onEmailOtpVerify?.(email.trim(), emailOtpChallenge.challenge_id, code.trim());
    } else {
      void onEmailOtpRequest?.(email.trim());
    }
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

  function clearFeedback() {
    setLocalError(null);
    setNotice(null);
    setPreviewUrl(null);
  }

  const secondFactorEmailSent = secondFactor === 'EMAIL_OTP' && Boolean(emailOtpChallenge);

  return (
    <div className="auth-page auth-full-page">
      <section className="auth-brand">
        <div className="brand-lockup"><span className="brand-mark">Q&T</span><div><b>Q & T FOODS LTD</b><small>Business management</small></div></div>
        <div>
          <div className="eyebrow light">YOUR WORKSPACE</div>
          <h1>The right work.<br />For the right people.</h1>
          <p>Choose a secure sign-in method. Privileged roles always require an approved second factor.</p>
        </div>
        <small>Secure sign-in · protected account · role-based access</small>
      </section>
      <section className="auth-form">
        {flow === 'signin' && (
          <form className="auth-card" onSubmit={submitSignIn}>
            <div className="eyebrow">SECURE SIGN IN</div>
            <h2>{mfaChallenge ? 'Two-step verification' : 'Welcome back'}</h2>
            <p className="auth-intro">{mfaChallenge
              ? `Complete the required second factor. Challenge expires ${new Date(mfaChallenge.expires_at).toLocaleTimeString()}.`
              : 'Select the authentication method available for your work account.'}</p>

            {(error || localError) && (
              <div className="form-error" role="alert">
                <span>{error ?? localError}</span>
                {onRetry && <button type="button" onClick={() => void onRetry()}>Retry connection</button>}
              </div>
            )}
            {notice && <div className="form-success" role="status"><span />{notice}</div>}

            {mfaChallenge ? <>
              <div className="auth-methods" role="group" aria-label="Second factor">
                {mfaChallenge.available_methods.includes('EMAIL_OTP') && (
                  <button type="button" className={secondFactor === 'EMAIL_OTP' ? 'selected' : ''} onClick={() => { setSecondFactor('EMAIL_OTP'); setCode(''); }}>Email OTP</button>
                )}
                {mfaChallenge.available_methods.includes('AUTHENTICATOR') && (
                  <button type="button" className={secondFactor === 'AUTHENTICATOR' ? 'selected' : ''} onClick={() => { setSecondFactor('AUTHENTICATOR'); setCode(''); }}>Google Authenticator</button>
                )}
              </div>
              {secondFactor === 'EMAIL_OTP' && !emailOtpChallenge ? (
                <button className="primary" type="button" onClick={() => void onMfaEmailOtpRequest?.()} disabled={working}>
                  {working ? 'Sending…' : 'Send email OTP'}
                </button>
              ) : <>
                <label htmlFor="login-code">{secondFactor === 'EMAIL_OTP' ? 'Email one-time code' : 'Authenticator code'}</label>
                <input id="login-code" inputMode="numeric" value={code} onChange={(event) => setCode(event.target.value)} autoComplete="one-time-code" autoFocus required />
                <button className="primary" type="submit" disabled={working || !code.trim()}>{working ? 'Verifying…' : 'Verify and sign in'}</button>
              </>}
              {secondFactorEmailSent && <p className="fine">A one-time code was sent to your verified work email.</p>}
              <button className="auth-link centered" type="button" onClick={onCancelMfa}>Start sign-in again</button>
            </> : <>
              <div className="auth-methods" role="tablist" aria-label="Authentication method">
                <button type="button" role="tab" aria-selected={authMethod === 'PASSWORD'} className={authMethod === 'PASSWORD' ? 'selected' : ''} onClick={() => chooseMethod('PASSWORD')}>Password</button>
                <button type="button" role="tab" aria-selected={authMethod === 'EMAIL_OTP'} className={authMethod === 'EMAIL_OTP' ? 'selected' : ''} onClick={() => chooseMethod('EMAIL_OTP')}>Email OTP</button>
                <button type="button" role="tab" aria-selected={authMethod === 'AUTHENTICATOR'} className={authMethod === 'AUTHENTICATOR' ? 'selected' : ''} onClick={() => chooseMethod('AUTHENTICATOR')}>Google Authenticator</button>
              </div>

              <label htmlFor="login-email">Email</label>
              <input id="login-email" type="email" value={email} onChange={(event) => setEmail(event.target.value)} autoComplete="username" required />

              {(authMethod === 'PASSWORD' || authMethod === 'AUTHENTICATOR') && <>
                <label htmlFor="login-password">Password</label>
                <input id="login-password" type="password" value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="current-password" required />
              </>}

              {authMethod === 'AUTHENTICATOR' && <>
                <label htmlFor="login-code">Google Authenticator code</label>
                <input id="login-code" inputMode="numeric" pattern="[0-9]{6}" maxLength={6} value={code} onChange={(event) => setCode(event.target.value)} autoComplete="one-time-code" required />
                <p className="fine">This method verifies your password and current six-digit authenticator code together.</p>
              </>}

              {authMethod === 'EMAIL_OTP' && emailOtpChallenge && <>
                <label htmlFor="login-code">Email one-time code</label>
                <input id="login-code" inputMode="numeric" pattern="[0-9]{6}" maxLength={6} value={code} onChange={(event) => setCode(event.target.value)} autoComplete="one-time-code" required />
                <p className="fine">Code expires at {new Date(emailOtpChallenge.expires_at).toLocaleTimeString()}.</p>
              </>}

              <button className="primary" type="submit" disabled={working || (authMethod === 'AUTHENTICATOR' && code.trim().length !== 6)}>
                {working ? 'Working…' : authMethod === 'EMAIL_OTP' && !emailOtpChallenge ? 'Send email OTP' : 'Sign in securely'}
              </button>

              {authMethod === 'EMAIL_OTP' && emailOtpChallenge && (
                <button className="auth-link centered" type="button" onClick={() => void onEmailOtpRequest?.(email.trim())} disabled={working}>Send a new code</button>
              )}

              <div className="auth-links">
                <button type="button" onClick={() => switchFlow('forgot')}>Forgot password?</button>
                <button type="button" onClick={() => switchFlow('verification')}>Resend verification</button>
              </div>

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

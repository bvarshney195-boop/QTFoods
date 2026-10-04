import { useEffect, useMemo, useState, type FormEvent } from 'react';
import type {
  EmailOtpChallenge,
  MfaChallenge,
  SecondFactorMethod,
} from '../api/auth';
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
type AuthMethod = 'password' | 'email_otp' | 'totp';

type LoginProps = {
  onPasswordLogin?: (email: string, password: string) => Promise<void> | void;
  /** Backward-compatible alias retained for focused component tests. */
  onLogin?: (email: string, password: string) => Promise<void> | void;
  onEmailOtpRequest?: (email: string) => Promise<void> | void;
  onEmailOtpVerify?: (code: string) => Promise<void> | void;
  onTotpLogin?: (email: string, code: string) => Promise<void> | void;
  onMfa?: (code: string, method?: SecondFactorMethod) => Promise<void> | void;
  onMfaEmailOtp?: () => Promise<void> | void;
  onCancelChallenge?: () => void;
  /** Backward-compatible alias. */
  onCancelMfa?: () => void;
  mfaChallenge?: MfaChallenge | null;
  emailOtpChallenge?: EmailOtpChallenge | null;
  onRetry?: () => Promise<void> | void;
  busy?: boolean;
  error?: string | null;
};

export default function ACC_LOGIN({
  onPasswordLogin,
  onLogin,
  onEmailOtpRequest,
  onEmailOtpVerify,
  onTotpLogin,
  onMfa,
  onMfaEmailOtp,
  onCancelChallenge,
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
  const [authMethod, setAuthMethod] = useState<AuthMethod>('password');
  const [email, setEmail] = useState(parameters.get('email') ?? '');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [code, setCode] = useState('');
  const [secondFactor, setSecondFactor] = useState<SecondFactorMethod>('TOTP');
  const [token] = useState(parameters.get('token') ?? '');
  const [invitation, setInvitation] = useState<PublicInvitation | null>(null);
  const [localBusy, setLocalBusy] = useState(false);
  const [localError, setLocalError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);

  const allowedSecondFactors = useMemo(
    () => mfaChallenge?.methods ?? [],
    [mfaChallenge],
  );

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
    const preferred = mfaChallenge.methods.includes('TOTP')
      ? 'TOTP'
      : mfaChallenge.methods.includes('EMAIL_OTP')
        ? 'EMAIL_OTP'
        : mfaChallenge.methods[0];
    if (preferred) setSecondFactor(preferred);
    setCode('');
  }, [mfaChallenge]);

  const working = busy || localBusy;

  function clearAuthFeedback() {
    setLocalError(null);
    setNotice(null);
    setCode('');
  }

  function selectMethod(method: AuthMethod) {
    setAuthMethod(method);
    clearAuthFeedback();
    onCancelChallenge?.();
    onCancelMfa?.();
  }

  function submitSignIn(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setNotice(null);
    if (mfaChallenge) {
      void onMfa?.(code.trim(), secondFactor);
      return;
    }
    if (authMethod === 'password') {
      void (onPasswordLogin ?? onLogin)?.(email.trim(), password);
      return;
    }
    if (authMethod === 'email_otp') {
      if (emailOtpChallenge) void onEmailOtpVerify?.(code.trim());
      else void onEmailOtpRequest?.(email.trim());
      return;
    }
    void onTotpLogin?.(email.trim(), code.trim());
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

  function cancelChallenge() {
    onCancelChallenge?.();
    onCancelMfa?.();
    setCode('');
  }

  const effectiveError = error ?? localError;

  return (
    <div className="auth-page auth-full-page">
      <section className="auth-brand">
        <div className="brand-lockup"><span className="brand-mark">Q&T</span><div><b>Q & T FOODS LTD</b><small>Business management</small></div></div>
        <div>
          <div className="eyebrow light">YOUR WORKSPACE</div>
          <h1>The right work.<br />For the right people.</h1>
          <p>Choose an approved sign-in method. Privileged roles require a separate second factor before access is granted.</p>
        </div>
        <small>Secure sign-in · verified identity · role-based access · MFA for privileged access</small>
      </section>

      <section className="auth-form">
        {flow === 'signin' && (
          <form className="auth-card" onSubmit={submitSignIn} aria-busy={working}>
            <div className="eyebrow">SECURE SIGN IN</div>
            <h2>{mfaChallenge ? 'Two-step verification' : 'Welcome back'}</h2>
            <p className="auth-intro">{mfaChallenge
              ? `Your primary ${methodLabel(mfaChallenge.primary_method)} check passed. Complete an independent approved second factor before access is granted.`
              : 'Select how you want to verify your work account.'}</p>

            {effectiveError && (
              <div className="form-error" role="alert">
                <span>{effectiveError}</span>
                {onRetry && <button type="button" onClick={() => void onRetry()}>Retry connection</button>}
              </div>
            )}
            {notice && <div className="form-success" role="status"><span />{notice}</div>}

            {mfaChallenge ? <>
              <fieldset className="auth-methods">
                <legend>Second factor</legend>
                {allowedSecondFactors.map((method) => (
                  <button
                    key={method}
                    type="button"
                    aria-pressed={secondFactor === method}
                    className={secondFactor === method ? 'selected' : ''}
                    onClick={() => { setSecondFactor(method); setCode(''); }}
                  >
                    {secondFactorLabel(method)}
                  </button>
                ))}
              </fieldset>
              {secondFactor === 'EMAIL_OTP' && !mfaChallenge.email_otp_sent ? (
                <button className="primary" type="button" disabled={working} onClick={() => void onMfaEmailOtp?.()}>
                  {working ? 'Sending…' : 'Send email verification code'}
                </button>
              ) : <>
                <label htmlFor="login-code">{secondFactor === 'EMAIL_OTP' ? 'Email verification code' : secondFactor === 'TOTP' ? 'Google Authenticator code' : 'Recovery code'}</label>
                <input
                  id="login-code"
                  inputMode={secondFactor === 'RECOVERY_CODE' ? undefined : 'numeric'}
                  value={code}
                  onChange={(event) => setCode(event.target.value)}
                  autoComplete="one-time-code"
                  autoFocus
                  required
                />
                {mfaChallenge.preview_code && secondFactor === 'EMAIL_OTP' ? <p className="fine">Development preview code: <code>{mfaChallenge.preview_code}</code></p> : null}
                <button className="primary" type="submit" disabled={working || !code.trim()}>{working ? 'Verifying…' : 'Verify and sign in'}</button>
              </>}
              <button className="auth-link centered" type="button" onClick={cancelChallenge}>Start sign-in again</button>
            </> : <>
              <fieldset className="auth-methods" aria-label="Authentication method">
                <legend>Authentication method</legend>
                <button type="button" aria-pressed={authMethod === 'password'} className={authMethod === 'password' ? 'selected' : ''} onClick={() => selectMethod('password')}>Password</button>
                <button type="button" aria-pressed={authMethod === 'email_otp'} className={authMethod === 'email_otp' ? 'selected' : ''} onClick={() => selectMethod('email_otp')}>Email OTP</button>
                <button type="button" aria-pressed={authMethod === 'totp'} className={authMethod === 'totp' ? 'selected' : ''} onClick={() => selectMethod('totp')}>Google Authenticator</button>
              </fieldset>

              <label htmlFor="login-email">Email</label>
              <input id="login-email" type="email" value={email} onChange={(event) => setEmail(event.target.value)} autoComplete="username" required />

              {authMethod === 'password' ? <>
                <label htmlFor="login-password">Password</label>
                <input id="login-password" type="password" value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="current-password" required />
                <button className="primary" type="submit" disabled={working || !email.trim() || !password}>{working ? 'Signing in…' : 'Continue with password'}</button>
              </> : null}

              {authMethod === 'email_otp' ? <>
                {emailOtpChallenge ? <>
                  <label htmlFor="login-email-code">Email sign-in code</label>
                  <input id="login-email-code" inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(event) => setCode(event.target.value)} required />
                  {emailOtpChallenge.preview_code ? <p className="fine">Development preview code: <code>{emailOtpChallenge.preview_code}</code></p> : null}
                  <button className="primary" type="submit" disabled={working || !code.trim()}>{working ? 'Verifying…' : 'Verify email code'}</button>
                  <button type="button" className="auth-link centered" disabled={working} onClick={() => void onEmailOtpRequest?.(email.trim())}>Send a new code</button>
                </> : <button className="primary" type="submit" disabled={working || !email.trim()}>{working ? 'Sending…' : 'Send email OTP'}</button>}
              </> : null}

              {authMethod === 'totp' ? <>
                <label htmlFor="login-totp">Google Authenticator code</label>
                <input id="login-totp" inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(event) => setCode(event.target.value)} required />
                <p className="fine">Use the current six-digit code from the authenticator app enrolled on this account.</p>
                <button className="primary" type="submit" disabled={working || !email.trim() || !code.trim()}>{working ? 'Verifying…' : 'Continue with Authenticator'}</button>
              </> : null}

              <div className="auth-links">
                <button type="button" onClick={() => switchFlow('forgot')}>Forgot password?</button>
                <button type="button" onClick={() => switchFlow('verification')}>Resend verification</button>
              </div>
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

function methodLabel(method: string): string {
  return method === 'EMAIL_OTP' ? 'Email OTP' : method === 'TOTP' ? 'Google Authenticator' : 'password';
}

function secondFactorLabel(method: SecondFactorMethod): string {
  return method === 'EMAIL_OTP' ? 'Email OTP' : method === 'TOTP' ? 'Google Authenticator' : 'Recovery code';
}

function message(error: unknown, fallback: string): string {
  return isApiError(error) ? error.message : fallback;
}

import { useEffect, useState, type FormEvent } from 'react';
import QRCode from 'qrcode';
import type {
  AuthenticationAction,
  AuthenticationChallenge,
  AuthenticationMethod,
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

type LoginProps = {
  onLogin?: (email: string, method: AuthenticationMethod, password?: string) => Promise<void> | void;
  onChallenge?: (action: AuthenticationAction, credential?: string) => Promise<void> | void;
  onCancelChallenge?: () => void;
  onClearError?: () => void;
  authChallenge?: AuthenticationChallenge | null;
  onRetry?: () => Promise<void> | void;
  busy?: boolean;
  error?: string | null;
};

export default function ACC_LOGIN({
  onLogin,
  onChallenge,
  onCancelChallenge,
  onClearError,
  authChallenge,
  onRetry,
  busy = false,
  error,
}: LoginProps = {}) {
  const parameters = new URLSearchParams(window.location.search);
  const initialFlow = validFlow(parameters.get('flow'));
  const [flow, setFlow] = useState<AccessFlow>(initialFlow);
  const [email, setEmail] = useState(parameters.get('email') ?? '');
  const [password, setPassword] = useState('');
  const [authMethod, setAuthMethod] = useState<AuthenticationMethod>('password');
  const [confirmation, setConfirmation] = useState('');
  const [code, setCode] = useState('');
  const [token] = useState(parameters.get('token') ?? '');
  const [invitation, setInvitation] = useState<PublicInvitation | null>(null);
  const [localBusy, setLocalBusy] = useState(false);
  const [localError, setLocalError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [qrCode, setQrCode] = useState<string | null>(null);

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
    let current = true;
    if (!authChallenge?.setup?.otpauth_uri) {
      setQrCode(null);
      return () => { current = false; };
    }
    void QRCode.toDataURL(authChallenge.setup.otpauth_uri, {
      errorCorrectionLevel: 'M',
      margin: 2,
      width: 220,
      color: { dark: '#173f35', light: '#ffffff' },
    }).then((value) => {
      if (current) setQrCode(value);
    }).catch(() => {
      if (current) setQrCode(null);
    });
    return () => { current = false; };
  }, [authChallenge?.setup?.otpauth_uri]);

  useEffect(() => {
    if (authChallenge && !isPasswordChallenge(authChallenge.phase)) setPassword('');
  }, [authChallenge?.phase]);

  const working = busy || localBusy;

  function submitSignIn(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    setNotice(null);
    if (authChallenge) {
      const action = challengeVerificationAction(authChallenge.phase);
      if (action) void onChallenge?.(action, action === 'verify_password' ? password : code.trim());
    } else {
      void onLogin?.(email.trim().toLowerCase(), authMethod, authMethod === 'password' ? password : undefined);
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

  function updateSignInEmail(value: string) {
    setEmail(value);
    setLocalError(null);
    setNotice(null);
    onClearError?.();
  }

  function selectAuthenticationMethod(method: AuthenticationMethod) {
    setAuthMethod(method);
    if (method !== 'password') setPassword('');
    setLocalError(null);
    setNotice(null);
    onClearError?.();
  }

  function updateSignInPassword(value: string) {
    setPassword(value);
    setLocalError(null);
    setNotice(null);
    onClearError?.();
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
            <h2>{authChallenge ? challengeTitle(authChallenge.phase) : 'Welcome back'}</h2>
            <p className="auth-intro">{authChallenge
              ? challengeIntroduction(authChallenge)
              : 'Choose how you want to verify your work account.'}</p>

            {(error || localError) && (
              <div className="form-error" role="alert">
                <span>{error ?? localError}</span>
                {onRetry && <button type="button" onClick={() => void onRetry()}>Retry connection</button>}
              </div>
            )}
            {notice && <div className="form-success" role="status"><span></span>{notice}</div>}

            {authChallenge ? <>
              {authChallenge.phase === 'SELECT_SECOND_FACTOR' && (
                <fieldset className="authentication-methods authentication-second-factors">
                  <legend>Choose a required second factor</legend>
                  <button type="button" className="authentication-method" disabled={working} onClick={() => void onChallenge?.('select_email_otp')}>
                    <b>Email one-time code</b><span>Send a six-digit code to {authChallenge.email_hint}</span>
                  </button>
                  {authChallenge.available_methods.includes('totp') && (
                    <button type="button" className="authentication-method" disabled={working} onClick={() => void onChallenge?.('select_totp')}>
                      <b>Google Authenticator</b><span>{authChallenge.totp_registered ? 'Use your registered authenticator or a recovery code' : 'Register a new authenticator now'}</span>
                    </button>
                  )}
                </fieldset>
              )}

              {authChallenge.phase === 'TOTP_ENROLLMENT' && authChallenge.setup && (
                <div className="totp-enrolment" aria-live="polite">
                  <p>Scan this QR code with Google Authenticator, then enter the current six-digit code.</p>
                  {qrCode
                    ? <img src={qrCode} width="220" height="220" alt="QR code for registering Q & T Foods in Google Authenticator" />
                    : <div className="qr-placeholder" aria-busy="true">Preparing secure QR code…</div>}
                  <details><summary>Can’t scan the QR code?</summary><p>Enter this setup key manually:</p><code>{authChallenge.setup.secret}</code></details>
                </div>
              )}

              {authChallenge.phase !== 'SELECT_SECOND_FACTOR' && (
                <>
                  {isPasswordChallenge(authChallenge.phase) ? <>
                    <label htmlFor="challenge-password">Account password</label>
                    <input
                      id="challenge-password"
                      type="password"
                      value={password}
                      onChange={(event) => updateSignInPassword(event.target.value)}
                      autoComplete="current-password"
                      autoFocus
                      required
                      aria-describedby="challenge-expiry"
                    />
                  </> : <>
                    <label htmlFor="login-code">{challengeCodeLabel(authChallenge.phase)}</label>
                    <input
                      id="login-code"
                      value={code}
                      onChange={(event) => setCode(event.target.value.replace(/\s/g, ''))}
                      inputMode={authChallenge.phase === 'TOTP_ENROLLMENT' || authChallenge.phase.startsWith('EMAIL_') ? 'numeric' : 'text'}
                      autoComplete="one-time-code"
                      autoFocus
                      required
                      aria-describedby="challenge-expiry"
                    />
                  </>}
                  <small id="challenge-expiry">This sign-in step expires at {new Date(authChallenge.expires_at).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' })}.</small>
                  {authChallenge.delivery?.preview_code && <small className="development-preview">Development email code: <code>{authChallenge.delivery.preview_code}</code></small>}
                  <button className="primary" type="submit" disabled={working || (isPasswordChallenge(authChallenge.phase) ? !password : !code.trim())}>{working ? 'Verifying…' : authChallenge.phase === 'TOTP_ENROLLMENT' ? 'Register and sign in' : isPasswordChallenge(authChallenge.phase) ? 'Verify password and continue' : 'Verify and continue'}</button>
                  {authChallenge.phase.startsWith('EMAIL_') && (
                    <button className="auth-link centered" type="button" disabled={working} onClick={() => void onChallenge?.('resend_email_otp')}>Send a new code</button>
                  )}
                </>
              )}
              <button className="auth-link centered" type="button" onClick={() => { setCode(''); setPassword(''); onCancelChallenge?.(); }}>Start again</button>
            </> : <>
              <label htmlFor="login-email">Email</label>
              <input id="login-email" type="email" value={email} onChange={(event) => updateSignInEmail(event.target.value)} autoComplete="username" required />
              <fieldset className="authentication-methods authentication-primary-methods">
                <legend>Authentication method</legend>
                {([
                  ['password', 'Password', 'Enter your account password'],
                  ['email_otp', 'Email OTP', 'Receive a code at your registered email'],
                  ['totp', 'Google Authenticator', 'Use or register a time-based code'],
                ] as const).map(([value, label, description]) => (
                  <label className={`authentication-method ${authMethod === value ? 'selected' : ''}`} key={value}>
                    <input type="radio" name="authentication-method" value={value} checked={authMethod === value} onChange={() => selectAuthenticationMethod(value)} />
                    <span><b>{label}</b><small>{description}</small></span>
                  </label>
                ))}
              </fieldset>
              {authMethod === 'password' && <>
                <label htmlFor="login-password">Password</label>
                <input id="login-password" type="password" value={password} onChange={(event) => updateSignInPassword(event.target.value)} autoComplete="current-password" required />
              </>}
              <button className="primary" type="submit" disabled={working || (authMethod === 'password' && !password)}>{working ? 'Starting secure sign-in…' : authMethod === 'email_otp' ? 'Send email code' : authMethod === 'totp' ? 'Continue with authenticator' : 'Continue with password'}</button>
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

function challengeVerificationAction(phase: AuthenticationChallenge['phase']): AuthenticationAction | null {
  if (phase === 'TOTP_ENROLLMENT') return 'confirm_totp_setup';
  if (phase === 'TOTP_PRIMARY' || phase === 'TOTP_SECOND') return 'verify_totp';
  if (isPasswordChallenge(phase)) return 'verify_password';
  if (phase.startsWith('EMAIL_')) return 'verify_email_otp';
  return null;
}

function challengeTitle(phase: AuthenticationChallenge['phase']): string {
  if (phase === 'SELECT_SECOND_FACTOR') return 'Two-step verification required';
  if (phase === 'TOTP_ENROLLMENT') return 'Register Google Authenticator';
  if (phase === 'TOTP_PRIMARY' || phase === 'TOTP_SECOND') return 'Google Authenticator';
  if (isPasswordChallenge(phase)) return 'Verify your account password';
  if (phase === 'EMAIL_PROOF_FOR_TOTP_SETUP') return 'Verify your registered email';
  return 'Check your email';
}

function challengeIntroduction(challenge: AuthenticationChallenge): string {
  if (challenge.phase === 'SELECT_SECOND_FACTOR') {
    return 'Your role requires another approved factor. Password verification alone cannot sign you in.';
  }
  if (challenge.phase === 'TOTP_ENROLLMENT') {
    return challenge.primary_method === 'EMAIL_OTP'
      ? 'Your registered email was verified. Complete authenticator registration to continue.'
      : 'Your account password was verified. Complete authenticator registration to continue.';
  }
  if (challenge.phase === 'TOTP_SECOND') {
    return 'Enter your Google Authenticator code to complete the required second factor.';
  }
  if (challenge.phase === 'TOTP_PRIMARY') {
    return 'Enter the code from Google Authenticator or an unused recovery code.';
  }
  if (challenge.phase === 'EMAIL_PROOF_FOR_TOTP_SETUP') {
    return `Authenticator is not registered yet. First enter the code sent to ${challenge.email_hint}.`;
  }
  if (challenge.phase === 'PASSWORD_PROOF_FOR_TOTP_SETUP') {
    return 'Authenticator is not registered yet. Verify your account password before the setup QR code is displayed.';
  }
  if (challenge.phase === 'PASSWORD_SECOND_AFTER_TOTP') {
    return 'Your privileged role requires two independent factors. Verify your account password to finish signing in.';
  }
  if (challenge.phase === 'EMAIL_OTP_SECOND_AFTER_TOTP') {
    return `Your privileged role requires a second factor. Enter the code sent to ${challenge.email_hint}.`;
  }
  return `Enter the six-digit code sent to ${challenge.email_hint}.`;
}

function challengeCodeLabel(phase: AuthenticationChallenge['phase']): string {
  if (phase === 'TOTP_PRIMARY' || phase === 'TOTP_SECOND') return 'Google Authenticator or recovery code';
  if (phase === 'TOTP_ENROLLMENT') return 'Six-digit Google Authenticator code';
  return 'Six-digit email code';
}

function isPasswordChallenge(phase: AuthenticationChallenge['phase']): boolean {
  return phase === 'PASSWORD_PROOF_FOR_TOTP_SETUP' || phase === 'PASSWORD_SECOND_AFTER_TOTP';
}

function message(error: unknown, fallback: string): string {
  return isApiError(error) ? error.message : fallback;
}

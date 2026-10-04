import { useEffect, useMemo, useState, type FormEvent } from 'react';
import type {
  EmailOtpChallenge,
  MfaChallenge,
  PrimaryAuthMethod,
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

type LoginProps = {
  onPassword?: (email: string, password: string) => Promise<void> | void;
  onTotp?: (email: string, code: string) => Promise<void> | void;
  onEmailOtpRequest?: (email: string) => Promise<EmailOtpChallenge | null>;
  onEmailOtpVerify?: (challengeId: string, code: string) => Promise<void> | void;
  onMfa?: (method: SecondFactorMethod, code: string) => Promise<void> | void;
  onMfaEmailRequest?: () => Promise<EmailOtpChallenge | null>;
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

export default function ACC_LOGIN({
  onPassword,
  onTotp,
  onEmailOtpRequest,
  onEmailOtpVerify,
  onMfa,
  onMfaEmailRequest,
  onCancelMfa,
  mfaChallenge,
  onRetry,
  busy = false,
  error,
}: LoginProps = {}) {
  const parameters = new URLSearchParams(window.location.search);
  const initialFlow = validFlow(parameters.get('flow'));
  const [flow, setFlow] = useState<AccessFlow>(initialFlow);
  const [method, setMethod] = useState<PrimaryAuthMethod>('PASSWORD');
  const [email, setEmail] = useState(parameters.get('email') ?? '');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [code, setCode] = useState('');
  const [emailOtp, setEmailOtp] = useState<EmailOtpChallenge | null>(null);
  const [secondFactor, setSecondFactor] = useState<SecondFactorMethod>('EMAIL_OTP');
  const [secondFactorEmailSent, setSecondFactorEmailSent] = useState(false);
  const [token] = useState(parameters.get('token') ?? '');
  const [invitation, setInvitation] = useState<PublicInvitation | null>(null);
  const [localBusy, setLocalBusy] = useState(false);
  const [localError, setLocalError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const showDemoAccounts = import.meta.env.VITE_SHOW_DEMO_ACCOUNTS === 'true';

  const availableSecondFactors = useMemo(
    () => mfaChallenge?.methods ?? [],
    [mfaChallenge]
  );

  useEffect(() => {
    if (!mfaChallenge) {
      setSecondFactorEmailSent(false);
      return;
    }
    const preferred = mfaChallenge.methods.includes('TOTP')
      ? 'TOTP'
      : mfaChallenge.methods[0] ?? 'EMAIL_OTP';
    setSecondFactor(preferred);
    setCode('');
  }, [mfaChallenge]);

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

  const working = busy || localBusy;

  async function submitSignIn(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    clearFeedback();

    if (mfaChallenge) {
      if (secondFactor === 'EMAIL_OTP' && !secondFactorEmailSent) {
        await sendSecondFactorEmail();
        return;
      }
      void onMfa?.(secondFactor, code.trim());
      return;
    }

    if (method === 'PASSWORD') {
      void onPassword?.(email.trim(), password);
      return;
    }
    if (method === 'TOTP') {
      void onTotp?.(email.trim(), code.trim());
      return;
    }
    if (!emailOtp) {
      await sendPrimaryEmailOtp();
      return;
    }

    void onEmailOtpVerify?.(emailOtp.challenge_id, code.trim());
  }

  async function sendPrimaryEmailOtp() {
    setLocalBusy(true);
    clearFeedback();
    try {
      const result = await onEmailOtpRequest?.(email.trim().toLowerCase());
      if (result) {
        setEmailOtp(result);
        setNotice(result.message);
        if (result.preview_code) setCode(result.preview_code);
      }
    } finally {
      setLocalBusy(false);
    }
  }

  async function sendSecondFactorEmail() {
    setLocalBusy(true);
    clearFeedback();
    try {
      const result = await onMfaEmailRequest?.();
      if (result) {
        setSecondFactorEmailSent(true);
        setNotice(result.message);
        if (result.preview_code) setCode(result.preview_code);
      }
    } finally {
      setLocalBusy(false);
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

  function selectPrimaryMethod(next: PrimaryAuthMethod) {
    setMethod(next);
    setCode('');
    setEmailOtp(null);
    clearFeedback();
  }

  function selectSecondFactor(next: SecondFactorMethod) {
    setSecondFactor(next);
    setCode('');
    setSecondFactorEmailSent(false);
    clearFeedback();
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
    setEmailOtp(null);
  }

  function clearFeedback() {
    setLocalError(null);
    setNotice(null);
    setPreviewUrl(null);
  }

  const codeLabel = method === 'TOTP' ? 'Google Authenticator code' : 'Email verification code';

  return (
    <div className="auth-page auth-full-page">
      <section className="auth-brand">
        <div className="brand-lockup"><span className="brand-mark">Q&T</span><div><b>Q & T FOODS LTD</b><small>Business management</small></div></div>
        <div>
          <div className="eyebrow light">YOUR WORKSPACE</div>
          <h1>The right work.<br />For the right people.</h1>
          <p>Sign in securely to see only the companies, locations, and work assigned to your role.</p>
        </div>
        <small>Secure sign-in · protected account · role-based access · step-up MFA for privileged access</small>
      </section>
      <section className="auth-form">
        {flow === 'signin' && (
          <form className="auth-card" onSubmit={submitSignIn}>
            <div className="eyebrow">SECURE SIGN IN</div>
            <h2>{mfaChallenge ? 'Two-step verification' : 'Welcome back'}</h2>
            <p className="auth-intro">{mfaChallenge
              ? `Complete the required second factor. This challenge expires ${new Date(mfaChallenge.expires_at).toLocaleTimeString()}.`
              : 'Choose how you want to verify your work account.'}</p>

            {(error || localError) && (
              <div className="form-error" role="alert">
                <span>{error ?? localError}</span>
                {onRetry && <button type="button" onClick={() => void onRetry()}>Retry connection</button>}
              </div>
            )}
            {notice && <div className="form-success" role="status"><span aria-hidden="true">✓</span>{notice}</div>}

            {mfaChallenge ? <>
              <fieldset className="auth-methods">
                <legend>Second factor</legend>
                {availableSecondFactors.map((factor) => (
                  <button
                    type="button"
                    key={factor}
                    className={secondFactor === factor ? 'selected' : ''}
                    aria-pressed={secondFactor === factor}
                    onClick={() => selectSecondFactor(factor)}
                  >
                    {factor === 'EMAIL_OTP' ? 'Email OTP' : factor === 'TOTP' ? 'Google Authenticator' : 'Recovery code'}
                  </button>
                ))}
              </fieldset>

              {secondFactor === 'EMAIL_OTP' && !secondFactorEmailSent ? (
                <button className="primary" type="submit" disabled={working}>{working ? 'Sending…' : 'Send email OTP'}</button>
              ) : <>
                <label htmlFor="login-code">
                  {secondFactor === 'EMAIL_OTP' ? 'Email verification code' : secondFactor === 'TOTP' ? 'Google Authenticator code' : 'Recovery code'}
                </label>
                <input
                  id="login-code"
                  inputMode={secondFactor === 'RECOVERY_CODE' ? undefined : 'numeric'}
                  value={code}
                  onChange={(event) => setCode(event.target.value)}
                  autoComplete="one-time-code"
                  autoFocus
                  required
                />
                <button className="primary" type="submit" disabled={working || !code.trim()}>{working ? 'Verifying…' : 'Verify and sign in'}</button>
                {secondFactor === 'EMAIL_OTP' && (
                  <button className="auth-link centered" type="button" disabled={working} onClick={() => void sendSecondFactorEmail()}>Send a new email code</button>
                )}
              </>}
              <button className="auth-link centered" type="button" onClick={onCancelMfa}>Start over</button>
            </> : <>
              <fieldset className="auth-methods">
                <legend>Authentication method</legend>
                <button type="button" className={method === 'PASSWORD' ? 'selected' : ''} aria-pressed={method === 'PASSWORD'} onClick={() => selectPrimaryMethod('PASSWORD')}>Password</button>
                <button type="button" className={method === 'EMAIL_OTP' ? 'selected' : ''} aria-pressed={method === 'EMAIL_OTP'} onClick={() => selectPrimaryMethod('EMAIL_OTP')}>Email OTP</button>
                <button type="button" className={method === 'TOTP' ? 'selected' : ''} aria-pressed={method === 'TOTP'} onClick={() => selectPrimaryMethod('TOTP')}>Google Authenticator</button>
              </fieldset>

              <label htmlFor="login-email">Email</label>
              <input id="login-email" type="email" value={email} onChange={(event) => { setEmail(event.target.value); setEmailOtp(null); }} autoComplete="username" required />

              {method === 'PASSWORD' && <>
                <label htmlFor="login-password">Password</label>
                <input id="login-password" type="password" value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="current-password" required />
                <button className="primary" type="submit" disabled={working || !email.trim() || !password}>{working ? 'Signing in…' : 'Sign in'}</button>
              </>}

              {method === 'EMAIL_OTP' && <>
                {emailOtp && <>
                  <label htmlFor="primary-email-code">{codeLabel}</label>
                  <input id="primary-email-code" inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(event) => setCode(event.target.value)} required />
                </>}
                <button className="primary" type="submit" disabled={working || !email.trim() || (Boolean(emailOtp) && !code.trim())}>
                  {working ? 'Working…' : emailOtp ? 'Verify email OTP' : 'Send email OTP'}
                </button>
                {emailOtp && <button className="auth-link centered" type="button" disabled={working} onClick={() => void sendPrimaryEmailOtp()}>Send a new code</button>}
              </>}

              {method === 'TOTP' && <>
                <label htmlFor="primary-totp-code">{codeLabel}</label>
                <input id="primary-totp-code" inputMode="numeric" autoComplete="one-time-code" value={code} onChange={(event) => setCode(event.target.value)} required />
                <p className="fine">Enter the current six-digit code from Google Authenticator or another compatible TOTP app.</p>
                <button className="primary" type="submit" disabled={working || !email.trim() || !code.trim()}>{working ? 'Verifying…' : 'Verify authenticator'}</button>
              </>}

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
            {notice && <div className="form-success stacked-success" role="status"><span aria-hidden="true">✓</span><div>{notice}{previewUrl && <a href={previewUrl}>Open development email link</a>}</div></div>}
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

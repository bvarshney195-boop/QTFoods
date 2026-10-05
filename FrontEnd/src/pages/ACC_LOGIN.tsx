import { useEffect, useState, type FormEvent } from 'react';
import type {
  MfaChallenge,
  OtpRequestResult,
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
  onLogin?: (method: PrimaryAuthMethod, email: string, credential: string) => Promise<void> | void;
  onRequestEmailOtp?: (email: string) => Promise<OtpRequestResult | undefined> | OtpRequestResult | undefined;
  onMfa?: (method: SecondFactorMethod, code: string) => Promise<void> | void;
  onRequestMfaEmailOtp?: () => Promise<OtpRequestResult | undefined> | OtpRequestResult | undefined;
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

const showDemoAccounts = import.meta.env.DEV || import.meta.env.VITE_ALLOW_DEMO_LOGIN === 'true';

export default function ACC_LOGIN({
  onLogin,
  onRequestEmailOtp,
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
  const [email, setEmail] = useState(parameters.get('email') ?? '');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [code, setCode] = useState('');
  const [method, setMethod] = useState<PrimaryAuthMethod>('PASSWORD');
  const [secondFactor, setSecondFactor] = useState<SecondFactorMethod>('TOTP');
  const [otpSent, setOtpSent] = useState(false);
  const [token] = useState(parameters.get('token') ?? '');
  const [invitation, setInvitation] = useState<PublicInvitation | null>(null);
  const [localBusy, setLocalBusy] = useState(false);
  const [localError, setLocalError] = useState<string | null>(null);
  const [notice, setNotice] = useState<string | null>(null);
  const [previewUrl, setPreviewUrl] = useState<string | null>(null);
  const [previewCode, setPreviewCode] = useState<string | null>(null);

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
    const preferred = mfaChallenge.allowed_methods.includes('TOTP') ? 'TOTP' : mfaChallenge.allowed_methods[0];
    if (preferred) setSecondFactor(preferred);
    setCode('');
    setOtpSent(false);
    setNotice(null);
    setPreviewCode(null);
  }, [mfaChallenge]);

  const working = busy || localBusy;

  function chooseMethod(next: PrimaryAuthMethod) {
    setMethod(next);
    setPassword('');
    setCode('');
    setOtpSent(false);
    setLocalError(null);
    setNotice(null);
    setPreviewCode(null);
  }

  async function submitSignIn(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    clearFeedback();
    if (mfaChallenge) {
      await onMfa?.(secondFactor, code.trim());
      return;
    }
    if (method === 'EMAIL_OTP' && !otpSent) {
      await sendPrimaryOtp();
      return;
    }
    const credential = method === 'PASSWORD' ? password : code.trim();
    await onLogin?.(method, email.trim().toLowerCase(), credential);
  }

  async function sendPrimaryOtp() {
    if (!email.trim()) {
      setLocalError('Enter your work email before requesting a one-time code.');
      return;
    }
    setLocalBusy(true);
    clearFeedback();
    try {
      const result = await onRequestEmailOtp?.(email.trim().toLowerCase());
      setOtpSent(true);
      setNotice(result?.message ?? 'If the account is eligible, a one-time code has been sent.');
      setPreviewCode(result?.delivery?.preview_code ?? null);
    } catch (caught) {
      setLocalError(message(caught, 'Unable to send the email code.'));
    } finally {
      setLocalBusy(false);
    }
  }

  async function sendMfaEmailOtp() {
    setLocalBusy(true);
    clearFeedback();
    try {
      const result = await onRequestMfaEmailOtp?.();
      setOtpSent(true);
      setNotice(result?.message ?? 'A one-time verification code has been sent.');
      setPreviewCode(result?.delivery?.preview_code ?? null);
    } catch (caught) {
      setLocalError(message(caught, 'Unable to send the verification code.'));
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
    setPreviewCode(null);
    setCode('');
  }

  function clearFeedback() {
    setLocalError(null);
    setNotice(null);
    setPreviewUrl(null);
    setPreviewCode(null);
  }

  const challengeMethods = mfaChallenge?.allowed_methods ?? [];

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
              ? `Primary sign-in verified with ${methodLabel(mfaChallenge.primary_method)}. Complete a different approved second factor before access is granted.`
              : 'Choose a sign-in method. Privileged roles always require an approved second factor.'}</p>

            {(error || localError) && (
              <div className="form-error" role="alert">
                <span>{error ?? localError}</span>
                {onRetry && <button type="button" onClick={() => void onRetry()}>Retry connection</button>}
              </div>
            )}
            {notice && <div className="form-success stacked-success" role="status"><span /><div>{notice}{previewCode && <code className="otp-preview-code">Development code: {previewCode}</code>}</div></div>}

            {mfaChallenge ? <>
              <div className="auth-methods" role="group" aria-label="Second-factor method">
                {challengeMethods.includes('TOTP') && <button type="button" className={secondFactor === 'TOTP' ? 'active' : ''} aria-pressed={secondFactor === 'TOTP'} onClick={() => { setSecondFactor('TOTP'); setCode(''); setOtpSent(false); clearFeedback(); }}>Google Authenticator</button>}
                {challengeMethods.includes('EMAIL_OTP') && <button type="button" className={secondFactor === 'EMAIL_OTP' ? 'active' : ''} aria-pressed={secondFactor === 'EMAIL_OTP'} onClick={() => { setSecondFactor('EMAIL_OTP'); setCode(''); setOtpSent(false); clearFeedback(); }}>Email OTP</button>}
              </div>
              {secondFactor === 'EMAIL_OTP' && !otpSent ? (
                <button className="secondary auth-full-button" type="button" disabled={working} onClick={() => void sendMfaEmailOtp()}>{working ? 'Sending…' : 'Send verification code'}</button>
              ) : <>
                <label htmlFor="login-code">{secondFactor === 'TOTP' ? 'Authenticator or recovery code' : 'Email verification code'}</label>
                <input id="login-code" inputMode={secondFactor === 'EMAIL_OTP' ? 'numeric' : undefined} value={code} onChange={(event) => setCode(event.target.value)} autoComplete="one-time-code" autoFocus required />
                <button className="primary" type="submit" disabled={working || !code.trim()}>{working ? 'Verifying…' : 'Verify and sign in'}</button>
                {secondFactor === 'EMAIL_OTP' && <button className="auth-link centered" type="button" disabled={working} onClick={() => void sendMfaEmailOtp()}>Send a new code</button>}
              </>}
              <button className="auth-link centered" type="button" onClick={onCancelMfa}>Back to sign in</button>
            </> : <>
              <div className="auth-methods" role="group" aria-label="Sign-in method">
                <button type="button" className={method === 'PASSWORD' ? 'active' : ''} aria-pressed={method === 'PASSWORD'} onClick={() => chooseMethod('PASSWORD')}>Password</button>
                <button type="button" className={method === 'EMAIL_OTP' ? 'active' : ''} aria-pressed={method === 'EMAIL_OTP'} onClick={() => chooseMethod('EMAIL_OTP')}>Email OTP</button>
                <button type="button" className={method === 'TOTP' ? 'active' : ''} aria-pressed={method === 'TOTP'} onClick={() => chooseMethod('TOTP')}>Google Authenticator</button>
              </div>

              <label htmlFor="login-email">Email</label>
              <input id="login-email" type="email" value={email} onChange={(event) => { setEmail(event.target.value); if (method === 'EMAIL_OTP') setOtpSent(false); }} autoComplete="username" required />

              {method === 'PASSWORD' && <>
                <label htmlFor="login-password">Password</label>
                <input id="login-password" type="password" value={password} onChange={(event) => setPassword(event.target.value)} autoComplete="current-password" required />
                <button className="primary" type="submit" disabled={working || !email.trim() || !password}>{working ? 'Signing in…' : 'Continue securely'}</button>
              </>}

              {method === 'EMAIL_OTP' && (!otpSent ? (
                <button className="primary" type="submit" disabled={working || !email.trim()}>{working ? 'Sending…' : 'Send one-time code'}</button>
              ) : <>
                <label htmlFor="login-email-code">Email one-time code</label>
                <input id="login-email-code" inputMode="numeric" pattern="[0-9]{6}" maxLength={6} value={code} onChange={(event) => setCode(event.target.value.replace(/\D/g, '').slice(0, 6))} autoComplete="one-time-code" required />
                <button className="primary" type="submit" disabled={working || code.length !== 6}>{working ? 'Signing in…' : 'Continue securely'}</button>
                <button className="auth-link centered" type="button" disabled={working} onClick={() => void sendPrimaryOtp()}>Send a new code</button>
              </>)}

              {method === 'TOTP' && <>
                <label htmlFor="login-totp">Google Authenticator code</label>
                <input id="login-totp" inputMode="numeric" pattern="[0-9]{6}" maxLength={6} value={code} onChange={(event) => setCode(event.target.value.replace(/\D/g, '').slice(0, 6))} autoComplete="one-time-code" required />
                <p className="fine">This method is available only after Google Authenticator has been enrolled for the account.</p>
                <button className="primary" type="submit" disabled={working || !email.trim() || code.length !== 6}>{working ? 'Signing in…' : 'Continue securely'}</button>
              </>}

              <div className="auth-links">
                <button type="button" onClick={() => switchFlow('forgot')}>Forgot password?</button>
                <button type="button" onClick={() => switchFlow('verification')}>Resend verification</button>
              </div>

              {showDemoAccounts && <div className="demo-accounts">
                <span>Development/UAT demo identities only. Production must disable demo login.</span>
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

function methodLabel(value: PrimaryAuthMethod): string {
  return value === 'PASSWORD' ? 'password' : value === 'EMAIL_OTP' ? 'email OTP' : 'Google Authenticator';
}

function message(error: unknown, fallback: string): string {
  return isApiError(error) ? error.message : fallback;
}

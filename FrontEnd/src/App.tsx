import { useEffect, useState } from 'react';
import {
  completeMfaChallenge,
  currentSession,
  isApiError,
  isMfaChallenge,
  login,
  loginWithTotp,
  logout,
  requestEmailOtp,
  requestMfaEmailOtp,
  resetAuthenticationClient,
  selectContext,
  verifyEmailOtp,
  type EmailOtpChallenge,
  type MfaChallenge,
  type SecondFactorMethod,
} from './api/auth';
import AppShell from './app/AppShell';
import ACC_CTX from './pages/ACC_CTX';
import ACC_LOGIN from './pages/ACC_LOGIN';
import type { ErpSession } from './types/session';

export default function App() {
  const [session, setSession] = useState<ErpSession | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [choosingContext, setChoosingContext] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [mfaChallenge, setMfaChallenge] = useState<MfaChallenge | null>(null);
  const [emailOtpChallenge, setEmailOtpChallenge] = useState<EmailOtpChallenge | null>(null);

  useEffect(() => {
    const handleSessionExpired = () => {
      resetAuthenticationClient();
      setSession(null);
      setChoosingContext(false);
      setMfaChallenge(null);
      setEmailOtpChallenge(null);
      setError('Your ERP session expired. Sign in again to continue.');
    };
    const handleContextRequired = () => void restoreSession();
    const handleSessionRefresh = () => {
      currentSession()
        .then((refreshed) => setSession(refreshed))
        .catch((caught) => setError(isApiError(caught) ? caught.message : 'Unable to refresh the ERP session.'));
    };

    window.addEventListener('erp:session-expired', handleSessionExpired);
    window.addEventListener('erp:context-required', handleContextRequired);
    window.addEventListener('erp:session-refresh', handleSessionRefresh);
    void restoreSession();

    return () => {
      window.removeEventListener('erp:session-expired', handleSessionExpired);
      window.removeEventListener('erp:context-required', handleContextRequired);
      window.removeEventListener('erp:session-refresh', handleSessionRefresh);
    };
  }, []);

  async function restoreSession() {
    setLoading(true);
    setError(null);
    try {
      const restored = await currentSession();
      setSession(restored);
      setChoosingContext(!restored.selected_context);
    } catch (caught) {
      if (!isApiError(caught) || caught.code !== 'UNAUTHENTICATED') {
        setError(isApiError(caught) ? caught.message : 'Unable to contact the ERP service.');
      }
      setSession(null);
    } finally {
      setLoading(false);
    }
  }

  async function handlePasswordLogin(email: string, password: string) {
    await runPrimary(async () => login(email, password));
  }

  async function handleTotpLogin(email: string, password: string, code: string) {
    await runPrimary(async () => loginWithTotp(email, password, code));
  }

  async function handleEmailOtpRequest(email: string) {
    setBusy(true);
    setError(null);
    try {
      setEmailOtpChallenge(await requestEmailOtp(email));
    } catch (caught) {
      setError(isApiError(caught) ? caught.message : 'Unable to send the email code.');
    } finally {
      setBusy(false);
    }
  }

  async function handleEmailOtpVerify(email: string, challengeId: string, code: string) {
    await runPrimary(async () => verifyEmailOtp(email, challengeId, code));
  }

  async function runPrimary(action: () => Promise<ErpSession | MfaChallenge>) {
    setBusy(true);
    setError(null);
    try {
      const authenticated = await action();
      if (isMfaChallenge(authenticated)) {
        setMfaChallenge(authenticated);
        setEmailOtpChallenge(null);
        return;
      }
      finishAuthentication(authenticated);
    } catch (caught) {
      setError(isApiError(caught) ? caught.message : 'Sign in failed.');
    } finally {
      setBusy(false);
    }
  }

  async function handleMfaEmailOtpRequest() {
    if (!mfaChallenge) return;
    setBusy(true);
    setError(null);
    try {
      setEmailOtpChallenge(await requestMfaEmailOtp(mfaChallenge.challenge_id));
    } catch (caught) {
      setError(isApiError(caught) ? caught.message : 'Unable to send the second-factor email code.');
    } finally {
      setBusy(false);
    }
  }

  async function handleMfa(method: SecondFactorMethod, code: string) {
    if (!mfaChallenge) return;
    setBusy(true);
    setError(null);
    try {
      const authenticated = await completeMfaChallenge(mfaChallenge.challenge_id, method, code);
      finishAuthentication(authenticated);
    } catch (caught) {
      setError(isApiError(caught) ? caught.message : 'Two-step verification failed.');
    } finally {
      setBusy(false);
    }
  }

  function finishAuthentication(authenticated: ErpSession) {
    setMfaChallenge(null);
    setEmailOtpChallenge(null);
    setSession(authenticated);
    setChoosingContext(true);
  }

  async function handleContext(companyId: string, plantId: string | null) {
    setBusy(true);
    setError(null);
    try {
      const updated = await selectContext(companyId, plantId);
      setSession(updated);
      setChoosingContext(false);
      setMfaChallenge(null);
      window.location.hash = 'WRK-HOME';
    } catch (caught) {
      setError(isApiError(caught) ? caught.message : 'Context selection failed.');
    } finally {
      setBusy(false);
    }
  }

  async function handleLogout() {
    setBusy(true);
    setError(null);
    try {
      await logout();
    } finally {
      setSession(null);
      setChoosingContext(false);
      setBusy(false);
      window.location.hash = '';
    }
  }

  if (loading) {
    return <div className="app-loading" aria-busy="true"><span className="loading-mark">Q&T</span><p>Opening secure ERP session…</p></div>;
  }

  if (!session) {
    return (
      <ACC_LOGIN
        onPasswordLogin={handlePasswordLogin}
        onTotpLogin={handleTotpLogin}
        onEmailOtpRequest={handleEmailOtpRequest}
        onEmailOtpVerify={handleEmailOtpVerify}
        emailOtpChallenge={emailOtpChallenge}
        onMfa={handleMfa}
        onMfaEmailOtpRequest={handleMfaEmailOtpRequest}
        onCancelMfa={() => { setMfaChallenge(null); setEmailOtpChallenge(null); setError(null); }}
        mfaChallenge={mfaChallenge}
        busy={busy}
        error={error}
        onRetry={error === 'Unable to contact the ERP service.' ? restoreSession : undefined}
      />
    );
  }

  if (!session.selected_context || choosingContext) {
    return (
      <ACC_CTX
        session={session}
        onSelect={handleContext}
        onCancel={session.selected_context ? () => { setChoosingContext(false); setError(null); } : undefined}
        onLogout={handleLogout}
        busy={busy}
        error={error}
      />
    );
  }

  return (
    <AppShell
      session={session}
      onChooseContext={() => { setChoosingContext(true); setError(null); }}
      onLogout={handleLogout}
    />
  );
}

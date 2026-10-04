import { useEffect, useState } from 'react';
import {
  completeMfaChallenge,
  currentSession,
  isApiError,
  isMfaChallenge,
  login,
  loginWithAuthenticator,
  loginWithEmailOtp,
  logout,
  requestEmailOtp,
  requestMfaEmailOtp,
  resetAuthenticationClient,
  selectContext,
  type AuthMethod,
  type EmailOtpChallenge,
  type MfaChallenge,
  type MfaMethod,
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
    const handleContextRequired = () => {
      void restoreSession();
    };
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
      setEmailOtpChallenge(null);
    } finally {
      setLoading(false);
    }
  }

  function acceptAuthentication(authenticated: Awaited<ReturnType<typeof login>>) {
    if (isMfaChallenge(authenticated)) {
      setMfaChallenge(authenticated);
      setEmailOtpChallenge(null);
      return;
    }
    setMfaChallenge(null);
    setEmailOtpChallenge(null);
    setSession(authenticated);
    setChoosingContext(true);
  }

  async function handlePrimaryAuth(method: AuthMethod, email: string, credential: string) {
    setBusy(true);
    setError(null);
    try {
      if (method === 'PASSWORD') {
        acceptAuthentication(await login(email, credential));
      } else if (method === 'AUTHENTICATOR') {
        acceptAuthentication(await loginWithAuthenticator(email, credential));
      } else if (emailOtpChallenge) {
        acceptAuthentication(await loginWithEmailOtp(emailOtpChallenge.challenge_id, credential));
      }
    } catch (caught) {
      setError(isApiError(caught) ? caught.message : 'Sign in failed.');
    } finally {
      setBusy(false);
    }
  }

  async function handleEmailOtpRequest(email: string) {
    setBusy(true);
    setError(null);
    try {
      setEmailOtpChallenge(await requestEmailOtp(email));
    } catch (caught) {
      setError(isApiError(caught) ? caught.message : 'Unable to send the email sign-in code.');
    } finally {
      setBusy(false);
    }
  }

  async function handleMfa(method: MfaMethod, code: string) {
    if (!mfaChallenge) return;
    setBusy(true);
    setError(null);
    try {
      const authenticated = await completeMfaChallenge(mfaChallenge.challenge_id, method, code);
      setMfaChallenge(null);
      setEmailOtpChallenge(null);
      setSession(authenticated);
      setChoosingContext(true);
    } catch (caught) {
      setError(isApiError(caught) ? caught.message : 'Two-step verification failed.');
    } finally {
      setBusy(false);
    }
  }

  async function handleMfaEmailOtpRequest() {
    if (!mfaChallenge) return;
    setBusy(true);
    setError(null);
    try {
      await requestMfaEmailOtp(mfaChallenge.challenge_id);
    } catch (caught) {
      setError(isApiError(caught) ? caught.message : 'Unable to send the verification code.');
    } finally {
      setBusy(false);
    }
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
      setMfaChallenge(null);
      setEmailOtpChallenge(null);
      setBusy(false);
      window.location.hash = '';
    }
  }

  if (loading) {
    return <div className="app-loading"><span className="loading-mark">Q&T</span><p>Opening secure ERP session…</p></div>;
  }

  if (!session) {
    return (
      <ACC_LOGIN
        onPrimaryAuth={handlePrimaryAuth}
        onRequestEmailOtp={handleEmailOtpRequest}
        emailOtpChallenge={emailOtpChallenge}
        onMfa={handleMfa}
        onRequestMfaEmailOtp={handleMfaEmailOtpRequest}
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

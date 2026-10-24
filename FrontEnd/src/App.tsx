import { useEffect, useState } from 'react';
import {
  continueAuthentication,
  currentSession,
  isApiError,
  isAuthenticationChallenge,
  login,
  logout,
  resetAuthenticationClient,
  selectContext,
  type AuthenticationAction,
  type AuthenticationChallenge,
  type AuthenticationMethod,
} from './api/auth';
import AppShell from './app/AppShell';
import ACC_CTX from './pages/ACC_CTX';
import ACC_LOGIN from './pages/ACC_LOGIN';
import { RequiredPasswordChange } from './components/RequiredPasswordChange';
import { changePassword } from './api/identity';
import type { ErpSession } from './types/session';

export default function App() {
  const [session, setSession] = useState<ErpSession | null>(null);
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [choosingContext, setChoosingContext] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [authChallenge, setAuthChallenge] = useState<AuthenticationChallenge | null>(null);

  useEffect(() => {
    const handleSessionExpired = () => {
      resetAuthenticationClient();
      setSession(null);
      setChoosingContext(false);
      setAuthChallenge(null);
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
    } finally {
      setLoading(false);
    }
  }

  async function handleLogin(email: string, method: AuthenticationMethod, password?: string) {
    setBusy(true);
    setError(null);

    try {
      const authenticated = await login(email, method, password);
      if (isAuthenticationChallenge(authenticated)) {
        setAuthChallenge(authenticated);
        return;
      }
      setAuthChallenge(null);
      setSession(authenticated);
      setChoosingContext(true);
    } catch (caught) {
      setError(isApiError(caught) ? caught.message : 'Sign in failed.');
    } finally {
      setBusy(false);
    }
  }

  async function handleChallenge(action: AuthenticationAction, code?: string) {
    if (!authChallenge) return;
    setBusy(true);
    setError(null);
    try {
      const authenticated = await continueAuthentication(authChallenge.challenge_id, action, code);
      if (isAuthenticationChallenge(authenticated)) {
        setAuthChallenge(authenticated);
        return;
      }
      setAuthChallenge(null);
      setSession(authenticated);
      setChoosingContext(true);
    } catch (caught) {
      setError(isApiError(caught) ? caught.message : 'Sign-in verification failed.');
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
      setAuthChallenge(null);
      window.location.hash = 'WRK-HOME';
    } catch (caught) {
      setError(isApiError(caught) ? caught.message : 'Context selection failed.');
    } finally {
      setBusy(false);
    }
  }

  async function handleRequiredPasswordChange(currentPassword: string, password: string, confirmation: string) {
    setBusy(true);
    setError(null);
    try {
      await changePassword(currentPassword, password, confirmation);
      const refreshed = await currentSession();
      setSession(refreshed);
      setChoosingContext(!refreshed.selected_context);
    } catch (caught) {
      setError(isApiError(caught) ? caught.message : 'Unable to replace the temporary password.');
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
    return <div className="app-loading"><span className="loading-mark">Q&T</span><p>Opening secure ERP session…</p></div>;
  }

  if (!session) {
    return (
      <ACC_LOGIN
        onLogin={handleLogin}
        onChallenge={handleChallenge}
        onCancelChallenge={() => { setAuthChallenge(null); setError(null); }}
        onClearError={() => setError(null)}
        authChallenge={authChallenge}
        busy={busy}
        error={error}
        onRetry={error === 'Unable to contact the ERP service.' ? restoreSession : undefined}
      />
    );
  }

  if (session.security?.password_change_required) {
    return (
      <RequiredPasswordChange
        session={session}
        busy={busy}
        error={error}
        onSubmit={handleRequiredPasswordChange}
        onLogout={handleLogout}
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

import {
  apiMutation,
  apiRequest,
  isApiError,
  resetApiClientAuthentication,
} from './client';
import type { ErpSession } from '../types/session';

type DataEnvelope<T> = { data: T };

export type AuthMethod = 'PASSWORD' | 'EMAIL_OTP' | 'AUTHENTICATOR';
export type MfaMethod = 'TOTP' | 'EMAIL_OTP' | 'RECOVERY_CODE';

export type MfaChallenge = {
  mfa_required: true;
  challenge_id: string;
  expires_at: string;
  primary_method: AuthMethod;
  allowed_methods: MfaMethod[];
};

export type EmailOtpChallenge = {
  challenge_id: string;
  expires_at: string;
  message: string;
  delivery?: {
    channel: string;
    status: string;
    preview_code?: string;
  };
};

export type LoginResult = ErpSession | MfaChallenge;

export { isApiError };

export async function currentSession(): Promise<ErpSession> {
  return (await apiRequest<DataEnvelope<ErpSession>>('/api/v1/me')).data;
}

export async function login(email: string, password: string): Promise<LoginResult> {
  return (await apiMutation<DataEnvelope<LoginResult>>('/api/v1/auth/login', {
    email,
    password,
  })).data;
}

export async function requestEmailOtp(email: string): Promise<EmailOtpChallenge> {
  return (await apiMutation<DataEnvelope<EmailOtpChallenge>>('/api/v1/auth/email-otp/request', {
    email,
  })).data;
}

export async function loginWithEmailOtp(challengeId: string, code: string): Promise<LoginResult> {
  return (await apiMutation<DataEnvelope<LoginResult>>('/api/v1/auth/email-otp/login', {
    challenge_id: challengeId,
    code,
  })).data;
}

export async function loginWithAuthenticator(email: string, code: string): Promise<LoginResult> {
  return (await apiMutation<DataEnvelope<LoginResult>>('/api/v1/auth/totp/login', {
    email,
    code,
  })).data;
}

export async function requestMfaEmailOtp(challengeId: string): Promise<EmailOtpChallenge> {
  return (await apiMutation<DataEnvelope<EmailOtpChallenge>>('/api/v1/auth/mfa/email-otp/request', {
    challenge_id: challengeId,
  })).data;
}

export async function completeMfaChallenge(
  challengeId: string,
  method: MfaMethod,
  code: string,
): Promise<ErpSession> {
  return (await apiMutation<DataEnvelope<ErpSession>>('/api/v1/auth/mfa/challenge', {
    challenge_id: challengeId,
    method,
    code,
  })).data;
}

export function isMfaChallenge(result: LoginResult): result is MfaChallenge {
  return 'mfa_required' in result && result.mfa_required === true;
}

export async function selectContext(companyId: string, plantId: string | null): Promise<ErpSession> {
  return (await apiMutation<DataEnvelope<ErpSession>>('/api/v1/contexts/select', {
    company_id: companyId,
    plant_id: plantId,
  })).data;
}

export async function logout(): Promise<void> {
  await apiMutation<DataEnvelope<{ logged_out: boolean }>>('/api/v1/auth/logout', {});
  resetAuthenticationClient();
}

export function resetAuthenticationClient(): void {
  resetApiClientAuthentication();
}

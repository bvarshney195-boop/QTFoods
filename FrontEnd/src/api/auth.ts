import {
  apiMutation,
  apiRequest,
  isApiError,
  resetApiClientAuthentication,
} from './client';
import type { ErpSession } from '../types/session';

type DataEnvelope<T> = { data: T };

export type PrimaryAuthMethod = 'PASSWORD' | 'EMAIL_OTP' | 'TOTP';
export type SecondFactorMethod = 'EMAIL_OTP' | 'TOTP';

export type MfaChallenge = {
  mfa_required: true;
  challenge_id: string;
  expires_at: string;
  primary_method: PrimaryAuthMethod;
  allowed_methods: SecondFactorMethod[];
};

export type LoginResult = ErpSession | MfaChallenge;

export type OtpRequestResult = {
  accepted: true;
  message: string;
  expires_at?: string;
  delivery?: {
    channel: 'EMAIL';
    status: 'SENT' | 'FAILED';
    preview_code?: string;
    preview_url?: string;
  };
};

export { isApiError };

export async function currentSession(): Promise<ErpSession> {
  return (await apiRequest<DataEnvelope<ErpSession>>('/api/v1/me')).data;
}

export async function login(email: string, password: string): Promise<LoginResult> {
  return loginWithPassword(email, password);
}

export async function loginWithPassword(email: string, password: string): Promise<LoginResult> {
  return (await apiMutation<DataEnvelope<LoginResult>>('/api/v1/auth/login', {
    email,
    password,
  })).data;
}

export async function requestLoginEmailOtp(email: string): Promise<OtpRequestResult> {
  return (await apiMutation<DataEnvelope<OtpRequestResult>>('/api/v1/auth/email-otp/request', {
    email,
  })).data;
}

export async function verifyLoginEmailOtp(email: string, code: string): Promise<LoginResult> {
  return (await apiMutation<DataEnvelope<LoginResult>>('/api/v1/auth/email-otp/verify', {
    email,
    code,
  })).data;
}

export async function loginWithTotp(email: string, code: string): Promise<LoginResult> {
  return (await apiMutation<DataEnvelope<LoginResult>>('/api/v1/auth/totp/login', {
    email,
    code,
  })).data;
}

export async function requestMfaEmailOtp(challengeId: string): Promise<OtpRequestResult> {
  return (await apiMutation<DataEnvelope<OtpRequestResult>>('/api/v1/auth/mfa/email-otp', {
    challenge_id: challengeId,
  })).data;
}

export async function completeMfaChallenge(
  challengeId: string,
  code: string,
  method: SecondFactorMethod = 'TOTP',
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

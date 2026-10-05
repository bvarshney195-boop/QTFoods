import {
  apiMutation,
  apiRequest,
  isApiError,
  resetApiClientAuthentication,
} from './client';
import type { ErpSession } from '../types/session';

type DataEnvelope<T> = { data: T };

export type AuthenticationMethod = 'password' | 'email_otp' | 'totp';
export type AuthenticationAction =
  | 'select_email_otp'
  | 'select_totp'
  | 'verify_email_otp'
  | 'verify_totp'
  | 'resend_email_otp'
  | 'confirm_totp_setup';

export type AuthenticationChallenge = {
  authentication_required: true;
  mfa_required: true;
  challenge_id: string;
  phase:
    | 'SELECT_SECOND_FACTOR'
    | 'EMAIL_OTP_PRIMARY'
    | 'EMAIL_OTP_SECOND'
    | 'EMAIL_OTP_SECOND_AFTER_TOTP'
    | 'EMAIL_PROOF_FOR_TOTP_SETUP'
    | 'TOTP_PRIMARY'
    | 'TOTP_SECOND'
    | 'TOTP_ENROLLMENT';
  primary_method: 'PASSWORD' | 'EMAIL_OTP' | 'TOTP';
  expires_at: string;
  email_hint: string;
  totp_registered: boolean;
  available_methods: AuthenticationMethod[];
  setup?: {
    secret: string;
    otpauth_uri: string;
    expires_at: string;
  };
  delivery?: {
    channel: 'EMAIL';
    status: string;
    preview_code?: string;
  };
};

/** Kept as an alias for older imports while the UI migrates to the richer flow. */
export type MfaChallenge = AuthenticationChallenge;
export type LoginResult = ErpSession | AuthenticationChallenge;

export { isApiError };

export async function currentSession(): Promise<ErpSession> {
  return (await apiRequest<DataEnvelope<ErpSession>>('/api/v1/me')).data;
}

export async function login(
  email: string,
  method: AuthenticationMethod,
  password?: string,
): Promise<LoginResult> {
  return (await apiMutation<DataEnvelope<LoginResult>>('/api/v1/auth/login', {
    email,
    method,
    ...(method === 'password' ? { password: password ?? '' } : {}),
  })).data;
}

export async function continueAuthentication(
  challengeId: string,
  action: AuthenticationAction,
  code?: string,
): Promise<LoginResult> {
  return (await apiMutation<DataEnvelope<LoginResult>>('/api/v1/auth/challenge', {
    challenge_id: challengeId,
    action,
    ...(code ? { code } : {}),
  })).data;
}

export function isAuthenticationChallenge(result: LoginResult): result is AuthenticationChallenge {
  return 'authentication_required' in result && result.authentication_required === true;
}

export const isMfaChallenge = isAuthenticationChallenge;

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

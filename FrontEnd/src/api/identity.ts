import { apiMutation, apiRequest } from './client';

type DataEnvelope<T> = { data: T };

export type IdentityDelivery = {
  channel: 'EMAIL';
  status: 'SENT' | 'FAILED';
  preview_url?: string;
};

export type PublicInvitation = {
  id: string;
  email: string;
  name: string;
  company_name: string;
  plant_name: string;
  expires_at: string;
};

export type IdentityRequestResult = {
  accepted: true;
  message: string;
  delivery?: IdentityDelivery;
};

export type MfaSetup = {
  secret: string;
  otpauth_uri: string;
  expires_at: string;
};

export type DeviceSession = {
  id: string;
  ip_address: string | null;
  user_agent: string | null;
  last_seen_at: string;
  expires_at: string;
  revoked_at: string | null;
  revoke_reason: string | null;
  status: 'ACTIVE' | 'REVOKED' | 'EXPIRED';
  current: boolean;
  record_version: number;
};

export type DeviceSessionWorkspace = {
  data: DeviceSession[];
  summary: { total: number; active: number };
};

export async function getInvitation(token: string): Promise<PublicInvitation> {
  return (await apiRequest<DataEnvelope<PublicInvitation>>(
    `/api/v1/auth/invitations/${encodeURIComponent(token)}`
  )).data;
}

export async function acceptInvitation(token: string, password: string, confirmation: string) {
  return (await apiMutation<DataEnvelope<{ accepted: true; email: string }>>(
    '/api/v1/auth/invitations/accept',
    { token, password, password_confirmation: confirmation }
  )).data;
}

export async function forgotPassword(email: string): Promise<IdentityRequestResult> {
  return (await apiMutation<DataEnvelope<IdentityRequestResult>>('/api/v1/auth/password/forgot', { email })).data;
}

export async function resetPassword(email: string, token: string, password: string, confirmation: string) {
  return (await apiMutation<DataEnvelope<{ reset: true }>>('/api/v1/auth/password/reset', {
    email, token, password, password_confirmation: confirmation,
  })).data;
}

export async function requestEmailVerification(email: string): Promise<IdentityRequestResult> {
  return (await apiMutation<DataEnvelope<IdentityRequestResult>>('/api/v1/auth/email/verification/request', {
    email,
  })).data;
}

export async function verifyEmail(email: string, token: string) {
  return (await apiMutation<DataEnvelope<{ verified: true; email: string }>>('/api/v1/auth/email/verify', {
    email, token,
  })).data;
}

export async function changePassword(currentPassword: string, password: string, confirmation: string) {
  return (await apiMutation<DataEnvelope<{ changed: true; other_sessions_revoked: number }>>(
    '/api/v1/auth/password/change',
    { current_password: currentPassword, password, password_confirmation: confirmation }
  )).data;
}

export async function beginMfaSetup(currentPassword: string): Promise<MfaSetup> {
  return (await apiMutation<DataEnvelope<MfaSetup>>('/api/v1/auth/mfa/setup', {
    current_password: currentPassword,
  })).data;
}

export async function confirmMfa(code: string) {
  return (await apiMutation<DataEnvelope<{ enabled: true; recovery_codes: string[] }>>(
    '/api/v1/auth/mfa/confirm', { code }
  )).data;
}

export async function disableMfa(currentPassword: string, code: string) {
  return (await apiMutation<DataEnvelope<{ enabled: false }>>('/api/v1/auth/mfa/disable', {
    current_password: currentPassword, code,
  })).data;
}

export async function regenerateRecoveryCodes(currentPassword: string, code: string) {
  return (await apiMutation<DataEnvelope<{ recovery_codes: string[] }>>(
    '/api/v1/auth/mfa/recovery-codes',
    { current_password: currentPassword, code }
  )).data;
}

export async function listDeviceSessions(): Promise<DeviceSessionWorkspace> {
  return apiRequest<DeviceSessionWorkspace>('/api/v1/auth/sessions');
}

export async function revokeDeviceSession(sessionId: string) {
  return (await apiMutation<DataEnvelope<{ id: string; revoked: true; current: boolean }>>(
    `/api/v1/auth/sessions/${sessionId}/revoke`, {}
  )).data;
}

export async function revokeOtherDeviceSessions() {
  return (await apiMutation<DataEnvelope<{ revoked_count: number }>>(
    '/api/v1/auth/sessions/revoke-others', {}
  )).data;
}

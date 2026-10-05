import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ErpSession } from '../types/session';
import { AccountSecurityPanel } from './AccountSecurityPanel';

const identityMocks = vi.hoisted(() => ({
  beginMfaSetup: vi.fn(),
  changePassword: vi.fn(),
  confirmMfa: vi.fn(),
  disableMfa: vi.fn(),
  listDeviceSessions: vi.fn(),
  regenerateRecoveryCodes: vi.fn(),
  requestEmailVerification: vi.fn(),
  revokeDeviceSession: vi.fn(),
  revokeOtherDeviceSessions: vi.fn(),
}));

vi.mock('../api/identity', async () => {
  const actual = await vi.importActual<typeof import('../api/identity')>('../api/identity');
  return { ...actual, ...identityMocks };
});

describe('account security panel', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    identityMocks.listDeviceSessions.mockResolvedValue({
      data: [{
        id: 'session-1', ip_address: '127.0.0.1', user_agent: 'Mozilla Chrome/140',
        last_seen_at: '2026-09-09T10:00:00Z', expires_at: '2026-09-09T12:00:00Z',
        revoked_at: null, revoke_reason: null, status: 'ACTIVE', current: true, record_version: 1,
      }],
      summary: { total: 1, active: 1 },
    });
  });

  it('guides MFA setup and shows recovery codes only after confirmation', async () => {
    identityMocks.beginMfaSetup.mockResolvedValue({
      secret: 'JBSWY3DPEHPK3PXP',
      otpauth_uri: 'otpauth://totp/QT?secret=JBSWY3DPEHPK3PXP',
      expires_at: '2026-09-09T10:10:00Z',
    });
    identityMocks.confirmMfa.mockResolvedValue({
      enabled: true,
      recovery_codes: ['AAAA-BBBB-CCCC', 'DDDD-EEEE-FFFF'],
    });
    render(<AccountSecurityPanel session={session()} onClose={vi.fn()} />);

    const mfaSection = screen.getByText('Multi-factor authentication').closest('section');
    expect(mfaSection).not.toBeNull();
    await userEvent.setup().type(within(mfaSection!).getByLabelText('Current password'), 'prototype');
    await userEvent.setup().click(within(mfaSection!).getByRole('button', { name: 'Set up MFA' }));
    expect(await screen.findByText('JBSWY3DPEHPK3PXP')).toBeInTheDocument();
    expect(screen.queryByLabelText('One-time MFA recovery codes')).not.toBeInTheDocument();

    await userEvent.setup().type(screen.getByLabelText('MFA setup code'), '123456');
    await userEvent.setup().click(screen.getByRole('button', { name: 'Enable MFA' }));
    await waitFor(() => expect(identityMocks.confirmMfa).toHaveBeenCalledWith('123456'));
    expect(await screen.findByLabelText('One-time MFA recovery codes')).toHaveTextContent('AAAA-BBBB-CCCC');
  });

  it('lists the current logical device and can explicitly sign it out', async () => {
    identityMocks.revokeDeviceSession.mockResolvedValue({ id: 'session-1', revoked: true, current: true });
    const expired = vi.fn();
    window.addEventListener('erp:session-expired', expired, { once: true });
    render(<AccountSecurityPanel session={session()} onClose={vi.fn()} />);

    expect(await screen.findByText('Google Chrome')).toBeInTheDocument();
    expect(screen.getByText('Current device')).toBeInTheDocument();
    await userEvent.setup().click(screen.getByRole('button', { name: 'Sign out' }));
    expect(screen.getByRole('alertdialog', { name: 'Sign out this device?' })).toBeInTheDocument();
    await userEvent.setup().click(screen.getByRole('button', { name: 'Sign out now' }));
    await waitFor(() => expect(identityMocks.revokeDeviceSession).toHaveBeenCalledWith('session-1'));
    expect(expired).toHaveBeenCalledTimes(1);
  });

  it('defaults to active devices, paginates history, keeps IDs secondary, and names revocation targets', async () => {
    const history = Array.from({ length: 6 }, (_, index) => ({
      id: `history-${index + 1}-reference`, ip_address: `10.0.0.${index + 1}`, user_agent: 'Firefox/140',
      last_seen_at: `2026-09-0${index + 1}T10:00:00Z`, expires_at: '2026-09-09T12:00:00Z',
      revoked_at: '2026-09-09T11:00:00Z', revoke_reason: 'Expired', status: 'REVOKED', current: false, record_version: 2,
    }));
    identityMocks.listDeviceSessions.mockResolvedValue({
      data: [{
        id: 'session-current', ip_address: '127.0.0.1', user_agent: 'Chrome/140', last_seen_at: '2026-09-09T10:00:00Z',
        expires_at: '2026-09-09T12:00:00Z', revoked_at: null, revoke_reason: null, status: 'ACTIVE', current: true, record_version: 1,
      }, {
        id: 'session-edge', ip_address: '10.0.0.20', user_agent: 'Edg/140', last_seen_at: '2026-09-09T09:00:00Z',
        expires_at: '2026-09-09T12:00:00Z', revoked_at: null, revoke_reason: null, status: 'ACTIVE', current: false, record_version: 1,
      }, ...history],
      summary: { total: 8, active: 2 },
    });
    const user = userEvent.setup();
    render(<AccountSecurityPanel session={session()} onClose={vi.fn()} />);

    expect(await screen.findByText('Google Chrome')).toBeInTheDocument();
    expect(screen.queryByText('Mozilla Firefox')).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Revoke' }));
    expect(screen.getByRole('alertdialog', { name: 'Revoke Microsoft Edge?' })).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Keep session' }));

    await user.click(screen.getByRole('tab', { name: 'History (6)' }));
    expect(await screen.findByText('Page 1 of 2')).toBeInTheDocument();
    const reference = screen.getAllByText(/Session reference/)[0];
    expect(reference).toHaveClass('technical-identifier');
    await user.click(screen.getByRole('button', { name: 'Next' }));
    expect(screen.getByText('Page 2 of 2')).toBeInTheDocument();
  });
});

function session(): ErpSession {
  return {
    user: { id: 'user-1', name: 'Demo User', email: 'demo@example.local' },
    security: {
      email_verified: true, mfa_enabled: false, password_changed_at: null,
      last_login_at: null, current_session_id: 'session-1',
    },
    roles: ['ERP_ADMIN'], allowed_screens: [], allowed_actions: [], contexts: [], selected_context: null,
  };
}

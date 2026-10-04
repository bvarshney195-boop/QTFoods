import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ACC_LOGIN from './ACC_LOGIN';

const identityMocks = vi.hoisted(() => ({
  getInvitation: vi.fn(),
  acceptInvitation: vi.fn(),
  forgotPassword: vi.fn(),
  resetPassword: vi.fn(),
  requestEmailVerification: vi.fn(),
  verifyEmail: vi.fn(),
}));

vi.mock('../api/identity', async () => {
  const actual = await vi.importActual<typeof import('../api/identity')>('../api/identity');
  return { ...actual, ...identityMocks };
});

describe('identity access flows', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    window.history.replaceState(null, '', '/');
  });

  it('submits the second factor without asking for the password again', async () => {
    const onMfa = vi.fn();
    render(<ACC_LOGIN
      mfaChallenge={{ mfa_required: true, challenge_id: 'challenge-1', expires_at: '2026-09-09T11:00:00Z' }}
      onMfa={onMfa}
    />);

    await userEvent.setup().type(screen.getByLabelText('Authenticator or recovery code'), '123456');
    await userEvent.setup().click(screen.getByRole('button', { name: 'Verify and sign in' }));
    expect(onMfa).toHaveBeenCalledWith('123456', 'TOTP');
    expect(screen.queryByLabelText('Password')).not.toBeInTheDocument();
  });

  it('keeps password-reset requests non-enumerating and exposes local preview links when configured', async () => {
    identityMocks.forgotPassword.mockResolvedValue({
      accepted: true,
      message: 'If an eligible account matches that email, a password reset link has been sent.',
      delivery: { channel: 'EMAIL', status: 'SENT', preview_url: '/?flow=reset&token=abc' },
    });
    render(<ACC_LOGIN />);

    await userEvent.setup().click(screen.getByRole('button', { name: 'Forgot password?' }));
    const email = screen.getByLabelText('Email');
    await userEvent.setup().clear(email);
    await userEvent.setup().type(email, 'person@example.local');
    await userEvent.setup().click(screen.getByRole('button', { name: 'Send secure link' }));

    await waitFor(() => expect(identityMocks.forgotPassword).toHaveBeenCalledWith('person@example.local'));
    expect(await screen.findByRole('status')).toHaveTextContent('If an eligible account matches');
    expect(screen.getByRole('link', { name: 'Open development email link' })).toHaveAttribute('href', '/?flow=reset&token=abc');
  });

  it('loads invitation metadata and completes the one-time password ceremony', async () => {
    const token = 'a'.repeat(64);
    window.history.replaceState(null, '', `/?flow=invite&email=invite%40example.local&token=${token}`);
    identityMocks.getInvitation.mockResolvedValue({
      id: 'invitation-1',
      email: 'invite@example.local',
      name: 'Plant Operator',
      company_name: 'Q & T Foods Ltd',
      plant_name: 'Training Plant',
      expires_at: '2026-09-12T10:00:00Z',
    });
    identityMocks.acceptInvitation.mockResolvedValue({ accepted: true, email: 'invite@example.local' });
    render(<ACC_LOGIN />);

    expect(await screen.findByText('Plant Operator')).toBeInTheDocument();
    const password = screen.getByLabelText('New password');
    await userEvent.setup().clear(password);
    await userEvent.setup().type(password, 'InvitationPass123');
    await userEvent.setup().type(screen.getByLabelText('Confirm new password'), 'InvitationPass123');
    await userEvent.setup().click(screen.getByRole('button', { name: 'Save and continue' }));

    await waitFor(() => expect(identityMocks.acceptInvitation).toHaveBeenCalledWith(
      token, 'InvitationPass123', 'InvitationPass123'
    ));
    expect(await screen.findByRole('status')).toHaveTextContent('Invitation accepted');
    expect(screen.getByRole('heading', { name: 'Welcome back' })).toBeInTheDocument();
  });
});

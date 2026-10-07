import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi } from 'vitest';
import type { ErpSession } from '../types/session';
import { RequiredPasswordChange } from './RequiredPasswordChange';

describe('RequiredPasswordChange', () => {
  it('collects the temporary password and a confirmed permanent replacement', async () => {
    const submit = vi.fn();
    render(<RequiredPasswordChange session={session} busy={false} error={null} onSubmit={submit} onLogout={vi.fn()} />);
    const user = userEvent.setup();

    expect(screen.getByText(/No ERP business data is available/)).toBeInTheDocument();
    await user.type(screen.getByLabelText('Temporary password'), 'TempPass9x');
    await user.type(screen.getByLabelText(/New permanent password/), 'PermanentPass123');
    await user.type(screen.getByLabelText('Confirm permanent password'), 'PermanentPass123');
    await user.click(screen.getByRole('button', { name: 'Replace password and continue' }));

    expect(submit).toHaveBeenCalledWith('TempPass9x', 'PermanentPass123', 'PermanentPass123');
  });

  it('does not submit mismatched permanent passwords', async () => {
    const submit = vi.fn();
    render(<RequiredPasswordChange session={session} busy={false} error={null} onSubmit={submit} onLogout={vi.fn()} />);
    const user = userEvent.setup();
    await user.type(screen.getByLabelText('Temporary password'), 'TempPass9x');
    await user.type(screen.getByLabelText(/New permanent password/), 'PermanentPass123');
    await user.type(screen.getByLabelText('Confirm permanent password'), 'PermanentPass124');

    expect(screen.getByText('The new passwords do not match.')).toBeInTheDocument();
    expect(screen.getByRole('button', { name: 'Replace password and continue' })).toBeDisabled();
    expect(submit).not.toHaveBeenCalled();
  });
});

const session: ErpSession = {
  user: { id: 'user-1', name: 'Plant Auditor', email: 'auditor@example.local' },
  security: {
    email_verified: true,
    mfa_enabled: false,
    mfa_required: false,
    password_change_required: true,
    password_changed_at: null,
    last_login_at: null,
    current_session_id: 'session-1',
  },
  roles: ['SALES_MANAGER'],
  allowed_screens: ['WRK-HOME'],
  allowed_actions: [],
  contexts: [],
  selected_context: null,
};

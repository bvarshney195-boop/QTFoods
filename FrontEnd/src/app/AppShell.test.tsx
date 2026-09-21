import { render, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ErpSession } from '../types/session';
import AppShell from './AppShell';

vi.mock('./lazyPageMap', () => ({
  pageMap: {
    'WRK-HOME': () => <div>Assigned work page</div>,
    'FIN-GL': () => <div>General ledger page</div>,
    'BI-REP': () => <div>Reports page</div>,
  },
}));

vi.mock('../components/AccountSecurityPanel', () => ({
  AccountSecurityPanel: () => <div>Account panel</div>,
}));

describe('AppShell role navigation', () => {
  beforeEach(() => {
    localStorage.clear();
    window.history.replaceState(null, '', '#WRK-HOME');
    Object.defineProperty(window, 'matchMedia', {
      configurable: true,
      value: vi.fn().mockImplementation(() => ({
        matches: false,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
      })),
    });
  });

  it('shows friendly names only and never adds screens missing from the session', async () => {
    render(<AppShell session={financeSession()} onChooseContext={vi.fn()} onLogout={vi.fn()} />);

    const navigation = screen.getByRole('navigation', { name: 'Main menu' });
    expect(within(navigation).getByRole('button', { name: 'My work' })).toBeVisible();
    expect(within(navigation).queryByText('WRK-HOME')).not.toBeInTheDocument();
    expect(within(navigation).queryByRole('button', { name: 'Production orders' })).not.toBeInTheDocument();
    expect(screen.getAllByText('Finance Manager').length).toBeGreaterThan(0);

    await userEvent.setup().click(within(navigation).getByRole('button', { name: /Finance & People/ }));
    expect(within(navigation).getByRole('button', { name: 'General ledger' })).toBeVisible();
    expect(within(navigation).queryByText('FIN-GL')).not.toBeInTheDocument();

    await userEvent.setup().click(within(navigation).getByRole('button', { name: /Finance & People/ }));
    expect(within(navigation).queryByRole('button', { name: 'General ledger' })).not.toBeInTheDocument();
  });

  it('searches friendly menu names and expands the matching section', async () => {
    render(<AppShell session={financeSession()} onChooseContext={vi.fn()} onLogout={vi.fn()} />);
    const navigation = screen.getByRole('navigation', { name: 'Main menu' });

    await userEvent.setup().type(screen.getByRole('textbox', { name: 'Search menu' }), 'general ledger');

    expect(within(navigation).getByRole('button', { name: 'General ledger' })).toBeVisible();
    expect(within(navigation).queryByRole('button', { name: 'My work' })).not.toBeInTheDocument();
  });
});

function financeSession(): ErpSession {
  return {
    user: { id: 'finance-1', name: 'Demo Finance User', email: 'finance@example.com' },
    security: {
      email_verified: true,
      mfa_enabled: false,
      password_changed_at: null,
      last_login_at: null,
      current_session_id: 'session-1',
    },
    roles: ['FINANCE_REVIEWER'],
    allowed_screens: ['WRK-HOME', 'FIN-GL', 'BI-REP'],
    allowed_actions: ['ACTION:FIN-GL:JOURNAL-POST'],
    contexts: [{ company_id: 'company-1', company_name: 'Q & T FOODS LTD', plant_id: 'plant-1', plant_name: 'Training Plant' }],
    selected_context: { company_id: 'company-1', company_name: 'Q & T FOODS LTD', plant_id: 'plant-1', plant_name: 'Training Plant' },
  };
}

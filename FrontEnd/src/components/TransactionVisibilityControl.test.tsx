import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { VisibilityMetadata } from '../api/p2Operations';
import { TransactionVisibilityControl } from './TransactionVisibilityControl';

const apiMocks = vi.hoisted(() => ({ changeTransactionVisibility: vi.fn() }));

vi.mock('../api/transactionVisibility', () => apiMocks);

const normal: VisibilityMetadata = {
  status: 'NORMAL',
  is_restricted: false,
  record_version: 0,
};

const restricted: VisibilityMetadata = {
  status: 'RESTRICTED',
  is_restricted: true,
  record_version: 1,
  restricted_by: 'actor-1',
  restricted_at: '2026-10-13T10:00:00Z',
  restriction_reason: null,
};

describe('transaction visibility control', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    apiMocks.changeTransactionVisibility.mockResolvedValue(restricted);
  });

  afterEach(() => vi.restoreAllMocks());

  it('renders nothing for a user with neither permission, including stale restricted metadata', () => {
    const { rerender } = renderControl([], normal);
    expect(screen.queryByRole('checkbox', { name: 'Restrict Entry' })).not.toBeInTheDocument();
    expect(screen.queryByLabelText('Transaction visibility')).not.toBeInTheDocument();

    rerender(<TransactionVisibilityControl transactionType="SALES_ORDER" transactionId="order-1" visibility={restricted} permissions={[]} onChanged={vi.fn()} />);
    expect(screen.queryByLabelText('Transaction visibility')).not.toBeInTheDocument();
  });

  it('requires confirmation before a DidntShowCheckbox-only user hides their own entry', async () => {
    const user = userEvent.setup();
    const confirm = vi.spyOn(window, 'confirm').mockReturnValueOnce(false).mockReturnValueOnce(true);
    const onChanged = vi.fn();
    renderControl(['DidntShowCheckbox'], normal, onChanged);

    await user.click(screen.getByRole('checkbox', { name: 'Restrict Entry' }));
    expect(apiMocks.changeTransactionVisibility).not.toHaveBeenCalled();

    await user.click(screen.getByRole('checkbox', { name: 'Restrict Entry' }));
    await waitFor(() => expect(apiMocks.changeTransactionVisibility).toHaveBeenCalledWith(
      'SALES_ORDER', 'order-1', 'RESTRICTED', 0,
    ));
    expect(confirm).toHaveBeenCalledTimes(2);
    expect(onChanged).toHaveBeenCalledWith(restricted);
    expect(screen.getByText('Restricted')).toBeInTheDocument();
  });

  it('gives ShowEntries-only users read-only access to a restricted badge', () => {
    renderControl(['ShowEntries'], restricted);
    expect(screen.getByText('Restricted')).toBeInTheDocument();
    expect(screen.getByText('This restricted entry is visible because you have ShowEntries permission.')).toBeInTheDocument();
    expect(screen.queryByRole('checkbox', { name: 'Restrict Entry' })).not.toBeInTheDocument();
  });

  it('lets a user with both permissions unrestrict immediately without confirmation', async () => {
    const user = userEvent.setup();
    const confirm = vi.spyOn(window, 'confirm');
    const changed = { ...normal, record_version: 2 };
    apiMocks.changeTransactionVisibility.mockResolvedValue(changed);
    const onChanged = vi.fn();
    renderControl(['DidntShowCheckbox', 'ShowEntries'], restricted, onChanged);

    await user.click(screen.getByRole('checkbox', { name: 'Restrict Entry' }));
    await waitFor(() => expect(apiMocks.changeTransactionVisibility).toHaveBeenCalledWith(
      'SALES_ORDER', 'order-1', 'NORMAL', 1,
    ));
    expect(confirm).not.toHaveBeenCalled();
    expect(onChanged).toHaveBeenCalledWith(changed);
    expect(screen.getByText('Normal visibility')).toBeInTheDocument();
  });
});

function renderControl(
  permissions: string[],
  visibility: VisibilityMetadata,
  onChanged = vi.fn(),
) {
  return render(<TransactionVisibilityControl
    transactionType="SALES_ORDER"
    transactionId="order-1"
    visibility={visibility}
    permissions={permissions}
    onChanged={onChanged}
  />);
}

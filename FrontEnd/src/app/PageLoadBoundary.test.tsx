import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { PageLoadBoundary } from './PageLoadBoundary';

describe('PageLoadBoundary', () => {
  afterEach(() => vi.restoreAllMocks());

  it('turns a rejected dynamic import into a recoverable reload message', async () => {
    const reload = vi.fn();
    vi.spyOn(console, 'error').mockImplementation(() => undefined);

    render(<PageLoadBoundary screenCode="INB-GATE" onReload={reload}><BrokenWorkspace /></PageLoadBoundary>);

    expect(screen.getByRole('alert')).toHaveTextContent('Workspace could not be opened');
    expect(screen.getByRole('alert')).toHaveTextContent('application was updated');
    await userEvent.setup().click(screen.getByRole('button', { name: 'Reload application' }));
    expect(reload).toHaveBeenCalledOnce();
  });
});

function BrokenWorkspace(): never {
  throw new Error('Failed to fetch dynamically imported module');
}

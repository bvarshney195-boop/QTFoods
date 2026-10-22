import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { BulkImportCentre } from './BulkImportCentre';

const apiMocks = vi.hoisted(() => ({
  previewImport: vi.fn(),
  commitImport: vi.fn(),
  rollbackImport: vi.fn(),
  downloadImportErrors: vi.fn(),
}));

vi.mock('../api/experience', async () => {
  const actual = await vi.importActual<typeof import('../api/experience')>('../api/experience');
  return { ...actual, ...apiMocks };
});

describe('governed bulk import controls', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    apiMocks.previewImport.mockResolvedValue({
      id: 'batch-1', status: 'VALID', row_count: 1, error_count: 0,
      preview: [{ code: 'IMPORT-PARTY-01', display_name: 'Imported Party' }], errors: [],
    });
    apiMocks.commitImport.mockResolvedValue({ data: { id: 'batch-1', status: 'COMMITTED', created_count: 1 } });
    apiMocks.rollbackImport.mockResolvedValue({ data: { id: 'batch-1', status: 'ROLLED_BACK', deleted_count: 1 } });
  });

  it('offers each transaction control only in the state where it can succeed', async () => {
    const user = userEvent.setup();
    render(<BulkImportCentre
      allowedScreens={['MD-PARTY']}
      allowedActions={['ACTION:MD-PARTY:CREATE']}
    />);

    await user.upload(
      screen.getByLabelText('CSV import file'),
      new File(['code,display_name\nIMPORT-PARTY-01,Imported Party\n'], 'parties.csv', { type: 'text/csv' }),
    );
    await user.click(screen.getByRole('button', { name: 'Preview & validate' }));
    expect(await screen.findByText('Ready to import')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Commit drafts' }));
    await waitFor(() => expect(apiMocks.commitImport).toHaveBeenCalledWith('batch-1'));
    expect(await screen.findByRole('status')).toHaveTextContent('1 validated records were imported as drafts.');
    expect(screen.queryByRole('button', { name: 'Commit drafts' })).not.toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Rollback import' }));
    await waitFor(() => expect(apiMocks.rollbackImport).toHaveBeenCalledWith('batch-1'));
    expect(await screen.findByRole('status')).toHaveTextContent('The imported draft records were rolled back.');
    expect(screen.queryByRole('button', { name: 'Commit drafts' })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: 'Rollback import' })).not.toBeInTheDocument();
  });
});

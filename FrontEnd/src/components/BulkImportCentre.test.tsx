import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { BulkImportCentre } from './BulkImportCentre';

const api = vi.hoisted(() => ({
  previewImport: vi.fn(), commitImport: vi.fn(), rollbackImport: vi.fn(),
  downloadImportErrors: vi.fn(), downloadPartyExport: vi.fn(),
}));

vi.mock('../api/experience', () => api);

describe('BulkImportCentre', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    api.previewImport.mockResolvedValue({
      id: 'batch-1', status: 'VALID', row_count: 1, error_count: 0,
      preview: [{ code: 'CRM-EXCEL-001' }], errors: [],
    });
    api.downloadPartyExport.mockResolvedValue(new Blob(['xlsx']));
    Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:crm-export') });
    Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() });
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
  });

  it('accepts Excel imports and exports editable CRM party workbooks', async () => {
    const user = userEvent.setup();
    render(<BulkImportCentre
      allowedScreens={['MD-PARTY', 'MD-ITEM']}
      allowedActions={['ACTION:MD-PARTY:CREATE', 'ACTION:MD-ITEM:CREATE']}
    />);

    const input = screen.getByLabelText('CSV or Excel import file');
    expect(input).toHaveAttribute('accept', expect.stringContaining('.xls'));
    expect(input).toHaveAttribute('accept', expect.stringContaining('.xlsx'));
    const workbook = new File(['workbook'], 'crm-parties.xlsx', {
      type: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    });
    await user.upload(input, workbook);
    await user.click(screen.getByRole('button', { name: 'Preview & validate' }));
    await waitFor(() => expect(api.previewImport).toHaveBeenCalledWith('PARTIES', workbook));
    expect(await screen.findByText('Ready to import')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Export CRM parties (.xlsx)' }));
    await waitFor(() => expect(api.downloadPartyExport).toHaveBeenCalledOnce());
    expect(await screen.findByText('CRM party records exported to Excel.')).toBeInTheDocument();
  });
});

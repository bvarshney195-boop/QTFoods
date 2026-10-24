import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { PageHeader } from './PageHeader';

describe('PageHeader', () => {
  it('presents business wording without internal screen or delivery codes', () => {
    render(<PageHeader code="FIN-GL" batch="P2 operational" title="General ledger" description="Review journals and accounting periods." />);

    expect(screen.getByRole('heading', { name: 'General ledger' })).toBeVisible();
    expect(screen.getByText('Review journals and accounting periods.')).toBeVisible();
    expect(screen.queryByText(/FIN-GL|P2 operational/)).not.toBeInTheDocument();
  });
});

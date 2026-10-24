import { useRef } from 'react';
import { render, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { useResponsiveDataTables } from './useResponsiveDataTables';

function Fixture({ complex = false }: { complex?: boolean }) {
  const ref = useRef<HTMLElement>(null);
  useResponsiveDataTables(ref);
  return <main ref={ref}><div className="table-wrap"><table><thead><tr><th>Order</th><th>Status</th><th /></tr></thead><tbody><tr><td>SO-101</td><td>Draft</td><td colSpan={complex ? 2 : 1}><button>Open</button></td></tr></tbody></table></div></main>;
}

describe('useResponsiveDataTables', () => {
  it('labels simple table cells for the mobile card layout', async () => {
    const { container } = render(<Fixture />);
    const table = container.querySelector('table');

    await waitFor(() => expect(table).toHaveClass('mobile-card-table'));
    expect(container.querySelectorAll('td')[0]).toHaveAttribute('data-label', 'Order');
    expect(container.querySelectorAll('td')[1]).toHaveAttribute('data-label', 'Status');
    expect(container.querySelectorAll('td')[2]).toHaveAttribute('data-label', 'Actions');
  });

  it('leaves complex tables in their scrollable desktop structure', async () => {
    const { container } = render(<Fixture complex />);
    await waitFor(() => expect(container.querySelector('table')).not.toHaveClass('mobile-card-table'));
    expect(container.querySelector('td')).not.toHaveAttribute('data-label');
  });
});

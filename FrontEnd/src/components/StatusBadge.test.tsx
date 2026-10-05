import { render } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { StatusBadge } from './StatusBadge';

describe('StatusBadge semantic tones', () => {
  it.each([
    ['DRAFT', 'status-neutral'],
    ['BLOCKED', 'status-bad'],
    ['OVERDUE', 'status-bad'],
    ['AWAITING_APPROVAL', 'status-warn'],
    ['PARTIALLY_PAID', 'status-warn'],
    ['COMPLETED', 'status-ok'],
    ['SETTLED', 'status-ok'],
  ])('maps %s to %s', (status, className) => {
    const { container } = render(<StatusBadge status={status} />);

    expect(container.querySelector('.status')).toHaveClass(className);
  });
});

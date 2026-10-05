import { describe, expect, it } from 'vitest';
import { businessTimeZone, formatBusinessDate, formatZonedDateTime } from './dateTime';

describe('explicit business-time rendering', () => {
  it('renders the same instant once in the named plant timezone regardless of source offset', () => {
    const storedUtc = formatZonedDateTime('2026-10-05T00:00:00Z');
    const equivalentOffset = formatZonedDateTime('2026-10-05T05:30:00+05:30');

    expect(businessTimeZone).toBe('Asia/Kolkata');
    expect(equivalentOffset).toBe(storedUtc);
    expect(storedUtc).toContain('05:30');
    expect(storedUtc).toMatch(/IST|GMT\+5:30/);
  });

  it('keeps date-only business values on their source calendar day', () => {
    expect(formatBusinessDate('2026-10-05')).toContain('5 Oct 2026');
  });
});

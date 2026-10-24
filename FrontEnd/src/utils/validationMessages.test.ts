import { describe, expect, it } from 'vitest';
import { friendlyValidationMessage, normaliseApiFieldErrors } from './validationMessages';

describe('validation message presentation', () => {
  it('replaces nested backend field paths with business labels', () => {
    expect(friendlyValidationMessage(
      'lines.0.quality_hold_location_id',
      'The lines.0.quality hold location id field is required.',
    )).toBe('Line 1 quality hold location is required.');

    expect(normaliseApiFieldErrors({
      'components.1.uom_code': ['The components.1.uom code field is required.'],
    })).toEqual({
      'components.1.uom_code': ['Component 2 unit of measure is required.'],
    });
  });
});

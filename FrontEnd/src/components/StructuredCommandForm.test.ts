import { describe, expect, it } from 'vitest';
import { validateStructuredCommand, type StructuredCommandSchema } from './StructuredCommandForm';

describe('structured command form schema validation', () => {
  it('uses declared requirements instead of template values', () => {
    const schema: StructuredCommandSchema = { required: ['name', 'is_on_hold'] };

    expect(validateStructuredCommand({
      name: 'Customer A',
      optional_note: '',
      is_on_hold: false,
      optional_rows: [],
    }, schema)).toEqual({});
  });

  it('validates declared collection size and nested fields', () => {
    const schema: StructuredCommandSchema = {
      required: ['lines[].account_id', 'lines[].amount'],
      minItems: { lines: 2 },
    };

    expect(validateStructuredCommand({ lines: [] }, schema)).toEqual({
      lines: 'Add at least 2 lines.',
    });
    expect(validateStructuredCommand({
      lines: [
        { account_id: '', amount: '1' },
        { account_id: 'account-2', amount: '1' },
      ],
    }, schema)).toMatchObject({
      'lines.0.account_id': 'Enter ledger account.',
    });
  });

  it('supports explicit alternative and conditional requirements', () => {
    const schema: StructuredCommandSchema = {
      required: ['export_type'],
      atLeastOne: [{ paths: ['contact_email', 'contact_phone'] }],
      requiredWhen: [{ path: 'export_type', equals: 'AP_BANK', required: ['bank_account_id'] }],
    };

    const errors = validateStructuredCommand({
      export_type: 'AP_BANK',
      bank_account_id: null,
      contact_email: null,
      contact_phone: '',
    }, schema);

    expect(errors.bank_account_id).toBe('Enter bank account.');
    expect(errors.contact_email).toContain('contact email or contact phone');
    expect(errors.contact_phone).toContain('contact email or contact phone');
  });

  it('does not treat reference identifiers containing price as numeric values', () => {
    expect(validateStructuredCommand({ sales_price_list_id: '00000000-0000-4000-8000-000000002401' }, { required: [] })).toEqual({});
  });
});

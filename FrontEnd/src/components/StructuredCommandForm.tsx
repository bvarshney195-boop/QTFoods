import { useMemo } from 'react';
import type { P2Workspace } from '../api/p2Operations';

type PathPart = string | number;
type LookupRow = Record<string, unknown> & { id?: unknown };
type LookupCollection = { key: string; rows: LookupRow[] };
type PrimitiveCollection = { key: string; values: Array<string | number> };
type LookupCatalog = { records: LookupCollection[]; primitives: PrimitiveCollection[] };

export type StructuredCommandSchema = {
  required: readonly string[];
  minItems?: Readonly<Record<string, number>>;
  atLeastOne?: readonly { paths: readonly string[]; message?: string }[];
  requiredWhen?: readonly {
    path: string;
    equals: string | number | boolean;
    required: readonly string[];
  }[];
};

type StructuredCommandFormProps = {
  value: unknown;
  workspace: P2Workspace | null;
  errors: Record<string, string>;
  schema: StructuredCommandSchema;
  onChange: (value: unknown) => void;
};

export function StructuredCommandForm({ value, workspace, errors, schema, onChange }: StructuredCommandFormProps) {
  const catalog = useMemo(() => buildLookupCatalog(workspace), [workspace]);
  const required = useMemo(() => effectiveRequiredPaths(value, schema), [schema, value]);
  const record = isRecord(value) ? value : {};

  return <div className="form-grid p2-entry-grid">
    {Object.entries(record).map(([key, fieldValue]) => <CommandField
      key={key}
      fieldKey={key}
      value={fieldValue}
      path={[key]}
      catalog={catalog}
      errors={errors}
      required={required}
      onChange={(path, next) => onChange(updateAtPath(record, path, next))}
    />)}
  </div>;
}

export function validateStructuredCommand(value: unknown, schema: StructuredCommandSchema): Record<string, string> {
  const errors: Record<string, string> = {};
  const required = effectiveRequiredPaths(value, schema);

  function visit(current: unknown, path: PathPart[], fieldKey = '') {
    const exactPath = fieldPath(path);
    const pattern = normalisedPath(path);
    if (Array.isArray(current)) {
      const minimum = schema.minItems?.[pattern] ?? 0;
      if (current.length < minimum) {
        errors[exactPath] = `Add at least ${minimum === 1 ? 'one' : minimum} ${minimum === 1 ? singularLabel(fieldKey).toLowerCase() : friendlyFieldLabel(fieldKey).toLowerCase()}.`;
      }
      current.forEach((item, index) => visit(item, [...path, index], fieldKey));
      return;
    }
    if (isRecord(current)) {
      Object.entries(current).forEach(([key, item]) => visit(item, [...path, key], key));
      return;
    }
    if (required.has(pattern) && (current === null || current === undefined || (typeof current === 'string' && current.trim() === ''))) {
      errors[exactPath] = `Enter ${friendlyFieldLabel(fieldKey).toLowerCase()}.`;
      return;
    }
    if (current !== null && current !== '' && numericField(fieldKey) && !Number.isFinite(Number(current))) {
      errors[exactPath] = 'Enter a valid number.';
    }
  }

  visit(value, []);
  for (const rule of schema.atLeastOne ?? []) {
    if (rule.paths.some((path) => !blankValue(readSchemaPath(value, path)))) continue;
    const message = rule.message ?? `Enter at least one of ${rule.paths.map((path) => friendlyFieldLabel(path.split('.').at(-1) ?? path).toLowerCase()).join(' or ')}.`;
    rule.paths.forEach((path) => { errors[path] = message; });
  }
  if (isRecord(value)) {
    const gross = Number(value.monthly_gross);
    const deductions = Number(value.monthly_deductions);
    if (Number.isFinite(gross) && Number.isFinite(deductions) && deductions >= gross) {
      errors.monthly_deductions = 'Monthly deductions must be less than monthly gross pay.';
    }
  }
  return errors;
}

function effectiveRequiredPaths(value: unknown, schema: StructuredCommandSchema): Set<string> {
  const paths = new Set(schema.required);
  Object.entries(schema.minItems ?? {}).forEach(([path, minimum]) => {
    if (minimum > 0) paths.add(`@array:${path}`);
  });
  for (const condition of schema.requiredWhen ?? []) {
    if (readSchemaPath(value, condition.path) === condition.equals) {
      condition.required.forEach((path) => paths.add(path));
    }
  }
  return paths;
}

function readSchemaPath(value: unknown, path: string): unknown {
  return path.split('.').reduce<unknown>((current, key) => isRecord(current) ? current[key] : undefined, value);
}

function blankValue(value: unknown): boolean {
  return value === null || value === undefined || (typeof value === 'string' && value.trim() === '');
}

function CommandField({ fieldKey, value, path, catalog, errors, required, onChange }: {
  fieldKey: string;
  value: unknown;
  path: PathPart[];
  catalog: LookupCatalog;
  errors: Record<string, string>;
  required: Set<string>;
  onChange: (path: PathPart[], value: unknown) => void;
}) {
  if (Array.isArray(value)) return <ArrayField fieldKey={fieldKey} value={value} path={path} catalog={catalog} errors={errors} required={required} onChange={onChange} />;
  if (isRecord(value)) return <ObjectField fieldKey={fieldKey} value={value} path={path} catalog={catalog} errors={errors} required={required} onChange={onChange} />;
  return <ScalarField fieldKey={fieldKey} value={value} path={path} catalog={catalog} errors={errors} required={required} onChange={onChange} />;
}

function ScalarField({ fieldKey, value, path, catalog, errors, required, onChange }: {
  fieldKey: string;
  value: unknown;
  path: PathPart[];
  catalog: LookupCatalog;
  errors: Record<string, string>;
  required: Set<string>;
  onChange: (path: PathPart[], value: unknown) => void;
}) {
  const pathText = fieldPath(path);
  const error = errorFor(errors, pathText);
  const requiredField = required.has(normalisedPath(path));
  const optional = !requiredField;
  const title = friendlyFieldLabel(fieldKey);
  const errorId = error ? `field-error-${pathText.replace(/[^a-z0-9]+/gi, '-')}` : undefined;
  const common = {
    'data-field-path': pathText,
    'aria-label': title,
    'aria-invalid': Boolean(error),
    'aria-describedby': errorId,
  };

  if (typeof value === 'boolean') {
    return <label className="p2-check-field full-span">
      <input {...common} type="checkbox" checked={value} onChange={(event) => onChange(path, event.target.checked)} />
      <span><b>{title}<RequiredMark show={requiredField} /></b><small>{booleanHint(fieldKey)}</small></span>
      <FieldError id={errorId} text={error} />
    </label>;
  }

  if (referenceField(fieldKey)) {
    const options = referenceOptions(fieldKey, value, catalog);
    if (options.length) {
      return <label>{title}<RequiredMark show={requiredField} />
        <select {...common} value={stringValue(value)} onChange={(event) => onChange(path, nullableValue(event.target.value, optional))}>
          <option value="">{optional ? `No ${title.toLowerCase()}` : `Select ${title.toLowerCase()}`}</option>
          {options.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
        </select>
        <FieldError id={errorId} text={error} />
      </label>;
    }
    return <label>{title}<RequiredMark show={requiredField} />
      <input {...common} className="p2-automatic-choice" readOnly value={value ? 'Selected automatically' : 'No available choice'} />
      <small className="field-hint">The available business record is selected from your current workplace.</small>
      <FieldError id={errorId} text={error} />
    </label>;
  }

  const choices = primitiveOptions(fieldKey, value, catalog);
  if (choices.length > 1) {
    return <label>{title}<RequiredMark show={requiredField} />
      <select {...common} value={stringValue(value)} onChange={(event) => onChange(path, nullableValue(event.target.value, optional))}>
        {optional ? <option value="">Not specified</option> : null}
        {choices.map((choice) => <option key={choice} value={choice}>{formatChoice(choice)}</option>)}
      </select>
      <FieldError id={errorId} text={error} />
    </label>;
  }

  if (longTextField(fieldKey)) {
    return <label className="full-span">{title}<RequiredMark show={requiredField} />
      <textarea {...common} rows={3} value={stringValue(value)} placeholder={optional ? 'Optional' : `Enter ${title.toLowerCase()}`} onChange={(event) => onChange(path, nullableValue(event.target.value, optional))} />
      <FieldError id={errorId} text={error} />
    </label>;
  }

  const inputType = dateTimeField(fieldKey, value) ? 'datetime-local' : dateField(fieldKey, value) ? 'date' : numericField(fieldKey) ? 'number' : emailField(fieldKey) ? 'email' : phoneField(fieldKey) ? 'tel' : 'text';
  const displayValue = inputType === 'datetime-local' ? dateTimeInputValue(value) : stringValue(value);
  const integer = integerField(fieldKey);
  return <label>{title}<RequiredMark show={requiredField} />
    <input
      {...common}
      type={inputType}
      value={displayValue}
      min={inputType === 'number' && nonNegativeField(fieldKey) ? 0 : undefined}
      step={inputType === 'number' ? (integer ? 1 : '0.000001') : undefined}
      placeholder={optional ? 'Optional' : undefined}
      onChange={(event) => onChange(path, scalarValue(event.target.value, value, optional, inputType))}
    />
    {generatedNumberField(fieldKey, path) ? <small className="field-hint">Prefilled for convenience; change it only if your numbering process requires it.</small> : null}
    <FieldError id={errorId} text={error} />
  </label>;
}

function ArrayField({ fieldKey, value, path, catalog, errors, required, onChange }: {
  fieldKey: string;
  value: unknown[];
  path: PathPart[];
  catalog: LookupCatalog;
  errors: Record<string, string>;
  required: Set<string>;
  onChange: (path: PathPart[], value: unknown) => void;
}) {
  const title = friendlyFieldLabel(fieldKey);
  const pathText = fieldPath(path);
  const error = errorFor(errors, pathText);
  const referenceValues = value.filter((item): item is string | number => typeof item === 'string' || typeof item === 'number');
  const multiOptions = (fieldKey.endsWith('_ids') || fieldKey.endsWith('_id_list')) ? referenceOptions(fieldKey.replace(/s$/, ''), referenceValues[0], catalog) : [];

  if ((value.length === 0 || value.every((item) => !isRecord(item))) && multiOptions.length) {
    return <fieldset className="p2-choice-group full-span" data-field-path={pathText} aria-invalid={Boolean(error)}>
      <legend>{title}<RequiredMark show={required.has(`@array:${normalisedPath(path)}`)} /></legend>
      <div>{multiOptions.map((option) => {
        const selected = referenceValues.map(String).includes(option.value);
        return <label key={option.value}><input type="checkbox" checked={selected} onChange={(event) => {
          const next = event.target.checked ? [...referenceValues, option.value] : referenceValues.filter((item) => String(item) !== option.value);
          onChange(path, next);
        }} /><span>{option.label}</span></label>;
      })}</div>
      <FieldError text={error} />
    </fieldset>;
  }

  const objectRows = value.every(isRecord);
  return <section className="p2-entry-section full-span" data-field-path={pathText}>
    <div className="p2-entry-section-head"><div><h3>{title}</h3><small>{arrayHelp(fieldKey, objectRows)}</small></div>
      {objectRows && value.length ? <button className="secondary compact-button" type="button" onClick={() => onChange(path, [...value, newArrayRow(value[0])])}>+ Add {singularLabel(fieldKey).toLowerCase()}</button> : null}
    </div>
    <FieldError text={error} />
    {!value.length ? <div className="p2-empty-form-section">No {title.toLowerCase()} are required for this entry.</div> : null}
    {value.map((item, index) => isRecord(item) ? <div className="p2-entry-line-card" key={`${pathText}-${index}`}>
      <div className="p2-entry-line-head"><b>{singularLabel(fieldKey)} {index + 1}</b>{value.length > 1 ? <button type="button" onClick={() => onChange(path, value.filter((_, rowIndex) => rowIndex !== index))}>Remove</button> : null}</div>
      <div className="form-grid p2-entry-grid nested">
        {Object.entries(item).map(([key, fieldValue]) => <CommandField key={key} fieldKey={key} value={fieldValue} path={[...path, index, key]} catalog={catalog} errors={errors} required={required} onChange={onChange} />)}
      </div>
    </div> : <PrimitiveArrayRow key={`${pathText}-${index}`} fieldKey={fieldKey} value={item} path={[...path, index]} optional={!required.has(normalisedPath([...path, index]))} error={errorFor(errors, fieldPath([...path, index]))} onChange={onChange} onRemove={() => onChange(path, value.filter((_, rowIndex) => rowIndex !== index))} />)}
  </section>;
}

function PrimitiveArrayRow({ fieldKey, value, path, optional, error, onChange, onRemove }: {
  fieldKey: string;
  value: unknown;
  path: PathPart[];
  optional: boolean;
  error?: string;
  onChange: (path: PathPart[], value: unknown) => void;
  onRemove: () => void;
}) {
  return <div className="p2-primitive-row"><input data-field-path={fieldPath(path)} aria-invalid={Boolean(error)} value={stringValue(value)} onChange={(event) => onChange(path, nullableValue(event.target.value, optional))} /><button type="button" onClick={onRemove}>Remove</button><FieldError text={error} /></div>;
}

function ObjectField({ fieldKey, value, path, catalog, errors, required, onChange }: {
  fieldKey: string;
  value: Record<string, unknown>;
  path: PathPart[];
  catalog: LookupCatalog;
  errors: Record<string, string>;
  required: Set<string>;
  onChange: (path: PathPart[], value: unknown) => void;
}) {
  const entries = Object.entries(value);
  return <section className="p2-entry-section full-span">
    <div className="p2-entry-section-head"><div><h3>{friendlyFieldLabel(fieldKey)}</h3><small>{entries.length ? 'Complete the related details below.' : 'No additional details are needed.'}</small></div></div>
    {entries.length ? <div className="form-grid p2-entry-grid nested">{entries.map(([key, fieldValue]) => <CommandField key={key} fieldKey={key} value={fieldValue} path={[...path, key]} catalog={catalog} errors={errors} required={required} onChange={onChange} />)}</div> : null}
  </section>;
}

function RequiredMark({ show }: { show: boolean }) { return show ? <span className="required-mark" aria-hidden="true"> *</span> : null; }
function FieldError({ id, text }: { id?: string; text?: string }) { return text ? <small id={id} className="field-error">{text}</small> : null; }

function buildLookupCatalog(workspace: P2Workspace | null): LookupCatalog {
  const catalog: LookupCatalog = { records: [], primitives: [] };
  if (!workspace) return catalog;

  function visit(value: unknown, path: string[]) {
    if (Array.isArray(value)) {
      const rows = value.filter(isRecord);
      if (rows.length && rows.some((row) => row.id !== undefined)) catalog.records.push({ key: path.join('.'), rows });
      const primitives = value.filter((item): item is string | number => typeof item === 'string' || typeof item === 'number');
      if (primitives.length === value.length && primitives.length) catalog.primitives.push({ key: path.join('.'), values: primitives });
      return;
    }
    if (isRecord(value)) Object.entries(value).forEach(([key, item]) => {
      if (!['meta', 'summary', 'allowed_actions'].includes(key)) visit(item, [...path, key]);
    });
  }

  visit(workspace, []);
  return catalog;
}

function referenceOptions(fieldKey: string, currentValue: unknown, catalog: LookupCatalog): Array<{ value: string; label: string }> {
  const current = currentValue === null || currentValue === undefined ? '' : String(currentValue);
  const matches = current ? catalog.records.filter((collection) => collection.rows.some((row) => String(row.id ?? '') === current)) : [];
  const candidates = (matches.length ? matches : catalog.records.filter((collection) => lookupScore(fieldKey, collection.key) > 0))
    .sort((left, right) => (lookupScore(fieldKey, right.key) + collectionQuality(right)) - (lookupScore(fieldKey, left.key) + collectionQuality(left)));
  const collection = candidates[0];
  if (!collection) return [];
  const suitableRows = suitableReferenceRows(fieldKey, collection.rows);
  const seen = new Set<string>();
  const options = suitableRows.flatMap((row, index) => {
    const id = row.id === null || row.id === undefined ? '' : String(row.id);
    if (!id || seen.has(id)) return [];
    seen.add(id);
    return [{ value: id, label: lookupRowLabel(row, index) }];
  });
  if (current && !seen.has(current)) options.unshift({ value: current, label: 'Current selection' });
  return options;
}

function suitableReferenceRows(fieldKey: string, rows: LookupRow[]): LookupRow[] {
  if (!fieldKey.includes('account_id')) return rows;
  const expectedType = fieldKey.includes('payable') ? 'LIABILITY'
    : fieldKey === 'asset_account_id' || fieldKey === 'depreciation_account_id' ? 'ASSET'
      : fieldKey.includes('expense') ? 'EXPENSE' : null;
  if (!expectedType) return rows;
  const suitable = rows.filter((row) => String(row.account_type ?? '').toUpperCase() === expectedType);
  return suitable.length ? suitable : rows;
}

function primitiveOptions(fieldKey: string, currentValue: unknown, catalog: LookupCatalog): string[] {
  if (!primitiveChoiceField(fieldKey)) return [];
  const current = currentValue === null || currentValue === undefined ? '' : String(currentValue);
  const exactLookup = CHOICE_LOOKUPS[fieldKey];
  const semantic = catalog.primitives.filter((collection) => exactLookup
    ? collection.key.split('.').at(-1) === exactLookup
    : lookupScore(fieldKey, collection.key) > 0);
  const collection = semantic.sort((left, right) => {
    const leftContains = current && left.values.map(String).includes(current) ? 10 : 0;
    const rightContains = current && right.values.map(String).includes(current) ? 10 : 0;
    return (lookupScore(fieldKey, right.key) + rightContains) - (lookupScore(fieldKey, left.key) + leftContains);
  })[0];
  const known = KNOWN_CHOICES[fieldKey] ?? [];
  return Array.from(new Set([...(collection?.values.map(String) ?? []), ...known, ...(current ? [current] : [])]));
}

function lookupScore(fieldKey: string, collectionKey: string): number {
  const fieldTokens = tokens(fieldKey.replace(/_ids?$/, ''));
  const collectionTokens = tokens(collectionKey);
  let score = fieldTokens.reduce((total, token) => total + (collectionTokens.includes(token) ? 4 : 0), 0);
  const aliases: Record<string, string[]> = {
    account: ['account'], customer: ['customer', 'party'], supplier: ['supplier', 'party'], claimant: ['user'],
    employee: ['employee'], item: ['item', 'sku'], route: ['route'], position: ['position'], period: ['period'],
    order: ['order'], invoice: ['invoice'], shipment: ['shipment'], plant: ['plant'], company: ['company'],
    contract: ['contract'], lead: ['lead'], price: ['price'], asset: ['asset'], group: ['group'], specification: ['specification'],
  };
  fieldTokens.forEach((token) => { if ((aliases[token] ?? []).some((alias) => collectionTokens.includes(alias))) score += 2; });
  if (collectionKey.startsWith('lookups.')) score += 1;
  return score;
}

function collectionQuality(collection: LookupCollection): number {
  return collection.rows.slice(0, 4).reduce((score, row) => score + humanKeys(row).length, 0);
}

function lookupRowLabel(row: LookupRow, index: number): string {
  const preferredPairs = [
    ['employee_number', 'name'], ['account_code', 'name'], ['account_code', 'account_name'], ['route_code', 'name'],
    ['order_number', 'customer_name'], ['invoice_number', 'customer_name'], ['item_code', 'name'], ['sku_code', 'name'],
    ['code', 'name'], ['number', 'name'], ['period_code', 'name'], ['plant_name', 'company_name'],
  ];
  for (const [first, second] of preferredPairs) {
    const primary = humanValue(row[first]);
    if (!primary) continue;
    const secondary = humanValue(row[second]);
    return [primary, secondary].filter(Boolean).join(' · ');
  }
  const values = humanKeys(row).slice(0, 2).map((key) => humanValue(row[key])).filter(Boolean);
  return values.length ? values.join(' · ') : `Available option ${index + 1}`;
}

function humanKeys(row: LookupRow): string[] {
  const priority = ['name', 'account_name', 'company_name', 'customer_name', 'supplier_name', 'employee_number', 'account_code', 'route_code', 'order_number', 'invoice_number', 'item_code', 'sku_code', 'code', 'number', 'period_code', 'plant_name', 'description', 'status'];
  return priority.filter((key) => humanValue(row[key]));
}

function humanValue(value: unknown): string {
  if (typeof value !== 'string' && typeof value !== 'number') return '';
  const text = String(value).trim();
  if (!text || uuidLike(text)) return '';
  return /^[A-Z][A-Z0-9_ -]*$/.test(text) && text.includes('_') ? formatChoice(text) : text;
}

function updateAtPath(root: unknown, path: PathPart[], next: unknown): unknown {
  if (!path.length) return next;
  const [head, ...tail] = path;
  if (Array.isArray(root)) {
    const copy = [...root];
    const index = Number(head);
    copy[index] = updateAtPath(copy[index], tail, next);
    return copy;
  }
  const copy = isRecord(root) ? { ...root } : {};
  copy[String(head)] = updateAtPath(copy[String(head)], tail, next);
  return copy;
}

function newArrayRow(value: unknown): unknown {
  if (!isRecord(value)) return '';
  return Object.fromEntries(Object.entries(value).map(([key, item]) => {
    if (Array.isArray(item)) return [key, item.map(newArrayRow)];
    if (isRecord(item)) return [key, newArrayRow(item)];
    if (item === null || item === undefined || typeof item === 'boolean') return [key, item];
    if (referenceField(key) || dateField(key, item) || dateTimeField(key, item) || key.includes('uom') || key.includes('currency')) return [key, item];
    if (numericField(key)) return [key, /(quantity|rate)$/i.test(key) ? '1' : '0'];
    return [key, ''];
  }));
}

function scalarValue(raw: string, previous: unknown, optional: boolean, inputType: string): unknown {
  if (raw === '' && optional) return null;
  if (typeof previous === 'number' && raw !== '' && inputType === 'number') return Number(raw);
  return raw;
}

function nullableValue(raw: string, optional: boolean): string | null { return raw === '' && optional ? null : raw; }
function stringValue(value: unknown): string { return value === null || value === undefined ? '' : String(value); }
function dateTimeInputValue(value: unknown): string { return stringValue(value).replace(/Z$/, '').slice(0, 16); }
function isRecord(value: unknown): value is Record<string, unknown> { return Boolean(value) && typeof value === 'object' && !Array.isArray(value); }
function fieldPath(path: PathPart[]): string { return path.map(String).join('.'); }
function normalisedPath(path: PathPart[]): string {
  return path.reduce<string>((result, part) => typeof part === 'number'
    ? `${result}[]`
    : result ? `${result}.${part}` : part, '');
}
function errorFor(errors: Record<string, string>, path: string): string | undefined { return errors[path] ?? errors[path.replace(/\.(\d+)\./g, '.*.')]; }
function tokens(value: string): string[] { return value.toLowerCase().split(/[^a-z0-9]+/).filter(Boolean).map(singular); }
function singular(value: string): string { return value.endsWith('ies') ? `${value.slice(0, -3)}y` : value.endsWith('s') && !value.endsWith('ss') ? value.slice(0, -1) : value; }
function uuidLike(value: string): boolean { return /^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i.test(value); }

export function friendlyFieldLabel(fieldKey: string): string {
  const explicit: Record<string, string> = {
    employee_number: 'Employee number', monthly_gross: 'Monthly gross pay', monthly_deductions: 'Monthly deductions',
    expense_account_id: 'Expense account', payable_account_id: 'Payroll payable account', claimant_user_id: 'Employee or claimant',
    customer_party_id: 'Customer', supplier_party_id: 'Supplier', item_id: 'Item', account_id: 'Ledger account',
    asset_account_id: 'Asset account', depreciation_account_id: 'Accumulated depreciation account', depreciation_expense_account_id: 'Depreciation expense account',
    target_account_id: 'Target account', fiscal_period_id: 'Accounting period', production_order_id: 'Production order',
    plant_transfer_route_id: 'Transfer route', source_position_id: 'Source stock position', destination_position_id: 'Destination stock position',
    sales_lead_id: 'Sales lead', sales_contract_id: 'Sales contract', sales_price_list_id: 'Sales price list',
    shipment_id: 'Shipment', shipment_line_id: 'Shipment line', sales_allocation_id: 'Sales allocation', invoice_id: 'Invoice',
    employee_ids: 'Employees included', member_company_ids: 'Companies included', uom_code: 'Unit of measure',
    quantity_base: 'Quantity', masked_account_number: 'Masked account number', ifsc_code: 'IFSC code',
    record_version: 'Record version', source_uom_code: 'Source unit of measure', destination_uom_code: 'Destination unit of measure',
  };
  if (explicit[fieldKey]) return explicit[fieldKey];
  const withoutId = fieldKey.replace(/_ids?$/, '');
  return withoutId.replaceAll('_', ' ').replaceAll('-', ' ').replace(/\b\w/g, (character) => character.toUpperCase());
}

function singularLabel(fieldKey: string): string {
  const title = friendlyFieldLabel(fieldKey);
  if (title === 'Employees included') return 'Employee';
  return title.endsWith('ies') ? `${title.slice(0, -3)}y` : title.endsWith('s') ? title.slice(0, -1) : title;
}

function formatChoice(value: string): string { return value.toLowerCase().replaceAll('_', ' ').replaceAll('-', ' ').replace(/\b\w/g, (character) => character.toUpperCase()); }
function primitiveChoiceField(fieldKey: string): boolean {
  return Boolean(KNOWN_CHOICES[fieldKey])
    || fieldKey === 'source'
    || fieldKey === 'category'
    || fieldKey === 'priority'
    || fieldKey === 'severity'
    || fieldKey === 'currency'
    || fieldKey === 'uom_code'
    || fieldKey === 'allocation_basis'
    || fieldKey === 'payment_method'
    || fieldKey === 'classification'
    || fieldKey === 'transfer_scope'
    || fieldKey.endsWith('_type')
    || fieldKey.endsWith('_status');
}
function referenceField(fieldKey: string): boolean { return fieldKey.endsWith('_id'); }
function emailField(fieldKey: string): boolean { return fieldKey.includes('email'); }
function phoneField(fieldKey: string): boolean { return fieldKey.includes('phone'); }
function dateTimeField(fieldKey: string, value: unknown): boolean { return fieldKey.endsWith('_at') || /^\d{4}-\d{2}-\d{2}T/.test(stringValue(value)); }
function dateField(fieldKey: string, value: unknown): boolean { return /(^|_)(date|from|to|start|end)$/.test(fieldKey) || /^\d{4}-\d{2}-\d{2}$/.test(stringValue(value)); }
function numericField(fieldKey: string): boolean {
  if (referenceField(fieldKey)) return false;
  return tokens(fieldKey).some((token) => ['amount', 'cost', 'price', 'value', 'rate', 'percent', 'quantity', 'day', 'month', 'count', 'gross', 'deduction', 'proceed', 'debit', 'credit'].includes(token));
}
function integerField(fieldKey: string): boolean { return /(days|months|count|line_number)$/i.test(fieldKey); }
function nonNegativeField(fieldKey: string): boolean { return /(amount|cost|price|value|rate|percent|quantity|days|months|count|gross|deductions|proceeds|debit|credit)$/i.test(fieldKey); }
function longTextField(fieldKey: string): boolean { return /(description|notes|reason|instructions|terms|assumption|resolution|corrective_action)$/i.test(fieldKey); }
function generatedNumberField(fieldKey: string, path: PathPart[]): boolean { return path.length === 1 && (fieldKey.endsWith('_number') || fieldKey.endsWith('_code') || fieldKey === 'case_number'); }
function booleanHint(fieldKey: string): string { return fieldKey.includes('hold') ? 'Turn on to prevent further processing.' : 'Turn on when this option should apply.'; }
function arrayHelp(fieldKey: string, objectRows: boolean): string { return objectRows ? `Enter each ${singularLabel(fieldKey).toLowerCase()} separately.` : `Choose or enter the ${friendlyFieldLabel(fieldKey).toLowerCase()} for this entry.`; }

const KNOWN_CHOICES: Record<string, string[]> = {
  status: ['ACTIVE', 'INACTIVE'],
  priority: ['LOW', 'MEDIUM', 'HIGH', 'URGENT'],
  outcome: ['DELIVERED', 'FAILED'],
  claim_type: ['DAMAGE', 'SHORTAGE', 'QUALITY', 'OTHER'],
  requested_resolution: ['CREDIT', 'REPLACEMENT', 'REJECT'],
  resolution_type: ['CREDIT', 'REPLACEMENT', 'REJECT'],
  payment_method: ['BANK', 'CASH', 'CHEQUE', 'UPI'],
  export_type: ['AP_BANK', 'GST_INPUT', 'GST_OUTPUT'],
  severity: ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'],
  conversion_path: ['EXISTING_CUSTOMER', 'CREATE_CUSTOMER'],
};

const CHOICE_LOOKUPS: Record<string, string> = {
  outcome: 'outcomes',
  claim_type: 'claim_types',
  requested_resolution: 'resolutions',
  resolution_type: 'resolutions',
  payment_method: 'payment_methods',
};

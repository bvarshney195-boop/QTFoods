export type ApiFieldErrors = Record<string, string[]>;

export function normaliseApiFieldErrors(fields: ApiFieldErrors | undefined): ApiFieldErrors | undefined {
  if (!fields) return undefined;

  return Object.fromEntries(Object.entries(fields).map(([path, messages]) => [
    path,
    messages.map((message) => friendlyValidationMessage(path, message)),
  ]));
}

export function firstApiFieldError(fields: ApiFieldErrors | undefined): string | undefined {
  return Object.values(fields ?? {}).flat().find((message) => message.trim());
}

export function friendlyValidationMessage(path: string, message: string): string {
  const text = message.trim();
  const label = validationPathLabel(path);
  const fieldKey = path.split('.').at(-1) ?? path;

  if (/(?:^|\.)quantity$/.test(path) && /(greater than|\bgt\b|at least|positive)/i.test(text)) {
    return `${capitalise(label)} must be greater than 0.`;
  }

  const defaultFieldMessage = text.match(/^The\s+.+?\s+field\s+(.+)$/i);
  if (defaultFieldMessage) return `${capitalise(label)} ${defaultFieldMessage[1]}`;

  if (/^The selected .+ is invalid\.?$/i.test(text)) {
    return `Select a valid ${label.toLowerCase()}.`;
  }

  const variants = [
    path,
    path.replaceAll('_', ' '),
    fieldKey,
    fieldKey.replaceAll('_', ' '),
  ].filter((value, index, values) => value && values.indexOf(value) === index);
  const internalName = variants.find((variant) => text.toLowerCase().includes(variant.toLowerCase()));
  if (!internalName) return text;

  return text.replace(new RegExp(escapeRegExp(internalName), 'ig'), label.toLowerCase())
    .replace(/^The\s+/i, '')
    .replace(/^./, (character) => character.toUpperCase());
}

export function validationPathLabel(path: string): string {
  const parts = path.replace(/\[(\d+)\]/g, '.$1').split('.').filter(Boolean);
  const fieldKey = parts.at(-1) ?? path;
  const reverseIndex = [...parts].reverse().findIndex((part: string) => /^\d+$/.test(part));
  const indexPosition = reverseIndex === -1 ? -1 : parts.length - reverseIndex - 1;
  const field = fieldLabel(fieldKey);

  if (indexPosition > 0) {
    const collection = singular(parts[indexPosition - 1]);
    return `${title(collection)} ${Number(parts[indexPosition]) + 1} ${field.toLowerCase()}`;
  }

  return field;
}

function fieldLabel(value: string): string {
  const explicit: Record<string, string> = {
    quality_hold_location_id: 'Quality Hold location',
    released_location_id: 'Released store location',
    output_sku_id: 'Output SKU',
    uom_code: 'Unit of measure',
  };
  if (explicit[value]) return explicit[value];
  return title(value.replace(/_ids?$/, '').replaceAll('_', ' ').replaceAll('-', ' '));
}

function singular(value: string): string {
  if (value.endsWith('ies')) return `${value.slice(0, -3)}y`;
  return value.endsWith('s') && !value.endsWith('ss') ? value.slice(0, -1) : value;
}

function title(value: string): string {
  return value.toLowerCase().replace(/\b\w/g, (character) => character.toUpperCase());
}

function capitalise(value: string): string {
  return value.replace(/^./, (character) => character.toUpperCase());
}

function escapeRegExp(value: string): string {
  return value.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
}

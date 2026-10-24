import type { P2Record, P2Workspace } from '../api/p2Operations';
import {
  GovernedP2Workspace,
  type GovernedP2Config,
  type P2ActionSpec,
} from './GovernedP2Workspace';
import type { StructuredCommandSchema } from './StructuredCommandForm';

const scaleSchemas = {
  transferCreate: { required: ['transfer_number', 'plant_transfer_route_id', 'transfer_date', 'expected_arrival_date', 'lines[].source_position_id', 'lines[].destination_position_id', 'lines[].quantity_base'], minItems: { lines: 1 } },
  transferUpdate: { required: ['transfer_date', 'expected_arrival_date', 'lines[].source_position_id', 'lines[].destination_position_id', 'lines[].quantity_base'], minItems: { lines: 1 } },
  routeCreate: { required: ['route_code', 'destination_company_id', 'destination_plant_id', 'transfer_scope', 'name', 'currency', 'transit_days', 'markup_percent', 'mappings[].source_item_id', 'mappings[].destination_item_id', 'mappings[].source_uom_code', 'mappings[].destination_uom_code', 'mappings[].conversion_rate'], minItems: { mappings: 1 } },
  routeUpdate: { required: ['name', 'currency', 'transit_days', 'markup_percent', 'mappings[].source_item_id', 'mappings[].destination_item_id', 'mappings[].source_uom_code', 'mappings[].destination_uom_code', 'mappings[].conversion_rate'], minItems: { mappings: 1 } },
  groupCreate: { required: ['group_code', 'name', 'base_currency', 'members[].company_id', 'members[].member_code', 'members[].reporting_currency', 'members[].ownership_percent', 'members[].effective_from'], minItems: { members: 1 } },
  groupUpdate: { required: ['name', 'base_currency', 'members[].company_id', 'members[].member_code', 'members[].reporting_currency', 'members[].ownership_percent', 'members[].effective_from'], minItems: { members: 1 } },
  consolidation: { required: ['run_number', 'consolidation_group_id', 'cutoff_date', 'member_rates[].company_id', 'member_rates[].exchange_rate', 'eliminations[].description', 'eliminations[].debit_amount', 'eliminations[].credit_amount'], minItems: { member_rates: 1 } },
  destinationReference: { required: ['destination_reference'] },
  reason: { required: ['reason'] },
} satisfies Record<string, StructuredCommandSchema>;

export const scalePlantConfig: GovernedP2Config = {
  code: 'SCALE-PLANT',
  batch: 'P3 scale',
  title: 'Multi-Plant Control',
  description: 'Govern legal-entity groups, plant lanes, in-transit stock, and consolidated finance snapshots.',
  notice: 'Dispatch and receipt are separate scoped ledger postings. Cross-company lanes require an active group, explicit item mapping, commercial reference, and independent destination acceptance.',
  listPath: '/api/v1/scale/plants',
  collections: [
    {
      key: 'data', label: 'Transfers', kind: 'transfer',
      detailPath: (record) => `/api/v1/scale/transfers/${record.id}`,
      columns: [
        { label: 'Transfer', key: 'transfer_number' },
        { label: 'Side', key: 'side' },
        { label: 'Route', key: 'route_code' },
        { label: 'From', key: 'source_plant_name' },
        { label: 'To', key: 'destination_plant_name' },
        { label: 'Expected', key: 'expected_arrival_date', format: 'date' },
      ],
    },
    {
      key: 'routes', label: 'Transfer routes', kind: 'route',
      detailPath: (record) => `/api/v1/scale/transfer-routes/${record.id}`,
      columns: [
        { label: 'Route', key: 'route_code' },
        { label: 'Scope', key: 'transfer_scope' },
        { label: 'Source', key: 'source_plant_name' },
        { label: 'Destination', key: 'destination_plant_name' },
        { label: 'Transit days', key: 'transit_days', format: 'number' },
        { label: 'Mappings', key: 'mapping_count', format: 'number' },
      ],
    },
    {
      key: 'groups', label: 'Consolidation groups', kind: 'group',
      detailPath: (record) => `/api/v1/scale/consolidation-groups/${record.id}`,
      columns: [
        { label: 'Group', key: 'group_code' },
        { label: 'Name', key: 'name' },
        { label: 'Base currency', key: 'base_currency' },
        { label: 'Entities', key: 'member_count', format: 'number' },
        { label: 'Plants', key: 'plant_count', format: 'number' },
      ],
    },
    {
      key: 'consolidation_runs', label: 'Consolidations', kind: 'consolidation',
      detailPath: (record) => `/api/v1/scale/consolidations/${record.id}`,
      columns: [
        { label: 'Run', key: 'run_number' },
        { label: 'Group', key: 'group_code' },
        { label: 'Cutoff', key: 'cutoff_date', format: 'date' },
        { label: 'Entities', key: 'member_count', format: 'number' },
        { label: 'Debit', key: 'consolidated_debit', format: 'money' },
        { label: 'Credit', key: 'consolidated_credit', format: 'money' },
      ],
    },
  ],
  creators: [
    {
      action: 'TRANSFER-CREATE', label: 'New transfer', path: '/api/v1/scale/transfers',
      help: 'Choose an active source lane and mapped stock positions. Quantities and stock identity are rechecked under row locks at dispatch.',
      available: (workspace) => Boolean(first(workspace, 'routes') && first(workspace, 'source_positions') && first(workspace, 'destination_positions')),
      schema: scaleSchemas.transferCreate,
      template: transferTemplate,
    },
    {
      action: 'ROUTE-CREATE', label: 'New route', path: '/api/v1/scale/transfer-routes',
      help: 'Define an exact source-to-destination lane and explicit item/UOM conversion mappings.',
      available: (workspace) => rows(workspace, 'plants').some((plant) => plant.id !== scope(workspace).plant_id),
      schema: scaleSchemas.routeCreate,
      template: routeTemplate,
    },
    {
      action: 'GROUP-CREATE', label: 'New group', path: '/api/v1/scale/consolidation-groups',
      help: 'Create the legal-entity boundary used by cross-company lanes and consolidation snapshots.',
      schema: scaleSchemas.groupCreate,
      template: groupTemplate,
    },
    {
      action: 'CONSOLIDATE', label: 'New consolidation', path: '/api/v1/scale/consolidations',
      help: 'Capture balanced posted ledgers at one cutoff with explicit exchange rates and balanced eliminations.',
      available: (workspace) => Boolean(first(workspace, 'groups')),
      schema: scaleSchemas.consolidation,
      template: consolidationTemplate,
    },
  ],
  resolveAction: resolveScaleAction,
};

export function ScalePlantWorkspace() {
  return <GovernedP2Workspace config={scalePlantConfig} />;
}

function resolveScaleAction(action: string, record: P2Record): P2ActionSpec | null {
  if (record._kind === 'transfer') {
    const base = `/api/v1/scale/transfers/${record.id}`;
    if (action === 'UPDATE') return editor('Update transfer', 'Replace editable dates, reference, notes, and stock lines while the transfer is draft.', base, {
      transfer_date: field(record, 'transfer_date', today()),
      expected_arrival_date: field(record, 'expected_arrival_date', tomorrow()),
      commercial_reference: nullableField(record, 'commercial_reference'),
      notes: nullableField(record, 'notes'),
      lines: recordLines(record).map((line) => ({
        source_position_id: field(line, 'source_position_id'),
        destination_position_id: field(line, 'destination_position_id'),
        quantity_base: field(line, 'source_quantity', '1'),
        notes: nullableField(line, 'notes'),
      })),
    }, record, scaleSchemas.transferUpdate);
    if (action === 'SUBMIT') return run(`${base}/submit`, 'Transfer submitted for independent source approval.', record);
    if (action === 'APPROVE') return run(`${base}/approve`, 'Transfer independently approved at source.', record);
    if (action === 'ACCEPT') return editor('Accept cross-company transfer', 'Record the destination-side commercial document before the stock can leave source.', `${base}/accept`, { destination_reference: '' }, record, scaleSchemas.destinationReference);
    if (action === 'DISPATCH') return run(`${base}/dispatch`, 'Source stock dispatched into governed transit.', record);
    if (action === 'RECEIVE') return run(`${base}/receive`, 'Destination stock received with linked inbound movement evidence.', record);
    if (action === 'CANCEL') return editor('Cancel transfer', 'Record why this unshipped transfer is being cancelled.', `${base}/cancel`, { reason: '' }, record, scaleSchemas.reason);
  }

  if (record._kind === 'route') {
    const base = `/api/v1/scale/transfer-routes/${record.id}`;
    if (action === 'UPDATE') return editor('Update transfer route', 'Replace draft lane terms and item mappings without changing its legal-entity endpoints.', base, {
      name: field(record, 'name'), currency: field(record, 'currency', 'INR'),
      transit_days: numberField(record, 'transit_days', 1), markup_percent: field(record, 'markup_percent', '0'),
      mappings: arrayField(record, 'mappings').map((mapping) => ({
        source_item_id: field(mapping, 'source_item_id'), destination_item_id: field(mapping, 'destination_item_id'),
        source_uom_code: field(mapping, 'source_uom_code'), destination_uom_code: field(mapping, 'destination_uom_code'),
        conversion_rate: field(mapping, 'conversion_rate', '1'),
      })),
    }, record, scaleSchemas.routeUpdate);
    if (action === 'ACTIVATE') return run(`${base}/activate`, 'Transfer route activated after endpoint and mapping validation.', record);
    if (action === 'DEACTIVATE') return editor('Deactivate transfer route', 'Open transfers must be completed or cancelled first.', `${base}/deactivate`, { reason: '' }, record, scaleSchemas.reason);
  }

  if (record._kind === 'group') {
    const base = `/api/v1/scale/consolidation-groups/${record.id}`;
    if (action === 'UPDATE') return editor('Update consolidation group', 'Replace draft membership and reporting facts.', base, {
      name: field(record, 'name'), base_currency: field(record, 'base_currency', 'INR'),
      members: arrayField(record, 'members').map((member) => ({
        company_id: field(member, 'company_id'), member_code: field(member, 'member_code'),
        reporting_currency: field(member, 'reporting_currency', 'INR'),
        ownership_percent: field(member, 'ownership_percent', '100'),
        effective_from: field(member, 'effective_from', today()), effective_to: nullableField(member, 'effective_to'),
      })),
    }, record, scaleSchemas.groupUpdate);
    if (action === 'ACTIVATE') return run(`${base}/activate`, 'Consolidation group activated across its authorised entities.', record);
    if (action === 'RETIRE') return editor('Retire consolidation group', 'Every active route must be deactivated before retirement.', `${base}/retire`, { reason: '' }, record, scaleSchemas.reason);
  }

  if (record._kind === 'consolidation' && action === 'FINALIZE') {
    return run(`/api/v1/scale/consolidations/${record.id}/finalize`, 'Balanced consolidation snapshot independently finalized.', record);
  }

  return null;
}

function transferTemplate(workspace: P2Workspace): unknown {
  const route = first(workspace, 'routes');
  const sources = rows(workspace, 'source_positions');
  const destinations = rows(workspace, 'destination_positions');
  const source = sources.find((position) => position.company_id === route?.source_company_id && position.plant_id === route?.source_plant_id) ?? sources[0];
  const destination = destinations.find((position) => position.company_id === route?.destination_company_id
    && position.plant_id === route?.destination_plant_id
    && (route?.transfer_scope === 'INTER_COMPANY' || (position.item_id === source?.item_id
      && position.lot_id === source?.lot_id && position.inventory_owner_id === source?.inventory_owner_id))) ?? destinations[0];
  return {
    transfer_number: commandNumber('XFER'), plant_transfer_route_id: id(route),
    transfer_date: today(), expected_arrival_date: tomorrow(),
    commercial_reference: route?.require_commercial_reference ? '' : null,
    notes: null,
    lines: [{ source_position_id: id(source), destination_position_id: id(destination), quantity_base: '1', notes: null }],
  };
}

function routeTemplate(workspace: P2Workspace): unknown {
  const selected = scope(workspace);
  const plants = rows(workspace, 'plants');
  const destination = plants.find((plant) => plant.id !== selected.plant_id && plant.company_id === selected.company_id)
    ?? plants.find((plant) => plant.id !== selected.plant_id);
  const sourceItem = rows(workspace, 'items').find((item) => item.company_id === selected.company_id);
  const destinationItem = rows(workspace, 'items').find((item) => item.company_id === destination?.company_id && item.code === sourceItem?.code)
    ?? rows(workspace, 'items').find((item) => item.company_id === destination?.company_id);
  const crossCompany = destination?.company_id !== selected.company_id;
  return {
    route_code: commandNumber('LANE'), name: '',
    destination_company_id: field(destination, 'company_id'), destination_plant_id: id(destination),
    consolidation_group_id: id(first(workspace, 'groups')) || null,
    transfer_scope: crossCompany ? 'INTER_COMPANY' : 'INTER_PLANT', currency: 'INR', transit_days: 1,
    markup_percent: '0', require_destination_acceptance: crossCompany, require_commercial_reference: crossCompany,
    mappings: [{
      source_item_id: id(sourceItem), destination_item_id: id(destinationItem),
      source_uom_code: field(sourceItem, 'base_uom', 'PACK'),
      destination_uom_code: field(destinationItem, 'base_uom', 'PACK'), conversion_rate: '1',
    }],
  };
}

function groupTemplate(workspace: P2Workspace): unknown {
  const selected = scope(workspace);
  const plant = rows(workspace, 'plants').find((value) => value.company_id === selected.company_id);
  return {
    group_code: commandNumber('GROUP'), name: '', base_currency: 'INR',
    members: [{
      company_id: selected.company_id, member_code: field(plant, 'company_code', 'OWNER'),
      reporting_currency: 'INR', ownership_percent: '100', effective_from: today(), effective_to: null,
    }],
  };
}

function consolidationTemplate(workspace: P2Workspace): unknown {
  const group = first(workspace, 'groups');
  const members = rows(workspace, 'group_members').filter((member) => member.consolidation_group_id === group?.id);
  return {
    run_number: commandNumber('CONS'), consolidation_group_id: id(group), cutoff_date: today(),
    member_rates: members.map((member) => ({ company_id: field(member, 'company_id'), exchange_rate: '1' })),
    eliminations: [],
  };
}

function editor(label: string, help: string, path: string, body: unknown, record: P2Record, schema: StructuredCommandSchema): P2ActionSpec {
  return { editor: { label, help, path, body, schema, expectedVersion: record.record_version } };
}

function run(path: string, success: string, record: P2Record): P2ActionSpec {
  return { path, body: {}, expectedVersion: record.record_version, success };
}

function scope(workspace: P2Workspace): Record<string, string> {
  const value = workspace.scope;
  return value && typeof value === 'object' ? value as Record<string, string> : {};
}

function rows(workspace: P2Workspace, key: string): P2Record[] {
  const value = workspace.lookups?.[key];
  return Array.isArray(value) ? value as P2Record[] : [];
}

function first(workspace: P2Workspace, key: string): P2Record | undefined {
  return rows(workspace, key)[0];
}

function arrayField(record: P2Record, key: string): P2Record[] {
  return Array.isArray(record[key]) ? record[key] as P2Record[] : [];
}

function recordLines(record: P2Record): P2Record[] {
  return arrayField(record, 'lines');
}

function id(record?: P2Record): string {
  return String(record?.id ?? '');
}

function field(record: P2Record | undefined, key: string, fallback = ''): string {
  const value = record?.[key];
  return value === undefined || value === null ? fallback : String(value);
}

function nullableField(record: P2Record, key: string): string | null {
  const value = record[key];
  return value === undefined || value === null || value === '' ? null : String(value);
}

function numberField(record: P2Record, key: string, fallback: number): number {
  const value = Number(record[key]);
  return Number.isFinite(value) ? value : fallback;
}

function today(): string {
  return new Date().toISOString().slice(0, 10);
}

function tomorrow(): string {
  const value = new Date();
  value.setUTCDate(value.getUTCDate() + 1);
  return value.toISOString().slice(0, 10);
}

function commandNumber(prefix: string): string {
  return `${prefix}-${Date.now().toString(36).toUpperCase()}`;
}

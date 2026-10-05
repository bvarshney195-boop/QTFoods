import type { P2Record, P2Workspace } from '../api/p2Operations';
import { GovernedP2Workspace, type GovernedP2Config, type P2ActionSpec } from './GovernedP2Workspace';
import type { StructuredCommandSchema } from './StructuredCommandForm';

export type FinanceP2Code =
  | 'FIN-EXP' | 'FIN-GL' | 'COST-OH' | 'ASSET-REG' | 'HR-PAY' | 'ENG-MNT'
  | 'FIN-SIM' | 'FIN-ADJ' | 'FIN-LEGACY' | 'FIN-OPEN' | 'FIN-SUP';

export function FinanceP2Workspace({ screen }: { screen: FinanceP2Code }) {
  return <GovernedP2Workspace config={financeP2Configs[screen]} />;
}

export function PayablesIntegrationWorkspace() {
  return <GovernedP2Workspace config={payablesIntegrationConfig} />;
}

type Row = Record<string, unknown>;
const today = () => new Date().toISOString().slice(0, 10);
const future = (days: number) => { const result = new Date(); result.setUTCDate(result.getUTCDate() + days); return result.toISOString().slice(0, 10); };
const monthStart = () => `${today().slice(0, 8)}01`;
const commandNumber = (prefix: string) => `${prefix}-${new Date().toISOString().replace(/\D/g, '').slice(2, 14)}`;
const field = (record: Row | undefined, key: string, fallback: unknown = null) => record?.[key] ?? fallback;
const id = (record: Row | undefined) => String(record?.id ?? '');
const lookupValue = (workspace: P2Workspace, key: string): unknown[] => {
  const lookup = workspace.lookups;
  if (!lookup || typeof lookup !== 'object') return [];
  const value = (lookup as Row)[key]; return Array.isArray(value) ? value : [];
};
const rows = (workspace: P2Workspace, key: string): Row[] => lookupValue(workspace, key).filter((value): value is Row => Boolean(value) && typeof value === 'object');
const first = (workspace: P2Workspace, key: string) => rows(workspace, key)[0];
const find = (workspace: P2Workspace, key: string, predicate: (record: Row) => boolean) => rows(workspace, key).find(predicate) ?? first(workspace, key);
const lines = (record: P2Record) => Array.isArray(record.lines) ? record.lines as Row[] : [];
const editor = (label: string, help: string, path: string, body: unknown, record: P2Record, schema: StructuredCommandSchema): P2ActionSpec => ({ editor: { label, help, path, body, schema, expectedVersion: record.record_version } });
const run = (path: string, success: string, record: P2Record, body: unknown = {}): P2ActionSpec => ({ path, success, body, expectedVersion: record.record_version });
const account = (workspace: P2Workspace, index = 0) => rows(workspace, 'accounts')[index] ?? first(workspace, 'accounts');
const suitableAccount = (workspace: P2Workspace, type: string, words: string[]) => {
  const accounts = rows(workspace, 'accounts');
  const typed = accounts.filter((row) => String(row.account_type ?? '').toUpperCase() === type);
  return typed.find((row) => words.some((word) => `${row.account_code ?? ''} ${row.name ?? ''} ${row.account_name ?? ''}`.toLowerCase().includes(word))) ?? typed[0] ?? accounts[0];
};
const openPeriod = (workspace: P2Workspace) => find(workspace, 'periods', (record) => record.status === 'OPEN');
const balancedLines = (workspace: P2Workspace, amount = '1') => [
  { account_id: id(account(workspace, 0)), party_id: null, description: 'Debit line', debit_amount: amount, credit_amount: '0' },
  { account_id: id(account(workspace, 1) ?? account(workspace, 0)), party_id: null, description: 'Credit line', debit_amount: '0', credit_amount: amount },
];

const financeSchemas = {
  expenseCreate: { required: ['expense_number', 'claimant_user_id', 'expense_date', 'category', 'description', 'lines[].expense_account_id', 'lines[].description', 'lines[].net_amount', 'lines[].tax_rate'], minItems: { lines: 1 } },
  expenseUpdate: { required: ['claimant_user_id', 'expense_date', 'category', 'description', 'lines[].expense_account_id', 'lines[].description', 'lines[].net_amount', 'lines[].tax_rate'], minItems: { lines: 1 } },
  reason: { required: ['reason'] },
  journal: { required: ['journal_number', 'fiscal_period_id', 'posting_date', 'description', 'lines[].account_id', 'lines[].description', 'lines[].debit_amount', 'lines[].credit_amount'], minItems: { lines: 2 } },
  periodClose: { required: ['notes'] },
  reversal: { required: ['reason', 'reversal_number', 'posting_date'] },
  overheadCreate: { required: ['pool_code', 'name', 'expense_account_id', 'allocation_basis', 'rate'] },
  overheadUpdate: { required: ['name', 'expense_account_id', 'allocation_basis', 'rate', 'status'] },
  overheadAllocate: { required: ['allocation_number', 'fiscal_period_id', 'posting_date', 'target_account_id', 'basis_quantity'] },
  assetCreate: { required: ['asset_number', 'name', 'category', 'acquisition_date', 'acquisition_cost', 'residual_value', 'useful_life_months', 'asset_account_id', 'depreciation_account_id', 'depreciation_expense_account_id'] },
  assetUpdate: { required: ['name', 'category', 'acquisition_date', 'acquisition_cost', 'residual_value', 'useful_life_months', 'asset_account_id', 'depreciation_account_id', 'depreciation_expense_account_id'] },
  postingDate: { required: ['posting_date'] },
  fiscalPeriod: { required: ['fiscal_period_id'] },
  assetDispose: { required: ['posting_date', 'disposal_proceeds', 'reason'] },
  employeeCreate: { required: ['employee_number', 'name', 'department', 'monthly_gross', 'monthly_deductions', 'expense_account_id', 'payable_account_id'] },
  employeeUpdate: { required: ['name', 'department', 'monthly_gross', 'monthly_deductions', 'expense_account_id', 'payable_account_id', 'status'] },
  payroll: { required: ['run_number', 'period_start', 'period_end'], minItems: { employee_ids: 1 } },
  maintenanceCreate: { required: ['work_order_number', 'priority', 'description', 'planned_date', 'labour_cost', 'material_cost', 'external_cost', 'expense_account_id'] },
  maintenanceUpdate: { required: ['priority', 'description', 'planned_date', 'labour_cost', 'material_cost', 'external_cost', 'expense_account_id'] },
  maintenanceComplete: { required: ['posting_date', 'labour_cost', 'material_cost', 'external_cost'] },
  simulation: { required: ['simulation_number', 'name', 'as_of_date', 'lines[].account_id', 'lines[].description', 'lines[].debit_amount', 'lines[].credit_amount'], minItems: { lines: 2 } },
  adjustment: { required: ['adjustment_number', 'adjustment_date', 'adjustment_type', 'reason', 'lines[].account_id', 'lines[].description', 'lines[].debit_amount', 'lines[].credit_amount'], minItems: { lines: 2 } },
  legacy: { required: ['batch_number', 'source_system', 'posting_date', 'rows[].legacy_account_code', 'rows[].description', 'rows[].debit_amount', 'rows[].credit_amount'], minItems: { rows: 1 } },
  opening: { required: ['batch_number', 'opening_date', 'lines[].account_id', 'lines[].description', 'lines[].debit_amount', 'lines[].credit_amount'], minItems: { lines: 2 } },
  support: { required: ['case_number', 'category', 'severity', 'subject', 'description'] },
  reconcile: { required: ['lines[].line_id', 'lines[].reconciled_amount'], minItems: { lines: 2 } },
  diagnose: { required: ['notes'] },
  supportClose: { required: ['resolution_notes'] },
  bankCreate: { required: ['account_code', 'bank_name', 'account_name', 'masked_account_number', 'ifsc_code'] },
  bankUpdate: { required: ['bank_name', 'account_name', 'masked_account_number', 'ifsc_code', 'status'] },
  export: { required: ['export_number', 'export_type', 'period_start', 'period_end'], requiredWhen: [{ path: 'export_type', equals: 'AP_BANK', required: ['bank_account_id'] }] },
  acknowledge: { required: ['acknowledgement_reference'] },
} satisfies Record<string, StructuredCommandSchema>;

function expenseBody(record: P2Record) {
  return { claimant_user_id: field(record, 'claimant_user_id'), expense_date: field(record, 'expense_date', today()), category: field(record, 'category', 'SUPPLIES'), description: field(record, 'description', ''), lines: lines(record).map((line) => ({ expense_account_id: field(line, 'expense_account_id'), description: field(line, 'description'), net_amount: field(line, 'net_amount'), tax_rate: field(line, 'tax_rate') })) };
}

function assetBody(record: P2Record) {
  return { name: field(record, 'name', ''), category: field(record, 'category', 'EQUIPMENT'), acquisition_date: field(record, 'acquisition_date', today()), acquisition_cost: field(record, 'acquisition_cost', '0'), residual_value: field(record, 'residual_value', '0'), useful_life_months: field(record, 'useful_life_months', 36), asset_account_id: field(record, 'asset_account_id'), depreciation_account_id: field(record, 'depreciation_account_id'), depreciation_expense_account_id: field(record, 'depreciation_expense_account_id') };
}

function employeeBody(record: P2Record) {
  return { name: field(record, 'name', ''), department: field(record, 'department', ''), monthly_gross: field(record, 'monthly_gross', '0'), monthly_deductions: field(record, 'monthly_deductions', '0'), expense_account_id: field(record, 'expense_account_id'), payable_account_id: field(record, 'payable_account_id'), status: field(record, 'status', 'ACTIVE') };
}

function maintenanceBody(record: P2Record) {
  return { asset_id: field(record, 'asset_id'), priority: field(record, 'priority', 'MEDIUM'), description: field(record, 'description', ''), planned_date: field(record, 'planned_date', today()), labour_cost: field(record, 'labour_cost', '0'), material_cost: field(record, 'material_cost', '0'), external_cost: field(record, 'external_cost', '0'), expense_account_id: field(record, 'expense_account_id') };
}

export const financeP2Configs: Record<FinanceP2Code, GovernedP2Config> = {
  'FIN-EXP': {
    code: 'FIN-EXP', title: 'Employee Expenses', description: 'Capture, approve and post itemized employee expense claims.',
    notice: 'Totals and tax are calculated automatically. Submission, independent approval and ledger posting remain separate controlled steps.', listPath: '/api/v1/finance/expenses',
    collections: [{ key: 'data', label: 'Expense claims', kind: 'expense', detailPath: (record) => `/api/v1/finance/expenses/${record.id}`, columns: [{ label: 'Claim', key: 'expense_number' }, { label: 'Date', key: 'expense_date', format: 'date' }, { label: 'Category', key: 'category' }, { label: 'Description', key: 'description' }, { label: 'Total', key: 'total_amount', format: 'money' }] }],
    creators: [{ action: 'CREATE', label: 'New expense claim', path: '/api/v1/finance/expenses', available: (workspace) => Boolean(first(workspace, 'users') && first(workspace, 'accounts')), help: 'Add itemized net and tax facts; the gross claim total is derived on the server.', schema: financeSchemas.expenseCreate, template: (workspace) => ({ expense_number: commandNumber('EXP'), claimant_user_id: id(first(workspace, 'users')), expense_date: today(), category: String(lookupValue(workspace, 'categories')[0] ?? 'SUPPLIES'), description: '', lines: [{ expense_account_id: id(suitableAccount(workspace, 'EXPENSE', ['general', 'operating'])), description: '', net_amount: '1', tax_rate: '0' }] }) }],
    resolveAction: (action, record) => { const base = `/api/v1/finance/expenses/${record.id}`; if (action === 'UPDATE') return editor('Update expense claim', 'Replace editable claim lines while it remains draft.', base, expenseBody(record), record, financeSchemas.expenseUpdate); if (action === 'SUBMIT') return run(`${base}/submit`, 'Expense claim submitted for independent approval.', record); if (action === 'APPROVE') return run(`${base}/approve`, 'Expense claim approved by an independent actor.', record); if (action === 'POST') return run(`${base}/post`, 'Expense claim posted to the immutable ledger.', record); if (action === 'CANCEL') return editor('Cancel expense claim', 'Record the reason retained with the cancellation audit event.', `${base}/cancel`, { reason: '' }, record, financeSchemas.reason); return null; },
  },
  'FIN-GL': {
    code: 'FIN-GL', title: 'General Ledger & Period Close', description: 'Post balanced journals, reverse entries and close fiscal periods.',
    notice: 'Every journal must balance. Posting to closed periods is blocked and reversals create new immutable journals.', listPath: '/api/v1/finance/ledger',
    collections: [
      { key: 'data', label: 'Journals', kind: 'journal', detailPath: (record) => `/api/v1/finance/journals/${record.id}`, columns: [{ label: 'Journal', key: 'journal_number' }, { label: 'Posting date', key: 'posting_date', format: 'date' }, { label: 'Description', key: 'description' }, { label: 'Debit', key: 'total_debit', format: 'money' }, { label: 'Credit', key: 'total_credit', format: 'money' }] },
      { key: 'lookups.periods', label: 'Fiscal periods', kind: 'period', columns: [{ label: 'Period', key: 'period_code' }, { label: 'Starts', key: 'starts_on', format: 'date' }, { label: 'Ends', key: 'ends_on', format: 'date' }, { label: 'Closed at', key: 'closed_at', format: 'date' }] },
    ],
    creators: [{ action: 'JOURNAL-CREATE', label: 'New journal', path: '/api/v1/finance/journals', available: (workspace) => rows(workspace, 'accounts').length >= 2 && Boolean(openPeriod(workspace)), help: 'Provide at least two balanced lines in an open period.', schema: financeSchemas.journal, template: (workspace) => ({ journal_number: commandNumber('JV'), fiscal_period_id: id(openPeriod(workspace)), posting_date: today(), description: '', source_reference: null, lines: balancedLines(workspace) }) }],
    resolveAction: (action, record) => { if (record._kind === 'period' && action === 'CLOSE') return editor('Close fiscal period', 'Confirm reconciliations in the retained close notes. New postings will be rejected.', `/api/v1/finance/fiscal-periods/${record.id}/close`, { notes: '' }, record, financeSchemas.periodClose); const base = `/api/v1/finance/journals/${record.id}`; if (action === 'POST') return run(`${base}/post`, 'Balanced journal posted.', record); if (action === 'REVERSE') return editor('Reverse posted journal', 'A linked reversing journal is created; the original remains immutable.', `${base}/reverse`, { reason: '', reversal_number: commandNumber('RV'), posting_date: today() }, record, financeSchemas.reversal); return null; },
  },
  'COST-OH': {
    code: 'COST-OH', title: 'Overhead Allocation', description: 'Maintain allocation pools and post measured overhead to production targets.',
    notice: 'Rates, bases and account ownership are validated. Each allocation creates a balanced linked journal in an open period.', listPath: '/api/v1/costing/overheads',
    collections: [
      { key: 'data', label: 'Overhead pools', kind: 'overhead', detailPath: (record) => `/api/v1/costing/overheads/${record.id}`, columns: [{ label: 'Pool', key: 'pool_code' }, { label: 'Name', key: 'name' }, { label: 'Basis', key: 'allocation_basis' }, { label: 'Rate', key: 'rate', format: 'money' }] },
      { key: 'lookups.allocations', label: 'Posted allocations', kind: 'allocation', columns: [{ label: 'Allocation', key: 'allocation_number' }, { label: 'Posting date', key: 'posting_date', format: 'date' }, { label: 'Basis quantity', key: 'basis_quantity', format: 'number' }, { label: 'Amount', key: 'allocated_amount', format: 'money' }] },
    ],
    creators: [{ action: 'CREATE', label: 'New overhead pool', path: '/api/v1/costing/overheads', available: (workspace) => Boolean(first(workspace, 'accounts')), help: 'Define the expense account, allocation basis and positive rate.', schema: financeSchemas.overheadCreate, template: (workspace) => ({ pool_code: commandNumber('OH'), name: '', expense_account_id: id(suitableAccount(workspace, 'EXPENSE', ['overhead'])), allocation_basis: String(lookupValue(workspace, 'allocation_bases')[0] ?? 'OUTPUT_UNIT'), rate: '1' }) }],
    resolveAction: (action, record, workspace) => { const base = `/api/v1/costing/overheads/${record.id}`; if (action === 'UPDATE') return editor('Update overhead pool', 'Change the rate, basis, account or lifecycle state under optimistic locking.', base, { name: field(record, 'name'), expense_account_id: field(record, 'expense_account_id'), allocation_basis: field(record, 'allocation_basis'), rate: field(record, 'rate'), status: field(record, 'status') }, record, financeSchemas.overheadUpdate); if (action === 'ALLOCATE') return editor('Allocate overhead', 'The server multiplies the pool rate by the measured basis and posts a balanced journal.', `${base}/allocate`, { allocation_number: commandNumber('OHA'), fiscal_period_id: id(openPeriod(workspace)), posting_date: today(), production_order_id: null, target_account_id: id(suitableAccount(workspace, 'ASSET', ['inventory'])), basis_quantity: '1' }, record, financeSchemas.overheadAllocate); return null; },
  },
  'ASSET-REG': {
    code: 'ASSET-REG', title: 'Fixed Asset Register', description: 'Control acquisition, activation, depreciation and disposal accounting.',
    notice: 'Net book value and periodic depreciation are server authoritative. Every accounting transition posts a linked balanced journal.', listPath: '/api/v1/finance/assets',
    collections: [{ key: 'data', label: 'Assets', kind: 'asset', detailPath: (record) => `/api/v1/finance/assets/${record.id}`, columns: [{ label: 'Asset', key: 'asset_number' }, { label: 'Name', key: 'name' }, { label: 'Category', key: 'category' }, { label: 'Acquired', key: 'acquisition_date', format: 'date' }, { label: 'Historical acquisition cost', key: 'historical_acquisition_cost', format: 'money' }, { label: 'Current carrying amount', key: 'current_carrying_amount', format: 'money' }] }],
    creators: [{ action: 'CREATE', label: 'New fixed asset', path: '/api/v1/finance/assets', available: (workspace) => rows(workspace, 'accounts').length >= 3, help: 'Select the asset, accumulated-depreciation and depreciation-expense accounts.', schema: financeSchemas.assetCreate, template: (workspace) => ({ asset_number: commandNumber('AST'), name: '', category: 'EQUIPMENT', acquisition_date: today(), acquisition_cost: '1', residual_value: '0', useful_life_months: 36, asset_account_id: id(suitableAccount(workspace, 'ASSET', ['plant', 'equipment', 'fixed asset'])), depreciation_account_id: id(suitableAccount(workspace, 'ASSET', ['accumulated depreciation'])), depreciation_expense_account_id: id(suitableAccount(workspace, 'EXPENSE', ['depreciation'])) }) }],
    resolveAction: (action, record, workspace) => { const base = `/api/v1/finance/assets/${record.id}`; if (action === 'UPDATE') return editor('Update fixed asset', 'Replace editable depreciation facts while the asset remains draft.', base, assetBody(record), record, financeSchemas.assetUpdate); if (action === 'ACTIVATE') return editor('Activate fixed asset', 'Activation posts acquisition value on the selected date.', `${base}/activate`, { posting_date: field(record, 'acquisition_date', today()) }, record, financeSchemas.postingDate); if (action === 'DEPRECIATE') return editor('Post depreciation', 'Select an open period that has not already been depreciated.', `${base}/depreciate`, { fiscal_period_id: id(openPeriod(workspace)) }, record, financeSchemas.fiscalPeriod); if (action === 'DISPOSE') return editor('Dispose fixed asset', 'Record proceeds and a reason; gain/loss is calculated and posted by the server.', `${base}/dispose`, { posting_date: today(), disposal_proceeds: '0', reason: '' }, record, financeSchemas.assetDispose); return null; },
  },
  'HR-PAY': {
    code: 'HR-PAY', title: 'Payroll Posting', description: 'Maintain payroll accounting profiles and post governed payroll runs.',
    notice: 'Payroll totals are derived from active employee profiles. Run creation, independent approval and ledger posting are separated.', listPath: '/api/v1/finance/payroll',
    collections: [
      { key: 'data', label: 'Payroll runs', kind: 'payroll', detailPath: (record) => `/api/v1/finance/payroll/${record.id}`, columns: [{ label: 'Run', key: 'run_number' }, { label: 'Period start', key: 'period_start', format: 'date' }, { label: 'Period end', key: 'period_end', format: 'date' }, { label: 'Gross', key: 'gross_amount', format: 'money' }, { label: 'Net pay', key: 'net_amount', format: 'money' }] },
      { key: 'lookups.employees', label: 'Employees', kind: 'employee', columns: [{ label: 'Employee', key: 'employee_number' }, { label: 'Name', key: 'name' }, { label: 'Department', key: 'department' }, { label: 'Gross / month', key: 'monthly_gross', format: 'money' }, { label: 'Deductions', key: 'monthly_deductions', format: 'money' }] },
    ],
    creators: [
      { action: 'EMPLOYEE-CREATE', label: 'New employee profile', path: '/api/v1/finance/employees', available: (workspace) => rows(workspace, 'accounts').length >= 2, help: 'Create the payroll accounting profile used by future run snapshots.', schema: financeSchemas.employeeCreate, template: (workspace) => ({ employee_number: commandNumber('EMP'), name: '', department: '', monthly_gross: '1', monthly_deductions: '0', expense_account_id: id(suitableAccount(workspace, 'EXPENSE', ['payroll', 'salary', 'wage'])), payable_account_id: id(suitableAccount(workspace, 'LIABILITY', ['payroll', 'salary', 'wage'])) }) },
      { action: 'RUN-CREATE', label: 'New payroll run', path: '/api/v1/finance/payroll', available: (workspace) => rows(workspace, 'employees').some((employee) => employee.status === 'ACTIVE'), help: 'Active employees are selected for you and copied into a permanent payroll snapshot when saved.', schema: financeSchemas.payroll, template: (workspace) => ({ run_number: commandNumber('PAYROLL'), period_start: monthStart(), period_end: today(), employee_ids: rows(workspace, 'employees').filter((employee) => employee.status === 'ACTIVE').map(id) }) },
    ],
    resolveAction: (action, record) => { if (record._kind === 'employee' && action === 'UPDATE') return editor('Update employee profile', 'Changes affect future runs only; existing payroll lines remain immutable snapshots.', `/api/v1/finance/employees/${record.id}`, employeeBody(record), record, financeSchemas.employeeUpdate); const base = `/api/v1/finance/payroll/${record.id}`; if (action === 'APPROVE') return run(`${base}/approve`, 'Payroll run approved by an independent actor.', record); if (action === 'POST') return run(`${base}/post`, 'Payroll run posted to the ledger.', record); return null; },
  },
  'ENG-MNT': {
    code: 'ENG-MNT', title: 'Maintenance Accounting', description: 'Plan maintenance work and post completed labour, material and external cost.',
    notice: 'Work can reference a fixed asset. Completion posts the captured total cost to the configured expense account.', listPath: '/api/v1/engineering/maintenance',
    collections: [{ key: 'data', label: 'Maintenance work', kind: 'maintenance', detailPath: (record) => `/api/v1/engineering/maintenance/${record.id}`, columns: [{ label: 'Work order', key: 'work_order_number' }, { label: 'Priority', key: 'priority' }, { label: 'Planned', key: 'planned_date', format: 'date' }, { label: 'Description', key: 'description' }, { label: 'Total cost', key: 'total_cost', format: 'money' }] }],
    creators: [{ action: 'CREATE', label: 'New maintenance work', path: '/api/v1/engineering/maintenance', available: (workspace) => Boolean(first(workspace, 'accounts')), help: 'Plan the work and its expense account; costs may be refined before completion.', schema: financeSchemas.maintenanceCreate, template: (workspace) => ({ work_order_number: commandNumber('MNT'), asset_id: id(first(workspace, 'assets')) || null, priority: String(lookupValue(workspace, 'priorities')[0] ?? 'MEDIUM'), description: '', planned_date: today(), labour_cost: '0', material_cost: '0', external_cost: '0', expense_account_id: id(suitableAccount(workspace, 'EXPENSE', ['maintenance'])) }) }],
    resolveAction: (action, record) => { const base = `/api/v1/engineering/maintenance/${record.id}`; if (action === 'UPDATE') return editor('Update maintenance work', 'Replace the current plan while it remains draft.', base, maintenanceBody(record), record, financeSchemas.maintenanceUpdate); if (action === 'RELEASE') return run(`${base}/release`, 'Maintenance work released.', record); if (action === 'COMPLETE') return editor('Complete maintenance work', 'Confirm actual costs and the accounting date.', `${base}/complete`, { posting_date: today(), labour_cost: field(record, 'labour_cost', '0'), material_cost: field(record, 'material_cost', '0'), external_cost: field(record, 'external_cost', '0') }, record, financeSchemas.maintenanceComplete); if (action === 'CANCEL') return run(`${base}/cancel`, 'Maintenance work cancelled.', record); return null; },
  },
  'FIN-SIM': supplement('FIN-SIM', 'Finance Simulation', 'Model ledger-shaped assumptions without posting accounting entries.', '/api/v1/finance/simulation', 'simulations', 'simulation_number', [{ label: 'Simulation', key: 'simulation_number' }, { label: 'Name', key: 'name' }, { label: 'As of', key: 'as_of_date', format: 'date' }, { label: 'Projected profit', key: 'projected_profit', format: 'money' }]),
  'FIN-ADJ': supplement('FIN-ADJ', 'Finance Adjustments', 'Submit balanced reclassifications and corrections through maker-checker posting.', '/api/v1/finance/adjustments', 'adjustments', 'adjustment_number', [{ label: 'Adjustment', key: 'adjustment_number' }, { label: 'Date', key: 'adjustment_date', format: 'date' }, { label: 'Type', key: 'adjustment_type' }, { label: 'Debit', key: 'total_debit', format: 'money' }]),
  'FIN-LEGACY': supplement('FIN-LEGACY', 'Historical Import', 'Stage, validate and post balanced legacy accounting batches.', '/api/v1/finance/legacy-imports', 'legacy', 'batch_number', [{ label: 'Batch', key: 'batch_number' }, { label: 'Source', key: 'source_system' }, { label: 'Posting date', key: 'posting_date', format: 'date' }, { label: 'Records', key: 'record_count', format: 'number' }, { label: 'Errors', key: 'error_count', format: 'number' }]),
  'FIN-OPEN': supplement('FIN-OPEN', 'Opening Balance Reconciliation', 'Reconcile every source balance before controlled ledger posting.', '/api/v1/finance/opening-balances', 'opening', 'batch_number', [{ label: 'Batch', key: 'batch_number' }, { label: 'Opening date', key: 'opening_date', format: 'date' }, { label: 'Debit', key: 'total_debit', format: 'money' }, { label: 'Credit', key: 'total_credit', format: 'money' }, { label: 'Difference', key: 'difference_amount', format: 'money' }]),
  'FIN-SUP': supplement('FIN-SUP', 'Finance Support & Diagnostics', 'Capture diagnostic snapshots and resolutions without mutating ledger rows.', '/api/v1/finance/support-cases', 'support', 'case_number', [{ label: 'Case', key: 'case_number' }, { label: 'Category', key: 'category' }, { label: 'Severity', key: 'severity' }, { label: 'Subject', key: 'subject' }, { label: 'Opened', key: 'created_at', format: 'date' }]),
};

function supplement(code: Extract<FinanceP2Code, `FIN-${string}`>, title: string, description: string, listPath: string, kind: string, numberKey: string, columns: GovernedP2Config['collections'][number]['columns']): GovernedP2Config {
  const detailKind = kind === 'simulations' ? 'simulation' : kind === 'adjustments' ? 'adjustment' : kind;
  const pathSegment = kind === 'simulations' ? 'simulations' : kind === 'adjustments' ? 'adjustments' : kind === 'legacy' ? 'legacy-imports' : kind === 'opening' ? 'opening-balances' : 'support-cases';
  return {
    code, title, description,
    notice: code === 'FIN-SIM' ? 'Simulation lines are ledger shaped but explicitly isolated: running a scenario never creates a journal.' : 'Live workplace register with permission checks, duplicate protection, change-conflict checks, and permanent audit evidence.',
    listPath,
    collections: [{ key: 'data', label: title, kind, detailPath: (record) => `/api/v1/finance/${pathSegment}/${record.id}`, columns }],
    creators: [{ action: 'CREATE', label: `New ${title.toLowerCase().replace('finance ', '')}`, path: `/api/v1/finance/${pathSegment}`, available: (workspace) => code === 'FIN-SUP' || rows(workspace, 'accounts').length >= 2, help: 'Available business choices are prefilled from your current workplace. Review the form before saving.', schema: supplementSchema(code), template: (workspace) => supplementTemplate(code, workspace) }],
    resolveAction: (action, record) => supplementAction(code, detailKind, action, record),
  };
}

function supplementSchema(code: FinanceP2Code): StructuredCommandSchema {
  if (code === 'FIN-SIM') return financeSchemas.simulation;
  if (code === 'FIN-ADJ') return financeSchemas.adjustment;
  if (code === 'FIN-LEGACY') return financeSchemas.legacy;
  if (code === 'FIN-OPEN') return financeSchemas.opening;
  return financeSchemas.support;
}

function supplementTemplate(code: FinanceP2Code, workspace: P2Workspace): unknown {
  if (code === 'FIN-SIM') return { simulation_number: commandNumber('SIM'), name: '', description: null, as_of_date: today(), lines: balancedLines(workspace).map((line) => ({ ...line, assumption: null })) };
  if (code === 'FIN-ADJ') return { adjustment_number: commandNumber('ADJ'), adjustment_date: today(), adjustment_type: String(lookupValue(workspace, 'adjustment_types')[0] ?? 'RECLASSIFICATION'), reason: '', lines: balancedLines(workspace) };
  if (code === 'FIN-LEGACY') return { batch_number: commandNumber('LEG'), source_system: '', posting_date: today(), rows: rows(workspace, 'accounts').slice(0, 2).map((row, index) => ({ legacy_account_code: field(row, 'account_code'), account_id: id(row), description: index === 0 ? 'Imported debit' : 'Imported credit', debit_amount: index === 0 ? '1' : '0', credit_amount: index === 0 ? '0' : '1', source: {} })) };
  if (code === 'FIN-OPEN') return { batch_number: commandNumber('OPEN'), opening_date: today(), notes: null, lines: balancedLines(workspace) };
  return { case_number: commandNumber('FS'), category: String(lookupValue(workspace, 'categories')[0] ?? 'RECONCILIATION'), severity: String(lookupValue(workspace, 'severities')[0] ?? 'MEDIUM'), subject: '', description: '' };
}

function supplementAction(code: FinanceP2Code, kind: string, action: string, record: P2Record): P2ActionSpec | null {
  const segment = kind === 'simulation' ? 'simulations' : kind === 'adjustment' ? 'adjustments' : kind === 'legacy' ? 'legacy-imports' : kind === 'opening' ? 'opening-balances' : 'support-cases';
  const base = `/api/v1/finance/${segment}/${record.id}`;
  if (code === 'FIN-SIM' && action === 'RUN') return run(`${base}/run`, 'Simulation run completed with no ledger effect.', record);
  if (code === 'FIN-ADJ') { if (action === 'SUBMIT') return run(`${base}/submit`, 'Adjustment submitted.', record); if (action === 'APPROVE') return run(`${base}/approve`, 'Adjustment independently approved.', record); if (action === 'POST') return run(`${base}/post`, 'Adjustment posted to the ledger.', record); if (action === 'CANCEL') return editor('Cancel adjustment', 'Record the reason in the immutable audit trail.', `${base}/cancel`, { reason: '' }, record, financeSchemas.reason); }
  if (code === 'FIN-LEGACY') { if (action === 'VALIDATE') return run(`${base}/validate`, 'Legacy batch validated against the account map and balancing rules.', record); if (action === 'POST') return run(`${base}/post`, 'Valid legacy batch posted.', record); }
  if (code === 'FIN-OPEN') { if (action === 'RECONCILE') return editor('Reconcile opening balances', 'Every source line must be explicitly reconciled before posting.', `${base}/reconcile`, { lines: lines(record).map((line) => ({ line_id: id(line), reconciled_amount: field(line, 'source_amount', '0') })) }, record, financeSchemas.reconcile); if (action === 'POST') return run(`${base}/post`, 'Reconciled opening balances posted.', record); }
  if (code === 'FIN-SUP') { if (action === 'DIAGNOSE') return editor('Capture diagnostic snapshot', 'Notes and current finance counters are retained without changing journal data.', `${base}/diagnose`, { notes: '' }, record, financeSchemas.diagnose); if (action === 'CLOSE') return editor('Close finance support case', 'Record the final resolution supplied to the operator.', `${base}/close`, { resolution_notes: '' }, record, financeSchemas.supportClose); }
  return null;
}

export const payablesIntegrationConfig: GovernedP2Config = {
  code: 'FIN-AP', title: 'Payables Bank & Statutory Integrations', description: 'Manage outbound bank identities and deterministic AP/GST exports.',
  notice: 'Generated payloads are hashed and single-use. Bank acknowledgements are retained against the exact export.', listPath: '/api/v1/finance/payables/integrations',
  collections: [
    { key: 'data', label: 'Exports', kind: 'export', detailPath: (record) => `/api/v1/finance/payables/exports/${record.id}`, columns: [{ label: 'Export', key: 'export_number' }, { label: 'Type', key: 'export_type' }, { label: 'Period start', key: 'period_start', format: 'date' }, { label: 'Records', key: 'record_count', format: 'number' }, { label: 'Amount', key: 'total_amount', format: 'money' }] },
    { key: 'lookups.banks', label: 'Bank accounts', kind: 'bank', columns: [{ label: 'Code', key: 'account_code' }, { label: 'Bank', key: 'bank_name' }, { label: 'Account name', key: 'account_name' }, { label: 'Masked number', key: 'masked_account_number' }, { label: 'IFSC', key: 'ifsc_code' }] },
  ],
  creators: [
    { action: 'BANK-MANAGE', label: 'New bank account', path: '/api/v1/finance/payables/bank-accounts', help: 'Only masked account data is retained in this operational register.', schema: financeSchemas.bankCreate, template: () => ({ account_code: commandNumber('BANK'), bank_name: '', account_name: '', masked_account_number: '', ifsc_code: '' }) },
    { action: 'EXPORT', label: 'Generate export', path: '/api/v1/finance/payables/exports', help: 'Choose AP_BANK, GST_INPUT or GST_OUTPUT. AP bank files require an active configured bank account.', schema: financeSchemas.export, template: (workspace) => { const bank = rows(workspace, 'banks').find((record) => record.status === 'ACTIVE'); return { export_number: commandNumber('EXPORT'), export_type: bank ? 'AP_BANK' : 'GST_INPUT', bank_account_id: id(bank) || null, period_start: monthStart(), period_end: today() }; } },
  ],
  resolveAction: (action, record) => { if (record._kind === 'bank' && action === 'UPDATE') return editor('Update bank account', 'Update display and lifecycle facts without exposing a full account number.', `/api/v1/finance/payables/bank-accounts/${record.id}`, { bank_name: field(record, 'bank_name'), account_name: field(record, 'account_name'), masked_account_number: field(record, 'masked_account_number'), ifsc_code: field(record, 'ifsc_code'), status: field(record, 'status') }, record, financeSchemas.bankUpdate); if (action === 'ACKNOWLEDGE') return editor('Acknowledge export', 'Retain the receiving bank or statutory-system acknowledgement reference.', `/api/v1/finance/payables/exports/${record.id}/acknowledge`, { acknowledgement_reference: '' }, record, financeSchemas.acknowledge); return null; },
};

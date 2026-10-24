import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ErpSessionContext } from '../app/ErpSessionContext';
import type { HelpArticle, HelpSupportCase, HelpWorkspace } from '../api/helpSupport';
import type { ReportDefinition, ReportRun, ReportingWorkspace as ReportingWorkspaceResponse } from '../api/reporting';
import type { ErpSession } from '../types/session';
import { HelpSupportWorkspace } from './HelpSupportWorkspace';
import { ReportingWorkspace } from './ReportingWorkspace';

const reportingApi = vi.hoisted(() => ({
  listReportRuns: vi.fn(), getReportRun: vi.fn(), generateReport: vi.fn(),
  createReportExport: vi.fn(), downloadReportExport: vi.fn(),
}));
const helpApi = vi.hoisted(() => ({
  getHelpWorkspace: vi.fn(), getHelpArticle: vi.fn(), getHelpCase: vi.fn(),
  createHelpCase: vi.fn(), runHelpCaseCommand: vi.fn(),
}));
vi.mock('../api/reporting', () => reportingApi);
vi.mock('../api/helpSupport', () => helpApi);

describe('Controlled reporting workspace', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    reportingApi.listReportRuns.mockResolvedValue(reportWorkspace());
    reportingApi.generateReport.mockResolvedValue({ id: 'run-1', report_code: 'TRIAL_BALANCE', run_number: 'REP-UI-001', status: 'GENERATED', record_version: 1, row_count: 2, sha256: 'a'.repeat(64) });
    reportingApi.getReportRun.mockResolvedValue(reportRun());
    reportingApi.createReportExport.mockResolvedValue({ id: 'export-1', report_run_id: 'run-1', status: 'READY', record_version: 1, format: 'CSV', file_name: 'rep-ui-001.csv', row_count: 2, size_bytes: 120, sha256: 'b'.repeat(64) });
    reportingApi.downloadReportExport.mockResolvedValue(new Blob(['report']));
    Object.defineProperty(URL, 'createObjectURL', { configurable: true, value: vi.fn(() => 'blob:report') });
    Object.defineProperty(URL, 'revokeObjectURL', { configurable: true, value: vi.fn() });
    vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => undefined);
  });

  it('generates a cutoff-bound snapshot with definition parameters', async () => {
    const user = userEvent.setup();
    renderReporting();

    expect(await screen.findByRole('heading', { level: 1, name: 'Reports' })).toBeInTheDocument();
    expect(screen.getByText(/never refresh in place/i)).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: '+ New' }));
    await user.clear(screen.getByLabelText('Report run number'));
    await user.type(screen.getByLabelText('Report run number'), 'rep-ui-001');
    await user.click(screen.getByLabelText('Include zero-balance accounts'));
    await user.click(screen.getByRole('button', { name: 'Generate immutable snapshot' }));

    await waitFor(() => expect(reportingApi.generateReport).toHaveBeenCalledWith(
      expect.objectContaining({
        run_number: 'REP-UI-001', report_code: 'TRIAL_BALANCE',
        as_of_date: expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/), parameters: { include_zero: true },
      }),
      expect.any(String),
    ));
    expect(await screen.findByText(/snapshot generated with 2 rows/i)).toBeInTheDocument();
  });

  it('renders immutable rows and creates an export from the selected run', async () => {
    const user = userEvent.setup();
    reportingApi.listReportRuns.mockResolvedValue(reportWorkspace([reportRun()]));
    renderReporting();

    await user.click(await screen.findByRole('button', { name: 'Open' }));
    expect(screen.getByText('Snapshot rows')).toBeInTheDocument();
    expect(screen.getByText('General operating expense')).toBeInTheDocument();
    const integrity = screen.getByText('Export details and integrity').closest('details');
    expect(integrity).not.toHaveAttribute('open');
    await user.click(screen.getByText(/Choose columns/));
    await user.click(screen.getByLabelText('Debit'));
    expect(screen.queryByRole('columnheader', { name: 'Debit' })).not.toBeInTheDocument();
    await user.click(screen.getByText('Export details and integrity'));
    expect(screen.getByText('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Download CSV' }));

    await waitFor(() => expect(reportingApi.createReportExport).toHaveBeenCalledWith('run-1', 'CSV', expect.any(String)));
    expect(reportingApi.downloadReportExport).toHaveBeenCalledWith('export-1');
    expect(await screen.findByText(/CSV export created from stored snapshot rows/i)).toBeInTheDocument();
  });
});

describe('Help and support workspace', () => {
  beforeEach(() => {
    vi.resetAllMocks();
    helpApi.getHelpWorkspace.mockResolvedValue(helpWorkspace());
    helpApi.getHelpArticle.mockResolvedValue(article(true));
    helpApi.getHelpCase.mockResolvedValue(supportCase('OPEN', ['COMMENT']));
    helpApi.createHelpCase.mockResolvedValue({ entity_type: 'help_support_case', id: 'case-1', case_number: 'HLP-20260914-ABCDEF12', status: 'OPEN', record_version: 1 });
    helpApi.runHelpCaseCommand.mockResolvedValue({ entity_type: 'help_support_case', id: 'case-1', case_number: 'HLP-20260914-ABCDEF12', status: 'IN_PROGRESS', record_version: 2 });
  });

  it('opens published role-relevant guidance without prototype content', async () => {
    const user = userEvent.setup();
    renderHelp();

    expect(await screen.findByRole('heading', { level: 1, name: 'Help & support' })).toBeInTheDocument();
    expect(screen.queryByText(/prototype/i)).not.toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: /Getting started with your company/i }));
    expect(await screen.findByRole('heading', { level: 3, name: 'Select the operating boundary' })).toBeInTheDocument();
    expect(screen.getByText(/protected query and command is constrained/i)).toBeInTheDocument();
  });

  it('opens a scoped support case with only screens available to the user', async () => {
    const user = userEvent.setup();
    renderHelp();
    await screen.findByText('Getting started with your company and plant context');
    await user.click(screen.getByRole('button', { name: '+ New' }));
    expect(screen.getByLabelText('Affected screen')).toHaveTextContent('CRM-ORDER');
    expect(screen.getByLabelText('Affected screen')).not.toHaveTextContent('BI-REP');
    await user.selectOptions(screen.getByLabelText('Support case category'), 'WORKFLOW');
    await user.selectOptions(screen.getByLabelText('Affected screen'), 'CRM-ORDER');
    await user.type(screen.getByLabelText('Support case subject'), 'Order allocation question');
    await user.type(screen.getByLabelText('Support case description'), 'The confirmed order is visible but no picking allocation has appeared.');
    await user.click(screen.getByRole('button', { name: 'Open support case' }));

    await waitFor(() => expect(helpApi.createHelpCase).toHaveBeenCalledWith(expect.objectContaining({
      category: 'WORKFLOW', priority: 'NORMAL', affected_screen_code: 'CRM-ORDER',
      subject: 'Order allocation question',
    }), expect.any(String)));
    expect(await screen.findByText(/HLP-20260914-ABCDEF12 opened/i)).toBeInTheDocument();
  });

  it('supports the versioned manager start and resolution hand-off', async () => {
    const user = userEvent.setup();
    const opened = supportCase('OPEN', ['START', 'COMMENT']);
    const inProgress = supportCase('IN_PROGRESS', ['RESOLVE', 'COMMENT']); inProgress.record_version = 2;
    const resolved = supportCase('RESOLVED', []); resolved.record_version = 3; resolved.resolution_summary = 'Credit hold released and allocation created.';
    helpApi.getHelpWorkspace.mockResolvedValue(helpWorkspace([opened], true));
    helpApi.getHelpCase.mockResolvedValueOnce(opened).mockResolvedValueOnce(inProgress).mockResolvedValueOnce(resolved);
    renderHelp(adminSession());
    await user.click(await screen.findByRole('tab', { name: 'Support queue' }));
    await user.click(await screen.findByRole('button', { name: 'Open' }));
    await user.click(screen.getByRole('button', { name: 'Start support work' }));
    await waitFor(() => expect(helpApi.runHelpCaseCommand).toHaveBeenNthCalledWith(1,
      expect.objectContaining({ id: 'case-1', record_version: 1 }), 'start', {}, expect.any(String),
    ));
    await user.click(await screen.findByRole('button', { name: 'Propose resolution' }));
    await user.type(screen.getByLabelText('Resolution summary'), 'Credit hold released and allocation created.');
    await user.click(screen.getByRole('button', { name: 'Record proposed resolution' }));
    await waitFor(() => expect(helpApi.runHelpCaseCommand).toHaveBeenNthCalledWith(2,
      expect.objectContaining({ id: 'case-1', record_version: 2 }), 'resolve',
      { resolution_summary: 'Credit hold released and allocation created.' }, expect.any(String),
    ));
    expect(await screen.findByText('Proposed resolution')).toBeInTheDocument();
  });
});

function renderReporting(value: ErpSession = financeSession()) {
  return render(<ErpSessionContext.Provider value={value}><ReportingWorkspace /></ErpSessionContext.Provider>);
}

function renderHelp(value: ErpSession = salesSession()) {
  return render(<ErpSessionContext.Provider value={value}><HelpSupportWorkspace /></ErpSessionContext.Provider>);
}

function reportDefinition(): ReportDefinition {
  return {
    code: 'TRIAL_BALANCE', title: 'Trial balance', description: 'Posted balances through cutoff.',
    freshness_source: 'Posted general-ledger journals', historical_cutoff: true,
    parameters: [{ key: 'include_zero', label: 'Include zero-balance accounts', type: 'BOOLEAN', default: false }],
    columns: [
      { key: 'account_code', label: 'Account', type: 'TEXT' }, { key: 'account_name', label: 'Account name', type: 'TEXT' },
      { key: 'debit', label: 'Debit', type: 'MONEY' }, { key: 'credit', label: 'Credit', type: 'MONEY' },
    ],
  };
}

function reportWorkspace(data: ReportRun[] = []): ReportingWorkspaceResponse {
  return {
    data, meta: { total: data.length },
    summary: { total_runs: data.length, rows_snapshotted: data.reduce((sum, run) => sum + run.row_count, 0), exports_created: 0, latest_freshness_at: null },
    definitions: [reportDefinition()], allowed_actions: ['RUN', 'EXPORT'],
  };
}

function reportRun(): ReportRun {
  return {
    id: 'run-1', run_number: 'REP-UI-001', report_code: 'TRIAL_BALANCE', report_title: 'Trial balance', status: 'GENERATED',
    as_of_at: '2026-09-13T18:29:59Z', source_freshness_at: '2026-09-13T12:00:00Z', parameters: { include_zero: false },
    columns: reportDefinition().columns, row_count: 2, totals: { currency: 'INR', debit: '1250.000000', credit: '1250.000000', balance: '0.000000' },
    sha256: 'a'.repeat(64), record_version: 1, generated_at: '2026-09-14T09:00:00Z', created_at: '2026-09-14T09:00:00Z',
    created_by: { id: 'finance-1', name: 'Finance Reviewer' }, definition: reportDefinition(), allowed_actions: ['EXPORT'], exports: [],
    rows: [
      { id: 'row-1', row_number: 1, group_key: 'EXPENSE', data: { account_code: '510000', account_name: 'General operating expense', debit: '1250.000000', credit: '0.000000' } },
      { id: 'row-2', row_number: 2, group_key: 'EXPENSE', data: { account_code: '520000', account_name: 'Manufacturing overhead', debit: '0.000000', credit: '1250.000000' } },
    ],
  };
}

function helpWorkspace(cases: HelpSupportCase[] = [], manager = false): HelpWorkspace {
  return {
    articles: [article(false)], cases,
    summary: { published_articles: 1, visible_cases: cases.length, open_cases: cases.filter((item) => item.status === 'OPEN' || item.status === 'IN_PROGRESS').length, critical_open_cases: 0 },
    lookups: {
      case_categories: ['ACCESS', 'WORKFLOW', 'REPORTING'], priorities: ['LOW', 'NORMAL', 'HIGH', 'CRITICAL'],
      statuses: ['OPEN', 'IN_PROGRESS', 'RESOLVED', 'CLOSED'], article_categories: ['GETTING_STARTED', 'WORKFLOWS'],
      screen_codes: manager ? ['ADM-HELP', 'BI-REP', 'CRM-ORDER'] : ['ADM-HELP', 'CRM-ORDER'],
    }, allowed_actions: ['CREATE', 'COMMENT', 'REOPEN', 'CLOSE', ...(manager ? ['MANAGE'] : [])], is_support_manager: manager,
  };
}

function article(detail: boolean): HelpArticle {
  return {
    id: 'article-1', slug: 'getting-started-with-your-context', category: 'GETTING_STARTED', related_screen_code: null,
    title: 'Getting started with your company and plant context', summary: 'Choose the correct operating context.',
    content_version: 1, published_at: '2026-09-14T08:00:00Z', updated_at: '2026-09-14T08:00:00Z',
    sections: detail ? [{ heading: 'Select the operating boundary', content: 'Every protected query and command is constrained to the selected company and plant.' }] : undefined,
  };
}

function supportCase(status: string, allowedActions: string[]): HelpSupportCase {
  return {
    id: 'case-1', case_number: 'HLP-20260914-ABCDEF12', category: 'WORKFLOW', priority: 'HIGH', affected_screen_code: 'CRM-ORDER',
    subject: 'Order allocation question', description: 'The confirmed order has no picking allocation.', status, record_version: 1,
    requester: { id: 'sales-1', name: 'Sales Manager' }, assigned_to: status === 'OPEN' ? null : { id: 'admin-1', name: 'ERP Administrator' },
    resolution_summary: null, resolved_at: status === 'RESOLVED' ? '2026-09-14T11:00:00Z' : null,
    resolved_by: status === 'RESOLVED' ? { id: 'admin-1', name: 'ERP Administrator' } : null,
    closed_at: null, created_at: '2026-09-14T09:00:00Z', updated_at: '2026-09-14T10:00:00Z', allowed_actions: allowedActions,
    events: [{ id: 'event-1', sequence_number: 1, event_type: 'CREATED', status_from: null, status_to: 'OPEN', message: 'The confirmed order has no picking allocation.', actor: { id: 'sales-1', name: 'Sales Manager' }, created_at: '2026-09-14T09:00:00Z' }],
  };
}

function financeSession(): ErpSession {
  return session(['BI-REP'], ['ACTION:BI-REP:RUN', 'ACTION:BI-REP:EXPORT'], ['FINANCE_REVIEWER']);
}
function salesSession(): ErpSession {
  return session(['ADM-HELP', 'CRM-ORDER'], ['ACTION:ADM-HELP:CREATE', 'ACTION:ADM-HELP:COMMENT', 'ACTION:ADM-HELP:CLOSE', 'ACTION:ADM-HELP:REOPEN'], ['SALES_MANAGER']);
}
function adminSession(): ErpSession {
  return session(['ADM-HELP', 'BI-REP', 'CRM-ORDER'], ['ACTION:ADM-HELP:CREATE', 'ACTION:ADM-HELP:COMMENT', 'ACTION:ADM-HELP:CLOSE', 'ACTION:ADM-HELP:REOPEN', 'ACTION:ADM-HELP:MANAGE'], ['ERP_ADMIN']);
}
function session(screens: string[], actions: string[], roles: string[]): ErpSession {
  return {
    user: { id: 'user-1', name: 'ERP User', email: 'user@example.com' }, roles, allowed_screens: screens, allowed_actions: actions,
    contexts: [{ company_id: 'company-1', company_name: 'Q & T Foods', plant_id: 'plant-1', plant_name: 'Training Plant' }],
    selected_context: { company_id: 'company-1', company_name: 'Q & T Foods', plant_id: 'plant-1', plant_name: 'Training Plant' },
  };
}

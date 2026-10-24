import { useCallback, useEffect, useRef, useState, type FormEvent } from 'react';
import {
  createHelpCase,
  getHelpArticle,
  getHelpCase,
  getHelpWorkspace,
  runHelpCaseCommand,
  type HelpArticle,
  type HelpSupportCase,
  type HelpWorkspace,
} from '../api/helpSupport';
import { isApiError } from '../api/client';
import { useErpSession } from '../app/ErpSessionContext';
import { PageHeader } from './PageHeader';
import { StatusBadge } from './StatusBadge';

type Tab = 'ARTICLES' | 'CASES';
type Selected = { kind: 'ARTICLE'; value: HelpArticle } | { kind: 'CASE'; value: HelpSupportCase };
type CaseDraft = { category: string; priority: string; affected_screen_code: string; subject: string; description: string };
type ActionDraft = { action: 'COMMENT' | 'RESOLVE' | 'REOPEN' | 'CLOSE'; text: string };

export function HelpSupportWorkspace() {
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const [workspace, setWorkspace] = useState<HelpWorkspace | null>(null);
  const [tab, setTab] = useState<Tab>('ARTICLES');
  const [selected, setSelected] = useState<Selected | null>(null);
  const [caseDraft, setCaseDraft] = useState<CaseDraft | null>(null);
  const [actionDraft, setActionDraft] = useState<ActionDraft | null>(null);
  const [search, setSearch] = useState('');
  const [category, setCategory] = useState('');
  const [status, setStatus] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});
  const createKey = useRef<string | null>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    try {
      setWorkspace(await getHelpWorkspace({
        q: search || undefined,
        article_category: tab === 'ARTICLES' ? category || undefined : undefined,
        case_status: tab === 'CASES' ? status || undefined : undefined,
      }));
      setError(null);
    } catch (caught) {
      setError(message(caught, 'Unable to load help and support.'));
    } finally {
      setLoading(false);
    }
  }, [contextKey, tab, search, category, status]);

  useEffect(() => { void refresh(); }, [refresh]);
  useEffect(() => {
    setTab('ARTICLES'); setSelected(null); setCaseDraft(null); setActionDraft(null);
    setSearch(''); setCategory(''); setStatus(''); setError(null); setSuccess(null); setFieldErrors({});
    createKey.current = null;
  }, [contextKey]);

  const canCreate = Boolean(workspace?.allowed_actions.includes('CREATE'));

  function changeTab(next: Tab) {
    setTab(next); setSelected(null); setCaseDraft(null); setActionDraft(null);
    setSearch(''); setCategory(''); setStatus(''); setError(null); setSuccess(null); setFieldErrors({});
  }

  function startCase() {
    const lookups = workspace?.lookups;
    setTab('CASES'); setSelected(null); setActionDraft(null); setError(null); setSuccess(null); setFieldErrors({});
    createKey.current = null;
    setCaseDraft({
      category: lookups?.case_categories[0] ?? 'ACCESS',
      priority: lookups?.priorities.find((value) => value === 'NORMAL') ?? lookups?.priorities[0] ?? 'NORMAL',
      affected_screen_code: '', subject: '', description: '',
    });
  }

  async function openArticle(article: HelpArticle) {
    setCaseDraft(null); setActionDraft(null); setError(null); setSuccess(null); setFieldErrors({});
    try { setSelected({ kind: 'ARTICLE', value: await getHelpArticle(article.slug) }); }
    catch (caught) { setError(message(caught, 'Unable to load the help article.')); }
  }

  async function openCase(record: HelpSupportCase) {
    setCaseDraft(null); setActionDraft(null); setError(null); setSuccess(null); setFieldErrors({});
    try { setSelected({ kind: 'CASE', value: await getHelpCase(record.id) }); }
    catch (caught) { setError(message(caught, 'Unable to load the support case.')); }
  }

  async function submitCase(event: FormEvent) {
    event.preventDefault();
    if (!caseDraft) return;
    const fields: Record<string, string> = {};
    if (caseDraft.subject.trim().length < 3) fields.subject = 'Enter a clear subject.';
    if (caseDraft.description.trim().length < 10) fields.description = 'Describe the issue in at least 10 characters.';
    if (Object.keys(fields).length) { setFieldErrors(fields); setError('Correct the highlighted support-case fields.'); return; }
    setBusy(true); setError(null); setSuccess(null); setFieldErrors({});
    try {
      createKey.current ??= crypto.randomUUID();
      const result = await createHelpCase({
        ...caseDraft,
        affected_screen_code: caseDraft.affected_screen_code || null,
        subject: caseDraft.subject.trim(),
        description: caseDraft.description.trim(),
      }, createKey.current);
      createKey.current = null;
      await refresh();
      setSelected({ kind: 'CASE', value: await getHelpCase(result.id) });
      setCaseDraft(null);
      setSuccess(`${result.case_number} opened in the selected plant support queue.`);
    } catch (caught) {
      capture(caught, setError, setFieldErrors, 'Unable to open the support case.');
    } finally {
      setBusy(false);
    }
  }

  async function startSupport() {
    if (selected?.kind !== 'CASE') return;
    await command(selected.value, 'start', {}, 'Support ownership accepted; the case is now in progress.');
  }

  async function submitAction(event: FormEvent) {
    event.preventDefault();
    if (selected?.kind !== 'CASE' || !actionDraft) return;
    if (actionDraft.text.trim().length < (actionDraft.action === 'COMMENT' ? 2 : 5)) {
      setFieldErrors({ message: 'Enter enough detail for the case history.' }); return;
    }
    const path = actionDraft.action === 'COMMENT' ? 'comments' : actionDraft.action.toLowerCase() as 'resolve' | 'reopen' | 'close';
    const field = actionDraft.action === 'COMMENT' ? 'message' : actionDraft.action === 'RESOLVE' ? 'resolution_summary' : actionDraft.action === 'REOPEN' ? 'reason' : 'confirmation';
    const successMessage = actionDraft.action === 'COMMENT' ? 'Comment added to the versioned case history.'
      : actionDraft.action === 'RESOLVE' ? 'Resolution recorded for requester confirmation.'
        : actionDraft.action === 'REOPEN' ? 'Case reopened for further support work.' : 'Requester confirmed the resolution and closed the case.';
    await command(selected.value, path, { [field]: actionDraft.text.trim() }, successMessage);
  }

  async function command(record: HelpSupportCase, action: 'comments' | 'start' | 'resolve' | 'reopen' | 'close', body: Record<string, string>, successMessage: string) {
    setBusy(true); setError(null); setSuccess(null); setFieldErrors({});
    try {
      await runHelpCaseCommand(record, action, body, crypto.randomUUID());
      await refresh();
      setSelected({ kind: 'CASE', value: await getHelpCase(record.id) });
      setActionDraft(null); setSuccess(successMessage);
    } catch (caught) {
      capture(caught, setError, setFieldErrors, 'Unable to update the support case.');
    } finally {
      setBusy(false);
    }
  }

  return <>
    <PageHeader code="ADM-HELP" batch="B04 live" title="Help & Support" description="Search role-relevant operating guidance or open a scoped, versioned support case with a complete response history." onNew={canCreate ? startCase : undefined} />
    <div className="live-notice help-notice"><span />Knowledge articles follow your effective screen access. Support cases remain inside the selected company and plant; only you and authorised support managers can read them.</div>
    {workspace ? <div className="manufacturing-summary help-summary">
      <Metric label="Available articles" value={workspace.summary.published_articles} />
      <Metric label={workspace.is_support_manager ? 'Queue cases' : 'My cases'} value={workspace.summary.visible_cases} />
      <Metric label="Open / in progress" value={workspace.summary.open_cases} />
      <Metric label="Critical open" value={workspace.summary.critical_open_cases} />
    </div> : null}
    <div className="workspace-tabs help-tabs" role="tablist" aria-label="Help workspace areas">
      <button type="button" role="tab" aria-selected={tab === 'ARTICLES'} className={tab === 'ARTICLES' ? 'active' : ''} onClick={() => changeTab('ARTICLES')}>Knowledge articles</button>
      <button type="button" role="tab" aria-selected={tab === 'CASES'} className={tab === 'CASES' ? 'active' : ''} onClick={() => changeTab('CASES')}>{workspace?.is_support_manager ? 'Support queue' : 'My support cases'}</button>
    </div>
    <div className="module-grid requisition-workspace help-workspace">
      <section className="panel">
        <div className="requisition-toolbar help-toolbar">
          <label>Search<input aria-label="Help search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder={tab === 'ARTICLES' ? 'Title, topic or keyword' : 'Case number, subject or description'} /></label>
          {tab === 'ARTICLES' ? <label>Category<select aria-label="Help article category" value={category} onChange={(event) => setCategory(event.target.value)}><option value="">All categories</option>{workspace?.lookups.article_categories.map((value) => <option key={value}>{value}</option>)}</select></label>
            : <label>Status<select aria-label="Support case status" value={status} onChange={(event) => setStatus(event.target.value)}><option value="">All statuses</option>{workspace?.lookups.statuses.map((value) => <option key={value}>{value}</option>)}</select></label>}
          <button type="button" className="secondary compact-button" disabled={loading} onClick={() => void refresh()}>Refresh</button>
        </div>
        {loading && !workspace ? <Empty text="Loading role-relevant help..." /> : tab === 'ARTICLES'
          ? workspace?.articles.length ? <div className="help-article-list">{workspace.articles.map((article) => <button type="button" key={article.id} className={selected?.kind === 'ARTICLE' && selected.value.id === article.id ? 'selected' : ''} onClick={() => void openArticle(article)}><span><StatusBadge status={article.category} />{article.related_screen_code ? <code>{article.related_screen_code}</code> : null}</span><b>{article.title}</b><small>{article.summary}</small><em>Open guidance →</em></button>)}</div> : <Empty text="No knowledge articles match this role and search." />
          : workspace?.cases.length ? <div className="table-wrap"><table className="requisition-table help-case-register"><thead><tr><th>Case</th><th>Subject</th><th>Category</th><th>Priority</th><th>Status</th><th>Owner</th><th /></tr></thead><tbody>{workspace.cases.map((record) => <tr key={record.id} className={selected?.kind === 'CASE' && selected.value.id === record.id ? 'selected-row' : ''}><td><b>{record.case_number}</b><small>v{record.record_version} · {dateTime(record.created_at)}</small></td><td>{record.subject}<small>{record.affected_screen_code ?? 'General support'}</small></td><td>{label(record.category)}</td><td><StatusBadge status={record.priority} /></td><td><StatusBadge status={record.status} /></td><td>{record.assigned_to?.name ?? 'Unassigned'}</td><td><button type="button" className="secondary compact-button" onClick={() => void openCase(record)}>Open</button></td></tr>)}</tbody></table></div> : <Empty text={workspace?.is_support_manager ? 'No support cases match this queue filter.' : 'You have no support cases in this plant.'} />}
      </section>
      <aside className="panel requisition-editor help-editor"><div className="requisition-detail-body">
        {error ? <div className="form-error" role="alert"><span>{error}</span></div> : null}
        {success ? <div className="form-success" role="status"><span />{success}</div> : null}
        {caseDraft ? <NewCaseForm draft={caseDraft} setDraft={setCaseDraft} workspace={workspace} errors={fieldErrors} busy={busy} submit={submitCase} close={() => { setCaseDraft(null); createKey.current = null; }} />
          : actionDraft && selected?.kind === 'CASE' ? <CaseActionForm draft={actionDraft} setDraft={setActionDraft} busy={busy} errors={fieldErrors} submit={submitAction} close={() => setActionDraft(null)} />
            : selected?.kind === 'ARTICLE' ? <ArticleDetail article={selected.value} />
              : selected?.kind === 'CASE' ? <CaseDetail record={selected.value} busy={busy} start={startSupport} action={(action) => { setError(null); setSuccess(null); setFieldErrors({}); setActionDraft({ action, text: '' }); }} />
                : <Empty text={tab === 'ARTICLES' ? 'Choose an article to read its published guidance.' : canCreate ? 'Choose a case or open a new scoped support request.' : 'Choose a support case to inspect its history.'} />}
      </div></aside>
    </div>
  </>;
}

function NewCaseForm({ draft, setDraft, workspace, errors, busy, submit, close }: { draft: CaseDraft; setDraft: (value: CaseDraft) => void; workspace: HelpWorkspace | null; errors: Record<string, string>; busy: boolean; submit: (event: FormEvent) => void; close: () => void }) {
  return <form className="requisition-form help-case-form" onSubmit={submit}><fieldset disabled={busy}><div className="detail-status"><StatusBadge status="NEW CASE" /><span>Number assigned on save</span></div><div className="requisition-field-grid">
    <label>Category<select aria-label="Support case category" value={draft.category} onChange={(event) => setDraft({ ...draft, category: event.target.value })}>{workspace?.lookups.case_categories.map((value) => <option key={value}>{value}</option>)}</select></label>
    <label>Priority<select aria-label="Support case priority" value={draft.priority} onChange={(event) => setDraft({ ...draft, priority: event.target.value })}>{workspace?.lookups.priorities.map((value) => <option key={value}>{value}</option>)}</select></label>
    <label className="wide">Affected screen<select aria-label="Affected screen" value={draft.affected_screen_code} onChange={(event) => setDraft({ ...draft, affected_screen_code: event.target.value })}><option value="">General / no single screen</option>{workspace?.lookups.screen_codes.map((value) => <option key={value}>{value}</option>)}</select><FieldError value={errors.affected_screen_code} /></label>
    <label className="wide">Subject<input aria-label="Support case subject" value={draft.subject} onChange={(event) => setDraft({ ...draft, subject: event.target.value })} maxLength={200} /><FieldError value={errors.subject} /></label>
    <label className="wide">Issue description<textarea aria-label="Support case description" rows={6} value={draft.description} onChange={(event) => setDraft({ ...draft, description: event.target.value })} maxLength={8000} /><small className="field-hint">Include the record number, expected result, actual result, and when it occurred. Do not include passwords or MFA codes.</small><FieldError value={errors.description} /></label>
  </div><div className="form-actions"><button type="button" className="secondary" onClick={close}>Close</button><button type="submit" className="primary">Open support case</button></div></fieldset></form>;
}

function ArticleDetail({ article }: { article: HelpArticle }) {
  return <article className="help-article-detail"><div className="detail-status"><StatusBadge status={article.category} />{article.related_screen_code ? <code>{article.related_screen_code}</code> : <span>All roles</span>}</div><h2>{article.title}</h2><p className="help-article-summary">{article.summary}</p>{article.sections?.map((section) => <section key={section.heading}><h3>{section.heading}</h3><p>{section.content}</p></section>)}<small>Published content v{article.content_version} · updated {dateTime(article.updated_at)}</small></article>;
}

function CaseDetail({ record, busy, start, action }: { record: HelpSupportCase; busy: boolean; start: () => Promise<void>; action: (value: ActionDraft['action']) => void }) {
  return <div className="requisition-detail help-case-detail"><div className="detail-status"><StatusBadge status={record.status} /><b>{record.case_number}</b><span>v{record.record_version}</span></div><h3>{record.subject}</h3><dl className="control-definition">
    <Datum label="Requester" value={record.requester.name} /><Datum label="Support owner" value={record.assigned_to?.name ?? 'Unassigned'} />
    <Datum label="Category / priority" value={`${label(record.category)} · ${label(record.priority)}`} /><Datum label="Affected screen" value={record.affected_screen_code ?? 'General'} />
    <Datum label="Opened" value={dateTime(record.created_at)} /><Datum label="Last updated" value={dateTime(record.updated_at)} />
  </dl><div className="callout help-description">{record.description}</div>{record.resolution_summary ? <div className="help-resolution"><b>Proposed resolution</b><p>{record.resolution_summary}</p><small>{record.resolved_by?.name ?? 'Support manager'} · {record.resolved_at ? dateTime(record.resolved_at) : '—'}</small></div> : null}
    <section className="help-timeline"><h4>Versioned case history</h4>{record.events?.map((event) => <div key={event.id}><span><StatusBadge status={event.event_type} /><b>{event.actor.name}</b><small>{dateTime(event.created_at)}</small></span><p>{event.message}</p>{event.status_from !== event.status_to ? <code>{event.status_from ?? '—'} → {event.status_to ?? '—'}</code> : null}</div>)}</section>
    <div className="p2-action-grid help-actions">
      {record.allowed_actions.includes('START') ? <button type="button" className="primary" disabled={busy} onClick={() => void start()}>Start support work</button> : null}
      {record.allowed_actions.includes('COMMENT') ? <button type="button" className="secondary" disabled={busy} onClick={() => action('COMMENT')}>Add comment</button> : null}
      {record.allowed_actions.includes('RESOLVE') ? <button type="button" className="primary" disabled={busy} onClick={() => action('RESOLVE')}>Propose resolution</button> : null}
      {record.allowed_actions.includes('REOPEN') ? <button type="button" className="secondary" disabled={busy} onClick={() => action('REOPEN')}>Reopen case</button> : null}
      {record.allowed_actions.includes('CLOSE') ? <button type="button" className="primary" disabled={busy} onClick={() => action('CLOSE')}>Confirm & close</button> : null}
    </div>
  </div>;
}

function CaseActionForm({ draft, setDraft, busy, errors, submit, close }: { draft: ActionDraft; setDraft: (value: ActionDraft) => void; busy: boolean; errors: Record<string, string>; submit: (event: FormEvent) => void; close: () => void }) {
  const copy = {
    COMMENT: ['Add case comment', 'Record troubleshooting context or a requester update.', 'Comment', 'Add comment'],
    RESOLVE: ['Propose resolution', 'Explain the cause, corrective action, and evidence the requester should verify.', 'Resolution summary', 'Record proposed resolution'],
    REOPEN: ['Reopen support case', 'Explain what remains unresolved so support can continue from the retained history.', 'Reopen reason', 'Reopen case'],
    CLOSE: ['Confirm resolution', 'Confirm that the proposed resolution worked. Closing makes the case history read-only.', 'Confirmation', 'Confirm and close'],
  }[draft.action];
  return <form className="requisition-decision help-action-form" onSubmit={submit}><fieldset disabled={busy}><div className="detail-status"><StatusBadge status={draft.action} /></div><h3>{copy[0]}</h3><p>{copy[1]}</p><label>{copy[2]}<textarea aria-label={copy[2]} rows={6} value={draft.text} onChange={(event) => setDraft({ ...draft, text: event.target.value })} /><FieldError value={errors.message ?? errors.resolution_summary ?? errors.reason ?? errors.confirmation} /></label><div className="form-actions"><button type="button" className="secondary" onClick={close}>Back</button><button type="submit" className="primary">{copy[3]}</button></div></fieldset></form>;
}

function Metric({ label: text, value }: { label: string; value: string | number }) { return <div><span>{text}</span><b>{value}</b></div>; }
function Datum({ label: text, value }: { label: string; value: string }) { return <div><dt>{text}</dt><dd>{value}</dd></div>; }
function FieldError({ value }: { value?: string }) { return value ? <span className="field-error">{value}</span> : null; }
function Empty({ text }: { text: string }) { return <div className="empty-state">{text}</div>; }
function label(value: string) { return value.toLowerCase().replace(/_/g, ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()); }
function dateTime(value: string) { const parsed = new Date(value); return Number.isNaN(parsed.valueOf()) ? value : new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium', timeStyle: 'short' }).format(parsed); }
function message(error: unknown, fallback: string) { return isApiError(error) ? error.message : error instanceof Error ? error.message : fallback; }
function capture(error: unknown, setError: (value: string) => void, setFields: (value: Record<string, string>) => void, fallback: string) { setError(message(error, fallback)); setFields(isApiError(error) && error.fields ? Object.fromEntries(Object.entries(error.fields).map(([key, values]) => [key, values[0]])) : {}); }

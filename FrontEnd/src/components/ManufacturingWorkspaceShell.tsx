import type { Dispatch, ReactNode, SetStateAction } from 'react';
import { isApiError } from '../api/client';
import { useErpSession } from '../app/ErpSessionContext';
import { PageHeader } from './PageHeader';
import { StatusBadge } from './StatusBadge';

export type Feedback = { error: string | null; success: string | null; fields: Record<string, string> };
export const emptyFeedback = (): Feedback => ({ error: null, success: null, fields: {} });

export function ManufacturingWorkspace({ code, title, description, notice, loading, feedback, workspace,
  search, setSearch, status, setStatus, sort, setSort, page, setPage, onNew, headings, rows, ids,
  selectedId, onOpen, children }: {
  code: string; title: string; description: string; notice: string; loading: boolean; feedback: Feedback;
  workspace: { lookups: { statuses: string[]; sorts: string[] }; meta: { current_page: number; last_page: number; total: number }; summary: Record<string, number | string> } | null;
  search: string; setSearch: (value: string) => void; status: string; setStatus: (value: string) => void;
  sort: string; setSort: (value: string) => void; page: number; setPage: Dispatch<SetStateAction<number>>;
  onNew?: () => void; headings: string[]; rows: ReactNode[][]; ids: string[]; selectedId?: string;
  onOpen: (id: string) => void; children: ReactNode;
}) {
  return <>
    <PageHeader code={code} batch="P1 Manufacturing execution" title={title} description={description} onNew={onNew} />
    <div className="live-notice procurement-notice"><span />{notice}</div>
    {workspace ? <SummaryStrip summary={workspace.summary} /> : null}
    <div className="module-grid requisition-workspace manufacturing-workspace">
      <section className="panel">
        <div className="requisition-toolbar">
          <label>Search<input aria-label={`${title} search`} value={search} onChange={(event) => { setPage(1); setSearch(event.target.value); }} placeholder="Number, lot, batch or SKU" /></label>
          <label>Status<select aria-label={`${title} status`} value={status} onChange={(event) => { setPage(1); setStatus(event.target.value); }}><option value="">All statuses</option>{workspace?.lookups.statuses.map((item) => <option key={item}>{item}</option>)}</select></label>
          <label>Sort<select aria-label={`${title} sort`} value={sort} onChange={(event) => { setPage(1); setSort(event.target.value); }}>{workspace?.lookups.sorts.map((item) => <option key={item}>{item}</option>)}</select></label>
        </div>
        {loading && !workspace ? <Empty text={`Loading ${title.toLowerCase()}...`} /> : <>
          <div className="table-wrap"><table className="requisition-table"><thead><tr>{headings.map((heading) => <th key={heading}>{heading}</th>)}<th /></tr></thead><tbody>{rows.map((cells, index) => <tr key={ids[index]} className={selectedId === ids[index] ? 'selected-row' : ''}>{cells.map((cell, position) => <td key={position}>{cell}</td>)}<td><button className="secondary compact-button" type="button" onClick={() => void onOpen(ids[index])}>Open</button></td></tr>)}</tbody></table></div>
          {!rows.length ? <Empty text={`No ${title.toLowerCase()} match the current filters.`} /> : null}
          {workspace ? <div className="pagination"><button type="button" disabled={page <= 1 || loading} onClick={() => setPage((value) => value - 1)}>Previous</button><span>Page {workspace.meta.current_page} of {workspace.meta.last_page} · {workspace.meta.total} records</span><button type="button" disabled={page >= workspace.meta.last_page || loading} onClick={() => setPage((value) => value + 1)}>Next</button></div> : null}
        </>}
      </section>
      <aside className="panel requisition-editor"><div className="requisition-detail-body">{feedback.error ? <div className="form-error" role="alert"><span>{feedback.error}</span></div> : null}{feedback.success ? <div className="form-success" role="status"><span />{feedback.success}</div> : null}{children}</div></aside>
    </div>
  </>;
}

export function SummaryStrip({ summary }: { summary: Record<string, number | string> }) {
  return <div className="manufacturing-summary">{Object.entries(summary).slice(0, 6).map(([key, value]) => <div key={key}><span>{key.replaceAll('_', ' ')}</span><b>{typeof value === 'string' && /^-?\d+(\.\d+)?$/.test(value) ? decimal(value) : value}</b></div>)}</div>;
}
export function Detail({ children }: { children: ReactNode }) { return <div className="requisition-detail">{children}</div>; }
export function DetailHead({ status, version, label }: { status: string; version?: number; label?: string }) { return <div className="detail-status"><StatusBadge status={status} />{version !== undefined ? <span>record version {version}</span> : null}{label ? <span>{label}</span> : null}</div>; }
export function Datum({ text, value, wide = false }: { text: string; value: ReactNode; wide?: boolean }) { return <div className={wide ? 'wide' : undefined}><dt>{text}</dt><dd>{value}</dd></div>; }
export function Field({ text }: { text?: string }) { return text ? <small className="field-error">{text}</small> : null; }
export function Empty({ text }: { text: string }) {
  const loading = /^Loading\b/i.test(text);
  return <div className={`empty-state shared-state ${loading ? 'is-loading' : ''}`} role={loading ? 'status' : undefined} aria-live={loading ? 'polite' : undefined}><span aria-hidden="true" />{text}</div>;
}
export function FormActions({ onClose, submit, busy = false }: { onClose: () => void; submit: string; busy?: boolean }) { return <div className="form-actions"><button className="secondary" type="button" disabled={busy} onClick={onClose}>Close</button><button className="primary" type="submit" disabled={busy}>{submit}</button></div>; }
export function LineTable({ heads, rows }: { heads: string[]; rows: ReactNode[][] }) { return <div className="table-wrap"><table><thead><tr>{heads.map((head) => <th key={head}>{head}</th>)}</tr></thead><tbody>{rows.map((row, index) => <tr key={index}>{row.map((cell, position) => <td key={position}>{cell}</td>)}</tr>)}</tbody></table></div>; }
export function useManufacturingContextKey() { const session = useErpSession(); return `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`; }
export function applyFailure(set: Dispatch<SetStateAction<Feedback>>, error: unknown, fallback: string) {
  const api = isApiError(error) ? error : null; const fields: Record<string, string> = {};
  Object.entries(api?.fields ?? {}).forEach(([key, values]) => { fields[key] = values[0] ?? ''; });
  set({ error: api?.message ?? (error instanceof Error ? error.message : fallback), success: null, fields });
}
export function nullable(value: string) { const trimmed = value.trim(); return trimmed || null; }
export function numeric(value: unknown) { const parsed = Number(value); return Number.isFinite(parsed) ? parsed : 0; }
export function decimal(value: unknown) { return new Intl.NumberFormat('en-IN', { maximumFractionDigits: 6 }).format(numeric(value)); }
export function date(value: string | null | undefined) { return value ? new Intl.DateTimeFormat('en-IN', { day: '2-digit', month: 'short', year: 'numeric', timeZone: 'UTC' }).format(new Date(`${value.slice(0, 10)}T00:00:00Z`)) : '—'; }
export function dateTime(value: string | null | undefined) { return value ? new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value)) : '—'; }
export function today() { return new Date().toISOString().slice(0, 10); }
export function addMonths(value: string, months: number) { const result = new Date(`${value}T00:00:00Z`); result.setUTCMonth(result.getUTCMonth() + months); return result.toISOString().slice(0, 10); }

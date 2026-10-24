import { useCallback, useEffect, useState, type FormEvent } from 'react';
import { isApiError } from '../api/client';
import { downloadP2, getP2, listP2, uploadP2, type P2Record, type P2Workspace } from '../api/p2Operations';
import { useErpSession } from '../app/ErpSessionContext';
import { PageHeader } from './PageHeader';
import { StatusBadge } from './StatusBadge';
import { SummaryStrip } from './ManufacturingWorkspaceShell';

type ArchiveDraft = {
  document_number: string;
  invoice_id: string;
  document_type: string;
  document_date: string;
  retain_until: string;
  notes: string;
};

export function FinanceArchiveWorkspace() {
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const [workspace, setWorkspace] = useState<P2Workspace | null>(null);
  const [selected, setSelected] = useState<P2Record | null>(null);
  const [draft, setDraft] = useState<ArchiveDraft | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    try { setWorkspace(await listP2('/api/v1/finance/archive', { q: search || undefined })); setError(null); }
    catch (caught) { setError(message(caught, 'Unable to load the private bill archive.')); }
    finally { setLoading(false); }
  }, [contextKey, search]);

  useEffect(() => { void refresh(); }, [refresh]);
  useEffect(() => { setSelected(null); setDraft(null); setFile(null); setSearch(''); setError(null); setSuccess(null); }, [contextKey]);

  const documents = workspace?.data ?? [];
  const canUpload = Boolean(workspace?.allowed_actions?.includes('UPLOAD'));
  const documentTypes = archiveTypes(workspace);

  function startUpload() {
    const retained = new Date(); retained.setUTCFullYear(retained.getUTCFullYear() + 7);
    setSelected(null); setFile(null); setError(null); setSuccess(null);
    setDraft({ document_number: archiveNumber(), invoice_id: '', document_type: documentTypes[0] ?? 'SUPPLIER_INVOICE', document_date: new Date().toISOString().slice(0, 10), retain_until: retained.toISOString().slice(0, 10), notes: '' });
  }

  async function open(record: P2Record) {
    setDraft(null); setFile(null); setError(null); setSuccess(null);
    try { setSelected(await getP2(`/api/v1/finance/archive/${record.id}`)); }
    catch (caught) { setError(message(caught, 'Unable to load archived document metadata.')); }
  }

  async function submit(event: FormEvent) {
    event.preventDefault(); if (!draft || !file) { setError('Choose a supported private document before uploading.'); return; }
    const body = new FormData();
    body.set('document_number', draft.document_number); body.set('document_type', draft.document_type);
    body.set('document_date', draft.document_date); body.set('retain_until', draft.retain_until);
    if (draft.invoice_id.trim()) body.set('invoice_id', draft.invoice_id.trim());
    if (draft.notes.trim()) body.set('notes', draft.notes.trim());
    body.set('file', file);
    setBusy(true); setError(null); setSuccess(null);
    try { const result = await uploadP2('/api/v1/finance/archive', body); setDraft(null); setFile(null); setSuccess(`Document archived with verified private metadata (${result.status}).`); await refresh(); }
    catch (caught) { setError(message(caught, 'Unable to archive the private document.')); }
    finally { setBusy(false); }
  }

  async function download() {
    if (!selected) return; setBusy(true); setError(null); setSuccess(null);
    try { save(await downloadP2(`/api/v1/finance/archive/${selected.id}/download`), String(selected.original_name ?? `${selected.document_number}.bin`)); setSuccess('Private document downloaded after permission and scope verification.'); }
    catch (caught) { setError(message(caught, 'Unable to download the private document.')); }
    finally { setBusy(false); }
  }

  return <>
    <PageHeader code="FIN-ARCH" batch="P2 operational" title="Private Bill Archive" description="Retain scoped finance evidence with verified checksums and controlled retrieval." onNew={canUpload ? startUpload : undefined} />
    <div className="live-notice p2-live-notice"><span />Live private storage only. MIME, size, duplicate checksum, scope and retention policy are enforced before metadata is committed.</div>
    {workspace?.summary ? <SummaryStrip summary={workspace.summary} /> : null}
    <div className="module-grid requisition-workspace p2-workspace">
      <section className="panel">
        <div className="requisition-toolbar p2-toolbar"><label>Search<input aria-label="Archive search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Document number" /></label><div /><button type="button" className="secondary compact-button" disabled={loading} onClick={() => void refresh()}>Refresh</button></div>
        {loading && !workspace ? <Empty text="Loading private archive..." /> : documents.length ? <div className="table-wrap"><table className="requisition-table p2-register"><thead><tr><th>Document</th><th>Type</th><th>Date</th><th>File</th><th>Size</th><th>Status</th><th /></tr></thead><tbody>{documents.map((record) => <tr key={record.id} className={selected?.id === record.id ? 'selected-row' : ''}><td>{String(record.document_number ?? '—')}</td><td>{String(record.document_type ?? '—')}</td><td>{formatDate(record.document_date)}</td><td>{String(record.original_name ?? '—')}</td><td>{formatBytes(record.size_bytes)}</td><td><StatusBadge status="ARCHIVED" /></td><td><button type="button" className="secondary compact-button" onClick={() => void open(record)}>Open</button></td></tr>)}</tbody></table></div> : <Empty text="No archived documents match this context and search." />}
      </section>
      <aside className="panel requisition-editor"><div className="requisition-detail-body">
        {error ? <div className="form-error" role="alert"><span>{error}</span></div> : null}
        {success ? <div className="form-success" role="status"><span />{success}</div> : null}
        {draft ? <ArchiveForm draft={draft} setDraft={setDraft} file={file} setFile={setFile} documentTypes={documentTypes} busy={busy} submit={submit} close={() => { setDraft(null); setFile(null); }} /> : selected ? <ArchiveDetail record={selected} busy={busy} download={download} /> : <Empty text={canUpload ? 'Choose a document or start a governed private upload.' : 'Choose a document to inspect its retention and checksum metadata.'} />}
      </div></aside>
    </div>
  </>;
}

function ArchiveForm({ draft, setDraft, file, setFile, documentTypes, busy, submit, close }: { draft: ArchiveDraft; setDraft: (value: ArchiveDraft) => void; file: File | null; setFile: (value: File | null) => void; documentTypes: string[]; busy: boolean; submit: (event: FormEvent) => void; close: () => void }) {
  return <form className="p2-command-editor p2-archive-form" onSubmit={submit}><fieldset disabled={busy}><div className="detail-status"><StatusBadge status="PRIVATE UPLOAD" /><span>20 MB maximum</span></div><h2>Archive finance document</h2><div className="form-grid">
    <label>Document number<input aria-label="Archive document number" value={draft.document_number} onChange={(event) => setDraft({ ...draft, document_number: event.target.value.toUpperCase() })} required /></label>
    <label>Document type<select aria-label="Archive document type" value={draft.document_type} onChange={(event) => setDraft({ ...draft, document_type: event.target.value })}>{documentTypes.map((type) => <option key={type}>{type}</option>)}</select></label>
    <label>Document date<input aria-label="Archive document date" type="date" value={draft.document_date} onChange={(event) => setDraft({ ...draft, document_date: event.target.value })} required /></label>
    <label>Retain until<input aria-label="Archive retain until" type="date" min={draft.document_date} value={draft.retain_until} onChange={(event) => setDraft({ ...draft, retain_until: event.target.value })} required /></label>
    <label className="full-span">Related invoice ID (optional)<input aria-label="Archive invoice ID" value={draft.invoice_id} onChange={(event) => setDraft({ ...draft, invoice_id: event.target.value })} placeholder="UUID" /></label>
    <label className="full-span">Notes<textarea aria-label="Archive notes" rows={3} value={draft.notes} onChange={(event) => setDraft({ ...draft, notes: event.target.value })} /></label>
    <label className="full-span">Private document<input aria-label="Archive private document" type="file" accept=".pdf,.png,.jpg,.jpeg,.csv,.txt" onChange={(event) => setFile(event.currentTarget.files?.[0] ?? null)} /></label>
  </div>{file ? <div className="callout">Selected {file.name} · {formatBytes(file.size)}. The server will calculate and persist its SHA-256 checksum.</div> : null}<div className="form-actions"><button type="button" className="secondary" onClick={close}>Close</button><button type="submit" className="primary">Upload privately</button></div></fieldset></form>;
}

function ArchiveDetail({ record, busy, download }: { record: P2Record; busy: boolean; download: () => void }) {
  const downloadable = record.allowed_actions?.includes('DOWNLOAD');
  return <div className="requisition-detail p2-detail"><div className="detail-status"><StatusBadge status="ARCHIVED" /><span>{String(record.document_number)}</span></div><dl className="control-definition">
    <div><dt>Document type</dt><dd>{String(record.document_type ?? '—')}</dd></div><div><dt>Document date</dt><dd>{formatDate(record.document_date)}</dd></div>
    <div><dt>Original file</dt><dd>{String(record.original_name ?? '—')}</dd></div><div><dt>MIME type</dt><dd>{String(record.mime_type ?? '—')}</dd></div>
    <div><dt>Size</dt><dd>{formatBytes(record.size_bytes)}</dd></div><div><dt>Retain until</dt><dd>{formatDate(record.retain_until)}</dd></div>
    <div><dt>SHA-256</dt><dd className="p2-checksum">{String(record.sha256_checksum ?? '—')}</dd></div><div><dt>Related invoice</dt><dd>{String(record.invoice_id ?? '—')}</dd></div>
  </dl>{record.notes ? <div className="callout">{String(record.notes)}</div> : null}{downloadable ? <div className="form-actions"><button className="primary" type="button" disabled={busy} onClick={() => void download()}>Download private document</button></div> : <div className="callout">Your role can inspect metadata but cannot retrieve private bytes.</div>}</div>;
}

function archiveTypes(workspace: P2Workspace | null): string[] { const lookups = workspace?.lookups; const value = lookups && typeof lookups === 'object' ? (lookups as Record<string, unknown>).document_types : null; return Array.isArray(value) ? value.map(String) : []; }
function archiveNumber() { return `DOC-${new Date().toISOString().replace(/\D/g, '').slice(2, 14)}`; }
function formatDate(value: unknown) { if (!value) return '—'; const parsed = new Date(String(value).length === 10 ? `${value}T00:00:00Z` : String(value)); return Number.isNaN(parsed.valueOf()) ? String(value) : new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium' }).format(parsed); }
function formatBytes(value: unknown) { const bytes = Number(value); if (!Number.isFinite(bytes)) return '—'; if (bytes < 1024) return `${bytes} B`; if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`; return `${(bytes / 1024 / 1024).toFixed(1)} MB`; }
function message(error: unknown, fallback: string) { return isApiError(error) ? error.message : error instanceof Error ? error.message : fallback; }
function save(blob: Blob, filename: string) { const url = URL.createObjectURL(blob); const anchor = document.createElement('a'); anchor.href = url; anchor.download = filename; document.body.append(anchor); anchor.click(); anchor.remove(); URL.revokeObjectURL(url); }
function Empty({ text }: { text: string }) { return <div className="empty-state">{text}</div>; }

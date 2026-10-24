import { useState } from 'react';
import { commitImport, downloadImportErrors, previewImport, rollbackImport, type ImportPreview } from '../api/experience';
import { isApiError } from '../api/client';

export function BulkImportCentre({ allowedScreens, allowedActions }: { allowedScreens: string[]; allowedActions: string[] }) {
  const options = [allowedScreens.includes('MD-PARTY') && { value: 'PARTIES' as const, label: 'Customers & suppliers', permission: 'ACTION:MD-PARTY:CREATE' }, allowedScreens.includes('MD-ITEM') && { value: 'ITEMS' as const, label: 'Items & SKUs', permission: 'ACTION:MD-ITEM:CREATE' }].filter(Boolean) as Array<{ value: 'PARTIES' | 'ITEMS'; label: string; permission: string }>;
  const [entity, setEntity] = useState<'PARTIES' | 'ITEMS'>(options[0]?.value ?? 'PARTIES');
  const [file, setFile] = useState<File | null>(null);
  const [preview, setPreview] = useState<ImportPreview | null>(null);
  const [status, setStatus] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  if (!options.length) return null;
  const canCommit = allowedActions.includes(options.find((option) => option.value === entity)?.permission ?? '');

  async function validate() { if (!file) return; setBusy(true); setError(null); setStatus(null); try { setPreview(await previewImport(entity, file)); } catch (caught) { setError(isApiError(caught) ? caught.message : 'Unable to validate the CSV file.'); } finally { setBusy(false); } }
  async function commit() { if (!preview) return; setBusy(true); try { await commitImport(preview.id); setStatus(`${preview.row_count} validated records were imported as drafts.`); } catch (caught) { setError(isApiError(caught) ? caught.message : 'Unable to commit this import.'); } finally { setBusy(false); } }
  async function rollback() { if (!preview) return; setBusy(true); try { await rollbackImport(preview.id); setStatus('The imported draft records were rolled back.'); } catch (caught) { setError(isApiError(caught) ? caught.message : 'Rollback is unavailable because imported records are already in use.'); } finally { setBusy(false); } }
  async function downloadErrors() { if (!preview) return; const blob = await downloadImportErrors(preview.id); const url = URL.createObjectURL(blob); const link = document.createElement('a'); link.href = url; link.download = `qtfoods-import-errors-${preview.id}.csv`; link.click(); URL.revokeObjectURL(url); }

  return <div className="bulk-import-centre">
    <div><b>Governed bulk import</b><small>Preview every row before creating draft master records.</small></div>
    <div className="bulk-import-controls"><select aria-label="Import record type" value={entity} onChange={(event) => { setEntity(event.target.value as 'PARTIES' | 'ITEMS'); setPreview(null); }}><option value="PARTIES">Customers & suppliers</option><option value="ITEMS">Items & SKUs</option></select><input aria-label="CSV import file" type="file" accept=".csv,text/csv" onChange={(event) => { setFile(event.target.files?.[0] ?? null); setPreview(null); }} /><button className="secondary" type="button" disabled={!file || busy} onClick={() => void validate()}>{busy ? 'Checking…' : 'Preview & validate'}</button></div>
    {error && <div className="form-error" role="alert">{error}</div>}{status && <div className="form-success" role="status">{status}</div>}
    {preview && <div className="import-preview"><div className="import-summary"><b>{preview.row_count} rows</b><span className={preview.error_count ? 'text-bad' : 'text-good'}>{preview.error_count ? `${preview.error_count} validation errors` : 'Ready to import'}</span><div>{preview.error_count > 0 && <button className="secondary" type="button" onClick={() => void downloadErrors()}>Download error report</button>}{preview.status === 'VALID' && <button className="primary" type="button" disabled={!canCommit || busy} title={!canCommit ? 'Create permission is required' : undefined} onClick={() => void commit()}>Commit drafts</button>}{status && <button className="secondary" type="button" disabled={busy} onClick={() => void rollback()}>Rollback import</button>}</div></div>
      {preview.errors.length > 0 && <div className="table-wrap"><table><thead><tr><th>Row</th><th>Field</th><th>Problem</th><th>Value</th></tr></thead><tbody>{preview.errors.slice(0, 20).map((item, index) => <tr key={`${item.row}-${item.field}-${index}`}><td>{item.row}</td><td>{item.field}</td><td>{item.message}</td><td>{item.value || '—'}</td></tr>)}</tbody></table></div>}
    </div>}
  </div>;
}

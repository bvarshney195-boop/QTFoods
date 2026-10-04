import { useCallback, useEffect, useMemo, useState, type FormEvent } from 'react';
import { isApiError } from '../api/client';
import { commandP2, downloadP2, getP2, listP2, uploadP2, type P2Record, type P2Workspace } from '../api/p2Operations';
import { useErpSession } from '../app/ErpSessionContext';
import { PageHeader } from './PageHeader';
import { StatusBadge } from './StatusBadge';
import { SummaryStrip } from './ManufacturingWorkspaceShell';

type PortalWorkspace = P2Workspace & {
  mode: 'INTERNAL' | 'PARTNER';
  identity: Record<string, unknown>;
  entitlements: string[];
  access_grants: P2Record[];
  documents: P2Record[];
  orders: P2Record[];
  shipments: P2Record[];
  invoices: P2Record[];
  claims: P2Record[];
};

type EditorKind = 'GRANT' | 'GRANT_UPDATE' | 'REVOKE' | 'PUBLISH' | 'UPLOAD' | 'CLAIM' | 'ACKNOWLEDGE' | 'WITHDRAW';
type Editor = {
  kind: EditorKind;
  title: string;
  record?: P2Record;
  draft: Record<string, string | string[]>;
};
type Tab = { key: string; label: string; columns: Array<[string, string, 'date' | 'money' | 'text']> };

const partnerTabs: Tab[] = [
  { key: 'orders', label: 'Orders', columns: [['Order', 'order_number', 'text'], ['Order date', 'order_date', 'date'], ['Delivery', 'requested_delivery_date', 'date'], ['Total', 'total_amount', 'money']] },
  { key: 'shipments', label: 'Shipments', columns: [['Shipment', 'shipment_number', 'text'], ['Order', 'order_number', 'text'], ['Dispatched', 'dispatched_at', 'date'], ['Invoice', 'invoice_number', 'text']] },
  { key: 'invoices', label: 'Invoices', columns: [['Invoice', 'invoice_number', 'text'], ['Issued', 'issued_at', 'date'], ['Due', 'due_date', 'date'], ['Gross', 'gross_amount', 'money'], ['Outstanding', 'outstanding_amount', 'money']] },
  { key: 'claims', label: 'Claims', columns: [['Claim', 'claim_number', 'text'], ['Shipment', 'shipment_number', 'text'], ['Type', 'claim_type', 'text'], ['Resolution', 'requested_resolution', 'text']] },
  { key: 'documents', label: 'Documents', columns: [['Document', 'document_number', 'text'], ['Direction', 'direction', 'text'], ['Type', 'document_type', 'text'], ['File', 'original_name', 'text']] },
];

const internalTabs: Tab[] = [
  { key: 'access_grants', label: 'Access grants', columns: [['Identity', 'user_name', 'text'], ['Email', 'user_email', 'text'], ['Partner', 'party_name', 'text'], ['Effective access', 'effective_status', 'text']] },
  { key: 'documents', label: 'Document exchange', columns: [['Document', 'document_number', 'text'], ['Partner', 'party_name', 'text'], ['Direction', 'direction', 'text'], ['Type', 'document_type', 'text']] },
];

export function PartnerPortalWorkspace() {
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const [workspace, setWorkspace] = useState<PortalWorkspace | null>(null);
  const [tabKey, setTabKey] = useState('documents');
  const [selected, setSelected] = useState<P2Record | null>(null);
  const [editor, setEditor] = useState<Editor | null>(null);
  const [file, setFile] = useState<File | null>(null);
  const [search, setSearch] = useState('');
  const [loading, setLoading] = useState(true);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [success, setSuccess] = useState<string | null>(null);

  const refresh = useCallback(async () => {
    setLoading(true);
    try {
      const next = await listP2('/api/v1/partner/workspaces', { q: search || undefined }) as PortalWorkspace;
      setWorkspace(next);
      setTabKey((current) => visibleTabs(next).some((tab) => tab.key === current) ? current : visibleTabs(next)[0]?.key ?? 'documents');
      setError(null);
    } catch (caught) {
      setError(errorMessage(caught, 'Unable to load the partner portal.'));
    } finally {
      setLoading(false);
    }
  }, [contextKey, search]);

  useEffect(() => { void refresh(); }, [refresh]);
  useEffect(() => { setSelected(null); setEditor(null); setFile(null); setSearch(''); setError(null); setSuccess(null); }, [contextKey]);

  const tabs = useMemo(() => workspace ? visibleTabs(workspace) : [], [workspace]);
  const tab = tabs.find((candidate) => candidate.key === tabKey) ?? tabs[0];
  const records = tab && workspace ? collection(workspace, tab.key) : [];
  const actions = workspace?.allowed_actions ?? [];

  async function open(record: P2Record) {
    if (!tab) return;
    setEditor(null); setFile(null); setError(null); setSuccess(null);
    const resource = resourceFor(tab.key);
    try {
      setSelected(resource ? await getP2(`/api/v1/partner/${resource}/${record.id}`) : record);
    } catch (caught) {
      setError(errorMessage(caught, 'Unable to load the selected portal record.'));
    }
  }

  async function submit(event: FormEvent) {
    event.preventDefault();
    if (!editor || !workspace) return;
    setBusy(true); setError(null); setSuccess(null);
    try {
      if (editor.kind === 'GRANT' || editor.kind === 'GRANT_UPDATE') {
        const body = {
          ...(editor.kind === 'GRANT' ? { user_id: text(editor.draft.user_id), party_id: text(editor.draft.party_id) } : {}),
          effective_from: nullable(text(editor.draft.effective_from)),
          effective_to: nullable(text(editor.draft.effective_to)),
          entitlements: values(editor.draft.entitlements),
        };
        const path = editor.kind === 'GRANT' ? '/api/v1/partner/access-grants' : `/api/v1/partner/access-grants/${editor.record?.id}`;
        await commandP2(path, body, editor.kind === 'GRANT_UPDATE' ? editor.record?.record_version : undefined);
      } else if (editor.kind === 'PUBLISH' || editor.kind === 'UPLOAD') {
        if (!file) throw new Error('Choose a supported document before submitting.');
        const body = new FormData();
        if (editor.kind === 'PUBLISH') body.set('party_id', text(editor.draft.party_id));
        body.set('document_number', text(editor.draft.document_number));
        body.set('document_type', text(editor.draft.document_type));
        body.set('title', text(editor.draft.title));
        if (text(editor.draft.description)) body.set('description', text(editor.draft.description));
        const referenceType = text(editor.draft.reference_type);
        if (referenceType && referenceType !== 'NONE' && text(editor.draft.reference_id)) body.set(referenceType, text(editor.draft.reference_id));
        body.set('file', file);
        await uploadP2(editor.kind === 'PUBLISH' ? '/api/v1/partner/documents/publish' : '/api/v1/partner/documents', body);
      } else if (editor.kind === 'CLAIM') {
        await commandP2('/api/v1/partner/claims', {
          claim_number: text(editor.draft.claim_number), shipment_id: text(editor.draft.shipment_id),
          claim_type: text(editor.draft.claim_type), requested_resolution: text(editor.draft.requested_resolution),
          reason: text(editor.draft.reason), lines: [{ shipment_line_id: text(editor.draft.shipment_line_id), quantity: text(editor.draft.quantity) }],
        });
      } else if (editor.kind === 'ACKNOWLEDGE') {
        await commandP2(`/api/v1/partner/documents/${editor.record?.id}/acknowledge`, {
          acknowledgement_reference: text(editor.draft.reference),
        }, editor.record?.record_version);
      } else if (editor.kind === 'REVOKE') {
        await commandP2(`/api/v1/partner/access-grants/${editor.record?.id}/revoke`, { reason: text(editor.draft.reason) }, editor.record?.record_version);
      } else if (editor.kind === 'WITHDRAW') {
        await commandP2(`/api/v1/partner/documents/${editor.record?.id}/withdraw`, { reason: text(editor.draft.reason) }, editor.record?.record_version);
      }
      setSuccess(successMessage(editor.kind)); setEditor(null); setSelected(null); setFile(null);
      await refresh();
    } catch (caught) {
      setError(errorMessage(caught, 'Unable to complete the portal action.'));
    } finally {
      setBusy(false);
    }
  }

  async function download(record: P2Record) {
    setBusy(true); setError(null); setSuccess(null);
    try {
      saveBlob(await downloadP2(`/api/v1/partner/documents/${record.id}/download`), String(record.original_name ?? `${record.document_number ?? 'partner-document'}.bin`));
      setSuccess('Document downloaded after permission and party-scope verification.');
    } catch (caught) {
      setError(errorMessage(caught, 'Unable to download the partner document.'));
    } finally {
      setBusy(false);
    }
  }

  function startGrant(record?: P2Record) {
    const lookupUsers = rows(workspace, 'users').filter((user) => Boolean(user.portal_eligible));
    const customers = rows(workspace, 'customers');
    setSelected(null); setFile(null); setError(null); setSuccess(null);
    setEditor({
      kind: record ? 'GRANT_UPDATE' : 'GRANT', title: record ? 'Update access entitlements' : 'Grant partner access', record,
      draft: {
        user_id: String(record?.user_id ?? lookupUsers[0]?.id ?? ''), party_id: String(record?.party_id ?? customers[0]?.id ?? ''),
        effective_from: inputDateTime(record?.effective_from), effective_to: inputDateTime(record?.effective_to),
        entitlements: record ? values(record.entitlements).map(String) : lookupStrings(workspace, 'entitlements'),
      },
    });
  }

  function startDocument(kind: 'PUBLISH' | 'UPLOAD') {
    const customers = rows(workspace, 'customers');
    setSelected(null); setFile(null); setError(null); setSuccess(null);
    setEditor({ kind, title: kind === 'PUBLISH' ? 'Publish outbound document' : 'Upload document to Q & T Foods', draft: {
      party_id: String(customers[0]?.id ?? ''), document_number: portalNumber(kind === 'PUBLISH' ? 'OUT' : 'IN'),
      document_type: lookupStrings(workspace, 'document_types')[0] ?? 'GENERAL', title: '', description: '',
      reference_type: 'NONE', reference_id: '',
    } });
  }

  function startClaim(shipment?: P2Record) {
    const claimable = rows(workspace, 'claimable_shipments');
    const chosen = shipment ? claimable.find((row) => row.id === shipment.id) ?? shipment : claimable[0];
    const line = values(chosen?.lines)[0] as P2Record | undefined;
    setSelected(null); setFile(null); setError(null); setSuccess(null);
    setEditor({ kind: 'CLAIM', title: 'Submit customer claim', draft: {
      claim_number: portalNumber('CLAIM'), shipment_id: String(chosen?.id ?? ''), shipment_line_id: String(line?.id ?? ''),
      claim_type: lookupStrings(workspace, 'claim_types')[0] ?? 'DAMAGE',
      requested_resolution: lookupStrings(workspace, 'claim_resolutions')[0] ?? 'CREDIT', reason: '', quantity: '1',
    } });
  }

  function updateDraft(field: string, value: string | string[]) {
    if (!editor) return;
    const next = { ...editor, draft: { ...editor.draft, [field]: value } };
    if (field === 'shipment_id') {
      const shipment = rows(workspace, 'claimable_shipments').find((row) => row.id === value);
      const line = values(shipment?.lines)[0] as P2Record | undefined;
      next.draft.shipment_line_id = String(line?.id ?? '');
    }
    setEditor(next);
  }

  function recordAction(action: string) {
    if (!selected) return;
    if (action === 'DOWNLOAD') { void download(selected); return; }
    if (action === 'UPDATE') { startGrant(selected); return; }
    const definitions: Record<string, [EditorKind, string, Record<string, string>]> = {
      REVOKE: ['REVOKE', 'Revoke partner access', { reason: '' }],
      ACKNOWLEDGE: ['ACKNOWLEDGE', 'Acknowledge document receipt', { reference: '' }],
      WITHDRAW: ['WITHDRAW', 'Withdraw outbound document', { reason: '' }],
    };
    const definition = definitions[action];
    if (definition) setEditor({ kind: definition[0], title: definition[1], record: selected, draft: definition[2] });
  }

  const partnerName = String(workspace?.identity?.party_name ?? 'assigned partner');
  const internal = workspace?.mode === 'INTERNAL';
  return <>
    <PageHeader code="PORTAL-EXT" batch="P3 external" title="Partner Portal" description="Exchange commercial records and documents inside an explicit party tenant." />
    <div className="live-notice p2-live-notice"><span />{internal
      ? 'Internal administration view. Access grants bind one external identity to one customer party and plant context.'
      : `External organisation: ${partnerName}. Every view and action is limited to this organisation; acknowledgements record receipt only and never approve an internal workflow.`}</div>
    {workspace?.summary ? <SummaryStrip summary={workspace.summary} /> : null}
    <div className="workspace-tabs p2-command-tabs">
      {tabs.map((item) => <button key={item.key} className={item.key === tabKey ? 'active' : ''} type="button" onClick={() => { setTabKey(item.key); setSelected(null); setEditor(null); }}>{item.label}</button>)}
      <i />
      {actions.includes('ACCESS-GRANT') ? <button className="p2-create-command" type="button" onClick={() => startGrant()}>+ Grant access</button> : null}
      {actions.includes('DOCUMENT-PUBLISH') ? <button className="p2-create-command" type="button" onClick={() => startDocument('PUBLISH')}>+ Publish document</button> : null}
      {actions.includes('CLAIM-CREATE') && rows(workspace, 'claimable_shipments').length ? <button className="p2-create-command" type="button" onClick={() => startClaim()}>+ New claim</button> : null}
      {actions.includes('DOCUMENT-UPLOAD') ? <button className="p2-create-command" type="button" onClick={() => startDocument('UPLOAD')}>+ Upload document</button> : null}
    </div>
    <div className="module-grid requisition-workspace p2-workspace portal-workspace">
      <section className="panel">
        <div className="requisition-toolbar p2-toolbar"><label>Search<input aria-label="Partner Portal search" value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Number, title, partner or email" /></label><div /><button type="button" className="secondary compact-button" disabled={loading} onClick={() => void refresh()}>Refresh</button></div>
        {loading && !workspace ? <Empty text="Loading the governed partner workspace..." /> : <PortalTable records={records} tab={tab} selectedId={selected?.id} open={open} />}
      </section>
      <aside className="panel requisition-editor"><div className="requisition-detail-body">
        {error ? <div className="form-error" role="alert"><span>{error}</span></div> : null}
        {success ? <div className="form-success" role="status"><span />{success}</div> : null}
        {editor ? <PortalEditor editor={editor} workspace={workspace} file={file} setFile={setFile} update={updateDraft} submit={submit} close={() => { setEditor(null); setFile(null); }} busy={busy} />
          : selected ? <PortalDetail record={selected} tabKey={tabKey} canClaim={Boolean(!internal && actions.includes('CLAIM-CREATE'))} action={recordAction} claim={() => startClaim(selected)} busy={busy} />
            : <Empty text={internal ? 'Choose an access grant or document, or start a new portal entry.' : 'Choose one of your available records or start a document or claim exchange.'} />}
      </div></aside>
    </div>
  </>;
}

function PortalTable({ records, tab, selectedId, open }: { records: P2Record[]; tab?: Tab; selectedId?: string; open: (record: P2Record) => void }) {
  if (!tab || !records.length) return <Empty text="No records are visible in this entitled section." />;
  return <div className="table-wrap"><table className="requisition-table p2-register"><thead><tr>{tab.columns.map(([label]) => <th key={label}>{label}</th>)}<th>Status</th><th /></tr></thead><tbody>{records.map((record) => <tr key={record.id} className={selectedId === record.id ? 'selected-row' : ''}>{tab.columns.map(([label, key, format]) => <td key={label}>{formatValue(record[key], format)}</td>)}<td><StatusBadge status={String(record.effective_status ?? record.status ?? 'AVAILABLE')} /></td><td><button type="button" className="secondary compact-button" onClick={() => void open(record)}>Open</button></td></tr>)}</tbody></table></div>;
}

function PortalDetail({ record, tabKey, canClaim, action, claim, busy }: { record: P2Record; tabKey: string; canClaim: boolean; action: (value: string) => void; claim: () => void; busy: boolean }) {
  const hidden = new Set(['allowed_actions', 'lines', 'events', 'transactions', 'proof', 'invoice']);
  const facts = Object.entries(record).filter(([key, value]) => !hidden.has(key) && (value === null || ['string', 'number', 'boolean'].includes(typeof value))).slice(0, 24);
  const related = Object.entries(record).filter(([, value]) => Array.isArray(value)) as Array<[string, unknown[]]>;
  return <div className="requisition-detail p2-detail"><div className="detail-status"><StatusBadge status={String(record.effective_status ?? record.status ?? 'AVAILABLE')} />{record.record_version ? <span>record version {record.record_version}</span> : null}</div>
    <dl className="control-definition">{facts.map(([key, value]) => <div key={key}><dt>{label(key)}</dt><dd className={key.includes('checksum') ? 'p2-checksum' : ''}>{formatValue(value, moneyKey(key) ? 'money' : dateKey(key) ? 'date' : 'text')}</dd></div>)}</dl>
    {related.map(([key, values]) => <Related key={key} title={label(key)} values={values} />)}
    {tabKey === 'shipments' && canClaim && ['DISPATCHED', 'DELIVERED'].includes(String(record.status)) ? <div className="form-actions"><button type="button" className="primary" onClick={claim}>File claim for shipment</button></div> : null}
    {(record.allowed_actions?.length ?? 0) > 0 ? <div className="p2-action-grid">{record.allowed_actions!.map((item) => <button key={item} type="button" disabled={busy} className={item === 'REVOKE' || item === 'WITHDRAW' ? 'secondary' : 'primary'} onClick={() => action(item)}>{label(item)}</button>)}</div> : null}
  </div>;
}

function Related({ title, values: relatedValues }: { title: string; values: unknown[] }) {
  const records = relatedValues.filter((value): value is Record<string, unknown> => Boolean(value) && typeof value === 'object');
  if (!records.length) return null;
  const keys = Object.keys(records[0]).filter((key) => !key.endsWith('_id') && !['id', 'company_id', 'plant_id'].includes(key)).slice(0, 6);
  return <section className="p2-related"><h4>{title}</h4><div className="table-wrap"><table className="requisition-table"><thead><tr>{keys.map((key) => <th key={key}>{label(key)}</th>)}</tr></thead><tbody>{records.map((record, index) => <tr key={String(record.id ?? index)}>{keys.map((key) => <td key={key}>{formatValue(record[key], moneyKey(key) ? 'money' : dateKey(key) ? 'date' : 'text')}</td>)}</tr>)}</tbody></table></div></section>;
}

function PortalEditor({ editor, workspace, file, setFile, update, submit, close, busy }: { editor: Editor; workspace: PortalWorkspace | null; file: File | null; setFile: (file: File | null) => void; update: (field: string, value: string | string[]) => void; submit: (event: FormEvent) => void; close: () => void; busy: boolean }) {
  return <form className="p2-command-editor p2-archive-form portal-command" onSubmit={submit}><fieldset disabled={busy}><div className="detail-status"><StatusBadge status="NEW ENTRY" /><span>Checked when saved</span></div><h2>{editor.title}</h2>
    {(editor.kind === 'GRANT' || editor.kind === 'GRANT_UPDATE') ? <GrantFields editor={editor} workspace={workspace} update={update} /> : null}
    {(editor.kind === 'PUBLISH' || editor.kind === 'UPLOAD') ? <DocumentFields editor={editor} workspace={workspace} file={file} setFile={setFile} update={update} /> : null}
    {editor.kind === 'CLAIM' ? <ClaimFields editor={editor} workspace={workspace} update={update} /> : null}
    {editor.kind === 'ACKNOWLEDGE' ? <div className="form-grid"><label className="full-span">Acknowledgement reference<input aria-label="Acknowledgement reference" value={text(editor.draft.reference)} onChange={(event) => update('reference', event.target.value)} required minLength={3} /></label><div className="callout full-span">This records document receipt only. It does not approve an order, claim, invoice, or internal workflow.</div></div> : null}
    {(editor.kind === 'REVOKE' || editor.kind === 'WITHDRAW') ? <div className="form-grid"><label className="full-span">Reason<textarea aria-label={`${editor.title} reason`} rows={4} value={text(editor.draft.reason)} onChange={(event) => update('reason', event.target.value)} required minLength={3} /></label></div> : null}
    <div className="form-actions"><button type="button" className="secondary" onClick={close}>Close</button><button type="submit" className="primary">Save</button></div>
  </fieldset></form>;
}

function GrantFields({ editor, workspace, update }: { editor: Editor; workspace: PortalWorkspace | null; update: (field: string, value: string | string[]) => void }) {
  const allEntitlements = lookupStrings(workspace, 'entitlements');
  const chosen = values(editor.draft.entitlements).map(String);
  function toggle(code: string, checked: boolean) { update('entitlements', checked ? [...chosen, code] : chosen.filter((value) => value !== code)); }
  return <><div className="form-grid">
    {editor.kind === 'GRANT' ? <><label>Portal identity<select aria-label="Portal identity" value={text(editor.draft.user_id)} onChange={(event) => update('user_id', event.target.value)} required>{rows(workspace, 'users').filter((user) => Boolean(user.portal_eligible)).map((user) => <option key={user.id} value={user.id}>{String(user.name)} · {String(user.email)}</option>)}</select></label><label>Customer tenant<select aria-label="Customer tenant" value={text(editor.draft.party_id)} onChange={(event) => update('party_id', event.target.value)} required>{rows(workspace, 'customers').map((party) => <option key={party.id} value={party.id}>{String(party.code)} · {String(party.name)}</option>)}</select></label></> : null}
    <label>Effective from<input aria-label="Access effective from" type="datetime-local" value={text(editor.draft.effective_from)} onChange={(event) => update('effective_from', event.target.value)} /></label>
    <label>Effective to<input aria-label="Access effective to" type="datetime-local" value={text(editor.draft.effective_to)} onChange={(event) => update('effective_to', event.target.value)} /></label>
  </div><fieldset className="portal-entitlements"><legend>Explicit entitlements</legend>{allEntitlements.map((code) => <label key={code}><input type="checkbox" checked={chosen.includes(code)} onChange={(event) => toggle(code, event.target.checked)} />{label(code)}</label>)}</fieldset></>;
}

function DocumentFields({ editor, workspace, file, setFile, update }: { editor: Editor; workspace: PortalWorkspace | null; file: File | null; setFile: (file: File | null) => void; update: (field: string, value: string) => void }) {
  return <div className="form-grid">
    {editor.kind === 'PUBLISH' ? <label>Customer tenant<select aria-label="Document customer tenant" value={text(editor.draft.party_id)} onChange={(event) => update('party_id', event.target.value)} required>{rows(workspace, 'customers').map((party) => <option key={party.id} value={party.id}>{String(party.code)} · {String(party.name)}</option>)}</select></label> : null}
    <label>Document number<input aria-label="Partner document number" value={text(editor.draft.document_number)} onChange={(event) => update('document_number', event.target.value.toUpperCase())} required /></label>
    <label>Document type<select aria-label="Partner document type" value={text(editor.draft.document_type)} onChange={(event) => update('document_type', event.target.value)}>{lookupStrings(workspace, 'document_types').map((type) => <option key={type}>{type}</option>)}</select></label>
    <label className="full-span">Title<input aria-label="Partner document title" value={text(editor.draft.title)} onChange={(event) => update('title', event.target.value)} required minLength={3} /></label>
    <label className="full-span">Description<textarea aria-label="Partner document description" rows={3} value={text(editor.draft.description)} onChange={(event) => update('description', event.target.value)} /></label>
    <label>Business link<select aria-label="Partner document link type" value={text(editor.draft.reference_type)} onChange={(event) => { update('reference_type', event.target.value); update('reference_id', ''); }}><option value="NONE">No link</option><option value="sales_order_id">Sales order</option><option value="shipment_id">Shipment</option><option value="invoice_id">Invoice</option><option value="customer_claim_id">Claim</option></select></label>
    <label>Linked business record<select aria-label="Partner document linked business record" value={text(editor.draft.reference_id)} onChange={(event) => update('reference_id', event.target.value)} disabled={text(editor.draft.reference_type) === 'NONE'}>
      <option value="">{text(editor.draft.reference_type) === 'NONE' ? 'No business link' : 'Select an entitled record'}</option>
      {linkedRecordOptions(workspace, text(editor.draft.reference_type)).map((record) => <option key={record.id} value={record.id}>{record.label}</option>)}
    </select></label>
    <label className="full-span">Private document<input aria-label="Partner private document" type="file" accept=".pdf,.png,.jpg,.jpeg,.csv,.txt" onChange={(event) => setFile(event.currentTarget.files?.[0] ?? null)} /></label>
    {file ? <div className="callout full-span">Selected {file.name} · {formatBytes(file.size)}. SHA-256 and private object metadata will be calculated by the server.</div> : null}
  </div>;
}

function ClaimFields({ editor, workspace, update }: { editor: Editor; workspace: PortalWorkspace | null; update: (field: string, value: string) => void }) {
  const shipments = rows(workspace, 'claimable_shipments');
  const shipment = shipments.find((row) => row.id === text(editor.draft.shipment_id));
  const lines = values(shipment?.lines).filter((value): value is P2Record => Boolean(value) && typeof value === 'object');
  return <div className="form-grid">
    <label>Claim number<input aria-label="Partner claim number" value={text(editor.draft.claim_number)} onChange={(event) => update('claim_number', event.target.value.toUpperCase())} required /></label>
    <label>Shipment<select aria-label="Partner claim shipment" value={text(editor.draft.shipment_id)} onChange={(event) => update('shipment_id', event.target.value)} required>{shipments.map((row) => <option key={row.id} value={row.id}>{String(row.shipment_number)} · {String(row.status)}</option>)}</select></label>
    <label>Shipment line<select aria-label="Partner claim shipment line" value={text(editor.draft.shipment_line_id)} onChange={(event) => update('shipment_line_id', event.target.value)} required>{lines.map((line) => <option key={line.id} value={line.id}>{String(line.item_code)} · {String(line.item_name)} · {String(line.shipped_quantity)} {String(line.uom_code)}</option>)}</select></label>
    <label>Quantity<input aria-label="Partner claim quantity" type="number" min="0.000001" step="0.000001" value={text(editor.draft.quantity)} onChange={(event) => update('quantity', event.target.value)} required /></label>
    <label>Claim type<select aria-label="Partner claim type" value={text(editor.draft.claim_type)} onChange={(event) => update('claim_type', event.target.value)}>{lookupStrings(workspace, 'claim_types').map((type) => <option key={type}>{type}</option>)}</select></label>
    <label>Requested resolution<select aria-label="Partner claim resolution" value={text(editor.draft.requested_resolution)} onChange={(event) => update('requested_resolution', event.target.value)}>{lookupStrings(workspace, 'claim_resolutions').map((type) => <option key={type}>{type}</option>)}</select></label>
    <label className="full-span">Reason<textarea aria-label="Partner claim reason" rows={4} value={text(editor.draft.reason)} onChange={(event) => update('reason', event.target.value)} required minLength={3} /></label>
  </div>;
}

function visibleTabs(workspace: PortalWorkspace): Tab[] {
  if (workspace.mode === 'INTERNAL') return internalTabs;
  const required: Record<string, string> = { orders: 'ORDERS_VIEW', shipments: 'SHIPMENTS_VIEW', invoices: 'INVOICES_VIEW', claims: 'CLAIMS_VIEW', documents: 'DOCUMENTS_VIEW' };
  return partnerTabs.filter((tab) => workspace.entitlements.includes(required[tab.key]));
}

function linkedRecordOptions(workspace: PortalWorkspace | null, referenceType: string): Array<{ id: string; label: string }> {
  if (!workspace || referenceType === 'NONE') return [];
  const key = ({ sales_order_id: 'orders', shipment_id: 'shipments', invoice_id: 'invoices', customer_claim_id: 'claims' } as Record<string, string>)[referenceType];
  if (!key) return [];
  return collection(workspace, key).map((record) => ({
    id: String(record.id),
    label: String(record.order_number ?? record.shipment_number ?? record.invoice_number ?? record.claim_number ?? 'Entitled record'),
  }));
}

function collection(workspace: PortalWorkspace, key: string): P2Record[] {
  const value = workspace[key];
  return Array.isArray(value) ? value as P2Record[] : [];
}

function resourceFor(key: string): string | null {
  return ({ access_grants: 'access-grants', orders: 'orders', shipments: 'shipments', invoices: 'invoices', claims: 'claims', documents: 'documents' } as Record<string, string>)[key] ?? null;
}

function rows(workspace: PortalWorkspace | null, key: string): P2Record[] {
  const value = workspace?.lookups?.[key];
  return Array.isArray(value) ? value as P2Record[] : [];
}

function lookupStrings(workspace: PortalWorkspace | null, key: string): string[] {
  const value = workspace?.lookups?.[key];
  return Array.isArray(value) ? value.map(String) : [];
}

function values(value: unknown): unknown[] {
  return Array.isArray(value) ? value : [];
}

function text(value: unknown): string {
  return typeof value === 'string' ? value : value === null || value === undefined ? '' : String(value);
}

function nullable(value: string): string | null { return value.trim() ? value : null; }
function inputDateTime(value: unknown): string { return value ? String(value).replace(' ', 'T').slice(0, 16) : ''; }
function portalNumber(prefix: string): string { return `${prefix}-${Date.now().toString(36).toUpperCase()}`; }
function label(value: string): string { return value.toLowerCase().replaceAll('_', ' ').replaceAll('-', ' ').replace(/\b\w/g, (letter) => letter.toUpperCase()); }
function moneyKey(key: string): boolean { return /(amount|total|price|cost|credit|outstanding)/.test(key); }
function dateKey(key: string): boolean { return key.includes('date') || key.endsWith('_at') || key.startsWith('effective_'); }
function formatValue(value: unknown, format: 'date' | 'money' | 'text'): string {
  if (value === null || value === undefined || value === '') return '—';
  if (format === 'money') { const amount = Number(value); return Number.isFinite(amount) ? new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 2 }).format(amount) : String(value); }
  if (format === 'date') { const parsed = new Date(String(value).length === 10 ? `${value}T00:00:00Z` : String(value)); return Number.isNaN(parsed.valueOf()) ? String(value) : new Intl.DateTimeFormat('en-IN', { dateStyle: 'medium' }).format(parsed); }
  if (typeof value === 'boolean') return value ? 'Yes' : 'No';
  return String(value);
}
function formatBytes(value: number): string { if (value < 1024) return `${value} B`; if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`; return `${(value / 1024 / 1024).toFixed(1)} MB`; }
function errorMessage(error: unknown, fallback: string): string { return isApiError(error) ? error.message : error instanceof Error ? error.message : fallback; }
function successMessage(kind: EditorKind): string { return ({ GRANT: 'Partner access granted.', GRANT_UPDATE: 'Partner entitlements updated.', REVOKE: 'Partner access revoked with its role assignment.', PUBLISH: 'Outbound document published privately.', UPLOAD: 'Document uploaded privately to Q & T Foods.', CLAIM: 'Customer claim submitted for internal review.', ACKNOWLEDGE: 'Document receipt acknowledged without internal approval effect.', WITHDRAW: 'Unacknowledged outbound document withdrawn.' })[kind]; }
function saveBlob(blob: Blob, filename: string) { const url = URL.createObjectURL(blob); const anchor = document.createElement('a'); anchor.href = url; anchor.download = filename; document.body.append(anchor); anchor.click(); anchor.remove(); URL.revokeObjectURL(url); }
function Empty({ text: value }: { text: string }) { return <div className="empty-state">{value}</div>; }

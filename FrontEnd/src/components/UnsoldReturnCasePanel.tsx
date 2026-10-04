import { useCallback, useEffect, useMemo, useRef, useState, type FormEvent } from 'react';
import { isApiError } from '../api/client';
import {
  downloadUnsoldReturnEvidence,
  dispositionUnsoldReturn,
  getUnsoldReturn,
  getUnsoldReturnLookups,
  linkUnsoldReturnInvoice,
  postUnsoldReturnCreditNote,
  postUnsoldReturnLoss,
  postUnsoldReturnTaxAdjustment,
  receiveUnsoldReturn,
  settleUnsoldReturnFinance,
  uploadUnsoldReturnEvidence,
  type InvoiceLookup,
  type ReturnPositionLookup,
  type UnsoldReturnDetail,
  type UnsoldReturnEvidence,
  type UnsoldReturnEvidenceCategory,
  type UnsoldReturnLine,
} from '../api/unsoldReturns';
import { useErpSession } from '../app/ErpSessionContext';
import { StatusBadge } from './StatusBadge';

type Props = {
  caseId: string | null;
  refreshToken: number;
  onClose: () => void;
  onChanged: () => void | Promise<void>;
};

type ReceiptDraft = Record<string, { quantity: string; positionId: string }>;
type DispositionDraft = Record<string, {
  restock: string;
  repack: string;
  rework: string;
  destroy: string;
  reasonCode: string;
}>;
type FinanceDraft = {
  invoiceId: string;
  documentNumber: string;
  amount: string;
  taxCode: string;
  settlement: 'receivable-adjustment' | 'refund' | 'replacement';
  settlementReference: string;
  notes: string;
};

const receiptStates = ['REQUESTED', 'IN_TRANSIT', 'PARTIALLY_RECEIVED'];

export function UnsoldReturnCasePanel({ caseId, refreshToken, onClose, onChanged }: Props) {
  const session = useErpSession();
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const canReceive = session.allowed_actions.includes('ACTION:RET-UNSOLD:RECEIVE');
  const canDisposition = session.allowed_actions.includes('ACTION:RET-UNSOLD:DISPOSITION');
  const canPostLoss = session.allowed_actions.includes('ACTION:RET-UNSOLD:POST-LOSS');
  const canFinance = session.allowed_actions.includes('ACTION:RET-UNSOLD:FINANCE');
  const canEvidence = session.allowed_actions.includes('ACTION:RET-UNSOLD:EVIDENCE');
  const [detail, setDetail] = useState<UnsoldReturnDetail | null>(null);
  const [positions, setPositions] = useState<Record<string, ReturnPositionLookup[]>>({});
  const [invoices, setInvoices] = useState<InvoiceLookup[]>([]);
  const [receiptDraft, setReceiptDraft] = useState<ReceiptDraft>({});
  const [dispositionDraft, setDispositionDraft] = useState<DispositionDraft>({});
  const [lossCost, setLossCost] = useState('');
  const [lossCurrency, setLossCurrency] = useState('INR');
  const [financeDraft, setFinanceDraft] = useState<FinanceDraft>(emptyFinanceDraft());
  const [evidenceCategory, setEvidenceCategory] = useState<UnsoldReturnEvidenceCategory>('RETURN_CONFIRMATION');
  const [evidenceFile, setEvidenceFile] = useState<File | null>(null);
  const [evidenceNotes, setEvidenceNotes] = useState('');
  const [downloadingEvidenceId, setDownloadingEvidenceId] = useState<string | null>(null);
  const [loading, setLoading] = useState(false);
  const [loadError, setLoadError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [actionSuccess, setActionSuccess] = useState<string | null>(null);
  const [submitting, setSubmitting] = useState<'receive' | 'disposition' | 'loss' | 'finance' | 'evidence' | null>(null);
  const requestSequence = useRef(0);
  const receiptKey = useRef<string | null>(null);
  const dispositionKey = useRef<string | null>(null);
  const lossKey = useRef<string | null>(null);
  const financeKey = useRef<string | null>(null);
  const evidenceKey = useRef<string | null>(null);
  const evidenceInput = useRef<HTMLInputElement>(null);

  const loadCase = useCallback(async () => {
    const sequence = ++requestSequence.current;
    if (!caseId) {
      setDetail(null);
      setPositions({});
      setInvoices([]);
      setLoading(false);
      setLoadError(null);
      return;
    }

    setLoading(true);
    setLoadError(null);
    try {
      const nextDetail = await getUnsoldReturn(caseId);
      const [positionEntries, invoiceLookup] = await Promise.all([
        Promise.all(nextDetail.lines.map(async (line) => {
          if (!line.fg_lot) return [line.id, []] as const;

          try {
            const lookup = await getUnsoldReturnLookups({
              sku_id: line.sku.id,
              lot_id: line.fg_lot.id,
            });
            return [line.id, lookup.return_positions] as const;
          } catch {
            return [line.id, []] as const;
          }
        })),
        getUnsoldReturnLookups({
          party_id: nextDetail.party.id,
          shipment_id: nextDetail.shipment_id,
        }).catch(() => null),
      ]);

      if (sequence !== requestSequence.current) return;

      const nextPositions = Object.fromEntries(positionEntries);
      setDetail(nextDetail);
      setPositions(nextPositions);
      setInvoices(invoiceLookup?.invoices ?? []);
      setReceiptDraft(makeReceiptDraft(nextDetail.lines, nextPositions));
      setDispositionDraft(makeDispositionDraft(nextDetail.lines));
      setLossCost('');
      setLossCurrency('INR');
      setFinanceDraft(makeFinanceDraft(nextDetail, invoiceLookup?.invoices ?? []));
      setEvidenceCategory('RETURN_CONFIRMATION');
      setEvidenceFile(null);
      setEvidenceNotes('');
      if (evidenceInput.current) evidenceInput.current.value = '';
      receiptKey.current = null;
      dispositionKey.current = null;
      lossKey.current = null;
      financeKey.current = null;
      evidenceKey.current = null;
    } catch (caught) {
      if (sequence === requestSequence.current) {
        setDetail(null);
        setLoadError(apiMessage(caught, 'Unable to load this return case.'));
      }
    } finally {
      if (sequence === requestSequence.current) setLoading(false);
    }
  }, [caseId, contextKey, refreshToken]);

  useEffect(() => {
    setActionError(null);
    setActionSuccess(null);
    void loadCase();
  }, [loadCase]);

  const remainingLines = useMemo(
    () => detail?.lines.filter((line) => remainingQuantity(line) > 0) ?? [],
    [detail]
  );
  const missingReceiptRoutes = useMemo(
    () => remainingLines.filter((line) => (positions[line.id] ?? []).length === 0),
    [remainingLines, positions],
  );
  const receiptRoutingReady = remainingLines.length > 0 && missingReceiptRoutes.length === 0;

  const lossUom = useMemo(() => {
    if (!detail) return '';
    const destroyed = detail.lines.filter((line) => Number(line.destroy_quantity) > 0);
    const uoms = [...new Set(destroyed.map((line) => line.uom_code))];
    return uoms.length === 1 ? uoms[0] : '';
  }, [detail]);

  function changeReceipt(lineId: string, patch: Partial<ReceiptDraft[string]>) {
    receiptKey.current = null;
    setActionError(null);
    setActionSuccess(null);
    setReceiptDraft((current) => ({
      ...current,
      [lineId]: { ...current[lineId], ...patch },
    }));
  }

  function changeDisposition(lineId: string, patch: Partial<DispositionDraft[string]>) {
    dispositionKey.current = null;
    setActionError(null);
    setActionSuccess(null);
    setDispositionDraft((current) => ({
      ...current,
      [lineId]: { ...current[lineId], ...patch },
    }));
  }

  function changeFinance(patch: Partial<FinanceDraft>) {
    financeKey.current = null;
    setActionError(null);
    setActionSuccess(null);
    setFinanceDraft((current) => ({ ...current, ...patch }));
  }

  function changeEvidence() {
    evidenceKey.current = null;
    setActionError(null);
    setActionSuccess(null);
  }

  async function finishCommand(message: string) {
    setActionSuccess(message);
    await Promise.all([loadCase(), Promise.resolve(onChanged())]);
  }

  async function submitReceipt(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!detail || submitting || !canReceive || !receiptStates.includes(detail.status)) return;

    const lines = remainingLines.flatMap((line) => {
      const draft = receiptDraft[line.id];
      return draft && Number(draft.quantity) > 0 ? [{ line, draft }] : [];
    });
    if (!lines.length) {
      setActionError('Enter a positive received quantity for at least one line.');
      return;
    }

    for (const { line, draft } of lines) {
      if (!isQuantity(draft.quantity) || Number(draft.quantity) > remainingQuantity(line)) {
        setActionError(`Receipt for ${line.sku.code ?? line.sku.id} must be positive and no more than the remaining quantity.`);
        return;
      }
      if (!draft.positionId) {
        setActionError(`Select a return-quarantine position for ${line.sku.code ?? line.sku.id}.`);
        return;
      }
    }

    setSubmitting('receive');
    setActionError(null);
    setActionSuccess(null);
    receiptKey.current ??= globalThis.crypto.randomUUID();
    try {
      const result = await receiveUnsoldReturn(
        detail.id,
        { lines: lines.map(({ line, draft }) => ({
          line_id: line.id,
          received_quantity: draft.quantity,
          return_position_id: draft.positionId,
        })) },
        detail.record_version,
        receiptKey.current
      );
      receiptKey.current = null;
      await finishCommand(`Physical receipt recorded. Case is now ${displayStatus(result.status)} at version ${result.record_version}.`);
    } catch (caught) {
      setActionError(apiMessage(caught, 'Unable to record the physical receipt.'));
    } finally {
      setSubmitting(null);
    }
  }

  async function submitDisposition(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!detail || submitting || !canDisposition || detail.status !== 'RETURN_QUARANTINE') return;

    for (const line of detail.lines) {
      const draft = dispositionDraft[line.id];
      const values = draft ? [draft.restock, draft.repack, draft.rework, draft.destroy] : [];
      if (values.length !== 4 || values.some((value) => !isNonNegativeQuantity(value))) {
        setActionError(`Every disposition quantity for ${line.sku.code ?? line.sku.id} must be zero or a positive number with at most six decimals.`);
        return;
      }
      const sum = values.reduce((total, value) => total + Number(value), 0);
      if (Math.abs(sum - Number(line.received_quantity)) > 0.0000005) {
        setActionError(`Disposition for ${line.sku.code ?? line.sku.id} must total exactly ${formatQuantity(line.received_quantity)} ${line.uom_code}.`);
        return;
      }
    }

    setSubmitting('disposition');
    setActionError(null);
    setActionSuccess(null);
    dispositionKey.current ??= globalThis.crypto.randomUUID();
    try {
      const result = await dispositionUnsoldReturn(
        detail.id,
        { lines: detail.lines.map((line) => {
          const draft = dispositionDraft[line.id];
          return {
            line_id: line.id,
            restock_quantity: draft.restock,
            repack_quantity: draft.repack,
            rework_quantity: draft.rework,
            destroy_quantity: draft.destroy,
            quality_reason_code: draft.reasonCode || undefined,
          };
        }) },
        detail.record_version,
        dispositionKey.current
      );
      dispositionKey.current = null;
      await finishCommand(`Quality disposition submitted for approval at version ${result.record_version}.`);
    } catch (caught) {
      setActionError(apiMessage(caught, 'Unable to submit the Quality disposition.'));
    } finally {
      setSubmitting(null);
    }
  }

  async function submitLoss(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (
      !detail
      || submitting
      || !canPostLoss
      || detail.status !== 'DISPOSITION_REVIEW'
      || detail.approval?.status !== 'APPROVED'
    ) return;

    if (!lossUom) {
      setActionError('Destroyed lines must share one UOM before Finance can post the loss.');
      return;
    }
    if (lossCost && !/^\d+(?:\.\d{1,4})?$/.test(lossCost)) {
      setActionError('Cost amount must be zero or positive with at most four decimal places.');
      return;
    }
    if (!/^[A-Za-z]{3}$/.test(lossCurrency)) {
      setActionError('Currency must be a three-letter code.');
      return;
    }

    setSubmitting('loss');
    setActionError(null);
    setActionSuccess(null);
    lossKey.current ??= globalThis.crypto.randomUUID();
    try {
      const result = await postUnsoldReturnLoss(
        detail.id,
        {
          uom_code: lossUom,
          cost_amount: lossCost || undefined,
          currency: lossCurrency.toUpperCase(),
        },
        detail.record_version,
        lossKey.current
      );
      lossKey.current = null;
      await finishCommand(`Finance posted ${formatQuantity(result.loss_quantity ?? '0')} ${lossUom} as an approved loss.`);
    } catch (caught) {
      setActionError(apiMessage(caught, 'Unable to post the approved loss.'));
    } finally {
      setSubmitting(null);
    }
  }

  async function submitFinance(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!detail || submitting || !canFinance || detail.finance.stage === 'NOT_READY' || detail.finance.stage === 'RESOLVED') return;

    const stage = detail.finance.stage;
    const invoice = detail.invoice ?? invoices.find((candidate) => candidate.id === financeDraft.invoiceId) ?? null;
    if (stage === 'NOT_STARTED' && !financeDraft.invoiceId) {
      setActionError('Select the source invoice for this customer and shipment.');
      return;
    }
    if ((stage === 'INVOICE_LINKED' || stage === 'CREDIT_NOTE_POSTED') && !invoice) {
      setActionError('The linked source invoice is unavailable. Refresh the case before continuing.');
      return;
    }
    if ((stage === 'INVOICE_LINKED' || stage === 'CREDIT_NOTE_POSTED') && !financeDraft.documentNumber.trim()) {
      setActionError('Enter the finance document reference.');
      return;
    }
    if (stage === 'INVOICE_LINKED' && (!isMoney(financeDraft.amount, false) || Number(financeDraft.amount) > Number(invoice!.net_amount))) {
      setActionError(`Credit-note net amount must be positive and no more than ${invoice!.currency} ${formatMoney(invoice!.net_amount)}.`);
      return;
    }
    if (stage === 'CREDIT_NOTE_POSTED' && (!isMoney(financeDraft.amount, true) || Number(financeDraft.amount) > Number(invoice!.tax_amount))) {
      setActionError(`Tax adjustment must be zero or positive and no more than ${invoice!.currency} ${formatMoney(invoice!.tax_amount)}.`);
      return;
    }
    if (stage === 'CREDIT_NOTE_POSTED' && !financeDraft.taxCode.trim()) {
      setActionError('Enter the tax code reviewed for this adjustment.');
      return;
    }
    if (stage === 'TAX_REVIEWED' && !financeDraft.settlementReference.trim()) {
      setActionError('Enter the receivable, refund, or replacement reference.');
      return;
    }

    setSubmitting('finance');
    setActionError(null);
    setActionSuccess(null);
    financeKey.current ??= globalThis.crypto.randomUUID();
    try {
      let message: string;
      if (stage === 'NOT_STARTED') {
        const result = await linkUnsoldReturnInvoice(
          detail.id,
          financeDraft.invoiceId,
          financeDraft.notes.trim() || undefined,
          detail.record_version,
          financeKey.current
        );
        message = `Source invoice ${result.reference_number} confirmed.`;
      } else if (stage === 'INVOICE_LINKED') {
        const result = await postUnsoldReturnCreditNote(
          detail.id,
          {
            document_number: financeDraft.documentNumber.trim(),
            amount: financeDraft.amount,
            currency: invoice!.currency,
            notes: financeDraft.notes.trim() || undefined,
          },
          detail.record_version,
          financeKey.current
        );
        message = `Credit note ${result.reference_number} posted for ${result.currency} ${formatMoney(result.amount ?? '0')}.`;
      } else if (stage === 'CREDIT_NOTE_POSTED') {
        const result = await postUnsoldReturnTaxAdjustment(
          detail.id,
          {
            document_number: financeDraft.documentNumber.trim(),
            amount: financeDraft.amount,
            currency: invoice!.currency,
            tax_code: financeDraft.taxCode.trim().toUpperCase(),
            notes: financeDraft.notes.trim() || undefined,
          },
          detail.record_version,
          financeKey.current
        );
        message = `Tax treatment ${result.reference_number} recorded.`;
      } else {
        const result = await settleUnsoldReturnFinance(
          detail.id,
          financeDraft.settlement,
          financeDraft.settlementReference.trim(),
          financeDraft.notes.trim() || undefined,
          detail.record_version,
          financeKey.current
        );
        message = `${displayStatus(result.action_type)} completed for ${result.currency} ${formatMoney(result.amount ?? '0')}.`;
      }
      financeKey.current = null;
      await finishCommand(message);
    } catch (caught) {
      setActionError(apiMessage(caught, 'Unable to record the finance action.'));
    } finally {
      setSubmitting(null);
    }
  }

  async function submitEvidence(event: FormEvent<HTMLFormElement>) {
    event.preventDefault();
    if (!detail || submitting || !canEvidence) return;

    if (!evidenceFile) {
      setActionError('Select a PDF, image, or plain-text evidence file.');
      return;
    }

    setSubmitting('evidence');
    setActionError(null);
    setActionSuccess(null);
    evidenceKey.current ??= globalThis.crypto.randomUUID();
    try {
      const result = await uploadUnsoldReturnEvidence(
        detail.id,
        {
          category: evidenceCategory,
          file: evidenceFile,
          notes: evidenceNotes.trim() || undefined,
        },
        detail.record_version,
        evidenceKey.current
      );
      evidenceKey.current = null;
      await finishCommand(
        `${result.original_name} attached and retained through ${formatDate(result.retention_until)}.`
      );
    } catch (caught) {
      setActionError(apiMessage(caught, 'Unable to upload the evidence file.'));
    } finally {
      setSubmitting(null);
    }
  }

  async function downloadEvidence(evidence: UnsoldReturnEvidence) {
    if (!detail || downloadingEvidenceId || !canEvidence) return;

    setDownloadingEvidenceId(evidence.id);
    setActionError(null);
    try {
      const blob = await downloadUnsoldReturnEvidence(detail.id, evidence.id);
      const url = URL.createObjectURL(blob);
      const anchor = document.createElement('a');
      anchor.href = url;
      anchor.download = evidence.original_name;
      document.body.append(anchor);
      anchor.click();
      anchor.remove();
      globalThis.setTimeout(() => URL.revokeObjectURL(url), 1000);
    } catch (caught) {
      setActionError(apiMessage(caught, 'Unable to download the evidence file.'));
    } finally {
      setDownloadingEvidenceId(null);
    }
  }

  if (!caseId) {
    return (
      <section className="panel case-workspace" style={{ marginTop: 16 }}>
        <div className="panel-head"><h3>Case workspace</h3><span>Role and state controlled</span></div>
        <div className="empty-state">Open a recent return case to inspect its history and perform any action assigned to your role.</div>
      </section>
    );
  }

  if (loading && !detail) {
    return (
      <section className="panel case-workspace" style={{ marginTop: 16 }}>
        <div className="panel-head"><h3>Loading return case</h3><span>{shortId(caseId)}</span></div>
        <div className="empty-state">Resolving current state, lines, approval, and quarantine routes...</div>
      </section>
    );
  }

  if (loadError || !detail) {
    return (
      <section className="panel case-workspace" style={{ marginTop: 16 }}>
        <div className="panel-head"><h3>Case workspace</h3><button className="secondary compact-button" type="button" onClick={onClose}>Close</button></div>
        <div className="form-error panel-message" role="alert"><span>{loadError ?? 'Return case is unavailable.'}</span><button type="button" onClick={() => void loadCase()}>Retry</button></div>
      </section>
    );
  }

  const hasAssignedCommand = canReceive || canDisposition || canPostLoss || canFinance;

  return (
    <section className="panel case-workspace" style={{ marginTop: 16 }}>
      <div className="panel-head">
        <div><h3>{shortId(detail.id)} · {detail.party.name ?? detail.party.code ?? 'Customer'}</h3><span>Version {detail.record_version} · {detail.reason_code}</span></div>
        <div className="case-head-actions"><StatusBadge status={displayStatus(detail.status)} /><button className="secondary compact-button" type="button" onClick={onClose}>Close</button></div>
      </div>

      <div className="panel-body case-workspace-body">
        {actionError && <div className="form-error" role="alert"><span>{actionError}</span><button type="button" onClick={() => void loadCase()}>Refresh case</button></div>}
        {actionSuccess && <div className="form-success" role="status"><span></span>{actionSuccess}</div>}
        {loading && <div className="case-refreshing">Refreshing current record...</div>}

        <div className="case-facts">
          <div><span>Requested by</span><b>{detail.maker.name ?? detail.maker.id}</b></div>
          <div><span>Expected return</span><b>{detail.expected_return_date ?? 'Not set'}</b></div>
          <div><span>Approval</span><b>{detail.approval?.status ? displayStatus(detail.approval.status) : 'Not requested'}</b></div>
          <div><span>Last updated</span><b>{formatDateTime(detail.updated_at)}</b></div>
        </div>

        <div className="table-wrap case-lines">
          <table>
            <thead><tr><th>SKU / lot</th><th>Requested</th><th>Received</th><th>Restock</th><th>Repack</th><th>Rework</th><th>Destroy</th><th>Quarantine</th></tr></thead>
            <tbody>{detail.lines.map((line) => (
              <tr key={line.id}>
                <td><b>{line.sku.code ?? line.sku.id}</b><small>{line.fg_lot?.code ?? 'No lot'}</small></td>
                <td>{formatQuantity(line.requested_quantity)} {line.uom_code}</td>
                <td>{formatQuantity(line.received_quantity)} {line.uom_code}</td>
                <td>{formatQuantity(line.restock_quantity)}</td>
                <td>{formatQuantity(line.repack_quantity)}</td>
                <td>{formatQuantity(line.rework_quantity)}</td>
                <td>{formatQuantity(line.destroy_quantity)}</td>
                <td>{line.return_position ? <StatusBadge status={displayStatus(line.return_position.quality_status ?? 'RECORDED')} /> : '-'}</td>
              </tr>
            ))}</tbody>
          </table>
        </div>

        {!hasAssignedCommand && <div className="action-wait">This role has read access to the case but no RET-UNSOLD transaction action.</div>}

        <div className="case-actions">
          {canEvidence && (
            <form className="action-card wide-action evidence-action-card" onSubmit={submitEvidence} noValidate>
              <div className="action-card-head"><div><span>CASE EVIDENCE</span><h4>Attach a retained document or image</h4></div><StatusBadge status="AVAILABLE" /></div>
              <p>Files are stored privately, integrity-hashed, linked to this case version and audit event, and retained for seven years.</p>
              <div className="evidence-fields">
                <label>Evidence category
                  <select value={evidenceCategory} onChange={(event) => { changeEvidence(); setEvidenceCategory(event.target.value as UnsoldReturnEvidenceCategory); }}>
                    <option value="RETURN_CONFIRMATION">Return confirmation</option>
                    <option value="RECEIPT_PHOTO">Receipt photo</option>
                    <option value="QUALITY_REPORT">Quality report</option>
                    <option value="FINANCE_DOCUMENT">Finance document</option>
                    <option value="OTHER">Other evidence</option>
                  </select>
                </label>
                <label>File
                  <input ref={evidenceInput} type="file" accept=".pdf,.jpg,.jpeg,.png,.webp,.txt,application/pdf,image/jpeg,image/png,image/webp,text/plain" onChange={(event) => { changeEvidence(); setEvidenceFile(event.target.files?.[0] ?? null); }} />
                </label>
                <label>Evidence note
                  <textarea rows={2} maxLength={2000} value={evidenceNotes} onChange={(event) => { changeEvidence(); setEvidenceNotes(event.target.value); }} placeholder="Optional context for reviewers and auditors" />
                </label>
              </div>
              <div className="evidence-upload-footer"><small>PDF, JPEG, PNG, WebP, or text; maximum 10 MB.</small><button className="primary" type="submit" disabled={submitting !== null}>{submitting === 'evidence' ? 'Uploading...' : 'Attach evidence'}</button></div>
            </form>
          )}

          {canReceive && (receiptStates.includes(detail.status) ? (
            <form className="action-card" onSubmit={submitReceipt} noValidate>
              <div className="action-card-head"><div><span>STORES ACTION</span><h4>Record physical receipt</h4></div><StatusBadge status="AVAILABLE" /></div>
              <p>Only positive quantities are posted. Set a line to zero to leave it open for a later partial receipt.</p>
              {remainingLines.map((line) => {
                const routes = positions[line.id] ?? [];
                const draft = receiptDraft[line.id] ?? { quantity: '', positionId: '' };
                return (
                  <div className="action-line" key={line.id}>
                    <b>{line.sku.code ?? line.sku.id}<small>{formatQuantityValue(remainingQuantity(line))} {line.uom_code} remaining</small></b>
                    <label>Received<input type="number" min="0" max={remainingQuantity(line)} step="0.000001" value={draft.quantity} onChange={(event) => changeReceipt(line.id, { quantity: event.target.value })} /></label>
                    <label>Quarantine position<select aria-describedby={routes.length ? undefined : `route-help-${line.id}`} value={draft.positionId} onChange={(event) => changeReceipt(line.id, { positionId: event.target.value })} disabled={routes.length === 0}><option value="">{routes.length ? 'Select destination' : 'No eligible destination'}</option>{routes.map((position) => <option key={position.id} value={position.id}>{position.location.code} · {position.location.name}</option>)}</select></label>
                    {routes.length === 0 && <div id={`route-help-${line.id}`} className="lookup-route route-warning" role="alert"><b>No return quarantine location is configured for this lot/owner.</b><span>Ask Operations to configure an active RETURN_QUARANTINE route for this plant, SKU, lot and inventory owner, then retry.</span><button type="button" className="secondary" disabled={loading} onClick={() => void loadCase()}>Retry routing lookup</button></div>}
                  </div>
                );
              })}
              <div className="form-actions"><button className="primary" type="submit" disabled={submitting !== null || !receiptRoutingReady} title={!receiptRoutingReady ? 'Configure an eligible return quarantine destination before posting.' : undefined}>{submitting === 'receive' ? 'Posting receipt...' : 'Post quarantine receipt'}</button></div>
            </form>
          ) : <ActionWait role="STORES ACTION" title="Physical receipt" message={`Available only while a return is requested, in transit, or partially received. Current state: ${displayStatus(detail.status)}.`} />)}

          {canDisposition && (detail.status === 'RETURN_QUARANTINE' ? (
            <form className="action-card wide-action" onSubmit={submitDisposition} noValidate>
              <div className="action-card-head"><div><span>QUALITY ACTION</span><h4>Set disposition quantities</h4></div><StatusBadge status="AVAILABLE" /></div>
              <p>For every line, restock + repack + rework + destroy must exactly equal the physically received quantity.</p>
              <div className="disposition-grid disposition-head"><span>SKU</span><span>Restock</span><span>Repack</span><span>Rework</span><span>Destroy</span><span>Reason</span></div>
              {detail.lines.map((line) => {
                const draft = dispositionDraft[line.id];
                return (
                  <div className="disposition-grid" key={line.id}>
                    <b>{line.sku.code ?? line.sku.id}<small>{formatQuantity(line.received_quantity)} {line.uom_code}</small></b>
                    {(['restock', 'repack', 'rework', 'destroy'] as const).map((field) => <input key={field} aria-label={`${field} quantity for ${line.sku.code ?? line.sku.id}`} type="number" min="0" step="0.000001" value={draft?.[field] ?? '0'} onChange={(event) => changeDisposition(line.id, { [field]: event.target.value })} />)}
                    <select aria-label={`Quality reason for ${line.sku.code ?? line.sku.id}`} value={draft?.reasonCode ?? ''} onChange={(event) => changeDisposition(line.id, { reasonCode: event.target.value })}><option value="">No reason</option><option value="SHORT_SHELF_LIFE">Short shelf life</option><option value="DAMAGED_RETURN">Damaged return</option><option value="SALEABLE_RETURN">Saleable return</option></select>
                  </div>
                );
              })}
              <div className="form-actions"><button className="primary" type="submit" disabled={submitting !== null}>{submitting === 'disposition' ? 'Submitting...' : 'Submit for loss approval'}</button></div>
            </form>
          ) : <ActionWait role="QUALITY ACTION" title="Disposition" message={`Available only after every line is fully received into return quarantine. Current state: ${displayStatus(detail.status)}.`} />)}

          {canPostLoss && (detail.status === 'DISPOSITION_REVIEW' && detail.approval?.status === 'APPROVED' ? (
            <form className="action-card" onSubmit={submitLoss} noValidate>
              <div className="action-card-head"><div><span>FINANCE ACTION</span><h4>Post approved destroyed quantity</h4></div><StatusBadge status="AVAILABLE" /></div>
              <p>The posted loss quantity comes from the approved Quality disposition and cannot be edited here.</p>
              <div className="loss-summary"><span>Approved destroy quantity</span><b>{formatQuantity(totalDestroyed(detail.lines))} {lossUom || 'mixed UOM'}</b></div>
              <div className="inline-fields"><label>Cost amount<input type="number" min="0" step="0.0001" value={lossCost} onChange={(event) => { lossKey.current = null; setLossCost(event.target.value); }} placeholder="Optional" /></label><label>Currency<input value={lossCurrency} maxLength={3} onChange={(event) => { lossKey.current = null; setLossCurrency(event.target.value.toUpperCase()); }} /></label></div>
              <div className="form-actions"><button className="primary" type="submit" disabled={submitting !== null}>{submitting === 'loss' ? 'Posting loss...' : 'Post approved loss'}</button></div>
            </form>
          ) : <ActionWait role="FINANCE ACTION" title="Loss posting" message={financeWaitMessage(detail)} />)}

          {canFinance && (detail.finance.stage === 'NOT_READY' ? (
            <ActionWait role="FINANCE RESOLUTION" title="Invoice and value treatment" message={`Available after the approved inventory loss is posted. Current state: ${displayStatus(detail.status)}.`} />
          ) : detail.finance.stage === 'RESOLVED' ? (
            <div className="action-card finance-action-card">
              <div className="action-card-head"><div><span>FINANCE RESOLUTION</span><h4>Value treatment completed</h4></div><StatusBadge status="RESOLVED" /></div>
              <p>The source invoice, credit note, tax treatment, and one final settlement path are recorded separately.</p>
              <div className="finance-summary">
                <span>Invoice<b>{detail.invoice?.number ?? 'Recorded'}</b></span>
                <span>Credit total<b>{detail.invoice?.currency} {formatMoney(detail.finance.credit_total ?? '0')}</b></span>
                <span>Settlement<b>{displayStatus(detail.finance.settlement_type ?? 'Resolved')}</b></span>
                <span>Open balance<b>{detail.invoice?.currency} {formatMoney(detail.invoice?.outstanding_amount ?? '0')}</b></span>
              </div>
            </div>
          ) : (
            <form className="action-card wide-action finance-action-card" onSubmit={submitFinance} noValidate>
              <div className="action-card-head">
                <div><span>FINANCE RESOLUTION</span><h4>{financeStepTitle(detail.finance.stage)}</h4></div>
                <StatusBadge status="AVAILABLE" />
              </div>
              <p>{financeStepHelp(detail.finance.stage)}</p>

              {detail.finance.stage === 'NOT_STARTED' && (
                <label>Source invoice
                  <select value={financeDraft.invoiceId} onChange={(event) => changeFinance({ invoiceId: event.target.value })}>
                    <option value="">Select an eligible invoice</option>
                    {invoices.map((invoice) => <option key={invoice.id} value={invoice.id}>{invoice.number} - {displayStatus(invoice.status)} - {invoice.currency} {formatMoney(invoice.outstanding_amount)} open</option>)}
                  </select>
                </label>
              )}

              {(detail.finance.stage === 'INVOICE_LINKED' || detail.finance.stage === 'CREDIT_NOTE_POSTED') && (
                <div className="inline-fields">
                  <label>{detail.finance.stage === 'INVOICE_LINKED' ? 'Credit-note number' : 'Tax document number'}
                    <input value={financeDraft.documentNumber} maxLength={80} onChange={(event) => changeFinance({ documentNumber: event.target.value })} />
                  </label>
                  <label>{detail.finance.stage === 'INVOICE_LINKED' ? 'Net credit amount' : 'Tax adjustment amount'}
                    <input type="number" min={detail.finance.stage === 'INVOICE_LINKED' ? '0.0001' : '0'} step="0.0001" value={financeDraft.amount} onChange={(event) => changeFinance({ amount: event.target.value })} />
                  </label>
                  {detail.finance.stage === 'CREDIT_NOTE_POSTED' && <label>Tax code<input value={financeDraft.taxCode} maxLength={32} onChange={(event) => changeFinance({ taxCode: event.target.value.toUpperCase() })} /></label>}
                </div>
              )}

              {detail.finance.stage === 'TAX_REVIEWED' && (
                <>
                  <div className="finance-summary">
                    <span>Approved credit + tax<b>{detail.invoice?.currency} {formatMoney(detail.finance.credit_total ?? '0')}</b></span>
                    <span>Invoice outstanding<b>{detail.invoice?.currency} {formatMoney(detail.invoice?.outstanding_amount ?? '0')}</b></span>
                  </div>
                  <div className="inline-fields">
                    <label>Settlement path
                      <select value={financeDraft.settlement} onChange={(event) => changeFinance({ settlement: event.target.value as FinanceDraft['settlement'] })}>
                        {Number(detail.invoice?.outstanding_amount ?? 0) > 0 ? (
                          <option value="receivable-adjustment">Receivable adjustment</option>
                        ) : (
                          <><option value="refund">Customer refund</option><option value="replacement">Replacement authorisation</option></>
                        )}
                      </select>
                    </label>
                    <label>Settlement reference<input value={financeDraft.settlementReference} maxLength={80} onChange={(event) => changeFinance({ settlementReference: event.target.value })} /></label>
                  </div>
                </>
              )}

              <label>Finance note<textarea rows={2} maxLength={2000} value={financeDraft.notes} onChange={(event) => changeFinance({ notes: event.target.value })} placeholder="Optional audit note" /></label>
              <div className="form-actions"><button className="primary" type="submit" disabled={submitting !== null}>{submitting === 'finance' ? 'Recording...' : financeStepButton(detail.finance.stage)}</button></div>
            </form>
          ))}
        </div>

        <div className="evidence-history">
          <h4>Evidence history</h4>
          {!detail.evidence.length ? (
            <div className="empty-state">No retained evidence has been attached to this return yet.</div>
          ) : (
            <div className="table-wrap">
              <table>
                <thead><tr><th>Evidence</th><th>Integrity</th><th>Retention</th><th>Uploaded by / time</th><th>File</th></tr></thead>
                <tbody>{detail.evidence.map((evidence) => (
                  <tr key={evidence.id}>
                    <td><b>{evidence.original_name}</b><small>{displayStatus(evidence.category)} · Case v{evidence.case_record_version}{evidence.notes ? ` · ${evidence.notes}` : ''}</small></td>
                    <td><b>{formatBytes(evidence.size_bytes)}</b><small>SHA-256 {evidence.sha256.slice(0, 12)}… · Audit {evidence.audit_event_id.slice(0, 8)}</small></td>
                    <td><b>{formatDate(evidence.retention_until)}</b><small>{evidence.retention_policy}{evidence.legal_hold ? ' · Legal hold' : ''}</small></td>
                    <td><b>{evidence.uploader.name ?? evidence.uploader.id}</b><small>{formatDateTime(evidence.uploaded_at)}</small></td>
                    <td><button className="secondary" type="button" disabled={!canEvidence || downloadingEvidenceId !== null} onClick={() => void downloadEvidence(evidence)}>{downloadingEvidenceId === evidence.id ? 'Downloading...' : 'Download'}</button></td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          )}
        </div>

        <div className="stock-movement-history">
          <h4>Stock movement history</h4>
          {!detail.stock_movements.length ? (
            <div className="empty-state">No inventory movement has been posted for this return yet.</div>
          ) : (
            <div className="table-wrap case-movements">
              <table>
                <thead><tr><th>Movement</th><th>Quantity</th><th>From</th><th>To</th><th>Posted by / time</th></tr></thead>
                <tbody>{detail.stock_movements.map((movement) => (
                  <tr key={movement.id}>
                    <td><b>{displayStatus(movement.movement_type)}</b><small>{movement.reason_code ? displayStatus(movement.reason_code) : `Case v${movement.source_version ?? '-'}`}</small></td>
                    <td>{formatQuantity(movement.quantity)} {movement.uom_code}</td>
                    <td>{movementPosition(movement.from_position, 'Customer / external')}</td>
                    <td>{movementPosition(movement.to_position, 'Consumed / destroyed')}</td>
                    <td><b>{movement.actor.name ?? movement.actor.id}</b><small>{formatDateTime(movement.posted_at)}</small></td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          )}
        </div>

        <div className="finance-history">
          <h4>Finance action history</h4>
          {!detail.finance.actions.length ? (
            <div className="empty-state">No invoice or value-treatment action has been recorded for this return yet.</div>
          ) : (
            <div className="table-wrap">
              <table>
                <thead><tr><th>Action</th><th>Reference</th><th>Amount</th><th>Balance</th><th>Recorded by / time</th></tr></thead>
                <tbody>{detail.finance.actions.map((action) => (
                  <tr key={action.id}>
                    <td><b>{displayStatus(action.action_type)}</b><small>Case v{action.case_record_version}{action.tax_code ? ` · Tax code ${action.tax_code}` : action.notes ? ` · ${action.notes}` : ''}</small></td>
                    <td>{action.reference_number}</td>
                    <td>{action.amount === null ? '-' : `${action.currency} ${formatMoney(action.amount)}`}</td>
                    <td>{action.balance_before === null ? '-' : `${action.currency} ${formatMoney(action.balance_before)} → ${formatMoney(action.balance_after ?? action.balance_before)}`}</td>
                    <td><b>{action.actor.name ?? action.actor.id}</b><small>{formatDateTime(action.posted_at)}</small></td>
                  </tr>
                ))}</tbody>
              </table>
            </div>
          )}
        </div>

        <div className="status-history">
          <h4>Status history</h4>
          <div>{detail.status_history.map((entry) => <span key={entry.id}><b>v{entry.record_version} · {displayStatus(entry.to_status)}</b><small>{entry.actor.name ?? entry.actor.id} · {formatDateTime(entry.changed_at)}</small></span>)}</div>
        </div>
      </div>
    </section>
  );
}

function ActionWait({ role, title, message }: { role: string; title: string; message: string }) {
  return <div className="action-card action-wait"><span>{role}</span><h4>{title}</h4><p>{message}</p></div>;
}

function makeReceiptDraft(
  lines: UnsoldReturnLine[],
  positions: Record<string, ReturnPositionLookup[]>
): ReceiptDraft {
  return Object.fromEntries(lines.map((line) => {
    const remaining = remainingQuantity(line);
    const existingPosition = line.return_position?.id;
    return [line.id, {
      quantity: remaining > 0 ? formatQuantityValue(remaining) : '0',
      positionId: existingPosition ?? positions[line.id]?.[0]?.id ?? '',
    }];
  }));
}

function makeDispositionDraft(lines: UnsoldReturnLine[]): DispositionDraft {
  return Object.fromEntries(lines.map((line) => [line.id, {
    restock: normalizeDecimal(line.restock_quantity),
    repack: normalizeDecimal(line.repack_quantity),
    rework: normalizeDecimal(line.rework_quantity),
    destroy: normalizeDecimal(line.destroy_quantity),
    reasonCode: line.quality_reason_code ?? '',
  }]));
}

function emptyFinanceDraft(): FinanceDraft {
  return {
    invoiceId: '',
    documentNumber: '',
    amount: '',
    taxCode: 'GST18',
    settlement: 'receivable-adjustment',
    settlementReference: '',
    notes: '',
  };
}

function makeFinanceDraft(detail: UnsoldReturnDetail, invoices: InvoiceLookup[]): FinanceDraft {
  const invoice = detail.invoice ?? invoices.find((candidate) => candidate.id === detail.invoice_id) ?? invoices[0];

  return {
    ...emptyFinanceDraft(),
    invoiceId: detail.invoice_id ?? invoice?.id ?? '',
    amount: detail.finance.stage === 'CREDIT_NOTE_POSTED' ? '0' : '',
    settlement: Number(invoice?.outstanding_amount ?? 0) > 0 ? 'receivable-adjustment' : 'refund',
  };
}

function financeStepTitle(stage: UnsoldReturnDetail['finance']['stage']): string {
  if (stage === 'NOT_STARTED') return 'Confirm source invoice';
  if (stage === 'INVOICE_LINKED') return 'Record net credit note';
  if (stage === 'CREDIT_NOTE_POSTED') return 'Record tax treatment';
  return 'Choose final settlement';
}

function financeStepHelp(stage: UnsoldReturnDetail['finance']['stage']): string {
  if (stage === 'NOT_STARTED') return 'Confirm the posted invoice that belongs to this customer, shipment, company, and plant.';
  if (stage === 'INVOICE_LINKED') return 'Record only the approved net credit. Tax is reviewed in the next independent action.';
  if (stage === 'CREDIT_NOTE_POSTED') return 'Record the tax adjustment, including a zero-value review when no tax reversal is due.';
  return 'An open invoice is adjusted against receivables. A fully paid invoice can be refunded or replaced.';
}

function financeStepButton(stage: UnsoldReturnDetail['finance']['stage']): string {
  if (stage === 'NOT_STARTED') return 'Confirm invoice';
  if (stage === 'INVOICE_LINKED') return 'Post net credit';
  if (stage === 'CREDIT_NOTE_POSTED') return 'Record tax review';
  return 'Complete finance resolution';
}

function financeWaitMessage(detail: UnsoldReturnDetail): string {
  if (detail.status !== 'DISPOSITION_REVIEW') {
    return `Available only after Quality submits a disposition for review. Current state: ${displayStatus(detail.status)}.`;
  }
  if (!detail.approval) return 'The loss approval request has not been created.';
  if (detail.approval.status === 'PENDING') return 'Waiting for a different authorised reviewer to approve the loss disposition.';
  if (detail.approval.status === 'REJECTED') return 'The loss disposition was rejected and cannot be posted.';
  return `Approval is ${displayStatus(detail.approval.status)}.`;
}

function remainingQuantity(line: UnsoldReturnLine): number {
  return Math.max(0, Number(line.requested_quantity) - Number(line.received_quantity));
}

function totalDestroyed(lines: UnsoldReturnLine[]): string {
  return String(lines.reduce((total, line) => total + Number(line.destroy_quantity), 0));
}

function isQuantity(value: string): boolean {
  return /^\d+(?:\.\d{1,6})?$/.test(value.trim()) && Number(value) > 0;
}

function isNonNegativeQuantity(value: string): boolean {
  return /^\d+(?:\.\d{1,6})?$/.test(value.trim()) && Number(value) >= 0;
}

function isMoney(value: string, allowZero: boolean): boolean {
  return /^\d+(?:\.\d{1,4})?$/.test(value.trim()) && (allowZero ? Number(value) >= 0 : Number(value) > 0);
}

function normalizeDecimal(value: string): string {
  const number = Number(value);
  return Number.isFinite(number) ? formatQuantityValue(number) : '0';
}

function apiMessage(caught: unknown, fallback: string): string {
  if (!isApiError(caught)) return fallback;
  const firstFieldMessage = caught.fields && Object.values(caught.fields).flat()[0];
  return firstFieldMessage ?? caught.message;
}

function displayStatus(status: string): string {
  return status.replaceAll('_', ' ');
}

function formatQuantity(quantity: string): string {
  const value = Number(quantity);
  return Number.isFinite(value) ? value.toLocaleString(undefined, { maximumFractionDigits: 6 }) : quantity;
}

function formatQuantityValue(quantity: number): string {
  return quantity.toFixed(6).replace(/\.?0+$/, '');
}

function formatMoney(amount: string): string {
  const value = Number(amount);
  return Number.isFinite(value)
    ? value.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 4 })
    : amount;
}

function formatDateTime(value: string): string {
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString();
}

function formatDate(value: string): string {
  const date = new Date(`${value}T00:00:00`);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleDateString();
}

function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`;
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`;
}

function movementPosition(
  position: UnsoldReturnDetail['stock_movements'][number]['from_position'],
  fallback: string
) {
  if (!position) return fallback;
  const location = position.location?.code ?? position.location?.name ?? position.id.slice(0, 8);
  return <><b>{location}</b><small>{displayStatus(position.quality_status ?? 'Recorded')}</small></>;
}

function shortId(id: string): string {
  return `RET-${id.slice(0, 8).toUpperCase()}`;
}

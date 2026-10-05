import { useCallback, useEffect, useRef, useState } from 'react';
import { isApiError } from '../api/client';
import {
  approveUnsoldReturnApproval,
  getUnsoldReturnApproval,
  listUnsoldReturnApprovals,
  rejectUnsoldReturnApproval,
  type UnsoldReturnApprovalDecisionResult,
  type UnsoldReturnApprovalDetail,
  type UnsoldReturnApprovalList,
} from '../api/unsoldReturns';
import { useErpSession } from '../app/ErpSessionContext';
import { StatusBadge } from './StatusBadge';
import { formatZonedDateTime } from '../utils/dateTime';

type Props = {
  refreshToken: number;
  onOpenCase: (caseId: string) => void;
  onDecision: (result: UnsoldReturnApprovalDecisionResult) => void | Promise<void>;
};

type ApprovalFilter = 'PENDING' | 'APPROVED' | 'REJECTED';

export function UnsoldReturnApprovalInbox({ refreshToken, onOpenCase, onDecision }: Props) {
  const session = useErpSession();
  const canApprove = session.allowed_actions.some((permission) =>
    permission.startsWith('ACTION:RET-UNSOLD:APPROVE')
  );
  const contextKey = `${session.selected_context?.company_id}:${session.selected_context?.plant_id}`;
  const [filter, setFilter] = useState<ApprovalFilter>('PENDING');
  const [list, setList] = useState<UnsoldReturnApprovalList | null>(null);
  const [selectedId, setSelectedId] = useState<string | null>(null);
  const [detail, setDetail] = useState<UnsoldReturnApprovalDetail | null>(null);
  const [listLoading, setListLoading] = useState(false);
  const [detailLoading, setDetailLoading] = useState(false);
  const [listError, setListError] = useState<string | null>(null);
  const [detailError, setDetailError] = useState<string | null>(null);
  const [actionError, setActionError] = useState<string | null>(null);
  const [actionSuccess, setActionSuccess] = useState<string | null>(null);
  const [reason, setReason] = useState('');
  const [submitting, setSubmitting] = useState<'approve' | 'reject' | null>(null);
  const approveKey = useRef<string | null>(null);
  const rejectKey = useRef<string | null>(null);
  const listSequence = useRef(0);
  const detailSequence = useRef(0);

  const refreshInbox = useCallback(async () => {
    if (!canApprove) return;
    const sequence = ++listSequence.current;
    setListLoading(true);
    setListError(null);
    try {
      const next = await listUnsoldReturnApprovals({ status: filter, per_page: 12 });
      if (sequence !== listSequence.current) return;
      setList(next);
      setSelectedId((current) => current ?? next.data[0]?.id ?? null);
    } catch (caught) {
      if (sequence === listSequence.current) {
        setListError(apiMessage(caught, 'Unable to load the approval inbox.'));
      }
    } finally {
      if (sequence === listSequence.current) setListLoading(false);
    }
  }, [canApprove, contextKey, filter]);

  const loadDetail = useCallback(async () => {
    const sequence = ++detailSequence.current;
    if (!selectedId) {
      setDetail(null);
      setDetailError(null);
      return;
    }

    setDetailLoading(true);
    setDetailError(null);
    try {
      const next = await getUnsoldReturnApproval(selectedId);
      if (sequence !== detailSequence.current) return;
      setDetail(next);
      setReason('');
      approveKey.current = null;
      rejectKey.current = null;
    } catch (caught) {
      if (sequence === detailSequence.current) {
        setDetail(null);
        setDetailError(apiMessage(caught, 'Unable to load this approval request.'));
      }
    } finally {
      if (sequence === detailSequence.current) setDetailLoading(false);
    }
  }, [selectedId, contextKey]);

  useEffect(() => {
    setSelectedId(null);
    setDetail(null);
    setActionError(null);
    setActionSuccess(null);
    void refreshInbox();
  }, [refreshInbox, refreshToken]);

  useEffect(() => {
    setActionError(null);
    setActionSuccess(null);
    void loadDetail();
  }, [loadDetail]);

  function changeReason(value: string) {
    setReason(value);
    setActionError(null);
    setActionSuccess(null);
    approveKey.current = null;
    rejectKey.current = null;
  }

  async function decide(decision: 'approve' | 'reject') {
    if (!detail || !detail.can_decide || submitting) return;
    const trimmedReason = reason.trim();
    if (decision === 'reject' && trimmedReason.length < 3) {
      setActionError('Enter a rejection reason of at least three characters.');
      return;
    }

    setSubmitting(decision);
    setActionError(null);
    setActionSuccess(null);
    const keyRef = decision === 'approve' ? approveKey : rejectKey;
    keyRef.current ??= globalThis.crypto.randomUUID();

    try {
      const result = decision === 'approve'
        ? await approveUnsoldReturnApproval(
            detail.id,
            trimmedReason || undefined,
            detail.record_version,
            keyRef.current
          )
        : await rejectUnsoldReturnApproval(
            detail.id,
            trimmedReason,
            detail.record_version,
            keyRef.current
          );

      keyRef.current = null;
      setActionSuccess(
        decision === 'approve'
          ? `Disposition approved. ${result.stock_movement_ids.length} non-destroy outcome movement${result.stock_movement_ids.length === 1 ? '' : 's'} posted; Finance loss posting is now available.`
          : 'Disposition rejected. The case returned to Quality quarantine for correction.'
      );
      await Promise.all([loadDetail(), refreshInbox(), Promise.resolve(onDecision(result))]);
    } catch (caught) {
      setActionError(apiMessage(caught, `Unable to ${decision} this approval request.`));
    } finally {
      setSubmitting(null);
    }
  }

  if (!canApprove) return null;

  return (
    <section className="panel approval-inbox" style={{ marginTop: 16 }}>
      <div className="panel-head">
        <div><h3>Loss disposition approval inbox</h3><span>Scoped reviewer queue · maker-checker enforced</span></div>
        <div className="approval-toolbar">
          <select value={filter} onChange={(event) => { setSelectedId(null); setFilter(event.target.value as ApprovalFilter); }} aria-label="Approval status filter">
            <option value="PENDING">Pending</option>
            <option value="APPROVED">Approved</option>
            <option value="REJECTED">Rejected</option>
          </select>
          <button className="secondary compact-button" type="button" onClick={() => void refreshInbox()} disabled={listLoading}>Refresh</button>
        </div>
      </div>

      {listError && <div className="form-error panel-message" role="alert"><span>{listError}</span><button type="button" onClick={() => void refreshInbox()}>Retry</button></div>}
      <div className="approval-layout">
        <div className="approval-queue">
          <div className="approval-queue-summary"><b>{listLoading ? '—' : list?.meta.total ?? 0}</b><span>{filter.toLowerCase()} requests in this plant</span></div>
          {listLoading && !list && <div className="empty-state">Loading reviewer queue...</div>}
          {!listLoading && !listError && !list?.data.length && <div className="empty-state">No {filter.toLowerCase()} loss dispositions in this context.</div>}
          {list?.data.map((approval) => (
            <button className={`approval-queue-item ${selectedId === approval.id ? 'selected' : ''}`} type="button" key={approval.id} onClick={() => setSelectedId(approval.id)}>
              <span><b>{shortId(approval.return_case.id)}</b><StatusBadge status={displayStatus(approval.status)} /></span>
              <strong>{approval.return_case.party.name ?? approval.return_case.party.code ?? 'Customer'}</strong>
              <small>Submission {approval.submission_number} · {formatQuantity(approval.destroy_quantity)} destroy · {approval.authority.band_name ?? 'approval authority'}</small>
            </button>
          ))}
        </div>

        <div className="approval-detail">
          {!selectedId && <div className="empty-state">Select an approval request to inspect its submitted disposition.</div>}
          {detailLoading && !detail && <div className="empty-state">Loading approval detail...</div>}
          {detailError && <div className="form-error" role="alert"><span>{detailError}</span><button type="button" onClick={() => void loadDetail()}>Retry</button></div>}
          {detail && (
            <>
              <div className="approval-detail-head">
                <div><span>APPROVAL {shortId(detail.id)}</span><h4>{detail.return_case.party.name ?? detail.return_case.party.code ?? 'Customer return'}</h4><small>Submitted by {detail.maker.name ?? detail.maker.id} · approval v{detail.record_version}</small></div>
                <StatusBadge status={displayStatus(detail.status)} />
              </div>

              {actionError && <div className="form-error" role="alert"><span>{actionError}</span><button type="button" onClick={() => void loadDetail()}>Refresh</button></div>}
              {actionSuccess && <div className="form-success" role="status"><span></span>{actionSuccess}</div>}

              <div className="approval-facts">
                <div><span>Return case</span><button type="button" onClick={() => onOpenCase(detail.return_case.id)}>{shortId(detail.return_case.id)}</button></div>
                <div><span>Case version</span><b>v{detail.entity_version}</b></div>
                <div><span>Total destroy</span><b>{formatQuantity(detail.destroy_quantity)}</b></div>
                <div><span>Reason</span><b>{displayStatus(detail.return_case.reason_code)}</b></div>
                <div><span>Authority band</span><b>{detail.authority.band_name ?? detail.rule_code}</b></div>
                <div><span>Required authority</span><b>{detail.authority.required_permission.replace('ACTION:RET-UNSOLD:', '')}</b></div>
                <div><span>Submission</span><b>{detail.submission_number}{detail.resubmission_of_id ? ' · corrected resubmission' : ''}</b></div>
                <div><span>SLA / escalation</span><b>{detail.escalated_at ? `Escalated ${formatDateTime(detail.escalated_at)}` : detail.due_at ? `Due ${formatDateTime(detail.due_at)}` : 'No deadline'}</b></div>
              </div>

              <div className="table-wrap approval-lines">
                <table>
                  <thead><tr><th>SKU / lot</th><th>Received</th><th>Restock</th><th>Repack</th><th>Rework</th><th>Destroy</th><th>Quality reason</th></tr></thead>
                  <tbody>{detail.lines.map((line) => <tr key={line.id}><td><b>{line.sku.code ?? line.sku.id}</b><small>{line.fg_lot?.code ?? 'No lot'}</small></td><td>{formatQuantity(line.received_quantity)} {line.uom_code}</td><td>{formatQuantity(line.restock_quantity)}</td><td>{formatQuantity(line.repack_quantity)}</td><td>{formatQuantity(line.rework_quantity)}</td><td>{formatQuantity(line.destroy_quantity)}</td><td>{line.quality_reason_code ? displayStatus(line.quality_reason_code) : '-'}</td></tr>)}</tbody>
                </table>
              </div>

              {detail.status === 'PENDING' && (
                <div className="approval-decision">
                  {!detail.can_decide && <div className="action-wait">This request requires {detail.authority.required_permission}. Makers cannot review their own disposition, and the source case version must still match.</div>}
                  <label>Reviewer note / rejection reason<textarea rows={3} maxLength={2000} value={reason} onChange={(event) => changeReason(event.target.value)} placeholder="Required for rejection; optional for approval." disabled={!detail.can_decide || submitting !== null} /></label>
                  <div className="form-actions"><button className="danger-button" type="button" onClick={() => void decide('reject')} disabled={!detail.can_decide || submitting !== null}>{submitting === 'reject' ? 'Rejecting...' : 'Reject to Quality'}</button><button className="primary" type="button" onClick={() => void decide('approve')} disabled={!detail.can_decide || submitting !== null}>{submitting === 'approve' ? 'Approving...' : 'Approve disposition'}</button></div>
                </div>
              )}

              {detail.decisions.length > 0 && <div className="approval-decision-history"><h4>Decision record</h4>{detail.decisions.map((decision) => <div key={decision.id}><StatusBadge status={decision.decision} /><span><b>{decision.reviewer.name ?? decision.reviewer.id}</b><small>{decision.reason ?? 'No reviewer note'} · {decision.authority_source ?? 'Recorded'} authority · {formatDateTime(decision.decided_at)}</small></span></div>)}</div>}
            </>
          )}
        </div>
      </div>
    </section>
  );
}

function apiMessage(caught: unknown, fallback: string): string {
  if (!isApiError(caught)) return fallback;
  return caught.fields ? Object.values(caught.fields).flat()[0] ?? caught.message : caught.message;
}

function displayStatus(status: string): string {
  return status.replaceAll('_', ' ');
}

function formatQuantity(quantity: string): string {
  const value = Number(quantity);
  return Number.isFinite(value) ? value.toLocaleString(undefined, { maximumFractionDigits: 6 }) : quantity;
}

function formatDateTime(value: string): string {
  return formatZonedDateTime(value);
}

function shortId(id: string): string {
  return id.slice(0, 8).toUpperCase();
}

import { useEffect, useState } from 'react';
import { changeTransactionVisibility } from '../api/transactionVisibility';
import type { VisibilityMetadata } from '../api/p2Operations';
import { isApiError } from '../api/client';
import { StatusBadge } from './StatusBadge';

const HELPER = 'This entry will be hidden from ordinary operational views and visible only to users with ShowEntries permission. Official stock and accounting records remain unchanged.';

export function TransactionVisibilityControl({
  transactionType,
  transactionId,
  visibility,
  permissions,
  onChanged,
}: {
  transactionType: string;
  transactionId: string;
  visibility?: VisibilityMetadata;
  permissions: string[];
  onChanged: (visibility: VisibilityMetadata) => Promise<void> | void;
}) {
  const canChange = permissions.includes('DidntShowCheckbox');
  const canShow = permissions.includes('ShowEntries');
  const [restricted, setRestricted] = useState(Boolean(visibility?.is_restricted));
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => setRestricted(Boolean(visibility?.is_restricted)), [visibility?.is_restricted]);
  // Never render cached restricted metadata to a user who no longer has either
  // visibility permission. The API remains authoritative, but this also closes
  // the brief stale-client window after an RBAC change.
  if (!canChange && !canShow) return null;
  if (!canChange && !visibility?.is_restricted) return null;

  async function toggle(next: boolean) {
    if (!canChange || busy) return;
    if (next && !canShow && !window.confirm('Restricting this entry will remove it from your operational view immediately. A user with both visibility permissions must recover it. Continue?')) return;
    // Update the controlled input synchronously so a click changes its state.
    // The server remains authoritative: roll back if the mutation fails.
    setRestricted(next);
    setBusy(true); setError(null);
    try {
      const changed = await changeTransactionVisibility(
        transactionType,
        transactionId,
        next ? 'RESTRICTED' : 'NORMAL',
        visibility?.record_version ?? 0,
      );
      setRestricted(changed.is_restricted);
      await onChanged(changed);
    } catch (caught) {
      setRestricted(Boolean(visibility?.is_restricted));
      setError(isApiError(caught) ? caught.message : 'Unable to change entry visibility.');
    } finally { setBusy(false); }
  }

  return <section className="transaction-visibility-control" aria-label="Transaction visibility">
    <div>{restricted ? <StatusBadge status="RESTRICTED" /> : <StatusBadge status="NORMAL VISIBILITY" />}</div>
    {canChange ? <label><input type="checkbox" checked={restricted} disabled={busy} onChange={(event) => void toggle(event.target.checked)} /> Restrict Entry</label> : null}
    {canChange ? <p>{HELPER}</p> : <p>This restricted entry is visible because you have ShowEntries permission.</p>}
    {error ? <div className="form-error" role="alert"><span>{error}</span></div> : null}
  </section>;
}

export { HELPER as TRANSACTION_VISIBILITY_HELPER };

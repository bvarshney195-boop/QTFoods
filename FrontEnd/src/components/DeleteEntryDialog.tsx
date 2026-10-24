import { useId } from 'react';

type DeleteEntryDialogProps = {
  entryLabel: string;
  entryCode?: string;
  busy?: boolean;
  onCancel: () => void;
  onConfirm: () => void;
};

export function DeleteEntryDialog({
  entryLabel,
  entryCode,
  busy = false,
  onCancel,
  onConfirm,
}: DeleteEntryDialogProps) {
  const titleId = useId();

  return (
    <div className="confirm-overlay" role="presentation">
      <section className="confirmation-dialog" role="alertdialog" aria-modal="true" aria-labelledby={titleId}>
        <h3 id={titleId}>Delete {entryLabel}?</h3>
        <p>You are deleting this entry. This action cannot be undone. Confirm that you want to remove it from the ERP.</p>
        {entryCode && <p className="callout"><b>Entry:</b> {entryCode}</p>}
        <div className="form-actions">
          <button className="secondary" type="button" autoFocus onClick={onCancel} disabled={busy}>Cancel</button>
          <button className="danger-button" type="button" onClick={onConfirm} disabled={busy}>{busy ? 'Deleting...' : 'Delete entry'}</button>
        </div>
      </section>
    </div>
  );
}

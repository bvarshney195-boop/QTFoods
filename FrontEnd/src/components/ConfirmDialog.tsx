import { useEffect, useRef } from 'react';

export function ConfirmDialog({ open, title, children, confirmLabel, onConfirm, onCancel, destructive = true }: {
  open: boolean;
  title: string;
  children: React.ReactNode;
  confirmLabel: string;
  onConfirm: () => void;
  onCancel: () => void;
  destructive?: boolean;
}) {
  const cancelRef = useRef<HTMLButtonElement>(null);
  useEffect(() => {
    if (!open) return;
    const prior = document.activeElement as HTMLElement | null;
    cancelRef.current?.focus();
    const onKey = (event: KeyboardEvent) => { if (event.key === 'Escape') onCancel(); };
    window.addEventListener('keydown', onKey);
    return () => { window.removeEventListener('keydown', onKey); prior?.focus(); };
  }, [open, onCancel]);

  if (!open) return null;
  return <div className="modal-backdrop" role="presentation" onMouseDown={(event) => { if (event.target === event.currentTarget) onCancel(); }}>
    <section className="confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="confirm-dialog-title">
      <h2 id="confirm-dialog-title">{title}</h2>
      <div className="confirm-dialog-body">{children}</div>
      <div className="form-actions">
        <button ref={cancelRef} className="secondary" type="button" onClick={onCancel}>Keep reviewing</button>
        <button className={destructive ? 'danger' : 'primary'} type="button" onClick={onConfirm}>{confirmLabel}</button>
      </div>
    </section>
  </div>;
}

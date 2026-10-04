import { useEffect } from 'react';

export function SuccessToast({ message, onDismiss }: { message: string | null; onDismiss: () => void }) {
  useEffect(() => {
    if (!message) return;
    const timer = window.setTimeout(onDismiss, 5000);
    return () => window.clearTimeout(timer);
  }, [message, onDismiss]);

  if (!message) return null;

  return (
    <div className="success-toast" role="status" aria-live="polite" aria-atomic="true">
      <span className="success-toast-icon" aria-hidden="true">✓</span>
      <div><b>Success</b><p>{message}</p></div>
      <button type="button" aria-label="Dismiss success message" onClick={onDismiss}>×</button>
    </div>
  );
}

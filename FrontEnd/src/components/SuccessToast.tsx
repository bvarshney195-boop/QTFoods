import { useEffect } from 'react';

export function SuccessToast({ message, onDismiss, timeoutMs = 5000 }: { message: string | null; onDismiss: () => void; timeoutMs?: number }) {
  useEffect(() => {
    if (!message) return;
    const timer = window.setTimeout(onDismiss, timeoutMs);
    return () => window.clearTimeout(timer);
  }, [message, onDismiss, timeoutMs]);

  if (!message) return null;
  return <div className="success-toast" role="status" aria-live="polite" aria-atomic="true">
    <span className="success-toast-icon" aria-hidden="true">✓</span>
    <div><b>Success</b><span>{message}</span></div>
    <button type="button" aria-label="Dismiss success message" onClick={onDismiss}>×</button>
  </div>;
}

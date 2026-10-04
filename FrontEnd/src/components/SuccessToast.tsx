import { useEffect } from 'react';

export function SuccessToast({ message, onDismiss }: { message: string; onDismiss: () => void }) {
  useEffect(() => {
    const timer = window.setTimeout(onDismiss, 5000);
    return () => window.clearTimeout(timer);
  }, [message, onDismiss]);

  return <div className="form-success success-toast" role="status" aria-live="polite">
    <span aria-hidden="true">✓</span>
    <div><b>Success</b><small>{message}</small></div>
    <button type="button" aria-label="Dismiss success message" onClick={onDismiss}>×</button>
  </div>;
}

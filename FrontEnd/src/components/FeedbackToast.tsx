import { useEffect, useRef } from 'react';

export function FeedbackToast({ title = 'Saved', message, onDismiss, duration = 5000 }: {
  title?: string;
  message: string | null;
  onDismiss: () => void;
  duration?: number;
}) {
  const dismissRef = useRef(onDismiss);
  dismissRef.current = onDismiss;
  useEffect(() => {
    if (!message) return;
    const timer = window.setTimeout(() => dismissRef.current(), duration);
    return () => window.clearTimeout(timer);
  }, [duration, message]);

  if (!message) return null;
  return <div className="feedback-toast" role="status" aria-live="polite">
    <span aria-hidden="true">✓</span><div><strong>{title}</strong><p>{message}</p></div>
    <button type="button" aria-label="Dismiss notification" onClick={onDismiss}>×</button>
  </div>;
}

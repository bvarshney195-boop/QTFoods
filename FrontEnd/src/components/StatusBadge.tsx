import { statusLabel } from '../utils/displayText';

export function StatusBadge({ status }: { status: string }) {
  const key = status.toLowerCase();
  const tone =
    key.includes('failure') || key.includes('exception') || key === 'fail' || key.includes('denied') || key.includes('quarantined') || key.includes('rejected') || key.includes('error') ? 'bad' :
    key.includes('approved') || key.includes('released') || key.includes('recorded') || key.includes('current') || key.includes('posted') || key.includes('delivered') || key.includes('success') || key.includes('reconciled') || key === 'pass' || key.includes('acknowledged') || key === 'active' || key.includes('resolved') ? 'ok' :
    key.includes('pending') || key.includes('review') || key.includes('preview') || key.includes('provisional') || key.includes('retry') || key.includes('held') ? 'warn' :
    key.includes('progress') || key.includes('processing') ? 'info' :
    key === 'draft' || key === 'inactive' || key === 'closed' || key === 'cancelled' || key === 'archived' ? 'neutral' :
    'info';

  return <span className={`status status-${tone}`}>{statusLabel(status)}</span>;
}

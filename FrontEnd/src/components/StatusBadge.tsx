import { statusLabel } from '../utils/displayText';

export function StatusBadge({ status }: { status: string }) {
  const key = status.toLowerCase();
  const tone =
    key.includes('held') || key.includes('validation') || key.includes('failure') || key.includes('failed') || key.includes('exception') || key === 'fail' || key.includes('denied') || key.includes('quarantined') || key.includes('rejected') || key.includes('cancelled') || key.includes('overdue') || key.includes('expired') || key.includes('blocked') ? 'bad' :
    key.includes('approved') || key.includes('released') || key.includes('recorded') || key.includes('current') || key.includes('posted') || key.includes('delivered') || key.includes('success') || key.includes('reconciled') || key === 'pass' || key.includes('acknowledged') || key === 'active' || key.includes('settled') || key === 'paid' || key.includes('completed') ? 'ok' :
    key.includes('pending') || key.includes('review') || key.includes('preview') || key.includes('provisional') || key.includes('retry') || key.includes('partial') || key.includes('awaiting') || key.includes('missing') ? 'warn' :
    key.includes('progress') || key.includes('processing') || key.includes('transit') ? 'info' :
    key === 'draft' || key === 'inactive' ? 'neutral' :
    'info';

  return <span className={`status status-${tone}`}>{statusLabel(status)}</span>;
}

import { statusLabel } from '../utils/displayText';

export function StatusBadge({ status }: { status: string }) {
  const key = status.toLowerCase();
  const tone =
    key === 'draft' || key === 'inactive' || key === 'closed' || key === 'archived' || key === 'not_applicable' || key === 'not recognized' || key === 'not_recognized' ? 'neutral' :
    key.includes('held') || key.includes('validation') || key.includes('failure') || key.includes('exception') || key === 'fail' || key.includes('denied') || key.includes('quarantined') || key.includes('rejected') || key.includes('cancelled') || key.includes('error') ? 'bad' :
    key.includes('approved') || key.includes('released') || key.includes('recorded') || key.includes('posted') || key.includes('delivered') || key.includes('success') || key.includes('reconciled') || key === 'pass' || key.includes('acknowledged') || key === 'active' || key === 'resolved' || key === 'settled' || key === 'complete' ? 'ok' :
    key.includes('pending') || key.includes('review') || key.includes('retry') || key.includes('provisional') ? 'warn' :
    key.includes('progress') || key.includes('processing') || key.includes('transit') ? 'info' :
    'info';

  return <span className={`status status-${tone}`}><span className="status-dot" aria-hidden="true" />{statusLabel(status)}</span>;
}

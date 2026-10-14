import {
  useEffect,
  useId,
  useRef,
  useState,
  type CSSProperties,
  type KeyboardEvent,
  type ReactNode,
} from 'react';

export type ExpandableRegisterColumn<T> = {
  key: string;
  heading: string;
  render: (record: T) => ReactNode;
  className?: string;
};

type ExpandableRegisterProps<T> = {
  ariaLabel: string;
  records: T[];
  columns: ExpandableRegisterColumn<T>[];
  getRecordId: (record: T) => string;
  getRecordLabel: (record: T) => string;
  renderExpanded: (record: T) => ReactNode;
  selectedId?: string | null;
  columnsTemplate?: string;
};

type RegisterFact = {
  label: string;
  value: ReactNode;
  wide?: boolean;
};

/**
 * Shared disclosure register for ERP master data. Only one record is expanded
 * at a time, and the native toggle buttons retain Enter/Space behaviour while
 * also supporting Arrow/Home/End movement between records.
 */
export function ExpandableRegister<T>({
  ariaLabel,
  records,
  columns,
  getRecordId,
  getRecordLabel,
  renderExpanded,
  selectedId = null,
  columnsTemplate,
}: ExpandableRegisterProps<T>) {
  const registerId = useId();
  const [expandedId, setExpandedId] = useState<string | null>(null);
  const toggleRefs = useRef(new Map<string, HTMLButtonElement>());
  const recordIds = records.map(getRecordId);
  const gridStyle = {
    '--register-columns': `44px ${columnsTemplate ?? `repeat(${columns.length}, minmax(120px, 1fr))`}`,
  } as CSSProperties;

  useEffect(() => {
    if (expandedId && !recordIds.includes(expandedId)) setExpandedId(null);
  }, [expandedId, recordIds.join('|')]);

  function moveToggle(event: KeyboardEvent<HTMLButtonElement>, recordId: string) {
    const currentIndex = recordIds.indexOf(recordId);
    if (currentIndex < 0) return;
    let targetIndex: number | null = null;
    if (event.key === 'ArrowDown') targetIndex = Math.min(currentIndex + 1, recordIds.length - 1);
    if (event.key === 'ArrowUp') targetIndex = Math.max(currentIndex - 1, 0);
    if (event.key === 'Home') targetIndex = 0;
    if (event.key === 'End') targetIndex = recordIds.length - 1;
    if (targetIndex === null) return;
    event.preventDefault();
    toggleRefs.current.get(recordIds[targetIndex])?.focus();
  }

  return (
    <div className="expandable-register" role="table" aria-label={ariaLabel} aria-rowcount={records.length + 1}>
      <div className="expandable-register-head" role="rowgroup">
        <div className="expandable-register-grid" role="row" style={gridStyle}>
          <span role="columnheader" aria-label="Expand record" />
          {columns.map((column) => <span key={column.key} role="columnheader">{column.heading}</span>)}
        </div>
      </div>
      {records.map((record, rowIndex) => {
          const recordId = getRecordId(record);
          const recordLabel = getRecordLabel(record);
          const expanded = expandedId === recordId;
          const safeId = recordId.replace(/[^a-zA-Z0-9_-]/g, '-');
          const toggleId = `${registerId}-${safeId}-toggle`;
          const panelId = `${registerId}-${safeId}-detail`;

          return (
            <div
              className={`expandable-register-record${selectedId === recordId ? ' is-selected' : ''}${expanded ? ' is-expanded' : ''}`}
              key={recordId}
              role="rowgroup"
            >
              <div className="expandable-register-grid expandable-register-summary" role="row" aria-rowindex={rowIndex + 2} style={gridStyle}>
                <div className="expandable-register-toggle-cell" role="cell">
                  <button
                    id={toggleId}
                    className="register-chevron"
                    type="button"
                    aria-expanded={expanded}
                    aria-controls={panelId}
                    aria-label={`${expanded ? 'Collapse' : 'Expand'} ${recordLabel}`}
                    onClick={() => setExpandedId((current) => current === recordId ? null : recordId)}
                    onKeyDown={(event) => moveToggle(event, recordId)}
                    ref={(node) => {
                      if (node) toggleRefs.current.set(recordId, node);
                      else toggleRefs.current.delete(recordId);
                    }}
                  >
                    <span aria-hidden="true">›</span>
                  </button>
                </div>
                {columns.map((column) => (
                  <div
                    className={`expandable-register-cell${column.className ? ` ${column.className}` : ''}`}
                    data-label={column.heading}
                    key={column.key}
                    role="cell"
                  >
                    {column.render(record)}
                  </div>
                ))}
              </div>
              {expanded && (
                <div className="expandable-register-detail-row" role="row">
                  <div
                    id={panelId}
                    className="expandable-register-detail"
                    role="cell"
                    aria-colspan={columns.length + 1}
                  >
                    <section role="region" aria-labelledby={toggleId}>
                      {renderExpanded(record)}
                    </section>
                  </div>
                </div>
              )}
            </div>
          );
        })}
    </div>
  );
}

export function RegisterDetailGrid({ facts }: { facts: RegisterFact[] }) {
  return (
    <dl className="register-detail-grid">
      {facts.map((fact) => (
        <div className={fact.wide ? 'wide' : undefined} key={fact.label}>
          <dt>{fact.label}</dt>
          <dd>{fact.value ?? '—'}</dd>
        </div>
      ))}
    </dl>
  );
}

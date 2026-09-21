export function PageHeader({
  code,
  title,
  description,
  onNew,
  onHistory,
  onExport,
}: {
  code: string;
  batch: string;
  title: string;
  description: string;
  onNew?: () => void;
  onHistory?: () => void;
  onExport?: () => void;
}) {
  return (
    <div className="page-head" data-screen-code={code}>
      <div>
        <h1>{title}</h1>
        <p>{description}</p>
      </div>
      {(onHistory || onExport || onNew) && (
        <div className="head-actions">
          {onHistory && <button className="secondary" type="button" onClick={onHistory}>History</button>}
          {onExport && <button className="secondary" type="button" onClick={onExport}>Export</button>}
          {onNew && <button className="primary" type="button" onClick={onNew}>+ New</button>}
        </div>
      )}
    </div>
  );
}

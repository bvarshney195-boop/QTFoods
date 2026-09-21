import { useEffect, type RefObject } from 'react';

const MAX_CARD_COLUMNS = 7;

/**
 * Adds mobile labels to ordinary data tables without changing their desktop
 * markup. Complex matrix/report tables keep their horizontal-scroll layout.
 * This shared enhancement intentionally contains no module-specific business logic.
 */
export function useResponsiveDataTables(rootRef: RefObject<HTMLElement | null>): void {
  useEffect(() => {
    const root = rootRef.current;
    if (!root) return;

    const enhanceTables = () => {
      root.querySelectorAll<HTMLTableElement>('.table-wrap table').forEach((table) => {
        const headings = Array.from(table.querySelectorAll<HTMLTableCellElement>('thead > tr:first-child > th'));
        const rows = Array.from(table.querySelectorAll<HTMLTableRowElement>('tbody > tr'));
        const isSimple = headings.length >= 2
          && headings.length <= MAX_CARD_COLUMNS
          && rows.every((row) => Array.from(row.cells).every((cell) => cell.colSpan === 1 && cell.rowSpan === 1));

        table.classList.toggle('mobile-card-table', isSimple);
        if (!isSimple) return;

        const labels = headings.map((heading, index) => heading.textContent?.trim() || (index === headings.length - 1 ? 'Actions' : 'Detail'));
        rows.forEach((row) => {
          Array.from(row.cells).forEach((cell, index) => {
            if (!cell.hasAttribute('data-label')) cell.setAttribute('data-label', labels[index] ?? 'Detail');
          });
        });
      });
    };

    enhanceTables();
    const observer = new MutationObserver(enhanceTables);
    observer.observe(root, { childList: true, subtree: true });
    return () => observer.disconnect();
  }, [rootRef]);
}

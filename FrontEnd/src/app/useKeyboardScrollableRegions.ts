import { useEffect, type RefObject } from 'react';

export function useKeyboardScrollableRegions(rootRef: RefObject<HTMLElement | null>): void {
  useEffect(() => {
    const root = rootRef.current;
    if (!root) return;

    const makeReachable = () => {
      root.querySelectorAll<HTMLElement>('.table-wrap').forEach((region) => {
        if (!region.hasAttribute('tabindex')) region.tabIndex = 0;
        if (!region.hasAttribute('role')) region.setAttribute('role', 'region');
        if (!region.hasAttribute('aria-label')) {
          const panel = region.closest('.panel');
          const heading = panel?.querySelector<HTMLElement>('h2, h3, h4');
          region.setAttribute('aria-label', heading?.textContent?.trim() || 'Scrollable data table');
        }
      });
    };

    makeReachable();
    const observer = new MutationObserver(makeReachable);
    observer.observe(root, { childList: true, subtree: true });

    return () => observer.disconnect();
  }, [rootRef]);
}

import { useEffect, type RefObject } from 'react';

const editorSelector = '.requisition-editor, .admin-editor, .inventory-operation-editor';
const addOrNewAction = /^\+?\s*(?:add|new)\b/i;
const editableFieldSelector = 'input:not([type="hidden"]), select, textarea';

export function useEditorActionReveal(rootRef: RefObject<HTMLElement | null>): void {
  useEffect(() => {
    const root = rootRef.current;
    if (!root) return;

    let frame: number | null = null;
    const reveal = (event: MouseEvent) => {
      if (!(event.target instanceof Element)) return;
      const button = event.target.closest<HTMLButtonElement>('button');
      const label = button?.textContent?.trim() ?? '';
      if (!button || !root.contains(button) || !addOrNewAction.test(label)) return;

      const containingEditor = button.closest<HTMLElement>(editorSelector);
      const editor = containingEditor ?? root.querySelector<HTMLElement>(editorSelector);
      if (!editor) return;

      const existingFields = new Set(editor.querySelectorAll<HTMLElement>(editableFieldSelector));
      if (frame !== null) window.cancelAnimationFrame(frame);
      frame = window.requestAnimationFrame(() => {
        frame = null;

        if (!containingEditor) {
          editor.scrollTop = 0;
          if (window.innerWidth <= 820) editor.scrollIntoView?.({ block: 'start', inline: 'nearest' });
          return;
        }

        const addedField = Array.from(editor.querySelectorAll<HTMLElement>(editableFieldSelector))
          .find((field) => !existingFields.has(field));
        addedField?.scrollIntoView?.({ block: 'nearest', inline: 'nearest' });
      });
    };

    root.addEventListener('click', reveal);
    return () => {
      root.removeEventListener('click', reveal);
      if (frame !== null) window.cancelAnimationFrame(frame);
    };
  }, [rootRef]);
}

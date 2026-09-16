import { act, fireEvent, render, screen } from '@testing-library/react';
import { useRef, useState } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { useEditorActionReveal } from './useEditorActionReveal';

describe('useEditorActionReveal', () => {
  it('reveals mobile editors after New and newly appended fields after Add', () => {
    const originalWidth = window.innerWidth;
    const originalScroll = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'scrollIntoView');
    const scrolled: HTMLElement[] = [];
    const options: ScrollIntoViewOptions[] = [];
    let pendingFrame: FrameRequestCallback | null = null;

    Object.defineProperty(window, 'innerWidth', { configurable: true, value: 390 });
    Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', {
      configurable: true,
      value(this: HTMLElement, option: ScrollIntoViewOptions) {
        scrolled.push(this);
        options.push(option);
      },
    });
    vi.spyOn(window, 'requestAnimationFrame').mockImplementation((callback) => {
      pendingFrame = callback;
      return 1;
    });
    vi.spyOn(window, 'cancelAnimationFrame').mockImplementation(() => undefined);

    try {
      render(<EditorHarness />);
      screen.getByTestId('editor').scrollTop = 120;
      fireEvent.click(screen.getByRole('button', { name: '+ New' }));
      act(() => {
        pendingFrame?.(0);
      });

      expect(scrolled[0]).toBe(screen.getByTestId('editor'));
      expect(screen.getByTestId('editor')).toHaveProperty('scrollTop', 0);
      expect(options[0]).toEqual({ block: 'start', inline: 'nearest' });

      fireEvent.click(screen.getByRole('button', { name: 'Add line' }));
      act(() => {
        pendingFrame?.(1);
      });

      expect(scrolled[1]).toBe(screen.getByLabelText('Line 1 detail'));
      expect(options[1]).toEqual({ block: 'nearest', inline: 'nearest' });
    } finally {
      Object.defineProperty(window, 'innerWidth', { configurable: true, value: originalWidth });
      if (originalScroll) Object.defineProperty(HTMLElement.prototype, 'scrollIntoView', originalScroll);
      else Reflect.deleteProperty(HTMLElement.prototype, 'scrollIntoView');
    }
  });
});

function EditorHarness() {
  const rootRef = useRef<HTMLElement>(null);
  const [open, setOpen] = useState(false);
  const [lines, setLines] = useState(0);
  useEditorActionReveal(rootRef);

  return (
    <main ref={rootRef}>
      <button type="button" onClick={() => setOpen(true)}>+ New</button>
      <aside className="requisition-editor" data-testid="editor">
        {open && <>
          <label>Title<input /></label>
          <button type="button" onClick={() => setLines((value) => value + 1)}>Add line</button>
          {Array.from({ length: lines }, (_, index) => <label key={index}>Line {index + 1} detail<input aria-label={`Line ${index + 1} detail`} /></label>)}
        </>}
      </aside>
    </main>
  );
}

import { act, fireEvent, render, screen } from '@testing-library/react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { FeedbackToast } from './FeedbackToast';

describe('FeedbackToast', () => {
  afterEach(() => vi.useRealTimers());

  it('announces success without stealing focus and auto-dismisses after five seconds', () => {
    vi.useFakeTimers();
    const dismiss = vi.fn();
    render(<><button type="button">Continue editing</button><FeedbackToast title="Order saved" message="SO-100 was saved." onDismiss={dismiss} /></>);
    const editing = screen.getByRole('button', { name: 'Continue editing' });
    editing.focus();

    expect(screen.getByRole('status')).toHaveTextContent('Order saved');
    expect(document.activeElement).toBe(editing);
    act(() => vi.advanceTimersByTime(4_999));
    expect(dismiss).not.toHaveBeenCalled();
    act(() => vi.advanceTimersByTime(1));
    expect(dismiss).toHaveBeenCalledTimes(1);
    expect(document.activeElement).toBe(editing);
  });

  it('provides an explicit dismiss control', () => {
    const dismiss = vi.fn();
    render(<FeedbackToast message="Saved." onDismiss={dismiss} />);
    fireEvent.click(screen.getByRole('button', { name: 'Dismiss notification' }));
    expect(dismiss).toHaveBeenCalledTimes(1);
  });
});

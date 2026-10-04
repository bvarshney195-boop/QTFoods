import { useEffect } from 'react';

export function FeedbackBehavior() {
  useEffect(() => {
    const timers = new WeakMap<Element, number>();

    const enhance = (element: Element) => {
      if (!(element instanceof HTMLElement) || !element.matches('.form-success[role="status"]')) return;
      element.classList.remove('feedback-dismissed');
      if (!element.querySelector('.feedback-dismiss')) {
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'feedback-dismiss';
        button.setAttribute('aria-label', 'Dismiss success message');
        button.textContent = '×';
        button.addEventListener('click', () => element.classList.add('feedback-dismissed'));
        element.append(button);
      }
      const previous = timers.get(element);
      if (previous) window.clearTimeout(previous);
      timers.set(element, window.setTimeout(() => element.classList.add('feedback-dismissed'), 5000));
    };

    const scan = (root: ParentNode) => root.querySelectorAll('.form-success[role="status"]').forEach(enhance);
    scan(document);

    const observer = new MutationObserver((mutations) => {
      for (const mutation of mutations) {
        if (mutation.target instanceof HTMLElement && mutation.target.matches('.form-success[role="status"]')) {
          enhance(mutation.target);
        }
        mutation.addedNodes.forEach((node) => {
          if (node instanceof HTMLElement) {
            if (node.matches('.form-success[role="status"]')) enhance(node);
            scan(node);
          }
        });
      }
    });
    observer.observe(document.body, { childList: true, subtree: true, characterData: true });

    return () => observer.disconnect();
  }, []);

  return null;
}

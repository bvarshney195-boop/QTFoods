import { saveE2EStackDiagnostics, stopE2EStack } from './support/compose';

export default function globalTeardown(): void {
  try {
    // Capture before teardown so assertion, browser, and startup failures all retain stack evidence.
    saveE2EStackDiagnostics(false);
  } finally {
    stopE2EStack();
  }
}

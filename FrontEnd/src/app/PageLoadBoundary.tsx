import { Component, type ErrorInfo, type ReactNode } from 'react';

type PageLoadBoundaryProps = {
  children: ReactNode;
  screenCode: string;
  onReload?: () => void;
};

type PageLoadBoundaryState = {
  error: Error | null;
};

export class PageLoadBoundary extends Component<PageLoadBoundaryProps, PageLoadBoundaryState> {
  state: PageLoadBoundaryState = { error: null };

  static getDerivedStateFromError(error: Error): PageLoadBoundaryState {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo): void {
    console.error('ERP workspace failed to load.', { screenCode: this.props.screenCode, error, componentStack: info.componentStack });
  }

  componentDidUpdate(previous: PageLoadBoundaryProps): void {
    if (previous.screenCode !== this.props.screenCode && this.state.error) this.setState({ error: null });
  }

  render(): ReactNode {
    if (!this.state.error) return this.props.children;

    const chunkFailure = /chunk|dynamically imported|module script|failed to fetch/i.test(this.state.error.message);
    return <section className="panel page-load-error" role="alert" aria-live="assertive">
      <span aria-hidden="true">!</span>
      <div>
        <h1>Workspace could not be opened</h1>
        <p>{chunkFailure
          ? 'The application was updated while this browser session was open. Reload to fetch the current workspace files.'
          : 'The workspace encountered an unexpected display error. Other navigation remains available.'}</p>
      </div>
      <button className="primary" type="button" onClick={() => (this.props.onReload ?? (() => window.location.reload()))()}>Reload application</button>
    </section>;
  }
}

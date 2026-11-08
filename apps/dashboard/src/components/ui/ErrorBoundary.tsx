import { Component, type ErrorInfo, type ReactNode } from "react";

/* A crash inside a render must never present as a blank white page.
   Before this existed, an unexpected API shape threw while rendering a page,
   React unmounted the whole tree, and the user was left staring at nothing —
   no message, no way back, and nothing in the UI to say what happened.
   Scoped per page, so one broken module cannot take down the whole shell. */
export class ErrorBoundary extends Component<
  { children: ReactNode; label?: string },
  { error: Error | null }
> {
  state: { error: Error | null } = { error: null };

  static getDerivedStateFromError(error: Error) {
    return { error };
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    /* Keep the stack for the console; the UI stays human-readable. */
    console.error("[dashboard] render failed:", error, info.componentStack);
  }

  render() {
    const { error } = this.state;
    if (!error) return this.props.children;
    const where = this.props.label ? ` in ${this.props.label}` : "";
    return (
      <div className="mx-auto max-w-xl py-16">
        <div className="rounded-card border border-danger/30 bg-danger-soft p-6">
          <p className="text-bodylg font-bold text-danger-strong">This screen hit an error{where}.</p>
          <p className="mt-1 text-small text-danger-strong/80">
            The rest of the workspace is still fine — go back to Overview, or reload to try again.
          </p>
          <p className="mt-3 rounded-control bg-white/60 px-3 py-2 font-mono text-[11px] text-danger-strong/90">
            {error.message || String(error)}
          </p>
          <div className="mt-4 flex gap-2">
            <button
              onClick={() => this.setState({ error: null })}
              className="rounded-control border border-danger/40 bg-white px-4 py-2 text-caption font-bold text-danger-strong hover:bg-danger-soft"
            >
              Try again
            </button>
            <a
              href={import.meta.env.BASE_URL}
              onClick={(e) => {
                e.preventDefault();
                window.history.replaceState(null, "", import.meta.env.BASE_URL);
                this.setState({ error: null });
              }}
              className="rounded-control bg-danger-strong px-4 py-2 text-caption font-bold text-white"
            >
              Go to Overview
            </a>
          </div>
        </div>
      </div>
    );
  }
}

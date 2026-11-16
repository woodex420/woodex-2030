import { Component, type ErrorInfo, type ReactNode } from "react";

/* A crash inside a render must never present as a blank white page.
   Before this existed, an unexpected API shape threw while rendering a page,
   React unmounted the whole tree, and the user was left staring at nothing —
   no message, no way back, and nothing in the UI to say what happened.

   Two things this card got wrong the first time, both found when Purchase
   Orders and Production crashed on a response shape (Furniture 2030 F-05):

   1. IT COULD NOT BE DISMISSED. "Go to Overview" rewrote the address with
      history.replaceState and cleared the error. React Router never hears about
      a raw replaceState, so it re-rendered the same route, which threw again.
      The button changed the URL and nothing else. It is now an ordinary link:
      the browser loads the dashboard home, which works whatever state the
      router is in.

   2. IT STAYED UP AFTER THE USER MOVED ON. `resetKey` is the current path.
      When it changes, the error is cleared — so choosing another screen from
      the sidebar leaves the broken one behind, instead of carrying its error
      card to every screen until a manual reload. It is a prop compared in
      componentDidUpdate rather than a React `key`, so a healthy page is never
      remounted by it.

   Mounted twice: around the page outlet in AppShell (so one broken screen
   leaves the sidebar and top bar usable) and around the whole router in
   App.tsx (so a crash in the shell itself still shows this card). */
export class ErrorBoundary extends Component<
  { children: ReactNode; label?: string; resetKey?: string },
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

  componentDidUpdate(previous: { resetKey?: string }) {
    if (this.state.error && previous.resetKey !== this.props.resetKey) this.setState({ error: null });
  }

  render() {
    const { error } = this.state;
    if (!error) return this.props.children;
    const where = this.props.label ? ` in ${this.props.label}` : "";
    return (
      <div className="mx-auto max-w-xl py-16" role="alert" data-error-boundary={this.props.label ?? "screen"}>
        <div className="rounded-card border border-danger/30 bg-danger-soft p-6">
          <p className="text-bodylg font-bold text-danger-strong">This screen hit an error{where}.</p>
          <p className="mt-1 text-small text-danger-strong/80">
            The rest of the workspace is still fine — choose another screen, go back to Overview, or try this one again.
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
            {/* A plain link on purpose: a full load of the dashboard home. */}
            <a
              href={import.meta.env.BASE_URL}
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

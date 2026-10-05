import type { ReactNode } from "react";
import { cn } from "@/lib/cn";

/** §27 — Empty / loading / error states. */

export function EmptyState({
  icon,
  title,
  description,
  action,
  secondary,
  className,
}: {
  icon?: ReactNode;
  title: string;
  description?: string;
  action?: ReactNode;
  secondary?: ReactNode;
  className?: string;
}) {
  return (
    <div className={cn("flex flex-col items-center justify-center px-6 py-10 text-center", className)}>
      {icon && (
        <div className="mb-3 grid h-12 w-12 place-items-center rounded-panel bg-slate-100 text-slate-500">
          {icon}
        </div>
      )}
      <p className="text-bodylg font-semibold text-ink">{title}</p>
      {description && <p className="mt-1 max-w-sm text-small text-muted">{description}</p>}
      {(action || secondary) && (
        <div className="mt-4 flex items-center gap-2">
          {action}
          {secondary}
        </div>
      )}
    </div>
  );
}

export function Skeleton({ className }: { className?: string }) {
  return <div aria-hidden className={cn("animate-pulse rounded-control bg-slate-100", className)} />;
}

/** Skeleton grid matching final layout (loading pattern). */
export function SkeletonRows({ rows = 4, cols = 1 }: { rows?: number; cols?: number }) {
  const n = Math.max(1, Math.min(4, cols));
  const gridCols = ["grid-cols-1", "grid-cols-2", "grid-cols-3", "grid-cols-4"][n - 1];
  return (
    <div className="space-y-2.5" aria-busy="true" aria-label="Loading">
      {Array.from({ length: rows }).map((_, r) => (
        <div key={r} className={cn("grid gap-2.5", gridCols)}>
          {Array.from({ length: n }).map((_, c) => (
            <Skeleton key={c} className="h-9 w-full" />
          ))}
        </div>
      ))}
    </div>
  );
}

export function ErrorState({
  title = "Something went wrong",
  description = "We couldn't load this data. Please try again.",
  onRetry,
  debug,
  className,
}: {
  title?: string;
  description?: string;
  onRetry?: () => void;
  debug?: string;
  className?: string;
}) {
  return (
    <div
      className={cn(
        "flex flex-col items-center justify-center rounded-card border border-danger/30 bg-danger-soft/40 px-6 py-10 text-center",
        className
      )}
    >
      <p className="text-bodylg font-semibold text-danger-strong">{title}</p>
      <p className="mt-1 max-w-sm text-small text-muted">{description}</p>
      {onRetry && (
        <button
          onClick={onRetry}
          className="mt-4 h-9 rounded-control border border-danger/40 bg-white px-4 text-small font-medium text-danger-strong transition-colors hover:bg-danger-soft"
        >
          Retry
        </button>
      )}
      {debug && <p className="mt-3 text-caption text-subtle">{debug}</p>}
    </div>
  );
}

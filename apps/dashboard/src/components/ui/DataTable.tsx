import { useState } from "react";
import type { ReactNode } from "react";
import { cn } from "@/lib/cn";
import { ChevronLeft, ChevronRight } from "@/icons";

export type Column<T> = {
  key: string;
  header: ReactNode;
  cell: (row: T) => ReactNode;
  align?: "left" | "right";
  className?: string;
  headClassName?: string;
};

/** Table — §13: sticky header, row hover, 52–60px rows, pagination. */
export function DataTable<T extends { id: string }>({
  rows,
  columns,
  perPage = 0,
  stickyHeight,
  onRowClick,
  footer,
}: {
  rows: T[];
  columns: Column<T>[];
  perPage?: number;
  stickyHeight?: number;
  onRowClick?: (row: T) => void;
  footer?: ReactNode;
}) {
  const [page, setPage] = useState(0);
  const paged = perPage > 0;
  const pages = paged ? Math.max(1, Math.ceil(rows.length / perPage)) : 1;
  const view = paged ? rows.slice(page * perPage, page * perPage + perPage) : rows;
  const sticky = stickyHeight !== undefined;

  return (
    <div>
      <div
        className="scrollbar-slim overflow-auto"
        style={sticky ? { maxHeight: stickyHeight } : undefined}
      >
        <table className="w-full min-w-[620px] border-collapse text-left">
          <thead>
            <tr className={cn("border-b border-line", sticky && "bg-surface")}>
              {columns.map((c) => (
                <th
                  key={c.key}
                  scope="col"
                  className={cn(
                    "px-4 py-2.5 text-caption font-semibold tracking-wide text-subtle uppercase",
                    sticky && "sticky top-0 z-10 bg-slate-50",
                    c.align === "right" && "text-right",
                    c.headClassName
                  )}
                >
                  {c.header}
                </th>
              ))}
            </tr>
          </thead>
          <tbody>
            {view.map((r) => (
              <tr
                key={r.id}
                onClick={onRowClick ? () => onRowClick(r) : undefined}
                className={cn(
                  "border-b border-slate-100 transition-colors duration-150 last:border-0 hover:bg-primary-50/50",
                  onRowClick && "cursor-pointer"
                )}
              >
                {columns.map((c) => (
                  <td
                    key={c.key}
                    className={cn(
                      "h-13 px-4 align-middle whitespace-nowrap",
                      c.align === "right" && "text-right",
                      c.className
                    )}
                  >
                    {c.cell(r)}
                  </td>
                ))}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      <div className="flex items-center justify-between gap-3 border-t border-line px-4 py-2.5">
        <span className="text-caption text-subtle">
          {paged
            ? `Showing ${view.length ? page * perPage + 1 : 0}–${page * perPage + view.length} of ${rows.length}`
            : `${rows.length} ${rows.length === 1 ? "row" : "rows"}`}
        </span>
        {paged && pages > 1 && (
          <div className="flex items-center gap-1.5">
            <button
              aria-label="Previous page"
              disabled={page === 0}
              onClick={() => setPage((p) => p - 1)}
              className="grid h-7 w-7 place-items-center rounded-control border border-line-strong bg-white text-slate-600 transition-colors hover:bg-slate-50 disabled:opacity-40"
            >
              <ChevronLeft size={14} />
            </button>
            <span className="px-1 text-caption font-medium text-muted">
              {page + 1} / {pages}
            </span>
            <button
              aria-label="Next page"
              disabled={page >= pages - 1}
              onClick={() => setPage((p) => p + 1)}
              className="grid h-7 w-7 place-items-center rounded-control border border-line-strong bg-white text-slate-600 transition-colors hover:bg-slate-50 disabled:opacity-40"
            >
              <ChevronRight size={14} />
            </button>
          </div>
        )}
        {footer}
      </div>
    </div>
  );
}

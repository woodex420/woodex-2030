import type { ReactNode } from "react";
import { Link } from "react-router";
import { ChevronRight } from "@/icons";

export type Crumb = { label: string; to?: string };

/** Page header — §24: breadcrumbs → title → description → primary actions. */
export function PageHeader({
  crumbs,
  title,
  description,
  actions,
}: {
  crumbs?: Crumb[];
  title: string;
  description?: string;
  actions?: ReactNode;
}) {
  return (
    <div className="mb-5">
      {crumbs && crumbs.length > 0 && (
        <nav aria-label="Breadcrumb" className="mb-1.5 flex items-center gap-1 text-caption">
          {crumbs.map((c, i) => (
            <span key={c.label + i} className="flex items-center gap-1">
              {i > 0 && <ChevronRight size={12} className="text-slate-400" />}
              {c.to ? (
                <Link to={c.to} className="text-muted transition-colors hover:text-primary-700">
                  {c.label}
                </Link>
              ) : (
                <span className="font-medium text-slate-600">{c.label}</span>
              )}
            </span>
          ))}
        </nav>
      )}
      <div className="flex flex-wrap items-end justify-between gap-x-4 gap-y-3">
        <div className="min-w-0">
          <h1 className="text-h1">{title}</h1>
          {description && <p className="mt-1 text-small text-muted">{description}</p>}
        </div>
        {actions && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
      </div>
    </div>
  );
}

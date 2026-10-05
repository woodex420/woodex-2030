import type { ReactNode } from "react";
import { Link } from "react-router";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { buttonCls } from "@/components/ui/Button";
import { EmptyState, Skeleton, SkeletonRows } from "@/components/ui/States";
import { ArrowRight } from "@/icons";

/** Under-construction module screen — §27 empty-state pattern with skeleton preview. */
export function Placeholder({
  crumbs,
  title,
  description,
  icon,
  targetLabel = "Open related screen",
  to,
  planned,
}: {
  crumbs: string[];
  title: string;
  description: string;
  icon: ReactNode;
  to?: string;
  targetLabel?: string;
  planned: string;
}) {
  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Agency OS", to: "/" }, ...crumbs.map((c, i) => ({ label: c, to: i === 0 ? "/" : undefined }))]}
        title={title}
        description={description}
      />
      <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_420px]">
        <Card>
          <EmptyState
            icon={icon}
            title={`${title} is scheduled for ${planned}`}
            description="The application shell, navigation, tokens and table/form patterns are already live — this module slots into them without redesign."
            action={
              to ? (
                <Link to={to} className={buttonCls("primary", "md")}>
                  {targetLabel} <ArrowRight size={14} />
                </Link>
              ) : (
                <Link to="/" className={buttonCls("primary", "md")}>
                  Back to dashboard <ArrowRight size={14} />
                </Link>
              )
            }
            secondary={
              <Link to="/settings" className={buttonCls("secondary", "md")}>
                Configure workspace
              </Link>
            }
            className="py-14"
          />
        </Card>
        <Card>
          <p className="mb-3 text-caption font-semibold tracking-wide text-subtle uppercase">Planned layout (skeleton)</p>
          <div className="grid grid-cols-3 gap-2.5">
            {[0, 1, 2].map((i) => (
              <Skeleton key={i} className="h-16" />
            ))}
          </div>
          <Skeleton className="mt-2.5 h-40" />
          <div className="mt-2.5">
            <SkeletonRows rows={3} />
          </div>
        </Card>
      </div>
    </div>
  );
}

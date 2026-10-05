import { Link } from "react-router";
import { cn } from "@/lib/cn";

export function Logo({
  collapsed = false,
  onLight = false,
  className,
}: {
  collapsed?: boolean;
  onLight?: boolean;
  className?: string;
}) {
  return (
    <Link
      to="/"
      aria-label="Woodex Agency OS — home"
      className={cn("flex min-w-0 items-center gap-2.5", collapsed && "justify-center", className)}
    >
      <span className="relative grid h-9 w-9 shrink-0 place-items-center rounded-control bg-primary-500 font-sans text-lg font-bold text-white shadow-cta">
        W
        <span className="absolute right-1 bottom-1 h-1.5 w-1.5 rounded-full bg-primary-100" aria-hidden />
      </span>
      {!collapsed && (
        <span className="min-w-0">
          <span className={cn("block text-bodylg leading-tight font-bold tracking-tight", onLight ? "text-ink" : "text-white")}>
            Woodex
          </span>
          <span className="block text-caption leading-tight font-medium tracking-[0.16em] text-slate-500 uppercase">
            Agency OS
          </span>
        </span>
      )}
    </Link>
  );
}

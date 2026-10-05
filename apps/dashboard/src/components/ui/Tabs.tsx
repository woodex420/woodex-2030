import type { ReactNode } from "react";
import { cn } from "@/lib/cn";

export type TabItem = { value: string; label: string; icon?: ReactNode; count?: number };

export function Tabs({
  items,
  value,
  onChange,
  variant = "underline",
  className,
}: {
  items: TabItem[];
  value: string;
  onChange: (v: string) => void;
  variant?: "underline" | "pill";
  className?: string;
}) {
  if (variant === "pill") {
    return (
      <div
        role="tablist"
        className={cn(
          "scrollbar-slim inline-flex max-w-full gap-1 overflow-x-auto rounded-control border border-line bg-slate-100/80 p-1",
          className
        )}
      >
        {items.map((t) => (
          <button
            key={t.value}
            role="tab"
            aria-selected={value === t.value}
            onClick={() => onChange(t.value)}
            className={cn(
              "inline-flex h-8 shrink-0 items-center gap-1.5 rounded-[5px] px-3 text-small font-medium transition-colors duration-150",
              value === t.value ? "bg-white text-ink shadow-card" : "text-muted hover:text-ink"
            )}
          >
            {t.label}
            {t.count !== undefined && (
              <span className="rounded-full bg-slate-100 px-1.5 text-caption text-slate-600">
                {t.count}
              </span>
            )}
          </button>
        ))}
      </div>
    );
  }

  return (
    <div
      role="tablist"
      className={cn(
        "scrollbar-slim flex items-center gap-1 overflow-x-auto border-b border-line",
        className
      )}
    >
      {items.map((t) => {
        const active = value === t.value;
        return (
          <button
            key={t.value}
            role="tab"
            aria-selected={active}
            onClick={() => onChange(t.value)}
            className={cn(
              "relative inline-flex h-10 shrink-0 items-center gap-1.5 px-3.5 text-small font-medium transition-colors duration-150",
              active ? "text-ink" : "text-muted hover:text-ink"
            )}
          >
            {t.icon}
            {t.label}
            {t.count !== undefined && (
              <span className="rounded-full bg-slate-100 px-1.5 text-caption text-slate-600">
                {t.count}
              </span>
            )}
            <span
              aria-hidden
              className={cn(
                "absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-primary-500",
                active ? "opacity-100" : "opacity-0"
              )}
            />
          </button>
        );
      })}
    </div>
  );
}

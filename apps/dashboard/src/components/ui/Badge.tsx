import type { ReactNode } from "react";
import { cn } from "@/lib/cn";
import { statusTone } from "@/data/mock";
import type { Tone } from "@/data/mock";
import { TrendingDown, TrendingUp } from "@/icons";

/* Chip styles — soft background, accessible strong text, dot + label (never color alone, §14) */
export const chipTone: Record<Tone, string> = {
  neutral: "bg-slate-100 text-slate-700",
  primary: "bg-primary-100 text-primary-700",
  success: "bg-success-soft text-success-strong",
  warning: "bg-warning-soft text-warning-strong",
  danger: "bg-danger-soft text-danger-strong",
  info: "bg-info-soft text-info-strong",
  muted: "bg-slate-100 text-subtle",
};

export const dotTone: Record<Tone, string> = {
  neutral: "bg-slate-500",
  primary: "bg-primary-500",
  success: "bg-success",
  warning: "bg-warning",
  danger: "bg-danger",
  info: "bg-info",
  muted: "bg-slate-400",
};

export const solidTone: Record<Tone, string> = {
  neutral: "bg-slate-500 text-white",
  primary: "bg-primary-500 text-white",
  success: "bg-success text-white",
  warning: "bg-warning text-charcoal-950",
  danger: "bg-danger text-white",
  info: "bg-info text-white",
  muted: "bg-slate-300 text-slate-700",
};

export function Badge({
  tone = "neutral",
  dot = true,
  className,
  children,
}: {
  tone?: Tone;
  dot?: boolean;
  className?: string;
  children: ReactNode;
}) {
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-caption font-medium whitespace-nowrap",
        chipTone[tone],
        className
      )}
    >
      {dot && <span aria-hidden className={cn("h-1.5 w-1.5 rounded-full", dotTone[tone])} />}
      {children}
    </span>
  );
}

/** Compact semantic status chip — §14 status system. */
export function StatusBadge({ status, className }: { status: string; className?: string }) {
  const tone = statusTone[status] ?? "neutral";
  return (
    <Badge tone={tone} className={className}>
      {status}
    </Badge>
  );
}

/** Trend indicator — success/danger styling per §7.2 KPI rules. */
export function TrendChip({ value, className }: { value: number; className?: string }) {
  const up = value >= 0;
  return (
    <span
      className={cn(
        "inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-caption font-semibold",
        up ? "bg-success-soft text-success-strong" : "bg-danger-soft text-danger-strong",
        className
      )}
    >
      {up ? <TrendingUp size={13} /> : <TrendingDown size={13} />}
      {up ? "↑" : "↓"} {Math.abs(value).toFixed(1)}%
    </span>
  );
}

import { cn } from "@/lib/cn";
import type { Tone } from "@/data/mock";

const fills: Record<Tone, string> = {
  neutral: "bg-slate-500",
  primary: "bg-primary-500",
  success: "bg-success",
  warning: "bg-warning",
  danger: "bg-danger",
  info: "bg-info",
  muted: "bg-slate-300",
};

export function Progress({
  value,
  tone = "primary",
  size = "md",
  className,
}: {
  value: number;
  tone?: Tone;
  size?: "sm" | "md" | "lg";
  className?: string;
}) {
  const v = Math.max(0, Math.min(100, value));
  return (
    <div
      role="progressbar"
      aria-valuenow={Math.round(v)}
      aria-valuemin={0}
      aria-valuemax={100}
      className={cn(
        "w-full overflow-hidden rounded-full bg-slate-100",
        size === "sm" && "h-1",
        size === "md" && "h-1.5",
        size === "lg" && "h-2.5",
        className
      )}
    >
      <div
        className={cn("h-full rounded-full transition-[width] duration-500 ease-out", fills[tone])}
        style={{ width: `${v}%` }}
      />
    </div>
  );
}

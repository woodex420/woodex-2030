import { cn } from "@/lib/cn";
import type { Tone } from "@/data/mock";

const tones: Record<Tone, string> = {
  neutral: "bg-slate-100 text-slate-700",
  primary: "bg-primary-100 text-primary-700",
  success: "bg-success-soft text-success-strong",
  warning: "bg-warning-soft text-warning-strong",
  danger: "bg-danger-soft text-danger-strong",
  info: "bg-info-soft text-info-strong",
  muted: "bg-slate-100 text-subtle",
};

const palette: Tone[] = ["primary", "info", "success", "warning", "neutral", "muted"];

function hash(s: string) {
  let h = 0;
  for (let i = 0; i < s.length; i++) h = (h * 31 + s.charCodeAt(i)) | 0;
  return Math.abs(h);
}

export function Avatar({
  initials,
  size = "md",
  tone,
  className,
}: {
  initials: string;
  size?: "xs" | "sm" | "md" | "lg";
  tone?: Tone;
  className?: string;
}) {
  const t = tone ?? palette[hash(initials) % palette.length];
  return (
    <span
      aria-hidden
      className={cn(
        "inline-grid shrink-0 place-items-center rounded-full font-semibold",
        tones[t],
        size === "xs" && "h-5 w-5 text-[9px]",
        size === "sm" && "h-7 w-7 text-[10px]",
        size === "md" && "h-9 w-9 text-xs",
        size === "lg" && "h-11 w-11 text-sm",
        className
      )}
    >
      {initials}
    </span>
  );
}

export function AvatarGroup({ names, max = 4 }: { names: string[]; max?: number }) {
  const shown = names.slice(0, max);
  const extra = names.length - shown.length;
  return (
    <span className="inline-flex items-center -space-x-2">
      {shown.map((n, i) => (
        <span key={n + i} className="rounded-full ring-2 ring-white">
          <Avatar initials={n} size="sm" />
        </span>
      ))}
      {extra > 0 && (
        <span className="inline-grid h-7 w-7 place-items-center rounded-full bg-slate-100 text-[10px] font-semibold text-slate-700 ring-2 ring-white">
          +{extra}
        </span>
      )}
    </span>
  );
}

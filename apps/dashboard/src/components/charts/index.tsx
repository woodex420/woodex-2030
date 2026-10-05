import { useId, useMemo, useState } from "react";
import type { MouseEvent } from "react";
import { cn } from "@/lib/cn";

export type ChartSeries = {
  name: string;
  color: string;
  values: number[];
  area?: boolean;
};

/**
 * Dependency-free line/area chart with grid, axes and hover tooltip.
 * Clean treatment per §8.1 — subtle grid, no decoration.
 */
export function LineAreaChart({
  labels,
  series,
  height = 236,
  valueFormat = (n: number) => String(n),
}: {
  labels: string[];
  series: ChartSeries[];
  height?: number;
  valueFormat?: (n: number) => string;
}) {
  const uid = useId().replace(/[^a-zA-Z0-9]/g, "");
  const W = 660;
  const H = 240;
  const pad = { l: 46, r: 12, t: 12, b: 26 };
  const [hover, setHover] = useState<number | null>(null);

  const primary = series[0];
  const max = useMemo(() => {
    const all = series.flatMap((s) => s.values);
    return Math.max(...all) * 1.12;
  }, [series]);

  const n = labels.length;
  const x = (i: number) => pad.l + (i * (W - pad.l - pad.r)) / Math.max(1, n - 1);
  const y = (v: number, s: ChartSeries) => {
    const smax = s === primary ? max : Math.max(...s.values) * 1.25;
    return pad.t + (1 - v / smax) * (H - pad.t - pad.b);
  };

  const linePath = (s: ChartSeries) =>
    s.values.map((v, i) => `${i === 0 ? "M" : "L"}${x(i).toFixed(1)},${y(v, s).toFixed(1)}`).join(" ");
  const areaPath = (s: ChartSeries) =>
    `${linePath(s)} L${x(n - 1).toFixed(1)},${H - pad.b} L${x(0).toFixed(1)},${H - pad.b} Z`;

  const gridLines = [0.25, 0.5, 0.75, 1].map((f) => ({
    y: pad.t + (1 - f) * (H - pad.t - pad.b),
    label: valueFormat(Math.round((f * max) / 1000) * 1000),
  }));

  const onMove = (e: MouseEvent<HTMLDivElement>) => {
    const rect = e.currentTarget.getBoundingClientRect();
    const px = ((e.clientX - rect.left) / rect.width) * W;
    const i = Math.round(((px - pad.l) / (W - pad.l - pad.r)) * (n - 1));
    setHover(Math.max(0, Math.min(n - 1, i)));
  };

  const hoverPctX = hover !== null ? (x(hover) / W) * 100 : 0;

  return (
    <div className="relative" onMouseMove={onMove} onMouseLeave={() => setHover(null)}>
      <svg viewBox={`0 0 ${W} ${H}`} className="block w-full" style={{ height }} role="img" aria-label="Trend chart">
        <defs>
          {series.map((s, si) => (
            <linearGradient key={si} id={`${uid}-g${si}`} x1="0" y1="0" x2="0" y2="1">
              <stop offset="0%" stopColor={s.color} stopOpacity="0.18" />
              <stop offset="100%" stopColor={s.color} stopOpacity="0.01" />
            </linearGradient>
          ))}
        </defs>

        {gridLines.map((g, i) => (
          <g key={i}>
            <line x1={pad.l} x2={W - pad.r} y1={g.y} y2={g.y} stroke="#E5E8EA" strokeWidth="1" />
            <text x={pad.l - 8} y={g.y + 3.5} textAnchor="end" fontSize="10" fill="#7B858D">
              {g.label}
            </text>
          </g>
        ))}
        <line x1={pad.l} x2={W - pad.r} y1={H - pad.b} y2={H - pad.b} stroke="#D2D7DA" strokeWidth="1" />

        {series.map((s, si) => (
          <g key={si}>
            {s.area && <path d={areaPath(s)} fill={`url(#${uid}-g${si})`} />}
            <path
              d={linePath(s)}
              fill="none"
              stroke={s.color}
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
              strokeDasharray={s.area ? undefined : "4 4"}
            />
            {s.values.map((v, i) => (
              <circle
                key={i}
                cx={x(i)}
                cy={y(v, s)}
                r={hover === i ? 4.5 : 2.5}
                fill="#fff"
                stroke={s.color}
                strokeWidth="2"
                className="transition-[r] duration-100"
                opacity={s.area || hover === i ? 1 : 0}
              />
            ))}
          </g>
        ))}

        {labels.map((l, i) =>
          n > 8 && i % 2 !== 0 && hover !== i ? null : (
            <text key={i} x={x(i)} y={H - 8} textAnchor="middle" fontSize="10" fill={hover === i ? "#1D2226" : "#7B858D"}>
              {l}
            </text>
          )
        )}

        {hover !== null && (
          <line x1={x(hover)} x2={x(hover)} y1={pad.t} y2={H - pad.b} stroke="#A2AAB0" strokeWidth="1" strokeDasharray="3 3" />
        )}
      </svg>

      {hover !== null && (
        <div
          className="pointer-events-none absolute top-1 z-10 min-w-36 -translate-x-1/2 rounded-control border border-line bg-surface px-3 py-2 shadow-pop"
          style={{ left: `clamp(90px, ${hoverPctX}%, calc(100% - 90px))` }}
        >
          <p className="mb-1 text-caption font-medium text-subtle">{labels[hover]}</p>
          {series.map((s) => (
            <p key={s.name} className="flex items-center justify-between gap-4 text-caption">
              <span className="inline-flex items-center gap-1.5 text-muted">
                <span className="h-1.5 w-1.5 rounded-full" style={{ background: s.color }} />
                {s.name}
              </span>
              <span className="font-semibold text-ink">{valueFormat(s.values[hover])}</span>
            </p>
          ))}
        </div>
      )}
    </div>
  );
}

/** Horizontal ranked bars — compact, token-driven. */
export function BarList({
  items,
  valueFormat = (n: number) => String(n),
  className,
}: {
  items: { label: string; value: number; pct: number; tone: string }[];
  valueFormat?: (n: number) => string;
  className?: string;
}) {
  const fill: Record<string, string> = {
    primary: "bg-primary-500",
    info: "bg-info",
    success: "bg-success",
    warning: "bg-warning",
    danger: "bg-danger",
    muted: "bg-slate-300",
    neutral: "bg-slate-500",
  };
  return (
    <ul className={cn("space-y-3.5", className)}>
      {items.map((it) => (
        <li key={it.label}>
          <div className="mb-1.5 flex items-baseline justify-between gap-3">
            <span className="text-small font-medium text-slate-700">{it.label}</span>
            <span className="text-caption text-subtle">
              <span className="mr-1.5 font-semibold text-ink">{valueFormat(it.value)}</span>
              {it.pct}%
            </span>
          </div>
          <div className="h-1.5 w-full overflow-hidden rounded-full bg-slate-100">
            <div
              className={cn("h-full rounded-full transition-[width] duration-500", fill[it.tone] ?? "bg-primary-500")}
              style={{ width: `${it.pct}%` }}
            />
          </div>
        </li>
      ))}
    </ul>
  );
}

const donutColors: Record<string, string> = {
  primary: "#4F9D21",
  info: "#3578B8",
  success: "#2E8B57",
  warning: "#D99A24",
  danger: "#D9534F",
  muted: "#D2D7DA",
  neutral: "#7B858D",
};

/** Donut with center metric. */
export function Donut({
  segments,
  centerValue,
  centerLabel,
  size = 168,
}: {
  segments: { label: string; value: number; pct: number; tone: string }[];
  centerValue: string;
  centerLabel: string;
  size?: number;
}) {
  const r = 62;
  const C = 2 * Math.PI * r;
  let acc = 0;
  return (
    <div className="flex flex-wrap items-center gap-6">
      <svg width={size} height={size} viewBox="0 0 168 168" role="img" aria-label="Distribution donut">
        <circle cx="84" cy="84" r={r} fill="none" stroke="#F1F3F4" strokeWidth="17" />
        {segments.map((s) => {
          const len = (s.pct / 100) * C - 3;
          const off = -acc;
          acc += (s.pct / 100) * C;
          return (
            <circle
              key={s.label}
              cx="84"
              cy="84"
              r={r}
              fill="none"
              stroke={donutColors[s.tone] ?? "#4F9D21"}
              strokeWidth="17"
              strokeLinecap="butt"
              strokeDasharray={`${Math.max(0, len)} ${C - Math.max(0, len)}`}
              strokeDashoffset={off}
              transform="rotate(-90 84 84)"
            />
          );
        })}
        <text x="84" y="80" textAnchor="middle" fontSize="22" fontWeight="700" fill="#1D2226">
          {centerValue}
        </text>
        <text x="84" y="98" textAnchor="middle" fontSize="10.5" fill="#7B858D">
          {centerLabel}
        </text>
      </svg>
      <ul className="min-w-32 flex-1 space-y-2">
        {segments.map((s) => (
          <li key={s.label} className="flex items-center justify-between gap-3 text-small">
            <span className="inline-flex items-center gap-2 text-slate-700">
              <span className="h-2 w-2 rounded-full" style={{ background: donutColors[s.tone] ?? "#4F9D21" }} />
              {s.label}
            </span>
            <span className="font-semibold text-ink">{s.pct}%</span>
          </li>
        ))}
      </ul>
    </div>
  );
}

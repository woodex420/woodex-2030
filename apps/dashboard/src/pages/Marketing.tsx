import { useMemo } from "react";
import { Link } from "react-router";
import { previewHref } from "@/lib/preview";
import { cn } from "@/lib/cn";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { StatusBadge } from "@/components/ui/Badge";
import { DataTable } from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import { SkeletonRows, EmptyState, ErrorState } from "@/components/ui/States";
import { useApi, useRealtime, timeAgo } from "@/lib/api";
import { Eye, FileText, Megaphone, RefreshCw, TrendingUp, Users, Zap } from "@/icons";

type Day = { date: string; views: number; ctas: number; leads: number };
type PageRow = { id: number; slug: string; title: string; status: string; views: number; ctas: number; uniques: number; leads: number };
type Stats = {
  days: number;
  totals: { views: number; ctas: number; unique: number; scroll50: number; ctr: number; leads: number; convPct: number };
  daily: Day[]; pages: PageRow[]; refs: { label: string; n: number }[];
  utms: { s: string; m: string | null; camp: string | null; c: number }[];
  feed: { slug: string; kind: string; label: string | null; created_at: string }[];
};

const half = (rows: Day[], key: "views" | "ctas" | "leads", mid: number) => rows.slice(mid).reduce((a, d) => a + d[key], 0);
function Delta({ cur, prev }: { cur: number; prev: number }) {
  if (!prev && !cur) return <span className="text-[10px] text-subtle">—</span>;
  const d = prev ? ((cur - prev) / prev) * 100 : cur > 0 ? 100 : 0;
  return (
    <span className={cn("text-[10px] font-bold", d >= 0 ? "text-primary-700" : "text-danger-strong")}>
      {d >= 0 ? "▲" : "▼"} {Math.abs(Math.round(d))}% <span className="font-normal text-subtle">vs prev 7d</span>
    </span>);
}

export default function Marketing() {
  const { data, loading, error, reload } = useApi<Stats>("/api/marketing/stats", 20000);
  useRealtime(/track|leads|pages/, () => reload());
  const daily = data?.daily ?? [];
  const mid = Math.max(0, daily.length - 7);
  const win = useMemo(() => {
    const v = half(daily, "views", mid), vv = half(daily, "views", 0) - v;
    const c = half(daily, "ctas", mid), cc = half(daily, "ctas", 0) - c;
    const l = half(daily, "leads", mid), ll = half(daily, "leads", 0) - l;
    return { v, vv, c, cc, l, ll };
  }, [daily, mid]);
  if (error) return <ErrorState title="Marketing stats unavailable" debug="GET /api/marketing/stats" onRetry={reload} />;

  const maxDay = Math.max(1, ...daily.map((d) => d.views));
  const tiles = [
    { label: "Page views", icon: <Eye size={14} />, v: data?.totals.views, delta: <Delta cur={win.v} prev={win.vv} /> },
    { label: "Unique visitors", icon: <Users size={14} />, v: data?.totals.unique, delta: <span className="text-[10px] text-subtle">first-party, no cookies</span> },
    { label: "CTA clicks", icon: <Zap size={14} />, v: data?.totals.ctas, delta: <Delta cur={win.c} prev={win.cc} /> },
    { label: "CTR", icon: <TrendingUp size={14} />, v: data ? (data.totals.ctr + "%") : undefined, delta: <span className="text-[10px] text-subtle">clicks ÷ views</span> },
    { label: "Landing leads", icon: <Megaphone size={14} />, v: data?.totals.leads, delta: <Delta cur={win.l} prev={win.ll} /> },
    { label: "View → lead", icon: <TrendingUp size={14} />, v: data ? (data.totals.convPct + "%") : undefined, delta: <span className="text-[10px] text-subtle">source: Landing: *</span> },
  ];
  const cols: Column<PageRow>[] = [
    { key: "p", header: "Page", cell: (r) => (
      <span className="flex items-center gap-2.5"><span className="grid h-8 w-8 place-items-center rounded-control bg-primary-50 text-primary-700"><FileText size={15} /></span>
        <span><Link to={`/website/edit/${r.id}`} className="block text-small font-semibold text-ink hover:underline">{r.title}</Link>
          <button type="button" onClick={() => void previewHref(r).then((u) => window.open(u, "_blank", "noopener"))} className="block text-caption text-subtle hover:text-primary-600">/p/{r.slug}</button></span></span>) },
    { key: "s", header: "Status", cell: (r) => <StatusBadge status={r.status} /> },
    { key: "v", header: "Views", align: "right", cell: (r) => <span className="font-semibold text-ink">{r.views}</span> },
    { key: "u", header: "Uniques", align: "right", cell: (r) => String(r.uniques) },
    { key: "c", header: "CTAs", align: "right", cell: (r) => <span className={cn(r.ctas > 0 && "font-semibold text-primary-700")}>{r.ctas}</span> },
    { key: "t", header: "CTR", align: "right", cell: (r) => <span className="text-caption">{r.views ? Math.round((r.ctas / r.views) * 100) : 0}%</span> },
    { key: "l", header: "Leads", align: "right", cell: (r) => <span className={cn("text-caption", r.leads > 0 && "font-bold text-primary-700")}>{r.leads}</span> },
  ];
  const refMax = Math.max(1, ...((data?.refs ?? []).map((x) => x.n)));

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Digital" }, { label: "Marketing" }]}
        title="Campaign analytics"
        description="First-party pixel on published landings — views, CTA taps, scroll depth, referrers and UTM mix, live over SSE. 14-day window shown."
        actions={<Button variant="secondary" onClick={reload}><RefreshCw size={14} /> Refresh</Button>}
      />
      {loading || !data ? (
        <SkeletonRows rows={6} />
      ) : (
        <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_320px]">
          <div className="space-y-4">
            <div className="grid grid-cols-2 gap-3 sm:grid-cols-3 lg:grid-cols-6">
              {tiles.map((t) => (
                <Card key={t.label} className="p-3.5">
                  <p className="flex items-center gap-1.5 text-[10px] font-bold uppercase tracking-wide text-subtle">{t.icon}{t.label}</p>
                  <p className="mt-1 text-h2 text-ink">{t.v ?? "—"}</p>
                  {t.delta}
                </Card>))}
            </div>

            <Card className="p-4">
              <p className="mb-3 text-caption font-bold uppercase tracking-wide text-subtle">Daily traffic <span className="font-normal normal-case">· <span className="text-primary-600">■</span> views · <span className="text-amber-500">■</span> CTA clicks · <span className="text-sky-500">●</span> leads</span></p>
              <div className="flex h-32 items-end gap-1">
                {daily.map((d) => (
                  <div key={d.date} className="group relative flex h-full flex-1 flex-col items-center justify-end gap-0.5" title={`${d.date} — ${d.views} views · ${d.ctas} CTAs · ${d.leads} leads`}>
                    <span className="flex w-full flex-1 items-end justify-center gap-[3px]">
                      <span className="w-1/3 rounded-t bg-primary-500/80 transition-colors group-hover:bg-primary-600" style={{ height: `${(d.views / maxDay) * 100}%` }} />
                      <span className="w-1/5 rounded-t bg-amber-400" style={{ height: `${(d.ctas / maxDay) * 100}%` }} />
                    </span>
                    <span className={cn("h-1.5 w-1.5 rounded-full", d.leads ? "bg-sky-500" : "bg-transparent")} />
                    <span className="text-[9px] text-subtle">{d.date.slice(8)}</span>
                  </div>))}
              </div>
            </Card>

            <Card>
              <p className="border-b border-hairline px-4 py-2.5 text-caption font-bold uppercase tracking-wide text-subtle">Landing pages</p>
              {(data.pages.length === 0) ? <div className="p-4"><EmptyState icon={<Megaphone size={18} />} title="No pages yet" description="Publish one from Website Studio — the pixel ships with every /p page automatically." /></div>
                : <DataTable rows={data.pages} columns={cols} perPage={8} />}
            </Card>
          </div>

          <div className="space-y-4">
            <Card className="p-4">
              <p className="mb-2 text-caption font-bold uppercase tracking-wide text-subtle">Referrers</p>
              {data.refs.length === 0 ? <p className="text-caption text-subtle">No visits recorded yet.</p> : (
                <ul className="space-y-1.5">{data.refs.map((r) => (
                  <li key={r.label} className="text-caption">
                    <span className="flex items-baseline justify-between"><span className="font-semibold text-ink">{r.label}</span><span className="text-subtle">{r.n}</span></span>
                    <span className="mt-0.5 block h-1 rounded-full bg-surface-secondary"><span className="block h-1 rounded-full bg-primary-500" style={{ width: `${(r.n / refMax) * 100}%` }} /></span>
                  </li>))}</ul>)}
            </Card>
            <Card className="p-4">
              <p className="mb-2 text-caption font-bold uppercase tracking-wide text-subtle">UTM campaigns</p>
              {data.utms.length === 0 ? <p className="text-caption text-subtle">Share links with <code className="rounded bg-surface-secondary px-1">?utm_source=…</code> — they group themselves here.</p> : (
                <ul className="space-y-1">{data.utms.map((u, i) => (
                  <li key={i} className="flex items-baseline justify-between rounded-control bg-surface-secondary px-2.5 py-1.5 text-caption">
                    <span><b className="text-ink">{u.s}</b>{u.camp ? <span className="text-subtle"> · {u.camp}</span> : null}{u.m ? <span className="text-subtle"> · {u.m}</span> : null}</span>
                    <span className="font-bold text-primary-700">{u.c}</span>
                  </li>))}</ul>)}
            </Card>
            <Card className="p-4">
              <p className="mb-2 flex items-center justify-between text-caption font-bold uppercase tracking-wide text-subtle">Live feed <span className="inline-flex items-center gap-1 text-[9px] font-semibold normal-case text-primary-700"><span className="h-1.5 w-1.5 animate-pulse rounded-full bg-primary-500" />SSE</span></p>
              {data.feed.length === 0 ? <p className="text-caption text-subtle">Quiet so far — events appear the moment a visitor lands.</p> : (
                <ul className="space-y-1">{data.feed.map((f, i) => (
                  <li key={i} className="flex items-center gap-1.5 text-caption">
                    <span className="w-4 text-center">{f.kind === "view" ? "👁" : f.kind === "cta" ? "⚡" : "↕"}</span>
                    <span className="min-w-0 flex-1 truncate"><b className="text-ink">{f.slug}</b>{f.label ? <span className="text-subtle"> · {f.label}</span> : null}</span>
                    <span className="shrink-0 text-[9px] text-subtle">{timeAgo(f.created_at)}</span>
                  </li>))}</ul>)}
            </Card>
            <Card className="p-4 text-caption text-muted">
              <p className="font-bold text-ink">Privacy posture</p>
              <p className="mt-1">Anonymous visitor id in localStorage — no cookies, no third-party scripts, no IPs. Referrer host is stored truncated; purge any time via <code className="rounded bg-surface-secondary px-1">DELETE /api/track</code>.</p>
            </Card>
          </div>
        </div>
      )}
    </div>
  );
}

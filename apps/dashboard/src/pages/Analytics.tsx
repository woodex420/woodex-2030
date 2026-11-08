import { useMemo, useState } from "react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Select } from "@/components/ui/Field";
import { TrendChip } from "@/components/ui/Badge";
import { DataTable } from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import { BarList, Donut, LineAreaChart } from "@/components/charts";
import { SkeletonRows, ErrorState } from "@/components/ui/States";
import { useApi, useRealtime } from "@/lib/api";
import { money } from "@/lib/cn";
import { Download } from "@/icons";

/* ---------- API shapes ---------- */
type RevenuePoint = { label: string; revenue: number; orders: number; collected: number };
type RevenueStats = { months: number; series: RevenuePoint[]; summary: { totalRevenue: number; totalOrders: number; collected: number; revenueChange: number; ordersChange: number } };
type Stats = {
  products: { total: number; inStock: number; avgPrice: number };
  leads: { total: number; byStatus: Record<string, number> };
  quotes: { total: number; byStatus: Record<string, { count: number; value: number }>; pipelineValue: number };
  orders: { total: number; byStage: Record<string, { count: number; value: number }> };
};
type MarketingDaily = { date: string; views: number; ctas: number; leads: number };
type MarketingStats = { days: number; totals: { views: number; ctas: number; unique: number; leads: number; ctr: number; convPct: number }; daily: MarketingDaily[]; pages?: { slug: string; title: string; views: number; ctas: number }[] };

const RANGES = [
  { label: "Last 90 days", days: 90, months: 3 },
  { label: "Last 6 months", days: 180, months: 6 },
  { label: "This year", days: 365, months: 12 },
];

const monthLabel = (iso: string) => iso.slice(5) + "/" + iso.slice(2, 4);

type LeadSourceRow = { label: string; value: number; pct: number; tone: "primary" | "info" | "warning" | "muted" | "success" };

export default function Analytics() {
  const [rangeLabel, setRangeLabel] = useState(RANGES[1].label);
  const range = RANGES.find((r) => r.label === rangeLabel) ?? RANGES[1];

  const revenue = useApi<RevenueStats>(`/api/stats/revenue?months=${range.months}`, 30000);
  const stats = useApi<Stats>("/api/stats", 30000);
  const marketing = useApi<MarketingStats>("/api/marketing/stats", 30000);

  /* A colleague's order/lead/quote change should move these numbers live. */
  useRealtime(/^(orders|leads|quotes|track)$/, () => {
    void revenue.reload();
    void stats.reload();
    void marketing.reload();
  });

  const loading = !revenue.data && !stats.data && !marketing.data;
  const failed = revenue.error && stats.error && marketing.error;

  const leadSources: LeadSourceRow[] = useMemo(() => {
    const by = stats.data?.leads?.byStatus ?? {};
    const entries = Object.entries(by).sort((a, b) => b[1] - a[1]);
    const total = entries.reduce((n, [, v]) => n + v, 0);
    const tones: LeadSourceRow["tone"][] = ["primary", "info", "warning", "success", "muted"];
    return entries.map(([label, value], i) => ({
      label,
      value,
      pct: total > 0 ? Math.round((value / total) * 1000) / 10 : 0,
      tone: tones[i % tones.length],
    }));
  }, [stats.data]);

  /* Campaign-style table, but every row is a real quote status bucket. */
  type QuoteRow = { id: string; bucket: string; count: number; value: number; avg: number };
  const quoteRows: QuoteRow[] = useMemo(() => {
    const by = stats.data?.quotes?.byStatus ?? {};
    return Object.entries(by).map(([bucket, v]) => ({
      id: "q-" + bucket,
      bucket,
      count: v.count,
      value: v.value,
      avg: v.count > 0 ? Math.round(v.value / v.count) : 0,
    }));
  }, [stats.data]);

  const quoteCols: Column<QuoteRow>[] = [
    { key: "bucket", header: "Quote status", cell: (r) => <span className="text-small font-semibold text-ink">{r.bucket}</span> },
    { key: "count", header: "Count", align: "right", cell: (r) => <span className="text-small text-slate-700">{r.count}</span> },
    { key: "avg", header: "Avg value", align: "right", cell: (r) => <span className="text-small text-slate-700">{money(r.avg)}</span> },
    { key: "value", header: "Total value", align: "right", cell: (r) => <span className="text-small font-semibold text-ink">{money(r.value)}</span> },
  ];

  const wonCount = stats.data?.leads?.byStatus?.Won ?? 0;
  const leadTotal = stats.data?.leads?.total ?? 0;
  const convRate = leadTotal > 0 ? Math.round((wonCount / leadTotal) * 1000) / 10 : 0;

  const pageRows = (marketing.data?.pages ?? []).slice(0, 6);

  if (loading) {
    return (
      <div>
        <PageHeader title="Analytics" description="Executive performance across revenue, leads, conversion and campaigns." />
        <Card><SkeletonRows rows={6} /></Card>
      </div>
    );
  }
  if (failed) {
    return (
      <div>
        <PageHeader title="Analytics" description="Executive performance across revenue, leads, conversion and campaigns." />
        <Card><ErrorState description="Could not load analytics." onRetry={() => { void revenue.reload(); void stats.reload(); void marketing.reload(); }} /></Card>
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        title="Analytics"
        description="Executive performance across revenue, leads, conversion and campaigns — all live from the database."
        actions={
          <>
            <Select className="h-10 w-40" value={rangeLabel} onChange={(e) => setRangeLabel(e.target.value)} aria-label="Date range">
              {RANGES.map((r) => <option key={r.label}>{r.label}</option>)}
            </Select>
            <Button variant="secondary" onClick={() => window.print()}>
              <Download size={15} /> Export report
            </Button>
          </>
        }
      />

      {/* Headline cards — every figure below comes from the API */}
      <section aria-label="Performance KPIs" className="mb-4 grid grid-cols-1 gap-3 md:grid-cols-3 lg:gap-4">
        <Card className="flex items-start justify-between gap-3">
          <div>
            <p className="text-small font-medium text-muted">Revenue</p>
            <p className="mt-1 text-h1 tracking-tight text-ink">{revenue.data ? money(revenue.data.summary.totalRevenue) : "—"}</p>
            <p className="mt-1 text-caption text-subtle">last {range.months} months · {revenue.data?.summary?.totalOrders ?? 0} orders</p>
          </div>
          {revenue.data ? <TrendChip value={revenue.data.summary.revenueChange} className="mt-1" /> : null}
        </Card>
        <Card className="flex items-start justify-between gap-3">
          <div>
            <p className="text-small font-medium text-muted">Leads</p>
            <p className="mt-1 text-h1 tracking-tight text-ink">{leadTotal.toLocaleString()}</p>
            <p className="mt-1 text-caption text-subtle">all sources · {stats.data?.leads?.byStatus?.New ?? 0} new</p>
          </div>
        </Card>
        <Card className="flex items-start justify-between gap-3">
          <div>
            <p className="text-small font-medium text-muted">Lead → Won</p>
            <p className="mt-1 text-h1 tracking-tight text-ink">{convRate}%</p>
            <p className="mt-1 text-caption text-subtle">{wonCount} won of {leadTotal} leads</p>
          </div>
        </Card>
      </section>

      {/* Trend + conversion */}
      <div className="mb-4 grid grid-cols-1 gap-3 xl:grid-cols-3 lg:gap-4">
        <Card className="xl:col-span-2">
          <CardHeader title="Revenue Trend" description={`Orders and payments, last ${range.months} months`} />
          {revenue.data?.series?.length ? (
            <LineAreaChart
              labels={revenue.data.series.map((p) => monthLabel(p.label))}
              series={[
                { name: "Revenue", color: "#4F9D21", values: revenue.data.series.map((p) => p.revenue), area: true },
                { name: "Collected", color: "#3578B8", values: revenue.data.series.map((p) => p.collected) },
              ]}
              height={220}
              valueFormat={(n) => "PKR " + Math.round(n).toLocaleString("en-PK")}
            />
          ) : (
            <div className="grid h-56 place-items-center text-caption text-subtle">No revenue data</div>
          )}
        </Card>
        <Card>
          <CardHeader title="Site Engagement" description={`Last ${marketing.data?.days ?? 14} days`} />
          {marketing.data ? (
            <>
              <div className="mb-3 grid grid-cols-2 gap-2">
                <div className="rounded-control bg-slate-50 px-3 py-2">
                  <p className="text-caption text-subtle">Page views</p>
                  <p className="text-h3 text-ink">{marketing.data.totals.views.toLocaleString()}</p>
                </div>
                <div className="rounded-control bg-slate-50 px-3 py-2">
                  <p className="text-caption text-subtle">CTAs</p>
                  <p className="text-h3 text-ink">{marketing.data.totals.ctas.toLocaleString()}</p>
                </div>
              </div>
              <LineAreaChart
                labels={(marketing.data.daily ?? []).map((d) => d.date.slice(5))}
                series={[{ name: "Views", color: "#2E8B57", values: (marketing.data.daily ?? []).map((d) => d.views), area: true }]}
                height={130}
                valueFormat={(n) => String(n)}
              />
              {marketing.data.totals.views === 0 && (
                <p className="mt-2 text-caption text-subtle">No traffic recorded yet — the pixel starts reporting once the storefront is public.</p>
              )}
            </>
          ) : (
            <div className="grid h-56 place-items-center text-caption text-subtle">No engagement data</div>
          )}
        </Card>
      </div>

      {/* Lead status + quote value */}
      <div className="grid grid-cols-1 gap-3 xl:grid-cols-5 lg:gap-4">
        <Card className="xl:col-span-2">
          <CardHeader title="Lead Pipeline" description="Current leads by stage" />
          {leadSources.length === 0 ? (
            <p className="py-8 text-center text-small text-muted">No leads captured yet.</p>
          ) : (
            <>
              <Donut
                segments={leadSources.map((s) => ({ label: s.label, value: s.value, pct: s.pct, tone: s.tone }))}
                centerValue={String(leadTotal)}
                centerLabel="leads"
              />
              <div className="mt-4 border-t border-line pt-4">
                <p className="mb-3 text-caption font-semibold tracking-wide text-subtle uppercase">Stage detail</p>
                <BarList
                  items={leadSources.map((s) => ({ label: s.label, value: s.value, pct: s.pct, tone: s.tone }))}
                  valueFormat={(n) => String(n)}
                />
              </div>
            </>
          )}
        </Card>
        <Card padded={false} className="min-w-0 xl:col-span-3">
          <div className="px-5 pt-5 lg:px-6 lg:pt-6">
            <CardHeader
              className="mb-1"
              title="Quotation Value"
              description="Open and closed quote value by status"
              action={<span className="text-caption text-subtle">Pipeline {money(stats.data?.quotes?.pipelineValue ?? 0)}</span>}
            />
          </div>
          {quoteRows.length === 0 ? (
            <p className="px-6 py-10 text-center text-small text-muted">No quotations yet.</p>
          ) : (
            <DataTable rows={quoteRows} columns={quoteCols} />
          )}
          {pageRows.length > 0 && (
            <div className="border-t border-line px-5 py-4 lg:px-6">
              <p className="mb-2 text-caption font-semibold tracking-wide text-subtle uppercase">Top landing pages</p>
              <ul className="space-y-1.5">
                {pageRows.map((p) => (
                  <li key={p.slug} className="flex items-center justify-between text-small">
                    <span className="truncate text-slate-700">/{p.slug}</span>
                    <span className="text-caption text-subtle">{p.views} views · {p.ctas} CTAs</span>
                  </li>
                ))}
              </ul>
            </div>
          )}
        </Card>
      </div>
    </div>
  );
}

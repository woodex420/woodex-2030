import { useState } from "react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Select } from "@/components/ui/Field";
import { StatusBadge, TrendChip } from "@/components/ui/Badge";
import { DataTable } from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import { BarList, Donut, LineAreaChart } from "@/components/charts";
import { campaigns, conversionTrend, leadSources, revenueSeries } from "@/data/mock";
import { compact, money } from "@/lib/cn";
import { Download } from "@/icons";

type Campaign = (typeof campaigns)[number];

const fmt = (n: number) => "Rs " + n.toLocaleString();

const campaignCols: Column<Campaign>[] = [
  {
    key: "name",
    header: "Campaign",
    cell: (r) => (
      <span>
        <span className="block text-small font-semibold text-ink">{r.name}</span>
        <span className="block text-caption text-subtle">{r.channel}</span>
      </span>
    ),
  },
  { key: "spend", header: "Spend", align: "right", cell: (r) => <span className="text-small text-slate-700">{fmt(r.spend)}</span> },
  { key: "leads", header: "Leads", align: "right", cell: (r) => <span className="text-small font-semibold text-ink">{r.leads}</span> },
  { key: "cpl", header: "CPL", align: "right", cell: (r) => <span className="text-small text-slate-700">{fmt(r.cpl)}</span> },
  { key: "conv", header: "Conv. rate", align: "right", cell: (r) => <span className="text-small font-semibold text-primary-700">{r.conv}%</span> },
  { key: "revenue", header: "Revenue", align: "right", cell: (r) => <span className="text-small font-semibold text-ink">{fmt(r.revenue)}</span> },
  { key: "status", header: "Status", cell: (r) => <StatusBadge status={r.status} /> },
];

export default function Analytics() {
  const [range, setRange] = useState("Last 6 months");
  return (
    <div>
      <PageHeader
        title="Analytics"
        description="Executive performance across revenue, leads, conversion and campaigns."
        actions={
          <>
            <Select className="h-10 w-40" value={range} onChange={(e) => setRange(e.target.value)} aria-label="Date range">
              {["Last 30 days", "Last 90 days", "Last 6 months", "This year"].map((r) => (
                <option key={r}>{r}</option>
              ))}
            </Select>
            <Button variant="secondary" onClick={() => window.print()}>
              <Download size={15} /> Export report
            </Button>
          </>
        }
      />

      {/* Headline cards */}
      <section aria-label="Performance KPIs" className="mb-4 grid grid-cols-1 gap-3 md:grid-cols-3 lg:gap-4">
        {[
          { label: "Total Revenue", value: money(4642000), trend: 18.2, note: `${range.toLowerCase()}` },
          { label: "Total Leads", value: "1,284", trend: 12.4, note: "all sources" },
          { label: "Conversion Rate", value: "9.6%", trend: 6.7, note: "lead → won quote" },
        ].map((c) => (
          <Card key={c.label} className="flex items-start justify-between gap-3">
            <div>
              <p className="text-small font-medium text-muted">{c.label}</p>
              <p className="mt-1 text-h1 tracking-tight text-ink">{c.value}</p>
              <p className="mt-1 text-caption text-subtle capitalize">{c.note}</p>
            </div>
            <TrendChip value={c.trend} className="mt-1" />
          </Card>
        ))}
      </section>

      {/* Trend + donut */}
      <div className="mb-4 grid grid-cols-1 gap-3 xl:grid-cols-3 lg:gap-4">
        <Card className="xl:col-span-2">
          <CardHeader title="Revenue Trend" description="Revenue vs. quotation win rate" />
          <LineAreaChart
            labels={revenueSeries.labels.slice(6)}
            series={[
              { name: "Revenue", color: "#4F9D21", values: revenueSeries.revenue.slice(6), area: true },
              { name: "Orders", color: "#3578B8", values: revenueSeries.orders.slice(6) },
            ]}
            height={220}
            valueFormat={(n) => "$" + compact(n)}
          />
        </Card>
        <Card>
          <CardHeader title="Conversion Trend" description="Lead-to-quote conversion, %" />
          <LineAreaChart
            labels={conversionTrend.labels}
            series={[{ name: "Conversion", color: "#2E8B57", values: conversionTrend.values, area: true }]}
            height={220}
            valueFormat={(n) => n.toFixed(1)}
          />
        </Card>
      </div>

      {/* Lead source + campaigns */}
      <div className="grid grid-cols-1 gap-3 xl:grid-cols-5 lg:gap-4">
        <Card className="xl:col-span-2">
          <CardHeader title="Lead Source" description="Volume share by acquisition channel" />
          <Donut
            segments={leadSources.map((s) => ({ label: s.label, value: s.value, pct: s.pct, tone: s.tone }))}
            centerValue="1,284"
            centerLabel="leads · 90d"
          />
          <div className="mt-4 border-t border-line pt-4">
            <p className="mb-3 text-caption font-semibold tracking-wide text-subtle uppercase">Channel detail</p>
            <BarList
              items={leadSources.map((s) => ({ label: s.label, value: s.value, pct: s.pct, tone: s.tone }))}
              valueFormat={(n) => String(n)}
            />
          </div>
        </Card>
        <Card padded={false} className="min-w-0 xl:col-span-3">
          <div className="px-5 pt-5 lg:px-6 lg:pt-6">
            <CardHeader
              className="mb-1"
              title="Campaign Performance"
              description="Spend, cost per lead and attributed revenue"
              action={<span className="text-caption text-subtle">ROAS 4.2x overall</span>}
            />
          </div>
          <DataTable rows={campaigns.map((c, i) => ({ ...c, id: "cmp-" + i }))} columns={campaignCols} />
        </Card>
      </div>
    </div>
  );
}

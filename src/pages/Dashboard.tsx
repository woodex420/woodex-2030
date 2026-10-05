import { Link } from "react-router";
import { cn } from "@/lib/cn";
import { Card, CardHeader } from "@/components/ui/Card";
import { DataTable } from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import { StatusBadge, TrendChip } from "@/components/ui/Badge";
import { Button } from "@/components/ui/Button";
import { LineAreaChart } from "@/components/charts";
import {
  ActivityFeed,
  KpiRow,
  OperationsStatusRow,
  QuotationPipelineCard,
  WorkspaceContextBar,
} from "@/components/dashboard";
import { compact, money } from "@/lib/cn";
import { recentLeads, revenueSeries, revenueSummary } from "@/data/mock";
import type { Lead } from "@/data/mock";
import { ArrowRight, Plus } from "@/icons";

type LeadRow = Lead & { id: string };

const leadColumns: Column<LeadRow>[] = [
  {
    key: "name",
    header: "Name",
    cell: (r) => (
      <span className="flex items-center gap-2.5">
        <span className="grid h-7 w-7 place-items-center rounded-full bg-slate-100 text-[10px] font-semibold text-slate-700">
          {r.name.split(" ").map((w) => w[0]).slice(0, 2).join("")}
        </span>
        <span>
          <span className="block text-small font-semibold text-ink">{r.name}</span>
          <span className="block text-caption text-subtle">{r.id}</span>
        </span>
      </span>
    ),
  },
  { key: "interest", header: "Interest", cell: (r) => <span className="text-small text-slate-700">{r.interest}</span> },
  { key: "source", header: "Source", cell: (r) => <span className="text-caption text-muted">{r.source}</span> },
  { key: "value", header: "Est. value", align: "right", cell: (r) => <span className="text-small font-semibold text-ink">{r.value}</span> },
  { key: "date", header: "Date", cell: (r) => <span className="text-caption text-muted">{r.date}</span> },
  { key: "status", header: "Status", cell: (r) => <StatusBadge status={r.status} /> },
];

export default function Dashboard() {
  return (
    <div className="space-y-5 lg:space-y-6">
      <WorkspaceContextBar />
      <KpiRow />

      <div className="grid grid-cols-1 gap-3 xl:grid-cols-3 lg:gap-4">
        {/* §8.1 Sales & Revenue Overview */}
        <Card className="xl:col-span-2">
          <CardHeader
            title="Sales & Revenue Overview"
            description="Revenue and order trend, last 12 months"
            action={
              <div className="flex items-center gap-2">
                <Link
                  to="/analytics"
                  className="inline-flex items-center gap-1 text-caption font-semibold text-primary-600 hover:text-primary-700"
                >
                  Full analytics <ArrowRight size={13} />
                </Link>
              </div>
            }
          />
          <div className="mb-4 grid grid-cols-2 gap-3 sm:max-w-md">
            <div className="rounded-card border border-line bg-slate-50/60 p-3.5">
              <p className="text-caption font-medium text-subtle">Total Revenue</p>
              <p className="mt-1 text-h2 tracking-tight text-ink">{money(revenueSummary.totalRevenue)}</p>
              <p className="mt-1 flex items-center gap-1.5 text-caption">
                <TrendChip value={revenueSummary.revenueChange} />
                <span className="text-subtle">vs. prior period</span>
              </p>
            </div>
            <div className="rounded-card border border-line bg-slate-50/60 p-3.5">
              <p className="text-caption font-medium text-subtle">Total Orders</p>
              <p className="mt-1 text-h2 tracking-tight text-ink">{revenueSummary.totalOrders}</p>
              <p className="mt-1 flex items-center gap-1.5 text-caption">
                <TrendChip value={revenueSummary.ordersChange} />
                <span className="text-subtle">vs. prior period</span>
              </p>
            </div>
          </div>
          <LineAreaChart
            labels={revenueSeries.labels}
            series={[
              { name: "Revenue", color: "#4F9D21", values: revenueSeries.revenue, area: true },
              { name: "Orders", color: "#3578B8", values: revenueSeries.orders },
            ]}
            valueFormat={(n) => "$" + compact(n)}
          />
          <div className="mt-2 flex items-center gap-4 border-t border-line pt-3 text-caption text-muted">
            <span className="inline-flex items-center gap-1.5">
              <span className="h-1.5 w-4 rounded-full bg-primary-500" /> Revenue
            </span>
            <span className="inline-flex items-center gap-1.5">
              <span className="h-1.5 w-4 rounded-full bg-info" /> Orders
            </span>
          </div>
        </Card>

        {/* §8.2 Quotation pipeline */}
        <QuotationPipelineCard />

        {/* §8.3 Recent leads */}
        <Card padded={false} className="xl:col-span-2">
          <div className="px-5 pt-5 lg:px-6 lg:pt-6">
            <CardHeader
              className="mb-1"
              title="Recent Leads"
              description="Newest inbound opportunities across all channels"
              action={
                <Link
                  to="/crm"
                  className="inline-flex items-center gap-1 text-caption font-semibold text-primary-600 hover:text-primary-700"
                >
                  Open CRM <ArrowRight size={13} />
                </Link>
              }
            />
          </div>
          <DataTable rows={recentLeads} columns={leadColumns} />
        </Card>

        {/* §8.5 Activity */}
        <ActivityFeed />
      </div>

      {/* §8.4 Production & delivery */}
      <OperationsStatusRow />

      {/* Quick actions rail */}
      <Card className={cn("flex flex-wrap items-center justify-between gap-3")}>
        <div className="flex items-center gap-3">
          <span className="grid h-10 w-10 place-items-center rounded-control bg-primary-100 text-primary-700">
            <Plus size={18} />
          </span>
          <div>
            <p className="text-bodylg font-semibold text-ink">Ready for the next opportunity?</p>
            <p className="text-caption text-muted">Draft a quotation or capture a lead in seconds.</p>
          </div>
        </div>
        <div className="flex flex-wrap gap-2">
          <Link to="/quotations/new" className="inline-flex">
            <Button variant="primary">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round" aria-hidden>
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z" />
                <path d="M14 2v6h6" />
              </svg>
              New E-Quotation
            </Button>
          </Link>
          <Link to="/crm">
            <Button variant="secondary">Add Lead</Button>
          </Link>
        </div>
      </Card>
    </div>
  );
}

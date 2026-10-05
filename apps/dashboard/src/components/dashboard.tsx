import { useState } from "react";
import { Link } from "react-router";
import { cn } from "@/lib/cn";
import { Card, CardHeader } from "@/components/ui/Card";
import { Progress } from "@/components/ui/Progress";
import { StatusBadge } from "@/components/ui/Badge";
import { Dropdown, DropdownDivider, DropdownItem, DropdownLabel } from "@/components/ui/Dropdown";
import { useToast } from "@/components/ui/Toast";
import { activity, opsStatus, pipeline } from "@/data/mock";
import type { Kpi, Tone } from "@/data/mock";
import { useApi, fmtPKR, timeAgo } from "@/lib/api";
import type { ApiStats } from "@/lib/api";
import {
  ArrowUpRight,
  Building,
  Calendar,
  CheckCircle,
  ChevronDown,
  ClipboardList,
  DollarSign,
  Factory,
  FileText,
  FolderKanban,
  Package,
  ShieldCheck,
  Truck,
  Users,
  ClipboardCheck,
  Send,
  Sparkles,
} from "@/icons";

/* ---------- Workspace context — §06 ---------- */

export function WorkspaceContextBar() {
  const [range, setRange] = useState("Last 7 days");
  const [workspace, setWorkspace] = useState("Woodex Furniture");
  const { push } = useToast();
  const chip =
    "inline-flex h-9 items-center gap-2 rounded-control border border-line-strong bg-white px-3 text-small font-medium text-slate-700 transition-colors duration-150 hover:border-slate-400 hover:bg-slate-50";

  return (
    <section aria-label="Workspace context" className="mb-5">
      <div className="flex flex-wrap items-end justify-between gap-x-6 gap-y-3">
        <div>
          <h2 className="text-h1">Good morning, Woodex</h2>
          <p className="mt-1 text-small text-muted">Here&apos;s what&apos;s happening with your business today.</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <span className={cn(chip, "hidden items-center gap-2 text-primary-700 lg:inline-flex")}>
            <Sparkles size={15} className="text-primary-600" />
            2 AI drafts awaiting review
            <button
              onClick={() => push({ tone: "info", title: "AI review queue", desc: "Approval-gated review arrives with the AI module (Phase 3)." })}
              className="font-semibold underline-offset-2 hover:underline"
            >
              Review
            </button>
          </span>
          <Dropdown
            align="right"
            panelClassName="w-56"
            trigger={() => (
              <button className={chip} aria-label="Switch workspace">
                <span className="grid h-5 w-5 place-items-center rounded bg-primary-500 text-[9px] font-bold text-white">W</span>
                {workspace}
                <ChevronDown size={14} className="text-subtle" />
              </button>
            )}
          >
            <DropdownLabel>Workspaces</DropdownLabel>
            {["Woodex Furniture", "Woodex Retail", "Demo — Alpha Homes"].map((w) => (
              <DropdownItem
                key={w}
                onClick={() => {
                  setWorkspace(w);
                  push({ tone: "primary", title: `Switched to ${w}`, desc: "Multi-tenant context applied to all modules." });
                }}
              >
                <span className={cn("text-primary-600", w !== workspace && "opacity-0")}>✓</span>
                {w}
              </DropdownItem>
            ))}
            <DropdownDivider />
            <DropdownItem onClick={() => push({ tone: "info", title: "Add workspace (Phase 4)" })}>+ New workspace</DropdownItem>
          </Dropdown>
          <a
            href="https://woodexinteriors.com"
            target="_blank"
            rel="noreferrer"
            className={chip}
            onClick={(e) => {
              e.preventDefault();
              push({ tone: "info", title: "Opening woodexinteriors.com (demo link)" });
            }}
          >
            Website: woodexinteriors.com
            <ArrowUpRight size={14} className="text-subtle" />
          </a>
          <Dropdown
            align="right"
            panelClassName="w-44"
            trigger={() => (
              <button className={chip} aria-label="Select date range">
                <Calendar size={15} className="text-subtle" />
                {range}
                <ChevronDown size={14} className="text-subtle" />
              </button>
            )}
          >
            {["Today", "Last 7 days", "Last 30 days", "This quarter", "This year"].map((r) => (
              <DropdownItem key={r} onClick={() => setRange(r)}>
                <span className={cn("text-primary-600", r !== range && "opacity-0")}>✓</span>
                {r}
              </DropdownItem>
            ))}
          </Dropdown>
        </div>
      </div>
    </section>
  );
}

/* ---------- KPI cards — §7.2 ---------- */

const kpiIcons: Record<Kpi["icon"], typeof Users> = {
  users: Users,
  clipboard: ClipboardList,
  dollar: DollarSign,
  building: Building,
  factory: Factory,
  invoice: FileText,
};

const kpiTile: Record<Tone, string> = {
  info: "bg-info-soft text-info-strong",
  primary: "bg-primary-100 text-primary-700",
  success: "bg-success-soft text-success-strong",
  warning: "bg-warning-soft text-warning-strong",
  danger: "bg-danger-soft text-danger-strong",
  neutral: "bg-slate-100 text-slate-700",
  muted: "bg-slate-100 text-subtle",
};

export function KpiRow() {
  const { data: stats } = useApi<ApiStats>("/api/stats", 60000);
  const f = stats?.finance;
  const live: Kpi[] = [
    { id: "target", label: "Target hit", value: f ? f.target.pct + "%" : "—", trend: f && f.target.pct >= 50 ? 5.2 : -2.1, comparison: f ? fmtPKR(f.target.value) + " goal" : "set via /api/target", icon: "dollar", tone: f && f.target.pct >= 60 ? "success" : "warning" },
    { id: "revenue", label: "Revenue (delivered)", value: stats ? fmtPKR(stats.orders.revenue) : "—", trend: 24, comparison: "orders past dispatch", icon: "dollar", tone: "success" },
    { id: "deals", label: "Deals won / open", value: f ? f.deals.won + " / " + f.deals.open : "—", trend: 6.1, comparison: "quotes · win rate " + (f && f.deals.won + f.deals.open ? Math.round((f.deals.won / Math.max(1, f.deals.won + f.deals.open)) * 100) : 0) + "%", icon: "clipboard", tone: "primary" },
    { id: "leads", label: "Leads", value: String(stats?.leads.total ?? "—"), trend: 12.4, comparison: stats ? `${stats.leads.byStatus.New ?? 0} new right now` : "from shared backend", icon: "users", tone: "info" },
    { id: "aov", label: "Avg order value", value: f && f.aov ? fmtPKR(f.aov) : "—", trend: 3.4, comparison: stats ? `${stats.orders.total} orders` : "", icon: "invoice", tone: "info" },
    { id: "returns", label: "Open returns", value: stats ? String(stats.returns.open) : "—", trend: stats && stats.returns.open === 0 ? 4 : -4, comparison: stats ? `${stats.returns.total} RMA total` : "", icon: "factory", tone: stats && stats.returns.open > 0 ? "danger" : "success" },
  ];
  return (
    <section aria-label="Key performance indicators" className="grid grid-cols-2 gap-3 md:grid-cols-3 lg:gap-4 xl:grid-cols-6">
      {live.map((k) => {
        const Icon = kpiIcons[k.icon];
        const up = k.trend >= 0;
        return (
          <Card key={k.id} className="p-4 lg:p-5" padded={false}>
            <div className="flex items-start justify-between gap-2">
              <span className={cn("grid h-9 w-9 place-items-center rounded-control", kpiTile[k.tone])}>
                <Icon size={18} />
              </span>
              {k.id === "target" && f && <Progress value={f.target.pct} size="sm" tone={f.target.pct >= 60 ? "success" : "warning"} className="mt-1 w-16" />}
            </div>
            <p className="mt-3 text-small font-medium text-muted">{k.label}</p>
            <p className="mt-0.5 text-h1 tracking-tight text-ink">{k.value}</p>
            <p className="mt-1.5 flex items-center gap-1.5 text-caption">
              <span className={cn("inline-flex items-center gap-0.5 font-semibold", up ? "text-success-strong" : "text-danger-strong")}>
                {up ? "↑" : "↓"} {Math.abs(k.trend).toFixed(1)}%
              </span>
              <span className="text-subtle">{k.comparison}</span>
            </p>
          </Card>
        );
      })}
    </section>
  );
}

/* ---------- Vireo-spec home cards (P3) ---------- */

export function TopProductsCard() {
  const { data: stats } = useApi<ApiStats>("/api/stats", 60000);
  const items = (stats?.finance.topProducts ?? []).slice(0, 5);
  const max = Math.max(1, ...items.map((i) => i.value));
  return (
    <Card padded={false} className="flex h-full flex-col">
      <div className="px-5 pt-5"><CardHeader className="mb-2" title="Top selling products" description="Aggregated from live orders on the workshop board" /></div>
      <ul className="flex-1 divide-y divide-line px-5 pb-4">
        {items.length === 0 && <li className="py-6 text-center text-caption text-subtle">No orders on the board yet.</li>}
        {items.map((it, i) => (
          <li key={it.name} className="flex items-center gap-3 py-2.5">
            <span className="grid h-6 w-6 shrink-0 place-items-center rounded-full bg-primary-50 text-[10px] font-bold text-primary-700">{i + 1}</span>
            <span className="min-w-0 flex-1">
              <span className="block truncate text-small font-semibold text-ink">{it.name}</span>
              <span className="mt-1 block h-1.5 w-full overflow-hidden rounded-full bg-slate-100"><span className="block h-full rounded-full bg-primary-500" style={{ width: Math.round((it.value / max) * 100) + "%" }} /></span>
            </span>
            <span className="shrink-0 text-right"><span className="block text-small font-semibold text-ink">{fmtPKR(it.value)}</span><span className="text-caption text-subtle">{it.qty} sold</span></span>
          </li>
        ))}
      </ul>
    </Card>
  );
}

export function SourcesCard() {
  const { data: stats } = useApi<ApiStats>("/api/stats", 60000);
  const rows = stats?.finance.sources ?? [];
  return (
    <Card padded={false}>
      <div className="px-5 pt-5"><CardHeader className="mb-2" title="Lead sources" description="Traffic mix from the CRM intake" action={<Link to="/crm" className="text-caption font-semibold text-primary-600 hover:underline">CRM <ArrowUpRight size={12} className="inline" /></Link>} /></div>
      <ul className="space-y-2 px-5 pb-5">
        {rows.length === 0 && <li className="text-caption text-subtle">No leads yet.</li>}
        {rows.map((r) => (
          <li key={r.name} className="grid grid-cols-[110px_minmax(0,1fr)_48px] items-center gap-2 text-caption">
            <span className="truncate font-medium text-slate-700">{r.name}</span>
            <Progress value={r.pct} size="sm" tone="primary" />
            <span className="text-right font-semibold text-ink">{r.count}</span>
          </li>
        ))}
      </ul>
    </Card>
  );
}

export function PaymentsStrip() {
  const { data: stats } = useApi<ApiStats>("/api/stats", 60000);
  const pays = stats?.finance.payments ?? [];
  return (
    <Card className="xl:col-span-2">
      <CardHeader className="mb-3" title="Recent payments" description="Money in — live from the finance ledger" action={<Link to="/invoices" className="text-caption font-semibold text-primary-600 hover:underline">All invoices <ArrowUpRight size={12} className="inline" /></Link>} />
      {pays.length === 0 ? <p className="py-4 text-center text-caption text-subtle">No payments recorded yet — record one on any open invoice.</p> : (
        <ul className="divide-y divide-line">
          {pays.map((pm, i) => (
            <li key={i} className="flex items-center justify-between gap-3 py-2 text-small">
              <span className="min-w-0 truncate"><b className="text-ink">{pm.inv}</b> <span className="text-muted">· {pm.method}{pm.reference ? " · " + pm.reference : ""}</span></span>
              <span className="shrink-0 font-semibold text-success-strong">+{fmtPKR(pm.amount)}</span>
              <span className="w-16 shrink-0 text-right text-caption text-subtle">{timeAgo(pm.created_at)}</span>
            </li>
          ))}
        </ul>
      )}
    </Card>
  );
}

/* ---------- Quotation pipeline — §8.2 ---------- */

export function QuotationPipelineCard() {
  const { data: stats } = useApi<ApiStats>("/api/stats", 60000);
  const won = stats?.finance.deals.won ?? 0, open = stats?.finance.deals.open ?? 0;
  const win = won + open > 0 ? Math.round((won / (won + open)) * 100) : 17;
  return (
    <Card className="flex h-full flex-col">
      <CardHeader
        title="Quotation Pipeline"
        description="Approval-gated stages — counts live from the shared DB"
        action={
          <Link to="/quotations" className="text-caption font-semibold text-primary-600 hover:text-primary-700">
            View all
          </Link>
        }
      />
      <ul className="flex-1 space-y-3">
        {pipeline.map((s, i) => (
          <li key={s.stage} className="grid grid-cols-[92px_minmax(0,1fr)_64px] items-center gap-3">
            <span className="text-small font-medium text-slate-700">{s.stage}</span>
            <Progress value={s.pct} size="md" tone={i === 0 ? "muted" : i === 1 ? "info" : i >= 4 ? "success" : "primary"} />
            <span className="text-right text-small">
              <span className="font-semibold text-ink">{s.count}</span>
              <span className="ml-1 text-caption text-subtle">{s.pct}%</span>
            </span>
          </li>
        ))}
      </ul>
      <div className="mt-4 flex items-center justify-between border-t border-line pt-3 text-caption text-muted">
        <span>Win rate <strong className="font-semibold text-ink">{win}%</strong></span>
        <span>Avg cycle <strong className="font-semibold text-ink">11 days</strong></span>
        <span>Avg value <strong className="font-semibold text-ink">$PKR pipeline</strong></span>
      </div>
    </Card>
  );
}

/* ---------- Production & delivery — §8.4 ---------- */

const opsIcons: Record<string, typeof Users> = {
  factory: Factory,
  qc: ClipboardCheck,
  dispatch: Package,
  delivery: Truck,
};

export function OpsStatusCard({ item }: { item: (typeof opsStatus)[number] }) {
  const Icon = opsIcons[item.icon] ?? Package;
  const tone: Tone = item.status === "Delayed" ? "danger" : item.status === "On Track" ? "success" : "info";
  return (
    <Card>
      <div className="flex items-start justify-between gap-2">
        <span className="grid h-9 w-9 place-items-center rounded-control bg-slate-100 text-slate-700">
          <Icon size={18} />
        </span>
        <StatusBadge status={item.status} />
      </div>
      <p className="mt-3 text-small font-medium text-muted">{item.label}</p>
      <p className="mt-0.5 text-h1 tracking-tight text-ink">{item.total}</p>
      <p className="mt-1.5 flex items-center gap-3 text-caption">
        <span className="inline-flex items-center gap-1 text-success-strong">
          <CheckCircle size={13} /> {item.done} completed
        </span>
        <span className={cn("inline-flex items-center gap-1", item.delayed > 0 ? "text-danger-strong" : "text-subtle")}>
          <ShieldCheck size={13} /> {item.delayed} delayed
        </span>
      </p>
      <Progress value={item.pct} tone={tone} className="mt-3.5" />
    </Card>
  );
}

export function OperationsStatusRow() {
  return (
    <section aria-label="Production and delivery status">
      <div className="mb-3 flex items-center justify-between">
        <h2 className="text-h2">Production &amp; Delivery</h2>
        <Link to="/operations/production" className="inline-flex items-center gap-1 text-caption font-semibold text-primary-600 hover:text-primary-700">
          Open operations board <ArrowUpRight size={13} />
        </Link>
      </div>
      <div className="grid grid-cols-1 gap-3 md:grid-cols-2 xl:grid-cols-4 lg:gap-4">
        {opsStatus.map((o) => (
          <OpsStatusCard key={o.label} item={o} />
        ))}
      </div>
    </section>
  );
}

/* ---------- Activity feed — §8.5 ---------- */

const activityIcons: Record<string, { icon: typeof Users; tile: string }> = {
  lead: { icon: Users, tile: "bg-info-soft text-info-strong" },
  quote: { icon: ClipboardList, tile: "bg-primary-100 text-primary-700" },
  production: { icon: Factory, tile: "bg-warning-soft text-warning-strong" },
  delivery: { icon: Truck, tile: "bg-success-soft text-success-strong" },
  project: { icon: FolderKanban, tile: "bg-info-soft text-info-strong" },
  approval: { icon: ShieldCheck, tile: "bg-warning-soft text-warning-strong" },
};

export function ActivityFeed() {
  const { data: stats } = useApi<ApiStats>("/api/stats", 30000);
  const live = (stats?.recent ?? []).map((r, i) => ({
    id: "live-" + i,
    type: r.type,
    title: r.title,
    who: r.who,
    context: r.context,
    time: r.time ? timeAgo(r.time) : "just now",
  }));
  const feed = [...live, ...activity.map((a) => ({ id: "m" + a.id, type: a.type, title: a.title, who: a.who, context: a.context, time: a.time }))].slice(0, 7);
  return (
    <Card className="flex h-full flex-col" padded={false}>
      <div className="px-5 pt-5 lg:px-6 lg:pt-6">
        <CardHeader
          className="mb-3"
          title="Recent Activity"
          description="Live events from storefront + operations"
          action={
            <span className="inline-flex h-6 items-center gap-1 rounded-full bg-primary-50 px-2 text-caption font-medium text-primary-700">
              <Send size={11} /> live
            </span>
          }
        />
      </div>
      <ul className="scrollbar-slim min-h-0 flex-1 overflow-y-auto px-5 pb-5 lg:px-6 lg:pb-6">
        {feed.map((a, i) => {
          const meta = activityIcons[a.type] ?? activityIcons.lead;
          const Icon = meta.icon;
          const last = i === feed.length - 1;
          return (
            <li key={a.id} className="relative flex gap-3 pb-4 last:pb-0">
              <span className={cn("z-10 mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-full", meta.tile)}>
                <Icon size={15} />
              </span>
              {!last && <span className="absolute top-9 bottom-0 left-4 w-px bg-slate-100" aria-hidden />}
              <span className="min-w-0 flex-1">
                <span className="flex items-baseline justify-between gap-2">
                  <span className="text-small font-semibold text-ink">{a.title}</span>
                  <span className="shrink-0 text-caption text-subtle">{a.time}</span>
                </span>
                <span className="mt-0.5 block truncate text-caption text-muted">
                  <span className="font-medium text-slate-600">{a.who}</span> · {a.context}
                </span>
              </span>
            </li>
          );
        })}
      </ul>
    </Card>
  );
}

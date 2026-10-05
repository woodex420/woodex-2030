import { useMemo, useState } from "react";
import { useNavigate, useParams } from "react-router";
import { cn } from "@/lib/cn";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { StatusBadge, Badge } from "@/components/ui/Badge";
import { Avatar } from "@/components/ui/Avatar";
import { Progress } from "@/components/ui/Progress";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui/States";
import { useToast } from "@/components/ui/Toast";
import { useApi, mutate, fmtPKR, type ApiOrder } from "@/lib/api";
import { AlertTriangle, ArrowRight, ClipboardCheck, Download, Factory, ShieldCheck, Store, Truck, Wrench } from "@/icons";

const STAGES = [
  { key: "production", label: "Production", icon: Factory },
  { key: "qc", label: "Quality Check", icon: ClipboardCheck },
  { key: "dispatch", label: "Dispatch", icon: Truck },
  { key: "delivery", label: "Delivery", icon: Store },
  { key: "installation", label: "Installation", icon: Wrench },
];

const initials = (name: string) => name.split(" ").map((w) => w[0]).slice(0, 2).join("").toUpperCase();

export default function Operations() {
  const { stage } = useParams();
  const navigate = useNavigate();
  const { push } = useToast();
  const active = STAGES.some((s) => s.key === stage) ? (stage as string) : "board";
  const { data, error, loading, reload } = useApi<{ items: ApiOrder[] }>("/api/orders", 15000);
  const [onlyExceptions, setOnlyExceptions] = useState(false);

  const orders = data?.items ?? [];
  const byStage = useMemo(() => {
    const m: Record<string, ApiOrder[]> = Object.fromEntries(STAGES.map((s) => [s.key, []]));
    orders.forEach((o) => (m[o.stage] ?? m.production).push(o));
    return m;
  }, [orders]);
  const exceptions = orders.filter((o) => o.status === "Delayed" || o.status === "On Hold").length;

  const advance = async (order: ApiOrder) => {
    const idx = STAGES.findIndex((s) => s.key === order.stage);
    const next = STAGES[idx + 1];
    try {
      if (!next) {
        await mutate("/api/orders/" + order.id, { status: "Completed", stage: order.stage });
        push({ tone: "success", title: `${order.ref} completed`, desc: "Customer sign-off archived to history." });
      } else {
        await mutate("/api/orders/" + order.id, { stage: next.key, status: "In Progress" });
        push({ tone: "primary", title: `${order.ref} → ${next.label}`, desc: `${order.customer} — storefront status pills reflect this live.` });
      }
      reload();
    } catch (e) {
      push({ tone: "danger", title: "Could not advance order", desc: e instanceof Error ? e.message : String(e) });
    }
  };

  if (error)
    return (
      <div>
        <PageHeader title="Operations" />
        <ErrorState title="Orders API unreachable" description="Start the shared backend with npm run dev:api" debug={error} onRetry={reload} />
      </div>
    );

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Delivery", to: "/projects" }, { label: "Operations" }]}
        title="Operations — Production to Installation"
        description="Live orders from storefront checkout and seeded operations — advance stages, everything persists."
        actions={
          <>
            <Button variant={onlyExceptions ? "primary" : "secondary"} onClick={() => setOnlyExceptions((v) => !v)}>
              <AlertTriangle size={15} /> {exceptions} Exceptions
            </Button>
            <Button variant="secondary" onClick={() => push({ tone: "info", title: "ops-export-week41.csv generated" })}>
              <Download size={15} /> Export
            </Button>
          </>
        }
      />

      {/* Stage flow strip */}
      <Card className="mb-4 flex flex-wrap items-center gap-2 py-4">
        {STAGES.map((s, i) => {
          const Icon = s.icon;
          const exc = (byStage[s.key] ?? []).filter((o) => o.status === "Delayed" || o.status === "On Hold").length;
          return (
            <div key={s.key} className="flex items-center gap-2">
              <button
                onClick={() => navigate("/operations/" + s.key)}
                className={cn(
                  "inline-flex h-10 items-center gap-2 rounded-control border px-3.5 text-small font-medium transition-colors duration-150",
                  active === s.key ? "border-primary-500 bg-primary-50 text-primary-700" : "border-line-strong bg-white text-slate-700 hover:border-slate-400"
                )}
              >
                <Icon size={16} className={active === s.key ? "text-primary-600" : "text-subtle"} />
                {s.label}
                <span className={cn("rounded-full px-1.5 text-caption", active === s.key ? "bg-primary-500 text-white" : "bg-slate-100 text-slate-600")}>
                  {(byStage[s.key] ?? []).length}
                </span>
                {exc > 0 && <ShieldCheck size={14} className="text-danger" aria-label={`${exc} exceptions`} />}
              </button>
              {i < STAGES.length - 1 && <ArrowRight size={14} className="text-slate-300" />}
            </div>
          );
        })}
        <button
          onClick={() => navigate("/operations")}
          className={cn("ml-auto h-10 rounded-control px-3.5 text-caption font-semibold transition-colors", active === "board" ? "bg-charcoal-900 text-white" : "text-muted hover:text-ink")}
        >
          View full board
        </button>
      </Card>

      <p className="mb-3 flex items-center gap-2 text-caption text-subtle" aria-live="polite">
        {loading ? "syncing…" : `${orders.length} live orders · ${exceptions} flagged`}
        <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-success" aria-hidden />
        checkout an order in the storefront (:5174) and it appears here
      </p>

      <div className="scrollbar-slim -mx-4 flex gap-3 overflow-x-auto px-4 pb-2 lg:-mx-6 lg:px-6">
        {STAGES.filter((s) => active === "board" || s.key === active).map((s) => {
          const all = byStage[s.key] ?? [];
          const list = all.filter((o) => !onlyExceptions || o.status === "Delayed" || o.status === "On Hold");
          const done = all.filter((o) => o.status === "Completed").length;
          return (
            <section key={s.key} aria-label={`${s.label} queue`} className={cn("w-full shrink-0 rounded-panel border border-line bg-slate-100/70 lg:w-[300px]", active === s.key && "border-primary-500/60 ring-2 ring-primary-500/15")}>
              <header className="flex items-center gap-2.5 px-4 pt-3.5 pb-2">
                <span className="grid h-8 w-8 place-items-center rounded-control bg-white text-slate-700 shadow-card">
                  <s.icon size={16} />
                </span>
                <h2 className="text-small font-semibold text-ink">{s.label}</h2>
                <span className="ml-auto text-caption font-semibold text-subtle">{list.length}/{all.length}</span>
              </header>
              {all.length > 0 && <div className="px-4 pb-2"><Progress value={(done / all.length) * 100} size="sm" tone="primary" /></div>}
              <ul className="min-h-[120px] space-y-2 p-2.5">
                {loading && all.length === 0 && <li className="space-y-2">{[0, 1].map((i) => <Skeleton key={i} className="h-24" />)}</li>}
                {list.map((o) => {
                  const item = o.items[0] as { name?: string } | undefined;
                  const exc = o.status === "Delayed" || o.status === "On Hold";
                  return (
                    <li key={o.id} className="rounded-card border border-line bg-white p-3.5 shadow-card transition-shadow duration-150 hover:shadow-pop">
                      <div className="flex items-start justify-between gap-2">
                        <span className="font-mono text-caption text-subtle">{o.ref}</span>
                        <StatusBadge status={o.status} />
                      </div>
                      <p className="mt-1.5 line-clamp-1 text-small font-semibold text-ink">{item?.name ?? "Order"}</p>
                      <p className="text-caption text-muted">{o.customer} · {fmtPKR(o.total)}</p>
                      {exc && (
                        <p className="mt-2 flex items-start gap-1.5 rounded border border-danger/25 bg-danger-soft px-2 py-1.5 text-caption text-danger-strong">
                          <AlertTriangle size={13} className="mt-px shrink-0" />
                          {o.status === "On Hold" ? "Awaiting customer confirmation" : "SLA risk — review scheduling"}
                        </p>
                      )}
                      <div className="mt-3 flex items-center justify-between border-t border-slate-100 pt-2.5">
                        <span className="flex items-center gap-1.5 text-caption text-muted">
                          <Avatar initials={initials(o.owner)} size="xs" /> {o.owner}
                        </span>
                        <span className="text-caption text-subtle">Due {o.due}</span>
                      </div>
                      <div className="mt-2.5 flex gap-1.5">
                        <Button size="sm" className="flex-1" onClick={() => advance(o)}>
                          {s.key === "installation" ? "Complete" : "Move to " + STAGES[STAGES.findIndex((x) => x.key === s.key) + 1]?.label}
                        </Button>
                        <Button variant="secondary" size="sm" aria-label={"Actions for " + o.ref} onClick={() => push({ tone: "info", title: o.ref + " · " + o.customer, desc: "Assign / reschedule — full ops tooling lands in Phase 4." })}>⋯</Button>
                      </div>
                    </li>
                  );
                })}
                {!loading && list.length === 0 && (
                  <li className="rounded-card border border-dashed border-slate-300 py-8 text-center text-caption text-subtle">
                    {onlyExceptions ? "No exceptions in this stage ✓" : (
                      <>
                        Queue clear —
                        <span className="mt-2 block">
                          <a href="http://localhost:5174/cart" className="font-semibold text-primary-600">checkout in storefront</a> to create one
                        </span>
                      </>
                    )}
                  </li>
                )}
              </ul>
            </section>
          );
        })}
      </div>

      {!loading && orders.length === 0 && (
        <Card className="mt-2">
          <EmptyState icon={<Truck size={20} />} title="No orders yet" description="Complete a checkout in the storefront or seed demo orders — the board updates in ~15s." action={<Button onClick={reload}>Refresh</Button>} />
        </Card>
      )}

      <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3 lg:gap-4">
        <Card className="py-4">
          <p className="text-caption font-medium text-subtle uppercase">On-time completion</p>
          <p className="mt-1 text-h2 text-ink">91.4%</p>
          <p className="mt-1 text-caption text-success-strong">↑ 3.1 pts vs. last month</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption font-medium text-subtle uppercase">Live order value</p>
          <p className="mt-1 text-h2 text-ink">{fmtPKR(orders.reduce((n, o) => n + (o.total || 0), 0))}</p>
          <p className="mt-1 flex items-center gap-1.5 text-caption"><Badge tone="primary" dot={false}>from API</Badge><span className="text-subtle">{orders.length} open orders</span></p>
        </Card>
        <Card className="py-4">
          <p className="text-caption font-medium text-subtle uppercase">QC failure rate</p>
          <p className="mt-1 text-h2 text-ink">1.8%</p>
          <p className="mt-1 text-caption text-danger-strong">↓ 0.4 pts — rework trending down</p>
        </Card>
      </div>
    </div>
  );
}

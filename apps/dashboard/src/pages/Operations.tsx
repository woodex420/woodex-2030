import { useState } from "react";
import { useNavigate, useParams } from "react-router";
import { cn } from "@/lib/cn";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { StatusBadge, Badge } from "@/components/ui/Badge";
import { Avatar } from "@/components/ui/Avatar";
import { useToast } from "@/components/ui/Toast";
import { opStages } from "@/data/mock";
import type { OpOrder } from "@/data/mock";
import { AlertTriangle, ArrowRight, ClipboardCheck, Download, Factory, ShieldCheck, Truck, Wrench } from "@/icons";

const laneIcon: Record<string, typeof Factory> = {
  production: Factory,
  qc: ClipboardCheck,
  dispatch: Truck,
  delivery: Truck,
  installation: Wrench,
};

function initials(name: string) {
  return name.split(" ").map((w) => w[0]).slice(0, 2).join("").toUpperCase();
}

export default function Operations() {
  const { stage } = useParams();
  const navigate = useNavigate();
  const active = opStages.some((s) => s.key === stage) ? (stage as string) : "board";
  const { push } = useToast();
  const [orders, setOrders] = useState(opStages);
  const [onlyExceptions, setOnlyExceptions] = useState(false);

  const totalOrders = orders.reduce((n, s) => n + s.orders.length, 0);
  const exceptions = orders.reduce((n, s) => n + s.orders.filter((o) => o.exception).length, 0);

  const advance = (fromKey: string, order: OpOrder) => {
    const idx = orders.findIndex((s) => s.key === fromKey);
    const next = orders[idx + 1];
    if (!next) {
      push({ tone: "success", title: `${order.id} completed`, desc: "Order archived to history with sign-off." });
      setOrders((os) => os.map((s) => (s.key === fromKey ? { ...s, orders: s.orders.filter((o) => o.id !== order.id) } : s)));
      return;
    }
    setOrders((os) =>
      os.map((s) =>
        s.key === fromKey
          ? { ...s, orders: s.orders.filter((o) => o.id !== order.id) }
          : s.key === next.key
            ? { ...s, orders: [{ ...order, status: "In Progress", exception: undefined }, ...s.orders] }
            : s
      )
    );
    push({ tone: "primary", title: `${order.id} advanced to ${next.label}`, desc: `${order.customer} · owner ${order.owner}` });
  };

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Delivery", to: "/projects" }, { label: "Operations" }]}
        title="Operations — Production to Installation"
        description="Live stage board across the workshop pipeline. Owners, exceptions and next actions in one view."
        actions={
          <>
            <Button
              variant={onlyExceptions ? "primary" : "secondary"}
              onClick={() => setOnlyExceptions((v) => !v)}
            >
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
        {orders.map((s, i) => {
          const Icon = laneIcon[s.key] ?? Factory;
          const exc = s.orders.filter((o) => o.exception).length;
          return (
            <div key={s.key} className="flex items-center gap-2">
              <button
                onClick={() => navigate(`/operations/${s.key}`)}
                className={cn(
                  "inline-flex h-10 items-center gap-2 rounded-control border px-3.5 text-small font-medium transition-colors duration-150",
                  active === s.key
                    ? "border-primary-500 bg-primary-50 text-primary-700"
                    : "border-line-strong bg-white text-slate-700 hover:border-slate-400"
                )}
              >
                <Icon size={16} className={active === s.key ? "text-primary-600" : "text-subtle"} />
                {s.label}
                <span className={cn("rounded-full px-1.5 text-caption", active === s.key ? "bg-primary-500 text-white" : "bg-slate-100 text-slate-600")}>
                  {s.orders.length}
                </span>
                {exc > 0 && <ShieldCheck size={14} className="text-danger" aria-label={`${exc} exceptions`} />}
              </button>
              {i < orders.length - 1 && <ArrowRight size={14} className="text-slate-300" />}
            </div>
          );
        })}
        <button
          onClick={() => navigate("/operations")}
          className={cn(
            "ml-auto h-10 rounded-control px-3.5 text-caption font-semibold transition-colors",
            active === "board" ? "bg-charcoal-900 text-white" : "text-muted hover:text-ink"
          )}
        >
          View full board
        </button>
      </Card>

      <p className="mb-3 text-caption text-subtle" aria-live="polite">
        {totalOrders} active orders · {exceptions} flagged · board updates are simulated locally (no backend in Phase 3)
      </p>

      {/* Board */}
      <div className="scrollbar-slim -mx-4 flex gap-3 overflow-x-auto px-4 pb-2 lg:-mx-6 lg:px-6">
        {orders
          .filter((s) => active === "board" || s.key === active)
          .map((s) => {
            const Icon = laneIcon[s.key] ?? Factory;
            const list = s.orders.filter((o) => !onlyExceptions || o.exception);
            return (
              <section
                key={s.key}
                aria-label={`${s.label} queue`}
                className={cn(
                  "w-full shrink-0 rounded-panel border border-line bg-slate-100/70 lg:w-[300px]",
                  active === s.key && "border-primary-500/60 ring-2 ring-primary-500/15"
                )}
              >
                <header className="flex items-center gap-2.5 px-4 pt-3.5 pb-2">
                  <span className="grid h-8 w-8 place-items-center rounded-control bg-white text-slate-700 shadow-card">
                    <Icon size={16} />
                  </span>
                  <h2 className="text-small font-semibold text-ink">{s.label}</h2>
                  <span className="ml-auto text-caption font-semibold text-subtle">{list.length}/{s.orders.length}</span>
                </header>
                <ul className="space-y-2 p-2.5">
                  {list.map((o) => (
                    <li key={o.id} className="rounded-card border border-line bg-white p-3.5 shadow-card transition-shadow duration-150 hover:shadow-pop">
                      <div className="flex items-start justify-between gap-2">
                        <span className="font-mono text-caption text-subtle">{o.id}</span>
                        <StatusBadge status={o.status} />
                      </div>
                      <p className="mt-1.5 text-small font-semibold text-ink">{o.item}</p>
                      <p className="text-caption text-muted">{o.customer}</p>
                      {o.exception && (
                        <p className="mt-2 flex items-start gap-1.5 rounded border border-danger/25 bg-danger-soft px-2 py-1.5 text-caption text-danger-strong">
                          <AlertTriangle size={13} className="mt-px shrink-0" />
                          {o.exception}
                        </p>
                      )}
                      <div className="mt-3 flex items-center justify-between border-t border-slate-100 pt-2.5">
                        <span className="flex items-center gap-1.5 text-caption text-muted">
                          <Avatar initials={initials(o.owner)} size="xs" />
                          {o.owner}
                        </span>
                        <span className="text-caption text-subtle">Due {o.due}</span>
                      </div>
                      <div className="mt-2.5 flex gap-1.5">
                        <Button size="sm" className="flex-1" onClick={() => advance(s.key, o)}>
                          {s.key === "installation" ? "Complete" : `Move to ${orders[orders.findIndex((x) => x.key === s.key) + 1]?.label ?? "Done"}`}
                        </Button>
                        <Button variant="secondary" size="sm" aria-label={`Actions for ${o.id}`} onClick={() => push({ tone: "info", title: `Actions for ${o.id}`, desc: "Assign, reschedule, flag for QC — Phase 4." })}>
                          ⋯
                        </Button>
                      </div>
                    </li>
                  ))}
                  {list.length === 0 && (
                    <li className="rounded-card border border-dashed border-slate-300 py-8 text-center text-caption text-subtle">
                      {onlyExceptions ? "No exceptions in this stage ✓" : "Queue clear"}
                    </li>
                  )}
                </ul>
              </section>
            );
          })}
      </div>

      {/* SLA summary */}
      <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3 lg:gap-4">
        <Card className="py-4">
          <p className="text-caption font-medium text-subtle uppercase">On-time completion</p>
          <p className="mt-1 text-h2 text-ink">91.4%</p>
          <p className="mt-1 text-caption text-success-strong">↑ 3.1 pts vs. last month</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption font-medium text-subtle uppercase">Avg. production lead time</p>
          <p className="mt-1 text-h2 text-ink">12.6 days</p>
          <p className="mt-1 flex items-center gap-1.5 text-caption">
            <Badge tone="warning" dot={false}>SLA 14d</Badge>
            <span className="text-subtle">within target</span>
          </p>
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

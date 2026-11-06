import { useEffect, useState } from "react";
import type { FormEvent } from "react";
import { Link, useParams } from "react-router";
import { PageHeader } from "@/components/layout/PageHeader";
import { Badge, StatusBadge } from "@/components/ui/Badge";
import { Button, buttonCls } from "@/components/ui/Button";
import { Card, CardHeader } from "@/components/ui/Card";
import { Field, Input, Select } from "@/components/ui/Field";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui/States";
import { useToast } from "@/components/ui/Toast";
import { fmtPKR, mutate, useApi, useRealtime, type ApiOrderDetail } from "@/lib/api";
import { ChevronLeft, Calendar, CheckCircle, ClipboardList, FileText, RefreshCw, Truck, User } from "@/icons";

const STAGES = [
  { value: "production", label: "Production" },
  { value: "qc", label: "Quality check" },
  { value: "dispatch", label: "Dispatch" },
  { value: "delivery", label: "Delivery" },
  { value: "installation", label: "Installation" },
];
const STATUSES = ["Pending", "On Track", "In Progress", "On Hold", "Delayed", "Completed"];
const fmtDate = (value?: string | null) => {
  if (!value) return "—";
  const date = new Date(value);
  return Number.isNaN(date.getTime()) ? value : date.toLocaleString("en-PK", { dateStyle: "medium", timeStyle: "short" });
};

type OrderDraft = { status: string; stage: string; owner: string; due: string };

export default function OrderDetail() {
  const { id = "" } = useParams();
  const { push } = useToast();
  const { data, error, loading, reload } = useApi<ApiOrderDetail>(`/api/orders/${encodeURIComponent(id)}`, 30000);
  const [draft, setDraft] = useState<OrderDraft>({ status: "", stage: "", owner: "", due: "" });
  const [saving, setSaving] = useState(false);
  useRealtime(/orders|invoices|payments|returns|tasks/, () => reload());

  useEffect(() => {
    if (!data) return;
    setDraft({ status: data.status, stage: data.stage, owner: data.owner ?? "", due: data.due ?? "" });
  }, [data]);

  const saveOrder = async (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    if (!data) return;
    setSaving(true);
    try {
      await mutate(`/api/orders/${data.id}`, { status: draft.status, stage: draft.stage, owner: draft.owner.trim(), due: draft.due.trim() });
      push({ tone: "success", title: `${data.ref} updated`, desc: "Order status and fulfillment details were saved." });
      await reload();
    } catch (cause) {
      push({ tone: "danger", title: "Could not update order", desc: cause instanceof Error ? cause.message : String(cause) });
    } finally {
      setSaving(false);
    }
  };

  if (loading && !data) return <div><PageHeader title="Loading order…" /><Skeleton className="h-[520px]" /></div>;
  if (error || !data) return <div><PageHeader crumbs={[{ label: "Sales", to: "/orders" }, { label: "Order details" }]} title="Order unavailable" /><ErrorState title="Could not load order details" description="The order may have been removed or the API is unavailable." debug={error ?? `GET /api/orders/${id}`} onRetry={() => void reload()} /></div>;

  const order = data;
  const itemSubtotal = (order.items ?? []).reduce((sum, line) => sum + (line.price ?? 0) * (line.qty ?? 1), 0);

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Sales", to: "/orders" }, { label: "Orders", to: "/orders" }, { label: order.ref }]}
        title={order.ref}
        description={`Placed ${fmtDate(order.createdAt)} · last updated ${fmtDate(order.updatedAt)}`}
        actions={<><Link to="/orders" className={buttonCls("secondary", "md")}><ChevronLeft size={14} /> All orders</Link><Button type="button" variant="secondary" onClick={() => void reload()}><RefreshCw size={14} /> Refresh</Button></>}
      />

      <div className="mb-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <Card className="p-4"><p className="text-caption text-muted">Order total</p><p className="mt-1 text-h2 text-ink">{fmtPKR(order.total)}</p><p className="mt-1 text-caption text-subtle">{order.items.length} line item{order.items.length === 1 ? "" : "s"}</p></Card>
        <Card className="p-4"><p className="text-caption text-muted">Order status</p><div className="mt-2"><StatusBadge status={order.status} /></div><p className="mt-2 text-caption text-subtle">Live from order record</p></Card>
        <Card className="p-4"><p className="text-caption text-muted">Fulfillment stage</p><div className="mt-2 flex items-center gap-2 text-small font-semibold text-ink"><Truck size={15} className="text-primary-600" />{STAGES.find((stage) => stage.value === order.stage)?.label ?? order.stage}</div><p className="mt-2 text-caption text-subtle">Due {order.due || "not scheduled"}</p></Card>
        <Card className="p-4"><p className="text-caption text-muted">Payment records</p><p className="mt-1 text-h2 text-ink">{order.invoices.length}</p><p className="mt-1 text-caption text-subtle">{order.invoices.reduce((sum, invoice) => sum + invoice.payments.length, 0)} payment entries</p></Card>
      </div>

      <div className="grid items-start gap-4 xl:grid-cols-[minmax(0,1fr)_360px]">
        <div className="space-y-4">
          <Card padded={false} className="overflow-hidden">
            <div className="p-5 pb-0"><CardHeader title="Order items" description="Line items are preserved as placed at checkout, independent of later catalog edits." /></div>
            {order.items.length ? (
              <div className="scrollbar-slim overflow-x-auto">
                <table className="w-full min-w-[540px] border-collapse text-left text-small">
                  <thead><tr className="border-y border-line bg-slate-50 text-caption font-semibold uppercase tracking-wide text-subtle"><th className="px-5 py-2.5">Product</th><th className="px-3 py-2.5 text-right">Qty</th><th className="px-3 py-2.5 text-right">Unit price</th><th className="px-5 py-2.5 text-right">Line total</th></tr></thead>
                  <tbody>{order.items.map((line, index) => <tr key={`${line.id ?? line.productId ?? line.name}-${index}`} className="border-b border-slate-100 last:border-0"><td className="px-5 py-3.5"><span className="block font-medium text-ink">{line.name ?? "Product"}</span>{line.color && <span className="mt-0.5 block text-caption text-subtle">{line.color}</span>}</td><td className="px-3 py-3.5 text-right text-muted">{line.qty ?? 1}</td><td className="px-3 py-3.5 text-right text-muted">{fmtPKR(line.price ?? 0)}</td><td className="px-5 py-3.5 text-right font-semibold text-ink">{fmtPKR((line.price ?? 0) * (line.qty ?? 1))}</td></tr>)}</tbody>
                  <tfoot><tr className="bg-slate-50"><td colSpan={3} className="px-5 py-3 text-right text-small font-semibold text-ink">Order total</td><td className="px-5 py-3 text-right text-small font-bold text-ink">{fmtPKR(order.total)}</td></tr></tfoot>
                </table>
                {itemSubtotal !== order.total && <p className="px-5 py-2 text-caption text-subtle">Line items sum to {fmtPKR(itemSubtotal)}; the persisted order total is {fmtPKR(order.total)}.</p>}
              </div>
            ) : <EmptyState icon={<ShoppingBagIcon />} title="No line items" description="This order record has no product lines." />}
          </Card>

          <Card>
            <CardHeader title="Fulfillment & assignment" description="Updates save to the shared order API and appear on the operations board." />
            <form onSubmit={(event) => void saveOrder(event)} className="grid gap-3 sm:grid-cols-2">
              <Field label="Order status"><Select value={draft.status} onChange={(event) => setDraft((current) => ({ ...current, status: event.target.value }))}>{STATUSES.map((status) => <option key={status} value={status}>{status}</option>)}</Select></Field>
              <Field label="Fulfillment stage"><Select value={draft.stage} onChange={(event) => setDraft((current) => ({ ...current, stage: event.target.value }))}>{STAGES.map((stage) => <option key={stage.value} value={stage.value}>{stage.label}</option>)}</Select></Field>
              <Field label="Owner"><Input maxLength={80} value={draft.owner} onChange={(event) => setDraft((current) => ({ ...current, owner: event.target.value }))} placeholder="Assign an owner" /></Field>
              <Field label="Due / delivery target" hint="Current order records use a free-text due field."><Input maxLength={80} value={draft.due} onChange={(event) => setDraft((current) => ({ ...current, due: event.target.value }))} placeholder="e.g. Oct 18 or 2026-10-18" /></Field>
              <div className="flex items-center justify-between gap-3 sm:col-span-2"><span className="text-caption text-subtle">Status and stage are validated by the API.</span><Button type="submit" loading={saving}>Save order</Button></div>
            </form>
          </Card>

          <Card>
            <CardHeader title="Invoices & payments" description="Financial records linked to this order." action={<Link to="/invoices" className={buttonCls("secondary", "sm")}><FileText size={13} /> Invoices</Link>} />
            {order.invoices.length ? <div className="space-y-3">{order.invoices.map((invoice) => (
              <div key={invoice.id} className="rounded-card border border-line bg-slate-50/70 p-4">
                <div className="flex flex-wrap items-start justify-between gap-3"><div><p className="font-mono text-small font-semibold text-ink">{invoice.ref}</p><p className="mt-1 text-caption text-muted">Due {invoice.due || "—"} · issued {fmtDate(invoice.createdAt)}</p></div><StatusBadge status={invoice.status} /></div>
                <div className="mt-3 grid grid-cols-3 gap-2 border-t border-line pt-3 text-caption"><span><span className="block text-subtle">Total</span><strong className="mt-0.5 block text-ink">{fmtPKR(invoice.total)}</strong></span><span><span className="block text-subtle">Paid</span><strong className="mt-0.5 block text-success-strong">{fmtPKR(invoice.paid)}</strong></span><span><span className="block text-subtle">Balance</span><strong className="mt-0.5 block text-warning-strong">{fmtPKR(invoice.balance)}</strong></span></div>
                {invoice.payments.length > 0 && <ul className="mt-3 space-y-1.5 border-t border-line pt-3">{invoice.payments.map((payment) => <li key={payment.id} className="flex flex-wrap justify-between gap-2 text-caption"><span className="text-muted">{payment.method}{payment.reference ? ` · ${payment.reference}` : ""} · {fmtDate(payment.created_at)}</span><strong className="text-ink">{fmtPKR(payment.amount)}</strong></li>)}</ul>}
              </div>
            ))}</div> : <EmptyState icon={<FileText size={18} />} title="No invoice linked" description="Create or link an invoice from the Invoices module when ready." action={<Link to="/invoices" className={buttonCls("secondary", "sm")}>Open invoices</Link>} />}
          </Card>
        </div>

        <aside className="space-y-4">
          <Card>
            <CardHeader title="Customer" action={order.client ? <Link to={`/clients?c=${order.client.id}`} className="text-caption font-semibold text-primary-700 hover:text-primary-800">CRM record</Link> : undefined} />
            <div className="flex items-start gap-3"><span className="grid h-10 w-10 shrink-0 place-items-center rounded-full bg-primary-50 text-primary-700"><User size={17} /></span><span className="min-w-0"><span className="block truncate text-small font-semibold text-ink">{order.client?.name ?? order.customer}</span><span className="block text-caption text-muted">{order.client?.company || "Customer"}{order.client?.city ? ` · ${order.client.city}` : ""}</span></span></div>
            <dl className="mt-4 space-y-2 border-t border-line pt-3 text-small">{order.client?.phone && <div className="flex justify-between gap-3"><dt className="text-muted">Phone</dt><dd className="text-right text-ink">{order.client.phone}</dd></div>}{order.client?.email && <div className="flex justify-between gap-3"><dt className="text-muted">Email</dt><dd className="max-w-[210px] truncate text-right text-ink">{order.client.email}</dd></div>}{!order.client?.phone && !order.client?.email && <p className="text-caption text-subtle">No contact details are linked to this order.</p>}</dl>
          </Card>

          <Card>
            <CardHeader title="Returns & follow-ups" />
            <div className="space-y-3">
              <section aria-label="Returns"><div className="mb-2 flex items-center justify-between"><h3 className="text-caption font-semibold text-subtle uppercase">Returns</h3><Badge tone="neutral" dot={false}>{order.returns.length}</Badge></div>{order.returns.length ? <ul className="space-y-2">{order.returns.map((item) => <li key={item.id} className="rounded-control border border-line p-3"><div className="flex items-center justify-between gap-2"><span className="font-mono text-caption font-semibold text-ink">{item.ref}</span><StatusBadge status={item.state} /></div><p className="mt-1 text-caption text-muted">{item.item || "Order item"} · {item.reason}</p></li>)}</ul> : <p className="rounded-control border border-dashed border-line-strong px-3 py-3 text-caption text-subtle">No return cases recorded.</p>}</section>
              <section aria-label="Tasks"><div className="mb-2 flex items-center justify-between"><h3 className="text-caption font-semibold text-subtle uppercase">Tasks</h3><Link to="/tasks" className="text-caption font-semibold text-primary-700">Task board</Link></div>{order.tasks.length ? <ul className="space-y-2">{order.tasks.map((task) => <li key={task.id} className="flex items-start gap-2 rounded-control border border-line p-3"><span className="mt-0.5 text-primary-600">{task.done ? <CheckCircle size={14} /> : <ClipboardList size={14} />}</span><span className="min-w-0 flex-1"><span className={`block text-small font-medium ${task.done ? "text-muted line-through" : "text-ink"}`}>{task.title}</span><span className="mt-0.5 block text-caption text-subtle">{task.owner || "You"} · {task.due || "No due date"}</span></span></li>)}</ul> : <p className="rounded-control border border-dashed border-line-strong px-3 py-3 text-caption text-subtle">No follow-up tasks linked.</p>}</section>
            </div>
          </Card>

          <Card className="border-warning/30 bg-warning-soft/30">
            <div className="flex items-start gap-2.5"><Calendar size={16} className="mt-0.5 shrink-0 text-warning-strong" /><div><p className="text-small font-semibold text-warning-strong">Delivery address not captured</p><p className="mt-1 text-caption leading-relaxed text-muted">The current order record stores customer, items, value, owner, status, stage and due date only. Shipping address and delivery-event history need new order fields.</p></div></div>
          </Card>
        </aside>
      </div>
    </div>
  );
}

function ShoppingBagIcon() {
  return <ClipboardList size={18} />;
}

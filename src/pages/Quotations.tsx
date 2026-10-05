import { useMemo, useState } from "react";
import { Link, useLocation } from "react-router";
import { cn } from "@/lib/cn";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { StatusBadge } from "@/components/ui/Badge";
import { DataTable } from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import { Field, Input, Select, Textarea } from "@/components/ui/Field";
import { useToast } from "@/components/ui/Toast";
import { quotes } from "@/data/mock";
import { ArrowRight, Check, FileText, Plus, Save, Send, Sparkles } from "@/icons";

const IconTrash = ({ size = 14 }: { size?: number }) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden>
    <path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M6 6l1 14a1.5 1.5 0 0 0 1.5 1.4h7A1.5 1.5 0 0 0 17 20l1-14M10 11v6M14 11v6" />
  </svg>
);

/* ============================ LIST ============================ */

type QuoteRow = (typeof quotes)[number];

const quoteColumns: Column<QuoteRow>[] = [
  {
    key: "id",
    header: "Quote",
    cell: (r) => (
      <span className="flex items-center gap-2.5">
        <span className="grid h-8 w-8 place-items-center rounded-control bg-primary-50 text-primary-700">
          <FileText size={15} />
        </span>
        <span>
          <span className="block text-small font-semibold text-ink">{r.id}</span>
          <span className="block text-caption text-subtle">{r.items} line items</span>
        </span>
      </span>
    ),
  },
  { key: "customer", header: "Customer", cell: (r) => <span className="text-small text-slate-700">{r.customer}</span> },
  { key: "date", header: "Created", cell: (r) => <span className="text-caption text-muted">{r.date}</span> },
  { key: "total", header: "Total", align: "right", cell: (r) => <span className="text-small font-semibold text-ink">{r.total}</span> },
  { key: "stage", header: "Stage", cell: (r) => <StatusBadge status={r.stage} /> },
  {
    key: "actions",
    header: "",
    align: "right",
    cell: () => (
      <Link to="/quotations/new" className="text-caption font-semibold text-primary-600 hover:text-primary-700">
        Open editor
      </Link>
    ),
  },
];

export function QuotationsList() {
  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Sales", to: "/quotations" }, { label: "Quotations" }]}
        title="Sales & Quotations"
        description="Create, approve and send quotations — approval-gated end to end."
        actions={
          <Link to="/quotations/new" className="inline-flex">
            <Button>
              <Plus size={15} /> New E-Quotation
            </Button>
          </Link>
        }
      />
      <Card padded={false}>
        <DataTable rows={quotes} columns={quoteColumns} />
      </Card>
      <Card className="mt-4">
        <CardHeader
          title="Lifecycle"
          description="Every quote moves through visible, approval-gated stages."
          action={
            <Link to="/crm" className="text-caption font-semibold text-primary-600 hover:underline">
              Lead pipeline <ArrowRight size={12} className="inline" />
            </Link>
          }
        />
        <ol className="flex flex-wrap items-center gap-2">
          {["Draft", "Sent", "Viewed", "Negotiation", "Revised", "Approved"].map((s, i, a) => (
            <li key={s} className="flex items-center gap-2">
              <StatusBadge status={s} />
              {i < a.length - 1 && <ArrowRight size={13} className="text-slate-300" />}
            </li>
          ))}
          <li className="flex items-center gap-2">
            <span className="text-slate-300">/</span>
            <StatusBadge status="Rejected" />
            <StatusBadge status="Expired" />
          </li>
        </ol>
      </Card>
    </div>
  );
}

/* ============================ BUILDER ============================ */

type Line = { id: number; name: string; material: string; qty: number; price: number };

const lifecycle = ["Draft", "Review", "Approval", "Sent"] as const;

const auditInitial = [
  { who: "You", action: "created quotation", time: "Today 09:12" },
  { who: "Sana R.", action: "attached floor-plan reference", time: "Today 09:20" },
  { who: "You", action: "updated line items (v2)", time: "Today 09:41" },
];

export function QuotationBuilder() {
  const location = useLocation();
  const isNew = location.pathname.endsWith("/new");
  const { push } = useToast();
  const [status, setStatus] = useState<(typeof lifecycle)[number]>("Draft");
  const [lines, setLines] = useState<Line[]>([
    { id: 1, name: "Executive Modular Kitchen", material: "Ply + Acrylic · Sage Green", qty: 1, price: 850000 },
    { id: 2, name: "Aurora Sliding Wardrobe", material: "HMR Board · Smoked Oak", qty: 2, price: 240000 },
    { id: 3, name: "Lahore 3-Seater Sofa", material: "Solid Sheesham · Matte Walnut", qty: 1, price: 128000 },
  ]);
  const [discountPct, setDiscountPct] = useState(5);
  const [taxPct, setTaxPct] = useState(18);
  const [shipping, setShipping] = useState(25000);
  const [audit, setAudit] = useState(auditInitial);

  const totals = useMemo(() => {
    const subtotal = lines.reduce((n, l) => n + l.qty * l.price, 0);
    const discount = Math.round((subtotal * discountPct) / 100);
    const tax = Math.round(((subtotal - discount) * taxPct) / 100);
    return { subtotal, discount, tax, total: subtotal - discount + tax + shipping };
  }, [lines, discountPct, taxPct, shipping]);

  const fmt = (n: number) => "Rs " + n.toLocaleString("en-US");

  const note = (action: string) =>
    setAudit((a) => [{ who: "You", action, time: "Just now" }, ...a]);

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Sales", to: "/quotations" }, { label: isNew ? "New Quotation" : "Q-2295" }]}
        title={isNew ? "New E-Quotation" : "Quotation Q-2295"}
        description="Clifton Villa 9 · DHA Phase 5 expansion · prepared by Ayesha Rehman"
        actions={<StatusBadge status={status === "Sent" ? "Sent" : status === "Approval" ? "Negotiation" : status === "Review" ? "Viewed" : "Draft"} />}
      />

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_380px]">
        {/* ---------- Form column ---------- */}
        <div className="min-w-0 space-y-4">
          <Card>
            <CardHeader title="Customer Information" description="Primary contact for this quotation" />
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="Client name" required>
                <Input defaultValue="Ayesha Khan (Owner)" />
              </Field>
              <Field label="Company">
                <Input placeholder="Optional" defaultValue="Clifton Residency" />
              </Field>
              <Field label="Email" required>
                <Input type="email" defaultValue="ayesha.k@cliftonv9.com" />
              </Field>
              <Field label="Phone">
                <Input defaultValue="+92 300 8451290" />
              </Field>
              <Field label="Site address" className="sm:col-span-2">
                <Textarea defaultValue="Villa 9, Clifton Block 4, Karachi — 5,400 sq ft, 3 floors" />
              </Field>
            </div>
          </Card>

          <Card>
            <CardHeader
              title="Quotation Details"
              description="Numbering, validity and commercial terms"
              action={
                <span className="inline-flex items-center gap-1.5 rounded-full bg-primary-50 px-2.5 py-1 text-caption font-medium text-primary-700">
                  Auto-numbered
                </span>
              }
            />
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
              <Field label="Quote #">
                <Input value="Q-2295" readOnly className="bg-slate-50 text-muted" />
              </Field>
              <Field label="Date">
                <Input type="date" defaultValue="2026-10-05" />
              </Field>
              <Field label="Valid until">
                <Input type="date" defaultValue="2026-11-04" />
              </Field>
              <Field label="Currency">
                <Select defaultValue="PKR">
                  {["PKR", "USD", "AED", "GBP"].map((c) => (
                    <option key={c}>{c}</option>
                  ))}
                </Select>
              </Field>
            </div>
          </Card>

          {/* Lines */}
          <Card padded={false}>
            <div className="flex flex-wrap items-center justify-between gap-2 px-5 pt-5 lg:px-6 lg:pt-6">
              <div>
                <h3 className="text-h3">Product / Service Lines</h3>
                <p className="mt-1 text-small text-muted">{lines.length} items · quantities editable inline</p>
              </div>
              <div className="flex items-center gap-2">
                <Button
                  variant="subtle"
                  size="sm"
                  onClick={() => {
                    push({ tone: "info", title: "AI quote draft queued", desc: "Suggested items generated — review before saving (approval required)." });
                    note("requested AI draft for line items");
                  }}
                >
                  <Sparkles size={14} /> AI Draft
                </Button>
                <Link to="/catalog">
                  <Button variant="secondary" size="sm">
                    From Catalog
                  </Button>
                </Link>
              </div>
            </div>
            <div className="scrollbar-slim mt-3 overflow-x-auto">
              <table className="w-full min-w-[640px] border-collapse text-left">
                <thead>
                  <tr className="border-y border-line bg-slate-50 text-caption text-subtle">
                    <th className="px-5 py-2 font-medium uppercase tracking-wide lg:px-6">Item</th>
                    <th className="px-3 py-2 font-medium uppercase tracking-wide">Material / finish</th>
                    <th className="w-24 px-3 py-2 text-right font-medium uppercase tracking-wide">Qty</th>
                    <th className="w-36 px-3 py-2 text-right font-medium uppercase tracking-wide">Unit price</th>
                    <th className="w-32 px-3 py-2 text-right font-medium uppercase tracking-wide">Amount</th>
                    <th className="w-10 px-4" />
                  </tr>
                </thead>
                <tbody>
                  {lines.map((l) => (
                    <tr key={l.id} className="border-b border-slate-100 transition-colors hover:bg-primary-50/40 last:border-0">
                      <td className="px-5 py-2.5 lg:px-6">
                        <input
                          aria-label={`Item name for line ${l.id}`}
                          value={l.name}
                          onChange={(e) => setLines((x) => x.map((it) => (it.id === l.id ? { ...it, name: e.target.value } : it)))}
                          className="w-full rounded border border-transparent bg-transparent px-2 py-1.5 text-small font-medium text-ink hover:border-line-strong focus:border-primary-500 focus:bg-white focus:outline-none"
                        />
                      </td>
                      <td className="px-3 py-2.5">
                        <input
                          aria-label={`Material for line ${l.id}`}
                          value={l.material}
                          onChange={(e) => setLines((x) => x.map((it) => (it.id === l.id ? { ...it, material: e.target.value } : it)))}
                          className="w-full rounded border border-transparent bg-transparent px-2 py-1.5 text-caption text-muted hover:border-line-strong focus:border-primary-500 focus:bg-white focus:text-slate-700 focus:outline-none"
                        />
                      </td>
                      <td className="px-3 py-2.5 text-right">
                        <input
                          type="number"
                          min={1}
                          aria-label={`Quantity for line ${l.id}`}
                          value={l.qty}
                          onChange={(e) => setLines((x) => x.map((it) => (it.id === l.id ? { ...it, qty: Math.max(1, Number(e.target.value)) } : it)))}
                          className="h-9 w-20 rounded border border-line-strong bg-white px-2 text-right text-small focus:border-primary-500 focus:outline-none"
                        />
                      </td>
                      <td className="px-3 py-2.5 text-right">
                        <input
                          type="number"
                          min={0}
                          aria-label={`Unit price for line ${l.id}`}
                          value={l.price}
                          onChange={(e) => setLines((x) => x.map((it) => (it.id === l.id ? { ...it, price: Math.max(0, Number(e.target.value)) } : it)))}
                          className="h-9 w-32 rounded border border-line-strong bg-white px-2 text-right text-small focus:border-primary-500 focus:outline-none"
                        />
                      </td>
                      <td className="px-3 py-2.5 text-right text-small font-semibold text-ink">{fmt(l.qty * l.price)}</td>
                      <td className="px-4 py-2.5 text-right">
                        <button
                          aria-label={`Remove line ${l.name}`}
                          onClick={() => {
                            setLines((x) => x.filter((it) => it.id !== l.id));
                            note(`removed line: ${l.name}`);
                          }}
                          className="grid h-7 w-7 place-items-center rounded-control text-subtle transition-colors hover:bg-danger-soft hover:text-danger-strong"
                        >
                          <IconTrash size={14} />
                        </button>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
            <div className="flex items-center justify-between px-5 py-3.5 lg:px-6">
              <Button
                variant="secondary"
                size="sm"
                onClick={() => {
                  setLines((x) => [...x, { id: Date.now(), name: "Custom item", material: "Specify material & finish", qty: 1, price: 0 }]);
                  note("added a line item");
                }}
              >
                <Plus size={14} /> Add line
              </Button>
              <span className="text-caption text-subtle">Discount {discountPct}% · Tax {taxPct}% applied on totals</span>
            </div>
          </Card>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader title="Discount / Tax / Shipping" />
              <div className="grid grid-cols-3 gap-3">
                <Field label="Discount %">
                  <Input type="number" value={discountPct} onChange={(e) => setDiscountPct(Number(e.target.value) || 0)} />
                </Field>
                <Field label="Tax %">
                  <Input type="number" value={taxPct} onChange={(e) => setTaxPct(Number(e.target.value) || 0)} />
                </Field>
                <Field label="Shipping">
                  <Input type="number" value={shipping} onChange={(e) => setShipping(Number(e.target.value) || 0)} />
                </Field>
              </div>
            </Card>
            <Card>
              <CardHeader title="Terms" description="Shown on the client-facing document" />
              <Textarea
                defaultValue="50% advance with approval, 40% at production milestone, 10% on installation sign-off. Production window 21–28 working days. Woodex 2-year structural warranty included."
                rows={3}
              />
            </Card>
          </div>
        </div>

        {/* ---------- Right rail: totals + approval ---------- */}
        <div className="space-y-4">
          <Card className="sticky top-20 z-20 xl:top-20">
            <CardHeader title="Totals" />
            <dl className="space-y-2 text-small">
              <div className="flex justify-between">
                <dt className="text-muted">Subtotal</dt>
                <dd className="font-medium text-ink">{fmt(totals.subtotal)}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted">Discount ({discountPct}%)</dt>
                <dd className="font-medium text-danger-strong">− {fmt(totals.discount)}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted">Tax ({taxPct}%)</dt>
                <dd className="font-medium text-ink">{fmt(totals.tax)}</dd>
              </div>
              <div className="flex justify-between">
                <dt className="text-muted">Delivery &amp; installation</dt>
                <dd className="font-medium text-ink">{fmt(shipping)}</dd>
              </div>
            </dl>
            {/* Quotation total — visually prominent (§Screen 02) */}
            <div className="mt-4 rounded-card bg-primary-500 p-4 text-white shadow-cta">
              <p className="text-caption font-medium tracking-wide uppercase opacity-90">Quotation total</p>
              <p className="mt-1 text-display tracking-tight">{fmt(totals.total)}</p>
              <p className="mt-0.5 text-caption opacity-90">≈ {"$"}
                {Math.round(totals.total / 278).toLocaleString()} · incl. tax &amp; logistics</p>
            </div>
            <div className="mt-4 space-y-2" role="group" aria-label="Quotation actions">
              <Button
                className="w-full"
                variant="secondary"
                disabled={status === "Sent"}
                onClick={() => {
                  push({ tone: "primary", title: "Draft saved", desc: `${isNew ? "Q-2295" : "Q-2295"} · ${lines.length} lines · ${fmt(totals.total)}` });
                  note("saved draft");
                }}
              >
                <Save size={15} /> Save Draft
              </Button>
              <Button
                className="w-full"
                disabled={status !== "Draft" && status !== "Review"}
                onClick={() => {
                  if (status === "Draft") {
                    setStatus("Review");
                    push({ tone: "warning", title: "Submitted for internal review", desc: "Pending Finance + Ops sign-off." });
                    note("submitted for review");
                  } else {
                    setStatus("Approval");
                    push({ tone: "success", title: "Quotation approved", desc: "Ready to send to client." });
                    note("approved (Demo · acting as Finance Lead)");
                  }
                }}
              >
                <Check size={15} /> {status === "Draft" ? "Submit for Approval" : "Approve"}
              </Button>
              <Button
                className="w-full"
                disabled={status !== "Approval"}
                onClick={() => {
                  setStatus("Sent");
                  push({ tone: "success", title: "Sent to client", desc: "Secure link generated · tracked views enabled." });
                  note("sent to client");
                }}
              >
                <Send size={15} /> Send to Client
              </Button>
              {status === "Sent" && (
                <p className="pt-1 text-center text-caption text-success-strong">✓ Sent — tracking views &amp; responses</p>
              )}
            </div>
          </Card>

          <Card>
            <CardHeader title="Approval Workflow" description="Who created, owns, approves — and what's next" />
            <ol className="mb-4">
              {lifecycle.map((s, i) => {
                const activeIdx = lifecycle.indexOf(status);
                const state = i < activeIdx ? "done" : i === activeIdx ? "current" : "todo";
                return (
                  <li key={s} className="relative flex gap-3 pb-4 last:pb-0">
                    <span
                      className={cn(
                        "z-10 grid h-6 w-6 shrink-0 place-items-center rounded-full border text-[10px] font-bold",
                        state === "done" && "border-primary-500 bg-primary-500 text-white",
                        state === "current" && "border-primary-500 bg-white text-primary-700",
                        state === "todo" && "border-line-strong bg-white text-subtle"
                      )}
                    >
                      {state === "done" ? "✓" : i + 1}
                    </span>
                    {i < lifecycle.length - 1 && (
                      <span className={cn("absolute top-6 bottom-0 left-3 w-px", i < activeIdx ? "bg-primary-500" : "bg-slate-200")} aria-hidden />
                    )}
                    <span className="pt-0.5">
                      <span className={cn("block text-small font-semibold", state === "todo" ? "text-subtle" : "text-ink")}>{s}</span>
                      <span className="block text-caption text-muted">
                        {s === "Draft" && "Prepared by You · today"}
                        {s === "Review" && "Owner: Sana R. (Sales)"}
                        {s === "Approval" && "Approver: Finance Lead"}
                        {s === "Sent" && "Client portal + email"}
                      </span>
                    </span>
                  </li>
                );
              })}
            </ol>
            <div className="rounded-card border border-line bg-slate-50/70 p-3">
              <p className="mb-1.5 text-caption font-semibold tracking-wide text-subtle uppercase">Audit history</p>
              <ul className="space-y-1.5">
                {audit.slice(0, 4).map((a, i) => (
                  <li key={i} className="flex items-baseline justify-between gap-2 text-caption">
                    <span className="truncate text-slate-700">
                      <strong className="font-semibold text-ink">{a.who}</strong> {a.action}
                    </span>
                    <span className="shrink-0 text-subtle">{a.time}</span>
                  </li>
                ))}
              </ul>
            </div>
          </Card>
        </div>
      </div>
    </div>
  );
}

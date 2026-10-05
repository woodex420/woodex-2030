import { useMemo, useState } from "react";
import { Link } from "react-router";
import { cn } from "@/lib/cn";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { StatusBadge } from "@/components/ui/Badge";
import { DataTable } from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import { Field, Input, Select, Textarea } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { SkeletonRows, EmptyState } from "@/components/ui/States";
import { useToast } from "@/components/ui/Toast";
import { useApi, mutate, fmtPKR, timeAgo, type ApiQuote, type ApiProduct } from "@/lib/api";
import { ArrowRight, Check, FileText, Plus, Save, Search, Send, Sparkles } from "@/icons";

const IconTrash = ({ size = 14 }: { size?: number }) => (
  <svg width={size} height={size} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.8" strokeLinecap="round" aria-hidden>
    <path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M6 6l1 14a1.5 1.5 0 0 0 1.5 1.4h7A1.5 1.5 0 0 0 17 20l1-14M10 11v6M14 11v6" />
  </svg>
);

/* ============================ LIST ============================ */

const quoteCols: Column<ApiQuote>[] = [
  {
    key: "ref",
    header: "Quote",
    cell: (r) => (
      <span className="flex items-center gap-2.5">
        <span className="grid h-8 w-8 place-items-center rounded-control bg-primary-50 text-primary-700">
          <FileText size={15} />
        </span>
        <span>
          <span className="block text-small font-semibold text-ink">{r.ref}</span>
          <span className="block text-caption text-subtle capitalize">{r.source} · {r.items.length} line {r.items.length === 1 ? "item" : "items"}</span>
        </span>
      </span>
    ),
  },
  { key: "customer", header: "Customer", cell: (r) => <span className="text-small text-slate-700">{r.customer}</span> },
  { key: "created", header: "Created", cell: (r) => <span className="text-caption text-muted">{timeAgo(r.createdAt)}</span> },
  { key: "total", header: "Total", align: "right", cell: (r) => <span className="text-small font-semibold text-ink">{fmtPKR(r.total)}</span> },
  { key: "status", header: "Stage", cell: (r) => <StatusBadge status={r.status} /> },
];

export function QuotationsList() {
  const { data, loading, error, reload } = useApi<{ total: number; items: ApiQuote[] }>("/api/quotes", 15000);
  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Sales", to: "/quotations" }, { label: "Quotations" }]}
        title="Sales & Quotations"
        description="Storefront quote requests land here instantly — create, approve and send, all persisted in the shared backend."
        actions={
          <>
            <Button variant="secondary" onClick={reload}>Refresh</Button>
            <Link to="/quotations/new" className="inline-flex">
              <Button>
                <Plus size={15} /> New E-Quotation
              </Button>
            </Link>
          </>
        }
      />
      {error ? (
        <Card>
          <EmptyState icon={<FileText size={20} />} title="Quotes API unreachable" description={"Start the backend with npm run dev:api — " + error} action={<Button onClick={reload}>Retry</Button>} />
        </Card>
      ) : (
        <Card padded={false}>
          {loading ? (
            <div className="p-5">
              <SkeletonRows rows={5} />
            </div>
          ) : (
            <DataTable rows={data?.items ?? []} columns={quoteCols} perPage={8} />
          )}
        </Card>
      )}
      <Card className="mt-4">
        <CardHeader title="Lifecycle" description="Every quote moves through visible, approval-gated stages." action={<Link to="/crm" className="text-caption font-semibold text-primary-600 hover:underline">Lead pipeline <ArrowRight size={12} className="inline" /></Link>} />
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
type Stage = "Draft" | "Review" | "Approval" | "Sent";

const lifecycle: Stage[] = ["Draft", "Review", "Approval", "Sent"];

export function QuotationBuilder() {
  const { push } = useToast();
  const catalog = useApi<{ items: ApiProduct[] }>("/api/products");
  const [status, setStatus] = useState<Stage>("Draft");
  const [savedId, setSavedId] = useState<number | null>(null);
  const [savedRef, setSavedRef] = useState("Q-new");
  const [catalogOpen, setCatalogOpen] = useState(false);
  const [catalogQuery, setCatalogQuery] = useState("");
  const [customer, setCustomer] = useState({ name: "Ayesha Khan (Owner)", company: "Clifton Residency", email: "ayesha.k@cliftonv9.com", phone: "+92 300 8451290", address: "Villa 9, Clifton Block 4, Karachi — 5,400 sq ft, 3 floors" });
  const [lines, setLines] = useState<Line[]>([]);
  const [discountPct, setDiscountPct] = useState(5);
  const [taxPct, setTaxPct] = useState(18);
  const [shipping, setShipping] = useState(25000);
  const [audit, setAudit] = useState<{ who: string; action: string; time: string }[]>([{ who: "You", action: "started quotation", time: "now" }]);
  const [busy, setBusy] = useState(false);

  const fmt = fmtPKR;

  const totals = useMemo(() => {
    const subtotal = lines.reduce((n, l) => n + l.qty * l.price, 0);
    const discount = Math.round((subtotal * discountPct) / 100);
    const tax = Math.round(((subtotal - discount) * taxPct) / 100);
    return { subtotal, discount, tax, total: subtotal - discount + tax + (lines.length ? shipping : 0) };
  }, [lines, discountPct, taxPct, shipping]);

  const note = (action: string) => setAudit((a) => [{ who: "You", action, time: "Just now" }, ...a]);

  const ensureSaved = async (): Promise<number | null> => {
    if (savedId) return savedId;
    if (!lines.length) { push({ tone: "danger", title: "Add at least one line item first" }); return null; }
    const res = await mutate<ApiQuote>("/api/quotes", {
      customer: customer.name.replace(/\s*\(.*\)/, ""),
      contact: customer.email,
      items: lines.map((l) => ({ name: l.name, price: l.price, qty: l.qty, material: l.material })),
      total: totals.total,
      source: "dashboard",
      note: `Prepared by Ayesha Rehman · ${lines.length} lines`,
    }, "POST");
    setSavedId(res.id);
    setSavedRef(res.ref);
    setAudit((a) => [{ who: "System", action: "saved to backend as " + res.ref, time: "now" }, ...a]);
    return res.id;
  };

  const advance = async (to: Stage, okTitle: string) => {
    setBusy(true);
    try {
      const id = await ensureSaved();
      if (id == null) return;
      await mutate("/api/quotes/" + id, { status: to });
      setStatus(to);
      note("moved to " + to);
      push({ tone: "success", title: okTitle, desc: `${savedRef} · ${fmt(totals.total)} — persisted to shared DB` });
    } catch (e) {
      push({ tone: "danger", title: "Could not save", desc: e instanceof Error ? e.message : String(e) });
    } finally {
      setBusy(false);
    }
  };

  const addProduct = (p: ApiProduct) => {
    setLines((x) => {
      const existing = x.find((l) => l.name === p.name);
      if (existing) return x.map((l) => (l === existing ? { ...l, qty: l.qty + 1 } : l));
      return [...x, { id: Date.now() + Math.random(), name: p.name, material: (p.colors ?? []).map((c) => c.name).slice(0, 2).join(" / ") || p.series || "—", qty: 1, price: p.price }];
    });
    note(`added catalog item: ${p.name}`);
    push({ tone: "success", title: "Added to quotation", desc: `${p.name} · ${fmt(p.price)}` });
  };

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Sales", to: "/quotations" }, { label: savedId ? savedRef : "New Quotation" }]}
        title={savedId ? savedRef : "New E-Quotation"}
        description="Draft lines from the live catalog — saving persists to the shared backend and flows into the quotation pipeline."
        actions={<StatusBadge status={status === "Sent" ? "Sent" : status === "Approval" ? "Approved" : status === "Review" ? "Viewed" : "Draft"} />}
      />

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-[minmax(0,1fr)_380px]">
        <div className="min-w-0 space-y-4">
          <Card>
            <CardHeader title="Customer Information" description="Primary contact for this quotation" />
            <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
              <Field label="Client name" required>
                <Input value={customer.name} onChange={(e) => setCustomer((c) => ({ ...c, name: e.target.value }))} />
              </Field>
              <Field label="Company">
                <Input value={customer.company} onChange={(e) => setCustomer((c) => ({ ...c, company: e.target.value }))} />
              </Field>
              <Field label="Email" required>
                <Input type="email" value={customer.email} onChange={(e) => setCustomer((c) => ({ ...c, email: e.target.value }))} />
              </Field>
              <Field label="Phone">
                <Input value={customer.phone} onChange={(e) => setCustomer((c) => ({ ...c, phone: e.target.value }))} />
              </Field>
              <Field label="Site address" className="sm:col-span-2">
                <Textarea value={customer.address} rows={2} onChange={(e) => setCustomer((c) => ({ ...c, address: e.target.value }))} />
              </Field>
            </div>
          </Card>

          <Card>
            <CardHeader
              title="Quotation Details"
              description={savedId ? `${savedRef} · saved in shared backend` : "Auto-numbered when first saved"}
              action={<Button variant="secondary" size="sm" onClick={() => catalog.reload()}>Refresh catalog</Button>}
            />
            <div className="grid grid-cols-2 gap-4 lg:grid-cols-4">
              <Field label="Quote #"><Input value={savedRef} readOnly className="bg-slate-50 text-muted" /></Field>
              <Field label="Date"><Input type="date" defaultValue="2026-10-05" /></Field>
              <Field label="Valid until"><Input type="date" defaultValue="2026-11-04" /></Field>
              <Field label="Currency"><Select defaultValue="PKR"><option>PKR</option><option>USD</option></Select></Field>
            </div>
          </Card>

          <Card padded={false}>
            <div className="flex flex-wrap items-center justify-between gap-2 px-5 pt-5 lg:px-6 lg:pt-6">
              <div>
                <h3 className="text-h3">Product / Service Lines</h3>
                <p className="mt-1 text-small text-muted">{lines.length} items · sourced from the {catalog.data?.items.length ?? "live"}-SKU catalog</p>
              </div>
              <div className="flex items-center gap-2">
                <Button
                  variant="subtle"
                  size="sm"
                  onClick={() => {
                    const p = (catalog.data?.items ?? []).find((x) => x.isBestSeller) ?? catalog.data?.items[0];
                    if (p) { addProduct(p); push({ tone: "info", title: "AI draft suggestion", desc: "Picked a best-seller to start — edit freely (review required before sending, §26)." }); }
                  }}
                >
                  <Sparkles size={14} /> AI Draft
                </Button>
                <Button variant="secondary" size="sm" onClick={() => setCatalogOpen(true)}>From Catalog</Button>
              </div>
            </div>
            {lines.length === 0 ? (
              <div className="px-5 pb-5 lg:px-6 lg:pb-6">
                <EmptyState
                  icon={<FileText size={20} />}
                  title="No line items yet"
                  description="Add furniture SKUs from the live catalog, then save to push this quote into the approval pipeline."
                  action={<Button size="sm" onClick={() => setCatalogOpen(true)}><Plus size={14} /> From Catalog</Button>}
                />
              </div>
            ) : (
              <>
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
                            <input aria-label="Item name" value={l.name} onChange={(e) => setLines((x) => x.map((it) => (it.id === l.id ? { ...it, name: e.target.value } : it)))} className="w-full rounded border border-transparent bg-transparent px-2 py-1.5 text-small font-medium text-ink hover:border-line-strong focus:border-primary-500 focus:bg-white focus:outline-none" />
                          </td>
                          <td className="px-3 py-2.5">
                            <input aria-label="Material" value={l.material} onChange={(e) => setLines((x) => x.map((it) => (it.id === l.id ? { ...it, material: e.target.value } : it)))} className="w-full rounded border border-transparent bg-transparent px-2 py-1.5 text-caption text-muted hover:border-line-strong focus:border-primary-500 focus:bg-white focus:text-slate-700 focus:outline-none" />
                          </td>
                          <td className="px-3 py-2.5 text-right">
                            <input type="number" min={1} aria-label="Quantity" value={l.qty} onChange={(e) => setLines((x) => x.map((it) => (it.id === l.id ? { ...it, qty: Math.max(1, Number(e.target.value)) } : it)))} className="h-9 w-20 rounded border border-line-strong bg-white px-2 text-right text-small focus:border-primary-500 focus:outline-none" />
                          </td>
                          <td className="px-3 py-2.5 text-right">
                            <input type="number" min={0} aria-label="Unit price" value={l.price} onChange={(e) => setLines((x) => x.map((it) => (it.id === l.id ? { ...it, price: Math.max(0, Number(e.target.value)) } : it)))} className="h-9 w-32 rounded border border-line-strong bg-white px-2 text-right text-small focus:border-primary-500 focus:outline-none" />
                          </td>
                          <td className="px-3 py-2.5 text-right text-small font-semibold text-ink">{fmt(l.qty * l.price)}</td>
                          <td className="px-4 py-2.5 text-right">
                            <button aria-label="Remove line" onClick={() => { setLines((x) => x.filter((it) => it.id !== l.id)); note("removed line: " + l.name); }} className="grid h-7 w-7 place-items-center rounded-control text-subtle transition-colors hover:bg-danger-soft hover:text-danger-strong">
                              <IconTrash size={14} />
                            </button>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
                <div className="flex items-center justify-between px-5 py-3.5 lg:px-6">
                  <Button variant="secondary" size="sm" onClick={() => setLines((x) => [...x, { id: Date.now() + Math.random(), name: "Custom item", material: "Specify material & finish", qty: 1, price: 0 }])}>
                    <Plus size={14} /> Add line
                  </Button>
                  <span className="text-caption text-subtle">Discount {discountPct}% · Tax {taxPct}% applied on totals</span>
                </div>
              </>
            )}
          </Card>

          <div className="grid grid-cols-1 gap-4 lg:grid-cols-2">
            <Card>
              <CardHeader title="Discount / Tax / Shipping" />
              <div className="grid grid-cols-3 gap-3">
                <Field label="Discount %"><Input type="number" value={discountPct} onChange={(e) => setDiscountPct(Number(e.target.value) || 0)} /></Field>
                <Field label="Tax %"><Input type="number" value={taxPct} onChange={(e) => setTaxPct(Number(e.target.value) || 0)} /></Field>
                <Field label="Shipping"><Input type="number" value={shipping} onChange={(e) => setShipping(Number(e.target.value) || 0)} /></Field>
              </div>
            </Card>
            <Card>
              <CardHeader title="Terms" description="Shown on the client-facing document" />
              <Textarea rows={3} defaultValue="50% advance with approval, 40% at production milestone, 10% on installation sign-off. Production window 21–28 working days. Woodex 2-year structural warranty included." />
            </Card>
          </div>
        </div>

        <div className="space-y-4">
          <Card className="sticky top-20 z-20">
            <CardHeader title="Totals" />
            <dl className="space-y-2 text-small">
              <div className="flex justify-between"><dt className="text-muted">Subtotal</dt><dd className="font-medium text-ink">{fmt(totals.subtotal)}</dd></div>
              <div className="flex justify-between"><dt className="text-muted">Discount ({discountPct}%)</dt><dd className="font-medium text-danger-strong">− {fmt(totals.discount)}</dd></div>
              <div className="flex justify-between"><dt className="text-muted">Tax ({taxPct}%)</dt><dd className="font-medium text-ink">{fmt(totals.tax)}</dd></div>
              <div className="flex justify-between"><dt className="text-muted">Delivery & installation</dt><dd className="font-medium text-ink">{fmt(shipping)}</dd></div>
            </dl>
            <div className="mt-4 rounded-card bg-primary-500 p-4 text-white shadow-cta">
              <p className="text-caption font-medium tracking-wide uppercase opacity-90">Quotation total</p>
              <p className="mt-1 text-display tracking-tight">{fmt(totals.total)}</p>
              <p className="mt-0.5 text-caption opacity-90">incl. tax & logistics · ≈ ${Math.round(totals.total / 278).toLocaleString()}</p>
            </div>
            <div className="mt-4 space-y-2" role="group" aria-label="Quotation actions">
              <Button className="w-full" variant="secondary" disabled={busy} onClick={() => advance("Draft", "Draft saved to backend")}><Save size={15} /> Save Draft</Button>
              <Button className="w-full" disabled={busy || status === "Sent"} onClick={() => advance(status === "Draft" ? "Review" : "Approval", status === "Draft" ? "Submitted for internal review" : "Quotation approved")}><Check size={15} /> {status === "Draft" ? "Submit for Approval" : "Approve"}</Button>
              <Button className="w-full" disabled={busy || status !== "Approval"} onClick={() => advance("Sent", "Sent to client")}><Send size={15} /> Send to Client</Button>
              {status === "Sent" && <p className="pt-1 text-center text-caption text-success-strong">✓ Sent — customer views the same live record</p>}
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
                    <span className={cn("z-10 grid h-6 w-6 shrink-0 place-items-center rounded-full border text-[10px] font-bold", state === "done" && "border-primary-500 bg-primary-500 text-white", state === "current" && "border-primary-500 bg-white text-primary-700", state === "todo" && "border-line-strong bg-white text-subtle")}>{state === "done" ? "✓" : i + 1}</span>
                    {i < lifecycle.length - 1 && <span className={cn("absolute top-6 bottom-0 left-3 w-px", i < activeIdx ? "bg-primary-500" : "bg-slate-200")} aria-hidden />}
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
                    <span className="truncate text-slate-700"><strong className="font-semibold text-ink">{a.who}</strong> {a.action}</span>
                    <span className="shrink-0 text-subtle">{a.time}</span>
                  </li>
                ))}
              </ul>
            </div>
          </Card>
        </div>
      </div>

      {/* Live catalog picker — searches the same 205-SKU dataset the storefront sells */}
      <Modal open={catalogOpen} onClose={() => setCatalogOpen(false)} title="Add from Catalog" description="Live from the shared backend" className="max-w-2xl" footer={null}>
        <div onClick={(e) => e.stopPropagation()}>
          <div className="relative">
            <Search size={15} className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-subtle" />
            <input value={catalogQuery} onChange={(e) => setCatalogQuery(e.target.value)} placeholder="Search products…" className="h-10 w-full rounded-control border border-line-strong pl-9 text-small focus:border-primary-500 focus:outline-none" autoFocus />
          </div>
          <ul className="scrollbar-slim mt-3 max-h-80 divide-y divide-slate-100 overflow-y-auto rounded-card border border-line">
            {(catalog.data?.items ?? [])
              .filter((p) => catalogQuery.trim() === "" || (p.name + " " + (p.subcategory ?? "")).toLowerCase().includes(catalogQuery.toLowerCase()))
              .slice(0, 40)
              .map((p) => (
                <li key={p.id} className="flex items-center gap-3 px-3 py-2.5 transition-colors hover:bg-primary-50/50">
                  <img src={"/img/" + p.images[0]} alt="" loading="lazy" className="h-11 w-14 shrink-0 rounded border border-line object-cover" />
                  <span className="min-w-0 flex-1">
                    <span className="block truncate text-small font-semibold text-ink">{p.name}</span>
                    <span className="block truncate text-caption text-muted capitalize">{p.subcategory?.replace(/-/g, " ")} · {p.inStock ? "in stock" : "made to order"}</span>
                  </span>
                  <span className="text-small font-semibold text-ink">{fmt(p.price)}</span>
                  <Button size="sm" onClick={() => addProduct(p)}><Plus size={13} /> Add</Button>
                </li>
              ))}
            {!catalog.data && (
              <li className="px-3 py-8"><SkeletonRows rows={3} /></li>
            )}
            {catalog.data && (catalog.data.items ?? []).filter((p) => catalogQuery.trim() === "" || p.name.toLowerCase().includes(catalogQuery.toLowerCase())).length === 0 && (
              <li className="px-3 py-8 text-center text-caption text-subtle">No products match “{catalogQuery}”.</li>
            )}
          </ul>
        </div>
      </Modal>
    </div>
  );
}


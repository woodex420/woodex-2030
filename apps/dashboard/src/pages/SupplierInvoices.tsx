import { useMemo, useState } from "react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge, StatusBadge } from "@/components/ui/Badge";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui/States";
import { Field, Input, Select, Textarea } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { useToast } from "@/components/ui/Toast";
import { useApi, mutate, fmtPKR } from "@/lib/api";
import { Plus, RefreshCw, Receipt, AlertTriangle, Banknote, CheckCircle, Trash2, ShieldCheck } from "@/icons";

/* Supplier invoices — money out, gated by a three-way match.
 *
 * The screen's job is not to list bills. It is to make the match impossible to
 * miss: ordered (PO) vs received (GRN) vs billed (this document). A bill that
 * disagrees is recorded but blocked from payment, and this page shows exactly
 * which quantity and which line caused it. Hiding that behind a status word
 * would defeat the module — the whole point is that a finance clerk sees the
 * disagreement before money leaves.
 *
 * The payment ceiling is the server's (si_paid_within_total). This form asks
 * for a number and shows the balance; if the number is too large the server
 * refuses and its sentence is displayed verbatim. */

type SiLine = {
  id: number; purchaseOrderLineId: number | null; lineNo: number;
  description: string | null; qtyBilled: number; unitCost: number; lineTotal: number;
};
type Variance = { code: string; message: string; lineId?: number | null; field?: string } | string;
type SI = {
  id: number; ref: string; supplierId: number; purchaseOrderId: number | null;
  supplierRef: string; invoiceDate: string; dueDate: string | null;
  subtotal: number; tax: number; total: number; paid: number; balance: number;
  status: string; matchState: string | null; match: { ok?: boolean; findings?: Variance[]; checkedAt?: string } | null;
  note: string | null; lines?: SiLine[];
};
type Supplier = { id: number; code: string; name: string; active: boolean };
type PoLine = {
  id: number; lineNo: number; itemKind: string; itemId: string;
  qtyOrdered: number; qtyReceived: number; qtyOpen: number; unitCost: number;
};
type PO = { id: number; ref: string; supplierId: number; status: string; lines: PoLine[] };

type Draft = { purchaseOrderLineId: number; description: string; qtyBilled: string; unitCost: string };

const today = () => new Date().toISOString().slice(0, 10);
const findingText = (f: Variance) => (typeof f === "string" ? f : `${f.message ?? f.code}${f.field ? ` (${f.field})` : ""}`);

export default function SupplierInvoices() {
  const { push } = useToast();
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [detail, setDetail] = useState<SI | null>(null);
  const [payFor, setPayFor] = useState<SI | null>(null);
  const [payAmount, setPayAmount] = useState("");
  const [payErr, setPayErr] = useState<string | null>(null);
  const [paying, setPaying] = useState(false);
  const [filter, setFilter] = useState("all");

  const [supplierId, setSupplierId] = useState("");
  const [poId, setPoId] = useState("");
  const [supplierRef, setSupplierRef] = useState("");
  const [invoiceDate, setInvoiceDate] = useState(today());
  const [dueDate, setDueDate] = useState("");
  const [taxPct, setTaxPct] = useState("0");
  const [note, setNote] = useState("");
  const [lines, setLines] = useState<Draft[]>([]);

  const list = useApi<{ total: number; payable: number; variance: number; items: SI[] }>("/api/supplier-invoices", 20000);
  const summary = useApi<{ payable: number; bills: number; variance: number; varianceValue: number; overdue: number; overdueValue: number }>("/api/supplier-invoices/summary", 20000);
  const suppliers = useApi<{ items: Supplier[] }>("/api/suppliers?active=true");
  const pos = useApi<{ items: PO[] }>("/api/purchase-orders");

  const supplierName = (id: number) => (suppliers.data?.items ?? []).find((s) => s.id === id)?.name ?? `Supplier #${id}`;
  const posForSupplier = (pos.data?.items ?? []).filter((p) => !supplierId || p.supplierId === Number(supplierId));
  const selectedPo = posForSupplier.find((p) => String(p.id) === poId) ?? null;

  /* Billing starts from what was RECEIVED, not what was ordered. Prefilling the
     ordered quantity would hand the clerk a wrong number that looks right, and
     the match would then flag a variance the operator created by accepting a
     default. */
  const seedFromPo = (po: PO) => {
    const receivable = po.lines.filter((l) => l.qtyReceived > 0);
    setLines(receivable.map((l) => ({
      purchaseOrderLineId: l.id,
      description: l.itemId,
      qtyBilled: String(l.qtyReceived),
      unitCost: String(l.unitCost || ""),
    })));
  };

  const subtotal = useMemo(() => lines.reduce((n, l) => n + (Number(l.qtyBilled) || 0) * (Number(l.unitCost) || 0), 0), [lines]);
  const taxValue = Math.round(subtotal * (Number(taxPct) || 0)) / 100;
  const total = subtotal + taxValue;

  const reset = () => {
    setSupplierId(""); setPoId(""); setSupplierRef(""); setInvoiceDate(today()); setDueDate("");
    setTaxPct("0"); setNote(""); setLines([]); setErr(null);
  };

  const create = async () => {
    if (saving) return;
    setErr(null);
    if (!supplierId) { setErr("Choose the supplier who sent this bill."); return; }
    if (!supplierRef.trim()) { setErr("Enter the number printed on the supplier's own document — it is what prevents the same bill being entered twice."); return; }
    const prepared = lines
      .filter((l) => l.purchaseOrderLineId && Number(l.qtyBilled) > 0)
      .map((l) => ({
        purchaseOrderLineId: l.purchaseOrderLineId,
        description: l.description || null,
        qtyBilled: Number(l.qtyBilled),
        unitCost: Number(l.unitCost) || 0,
      }));
    if (!prepared.length) { setErr("A bill needs at least one line with a quantity."); return; }
    setSaving(true);
    try {
      const inv = await mutate<SI>("/api/supplier-invoices", {
        supplierId: Number(supplierId),
        purchaseOrderId: selectedPo?.id ?? null,
        supplierRef: supplierRef.trim(),
        invoiceDate, dueDate: dueDate || null,
        tax: taxValue, note: note || null,
        lines: prepared,
      }, "POST");
      const findings = inv.match?.findings ?? [];
      if (inv.matchState === "variance" || findings.length) {
        push({
          tone: "warning",
          title: `${inv.ref} recorded with a variance`,
          desc: `${findings.length} finding(s). Payment is blocked until this is reviewed.`,
        });
      } else {
        push({ tone: "success", title: `${inv.ref} recorded`, desc: inv.matchState === "matched" ? "Three-way match agreed. Post it to pay." : "Saved as a draft." });
      }
      setOpen(false); reset(); list.reload(); summary.reload();
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    } finally {
      setSaving(false);
    }
  };

  const move = async (si: SI, status: string) => {
    try {
      await mutate(`/api/supplier-invoices/${si.id}`, { status });
      push({ tone: status === "cancelled" ? "info" : "success", title: `${si.ref} → ${status}` });
      list.reload(); summary.reload();
      if (detail?.id === si.id) setDetail({ ...detail, status });
    } catch (e) {
      push({ tone: "danger", title: "Could not update the bill", desc: e instanceof Error ? e.message : String(e) });
    }
  };

  const openPay = (si: SI) => {
    setPayFor(si); setPayAmount(String(si.balance)); setPayErr(null);
  };

  const pay = async () => {
    if (!payFor || paying) return;
    setPayErr(null);
    const amount = Number(payAmount);
    if (!Number.isFinite(amount) || amount <= 0) { setPayErr("Enter an amount above zero."); return; }
    if (amount > payFor.balance) {
      setPayErr(`That is more than the ${fmtPKR(payFor.balance)} still owed on this bill.`);
      return;
    }
    setPaying(true);
    try {
      await mutate(`/api/supplier-invoices/${payFor.id}/payments`, { amount, method: "Bank transfer" }, "POST");
      push({ tone: "success", title: `${fmtPKR(amount)} paid`, desc: `${payFor.ref} — recorded against the bill, audited.` });
      setPayFor(null); list.reload(); summary.reload();
    } catch (e) {
      setPayErr(e instanceof Error ? e.message : String(e));
    } finally {
      setPaying(false);
    }
  };

  const openDetail = async (si: SI) => {
    try {
      const full = await mutate<SI>(`/api/supplier-invoices/${si.id}`, undefined, "GET");
      setDetail(full);
    } catch (e) {
      push({ tone: "danger", title: "Could not load the bill", desc: e instanceof Error ? e.message : String(e) });
    }
  };

  const items = (list.data?.items ?? []).filter((r) => filter === "all" || (filter === "variance" ? r.matchState === "variance" : r.status === filter));

  if (list.error)
    return (
      <div>
        <PageHeader title="Supplier Invoices" />
        <ErrorState title="Payables API unreachable" description="The supplier invoice endpoint did not answer." debug={list.error} onRetry={list.reload} />
      </div>
    );

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Operations", to: "/operations" }, { label: "Supplier Invoices" }]}
        title="Supplier Invoices"
        description="Bills from suppliers, checked against what was ordered and what actually arrived before any money moves."
        actions={
          <>
            <Button variant="secondary" onClick={() => { list.reload(); summary.reload(); }}><RefreshCw size={15} /> Refresh</Button>
            <Button onClick={() => { reset(); setOpen(true); }}><Plus size={15} /> Record a bill</Button>
          </>
        }
      />

      <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-4">
        <Card className="py-4">
          <p className="text-caption font-medium uppercase text-subtle">Payable</p>
          <p className="mt-1 text-h2 text-ink">{fmtPKR(summary.data?.payable ?? 0)}</p>
          <p className="mt-1 text-caption text-subtle">{summary.data?.bills ?? 0} open bills</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption font-medium uppercase text-subtle">Blocked by variance</p>
          <p className="mt-1 text-h2 text-ink">{summary.data?.variance ?? 0}</p>
          <p className="mt-1 text-caption text-subtle">{fmtPKR(summary.data?.varianceValue ?? 0)} cannot be paid yet</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption font-medium uppercase text-subtle">Overdue</p>
          <p className="mt-1 text-h2 text-ink">{summary.data?.overdue ?? 0}</p>
          <p className="mt-1 text-caption text-danger-strong">{fmtPKR(summary.data?.overdueValue ?? 0)} past due date</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption font-medium uppercase text-subtle">Bills recorded</p>
          <p className="mt-1 text-h2 text-ink">{list.data?.total ?? 0}</p>
        </Card>
      </div>

      <Card className="mb-3 flex flex-wrap items-center gap-2 py-3">
        {[
          { key: "all", label: "All" },
          { key: "variance", label: "Variances" },
          { key: "draft", label: "Draft" },
          { key: "posted", label: "Posted" },
          { key: "paid", label: "Paid" },
        ].map((f) => (
          <Button key={f.key} size="sm" variant={filter === f.key ? "primary" : "secondary"} onClick={() => setFilter(f.key)}>{f.label}</Button>
        ))}
      </Card>

      <Card className="overflow-hidden p-0">
        <table className="w-full text-left">
          <thead className="border-b border-line bg-slate-50 text-caption uppercase text-subtle">
            <tr>
              <th className="px-4 py-2.5 font-semibold">Ref</th>
              <th className="px-4 py-2.5 font-semibold">Supplier bill</th>
              <th className="px-4 py-2.5 font-semibold">Status</th>
              <th className="px-4 py-2.5 font-semibold">Match</th>
              <th className="px-4 py-2.5 font-semibold text-right">Total</th>
              <th className="px-4 py-2.5 font-semibold text-right">Balance</th>
              <th className="px-4 py-2.5 font-semibold text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {list.loading && items.length === 0 && (
              <tr><td colSpan={7} className="p-4"><Skeleton className="h-10" /><Skeleton className="mt-2 h-10" /></td></tr>
            )}
            {items.map((r) => (
              <tr key={r.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50/60">
                <td className="px-4 py-3">
                  <button className="font-mono text-caption font-semibold text-primary-700 hover:underline" onClick={() => void openDetail(r)}>{r.ref}</button>
                </td>
                <td className="px-4 py-3">
                  <p className="text-small font-semibold text-ink">{supplierName(r.supplierId)}</p>
                  <p className="text-caption text-subtle">their ref {r.supplierRef}</p>
                </td>
                <td className="px-4 py-3"><StatusBadge status={r.status} /></td>
                <td className="px-4 py-3">
                  {r.matchState === "variance"
                    ? <Badge tone="danger" dot={false}><AlertTriangle size={11} className="mr-1 inline" />variance</Badge>
                    : r.matchState === "matched"
                      ? <Badge tone="success" dot={false}><CheckCircle size={11} className="mr-1 inline" />matched</Badge>
                      : <span className="text-caption text-subtle">—</span>}
                </td>
                <td className="px-4 py-3 text-right text-small text-slate-700">{fmtPKR(r.total)}</td>
                <td className="px-4 py-3 text-right text-small font-semibold text-ink">{fmtPKR(r.balance)}</td>
                <td className="px-4 py-3">
                  <div className="flex justify-end gap-1.5">
                    {r.status === "draft" && (
                      <Button size="sm" onClick={() => move(r, "posted")} disabled={r.matchState === "variance"}>Post</Button>
                    )}
                    {r.status === "posted" && r.balance > 0 && (
                      <Button size="sm" onClick={() => openPay(r)} disabled={r.matchState === "variance"}>
                        <Banknote size={13} /> Pay
                      </Button>
                    )}
                    {r.status !== "cancelled" && r.status !== "paid" && r.paid === 0 && (
                      <Button size="sm" variant="secondary" onClick={() => move(r, "cancelled")}>Cancel</Button>
                    )}
                    {r.status === "paid" && <span className="text-caption text-success-strong">settled</span>}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {!list.loading && items.length === 0 && (
          <EmptyState
            icon={<Receipt size={20} />}
            title={filter === "all" ? "No supplier bills yet" : "Nothing in this filter"}
            description={filter === "all" ? "Record a bill from a supplier. It is matched against the purchase order and goods receipt automatically." : "Try a different filter."}
            action={filter === "all" ? <Button onClick={() => { reset(); setOpen(true); }}><Plus size={15} /> Record a bill</Button> : undefined}
          />
        )}
      </Card>

      {/* ---------- record a bill ---------- */}
      <Modal open={open} onClose={() => setOpen(false)} title="Record a supplier bill" className="max-w-3xl"
        description="Enter what the supplier charged. It is checked against the order and the delivery as soon as it is saved.">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <Field label="Supplier" required>
            <Select value={supplierId} onChange={(e) => { setSupplierId(e.target.value); setPoId(""); setLines([]); }}>
              <option value="">Choose…</option>
              {(suppliers.data?.items ?? []).map((s) => <option key={s.id} value={s.id}>{s.name} ({s.code})</option>)}
            </Select>
          </Field>
          <Field label="Against purchase order" hint="Optional, needed for a three-way match">
            <Select value={poId} onChange={(e) => {
              setPoId(e.target.value);
              const po = posForSupplier.find((p) => String(p.id) === e.target.value);
              if (po) seedFromPo(po); else setLines([]);
            }}>
              <option value="">No purchase order</option>
              {posForSupplier.map((p) => <option key={p.id} value={p.id}>{p.ref} · {p.status.replace("_", " ")}</option>)}
            </Select>
          </Field>
          <Field label="Their bill number" required hint="Unique per supplier">
            <Input value={supplierRef} onChange={(e) => setSupplierRef(e.target.value)} placeholder="INV-2291" />
          </Field>
          <Field label="Invoice date">
            <Input type="date" value={invoiceDate} onChange={(e) => setInvoiceDate(e.target.value)} />
          </Field>
          <Field label="Due date">
            <Input type="date" value={dueDate} onChange={(e) => setDueDate(e.target.value)} />
          </Field>
          <Field label="Tax %">
            <Input type="number" min={0} step="0.01" value={taxPct} onChange={(e) => setTaxPct(e.target.value)} />
          </Field>
        </div>

        <div className="mt-4">
          <p className="mb-1.5 text-small font-medium text-slate-700">Billed lines</p>
          {lines.length === 0 ? (
            <p className="rounded-control border border-dashed border-line-strong px-3 py-4 text-center text-caption text-subtle">
              Choose a purchase order to pull in what was received, or record the bill without one.
            </p>
          ) : (
            <table className="w-full text-left text-caption">
              <thead className="text-subtle uppercase">
                <tr>
                  <th className="py-1.5 font-semibold">Item</th>
                  <th className="py-1.5 text-right font-semibold">Qty</th>
                  <th className="py-1.5 text-right font-semibold">Unit cost</th>
                  <th className="py-1.5 text-right font-semibold">Line</th>
                  <th className="py-1.5" />
                </tr>
              </thead>
              <tbody>
                {lines.map((l, i) => {
                  const poLine = selectedPo?.lines.find((x) => x.id === l.purchaseOrderLineId);
                  return (
                    <tr key={l.purchaseOrderLineId} className="border-t border-slate-200">
                      <td className="py-2 font-mono">{l.description}</td>
                      <td className="py-2 text-right">
                        <input type="number" min={1} step={1} value={l.qtyBilled}
                          className="h-9 w-20 rounded-control border border-line-strong px-2 text-right"
                          onChange={(e) => setLines((c) => c.map((x, idx) => idx === i ? { ...x, qtyBilled: e.target.value } : x))} />
                        {poLine && <span className="ml-1 text-subtle">/ {poLine.qtyReceived} recv</span>}
                      </td>
                      <td className="py-2 text-right">
                        <input type="number" min={0} step="0.01" value={l.unitCost}
                          className="h-9 w-24 rounded-control border border-line-strong px-2 text-right"
                          onChange={(e) => setLines((c) => c.map((x, idx) => idx === i ? { ...x, unitCost: e.target.value } : x))} />
                      </td>
                      <td className="py-2 text-right font-semibold">{fmtPKR((Number(l.qtyBilled) || 0) * (Number(l.unitCost) || 0))}</td>
                      <td className="py-2 text-right">
                        <Button variant="secondary" size="sm" aria-label="Remove line"
                          onClick={() => setLines((c) => c.filter((_, idx) => idx !== i))}><Trash2 size={13} /></Button>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          )}
          <div className="mt-2 flex justify-end gap-6 text-caption">
            <span className="text-subtle">Subtotal <b className="text-ink">{fmtPKR(subtotal)}</b></span>
            <span className="text-subtle">Tax <b className="text-ink">{fmtPKR(taxValue)}</b></span>
            <span className="text-subtle">Total <b className="text-ink">{fmtPKR(total)}</b></span>
          </div>
        </div>

        <Field label="Note" className="mt-3">
          <Textarea value={note} onChange={(e) => setNote(e.target.value)} />
        </Field>

        {err && <p role="alert" className="mt-3 rounded-control border border-danger/30 bg-danger-soft px-3 py-2 text-caption font-semibold text-danger-strong">{err}</p>}

        <div className="mt-4 flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setOpen(false)} disabled={saving}>Cancel</Button>
          <Button onClick={create} disabled={saving}>{saving ? "Saving…" : "Record bill"}</Button>
        </div>
      </Modal>

      {/* ---------- detail with the match ---------- */}
      <Modal open={Boolean(detail)} onClose={() => setDetail(null)} title={detail ? `${detail.ref} — ${supplierName(detail.supplierId)}` : ""} className="max-w-2xl">
        {detail && (
          <>
            <div className="mb-3 flex flex-wrap items-center gap-2">
              <StatusBadge status={detail.status} />
              {detail.matchState === "variance"
                ? <Badge tone="danger" dot={false}>variance — payment blocked</Badge>
                : detail.matchState === "matched"
                  ? <Badge tone="success" dot={false}>three-way match agreed</Badge>
                  : <Badge tone="neutral" dot={false}>not matched</Badge>}
              <span className="text-caption text-subtle">their ref {detail.supplierRef}</span>
            </div>

            {(detail.match?.findings ?? []).length > 0 && (
              <div className="mb-3 rounded-control border border-danger/30 bg-danger-soft p-3">
                <p className="mb-1 flex items-center gap-1.5 text-small font-semibold text-danger-strong">
                  <AlertTriangle size={14} /> {detail.match!.findings!.length} finding(s)
                </p>
                <ul className="list-disc space-y-1 pl-5 text-caption text-danger-strong">
                  {detail.match!.findings!.map((f, i) => <li key={i}>{findingText(f)}</li>)}
                </ul>
                <p className="mt-2 text-caption text-danger-strong">Paying this bill requires an explicit override on the API; this screen will not do it for you.</p>
              </div>
            )}

            {(detail.match?.findings ?? []).length === 0 && detail.matchState === "matched" && (
              <p className="mb-3 flex items-center gap-1.5 rounded-control border border-success/30 bg-success-soft px-3 py-2 text-caption text-success-strong">
                <ShieldCheck size={14} /> Ordered, received and billed agree. Safe to pay.
              </p>
            )}

            <table className="w-full text-left text-caption">
              <thead className="text-subtle uppercase">
                <tr>
                  <th className="py-1.5 font-semibold">Item</th>
                  <th className="py-1.5 text-right font-semibold">Qty billed</th>
                  <th className="py-1.5 text-right font-semibold">Unit cost</th>
                  <th className="py-1.5 text-right font-semibold">Line</th>
                </tr>
              </thead>
              <tbody>
                {(detail.lines ?? []).map((l) => (
                  <tr key={l.id} className="border-t border-slate-200">
                    <td className="py-2 font-mono">{l.description ?? `PO line ${l.purchaseOrderLineId}`}</td>
                    <td className="py-2 text-right">{l.qtyBilled}</td>
                    <td className="py-2 text-right">{fmtPKR(l.unitCost)}</td>
                    <td className="py-2 text-right font-semibold">{fmtPKR(l.lineTotal)}</td>
                  </tr>
                ))}
              </tbody>
            </table>

            <div className="mt-3 space-y-1 border-t border-line pt-2 text-caption">
              <div className="flex justify-between"><span className="text-subtle">Subtotal</span><b className="text-ink">{fmtPKR(detail.subtotal)}</b></div>
              <div className="flex justify-between"><span className="text-subtle">Tax</span><b className="text-ink">{fmtPKR(detail.tax)}</b></div>
              <div className="flex justify-between"><span className="text-subtle">Total</span><b className="text-ink">{fmtPKR(detail.total)}</b></div>
              <div className="flex justify-between"><span className="text-subtle">Paid</span><b className="text-ink">{fmtPKR(detail.paid)}</b></div>
              <div className="flex justify-between text-small"><span className="font-semibold text-ink">Balance</span><b className="text-ink">{fmtPKR(detail.balance)}</b></div>
            </div>

            <div className="mt-4 flex justify-end gap-2">
              {detail.status === "draft" && <Button onClick={() => { void move(detail, "posted"); setDetail(null); }} disabled={detail.matchState === "variance"}>Post bill</Button>}
              {detail.status === "posted" && detail.balance > 0 && <Button onClick={() => { setDetail(null); openPay(detail); }} disabled={detail.matchState === "variance"}><Banknote size={15} /> Pay</Button>}
              <Button variant="secondary" onClick={() => setDetail(null)}>Close</Button>
            </div>
          </>
        )}
      </Modal>

      {/* ---------- pay ---------- */}
      <Modal open={Boolean(payFor)} onClose={() => setPayFor(null)} title={payFor ? `Pay ${payFor.ref}` : ""}>
        {payFor && (
          <>
            <p className="mb-3 text-small text-muted">
              {supplierName(payFor.supplierId)} · balance <b className="text-ink">{fmtPKR(payFor.balance)}</b> of {fmtPKR(payFor.total)}
            </p>
            <Field label="Amount" required hint="Cannot exceed the balance — the database enforces this too">
              <Input type="number" min={0} step="0.01" value={payAmount} onChange={(e) => setPayAmount(e.target.value)} />
            </Field>
            {payErr && <p role="alert" className="mt-3 rounded-control border border-danger/30 bg-danger-soft px-3 py-2 text-caption font-semibold text-danger-strong">{payErr}</p>}
            <div className="mt-4 flex justify-end gap-2">
              <Button variant="secondary" onClick={() => setPayFor(null)} disabled={paying}>Cancel</Button>
              <Button onClick={pay} disabled={paying}>{paying ? "Recording…" : "Record payment"}</Button>
            </div>
          </>
        )}
      </Modal>
    </div>
  );
}

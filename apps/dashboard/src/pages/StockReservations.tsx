import { useState } from "react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui/States";
import { Field, Input, Select } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { useToast } from "@/components/ui/Toast";
import { useApi, mutate } from "@/lib/api";
import { Plus, RefreshCw, Lock, ShieldCheck, Package } from "@/icons";

/* Stock reservations — what is promised but not yet gone.
 *
 * A hold is a claim, not a movement. It never touches stock_qty or the ledger;
 * it only reduces what a caller may promise. That distinction is the whole
 * design: if a hold decremented stock, cancelling an order would have to
 * reconstruct a number that was never subtracted, and the ledger would fill with
 * entries for things that did not happen.
 *
 * Because a hold is not a movement, releasing one is a status change rather
 * than a compensating entry — but it is timestamped, so "when did we stop
 * holding these" is still answerable.
 *
 * The number that matters is `available`: on hand minus everything held. This
 * page exists because that number was previously uncomputable. */

type Reservation = {
  id: number; productId: string; productName: string | null; qty: number;
  refType: string; refId: number | null; status: string;
  expiresAt: string | null; releasedAt: string | null; createdAt: string;
};
type Product = { id: string; name: string; stockQty: number };

const STATUS_TONE: Record<string, "info" | "success" | "neutral"> = {
  held: "info", consumed: "success", released: "neutral",
};

export default function StockReservations() {
  const { push } = useToast();
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState("held");
  const [form, setForm] = useState({ productId: "", qty: "", refType: "order", refId: "" });

  const res = useApi<{ total: number; held: number; items: Reservation[] }>(
    `/api/stock-reservations${statusFilter ? `?status=${statusFilter}` : ""}`, 20000);
  const products = useApi<{ items: Product[] }>("/api/products", 120000);

  const items = res.data?.items ?? [];
  const totalHeld = items.filter((i) => i.status === "held").reduce((a, i) => a + i.qty, 0);

  /* Availability for the selected product, so the form can show "you can hold
     at most N" while the number is being typed rather than after a 409. */
  const avail = useApi<{ onHand: number; held: number; available: number }>(
    form.productId ? `/api/stock-reservations/availability?product_id=${encodeURIComponent(form.productId)}` : null,
    10000);

  const hold = async () => {
    if (saving) return;
    setErr(null);
    if (!form.productId) { setErr("Choose a product."); return; }
    const qty = Number(form.qty);
    if (!Number.isInteger(qty) || qty <= 0) { setErr("Quantity must be a positive whole number."); return; }
    if (!form.refType.trim()) { setErr("Say what this hold is for — a hold with no reference cannot be released."); return; }
    setSaving(true);
    try {
      const out = await mutate<{ reservation: Reservation; available: number }>("/api/stock-reservations", {
        productId: form.productId, qty, refType: form.refType.trim(),
        refId: form.refId ? Number(form.refId) : null,
      }, "POST");
      push({
        tone: "success",
        title: `${qty} unit(s) held`,
        desc: `${out.available} still available after this hold.`,
      });
      setOpen(false);
      setForm({ productId: "", qty: "", refType: "order", refId: "" });
      res.reload();
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    } finally {
      setSaving(false);
    }
  };

  const settle = async (r: Reservation, status: "released" | "consumed") => {
    const desc = status === "consumed"
      ? "The order shipped against this hold. No ledger row is written — the sale writes it."
      : "The hold is dropped and the units become available again.";
    try {
      await mutate(`/api/stock-reservations/${r.id}`, { status });
      push({ tone: "info", title: status === "consumed" ? "Hold consumed" : "Hold released", desc });
      res.reload();
    } catch (e) {
      push({ tone: "danger", title: "Could not settle the hold", desc: e instanceof Error ? e.message : String(e) });
    }
  };

  if (res.error)
    return (
      <div>
        <PageHeader title="Reservations" />
        <ErrorState title="Reservations API unreachable" description="The inventory backend did not answer." debug={res.error} onRetry={res.reload} />
      </div>
    );

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Operations", to: "/operations" }, { label: "Reservations" }]}
        title="Stock Reservations"
        description="Stock promised to an order but not yet shipped. Holds reduce availability without moving a single unit."
        actions={
          <>
            <Button variant="secondary" onClick={res.reload}><RefreshCw size={15} /> Refresh</Button>
            <Button onClick={() => { setErr(null); setOpen(true); }}><Plus size={15} /> Hold stock</Button>
          </>
        }
      />

      <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <Card className="py-4">
          <p className="text-caption uppercase text-subtle">Live holds</p>
          <p className="mt-1 text-h2 font-semibold text-ink">{res.loading ? "—" : res.data?.held ?? 0}</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption uppercase text-subtle">Units held</p>
          <p className="mt-1 text-h2 font-semibold text-ink">{totalHeld}</p>
        </Card>
        <Card className="flex items-center gap-3 py-4">
          <ShieldCheck size={20} className="text-success-strong" />
          <div>
            <p className="text-caption uppercase text-subtle">Effect on stock</p>
            <p className="text-small font-semibold text-ink">None — holds never move the ledger</p>
          </div>
        </Card>
      </div>

      <Card className="mb-4 flex flex-wrap items-end gap-3 py-4">
        <Field label="Show">
          <Select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} className="min-w-[160px]">
            <option value="held">Live holds</option>
            <option value="">All reservations</option>
            <option value="consumed">Consumed</option>
            <option value="released">Released</option>
          </Select>
        </Field>
        <div className="ml-auto text-caption text-subtle">{res.loading ? "syncing…" : `${items.length} row(s)`}</div>
      </Card>

      <Card className="overflow-hidden p-0">
        <table className="w-full text-left">
          <thead className="border-b border-line bg-slate-50 text-caption uppercase text-subtle">
            <tr>
              <th className="px-4 py-2.5 font-semibold">Product</th>
              <th className="px-4 py-2.5 text-right font-semibold">Qty</th>
              <th className="px-4 py-2.5 font-semibold">Held for</th>
              <th className="px-4 py-2.5 font-semibold">Status</th>
              <th className="px-4 py-2.5 font-semibold">Since</th>
              <th className="px-4 py-2.5 text-right font-semibold">Action</th>
            </tr>
          </thead>
          <tbody>
            {res.loading && items.length === 0 && (
              <tr><td colSpan={6} className="p-4"><Skeleton className="h-10" /><Skeleton className="mt-2 h-10" /></td></tr>
            )}
            {items.map((r) => (
              <tr key={r.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50/60">
                <td className="px-4 py-3">
                  <p className="text-small font-semibold text-ink">{r.productName ?? r.productId}</p>
                  <p className="font-mono text-caption text-subtle">{r.productId}</p>
                </td>
                <td className="px-4 py-3 text-right text-small font-semibold text-ink">{r.qty}</td>
                <td className="px-4 py-3 text-small text-slate-700">
                  {r.refType}{r.refId !== null ? ` #${r.refId}` : ""}
                </td>
                <td className="px-4 py-3">
                  <Badge tone={STATUS_TONE[r.status] ?? "neutral"} dot={false}>{r.status}</Badge>
                </td>
                <td className="px-4 py-3 text-caption text-subtle">
                  {new Date(r.createdAt).toLocaleDateString()}
                  {r.releasedAt && <span className="block">settled {new Date(r.releasedAt).toLocaleDateString()}</span>}
                </td>
                <td className="px-4 py-3 text-right">
                  {r.status === "held" ? (
                    <div className="flex justify-end gap-2">
                      <Button size="sm" onClick={() => settle(r, "consumed")}>Mark shipped</Button>
                      <Button variant="secondary" size="sm" onClick={() => settle(r, "released")}>Release</Button>
                    </div>
                  ) : (
                    <span className="text-caption text-subtle">settled</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {!res.loading && items.length === 0 && (
          <EmptyState
            icon={<Lock size={20} />}
            title={statusFilter === "held" ? "No stock is being held" : "No reservations match"}
            description={statusFilter === "held"
              ? "Hold stock when an order is confirmed but not yet shipped. Availability falls; the ledger does not change."
              : "Clear the filter to see every reservation."}
            action={<Button onClick={() => setOpen(true)}><Plus size={15} /> Hold stock</Button>}
          />
        )}
      </Card>

      <Modal open={open} onClose={() => setOpen(false)} title="Hold stock for an order">
        <div className="space-y-3">
          <Field label="Product" required>
            <Select value={form.productId} onChange={(e) => setForm({ ...form, productId: e.target.value })}>
              <option value="">Choose a product…</option>
              {(products.data?.items ?? []).map((p) => (
                <option key={p.id} value={p.id}>{p.name}</option>
              ))}
            </Select>
          </Field>

          {form.productId && avail.data && (
            <div className="rounded-control border border-line bg-slate-50 px-3 py-2">
              <div className="flex items-center gap-2 text-small">
                <Package size={14} className="text-subtle" />
                <span className="text-slate-700">
                  <strong className="font-semibold text-ink">{avail.data.onHand}</strong> on hand
                  {avail.data.held > 0 && <> · <strong className="font-semibold text-ink">{avail.data.held}</strong> held</>}
                </span>
                <Badge tone={avail.data.available > 0 ? "success" : "danger"} dot={false}>
                  {avail.data.available} available
                </Badge>
              </div>
            </div>
          )}

          <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
            <Field label="Quantity" required>
              <Input type="number" min={1} value={form.qty} onChange={(e) => setForm({ ...form, qty: e.target.value })} placeholder="0" />
            </Field>
            <Field label="Reference type" required hint="What is this hold for?">
              <Select value={form.refType} onChange={(e) => setForm({ ...form, refType: e.target.value })}>
                <option value="order">Sales order</option>
                <option value="quote">Quotation</option>
                <option value="job_card">Job card</option>
                <option value="transfer">Transfer</option>
              </Select>
            </Field>
          </div>
          <Field label="Reference id" hint="Optional — the order number, if you have it">
            <Input value={form.refId} onChange={(e) => setForm({ ...form, refId: e.target.value })} placeholder="e.g. 4301" />
          </Field>
        </div>
        {err && <p role="alert" className="mt-3 rounded-control border border-danger/30 bg-danger-soft px-3 py-2 text-caption font-semibold text-danger-strong">{err}</p>}
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setOpen(false)} disabled={saving}>Cancel</Button>
          <Button onClick={hold} disabled={saving}>{saving ? "Holding…" : "Hold stock"}</Button>
        </div>
      </Modal>
    </div>
  );
}

import { useMemo, useState } from "react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui/States";
import { Field, Input } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { useToast } from "@/components/ui/Toast";
import { useApi, mutate, fmtPKR } from "@/lib/api";
import { RefreshCw, Truck, CheckCircle, ClipboardCheck } from "@/icons";

/* Goods receipts — where stock actually moves.
 *
 * This is the screen the whole ledger depends on: receiving a product writes a
 * `receipt` movement through applyStockMovement, and receiving a material
 * writes nothing because a finish has no quantity on hand. The UI mirrors that
 * distinction rather than hiding it: product lines show "adds to stock", material
 * lines show "recorded, no stock". An operator who does not know which will
 * happen cannot tell whether the numbers are right.
 *
 * Only orders the API considers receivable are offered. Showing an approved
 * order that the server would refuse is a trap, not a feature. */

type PoLine = {
  id: number; lineNo: number; itemKind: string; itemId: string;
  qtyOrdered: number; qtyReceived: number; qtyOpen: number; unitCost: number; lineTotal: number;
};
type Receivable = {
  id: number; ref: string; supplierId: number; status: string; value: number;
  lines: PoLine[]; qtyOpen: number;
};
type Receipt = {
  id: number; ref: string; purchaseOrderId: number; receivedAt: string;
  receivedBy: string | null; note: string | null; createdAt: string;
};

/* Mirrors PO_RECEIVABLE on the server (009). Kept as a literal because the UI
   needs it before the first request; if these ever diverge, the server wins and
   the request is refused — the client-side list is a convenience, not the rule. */
const RECEIVABLE = new Set(["approved", "sent", "partially_received"]);

export default function GoodsReceipts() {
  const { push } = useToast();
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [poId, setPoId] = useState("");
  const [qty, setQty] = useState<Record<number, string>>({});
  const [note, setNote] = useState("");
  const [receivedOn, setReceivedOn] = useState("");

  const pos = useApi<{ items: Receivable[] }>("/api/purchase-orders", 20000);
  const receipts = useApi<{ items: Receipt[] }>("/api/goods-receipts", 20000);

  const receivable = useMemo(
    () => (pos.data?.items ?? []).filter((p) => RECEIVABLE.has(p.status) && p.qtyOpen > 0),
    [pos.data]
  );
  const selected = receivable.find((p) => String(p.id) === poId) ?? null;

  const openLines = (selected?.lines ?? []).filter((l) => l.qtyOpen > 0);
  const totalUnits = Object.values(qty).reduce((n, v) => n + (Number(v) || 0), 0);

  const reset = () => { setPoId(""); setQty({}); setNote(""); setReceivedOn(""); setErr(null); };

  const receive = async () => {
    if (saving || !selected) return;
    setErr(null);
    const lines = openLines
      .map((l) => ({ purchaseOrderLineId: l.id, qtyReceived: Number(qty[l.id] ?? 0) }))
      .filter((l) => Number.isInteger(l.qtyReceived) && l.qtyReceived > 0);
    if (!lines.length) { setErr("Enter a quantity for at least one line."); return; }
    /* Check the ceiling here as well as on the server: the server returns 409
       with a good sentence, but the operator should never have to submit to
       discover that 30 on a line with 10 outstanding cannot work. */
    for (const l of lines) {
      const line = openLines.find((x) => x.id === l.purchaseOrderLineId)!;
      if (l.qtyReceived > line.qtyOpen) {
        setErr(`${line.itemId}: only ${line.qtyOpen} still outstanding (${line.qtyReceived} of ${line.qtyOrdered} already arrived).`);
        return;
      }
    }
    setSaving(true);
    try {
      const grn = await mutate<Receipt>("/api/goods-receipts", {
        purchaseOrderId: selected.id,
        receivedAt: receivedOn || null,
        note: note || null,
        lines,
      }, "POST");
      const products = lines.filter((l) => openLines.find((x) => x.id === l.purchaseOrderLineId)?.itemKind === "product");
      push({
        tone: "success",
        title: `${grn.ref} received`,
        desc: products.length
          ? `${products.reduce((n, l) => n + l.qtyReceived, 0)} unit(s) added to stock; every one has a ledger row.`
          : "Materials recorded. Finishes carry no stock, so the ledger is unchanged.",
      });
      setOpen(false); reset();
      receipts.reload(); pos.reload();
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    } finally {
      setSaving(false);
    }
  };

  if (pos.error || receipts.error)
    return (
      <div>
        <PageHeader title="Goods Receipts" />
        <ErrorState title="Procurement API unreachable" description="The receiving endpoint did not answer." debug={pos.error || receipts.error || undefined} onRetry={() => { pos.reload(); receipts.reload(); }} />
      </div>
    );

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Operations", to: "/operations" }, { label: "Goods Receipts" }]}
        title="Goods Receipts"
        description="Record what actually arrived. Receiving a product adds it to stock through the ledger; receiving a material records it without touching stock."
        actions={
          <>
            <Button variant="secondary" onClick={() => { pos.reload(); receipts.reload(); }}><RefreshCw size={15} /> Refresh</Button>
            <Button onClick={() => { reset(); setOpen(true); }} disabled={!receivable.length}>
              <Truck size={15} /> Receive goods
            </Button>
          </>
        }
      />

      <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <Card className="py-4">
          <p className="text-caption font-medium uppercase text-subtle">Awaiting delivery</p>
          <p className="mt-1 text-h2 text-ink">{receivable.length}</p>
          <p className="mt-1 text-caption text-subtle">approved, sent or partly received</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption font-medium uppercase text-subtle">Units outstanding</p>
          <p className="mt-1 text-h2 text-ink">{receivable.reduce((n, p) => n + Number(p.qtyOpen || 0), 0)}</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption font-medium uppercase text-subtle">Receipts recorded</p>
          <p className="mt-1 text-h2 text-ink">{receipts.data?.items?.length ?? 0}</p>
        </Card>
      </div>

      {receivable.length > 0 && (
        <Card className="mb-4">
          <h2 className="mb-2 flex items-center gap-2 text-small font-semibold text-ink"><ClipboardCheck size={15} /> Ready to receive</h2>
          <ul className="divide-y divide-slate-100">
            {receivable.map((p) => (
              <li key={p.id} className="flex flex-wrap items-center gap-3 py-2.5">
                <span className="font-mono text-caption font-semibold text-primary-700">{p.ref}</span>
                <Badge tone="warning" dot={false}>{p.status.replace("_", " ")}</Badge>
                <span className="text-caption text-muted">{p.qtyOpen} of {p.lines.reduce((n, l) => n + l.qtyOrdered, 0)} units outstanding · {fmtPKR(p.value)}</span>
                <Button size="sm" className="ml-auto" onClick={() => { reset(); setPoId(String(p.id)); setOpen(true); }}>Receive</Button>
              </li>
            ))}
          </ul>
        </Card>
      )}

      <Card className="overflow-hidden p-0">
        <table className="w-full text-left">
          <thead className="border-b border-line bg-slate-50 text-caption uppercase text-subtle">
            <tr>
              <th className="px-4 py-2.5 font-semibold">GRN</th>
              <th className="px-4 py-2.5 font-semibold">Purchase order</th>
              <th className="px-4 py-2.5 font-semibold">Received</th>
              <th className="px-4 py-2.5 font-semibold">By</th>
              <th className="px-4 py-2.5 font-semibold">Note</th>
            </tr>
          </thead>
          <tbody>
            {receipts.loading && (receipts.data?.items ?? []).length === 0 && (
              <tr><td colSpan={5} className="p-4"><Skeleton className="h-10" /></td></tr>
            )}
            {(receipts.data?.items ?? []).map((g) => {
              const po = (pos.data?.items ?? []).find((p) => p.id === g.purchaseOrderId);
              return (
                <tr key={g.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50/60">
                  <td className="px-4 py-3 font-mono text-caption font-semibold text-ink">{g.ref}</td>
                  <td className="px-4 py-3 text-small text-slate-700">{po?.ref ?? `#${g.purchaseOrderId}`}</td>
                  <td className="px-4 py-3 text-caption text-subtle">{g.receivedAt ? String(g.receivedAt).slice(0, 10) : "—"}</td>
                  <td className="px-4 py-3 text-small text-slate-700">{g.receivedBy ?? "—"}</td>
                  <td className="px-4 py-3 text-caption text-muted">{g.note ?? "—"}</td>
                </tr>
              );
            })}
          </tbody>
        </table>
        {!receipts.loading && (receipts.data?.items ?? []).length === 0 && (
          <EmptyState
            icon={<Truck size={20} />}
            title="Nothing received yet"
            description={receivable.length ? "Choose a purchase order above to record a delivery." : "Approve a purchase order first — goods can only be received against one."}
            action={receivable.length ? <Button onClick={() => { reset(); setOpen(true); }}><Truck size={15} /> Receive goods</Button> : undefined}
          />
        )}
      </Card>

      <Modal open={open} onClose={() => setOpen(false)} title="Record a goods receipt" className="max-w-2xl"
        description="Only what physically arrived. Part deliveries are normal — the balance stays outstanding.">
        {!selected ? (
          <Field label="Purchase order" required>
            <select className="h-10 w-full rounded-control border border-line-strong bg-white px-3 text-small"
              value={poId} onChange={(e) => { setPoId(e.target.value); setQty({}); }}>
              <option value="">Choose an order…</option>
              {receivable.map((p) => <option key={p.id} value={p.id}>{p.ref} — {p.qtyOpen} units outstanding</option>)}
            </select>
          </Field>
        ) : (
          <>
            <div className="mb-3 flex items-center justify-between rounded-control bg-slate-50 px-3 py-2">
              <span className="font-mono text-small font-semibold text-ink">{selected.ref}</span>
              <button className="text-caption font-semibold text-primary-700 hover:underline" onClick={() => { setPoId(""); setQty({}); }}>Change order</button>
            </div>
            <table className="w-full text-left text-caption">
              <thead className="text-subtle uppercase">
                <tr>
                  <th className="py-1.5 font-semibold">Item</th>
                  <th className="py-1.5 font-semibold">Effect</th>
                  <th className="py-1.5 text-right font-semibold">Outstanding</th>
                  <th className="py-1.5 text-right font-semibold">Received now</th>
                </tr>
              </thead>
              <tbody>
                {openLines.map((l) => (
                  <tr key={l.id} className="border-t border-slate-200">
                    <td className="py-2 font-mono">{l.itemId}</td>
                    <td className="py-2">
                      {l.itemKind === "product"
                        ? <Badge tone="success" dot={false}>adds to stock</Badge>
                        : <Badge tone="neutral" dot={false}>recorded, no stock</Badge>}
                    </td>
                    <td className="py-2 text-right">{l.qtyOpen} of {l.qtyOrdered}</td>
                    <td className="py-2 text-right">
                      <input type="number" min={0} max={l.qtyOpen} step={1}
                        className="h-9 w-24 rounded-control border border-line-strong px-2 text-right text-small"
                        value={qty[l.id] ?? ""} placeholder="0"
                        onChange={(e) => setQty((c) => ({ ...c, [l.id]: e.target.value }))} />
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
            <div className="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2">
              <Field label="Received on" hint="Defaults to now">
                <Input type="date" value={receivedOn} onChange={(e) => setReceivedOn(e.target.value)} />
              </Field>
              <Field label="Note">
                <Input value={note} onChange={(e) => setNote(e.target.value)} placeholder="Delivery note number, condition on arrival…" />
              </Field>
            </div>
            <p className="mt-2 text-caption text-subtle">{totalUnits} unit(s) in this receipt.</p>
          </>
        )}

        {err && <p role="alert" className="mt-3 rounded-control border border-danger/30 bg-danger-soft px-3 py-2 text-caption font-semibold text-danger-strong">{err}</p>}

        <div className="mt-4 flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setOpen(false)} disabled={saving}>Cancel</Button>
          <Button onClick={receive} disabled={saving || !selected}>
            {saving ? "Recording…" : <><CheckCircle size={15} /> Record receipt</>}
          </Button>
        </div>
      </Modal>
    </div>
  );
}

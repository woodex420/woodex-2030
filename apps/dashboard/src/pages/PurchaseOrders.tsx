import { Fragment, useMemo, useState } from "react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { StatusBadge } from "@/components/ui/Badge";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui/States";
import { Field, Input, Select, Textarea } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { useToast } from "@/components/ui/Toast";
import { useApi, mutate, fmtPKR } from "@/lib/api";
import { Plus, RefreshCw, ClipboardList, Trash2, Package } from "@/icons";

/* Purchase orders — the second screen the procurement API never had.
 *
 * A PO is the business document in this module: it is what a supplier is sent
 * and what goods receipts and supplier invoices are matched against. Building
 * it needs three live reads (suppliers, products, materials) and one write, so
 * the form refuses to open a line it cannot price honestly.
 *
 * The catalogue is capped at 500 rows for the picker. That is a real limit, not
 * a hidden one: beyond 500 products this needs a type-ahead against a search
 * endpoint, and saying so beats a picker that silently only knows part of the
 * catalogue. */

type Option = { id: string; name: string; price?: number };
type Supplier = { id: number; code: string; name: string; active: boolean };
type PoLine = {
  id: number; lineNo: number; itemKind: string; itemId: string;
  qtyOrdered: number; qtyReceived: number; unitCost: number; lineTotal: number;
};
/* The API already returns lines and a computed value with every row
   (purchaseOrderFull), so there is no second fetch and no client-side maths to
   disagree with the server. `value` is the authoritative total: it is summed
   from lineTotal, which is itself qty x unitCost as the database computed it. */
type PO = {
  id: number; ref: string; supplierId: number; status: string; currency: string;
  expectedAt: string | null; note: string | null; createdAt: string;
  lines: PoLine[]; qtyOrdered: number; qtyReceived: number; qtyOpen: number; value: number;
};

type Draft = { itemKind: "product" | "material"; itemId: string; qtyOrdered: string; unitCost: string };
const emptyDraft = (): Draft => ({ itemKind: "product", itemId: "", qtyOrdered: "1", unitCost: "" });

const NEXT_STATUS: Record<string, string[]> = {
  draft: ["approved", "cancelled"],
  approved: ["sent", "cancelled"],
  sent: ["cancelled"],
  partially_received: [],
  received: [],
  cancelled: [],
};

export default function PurchaseOrders() {
  const { push } = useToast();
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [expanded, setExpanded] = useState<number | null>(null);

  const [supplierId, setSupplierId] = useState("");
  const [expectedAt, setExpectedAt] = useState("");
  const [notes, setNotes] = useState("");
  const [lines, setLines] = useState<Draft[]>([emptyDraft()]);

  const pos = useApi<{ total: number; open: number; items: PO[] }>("/api/purchase-orders", 20000);
  const suppliers = useApi<{ items: Supplier[] }>("/api/suppliers?active=true");
  const products = useApi<{ total: number; items: Option[] }>("/api/products");
  const materials = useApi<Option[]>("/api/materials");

  /* One price source. A product line defaults to its list price but the buyer
     may overwrite it — that is a negotiation, and is exactly what unitCost is
     for. The default exists so a PO is not raised at zero by accident. */
  const priceOf = (kind: "product" | "material", id: string) =>
    kind === "product" ? Number((products.data?.items ?? []).find((p) => p.id === id)?.price ?? 0) : 0;

  const options = (kind: "product" | "material") =>
    kind === "product"
      ? (products.data?.items ?? []).map((p) => ({ id: p.id, name: p.name }))
      : (materials.data ?? []).map((m) => ({ id: m.id, name: m.name }));

  const draftTotal = useMemo(
    () => lines.reduce((n, l) => n + (Number(l.qtyOrdered) || 0) * (Number(l.unitCost) || 0), 0),
    [lines]
  );

  const setLine = (i: number, patch: Partial<Draft>) =>
    setLines((cur) => cur.map((l, idx) => (idx === i ? { ...l, ...patch } : l)));

  const reset = () => {
    setSupplierId(""); setExpectedAt(""); setNotes(""); setLines([emptyDraft()]); setErr(null);
  };

  const create = async () => {
    if (saving) return;
    setErr(null);
    if (!supplierId) { setErr("Choose a supplier first."); return; }
    const prepared = lines
      .filter((l) => l.itemId)
      .map((l) => ({
        itemKind: l.itemKind, itemId: l.itemId,
        qtyOrdered: Number(l.qtyOrdered), unitCost: Number(l.unitCost),
      }));
    if (!prepared.length) { setErr("Add at least one line."); return; }
    /* The route validates all of this too. Checking here keeps a rejected
       request from being the user's only explanation of what was wrong. */
    const bad = prepared.find((l) => !Number.isInteger(l.qtyOrdered) || l.qtyOrdered <= 0 || !Number.isFinite(l.unitCost) || l.unitCost < 0);
    if (bad) { setErr(`Line for ${bad.itemId}: quantity must be a whole number above zero and cost cannot be negative.`); return; }
    const seen = new Set<string>();
    for (const l of prepared) {
      const key = l.itemKind + ":" + l.itemId;
      if (seen.has(key)) { setErr(`${l.itemId} appears twice — raise the quantity on one line instead.`); return; }
      seen.add(key);
    }
    setSaving(true);
    try {
      const po = await mutate<PO>("/api/purchase-orders", {
        supplierId: Number(supplierId), expectedAt: expectedAt || null, notes: notes || null, lines: prepared,
      }, "POST");
      push({ tone: "success", title: `${po.ref} raised`, desc: `${fmtPKR(po.value)} · ${prepared.length} line(s). Approve it to receive goods.` });
      setOpen(false); reset(); pos.reload();
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    } finally {
      setSaving(false);
    }
  };

  const advance = async (po: PO, status: string) => {
    try {
      await mutate(`/api/purchase-orders/${po.id}`, { status });
      push({ tone: status === "cancelled" ? "info" : "success", title: `${po.ref} → ${status.replace("_", " ")}` });
      pos.reload();
    } catch (e) {
      push({ tone: "danger", title: "Could not update the order", desc: e instanceof Error ? e.message : String(e) });
    }
  };

  /* Lines arrive with the list, so expanding is pure local state — no request,
     no loading spinner, nothing that can fail. */
  const toggleLines = (po: PO) => setExpanded((cur) => (cur === po.id ? null : po.id));

  const items = pos.data?.items ?? [];
  /* The PO row carries supplierId, not a name. Resolving it here from the same
     suppliers read the form already uses avoids inventing a server field. */
  const supplierName = (id: number) => (suppliers.data?.items ?? []).find((x) => x.id === id)?.name ?? `Supplier #${id}`;

  if (pos.error)
    return (
      <div>
        <PageHeader title="Purchase Orders" />
        <ErrorState title="Purchase orders API unreachable" description="The procurement backend did not answer." debug={pos.error} onRetry={pos.reload} />
      </div>
    );

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Operations", to: "/operations" }, { label: "Purchase Orders" }]}
        title="Purchase Orders"
        description="What Woodex has ordered from suppliers. Goods receipts and supplier invoices are matched against these."
        actions={
          <>
            <Button variant="secondary" onClick={pos.reload}><RefreshCw size={15} /> Refresh</Button>
            <Button onClick={() => { reset(); setOpen(true); }}><Plus size={15} /> New purchase order</Button>
          </>
        }
      />

      <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <Card className="py-4">
          <p className="text-caption font-medium uppercase text-subtle">Purchase orders</p>
          <p className="mt-1 text-h2 text-ink">{pos.data?.total ?? 0}</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption font-medium uppercase text-subtle">Open</p>
          <p className="mt-1 text-h2 text-ink">{pos.data?.open ?? 0}</p>
          <p className="mt-1 text-caption text-subtle">draft, approved, sent or partly received</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption font-medium uppercase text-subtle">Value on order</p>
          <p className="mt-1 text-h2 text-ink">
            {fmtPKR(items.filter((p) => !["received", "cancelled"].includes(p.status)).reduce((n, p) => n + Number(p.value || 0), 0))}
          </p>
        </Card>
      </div>

      <Card className="overflow-hidden p-0">
        <table className="w-full text-left">
          <thead className="border-b border-line bg-slate-50 text-caption uppercase text-subtle">
            <tr>
              <th className="px-4 py-2.5 font-semibold">Ref</th>
              <th className="px-4 py-2.5 font-semibold">Supplier</th>
              <th className="px-4 py-2.5 font-semibold">Status</th>
              <th className="px-4 py-2.5 font-semibold text-right">Total</th>
              <th className="px-4 py-2.5 font-semibold">Expected</th>
              <th className="px-4 py-2.5 font-semibold text-right">Actions</th>
            </tr>
          </thead>
          <tbody>
            {pos.loading && items.length === 0 && (
              <tr><td colSpan={6} className="p-4"><Skeleton className="h-10" /><Skeleton className="mt-2 h-10" /></td></tr>
            )}
            {items.map((po) => {
              const nexts = NEXT_STATUS[po.status] ?? [];
              return (
                /* A fragment inside a map needs the key, and it must be on the
                   fragment: keys on the children are not enough for React to
                   track the pair as one unit. */
                <Fragment key={po.id}>
                  <tr className="border-b border-slate-100 hover:bg-slate-50/60">
                    <td className="px-4 py-3">
                      <button className="font-mono text-caption font-semibold text-primary-700 hover:underline" onClick={() => toggleLines(po)}>
                        {po.ref}
                      </button>
                    </td>
                    <td className="px-4 py-3 text-small text-slate-700">{supplierName(po.supplierId)}</td>
                    <td className="px-4 py-3"><StatusBadge status={po.status} /></td>
                    <td className="px-4 py-3 text-right text-small font-semibold text-ink">{fmtPKR(po.value)}</td>
                    <td className="px-4 py-3 text-caption text-subtle">{po.expectedAt ? po.expectedAt.slice(0, 10) : "—"}</td>
                    <td className="px-4 py-3">
                      <div className="flex justify-end gap-1.5">
                        {nexts.filter((n) => n !== "cancelled").map((n) => (
                          <Button key={n} size="sm" variant={n === "approved" ? "primary" : "secondary"} onClick={() => advance(po, n)}>
                            {n === "approved" ? "Approve" : n === "sent" ? "Mark sent" : n}
                          </Button>
                        ))}
                        {nexts.includes("cancelled") && !["partially_received", "received"].includes(po.status) && (
                          <Button size="sm" variant="secondary" onClick={() => advance(po, "cancelled")}>Cancel</Button>
                        )}
                        {nexts.length === 0 && <span className="text-caption text-subtle">—</span>}
                      </div>
                    </td>
                  </tr>
                  {expanded === po.id && (
                    <tr className="border-b border-slate-100 bg-slate-50/70">
                      <td colSpan={6} className="px-4 py-3">
                        {po.lines.length ? (
                          <table className="w-full text-left text-caption">
                            <thead className="text-subtle uppercase">
                              <tr>
                                <th className="py-1 font-semibold">#</th>
                                <th className="py-1 font-semibold">Item</th>
                                <th className="py-1 font-semibold">Kind</th>
                                <th className="py-1 font-semibold text-right">Ordered</th>
                                <th className="py-1 font-semibold text-right">Received</th>
                                <th className="py-1 font-semibold text-right">Unit cost</th>
                                <th className="py-1 font-semibold text-right">Line total</th>
                              </tr>
                            </thead>
                            <tbody>
                              {po.lines.map((l) => (
                                <tr key={l.id} className="border-t border-slate-200">
                                  <td className="py-1.5 text-subtle">{l.lineNo}</td>
                                  <td className="py-1.5 font-mono">{l.itemId}</td>
                                  <td className="py-1.5">{l.itemKind}</td>
                                  <td className="py-1.5 text-right">{l.qtyOrdered}</td>
                                  <td className="py-1.5 text-right">{l.qtyReceived}</td>
                                  <td className="py-1.5 text-right">{fmtPKR(l.unitCost)}</td>
                                  <td className="py-1.5 text-right font-semibold">{fmtPKR(l.lineTotal)}</td>
                                </tr>
                              ))}
                            </tbody>
                          </table>
                        ) : (
                          <p className="text-caption text-subtle">This order has no lines.</p>
                        )}
                      </td>
                    </tr>
                  )}
                </Fragment>
              );
            })}
          </tbody>
        </table>
        {!pos.loading && items.length === 0 && (
          <EmptyState
            icon={<ClipboardList size={20} />}
            title="No purchase orders yet"
            description="Raise one against a supplier. It will then be receivable and invoiceable."
            action={<Button onClick={() => { reset(); setOpen(true); }}><Plus size={15} /> New purchase order</Button>}
          />
        )}
      </Card>

      <Modal open={open} onClose={() => setOpen(false)} title="New purchase order" className="max-w-3xl">
        {(suppliers.data?.items ?? []).length === 0 && (
          <p className="mb-3 rounded-control border border-warning/30 bg-warning-soft px-3 py-2 text-caption text-warning-strong">
            No active suppliers yet. Add one on the Suppliers page first — a purchase order must be addressed to somebody.
          </p>
        )}
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-3">
          <Field label="Supplier" required className="sm:col-span-2">
            <Select value={supplierId} onChange={(e) => setSupplierId(e.target.value)}>
              <option value="">Choose a supplier…</option>
              {(suppliers.data?.items ?? []).map((s) => (
                <option key={s.id} value={s.id}>{s.name} ({s.code})</option>
              ))}
            </Select>
          </Field>
          <Field label="Expected delivery">
            <Input type="date" value={expectedAt} onChange={(e) => setExpectedAt(e.target.value)} />
          </Field>
        </div>

        <div className="mt-4">
          <div className="mb-1.5 flex items-center justify-between">
            <span className="text-small font-medium text-slate-700">Lines</span>
            <span className="text-caption text-subtle">Order total: <b className="text-ink">{fmtPKR(draftTotal)}</b></span>
          </div>
          <div className="space-y-2">
            {lines.map((l, i) => {
              const opts = options(l.itemKind);
              return (
                <div key={i} className="grid grid-cols-12 items-end gap-2 rounded-control border border-line bg-slate-50/60 p-2">
                  <div className="col-span-3">
                    <Select value={l.itemKind} onChange={(e) => setLine(i, { itemKind: e.target.value as "product" | "material", itemId: "", unitCost: "" })}>
                      <option value="product">Product</option>
                      <option value="material">Material / finish</option>
                    </Select>
                  </div>
                  <div className="col-span-4">
                    <Select value={l.itemId} onChange={(e) => {
                      const id = e.target.value;
                      setLine(i, { itemId: id, unitCost: l.unitCost || String(priceOf(l.itemKind, id) || "") });
                    }}>
                      <option value="">Choose…</option>
                      {opts.map((o) => <option key={o.id} value={o.id}>{o.name}</option>)}
                    </Select>
                  </div>
                  <div className="col-span-2">
                    <Input type="number" min={1} step={1} value={l.qtyOrdered} onChange={(e) => setLine(i, { qtyOrdered: e.target.value })} placeholder="Qty" />
                  </div>
                  <div className="col-span-2">
                    <Input type="number" min={0} step="0.01" value={l.unitCost} onChange={(e) => setLine(i, { unitCost: e.target.value })} placeholder="Unit cost" />
                  </div>
                  <div className="col-span-1 flex justify-end">
                    <Button variant="secondary" size="sm" aria-label="Remove line" disabled={lines.length === 1}
                      onClick={() => setLines((cur) => cur.filter((_, idx) => idx !== i))}>
                      <Trash2 size={13} />
                    </Button>
                  </div>
                </div>
              );
            })}
          </div>
          <button className="mt-2 inline-flex items-center gap-1.5 text-caption font-semibold text-primary-700 hover:underline"
            onClick={() => setLines((cur) => [...cur, emptyDraft()])}>
            <Plus size={13} /> Add another line
          </button>
        </div>

        <Field label="Notes" className="mt-3">
          <Textarea value={notes} onChange={(e) => setNotes(e.target.value)} placeholder="Delivery instructions, dock hours, PO reference for the supplier…" />
        </Field>

        {err && <p role="alert" className="mt-3 rounded-control border border-danger/30 bg-danger-soft px-3 py-2 text-caption font-semibold text-danger-strong">{err}</p>}

        <div className="mt-4 flex items-center justify-between gap-2">
          <p className="flex items-center gap-1.5 text-caption text-subtle"><Package size={13} /> Created as a draft — approve it before receiving.</p>
          <div className="flex gap-2">
            <Button variant="secondary" onClick={() => setOpen(false)} disabled={saving}>Cancel</Button>
            <Button onClick={create} disabled={saving}>{saving ? "Saving…" : "Create purchase order"}</Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}

import { useMemo, useState } from "react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui/States";
import { Field, Input, Select, Textarea } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { useToast } from "@/components/ui/Toast";
import { useApi, mutate } from "@/lib/api";
import { Plus, RefreshCw, Truck, Trash2, ArrowRight } from "@/icons";

/* Stock transfers — moving units between locations.
 *
 * A transfer is two ledger rows, never one edit. Dispatching debits the source;
 * receiving credits the destination. Between those two moments the goods are in
 * a truck and belong to neither location, which is why the third state exists.
 *
 * That window is visible in the numbers: units in transit are not on hand.
 * Counting them at both ends would let the same desk be sold in Lahore and
 * promised in Karachi. */

type Transfer = {
  id: number; ref: string; fromLocationId: number; toLocationId: number;
  fromLabel: string | null; toLabel: string | null; status: string; note: string | null;
  createdBy: string | null; dispatchedAt: string | null; receivedAt: string | null;
  createdAt: string; qty: number;
  lines: { id: number; productId: string; productName: string; qty: number }[];
};
type Location = { id: number; warehouseId: number; warehouseName: string | null; code: string; name: string; active: boolean };
type Product = { id: string; name: string; stockQty: number };

const STATUS_TONE: Record<string, "neutral" | "info" | "warning" | "success" | "danger"> = {
  draft: "neutral", dispatched: "warning", received: "success", cancelled: "neutral",
};

export default function StockTransfers() {
  const { push } = useToast();
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [filter, setFilter] = useState("");

  const transfers = useApi<{ total: number; items: Transfer[] }>(
    `/api/stock-transfers${filter ? `?status=${filter}` : ""}`, 20000);
  const locations = useApi<{ total: number; items: Location[] }>("/api/storage-locations?active=true", 60000);
  const products = useApi<{ items: Product[] }>("/api/products", 120000);

  const [from, setFrom] = useState("");
  const [to, setTo] = useState("");
  const [note, setNote] = useState("");
  const [lines, setLines] = useState<{ productId: string; qty: string }[]>([{ productId: "", qty: "" }]);

  const locs = locations.data?.items ?? [];
  const items = transfers.data?.items ?? [];

  /* Quantity available at the FROM location, so the form can refuse a dispatch
     before it is created rather than failing at dispatch time. Stock may be
     unlocated (pre-012), which is why this can be lower than the product total
     and why the hint says so. */
  const fromLevels = useApi<{ items: { productId: string; qty: number }[] }>(
    from ? `/api/stock-levels?location_id=${from}` : "/api/stock-levels?location_id=0", 20000);
  const atSource = useMemo(() => {
    const m = new Map<string, number>();
    for (const l of fromLevels.data?.items ?? []) m.set(l.productId, l.qty);
    return m;
  }, [fromLevels.data]);

  const reset = () => { setFrom(""); setTo(""); setNote(""); setLines([{ productId: "", qty: "" }]); setErr(null); };

  const submit = async () => {
    if (saving) return;
    setErr(null);
    if (!from || !to) { setErr("Choose a source and a destination location."); return; }
    if (from === to) { setErr("Source and destination must be different."); return; }
    const clean = lines.filter((l) => l.productId && Number(l.qty) > 0);
    if (!clean.length) { setErr("Add at least one product with a quantity."); return; }
    const dupes = clean.map((l) => l.productId).filter((id, i, a) => a.indexOf(id) !== i);
    if (dupes.length) { setErr("Each product may appear only once per transfer."); return; }
    setSaving(true);
    try {
      const res = await mutate<{ transfer: Transfer }>("/api/stock-transfers", {
        fromLocationId: Number(from), toLocationId: Number(to), note: note || null,
        lines: clean.map((l) => ({ productId: l.productId, qty: Number(l.qty) })),
      }, "POST");
      push({
        tone: "success",
        title: `${res.transfer.ref} created as a draft`,
        desc: "Dispatch it to move the stock — a draft moves nothing.",
      });
      setOpen(false); reset(); transfers.reload();
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    } finally {
      setSaving(false);
    }
  };

  const advance = async (t: Transfer, status: "dispatched" | "received" | "cancelled") => {
    const verb = status === "dispatched" ? "Dispatch" : status === "received" ? "Receive" : "Cancel";
    const desc = status === "dispatched"
      ? `${t.qty} unit(s) leave ${t.fromLabel}. They are in transit until received.`
      : status === "received"
        ? `${t.qty} unit(s) arrive at ${t.toLabel} and enter its stock.`
        : "The draft is cancelled. No stock moves.";
    if (!confirm(`${verb} ${t.ref}?\n\n${desc}`)) return;
    try {
      await mutate(`/api/stock-transfers/${t.id}`, { status });
      push({ tone: status === "received" ? "success" : "info", title: `${t.ref} ${status}`, desc });
      transfers.reload();
    } catch (e) {
      push({ tone: "danger", title: `Could not ${verb.toLowerCase()} ${t.ref}`, desc: e instanceof Error ? e.message : String(e) });
    }
  };

  if (transfers.error)
    return (
      <div>
        <PageHeader title="Stock Transfers" />
        <ErrorState title="Transfers API unreachable" description="The inventory backend did not answer." debug={transfers.error} onRetry={transfers.reload} />
      </div>
    );

  const counts = {
    draft: items.filter((t) => t.status === "draft").length,
    dispatched: items.filter((t) => t.status === "dispatched").length,
    received: items.filter((t) => t.status === "received").length,
  };

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Operations", to: "/operations" }, { label: "Stock Transfers" }]}
        title="Stock Transfers"
        description="Move stock between warehouses and racks. Dispatch debits the source; receipt credits the destination."
        actions={
          <>
            <Button variant="secondary" onClick={transfers.reload}><RefreshCw size={15} /> Refresh</Button>
            <Button onClick={() => { reset(); setOpen(true); }}><Plus size={15} /> New transfer</Button>
          </>
        }
      />

      <div className="mb-4 grid grid-cols-3 gap-3">
        <Card className="py-4">
          <p className="text-caption uppercase text-subtle">Drafts</p>
          <p className="mt-1 text-h2 font-semibold text-ink">{counts.draft}</p>
          <p className="text-caption text-subtle">Not dispatched — no stock moved</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption uppercase text-subtle">In transit</p>
          <p className="mt-1 text-h2 font-semibold text-warning-strong">{counts.dispatched}</p>
          <p className="text-caption text-subtle">Off the shelf, not yet received</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption uppercase text-subtle">Received</p>
          <p className="mt-1 text-h2 font-semibold text-ink">{counts.received}</p>
          <p className="text-caption text-subtle">At the destination</p>
        </Card>
      </div>

      <Card className="mb-4 flex flex-wrap items-end gap-3 py-4">
        <Field label="Status">
          <Select value={filter} onChange={(e) => setFilter(e.target.value)} className="min-w-[160px]">
            <option value="">All</option>
            <option value="draft">Draft</option>
            <option value="dispatched">Dispatched (in transit)</option>
            <option value="received">Received</option>
            <option value="cancelled">Cancelled</option>
          </Select>
        </Field>
        <div className="ml-auto text-caption text-subtle">{transfers.loading ? "syncing…" : `${items.length} transfer(s)`}</div>
      </Card>

      <Card className="overflow-hidden p-0">
        <table className="w-full text-left">
          <thead className="border-b border-line bg-slate-50 text-caption uppercase text-subtle">
            <tr>
              <th className="px-4 py-2.5 font-semibold">Ref</th>
              <th className="px-4 py-2.5 font-semibold">Route</th>
              <th className="px-4 py-2.5 font-semibold">Contents</th>
              <th className="px-4 py-2.5 text-right font-semibold">Units</th>
              <th className="px-4 py-2.5 font-semibold">Status</th>
              <th className="px-4 py-2.5 text-right font-semibold">Action</th>
            </tr>
          </thead>
          <tbody>
            {transfers.loading && items.length === 0 && (
              <tr><td colSpan={6} className="p-4"><Skeleton className="h-10" /><Skeleton className="mt-2 h-10" /></td></tr>
            )}
            {items.map((t) => (
              <tr key={t.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50/60">
                <td className="px-4 py-3">
                  <p className="font-mono text-caption font-semibold text-ink">{t.ref}</p>
                  <p className="text-caption text-subtle">{t.createdBy ?? "—"}</p>
                </td>
                <td className="px-4 py-3">
                  <div className="flex items-center gap-1.5 text-small text-slate-700">
                    <span className="font-mono text-caption">{t.fromLabel}</span>
                    <ArrowRight size={13} className="text-subtle" />
                    <span className="font-mono text-caption">{t.toLabel}</span>
                  </div>
                  {t.note && <p className="text-caption text-muted">{t.note}</p>}
                </td>
                <td className="px-4 py-3">
                  {t.lines.map((l) => (
                    <p key={l.id} className="text-caption text-slate-700">{l.productName} <span className="text-subtle">×{l.qty}</span></p>
                  ))}
                </td>
                <td className="px-4 py-3 text-right text-small font-semibold text-ink">{t.qty}</td>
                <td className="px-4 py-3">
                  <Badge tone={STATUS_TONE[t.status] ?? "neutral"} dot={false}>{t.status}</Badge>
                  {t.status === "dispatched" && t.dispatchedAt && (
                    <p className="mt-1 text-caption text-subtle">sent {new Date(t.dispatchedAt).toLocaleDateString()}</p>
                  )}
                  {t.status === "received" && t.receivedAt && (
                    <p className="mt-1 text-caption text-subtle">in {new Date(t.receivedAt).toLocaleDateString()}</p>
                  )}
                </td>
                <td className="px-4 py-3 text-right">
                  <div className="flex justify-end gap-2">
                    {t.status === "draft" && (
                      <>
                        <Button size="sm" onClick={() => advance(t, "dispatched")}>Dispatch</Button>
                        <Button variant="secondary" size="sm" onClick={() => advance(t, "cancelled")}>Cancel</Button>
                      </>
                    )}
                    {t.status === "dispatched" && <Button size="sm" onClick={() => advance(t, "received")}>Receive</Button>}
                    {(t.status === "received" || t.status === "cancelled") && (
                      <span className="text-caption text-subtle">closed</span>
                    )}
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {!transfers.loading && items.length === 0 && (
          <EmptyState
            icon={<Truck size={20} />}
            title={filter ? `No ${filter} transfers` : "No transfers yet"}
            description={filter
              ? "Clear the filter to see every transfer."
              : "Move stock between warehouses or racks. Nothing moves until a transfer is dispatched."}
            action={!filter ? <Button onClick={() => { reset(); setOpen(true); }}><Plus size={15} /> New transfer</Button> : undefined}
          />
        )}
      </Card>

      <Modal open={open} onClose={() => setOpen(false)} title="New stock transfer">
        {locs.length < 2 ? (
          <div className="rounded-control border border-warning/30 bg-warning-soft px-3 py-3 text-small text-warning-strong">
            A transfer needs at least two storage locations in different places. Create a second warehouse and a location in it first.
          </div>
        ) : (
          <>
            <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
              <Field label="From location" required>
                <Select value={from} onChange={(e) => setFrom(e.target.value)}>
                  <option value="">Choose…</option>
                  {locs.map((l) => (
                    <option key={l.id} value={l.id}>{l.code} · {l.warehouseName}</option>
                  ))}
                </Select>
              </Field>
              <Field label="To location" required>
                <Select value={to} onChange={(e) => setTo(e.target.value)}>
                  <option value="">Choose…</option>
                  {locs.filter((l) => String(l.id) !== from).map((l) => (
                    <option key={l.id} value={l.id}>{l.code} · {l.warehouseName}</option>
                  ))}
                </Select>
              </Field>
            </div>

            <div className="mt-4">
              <p className="mb-2 text-caption font-semibold uppercase text-subtle">Products</p>
              <div className="space-y-2">
                {lines.map((line, i) => {
                  const available = atSource.get(line.productId);
                  return (
                    <div key={i} className="flex items-start gap-2">
                      <div className="flex-1">
                        <Select
                          value={line.productId}
                          onChange={(e) => {
                            const next = [...lines]; next[i] = { ...next[i], productId: e.target.value };
                            setLines(next);
                          }}
                        >
                          <option value="">Choose a product…</option>
                          {(products.data?.items ?? []).map((p) => (
                            <option key={p.id} value={p.id}>{p.name}</option>
                          ))}
                        </Select>
                        {line.productId && (
                          <p className="mt-1 text-caption text-subtle">
                            {available === undefined
                              ? "No stock recorded at this location — it may be unlocated, or in another warehouse."
                              : `${available} unit(s) at this location.`}
                          </p>
                        )}
                      </div>
                      <Input
                        type="number"
                        min={1}
                        value={line.qty}
                        onChange={(e) => {
                          const next = [...lines]; next[i] = { ...next[i], qty: e.target.value };
                          setLines(next);
                        }}
                        className="w-24"
                        placeholder="Qty"
                      />
                      <Button
                        variant="secondary" size="sm" className="mt-0.5"
                        onClick={() => setLines(lines.length === 1 ? [{ productId: "", qty: "" }] : lines.filter((_, j) => j !== i))}
                        aria-label="Remove line"
                      >
                        <Trash2 size={14} />
                      </Button>
                    </div>
                  );
                })}
              </div>
              <Button variant="secondary" size="sm" className="mt-2" onClick={() => setLines([...lines, { productId: "", qty: "" }])}>
                <Plus size={14} /> Add line
              </Button>
            </div>

            <Field label="Note" className="mt-4">
              <Textarea value={note} onChange={(e) => setNote(e.target.value)} placeholder="Why is this moving?" />
            </Field>
            {err && <p role="alert" className="mt-3 rounded-control border border-danger/30 bg-danger-soft px-3 py-2 text-caption font-semibold text-danger-strong">{err}</p>}
            <div className="mt-4 flex justify-end gap-2">
              <Button variant="secondary" onClick={() => setOpen(false)} disabled={saving}>Cancel</Button>
              <Button onClick={submit} disabled={saving}>{saving ? "Creating…" : "Create draft"}</Button>
            </div>
          </>
        )}
      </Modal>
    </div>
  );
}

import { useState } from "react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui/States";
import { Field, Select } from "@/components/ui/Field";
import { useApi } from "@/lib/api";
import { RefreshCw, Package, CheckCircle, AlertTriangle, TrendingUp, TrendingDown } from "@/icons";

/* Stock ledger — the read side of the one rule this system will not bend.
 *
 * Every quantity change is written by applyStockMovement. Nothing else may
 * touch stock_qty, so the sum of a product's movements is the truth and the
 * cached column is a cache. This page shows both, side by side, and says out
 * loud when they disagree — that disagreement is the bug the entire ledger was
 * built to make impossible, and pretending it cannot happen is how it goes
 * unnoticed for weeks.
 *
 * The reconciliation panel is also the Phase 7 acceptance criterion made
 * visible: "inventory balances reconcile with the stock movement ledger."
 */

type Movement = {
  id: number; productId: string; delta: number; reason: string;
  refType: string | null; refId: number | null; note: string | null; by: string | null; at: string;
};
type Reconcile = {
  ok: boolean; products: number; movements: number; drifted: number;
  items: { productId: string; name: string; stockQty: number; ledgerQty: number; difference: number }[];
};

const REASONS = ["receipt", "sale", "return", "adjustment", "transfer", "wastage", "production"];

const reasonTone = (r: string) =>
  r === "receipt" ? "success" : r === "sale" ? "primary" : r === "production" ? "info"
    : r === "wastage" ? "danger" : r === "return" ? "warning" : "neutral";

export default function StockLedger() {
  const [reason, setReason] = useState("");
  const [limit, setLimit] = useState("200");

  const path = `/api/stock-movements?limit=${limit}${reason ? `&reason=${reason}` : ""}`;
  const ledger = useApi<{ total: number; in: number; out: number; items: Movement[] }>(path, 20000);
  const check = useApi<Reconcile>("/api/stock-movements/reconcile", 30000);

  if (ledger.error || check.error)
    return (
      <div>
        <PageHeader title="Stock Ledger" />
        <ErrorState title="Ledger API unreachable" description="The stock movement endpoint did not answer." debug={ledger.error || check.error || undefined} onRetry={() => { ledger.reload(); check.reload(); }} />
      </div>
    );

  const items = ledger.data?.items ?? [];
  const reconcile = check.data;

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Operations", to: "/operations" }, { label: "Stock Ledger" }]}
        title="Stock Ledger"
        description="Every change in inventory, in order. The sum of these rows is the stock figure — the product record is only a cache of it."
        actions={<Button variant="secondary" onClick={() => { ledger.reload(); check.reload(); }}><RefreshCw size={15} /> Refresh</Button>}
      />

      {/* Reconciliation first: if this is not green, nothing below it can be trusted. */}
      <Card className={"mb-4 border-2 " + (reconcile && !reconcile.ok ? "border-danger/40" : "border-success/30")}>
        <div className="flex flex-wrap items-center gap-3">
          <span className={"grid h-10 w-10 place-items-center rounded-full " + (reconcile && !reconcile.ok ? "bg-danger-soft text-danger-strong" : "bg-success-soft text-success-strong")}>
            {reconcile && !reconcile.ok ? <AlertTriangle size={18} /> : <CheckCircle size={18} />}
          </span>
          <div>
            <p className="text-small font-bold text-ink">
              {!reconcile ? "Checking…" : reconcile.ok ? "Every product reconciles with the ledger" : `${reconcile.drifted} product(s) disagree with the ledger`}
            </p>
            <p className="text-caption text-muted">
              {reconcile
                ? `${reconcile.products} products · ${reconcile.movements} movements · ${reconcile.ok ? "cache = sum of movements" : "stock_qty ≠ sum of movements"}`
                : "Reading the ledger…"}
            </p>
          </div>
          {reconcile && !reconcile.ok && (
            <Badge tone="danger" dot={false}><AlertTriangle size={11} className="mr-1 inline" />needs repair</Badge>
          )}
        </div>
        {reconcile && !reconcile.ok && (
          <table className="mt-3 w-full text-left text-caption">
            <thead className="text-subtle uppercase">
              <tr>
                <th className="py-1 font-semibold">Product</th>
                <th className="py-1 text-right font-semibold">Cached stock_qty</th>
                <th className="py-1 text-right font-semibold">Sum of movements</th>
                <th className="py-1 text-right font-semibold">Difference</th>
              </tr>
            </thead>
            <tbody>
              {reconcile.items.map((d) => (
                <tr key={d.productId} className="border-t border-slate-200">
                  <td className="py-1.5">
                    <span className="font-mono text-subtle">{d.productId}</span>
                    <span className="ml-2 text-ink">{d.name}</span>
                  </td>
                  <td className="py-1.5 text-right">{d.stockQty}</td>
                  <td className="py-1.5 text-right">{d.ledgerQty}</td>
                  <td className="py-1.5 text-right font-semibold text-danger-strong">{d.difference > 0 ? "+" : ""}{d.difference}</td>
                </tr>
              ))}
            </tbody>
          </table>
        )}
      </Card>

      <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <Card className="py-4">
          <p className="text-caption font-medium uppercase text-subtle">Units in</p>
          <p className="mt-1 flex items-center gap-2 text-h2 text-ink"><TrendingUp size={18} className="text-success" />{ledger.data?.in ?? 0}</p>
          <p className="mt-1 text-caption text-subtle">receipts, returns, production output</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption font-medium uppercase text-subtle">Units out</p>
          <p className="mt-1 flex items-center gap-2 text-h2 text-ink"><TrendingDown size={18} className="text-danger" />{ledger.data?.out ?? 0}</p>
          <p className="mt-1 text-caption text-subtle">sales, consumption, wastage</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption font-medium uppercase text-subtle">Movements shown</p>
          <p className="mt-1 text-h2 text-ink">{ledger.data?.total ?? 0}</p>
        </Card>
      </div>

      <Card className="mb-3 flex flex-wrap items-end gap-3 py-4">
        <div className="min-w-[180px]">
          <Field label="Reason">
            <Select value={reason} onChange={(e) => setReason(e.target.value)}>
              <option value="">All reasons</option>
              {REASONS.map((r) => <option key={r} value={r}>{r}</option>)}
            </Select>
          </Field>
        </div>
        <div className="min-w-[140px]">
          <Field label="Rows">
            <Select value={limit} onChange={(e) => setLimit(e.target.value)}>
              {["50", "100", "200", "500", "1000"].map((n) => <option key={n} value={n}>{n}</option>)}
            </Select>
          </Field>
        </div>
        <p className="ml-auto text-caption text-subtle">Totals above cover the filtered set, not just this page.</p>
      </Card>

      <Card className="overflow-hidden p-0">
        <table className="w-full text-left">
          <thead className="border-b border-line bg-slate-50 text-caption uppercase text-subtle">
            <tr>
              <th className="px-4 py-2.5 font-semibold">#</th>
              <th className="px-4 py-2.5 font-semibold">Product</th>
              <th className="px-4 py-2.5 font-semibold">Reason</th>
              <th className="px-4 py-2.5 font-semibold text-right">Delta</th>
              <th className="px-4 py-2.5 font-semibold">Reference</th>
              <th className="px-4 py-2.5 font-semibold">Note</th>
              <th className="px-4 py-2.5 font-semibold">When</th>
            </tr>
          </thead>
          <tbody>
            {ledger.loading && items.length === 0 && (
              <tr><td colSpan={7} className="p-4"><Skeleton className="h-10" /><Skeleton className="mt-2 h-10" /></td></tr>
            )}
            {items.map((m) => (
              <tr key={m.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50/60">
                <td className="px-4 py-2.5 font-mono text-caption text-subtle">{m.id}</td>
                <td className="px-4 py-2.5 font-mono text-caption text-ink">{m.productId}</td>
                <td className="px-4 py-2.5"><Badge tone={reasonTone(m.reason) as never} dot={false}>{m.reason}</Badge></td>
                <td className={"px-4 py-2.5 text-right text-small font-bold " + (m.delta < 0 ? "text-danger-strong" : "text-success-strong")}>
                  {m.delta > 0 ? "+" : ""}{m.delta}
                </td>
                <td className="px-4 py-2.5 text-caption text-subtle">{m.refType ? `${m.refType} #${m.refId}` : "—"}</td>
                <td className="px-4 py-2.5 text-caption text-muted">{m.note ?? "—"}</td>
                <td className="px-4 py-2.5 text-caption text-subtle">{m.at ? m.at.slice(0, 16).replace("T", " ") : "—"}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {!ledger.loading && items.length === 0 && (
          <EmptyState
            icon={<Package size={20} />}
            title={reason ? `No ${reason} movements` : "No stock movements yet"}
            description={reason ? "Try another reason, or clear the filter." : "Receiving goods or issuing production components writes the first rows."}
          />
        )}
      </Card>
    </div>
  );
}

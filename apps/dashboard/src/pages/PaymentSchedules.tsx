import { useState } from "react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui/States";
import { Field, Input, Select } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { useToast } from "@/components/ui/Toast";
import { useApi, mutate, fmtPKR } from "@/lib/api";
import { Plus, RefreshCw, Calendar, AlertTriangle } from "@/icons";

/* Payment schedules — what is due when.
 *
 * An invoice with three instalments currently has one due date and a paid
 * total, so "which instalment is late" cannot be answered. Each row here is a
 * promise with its own date.
 *
 * `overdue` is derived from the due date and what has been paid, never stored:
 * a stored flag would need a nightly job to stay true, and would be wrong every
 * morning until it ran. */

type Instalment = {
  id: number; invoiceId: number; seq: number; dueDate: string;
  amount: number; paid: number; outstanding: number; status: string;
  note: string | null; overdue: boolean;
};
type ScheduleList = { total: number; outstanding: number; overdue: number; items: Instalment[] };

const TONE: Record<string, "info" | "success" | "neutral"> = { pending: "info", paid: "success", waived: "neutral" };

export default function PaymentSchedules() {
  const { push } = useToast();
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [filter, setFilter] = useState("");
  const [form, setForm] = useState({ invoiceId: "", rows: [{ amount: "", dueDate: "" }] });

  const list = useApi<ScheduleList>(`/api/payment-schedules${filter ? `?status=${filter}` : ""}`, 20000);
  const invoices = useApi<{ items: { id: number; ref: string; customer: string; total: number; balance: number }[] }>("/api/invoices", 60000);

  const items = list.data?.items ?? [];
  const scheduled = form.rows.reduce((a, r) => a + (Number(r.amount) || 0), 0);
  const chosen = invoices.data?.items?.find((i) => String(i.id) === form.invoiceId);

  const save = async () => {
    if (saving) return;
    setErr(null);
    if (!form.invoiceId) { setErr("Choose an invoice."); return; }
    const clean = form.rows.filter((r) => Number(r.amount) > 0 && r.dueDate);
    if (!clean.length) { setErr("Add at least one instalment with an amount and a date."); return; }
    setSaving(true);
    try {
      await mutate("/api/payment-schedules", {
        invoiceId: Number(form.invoiceId),
        instalments: clean.map((r) => ({ amount: Number(r.amount), dueDate: r.dueDate })),
      }, "POST");
      push({ tone: "success", title: `${clean.length} instalment(s) scheduled`, desc: "Due dates are now individually tracked and shown as overdue when they pass." });
      setOpen(false); setForm({ invoiceId: "", rows: [{ amount: "", dueDate: "" }] }); list.reload();
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    } finally {
      setSaving(false);
    }
  };

  if (list.error)
    return (
      <div>
        <PageHeader title="Payment Schedules" />
        <ErrorState title="Schedules API unreachable" description="The finance backend did not answer." debug={list.error} onRetry={list.reload} />
      </div>
    );

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Finance", to: "/ledger" }, { label: "Payment Schedules" }]}
        title="Payment Schedules"
        description="What is due when. Instalments are tracked individually so a late second payment is visible without reading the invoice."
        actions={
          <>
            <Button variant="secondary" onClick={list.reload}><RefreshCw size={15} /> Refresh</Button>
            <Button onClick={() => { setErr(null); setOpen(true); }}><Plus size={15} /> New schedule</Button>
          </>
        }
      />

      <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <Card className="py-4">
          <p className="text-caption uppercase text-subtle">Instalments</p>
          <p className="mt-1 text-h2 font-semibold text-ink">{list.loading ? "—" : list.data?.total ?? 0}</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption uppercase text-subtle">Outstanding</p>
          <p className="mt-1 text-h2 font-semibold text-ink">{list.loading ? "—" : fmtPKR(list.data?.outstanding ?? 0)}</p>
        </Card>
        <Card className={`py-4 ${(list.data?.overdue ?? 0) > 0 ? "border-l-4 border-danger" : ""}`}>
          <p className="text-caption uppercase text-subtle">Overdue</p>
          <p className={`mt-1 text-h2 font-semibold ${(list.data?.overdue ?? 0) > 0 ? "text-danger-strong" : "text-ink"}`}>
            {list.loading ? "—" : list.data?.overdue ?? 0}
          </p>
          <p className="text-caption text-subtle">past their due date</p>
        </Card>
      </div>

      <Card className="mb-4 flex flex-wrap items-end gap-3 py-4">
        <Field label="Status" className="w-48">
          <Select value={filter} onChange={(e) => setFilter(e.target.value)}>
            <option value="">All</option>
            <option value="pending">Pending</option>
            <option value="paid">Paid</option>
            <option value="waived">Waived</option>
          </Select>
        </Field>
        <div className="ml-auto text-caption text-subtle">{list.loading ? "syncing…" : `${items.length} shown`}</div>
      </Card>

      <Card className="overflow-hidden p-0">
        <table className="w-full text-left">
          <thead className="border-b border-line bg-slate-50 text-caption uppercase text-subtle">
            <tr>
              <th className="px-4 py-2.5 font-semibold">Invoice</th>
              <th className="px-4 py-2.5 font-semibold">Instalment</th>
              <th className="px-4 py-2.5 font-semibold">Due</th>
              <th className="px-4 py-2.5 text-right font-semibold">Amount</th>
              <th className="px-4 py-2.5 text-right font-semibold">Outstanding</th>
              <th className="px-4 py-2.5 font-semibold">Status</th>
            </tr>
          </thead>
          <tbody>
            {list.loading && items.length === 0 && (
              <tr><td colSpan={6} className="p-4"><Skeleton className="h-10" /><Skeleton className="mt-2 h-10" /></td></tr>
            )}
            {items.map((i) => (
              <tr key={i.id} className={`border-b border-slate-100 last:border-0 hover:bg-slate-50/60 ${i.overdue ? "bg-danger-soft/30" : ""}`}>
                <td className="px-4 py-3 font-mono text-caption text-subtle">#{i.invoiceId}</td>
                <td className="px-4 py-3 text-small text-slate-700">{i.seq}</td>
                <td className="px-4 py-3">
                  <span className="flex items-center gap-1.5 text-small text-slate-700">
                    <Calendar size={13} className="text-subtle" /> {i.dueDate}
                  </span>
                  {i.overdue && (
                    <span className="mt-0.5 flex items-center gap-1 text-caption font-semibold text-danger-strong">
                      <AlertTriangle size={11} /> overdue
                    </span>
                  )}
                </td>
                <td className="px-4 py-3 text-right text-small tabular-nums text-ink">{fmtPKR(i.amount)}</td>
                <td className="px-4 py-3 text-right text-small font-semibold tabular-nums text-ink">
                  {i.status === "pending" ? fmtPKR(i.outstanding) : "—"}
                </td>
                <td className="px-4 py-3">
                  <Badge tone={TONE[i.status] ?? "neutral"} dot={false}>{i.status}</Badge>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {!list.loading && items.length === 0 && (
          <EmptyState
            icon={<Calendar size={20} />}
            title={filter ? `No ${filter} instalments` : "No payment schedules yet"}
            description={filter
              ? "Clear the filter to see every instalment."
              : "Split an invoice into instalments so each due date is tracked on its own."}
            action={!filter ? <Button onClick={() => setOpen(true)}><Plus size={15} /> New schedule</Button> : undefined}
          />
        )}
      </Card>

      <Modal open={open} onClose={() => setOpen(false)} title="Schedule instalments">
        <Field label="Invoice" required>
          <Select value={form.invoiceId} onChange={(e) => setForm({ ...form, invoiceId: e.target.value })}>
            <option value="">Choose an invoice…</option>
            {(invoices.data?.items ?? []).map((i) => (
              <option key={i.id} value={i.id}>{i.ref} · {i.customer} · balance {fmtPKR(i.balance)}</option>
            ))}
          </Select>
        </Field>

        <div className="mt-4">
          <p className="mb-2 text-caption font-semibold uppercase text-subtle">Instalments</p>
          <div className="space-y-2">
            {form.rows.map((row, i) => (
              <div key={i} className="flex gap-2">
                <Input
                  type="number" min={0} placeholder="Amount" className="flex-1"
                  value={row.amount}
                  onChange={(e) => { const n = [...form.rows]; n[i] = { ...n[i], amount: e.target.value }; setForm({ ...form, rows: n }); }}
                />
                <Input
                  type="date" className="w-40"
                  value={row.dueDate}
                  onChange={(e) => { const n = [...form.rows]; n[i] = { ...n[i], dueDate: e.target.value }; setForm({ ...form, rows: n }); }}
                />
                <Button
                  variant="secondary" size="sm"
                  onClick={() => setForm({ ...form, rows: form.rows.length === 1 ? [{ amount: "", dueDate: "" }] : form.rows.filter((_, j) => j !== i) })}
                  aria-label="Remove"
                >×</Button>
              </div>
            ))}
          </div>
          <Button variant="secondary" size="sm" className="mt-2" onClick={() => setForm({ ...form, rows: [...form.rows, { amount: "", dueDate: "" }] })}>
            <Plus size={14} /> Add instalment
          </Button>
        </div>

        <div className="mt-3 flex items-center justify-between rounded-control bg-slate-50 px-3 py-2">
          <span className="text-caption text-slate-700">Scheduled total</span>
          <span className="text-small font-semibold tabular-nums text-ink">{fmtPKR(scheduled)}</span>
        </div>
        {chosen && scheduled !== chosen.balance && (
          <p className="mt-2 text-caption text-warning-strong">
            This covers {fmtPKR(scheduled)} of a {fmtPKR(chosen.balance)} balance. That is allowed — later instalments can be added.
          </p>
        )}

        {err && <p role="alert" className="mt-3 rounded-control border border-danger/30 bg-danger-soft px-3 py-2 text-caption font-semibold text-danger-strong">{err}</p>}
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setOpen(false)} disabled={saving}>Cancel</Button>
          <Button onClick={save} disabled={saving}>{saving ? "Saving…" : "Create schedule"}</Button>
        </div>
      </Modal>
    </div>
  );
}

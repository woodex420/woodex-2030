import { useState } from "react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui/States";
import { Field, Input, Select, Textarea } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { useToast } from "@/components/ui/Toast";
import { useApi, mutate, fmtPKR } from "@/lib/api";
import { Plus, RefreshCw, Undo2, FileText } from "@/icons";

/* Credit notes — reducing what a customer owes.
 *
 * A credit note is a document, not a negative invoice: a cancelled sale and a
 * discounted one are different facts and are reported differently. It is also
 * why a draft posts nothing — the money has not been given up until someone
 * decides it has. Issuing is that decision, and it posts to the journal. */

type CreditNote = {
  id: number; ref: string; invoiceId: number | null; customer: string; reason: string | null;
  subtotal: number; tax: number; total: number; status: string;
  issuedAt: string | null; appliedAt: string | null; journalEntryId: number | null;
  note: string | null; createdAt: string;
};

const TONE: Record<string, "neutral" | "info" | "success" | "danger"> = {
  draft: "neutral", issued: "info", applied: "success", cancelled: "danger",
};

const blank = { customer: "", subtotal: "", tax: "0", invoiceId: "", reason: "", note: "" };

export default function CreditNotes() {
  const { push } = useToast();
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [form, setForm] = useState({ ...blank });
  const [filter, setFilter] = useState("");

  const list = useApi<{ total: number; totalAmount: number; items: CreditNote[] }>(
    `/api/credit-notes${filter ? `?status=${filter}` : ""}`, 20000);
  const invoices = useApi<{ items: { id: number; ref: string; customer: string; total: number }[] }>("/api/invoices", 60000);

  const items = list.data?.items ?? [];

  const save = async () => {
    if (saving) return;
    setErr(null);
    if (!form.customer.trim()) { setErr("Customer is required."); return; }
    const subtotal = Number(form.subtotal);
    if (!(subtotal > 0)) { setErr("Subtotal must be greater than zero."); return; }
    setSaving(true);
    try {
      await mutate("/api/credit-notes", {
        customer: form.customer.trim(), subtotal,
        tax: Number(form.tax) || 0,
        invoiceId: form.invoiceId ? Number(form.invoiceId) : null,
        reason: form.reason || null, note: form.note || null,
      }, "POST");
      push({ tone: "success", title: "Credit note drafted", desc: "It posts nothing until you issue it." });
      setOpen(false); setForm({ ...blank }); list.reload();
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    } finally {
      setSaving(false);
    }
  };

  const advance = async (cn: CreditNote, status: "issued" | "applied" | "cancelled") => {
    const desc = status === "cancelled"
      ? "The draft is withdrawn. No money is given up."
      : `Debits returns and credits receivables for ${fmtPKR(cn.total)}. This cannot be undone — only reversed.`;
    if (!confirm(`${status === "issued" ? "Issue" : status === "applied" ? "Mark applied" : "Cancel"} ${cn.ref}?\n\n${desc}`)) return;
    try {
      await mutate(`/api/credit-notes/${cn.id}`, { status });
      push({ tone: "info", title: `${cn.ref} ${status}`, desc });
      list.reload();
    } catch (e) {
      push({ tone: "danger", title: `Could not ${status} ${cn.ref}`, desc: e instanceof Error ? e.message : String(e) });
    }
  };

  if (list.error)
    return (
      <div>
        <PageHeader title="Credit Notes" />
        <ErrorState title="Credit notes API unreachable" description="The finance backend did not answer." debug={list.error} onRetry={list.reload} />
      </div>
    );

  const previewTotal = (Number(form.subtotal) || 0) + (Number(form.tax) || 0);

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Finance", to: "/ledger" }, { label: "Credit Notes" }]}
        title="Credit Notes"
        description="Reduce what a customer owes — damages, returns, goodwill. A draft posts nothing; issuing posts to the ledger."
        actions={
          <>
            <Button variant="secondary" onClick={list.reload}><RefreshCw size={15} /> Refresh</Button>
            <Button onClick={() => { setForm({ ...blank }); setErr(null); setOpen(true); }}><Plus size={15} /> New credit note</Button>
          </>
        }
      />

      <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <Card className="py-4">
          <p className="text-caption uppercase text-subtle">Credit notes</p>
          <p className="mt-1 text-h2 font-semibold text-ink">{list.loading ? "—" : list.data?.total ?? 0}</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption uppercase text-subtle">Total credited</p>
          <p className="mt-1 text-h2 font-semibold text-ink">{list.loading ? "—" : fmtPKR(list.data?.totalAmount ?? 0)}</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption uppercase text-subtle">Drafts</p>
          <p className="mt-1 text-h2 font-semibold text-ink">{items.filter((c) => c.status === "draft").length}</p>
          <p className="text-caption text-subtle">not yet in the books</p>
        </Card>
      </div>

      <Card className="mb-4 flex flex-wrap items-end gap-3 py-4">
        <Field label="Status" className="w-48">
          <Select value={filter} onChange={(e) => setFilter(e.target.value)}>
            <option value="">All</option>
            <option value="draft">Draft</option>
            <option value="issued">Issued</option>
            <option value="applied">Applied</option>
            <option value="cancelled">Cancelled</option>
          </Select>
        </Field>
        <div className="ml-auto text-caption text-subtle">{list.loading ? "syncing…" : `${items.length} shown`}</div>
      </Card>

      <Card className="overflow-hidden p-0">
        <table className="w-full text-left">
          <thead className="border-b border-line bg-slate-50 text-caption uppercase text-subtle">
            <tr>
              <th className="px-4 py-2.5 font-semibold">Ref</th>
              <th className="px-4 py-2.5 font-semibold">Customer</th>
              <th className="px-4 py-2.5 font-semibold">Reason</th>
              <th className="px-4 py-2.5 text-right font-semibold">Total</th>
              <th className="px-4 py-2.5 font-semibold">Status</th>
              <th className="px-4 py-2.5 font-semibold">In the books</th>
              <th className="px-4 py-2.5 text-right font-semibold">Action</th>
            </tr>
          </thead>
          <tbody>
            {list.loading && items.length === 0 && (
              <tr><td colSpan={7} className="p-4"><Skeleton className="h-10" /><Skeleton className="mt-2 h-10" /></td></tr>
            )}
            {items.map((cn) => (
              <tr key={cn.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50/60">
                <td className="px-4 py-3">
                  <p className="font-mono text-caption font-semibold text-ink">{cn.ref}</p>
                  {cn.invoiceId && <p className="text-caption text-subtle">invoice #{cn.invoiceId}</p>}
                </td>
                <td className="px-4 py-3 text-small text-ink">{cn.customer}</td>
                <td className="px-4 py-3 text-caption text-slate-700">{cn.reason ?? "—"}</td>
                <td className="px-4 py-3 text-right text-small font-semibold tabular-nums text-ink">
                  {fmtPKR(cn.total)}
                  {cn.tax > 0 && <span className="block text-caption font-normal text-subtle">incl. {fmtPKR(cn.tax)} tax</span>}
                </td>
                <td className="px-4 py-3">
                  <Badge tone={TONE[cn.status] ?? "neutral"} dot={false}>{cn.status}</Badge>
                </td>
                <td className="px-4 py-3">
                  {cn.journalEntryId ? (
                    <span className="flex items-center gap-1.5 text-caption text-success-strong"><FileText size={13} /> JE #{cn.journalEntryId}</span>
                  ) : (
                    <span className="text-caption text-subtle">not posted</span>
                  )}
                </td>
                <td className="px-4 py-3 text-right">
                  {cn.status === "draft" ? (
                    <div className="flex justify-end gap-2">
                      <Button size="sm" onClick={() => advance(cn, "issued")}>Issue</Button>
                      <Button variant="secondary" size="sm" onClick={() => advance(cn, "cancelled")}>Cancel</Button>
                    </div>
                  ) : cn.status === "issued" ? (
                    <Button variant="secondary" size="sm" onClick={() => advance(cn, "applied")}>
                      <Undo2 size={14} /> Mark applied
                    </Button>
                  ) : (
                    <span className="text-caption text-subtle">closed</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {!list.loading && items.length === 0 && (
          <EmptyState
            icon={<Undo2 size={20} />}
            title={filter ? `No ${filter} credit notes` : "No credit notes yet"}
            description={filter
              ? "Clear the filter to see every credit note."
              : "Credit a customer for damages, a return or goodwill. Nothing reaches the ledger until you issue it."}
            action={!filter ? <Button onClick={() => { setForm({ ...blank }); setOpen(true); }}><Plus size={15} /> New credit note</Button> : undefined}
          />
        )}
      </Card>

      <Modal open={open} onClose={() => setOpen(false)} title="New credit note">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="Customer" required className="sm:col-span-2">
            <Input value={form.customer} onChange={(e) => setForm({ ...form, customer: e.target.value })} placeholder="Clifton Villa" />
          </Field>
          <Field label="Subtotal" required>
            <Input type="number" min={0} value={form.subtotal} onChange={(e) => setForm({ ...form, subtotal: e.target.value })} placeholder="0" />
          </Field>
          <Field label="Tax">
            <Input type="number" min={0} value={form.tax} onChange={(e) => setForm({ ...form, tax: e.target.value })} />
          </Field>
          <Field label="Against invoice" hint="Optional" className="sm:col-span-2">
            <Select value={form.invoiceId} onChange={(e) => {
              const inv = invoices.data?.items?.find((i) => String(i.id) === e.target.value);
              setForm({ ...form, invoiceId: e.target.value, customer: form.customer || inv?.customer || "" });
            }}>
              <option value="">Not against a specific invoice</option>
              {(invoices.data?.items ?? []).map((i) => (
                <option key={i.id} value={i.id}>{i.ref} · {i.customer} · {fmtPKR(i.total)}</option>
              ))}
            </Select>
          </Field>
          <Field label="Reason" className="sm:col-span-2">
            <Input value={form.reason} onChange={(e) => setForm({ ...form, reason: e.target.value })} placeholder="Damaged panel, wrong finish, goodwill…" />
          </Field>
          <Field label="Note" className="sm:col-span-2">
            <Textarea value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} />
          </Field>
        </div>

        <div className="mt-3 flex items-center justify-between rounded-control bg-slate-50 px-3 py-2">
          <span className="text-caption text-slate-700">Credit total</span>
          <span className="text-small font-semibold tabular-nums text-ink">{fmtPKR(previewTotal)}</span>
        </div>

        {err && <p role="alert" className="mt-3 rounded-control border border-danger/30 bg-danger-soft px-3 py-2 text-caption font-semibold text-danger-strong">{err}</p>}
        <div className="mt-4 flex items-center justify-between gap-2">
          <p className="text-caption text-subtle">A draft posts nothing.</p>
          <div className="flex gap-2">
            <Button variant="secondary" onClick={() => setOpen(false)} disabled={saving}>Cancel</Button>
            <Button onClick={save} disabled={saving}>{saving ? "Saving…" : "Create draft"}</Button>
          </div>
        </div>
      </Modal>
    </div>
  );
}

import { useState } from "react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui/States";
import { Field, Input, Select, Textarea } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { useToast } from "@/components/ui/Toast";
import { useApi, mutate, fmtPKR } from "@/lib/api";
import { Plus, RefreshCw, Banknote, FileText } from "@/icons";

/* Expenses — money out that is not a supplier invoice.
 *
 * A supplier bill is a document that matches an order and a receipt; rent is
 * neither, and forcing it through the three-way check would fail every time.
 *
 * Every expense posts to the journal in the same transaction as its row, so
 * there is no state in which an expense exists and is not in the books. That
 * is the whole reason this screen shows the journal reference rather than
 * hiding it: the operator can see that the two agree. */

type Expense = {
  id: number; ref: string; category: string; payee: string | null; amount: number;
  taxAmount: number; spentAt: string; method: string | null; reference: string | null;
  journalEntryId: number | null; note: string | null; by: string | null;
};
type ExpenseList = {
  total: number; totalAmount: number; totalTax: number;
  byCategory: { category: string; amount: number }[];
  items: Expense[];
};

const CATEGORIES = ["Rent", "Salaries and wages", "Utilities", "Transport and freight", "Tools and consumables", "Marketing", "Other"];

/* Which account each category debits. Kept in the client only as a default —
   the server accepts an explicit account and validates it, so this list is a
   convenience, not the source of truth. */
const CATEGORY_ACCOUNT: Record<string, string> = {
  "Rent": "6000", "Salaries and wages": "6100", "Utilities": "6200",
  "Transport and freight": "6300", "Tools and consumables": "6900",
  "Marketing": "6900", "Other": "6900",
};

const blank = {
  category: "Rent", amount: "", taxAmount: "0", spentAt: new Date().toISOString().slice(0, 10),
  payee: "", method: "", reference: "", paidFrom: "1010", note: "",
};

export default function Expenses() {
  const { push } = useToast();
  const [open, setOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [form, setForm] = useState({ ...blank });
  const [category, setCategory] = useState("");

  const path = `/api/expenses${category ? `?category=${encodeURIComponent(category)}` : ""}`;
  const list = useApi<ExpenseList>(path, 20000);
  const accounts = useApi<{ items: { code: string; name: string; kind: string }[] }>("/api/accounts?active=true", 120000);

  const items = list.data?.items ?? [];
  const cashAccounts = (accounts.data?.items ?? []).filter((a) => a.kind === "asset");

  const save = async () => {
    if (saving) return;
    setErr(null);
    const amount = Number(form.amount);
    if (!(amount > 0)) { setErr("Amount must be greater than zero."); return; }
    setSaving(true);
    try {
      await mutate("/api/expenses", {
        category: form.category, amount,
        taxAmount: Number(form.taxAmount) || 0,
        spentAt: form.spentAt, payee: form.payee || null,
        method: form.method || null, reference: form.reference || null,
        paidFrom: form.paidFrom,
        expenseAccount: CATEGORY_ACCOUNT[form.category] ?? "6900",
        note: form.note || null,
      }, "POST");
      push({
        tone: "success",
        title: `${fmtPKR(amount)} recorded`,
        desc: "Posted to the ledger in the same transaction — the books already know.",
      });
      setOpen(false); setForm({ ...blank }); list.reload();
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    } finally {
      setSaving(false);
    }
  };

  if (list.error)
    return (
      <div>
        <PageHeader title="Expenses" />
        <ErrorState title="Expenses API unreachable" description="The finance backend did not answer." debug={list.error} onRetry={list.reload} />
      </div>
    );

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Finance", to: "/ledger" }, { label: "Expenses" }]}
        title="Expenses"
        description="Money out that has no supplier bill behind it — rent, wages, fuel, tools. Each one posts to the ledger immediately."
        actions={
          <>
            <Button variant="secondary" onClick={list.reload}><RefreshCw size={15} /> Refresh</Button>
            <Button onClick={() => { setForm({ ...blank }); setErr(null); setOpen(true); }}><Plus size={15} /> Record expense</Button>
          </>
        }
      />

      <div className="mb-4 grid grid-cols-1 gap-3 sm:grid-cols-3">
        <Card className="py-4">
          <p className="text-caption uppercase text-subtle">Recorded</p>
          <p className="mt-1 text-h2 font-semibold text-ink">{list.loading ? "—" : list.data?.total ?? 0}</p>
          <p className="text-caption text-subtle">expenses</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption uppercase text-subtle">Total spent</p>
          <p className="mt-1 text-h2 font-semibold text-ink">{list.loading ? "—" : fmtPKR(list.data?.totalAmount ?? 0)}</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption uppercase text-subtle">Recoverable tax</p>
          <p className="mt-1 text-h2 font-semibold text-ink">{list.loading ? "—" : fmtPKR(list.data?.totalTax ?? 0)}</p>
          <p className="text-caption text-subtle">input tax recorded</p>
        </Card>
      </div>

      {(list.data?.byCategory?.length ?? 0) > 0 && (
        <Card className="mb-4 py-4">
          <p className="mb-2 text-caption font-semibold uppercase text-subtle">By category</p>
          <div className="space-y-1.5">
            {list.data!.byCategory.map((c) => {
              const pct = list.data!.totalAmount > 0 ? (c.amount / list.data!.totalAmount) * 100 : 0;
              return (
                <div key={c.category} className="flex items-center gap-3">
                  <button
                    onClick={() => setCategory(category === c.category ? "" : c.category)}
                    className="w-44 text-left text-small text-slate-700 hover:text-ink"
                  >
                    {c.category}
                  </button>
                  <div className="h-2 flex-1 overflow-hidden rounded-full bg-slate-100">
                    <div className="h-full rounded-full bg-primary-500" style={{ width: `${pct}%` }} />
                  </div>
                  <span className="w-28 text-right text-caption tabular-nums text-slate-700">{fmtPKR(c.amount)}</span>
                </div>
              );
            })}
          </div>
        </Card>
      )}

      {category && (
        <div className="mb-3 flex items-center gap-2">
          <span className="text-caption text-subtle">Filtered to</span>
          <Button variant="secondary" size="sm" onClick={() => setCategory("")}>{category} ×</Button>
        </div>
      )}

      <Card className="overflow-hidden p-0">
        <table className="w-full text-left">
          <thead className="border-b border-line bg-slate-50 text-caption uppercase text-subtle">
            <tr>
              <th className="px-4 py-2.5 font-semibold">Ref</th>
              <th className="px-4 py-2.5 font-semibold">Date</th>
              <th className="px-4 py-2.5 font-semibold">Category</th>
              <th className="px-4 py-2.5 font-semibold">Payee</th>
              <th className="px-4 py-2.5 text-right font-semibold">Amount</th>
              <th className="px-4 py-2.5 font-semibold">In the books</th>
            </tr>
          </thead>
          <tbody>
            {list.loading && items.length === 0 && (
              <tr><td colSpan={6} className="p-4"><Skeleton className="h-10" /><Skeleton className="mt-2 h-10" /></td></tr>
            )}
            {items.map((e) => (
              <tr key={e.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50/60">
                <td className="px-4 py-3 font-mono text-caption text-subtle">{e.ref}</td>
                <td className="px-4 py-3 text-caption text-slate-700">{e.spentAt}</td>
                <td className="px-4 py-3 text-small text-ink">{e.category}</td>
                <td className="px-4 py-3 text-small text-slate-700">
                  {e.payee ?? "—"}
                  {e.reference && <span className="block text-caption text-subtle">ref {e.reference}</span>}
                </td>
                <td className="px-4 py-3 text-right text-small font-semibold tabular-nums text-ink">
                  {fmtPKR(e.amount)}
                  {e.taxAmount > 0 && <span className="block text-caption font-normal text-subtle">+{fmtPKR(e.taxAmount)} tax</span>}
                </td>
                <td className="px-4 py-3">
                  {e.journalEntryId ? (
                    <span className="flex items-center gap-1.5 text-caption text-success-strong">
                      <FileText size={13} /> JE #{e.journalEntryId}
                    </span>
                  ) : (
                    <span className="text-caption text-danger-strong">not posted</span>
                  )}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {!list.loading && items.length === 0 && (
          <EmptyState
            icon={<Banknote size={20} />}
            title={category ? `No expenses in ${category}` : "No expenses recorded"}
            description={category
              ? "Clear the filter to see every expense."
              : "Record rent, wages or fuel here. Each one posts to the ledger as it is saved."}
            action={!category ? <Button onClick={() => { setForm({ ...blank }); setOpen(true); }}><Plus size={15} /> Record expense</Button> : undefined}
          />
        )}
      </Card>

      <Modal open={open} onClose={() => setOpen(false)} title="Record an expense">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="Category" required>
            <Select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })}>
              {CATEGORIES.map((c) => <option key={c} value={c}>{c}</option>)}
            </Select>
          </Field>
          <Field label="Amount" required>
            <Input type="number" min={0} value={form.amount} onChange={(e) => setForm({ ...form, amount: e.target.value })} placeholder="0" />
          </Field>
          <Field label="Tax included" hint="Recoverable input tax, if any">
            <Input type="number" min={0} value={form.taxAmount} onChange={(e) => setForm({ ...form, taxAmount: e.target.value })} />
          </Field>
          <Field label="Date" required>
            <Input type="date" value={form.spentAt} onChange={(e) => setForm({ ...form, spentAt: e.target.value })} />
          </Field>
          <Field label="Payee">
            <Input value={form.payee} onChange={(e) => setForm({ ...form, payee: e.target.value })} placeholder="Who was paid" />
          </Field>
          <Field label="Paid from" required>
            <Select value={form.paidFrom} onChange={(e) => setForm({ ...form, paidFrom: e.target.value })}>
              {cashAccounts.length === 0 && <option value="1010">1010 · Bank — current</option>}
              {cashAccounts.map((a) => <option key={a.code} value={a.code}>{a.code} · {a.name}</option>)}
            </Select>
          </Field>
          <Field label="Method">
            <Input value={form.method} onChange={(e) => setForm({ ...form, method: e.target.value })} placeholder="Cash, cheque, transfer" />
          </Field>
          <Field label="Reference">
            <Input value={form.reference} onChange={(e) => setForm({ ...form, reference: e.target.value })} placeholder="Voucher or cheque no." />
          </Field>
          <Field label="Note" className="sm:col-span-2">
            <Textarea value={form.note} onChange={(e) => setForm({ ...form, note: e.target.value })} />
          </Field>
        </div>
        {err && <p role="alert" className="mt-3 rounded-control border border-danger/30 bg-danger-soft px-3 py-2 text-caption font-semibold text-danger-strong">{err}</p>}
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setOpen(false)} disabled={saving}>Cancel</Button>
          <Button onClick={save} disabled={saving}>{saving ? "Recording…" : "Record & post"}</Button>
        </div>
      </Modal>
    </div>
  );
}

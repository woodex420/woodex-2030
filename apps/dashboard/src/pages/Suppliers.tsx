import { useState } from "react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui/States";
import { Field, Input, Select, Textarea } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { useToast } from "@/components/ui/Toast";
import { useApi, mutate } from "@/lib/api";
import { Plus, RefreshCw, Building, Search } from "@/icons";

/* Suppliers — the screen that was missing entirely.
 *
 * The procurement API shipped in 009 and has been live in production since,
 * but nothing ever called it. A supplier could only be created with curl. This
 * page is the difference between "the backend exists" and "the business can
 * use it", which is the distinction the spec draws everywhere.
 *
 * No mock rows: an empty table here means the database is empty, and says so. */

type Supplier = {
  id: number; code: string; name: string; contactName: string | null; phone: string | null;
  email: string | null; address: string | null; taxRef: string | null;
  paymentTerms: string | null; currency: string; active: boolean; notes: string | null;
};

const blank = { code: "", name: "", contactName: "", phone: "", email: "", address: "", taxRef: "", paymentTerms: "", notes: "" };

export default function Suppliers() {
  const { push } = useToast();
  const [q, setQ] = useState("");
  const [showInactive, setShowInactive] = useState(false);
  const [open, setOpen] = useState(false);
  const [form, setForm] = useState({ ...blank });
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  const path = `/api/suppliers${q ? `?q=${encodeURIComponent(q)}` : ""}`;
  const { data, error, loading, reload } = useApi<{ total: number; active: number; items: Supplier[] }>(path, 20000);

  const items = (data?.items ?? []).filter((s) => showInactive || s.active);

  const save = async () => {
    if (saving) return;
    setErr(null);
    /* Validated here as well as in the route. The route is the authority (the
       API is public to any authenticated client, not just this form), but a
       round-trip to be told a required field is empty is a poor first
       experience when the answer is already known. */
    if (!form.code.trim() || !form.name.trim()) {
      setErr("Supplier code and name are required.");
      return;
    }
    setSaving(true);
    try {
      await mutate<Supplier>("/api/suppliers", {
        code: form.code.trim(), name: form.name.trim(),
        contactName: form.contactName || null, phone: form.phone || null, email: form.email || null,
        address: form.address || null, taxRef: form.taxRef || null,
        paymentTerms: form.paymentTerms || null, notes: form.notes || null,
      }, "POST");
      push({ tone: "success", title: `${form.name.trim()} added`, desc: "Raise a purchase order against them next." });
      setOpen(false);
      setForm({ ...blank });
      reload();
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    } finally {
      setSaving(false);
    }
  };

  const toggleActive = async (s: Supplier) => {
    try {
      await mutate(`/api/suppliers/${s.id}`, { active: !s.active });
      push({ tone: s.active ? "info" : "success", title: `${s.name} ${s.active ? "deactivated" : "reactivated"}` });
      reload();
    } catch (e) {
      push({ tone: "danger", title: "Could not update supplier", desc: e instanceof Error ? e.message : String(e) });
    }
  };

  if (error)
    return (
      <div>
        <PageHeader title="Suppliers" />
        <ErrorState title="Suppliers API unreachable" description="The procurement backend did not answer." debug={error} onRetry={reload} />
      </div>
    );

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Operations", to: "/operations" }, { label: "Suppliers" }]}
        title="Suppliers"
        description="Who Woodex buys from. Purchase orders and goods receipts are raised against these records."
        actions={
          <>
            <Button variant="secondary" onClick={reload}><RefreshCw size={15} /> Refresh</Button>
            <Button onClick={() => { setForm({ ...blank }); setErr(null); setOpen(true); }}>
              <Plus size={15} /> New supplier
            </Button>
          </>
        }
      />

      <Card className="mb-4 flex flex-wrap items-end gap-3 py-4">
        <div className="min-w-[220px] flex-1">
          <Field label="Search">
            <div className="relative">
              <Search size={15} className="pointer-events-none absolute top-1/2 left-3 -translate-y-1/2 text-subtle" />
              <Input value={q} onChange={(e) => setQ(e.target.value)} placeholder="code, name, email or tax ref" className="pl-9" />
            </div>
          </Field>
        </div>
        <label className="flex h-10 items-center gap-2 text-small text-slate-700">
          <input type="checkbox" checked={showInactive} onChange={(e) => setShowInactive(e.target.checked)} />
          Show inactive
        </label>
        <div className="ml-auto text-caption text-subtle">
          {loading ? "syncing…" : `${items.length} shown · ${data?.active ?? 0} active of ${data?.total ?? 0}`}
        </div>
      </Card>

      <Card className="overflow-hidden p-0">
        <table className="w-full text-left">
          <thead className="border-b border-line bg-slate-50 text-caption uppercase text-subtle">
            <tr>
              <th className="px-4 py-2.5 font-semibold">Code</th>
              <th className="px-4 py-2.5 font-semibold">Supplier</th>
              <th className="px-4 py-2.5 font-semibold">Contact</th>
              <th className="px-4 py-2.5 font-semibold">Terms</th>
              <th className="px-4 py-2.5 font-semibold">Status</th>
              <th className="px-4 py-2.5 font-semibold text-right">Action</th>
            </tr>
          </thead>
          <tbody>
            {loading && items.length === 0 && (
              <tr><td colSpan={6} className="p-4"><Skeleton className="h-10" /><Skeleton className="mt-2 h-10" /></td></tr>
            )}
            {items.map((s) => (
              <tr key={s.id} className="border-b border-slate-100 last:border-0 hover:bg-slate-50/60">
                <td className="px-4 py-3 font-mono text-caption text-subtle">{s.code}</td>
                <td className="px-4 py-3">
                  <p className="text-small font-semibold text-ink">{s.name}</p>
                  {s.email && <p className="text-caption text-muted">{s.email}</p>}
                </td>
                <td className="px-4 py-3 text-small text-slate-700">
                  {s.contactName ?? "—"}
                  {s.phone && <span className="block text-caption text-subtle">{s.phone}</span>}
                </td>
                <td className="px-4 py-3 text-small text-slate-700">{s.paymentTerms ?? "—"}</td>
                <td className="px-4 py-3">
                  <Badge tone={s.active ? "success" : "neutral"} dot={false}>{s.active ? "Active" : "Inactive"}</Badge>
                </td>
                <td className="px-4 py-3 text-right">
                  <Button variant="secondary" size="sm" onClick={() => toggleActive(s)}>
                    {s.active ? "Deactivate" : "Reactivate"}
                  </Button>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
        {!loading && items.length === 0 && (
          <EmptyState
            icon={<Building size={20} />}
            title={q ? "No supplier matches that search" : "No suppliers yet"}
            description={q ? "Try a shorter search, or clear it to see all suppliers." : "Add the first supplier, then raise a purchase order against them."}
            action={!q ? <Button onClick={() => { setForm({ ...blank }); setOpen(true); }}><Plus size={15} /> New supplier</Button> : undefined}
          />
        )}
      </Card>

      <Modal open={open} onClose={() => setOpen(false)} title="New supplier">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="Supplier code" required hint="Short, unique — e.g. SUP-TIMBER">
            <Input value={form.code} onChange={(e) => setForm({ ...form, code: e.target.value })} placeholder="SUP-TIMBER" />
          </Field>
          <Field label="Name" required>
            <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Bill Timber Co" />
          </Field>
          <Field label="Contact person">
            <Input value={form.contactName} onChange={(e) => setForm({ ...form, contactName: e.target.value })} />
          </Field>
          <Field label="Phone">
            <Input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} />
          </Field>
          <Field label="Email">
            <Input type="email" value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} />
          </Field>
          <Field label="Tax reference (NTN)">
            <Input value={form.taxRef} onChange={(e) => setForm({ ...form, taxRef: e.target.value })} />
          </Field>
          <Field label="Payment terms">
            <Select value={form.paymentTerms} onChange={(e) => setForm({ ...form, paymentTerms: e.target.value })}>
              <option value="">Not agreed</option>
              <option value="Advance">Advance</option>
              <option value="Net 15">Net 15</option>
              <option value="Net 30">Net 30</option>
              <option value="Net 45">Net 45</option>
              <option value="Net 60">Net 60</option>
            </Select>
          </Field>
          <Field label="Address">
            <Input value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} />
          </Field>
          <Field label="Notes" className="sm:col-span-2">
            <Textarea value={form.notes} onChange={(e) => setForm({ ...form, notes: e.target.value })} />
          </Field>
        </div>
        {err && <p role="alert" className="mt-3 rounded-control border border-danger/30 bg-danger-soft px-3 py-2 text-caption font-semibold text-danger-strong">{err}</p>}
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setOpen(false)} disabled={saving}>Cancel</Button>
          <Button onClick={save} disabled={saving}>{saving ? "Saving…" : "Create supplier"}</Button>
        </div>
      </Modal>
    </div>
  );
}

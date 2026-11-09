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
import { Plus, RefreshCw, Truck, MapPin, Clock, Calendar, ShieldCheck, FileText, Lock } from "@/icons";

/* Deliveries — what left the building, what arrived, and who signed.
 *
 * A stage transition is a single current word. It cannot say who drove, what was
 * on the vehicle, which items arrived in which run, or who took receipt. Those
 * are the questions a delivery raises and the questions a dispute turns on.
 *
 * Marking a delivery delivered requires a signature and at least one line. The
 * server refuses it otherwise, and this screen asks first — "delivered" with no
 * evidence is worse than no record at all, because it looks authoritative.
 *
 * Partial delivery is normal: a kitchen arrives in three runs. The shortfall
 * shown per order is computed from the lines, not set by hand. */

type DispatchRun = {
  id: number; ref: string; vehicle: string | null; driver: string | null; crew: string | null;
  address: string | null; city: string | null; scheduledFor: string | null;
  departedAt: string | null; returnedAt: string | null; status: string;
  notes: string | null; deliveryCount: number;
};
type Delivery = {
  id: number; ref: string; orderId: number | null; orderRef: string | null;
  projectId: number | null; projectName: string | null; dispatchRunId: number | null;
  runRef: string | null; customer: string; address: string | null;
  deliveredAt: string | null; receivedBy: string | null; signedBy: string | null;
  photo: string | null; condition: string; damageNotes: string | null;
  status: string; notes: string | null; lineCount: number; units: number;
};
type InstalledAsset = {
  id: number; tag: string; name: string; room: string | null; location: string | null;
  installedOn: string | null; warrantyUntil: string | null; status: string;
  warrantyMonths: number | null; inWarranty: boolean | null;
};
type Order = { id: number; ref: string; customer: string };

const RUN_TONE: Record<string, "neutral" | "info" | "success" | "warning"> = {
  planned: "neutral", dispatched: "info", completed: "success", cancelled: "warning",
};
const DEL_TONE: Record<string, "neutral" | "info" | "success" | "warning" | "danger"> = {
  pending: "neutral", in_transit: "info", delivered: "success", failed: "danger", returned: "warning",
};
const COND_TONE: Record<string, "success" | "warning" | "danger"> = {
  good: "success", partial: "warning", damaged: "warning", rejected: "danger",
};

const dt = (v: string | null) => (v ? new Date(v).toLocaleString(undefined, {
  day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) : "—");
const day = (v: string | null) => (v ? new Date(v).toLocaleDateString(undefined, {
  day: "2-digit", month: "short", year: "numeric" }) : "—");

const TABS = [
  { id: "deliveries", label: "Deliveries" },
  { id: "runs", label: "Dispatch runs" },
  { id: "assets", label: "Installed assets" },
];

export default function Deliveries() {
  const { push } = useToast();
  const [tab, setTab] = useState("deliveries");
  const [newOpen, setNewOpen] = useState(false);
  const [runOpen, setRunOpen] = useState(false);
  const [assetOpen, setAssetOpen] = useState(false);
  const [signFor, setSignFor] = useState<Delivery | null>(null);
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  const [form, setForm] = useState({ customer: "", orderId: "", address: "", description: "", qty: "1", unit: "pcs" });
  const [runForm, setRunForm] = useState({ vehicle: "", driver: "", crew: "", scheduledFor: "", address: "", city: "" });
  const [assetForm, setAssetForm] = useState({ name: "", room: "", location: "", warrantyMonths: "12", orderId: "" });
  const [signature, setSignature] = useState({ signedBy: "", condition: "good", damageNotes: "" });

  const deliveries = useApi<{ total: number; items: Delivery[] }>("/api/deliveries", 25000);
  const runs = useApi<{ total: number; items: DispatchRun[] }>("/api/dispatch-runs", 25000);
  const assets = useApi<{ total: number; inWarranty: number; items: InstalledAsset[] }>("/api/installed-assets", 25000);
  const orders = useApi<{ items: Order[] }>("/api/orders", 120000);

  const reloadAll = () => { deliveries.reload(); runs.reload(); assets.reload(); };

  const createDelivery = async () => {
    if (saving) return;
    setErr(null);
    const qty = Number(form.qty);
    if (!form.customer.trim()) { setErr("Say who this is going to."); return; }
    if (!form.description.trim()) { setErr("A delivery needs at least one line saying what is on it."); return; }
    if (!Number.isInteger(qty) || qty <= 0) { setErr("Quantity must be a positive whole number."); return; }
    setSaving(true);
    try {
      await mutate("/api/deliveries", {
        customer: form.customer.trim(),
        orderId: form.orderId ? Number(form.orderId) : null,
        address: form.address || null,
        lines: [{ description: form.description.trim(), qty, unit: form.unit || null }],
      }, "POST");
      push({ tone: "success", title: "Delivery recorded" });
      setNewOpen(false);
      setForm({ customer: "", orderId: "", address: "", description: "", qty: "1", unit: "pcs" });
      reloadAll();
    } catch (e) {
      setErr(e instanceof Error ? e.message : "Could not record the delivery.");
    } finally { setSaving(false); }
  };

  const createRun = async () => {
    if (saving) return;
    setErr(null);
    setSaving(true);
    try {
      await mutate("/api/dispatch-runs", {
        vehicle: runForm.vehicle || null, driver: runForm.driver || null,
        crew: runForm.crew || null,
        scheduledFor: runForm.scheduledFor ? new Date(runForm.scheduledFor).toISOString() : null,
        address: runForm.address || null, city: runForm.city || null,
      }, "POST");
      push({ tone: "success", title: "Run planned" });
      setRunOpen(false);
      setRunForm({ vehicle: "", driver: "", crew: "", scheduledFor: "", address: "", city: "" });
      reloadAll();
    } catch (e) {
      setErr(e instanceof Error ? e.message : "Could not plan the run.");
    } finally { setSaving(false); }
  };

  const installAsset = async () => {
    if (saving) return;
    setErr(null);
    if (!assetForm.name.trim()) { setErr("What was installed?"); return; }
    setSaving(true);
    try {
      const out = await mutate<{ asset: InstalledAsset }>("/api/installed-assets", {
        name: assetForm.name.trim(), room: assetForm.room || null,
        location: assetForm.location || null,
        warrantyMonths: assetForm.warrantyMonths === "" ? null : Number(assetForm.warrantyMonths),
        orderId: assetForm.orderId ? Number(assetForm.orderId) : null,
      }, "POST");
      push({ tone: "success", title: "Asset installed",
        desc: out.asset.warrantyUntil ? `Warranty to ${out.asset.warrantyUntil}` : "No warranty recorded" });
      setAssetOpen(false);
      setAssetForm({ name: "", room: "", location: "", warrantyMonths: "12", orderId: "" });
      reloadAll();
    } catch (e) {
      setErr(e instanceof Error ? e.message : "Could not record the install.");
    } finally { setSaving(false); }
  };

  const confirmDelivery = async () => {
    if (!signFor || saving) return;
    setErr(null);
    if (!signature.signedBy.trim()) {
      setErr("A delivery that arrived names who signed for it. That is the whole point of the record.");
      return;
    }
    if (signature.condition !== "good" && !signature.damageNotes.trim()) {
      setErr("Say what was wrong — a condition with no note is a claim nobody can act on.");
      return;
    }
    setSaving(true);
    try {
      await mutate(`/api/deliveries/${signFor.id}`, {
        status: "delivered", signedBy: signature.signedBy.trim(),
        condition: signature.condition,
        damageNotes: signature.damageNotes || null,
      });
      push({ tone: "success", title: "Delivery confirmed", desc: `Signed by ${signature.signedBy.trim()}` });
      setSignFor(null);
      setSignature({ signedBy: "", condition: "good", damageNotes: "" });
      reloadAll();
    } catch (e) {
      setErr(e instanceof Error ? e.message : "Could not confirm the delivery.");
    } finally { setSaving(false); }
  };

  const advanceRun = async (r: DispatchRun, status: string) => {
    try {
      await mutate(`/api/dispatch-runs/${r.id}`, { status });
      push({ tone: "info", title: `Run ${status}`, desc: r.ref });
      reloadAll();
    } catch (e) {
      push({ tone: "danger", title: "Could not update the run", desc: e instanceof Error ? e.message : String(e) });
    }
  };

  return (
    <>
      <PageHeader
        title="Delivery & installation"
        description="Runs out of the warehouse, arrivals with proof, and the assets left in the building."
        actions={
          <>
            <Button variant="ghost" onClick={reloadAll}><RefreshCw size={16} /> Refresh</Button>
            <Button variant="ghost" onClick={() => { setErr(null); setRunOpen(true); }}><Truck size={16} /> Plan run</Button>
            <Button onClick={() => { setErr(null); setNewOpen(true); }}><Plus size={16} /> New delivery</Button>
          </>
        }
      />

      <div className="flex flex-wrap gap-1 mb-4">
        {TABS.map((t) => (
          <button key={t.id} onClick={() => setTab(t.id)}
            className={`px-3 py-1.5 rounded-lg text-sm transition ${
              tab === t.id ? "bg-primary-600 text-white" : "bg-surface-2 text-muted hover:text-fg"}`}>
            {t.label}
          </button>
        ))}
      </div>

      {tab === "deliveries" && (
        deliveries.loading && !deliveries.data ? (
          <Card className="p-4 space-y-3"><Skeleton className="h-16" /><Skeleton className="h-16" /></Card>
        ) : deliveries.error ? (
          <Card><ErrorState title="Deliveries API unreachable" debug={deliveries.error} onRetry={deliveries.reload} /></Card>
        ) : (deliveries.data?.items ?? []).length === 0 ? (
          <Card><EmptyState icon={<Truck size={20} />} title="No deliveries yet"
            description="Every arrival is recorded with what it carried and who signed for it." /></Card>
        ) : (
          <Card className="p-0 overflow-hidden">
            <ul className="divide-y divide-line">
              {(deliveries.data?.items ?? []).map((d) => (
                <li key={d.id} className="px-4 py-3">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-mono text-sm">{d.ref}</span>
                        <Badge tone={DEL_TONE[d.status] ?? "neutral"}>{d.status.replace("_", " ")}</Badge>
                        <Badge tone={COND_TONE[d.condition] ?? "neutral"}>{d.condition}</Badge>
                        <span className="font-medium truncate">{d.customer}</span>
                      </div>
                      <div className="text-xs text-muted mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5">
                        {d.orderRef && <span className="flex items-center gap-1"><FileText size={12} /> {d.orderRef}</span>}
                        {d.runRef && <span className="flex items-center gap-1"><Truck size={12} /> {d.runRef}</span>}
                        <span>{d.lineCount} line{d.lineCount === 1 ? "" : "s"} · {d.units} unit{d.units === 1 ? "" : "s"}</span>
                        {d.address && <span className="flex items-center gap-1"><MapPin size={12} /> {d.address}</span>}
                        {d.deliveredAt && <span className="flex items-center gap-1"><Clock size={12} /> {dt(d.deliveredAt)}</span>}
                      </div>
                      {d.signedBy && <div className="text-xs mt-1"><span className="text-muted">Signed by:</span> {d.signedBy}</div>}
                      {d.damageNotes && <div className="text-xs mt-0.5 text-warning-strong">{d.damageNotes}</div>}
                    </div>
                    {d.status !== "delivered" && d.lineCount > 0 && (
                      <Button variant="ghost" onClick={() => {
                        setErr(null);
                        setSignature({ signedBy: "", condition: "good", damageNotes: "" });
                        setSignFor(d);
                      }}>Confirm arrival</Button>
                    )}
                  </div>
                </li>
              ))}
            </ul>
          </Card>
        )
      )}

      {tab === "runs" && (
        runs.error ? <Card><ErrorState title="Dispatch API unreachable" debug={runs.error} onRetry={runs.reload} /></Card>
        : (runs.data?.items ?? []).length === 0 ? (
          <Card><EmptyState icon={<Truck size={20} />} title="No runs planned"
            description="A run is a vehicle, a crew and a date. Deliveries hang off it." /></Card>
        ) : (
          <Card className="p-0 overflow-hidden">
            <ul className="divide-y divide-line">
              {(runs.data?.items ?? []).map((r) => (
                <li key={r.id} className="px-4 py-3">
                  <div className="flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-mono text-sm">{r.ref}</span>
                        <Badge tone={RUN_TONE[r.status] ?? "neutral"}>{r.status}</Badge>
                        <span className="font-medium">{r.vehicle ?? "No vehicle"}</span>
                        {r.driver && <span className="text-sm text-muted">· {r.driver}</span>}
                      </div>
                      <div className="text-xs text-muted mt-0.5 flex flex-wrap items-center gap-x-3">
                        <span className="flex items-center gap-1"><Calendar size={12} /> {dt(r.scheduledFor)}</span>
                        {r.address && <span className="flex items-center gap-1"><MapPin size={12} /> {r.address}</span>}
                        <span>{r.deliveryCount} deliver{r.deliveryCount === 1 ? "y" : "ies"}</span>
                        {r.departedAt && <span className="flex items-center gap-1"><Lock size={11} /> departed {dt(r.departedAt)}</span>}
                      </div>
                    </div>
                    <div className="flex gap-1 shrink-0">
                      {r.status === "planned" && <Button variant="ghost" onClick={() => advanceRun(r, "dispatched")}>Dispatch</Button>}
                      {r.status === "dispatched" && <Button variant="ghost" onClick={() => advanceRun(r, "completed")}>Back in</Button>}
                    </div>
                  </div>
                </li>
              ))}
            </ul>
          </Card>
        )
      )}

      {tab === "assets" && (
        <>
          <div className="flex justify-end mb-3">
            <Button onClick={() => { setErr(null); setAssetOpen(true); }}><Plus size={16} /> Record install</Button>
          </div>
          {assets.error ? <Card><ErrorState title="Assets API unreachable" debug={assets.error} onRetry={assets.reload} /></Card>
          : (assets.data?.items ?? []).length === 0 ? (
            <Card><EmptyState icon={<ShieldCheck size={20} />} title="Nothing installed yet"
              description="An installed asset is a physical object at a place, with a warranty that runs from when it went in." /></Card>
          ) : (
            <Card className="p-0 overflow-hidden">
              <div className="px-4 py-2 border-b border-line text-xs text-muted">
                {assets.data?.inWarranty ?? 0} of {assets.data?.total ?? 0} under warranty
              </div>
              <ul className="divide-y divide-line">
                {(assets.data?.items ?? []).map((a) => (
                  <li key={a.id} className="px-4 py-3 flex items-start justify-between gap-3">
                    <div className="min-w-0">
                      <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-mono text-xs">{a.tag}</span>
                        <span className="font-medium">{a.name}</span>
                        {a.inWarranty === true && <Badge tone="success">In warranty</Badge>}
                        {a.inWarranty === false && <Badge tone="neutral">Warranty ended</Badge>}
                        {a.status !== "installed" && <Badge tone="warning">{a.status}</Badge>}
                      </div>
                      <div className="text-xs text-muted mt-0.5 flex flex-wrap items-center gap-x-3">
                        {a.room && <span>{a.room}</span>}
                        {a.location && <span className="flex items-center gap-1"><MapPin size={12} /> {a.location}</span>}
                        <span>Installed {day(a.installedOn)}</span>
                        {a.warrantyUntil && <span className="flex items-center gap-1"><ShieldCheck size={12} /> to {day(a.warrantyUntil)}</span>}
                      </div>
                    </div>
                  </li>
                ))}
              </ul>
            </Card>
          )}
        </>
      )}

      {/* new delivery */}
      <Modal open={newOpen} onClose={() => setNewOpen(false)} title="Record a delivery">
        <div className="space-y-3">
          {err && <div className="text-sm text-danger">{err}</div>}
          <Field label="Customer"><Input value={form.customer} onChange={(e) => setForm({ ...form, customer: e.target.value })} /></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Order">
              <Select value={form.orderId} onChange={(e) => setForm({ ...form, orderId: e.target.value })}>
                <option value="">Not linked</option>
                {(orders.data?.items ?? []).map((o) => <option key={o.id} value={o.id}>{o.ref} — {o.customer}</option>)}
              </Select>
            </Field>
            <Field label="Address"><Input value={form.address} onChange={(e) => setForm({ ...form, address: e.target.value })} /></Field>
          </div>
          <div className="text-xs text-muted">What is on this run? More lines can be added until it is signed.</div>
          <div className="grid grid-cols-[1fr_5rem_5rem] gap-2">
            <Field label="Item"><Input value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} /></Field>
            <Field label="Qty"><Input type="number" min={1} value={form.qty} onChange={(e) => setForm({ ...form, qty: e.target.value })} /></Field>
            <Field label="Unit"><Input value={form.unit} onChange={(e) => setForm({ ...form, unit: e.target.value })} /></Field>
          </div>
          <div className="flex justify-end gap-2 pt-1">
            <Button variant="ghost" onClick={() => setNewOpen(false)}>Cancel</Button>
            <Button onClick={createDelivery} disabled={saving}>{saving ? "Saving…" : "Record"}</Button>
          </div>
        </div>
      </Modal>

      {/* plan run */}
      <Modal open={runOpen} onClose={() => setRunOpen(false)} title="Plan a dispatch run">
        <div className="space-y-3">
          {err && <div className="text-sm text-danger">{err}</div>}
          <div className="grid grid-cols-2 gap-3">
            <Field label="Vehicle"><Input value={runForm.vehicle} onChange={(e) => setRunForm({ ...runForm, vehicle: e.target.value })} placeholder="LEB-4471" /></Field>
            <Field label="Driver"><Input value={runForm.driver} onChange={(e) => setRunForm({ ...runForm, driver: e.target.value })} /></Field>
          </div>
          <Field label="Crew"><Input value={runForm.crew} onChange={(e) => setRunForm({ ...runForm, crew: e.target.value })} /></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Scheduled"><Input type="datetime-local" value={runForm.scheduledFor} onChange={(e) => setRunForm({ ...runForm, scheduledFor: e.target.value })} /></Field>
            <Field label="City"><Input value={runForm.city} onChange={(e) => setRunForm({ ...runForm, city: e.target.value })} /></Field>
          </div>
          <Field label="Address"><Input value={runForm.address} onChange={(e) => setRunForm({ ...runForm, address: e.target.value })} /></Field>
          <div className="text-xs text-muted">
            The departure time is stamped when the run leaves and cannot be rewritten afterwards.
          </div>
          <div className="flex justify-end gap-2 pt-1">
            <Button variant="ghost" onClick={() => setRunOpen(false)}>Cancel</Button>
            <Button onClick={createRun} disabled={saving}>{saving ? "Saving…" : "Plan"}</Button>
          </div>
        </div>
      </Modal>

      {/* record install */}
      <Modal open={assetOpen} onClose={() => setAssetOpen(false)} title="Record an installation">
        <div className="space-y-3">
          {err && <div className="text-sm text-danger">{err}</div>}
          <Field label="What was installed"><Input value={assetForm.name} onChange={(e) => setAssetForm({ ...assetForm, name: e.target.value })} placeholder="Walnut worktop" /></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Room"><Input value={assetForm.room} onChange={(e) => setAssetForm({ ...assetForm, room: e.target.value })} placeholder="Kitchen" /></Field>
            <Field label="Warranty (months)">
              <Input type="number" min={0} value={assetForm.warrantyMonths} onChange={(e) => setAssetForm({ ...assetForm, warrantyMonths: e.target.value })} />
            </Field>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Location"><Input value={assetForm.location} onChange={(e) => setAssetForm({ ...assetForm, location: e.target.value })} /></Field>
            <Field label="Order">
              <Select value={assetForm.orderId} onChange={(e) => setAssetForm({ ...assetForm, orderId: e.target.value })}>
                <option value="">Not linked</option>
                {(orders.data?.items ?? []).map((o) => <option key={o.id} value={o.id}>{o.ref}</option>)}
              </Select>
            </Field>
          </div>
          <div className="text-xs text-muted">
            The warranty runs from today, not from the order date — a worktop that sat in a warehouse for six weeks has not been in use for six weeks.
          </div>
          <div className="flex justify-end gap-2 pt-1">
            <Button variant="ghost" onClick={() => setAssetOpen(false)}>Cancel</Button>
            <Button onClick={installAsset} disabled={saving}>{saving ? "Saving…" : "Record"}</Button>
          </div>
        </div>
      </Modal>

      {/* confirm arrival */}
      <Modal open={!!signFor} onClose={() => setSignFor(null)} title="Confirm the arrival">
        <div className="space-y-3">
          {err && <div className="text-sm text-danger">{err}</div>}
          <div className="text-sm text-muted">{signFor?.ref} · {signFor?.customer}</div>
          <Field label="Signed by">
            <Input value={signature.signedBy} onChange={(e) => setSignature({ ...signature, signedBy: e.target.value })}
              placeholder="Name of the person who took receipt" />
          </Field>
          <Field label="Condition">
            <Select value={signature.condition} onChange={(e) => setSignature({ ...signature, condition: e.target.value })}>
              <option value="good">Good</option>
              <option value="partial">Partial</option>
              <option value="damaged">Damaged</option>
              <option value="rejected">Rejected</option>
            </Select>
          </Field>
          {signature.condition !== "good" && (
            <Field label="What was wrong">
              <Input value={signature.damageNotes} onChange={(e) => setSignature({ ...signature, damageNotes: e.target.value })}
                placeholder="Worktop corner chipped in transit" />
            </Field>
          )}
          <div className="text-xs text-muted">
            A delivery marked delivered names who signed for it. Without that, "delivered" is a claim with no evidence.
          </div>
          <div className="flex justify-end gap-2 pt-1">
            <Button variant="ghost" onClick={() => setSignFor(null)}>Cancel</Button>
            <Button onClick={confirmDelivery} disabled={saving}>{saving ? "Saving…" : "Confirm arrival"}</Button>
          </div>
        </div>
      </Modal>
    </>
  );
}

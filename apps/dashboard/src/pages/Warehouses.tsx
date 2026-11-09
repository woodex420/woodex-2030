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
import { Plus, RefreshCw, Building, MapPin, ChevronDown, ChevronRight } from "@/icons";

/* Warehouses — where the stock actually is.
 *
 * Before this page existed the whole company had one implicit location and
 * `products.stock_qty` was the only answer to "how much". The first question a
 * warehouse manager asks is "which godown", and the system could not answer it.
 *
 * A warehouse is not a location. "How much teak in Lahore" and "which rack" are
 * both real questions at different granularities; collapsing them forces one
 * into a naming convention inside a free-text field, which is how stock gets
 * lost. Each warehouse row expands to its locations.
 *
 * No mock data: zero warehouses means the database has none, and this screen is
 * where you create the first one. */

type Warehouse = {
  id: number; code: string; name: string; city: string | null; address: string | null;
  manager: string | null; phone: string | null; isDefault: boolean; active: boolean;
  notes: string | null; locations: number;
};
type Location = {
  id: number; warehouseId: number; warehouseName: string | null; warehouseCode: string | null;
  code: string; name: string; zone: string | null; active: boolean;
};
type Level = {
  productId: string; productName: string; locationId: number; locationCode: string;
  locationName: string; warehouseId: number; warehouseCode: string; warehouseName: string; qty: number;
};

const blankWh = { code: "", name: "", city: "", address: "", manager: "", phone: "", notes: "" };
const blankLoc = { code: "", name: "", zone: "" };

export default function Warehouses() {
  const { push } = useToast();
  const [openWh, setOpenWh] = useState(false);
  const [openLoc, setOpenLoc] = useState<number | null>(null);
  const [formWh, setFormWh] = useState({ ...blankWh });
  const [formLoc, setFormLoc] = useState({ ...blankLoc });
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [expanded, setExpanded] = useState<number | null>(null);

  const wh = useApi<{ total: number; active: number; items: Warehouse[] }>("/api/warehouses", 30000);
  const loc = useApi<{ total: number; items: Location[] }>("/api/storage-locations", 30000);
  const levels = useApi<{ total: number; onHand: number; items: Level[] }>(
    expanded ? `/api/stock-levels?warehouse_id=${expanded}` : "/api/stock-levels",
    30000
  );

  const items = wh.data?.items ?? [];
  const locations = loc.data?.items ?? [];

  const createWh = async () => {
    if (saving) return;
    setErr(null);
    if (!formWh.code.trim() || !formWh.name.trim()) {
      setErr("Warehouse code and name are required.");
      return;
    }
    setSaving(true);
    try {
      await mutate<{ warehouse: Warehouse }>("/api/warehouses", {
        code: formWh.code.trim(), name: formWh.name.trim(),
        city: formWh.city || null, address: formWh.address || null,
        manager: formWh.manager || null, phone: formWh.phone || null, notes: formWh.notes || null,
      }, "POST");
      const isFirst = items.length === 0;
      push({
        tone: "success",
        title: `${formWh.name.trim()} added`,
        desc: isFirst ? "It is the default warehouse — unattributed stock lands there." : "Add storage locations to it next.",
      });
      setOpenWh(false);
      setFormWh({ ...blankWh });
      wh.reload(); loc.reload();
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    } finally {
      setSaving(false);
    }
  };

  const createLoc = async (warehouseId: number) => {
    if (saving) return;
    setErr(null);
    if (!formLoc.code.trim() || !formLoc.name.trim()) {
      setErr("Location code and name are required.");
      return;
    }
    setSaving(true);
    try {
      await mutate<{ location: Location }>("/api/storage-locations", {
        warehouseId, code: formLoc.code.trim(), name: formLoc.name.trim(), zone: formLoc.zone || null,
      }, "POST");
      push({ tone: "success", title: `Location ${formLoc.code.trim().toUpperCase()} added`, desc: "Stock can now be received into it." });
      setOpenLoc(null);
      setFormLoc({ ...blankLoc });
      wh.reload(); loc.reload(); levels.reload();
    } catch (e) {
      setErr(e instanceof Error ? e.message : String(e));
    } finally {
      setSaving(false);
    }
  };

  const setDefault = async (w: Warehouse) => {
    try {
      await mutate(`/api/warehouses/${w.id}`, { isDefault: true });
      push({ tone: "success", title: `${w.name} is now the default`, desc: "Unattributed receipts land in its first active location." });
      wh.reload();
    } catch (e) {
      push({ tone: "danger", title: "Could not set the default", desc: e instanceof Error ? e.message : String(e) });
    }
  };

  const toggle = async (w: Warehouse) => {
    try {
      await mutate(`/api/warehouses/${w.id}`, { active: !w.active });
      wh.reload();
    } catch (e) {
      push({ tone: "danger", title: "Could not update the warehouse", desc: e instanceof Error ? e.message : String(e) });
    }
  };

  if (wh.error)
    return (
      <div>
        <PageHeader title="Warehouses" />
        <ErrorState title="Warehouse API unreachable" description="The inventory backend did not answer." debug={wh.error} onRetry={wh.reload} />
      </div>
    );

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Operations", to: "/operations" }, { label: "Warehouses" }]}
        title="Warehouses"
        description="Where stock is. One default warehouse receives anything that arrives without a named location."
        actions={
          <>
            <Button variant="secondary" onClick={() => { wh.reload(); loc.reload(); levels.reload(); }}><RefreshCw size={15} /> Refresh</Button>
            <Button onClick={() => { setFormWh({ ...blankWh }); setErr(null); setOpenWh(true); }}>
              <Plus size={15} /> New warehouse
            </Button>
          </>
        }
      />

      <div className="mb-4 grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Card className="py-4">
          <p className="text-caption uppercase text-subtle">Warehouses</p>
          <p className="mt-1 text-h2 font-semibold text-ink">{wh.loading ? "—" : wh.data?.total ?? 0}</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption uppercase text-subtle">Active</p>
          <p className="mt-1 text-h2 font-semibold text-ink">{wh.loading ? "—" : wh.data?.active ?? 0}</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption uppercase text-subtle">Locations</p>
          <p className="mt-1 text-h2 font-semibold text-ink">{loc.loading ? "—" : loc.data?.total ?? 0}</p>
        </Card>
        <Card className="py-4">
          <p className="text-caption uppercase text-subtle">Units on hand</p>
          <p className="mt-1 text-h2 font-semibold text-ink">{levels.loading ? "—" : (levels.data?.onHand ?? 0).toLocaleString()}</p>
        </Card>
      </div>

      <Card className="mb-4 overflow-hidden p-0">
        <table className="w-full text-left">
          <thead className="border-b border-line bg-slate-50 text-caption uppercase text-subtle">
            <tr>
              <th className="w-8 px-3 py-2.5" />
              <th className="px-4 py-2.5 font-semibold">Code</th>
              <th className="px-4 py-2.5 font-semibold">Warehouse</th>
              <th className="px-4 py-2.5 font-semibold">City</th>
              <th className="px-4 py-2.5 font-semibold">Locations</th>
              <th className="px-4 py-2.5 font-semibold">Status</th>
              <th className="px-4 py-2.5 text-right font-semibold">Action</th>
            </tr>
          </thead>
          <tbody>
            {wh.loading && items.length === 0 && (
              <tr><td colSpan={7} className="p-4"><Skeleton className="h-10" /><Skeleton className="mt-2 h-10" /></td></tr>
            )}
            {items.map((w) => {
              const mine = locations.filter((l) => l.warehouseId === w.id);
              const isOpen = expanded === w.id;
              return (
                <>
                  <tr key={w.id} className="border-b border-slate-100 hover:bg-slate-50/60">
                    <td className="px-3 py-3">
                      <button
                        className="flex h-6 w-6 items-center justify-center rounded-control text-subtle hover:bg-slate-100"
                        onClick={() => setExpanded(isOpen ? null : w.id)}
                        aria-label={isOpen ? "Collapse" : "Expand"}
                      >
                        {isOpen ? <ChevronDown size={15} /> : <ChevronRight size={15} />}
                      </button>
                    </td>
                    <td className="px-4 py-3 font-mono text-caption text-subtle">{w.code}</td>
                    <td className="px-4 py-3">
                      <p className="flex items-center gap-1.5 text-small font-semibold text-ink">
                        {w.name}
                        {w.isDefault && <Badge tone="info" dot={false}>Default</Badge>}
                      </p>
                      {w.manager && <p className="text-caption text-muted">{w.manager}{w.phone ? ` · ${w.phone}` : ""}</p>}
                    </td>
                    <td className="px-4 py-3 text-small text-slate-700">{w.city ?? "—"}</td>
                    <td className="px-4 py-3 text-small text-slate-700">{mine.length}</td>
                    <td className="px-4 py-3">
                      <Badge tone={w.active ? "success" : "neutral"} dot={false}>{w.active ? "Active" : "Inactive"}</Badge>
                    </td>
                    <td className="px-4 py-3 text-right">
                      <div className="flex justify-end gap-2">
                        {!w.isDefault && w.active && (
                          <Button variant="secondary" size="sm" onClick={() => setDefault(w)}>Make default</Button>
                        )}
                        <Button variant="secondary" size="sm" onClick={() => toggle(w)}>{w.active ? "Deactivate" : "Reactivate"}</Button>
                      </div>
                    </td>
                  </tr>
                  {isOpen && (
                    <tr key={`${w.id}-detail`} className="border-b border-slate-100 bg-slate-50/40">
                      <td colSpan={7} className="px-4 py-4">
                        <div className="flex items-center justify-between">
                          <p className="text-caption font-semibold uppercase text-subtle">Storage locations</p>
                          <Button variant="secondary" size="sm" onClick={() => { setFormLoc({ ...blankLoc }); setErr(null); setOpenLoc(w.id); }}>
                            <Plus size={14} /> Add location
                          </Button>
                        </div>
                        {mine.length === 0 ? (
                          <p className="mt-3 text-small text-muted">
                            No locations in this warehouse. Add one before receiving stock here — a movement has to land somewhere.
                          </p>
                        ) : (
                          <div className="mt-3 grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-3">
                            {mine.map((l) => {
                              const here = (levels.data?.items ?? []).filter((x) => x.locationId === l.id);
                              const total = here.reduce((a, x) => a + x.qty, 0);
                              return (
                                <div key={l.id} className="rounded-control border border-line bg-white px-3 py-2">
                                  <p className="flex items-center gap-1.5 text-small font-semibold text-ink">
                                    <MapPin size={13} className="text-subtle" /> {l.code}
                                  </p>
                                  <p className="text-caption text-muted">{l.name}{l.zone ? ` · ${l.zone}` : ""}</p>
                                  <p className="mt-1 text-caption text-subtle">
                                    {total} unit{total === 1 ? "" : "s"} · {here.length} product{here.length === 1 ? "" : "s"}
                                  </p>
                                </div>
                              );
                            })}
                          </div>
                        )}
                      </td>
                    </tr>
                  )}
                </>
              );
            })}
          </tbody>
        </table>
        {!wh.loading && items.length === 0 && (
          <EmptyState
            icon={<Building size={20} />}
            title="No warehouses yet"
            description="Create the first one. It becomes the default, which is where stock lands when a movement does not name a location."
            action={<Button onClick={() => { setFormWh({ ...blankWh }); setOpenWh(true); }}><Plus size={15} /> New warehouse</Button>}
          />
        )}
      </Card>

      {/* Stock by location, filtered to whichever warehouse is expanded. */}
      <Card className="overflow-hidden p-0">
        <div className="flex items-center justify-between border-b border-line px-4 py-3">
          <div>
            <p className="text-small font-semibold text-ink">
              Stock by location{expanded ? ` · ${items.find((w) => w.id === expanded)?.name}` : ""}
            </p>
            <p className="text-caption text-subtle">
              Only movements recorded since locations were tracked appear here. Older stock is counted at product level.
            </p>
          </div>
        </div>
        <table className="w-full text-left">
          <thead className="border-b border-line bg-slate-50 text-caption uppercase text-subtle">
            <tr>
              <th className="px-4 py-2.5 font-semibold">Product</th>
              <th className="px-4 py-2.5 font-semibold">Warehouse</th>
              <th className="px-4 py-2.5 font-semibold">Location</th>
              <th className="px-4 py-2.5 text-right font-semibold">Qty</th>
            </tr>
          </thead>
          <tbody>
            {(levels.data?.items ?? []).slice(0, 100).map((l) => (
              <tr key={`${l.productId}-${l.locationId}`} className="border-b border-slate-100 last:border-0 hover:bg-slate-50/60">
                <td className="px-4 py-3 text-small text-ink">{l.productName}</td>
                <td className="px-4 py-3 text-small text-slate-700">{l.warehouseName} <span className="text-caption text-subtle">({l.warehouseCode})</span></td>
                <td className="px-4 py-3 font-mono text-caption text-subtle">{l.locationCode}</td>
                <td className="px-4 py-3 text-right text-small font-semibold text-ink">{l.qty}</td>
              </tr>
            ))}
          </tbody>
        </table>
        {!levels.loading && (levels.data?.items ?? []).length === 0 && (
          <EmptyState
            icon={<MapPin size={20} />}
            title="No located stock yet"
            description="Stock appears here once it moves into a location — receive a purchase order, or set a stock level on a product."
          />
        )}
      </Card>

      <Modal open={openWh} onClose={() => setOpenWh(false)} title="New warehouse">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="Code" required hint="Short, unique — e.g. LHR">
            <Input value={formWh.code} onChange={(e) => setFormWh({ ...formWh, code: e.target.value })} placeholder="LHR" />
          </Field>
          <Field label="Name" required>
            <Input value={formWh.name} onChange={(e) => setFormWh({ ...formWh, name: e.target.value })} placeholder="Lahore Main" />
          </Field>
          <Field label="City">
            <Input value={formWh.city} onChange={(e) => setFormWh({ ...formWh, city: e.target.value })} />
          </Field>
          <Field label="Manager">
            <Input value={formWh.manager} onChange={(e) => setFormWh({ ...formWh, manager: e.target.value })} />
          </Field>
          <Field label="Phone">
            <Input value={formWh.phone} onChange={(e) => setFormWh({ ...formWh, phone: e.target.value })} />
          </Field>
          <Field label="Address">
            <Input value={formWh.address} onChange={(e) => setFormWh({ ...formWh, address: e.target.value })} />
          </Field>
          <Field label="Notes" className="sm:col-span-2">
            <Textarea value={formWh.notes} onChange={(e) => setFormWh({ ...formWh, notes: e.target.value })} />
          </Field>
        </div>
        {err && <p role="alert" className="mt-3 rounded-control border border-danger/30 bg-danger-soft px-3 py-2 text-caption font-semibold text-danger-strong">{err}</p>}
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setOpenWh(false)} disabled={saving}>Cancel</Button>
          <Button onClick={createWh} disabled={saving}>{saving ? "Saving…" : "Create warehouse"}</Button>
        </div>
      </Modal>

      <Modal open={openLoc !== null} onClose={() => setOpenLoc(null)} title="New storage location">
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="Code" required hint="Unique within this warehouse">
            <Input value={formLoc.code} onChange={(e) => setFormLoc({ ...formLoc, code: e.target.value })} placeholder="A1" />
          </Field>
          <Field label="Name" required>
            <Input value={formLoc.name} onChange={(e) => setFormLoc({ ...formLoc, name: e.target.value })} placeholder="Aisle A Rack 1" />
          </Field>
          <Field label="Zone / aisle" className="sm:col-span-2">
            <Input value={formLoc.zone} onChange={(e) => setFormLoc({ ...formLoc, zone: e.target.value })} placeholder="Ground floor" />
          </Field>
          <Field label="Warehouse" className="sm:col-span-2">
            <Select value={String(openLoc ?? "")} disabled>
              <option value={String(openLoc ?? "")}>{items.find((w) => w.id === openLoc)?.name ?? ""}</option>
            </Select>
          </Field>
        </div>
        {err && <p role="alert" className="mt-3 rounded-control border border-danger/30 bg-danger-soft px-3 py-2 text-caption font-semibold text-danger-strong">{err}</p>}
        <div className="mt-4 flex justify-end gap-2">
          <Button variant="secondary" onClick={() => setOpenLoc(null)} disabled={saving}>Cancel</Button>
          <Button onClick={() => openLoc && createLoc(openLoc)} disabled={saving}>{saving ? "Saving…" : "Create location"}</Button>
        </div>
      </Modal>
    </div>
  );
}

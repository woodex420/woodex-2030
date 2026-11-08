import { useMemo, useState } from "react";
import { cn } from "@/lib/cn";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { StatusBadge } from "@/components/ui/Badge";
import { Progress } from "@/components/ui/Progress";
import { Tabs } from "@/components/ui/Tabs";
import { DataTable } from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import { Modal } from "@/components/ui/Modal";
import { Field, Input, Select, Textarea } from "@/components/ui/Field";
import { SkeletonRows, EmptyState, ErrorState } from "@/components/ui/States";
import { useToast } from "@/components/ui/Toast";
import { useApi, useRealtime, timeAgo } from "@/lib/api";
import { Check, FileText, MapPin, Plus, Trash2 } from "@/icons";

/* ---------- API types (mirror apps/api/server.mjs rowProject & friends) ---------- */
type Milestone = { id: number; projectId: number; title: string; dueAt: string | null; done: boolean; sort: number; note: string | null };
type BoqLine = { id: number; projectId: number; sku: string | null; description: string; qty: number; unit: string; unitCost: number; lineTotal: number };
type ProjectFile = { id: number; projectId: number; name: string; kind: string; size: number; url: string | null; uploadedBy: string; createdAt: string };
type Project = {
  id: number; ref: string; name: string; clientId: number; orderId: number | null;
  owner: string; stage: string; address: string | null; startAt: string | null; dueAt: string | null;
  plannedCost: number; actualCost: number; progress: number; notes: string | null;
  createdAt: string; updatedAt: string;
  boqTotal: number; boqCount: number; milestones: number; milestonesDone: number;
};
type ProjectDetail = Project & {
  boq: BoqLine[]; milestonesList: Milestone[]; files: ProjectFile[];
  margin: number | null; client: { id: number; name: string; company: string | null; email: string | null; phone: string | null } | null;
};
type ProjectList = { total: number; planned: number; open: number; items: Project[] };
type Client = { id: number; name: string; company?: string | null };

const STAGES = ["Planning", "Procurement", "Production", "Delivery", "Installation", "Handover"];
const FILE_KINDS = ["drawing", "boq", "contract", "photo", "other"];

const money = (n: number) => "Rs " + Math.round(n || 0).toLocaleString("en-PK");
const day = (s: string | null) => (s ? String(s).slice(0, 10) : "—");

export default function Projects() {
  const [selected, setSelected] = useState<number | null>(null);
  const [tab, setTab] = useState("Overview");
  const [createOpen, setCreateOpen] = useState(false);
  const [boqOpen, setBoqOpen] = useState(false);
  const [msOpen, setMsOpen] = useState(false);
  const [fileOpen, setFileOpen] = useState(false);
  const { push } = useToast();

  const list = useApi<ProjectList>("/api/projects");
  const clients = useApi<{ items: Client[] }>("/api/clients");
  const activeId = selected ?? list.data?.items[0]?.id ?? null;
  const detail = useApi<ProjectDetail>(activeId ? `/api/projects/${activeId}` : null);

  /* Live: any colleague touching a project refreshes this screen. */
  useRealtime(/^(projects|orders)$/, () => {
    void list.reload();
    void detail.reload();
  });

  const items = list.data?.items ?? [];
  const p = detail.data ?? null;
  const milestones = detail.data?.milestonesList ?? [];
  const boqRows = p?.boq ?? [];
  const files = p?.files ?? [];

  const boqCols: Column<BoqLine>[] = useMemo(
    () => [
      { key: "description", header: "Scope item", cell: (r) => <span className="text-small font-medium text-ink">{r.description}</span> },
      { key: "sku", header: "SKU", cell: (r) => <span className="font-mono text-caption text-subtle">{r.sku ?? "—"}</span> },
      { key: "qty", header: "Qty", align: "right", cell: (r) => <span className="text-small">{r.qty} {r.unit}</span> },
      { key: "unitCost", header: "Unit rate", align: "right", cell: (r) => <span className="text-small text-slate-700">{money(r.unitCost)}</span> },
      { key: "lineTotal", header: "Total", align: "right", cell: (r) => <span className="text-small font-semibold text-ink">{money(r.lineTotal)}</span> },
      {
        key: "del", header: "", align: "right",
        cell: (r) => (
          <Button variant="ghost" size="sm" aria-label={`Remove ${r.description}`}
            onClick={async () => {
              const res = await fetch(`/api/projects/${activeId}/boq/${r.id}`, { method: "DELETE" });
              if (res.ok) { push({ tone: "success", title: "BOQ line removed" }); void detail.reload(); void list.reload(); }
              else push({ tone: "danger", title: "Could not remove line" });
            }}>
            <Trash2 size={14} />
          </Button>
        ),
      },
    ],
    [activeId, detail, list, push]
  );

  const post = async (url: string, body: unknown, okTitle: string) => {
    const res = await fetch(url, { method: "POST", headers: { "content-type": "application/json" }, body: JSON.stringify(body) });
    const j = await res.json().catch(() => ({}));
    if (res.ok) { push({ tone: "success", title: okTitle }); void detail.reload(); void list.reload(); return true; }
    push({ tone: "danger", title: j.error ?? "Request failed" });
    return false;
  };

  /* ---------- loading / error / empty ---------- */
  if (list.loading && !list.data) {
    return (
      <div>
        <PageHeader crumbs={[{ label: "Delivery", to: "/projects" }, { label: "Projects" }]} title="Interior Projects" description="Design-to-installation workspaces with BOQ, milestones and site operations." />
        <Card><SkeletonRows rows={5} /></Card>
      </div>
    );
  }
  if (list.error) {
    return (
      <div>
        <PageHeader crumbs={[{ label: "Delivery", to: "/projects" }, { label: "Projects" }]} title="Interior Projects" description="Design-to-installation workspaces with BOQ, milestones and site operations." />
        <Card><ErrorState description={list.error} onRetry={() => void list.reload()} /></Card>
      </div>
    );
  }

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Delivery", to: "/projects" }, { label: "Projects" }]}
        title="Interior Projects"
        description={
          list.data
            ? `${list.data.total} project${list.data.total === 1 ? "" : "s"} · ${list.data.open} open · ${money(list.data.planned)} planned`
            : "Design-to-installation workspaces with BOQ, milestones and site operations."
        }
        actions={
          <Button onClick={() => setCreateOpen(true)}>
            <Plus size={15} /> New Project
          </Button>
        }
      />

      {items.length === 0 ? (
        <Card>
          <EmptyState
            title="No projects yet"
            description="Open the first fit-out to start tracking BOQ, milestones and site work."
            action={<Button onClick={() => setCreateOpen(true)}><Plus size={15} /> New Project</Button>}
          />
        </Card>
      ) : (
        <div className="grid grid-cols-1 gap-4 xl:grid-cols-[360px_minmax(0,1fr)]">
          {/* Project list */}
          <div className="space-y-3">
            {items.map((item) => {
              const active = item.id === activeId;
              return (
                <button
                  key={item.id}
                  onClick={() => setSelected(item.id)}
                  className={cn(
                    "block w-full rounded-card border bg-surface p-4 text-left shadow-card transition-all duration-150 hover:border-slate-300",
                    active ? "border-primary-500 ring-2 ring-primary-500/20" : "border-line"
                  )}
                  aria-pressed={active}
                >
                  <div className="flex items-start justify-between gap-2">
                    <span className="text-caption font-medium text-subtle">{item.ref}</span>
                    <StatusBadge status={item.stage} />
                  </div>
                  <h3 className={cn("mt-1 line-clamp-2 text-bodylg font-semibold", active ? "text-primary-700" : "text-ink")}>{item.name}</h3>
                  <p className="mt-1 flex items-center gap-1 text-caption text-muted">
                    <MapPin size={12} className="text-subtle" /> {item.address ?? "No address"}
                  </p>
                  <div className="mt-3 flex items-center gap-2">
                    <Progress value={item.progress} tone={item.progress === 100 ? "primary" : "primary"} size="lg" />
                    <span className="shrink-0 text-caption font-semibold text-ink">{item.progress}%</span>
                  </div>
                  <p className="mt-2 flex items-center justify-between text-caption text-muted">
                    <span>Due {day(item.dueAt)}</span>
                    <span>{money(item.boqTotal)}</span>
                  </p>
                </button>
              );
            })}
          </div>

          {/* Workspace */}
          <Card padded={false} className="min-w-0">
            {!p ? (
              <div className="p-6">{detail.loading ? <SkeletonRows rows={6} /> : <EmptyState title="Select a project"
            description="Choose a project on the left to see its BOQ, milestones and files." />}</div>
            ) : (
              <>
                <div className="flex flex-wrap items-center justify-between gap-2 px-5 pt-5 lg:px-6 lg:pt-6">
                  <div>
                    <h2 className="text-h2">{p.name}</h2>
                    <p className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-caption text-muted">
                      <span className="inline-flex items-center gap-1"><MapPin size={12} /> {p.address ?? "No address"}</span>
                      <span>Client: {p.client?.name ?? "—"}</span>
                      <span>Owner: {p.owner}</span>
                    </p>
                  </div>
                  <div className="flex items-center gap-2">
                    <Select
                      aria-label="Project stage"
                      value={p.stage}
                      onChange={async (e) => {
                        const res = await fetch(`/api/projects/${p.id}`, { method: "PATCH", headers: { "content-type": "application/json" }, body: JSON.stringify({ stage: e.target.value }) });
                        if (res.ok) { push({ tone: "success", title: "Stage → " + e.target.value }); void detail.reload(); void list.reload(); }
                        else push({ tone: "danger", title: "Could not change stage" });
                      }}
                    >
                      {STAGES.map((s) => <option key={s} value={s}>{s}</option>)}
                    </Select>
                  </div>
                </div>

                <Tabs className="mt-4 px-3 lg:px-4" items={["Overview", "BOQ", "Milestones", "Files"].map((t) => ({ value: t, label: t }))} value={tab} onChange={setTab} />

                <div className="p-5 lg:p-6">
                  {tab === "Overview" && (
                    <div className="grid gap-3 sm:grid-cols-3">
                      <div className="rounded-card border border-line bg-slate-50/70 p-4">
                        <p className="text-caption font-medium text-subtle uppercase">Planned</p>
                        <p className="mt-1 text-h3">{money(p.plannedCost)}</p>
                        <p className="mt-1 text-caption text-subtle">budget on record</p>
                      </div>
                      <div className="rounded-card border border-line bg-slate-50/70 p-4">
                        <p className="text-caption font-medium text-subtle uppercase">BOQ total</p>
                        <p className="mt-1 text-h3 text-info-strong">{money(p.boqTotal)}</p>
                        <p className="mt-1 text-caption text-subtle">{p.boq.length} line{p.boq.length === 1 ? "" : "s"} priced</p>
                      </div>
                      <div className="rounded-card border border-line bg-primary-50 p-4">
                        <p className="text-caption font-medium text-primary-700/70 uppercase">Progress</p>
                        <p className="mt-1 text-h3 text-primary-700">{p.progress}%</p>
                        <Progress value={p.progress} className="mt-2" />
                      </div>
                      <div className="rounded-card border border-line p-4 sm:col-span-2">
                        <p className="mb-2 text-caption font-semibold text-subtle uppercase">Open milestones</p>
                        {milestones.filter((m) => !m.done).length === 0 ? (
                          <p className="text-small text-muted">None open.</p>
                        ) : (
                          milestones.filter((m) => !m.done).slice(0, 4).map((m) => (
                            <p key={m.id} className="flex items-center justify-between border-b border-slate-100 py-2 text-small last:border-0">
                              <span className="font-medium text-ink">{m.title}</span>
                              <span className="text-caption text-muted">{day(m.dueAt)}</span>
                            </p>
                          ))
                        )}
                      </div>
                      <div className="rounded-card border border-line p-4">
                        <p className="mb-2 text-caption font-semibold text-subtle uppercase">Margin</p>
                        <p className="text-h3">{p.margin === null ? "—" : p.margin + "%"}</p>
                        <p className="mt-1 text-caption text-subtle">BOQ vs actual cost</p>
                      </div>
                      {p.notes && (
                        <div className="rounded-card border border-line p-4 sm:col-span-3">
                          <p className="mb-1 text-caption font-semibold text-subtle uppercase">Notes</p>
                          <p className="text-small text-slate-700">{p.notes}</p>
                        </div>
                      )}
                    </div>
                  )}

                  {tab === "BOQ" && (
                    <>
                      <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                        <p className="text-small text-muted">Server-priced scope of work · {boqRows.length} line{boqRows.length === 1 ? "" : "s"}</p>
                        <Button size="sm" onClick={() => setBoqOpen(true)}><Plus size={13} /> Add line</Button>
                      </div>
                      {boqRows.length === 0 ? (
                        <EmptyState title="No BOQ lines"
            description="Add the first priced scope item to start costing this project." action={<Button onClick={() => setBoqOpen(true)}><Plus size={13} /> Add line</Button>} />
                      ) : (
                        <div className="overflow-hidden rounded-card border border-line">
                          <DataTable rows={boqRows} columns={boqCols} />
                          <div className="flex items-center justify-between border-t border-line bg-slate-50 px-4 py-3">
                            <span className="text-small font-semibold text-ink">BOQ total</span>
                            <span className="text-h3 text-ink">{money(p.boqTotal)}</span>
                          </div>
                        </div>
                      )}
                    </>
                  )}

                  {tab === "Milestones" && (
                    <>
                      <div className="mb-3 flex items-center justify-between">
                        <p className="text-small text-muted">{milestones.filter((m) => m.done).length}/{milestones.length} complete</p>
                        <Button size="sm" onClick={() => setMsOpen(true)}><Plus size={13} /> Add milestone</Button>
                      </div>
                      {milestones.length === 0 ? (
                        <EmptyState title="No milestones"
            description="Break the project into checkpoints so progress tracks itself." action={<Button onClick={() => setMsOpen(true)}><Plus size={13} /> Add milestone</Button>} />
                      ) : (
                        <ol className="max-w-xl">
                          {milestones.map((m, i) => (
                            <li key={m.id} className="relative flex gap-3 pb-5 last:pb-0">
                              <button
                                onClick={async () => {
                                  const res = await fetch(`/api/projects/${p.id}/milestones/${m.id}`, { method: "PATCH", headers: { "content-type": "application/json" }, body: JSON.stringify({ done: !m.done }) });
                                  if (res.ok) { void detail.reload(); void list.reload(); }
                                  else push({ tone: "danger", title: "Could not update milestone" });
                                }}
                                aria-label={m.done ? `Mark ${m.title} not done` : `Mark ${m.title} done`}
                                className={cn(
                                  "z-10 grid h-6 w-6 shrink-0 place-items-center rounded-full border transition-colors",
                                  m.done ? "border-primary-500 bg-primary-500 text-white" : "border-line-strong bg-white text-[10px] font-bold text-subtle hover:border-primary-400"
                                )}
                              >
                                {m.done ? <Check size={12} /> : i + 1}
                              </button>
                              {i < milestones.length - 1 && (
                                <span className={cn("absolute top-6 bottom-0 left-3 w-px", m.done ? "bg-primary-500/60" : "bg-slate-200")} aria-hidden />
                              )}
                              <span className="pt-0.5">
                                <span className={cn("block text-small font-semibold", m.done ? "text-ink" : "text-muted")}>{m.title}</span>
                                <span className="block text-caption text-subtle">{day(m.dueAt)} · {m.done ? "completed" : "scheduled"}</span>
                              </span>
                            </li>
                          ))}
                        </ol>
                      )}
                    </>
                  )}

                  {tab === "Files" && (
                    <>
                      <div className="mb-3 flex items-center justify-between">
                        <p className="text-small text-muted">{files.length} file{files.length === 1 ? "" : "s"} on record</p>
                        <Button size="sm" onClick={() => setFileOpen(true)}><Plus size={13} /> Attach file</Button>
                      </div>
                      {files.length === 0 ? (
                        <EmptyState title="No files"
            description="Attach drawings, contracts or site photos. Only metadata is stored; binaries live in /img." action={<Button onClick={() => setFileOpen(true)}><Plus size={13} /> Attach file</Button>} />
                      ) : (
                        <ul className="divide-y divide-slate-100 rounded-card border border-line">
                          {files.map((f) => (
                            <li key={f.id} className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-slate-50">
                              <span className="grid h-9 w-9 place-items-center rounded-control bg-slate-100 text-slate-600"><FileText size={16} /></span>
                              <span className="min-w-0 flex-1">
                                <span className="block truncate text-small font-medium text-ink">{f.name}</span>
                                <span className="block text-caption text-subtle">{f.kind} · added {timeAgo(f.createdAt)} by {f.uploadedBy}</span>
                              </span>
                              {f.url && (
                                <a className="text-caption font-semibold text-primary-600 hover:underline" href={f.url} target="_blank" rel="noreferrer">Open</a>
                              )}
                              <Button variant="ghost" size="sm" aria-label={`Remove ${f.name}`}
                                onClick={async () => {
                                  const res = await fetch(`/api/projects/${p.id}/files/${f.id}`, { method: "DELETE" });
                                  if (res.ok) { push({ tone: "success", title: "File removed" }); void detail.reload(); }
                                  else push({ tone: "danger", title: "Could not remove file" });
                                }}>
                                <Trash2 size={14} />
                              </Button>
                            </li>
                          ))}
                        </ul>
                      )}
                    </>
                  )}
                </div>
              </>
            )}
          </Card>
        </div>
      )}

      {/* ---------- New project ---------- */}
      <CreateProjectModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        clients={clients.data?.items ?? []}
        onCreate={async (body) => {
          const ok = await post("/api/projects", body, "Project opened");
          if (ok) setCreateOpen(false);
        }}
      />

      {/* ---------- Add BOQ line ---------- */}
      <AddBoqModal open={boqOpen} onClose={() => setBoqOpen(false)} onSubmit={async (body) => {
        if (!activeId) return;
        const ok = await post(`/api/projects/${activeId}/boq`, body, "BOQ line added");
        if (ok) setBoqOpen(false);
      }} />

      {/* ---------- Add milestone ---------- */}
      <AddMilestoneModal open={msOpen} onClose={() => setMsOpen(false)} onSubmit={async (body) => {
        if (!activeId) return;
        const ok = await post(`/api/projects/${activeId}/milestones`, body, "Milestone added");
        if (ok) setMsOpen(false);
      }} />

      {/* ---------- Attach file ---------- */}
      <AttachFileModal open={fileOpen} onClose={() => setFileOpen(false)} onSubmit={async (body) => {
        if (!activeId) return;
        const ok = await post(`/api/projects/${activeId}/files`, body, "File attached");
        if (ok) setFileOpen(false);
      }} />
    </div>
  );
}

/* ============================ modals ============================ */

function CreateProjectModal({ open, onClose, clients, onCreate }: {
  open: boolean; onClose: () => void; clients: Client[];
  onCreate: (body: Record<string, unknown>) => Promise<void>;
}) {
  const [f, setF] = useState({ name: "", clientId: "", address: "", plannedCost: "", dueAt: "", stage: "Planning" });
  const [busy, setBusy] = useState(false);
  return (
    <Modal
      open={open}
      onClose={onClose}
      title="New project"
      description="Opens a fit-out workspace with its own BOQ, milestones and files."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button
            disabled={busy || !f.name.trim() || !f.clientId}
            onClick={async () => {
              setBusy(true);
              await onCreate({
                name: f.name.trim(),
                clientId: Number(f.clientId),
                address: f.address.trim() || undefined,
                plannedCost: Number(f.plannedCost) || 0,
                dueAt: f.dueAt || undefined,
                stage: f.stage,
              });
              setBusy(false);
            }}
          >
            <Plus size={15} /> Open project
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <Field label="Project name" required>
          <Input value={f.name} onChange={(e) => setF((x) => ({ ...x, name: e.target.value }))} placeholder="e.g. HQ fit-out — Phase 2" />
        </Field>
        <Field label="Client" required>
          <Select value={f.clientId} onChange={(e) => setF((x) => ({ ...x, clientId: e.target.value }))}>
            <option value="">Select a client…</option>
            {clients.map((c) => <option key={c.id} value={c.id}>{c.name}{c.company ? " · " + c.company : ""}</option>)}
          </Select>
        </Field>
        <Field label="Site address">
          <Input value={f.address} onChange={(e) => setF((x) => ({ ...x, address: e.target.value }))} placeholder="e.g. Gulberg III, Lahore" />
        </Field>
        <div className="grid grid-cols-2 gap-3">
          <Field label="Planned cost (PKR)">
            <Input type="number" min={0} value={f.plannedCost} onChange={(e) => setF((x) => ({ ...x, plannedCost: e.target.value }))} placeholder="2500000" />
          </Field>
          <Field label="Due date">
            <Input type="date" value={f.dueAt} onChange={(e) => setF((x) => ({ ...x, dueAt: e.target.value }))} />
          </Field>
        </div>
        <Field label="Stage">
          <Select value={f.stage} onChange={(e) => setF((x) => ({ ...x, stage: e.target.value }))}>
            {STAGES.map((s) => <option key={s} value={s}>{s}</option>)}
          </Select>
        </Field>
      </div>
    </Modal>
  );
}

function AddBoqModal({ open, onClose, onSubmit }: { open: boolean; onClose: () => void; onSubmit: (b: Record<string, unknown>) => Promise<void> }) {
  const [f, setF] = useState({ description: "", sku: "", qty: "1", unit: "pcs", unitCost: "" });
  const [busy, setBusy] = useState(false);
  return (
    <Modal
      open={open} onClose={onClose} title="Add BOQ line"
      description="Line totals are computed on the server from qty × unit cost."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button disabled={busy || !f.description.trim() || !f.unitCost}
            onClick={async () => {
              setBusy(true);
              await onSubmit({ description: f.description.trim(), sku: f.sku.trim() || undefined, qty: Number(f.qty) || 1, unit: f.unit, unitCost: Number(f.unitCost) });
              setBusy(false);
            }}>
            <Plus size={15} /> Add line
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <Field label="Scope item" required>
          <Input value={f.description} onChange={(e) => setF((x) => ({ ...x, description: e.target.value }))} placeholder="e.g. Ergonomic task chair" />
        </Field>
        <Field label="Catalog SKU (optional)">
          <Input value={f.sku} onChange={(e) => setF((x) => ({ ...x, sku: e.target.value }))} placeholder="e.g. ergo-chair" />
        </Field>
        <div className="grid grid-cols-3 gap-3">
          <Field label="Qty" required>
            <Input type="number" min={0} step="any" value={f.qty} onChange={(e) => setF((x) => ({ ...x, qty: e.target.value }))} />
          </Field>
          <Field label="Unit">
            <Input value={f.unit} onChange={(e) => setF((x) => ({ ...x, unit: e.target.value }))} />
          </Field>
          <Field label="Unit cost" required>
            <Input type="number" min={0} value={f.unitCost} onChange={(e) => setF((x) => ({ ...x, unitCost: e.target.value }))} placeholder="18500" />
          </Field>
        </div>
        {f.qty && f.unitCost && (
          <p className="rounded-control bg-slate-50 px-3 py-2 text-small text-slate-700">
            Line total: <strong>{money((Number(f.qty) || 0) * (Number(f.unitCost) || 0))}</strong>
          </p>
        )}
      </div>
    </Modal>
  );
}

function AddMilestoneModal({ open, onClose, onSubmit }: { open: boolean; onClose: () => void; onSubmit: (b: Record<string, unknown>) => Promise<void> }) {
  const [f, setF] = useState({ title: "", dueAt: "", note: "" });
  const [busy, setBusy] = useState(false);
  return (
    <Modal
      open={open} onClose={onClose} title="Add milestone"
      description="Completing every milestone sets the project progress to 100%."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button disabled={busy || !f.title.trim()}
            onClick={async () => {
              setBusy(true);
              await onSubmit({ title: f.title.trim(), dueAt: f.dueAt || undefined, note: f.note.trim() || undefined });
              setBusy(false);
            }}>
            <Plus size={15} /> Add milestone
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <Field label="Milestone" required>
          <Input value={f.title} onChange={(e) => setF((x) => ({ ...x, title: e.target.value }))} placeholder="e.g. Site survey" />
        </Field>
        <Field label="Due date">
          <Input type="date" value={f.dueAt} onChange={(e) => setF((x) => ({ ...x, dueAt: e.target.value }))} />
        </Field>
        <Field label="Note">
          <Textarea rows={2} value={f.note} onChange={(e) => setF((x) => ({ ...x, note: e.target.value }))} />
        </Field>
      </div>
    </Modal>
  );
}

function AttachFileModal({ open, onClose, onSubmit }: { open: boolean; onClose: () => void; onSubmit: (b: Record<string, unknown>) => Promise<void> }) {
  const [f, setF] = useState({ name: "", kind: "drawing", url: "" });
  const [busy, setBusy] = useState(false);
  return (
    <Modal
      open={open} onClose={onClose} title="Attach file"
      description="Records the file on the project. Binaries stay in /img or an object store — paste a URL."
      footer={
        <>
          <Button variant="secondary" onClick={onClose}>Cancel</Button>
          <Button disabled={busy || !f.name.trim()}
            onClick={async () => {
              setBusy(true);
              await onSubmit({ name: f.name.trim(), kind: f.kind, url: f.url.trim() || undefined });
              setBusy(false);
            }}>
            <Plus size={15} /> Attach
          </Button>
        </>
      }
    >
      <div className="space-y-3">
        <Field label="File name" required>
          <Input value={f.name} onChange={(e) => setF((x) => ({ ...x, name: e.target.value }))} placeholder="e.g. mep-asbuilt-schematic.pdf" />
        </Field>
        <Field label="Kind">
          <Select value={f.kind} onChange={(e) => setF((x) => ({ ...x, kind: e.target.value }))}>
            {FILE_KINDS.map((k) => <option key={k} value={k}>{k}</option>)}
          </Select>
        </Field>
        <Field label="URL (optional)" hint="Must be an /img/ path or an https URL.">
          <Input value={f.url} onChange={(e) => setF((x) => ({ ...x, url: e.target.value }))} placeholder="/img/hero-office.jpg" />
        </Field>
      </div>
    </Modal>
  );
}

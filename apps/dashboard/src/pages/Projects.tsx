import { useState } from "react";
import { cn } from "@/lib/cn";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { StatusBadge, Badge } from "@/components/ui/Badge";
import { Progress } from "@/components/ui/Progress";
import { AvatarGroup } from "@/components/ui/Avatar";
import { Tabs } from "@/components/ui/Tabs";
import { DataTable } from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import { Modal } from "@/components/ui/Modal";
import { useToast } from "@/components/ui/Toast";
import { boq, milestones, projectFiles, projects } from "@/data/mock";
import { Check, Download, Eye, FileText, MapPin, Plus, TreePine } from "@/icons";

const tabs = [
  "Overview",
  "BOQ",
  "Milestones",
  "Files",
  "Design Proposal",
  "Site Visit",
  "Production",
  "Installation",
];

const fmt = (n: number) => "Rs " + n.toLocaleString();

type BoqRow = (typeof boq)[number] & { id: string };

export default function Projects() {
  const [selected, setSelected] = useState(projects[0].id);
  const [tab, setTab] = useState("Overview");
  const [signoffOpen, setSignoffOpen] = useState(false);
  const { push } = useToast();
  const project = projects.find((p) => p.id === selected)!;

  const boqRows: BoqRow[] = boq.map((b, i) => ({ ...b, id: "BOQ-" + (i + 1) }));
  const boqTotal = 2822500;

  const boqCols: Column<BoqRow>[] = [
    { key: "item", header: "Scope item", cell: (r) => <span className="text-small font-medium text-ink">{r.item}</span> },
    { key: "material", header: "Material", cell: (r) => <span className="text-caption text-muted">{r.material}</span> },
    { key: "qty", header: "Qty", align: "right", cell: (r) => <span className="text-small">{r.qty}</span> },
    { key: "unit", header: "Unit rate", align: "right", cell: (r) => <span className="text-small text-slate-700">Rs {r.unit}</span> },
    { key: "total", header: "Total", align: "right", cell: (r) => <span className="text-small font-semibold text-ink">Rs {r.total}</span> },
    { key: "status", header: "Production", align: "right", cell: (r) => <StatusBadge status={r.status} /> },
  ];

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Delivery", to: "/projects" }, { label: "Projects" }]}
        title="Interior Projects"
        description="Design-to-installation workspaces with BOQ, milestones and site operations."
        actions={
          <Button onClick={() => push({ tone: "primary", title: "New project wizard (Phase 4)" })}>
            <Plus size={15} /> New Project
          </Button>
        }
      />

      <div className="grid grid-cols-1 gap-4 xl:grid-cols-[360px_minmax(0,1fr)]">
        {/* Project list */}
        <div className="space-y-3">
          {projects.map((p) => {
            const active = p.id === selected;
            return (
              <button
                key={p.id}
                onClick={() => setSelected(p.id)}
                className={cn(
                  "block w-full rounded-card border bg-surface p-4 text-left shadow-card transition-all duration-150 hover:border-slate-300",
                  active ? "border-primary-500 ring-2 ring-primary-500/20" : "border-line"
                )}
                aria-pressed={active}
              >
                <div className="flex items-start justify-between gap-2">
                  <span className="text-caption font-medium text-subtle">{p.id}</span>
                  <StatusBadge status={p.status} />
                </div>
                <h3 className={cn("mt-1 line-clamp-2 text-bodylg font-semibold", active ? "text-primary-700" : "text-ink")}>{p.name}</h3>
                <p className="mt-1 flex items-center gap-1 text-caption text-muted">
                  <MapPin size={12} className="text-subtle" /> {p.location} · {p.client}
                </p>
                <div className="mt-3 flex items-center gap-2">
                  <Progress value={p.progress} tone={p.status === "Delayed" ? "danger" : "primary"} size="lg" />
                  <span className="shrink-0 text-caption font-semibold text-ink">{p.progress}%</span>
                </div>
                <p className="mt-2 flex items-center justify-between text-caption text-muted">
                  <span>Due {p.due}</span>
                  <span>Lead: {p.lead}</span>
                </p>
              </button>
            );
          })}
        </div>

        {/* Workspace */}
        <Card padded={false} className="min-w-0">
          <div className="flex flex-wrap items-center justify-between gap-2 px-5 pt-5 lg:px-6 lg:pt-6">
            <div>
              <h2 className="text-h2">{project.name}</h2>
              <p className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-caption text-muted">
                <span className="inline-flex items-center gap-1"><MapPin size={12} /> {project.location}</span>
                <span>Budget {fmt(project.budget)}</span>
                <span>Lead: {project.lead}</span>
              </p>
            </div>
            <div className="flex items-center gap-2">
              <AvatarGroup names={["OK", "SR", "DM", "FZ", "HN"]} />
              <Button variant="secondary" size="sm" onClick={() => push({ tone: "info", title: "Share project link copied" })}>Share</Button>
            </div>
          </div>
          <Tabs className="mt-4 px-3 lg:px-4" items={tabs.map((t) => ({ value: t, label: t }))} value={tab} onChange={setTab} />

          <div className="p-5 lg:p-6">
            {tab === "Overview" && (
              <div className="grid gap-3 sm:grid-cols-3">
                <div className="rounded-card border border-line bg-slate-50/70 p-4">
                  <p className="text-caption font-medium text-subtle uppercase">Budget</p>
                  <p className="mt-1 text-h3">{fmt(project.budget)}</p>
                </div>
                <div className="rounded-card border border-line bg-slate-50/70 p-4">
                  <p className="text-caption font-medium text-subtle uppercase">Committed</p>
                  <p className="mt-1 text-h3 text-info-strong">{fmt(project.spent)}</p>
                  <p className="mt-1 text-caption text-subtle">{Math.round((project.spent / project.budget) * 100)}% of budget</p>
                </div>
                <div className="rounded-card border border-line bg-primary-50 p-4">
                  <p className="text-caption font-medium text-primary-700/70 uppercase">Progress</p>
                  <p className="mt-1 text-h3 text-primary-700">{project.progress}%</p>
                  <Progress value={project.progress} className="mt-2" />
                </div>
                <div className="sm:col-span-2 rounded-card border border-line p-4">
                  <p className="mb-2 text-caption font-semibold text-subtle uppercase">Next milestones</p>
                  {milestones.filter((m) => !m.done).slice(0, 2).map((m) => (
                    <p key={m.title} className="flex items-center justify-between border-b border-slate-100 py-2 text-small last:border-0">
                      <span className="font-medium text-ink">{m.title}</span>
                      <span className="text-caption text-muted">{m.date}</span>
                    </p>
                  ))}
                </div>
                <div className="rounded-card border border-line p-4">
                  <p className="mb-2 text-caption font-semibold text-subtle uppercase">Exceptions</p>
                  {project.status === "Delayed" ? (
                    <p className="text-small text-danger-strong">Hardware import stuck at customs — escalation to procurement sent.</p>
                  ) : (
                    <p className="text-small text-muted">None. All workstreams on plan.</p>
                  )}
                </div>
              </div>
            )}

            {tab === "BOQ" && (
              <>
                <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
                  <p className="text-small text-muted">Bill of Quantities · v4 · approved Sep 02</p>
                  <div className="flex gap-2">
                    <Button variant="secondary" size="sm"><Download size={13} /> Export BOQ</Button>
                    <Button size="sm"><Plus size={13} /> Add line</Button>
                  </div>
                </div>
                <div className="overflow-hidden rounded-card border border-line">
                  <DataTable rows={boqRows} columns={boqCols} />
                  <div className="flex items-center justify-between border-t border-line bg-slate-50 px-4 py-3">
                    <span className="text-small font-semibold text-ink">BOQ total</span>
                    <span className="text-h3 text-ink">{fmt(boqTotal)}</span>
                  </div>
                </div>
              </>
            )}

            {tab === "Milestones" && (
              <ol className="max-w-xl">
                {milestones.map((m, i) => (
                  <li key={m.title} className="relative flex gap-3 pb-5 last:pb-0">
                    <span
                      className={cn(
                        "z-10 grid h-6 w-6 shrink-0 place-items-center rounded-full border",
                        m.done ? "border-primary-500 bg-primary-500 text-white" : "border-line-strong bg-white text-[10px] font-bold text-subtle"
                      )}
                    >
                      {m.done ? <Check size={12} /> : i + 1}
                    </span>
                    {i < milestones.length - 1 && (
                      <span className={cn("absolute top-6 bottom-0 left-3 w-px", m.done ? "bg-primary-500/60" : "bg-slate-200")} aria-hidden />
                    )}
                    <span className="pt-0.5">
                      <span className={cn("block text-small font-semibold", m.done ? "text-ink" : "text-muted")}>{m.title}</span>
                      <span className="block text-caption text-subtle">{m.date} · {m.done ? "completed" : "scheduled"}</span>
                    </span>
                  </li>
                ))}
              </ol>
            )}

            {tab === "Files" && (
              <ul className="divide-y divide-slate-100 rounded-card border border-line">
                {projectFiles.map((f) => (
                  <li key={f.name} className="flex items-center gap-3 px-4 py-3 transition-colors hover:bg-slate-50">
                    <span className="grid h-9 w-9 place-items-center rounded-control bg-slate-100 text-slate-600"><FileText size={16} /></span>
                    <span className="min-w-0 flex-1">
                      <span className="block truncate text-small font-medium text-ink">{f.name}</span>
                      <span className="block text-caption text-subtle">{f.kind} · {f.size} · updated {f.updated}</span>
                    </span>
                    <Button variant="ghost" size="sm" aria-label={`Preview ${f.name}`}><Eye size={15} /></Button>
                    <Button variant="secondary" size="sm" onClick={() => push({ tone: "info", title: `Downloading ${f.name}` })}>
                      <Download size={13} />
                    </Button>
                  </li>
                ))}
              </ul>
            )}

            {tab === "Design Proposal" && (
              <div className="grid gap-3 sm:grid-cols-2">
                {[
                  { name: "Concept A — Warm Oak & Sage", ver: "v4 · 12 pages", state: "Approved" },
                  { name: "Concept B — Walnut Executive", ver: "v2 · 9 pages", state: "Pending" },
                ].map((d) => (
                  <div key={d.name} className="overflow-hidden rounded-card border border-line">
                    <div className="relative flex h-36 items-center justify-center bg-[linear-gradient(140deg,#eaf5e3,#d2d7da)]" role="img" aria-label="Design proposal cover">
                      <TreePine size={36} className="text-primary-600/50" />
                      <span className="absolute top-2.5 right-2.5"><StatusBadge status={d.state === "Approved" ? "Approved" : "Negotiation"} /></span>
                    </div>
                    <div className="flex items-center justify-between gap-2 p-4">
                      <div className="min-w-0">
                        <p className="truncate text-small font-semibold text-ink">{d.name}</p>
                        <p className="text-caption text-subtle">{d.ver} · PDF</p>
                      </div>
                      <div className="flex shrink-0 gap-1.5">
                        <Button variant="ghost" size="sm" aria-label="Preview"><Eye size={15} /></Button>
                        <Button
                          size="sm"
                          disabled={d.state === "Approved"}
                          onClick={() => push({ tone: "success", title: "Proposal approved", desc: "Client sign-off recorded against the project." })}
                        >
                          {d.state === "Approved" ? "Approved" : "Approve"}
                        </Button>
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}

            {tab === "Site Visit" && (
              <div className="grid gap-3 sm:grid-cols-2">
                <Card className="shadow-none ring-1 ring-line" padded>
                  <CardHeader className="mb-2" title="Next visit — Oct 08, 11:00" action={<Badge tone="warning">Scheduled</Badge>} />
                  <ul className="space-y-1.5 text-small text-muted">
                    {[
                      ["Inspector", "Hira T."],
                      ["Checklist", "Measurements, ceiling grid, plumbing points"],
                      ["Client contact", "+92 300 8451290"],
                    ].map(([k, v]) => (
                      <li key={k} className="flex justify-between gap-4">
                        <span className="text-subtle">{k}</span>
                        <span className="text-right font-medium text-ink">{v}</span>
                      </li>
                    ))}
                  </ul>
                  <Button className="mt-4 w-full" variant="secondary" size="sm" onClick={() => push({ tone: "primary", title: "Visit report form opened" })}>
                    Log visit report
                  </Button>
                </Card>
                <Card className="shadow-none ring-1 ring-line">
                  <CardHeader className="mb-2" title="Completed visits" />
                  {[
                    { d: "Aug 20", n: "Measurements + photos", ok: true },
                    { d: "Sep 12", n: "Electrical rough-in check", ok: true },
                    { d: "Sep 29", n: "Ceiling handover inspection", ok: false },
                  ].map((v) => (
                    <p key={v.d} className="flex items-center justify-between border-b border-slate-100 py-2 text-small last:border-0">
                      <span className="text-slate-700">{v.d} · {v.n}</span>
                      <Badge tone={v.ok ? "success" : "warning"}>{v.ok ? "Signed off" : "Punch list"}</Badge>
                    </p>
                  ))}
                </Card>
              </div>
            )}

            {tab === "Production" && (
              <ul className="space-y-2">
                {[
                  { id: "PO-418", item: "TV panel wall + media unit", status: "In Progress", eta: "Oct 12" },
                  { id: "PO-419", item: "Master wardrobe (sliding)", status: "Pending", eta: "Oct 16" },
                  { id: "PO-420", item: "Kitchen acrylic shutters", status: "On Hold", eta: "Oct 20" },
                ].map((po) => (
                  <li key={po.id} className="flex flex-wrap items-center gap-3 rounded-card border border-line bg-white px-4 py-3 shadow-card">
                    <span className="font-mono text-caption text-subtle">{po.id}</span>
                    <span className="min-w-0 flex-1 truncate text-small font-medium text-ink">{po.item}</span>
                    <StatusBadge status={po.status} />
                    <span className="text-caption text-muted">ETA {po.eta}</span>
                    <Button variant="secondary" size="sm" onClick={() => push({ tone: "info", title: `${po.id} opened in Operations`, desc: "Workshop board updates sync live." })}>Open</Button>
                  </li>
                ))}
              </ul>
            )}

            {tab === "Installation" && (
              <div className="max-w-lg">
                <ul className="mb-4 space-y-2">
                  {[
                    { d: "Nov 06", z: "Zone 1 — Living + media wall", s: "On Track" },
                    { d: "Nov 10", z: "Zone 2 — Master + wardrobes", s: "Pending" },
                    { d: "Nov 14", z: "Zone 3 — Kitchen install + snag", s: "Pending" },
                  ].map((row) => (
                    <li key={row.d} className="flex items-center gap-3 rounded-card border border-line px-4 py-3">
                      <span className="grid h-9 w-12 place-items-center rounded-control bg-slate-100 text-caption font-semibold text-slate-700">{row.d}</span>
                      <span className="min-w-0 flex-1 truncate text-small font-medium text-ink">{row.z}</span>
                      <StatusBadge status={row.s} />
                    </li>
                  ))}
                </ul>
                <Button onClick={() => setSignoffOpen(true)}>
                  <Check size={15} /> Request Customer Sign-off
                </Button>
              </div>
            )}
          </div>
        </Card>
      </div>

      {/* Customer sign-off */}
      <Modal
        open={signoffOpen}
        onClose={() => setSignoffOpen(false)}
        title="Customer sign-off"
        description="Captures acceptance for the completed installation zone."
        footer={
          <>
            <Button variant="secondary" onClick={() => setSignoffOpen(false)}>Cancel</Button>
            <Button
              onClick={() => {
                setSignoffOpen(false);
                push({ tone: "success", title: "Sign-off recorded", desc: "Customer signature captured · invoice milestone unlocked." });
              }}
            >
              <Check size={15} /> Record Sign-off
            </Button>
          </>
        }
      >
        <div className="space-y-3">
          <div className="grid h-28 place-items-center rounded-card border-2 border-dashed border-slate-300 text-subtle">
            <span className="text-caption">Signature pad — customer signs here</span>
          </div>
          <label className="flex items-start gap-2.5 text-small text-slate-700">
            <input type="checkbox" defaultChecked className="mt-1 h-4 w-4 accent-[#4F9D21]" />
            All punch-list items for this zone are closed and inspected.
          </label>
          <label className="flex items-start gap-2.5 text-small text-slate-700">
            <input type="checkbox" defaultChecked className="mt-1 h-4 w-4 accent-[#4F9D21]" />
            Care instructions &amp; 2-year warranty pack delivered.
          </label>
        </div>
      </Modal>
    </div>
  );
}

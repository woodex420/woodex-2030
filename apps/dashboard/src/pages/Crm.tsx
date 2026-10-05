import { useMemo, useState } from "react";
import type { DragEvent } from "react";
import { cn } from "@/lib/cn";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge, StatusBadge } from "@/components/ui/Badge";
import { Field, Input, SearchInput, Select } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { EmptyState, ErrorState, SkeletonRows } from "@/components/ui/States";
import { useToast } from "@/components/ui/Toast";
import { useApi, mutate, timeAgo, type ApiLead } from "@/lib/api";
import { Download, Filter, Plus, Dots, Users } from "@/icons";

const STAGES = ["New", "Qualified", "Meeting", "Quotation", "Won", "Lost"] as const;
type Stage = (typeof STAGES)[number];

const stageDot: Record<Stage, string> = {
  New: "bg-info",
  Qualified: "bg-primary-500",
  Meeting: "bg-warning",
  Quotation: "bg-info",
  Won: "bg-success",
  Lost: "bg-danger",
};

const initials = (name: string) =>
  name.split(" ").map((w) => w[0]).slice(0, 2).join("").toUpperCase();

export default function Crm() {
  const { data, error, loading, reload } = useApi<{ total: number; items: ApiLead[] }>("/api/leads", 15000);
  const [query, setQuery] = useState("");
  const [priority, setPriority] = useState("All");
  const [dragId, setDragId] = useState<number | null>(null);
  const [overCol, setOverCol] = useState<Stage | null>(null);
  const [newOpen, setNewOpen] = useState(false);
  const [draft, setDraft] = useState({ name: "", interest: "", contact: "", source: "Website Form", note: "" });
  const { push } = useToast();

  const leads = data?.items ?? [];

  const byStage = useMemo(() => {
    const map: Record<Stage, ApiLead[]> = { New: [], Qualified: [], Meeting: [], Quotation: [], Won: [], Lost: [] };
    leads.forEach((l) => {
      if ((STAGES as readonly string[]).includes(l.status)) map[l.status as Stage].push(l);
    });
    return map;
  }, [leads]);

  const matches = (l: ApiLead) =>
    query.trim() === "" || (l.name + " " + (l.interest ?? "")).toLowerCase().includes(query.toLowerCase());

  const move = async (id: number, to: Stage) => {
    const current = leads.find((l) => l.id === id);
    if (!current || current.status === to) return;
    try {
      await mutate("/api/leads/" + id, { status: to });
      reload();
      push({ tone: "primary", title: `${current.name} → ${to}`, desc: "Stage saved to the shared backend." });
    } catch (e) {
      push({ tone: "danger", title: "Could not move lead", desc: e instanceof Error ? e.message : String(e) });
    }
  };

  const onDrop = (e: DragEvent, col: Stage) => {
    e.preventDefault();
    const id = Number(e.dataTransfer.getData("text/plain")) || dragId;
    setOverCol(null);
    setDragId(null);
    if (id) move(id, col);
  };

  if (error)
    return (
      <div>
        <PageHeader title="CRM — Lead Pipeline" />
        <ErrorState
          title="Lead API unreachable"
          description="Start the shared backend (npm run dev:api) — leads are created live by the storefront contact form."
          debug={error}
          onRetry={reload}
        />
      </div>
    );

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Sales", to: "/quotations" }, { label: "CRM" }]}
        title="CRM — Lead Pipeline"
        description="Live leads from the website — submit one in the storefront contact form and watch it land here."
        actions={
          <>
            <Button variant="secondary" onClick={() => push({ tone: "info", title: "Export started", desc: "leads-" + leads.length + ".csv" })}>
              <Download size={15} /> Export
            </Button>
            <Button onClick={() => setNewOpen(true)}>
              <Plus size={15} /> New Lead
            </Button>
          </>
        }
      />

      <Card className="mb-4 flex flex-wrap items-center gap-3 p-3.5" padded={false}>
        <SearchInput className="w-full sm:max-w-64" value={query} onChange={setQuery} placeholder="Search leads…" />
        <span className="inline-flex items-center gap-1.5 text-caption font-medium text-subtle">
          <Filter size={14} /> Owner
        </span>
        <Select className="h-7 w-40 text-caption" value={priority} onChange={(e) => setPriority(e.target.value)} aria-label="Filter by owner">
          <option>All</option>
          {Array.from(new Set(leads.map((l) => l.owner).filter(Boolean) as string[])).map((o) => (
            <option key={o}>{o}</option>
          ))}
        </Select>
        <span className="ml-auto flex items-center gap-1.5 text-caption text-subtle">
          <span className="h-1.5 w-1.5 animate-pulse rounded-full bg-success" />
          {loading ? "syncing…" : `${leads.length} leads · drag between stages saves instantly`}
        </span>
      </Card>

      {loading && leads.length === 0 ? (
        <Card>
          <SkeletonRows rows={5} cols={3} />
        </Card>
      ) : (
        <div className="scrollbar-slim -mx-4 flex gap-3 overflow-x-auto px-4 pb-2 lg:-mx-6 lg:px-6">
          {STAGES.map((stage) => {
            const items = byStage[stage].filter(matches);
            return (
              <section
                key={stage}
                aria-label={`${stage} stage`}
                onDragOver={(e) => { e.preventDefault(); setOverCol(stage); }}
                onDragLeave={() => setOverCol((c) => (c === stage ? null : c))}
                onDrop={(e) => onDrop(e, stage)}
                className={cn(
                  "flex w-[280px] shrink-0 flex-col rounded-panel border bg-slate-100/70 transition-colors duration-150",
                  overCol === stage ? "border-primary-500 bg-primary-50" : "border-line"
                )}
              >
                <header className="flex items-center gap-2 px-3.5 pt-3 pb-2">
                  <span className={cn("h-2 w-2 rounded-full", stageDot[stage])} aria-hidden />
                  <h2 className="text-small font-semibold text-ink">{stage}</h2>
                  <span className="rounded-full bg-white px-1.5 text-caption font-semibold text-slate-600 ring-1 ring-line">{items.length}</span>
                  <button aria-label={`Stage options for ${stage}`} className="ml-auto grid h-6 w-6 place-items-center rounded text-slate-500 hover:bg-white hover:text-ink">
                    <Dots size={14} />
                  </button>
                </header>
                <ul className="min-h-[120px] flex-1 space-y-2 px-2.5 pb-3">
                  {items.map((l) => (
                    <li
                      key={l.id}
                      draggable
                      onDragStart={(e) => { e.dataTransfer.setData("text/plain", String(l.id)); e.dataTransfer.effectAllowed = "move"; setDragId(l.id); }}
                      onDragEnd={() => setDragId(null)}
                      className={cn(
                        "cursor-grab rounded-card border border-line bg-white p-3 shadow-card transition-all duration-150 hover:shadow-pop active:cursor-grabbing",
                        dragId === l.id && "opacity-50 ring-2 ring-primary-500/40"
                      )}
                    >
                      <div className="flex items-start justify-between gap-2">
                        <p className="text-small font-semibold text-ink">{l.name}</p>
                        <Badge tone={l.source === "Referral" ? "success" : "info"} dot={false} className="px-1.5 py-0">{l.source}</Badge>
                      </div>
                      <p className="mt-1 line-clamp-1 text-caption text-muted">{l.interest ?? "—"}</p>
                      {l.note && <p className="mt-0.5 text-caption font-medium text-primary-700">{l.note}</p>}
                      <div className="mt-2.5 flex items-center justify-between border-t border-slate-100 pt-2.5">
                        <span className="flex items-center gap-1.5 text-caption text-subtle">
                          <span className="grid h-5 w-5 place-items-center rounded-full bg-slate-100 text-[9px] font-bold text-slate-600">{initials(l.owner ?? "?")}</span>
                          {l.owner ?? "Unassigned"}
                        </span>
                        <span className="text-caption text-subtle">{timeAgo(l.createdAt)}</span>
                      </div>
                    </li>
                  ))}
                  {items.length === 0 && (
                    <li className="grid place-items-center rounded-card border border-dashed border-slate-300 py-6 text-caption text-subtle">
                      Drop a lead here
                    </li>
                  )}
                </ul>
              </section>
            );
          })}
        </div>
      )}

      {leads.length > 0 && (
        <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3 lg:gap-4">
          <Card className="py-4">
            <p className="text-caption font-medium text-subtle uppercase">Won (live)</p>
            <p className="mt-1 text-h2 text-ink">{byStage.Won.length} leads</p>
            <p className="mt-1 text-caption text-success-strong">from the shared backend</p>
          </Card>
          <Card className="py-4">
            <p className="text-caption font-medium text-subtle uppercase">Win rate</p>
            <p className="mt-1 text-h2 text-ink">{leads.length ? Math.round((byStage.Won.length / Math.max(1, byStage.Won.length + byStage.Lost.length)) * 100) : 0}%</p>
            <p className="mt-1 text-caption text-subtle">won ÷ (won + lost)</p>
          </Card>
          <Card className="py-4">
            <p className="text-caption font-medium text-subtle uppercase">Pipeline value</p>
            <p className="mt-1 text-h2 text-ink">{leads.length ? "$" + (leads.length * 31.4).toFixed(1) + "k" : "—"}</p>
            <p className="mt-1 text-caption text-subtle">avg note-weighted estimate</p>
          </Card>
        </div>
      )}

      <Modal
        open={newOpen}
        onClose={() => setNewOpen(false)}
        title="Add New Lead"
        description="Saves to the same DB the storefront writes to."
        footer={
          <>
            <Button variant="secondary" onClick={() => setNewOpen(false)}>Cancel</Button>
            <Button
              onClick={async () => {
                if (!draft.name.trim()) { push({ tone: "danger", title: "Lead name is required" }); return; }
                try {
                  await mutate("/api/leads", draft, "POST");
                  setNewOpen(false);
                  setDraft({ name: "", interest: "", contact: "", source: "Website Form", note: "" });
                  reload();
                  push({ tone: "success", title: "Lead created", desc: `${draft.name} added to New.` });
                } catch (e) {
                  push({ tone: "danger", title: "Save failed", desc: e instanceof Error ? e.message : String(e) });
                }
              }}
            >
              Create Lead
            </Button>
          </>
        }
      >
        <div className="space-y-3.5">
          <Field label="Lead name" required>
            <Input value={draft.name} onChange={(e) => setDraft((d) => ({ ...d, name: e.target.value }))} placeholder="e.g. Ali Raza — DHA Villa" />
          </Field>
          <Field label="Interest">
            <Input value={draft.interest} onChange={(e) => setDraft((d) => ({ ...d, interest: e.target.value }))} placeholder="Modular kitchen, wardrobes…" />
          </Field>
          <div className="grid grid-cols-2 gap-3.5">
            <Field label="Contact">
              <Input value={draft.contact} onChange={(e) => setDraft((d) => ({ ...d, contact: e.target.value }))} placeholder="+92 300 …" />
            </Field>
            <Field label="Source">
              <Select value={draft.source} onChange={(e) => setDraft((d) => ({ ...d, source: e.target.value }))}>
                {["Website Form", "Referral", "Instagram", "Walk-in", "Cold Outreach", "Expo", "Manual Entry"].map((s) => (
                  <option key={s}>{s}</option>
                ))}
              </Select>
            </Field>
          </div>
          <p className="text-caption text-subtle">Status starts at <StatusBadge status="New" className="mx-1 align-middle" /> · <Users size={12} className="inline" /> unassigned until claimed.</p>
        </div>
      </Modal>

      {!loading && leads.length === 0 && (
        <Card className="mt-4">
          <EmptyState
            icon={<Users size={20} />}
            title="No leads yet"
            description="Submit the storefront contact form (port 5174) or create one manually — it appears here instantly."
            action={<Button onClick={() => setNewOpen(true)}><Plus size={14} /> Create first lead</Button>}
          />
        </Card>
      )}
    </div>
  );
}

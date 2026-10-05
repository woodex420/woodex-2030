import { useMemo, useState } from "react";
import type { DragEvent } from "react";
import { cn } from "@/lib/cn";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button, IconButton } from "@/components/ui/Button";
import { Badge, StatusBadge } from "@/components/ui/Badge";
import { SearchInput } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { Field, Input, Select } from "@/components/ui/Field";
import { useToast } from "@/components/ui/Toast";
import { kanbanColumns } from "@/data/mock";
import type { Lead, LeadStatus } from "@/data/mock";
import { Download, Filter, Plus, Dots } from "@/icons";

const stageToneDot: Record<LeadStatus, string> = {
  New: "bg-info",
  Qualified: "bg-primary-500",
  Meeting: "bg-warning",
  Quotation: "bg-info",
  Won: "bg-success",
  Lost: "bg-danger",
};

type Board = Record<LeadStatus, Lead[]>;

function initialBoard(): Board {
  const b = { New: [], Qualified: [], Meeting: [], Quotation: [], Won: [], Lost: [] } as Board;
  kanbanColumns.forEach((c) => b[c.key].push(...c.leads));
  return b;
}

export default function Crm() {
  const [board, setBoard] = useState<Board>(initialBoard);
  const [query, setQuery] = useState("");
  const [priority, setPriority] = useState<"All" | "High" | "Medium" | "Low">("All");
  const [dragId, setDragId] = useState<string | null>(null);
  const [overCol, setOverCol] = useState<LeadStatus | null>(null);
  const [newOpen, setNewOpen] = useState(false);
  const [draft, setDraft] = useState({ name: "", interest: "", value: "" });
  const { push } = useToast();

  const stageOrder = useMemo(() => kanbanColumns.map((c) => c.key), []);

  const matches = (l: Lead) =>
    (priority === "All" || l.priority === priority) &&
    (query.trim() === "" ||
      (l.name + " " + l.interest).toLowerCase().includes(query.toLowerCase()));

  const move = (id: string, to: LeadStatus) => {
    setBoard((b) => {
      const from = stageOrder.find((s) => b[s].some((l) => l.id === id));
      if (!from || from === to) return b;
      const lead = b[from].find((l) => l.id === id)!;
      return {
        ...b,
        [from]: b[from].filter((l) => l.id !== id),
        [to]: [{ ...lead, status: to }, ...b[to]],
      };
    });
    push({ tone: "primary", title: `Lead moved to ${to}`, desc: `Stage change recorded in CRM timeline.` });
  };

  const onDrop = (e: DragEvent, col: LeadStatus) => {
    e.preventDefault();
    const id = e.dataTransfer.getData("text/plain") || dragId;
    setOverCol(null);
    setDragId(null);
    if (id) move(id, col);
  };

  const total = stageOrder.reduce((n, s) => n + board[s].length, 0);

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Sales", to: "/quotations" }, { label: "CRM" }]}
        title="CRM — Lead Pipeline"
        description="Qualify, assign and progress leads through the sales funnel."
        actions={
          <>
            <Button variant="secondary" onClick={() => push({ tone: "info", title: "Export started", desc: "leads_october.csv will download shortly." })}>
              <Download size={15} /> Export
            </Button>
            <Button onClick={() => setNewOpen(true)}>
              <Plus size={15} /> New Lead
            </Button>
          </>
        }
      />

      {/* Filters / search — §Screen 01 structure */}
      <Card className="mb-4 flex flex-wrap items-center gap-3 p-3.5" padded={false}>
        <SearchInput
          className="w-full sm:max-w-64"
          value={query}
          onChange={setQuery}
          placeholder="Search leads…"
        />
        <span className="inline-flex items-center gap-1.5 text-caption font-medium text-subtle">
          <Filter size={14} /> Priority
        </span>
        <div className="flex gap-1">
          {(["All", "High", "Medium", "Low"] as const).map((p) => (
            <button
              key={p}
              onClick={() => setPriority(p)}
              className={cn(
                "h-7 rounded-full border px-2.5 text-caption font-medium transition-colors duration-150",
                priority === p
                  ? "border-primary-500 bg-primary-500 text-white"
                  : "border-line-strong bg-white text-slate-600 hover:border-slate-400"
              )}
            >
              {p}
            </button>
          ))}
        </div>
        <span className="ml-auto hidden text-caption text-subtle md:block">
          {total} leads · drag cards between stages
        </span>
      </Card>

      {/* Kanban board */}
      <div className="scrollbar-slim -mx-4 flex gap-3 overflow-x-auto px-4 pb-2 lg:-mx-6 lg:px-6">
        {stageOrder.map((stage) => {
          const leads = board[stage].filter(matches);
          const sum = board[stage].reduce((n, l) => n + Number((l.value ?? "0").replace(/[$,]/g, "")), 0);
          return (
            <section
              key={stage}
              aria-label={`${stage} stage`}
              onDragOver={(e) => {
                e.preventDefault();
                setOverCol(stage);
              }}
              onDragLeave={() => setOverCol((c) => (c === stage ? null : c))}
              onDrop={(e) => onDrop(e, stage)}
              className={cn(
                "flex w-[280px] shrink-0 flex-col rounded-panel border bg-slate-100/70 transition-colors duration-150",
                overCol === stage ? "border-primary-500 bg-primary-50" : "border-line"
              )}
            >
              <header className="flex items-center gap-2 px-3.5 pt-3 pb-2">
                <span className={cn("h-2 w-2 rounded-full", stageToneDot[stage])} aria-hidden />
                <h2 className="text-small font-semibold text-ink">{stage}</h2>
                <span className="rounded-full bg-white px-1.5 text-caption font-semibold text-slate-600 ring-1 ring-line">
                  {leads.length}
                </span>
                <IconButton label={`Stage options for ${stage}`} className="ml-auto h-6 w-6">
                  <Dots size={14} />
                </IconButton>
              </header>
              <p className="px-3.5 pb-2 text-caption text-subtle">{sum > 0 ? `${"$"}${sum.toLocaleString()} pipeline value` : "No value tracked"}</p>
              <ul className="min-h-[120px] flex-1 space-y-2 px-2.5 pb-3">
                {leads.map((l) => (
                  <li
                    key={l.id}
                    draggable
                    onDragStart={(e) => {
                      e.dataTransfer.setData("text/plain", l.id);
                      e.dataTransfer.effectAllowed = "move";
                      setDragId(l.id);
                    }}
                    onDragEnd={() => setDragId(null)}
                    className={cn(
                      "cursor-grab rounded-card border border-line bg-white p-3 shadow-card transition-all duration-150 hover:shadow-pop active:cursor-grabbing",
                      dragId === l.id && "opacity-50 ring-2 ring-primary-500/40"
                    )}
                  >
                    <div className="flex items-start justify-between gap-2">
                      <p className="text-small font-semibold text-ink">{l.name}</p>
                      <Badge tone={l.priority === "High" ? "danger" : l.priority === "Medium" ? "warning" : "neutral"} dot={false} className="px-1.5">
                        {l.priority}
                      </Badge>
                    </div>
                    <p className="mt-1 line-clamp-1 text-caption text-muted">{l.interest}</p>
                    <p className="mt-0.5 text-caption text-subtle">{l.source}</p>
                    <div className="mt-2.5 flex items-center justify-between border-t border-slate-100 pt-2.5">
                      <span className="text-caption font-semibold text-ink">{l.value}</span>
                      <span className="flex items-center gap-1.5 text-caption text-subtle">
                        <span className="grid h-5 w-5 place-items-center rounded-full bg-slate-100 text-[9px] font-bold text-slate-600">
                          {l.owner.slice(0, 2)}
                        </span>
                        {l.date}
                      </span>
                    </div>
                  </li>
                ))}
                {leads.length === 0 && (
                  <li className="grid place-items-center rounded-card border border-dashed border-slate-300 py-6 text-caption text-subtle">
                    Drop a lead here
                  </li>
                )}
              </ul>
            </section>
          );
        })}
      </div>

      {/* Won/Lost summary */}
      <div className="mt-4 grid grid-cols-1 gap-3 sm:grid-cols-3 lg:gap-4">
        <Card className="py-4" padded={false}>
          <div className="px-5">
            <p className="text-caption font-medium text-subtle uppercase">Won this month</p>
            <p className="mt-1 text-h2 text-ink">
              {board.Won.length} leads · <span className="text-success-strong">${board.Won.reduce((n, l) => n + Number((l.value ?? "0").replace(/[$,]/g, "")), 0).toLocaleString()}</span>
            </p>
          </div>
        </Card>
        <Card className="py-4" padded={false}>
          <div className="px-5">
            <p className="text-caption font-medium text-subtle uppercase">Win rate</p>
            <p className="mt-1 text-h2 text-ink">34.2%</p>
          </div>
        </Card>
        <Card className="py-4" padded={false}>
          <div className="px-5">
            <p className="text-caption font-medium text-subtle uppercase">Avg. response time</p>
            <p className="mt-1 text-h2 text-ink">2.4 h</p>
          </div>
        </Card>
      </div>

      {/* New lead modal */}
      <Modal
        open={newOpen}
        onClose={() => setNewOpen(false)}
        title="Add New Lead"
        description="Leads enter the pipeline in the New stage."
        footer={
          <>
            <Button variant="secondary" onClick={() => setNewOpen(false)}>
              Cancel
            </Button>
            <Button
              onClick={() => {
                if (!draft.name.trim()) {
                  push({ tone: "danger", title: "Lead name is required" });
                  return;
                }
                const lead: Lead = {
                  id: "L-" + Math.floor(1100 + Math.random() * 90),
                  name: draft.name.trim(),
                  interest: draft.interest.trim() || "General inquiry",
                  source: "Manual Entry",
                  date: "Oct 05",
                  status: "New",
                  priority: "Medium",
                  owner: "You",
                  value: draft.value.trim() || "$0",
                };
                setBoard((b) => ({ ...b, New: [lead, ...b.New] }));
                setNewOpen(false);
                setDraft({ name: "", interest: "", value: "" });
                push({ tone: "success", title: "Lead created", desc: `${lead.name} added to New.` });
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
          <Field label="Interest" hint="What is the lead asking for?">
            <Input value={draft.interest} onChange={(e) => setDraft((d) => ({ ...d, interest: e.target.value }))} placeholder="Modular kitchen, wardrobes…" />
          </Field>
          <div className="grid grid-cols-2 gap-3.5">
            <Field label="Est. value">
              <Input value={draft.value} onChange={(e) => setDraft((d) => ({ ...d, value: e.target.value }))} placeholder="$25,000" />
            </Field>
            <Field label="Source">
              <Select defaultValue="Website Form">
                {["Website Form", "Referral", "Instagram", "Walk-in", "Cold Outreach", "Expo"].map((s) => (
                  <option key={s}>{s}</option>
                ))}
              </Select>
            </Field>
          </div>
          <p className="text-caption text-subtle">Status will be set to <StatusBadge status="New" className="mx-1 align-middle" /> automatically.</p>
        </div>
      </Modal>
    </div>
  );
}

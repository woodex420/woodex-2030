import { useMemo, useState } from "react";
import type { DragEvent } from "react";
import { cn } from "@/lib/cn";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge, StatusBadge } from "@/components/ui/Badge";
import { Field, Input, SearchInput, Select, Textarea } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { EmptyState, ErrorState, SkeletonRows } from "@/components/ui/States";
import { useToast } from "@/components/ui/Toast";
import { useApi, mutate, timeAgo, type ApiLead, type ApiSiteVisit } from "@/lib/api";
import { Download, Filter, Plus, Dots, Users } from "@/icons";

const STAGES = ["New", "Contacted", "Qualified", "Meeting", "Site visit booked", "Site visit completed", "Quotation", "Won", "Lost"] as const;
type Stage = (typeof STAGES)[number];
type SiteVisitDraft = {
  leadId: string;
  scheduledAt: string;
  timezone: string;
  address: string;
  owner: string;
  notes: string;
  reminderAt: string;
};

const stageDot: Record<Stage, string> = {
  New: "bg-info",
  Contacted: "bg-primary-400",
  Qualified: "bg-primary-500",
  Meeting: "bg-warning",
  "Site visit booked": "bg-warning",
  "Site visit completed": "bg-success",
  Quotation: "bg-info",
  Won: "bg-success",
  Lost: "bg-danger",
};

const localDateTimeInput = (date: Date) => {
  const local = new Date(date.getTime() - date.getTimezoneOffset() * 60_000);
  return local.toISOString().slice(0, 16);
};

const zonedDateTimeInput = (date: Date, timeZone: string) => {
  const parts = new Intl.DateTimeFormat("en-CA", {
    timeZone,
    year: "numeric", month: "2-digit", day: "2-digit",
    hour: "2-digit", minute: "2-digit", hourCycle: "h23",
  }).formatToParts(date).reduce<Record<string, string>>((out, part) => {
    if (part.type !== "literal") out[part.type] = part.value;
    return out;
  }, {});
  return `${parts.year}-${parts.month}-${parts.day}T${parts.hour}:${parts.minute}`;
};

const zonedDateTimeToIso = (value: string, timeZone: string) => {
  const match = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/.exec(value);
  if (!match) throw new Error("Enter a valid visit date and time.");
  const [, yearText, monthText, dayText, hourText, minuteText] = match;
  const requested = [yearText, monthText, dayText, hourText, minuteText].map(Number);
  const [year, month, day, hour, minute] = requested;
  const targetUtc = Date.UTC(year, month - 1, day, hour, minute);
  const formatter = new Intl.DateTimeFormat("en-US", {
    timeZone,
    year: "numeric", month: "2-digit", day: "2-digit",
    hour: "2-digit", minute: "2-digit", second: "2-digit", hourCycle: "h23",
  });
  let instant = targetUtc;
  for (let i = 0; i < 4; i++) {
    const parts = formatter.formatToParts(new Date(instant)).reduce<Record<string, number>>((out, part) => {
      if (part.type !== "literal") out[part.type] = Number(part.value);
      return out;
    }, {});
    const representedUtc = Date.UTC(parts.year, parts.month - 1, parts.day, parts.hour, parts.minute, parts.second);
    const correction = targetUtc - representedUtc;
    instant += correction;
    if (correction === 0) break;
  }
  const check = formatter.formatToParts(new Date(instant)).reduce<Record<string, number>>((out, part) => {
    if (part.type !== "literal") out[part.type] = Number(part.value);
    return out;
  }, {});
  if (check.year !== year || check.month !== month || check.day !== day || check.hour !== hour || check.minute !== minute)
    throw new Error(`That local time does not exist in ${timeZone}. Choose another appointment time.`);
  return new Date(instant).toISOString();
};

const formatVisitDate = (visit: ApiSiteVisit) => {
  try {
    return new Intl.DateTimeFormat("en-PK", {
      dateStyle: "medium",
      timeStyle: "short",
      timeZone: visit.timezone || "Asia/Karachi",
    }).format(new Date(visit.scheduledAt));
  } catch {
    return new Date(visit.scheduledAt).toLocaleString();
  }
};

const newVisitDraft = (leadId = ""): SiteVisitDraft => ({
  leadId,
  scheduledAt: localDateTimeInput(new Date(Date.now() + 24 * 60 * 60 * 1000)),
  timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || "Asia/Karachi",
  address: "",
  owner: "",
  notes: "",
  reminderAt: "",
});

const initials = (name: string) =>
  name.split(" ").map((w) => w[0]).slice(0, 2).join("").toUpperCase();

export default function Crm() {
  const { data, error, loading, reload } = useApi<{ total: number; items: ApiLead[] }>("/api/leads", 15000);
  const siteVisitsApi = useApi<{ total: number; items: ApiSiteVisit[] }>("/api/site-visits", 15000);
  const [query, setQuery] = useState("");
  const [priority, setPriority] = useState("All");
  const [dragId, setDragId] = useState<number | null>(null);
  const [overCol, setOverCol] = useState<Stage | null>(null);
  const [newOpen, setNewOpen] = useState(false);
  const [visitOpen, setVisitOpen] = useState(false);
  const [visitEditingId, setVisitEditingId] = useState<number | null>(null);
  const [visitSaving, setVisitSaving] = useState(false);
  const [visitBusyId, setVisitBusyId] = useState<number | null>(null);
  const [outcomeDrafts, setOutcomeDrafts] = useState<Record<number, string>>({});
  const [draft, setDraft] = useState({ name: "", interest: "", contact: "", source: "Website Form", note: "" });
  const [visitDraft, setVisitDraft] = useState<SiteVisitDraft>(() => newVisitDraft());
  const { push } = useToast();

  const leads = data?.items ?? [];
  const siteVisits = siteVisitsApi.data?.items ?? [];

  const byStage = useMemo(() => {
    const map = {} as Record<Stage, ApiLead[]>;
    STAGES.forEach((stage) => { map[stage] = []; });
    leads.forEach((l) => {
      if ((STAGES as readonly string[]).includes(l.status)) map[l.status as Stage].push(l);
    });
    return map;
  }, [leads]);

  const matches = (l: ApiLead) =>
    (priority === "All" || (l.owner ?? "Unassigned") === priority) &&
    (query.trim() === "" || [l.name, l.company, l.interest, l.contact, l.email, l.phone, l.source]
      .filter(Boolean).join(" ").toLowerCase().includes(query.toLowerCase()));

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

  const openVisitForm = (visit?: ApiSiteVisit) => {
    if (visit) {
      setVisitEditingId(visit.id);
      setVisitDraft({
        leadId: String(visit.leadId),
        scheduledAt: zonedDateTimeInput(new Date(visit.scheduledAt), visit.timezone),
        timezone: visit.timezone,
        address: visit.address,
        owner: visit.owner,
        notes: visit.notes ?? "",
        reminderAt: visit.reminderAt ? zonedDateTimeInput(new Date(visit.reminderAt), visit.timezone) : "",
      });
    } else {
      setVisitEditingId(null);
      setVisitDraft(newVisitDraft(leads[0] ? String(leads[0].id) : ""));
    }
    setVisitOpen(true);
  };

  const saveVisit = async () => {
    if (!visitDraft.leadId || !visitDraft.scheduledAt || !visitDraft.address.trim()) {
      push({ tone: "danger", title: "Lead, date and visit location are required" });
      return;
    }
    const wasEditing = visitEditingId !== null;
    setVisitSaving(true);
    try {
      await mutate(visitEditingId ? `/api/site-visits/${visitEditingId}` : "/api/site-visits", {
        leadId: Number(visitDraft.leadId),
        scheduledAt: zonedDateTimeToIso(visitDraft.scheduledAt, visitDraft.timezone),
        timezone: visitDraft.timezone,
        address: visitDraft.address.trim(),
        owner: visitDraft.owner.trim() || "Unassigned",
        notes: visitDraft.notes.trim(),
        reminderAt: visitDraft.reminderAt ? zonedDateTimeToIso(visitDraft.reminderAt, visitDraft.timezone) : null,
      }, visitEditingId ? "PATCH" : "POST");
      setVisitOpen(false);
      setVisitEditingId(null);
      setVisitDraft(newVisitDraft());
      reload();
      siteVisitsApi.reload();
      push({ tone: "success", title: wasEditing ? "Site visit updated" : "Site visit booked", desc: wasEditing ? "The appointment and audit history were updated." : "The lead was updated and the staff reminder was saved." });
    } catch (e) {
      push({ tone: "danger", title: wasEditing ? "Could not update site visit" : "Could not book site visit", desc: e instanceof Error ? e.message : String(e) });
    } finally {
      setVisitSaving(false);
    }
  };

  const updateVisitStatus = async (visit: ApiSiteVisit, status: ApiSiteVisit["status"]) => {
    if (visit.status === status) return;
    setVisitBusyId(visit.id);
    try {
      await mutate(`/api/site-visits/${visit.id}`, { status });
      reload();
      siteVisitsApi.reload();
      push({ tone: "success", title: `${visit.ref} marked ${status}` });
    } catch (e) {
      push({ tone: "danger", title: "Could not update site visit", desc: e instanceof Error ? e.message : String(e) });
    } finally {
      setVisitBusyId(null);
    }
  };

  const saveVisitOutcome = async (visit: ApiSiteVisit) => {
    setVisitBusyId(visit.id);
    try {
      await mutate(`/api/site-visits/${visit.id}`, { outcome: outcomeDrafts[visit.id] ?? visit.outcome ?? "" });
      siteVisitsApi.reload();
      push({ tone: "success", title: `${visit.ref} outcome saved` });
    } catch (e) {
      push({ tone: "danger", title: "Could not save visit outcome", desc: e instanceof Error ? e.message : String(e) });
    } finally {
      setVisitBusyId(null);
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
            <Button variant="secondary" onClick={() => openVisitForm()} disabled={leads.length === 0} title={leads.length === 0 ? "Create a lead before booking a visit" : undefined}>
              <Plus size={15} /> Book visit
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

      <Card className="mb-4 p-4">
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h2 className="text-small font-semibold text-ink">Site visits</h2>
            <p className="mt-1 text-caption text-subtle">Schedule and track lead visits. Reminders create internal staff tasks; no customer message is sent automatically.</p>
          </div>
          <Button variant="secondary" onClick={() => openVisitForm()} disabled={leads.length === 0}>
            <Plus size={14} /> Book site visit
          </Button>
        </div>
        {siteVisitsApi.error ? (
          <div className="mt-3 flex flex-wrap items-center justify-between gap-2 rounded-control border border-danger/20 bg-danger-soft/30 px-3 py-2 text-caption text-danger-strong">
            <span>Site visits could not be loaded: {siteVisitsApi.error}</span>
            <Button variant="secondary" onClick={siteVisitsApi.reload}>Retry</Button>
          </div>
        ) : siteVisitsApi.loading && siteVisits.length === 0 ? (
          <p className="mt-3 text-caption text-subtle">Loading site visits…</p>
        ) : siteVisits.length === 0 ? (
          <p className="mt-3 rounded-card border border-dashed border-line-strong px-3 py-5 text-center text-caption text-subtle">
            No site visits booked yet. Choose a lead and schedule a visit to start tracking it here.
          </p>
        ) : (
          <div className="mt-3 space-y-2">
            {siteVisits.map((visit) => (
              <article key={visit.id} aria-label={`${visit.ref} site visit`} className="flex flex-col gap-3 rounded-card border border-line bg-slate-50/70 p-3 sm:flex-row sm:items-start sm:justify-between">
                <div className="min-w-0">
                  <div className="flex flex-wrap items-center gap-x-2 gap-y-1">
                    <p className="text-small font-semibold text-ink">{visit.ref}</p>
                    <span className="text-caption text-subtle">{visit.leadName ?? `Lead #${visit.leadId}`} · {visit.leadRef ?? ""}</span>
                    <StatusBadge status={visit.status} />
                  </div>
                  <p className="mt-1 text-caption font-medium text-slate-700">{formatVisitDate(visit)} · {visit.timezone}</p>
                  <p className="mt-0.5 break-words text-caption text-muted">{visit.address}</p>
                  <p className="mt-1 text-caption text-subtle">Owner: {visit.owner || "Unassigned"}</p>
                  {visit.notes && <p className="mt-1 whitespace-pre-wrap text-caption text-muted">{visit.notes}</p>}
                  {visit.outcome && <p className="mt-1 text-caption text-success-strong">Outcome: {visit.outcome}</p>}
                  {visit.reminderAt && <p className="mt-1 text-caption text-primary-700">Staff task reminder: {formatVisitDate({ ...visit, scheduledAt: visit.reminderAt })} · no automatic customer reminder</p>}
                </div>
                <div className="w-full shrink-0 space-y-2 sm:w-48">
                  <Field label="Visit status">
                    <Select
                      value={visit.status}
                      disabled={visitBusyId === visit.id}
                      aria-label={`Visit status for ${visit.ref}`}
                      onChange={(e) => updateVisitStatus(visit, e.target.value as ApiSiteVisit["status"])}
                    >
                      {["Scheduled", "Confirmed", "Completed", "Cancelled"].map((status) => <option key={status}>{status}</option>)}
                    </Select>
                  </Field>
                  <Button variant="secondary" className="w-full" onClick={() => openVisitForm(visit)}>
                    Edit visit
                  </Button>
                  <details className="rounded-control border border-line bg-white px-2.5 py-2">
                    <summary className="cursor-pointer text-caption font-medium text-primary-700">Record visit outcome</summary>
                    <div className="mt-2 space-y-2">
                      <Textarea
                        aria-label={`Outcome for ${visit.ref}`}
                        value={outcomeDrafts[visit.id] ?? visit.outcome ?? ""}
                        onChange={(e) => setOutcomeDrafts((drafts) => ({ ...drafts, [visit.id]: e.target.value }))}
                        placeholder="Measurements, decisions, and agreed next steps"
                      />
                      <Button variant="secondary" className="w-full" disabled={visitBusyId === visit.id} onClick={() => void saveVisitOutcome(visit)}>
                        Save outcome
                      </Button>
                    </div>
                  </details>
                </div>
              </article>
            ))}
          </div>
        )}
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
                        <span className="flex shrink-0 items-center gap-1">{l.identityReview === true && <Badge tone="warning" dot={false} className="px-1.5 py-0">Review match</Badge>}{typeof l.returning === "boolean" && <Badge tone={l.returning ? "success" : "info"} dot={false} className="px-1.5 py-0">{l.returning ? "Returning" : "New"}</Badge>}<Badge tone={l.source === "Referral" ? "success" : "info"} dot={false} className="px-1.5 py-0">{l.source}</Badge>{typeof l.score === "number" && <span title={l.scoreWhy?.join("\n")} className={"grid h-5 min-w-5 place-items-center rounded-full px-1 text-[9px] font-bold " + (l.score >= 55 ? "bg-danger text-white" : l.score >= 35 ? "bg-warning-soft text-warning-strong" : "bg-slate-100 text-subtle")}>{l.score}</span>}</span>
                      </div>
                      {l.company && <p className="mt-1 line-clamp-1 text-caption text-subtle">{l.company}</p>}
                      <p className="mt-1 line-clamp-1 text-caption text-muted">{l.interest ?? "—"}</p>
                      {(l.email || l.phone || l.contact) && <p className="mt-1 line-clamp-1 text-caption text-subtle">{[l.email, l.phone].filter(Boolean).join(" · ") || l.contact}</p>}
                      {l.contactConsent === true && <p className="mt-1 text-[10px] font-semibold text-success-strong">Contact permission captured · {l.preferredChannel ?? "method not specified"}</p>}
                      {l.contactConsent === false && <p className="mt-1 text-[10px] font-medium text-subtle">Contact permission not granted</p>}
                      {l.note && <p className="mt-0.5 line-clamp-2 text-caption font-medium text-primary-700">{l.note}</p>}
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

      <Modal
        open={visitOpen}
        onClose={() => setVisitOpen(false)}
        title={visitEditingId ? "Edit site visit" : "Book a site visit"}
        description={visitEditingId ? "Update the appointment and preserve its audit history. Reminders are internal staff tasks only." : "Link a visit to a lead. Any reminder is an internal staff task only; no customer message is sent."}
        footer={
          <>
            <Button variant="secondary" onClick={() => setVisitOpen(false)} disabled={visitSaving}>Cancel</Button>
            <Button onClick={saveVisit} disabled={visitSaving || leads.length === 0}>
              {visitSaving ? "Saving…" : visitEditingId ? "Save changes" : "Book site visit"}
            </Button>
          </>
        }
      >
        <div className="space-y-3.5">
          <Field label="Lead" required>
            <Select value={visitDraft.leadId} disabled={visitEditingId !== null} onChange={(e) => setVisitDraft((d) => ({ ...d, leadId: e.target.value }))}>
              <option value="">Select a lead…</option>
              {leads.map((lead) => <option key={lead.id} value={lead.id}>{lead.ref} · {lead.name}</option>)}
            </Select>
          </Field>
          <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
            <Field label="Visit date and time" required>
              <Input type="datetime-local" value={visitDraft.scheduledAt} onChange={(e) => setVisitDraft((d) => ({ ...d, scheduledAt: e.target.value }))} />
            </Field>
            <Field label="Timezone" required hint="IANA timezone, for example Asia/Karachi.">
              <Input value={visitDraft.timezone} onChange={(e) => setVisitDraft((d) => ({ ...d, timezone: e.target.value }))} placeholder="Asia/Karachi" />
            </Field>
          </div>
          <Field label="Visit address or location" required>
            <Input value={visitDraft.address} onChange={(e) => setVisitDraft((d) => ({ ...d, address: e.target.value }))} placeholder="Area, city, project or meeting location" />
          </Field>
          <div className="grid grid-cols-1 gap-3.5 sm:grid-cols-2">
            <Field label="Assigned owner">
              <Input value={visitDraft.owner} onChange={(e) => setVisitDraft((d) => ({ ...d, owner: e.target.value }))} placeholder="Unassigned" />
            </Field>
            <Field label="Staff reminder time" hint="Optional. Creates a task for staff; it does not contact the customer.">
              <Input type="datetime-local" value={visitDraft.reminderAt} onChange={(e) => setVisitDraft((d) => ({ ...d, reminderAt: e.target.value }))} />
            </Field>
          </div>
          <Field label="Visit notes">
            <Textarea value={visitDraft.notes} onChange={(e) => setVisitDraft((d) => ({ ...d, notes: e.target.value }))} placeholder="Access details, measurements, or preparation notes" />
          </Field>
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

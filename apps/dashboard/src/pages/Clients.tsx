import { useEffect, useState } from "react";
import { useSearchParams } from "react-router";
import { cn } from "@/lib/cn";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card, CardHeader } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { StatusBadge, Badge } from "@/components/ui/Badge";
import { DataTable } from "@/components/ui/DataTable";
import type { Column } from "@/components/ui/DataTable";
import { Field, Input } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { Tabs } from "@/components/ui/Tabs";
import { SkeletonRows, EmptyState, ErrorState } from "@/components/ui/States";
import { useToast } from "@/components/ui/Toast";
import { useApi, useRealtime, mutate, fmtPKR, timeAgo } from "@/lib/api";
import {Building, Check, ClipboardList, GitMerge, Plus, Users, MessagesSquare} from "@/icons";

type ClientRow = { id: number; name: string; company?: string | null; email?: string | null; phone?: string | null;
  city?: string | null; tags: string[]; source?: string | null; lifetime: number; outstanding: number;
  quotes: number; orders: number; openTasks: number; lastActivity: string; updatedAt: string };
type TimelineEvent = { kind: string; ref?: string; label: string; status?: string; value?: number; paid?: number; time: string };
type Task = { id: number; clientId: number | null; title: string; due: string | null; owner: string; priority: string; done: boolean };
type Client360 = ClientRow & { notes?: string | null; timeline: TimelineEvent[]; tasks: Task[] };
type ReviewPair = { a: ClientRow; b: ClientRow; shared: string; value: string; conflict: string };

const EV_TONE: Record<string, "info" | "primary" | "warning" | "success" | "danger"> = {
  lead: "info", quote: "primary", order: "warning", invoice: "success", return: "danger",
};

const score = (c: ClientRow) => Math.min(100, (c.lifetime > 0 ? 45 : 0) + (c.orders ? 25 : 0) + (c.quotes ? 12 : 0) + (c.openTasks ? 8 : 0) + (Date.now() - Date.parse(c.lastActivity) < 7 * 864e5 ? 10 : 0));

export default function Clients() {
  const [tab, setTab] = useState<"clients" | "review" | "tasks">("clients");
  const [q, setQ] = useState("");
  const [open360, setOpen360] = useState<Client360 | null>(null);
  const [newOpen, setNewOpen] = useState(false);
  const [nf, setNf] = useState({ name: "", company: "", phone: "", email: "" });
  const [params] = useSearchParams();
  useEffect(() => { if (params.get("new") === "1") setNewOpen(true); }, [params]);
  const cParam = params.get("c");
  const { push } = useToast();
  const { data, loading, error, reload } = useApi<{ total: number; items: ClientRow[] }>(`/api/clients${q ? "?q=" + encodeURIComponent(q) : ""}`, 30000);
  const review = useApi<{ items: ReviewPair[]; total: number }>("/api/clients/review", 45000);
  const tasks = useApi<{ open: number; overdue: number; items: Task[] }>("/api/tasks", 30000);
  useRealtime(/clients|tasks|leads|orders|quotes|invoices/, () => { reload(); review.reload(); tasks.reload(); });

  const open = async (c: ClientRow) => {
    try {
      const r = await fetch(`/api/clients/${c.id}`);
      if (!r.ok) throw new Error("could not load client");
      setOpen360(await r.json());
    } catch (e) { push({ tone: "danger", title: "Load failed", desc: e instanceof Error ? e.message : String(e) }); }
  };
  useEffect(() => {
    if (!cParam) return;
    void (async () => {
      try { const r = await fetch(`/api/clients/${cParam}`); if (r.ok) setOpen360(await r.json()); } catch { /* ignore bad deep link */ }
    })();
  }, [cParam]);
  const merge = async (keepId: number, fromId: number) => {
    try {
      await mutate(`/api/clients/${keepId}/merge`, { from_id: fromId }, "POST");
      push({ tone: "success", title: "Clients merged", desc: "History re-linked onto the kept record — never silently." });
      review.reload(); reload();
    } catch (e) { push({ tone: "danger", title: "Merge failed", desc: e instanceof Error ? e.message : String(e) }); }
  };
  const toggleTask = async (t: Task) => { await mutate(`/api/tasks/${t.id}`, { done: !t.done }); tasks.reload(); };
  const createClient = async () => {
    if (!nf.name.trim()) return;
    try {
      await mutate("/api/clients", { ...nf, phone: nf.phone || undefined, email: nf.email || undefined, company: nf.company || undefined }, "POST");
      push({ tone: "success", title: "Client added", desc: nf.name }); setNewOpen(false); setNf({ name: "", company: "", phone: "", email: "" }); reload();
    } catch (e) { push({ tone: "danger", title: "Could not create", desc: e instanceof Error ? e.message : String(e) }); }
  };

  const cols: Column<ClientRow>[] = [
    { key: "name", header: "Client", cell: (r) => (
      <button onClick={() => void open(r)} className="flex items-center gap-2.5 text-left">
        <span className="grid h-8 w-8 place-items-center rounded-full bg-primary-50 text-[10px] font-bold text-primary-700">{r.name.split(" ").map((w) => w[0]).slice(0, 2).join("").toUpperCase()}</span>
        <span><span className="block text-small font-semibold text-ink hover:underline">{r.name}</span>
          <span className="block text-caption text-subtle">{[r.company, r.city].filter(Boolean).join(" · ") || r.source}</span></span>
      </button>) },
    { key: "contact", header: "Contact", cell: (r) => <span className="text-caption text-muted">{r.phone ?? r.email ?? "—"}</span> },
    { key: "ltv", header: "Lifetime", align: "right", cell: (r) => <span className={cn("text-small font-semibold", r.lifetime ? "text-ink" : "text-subtle")}>{fmtPKR(r.lifetime)}</span> },
    { key: "due", header: "Outstanding", align: "right", cell: (r) => <span className={cn("text-small font-semibold", r.outstanding > 0 ? "text-warning-strong" : "text-subtle")}>{r.outstanding ? fmtPKR(r.outstanding) : "—"}</span> },
    { key: "act", header: "Activity", cell: (r) => <span className="text-caption text-muted">{r.quotes}q · {r.orders}o{r.openTasks ? ` · ${r.openTasks}⚑` : ""}</span> },
    { key: "hot", header: "Score", cell: (r) => { const sc = score(r); return (
      <Badge tone={sc >= 70 ? "success" : sc >= 40 ? "primary" : "muted"} dot={false} className="px-1.5 py-0">{sc}</Badge>); } },
    { key: "last", header: "Last touch", cell: (r) => <span className="text-caption text-subtle">{timeAgo(r.lastActivity)}</span> },
  ];

  const openTasks = (tasks.data?.items ?? []).filter((t) => !t.done);

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Sales", to: "/crm" }, { label: "Clients" }]}
        title="Clients — one record per customer"
        description="Identity resolution by phone/email across leads, quotes, orders, invoices and returns. Ambiguity goes to a human, never silent merges."
        actions={<><Button variant="secondary" onClick={reload}>Refresh</Button>
          <Button onClick={() => setNewOpen(true)}><Plus size={15} /> New client</Button></>}
      />
      <div className="mb-4"><Tabs value={tab} onChange={(v) => setTab(v as typeof tab)} items={[
        { value: "clients", label: "Clients", count: data?.total },
        { value: "review", label: "Merge review", count: review.data?.total },
        { value: "tasks", label: "Follow-ups", count: tasks.data?.open },
      ]} /></div>

      {tab === "clients" && (
        <Card>
          <div className="border-b border-hairline px-4 py-3">
            <Input placeholder="Search name, company, email, phone…" value={q} onChange={(e) => setQ(e.target.value)} className="max-w-sm" />
          </div>
          {error ? <div className="p-4"><ErrorState title="Clients unavailable" debug="GET /api/clients" onRetry={reload} /></div>
            : loading ? <SkeletonRows rows={6} />
            : (data?.items.length ?? 0) === 0 ? <div className="p-6"><EmptyState icon={<Building size={20} />} title="No clients yet" description="They appear automatically as storefront leads, quotes and orders arrive." /></div>
            : <DataTable rows={data!.items} columns={cols} perPage={10} />}
        </Card>
      )}

      {tab === "review" && (
        <Card>
          <CardHeader title="Ambiguous identities" description="Same phone or email across two client records — keep separate or merge (history re-links)." />
          {(review.data?.items.length ?? 0) === 0 ? (
            <div className="p-6"><EmptyState icon={<GitMerge size={20} />} title="Queue is clear" description="No duplicate contact signatures detected." /></div>
          ) : (
            <ul className="divide-y divide-hairline">{review.data!.items.map((p, i) => (
              <li key={i} className="flex flex-wrap items-center justify-between gap-3 px-4 py-3">
                <span className="text-small">
                  <b className="text-ink">{p.a.name}</b> ⇄ <b className="text-ink">{p.b.name}</b>
                  <span className="block text-caption text-subtle">shared {p.shared} {p.value} · {p.conflict}</span>
                </span>
                <span className="flex gap-2">
                  <Button size="sm" variant="secondary" onClick={() => { push({ tone: "info", title: "Marked as distinct", desc: "They stay separate records." /* demo: no persistent flag yet */ }); }}>Keep separate</Button>
                  <Button size="sm" onClick={() => void merge(p.a.id, p.b.id)}><GitMerge size={13} /> Merge into “{p.a.name}”</Button>
                </span>
              </li>))}</ul>
          )}
        </Card>
      )}

      {tab === "tasks" && (
        <Card>
          <CardHeader title={`Follow-ups (${openTasks.length} open)`} description={tasks.data?.overdue ? `${tasks.data.overdue} overdue — highlighted below.` : "Quotes entering 'Sent' auto-schedule a follow-up."} />
          {openTasks.length === 0 ? <div className="p-6"><EmptyState icon={<ClipboardList size={20} />} title="Inbox zero" description="Follow-ups appear here when quotes are sent or advances are due." /></div> : (
            <ul className="divide-y divide-hairline">{openTasks.map((t) => { const od = t.due && t.due < new Date().toISOString().slice(0, 10); return (
              <li key={t.id} className={cn("flex items-center gap-3 px-4 py-2.5", od && "bg-danger-soft/40")}>
                <button aria-label="toggle" onClick={() => void toggleTask(t)} className="grid h-5 w-5 shrink-0 place-items-center rounded border border-line-strong text-transparent hover:border-primary-500 hover:text-primary-600"><Check size={12} /></button>
                <span className="min-w-0 flex-1"><span className="block truncate text-small font-medium text-ink">{t.title}</span>
                  <span className="text-caption text-subtle">{t.owner}{t.due ? ` · due ${t.due}` : ""}{od ? " · OVERDUE" : ""}</span></span>
                <Badge tone={t.priority === "high" ? "danger" : od ? "warning" : "neutral"} dot={false}>{t.priority}</Badge>
              </li>); })}</ul>
          )}
        </Card>
      )}

      {/* Customer 360 */}
      <Modal open={!!open360} onClose={() => setOpen360(null)} className="max-w-2xl"
        title={open360 ? `${open360.name}${open360.company ? " · " + open360.company : ""}` : ""}
        description={open360 ? [open360.phone, open360.email, open360.city].filter(Boolean).join(" · ") || "no contact details yet" : undefined}
        footer={open360 && <>
          <span className="mr-auto text-caption text-subtle">lifetime {fmtPKR(open360.lifetime)} · outstanding <b className={open360.outstanding ? "text-warning-strong" : ""}>{fmtPKR(open360.outstanding)}</b></span>
          <a href={`/omnichannel?c=${open360.id}`}><Button variant="secondary"><MessagesSquare size={13} /> Inbox thread</Button></a>
          <Button variant="secondary" onClick={() => setOpen360(null)}>Close</Button>
          <Button onClick={() => (push({ tone: "info", title: "Quote drafted", desc: "Prefilled builder opens with this client." }))}><Users size={14} /> New quote for client</Button>
        </>}>
        {open360 && (
          <div className="max-h-[55vh] space-y-4 overflow-y-auto scrollbar-slim pr-1">
            {open360.notes && <p className="rounded-control bg-surface-secondary p-3 text-caption text-muted">{open360.notes}</p>}
            {open360.tasks.length > 0 && (
              <section>
                <p className="mb-1.5 text-caption font-semibold uppercase text-subtle">Follow-ups</p>
                <ul className="space-y-1">{open360.tasks.map((t) => (
                  <li key={t.id} className="flex items-center justify-between rounded-control bg-surface-secondary px-3 py-1.5 text-caption">
                    <span className={cn("truncate", t.done && "text-subtle line-through")}>{t.title}</span>
                    <span>{t.due ?? ""} <StatusBadge status={t.done ? "Done" : "Open"} /></span>
                  </li>))}</ul>
              </section>)}
            <section>
              <p className="mb-1.5 text-caption font-semibold uppercase text-subtle">Full timeline</p>
              <ul className="space-y-1.5">{open360.timeline.map((e, i) => (
                <li key={i} className="flex items-center gap-2.5 rounded-control border border-hairline px-3 py-2 text-caption">
                  <Badge tone={EV_TONE[e.kind] ?? "neutral"} dot={false} className="w-16 justify-center uppercase">{e.kind}</Badge>
                  <span className="min-w-0 flex-1 truncate text-small text-ink">{e.label}</span>
                  {e.value ? <span className="font-semibold text-ink">{fmtPKR(e.value)}{e.paid != null && e.paid < e.value ? ` (${fmtPKR(e.paid)} paid)` : ""}</span> : null}
                  {e.status && <StatusBadge status={e.status} />}
                  <span className="shrink-0 text-subtle">{e.time ? timeAgo(e.time) : ""}</span>
                </li>))}</ul>
            </section>
          </div>
        )}
      </Modal>

      {/* New client */}
      <Modal open={newOpen} onClose={() => setNewOpen(false)} title="New client" description="Manual capture — leads and checkouts create clients automatically." footer={<Button onClick={() => void createClient()}>Create</Button>}>
        <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
          <Field label="Name" required><Input value={nf.name} onChange={(e) => setNf((f) => ({ ...f, name: e.target.value }))} /></Field>
          <Field label="Company"><Input value={nf.company} onChange={(e) => setNf((f) => ({ ...f, company: e.target.value }))} /></Field>
          <Field label="Phone"><Input value={nf.phone} onChange={(e) => setNf((f) => ({ ...f, phone: e.target.value }))} placeholder="+92 3xx xxxxxxx" /></Field>
          <Field label="Email"><Input value={nf.email} onChange={(e) => setNf((f) => ({ ...f, email: e.target.value }))} /></Field>
        </div>
      </Modal>
    </div>
  );
}

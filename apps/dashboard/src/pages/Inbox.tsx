import { useEffect, useMemo, useRef, useState } from "react";
import { Link, useSearchParams } from "react-router";
import { cn } from "@/lib/cn";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge, StatusBadge } from "@/components/ui/Badge";
import { Input } from "@/components/ui/Field";
import { SkeletonRows, EmptyState } from "@/components/ui/States";
import { useToast } from "@/components/ui/Toast";
import { useApi, useRealtime, timeAgo } from "@/lib/api";
import { ArrowRight, Eye, MessagesSquare, Phone, Search, Send, User } from "@/icons";

type LastMsg = { body: string; channel: string; direction: string; author: string } | null;
type Conv = { id: number; clientId: number; clientName: string; company: string | null; phone: string | null; city: string | null;
  status: string; assignee: string | null; priority: number; unread: number; lastAt: string | null; lastMessage: LastMsg; totalMessages: number;
  lead: { ref: string; status: string; interest: string } | null; wa: string | null };
type Msg = { id: number; channel: string; direction: string; author: string; body: string; createdAt: string; meta: { handoff?: string } | null };
type Thread = { conversation: { id: number; status: string; assignee: string | null; priority: number; unread: number };
  client: { name: string; company?: string | null; phone?: string | null; email?: string | null; city?: string | null; source?: string | null };
  messages: Msg[]; context: { orders: { ref: string; status: string; total: number }[]; quotes: { ref: string; status: string; total: number }[]; invoices: { ref: string; status: string; due: string | null }[] }; wa: string | null };
type Tpl = { id: string; label: string; body: string };
const CHANNELS = ["whatsapp", "email", "phone", "note"] as const;
const CH_ICON: Record<string, string> = { whatsapp: "💬", email: "✉️", phone: "📞", note: "📝", system: "⚙️" };
const TEAM = ["Usman", "Ayesha", "Bilal", "Ali"];
const pkr = (n: number) => "Rs " + Math.round(n).toLocaleString("en-PK");
const dayOf = (iso: string) => new Date(iso).toDateString();

export default function Inbox() {
  const { push } = useToast();
  const [params, setParams] = useSearchParams();
  const [box, setBox] = useState<"all" | "unread" | "mine">("all");
  const [qText, setQText] = useState("");
  const [q, setQ] = useState("");
  const [channel, setChannel] = useState<string>("");
  const sel = Number(params.get("c") ?? "") || null;
  const setSel = (id: number | null) => setParams(id ? { c: String(id) } : {}, { replace: true });

  const listPath = useMemo(() => {
    const p = new URLSearchParams();
    if (box !== "all") p.set("box", box);
    if (q) p.set("q", q);
    if (channel) p.set("channel", channel);
    const qs = p.toString();
    return "/api/inbox" + (qs ? "?" + qs : "");
  }, [box, q, channel]);
  const list = useApi<{ counts: { all: number; unread: number; open: number }; items: Conv[] }>(listPath, 25000);
  const thread = useApi<Thread>(sel ? `/api/inbox/${sel}/thread` : null);
  const templates = useApi<{ items: Tpl[] }>("/api/inbox/templates");
  useRealtime(/inbox|leads/, () => { list.reload(); if (sel) thread.reload(); });

  const [draft, setDraft] = useState("");
  const [ch, setCh] = useState<(typeof CHANNELS)[number]>("whatsapp");
  const [handoffUrl, setHandoffUrl] = useState<string | null>(null);
  const endRef = useRef<HTMLDivElement>(null);
  useEffect(() => { endRef.current?.scrollIntoView({ block: "end" }); }, [thread.data?.messages.length]);
  useEffect(() => { setHandoffUrl(null); }, [sel]);

  const items = list.data?.items ?? [];
  const conv = items.find((x) => x.clientId === sel) ?? null;
  const th = thread.data;
  const send = async (inbound = false) => {
    if (!sel || !draft.trim()) return;
    await fetch(`/api/inbox/${sel}/messages`, { method: "POST", headers: { "content-type": "application/json" },
      body: JSON.stringify({ body: draft.trim(), channel: ch, direction: inbound ? "inbound" : "outbound", author: inbound ? conv?.clientName ?? "Client" : "You" }) });
    setDraft(""); thread.reload(); list.reload();
  };
  const openHandoff = async () => {
    if (!sel || !draft.trim()) return push({ tone: "warning", title: "Nothing to hand off", desc: "Write the message first." });
    const res = await fetch(`/api/inbox/${sel}/handoff`, { method: "POST", headers: { "content-type": "application/json" },
      body: JSON.stringify({ body: draft.trim(), author: "You" }) });
    const j = await res.json();
    if (!res.ok) return push({ tone: "danger", title: "Handoff blocked", desc: j.error });
    setHandoffUrl(j.url); thread.reload(); list.reload();
    window.open(j.url, "_blank", "noopener");
  };
  const patchConv = async (patch: Record<string, unknown>) => {
    if (!conv) return;
    await fetch(`/api/inbox/conversations/${conv.id}`, { method: "PATCH", headers: { "content-type": "application/json" }, body: JSON.stringify(patch) });
    list.reload(); thread.reload();
  };
  const insertTpl = (t: Tpl) => {
    const c = thread.data;
    const name = c?.client.name ?? "there";
    const ref = c?.context.quotes[0]?.ref ?? conv?.lead?.ref ?? "your inquiry";
    setDraft((d) => (d ? d + "\n" : "") + t.body.replaceAll("{{name}}", name).replaceAll("{{ref}}", ref));
  };
  const grouped = useMemo(() => {
    const out: { day: string; msgs: Msg[] }[] = [];
    for (const m of thread.data?.messages ?? []) {
      const d = dayOf(m.createdAt);
      if (out.at(-1)?.day !== d) out.push({ day: d, msgs: [m] }); else out.at(-1)!.msgs.push(m);
    }
    return out;
  }, [thread.data]);

  return (
    <div>
      <PageHeader
        crumbs={[{ label: "Digital" }, { label: "Omnichannel" }]}
        title="Shared inbox"
        description="WhatsApp / call / email handoffs and internal notes threaded per client — leads open conversations automatically. Never deletes, only logs."
        actions={<Badge tone="primary" dot>{list.data?.counts.unread ?? 0} unread · {list.data?.counts.open ?? 0} open</Badge>}
      />
      <div className="mb-3 flex flex-wrap items-center gap-2">
        {(["all", "unread", "mine"] as const).map((b) => (
          <button key={b} onClick={() => setBox(b)}
            className={cn("rounded-control px-3 py-1.5 text-caption font-bold capitalize transition-colors", box === b ? "bg-ink text-white" : "bg-surface-secondary text-muted hover:text-ink")}>
            {b === "mine" ? "assigned to me" : b}{b === "unread" && list.data?.counts.unread ? ` (${list.data.counts.unread})` : ""}
          </button>))}
        <span className="mx-1 h-5 w-px bg-hairline" />
        <form onSubmit={(e) => { e.preventDefault(); setQ(qText.trim()); }} className="relative flex-1 min-w-44 max-w-72">
          <Search size={13} className="pointer-events-none absolute left-2.5 top-1/2 -translate-y-1/2 text-subtle" />
          <Input value={qText} onChange={(e) => setQText(e.target.value)} placeholder="Search name, phone, message…" className="pl-8" />
        </form>
        <select value={channel} onChange={(e) => setChannel(e.target.value)} className="rounded-control border border-line-strong bg-white px-2 py-1.5 text-caption text-ink">
          <option value="">all channels</option>
          {["whatsapp", "email", "phone", "note"].map((c) => <option key={c} value={c}>{c}</option>)}
        </select>
        {q && <button onClick={() => { setQ(""); setQText(""); }} className="text-caption font-semibold text-primary-600 hover:underline">clear</button>}
      </div>

      <div className="grid grid-cols-1 gap-4 lg:grid-cols-[minmax(0,340px)_minmax(0,1fr)] xl:grid-cols-[minmax(0,320px)_minmax(0,1fr)_280px]">
        {/* list */}
        <Card className="p-0 overflow-hidden">
          <div className="max-h-[68vh] divide-y divide-hairline overflow-y-auto scrollbar-slim">
            {list.loading ? <SkeletonRows rows={5} /> : items.length === 0 ? (
              <div className="p-5"><EmptyState icon={<MessagesSquare size={18} />} title="Inbox zero" description="New leads from the site, landing forms and manual adds all open a thread here." /></div>
            ) : items.map((c) => (
              <button key={c.id} onClick={() => setSel(c.clientId)}
                className={cn("flex w-full items-start gap-2.5 px-3 py-2.5 text-left transition-colors hover:bg-surface-secondary/60", sel === c.clientId && "bg-primary-50/70")}>
                <span className={cn("mt-0.5 grid h-8 w-8 shrink-0 place-items-center rounded-full text-caption font-black", c.unread ? "bg-primary-600 text-white" : "bg-surface-secondary text-muted")}>
                  {c.clientName.split(" ").map((w) => w[0]).slice(0, 2).join("")}
                </span>
                <span className="min-w-0 flex-1">
                  <span className="flex items-baseline justify-between gap-2">
                    <b className={cn("truncate text-small", c.unread ? "text-ink" : "text-muted")}>{c.clientName}</b>
                    <span className="shrink-0 text-[10px] text-subtle">{c.lastAt ? timeAgo(c.lastAt) : "—"}</span>
                  </span>
                  <span className="mt-0.5 flex items-center gap-1.5">
                    {c.priority === 1 && <span className="h-1.5 w-1.5 shrink-0 rounded-full bg-danger" />}
                    {c.unread > 0 && <span className="grid h-4 min-w-4 place-items-center rounded-full bg-primary-600 px-1 text-[9px] font-bold text-white">{c.unread}</span>}
                    <span className="truncate text-caption text-muted">{c.lastMessage ? CH_ICON[c.lastMessage.channel] + " " + c.lastMessage.body : "(no messages)"}</span>
                  </span>
                  <span className="mt-1 flex items-center gap-1.5">
                    <StatusBadge status={c.status} />
                    {c.lead && <Badge tone={c.lead.status === "New" ? "warning" : "neutral"} dot={false}>lead {c.lead.status}</Badge>}
                    {c.assignee && <span className="text-[10px] text-subtle">@{c.assignee}</span>}
                  </span>
                </span>
              </button>))}
          </div>
        </Card>

        {/* thread */}
        <Card className={cn("flex max-h-[68vh] flex-col p-0 overflow-hidden", !sel && "hidden xl:flex")}>
          {!sel ? <div className="grid flex-1 place-items-center p-8 text-center">
            <div><MessagesSquare size={26} className="mx-auto text-subtle" /><p className="mt-2 text-caption font-semibold text-muted">Pick a conversation</p>
              <p className="text-[11px] text-subtle">Or open one from a lead / Customer-360 card.</p></div></div>
          : thread.loading || !th ? <SkeletonRows rows={6} /> : (
            <>
              <div className="flex items-center justify-between gap-2 border-b border-hairline px-4 py-2.5">
                <div className="min-w-0">
                  <p className="truncate text-small font-bold text-ink">{th.client.name} <span className="font-normal text-subtle">· {th.client.city ?? "—"}</span></p>
                  <p className="text-[10px] text-subtle">{[th.client.phone, th.client.email].filter(Boolean).join(" · ") || "no direct contact on file"} · {th.messages.length} messages</p>
                </div>
                <div className="flex shrink-0 items-center gap-1.5">
                  {th.wa && <a href={"https://wa.me/" + th.wa} target="_blank" rel="noreferrer"><Button size="sm" variant="secondary">WhatsApp</Button></a>}
                  {th.client.phone && <a href={"tel:" + th.client.phone.replace(/\s/g, "")}><Button size="sm" variant="secondary"><Phone size={12} /></Button></a>}
                  <Link to={`/clients?c=${sel}`}><Button size="sm" variant="secondary"><Eye size={12} /> 360</Button></Link>
                </div>
              </div>
              <div className="flex-1 space-y-3 overflow-y-auto bg-surface-secondary/30 px-4 py-3 scrollbar-slim">
                {th.messages.length === 0 && <p className="py-8 text-center text-caption text-subtle">Empty thread — start with a quick reply ↓</p>}
                {grouped.map((g) => (
                  <div key={g.day}>
                    <p className="my-1.5 text-center text-[9px] font-bold uppercase tracking-widest text-subtle">{new Date(g.day).toDateString() === dayOf(new Date().toISOString()) ? "Today" : new Date(g.day).toLocaleDateString("en-PK", { day: "numeric", month: "short" })}</p>
                    <div className="space-y-2">
                      {g.msgs.map((m) => m.direction === "system" ? (
                        <p key={m.id} className="mx-auto w-fit rounded-full bg-surface-secondary px-3 py-1 text-center text-[10px] text-subtle">⚙️ {m.body}<span className="ml-1.5 opacity-60">{new Date(m.createdAt).toLocaleTimeString("en-PK", { hour: "numeric", minute: "2-digit" })}</span></p>
                      ) : (
                        <div key={m.id} className={cn("flex", m.direction === "outbound" ? "justify-end" : "justify-start")}>
                          <div className={cn("max-w-[78%] rounded-card px-3 py-2 text-caption", m.direction === "outbound" ? "rounded-br-sm bg-primary-600 text-white" : "rounded-bl-sm border border-hairline bg-white text-ink")}>
                            <p className={cn("mb-0.5 text-[9px] font-bold uppercase tracking-wide", m.direction === "outbound" ? "text-white/70" : "text-subtle")}>
                              {CH_ICON[m.channel]} {m.author}{m.meta?.handoff && <a href={m.meta.handoff} target="_blank" rel="noreferrer" className="ml-1.5 font-semibold underline">↗ opened</a>}
                            </p>
                            <p className="whitespace-pre-wrap leading-snug">{m.body}</p>
                            <p className={cn("mt-0.5 text-right text-[9px]", m.direction === "outbound" ? "text-white/60" : "text-subtle")}>{new Date(m.createdAt).toLocaleTimeString("en-PK", { hour: "numeric", minute: "2-digit" })}</p>
                          </div>
                        </div>))}
                    </div>
                  </div>))}
                <div ref={endRef} />
              </div>
              {handoffUrl && (
                <p className="flex items-center gap-2 border-t border-primary-200 bg-primary-50 px-4 py-1.5 text-[11px] text-primary-900">
                  Logged to thread. <a className="font-bold underline" href={handoffUrl} target="_blank" rel="noreferrer">Open WhatsApp again ↗</a>
                  <button className="ml-auto text-subtle hover:text-ink" onClick={() => setHandoffUrl(null)}>✕</button>
                </p>)}
              <div className="border-t border-hairline p-3">
                {templates.data && (
                  <div className="mb-2 flex flex-wrap gap-1.5">
                    {templates.data.items.map((t) => (
                      <button key={t.id} onClick={() => insertTpl(t)} className="rounded-full border border-hairline bg-surface-secondary px-2.5 py-1 text-[10px] font-semibold text-muted transition-colors hover:border-primary-400 hover:text-primary-800">＋ {t.label}</button>))}
                  </div>)}
                <div className="flex items-end gap-2">
                  <select value={ch} onChange={(e) => setCh(e.target.value as typeof ch)} className="rounded-control border border-line-strong bg-white px-2 py-2 text-caption">
                    {CHANNELS.map((c) => <option key={c} value={c}>{CH_ICON[c]} {c}</option>)}
                  </select>
                  <textarea value={draft} onChange={(e) => setDraft(e.target.value)} rows={2} placeholder="Reply — Enter sends, Shift+Enter new line…"
                    onKeyDown={(e) => { if (e.key === "Enter" && !e.shiftKey) { e.preventDefault(); void send(); } }}
                    className="min-h-0 flex-1 resize-none rounded-control border border-line-strong bg-white px-3 py-2 text-caption focus:border-primary-500 focus:outline-none focus:ring-2 focus:ring-primary-500/20" />
                  <div className="flex flex-col gap-1">
                    <Button size="sm" onClick={() => void send()}><Send size={12} /> Send</Button>
                    {th.wa && conv?.wa && <Button size="sm" variant="secondary" onClick={() => void openHandoff()}>WA →</Button>}
                  </div>
                </div>
                <button onClick={() => void send(true)} className="mt-1.5 text-[10px] font-semibold text-subtle hover:text-ink">↳ log as inbound (“client just told us this”)</button>
              </div>
            </>
          )}
        </Card>

        {/* context rail */}
        <div className="hidden space-y-4 xl:block">
          {th ? (<>
            <Card className="p-4">
              <p className="mb-2 text-caption font-bold uppercase tracking-wide text-subtle">Disposition</p>
              <div className="flex flex-wrap gap-1.5">
                {["open", "pending", "resolved", "snoozed"].map((st) => (
                  <button key={st} onClick={() => void patchConv({ status: st })}
                    className={cn("rounded-full border px-2.5 py-1 text-[10px] font-bold capitalize", th.conversation.status === st ? "border-primary-500 bg-primary-50 text-primary-900" : "border-hairline text-subtle hover:bg-surface-secondary")}>{st}</button>))}
              </div>
              <div className="mt-3">
                <p className="mb-1 text-[10px] font-bold uppercase tracking-wide text-subtle">Assignee</p>
                <div className="flex flex-wrap gap-1.5">
                  <button onClick={() => void patchConv({ assignee: null })} className={cn("rounded-full border px-2 py-0.5 text-[10px]", !th.conversation.assignee ? "border-ink bg-ink text-white" : "border-hairline text-subtle")}>unassigned</button>
                  {TEAM.map((t) => (
                    <button key={t} onClick={() => void patchConv({ assignee: t })} className={cn("inline-flex items-center gap-1 rounded-full border px-2 py-0.5 text-[10px] font-semibold", th.conversation.assignee === t ? "border-primary-500 bg-primary-50 text-primary-900" : "border-hairline text-subtle hover:bg-surface-secondary")}><User size={9} />{t}</button>))}
                </div>
              </div>
              <button onClick={() => void patchConv({ priority: th.conversation.priority ? 0 : 1 })}
                className={cn("mt-3 flex w-full items-center gap-2 rounded-control border px-3 py-1.5 text-caption font-semibold", th.conversation.priority ? "border-danger/40 bg-danger-soft text-danger-strong" : "border-hairline text-subtle hover:bg-surface-secondary")}>
                <span className={cn("h-2 w-2 rounded-full", th.conversation.priority ? "bg-danger" : "bg-slate-300")} /> Hot lead flag {th.conversation.priority ? "on" : "off"}
              </button>
            </Card>
            <Card className="p-4">
              <p className="mb-2 text-caption font-bold uppercase tracking-wide text-subtle">Money context</p>
              {th.context.quotes.length === 0 && th.context.orders.length === 0 && th.context.invoices.length === 0
                ? <p className="text-caption text-subtle">No quotes or orders yet — the lead card in CRM shows source &amp; score.</p> : (
                <ul className="space-y-1.5 text-caption">
                  {th.context.quotes.map((x) => <li key={"q" + x.ref} className="flex items-center justify-between gap-2"><span className="font-semibold text-ink">{x.ref}</span><span className="text-subtle">quote · {x.status}</span><b>{pkr(x.total)}</b></li>)}
                  {th.context.orders.map((x) => <li key={"o" + x.ref} className="flex items-center justify-between gap-2"><span className="font-semibold text-ink">{x.ref}</span><span className="text-subtle">order · {x.status}</span><b>{pkr(x.total)}</b></li>)}
                  {th.context.invoices.map((x) => <li key={"i" + x.ref} className="flex items-center justify-between gap-2"><span className="font-semibold text-ink">{x.ref}</span><span className="text-subtle">invoice · {x.status}</span><b>{x.due ? pkr(0) + " due" : "cleared"}</b></li>)}
                </ul>)}
              <Link to="/clients" className="mt-2.5 inline-flex items-center gap-1 text-caption font-bold text-primary-600 hover:underline">Open Clients <ArrowRight size={11} /></Link>
            </Card>
            {conv?.lead && (
              <Card className="p-4">
                <p className="mb-1 text-caption font-bold uppercase tracking-wide text-subtle">Latest lead</p>
                <p className="text-small font-bold text-ink">{conv.lead.ref} · <StatusBadge status={conv.lead.status} /></p>
                <p className="mt-1 text-caption text-muted">“{conv.lead.interest}”</p>
                <Link to="/crm" className="mt-2 inline-flex items-center gap-1 text-caption font-bold text-primary-600 hover:underline">Handle in CRM <ArrowRight size={11} /></Link>
              </Card>)}
          </>) : (
            <Card className="p-4 text-caption text-subtle">Select a conversation to see disposition controls, money context and the active lead here.</Card>
          )}
        </div>
      </div>
    </div>
  );
}

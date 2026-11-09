import { useMemo, useState } from "react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui/States";
import { Field, Input, Select } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { useToast } from "@/components/ui/Toast";
import { useApi, mutate } from "@/lib/api";
import { Plus, RefreshCw, Wrench, Clock, AlertTriangle, Lock } from "@/icons";

/* Support — what customers reported, what we did, and what we decided.
 *
 * A ticket is the customer's claim. A visit is our response. A claim is the
 * answer about warranty coverage, recorded with the date it was decided.
 *
 * Three things this screen refuses to blur:
 *  - The SLA is arithmetic. Deadlines were set from the priority at intake and
 *    are frozen; "late" is a comparison against the clock, not a colour.
 *  - An internal note is not a response. The first-response time only moves
 *    when something goes to the customer.
 *  - A closed ticket is not silently reopened by writing to it.
 */

type Ticket = {
  id: number; ref: string; customer: string; subject: string; description: string | null;
  assetId: number | null; assetTag: string | null; assetName: string | null;
  category: string; channel: string; priority: string; status: string; waitingOn: string;
  owner: string | null; firstResponseAt: string | null; respondBy: string | null;
  resolveBy: string | null; resolvedAt: string | null; resolution: string | null;
  createdAt: string; responseBreached: boolean; resolutionBreached: boolean; ageHours: number;
};
type Message = { id: number; author: string | null; body: string; internal: boolean; createdAt: string };
type Visit = {
  id: number; ref: string; ticketId: number; scheduledFor: string | null; technician: string | null;
  status: string; arrivedAt: string | null; completedAt: string | null; workDone: string | null;
  chargeable: boolean; chargeAmount: number | null;
};
type Claim = {
  id: number; ref: string; customer: string; fault: string; part: string | null;
  claimedOn: string; warrantyUntilOnClaim: string | null; verdict: string;
  decidedOn: string | null; reason: string | null; costToWooodex: number | null;
};
type Detail = { ticket: Ticket; messages: Message[]; visits: Visit[]; claims: Claim[] };
type Asset = { id: number; tag: string; name: string; warrantyUntil: string | null };

const PRIORITY_TONE: Record<string, "neutral" | "info" | "warning" | "danger"> = {
  low: "neutral", normal: "info", high: "warning", urgent: "danger",
};
const STATUS_TONE: Record<string, "info" | "warning" | "success" | "neutral"> = {
  open: "info", in_progress: "info", waiting: "warning", resolved: "success", closed: "neutral",
};
const VERDICT_TONE: Record<string, "success" | "danger" | "warning" | "neutral"> = {
  covered: "success", not_covered: "danger", goodwill: "warning", pending: "neutral",
};

const dt = (v: string | null) => (v ? new Date(v).toLocaleString(undefined, {
  day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" }) : "—");

const FILTERS = [
  { id: "open", label: "Open" },
  { id: "", label: "All" },
  { id: "resolved", label: "Resolved" },
  { id: "closed", label: "Closed" },
];

export default function Support() {
  const { push } = useToast();
  const [filter, setFilter] = useState("open");
  const [selected, setSelected] = useState<number | null>(null);
  const [newOpen, setNewOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [reply, setReply] = useState("");
  const [internal, setInternal] = useState(false);
  const [resolveFor, setResolveFor] = useState<Ticket | null>(null);
  const [resolution, setResolution] = useState("");
  const [visitFor, setVisitFor] = useState<Ticket | null>(null);
  const [visit, setVisit] = useState({ technician: "", scheduledFor: "" });

  const [form, setForm] = useState({
    customer: "", subject: "", description: "", priority: "normal",
    category: "other", channel: "phone", assetId: "",
  });

  const query = filter === "open" ? "/api/tickets?open=1" : filter ? `/api/tickets?status=${filter}` : "/api/tickets";
  const list = useApi<{ total: number; open: number; breached: number; items: Ticket[] }>(query, 20000);
  const detail = useApi<Detail>(selected ? `/api/tickets/${selected}` : null, 15000);
  const assets = useApi<{ items: Asset[] }>("/api/installed-assets", 120000);
  const summary = useApi<{
    openTickets: number; responseBreached: number; resolutionBreached: number;
    waitingOnCustomer: number; unclaimedDecisions: number; coveredCost: number;
    assetsOutOfWarranty: number;
  }>("/api/support/summary", 30000);

  const items = list.data?.items ?? [];
  const d = detail.data;

  const reload = () => { list.reload(); summary.reload(); if (selected) detail.reload(); };

  /* Breached first, then by priority — the order a person should work in,
     rather than the order the rows happen to sit in. */
  const ordered = useMemo(() => {
    const rank: Record<string, number> = { urgent: 0, high: 1, normal: 2, low: 3 };
    return [...items].sort((a, b) => {
      const ab = a.responseBreached || a.resolutionBreached ? 0 : 1;
      const bb = b.responseBreached || b.resolutionBreached ? 0 : 1;
      if (ab !== bb) return ab - bb;
      if (rank[a.priority] !== rank[b.priority]) return rank[a.priority] - rank[b.priority];
      return b.createdAt.localeCompare(a.createdAt);
    });
  }, [items]);

  const openTicket = async () => {
    if (saving) return;
    setErr(null);
    if (!form.customer.trim()) { setErr("Who is reporting this?"); return; }
    if (!form.subject.trim()) { setErr("Say what is wrong — a ticket with no subject cannot be worked."); return; }
    setSaving(true);
    try {
      const out = await mutate<{ ticket: Ticket }>("/api/tickets", {
        customer: form.customer.trim(), subject: form.subject.trim(),
        description: form.description || null, priority: form.priority,
        category: form.category, channel: form.channel,
        assetId: form.assetId ? Number(form.assetId) : null,
      }, "POST");
      push({ tone: "success", title: `Ticket ${out.ticket.ref} opened`,
        desc: `Resolve by ${dt(out.ticket.resolveBy)}` });
      setNewOpen(false);
      setForm({ customer: "", subject: "", description: "", priority: "normal", category: "other", channel: "phone", assetId: "" });
      setSelected(out.ticket.id);
      reload();
    } catch (e) {
      setErr(e instanceof Error ? e.message : "Could not open the ticket.");
    } finally { setSaving(false); }
  };

  const send = async () => {
    if (!d || saving) return;
    setErr(null);
    if (!reply.trim()) { setErr("Nothing to send."); return; }
    setSaving(true);
    try {
      await mutate(`/api/tickets/${d.ticket.id}/messages`, { body: reply.trim(), internal }, "POST");
      push({ tone: "success", title: internal ? "Note saved" : "Reply sent",
        desc: internal ? "Internal — the customer has not been told" : undefined });
      setReply("");
      setInternal(false);
      reload();
    } catch (e) {
      setErr(e instanceof Error ? e.message : "Could not save.");
    } finally { setSaving(false); }
  };

  const setStatus = async (t: Ticket, status: string) => {
    if (status === "resolved") { setErr(null); setResolution(""); setResolveFor(t); return; }
    try {
      await mutate(`/api/tickets/${t.id}`, { status });
      push({ tone: "info", title: `Ticket ${status}`, desc: t.ref });
      reload();
    } catch (e) {
      push({ tone: "danger", title: "Could not update", desc: e instanceof Error ? e.message : String(e) });
    }
  };

  const doResolve = async () => {
    if (!resolveFor || saving) return;
    if (!resolution.trim()) {
      setErr("What fixed it? A resolved ticket with no resolution tells the next person nothing.");
      return;
    }
    setSaving(true);
    setErr(null);
    try {
      await mutate(`/api/tickets/${resolveFor.id}`, { status: "resolved", resolution: resolution.trim() });
      push({ tone: "success", title: "Ticket resolved" });
      setResolveFor(null);
      setResolution("");
      reload();
    } catch (e) {
      setErr(e instanceof Error ? e.message : "Could not resolve.");
    } finally { setSaving(false); }
  };

  const bookVisit = async () => {
    if (!visitFor || saving) return;
    setErr(null);
    setSaving(true);
    try {
      await mutate("/api/service-visits", {
        ticketId: visitFor.id, technician: visit.technician || null,
        scheduledFor: visit.scheduledFor ? new Date(visit.scheduledFor).toISOString() : null,
      }, "POST");
      push({ tone: "success", title: "Visit scheduled" });
      setVisitFor(null);
      setVisit({ technician: "", scheduledFor: "" });
      reload();
    } catch (e) {
      setErr(e instanceof Error ? e.message : "Could not schedule the visit.");
    } finally { setSaving(false); }
  };

  const completeVisit = async (v: Visit) => {
    const work = window.prompt("What was actually done?");
    if (work === null) return;
    if (!work.trim()) {
      push({ tone: "warning", title: "A completed visit says what was done",
        desc: "Otherwise it is the same as no visit at all, except that it closes the ticket." });
      return;
    }
    try {
      await mutate(`/api/service-visits/${v.id}`, { status: "completed", workDone: work.trim() });
      push({ tone: "success", title: "Visit completed" });
      reload();
    } catch (e) {
      push({ tone: "danger", title: "Could not complete", desc: e instanceof Error ? e.message : String(e) });
    }
  };

  const s = summary.data;

  return (
    <>
      <PageHeader
        title="Support & warranty"
        description="What customers reported, what was done about it, and what the warranty covered."
        actions={
          <>
            <Button variant="ghost" onClick={reload}><RefreshCw size={16} /> Refresh</Button>
            <Button onClick={() => { setErr(null); setNewOpen(true); }}><Plus size={16} /> New ticket</Button>
          </>
        }
      />

      {s && (
        <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
          <Card className="p-3">
            <div className="text-xs text-muted">Open</div>
            <div className="text-xl font-semibold">{s.openTickets}</div>
          </Card>
          <Card className="p-3">
            <div className="text-xs text-muted">Past deadline</div>
            <div className={`text-xl font-semibold ${(s.responseBreached + s.resolutionBreached) > 0 ? "text-danger" : ""}`}>
              {s.responseBreached + s.resolutionBreached}
            </div>
          </Card>
          <Card className="p-3">
            <div className="text-xs text-muted">Waiting on customer</div>
            <div className="text-xl font-semibold">{s.waitingOnCustomer}</div>
          </Card>
          <Card className="p-3">
            <div className="text-xs text-muted">Warranty cost</div>
            <div className="text-xl font-semibold">PKR {Math.round(s.coveredCost).toLocaleString("en-PK")}</div>
          </Card>
        </div>
      )}

      <div className="grid gap-4 lg:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
        <Card className="p-0 overflow-hidden self-start">
          <div className="flex gap-1 p-2 border-b border-line">
            {FILTERS.map((f) => (
              <button key={f.id || "all"} onClick={() => setFilter(f.id)}
                className={`px-2.5 py-1 rounded-md text-xs transition ${
                  filter === f.id ? "bg-primary-600 text-white" : "bg-surface-2 text-muted hover:text-fg"}`}>
                {f.label}
              </button>
            ))}
          </div>
          {list.loading && !list.data ? (
            <div className="p-3 space-y-2"><Skeleton className="h-14" /><Skeleton className="h-14" /></div>
          ) : list.error ? (
            <ErrorState title="Support API unreachable" debug={list.error} onRetry={list.reload} />
          ) : ordered.length === 0 ? (
            <EmptyState icon={<Wrench size={20} />} title={filter === "open" ? "Nothing open" : "No tickets"}
              description={filter === "open" ? "Every reported fault has been dealt with." : undefined} />
          ) : (
            <ul className="divide-y divide-line max-h-[38rem] overflow-auto">
              {ordered.map((t) => (
                <li key={t.id}>
                  <button onClick={() => setSelected(t.id)}
                    className={`w-full text-left px-3 py-2.5 hover:bg-surface-2 transition ${selected === t.id ? "bg-surface-2" : ""}`}>
                    <div className="flex items-center gap-1.5 flex-wrap">
                      <span className="font-mono text-xs">{t.ref}</span>
                      <Badge tone={PRIORITY_TONE[t.priority] ?? "neutral"}>{t.priority}</Badge>
                      <Badge tone={STATUS_TONE[t.status] ?? "neutral"}>{t.status.replace("_", " ")}</Badge>
                      {(t.responseBreached || t.resolutionBreached) && (
                        <span className="text-xs text-danger flex items-center gap-1">
                          <AlertTriangle size={11} /> late
                        </span>
                      )}
                    </div>
                    <div className="text-sm font-medium truncate mt-0.5">{t.subject}</div>
                    <div className="text-xs text-muted truncate">
                      {t.customer}{t.assetTag ? ` · ${t.assetName}` : ""}
                    </div>
                  </button>
                </li>
              ))}
            </ul>
          )}
        </Card>

        {!selected ? (
          <Card className="flex items-center justify-center min-h-[18rem]">
            <EmptyState icon={<Wrench size={20} />} title="Choose a ticket"
              description="The thread, the visits and any warranty claim appear here." />
          </Card>
        ) : detail.loading && !d ? (
          <Card className="p-4 space-y-3"><Skeleton className="h-8 w-1/2" /><Skeleton className="h-32" /></Card>
        ) : detail.error ? (
          <Card><ErrorState title="Ticket API unreachable" debug={detail.error} onRetry={detail.reload} /></Card>
        ) : d ? (
          <div className="space-y-4 min-w-0">
            <Card className="p-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="font-mono text-sm">{d.ticket.ref}</span>
                    <Badge tone={PRIORITY_TONE[d.ticket.priority] ?? "neutral"}>{d.ticket.priority}</Badge>
                    <Badge tone={STATUS_TONE[d.ticket.status] ?? "neutral"}>{d.ticket.status.replace("_", " ")}</Badge>
                    {d.ticket.waitingOn === "them" && <Badge tone="warning">waiting on customer</Badge>}
                  </div>
                  <h2 className="text-lg font-semibold mt-1">{d.ticket.subject}</h2>
                  <div className="text-sm text-muted">
                    {d.ticket.customer}
                    {d.ticket.assetTag && <> · <span className="font-mono text-xs">{d.ticket.assetTag}</span> {d.ticket.assetName}</>}
                  </div>
                </div>
                <div className="flex flex-wrap gap-1">
                  <Button variant="ghost" onClick={() => { setErr(null); setVisit({ technician: "", scheduledFor: "" }); setVisitFor(d.ticket); }}>
                    Book visit
                  </Button>
                  {d.ticket.status !== "resolved" && d.ticket.status !== "closed" && (
                    <Button variant="ghost" onClick={() => setStatus(d.ticket, "resolved")}>Resolve</Button>
                  )}
                  {d.ticket.status === "resolved" && (
                    <Button variant="ghost" onClick={() => setStatus(d.ticket, "closed")}>Close</Button>
                  )}
                </div>
              </div>
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-3 pt-3 border-t border-line text-sm">
                <div>
                  <div className="text-xs text-muted">First response</div>
                  <div className="font-medium">{d.ticket.firstResponseAt ? dt(d.ticket.firstResponseAt) : "Not yet"}</div>
                </div>
                <div>
                  <div className="text-xs text-muted">Respond by</div>
                  <div className={`font-medium ${d.ticket.responseBreached ? "text-danger" : ""}`}>{dt(d.ticket.respondBy)}</div>
                </div>
                <div>
                  <div className="text-xs text-muted">Resolve by</div>
                  <div className={`font-medium ${d.ticket.resolutionBreached ? "text-danger" : ""}`}>{dt(d.ticket.resolveBy)}</div>
                </div>
                <div>
                  <div className="text-xs text-muted">Open for</div>
                  <div className="font-medium">{d.ticket.ageHours}h</div>
                </div>
              </div>
              {d.ticket.resolution && (
                <div className="mt-3 pt-3 border-t border-line text-sm">
                  <span className="text-muted">Resolution:</span> {d.ticket.resolution}
                </div>
              )}
            </Card>

            {d.visits.length > 0 && (
              <Card className="p-0 overflow-hidden">
                <div className="px-4 py-2.5 border-b border-line font-medium text-sm">Visits</div>
                <ul className="divide-y divide-line">
                  {d.visits.map((v) => (
                    <li key={v.id} className="px-4 py-2.5">
                      <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                          <div className="flex items-center gap-2 flex-wrap">
                            <span className="font-mono text-xs">{v.ref}</span>
                            <Badge tone={v.status === "completed" ? "success" : v.status === "missed" ? "danger" : "info"}>
                              {v.status}
                            </Badge>
                            {v.technician && <span className="text-sm">{v.technician}</span>}
                          </div>
                          <div className="text-xs text-muted mt-0.5 flex items-center gap-1">
                            <Clock size={12} /> {dt(v.scheduledFor)}
                          </div>
                          {v.workDone && <div className="text-xs mt-1"><span className="text-muted">Done:</span> {v.workDone}</div>}
                        </div>
                        {v.status !== "completed" && v.status !== "cancelled" && (
                          <Button variant="ghost" onClick={() => completeVisit(v)}>Complete</Button>
                        )}
                      </div>
                    </li>
                  ))}
                </ul>
              </Card>
            )}

            {d.claims.length > 0 && (
              <Card className="p-0 overflow-hidden">
                <div className="px-4 py-2.5 border-b border-line font-medium text-sm">Warranty claims</div>
                <ul className="divide-y divide-line">
                  {d.claims.map((c) => (
                    <li key={c.id} className="px-4 py-2.5">
                      <div className="flex items-center gap-2 flex-wrap">
                        <span className="font-mono text-xs">{c.ref}</span>
                        <Badge tone={VERDICT_TONE[c.verdict] ?? "neutral"}>{c.verdict.replace("_", " ")}</Badge>
                        <span className="text-sm">{c.fault}</span>
                      </div>
                      <div className="text-xs text-muted mt-0.5">
                        Claimed {dt(c.claimedOn)}
                        {c.warrantyUntilOnClaim && <> · warranty ran to {c.warrantyUntilOnClaim}</>}
                        {c.decidedOn && <> · decided {c.decidedOn}</>}
                        {c.costToWooodex !== null && <> · cost PKR {c.costToWooodex.toLocaleString("en-PK")}</>}
                      </div>
                      {c.reason && <div className="text-xs mt-0.5">{c.reason}</div>}
                    </li>
                  ))}
                </ul>
              </Card>
            )}

            <Card className="p-0 overflow-hidden">
              <div className="px-4 py-2.5 border-b border-line font-medium text-sm">Thread</div>
              <ul className="divide-y divide-line max-h-[22rem] overflow-auto">
                {d.messages.map((m) => (
                  <li key={m.id} className={`px-4 py-2.5 ${m.internal ? "bg-surface-2" : ""}`}>
                    <div className="flex items-center gap-2 text-xs text-muted">
                      <span className="font-medium text-fg">{m.author ?? "—"}</span>
                      <span>{dt(m.createdAt)}</span>
                      {m.internal && <span className="flex items-center gap-1"><Lock size={10} /> internal</span>}
                    </div>
                    <div className="text-sm mt-0.5">{m.body}</div>
                  </li>
                ))}
              </ul>
              {d.ticket.status === "closed" ? (
                <div className="px-4 py-3 text-sm text-muted border-t border-line">
                  This ticket is closed. Reopen it before writing, so the reopening is visible.
                </div>
              ) : (
                <div className="p-3 border-t border-line space-y-2">
                  {err && <div className="text-sm text-danger">{err}</div>}
                  <Input value={reply} onChange={(e) => setReply(e.target.value)}
                    placeholder={internal ? "Internal note — the customer will not see this" : "Reply to the customer…"} />
                  <div className="flex items-center justify-between">
                    <label className="flex items-center gap-2 text-xs text-muted">
                      <input type="checkbox" checked={internal} onChange={(e) => setInternal(e.target.checked)} />
                      Internal note (does not count as a response)
                    </label>
                    <Button onClick={send} disabled={saving || !reply.trim()}>
                      {saving ? "Saving…" : internal ? "Save note" : "Send reply"}
                    </Button>
                  </div>
                </div>
              )}
            </Card>
          </div>
        ) : null}
      </div>

      {/* new ticket */}
      <Modal open={newOpen} onClose={() => setNewOpen(false)} title="Open a ticket">
        <div className="space-y-3">
          {err && <div className="text-sm text-danger">{err}</div>}
          <Field label="Customer"><Input value={form.customer} onChange={(e) => setForm({ ...form, customer: e.target.value })} /></Field>
          <Field label="What is wrong"><Input value={form.subject} onChange={(e) => setForm({ ...form, subject: e.target.value })} placeholder="Door hinges squeaking" /></Field>
          <Field label="Details"><Input value={form.description} onChange={(e) => setForm({ ...form, description: e.target.value })} placeholder="Two doors scrape the frame" /></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Priority">
              <Select value={form.priority} onChange={(e) => setForm({ ...form, priority: e.target.value })}>
                <option value="low">Low</option>
                <option value="normal">Normal</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
              </Select>
            </Field>
            <Field label="Category">
              <Select value={form.category} onChange={(e) => setForm({ ...form, category: e.target.value })}>
                <option value="other">Other</option>
                <option value="damage">Damage</option>
                <option value="defect">Defect</option>
                <option value="missing_part">Missing part</option>
                <option value="fitting">Fitting</option>
                <option value="finish">Finish</option>
                <option value="delivery">Delivery</option>
              </Select>
            </Field>
          </div>
          <Field label="Installed asset (optional)">
            <Select value={form.assetId} onChange={(e) => setForm({ ...form, assetId: e.target.value })}>
              <option value="">Not about a specific asset</option>
              {(assets.data?.items ?? []).map((a) => (
                <option key={a.id} value={a.id}>{a.tag} — {a.name}{a.warrantyUntil ? ` (to ${a.warrantyUntil})` : ""}</option>
              ))}
            </Select>
          </Field>
          <div className="text-xs text-muted">
            Linking the asset is what lets the warranty answer be looked up instead of argued.
          </div>
          <div className="flex justify-end gap-2 pt-1">
            <Button variant="ghost" onClick={() => setNewOpen(false)}>Cancel</Button>
            <Button onClick={openTicket} disabled={saving}>{saving ? "Saving…" : "Open ticket"}</Button>
          </div>
        </div>
      </Modal>

      {/* resolve */}
      <Modal open={!!resolveFor} onClose={() => setResolveFor(null)} title="What fixed it?">
        <div className="space-y-3">
          {err && <div className="text-sm text-danger">{err}</div>}
          <div className="text-sm text-muted">{resolveFor?.ref} · {resolveFor?.subject}</div>
          <Field label="Resolution">
            <Input value={resolution} onChange={(e) => setResolution(e.target.value)}
              placeholder="Adjusted both hinges and replaced the screws" />
          </Field>
          <div className="text-xs text-muted">
            A resolved ticket with no resolution tells the next person nothing — and this is the field they read first.
          </div>
          <div className="flex justify-end gap-2 pt-1">
            <Button variant="ghost" onClick={() => setResolveFor(null)}>Cancel</Button>
            <Button onClick={doResolve} disabled={saving}>{saving ? "Saving…" : "Resolve"}</Button>
          </div>
        </div>
      </Modal>

      {/* book visit */}
      <Modal open={!!visitFor} onClose={() => setVisitFor(null)} title="Book a visit">
        <div className="space-y-3">
          {err && <div className="text-sm text-danger">{err}</div>}
          <div className="text-sm text-muted">{visitFor?.ref} · {visitFor?.subject}</div>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Technician"><Input value={visit.technician} onChange={(e) => setVisit({ ...visit, technician: e.target.value })} /></Field>
            <Field label="When">
              <Input type="datetime-local" value={visit.scheduledFor} onChange={(e) => setVisit({ ...visit, scheduledFor: e.target.value })} />
            </Field>
          </div>
          <div className="flex justify-end gap-2 pt-1">
            <Button variant="ghost" onClick={() => setVisitFor(null)}>Cancel</Button>
            <Button onClick={bookVisit} disabled={saving}>{saving ? "Saving…" : "Book"}</Button>
          </div>
        </div>
      </Modal>
    </>
  );
}

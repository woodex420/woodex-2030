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
import { Plus, RefreshCw, Calendar, Clock, MapPin, Phone, Users, Video } from "@/icons";

/* Meetings — what was scheduled, what happened, and what came of it.
 *
 * The status is a small state machine, enforced by the server and mirrored
 * here: scheduled, then held, cancelled or no-show. "Held" requires an outcome,
 * because a record that says a conversation took place and nothing about it is
 * a diary entry — and the outcome is the only part anyone rereads.
 *
 * Deliberately not site visits. A site visit is a measurement taken at a
 * property; a meeting is a conversation. Folding them together would force
 * every video call to claim a site, so visits keep their own table. */

type Meeting = {
  id: number; ref: string; title: string; companyId: number | null; companyName: string | null;
  clientId: number | null; leadId: number | null; startsAt: string; minutes: number;
  location: string | null; channel: string; owner: string | null; status: string;
  attendees: string | null; agenda: string | null; outcome: string | null;
  createdAt: string; updatedAt: string;
};
type Company = { id: number; name: string };

const STATUS_TONE: Record<string, "info" | "success" | "neutral" | "warning"> = {
  scheduled: "info", held: "success", cancelled: "neutral", no_show: "warning",
};
const CHANNEL_ICON: Record<string, typeof Phone> = { phone: Phone, video: Video, "in-person": MapPin };

const dt = (v: string) => new Date(v).toLocaleString(undefined, {
  weekday: "short", day: "2-digit", month: "short", hour: "2-digit", minute: "2-digit" });

const FILTERS = [
  { id: "", label: "All" },
  { id: "scheduled", label: "Scheduled" },
  { id: "held", label: "Held" },
  { id: "no_show", label: "No-show" },
  { id: "cancelled", label: "Cancelled" },
];

const EMPTY_FORM = { title: "", startsAt: "", channel: "in-person", minutes: "30", location: "", agenda: "", companyId: "" };

export default function Meetings() {
  const { push } = useToast();
  const [status, setStatus] = useState("");
  const [open, setOpen] = useState(false);
  const [outcomeFor, setOutcomeFor] = useState<Meeting | null>(null);
  const [outcome, setOutcome] = useState("");
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState<string | null>(null);
  const [form, setForm] = useState(EMPTY_FORM);

  const res = useApi<{ total: number; upcoming: number; items: Meeting[] }>(
    `/api/meetings${status ? `?status=${status}` : ""}`, 30000);
  const companies = useApi<{ items: Company[] }>("/api/companies", 120000);

  const items = res.data?.items ?? [];
  const nowIso = new Date().toISOString();

  /* Two lists, because "what is coming" and "what happened" are read for
     different reasons and interleaving them makes both harder. */
  const { upcoming, past } = useMemo(() => {
    const up: Meeting[] = [];
    const down: Meeting[] = [];
    for (const m of items) (m.status === "scheduled" && m.startsAt >= nowIso ? up : down).push(m);
    up.sort((a, b) => a.startsAt.localeCompare(b.startsAt));
    return { upcoming: up, past: down };
  }, [items, nowIso]);

  const schedule = async () => {
    if (saving) return;
    setErr(null);
    if (!form.title.trim()) { setErr("A meeting needs a subject."); return; }
    if (!form.startsAt) { setErr("A meeting needs a date and time."); return; }
    setSaving(true);
    try {
      await mutate("/api/meetings", {
        title: form.title.trim(),
        startsAt: new Date(form.startsAt).toISOString(),
        channel: form.channel,
        minutes: Number(form.minutes) || 30,
        location: form.location || null,
        agenda: form.agenda || null,
        companyId: form.companyId ? Number(form.companyId) : null,
      }, "POST");
      push({ tone: "success", title: "Meeting scheduled" });
      setOpen(false);
      setForm(EMPTY_FORM);
      res.reload();
    } catch (e) {
      setErr(e instanceof Error ? e.message : "Could not schedule the meeting.");
    } finally { setSaving(false); }
  };

  const setState = async (m: Meeting, next: "cancelled" | "no_show") => {
    try {
      await mutate(`/api/meetings/${m.id}`, { status: next });
      push({ tone: "info", title: `Marked ${next.replace("_", " ")}`, desc: m.title });
      res.reload();
    } catch (e) {
      push({ tone: "danger", title: "Could not update", desc: e instanceof Error ? e.message : String(e) });
    }
  };

  const recordHeld = async () => {
    if (!outcomeFor || saving) return;
    if (!outcome.trim()) { setErr("Say what came of it — that is the part anyone rereads."); return; }
    setSaving(true);
    setErr(null);
    try {
      await mutate(`/api/meetings/${outcomeFor.id}`, { status: "held", outcome: outcome.trim() });
      push({ tone: "success", title: "Meeting recorded" });
      setOutcomeFor(null);
      setOutcome("");
      res.reload();
    } catch (e) {
      setErr(e instanceof Error ? e.message : "Could not record the outcome.");
    } finally { setSaving(false); }
  };

  if (res.error && !res.data) {
    return (
      <div>
        <PageHeader title="Meetings" />
        <ErrorState title="Meetings API unreachable" debug={res.error} onRetry={res.reload} />
      </div>
    );
  }

  const row = (m: Meeting, showActions: boolean) => {
    const Icon = CHANNEL_ICON[m.channel] ?? MapPin;
    return (
      <li key={m.id} className="px-4 py-3">
        <div className="flex items-start justify-between gap-3">
          <div className="min-w-0">
            <div className="flex items-center gap-2 flex-wrap">
              <span className="font-medium">{m.title}</span>
              <Badge tone={STATUS_TONE[m.status] ?? "neutral"}>{m.status.replace("_", " ")}</Badge>
              <span className="text-xs text-muted flex items-center gap-1"><Icon size={12} /> {m.channel}</span>
            </div>
            <div className="text-xs text-muted mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5">
              <span className="flex items-center gap-1"><Calendar size={12} /> {dt(m.startsAt)}</span>
              <span className="flex items-center gap-1"><Clock size={12} /> {m.minutes} min</span>
              {m.companyName && <span className="flex items-center gap-1"><Users size={12} /> {m.companyName}</span>}
              {m.location && <span className="flex items-center gap-1"><MapPin size={12} /> {m.location}</span>}
              <span className="font-mono">{m.ref}</span>
            </div>
            {m.agenda && <div className="text-xs mt-1"><span className="text-muted">Agenda:</span> {m.agenda}</div>}
            {m.outcome && <div className="text-xs mt-0.5"><span className="text-muted">Outcome:</span> {m.outcome}</div>}
          </div>
          {showActions && (
            <div className="flex gap-1 shrink-0">
              <Button variant="ghost" onClick={() => { setErr(null); setOutcome(""); setOutcomeFor(m); }}>Held</Button>
              <Button variant="ghost" onClick={() => setState(m, "no_show")}>No-show</Button>
              <Button variant="ghost" onClick={() => setState(m, "cancelled")}>Cancel</Button>
            </div>
          )}
        </div>
      </li>
    );
  };

  return (
    <>
      <PageHeader
        title="Meetings"
        description="Scheduled conversations and what came of them."
        actions={
          <>
            <Button variant="ghost" onClick={res.reload}><RefreshCw size={16} /> Refresh</Button>
            <Button onClick={() => { setErr(null); setOpen(true); }}><Plus size={16} /> Schedule</Button>
          </>
        }
      />

      <div className="flex flex-wrap gap-1 mb-4">
        {FILTERS.map((f) => (
          <button
            key={f.id || "all"}
            onClick={() => setStatus(f.id)}
            className={`px-3 py-1.5 rounded-lg text-sm transition ${
              status === f.id ? "bg-primary-600 text-white" : "bg-surface-2 text-muted hover:text-fg"
            }`}
          >{f.label}</button>
        ))}
      </div>

      {res.loading && !res.data ? (
        <Card className="p-4 space-y-3"><Skeleton className="h-14" /><Skeleton className="h-14" /><Skeleton className="h-14" /></Card>
      ) : items.length === 0 ? (
        <Card>
          <EmptyState
            icon={<Calendar size={20} />}
            title={status ? "Nothing in that state" : "No meetings yet"}
            description={status ? "Try another filter." : "Schedule the first conversation and it lands on the company's history."}
            action={<Button onClick={() => { setErr(null); setOpen(true); }}><Plus size={16} /> Schedule a meeting</Button>}
          />
        </Card>
      ) : (
        <div className="space-y-4">
          {upcoming.length > 0 && (
            <Card className="p-0 overflow-hidden">
              <div className="px-4 py-2.5 border-b border-line font-medium text-sm">
                Upcoming <span className="text-muted">({upcoming.length})</span>
              </div>
              <ul className="divide-y divide-line">{upcoming.map((m) => row(m, true))}</ul>
            </Card>
          )}
          {past.length > 0 && (
            <Card className="p-0 overflow-hidden">
              <div className="px-4 py-2.5 border-b border-line font-medium text-sm">
                Earlier <span className="text-muted">({past.length})</span>
              </div>
              <ul className="divide-y divide-line">{past.map((m) => row(m, m.status === "scheduled"))}</ul>
            </Card>
          )}
        </div>
      )}

      <Modal open={open} onClose={() => setOpen(false)} title="Schedule a meeting">
        <div className="space-y-3">
          {err && <div className="text-sm text-danger">{err}</div>}
          <Field label="Subject">
            <Input value={form.title} onChange={(e) => setForm({ ...form, title: e.target.value })} placeholder="Spec review" />
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Starts">
              <Input type="datetime-local" value={form.startsAt} onChange={(e) => setForm({ ...form, startsAt: e.target.value })} />
            </Field>
            <Field label="Minutes">
              <Input type="number" min={1} value={form.minutes} onChange={(e) => setForm({ ...form, minutes: e.target.value })} />
            </Field>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Channel">
              <Select value={form.channel} onChange={(e) => setForm({ ...form, channel: e.target.value })}>
                <option value="in-person">In person</option>
                <option value="phone">Phone</option>
                <option value="video">Video</option>
              </Select>
            </Field>
            <Field label="Location">
              <Input value={form.location} onChange={(e) => setForm({ ...form, location: e.target.value })} />
            </Field>
          </div>
          <Field label="Company">
            <Select value={form.companyId} onChange={(e) => setForm({ ...form, companyId: e.target.value })}>
              <option value="">Not linked</option>
              {(companies.data?.items ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
          </Field>
          <Field label="Agenda">
            <Input value={form.agenda} onChange={(e) => setForm({ ...form, agenda: e.target.value })} />
          </Field>
          <div className="flex justify-end gap-2 pt-1">
            <Button variant="ghost" onClick={() => setOpen(false)}>Cancel</Button>
            <Button onClick={schedule} disabled={saving}>{saving ? "Saving…" : "Schedule"}</Button>
          </div>
        </div>
      </Modal>

      <Modal open={!!outcomeFor} onClose={() => setOutcomeFor(null)} title="What came of it?">
        <div className="space-y-3">
          {err && <div className="text-sm text-danger">{err}</div>}
          <div className="text-sm text-muted">{outcomeFor?.title}</div>
          <Field label="Outcome">
            <Input value={outcome} onChange={(e) => setOutcome(e.target.value)}
              placeholder="Approved the walnut finish; revised quote to follow" />
          </Field>
          <div className="text-xs text-muted">
            A meeting recorded as held must say what happened — this is the only part anyone rereads.
          </div>
          <div className="flex justify-end gap-2 pt-1">
            <Button variant="ghost" onClick={() => setOutcomeFor(null)}>Cancel</Button>
            <Button onClick={recordHeld} disabled={saving}>{saving ? "Saving…" : "Record as held"}</Button>
          </div>
        </div>
      </Modal>
    </>
  );
}

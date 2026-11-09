import { useMemo, useState } from "react";
import { PageHeader } from "@/components/layout/PageHeader";
import { Card } from "@/components/ui/Card";
import { Button } from "@/components/ui/Button";
import { Badge } from "@/components/ui/Badge";
import { EmptyState, ErrorState, Skeleton } from "@/components/ui/States";
import { Field, Input, Select } from "@/components/ui/Field";
import { Modal } from "@/components/ui/Modal";
import { useToast } from "@/components/ui/Toast";
import { useApi, mutate, ApiError } from "@/lib/api";
import { Plus, RefreshCw, Building, Phone, Mail, MapPin, Clock, Users, Calendar, Lock } from "@/icons";

/* Companies — the record a customer, a lead and a project all point at.
 *
 * Before this screen a company was a string typed into a lead and again into a
 * client. The same customer arriving from two channels became two rows, and
 * "everything we have ever done with them" was a LIKE query that missed the
 * spellings the typist got wrong. The server folds punctuation and legal
 * suffixes into `name_norm`, so "Clifton Villa (Pvt) Ltd." and "clifton villa
 * private limited" are one key — and the second is refused with a 409 that
 * names the first, so the operator can open it rather than invent a third
 * spelling.
 *
 * The detail panel is one GET because the point of a real company record is
 * that "show me everything" is a query, not a search.
 */

type Company = {
  id: number; name: string; industry: string | null; website: string | null;
  phone: string | null; email: string | null; taxRef: string | null; city: string | null;
  address: string | null; accountOwner: string | null; notes: string | null;
  createdAt: string; updatedAt: string; contactCount: number; clientCount: number;
};
type Contact = {
  id: number; companyId: number | null; companyName: string | null; clientId: number | null;
  name: string; email: string | null; phone: string | null; role: string | null;
  isPrimary: boolean; notes: string | null; createdAt: string;
};
type Meeting = {
  id: number; ref: string; title: string; companyId: number | null; companyName: string | null;
  startsAt: string; minutes: number; location: string | null; channel: string;
  owner: string | null; status: string; attendees: string | null;
  agenda: string | null; outcome: string | null;
};
type Activity = {
  id: number; entityType: string; entityId: number; kind: string;
  summary: string; detail: string | null; actor: string | null;
  occurredAt: string; createdAt: string;
};
type Attachment = { id: number; filename: string; mimeType: string | null; sizeBytes: number | null; checksum: string | null };
type Lead = { id: number; ref: string; name: string; status: string };
type Client = { id: number; name: string; email: string | null; phone: string | null };
type Detail = {
  company: Company; contacts: Contact[]; clients: Client[];
  leads: Lead[]; meetings: Meeting[]; timeline: Activity[]; attachments: Attachment[];
};

const MEETING_TONE: Record<string, "info" | "success" | "neutral" | "warning"> = {
  scheduled: "info", held: "success", cancelled: "neutral", no_show: "warning",
};
const KIND_LABEL: Record<string, string> = {
  call: "Call", meeting: "Meeting", note: "Note", email: "Email",
  status_change: "Change", document: "File", payment: "Payment", system: "System",
};

const dt = (v: string | null) => (v ? new Date(v).toLocaleString(undefined, {
  day: "2-digit", month: "short", year: "numeric", hour: "2-digit", minute: "2-digit" }) : "—");
const day = (v: string | null) => (v ? new Date(v).toLocaleDateString(undefined, {
  day: "2-digit", month: "short", year: "numeric" }) : "—");

export default function Companies() {
  const { push } = useToast();
  const [q, setQ] = useState("");
  const [selected, setSelected] = useState<number | null>(null);
  const [createOpen, setCreateOpen] = useState(false);
  const [contactOpen, setContactOpen] = useState(false);
  const [meetingOpen, setMeetingOpen] = useState(false);
  const [noteOpen, setNoteOpen] = useState(false);
  const [saving, setSaving] = useState(false);
  const [err, setErr] = useState<string | null>(null);

  const [form, setForm] = useState({ name: "", industry: "", city: "", phone: "", email: "", accountOwner: "" });
  const [contactForm, setContactForm] = useState({ name: "", email: "", phone: "", role: "", isPrimary: false });
  const [meetingForm, setMeetingForm] = useState({ title: "", startsAt: "", channel: "in-person", minutes: "30", location: "", agenda: "" });
  const [noteForm, setNoteForm] = useState({ kind: "note", summary: "", detail: "" });

  const list = useApi<{ total: number; items: Company[] }>(
    `/api/companies${q ? `?q=${encodeURIComponent(q)}` : ""}`, 20000);
  const detail = useApi<Detail>(selected ? `/api/companies/${selected}` : null, 15000);

  const items = list.data?.items ?? [];
  const d = detail.data;

  const upcoming = useMemo(() => {
    const nowIso = new Date().toISOString();
    return (d?.meetings ?? []).filter((m) => m.status === "scheduled" && m.startsAt >= nowIso);
  }, [d]);

  const refresh = () => { list.reload(); if (selected) detail.reload(); };

  const submitCompany = async () => {
    if (saving) return;
    setErr(null);
    if (!form.name.trim()) { setErr("A company needs a name."); return; }
    setSaving(true);
    try {
      const out = await mutate<{ company: Company }>("/api/companies", {
        name: form.name.trim(),
        industry: form.industry || null, city: form.city || null,
        phone: form.phone || null, email: form.email || null,
        accountOwner: form.accountOwner || null,
      }, "POST");
      push({ tone: "success", title: "Company created", desc: out.company.name });
      setCreateOpen(false);
      setForm({ name: "", industry: "", city: "", phone: "", email: "", accountOwner: "" });
      setSelected(out.company.id);
      refresh();
    } catch (e) {
      /* The 409 carries the id of the company that already exists. Open it,
         rather than making the operator retype the name with a new spelling —
         which is how the duplicate got here in the first place. */
      const message = e instanceof Error ? e.message : "Could not create the company.";
      setErr(message);
      const existing = e instanceof ApiError ? e.idOf("companyId") : null;
      if (existing) {
        setCreateOpen(false);
        setSelected(existing);
        push({ tone: "warning", title: "That company already exists", desc: message });
      }
    } finally { setSaving(false); }
  };

  const submitContact = async () => {
    if (saving || !selected) return;
    setErr(null);
    if (!contactForm.name.trim()) { setErr("A contact needs a name."); return; }
    setSaving(true);
    try {
      await mutate("/api/contacts", {
        name: contactForm.name.trim(), email: contactForm.email || null,
        phone: contactForm.phone || null, role: contactForm.role || null,
        isPrimary: contactForm.isPrimary, companyId: selected,
      }, "POST");
      push({ tone: "success", title: "Contact added" });
      setContactOpen(false);
      setContactForm({ name: "", email: "", phone: "", role: "", isPrimary: false });
      refresh();
    } catch (e) {
      setErr(e instanceof Error ? e.message : "Could not add the contact.");
    } finally { setSaving(false); }
  };

  const submitMeeting = async () => {
    if (saving || !selected) return;
    setErr(null);
    if (!meetingForm.title.trim()) { setErr("A meeting needs a subject."); return; }
    if (!meetingForm.startsAt) { setErr("A meeting needs a date and time."); return; }
    setSaving(true);
    try {
      await mutate("/api/meetings", {
        title: meetingForm.title.trim(),
        startsAt: new Date(meetingForm.startsAt).toISOString(),
        channel: meetingForm.channel, minutes: Number(meetingForm.minutes) || 30,
        location: meetingForm.location || null, agenda: meetingForm.agenda || null,
        companyId: selected,
      }, "POST");
      push({ tone: "success", title: "Meeting scheduled" });
      setMeetingOpen(false);
      setMeetingForm({ title: "", startsAt: "", channel: "in-person", minutes: "30", location: "", agenda: "" });
      refresh();
    } catch (e) {
      setErr(e instanceof Error ? e.message : "Could not schedule the meeting.");
    } finally { setSaving(false); }
  };

  const submitNote = async () => {
    if (saving || !selected) return;
    setErr(null);
    if (!noteForm.summary.trim()) { setErr("Say what happened."); return; }
    setSaving(true);
    try {
      await mutate("/api/activities", {
        entityType: "company", entityId: selected, kind: noteForm.kind,
        summary: noteForm.summary.trim(), detail: noteForm.detail || null,
      }, "POST");
      push({ tone: "success", title: "Logged to the timeline" });
      setNoteOpen(false);
      setNoteForm({ kind: "note", summary: "", detail: "" });
      refresh();
    } catch (e) {
      setErr(e instanceof Error ? e.message : "Could not log it.");
    } finally { setSaving(false); }
  };

  /* Marking a meeting held requires the outcome — the server refuses it
     otherwise, and asking here rather than letting the request fail is the
     difference between a form and a lesson. */
  const markHeld = async (m: Meeting) => {
    const outcome = window.prompt(`What came of "${m.title}"?`);
    if (outcome === null) return;
    if (!outcome.trim()) {
      push({ tone: "warning", title: "An outcome is required", desc: "A meeting that happened says what came of it." });
      return;
    }
    try {
      await mutate(`/api/meetings/${m.id}`, { status: "held", outcome: outcome.trim() });
      push({ tone: "success", title: "Meeting recorded as held" });
      refresh();
    } catch (e) {
      push({ tone: "danger", title: "Could not update", desc: e instanceof Error ? e.message : String(e) });
    }
  };

  return (
    <>
      <PageHeader
        title="Companies"
        description="One record per customer, with the people, meetings and history that belong to it."
        actions={
          <>
            <Button variant="ghost" onClick={refresh}><RefreshCw size={16} /> Refresh</Button>
            <Button onClick={() => { setErr(null); setCreateOpen(true); }}><Plus size={16} /> New company</Button>
          </>
        }
      />

      <div className="grid gap-4 lg:grid-cols-[minmax(0,22rem)_minmax(0,1fr)]">
        <Card className="p-0 overflow-hidden self-start">
          <div className="p-3 border-b border-line">
            <Input placeholder="Search name, city, email…" value={q} onChange={(e) => setQ(e.target.value)} />
          </div>
          {list.loading && !list.data ? (
            <div className="p-3 space-y-2"><Skeleton className="h-12" /><Skeleton className="h-12" /><Skeleton className="h-12" /></div>
          ) : list.error ? (
            <ErrorState title="Companies API unreachable" debug={list.error} onRetry={list.reload} />
          ) : items.length === 0 ? (
            <EmptyState
              icon={<Building size={20} />}
              title={q ? "No company matches that" : "No companies yet"}
              description={q ? "Try a shorter search, or create the company." : "Add the first customer and everything else can point at it."}
            />
          ) : (
            <ul className="divide-y divide-line max-h-[36rem] overflow-auto">
              {items.map((c) => (
                <li key={c.id}>
                  <button
                    onClick={() => setSelected(c.id)}
                    className={`w-full text-left px-3 py-2.5 hover:bg-surface-2 transition ${selected === c.id ? "bg-surface-2" : ""}`}
                  >
                    <div className="flex items-center justify-between gap-2">
                      <span className="font-medium truncate">{c.name}</span>
                      {c.contactCount > 0 && (
                        <span className="text-xs text-muted shrink-0 flex items-center gap-1">
                          <Users size={12} /> {c.contactCount}
                        </span>
                      )}
                    </div>
                    <div className="text-xs text-muted truncate">
                      {[c.industry, c.city].filter(Boolean).join(" · ") || "No industry or city recorded"}
                    </div>
                  </button>
                </li>
              ))}
            </ul>
          )}
          <div className="px-3 py-2 text-xs text-muted border-t border-line">
            {list.data ? `${list.data.total} compan${list.data.total === 1 ? "y" : "ies"}` : "—"}
          </div>
        </Card>

        {!selected ? (
          <Card className="flex items-center justify-center min-h-[20rem]">
            <EmptyState icon={<Building size={20} />} title="Choose a company"
              description="Contacts, meetings and the full history appear here." />
          </Card>
        ) : detail.loading && !d ? (
          <Card className="p-4 space-y-3"><Skeleton className="h-8 w-1/2" /><Skeleton className="h-24" /><Skeleton className="h-24" /></Card>
        ) : detail.error ? (
          <Card><ErrorState title="Company API unreachable" debug={detail.error} onRetry={detail.reload} /></Card>
        ) : d ? (
          <div className="space-y-4 min-w-0">
            <Card className="p-4">
              <div className="flex flex-wrap items-start justify-between gap-3">
                <div className="min-w-0">
                  <h2 className="text-lg font-semibold truncate">{d.company.name}</h2>
                  <div className="text-sm text-muted flex flex-wrap items-center gap-x-4 gap-y-1 mt-1">
                    {d.company.industry && <span>{d.company.industry}</span>}
                    {d.company.city && <span className="flex items-center gap-1"><MapPin size={13} /> {d.company.city}</span>}
                    {d.company.phone && <span className="flex items-center gap-1"><Phone size={13} /> {d.company.phone}</span>}
                    {d.company.email && <span className="flex items-center gap-1"><Mail size={13} /> {d.company.email}</span>}
                  </div>
                </div>
                <div className="flex flex-wrap gap-2">
                  <Button variant="ghost" onClick={() => { setErr(null); setContactOpen(true); }}><Users size={15} /> Contact</Button>
                  <Button variant="ghost" onClick={() => { setErr(null); setMeetingOpen(true); }}><Calendar size={15} /> Meeting</Button>
                  <Button variant="ghost" onClick={() => { setErr(null); setNoteOpen(true); }}><Clock size={15} /> Log activity</Button>
                </div>
              </div>
              <div className="grid grid-cols-2 sm:grid-cols-4 gap-3 mt-4 pt-4 border-t border-line text-sm">
                <div><div className="text-xs text-muted">Contacts</div><div className="font-medium">{d.contacts.length}</div></div>
                <div><div className="text-xs text-muted">Clients</div><div className="font-medium">{d.clients.length}</div></div>
                <div><div className="text-xs text-muted">Leads</div><div className="font-medium">{d.leads.length}</div></div>
                <div><div className="text-xs text-muted">Account owner</div><div className="font-medium truncate">{d.company.accountOwner ?? "Unassigned"}</div></div>
              </div>
            </Card>

            <Card className="p-0 overflow-hidden">
              <div className="px-4 py-2.5 border-b border-line font-medium text-sm">People</div>
              {d.contacts.length === 0 ? (
                <div className="px-4 py-6 text-sm text-muted">
                  Nobody recorded yet. A contact is a person, not a phone number in a note.
                </div>
              ) : (
                <ul className="divide-y divide-line">
                  {d.contacts.map((c) => (
                    <li key={c.id} className="px-4 py-2.5">
                      <div className="flex items-center gap-2">
                        <span className="font-medium truncate">{c.name}</span>
                        {c.isPrimary && <Badge tone="info">Primary</Badge>}
                      </div>
                      <div className="text-xs text-muted truncate">
                        {[c.role, c.email, c.phone].filter(Boolean).join(" · ") || "No role or contact details"}
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </Card>

            <Card className="p-0 overflow-hidden">
              <div className="px-4 py-2.5 border-b border-line flex items-center justify-between">
                <span className="font-medium text-sm">Meetings</span>
                {upcoming.length > 0 && <span className="text-xs text-muted">{upcoming.length} upcoming</span>}
              </div>
              {d.meetings.length === 0 ? (
                <div className="px-4 py-6 text-sm text-muted">No meetings yet.</div>
              ) : (
                <ul className="divide-y divide-line">
                  {d.meetings.map((m) => (
                    <li key={m.id} className="px-4 py-2.5">
                      <div className="flex items-start justify-between gap-3">
                        <div className="min-w-0">
                          <div className="flex items-center gap-2 flex-wrap">
                            <span className="font-medium">{m.title}</span>
                            <Badge tone={MEETING_TONE[m.status] ?? "neutral"}>{m.status.replace("_", " ")}</Badge>
                            <span className="text-xs text-muted">{m.channel}</span>
                          </div>
                          <div className="text-xs text-muted mt-0.5">
                            {dt(m.startsAt)} · {m.minutes} min{m.location ? ` · ${m.location}` : ""}
                          </div>
                          {m.outcome && <div className="text-xs mt-1"><span className="text-muted">Outcome:</span> {m.outcome}</div>}
                        </div>
                        {m.status === "scheduled" && <Button variant="ghost" onClick={() => markHeld(m)}>Mark held</Button>}
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </Card>

            {d.attachments.length > 0 && (
              <Card className="p-0 overflow-hidden">
                <div className="px-4 py-2.5 border-b border-line font-medium text-sm">Files</div>
                <ul className="divide-y divide-line">
                  {d.attachments.map((a) => (
                    <li key={a.id} className="px-4 py-2 flex items-center justify-between gap-3 text-sm">
                      <span className="truncate">{a.filename}</span>
                      <span className="text-xs text-muted shrink-0">
                        {a.sizeBytes ? `${Math.round(a.sizeBytes / 1024)} kB` : "size unknown"}
                        {a.checksum ? ` · ${a.checksum.slice(0, 12)}` : ""}
                      </span>
                    </li>
                  ))}
                </ul>
              </Card>
            )}

            <Card className="p-0 overflow-hidden">
              <div className="px-4 py-2.5 border-b border-line flex items-center justify-between">
                <span className="font-medium text-sm">History</span>
                <span className="text-xs text-muted flex items-center gap-1"><Lock size={11} /> append-only</span>
              </div>
              {d.timeline.length === 0 ? (
                <div className="px-4 py-6 text-sm text-muted">Nothing recorded yet.</div>
              ) : (
                <ul className="divide-y divide-line">
                  {d.timeline.map((a) => (
                    <li key={a.id} className="px-4 py-2.5 flex gap-3">
                      <div className="text-xs text-muted w-28 shrink-0">{day(a.occurredAt)}</div>
                      <div className="min-w-0">
                        <div className="text-sm">
                          <span className="text-muted">{KIND_LABEL[a.kind] ?? a.kind}</span>{" · "}{a.summary}
                        </div>
                        {a.detail && <div className="text-xs text-muted mt-0.5">{a.detail}</div>}
                        {a.actor && <div className="text-xs text-muted mt-0.5">by {a.actor}</div>}
                      </div>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
          </div>
        ) : null}
      </div>

      <Modal open={createOpen} onClose={() => setCreateOpen(false)} title="New company">
        <div className="space-y-3">
          {err && <div className="text-sm text-danger">{err}</div>}
          <Field label="Name">
            <Input value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} placeholder="Clifton Villa (Pvt) Ltd." />
          </Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Industry"><Input value={form.industry} onChange={(e) => setForm({ ...form, industry: e.target.value })} /></Field>
            <Field label="City"><Input value={form.city} onChange={(e) => setForm({ ...form, city: e.target.value })} /></Field>
            <Field label="Phone"><Input value={form.phone} onChange={(e) => setForm({ ...form, phone: e.target.value })} /></Field>
            <Field label="Email"><Input value={form.email} onChange={(e) => setForm({ ...form, email: e.target.value })} /></Field>
          </div>
          <Field label="Account owner"><Input value={form.accountOwner} onChange={(e) => setForm({ ...form, accountOwner: e.target.value })} /></Field>
          <div className="flex justify-end gap-2 pt-1">
            <Button variant="ghost" onClick={() => setCreateOpen(false)}>Cancel</Button>
            <Button onClick={submitCompany} disabled={saving}>{saving ? "Saving…" : "Create"}</Button>
          </div>
        </div>
      </Modal>

      <Modal open={contactOpen} onClose={() => setContactOpen(false)} title="Add a contact">
        <div className="space-y-3">
          {err && <div className="text-sm text-danger">{err}</div>}
          <Field label="Name"><Input value={contactForm.name} onChange={(e) => setContactForm({ ...contactForm, name: e.target.value })} /></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Email"><Input value={contactForm.email} onChange={(e) => setContactForm({ ...contactForm, email: e.target.value })} /></Field>
            <Field label="Phone"><Input value={contactForm.phone} onChange={(e) => setContactForm({ ...contactForm, phone: e.target.value })} /></Field>
          </div>
          <Field label="Role"><Input value={contactForm.role} onChange={(e) => setContactForm({ ...contactForm, role: e.target.value })} placeholder="Facilities, Architect, Owner…" /></Field>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" checked={contactForm.isPrimary}
              onChange={(e) => setContactForm({ ...contactForm, isPrimary: e.target.checked })} />
            This is the primary contact
          </label>
          <div className="flex justify-end gap-2 pt-1">
            <Button variant="ghost" onClick={() => setContactOpen(false)}>Cancel</Button>
            <Button onClick={submitContact} disabled={saving}>{saving ? "Saving…" : "Add contact"}</Button>
          </div>
        </div>
      </Modal>

      <Modal open={meetingOpen} onClose={() => setMeetingOpen(false)} title="Schedule a meeting">
        <div className="space-y-3">
          {err && <div className="text-sm text-danger">{err}</div>}
          <Field label="Subject"><Input value={meetingForm.title} onChange={(e) => setMeetingForm({ ...meetingForm, title: e.target.value })} /></Field>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Starts"><Input type="datetime-local" value={meetingForm.startsAt} onChange={(e) => setMeetingForm({ ...meetingForm, startsAt: e.target.value })} /></Field>
            <Field label="Minutes"><Input type="number" min={1} value={meetingForm.minutes} onChange={(e) => setMeetingForm({ ...meetingForm, minutes: e.target.value })} /></Field>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Field label="Channel">
              <Select value={meetingForm.channel} onChange={(e) => setMeetingForm({ ...meetingForm, channel: e.target.value })}>
                <option value="in-person">In person</option>
                <option value="phone">Phone</option>
                <option value="video">Video</option>
              </Select>
            </Field>
            <Field label="Location"><Input value={meetingForm.location} onChange={(e) => setMeetingForm({ ...meetingForm, location: e.target.value })} /></Field>
          </div>
          <Field label="Agenda"><Input value={meetingForm.agenda} onChange={(e) => setMeetingForm({ ...meetingForm, agenda: e.target.value })} /></Field>
          <div className="flex justify-end gap-2 pt-1">
            <Button variant="ghost" onClick={() => setMeetingOpen(false)}>Cancel</Button>
            <Button onClick={submitMeeting} disabled={saving}>{saving ? "Saving…" : "Schedule"}</Button>
          </div>
        </div>
      </Modal>

      <Modal open={noteOpen} onClose={() => setNoteOpen(false)} title="Log an activity">
        <div className="space-y-3">
          {err && <div className="text-sm text-danger">{err}</div>}
          <Field label="What kind">
            <Select value={noteForm.kind} onChange={(e) => setNoteForm({ ...noteForm, kind: e.target.value })}>
              <option value="note">Note</option>
              <option value="call">Call</option>
              <option value="email">Email</option>
              <option value="meeting">Meeting</option>
            </Select>
          </Field>
          <Field label="What happened"><Input value={noteForm.summary} onChange={(e) => setNoteForm({ ...noteForm, summary: e.target.value })} /></Field>
          <Field label="Detail (optional)"><Input value={noteForm.detail} onChange={(e) => setNoteForm({ ...noteForm, detail: e.target.value })} /></Field>
          <div className="text-xs text-muted">
            This is added to the history and cannot be edited afterwards — only added to.
          </div>
          <div className="flex justify-end gap-2 pt-1">
            <Button variant="ghost" onClick={() => setNoteOpen(false)}>Cancel</Button>
            <Button onClick={submitNote} disabled={saving}>{saving ? "Saving…" : "Log it"}</Button>
          </div>
        </div>
      </Modal>
    </>
  );
}

#!/usr/bin/env node
/**
 * CRM rehearsal — companies, contacts, meetings, attachments, activity history.
 *
 *   node scripts/test-crm.mjs
 *
 * The assertions that matter are not "it saved the row". They are:
 *
 *   1. ONE COMPANY HAS ONE RECORD. "Clifton Villa (Pvt) Ltd.", "clifton villa
 *      private limited", "CLIFTON VILLA" and "Clifton-Villa" are the same
 *      customer. The fold that decides this is the difference between a CRM and
 *      a list of strings that look alike — so it is tested with the punctuation
 *      and legal forms real intake actually produces. The first version of this
 *      fold left the brackets behind, turned "(Pvt) Ltd." into "( )" and let the
 *      duplicate through; this suite is what caught it.
 *
 *   2. OVER-FOLDING IS REFUSED TOO. "Clifton Heights" must still be creatable.
 *      Merging two real companies is worse than leaving a duplicate: a
 *      duplicate is visible, a wrong merge is not.
 *
 *   3. A REFUSED DUPLICATE NAMES THE EXISTING RECORD. A 409 that says only "no"
 *      leaves nothing to do but invent a variant spelling, which creates the
 *      duplicate it was preventing. The response carries the id.
 *
 *   4. A MEETING THAT HAPPENED SAYS WHAT CAME OF IT. Marking a meeting "held"
 *      with no outcome is refused: a calendar that records attendance and not
 *      results is a diary.
 *
 *   5. THE HISTORY CANNOT BE REWRITTEN. UPDATE and DELETE on activities are
 *      refused by database triggers. Verified by opening SQLite directly — the
 *      API offering no UPDATE would not be the same thing.
 *
 *   6. AN ATTACHMENT IS A POINTER, NOT BYTES. path and checksum, never the
 *      file; identical bytes on the same entity are refused, identical bytes on
 *      a different entity are fine.
 *
 *   7. THE TIMELINE IS COMPLETE BY CONSTRUCTION. Scheduling a meeting writes to
 *      both the meeting's history and the company's, so a company timeline does
 *      not omit the things nobody filed by hand.
 */
import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { DatabaseSync } from "node:sqlite";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const tempRoot = path.join(os.tmpdir(), `woodex-crm-${process.pid}`);
const problems = [];
function check(ok, label, detail = "") {
  if (ok) console.log(`  ✓ ${label}`);
  else { problems.push(`${label}${detail ? ` — ${detail}` : ""}`); console.log(`  ✗ ${label}${detail ? ` — ${detail}` : ""}`); }
}

let child;
try {
  fs.mkdirSync(tempRoot, { recursive: true });
  child = spawn(process.execPath, ["apps/api/server.mjs"], {
    cwd: ROOT,
    env: { ...process.env, NODE_ENV: "test", PORT: "0", WOODEX_DATA_DIR: tempRoot,
      DATABASE_URL: "", VERCEL: "", VERCEL_ENV: "", WOODEX_DEMO_SEED: "1" },
    stdio: ["ignore", "pipe", "pipe"],
  });

  let log = "";
  const base = await new Promise((resolve, reject) => {
    const timer = setTimeout(() => reject(new Error(`server did not start in 60s:\n${log.slice(-900)}`)), 60_000);
    const onData = (buf) => {
      log += String(buf);
      const m = log.match(/http:\/\/localhost:(\d+)/);
      if (m) { clearTimeout(timer); resolve(`http://127.0.0.1:${m[1]}`); }
    };
    child.stdout.on("data", onData);
    child.stderr.on("data", onData);
    child.on("exit", (code) => { clearTimeout(timer); reject(new Error(`server exited ${code}:\n${log.slice(-900)}`)); });
  });

  const api = async (p, opts = {}) => {
    const res = await fetch(base + p, {
      method: opts.method ?? "GET",
      headers: { "content-type": "application/json", ...(opts.token ? { authorization: "Bearer " + opts.token } : {}) },
      body: opts.body ? JSON.stringify(opts.body) : undefined,
    });
    let json = null;
    try { json = await res.json(); } catch { /* non-JSON is itself a finding */ }
    return { status: res.status, json };
  };

  const login = await api("/api/auth/login", { method: "POST", body: { email: "admin@woodex.pk", password: "admin" } });
  const token = login.json?.token;
  check(Boolean(token), "operator signed in", login.json?.error ?? "");

  /* ───────────────────────── companies ───────────────────────── */
  console.log("\n[crm] one company, one record");
  const first = await api("/api/companies", { method: "POST", token, body: {
    name: "Clifton Villa (Pvt) Ltd.", city: "Lahore", industry: "Residential", accountOwner: "Admin" } });
  check(first.status === 201, "a company is created", `got ${first.status} ${JSON.stringify(first.json)?.slice(0, 140)}`);
  const companyId = first.json?.company?.id;

  /* Every spelling of one customer. The brackets and hyphens are the point:
     they are what real intake produces and what a naive fold leaves behind. */
  for (const v of ["clifton villa private limited", "CLIFTON VILLA", "Clifton Villa Ltd", "Clifton-Villa", "Clifton Villa (Pvt) Ltd."]) {
    const r = await api("/api/companies", { method: "POST", token, body: { name: v } });
    check(r.status === 409, `"${v}" is recognised as the same company`, `got ${r.status}`);
  }

  const dup = await api("/api/companies", { method: "POST", token, body: { name: "Clifton Villa" } });
  check(dup.json?.companyId === companyId,
    "the refusal carries the existing id so the caller can open it", `companyId=${dup.json?.companyId}`);
  check(dup.json?.code === "company_exists", "and a machine-readable code", dup.json?.code);
  check(/Clifton Villa \(Pvt\) Ltd\./.test(dup.json?.error ?? ""),
    "and quotes the name already on file", dup.json?.error);

  /* The other direction: a different company must remain creatable. */
  const other = await api("/api/companies", { method: "POST", token, body: { name: "Clifton Heights" } });
  check(other.status === 201, "a similar-but-different company is still created", `got ${other.status}`);

  const blank = await api("/api/companies", { method: "POST", token, body: { name: "   " } });
  check(blank.status === 400, "a blank company name is refused", `got ${blank.status}`);
  const punct = await api("/api/companies", { method: "POST", token, body: { name: "(Pvt) Ltd." } });
  check(punct.status === 400, "a name that folds away to nothing is refused", `got ${punct.status}`);

  const renamed = await api(`/api/companies/${companyId}`, { method: "PATCH", token, body: { city: "Karachi" } });
  check(renamed.json?.company?.city === "Karachi", "a company can be corrected");
  const clash = await api(`/api/companies/${companyId}`, { method: "PATCH", token, body: { name: "Clifton Heights" } });
  check(clash.status === 409, "renaming onto an existing company is refused", `got ${clash.status}`);
  const noop = await api(`/api/companies/${companyId}`, { method: "PATCH", token, body: {} });
  check(noop.status === 400, "a patch that changes nothing is refused rather than taken as a success", `got ${noop.status}`);

  /* ───────────────────────── contacts ───────────────────────── */
  console.log("\n[crm] people, not strings");
  const contact = await api("/api/contacts", { method: "POST", token, body: {
    name: "Ahmed Raza", email: "Ahmed@Clifton.example", phone: "+92 300 1112223",
    role: "Facilities", companyId, isPrimary: true } });
  check(contact.status === 201, "a contact is created against a company", `got ${contact.status}`);
  check(contact.json?.contact?.companyName === "Clifton Villa (Pvt) Ltd.",
    "and reports which company it belongs to");
  const contactId = contact.json?.contact?.id;

  const ghost = await api("/api/contacts", { method: "POST", token, body: { name: "Ghost", companyId: 99999 } });
  check(ghost.status === 404, "a contact cannot point at a company that does not exist", `got ${ghost.status}`);

  const sameEmail = await api("/api/contacts", { method: "POST", token, body: { name: "Impostor", email: "AHMED@clifton.example" } });
  check(sameEmail.status === 409, "the same mailbox in different case is refused", `got ${sameEmail.status}`);
  check(sameEmail.json?.contactId === contactId, "and names the contact who owns it");

  /* A foreman with a mobile and no mailbox is the normal case on site. */
  const phoneOnly = await api("/api/contacts", { method: "POST", token, body: { name: "Site Foreman", phone: "0300 111 2223", companyId } });
  check(phoneOnly.status === 201, "a contact with no email is accepted", `got ${phoneOnly.status}`);
  const twoEmpty = await api("/api/contacts", { method: "POST", token, body: { name: "Second No-Email", companyId } });
  check(twoEmpty.status === 201, "and two contacts with no email do not collide on NULL", `got ${twoEmpty.status}`);

  const listed = await api(`/api/contacts?company_id=${companyId}`, { token });
  check(listed.json?.items?.[0]?.isPrimary === true, "the primary contact sorts first");
  check(listed.json?.total === 3, "all three contacts are listed for the company", `${listed.json?.total}`);

  /* ───────────────────────── meetings ───────────────────────── */
  console.log("\n[crm] a meeting records what came of it");
  const meeting = await api("/api/meetings", { method: "POST", token, body: {
    title: "Spec review", startsAt: "2026-11-12T10:00:00Z", channel: "video", minutes: 45, companyId } });
  check(meeting.status === 201, "a meeting is scheduled", `got ${meeting.status} ${JSON.stringify(meeting.json)?.slice(0, 140)}`);
  check(/^MTG-/.test(meeting.json?.meeting?.ref ?? ""), "it gets a human reference", meeting.json?.meeting?.ref);
  const meetingId = meeting.json?.meeting?.id;

  const noOutcome = await api(`/api/meetings/${meetingId}`, { method: "PATCH", token, body: { status: "held" } });
  check(noOutcome.status === 400, "marking it held with no outcome is refused", `got ${noOutcome.status}`);
  check(noOutcome.json?.code === "outcome_required", "with a code the UI can act on", noOutcome.json?.code);
  check(/outcome/i.test(noOutcome.json?.error ?? ""), "and a refusal that says what is missing", noOutcome.json?.error);

  const badChannel = await api("/api/meetings", { method: "POST", token, body: { title: "Walkabout", startsAt: "2026-11-13T10:00:00Z", channel: "telepathy" } });
  check(badChannel.status === 400, "an unknown channel is refused", `got ${badChannel.status}`);
  const badDate = await api("/api/meetings", { method: "POST", token, body: { title: "Whenever", startsAt: "next tuesday" } });
  check(badDate.status === 400, "an unparseable date is refused", `got ${badDate.status}`);
  const badMinutes = await api("/api/meetings", { method: "POST", token, body: { title: "Zero", startsAt: "2026-11-13T10:00:00Z", minutes: 0 } });
  check(badMinutes.status === 400, "a zero-length meeting is refused", `got ${badMinutes.status}`);
  const badStatus = await api(`/api/meetings/${meetingId}`, { method: "PATCH", token, body: { status: "maybe" } });
  check(badStatus.status === 400, "an unknown status is refused", `got ${badStatus.status}`);

  const held = await api(`/api/meetings/${meetingId}`, { method: "PATCH", token, body: { status: "held", outcome: "Approved the walnut finish" } });
  check(held.json?.meeting?.status === "held", "with an outcome it is recorded as held");
  check(held.json?.meeting?.outcome === "Approved the walnut finish", "and the outcome is kept");

  /* A site visit is not a meeting: `meetings` has no visit channel, so a visit
     must be recorded elsewhere rather than smuggled in as channel=in-person. */
  const visits = await api("/api/site-visits", { token });
  check(visits.status === 200, "site visits remain their own record, not a meeting channel");

  /* ───────────────────────── attachments ───────────────────────── */
  console.log("\n[crm] attachments are pointers");
  const att = await api("/api/attachments", { method: "POST", token, body: {
    entityType: "company", entityId: companyId, filename: "floorplan.pdf",
    mimeType: "application/pdf", sizeBytes: 248000, checksum: "sha256-abc123", path: "s3://wx/floorplan.pdf" } });
  check(att.status === 201, "a file is attached", `got ${att.status}`);
  check(att.json?.attachment?.path === "s3://wx/floorplan.pdf", "the pointer is stored");
  check(att.json?.attachment?.checksum === "sha256-abc123", "the checksum is stored");
  check(att.json?.attachment?.sizeBytes === 248000, "the size is kept as a number");

  const sameFile = await api("/api/attachments", { method: "POST", token, body: {
    entityType: "company", entityId: companyId, filename: "floorplan-v2.pdf", checksum: "sha256-abc123" } });
  check(sameFile.status === 409, "the identical file is not attached twice", `got ${sameFile.status}`);
  check(sameFile.json?.attachmentId === att.json?.attachment?.id, "and the refusal names the copy that exists");

  /* The same bytes under a DIFFERENT entity are legitimate: one drawing can
     belong to a company and to the project it describes. */
  const elsewhere = await api("/api/attachments", { method: "POST", token, body: {
    entityType: "project", entityId: 1, filename: "floorplan.pdf", checksum: "sha256-abc123" } });
  check(elsewhere.status === 201, "the same bytes under another entity are allowed", `got ${elsewhere.status}`);

  const noFilename = await api("/api/attachments", { method: "POST", token, body: { entityType: "company", entityId: companyId } });
  check(noFilename.status === 400, "an attachment with no filename is refused", `got ${noFilename.status}`);

  /* ───────────────────────── the timeline ───────────────────────── */
  console.log("\n[crm] the timeline");
  await api("/api/activities", { method: "POST", token, body: {
    entityType: "company", entityId: companyId, kind: "call",
    summary: "Called about delivery date", detail: "Wants week 46" } });

  const timeline = await api(`/api/activities?entity_type=company&entity_id=${companyId}`, { token });
  const kinds = (timeline.json?.items ?? []).map((a) => a.kind);
  check((timeline.json?.total ?? 0) >= 5, "the company timeline has the events that happened", `${timeline.json?.total}`);
  check(kinds.includes("call"), "the logged call is on it");
  check(kinds.includes("meeting"), "the scheduled meeting is on it without anyone filing it twice");
  check(kinds.includes("system"), "and the record of the company being created is on it");
  check(kinds.includes("document"), "and the attachment");

  const mt = (await api(`/api/activities?entity_type=meeting&entity_id=${meetingId}`, { token })).json?.items ?? [];
  const mk = mt.map((a) => a.kind);
  check(mk.includes("meeting") && mk.includes("status_change"),
    "the meeting has its own history, including the status change", mk.join(","));

  /* Ordering is by when things happened, not when they were recorded: a Monday
     call logged on Friday keeps Monday's place. */
  const past = new Date(Date.now() - 6 * 86400_000).toISOString();
  await api("/api/activities", { method: "POST", token, body: {
    entityType: "company", entityId: companyId, kind: "note", summary: "Older note, logged late", occurredAt: past } });
  const ordered = (await api(`/api/activities?entity_type=company&entity_id=${companyId}`, { token })).json?.items ?? [];
  check(ordered[ordered.length - 1]?.summary === "Older note, logged late",
    "a late-logged event sorts by when it happened, not when it was typed");

  const badKind = await api("/api/activities", { method: "POST", token, body: {
    entityType: "company", entityId: companyId, kind: "telegram", summary: "hello" } });
  check(badKind.status === 400, "an unknown activity kind is refused", `got ${badKind.status}`);
  const noSummary = await api("/api/activities", { method: "POST", token, body: {
    entityType: "company", entityId: companyId, kind: "note" } });
  check(noSummary.status === 400, "an activity with no summary is refused", `got ${noSummary.status}`);

  const detailRes = await api(`/api/companies/${companyId}`, { token });
  check((detailRes.json?.contacts ?? []).length === 3, "the company record lists its contacts");
  check((detailRes.json?.meetings ?? []).length === 1, "and its meetings");
  check((detailRes.json?.attachments ?? []).length === 1, "and its attachments");
  check(Array.isArray(detailRes.json?.timeline) && detailRes.json.timeline.length >= 6, "and its timeline");

  /* ───────────── append-only, proven at the database ───────────── */
  console.log("\n[crm] the history cannot be rewritten");
  const dbFile = path.join(tempRoot, "woodex.db");
  check(fs.existsSync(dbFile), "the rehearsal database exists", dbFile);
  const db = new DatabaseSync(dbFile);
  try {
    const before = db.prepare("SELECT COUNT(*) AS n FROM activities").get().n;
    let updateBlocked = false;
    try { db.prepare("UPDATE activities SET summary = 'rewritten' WHERE id = 1").run(); }
    catch (e) { updateBlocked = /append-only/i.test(String(e.message)); }
    check(updateBlocked, "the database itself refuses an UPDATE on the timeline");
    let deleteBlocked = false;
    try { db.prepare("DELETE FROM activities WHERE id = 1").run(); }
    catch (e) { deleteBlocked = /append-only/i.test(String(e.message)); }
    check(deleteBlocked, "and refuses a DELETE");
    check(db.prepare("SELECT COUNT(*) AS n FROM activities").get().n === before,
      "so no event was lost to either attempt");
    db.prepare("INSERT INTO activities(entity_type,entity_id,kind,summary,occurred_at,created_at) VALUES('company',1,'note','appended','2026-11-09T21:00:00Z','2026-11-09T21:00:00Z')").run();
    check(true, "appending a new event still works — history is added to, never edited");
  } finally { db.close(); }

  /* ───────────── leads and clients point at real companies ───────────── */
  console.log("\n[crm] leads and clients point at real companies");
  const lead = await api("/api/leads", { method: "POST", token, body: {
    name: "Hamza Sheikh", company: "Clifton Villa (Pvt) Ltd.", interest: "Kitchen",
    source: "Referral", contact: "0321 1234567" } });
  check(lead.status === 201 || lead.status === 200, "a lead can still be created from free-text intake", `got ${lead.status}`);
  const leadId = lead.json?.lead?.id ?? lead.json?.id;
  const linked = await api(`/api/leads/${leadId}`, { method: "PATCH", token, body: { companyId } });
  check(linked.status === 200 && linked.json?.companyId === companyId,
    "and pointed at the real company record later", `got ${linked.status} ${JSON.stringify(linked.json)?.slice(0, 120)}`);

  const ghostLink = await api(`/api/leads/${leadId}`, { method: "PATCH", token, body: { companyId: 99999 } });
  check(ghostLink.status === 404, "pointing it at a company that does not exist is refused", `got ${ghostLink.status}`);

  const tied = await api(`/api/companies/${companyId}`, { token });
  check((tied.json?.leads ?? []).some((l) => l.id === leadId),
    "so 'everything with this company' is a query, not a LIKE across names");

} catch (e) {
  problems.push(`suite crashed: ${e.message}`);
  console.error(e);
} finally {
  child?.kill("SIGTERM");
  fs.rmSync(tempRoot, { recursive: true, force: true });
}

console.log("");
if (problems.length) {
  console.error(`${problems.length} problem(s):`);
  for (const p of problems) console.error(`  - ${p}`);
  process.exit(1);
}
console.log("CRM rehearsal passed.");

#!/usr/bin/env node
/**
 * Support and warranty rehearsal — tickets, messages, visits, warranty claims.
 *
 *   node scripts/test-support.mjs
 *
 * The assertions that matter are not "it saved the row". They are:
 *
 *   1. THE SLA IS ARITHMETIC. Deadlines are computed from the priority at
 *      intake and frozen, so a breach cannot be made to disappear by
 *      downgrading a ticket, waiting, and upgrading it again. Proven by
 *      attempting to move the deadline at the database.
 *
 *   2. AN INTERNAL NOTE IS NOT A RESPONSE. `first_response_at` is stamped only
 *      by an outbound message; a team talking to itself must not make a ticket
 *      look answered while the customer has heard nothing.
 *
 *   3. A TICKET IS RESOLVED BEFORE IT IS CLOSED. Resolving needs a resolution
 *      and closing needs a resolution first, or nobody knows what fixed it.
 *
 *   4. A COMPLETED VISIT SAYS WHAT WAS DONE. "Completed" with no work recorded
 *      is the same as no visit at all, except that it closes the ticket.
 *
 *   5. THE WARRANTY ANSWER IS COPIED AT CLAIM TIME, and a decided claim is not
 *      reopened. A term can change; the promise made to this customer on this
 *      day cannot.
 *
 *   6. THE SUMMARY ADDS UP. What is open, what is late, and what the warranty
 *      decisions have cost.
 */
import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { DatabaseSync } from "node:sqlite";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const tempRoot = path.join(os.tmpdir(), `woodex-support-${process.pid}`);
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

  const login = await api("/api/auth/login", { method: "POST", body: { email: "admin", password: "admin" } });
  const token = login.json?.token;
  check(Boolean(token), "operator signed in", login.json?.error ?? "");

  /* An installed asset to complain about. */
  const asset = await api("/api/installed-assets", { method: "POST", token, body: {
    name: "Hinge set", room: "Kitchen", warrantyMonths: 12, installedOn: "2026-06-01" } });
  check(asset.status === 201, "there is an installed asset under warranty", `got ${asset.status}`);
  const assetId = asset.json?.asset?.id;

  /* ───────────────────────── tickets ───────────────────────── */
  console.log("\n[support] the SLA is arithmetic");
  const t1 = await api("/api/tickets", { method: "POST", token, body: {
    customer: "Gulberg Interiors", subject: "Door hinges squeaking",
    description: "Two doors scrape the frame", priority: "high", category: "fitting", assetId } });
  check(t1.status === 201, "a ticket is opened", `got ${t1.status} ${JSON.stringify(t1.json)?.slice(0, 140)}`);
  const ticket = t1.json?.ticket;
  check(/^TCK-/.test(ticket?.ref ?? ""), "with a reference", ticket?.ref);
  check(Boolean(ticket?.respondBy) && Boolean(ticket?.resolveBy), "and both deadlines set");
  check(Date.parse(ticket.resolveBy) - Date.parse(ticket.createdAt) >= 71 * 3600_000,
    "high priority resolves in three days", ticket?.resolveBy);
  check(ticket?.assetTag === asset.json?.asset?.tag, "it names the installed asset it is about", ticket?.assetTag);
  check(ticket?.waitingOn === "us", "and starts as the team's move");
  check(ticket?.firstResponseAt === null, "with no response yet — opening is not responding");
  const t1id = ticket?.id;

  const urgent = await api("/api/tickets", { method: "POST", token, body: {
    customer: "Gulberg Interiors", subject: "Water under the sink", priority: "urgent" } });
  check(Date.parse(urgent.json?.ticket?.resolveBy) - Date.parse(urgent.json?.ticket?.createdAt) <= 25 * 3600_000,
    "urgent resolves within a day", urgent.json?.ticket?.resolveBy);

  const badPriority = await api("/api/tickets", { method: "POST", token, body: {
    customer: "X", subject: "Y", priority: "whenever" } });
  check(badPriority.status === 400, "an unknown priority is refused", `got ${badPriority.status}`);
  const noSubject = await api("/api/tickets", { method: "POST", token, body: { customer: "X" } });
  check(noSubject.status === 400, "a ticket with no subject is refused", `got ${noSubject.status}`);
  const ghostAsset = await api("/api/tickets", { method: "POST", token, body: {
    customer: "X", subject: "Y", assetId: 99999 } });
  check(ghostAsset.status === 404, "a ticket cannot cite an asset that does not exist", `got ${ghostAsset.status}`);

  /* ─────────────── an internal note is not a response ─────────────── */
  console.log("\n[support] an internal note is not a response");
  const note = await api(`/api/tickets/${t1id}/messages`, { method: "POST", token, body: {
    body: "Checked stock, two hinges reserved", internal: true } });
  check(note.status === 201, "an internal note is recorded", `got ${note.status}`);
  check(note.json?.ticket?.firstResponseAt === null,
    "but it does not count as a first response — the customer has heard nothing");

  const reply = await api(`/api/tickets/${t1id}/messages`, { method: "POST", token, body: {
    body: "We can be there Thursday morning" } });
  check(Boolean(reply.json?.ticket?.firstResponseAt), "an outbound reply stamps the first response");

  const thread = await api(`/api/tickets/${t1id}`, { token });
  /* Three, not two: the description typed at intake becomes the opening
     message, so the thread reads as a conversation from the first line rather
     than starting with a reply to something nobody can see. */
  check((thread.json?.messages ?? []).length === 3, "the thread holds the opening complaint and both messages",
    `${thread.json?.messages?.length}`);
  check(thread.json?.messages?.[0]?.body === "Two doors scrape the frame",
    "starting with what the customer reported");
  check(thread.json.messages.some((m) => m.internal) && thread.json.messages.some((m) => !m.internal),
    "internal and outbound are distinguishable on it");

  const emptyBody = await api(`/api/tickets/${t1id}/messages`, { method: "POST", token, body: { body: "  " } });
  check(emptyBody.status === 400, "an empty message is refused", `got ${emptyBody.status}`);

  /* ────────────────── resolve, then close ────────────────── */
  console.log("\n[support] resolved before closed");
  const resolveBare = await api(`/api/tickets/${t1id}`, { method: "PATCH", token, body: { status: "resolved" } });
  check(resolveBare.status === 400, "resolving with no resolution is refused", `got ${resolveBare.status}`);
  check(resolveBare.json?.code === "resolution_required", "and says what is missing", resolveBare.json?.code);

  const closedEarly = await api(`/api/tickets/${urgent.json.ticket.id}`, { method: "PATCH", token, body: { status: "closed" } });
  check(closedEarly.status === 409, "closing an unresolved ticket is refused", `got ${closedEarly.status}`);
  check(closedEarly.json?.code === "not_resolved", "with a reason", closedEarly.json?.code);

  const resolved = await api(`/api/tickets/${t1id}`, { method: "PATCH", token, body: {
    status: "resolved", resolution: "Adjusted both hinges and replaced the screws" } });
  check(resolved.json?.ticket?.status === "resolved", "with a resolution it resolves");
  check(Boolean(resolved.json?.ticket?.resolvedAt), "and is timestamped");
  const closed = await api(`/api/tickets/${t1id}`, { method: "PATCH", token, body: { status: "closed" } });
  check(closed.json?.ticket?.status === "closed", "and then closes");

  const writeClosed = await api(`/api/tickets/${t1id}/messages`, { method: "POST", token, body: { body: "one more thing" } });
  check(writeClosed.status === 409, "writing to a closed ticket is refused", `got ${writeClosed.status}`);
  check(writeClosed.json?.code === "ticket_closed", "because reopening must be visible", writeClosed.json?.code);

  /* waiting_on distinguishes "blocked on the customer" from "ignored by us". */
  const waiting = await api(`/api/tickets/${urgent.json.ticket.id}`, { method: "PATCH", token, body: {
    status: "waiting", waitingOn: "them" } });
  check(waiting.json?.ticket?.waitingOn === "them", "a ticket can wait on the customer");

  /* ───────────────────── service visits ───────────────────── */
  console.log("\n[support] a completed visit says what was done");
  const visit = await api("/api/service-visits", { method: "POST", token, body: {
    ticketId: t1id, technician: "Nadeem", scheduledFor: "2026-11-12T09:00:00Z" } });
  check(visit.status === 201, "a visit is scheduled", `got ${visit.status} ${JSON.stringify(visit.json)?.slice(0, 140)}`);
  check(/^VIS-/.test(visit.json?.visit?.ref ?? ""), "with a reference", visit.json?.visit?.ref);
  const visitId = visit.json?.visit?.id;

  const completeBare = await api(`/api/service-visits/${visitId}`, { method: "PATCH", token, body: { status: "completed" } });
  check(completeBare.status === 400, "completing a visit with no work recorded is refused", `got ${completeBare.status}`);
  check(completeBare.json?.code === "work_done_required", "and asks for it", completeBare.json?.code);

  const completed = await api(`/api/service-visits/${visitId}`, { method: "PATCH", token, body: {
    status: "completed", workDone: "Adjusted hinges, replaced two screws" } });
  check(completed.json?.visit?.status === "completed", "with a note it completes");
  check(Boolean(completed.json?.visit?.arrivedAt), "and the arrival is stamped too");
  check(completed.json?.visit?.workDone === "Adjusted hinges, replaced two screws", "the work is kept as written");

  const visitOnGhost = await api("/api/service-visits", { method: "POST", token, body: { ticketId: 99999 } });
  check(visitOnGhost.status === 404, "a visit cannot be filed against a ticket that does not exist", `got ${visitOnGhost.status}`);

  /* ──────────────────── warranty claims ──────────────────── */
  console.log("\n[support] the warranty answer is copied at claim time");
  const claim = await api("/api/warranty-claims", { method: "POST", token, body: {
    customer: "Gulberg Interiors", assetId, fault: "Hinge snapped", part: "Hinge", ticketId: t1id } });
  check(claim.status === 201, "a claim is opened", `got ${claim.status} ${JSON.stringify(claim.json)?.slice(0, 140)}`);
  check(claim.json?.claim?.verdict === "pending", "and starts undecided");
  check(claim.json?.claim?.warrantyUntilOnClaim === asset.json?.asset?.warrantyUntil,
    "with the warranty date copied from the asset", `${claim.json?.claim?.warrantyUntilOnClaim}`);
  const claimId = claim.json?.claim?.id;

  const noReason = await api(`/api/warranty-claims/${claimId}`, { method: "PATCH", token, body: { verdict: "covered" } });
  check(noReason.status === 400, "a verdict with no reason is refused", `got ${noReason.status}`);
  check(noReason.json?.code === "reason_required", "and says why", noReason.json?.code);

  const decided = await api(`/api/warranty-claims/${claimId}`, { method: "PATCH", token, body: {
    verdict: "covered", reason: "Hinge failed inside the 12-month term", costToWooodex: 2500 } });
  check(decided.json?.claim?.verdict === "covered", "with a reason it is decided");
  check(Boolean(decided.json?.claim?.decidedOn), "on a recorded date");
  check(decided.json?.claim?.costToWooodex === 2500, "with the cost recorded because Woodex bore it");

  const reopened = await api(`/api/warranty-claims/${claimId}`, { method: "PATCH", token, body: {
    verdict: "not_covered", reason: "changed my mind" } });
  check(reopened.status === 409, "a decided claim is not reopened — a new claim is raised", `got ${reopened.status}`);
  check(reopened.json?.code === "claim_decided", "and says so", reopened.json?.code);

  const goodwill = await api("/api/warranty-claims", { method: "POST", token, body: {
    customer: "Gulberg Interiors", fault: "Water mark on worktop" } });
  const goodwillOk = await api(`/api/warranty-claims/${goodwill.json.claim.id}`, { method: "PATCH", token, body: {
    verdict: "goodwill", reason: "Out of term but small", costToWooodex: 500 } });
  check(goodwillOk.json?.claim?.verdict === "goodwill", "goodwill is a separate verdict from covered");
  check(goodwillOk.json?.claim?.warrantyUntilOnClaim === null,
    "and a claim with no asset records no warranty date rather than inventing one");

  /* ─────────────── the SLA deadline is frozen ─────────────── */
  console.log("\n[support] the SLA deadline is frozen");
  const dbFile = path.join(tempRoot, "woodex.db");
  const db = new DatabaseSync(dbFile);
  try {
    const original = db.prepare("SELECT resolve_by FROM tickets WHERE id = ?").get(urgent.json.ticket.id).resolve_by;
    let blocked = false, message = "";
    try {
      db.prepare("UPDATE tickets SET resolve_by = ? WHERE id = ?").run("2030-01-01T00:00:00.000Z", urgent.json.ticket.id);
    } catch (e) { blocked = /SLA deadline/i.test(String(e.message)); message = String(e.message); }
    check(blocked, "the database refuses to move an SLA deadline", message.slice(0, 70));
    const kept = db.prepare("SELECT resolve_by FROM tickets WHERE id = ?").get(urgent.json.ticket.id).resolve_by;
    check(kept === original, "so the promise made at intake survives");
    /* Re-raising the priority is still allowed — the deadline simply does not move. */
    db.prepare("UPDATE tickets SET priority = 'high' WHERE id = ?").run(urgent.json.ticket.id);
    const stillKept = db.prepare("SELECT resolve_by FROM tickets WHERE id = ?").get(urgent.json.ticket.id).resolve_by;
    check(stillKept === original, "and raising the priority does not move it either");
  } finally { db.close(); }

  /* ──────────────────────── the summary ──────────────────────── */
  console.log("\n[support] the Monday number");
  const summary = await api("/api/support/summary", { token });
  const s = summary.json;
  check(typeof s?.openTickets === "number", "the summary reports open tickets", JSON.stringify(s)?.slice(0, 140));
  check(s.openTickets >= 1, "counting the one still open", `${s?.openTickets}`);
  check(s.unclaimedDecisions === 0, "no undecided claims are left", `${s?.unclaimedDecisions}`);
  check(s.coveredCost === 3000, "and the covered cost adds up (2500 covered + 500 goodwill)", `${s?.coveredCost}`);
  check(s.visitsByStatus?.completed === 1, "with the visit counted by status", JSON.stringify(s?.visitsByStatus));

  const openOnly = await api("/api/tickets?open=1", { token });
  check((openOnly.json?.items ?? []).every((t) => ["open", "in_progress", "waiting"].includes(t.status)),
    "the open filter returns only open work");

  /* ───────────── the asset's own history hears about it ───────────── */
  const assetDetail = await api(`/api/installed-assets/${assetId}`, { token });
  const assetTimeline = assetDetail.json?.timeline ?? [];
  check(assetTimeline.some((a) => String(a.summary).startsWith("Reported fault")),
    "the fault is on the installed asset's own history", `${assetTimeline.length} entries`);

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
console.log("Support rehearsal passed.");

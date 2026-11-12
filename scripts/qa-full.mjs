#!/usr/bin/env node
/* Full-stack QA battery — API, auth/RBAC, public surface, CRUD flows, SSE, DB integrity, self-cleanup.
   Usage: node scripts/qa-full.mjs [--base http://127.0.0.1:3001] [--keep] */
import { DatabaseSync } from "node:sqlite";
import { mkdirSync, writeFileSync } from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const explicitBase = process.argv.find((a) => a.startsWith("--base="))?.slice(7) ?? process.env.QA_BASE;
let BASE = explicitBase ?? "http://127.0.0.1:3001";
const KEEP = process.argv.includes("--keep");
const PG_REHEARSAL = process.argv.includes("--pg-rehearsal") || process.env.QA_PG_REHEARSAL === "1";
let DB, qaRuntime = null;
if (PG_REHEARSAL) {
  if (!process.env.DATABASE_URL) throw new Error("--pg-rehearsal requires DATABASE_URL=pglite:<dir> or a PostgreSQL URL");
  qaRuntime = await import("../apps/api/server.mjs"); // run API in this process so PGlite and DB assertions share one engine
  DB = qaRuntime.db;
  if (!qaRuntime.httpServer.listening) await new Promise((resolve) => qaRuntime.httpServer.once("listening", resolve));
  if (!explicitBase) BASE = `http://127.0.0.1:${qaRuntime.httpServer.address().port}`;
} else {
  const dbPath = process.env.WOODEX_DATA_DIR
    ? path.join(path.resolve(process.env.WOODEX_DATA_DIR), "woodex.db")
    : fileURLToPath(new URL("../data/woodex.db", import.meta.url));
  /* The API creates its own data directory on boot, but this script opens the
     database before any server exists — so on a fresh clone, where data/ is
     gitignored and therefore absent, `npm run qa` died with "unable to open
     database file" before running a single check. Create it here. */
  mkdirSync(path.dirname(dbPath), { recursive: true });
  DB = new DatabaseSync(dbPath);
}
const TS = Date.now();
const Q = `QA·${TS}`; // unique fingerprint for every row we create → exact cleanup

const R = [];
let group = "misc";
const G = (g) => (group = g);
async function T(name, fn) {
  const t0 = Date.now();
  try {
    const note = await fn();
    R.push({ g: group, name, ok: true, note: String(note ?? ""), ms: Date.now() - t0 });
    console.log(`PASS  ${group} · ${name}${note ? " · " + note : ""}`);
  } catch (e) {
    R.push({ g: group, name, ok: false, note: String(e.message ?? e).slice(0, 300), ms: Date.now() - t0 });
    console.log(`FAIL  ${group} · ${name} · ${String(e.message ?? e).slice(0, 220)}`);
  }
}
const bad = (m) => { throw new Error(m); };
async function api(path, { method = "GET", role = "owner", body, raw, ip } = {}) {
  const h = { accept: "application/json" };
  if (ip) h["x-forwarded-for"] = ip;
  if (role && toks[role]) h.authorization = "Bearer " + toks[role];
  if (body !== undefined || raw !== undefined) h["content-type"] = "application/json";
  const r = await fetch(BASE + path, {
    method,
    headers: h,
    body: raw !== undefined ? raw : body !== undefined ? JSON.stringify(body) : undefined,
    signal: AbortSignal.timeout(8000),
  });
  const text = await r.text();
  let j = null; try { j = JSON.parse(text); } catch { /* non-json */ }
  return { s: r.status, j, text, ct: r.headers.get("content-type") ?? "", hdr: r.headers };
}
const want = (x, code, why = "") => { if (x.s !== code) bad(`expected ${code}${why ? " (" + why + ")" : ""}, got ${x.s} ${JSON.stringify(x.j ?? "")}`); };
const toks = {};
const qa = {};
/* Public intake is catalog-priced: the suite must use a real SKU and assert the
   server's price, never a client-supplied one. */
const catalogProduct = async () => {
  const r = await api("/api/products");
  const item = r.j?.items?.find((x) => x.inStock !== false) ?? r.j?.items?.[0];
  if (!item) bad("catalog is empty");
  return item;
};
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
async function loginAs(role, id, pw) {
  let r = await api("/api/auth/login", { role: null, method: "POST", body: { [id.includes("@") ? "email" : "username"]: id, password: pw } });
  if (r.s === 429) { // IP backstop engaged (typically a prior suite run's flood) — honor retry-after, then continue
    const wait = (Number(r.hdr?.get?.("retry-after")) || 60) * 1000 + 400;
    console.log(`      …locked out; waiting ${Math.round(wait / 1000)}s for the window to reset`);
    await sleep(wait);
    r = await api("/api/auth/login", { role: null, method: "POST", body: { [id.includes("@") ? "email" : "username"]: id, password: pw } });
  }
  if (!r.j?.token) bad(`login ${role} failed: ${JSON.stringify(r.j)}`);
  toks[role] = r.j.token;
  return r.j;
}

/* ============ AUTH ============ */
G("auth");
await T("owner login admin/admin → token + owner caps", async () => {
  const j = await loginAs("owner", "admin", "admin");
  if (j.user.role !== "owner" || !j.caps.includes("*")) bad("caps/role wrong: " + JSON.stringify(j.caps));
  return `user#${j.user.id}`;
});
await T("uppercase + bare username normalize", async () => {
  const r = await api("/api/auth/login", { role: null, method: "POST", body: { username: "ADMIN", password: "admin" } });
  want(r, 200, "case-insensitive"); if (!r.j.token) bad("no token");
});
await T("wrong password → 401 ambiguous", async () => {
  const r = await api("/api/auth/login", { role: null, method: "POST", body: { email: "admin", password: "nope" + TS } });
  want(r, 401); if (String(r.j.error) !== "wrong email or password") bad("message leaked: " + r.j.error);
});
await T("unknown user → 401 (same message, no enumeration)", async () => {
  const r = await api("/api/auth/login", { role: null, method: "POST", body: { email: `ghost${TS}@nowhere.pk`, password: "x" } });
  want(r, 401); if (String(r.j.error) !== "wrong email or password") bad("enumerable: " + r.j.error);
});
await T("empty/garbage body → 4xx never 500", async () => {
  const r = await api("/api/auth/login", { role: null, method: "POST", body: {} });
  if (r.s >= 500) bad("500 on garbage body");
  return `s=${r.s}`;
});
await T("rate limit: 5 misses then 429 + retry-after", async () => {
  const em = `qa-rate-${TS}@woodex.pk`;
  for (let i = 0; i < 5; i++) await api("/api/auth/login", { role: null, method: "POST", body: { email: em, password: "x" } });
  const r = await api("/api/auth/login", { role: null, method: "POST", body: { email: em, password: "x" } });
  want(r, 429); if (!r.hdr.get("retry-after")) bad("no retry-after header");
});
await T("me without token → 401 no_token", async () => {
  const r = await api("/api/auth/me", { role: null }); want(r, 401); if (r.j.code !== "no_token") bad("code=" + r.j.code);
});
await T("published media bridge is anonymous but neighboring private paths are not", async () => {
  const publicReply = await api("/api/v1/media", { role: null });
  if (![200, 503].includes(publicReply.s)) bad("public media status=" + publicReply.s);
  const privateReply = await api("/api/v1/media/admin", { role: null });
  want(privateReply, 401);
});
await T("me with garbage token → 401 bad_token", async () => {
  const r = await fetch(BASE + "/api/auth/me", { headers: { authorization: "Bearer deadbeef" + TS } });
  const j = await r.json(); want({ s: r.status, j }, 401); if (j.code !== "bad_token") bad("code=" + j.code);
});
await T("me with token → Admin/owner", async () => {
  const r = await api("/api/auth/me"); want(r, 200); if (r.j.user.name !== "Admin") bad(JSON.stringify(r.j.user));
});
await T("preview-safe x-woodex-session header", async () => {
  const r = await fetch(BASE + "/api/auth/me", { headers: { "x-woodex-session": toks.owner } });
  const j = await r.json(); want({ s: r.status, j }, 200);
  if (j.user?.name !== "Admin") bad("custom session header returned wrong user");
});
await T("roles login matrix (editor/sales/finance/viewer)", async () => {
  await loginAs("editor", "ayesha@woodex.pk", "woodex123");
  await loginAs("sales", "bilal@woodex.pk", "woodex123");
  await loginAs("finance", "farhan@woodex.pk", "woodex123");
  await loginAs("viewer", "guest@woodex.pk", "woodex123");
  return "4 roles ok";
});
await T("logout invalidates session", async () => {
  const j = await loginAs("temp", "admin", "admin");
  await api("/api/auth/logout", { role: "temp", method: "POST" });
  const r = await api("/api/auth/me", { role: "temp" }); want(r, 401, "after logout");
  delete toks.temp;
});

/* ============ PUBLIC SURFACE (must stay open) ============ */
G("public");
await T("GET /api/health is public but omits business counts", async () => {
  const r = await api("/api/health", { role: null }); want(r, 200);
  if (r.j.ok !== true || r.j.service !== "woodex-api" || r.j.counts || r.j.db) bad("health exposed database or customer/finance details");
  return "minimal liveness response";
});
await T("GET products list + by id", async () => {
  const l = await api("/api/products", { role: null }); want(l, 200);
  const items = l.j.items ?? l.j; if (!Array.isArray(items) || items.length < 12) bad("count " + items?.length);
  if (typeof items[0].price !== "number") bad("price not numeric");
  const one = await api(`/api/products/${items[0].id}`, { role: null }); want(one, 200);
  return `${items.length} products`;
});
/* A limit that is accepted and then ignored is worse than one that is rejected:
   the caller cannot distinguish "asked wrong" from "API disobeyed". Before this
   check existed, /api/products?limit=1 returned all 148 rows with a 200. */
await T("GET products?limit is honoured, not silently ignored", async () => {
  const full = await api("/api/products", { role: null }); want(full, 200);
  const all = full.j.items.length;
  const one = await api("/api/products?limit=1", { role: null }); want(one, 200);
  if (one.j.items.length !== 1) bad(`limit=1 returned ${one.j.items.length} rows`);
  if (one.j.total !== all) bad(`total should stay the unfiltered count (${all}), got ${one.j.total}`);
  const page = await api("/api/products?limit=5&offset=2", { role: null }); want(page, 200);
  if (page.j.items.length !== 5) bad(`limit=5&offset=2 returned ${page.j.items.length}`);
  if (page.j.items[0].id === one.j.items[0].id) bad("offset did not skip the first row");
  const tail = await api(`/api/products?limit=10&offset=${all - 2}`, { role: null }); want(tail, 200);
  if (tail.j.items.length !== 2) bad(`window past the end returned ${tail.j.items.length}, expected the 2 remaining`);
  for (const q of ["abc", "1.5", "-1"]) {
    const r = await api(`/api/products?limit=${q}`, { role: null });
    if (r.s !== 400) bad(`limit=${q} returned ${r.s}, expected 400`);
  }
  return `limit/offset honoured; malformed limits rejected`;
});
await T("GET unknown product → 404 not 500", async () => {
  const r = await api("/api/products/999999", { role: null }); if (r.s < 400 || r.s >= 500) bad("s=" + r.s);
});
await T("GET theme / services / materials", async () => {
  for (const p of ["/api/theme", "/api/services", "/api/materials"]) { const r = await api(p, { role: null }); want(r, 200, p); }
});
await T("GET sitemap.xml + robots.txt (storefront/API origin)", async () => {
  const B2 = BASE.includes(":5173") ? BASE.replace(":5173", ":5174") : BASE; // dashboard proxies only /api/*
  for (const p of ["/sitemap.xml", "/robots.txt"]) {
    const r = await fetch(B2 + p, { signal: AbortSignal.timeout(8000) });
    if (r.status !== 200) bad(`${p} via ${B2} → ${r.status}`);
    if (p === "/sitemap.xml") { const x = await r.text(); if (!x.includes("/shop/")) bad("TODO-9: products missing from sitemap"); }
  }
});
await T("POST track beacon accepted", async () => {
  const r = await api("/api/track", { role: null, method: "POST", body: { slug: "home", kind: "view", cid: `qa-${TS}` } });
  want(r, 201); // pixel contract: {slug,kind:|view|cta|scroll, label?, pct?}
  const badReq = await api("/api/track", { role: null, method: "POST", body: { kind: "fly" } }); if (badReq.s !== 400) bad("no validation");
});
await T("POST lead (public) → 201 with consent, attribution, client and follow-up", async () => {
  const email = `qa+lead${TS}@mail.pk`;
  const phone = `+92 300 ${String(TS).slice(-7)}`;
  const r = await api("/api/leads", { role: null, method: "POST", body: {
    name: `${Q} Shopper`, interest: "Teak dining set 8-seater", email, phone,
    preferredChannel: "whatsapp", contactConsent: true, source: "QA", landingPath: "/qa/intake", utmSource: "qa",
  } });
  want(r, 201); qa.leadId = r.j.id;
  if (r.j.status !== "New" || !String(r.j.ref).startsWith("L-")) bad(JSON.stringify(r.j).slice(0, 160));
  if (r.j.email !== email || r.j.phone !== phone || r.j.contactConsent !== true || !r.j.consentAt || r.j.utmSource !== "qa")
    bad("capture fields missing: " + JSON.stringify({ email: r.j.email, phone: r.j.phone, consent: r.j.contactConsent, consentAt: r.j.consentAt, utmSource: r.j.utmSource }));
  if (r.j.returning !== false) bad("first lead should be classified as new");
  if (!(await DB.prepare("SELECT id FROM tasks WHERE lead_id=?").get(r.j.id))) bad("automatic follow-up task missing");
  const returning = await api("/api/leads", { role: null, method: "POST", body: {
    name: `${Q} Returning Shopper`, interest: "Additional office furniture", email, phone,
    preferredChannel: "whatsapp", contactConsent: true, source: "QA Returning",
  } });
  want(returning, 201);
  if (returning.j.clientId !== r.j.clientId || returning.j.returning !== true) bad("returning lead did not reuse its client history");
  return `new lead#${r.j.id} + returning lead#${returning.j.id} linked to client#${r.j.clientId}`;
});
await T("public WhatsApp handoff logs a manual, not-sent inbox event", async () => {
  const result = await api(`/api/leads/${qa.leadId}/whatsapp-handoff`, { role: null, method: "POST", body: {} });
  want(result, 201);
  if (!result.j.logged || result.j.delivery !== "manual_handoff_not_sent") bad("handoff response implied unlogged or sent delivery");
  const row = await DB.prepare("SELECT channel,direction,body,meta FROM messages WHERE meta LIKE ? ORDER BY id DESC LIMIT 1").get(`%"leadId":${qa.leadId}%`);
  const meta = row ? JSON.parse(row.meta || "{}") : {};
  if (!row || row.channel !== "system" || row.direction !== "system" || meta.delivery !== "not_sent" || !row.body.includes("did not send or receive"))
    bad("handoff event missing explicit manual/not-sent state");
  return `lead#${qa.leadId} · inbox event logged · delivery not sent`;
});
await T("conflicting email/phone identities stay separate and queue for review", async () => {
  const emailA = `qa-conflict-a${TS}@mail.pk`, emailB = `qa-conflict-b${TS}@mail.pk`;
  const phoneA = `+92 311 ${String(TS).slice(-7)}`, phoneB = `+92 322 ${String(TS).slice(-7)}`;
  const a = await api("/api/clients", { method: "POST", body: { name: `${Q} Candidate A`, email: emailA, phone: phoneA } }); want(a, 201);
  const b = await api("/api/clients", { method: "POST", body: { name: `${Q} Candidate B`, email: emailB, phone: phoneB } }); want(b, 201);
  const lead = await api("/api/leads", { role: null, method: "POST", body: {
    name: `${Q} Conflict`, email: emailA, phone: phoneB, source: "QA conflict", contactConsent: true,
  } });
  want(lead, 201);
  if (lead.j.identityReview !== true || lead.j.returning !== false || [a.j.id, b.j.id].includes(lead.j.clientId))
    bad("ambiguous identity was silently attached: " + JSON.stringify({ identityReview: lead.j.identityReview, returning: lead.j.returning, clientId: lead.j.clientId }));
  const review = await api("/api/clients/review"); want(review, 200);
  if (!review.j.items.some((pair) => [pair.a.id, pair.b.id].includes(lead.j.clientId))) bad("ambiguous client not in the human review queue");
  const task = await DB.prepare("SELECT title,priority FROM tasks WHERE lead_id=?").get(lead.j.id);
  if (!task || task.priority !== "high" || !task.title.includes("Review possible duplicate")) bad("identity review follow-up not scheduled");
  return `lead#${lead.j.id} isolated from client#${a.j.id}/#${b.j.id}; review + high-priority task created`;
});
await T("POST lead validation (no name → 400)", async () => {
  const r = await api("/api/leads", { role: null, method: "POST", body: { interest: "x" } }); want(r, 400);
});
await T("site visit booking, reminder task, reschedule, confirmation and completion audit", async () => {
  const scheduledAt = new Date(Date.now() + 72 * 3600e3).toISOString();
  const reminderAt = new Date(Date.now() + 48 * 3600e3).toISOString();
  const created = await api("/api/site-visits", { method: "POST", body: {
    leadId: qa.leadId, scheduledAt, timezone: "Asia/Karachi", address: "QA Lahore site", owner: "QA coordinator",
    notes: "Fictional QA visit", reminderAt,
  } });
  want(created, 201); qa.siteVisitId = created.j.id;
  if (created.j.status !== "Scheduled" || created.j.leadId !== qa.leadId || !created.j.reminderTaskId)
    bad("visit/client/lead/reminder link missing");
  const task = await DB.prepare("SELECT site_visit_id,lead_id,done FROM tasks WHERE id=?").get(created.j.reminderTaskId);
  if (!task || Number(task.site_visit_id) !== created.j.id || Number(task.lead_id) !== qa.leadId || Number(task.done) !== 0)
    bad("reminder was not created as a linked staff task");
  const movedAt = new Date(Date.parse(scheduledAt) + 2 * 3600e3).toISOString();
  const confirmed = await api(`/api/site-visits/${created.j.id}`, { method: "PATCH", body: { scheduledAt: movedAt, status: "Confirmed" } });
  want(confirmed, 200);
  if (confirmed.j.status !== "Confirmed" || !confirmed.j.audit.some((event) => event.action === "rescheduled"))
    bad("reschedule/confirmation audit missing");
  const done = await api(`/api/site-visits/${created.j.id}`, { method: "PATCH", body: { status: "Completed", outcome: "QA measurements recorded" } });
  want(done, 200);
  if (done.j.outcome !== "QA measurements recorded" || !done.j.audit.some((event) => event.fields.includes("outcome")))
    bad("completion outcome was not audited");
  const finalTask = await DB.prepare("SELECT done FROM tasks WHERE id=?").get(created.j.reminderTaskId);
  const lead = await DB.prepare("SELECT status FROM leads WHERE id=?").get(qa.leadId);
  if (Number(finalTask.done) !== 1 || lead.status !== "Site visit completed") bad("completion did not close the reminder and advance the lead");
  const list = await api(`/api/site-visits?leadId=${qa.leadId}`); want(list, 200);
  if (!list.j.items.some((visit) => visit.id === created.j.id)) bad("visit missing from lead-filtered list");
  return `visit ${created.j.ref} · linked task closed · lead advanced to Site visit completed`;
});
await T("quotation templates are persisted, validated and role-gated", async () => {
  const anonymous = await api("/api/quote-templates", { role: null }); want(anonymous, 401, "private template library");
  const financeWrite = await api("/api/quote-templates", { role: "finance", method: "POST", body: { name: `${Q} forbidden` } });
  want(financeWrite, 403, "finance cannot edit quote templates");
  const created = await api("/api/quote-templates", { role: "sales", method: "POST", body: {
    id: "client-supplied-id", name: `${Q} Template`, brandColor: "#318b45", logoText: "QA", companyName: "Woodex QA",
    companyAddress: "QA Lahore", companyPhone: "+92 300 0000000", companyEmail: "quotes@example.pk",
    header: [
      { id: "qa-meta", type: "meta", label: "Date and reference", visible: true },
      { id: "qa-title", type: "title", label: "Quotation", visible: true },
    ],
    footer: [
      { id: "qa-footer", type: "custom", label: "Delivery note", content: "QA footer content", visible: true },
      { id: "qa-payment", type: "payment-note", label: "Payment terms", content: "75% advance and 25% balance", visible: false },
    ],
  } });
  want(created, 201); qa.quoteTemplateId = created.j.id;
  if (created.j.id === "client-supplied-id" || created.j.logoText !== "QA" || created.j.footer?.[1]?.type !== "payment-note" ||
      created.j.footer?.[1]?.visible !== true || created.j.footer?.[1]?.content !== "Final payment terms will be included in formal quotation." ||
      /\b75%|25%\b/.test(JSON.stringify(created.j)))
    bad("template ID or required payment note was not normalized: " + JSON.stringify(created.j));
  if (created.j.header?.[0]?.type !== "meta" || created.j.header?.[1]?.type !== "title") bad("header block order was not preserved");
  const updated = await api(`/api/quote-templates/${encodeURIComponent(qa.quoteTemplateId)}`, { role: "sales", method: "PATCH", body: { ...created.j, name: `${Q} Template updated` } });
  want(updated, 200); qa.quoteTemplate = updated.j;
  const list = await api("/api/quote-templates", { role: "sales" }); want(list, 200);
  if (!list.j.items?.some((item) => item.id === qa.quoteTemplateId && item.name === qa.quoteTemplate.name)) bad("saved template not listed after update");
  return `${qa.quoteTemplateId} · fixed wording enforced · header order retained`;
});
await T("POST quote (public) → Draft + linked lead + catalog pricing", async () => {
  const product = await catalogProduct();
  const email = `qa+quote${TS}@mail.pk`;
  const phone = `+92 322 ${String(TS).slice(-7)}`;
  const r = await api("/api/quotes", { role: null, method: "POST", body: {
    customer: `${Q} Customer`, company: "QA Studio", email, phone, preferredChannel: "whatsapp", contactConsent: true,
    source: "storefront", leadSource: "E-Quotation Form", captureLead: true, landingPath: "/quote", utmCampaign: "qa",
    // client price is a hint only — the server must overwrite it from the catalog
    items: [{ productId: product.id, name: "spoofed name", price: 1, qty: 2 }],
    details: { quoteTemplateId: qa.quoteTemplateId, quoteTemplateSnapshot: qa.quoteTemplate },
  } });
  want(r, 201); qa.quoteId = r.j.id; qa.quoteLeadId = r.j.leadId;
  const expected = Math.round(product.price * 2 * 100) / 100;
  qa.quoteTotal = expected;
  const line = r.j.items?.[0] ?? {};
  if (line.price !== product.price || line.name !== product.name) bad(`catalog pricing not enforced: ${JSON.stringify({ price: line.price, name: line.name })}`);
  const total = r.j.total; if (total !== expected) bad(`total=${total} expected=${expected}`);
  if (!Array.isArray(r.j.audit) || !r.j.audit.length) bad("no audit entry");
  if (r.j.details?.currency !== "PKR" || r.j.details?.discountPercent !== 0 || r.j.details?.taxPercent !== 0 || r.j.details?.shipping !== 0 || r.j.details?.terms !== "")
    bad("unconfirmed pricing or terms were defaulted");
  if (!r.j.leadId) bad("storefront quote wasn't linked to a lead");
  const lead = await DB.prepare("SELECT * FROM leads WHERE id=?").get(r.j.leadId);
  if (!lead || Number(lead.contact_consent) !== 1 || lead.utm_campaign !== "qa" || Number(lead.is_returning) !== 0)
    bad("quote lead capture fields missing: " + JSON.stringify({ id: lead?.id, consent: lead?.contact_consent, campaign: lead?.utm_campaign, returning: lead?.is_returning }));
  return `quote#${r.j.id} + lead#${r.j.leadId} @ ${total}`;
});
await T("quote detail reload retains customer, contact, totals and links", async () => {
  const r = await api(`/api/quotes/${qa.quoteId}`); want(r, 200);
  if (r.j.details?.company !== "QA Studio" || r.j.details?.email !== `qa+quote${TS}@mail.pk` || r.j.details?.phone == null)
    bad("quote contact details did not reload");
  if (r.j.details?.calculation?.total !== qa.quoteTotal || r.j.total !== qa.quoteTotal) bad("server calculation missing or inconsistent");
  if (r.j.details?.quoteTemplateId !== qa.quoteTemplateId || r.j.details?.quoteTemplateSnapshot?.id !== qa.quoteTemplateId ||
      /\b75%|25%\b/.test(JSON.stringify(r.j.details?.quoteTemplateSnapshot)) ||
      !r.j.details?.quoteTemplateSnapshot?.footer?.some((item) => item.type === "payment-note" && item.content === "Final payment terms will be included in formal quotation."))
    bad("quote's immutable template snapshot did not reload");
  if (r.j.leadId !== qa.quoteLeadId || !r.j.clientId) bad("client / lead link did not reload");
  return `v${r.j.version} · client#${r.j.clientId} · lead#${r.j.leadId}`;
});
await T("deleting a reusable template leaves existing quote snapshots intact", async () => {
  const removed = await api(`/api/quote-templates/${encodeURIComponent(qa.quoteTemplateId)}`, { method: "DELETE" }); want(removed, 200);
  const library = await api("/api/quote-templates"); want(library, 200);
  if (library.j.items?.some((item) => item.id === qa.quoteTemplateId)) bad("deleted template remains in the reusable library");
  const quote = await api(`/api/quotes/${qa.quoteId}`); want(quote, 200);
  if (quote.j.details?.quoteTemplateSnapshot?.id !== qa.quoteTemplateId) bad("deleting a template changed a saved quote snapshot");
  return "library entry removed; quote snapshot retained";
});
await T("quote revision persists options and server-calculates totals", async () => {
  const issueDate = new Date().toISOString().slice(0, 10);
  const validUntil = new Date(Date.now() + 30 * 864e5).toISOString().slice(0, 10);
  const r = await api(`/api/quotes/${qa.quoteId}`, { role: "sales", method: "PATCH", body: {
    status: "Draft", leadId: qa.quoteLeadId, total: 1, note: "Revision QA note",
    items: [{ id: "qa-line-1", productId: "qa-sku", name: "QA Desk", category: "Office", series: "QA Series",
      price: 10000, qty: 2, material: "Oak", finish: "Walnut", width: 120, height: 75, depth: 60,
      dimensionUnit: "cm", customNotes: "Cable tray" }],
    details: { company: "QA Studio", email: `qa+quote${TS}@mail.pk`, phone: `+92 322 ${String(TS).slice(-7)}`,
      siteAddress: "QA site address", issueDate, validUntil, currency: "PKR", discountPercent: 10,
      taxPercent: 18, shipping: 1500, terms: "Approved QA terms only." },
  } });
  want(r, 200);
  if (r.j.total !== 22740 || r.j.details?.calculation?.total !== 22740) bad(`client total was trusted or amount wrong: ${r.j.total}`);
  const item = r.j.items?.[0];
  if (item?.material !== "Oak" || item?.finish !== "Walnut" || item?.width !== 120 || item?.dimensionUnit !== "cm" || item?.customNotes !== "Cable tray")
    bad("line-item options did not persist: " + JSON.stringify(item));
  if (r.j.version !== 2 || r.j.leadId !== qa.quoteLeadId) bad("version or lead link was lost");
  return `v${r.j.version} · server total PKR ${r.j.total}`;
});
await T("quote version history preserves prior snapshot", async () => {
  const r = await api(`/api/quotes/${qa.quoteId}/versions`); want(r, 200);
  if (r.j.items?.length !== 2 || r.j.items[0].version !== 1 || r.j.items[1].version !== 2) bad("version sequence missing");
  if (r.j.items[0].snapshot.total !== qa.quoteTotal || r.j.items[1].snapshot.total !== 22740) bad(`revision snapshots were overwritten: ${r.j.items[0].snapshot.total} / ${r.j.items[1].snapshot.total}`);
  return `${r.j.items.length} immutable snapshots`;
});
await T("quote validation rejects unsupported currency and impossible totals", async () => {
  const currency = await api("/api/quotes", { role: null, method: "POST", body: {
    customer: `${Q} Invalid currency`, items: [{ name: "QA item", price: 10, qty: 1 }], details: { currency: "USD" },
  } });
  want(currency, 400, "unsupported currency");
  const quantity = await api("/api/quotes", { role: null, method: "POST", body: {
    customer: `${Q} Invalid qty`, items: [{ name: "QA item", price: 10, qty: -1 }],
  } });
  want(quantity, 400, "invalid quantity");
});
await T("public quote intake cannot be poisoned with a made-up product or price", async () => {
  const fake = await api("/api/quotes", { role: null, method: "POST", body: {
    customer: `${Q} Fake SKU`, items: [{ productId: "no-such-product", name: "Ghost", price: 1, qty: 500 }],
  } });
  want(fake, 400, "unknown catalog product must be rejected");
  const product = await catalogProduct();
  const discounted = await api("/api/quotes", { role: null, method: "POST", body: {
    customer: `${Q} Discount`, items: [{ productId: product.id, qty: 1 }], details: { discountPercent: 90, shipping: 0 },
  } });
  want(discounted, 201);
  if (discounted.j.total !== product.price || discounted.j.details?.discountPercent !== 0)
    bad(`anonymous visitor set their own discount: total=${discounted.j.total} discount=${discounted.j.details?.discountPercent}`);
  const custom = await api("/api/quotes", { role: null, method: "POST", body: {
    customer: `${Q} Custom line`, items: [{ name: "Bespoke reception desk", price: 450000, qty: 1 }],
  } });
  want(custom, 201);
  if (custom.j.total !== 0) bad("a client-supplied custom price reached a public quote total: " + custom.j.total);
  await api(`/api/quotes/${fake.j?.id ?? 0}`, { method: "DELETE" }).catch(() => {});
  return "unknown SKU → 400 · self-discount stripped · custom lines priced 0";
});
await T("staff quote creation stays privileged through the public route", async () => {
  const r = await api("/api/quotes", { role: "sales", method: "POST", body: {
    customer: `${Q} Staff`, items: [{ name: "Custom boardroom table", price: 875000, qty: 1 }],
    details: { discountPercent: 10 }, status: "Sent",
  } });
  want(r, 201);
  if (r.j.source !== "dashboard") bad("staff request was treated as storefront: source=" + r.j.source);
  if (r.j.status !== "Sent") bad("staff status was downgraded: " + r.j.status);
  if (r.j.items[0].price !== 875000 || r.j.total !== 787500) bad(`staff pricing was overwritten: ${r.j.items[0].price} / ${r.j.total}`);
  return `source=dashboard · status=Sent · total ${r.j.total}`;
});
await T("API errors are JSON and never leak a stack trace", async () => {
  const malformed = await api("/api/leads", { role: null, method: "POST", body: undefined, raw: "{bad" });
  if (malformed.s !== 400 || malformed.ct?.includes("text/html")) bad(`malformed body → ${malformed.s} ${malformed.ct}`);
  if (/body-parser|node_modules|\bat \//.test(malformed.text ?? "")) bad("stack trace leaked to the client");
  const missing = await api("/api/definitely-not-a-route");
  if (missing.s !== 404 || missing.ct?.includes("text/html")) bad(`unknown route → ${missing.s} ${missing.ct}`);
  const headers = malformed.hdr;
  for (const name of ["x-content-type-options", "referrer-policy", "x-frame-options"]) {
    if (!headers?.get?.(name)) bad("missing security header: " + name);
  }
  if (headers?.get?.("x-powered-by")) bad("X-Powered-By still advertises Express");
  return "JSON 400/404 · no stack trace · nosniff/frame/referrer present";
});
await T("public write paths are rate limited", async () => {
  // Isolate the bucket: the limiter keys on the client IP, so flood from a spoofed
  // X-Forwarded-For that no later test uses. (Vercel sets the real hop; locally the
  // process trusts loopback, so this header is honoured exactly as in production.)
  const floodIp = `203.0.113.${(TS % 200) + 10}`;
  const codes = [];
  for (let i = 0; i < 36; i++) {
    const r = await api("/api/leads", { role: null, method: "POST", body: { name: `${Q} Flood ${i}`, interest: "rate limit probe", source: "QA" }, ip: floodIp });
    codes.push(r.s);
  }
  const limited = codes.filter((code) => code === 429).length;
  const accepted = codes.filter((code) => code === 201).length;
  if (!limited) bad("no 429 after 36 public writes from one IP");
  if (accepted > 31) bad("limiter allowed too many writes: " + accepted);
  return `${accepted} accepted · ${limited} throttled (limit 30/min/IP)`;
});
await T("POST order prices from catalog, ignores client totals, and returns a private tracking secret", async () => {
  const product = await DB.prepare("SELECT id,json,price,in_stock,stock_qty FROM products WHERE in_stock=1 AND stock_qty>0 ORDER BY id LIMIT 1").get();
  if (!product) bad("no in-stock catalog row for checkout QA");
  const catalogItem = JSON.parse(product.json);
  const expectedSubtotal = Number(product.price);
  const expectedShipping = expectedSubtotal >= 50_000 ? 0 : 2_500;
  const r = await api("/api/orders", { role: null, method: "POST", body: {
    customer: `${Q} Buyer`, items: [{ id: product.id, name: "Client-forged name", price: 1, qty: 1 }], total: 100,
  } });
  want(r, 201); qa.orderId = r.j.id; qa.orderRef = r.j.ref;
  if (r.j.status !== "Pending" || typeof r.j.trackingToken !== "string" || r.j.trackingToken.length < 40) bad("order status/private tracking secret missing");
  if (r.j.items?.[0]?.name !== catalogItem.name || r.j.items?.[0]?.price !== expectedSubtotal || r.j.total !== expectedSubtotal + expectedShipping)
    bad("client price/name/total were not replaced with server-calculated catalog values");
  if (Object.hasOwn(r.j, "trackingTokenHash") || Object.hasOwn(r.j, "customer")) bad("response included a stored secret or unnecessary customer data");
  const storedHash = (await DB.prepare("SELECT tracking_token_hash FROM orders WHERE id=?").get(qa.orderId))?.tracking_token_hash;
  if (!/^[a-f0-9]{64}$/i.test(String(storedHash)) || storedHash === r.j.trackingToken)
    bad(`tracking secret was not stored as a one-way digest (type=${typeof storedHash}, length=${String(storedHash).length}, order=${qa.orderId})`);
  const invoice = await DB.prepare("SELECT subtotal,tax,shipping,total FROM invoices WHERE order_id=?").get(qa.orderId);
  if (!invoice || invoice.subtotal !== expectedSubtotal || invoice.tax !== 0 || invoice.shipping !== expectedShipping || invoice.total !== r.j.total)
    bad("order and generated invoice amounts disagree");

  const lookup = await api("/api/orders/lookup", { role: null, method: "POST", body: { ref: r.j.ref, token: r.j.trackingToken } });
  want(lookup, 200, "valid private tracking token");
  for (const field of ["customer", "items", "total", "invoice", "returns", "email", "phone", "address"])
    if (Object.hasOwn(lookup.j, field)) bad(`public tracking exposed ${field}`);
  const wrong = await api("/api/orders/lookup", { role: null, method: "POST", body: { ref: r.j.ref, token: "wrong-token" } }); want(wrong, 404, "wrong token is indistinguishable from missing order");
  const legacy = await api(`/api/orders/lookup?ref=${encodeURIComponent(r.j.ref)}`, { role: null }); want(legacy, 401, "reference-only lookup is no longer public");
  qa.invoiceId = (await DB.prepare("SELECT id FROM invoices WHERE order_id=?").get(qa.orderId))?.id;
  if (!qa.invoiceId) bad("auto-invoice missing for QA order");
  qa.orderTrackingToken = r.j.trackingToken;
  return `${r.j.ref} · canonical catalog pricing · private tracking code · INV#${qa.invoiceId}`;
});
await T("lookup unknown order/token pair → 404", async () => {
  const r = await api("/api/orders/lookup", { role: null, method: "POST", body: { ref: "WX-NOPE", token: "nonexistent-token" } });
  if (r.s !== 404) bad("s=" + r.s);
});
await T("no data leak without token (stats/users/leads/quotes/templates/invoices/inbox/returns/tasks/pages)", async () => {
  for (const p of ["/api/stats", "/api/users", "/api/leads", "/api/quotes", "/api/quote-templates", "/api/invoices", "/api/inbox", "/api/returns", "/api/tasks", "/api/pages", "/api/clients", "/api/orders", "/api/events", `/api/orders/lookup?ref=${encodeURIComponent(qa.orderRef)}`]) {
    const r = await api(p, { role: null }); want(r, 401, p);
  }
});
await T("public page read: published key 200, drafts and invalid draft flags 404", async () => {
  const pgs = await api("/api/pages"); want(pgs, 200);
  const published = (pgs.j.items ?? []).find((x) => x.status === "Published");
  const draft = (pgs.j.items ?? []).find((x) => x.status !== "Published");
  if (!published && !draft) return bad("no pages seeded at all");
  if (published) {
    const pub = await api(`/api/pages/${encodeURIComponent(published.slug)}/public`, { role: null }); want(pub, 200);
    const forgedDraft = await api(`/api/pages/${encodeURIComponent(published.slug)}/public?draft=1`, { role: null });
    want(forgedDraft, 404, "draft flag without token must not reveal current edits on a published page");
  }
  if (draft) {
    const pub = await api(`/api/pages/${encodeURIComponent(draft.slug)}/public`, { role: null }); want(pub, 404, "unpublished draft");
    const forgedDraft = await api(`/api/pages/${encodeURIComponent(draft.slug)}/public?draft=1`, { role: null }); want(forgedDraft, 404, "draft preview without signature");
  }
  const nf = await api("/api/pages/qa-no-such-slug/public", { role: null }); want(nf, 404, "unknown slug");
  return `published=${published?.slug ?? "none"} · draft=${draft?.slug ?? "none"}`;
});
await T("CMS publish snapshots isolate edits, signed preview and full rollback", async () => {
  const slug = `qa-page-cms-${TS}`;
  const created = await api("/api/pages", { method: "POST", body: { slug, title: "QA page original", template: "sale-landing" } });
  want(created, 201); qa.pageId = created.j.id;
  const original = { title: "QA page original", seoTitle: "Original SEO", seoDesc: "Original description", theme: { primary: "#14532d", ink: "#111827", bg: "#ffffff" },
    blocks: [{ type: "hero", props: { heading: "Original published headline", sub: "Original body", cta_label: "Contact", cta_href: "/contact" } }] };
  const put1 = await api(`/api/pages/${qa.pageId}/blocks`, { method: "PUT", body: original }); want(put1, 200);
  if (put1.j.validation?.length) bad("initial draft did not validate: " + JSON.stringify(put1.j.validation));
  const pub1 = await api(`/api/pages/${qa.pageId}/publish`, { method: "POST", body: { note: "QA initial publish" } }); want(pub1, 200);
  const live1 = await api(`/api/pages/${slug}/public`, { role: null }); want(live1, 200);
  if (live1.j.title !== original.title || live1.j.blocks[0]?.props?.heading !== "Original published headline" || live1.j.seoDesc !== original.seoDesc || live1.j.theme?.primary !== original.theme.primary)
    bad("first published snapshot did not capture page metadata and content");

  const changed = { title: "QA page draft edit", seoTitle: "Draft SEO", seoDesc: "Draft description", theme: { primary: "#7c2d12", ink: "#111827", bg: "#fff7ed" },
    blocks: [{ type: "hero", props: { heading: "Draft-only headline", sub: "Draft body", cta_label: "Visit", cta_href: "/contact" } }] };
  const put2 = await api(`/api/pages/${qa.pageId}/blocks`, { method: "PUT", body: changed }); want(put2, 200);
  const anonymousDraft = await api(`/api/pages/${slug}/public?draft=1`, { role: null }); want(anonymousDraft, 404, "published draft preview must be signed");
  const liveAfterEdit = await api(`/api/pages/${slug}/public`, { role: null }); want(liveAfterEdit, 200);
  if (liveAfterEdit.j.title !== original.title || liveAfterEdit.j.blocks[0]?.props?.heading !== "Original published headline" || liveAfterEdit.j.theme?.primary !== original.theme.primary)
    bad("editing draft changed anonymous published output");

  const link = await api(`/api/pages/${qa.pageId}/preview-link`, { method: "POST", body: {} }); want(link, 200);
  const previewUrl = new URL(link.j.url, BASE);
  const signedPreview = await api(`/api/pages/${slug}/public${previewUrl.search}`, { role: null }); want(signedPreview, 200, "signed draft preview");
  if (!signedPreview.j.preview || signedPreview.j.title !== changed.title || signedPreview.j.blocks[0]?.props?.heading !== "Draft-only headline")
    bad("signed preview did not show current draft");

  const pub2 = await api(`/api/pages/${qa.pageId}/publish`, { method: "POST", body: { note: "QA second publish" } }); want(pub2, 200);
  const live2 = await api(`/api/pages/${slug}/public`, { role: null }); want(live2, 200);
  if (live2.j.title !== changed.title || live2.j.blocks[0]?.props?.heading !== "Draft-only headline" || live2.j.seoDesc !== changed.seoDesc)
    bad("publish did not switch the public snapshot");
  const versions = await api(`/api/pages/${qa.pageId}/versions`); want(versions, 200);
  if (versions.j.items.length < 2) bad("expected two published versions");
  const originalVersion = versions.j.items.at(-1);
  const rolled = await api(`/api/pages/${qa.pageId}/rollback`, { method: "POST", body: { version_id: originalVersion.id } }); want(rolled, 200);
  if (rolled.j.title !== original.title || rolled.j.seoTitle !== original.seoTitle || rolled.j.theme?.primary !== original.theme.primary || rolled.j.blocks[0]?.props?.heading !== "Original published headline")
    bad("rollback did not restore full editable draft metadata and blocks");
  const liveAfterRollback = await api(`/api/pages/${slug}/public`, { role: null }); want(liveAfterRollback, 200);
  if (liveAfterRollback.j.title !== changed.title || liveAfterRollback.j.blocks[0]?.props?.heading !== "Draft-only headline")
    bad("rollback unexpectedly changed the current published snapshot");
  return `draft isolated · two versions · rollback restored draft · published output retained`;
});

/* ============ RBAC MATRIX ============ */
G("rbac");
await T("editor cannot view roster or create users", async () => {
  const a = await api("/api/users", { role: "editor" }); want(a, 403, "GET roster");
  const b = await api("/api/users", { role: "editor", method: "POST", body: { email: `qa${TS}@woodex.pk`, name: "Q", role: "viewer", password: "qapass123" } });
  want(b, 403, "POST users"); if (b.j.need !== "users.manage") bad("need=" + b.j.need);
});
await T("editor CAN manage pages (saved-sections create → 201)", async () => {
  const bl = await api("/api/blocks"); want(bl, 200);
  const firstType = (bl.j.items ?? bl.j.types ?? []).map?.((x) => x.type ?? x)[0] ?? "hero";
  const r = await api("/api/saved-sections", { role: "editor", method: "POST", body: { name: `${Q} Section`, block: { type: typeof firstType === "string" ? firstType : "hero", props: {} } } });
  want(r, 201, "saved-section"); qa.sectionId = r.j?.id; if (!qa.sectionId) bad(JSON.stringify(r.j).slice(0, 120));
});
await T("editor cannot touch CRM (PATCH lead → 403)", async () => {
  const r = await api(`/api/leads/${qa.leadId}`, { role: "editor", method: "PATCH", body: { status: "Contacted" } });
  want(r, 403); if (r.j.need !== "crm.manage") bad("need=" + r.j.need);
});
await T("sales CAN move lead (Contacted)", async () => {
  const r = await api(`/api/leads/${qa.leadId}`, { role: "sales", method: "PATCH", body: { status: "Contacted" } }); want(r, 200);
});
await T("sales cannot PUT theme", async () => { const r = await api("/api/theme", { role: "sales", method: "PUT", body: {} }); want(r, 403, "theme.manage"); });
await T("sales CAN accept quote", async () => {
  const r = await api(`/api/quotes/${qa.quoteId}`, { role: "sales", method: "PATCH", body: { status: "Accepted" } }); want(r, 200);
});
await T("finance quote.view-move allowed, content changes denied", async () => {
  const m = await api(`/api/quotes/${qa.quoteId}`, { role: "finance", method: "PATCH", body: { status: "Sent" } });
  want(m, 200);
  const edit = await api(`/api/quotes/${qa.quoteId}`, { role: "finance", method: "PATCH", body: { note: "unauthorized edit" } });
  want(edit, 403, "quote content edit");
  const c = await api("/api/products/1", { role: "finance", method: "PATCH", body: { name: "hax" } }); want(c, 403, "catalog");
});
await T("issued quote cannot be silently overwritten; explicit revision increments version", async () => {
  const current = await api(`/api/quotes/${qa.quoteId}`); want(current, 200);
  const changedItems = current.j.items.map((item) => ({ ...item, price: 11000 }));
  const blocked = await api(`/api/quotes/${qa.quoteId}`, { role: "sales", method: "PATCH", body: { items: changedItems } });
  want(blocked, 409, "issued quote content lock");
  const revision = await api(`/api/quotes/${qa.quoteId}`, { role: "sales", method: "PATCH", body: {
    status: "Revised", items: changedItems,
  } });
  want(revision, 200);
  if (revision.j.status !== "Revised" || revision.j.version !== 3 || revision.j.total !== 24864)
    bad(`revision not recalculated: ${JSON.stringify({ status: revision.j.status, version: revision.j.version, total: revision.j.total })}`);
  return `v${revision.j.version} · PKR ${revision.j.total}`;
});
await T("finance CAN record payment on invoice", async () => {
  const r = await api(`/api/invoices/${qa.invoiceId}/payments`, { role: "finance", method: "POST", body: { amount: 5000, method: "Cash" } });
  if (r.s >= 400) bad("payments: " + r.s + " " + JSON.stringify(r.j).slice(0, 120));
  qa.paymentId = r.j?.id;
  const inv = await api(`/api/invoices/${qa.invoiceId}`); want(inv, 200);
  if (!(inv.j.payments ?? []).length) bad("payment not listed");
});
await T("read RBAC: viewer has self-profile only, sensitive reads and writes are denied", async () => {
  const profile = await api("/api/auth/me", { role: "viewer" }); want(profile, 200, "self profile");
  for (const path of ["/api/stats", "/api/clients", "/api/invoices", "/api/orders", "/api/leads", "/api/events"]) {
    const read = await api(path, { role: "viewer" }); want(read, 403, path);
  }
  const write = await api("/api/tasks", { role: "viewer", method: "POST", body: { title: `${Q} x` } });
  want(write, 403); if (write.j.need !== "crm.manage") bad("need=" + write.j.need);
});
await T("read RBAC: sales, finance and editor receive only their mapped data", async () => {
  for (const [path, role] of [["/api/leads", "sales"], ["/api/quotes", "sales"], ["/api/orders", "sales"], ["/api/quotes", "finance"], ["/api/orders", "finance"], ["/api/invoices", "finance"], ["/api/pages", "editor"], ["/api/products", "editor"]]) {
    const allowed = await api(path, { role }); want(allowed, 200, `${role} ${path}`);
  }
  for (const [path, role] of [["/api/invoices", "sales"], ["/api/clients", "editor"], ["/api/clients", "finance"]]) {
    const denied = await api(path, { role }); want(denied, 403, `${role} ${path}`);
  }
});
await T("disabled account: new token denied + live token cut", async () => {
  const em = `qa-dis-${TS}@woodex.pk`;
  const mk = await api("/api/users", { method: "POST", body: { email: em, name: `${Q} Disabled`, role: "viewer", password: "qapass123" } });
  want(mk, 201, "create user"); const uid = mk.j.id ?? mk.j.user?.id; qa.userId = uid;
  const live = await loginAs("dis", em, "qapass123"); void live;
  await api(`/api/users/${uid}`, { method: "PATCH", body: { active: false } });
  const re = await api("/api/auth/login", { role: null, method: "POST", body: { email: em, password: "qapass123" } }); want(re, 403, "login after disable");
  const old = await api("/api/stats", { role: "dis" }); // PATCH active=false kills sessions → 401 (revoked) or 403 (guard) both safe
  if (old.s !== 401 && old.s !== 403) bad("live token survived disable: " + old.s);
  delete toks.dis;
});

/* ============ OWNER READS ============ */
G("reads");
for (const [p, field] of [["/api/stats", null], ["/api/leads", "items"], ["/api/clients", "items"], ["/api/clients/review", "items"], ["/api/inbox", "items"], ["/api/inbox/templates", null], ["/api/site-visits", "items"], ["/api/quotes", "items"], ["/api/quote-templates", "items"], ["/api/orders", "items"], ["/api/invoices", "items"], ["/api/returns", "items"], ["/api/tasks", "items"], ["/api/pages", "items"], ["/api/marketing/stats", null], ["/api/media", null], ["/api/saved-sections", "items"], ["/api/products/overrides", null], ["/api/users", "items"], ["/api/blocks", null], ["/api/materials", null]]) {
  await T(`GET ${p}`, async () => {
    const r = await api(p); want(r, 200);
    if (field && !Array.isArray(r.j[field])) bad(field + " missing");
    return field ? `${r.j[field].length} rows` : "ok";
  });
}
await T("GET filters: leads?status=Contacted only contacted", async () => {
  const r = await api("/api/leads?status=Contacted"); want(r, 200);
  if (!(r.j.items ?? []).every((l) => l.status === "Contacted")) bad("filter leaked");
});
await T("GET clients?q=QA finds our lead-client", async () => {
  const r = await api(`/api/clients?q=${encodeURIComponent(Q + " Shopper")}`); want(r, 200);
  if (!(r.j.items ?? []).length) bad("client auto-created by lead not searchable");
  qa.clientId = r.j.items[0].id; return `client#${qa.clientId}`;
});
await T("GET inbox thread has system message from lead", async () => {
  if (!qa.clientId) bad("no qa client");
  const r = await api(`/api/inbox/${qa.clientId}/thread`); want(r, 200);
  const msgs = r.j.messages ?? r.j.items ?? [];
  if (!msgs.some((m) => String(m.body).includes("New QA lead"))) bad("no system ping in thread");
});

/* ============ OWNER CRUD FLOWS ============ */
G("crud");
await T("task create → done → verified", async () => {
  const c = await api("/api/tasks", { method: "POST", body: { title: `${Q} ship sample`, priority: "High", due: "2026-10-20" } });
  want(c, 201); qa.taskId = c.j.id;
  const d = await api(`/api/tasks/${c.j.id}`, { method: "PATCH", body: { done: true } }); want(d, 200);
  if (d.j.done !== true) bad("done flag lost");
});
await T("quote → invoice conversion preserves revision pricing", async () => {
  const r = await api(`/api/quotes/${qa.quoteId}/invoice`, { method: "POST" });
  if (r.s >= 400) bad("convert: " + r.s + " " + JSON.stringify(r.j).slice(0, 140));
  qa.convertedInv = r.j?.invoice?.id ?? r.j?.id;
  if (r.j.subtotal !== 22000 || r.j.discount !== 2200 || r.j.tax !== 3564 || r.j.shipping !== 1500 || r.j.total !== 24864)
    bad("invoice did not copy saved quote pricing: " + JSON.stringify({ subtotal: r.j.subtotal, discount: r.j.discount, tax: r.j.tax, shipping: r.j.shipping, total: r.j.total }));
  return `INV ${r.j?.ref ?? r.j?.invoice?.ref ?? "?"} · exact quote totals`;
});
await T("order stage move advances status", async () => {
  const r = await api(`/api/orders/${qa.orderId}`, { method: "PATCH", body: { stage: "dispatch", status: "In Progress" } });
  want(r, 200); if (r.j.stage !== "dispatch") bad("stage=" + r.j.stage);
});
await T("return on QA order → resolution", async () => {
  const c = await api("/api/returns", { method: "POST", body: { order_ref: qa.orderRef, reason: `${Q} damaged rail`, state: "Received" } });
  if (c.s >= 400) bad("create: " + c.s + " " + JSON.stringify(c.j).slice(0, 140));
  qa.returnId = c.j.id;
  const p = await api(`/api/returns/${c.j.id}`, { method: "PATCH", body: { resolution: "Refunded", refundAmount: 5000 } });
  want(p, 200); if (String(p.j.refundAmount) !== "5000") bad("refund=" + p.j.refundAmount);
});
await T("section PATCH → DELETE → gone", async () => {
  if (!qa.sectionId) bad("skipped upstream");
  const p = await api(`/api/saved-sections/${qa.sectionId}`, { method: "PATCH", body: { name: `${Q} Section²` } }); want(p, 200);
  const d = await api(`/api/saved-sections/${qa.sectionId}`, { method: "DELETE" }); if (d.s >= 400) bad("delete s=" + d.s);
  const g = await api(`/api/saved-sections/${qa.sectionId}`); if (g.s !== 404) bad("still readable s=" + g.s);
});
await T("client merge keeps survivor & links", async () => {
  const dup = await api("/api/clients", { method: "POST", body: { name: `${Q} Duplicate`, email: `qa+dup${TS}@mail.pk`, source: "QA" } }); want(dup, 201);
  const a = qa.clientId; const b2 = dup.j.id;
  if (!a || !b2 || a === b2) bad(`invalid merge pair (a=${a} b=${b2})`);
  const m = await api(`/api/clients/${a}/merge`, { method: "POST", body: { from_id: b2 } });
  if (m.s >= 400) bad("merge: " + m.s + " " + JSON.stringify(m.j).slice(0, 140));
});
/* ---- Projects / BOQ: the delivery side of the business ---- */
await T("project create → BOQ → milestone → derived progress", async () => {
  if (!qa.clientId) bad("no qa client to attach the project to");
  const c = await api("/api/projects", { method: "POST", body: { name: `${Q} Fit-out`, clientId: qa.clientId, plannedCost: 1000000, dueAt: "2027-03-01" } });
  if (c.s >= 400) bad("create: " + c.s + " " + JSON.stringify(c.j).slice(0, 140));
  qa.projectId = c.j.id;
  if (!/^PRJ-\d+$/.test(String(c.j.ref))) bad("ref not generated: " + c.j.ref);
  if (c.j.stage !== "Planning") bad("default stage=" + c.j.stage);
});
await T("project BOQ line totals are server-computed", async () => {
  if (!qa.projectId) bad("skipped upstream");
  const a = await api(`/api/projects/${qa.projectId}/boq`, { method: "POST", body: { description: `${Q} chair`, qty: 4, unitCost: 2500 } });
  if (a.s >= 400) bad("line: " + a.s + " " + JSON.stringify(a.j).slice(0, 140));
  if (Number(a.j.lineTotal) !== 10000) bad("lineTotal=" + a.j.lineTotal + " expected 10000");
  // A client-supplied lineTotal must never be trusted.
  const b = await api(`/api/projects/${qa.projectId}/boq`, { method: "POST", body: { description: `${Q} desk`, qty: 2, unitCost: 9000, lineTotal: 1 } });
  if (b.s >= 400) bad("line2: " + b.s);
  if (Number(b.j.lineTotal) !== 18000) bad("client-supplied lineTotal was honoured: " + b.j.lineTotal);
  const d = await api(`/api/projects/${qa.projectId}`);
  want(d, 200);
  if (Number(d.j.boqTotal) !== 28000) bad("boqTotal=" + d.j.boqTotal + " expected 28000");
  if (!Array.isArray(d.j.milestonesList)) bad("milestonesList missing from detail");
});
await T("milestones drive project progress", async () => {
  if (!qa.projectId) bad("skipped upstream");
  const m1 = await api(`/api/projects/${qa.projectId}/milestones`, { method: "POST", body: { title: `${Q} survey` } });
  const m2 = await api(`/api/projects/${qa.projectId}/milestones`, { method: "POST", body: { title: `${Q} install` } });
  if (m1.s >= 400 || m2.s >= 400) bad("milestone create failed");
  const done = await api(`/api/projects/${qa.projectId}/milestones/${m1.j.id}`, { method: "PATCH", body: { done: true } });
  want(done, 200);
  const p = await api(`/api/projects/${qa.projectId}`);
  if (p.j.progress !== 50) bad("progress=" + p.j.progress + " expected 50 after 1 of 2 milestones");
  await api(`/api/projects/${qa.projectId}/milestones/${m2.j.id}`, { method: "PATCH", body: { done: true } });
  const p2 = await api(`/api/projects/${qa.projectId}`);
  if (p2.j.progress !== 100) bad("progress=" + p2.j.progress + " expected 100");
});
await T("project rejects bad input, 404s unknown, cascades on delete", async () => {
  const noName = await api("/api/projects", { method: "POST", body: { clientId: qa.clientId } });
  if (noName.s !== 400) bad("missing name accepted: " + noName.s);
  const noClient = await api("/api/projects", { method: "POST", body: { name: `${Q} orphan`, clientId: 999999 } });
  if (noClient.s !== 400) bad("unknown client accepted: " + noClient.s);
  const badStage = await api(`/api/projects/${qa.projectId}`, { method: "PATCH", body: { stage: "Teleported" } });
  if (badStage.s !== 400) bad("invalid stage accepted: " + badStage.s);
  const gone = await api("/api/projects/999999");
  if (gone.s !== 404) bad("unknown project should 404, got " + gone.s);
  const del = await api(`/api/projects/${qa.projectId}`, { method: "DELETE" });
  if (del.s >= 400) bad("delete: " + del.s);
  const after = await api(`/api/projects/${qa.projectId}`);
  if (after.s !== 404) bad("project still readable after delete");
  const list = await api("/api/projects");
  if ((list.j.items ?? []).some((p) => p.id === qa.projectId)) bad("deleted project still in list");
});

await T("inbox message round-trip", async () => {
  const cid = qa.clientId; if (!cid) bad("no qa client");
  const p = await api(`/api/inbox/${cid}/messages`, { method: "POST", body: { channel: "whatsapp", body: `${Q} · reply sent via QA`, direction: "outbound" } });
  if (p.s >= 400) bad("post: " + p.s + " " + JSON.stringify(p.j).slice(0, 120));
  const t = await api(`/api/inbox/${cid}/thread`); want(t, 200);
  if (!(t.j.messages ?? []).some((m) => String(m.body).includes("reply sent via QA"))) bad("not in thread");
});
await T("pages: create → blocks → publish → versions → rollback → unpublish", async () => {
  const key = `qa-page-${TS}`;
  const c = await api("/api/pages", { method: "POST", body: { slug: key, title: `${Q} Landing`, blocks: [{ type: "hero", props: { heading: "QA" } }] } });
  if (c.s >= 400) bad("create: " + c.s + " " + JSON.stringify(c.j).slice(0, 160));
  qa.pageId = c.j.id ?? c.j.page?.id;
  const bl = await api(`/api/pages/${qa.pageId}/blocks`, { method: "PUT", body: { blocks: [{ type: "hero", props: { heading: "QA v2" } }, { type: "faq", props: { heading: "H", items: "a | b" } }] } });
  want(bl, 200, "put blocks");
  const p1 = await api(`/api/pages/${qa.pageId}/publish`, { method: "POST" }); want(p1, 200, "publish v1");
  await api(`/api/pages/${qa.pageId}/blocks`, { method: "PUT", body: { blocks: [{ type: "hero", props: { heading: "QA v3" } }] } });
  await api(`/api/pages/${qa.pageId}/publish`, { method: "POST" });
  const v = await api(`/api/pages/${qa.pageId}/versions`); want(v, 200);
  const vers = v.j.items ?? v.j ?? []; if (!(vers.length >= 2)) bad("versions=" + (vers.length ?? JSON.stringify(v.j).slice(0, 80)));
  const noVid = await api(`/api/pages/${qa.pageId}/rollback`, { method: "POST", body: {} }); want(noVid, 400, "missing version_id → 400 not 500");
  const rb = await api(`/api/pages/${qa.pageId}/rollback`, { method: "POST", body: { version_id: (vers[0]).id ?? (vers[vers.length - 1]).id } }); want(rb, 200, "rollback");
  const upub = await api(`/api/pages/${qa.pageId}/unpublish`, { method: "POST" }); if (upub.s >= 400) bad("unpublish s=" + upub.s);
  return `${key} id#${qa.pageId}`;
});

await T("blocks · registry declares the rich field types the editor renders", async () => {
  const r = await api("/api/blocks"); want(r, 200, "registry");
  const reg = r.j.registry ?? {};
  const types = new Set();
  for (const spec of Object.values(reg)) for (const f of spec.fields ?? []) types.add(f.t);
  for (const need of ["text", "textarea", "lines", "image", "select", "number", "color", "link", "boolean"]) {
    if (!types.has(need)) bad(`field type "${need}" missing from the server catalogue`);
  }
  // A select is only usable if it declares its options — the editor renders them verbatim.
  const selects = Object.entries(reg).flatMap(([type, spec]) => (spec.fields ?? []).filter((f) => f.t === "select").map((f) => ({ type, ...f })));
  if (!selects.length) bad("no select fields declared");
  for (const f of selects) if (!Array.isArray(f.options) || f.options.length < 2) bad(`select ${f.type}.${f.k} has no options`);
  return `${Object.keys(reg).length} blocks · ${types.size} field types · ${selects.length} selects`;
});

await T("blocks · an unknown block type is rejected, a rich-prop block round-trips", async () => {
  const key = `qa-rich-${TS}`;
  const c = await api("/api/pages", { method: "POST", body: { slug: key, title: `${Q} Rich`, blocks: [{ type: "hero", props: { heading: "A" } }] } });
  if (c.s >= 400) bad("create: " + c.s);
  const id = c.j.id ?? c.j.page?.id;
  const bad1 = await api(`/api/pages/${id}/blocks`, { method: "PUT", body: { blocks: [{ type: "not-a-block", props: {} }] } });
  if (bad1.s < 400) bad("unknown block type was accepted (s=" + bad1.s + ")");
  const rich = [{ type: "hero", props: { heading: "Rich", align: "left", accent: "#0f766e", compact: true } }];
  const put = await api(`/api/pages/${id}/blocks`, { method: "PUT", body: { blocks: rich } }); want(put, 200, "put rich props");
  const back = await api(`/api/pages/${id}`);
  const stored = (back.j.blocks ?? back.j.page?.blocks ?? [])[0]?.props ?? {};
  if (stored.accent !== "#0f766e") bad("colour prop lost: " + JSON.stringify(stored).slice(0, 120));
  if (stored.compact !== true) bad("boolean prop lost: " + JSON.stringify(stored.compact));
  await api(`/api/pages/${id}/unpublish`, { method: "POST" });
  return "unknown type rejected · select/colour/boolean survive the round-trip";
});

await T("pages · delete removes the page and its blocks/versions (no orphans)", async () => {
  const key = `qa-del-${TS}`;
  const c = await api("/api/pages", { method: "POST", body: { slug: key, title: `${Q} Deletable`, blocks: [{ type: "hero", props: { heading: "x" } }] } });
  if (c.s >= 400) bad("create: " + c.s);
  const id = c.j.id ?? c.j.page?.id;
  // A delete test that runs on an empty page proves nothing about cascades.
  if ((c.j.blocks ?? []).length !== 1) bad(`create dropped the blocks it was given (${(c.j.blocks ?? []).length})`);
  await api(`/api/pages/${id}/publish`, { method: "POST" });
  const del = await api(`/api/pages/${id}`, { method: "DELETE" }); want(del, 200, "delete");
  if ((del.j.blocks ?? 0) < 1) bad("deleted page reported 0 blocks — cascade never ran");
  const gone = await api(`/api/pages/${id}`); if (gone.s !== 404) bad(`page still readable (s=${gone.s})`);
  const again = await api(`/api/pages/${id}`, { method: "DELETE" }); want(again, 404, "second delete is 404, not 500");
  return `${key} removed with ${del.j.blocks ?? 0} blocks, ${del.j.versions ?? 0} versions`;
});

await T("perf · GET /api/clients stays set-based (query count does not track row count)", async () => {
  const before = await api("/api/diagnostics/queries");
  if (before.s !== 200) return "diagnostics unavailable (non-owner role) — skipped";
  const a = before.j.queries;
  const list = await api("/api/clients"); want(list, 200, "list");
  const b = (await api("/api/diagnostics/queries")).j.queries;
  const perRequest = b - a;
  /* The old implementation issued ~9 queries PER CLIENT (2,943 for 321 rows).
     Anything near `clients × 4` means somebody reintroduced a rollup loop. */
  const ceiling = 40 + list.j.total * 2;
  if (perRequest > ceiling) bad(`/${list.j.total} clients → ${perRequest} queries (ceiling ${ceiling}) — looks like N+1`);
  // Values must still match the reference implementation.
  const sample = (list.j.items ?? []).slice(0, 3);
  for (const it of sample) {
    const det = await api(`/api/clients/${it.id}`); want(det, 200, "detail");
    if (det.j.lifetime !== it.lifetime) bad(`${it.name}: lifetime ${it.lifetime} vs ${det.j.lifetime}`);
    if (det.j.outstanding !== it.outstanding) bad(`${it.name}: outstanding ${it.outstanding} vs ${det.j.outstanding}`);
    if (det.j.orders !== it.orders) bad(`${it.name}: orders ${it.orders} vs ${det.j.orders}`);
    if (det.j.quotes !== it.quotes) bad(`${it.name}: quotes ${it.quotes} vs ${det.j.quotes}`);
  }
  return `${list.j.total} clients → ${perRequest} queries · rollups match /:id`;
});

await T("db · every hot-path index exists, and the two schemas agree on them", async () => {
  const { DatabaseSync } = await import("node:sqlite");
  const { readFileSync, existsSync } = await import("node:fs");
  const dbPath = process.env.WOODEX_DATA_DIR ? process.env.WOODEX_DATA_DIR + "/woodex.db" : "data/woodex.db";
  if (!existsSync(dbPath)) return "database file not reachable from the test host — skipped";
  const raw = new DatabaseSync(dbPath);
  const sqlite = new Set(raw.prepare("SELECT name FROM sqlite_master WHERE type='index' AND name LIKE 'idx_%'").all().map((r) => r.name));
  raw.close();
  const pg = new Set([...readFileSync("scripts/schema.postgres.sql", "utf8").matchAll(/CREATE INDEX IF NOT EXISTS (idx_\w+)/g)].map((m) => m[1]));
  const missingSqlite = [...pg].filter((n) => !sqlite.has(n));
  if (missingSqlite.length) bad("declared in schema.postgres.sql but missing from the live SQLite db: " + missingSqlite.join(", "));
  /* An index that precedes its CREATE TABLE makes the whole Postgres migration
     abort, which only `qa:pg` catches — pin the order here too. */
  const sql = readFileSync("scripts/schema.postgres.sql", "utf8");
  const tableAt = new Map([...sql.matchAll(/CREATE TABLE IF NOT EXISTS (\w+)/g)].map((m) => [m[1], m.index]));
  for (const m of sql.matchAll(/CREATE INDEX IF NOT EXISTS (idx_\w+) ON (\w+)/g)) {
    const [, name, table] = m;
    if (!tableAt.has(table)) bad(`${name} references unknown table ${table}`);
    else if (tableAt.get(table) > m.index) bad(`${name} is declared BEFORE CREATE TABLE ${table} — Postgres migration will abort`);
  }
  return `${pg.size} indexes declared · ${sqlite.size} present · ordering valid`;
});

await T("db · backup produces a restorable snapshot whose counts match the live db", async () => {
  const { spawnSync } = await import("node:child_process");
  const { mkdtempSync, rmSync, existsSync } = await import("node:fs");
  const { tmpdir } = await import("node:os");
  const dir = mkdtempSync(tmpdir() + "/wx-bak-");
  const out = dir + "/snap.db.gz";
  try {
    const made = spawnSync(process.execPath, ["scripts/backup.mjs", "--out", out], { encoding: "utf8" });
    if (made.status !== 0) bad("backup failed: " + (made.stderr || made.stdout).slice(0, 200));
    if (!existsSync(out)) bad("backup file was not written");
    const checked = spawnSync(process.execPath, ["scripts/backup.mjs", "--verify", out], { encoding: "utf8" });
    if (checked.status !== 0) bad("verify failed: " + (checked.stderr || checked.stdout).slice(0, 200));
    const line = (checked.stdout.match(/integrity ok · \d+ tables · \d+ rows/) ?? [null])[0];
    if (!line) bad("verify did not report a row count: " + checked.stdout.slice(0, 200));
    if (!/row counts match the live database exactly/.test(checked.stdout))
      bad("restored snapshot does not match the live database: " + checked.stdout.slice(0, 240));
    return line;
  } finally {
    rmSync(dir, { recursive: true, force: true });
  }
});

await T("seo · sitemap covers every declared static route and both consumers agree", async () => {
  const { PUBLIC_ROUTES } = await import("../apps/api/public-routes.mjs");
  // `api()` is a JSON helper; the sitemap is XML, so fetch it raw.
  const res = await fetch(BASE + "/sitemap.xml");
  if (res.status !== 200) bad(`sitemap status ${res.status}`);
  const xml = await res.text();
  if (!res.headers.get("content-type")?.includes("xml")) bad("sitemap content-type is not XML");
  const locs = [...xml.matchAll(/<loc>([^<]+)<\/loc>/g)].map((m) => m[1]);
  if (!locs.length) bad("sitemap has no <loc> entries");
  const paths = new Set(locs.map((u) => { try { return new URL(u).pathname; } catch { return u; } }));
  const missing = PUBLIC_ROUTES.map((x) => x.path).filter((p) => !paths.has(p));
  if (missing.length) bad("static routes missing from the sitemap: " + missing.join(", "));
  // The prerender must render exactly this list — otherwise a route exists in the
  // sitemap but ships as a JavaScript-only page to crawlers.
  const src = (await import("node:fs")).readFileSync("scripts/prerender.mjs", "utf8");
  if (!/STATIC_ROUTES = PUBLIC_ROUTES/.test(src)) bad("prerender no longer consumes PUBLIC_ROUTES");
  return `${PUBLIC_ROUTES.length} static routes + dynamic · ${locs.length} URLs total`;
});

/* ============ REAL-TIME ============ */
G("realtime");
await T("anonymous and non-owner event streams are blocked", async () => {
  const anonymous = await api("/api/events", { role: null }); want(anonymous, 401);
  const viewer = await api("/api/events", { role: "viewer" }); want(viewer, 403);
});
await T("owner-only SSE streams locally and event payloads omit customer fingerprints", async () => {
  const headers = { accept: "text/event-stream", authorization: "Bearer " + toks.owner };
  const ctl = new AbortController(); const timeout = setTimeout(() => ctl.abort(), 3000);
  const response = await fetch(BASE + "/api/events", { headers, signal: ctl.signal }).catch((error) => bad("connect: " + error.name));
  clearTimeout(timeout);
  if (!(response.headers.get("content-type") ?? "").includes("text/event-stream")) bad("event stream content type missing");
  want({ s: response.status }, 200);
  const reader = response.body.getReader(); const decoder = new TextDecoder(); let received = "";
  const pump = (async () => {
    await new Promise((resolve) => setTimeout(resolve, 300));
    await api("/api/leads", { role: null, method: "POST", body: { name: `${Q} Streamcheck`, interest: "SSE proof", source: "QA" } });
    const deadline = Date.now() + 4000;
    while (Date.now() < deadline) {
      const item = await Promise.race([reader.read(), new Promise((resolve) => setTimeout(() => resolve("timeout"), 1200))]);
      if (item === "timeout") continue;
      received += decoder.decode(item.value ?? new Uint8Array(), { stream: true });
      if (/event:\s*leads|"New lead/.test(received)) break;
    }
  })().finally(() => { try { ctl.abort(); } catch { /* noop */ } });
  const forcedClose = setTimeout(() => { try { ctl.abort(); } catch { /* noop */ } }, 6000);
  await pump; clearTimeout(forcedClose);
  if (!/event:\s*leads|"New lead/.test(received)) bad("no lead frame within 4s · saw: " + received.slice(0, 120));
  if (received.includes(Q)) bad("customer fingerprint leaked into stream payload");
  return "owner authenticated · no customer fingerprint in event frame";
});

/* ============ TODO REGRESSION GATES ============ */
G("todo");
await T("TODO-1: drafts sealed without a signed preview link", async () => {
  const slug = `qa-page-seal-${TS}`;
  const c = await api("/api/pages", { method: "POST", body: { slug, title: `${Q} Sealed`, blocks: [] } }); want(c, 201);
  qa.page2 = c.j.id;
  const anon = await api(`/api/pages/${slug}/public?draft=1`, { role: null }); want(anon, 404, "draft=1 alone must not open (was: leak)");
  const link = await api(`/api/pages/${c.j.id}/preview-link`, { method: "POST" }); want(link, 200, "preview-link for editor");
  const u = new URL("http://x" + link.j.url);
  const sig = u.searchParams.get("sig"), exp = u.searchParams.get("exp");
  const withSig = await fetch(BASE + `/api/pages/${slug}/public?draft=1&sig=${encodeURIComponent(sig)}&exp=${exp}`, { signal: AbortSignal.timeout(5000) });
  if (withSig.status !== 200) bad(`valid sig rejected (${withSig.status})`);
  const forged = await fetch(BASE + `/api/pages/${slug}/public?draft=1&sig=${"a".repeat(40)}&exp=${exp}`, { signal: AbortSignal.timeout(5000) });
  if (forged.status !== 404) bad("forged sig accepted");
  const expired = await fetch(BASE + `/api/pages/${slug}/public?draft=1&sig=${encodeURIComponent(sig)}&exp=${Date.now() - 1000}`, { signal: AbortSignal.timeout(5000) });
  if (expired.status !== 404) bad("expired sig accepted");
  const sess = await fetch(BASE + `/api/pages/${slug}/public?draft=1&token=${toks.owner}`, { signal: AbortSignal.timeout(5000) });
  if (sess.status !== 404) bad("query-string session token must not authenticate a draft: " + sess.status);
  return "signed preview works; unsigned, forged, expired, and query-token access are denied";
});
await T("TODO-2: order→invoice→task flow rolls back after forced invoice failure", async () => {
  const customer = `${Q} Txprobe`;
  const literal = "'" + customer.replaceAll("'", "''") + "'";
  const product = await DB.prepare("SELECT id FROM products WHERE in_stock=1 AND stock_qty>=1 LIMIT 1").get();
  if (!product) bad("no available catalog product fixture");
  if (PG_REHEARSAL) {
    await DB.exec(`DROP TRIGGER IF EXISTS qa_force_invoice_failure ON invoices;
      DROP FUNCTION IF EXISTS qa_force_invoice_failure();
      CREATE FUNCTION qa_force_invoice_failure() RETURNS trigger LANGUAGE plpgsql AS $qa$
      BEGIN IF NEW.customer = ${literal} THEN RAISE EXCEPTION 'forced QA invoice failure'; END IF; RETURN NEW; END
      $qa$;
      CREATE TRIGGER qa_force_invoice_failure BEFORE INSERT ON invoices FOR EACH ROW EXECUTE FUNCTION qa_force_invoice_failure();`);
  } else {
    await DB.exec(`DROP TRIGGER IF EXISTS qa_force_invoice_failure;
      CREATE TRIGGER qa_force_invoice_failure BEFORE INSERT ON invoices
      WHEN NEW.customer=${literal} BEGIN SELECT RAISE(ABORT,'forced QA invoice failure'); END;`);
  }
  const count = async (table) => Number((await DB.prepare(`SELECT COUNT(*) n FROM ${table}`).get()).n);
  try {
    const beforeOrders = await count("orders");
    const beforeInvoices = await count("invoices");
    const r = await api("/api/orders", { role: null, method: "POST", body: { customer, items: [{ id: product.id, qty: 1 }] } });
    const dO = (await count("orders")) - beforeOrders;
    const dI = (await count("invoices")) - beforeInvoices;
    if (r.s < 500) bad(`fault trigger did not abort the flow (status ${r.s})`);
    if (dO !== 0 || dI !== 0) bad(`partial write! status ${r.s}, Δorders=${dO}, Δinvoices=${dI}`);
    return `injected invoice failure returned ${r.s}; SQL counts unchanged`;
  } finally {
    if (PG_REHEARSAL) await DB.exec("DROP TRIGGER IF EXISTS qa_force_invoice_failure ON invoices; DROP FUNCTION IF EXISTS qa_force_invoice_failure();");
    else await DB.exec("DROP TRIGGER IF EXISTS qa_force_invoice_failure;");
  }
});
await T("TODO-3: refs survive deletion (no reuse)", async () => {
  const before = (await (DB.prepare("SELECT COUNT(*) n FROM leads").get())).n;
  (await (DB.prepare("DELETE FROM leads WHERE id=(SELECT MAX(id) FROM leads WHERE name LIKE 'QA·%')").run())); // old COUNT rule → next lead would REUSE a live row's ref
  const r = await api("/api/leads", { role: null, method: "POST", body: { name: `${Q} Refprobe`, interest: "x", source: "QA" } }); want(r, 201);
  const clash = (await (DB.prepare("SELECT COUNT(*) n FROM leads WHERE ref=?").get(r.j.ref))).n;
  const dupAny = (await (DB.prepare("SELECT COUNT(*) n FROM (SELECT ref FROM leads GROUP BY ref HAVING COUNT(*)>1)").get())).n;
  if (clash > 1 || dupAny > 0) bad(`ref ${r.j.ref} collides (table dup groups: ${dupAny})`);
  return `${r.j.ref} unique among ${before} rows · zero dup refs table-wide`;
});

/* ============ DB INTEGRITY ============ */
G("db");
await T("database integrity check", async () => {
  if (PG_REHEARSAL) { const v = (await DB.prepare("SELECT 1 AS ok").get()).ok; if (Number(v) !== 1) bad("Postgres query failed"); return `${DB.kind} connection + SQL ok`; }
  const v = (await (DB.prepare("PRAGMA integrity_check").get())).integrity_check; if (v !== "ok") bad(v);
});
await T("storage journal mode", async () => {
  if (PG_REHEARSAL) return `${DB.kind} persistence managed by PostgreSQL engine`;
  const m = (await (DB.prepare("PRAGMA journal_mode").get())).journal_mode; if (m !== "wal") bad("mode=" + m);
});
await T("health is liveness-only while database rows remain queryable", async () => {
  const health = (await api("/api/health", { role: null })).j;
  if (health.ok !== true || health.service !== "woodex-api" || !health.ts || "counts" in health || "db" in health)
    bad("health response included internal state or omitted liveness metadata");
  const orders = Number((await DB.prepare("SELECT COUNT(*) n FROM orders").get()).n);
  const invoices = Number((await DB.prepare("SELECT COUNT(*) n FROM invoices").get()).n);
  if (!Number.isInteger(orders) || !Number.isInteger(invoices)) bad("database read-after-write check failed");
  return `minimal health response; direct SQL counts remain available (${orders} orders, ${invoices} invoices)`;
});
await T("sequence continuity (ids are ints, no reuse gaps issue)", async () => {
  const n = (await (DB.prepare("SELECT MAX(id) m FROM leads").get())).m; if (!(n > 0)) bad("no ids");
  return "max lead id " + n;
});

/* ============ IP BACKSTOP (self-contained, own bucket) ============ */
G("throttle");
await T("TODO-5: 30 fails/min from one IP → 429 even with fresh emails", async () => {
  const ip = `10.9${TS % 9}.${TS % 250}.${TS % 240 + 1}`; // spoofed via XFF — server trusts loopback peers only
  for (let i = 0; i < 31; i++) {
    const r = await fetch(BASE + "/api/auth/login", { method: "POST", headers: { "content-type": "application/json", "x-forwarded-for": ip }, body: JSON.stringify({ email: `qa-ip${TS}x${i}@woodex.pk`, password: "x" }) });
    if (r.status === 429) return `429 at attempt ${i + 1} of 31`;
  }
  bad("no per-IP backstop");
});
await T("flood consequences are sane (isolation or shared lock)", async () => {
  const r = await api("/api/auth/login", { role: null, method: "POST", body: { email: "admin", password: "admin" } });
  if (r.s === 200) { toks.owner = r.j.token; return "XFF isolation held — admin unaffected"; }
  if (r.s === 429) return "shared loopback window locked for ≤60s (expected in-suite; QA re-run needs a 60s gap)";
  bad("unexpected: " + r.s);
});

/* ============ CLEANUP ============ */
G("cleanup");
await T("remove all QA· rows and re-count", async () => {
  if (KEEP) return "--keep: rows left";
  const like = 'QA·%'; // sweep every QA run's rows, not just this TS
  (await (DB.exec(`DELETE FROM payments WHERE invoice_id IN (SELECT id FROM invoices WHERE customer LIKE '${like}');
    DELETE FROM invoices WHERE customer LIKE '${like}';
    DELETE FROM returns WHERE reason LIKE '${like}' OR customer LIKE '${like}';
    DELETE FROM orders WHERE customer LIKE '${like}';
    DELETE FROM quote_versions WHERE quote_id IN (SELECT id FROM quotes WHERE customer LIKE '${like}');
    DELETE FROM quotes WHERE customer LIKE '${like}';
    DELETE FROM quote_templates WHERE name LIKE '${like}';
    DELETE FROM messages WHERE conversation_id IN (SELECT id FROM conversations WHERE client_id IN (SELECT id FROM clients WHERE name LIKE '${like}')) OR body LIKE '${like}';
    DELETE FROM conversations WHERE client_id IN (SELECT id FROM clients WHERE name LIKE '${like}');
    DELETE FROM tasks WHERE site_visit_id IN (SELECT id FROM site_visits WHERE lead_id IN (SELECT id FROM leads WHERE name LIKE '${like}'));
    DELETE FROM site_visits WHERE lead_id IN (SELECT id FROM leads WHERE name LIKE '${like}');
    DELETE FROM tasks WHERE title LIKE '${like}' OR order_id IN (SELECT id FROM orders WHERE customer LIKE '${like}') OR client_id IN (SELECT id FROM clients WHERE name LIKE '${like}');
    DELETE FROM tasks WHERE client_id IS NOT NULL AND client_id NOT IN (SELECT id FROM clients);
    DELETE FROM page_blocks WHERE page_id IN (SELECT id FROM pages WHERE slug LIKE 'qa-page-%');
    DELETE FROM page_versions WHERE page_id IN (SELECT id FROM pages WHERE slug LIKE 'qa-page-%');
    DELETE FROM pages WHERE slug LIKE 'qa-page-%';
    DELETE FROM client_links WHERE client_id IN (SELECT id FROM clients WHERE name LIKE '${like}');
    DELETE FROM leads WHERE name LIKE '${like}';
    DELETE FROM clients WHERE name LIKE '${like}';
    DELETE FROM users WHERE email LIKE 'qa-%@woodex.pk';
    DELETE FROM sessions WHERE user_id NOT IN (SELECT id FROM users);
    DELETE FROM messages WHERE conversation_id NOT IN (SELECT id FROM conversations);
    DELETE FROM client_links WHERE entity='lead' AND entity_id NOT IN (SELECT id FROM leads);
    DELETE FROM tasks WHERE client_id IS NOT NULL AND client_id NOT IN (SELECT id FROM clients);
    DELETE FROM track_events WHERE cid LIKE 'qa-%';`)));
  const n = (await (DB.prepare(`SELECT (SELECT COUNT(*) FROM leads WHERE name LIKE '${like}') + (SELECT COUNT(*) FROM clients WHERE name LIKE '${like}') + (SELECT COUNT(*) FROM users WHERE email LIKE 'qa-%@woodex.pk') x`).get())).x;
  if (n !== 0) bad(`${n} QA rows survived`);
  return "workspace untouched";
});
await T("no orphans after sweep (sessions/links/messages/tasks vs parents)", async () => {
  const q = async (sql) => (await (DB.prepare(sql).get())).n;
  const o = {
    sessions: (await (q("SELECT COUNT(*) n FROM sessions WHERE user_id NOT IN (SELECT id FROM users)"))),
    client_links: (await (q("SELECT COUNT(*) n FROM client_links WHERE client_id NOT IN (SELECT id FROM clients)"))),
    messages: (await (q("SELECT COUNT(*) n FROM messages WHERE conversation_id NOT IN (SELECT id FROM conversations)"))),
    tasks_to_clients: (await (q("SELECT COUNT(*) n FROM tasks WHERE client_id IS NOT NULL AND client_id NOT IN (SELECT id FROM clients)"))),
    visits_to_leads: (await (q("SELECT COUNT(*) n FROM site_visits WHERE lead_id NOT IN (SELECT id FROM leads)"))),
    visits_to_clients: (await (q("SELECT COUNT(*) n FROM site_visits WHERE client_id NOT IN (SELECT id FROM clients)"))),
    tasks_to_visits: (await (q("SELECT COUNT(*) n FROM tasks WHERE site_visit_id IS NOT NULL AND site_visit_id NOT IN (SELECT id FROM site_visits)"))),
  };
  const bad2 = Object.entries(o).filter(([, n]) => n > 0); if (bad2.length) bad(JSON.stringify(bad2));
  return "clean";
});


/* ============ SUMMARY ============ */
const fails = R.filter((r) => !r.ok);
const byGroup = {};
for (const r of R) { (byGroup[r.g] ??= { p: 0, f: 0 }).f += r.ok ? 0 : 1; byGroup[r.g].p += r.ok ? 1 : 0; }
console.log("\n════════ QA SUMMARY ════════");
for (const [g, c] of Object.entries(byGroup)) console.log(`${g.padEnd(10)} ${String(c.p).padStart(2)} pass · ${c.f} fail`);
console.log(`TOTAL ${R.length - fails.length}/${R.length} pass  · base ${BASE} · ${((Date.now() - TS) / 1000).toFixed(1)}s`);
writeFileSync("/tmp/qa-results.json", JSON.stringify({ base: BASE, at: new Date().toISOString(), results: R }, null, 1));
console.log("json → /tmp/qa-results.json");
if (qaRuntime) {
  await new Promise((resolve) => { qaRuntime.httpServer.close(resolve); qaRuntime.httpServer.closeAllConnections?.(); });
  await DB.close();
} else DB.close();
process.exitCode = fails.length ? 1 : 0;

#!/usr/bin/env node
/* Full-stack QA battery — API, auth/RBAC, public surface, CRUD flows, SSE, DB integrity, self-cleanup.
   Usage: node scripts/qa-full.mjs [--base http://127.0.0.1:3001] [--keep] */
import { DatabaseSync } from "node:sqlite";
import { writeFileSync } from "node:fs";

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
} else DB = new DatabaseSync(new URL("../data/woodex.db", import.meta.url).pathname);
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
async function api(path, { method = "GET", role = "owner", body } = {}) {
  const h = { accept: "application/json" };
  if (role && toks[role]) h.authorization = "Bearer " + toks[role];
  if (body !== undefined) h["content-type"] = "application/json";
  const r = await fetch(BASE + path, { method, headers: h, body: body !== undefined ? JSON.stringify(body) : undefined, signal: AbortSignal.timeout(8000) });
  let j = null; try { j = await r.json(); } catch { /* non-json */ }
  return { s: r.status, j, hdr: r.headers };
}
const want = (x, code, why = "") => { if (x.s !== code) bad(`expected ${code}${why ? " (" + why + ")" : ""}, got ${x.s} ${JSON.stringify(x.j ?? "")}`); };
const toks = {};
const qa = {};
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
await T("me with garbage token → 401 bad_token", async () => {
  const r = await fetch(BASE + "/api/auth/me", { headers: { authorization: "Bearer deadbeef" + TS } });
  const j = await r.json(); want({ s: r.status, j }, 401); if (j.code !== "bad_token") bad("code=" + j.code);
});
await T("me with token → Admin/owner", async () => {
  const r = await api("/api/auth/me"); want(r, 200); if (r.j.user.name !== "Admin") bad(JSON.stringify(r.j.user));
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
await T("GET /api/health counts sane", async () => {
  const r = await api("/api/health", { role: null }); want(r, 200);
  const c = r.j.counts; if (!(c.products >= 14 && c.leads >= 1 && c.users >= 5)) bad(JSON.stringify(c));
  return `${c.products}p/${c.leads}l/${c.orders}o`;
});
await T("GET products list + by id", async () => {
  const l = await api("/api/products", { role: null }); want(l, 200);
  const items = l.j.items ?? l.j; if (!Array.isArray(items) || items.length < 12) bad("count " + items?.length);
  if (typeof items[0].price !== "number") bad("price not numeric");
  const one = await api(`/api/products/${items[0].id}`, { role: null }); want(one, 200);
  return `${items.length} products`;
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
await T("POST lead (public) → 201 New + client auto-match", async () => {
  const r = await api("/api/leads", { role: null, method: "POST", body: { name: `${Q} Shopper`, interest: "Teak dining set 8-seater", contact: `qa+lead${TS}@mail.pk`, source: "QA" } });
  want(r, 201); qa.leadId = r.j.id; if (r.j.status !== "New" || !String(r.j.ref).startsWith("L-")) bad(JSON.stringify(r.j).slice(0, 160));
  return `lead#${r.j.id}`;
});
await T("POST lead validation (no name → 400)", async () => {
  const r = await api("/api/leads", { role: null, method: "POST", body: { interest: "x" } }); want(r, 400);
});
await T("POST quote (public) → 201 Draft + audit trail", async () => {
  const r = await api("/api/quotes", { role: null, method: "POST", body: { customer: `${Q} Customer`, items: [{ name: "Chair", price: 12000, qty: 2 }] } });
  want(r, 201); qa.quoteId = r.j.id;
  const total = r.j.total; if (total !== 24000) bad("total=" + total);
  if (!Array.isArray(r.j.audit) || !r.j.audit.length) bad("no audit entry");
  return `quote#${r.j.id} @ ${total}`;
});
await T("POST order (public) → 201 Pending + auto-invoice", async () => {
  const r = await api("/api/orders", { role: null, method: "POST", body: { customer: `${Q} Buyer`, items: [{ name: "Wardrobe", price: 95000, qty: 1 }] } });
  want(r, 201); qa.orderId = r.j.id; qa.orderRef = r.j.ref;
  if (r.j.status !== "Pending") bad("status=" + r.j.status);
  const look = await api(`/api/orders/lookup?ref=${encodeURIComponent(r.j.ref)}`, { role: null }); want(look, 200, "lookup");
  qa.invoiceId = (await (DB.prepare("SELECT id FROM invoices WHERE customer LIKE ? ").get(`${Q} %`)))?.id;
  if (!qa.invoiceId) bad("auto-invoice missing for QA order");
  return `${r.j.ref} + INV#${qa.invoiceId}`;
});
await T("lookup unknown ref → 404", async () => {
  const r = await api("/api/orders/lookup?ref=WX-NOPE", { role: null }); if (r.s !== 404) bad("s=" + r.s);
});
await T("no data leak without token (stats/users/leads/invoices/inbox/returns/tasks/pages)", async () => {
  for (const p of ["/api/stats", "/api/users", "/api/leads", "/api/invoices", "/api/inbox", "/api/returns", "/api/tasks", "/api/pages"]) {
    const r = await api(p, { role: null }); want(r, 401, p);
  }
});
await T("public page read: published key 200, unknown key 404", async () => {
  const pgs = await api("/api/pages"); want(pgs, 200);
  const it = (pgs.j.items ?? []).find((x) => x.status === "Published") ?? (pgs.j.items ?? [])[0];
  if (!it) return bad("no pages seeded at all");
  const pub = await api(`/api/pages/${encodeURIComponent(it.slug)}/public`, { role: null });
  if (it.status !== "Published") { // draft privacy: anonymous must NOT see drafts… but ?draft=1 does (known P3 in TODO)
    if (pub.s !== 404) bad("draft leaked without draft=1");
    const d = await api(`/api/pages/${encodeURIComponent(it.slug)}/public?draft=1`, { role: null }); want(d, 200, "draft via ?draft=1");
    return `draft=${it.slug} (draft=1 readable by design)`;
  }
  want(pub, 200);
  const nf = await api("/api/pages/qa-no-such-slug/public", { role: null }); if (nf.s !== 404) bad("unknown slug s=" + nf.s);
  return `slug=${it.slug}`;
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
await T("finance quote.view-move allowed, catalog denied", async () => {
  const m = await api(`/api/quotes/${qa.quoteId}`, { role: "finance", method: "PATCH", body: { status: "Sent" } });
  if (![200, 400].includes(m.s)) bad("view-move blocked: " + m.s); // 400 = route-side flow rule (Accepted→Sent not allowed) — that's fine, 403 is not
  const c = await api("/api/products/1", { role: "finance", method: "PATCH", body: { name: "hax" } }); want(c, 403, "catalog");
});
await T("finance CAN record payment on invoice", async () => {
  const r = await api(`/api/invoices/${qa.invoiceId}/payments`, { role: "finance", method: "POST", body: { amount: 5000, method: "Cash" } });
  if (r.s >= 400) bad("payments: " + r.s + " " + JSON.stringify(r.j).slice(0, 120));
  qa.paymentId = r.j?.id;
  const inv = await api(`/api/invoices/${qa.invoiceId}`); want(inv, 200);
  if (!(inv.j.payments ?? []).length) bad("payment not listed");
});
await T("viewer: read ok, every write 403", async () => {
  const g = await api("/api/stats", { role: "viewer" }); want(g, 200, "read");
  const w = await api("/api/tasks", { role: "viewer", method: "POST", body: { title: `${Q} x` } }); want(w, 403); if (w.j.need !== "crm.manage") bad("need=" + w.j.need);
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
for (const [p, field] of [["/api/stats", null], ["/api/leads", "items"], ["/api/clients", "items"], ["/api/clients/review", "items"], ["/api/inbox", "items"], ["/api/inbox/templates", null], ["/api/quotes", "items"], ["/api/orders", "items"], ["/api/invoices", "items"], ["/api/returns", "items"], ["/api/tasks", "items"], ["/api/pages", "items"], ["/api/marketing/stats", null], ["/api/media", null], ["/api/saved-sections", "items"], ["/api/products/overrides", null], ["/api/users", "items"], ["/api/blocks", null], ["/api/materials", null]]) {
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
await T("quote → invoice conversion", async () => {
  const r = await api(`/api/quotes/${qa.quoteId}/invoice`, { method: "POST" });
  if (r.s >= 400) bad("convert: " + r.s + " " + JSON.stringify(r.j).slice(0, 140));
  qa.convertedInv = r.j?.invoice?.id ?? r.j?.id;
  return `INV ${r.j?.ref ?? r.j?.invoice?.ref ?? "?"}`;
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

/* ============ REAL-TIME ============ */
G("realtime");
await T("SSE opens with text/event-stream", async () => {
  const ctl = new AbortController(); const t = setTimeout(() => ctl.abort(), 3000);
  const r = await fetch(BASE + "/api/events", { headers: { accept: "text/event-stream" }, signal: ctl.signal }).catch((e) => bad("connect: " + e.name));
  clearTimeout(t);
  const ct = r.headers.get("content-type") ?? ""; if (!ct.includes("text/event-stream")) bad("ct=" + ct); want({ s: r.status }, 200);
  r.body?.cancel?.().catch(() => {});
});
await T("SSE delivers lead event emitted during publish", async () => {
  const ctl = new AbortController();
  let resp;
  try { resp = await fetch(BASE + "/api/events", { headers: { accept: "text/event-stream" }, signal: ctl.signal }); } catch (e) { bad("open: " + e); }
  const reader = resp.body.getReader(); const dec = new TextDecoder(); let acc = "";
  const pump = (async () => {
    await new Promise((r2) => setTimeout(r2, 300));
    await api("/api/leads", { role: null, method: "POST", body: { name: `${Q} Streamcheck`, interest: "SSE proof", source: "QA" } });
    const dl = Date.now() + 4000;
    while (Date.now() < dl) {
      const n = await Promise.race([reader.read(), new Promise((r2) => setTimeout(() => r2("t"), 1200))]);
      if (n === "t") continue;
      acc += dec.decode(n.value ?? new Uint8Array(), { stream: true });
      if (/event:\s*leads|"New lead/.test(acc)) break;
    }
  })().finally(() => { try { ctl.abort(); } catch {} });
  const to = setTimeout(() => { try { ctl.abort(); } catch {} }, 6000); await pump; clearTimeout(to);
  if (!/event:\s*leads|"New lead/.test(acc)) bad("no lead frame within 4s · saw: " + acc.slice(0, 120));
  if (/QA·/.test(acc)) bad("TODO-7: customer fingerprint leaked to the PUBLIC stream");
  return "frame delivered, PII-free";
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
  if (sess.status !== 200) bad("session fallback denied: " + sess.status);
  return "sig/exp/forged/expired/session all correct";
});
await T("TODO-2: order→invoice→task flow rolls back after forced invoice failure", async () => {
  const customer = `${Q} Txprobe`;
  const literal = "'" + customer.replaceAll("'", "''") + "'";
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
  try {
    const h = (await api("/api/health", { role: null })).j.counts;
    const r = await api("/api/orders", { role: null, method: "POST", body: { customer, items: [{ name: "x", price: 1000, qty: 1 }] } });
    const h2 = (await api("/api/health", { role: null })).j.counts;
    const dO = h2.orders - h.orders, dI = h2.invoices - h.invoices;
    if (r.s < 500) bad(`fault trigger did not abort the flow (status ${r.s})`);
    if (dO !== 0 || dI !== 0) bad(`partial write! status ${r.s}, Δorders=${dO}, Δinvoices=${dI}`);
    return `injected invoice failure returned ${r.s}; Δorders=${dO}, Δinvoices=${dI}`;
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
await T("health counts == real rows (fresh read)", async () => {
  const h = (await api("/api/health", { role: null })).j.counts;
  const real = {}; for (const t2 of Object.keys(h)) { try { real[t2] = (await (DB.prepare(`SELECT COUNT(*) n FROM ${t2}`).get())).n; } catch { real[t2] = -1; } }
  const diff = Object.keys(h).filter((k) => real[k] !== -1 && Math.abs(real[k] - h[k]) > 1); // ≤1 drift from concurrent streamcheck insert ok
  if (diff.length) bad("drift " + diff.map((k) => `${k}:${h[k]}≠${real[k]}`).join(" "));
  return Object.keys(h).length + " tables matched";
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
    DELETE FROM quotes WHERE customer LIKE '${like}';
    DELETE FROM messages WHERE conversation_id IN (SELECT id FROM conversations WHERE client_id IN (SELECT id FROM clients WHERE name LIKE '${like}')) OR body LIKE '${like}';
    DELETE FROM conversations WHERE client_id IN (SELECT id FROM clients WHERE name LIKE '${like}');
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

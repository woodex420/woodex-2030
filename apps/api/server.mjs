/**
 * WOODEX platform API — single runtime source of truth for storefront + dashboard.
 * Express + async DB facade (SQLite default; PostgreSQL via DATABASE_URL).
 *
 *   GET  /api/health                 status + row counts
 *   GET  /api/products[?q&sub]       full catalog (price/stock edits merged)
 *   GET  /api/products/overrides     changed rows only (storefront polling)
 *   GET  /api/products/:id           one product
 *   PATCH/api/products/:id           {price?, inStock?, stockQty?}  → both apps see it live
 *   GET  /api/materials /api/services
 *   GET/POST/PATCH /api/quotes       storefront quote requests + dashboard pipeline
 *   GET/POST/PATCH/DELETE /api/quote-templates reusable quotation layouts (quote.manage)
 *   GET/POST/PATCH /api/leads        contact form → CRM kanban
 *   GET/POST/PATCH /api/orders       checkout → operations board
 *   GET  /api/invoices[?status=open|...] · GET/PATCH /api/invoices/:id · POST /api/invoices (from quote_id/order_id or raw)
 *   POST /api/invoices/:id/payments  · GET/POST /api/returns · PATCH /api/returns/:id
 *   POST /api/orders/lookup          public tracking with order reference + private code
 *   P4 CRM: GET/POST/PATCH /api/clients · /api/clients/review · /api/clients/:id/merge
 *           GET/POST/PATCH /api/tasks · leads now carry score + scoreWhy
 *   GET  /api/stats                  KPI + activity rollup for the dashboard
 *   GET  /img/:file                  shared product photography
 */
import fs from "node:fs";
import crypto from "node:crypto";
import path from "node:path";
import { fileURLToPath } from "node:url";
import express from "express";
import { loadShopData, ROOT } from "./shop-data.mjs";
import { PUBLIC_ROUTES, absoluteUrl } from "./public-routes.mjs";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const IS_SERVERLESS = process.env.VERCEL === "1";
const IS_PRODUCTION = process.env.VERCEL_ENV
  ? process.env.VERCEL_ENV === "production"
  : process.env.NODE_ENV === "production";
const DATABASE_URL = String(process.env.DATABASE_URL ?? "").trim();
if ((IS_SERVERLESS || IS_PRODUCTION) && !DATABASE_URL) {
  throw new Error("DATABASE_URL is required for Vercel/production; SQLite is local-development only.");
}
const DB_DIR = process.env.WOODEX_DATA_DIR ? path.resolve(process.env.WOODEX_DATA_DIR) : path.join(ROOT, "data");
if (!DATABASE_URL) fs.mkdirSync(DB_DIR, { recursive: true });
const { createDb } = await import("./db-adapters.mjs");
const db = await createDb({ sqliteFile: path.join(DB_DIR, "woodex.db"), databaseUrl: DATABASE_URL });
console.log("[woodex-api] driver=" + db.kind);

const now = () => new Date().toISOString();
const fmtMoney = (n) => "PKR " + Math.round(n).toLocaleString("en-PK");

/* ---------------- schema ---------------- */
if (db.kind === "sqlite") await db.exec(`
CREATE TABLE IF NOT EXISTS products(
  id TEXT PRIMARY KEY, json TEXT NOT NULL, price REAL NOT NULL,
  in_stock INTEGER NOT NULL DEFAULT 1, stock_qty INTEGER NOT NULL DEFAULT 99,
  updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS materials(id TEXT PRIMARY KEY, json TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS services(id TEXT PRIMARY KEY, json TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS leads(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT, name TEXT NOT NULL, company TEXT,
  interest TEXT, source TEXT, contact TEXT, status TEXT DEFAULT 'New', owner TEXT,
  note TEXT, email TEXT, phone TEXT, preferred_channel TEXT, contact_consent INTEGER, is_returning INTEGER, identity_review INTEGER,
  consent_at TEXT, consent_version TEXT, landing_path TEXT, referrer TEXT,
  utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, created_at TEXT, updated_at TEXT
);
CREATE TABLE IF NOT EXISTS quotes(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT, customer TEXT NOT NULL, contact TEXT, lead_id INTEGER,
  items TEXT NOT NULL, total REAL NOT NULL DEFAULT 0, status TEXT DEFAULT 'Draft',
  source TEXT DEFAULT 'storefront', note TEXT, audit TEXT DEFAULT '[]', details TEXT NOT NULL DEFAULT '{}',
  version INTEGER NOT NULL DEFAULT 1, created_at TEXT, updated_at TEXT
);
CREATE TABLE IF NOT EXISTS quote_versions(
  id INTEGER PRIMARY KEY AUTOINCREMENT, quote_id INTEGER NOT NULL, version INTEGER NOT NULL,
  snapshot TEXT NOT NULL, actor TEXT, action TEXT, created_at TEXT,
  UNIQUE(quote_id,version)
);
CREATE INDEX IF NOT EXISTS idx_quote_versions_quote ON quote_versions(quote_id, version);
CREATE TABLE IF NOT EXISTS quote_templates(
  id TEXT PRIMARY KEY, name TEXT NOT NULL, template TEXT NOT NULL,
  created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS orders(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT, customer TEXT NOT NULL, items TEXT NOT NULL,
  total REAL NOT NULL DEFAULT 0, status TEXT DEFAULT 'In Progress', stage TEXT DEFAULT 'production',
  owner TEXT DEFAULT 'Faisal', due TEXT, tracking_token_hash TEXT, created_at TEXT, updated_at TEXT
);
CREATE TABLE IF NOT EXISTS invoices(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT, quote_id INTEGER, order_id INTEGER,
  customer TEXT NOT NULL, items TEXT NOT NULL DEFAULT '[]',
  subtotal REAL NOT NULL DEFAULT 0, discount REAL NOT NULL DEFAULT 0, tax REAL NOT NULL DEFAULT 0,
  shipping REAL NOT NULL DEFAULT 0, total REAL NOT NULL DEFAULT 0, paid REAL NOT NULL DEFAULT 0,
  status TEXT DEFAULT 'Issued', due TEXT, notes TEXT, created_at TEXT, updated_at TEXT);
CREATE TABLE IF NOT EXISTS payments(
  id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER NOT NULL, amount REAL NOT NULL,
  method TEXT DEFAULT 'Bank transfer', reference TEXT, note TEXT, created_at TEXT);
CREATE TABLE IF NOT EXISTS returns(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT, order_id INTEGER, customer TEXT,
  item TEXT, reason TEXT, state TEXT DEFAULT 'Requested', refund_amount REAL DEFAULT 0,
  resolution TEXT, created_at TEXT, updated_at TEXT);
CREATE TABLE IF NOT EXISTS clients(
  id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, company TEXT, email TEXT, email_norm TEXT,
  phone TEXT, phone_norm TEXT, city TEXT, tags TEXT DEFAULT '[]', notes TEXT, source TEXT,
  merged_into INTEGER, created_at TEXT, updated_at TEXT);
CREATE TABLE IF NOT EXISTS client_links(client_id INTEGER NOT NULL, entity TEXT NOT NULL, entity_id INTEGER NOT NULL,
  UNIQUE(entity, entity_id));
CREATE TABLE IF NOT EXISTS tasks(
  id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, lead_id INTEGER, quote_id INTEGER, order_id INTEGER, site_visit_id INTEGER,
  title TEXT NOT NULL, due TEXT, owner TEXT DEFAULT 'You', priority TEXT DEFAULT 'normal', done INTEGER DEFAULT 0,
  created_at TEXT, updated_at TEXT);
CREATE TABLE IF NOT EXISTS site_visits(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT UNIQUE NOT NULL, lead_id INTEGER NOT NULL, client_id INTEGER NOT NULL,
  scheduled_at TEXT NOT NULL, timezone TEXT NOT NULL DEFAULT 'Asia/Karachi', address TEXT NOT NULL,
  owner TEXT DEFAULT 'Unassigned', status TEXT NOT NULL DEFAULT 'Scheduled', notes TEXT, outcome TEXT,
  reminder_at TEXT, reminder_task_id INTEGER, audit TEXT NOT NULL DEFAULT '[]', created_at TEXT, updated_at TEXT);
CREATE INDEX IF NOT EXISTS idx_site_visits_lead ON site_visits(lead_id, scheduled_at);
CREATE TABLE IF NOT EXISTS pages(
  id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT UNIQUE NOT NULL, title TEXT NOT NULL,
  status TEXT DEFAULT 'Draft', seo_title TEXT, seo_desc TEXT, created_at TEXT, updated_at TEXT,
  published_snapshot TEXT);
CREATE TABLE IF NOT EXISTS page_blocks(
  id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER NOT NULL, type TEXT NOT NULL,
  props TEXT NOT NULL DEFAULT '{}', sort INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS page_versions(
  id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER NOT NULL, snapshot TEXT NOT NULL,
  note TEXT, who TEXT DEFAULT 'You', created_at TEXT);
CREATE TABLE IF NOT EXISTS saved_sections(
  id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, category TEXT DEFAULT 'Custom',
  tags TEXT DEFAULT '[]', block TEXT NOT NULL, created_at TEXT, updated_at TEXT);
CREATE TABLE IF NOT EXISTS track_events(
  id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER, slug TEXT NOT NULL,
  kind TEXT NOT NULL, label TEXT, href TEXT, cid TEXT, ref TEXT,
  utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, pct INTEGER,
  created_at TEXT);
CREATE INDEX IF NOT EXISTS idx_client_links_client ON client_links(client_id, entity);
CREATE INDEX IF NOT EXISTS idx_page_blocks_page ON page_blocks(page_id, sort);
CREATE INDEX IF NOT EXISTS idx_tasks_client ON tasks(client_id, done);
CREATE INDEX IF NOT EXISTS idx_tasks_order ON tasks(order_id);
CREATE INDEX IF NOT EXISTS idx_orders_stage ON orders(stage, created_at);
CREATE INDEX IF NOT EXISTS idx_invoices_order ON invoices(order_id);
CREATE INDEX IF NOT EXISTS idx_clients_merged ON clients(merged_into, updated_at);
CREATE INDEX IF NOT EXISTS idx_clients_email ON clients(email_norm);
CREATE INDEX IF NOT EXISTS idx_clients_phone ON clients(phone_norm);
CREATE INDEX IF NOT EXISTS idx_leads_created ON leads(created_at);
CREATE INDEX IF NOT EXISTS idx_quotes_created ON quotes(created_at);
CREATE INDEX IF NOT EXISTS idx_track_slug ON track_events(slug, kind, created_at);
CREATE INDEX IF NOT EXISTS idx_track_page ON track_events(page_id, kind, created_at);
CREATE TABLE IF NOT EXISTS projects(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT UNIQUE NOT NULL, name TEXT NOT NULL,
  client_id INTEGER NOT NULL, order_id INTEGER, owner TEXT DEFAULT 'Unassigned',
  stage TEXT NOT NULL DEFAULT 'Planning', address TEXT, start_at TEXT, due_at TEXT,
  planned_cost REAL DEFAULT 0, actual_cost REAL DEFAULT 0, progress INTEGER DEFAULT 0,
  notes TEXT, created_at TEXT, updated_at TEXT);
CREATE INDEX IF NOT EXISTS idx_projects_client ON projects(client_id, stage);
CREATE TABLE IF NOT EXISTS project_milestones(
  id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, title TEXT NOT NULL,
  due_at TEXT, done INTEGER DEFAULT 0, sort INTEGER DEFAULT 0, note TEXT, created_at TEXT);
CREATE INDEX IF NOT EXISTS idx_milestones_project ON project_milestones(project_id, sort);
CREATE TABLE IF NOT EXISTS boq_lines(
  id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, sku TEXT,
  description TEXT NOT NULL, qty REAL NOT NULL DEFAULT 1, unit TEXT DEFAULT 'pcs',
  unit_cost REAL NOT NULL DEFAULT 0, sort INTEGER DEFAULT 0, created_at TEXT);
CREATE INDEX IF NOT EXISTS idx_boq_project ON boq_lines(project_id, sort);
CREATE TABLE IF NOT EXISTS project_files(
  id INTEGER PRIMARY KEY AUTOINCREMENT, project_id INTEGER NOT NULL, name TEXT NOT NULL,
  kind TEXT DEFAULT 'drawing', size INTEGER DEFAULT 0, url TEXT, uploaded_by TEXT DEFAULT 'You',
  created_at TEXT);
CREATE INDEX IF NOT EXISTS idx_files_project ON project_files(project_id);
CREATE TABLE IF NOT EXISTS conversations(
  id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER NOT NULL UNIQUE,
  status TEXT DEFAULT 'open', assignee TEXT, priority INTEGER DEFAULT 0,
  unread INTEGER DEFAULT 0, last_at TEXT, created_at TEXT, updated_at TEXT);
CREATE TABLE IF NOT EXISTS messages(
  id INTEGER PRIMARY KEY AUTOINCREMENT, conversation_id INTEGER NOT NULL,
  channel TEXT NOT NULL, direction TEXT DEFAULT 'outbound', author TEXT,
  body TEXT NOT NULL, meta TEXT, created_at TEXT);
CREATE INDEX IF NOT EXISTS idx_msg_conv ON messages(conversation_id, id);
CREATE TABLE IF NOT EXISTS users(
  id INTEGER PRIMARY KEY AUTOINCREMENT, email TEXT UNIQUE NOT NULL, name TEXT NOT NULL,
  role TEXT NOT NULL DEFAULT 'viewer', pass_salt TEXT, pass_hash TEXT,
  active INTEGER DEFAULT 1, created_at TEXT, updated_at TEXT);
CREATE TABLE IF NOT EXISTS sessions(token TEXT PRIMARY KEY, user_id INTEGER NOT NULL, created_at TEXT, expires_at TEXT);
CREATE TABLE IF NOT EXISTS meta(key TEXT PRIMARY KEY, value TEXT);
PRAGMA journal_mode = WAL;
`);

// Keep older local SQLite files compatible with the expanded storefront intake record.
if (db.kind === "sqlite") {
  const leadColumns = new Set((await db.prepare("PRAGMA table_info(leads)").all()).map((column) => column.name));
  const additions = [
    ["email", "TEXT"], ["phone", "TEXT"], ["preferred_channel", "TEXT"],
    ["contact_consent", "INTEGER"], ["is_returning", "INTEGER"], ["identity_review", "INTEGER"],
    ["consent_at", "TEXT"], ["consent_version", "TEXT"],
    ["landing_path", "TEXT"], ["referrer", "TEXT"], ["utm_source", "TEXT"],
    ["utm_medium", "TEXT"], ["utm_campaign", "TEXT"],
  ];
  for (const [name, type] of additions) {
    if (!leadColumns.has(name)) await db.exec(`ALTER TABLE leads ADD COLUMN ${name} ${type}`);
  }

  const quoteColumns = new Set((await db.prepare("PRAGMA table_info(quotes)").all()).map((column) => column.name));
  const quoteAdditions = [["details", "TEXT NOT NULL DEFAULT '{}'"], ["version", "INTEGER NOT NULL DEFAULT 1"]];
  for (const [name, type] of quoteAdditions) {
    if (!quoteColumns.has(name)) await db.exec(`ALTER TABLE quotes ADD COLUMN ${name} ${type}`);
  }
  await db.exec(`CREATE TABLE IF NOT EXISTS quote_versions(
    id INTEGER PRIMARY KEY AUTOINCREMENT, quote_id INTEGER NOT NULL, version INTEGER NOT NULL,
    snapshot TEXT NOT NULL, actor TEXT, action TEXT, created_at TEXT,
    UNIQUE(quote_id,version)
  )`);
  await db.exec("CREATE INDEX IF NOT EXISTS idx_quote_versions_quote ON quote_versions(quote_id, version)");

  const taskColumns = new Set((await db.prepare("PRAGMA table_info(tasks)").all()).map((column) => column.name));
  if (!taskColumns.has("site_visit_id")) await db.exec("ALTER TABLE tasks ADD COLUMN site_visit_id INTEGER");
  await db.exec(`CREATE TABLE IF NOT EXISTS site_visits(
    id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT UNIQUE NOT NULL, lead_id INTEGER NOT NULL, client_id INTEGER NOT NULL,
    scheduled_at TEXT NOT NULL, timezone TEXT NOT NULL DEFAULT 'Asia/Karachi', address TEXT NOT NULL,
    owner TEXT DEFAULT 'Unassigned', status TEXT NOT NULL DEFAULT 'Scheduled', notes TEXT, outcome TEXT,
    reminder_at TEXT, reminder_task_id INTEGER, audit TEXT NOT NULL DEFAULT '[]', created_at TEXT, updated_at TEXT
  )`);
  await db.exec("CREATE INDEX IF NOT EXISTS idx_site_visits_lead ON site_visits(lead_id, scheduled_at)");
}

/* ---------------- seed ---------------- */
async function seed() {
  const count = (await (db.prepare("SELECT COUNT(*) AS n FROM products").get())).n;
  if (count > 0) return;
  console.log("[seed] importing storefront catalog into SQLite…");
  const { products, materials, services } = await loadShopData();
  /* Atomic: an interrupted boot (dev watcher restart, Ctrl-C, crash) must leave the
     catalogue empty rather than partially seeded. seed() short-circuits on a
     non-empty products table, so a half-written import would never be repaired. */
  await db.transaction(async () => {
    const ins = db.prepare(
      "INSERT INTO products(id,json,price,in_stock,stock_qty,updated_at) VALUES(?,?,?,?,?,?)"
    );
    for (const p of products) {
      const stock = p.inStock ? 99 : 0;
      (await (ins.run(p.id, JSON.stringify(p), p.price, p.inStock ? 1 : 0, stock, now())));
    }
    const insMat = db.prepare("INSERT INTO materials(id,json) VALUES(?,?)");
    for (const m of materials) (await (insMat.run(m.id, JSON.stringify(m))));
    const insSvc = db.prepare("INSERT INTO services(id,json) VALUES(?,?)");
    for (const [i, svc] of services.entries()) (await (insSvc.run(String(i + 1), JSON.stringify(svc))));
  });

  // Demo operational data so every module has content on first boot
  const seedLeads = [
    ["Amara Sheikh", "Website Form", "Modular Kitchen", "New", "Sana R.", "$18,400"],
    ["Bilal Traders", "Referral", "Office Fit-out", "Qualified", "Omar K.", "$64,000"],
    ["Hina Farooq", "Instagram", "Wardrobe + Bedroom", "Meeting", "Sana R.", "$12,750"],
    ["Gulberg Residency", "Cold Outreach", "Furniture Lot (24 units)", "Quotation", "Daniyal M.", "$96,500"],
    ["Zeeshan Ahmed", "Walk-in", "TV Unit & Panel", "Won", "Omar K.", "$7,900"],
    ["Maple Cafe", "Website Form", "Cafe Seating", "Lost", "Sana R.", "$9,300"],
  ];
  const insLead = db.prepare(
    "INSERT INTO leads(ref,name,interest,source,status,owner,note,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)"
  );
  (await Promise.all(seedLeads.map(async ([name, source, interest, status, owner, note], i) => {
    const t = new Date(Date.now() - (i + 1) * 36e5 * 7).toISOString();
    (await (insLead.run("L-" + (1042 - i * 3), name, interest, source, status, owner, note, t, t)));
  })));

  const seedQuotes = [
    ["Q-2291", "Gulberg Residency", "Approved", 96500000, "email"],
    ["Q-2294", "Clifton Villa 9", "Negotiation", 142000000, "email"],
    ["Q-2290", "Zen Dental Clinic", "Viewed", 22400000, "portal"],
    ["Q-2288", "Hina Farooq", "Sent", 12750000, "portal"],
    ["Q-2285", "Maple Cafe", "Expired", 9300000, "email"],
  ];
  const first = products[0];
  const insQ = db.prepare(
    "INSERT INTO quotes(ref,customer,items,total,status,source,audit,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)"
  );
  (await Promise.all(seedQuotes.map(async ([ref, customer, status, total, source], i) => {
    const t = new Date(Date.now() - (i + 1) * 36e5 * 11).toISOString();
    const items = JSON.stringify([
      { name: first?.name ?? "Executive desk", price: Math.round(total / 24), qty: i + 3 },
    ]);
    (await (insQ.run(ref, customer, items, total, status, source,
      JSON.stringify([{ who: "System", action: "seeded from " + source, time: t }]), t, t)));
  })));

  const stages = ["production", "qc", "dispatch", "delivery", "installation"];
  const insO = db.prepare(
    "INSERT INTO orders(ref,customer,items,total,status,stage,owner,due,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)"
  );
  (await Promise.all(["Clifton Villa 9", "Gulberg Residency", "Tariq Hotels", "Zeeshan Ahmed", "Zen Dental", "Bahria Block C", "Nadia Clock Tower", "Maple Cafe"].map(async (customer, i) => {
    const stage = stages[i % 5];
    const status = i % 4 === 3 ? "Delayed" : i % 3 === 0 ? "In Progress" : "On Track";
    const p = products[(i * 7) % products.length];
    const t = now();
    (await (insO.run("WX-" + (4210 + i), customer,
      JSON.stringify([{ name: p?.name, price: p?.price ?? 50000, qty: (i % 4) + 1 }]),
      (p?.price ?? 50000) * ((i % 4) + 1), status, stage,
      ["Faisal", "Hira", "Logistics", "Kamran"][i % 4],
      ["Oct 08", "Oct 12", "Oct 15", "Oct 18"][i % 4], t, t)));
  })));
  (await (ensureFinanceDemo()));
  (await (db.prepare("INSERT INTO meta(key,value) VALUES('seeded_at',?)").run(now())));
  console.log(`[seed] ${products.length} products, ${materials.length} materials, ${services.length} services, ${seedLeads.length} leads, ${seedQuotes.length} quotes, 8 orders`);
}

async function ensureFinanceDemo() {
  if ((await (db.prepare("SELECT COUNT(*) n FROM invoices").get())).n > 0) return;
  const orders = (await (db.prepare("SELECT * FROM orders ORDER BY id LIMIT 2").all()));
  const t = now();
  (await Promise.all(orders.map(async (o, i) => {
    const items = JSON.parse(o.items);
    const subtotal = items.reduce((n, x) => n + (x.price || 0) * (x.qty || 1), 0);
    const total = Math.round(subtotal * 1.05);
    const info = (await (db.prepare(`INSERT INTO invoices(ref,order_id,customer,items,subtotal,total,status,due,created_at,updated_at)
      VALUES(?,?,?,?,?,?,?,?,?,?)`).run("INV-26-" + (1001 + i), o.id, o.customer, o.items, subtotal, total,
      i === 0 ? "Issued" : "Partially Paid", new Date(Date.now() + 864e5 * 14).toISOString().slice(0, 10), t, t)));
    if (i === 1) { const paid = Math.round(total * 0.5);
      (await (db.prepare("INSERT INTO payments(invoice_id,amount,method,reference,created_at) VALUES(?,?,?,?,?)")
        .run(info.lastInsertRowid, paid, "50% advance — Bank transfer", "TRX-88231", t)));
      (await (db.prepare("UPDATE invoices SET paid=? WHERE id=?").run(paid, info.lastInsertRowid))); }
  })));
  (await (db.prepare("INSERT INTO returns(ref,order_id,customer,item,reason,state,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)")
    .run("RMA-0201", orders[1]?.id ?? null, orders[1]?.customer ?? "Walk-in", "Fabric swatch mismatch", "Color differs from configurator preview", "Requested", t, t)));
  console.log("[seed] finance demo: 2 invoices, 1 payment, 1 return");
}

async function ensureCrmDemo() {
  if ((await (db.prepare("SELECT COUNT(*) n FROM clients").get())).n > 0) return;
  const t = now();
  const seen = new Map();
  const upsert = async (name, extra = {}, entity = null, entityId = null) => {
    if (!name) return;
    const key = String(name).toLowerCase().trim();
    let c = seen.get(key) ?? (await (db.prepare("SELECT * FROM clients WHERE lower(name)=? AND merged_into IS NULL").get(key)));
    if (!c) {
      const info = (await (db.prepare("INSERT INTO clients(name,company,email,email_norm,phone,phone_norm,city,tags,notes,source,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
        .run(name, extra.company ?? null, extra.email ?? null, extra.email ? normEmail(extra.email) : null,
          extra.phone ?? null, extra.phone ? normPhone(extra.phone) : null, extra.city ?? "Lahore",
          JSON.stringify(extra.tags ?? []), extra.notes ?? null, extra.source ?? "backfill", t, t)));
      c = (await (db.prepare("SELECT * FROM clients WHERE id=?").get(info.lastInsertRowid)));
      seen.set(key, c);
    }
    if (entity) (await (db.prepare("INSERT OR IGNORE INTO client_links(client_id,entity,entity_id) VALUES(?,?,?)").run(c.id, entity, entityId)));
  };
  for (const r of (await (db.prepare("SELECT * FROM leads").all()))) (await (upsert(r.name, { company: r.company, source: r.source, notes: r.note }, "lead", r.id)));
  for (const r of (await (db.prepare("SELECT * FROM quotes").all()))) (await (upsert(r.customer, { source: r.source }, "quote", r.id)));
  for (const r of (await (db.prepare("SELECT * FROM orders").all()))) (await (upsert(r.customer, { source: "checkout" }, "order", r.id)));
  for (const r of (await (db.prepare("SELECT * FROM invoices").all()))) (await (upsert(r.customer, {}, "invoice", r.id)));
  const clifton = (await (db.prepare("SELECT id FROM clients WHERE name LIKE ?").get("%Clifton%")));
  if (clifton) (await (db.prepare("UPDATE clients SET email='sales@cliftonvilla9.example', email_norm='sales@cliftonvilla9.example' WHERE id=?").run(clifton.id)));
  if (clifton) {
    (await (db.prepare("INSERT INTO tasks(client_id,title,due,priority,done,created_at,updated_at) VALUES(?,?,?,?,?,?,?)")
      .run(clifton.id, "Confirm teak veneer swatch before production", new Date(Date.now() + 864e5).toISOString().slice(0, 10), "high", 0, t, t)));
    (await (db.prepare("INSERT INTO tasks(client_id,title,due,priority,created_at,updated_at) VALUES(?,?,?,?,?,?)")
      .run(clifton.id, "Send installment-2 reminder", new Date(Date.now() - 864e5).toISOString().slice(0, 10), "normal", t, t)));
  }
  // deliberately ambiguous pair for the review queue: same email, different phone
  (await (db.prepare("INSERT INTO clients(name,email,email_norm,phone,phone_norm,tags,source,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)")
    .run("Clifton Villa 9 (WhatsApp)", "sales@cliftonvilla9.example", "sales@cliftonvilla9.example", "+92 300 7654321", "3007654321", "[]", "WhatsApp", t, t)));
  console.log("[seed] CRM demo: clients, links, tasks, 1 review pair");
}

/* ---------------- helpers ---------------- */
const rowProduct = (r) => ({ ...JSON.parse(r.json), price: r.price, inStock: !!r.in_stock, stockQty: r.stock_qty, updatedAt: r.updated_at });
const safeJson = (value, fallback) => { try { return JSON.parse(value ?? ""); } catch { return fallback; } };
const rowQuote = (r) => ({
  id: r.id, ref: r.ref, customer: r.customer, contact: r.contact, leadId: r.lead_id ?? null,
  items: safeJson(r.items, []), total: Number(r.total) || 0,
  status: r.status, source: r.source, note: r.note, details: safeJson(r.details, {}), version: Number(r.version) || 1,
  audit: safeJson(r.audit, []), createdAt: r.created_at, updatedAt: r.updated_at,
});
const rowQuoteVersion = (r) => ({ id: r.id, quoteId: r.quote_id, version: r.version,
  snapshot: safeJson(r.snapshot, {}), actor: r.actor, action: r.action, createdAt: r.created_at });
const rowQuoteTemplate = (r) => ({
  ...normalizeQuoteTemplate(safeJson(r.template, {}), r.id), updatedAt: r.updated_at,
});
const QUOTE_STATUSES = new Set(["Draft", "Review", "Approval", "Sent", "Viewed", "Negotiation", "Revised", "Accepted", "Approved", "Rejected", "Expired", "Cancelled"]);
const quoteInputError = (message) => Object.assign(new Error(message), { statusCode: 400 });
const roundMoney = (value) => Math.round((value + Number.EPSILON) * 100) / 100;
const quoteText = (value, max = 240) => String(value ?? "").trim().slice(0, max);
const lahoreToday = () => {
  const parts = Object.fromEntries(new Intl.DateTimeFormat("en-US", {
    timeZone: "Asia/Karachi", year: "numeric", month: "2-digit", day: "2-digit",
  }).formatToParts(new Date()).map((part) => [part.type, part.value]));
  return `${parts.year}-${parts.month}-${parts.day}`;
};
const quoteDate = (value, label, fallback = "") => {
  const text = quoteText(value, 10);
  if (!text) return fallback;
  const valid = /^\d{4}-\d{2}-\d{2}$/.test(text) && !Number.isNaN(Date.parse(text + "T00:00:00Z"))
    && new Date(text + "T00:00:00Z").toISOString().slice(0, 10) === text;
  if (!valid) throw quoteInputError(`${label} must be a valid YYYY-MM-DD date`);
  return text;
};
const quoteNumber = (value, label, { fallback = 0, min = 0, max = 1_000_000_000_000 } = {}) => {
  if (value == null || value === "") return fallback;
  const n = Number(value);
  if (!Number.isFinite(n) || n < min || n > max) throw quoteInputError(`${label} must be between ${min} and ${max}`);
  return n;
};
const QUOTE_TEMPLATE_PAYMENT_NOTE = "Final payment terms will be included in formal quotation.";
const QUOTE_TEMPLATE_BLOCK_TYPES = new Set(["brand", "title", "meta", "customer", "terms", "payment-note", "contact", "custom"]);
function normalizeQuoteTemplate(value, requestedId = "") {
  if (!value || typeof value !== "object" || Array.isArray(value)) throw quoteInputError("quotation template must be an object");
  const id = quoteText(requestedId || value.id, 100);
  const name = quoteText(value.name, 100);
  if (!id) throw quoteInputError("quotation template id is required");
  if (!name) throw quoteInputError("quotation template name is required");
  const seen = new Set();
  const normalizeBlocks = (input, region) => {
    if (!Array.isArray(input)) return [];
    if (input.length > 30) throw quoteInputError(`${region} may contain at most 30 blocks`);
    return input.map((raw, index) => {
      if (!raw || typeof raw !== "object" || Array.isArray(raw)) throw quoteInputError(`${region} block ${index + 1} is invalid`);
      const type = quoteText(raw.type, 32);
      if (!QUOTE_TEMPLATE_BLOCK_TYPES.has(type)) throw quoteInputError(`${region} block ${index + 1} has an unsupported type`);
      let blockId = quoteText(raw.id, 100);
      if (!blockId || seen.has(blockId)) blockId = `${region}-${crypto.randomUUID()}`;
      seen.add(blockId);
      const isPaymentNote = type === "payment-note";
      return {
        id: blockId,
        type,
        label: quoteText(raw.label, 100) || (isPaymentNote ? "Formal payment note" : "Text block"),
        content: isPaymentNote ? QUOTE_TEMPLATE_PAYMENT_NOTE : String(raw.content ?? "").slice(0, 4000),
        visible: isPaymentNote || raw.visible !== false,
      };
    });
  };
  const header = normalizeBlocks(value.header, "header").filter((item) => item.type !== "payment-note");
  const footer = normalizeBlocks(value.footer, "footer");
  let requiredPaymentNote = null;
  const safeFooter = [];
  for (const item of footer) {
    if (item.type !== "payment-note") { safeFooter.push(item); continue; }
    if (requiredPaymentNote) continue;
    requiredPaymentNote = {
      id: item.id, type: "payment-note", label: item.label || "Formal payment note",
      content: QUOTE_TEMPLATE_PAYMENT_NOTE, visible: true,
    };
    safeFooter.push(requiredPaymentNote);
  }
  if (!requiredPaymentNote) {
    if (safeFooter.length >= 30) throw quoteInputError("footer needs room for the required formal-payment block");
    let paymentNoteId = "required-payment-note";
    if (seen.has(paymentNoteId)) paymentNoteId = `footer-${crypto.randomUUID()}`;
    safeFooter.push({ id: paymentNoteId, type: "payment-note", label: "Formal payment note", content: QUOTE_TEMPLATE_PAYMENT_NOTE, visible: true });
  }
  const brandColor = /^#[0-9a-f]{6}$/i.test(String(value.brandColor ?? "")) ? String(value.brandColor) : "#318b45";
  const companyEmail = quoteText(value.companyEmail, 254).toLowerCase();
  if (companyEmail && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(companyEmail)) throw quoteInputError("template company email must be valid");
  const companyName = quoteText(value.companyName, 160) || "Woodex Furniture";
  const logoText = quoteText(value.logoText, 3).toUpperCase() || companyName.trim().charAt(0).toUpperCase();
  return {
    id,
    name,
    brandColor,
    logoText,
    companyName,
    companyAddress: quoteText(value.companyAddress, 500),
    companyPhone: quoteText(value.companyPhone, 80),
    companyEmail,
    header,
    footer: safeFooter,
    ...(value.updatedAt ? { updatedAt: quoteText(value.updatedAt, 40) } : {}),
  };
}
function normalizeQuotePayload(body = {}, previous = null) {
  const has = (key) => Object.prototype.hasOwnProperty.call(body, key);
  const customer = quoteText(has("customer") ? body.customer : previous?.customer, 120);
  if (!customer) throw quoteInputError("customer is required");

  const rawDetails = body.details && typeof body.details === "object" && !Array.isArray(body.details) ? body.details : {};
  const detailsInput = { ...(previous?.details ?? {}), ...rawDetails };
  for (const key of ["company", "email", "phone", "siteAddress"]) {
    if (has(key)) detailsInput[key] = body[key];
  }
  if (has("address")) detailsInput.siteAddress = body.address;
  const company = quoteText(detailsInput.company, 160);
  const email = quoteText(detailsInput.email, 254).toLowerCase();
  if (email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) throw quoteInputError("email must be valid");
  const phone = quoteText(detailsInput.phone, 80);
  if (phone && phone.replace(/\D/g, "").length < 7) throw quoteInputError("phone must contain at least 7 digits");
  const siteAddress = quoteText(detailsInput.siteAddress, 500);
  const currency = quoteText(detailsInput.currency || "PKR", 3).toUpperCase();
  if (currency !== "PKR") throw quoteInputError("Only PKR is supported; currency conversion is not configured");

  const issueDate = quoteDate(detailsInput.issueDate, "Issue date", previous?.details?.issueDate || lahoreToday());
  const validUntil = quoteDate(detailsInput.validUntil, "Valid until", "");
  if (validUntil && issueDate && validUntil < issueDate) throw quoteInputError("Valid until cannot be earlier than the issue date");
  const discountPercent = quoteNumber(detailsInput.discountPercent, "Discount percent", { fallback: 0, min: 0, max: 100 });
  const taxPercent = quoteNumber(detailsInput.taxPercent, "Tax percent", { fallback: 0, min: 0, max: 100 });
  const shipping = roundMoney(quoteNumber(detailsInput.shipping, "Delivery / installation amount", { fallback: 0, min: 0 }));
  const terms = String(detailsInput.terms ?? "").slice(0, 4000);
  const requestedTemplateId = quoteText(detailsInput.quoteTemplateId, 100);
  const rawTemplateSnapshot = detailsInput.quoteTemplateSnapshot && typeof detailsInput.quoteTemplateSnapshot === "object"
    ? detailsInput.quoteTemplateSnapshot
    : null;
  const snapshotTemplateId = quoteText(rawTemplateSnapshot?.id, 100);
  if (requestedTemplateId && snapshotTemplateId && requestedTemplateId !== snapshotTemplateId)
    throw quoteInputError("quotation template id does not match its snapshot");
  const quoteTemplateSnapshot = rawTemplateSnapshot
    ? normalizeQuoteTemplate(rawTemplateSnapshot, requestedTemplateId)
    : null;
  const quoteTemplateId = quoteTemplateSnapshot?.id ?? requestedTemplateId;

  const rawItems = has("items") ? body.items : previous?.items;
  if (!Array.isArray(rawItems) || rawItems.length < 1 || rawItems.length > 100)
    throw quoteInputError("at least one and at most 100 quote items are required");
  const items = rawItems.map((raw, index) => {
    if (!raw || typeof raw !== "object" || Array.isArray(raw)) throw quoteInputError(`item ${index + 1} is invalid`);
    // A line that names a catalog product gets its name from the catalog later; only
    // free-text (custom) lines must carry one at the boundary.
    const hasProductRef = Boolean(String(raw.productId ?? raw.id ?? "").trim());
    const name = quoteText(raw.name, 160);
    if (!name && !hasProductRef) throw quoteInputError(`item ${index + 1} needs a name`);
    const price = roundMoney(quoteNumber(raw.price, `item ${index + 1} unit price`, { fallback: 0, min: 0, max: 1_000_000_000 }));
    const qty = Math.round(quoteNumber(raw.qty, `item ${index + 1} quantity`, { fallback: 1, min: 0.01, max: 100_000 }) * 100) / 100;
    const item = {
      ...(raw.id != null ? { id: quoteText(raw.id, 80) } : {}),
      ...(raw.productId != null ? { productId: quoteText(raw.productId, 120) } : {}),
      name, price, qty, lineTotal: roundMoney(price * qty),
    };
    for (const key of ["category", "series", "material", "finish", "customNotes"]) {
      if (raw[key] != null && String(raw[key]).trim()) item[key] = quoteText(raw[key], key === "customNotes" ? 500 : 120);
    }
    for (const key of ["width", "height", "depth"]) {
      if (raw[key] != null && raw[key] !== "") item[key] = quoteNumber(raw[key], `item ${index + 1} ${key}`, { min: 0, max: 100_000 });
    }
    if (raw.dimensionUnit) {
      const unit = quoteText(raw.dimensionUnit, 8).toLowerCase();
      if (!["mm", "cm", "in"].includes(unit)) throw quoteInputError(`item ${index + 1} dimension unit must be mm, cm or in`);
      item.dimensionUnit = unit;
    }
    return item;
  });

  const subtotal = roundMoney(items.reduce((sum, item) => sum + item.lineTotal, 0));
  const discount = roundMoney(subtotal * discountPercent / 100);
  const tax = roundMoney((subtotal - discount) * taxPercent / 100);
  const total = roundMoney(subtotal - discount + tax + shipping);
  const details = {
    company, email, phone, siteAddress, issueDate, validUntil, currency,
    discountPercent, taxPercent, shipping, terms,
    ...(quoteTemplateId ? { quoteTemplateId } : {}),
    ...(quoteTemplateSnapshot ? { quoteTemplateSnapshot } : {}),
    calculation: { currency, subtotal, discount, tax, shipping, total },
  };
  const contactValue = has("contact") ? quoteText(body.contact, 320)
    : previous?.contact || [phone, email].filter(Boolean).join(" · ");
  return {
    customer, contact: contactValue || [phone, email].filter(Boolean).join(" · ") || null,
    items, total, details,
    source: quoteText(has("source") ? body.source : previous?.source || "storefront", 80) || "storefront",
    note: has("note") ? (quoteText(body.note, 2000) || null) : (previous?.note ?? null),
  };
}
const quoteContentSnapshot = (quote) => ({
  customer: quote.customer, contact: quote.contact, leadId: quote.leadId ?? null,
  items: quote.items, total: quote.total, source: quote.source, note: quote.note, details: quote.details,
});
async function insertQuoteVersion(quoteId, version, quote, actor, action, createdAt = now()) {
  await db.prepare("INSERT INTO quote_versions(quote_id,version,snapshot,actor,action,created_at) VALUES(?,?,?,?,?,?)")
    .run(quoteId, version, JSON.stringify(quoteContentSnapshot(quote)), actor, action, createdAt);
}
async function ensureQuoteVersionBaselines() {
  const rows = await db.prepare("SELECT * FROM quotes ORDER BY id").all();
  for (const row of rows) {
    const version = Number(row.version) || 1;
    const exists = await db.prepare("SELECT id FROM quote_versions WHERE quote_id=? AND version=?").get(row.id, version);
    if (!exists) await insertQuoteVersion(row.id, version, rowQuote(row), "System", "Initial version captured", row.created_at || now());
  }
}
const rowLead = (r) => ({
  id: r.id, ref: r.ref, name: r.name, company: r.company, interest: r.interest, source: r.source,
  contact: r.contact, email: r.email, phone: r.phone, preferredChannel: r.preferred_channel,
  contactConsent: r.contact_consent == null ? null : !!Number(r.contact_consent),
  returning: r.is_returning == null ? null : !!Number(r.is_returning),
  identityReview: r.identity_review == null ? null : !!Number(r.identity_review),
  consentAt: r.consent_at, consentVersion: r.consent_version, landingPath: r.landing_path,
  referrer: r.referrer, utmSource: r.utm_source, utmMedium: r.utm_medium, utmCampaign: r.utm_campaign,
  status: r.status, owner: r.owner, note: r.note, createdAt: r.created_at, updatedAt: r.updated_at,
});
const rowOrder = (r) => ({ id: r.id, ref: r.ref, customer: r.customer, items: JSON.parse(r.items), total: r.total, status: r.status, stage: r.stage, owner: r.owner, due: r.due, createdAt: r.created_at, updatedAt: r.updated_at });
const rowInvoice = (r) => ({ id: r.id, ref: r.ref, quoteId: r.quote_id, orderId: r.order_id, customer: r.customer,
  items: JSON.parse(r.items || "[]"), subtotal: r.subtotal, discount: r.discount, tax: r.tax, shipping: r.shipping,
  total: r.total, paid: r.paid, balance: Math.max(0, r.total - r.paid), status: r.status, due: r.due, notes: r.notes,
  createdAt: r.created_at, updatedAt: r.updated_at });
const decorateInvoice = (r) => { const j = rowInvoice(r);
  if (j.due && j.balance > 0 && ["Issued", "Partially Paid"].includes(j.status) && j.due < now().slice(0, 10)) j.status = "Overdue";
  return j; };
const rowReturn = (r) => ({ id: r.id, ref: r.ref, orderId: r.order_id, customer: r.customer, item: r.item, reason: r.reason,
  state: r.state, refundAmount: r.refund_amount, resolution: r.resolution, createdAt: r.created_at, updatedAt: r.updated_at });
const wrap = (fn) => (req, res) => Promise.resolve().then(() => fn(req, res)).catch((e) => {
  const status = Number(e.statusCode) || 500;
  // 5xx bodies stay generic: driver/stack detail belongs in the server log, not the response.
  if (status >= 500) console.error(`[woodex-api] ${req.method} ${req.originalUrl} → 500 ·`, e?.stack ?? e);
  if (!res.headersSent) res.status(status).json({ error: status >= 500 ? "internal error" : String(e.message || e) });
  else res.end();
});
/* TODO-2 (QA): compound write flows run atomically — a mid-flow throw rolls the whole
   route back (no order-without-invoice half-state). */
const wtx = (fn) => db.transaction(fn);
const wrapTx = (fn) => wrap(async (req, res) => wtx(() => fn(req, res)));
/* Responding from inside a transaction races the commit: the client's next request
   (dashboard drill-down, payment, QA assertion) can open a snapshot that does not yet
   contain the row and get a spurious 404. These routes build the reply, let COMMIT
   land, and only then write the response. */
const wrapTxJson = (fn) => wrap(async (req, res) => {
  const afterCommit = [];
  const result = await db.transaction(() => fn(req, afterCommit));
  // Business rows carry their own `status` ("Sent", "Partially Paid", …) — only an
  // integer is ever an HTTP code.
  for (const effect of afterCommit) {
    try { effect(); } catch (error) { console.error("[woodex-api] post-commit effect failed:", error.message); }
  }
  const status = Number.isInteger(result?.status) ? result.status : 200;
  res.status(status).json(result?.body ?? result ?? { ok: true });
});

// For public capture routes, return success only after the durable transaction commits.
const wrapTxResponse = (fn) => wrap(async (req, res) => {
  const afterCommit = [];
  const result = await db.transaction(() => fn(req, afterCommit));
  for (const effect of afterCommit) {
    try { effect(); } catch (error) { console.error("[woodex-api] post-commit effect failed:", error.message); }
  }
  res.status(result?.status ?? 200).json(result?.body ?? result);
});

/* ---------------- app ---------------- */
const app = express();
app.disable("x-powered-by");
app.set("trust proxy", IS_SERVERLESS ? 1 : "loopback"); // Vercel terminates at a trusted single proxy hop; local previews trust only their loopback proxy.

/* Baseline response hardening. The dashboard is designed to run inside preview iframes,
   so framing is allowed — but only for this origin family, never `*`. */
const PUBLIC_ORIGINS = String(process.env.WOODEX_PUBLIC_ORIGINS ?? "")
  .split(",").map((origin) => origin.trim()).filter(Boolean);
app.use((req, res, next) => {
  res.set("X-Content-Type-Options", "nosniff");
  res.set("Referrer-Policy", "strict-origin-when-cross-origin");
  res.set("X-Frame-Options", "SAMEORIGIN");
  res.set("Cross-Origin-Resource-Policy", "same-origin");
  res.set("Permissions-Policy", "geolocation=(), microphone=(), camera=()");
  if (IS_SERVERLESS || IS_PRODUCTION) res.set("Strict-Transport-Security", "max-age=31536000; includeSubDomains");
  const origin = String(req.headers.origin ?? "");
  const allowed = origin && (PUBLIC_ORIGINS.includes(origin) || /^https:\/\/[a-z0-9-]+(\.[a-z0-9-]+)*\.vercel\.app$/i.test(origin));
  if (allowed) {
    res.set("Access-Control-Allow-Origin", origin);
    res.set("Vary", "Origin");
    res.set("Access-Control-Allow-Headers", "authorization, x-woodex-session, content-type, accept");
    res.set("Access-Control-Allow-Methods", "GET, POST, PUT, PATCH, DELETE, OPTIONS");
    res.set("Access-Control-Max-Age", "600");
  }
  if (req.method === "OPTIONS") return res.status(204).end();
  next();
});

/* Cheap per-IP token buckets for the public write paths. Login keeps its own
   stricter per-email limiter further below; this closes bare form/spam floods. */
const publicWrites = new Map();
const PUBLIC_WRITE_LIMITS = [
  // Business writes: enough headroom for a busy showroom kiosk on one IP, far below
  // anything a script needs to poison the pipeline.
  { test: (p) => /^\/api\/(leads|quotes|orders|target)$/.test(p), max: 30, windowMs: 60_000 },
  // The analytics pixel is one beacon per page view; it must never throttle a real visitor.
  { test: (p) => p === "/api/track", max: 120, windowMs: 60_000 },
];
const throttlePublicWrites = (req, res, next) => {
  if (req.method === "GET" || req.method === "HEAD" || req.method === "OPTIONS") return next();
  const rule = PUBLIC_WRITE_LIMITS.find((entry) => entry.test(req.path));
  if (!rule) return next();
  const key = (req.ip ?? "local") + " " + req.path;
  const nowMs = Date.now();
  const bucket = publicWrites.get(key) ?? { n: 0, t: nowMs };
  if (nowMs - bucket.t > rule.windowMs) { bucket.n = 0; bucket.t = nowMs; }
  if (bucket.n >= rule.max) {
    res.set("retry-after", String(Math.ceil((rule.windowMs - (nowMs - bucket.t)) / 1000)));
    return res.status(429).json({ error: "too many requests — please wait a minute and try again" });
  }
  bucket.n += 1;
  publicWrites.set(key, bucket);
  if (publicWrites.size > 5_000) for (const [k, v] of publicWrites) if (nowMs - v.t > rule.windowMs * 4) publicWrites.delete(k);
  next();
};
app.use(throttlePublicWrites);
app.use(express.json({ limit: "2mb" }));

/* ---- Foundation: session auth + RBAC (bearer token; no cookies) ---- */
const ROLE_CAPS = {
  owner: ["*"],
  editor: ["page.manage", "theme.manage", "catalog.manage"],
  sales: ["crm.manage", "quote.manage", "order.manage", "project.manage"],
  finance: ["finance.manage", "quote.view-move", "order.view-move"],
  viewer: [],
};
const ROLES = Object.keys(ROLE_CAPS);
const capsFor = (m, path) => {
  if (path.startsWith("/api/auth")) return "auth";
  if (path.startsWith("/api/users")) return "users.manage";
  if (path === "/api/events") return "owner-only";
  if (path === "/api/diagnostics/queries") return "owner-only";
  if (path === "/api/blocks") return "page.manage";
  if (path === "/api/track") return "purge";
  if (path.startsWith("/api/pages") || path.startsWith("/api/saved-sections") || path.startsWith("/api/media")) return "page.manage";
  if (path.startsWith("/api/theme") || path.startsWith("/api/target")) return "theme.manage";
  if (path.startsWith("/api/leads") || path.startsWith("/api/clients") || path.startsWith("/api/inbox") || path.startsWith("/api/tasks") || path.startsWith("/api/site-visits")) return "crm.manage";
  if (path.startsWith("/api/quotes") || path.startsWith("/api/quote-templates")) return "quote.manage";
  if (path.startsWith("/api/projects")) return "project.manage";
  if (path.startsWith("/api/orders")) return "order.manage";
  if (path.startsWith("/api/invoices") || path.startsWith("/api/payments") || path.startsWith("/api/returns")) return "finance.manage";
  if (path.startsWith("/api/products") || path.startsWith("/api/materials") || path.startsWith("/api/services") || path.startsWith("/api/categories") || path.startsWith("/api/collections")) return "catalog.manage";
  return "owner-only";
};
const isPublic = (m, p) =>
  m === "GET" && (
    p === "/api/health" || p === "/api/theme" ||
    /^\/api\/(products|categories|collections|materials|services)(\/|$)/.test(p) ||
    /^\/api\/pages\/[^/]+\/public$/.test(p) ||
    p.startsWith("/img/") || p === "/uploads" || /^\/(uploads|img)\//.test(p) ||
    p === "/sitemap.xml" || p === "/robots.txt"
  ) || (m === "POST" && (
    /^\/api\/(track|leads|quotes|orders|auth\/login)$/.test(p)
    || /^\/api\/orders\/lookup$/.test(p)
    || /^\/api\/leads\/\d+\/whatsapp-handoff$/.test(p)
  ));
const requestToken = (req) => {
  const auth = String(req.headers.authorization ?? "");
  const bearer = auth.match(/^Bearer\s+(.+)$/i)?.[1] ?? "";
  /* EventSource cannot set request headers, so the stream may authenticate with
     ?token=. Scoped to that one endpoint: no other route accepts a URL token,
     because URLs leak into logs and Referer headers. */
  const queryToken = req.path === "/api/events" ? String(req.query?.token ?? "") : "";
  return bearer || String(req.headers["x-woodex-session"] ?? "") || queryToken;
};
/* ---------- all-in-one hosting (Hostinger Shape B) ----------
   When WOODEX_SERVE_STATIC points at the built `dist/`, this process serves the
   storefront and dashboard too: one Node app, one origin, one port. That is the
   simplest deployment the client can run on shared hosting, and it also lets us
   rehearse production locally (`npm run start:hostinger`).

   Ordering is the whole trick. This must run BEFORE the session middleware (or
   every page request returns 401), it must never answer /api/*, and it must not
   send a file for a directory path — express.static would answer `<route>/` with
   a 301 redirect instead. Serving `dist/<route>/index.html` directly keeps every
   prerendered URL a clean 200. */
const STATIC_ROOT = process.env.WOODEX_SERVE_STATIC ? path.resolve(process.env.WOODEX_SERVE_STATIC) : "";
if (STATIC_ROOT && fs.existsSync(STATIC_ROOT)) {
  const indexHtml = path.join(STATIC_ROOT, "index.html");
  const dashboardHtml = path.join(STATIC_ROOT, "dashboard/index.html");
  const sendHtml = (file, res) => {
    res.setHeader("cache-control", "no-cache");
    res.sendFile(file);
  };
  // Hashed bundles are immutable; a deploy renames them.
  app.use("/assets", express.static(path.join(STATIC_ROOT, "assets"), { immutable: true, maxAge: "1y" }));
  app.use("/img", express.static(path.join(HERE, "public/img"), { maxAge: "1h" }));
  app.use((req, res, next) => {
    if (req.method !== "GET" && req.method !== "HEAD") return next();
    const clean = decodeURIComponent(req.path).replace(/\.html$/, "").replace(/\/+$/, "") || "/";
    if (clean === "/api" || clean.startsWith("/api/")) return next();   // never answer the API
    if (clean === "/dashboard") return fs.existsSync(dashboardHtml) ? sendHtml(dashboardHtml, res) : next();
    if (clean.startsWith("/dashboard/")) return fs.existsSync(dashboardHtml) ? sendHtml(dashboardHtml, res) : next();
    if (clean === "/sitemap.xml" || clean === "/robots.txt") return next(); // the API owns these
    const candidate = clean === "/" ? indexHtml : path.join(STATIC_ROOT, clean, "index.html");
    const flat = clean === "/" ? null : path.join(STATIC_ROOT, clean);
    if (candidate && candidate.startsWith(STATIC_ROOT) && fs.existsSync(candidate) && fs.statSync(candidate).isFile()) return sendHtml(candidate, res);
    if (flat && flat.startsWith(STATIC_ROOT) && fs.existsSync(flat) && fs.statSync(flat).isFile()) return res.sendFile(flat);
    // Deep link that was never prerendered: hand the SPA shell to the router.
    if (path.extname(clean)) return next();
    return fs.existsSync(indexHtml) ? sendHtml(indexHtml, res) : next();
  });
  console.log(`[woodex-api] serving static build from ${STATIC_ROOT}`);
} else if (STATIC_ROOT) {
  console.warn(`[woodex-api] WOODEX_SERVE_STATIC=${STATIC_ROOT} does not exist — API only.`);
}

app.use(async (req, res, next) => { try {
  /* Public paths stay accessible without a session, but a *valid* token must still be
     resolved: staff acting through a public route (dashboard quote builder, embed)
     need `req.actor`, and skipping the lookup made their writes look anonymous —
     which silently downgraded them to storefront defaults (wrong pricing, status
     forced to Draft, lead auto-capture). An invalid token on a public path is still
     treated as an anonymous visitor, never as an error. */
  const token = requestToken(req);
  if (isPublic(req.method, req.path)) {
    if (!token) return next();
    const visitor = (await (db.prepare("SELECT u.id, u.email, u.name, u.role, u.active, s.expires_at FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=?").get(token)));
    if (visitor && visitor.active && String(visitor.expires_at) >= now()) { req.user = visitor; req.actor = visitor.name; }
    return next();
  }
  if (!token) return res.status(401).json({ error: "sign in required", code: "no_token" });
  const u = (await (db.prepare("SELECT u.id, u.email, u.name, u.role, u.active, s.expires_at FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=?").get(token)));
  if (!u) return res.status(401).json({ error: "session expired — sign in again", code: "bad_token" });
  if (String(u.expires_at) < now()) { (await (db.prepare("DELETE FROM sessions WHERE token=?").run(token))); return res.status(401).json({ error: "session expired", code: "expired" }); }
  if (!u.active) return res.status(403).json({ error: "account disabled" });
  req.user = u; req.actor = u.name;
  const caps = ROLE_CAPS[u.role] ?? [];
  if (req.path.startsWith("/api/users") && req.method === "GET" && !caps.includes("*") && !caps.includes("users.manage"))
    return res.status(403).json({ error: "team roster is owner-only", need: "users.manage" });

  const selfAuthRoute = (req.method === "GET" && req.path === "/api/auth/me")
    || (req.method === "POST" && req.path === "/api/auth/logout");
  const need = capsFor(req.method, req.path);
  if (!selfAuthRoute && req.method === "GET") {
    const readOnlyMove = (need === "quote.manage" && caps.includes("quote.view-move"))
      || (need === "order.manage" && caps.includes("order.view-move"));
    if (!caps.includes("*") && !caps.includes(need) && !readOnlyMove)
      return res.status(403).json({ error: `role '${u.role}' may not read ${need}`, need });
  }
  if (req.method !== "GET" && !selfAuthRoute) {
    const quoteMoveOnly = need === "quote.manage" && caps.includes("quote.view-move");
    const orderMoveOnly = need === "order.manage" && caps.includes("order.view-move");
    const moveOnly = quoteMoveOnly || orderMoveOnly;
    if (quoteMoveOnly && (req.method !== "PATCH" || Object.keys(req.body ?? {}).some((key) => key !== "status")))
      return res.status(403).json({ error: `role '${u.role}' may only move quote status`, need: "quote.manage" });
    if (orderMoveOnly && (req.method !== "PATCH" || Object.keys(req.body ?? {}).some((key) => !["status", "stage"].includes(key))))
      return res.status(403).json({ error: `role '${u.role}' may only move order status or stage`, need: "order.manage" });
    if (!caps.includes("*") && !caps.includes(need) && !moveOnly)
      return res.status(403).json({ error: `role '${u.role}' may not ${need} — an owner can grant it`, need });
  }
  next();
} catch (error) { next(error); } });
const hashPass = (pw, salt = crypto.randomBytes(8).toString("hex")) =>
  ({ salt, hash: crypto.scryptSync(String(pw), salt, 32).toString("hex") });
const loginBucket = new Map();


app.get("/api/health", (req, res) => {
  // Keep the public liveness response deliberately free of customer and finance counts.
  res.json({ ok: true, service: "woodex-api", ts: now() });
});
/* N+1 hunting: `db.queries` is cumulative, so a test takes two readings around
   one request and asserts the delta does not grow with row count. Exposed as a
   capability so only tooling (never the dashboard) can read it. */
app.get("/api/diagnostics/queries", (req, res) => {
  if (process.env.NODE_ENV === "production") return res.status(404).json({ error: "not found" });
  res.json({ queries: db.queries ?? null, driver: db.kind });
});

const PRODUCT_ID_RE = /^[a-z0-9][a-z0-9-]{0,78}[a-z0-9]$/;
const productImageRef = (value) => {
  const image = String(value ?? "").trim();
  if (/^https:\/\//i.test(image)) return image;
  if (image.startsWith("/img/")) return /^[\w.-]+\.(jpg|jpeg|png|webp)$/i.test(image.slice(5)) ? image.slice(5) : null;
  return /^[\w.-]+\.(jpg|jpeg|png|webp)$/i.test(image) ? image : null;
};
const productText = (value, max = 4000) => String(value ?? "").trim().slice(0, max);
function normalizeProduct(input, base = {}, id = base.id) {
  const raw = { ...base, ...input, id };
  const name = productText(raw.name, 140);
  const category = productText(raw.category, 80);
  const description = productText(raw.description, 5000);
  const shortDescription = productText(raw.shortDescription || description, 240);
  const price = Number(raw.price);
  const stockQty = Number(raw.stockQty ?? 0);
  if (!name) return { error: "name is required" };
  if (!category) return { error: "category is required" };
  if (!Number.isFinite(price) || price <= 0) return { error: "price must be greater than 0" };
  if (!Number.isInteger(stockQty) || stockQty < 0) return { error: "stockQty must be a non-negative whole number" };
  if (raw.images !== undefined && !Array.isArray(raw.images)) return { error: "images must be an array" };
  const images = (Array.isArray(raw.images) ? raw.images : []).map(productImageRef);
  if (images.some((image) => !image)) return { error: "images must be library file names or secure https URLs" };
  if (!images.length) return { error: "at least one product image is required" };
  if (raw.colors !== undefined && !Array.isArray(raw.colors)) return { error: "colors must be an array" };
  const colors = (Array.isArray(raw.colors) ? raw.colors : []).map((color) => ({
    name: productText(color?.name, 60),
    hex: productText(color?.hex, 9),
  })).filter((color) => color.name && color.hex);
  if (colors.some((color) => !/^#[\da-f]{3,8}$/i.test(color.hex))) return { error: "each color needs a valid hex value" };
  if (raw.specifications !== undefined && !Array.isArray(raw.specifications)) return { error: "specifications must be an array" };
  const specifications = (Array.isArray(raw.specifications) ? raw.specifications : []).map((spec) => ({
    label: productText(spec?.label, 80), value: productText(spec?.value, 240),
  })).filter((spec) => spec.label || spec.value);
  if (raw.features !== undefined && !Array.isArray(raw.features)) return { error: "features must be an array" };
  const features = (Array.isArray(raw.features) ? raw.features : []).map((feature) => productText(feature, 180)).filter(Boolean).slice(0, 30);
  const originalPriceValue = raw.originalPrice === "" || raw.originalPrice == null ? null : Number(raw.originalPrice);
  if (originalPriceValue !== null && (!Number.isFinite(originalPriceValue) || originalPriceValue <= 0)) return { error: "originalPrice must be greater than 0 or blank" };
  if (typeof raw.inStock !== "boolean") return { error: "inStock must be a boolean" };
  const rating = Number(raw.rating ?? 0);
  const reviews = Number(raw.reviews ?? 0);
  if (!Number.isFinite(rating) || rating < 0 || rating > 5) return { error: "rating must be between 0 and 5" };
  if (!Number.isInteger(reviews) || reviews < 0) return { error: "reviews must be a non-negative whole number" };
  return { value: {
    id, name, category,
    subcategory: productText(raw.subcategory, 80) || undefined,
    series: productText(raw.series, 80) || undefined,
    price,
    originalPrice: originalPriceValue ?? undefined,
    description,
    shortDescription,
    images,
    colors,
    specifications,
    features,
    inStock: raw.inStock,
    stockQty,
    isNew: raw.isNew === true,
    isBestSeller: raw.isBestSeller === true,
    rating,
    reviews,
  } };
}

app.get("/api/products", wrap(async (req, res) => {
  const q = String(req.query.q || "").toLowerCase();
  const sub = req.query.sub || "";
  let rows = (await (db.prepare("SELECT * FROM products ORDER BY id").all()));
  let items = rows.map(rowProduct);
  if (sub) items = items.filter((p) => p.subcategory === sub);
  if (q) items = items.filter((p) => (p.name + " " + (p.series ?? "") + " " + (p.category ?? "")).toLowerCase().includes(q));
  res.json({ total: items.length, items });
}));

app.get("/api/products/overrides", wrap(async (req, res) => {
  const since = req.query.since ? Date.parse(req.query.since) : 0;
  const items = (await (db
    .prepare("SELECT id, price, in_stock, updated_at FROM products WHERE datetime(updated_at) > datetime(?)")
    .all(new Date(since).toISOString())))
    .map((r) => ({ id: r.id, price: r.price, inStock: !!r.in_stock, updatedAt: r.updated_at }));
  res.json({ serverTime: now(), items });
}));

app.get("/api/products/:id", wrap(async (req, res) => {
  const r = (await (db.prepare("SELECT * FROM products WHERE id = ?").get(req.params.id)));
  if (!r) return res.status(404).json({ error: "not found" });
  res.json(rowProduct(r));
}));

app.post("/api/products", wrap(async (req, res) => {
  const body = req.body ?? {};
  const id = String(body.id ?? "").trim().toLowerCase();
  if (!PRODUCT_ID_RE.test(id)) return res.status(400).json({ error: "id must be a lowercase product slug (letters, numbers and dashes; 2–80 chars)" });
  if (await db.prepare("SELECT id FROM products WHERE id=?").get(id)) return res.status(409).json({ error: "a product with this id already exists" });
  const parsed = normalizeProduct({ ...body, stockQty: body.stockQty ?? (body.inStock === false ? 0 : 99), inStock: body.inStock ?? true }, {}, id);
  if (parsed.error) return res.status(400).json({ error: parsed.error });
  const t = now();
  const product = parsed.value;
  await db.prepare("INSERT INTO products(id,json,price,in_stock,stock_qty,updated_at) VALUES(?,?,?,?,?,?)")
    .run(id, JSON.stringify(product), product.price, product.inStock ? 1 : 0, product.stockQty, t);
  emit("products", { title: "Product created: " + product.name.slice(0, 60), who: req.actor ?? "Catalog" });
  res.status(201).json(rowProduct(await db.prepare("SELECT * FROM products WHERE id=?").get(id)));
}));

app.patch("/api/products/:id", wrap(async (req, res) => {
  const r = (await (db.prepare("SELECT * FROM products WHERE id = ?").get(req.params.id)));
  if (!r) return res.status(404).json({ error: "not found" });
  const base = { ...JSON.parse(r.json), stockQty: r.stock_qty };
  const parsed = normalizeProduct(req.body ?? {}, base, r.id);
  if (parsed.error) return res.status(400).json({ error: parsed.error });
  const product = parsed.value;
  const t = now();
  await db.prepare("UPDATE products SET json=?, price=?, in_stock=?, stock_qty=?, updated_at=? WHERE id=?")
    .run(JSON.stringify(product), product.price, product.inStock ? 1 : 0, product.stockQty, t, r.id);
  const out = rowProduct(await db.prepare("SELECT * FROM products WHERE id=?").get(r.id));
  emit("products", { title: "Product updated: " + product.name.slice(0, 60), who: req.actor ?? "Catalog" });
  res.json(out);
}));

app.delete("/api/products/:id", wrap(async (req, res) => {
  const r = await db.prepare("SELECT * FROM products WHERE id=?").get(req.params.id);
  if (!r) return res.status(404).json({ error: "not found" });
  await db.prepare("DELETE FROM products WHERE id=?").run(r.id);
  emit("products", { title: "Product removed: " + JSON.parse(r.json).name.slice(0, 60), who: req.actor ?? "Catalog" });
  res.json({ deleted: r.id });
}));

app.get("/api/materials", wrap(async (req, res) => res.json((await (db.prepare("SELECT json FROM materials").all())).map((r) => JSON.parse(r.json)))));
app.get("/api/services", wrap(async (req, res) => res.json((await (db.prepare("SELECT json FROM services").all())).map((r) => JSON.parse(r.json)))));

/* Quotes — storefront submits, dashboard works the pipeline */
app.get("/api/quote-templates", wrap(async (req, res) => {
  const rows = await db.prepare("SELECT * FROM quote_templates ORDER BY datetime(updated_at) DESC, lower(name)").all();
  res.json({ total: rows.length, items: rows.map(rowQuoteTemplate) });
}));
app.post("/api/quote-templates", wrap(async (req, res) => {
  const id = `qt-${crypto.randomUUID()}`;
  const t = now();
  const template = { ...normalizeQuoteTemplate(req.body, id), updatedAt: t };
  await db.prepare("INSERT INTO quote_templates(id,name,template,created_at,updated_at) VALUES(?,?,?,?,?)")
    .run(template.id, template.name, JSON.stringify(template), t, t);
  res.status(201).json(template);
}));
app.patch("/api/quote-templates/:id", wrap(async (req, res) => {
  const existing = await db.prepare("SELECT * FROM quote_templates WHERE id=?").get(req.params.id);
  if (!existing) return res.status(404).json({ error: "quotation template not found" });
  const t = now();
  const template = { ...normalizeQuoteTemplate(req.body, existing.id), updatedAt: t };
  await db.prepare("UPDATE quote_templates SET name=?, template=?, updated_at=? WHERE id=?")
    .run(template.name, JSON.stringify(template), t, existing.id);
  res.json(template);
}));
app.delete("/api/quote-templates/:id", wrap(async (req, res) => {
  const result = await db.prepare("DELETE FROM quote_templates WHERE id=?").run(req.params.id);
  if (!result.changes) return res.status(404).json({ error: "quotation template not found" });
  res.json({ id: req.params.id, deleted: true });
}));

app.get("/api/quotes", wrap(async (req, res) => {
  let rows = (await (db.prepare("SELECT * FROM quotes ORDER BY datetime(created_at) DESC").all()));
  if (req.query.status) rows = rows.filter((r) => r.status === req.query.status);
  res.json({ total: rows.length, items: rows.map(rowQuote) });
}));
app.get("/api/quotes/:id/versions", wrap(async (req, res) => {
  const quote = await db.prepare("SELECT id FROM quotes WHERE id=? OR ref=?").get(req.params.id, req.params.id);
  if (!quote) return res.status(404).json({ error: "not found" });
  const rows = await db.prepare("SELECT * FROM quote_versions WHERE quote_id=? ORDER BY version").all(quote.id);
  res.json({ quoteId: quote.id, items: rows.map(rowQuoteVersion) });
}));
app.get("/api/quotes/:id", wrap(async (req, res) => {
  const row = await db.prepare("SELECT * FROM quotes WHERE id=? OR ref=?").get(req.params.id, req.params.id);
  if (!row) return res.status(404).json({ error: "not found" });
  const client = await db.prepare("SELECT client_id FROM client_links WHERE entity='quote' AND entity_id=?").get(row.id);
  res.json({ ...rowQuote(row), clientId: client?.client_id ?? null });
}));
/* Public quotation intake must never trust client pricing. Every line that names a
   catalog product is re-priced from `products`; unknown ids are rejected. Lines with
   no product reference are custom-furniture requests whose price is a *request*, not
   an agreed amount — they are stored as 0 so no client number ever reaches a total.
   Anonymous visitors also cannot set their own discount, tax or shipping.
   Staff building a quote in the dashboard keep full authority over custom pricing. */
async function applyCatalogPricingToPublicIntake(quote) {
  const priced = [];
  for (const [index, item] of quote.items.entries()) {
    const productId = String(item.productId ?? item.id ?? "").trim();
    if (!productId) { priced.push({ ...item, price: 0, lineTotal: 0 }); continue; }
    if (!PRODUCT_ID_RE.test(productId)) throw quoteInputError(`item ${index + 1} is not an available catalog product`);
    const product = await db.prepare("SELECT id,json,price,in_stock,stock_qty FROM products WHERE id=?").get(productId);
    if (!product) throw quoteInputError(`item ${index + 1} is not an available catalog product`);
    if (!Number(product.in_stock) || Number(product.stock_qty) < item.qty)
      throw Object.assign(new Error(`${safeJson(product.json, {}).name ?? "A selected product"} is unavailable in the requested quantity`), { statusCode: 409 });
    const catalogName = String(safeJson(product.json, {}).name ?? product.id).slice(0, 160);
    const unitPrice = Number(product.price);
    priced.push({ ...item, id: product.id, name: catalogName, price: unitPrice, lineTotal: roundMoney(unitPrice * item.qty) });
  }
  const subtotal = roundMoney(priced.reduce((sum, item) => sum + item.lineTotal, 0));
  const details = { ...quote.details, discountPercent: 0, taxPercent: 0, shipping: 0 };
  const total = subtotal;
  details.calculation = { currency: details.currency ?? "PKR", subtotal, discount: 0, tax: 0, shipping: 0, total };
  return { ...quote, items: priced, total, details };
}

app.post("/api/quotes", wrapTxResponse(async (req, afterCommit) => {
  const body = req.body ?? {};
  const source = req.actor ? (quoteText(body.source || "dashboard", 80) || "dashboard") : "storefront";
  const normalized = normalizeQuotePayload({ ...body, source });
  // Anonymous intake: catalog decides every price, and no self-serve discount/tax/shipping.
  const quote = req.actor ? normalized : (await applyCatalogPricingToPublicIntake(normalized));
  const status = req.actor ? (quoteText(body.status || "Draft", 32) || "Draft") : "Draft";
  if (!QUOTE_STATUSES.has(status)) throw quoteInputError("status is not supported");

  const captureLead = !body.leadId && (source === "storefront" || body.captureLead === true);
  const leadInput = captureLead ? normalizeLeadInput({
    ...body,
    name: quote.customer,
    company: quote.details.company,
    contact: quote.contact,
    email: quote.details.email,
    phone: quote.details.phone,
    source: body.leadSource || "E-Quotation Form",
    interest: body.interest || `Quote request: ${quote.items.map((item) => item.name).slice(0, 6).join(", ")}`,
    note: body.leadNote ?? quote.note,
  }, "E-Quotation Form") : null;
  if (leadInput?.error) throw quoteInputError(leadInput.error);

  const requestedLeadId = req.actor && body.leadId != null && body.leadId !== "" ? Number(body.leadId) : null;
  if (requestedLeadId != null && (!Number.isSafeInteger(requestedLeadId) || requestedLeadId < 1))
    throw quoteInputError("leadId must be a valid lead id");
  const match = await matchOrCreateClient({
    name: quote.customer, company: quote.details.company, contact: quote.contact,
    email: quote.details.email, phone: quote.details.phone, source, note: quote.note,
  });
  if (requestedLeadId != null) {
    const lead = await db.prepare("SELECT id FROM leads WHERE id=?").get(requestedLeadId);
    if (!lead) throw quoteInputError("selected lead was not found");
    const leadLink = await db.prepare("SELECT client_id FROM client_links WHERE entity='lead' AND entity_id=?").get(requestedLeadId);
    if (leadLink && Number(leadLink.client_id) !== Number(match.client.id))
      throw Object.assign(new Error("selected lead belongs to a different client"), { statusCode: 409 });
    if (!leadLink) await linkClient(match.client.id, "lead", requestedLeadId);
  }
  const linkedLead = leadInput
    ? await createLeadRecord(leadInput, match.client.id, match.created, match.returning, match.needsReview, afterCommit)
    : null;
  const leadId = linkedLead?.id ?? requestedLeadId ?? null;
  const ref = body.ref || (await nextRef("quotes", "Q-", 2295));
  const t = now();
  const audit = [{
    who: req.actor ?? quote.customer,
    action: (source === "storefront" ? "requested quotation via " : "created quotation via ") + source,
    time: t,
  }];
  const info = await db.prepare(`INSERT INTO quotes(ref,customer,contact,lead_id,items,total,status,source,note,audit,details,version,created_at,updated_at)
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)`).run(
    ref, quote.customer, quote.contact, leadId, JSON.stringify(quote.items), quote.total, status,
    source, quote.note, JSON.stringify(audit), JSON.stringify(quote.details), 1, t, t,
  );
  const stored = rowQuote(await db.prepare("SELECT * FROM quotes WHERE id=?").get(info.lastInsertRowid));
  await linkClient(match.client.id, "quote", stored.id);
  await insertQuoteVersion(stored.id, 1, stored, req.actor ?? (source === "storefront" ? quote.customer : "System"), "Quote created", t);
  afterCommit.push(() => emit("quotes", { title: "Quotation " + ref + " created", who: req.actor ?? "Storefront" }));
  return { status: 201, body: { ...stored, clientId: match.client.id } };
}));
app.patch("/api/quotes/:id", wrapTxJson(async (req, afterCommit) => {
  const row = await db.prepare("SELECT * FROM quotes WHERE id=? OR ref=?").get(req.params.id, req.params.id);
  if (!row) return { status: 404, body: { error: "not found" } };
  const body = req.body ?? {};
  const previous = rowQuote(row);
  const has = (key) => Object.prototype.hasOwnProperty.call(body, key);
  const status = has("status") ? quoteText(body.status, 32) : row.status;
  if (!QUOTE_STATUSES.has(status)) return { status: 400, body: { error: "status is not supported" } };

  const hasContentInput = ["customer", "contact", "items", "details", "source", "note", "company", "email", "phone", "siteAddress", "address", "leadId"].some(has);
  const quote = hasContentInput ? normalizeQuotePayload(body, previous) : previous;
  let leadId = previous.leadId ?? null;
  if (has("leadId")) {
    if (body.leadId == null || body.leadId === "") leadId = null;
    else {
      leadId = Number(body.leadId);
      if (!Number.isSafeInteger(leadId) || leadId < 1) return { status: 400, body: { error: "leadId must be a valid lead id" } };
      const lead = await db.prepare("SELECT id FROM leads WHERE id=?").get(leadId);
      if (!lead) return { status: 400, body: { error: "selected lead was not found" } };
      const [clientLink, leadLink] = await Promise.all([
        db.prepare("SELECT client_id FROM client_links WHERE entity='quote' AND entity_id=?").get(row.id),
        db.prepare("SELECT client_id FROM client_links WHERE entity='lead' AND entity_id=?").get(leadId),
      ]);
      if (clientLink && leadLink && Number(clientLink.client_id) !== Number(leadLink.client_id))
        return { status: 409, body: { error: "selected lead belongs to a different client" } };
      if (!leadLink && clientLink) await linkClient(clientLink.client_id, "lead", leadId);
    }
  }
  quote.leadId = leadId;
  const contentChanged = JSON.stringify(quoteContentSnapshot(quote)) !== JSON.stringify(quoteContentSnapshot(previous));
  const statusChanged = status !== row.status;
  if (contentChanged && ["Sent", "Accepted", "Approved"].includes(row.status) && status !== "Revised")
    return { status: 409, body: { error: "This quote has been issued. Set status to Revised before changing its content." } };
  const version = (Number(row.version) || 1) + (contentChanged ? 1 : 0);
  const actor = req.actor ?? "You";
  const t = now();
  const audit = safeJson(row.audit, []);
  if (statusChanged) audit.unshift({ who: actor, action: "moved to " + status, time: t });
  if (contentChanged) audit.unshift({ who: actor, action: "saved revision " + version, time: t });
  if (has("note") && quote.note !== previous.note) audit.unshift({ who: actor, action: quote.note ? "updated quote note" : "cleared quote note", time: t });
  await db.prepare(`UPDATE quotes SET customer=?,contact=?,lead_id=?,items=?,total=?,status=?,source=?,note=?,details=?,audit=?,version=?,updated_at=? WHERE id=?`).run(
    quote.customer, quote.contact, leadId, JSON.stringify(quote.items), quote.total, status, quote.source,
    quote.note, JSON.stringify(quote.details), JSON.stringify(audit), version, t, row.id,
  );
  const out = rowQuote(await db.prepare("SELECT * FROM quotes WHERE id=?").get(row.id));
  if (contentChanged) await insertQuoteVersion(row.id, version, out, actor, "Quote revised", t);
  if (status === "Sent" && row.status !== "Sent") await db.prepare("INSERT INTO tasks(quote_id,title,due,priority,created_at,updated_at) VALUES(?,?,?,?,?,?)")
    .run(row.id, "Follow up on quote " + row.ref + " (" + row.customer + ")", new Date(Date.now() + 864e5 * 3).toISOString().slice(0, 10), "normal", t, t);
  if (statusChanged || contentChanged) emit("quotes", { title: "Quotation " + row.ref + (statusChanged ? " → " + status : " revised"), who: actor });
  return out;
}));

/* Leads — contact form → CRM */
app.get("/api/leads", wrap(async (req, res) => {
  let rows = (await (db.prepare("SELECT * FROM leads ORDER BY datetime(created_at) DESC").all()));
  const fs = String(req.query.status ?? "").trim();
  if (fs) rows = rows.filter((r) => String(r.status).toLowerCase() === fs.toLowerCase());
  const fq = String(req.query.q ?? "").trim().toLowerCase();
  if (fq) rows = rows.filter((r) => [r.name, r.company, r.interest, r.contact, r.email, r.phone, r.ref].some((v) => String(v ?? "").toLowerCase().includes(fq)));
  const items = (await Promise.all(rows.map(async (r) => { const l = rowLead(r); const sc = (await (scoreLead(l)));
    const link = (await (db.prepare("SELECT client_id c FROM client_links WHERE entity='lead' AND entity_id=?").get(l.id)));
    return { ...l, score: sc.score, scoreWhy: sc.why, clientId: link?.c ?? null }; })));
  res.json({ total: rows.length, items });
}));
/* ---- P10 omnichannel: per-client conversations, threads, WhatsApp handoff ---- */
const CONV_CHANNELS = new Set(["whatsapp", "email", "phone", "note", "system"]);
const CONV_STATUS = new Set(["open", "pending", "resolved", "snoozed"]);
const INBOX_TEMPLATES = [
  { id: "welcome", label: "New lead welcome", body: "Assalam-o-Alaikum {{name}}, thanks for reaching out to Woodex! Share a few details about your space and I'll send options with prices. - Woodex" },
  { id: "quote-followup", label: "Quote follow-up", body: "Hi {{name}}, following up on {{ref}} - should we hold the stock for you this week? - Woodex" },
  { id: "payment-reminder", label: "Advance reminder", body: "Hi {{name}}, gentle reminder: 50% advance on {{ref}} gets production started this week. - Woodex" },
  { id: "delivery-update", label: "Delivery update", body: "Hi {{name}}, good news - your order is packed and our team will call before arrival. - Woodex" },
];
const waPhone = (phone) => { const d = String(phone ?? "").replace(/\D/g, ""); if (d.length < 10) return null; return d.startsWith("92") ? d : "92" + d.slice(-10); };
const ensureConv = async (clientId) => {
  let c = (await (db.prepare("SELECT * FROM conversations WHERE client_id=?").get(clientId)));
  if (!c) { const t = now(); (await (db.prepare("INSERT INTO conversations(client_id,created_at,updated_at,last_at) VALUES(?,?,?,?)").run(clientId, t, t, t))); c = (await (db.prepare("SELECT * FROM conversations WHERE client_id=?").get(clientId))); }
  return c;
};
const appendMsg = async (convId, { channel = "note", direction = "outbound", author = "You", body, meta = null }) => {
  const t = now();
  const info = (await (db.prepare("INSERT INTO messages(conversation_id,channel,direction,author,body,meta,created_at) VALUES(?,?,?,?,?,?,?)")
    .run(convId, channel, direction, String(author).slice(0, 40), String(body).slice(0, 4000), meta ? JSON.stringify(meta) : null, t)));
  (await (db.prepare("UPDATE conversations SET last_at=?, updated_at=?, unread=unread+?, status=CASE WHEN ?='inbound' THEN 'open' ELSE status END WHERE id=?")
    .run(t, t, direction === "inbound" ? 1 : 0, direction, convId)));
  return (await (db.prepare("SELECT * FROM messages WHERE id=?").get(info.lastInsertRowid)));
};
const rowMsg = (m) => ({ id: m.id, channel: m.channel, direction: m.direction, author: m.author, body: m.body, createdAt: m.created_at, meta: m.meta ? JSON.parse(m.meta) : null });
const resolveTemplate = async (tpl, clientId) => {
  const cl = (await (db.prepare("SELECT * FROM clients WHERE id=?").get(clientId))) ?? {};
  const ref = (await (db.prepare("SELECT l.ref FROM client_links x JOIN leads l ON l.id=x.entity_id WHERE x.client_id=? AND x.entity='lead' ORDER BY l.id DESC LIMIT 1").get(clientId)))?.ref
    ?? (await (db.prepare("SELECT q.ref FROM client_links x JOIN quotes q ON q.id=x.entity_id WHERE x.client_id=? AND x.entity='quote' ORDER BY q.id DESC LIMIT 1").get(clientId)))?.ref ?? "your inquiry";
  return tpl.body.replaceAll("{{name}}", cl.name ?? "there").replaceAll("{{ref}}", ref);
};
const touchInbox = (extra = {}) => emit("inbox", extra);
app.post("/api/leads/:id/whatsapp-handoff", wrapTxResponse(async (req, afterCommit) => {
  const lead = await db.prepare(`SELECT l.*, x.client_id FROM leads l
    LEFT JOIN client_links x ON x.entity='lead' AND x.entity_id=l.id WHERE l.id=?`).get(req.params.id);
  if (!lead) return { status: 404, body: { error: "lead not found" } };
  if (Number(lead.contact_consent) !== 1)
    return { status: 403, body: { error: "contact permission is required before logging a handoff" } };
  if (lead.preferred_channel !== "whatsapp")
    return { status: 409, body: { error: "WhatsApp was not selected as the preferred contact method" } };
  if (!lead.client_id) return { status: 409, body: { error: "lead has no linked client record" } };
  const conversation = await ensureConv(lead.client_id);
  await appendMsg(conversation.id, {
    channel: "system", direction: "system", author: "Storefront",
    body: `Visitor opened a manual WhatsApp handoff for ${lead.ref}. Woodex did not send or receive a message.`,
    meta: { handoff: "manual", delivery: "not_sent", source: "storefront", leadId: lead.id },
  });
  afterCommit.push(() => {
    touchInbox({ clientId: lead.client_id });
    emit("leads", { title: "Manual WhatsApp handoff opened for " + lead.ref, who: "Storefront" });
  });
  return { status: 201, body: { logged: true, leadId: lead.id, clientId: lead.client_id, ref: lead.ref, delivery: "manual_handoff_not_sent" } };
}));
app.post("/api/leads", wrapTxResponse(async (req, afterCommit) => {
  const leadInput = normalizeLeadInput(req.body ?? {}, "Website");
  if (leadInput.error) return { status: 400, body: { error: leadInput.error } };
  const m = (await (matchOrCreateClient(leadInput)));
  const lead = await createLeadRecord(leadInput, m.client.id, m.created, m.returning, m.needsReview, afterCommit);
  return { status: 201, body: { ...lead, clientId: m.client.id } };
}));
app.patch("/api/leads/:id", wrap(async (req, res) => {
  const r = (await (db.prepare("SELECT * FROM leads WHERE id=? OR ref=?").get(req.params.id, req.params.id)));
  if (!r) return res.status(404).json({ error: "not found" });
  const { status, owner } = req.body ?? {};
  (await (db.prepare("UPDATE leads SET status=?, owner=?, updated_at=? WHERE id=?").run(status ?? r.status, owner ?? r.owner, now(), r.id)));
  const leadOut = rowLead((await (db.prepare("SELECT * FROM leads WHERE id=?").get(r.id))));
  emit("leads", { title: "Lead " + r.ref + " → " + (req.body?.status ?? r.status), who: req.actor ?? "CRM" });
  res.json(leadOut);
}));

/* Orders — checkout → operations */
/* ---- Projects / BOQ / milestones / files ---- */
const PROJECT_STAGES = ["Planning", "Procurement", "Production", "Delivery", "Installation", "Handover"];
const PROJECT_FILE_KINDS = ["drawing", "boq", "contract", "photo", "other"];
const rowProject = (r) => ({
  id: r.id, ref: r.ref, name: r.name, clientId: r.client_id, orderId: r.order_id,
  owner: r.owner, stage: r.stage, address: r.address,
  startAt: r.start_at, dueAt: r.due_at,
  plannedCost: r.planned_cost, actualCost: r.actual_cost, progress: r.progress,
  notes: r.notes, createdAt: r.created_at, updatedAt: r.updated_at,
});
const rowMilestone = (r) => ({ id: r.id, projectId: r.project_id, title: r.title, dueAt: r.due_at, done: !!r.done, sort: r.sort, note: r.note });
const rowBoq = (r) => ({ id: r.id, projectId: r.project_id, sku: r.sku, description: r.description, qty: r.qty, unit: r.unit, unitCost: r.unit_cost, lineTotal: Math.round(r.qty * r.unit_cost) });
const rowProjectFile = (r) => ({ id: r.id, projectId: r.project_id, name: r.name, kind: r.kind, size: r.size, url: r.url, uploadedBy: r.uploaded_by, createdAt: r.created_at });
const projectNextRef = async () => {
  const row = (await db.prepare("SELECT COUNT(*) n FROM projects").get());
  return "PRJ-" + String(1001 + Number(row?.n ?? 0)).padStart(4, "0");
};

app.get("/api/projects", wrap(async (req, res) => {
  let rows = (await (db.prepare("SELECT * FROM projects ORDER BY datetime(created_at) DESC").all())).map(rowProject);
  if (req.query.stage) rows = rows.filter((p) => p.stage === req.query.stage);
  if (req.query.clientId) rows = rows.filter((p) => p.clientId === Number(req.query.clientId));
  const items = [];
  for (const p of rows) {
    const boq = (await (db.prepare("SELECT * FROM boq_lines WHERE project_id=? ORDER BY sort, id").all(p.id))).map(rowBoq);
    const boqTotal = boq.reduce((n, l) => n + l.lineTotal, 0);
    const milestones = (await (db.prepare("SELECT * FROM project_milestones WHERE project_id=? ORDER BY sort, id").all(p.id))).map(rowMilestone);
    items.push({ ...p, boqTotal, boqCount: boq.length, milestones: milestones.length, milestonesDone: milestones.filter((m) => m.done).length });
  }
  const planned = items.reduce((n, p) => n + (p.plannedCost || 0), 0);
  res.json({ total: items.length, planned, open: items.filter((p) => p.stage !== "Handover").length, items });
}));

app.get("/api/projects/:id", wrap(async (req, res) => {
  const r = (await (db.prepare("SELECT * FROM projects WHERE id=?").get(Number(req.params.id))));
  if (!r) return res.status(404).json({ error: "project not found" });
  const p = rowProject(r);
  const boq = (await (db.prepare("SELECT * FROM boq_lines WHERE project_id=? ORDER BY sort, id").all(p.id))).map(rowBoq);
  const milestonesList = (await (db.prepare("SELECT * FROM project_milestones WHERE project_id=? ORDER BY sort, id").all(p.id))).map(rowMilestone);
  const files = (await (db.prepare("SELECT * FROM project_files WHERE project_id=? ORDER BY id DESC").all(p.id))).map(rowProjectFile);
  const client = p.clientId ? (await (db.prepare("SELECT id, name, company, email, phone FROM clients WHERE id=?").get(p.clientId))) : null;
  const boqTotal = boq.reduce((n, l) => n + l.lineTotal, 0);
  /* Detail returns `milestonesList` (rows) so it never collides with the `milestones`
     count the list endpoint uses — reading the wrong one renders "3" not three rows. */
  res.json({ ...p, boq, boqTotal, milestonesList, files,
    margin: boqTotal > 0 ? Math.round(((boqTotal - (p.actualCost || 0)) / boqTotal) * 1000) / 10 : null,
    client: client ?? null });
}));

app.post("/api/projects", wrapTxJson(async (req, afterCommit) => {
  const name = productText(req.body?.name, 160);
  const clientId = Number(req.body?.clientId);
  if (!name) return { status: 400, body: { error: "project name is required" } };
  if (!Number.isInteger(clientId) || clientId <= 0) return { status: 400, body: { error: "clientId is required" } };
  const client = (await db.prepare("SELECT id FROM clients WHERE id=?").get(clientId));
  if (!client) return { status: 400, body: { error: "client not found" } };
  const ref = await projectNextRef();
  const stage = PROJECT_STAGES.includes(req.body?.stage) ? req.body.stage : "Planning";
  const at = now();
  const info = (await db.prepare(`INSERT INTO projects(ref,name,client_id,order_id,owner,stage,address,start_at,due_at,planned_cost,actual_cost,progress,notes,created_at,updated_at)
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`)
    .run(ref, name, clientId, req.body?.orderId ?? null, productText(req.body?.owner, 80) || req.actor, stage,
      productText(req.body?.address, 300) || null, req.body?.startAt ?? null, req.body?.dueAt ?? null,
      Number(req.body?.plannedCost) || 0, 0, 0, productText(req.body?.notes, 2000) || null, at, at));
  afterCommit.push(() => emit("projects", { title: "Project opened: " + name, who: req.actor }));
  return { status: 201, body: rowProject((await db.prepare("SELECT * FROM projects WHERE id=?").get(info.lastInsertRowid))) };
}));

app.patch("/api/projects/:id", wrapTxJson(async (req, afterCommit) => {
  const id = Number(req.params.id);
  const r = (await db.prepare("SELECT * FROM projects WHERE id=?").get(id));
  if (!r) return { status: 404, body: { error: "project not found" } };
  const b = req.body ?? {};
  const sets = [], vals = [];
  const push = (col, val) => { sets.push(col + "=?"); vals.push(val); };
  if (b.name !== undefined) { const v = productText(b.name, 160); if (!v) return { status: 400, body: { error: "name cannot be empty" } }; push("name", v); }
  if (b.stage !== undefined) {
    if (!PROJECT_STAGES.includes(b.stage)) return { status: 400, body: { error: "stage must be one of " + PROJECT_STAGES.join(", ") } };
    push("stage", b.stage);
  }
  if (b.owner !== undefined) push("owner", productText(b.owner, 80) || "Unassigned");
  if (b.address !== undefined) push("address", productText(b.address, 300) || null);
  if (b.startAt !== undefined) push("start_at", b.startAt || null);
  if (b.dueAt !== undefined) push("due_at", b.dueAt || null);
  if (b.plannedCost !== undefined) { const n = Number(b.plannedCost); if (!Number.isFinite(n) || n < 0) return { status: 400, body: { error: "plannedCost must be a positive number" } }; push("planned_cost", n); }
  if (b.actualCost !== undefined) { const n = Number(b.actualCost); if (!Number.isFinite(n) || n < 0) return { status: 400, body: { error: "actualCost must be a positive number" } }; push("actual_cost", n); }
  if (b.progress !== undefined) { const n = Number(b.progress); if (!Number.isInteger(n) || n < 0 || n > 100) return { status: 400, body: { error: "progress must be an integer 0-100" } }; push("progress", n); }
  if (b.notes !== undefined) push("notes", productText(b.notes, 2000) || null);
  if (!sets.length) return { status: 400, body: { error: "no supported fields supplied" } };
  push("updated_at", now());
  vals.push(id);
  await db.prepare(`UPDATE projects SET ${sets.join(", ")} WHERE id=?`).run(...vals);
  afterCommit.push(() => emit("projects", { title: "Project updated: " + (b.name ?? r.name), who: req.actor }));
  return { status: 200, body: rowProject((await db.prepare("SELECT * FROM projects WHERE id=?").get(id))) };
}));

app.delete("/api/projects/:id", wrapTxJson(async (req, afterCommit) => {
  const id = Number(req.params.id);
  const r = (await db.prepare("SELECT id, name FROM projects WHERE id=?").get(id));
  if (!r) return { status: 404, body: { error: "project not found" } };
  for (const t of ["project_milestones", "boq_lines", "project_files"]) await db.prepare(`DELETE FROM ${t} WHERE project_id=?`).run(id);
  await db.prepare("DELETE FROM projects WHERE id=?").run(id);
  afterCommit.push(() => emit("projects", { title: "Project deleted: " + r.name, who: req.actor }));
  return { status: 200, body: { ok: true } };
}));

/* BOQ lines — the priced scope of work. Line totals are always server-computed. */
app.post("/api/projects/:id/boq", wrapTxJson(async (req, afterCommit) => {
  const projectId = Number(req.params.id);
  const project = (await db.prepare("SELECT id FROM projects WHERE id=?").get(projectId));
  if (!project) return { status: 404, body: { error: "project not found" } };
  const description = productText(req.body?.description, 300);
  const qty = Number(req.body?.qty ?? 1);
  const unitCost = Number(req.body?.unitCost ?? 0);
  if (!description) return { status: 400, body: { error: "description is required" } };
  if (!Number.isFinite(qty) || qty <= 0) return { status: 400, body: { error: "qty must be greater than zero" } };
  if (!Number.isFinite(unitCost) || unitCost < 0) return { status: 400, body: { error: "unitCost must be zero or more" } };
  const maxSort = (await db.prepare("SELECT COALESCE(MAX(sort),0) m FROM boq_lines WHERE project_id=?").get(projectId));
  const info = (await db.prepare("INSERT INTO boq_lines(project_id,sku,description,qty,unit,unit_cost,sort,created_at) VALUES(?,?,?,?,?,?,?,?)")
    .run(projectId, productText(req.body?.sku, 60) || null, description, qty, productText(req.body?.unit, 16) || "pcs", unitCost, Number(maxSort?.m ?? 0) + 1, now()));
  afterCommit.push(() => emit("projects", { title: "BOQ line added", who: req.actor }));
  return { status: 201, body: rowBoq((await db.prepare("SELECT * FROM boq_lines WHERE id=?").get(info.lastInsertRowid))) };
}));

app.patch("/api/projects/:id/boq/:lineId", wrapTxJson(async (req, afterCommit) => {
  const projectId = Number(req.params.id), lineId = Number(req.params.lineId);
  const r = (await db.prepare("SELECT * FROM boq_lines WHERE id=? AND project_id=?").get(lineId, projectId));
  if (!r) return { status: 404, body: { error: "BOQ line not found" } };
  const b = req.body ?? {};
  const sets = [], vals = [];
  if (b.description !== undefined) { const v = productText(b.description, 300); if (!v) return { status: 400, body: { error: "description cannot be empty" } }; sets.push("description=?"); vals.push(v); }
  if (b.qty !== undefined) { const n = Number(b.qty); if (!Number.isFinite(n) || n <= 0) return { status: 400, body: { error: "qty must be greater than zero" } }; sets.push("qty=?"); vals.push(n); }
  if (b.unitCost !== undefined) { const n = Number(b.unitCost); if (!Number.isFinite(n) || n < 0) return { status: 400, body: { error: "unitCost must be zero or more" } }; sets.push("unit_cost=?"); vals.push(n); }
  if (b.unit !== undefined) { sets.push("unit=?"); vals.push(productText(b.unit, 16) || "pcs"); }
  if (b.sku !== undefined) { sets.push("sku=?"); vals.push(productText(b.sku, 60) || null); }
  if (!sets.length) return { status: 400, body: { error: "no supported fields supplied" } };
  vals.push(lineId);
  await db.prepare(`UPDATE boq_lines SET ${sets.join(", ")} WHERE id=?`).run(...vals);
  afterCommit.push(() => emit("projects", { title: "BOQ updated", who: req.actor }));
  return { status: 200, body: rowBoq((await db.prepare("SELECT * FROM boq_lines WHERE id=?").get(lineId))) };
}));

app.delete("/api/projects/:id/boq/:lineId", wrapTxJson(async (req, afterCommit) => {
  const lineId = Number(req.params.lineId), projectId = Number(req.params.id);
  const r = (await db.prepare("SELECT id FROM boq_lines WHERE id=? AND project_id=?").get(lineId, projectId));
  if (!r) return { status: 404, body: { error: "BOQ line not found" } };
  await db.prepare("DELETE FROM boq_lines WHERE id=?").run(lineId);
  afterCommit.push(() => emit("projects", { title: "BOQ line removed", who: req.actor }));
  return { status: 200, body: { ok: true } };
}));

/* Milestones */
app.post("/api/projects/:id/milestones", wrapTxJson(async (req, afterCommit) => {
  const projectId = Number(req.params.id);
  const project = (await db.prepare("SELECT id FROM projects WHERE id=?").get(projectId));
  if (!project) return { status: 404, body: { error: "project not found" } };
  const title = productText(req.body?.title, 160);
  if (!title) return { status: 400, body: { error: "milestone title is required" } };
  const maxSort = (await db.prepare("SELECT COALESCE(MAX(sort),0) m FROM project_milestones WHERE project_id=?").get(projectId));
  const info = (await db.prepare("INSERT INTO project_milestones(project_id,title,due_at,done,sort,note,created_at) VALUES(?,?,?,?,?,?,?)")
    .run(projectId, title, req.body?.dueAt ?? null, 0, Number(maxSort?.m ?? 0) + 1, productText(req.body?.note, 500) || null, now()));
  afterCommit.push(() => emit("projects", { title: "Milestone added: " + title, who: req.actor }));
  return { status: 201, body: rowMilestone((await db.prepare("SELECT * FROM project_milestones WHERE id=?").get(info.lastInsertRowid))) };
}));

app.patch("/api/projects/:id/milestones/:mid", wrapTxJson(async (req, afterCommit) => {
  const projectId = Number(req.params.id), mid = Number(req.params.mid);
  const r = (await db.prepare("SELECT * FROM project_milestones WHERE id=? AND project_id=?").get(mid, projectId));
  if (!r) return { status: 404, body: { error: "milestone not found" } };
  const b = req.body ?? {};
  const sets = [], vals = [];
  if (b.title !== undefined) { const v = productText(b.title, 160); if (!v) return { status: 400, body: { error: "title cannot be empty" } }; sets.push("title=?"); vals.push(v); }
  if (b.dueAt !== undefined) { sets.push("due_at=?"); vals.push(b.dueAt || null); }
  if (b.done !== undefined) { sets.push("done=?"); vals.push(b.done ? 1 : 0); }
  if (b.note !== undefined) { sets.push("note=?"); vals.push(productText(b.note, 500) || null); }
  if (!sets.length) return { status: 400, body: { error: "no supported fields supplied" } };
  vals.push(mid);
  await db.prepare(`UPDATE project_milestones SET ${sets.join(", ")} WHERE id=?`).run(...vals);
  // Derived progress: a project whose milestones are all done is 100%.
  const all = (await db.prepare("SELECT done FROM project_milestones WHERE project_id=?").all(projectId));
  if (all.length) {
    const pct = Math.round((all.filter((m) => m.done).length / all.length) * 100);
    await db.prepare("UPDATE projects SET progress=?, updated_at=? WHERE id=?").run(pct, now(), projectId);
  }
  afterCommit.push(() => emit("projects", { title: "Milestone updated", who: req.actor }));
  return { status: 200, body: rowMilestone((await db.prepare("SELECT * FROM project_milestones WHERE id=?").get(mid))) };
}));

app.delete("/api/projects/:id/milestones/:mid", wrapTxJson(async (req, afterCommit) => {
  const mid = Number(req.params.mid), projectId = Number(req.params.id);
  const r = (await db.prepare("SELECT id FROM project_milestones WHERE id=? AND project_id=?").get(mid, projectId));
  if (!r) return { status: 404, body: { error: "milestone not found" } };
  await db.prepare("DELETE FROM project_milestones WHERE id=?").run(mid);
  afterCommit.push(() => emit("projects", { title: "Milestone removed", who: req.actor }));
  return { status: 200, body: { ok: true } };
}));

/* Project files — metadata only; binaries live in /img or an object store. */
app.post("/api/projects/:id/files", wrapTxJson(async (req, afterCommit) => {
  const projectId = Number(req.params.id);
  const project = (await db.prepare("SELECT id FROM projects WHERE id=?").get(projectId));
  if (!project) return { status: 404, body: { error: "project not found" } };
  const name = productText(req.body?.name, 200);
  if (!name) return { status: 400, body: { error: "file name is required" } };
  const kind = PROJECT_FILE_KINDS.includes(req.body?.kind) ? req.body.kind : "other";
  const url = productText(req.body?.url, 500);
  if (url && !/^(\/img\/|https:\/\/)/.test(url)) return { status: 400, body: { error: "url must be an /img/ path or an https URL" } };
  const info = (await db.prepare("INSERT INTO project_files(project_id,name,kind,size,url,uploaded_by,created_at) VALUES(?,?,?,?,?,?,?)")
    .run(projectId, name, kind, Math.max(0, Number(req.body?.size) || 0), url || null, req.actor, now()));
  afterCommit.push(() => emit("projects", { title: "File attached: " + name, who: req.actor }));
  return { status: 201, body: rowProjectFile((await db.prepare("SELECT * FROM project_files WHERE id=?").get(info.lastInsertRowid))) };
}));

app.delete("/api/projects/:id/files/:fid", wrapTxJson(async (req, afterCommit) => {
  const fid = Number(req.params.fid), projectId = Number(req.params.id);
  const r = (await db.prepare("SELECT id, name FROM project_files WHERE id=? AND project_id=?").get(fid, projectId));
  if (!r) return { status: 404, body: { error: "file not found" } };
  await db.prepare("DELETE FROM project_files WHERE id=?").run(fid);
  afterCommit.push(() => emit("projects", { title: "File removed: " + r.name, who: req.actor }));
  return { status: 200, body: { ok: true } };
}));

app.get("/api/orders", wrap(async (req, res) => {
  const items = (await (db.prepare("SELECT * FROM orders ORDER BY datetime(created_at) DESC").all())).map(rowOrder);
  res.json({ total: items.length, items });
}));
app.post("/api/orders", wrapTxJson(async (req, afterCommit) => {
  const customer = productText(req.body?.customer, 120);
  const requestedItems = req.body?.items;
  if (!customer || !Array.isArray(requestedItems) || requestedItems.length < 1 || requestedItems.length > 50)
    return { status: 400, body: { error: "customer and 1–50 catalog items are required" } };

  const items = [];
  for (const [index, raw] of requestedItems.entries()) {
    const productId = String(raw?.id ?? raw?.productId ?? "").trim();
    const qty = Number(raw?.qty);
    if (!PRODUCT_ID_RE.test(productId) || !Number.isInteger(qty) || qty < 1 || qty > 100)
      return { status: 400, body: { error: `item ${index + 1} needs a valid catalog product and whole quantity from 1 to 100` } };
    const product = await db.prepare("SELECT id,json,price,in_stock,stock_qty FROM products WHERE id=?").get(productId);
    if (!product) return { status: 400, body: { error: `item ${index + 1} is not an available catalog product` } };
    if (!Number(product.in_stock) || Number(product.stock_qty) < qty)
      return { status: 409, body: { error: `${safeJson(product.json, {}).name ?? "A selected product"} is unavailable in the requested quantity` } };

    const data = safeJson(product.json, {});
    const requestedColor = String(raw?.color ?? "").trim().slice(0, 60);
    const color = (Array.isArray(data.colors) ? data.colors : []).find((entry) => entry?.name === requestedColor)?.name;
    items.push({ id: product.id, name: String(data.name ?? product.id).slice(0, 140), price: Number(product.price), qty, ...(color ? { color } : {}) });
  }

  // Client totals/prices are display hints only. Recompute from the current catalog.
  const subtotal = roundMoney(items.reduce((sum, item) => sum + item.price * item.qty, 0));
  const shipping = subtotal >= 50_000 ? 0 : 2_500; // same published threshold/fee as Checkout
  const total = roundMoney(subtotal + shipping);
  const t = now();
  const ref = await nextRef("orders", "WX-", 4300);
  const trackingToken = crypto.randomBytes(32).toString("base64url");
  const trackingTokenHash = crypto.createHash("sha256").update(trackingToken).digest("hex");
  const info = await db.prepare("INSERT INTO orders(ref,customer,items,total,status,stage,owner,due,tracking_token_hash,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
    .run(ref, customer, JSON.stringify(items), total, "Pending", "production", "Faisal", "TBD", trackingTokenHash, t, t);
  const oLed = rowOrder(await db.prepare("SELECT * FROM orders WHERE id=?").get(info.lastInsertRowid));
  const m = await matchOrCreateClient({ name: customer, contact: req.body?.contact, source: "checkout" });
  await linkClient(m.client.id, "order", oLed.id);
  const invoiceRef = await nextRef("invoices", "INV-26-", 1100);
  const invoice = await db.prepare(`INSERT INTO invoices(ref,order_id,customer,items,subtotal,discount,tax,shipping,total,status,due,created_at,updated_at)
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)`).run(invoiceRef, oLed.id, customer, JSON.stringify(items), subtotal, 0, 0, shipping, total,
    "Issued", new Date(Date.now() + 864e5 * 7).toISOString().slice(0, 10), t, t);
  const invRow = await db.prepare("SELECT id FROM invoices WHERE id=?").get(invoice.lastInsertRowid);
  if (invRow) await linkClient(m.client.id, "invoice", invRow.id);
  await db.prepare("INSERT OR IGNORE INTO tasks(client_id,order_id,title,due,priority,created_at,updated_at) VALUES(?,?,?,?,?,?,?)")
    .run(m.client.id, oLed.id, "Collect 50% advance before production — " + ref,
      new Date(Date.now() + 864e5 * 2).toISOString().slice(0, 10), "high", t, t);
  emit("orders", { title: "Order " + ref + " request received", who: "Storefront" });
  return { status: 201, body: {
    id: oLed.id, ref, items, total, status: oLed.status, stage: oLed.stage, createdAt: oLed.createdAt,
    trackingToken,
  } };
}));
/* Public tracking requires both the order reference and a high-entropy secret delivered at checkout.
   Return operational progress only; never customer, address, item pricing, invoice, payment or return data. */
app.post("/api/orders/lookup", wrap(async (req, res) => {
  const ref = String(req.body?.ref ?? "").trim().slice(0, 100);
  const token = String(req.body?.token ?? "").trim();
  const order = ref && token.length <= 200
    ? await db.prepare("SELECT ref,status,stage,created_at,tracking_token_hash FROM orders WHERE ref=? OR ref=?").get(ref, ref.toUpperCase())
    : null;
  const expected = order?.tracking_token_hash ? Buffer.from(order.tracking_token_hash, "hex") : Buffer.alloc(0);
  const actual = token ? Buffer.from(crypto.createHash("sha256").update(token).digest("hex"), "hex") : Buffer.alloc(0);
  if (!order || expected.length !== actual.length || expected.length !== 32 || !crypto.timingSafeEqual(expected, actual))
    return res.status(404).json({ error: "tracking details not found; check the reference and private access code" });
  const stages = ["production", "qc", "dispatch", "delivery", "installation"];
  res.json({ ref: order.ref, status: order.status, stage: order.stage, stages,
    stageIndex: stages.indexOf(order.stage), placedAt: order.created_at });
}));
app.get("/api/orders/:id", wrap(async (req, res) => {
  const r = await db.prepare("SELECT * FROM orders WHERE id=? OR ref=?").get(req.params.id, req.params.id);
  if (!r) return res.status(404).json({ error: "not found" });
  let client = await db.prepare(`SELECT c.* FROM client_links x JOIN clients c ON c.id=x.client_id
    WHERE x.entity='order' AND x.entity_id=? AND c.merged_into IS NULL ORDER BY c.id LIMIT 1`).get(r.id);
  if (!client) client = await db.prepare("SELECT * FROM clients WHERE lower(name)=lower(?) AND merged_into IS NULL ORDER BY id LIMIT 1").get(r.customer);
  const invoices = await Promise.all((await db.prepare("SELECT * FROM invoices WHERE order_id=? ORDER BY id DESC").all(r.id)).map(async (invoice) => ({
    ...decorateInvoice(invoice),
    payments: await db.prepare("SELECT * FROM payments WHERE invoice_id=? ORDER BY datetime(created_at) DESC").all(invoice.id),
  })));
  const returns = (await db.prepare("SELECT * FROM returns WHERE order_id=? ORDER BY datetime(created_at) DESC").all(r.id)).map(rowReturn);
  const tasks = (await db.prepare("SELECT * FROM tasks WHERE order_id=? ORDER BY done ASC, datetime(COALESCE(due,'9999')) ASC").all(r.id)).map(rowTask);
  res.json({
    ...rowOrder(r),
    client: client ? rowClientLite(client) : null,
    invoices,
    returns,
    tasks,
  });
}));
app.patch("/api/orders/:id", wrap(async (req, res) => {
  const r = (await (db.prepare("SELECT * FROM orders WHERE id=? OR ref=?").get(req.params.id, req.params.id)));
  if (!r) return res.status(404).json({ error: "not found" });
  const { status, stage, owner, due } = req.body ?? {};
  if (status !== undefined && !["Pending", "On Track", "In Progress", "On Hold", "Delayed", "Completed"].includes(status))
    return res.status(400).json({ error: "status must be Pending, On Track, In Progress, On Hold, Delayed or Completed" });
  if (stage !== undefined && !["production", "qc", "dispatch", "delivery", "installation"].includes(stage))
    return res.status(400).json({ error: "stage must be production, qc, dispatch, delivery or installation" });
  if (owner !== undefined && (typeof owner !== "string" || owner.trim().length > 80))
    return res.status(400).json({ error: "owner must be a string of at most 80 characters" });
  if (due !== undefined && (typeof due !== "string" || due.length > 80))
    return res.status(400).json({ error: "due must be a string of at most 80 characters" });
  (await (db.prepare("UPDATE orders SET status=?, stage=?, owner=?, due=?, updated_at=? WHERE id=?").run(
    status ?? r.status, stage ?? r.stage, owner ?? r.owner, due ?? r.due, now(), r.id
  )));
  const oOut = rowOrder((await (db.prepare("SELECT * FROM orders WHERE id=?").get(r.id))));
  if (req.body?.stage || req.body?.status) emit("orders", { title: "Order " + r.ref + " → " + (req.body?.stage ? req.body.stage + " · " : "") + (req.body?.status ?? r.status), who: req.actor ?? "Ops" });
  res.json(oOut);
}));

/* Dashboard rollup */
/* ---- P4 CRM: single client record, links, tasks, scoring ---- */
const digits = (v) => String(v ?? "").replace(/\D+/g, "");
const normPhone = (v) => (digits(v).slice(-10) || null);
const normEmail = (v) => { const m = String(v ?? "").match(/[\w.+-]+@[\w-]+\.[\w.]+/); return m ? m[0].toLowerCase() : null; };
const rowClientLite = (c) => ({ id: c.id, name: c.name, company: c.company, email: c.email, phone: c.phone, city: c.city,
  tags: JSON.parse(c.tags || "[]"), notes: c.notes, source: c.source, createdAt: c.created_at, updatedAt: c.updated_at });
const linkIds = async (clientId, entity) => (await (db.prepare("SELECT entity_id id FROM client_links WHERE client_id=? AND entity=?").all(clientId, entity))).map((r) => r.id);
const inList = (ids) => (ids.length ? `(${ids.join(",")})` : "(0)");

async function scoreLead(l) {
  let score = 20; const why = ["base engagement score"];
  const srcBonus = { Referral: 25, "Walk-in": 20, Website: 12, "Website Form": 12, Instagram: 12, Ecommerce: 12, "Contact Form": 10, Campaign: 10, "Cold Outreach": 4 }[l.source] ?? 6;
  score += srcBonus; why.push(l.source + " source +" + srcBonus);
  const q = (await (db.prepare("SELECT COUNT(*) n FROM quotes WHERE contact=? OR customer=?").get(l.contact ?? "", l.name)))?.n ?? 0;
  if (q) { score += 20; why.push("has " + q + " quote" + (q > 1 ? "s" : "") + " +20"); }
  const o = (await (db.prepare("SELECT COUNT(*) n FROM orders WHERE customer=?").get(l.name)))?.n ?? 0;
  if (o) { score += 30; why.push("repeat buyer / live order +30"); }
  if (l.contact && /@/.test(l.contact) && digits(l.contact).length > 6) { score += 10; why.push("full contact +10"); }
  const days = (Date.now() - Date.parse(l.updatedAt ?? l.createdAt)) / 864e5;
  if (days < 7) { score += 15; why.push("active this week +15"); } else if (days < 30) { score += 5; why.push("active this month +5"); }
  if (/(budget|rs\s?\d|lakhs?|crore)/i.test((l.note ?? "") + " " + (l.interest ?? ""))) { score += 10; why.push("budget signal +10"); }
  if (l.status === "Lost") { score -= 30; why.push("marked lost −30"); }
  return { score: Math.max(0, Math.min(100, score)), why };
}

/** Resolve by normalized phone/email only; ambiguous signatures stay separate and go to review. */
async function matchOrCreateClient({ name, company, contact, email: emailInput, phone: phoneInput, source, note }) {
  const contactText = String(contact ?? "");
  const email = normEmail(emailInput) ?? normEmail(contactText);
  const phoneCandidate = String(phoneInput ?? "").trim()
    || contactText.match(/\+?\d[\d\s().-]{5,}\d/)?.[0]
    || "";
  const phoneNorm = normPhone(phoneCandidate);
  const phone = phoneNorm ? phoneCandidate.trim().slice(0, 80) : null;
  const where = [];
  if (email) where.push("email_norm=" + db2s(email));
  if (phoneNorm) where.push("phone_norm=" + db2s(phoneNorm));
  const hits = where.length
    ? await db.prepare("SELECT * FROM clients WHERE merged_into IS NULL AND (" + where.join(" OR ") + ") ORDER BY id LIMIT 2").all()
    : [];
  const t = now();
  const createClient = async (identityReview = false) => {
    const notes = [note ? String(note).slice(0, 280) : "", identityReview ? "Possible duplicate identity — review before merging." : ""]
      .filter(Boolean).join("\n").slice(0, 400) || null;
    const info = await db.prepare("INSERT INTO clients(name,company,email,email_norm,phone,phone_norm,tags,notes,source,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
      .run(name ?? "Unknown", company ?? null, email, email, phone, phoneNorm, "[]", notes, source ?? "manual", t, t);
    return { client: await db.prepare("SELECT * FROM clients WHERE id=?").get(info.lastInsertRowid), created: true, returning: false, needsReview: identityReview };
  };
  if (hits.length === 0) return createClient();
  if (hits.length > 1) return createClient(true);

  const c = hits[0];
  const priorHistory = (await db.prepare("SELECT COUNT(*) n FROM client_links WHERE client_id=?").get(c.id))?.n ?? 0;
  const returning = Number(priorHistory) > 0;
  const patch = {};
  if (email && !c.email_norm) { patch.email = email; patch.email_norm = email; }
  if (phone && !c.phone_norm) { patch.phone = phone; patch.phone_norm = phoneNorm; }
  if (company && !c.company) patch.company = company;
  if (Object.keys(patch).length) {
    await db.prepare("UPDATE clients SET " + Object.keys(patch).map((k) => k + "=?").join(",") + ", updated_at=? WHERE id=?")
      .run(...Object.values(patch), t, c.id);
  }
  return { client: await db.prepare("SELECT * FROM clients WHERE id=?").get(c.id), created: false, returning, needsReview: false };
}

function normalizeLeadInput(body = {}, fallbackSource = "Website") {
  const value = (key, max) => {
    const raw = body[key];
    if (raw == null) return "";
    return String(raw).trim().slice(0, max);
  };
  const name = value("name", 120);
  if (!name) return { error: "name is required" };
  const company = value("company", 160) || null;
  const rawEmail = value("email", 254);
  const contactInput = value("contact", 320);
  const email = rawEmail ? normEmail(rawEmail) : normEmail(contactInput);
  if (rawEmail && !email) return { error: "email must be valid" };
  const rawPhone = value("phone", 80);
  if (rawPhone && digits(rawPhone).length < 7) return { error: "phone must contain at least 7 digits" };
  const phoneCandidate = rawPhone || contactInput.match(/\+?\d[\d\s().-]{5,}\d/)?.[0] || "";
  const phone = normPhone(phoneCandidate) ? phoneCandidate.trim().slice(0, 80) : null;
  const preferredChannel = value("preferredChannel", 20).toLowerCase() || null;
  if (preferredChannel && !["email", "phone", "whatsapp"].includes(preferredChannel))
    return { error: "preferredChannel must be email, phone or whatsapp" };
  if (preferredChannel === "email" && !email) return { error: "email is required for the selected contact method" };
  if ((preferredChannel === "phone" || preferredChannel === "whatsapp") && !phone)
    return { error: "a valid phone number is required for the selected contact method" };
  const contact = contactInput || [email, phone].filter(Boolean).join(" · ") || null;
  const consentValue = body.contactConsent;
  const contactConsent = consentValue === true || consentValue === "true" || consentValue === "on"
    ? true
    : consentValue === false || consentValue === "false" ? false : null;
  return {
    name, company, email, phone, contact,
    interest: value("interest", 240) || "General inquiry",
    source: value("source", 80) || fallbackSource,
    note: value("note", 2000) || null,
    preferredChannel, contactConsent,
    landingPath: value("landingPath", 300) || null,
    referrer: value("referrer", 1000) || null,
    utmSource: value("utmSource", 200) || null,
    utmMedium: value("utmMedium", 200) || null,
    utmCampaign: value("utmCampaign", 200) || null,
  };
}

async function createLeadRecord(input, clientId, clientCreated = false, clientReturning = false, identityReview = false, afterCommit = []) {
  const t = now();
  const ref = await nextRef("leads", "L-", 1100);
  const consent = input.contactConsent == null ? null : input.contactConsent ? 1 : 0;
  const consentAt = input.contactConsent === true ? t : null;
  const consentVersion = input.contactConsent === true ? "inquiry-contact-v1" : null;
  const info = await db.prepare(`INSERT INTO leads(
    ref,name,company,interest,source,contact,status,note,email,phone,preferred_channel,contact_consent,is_returning,identity_review,
    consent_at,consent_version,landing_path,referrer,utm_source,utm_medium,utm_campaign,created_at,updated_at
  ) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`).run(
    ref, input.name, input.company, input.interest, input.source, input.contact, "New", input.note,
    input.email, input.phone, input.preferredChannel, consent, clientReturning ? 1 : 0, identityReview ? 1 : 0,
    consentAt, consentVersion, input.landingPath, input.referrer, input.utmSource, input.utmMedium, input.utmCampaign, t, t,
  );
  const lead = rowLead(await db.prepare("SELECT * FROM leads WHERE id=?").get(info.lastInsertRowid));
  await linkClient(clientId, "lead", lead.id);
  const conv = await ensureConv(clientId);
  const contactSummary = [input.email, input.phone].filter(Boolean).join(" · ");
  await appendMsg(conv.id, {
    channel: "system", direction: "system", author: "System",
    body: `New ${input.source} lead ${lead.ref}: "${input.interest}"${contactSummary ? " · " + contactSummary : ""} — follow up within 24h.${input.note ? "\nMessage: " + input.note : ""}${identityReview ? "\nPossible identity match — review before merging." : ""}`,
    meta: { leadId: lead.id, source: input.source, preferredChannel: input.preferredChannel, contactConsent: input.contactConsent, identityReview },
  });
  await db.prepare("UPDATE conversations SET unread=unread+1 WHERE id=?").run(conv.id);
  const due = new Date(Date.now() + 864e5).toISOString().slice(0, 10);
  const taskTitle = identityReview
    ? `Review possible duplicate identity for ${input.name}`
    : `Follow up with ${input.name} about ${input.interest}`;
  await db.prepare(`INSERT INTO tasks(client_id,lead_id,title,due,owner,priority,created_at,updated_at)
    VALUES(?,?,?,?,?,?,?,?)`).run(clientId, lead.id, taskTitle.slice(0, 240), due, "Unassigned", identityReview ? "high" : "normal", t, t);
  afterCommit.push(() => {
    touchInbox({ clientId });
    emit("leads", { title: "New lead " + lead.ref + (clientCreated ? " + client record" : ""), who: "CRM" });
    emit("clients", { title: clientCreated ? "Client record created" : "Client matched", who: "CRM" });
  });
  return { ...lead, clientId, returning: clientReturning, identityReview };
}

const db2s = (v) => "'" + String(v).replace(/'/g, "''") + "'";
const linkClient = async (clientId, entity, entityId) =>
  (await (db.prepare("INSERT OR IGNORE INTO client_links(client_id,entity,entity_id) VALUES(?,?,?)").run(clientId, entity, entityId)));

async function clientTimeline(clientId) {
  const ev = [];
  for (const id of (await (linkIds(clientId, "lead")))) {
    const r = (await (db.prepare("SELECT * FROM leads WHERE id=?").get(id)));
    if (r) ev.push({
      kind: "lead", ref: r.ref, label: "Lead · " + (r.interest ?? "inquiry"), status: r.status,
      source: r.source, email: r.email, phone: r.phone, preferredChannel: r.preferred_channel,
      contactConsent: r.contact_consent == null ? null : !!Number(r.contact_consent),
      returning: r.is_returning == null ? null : !!Number(r.is_returning),
      identityReview: r.identity_review == null ? null : !!Number(r.identity_review), time: r.created_at,
    });
  }
  for (const id of (await (linkIds(clientId, "quote")))) { const r = (await (db.prepare("SELECT * FROM quotes WHERE id=?").get(id))); if (r) ev.push({ kind: "quote", ref: r.ref, label: "Quotation " + r.ref, status: r.status, value: r.total, time: r.created_at }); }
  for (const id of (await (linkIds(clientId, "order")))) { const r = (await (db.prepare("SELECT * FROM orders WHERE id=?").get(id))); if (r) ev.push({ kind: "order", ref: r.ref, label: "Order " + r.ref, status: r.status + " · " + r.stage, value: r.total, time: r.created_at }); }
  for (const id of (await (linkIds(clientId, "invoice")))) { const r = (await (db.prepare("SELECT * FROM invoices WHERE id=?").get(id))); if (r) ev.push({ kind: "invoice", ref: r.ref, label: "Invoice " + r.ref, status: r.status, value: r.total, paid: r.paid, time: r.created_at }); }
  for (const id of (await (linkIds(clientId, "return")))) { const r = (await (db.prepare("SELECT * FROM returns WHERE id=?").get(id))); if (r) ev.push({ kind: "return", ref: r.ref, label: "Return " + r.ref, status: r.state, time: r.created_at }); }
  return ev.sort((a, b) => Date.parse(b.time ?? 0) - Date.parse(a.time ?? 0)).slice(0, 30);
}

async function clientFull(c) {
  const orders = (await Promise.all((await (linkIds(c.id, "order"))).map(async (id) => (await (db.prepare("SELECT * FROM orders WHERE id=?").get(id)))))).filter(Boolean);
  const quotes = (await Promise.all((await (linkIds(c.id, "quote"))).map(async (id) => (await (db.prepare("SELECT * FROM quotes WHERE id=?").get(id)))))).filter(Boolean);
  const invoices = (await Promise.all((await (linkIds(c.id, "invoice"))).map(async (id) => (await (db.prepare("SELECT * FROM invoices WHERE id=?").get(id)))))).filter(Boolean);
  const lifetime = orders.reduce((n, o) => n + o.total, 0);
  const outstanding = invoices.reduce((n, i) => n + Math.max(0, i.total - i.paid), 0);
  const tl = (await (clientTimeline(c.id)));
  const tasks = (await (db.prepare("SELECT * FROM tasks WHERE client_id=? ORDER BY done ASC, datetime(COALESCE(due,'9999')) ASC").all(c.id))).map(rowTask);
  return { ...rowClientLite(c), lifetime, outstanding, quotes: quotes.length, orders: orders.length,
    openBalance: outstanding, lastActivity: tl[0]?.time ?? c.updated_at, timeline: tl, tasks };
}
const rowTask = (r) => ({ id: r.id, clientId: r.client_id, leadId: r.lead_id, quoteId: r.quote_id, orderId: r.order_id, siteVisitId: r.site_visit_id ?? null,
  title: r.title, due: r.due, owner: r.owner, priority: r.priority, done: !!r.done, createdAt: r.created_at, updatedAt: r.updated_at });
const SITE_VISIT_STATUSES = new Set(["Scheduled", "Confirmed", "Completed", "Cancelled"]);
const rowSiteVisit = (r) => ({
  id: r.id, ref: r.ref, leadId: r.lead_id, clientId: r.client_id, leadName: r.lead_name ?? null, leadRef: r.lead_ref ?? null,
  scheduledAt: r.scheduled_at, timezone: r.timezone, address: r.address, owner: r.owner, status: r.status,
  notes: r.notes, outcome: r.outcome, reminderAt: r.reminder_at, reminderTaskId: r.reminder_task_id,
  audit: safeJson(r.audit, []), createdAt: r.created_at, updatedAt: r.updated_at,
});
const normalizeSiteDateTime = (value, label) => {
  const input = String(value ?? "").trim();
  if (!input || !/(?:Z|[+-]\d{2}:\d{2})$/i.test(input)) throw quoteInputError(`${label} must include a timezone offset`);
  const ms = Date.parse(input);
  if (!Number.isFinite(ms)) throw quoteInputError(`${label} must be a valid date and time`);
  return new Date(ms).toISOString();
};
const validTimeZone = (value) => {
  const timezone = String(value ?? "Asia/Karachi").trim().slice(0, 80) || "Asia/Karachi";
  try { new Intl.DateTimeFormat("en-US", { timeZone: timezone }).format(new Date()); }
  catch { throw quoteInputError("timezone must be a valid IANA zone"); }
  return timezone;
};

/* ---- auth endpoints + team management ---- */
const rowUserLite = (u) => ({ id: u.id, email: u.email, name: u.name, role: u.role, active: !!u.active, updatedAt: u.updated_at });
const ipMisses = new Map(); // TODO-5: 30 failed logins/min/IP backstop (successes never count)
app.post("/api/auth/login", wrap(async (req, res) => {
  const raw = String(req.body?.email ?? req.body?.username ?? "").trim().toLowerCase();
  const email = raw.includes("@") ? raw : raw + "@woodex.pk"; // username or full email both work
  const pw = String(req.body?.password ?? "");
  const rl = loginBucket.get(email) ?? { n: 0, t: Date.now() };
  if (Date.now() - rl.t > 60_000) { rl.n = 0; rl.t = Date.now(); }
  const ipk = req.ip ?? "local"; const ipb = ipMisses.get(ipk) ?? { n: 0, t: Date.now() };
  if (Date.now() - ipb.t > 60_000) { ipb.n = 0; ipb.t = Date.now(); }
  if (rl.n >= 5 || ipb.n >= 30) { res.set("retry-after", "60"); return res.status(429).json({ error: "too many attempts — wait a minute" }); }
  const u = (await (db.prepare("SELECT * FROM users WHERE email=?").get(email)));
  const guess = u ? hashPass(pw, u.pass_salt) : hashPass("x", "00");
  if (!u || !crypto.timingSafeEqual(Buffer.from(guess.hash, "hex"), Buffer.from(u.pass_hash, "hex"))) {
    rl.n += 1; loginBucket.set(email, rl);
    ipb.n += 1; ipMisses.set(ipk, ipb);
    return res.status(401).json({ error: "wrong email or password" });
  }
  if (!u.active) return res.status(403).json({ error: "account disabled — ask an owner" });
  loginBucket.delete(email);
  const token = crypto.randomBytes(32).toString("hex");
  const t = now();
  (await (db.prepare("INSERT INTO sessions(token,user_id,created_at,expires_at) VALUES(?,?,?,?)").run(token, u.id, t, new Date(Date.now() + 7 * 864e5).toISOString())));
  emit("users", { title: u.name + " signed in", who: u.name });
  res.json({ token, user: rowUserLite(u), caps: ROLE_CAPS[u.role], roles: ROLES });
}));
app.get("/api/auth/me", wrap((req, res) => res.json({ user: rowUserLite(req.user), caps: ROLE_CAPS[req.user.role], roles: ROLES })));
app.post("/api/auth/logout", wrap(async (req, res) => {
  const token = requestToken(req);
  if (token) (await (db.prepare("DELETE FROM sessions WHERE token=?").run(token)));
  res.json({ ok: true });
}));
app.get("/api/users", wrap(async (req, res) => res.json({ items: (await (db.prepare("SELECT * FROM users ORDER BY id").all())).map(rowUserLite), roles: ROLES, caps: ROLE_CAPS })));
app.post("/api/users", wrap(async (req, res) => {
  const { email, name, role, password } = req.body ?? {};
  const em = String(email ?? "").trim().toLowerCase();
  if (!/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(em)) return res.status(400).json({ error: "valid email required" });
  if (!name || String(name).length > 60) return res.status(400).json({ error: "name required (≤60)" });
  if (!ROLES.includes(role)) return res.status(400).json({ error: "role must be " + ROLES.join("|") });
  if (String(password ?? "").length < 6) return res.status(400).json({ error: "password ≥6 chars" });
  if ((await (db.prepare("SELECT id FROM users WHERE email=?").get(em)))) return res.status(409).json({ error: "email exists" });
  const { salt, hash } = hashPass(password);
  const t = now();
  const info = (await (db.prepare("INSERT INTO users(email,name,role,pass_salt,pass_hash,created_at,updated_at) VALUES(?,?,?,?,?,?,?)").run(em, String(name), role, salt, hash, t, t)));
  emit("users", { title: "Team member added: " + name, who: req.actor });
  res.status(201).json(rowUserLite((await (db.prepare("SELECT * FROM users WHERE id=?").get(info.lastInsertRowid)))));
}));
app.patch("/api/users/:id", wrap(async (req, res) => {
  const u = (await (db.prepare("SELECT * FROM users WHERE id=?").get(req.params.id)));
  if (!u) return res.status(404).json({ error: "user not found" });
  const { role, active, password, name } = req.body ?? {};
  if (role !== undefined && !ROLES.includes(role)) return res.status(400).json({ error: "bad role" });
  if (u.id === req.user.id && active === false) return res.status(400).json({ error: "can't disable your own account" });
  if (u.role === "owner" && role !== undefined && role !== "owner" && (await (db.prepare("SELECT COUNT(*) n FROM users WHERE role='owner' AND active=1").get())).n <= 1)
    return res.status(400).json({ error: "last active owner stays owner" });
  const t = now();
  (await (db.prepare("UPDATE users SET role=?, active=?, name=?, updated_at=? WHERE id=?").run(
    role ?? u.role, active !== undefined ? (active ? 1 : 0) : u.active, name ? String(name).slice(0, 60) : u.name, t, u.id)));
  if (password !== undefined) {
    if (String(password).length < 6) return res.status(400).json({ error: "password ≥6 chars" });
    const { salt, hash } = hashPass(password);
    (await (db.prepare("UPDATE users SET pass_salt=?, pass_hash=? WHERE id=?").run(salt, hash, u.id)));
  }
  if (active === false || password !== undefined) (await (db.prepare("DELETE FROM sessions WHERE user_id=?").run(u.id)));
  emit("users", { title: "Team update: " + u.name, who: req.actor });
  res.json(rowUserLite((await (db.prepare("SELECT * FROM users WHERE id=?").get(u.id)))));
}));

/* ---- P3 realtime: Server-Sent Events bus ---- */
const sseClients = new Set();
const emit = (type, payload = {}) => {
  const json = JSON.stringify({ type, at: now(), ...payload });
  for (const c of sseClients) { try { c.write(`event: ${type}\ndata: ${json}\n\n`); } catch { sseClients.delete(c); } }
};
/* Set-based client rollup for the LIST endpoint.
   `clientFull` is correct but issues ~9 queries per client (links, each order,
   each quote, each invoice, timeline, tasks): 2,943 queries for 321 clients.
   The list only needs six numbers per row, so compute them with five grouped
   queries total. `?q=` filtering happens in SQL for the same reason.

   Portability gotchas that bit here: Postgres rejects GROUP_CONCAT (use
   STRING_AGG), and `MAX(a, b)` is a scalar in SQLite but an AGGREGATE in
   Postgres — a two-arg MAX compiles fine on SQLite and throws
   "function max(double precision, integer) does not exist" on Postgres. Use
   CASE expressions rather than either dialect's convenience functions. */
async function clientRollups(where = "", params = []) {
  const agg = db.kind === "sqlite"
    ? "GROUP_CONCAT(x.entity_id) AS ids"
    : "STRING_AGG(CAST(x.entity_id AS TEXT), ',') AS ids";
  const boolSum = (expr) => `SUM(CASE WHEN ${expr} THEN 1 ELSE 0 END)`;
  const [orderAgg, quoteAgg, invoiceAgg, taskAgg, lastAct] = await Promise.all([
    db.prepare(`SELECT x.client_id, COUNT(*) AS n, COALESCE(SUM(o.total),0) AS total
                FROM client_links x JOIN orders o ON o.id = x.entity_id
                WHERE x.entity = 'order' GROUP BY x.client_id`).all(),
    db.prepare(`SELECT x.client_id, ${agg}
                FROM client_links x WHERE x.entity = 'quote' GROUP BY x.client_id`).all(),
    db.prepare(`SELECT x.client_id, COALESCE(SUM(CASE WHEN i.total - i.paid > 0 THEN i.total - i.paid ELSE 0 END),0) AS outstanding
                FROM client_links x JOIN invoices i ON i.id = x.entity_id
                WHERE x.entity = 'invoice' GROUP BY x.client_id`).all(),
    db.prepare(`SELECT client_id, ${boolSum("done = 0")} AS open
                FROM tasks WHERE client_id IS NOT NULL GROUP BY client_id`).all(),
    /* `clients` has no client_id column — project `id` under that name so every
       rollup map is keyed the same way. */
    db.prepare(`SELECT id AS client_id, MAX(updated_at) AS last FROM clients
                WHERE merged_into IS NULL GROUP BY id`).all(),
  ]);
  const byId = new Map();
  const put = (id) => { if (!byId.has(id)) byId.set(id, { lifetime: 0, outstanding: 0, quotes: 0, orders: 0, openTasks: 0, lastActivity: null }); return byId.get(id); };
  for (const r of orderAgg) { const e = put(r.client_id); e.lifetime = Number(r.total) || 0; e.orders = Number(r.n) || 0; }
  for (const r of quoteAgg) { const e = put(r.client_id); e.quotes = String(r.ids ?? "").split(",").filter(Boolean).length; }
  for (const r of invoiceAgg) { const e = put(r.client_id); e.outstanding = Number(r.outstanding) || 0; }
  for (const r of taskAgg) { const e = put(r.client_id); e.openTasks = Number(r.open) || 0; }
  for (const r of lastAct) { const e = put(r.client_id); e.lastActivity = r.last; }
  void where; void params;
  return byId;
}
app.get("/api/clients", wrap(async (req, res) => {
  const q = String(req.query.q ?? "").trim().toLowerCase();
  const where = q ? `WHERE merged_into IS NULL AND (LOWER(name) LIKE ? OR LOWER(COALESCE(company,'')) LIKE ? OR LOWER(COALESCE(email,'')) LIKE ? OR LOWER(COALESCE(phone,'')) LIKE ?)` : "WHERE merged_into IS NULL";
  const params = q ? Array(4).fill(`%${q}%`) : [];
  const rows = await db.prepare(`SELECT * FROM clients ${where} ORDER BY datetime(updated_at) DESC`).all(...params);
  const roll = await clientRollups();
  const items = rows.map((c) => {
    const e = roll.get(c.id) ?? { lifetime: 0, outstanding: 0, quotes: 0, orders: 0, openTasks: 0, lastActivity: null };
    return { ...rowClientLite(c), lifetime: e.lifetime, outstanding: e.outstanding, quotes: e.quotes,
      orders: e.orders, lastActivity: e.lastActivity ?? c.updated_at, openTasks: e.openTasks };
  });
  res.json({ total: items.length, items });
}));
app.get("/api/clients/review", wrap(async (req, res) => {
  const pairs = [];
  for (const key of ["email_norm", "phone_norm"]) {
    const rows = (await (db.prepare(`SELECT ${key} k, COUNT(*) c, GROUP_CONCAT(CAST(id AS TEXT)) ids FROM clients WHERE merged_into IS NULL AND ${key} IS NOT NULL GROUP BY ${key} HAVING COUNT(*) > 1`).all()));
    for (const r of rows) { const ids = r.ids.split(",").map(Number);
      for (let i = 0; i + 1 < ids.length; i++) {
        const a = (await (db.prepare("SELECT * FROM clients WHERE id=?").get(ids[i]))), b = (await (db.prepare("SELECT * FROM clients WHERE id=?").get(ids[i + 1])));
        pairs.push({ a: rowClientLite(a), b: rowClientLite(b), shared: key === "email_norm" ? "email" : "phone", value: r.k,
          conflict: (a.phone_norm ?? "") !== (b.phone_norm ?? "") && (a.email_norm ?? "") !== (b.email_norm ?? "") ? "other contact differs" : "same " + (key === "email_norm" ? "email" : "phone") });
      } }
  }
  res.json({ total: pairs.length, items: pairs });
}));
app.get("/api/clients/:id", wrap(async (req, res) => {
  const c = (await (db.prepare("SELECT * FROM clients WHERE id=?").get(req.params.id)));
  if (!c) return res.status(404).json({ error: "not found" });
  res.json((await (clientFull(c))));
}));
app.post("/api/clients", wrapTxJson(async (req, afterCommit) => {
  const b = req.body ?? {}; if (!b.name) return { status: 400, body: { error: "name required" } };
  const t = now();
  const info = (await (db.prepare("INSERT INTO clients(name,company,email,email_norm,phone,phone_norm,tags,notes,source,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
    .run(b.name, b.company ?? null, b.email ?? null, normEmail(b.email), b.phone ?? null, normPhone(b.phone),
      JSON.stringify(b.tags ?? []), b.notes ?? null, b.source ?? "manual", t, t)));
  const c = (await (db.prepare("SELECT * FROM clients WHERE id=?").get(info.lastInsertRowid)));
  emit("clients", { title: "Client added · #" + c.id, who: req.actor ?? "Sales" });
  return { status: 201, body: rowClientLite(c) };
}));
app.patch("/api/clients/:id", wrap(async (req, res) => {
  const c = (await (db.prepare("SELECT * FROM clients WHERE id=?").get(req.params.id)));
  if (!c) return res.status(404).json({ error: "not found" });
  const b = req.body ?? {};
  (await (db.prepare("UPDATE clients SET name=?,company=?,email=?,email_norm=?,phone=?,phone_norm=?,city=?,tags=?,notes=?,updated_at=? WHERE id=?")
    .run(b.name ?? c.name, b.company ?? c.company, b.email ?? c.email,
      b.email !== undefined ? normEmail(b.email) : c.email_norm, b.phone ?? c.phone,
      b.phone !== undefined ? normPhone(b.phone) : c.phone_norm, b.city ?? c.city,
      b.tags ? JSON.stringify(b.tags) : c.tags, b.notes ?? c.notes, now(), c.id)));
  res.json(rowClientLite((await (db.prepare("SELECT * FROM clients WHERE id=?").get(c.id)))));
}));
app.post("/api/clients/:id/merge", wrapTxJson(async (req, afterCommit) => {
  const into = (await (db.prepare("SELECT * FROM clients WHERE id=?").get(req.params.id)));
  const from = (await (db.prepare("SELECT * FROM clients WHERE id=?").get(req.body?.from_id)));
  if (!into || !from) return { status: 404, body: { error: "client pair not found" } };
  (await (db.prepare("UPDATE client_links SET client_id=? WHERE client_id=?").run(into.id, from.id)));
  const t = now();
  (await (db.prepare("UPDATE clients SET merged_into=?, updated_at=? WHERE id=?").run(into.id, t, from.id)));
  (await (db.prepare("UPDATE clients SET email=?, email_norm=?, phone=?, phone_norm=?, company=COALESCE(company,?), notes=TRIM(COALESCE(notes,'')||?) WHERE id=?")
    .run(into.email ?? from.email, into.email_norm ?? from.email_norm, into.phone ?? from.phone, into.phone_norm ?? from.phone_norm,
      from.company, from.notes ? " | merged note: " + from.notes : "", into.id)));
  emit("clients", { title: "Clients merged → #" + into.id, who: req.actor ?? "Sales" });
  return (await clientFull((await (db.prepare("SELECT * FROM clients WHERE id=?").get(into.id)))));
}));
app.get("/api/tasks", wrap(async (req, res) => {
  let rows = (await (db.prepare("SELECT * FROM tasks ORDER BY done ASC, datetime(COALESCE(due,'9999')) ASC").all()));
  if (req.query.scope === "today") rows = rows.filter((r) => !r.done && (r.due ?? "") <= now().slice(0, 10));
  res.json({ total: rows.length, open: rows.filter((r) => !r.done).length, overdue: rows.filter((r) => !r.done && r.due && r.due < now().slice(0, 10)).length, items: rows.map(rowTask) });
}));
app.post("/api/tasks", wrap(async (req, res) => {
  const b = req.body ?? {}; if (!b.title) return res.status(400).json({ error: "title required" });
  const t = now();
  const info = (await (db.prepare("INSERT INTO tasks(client_id,lead_id,quote_id,order_id,title,due,owner,priority,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)")
    .run(b.client_id ?? null, b.lead_id ?? null, b.quote_id ?? null, b.order_id ?? null, b.title, b.due ?? null, b.owner ?? "You", b.priority ?? "normal", t, t)));
  const out = rowTask((await (db.prepare("SELECT * FROM tasks WHERE id=?").get(info.lastInsertRowid))));
  emit("tasks", { title: "Task added: " + b.title.slice(0, 60), who: b.owner ?? "You" });
  res.status(201).json(out);
}));
app.patch("/api/tasks/:id", wrap(async (req, res) => {
  const r = (await (db.prepare("SELECT * FROM tasks WHERE id=?").get(req.params.id)));
  if (!r) return res.status(404).json({ error: "not found" });
  const b = req.body ?? {};
  (await (db.prepare("UPDATE tasks SET title=?, due=?, owner=?, priority=?, done=?, updated_at=? WHERE id=?").run(
    b.title ?? r.title, b.due ?? r.due, b.owner ?? r.owner, b.priority ?? r.priority,
    b.done !== undefined ? (b.done ? 1 : 0) : r.done, now(), r.id)));
  res.json(rowTask((await (db.prepare("SELECT * FROM tasks WHERE id=?").get(r.id)))));
}));

const SITE_VISIT_SELECT = `SELECT v.*, l.name AS lead_name, l.ref AS lead_ref FROM site_visits v JOIN leads l ON l.id=v.lead_id`;
app.get("/api/site-visits", wrap(async (req, res) => {
  let rows = await db.prepare(`${SITE_VISIT_SELECT} ORDER BY datetime(v.scheduled_at) ASC LIMIT 250`).all();
  if (req.query.leadId) rows = rows.filter((v) => Number(v.lead_id) === Number(req.query.leadId));
  if (req.query.status) rows = rows.filter((v) => v.status === String(req.query.status));
  res.json({ total: rows.length, items: rows.map(rowSiteVisit) });
}));
app.post("/api/site-visits", wrapTxResponse(async (req, afterCommit) => {
  const body = req.body ?? {};
  const leadId = Number(body.leadId);
  if (!Number.isSafeInteger(leadId) || leadId < 1) return { status: 400, body: { error: "leadId is required" } };
  const lead = await db.prepare("SELECT * FROM leads WHERE id=?").get(leadId);
  if (!lead) return { status: 404, body: { error: "lead not found" } };
  const scheduledAt = normalizeSiteDateTime(body.scheduledAt, "scheduledAt");
  const timezone = validTimeZone(body.timezone);
  const address = String(body.address ?? "").trim().slice(0, 300);
  if (!address) return { status: 400, body: { error: "address or visit location is required" } };
  const status = String(body.status ?? "Scheduled");
  if (!SITE_VISIT_STATUSES.has(status)) return { status: 400, body: { error: "status must be Scheduled, Confirmed, Completed or Cancelled" } };
  const reminderAt = body.reminderAt == null || body.reminderAt === "" ? null : normalizeSiteDateTime(body.reminderAt, "reminderAt");
  if (reminderAt && Date.parse(reminderAt) >= Date.parse(scheduledAt))
    return { status: 400, body: { error: "reminderAt must be before the site visit" } };

  let clientLink = await db.prepare("SELECT client_id FROM client_links WHERE entity='lead' AND entity_id=?").get(lead.id);
  if (!clientLink) {
    const matched = await matchOrCreateClient({ name: lead.name, company: lead.company, contact: lead.contact,
      email: lead.email, phone: lead.phone, source: "site-visit" });
    await linkClient(matched.client.id, "lead", lead.id);
    clientLink = { client_id: matched.client.id };
  }
  const clientId = Number(clientLink.client_id);
  const owner = String(body.owner ?? "").trim().slice(0, 120) || "Unassigned";
  const notes = String(body.notes ?? "").trim().slice(0, 2000) || null;
  const t = now();
  const ref = await nextRef("site_visits", "SV-", 0);
  const audit = [{ who: req.actor ?? "You", action: "booked", fields: ["scheduledAt", "address"], time: t }];
  const visit = await db.prepare(`INSERT INTO site_visits(ref,lead_id,client_id,scheduled_at,timezone,address,owner,status,notes,reminder_at,audit,created_at,updated_at)
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)`).run(ref, lead.id, clientId, scheduledAt, timezone, address, owner, status, notes, reminderAt, JSON.stringify(audit), t, t);
  const visitId = visit.lastInsertRowid;
  let reminderTaskId = null;
  if (reminderAt && !["Completed", "Cancelled"].includes(status)) {
    const task = await db.prepare(`INSERT INTO tasks(client_id,lead_id,site_visit_id,title,due,owner,priority,done,created_at,updated_at)
      VALUES(?,?,?,?,?,?,?,?,?,?)`).run(clientId, lead.id, visitId, `Reminder: site visit ${ref}`, reminderAt, owner, "normal", 0, t, t);
    reminderTaskId = task.lastInsertRowid;
    await db.prepare("UPDATE site_visits SET reminder_task_id=? WHERE id=?").run(reminderTaskId, visitId);
  }
  if (!["Won", "Lost", "Quotation"].includes(lead.status))
    await db.prepare("UPDATE leads SET status='Site visit booked', updated_at=? WHERE id=?").run(t, lead.id);
  const out = rowSiteVisit(await db.prepare(`${SITE_VISIT_SELECT} WHERE v.id=?`).get(visitId));
  out.reminderTaskId = reminderTaskId;
  afterCommit.push(() => {
    emit("site-visits", { title: "Site visit booked: " + ref, who: req.actor ?? "You" });
    emit("leads", { title: "Lead " + lead.ref + " → Site visit booked", who: req.actor ?? "You" });
    if (reminderTaskId) emit("tasks", { title: "Site visit reminder task added: " + ref, who: owner });
  });
  return { status: 201, body: out };
}));
app.patch("/api/site-visits/:id", wrapTxResponse(async (req, afterCommit) => {
  const current = await db.prepare(`${SITE_VISIT_SELECT} WHERE v.id=?`).get(req.params.id);
  if (!current) return { status: 404, body: { error: "site visit not found" } };
  const body = req.body ?? {};
  const has = (key) => Object.prototype.hasOwnProperty.call(body, key);
  const scheduledAt = has("scheduledAt") ? normalizeSiteDateTime(body.scheduledAt, "scheduledAt") : current.scheduled_at;
  const timezone = has("timezone") ? validTimeZone(body.timezone) : current.timezone;
  const address = has("address") ? String(body.address ?? "").trim().slice(0, 300) : current.address;
  if (!address) return { status: 400, body: { error: "address or visit location is required" } };
  const owner = has("owner") ? (String(body.owner ?? "").trim().slice(0, 120) || "Unassigned") : current.owner;
  const status = has("status") ? String(body.status) : current.status;
  if (!SITE_VISIT_STATUSES.has(status)) return { status: 400, body: { error: "unsupported site visit status" } };
  const notes = has("notes") ? (String(body.notes ?? "").trim().slice(0, 2000) || null) : current.notes;
  const outcome = has("outcome") ? (String(body.outcome ?? "").trim().slice(0, 2000) || null) : current.outcome;
  const reminderAt = has("reminderAt")
    ? (body.reminderAt == null || body.reminderAt === "" ? null : normalizeSiteDateTime(body.reminderAt, "reminderAt"))
    : current.reminder_at;
  if (reminderAt && Date.parse(reminderAt) >= Date.parse(scheduledAt))
    return { status: 400, body: { error: "reminderAt must be before the site visit" } };

  const changed = [];
  if (scheduledAt !== current.scheduled_at) changed.push("scheduledAt");
  if (timezone !== current.timezone) changed.push("timezone");
  if (address !== current.address) changed.push("address");
  if (owner !== current.owner) changed.push("owner");
  if (status !== current.status) changed.push("status");
  if (notes !== current.notes) changed.push("notes");
  if (outcome !== current.outcome) changed.push("outcome");
  if (reminderAt !== current.reminder_at) changed.push("reminderAt");
  const t = now();
  const audit = safeJson(current.audit, []);
  if (changed.length) audit.unshift({ who: req.actor ?? "You", action: changed.includes("scheduledAt") ? "rescheduled" : "updated", fields: changed, time: t });
  await db.prepare(`UPDATE site_visits SET scheduled_at=?,timezone=?,address=?,owner=?,status=?,notes=?,outcome=?,reminder_at=?,audit=?,updated_at=? WHERE id=?`)
    .run(scheduledAt, timezone, address, owner, status, notes, outcome, reminderAt, JSON.stringify(audit), t, current.id);

  let reminderTaskId = current.reminder_task_id ?? null;
  const taskDone = ["Completed", "Cancelled"].includes(status) ? 1 : 0;
  if (reminderTaskId) {
    await db.prepare("UPDATE tasks SET due=?,owner=?,done=?,updated_at=? WHERE id=?")
      .run(reminderAt, owner, taskDone, t, reminderTaskId);
  } else if (reminderAt && !taskDone) {
    const task = await db.prepare(`INSERT INTO tasks(client_id,lead_id,site_visit_id,title,due,owner,priority,done,created_at,updated_at)
      VALUES(?,?,?,?,?,?,?,?,?,?)`).run(current.client_id, current.lead_id, current.id, `Reminder: site visit ${current.ref}`, reminderAt, owner, "normal", 0, t, t);
    reminderTaskId = task.lastInsertRowid;
  }
  await db.prepare("UPDATE site_visits SET reminder_task_id=? WHERE id=?").run(reminderTaskId, current.id);

  const lead = await db.prepare("SELECT status FROM leads WHERE id=?").get(current.lead_id);
  if (status === "Completed" && lead?.status === "Site visit booked")
    await db.prepare("UPDATE leads SET status='Site visit completed', updated_at=? WHERE id=?").run(t, current.lead_id);
  else if (status === "Cancelled" && lead?.status === "Site visit booked")
    await db.prepare("UPDATE leads SET status='Qualified', updated_at=? WHERE id=?").run(t, current.lead_id);

  const out = rowSiteVisit(await db.prepare(`${SITE_VISIT_SELECT} WHERE v.id=?`).get(current.id));
  afterCommit.push(() => {
    emit("site-visits", { title: "Site visit " + current.ref + (changed.includes("status") ? " → " + status : " updated"), who: req.actor ?? "You" });
    if (changed.includes("status")) emit("leads", { title: "Site visit status updated: " + current.ref, who: req.actor ?? "You" });
    if (reminderAt !== current.reminder_at || taskDone) emit("tasks", { title: "Site visit reminder updated: " + current.ref, who: owner });
  });
  return { status: 200, body: out };
}));

app.get("/api/events", (req, res) => {
  if (IS_SERVERLESS) return res.status(410).json({ error: "Streaming events are not available in the serverless runtime; clients refresh by polling." });
  res.writeHead(200, { "content-type": "text/event-stream", "cache-control": "no-cache, no-transform", connection: "keep-alive", "x-accel-buffering": "no" });
  res.write("retry: 3000\n\n");
  res.write(`event: hello\ndata: ${JSON.stringify({ type: "hello", at: now() })}\n\n`);
  sseClients.add(res);
  req.on("close", () => sseClients.delete(res));
});

/* ---- P6 Theme Engine: site-wide tokens, persisted in meta['site_theme'] ---- */
const THEME_DEFAULTS = { brand: "#16A34A", darkBrand: null, radius: 4, font: "sans", mode: "light", tintNav: false, announce: null, siteUrl: null };
const readTheme = async () => {
  const raw = (await (db.prepare("SELECT value FROM meta WHERE key='site_theme'").get()));
  let saved = {};
  try { saved = raw ? JSON.parse(raw.value) : {}; } catch { /* corrupt row → defaults */ }
  return { ...THEME_DEFAULTS, ...saved };
};
const isHex = (v) => /^#[0-9a-f]{6}$/i.test(String(v ?? "").trim());
app.get("/api/theme", wrap(async (req, res) => res.json((await (readTheme())))));
app.put("/api/theme", wrap(async (req, res) => {
  const b = req.body ?? {};
  const errs = [];
  if (b.brand !== undefined && !isHex(b.brand)) errs.push("brand must be #rrggbb hex");
  if (b.darkBrand !== undefined && b.darkBrand !== null && !isHex(b.darkBrand)) errs.push("darkBrand must be hex or null");
  if (b.radius !== undefined && (!Number.isFinite(Number(b.radius)) || Number(b.radius) < 0 || Number(b.radius) > 28)) errs.push("radius must be 0–28 (px)");
  if (b.font !== undefined && !["sans", "serif"].includes(b.font)) errs.push("font must be sans|serif");
  if (b.mode !== undefined && !["light", "dark", "auto"].includes(b.mode)) errs.push("mode must be light|dark|auto");
  if (b.siteUrl !== undefined && b.siteUrl !== null && !/^https?:\/\/[^\s"']+$/.test(String(b.siteUrl).trim())) errs.push("siteUrl must be a full http(s) URL or null");
  let announce;
  if (b.announce !== undefined) {
    if (b.announce === null || b.announce === "") announce = null;
    else if (typeof b.announce === "object" && typeof b.announce.text === "string" && b.announce.text.trim() && b.announce.text.length <= 160) {
      if (b.announce.href != null && !/^(\/|https?:\/\/)/.test(String(b.announce.href))) errs.push("announce.href must start with / or http(s)");
      announce = { text: b.announce.text.trim(), href: b.announce.href != null ? String(b.announce.href) : null };
    } else errs.push("announce needs text (1–160 chars) or null to clear");
  }
  if (errs.length) return res.status(400).json({ error: [...new Set(errs)].join("; ") });
  const cur = (await (readTheme()));
  const next = {
    brand: isHex(b.brand ?? cur.brand) ? String(b.brand ?? cur.brand).trim() : cur.brand,
    darkBrand: (b.darkBrand !== undefined ? b.darkBrand : cur.darkBrand) ?? null,
    radius: Number.isFinite(Number(b.radius)) ? Math.round(Number(b.radius)) : cur.radius,
    font: b.font ?? cur.font,
    mode: b.mode ?? cur.mode,
    tintNav: b.tintNav !== undefined ? !!b.tintNav : cur.tintNav,
    announce: announce !== undefined ? announce : (cur.announce ?? null),
    siteUrl: (b.siteUrl !== undefined ? (b.siteUrl ? String(b.siteUrl).trim() : null) : (cur.siteUrl ?? null)) ?? null,
  };
  (await (db.prepare("INSERT INTO meta(key,value) VALUES('site_theme',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value").run(JSON.stringify(next))));
  emit("theme", {});
  res.json(next);
}));

/* ---- P9 marketing: first-party event pixel + analytics + sitemap ---- */
const TRACK_KINDS = new Set(["view", "cta", "scroll"]);
app.post("/api/track", wrap(async (req, res) => {
  const b = req.body ?? {};
  const kind = String(b.kind ?? "");
  if (!TRACK_KINDS.has(kind)) return res.status(400).json({ error: "kind must be view|cta|scroll" });
  const slug = String(b.slug ?? "").slice(0, 80);
  if (!SLUG_RE.test(slug)) return res.status(400).json({ error: "bad slug" });
  let pct = null;
  if (kind === "scroll") { pct = Math.round(Number(b.pct)); if (pct !== 50 && pct !== 90) return res.status(400).json({ error: "pct must be 50 or 90" }); }
  const clip = (v, n) => (v == null ? null : String(v).slice(0, n));
  const page = (await (db.prepare("SELECT id FROM pages WHERE slug=?").get(slug)));
  (await (db.prepare("INSERT INTO track_events(page_id,slug,kind,label,href,cid,ref,utm_source,utm_medium,utm_campaign,pct,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
    .run(page?.id ?? null, slug, kind, clip(b.label, 60), clip(b.href, 300), clip(b.cid, 64), clip(b.ref, 300),
      clip(b.utm?.source, 40), clip(b.utm?.medium, 40), clip(b.utm?.campaign, 60), pct, now())));
  emit("track", { slug, kind });
  res.status(201).json({ ok: true });
}));

const mkSince = (days) => new Date(Date.now() - (days - 1) * 864e5).toISOString().slice(0, 10);
app.get("/api/marketing/stats", wrap(async (req, res) => {
  const days = Math.min(60, Math.max(1, Number(req.query.days) || 14));
  const since = mkSince(days);
  const dayKey = (iso) => String(iso).slice(0, 10);
  const bump = (map, k, f = "c") => { if (k >= since) map[k] = (map[k] ?? 0) + f; };
  const dViews = {}, dCtas = {}, dLeads = {};
  for (const r of (await (db.prepare("SELECT substr(created_at,1,10) d, kind, COUNT(*) c FROM track_events WHERE kind IN ('view','cta') GROUP BY d, kind").all()))) {
    bump(dViews, r.d, r.kind === "view" ? r.c : 0); bump(dCtas, r.d, r.kind === "cta" ? r.c : 0);
  }
  const leadsSql = "SELECT substr(created_at,1,10) d, COUNT(*) c FROM leads WHERE source LIKE 'Landing:%' GROUP BY d";
  let leadTotals = { c: 0 };
  for (const r of (await (db.prepare(leadsSql).all()))) { if (r.d >= since) { dLeads[r.d] = (dLeads[r.d] ?? 0) + r.c; leadTotals = { c: leadTotals.c + r.c }; } }
  const totalsRow = (await (db.prepare("SELECT kind, COUNT(*) c, COUNT(DISTINCT CASE WHEN kind='view' THEN cid END) u, MAX(CASE WHEN kind='scroll' AND pct=50 THEN 1 ELSE 0 END) s50 FROM track_events WHERE substr(created_at,1,10)>=? GROUP BY kind").all(since)));
  const g = {}; for (const r of totalsRow) g[r.kind] = r;
  const views = g.view?.c ?? 0, ctas = g.cta?.c ?? 0;
  const daily = [];
  for (let i = days - 1; i >= 0; i--) {
    const d = new Date(Date.now() - i * 864e5).toISOString().slice(0, 10);
    daily.push({ date: d, views: dViews[d] ?? 0, ctas: dCtas[d] ?? 0, leads: dLeads[d] ?? 0 });
  }
  const pages = (await (db.prepare(`SELECT p.id, p.slug, p.title, p.status,
      (SELECT COUNT(*) FROM track_events t WHERE t.page_id=p.id AND t.kind='view') views,
      (SELECT COUNT(*) FROM track_events t WHERE t.page_id=p.id AND t.kind='cta') ctas,
      (SELECT COUNT(DISTINCT t.cid) FROM track_events t WHERE t.page_id=p.id AND t.kind='view') uniques,
      (SELECT COUNT(*) FROM leads l WHERE l.source = 'Landing: ' || p.slug) leads
    FROM pages p ORDER BY views DESC, p.updated_at DESC`).all()));
  const refs = {};
  for (const r of (await (db.prepare("SELECT ref, COUNT(*) c FROM track_events WHERE kind='view' GROUP BY ref ORDER BY c DESC LIMIT 16").all()))) {
    let host = "direct / typed";
    if (r.ref) { try { host = new URL(r.ref).hostname; } catch { host = "other"; } }
    refs[host] = (refs[host] ?? 0) + r.c;
  }
  const utms = (await (db.prepare("SELECT utm_source s, utm_medium m, utm_campaign camp, COUNT(*) c FROM track_events WHERE kind='view' AND utm_source IS NOT NULL GROUP BY s, m, camp ORDER BY c DESC LIMIT 8").all()));
  const feed = (await (db.prepare("SELECT slug, kind, label, created_at FROM track_events ORDER BY id DESC LIMIT 12").all()));
  res.json({ days, since, totals: { views, ctas, unique: g.view?.u ?? 0, scroll50: g.scroll?.c ?? 0,
    ctr: views ? +((ctas / views) * 100).toFixed(1) : 0, leads: leadTotals.c,
    convPct: views ? +((leadTotals.c / views) * 100).toFixed(1) : 0 },
    daily, pages, refs: Object.entries(refs).map(([label, n]) => ({ label, n })).sort((a, b) => b.n - a.n).slice(0, 8),
    utms, feed });
}));
app.get("/api/pages/:key/analytics", wrap(async (req, res) => {
  const r = (await (db.prepare("SELECT * FROM pages WHERE id=? OR slug=?").get(req.params.key, req.params.key)));
  if (!r) return res.status(404).json({ error: "page not found" });
  const days = Math.min(60, Math.max(1, Number(req.query.days) || 14));
  const since = mkSince(days);
  const daily = {};
  for (const x of (await (db.prepare("SELECT substr(created_at,1,10) d, kind, COUNT(*) c FROM track_events WHERE page_id=? GROUP BY d, kind").all(r.id)))) {
    if (x.d < since) continue;
    (daily[x.d] ??= { date: x.d, views: 0, ctas: 0, scrolls: 0 })[x.kind === "view" ? "views" : x.kind === "cta" ? "ctas" : "scrolls"] += x.c;
  }
  const topCtas = (await (db.prepare("SELECT COALESCE(label,'(unlabeled)') label, COUNT(*) c FROM track_events WHERE page_id=? AND kind='cta' GROUP BY label ORDER BY c DESC LIMIT 8").all(r.id)));
  const totals = (await (db.prepare("SELECT COUNT(*) c, SUM(CASE WHEN kind='view' THEN 1 ELSE 0 END) v, SUM(CASE WHEN kind='cta' THEN 1 ELSE 0 END) k FROM track_events WHERE page_id=?").get(r.id)));
  const leads = (await (db.prepare("SELECT COUNT(*) c FROM leads WHERE source = 'Landing: ' || ?").get(r.slug))).c;
  res.json({ page: { id: r.id, slug: r.slug, title: r.title, status: r.status }, totals: { events: totals.c, views: totals.v ?? 0, ctas: totals.k ?? 0, leads },
    daily: Object.values(daily).sort((a, b) => a.date.localeCompare(b.date)), topCtas });
}));
app.delete("/api/track", wrap(async (req, res) => {
  const conds = []; const args = [];
  if (req.query.slug) { conds.push("slug=?"); args.push(String(req.query.slug).slice(0, 80)); }
  if (req.query.before) { conds.push("created_at<?"); args.push(String(req.query.before) + (String(req.query.before).length === 10 ? "T23:59:59.999Z" : "")); }
  const info = conds.length ? (await (db.prepare("DELETE FROM track_events WHERE " + conds.join(" AND ")).run(...args))) : (await (db.prepare("DELETE FROM track_events").run()));
  res.json({ deleted: info.changes });
}));
app.get("/api/inbox", wrap(async (req, res) => {
  const { box = "all", channel, q } = req.query;
  let rows = (await (db.prepare("SELECT cv.*, cl.name clientName, cl.phone, cl.company, cl.city FROM conversations cv JOIN clients cl ON cl.id=cv.client_id AND cl.merged_into IS NULL ORDER BY datetime(cv.last_at) DESC").all()));
  if (box === "unread") rows = rows.filter((r) => r.unread > 0);
  if (q) { const needle = String(q).toLowerCase();
    const matchingThreads = new Set((await (db.prepare("SELECT DISTINCT conversation_id FROM messages WHERE lower(body) LIKE ?").all("%" + needle + "%"))).map((m) => m.conversation_id));
    rows = rows.filter((r) => (r.clientName + " " + (r.company ?? "") + " " + (r.phone ?? "")).toLowerCase().includes(needle) || matchingThreads.has(r.id)); }
  const items = (await Promise.all(rows.map(async (r) => {
    const last = (await (db.prepare("SELECT * FROM messages WHERE conversation_id=? ORDER BY id DESC LIMIT 1").get(r.id)));
    const total = (await (db.prepare("SELECT COUNT(*) n FROM messages WHERE conversation_id=?").get(r.id))).n;
    if (channel && last?.channel !== channel) return null;
    const lead = (await (db.prepare("SELECT l.ref, l.status, l.interest FROM client_links x JOIN leads l ON l.id=x.entity_id WHERE x.client_id=? AND x.entity='lead' ORDER BY l.id DESC LIMIT 1").get(r.client_id)));
    return { id: r.id, clientId: r.client_id, clientName: r.clientName, company: r.company, phone: r.phone, city: r.city,
      status: r.status, assignee: r.assignee, priority: r.priority, unread: r.unread, lastAt: r.last_at, updatedAt: r.updated_at,
      lastMessage: last ? { body: String(last.body).slice(0, 90), channel: last.channel, direction: last.direction, author: last.author } : null,
      totalMessages: total, lead: lead ?? null, wa: waPhone(r.phone) };
  }))).filter(Boolean);
  const counts = { all: (await (db.prepare("SELECT COUNT(*) n FROM conversations").get())).n,
    unread: (await (db.prepare("SELECT COUNT(*) n FROM conversations WHERE unread>0").get())).n,
    open: (await (db.prepare("SELECT COUNT(*) n FROM conversations WHERE status='open'").get())).n };
  res.json({ total: items.length, counts, items });
}));
app.get("/api/inbox/templates", wrap((req, res) => res.json({ items: INBOX_TEMPLATES })));
app.get("/api/inbox/:clientId/thread", wrap(async (req, res) => {
  const cl = (await (db.prepare("SELECT * FROM clients WHERE id=?").get(req.params.clientId)));
  if (!cl) return res.status(404).json({ error: "client not found" });
  const conv = (await (ensureConv(cl.id)));
  const msgs = (await (db.prepare("SELECT * FROM messages WHERE conversation_id=? ORDER BY id ASC").all(conv.id)));
  if (conv.unread > 0) (await (db.prepare("UPDATE conversations SET unread=0, updated_at=? WHERE id=?").run(now(), conv.id)));
  const orders = (await (db.prepare("SELECT o.ref, o.status, o.total FROM client_links x JOIN orders o ON o.id=x.entity_id WHERE x.client_id=? AND x.entity='order' ORDER BY o.id DESC LIMIT 3").all(cl.id)));
  const quotes = (await (db.prepare("SELECT q.ref, q.status, q.total FROM client_links x JOIN quotes q ON q.id=x.entity_id WHERE x.client_id=? AND x.entity='quote' ORDER BY q.id DESC LIMIT 3").all(cl.id)));
  const invoices = (await (db.prepare("SELECT i.ref, i.status, i.due FROM client_links x JOIN invoices i ON i.id=x.entity_id WHERE x.client_id=? AND x.entity='invoice' ORDER BY i.id DESC LIMIT 2").all(cl.id)));
  touchInbox({ clientId: cl.id });
  res.json({ conversation: { id: conv.id, clientId: cl.id, status: conv.status, assignee: conv.assignee, priority: conv.priority, unread: 0, lastAt: conv.last_at },
    client: rowClientLite(cl), messages: msgs.map(rowMsg),
    context: { orders, quotes, invoices }, wa: waPhone(cl.phone) });
}));
app.post("/api/inbox/:clientId/messages", wrap(async (req, res) => {
  const cl = (await (db.prepare("SELECT * FROM clients WHERE id=?").get(req.params.clientId)));
  if (!cl) return res.status(404).json({ error: "client not found" });
  const b = req.body ?? {};
  const body = String(b.body ?? "").trim();
  if (!body || body.length > 4000) return res.status(400).json({ error: "body required (1–4000 chars)" });
  const channel = CONV_CHANNELS.has(b.channel) ? b.channel : "note";
  let direction = ["inbound", "outbound"].includes(b.direction) ? b.direction : "outbound";
  if (channel === "system") direction = "system";
  const conv = (await (ensureConv(cl.id)));
  const m = (await (appendMsg(conv.id, { channel, direction, author: b.author ?? req.actor ?? "You", body })));
  touchInbox({ clientId: cl.id });
  res.status(201).json(rowMsg(m));
}));
app.post("/api/inbox/:clientId/handoff", wrap(async (req, res) => {
  const cl = (await (db.prepare("SELECT * FROM clients WHERE id=?").get(req.params.clientId)));
  if (!cl) return res.status(404).json({ error: "client not found" });
  const phone = waPhone(cl.phone);
  if (!phone) return res.status(400).json({ error: "client has no dialable phone number" });
  const b = req.body ?? {};
  const tpl = INBOX_TEMPLATES.find((x) => x.id === b.templateId);
  const body = String(b.body ?? "").trim() || (tpl ? (await (resolveTemplate(tpl, cl.id))) : "");
  if (!body) return res.status(400).json({ error: "templateId or body required" });
  const url = "https://wa.me/" + phone + "?text=" + encodeURIComponent(body);
  const conv = (await (ensureConv(cl.id)));
  const m = (await (appendMsg(conv.id, { channel: "whatsapp", direction: "outbound", author: b.author ?? req.actor ?? "You", body, meta: { handoff: url, template: tpl?.id ?? null } })));
  if (tpl) (await (db.prepare("UPDATE conversations SET updated_at=?, last_at=? WHERE id=?").run(now(), now(), conv.id)));
  touchInbox({ clientId: cl.id });
  res.json({ url, message: rowMsg(m) });
}));
app.patch("/api/inbox/conversations/:id", wrap(async (req, res) => {
  const cv = (await (db.prepare("SELECT * FROM conversations WHERE id=?").get(req.params.id)));
  if (!cv) return res.status(404).json({ error: "conversation not found" });
  const b = req.body ?? {};
  if (b.status !== undefined && !CONV_STATUS.has(b.status)) return res.status(400).json({ error: "status must be open|pending|resolved|snoozed" });
  (await (db.prepare("UPDATE conversations SET status=?, assignee=?, priority=?, unread=?, updated_at=? WHERE id=?").run(
    b.status ?? cv.status, b.assignee !== undefined ? (b.assignee ? String(b.assignee).slice(0, 40) : null) : cv.assignee,
    b.priority !== undefined ? (b.priority ? 1 : 0) : cv.priority, Number.isFinite(Number(b.unread)) ? Math.max(0, Number(b.unread)) : cv.unread, now(), cv.id)));
  touchInbox({ clientId: cv.client_id });
  res.json((await (db.prepare("SELECT * FROM conversations WHERE id=?").get(cv.id))));
}));

const xmlEsc = (x) => String(x).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&apos;" }[c]));
/* One sitemap, built from the shared PUBLIC_ROUTES list plus whatever the
   database knows: published CMS pages and live products. Before this, the API
   listed pages+products and silently omitted all 16 static marketing routes. */
app.get("/sitemap.xml", wrap(async (req, res) => {
  const theme = await readTheme();
  const base = (theme.siteUrl || "http://localhost:5174").replace(/\/+$/, "");
  const today = new Date().toISOString().slice(0, 10);
  const urls = [];
  for (const r of PUBLIC_ROUTES)
    urls.push(`  <url><loc>${xmlEsc(absoluteUrl(base, r.path))}</loc><lastmod>${today}</lastmod><changefreq>${r.changefreq}</changefreq><priority>${r.priority}</priority></url>`);
  for (const x of await db.prepare("SELECT slug, updated_at FROM pages WHERE status='Published' ORDER BY updated_at DESC").all())
    urls.push(`  <url><loc>${xmlEsc(base + "/p/" + x.slug)}</loc><lastmod>${String(x.updated_at).slice(0, 10)}</lastmod></url>`);
  for (const x of await db.prepare("SELECT id, updated_at FROM products ORDER BY updated_at DESC LIMIT 500").all())
    urls.push(`  <url><loc>${xmlEsc(base + "/shop/" + x.id)}</loc><lastmod>${String(x.updated_at).slice(0, 10)}</lastmod></url>`);
  res.setHeader("content-type", "application/xml; charset=utf-8");
  res.end(`<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls.join("\n")}\n</urlset>\n`);
}));
app.get("/robots.txt", wrap(async (req, res) => {
  const base = ((await (readTheme())).siteUrl || "http://localhost:5174").replace(/\/+$/, "");
  res.setHeader("content-type", "text/plain; charset=utf-8");
  res.end("User-agent: *\nAllow: /\nDisallow: /api/\nSitemap: " + base + "/sitemap.xml\n");
}));

app.post("/api/target", wrap(async (req, res) => {
  const v = Number(req.body?.value);
  if (!(v > 0)) return res.status(400).json({ error: "value must be > 0" });
  (await (db.prepare("INSERT INTO meta(key,value) VALUES('target',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value").run(String(v))));
  res.json({ target: v });
}));

/* ---- P5 finance: invoices, payments, returns, public lookup ---- */
const subtotalOf = (items) => roundMoney(items.reduce((sum, item) => {
  const lineTotal = item.lineTotal == null ? (Number(item.price) || 0) * (Number(item.qty) || 1) : Number(item.lineTotal) || 0;
  return sum + lineTotal;
}, 0));
const totalsFrom = (items, { discount = 0, tax = 0, shipping = 0 }) => {
  const subtotal = subtotalOf(items);
  return { subtotal, total: roundMoney(Math.max(0, subtotal - discount + tax + shipping)) };
};
const insertInvoice = async ({ ref, quoteId, orderId, customer, items, subtotal, discount, tax, shipping, total, due, notes }) => {
  const t = now();
  const info = (await (db.prepare(`INSERT INTO invoices(ref,quote_id,order_id,customer,items,subtotal,discount,tax,shipping,total,status,due,notes,created_at,updated_at)
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`).run(ref, quoteId ?? null, orderId ?? null, customer, JSON.stringify(items),
    subtotal ?? subtotalOf(items), discount ?? 0, tax ?? 0, shipping ?? 0, total, "Issued", due ?? null, notes ?? null, t, t)));
  return (await (db.prepare("SELECT * FROM invoices WHERE id=?").get(info.lastInsertRowid)));
};
const nextRef = async (table, prefix, floor = 0) => { // MAX-based: survives row deletion (QA TODO-3)
  const m = (await (db.prepare("SELECT MAX(CAST(substr(ref, ?) AS INTEGER)) v FROM " + table).get(prefix.length + 1))).v ?? 0;
  return prefix + String(Math.max(m, floor) + 1);
};

app.get("/api/invoices", wrap(async (req, res) => {
  let rows = (await (db.prepare("SELECT * FROM invoices ORDER BY datetime(created_at) DESC").all())).map(decorateInvoice);
  const f = req.query.status;
  if (f === "open") rows = rows.filter((r) => ["Issued", "Partially Paid", "Overdue"].includes(r.status));
  else if (f) rows = rows.filter((r) => r.status === f);
  res.json({ total: rows.length,
    collected: rows.reduce((n, r) => n + r.paid, 0),
    outstanding: rows.reduce((n, r) => n + (r.status === "Cancelled" ? 0 : r.balance), 0),
    items: rows });
}));
app.get("/api/invoices/:id", wrap(async (req, res) => {
  const r = (await (db.prepare("SELECT * FROM invoices WHERE id=? OR ref=?").get(req.params.id, req.params.id)));
  if (!r) return res.status(404).json({ error: "not found" });
  const payments = (await (db.prepare("SELECT * FROM payments WHERE invoice_id=? ORDER BY datetime(created_at) DESC").all(r.id)));
  res.json({ ...decorateInvoice(r), payments });
}));
app.post("/api/invoices", wrap(async (req, res) => {
  const b = req.body ?? {};
  let { customer, items } = b;
  let quoteId = null, orderId = null, quoteAmounts = null;
  if (b.quote_id) { const q = (await (db.prepare("SELECT * FROM quotes WHERE id=? OR ref=?").get(b.quote_id, b.quote_id)));
    if (!q) return res.status(400).json({ error: "quote not found" });
    customer = q.customer; items = JSON.parse(q.items || "[]"); quoteId = q.id;
    const saved = safeJson(q.details, {}).calculation ?? {};
    quoteAmounts = { subtotal: Number(saved.subtotal) || subtotalOf(items), discount: Number(saved.discount) || 0,
      tax: Number(saved.tax) || 0, shipping: Number(saved.shipping) || 0,
      total: Number(q.total) || Number(saved.total) || subtotalOf(items) }; }
  else if (b.order_id) { const o = (await (db.prepare("SELECT * FROM orders WHERE id=? OR ref=?").get(b.order_id, b.order_id)));
    if (!o) return res.status(400).json({ error: "order not found" });
    customer = customer || o.customer; items = items ?? JSON.parse(o.items); orderId = o.id; }
  if (!customer || !Array.isArray(items) || !items.length) return res.status(400).json({ error: "customer+items or quote_id/order_id required" });
  const amounts = quoteAmounts ?? totalsFrom(items, b);
  const r = (await (insertInvoice({ ref: (await (nextRef("invoices", "INV-26-", 1001))), quoteId, orderId, customer, items,
    subtotal: quoteAmounts?.subtotal, discount: quoteAmounts?.discount ?? b.discount, tax: quoteAmounts?.tax ?? b.tax,
    shipping: quoteAmounts?.shipping ?? b.shipping, total: quoteAmounts?.total ?? (typeof b.total === "number" ? b.total : amounts.total),
    due: b.due, notes: b.notes })));
  if (quoteId) (await (db.prepare("UPDATE quotes SET audit=?, updated_at=? WHERE id=?").run(
    JSON.stringify([{ who: "You", action: "invoice " + r.ref + " issued", time: now() },
      ...safeJson((await (db.prepare("SELECT audit FROM quotes WHERE id=?").get(quoteId)))?.audit, [])]), now(), quoteId)));
  emit("invoices", { title: "Invoice " + r.ref + " issued", who: r.customer });
  res.status(201).json(rowInvoice(r));
}));
app.post("/api/quotes/:id/invoice", wrapTxJson(async (req, afterCommit) => {
  const q = (await (db.prepare("SELECT * FROM quotes WHERE id=? OR ref=?").get(req.params.id, req.params.id)));
  if (!q) return { status: 404, body: { error: "quote not found" } };
  const items = JSON.parse(q.items || "[]");
  const b = req.body ?? {};
  const saved = safeJson(q.details, {}).calculation ?? {};
  const amounts = { subtotal: Number(saved.subtotal) || subtotalOf(items), discount: Number(saved.discount) || 0,
    tax: Number(saved.tax) || 0, shipping: Number(saved.shipping) || 0,
    total: Number(q.total) || Number(saved.total) || subtotalOf(items) };
  const r = (await (insertInvoice({ ref: (await (nextRef("invoices", "INV-26-", 1001))), quoteId: q.id, orderId: null,
    customer: q.customer, items, subtotal: amounts.subtotal, discount: amounts.discount, tax: amounts.tax, shipping: amounts.shipping,
    total: amounts.total, due: b.due, notes: b.notes })));
  (await (db.prepare("UPDATE quotes SET audit=?, updated_at=? WHERE id=?").run(
    JSON.stringify([{ who: "You", action: "invoice " + r.ref + " issued from quote", time: now() },
      ...JSON.parse(q.audit || "[]")]), now(), q.id)));
  { const cl = (await (db.prepare("SELECT id AS c FROM clients WHERE lower(name)=lower(?) AND merged_into IS NULL").get(r.customer)));
    if (cl) (await (linkClient(cl.c, "invoice", r.id))); }
  emit("invoices", { title: "Invoice " + r.ref + " issued", who: r.customer });
  return { status: 201, body: rowInvoice(r) };
}));
app.patch("/api/invoices/:id", wrap(async (req, res) => {
  const r = (await (db.prepare("SELECT * FROM invoices WHERE id=? OR ref=?").get(req.params.id, req.params.id)));
  if (!r) return res.status(404).json({ error: "not found" });
  const b = req.body ?? {};
  emit("invoices", { title: "Invoice " + r.ref + " → " + (b.status ?? r.status), who: r.customer });
  (await (db.prepare("UPDATE invoices SET status=?, due=?, notes=?, discount=?, tax=?, shipping=?, total=?, updated_at=? WHERE id=?").run(
    b.status ?? r.status, b.due ?? r.due, b.notes ?? r.notes,
    b.discount ?? r.discount, b.tax ?? r.tax, b.shipping ?? r.shipping,
    b.total ?? (b.discount != null || b.tax != null || b.shipping != null
      ? Math.max(0, r.subtotal - (b.discount ?? r.discount) + (b.tax ?? r.tax) + (b.shipping ?? r.shipping)) : r.total),
    now(), r.id)));
  res.json(rowInvoice((await (db.prepare("SELECT * FROM invoices WHERE id=?").get(r.id)))));
}));
app.post("/api/invoices/:id/payments", wrapTxJson(async (req, afterCommit) => {
  const r = (await (db.prepare("SELECT * FROM invoices WHERE id=? OR ref=?").get(req.params.id, req.params.id)));
  if (!r) return { status: 404, body: { error: "not found" } };
  const amount = Number(req.body?.amount);
  if (!(amount > 0)) return { status: 400, body: { error: "amount must be > 0" } };
  const t = now();
  (await (db.prepare("INSERT INTO payments(invoice_id,amount,method,reference,note,created_at) VALUES(?,?,?,?,?,?)")
    .run(r.id, amount, req.body?.method ?? "Bank transfer", req.body?.reference ?? null, req.body?.note ?? null, t)));
  const paid = r.paid + amount;
  const status = paid >= r.total ? "Paid" : "Partially Paid";
  (await (db.prepare("UPDATE invoices SET paid=?, status=?, updated_at=? WHERE id=?").run(paid, status, t, r.id)));
  emit("invoices", { title: "Payment " + (amount >= r.balance ? "" : "partial ") + fmtMoney(amount) + " on " + r.ref, who: r.customer });
  emit("payments", { amount, invoiceId: r.id, method: req.body?.method ?? "Bank transfer" });
  return { status: 201, body: { ...rowInvoice((await (db.prepare("SELECT * FROM invoices WHERE id=?").get(r.id)))),
    payments: (await (db.prepare("SELECT * FROM payments WHERE invoice_id=? ORDER BY datetime(created_at) DESC").all(r.id))) } };
}));

const RETURN_STATES = ["Requested", "Inspecting", "Refunding", "Closed"];
app.get("/api/returns", wrap(async (req, res) => {
  let rows = (await (db.prepare("SELECT * FROM returns ORDER BY datetime(created_at) DESC").all())).map(rowReturn);
  if (req.query.state) rows = rows.filter((r) => r.state === req.query.state);
  res.json({ total: rows.length, open: rows.filter((r) => r.state !== "Closed").length, items: rows });
}));
app.post("/api/returns", wrapTxJson(async (req, afterCommit) => {
  const b = req.body ?? {};
  let customer = b.customer; let orderId = null;
  if (b.order_id || b.order_ref) {
    const o = b.order_id != null
      ? (await (db.prepare("SELECT * FROM orders WHERE id=?").get(b.order_id)))
      : (await (db.prepare("SELECT * FROM orders WHERE ref=?").get(b.order_ref)));
    if (!o) return { status: 400, body: { error: "order not found" } }; customer = customer || o.customer; orderId = o.id;
  }
  if (!customer || !b.reason) return { status: 400, body: { error: "customer (or order_ref) and reason required" } };
  const t = now();
  const info = (await (db.prepare("INSERT INTO returns(ref,order_id,customer,item,reason,state,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)")
    .run((await (nextRef("returns", "RMA-", 201))), orderId, customer, b.item ?? null, b.reason, "Requested", t, t)));
  const retOut = rowReturn((await (db.prepare("SELECT * FROM returns WHERE id=?").get(info.lastInsertRowid))));
  emit("returns", { title: "RMA opened for " + customer, who: customer, context: b.reason });
  return { status: 201, body: retOut };
}));
app.patch("/api/returns/:id", wrap(async (req, res) => {
  const r = (await (db.prepare("SELECT * FROM returns WHERE id=? OR ref=?").get(req.params.id, req.params.id)));
  if (!r) return res.status(404).json({ error: "not found" });
  const b = req.body ?? {};
  (await (db.prepare("UPDATE returns SET state=?, refund_amount=?, resolution=?, updated_at=? WHERE id=?").run(
    b.state ?? r.state, b.refund_amount ?? b.refundAmount ?? r.refund_amount, b.resolution ?? r.resolution, now(), r.id)));
  const rOut = rowReturn((await (db.prepare("SELECT * FROM returns WHERE id=?").get(r.id))));
  if (b.state) emit("returns", { title: "RMA " + r.ref + " → " + b.state, who: r.customer });
  res.json(rOut);
}));

/* Real revenue series for the Overview + Analytics charts.
   Sums orders by month, plus collected payments, over a bounded window. */
app.get("/api/stats/revenue", wrap(async (req, res) => {
  const months = Math.max(1, Math.min(24, Number(req.query.months) || 6));
  const buckets = new Map();
  const stamp = (d) => (d instanceof Date ? d.toISOString() : new Date(d).toISOString());
  const bump = (iso, key, amount) => {
    if (!iso) return;
    const d = new Date(iso);
    if (Number.isNaN(d.getTime())) return;
    const label = d.toISOString().slice(0, 7); // YYYY-MM
    const b = buckets.get(label) ?? { label, revenue: 0, orders: 0, collected: 0 };
    if (key === "orders") { b.orders += 1; b.revenue += Number(amount) || 0; }
    if (key === "collected") b.collected += Number(amount) || 0;
    buckets.set(label, b);
  };

  for (const r of (await (db.prepare("SELECT created_at, total FROM orders").all()))) bump(r.created_at, "orders", r.total);
  for (const r of (await (db.prepare("SELECT created_at, amount FROM payments").all()))) bump(r.created_at, "collected", r.amount);

  // Emit a continuous window so a quiet month shows as zero, never as a gap.
  const series = [];
  const cursor = new Date();
  cursor.setUTCDate(1);
  for (let i = months - 1; i >= 0; i--) {
    const d = new Date(Date.UTC(cursor.getUTCFullYear(), cursor.getUTCMonth() - i, 1));
    const label = d.toISOString().slice(0, 7);
    const b = buckets.get(label) ?? { label, revenue: 0, orders: 0, collected: 0 };
    series.push({ label, revenue: Math.round(b.revenue), orders: b.orders, collected: Math.round(b.collected) });
  }

  const current = series.slice(-1)[0] ?? { revenue: 0, orders: 0 };
  const prior = series.slice(-2)[0] ?? { revenue: 0, orders: 0 };
  const pct = (a, b) => (b > 0 ? Math.round(((a - b) / b) * 1000) / 10 : a > 0 ? 100 : 0);
  res.json({
    months,
    series,
    summary: {
      totalRevenue: series.reduce((n, s) => n + s.revenue, 0),
      totalOrders: series.reduce((n, s) => n + s.orders, 0),
      collected: series.reduce((n, s) => n + s.collected, 0),
      revenueChange: pct(current.revenue, prior.revenue),
      ordersChange: pct(current.orders, prior.orders),
    },
  });
}));

app.get("/api/stats", wrap(async (req, res) => {
  const hotLeadRows = (await (db.prepare("SELECT * FROM leads ORDER BY datetime(updated_at) DESC LIMIT 60").all())).map(rowLead);
  const hotLeadScores = (await Promise.all(hotLeadRows.map(async (l) => (await (scoreLead(l))))));
  const hotLeads = hotLeadScores.filter((x) => x.score >= 55).length;
  const n = async (q) => (await (db.prepare(q).get())).n;
  const byStatus = async (table) => {
    const rows = (await (db.prepare(`SELECT status, COUNT(*) c, COALESCE(SUM(total),0) s FROM ${table} GROUP BY status`).all()));
    return Object.fromEntries(rows.map((r) => [r.status, { count: r.c, value: r.s }]));
  };
  const leadBy = Object.fromEntries((await (db.prepare("SELECT status, COUNT(*) c FROM leads GROUP BY status").all())).map((r) => [r.status, r.c]));
  const rec = async (table, type) => (await (db.prepare(`SELECT * FROM ${table} ORDER BY datetime(created_at) DESC LIMIT 3`).all())).map((r) => {
    if (table === "leads") return { type, title: type === "lead" ? "New lead captured" : r.name, who: r.name, context: r.interest ?? "", time: r.created_at };
    return { type, title: `${table === "quotes" ? "Quotation" : "Order"} ${r.ref} · ${r.status}`, who: r.customer, context: `${(JSON.parse(r.items)).length} line items`, time: r.created_at };
  });
  const revenue = (await (db.prepare("SELECT COALESCE(SUM(total),0) v FROM orders WHERE stage IN ('delivery','installation')").get())).v;
  res.json({
    products: { total: (await (n("SELECT COUNT(*) n FROM products"))), inStock: (await (n("SELECT COUNT(*) n FROM products WHERE in_stock=1"))), avgPrice: (await (db.prepare("SELECT AVG(price) v FROM products").get())).v },
    leads: { total: (await (n("SELECT COUNT(*) n FROM leads"))), byStatus: leadBy },
    quotes: { total: (await (n("SELECT COUNT(*) n FROM quotes"))), byStatus: (await (byStatus("quotes"))), pipelineValue: (await (db.prepare("SELECT COALESCE(SUM(total),0) v FROM quotes WHERE status NOT IN ('Rejected','Expired')").get())).v },
    orders: { total: (await (n("SELECT COUNT(*) n FROM orders"))), byStage: (await (byStatus("orders"))), revenue },
    invoices: { total: (await (n("SELECT COUNT(*) n FROM invoices"))),
      collected: (await (db.prepare("SELECT COALESCE(SUM(paid),0) v FROM invoices").get())).v,
      outstanding: (await (db.prepare("SELECT COALESCE(SUM(total-paid),0) v FROM invoices WHERE status NOT IN ('Paid','Cancelled')").get())).v,
      byStatus: (await (byStatus("invoices"))) },
    returns: { total: (await (n("SELECT COUNT(*) n FROM returns"))), open: (await (n("SELECT COUNT(*) n FROM returns WHERE state != 'Closed'"))) },
    recent: [...(await (rec("leads", "lead"))), ...(await (rec("quotes", "quote"))), ...(await (rec("orders", "production"))),
      ...(await (db.prepare("SELECT * FROM invoices ORDER BY datetime(created_at) DESC LIMIT 2").all())).map((r) => ({ type: "invoice", title: `Invoice ${r.ref} · ${r.status}`, who: r.customer, context: "finance", time: r.created_at })),
      ...(await (db.prepare("SELECT * FROM returns ORDER BY datetime(created_at) DESC LIMIT 1").all())).map((r) => ({ type: "return", title: `Return ${r.ref} · ${r.state}`, who: r.customer, context: r.reason ?? "", time: r.created_at }))].slice(0, 8),
    finance: (await ((async () => {
      const delivered = (await (db.prepare("SELECT COUNT(*) n, COALESCE(SUM(total),0) v FROM orders WHERE stage IN ('delivery','installation')").get()));
      const target = Number((await (db.prepare("SELECT value FROM meta WHERE key='target'").get()))?.value ?? 10000000);
      const won = (await (n("SELECT COUNT(*) n FROM quotes WHERE status='Approved'")));
      const open = (await (n("SELECT COUNT(*) n FROM quotes WHERE status NOT IN ('Approved','Rejected','Expired')")));
      const agg = {};
      for (const o of (await (db.prepare("SELECT items FROM orders").all()))) for (const it of JSON.parse(o.items)) {
        const k = it.name || "Item"; const a = (agg[k] ??= { name: k, qty: 0, value: 0 });
        a.qty += it.qty || 1; a.value += (it.price || 0) * (it.qty || 1);
      }
      const topProducts = Object.values(agg).sort((x, y) => y.value - x.value).slice(0, 5);
      const sources = (await (db.prepare("SELECT COALESCE(source,'Other') k, COUNT(*) c FROM leads GROUP BY 1 ORDER BY c DESC").all()));
      const totalLeads = Math.max(1, sources.reduce((n_, r) => n_ + r.c, 0));
      const payments = (await (db.prepare(`SELECT p.amount, p.method, p.reference, p.created_at, i.ref inv FROM payments p JOIN invoices i ON i.id=p.invoice_id ORDER BY datetime(p.created_at) DESC LIMIT 5`).all()));
      const months = []; const d = new Date();
      for (let i = 5; i >= 0; i--) { const t = new Date(d.getFullYear(), d.getMonth() - i, 1); months.push(t.toISOString().slice(0, 7)); }
      const monthly = (await Promise.all(months.map(async (m) => ({ month: m,
        revenue: (await (db.prepare("SELECT COALESCE(SUM(total),0) v FROM orders WHERE substr(created_at,1,7)=? AND stage IN ('delivery','installation')").get(m))).v }))));
      return {
        aov: delivered.n ? Math.round(delivered.v / delivered.n) : 0,
        target: { value: target, pct: Math.min(100, Math.round((delivered.v / target) * 100)) },
        deals: { won, open },
        topProducts,
        sources: sources.map((r) => ({ name: r.k, count: r.c, pct: Math.round((r.c / totalLeads) * 100) })),
        payments, monthly,
      };
    })())),
    site: { pages: (await (n("SELECT COUNT(*) n FROM pages"))), published: (await (n("SELECT COUNT(*) n FROM pages WHERE status='Published'"))), sections: (await (n("SELECT COUNT(*) n FROM saved_sections"))) },
    crm: {
      clients: (await (n("SELECT COUNT(*) n FROM clients WHERE merged_into IS NULL"))),
      review: (await ((async () => { let c = 0; for (const key of ["email_norm", "phone_norm"]) c += (await (db.prepare(`SELECT COUNT(*) n FROM (SELECT ${key} k FROM clients WHERE merged_into IS NULL AND ${key} IS NOT NULL GROUP BY ${key} HAVING COUNT(*)>1)`).get())).n; return c; })())),
      tasksDue: (await (n("SELECT COUNT(*) n FROM tasks WHERE done=0 AND (due IS NULL OR due<=CAST(CURRENT_DATE AS TEXT))"))),
      hotLeads,
    },
    seededAt: (await (db.prepare("SELECT value FROM meta WHERE key='seeded_at'").get()))?.value ?? null,
  });
}));

const pageCols = db.kind === "sqlite"
  ? (await db.prepare("PRAGMA table_info(pages)").all()).map((c) => c.name)
  : (await db.prepare("SELECT column_name AS name FROM information_schema.columns WHERE table_name='pages'").all()).map((c) => c.name);
if (!pageCols.includes("theme") && db.kind === "sqlite") await db.exec("ALTER TABLE pages ADD COLUMN theme TEXT");
if (!pageCols.includes("published_snapshot")) {
  await db.exec(db.kind === "sqlite"
    ? "ALTER TABLE pages ADD COLUMN published_snapshot TEXT"
    : "ALTER TABLE pages ADD COLUMN IF NOT EXISTS published_snapshot TEXT");
}

/* ---- P7/P8 Website CMS: typed block registry, pages, publish, versions ---- */
const BLOCKS = {
  "hero": { label: "Hero", group: "Marketing", fields: [
    { k: "kicker", t: "text" }, { k: "heading", t: "text", req: true }, { k: "sub", t: "textarea" },
    { k: "image", t: "image" }, { k: "cta_label", t: "text" }, { k: "cta_href", t: "link" },
    { k: "align", t: "select", help: "Image side", options: [
      { value: "right", label: "Image right" }, { value: "left", label: "Image left" }, { value: "none", label: "No image" } ] },
    { k: "accent", t: "color", help: "Overrides the theme colour for this block only" },
    { k: "compact", t: "boolean", help: "Tighter vertical padding" } ] },
  "text-section": { label: "Text + points", group: "Content", fields: [
    { k: "kicker", t: "text" }, { k: "heading", t: "text", req: true }, { k: "body", t: "textarea" },
    { k: "bullets", t: "lines" }, { k: "image", t: "image" } ] },
  "product-grid": { label: "Product grid (live)", group: "Commerce", fields: [
    { k: "heading", t: "text" }, { k: "match", t: "text", help: "series / category / sku words" },
    { k: "ids", t: "text", help: "or exact ids, comma separated" },
    { k: "limit", t: "number", help: "how many cards (default 4)" },
    { k: "sort", t: "select", help: "Order", options: [
      { value: "featured", label: "Featured first" }, { value: "price-asc", label: "Price: low → high" },
      { value: "price-desc", label: "Price: high → low" }, { value: "name", label: "Name A → Z" } ] } ] },
  "product-feature": { label: "Product spotlight (live)", group: "Commerce", fields: [
    { k: "productId", t: "text", req: true }, { k: "heading", t: "text" }, { k: "body", t: "textarea" } ] },
  "materials": { label: "Materials & craft", group: "Commerce", fields: [
    { k: "heading", t: "text" }, { k: "sub", t: "textarea" } ] },
  "gallery": { label: "Gallery", group: "Content", fields: [
    { k: "heading", t: "text" }, { k: "images", t: "lines", req: true } ] },
  "testimonials": { label: "Testimonials", group: "Social proof", fields: [
    { k: "heading", t: "text" }, { k: "items", t: "lines", req: true, help: "quote | name | role" } ] },
  "faq": { label: "FAQ accordion", group: "Content", fields: [
    { k: "heading", t: "text" }, { k: "items", t: "lines", req: true, help: "question | answer" } ] },
  "cta-band": { label: "CTA band", group: "Marketing", fields: [
    { k: "heading", t: "text", req: true }, { k: "sub", t: "textarea" },
    { k: "primary_label", t: "text" }, { k: "primary_href", t: "text" },
    { k: "secondary_label", t: "text" }, { k: "secondary_href", t: "text" } ] },
  "global-section": { label: "Global section (fan-out)", group: "Global", fields: [
    { k: "section_id", t: "text", req: true, help: "section id — edit the source once, updates every page" } ] },
  "lead-form": { label: "Lead form → CRM", group: "Lead gen", fields: [
    { k: "heading", t: "text", req: true }, { k: "sub", t: "textarea" },
    { k: "submit_label", t: "text" }, { k: "consent", t: "textarea" } ] },
};
const rowSection = async (r) => ({ id: r.id, name: r.name, category: r.category, tags: JSON.parse(r.tags || "[]"),
  block: JSON.parse(r.block), usage: (await (sectionUsage(r.id))), createdAt: r.created_at, updatedAt: r.updated_at });
app.get("/api/saved-sections", wrap(async (req, res) => {
  const rows = (await (db.prepare("SELECT * FROM saved_sections ORDER BY datetime(updated_at) DESC").all()));
  res.json({ total: rows.length, items: (await Promise.all(rows.map(rowSection))) });
}));
app.post("/api/saved-sections", wrap(async (req, res) => {
  const { name, category, block } = req.body ?? {};
  if (!name || !block?.type || !BLOCKS[block.type]) return res.status(400).json({ error: "name + valid block required" });
  const t = now();
  const info = (await (db.prepare("INSERT INTO saved_sections(name,category,tags,block,created_at,updated_at) VALUES(?,?,?,?,?,?)")
    .run(name, category || "Custom", JSON.stringify(req.body?.tags ?? []), JSON.stringify({ type: block.type, props: block.props ?? {} }), t, t)));
  const out = (await (rowSection((await (db.prepare("SELECT * FROM saved_sections WHERE id=?").get(info.lastInsertRowid))))));
  emit("sections", { title: "Section saved: " + name, who: category || "Custom" });
  res.status(201).json(out);
}));
app.patch("/api/saved-sections/:id", wrap(async (req, res) => {
  const r = (await (db.prepare("SELECT * FROM saved_sections WHERE id=?").get(req.params.id)));
  if (!r) return res.status(404).json({ error: "not found" });
  (await (db.prepare("UPDATE saved_sections SET name=?, category=?, tags=?, block=?, updated_at=? WHERE id=?").run(
    req.body?.name ?? r.name, req.body?.category ?? r.category,
    req.body?.tags ? JSON.stringify(req.body.tags) : r.tags,
    req.body?.block ? JSON.stringify({ type: req.body.block.type, props: req.body.block.props ?? {} }) : r.block, now(), r.id)));
  emit("sections", { title: "Section updated: " + r.name, who: "fans out to " + (await (sectionUsage(r.id))) + " page(s)" });
  res.json((await (rowSection((await (db.prepare("SELECT * FROM saved_sections WHERE id=?").get(r.id)))))));
}));
app.delete("/api/saved-sections/:id", wrap(async (req, res) => {
  const r = (await (db.prepare("SELECT * FROM saved_sections WHERE id=?").get(req.params.id)));
  if (!r) return res.status(404).json({ error: "not found" });
  const usage = (await (sectionUsage(r.id)));
  if (usage > 0) return res.status(409).json({ error: `in use on ${usage} page${usage > 1 ? "s" : ""} — detach references first` });
  (await (db.prepare("DELETE FROM saved_sections WHERE id=?").run(r.id)));
  res.json({ deleted: r.id });
}));

app.get("/api/blocks", (req, res) => res.json({ registry: BLOCKS }));
const MEDIA = () => fs.readdirSync(path.join(HERE, "public/img")).filter((f) => /\.(jpg|jpeg|png|webp)$/i.test(f));
app.get("/api/media", (req, res) => res.json({ items: MEDIA().slice(0, 96) }));

const rowPage = async (r, blocks) => {
  const published = safeJson(r.published_snapshot, null);
  return {
    id: r.id, slug: r.slug, title: r.title, status: r.status,
    seoTitle: r.seo_title, seoDesc: r.seo_desc, createdAt: r.created_at, updatedAt: r.updated_at,
    publishedAt: published?.publishedAt ?? null,
    hasUnpublishedChanges: r.status === "Published" && !!published?.publishedAt && String(r.updated_at ?? "") > String(published.publishedAt),
    blocks: blocks ?? (await pageBlocks(r.id)),
    theme: safeJson(r.theme, null),
  };
};
const SLUG_RE = /^[a-z0-9][a-z0-9-]{1,60}$/;
async function validatePage(title, blocks) {
  const errs = [];
  if (!blocks.length) errs.push({ block: -1, msg: "Page has no blocks yet." });
  (await Promise.all(blocks.map(async (b, i) => {
    const spec = BLOCKS[b.type];
    if (!spec) return errs.push({ block: i, msg: `Unknown block type “${b.type}”.` });
    for (const f of spec.fields) {
      const v = b.props?.[f.k];
      if (f.req && (!v || !String(v).trim())) errs.push({ block: i, msg: `${spec.label}: “${f.k}” is required.` });
      if (f.t === "image" && v) { const sv = String(v); const okImg = sv.startsWith("/img/") || sv.startsWith("http") || sv.startsWith("data:") || /^[\w.-]+\.(jpg|jpeg|png|webp)$/i.test(sv);
        if (!okImg) errs.push({ block: i, msg: `${spec.label}: image must be a library file or URL.` }); }
    }
    if (b.type === "cta-band" && (b.props.primary_label && !b.props.primary_href)) errs.push({ block: i, msg: "CTA band: primary button needs a link." });
    if (b.type === "global-section") { const sid = Number(b.props.section_id);
      if (!sid || !(await (db.prepare("SELECT id FROM saved_sections WHERE id=?").get(sid)))) errs.push({ block: i, msg: "Global section points to a missing source (re-detach or pick a section)." }); }
  })));
  if (!title || !title.trim()) errs.push({ block: -1, msg: "Page title is required." });
  return errs;
}

const rowBlock = async (b) => { const props = typeof b.props === "string" ? safeJson(b.props, {}) : (b.props ?? {});
  if (b.type === "global-section") { const sec = (await (db.prepare("SELECT * FROM saved_sections WHERE id=?").get(Number(props.section_id))));
    if (sec) return { type: b.type, props: { ...props, sectionLabel: sec.name, section: safeJson(sec.block, {}) } }; }
  return { type: b.type, props }; };
const pageBlocks = async (pid) => (await Promise.all((await (db.prepare("SELECT type, props FROM page_blocks WHERE page_id=? ORDER BY sort").all(pid))).map(rowBlock)));
const resolveSnapshotBlocks = async (blocks) => Promise.all((blocks ?? []).map((block) => rowBlock(block)));
const sectionUsage = async (sid) => (await (db.prepare("SELECT COUNT(*) n FROM page_blocks WHERE type='global-section' AND props LIKE ?").get(`%"section_id":"${sid}"%`))).n
  + (await (db.prepare("SELECT COUNT(*) n FROM page_blocks WHERE type='global-section' AND props LIKE ?").get(`%"section_id":${sid}%`))).n;
app.get("/api/pages", wrap(async (req, res) => {
  let rows = (await (db.prepare("SELECT * FROM pages ORDER BY datetime(updated_at) DESC").all()));
  if (req.query.status) rows = rows.filter((r) => r.status === req.query.status);
  res.json({ total: rows.length, published: rows.filter((r) => r.status === "Published").length,
    items: (await Promise.all(rows.map(async (r) => ({ ...(await (rowPage(r, []))), blockCount: (await (db.prepare("SELECT COUNT(*) n FROM page_blocks WHERE page_id=?").get(r.id))).n,
      views: (await (db.prepare("SELECT COUNT(*) n FROM track_events WHERE page_id=? AND kind='view'").get(r.id))).n,
      ctas: (await (db.prepare("SELECT COUNT(*) n FROM track_events WHERE page_id=? AND kind='cta'").get(r.id))).n })))) });
}));
app.post("/api/pages", wrap(async (req, res) => {
  const { slug, title, template, blocks: seedBlocks } = req.body ?? {};
  if (!slug || !SLUG_RE.test(slug)) return res.status(400).json({ error: "slug must be lowercase letters/numbers/dashes (2-61)" });
  if ((await (db.prepare("SELECT id FROM pages WHERE slug=?").get(slug)))) return res.status(409).json({ error: "slug already exists" });
  const t = now();
  const info = (await (db.prepare("INSERT INTO pages(slug,title,status,created_at,updated_at) VALUES(?,?,?,?,?)").run(slug, title || slug, "Draft", t, t)));
  const pid = info.lastInsertRowid;
  const packs = {
    "sale-landing": [
      { type: "hero", props: { kicker: "Limited period", heading: "Workspace Sale — up to 30% off", sub: "Executive desks, ergonomic chairs and storage, ready in Lahore warehouse.", image: "desk-product.jpg", cta_label: "Shop the sale", cta_href: "/shop" } },
      { type: "product-grid", props: { heading: "Featured this week", match: "executive desk", limit: "4" } },
      { type: "cta-band", props: { heading: "Bulk order for an office floor?", sub: "Get a quotation with installation in 48 hours.", primary_label: "Request a quote", primary_href: "/quotation" } },
    ],
    "collection-intro": [
      { type: "hero", props: { kicker: "New collection", heading: "The Boardroom Series", sub: "Solid Sheesham, silent soft-close drawers, five-year frame warranty.", image: "conference-table.jpg", cta_label: "View products", cta_href: "/shop" } },
      { type: "materials", props: { heading: "Built like furniture should be", sub: "Seasoned hardwood, hand-applied finish, marine-grade hardware." } },
      { type: "faq", props: { heading: "Common questions", items: "Do you deliver nationwide? | Yes — flat-rate cargo, assembly included in Lahore and Karachi.\nIs there a warranty? | 5 years on frames, 2 years on mechanisms." } },
    ],
  };
  /* Explicit blocks win over a template. Silently dropping what the caller sent
     made create look successful while the page came back empty — callers then
     published a blank page. Validate against the catalogue for the same reason
     the PUT route does. */
  if (seedBlocks !== undefined) {
    if (!Array.isArray(seedBlocks)) return res.status(400).json({ error: "blocks must be an array" });
    if (seedBlocks.length > 100) return res.status(400).json({ error: "a page may contain at most 100 blocks" });
    const unknownSeed = seedBlocks.find((b) => !b || typeof b !== "object" || !BLOCKS[b.type]);
    if (unknownSeed) return res.status(400).json({ error: `unknown block type "${unknownSeed?.type}" — see GET /api/blocks` });
  }
  const seed = seedBlocks ?? packs[template] ?? [];
  for (const [i, b] of seed.entries())
    await db.prepare("INSERT INTO page_blocks(page_id,type,props,sort) VALUES(?,?,?,?)").run(pid, b.type, JSON.stringify(b.props ?? {}), i);
  const r = (await (db.prepare("SELECT * FROM pages WHERE id=?").get(pid)));
  emit("pages", { title: "Page created: /" + r.slug, who: r.title });
  res.status(201).json((await (rowPage(r))));
}));
app.get("/api/pages/:key", wrap(async (req, res) => {
  const r = (await (db.prepare("SELECT * FROM pages WHERE id=? OR slug=?").get(req.params.key, req.params.key)));
  if (!r) return res.status(404).json({ error: "page not found" });
  res.json((await (rowPage(r))));
}));
const PREVIEW_SECRET = crypto.randomBytes(32).toString("hex"); // restart rotates → old links die with it
const previewSig = (slug, exp) => crypto.createHmac("sha256", PREVIEW_SECRET).update(slug + "." + exp).digest("base64url").slice(0, 40);
const previewSigOk = (slug, exp, sig) => {
  if (!sig || !(Number(exp) > Date.now())) return false;
  const a = Buffer.from(previewSig(slug, exp)), b = Buffer.from(String(sig).slice(0, 64));
  return a.length === b.length && crypto.timingSafeEqual(a, b);
};
const bearerValid = async (req) => {
  const t = requestToken(req);
  if (!t) return false;
  const u = (await (db.prepare("SELECT u.active, s.expires_at FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=?").get(t)));
  return !!u && !!u.active && String(u.expires_at) >= now();
};
app.post("/api/pages/:id/preview-link", wrap(async (req, res) => {
  const r = (await (db.prepare("SELECT * FROM pages WHERE id=? OR slug=?").get(req.params.id, req.params.id)));
  if (!r) return res.status(404).json({ error: "page not found" });
  const exp = Date.now() + 15 * 60e3; // 15-minute preview window (TODO-1)
  res.json({ url: "/p/" + r.slug + "?draft=1&exp=" + exp + "&sig=" + previewSig(r.slug, exp), expiresAt: new Date(exp).toISOString() });
}));
app.get("/api/pages/:key/public", wrap(async (req, res) => {
  const r = (await (db.prepare("SELECT * FROM pages WHERE slug=?").get(req.params.key)));
  if (!r) return res.status(404).json({ error: "no page at that address" });
  const wantDraft = req.query.draft === "1";
  if (wantDraft) {
    const authorized = previewSigOk(r.slug, req.query.exp, req.query.sig) || (await (bearerValid(req)));
    if (!authorized) return res.status(404).json({ error: "draft preview is not available" });
  }

  let snapshot;
  if (wantDraft) {
    snapshot = {
      slug: r.slug, title: r.title, seoTitle: r.seo_title, seoDesc: r.seo_desc,
      theme: safeJson(r.theme, null), publishedAt: r.updated_at, blocks: await pageBlocks(r.id),
    };
  } else {
    if (r.status !== "Published") return res.status(404).json({ error: "page is not published" });
    snapshot = safeJson(r.published_snapshot, null);
    if (!snapshot || !Array.isArray(snapshot.blocks))
      return res.status(404).json({ error: "page has no published snapshot" });
  }
  res.json({ slug: snapshot.slug ?? r.slug, title: snapshot.title ?? r.title, status: r.status,
    seoTitle: Object.prototype.hasOwnProperty.call(snapshot, "seoTitle") ? snapshot.seoTitle : (snapshot.seo?.title ?? r.seo_title),
    seoDesc: Object.prototype.hasOwnProperty.call(snapshot, "seoDesc") ? snapshot.seoDesc : (snapshot.seo?.desc ?? r.seo_desc),
    publishedAt: snapshot.publishedAt ?? r.updated_at,
    preview: wantDraft,
    blocks: await resolveSnapshotBlocks(snapshot.blocks),
    theme: Object.prototype.hasOwnProperty.call(snapshot, "theme") ? snapshot.theme : safeJson(r.theme, null) });
}));
app.put("/api/pages/:id/blocks", wrapTxJson(async (req, afterCommit) => {
  const r = (await (db.prepare("SELECT * FROM pages WHERE id=?").get(req.params.id)));
  if (!r) return { status: 404, body: { error: "not found" } };
  const body = req.body ?? {};
  const has = (key) => Object.prototype.hasOwnProperty.call(body, key);
  const rewrite = Array.isArray(body.blocks);
  if (has("blocks") && !rewrite) return { status: 400, body: { error: "blocks must be an array" } };
  if (rewrite && body.blocks.length > 100)
    return { status: 400, body: { error: "a page may contain at most 100 blocks" } };
  const invalidBlocks = rewrite && body.blocks.some((b) =>
    !b || typeof b !== "object" || Array.isArray(b) || typeof b.type !== "string" || !b.type.trim()
    || (b.props != null && (typeof b.props !== "object" || Array.isArray(b.props))));
  if (invalidBlocks) return { status: 400, body: { error: "blocks must contain typed blocks with object props" } };
  /* Shape is not enough: a block whose type is not in the server catalogue has
     no renderer, so it publishes as an invisible gap on a live page. Reject it
     at the door and name the offending type. */
  const unknown = rewrite && body.blocks.find((b) => !BLOCKS[b.type]);
  if (unknown)
    return { status: 400, body: { error: `unknown block type "${unknown.type}" — see GET /api/blocks`, known: Object.keys(BLOCKS) } };

  const draftBlocks = rewrite ? body.blocks.map((b) => ({ type: b.type, props: b.props ?? {} }))
    : (await (db.prepare("SELECT type, props FROM page_blocks WHERE page_id=? ORDER BY sort").all(r.id))).map((b) => ({ type: b.type, props: safeJson(b.props, {}) }));
  if (rewrite) {
    await db.prepare("DELETE FROM page_blocks WHERE page_id=?").run(r.id);
    for (const [i, b] of draftBlocks.entries())
      await db.prepare("INSERT INTO page_blocks(page_id,type,props,sort) VALUES(?,?,?,?)").run(r.id, b.type, JSON.stringify(b.props), i);
  }

  const assignments = ["updated_at=?"];
  const values = [now()];
  const setField = (key, column, value) => { if (has(key)) { assignments.push(`${column}=?`); values.push(value); } };
  setField("title", "title", body.title == null ? "" : String(body.title).trim().slice(0, 200));
  setField("seoTitle", "seo_title", body.seoTitle == null ? null : String(body.seoTitle).slice(0, 180));
  setField("seoDesc", "seo_desc", body.seoDesc == null ? null : String(body.seoDesc).slice(0, 500));
  setField("theme", "theme", body.theme == null ? null : JSON.stringify(body.theme));
  values.push(r.id);
  await db.prepare(`UPDATE pages SET ${assignments.join(", ")} WHERE id=?`).run(...values);

  const nextTitle = has("title") ? (body.title == null ? "" : String(body.title).trim()) : r.title;
  const validation = await validatePage(nextTitle, draftBlocks);
  const updated = await db.prepare("SELECT * FROM pages WHERE id=?").get(r.id);
  return { ...(await rowPage(updated)), validation };
}));
app.post("/api/pages/:id/publish", wrapTxResponse(async (req, afterCommit) => {
  const r = await db.prepare("SELECT * FROM pages WHERE id=?").get(req.params.id);
  if (!r) return { status: 404, body: { error: "not found" } };
  if (req.body?.title != null && String(req.body.title).trim() !== String(r.title).trim())
    return { status: 409, body: { error: "save the latest draft before publishing" } };

  const blocks = (await db.prepare("SELECT type, props FROM page_blocks WHERE page_id=? ORDER BY sort").all(r.id))
    .map((b) => ({ type: b.type, props: safeJson(b.props, {}) }));
  const errs = await validatePage(r.title, blocks);
  if (errs.length) return { status: 422, body: { error: "validation failed", validation: errs } };

  const t = now();
  const snapshot = {
    slug: r.slug, title: r.title, seoTitle: r.seo_title, seoDesc: r.seo_desc,
    theme: safeJson(r.theme, null), blocks, publishedAt: t,
  };
  await db.prepare("INSERT INTO page_versions(page_id,snapshot,note,who,created_at) VALUES(?,?,?,?,?)")
    .run(r.id, JSON.stringify(snapshot), String(req.body?.note ?? "published").slice(0, 200), req.actor ?? "You", t);
  await db.prepare("UPDATE pages SET status='Published', published_snapshot=?, updated_at=? WHERE id=?")
    .run(JSON.stringify(snapshot), t, r.id);
  const updated = await db.prepare("SELECT * FROM pages WHERE id=?").get(r.id);
  const versions = (await db.prepare("SELECT COUNT(*) n FROM page_versions WHERE page_id=?").get(r.id)).n;
  afterCommit.push(() => emit("pages", { title: "Page published: /" + r.slug, who: r.title }));
  return { status: 200, body: { ...(await rowPage(updated)), versions } };
}));
app.post("/api/pages/:id/unpublish", wrap(async (req, res) => {
  const r = (await (db.prepare("SELECT * FROM pages WHERE id=?").get(req.params.id)));
  if (!r) return res.status(404).json({ error: "not found" });
  (await (db.prepare("UPDATE pages SET status='Draft', updated_at=? WHERE id=?").run(now(), r.id)));
  emit("pages", { title: "Page unpublished: /" + r.slug, who: r.title });
  res.json((await (rowPage((await (db.prepare("SELECT * FROM pages WHERE id=?").get(r.id)))))));
}));
app.delete("/api/pages/:id", wrapTxJson(async (req, afterCommit) => {
  const r = (await (db.prepare("SELECT * FROM pages WHERE id=?").get(req.params.id)));
  if (!r) return { status: 404, body: { error: "not found" } };
  /* page_blocks / page_versions have no FK cascade, so orphaned rows would
     survive the delete and every later integrity sweep would flag them. */
  const blocks = (await (db.prepare("SELECT COUNT(*) AS n FROM page_blocks WHERE page_id=?").get(r.id))).n;
  const versions = (await (db.prepare("SELECT COUNT(*) AS n FROM page_versions WHERE page_id=?").get(r.id))).n;
  await db.prepare("DELETE FROM page_blocks WHERE page_id=?").run(r.id);
  await db.prepare("DELETE FROM page_versions WHERE page_id=?").run(r.id);
  await db.prepare("DELETE FROM pages WHERE id=?").run(r.id);
  afterCommit.push(() => emit("pages", { title: "Page deleted: /" + r.slug, who: r.title }));
  return { body: { deleted: true, slug: r.slug, blocks, versions } };
}));
app.get("/api/pages/:id/versions", wrap(async (req, res) => {
  const rows = (await (db.prepare("SELECT id, note, who, created_at, snapshot FROM page_versions WHERE page_id=? ORDER BY id DESC").all(req.params.id)));
  res.json({ items: rows.map((v) => ({ id: v.id, note: v.note, who: v.who, createdAt: v.created_at,
    blocks: JSON.parse(v.snapshot).blocks.length })) });
}));
app.post("/api/pages/:id/rollback", wrapTxJson(async (req, afterCommit) => {
  const page = await db.prepare("SELECT * FROM pages WHERE id=?").get(req.params.id);
  if (!page) return { status: 404, body: { error: "page not found" } };
  const vid = Number(req.body?.version_id);
  if (!Number.isSafeInteger(vid) || vid < 1) return { status: 400, body: { error: "version_id required" } };
  const version = await db.prepare("SELECT * FROM page_versions WHERE id=? AND page_id=?").get(vid, page.id);
  if (!version) return { status: 404, body: { error: "version not found" } };
  const snapshot = safeJson(version.snapshot, null);
  if (!snapshot || !Array.isArray(snapshot.blocks)) return { status: 422, body: { error: "version snapshot is invalid" } };
  if (snapshot.blocks.length > 100 || snapshot.blocks.some((block) =>
    !block?.type || (block.props != null && (typeof block.props !== "object" || Array.isArray(block.props)))))
    return { status: 422, body: { error: "version snapshot contains invalid blocks" } };

  await db.prepare("DELETE FROM page_blocks WHERE page_id=?").run(page.id);
  for (const [i, block] of snapshot.blocks.entries()) {
    await db.prepare("INSERT INTO page_blocks(page_id,type,props,sort) VALUES(?,?,?,?)")
      .run(page.id, String(block.type), JSON.stringify(block.props ?? {}), i);
  }
  const legacySeo = snapshot.seo ?? {};
  const title = Object.prototype.hasOwnProperty.call(snapshot, "title") ? snapshot.title : page.title;
  const seoTitle = Object.prototype.hasOwnProperty.call(snapshot, "seoTitle") ? snapshot.seoTitle : (legacySeo.title ?? page.seo_title);
  const seoDesc = Object.prototype.hasOwnProperty.call(snapshot, "seoDesc") ? snapshot.seoDesc : (legacySeo.desc ?? page.seo_desc);
  const theme = Object.prototype.hasOwnProperty.call(snapshot, "theme") ? snapshot.theme : safeJson(page.theme, null);
  await db.prepare("UPDATE pages SET title=?,seo_title=?,seo_desc=?,theme=?,updated_at=? WHERE id=?")
    .run(title, seoTitle, seoDesc, theme == null ? null : JSON.stringify(theme), now(), page.id);
  const updated = await db.prepare("SELECT * FROM pages WHERE id=?").get(page.id);
  emit("pages", { title: "Draft restored from version " + vid + " on /" + page.slug, who: req.actor ?? "You" });
  return await rowPage(updated);
}));

async function backfillPublishedSnapshots() {
  const pages = await db.prepare("SELECT * FROM pages WHERE status='Published' AND (published_snapshot IS NULL OR published_snapshot='')").all();
  if (!pages.length) return;
  await db.transaction(async () => {
    for (const page of pages) {
      const blocks = (await db.prepare("SELECT type, props FROM page_blocks WHERE page_id=? ORDER BY sort").all(page.id))
        .map((b) => ({ type: b.type, props: safeJson(b.props, {}) }));
      const snapshot = {
        slug: page.slug, title: page.title, seoTitle: page.seo_title, seoDesc: page.seo_desc,
        theme: safeJson(page.theme, null), blocks, publishedAt: page.updated_at,
      };
      await db.prepare("UPDATE pages SET published_snapshot=? WHERE id=?").run(JSON.stringify(snapshot), page.id);
    }
  });
  console.log(`[woodex-api] captured current public baseline for ${pages.length} published CMS page(s)`);
}

async function ensureSiteDemo() {
  if ((await (db.prepare("SELECT COUNT(*) n FROM pages").get())).n > 0) return;
  const t = now();
  const info = (await (db.prepare("INSERT INTO pages(slug,title,status,seo_title,seo_desc,created_at,updated_at) VALUES(?,?,?,?,?,?,?)")
    .run("ramadan-workspace-sale", "Ramadan Workspace Sale", "Published",
      "Ramadan Workspace Sale — Woodex Furniture", "Up to 30% off desks & ergonomic chairs. Quotation + installation in 48h.", t, t)));
  const pid = info.lastInsertRowid;
  const demo = [
    { type: "hero", props: { kicker: "Ramadan offer · Lahore & nationwide", heading: "Your workspace, upgraded for PKR under 150,000", sub: "Solid-wood desks, ergonomic chairs and silent soft-close storage — warehouse stock, delivered and installed before Eid.", image: "office-desk-setup.jpg", cta_label: "Request a quotation", cta_href: "/quotation", cta2: "Browse the catalog" } },
    { type: "product-grid", props: { heading: "On offer this week", match: "desk chair executive ergonomic", limit: "4" } },
    { type: "text-section", props: { kicker: "How it works", heading: "Ordered today, working by next week", body: "We quote from live warehouse stock, build to spec where needed in our Johar Town workshop, and install with a customer sign-off checklist.", bullets: "48h quotation with real stock, not guesses\n50% advance unlocks production\nDelivery + assembly included in Lahore" } },
    { type: "testimonials", props: { heading: "What clients say", items: "They outfitted our entire 30-seat office in nine days.\nAhmed K. · DHA\nIn the budget, on time, no scratches on the walls.\nSana T. · Clifton\nThe chair is still perfect after two years.\nBilal Traders" } },
    { type: "faq", props: { heading: "Before you ask", items: "Is the offer on custom sizes too? | Custom orders get 10% off materials; sizes are free.\nCan I pay in installments? | 50% advance, rest on delivery — cards accepted at showroom.\nDo you deliver outside Lahore? | Nationwide cargo, assembly guidance by video call." } },
    { type: "lead-form", props: { heading: "Reserve your offer slot", sub: "Share your requirement — we confirm stock and install date within one working day.", submit_label: "Send to sales", consent: "By sending you agree to be contacted on WhatsApp about this offer." } },
    { type: "cta-band", props: { heading: "Need 10+ desks?", sub: "Bulk floor plans get dedicated pricing and staged delivery.", primary_label: "Talk to B2B desk", primary_href: "/b2b" } },
  ];
  for (const [i, b] of demo.entries()) (await (db.prepare("INSERT INTO page_blocks(page_id,type,props,sort) VALUES(?,?,?,?)").run(pid, b.type, JSON.stringify(b.props), i)));
  const si = (await (db.prepare("INSERT INTO saved_sections(name,category,tags,block,created_at,updated_at) VALUES(?,?,?,?,?,?)")
    .run("Trust strip", "Social proof", JSON.stringify(["woodex", "reusable"]),
      JSON.stringify({ type: "text-section", props: { kicker: "Why Woodex", heading: "12 years · 4,100+ rooms · 5-year frame warranty", body: "Own Johar Town workshop, seasoned Sheesham & Grade-A hardware, delivery with customer sign-off checklist.", bullets: "4.9 average rating across 860 reviews\nFactory-direct pricing, no showroom loading\nFree 3D preview for bulk orders" } }), t, t)));
  const globalRef = { type: "global-section", props: { section_id: String(si.lastInsertRowid) } };
  (await (db.prepare("INSERT INTO page_blocks(page_id,type,props,sort) VALUES(?,?,?,?)").run(pid, globalRef.type, JSON.stringify(globalRef.props), 1)));
  const shifted = (await (db.prepare("SELECT id, sort FROM page_blocks WHERE page_id=? AND sort>=1 ORDER BY sort DESC").all(pid)));
  for (const row of shifted) (await (db.prepare("UPDATE page_blocks SET sort=sort+1 WHERE id=?").run(row.id)));
  (await (db.prepare("INSERT INTO page_versions(page_id,snapshot,note,who,created_at) VALUES(?,?,?,?,?)").run(pid, JSON.stringify({ blocks: demo }), "seeded", "System", t)));
  console.log("[seed] site demo: 1 published landing page (7 blocks)");
}

/* Shared imagery */
app.use("/img", express.static(path.join(HERE, "public/img"), { maxAge: "1h" }));

/* Any unmatched API path answers in JSON, so a typo in the client is a clear `404`
   instead of Express's HTML page (which also told callers this is Express). */
app.use("/api", (req, res) => res.status(404).json({ error: "not found", path: req.path }));

/* JSON error boundary — body-parser, static and any other sync middleware throw
   before `wrap()` can see them. Without this, a malformed request body returned
   Express's HTML page complete with an absolute-path stack trace. */
app.use((error, req, res, next) => {
  if (res.headersSent) return next(error);
  const status = Number(error?.status ?? error?.statusCode) || 500;
  const clientError = status >= 400 && status < 500;
  if (!clientError) console.error("[woodex-api]", error?.stack ?? error);
  const message = clientError
    ? (status === 400 && error?.type === "entity.parse.failed" ? "request body is not valid JSON" : String(error?.message ?? "request rejected"))
    : "internal error";
  res.status(status).json({ error: message });
});

const PORT = process.env.PORT ? Number(process.env.PORT) : 3001;

async function ensureServerlessCatalog() {
  const { products, materials, services } = await loadShopData();
  await db.transaction(async () => {
    const t = now();
    for (const product of products) {
      const stock = product.inStock ? 99 : 0;
      await db.prepare("INSERT OR IGNORE INTO products(id,json,price,in_stock,stock_qty,updated_at) VALUES(?,?,?,?,?,?)")
        .run(product.id, JSON.stringify(product), product.price, product.inStock ? 1 : 0, stock, t);
    }
    for (const material of materials) {
      await db.prepare("INSERT OR IGNORE INTO materials(id,json) VALUES(?,?)").run(material.id, JSON.stringify(material));
    }
    for (const [index, service] of services.entries()) {
      await db.prepare("INSERT OR IGNORE INTO services(id,json) VALUES(?,?)").run(String(index + 1), JSON.stringify(service));
    }
  });
}

async function bootstrapProductionOwner() {
  const users = Number((await db.prepare("SELECT COUNT(*) n FROM users").get()).n);
  if (users > 0) {
    const owner = await db.prepare("SELECT id FROM users WHERE role='owner' AND active=1 LIMIT 1").get();
    if (!owner) throw new Error("Production database has users but no active owner; provision an owner before deployment.");
    return;
  }

  const email = String(process.env.INITIAL_ADMIN_EMAIL ?? "").trim().toLowerCase();
  const password = String(process.env.INITIAL_ADMIN_PASSWORD ?? "");
  if (!email || !password) throw new Error("No production owner exists. Set INITIAL_ADMIN_EMAIL and INITIAL_ADMIN_PASSWORD once, or provision an owner before deployment.");
  if (!/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(email)) throw new Error("INITIAL_ADMIN_EMAIL must be a valid email address.");
  if (password.length < 16) throw new Error("INITIAL_ADMIN_PASSWORD must be at least 16 characters.");

  const { salt, hash } = hashPass(password);
  const t = now();
  await db.prepare("INSERT OR IGNORE INTO users(email,name,role,pass_salt,pass_hash,active,created_at,updated_at) VALUES(?,?,?,?,?,1,?,?)")
    .run(email, "Woodex Owner", "owner", salt, hash, t, t);
  const owner = await db.prepare("SELECT id FROM users WHERE role='owner' AND active=1 LIMIT 1").get();
  if (!owner) throw new Error("Production owner bootstrap did not create an active owner.");
  console.log("[woodex-api] initial production owner provisioned; remove bootstrap secrets from Vercel after verification.");
}

let httpServer = null;
const allowDemoSeed = !IS_SERVERLESS && !IS_PRODUCTION
  && (db.kind === "sqlite" || process.env.WOODEX_DEMO_SEED === "1");
if (allowDemoSeed) {
  await seed();
  await ensureQuoteVersionBaselines();
  /* P10 demo threads — boot-guarded, local/demo only */
  try {
    if ((await db.prepare("SELECT COUNT(*) n FROM conversations").get()).n === 0) {
      const seeds = [
        { n: -5, msgs: [["inbound", "whatsapp", "Hassan", "Salam, do you have the L-shaped desk in walnut? Need 4 for a new office by Eid."], ["outbound", "whatsapp", "Usman", "Walaikum salam! Yes — 6 in stock at Gulberg. I'll send prices + 3D layout options today."], ["inbound", "whatsapp", "Hassan", "Great, also do you install in DHA?"]] },
        { n: -2, msgs: [["inbound", "email", "Bilal Traders", "Following up on quote Q-1042 — can we move to 40% advance?"], ["outbound", "note", "Ayesha", "Owner asked for payment plan — approved 40/60 per finance, updating quote."]] },
        { n: -1, msgs: [["inbound", "phone", "Sana", "Call: wants 3 executive tables delivered before the 20th, meeting room chairs too."], ["outbound", "whatsapp", "Usman", "Confirmed stock + slot on 19th. Sending delivery address form shortly."]] },
      ];
      for (const [i, sp] of seeds.entries()) {
        const cl = await db.prepare("SELECT id FROM clients WHERE merged_into IS NULL ORDER BY id LIMIT 1 OFFSET ?").get(i);
        if (!cl) break;
        const t = new Date(Date.now() + sp.n * 864e5).toISOString();
        const info = await db.prepare("INSERT INTO conversations(client_id,status,assignee,unread,last_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?)")
          .run(cl.id, "open", i === 1 ? "Ayesha" : "Usman", i === 2 ? 1 : 0, t, t, t);
        let k = 0;
        for (const [dir, ch, au, body] of sp.msgs) {
          k += 19 * 60 * 1000;
          await db.prepare("INSERT INTO messages(conversation_id,channel,direction,author,body,created_at) VALUES(?,?,?,?,?,?)")
            .run(info.lastInsertRowid, ch, dir, au, body, new Date(Date.parse(t) + k).toISOString());
        }
      }
      console.log("[woodex-api] local P10 inbox demo threads seeded");
    }
  } catch (e) { console.error("[woodex-api] local inbox seed skipped:", e.message); }
  /* Demo owner/team accounts are strictly local; never created in a Vercel runtime. */
  try {
    if (!(await db.prepare("SELECT id FROM users WHERE email='admin@woodex.pk'").get())) {
      const legacy = await db.prepare("SELECT id FROM users WHERE email='usman@woodex.pk'").get();
      if (legacy) {
        const { salt, hash } = hashPass("admin");
        await db.prepare("UPDATE users SET email='admin@woodex.pk', name='Admin', role='owner', pass_salt=?, pass_hash=?, active=1, updated_at=? WHERE id=?")
          .run(salt, hash, now(), legacy.id);
        await db.prepare("DELETE FROM sessions WHERE user_id=?").run(legacy.id);
      } else {
        const { salt, hash } = hashPass("admin");
        await db.prepare("INSERT INTO users(email,name,role,pass_salt,pass_hash,created_at,updated_at) VALUES(?,?,?,?,?,?,?)")
          .run("admin@woodex.pk", "Admin", "owner", salt, hash, now(), now());
      }
      console.log("[woodex-api] local demo login created (never use these credentials in production).");
    }
  } catch (e) { console.error("[woodex-api] local demo owner seed skipped:", e.message); }
  try {
    const t = now();
    const team = [
      ["ayesha@woodex.pk", "Ayesha (Content)", "editor"],
      ["bilal@woodex.pk", "Bilal (Sales)", "sales"],
      ["farhan@woodex.pk", "Farhan (Finance)", "finance"],
      ["guest@woodex.pk", "Guest (View only)", "viewer"],
    ];
    for (const [email, name, role] of team) {
      if (await db.prepare("SELECT id FROM users WHERE email=?").get(email)) continue;
      const { salt, hash } = hashPass("woodex123");
      await db.prepare("INSERT INTO users(email,name,role,pass_salt,pass_hash,created_at,updated_at) VALUES(?,?,?,?,?,?,?)")
        .run(email, name, role, salt, hash, t, t);
    }
    console.log("[woodex-api] local demo team ensured (never use these credentials in production).");
  } catch (e) { console.error("[woodex-api] local demo team seed skipped:", e.message); }
  await ensureFinanceDemo();
  await ensureCrmDemo();
  await ensureSiteDemo();
  await backfillPublishedSnapshots();
} else if (IS_PRODUCTION) {
  await bootstrapProductionOwner();
}

if (IS_SERVERLESS) {
  if (db.kind !== "postgres") throw new Error("Vercel requires a persistent PostgreSQL DATABASE_URL; SQLite/PGlite are unsupported.");
  // Fail during initialization rather than accepting traffic against an uninitialized database.
  await db.prepare("SELECT 1 FROM meta LIMIT 1").get();
  // Only repository-owned public catalog content is bootstrapped; customer/demo records are never seeded on Vercel.
  await ensureServerlessCatalog();
} else {
  httpServer = app.listen(PORT, "0.0.0.0", () => console.log(`[woodex-api] http://localhost:${httpServer.address()?.port ?? PORT} · driver=${db.kind}`));
}
export { app, db, httpServer };

/**
 * WOODEX platform API — single runtime source of truth for storefront + dashboard.
 * Express + node:sqlite (no external DB). Persists to <repo>/data/woodex.db.
 *
 *   GET  /api/health                 status + row counts
 *   GET  /api/products[?q&sub]       full catalog (price/stock edits merged)
 *   GET  /api/products/overrides     changed rows only (storefront polling)
 *   GET  /api/products/:id           one product
 *   PATCH/api/products/:id           {price?, inStock?, stockQty?}  → both apps see it live
 *   GET  /api/materials /api/services
 *   GET/POST/PATCH /api/quotes       storefront quote requests + dashboard pipeline
 *   GET/POST/PATCH /api/leads        contact form → CRM kanban
 *   GET/POST/PATCH /api/orders       checkout → operations board
 *   GET  /api/invoices[?status=open|...] · GET/PATCH /api/invoices/:id · POST /api/invoices (from quote_id/order_id or raw)
 *   POST /api/invoices/:id/payments  · GET/POST /api/returns · PATCH /api/returns/:id
 *   GET  /api/orders/lookup?ref=     public order tracking for storefront
 *   P4 CRM: GET/POST/PATCH /api/clients · /api/clients/review · /api/clients/:id/merge
 *           GET/POST/PATCH /api/tasks · leads now carry score + scoreWhy
 *   GET  /api/stats                  KPI + activity rollup for the dashboard
 *   GET  /img/:file                  shared product photography
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { DatabaseSync } from "node:sqlite";
import express from "express";
import { loadShopData, ROOT } from "./shop-data.mjs";

const HERE = path.dirname(fileURLToPath(import.meta.url));
const DB_DIR = path.join(ROOT, "data");
fs.mkdirSync(DB_DIR, { recursive: true });
const db = new DatabaseSync(path.join(DB_DIR, "woodex.db"));

const now = () => new Date().toISOString();
const fmtMoney = (n) => "PKR " + Math.round(n).toLocaleString("en-PK");

/* ---------------- schema ---------------- */
db.exec(`
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
  note TEXT, created_at TEXT, updated_at TEXT
);
CREATE TABLE IF NOT EXISTS quotes(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT, customer TEXT NOT NULL, contact TEXT,
  items TEXT NOT NULL, total REAL NOT NULL DEFAULT 0, status TEXT DEFAULT 'Draft',
  source TEXT DEFAULT 'storefront', note TEXT, audit TEXT DEFAULT '[]',
  created_at TEXT, updated_at TEXT
);
CREATE TABLE IF NOT EXISTS orders(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT, customer TEXT NOT NULL, items TEXT NOT NULL,
  total REAL NOT NULL DEFAULT 0, status TEXT DEFAULT 'In Progress', stage TEXT DEFAULT 'production',
  owner TEXT DEFAULT 'Faisal', due TEXT, created_at TEXT, updated_at TEXT
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
  id INTEGER PRIMARY KEY AUTOINCREMENT, client_id INTEGER, lead_id INTEGER, quote_id INTEGER, order_id INTEGER,
  title TEXT NOT NULL, due TEXT, owner TEXT DEFAULT 'You', priority TEXT DEFAULT 'normal', done INTEGER DEFAULT 0,
  created_at TEXT, updated_at TEXT);
CREATE TABLE IF NOT EXISTS pages(
  id INTEGER PRIMARY KEY AUTOINCREMENT, slug TEXT UNIQUE NOT NULL, title TEXT NOT NULL,
  status TEXT DEFAULT 'Draft', seo_title TEXT, seo_desc TEXT, created_at TEXT, updated_at TEXT);
CREATE TABLE IF NOT EXISTS page_blocks(
  id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER NOT NULL, type TEXT NOT NULL,
  props TEXT NOT NULL DEFAULT '{}', sort INTEGER NOT NULL DEFAULT 0);
CREATE TABLE IF NOT EXISTS page_versions(
  id INTEGER PRIMARY KEY AUTOINCREMENT, page_id INTEGER NOT NULL, snapshot TEXT NOT NULL,
  note TEXT, who TEXT DEFAULT 'You', created_at TEXT);
CREATE TABLE IF NOT EXISTS saved_sections(
  id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, category TEXT DEFAULT 'Custom',
  tags TEXT DEFAULT '[]', block TEXT NOT NULL, created_at TEXT, updated_at TEXT);
CREATE TABLE IF NOT EXISTS meta(key TEXT PRIMARY KEY, value TEXT);
PRAGMA journal_mode = WAL;
`);

/* ---------------- seed ---------------- */
async function seed() {
  const count = db.prepare("SELECT COUNT(*) AS n FROM products").get().n;
  if (count > 0) return;
  console.log("[seed] importing storefront catalog into SQLite…");
  const { products, materials, services } = await loadShopData();
  const ins = db.prepare(
    "INSERT INTO products(id,json,price,in_stock,stock_qty,updated_at) VALUES(?,?,?,?,?,?)"
  );
  for (const p of products) {
    const stock = p.inStock ? 99 : 0;
    ins.run(p.id, JSON.stringify(p), p.price, p.inStock ? 1 : 0, stock, now());
  }
  const insMat = db.prepare("INSERT INTO materials(id,json) VALUES(?,?)");
  materials.forEach((m) => insMat.run(m.id, JSON.stringify(m)));
  const insSvc = db.prepare("INSERT INTO services(id,json) VALUES(?,?)");
  services.forEach((s, i) => insSvc.run(String(i + 1), JSON.stringify(s)));

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
  seedLeads.forEach(([name, source, interest, status, owner, note], i) => {
    const t = new Date(Date.now() - (i + 1) * 36e5 * 7).toISOString();
    insLead.run("L-" + (1042 - i * 3), name, interest, source, status, owner, note, t, t);
  });

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
  seedQuotes.forEach(([ref, customer, status, total, source], i) => {
    const t = new Date(Date.now() - (i + 1) * 36e5 * 11).toISOString();
    const items = JSON.stringify([
      { name: first?.name ?? "Executive desk", price: Math.round(total / 24), qty: i + 3 },
    ]);
    insQ.run(ref, customer, items, total, status, source,
      JSON.stringify([{ who: "System", action: "seeded from " + source, time: t }]), t, t);
  });

  const stages = ["production", "qc", "dispatch", "delivery", "installation"];
  const insO = db.prepare(
    "INSERT INTO orders(ref,customer,items,total,status,stage,owner,due,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)"
  );
  ["Clifton Villa 9", "Gulberg Residency", "Tariq Hotels", "Zeeshan Ahmed", "Zen Dental", "Bahria Block C", "Nadia Clock Tower", "Maple Cafe"].forEach((customer, i) => {
    const stage = stages[i % 5];
    const status = i % 4 === 3 ? "Delayed" : i % 3 === 0 ? "In Progress" : "On Track";
    const p = products[(i * 7) % products.length];
    const t = now();
    insO.run("WX-" + (4210 + i), customer,
      JSON.stringify([{ name: p?.name, price: p?.price ?? 50000, qty: (i % 4) + 1 }]),
      (p?.price ?? 50000) * ((i % 4) + 1), status, stage,
      ["Faisal", "Hira", "Logistics", "Kamran"][i % 4],
      ["Oct 08", "Oct 12", "Oct 15", "Oct 18"][i % 4], t, t);
  });
  ensureFinanceDemo();
  db.prepare("INSERT INTO meta(key,value) VALUES('seeded_at',?)").run(now());
  console.log(`[seed] ${products.length} products, ${materials.length} materials, ${services.length} services, ${seedLeads.length} leads, ${seedQuotes.length} quotes, 8 orders`);
}

function ensureFinanceDemo() {
  if (db.prepare("SELECT COUNT(*) n FROM invoices").get().n > 0) return;
  const orders = db.prepare("SELECT * FROM orders ORDER BY id LIMIT 2").all();
  const t = now();
  orders.forEach((o, i) => {
    const items = JSON.parse(o.items);
    const subtotal = items.reduce((n, x) => n + (x.price || 0) * (x.qty || 1), 0);
    const total = Math.round(subtotal * 1.05);
    const info = db.prepare(`INSERT INTO invoices(ref,order_id,customer,items,subtotal,total,status,due,created_at,updated_at)
      VALUES(?,?,?,?,?,?,?,?,?,?)`).run("INV-26-" + (1001 + i), o.id, o.customer, o.items, subtotal, total,
      i === 0 ? "Issued" : "Partially Paid", new Date(Date.now() + 864e5 * 14).toISOString().slice(0, 10), t, t);
    if (i === 1) { const paid = Math.round(total * 0.5);
      db.prepare("INSERT INTO payments(invoice_id,amount,method,reference,created_at) VALUES(?,?,?,?,?)")
        .run(info.lastInsertRowid, paid, "50% advance — Bank transfer", "TRX-88231", t);
      db.prepare("UPDATE invoices SET paid=? WHERE id=?").run(paid, info.lastInsertRowid); }
  });
  db.prepare("INSERT INTO returns(ref,order_id,customer,item,reason,state,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)")
    .run("RMA-0201", orders[1]?.id ?? null, orders[1]?.customer ?? "Walk-in", "Fabric swatch mismatch", "Color differs from configurator preview", "Requested", t, t);
  console.log("[seed] finance demo: 2 invoices, 1 payment, 1 return");
}

function ensureCrmDemo() {
  if (db.prepare("SELECT COUNT(*) n FROM clients").get().n > 0) return;
  const t = now();
  const seen = new Map();
  const upsert = (name, extra = {}, entity = null, entityId = null) => {
    if (!name) return;
    const key = String(name).toLowerCase().trim();
    let c = seen.get(key) ?? db.prepare("SELECT * FROM clients WHERE lower(name)=? AND merged_into IS NULL").get(key);
    if (!c) {
      const info = db.prepare("INSERT INTO clients(name,company,email,email_norm,phone,phone_norm,city,tags,notes,source,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)")
        .run(name, extra.company ?? null, extra.email ?? null, extra.email ? normEmail(extra.email) : null,
          extra.phone ?? null, extra.phone ? normPhone(extra.phone) : null, extra.city ?? "Lahore",
          JSON.stringify(extra.tags ?? []), extra.notes ?? null, extra.source ?? "backfill", t, t);
      c = db.prepare("SELECT * FROM clients WHERE id=?").get(info.lastInsertRowid);
      seen.set(key, c);
    }
    if (entity) db.prepare("INSERT OR IGNORE INTO client_links(client_id,entity,entity_id) VALUES(?,?,?)").run(c.id, entity, entityId);
  };
  for (const r of db.prepare("SELECT * FROM leads").all()) upsert(r.name, { company: r.company, source: r.source, notes: r.note }, "lead", r.id);
  for (const r of db.prepare("SELECT * FROM quotes").all()) upsert(r.customer, { source: r.source }, "quote", r.id);
  for (const r of db.prepare("SELECT * FROM orders").all()) upsert(r.customer, { source: "checkout" }, "order", r.id);
  for (const r of db.prepare("SELECT * FROM invoices").all()) upsert(r.customer, {}, "invoice", r.id);
  const clifton = db.prepare("SELECT id FROM clients WHERE name LIKE ?").get("%Clifton%");
  if (clifton) db.prepare("UPDATE clients SET email='sales@cliftonvilla9.example', email_norm='sales@cliftonvilla9.example' WHERE id=?").run(clifton.id);
  if (clifton) {
    db.prepare("INSERT INTO tasks(client_id,title,due,priority,done,created_at,updated_at) VALUES(?,?,?,?,?,?,?)")
      .run(clifton.id, "Confirm teak veneer swatch before production", new Date(Date.now() + 864e5).toISOString().slice(0, 10), "high", 0, t, t);
    db.prepare("INSERT INTO tasks(client_id,title,due,priority,created_at,updated_at) VALUES(?,?,?,?,?,?)")
      .run(clifton.id, "Send installment-2 reminder", new Date(Date.now() - 864e5).toISOString().slice(0, 10), "normal", t, t);
  }
  // deliberately ambiguous pair for the review queue: same email, different phone
  db.prepare("INSERT INTO clients(name,email,email_norm,phone,phone_norm,tags,source,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)")
    .run("Clifton Villa 9 (WhatsApp)", "sales@cliftonvilla9.example", "sales@cliftonvilla9.example", "+92 300 7654321", "3007654321", "[]", "WhatsApp", t, t);
  console.log("[seed] CRM demo: clients, links, tasks, 1 review pair");
}

/* ---------------- helpers ---------------- */
const rowProduct = (r) => ({ ...JSON.parse(r.json), price: r.price, inStock: !!r.in_stock, stockQty: r.stock_qty, updatedAt: r.updated_at });
const rowQuote = (r) => ({ id: r.id, ref: r.ref, customer: r.customer, contact: r.contact, items: JSON.parse(r.items), total: r.total, status: r.status, source: r.source, note: r.note, audit: JSON.parse(r.audit || "[]"), createdAt: r.created_at, updatedAt: r.updated_at });
const rowLead = (r) => ({ id: r.id, ref: r.ref, name: r.name, interest: r.interest, source: r.source, contact: r.contact, status: r.status, owner: r.owner, note: r.note, createdAt: r.created_at, updatedAt: r.updated_at });
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
const wrap = (fn) => (req, res) => { try { fn(req, res); } catch (e) { res.status(500).json({ error: String(e.message || e) }); } };

/* ---------------- app ---------------- */
const app = express();
app.use(express.json({ limit: "2mb" }));

app.get("/api/health", wrap((req, res) => {
  const c = (t) => db.prepare(`SELECT COUNT(*) n FROM ${t}`).get().n;
  res.json({ ok: true, db: "sqlite", ts: now(), counts: { products: c("products"), leads: c("leads"), quotes: c("quotes"), orders: c("orders") } });
}));

app.get("/api/products", wrap((req, res) => {
  const q = (req.query.q || "").toLowerCase();
  const sub = req.query.sub || "";
  let rows = db.prepare("SELECT * FROM products ORDER BY id").all();
  let items = rows.map(rowProduct);
  if (sub) items = items.filter((p) => p.subcategory === sub);
  if (q) items = items.filter((p) => (p.name + " " + (p.series ?? "")).toLowerCase().includes(q));
  res.json({ total: items.length, items });
}));

app.get("/api/products/overrides", wrap((req, res) => {
  const since = req.query.since ? Date.parse(req.query.since) : 0;
  const items = db
    .prepare("SELECT id, price, in_stock, updated_at FROM products WHERE datetime(updated_at) > datetime(?)")
    .all(new Date(since).toISOString())
    .map((r) => ({ id: r.id, price: r.price, inStock: !!r.in_stock, updatedAt: r.updated_at }));
  res.json({ serverTime: now(), items });
}));

app.get("/api/products/:id", wrap((req, res) => {
  const r = db.prepare("SELECT * FROM products WHERE id = ?").get(req.params.id);
  if (!r) return res.status(404).json({ error: "not found" });
  res.json(rowProduct(r));
}));

app.patch("/api/products/:id", wrap((req, res) => {
  const r = db.prepare("SELECT * FROM products WHERE id = ?").get(req.params.id);
  if (!r) return res.status(404).json({ error: "not found" });
  const { price, inStock, stockQty } = req.body ?? {};
  const json = JSON.parse(r.json);
  if (typeof price === "number" && price > 0) json.price = price;
  if (typeof inStock === "boolean") json.inStock = inStock;
  db.prepare("UPDATE products SET json=?, price=?, in_stock=?, stock_qty=?, updated_at=? WHERE id=?").run(
    JSON.stringify(json),
    json.price,
    json.inStock ? 1 : 0,
    typeof stockQty === "number" ? stockQty : r.stock_qty,
    now(),
    req.params.id
  );
  const out = rowProduct(db.prepare("SELECT * FROM products WHERE id = ?").get(req.params.id));
  res.json(out);
}));

app.get("/api/materials", wrap((req, res) => res.json(db.prepare("SELECT json FROM materials").all().map((r) => JSON.parse(r.json)))));
app.get("/api/services", wrap((req, res) => res.json(db.prepare("SELECT json FROM services").all().map((r) => JSON.parse(r.json)))));

/* Quotes — storefront submits, dashboard works the pipeline */
app.get("/api/quotes", wrap((req, res) => {
  let rows = db.prepare("SELECT * FROM quotes ORDER BY datetime(created_at) DESC").all();
  if (req.query.status) rows = rows.filter((r) => r.status === req.query.status);
  res.json({ total: rows.length, items: rows.map(rowQuote) });
}));
app.post("/api/quotes", wrap((req, res) => {
  const { customer, contact, items = [], total, note, source = "storefront" } = req.body ?? {};
  if (!customer || !items.length) return res.status(400).json({ error: "customer and items are required" });
  const seq = 2295 + db.prepare("SELECT COUNT(*) n FROM quotes").get().n;
  const ref = req.body.ref || "Q-" + seq;
  const sum = typeof total === "number" ? total : items.reduce((n, i) => n + (i.price || 0) * (i.qty || 1), 0);
  const t = now();
  const info = db.prepare("INSERT INTO quotes(ref,customer,contact,items,total,status,source,note,audit,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
    .run(ref, customer, contact ?? null, JSON.stringify(items), sum, "Draft", source, note ?? null,
      JSON.stringify([{ who: source === "storefront" ? customer : "You", action: "requested quotation via " + source, time: t }]), t, t);
  const qLed = rowQuote(db.prepare("SELECT * FROM quotes WHERE id=?").get(info.lastInsertRowid));
  { const m = matchOrCreateClient({ name: customer, contact: contact ?? note, source }); linkClient(m.client.id, "quote", qLed.id); }
  emit("quotes", { title: "Quotation " + ref + " requested", who: customer }); res.status(201).json(qLed);
}));
app.patch("/api/quotes/:id", wrap((req, res) => {
  const r = db.prepare("SELECT * FROM quotes WHERE id=? OR ref=?").get(req.params.id, req.params.id);
  if (!r) return res.status(404).json({ error: "not found" });
  const { status, note, customer, contact } = req.body ?? {};
  const audit = JSON.parse(r.audit || "[]");
  if (status) audit.unshift({ who: "You", action: "moved to " + status, time: now() });
  if (note) audit.unshift({ who: "You", action: "noted: " + note, time: now() });
  db.prepare("UPDATE quotes SET status=?, note=?, customer=?, contact=?, audit=?, updated_at=? WHERE id=?").run(
    status ?? r.status, note ?? r.note, customer ?? r.customer, contact ?? r.contact, JSON.stringify(audit), now(), r.id
  );
  const out = rowQuote(db.prepare("SELECT * FROM quotes WHERE id=?").get(r.id));
  if (status === "Sent") db.prepare("INSERT INTO tasks(quote_id,title,due,priority,created_at,updated_at) VALUES(?,?,?,?,?,?)")
    .run(r.id, "Follow up on quote " + r.ref + " (" + r.customer + ")", new Date(Date.now() + 864e5 * 3).toISOString().slice(0, 10), "normal", now(), now());
  if (status) emit("quotes", { title: "Quotation " + r.ref + " → " + status, who: r.customer });
  res.json(out);
}));

/* Leads — contact form → CRM */
app.get("/api/leads", wrap((req, res) => {
  const rows = db.prepare("SELECT * FROM leads ORDER BY datetime(created_at) DESC").all();
  const items = rows.map((r) => { const l = rowLead(r); const sc = scoreLead(l);
    const link = db.prepare("SELECT client_id c FROM client_links WHERE entity='lead' AND entity_id=?").get(l.id);
    return { ...l, score: sc.score, scoreWhy: sc.why, clientId: link?.c ?? null }; });
  res.json({ total: rows.length, items });
}));
app.post("/api/leads", wrap((req, res) => {
  const { name, company, interest, source = "Website", contact, note } = req.body ?? {};
  if (!name) return res.status(400).json({ error: "name is required" });
  const t = now();
  const ref = "L-" + (1100 + db.prepare("SELECT COUNT(*) n FROM leads").get().n);
  const info = db.prepare("INSERT INTO leads(ref,name,company,interest,source,contact,status,note,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)")
    .run(ref, name, company ?? null, interest ?? "General inquiry", source, contact ?? null, "New", note ?? null, t, t);
  const led = rowLead(db.prepare("SELECT * FROM leads WHERE id=?").get(info.lastInsertRowid));
  const m = matchOrCreateClient({ name, company, contact, source, note });
  linkClient(m.client.id, "lead", led.id);
  emit("leads", { title: "New lead " + name + (m.created ? " + client created" : ""), who: name, context: interest ?? "" });
  emit("clients", { title: (m.created ? "Client created: " : "Client matched: ") + m.client.name, who: m.client.name });
  res.status(201).json({ ...led, clientId: m.client.id });
}));
app.patch("/api/leads/:id", wrap((req, res) => {
  const r = db.prepare("SELECT * FROM leads WHERE id=? OR ref=?").get(req.params.id, req.params.id);
  if (!r) return res.status(404).json({ error: "not found" });
  const { status, owner } = req.body ?? {};
  db.prepare("UPDATE leads SET status=?, owner=?, updated_at=? WHERE id=?").run(status ?? r.status, owner ?? r.owner, now(), r.id);
  const leadOut = rowLead(db.prepare("SELECT * FROM leads WHERE id=?").get(r.id));
  emit("leads", { title: "Lead " + r.ref + " → " + (req.body?.status ?? r.status), who: r.name });
  res.json(leadOut);
}));

/* Orders — checkout → operations */
app.get("/api/orders", wrap((req, res) => {
  res.json({ total: 0, items: db.prepare("SELECT * FROM orders ORDER BY datetime(created_at) DESC").all().map(rowOrder) });
}));
app.post("/api/orders", wrap((req, res) => {
  const { customer, items = [], total, source } = req.body ?? {};
  if (!customer || !items.length) return res.status(400).json({ error: "customer and items are required" });
  const t = now();
  const sum = typeof total === "number" ? total : items.reduce((n, i) => n + (i.price || 0) * (i.qty || 1), 0);
  const info = db.prepare("INSERT INTO orders(ref,customer,items,total,status,stage,owner,due,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)")
    .run("WX-" + (4300 + db.prepare("SELECT COUNT(*) n FROM orders").get().n), customer, JSON.stringify(items), sum,
      "Pending", "production", source || "Showroom", "TBD", t, t);
  const oLed = rowOrder(db.prepare("SELECT * FROM orders WHERE id=?").get(info.lastInsertRowid));
  { const m = matchOrCreateClient({ name: customer, contact: req.body?.contact, source: "checkout" }); linkClient(m.client.id, "order", oLed.id);
    db.prepare("INSERT INTO invoices(ref,order_id,customer,items,subtotal,total,status,due,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)")
      .run("INV-26-" + (1100 + db.prepare("SELECT COUNT(*) n FROM invoices").get().n), oLed.id, customer, oLed.items ? JSON.stringify(oLed.items) : "[]",
        oLed.total, Math.round(oLed.total * 1.05), "Issued", new Date(Date.now() + 864e5 * 7).toISOString().slice(0, 10), t, t);
    const invRow = db.prepare("SELECT id FROM invoices WHERE order_id=?").get(oLed.id);
    if (invRow) linkClient(m.client.id, "invoice", invRow.id);
    db.prepare("INSERT OR IGNORE INTO tasks(client_id,order_id,title,due,priority,created_at,updated_at) VALUES(?,?,?,?,?,?,?)")
      .run(m.client.id, oLed.id, "Collect 50% advance before production — " + ref, new Date(Date.now() + 864e5 * 2).toISOString().slice(0, 10), "high", t, t); }
  emit("orders", { title: "Order " + ref + " placed · invoice issued", who: customer }); res.status(201).json(oLed);
}));
app.patch("/api/orders/:id", wrap((req, res) => {
  const r = db.prepare("SELECT * FROM orders WHERE id=? OR ref=?").get(req.params.id, req.params.id);
  if (!r) return res.status(404).json({ error: "not found" });
  const { status, stage, owner, due } = req.body ?? {};
  db.prepare("UPDATE orders SET status=?, stage=?, owner=?, due=?, updated_at=? WHERE id=?").run(
    status ?? r.status, stage ?? r.stage, owner ?? r.owner, due ?? r.due, now(), r.id
  );
  const oOut = rowOrder(db.prepare("SELECT * FROM orders WHERE id=?").get(r.id));
  if (req.body?.stage || req.body?.status) emit("orders", { title: "Order " + r.ref + " → " + (req.body?.stage ? req.body.stage + " · " : "") + (req.body?.status ?? r.status), who: r.customer });
  res.json(oOut);
}));

/* Dashboard rollup */
/* ---- P4 CRM: single client record, links, tasks, scoring ---- */
const digits = (v) => String(v ?? "").replace(/\D+/g, "");
const normPhone = (v) => (digits(v).slice(-10) || null);
const normEmail = (v) => { const m = String(v ?? "").match(/[\w.+-]+@[\w-]+\.[\w.]+/); return m ? m[0].toLowerCase() : null; };
const rowClientLite = (c) => ({ id: c.id, name: c.name, company: c.company, email: c.email, phone: c.phone, city: c.city,
  tags: JSON.parse(c.tags || "[]"), notes: c.notes, source: c.source, createdAt: c.created_at, updatedAt: c.updated_at });
const linkIds = (clientId, entity) => db.prepare("SELECT entity_id id FROM client_links WHERE client_id=? AND entity=?").all(clientId, entity).map((r) => r.id);
const inList = (ids) => (ids.length ? `(${ids.join(",")})` : "(0)");

function scoreLead(l) {
  let score = 20; const why = ["base engagement score"];
  const srcBonus = { Referral: 25, "Walk-in": 20, Website: 12, "Website Form": 12, Instagram: 12, Ecommerce: 12, "Contact Form": 10, Campaign: 10, "Cold Outreach": 4 }[l.source] ?? 6;
  score += srcBonus; why.push(l.source + " source +" + srcBonus);
  const q = db.prepare("SELECT COUNT(*) n FROM quotes WHERE contact=? OR customer=?").get(l.contact ?? "", l.name)?.n ?? 0;
  if (q) { score += 20; why.push("has " + q + " quote" + (q > 1 ? "s" : "") + " +20"); }
  const o = db.prepare("SELECT COUNT(*) n FROM orders WHERE customer=?").get(l.name)?.n ?? 0;
  if (o) { score += 30; why.push("repeat buyer / live order +30"); }
  if (l.contact && /@/.test(l.contact) && digits(l.contact).length > 6) { score += 10; why.push("full contact +10"); }
  const days = (Date.now() - Date.parse(l.updatedAt ?? l.createdAt)) / 864e5;
  if (days < 7) { score += 15; why.push("active this week +15"); } else if (days < 30) { score += 5; why.push("active this month +5"); }
  if (/(budget|rs\s?\d|lakhs?|crore)/i.test((l.note ?? "") + " " + (l.interest ?? ""))) { score += 10; why.push("budget signal +10"); }
  if (l.status === "Lost") { score -= 30; why.push("marked lost −30"); }
  return { score: Math.max(0, Math.min(100, score)), why };
}

/** identity resolution: phone OR email match — never silently merge conflicts */
function matchOrCreateClient({ name, company, contact, source, note }) {
  const email = normEmail(contact), phone = normPhone(contact);
  let where = [];
  if (email) where.push("email_norm=" + db2s(email));
  if (phone) where.push("phone_norm=" + db2s(phone));
  where.push("lower(name)=" + db2s(String(name ?? "").toLowerCase().trim()));
  const sql = "SELECT * FROM clients WHERE merged_into IS NULL AND (" + where.join(" OR ") + ") ORDER BY id LIMIT 2";
  const hits = db.prepare(sql).all();
  const t = now();
  if (hits.length === 0) {
    const info = db.prepare("INSERT INTO clients(name,company,email,email_norm,phone,phone_norm,tags,notes,source,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
      .run(name ?? "Unknown", company ?? null, email ? String(contact).match(/[\w.+-]+@[\w-]+\.[\w.]+/)?.[0] : null, email, phone,
        "[]", note ? String(note).slice(0, 400) : null, source ?? "manual", t, t);
    return { client: db.prepare("SELECT * FROM clients WHERE id=?").get(info.lastInsertRowid), created: true };
  }
  const c = hits[0];
  const patch = {};
  if (email && !c.email_norm) { patch.email = email; patch.email_norm = email; }
  if (phone && !c.phone_norm) { patch.phone = phone; patch.phone_norm = phone; }
  if (company && !c.company) patch.company = company;
  if (Object.keys(patch).length) {
    db.prepare("UPDATE clients SET " + Object.keys(patch).map((k) => k + "=?").join(",") + ", updated_at=? WHERE id=?")
      .run(...Object.values(patch), t, c.id);
  }
  return { client: db.prepare("SELECT * FROM clients WHERE id=?").get(c.id), created: false };
}
const db2s = (v) => "'" + String(v).replace(/'/g, "''") + "'";
const linkClient = (clientId, entity, entityId) =>
  db.prepare("INSERT OR IGNORE INTO client_links(client_id,entity,entity_id) VALUES(?,?,?)").run(clientId, entity, entityId);

function clientTimeline(clientId) {
  const ev = [];
  for (const id of linkIds(clientId, "lead")) { const r = db.prepare("SELECT * FROM leads WHERE id=?").get(id); if (r) ev.push({ kind: "lead", ref: r.ref, label: "Lead · " + (r.interest ?? "inquiry"), status: r.status, time: r.created_at }); }
  for (const id of linkIds(clientId, "quote")) { const r = db.prepare("SELECT * FROM quotes WHERE id=?").get(id); if (r) ev.push({ kind: "quote", ref: r.ref, label: "Quotation " + r.ref, status: r.status, value: r.total, time: r.created_at }); }
  for (const id of linkIds(clientId, "order")) { const r = db.prepare("SELECT * FROM orders WHERE id=?").get(id); if (r) ev.push({ kind: "order", ref: r.ref, label: "Order " + r.ref, status: r.status + " · " + r.stage, value: r.total, time: r.created_at }); }
  for (const id of linkIds(clientId, "invoice")) { const r = db.prepare("SELECT * FROM invoices WHERE id=?").get(id); if (r) ev.push({ kind: "invoice", ref: r.ref, label: "Invoice " + r.ref, status: r.status, value: r.total, paid: r.paid, time: r.created_at }); }
  for (const id of linkIds(clientId, "return")) { const r = db.prepare("SELECT * FROM returns WHERE id=?").get(id); if (r) ev.push({ kind: "return", ref: r.ref, label: "Return " + r.ref, status: r.state, time: r.created_at }); }
  return ev.sort((a, b) => Date.parse(b.time ?? 0) - Date.parse(a.time ?? 0)).slice(0, 30);
}

function clientFull(c) {
  const orders = linkIds(c.id, "order").map((id) => db.prepare("SELECT * FROM orders WHERE id=?").get(id)).filter(Boolean);
  const quotes = linkIds(c.id, "quote").map((id) => db.prepare("SELECT * FROM quotes WHERE id=?").get(id)).filter(Boolean);
  const invoices = linkIds(c.id, "invoice").map((id) => db.prepare("SELECT * FROM invoices WHERE id=?").get(id)).filter(Boolean);
  const lifetime = orders.reduce((n, o) => n + o.total, 0);
  const outstanding = invoices.reduce((n, i) => n + Math.max(0, i.total - i.paid), 0);
  const tl = clientTimeline(c.id);
  const tasks = db.prepare("SELECT * FROM tasks WHERE client_id=? ORDER BY done ASC, datetime(COALESCE(due,'9999')) ASC").all(c.id).map(rowTask);
  return { ...rowClientLite(c), lifetime, outstanding, quotes: quotes.length, orders: orders.length,
    openBalance: outstanding, lastActivity: tl[0]?.time ?? c.updated_at, timeline: tl, tasks };
}
const rowTask = (r) => ({ id: r.id, clientId: r.client_id, leadId: r.lead_id, quoteId: r.quote_id, orderId: r.order_id,
  title: r.title, due: r.due, owner: r.owner, priority: r.priority, done: !!r.done, createdAt: r.created_at, updatedAt: r.updated_at });

/* ---- P3 realtime: Server-Sent Events bus ---- */
const sseClients = new Set();
const emit = (type, payload = {}) => {
  const json = JSON.stringify({ type, at: now(), ...payload });
  for (const c of sseClients) { try { c.write(`event: ${type}\ndata: ${json}\n\n`); } catch { sseClients.delete(c); } }
};
app.get("/api/clients", wrap((req, res) => {
  let rows = db.prepare("SELECT * FROM clients WHERE merged_into IS NULL ORDER BY datetime(updated_at) DESC").all();
  const q = String(req.query.q ?? "").toLowerCase();
  if (q) rows = rows.filter((c) => (c.name + " " + (c.company ?? "") + " " + (c.email ?? "") + " " + (c.phone ?? "")).toLowerCase().includes(q));
  const items = rows.map((c) => { const f = clientFull(c);
    return { ...rowClientLite(c), lifetime: f.lifetime, outstanding: f.outstanding, quotes: f.quotes, orders: f.orders,
      lastActivity: f.lastActivity, openTasks: f.tasks.filter((x) => !x.done).length }; });
  res.json({ total: items.length, items });
}));
app.get("/api/clients/review", wrap((req, res) => {
  const pairs = [];
  for (const key of ["email_norm", "phone_norm"]) {
    const rows = db.prepare(`SELECT ${key} k, COUNT(*) c, GROUP_CONCAT(id) ids FROM clients WHERE merged_into IS NULL AND ${key} IS NOT NULL GROUP BY ${key} HAVING c > 1`).all();
    for (const r of rows) { const ids = r.ids.split(",").map(Number);
      for (let i = 0; i + 1 < ids.length; i++) {
        const a = db.prepare("SELECT * FROM clients WHERE id=?").get(ids[i]), b = db.prepare("SELECT * FROM clients WHERE id=?").get(ids[i + 1]);
        pairs.push({ a: rowClientLite(a), b: rowClientLite(b), shared: key === "email_norm" ? "email" : "phone", value: r.k,
          conflict: (a.phone_norm ?? "") !== (b.phone_norm ?? "") && (a.email_norm ?? "") !== (b.email_norm ?? "") ? "other contact differs" : "same " + (key === "email_norm" ? "email" : "phone") });
      } }
  }
  res.json({ total: pairs.length, items: pairs });
}));
app.get("/api/clients/:id", wrap((req, res) => {
  const c = db.prepare("SELECT * FROM clients WHERE id=?").get(req.params.id);
  if (!c) return res.status(404).json({ error: "not found" });
  res.json(clientFull(c));
}));
app.post("/api/clients", wrap((req, res) => {
  const b = req.body ?? {}; if (!b.name) return res.status(400).json({ error: "name required" });
  const t = now();
  const info = db.prepare("INSERT INTO clients(name,company,email,email_norm,phone,phone_norm,tags,notes,source,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?)")
    .run(b.name, b.company ?? null, b.email ?? null, normEmail(b.email), b.phone ?? null, normPhone(b.phone),
      JSON.stringify(b.tags ?? []), b.notes ?? null, b.source ?? "manual", t, t);
  const c = db.prepare("SELECT * FROM clients WHERE id=?").get(info.lastInsertRowid);
  emit("clients", { title: "Client added: " + c.name, who: c.name });
  res.status(201).json(rowClientLite(c));
}));
app.patch("/api/clients/:id", wrap((req, res) => {
  const c = db.prepare("SELECT * FROM clients WHERE id=?").get(req.params.id);
  if (!c) return res.status(404).json({ error: "not found" });
  const b = req.body ?? {};
  db.prepare("UPDATE clients SET name=?,company=?,email=?,email_norm=?,phone=?,phone_norm=?,city=?,tags=?,notes=?,updated_at=? WHERE id=?")
    .run(b.name ?? c.name, b.company ?? c.company, b.email ?? c.email,
      b.email !== undefined ? normEmail(b.email) : c.email_norm, b.phone ?? c.phone,
      b.phone !== undefined ? normPhone(b.phone) : c.phone_norm, b.city ?? c.city,
      b.tags ? JSON.stringify(b.tags) : c.tags, b.notes ?? c.notes, now(), c.id);
  res.json(rowClientLite(db.prepare("SELECT * FROM clients WHERE id=?").get(c.id)));
}));
app.post("/api/clients/:id/merge", wrap((req, res) => {
  const into = db.prepare("SELECT * FROM clients WHERE id=?").get(req.params.id);
  const from = db.prepare("SELECT * FROM clients WHERE id=?").get(req.body?.from_id);
  if (!into || !from) return res.status(404).json({ error: "client pair not found" });
  db.prepare("UPDATE client_links SET client_id=? WHERE client_id=?").run(into.id, from.id);
  const t = now();
  db.prepare("UPDATE clients SET merged_into=?, updated_at=? WHERE id=?").run(into.id, t, from.id);
  db.prepare("UPDATE clients SET email=?, email_norm=?, phone=?, phone_norm=?, company=COALESCE(company,?), notes=TRIM(COALESCE(notes,'')||?) WHERE id=?")
    .run(into.email ?? from.email, into.email_norm ?? from.email_norm, into.phone ?? from.phone, into.phone_norm ?? from.phone_norm,
      from.company, from.notes ? " | merged note: " + from.notes : "", into.id);
  emit("clients", { title: "Merged " + from.name + " → " + into.name, who: into.name });
  res.json(clientFull(db.prepare("SELECT * FROM clients WHERE id=?").get(into.id)));
}));
app.get("/api/tasks", wrap((req, res) => {
  let rows = db.prepare("SELECT * FROM tasks ORDER BY done ASC, datetime(COALESCE(due,'9999')) ASC").all();
  if (req.query.scope === "today") rows = rows.filter((r) => !r.done && (r.due ?? "") <= now().slice(0, 10));
  res.json({ total: rows.length, open: rows.filter((r) => !r.done).length, overdue: rows.filter((r) => !r.done && r.due && r.due < now().slice(0, 10)).length, items: rows.map(rowTask) });
}));
app.post("/api/tasks", wrap((req, res) => {
  const b = req.body ?? {}; if (!b.title) return res.status(400).json({ error: "title required" });
  const t = now();
  const info = db.prepare("INSERT INTO tasks(client_id,lead_id,quote_id,order_id,title,due,owner,priority,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)")
    .run(b.client_id ?? null, b.lead_id ?? null, b.quote_id ?? null, b.order_id ?? null, b.title, b.due ?? null, b.owner ?? "You", b.priority ?? "normal", t, t);
  const out = rowTask(db.prepare("SELECT * FROM tasks WHERE id=?").get(info.lastInsertRowid));
  emit("tasks", { title: "Task added: " + b.title.slice(0, 60), who: b.owner ?? "You" });
  res.status(201).json(out);
}));
app.patch("/api/tasks/:id", wrap((req, res) => {
  const r = db.prepare("SELECT * FROM tasks WHERE id=?").get(req.params.id);
  if (!r) return res.status(404).json({ error: "not found" });
  const b = req.body ?? {};
  db.prepare("UPDATE tasks SET title=?, due=?, owner=?, priority=?, done=?, updated_at=? WHERE id=?").run(
    b.title ?? r.title, b.due ?? r.due, b.owner ?? r.owner, b.priority ?? r.priority,
    b.done !== undefined ? (b.done ? 1 : 0) : r.done, now(), r.id);
  res.json(rowTask(db.prepare("SELECT * FROM tasks WHERE id=?").get(r.id)));
}));

app.get("/api/events", (req, res) => {
  res.writeHead(200, { "content-type": "text/event-stream", "cache-control": "no-cache, no-transform", connection: "keep-alive", "x-accel-buffering": "no" });
  res.write("retry: 3000\n\n");
  res.write(`event: hello\ndata: ${JSON.stringify({ type: "hello", at: now() })}\n\n`);
  sseClients.add(res);
  req.on("close", () => sseClients.delete(res));
});

app.post("/api/target", wrap((req, res) => {
  const v = Number(req.body?.value);
  if (!(v > 0)) return res.status(400).json({ error: "value must be > 0" });
  db.prepare("INSERT INTO meta(key,value) VALUES('target',?) ON CONFLICT(key) DO UPDATE SET value=excluded.value").run(String(v));
  res.json({ target: v });
}));

/* ---- P5 finance: invoices, payments, returns, public lookup ---- */
const totalsFrom = (items, { discount = 0, tax = 0, shipping = 0 }) => {
  const subtotal = items.reduce((n, i) => n + (i.price || 0) * (i.qty || 1), 0);
  return { subtotal, total: Math.max(0, subtotal - discount + tax + shipping) };
};
const insertInvoice = ({ ref, quoteId, orderId, customer, items, discount, tax, shipping, total, due, notes }) => {
  const t = now();
  const info = db.prepare(`INSERT INTO invoices(ref,quote_id,order_id,customer,items,subtotal,discount,tax,shipping,total,status,due,notes,created_at,updated_at)
    VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`).run(ref, quoteId ?? null, orderId ?? null, customer, JSON.stringify(items),
    subtotalOf(items), discount ?? 0, tax ?? 0, shipping ?? 0, total, "Issued", due ?? null, notes ?? null, t, t);
  return db.prepare("SELECT * FROM invoices WHERE id=?").get(info.lastInsertRowid);
};
const subtotalOf = (items) => items.reduce((n, i) => n + (i.price || 0) * (i.qty || 1), 0);
const nextRef = (table, prefix, pad) => prefix + String(db.prepare("SELECT COUNT(*) n FROM " + table).get().n + pad).padStart(4, "0");

app.get("/api/invoices", wrap((req, res) => {
  let rows = db.prepare("SELECT * FROM invoices ORDER BY datetime(created_at) DESC").all().map(decorateInvoice);
  const f = req.query.status;
  if (f === "open") rows = rows.filter((r) => ["Issued", "Partially Paid", "Overdue"].includes(r.status));
  else if (f) rows = rows.filter((r) => r.status === f);
  res.json({ total: rows.length,
    collected: rows.reduce((n, r) => n + r.paid, 0),
    outstanding: rows.reduce((n, r) => n + (r.status === "Cancelled" ? 0 : r.balance), 0),
    items: rows });
}));
app.get("/api/invoices/:id", wrap((req, res) => {
  const r = db.prepare("SELECT * FROM invoices WHERE id=? OR ref=?").get(req.params.id, req.params.id);
  if (!r) return res.status(404).json({ error: "not found" });
  const payments = db.prepare("SELECT * FROM payments WHERE invoice_id=? ORDER BY datetime(created_at) DESC").all(r.id);
  res.json({ ...decorateInvoice(r), payments });
}));
app.post("/api/invoices", wrap((req, res) => {
  const b = req.body ?? {};
  let { customer, items } = b;
  let quoteId = null, orderId = null;
  if (b.quote_id) { const q = db.prepare("SELECT * FROM quotes WHERE id=? OR ref=?").get(b.quote_id, b.quote_id);
    if (!q) return res.status(400).json({ error: "quote not found" });
    customer = customer || q.customer; items = items ?? JSON.parse(q.items); quoteId = q.id; }
  else if (b.order_id) { const o = db.prepare("SELECT * FROM orders WHERE id=? OR ref=?").get(b.order_id, b.order_id);
    if (!o) return res.status(400).json({ error: "order not found" });
    customer = customer || o.customer; items = items ?? JSON.parse(o.items); orderId = o.id; }
  if (!customer || !Array.isArray(items) || !items.length) return res.status(400).json({ error: "customer+items or quote_id/order_id required" });
  const { subtotal: _st, total } = totalsFrom(items, b);
  const r = insertInvoice({ ref: nextRef("invoices", "INV-26-", 1001), quoteId, orderId, customer, items,
    discount: b.discount, tax: b.tax, shipping: b.shipping, total: typeof b.total === "number" ? b.total : total,
    due: b.due, notes: b.notes });
  if (quoteId) db.prepare("UPDATE quotes SET audit=?, updated_at=? WHERE id=?").run(
    JSON.stringify([{ who: "You", action: "invoice " + r.ref + " issued", time: now() },
      ...JSON.parse(db.prepare("SELECT audit FROM quotes WHERE id=?").get(quoteId)?.audit || "[]")]), now(), quoteId);
  emit("invoices", { title: "Invoice " + r.ref + " issued", who: r.customer });
  res.status(201).json(rowInvoice(r));
}));
app.post("/api/quotes/:id/invoice", wrap((req, res) => {
  const q = db.prepare("SELECT * FROM quotes WHERE id=? OR ref=?").get(req.params.id, req.params.id);
  if (!q) return res.status(404).json({ error: "quote not found" });
  const items = JSON.parse(q.items || "[]");
  const b = req.body ?? {};
  const { total } = totalsFrom(items, b);
  const r = insertInvoice({ ref: nextRef("invoices", "INV-26-", 1001), quoteId: q.id, orderId: null,
    customer: q.customer, items, discount: b.discount, tax: b.tax, shipping: b.shipping,
    total: typeof b.total === "number" ? b.total : total, due: b.due, notes: b.notes });
  db.prepare("UPDATE quotes SET audit=?, updated_at=? WHERE id=?").run(
    JSON.stringify([{ who: "You", action: "invoice " + r.ref + " issued from quote", time: now() },
      ...JSON.parse(q.audit || "[]")]), now(), q.id);
  { const cl = db.prepare("SELECT client_id c FROM clients WHERE lower(name)=lower(?) AND merged_into IS NULL").get(r.customer);
    if (cl) linkClient(cl.c, "invoice", r.id); }
  emit("invoices", { title: "Invoice " + r.ref + " issued", who: r.customer });
  res.status(201).json(rowInvoice(r));
}));
app.patch("/api/invoices/:id", wrap((req, res) => {
  const r = db.prepare("SELECT * FROM invoices WHERE id=? OR ref=?").get(req.params.id, req.params.id);
  if (!r) return res.status(404).json({ error: "not found" });
  const b = req.body ?? {};
  emit("invoices", { title: "Invoice " + r.ref + " → " + (b.status ?? r.status), who: r.customer });
  db.prepare("UPDATE invoices SET status=?, due=?, notes=?, discount=?, tax=?, shipping=?, total=?, updated_at=? WHERE id=?").run(
    b.status ?? r.status, b.due ?? r.due, b.notes ?? r.notes,
    b.discount ?? r.discount, b.tax ?? r.tax, b.shipping ?? r.shipping,
    b.total ?? (b.discount != null || b.tax != null || b.shipping != null
      ? Math.max(0, r.subtotal - (b.discount ?? r.discount) + (b.tax ?? r.tax) + (b.shipping ?? r.shipping)) : r.total),
    now(), r.id);
  res.json(rowInvoice(db.prepare("SELECT * FROM invoices WHERE id=?").get(r.id)));
}));
app.post("/api/invoices/:id/payments", wrap((req, res) => {
  const r = db.prepare("SELECT * FROM invoices WHERE id=? OR ref=?").get(req.params.id, req.params.id);
  if (!r) return res.status(404).json({ error: "not found" });
  const amount = Number(req.body?.amount);
  if (!(amount > 0)) return res.status(400).json({ error: "amount must be > 0" });
  const t = now();
  db.prepare("INSERT INTO payments(invoice_id,amount,method,reference,note,created_at) VALUES(?,?,?,?,?,?)")
    .run(r.id, amount, req.body?.method ?? "Bank transfer", req.body?.reference ?? null, req.body?.note ?? null, t);
  const paid = r.paid + amount;
  const status = paid >= r.total ? "Paid" : "Partially Paid";
  db.prepare("UPDATE invoices SET paid=?, status=?, updated_at=? WHERE id=?").run(paid, status, t, r.id);
  emit("invoices", { title: "Payment " + (amount >= r.balance ? "" : "partial ") + fmtMoney(amount) + " on " + r.ref, who: r.customer });
  emit("payments", { amount, invoiceId: r.id, method: req.body?.method ?? "Bank transfer" });
  res.status(201).json({ ...rowInvoice(db.prepare("SELECT * FROM invoices WHERE id=?").get(r.id)),
    payments: db.prepare("SELECT * FROM payments WHERE invoice_id=? ORDER BY datetime(created_at) DESC").all(r.id) });
}));

const RETURN_STATES = ["Requested", "Inspecting", "Refunding", "Closed"];
app.get("/api/returns", wrap((req, res) => {
  let rows = db.prepare("SELECT * FROM returns ORDER BY datetime(created_at) DESC").all().map(rowReturn);
  if (req.query.state) rows = rows.filter((r) => r.state === req.query.state);
  res.json({ total: rows.length, open: rows.filter((r) => r.state !== "Closed").length, items: rows });
}));
app.post("/api/returns", wrap((req, res) => {
  const b = req.body ?? {};
  let customer = b.customer; let orderId = null;
  if (b.order_id || b.order_ref) { const o = db.prepare("SELECT * FROM orders WHERE id=? OR ref=?").get(b.order_id ?? b.order_ref, b.order_ref ?? b.order_id);
    if (!o) return res.status(400).json({ error: "order not found" }); customer = customer || o.customer; orderId = o.id; }
  if (!customer || !b.reason) return res.status(400).json({ error: "customer (or order_ref) and reason required" });
  const t = now();
  const info = db.prepare("INSERT INTO returns(ref,order_id,customer,item,reason,state,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)")
    .run(nextRef("returns", "RMA-", 201), orderId, customer, b.item ?? null, b.reason, "Requested", t, t);
  const retOut = rowReturn(db.prepare("SELECT * FROM returns WHERE id=?").get(info.lastInsertRowid));
  emit("returns", { title: "RMA opened for " + customer, who: customer, context: b.reason });
  res.status(201).json(retOut);
}));
app.patch("/api/returns/:id", wrap((req, res) => {
  const r = db.prepare("SELECT * FROM returns WHERE id=? OR ref=?").get(req.params.id, req.params.id);
  if (!r) return res.status(404).json({ error: "not found" });
  const b = req.body ?? {};
  db.prepare("UPDATE returns SET state=?, refund_amount=?, resolution=?, updated_at=? WHERE id=?").run(
    b.state ?? r.state, b.refund_amount ?? b.refundAmount ?? r.refund_amount, b.resolution ?? r.resolution, now(), r.id);
  const rOut = rowReturn(db.prepare("SELECT * FROM returns WHERE id=?").get(r.id));
  if (b.state) emit("returns", { title: "RMA " + r.ref + " → " + b.state, who: r.customer });
  res.json(rOut);
}));

/* Public order tracking (storefront /order-status) — ref is the capability, no auth */
app.get("/api/orders/lookup", wrap((req, res) => {
  const ref = String(req.query.ref || "").trim();
  if (!ref) return res.status(400).json({ error: "ref required" });
  const o = db.prepare("SELECT * FROM orders WHERE ref=? OR ref=?").get(ref, ref.toUpperCase());
  if (!o) return res.status(404).json({ error: "no order with that reference" });
  const stages = ["production", "qc", "dispatch", "delivery", "installation"];
  const inv = o.invoice_ref ? db.prepare("SELECT * FROM invoices WHERE ref=?").get(o.invoice_ref) :
    db.prepare("SELECT * FROM invoices WHERE order_id=? ORDER BY id DESC LIMIT 1").get(o.id);
  const rms = db.prepare("SELECT * FROM returns WHERE order_id=? ORDER BY id DESC").all(o.id).map(rowReturn);
  res.json({ ref: o.ref, customer: o.customer, status: o.status, stage: o.stage, stages,
    stageIndex: stages.indexOf(o.stage), total: o.total, due: o.due, placedAt: o.created_at,
    items: JSON.parse(o.items), invoice: inv ? decorateInvoice(inv) : null, returns: rms });
}));

app.get("/api/stats", wrap((req, res) => {
  const n = (q) => db.prepare(q).get().n;
  const byStatus = (table) => {
    const rows = db.prepare(`SELECT status, COUNT(*) c, COALESCE(SUM(total),0) s FROM ${table} GROUP BY status`).all();
    return Object.fromEntries(rows.map((r) => [r.status, { count: r.c, value: r.s }]));
  };
  const leadBy = Object.fromEntries(db.prepare("SELECT status, COUNT(*) c FROM leads GROUP BY status").all().map((r) => [r.status, r.c]));
  const rec = (table, type) => db.prepare(`SELECT * FROM ${table} ORDER BY datetime(created_at) DESC LIMIT 3`).all().map((r) => {
    if (table === "leads") return { type, title: type === "lead" ? "New lead captured" : r.name, who: r.name, context: r.interest ?? "", time: r.created_at };
    return { type, title: `${table === "quotes" ? "Quotation" : "Order"} ${r.ref} · ${r.status}`, who: r.customer, context: `${(JSON.parse(r.items)).length} line items`, time: r.created_at };
  });
  const revenue = db.prepare("SELECT COALESCE(SUM(total),0) v FROM orders WHERE stage IN ('delivery','installation')").get().v;
  res.json({
    products: { total: n("SELECT COUNT(*) n FROM products"), inStock: n("SELECT COUNT(*) n FROM products WHERE in_stock=1"), avgPrice: db.prepare("SELECT AVG(price) v FROM products").get().v },
    leads: { total: n("SELECT COUNT(*) n FROM leads"), byStatus: leadBy },
    quotes: { total: n("SELECT COUNT(*) n FROM quotes"), byStatus: byStatus("quotes"), pipelineValue: db.prepare("SELECT COALESCE(SUM(total),0) v FROM quotes WHERE status NOT IN ('Rejected','Expired')").get().v },
    orders: { total: n("SELECT COUNT(*) n FROM orders"), byStage: byStatus("orders"), revenue },
    invoices: { total: n("SELECT COUNT(*) n FROM invoices"),
      collected: db.prepare("SELECT COALESCE(SUM(paid),0) v FROM invoices").get().v,
      outstanding: db.prepare("SELECT COALESCE(SUM(total-paid),0) v FROM invoices WHERE status NOT IN ('Paid','Cancelled')").get().v,
      byStatus: byStatus("invoices") },
    returns: { total: n("SELECT COUNT(*) n FROM returns"), open: n("SELECT COUNT(*) n FROM returns WHERE state != 'Closed'") },
    recent: [...rec("leads", "lead"), ...rec("quotes", "quote"), ...rec("orders", "production"),
      ...db.prepare("SELECT * FROM invoices ORDER BY datetime(created_at) DESC LIMIT 2").all().map((r) => ({ type: "invoice", title: `Invoice ${r.ref} · ${r.status}`, who: r.customer, context: "finance", time: r.created_at })),
      ...db.prepare("SELECT * FROM returns ORDER BY datetime(created_at) DESC LIMIT 1").all().map((r) => ({ type: "return", title: `Return ${r.ref} · ${r.state}`, who: r.customer, context: r.reason ?? "", time: r.created_at }))].slice(0, 8),
    finance: (() => {
      const delivered = db.prepare("SELECT COUNT(*) n, COALESCE(SUM(total),0) v FROM orders WHERE stage IN ('delivery','installation')").get();
      const target = Number(db.prepare("SELECT value FROM meta WHERE key='target'").get()?.value ?? 10000000);
      const won = n("SELECT COUNT(*) n FROM quotes WHERE status='Approved'");
      const open = n("SELECT COUNT(*) n FROM quotes WHERE status NOT IN ('Approved','Rejected','Expired')");
      const agg = {};
      for (const o of db.prepare("SELECT items FROM orders").all()) for (const it of JSON.parse(o.items)) {
        const k = it.name || "Item"; const a = (agg[k] ??= { name: k, qty: 0, value: 0 });
        a.qty += it.qty || 1; a.value += (it.price || 0) * (it.qty || 1);
      }
      const topProducts = Object.values(agg).sort((x, y) => y.value - x.value).slice(0, 5);
      const sources = db.prepare("SELECT COALESCE(source,'Other') k, COUNT(*) c FROM leads GROUP BY 1 ORDER BY c DESC").all();
      const totalLeads = Math.max(1, sources.reduce((n_, r) => n_ + r.c, 0));
      const payments = db.prepare(`SELECT p.amount, p.method, p.reference, p.created_at, i.ref inv FROM payments p JOIN invoices i ON i.id=p.invoice_id ORDER BY datetime(p.created_at) DESC LIMIT 5`).all();
      const months = []; const d = new Date();
      for (let i = 5; i >= 0; i--) { const t = new Date(d.getFullYear(), d.getMonth() - i, 1); months.push(t.toISOString().slice(0, 7)); }
      const monthly = months.map((m) => ({ month: m,
        revenue: db.prepare("SELECT COALESCE(SUM(total),0) v FROM orders WHERE substr(created_at,1,7)=? AND stage IN ('delivery','installation')").get(m).v }));
      return {
        aov: delivered.n ? Math.round(delivered.v / delivered.n) : 0,
        target: { value: target, pct: Math.min(100, Math.round((delivered.v / target) * 100)) },
        deals: { won, open },
        topProducts,
        sources: sources.map((r) => ({ name: r.k, count: r.c, pct: Math.round((r.c / totalLeads) * 100) })),
        payments, monthly,
      };
    })(),
    site: { pages: n("SELECT COUNT(*) n FROM pages"), published: n("SELECT COUNT(*) n FROM pages WHERE status='Published'"), sections: n("SELECT COUNT(*) n FROM saved_sections") },
    crm: {
      clients: n("SELECT COUNT(*) n FROM clients WHERE merged_into IS NULL"),
      review: (() => { let c = 0; for (const key of ["email_norm", "phone_norm"]) c += db.prepare(`SELECT COUNT(*) n FROM (SELECT ${key} k FROM clients WHERE merged_into IS NULL AND ${key} IS NOT NULL GROUP BY ${key} HAVING COUNT(*)>1)`).get().n; return c; })(),
      tasksDue: n("SELECT COUNT(*) n FROM tasks WHERE done=0 AND (due IS NULL OR due<=date('now'))"),
      hotLeads: db.prepare("SELECT * FROM leads ORDER BY datetime(updated_at) DESC LIMIT 60").all().map(rowLead).filter((l) => scoreLead(l).score >= 55).length,
    },
    seededAt: db.prepare("SELECT value FROM meta WHERE key='seeded_at'").get()?.value ?? null,
  });
}));

const pageCols = db.prepare("PRAGMA table_info(pages)").all().map((c) => c.name);
if (!pageCols.includes("theme")) db.exec("ALTER TABLE pages ADD COLUMN theme TEXT");

/* ---- P7/P8 Website CMS: typed block registry, pages, publish, versions ---- */
const BLOCKS = {
  "hero": { label: "Hero", group: "Marketing", fields: [
    { k: "kicker", t: "text" }, { k: "heading", t: "text", req: true }, { k: "sub", t: "textarea" },
    { k: "image", t: "image" }, { k: "cta_label", t: "text" }, { k: "cta_href", t: "text" } ] },
  "text-section": { label: "Text + points", group: "Content", fields: [
    { k: "kicker", t: "text" }, { k: "heading", t: "text", req: true }, { k: "body", t: "textarea" },
    { k: "bullets", t: "lines" }, { k: "image", t: "image" } ] },
  "product-grid": { label: "Product grid (live)", group: "Commerce", fields: [
    { k: "heading", t: "text" }, { k: "match", t: "text", help: "series / category / sku words" },
    { k: "ids", t: "text", help: "or exact ids, comma separated" }, { k: "limit", t: "text" } ] },
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
const rowSection = (r) => ({ id: r.id, name: r.name, category: r.category, tags: JSON.parse(r.tags || "[]"),
  block: JSON.parse(r.block), usage: sectionUsage(r.id), createdAt: r.created_at, updatedAt: r.updated_at });
app.get("/api/saved-sections", wrap((req, res) => {
  const rows = db.prepare("SELECT * FROM saved_sections ORDER BY datetime(updated_at) DESC").all();
  res.json({ total: rows.length, items: rows.map(rowSection) });
}));
app.post("/api/saved-sections", wrap((req, res) => {
  const { name, category, block } = req.body ?? {};
  if (!name || !block?.type || !BLOCKS[block.type]) return res.status(400).json({ error: "name + valid block required" });
  const t = now();
  const info = db.prepare("INSERT INTO saved_sections(name,category,tags,block,created_at,updated_at) VALUES(?,?,?,?,?,?)")
    .run(name, category || "Custom", JSON.stringify(req.body?.tags ?? []), JSON.stringify({ type: block.type, props: block.props ?? {} }), t, t);
  const out = rowSection(db.prepare("SELECT * FROM saved_sections WHERE id=?").get(info.lastInsertRowid));
  emit("sections", { title: "Section saved: " + name, who: category || "Custom" });
  res.status(201).json(out);
}));
app.patch("/api/saved-sections/:id", wrap((req, res) => {
  const r = db.prepare("SELECT * FROM saved_sections WHERE id=?").get(req.params.id);
  if (!r) return res.status(404).json({ error: "not found" });
  db.prepare("UPDATE saved_sections SET name=?, category=?, tags=?, block=?, updated_at=? WHERE id=?").run(
    req.body?.name ?? r.name, req.body?.category ?? r.category,
    req.body?.tags ? JSON.stringify(req.body.tags) : r.tags,
    req.body?.block ? JSON.stringify({ type: req.body.block.type, props: req.body.block.props ?? {} }) : r.block, now(), r.id);
  emit("sections", { title: "Section updated: " + r.name, who: "fans out to " + sectionUsage(r.id) + " page(s)" });
  res.json(rowSection(db.prepare("SELECT * FROM saved_sections WHERE id=?").get(r.id)));
}));
app.delete("/api/saved-sections/:id", wrap((req, res) => {
  const r = db.prepare("SELECT * FROM saved_sections WHERE id=?").get(req.params.id);
  if (!r) return res.status(404).json({ error: "not found" });
  const usage = sectionUsage(r.id);
  if (usage > 0) return res.status(409).json({ error: `in use on ${usage} page${usage > 1 ? "s" : ""} — detach references first` });
  db.prepare("DELETE FROM saved_sections WHERE id=?").run(r.id);
  res.json({ deleted: r.id });
}));

app.get("/api/blocks", (req, res) => res.json({ registry: BLOCKS }));
const MEDIA = () => fs.readdirSync(path.join(HERE, "public/img")).filter((f) => /\.(jpg|jpeg|png|webp)$/i.test(f));
app.get("/api/media", (req, res) => res.json({ items: MEDIA().slice(0, 96) }));

const rowPage = (r, blocks) => ({ id: r.id, slug: r.slug, title: r.title, status: r.status,
  seoTitle: r.seo_title, seoDesc: r.seo_desc, createdAt: r.created_at, updatedAt: r.updated_at,
  blocks: blocks ?? pageBlocks(r.id),
  theme: r.theme ? JSON.parse(r.theme) : null });
const SLUG_RE = /^[a-z0-9][a-z0-9-]{1,60}$/;
function validatePage(title, blocks) {
  const errs = [];
  if (!blocks.length) errs.push({ block: -1, msg: "Page has no blocks yet." });
  blocks.forEach((b, i) => {
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
      if (!sid || !db.prepare("SELECT id FROM saved_sections WHERE id=?").get(sid)) errs.push({ block: i, msg: "Global section points to a missing source (re-detach or pick a section)." }); }
  });
  if (!title || !title.trim()) errs.push({ block: -1, msg: "Page title is required." });
  return errs;
}

const rowBlock = (b) => { const props = JSON.parse(b.props || "{}");
  if (b.type === "global-section") { const sec = db.prepare("SELECT * FROM saved_sections WHERE id=?").get(Number(props.section_id));
    if (sec) return { type: b.type, props: { ...props, sectionLabel: sec.name, section: { type: JSON.parse(sec.block).type, props: JSON.parse(sec.block).props } } }; }
  return { type: b.type, props }; };
const pageBlocks = (pid) => db.prepare("SELECT type, props FROM page_blocks WHERE page_id=? ORDER BY sort").all(pid).map(rowBlock);
const sectionUsage = (sid) => db.prepare("SELECT COUNT(*) n FROM page_blocks WHERE type='global-section' AND props LIKE ?").get(`%"section_id":"${sid}"%`).n
  + db.prepare("SELECT COUNT(*) n FROM page_blocks WHERE type='global-section' AND props LIKE ?").get(`%"section_id":${sid}%`).n;
app.get("/api/pages", wrap((req, res) => {
  let rows = db.prepare("SELECT * FROM pages ORDER BY datetime(updated_at) DESC").all();
  if (req.query.status) rows = rows.filter((r) => r.status === req.query.status);
  res.json({ total: rows.length, published: rows.filter((r) => r.status === "Published").length,
    items: rows.map((r) => ({ ...rowPage(r, []), blockCount: db.prepare("SELECT COUNT(*) n FROM page_blocks WHERE page_id=?").get(r.id).n })) });
}));
app.post("/api/pages", wrap((req, res) => {
  const { slug, title, template } = req.body ?? {};
  if (!slug || !SLUG_RE.test(slug)) return res.status(400).json({ error: "slug must be lowercase letters/numbers/dashes (2-61)" });
  if (db.prepare("SELECT id FROM pages WHERE slug=?").get(slug)) return res.status(409).json({ error: "slug already exists" });
  const t = now();
  const info = db.prepare("INSERT INTO pages(slug,title,status,created_at,updated_at) VALUES(?,?,?,?,?)").run(slug, title || slug, "Draft", t, t);
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
  for (const [i, b] of ((packs[template] ?? []).entries())) db.prepare("INSERT INTO page_blocks(page_id,type,props,sort) VALUES(?,?,?,?)").run(pid, b.type, JSON.stringify(b.props), i);
  const r = db.prepare("SELECT * FROM pages WHERE id=?").get(pid);
  emit("pages", { title: "Page created: /" + r.slug, who: r.title });
  res.status(201).json(rowPage(r));
}));
app.get("/api/pages/:key", wrap((req, res) => {
  const r = db.prepare("SELECT * FROM pages WHERE id=? OR slug=?").get(req.params.key, req.params.key);
  if (!r) return res.status(404).json({ error: "page not found" });
  res.json(rowPage(r));
}));
app.get("/api/pages/:key/public", wrap((req, res) => {
  const r = db.prepare("SELECT * FROM pages WHERE slug=?").get(req.params.key);
  if (!r) return res.status(404).json({ error: "no page at that address" });
  const wantDraft = req.query.draft === "1";
  if (r.status !== "Published" && !wantDraft) return res.status(404).json({ error: "page is not published" });
  res.json({ slug: r.slug, title: r.title, status: r.status, seoTitle: r.seo_title, seoDesc: r.seo_desc,
    publishedAt: r.updated_at,
    blocks: pageBlocks(r.id), theme: r.theme ? JSON.parse(r.theme) : null });
}));
app.put("/api/pages/:id/blocks", wrap((req, res) => {
  const r = db.prepare("SELECT * FROM pages WHERE id=?").get(req.params.id);
  if (!r) return res.status(404).json({ error: "not found" });
  const blocks = Array.isArray(req.body?.blocks) ? req.body.blocks : [];
  db.prepare("DELETE FROM page_blocks WHERE page_id=?").run(r.id);
  blocks.forEach((b, i) => db.prepare("INSERT INTO page_blocks(page_id,type,props,sort) VALUES(?,?,?,?)")
    .run(r.id, String(b.type), JSON.stringify(b.props ?? {}), i));
  const { title, seoTitle, seoDesc, theme } = req.body ?? {};
  db.prepare("UPDATE pages SET title=COALESCE(?,title), seo_title=COALESCE(?,seo_title), seo_desc=COALESCE(?,seo_desc), theme=COALESCE(?,theme), updated_at=? WHERE id=?")
    .run(title ?? null, seoTitle ?? null, seoDesc ?? null, theme ? JSON.stringify(theme) : null, now(), r.id);
  res.json({ ...rowPage(db.prepare("SELECT * FROM pages WHERE id=?").get(r.id)),
    validation: validatePage(req.body?.title ?? r.title, blocks) });
}));
app.post("/api/pages/:id/publish", wrap((req, res) => {
  const r = db.prepare("SELECT * FROM pages WHERE id=?").get(req.params.id);
  if (!r) return res.status(404).json({ error: "not found" });
  const blocks = db.prepare("SELECT type, props FROM page_blocks WHERE page_id=? ORDER BY sort").all(r.id).map((b) => ({ type: b.type, props: JSON.parse(b.props || "{}") }));
  const errs = validatePage(req.body?.title ?? r.title, blocks);
  if (errs.length) return res.status(422).json({ error: "validation failed", validation: errs });
  const t = now();
  db.prepare("INSERT INTO page_versions(page_id,snapshot,note,created_at) VALUES(?,?,?,?)")
    .run(r.id, JSON.stringify({ blocks, seo: { title: r.seo_title, desc: r.seo_desc } }), req.body?.note ?? "published", t);
  db.prepare("UPDATE pages SET status='Published', updated_at=? WHERE id=?").run(t, r.id);
  emit("pages", { title: "Page published: /" + r.slug, who: r.title });
  res.json({ ...rowPage(db.prepare("SELECT * FROM pages WHERE id=?").get(r.id)), versions: db.prepare("SELECT COUNT(*) n FROM page_versions WHERE page_id=?").get(r.id).n });
}));
app.post("/api/pages/:id/unpublish", wrap((req, res) => {
  const r = db.prepare("SELECT * FROM pages WHERE id=?").get(req.params.id);
  if (!r) return res.status(404).json({ error: "not found" });
  db.prepare("UPDATE pages SET status='Draft', updated_at=? WHERE id=?").run(now(), r.id);
  emit("pages", { title: "Page unpublished: /" + r.slug, who: r.title });
  res.json(rowPage(db.prepare("SELECT * FROM pages WHERE id=?").get(r.id)));
}));
app.get("/api/pages/:id/versions", wrap((req, res) => {
  const rows = db.prepare("SELECT id, note, who, created_at, snapshot FROM page_versions WHERE page_id=? ORDER BY id DESC").all(req.params.id);
  res.json({ items: rows.map((v) => ({ id: v.id, note: v.note, who: v.who, createdAt: v.created_at,
    blocks: JSON.parse(v.snapshot).blocks.length })) });
}));
app.post("/api/pages/:id/rollback", wrap((req, res) => {
  const v = db.prepare("SELECT * FROM page_versions WHERE id=? AND page_id=?").get(req.body?.version_id, req.params.id);
  if (!v) return res.status(404).json({ error: "version not found" });
  const snap = JSON.parse(v.snapshot);
  db.prepare("DELETE FROM page_blocks WHERE page_id=?").run(req.params.id);
  (snap.blocks ?? []).forEach((b, i) => db.prepare("INSERT INTO page_blocks(page_id,type,props,sort) VALUES(?,?,?,?)")
    .run(req.params.id, b.type, JSON.stringify(b.props ?? {}), i));
  db.prepare("UPDATE pages SET updated_at=? WHERE id=?").run(now(), req.params.id);
  emit("pages", { title: "Version rolled back on page " + req.params.id, who: "You" });
  res.json(rowPage(db.prepare("SELECT * FROM pages WHERE id=?").get(req.params.id)));
}));

function ensureSiteDemo() {
  if (db.prepare("SELECT COUNT(*) n FROM pages").get().n > 0) return;
  const t = now();
  const info = db.prepare("INSERT INTO pages(slug,title,status,seo_title,seo_desc,created_at,updated_at) VALUES(?,?,?,?,?,?,?)")
    .run("ramadan-workspace-sale", "Ramadan Workspace Sale", "Published",
      "Ramadan Workspace Sale — Woodex Furniture", "Up to 30% off desks & ergonomic chairs. Quotation + installation in 48h.", t, t);
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
  for (const [i, b] of demo.entries()) db.prepare("INSERT INTO page_blocks(page_id,type,props,sort) VALUES(?,?,?,?)").run(pid, b.type, JSON.stringify(b.props), i);
  const si = db.prepare("INSERT INTO saved_sections(name,category,tags,block,created_at,updated_at) VALUES(?,?,?,?,?,?)")
    .run("Trust strip", "Social proof", JSON.stringify(["woodex", "reusable"]),
      JSON.stringify({ type: "text-section", props: { kicker: "Why Woodex", heading: "12 years · 4,100+ rooms · 5-year frame warranty", body: "Own Johar Town workshop, seasoned Sheesham & Grade-A hardware, delivery with customer sign-off checklist.", bullets: "4.9 average rating across 860 reviews\nFactory-direct pricing, no showroom loading\nFree 3D preview for bulk orders" } }), t, t);
  const globalRef = { type: "global-section", props: { section_id: String(si.lastInsertRowid) } };
  db.prepare("INSERT INTO page_blocks(page_id,type,props,sort) VALUES(?,?,?,?)").run(pid, globalRef.type, JSON.stringify(globalRef.props), 1);
  const shifted = db.prepare("SELECT id, sort FROM page_blocks WHERE page_id=? AND sort>=1 ORDER BY sort DESC").all(pid);
  for (const row of shifted) db.prepare("UPDATE page_blocks SET sort=sort+1 WHERE id=?").run(row.id);
  db.prepare("INSERT INTO page_versions(page_id,snapshot,note,who,created_at) VALUES(?,?,?,?,?)").run(pid, JSON.stringify({ blocks: demo }), "seeded", "System", t);
  console.log("[seed] site demo: 1 published landing page (7 blocks)");
}

/* Shared imagery */
app.use("/img", express.static(path.join(HERE, "public/img"), { maxAge: "1h" }));

const PORT = process.env.PORT ? Number(process.env.PORT) : 3001;
await seed();
ensureFinanceDemo();
ensureCrmDemo();
ensureSiteDemo();
app.listen(PORT, "0.0.0.0", () => console.log(`[woodex-api] http://localhost:${PORT} · db=data/woodex.db`));

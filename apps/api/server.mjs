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
  emit("quotes", { title: "Quotation " + ref + " requested", who: customer }); res.status(201).json(rowQuote(db.prepare("SELECT * FROM quotes WHERE id=?").get(info.lastInsertRowid)));
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
  if (status) emit("quotes", { title: "Quotation " + r.ref + " → " + status, who: r.customer });
  res.json(out);
}));

/* Leads — contact form → CRM */
app.get("/api/leads", wrap((req, res) => {
  const rows = db.prepare("SELECT * FROM leads ORDER BY datetime(created_at) DESC").all();
  res.json({ total: rows.length, items: rows.map(rowLead) });
}));
app.post("/api/leads", wrap((req, res) => {
  const { name, company, interest, source = "Website", contact, note } = req.body ?? {};
  if (!name) return res.status(400).json({ error: "name is required" });
  const t = now();
  const ref = "L-" + (1100 + db.prepare("SELECT COUNT(*) n FROM leads").get().n);
  const info = db.prepare("INSERT INTO leads(ref,name,company,interest,source,contact,status,note,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?)")
    .run(ref, name, company ?? null, interest ?? "General inquiry", source, contact ?? null, "New", note ?? null, t, t);
  emit("leads", { title: "New lead " + name, who: name, context: interest ?? "" }); res.status(201).json(rowLead(db.prepare("SELECT * FROM leads WHERE id=?").get(info.lastInsertRowid)));
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
  emit("orders", { title: "Order " + ref + " placed", who: customer }); res.status(201).json(rowOrder(db.prepare("SELECT * FROM orders WHERE id=?").get(info.lastInsertRowid)));
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
/* ---- P3 realtime: Server-Sent Events bus ---- */
const sseClients = new Set();
const emit = (type, payload = {}) => {
  const json = JSON.stringify({ type, at: now(), ...payload });
  for (const c of sseClients) { try { c.write(`event: ${type}\ndata: ${json}\n\n`); } catch { sseClients.delete(c); } }
};
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
    seededAt: db.prepare("SELECT value FROM meta WHERE key='seeded_at'").get()?.value ?? null,
  });
}));

/* Shared imagery */
app.use("/img", express.static(path.join(HERE, "public/img"), { maxAge: "1h" }));

const PORT = process.env.PORT ? Number(process.env.PORT) : 3001;
await seed();
ensureFinanceDemo();
app.listen(PORT, "0.0.0.0", () => console.log(`[woodex-api] http://localhost:${PORT} · db=data/woodex.db`));

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
  db.prepare("INSERT INTO meta(key,value) VALUES('seeded_at',?)").run(now());
  console.log(`[seed] ${products.length} products, ${materials.length} materials, ${services.length} services, ${seedLeads.length} leads, ${seedQuotes.length} quotes, 8 orders`);
}

/* ---------------- helpers ---------------- */
const rowProduct = (r) => ({ ...JSON.parse(r.json), price: r.price, inStock: !!r.in_stock, stockQty: r.stock_qty, updatedAt: r.updated_at });
const rowQuote = (r) => ({ id: r.id, ref: r.ref, customer: r.customer, contact: r.contact, items: JSON.parse(r.items), total: r.total, status: r.status, source: r.source, note: r.note, audit: JSON.parse(r.audit || "[]"), createdAt: r.created_at, updatedAt: r.updated_at });
const rowLead = (r) => ({ id: r.id, ref: r.ref, name: r.name, interest: r.interest, source: r.source, contact: r.contact, status: r.status, owner: r.owner, note: r.note, createdAt: r.created_at, updatedAt: r.updated_at });
const rowOrder = (r) => ({ id: r.id, ref: r.ref, customer: r.customer, items: JSON.parse(r.items), total: r.total, status: r.status, stage: r.stage, owner: r.owner, due: r.due, createdAt: r.created_at, updatedAt: r.updated_at });
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
  res.status(201).json(rowQuote(db.prepare("SELECT * FROM quotes WHERE id=?").get(info.lastInsertRowid)));
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
  res.json(rowQuote(db.prepare("SELECT * FROM quotes WHERE id=?").get(r.id)));
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
  res.status(201).json(rowLead(db.prepare("SELECT * FROM leads WHERE id=?").get(info.lastInsertRowid)));
}));
app.patch("/api/leads/:id", wrap((req, res) => {
  const r = db.prepare("SELECT * FROM leads WHERE id=? OR ref=?").get(req.params.id, req.params.id);
  if (!r) return res.status(404).json({ error: "not found" });
  const { status, owner } = req.body ?? {};
  db.prepare("UPDATE leads SET status=?, owner=?, updated_at=? WHERE id=?").run(status ?? r.status, owner ?? r.owner, now(), r.id);
  res.json(rowLead(db.prepare("SELECT * FROM leads WHERE id=?").get(r.id)));
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
  res.status(201).json(rowOrder(db.prepare("SELECT * FROM orders WHERE id=?").get(info.lastInsertRowid)));
}));
app.patch("/api/orders/:id", wrap((req, res) => {
  const r = db.prepare("SELECT * FROM orders WHERE id=? OR ref=?").get(req.params.id, req.params.id);
  if (!r) return res.status(404).json({ error: "not found" });
  const { status, stage, owner, due } = req.body ?? {};
  db.prepare("UPDATE orders SET status=?, stage=?, owner=?, due=?, updated_at=? WHERE id=?").run(
    status ?? r.status, stage ?? r.stage, owner ?? r.owner, due ?? r.due, now(), r.id
  );
  res.json(rowOrder(db.prepare("SELECT * FROM orders WHERE id=?").get(r.id)));
}));

/* Dashboard rollup */
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
    recent: [...rec("leads", "lead"), ...rec("quotes", "quote"), ...rec("orders", "production")].slice(0, 6),
    seededAt: db.prepare("SELECT value FROM meta WHERE key='seeded_at'").get()?.value ?? null,
  });
}));

/* Shared imagery */
app.use("/img", express.static(path.join(HERE, "public/img"), { maxAge: "1h" }));

const PORT = process.env.PORT ? Number(process.env.PORT) : 3001;
await seed();
app.listen(PORT, "0.0.0.0", () => console.log(`[woodex-api] http://localhost:${PORT} · db=data/woodex.db`));

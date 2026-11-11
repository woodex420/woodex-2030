/**
 * WOODEX platform API — single runtime source of truth for storefront + dashboard.
 * Express + async DB facade (SQLite default; PostgreSQL via DATABASE_URL).
 *
 *   GET  /api/health                 status + row counts
 *   GET  /api/products[?q&sub&limit&offset]  catalog (price/stock edits merged)
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
  /* Make the misconfiguration diagnosable from the outside. A bare thrown error
     reaches the browser only as FUNCTION_INVOCATION_FAILED, which says nothing
     about the missing setting — that is exactly how this deployment shipped
     "successfully" while every /api/* call was a 500. */
  const reason = "DATABASE_URL is required for Vercel/production; SQLite is local-development only.";
  console.error(`[woodex-api] fatal: ${reason} Add it in Vercel → Project → Settings → Environment Variables, then run \`DATABASE_URL=... npm run db:apply-schema\` once.`);
  globalThis.__WOODEX_FATAL_CONFIG__ = reason;
  throw Object.assign(new Error(reason), { code: "WOODEX_MISSING_DATABASE_URL", statusCode: 503, expose: true });
}
const DB_DIR = process.env.WOODEX_DATA_DIR ? path.resolve(process.env.WOODEX_DATA_DIR) : path.join(ROOT, "data");
if (!DATABASE_URL) fs.mkdirSync(DB_DIR, { recursive: true });
const { createDb } = await import("./db-adapters.mjs");
const db = await createDb({ sqliteFile: path.join(DB_DIR, "woodex.db"), databaseUrl: DATABASE_URL });
console.log("[woodex-api] driver=" + db.kind);

const now = () => new Date().toISOString();
/* Timestamps arrive from Postgres as Date objects and from SQLite as strings.
   Two bugs follow from forgetting that, and both hit production only:
   - writing one back raw serialises as "Tue Nov 10 2026 …", which Postgres
     rejects (4276) while SQLite accepts;
   - comparing String(date) against an ISO string compares "Tue…" with "2026…",
     so an expiry check is silently always-true and a timed-out token lives on.
   `iso` normalises for both purposes. */
const iso = (v) => (v === null || v === undefined ? null : v instanceof Date ? v.toISOString() : String(v));
/* A sitemap <lastmod> is a calendar date. String(Date).slice(0,10) yields
   "Tue Nov 0" on Postgres, producing a sitemap crawlers silently drop. */
const isoDay = (v) => { const ms = ts(v); return Number.isFinite(ms) ? new Date(ms).toISOString().slice(0, 10) : ""; };
const ts = (v) => (v === null || v === undefined ? NaN : v instanceof Date ? v.getTime() : Date.parse(String(v)));
const fmtMoney = (n) => "PKR " + Math.round(n).toLocaleString("en-PK");

/* ---- pre-DDL column migrations ----
   011 adds stock_movements.idem_key, and 011's unique index — created in the
   DDL immediately below — references it. Putting the column in the CREATE
   TABLE statement is not enough: that statement is IF NOT EXISTS, so on a
   database created before this change it does nothing, and the CREATE INDEX
   then fails with "no such column". The API would not start, which is exactly
   what happened on any machine holding an older woodex.db.
   So the column is added here, before the DDL, guarded by a pragma probe
   (SQLite has no ADD COLUMN IF NOT EXISTS.)

   Both halves are needed and neither is redundant: the column is declared in
   the CREATE TABLE below so a NEW database is born with it, and added here so
   an EXISTING one acquires it. `cols.length` distinguishes the two — an empty
   pragma result means the table does not exist yet and the CREATE will handle
   it. */
if (db.kind === "sqlite") {
  const cols = await db.prepare("PRAGMA table_info(stock_movements)").all();
  if (cols.length && !cols.some((c) => c.name === "idem_key")) {
    await db.exec("ALTER TABLE stock_movements ADD COLUMN idem_key TEXT");
    console.log("[woodex-api] migrated stock_movements.idem_key (011)");
  }
  /* An earlier revision of 011 created this index with the wrong predicate —
     one movement per material line — which forbids the partial-issue-then-top-up
     that a real build needs. IF NOT EXISTS never removes it, so a database that
     ran that revision would keep enforcing the stricter rule. Dropped explicitly
     so every database converges on the same rule. */
  await db.exec("DROP INDEX IF EXISTS uq_stock_movements_production_issue");

  /* 012 adds stock_movements.location_id and stock_reservations.released_at.
     Same trap as 011's idem_key: the CREATE TABLE below is IF NOT EXISTS, so a
     database that already has these tables never sees the new columns, and the
     index / route that references them fails at runtime rather than at startup.
     Guarded by a pragma probe because SQLite has no ADD COLUMN IF NOT EXISTS. */
  const moveCols = await db.prepare("PRAGMA table_info(stock_movements)").all();
  if (moveCols.length && !moveCols.some((c) => c.name === "location_id")) {
    await db.exec("ALTER TABLE stock_movements ADD COLUMN location_id INTEGER");
    console.log("[woodex-api] migrated stock_movements.location_id (012)");
  }
  /* 014 adds company_id to leads and clients. Same trap as 011 and 012: the
     CREATE TABLE is IF NOT EXISTS, so an existing database never gains the
     column and the CREATE INDEX that follows fails at boot. A FRESH database
     has no table yet (zero columns) and gets the column from the CREATE, so
     the ALTER is gated on the table already existing. */
  for (const table of ["leads", "clients"]) {
    const cols = await db.prepare(`PRAGMA table_info(${table})`).all();
    if (cols.length && !cols.some((c) => c.name === "company_id")) {
      await db.exec(`ALTER TABLE ${table} ADD COLUMN company_id INTEGER`);
      console.log(`[woodex-api] migrated ${table}.company_id (014)`);
    }
  }
  const resCols = await db.prepare("PRAGMA table_info(stock_reservations)").all();
  if (resCols.length && !resCols.some((c) => c.name === "released_at")) {
    await db.exec("ALTER TABLE stock_reservations ADD COLUMN released_at TEXT");
    console.log("[woodex-api] migrated stock_reservations.released_at (012)");
  }
}

/* ---------------- schema ---------------- */
if (db.kind === "sqlite") await db.exec(`
CREATE TABLE IF NOT EXISTS products(
  id TEXT PRIMARY KEY, json TEXT NOT NULL, price REAL NOT NULL,
  in_stock INTEGER NOT NULL DEFAULT 1, stock_qty INTEGER NOT NULL DEFAULT 99,
  updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS materials(id TEXT PRIMARY KEY, json TEXT NOT NULL);
CREATE TABLE IF NOT EXISTS services(id TEXT PRIMARY KEY, json TEXT NOT NULL);

/* Stock ledger — mirror of scripts/migrations/003_stock_ledger.sql.
   SQLite does not enforce the CHECK/FK set the way PostgreSQL does, but the
   shape must match so pg-migrate can copy every column and the reconciliation
   test runs identically on both engines. */
CREATE TABLE IF NOT EXISTS stock_movements(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  product_id TEXT NOT NULL, delta INTEGER NOT NULL CHECK (delta <> 0),
  reason TEXT NOT NULL, ref_type TEXT, ref_id INTEGER, note TEXT,
  created_by TEXT, created_at TEXT NOT NULL, idem_key TEXT, location_id INTEGER
);
CREATE INDEX IF NOT EXISTS idx_stock_movements_product ON stock_movements(product_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_stock_movements_ref ON stock_movements(ref_type, ref_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_stock_movements_sale_ref
  ON stock_movements(ref_type, ref_id, product_id)
  WHERE reason = 'sale' AND ref_type IS NOT NULL AND ref_id IS NOT NULL;
CREATE TABLE IF NOT EXISTS stock_reservations(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  product_id TEXT NOT NULL, qty INTEGER NOT NULL CHECK (qty > 0),
  ref_type TEXT NOT NULL, ref_id INTEGER, status TEXT NOT NULL DEFAULT 'held',
  expires_at TEXT, created_at TEXT NOT NULL, released_at TEXT
);
CREATE INDEX IF NOT EXISTS idx_stock_reservations_product ON stock_reservations(product_id, status);
CREATE INDEX IF NOT EXISTS idx_stock_reservations_live ON stock_reservations(product_id, status) WHERE status = 'held';

/* Inventory depth — mirror of scripts/migrations/012_warehouses.sql.
   Column sets must match the PostgreSQL definitions name-for-name; the same
   rule as the procurement block below. Warehouse → location is a real parent-
   child relation, and a location's warehouse is the only thing that decides
   which warehouse a movement belongs to. */
CREATE TABLE IF NOT EXISTS warehouses(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, city TEXT, address TEXT,
  manager TEXT, phone TEXT, is_default INTEGER NOT NULL DEFAULT 0, active INTEGER NOT NULL DEFAULT 1,
  notes TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
  CHECK (is_default IN (0, 1)), CHECK (active IN (0, 1))
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_warehouses_single_default ON warehouses(is_default) WHERE is_default = 1;
CREATE TABLE IF NOT EXISTS storage_locations(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  warehouse_id INTEGER NOT NULL, code TEXT NOT NULL, name TEXT NOT NULL,
  zone TEXT, active INTEGER NOT NULL DEFAULT 1, notes TEXT,
  created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
  UNIQUE (warehouse_id, code)
);
CREATE INDEX IF NOT EXISTS idx_storage_locations_warehouse ON storage_locations(warehouse_id, active);
CREATE INDEX IF NOT EXISTS idx_stock_movements_location ON stock_movements(location_id, id DESC);

/* stock_levels is a PROJECTION over the ledger, not a source of truth — same
   discipline as products.stock_qty. Sum of a product's levels must equal its
   stock_qty; that invariant is asserted by the test suite, not assumed. */
CREATE TABLE IF NOT EXISTS stock_levels(
  product_id TEXT NOT NULL, location_id INTEGER NOT NULL, qty INTEGER NOT NULL DEFAULT 0,
  updated_at TEXT NOT NULL, PRIMARY KEY (product_id, location_id)
);
CREATE INDEX IF NOT EXISTS idx_stock_levels_location ON stock_levels(location_id);

/* A transfer is two ledger rows (-src, +dst) tied to one document. The status
   vocabulary keeps 'in_transit' honest: goods in a truck are at neither end. */
CREATE TABLE IF NOT EXISTS stock_transfers(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ref TEXT NOT NULL UNIQUE, from_location_id INTEGER NOT NULL, to_location_id INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','dispatched','received','cancelled')),
  note TEXT, created_by TEXT, dispatched_at TEXT, received_at TEXT,
  created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
  CHECK (from_location_id <> to_location_id),
  CHECK (received_at IS NULL OR dispatched_at IS NOT NULL)
);
CREATE INDEX IF NOT EXISTS idx_stock_transfers_status ON stock_transfers(status, created_at DESC);
CREATE TABLE IF NOT EXISTS stock_transfer_lines(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  transfer_id INTEGER NOT NULL, product_id TEXT NOT NULL,
  qty INTEGER NOT NULL CHECK (qty > 0),
  UNIQUE (transfer_id, product_id)
);
CREATE INDEX IF NOT EXISTS idx_stock_transfer_lines_transfer ON stock_transfer_lines(transfer_id);
/* Finance depth — mirror of scripts/migrations/013_finance.sql.
   The PostgreSQL version guards journal immutability with triggers, because a
   route that simply does not expose an UPDATE is a policy, not a constraint.
   SQLite has triggers too, and the same two are created here so local
   development cannot quietly allow what production refuses. */
CREATE TABLE IF NOT EXISTS accounts(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, kind TEXT NOT NULL,
  active INTEGER NOT NULL DEFAULT 1, notes TEXT,
  created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
  CHECK (kind IN ('asset','liability','equity','revenue','expense')),
  CHECK (active IN (0,1))
);
CREATE TABLE IF NOT EXISTS journal_entries(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT NOT NULL UNIQUE, entry_date TEXT NOT NULL,
  memo TEXT, source_type TEXT, source_id INTEGER, reverses_id INTEGER,
  posted_by TEXT, created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_journal_entries_date ON journal_entries(entry_date, id);
CREATE INDEX IF NOT EXISTS idx_journal_entries_source ON journal_entries(source_type, source_id);
CREATE TABLE IF NOT EXISTS journal_lines(
  id INTEGER PRIMARY KEY AUTOINCREMENT, entry_id INTEGER NOT NULL, account_id INTEGER NOT NULL,
  debit REAL NOT NULL DEFAULT 0, credit REAL NOT NULL DEFAULT 0, description TEXT,
  CHECK ((debit > 0 AND credit = 0) OR (credit > 0 AND debit = 0)),
  CHECK (debit >= 0 AND credit >= 0)
);
CREATE INDEX IF NOT EXISTS idx_journal_lines_entry ON journal_lines(entry_id);
CREATE INDEX IF NOT EXISTS idx_journal_lines_account ON journal_lines(account_id);
CREATE TRIGGER IF NOT EXISTS trg_journal_lines_no_update
  BEFORE UPDATE ON journal_lines
BEGIN
  SELECT RAISE(ABORT, 'journal lines are immutable: post a reversing entry instead');
END;
CREATE TRIGGER IF NOT EXISTS trg_journal_lines_no_delete
  BEFORE DELETE ON journal_lines
BEGIN
  SELECT RAISE(ABORT, 'journal lines are immutable: post a reversing entry instead');
END;
CREATE TRIGGER IF NOT EXISTS trg_journal_entries_stable
  BEFORE UPDATE ON journal_entries
  WHEN NEW.entry_date <> OLD.entry_date
    OR COALESCE(NEW.source_type,'') <> COALESCE(OLD.source_type,'')
    OR COALESCE(NEW.source_id,-1) <> COALESCE(OLD.source_id,-1)
BEGIN
  SELECT RAISE(ABORT, 'an entry date or source cannot be changed: post a reversal instead');
END;
CREATE TABLE IF NOT EXISTS credit_notes(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT NOT NULL UNIQUE, invoice_id INTEGER,
  customer TEXT NOT NULL, reason TEXT, subtotal REAL NOT NULL DEFAULT 0,
  tax REAL NOT NULL DEFAULT 0, total REAL NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'draft', issued_at TEXT, applied_at TEXT,
  journal_entry_id INTEGER, note TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
  CHECK (status IN ('draft','issued','applied','cancelled')),
  CHECK (total >= 0)
);
CREATE INDEX IF NOT EXISTS idx_credit_notes_invoice ON credit_notes(invoice_id);
CREATE INDEX IF NOT EXISTS idx_credit_notes_status ON credit_notes(status, created_at DESC);
CREATE TABLE IF NOT EXISTS expenses(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT NOT NULL UNIQUE, category TEXT NOT NULL,
  payee TEXT, amount REAL NOT NULL, tax_amount REAL NOT NULL DEFAULT 0, spent_at TEXT NOT NULL,
  method TEXT, reference TEXT, account_id INTEGER, journal_entry_id INTEGER, note TEXT,
  created_by TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
  CHECK (amount > 0), CHECK (tax_amount >= 0)
);
CREATE INDEX IF NOT EXISTS idx_expenses_spent ON expenses(spent_at DESC);
CREATE INDEX IF NOT EXISTS idx_expenses_category ON expenses(category, spent_at DESC);
CREATE TABLE IF NOT EXISTS tax_records(
  id INTEGER PRIMARY KEY AUTOINCREMENT, kind TEXT NOT NULL, source_type TEXT NOT NULL,
  source_id INTEGER, tax_period TEXT NOT NULL, rate REAL NOT NULL DEFAULT 0,
  taxable_base REAL NOT NULL DEFAULT 0, tax_amount REAL NOT NULL DEFAULT 0, created_at TEXT NOT NULL,
  CHECK (kind IN ('sales','purchase','withholding')),
  CHECK (rate >= 0 AND rate <= 1),
  CHECK (taxable_base >= 0 AND tax_amount >= 0)
);
CREATE INDEX IF NOT EXISTS idx_tax_records_period ON tax_records(tax_period, kind);
CREATE TABLE IF NOT EXISTS payment_schedules(
  id INTEGER PRIMARY KEY AUTOINCREMENT, invoice_id INTEGER NOT NULL, seq INTEGER NOT NULL,
  due_date TEXT NOT NULL, amount REAL NOT NULL, paid REAL NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'pending', note TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
  CHECK (status IN ('pending','paid','waived')), CHECK (amount > 0), CHECK (paid >= 0),
  UNIQUE (invoice_id, seq)
);
CREATE INDEX IF NOT EXISTS idx_payment_schedules_due ON payment_schedules(due_date, status);
/* CRM depth — mirror of scripts/migrations/014_crm.sql.
   companies and contacts replace the free-text company and contact fields as
   the place a person actually lives. activities is the append-only timeline,
   guarded here by the same triggers production uses, so a local database
   cannot do what PostgreSQL refuses. */
CREATE TABLE IF NOT EXISTS companies(
  id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL, name_norm TEXT NOT NULL UNIQUE,
  industry TEXT, website TEXT, phone TEXT, email TEXT, tax_ref TEXT, city TEXT, address TEXT,
  account_owner TEXT, notes TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
  CHECK (trim(name) <> ''), CHECK (trim(name_norm) <> ''));
CREATE INDEX IF NOT EXISTS idx_companies_owner ON companies(account_owner);
CREATE INDEX IF NOT EXISTS idx_companies_city ON companies(city);
CREATE TABLE IF NOT EXISTS contacts(
  id INTEGER PRIMARY KEY AUTOINCREMENT, company_id INTEGER, client_id INTEGER,
  name TEXT NOT NULL, email TEXT, email_norm TEXT, phone TEXT, phone_norm TEXT,
  role TEXT, is_primary INTEGER NOT NULL DEFAULT 0, notes TEXT,
  created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
  CHECK (trim(name) <> ''), CHECK (is_primary IN (0,1)));
CREATE UNIQUE INDEX IF NOT EXISTS uq_contacts_email
  ON contacts(email_norm) WHERE email_norm IS NOT NULL AND email_norm <> '';
CREATE INDEX IF NOT EXISTS idx_contacts_company ON contacts(company_id, is_primary DESC);
CREATE INDEX IF NOT EXISTS idx_contacts_client ON contacts(client_id);
CREATE TABLE IF NOT EXISTS meetings(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT NOT NULL UNIQUE, title TEXT NOT NULL,
  company_id INTEGER, client_id INTEGER, lead_id INTEGER, starts_at TEXT NOT NULL,
  minutes INTEGER NOT NULL DEFAULT 30, location TEXT, channel TEXT NOT NULL DEFAULT 'in-person',
  owner TEXT, status TEXT NOT NULL DEFAULT 'scheduled', attendees TEXT, agenda TEXT, outcome TEXT,
  created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
  CHECK (status IN ('scheduled','held','cancelled','no_show')),
  CHECK (channel IN ('in-person','phone','video')),
  CHECK (minutes > 0), CHECK (trim(title) <> ''));
CREATE INDEX IF NOT EXISTS idx_meetings_starts ON meetings(starts_at, status);
CREATE INDEX IF NOT EXISTS idx_meetings_owner ON meetings(owner, starts_at DESC);
CREATE INDEX IF NOT EXISTS idx_meetings_company ON meetings(company_id);
CREATE TABLE IF NOT EXISTS attachments(
  id INTEGER PRIMARY KEY AUTOINCREMENT, entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL,
  filename TEXT NOT NULL, mime_type TEXT, size_bytes INTEGER, path TEXT, checksum TEXT,
  uploaded_by TEXT, notes TEXT, created_at TEXT NOT NULL,
  CHECK (trim(filename) <> ''), CHECK (size_bytes IS NULL OR size_bytes >= 0));
CREATE INDEX IF NOT EXISTS idx_attachments_entity ON attachments(entity_type, entity_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_attachments_checksum_per_entity
  ON attachments(entity_type, entity_id, checksum) WHERE checksum IS NOT NULL;
CREATE TABLE IF NOT EXISTS activities(
  id INTEGER PRIMARY KEY AUTOINCREMENT, entity_type TEXT NOT NULL, entity_id INTEGER NOT NULL,
  kind TEXT NOT NULL, summary TEXT NOT NULL, detail TEXT, actor TEXT,
  occurred_at TEXT NOT NULL, created_at TEXT NOT NULL,
  CHECK (trim(summary) <> ''),
  CHECK (kind IN ('call','meeting','note','email','status_change','document','payment','system')));
CREATE INDEX IF NOT EXISTS idx_activities_entity ON activities(entity_type, entity_id, occurred_at DESC);
CREATE INDEX IF NOT EXISTS idx_activities_occurred ON activities(occurred_at DESC);
CREATE TRIGGER IF NOT EXISTS trg_activities_no_update
  BEFORE UPDATE ON activities
BEGIN
  SELECT RAISE(ABORT, 'activities are append-only: a history that can be edited is not a history');
END;
CREATE TRIGGER IF NOT EXISTS trg_activities_no_delete
  BEFORE DELETE ON activities
BEGIN
  SELECT RAISE(ABORT, 'activities are append-only: a history that can be edited is not a history');
END;

/* Delivery and installation — mirror of scripts/migrations/015_delivery.sql.
   dispatch_runs are trips; deliveries are arrivals with proof; delivery_lines
   say what each arrival carried; installed_assets are the objects in the
   building, with a warranty clock that starts when they were installed. */
CREATE TABLE IF NOT EXISTS dispatch_runs(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT NOT NULL UNIQUE, vehicle TEXT, driver TEXT,
  crew TEXT, address TEXT, city TEXT, scheduled_for TEXT, departed_at TEXT, returned_at TEXT,
  status TEXT NOT NULL DEFAULT 'planned', notes TEXT,
  created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
  CHECK (status IN ('planned','dispatched','completed','cancelled')),
  CHECK (returned_at IS NULL OR departed_at IS NULL OR returned_at >= departed_at));
CREATE INDEX IF NOT EXISTS idx_dispatch_runs_status ON dispatch_runs(status, scheduled_for);
CREATE TABLE IF NOT EXISTS deliveries(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT NOT NULL UNIQUE, order_id INTEGER,
  project_id INTEGER, dispatch_run_id INTEGER, customer TEXT NOT NULL, address TEXT,
  delivered_at TEXT, received_by TEXT, signed_by TEXT, photo TEXT,
  condition TEXT NOT NULL DEFAULT 'good', damage_notes TEXT,
  status TEXT NOT NULL DEFAULT 'pending', notes TEXT,
  created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
  CHECK (status IN ('pending','in_transit','delivered','failed','returned')),
  CHECK (condition IN ('good','damaged','partial','rejected')),
  CHECK (trim(customer) <> ''));
CREATE INDEX IF NOT EXISTS idx_deliveries_order ON deliveries(order_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_deliveries_project ON deliveries(project_id);
CREATE INDEX IF NOT EXISTS idx_deliveries_status ON deliveries(status, delivered_at DESC);
CREATE TABLE IF NOT EXISTS delivery_lines(
  id INTEGER PRIMARY KEY AUTOINCREMENT, delivery_id INTEGER NOT NULL, product_id TEXT,
  description TEXT NOT NULL, qty INTEGER NOT NULL, unit TEXT, serial TEXT,
  CHECK (qty > 0), CHECK (trim(description) <> ''));
CREATE INDEX IF NOT EXISTS idx_delivery_lines_delivery ON delivery_lines(delivery_id);
CREATE TABLE IF NOT EXISTS installed_assets(
  id INTEGER PRIMARY KEY AUTOINCREMENT, tag TEXT NOT NULL UNIQUE, order_id INTEGER,
  project_id INTEGER, delivery_id INTEGER, product_id TEXT, name TEXT NOT NULL,
  location TEXT, room TEXT, serial TEXT, installed_on TEXT, installed_by TEXT,
  warranty_months INTEGER, warranty_until TEXT, status TEXT NOT NULL DEFAULT 'installed',
  notes TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
  CHECK (trim(name) <> ''), CHECK (trim(tag) <> ''),
  CHECK (status IN ('installed','removed','replaced','faulty')),
  CHECK (warranty_months IS NULL OR warranty_months >= 0),
  CHECK (warranty_until IS NULL OR installed_on IS NULL OR warranty_until >= installed_on));
CREATE INDEX IF NOT EXISTS idx_installed_assets_order ON installed_assets(order_id);
CREATE INDEX IF NOT EXISTS idx_installed_assets_project ON installed_assets(project_id);
CREATE INDEX IF NOT EXISTS idx_installed_assets_warranty ON installed_assets(warranty_until) WHERE warranty_until IS NOT NULL;
/* Support and warranty — mirror of scripts/migrations/016_support.sql.
   A ticket is the customer's claim, a visit is Woodex's response, a claim is
   the answer about coverage recorded with the date it was decided. */
CREATE TABLE IF NOT EXISTS tickets(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT NOT NULL UNIQUE, customer TEXT NOT NULL,
  client_id INTEGER, company_id INTEGER, order_id INTEGER, project_id INTEGER, asset_id INTEGER,
  subject TEXT NOT NULL, description TEXT, category TEXT NOT NULL DEFAULT 'other',
  channel TEXT NOT NULL DEFAULT 'phone', priority TEXT NOT NULL DEFAULT 'normal',
  status TEXT NOT NULL DEFAULT 'open', waiting_on TEXT NOT NULL DEFAULT 'us', owner TEXT,
  first_response_at TEXT, respond_by TEXT, resolve_by TEXT, resolved_at TEXT,
  resolution TEXT, closed_at TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
  CHECK (trim(customer) <> ''), CHECK (trim(subject) <> ''),
  CHECK (status IN ('open','in_progress','waiting','resolved','closed')),
  CHECK (priority IN ('low','normal','high','urgent')),
  CHECK (channel IN ('phone','email','whatsapp','visit','web')),
  CHECK (waiting_on IN ('us','them')),
  CHECK (category IN ('damage','defect','missing_part','fitting','finish','delivery','other')),
  CHECK (resolved_at IS NULL OR resolved_at >= created_at));
CREATE INDEX IF NOT EXISTS idx_tickets_status ON tickets(status, priority);
CREATE INDEX IF NOT EXISTS idx_tickets_asset ON tickets(asset_id, created_at DESC);
CREATE INDEX IF NOT EXISTS idx_tickets_customer ON tickets(customer);
CREATE TABLE IF NOT EXISTS ticket_messages(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ticket_id INTEGER NOT NULL, author TEXT,
  body TEXT NOT NULL, internal INTEGER NOT NULL DEFAULT 0, channel TEXT, created_at TEXT NOT NULL,
  CHECK (trim(body) <> ''), CHECK (internal IN (0,1)));
CREATE INDEX IF NOT EXISTS idx_ticket_messages_ticket ON ticket_messages(ticket_id, created_at);
CREATE TABLE IF NOT EXISTS service_visits(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT NOT NULL UNIQUE, ticket_id INTEGER NOT NULL,
  scheduled_for TEXT, technician TEXT, address TEXT, status TEXT NOT NULL DEFAULT 'scheduled',
  arrived_at TEXT, completed_at TEXT, work_done TEXT, parts_used TEXT,
  chargeable INTEGER NOT NULL DEFAULT 0, charge_amount REAL, notes TEXT,
  created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
  CHECK (status IN ('scheduled','attended','completed','missed','cancelled')),
  CHECK (charge_amount IS NULL OR charge_amount >= 0),
  CHECK (completed_at IS NULL OR arrived_at IS NULL OR completed_at >= arrived_at));
CREATE INDEX IF NOT EXISTS idx_service_visits_ticket ON service_visits(ticket_id, scheduled_for DESC);
CREATE INDEX IF NOT EXISTS idx_service_visits_status ON service_visits(status, scheduled_for);
CREATE TABLE IF NOT EXISTS warranty_claims(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT NOT NULL UNIQUE, ticket_id INTEGER,
  asset_id INTEGER, customer TEXT NOT NULL, part TEXT, fault TEXT NOT NULL,
  claimed_on TEXT NOT NULL, warranty_until_on_claim TEXT, verdict TEXT NOT NULL DEFAULT 'pending',
  decided_on TEXT, decided_by TEXT, reason TEXT, cost_to_woodex REAL,
  created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
  CHECK (trim(customer) <> ''), CHECK (trim(fault) <> ''),
  CHECK (verdict IN ('pending','covered','not_covered','goodwill')),
  CHECK (verdict = 'pending' OR decided_on IS NOT NULL),
  CHECK (cost_to_woodex IS NULL OR cost_to_woodex >= 0));
CREATE INDEX IF NOT EXISTS idx_warranty_claims_asset ON warranty_claims(asset_id);
CREATE INDEX IF NOT EXISTS idx_warranty_claims_verdict ON warranty_claims(verdict, claimed_on DESC);
CREATE TRIGGER IF NOT EXISTS trg_tickets_freeze_sla
  BEFORE UPDATE ON tickets
  WHEN OLD.resolve_by IS NOT NULL AND NEW.resolve_by IS NOT OLD.resolve_by
BEGIN
  SELECT RAISE(ABORT, 'the SLA deadline is set once, from the priority at intake: moving it after the fact makes a breach disappear');
END;
CREATE TRIGGER IF NOT EXISTS trg_dispatch_runs_departure
  BEFORE UPDATE ON dispatch_runs
  WHEN OLD.departed_at IS NOT NULL AND NEW.departed_at IS NOT OLD.departed_at
BEGIN
  SELECT RAISE(ABORT, 'a run that has departed keeps its departure time: rewriting it makes a late departure look punctual');
END;

/* Procurement — mirror of scripts/migrations/009_procurement.sql.
   Column sets must match the PostgreSQL definitions name-for-name: pg-migrate
   copies row-by-row into columns it reads from this schema, and reports a
   missing column rather than coercing. See the migration for why quantities are
   INTEGER and why purchase_order_lines.item_id carries no FK. */
CREATE TABLE IF NOT EXISTS suppliers(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  code TEXT NOT NULL UNIQUE, name TEXT NOT NULL, contact_name TEXT, phone TEXT,
  email TEXT, address TEXT, tax_ref TEXT, payment_terms TEXT,
  currency TEXT NOT NULL DEFAULT 'PKR', active INTEGER NOT NULL DEFAULT 1,
  notes TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_suppliers_active ON suppliers(active, name);
CREATE TABLE IF NOT EXISTS purchase_orders(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ref TEXT NOT NULL UNIQUE, supplier_id INTEGER NOT NULL,
  status TEXT NOT NULL DEFAULT 'draft', currency TEXT NOT NULL DEFAULT 'PKR',
  expected_at TEXT, approved_by TEXT, approved_at TEXT, sent_at TEXT,
  closed_at TEXT, note TEXT, created_by TEXT,
  created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_purchase_orders_supplier ON purchase_orders(supplier_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_purchase_orders_status ON purchase_orders(status, id DESC);
CREATE TABLE IF NOT EXISTS purchase_order_lines(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  purchase_order_id INTEGER NOT NULL, line_no INTEGER NOT NULL,
  item_kind TEXT NOT NULL, item_id TEXT NOT NULL, description TEXT,
  qty_ordered INTEGER NOT NULL, unit TEXT, unit_cost REAL NOT NULL DEFAULT 0,
  UNIQUE (purchase_order_id, line_no),
  UNIQUE (purchase_order_id, item_kind, item_id)
);
CREATE INDEX IF NOT EXISTS idx_purchase_order_lines_po ON purchase_order_lines(purchase_order_id, line_no);
CREATE TABLE IF NOT EXISTS goods_receipts(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ref TEXT NOT NULL UNIQUE, purchase_order_id INTEGER NOT NULL,
  received_at TEXT NOT NULL, received_by TEXT, note TEXT, created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_goods_receipts_po ON goods_receipts(purchase_order_id, id DESC);
CREATE TABLE IF NOT EXISTS goods_receipt_lines(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  goods_receipt_id INTEGER NOT NULL, purchase_order_line_id INTEGER NOT NULL,
  qty_received INTEGER NOT NULL,
  UNIQUE (goods_receipt_id, purchase_order_line_id)
);
CREATE INDEX IF NOT EXISTS idx_goods_receipt_lines_line ON goods_receipt_lines(purchase_order_line_id);
/* The receipt idempotency guard — same predicate as migration 009. Scoped to
   ref_type='goods_receipt' so opening balances (ref_type NULL) stay exempt. */
CREATE UNIQUE INDEX IF NOT EXISTS uq_stock_movements_receipt_ref
  ON stock_movements(ref_type, ref_id, product_id)
  WHERE reason = 'receipt' AND ref_type = 'goods_receipt' AND ref_id IS NOT NULL;
/* Production: BOMs, job cards, consumption — mirror of 011_production.sql.
   A job card names the product and quantity it builds, because orders.items is
   a JSON array of {name, price, qty} with no product id: a line saying
   "BALLMER Executive Desk" cannot be resolved to a products row without
   fuzzy-matching a display name. Deriving consumption from a sales order needs
   order creation to persist product ids — a separate change, recorded as open.
   NOTE: no backticks inside this template literal. */
CREATE TABLE IF NOT EXISTS boms(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ref TEXT NOT NULL UNIQUE, product_id TEXT NOT NULL, name TEXT NOT NULL,
  version INTEGER NOT NULL DEFAULT 1, output_qty INTEGER NOT NULL DEFAULT 1,
  active INTEGER NOT NULL DEFAULT 1, notes TEXT,
  created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_boms_active_product ON boms(product_id) WHERE active = 1;
CREATE INDEX IF NOT EXISTS idx_boms_product ON boms(product_id, version DESC);
CREATE TABLE IF NOT EXISTS bom_lines(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  bom_id INTEGER NOT NULL, line_no INTEGER NOT NULL,
  item_kind TEXT NOT NULL, item_id TEXT NOT NULL,
  qty_per_output INTEGER NOT NULL, unit TEXT, note TEXT,
  UNIQUE (bom_id, line_no)
);
CREATE INDEX IF NOT EXISTS idx_bom_lines_bom ON bom_lines(bom_id, line_no);
CREATE TABLE IF NOT EXISTS job_cards(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ref TEXT NOT NULL UNIQUE, bom_id INTEGER, product_id TEXT NOT NULL, order_id INTEGER,
  qty_to_build INTEGER NOT NULL, qty_built INTEGER NOT NULL DEFAULT 0,
  stage TEXT NOT NULL DEFAULT 'queued', assigned_to TEXT,
  started_at TEXT, completed_at TEXT, qc_passed_at TEXT, qc_by TEXT, qc_note TEXT,
  due_at TEXT, notes TEXT, created_by TEXT,
  created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_job_cards_stage ON job_cards(stage, id DESC);
CREATE INDEX IF NOT EXISTS idx_job_cards_order ON job_cards(order_id);
CREATE INDEX IF NOT EXISTS idx_job_cards_product ON job_cards(product_id);
CREATE TABLE IF NOT EXISTS job_card_materials(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  job_card_id INTEGER NOT NULL, line_no INTEGER NOT NULL,
  item_kind TEXT NOT NULL, item_id TEXT NOT NULL,
  qty_required INTEGER NOT NULL, qty_issued INTEGER NOT NULL DEFAULT 0,
  created_at TEXT NOT NULL,
  UNIQUE (job_card_id, line_no),
  UNIQUE (job_card_id, item_kind, item_id)
);
CREATE INDEX IF NOT EXISTS idx_jcm_job ON job_card_materials(job_card_id, line_no);
/* The issue and output guards — same predicates as migration 011. */
/* Idempotency by cumulative state: an issue is keyed on the total the line
   reaches, so replaying it collides while a genuine top-up does not. */
CREATE UNIQUE INDEX IF NOT EXISTS uq_stock_movements_idem_key
  ON stock_movements(ref_type, idem_key) WHERE idem_key IS NOT NULL;
CREATE UNIQUE INDEX IF NOT EXISTS uq_stock_movements_production_output
  ON stock_movements(ref_type, ref_id, product_id)
  WHERE reason = 'production' AND ref_type = 'job_card_output' AND ref_id IS NOT NULL;

/* Supplier invoices (payables) — mirror of 010_supplier_invoices.sql.
   Deliberately separate from "invoices": that table is receivables (money in,
   customer by name). Merging them would make every receivables query need a
   direction filter, and one missed filter reports a payable as revenue.
   NOTE: no backticks anywhere inside this template literal — a stray one ends
   the SQL string and the syntax error points at the line that opened it. */
CREATE TABLE IF NOT EXISTS supplier_invoices(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  ref TEXT NOT NULL UNIQUE, supplier_id INTEGER NOT NULL,
  purchase_order_id INTEGER, goods_receipt_id INTEGER,
  supplier_ref TEXT NOT NULL, invoice_date TEXT NOT NULL, due_date TEXT,
  currency TEXT NOT NULL DEFAULT 'PKR',
  subtotal REAL NOT NULL DEFAULT 0, tax REAL NOT NULL DEFAULT 0,
  total REAL NOT NULL DEFAULT 0, paid REAL NOT NULL DEFAULT 0,
  status TEXT NOT NULL DEFAULT 'draft', match_state TEXT, match_json TEXT,
  matched_at TEXT, note TEXT, created_by TEXT,
  created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE UNIQUE INDEX IF NOT EXISTS uq_supplier_invoices_supplier_ref
  ON supplier_invoices(supplier_id, supplier_ref);
CREATE INDEX IF NOT EXISTS idx_supplier_invoices_po ON supplier_invoices(purchase_order_id);
CREATE INDEX IF NOT EXISTS idx_supplier_invoices_status ON supplier_invoices(status, id DESC);
CREATE TABLE IF NOT EXISTS supplier_invoice_lines(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  supplier_invoice_id INTEGER NOT NULL, purchase_order_line_id INTEGER,
  line_no INTEGER NOT NULL, description TEXT,
  qty_billed INTEGER NOT NULL, unit_cost REAL NOT NULL DEFAULT 0,
  line_total REAL NOT NULL DEFAULT 0,
  UNIQUE (supplier_invoice_id, line_no),
  UNIQUE (supplier_invoice_id, purchase_order_line_id)
);
CREATE INDEX IF NOT EXISTS idx_supplier_invoice_lines_invoice ON supplier_invoice_lines(supplier_invoice_id, line_no);
CREATE INDEX IF NOT EXISTS idx_supplier_invoice_lines_pol ON supplier_invoice_lines(purchase_order_line_id);
CREATE TABLE IF NOT EXISTS supplier_payments(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  supplier_invoice_id INTEGER NOT NULL, amount REAL NOT NULL,
  method TEXT DEFAULT 'Bank transfer', reference TEXT, note TEXT,
  paid_at TEXT, created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_supplier_payments_invoice ON supplier_payments(supplier_invoice_id, id DESC);

CREATE TABLE IF NOT EXISTS audit_logs(
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  actor_id INTEGER, actor_email TEXT, actor_name TEXT, actor_role TEXT,
  action TEXT NOT NULL, entity_type TEXT NOT NULL, entity_id TEXT,
  before_json TEXT, after_json TEXT, ip TEXT, created_at TEXT NOT NULL
);
CREATE INDEX IF NOT EXISTS idx_audit_logs_entity ON audit_logs(entity_type, entity_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_audit_logs_actor  ON audit_logs(actor_id, id DESC);
CREATE INDEX IF NOT EXISTS idx_audit_logs_action ON audit_logs(action, id DESC);
CREATE TABLE IF NOT EXISTS leads(
  id INTEGER PRIMARY KEY AUTOINCREMENT, ref TEXT, name TEXT NOT NULL, company TEXT,
  interest TEXT, source TEXT, contact TEXT, status TEXT DEFAULT 'New', owner TEXT,
  note TEXT, email TEXT, phone TEXT, preferred_channel TEXT, contact_consent INTEGER, is_returning INTEGER, identity_review INTEGER,
  consent_at TEXT, consent_version TEXT, landing_path TEXT, referrer TEXT,
  utm_source TEXT, utm_medium TEXT, utm_campaign TEXT, company_id INTEGER, created_at TEXT, updated_at TEXT
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
  merged_into INTEGER, company_id INTEGER, created_at TEXT, updated_at TEXT);
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

/* Credentials — mirror of scripts/migrations/017_credentials.sql.
   Only the hash of a reset token is kept, so reading this table cannot reset
   anybody's password. requested_by is the administrator who minted it. */
CREATE TABLE IF NOT EXISTS password_resets(
  id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER NOT NULL, token_hash TEXT NOT NULL UNIQUE,
  reason TEXT NOT NULL DEFAULT 'forgotten', requested_by INTEGER,
  created_at TEXT NOT NULL, expires_at TEXT NOT NULL, used_at TEXT, revoked_at TEXT,
  CHECK (reason IN ('forgotten','compromised','offboarding','initial')),
  CHECK (used_at IS NULL OR revoked_at IS NULL));
CREATE INDEX IF NOT EXISTS idx_password_resets_user ON password_resets(user_id, created_at DESC);
/* Shared throttling — mirror of scripts/migrations/018_throttle.sql.
   The login throttle used to be a Map inside the process, which a cold start
   threw away. A lockout is a fact about the account, so it lives with it. */
CREATE TABLE IF NOT EXISTS throttle_buckets(
  id INTEGER PRIMARY KEY AUTOINCREMENT, bucket_key TEXT NOT NULL UNIQUE, kind TEXT NOT NULL,
  failures INTEGER NOT NULL DEFAULT 0, window_started_at TEXT NOT NULL,
  locked_until TEXT, last_seen_at TEXT NOT NULL, created_at TEXT NOT NULL,
  CHECK (kind IN ('login','ip')), CHECK (trim(bucket_key) <> ''),
  CHECK (failures >= 0), CHECK (window_started_at <= last_seen_at));
CREATE INDEX IF NOT EXISTS idx_throttle_buckets_locked
  ON throttle_buckets(bucket_key) WHERE locked_until IS NOT NULL;
CREATE INDEX IF NOT EXISTS idx_throttle_buckets_last_seen ON throttle_buckets(last_seen_at);
CREATE TABLE IF NOT EXISTS meta(key TEXT PRIMARY KEY, value TEXT);
PRAGMA journal_mode = WAL;
`);

/* Indexes on the columns 014 adds to tables created later in the DDL above.
   They cannot live in that batch: CREATE INDEX ... ON leads(company_id) would
   run before CREATE TABLE leads and SQLite refuses with "no such table:
   main.leads". PostgreSQL has no such ordering problem, so the migration file
   keeps them inline. */
if (db.kind === "sqlite") {
  await db.exec("CREATE INDEX IF NOT EXISTS idx_leads_company ON leads(company_id)");
  await db.exec("CREATE INDEX IF NOT EXISTS idx_clients_company ON clients(company_id)");
}


/* The opening chart of accounts — mirror of the INSERT at the end of
   013_finance.sql. Seeded here as well so a fresh SQLite database and a
   migrated PostgreSQL one start from the same place; ON CONFLICT DO NOTHING
   makes boot idempotent and leaves a chart the operator has since edited
   alone. */
if (db.kind === "sqlite") {
  const chart = [
    ["1000", "Cash on hand", "asset"], ["1010", "Bank — current", "asset"],
    ["1100", "Accounts receivable", "asset"], ["1200", "Inventory", "asset"],
    ["2000", "Accounts payable", "liability"], ["2100", "Sales tax payable", "liability"],
    ["3000", "Owner equity", "equity"],
    ["4000", "Furniture sales", "revenue"], ["4100", "Installation services", "revenue"],
    ["4900", "Discounts and returns", "revenue"],
    ["5000", "Cost of goods sold", "expense"], ["6000", "Rent", "expense"],
    ["6100", "Salaries and wages", "expense"], ["6200", "Utilities", "expense"],
    ["6300", "Transport and freight", "expense"], ["6900", "Other expenses", "expense"],
  ];
  const t0 = now();
  for (const [code, name, kind] of chart) {
    await db.prepare(
      "INSERT INTO accounts(code, name, kind, active, notes, created_at, updated_at) VALUES(?,?,?,1,NULL,?,?) ON CONFLICT(code) DO NOTHING"
    ).run(code, name, kind, t0, t0);
  }
}

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
    /* The catalogue just arrived with quantities but no history. Record them as
       opening receipts so stock_qty reconciles against SUM(delta) from the
       first boot rather than starting 148 products in debt. */
    await db.prepare(`INSERT INTO stock_movements(product_id, delta, reason, note, created_by, created_at)
      SELECT id, stock_qty, 'receipt', 'opening balance seeded with the catalogue', 'seed', ?
        FROM products p
       WHERE p.stock_qty <> 0
         AND NOT EXISTS (SELECT 1 FROM stock_movements m WHERE m.product_id = p.id)`).run(now());
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
/* ---------------- stock ledger ----------------
   Every change to product stock goes through applyStockMovement. Nothing else
   may write stock_qty, because a second write path is how a store starts
   disagreeing with its own ledger.

   stock_qty is a cache over SUM(delta) in stock_movements, not the source of
   truth. Two things follow from that:

   1. The decrement has to be atomic with its availability check. "Is there
      enough?" followed by "take some" is two statements, and between them
      another request can take the same units. Both are done in one running
      transaction, and the UPDATE asserts `stock_qty >= ?` on the row itself, so
      the database refuses the decrement even if application logic is wrong.

   2. Changing stock at the same moment as editing a product must not interleave.
      The admin PATCH used to write the whole products row from a value it read
      earlier; it now writes only its own columns and lets the ledger own
      stock_qty, so a concurrent sale cannot be silently overwritten by an
      edit form that loaded the page a minute ago. */
/* "production" is a real reason: components issued into a build and the
   finished item coming out. Filing those under "adjustment" would hide real
   consumption inside the bucket reserved for corrections. Migration 011 widens
   the PostgreSQL CHECK to match — the two must always move together. */
const STOCK_REASONS = new Set(["receipt", "sale", "return", "adjustment", "transfer", "wastage", "production"]);

/* The location a movement lands in when the caller does not name one.
   Cached for the life of the process because it changes approximately never and
   is consulted on every single stock write — including inside a 200-line
   checkout loop. The cache is invalidated by any warehouse/location mutation,
   so moving the default is not a restart-required operation. */
let defaultLocationCache;
const invalidateDefaultLocation = () => { defaultLocationCache = undefined; };
const computeDefaultLocationId = async () => {
  const row = await db.prepare(
    `SELECT l.id
       FROM storage_locations l
       JOIN warehouses w ON w.id = l.warehouse_id
      WHERE l.active = 1 AND w.active = 1
      ORDER BY w.is_default DESC, w.id ASC, l.id ASC
      LIMIT 1`
  ).get();
  return row ? Number(row.id) : null;
};
const defaultLocationId = async () => {
  if (defaultLocationCache === undefined) defaultLocationCache = await computeDefaultLocationId();
  return defaultLocationCache;
};
const applyStockMovement = async ({ productId, delta, reason, refType = null, refId = null, note = null, actor = "system", idemKey = null, locationId = null }) => {
  const d = Number(delta);
  if (!Number.isInteger(d) || d === 0) throw Object.assign(new Error("stock delta must be a non-zero whole number"), { statusCode: 400 });
  if (!STOCK_REASONS.has(reason)) throw Object.assign(new Error("unknown stock reason: " + reason), { statusCode: 400 });

  /* 012: a location the caller names must exist. Validated here rather than in
     every route, so no future caller can write a movement pointing at a
     location row that was deleted or mistyped. A null location is allowed only
     because history has them — new writes are expected to pass one, and the
     transfer route depends on this lookup to know which end is which. */
  if (locationId !== null && locationId !== undefined) {
    const loc = await db.prepare("SELECT id FROM storage_locations WHERE id = ?").get(Number(locationId));
    if (!loc) throw Object.assign(new Error("storage location not found: " + locationId), { statusCode: 404, code: "location_not_found" });
  }
  /* When the caller does not say where, the fallback below decides — and it
     decides differently for incoming and outgoing stock. */
  let locId = locationId === null || locationId === undefined ? null : Number(locationId);

  /* Positive unattributed movements go to the default location: goods that
     arrive have to arrive somewhere, and "the receiving bay" is a convention
     rather than a guess. Negative ones are different. A sale or a write-off
     that names no location means "out of the store", not "out of the default
     bay", and the units may well sit on a rack in the other warehouse — or,
     for opening balances seeded before 012, on no recorded rack at all.
     Attributing such a decrement to the default bay would either fail on a
     location that happens to be empty or, worse, silently subtract from a
     place the goods were never in. So a negative falls back to the default
     only when the default can actually cover it; otherwise it is recorded
     with no location, which is the honest answer and which keeps the
     invariant exact: SUM(stock_levels) always equals the sum of LOCATED
     ledger rows, because an unlocated row contributes to neither side. */
  if (locId === null) {
    const fallback = await defaultLocationId();
    if (fallback === null) locId = null;
    else if (d > 0) locId = fallback;
    else {
      const at = Number((await db.prepare(
        "SELECT qty FROM stock_levels WHERE product_id = ? AND location_id = ?"
      ).get(productId, fallback))?.qty ?? 0);
      locId = at >= -d ? fallback : null;
    }
  }
  /* A negative delta must be reconcilable in one statement. When it is not,
     the row simply does not match and we report insufficient stock rather than
     writing an entry the ledger cannot back. */
  if (d < 0) {
    const res = await db.prepare("UPDATE products SET stock_qty = stock_qty + ?, updated_at = ? WHERE id = ? AND stock_qty >= ?")
      .run(d, now(), productId, -d);
    if (!res.changes) throw Object.assign(new Error("insufficient stock"), { statusCode: 409, code: "insufficient_stock", productId });
  } else {
    const res = await db.prepare("UPDATE products SET stock_qty = stock_qty + ?, updated_at = ? WHERE id = ?").run(d, now(), productId);
    if (!res.changes) throw Object.assign(new Error("product not found: " + productId), { statusCode: 404, code: "product_not_found", productId });
  }

  let info;
  try {
    info = await db.prepare(
      "INSERT INTO stock_movements(product_id, delta, reason, ref_type, ref_id, note, created_by, created_at, idem_key, location_id) VALUES(?,?,?,?,?,?,?,?,?,?)"
    ).run(productId, d, reason, refType, refId, note, String(actor).slice(0, 80), now(), idemKey, locId);
  } catch (error) {
    /* The idempotency index fired: this exact movement was already recorded.
       Surfacing the driver message would make a correct refusal look like a
       server fault, and a caller retrying on 5xx would keep hammering it.
       The stock decrement above is rolled back with the transaction. */
    const duplicate = /UNIQUE constraint failed|duplicate key value/i.test(String(error?.message ?? ""));
    if (duplicate && idemKey) {
      throw Object.assign(new Error("this movement has already been recorded (idempotency key " + idemKey + ")"),
        { statusCode: 409, code: "duplicate_movement" });
    }
    throw error;
  }

  /* Keep the per-location projection in step with the movement that was just
     written. It is inside the caller's transaction (every write route runs in
     one), so a rollback removes both. UPSERT rather than select-then-insert:
     two concurrent receipts into the same empty slot would otherwise race and
     one would fail on the primary key. */
  if (locId !== null) {
    if (d < 0) {
      const lv = await db.prepare(
        "UPDATE stock_levels SET qty = qty + ?, updated_at = ? WHERE product_id = ? AND location_id = ? AND qty >= ?"
      ).run(d, now(), productId, locId, -d);
      /* Unreachable by construction: a caller-named location was checked for
         coverage below before we got here, and a fallback location was only
         chosen when it could cover the decrement. Asserted rather than assumed
         so a future edit to the fallback policy fails loudly instead of
         corrupting the projection. */
      if (!lv.changes) {
        throw Object.assign(new Error("insufficient stock at that location"), { statusCode: 409, code: "insufficient_location_stock", productId, locationId: locId });
      }
    } else {
      await db.prepare(
        `INSERT INTO stock_levels(product_id, location_id, qty, updated_at) VALUES(?,?,?,?)
         ON CONFLICT(product_id, location_id) DO UPDATE SET qty = stock_levels.qty + excluded.qty, updated_at = excluded.updated_at`
      ).run(productId, locId, d, now());
    }
  }

  return { id: info.lastInsertRowid, productId, delta: d, reason, locationId: locId };
};

/* Recompute the cache from the ledger. Used by the reconciliation test and by
   the admin repair endpoint; if these two ever disagree, the ledger wins. */
const stockFromLedger = async (productId) => {
  const row = await db.prepare("SELECT COALESCE(SUM(delta),0) AS q FROM stock_movements WHERE product_id = ?").get(productId);
  return Number(row?.q ?? 0);
};

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
  id: r.id, ref: r.ref, name: r.name, company: r.company, companyId: r.company_id ?? null,
  interest: r.interest, source: r.source,
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

/* ── invoice status: what is stored vs what is shown ──────────────────────
   This distinction is the whole difficulty of constraining the column.

   STORED:    Issued · Partially Paid · Paid · Cancelled
   COMPUTED:  Overdue  — decorateInvoice derives it on read from due/balance,
                         so it must never be written to the column.
   NOT A STATE AT ALL: Draft — the dashboard's union offers it, but no code
                         path ever produces an invoice in it.

   A CHECK can only guard the stored set. It cannot stop a Cancelled invoice
   from being flipped back to Paid by the payment route, which is why the
   transition guard below exists and has to land with the constraint. */

const INVOICE_STORED_STATUSES = ["Issued", "Partially Paid", "Paid", "Cancelled"];
/* Derived from what has been paid. Setting these by hand would contradict the
   money, so the PATCH route refuses them and points at the payments endpoint. */
const INVOICE_DERIVED_STATUSES = ["Paid", "Partially Paid"];

const INVOICE_STATUS_ALIASES = new Map([
  ["issued", "Issued"], ["issue", "Issued"], ["unpaid", "Issued"], ["open", "Issued"],
  ["pending", "Issued"], ["sent", "Issued"], ["new", "Issued"],
  ["partially paid", "Partially Paid"], ["partial", "Partially Paid"], ["part paid", "Partially Paid"],
  ["partiallypaid", "Partially Paid"],
  ["paid", "Paid"], ["settled", "Paid"], ["complete", "Paid"], ["completed", "Paid"],
  ["cancelled", "Cancelled"], ["canceled", "Cancelled"], ["cancel", "Cancelled"],
  ["void", "Cancelled"], ["voided", "Cancelled"],
]);
/* Returns null for a value that cannot be stored, so callers can answer 400
   with the reason instead of writing something the CHECK would later reject as
   a 500. Overdue is the interesting refusal: it is a real status to a reader,
   but storing it would freeze a value that is supposed to be recomputed. */
const normInvoiceStatus = (v) => {
  const raw = String(v ?? "").trim();
  if (!raw) return null;
  const key = raw.toLowerCase().replace(/[_-]+/g, " ").replace(/\s+/g, " ");
  if (key === "overdue") return { error: "Overdue is derived from the due date and balance, not set directly" };
  if (key === "draft") return { error: "an invoice does not have a Draft state; use Issued" };
  const hit = INVOICE_STATUS_ALIASES.get(key);
  return hit ? { status: hit } : null;
};
const rowReturn = (r) => ({ id: r.id, ref: r.ref, orderId: r.order_id, customer: r.customer, item: r.item, reason: r.reason,
  state: r.state, refundAmount: r.refund_amount, resolution: r.resolution, createdAt: r.created_at, updatedAt: r.updated_at });
const wrap = (fn) => (req, res) => Promise.resolve().then(() => fn(req, res)).catch((e) => {
  const status = Number(e.statusCode) || 500;
  // 5xx bodies stay generic: driver/stack detail belongs in the server log, not the response.
  if (status >= 500) console.error(`[woodex-api] ${req.method} ${req.originalUrl} → 500 ·`, e?.stack ?? e);
  if (!res.headersSent) {
    /* A refusal the caller cannot act on is a dead end. Client errors keep the
       handler's machine-readable `code` and the id of the record that caused
       the refusal, so "already exists" arrives with the id that already exists
       and the caller can open it instead of inventing a variant spelling —
       which creates the duplicate the refusal was there to prevent. 5xx stays
       a bare message on purpose: driver detail belongs in the log. */
    /* 501 is a deliberate refusal, not a crash: "this system cannot do that
       yet, and here is why" is exactly what the caller needs. Masking it as
       "internal error" turns a designed answer into a bug report. */
    const body = { error: status >= 500 && status !== 501 ? "internal error" : String(e.message || e) };
    /* Client errors keep their machine-readable code. 501 joins them: it is a
       refusal this system chose, so the caller can branch on the reason. */
    if (status < 500 || status === 501) {
      if (typeof e?.code === "string") body.code = e.code;
      for (const k of ["companyId", "contactId", "attachmentId", "productId", "locationId"]) {
        if (e?.[k] !== undefined) body[k] = e[k];
      }
    }
    res.status(status).json(body);
  }
  else res.end();
});
/* TODO-2 (QA): compound write flows run atomically — a mid-flow throw rolls the whole
   route back (no order-without-invoice half-state). */
const wtx = (fn) => db.transaction(fn);

/* ---------- audit trail ----------
   One writer, called from the route that performed the change, inside the same
   transaction wherever one exists. Two rules make it worth having:

     1. It never throws. An audit write that can fail a payment because the log
        row was rejected would be worse than the gap it fixes — but a swallowed
        failure is invisible, so failures are counted and exposed on
        /api/audit/health rather than silently dropped.

     2. It is called only after the change succeeded. Logging an attempt that
        rolled back would be a lie, and the tests assert rollback leaves no row. */
const AUDIT_JSON_LIMIT = 4000; // keeps a snapshot bounded; the row is evidence, not a backup
let auditWriteFailures = 0;

function auditSnapshot(value) {
  if (value === undefined || value === null) return null;
  let json;
  try { json = JSON.stringify(value); } catch { return null; }
  if (json === undefined) return null;
  return json.length > AUDIT_JSON_LIMIT ? JSON.stringify({ truncated: true, bytes: json.length }) : json;
}

async function writeAudit(req, { action, entityType, entityId = null, before = null, after = null }) {
  /* `<entity>.<verb>`, both lowercase snake-ish. Underscores ARE allowed in the
     verb — 'payment_recorded' is the honest name for the action, and an earlier
     version of this pattern rejected it, which meant the single most important
     audit point in the system silently logged nothing while reporting success.
     A validator that rejects the real vocabulary is worse than no validator. */
  if (!/^[a-z][a-z0-9_-]*\.[a-z][a-z0-9_-]*$/.test(String(action))) {
    /* A malformed action is a programming error, not a user error. Count it and
       carry on: refusing the caller's real write over a logging typo would be
       the tail wagging the dog. */
    auditWriteFailures += 1;
    console.error(`[audit] rejected malformed action ${JSON.stringify(action)}`);
    return;
  }
  const u = req?.user ?? null;
  try {
    await db.prepare(
      `INSERT INTO audit_logs(actor_id,actor_email,actor_name,actor_role,action,entity_type,entity_id,before_json,after_json,ip,created_at)
       VALUES(?,?,?,?,?,?,?,?,?,?,?)`
    ).run(
      u?.id ?? null,
      u?.email ?? null,
      /* Anonymous writes still need an attribution line. req.actor is set for
         staff acting through a public route; "storefront" is the honest label
         for an unauthenticated visitor. */
      u?.name ?? req?.actor ?? (u ? null : "storefront"),
      u?.role ?? null,
      action,
      entityType,
      entityId === null || entityId === undefined ? null : String(entityId),
      auditSnapshot(before),
      auditSnapshot(after),
      req?.ip ?? null,
      now(),
    );
  } catch (e) {
    auditWriteFailures += 1;
    console.error(`[audit] failed to record ${action}:`, e?.message ?? e);
  }
}

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
  /* A handler may attach headers. This exists for one caller — the supplier
     invoice POST, which signals a match variance in the response header as well
     as the body, so a client that only reads the body still cannot claim it was
     not told. Ignoring the field would have made that claim false. */
  if (result?.headers && typeof result.headers === "object") {
    for (const [k, v] of Object.entries(result.headers)) { try { res.setHeader(k, v); } catch { /* invalid header name: not worth failing the write */ } }
  }
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
  /* Purchasing owns suppliers, purchase orders and goods receiving. It can see
     the catalogue (it needs to know what a product is) but only through
     read paths the catalogue cap already guards, so it is granted nothing on
     products: raising a PO reads product data, and that read is allowed
     because reads check the cap for /api/products… see capsFor below, where
     purchasing gets an explicit read allowance rather than a write one. */
  purchasing: ["purchasing.manage", "catalog.view"],
  /* Production owns recipes (BOMs) and job cards. It needs to read the
     catalogue to know what it is building, and it needs NOTHING on stock —
     consumption and output post through applyStockMovement, which is a
     production write, not a catalogue one. Giving it catalog.manage would let
     the floor edit prices; catalog.view is the honest grant. */
  production: ["production.manage", "catalog.view"],
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
  /* Supplier invoices are money OUT and belong to finance, not to purchasing:
     the person who raises a PO must not also be the person who approves paying
     it. Same cap as receivables, different direction. */
  if (path.startsWith("/api/supplier-invoices")) return "finance.manage";
  if (path.startsWith("/api/invoices") || path.startsWith("/api/payments") || path.startsWith("/api/returns")) return "finance.manage";
  if (path.startsWith("/api/suppliers") || path.startsWith("/api/purchase-orders") || path.startsWith("/api/goods-receipts")) return "purchasing.manage";
  /* Recipes and job cards belong to the floor. Deliberately NOT folded into
     purchasing.manage: the person who buys the timber is not the person who
     decides how much of it a chair takes, and a shared cap would make the
     separation meaningless. */
  if (path.startsWith("/api/boms") || path.startsWith("/api/job-cards")) return "production.manage";
  if (path.startsWith("/api/products") || path.startsWith("/api/materials") || path.startsWith("/api/services") || path.startsWith("/api/categories") || path.startsWith("/api/collections")) return "catalog.manage";
  return "owner-only";
};
const isPublic = (m, p) =>
  /* Spending a reset token is the one write that must work with no session at
     all: the whole point is that the person cannot sign in. Everything else
     that touches credentials still requires one. */
  (m === "POST" && p === "/api/auth/reset-password") ||
  m === "GET" && (
    p === "/api/health" || p === "/api/theme" || p === "/api/auth/demo-available" ||
    /^\/api\/(products|categories|collections|materials|services)(\/|$)/.test(p) ||
    /^\/api\/pages\/[^/]+\/public$/.test(p) ||
    p.startsWith("/img/") || p === "/uploads" || /^\/(uploads|img)\//.test(p) ||
    /* /sitemap.xml has been broken in production for weeks while passing every
       local check. Three wrong diagnoses got here, so the chain is written out:

         browser  /sitemap.xml
         rewrite  /api/index          (this exact rule, not a second function)
         Vercel   strips the /api prefix for the catch-all, so the function
                  receives /sitemap.xml
         adapter  api/index.mjs prefixes anything outside /api with /api
         express  receives /api/sitemap.xml  ← the path the gate must allow

       A custom api/sitemap.mjs used to reset req.url to /sitemap.xml and then
       call the catch-all, which prefixed it again to /api/sitemap.xml — a path
       with no route and no allow-list entry, answered 401 to every crawler.
       Every local test passed because it spawned Express directly and used
       /api/sitemap, the spelling I had assumed rather than measured.

       All four spellings are public so the answer does not depend on which
       prefix Vercel adds: the route and the allow-list agree by construction. */
    p === "/sitemap.xml" || p === "/api/sitemap" || p === "/api/sitemap.xml"
    || p === "/robots.txt" || p === "/api/robots.txt"
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
    /* /admin is the address people actually type; the dashboard is built under
       /dashboard. Redirect rather than 404 (or serve) so both work either way. */
    if (clean === "/admin" || clean.startsWith("/admin/")) return res.redirect(308, "/dashboard" + clean.slice("/admin".length));
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

/* Vercel routes the catch-all function to /api/<function>/<path>, and a rewrite whose
   destination repeats its own prefix yields /api/api/... . That path matches no route,
   so it fell through to the session middleware, which answered 401 for every POST —
   surfacing to the browser as a generic 405 and looking exactly like a wrong password.
   Collapse a duplicated prefix instead of failing, so a routing mistake degrades to a
   working request rather than a misleading auth error. */
app.use((req, _res, next) => {
  if (req.url === "/api/api") req.url = "/api";
  else if (req.url.startsWith("/api/api/")) req.url = req.url.slice(4);
  next();
});

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
    if (visitor && visitor.active && ts(visitor.expires_at) > Date.now()) { req.user = visitor; req.actor = visitor.name; }
    return next();
  }
  if (!token) return res.status(401).json({ error: "sign in required", code: "no_token" });
  const u = (await (db.prepare("SELECT u.id, u.email, u.name, u.role, u.active, s.expires_at FROM sessions s JOIN users u ON u.id=s.user_id WHERE s.token=?").get(token)));
  if (!u) return res.status(401).json({ error: "session expired — sign in again", code: "bad_token" });
  if (ts(u.expires_at) < Date.now()) { (await (db.prepare("DELETE FROM sessions WHERE token=?").run(token))); return res.status(401).json({ error: "session expired", code: "expired" }); }
  if (!u.active) return res.status(403).json({ error: "account disabled" });
  req.user = u; req.actor = u.name;
  const caps = ROLE_CAPS[u.role] ?? [];
  if (req.path.startsWith("/api/users") && req.method === "GET" && !caps.includes("*") && !caps.includes("users.manage"))
    return res.status(403).json({ error: "team roster is owner-only", need: "users.manage" });

  const selfAuthRoute = (req.method === "GET" && req.path === "/api/auth/me")
    || (req.method === "POST" && req.path === "/api/auth/logout")
    /* Changing your OWN password is not team administration. A viewer who
       cannot reach /api/users must still be able to improve their password —
       otherwise the only people who can change it are the ones who least need
       to, and everybody else is stuck with whatever they were handed. */
    || (req.method === "POST" && req.path === "/api/auth/change-password");
  const need = capsFor(req.method, req.path);
  if (!selfAuthRoute && req.method === "GET") {
    const readOnlyMove = (need === "quote.manage" && caps.includes("quote.view-move"))
      || (need === "order.manage" && caps.includes("order.view-move"));
    /* Purchasing must be able to READ the catalogue to raise a purchase order
       against a product, but must not be able to EDIT it — editing stock or
       price is how a PO ends up agreeing with a number someone typed. The grant
       is therefore read-only and named as such. */
    const catalogReader = need === "catalog.manage" && caps.includes("catalog.view");
    if (!caps.includes("*") && !caps.includes(need) && !readOnlyMove && !catalogReader)
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
/* No in-process login bucket. It was here, and it protected the process rather
   than the account: a cold start began counting from zero again. See 018. */

app.get("/api/health", (req, res) => {
  // Keep the public liveness response deliberately free of customer and finance counts.
  res.json({ ok: true, service: "woodex-api", ts: now() });
});

/* Does this deployment actually have the demo accounts?
 *
 * The login screen used to print "Demo access — user admin · pass admin" as a
 * literal. That string shipped to production, where the demo owner is
 * deliberately never created (the seed is skipped on Vercel) and the password
 * is different. So the live site instructed every visitor to use credentials
 * that cannot work, and the first thing a new user saw was "wrong email or
 * password".
 *
 * The fix is not a better guess in the UI — it is to ask the database. This is
 * public because the login screen must be able to ask BEFORE anyone signs in.
 * It reveals only whether a well-known demo account exists and is active; it
 * exposes no other account, no email address beyond the demo one, and no hash.
 */
app.get("/api/auth/demo-available", wrap(async (_req, res) => {
  let demo = false;
  try {
    const u = await db.prepare(
      "SELECT id, pass_salt FROM users WHERE email='admin@woodex.pk' AND active=1"
    ).get();
    /* The account existing is not enough: it must be the seeded demo one, whose
       password is "admin". Without checking the hash a production owner who
       registered admin@woodex.pk with a real password would advertise a login
       that does not work — the same lie in a different disguise. */
    if (u) {
      const probe = hashPass("admin", u.pass_salt);
      demo = probe.hash === (await db.prepare("SELECT pass_hash FROM users WHERE id=?").get(u.id)).pass_hash;
    }
  } catch { demo = false; }
  res.json({ demo });
}));
/* N+1 hunting: `db.queries` is cumulative, so a test takes two readings around
   one request and asserts the delta does not grow with row count. Exposed as a
   capability so only tooling (never the dashboard) can read it. */
app.get("/api/diagnostics/queries", (req, res) => {
  if (process.env.NODE_ENV === "production") return res.status(404).json({ error: "not found" });
  res.json({ queries: db.queries ?? null, driver: db.kind });
});

const PRODUCT_ID_RE = /^[a-z0-9][a-z0-9-]{0,78}[a-z0-9]$/;

/* Bounds for ?limit/?offset. MAX_PAGE keeps a single response small; MAX_WINDOW
   stops a caller from making the server walk an arbitrary distance through the
   catalogue to discard it. */
const MAX_PAGE = 500;
const MAX_WINDOW = 5000;

/* Parses ?limit & ?offset into integers, or returns an error string.
   Absent/empty => null, meaning "no bound". Anything present must be a
   non-negative integer: 'abc', '1.5', '-1' and '' are rejected rather than
   coerced, because Math.trunc(Number('abc')) is NaN and a NaN limit silently
   becomes an unbounded query — the exact failure being fixed here. */
function parseWindow(query) {
  const int = (raw, { min, max, name }) => {
    if (raw === undefined || raw === "") return { value: null };
    if (!/^\d+$/.test(String(raw))) return { error: `${name} must be a non-negative integer` };
    const n = Number(raw);
    if (n < min) return { error: `${name} must be at least ${min}` };
    if (max !== undefined && n > max) return { error: `${name} must be at most ${max}` };
    return { value: n };
  };
  const l = int(query.limit, { min: 0, max: MAX_PAGE, name: "limit" });
  if (l.error) return { error: l.error };
  const o = int(query.offset, { min: 0, max: MAX_WINDOW, name: "offset" });
  if (o.error) return { error: o.error };
  return { limit: l.value, offset: o.value ?? 0 };
}
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

  /* limit/offset are accepted but must never be silently ignored: a caller that
     asks for 1 product and receives 148 has no way to tell whether it asked
     wrong or the API ignored it. Absent means "everything". */
  const { limit, offset, error } = parseWindow(req.query);
  if (error) return res.status(400).json({ error });

  let rows = (await (db.prepare("SELECT * FROM products ORDER BY id").all()));
  let items = rows.map(rowProduct);
  if (sub) items = items.filter((p) => p.subcategory === sub);
  if (q) items = items.filter((p) => (p.name + " " + (p.series ?? "") + " " + (p.category ?? "")).toLowerCase().includes(q));
  /* total is the size of the filtered set, before the window: a paginated client
     needs both "how many matched" and "these are the ones I asked for". */
  const total = items.length;
  const start = Math.min(offset, total);
  const page = items.slice(start, limit === null ? undefined : start + limit);
  res.json({ total, items: page, limit, offset });
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
  /* Opening quantity is a receipt, so a product's ledger is complete from the
     moment it exists and reconciliation never reports a phantom discrepancy. */
  if (Number(product.stockQty) > 0) {
    await db.prepare("INSERT INTO stock_movements(product_id, delta, reason, note, created_by, created_at) VALUES(?,?,?,?,?,?)")
      .run(id, Number(product.stockQty), "receipt", "opening balance at product creation", String(req.actor ?? "Catalog").slice(0, 80), t);
  }
  emit("products", { title: "Product created: " + product.name.slice(0, 60), who: req.actor ?? "Catalog" });
  res.status(201).json(rowProduct(await db.prepare("SELECT * FROM products WHERE id=?").get(id)));
}));

app.patch("/api/products/:id", wrapTxJson(async (req, afterCommit) => {
  const r = (await (db.prepare("SELECT * FROM products WHERE id = ?").get(req.params.id)));
  if (!r) return { status: 404, body: { error: "not found" } };
  const base = { ...JSON.parse(r.json), stockQty: r.stock_qty };
  const parsed = normalizeProduct(req.body ?? {}, base, r.id);
  if (parsed.error) return { status: 400, body: { error: parsed.error } };
  const product = parsed.value;

  /* This route deliberately does NOT write stock_qty. The edit form posts the
     whole product, including whatever quantity it loaded when the page opened;
     writing that back would silently undo every sale that happened in between,
     which is a lost update no constraint would ever catch. Stock moves only
     through the ledger, via an explicit delta below. */
  await db.prepare("UPDATE products SET json=?, price=?, in_stock=?, updated_at=? WHERE id=?")
    .run(JSON.stringify(product), product.price, product.inStock ? 1 : 0, now(), r.id);

  /* An integer stockQty that differs from current stock is interpreted as an
     explicit recount and recorded as an adjustment, not as a field edit. */
  const requested = Number(req.body?.stockQty);
  if (req.body?.stockQty !== undefined && Number.isInteger(requested)) {
    const current = Number((await db.prepare("SELECT stock_qty FROM products WHERE id=?").get(r.id)).stock_qty);
    if (requested !== current) {
      await applyStockMovement({
        productId: r.id, delta: requested - current, reason: "adjustment",
        refType: "product", refId: null, note: "stock set from product edit form",
        actor: req.actor ?? "Catalog",
      });
    }
  }

  const out = rowProduct(await db.prepare("SELECT * FROM products WHERE id=?").get(r.id));
  /* Price and stock are the two fields where a silent edit costs real money. */
  const priceChanged = Number(r.price) !== Number(product.price);
  const stockMoved = req.body?.stockQty !== undefined && Number.isInteger(Number(req.body.stockQty)) && Number(out.stockQty) !== Number(r.stock_qty);
  if (priceChanged || stockMoved) await writeAudit(req, {
    action: "product.updated",
    entityType: "product",
    entityId: r.id,
    before: { price: r.price, stockQty: r.stock_qty },
    after: { price: product.price, stockQty: out.stockQty, priceChanged, stockMoved },
  });
  afterCommit.push(() => emit("products", { title: "Product updated: " + product.name.slice(0, 60), who: req.actor ?? "Catalog" }));
  return { body: out };
}));

/* Stock history and reconciliation for one product.
   Read-only by design: the ledger is append-only, so there is no POST here —
   movements are created as a side effect of real events (a sale, a return, a
   recount), never by an API caller choosing a number. */
app.get("/api/products/:id/stock", wrap(async (req, res) => {
  const product = (await db.prepare("SELECT id, stock_qty FROM products WHERE id=?").get(req.params.id));
  if (!product) return res.status(404).json({ error: "not found" });
  const limit = Math.min(200, Math.max(1, Number(req.query.limit) || 50));
  const items = (await db.prepare("SELECT * FROM stock_movements WHERE product_id=? ORDER BY id DESC LIMIT ?").all(product.id, limit))
    .map((m) => ({ id: m.id, productId: m.product_id, delta: Number(m.delta), reason: m.reason,
      refType: m.ref_type, refId: m.ref_id, note: m.note, createdBy: m.created_by, createdAt: m.created_at }));
  const ledger = await stockFromLedger(product.id);
  res.json({ productId: product.id, stockQty: Number(product.stock_qty), ledgerQty: ledger,
    reconciles: Number(product.stock_qty) === ledger, total: items.length, items });
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
  const unpriced = [];
  for (const [index, item] of quote.items.entries()) {
    const productId = String(item.productId ?? item.id ?? "").trim();
    /* A bespoke line (no catalog id) is a legitimate request for a quote, but it
       has no price yet. Anonymous visitors must never supply the price, so flag
       the quote for sales instead of silently storing a PKR 0 figure. */
    if (!productId) { unpriced.push(item.name || `item ${index + 1}`); priced.push({ ...item, price: 0, lineTotal: 0 }); continue; }
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
  if (unpriced.length) details.pricingReview = { required: true, unpricedLines: unpriced };
  else delete details.pricingReview;
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
  /* companyId links the lead to the real company record. Nullable, and
     deliberately NOT auto-matched from the free-text `company` column: guessing
     wrong attaches a lead to the wrong customer, and nobody ever notices. */
  let companyId = r.company_id ?? null;
  if (req.body?.companyId !== undefined) {
    if (req.body.companyId === null) companyId = null;
    else {
      const co = await db.prepare("SELECT id FROM companies WHERE id = ?").get(Number(req.body.companyId));
      if (!co) throw Object.assign(new Error("company not found: " + req.body.companyId), { statusCode: 404 });
      companyId = co.id;
    }
  }
  (await (db.prepare("UPDATE leads SET status=?, owner=?, company_id=?, updated_at=? WHERE id=?").run(status ?? r.status, owner ?? r.owner, companyId, now(), r.id)));
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

  /* Take the stock. Until this existed, checkout validated availability and
     then never decremented it, so a product with 99 units could be sold an
     unlimited number of times — the availability check gave the appearance of
     inventory control without providing any.

     Ordered items were already per-line validated above, but that was a read.
     These are the writes, and they are the ones that can lose a race: the
     ledger's guarded UPDATE fails with 409 if the units are gone, which rolls
     the whole checkout back (order, invoice, client, task) instead of selling
     air. Lines are applied sequentially because two lines may name the same
     product, and a partial success must not survive. */
  for (const item of items) {
    await applyStockMovement({
      productId: item.id, delta: -item.qty, reason: "sale",
      refType: "order", refId: oLed.id, note: ref + " · " + item.name.slice(0, 80),
      actor: "storefront",
    });
  }

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
/* Task priority was free text: the dashboard sends low/normal/high, QA posted
   "High", seeds wrote both, and POST /api/tasks stored whatever arrived. That is
   why no CHECK constraint existed on the column — any single-casing predicate
   would have rejected working requests.

   The vocabulary is closed here, at the boundary, so the column can hold one.
   Anything unrecognised becomes "normal" rather than erroring: a task with an
   odd priority is not worth a failed request, and silently dropping the caller's
   intent is worse than a sensible default. */
const TASK_PRIORITIES = new Set(["low", "normal", "high"]);
const normPriority = (v) => {
  const s = String(v ?? "").trim().toLowerCase();
  if (!s) return "normal";
  if (TASK_PRIORITIES.has(s)) return s;
  /* "medium" and "urgent" are what people actually type; map them rather than
     flattening both to normal, which would lose the distinction. */
  if (s === "medium" || s === "med") return "normal";
  if (s === "urgent" || s === "critical" || s === "asap") return "high";
  return "normal";
};

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

/* ---- shared throttling (018) ------------------------------------------------
   A lockout is a fact about the account, not about the process that happened to
   serve the request. These live in the database for one reason: on Vercel every
   cold start used to get an empty Map, so the 5th wrong password was the 1st
   again. Two processes sharing one database were measured — one throttled, the
   other wide open.

   The window slides. `failures` counts within `window_started_at`; once that is
   older than the window, the count restarts from zero. A person who mistypes
   twice on Monday and twice on Friday is not an attacker, and a system that
   treats them as one eventually locks out the whole office.

   Nothing here trusts the caller to be honest about time: `now()` is this
   process's clock, and a bucket whose clock skews forward simply expires
   earlier for itself. */
const THROTTLE = {
  login: { windowMs: 60_000, limit: 5, lockMs: 60_000 },
  ip:    { windowMs: 60_000, limit: 30, lockMs: 60_000 },
};

const throttleKey = (kind, value) => `${kind}:${String(value)}`;

/* Read the bucket and decide, without writing. Called before the password is
   checked so a locked-out caller costs one indexed lookup and no scrypt. */
const throttleState = async (key) => {
  const row = await db.prepare("SELECT * FROM throttle_buckets WHERE bucket_key = ?").get(key);
  if (!row) return { locked: false, failures: 0, retryAfterMs: 0 };
  const t = Date.now();
  const lockedUntil = row.locked_until ? Date.parse(String(row.locked_until)) : 0;
  if (lockedUntil > t) return { locked: true, failures: row.failures, retryAfterMs: lockedUntil - t, row };
  return { locked: false, failures: Number(row.failures) || 0, retryAfterMs: 0, row };
};

/* Record a failure and return the new state. Upsert on the key so two parallel
   instances cannot both insert a fresh bucket for the same account and each
   count from zero — the database row is the single point of agreement. */
const throttleFail = async (kind, value) => {
  const policy = THROTTLE[kind];
  const key = throttleKey(kind, value);
  const t = Date.now();
  const nowIso = new Date(t).toISOString();
  const windowStart = new Date(t - policy.windowMs).toISOString();
  const row = await db.prepare("SELECT * FROM throttle_buckets WHERE bucket_key = ?").get(key);
  let failures;
  let windowStartedAt;
  if (!row) {
    failures = 1; windowStartedAt = nowIso;
    await db.prepare(
      `INSERT INTO throttle_buckets(bucket_key, kind, failures, window_started_at, locked_until, last_seen_at, created_at)
       VALUES(?,?,?,?,?,?,?)`
    ).run(key, kind, failures, windowStartedAt, null, nowIso, nowIso);
  } else {
    /* Slide the window: a bucket whose window has lapsed starts counting again
       rather than carrying Monday's mistypes into Friday. */
    const fresh = iso(row.window_started_at) <= windowStart;
    failures = fresh ? 1 : (Number(row.failures) || 0) + 1;
    windowStartedAt = fresh ? nowIso : iso(row.window_started_at);
    /* EVERY timestamp written back must be an ISO string. Postgres returns a
       Date object for a timestamptz column, and a Date reaching a parameter
       serialises as "Tue Nov 10 2026 17:57:25 GMT+0000 (Coordinated …)" — which
       Postgres rejects with 4276 while SQLite quietly accepts it. String() is
       not enough: String(new Date()) is that same non-ISO form, which is why
       the first fix here still failed the rehearsal. */
    const carryLock = iso(row.locked_until);
    const lockedUntil = failures >= policy.limit ? new Date(t + policy.lockMs).toISOString() : carryLock;
    await db.prepare(
      "UPDATE throttle_buckets SET failures = ?, window_started_at = ?, locked_until = ?, last_seen_at = ? WHERE bucket_key = ?"
    ).run(failures, windowStartedAt, lockedUntil, nowIso, key);
  }
  /* The limit-th failure is the last one accepted as a plain rejection; the
     bucket is armed at that point and the NEXT attempt is refused without
     checking a password. Returning `locked` here would make the 5th answer a
     429 and the caller never learns their 5th attempt was counted — and,
     worse, the count and the lock would stop agreeing with the policy name. */
  const armed = failures >= policy.limit;
  return {
    locked: false,
    armed,
    failures,
    retryAfterMs: armed ? policy.lockMs : 0,
  };
};

/* Clear a bucket after a successful login. Only the account's own bucket — the
   IP bucket is deliberately NOT cleared, because a success from one address
   says nothing about the 40 failures from it, and clearing it would let a
   sprayer with one working credential refresh their allowance. */
const throttleClear = async (kind, value) => {
  await db.prepare("DELETE FROM throttle_buckets WHERE bucket_key = ?").run(throttleKey(kind, value));
};

/* Housekeeping: drop buckets that are neither locked nor recently seen. Without
   it this table grows for every invented email ever typed at the login screen. */
const throttleSweep = async () => {
  const cutoff = new Date(Date.now() - 24 * 3600_000).toISOString();
  await db.prepare(
    "DELETE FROM throttle_buckets WHERE (locked_until IS NULL OR locked_until < ?) AND last_seen_at < ?"
  ).run(new Date().toISOString(), cutoff);
};

/* ---- auth endpoints + team management ---- */
const rowUserLite = (u) => ({ id: u.id, email: u.email, name: u.name, role: u.role, active: !!u.active, updatedAt: u.updated_at });
app.post("/api/auth/login", wrap(async (req, res) => {
  const raw = String(req.body?.email ?? req.body?.username ?? "").trim().toLowerCase();
  const email = raw.includes("@") ? raw : raw + "@woodex.pk"; // username or full email both work
  const pw = String(req.body?.password ?? "");
  /* Two keys guard one login: the account and the address it is coming from.
     The account key stops a targeted password guess; the address key stops one
     source spraying many accounts. Checking before the hash means a locked
     caller never costs a scrypt. */
  const ipk = req.ip ?? "local";
  const acct = await throttleState(throttleKey("login", email));
  const addr = await throttleState(throttleKey("ip", ipk));
  if (acct.locked || addr.locked) {
    const wait = Math.max(acct.retryAfterMs, addr.retryAfterMs, 1000);
    const secs = Math.ceil(wait / 1000);
    res.set("retry-after", String(secs));
    return res.status(429).json({
      error: `too many attempts — try again in ${secs} second${secs === 1 ? "" : "s"}`,
      code: "throttled",
      retryAfterSeconds: secs,
      /* Say WHICH limit tripped. An operator debugging "the owner is locked out"
         needs to know whether to wait for the account or the office IP. */
      scope: acct.locked ? "account" : "address",
    });
  }
  const u = (await (db.prepare("SELECT * FROM users WHERE email=?").get(email)));
  const guess = u ? hashPass(pw, u.pass_salt) : hashPass("x", "00");
  if (!u || !crypto.timingSafeEqual(Buffer.from(guess.hash, "hex"), Buffer.from(u.pass_hash, "hex"))) {
    const after = await throttleFail("login", email);
    await throttleFail("ip", ipk);
    /* A wrong password is the same answer whether or not the address exists —
       otherwise the login form is a directory of who works here. */
    if (after.locked) {
      const secs = Math.ceil(after.retryAfterMs / 1000);
      res.set("retry-after", String(secs));
      return res.status(429).json({
        error: `too many attempts — try again in ${secs} second${secs === 1 ? "" : "s"}`,
        code: "throttled", retryAfterSeconds: secs, scope: "account",
      });
    }
    return res.status(401).json({ error: "wrong email or password" });
  }
  /* A disabled account is refused AFTER the password check, so it costs a
     correct password — but it still records a failure. Otherwise a disabled
     account is a free oracle: unlimited guesses that never trip the throttle,
     with the "disabled" answer confirming when the password was right. */
  if (!u.active) {
    await throttleFail("login", email);
    return res.status(403).json({ error: "account disabled — ask an owner" });
  }
  /* Success clears the ACCOUNT bucket only. The address bucket is deliberately
     left alone: one working credential does not excuse forty failures from the
     same source, and clearing it would let a sprayer refresh their allowance. */
  await throttleClear("login", email);
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
/* ═══════════════════════════ credentials (017) ═══════════════════════════
   Two ways to regain access, deliberately kept apart.

   1. CHANGE — you know the current password. Self-service, immediate.
   2. RESET  — you have forgotten it. A single-use, expiring, hashed token,
      minted only by somebody who already holds `users.manage`.

   Conflating them is how "password reset" becomes an account-takeover vector.
   A reset that anybody can trigger and that never expires is not recovery, it
   is a back door.

   No email is sent: this system has no mail infrastructure. The token is
   returned to the caller who asked for it and handed over by a channel the
   administrator chooses. `channel: "email"` answers 501 and says which missing
   decision it is waiting on, rather than pretending to have sent something. */

const RESET_TTL_MINUTES = 60;
/* Six characters is the floor this system already enforced when an owner sets
   somebody's password. Self-service must not be a downgrade path, so it uses
   the same floor — and says so by name, because "password too short" without a
   number makes people guess. */
const MIN_PASSWORD = 6;

const hashToken = (t) => crypto.createHash("sha256").update(String(t)).digest("hex");
const rowPasswordReset = (r) => ({
  id: r.id, userId: r.user_id, reason: r.reason, requestedBy: r.requested_by,
  createdAt: r.created_at, expiresAt: r.expires_at,
  usedAt: r.used_at, revokedAt: r.revoked_at,
  live: !r.used_at && !r.revoked_at && ts(r.expires_at) > Date.now(),
});

/* Which sessions should this password change end?
   All of them, except the one making the change. A password change is the
   moment somebody believes their old password may be known to someone else;
   leaving other sessions alive means the change did not actually take the
   access away. The current session survives so the person is not thrown out
   of the screen they are standing on. */
const revokeOtherSessions = async (userId, keepToken) => {
  const rows = await db.prepare("SELECT token FROM sessions WHERE user_id = ?").all(userId);
  let ended = 0;
  for (const r of rows) {
    if (keepToken && r.token === keepToken) continue;
    await db.prepare("DELETE FROM sessions WHERE token = ?").run(r.token);
    ended += 1;
  }
  return ended;
};

/* --- 1. change your own password --- */
app.post("/api/auth/change-password", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const current = String(b.currentPassword ?? "");
  const next = String(b.newPassword ?? "");
  if (!current) throw Object.assign(new Error("your current password is required — a change is not a reset"),
    { statusCode: 400, code: "current_required" });
  if (next.length < MIN_PASSWORD) throw Object.assign(new Error(`a new password needs at least ${MIN_PASSWORD} characters`),
    { statusCode: 400, code: "password_too_short" });
  if (next === current) throw Object.assign(new Error("the new password is the same as the current one"),
    { statusCode: 400, code: "password_unchanged" });
  /* Re-read the row: req.user is the session projection and carries no hash. */
  const u = await db.prepare("SELECT * FROM users WHERE id = ?").get(req.user.id);
  if (!u) throw Object.assign(new Error("user not found"), { statusCode: 404 });
  const guess = hashPass(current, u.pass_salt);
  if (guess.hash !== u.pass_hash) {
    throw Object.assign(new Error("that is not your current password"), { statusCode: 401, code: "wrong_password" });
  }
  const { salt, hash } = hashPass(next);
  await db.prepare("UPDATE users SET pass_salt = ?, pass_hash = ?, updated_at = ? WHERE id = ?")
    .run(salt, hash, now(), u.id);
  const ended = await revokeOtherSessions(u.id, requestToken(req));
  await writeAudit(req, { action: "auth.password_changed", entityType: "user", entityId: u.id,
    after: { sessionsEnded: ended } });
  /* Never the password, never the hash — only that it changed and what it cost. */
  await writeActivity({ entityType: "user", entityId: u.id, kind: "system",
    summary: `${u.name} changed their own password (${ended} other session(s) ended)`,
    actor: u.name });
  return { ok: true, sessionsEnded: ended };
}));

/* --- 2. an administrator mints a reset --- */
app.post("/api/users/:id/password-reset", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const reason = String(b.reason ?? "forgotten").toLowerCase();
  if (!["forgotten", "compromised", "offboarding", "initial"].includes(reason)) {
    throw Object.assign(new Error("reason must be forgotten, compromised, offboarding or initial"),
      { statusCode: 400, code: "bad_reason" });
  }
  /* Say plainly what it would take, rather than accepting the request and
     quietly doing nothing. */
  const channel = String(b.channel ?? "manual").toLowerCase();
  if (channel === "email") {
    throw Object.assign(new Error("this system has no mail infrastructure yet, so it cannot deliver a reset by email. The token is returned here for you to pass on directly (or set SMTP credentials and D-5 is answered)."),
      { statusCode: 501, code: "email_not_configured", expose: true });
  }
  const u = await db.prepare("SELECT * FROM users WHERE id = ?").get(req.params.id);
  if (!u) throw Object.assign(new Error("user not found"), { statusCode: 404 });

  /* Any earlier live token is retired first. Two live tokens for one account
     means two valid keys, and nobody can tell which one the person will use —
     so "cancel that link" stops being possible. */
  const superseded = await db.prepare(
    "SELECT id FROM password_resets WHERE user_id = ? AND used_at IS NULL AND revoked_at IS NULL"
  ).all(u.id);
  for (const s of superseded) {
    await db.prepare("UPDATE password_resets SET revoked_at = ? WHERE id = ?").run(now(), s.id);
  }

  const token = crypto.randomBytes(32).toString("hex");
  const t = now();
  const expiresAt = new Date(Date.now() + RESET_TTL_MINUTES * 60_000).toISOString();
  const info = await db.prepare(
    `INSERT INTO password_resets(user_id, token_hash, reason, requested_by, created_at, expires_at)
     VALUES(?,?,?,?,?,?)`
  ).run(u.id, hashToken(token), reason, req.user?.id ?? null, t, expiresAt);
  const created = rowPasswordReset(await db.prepare("SELECT * FROM password_resets WHERE id = ?").get(info.lastInsertRowid));
  await writeAudit(req, { action: "auth.password_reset_issued", entityType: "user", entityId: u.id,
    after: { reason, expiresAt, superseded: superseded.length } });
  await writeActivity({ entityType: "user", entityId: u.id, kind: "system",
    summary: `Password reset issued for ${u.email} (${reason}); expires in ${RESET_TTL_MINUTES} minutes`,
    actor: req.user?.name ?? "system" });
  /* The raw token appears exactly once, here. It is not stored, not logged, and
     cannot be retrieved again — a reset that can be read back later is a
     password held in plaintext by whoever can read the table. */
  return { status: 201, body: {
    reset: { ...created, token }, expiresInMinutes: RESET_TTL_MINUTES,
    superseded: superseded.length,
    notice: "This token is shown once. It is not stored and cannot be retrieved again.",
  } };
}));

/* --- 3. the person spends it --- */
app.post("/api/auth/reset-password", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const token = String(b.token ?? "").trim();
  const next = String(b.newPassword ?? "");
  /* One message for every failure — unknown, spent, revoked, expired. Telling
     an anonymous caller which of those it was turns the endpoint into an oracle
     for guessing tokens. */
  const reject = () => Object.assign(
    new Error("this reset link is not valid, has been used, or has expired — ask for a new one"),
    { statusCode: 400, code: "reset_invalid" });
  if (!token) { throw reject(); }
  if (next.length < MIN_PASSWORD) throw Object.assign(new Error(`a new password needs at least ${MIN_PASSWORD} characters`),
    { statusCode: 400, code: "password_too_short" });

  const row = await db.prepare("SELECT * FROM password_resets WHERE token_hash = ?").get(hashToken(token));
  if (!row) throw reject();
  if (row.used_at) throw reject();
  if (row.revoked_at) throw reject();
  if (ts(row.expires_at) <= Date.now()) throw reject();
  const u = await db.prepare("SELECT * FROM users WHERE id = ?").get(row.user_id);
  if (!u) throw reject();
  if (!u.active) {
    throw Object.assign(new Error("this account is disabled — ask an owner to re-enable it"),
      { statusCode: 403, code: "account_disabled" });
  }

  const { salt, hash } = hashPass(next);
  await db.prepare("UPDATE users SET pass_salt = ?, pass_hash = ?, updated_at = ? WHERE id = ?")
    .run(salt, hash, now(), u.id);
  await db.prepare("UPDATE password_resets SET used_at = ? WHERE id = ?").run(now(), row.id);
  /* A reset is stronger than a change: the person was locked out, so every
     session goes — including any belonging to whoever may have taken the
     account. They sign in with the new password immediately. */
  const ended = await revokeOtherSessions(u.id, null);
  const req2 = { ...req, user: { id: u.id, email: u.email, name: u.name, role: u.role } };
  await writeAudit(req2, { action: "auth.password_reset_used", entityType: "user", entityId: u.id,
    after: { resetId: row.id, reason: row.reason, sessionsEnded: ended } });
  await writeActivity({ entityType: "user", entityId: u.id, kind: "system",
    summary: `${u.email} recovered their account with a reset token (${ended} session(s) ended)`,
    actor: u.name });
  return { ok: true, sessionsEnded: ended, email: u.email };
}));

/* --- 4. an administrator withdraws one --- */
app.post("/api/users/:id/password-reset/revoke", wrapTxJson(async (req, res) => {
  const u = await db.prepare("SELECT * FROM users WHERE id = ?").get(req.params.id);
  if (!u) throw Object.assign(new Error("user not found"), { statusCode: 404 });
  const live = await db.prepare(
    "SELECT id FROM password_resets WHERE user_id = ? AND used_at IS NULL AND revoked_at IS NULL"
  ).all(u.id);
  if (!live.length) throw Object.assign(new Error("there is no live reset for this user"),
    { statusCode: 409, code: "no_live_reset" });
  const t = now();
  for (const r of live) await db.prepare("UPDATE password_resets SET revoked_at = ? WHERE id = ?").run(t, r.id);
  await writeAudit(req, { action: "auth.password_reset_revoked", entityType: "user", entityId: u.id,
    after: { revoked: live.length } });
  return { ok: true, revoked: live.length };
}));

/* --- 5. who has a live reset, so nobody forgets one is outstanding --- */
app.get("/api/users/:id/password-resets", wrap(async (req, res) => {
  const u = await db.prepare("SELECT * FROM users WHERE id = ?").get(req.params.id);
  if (!u) throw Object.assign(new Error("user not found"), { statusCode: 404 });
  const rows = await db.prepare(
    "SELECT * FROM password_resets WHERE user_id = ? ORDER BY created_at DESC LIMIT 20"
  ).all(u.id);
  const items = rows.map(rowPasswordReset);
  res.json({ total: items.length, live: items.filter((r) => r.live).length, items });
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
  /* Privilege changes are the classic insider-risk action: a role bump or a
     password reset is how access is gained without anyone noticing. Never log
     the password itself — only that it changed. */
  if (role !== undefined || active !== undefined || password !== undefined)
    await writeAudit(req, {
      action: "user.privileges_changed",
      entityType: "user",
      entityId: u.id,
      before: { role: u.role, active: !!u.active },
      after: { role: role ?? u.role, active: active !== undefined ? !!active : !!u.active, passwordReset: password !== undefined },
    });
  emit("users", { title: "Team update: " + u.name, who: req.actor });
  res.json(rowUserLite((await (db.prepare("SELECT * FROM users WHERE id=?").get(u.id)))));
}));

/* ---------- migration-alignment check ----------
   A table can exist in the migrations, pass every local gate, ship to
   production, and still not exist in the HOSTED database. That happened:
   008_audit_log.sql merged, the deployment went READY, the API answered every
   request, and every audit write failed silently — because writeAudit swallows
   errors on purpose so a logging failure cannot break a payment.

   Nothing in the build touches the hosted database, so this is the first point
   in the whole pipeline where the deployed code and the real schema meet. It
   runs on demand, not at boot: a boot-time throw would take the API down over a
   soft dependency, and answering 503 for an audit gap is a worse outage than
   the gap.

   Tolerated by design: a completely bare database (schema never applied) reports
   "pending" rather than "misaligned", because that is a fresh environment, not a
   half-migrated one, and an alert that fires on every new environment gets muted.

   Returns a MISSING TABLE NAME, not a count. "One table is missing" tells an
   operator nothing they can act on. */

/* Tables added by migrations, with the version that introduced them. The base
   schema is excluded: if it were absent the application would not start at all,
   so it is already covered by every other gate. */
const MIGRATION_TABLES = [
  { table: "stock_movements", version: 3 },
  { table: "stock_reservations", version: 3 },
  { table: "audit_logs", version: 8 },
  { table: "suppliers", version: 9 },
  { table: "purchase_orders", version: 9 },
  { table: "purchase_order_lines", version: 9 },
  { table: "goods_receipts", version: 9 },
  { table: "goods_receipt_lines", version: 9 },
  { table: "supplier_invoices", version: 10 },
  { table: "warehouses", version: 12 },
  { table: "storage_locations", version: 12 },
  { table: "stock_levels", version: 12 },
  { table: "stock_transfers", version: 12 },
  { table: "stock_transfer_lines", version: 12 },
];

async function migrationAlignment() {
  /* Only PostgreSQL has a migration ledger. SQLite is built by CREATE TABLE IF
     NOT EXISTS at boot and has no ordered ledger to compare against, so on
     SQLite the honest answer is "not applicable" — reporting "aligned" would
     claim verification that did not happen, and "misaligned" would fire on a
     perfectly healthy local database.

     The test is `sqlite`, NOT `postgres`, because the adapter reports three
     kinds — sqlite, pglite and postgres — and pglite is PostgreSQL: it has the
     ledger and information_schema. Testing for "postgres" sent pglite down the
     sqlite_master branch, where it threw `relation "sqlite_master" does not
     exist`. The rehearsal engine has to take the same path as production or the
     rehearsal proves nothing. */
  const isSqlite = db.kind === "sqlite";
  if (isSqlite) {
    const present = new Set();
    for (const t of MIGRATION_TABLES) {
      const row = (await db.prepare("SELECT name FROM sqlite_master WHERE type='table' AND name=?").get(t.table));
      if (row) present.add(t.table);
    }
    const missing = MIGRATION_TABLES.filter((t) => !present.has(t.table)).map((t) => t.table);
    return {
      status: "not-applicable",
      driver: db.kind,
      detail: `${db.kind} has no migration ledger; schema is created idempotently at boot`,
      tables: MIGRATION_TABLES.map((t) => t.table),
      missingTables: missing,
      fix: null,
    };
  }

  let ledger;
  try {
    ledger = (await db.prepare("SELECT version, name FROM schema_migrations ORDER BY version").all())
      .map((r) => ({ version: Number(r.version), name: r.name }));
  } catch {
    /* No ledger table at all. Treat as un-migrated, not broken. */
    return {
      status: "pending",
      applied: [],
      expectedTable: MIGRATION_TABLES.length,
      detail: "schema_migrations does not exist — the schema has never been applied here",
      fix: "run: DATABASE_URL=... npm run db:migrate",
    };
  }
  if (!ledger.length) {
    return {
      status: "pending",
      applied: [],
      expectedTable: MIGRATION_TABLES.length,
      detail: "no migrations recorded — the schema has never been applied here",
      fix: "run: DATABASE_URL=... npm run db:migrate",
    };
  }

  const appliedVersions = new Set(ledger.map((r) => r.version));

  /* Which of the tracked tables physically exist, asked once for all of them. */
  let present;
  const want = MIGRATION_TABLES.map((t) => t.table);
  const placeholders = want.map(() => "?").join(",");
  try {
    if (!isSqlite) {
      const rows = (await db.prepare(
        "SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema() AND table_name IN (" + placeholders + ")"
      ).all(...want));
      present = new Set(rows.map((r) => String(r.table_name ?? r.TABLE_NAME ?? "").toLowerCase()));
    } else {
      const rows = (await db.prepare(
        "SELECT name FROM sqlite_master WHERE type='table' AND name IN (" + placeholders + ")"
      ).all(...want));
      present = new Set(rows.map((r) => String(r.name).toLowerCase()));
    }
  } catch (e) {
    return { status: "unknown", applied: ledger, detail: `could not inspect the schema: ${e?.message ?? e}`, fix: null };
  }

  const missingTables = MIGRATION_TABLES.filter((t) => !present.has(t.table)).map((t) => t.table);
  /* A migration recorded in the ledger whose file expects a table that is not
     there means the ledger and the schema disagree — the exact drift this
     exists to surface. */
  const unappliedVersions = [...new Set(MIGRATION_TABLES.map((t) => t.version))]
    .filter((v) => !appliedVersions.has(v));

  const aligned = missingTables.length === 0;
  return {
    status: aligned ? "aligned" : "misaligned",
    applied: ledger.map((r) => r.version),
    latest: ledger.length ? ledger[ledger.length - 1].version : null,
    tables: want,
    missingTables,
    unappliedVersions,
    detail: aligned
      ? `all ${want.length} migration-owned tables exist`
      : `table(s) missing from the hosted database: ${missingTables.join(", ")}`,
    fix: aligned ? null : "run: DATABASE_URL=... npm run db:migrate — the deployed code expects these tables",
  };
}

/* ══════════════════════════ production / BOM ══════════════════════════
   Bills of materials, job cards, material issue and finished output.

   The rule this block exists to enforce: issuing a component DECREMENTS stock
   exactly once (and a finish code never at all), and a job card cannot be
   marked done without passing QC. Both are asserted in
   scripts/test-production.mjs, including the direction neither check would
   catch on its own — that the output credit is not double-counted.

   Nothing here writes stock_qty directly. Every movement goes through
   applyStockMovement, with reason 'production'. */

const JC_STAGES = new Set(["queued", "in_progress", "qc", "done", "cancelled"]);
/* The forward path. Anything not listed is refused, including going backwards:
   work does not un-happen, and a stage that can regress makes every report
   built on it unreliable. */
const JC_NEXT = {
  queued: ["in_progress", "cancelled"],
  in_progress: ["qc", "cancelled"],
  qc: ["done", "in_progress"],
  done: [],
  cancelled: [],
};

const rowBomLine = (r) => ({
  id: r.id, bomId: r.bom_id, lineNo: r.line_no, itemKind: r.item_kind, itemId: r.item_id,
  qtyPerOutput: r.qty_per_output, unit: r.unit, note: r.note,
});
const rowBom = (r) => ({
  id: r.id, ref: r.ref, productId: r.product_id, name: r.name, version: r.version,
  outputQty: r.output_qty, active: !!r.active, notes: r.notes,
  createdAt: r.created_at, updatedAt: r.updated_at,
});
const rowJobCard = (r) => ({
  id: r.id, ref: r.ref, bomId: r.bom_id, productId: r.product_id, orderId: r.order_id,
  qtyToBuild: r.qty_to_build, qtyBuilt: r.qty_built, stage: r.stage, assignedTo: r.assigned_to,
  startedAt: r.started_at, completedAt: r.completed_at, dueAt: r.due_at, notes: r.notes,
  qcPassedAt: r.qc_passed_at, qcBy: r.qc_by, qcNote: r.qc_note,
  createdBy: r.created_by, createdAt: r.created_at, updatedAt: r.updated_at,
});
const rowJcMaterial = (r) => ({
  id: r.id, jobCardId: r.job_card_id, lineNo: r.line_no, itemKind: r.item_kind, itemId: r.item_id,
  qtyRequired: r.qty_required, qtyIssued: r.qty_issued,
  qtyOutstanding: Math.max(0, r.qty_required - r.qty_issued),
});

/* ids are BIGINT on Postgres. Binding "BOM-1001" to the id parameter is a cast
   error there, not a miss, so the numeric branch is taken only when the input
   actually looks numeric. (The older routes use `id=? OR ref=?` throughout and
   are fine because callers pass ids; these helpers are given refs by humans.) */
const looksNumeric = (v) => /^\d+$/.test(String(v ?? "").trim());
const bomById = async (id) =>
  looksNumeric(id)
    ? await db.prepare("SELECT * FROM boms WHERE id=? OR ref=?").get(Number(id), String(id))
    : await db.prepare("SELECT * FROM boms WHERE ref=?").get(String(id));
const jobCardById = async (id) =>
  looksNumeric(id)
    ? await db.prepare("SELECT * FROM job_cards WHERE id=? OR ref=?").get(Number(id), String(id))
    : await db.prepare("SELECT * FROM job_cards WHERE ref=?").get(String(id));
const jobCardMaterials = async (jobCardId) =>
  (await db.prepare("SELECT * FROM job_card_materials WHERE job_card_id=? ORDER BY line_no").all(jobCardId)).map(rowJcMaterial);

/* A job card with its materials and the movements actually posted against it,
   so "issued" is never merely what a column says. */
const jobCardFull = async (r) => {
  const materials = await jobCardMaterials(r.id);
  const movements = (await db.prepare(
    `SELECT id, product_id, delta, reason, ref_type, ref_id, note, created_by, created_at
       FROM stock_movements
      WHERE (ref_type = 'job_card_material' AND ref_id IN
              (SELECT id FROM job_card_materials WHERE job_card_id = ?))
         OR (ref_type = 'job_card_output' AND ref_id = ?)
      ORDER BY id`).all(r.id, r.id))
    .map((m) => ({ id: m.id, productId: m.product_id, delta: Number(m.delta), reason: m.reason,
      refType: m.ref_type, refId: m.ref_id, note: m.note, by: m.created_by, at: m.created_at }));
  return { ...rowJobCard(r), materials, movements };
};

/* ══════════════════════ stock ledger (read side) ══════════════════════

   Until now the ledger was write-only: every stock change appended a row and
   nothing could read them back. The history existed — it was just inaccessible
   without psql, which means nobody could answer "why is this 85 and not 90?".

   This is the read side, deliberately shaped around the one question that
   matters: does the cached number on the product agree with its movements?
   A drift row is not a warning to be scrolled past, it is the failure the whole
   ledger exists to prevent, so it is computed here rather than left to a script. */

const rowMovement = (r) => ({
  id: r.id, productId: r.product_id, delta: Number(r.delta), reason: r.reason,
  refType: r.ref_type, refId: r.ref_id, note: r.note, by: r.created_by, at: r.created_at,
  /* 012. Null means "recorded before location tracking existed", which is a
     fact about the row, not a missing field. */
  locationId: r.location_id ?? null,
});

app.get("/api/stock-movements", wrap(async (req, res) => {
  const limit = Math.min(1000, Math.max(1, Number(req.query.limit) || 200));
  const where = [];
  const params = [];
  if (req.query.product_id) { where.push("product_id = ?"); params.push(String(req.query.product_id)); }
  if (req.query.reason) { where.push("reason = ?"); params.push(String(req.query.reason).toLowerCase()); }
  if (req.query.ref_type) { where.push("ref_type = ?"); params.push(String(req.query.ref_type)); }
  if (req.query.ref_id) { where.push("ref_id = ?"); params.push(Number(req.query.ref_id)); }
  const clause = where.length ? "WHERE " + where.join(" AND ") + " " : "";
  const rows = (await db.prepare(
    `SELECT * FROM stock_movements ${clause}ORDER BY id DESC LIMIT ?`
  ).all(...params, limit)).map(rowMovement);

  /* Totals for the filtered set, so a page of 200 rows still reports the true
     in/out for the whole slice rather than for the page. */
  const totals = await db.prepare(
    `SELECT COALESCE(SUM(CASE WHEN delta > 0 THEN delta ELSE 0 END),0) AS inn,
            COALESCE(SUM(CASE WHEN delta < 0 THEN -delta ELSE 0 END),0) AS out
       FROM stock_movements ${clause}`
  ).get(...params);

  res.json({
    total: rows.length,
    in: Number(totals?.inn ?? 0),
    out: Number(totals?.out ?? 0),
    items: rows,
  });
}));

/* Reconciliation: every product's cached stock_qty against the sum of its
   movements. This is the Phase 7 acceptance criterion expressed as an endpoint —
   "inventory balances reconcile with the stock movement ledger" — and it must
   be observable in the running product, not only inside a test. */
app.get("/api/stock-movements/reconcile", wrap(async (_req, res) => {
  const products = await db.prepare("SELECT id, stock_qty, json FROM products").all();
  const sums = new Map(
    (await db.prepare("SELECT product_id, COALESCE(SUM(delta),0) AS q FROM stock_movements GROUP BY product_id").all())
      .map((r) => [r.product_id, Number(r.q)])
  );
  const drifted = [];
  for (const p of products) {
    const ledger = sums.get(p.id) ?? 0;
    const cached = Number(p.stock_qty) || 0;
    if (ledger !== cached) {
      drifted.push({
        productId: p.id,
        name: safeJson(p.json, {})?.name ?? p.id,
        stockQty: cached, ledgerQty: ledger, difference: cached - ledger,
      });
    }
  }
  const movements = (await db.prepare("SELECT COUNT(*) AS n FROM stock_movements").get())?.n ?? 0;
  res.json({
    ok: drifted.length === 0,
    products: products.length,
    movements: Number(movements),
    drifted: drifted.length,
    items: drifted,
  });
}));


/* ══════════════════════════ inventory depth (012) ══════════════════════════
   Warehouses, storage locations, transfers and reservations.

   012's header explains why a warehouse and a location are separate. This block
   adds the three things a reader needs from them:

     1. Where a product actually is  — GET /api/stock-levels
     2. How goods get from A to B    — transfers, as two ledger rows
     3. What is promised but not yet gone — reservations

   The invariant this whole block is built to keep: for every product,
   SUM(stock_levels.qty) = SUM(stock_movements.delta) = products.stock_qty.
   /api/stock-levels/reconcile reports a violation instead of hiding it, and the
   test suite asserts drift is zero after transfer and reservation traffic. */

const WAREHOUSE_FIELDS = ["code", "name", "city", "address", "manager", "phone", "notes"];

const rowWarehouse = (r) => ({
  id: r.id, code: r.code, name: r.name, city: r.city, address: r.address,
  manager: r.manager, phone: r.phone, isDefault: !!r.is_default, active: !!r.active,
  notes: r.notes, createdAt: r.created_at, updatedAt: r.updated_at,
});
const rowLocation = (r) => ({
  id: r.id, warehouseId: r.warehouse_id, warehouseName: r.warehouse_name ?? null,
  warehouseCode: r.warehouse_code ?? null, code: r.code, name: r.name, zone: r.zone,
  active: !!r.active, notes: r.notes, createdAt: r.created_at, updatedAt: r.updated_at,
});
const rowTransfer = (r) => ({
  id: r.id, ref: r.ref, fromLocationId: r.from_location_id, toLocationId: r.to_location_id,
  fromLabel: r.from_label ?? null, toLabel: r.to_label ?? null,
  status: r.status, note: r.note, createdBy: r.created_by,
  dispatchedAt: r.dispatched_at, receivedAt: r.received_at,
  createdAt: r.created_at, updatedAt: r.updated_at,
});
const rowReservation = (r) => ({
  id: r.id, productId: r.product_id, productName: r.product_name ?? null,
  qty: Number(r.qty), refType: r.ref_type, refId: r.ref_id, status: r.status,
  expiresAt: r.expires_at, releasedAt: r.released_at, createdAt: r.created_at,
});

/* ───────────────────────────── warehouses ───────────────────────────── */

app.get("/api/warehouses", wrap(async (req, res) => {
  let rows = await db.prepare("SELECT * FROM warehouses ORDER BY is_default DESC, id ASC").all();
  if (req.query.active !== undefined) {
    const want = ["1", "true", "yes"].includes(String(req.query.active).toLowerCase());
    rows = rows.filter((w) => !!w.active === want);
  }
  /* Location counts in one grouped query — a warehouse list with a per-row
     count is the classic N+1 and it shows up immediately on a cold lambda. */
  const counts = new Map(
    (await db.prepare("SELECT warehouse_id, COUNT(*) AS n FROM storage_locations WHERE active = 1 GROUP BY warehouse_id").all())
      .map((r) => [Number(r.warehouse_id), Number(r.n)])
  );
  const items = rows.map((r) => ({ ...rowWarehouse(r), locations: counts.get(Number(r.id)) ?? 0 }));
  res.json({ total: items.length, active: items.filter((w) => w.active).length, items });
}));

app.post("/api/warehouses", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  for (const k of ["code", "name"]) {
    if (!String(b[k] ?? "").trim()) throw Object.assign(new Error(`${k} is required`), { statusCode: 400 });
  }
  const code = String(b.code).trim().toUpperCase();
  /* Checked before the INSERT so a duplicate is a 409 with a sentence rather
     than a 500 from the UNIQUE index. The same reason procurement's supplier
     route checks its code first. */
  const dupe = await db.prepare("SELECT id FROM warehouses WHERE code = ?").get(code);
  if (dupe) throw Object.assign(new Error(`warehouse code ${code} already exists`), { statusCode: 409, code: "warehouse_exists" });
  const t = now();
  let wantDefault = b.isDefault === true || b.is_default === true;
  /* First warehouse is the default whether or not the caller said so: a
     warehouse that is not the default and no other warehouse exists means every
     movement has nowhere to land. */
  const existing = Number((await db.prepare("SELECT COUNT(*) AS n FROM warehouses").get())?.n ?? 0);
  if (existing === 0) wantDefault = true;
  if (wantDefault) await db.prepare("UPDATE warehouses SET is_default = 0 WHERE is_default = 1").run();
  const info = await db.prepare(
    `INSERT INTO warehouses(code, name, city, address, manager, phone, is_default, active, notes, created_at, updated_at)
     VALUES(?,?,?,?,?,?,?,?,?,?,?)`
  ).run(code, String(b.name).trim(), b.city ?? null, b.address ?? null, b.manager ?? null, b.phone ?? null,
    wantDefault ? 1 : 0, b.active === false ? 0 : 1, b.notes ?? null, t, t);
  invalidateDefaultLocation();
  const created = rowWarehouse(await db.prepare("SELECT * FROM warehouses WHERE id = ?").get(info.lastInsertRowid));
  await writeAudit(req, { action: "warehouse.created", entityType: "warehouse", entityId: created.id, after: created });
  return { warehouse: created };
}));

app.patch("/api/warehouses/:id", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const row = await db.prepare("SELECT * FROM warehouses WHERE id = ?").get(req.params.id);
  if (!row) throw Object.assign(new Error("warehouse not found"), { statusCode: 404 });
  const before = rowWarehouse(row);
  const sets = [];
  const params = [];
  for (const f of WAREHOUSE_FIELDS) {
    if (b[f] === undefined) continue;
    const snake = f;
    sets.push(`${snake} = ?`);
    params.push(f === "code" ? String(b[f]).trim().toUpperCase() : (b[f] === null ? null : String(b[f])));
  }
  const wantDefault = b.isDefault === true || b.is_default === true;
  const wantActive = b.active === undefined ? !!row.active : b.active === true;
  if (wantDefault) {
    /* Demoting is not offered: "no default warehouse" is a state the fallback
       location resolution cannot recover from, so a caller marks a different
       warehouse as default instead. */
    await db.prepare("UPDATE warehouses SET is_default = 0 WHERE is_default = 1").run();
    sets.push("is_default = ?"); params.push(1);
  }
  if (b.active !== undefined) { sets.push("active = ?"); params.push(wantActive ? 1 : 0); }
  if (!sets.length) throw Object.assign(new Error("nothing to update"), { statusCode: 400 });
  sets.push("updated_at = ?"); params.push(now(), row.id);
  await db.prepare(`UPDATE warehouses SET ${sets.join(", ")} WHERE id = ?`).run(...params);
  invalidateDefaultLocation();
  const after = rowWarehouse(await db.prepare("SELECT * FROM warehouses WHERE id = ?").get(row.id));
  await writeAudit(req, { action: "warehouse.updated", entityType: "warehouse", entityId: row.id, before, after });
  return { warehouse: after };
}));

/* ────────────────────────── storage locations ────────────────────────── */

app.get("/api/storage-locations", wrap(async (req, res) => {
  const where = [];
  const params = [];
  if (req.query.warehouse_id) { where.push("l.warehouse_id = ?"); params.push(Number(req.query.warehouse_id)); }
  if (req.query.active !== undefined) {
    where.push("l.active = ?");
    params.push(["1", "true", "yes"].includes(String(req.query.active).toLowerCase()) ? 1 : 0);
  }
  const rows = await db.prepare(
    `SELECT l.*, w.name AS warehouse_name, w.code AS warehouse_code
       FROM storage_locations l JOIN warehouses w ON w.id = l.warehouse_id
      ${where.length ? "WHERE " + where.join(" AND ") : ""}
      ORDER BY w.is_default DESC, w.id, l.code`
  ).all(...params);
  res.json({ total: rows.length, items: rows.map(rowLocation) });
}));

app.post("/api/storage-locations", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const warehouseId = Number(b.warehouseId ?? b.warehouse_id);
  if (!Number.isInteger(warehouseId)) throw Object.assign(new Error("warehouseId is required"), { statusCode: 400 });
  const wh = await db.prepare("SELECT id FROM warehouses WHERE id = ?").get(warehouseId);
  if (!wh) throw Object.assign(new Error("warehouse not found: " + warehouseId), { statusCode: 404 });
  for (const k of ["code", "name"]) {
    if (!String(b[k] ?? "").trim()) throw Object.assign(new Error(`${k} is required`), { statusCode: 400 });
  }
  /* A location code is unique per warehouse, not globally: every godown has an
     "A1" and that is not a conflict. */
  const code = String(b.code).trim().toUpperCase();
  const dup = await db.prepare("SELECT id FROM storage_locations WHERE warehouse_id = ? AND code = ?").get(warehouseId, code);
  if (dup) throw Object.assign(new Error(`location ${code} already exists in that warehouse`), { statusCode: 409, code: "location_exists" });
  const t = now();
  const info = await db.prepare(
    `INSERT INTO storage_locations(warehouse_id, code, name, zone, active, notes, created_at, updated_at)
     VALUES(?,?,?,?,?,?,?,?)`
  ).run(warehouseId, code, String(b.name).trim(), b.zone ?? null, b.active === false ? 0 : 1, b.notes ?? null, t, t);
  invalidateDefaultLocation();
  const created = rowLocation(await db.prepare(
    `SELECT l.*, w.name AS warehouse_name, w.code AS warehouse_code
       FROM storage_locations l JOIN warehouses w ON w.id = l.warehouse_id WHERE l.id = ?`
  ).get(info.lastInsertRowid));
  await writeAudit(req, { action: "storage_location.created", entityType: "storage_location", entityId: created.id, after: created });
  return { location: created };
}));

app.patch("/api/storage-locations/:id", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const row = await db.prepare("SELECT * FROM storage_locations WHERE id = ?").get(req.params.id);
  if (!row) throw Object.assign(new Error("storage location not found"), { statusCode: 404 });
  const before = rowLocation(row);
  const sets = [];
  const params = [];
  for (const f of ["code", "name", "zone", "notes"]) {
    if (b[f] === undefined) continue;
    sets.push(`${f} = ?`);
    params.push(f === "code" ? String(b[f]).trim().toUpperCase() : (b[f] === null ? null : String(b[f])));
  }
  if (b.active !== undefined) { sets.push("active = ?"); params.push(b.active === true ? 1 : 0); }
  if (!sets.length) throw Object.assign(new Error("nothing to update"), { statusCode: 400 });
  sets.push("updated_at = ?"); params.push(now(), row.id);
  await db.prepare(`UPDATE storage_locations SET ${sets.join(", ")} WHERE id = ?`).run(...params);
  invalidateDefaultLocation();
  const after = rowLocation(await db.prepare("SELECT * FROM storage_locations WHERE id = ?").get(row.id));
  await writeAudit(req, { action: "storage_location.updated", entityType: "storage_location", entityId: row.id, before, after });
  return { location: after };
}));

/* ─────────────────────────── stock by location ─────────────────────────── */

app.get("/api/stock-levels", wrap(async (req, res) => {
  const where = [];
  const params = [];
  if (req.query.product_id) { where.push("s.product_id = ?"); params.push(String(req.query.product_id)); }
  if (req.query.location_id) { where.push("s.location_id = ?"); params.push(Number(req.query.location_id)); }
  if (req.query.warehouse_id) { where.push("l.warehouse_id = ?"); params.push(Number(req.query.warehouse_id)); }
  const rows = await db.prepare(
    `SELECT s.*, l.code AS location_code, l.name AS location_name, w.id AS warehouse_id,
            w.code AS warehouse_code, w.name AS warehouse_name, p.json AS product_json
       FROM stock_levels s
       JOIN storage_locations l ON l.id = s.location_id
       JOIN warehouses w ON w.id = l.warehouse_id
       LEFT JOIN products p ON p.id = s.product_id
      ${where.length ? "WHERE " + where.join(" AND ") : ""}
      ORDER BY s.product_id, w.id, l.code`
  ).all(...params);
  const items = rows.map((r) => ({
    productId: r.product_id,
    productName: safeJson(r.product_json, {})?.name ?? r.product_id,
    locationId: r.location_id, locationCode: r.location_code, locationName: r.location_name,
    warehouseId: r.warehouse_id, warehouseCode: r.warehouse_code, warehouseName: r.warehouse_name,
    qty: Number(r.qty), updatedAt: r.updated_at,
  }));
  res.json({ total: items.length, onHand: items.reduce((a, i) => a + i.qty, 0), items });
}));

/* The three-way equality that makes stock_levels safe to exist:
     products.stock_qty  ==  SUM(stock_movements.delta)
     SUM(stock_levels)   ==  SUM(stock_movements.delta) for located rows
   Both comparisons are reported separately because they fail for different
   reasons and a single "ok" would hide which invariant broke. */
app.get("/api/stock-levels/reconcile", wrap(async (_req, res) => {
  const products = await db.prepare("SELECT id, stock_qty, json FROM products").all();
  const ledger = new Map(
    (await db.prepare("SELECT product_id, COALESCE(SUM(delta),0) AS q FROM stock_movements GROUP BY product_id").all())
      .map((r) => [r.product_id, Number(r.q)])
  );
  /* Only located movements feed the per-location projection; unlocated history
     predates 012 and is excluded from the second comparison by definition. */
  const located = new Map(
    (await db.prepare("SELECT product_id, COALESCE(SUM(delta),0) AS q FROM stock_movements WHERE location_id IS NOT NULL GROUP BY product_id").all())
      .map((r) => [r.product_id, Number(r.q)])
  );
  const levels = new Map(
    (await db.prepare("SELECT product_id, COALESCE(SUM(qty),0) AS q FROM stock_levels GROUP BY product_id").all())
      .map((r) => [r.product_id, Number(r.q)])
  );
  const cacheDrift = [];
  const levelDrift = [];
  for (const p of products) {
    const cached = Number(p.stock_qty) || 0;
    const led = ledger.get(p.id) ?? 0;
    if (cached !== led) {
      cacheDrift.push({ productId: p.id, name: safeJson(p.json, {})?.name ?? p.id, stockQty: cached, ledgerQty: led, difference: cached - led });
    }
    const loc = located.get(p.id) ?? 0;
    const sum = levels.get(p.id) ?? 0;
    if (loc !== sum) {
      levelDrift.push({ productId: p.id, name: safeJson(p.json, {})?.name ?? p.id, levelsQty: sum, locatedLedgerQty: loc, difference: sum - loc });
    }
  }
  res.json({
    ok: cacheDrift.length === 0 && levelDrift.length === 0,
    products: products.length,
    locations: Number((await db.prepare("SELECT COUNT(*) AS n FROM storage_locations").get())?.n ?? 0),
    cacheDrift: cacheDrift.length,
    levelDrift: levelDrift.length,
    items: [...cacheDrift.map((d) => ({ ...d, kind: "stock_qty" })), ...levelDrift.map((d) => ({ ...d, kind: "stock_levels" }))],
  });
}));

/* ──────────────────────────── stock transfers ──────────────────────────── */

const TRANSFER_JOIN = `
  SELECT t.*,
         (fl.code || ' · ' || fw.code) AS from_label,
         (tl.code || ' · ' || tw.code) AS to_label
    FROM stock_transfers t
    JOIN storage_locations fl ON fl.id = t.from_location_id
    JOIN warehouses fw ON fw.id = fl.warehouse_id
    JOIN storage_locations tl ON tl.id = t.to_location_id
    JOIN warehouses tw ON tw.id = tl.warehouse_id`;

const transferLines = async (transferId) => (await db.prepare(
  `SELECT tsl.*, p.json AS product_json FROM stock_transfer_lines tsl
     LEFT JOIN products p ON p.id = tsl.product_id
    WHERE tsl.transfer_id = ? ORDER BY tsl.id`
).all(transferId)).map((r) => ({
  id: r.id, productId: r.product_id, productName: safeJson(r.product_json, {})?.name ?? r.product_id, qty: Number(r.qty),
}));

const transferFull = async (id) => {
  const row = await db.prepare(`${TRANSFER_JOIN} WHERE t.id = ?`).get(id);
  if (!row) return null;
  const lines = await transferLines(id);
  return { ...rowTransfer(row), lines, qty: lines.reduce((a, l) => a + l.qty, 0) };
};

app.get("/api/stock-transfers", wrap(async (req, res) => {
  const where = [];
  const params = [];
  if (req.query.status) { where.push("t.status = ?"); params.push(String(req.query.status)); }
  if (req.query.warehouse_id) {
    where.push("(fl.warehouse_id = ? OR tl.warehouse_id = ?)");
    params.push(Number(req.query.warehouse_id), Number(req.query.warehouse_id));
  }
  const rows = await db.prepare(`${TRANSFER_JOIN} ${where.length ? "WHERE " + where.join(" AND ") : ""} ORDER BY t.id DESC LIMIT 200`)
    .all(...params);
  const items = [];
  for (const r of rows) {
    const lines = await transferLines(r.id);
    items.push({ ...rowTransfer(r), lines, qty: lines.reduce((a, l) => a + l.qty, 0) });
  }
  res.json({ total: items.length, items });
}));

app.get("/api/stock-transfers/:id", wrap(async (req, res) => {
  const t = await transferFull(req.params.id);
  if (!t) throw Object.assign(new Error("transfer not found"), { statusCode: 404 });
  res.json({ transfer: t });
}));

app.post("/api/stock-transfers", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const fromId = Number(b.fromLocationId ?? b.from_location_id);
  const toId = Number(b.toLocationId ?? b.to_location_id);
  if (!Number.isInteger(fromId) || !Number.isInteger(toId)) {
    throw Object.assign(new Error("fromLocationId and toLocationId are required"), { statusCode: 400 });
  }
  if (fromId === toId) throw Object.assign(new Error("a transfer must move stock to a different location"), { statusCode: 400, code: "same_location" });
  for (const id of [fromId, toId]) {
    const loc = await db.prepare("SELECT id, active FROM storage_locations WHERE id = ?").get(id);
    if (!loc) throw Object.assign(new Error("storage location not found: " + id), { statusCode: 404 });
    if (!loc.active) throw Object.assign(new Error("storage location " + id + " is inactive"), { statusCode: 409, code: "location_inactive" });
  }
  const lines = Array.isArray(b.lines) ? b.lines : [];
  if (!lines.length) throw Object.assign(new Error("at least one line is required"), { statusCode: 400 });
  const seen = new Set();
  const prepared = [];
  for (const l of lines) {
    const productId = String(l.productId ?? l.product_id ?? "").trim();
    const qty = Number(l.qty);
    if (!productId) throw Object.assign(new Error("each line needs a productId"), { statusCode: 400 });
    if (!Number.isInteger(qty) || qty <= 0) throw Object.assign(new Error(`qty for ${productId} must be a positive whole number`), { statusCode: 400 });
    /* Two lines for one product would try to debit the same units twice from one
       document; the schema's UNIQUE would reject it as a 500, so it is a 400. */
    if (seen.has(productId)) throw Object.assign(new Error(`duplicate product on transfer: ${productId}`), { statusCode: 400, code: "duplicate_line" });
    seen.add(productId);
    const p = await db.prepare("SELECT id FROM products WHERE id = ?").get(productId);
    if (!p) throw Object.assign(new Error("product not found: " + productId), { statusCode: 404 });
    prepared.push({ productId, qty });
  }
  const ref = await nextRef("stock_transfers", "TRF-", 1001);
  const t = now();
  const info = await db.prepare(
    `INSERT INTO stock_transfers(ref, from_location_id, to_location_id, status, note, created_by, created_at, updated_at)
     VALUES(?,?,?,'draft',?,?,?,?)`
  ).run(ref, fromId, toId, b.note ?? null, req.user?.name ?? req.actor ?? "system", t, t);
  const id = Number(info.lastInsertRowid);
  for (const line of prepared) {
    await db.prepare("INSERT INTO stock_transfer_lines(transfer_id, product_id, qty) VALUES(?,?,?)").run(id, line.productId, line.qty);
  }
  const created = await transferFull(id);
  await writeAudit(req, { action: "stock_transfer.created", entityType: "stock_transfer", entityId: id, after: created });
  return { transfer: created };
}));

/* draft → dispatched writes the negative half. dispatched → received writes the
   positive half. Keeping them apart is what makes 'in_transit' mean something:
   during that window the units are counted at neither location, which is true. */
app.patch("/api/stock-transfers/:id", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const row = await db.prepare("SELECT * FROM stock_transfers WHERE id = ?").get(req.params.id);
  if (!row) throw Object.assign(new Error("transfer not found"), { statusCode: 404 });
  const before = rowTransfer(row);
  const want = String(b.status ?? "").trim().toLowerCase();
  if (!want) throw Object.assign(new Error("status is required"), { statusCode: 400 });
  if (!["dispatched", "received", "cancelled"].includes(want)) {
    throw Object.assign(new Error("status must be dispatched, received or cancelled"), { statusCode: 400 });
  }
  const actor = req.user?.name ?? req.actor ?? "system";
  const t = now();
  const lines = await transferLines(row.id);

  if (want === "dispatched") {
    if (row.status !== "draft") throw Object.assign(new Error(`cannot dispatch a transfer that is ${row.status}`), { statusCode: 409, code: "invalid_transition" });
    for (const line of lines) {
      /* The source debit goes through applyStockMovement with a location, so the
         guarded UPDATE keeps it from taking units another request already took.
         'transfer' is the reason 003 defined for exactly this. */
      await applyStockMovement({
        productId: line.productId, delta: -line.qty, reason: "transfer",
        refType: "stock_transfer", refId: row.id, locationId: row.from_location_id,
        note: `${row.ref} dispatched to location ${row.to_location_id}`, actor,
      });
    }
    await db.prepare("UPDATE stock_transfers SET status='dispatched', dispatched_at=?, updated_at=? WHERE id=?").run(t, t, row.id);
  } else if (want === "received") {
    if (row.status !== "dispatched") throw Object.assign(new Error(`cannot receive a transfer that is ${row.status}`), { statusCode: 409, code: "invalid_transition" });
    for (const line of lines) {
      await applyStockMovement({
        productId: line.productId, delta: line.qty, reason: "transfer",
        refType: "stock_transfer", refId: row.id, locationId: row.to_location_id,
        note: `${row.ref} received from location ${row.from_location_id}`, actor,
      });
    }
    await db.prepare("UPDATE stock_transfers SET status='received', received_at=?, updated_at=? WHERE id=?").run(t, t, row.id);
  } else {
    /* Cancelling a dispatched transfer is refused, and that refusal is the point:
       stock already left the source. The honest reversal is to receive it and
       move it back, which leaves two auditable trails instead of one erasure. */
    if (row.status !== "draft") throw Object.assign(new Error("only a draft transfer can be cancelled; stock already dispatched must be received and moved back"), { statusCode: 409, code: "invalid_transition" });
    await db.prepare("UPDATE stock_transfers SET status='cancelled', updated_at=? WHERE id=?").run(t, row.id);
  }

  const after = await transferFull(row.id);
  await writeAudit(req, { action: `stock_transfer.${want}`, entityType: "stock_transfer", entityId: row.id, before, after });
  return { transfer: after };
}));

/* ───────────────────────────── reservations ─────────────────────────────
   A hold is a claim, not a movement. It never touches stock_qty or the ledger;
   it only reduces what a caller may promise. Releasing one is therefore a status
   change, not a compensating entry — but it IS timestamped, so "when did we stop
   holding these" is answerable. */

const heldQty = async (productId) => Number((await db.prepare(
  "SELECT COALESCE(SUM(qty),0) AS q FROM stock_reservations WHERE product_id = ? AND status = 'held'"
).get(productId))?.q ?? 0);

app.get("/api/stock-reservations", wrap(async (req, res) => {
  const where = [];
  const params = [];
  if (req.query.product_id) { where.push("r.product_id = ?"); params.push(String(req.query.product_id)); }
  if (req.query.status) { where.push("r.status = ?"); params.push(String(req.query.status)); }
  if (req.query.ref_type) { where.push("r.ref_type = ?"); params.push(String(req.query.ref_type)); }
  if (req.query.ref_id) { where.push("r.ref_id = ?"); params.push(Number(req.query.ref_id)); }
  const rows = await db.prepare(
    `SELECT r.*, p.json AS product_json FROM stock_reservations r
       LEFT JOIN products p ON p.id = r.product_id
      ${where.length ? "WHERE " + where.join(" AND ") : ""}
      ORDER BY r.id DESC LIMIT 500`
  ).all(...params);
  const items = rows.map((r) => ({ ...rowReservation({ ...r, product_name: safeJson(r.product_json, {})?.name }), productName: safeJson(r.product_json, {})?.name ?? r.product_id }));
  res.json({ total: items.length, held: items.filter((i) => i.status === "held").length, items });
}));

/* Availability is the number a sales page needs and the number a reservation
   must not exceed: on hand minus everything currently held. */
app.get("/api/stock-reservations/availability", wrap(async (req, res) => {
  const productId = String(req.query.product_id ?? "").trim();
  if (!productId) throw Object.assign(new Error("product_id is required"), { statusCode: 400 });
  const p = await db.prepare("SELECT id, stock_qty, json FROM products WHERE id = ?").get(productId);
  if (!p) throw Object.assign(new Error("product not found: " + productId), { statusCode: 404 });
  const held = await heldQty(productId);
  const onHand = Number(p.stock_qty) || 0;
  res.json({
    productId, name: safeJson(p.json, {})?.name ?? productId,
    onHand, held, available: onHand - held,
  });
}));

app.post("/api/stock-reservations", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const productId = String(b.productId ?? b.product_id ?? "").trim();
  const qty = Number(b.qty);
  const refType = String(b.refType ?? b.ref_type ?? "").trim();
  if (!productId) throw Object.assign(new Error("productId is required"), { statusCode: 400 });
  if (!Number.isInteger(qty) || qty <= 0) throw Object.assign(new Error("qty must be a positive whole number"), { statusCode: 400 });
  /* 012's constraint requires a reference. Enforcing it here turns what would be
     a 500 from the CHECK into a 400 that says why: a hold nobody can trace back
     to an order is a hold nobody can release. */
  if (!refType) throw Object.assign(new Error("refType is required — a hold must say what it is holding stock for"), { statusCode: 400, code: "ref_required" });
  const p = await db.prepare("SELECT id, stock_qty FROM products WHERE id = ?").get(productId);
  if (!p) throw Object.assign(new Error("product not found: " + productId), { statusCode: 404 });

  const onHand = Number(p.stock_qty) || 0;
  const held = await heldQty(productId);
  const available = onHand - held;
  /* Over-reserving is the whole failure mode a reservation prevents, so it is a
     409 with the numbers attached: a caller that needs to show "only 3 left"
     should not have to make a second request to find out. Returned rather than
     thrown because `wrap` only forwards a message — a thrown error would reach
     the client as a bare sentence and a client that wants to render the
     numbers would have to parse prose. */
  if (qty > available) {
    return { status: 409, body: {
      error: `only ${Math.max(0, available)} available (${onHand} on hand, ${held} held)`,
      code: "insufficient_available", available: Math.max(0, available), onHand, held,
    } };
  }

  const t = now();
  const info = await db.prepare(
    `INSERT INTO stock_reservations(product_id, qty, ref_type, ref_id, status, expires_at, created_at)
     VALUES(?,?,?,?,'held',?,?)`
  ).run(productId, qty, refType, b.refId ?? b.ref_id ?? null, b.expiresAt ?? b.expires_at ?? null, t);
  const created = rowReservation({ ...(await db.prepare("SELECT * FROM stock_reservations WHERE id = ?").get(info.lastInsertRowid)), product_name: safeJson((await db.prepare("SELECT json FROM products WHERE id=?").get(productId))?.json, {})?.name });
  await writeAudit(req, { action: "stock_reservation.held", entityType: "stock_reservation", entityId: created.id, after: created });
  return { reservation: created, available: available - qty };
}));

app.patch("/api/stock-reservations/:id", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const row = await db.prepare("SELECT * FROM stock_reservations WHERE id = ?").get(req.params.id);
  if (!row) throw Object.assign(new Error("reservation not found"), { statusCode: 404 });
  const before = rowReservation(row);
  const want = String(b.status ?? "").trim().toLowerCase();
  if (!["released", "consumed"].includes(want)) {
    throw Object.assign(new Error("status must be released or consumed"), { statusCode: 400 });
  }
  if (row.status !== "held") throw Object.assign(new Error(`this reservation is already ${row.status}`), { statusCode: 409, code: "already_settled" });
  /* 'consumed' means the goods shipped against this hold. It does NOT write a
     ledger row: the sale that ships them does, and writing one here would
     double-count the same units. The reservation's job ends at "this promise was
     kept". */
  await db.prepare("UPDATE stock_reservations SET status = ?, released_at = ? WHERE id = ?").run(want, now(), row.id);
  const after = rowReservation(await db.prepare("SELECT * FROM stock_reservations WHERE id = ?").get(row.id));
  await writeAudit(req, { action: `stock_reservation.${want}`, entityType: "stock_reservation", entityId: row.id, before, after });
  return { reservation: after };
}));

/* ---------- bills of materials ---------- */

app.get("/api/boms", wrap(async (req, res) => {
  let rows = await db.prepare("SELECT * FROM boms ORDER BY id DESC").all();
  if (req.query.active !== undefined) {
    const want = ["1", "true", "yes"].includes(String(req.query.active).toLowerCase());
    rows = rows.filter((b) => !!b.active === want);
  }
  if (req.query.product_id) rows = rows.filter((b) => b.product_id === req.query.product_id);
  const items = [];
  for (const b of rows) {
    const lines = (await db.prepare("SELECT * FROM bom_lines WHERE bom_id=? ORDER BY line_no").all(b.id)).map(rowBomLine);
    items.push({ ...rowBom(b), lines, componentCount: lines.length });
  }
  res.json({ total: items.length, active: items.filter((b) => b.active).length, items });
}));

app.get("/api/boms/:id", wrap(async (req, res) => {
  const b = await bomById(req.params.id);
  if (!b) return res.status(404).json({ error: "not found" });
  const lines = (await db.prepare("SELECT * FROM bom_lines WHERE bom_id=? ORDER BY line_no").all(b.id)).map(rowBomLine);
  res.json({ ...rowBom(b), lines });
}));

/* Create a BOM. Creating a second active BOM for the same product SUPERSEDES
   the first rather than failing: versioning is the normal case in a workshop,
   and forcing the operator to deactivate by hand first is how two "active"
   recipes end up in use on the floor. */
app.post("/api/boms", wrapTxJson(async (req) => {
  const b = req.body ?? {};
  const productId = String(b.productId ?? b.product_id ?? "").trim();
  if (!productId) return { status: 400, body: { error: "productId is required" } };
  const product = await db.prepare("SELECT * FROM products WHERE id=?").get(productId);
  if (!product) return { status: 400, body: { error: `product '${productId}' does not exist` } };

  const rawLines = Array.isArray(b.lines) ? b.lines : [];
  if (!rawLines.length) return { status: 400, body: { error: "a BOM needs at least one component" } };

  const prepared = [];
  const seen = new Set();
  for (const [i, line] of rawLines.entries()) {
    const kind = String(line.itemKind ?? line.item_kind ?? "product").toLowerCase();
    if (!["product", "material"].includes(kind))
      return { status: 400, body: { error: `line ${i + 1}: itemKind must be product or material` } };
    const itemId = String(line.itemId ?? line.item_id ?? "").trim();
    if (!itemId) return { status: 400, body: { error: `line ${i + 1}: itemId is required` } };
    const exists = kind === "product"
      ? await db.prepare("SELECT 1 AS x FROM products WHERE id=?").get(itemId)
      : await db.prepare("SELECT 1 AS x FROM materials WHERE id=?").get(itemId);
    if (!exists) return { status: 400, body: { error: `line ${i + 1}: ${kind} '${itemId}' does not exist` } };
    const key = kind + ":" + itemId;
    if (seen.has(key)) return { status: 400, body: { error: `line ${i + 1}: ${kind} '${itemId}' appears twice` } };
    if (kind === "product" && itemId === productId)
      return { status: 400, body: { error: `line ${i + 1}: a product cannot contain itself` } };
    seen.add(key);
    const qty = Number(line.qtyPerOutput ?? line.qty_per_output ?? line.qty);
    if (!Number.isInteger(qty) || qty <= 0)
      return { status: 400, body: { error: `line ${i + 1}: qtyPerOutput must be a positive whole number` } };
    prepared.push({ lineNo: i + 1, kind, itemId, qty, unit: line.unit ?? null, note: line.note ?? null });
  }

  const t = now();
  const priorBom = await db.prepare("SELECT * FROM boms WHERE product_id=? AND active=1").get(productId);
  const version = priorBom ? Number(priorBom.version) + 1 : 1;
  if (priorBom) await db.prepare("UPDATE boms SET active=0, updated_at=? WHERE id=?").run(t, priorBom.id);

  const ref = await nextRef("boms", "BOM-", 100);
  const info = await db.prepare(
    "INSERT INTO boms(ref,product_id,name,version,output_qty,active,notes,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?)"
  ).run(ref, productId, b.name ?? `${safeJson(product.json, {})?.name ?? productId} — recipe`, version,
    Number(b.outputQty ?? b.output_qty ?? 1), 1, b.notes ?? null, t, t);
  const bomId = info.lastInsertRowid;
  for (const l of prepared) {
    await db.prepare("INSERT INTO bom_lines(bom_id,line_no,item_kind,item_id,qty_per_output,unit,note) VALUES(?,?,?,?,?,?,?)")
      .run(bomId, l.lineNo, l.kind, l.itemId, l.qty, l.unit, l.note);
  }
  const out = { ...rowBom(await bomById(bomId)),
    lines: (await db.prepare("SELECT * FROM bom_lines WHERE bom_id=? ORDER BY line_no").all(bomId)).map(rowBomLine) };
  await writeAudit(req, { action: "bom.created", entityType: "bom", entityId: bomId,
    after: { ref, productId, version, lines: prepared.length, superseded: priorBom?.id ?? null } });
  return { status: 201, body: out };
}));

/* ---------- job cards ---------- */

app.get("/api/job-cards", wrap(async (req, res) => {
  let rows = await db.prepare("SELECT * FROM job_cards ORDER BY id DESC").all();
  if (req.query.stage) {
    const wanted = String(req.query.stage).split(",").map((s) => s.trim().toLowerCase());
    rows = rows.filter((r) => wanted.includes(r.stage));
  }
  if (req.query.order_id) rows = rows.filter((r) => String(r.order_id) === String(req.query.order_id));
  if (req.query.assigned_to) rows = rows.filter((r) => r.assigned_to === req.query.assigned_to);
  const items = await Promise.all(rows.map(jobCardFull));
  res.json({
    total: items.length,
    open: items.filter((j) => ["queued", "in_progress", "qc"].includes(j.stage)).length,
    awaitingQc: items.filter((j) => j.stage === "qc").length,
    items,
  });
}));

app.get("/api/job-cards/:id", wrap(async (req, res) => {
  const r = await jobCardById(req.params.id);
  if (!r) return res.status(404).json({ error: "not found" });
  res.json(await jobCardFull(r));
}));

/* Raise a job card. Materials are snapshotted from the active BOM at creation
   time and copied into job_card_materials — NOT read live from the BOM later.
   A recipe that changes mid-build must not silently change what an
   already-issued job was supposed to consume. */
app.post("/api/job-cards", wrapTxJson(async (req, afterCommit) => {
  const b = req.body ?? {};
  const productId = String(b.productId ?? b.product_id ?? "").trim();
  if (!productId) return { status: 400, body: { error: "productId is required — a job must say what it builds" } };
  const product = await db.prepare("SELECT * FROM products WHERE id=?").get(productId);
  if (!product) return { status: 400, body: { error: `product '${productId}' does not exist` } };

  const qty = Number(b.qtyToBuild ?? b.qty_to_build ?? b.qty);
  if (!Number.isInteger(qty) || qty <= 0)
    return { status: 400, body: { error: "qtyToBuild must be a positive whole number" } };

  let orderId = b.orderId ?? b.order_id ?? null;
  if (orderId != null && orderId !== "") {
    const order = await db.prepare("SELECT * FROM orders WHERE id=?").get(orderId);
    if (!order) return { status: 400, body: { error: "order not found" } };
    orderId = order.id;
  } else orderId = null;

  const bom = b.bomId ?? b.bom_id
    ? await bomById(b.bomId ?? b.bom_id)
    : await db.prepare("SELECT * FROM boms WHERE product_id=? AND active=1").get(productId);
  if (bom && bom.product_id !== productId)
    return { status: 400, body: { error: `${bom.ref} is a recipe for ${bom.product_id}, not ${productId}` } };

  const t = now();
  const ref = await nextRef("job_cards", "JC-", 1000);
  const info = await db.prepare(
    `INSERT INTO job_cards(ref,bom_id,product_id,order_id,qty_to_build,qty_built,stage,assigned_to,due_at,notes,created_by,created_at,updated_at)
     VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)`
  ).run(ref, bom?.id ?? null, productId, orderId, qty, 0, "queued",
    b.assignedTo ?? b.assigned_to ?? null, b.dueAt ?? b.due_at ?? null, b.notes ?? null,
    req.actor ?? req.user?.name ?? "system", t, t);
  const jcId = info.lastInsertRowid;

  /* required = qtyPerOutput × number of BOM runs, rounded UP: coming up one
     bracket short stops a build, and the shelf holds whole units. */
  if (bom) {
    const lines = await db.prepare("SELECT * FROM bom_lines WHERE bom_id=? ORDER BY line_no").all(bom.id);
    const runs = Math.ceil(qty / Number(bom.output_qty || 1));
    let lineNo = 1;
    for (const l of lines) {
      await db.prepare("INSERT INTO job_card_materials(job_card_id,line_no,item_kind,item_id,qty_required,qty_issued,created_at) VALUES(?,?,?,?,?,?,?)")
        .run(jcId, lineNo++, l.item_kind, l.item_id, Number(l.qty_per_output) * runs, 0, t);
    }
  }

  const out = await jobCardFull(await jobCardById(jcId));
  /* After the commit, not before: emitting inside the transaction can announce a
     job card that then rolls back. */
  const who = req.actor;
  const bomRef = bom ? bom.ref : "no BOM";
  afterCommit.push(() => emit("job-cards", { title: `${ref} raised for ${qty} × ${productId}`, who, context: bomRef }));
  await writeAudit(req, { action: "job_card.created", entityType: "job_card", entityId: jcId,
    after: { ref, productId, qty, bomId: bom?.id ?? null, orderId, materials: out.materials.length } });
  return { status: 201, body: out };
}));

/* Issue materials into a job. This is the consumption path.

   Each row's issue posts ONE ledger movement keyed (ref_type='job_card_material',
   ref_id=<row id>), so a retried request is refused by the unique index rather
   than double-decrementing. Issuing the whole card is done by passing no lines. */
app.post("/api/job-cards/:id/materials/issue", wrapTxJson(async (req, afterCommit) => {
  const jc = await jobCardById(req.params.id);
  if (!jc) return { status: 404, body: { error: "not found" } };
  if (jc.stage === "cancelled") return { status: 409, body: { error: "this job is cancelled" } };
  if (jc.stage === "done") return { status: 409, body: { error: "this job is already complete" } };

  const rows = await jobCardMaterials(jc.id);
  if (!rows.length) return { status: 409, body: { error: "this job has no materials to issue (no BOM)" } };

  const wanted = Array.isArray(req.body?.lines) && req.body.lines.length
    ? req.body.lines
    : rows.filter((r) => r.qtyOutstanding > 0).map((r) => ({ lineId: r.id, qty: r.qtyOutstanding }));

  const prepared = [];
  const seen = new Set();
  for (const [i, line] of wanted.entries()) {
    const rowId = line.lineId ?? line.line_id ?? line.id;
    const row = rows.find((r) => String(r.id) === String(rowId));
    if (!row) return { status: 400, body: { error: `line ${i + 1}: not a material on ${jc.ref}` } };
    if (seen.has(row.id)) return { status: 400, body: { error: `line ${i + 1}: ${row.itemId} appears twice` } };
    seen.add(row.id);
    const qty = Number(line.qty ?? row.qtyOutstanding);
    if (!Number.isInteger(qty) || qty <= 0)
      return { status: 400, body: { error: `line ${i + 1}: qty must be a positive whole number` } };
    if (row.qtyIssued + qty > row.qtyRequired)
      return { status: 409, body: { error: `line ${i + 1}: issuing ${qty} would exceed the ${row.qtyRequired} required (${row.qtyIssued} already issued)`, required: row.qtyRequired, issued: row.qtyIssued } };
    // A product may already have a movement for this row; the index is the backstop.
    prepared.push({ row, qty });
  }
  if (!prepared.length) return { status: 409, body: { error: "nothing outstanding to issue" } };

  const t = now();
  const posted = [];
  const skipped = [];
  for (const { row, qty } of prepared) {
    if (row.itemKind === "product") {
      /* Negative delta, through the one legal helper: it refuses to take stock
         that is not there, which is the correct behaviour — a build cannot
         consume components the store does not have. */
      const mv = await applyStockMovement({
        productId: row.itemId, delta: -qty, reason: "production",
        refType: "job_card_material", refId: row.id,
        /* Keyed on the total this line reaches, not on the delta. A replay of the
           same request computes the same total and collides on the unique index;
           an honest top-up computes a new one and is allowed. */
        idemKey: `jc${jc.id}:line${row.id}:to${row.qtyIssued + qty}`,
        note: `${jc.ref} consumed ${qty} (${row.qtyIssued + qty}/${row.qtyRequired})`,
        actor: req.actor ?? req.user?.name ?? "system",
      });
      posted.push({ itemId: row.itemId, qty, movementId: mv.id });
    } else {
      /* A finish code has no quantity on hand. Recording that it was used and
         moving no stock is the honest answer; inventing a quantity would make
         the ledger claim inventory that does not exist. */
      skipped.push({ itemId: row.itemId, qty, reason: "material has no stock dimension" });
    }
    await db.prepare("UPDATE job_card_materials SET qty_issued=qty_issued+? WHERE id=?").run(qty, row.id);
  }

  /* Starting to consume is starting the job. Reflecting that here means the
     floor does not have to remember a second click to make the board truthful. */
  if (jc.stage === "queued") await db.prepare("UPDATE job_cards SET stage='in_progress', started_at=?, updated_at=? WHERE id=?").run(t, t, jc.id);
  else await db.prepare("UPDATE job_cards SET updated_at=? WHERE id=?").run(t, jc.id);

  const out = await jobCardFull(await jobCardById(jc.id));
  afterCommit.push(() => emit("job-cards", { title: `${jc.ref}: issued ${posted.length} component(s)`, who: req.actor }));
  await writeAudit(req, { action: "job_card.materials_issued", entityType: "job_card", entityId: jc.id,
    after: { posted, skipped } });
  return { body: out };
}));

/* Move a job card. `done` records the finished quantity against stock, and is
   unreachable without QC. */
app.patch("/api/job-cards/:id", wrapTxJson(async (req, afterCommit) => {
  const jc = await jobCardById(req.params.id);
  if (!jc) return { status: 404, body: { error: "not found" } };
  const before = rowJobCard(jc);
  const b = req.body ?? {};
  const t = now();

  if (b.stage !== undefined) {
    const next = String(b.stage).toLowerCase();
    if (!JC_STAGES.has(next)) return { status: 400, body: { error: "stage must be " + [...JC_STAGES].join("|") } };
    if (next === jc.stage) return { status: 409, body: { error: `job is already ${next}` } };
    const allowed = JC_NEXT[jc.stage] ?? [];
    if (!allowed.includes(next))
      return { status: 409, body: { error: `cannot move a ${jc.stage} job to ${next}`, allowed } };

    if (next === "in_progress") {
      await db.prepare("UPDATE job_cards SET stage=?, started_at=COALESCE(started_at,?), updated_at=? WHERE id=?").run(next, t, t, jc.id);
    } else if (next === "qc") {
      /* Record how much was actually built before it can be inspected. Default
         to everything asked for; a short build is recorded honestly. */
      const built = b.qtyBuilt ?? b.qty_built ?? jc.qty_to_build;
      const n = Number(built);
      if (!Number.isInteger(n) || n <= 0 || n > jc.qty_to_build)
        return { status: 400, body: { error: `qtyBuilt must be a whole number between 1 and ${jc.qty_to_build}` } };
      await db.prepare("UPDATE job_cards SET stage=?, qty_built=?, updated_at=? WHERE id=?").run(next, n, t, jc.id);
    } else if (next === "done") {
      /* THE QC GATE. The schema refuses this too (jc_done_requires_qc); checking
         here turns a 500 into a sentence that says what to do. */
      if (!jc.qc_passed_at)
        return { status: 409, body: { error: "a job cannot be completed without passing QC — record the inspection first", need: "qc_passed_at" } };
      const built = Number(jc.qty_built) || 0;
      if (built <= 0)
        return { status: 409, body: { error: "nothing was recorded as built, so there is no output to add to stock" } };

      /* THE CONSUMPTION GATE. Completing a build without issuing its components
         would credit the shelf with goods made from nothing — the finished item
         appears, the parts are still on the rack. That is the same class of
         error as paying a bill for goods that never arrived, so it takes the
         same shape: block by default, allow an explicit override, and audit the
         override. A job whose parts were issued on an earlier card is the real
         case for the override. */
      const outstanding = (await jobCardMaterials(jc.id)).filter((m) => m.qtyOutstanding > 0);
      const override = b.allowShortIssue === true || b.allow_short_issue === true;
      if (outstanding.length && !override) {
        return { status: 409, body: {
          error: "materials have not been fully issued, so completing this job would create stock from nothing",
          outstanding: outstanding.map((m) => ({ itemId: m.itemId, outstanding: m.qtyOutstanding, required: m.qtyRequired, issued: m.qtyIssued })),
          override: "pass allowShortIssue:true to complete anyway; the shortfall is recorded in the audit trail",
        } };
      }
      if (outstanding.length) {
        await writeAudit(req, { action: "job_card.short_issue_override", entityType: "job_card", entityId: jc.id,
          after: { outstanding, qtyBuilt: built, actor: req.actor } });
      }
      /* Output: the finished item is real inventory. One movement, keyed to the
         job card itself, so a retry cannot double-credit the shelf. */
      await applyStockMovement({
        productId: jc.product_id, delta: built, reason: "production",
        refType: "job_card_output", refId: jc.id,
        note: `${jc.ref} output ${built}`, actor: req.actor ?? req.user?.name ?? "system",
      });
      await db.prepare("UPDATE job_cards SET stage=?, completed_at=?, updated_at=? WHERE id=?").run(next, t, t, jc.id);
    } else {
      await db.prepare("UPDATE job_cards SET stage=?, updated_at=? WHERE id=?").run(next, t, jc.id);
    }
  }

  /* The QC verdict. Passing records who and when; failing sends the job back
     to in_progress, because rework is work, not a terminal state. */
  if (b.qcPassed !== undefined || b.qc_passed !== undefined) {
    const passed = b.qcPassed === true || b.qc_passed === true;
    if (passed) {
      await db.prepare("UPDATE job_cards SET qc_passed_at=?, qc_by=?, qc_note=?, updated_at=? WHERE id=?")
        .run(t, req.actor ?? req.user?.name ?? "system", b.qcNote ?? b.qc_note ?? null, t, jc.id);
    } else {
      await db.prepare("UPDATE job_cards SET qc_passed_at=NULL, qc_by=?, qc_note=?, stage='in_progress', updated_at=? WHERE id=?")
        .run(req.actor ?? req.user?.name ?? "system", b.qcNote ?? b.qc_note ?? null, t, jc.id);
    }
  }

  if (b.assignedTo !== undefined || b.assigned_to !== undefined)
    await db.prepare("UPDATE job_cards SET assigned_to=?, updated_at=? WHERE id=?").run(b.assignedTo ?? b.assigned_to, t, jc.id);
  if (b.dueAt !== undefined || b.due_at !== undefined)
    await db.prepare("UPDATE job_cards SET due_at=?, updated_at=? WHERE id=?").run(b.dueAt ?? b.due_at, t, jc.id);
  if (b.notes !== undefined) await db.prepare("UPDATE job_cards SET notes=?, updated_at=? WHERE id=?").run(b.notes, t, jc.id);

  const out = await jobCardFull(await jobCardById(jc.id));
  if (out.stage !== before.stage) {
    await writeAudit(req, { action: "job_card." + (out.stage === "done" ? "completed" : "stage_changed"),
      entityType: "job_card", entityId: out.id,
      before: { stage: before.stage }, after: { stage: out.stage, qtyBuilt: out.qtyBuilt } });
    afterCommit.push(() => emit("job-cards", { title: `${out.ref}: ${before.stage} → ${out.stage}`, who: req.actor }));
  }
  return { body: out };
}));

app.get("/api/audit/migrations", wrap(async (_req, res) => {
  const state = await migrationAlignment();
  /* 503 when the deployment and the database disagree: a monitor polling this
     should page, not shrug. Owner-gated like the rest of /api/audit. */
  res.status(state.status === "misaligned" ? 503 : 200).json(state);
}));


/* ═════════════════════════════ procurement ═════════════════════════════
   Suppliers, purchase orders and goods receiving — Phase B increment 1.

   The one rule this block exists to enforce: a goods receipt for a PRODUCT
   moves stock exactly once, and a receipt for a MATERIAL never moves it at
   all. Both directions are asserted in scripts/test-procurement.mjs, because a
   module that only proves the happy direction would let a finish code inflate
   inventory forever without anyone noticing.

   Stock is never written directly here. Every movement goes through
   applyStockMovement(), which is the only legal write path and which refuses a
   non-integer delta — the reason qty_received is INTEGER in the schema. */

const PO_STATUSES = new Set(["draft", "approved", "sent", "partially_received", "received", "cancelled"]);
/* Statuses that may still be received against. A receipt against a draft would
   be goods nobody ordered; against a cancelled order, goods somebody explicitly
   withdrew. */
const PO_RECEIVABLE = new Set(["approved", "sent", "partially_received"]);

const rowSupplier = (r) => ({
  id: r.id, code: r.code, name: r.name, contactName: r.contact_name, phone: r.phone,
  email: r.email, address: r.address, taxRef: r.tax_ref, paymentTerms: r.payment_terms,
  currency: r.currency, active: !!r.active, notes: r.notes,
  createdAt: r.created_at, updatedAt: r.updated_at,
});
const rowPoLine = (r) => ({
  id: r.id, purchaseOrderId: r.purchase_order_id, lineNo: r.line_no, itemKind: r.item_kind,
  itemId: r.item_id, description: r.description, qtyOrdered: r.qty_ordered,
  unit: r.unit, unitCost: Number(r.unit_cost) || 0,
  lineTotal: (Number(r.qty_ordered) || 0) * (Number(r.unit_cost) || 0),
});
const rowPurchaseOrder = (r) => ({
  id: r.id, ref: r.ref, supplierId: r.supplier_id, status: r.status, currency: r.currency,
  expectedAt: r.expected_at, approvedBy: r.approved_by, approvedAt: r.approved_at,
  sentAt: r.sent_at, closedAt: r.closed_at, note: r.note, createdBy: r.created_by,
  createdAt: r.created_at, updatedAt: r.updated_at,
});
const rowGoodsReceipt = (r) => ({
  id: r.id, ref: r.ref, purchaseOrderId: r.purchase_order_id, receivedAt: r.received_at,
  receivedBy: r.received_by, note: r.note, createdAt: r.created_at,
});

/* How much of each ordered line has already arrived. One grouped query rather
   than a per-line round trip — a 20-line order would otherwise be 20 queries
   every time the PO list is opened. */
const receivedByLine = async (purchaseOrderId) => {
  const rows = (await db.prepare(`
    SELECT grl.purchase_order_line_id AS line_id, COALESCE(SUM(grl.qty_received), 0) AS qty
      FROM goods_receipt_lines grl
      JOIN goods_receipts gr ON gr.id = grl.goods_receipt_id
     WHERE gr.purchase_order_id = ?
     GROUP BY grl.purchase_order_line_id`).all(purchaseOrderId));
  return new Map(rows.map((r) => [Number(r.line_id), Number(r.qty)]));
};

/* The PO with its lines and the received/open quantities folded in. This is
   what makes 'partially_received' legible rather than a status word. */
const purchaseOrderFull = async (r) => {
  const lines = (await db.prepare("SELECT * FROM purchase_order_lines WHERE purchase_order_id=? ORDER BY line_no").all(r.id)).map(rowPoLine);
  const received = await receivedByLine(r.id);
  const withProgress = lines.map((l) => {
    const got = received.get(l.id) ?? 0;
    return { ...l, qtyReceived: got, qtyOpen: Math.max(0, l.qtyOrdered - got) };
  });
  const ordered = withProgress.reduce((n, l) => n + l.qtyOrdered, 0);
  const arrived = withProgress.reduce((n, l) => n + l.qtyReceived, 0);
  return {
    ...rowPurchaseOrder(r),
    lines: withProgress,
    qtyOrdered: ordered,
    qtyReceived: arrived,
    qtyOpen: Math.max(0, ordered - arrived),
    value: withProgress.reduce((n, l) => n + l.lineTotal, 0),
  };
};

const supplierById = async (id) => (await db.prepare("SELECT * FROM suppliers WHERE id=?").get(id));
const purchaseOrderById = async (id) => (await db.prepare("SELECT * FROM purchase_orders WHERE id=? OR ref=?").get(id, id));



/* ═══════════════════════════════ CRM depth (014) ═══════════════════════════════
   Companies, contacts, meetings, attachments and the activity timeline.

   Before this block a person was a string: leads.contact and clients.company
   were TEXT, so the same human arriving twice from two channels was two rows and
   "everything with this company" was a LIKE across a free-text column. The merge
   endpoint on clients exists to clean up after that model — it is a symptom, not
   a feature.

   The timeline is a table rather than a view over audit_logs/tasks/meetings/
   payments because a UNION silently loses any event whose table nobody added to
   it, and the failure looks like a complete timeline rather than an error. */

/* Fold a name for matching. Lowercase; punctuation, brackets and hyphens become
   spaces; the legal suffixes that carry no identifying information are dropped.
   "Clifton Villa (Pvt) Ltd." and "clifton villa private limited" must fold to
   the same key or the duplicate check is decoration.

   Deliberately conservative: "pvt", "ltd", "llc" and friends go, and nothing
   else does. The words that distinguish one company from another stay, because
   over-folding merges two real customers and nobody ever notices — a duplicate
   is visible, a wrong merge is not. */
const foldName = (v) => String(v ?? "")
  .toLowerCase()
  .replace(/[.,()[\]{}#'"\-–—&@/\\|]/g, " ")
  .replace(/\b(pvt|private|ltd|limited|llc|inc|co|company|corp|corporation)\b/g, " ")
  .replace(/\s+/g, " ")
  .trim();
const foldEmail = (v) => String(v ?? "").trim().toLowerCase();
const foldPhone = (v) => String(v ?? "").replace(/[^\d]/g, "").replace(/^92/, "").replace(/^0/, "");

const rowCompany = (r) => ({
  id: r.id, name: r.name, industry: r.industry, website: r.website, phone: r.phone,
  email: r.email, taxRef: r.tax_ref, city: r.city, address: r.address,
  accountOwner: r.account_owner, notes: r.notes, createdAt: r.created_at, updatedAt: r.updated_at,
  contactCount: Number(r.contact_count ?? 0), clientCount: Number(r.client_count ?? 0),
});
const rowContact = (r) => ({
  id: r.id, companyId: r.company_id, companyName: r.company_name ?? null,
  clientId: r.client_id, name: r.name, email: r.email, phone: r.phone, role: r.role,
  isPrimary: !!r.is_primary, notes: r.notes, createdAt: r.created_at, updatedAt: r.updated_at,
});
const rowMeeting = (r) => ({
  id: r.id, ref: r.ref, title: r.title, companyId: r.company_id, companyName: r.company_name ?? null,
  clientId: r.client_id, leadId: r.lead_id, startsAt: r.starts_at, minutes: Number(r.minutes),
  location: r.location, channel: r.channel, owner: r.owner, status: r.status,
  attendees: r.attendees, agenda: r.agenda, outcome: r.outcome,
  createdAt: r.created_at, updatedAt: r.updated_at,
});
const rowAttachment = (r) => ({
  id: r.id, entityType: r.entity_type, entityId: r.entity_id, filename: r.filename,
  mimeType: r.mime_type, sizeBytes: r.size_bytes === null ? null : Number(r.size_bytes),
  path: r.path, checksum: r.checksum, uploadedBy: r.uploaded_by, notes: r.notes, createdAt: r.created_at,
});
const rowActivity = (r) => ({
  id: r.id, entityType: r.entity_type, entityId: r.entity_id, kind: r.kind,
  summary: r.summary, detail: r.detail, actor: r.actor, occurredAt: r.occurred_at, createdAt: r.created_at,
});

/* One writer for the timeline, called from the route that performed the change
   inside its own transaction — the same shape as writeAudit, so an activity
   exists only if the thing it describes committed. Never throws: failing to
   record a note must not fail the route that already wrote the row. */
const ACTIVITY_KINDS = new Set(["call", "meeting", "note", "email", "status_change", "document", "payment", "system"]);
async function writeActivity({ entityType, entityId, kind, summary, detail = null, actor = "system", occurredAt = null }) {
  if (!ACTIVITY_KINDS.has(kind)) {
    auditWriteFailures += 1;
    console.error(`[activity] rejected unknown kind ${JSON.stringify(kind)}`);
    return;
  }
  try {
    await db.prepare(
      "INSERT INTO activities(entity_type, entity_id, kind, summary, detail, actor, occurred_at, created_at) VALUES(?,?,?,?,?,?,?,?)"
    ).run(entityType, Number(entityId), kind, String(summary).slice(0, 400),
      detail ? String(detail).slice(0, 4000) : null, String(actor).slice(0, 80),
      occurredAt ?? now(), now());
  } catch (e) {
    auditWriteFailures += 1;
    console.error("[activity] failed to record:", e?.message ?? e);
  }
}

/* ──────────────────────────── companies ──────────────────────────── */

app.get("/api/companies", wrap(async (req, res) => {
  const where = [];
  const params = [];
  if (req.query.q) {
    const like = "%" + String(req.query.q) + "%";
    where.push("(c.name LIKE ? OR c.name_norm LIKE ? OR c.email LIKE ? OR c.city LIKE ?)");
    params.push(like, "%" + foldName(req.query.q) + "%", like, like);
  }
  if (req.query.owner) { where.push("c.account_owner = ?"); params.push(String(req.query.owner)); }
  const rows = await db.prepare(
    `SELECT c.*,
            (SELECT COUNT(*) FROM contacts x WHERE x.company_id = c.id) AS contact_count,
            (SELECT COUNT(*) FROM clients k WHERE k.company_id = c.id) AS client_count
       FROM companies c ${where.length ? "WHERE " + where.join(" AND ") : ""}
      ORDER BY c.name LIMIT 300`
  ).all(...params);
  res.json({ total: rows.length, items: rows.map(rowCompany) });
}));

app.get("/api/companies/:id", wrap(async (req, res) => {
  const row = await db.prepare("SELECT * FROM companies WHERE id = ?").get(req.params.id);
  if (!row) throw Object.assign(new Error("company not found"), { statusCode: 404 });
  const contacts = (await db.prepare("SELECT * FROM contacts WHERE company_id = ? ORDER BY is_primary DESC, name").all(row.id)).map(rowContact);
  /* Everything this company touches, in one call. The whole point of a real
     company record is that "show me everything" is a query, not a search. */
  const clients = await db.prepare("SELECT id, name, email, phone FROM clients WHERE company_id = ? ORDER BY name").all(row.id);
  const leads = await db.prepare("SELECT id, ref, name, status FROM leads WHERE company_id = ? ORDER BY id DESC LIMIT 50").all(row.id);
  const meetings = (await db.prepare("SELECT * FROM meetings WHERE company_id = ? ORDER BY starts_at DESC LIMIT 50").all(row.id)).map(rowMeeting);
  const timeline = (await db.prepare("SELECT * FROM activities WHERE entity_type = 'company' AND entity_id = ? ORDER BY occurred_at DESC, id DESC LIMIT 100").all(row.id)).map(rowActivity);
  const attachments = (await db.prepare("SELECT * FROM attachments WHERE entity_type = 'company' AND entity_id = ? ORDER BY id DESC").all(row.id)).map(rowAttachment);
  res.json({
    company: rowCompany({ ...row, contact_count: contacts.length, client_count: clients.length }),
    contacts, clients, leads, meetings, timeline, attachments,
  });
}));

app.post("/api/companies", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const name = String(b.name ?? "").trim();
  if (!name) throw Object.assign(new Error("name is required"), { statusCode: 400 });
  const norm = foldName(name);
  if (!norm) throw Object.assign(new Error("name must contain at least one letter or digit"), { statusCode: 400 });
  const dupe = await db.prepare("SELECT id, name FROM companies WHERE name_norm = ?").get(norm);
  if (dupe) {
    /* 409 that names the existing record, so the caller can open it instead of
       being told "no" with nowhere to go — which is what produces the third
       spelling of the same customer. */
    throw Object.assign(new Error(`"${dupe.name}" already exists as company #${dupe.id}`),
      { statusCode: 409, code: "company_exists", companyId: dupe.id });
  }
  const t = now();
  const info = await db.prepare(
    `INSERT INTO companies(name, name_norm, industry, website, phone, email, tax_ref, city, address, account_owner, notes, created_at, updated_at)
     VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)`
  ).run(name, norm, b.industry ?? null, b.website ?? null, b.phone ?? null, b.email ?? null,
    b.taxRef ?? null, b.city ?? null, b.address ?? null, b.accountOwner ?? null, b.notes ?? null, t, t);
  const created = rowCompany(await db.prepare("SELECT * FROM companies WHERE id = ?").get(info.lastInsertRowid));
  await writeAudit(req, { action: "company.created", entityType: "company", entityId: created.id, after: created });
  await writeActivity({ entityType: "company", entityId: created.id, kind: "system",
    summary: `Company created: ${created.name}`, actor: req.user?.name ?? "system" });
  return { status: 201, body: { company: created } };
}));

app.patch("/api/companies/:id", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const row = await db.prepare("SELECT * FROM companies WHERE id = ?").get(req.params.id);
  if (!row) throw Object.assign(new Error("company not found"), { statusCode: 404 });
  const before = rowCompany(row);
  const sets = [];
  const params = [];
  for (const [k, col] of Object.entries({ industry: "industry", website: "website", phone: "phone",
    email: "email", taxRef: "tax_ref", city: "city", address: "address", accountOwner: "account_owner", notes: "notes" })) {
    if (b[k] === undefined) continue;
    sets.push(`${col} = ?`); params.push(b[k] === null ? null : String(b[k]));
  }
  if (b.name !== undefined) {
    const name = String(b.name).trim();
    if (!name) throw Object.assign(new Error("name cannot be empty"), { statusCode: 400 });
    const norm = foldName(name);
    const clash = await db.prepare("SELECT id, name FROM companies WHERE name_norm = ? AND id <> ?").get(norm, row.id);
    if (clash) throw Object.assign(new Error(`"${clash.name}" already exists as company #${clash.id}`),
      { statusCode: 409, code: "company_exists", companyId: clash.id });
    sets.push("name = ?", "name_norm = ?"); params.push(name, norm);
  }
  if (!sets.length) throw Object.assign(new Error("nothing to update"), { statusCode: 400 });
  sets.push("updated_at = ?"); params.push(now(), row.id);
  await db.prepare(`UPDATE companies SET ${sets.join(", ")} WHERE id = ?`).run(...params);
  const after = rowCompany(await db.prepare("SELECT * FROM companies WHERE id = ?").get(row.id));
  await writeAudit(req, { action: "company.updated", entityType: "company", entityId: row.id, before, after });
  if (before.name !== after.name) {
    await writeActivity({ entityType: "company", entityId: row.id, kind: "status_change",
      summary: `Renamed from "${before.name}" to "${after.name}"`, actor: req.user?.name ?? "system" });
  }
  return { company: after };
}));

/* ──────────────────────────── contacts ──────────────────────────── */

app.get("/api/contacts", wrap(async (req, res) => {
  const where = [];
  const params = [];
  if (req.query.company_id) { where.push("c.company_id = ?"); params.push(Number(req.query.company_id)); }
  if (req.query.client_id) { where.push("c.client_id = ?"); params.push(Number(req.query.client_id)); }
  if (req.query.q) {
    const like = "%" + String(req.query.q) + "%";
    where.push("(c.name LIKE ? OR c.email LIKE ? OR c.phone LIKE ?)");
    params.push(like, like, like);
  }
  const rows = await db.prepare(
    `SELECT c.*, co.name AS company_name FROM contacts c
       LEFT JOIN companies co ON co.id = c.company_id
      ${where.length ? "WHERE " + where.join(" AND ") : ""}
      ORDER BY c.is_primary DESC, c.name LIMIT 500`
  ).all(...params);
  res.json({ total: rows.length, items: rows.map(rowContact) });
}));

app.post("/api/contacts", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const name = String(b.name ?? "").trim();
  if (!name) throw Object.assign(new Error("name is required"), { statusCode: 400 });
  let companyId = b.companyId ?? null;
  if (companyId !== null) {
    const co = await db.prepare("SELECT id FROM companies WHERE id = ?").get(Number(companyId));
    if (!co) throw Object.assign(new Error("company not found: " + companyId), { statusCode: 404 });
    companyId = co.id;
  }
  let clientId = b.clientId ?? null;
  if (clientId !== null) {
    const cl = await db.prepare("SELECT id FROM clients WHERE id = ?").get(Number(clientId));
    if (!cl) throw Object.assign(new Error("client not found: " + clientId), { statusCode: 404 });
    clientId = cl.id;
  }
  const email = b.email ? String(b.email).trim() : null;
  const emailNorm = email ? foldEmail(email) : null;
  if (emailNorm) {
    const dupe = await db.prepare("SELECT id, name FROM contacts WHERE email_norm = ?").get(emailNorm);
    if (dupe) {
      throw Object.assign(new Error(`${email} already belongs to contact #${dupe.id} (${dupe.name})`),
        { statusCode: 409, code: "contact_exists", contactId: dupe.id });
    }
  }
  const t = now();
  const info = await db.prepare(
    `INSERT INTO contacts(company_id, client_id, name, email, email_norm, phone, phone_norm, role, is_primary, notes, created_at, updated_at)
     VALUES(?,?,?,?,?,?,?,?,?,?,?,?)`
  ).run(companyId, clientId, name, email, emailNorm, b.phone ?? null,
    b.phone ? foldPhone(b.phone) : null, b.role ?? null, b.isPrimary === true ? 1 : 0, b.notes ?? null, t, t);
  const created = rowContact(await db.prepare(
    "SELECT c.*, co.name AS company_name FROM contacts c LEFT JOIN companies co ON co.id = c.company_id WHERE c.id = ?"
  ).get(info.lastInsertRowid));
  await writeAudit(req, { action: "contact.created", entityType: "contact", entityId: created.id, after: created });
  if (companyId) {
    await writeActivity({ entityType: "company", entityId: companyId, kind: "system",
      summary: `Contact added: ${created.name}${created.role ? ` (${created.role})` : ""}`,
      actor: req.user?.name ?? "system" });
  }
  return { status: 201, body: { contact: created } };
}));

app.patch("/api/contacts/:id", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const row = await db.prepare("SELECT * FROM contacts WHERE id = ?").get(req.params.id);
  if (!row) throw Object.assign(new Error("contact not found"), { statusCode: 404 });
  const before = rowContact(row);
  const sets = [];
  const params = [];
  for (const [k, col] of Object.entries({ name: "name", role: "role", notes: "notes", phone: "phone" })) {
    if (b[k] === undefined) continue;
    sets.push(`${col} = ?`); params.push(b[k] === null ? null : String(b[k]));
  }
  if (b.email !== undefined) {
    const email = b.email ? String(b.email).trim() : null;
    const norm = email ? foldEmail(email) : null;
    if (norm) {
      const clash = await db.prepare("SELECT id, name FROM contacts WHERE email_norm = ? AND id <> ?").get(norm, row.id);
      if (clash) throw Object.assign(new Error(`${email} already belongs to contact #${clash.id} (${clash.name})`),
        { statusCode: 409, code: "contact_exists", contactId: clash.id });
    }
    sets.push("email = ?", "email_norm = ?"); params.push(email, norm);
  }
  if (b.isPrimary !== undefined) {
    sets.push("is_primary = ?"); params.push(b.isPrimary === true ? 1 : 0);
  }
  if (b.companyId !== undefined) {
    if (b.companyId === null) sets.push("company_id = NULL");
    else {
      const co = await db.prepare("SELECT id FROM companies WHERE id = ?").get(Number(b.companyId));
      if (!co) throw Object.assign(new Error("company not found: " + b.companyId), { statusCode: 404 });
      sets.push("company_id = ?"); params.push(co.id);
    }
  }
  if (!sets.length) throw Object.assign(new Error("nothing to update"), { statusCode: 400 });
  sets.push("updated_at = ?"); params.push(now(), row.id);
  await db.prepare(`UPDATE contacts SET ${sets.join(", ")} WHERE id = ?`).run(...params);
  const after = rowContact(await db.prepare(
    "SELECT c.*, co.name AS company_name FROM contacts c LEFT JOIN companies co ON co.id = c.company_id WHERE c.id = ?"
  ).get(row.id));
  await writeAudit(req, { action: "contact.updated", entityType: "contact", entityId: row.id, before, after });
  return { contact: after };
}));

/* ──────────────────────────── meetings ──────────────────────────── */

const MEETING_STATUSES = new Set(["scheduled", "held", "cancelled", "no_show"]);

app.get("/api/meetings", wrap(async (req, res) => {
  const where = [];
  const params = [];
  if (req.query.status) { where.push("m.status = ?"); params.push(String(req.query.status)); }
  if (req.query.company_id) { where.push("m.company_id = ?"); params.push(Number(req.query.company_id)); }
  if (req.query.client_id) { where.push("m.client_id = ?"); params.push(Number(req.query.client_id)); }
  if (req.query.from) { where.push("m.starts_at >= ?"); params.push(String(req.query.from)); }
  if (req.query.to) { where.push("m.starts_at <= ?"); params.push(String(req.query.to) + "T23:59:59.999Z"); }
  const rows = await db.prepare(
    `SELECT m.*, co.name AS company_name FROM meetings m
       LEFT JOIN companies co ON co.id = m.company_id
      ${where.length ? "WHERE " + where.join(" AND ") : ""}
      ORDER BY m.starts_at DESC LIMIT 300`
  ).all(...params);
  const items = rows.map(rowMeeting);
  const nowIso = now();
  res.json({
    total: items.length,
    upcoming: items.filter((m) => m.status === "scheduled" && m.startsAt >= nowIso).length,
    items,
  });
}));

app.post("/api/meetings", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const title = String(b.title ?? "").trim();
  const startsAt = String(b.startsAt ?? "").trim();
  if (!title) throw Object.assign(new Error("title is required"), { statusCode: 400 });
  if (!startsAt) throw Object.assign(new Error("startsAt is required"), { statusCode: 400 });
  const parsed = Date.parse(startsAt);
  if (!Number.isFinite(parsed)) throw Object.assign(new Error("startsAt must be a valid date and time"), { statusCode: 400 });
  const channel = String(b.channel ?? "in-person");
  if (!["in-person", "phone", "video"].includes(channel)) {
    throw Object.assign(new Error("channel must be in-person, phone or video"), { statusCode: 400 });
  }
  const minutes = Number(b.minutes ?? 30);
  if (!Number.isInteger(minutes) || minutes <= 0) {
    throw Object.assign(new Error("minutes must be a positive whole number"), { statusCode: 400 });
  }
  const ref = await nextRef("meetings", "MTG-", 1000);
  const t = now();
  const info = await db.prepare(
    `INSERT INTO meetings(ref, title, company_id, client_id, lead_id, starts_at, minutes, location, channel, owner, status, attendees, agenda, created_at, updated_at)
     VALUES(?,?,?,?,?,?,?,?,?,?,'scheduled',?,?,?,?)`
  ).run(ref, title, b.companyId ?? null, b.clientId ?? null, b.leadId ?? null,
    new Date(parsed).toISOString(), minutes, b.location ?? null, channel,
    b.owner ?? req.user?.name ?? null, b.attendees ?? null, b.agenda ?? null, t, t);
  const created = rowMeeting(await db.prepare(
    "SELECT m.*, co.name AS company_name FROM meetings m LEFT JOIN companies co ON co.id = m.company_id WHERE m.id = ?"
  ).get(info.lastInsertRowid));
  await writeAudit(req, { action: "meeting.created", entityType: "meeting", entityId: created.id, after: created });
  await writeActivity({ entityType: "meeting", entityId: created.id, kind: "meeting",
    summary: `${created.title} scheduled for ${created.startsAt.slice(0, 16).replace("T", " ")}`,
    actor: req.user?.name ?? "system", occurredAt: created.startsAt });
  if (created.companyId) {
    await writeActivity({ entityType: "company", entityId: created.companyId, kind: "meeting",
      summary: `Meeting scheduled: ${created.title}`, actor: req.user?.name ?? "system" });
  }
  return { status: 201, body: { meeting: created } };
}));

app.patch("/api/meetings/:id", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const row = await db.prepare("SELECT * FROM meetings WHERE id = ?").get(req.params.id);
  if (!row) throw Object.assign(new Error("meeting not found"), { statusCode: 404 });
  const before = rowMeeting(row);
  const sets = [];
  const params = [];
  if (b.status !== undefined) {
    const status = String(b.status).toLowerCase();
    if (!MEETING_STATUSES.has(status)) {
      throw Object.assign(new Error(`status must be one of ${[...MEETING_STATUSES].join(", ")}`), { statusCode: 400 });
    }
    /* Being held requires an outcome. A calendar that records attendance and
       not results is a diary, and the outcome is the only part anyone rereads. */
    if (status === "held" && !String(b.outcome ?? row.outcome ?? "").trim()) {
      throw Object.assign(new Error("recording a meeting as held requires an outcome — what came of it?"),
        { statusCode: 400, code: "outcome_required" });
    }
    sets.push("status = ?"); params.push(status);
  }
  for (const [k, col] of Object.entries({ title: "title", location: "location",
    attendees: "attendees", agenda: "agenda", outcome: "outcome", owner: "owner" })) {
    if (b[k] === undefined) continue;
    sets.push(`${col} = ?`); params.push(b[k] === null ? null : String(b[k]));
  }
  if (b.startsAt !== undefined) {
    const parsed = Date.parse(String(b.startsAt));
    if (!Number.isFinite(parsed)) throw Object.assign(new Error("startsAt must be a valid date and time"), { statusCode: 400 });
    sets.push("starts_at = ?"); params.push(new Date(parsed).toISOString());
  }
  if (!sets.length) throw Object.assign(new Error("nothing to update"), { statusCode: 400 });
  sets.push("updated_at = ?"); params.push(now(), row.id);
  await db.prepare(`UPDATE meetings SET ${sets.join(", ")} WHERE id = ?`).run(...params);
  const after = rowMeeting(await db.prepare(
    "SELECT m.*, co.name AS company_name FROM meetings m LEFT JOIN companies co ON co.id = m.company_id WHERE m.id = ?"
  ).get(row.id));
  await writeAudit(req, { action: "meeting.updated", entityType: "meeting", entityId: row.id, before, after });
  if (before.status !== after.status) {
    await writeActivity({ entityType: "meeting", entityId: row.id, kind: "status_change",
      summary: `Status ${before.status} → ${after.status}${after.outcome ? `: ${after.outcome}` : ""}`,
      actor: req.user?.name ?? "system" });
    if (after.companyId) {
      await writeActivity({ entityType: "company", entityId: after.companyId, kind: "meeting",
        summary: `${after.title} — ${after.status}${after.outcome ? `: ${after.outcome}` : ""}`,
        actor: req.user?.name ?? "system" });
    }
  }
  return { meeting: after };
}));

/* ──────────────────────────── activity ────────────────────────────
   The timeline for any entity. Append-only: UPDATE and DELETE are refused by
   triggers, so the only way to correct a history is to add to it. */

app.get("/api/activities", wrap(async (req, res) => {
  const where = [];
  const params = [];
  if (req.query.entity_type) { where.push("entity_type = ?"); params.push(String(req.query.entity_type)); }
  if (req.query.entity_id) { where.push("entity_id = ?"); params.push(Number(req.query.entity_id)); }
  if (req.query.kind) { where.push("kind = ?"); params.push(String(req.query.kind)); }
  const limit = Math.min(500, Math.max(1, Number(req.query.limit) || 100));
  const rows = await db.prepare(
    `SELECT * FROM activities ${where.length ? "WHERE " + where.join(" AND ") : ""} ORDER BY occurred_at DESC, id DESC LIMIT ?`
  ).all(...params, limit);
  res.json({ total: rows.length, kinds: [...ACTIVITY_KINDS], items: rows.map(rowActivity) });
}));

/* Log a note, a call, an email — anything a person did that the system did not
   otherwise observe. The only write path into the timeline not driven by
   another route. */
app.post("/api/activities", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const entityType = String(b.entityType ?? "").trim();
  const entityId = Number(b.entityId);
  const kind = String(b.kind ?? "note").toLowerCase();
  const summary = String(b.summary ?? "").trim();
  if (!entityType) throw Object.assign(new Error("entityType is required"), { statusCode: 400 });
  if (!Number.isInteger(entityId)) throw Object.assign(new Error("entityId is required"), { statusCode: 400 });
  if (!summary) throw Object.assign(new Error("summary is required"), { statusCode: 400 });
  if (!ACTIVITY_KINDS.has(kind)) {
    throw Object.assign(new Error(`kind must be one of ${[...ACTIVITY_KINDS].join(", ")}`), { statusCode: 400 });
  }
  let occurredAt = now();
  if (b.occurredAt) {
    const parsed = Date.parse(String(b.occurredAt));
    if (!Number.isFinite(parsed)) throw Object.assign(new Error("occurredAt must be a valid date and time"), { statusCode: 400 });
    occurredAt = new Date(parsed).toISOString();
  }
  const info = await db.prepare(
    "INSERT INTO activities(entity_type, entity_id, kind, summary, detail, actor, occurred_at, created_at) VALUES(?,?,?,?,?,?,?,?)"
  ).run(entityType, entityId, kind, summary.slice(0, 400),
    b.detail ? String(b.detail).slice(0, 4000) : null, req.user?.name ?? "system", occurredAt, now());
  const created = rowActivity(await db.prepare("SELECT * FROM activities WHERE id = ?").get(info.lastInsertRowid));
  await writeAudit(req, { action: "activity.recorded", entityType, entityId, after: created });
  return { status: 201, body: { activity: created } };
}));

/* ──────────────────────────── attachments ──────────────────────────── */

app.get("/api/attachments", wrap(async (req, res) => {
  const where = [];
  const params = [];
  if (req.query.entity_type) { where.push("entity_type = ?"); params.push(String(req.query.entity_type)); }
  if (req.query.entity_id) { where.push("entity_id = ?"); params.push(Number(req.query.entity_id)); }
  const rows = await db.prepare(
    `SELECT * FROM attachments ${where.length ? "WHERE " + where.join(" AND ") : ""} ORDER BY id DESC LIMIT 300`
  ).all(...params);
  res.json({ total: rows.length, items: rows.map(rowAttachment) });
}));

app.post("/api/attachments", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const entityType = String(b.entityType ?? "").trim();
  const entityId = Number(b.entityId);
  const filename = String(b.filename ?? "").trim();
  if (!entityType) throw Object.assign(new Error("entityType is required"), { statusCode: 400 });
  if (!Number.isInteger(entityId)) throw Object.assign(new Error("entityId is required"), { statusCode: 400 });
  if (!filename) throw Object.assign(new Error("filename is required"), { statusCode: 400 });
  /* The checksum is what stops the same drawing being attached twice and what
     lets a later reader tell revision 2 from revision 1 when the name is
     identical. The same bytes under a different entity are legitimate. */
  const checksum = b.checksum ? String(b.checksum).trim() : null;
  if (checksum) {
    const dupe = await db.prepare(
      "SELECT id FROM attachments WHERE entity_type = ? AND entity_id = ? AND checksum = ?"
    ).get(entityType, entityId, checksum);
    if (dupe) throw Object.assign(new Error(`this exact file is already attached as #${dupe.id}`),
      { statusCode: 409, code: "attachment_exists", attachmentId: dupe.id });
  }
  const size = b.sizeBytes === undefined || b.sizeBytes === null ? null : Math.max(0, Number(b.sizeBytes) || 0);
  const info = await db.prepare(
    `INSERT INTO attachments(entity_type, entity_id, filename, mime_type, size_bytes, path, checksum, uploaded_by, notes, created_at)
     VALUES(?,?,?,?,?,?,?,?,?,?)`
  ).run(entityType, entityId, filename, b.mimeType ?? null, size, b.path ?? null,
    checksum, req.user?.name ?? null, b.notes ?? null, now());
  const created = rowAttachment(await db.prepare("SELECT * FROM attachments WHERE id = ?").get(info.lastInsertRowid));
  await writeAudit(req, { action: "attachment.created", entityType, entityId, after: created });
  await writeActivity({ entityType, entityId, kind: "document",
    summary: `Attached ${filename}`, actor: req.user?.name ?? "system" });
  return { status: 201, body: { attachment: created } };
}));


/* ═══════════════════════════ delivery & installation (015) ═══════════════════════
   Dispatch runs, deliveries with proof, delivery lines, installed assets.

   `orders.stage` is a single current word. It cannot say who drove, what was on
   the vehicle, which items arrived in which run, who signed for them, or when
   the warranty on a particular worktop started. Those are the questions a
   delivery raises, and this block answers them.

   Partial delivery is the normal case: a kitchen arrives in three runs. The
   order counts as delivered when the lines account for every unit, and until
   then the shortfall is a number rather than an opinion. */

const rowDispatchRun = (r) => ({
  id: r.id, ref: r.ref, vehicle: r.vehicle, driver: r.driver, crew: r.crew,
  address: r.address, city: r.city, scheduledFor: r.scheduled_for, departedAt: r.departed_at,
  returnedAt: r.returned_at, status: r.status, notes: r.notes,
  createdAt: r.created_at, updatedAt: r.updated_at,
  deliveryCount: Number(r.delivery_count ?? 0),
});
const rowDelivery = (r) => ({
  id: r.id, ref: r.ref, orderId: r.order_id, orderRef: r.order_ref ?? null,
  projectId: r.project_id, projectName: r.project_name ?? null,
  dispatchRunId: r.dispatch_run_id, runRef: r.run_ref ?? null,
  customer: r.customer, address: r.address, deliveredAt: r.delivered_at,
  receivedBy: r.received_by, signedBy: r.signed_by, photo: r.photo,
  condition: r.condition, damageNotes: r.damage_notes, status: r.status, notes: r.notes,
  createdAt: r.created_at, updatedAt: r.updated_at,
  lineCount: Number(r.line_count ?? 0), units: Number(r.units ?? 0),
});
/* A delivery plus the references it points at. Every route that returns one
   reads through this, because the same row was previously assembled in four
   places and the POST response was missing the order it belonged to while the
   GET response had it — the kind of inconsistency nobody reports as a bug and
   everybody stops trusting. */
const deliverySql = (where) => `SELECT d.*, o.ref AS linked_order_ref, p.name AS linked_project_name, r.ref AS linked_run_ref
  FROM deliveries d
  LEFT JOIN orders o ON o.id = d.order_id
  LEFT JOIN projects p ON p.id = d.project_id
  LEFT JOIN dispatch_runs r ON r.id = d.dispatch_run_id
  WHERE ${where}`;
const deliveryFromRow = (r) => rowDelivery({
  ...r, order_ref: r.linked_order_ref, project_name: r.linked_project_name, run_ref: r.linked_run_ref,
});
const rowDeliveryLine = (r) => ({
  id: r.id, deliveryId: r.delivery_id, productId: r.product_id, description: r.description,
  qty: Number(r.qty), unit: r.unit, serial: r.serial,
});
const rowInstalledAsset = (r) => ({
  id: r.id, tag: r.tag, orderId: r.order_id, projectId: r.project_id, deliveryId: r.delivery_id,
  productId: r.product_id, name: r.name, location: r.location, room: r.room, serial: r.serial,
  installedOn: r.installed_on, installedBy: r.installed_by,
  warrantyMonths: r.warranty_months === null ? null : Number(r.warranty_months),
  warrantyUntil: r.warranty_until, status: r.status, notes: r.notes,
  createdAt: r.created_at, updatedAt: r.updated_at,
  inWarranty: r.warranty_until ? String(r.warranty_until) >= new Date().toISOString().slice(0, 10) : null,
});

/* Warranty end, from an install date and a term. Stored, never recomputed: a
   later change to the product's warranty term must not silently shorten a
   warranty that was already promised to a customer. */
function warrantyEnd(installedOn, months) {
  if (!installedOn || months === null || months === undefined) return null;
  const d = new Date(String(installedOn) + "T00:00:00Z");
  if (!Number.isFinite(d.getTime())) return null;
  d.setUTCMonth(d.getUTCMonth() + Number(months));
  return d.toISOString().slice(0, 10);
}

/* ─────────────────────────── dispatch runs ─────────────────────────── */

app.get("/api/dispatch-runs", wrap(async (req, res) => {
  const where = [];
  const params = [];
  if (req.query.status) { where.push("r.status = ?"); params.push(String(req.query.status)); }
  if (req.query.from) { where.push("r.scheduled_for >= ?"); params.push(String(req.query.from)); }
  const rows = await db.prepare(
    `SELECT r.*, (SELECT COUNT(*) FROM deliveries d WHERE d.dispatch_run_id = r.id) AS delivery_count
       FROM dispatch_runs r ${where.length ? "WHERE " + where.join(" AND ") : ""}
      ORDER BY r.scheduled_for DESC, r.id DESC LIMIT 200`
  ).all(...params);
  res.json({ total: rows.length, items: rows.map(rowDispatchRun) });
}));

app.post("/api/dispatch-runs", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const ref = String(b.ref ?? "").trim() || await nextRef("dispatch_runs", "RUN-", 5000);
  let scheduledFor = null;
  if (b.scheduledFor) {
    const parsed = Date.parse(String(b.scheduledFor));
    if (!Number.isFinite(parsed)) throw Object.assign(new Error("scheduledFor must be a valid date and time"), { statusCode: 400 });
    scheduledFor = new Date(parsed).toISOString();
  }
  const t = now();
  const info = await db.prepare(
    `INSERT INTO dispatch_runs(ref, vehicle, driver, crew, address, city, scheduled_for, status, notes, created_at, updated_at)
     VALUES(?,?,?,?,?,?,?,'planned',?,?,?)`
  ).run(ref, b.vehicle ?? null, b.driver ?? null, b.crew ?? null, b.address ?? null,
    b.city ?? null, scheduledFor, b.notes ?? null, t, t);
  const created = rowDispatchRun(await db.prepare("SELECT * FROM dispatch_runs WHERE id = ?").get(info.lastInsertRowid));
  await writeAudit(req, { action: "dispatch_run.created", entityType: "dispatch_run", entityId: created.id, after: created });
  /* A run is a vehicle leaving the building, so it belongs on the timeline of
     whatever it is going to — but a run has no company link yet, so the event
     goes on the run itself and on each delivery it makes. */
  await writeActivity({ entityType: "dispatch_run", entityId: created.id, kind: "system",
    summary: `Run ${created.ref} planned${created.vehicle ? ` on ${created.vehicle}` : ""}`,
    actor: req.user?.name ?? "system" });
  return { status: 201, body: { run: created } };
}));

app.patch("/api/dispatch-runs/:id", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const row = await db.prepare("SELECT * FROM dispatch_runs WHERE id = ?").get(req.params.id);
  if (!row) throw Object.assign(new Error("dispatch run not found"), { statusCode: 404 });
  const before = rowDispatchRun(row);
  const sets = [];
  const params = [];
  for (const [k, col] of Object.entries({ vehicle: "vehicle", driver: "driver", crew: "crew",
    address: "address", city: "city", notes: "notes" })) {
    if (b[k] === undefined) continue;
    sets.push(`${col} = ?`); params.push(b[k] === null ? null : String(b[k]));
  }
  if (b.status !== undefined) {
    const status = String(b.status).toLowerCase();
    if (!["planned", "dispatched", "completed", "cancelled"].includes(status)) {
      throw Object.assign(new Error("status must be planned, dispatched, completed or cancelled"), { statusCode: 400 });
    }
    /* Departure is stamped once, here, and the trigger refuses to let it be
       rewritten — a late run that can be re-timestamped is a run that is never
       late, which makes the record worthless for the only thing it is for. */
    sets.push("status = ?"); params.push(status);
    if (status === "dispatched" && !row.departed_at) { sets.push("departed_at = ?"); params.push(now()); }
    if (status === "completed") {
      if (!row.departed_at) throw Object.assign(new Error("a run cannot be completed without having been dispatched"), { statusCode: 409, code: "not_dispatched" });
      sets.push("returned_at = ?"); params.push(now());
    }
  }
  if (!sets.length) throw Object.assign(new Error("nothing to update"), { statusCode: 400 });
  sets.push("updated_at = ?"); params.push(now(), row.id);
  await db.prepare(`UPDATE dispatch_runs SET ${sets.join(", ")} WHERE id = ?`).run(...params);
  const after = rowDispatchRun(await db.prepare("SELECT * FROM dispatch_runs WHERE id = ?").get(row.id));
  await writeAudit(req, { action: "dispatch_run.updated", entityType: "dispatch_run", entityId: row.id, before, after });
  if (before.status !== after.status) {
    await writeActivity({ entityType: "dispatch_run", entityId: row.id, kind: "status_change",
      summary: `Run ${after.ref}: ${before.status} → ${after.status}`, actor: req.user?.name ?? "system" });
  }
  return { run: after };
}));

/* ──────────────────────────── deliveries ──────────────────────────── */

app.get("/api/deliveries", wrap(async (req, res) => {
  const where = [];
  const params = [];
  if (req.query.status) { where.push("d.status = ?"); params.push(String(req.query.status)); }
  if (req.query.order_id) { where.push("d.order_id = ?"); params.push(Number(req.query.order_id)); }
  if (req.query.project_id) { where.push("d.project_id = ?"); params.push(Number(req.query.project_id)); }
  if (req.query.run_id) { where.push("d.dispatch_run_id = ?"); params.push(Number(req.query.run_id)); }
  const rows = await db.prepare(
    `SELECT d.*, o.ref AS linked_order_ref, p.name AS linked_project_name, r.ref AS linked_run_ref,
            (SELECT COUNT(*) FROM delivery_lines l WHERE l.delivery_id = d.id) AS line_count,
            (SELECT COALESCE(SUM(l.qty), 0) FROM delivery_lines l WHERE l.delivery_id = d.id) AS units
       FROM deliveries d
       LEFT JOIN orders o ON o.id = d.order_id
       LEFT JOIN projects p ON p.id = d.project_id
       LEFT JOIN dispatch_runs r ON r.id = d.dispatch_run_id
      ${where.length ? "WHERE " + where.join(" AND ") : ""}
      ORDER BY COALESCE(d.delivered_at, d.created_at) DESC LIMIT 300`
  ).all(...params);
  res.json({ total: rows.length, items: rows.map((r) => rowDelivery({
    ...r, order_ref: r.linked_order_ref, project_name: r.linked_project_name, run_ref: r.linked_run_ref,
  })) });
}));

app.get("/api/deliveries/:id", wrap(async (req, res) => {
  /* d.* already carries `ref`, which is the delivery's own reference; the
     joined references must not be given a name that collides with it or d.*
     wins the spread and the delivery's ref is reported as the order's. */
  const row = await db.prepare(deliverySql("d.id = ? OR d.ref = ?")).get(req.params.id, req.params.id);
  if (!row) throw Object.assign(new Error("delivery not found"), { statusCode: 404 });
  const lines = (await db.prepare("SELECT * FROM delivery_lines WHERE delivery_id = ? ORDER BY id").all(row.id)).map(rowDeliveryLine);
  const assets = (await db.prepare("SELECT * FROM installed_assets WHERE delivery_id = ? ORDER BY id").all(row.id)).map(rowInstalledAsset);
  const timeline = (await db.prepare("SELECT * FROM activities WHERE entity_type = 'delivery' AND entity_id = ? ORDER BY occurred_at DESC, id DESC").all(row.id)).map(rowActivity);
  res.json({
    delivery: rowDelivery({ ...row, order_ref: row.linked_order_ref, project_name: row.linked_project_name,
      run_ref: row.linked_run_ref, line_count: lines.length, units: lines.reduce((a, l) => a + l.qty, 0) }),
    lines, assets, timeline,
  });
}));

app.post("/api/deliveries", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const customer = String(b.customer ?? "").trim();
  if (!customer) throw Object.assign(new Error("customer is required"), { statusCode: 400 });
  let orderId = b.orderId ?? null;
  if (orderId !== null) {
    const o = await db.prepare("SELECT id FROM orders WHERE id = ?").get(Number(orderId));
    if (!o) throw Object.assign(new Error("order not found: " + orderId), { statusCode: 404 });
    orderId = o.id;
  }
  let projectId = b.projectId ?? null;
  if (projectId !== null) {
    const p = await db.prepare("SELECT id FROM projects WHERE id = ?").get(Number(projectId));
    if (!p) throw Object.assign(new Error("project not found: " + projectId), { statusCode: 404 });
    projectId = p.id;
  }
  let runId = b.dispatchRunId ?? null;
  if (runId !== null) {
    const r = await db.prepare("SELECT id FROM dispatch_runs WHERE id = ?").get(Number(runId));
    if (!r) throw Object.assign(new Error("dispatch run not found: " + runId), { statusCode: 404 });
    runId = r.id;
  }
  const ref = String(b.ref ?? "").trim() || await nextRef("deliveries", "DLV-", 3000);
  const t = now();
  const info = await db.prepare(
    `INSERT INTO deliveries(ref, order_id, project_id, dispatch_run_id, customer, address, status, condition, notes, created_at, updated_at)
     VALUES(?,?,?,?,?,?,'pending',?,?,?,?)`
  ).run(ref, orderId, projectId, runId, customer, b.address ?? null,
    b.condition ?? "good", b.notes ?? null, t, t);
  const deliveryId = Number(info.lastInsertRowid);

  /* Lines are optional at creation and required before the delivery can be
     marked delivered — a delivery whose contents are unknown cannot be checked
     against the order, so it cannot prove anything arrived. They are written
     BEFORE the row is read back: reading first returned a delivery reporting
     zero lines and zero units to the screen that had just sent two, which
     reads as "my line was not saved" even though it was. */
  if (Array.isArray(b.lines) && b.lines.length) {
    for (const line of b.lines) {
      const qty = Number(line.qty);
      if (!Number.isInteger(qty) || qty <= 0) throw Object.assign(new Error("each line needs a positive whole quantity"), { statusCode: 400 });
      if (!String(line.description ?? "").trim()) throw Object.assign(new Error("each line needs a description"), { statusCode: 400 });
      await db.prepare(
        "INSERT INTO delivery_lines(delivery_id, product_id, description, qty, unit, serial) VALUES(?,?,?,?,?,?)"
      ).run(deliveryId, line.productId ?? null, String(line.description).trim(), qty,
        line.unit ?? null, line.serial ?? null);
    }
  }

  const counted = await db.prepare(
    "SELECT COUNT(*) AS line_count, COALESCE(SUM(qty), 0) AS units FROM delivery_lines WHERE delivery_id = ?"
  ).get(deliveryId);
  const created = deliveryFromRow({
    ...(await db.prepare(deliverySql("d.id = ?")).get(deliveryId)),
    line_count: counted?.line_count ?? 0, units: counted?.units ?? 0,
  });
  await writeAudit(req, { action: "delivery.created", entityType: "delivery", entityId: created.id, after: created });
  await writeActivity({ entityType: "delivery", entityId: created.id, kind: "system",
    summary: `Delivery ${created.ref} created for ${customer}`, actor: req.user?.name ?? "system" });
  return { status: 201, body: { delivery: created } };
}));

/* Adding a line after the fact. Refused once the delivery is signed — the
   contents of a signed delivery are what the signature attests to. */
app.post("/api/deliveries/:id/lines", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const row = await db.prepare("SELECT * FROM deliveries WHERE id = ?").get(req.params.id);
  if (!row) throw Object.assign(new Error("delivery not found"), { statusCode: 404 });
  if (row.status === "delivered") {
    throw Object.assign(new Error("a signed delivery keeps its contents: what it carried is what the signature attests to"),
      { statusCode: 409, code: "delivery_signed" });
  }
  const qty = Number(b.qty);
  if (!Number.isInteger(qty) || qty <= 0) throw Object.assign(new Error("qty must be a positive whole number"), { statusCode: 400 });
  const description = String(b.description ?? "").trim();
  if (!description) throw Object.assign(new Error("description is required"), { statusCode: 400 });
  const info = await db.prepare(
    "INSERT INTO delivery_lines(delivery_id, product_id, description, qty, unit, serial) VALUES(?,?,?,?,?,?)"
  ).run(row.id, b.productId ?? null, description, qty, b.unit ?? null, b.serial ?? null);
  const created = rowDeliveryLine(await db.prepare("SELECT * FROM delivery_lines WHERE id = ?").get(info.lastInsertRowid));
  await writeAudit(req, { action: "delivery_line.created", entityType: "delivery", entityId: row.id, after: created });
  return { status: 201, body: { line: created } };
}));

/* Marking a delivery delivered. This is the one transition that asserts
   something about the physical world, so it demands the evidence. */
app.patch("/api/deliveries/:id", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const row = await db.prepare("SELECT * FROM deliveries WHERE id = ?").get(req.params.id);
  if (!row) throw Object.assign(new Error("delivery not found"), { statusCode: 404 });
  const before = rowDelivery(row);
  const sets = [];
  const params = [];
  for (const [k, col] of Object.entries({ address: "address", photo: "photo",
    damageNotes: "damage_notes", notes: "notes", receivedBy: "received_by" })) {
    if (b[k] === undefined) continue;
    sets.push(`${col} = ?`); params.push(b[k] === null ? null : String(b[k]));
  }
  if (b.condition !== undefined) {
    const condition = String(b.condition).toLowerCase();
    if (!["good", "damaged", "partial", "rejected"].includes(condition)) {
      throw Object.assign(new Error("condition must be good, damaged, partial or rejected"), { statusCode: 400 });
    }
    sets.push("condition = ?"); params.push(condition);
    /* Damage without a note is a claim nobody can act on. */
    if (condition !== "good" && !String(b.damageNotes ?? row.damage_notes ?? "").trim()) {
      throw Object.assign(new Error(`recording a delivery as ${condition} needs a note saying what was wrong`),
        { statusCode: 400, code: "damage_note_required" });
    }
  }
  if (b.status !== undefined) {
    const status = String(b.status).toLowerCase();
    if (!["pending", "in_transit", "delivered", "failed", "returned"].includes(status)) {
      throw Object.assign(new Error("status must be pending, in_transit, delivered, failed or returned"), { statusCode: 400 });
    }
    if (status === "delivered") {
      const lineCount = Number((await db.prepare("SELECT COUNT(*) AS n FROM delivery_lines WHERE delivery_id = ?").get(row.id))?.n ?? 0);
      if (!lineCount) {
        throw Object.assign(new Error("a delivery with no lines cannot be marked delivered — nothing says what arrived"),
          { statusCode: 409, code: "no_lines" });
      }
      const signedBy = String(b.signedBy ?? row.signed_by ?? "").trim();
      if (!signedBy) {
        throw Object.assign(new Error("a delivery marked delivered needs the name of who signed for it"),
          { statusCode: 400, code: "signature_required" });
      }
      sets.push("signed_by = ?"); params.push(signedBy);
      sets.push("delivered_at = ?"); params.push(b.deliveredAt ?? now());
    }
    sets.push("status = ?"); params.push(status);
  }
  if (!sets.length) throw Object.assign(new Error("nothing to update"), { statusCode: 400 });
  sets.push("updated_at = ?"); params.push(now(), row.id);
  await db.prepare(`UPDATE deliveries SET ${sets.join(", ")} WHERE id = ?`).run(...params);
  const after = deliveryFromRow(await db.prepare(deliverySql("d.id = ?")).get(row.id));
  await writeAudit(req, { action: "delivery.updated", entityType: "delivery", entityId: row.id, before, after });
  if (before.status !== after.status) {
    await writeActivity({ entityType: "delivery", entityId: row.id, kind: "status_change",
      summary: `Delivery ${after.ref}: ${before.status} → ${after.status}${after.signedBy ? `, signed by ${after.signedBy}` : ""}`,
      actor: req.user?.name ?? "system" });
  }
  return { delivery: after };
}));

/* How much of an order has actually arrived. The number that says whether
   "delivered" is true, rather than a stage someone set by hand. */
app.get("/api/deliveries/shortfall/:orderId", wrap(async (req, res) => {
  const order = await db.prepare("SELECT * FROM orders WHERE id = ? OR ref = ?").get(req.params.orderId, req.params.orderId);
  if (!order) throw Object.assign(new Error("order not found"), { statusCode: 404 });
  let items = [];
  try { items = JSON.parse(order.items ?? "[]"); } catch { items = []; }
  const expected = items.reduce((a, i) => a + Number(i.qty ?? 1), 0);
  const arrived = Number((await db.prepare(
    `SELECT COALESCE(SUM(l.qty), 0) AS n FROM delivery_lines l
       JOIN deliveries d ON d.id = l.delivery_id
      WHERE d.order_id = ? AND d.status = 'delivered'`
  ).get(order.id))?.n ?? 0);
  res.json({
    orderId: order.id, orderRef: order.ref,
    expected, arrived, outstanding: Math.max(0, expected - arrived),
    complete: expected > 0 && arrived >= expected,
    /* A partial delivery is not a failure: it is three runs out of five. */
    partial: arrived > 0 && arrived < expected,
  });
}));

/* ─────────────────────────── installed assets ─────────────────────────── */

app.get("/api/installed-assets", wrap(async (req, res) => {
  const where = [];
  const params = [];
  if (req.query.order_id) { where.push("order_id = ?"); params.push(Number(req.query.order_id)); }
  if (req.query.project_id) { where.push("project_id = ?"); params.push(Number(req.query.project_id)); }
  if (req.query.status) { where.push("status = ?"); params.push(String(req.query.status)); }
  if (req.query.in_warranty === "1") {
    where.push("warranty_until IS NOT NULL AND warranty_until >= ?"); params.push(new Date().toISOString().slice(0, 10));
  }
  const rows = await db.prepare(
    `SELECT * FROM installed_assets ${where.length ? "WHERE " + where.join(" AND ") : ""}
      ORDER BY COALESCE(installed_on, created_at) DESC LIMIT 300`
  ).all(...params);
  const items = rows.map(rowInstalledAsset);
  res.json({
    total: items.length,
    inWarranty: items.filter((a) => a.inWarranty === true).length,
    items,
  });
}));

/* One asset, with its whole life: what was installed, when the warranty ends,
   what has been reported wrong with it, and which visits and claims followed.
   This is the page a technician reads before driving out — it answers "has this
   happened before" without asking anyone. */
app.get("/api/installed-assets/:id", wrap(async (req, res) => {
  const row = await db.prepare("SELECT * FROM installed_assets WHERE id = ? OR tag = ?")
    .get(Number(req.params.id), String(req.params.id));
  if (!row) throw Object.assign(new Error("installed asset not found"), { statusCode: 404 });
  const order = row.order_id
    ? await db.prepare("SELECT ref, customer FROM orders WHERE id = ?").get(row.order_id) : null;
  const project = row.project_id
    ? await db.prepare("SELECT name FROM projects WHERE id = ?").get(row.project_id) : null;
  const tickets = (await db.prepare(
    `${ticketSql("t.asset_id = ?")} ORDER BY t.created_at DESC`
  ).all(row.id)).map(rowTicket);
  const claims = (await db.prepare(
    "SELECT * FROM warranty_claims WHERE asset_id = ? ORDER BY claimed_on DESC, id DESC"
  ).all(row.id)).map(rowWarrantyClaim);
  /* Visits belong to tickets, not to assets, so they are reached through the
     tickets raised about this asset — a visit with no ticket has nothing to do
     with this object and would be noise here. */
  const visits = tickets.length ? (await db.prepare(
    `SELECT v.*, t.ref AS ticket_ref FROM service_visits v JOIN tickets t ON t.id = v.ticket_id
      WHERE v.ticket_id IN (${tickets.map(() => "?").join(",")})
      ORDER BY v.scheduled_for DESC, v.id DESC`
  ).all(...tickets.map((t) => t.id))).map(rowServiceVisit) : [];
  const timeline = (await db.prepare(
    "SELECT * FROM activities WHERE entity_type = 'installed_asset' AND entity_id = ? ORDER BY occurred_at DESC, id DESC"
  ).all(row.id)).map(rowActivity);
  res.json({
    asset: rowInstalledAsset(row),
    orderRef: order?.ref ?? null,
    customer: order?.customer ?? null,
    projectName: project?.name ?? null,
    tickets, claims, visits, timeline,
  });
}));

/* Install an asset. The warranty end is computed here and stored — see the
   migration header for why it is not recomputed later. */
app.post("/api/installed-assets", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const name = String(b.name ?? "").trim();
  if (!name) throw Object.assign(new Error("name is required"), { statusCode: 400 });
  let orderId = b.orderId ?? null;
  if (orderId !== null) {
    const o = await db.prepare("SELECT id FROM orders WHERE id = ?").get(Number(orderId));
    if (!o) throw Object.assign(new Error("order not found: " + orderId), { statusCode: 404 });
    orderId = o.id;
  }
  let deliveryId = b.deliveryId ?? null;
  if (deliveryId !== null) {
    const d = await db.prepare("SELECT id FROM deliveries WHERE id = ?").get(Number(deliveryId));
    if (!d) throw Object.assign(new Error("delivery not found: " + deliveryId), { statusCode: 404 });
    deliveryId = d.id;
  }
  const installedOn = b.installedOn ? String(b.installedOn).slice(0, 10) : new Date().toISOString().slice(0, 10);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(installedOn) || !Number.isFinite(Date.parse(installedOn))) {
    throw Object.assign(new Error("installedOn must be a date, YYYY-MM-DD"), { statusCode: 400 });
  }
  const months = b.warrantyMonths === undefined || b.warrantyMonths === null ? null : Number(b.warrantyMonths);
  if (months !== null && (!Number.isInteger(months) || months < 0)) {
    throw Object.assign(new Error("warrantyMonths must be a whole number of months, zero or more"), { statusCode: 400 });
  }
  const tag = String(b.tag ?? "").trim() || await nextRefColumn("installed_assets", "tag", "AST-", 7000);
  const t = now();
  const info = await db.prepare(
    `INSERT INTO installed_assets(tag, order_id, project_id, delivery_id, product_id, name, location, room,
      serial, installed_on, installed_by, warranty_months, warranty_until, status, notes, created_at, updated_at)
     VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,'installed',?,?,?)`
  ).run(tag, orderId, b.projectId ?? null, deliveryId, b.productId ?? null, name,
    b.location ?? null, b.room ?? null, b.serial ?? null, installedOn,
    b.installedBy ?? req.user?.name ?? null, months, warrantyEnd(installedOn, months),
    b.notes ?? null, t, t);
  const created = rowInstalledAsset(await db.prepare("SELECT * FROM installed_assets WHERE id = ?").get(info.lastInsertRowid));
  await writeAudit(req, { action: "installed_asset.created", entityType: "installed_asset", entityId: created.id, after: created });
  await writeActivity({ entityType: "installed_asset", entityId: created.id, kind: "system",
    summary: `${created.name} installed${created.room ? ` in ${created.room}` : ""}${created.warrantyUntil ? `, warranty to ${created.warrantyUntil}` : ""}`,
    actor: req.user?.name ?? "system", occurredAt: installedOn + "T00:00:00.000Z" });
  return { status: 201, body: { asset: created } };
}));

app.patch("/api/installed-assets/:id", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const row = await db.prepare("SELECT * FROM installed_assets WHERE id = ? OR tag = ?").get(req.params.id, req.params.id);
  if (!row) throw Object.assign(new Error("installed asset not found"), { statusCode: 404 });
  const before = rowInstalledAsset(row);
  const sets = [];
  const params = [];
  for (const [k, col] of Object.entries({ name: "name", location: "location", room: "room",
    serial: "serial", notes: "notes", installedBy: "installed_by" })) {
    if (b[k] === undefined) continue;
    sets.push(`${col} = ?`); params.push(b[k] === null ? null : String(b[k]));
  }
  if (b.status !== undefined) {
    const status = String(b.status).toLowerCase();
    if (!["installed", "removed", "replaced", "faulty"].includes(status)) {
      throw Object.assign(new Error("status must be installed, removed, replaced or faulty"), { statusCode: 400 });
    }
    sets.push("status = ?"); params.push(status);
  }
  /* Re-provisioning the warranty is refused once it has been promised: the
     stored date is what the customer was told, and moving it after the fact is
     the thing this column exists to prevent. */
  if (b.warrantyUntil !== undefined) {
    if (before.warrantyUntil && b.warrantyUntil !== before.warrantyUntil) {
      throw Object.assign(new Error(`the warranty on this asset already runs to ${before.warrantyUntil}; a promised end date is not renegotiated by editing it`),
        { statusCode: 409, code: "warranty_locked" });
    }
    sets.push("warranty_until = ?"); params.push(b.warrantyUntil);
  }
  if (!sets.length) throw Object.assign(new Error("nothing to update"), { statusCode: 400 });
  sets.push("updated_at = ?"); params.push(now(), row.id);
  await db.prepare(`UPDATE installed_assets SET ${sets.join(", ")} WHERE id = ?`).run(...params);
  const after = rowInstalledAsset(await db.prepare("SELECT * FROM installed_assets WHERE id = ?").get(row.id));
  await writeAudit(req, { action: "installed_asset.updated", entityType: "installed_asset", entityId: row.id, before, after });
  if (before.status !== after.status) {
    await writeActivity({ entityType: "installed_asset", entityId: row.id, kind: "status_change",
      summary: `${after.name}: ${before.status} → ${after.status}`, actor: req.user?.name ?? "system" });
  }
  return { asset: after };
}));


/* ═══════════════════════════ support & warranty (016) ═══════════════════════════
   Tickets, messages, service visits, warranty claims.

   A ticket is the customer's claim. A visit is Woodex's response. A claim is the
   answer about coverage, recorded with the date it was decided. Collapsing them
   means a ticket can only ever have one attempt, or the attempts end up in a
   notes field where nothing can be reported on.

   The SLA is arithmetic, not a colour judgement: deadlines are computed from the
   priority at intake and frozen, so a breach cannot be made to disappear by
   downgrading a ticket, waiting, and upgrading it again. */

/* Hours to first response and to resolution, by priority. The promise made at
   intake; changing it later does not move a deadline already set. */
const SLA_HOURS = {
  urgent: { respond: 2, resolve: 24 },
  high:   { respond: 8, resolve: 72 },
  normal: { respond: 24, resolve: 168 },
  low:    { respond: 72, resolve: 336 },
};

const addHours = (iso, hours) => new Date(Date.parse(iso) + hours * 3600_000).toISOString();

const rowTicket = (r) => {
  const nowIso = new Date().toISOString();
  const open = !r.resolved_at && !r.closed_at;
  return {
    id: r.id, ref: r.ref, customer: r.customer, clientId: r.client_id, companyId: r.company_id,
    orderId: r.order_id, projectId: r.project_id, assetId: r.asset_id, assetTag: r.asset_tag ?? null,
    assetName: r.asset_name ?? null, subject: r.subject, description: r.description,
    category: r.category, channel: r.channel, priority: r.priority, status: r.status,
    waitingOn: r.waiting_on, owner: r.owner,
    firstResponseAt: r.first_response_at, respondBy: r.respond_by, resolveBy: r.resolve_by,
    resolvedAt: r.resolved_at, resolution: r.resolution, closedAt: r.closed_at,
    createdAt: r.created_at, updatedAt: r.updated_at,
    /* Derived, never stored: the moment the deadline passes the flag must flip
       without anybody writing to the row. */
    responseBreached: open && !r.first_response_at && !!r.respond_by && nowIso > r.respond_by,
    resolutionBreached: open && !!r.resolve_by && nowIso > r.resolve_by,
    ageHours: Math.max(0, Math.round((Date.now() - Date.parse(r.created_at)) / 3600_000)),
  };
};
const rowTicketMessage = (r) => ({
  id: r.id, ticketId: r.ticket_id, author: r.author, body: r.body,
  internal: !!r.internal, channel: r.channel, createdAt: r.created_at,
});
const rowServiceVisit = (r) => ({
  id: r.id, ref: r.ref, ticketId: r.ticket_id, ticketRef: r.ticket_ref ?? null,
  scheduledFor: r.scheduled_for, technician: r.technician, address: r.address,
  status: r.status, arrivedAt: r.arrived_at, completedAt: r.completed_at,
  workDone: r.work_done, partsUsed: r.parts_used,
  chargeable: !!r.chargeable,
  chargeAmount: r.charge_amount === null ? null : Number(r.charge_amount),
  notes: r.notes, createdAt: r.created_at, updatedAt: r.updated_at,
});
const rowWarrantyClaim = (r) => ({
  id: r.id, ref: r.ref, ticketId: r.ticket_id, assetId: r.asset_id, customer: r.customer,
  part: r.part, fault: r.fault, claimedOn: r.claimed_on,
  warrantyUntilOnClaim: r.warranty_until_on_claim, verdict: r.verdict,
  decidedOn: r.decided_on, decidedBy: r.decided_by, reason: r.reason,
  costToWooodex: r.cost_to_woodex === null ? null : Number(r.cost_to_woodex),
  createdAt: r.created_at, updatedAt: r.updated_at,
});

const ticketSql = (where) => `SELECT t.*, a.tag AS asset_tag, a.name AS asset_name
  FROM tickets t LEFT JOIN installed_assets a ON a.id = t.asset_id WHERE ${where}`;

/* ───────────────────────────────── tickets ───────────────────────────────── */

app.get("/api/tickets", wrap(async (req, res) => {
  const where = [];
  const params = [];
  if (req.query.status) { where.push("t.status = ?"); params.push(String(req.query.status)); }
  if (req.query.priority) { where.push("t.priority = ?"); params.push(String(req.query.priority)); }
  if (req.query.asset_id) { where.push("t.asset_id = ?"); params.push(Number(req.query.asset_id)); }
  if (req.query.customer) { where.push("t.customer LIKE ?"); params.push("%" + String(req.query.customer) + "%"); }
  if (req.query.open === "1") { where.push("t.status IN ('open','in_progress','waiting')"); }
  const rows = await db.prepare(
    `${ticketSql(where.length ? where.join(" AND ") : "1=1")}
      ORDER BY CASE t.priority WHEN 'urgent' THEN 0 WHEN 'high' THEN 1 WHEN 'normal' THEN 2 ELSE 3 END,
               t.created_at DESC LIMIT 300`
  ).all(...params);
  const items = rows.map(rowTicket);
  res.json({
    total: items.length,
    open: items.filter((t) => ["open", "in_progress", "waiting"].includes(t.status)).length,
    breached: items.filter((t) => t.responseBreached || t.resolutionBreached).length,
    items,
  });
}));

app.get("/api/tickets/:id", wrap(async (req, res) => {
  const row = await db.prepare(ticketSql("t.id = ? OR t.ref = ?")).get(req.params.id, req.params.id);
  if (!row) throw Object.assign(new Error("ticket not found"), { statusCode: 404 });
  const messages = (await db.prepare("SELECT * FROM ticket_messages WHERE ticket_id = ? ORDER BY created_at, id").all(row.id)).map(rowTicketMessage);
  const visits = (await db.prepare(
    `SELECT v.*, t.ref AS ticket_ref FROM service_visits v JOIN tickets t ON t.id = v.ticket_id
      WHERE v.ticket_id = ? ORDER BY v.scheduled_for DESC, v.id DESC`
  ).all(row.id)).map(rowServiceVisit);
  const claims = (await db.prepare("SELECT * FROM warranty_claims WHERE ticket_id = ? ORDER BY id").all(row.id)).map(rowWarrantyClaim);
  const timeline = (await db.prepare("SELECT * FROM activities WHERE entity_type = 'ticket' AND entity_id = ? ORDER BY occurred_at DESC, id DESC").all(row.id)).map(rowActivity);
  res.json({ ticket: rowTicket(row), messages, visits, claims, timeline });
}));

app.post("/api/tickets", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const customer = String(b.customer ?? "").trim();
  const subject = String(b.subject ?? "").trim();
  if (!customer) throw Object.assign(new Error("customer is required"), { statusCode: 400 });
  if (!subject) throw Object.assign(new Error("a ticket needs a subject saying what is wrong"), { statusCode: 400 });

  const priority = String(b.priority ?? "normal").toLowerCase();
  if (!Object.keys(SLA_HOURS).includes(priority)) {
    throw Object.assign(new Error(`priority must be ${Object.keys(SLA_HOURS).join(", ")}`), { statusCode: 400 });
  }
  const channel = String(b.channel ?? "phone").toLowerCase();
  if (!["phone", "email", "whatsapp", "visit", "web"].includes(channel)) {
    throw Object.assign(new Error("channel must be phone, email, whatsapp, visit or web"), { statusCode: 400 });
  }
  const category = String(b.category ?? "other").toLowerCase();
  if (!["damage", "defect", "missing_part", "fitting", "finish", "delivery", "other"].includes(category)) {
    throw Object.assign(new Error("category must be damage, defect, missing_part, fitting, finish, delivery or other"), { statusCode: 400 });
  }

  let asset = null;
  if (b.assetId !== undefined && b.assetId !== null && b.assetId !== "") {
    asset = await db.prepare("SELECT * FROM installed_assets WHERE id = ? OR tag = ?").get(Number(b.assetId), String(b.assetId));
    if (!asset) throw Object.assign(new Error("installed asset not found: " + b.assetId), { statusCode: 404 });
  }

  const t = now();
  const sla = SLA_HOURS[priority];
  const ref = await nextRef("tickets", "TCK-", 4000);
  const info = await db.prepare(
    `INSERT INTO tickets(ref, customer, client_id, company_id, order_id, project_id, asset_id,
       subject, description, category, channel, priority, status, waiting_on, owner,
       respond_by, resolve_by, created_at, updated_at)
     VALUES(?,?,?,?,?,?,?,?,?,?,?,?,'open','us',?,?,?,?,?)`
  ).run(ref, customer, b.clientId ?? null, b.companyId ?? null, b.orderId ?? null,
    b.projectId ?? null, asset?.id ?? null, subject, b.description || null, category,
    channel, priority, b.owner ?? req.user?.name ?? null,
    addHours(t, sla.respond), addHours(t, sla.resolve), t, t);
  const id = Number(info.lastInsertRowid);

  /* The description typed at intake becomes the opening message, so the thread
     reads as a conversation from its first line instead of starting with a
     reply to something nobody can see. */
  if (b.description && String(b.description).trim()) {
    await db.prepare("INSERT INTO ticket_messages(ticket_id, author, body, internal, channel, created_at) VALUES(?,?,?,0,?,?)")
      .run(id, customer, String(b.description).trim(), channel, t);
  }

  const created = rowTicket(await db.prepare(ticketSql("t.id = ?")).get(id));
  await writeAudit(req, { action: "ticket.created", entityType: "ticket", entityId: id, after: created });
  await writeActivity({ entityType: "ticket", entityId: id, kind: "system",
    summary: `Ticket ${created.ref} opened: ${subject}`, actor: req.user?.name ?? "system" });
  /* A ticket about an installed asset belongs on that asset's history too —
     that is the whole point of the warranty register. */
  if (asset) {
    await writeActivity({ entityType: "installed_asset", entityId: asset.id, kind: "status_change",
      summary: `Reported fault (${created.ref}): ${subject}`, actor: req.user?.name ?? "system" });
  }
  return { status: 201, body: { ticket: created } };
}));

/* Adding a message. The first OUTBOUND message stamps `first_response_at` — an
   internal note does not, which is why this cannot be set at creation. */
app.post("/api/tickets/:id/messages", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const row = await db.prepare("SELECT * FROM tickets WHERE id = ? OR ref = ?").get(req.params.id, req.params.id);
  if (!row) throw Object.assign(new Error("ticket not found"), { statusCode: 404 });
  const body = String(b.body ?? "").trim();
  if (!body) throw Object.assign(new Error("a message needs a body"), { statusCode: 400 });
  if (row.closed_at) {
    throw Object.assign(new Error("a closed ticket is reopened before it is written to, so the reopening is visible"),
      { statusCode: 409, code: "ticket_closed" });
  }
  const internal = b.internal === true;
  const t = now();
  const info = await db.prepare(
    "INSERT INTO ticket_messages(ticket_id, author, body, internal, channel, created_at) VALUES(?,?,?,?,?,?)"
  ).run(row.id, b.author ?? req.user?.name ?? "system", body, internal ? 1 : 0, b.channel ?? null, t);

  const sets = ["updated_at = ?"];
  const params = [t];
  /* Only an outbound message counts as a response. An internal note is the team
     talking to itself, and counting it would let a ticket look answered while
     the customer has heard nothing. */
  if (!internal && !row.first_response_at) {
    sets.push("first_response_at = ?"); params.push(t);
  }
  if (row.waiting_on === "them" && !internal) { sets.push("waiting_on = 'us'"); }
  params.push(row.id);
  await db.prepare(`UPDATE tickets SET ${sets.join(", ")} WHERE id = ?`).run(...params);

  const created = rowTicketMessage(await db.prepare("SELECT * FROM ticket_messages WHERE id = ?").get(info.lastInsertRowid));
  await writeAudit(req, { action: internal ? "ticket.note" : "ticket.reply", entityType: "ticket", entityId: row.id, after: created });
  const after = rowTicket(await db.prepare(ticketSql("t.id = ?")).get(row.id));
  return { status: 201, body: { message: created, ticket: after } };
}));

app.patch("/api/tickets/:id", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const row = await db.prepare("SELECT * FROM tickets WHERE id = ? OR ref = ?").get(req.params.id, req.params.id);
  if (!row) throw Object.assign(new Error("ticket not found"), { statusCode: 404 });
  const before = rowTicket(row);
  const sets = [];
  const params = [];
  for (const [k, col] of Object.entries({ owner: "owner", resolution: "resolution", subject: "subject", description: "description" })) {
    if (b[k] === undefined) continue;
    sets.push(`${col} = ?`); params.push(b[k] === null ? null : String(b[k]));
  }
  if (b.status !== undefined) {
    const status = String(b.status).toLowerCase();
    if (!["open", "in_progress", "waiting", "resolved", "closed"].includes(status)) {
      throw Object.assign(new Error("status must be open, in_progress, waiting, resolved or closed"), { statusCode: 400 });
    }
    if (status === "resolved") {
      const resolution = String(b.resolution ?? row.resolution ?? "").trim();
      if (!resolution) {
        throw Object.assign(new Error("resolving a ticket needs a resolution saying what fixed it"),
          { statusCode: 400, code: "resolution_required" });
      }
      sets.push("resolution = ?"); params.push(resolution);
      sets.push("resolved_at = ?"); params.push(row.resolved_at ?? now());
    }
    if (status === "closed") {
      if (!row.resolved_at && !b.resolution) {
        throw Object.assign(new Error("a ticket is resolved before it is closed — otherwise nobody knows what fixed it"),
          { statusCode: 409, code: "not_resolved" });
      }
      sets.push("closed_at = ?"); params.push(now());
    }
    if (status === "waiting") {
      sets.push("waiting_on = ?"); params.push(b.waitingOn === "us" ? "us" : "them");
    }
    if (status === "open" || status === "in_progress") { sets.push("waiting_on = 'us'"); }
    sets.push("status = ?"); params.push(status);
  }
  if (b.waitingOn !== undefined && b.status === undefined) {
    const w = String(b.waitingOn);
    if (!["us", "them"].includes(w)) throw Object.assign(new Error("waitingOn must be us or them"), { statusCode: 400 });
    sets.push("waiting_on = ?"); params.push(w);
  }
  /* Re-raising the priority is allowed; moving the SLA deadline is not. The
     trigger refuses it, and refusing here too gives a message that says why. */
  if (b.priority !== undefined) {
    const priority = String(b.priority).toLowerCase();
    if (!Object.keys(SLA_HOURS).includes(priority)) {
      throw Object.assign(new Error(`priority must be ${Object.keys(SLA_HOURS).join(", ")}`), { statusCode: 400 });
    }
    sets.push("priority = ?"); params.push(priority);
  }
  if (!sets.length) throw Object.assign(new Error("nothing to update"), { statusCode: 400 });
  sets.push("updated_at = ?"); params.push(now(), row.id);
  await db.prepare(`UPDATE tickets SET ${sets.join(", ")} WHERE id = ?`).run(...params);
  const after = rowTicket(await db.prepare(ticketSql("t.id = ?")).get(row.id));
  await writeAudit(req, { action: "ticket.updated", entityType: "ticket", entityId: row.id, before, after });
  if (before.status !== after.status) {
    await writeActivity({ entityType: "ticket", entityId: row.id, kind: "status_change",
      summary: `${after.ref}: ${before.status} → ${after.status}${after.resolution ? `: ${after.resolution}` : ""}`,
      actor: req.user?.name ?? "system" });
  }
  return { ticket: after };
}));

/* ─────────────────────────── service visits ─────────────────────────── */

app.get("/api/service-visits", wrap(async (req, res) => {
  const where = [];
  const params = [];
  if (req.query.status) { where.push("v.status = ?"); params.push(String(req.query.status)); }
  if (req.query.ticket_id) { where.push("v.ticket_id = ?"); params.push(Number(req.query.ticket_id)); }
  const rows = await db.prepare(
    `SELECT v.*, t.ref AS ticket_ref FROM service_visits v JOIN tickets t ON t.id = v.ticket_id
      ${where.length ? "WHERE " + where.join(" AND ") : ""}
      ORDER BY v.scheduled_for DESC, v.id DESC LIMIT 300`
  ).all(...params);
  res.json({ total: rows.length, items: rows.map(rowServiceVisit) });
}));

app.post("/api/service-visits", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const ticket = await db.prepare("SELECT * FROM tickets WHERE id = ? OR ref = ?").get(Number(b.ticketId), String(b.ticketId ?? ""));
  if (!ticket) throw Object.assign(new Error("ticket not found: " + b.ticketId), { statusCode: 404 });
  let scheduledFor = null;
  if (b.scheduledFor) {
    const parsed = Date.parse(String(b.scheduledFor));
    if (!Number.isFinite(parsed)) throw Object.assign(new Error("scheduledFor must be a valid date and time"), { statusCode: 400 });
    scheduledFor = new Date(parsed).toISOString();
  }
  const ref = await nextRef("service_visits", "VIS-", 2000);
  const t = now();
  const info = await db.prepare(
    `INSERT INTO service_visits(ref, ticket_id, scheduled_for, technician, address, status, notes, created_at, updated_at)
     VALUES(?,?,?,?,?,'scheduled',?,?,?)`
  ).run(ref, ticket.id, scheduledFor, b.technician ?? null, b.address ?? ticket.address ?? null,
    b.notes ?? null, t, t);
  const created = rowServiceVisit(await db.prepare(
    "SELECT v.*, t.ref AS ticket_ref FROM service_visits v JOIN tickets t ON t.id = v.ticket_id WHERE v.id = ?"
  ).get(info.lastInsertRowid));
  await writeAudit(req, { action: "service_visit.created", entityType: "service_visit", entityId: created.id, after: created });
  await writeActivity({ entityType: "ticket", entityId: ticket.id, kind: "meeting",
    summary: `Visit ${created.ref} scheduled${created.technician ? ` with ${created.technician}` : ""}`,
    actor: req.user?.name ?? "system" });
  return { status: 201, body: { visit: created } };
}));

app.patch("/api/service-visits/:id", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const row = await db.prepare("SELECT * FROM service_visits WHERE id = ? OR ref = ?").get(req.params.id, req.params.id);
  if (!row) throw Object.assign(new Error("visit not found"), { statusCode: 404 });
  const before = rowServiceVisit(row);
  const sets = [];
  const params = [];
  for (const [k, col] of Object.entries({ technician: "technician", address: "address",
    workDone: "work_done", partsUsed: "parts_used", notes: "notes" })) {
    if (b[k] === undefined) continue;
    sets.push(`${col} = ?`); params.push(b[k] === null ? null : String(b[k]));
  }
  if (b.scheduledFor !== undefined) {
    const parsed = Date.parse(String(b.scheduledFor));
    if (!Number.isFinite(parsed)) throw Object.assign(new Error("scheduledFor must be a valid date and time"), { statusCode: 400 });
    sets.push("scheduled_for = ?"); params.push(new Date(parsed).toISOString());
  }
  if (b.chargeable !== undefined) { sets.push("chargeable = ?"); params.push(b.chargeable === true ? 1 : 0); }
  if (b.chargeAmount !== undefined) {
    const amount = b.chargeAmount === null ? null : Number(b.chargeAmount);
    if (amount !== null && (!Number.isFinite(amount) || amount < 0)) {
      throw Object.assign(new Error("chargeAmount must be zero or more"), { statusCode: 400 });
    }
    sets.push("charge_amount = ?"); params.push(amount);
  }
  if (b.status !== undefined) {
    const status = String(b.status).toLowerCase();
    if (!["scheduled", "attended", "completed", "missed", "cancelled"].includes(status)) {
      throw Object.assign(new Error("status must be scheduled, attended, completed, missed or cancelled"), { statusCode: 400 });
    }
    if (status === "attended" && !row.arrived_at) { sets.push("arrived_at = ?"); params.push(now()); }
    if (status === "completed") {
      /* A completed visit says what was done. "Completed" with no work recorded
         is the same as no visit at all, except that it closes the ticket. */
      const workDone = String(b.workDone ?? row.work_done ?? "").trim();
      if (!workDone) {
        throw Object.assign(new Error("completing a visit needs a note of what was actually done"),
          { statusCode: 400, code: "work_done_required" });
      }
      sets.push("work_done = ?"); params.push(workDone);
      sets.push("completed_at = ?"); params.push(row.completed_at ?? now());
      if (!row.arrived_at) { sets.push("arrived_at = ?"); params.push(now()); }
    }
    sets.push("status = ?"); params.push(status);
  }
  if (!sets.length) throw Object.assign(new Error("nothing to update"), { statusCode: 400 });
  sets.push("updated_at = ?"); params.push(now(), row.id);
  await db.prepare(`UPDATE service_visits SET ${sets.join(", ")} WHERE id = ?`).run(...params);
  const after = rowServiceVisit(await db.prepare(
    "SELECT v.*, t.ref AS ticket_ref FROM service_visits v JOIN tickets t ON t.id = v.ticket_id WHERE v.id = ?"
  ).get(row.id));
  await writeAudit(req, { action: "service_visit.updated", entityType: "service_visit", entityId: row.id, before, after });
  if (before.status !== after.status) {
    await writeActivity({ entityType: "ticket", entityId: row.ticket_id, kind: "status_change",
      summary: `Visit ${after.ref}: ${before.status} → ${after.status}${after.workDone ? ` — ${after.workDone}` : ""}`,
      actor: req.user?.name ?? "system" });
  }
  return { visit: after };
}));

/* ─────────────────────────── warranty claims ─────────────────────────── */

app.get("/api/warranty-claims", wrap(async (req, res) => {
  const where = [];
  const params = [];
  if (req.query.verdict) { where.push("verdict = ?"); params.push(String(req.query.verdict)); }
  if (req.query.asset_id) { where.push("asset_id = ?"); params.push(Number(req.query.asset_id)); }
  const rows = await db.prepare(
    `SELECT * FROM warranty_claims ${where.length ? "WHERE " + where.join(" AND ") : ""}
      ORDER BY claimed_on DESC, id DESC LIMIT 300`
  ).all(...params);
  res.json({ total: rows.length, items: rows.map(rowWarrantyClaim) });
}));

/* Open a claim. The warranty date is copied from the asset **at the moment of
   the claim** and stored: the asset can be edited and a product's term can
   change, but what the customer was told on the day cannot be re-derived. */
app.post("/api/warranty-claims", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const customer = String(b.customer ?? "").trim();
  const fault = String(b.fault ?? "").trim();
  if (!customer) throw Object.assign(new Error("customer is required"), { statusCode: 400 });
  if (!fault) throw Object.assign(new Error("say what the fault is"), { statusCode: 400 });
  let asset = null;
  if (b.assetId !== undefined && b.assetId !== null && b.assetId !== "") {
    asset = await db.prepare("SELECT * FROM installed_assets WHERE id = ? OR tag = ?").get(Number(b.assetId), String(b.assetId));
    if (!asset) throw Object.assign(new Error("installed asset not found: " + b.assetId), { statusCode: 404 });
  }
  const ref = await nextRef("warranty_claims", "WCL-", 300);
  const t = now();
  const info = await db.prepare(
    `INSERT INTO warranty_claims(ref, ticket_id, asset_id, customer, part, fault, claimed_on,
       warranty_until_on_claim, verdict, created_at, updated_at)
     VALUES(?,?,?,?,?,?,?,?,'pending',?,?)`
  ).run(ref, b.ticketId ?? null, asset?.id ?? null, customer, b.part ?? null, fault,
    String(b.claimedOn ?? new Date().toISOString().slice(0, 10)), asset?.warranty_until ?? null, t, t);
  const created = rowWarrantyClaim(await db.prepare("SELECT * FROM warranty_claims WHERE id = ?").get(info.lastInsertRowid));
  await writeAudit(req, { action: "warranty_claim.created", entityType: "warranty_claim", entityId: created.id, after: created });
  await writeActivity({ entityType: "warranty_claim", entityId: created.id, kind: "system",
    summary: `Claim ${created.ref}: ${fault}${created.warrantyUntilOnClaim ? ` (warranty to ${created.warrantyUntilOnClaim})` : ""}`,
    actor: req.user?.name ?? "system" });
  return { status: 201, body: { claim: created } };
}));

app.patch("/api/warranty-claims/:id", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const row = await db.prepare("SELECT * FROM warranty_claims WHERE id = ? OR ref = ?").get(req.params.id, req.params.id);
  if (!row) throw Object.assign(new Error("claim not found"), { statusCode: 404 });
  if (row.verdict !== "pending") {
    throw Object.assign(new Error(`this claim was already decided (${row.verdict}) on ${row.decided_on}; a decided answer is not reopened, a new claim is raised`),
      { statusCode: 409, code: "claim_decided" });
  }
  const verdict = String(b.verdict ?? "").toLowerCase();
  if (!["covered", "not_covered", "goodwill"].includes(verdict)) {
    throw Object.assign(new Error("verdict must be covered, not_covered or goodwill"), { statusCode: 400 });
  }
  const reason = String(b.reason ?? "").trim();
  if (!reason) {
    throw Object.assign(new Error("a verdict needs a reason — the customer is told it, and finance acts on it"),
      { statusCode: 400, code: "reason_required" });
  }
  /* Covered and goodwill mean Woodex pays. Not covered means the customer does.
     Recording the cost only where Woodex bears it keeps the support P&L honest. */
  let cost = b.costToWooodex === undefined || b.costToWooodex === null ? null : Number(b.costToWooodex);
  if (cost !== null && (!Number.isFinite(cost) || cost < 0)) {
    throw Object.assign(new Error("cost must be zero or more"), { statusCode: 400 });
  }
  const decidedOn = String(b.decidedOn ?? new Date().toISOString().slice(0, 10));
  await db.prepare(
    "UPDATE warranty_claims SET verdict = ?, decided_on = ?, decided_by = ?, reason = ?, cost_to_woodex = ?, updated_at = ? WHERE id = ?"
  ).run(verdict, decidedOn, req.user?.name ?? "system", reason, cost, now(), row.id);
  const after = rowWarrantyClaim(await db.prepare("SELECT * FROM warranty_claims WHERE id = ?").get(row.id));
  await writeAudit(req, { action: "warranty_claim.decided", entityType: "warranty_claim", entityId: row.id,
    before: rowWarrantyClaim(row), after });
  await writeActivity({ entityType: "warranty_claim", entityId: row.id, kind: "status_change",
    summary: `Claim ${after.ref} decided: ${verdict} — ${reason}`, actor: req.user?.name ?? "system" });
  return { claim: after };
}));

/* Throttle housekeeping and visibility. An operator asking "why is this person
   locked out" needs the buckets by key, not a log line — and the sweep needs a
   way to run on a serverless platform, where no timer survives between
   requests. */
app.get("/api/ops/throttle", wrap(async (req, res) => {
  const where = [];
  const params = [];
  if (req.query.locked === "1") { where.push("locked_until IS NOT NULL AND locked_until > ?"); params.push(now()); }
  if (req.query.kind) { where.push("kind = ?"); params.push(String(req.query.kind)); }
  const rows = await db.prepare(
    `SELECT * FROM throttle_buckets ${where.length ? "WHERE " + where.join(" AND ") : ""}
      ORDER BY last_seen_at DESC LIMIT 200`
  ).all(...params);
  const t = Date.now();
  const items = rows.map((r) => {
    const lockedUntil = r.locked_until ? Date.parse(String(r.locked_until)) : 0;
    return {
      key: r.bucket_key, kind: r.kind, failures: Number(r.failures),
      windowStartedAt: r.window_started_at, lastSeenAt: r.last_seen_at,
      locked: lockedUntil > t, retryAfterSeconds: lockedUntil > t ? Math.ceil((lockedUntil - t) / 1000) : 0,
    };
  });
  res.json({ total: items.length, locked: items.filter((i) => i.locked).length, items });
}));

/* Clear a lock deliberately — the person is standing in front of you and has
   proved who they are. Recording it, because lifting a security control by hand
   is exactly the kind of act that must not be invisible afterwards. */
app.delete("/api/ops/throttle/:key", wrapTxJson(async (req, res) => {
  const key = decodeURIComponent(req.params.key);
  const row = await db.prepare("SELECT * FROM throttle_buckets WHERE bucket_key = ?").get(key);
  if (!row) throw Object.assign(new Error("no such bucket: " + key), { statusCode: 404 });
  await db.prepare("DELETE FROM throttle_buckets WHERE bucket_key = ?").run(key);
  await writeAudit(req, { action: "throttle.cleared", entityType: "throttle_bucket", entityId: key,
    before: { failures: row.failures, lockedUntil: row.locked_until } });
  await writeActivity({ entityType: "throttle_bucket", entityId: key, kind: "status_change",
    summary: `Login lock cleared for ${key} by hand`, actor: req.user?.name ?? "system" });
  return { ok: true, cleared: key };
}));

/* Run the sweep on demand. Serverless has no timer that outlives a request, so
   the housekeeping is callable as well as scheduled. */
app.post("/api/ops/throttle/sweep", wrapTxJson(async (req, res) => {
  const before = await db.prepare("SELECT COUNT(*) AS n FROM throttle_buckets").get();
  await throttleSweep();
  const after = await db.prepare("SELECT COUNT(*) AS n FROM throttle_buckets").get();
  const removed = Number(before?.n ?? 0) - Number(after?.n ?? 0);
  await writeAudit(req, { action: "throttle.swept", entityType: "throttle_bucket", entityId: "all",
    after: { removed, remaining: Number(after?.n ?? 0) } });
  return { removed, remaining: Number(after?.n ?? 0) };
}));

/* Support in one number: what is open, what is late, and what the warranty
   decisions have cost. This is the page a manager reads on Monday. */
app.get("/api/support/summary", wrap(async (req, res) => {
  const nowIso = now();
  const today = nowIso.slice(0, 10);
  const open = (await db.prepare("SELECT * FROM tickets WHERE status IN ('open','in_progress','waiting')").all()).map(rowTicket);
  const claims = await db.prepare("SELECT * FROM warranty_claims").all();
  const visits = await db.prepare("SELECT status, COUNT(*) AS n FROM service_visits GROUP BY status").all();
  const expired = await db.prepare(
    "SELECT COUNT(*) AS n FROM installed_assets WHERE warranty_until IS NOT NULL AND warranty_until < ?"
  ).get(today);
  res.json({
    openTickets: open.length,
    responseBreached: open.filter((t) => t.responseBreached).length,
    resolutionBreached: open.filter((t) => t.resolutionBreached).length,
    waitingOnCustomer: open.filter((t) => t.waitingOn === "them").length,
    unclaimedDecisions: claims.filter((c) => String(c.verdict) === "pending").length,
    coveredCost: claims.filter((c) => ["covered", "goodwill"].includes(String(c.verdict)))
      .reduce((a, c) => a + Number(c.cost_to_woodex ?? 0), 0),
    visitsByStatus: visits.reduce((acc, v) => ({ ...acc, [String(v.status)]: Number(v.n) }), {}),
    assetsOutOfWarranty: Number(expired?.n ?? 0),
  });
}));

/* ══════════════════════════════ finance (013) ══════════════════════════════
   A double-entry journal, plus the documents that post into it.

   The one rule: every financial event enters through postJournal(), which
   refuses an entry whose debits do not equal its credits, before writing
   anything. The database enforces the same rule again with a deferred
   constraint trigger — not because the helper might be bypassed, but because a
   rule that lives only in application code is a convention, and conventions
   lose to a repair script at 2am.

   Immutability is a mechanism here, not a promise: migration 013 installs
   triggers that refuse UPDATE and DELETE on journal_lines, so a correction must
   be a reversing entry. The API offers exactly that and nothing else. */

const ACCOUNT_KINDS = new Set(["asset", "liability", "equity", "revenue", "expense"]);

const rowAccount = (r) => ({
  id: r.id, code: r.code, name: r.name, kind: r.kind, active: !!r.active, notes: r.notes,
  /* Debit-normal vs credit-normal, computed here rather than remembered by each
     caller. A reader should not have to know that expenses are debit-normal to
     check a sign on a report. */
  normalSide: ["asset", "expense"].includes(r.kind) ? "debit" : "credit",
});
const rowJournalLine = (r) => ({
  id: r.id, entryId: r.entry_id, accountId: r.account_id, accountCode: r.account_code ?? null,
  accountName: r.account_name ?? null, debit: Number(r.debit), credit: Number(r.credit),
  description: r.description,
});
const rowJournalEntry = (r) => ({
  id: r.id, ref: r.ref, entryDate: r.entry_date, memo: r.memo,
  sourceType: r.source_type, sourceId: r.source_id, reversesId: r.reverses_id,
  postedBy: r.posted_by, createdAt: r.created_at,
});
const rowExpense = (r) => ({
  id: r.id, ref: r.ref, category: r.category, payee: r.payee, amount: Number(r.amount),
  taxAmount: Number(r.tax_amount), spentAt: r.spent_at, method: r.method, reference: r.reference,
  accountId: r.account_id, journalEntryId: r.journal_entry_id, note: r.note, by: r.created_by,
  createdAt: r.created_at,
});
const rowCreditNote = (r) => ({
  id: r.id, ref: r.ref, invoiceId: r.invoice_id, customer: r.customer, reason: r.reason,
  subtotal: Number(r.subtotal), tax: Number(r.tax), total: Number(r.total), status: r.status,
  issuedAt: r.issued_at, appliedAt: r.applied_at, journalEntryId: r.journal_entry_id,
  note: r.note, createdAt: r.created_at, updatedAt: r.updated_at,
});

const accountByCode = async (code) => db.prepare("SELECT * FROM accounts WHERE code = ? AND active = 1").get(String(code));

/* ─────────────────────── the posting engine ───────────────────────
   Callers pass account CODES, not ids: a code is stable and legible, and a
   route that says '1100' can be read by an accountant without a join. An
   unknown or inactive code is a 400 that names it, not a silent skip. */
const postJournal = async ({ entryDate, memo, sourceType = null, sourceId = null,
  lines, actor = "system", refPrefix = "JE-", reversesId = null }) => {
  if (!Array.isArray(lines) || lines.length < 2) {
    throw Object.assign(new Error("a journal entry needs at least two lines"), { statusCode: 400, code: "needs_two_lines" });
  }
  let debit = 0;
  let credit = 0;
  const resolved = [];
  for (const line of lines) {
    const code = String(line.account ?? line.accountCode ?? "").trim();
    if (!code) throw Object.assign(new Error("every journal line needs an account code"), { statusCode: 400 });
    const account = await accountByCode(code);
    if (!account) {
      throw Object.assign(new Error(`account ${code} does not exist or is inactive`), { statusCode: 400, code: "unknown_account", account: code });
    }
    const d = Math.round(Number(line.debit ?? 0) * 100) / 100;
    const c = Math.round(Number(line.credit ?? 0) * 100) / 100;
    /* Exactly one side per line. The database shares this CHECK; catching it
       here produces a sentence naming the account instead of a driver error. */
    const oneSide = (d > 0 && c === 0) || (c > 0 && d === 0);
    if (!oneSide) {
      throw Object.assign(new Error(`line on account ${code} must have exactly one of debit or credit, greater than zero`),
        { statusCode: 400, code: "one_side_per_line" });
    }
    debit += d; credit += c;
    resolved.push({ accountId: account.id, debit: d, credit: c, description: line.description ?? null });
  }
  /* Rounded to paisa before comparison: accumulating floats means a correct
     entry of 333.33 + 333.33 + 333.34 can miss equality by 1e-13, and refusing
     that would be a bug in the check rather than in the caller. */
  const dr = Math.round(debit * 100) / 100;
  const cr = Math.round(credit * 100) / 100;
  if (dr !== cr) {
    throw Object.assign(new Error(`entry does not balance: debits ${dr} vs credits ${cr}`),
      { statusCode: 422, code: "unbalanced", debit: dr, credit: cr });
  }
  if (dr === 0) throw Object.assign(new Error("an entry of zero is not a transaction"), { statusCode: 400, code: "zero_entry" });

  const ref = await nextRef("journal_entries", refPrefix, 1000);
  const t = now();
  const info = await db.prepare(
    `INSERT INTO journal_entries(ref, entry_date, memo, source_type, source_id, reverses_id, posted_by, created_at)
     VALUES(?,?,?,?,?,?,?,?)`
  ).run(ref, String(entryDate).slice(0, 10), memo ?? null, sourceType, sourceId, reversesId, String(actor).slice(0, 80), t);
  const entryId = Number(info.lastInsertRowid);
  for (const line of resolved) {
    await db.prepare("INSERT INTO journal_lines(entry_id, account_id, debit, credit, description) VALUES(?,?,?,?,?)")
      .run(entryId, line.accountId, line.debit, line.credit, line.description);
  }
  return { id: entryId, ref, debit: dr, credit: cr };
};

const journalEntryFull = async (id) => {
  const row = await db.prepare("SELECT * FROM journal_entries WHERE id = ?").get(id);
  if (!row) return null;
  const lines = (await db.prepare(
    `SELECT l.*, a.code AS account_code, a.name AS account_name
       FROM journal_lines l JOIN accounts a ON a.id = l.account_id
      WHERE l.entry_id = ? ORDER BY l.id`
  ).all(id)).map(rowJournalLine);
  return {
    ...rowJournalEntry(row), lines,
    totalDebit: lines.reduce((a, l) => a + l.debit, 0),
    totalCredit: lines.reduce((a, l) => a + l.credit, 0),
    balanced: Math.round(lines.reduce((a, l) => a + l.debit, 0) * 100) === Math.round(lines.reduce((a, l) => a + l.credit, 0) * 100),
  };
};

/* ─────────────────────────── chart of accounts ─────────────────────────── */

app.get("/api/accounts", wrap(async (req, res) => {
  let rows = await db.prepare("SELECT * FROM accounts ORDER BY code").all();
  if (req.query.active !== undefined) {
    const want = ["1", "true", "yes"].includes(String(req.query.active).toLowerCase());
    rows = rows.filter((a) => !!a.active === want);
  }
  if (req.query.kind) rows = rows.filter((a) => a.kind === String(req.query.kind));
  res.json({ total: rows.length, kinds: [...ACCOUNT_KINDS], items: rows.map(rowAccount) });
}));

app.post("/api/accounts", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const code = String(b.code ?? "").trim();
  const name = String(b.name ?? "").trim();
  const kind = String(b.kind ?? "").trim().toLowerCase();
  if (!code || !name) throw Object.assign(new Error("code and name are required"), { statusCode: 400 });
  if (!ACCOUNT_KINDS.has(kind)) {
    throw Object.assign(new Error(`kind must be one of ${[...ACCOUNT_KINDS].join(", ")}`), { statusCode: 400, code: "bad_kind" });
  }
  const dupe = await db.prepare("SELECT id FROM accounts WHERE code = ?").get(code);
  if (dupe) throw Object.assign(new Error(`account ${code} already exists`), { statusCode: 409, code: "account_exists" });
  const t = now();
  const info = await db.prepare(
    "INSERT INTO accounts(code, name, kind, active, notes, created_at, updated_at) VALUES(?,?,?,1,?,?,?)"
  ).run(code, name, kind, b.notes ?? null, t, t);
  const created = rowAccount(await db.prepare("SELECT * FROM accounts WHERE id = ?").get(info.lastInsertRowid));
  await writeAudit(req, { action: "account.created", entityType: "account", entityId: created.id, after: created });
  return { account: created };
}));

app.patch("/api/accounts/:id", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const row = await db.prepare("SELECT * FROM accounts WHERE id = ?").get(req.params.id);
  if (!row) throw Object.assign(new Error("account not found"), { statusCode: 404 });
  const before = rowAccount(row);
  const sets = [];
  const params = [];
  if (b.name !== undefined) { sets.push("name = ?"); params.push(String(b.name).trim()); }
  if (b.notes !== undefined) { sets.push("notes = ?"); params.push(b.notes); }
  /* `kind` is deliberately NOT editable. It decides which side a balance sits
     on, so changing it silently rewrites every historical report that included
     the account. Deactivate and create a correct one instead. */
  if (b.kind !== undefined && String(b.kind) !== row.kind) {
    throw Object.assign(new Error("an account's kind cannot be changed — deactivate it and create a new account"),
      { statusCode: 409, code: "kind_immutable" });
  }
  if (b.active !== undefined) { sets.push("active = ?"); params.push(b.active === true ? 1 : 0); }
  if (!sets.length) throw Object.assign(new Error("nothing to update"), { statusCode: 400 });
  sets.push("updated_at = ?"); params.push(now(), row.id);
  await db.prepare(`UPDATE accounts SET ${sets.join(", ")} WHERE id = ?`).run(...params);
  const after = rowAccount(await db.prepare("SELECT * FROM accounts WHERE id = ?").get(row.id));
  await writeAudit(req, { action: "account.updated", entityType: "account", entityId: row.id, before, after });
  return { account: after };
}));

/* ──────────────────────────── journal ──────────────────────────── */

app.get("/api/journal", wrap(async (req, res) => {
  const where = [];
  const params = [];
  if (req.query.source_type) { where.push("source_type = ?"); params.push(String(req.query.source_type)); }
  if (req.query.source_id) { where.push("source_id = ?"); params.push(Number(req.query.source_id)); }
  if (req.query.from) { where.push("entry_date >= ?"); params.push(String(req.query.from)); }
  if (req.query.to) { where.push("entry_date <= ?"); params.push(String(req.query.to)); }
  const limit = Math.min(500, Math.max(1, Number(req.query.limit) || 100));
  const rows = await db.prepare(
    `SELECT * FROM journal_entries ${where.length ? "WHERE " + where.join(" AND ") : ""} ORDER BY entry_date DESC, id DESC LIMIT ?`
  ).all(...params, limit);
  const items = [];
  for (const r of rows) items.push(await journalEntryFull(r.id));
  res.json({ total: items.length, items });
}));

app.get("/api/journal/:id", wrap(async (req, res) => {
  const entry = await journalEntryFull(req.params.id);
  if (!entry) throw Object.assign(new Error("journal entry not found"), { statusCode: 404 });
  res.json({ entry });
}));

/* A manual entry. Most entries are posted by a document route; this exists for
   opening balances, corrections and anything the business does that has no
   document in this system. */
app.post("/api/journal", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  if (!b.entryDate && !b.entry_date) throw Object.assign(new Error("entryDate is required"), { statusCode: 400 });
  const posted = await postJournal({
    entryDate: b.entryDate ?? b.entry_date,
    memo: b.memo ?? null,
    sourceType: b.sourceType ?? b.source_type ?? "manual",
    sourceId: b.sourceId ?? b.source_id ?? null,
    lines: b.lines ?? [],
    actor: req.user?.name ?? req.actor ?? "system",
  });
  const entry = await journalEntryFull(posted.id);
  await writeAudit(req, { action: "journal.posted", entityType: "journal_entry", entityId: posted.id, after: entry });
  return { entry };
}));

/* Reversal — the ONLY correction this system offers, and the reason
   journal_lines can be immutable. The reversing entry mirrors every line, points
   at the original by id, and carries its own author and date. Both stay. */
app.post("/api/journal/:id/reverse", wrapTxJson(async (req, res) => {
  const original = await journalEntryFull(req.params.id);
  if (!original) throw Object.assign(new Error("journal entry not found"), { statusCode: 404 });
  const already = await db.prepare("SELECT id FROM journal_entries WHERE reverses_id = ?").get(original.id);
  if (already) {
    throw Object.assign(new Error(`entry ${original.ref} has already been reversed by ${already.id}`),
      { statusCode: 409, code: "already_reversed", reversalId: already.id });
  }
  const posted = await postJournal({
    entryDate: req.body?.entryDate ?? original.entryDate,
    memo: `Reversal of ${original.ref}${original.memo ? " — " + original.memo : ""}`,
    sourceType: "reversal",
    sourceId: original.id,
    reversesId: original.id,
    /* Debit and credit swap. This is the whole operation. */
    lines: original.lines.map((l) => ({
      account: l.accountCode, debit: l.credit, credit: l.debit, description: `reverses line ${l.id}`,
    })),
    actor: req.user?.name ?? req.actor ?? "system",
  });
  const entry = await journalEntryFull(posted.id);
  await writeAudit(req, {
    action: "journal.reversed", entityType: "journal_entry", entityId: original.id,
    before: original, after: entry,
  });
  return { entry, reverses: original.id };
}));

/* Trial balance: every account with its debit/credit totals over a date range.
   `balanced` is computed from the totals, not assumed — a report that prints a
   grand total of zero because it added two unequal columns is worse than none. */
app.get("/api/finance/trial-balance", wrap(async (req, res) => {
  const from = req.query.from ? String(req.query.from) : null;
  const to = req.query.to ? String(req.query.to) : null;
  const where = [];
  const params = [];
  if (from) { where.push("e.entry_date >= ?"); params.push(from); }
  if (to) { where.push("e.entry_date <= ?"); params.push(to); }
  const rows = await db.prepare(
    `SELECT a.id, a.code, a.name, a.kind,
            COALESCE(SUM(l.debit),0) AS debit, COALESCE(SUM(l.credit),0) AS credit
       FROM accounts a
       LEFT JOIN journal_lines l ON l.account_id = a.id
       LEFT JOIN journal_entries e ON e.id = l.entry_id
      ${where.length ? "WHERE " + where.map((w) => w.replace(/^/, "(")).join(" AND ") + " OR l.id IS NULL)" : ""}
      GROUP BY a.id, a.code, a.name, a.kind
      ORDER BY a.code`
  ).all(...params);
  const items = rows.map((r) => {
    const debit = Number(r.debit);
    const credit = Number(r.credit);
    /* Normal balance sign: an asset showing more credits than debits is worth
       showing as negative rather than as an inverted positive, because that is
       what it is. */
    const normalSide = ["asset", "expense"].includes(r.kind) ? "debit" : "credit";
    const balance = normalSide === "debit" ? debit - credit : credit - debit;
    return { ...rowAccount(r), debit, credit, balance, normalSide };
  });
  const totalDebit = Math.round(items.reduce((a, i) => a + i.debit, 0) * 100) / 100;
  const totalCredit = Math.round(items.reduce((a, i) => a + i.credit, 0) * 100) / 100;
  res.json({
    from, to, items,
    totalDebit, totalCredit,
    balanced: totalDebit === totalCredit,
    difference: Math.round((totalDebit - totalCredit) * 100) / 100,
    entries: Number((await db.prepare("SELECT COUNT(*) AS n FROM journal_entries").get())?.n ?? 0),
  });
}));

/* ──────────────────────────── expenses ──────────────────────────── */

app.get("/api/expenses", wrap(async (req, res) => {
  const where = [];
  const params = [];
  if (req.query.category) { where.push("category = ?"); params.push(String(req.query.category)); }
  if (req.query.from) { where.push("spent_at >= ?"); params.push(String(req.query.from)); }
  if (req.query.to) { where.push("spent_at <= ?"); params.push(String(req.query.to)); }
  const rows = await db.prepare(
    `SELECT * FROM expenses ${where.length ? "WHERE " + where.join(" AND ") : ""} ORDER BY spent_at DESC, id DESC LIMIT 500`
  ).all(...params);
  const items = rows.map(rowExpense);
  const cats = new Map();
  for (const e of items) cats.set(e.category, (cats.get(e.category) ?? 0) + e.amount);
  res.json({
    total: items.length,
    totalAmount: Math.round(items.reduce((a, e) => a + e.amount, 0) * 100) / 100,
    totalTax: Math.round(items.reduce((a, e) => a + e.taxAmount, 0) * 100) / 100,
    byCategory: [...cats].map(([category, amount]) => ({ category, amount })).sort((a, b) => b.amount - a.amount),
    items,
  });
}));

/* An expense posts to the journal in the same transaction as the row. Two
   separate writes would allow an expense that exists and is not in the books,
   which is precisely the state this module was built to make impossible. */
app.post("/api/expenses", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const category = String(b.category ?? "").trim();
  const amount = Number(b.amount);
  if (!category) throw Object.assign(new Error("category is required"), { statusCode: 400 });
  if (!(amount > 0)) throw Object.assign(new Error("amount must be greater than zero"), { statusCode: 400 });
  const spentAt = String(b.spentAt ?? b.spent_at ?? now()).slice(0, 10);
  const taxAmount = Math.max(0, Number(b.taxAmount ?? b.tax_amount ?? 0) || 0);
  /* The payable account is configurable because a business paid from cash and a
     business paid from the bank are both correct; defaulting to the bank and
     saying so beats guessing per row. */
  const paidFrom = String(b.paidFrom ?? b.paid_from ?? "1010");
  const paidFromAccount = await accountByCode(paidFrom);
  if (!paidFromAccount) throw Object.assign(new Error(`payment account ${paidFrom} not found`), { statusCode: 400 });

  const ref = await nextRef("expenses", "EXP-", 1000);
  const t = now();
  const info = await db.prepare(
    `INSERT INTO expenses(ref, category, payee, amount, tax_amount, spent_at, method, reference, account_id, note, created_by, created_at, updated_at)
     VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)`
  ).run(ref, category, b.payee ?? null, amount, taxAmount, spentAt, b.method ?? null, b.reference ?? null,
    paidFromAccount.id, b.note ?? null, req.user?.name ?? req.actor ?? "system", t, t);
  const expenseId = Number(info.lastInsertRowid);

  /* Debit the expense, debit any recoverable tax, credit the account it was
     paid from. Getting the tax line wrong understates recoverable input tax,
     which is money the business loses quietly. */
  const lines = [{ account: String(b.expenseAccount ?? "6900"), debit: amount, description: category }];
  if (taxAmount > 0) lines.push({ account: "2100", debit: taxAmount, description: "Input tax" });
  lines.push({ account: paidFrom, credit: Math.round((amount + taxAmount) * 100) / 100, description: `Paid for ${category}` });
  const posted = await postJournal({
    entryDate: spentAt, memo: `${ref} · ${category}${b.payee ? " · " + b.payee : ""}`,
    sourceType: "expense", sourceId: expenseId, lines,
    actor: req.user?.name ?? req.actor ?? "system", refPrefix: "JE-EXP-",
  });
  await db.prepare("UPDATE expenses SET journal_entry_id = ? WHERE id = ?").run(posted.id, expenseId);

  const created = rowExpense(await db.prepare("SELECT * FROM expenses WHERE id = ?").get(expenseId));
  await writeAudit(req, { action: "expense.recorded", entityType: "expense", entityId: expenseId, after: created });
  return { expense: created, journal: posted };
}));

/* ─────────────────────────── credit notes ─────────────────────────── */

app.get("/api/credit-notes", wrap(async (req, res) => {
  const where = [];
  const params = [];
  if (req.query.status) { where.push("status = ?"); params.push(String(req.query.status)); }
  if (req.query.invoice_id) { where.push("invoice_id = ?"); params.push(Number(req.query.invoice_id)); }
  const rows = await db.prepare(
    `SELECT * FROM credit_notes ${where.length ? "WHERE " + where.join(" AND ") : ""} ORDER BY id DESC LIMIT 300`
  ).all(...params);
  const items = rows.map(rowCreditNote);
  res.json({ total: items.length, totalAmount: Math.round(items.reduce((a, c) => a + c.total, 0) * 100) / 100, items });
}));

app.post("/api/credit-notes", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const customer = String(b.customer ?? "").trim();
  const subtotal = Number(b.subtotal ?? b.amount ?? 0);
  const tax = Number(b.tax ?? 0);
  if (!customer) throw Object.assign(new Error("customer is required"), { statusCode: 400 });
  if (!(subtotal > 0)) throw Object.assign(new Error("subtotal must be greater than zero"), { statusCode: 400 });
  if (tax < 0) throw Object.assign(new Error("tax cannot be negative"), { statusCode: 400 });
  let invoiceId = b.invoiceId ?? b.invoice_id ?? null;
  if (invoiceId !== null && invoiceId !== undefined) {
    const inv = await db.prepare("SELECT id FROM invoices WHERE id = ?").get(Number(invoiceId));
    if (!inv) throw Object.assign(new Error("invoice not found: " + invoiceId), { statusCode: 404 });
    invoiceId = inv.id;
  }
  const total = Math.round((subtotal + tax) * 100) / 100;
  const ref = await nextRef("credit_notes", "CN-", 1000);
  const t = now();
  const info = await db.prepare(
    `INSERT INTO credit_notes(ref, invoice_id, customer, reason, subtotal, tax, total, status, note, created_at, updated_at)
     VALUES(?,?,?,?,?,?,?,'draft',?,?,?)`
  ).run(ref, invoiceId, customer, b.reason ?? null, subtotal, tax, total, b.note ?? null, t, t);
  const created = rowCreditNote(await db.prepare("SELECT * FROM credit_notes WHERE id = ?").get(info.lastInsertRowid));
  await writeAudit(req, { action: "credit_note.created", entityType: "credit_note", entityId: created.id, after: created });
  return { creditNote: created };
}));

/* Issuing is the point money stops being owed, so it posts: debit the returns
   account (contra revenue), credit receivables. A draft is just a document. */
app.patch("/api/credit-notes/:id", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const row = await db.prepare("SELECT * FROM credit_notes WHERE id = ?").get(req.params.id);
  if (!row) throw Object.assign(new Error("credit note not found"), { statusCode: 404 });
  const before = rowCreditNote(row);
  const want = String(b.status ?? "").trim().toLowerCase();
  if (!["issued", "applied", "cancelled"].includes(want)) {
    throw Object.assign(new Error("status must be issued, applied or cancelled"), { statusCode: 400 });
  }
  if (row.status !== "draft") {
    throw Object.assign(new Error(`this credit note is already ${row.status}`), { statusCode: 409, code: "already_settled" });
  }
  const t = now();
  let journalEntryId = row.journal_entry_id;

  if (want !== "cancelled") {
    const total = Number(row.total);
    const lines = [
      { account: "4900", debit: Number(row.subtotal), description: `Credit note ${row.ref}` },
    ];
    if (Number(row.tax) > 0) lines.push({ account: "2100", debit: Number(row.tax), description: "Tax reversed" });
    lines.push({ account: "1100", credit: total, description: `Credit for ${row.customer}` });
    const posted = await postJournal({
      entryDate: t.slice(0, 10), memo: `${row.ref} · ${row.reason ?? "credit"} · ${row.customer}`,
      sourceType: "credit_note", sourceId: row.id, lines,
      actor: req.user?.name ?? req.actor ?? "system", refPrefix: "JE-CN-",
    });
    journalEntryId = posted.id;
  }

  await db.prepare(
    `UPDATE credit_notes SET status = ?, issued_at = ?, applied_at = ?, journal_entry_id = ?, updated_at = ? WHERE id = ?`
  /* Normalise the carried-over timestamps. Postgres returns a Date object for a
     timestamptz column and passing it straight back serialises as a
     non-ISO string that Postgres rejects (4276) while SQLite accepts it. This
     survived only because the columns are usually null; a credit note moved to
     "applied" after "issued" would have thrown on Postgres and nowhere else. */
  ).run(want, want === "issued" || want === "applied" ? t : (row.issued_at ? new Date(row.issued_at).toISOString() : null),
    want === "applied" ? t : (row.applied_at ? new Date(row.applied_at).toISOString() : null),
    journalEntryId, t, row.id);
  const after = rowCreditNote(await db.prepare("SELECT * FROM credit_notes WHERE id = ?").get(row.id));
  await writeAudit(req, { action: `credit_note.${want}`, entityType: "credit_note", entityId: row.id, before, after });
  return { creditNote: after };
}));

/* ─────────────────────── tax records + schedules ─────────────────────── */

app.get("/api/tax-records", wrap(async (req, res) => {
  const where = [];
  const params = [];
  if (req.query.period) { where.push("tax_period = ?"); params.push(String(req.query.period)); }
  if (req.query.kind) { where.push("kind = ?"); params.push(String(req.query.kind)); }
  const rows = await db.prepare(
    `SELECT * FROM tax_records ${where.length ? "WHERE " + where.join(" AND ") : ""} ORDER BY tax_period DESC, id DESC LIMIT 500`
  ).all(...params);
  const items = rows.map((r) => ({
    id: r.id, kind: r.kind, sourceType: r.source_type, sourceId: r.source_id,
    taxPeriod: r.tax_period, rate: Number(r.rate), taxableBase: Number(r.taxable_base),
    taxAmount: Number(r.tax_amount), createdAt: r.created_at,
  }));
  const byPeriod = new Map();
  for (const i of items) byPeriod.set(i.taxPeriod, (byPeriod.get(i.taxPeriod) ?? 0) + i.taxAmount);
  res.json({
    total: items.length,
    totalTax: Math.round(items.reduce((a, i) => a + i.taxAmount, 0) * 100) / 100,
    byPeriod: [...byPeriod].map(([taxPeriod, taxAmount]) => ({ taxPeriod, taxAmount: Math.round(taxAmount * 100) / 100 })).sort((a, b) => b.taxPeriod.localeCompare(a.taxPeriod)),
    items,
  });
}));

app.get("/api/payment-schedules", wrap(async (req, res) => {
  const where = [];
  const params = [];
  if (req.query.invoice_id) { where.push("invoice_id = ?"); params.push(Number(req.query.invoice_id)); }
  if (req.query.status) { where.push("status = ?"); params.push(String(req.query.status)); }
  const rows = await db.prepare(
    `SELECT * FROM payment_schedules ${where.length ? "WHERE " + where.join(" AND ") : ""} ORDER BY due_date, id LIMIT 500`
  ).all(...params);
  const today = now().slice(0, 10);
  const items = rows.map((r) => ({
    id: r.id, invoiceId: r.invoice_id, seq: r.seq, dueDate: r.due_date,
    amount: Number(r.amount), paid: Number(r.paid),
    outstanding: Math.round((Number(r.amount) - Number(r.paid)) * 100) / 100,
    status: r.status, note: r.note, createdAt: r.created_at, updatedAt: r.updated_at,
    /* "Late" is derived from the due date and what has been paid, never stored:
       a stored flag would need a nightly job to stay true. */
    overdue: r.status === "pending" && r.due_date < today,
  }));
  res.json({
    total: items.length,
    outstanding: Math.round(items.filter((i) => i.status === "pending").reduce((a, i) => a + i.outstanding, 0) * 100) / 100,
    overdue: items.filter((i) => i.overdue).length,
    items,
  });
}));

app.post("/api/payment-schedules", wrapTxJson(async (req, res) => {
  const b = req.body ?? {};
  const invoiceId = Number(b.invoiceId ?? b.invoice_id);
  if (!Number.isInteger(invoiceId)) throw Object.assign(new Error("invoiceId is required"), { statusCode: 400 });
  const inv = await db.prepare("SELECT * FROM invoices WHERE id = ?").get(invoiceId);
  if (!inv) throw Object.assign(new Error("invoice not found: " + invoiceId), { statusCode: 404 });
  const instalments = Array.isArray(b.instalments ?? b.items) ? (b.instalments ?? b.items) : [];
  if (!instalments.length) throw Object.assign(new Error("at least one instalment is required"), { statusCode: 400 });
  const t = now();
  let seq = Number((await db.prepare("SELECT COALESCE(MAX(seq),0) AS n FROM payment_schedules WHERE invoice_id = ?").get(invoiceId))?.n ?? 0);
  const created = [];
  for (const inst of instalments) {
    const amount = Number(inst.amount);
    const dueDate = String(inst.dueDate ?? inst.due_date ?? "").slice(0, 10);
    if (!(amount > 0)) throw Object.assign(new Error("each instalment needs an amount greater than zero"), { statusCode: 400 });
    if (!dueDate) throw Object.assign(new Error("each instalment needs a due date"), { statusCode: 400 });
    seq += 1;
    const info = await db.prepare(
      "INSERT INTO payment_schedules(invoice_id, seq, due_date, amount, paid, status, note, created_at, updated_at) VALUES(?,?,?,?,0,'pending',?,?,?)"
    ).run(invoiceId, seq, dueDate, amount, inst.note ?? null, t, t);
    created.push(info.lastInsertRowid);
  }
  await writeAudit(req, {
    action: "payment_schedule.created", entityType: "invoice", entityId: invoiceId,
    after: { instalments: created.length, amounts: instalments.map((i) => Number(i.amount)) },
  });
  return { created: created.length, invoiceId };
}));

/* ---------- suppliers ---------- */

app.get("/api/suppliers", wrap(async (req, res) => {
  let rows = (await db.prepare("SELECT * FROM suppliers ORDER BY active DESC, name").all()).map(rowSupplier);
  if (req.query.active !== undefined) {
    const want = ["1", "true", "yes"].includes(String(req.query.active).toLowerCase());
    rows = rows.filter((s) => s.active === want);
  }
  if (req.query.q) {
    const q = String(req.query.q).toLowerCase();
    rows = rows.filter((s) => [s.code, s.name, s.email, s.taxRef].some((v) => String(v ?? "").toLowerCase().includes(q)));
  }
  res.json({ total: rows.length, active: rows.filter((s) => s.active).length, items: rows });
}));

app.post("/api/suppliers", wrapTxJson(async (req) => {
  const b = req.body ?? {};
  const code = String(b.code ?? "").trim();
  const name = String(b.name ?? "").trim();
  if (!code || !name) return { status: 400, body: { error: "code and name are required" } };
  if (await db.prepare("SELECT 1 AS x FROM suppliers WHERE code=?").get(code))
    return { status: 409, body: { error: `supplier code '${code}' already exists` } };
  const t = now();
  const info = await db.prepare(
    `INSERT INTO suppliers(code,name,contact_name,phone,email,address,tax_ref,payment_terms,currency,active,notes,created_at,updated_at)
     VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)`
  ).run(code, name, b.contactName ?? b.contact_name ?? null, b.phone ?? null, b.email ?? null,
    b.address ?? null, b.taxRef ?? b.tax_ref ?? null, b.paymentTerms ?? b.payment_terms ?? null,
    b.currency ?? "PKR", b.active === undefined ? 1 : (b.active ? 1 : 0), b.notes ?? null, t, t);
  const out = rowSupplier(await supplierById(info.lastInsertRowid));
  await writeAudit(req, { action: "supplier.created", entityType: "supplier", entityId: out.id, after: out });
  return { status: 201, body: out };
}));

app.patch("/api/suppliers/:id", wrapTxJson(async (req) => {
  const r = await supplierById(req.params.id);
  if (!r) return { status: 404, body: { error: "not found" } };
  const b = req.body ?? {};
  const before = rowSupplier(r);
  /* A supplier cannot be renamed to a code that already exists. The UNIQUE in
     the schema would reject it anyway; catching it here turns a 500 into a 409
     with a sentence. */
  const code = b.code !== undefined ? String(b.code).trim() : r.code;
  if (!code) return { status: 400, body: { error: "code cannot be empty" } };
  if (code !== r.code && await db.prepare("SELECT 1 AS x FROM suppliers WHERE code=? AND id<>?").get(code, r.id))
    return { status: 409, body: { error: `supplier code '${code}' already exists` } };
  await db.prepare(
    `UPDATE suppliers SET code=?,name=?,contact_name=?,phone=?,email=?,address=?,tax_ref=?,payment_terms=?,currency=?,active=?,notes=?,updated_at=? WHERE id=?`
  ).run(code, b.name !== undefined ? String(b.name).trim() : r.name,
    b.contactName ?? b.contact_name ?? r.contact_name, b.phone ?? r.phone, b.email ?? r.email,
    b.address ?? r.address, b.taxRef ?? b.tax_ref ?? r.tax_ref,
    b.paymentTerms ?? b.payment_terms ?? r.payment_terms, b.currency ?? r.currency,
    b.active !== undefined ? (b.active ? 1 : 0) : r.active, b.notes ?? r.notes, now(), r.id);
  const out = rowSupplier(await supplierById(r.id));
  await writeAudit(req, { action: "supplier.updated", entityType: "supplier", entityId: out.id, before, after: out });
  return { body: out };
}));

/* ---------- purchase orders ---------- */

app.get("/api/purchase-orders", wrap(async (req, res) => {
  let rows = await db.prepare("SELECT * FROM purchase_orders ORDER BY id DESC").all();
  if (req.query.status) {
    const wanted = String(req.query.status).split(",").map((s) => s.trim().toLowerCase());
    rows = rows.filter((r) => wanted.includes(r.status));
  }
  if (req.query.supplier_id) rows = rows.filter((r) => String(r.supplier_id) === String(req.query.supplier_id));
  const items = await Promise.all(rows.map(purchaseOrderFull));
  res.json({
    total: items.length,
    open: items.filter((p) => ["approved", "sent", "partially_received"].includes(p.status)).length,
    items,
  });
}));

app.get("/api/purchase-orders/:id", wrap(async (req, res) => {
  const r = await purchaseOrderById(req.params.id);
  if (!r) return res.status(404).json({ error: "not found" });
  res.json(await purchaseOrderFull(r));
}));

app.post("/api/purchase-orders", wrapTxJson(async (req) => {
  const b = req.body ?? {};
  const supplierId = b.supplierId ?? b.supplier_id;
  if (!supplierId) return { status: 400, body: { error: "supplierId is required" } };
  const supplier = await supplierById(supplierId);
  if (!supplier) return { status: 400, body: { error: "supplier not found" } };
  if (!supplier.active) return { status: 409, body: { error: "supplier is inactive" } };
  const lines = Array.isArray(b.lines) ? b.lines : [];
  if (!lines.length) return { status: 400, body: { error: "at least one line is required" } };

  /* Validate every line BEFORE inserting anything. A PO that half-exists because
     line 7 was malformed is worse than a rejected request: it has a ref, it
     shows in the list, and nobody knows whether it was ever real. */
  const seen = new Set();
  const prepared = [];
  for (const [i, line] of lines.entries()) {
    const kind = String(line.itemKind ?? line.item_kind ?? "product").toLowerCase();
    if (!["product", "material"].includes(kind))
      return { status: 400, body: { error: `line ${i + 1}: itemKind must be product or material` } };
    const itemId = String(line.itemId ?? line.item_id ?? "").trim();
    if (!itemId) return { status: 400, body: { error: `line ${i + 1}: itemId is required` } };
    /* The FK that cannot exist (see migration 009) is enforced here instead:
       the item must actually be in the table its kind names. */
    const exists = kind === "product"
      ? await db.prepare("SELECT 1 AS x FROM products WHERE id=?").get(itemId)
      : await db.prepare("SELECT 1 AS x FROM materials WHERE id=?").get(itemId);
    if (!exists) return { status: 400, body: { error: `line ${i + 1}: ${kind} '${itemId}' does not exist` } };
    const key = kind + ":" + itemId;
    if (seen.has(key)) return { status: 400, body: { error: `line ${i + 1}: ${kind} '${itemId}' appears twice — raise the quantity on one line instead` } };
    seen.add(key);
    const qty = Number(line.qtyOrdered ?? line.qty_ordered ?? line.qty);
    if (!Number.isInteger(qty) || qty <= 0)
      return { status: 400, body: { error: `line ${i + 1}: qtyOrdered must be a positive whole number` } };
    const cost = Number(line.unitCost ?? line.unit_cost ?? 0);
    if (!Number.isFinite(cost) || cost < 0)
      return { status: 400, body: { error: `line ${i + 1}: unitCost must be zero or more` } };
    prepared.push({ lineNo: i + 1, kind, itemId, description: line.description ?? null, qty, unit: line.unit ?? null, cost });
  }

  const t = now();
  const ref = await nextRef("purchase_orders", "PO-", 1000);
  const info = await db.prepare(
    `INSERT INTO purchase_orders(ref,supplier_id,status,currency,expected_at,note,created_by,created_at,updated_at)
     VALUES(?,?,?,?,?,?,?,?,?)`
  ).run(ref, supplier.id, "draft", b.currency ?? supplier.currency ?? "PKR",
    b.expectedAt ?? b.expected_at ?? null, b.note ?? null, req.actor ?? req.user?.name ?? "system", t, t);
  const poId = info.lastInsertRowid;
  for (const l of prepared) {
    await db.prepare(
      `INSERT INTO purchase_order_lines(purchase_order_id,line_no,item_kind,item_id,description,qty_ordered,unit,unit_cost)
       VALUES(?,?,?,?,?,?,?,?)`
    ).run(poId, l.lineNo, l.kind, l.itemId, l.description, l.qty, l.unit, l.cost);
  }
  const out = await purchaseOrderFull(await purchaseOrderById(poId));
  emit("purchase-orders", { title: `PO raised: ${out.ref}`, who: req.actor, context: supplier.name });
  await writeAudit(req, { action: "purchase_order.created", entityType: "purchase_order", entityId: poId, after: out });
  return { status: 201, body: out };
}));

/* Status transitions. Each is checked against the current state rather than
   trusted from the body, because "set status=cancelled" on a received order
   would otherwise silently erase the fact that goods arrived. */
app.patch("/api/purchase-orders/:id", wrapTxJson(async (req) => {
  const r = await purchaseOrderById(req.params.id);
  if (!r) return { status: 404, body: { error: "not found" } };
  const b = req.body ?? {};
  const before = await purchaseOrderFull(r);
  const t = now();

  if (b.status !== undefined) {
    const next = String(b.status).toLowerCase();
    if (!PO_STATUSES.has(next)) return { status: 400, body: { error: "status must be " + [...PO_STATUSES].join("|") } };
    if (next === r.status) return { status: 409, body: { error: `order is already ${next}` } };

    const allowed = {
      draft: ["approved", "cancelled"],
      approved: ["sent", "cancelled", "partially_received", "received"],
      sent: ["partially_received", "received", "cancelled"],
      partially_received: ["received"],
      received: [],
      cancelled: [],
    }[r.status] ?? [];
    if (!allowed.includes(next)) {
      return { status: 409, body: { error: `cannot move a ${r.status} order to ${next}`, allowed } };
    }
    /* Approval is the one transition that must be attributable: the schema
       refuses an issued order with no approved_at, and this supplies it. */
    if (next === "approved") {
      await db.prepare("UPDATE purchase_orders SET status=?, approved_by=?, approved_at=?, updated_at=? WHERE id=?")
        .run(next, req.actor ?? req.user?.name ?? "system", t, t, r.id);
    } else if (next === "sent") {
      await db.prepare("UPDATE purchase_orders SET status=?, sent_at=?, updated_at=? WHERE id=?").run(next, t, t, r.id);
    } else if (next === "received") {
      /* 'received' is earned, not asserted. A PO cannot be closed while lines
         are outstanding — that is how an order quietly stops being chased. */
      const full = await purchaseOrderFull(r);
      if (full.qtyOpen > 0)
        return { status: 409, body: { error: `${full.qtyOpen} unit(s) still outstanding across ${full.lines.filter((l) => l.qtyOpen > 0).length} line(s)`, qtyOpen: full.qtyOpen } };
      await db.prepare("UPDATE purchase_orders SET status=?, closed_at=?, updated_at=? WHERE id=?").run(next, t, t, r.id);
    } else if (next === "cancelled") {
      /* Cancelling after goods arrived is refused: the stock is real and the
         ledger already recorded it, so the order it arrived against must
         remain a fact. */
      const got = (await db.prepare("SELECT COALESCE(SUM(grl.qty_received),0) AS q FROM goods_receipt_lines grl JOIN goods_receipts gr ON gr.id=grl.goods_receipt_id WHERE gr.purchase_order_id=?").get(r.id))?.q ?? 0;
      if (Number(got) > 0) return { status: 409, body: { error: `cannot cancel: ${got} unit(s) already received against this order` } };
      await db.prepare("UPDATE purchase_orders SET status=?, closed_at=?, updated_at=? WHERE id=?").run(next, t, t, r.id);
    } else {
      await db.prepare("UPDATE purchase_orders SET status=?, updated_at=? WHERE id=?").run(next, t, r.id);
    }
  }

  if (b.expectedAt !== undefined || b.expected_at !== undefined) {
    await db.prepare("UPDATE purchase_orders SET expected_at=?, updated_at=? WHERE id=?")
      .run(b.expectedAt ?? b.expected_at, t, r.id);
  }
  if (b.note !== undefined) {
    await db.prepare("UPDATE purchase_orders SET note=?, updated_at=? WHERE id=?").run(b.note, t, r.id);
  }

  const out = await purchaseOrderFull(await purchaseOrderById(r.id));
  if (out.status !== before.status) {
    await writeAudit(req, {
      action: "purchase_order." + (out.status === "cancelled" ? "cancelled" : out.status === "approved" ? "approved" : "status_changed"),
      entityType: "purchase_order", entityId: out.id,
      before: { status: before.status }, after: { status: out.status },
    });
    emit("purchase-orders", { title: `PO ${out.ref}: ${before.status} → ${out.status}`, who: req.actor });
  }
  return { body: out };
}));

/* ---------- goods receipts ---------- */

app.get("/api/goods-receipts", wrap(async (req, res) => {
  let rows = await db.prepare("SELECT * FROM goods_receipts ORDER BY id DESC").all();
  if (req.query.purchase_order_id) rows = rows.filter((r) => String(r.purchase_order_id) === String(req.query.purchase_order_id));
  const items = [];
  for (const r of rows) {
    const lines = (await db.prepare(
      `SELECT grl.*, pol.item_kind, pol.item_id, pol.line_no
         FROM goods_receipt_lines grl JOIN purchase_order_lines pol ON pol.id=grl.purchase_order_line_id
        WHERE grl.goods_receipt_id=? ORDER BY pol.line_no`).all(r.id))
      .map((l) => ({ id: l.id, purchaseOrderLineId: l.purchase_order_line_id, qtyReceived: l.qty_received,
        itemKind: l.item_kind, itemId: l.item_id, lineNo: l.line_no }));
    items.push({ ...rowGoodsReceipt(r), lines });
  }
  res.json({ total: items.length, items });
}));

/* Receive one delivery against a purchase order.

   Everything happens in one transaction: the receipt, its lines, the stock
   movements it causes and the order's new status. A partial receipt that
   committed the document but not the stock would leave a delivery note
   claiming goods that the catalogue never received. */
app.post("/api/goods-receipts", wrapTxJson(async (req) => {
  const b = req.body ?? {};
  const poRef = b.purchaseOrderId ?? b.purchase_order_id ?? b.ref;
  if (!poRef) return { status: 400, body: { error: "purchaseOrderId is required" } };
  const po = await purchaseOrderById(poRef);
  if (!po) return { status: 404, body: { error: "purchase order not found" } };
  if (!PO_RECEIVABLE.has(po.status))
    return { status: 409, body: { error: `cannot receive against a ${po.status} order`, status: po.status } };

  const lines = Array.isArray(b.lines) ? b.lines : [];
  if (!lines.length) return { status: 400, body: { error: "at least one line is required" } };

  const ordered = new Map((await db.prepare("SELECT * FROM purchase_order_lines WHERE purchase_order_id=?").all(po.id))
    .map((l) => [Number(l.id), rowPoLine(l)]));
  const alreadyReceived = await receivedByLine(po.id);

  /* Resolve and validate every line first, for the same reason the PO create
     does: a half-posted delivery is not a document anyone can reconcile. */
  const prepared = [];
  const seenLines = new Set();
  for (const [i, line] of lines.entries()) {
    /* Accept either the line id or the (kind,itemId) pair — a counter clerk
       reading a delivery note has the product name, not our integer. */
    let pol = null;
    if (line.purchaseOrderLineId ?? line.purchase_order_line_id ?? line.lineId)
      pol = ordered.get(Number(line.purchaseOrderLineId ?? line.purchase_order_line_id ?? line.lineId)) ?? null;
    else if (line.itemId ?? line.item_id)
      pol = [...ordered.values()].find((l) => l.itemId === String(line.itemId ?? line.item_id)) ?? null;
    if (!pol) return { status: 400, body: { error: `line ${i + 1}: not a line on ${po.ref}` } };
    if (seenLines.has(pol.id)) return { status: 400, body: { error: `line ${i + 1}: ${pol.itemId} appears twice in one receipt` } };
    seenLines.add(pol.id);

    const qty = Number(line.qtyReceived ?? line.qty_received ?? line.qty);
    /* INTEGER, because applyStockMovement refuses anything else — and because
       "3.5 office chairs" is not a delivery. Rejecting it here gives a sentence
       instead of a 400 from deep inside the stock helper. */
    if (!Number.isInteger(qty) || qty <= 0)
      return { status: 400, body: { error: `line ${i + 1}: qtyReceived must be a positive whole number` } };
    const already = alreadyReceived.get(pol.id) ?? 0;
    if (already + qty > pol.qtyOrdered)
      return { status: 409, body: { error: `line ${i + 1}: receiving ${qty} would exceed the ${pol.qtyOrdered} ordered (${already} already received)`, ordered: pol.qtyOrdered, received: already } };
    prepared.push({ pol, qty });
  }

  const t = now();
  const ref = await nextRef("goods_receipts", "GRN-", 1000);
  const info = await db.prepare(
    "INSERT INTO goods_receipts(ref,purchase_order_id,received_at,received_by,note,created_at) VALUES(?,?,?,?,?,?)"
  ).run(ref, po.id, b.receivedAt ?? b.received_at ?? t, req.actor ?? req.user?.name ?? "system", b.note ?? null, t);
  const grnId = info.lastInsertRowid;

  const moved = [];
  for (const { pol, qty } of prepared) {
    await db.prepare("INSERT INTO goods_receipt_lines(goods_receipt_id,purchase_order_line_id,qty_received) VALUES(?,?,?)")
      .run(grnId, pol.id, qty);

    /* THE BRANCH THIS MODULE EXISTS FOR.
       A product is real inventory: receiving it puts units on the shelf, so it
       writes a ledger row through the one legal helper. A material is a finish
       code with no quantity on hand, so it records what was delivered and
       moves nothing. Getting this backwards either hides real stock or invents
       stock for a colour. */
    if (pol.itemKind === "product") {
      const movement = await applyStockMovement({
        productId: pol.itemId,
        delta: qty,
        reason: "receipt",
        refType: "goods_receipt",
        refId: grnId,
        note: `${ref} against ${po.ref}`,
        actor: req.actor ?? req.user?.name ?? "system",
      });
      moved.push({ itemId: pol.itemId, delta: qty, movementId: movement.id });
    }
  }

  /* Promote the order's status from what actually arrived, not from what the
     caller claimed. A PO is 'received' only when every line is complete. */
  const receivedNow = await receivedByLine(po.id);
  const allLines = [...ordered.values()];
  const complete = allLines.every((l) => (receivedNow.get(l.id) ?? 0) >= l.qtyOrdered);
  const anyReceived = allLines.some((l) => (receivedNow.get(l.id) ?? 0) > 0);
  const nextStatus = complete ? "received" : anyReceived ? "partially_received" : po.status;
  if (nextStatus !== po.status) {
    await db.prepare("UPDATE purchase_orders SET status=?, closed_at=?, updated_at=? WHERE id=?")
      .run(nextStatus, complete ? t : null, t, po.id);
  }

  const receipt = rowGoodsReceipt(await db.prepare("SELECT * FROM goods_receipts WHERE id=?").get(grnId));
  const body = {
    ...receipt,
    purchaseOrder: { id: po.id, ref: po.ref, status: nextStatus },
    lines: prepared.map(({ pol, qty }) => ({ purchaseOrderLineId: pol.id, itemKind: pol.itemKind, itemId: pol.itemId, qtyReceived: qty })),
    /* Reported explicitly so a caller can see which lines changed stock and
       which deliberately did not. Silence here would look like a bug. */
    stockMoved: moved,
    stockUnchanged: prepared.filter(({ pol }) => pol.itemKind !== "product").map(({ pol, qty }) => ({ itemId: pol.itemId, qtyReceived: qty, reason: "material has no stock dimension" })),
  };
  emit("goods-receipts", { title: `${ref} received against ${po.ref}`, who: req.actor, context: `${moved.length} product line(s) moved stock` });
  await writeAudit(req, { action: "goods_receipt.received", entityType: "goods_receipt", entityId: grnId, after: body });
  return { status: 201, body };
}));

/* ══════════════════ supplier invoices + 3-way matching ══════════════════
   The third leg of procurement. A purchase order says what was ordered, a
   goods receipt says what arrived, and a supplier invoice says what is being
   charged. Matching compares all three, per line.

   The verdict is stored WITH its numbers. "matched" with no figures behind it
   is a claim, not evidence, and the first person to dispute a payment will ask
   which numbers disagreed.

   Matching never blocks RECORDING an invoice: a supplier's bill is a fact that
   arrives whether or not it agrees with us, and refusing to log it only moves
   the argument to a spreadsheet. It blocks PAYMENT. */

const SUPPLIER_INVOICE_STATUSES = new Set(["draft", "posted", "paid", "cancelled"]);

/* The tolerance is configuration, not a constant. It lives in meta so an
   installation can tighten it to 0 (exact) or loosen it for freight-heavy
   supply without a deployment. Defaults to 0 when unset — the strict reading,
   because a missing setting should not silently permit a variance. */
const readMatchTolerance = async () => {
  const row = (await db.prepare("SELECT value FROM meta WHERE key='procurement.match_tolerance'").get());
  const n = Number(row?.value ?? 0);
  return Number.isFinite(n) && n >= 0 ? n : 0;
};

const rowSupplierInvoice = (r) => ({
  id: r.id, ref: r.ref, supplierId: r.supplier_id,
  purchaseOrderId: r.purchase_order_id ?? null, goodsReceiptId: r.goods_receipt_id ?? null,
  supplierRef: r.supplier_ref, invoiceDate: r.invoice_date, dueDate: r.due_date,
  currency: r.currency, subtotal: Number(r.subtotal) || 0, tax: Number(r.tax) || 0,
  total: Number(r.total) || 0, paid: Number(r.paid) || 0,
  balance: Math.max(0, (Number(r.total) || 0) - (Number(r.paid) || 0)),
  status: r.status, matchState: r.match_state, matchedAt: r.matched_at,
  match: safeJson(r.match_json, null), note: r.note, createdBy: r.created_by,
  createdAt: r.created_at, updatedAt: r.updated_at,
});
const rowSupplierInvoiceLine = (r) => ({
  id: r.id, supplierInvoiceId: r.supplier_invoice_id, purchaseOrderLineId: r.purchase_order_line_id ?? null,
  lineNo: r.line_no, description: r.description, qtyBilled: r.qty_billed,
  unitCost: Number(r.unit_cost) || 0, lineTotal: Number(r.line_total) || 0,
});

/* ── the match itself ──────────────────────────────────────────────────────
   Per PO line: how much was ordered, how much arrived, how much is billed
   (across every non-cancelled invoice, not just this one). Three separate
   disagreements, and they mean different things:

     billed > received  we are being charged for goods that never arrived
     received > ordered we received more than we asked for (usually a gift or
                        a supplier rounding up — harmless but worth seeing)
     billed > ordered   the classic over-invoice

   Unlinked lines (no PO line) are "unlinked", not "variance": there is nothing
   to compare against, and calling that a variance would cry wolf on perfectly
   ordinary standalone bills. */
async function matchSupplierInvoice(invoice, lines) {
  const tolerance = await readMatchTolerance();
  const findings = [];
  let worst = "matched";

  const bump = (state) => {
    const rank = { matched: 0, tolerance: 1, variance: 2 };
    if (rank[state] > rank[worst]) worst = state;
  };

  const orderedQty = new Map();      // pol.id -> qty ordered
  const receivedQty = new Map();     // pol.id -> qty received
  const billedBefore = new Map();    // pol.id -> qty billed on OTHER invoices

  if (invoice.purchase_order_id) {
    for (const l of await db.prepare("SELECT * FROM purchase_order_lines WHERE purchase_order_id=?").all(invoice.purchase_order_id))
      orderedQty.set(Number(l.id), Number(l.qty_ordered));
    for (const [lineId, qty] of await receivedByLine(invoice.purchase_order_id))
      receivedQty.set(lineId, qty);
    /* Everything already billed against this order, EXCLUDING this invoice,
       so re-matching an edited invoice does not count itself. */
    const prior = (await db.prepare(`
      SELECT sil.purchase_order_line_id AS line_id, COALESCE(SUM(sil.qty_billed),0) AS qty
        FROM supplier_invoice_lines sil
        JOIN supplier_invoices si ON si.id = sil.supplier_invoice_id
       WHERE si.purchase_order_id = ? AND si.id <> ? AND si.status <> 'cancelled'
         AND sil.purchase_order_line_id IS NOT NULL
       GROUP BY sil.purchase_order_line_id`).all(invoice.purchase_order_id, invoice.id));
    for (const r of prior) billedBefore.set(Number(r.line_id), Number(r.qty));
  }

  const perLine = [];
  for (const line of lines) {
    /* `lines` are rowSupplierInvoiceLine() output, so the keys are camelCase.
       Reading the DB column names here made every line "unlinked" and every
       total zero — the match ran, and silently verified nothing. */
    if (!line.purchaseOrderLineId) {
      perLine.push({ ...line, state: "unlinked", ordered: null, received: null, previouslyBilled: null, billingNow: line.qtyBilled });
      continue;
    }
    const lineId = Number(line.purchaseOrderLineId);
    const ordered = orderedQty.get(lineId) ?? 0;
    const received = receivedQty.get(lineId) ?? 0;
    const before = billedBefore.get(lineId) ?? 0;
    const after = before + Number(line.qtyBilled);
    let state = "matched";
    const reasons = [];

    if (after > ordered) { state = "variance"; reasons.push(`billed ${after} > ordered ${ordered}`); findings.push({ lineId, kind: "over_invoiced", billed: after, ordered }); }
    if (after > received) { state = "variance"; reasons.push(`billed ${after} > received ${received}`); findings.push({ lineId, kind: "billed_beyond_receipt", billed: after, received }); }
    if (received > ordered) { if (state === "matched") state = "tolerance"; reasons.push(`received ${received} > ordered ${ordered}`); findings.push({ lineId, kind: "over_received", received, ordered }); }
    /* Escalate the document verdict. Without this the per-line states were
       computed correctly and then thrown away, so a document whose only line
       was a variance still reported "matched" — a verdict that contradicted
       its own findings array. */
    bump(state);

    perLine.push({ ...line, state, ordered, received, previouslyBilled: before, billingNow: Number(line.qtyBilled), billedTotal: after, reasons });
  }

  /* Total-level comparison catches what line quantities miss: a supplier who
     bills the right quantity at the wrong price. */
  /* Totals, for the record. The line-level findings above are the verdict; this
     is the document's own arithmetic, kept so a dispute can be settled from the
     stored match without re-reading the invoice. */
  const billedSubtotal = lines.reduce((n, l) => n + (Number(l.lineTotal) || 0), 0);
  const billedTotal = Number(invoice.subtotal) || billedSubtotal + (Number(invoice.tax) || 0);

  /* An invoice with no purchase order has nothing to match against. Calling
     that "matched" would claim a verification that never happened; calling it a
     variance would cry wolf on every ordinary standalone bill. It is "unlinked"
     — a recorded fact about what was compared, not a verdict on honesty. */
  if (!invoice.purchase_order_id) worst = "unlinked";
  /* Otherwise, if nothing disagreed and every line was comparable, the verdict
     is already "matched". A line that is unlinked inside a PO-linked invoice
     stays visible per line without downgrading the whole document. */

  return {
    state: worst,
    tolerance,
    at: now(),
    lines: perLine,
    findings,
    totals: { billedSubtotal, tax: Number(invoice.tax) || 0, billedTotal },
  };
}

/* Totals are derived from the lines rather than trusted from the body: a caller
   that sends a subtotal disagreeing with its own lines has produced a document
   nobody can reconcile. */
const recalcInvoiceTotals = (lines, tax) => {
  /* `prepared` carries `lineTotal`; this used to read `line_total`, so every
     invoice totalled zero while its lines were correct. */
  const subtotal = lines.reduce((n, l) => n + (Number(l.lineTotal) || 0), 0);
  return { subtotal, total: subtotal + (Number(tax) || 0) };
};

const supplierInvoiceById = async (id) => (await db.prepare("SELECT * FROM supplier_invoices WHERE id=? OR ref=?").get(id, id));
const supplierInvoiceLines = async (id) => (await db.prepare("SELECT * FROM supplier_invoice_lines WHERE supplier_invoice_id=? ORDER BY line_no").all(id)).map(rowSupplierInvoiceLine);

/* ---------- routes ---------- */

app.get("/api/supplier-invoices", wrap(async (req, res) => {
  let rows = await db.prepare("SELECT * FROM supplier_invoices ORDER BY id DESC").all();
  if (req.query.status) {
    const wanted = String(req.query.status).split(",").map((s) => s.trim().toLowerCase());
    rows = rows.filter((r) => wanted.includes(r.status));
  }
  if (req.query.match_state) rows = rows.filter((r) => r.match_state === req.query.match_state);
  if (req.query.supplier_id) rows = rows.filter((r) => String(r.supplier_id) === String(req.query.supplier_id));
  if (req.query.purchase_order_id) rows = rows.filter((r) => String(r.purchase_order_id) === String(req.query.purchase_order_id));
  const items = rows.map(rowSupplierInvoice);
  res.json({
    total: items.length,
    payable: items.filter((i) => i.status !== "cancelled").reduce((n, i) => n + i.balance, 0),
    outstanding: items.filter((i) => ["posted"].includes(i.status) && i.balance > 0).length,
    variance: items.filter((i) => i.matchState === "variance").length,
    items,
  });
}));

/* The payables summary a finance person actually wants: how much is owed, how
   much of it is disputed, and what is overdue. */
app.get("/api/supplier-invoices/summary", wrap(async (_req, res) => {
  const rows = (await db.prepare("SELECT * FROM supplier_invoices WHERE status<>'cancelled'").all()).map(rowSupplierInvoice);
  const today = now().slice(0, 10);
  res.json({
    payable: rows.reduce((n, r) => n + r.balance, 0),
    bills: rows.length,
    variance: rows.filter((r) => r.matchState === "variance").length,
    varianceValue: rows.filter((r) => r.matchState === "variance").reduce((n, r) => n + r.balance, 0),
    overdue: rows.filter((r) => r.dueDate && r.dueDate < today && r.balance > 0).length,
    overdueValue: rows.filter((r) => r.dueDate && r.dueDate < today && r.balance > 0).reduce((n, r) => n + r.balance, 0),
  });
}));


app.get("/api/supplier-invoices/:id", wrap(async (req, res) => {
  const r = await supplierInvoiceById(req.params.id);
  if (!r) return res.status(404).json({ error: "not found" });
  const payments = (await db.prepare("SELECT * FROM supplier_payments WHERE supplier_invoice_id=? ORDER BY id").all(r.id))
    .map((p) => ({ id: p.id, amount: Number(p.amount) || 0, method: p.method, reference: p.reference, note: p.note, paidAt: p.paid_at, createdAt: p.created_at }));
  res.json({ ...rowSupplierInvoice(r), lines: await supplierInvoiceLines(r.id), payments });
}));

/* Record a supplier's bill. Never refused for disagreeing with us — the
   variance is recorded, not the invoice rejected. */
app.post("/api/supplier-invoices", wrapTxJson(async (req) => {
  const b = req.body ?? {};
  const supplierId = b.supplierId ?? b.supplier_id;
  if (!supplierId) return { status: 400, body: { error: "supplierId is required" } };
  const supplier = await supplierById(supplierId);
  if (!supplier) return { status: 400, body: { error: "supplier not found" } };

  const supplierRef = String(b.supplierRef ?? b.supplier_ref ?? "").trim();
  if (!supplierRef) return { status: 400, body: { error: "supplierRef is required — it is the number on their document" } };

  /* The duplicate guard, checked before insert so the 409 can say which
     invoice already holds that number. The unique index is the backstop. */
  const dupe = await db.prepare("SELECT id, ref, status FROM supplier_invoices WHERE supplier_id=? AND supplier_ref=?")
    .get(supplier.id, supplierRef);
  if (dupe) {
    return { status: 409, body: {
      error: `supplier invoice ${supplierRef} is already recorded as ${dupe.ref} (${dupe.status})`,
      code: "duplicate_supplier_invoice", existingId: dupe.id, existingRef: dupe.ref,
    } };
  }

  const poRef = b.purchaseOrderId ?? b.purchase_order_id;
  let po = null;
  if (poRef != null && poRef !== "") {
    po = await purchaseOrderById(poRef);
    if (!po) return { status: 400, body: { error: "purchase order not found" } };
  }
  let grnId = b.goodsReceiptId ?? b.goods_receipt_id ?? null;
  if (grnId != null && grnId !== "") {
    const grn = await db.prepare("SELECT * FROM goods_receipts WHERE id=?").get(grnId);
    if (!grn) return { status: 400, body: { error: "goods receipt not found" } };
    if (po && String(grn.purchase_order_id) !== String(po.id))
      return { status: 400, body: { error: "that goods receipt belongs to a different purchase order" } };
    if (!po) po = await purchaseOrderById(grn.purchase_order_id);
  } else grnId = null;

  const rawLines = Array.isArray(b.lines) ? b.lines : [];
  if (!rawLines.length) return { status: 400, body: { error: "at least one line is required" } };

  /* Validate everything before writing, for the same reason PO creation does:
     a half-written invoice has a ref, appears in the payables list, and nobody
     can tell whether it was ever real. */
  const poLines = po ? new Map((await db.prepare("SELECT * FROM purchase_order_lines WHERE purchase_order_id=?").all(po.id))
    .map((l) => [Number(l.id), l])) : new Map();
  const prepared = [];
  const seenPol = new Set();
  for (const [i, line] of rawLines.entries()) {
    const polished = line.purchaseOrderLineId ?? line.purchase_order_line_id ?? line.lineId;
    let polId = null;
    if (polished != null && polished !== "") {
      polId = Number(polished);
      if (!poLines.has(polId)) return { status: 400, body: { error: `line ${i + 1}: not a line on ${po ? po.ref : "any purchase order"}` } };
      if (seenPol.has(polId)) return { status: 400, body: { error: `line ${i + 1}: the same PO line appears twice` } };
      seenPol.add(polId);
    }
    const qty = Number(line.qtyBilled ?? line.qty_billed ?? line.qty);
    if (!Number.isInteger(qty) || qty <= 0)
      return { status: 400, body: { error: `line ${i + 1}: qtyBilled must be a positive whole number` } };
    const cost = Number(line.unitCost ?? line.unit_cost ?? (polId ? poLines.get(polId).unit_cost : 0));
    if (!Number.isFinite(cost) || cost < 0)
      return { status: 400, body: { error: `line ${i + 1}: unitCost must be zero or more` } };
    prepared.push({ lineNo: i + 1, polId, description: line.description ?? (polId ? poLines.get(polId).description : null), qty, cost, lineTotal: qty * cost });
  }

  const tax = Number(b.tax ?? 0);
  if (!Number.isFinite(tax) || tax < 0) return { status: 400, body: { error: "tax must be zero or more" } };
  const { subtotal, total } = recalcInvoiceTotals(prepared, tax);

  const t = now();
  const ref = await nextRef("supplier_invoices", "BILL-", 100);
  const info = await db.prepare(
    `INSERT INTO supplier_invoices(ref,supplier_id,purchase_order_id,goods_receipt_id,supplier_ref,invoice_date,due_date,
       currency,subtotal,tax,total,paid,status,note,created_by,created_at,updated_at)
     VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)`
  ).run(ref, supplier.id, po?.id ?? null, grnId, supplierRef,
    b.invoiceDate ?? b.invoice_date ?? t.slice(0, 10), b.dueDate ?? b.due_date ?? null,
    b.currency ?? supplier.currency ?? "PKR", subtotal, tax, total, 0, "draft",
    b.note ?? null, req.actor ?? req.user?.name ?? "system", t, t);
  const invoiceId = info.lastInsertRowid;
  for (const l of prepared) {
    await db.prepare(
      `INSERT INTO supplier_invoice_lines(supplier_invoice_id,purchase_order_line_id,line_no,description,qty_billed,unit_cost,line_total)
       VALUES(?,?,?,?,?,?,?)`
    ).run(invoiceId, l.polId, l.lineNo, l.description, l.qty, l.cost, l.lineTotal);
  }

  const row = await supplierInvoiceById(invoiceId);
  const lines = await supplierInvoiceLines(invoiceId);
  const match = await matchSupplierInvoice(row, lines);
  await db.prepare("UPDATE supplier_invoices SET match_state=?, match_json=?, matched_at=?, updated_at=? WHERE id=?")
    .run(match.state, JSON.stringify(match), match.at, t, invoiceId);

  const out = { ...rowSupplierInvoice(await supplierInvoiceById(invoiceId)), lines };
  emit("supplier-invoices", { title: `Supplier bill ${supplierRef} recorded`, who: req.actor, context: `${match.state} vs ${po ? po.ref : "no PO"}` });
  await writeAudit(req, { action: "supplier_invoice.recorded", entityType: "supplier_invoice", entityId: invoiceId,
    after: { ref: out.ref, supplierRef, total, matchState: match.state } });

  /* 201 with the verdict visible: a caller that ignores `match.state` still
     cannot claim they were not told. A variance is reported as a header, not
     buried in the body. */
  return {
    status: 201,
    body: out,
    headers: match.state === "variance" ? { "x-match-state": "variance" } : undefined,
  };
}));

/* Recompute the verdict on every sibling invoice for an order. A cancellation
   changes what the others have been billed for, so leaving them alone would
   make a cancelled duplicate's siblings look over-invoiced for ever. */
const rematchOrderInvoices = async (purchaseOrderId) => {
  const rows = await db.prepare("SELECT * FROM supplier_invoices WHERE purchase_order_id=? AND status<>'cancelled'").all(purchaseOrderId);
  for (const row of rows) {
    const lines = await supplierInvoiceLines(row.id);
    const match = await matchSupplierInvoice(row, lines);
    await db.prepare("UPDATE supplier_invoices SET match_state=?, match_json=?, matched_at=?, updated_at=? WHERE id=?")
      .run(match.state, JSON.stringify(match), match.at, now(), row.id);
  }
};

/* Post / cancel / re-match. Posting is the point at which the bill becomes a
   payable; a draft is a note. */
app.patch("/api/supplier-invoices/:id", wrapTxJson(async (req) => {
  const r = await supplierInvoiceById(req.params.id);
  if (!r) return { status: 404, body: { error: "not found" } };
  const before = rowSupplierInvoice(r);
  const b = req.body ?? {};
  const t = now();

  if (b.status !== undefined) {
    const next = String(b.status).toLowerCase();
    if (!SUPPLIER_INVOICE_STATUSES.has(next))
      return { status: 400, body: { error: "status must be " + [...SUPPLIER_INVOICE_STATUSES].join("|") } };
    if (next === r.status) return { status: 409, body: { error: `invoice is already ${next}` } };
    const allowed = { draft: ["posted", "cancelled"], posted: ["cancelled"], paid: [], cancelled: [] }[r.status] ?? [];
    if (!allowed.includes(next)) return { status: 409, body: { error: `cannot move a ${r.status} invoice to ${next}`, allowed } };

    if (next === "cancelled" && Number(r.paid) > 0)
      return { status: 409, body: { error: `cannot cancel: ${r.paid} already paid against this bill` } };

    await db.prepare("UPDATE supplier_invoices SET status=?, updated_at=? WHERE id=?").run(next, t, r.id);
    /* A cancellation changes what other invoices on the same order have been
       billed for, so their verdicts are recomputed too. Without this a
       cancelled duplicate leaves its siblings looking over-invoiced forever. */
    if (next === "cancelled" && r.purchase_order_id) await rematchOrderInvoices(r.purchase_order_id);
  }

  if (b.dueDate !== undefined || b.due_date !== undefined)
    await db.prepare("UPDATE supplier_invoices SET due_date=?, updated_at=? WHERE id=?").run(b.dueDate ?? b.due_date, t, r.id);
  if (b.note !== undefined) await db.prepare("UPDATE supplier_invoices SET note=?, updated_at=? WHERE id=?").run(b.note, t, r.id);

  /* Re-match on demand. Cheap, and the honest answer after any change. */
  const row = await supplierInvoiceById(r.id);
  const lines = await supplierInvoiceLines(r.id);
  const match = await matchSupplierInvoice(row, lines);
  await db.prepare("UPDATE supplier_invoices SET match_state=?, match_json=?, matched_at=?, updated_at=? WHERE id=?")
    .run(match.state, JSON.stringify(match), match.at, t, r.id);

  const out = { ...rowSupplierInvoice(await supplierInvoiceById(r.id)), lines };
  if (out.status !== before.status) {
    await writeAudit(req, { action: "supplier_invoice." + (out.status === "cancelled" ? "cancelled" : "posted"),
      entityType: "supplier_invoice", entityId: out.id, before: { status: before.status }, after: { status: out.status } });
  }
  return { body: out };
}));


/* Record money OUT. The variance rule lives here: an invoice that disagrees
   with the goods can be recorded, but paying it requires an explicit override
   that is named in the request and written to the audit log. A silent override
   would defeat the entire purpose of matching. */
app.post("/api/supplier-invoices/:id/payments", wrapTxJson(async (req) => {
  const r = await supplierInvoiceById(req.params.id);
  if (!r) return { status: 404, body: { error: "not found" } };
  if (r.status === "cancelled") return { status: 409, body: { error: "this bill is cancelled and cannot be paid" } };
  if (r.status === "draft") return { status: 409, body: { error: "post the bill before paying it" } };

  const amount = Number(req.body?.amount);
  if (!(amount > 0)) return { status: 400, body: { error: "amount must be > 0" } };

  const outstanding = Number(r.total) - Number(r.paid);
  /* The ceiling the receivables side lacks: money cannot leave twice for the
     same bill. 0.005 absorbs floating-point cents. */
  if (amount > outstanding + 0.005)
    return { status: 409, body: { error: `payment of ${amount} exceeds the ${Math.round(outstanding * 100) / 100} outstanding on ${r.ref}`, outstanding } };

  const override = req.body?.overrideVariance === true;
  if (r.match_state === "variance" && !override) {
    const match = safeJson(r.match_json, null);
    return { status: 409, body: {
      error: "this bill does not match the purchase order and receipt — review the findings or pass overrideVariance to pay anyway",
      code: "match_variance", matchState: r.match_state,
      findings: match?.findings ?? [],
    } };
  }

  const t = now();
  await db.prepare("INSERT INTO supplier_payments(supplier_invoice_id,amount,method,reference,note,paid_at,created_at) VALUES(?,?,?,?,?,?,?)")
    .run(r.id, amount, req.body?.method ?? "Bank transfer", req.body?.reference ?? null, req.body?.note ?? null, t, t);
  const paid = Number(r.paid) + amount;
  const status = paid >= Number(r.total) - 0.005 ? "paid" : "posted";
  await db.prepare("UPDATE supplier_invoices SET paid=?, status=?, updated_at=? WHERE id=?").run(paid, status, t, r.id);

  await writeAudit(req, {
    action: "supplier_invoice.payment_recorded", entityType: "supplier_invoice", entityId: r.id,
    before: { paid: Number(r.paid), status: r.status }, after: { paid, status, amount, overrideVariance: override },
  });
  emit("supplier-invoices", { title: `Payment ${amount} to supplier on ${r.ref}`, who: req.actor, context: status });

  const out = rowSupplierInvoice(await supplierInvoiceById(r.id));
  return { body: { ...out, lines: await supplierInvoiceLines(r.id) } };
}));

/* Audit rows are evidence; they must not be editable through the API. Only
   reads are exposed, and only to an owner. */
app.get("/api/audit", wrap(async (req, res) => {
  const limit = Math.min(500, Math.max(1, Number(req.query.limit) || 100));
  const rows = (await db.prepare(
    `SELECT id,actor_email,actor_name,actor_role,action,entity_type,entity_id,created_at
       FROM audit_logs ORDER BY id DESC LIMIT ?`).all(limit));
  res.json({ total: rows.length, items: rows.map((r) => ({
    id: r.id, actor: r.actor_name, actorEmail: r.actor_email, actorRole: r.actor_role,
    action: r.action, entityType: r.entity_type, entityId: r.entity_id, at: r.created_at,
  })) });
}));

app.get("/api/audit/health", wrap(async (_req, res) => {
  const row = (await db.prepare("SELECT COUNT(*) AS n FROM audit_logs").get());
  res.json({ rows: Number(row?.n ?? 0), writeFailures: auditWriteFailures });
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
    .run(b.client_id ?? null, b.lead_id ?? null, b.quote_id ?? null, b.order_id ?? null, b.title, b.due ?? null, b.owner ?? "You", normPriority(b.priority), t, t)));
  const out = rowTask((await (db.prepare("SELECT * FROM tasks WHERE id=?").get(info.lastInsertRowid))));
  emit("tasks", { title: "Task added: " + b.title.slice(0, 60), who: b.owner ?? "You" });
  res.status(201).json(out);
}));
app.patch("/api/tasks/:id", wrap(async (req, res) => {
  const r = (await (db.prepare("SELECT * FROM tasks WHERE id=?").get(req.params.id)));
  if (!r) return res.status(404).json({ error: "not found" });
  const b = req.body ?? {};
  (await (db.prepare("UPDATE tasks SET title=?, due=?, owner=?, priority=?, done=?, updated_at=? WHERE id=?").run(
    b.title ?? r.title, b.due ?? r.due, b.owner ?? r.owner, b.priority !== undefined ? normPriority(b.priority) : r.priority,
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

/* The canonical public origin. Before this, the sitemap fell back to
   http://localhost:5174 whenever the theme had no siteUrl — and in production
   the theme is unset, so the live sitemap advertised 165 localhost URLs to
   Google. The fallback was invisible locally, where localhost is correct.
   Order: explicit theme setting (an admin can override), then deployment
   config, then the request's own host, and only then a dev default that can
   never reach production because a real request always carries a host. */
const publicOrigin = async (req) => {
  const configured = String((await readTheme()).siteUrl || "").trim();
  if (configured) return configured.replace(/\/+$/, "");
  const fromEnv = String(process.env.SITE_URL || process.env.PUBLIC_SITE_URL || "").trim();
  if (fromEnv) return fromEnv.replace(/\/+$/, "");
  const host = String(req?.headers?.host || "").trim();
  if (host) {
    const proto = String(req?.headers?.["x-forwarded-proto"] || "").split(",")[0].trim()
      || (host.startsWith("localhost") || host.startsWith("127.") ? "http" : "https");
    return `${proto}://${host}`;
  }
  return "http://localhost:5174";
};

const xmlEsc = (x) => String(x).replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&apos;" }[c]));
/* One sitemap, built from the shared PUBLIC_ROUTES list plus whatever the
   database knows: published CMS pages and live products. Before this, the API
   listed pages+products and silently omitted all 16 static marketing routes. */
const sitemapDocument = async (req) => {
  const base = await publicOrigin(req);
  const today = new Date().toISOString().slice(0, 10);
  const urls = [];
  for (const r of PUBLIC_ROUTES)
    urls.push(`  <url><loc>${xmlEsc(absoluteUrl(base, r.path))}</loc><lastmod>${today}</lastmod><changefreq>${r.changefreq}</changefreq><priority>${r.priority}</priority></url>`);
  for (const x of await db.prepare("SELECT slug, updated_at FROM pages WHERE status='Published' ORDER BY updated_at DESC").all())
    urls.push(`  <url><loc>${xmlEsc(base + "/p/" + x.slug)}</loc><lastmod>${isoDay(x.updated_at)}</lastmod></url>`);
  for (const x of await db.prepare("SELECT id, updated_at FROM products ORDER BY updated_at DESC LIMIT 500").all())
    urls.push(`  <url><loc>${xmlEsc(base + "/shop/" + x.id)}</loc><lastmod>${isoDay(x.updated_at)}</lastmod></url>`);
  return `<?xml version="1.0" encoding="UTF-8"?>\n<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">\n${urls.join("\n")}\n</urlset>\n`;
};

/* Two explicit routes, one handler.
   /sitemap.xml is the address in robots.txt and the one a human types.
   /api/sitemap is what vercel.json rewrites that to, and the only path the
   serverless function ever sees. Serving it here means the rewrite works
   regardless of which form Vercel delivers — the previous setup relied on a
   separate function file being invoked with the right path, and it was not.

   Written as two routes rather than a loop because a loop hides both from the
   `^app\.(get|post` pattern that derives the route count in
   00-CURRENT-STATE.md; the documented number would then drift silently. */
const sendSitemap = wrap(async (req, res) => {
  res.setHeader("content-type", "application/xml; charset=utf-8");
  res.end(await sitemapDocument(req));
});
app.get("/sitemap.xml", sendSitemap);
app.get("/api/sitemap", sendSitemap);
/* What the Vercel catch-all actually delivers: the rewrite sends /sitemap.xml
   to /api/index, Vercel strips the /api prefix, and api/index.mjs puts it back —
   so Express sees /api/sitemap.xml. Both spellings are routed so the sitemap
   does not depend on that prefixing behaviour staying the same. */
app.get("/api/sitemap.xml", sendSitemap);

app.get("/robots.txt", wrap(async (req, res) => {
  const base = await publicOrigin(req);
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
/* Some tables name their reference column something other than `ref` — an
   installed asset is identified by `tag`, because it is a label stuck on a
   physical object rather than a document number. Parameterised rather than
   guessing, because "no such column: ref" is a 500 at the moment a technician
   is standing in a kitchen. */
const nextRefColumn = async (table, column, prefix, floor = 0) => {
  const m = (await (db.prepare(`SELECT MAX(CAST(substr(${column}, ?) AS INTEGER)) v FROM ${table}`).get(prefix.length + 1))).v ?? 0;
  return prefix + String(Math.max(Number(m), floor) + 1);
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

  /* Status is normalised and validated before anything is written. Before this,
     PATCH stored whatever arrived — so "cancelled" (lowercase) would have become
     a raw value, and under the new CHECK that is a 500 instead of a clear 400.
     Paid and Partially Paid are derived from what has been paid: accepting them
     here would let an operator mark an invoice settled without any money, and
     the balance would immediately disagree with the status. */
  let nextStatus = r.status;
  if (b.status !== undefined) {
    const norm = normInvoiceStatus(b.status);
    if (!norm) return res.status(400).json({ error: `status must be one of ${INVOICE_STORED_STATUSES.join(", ")}` });
    if (norm.error) return res.status(400).json({ error: norm.error });
    if (INVOICE_DERIVED_STATUSES.includes(norm.status))
      return res.status(409).json({ error: `${norm.status} is set by recording a payment, not directly. Use POST /api/invoices/${r.id}/payments.` });
    if (r.status === "Cancelled" && norm.status !== "Cancelled")
      return res.status(409).json({ error: "this invoice is cancelled; a cancelled invoice cannot be reopened" });
    nextStatus = norm.status;
  }

  emit("invoices", { title: "Invoice " + r.ref + " → " + nextStatus, who: r.customer });
  const updateResult = (await (db.prepare("UPDATE invoices SET status=?, due=?, notes=?, discount=?, tax=?, shipping=?, total=?, updated_at=? WHERE id=?").run(
    nextStatus, b.due ?? r.due, b.notes ?? r.notes,
    b.discount ?? r.discount, b.tax ?? r.tax, b.shipping ?? r.shipping,
    b.total ?? (b.discount != null || b.tax != null || b.shipping != null
      ? Math.max(0, r.subtotal - (b.discount ?? r.discount) + (b.tax ?? r.tax) + (b.shipping ?? r.shipping)) : r.total),
    now(), r.id)));
  /* Voiding an invoice is the financial action most likely to be questioned
     later, and status is the field that carries it. Log every accepted change,
     not just cancellation — a total edited without a matching payment is the
     other way this row can stop making sense. */
  const nextTotal = b.total ?? r.total;
  await writeAudit(req, {
    action: "invoice.updated",
    entityType: "invoice",
    entityId: r.id,
    before: { status: r.status, total: r.total, paid: r.paid },
    after: { status: nextStatus, total: nextTotal, paid: r.paid, changedFields: Object.keys(b) },
  });
  res.json(rowInvoice((await (db.prepare("SELECT * FROM invoices WHERE id=?").get(r.id)))));
}));
app.post("/api/invoices/:id/payments", wrapTxJson(async (req, afterCommit) => {
  const r = (await (db.prepare("SELECT * FROM invoices WHERE id=? OR ref=?").get(req.params.id, req.params.id)));
  if (!r) return { status: 404, body: { error: "not found" } };
  /* The transition the CHECK cannot prevent. This route sets the status
     unconditionally from the running total, so recording a payment against a
     cancelled invoice used to flip it straight back to Paid/Partially Paid and
     quietly undo the cancellation. Refuse it instead of silently resurrecting
     an invoice somebody deliberately voided. */
  if (r.status === "Cancelled")
    return { status: 409, body: { error: "this invoice is cancelled and cannot take payments" } };
  const amount = Number(req.body?.amount);
  if (!(amount > 0)) return { status: 400, body: { error: "amount must be > 0" } };
  const t = now();
  (await (db.prepare("INSERT INTO payments(invoice_id,amount,method,reference,note,created_at) VALUES(?,?,?,?,?,?)")
    .run(r.id, amount, req.body?.method ?? "Bank transfer", req.body?.reference ?? null, req.body?.note ?? null, t)));
  const paid = r.paid + amount;
  const status = paid >= r.total ? "Paid" : "Partially Paid";
  (await (db.prepare("UPDATE invoices SET paid=?, status=?, updated_at=? WHERE id=?").run(paid, status, t, r.id)));
  /* Money moved. The before/after pair is the whole point: it records the
     balance as it stood and as it became, so a later dispute can be settled
     from the log alone rather than by re-deriving it from payments rows that
     somebody may since have edited. */
  await writeAudit(req, {
    action: "invoice.payment_recorded",
    entityType: "invoice",
    entityId: r.id,
    before: { paid: r.paid, status: r.status, balance: r.balance },
    after: { paid, status, amount, method: req.body?.method ?? "Bank transfer", reference: req.body?.reference ?? null },
  });
  emit("invoices", { title: "Payment " + (amount >= r.balance ? "" : "partial ") + fmtMoney(amount) + " on " + r.ref, who: r.customer });
  emit("payments", { amount, invoiceId: r.id, method: req.body?.method ?? "Bank transfer" });
  return { status: 201, body: { ...rowInvoice((await (db.prepare("SELECT * FROM invoices WHERE id=?").get(r.id)))),
    payments: (await (db.prepare("SELECT * FROM payments WHERE invoice_id=? ORDER BY datetime(created_at) DESC").all(r.id))) } };
}));

const RETURN_STATES = ["Requested", "Inspecting", "Refunding", "Closed"];

/* The return state is a closed set, but nothing enforced it, and one comparison
   downstream depends on an exact string: PATCH restocks the order's units only
   when the next state is literally "Closed". So "closed", "CLOSED" or "Complete"
   resolved the RMA and silently skipped the restock — the units stayed gone from
   inventory with no ledger entry saying why. Normalising here is what makes that
   comparison reliable, and it has to happen before a CHECK can be honest.

   Matching is case- and separator-insensitive, and the two spellings people
   actually reach for are mapped rather than rejected: an agent closing a return
   types "Done"/"Complete"/"Completed", and an older client may still send the
   seed's "Approved"/"Rejected". Rejecting those would turn working requests into
   400s, which is the mistake 002 avoided by leaving the column open. */
const RETURN_STATE_ALIASES = new Map([
  ["requested", "Requested"], ["request", "Requested"], ["open", "Requested"], ["new", "Requested"],
  ["inspecting", "Inspecting"], ["inspection", "Inspecting"], ["in transit", "Inspecting"],
  ["refunding", "Refunding"], ["refund", "Refunding"], ["refunded", "Refunding"],
  ["closed", "Closed"], ["close", "Closed"], ["complete", "Closed"], ["completed", "Closed"],
  ["done", "Closed"], ["resolved", "Closed"], ["approved", "Closed"], ["rejected", "Closed"],
]);
const normReturnState = (v) => {
  const raw = String(v ?? "").trim();
  if (!raw) return "Requested";
  const hit = RETURN_STATE_ALIASES.get(raw.toLowerCase().replace(/[_-]+/g, " ").replace(/\s+/g, " "));
  /* Unknown values keep "Requested" rather than being stored verbatim: a state
     outside the set is exactly what broke the restock, and the default is the
     only one that cannot skip a side effect. */
  return hit ?? "Requested";
};
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
app.patch("/api/returns/:id", wrapTxJson(async (req, afterCommit) => {
  const r = (await db.prepare("SELECT * FROM returns WHERE id=? OR ref=?").get(req.params.id, req.params.id));
  if (!r) return { status: 404, body: { error: "not found" } };
  const b = req.body ?? {};
  const next = b.state !== undefined ? normReturnState(b.state) : r.state;
  await db.prepare("UPDATE returns SET state=?, refund_amount=?, resolution=?, updated_at=? WHERE id=?")
    .run(next, b.refund_amount ?? b.refundAmount ?? r.refund_amount, b.resolution ?? r.resolution, now(), r.id);

  /* Goods coming back are stock coming back. Before this, closing a return
     changed a status and nothing else — the units stayed gone, so every return
     permanently understated inventory with no record of why.

     Only on the transition INTO Closed (never an edit that merely restates it),
     and only for lines naming a real catalogue product: a return may be for a
     bespoke item, and inventing stock for one would corrupt the ledger. */
  if (next === "Closed" && r.state !== "Closed" && r.order_id) {
    const order = (await db.prepare("SELECT items FROM orders WHERE id=?").get(r.order_id));
    const lines = safeJson(order?.items, []);
    const want = String(r.item ?? "").trim().toLowerCase().slice(0, 140);
    for (const line of Array.isArray(lines) ? lines : []) {
      if (!want) break;
      if (String(line?.name ?? "").trim().toLowerCase() !== want) continue;
      /* Checkout writes { id, name, price, qty }. Seeded and imported orders
         write { name, price, qty } with no id at all — so the original
         `if (!line?.id) continue` skipped every line of every order that did
         not come through checkout, and those returns silently never restocked.
         Resolve the catalogue id by name in that case instead of giving up.
         The name lives inside the JSON blob and this codebase never reads JSON
         in SQL (the two drivers disagree on the function), so match in JS. */
      let productId = String(line.id ?? line.productId ?? "").trim();
      const qty = Math.min(Number(line.qty) || 0, 100);
      if (!productId && qty > 0) {
        const bare = String(line.name ?? "").trim().slice(0, 140);
        for (const p of await db.prepare("SELECT id, json FROM products").all()) {
          if (String(safeJson(p.json, {}).name ?? "").trim() === bare) { productId = p.id; break; }
        }
      }
      if (productId && qty > 0) {
        await applyStockMovement({
          productId, delta: qty, reason: "return",
          refType: "return", refId: r.id, note: "RMA " + r.ref,
          actor: req.actor ?? "Returns",
        });
      }
    }
  }

  const rOut = rowReturn((await db.prepare("SELECT * FROM returns WHERE id=?").get(r.id)));
  /* Closing a return restocks inventory — a physical consequence of a status
     change. Log it with the restock flag so the ledger and the audit trail
     agree on whether units came back. */
  if (b.state) await writeAudit(req, {
    action: "return.state_changed",
    entityType: "return",
    entityId: r.id,
    before: { state: r.state, refundAmount: r.refund_amount },
    after: { state: rOut.state, refundAmount: rOut.refundAmount, restocked: next === "Closed" && r.state !== "Closed" && !!r.order_id },
  });
  if (b.state) afterCommit.push(() => emit("returns", { title: "RMA " + r.ref + " → " + b.state, who: r.customer }));
  return { body: rOut };
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
  return !!u && !!u.active && ts(u.expires_at) > Date.now();
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
    /* Opening balance for stock that already existed when the ledger arrived.
       The schema upgrade at boot runs BEFORE this seed, so a database that
       already had products gets no opening movement from migration 003 — the
       INSERT OR IGNORE above matches nothing and inserts no row. Without this,
       every pre-existing product holds real stock against an empty history, and
       reconciliation reports a discrepancy that is really a missing baseline.
       Guarded on "no movements at all", so it can never add a second baseline
       to a product that already has history. */
    await db.prepare(`INSERT INTO stock_movements(product_id, delta, reason, note, created_by, created_at)
      SELECT id, stock_qty, 'receipt', 'opening balance captured at ledger introduction', 'seed', ?
        FROM products p
       WHERE p.stock_qty <> 0
         AND NOT EXISTS (SELECT 1 FROM stock_movements m WHERE m.product_id = p.id)`).run(t);
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
  /* Sweep expired throttle buckets hourly on a long-running server. Deliberately
     NOT started on serverless: a timer there either never fires (instance
     recycled first) or keeps the instance alive, and the endpoint above exists
     so the sweep can be driven from outside instead. */
  if (process.env.WOODEX_DISABLE_SWEEP !== "1") {
    const timer = setInterval(() => {
      throttleSweep().catch((error) => console.error("[woodex-api] throttle sweep failed:", error.message));
    }, 3600_000);
    timer.unref?.();
  }
}
export { app, db, httpServer };

/**
 * Woodex DB facade. SQLite remains the zero-config default; PostgreSQL is enabled
 * with DATABASE_URL=postgres://… . For local/CI rehearsal, DATABASE_URL=pglite:/path
 * uses the same PostgreSQL parser, types, constraints, and SQL dialect in WASM.
 *
 * All query and statement methods return promises on both drivers. Transaction
 * context is request-local (AsyncLocalStorage), so concurrent API requests never
 * share a PostgreSQL client or accidentally join each other's transaction.
 */
import { AsyncLocalStorage } from "node:async_hooks";
import path from "node:path";

/* Every table that hands out an integer `id`. On PostgreSQL an INSERT only
   reports the new id if the statement asks for it (`RETURNING id`); SQLite
   reports it unasked. So for each table named here the adapter appends
   `RETURNING id`, and `lastInsertRowid` means the same thing on both engines.

   This list stopped at the original 22 tables. Every table added by migrations
   003–018 was left out, so on PostgreSQL — which is what production runs — an
   insert into any of them reported id 0: a purchase order whose lines were
   filed under order 0, a ticket whose first message belonged to ticket 0. The
   module tests never saw it because they ran on SQLite only.

   It is a list rather than "every INSERT" on purpose: nine tables have no
   integer id (products, sessions, stock_levels …) and `RETURNING id` on those
   is an error, not a zero.

   DO NOT EDIT FROM MEMORY. scripts/test-return-id.mjs builds the real schema,
   reads the catalogue, and fails if this set and the database disagree in
   either direction. A new table with an integer id goes here in the same
   commit that creates it. */
export const RETURN_ID_TABLES = new Set([
  // scripts/schema.postgres.sql
  "boq_lines", "clients", "conversations", "invoices", "leads", "messages", "orders", "page_blocks", "page_versions",
  "pages", "payments", "project_files", "project_milestones", "projects", "quote_versions", "quotes", "returns",
  "saved_sections", "site_visits", "tasks", "track_events", "users",
  // 003 stock ledger · 008 audit log
  "stock_movements", "stock_reservations", "audit_logs",
  // 009 procurement · 010 supplier invoices
  "goods_receipt_lines", "goods_receipts", "purchase_order_lines", "purchase_orders", "suppliers",
  "supplier_invoice_lines", "supplier_invoices", "supplier_payments",
  // 011 production · 012 warehouses
  "bom_lines", "boms", "job_card_materials", "job_cards",
  "stock_transfer_lines", "stock_transfers", "storage_locations", "warehouses",
  // 013 finance
  "accounts", "credit_notes", "expenses", "journal_entries", "journal_lines", "payment_schedules", "tax_records",
  // 014 CRM · 015 delivery · 016 support
  "activities", "attachments", "companies", "contacts", "meetings",
  "deliveries", "delivery_lines", "dispatch_runs", "installed_assets",
  "service_visits", "ticket_messages", "tickets", "warranty_claims",
  // 017 credentials · 018 throttle
  "password_resets", "throttle_buckets",
  // 019 catalogue
  "product_variants",
]);

/** Convert SQLite '?' placeholders to PostgreSQL '$n', respecting SQL quoting/comments. */
export function rewritePlaceholders(sql) {
  let out = "", n = 0, i = 0;
  while (i < sql.length) {
    const c = sql[i];
    if (c === "'" || c === '"' || c === "`") {
      const q = c; out += c; i++;
      while (i < sql.length) {
        const ch = sql[i]; out += ch; i++;
        if (ch === "\\" && i < sql.length) { out += sql[i++]; continue; }
        if (ch === q) {
          if (q !== '`' && sql[i] === q) { out += sql[i++]; continue; }
          break;
        }
      }
      continue;
    }
    if (c === "-" && sql[i + 1] === "-") {
      const end = sql.indexOf("\n", i); const j = end < 0 ? sql.length : end;
      out += sql.slice(i, j); i = j; continue;
    }
    if (c === "/" && sql[i + 1] === "*") {
      const end = sql.indexOf("*/", i + 2); const j = end < 0 ? sql.length : end + 2;
      out += sql.slice(i, j); i = j; continue;
    }
    if (c === "$" ) {
      const tag = /^\$[A-Za-z_][\w]*\$|^\$\$/.exec(sql.slice(i))?.[0];
      if (tag) { const end = sql.indexOf(tag, i + tag.length); const j = end < 0 ? sql.length : end + tag.length;
        out += sql.slice(i, j); i = j; continue; }
    }
    if (c === "?") { out += "$" + (++n); i++; continue; }
    out += c; i++;
  }
  return out;
}

/** Rewrite a statement issued through `.run()` for PostgreSQL. Exported for scripts/test-return-id.mjs. */
export function pgSqlForRun(sql) {
  const ignore = /^\s*INSERT\s+OR\s+IGNORE\s+INTO\b/i.test(sql);
  let out = rewritePlaceholders(sql).replace(/^\s*INSERT\s+OR\s+IGNORE\s+INTO\b/i, "INSERT INTO");
  const table = /^\s*INSERT\s+INTO\s+["`]?([\w]+)/i.exec(out)?.[1]?.toLowerCase();
  if (!table) return out;
  /* A clause is about to be appended, so a closing semicolon has to go first:
     `…VALUES (…); RETURNING id` is a syntax error, not an id. */
  const needsConflict = ignore && !/\bON\s+CONFLICT\b/i.test(out);
  const needsReturning = RETURN_ID_TABLES.has(table) && !/\bRETURNING\b/i.test(out);
  if (needsConflict || needsReturning) out = out.replace(/[;\s]+$/, "");
  if (needsConflict) out += " ON CONFLICT DO NOTHING";
  if (needsReturning) out += " RETURNING id";
  return out;
}

/* The id an INSERT produced, or 0 when it produced none (a conflict that did
   nothing, or a table whose key is not a number). Never NaN: callers test it. */
const insertedId = (result) => {
  const id = Number(result.rows?.[0]?.id);
  return Number.isFinite(id) ? id : 0;
};

function makeMutex() {
  let tail = Promise.resolve();
  return async (fn) => {
    const prior = tail;
    let unlock;
    tail = new Promise((resolve) => { unlock = resolve; });
    await prior;
    try { return await fn(); } finally { unlock(); }
  };
}
/* ---- PostgreSQL values → the values SQLite already hands the application ----
   The application was written, and for a long time only tested, against SQLite,
   where a timestamp is ISO text, a date is "YYYY-MM-DD" and an amount is a
   number. PostgreSQL's drivers hand back something else for the column types
   the newer migrations use (014 CRM onward):

     TIMESTAMPTZ → a Date     `row.starts_at.slice(0, 16)` throws; the request is a 500
     DATE        → a Date     at local midnight: "2027-11-10" becomes
                              "2027-11-10T00:00:00.000Z", and a day earlier or
                              later on any server whose clock is not UTC
     NUMERIC     → a string   "1500.00" + 250 is "1500.00250"

   There are 54 row mappers and each would have to remember all three. So the
   difference is removed here, once, where rows enter the application: both
   PostgreSQL paths return ISO text, date text and numbers — the same values,
   in the same format, that SQLite returns and that `now()` writes.

   The JSON the API sends does not change for a timestamp (a Date serialises to
   the same ISO text). It does change for a DATE column, from a midnight
   timestamp to the date itself, which is what the screens were built against.

   Booleans are deliberately left alone: PostgreSQL returns true/false, SQLite
   1/0, and the mappers already coerce those. */
const PG_OID = { NUMERIC: 1700, DATE: 1082, TIMESTAMP: 1114, TIMESTAMPTZ: 1184 };
const isoText = (date, raw) => (date instanceof Date && Number.isFinite(date.getTime()) ? date.toISOString() : String(raw));
const numberFromNumeric = (raw) => (raw === null || raw === undefined ? null : Number(raw));

const normalizeRow = (row) => {
  if (!row) return row;
  for (const [key, value] of Object.entries(row)) {
    if (typeof value === "bigint") row[key] = Number(value);
    else if (typeof value === "string" && key === "id" && /^\d+$/.test(value)) row[key] = Number(value);
    /* Any Date a type parser did not already convert (a TIMESTAMP without time
       zone, should one ever be added) — the application never receives a Date. */
    else if (value instanceof Date) row[key] = Number.isFinite(value.getTime()) ? value.toISOString() : null;
  }
  return row;
};

export async function createDb({ sqliteFile, databaseUrl = "" }) {
  const url = String(databaseUrl ?? "").trim();
  const als = new AsyncLocalStorage();
  const mutex = makeMutex();

  if (!url) {
    const { DatabaseSync } = await import("node:sqlite");
    const raw = new DatabaseSync(sqliteFile);
    const guarded = (fn) => als.getStore() ? fn() : mutex(fn);
    const db = {
      kind: "sqlite",
      /* Every statement is prepared through here. Counting them is how we catch
         N+1 loops: a list endpoint's query count must not grow with row count. */
      queries: 0,
      prepare(sql) {
        db.queries += 1;
        const stmt = raw.prepare(sql);
        return {
          get: (...params) => guarded(() => stmt.get(...params) ?? undefined),
          all: (...params) => guarded(() => stmt.all(...params)),
          run: (...params) => guarded(() => { const r = stmt.run(...params); return { lastInsertRowid: Number(r.lastInsertRowid), changes: Number(r.changes) }; }),
        };
      },
      exec: (sql) => guarded(() => raw.exec(sql)),
      async transaction(fn) {
        return mutex(async () => {
          raw.exec("BEGIN IMMEDIATE");
          try { const value = await als.run(true, fn); raw.exec("COMMIT"); return value; }
          catch (error) { try { raw.exec("ROLLBACK"); } catch { /* preserve original failure */ } throw error; }
        });
      },
      close: () => raw.close(),
    };
    return db;
  }

  if (url.startsWith("pglite:")) {
    const { PGlite } = await import("@electric-sql/pglite");
    const dir = path.resolve(url.slice("pglite:".length));
    const pgl = new PGlite(dir, {
      parsers: {
        [PG_OID.TIMESTAMPTZ]: (raw) => isoText(new Date(raw), raw),
        [PG_OID.DATE]: (raw) => raw,
        [PG_OID.NUMERIC]: numberFromNumeric,
      },
    });
    await pgl.waitReady;
    const query = async (sql, params = []) => {
      const run = async () => pgl.query(rewritePlaceholders(sql), params);
      return als.getStore() ? run() : mutex(run);
    };
    const db = {
      kind: "pglite",
      queries: 0,
      prepare(sql) {
        db.queries += 1;
        return {
          get: async (...params) => normalizeRow((await query(sql, params)).rows?.[0]),
          all: async (...params) => (await query(sql, params)).rows.map(normalizeRow),
          run: async (...params) => {
            const result = await query(pgSqlForRun(sql), params);
            return { lastInsertRowid: insertedId(result), changes: Number(result.affectedRows ?? result.rowCount ?? 0) };
          },
        };
      },
      exec: async (sql) => {
        const run = () => pgl.exec(sql);
        return als.getStore() ? run() : mutex(run);
      },
      async transaction(fn) {
        return mutex(async () => {
          await pgl.exec("BEGIN");
          try { const value = await als.run(true, fn); await pgl.exec("COMMIT"); return value; }
          catch (error) { try { await pgl.exec("ROLLBACK"); } catch { /* preserve original failure */ } throw error; }
        });
      },
      close: () => pgl.close(),
    };
    return db;
  }

  const pgModule = await import("pg");
  const pg = pgModule.default ?? pgModule;
  // node-postgres exposes int8 as strings by default; all app BIGINT ids/counts
  // are within JS's safe integer range, matching node:sqlite's number values.
  pg.types.setTypeParser(20, (value) => Number(value));
  // See "PostgreSQL values → the values SQLite already hands the application" above.
  const pgTimestamp = pg.types.getTypeParser(PG_OID.TIMESTAMPTZ);
  pg.types.setTypeParser(PG_OID.TIMESTAMPTZ, (raw) => isoText(pgTimestamp(raw), raw));
  pg.types.setTypeParser(PG_OID.DATE, (raw) => raw);
  pg.types.setTypeParser(PG_OID.NUMERIC, numberFromNumeric);
  const pool = new pg.Pool({
    connectionString: url,
    max: Number(process.env.PG_POOL_MAX || (process.env.VERCEL === "1" ? 1 : 10)),
    connectionTimeoutMillis: 5_000,
    idleTimeoutMillis: 10_000,
    allowExitOnIdle: process.env.VERCEL === "1",
  });
  await pool.query("SELECT 1");
  const query = async (sql, params = []) => {
    const client = als.getStore();
    return client ? client.query(sql, params) : pool.query(sql, params);
  };
  const db = {
    kind: "postgres",
    queries: 0,
    prepare(sql) {
      db.queries += 1;
      return {
        get: async (...params) => normalizeRow((await query(rewritePlaceholders(sql), params)).rows?.[0]),
        all: async (...params) => (await query(rewritePlaceholders(sql), params)).rows.map(normalizeRow),
        run: async (...params) => {
          const result = await query(pgSqlForRun(sql), params);
          return { lastInsertRowid: insertedId(result), changes: Number(result.rowCount ?? 0) };
        },
      };
    },
    exec: (sql) => query(sql),
    async transaction(fn) {
      const client = await pool.connect();
      try {
        await client.query("BEGIN");
        const value = await als.run(client, fn);
        await client.query("COMMIT");
        return value;
      } catch (error) {
        try { await client.query("ROLLBACK"); } catch { /* preserve original failure */ }
        throw error;
      } finally { client.release(); }
    },
    close: () => pool.end(),
  };
  return db;
}

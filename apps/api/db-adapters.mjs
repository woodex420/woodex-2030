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

const RETURN_ID_TABLES = new Set([
  "leads", "quotes", "orders", "invoices", "payments", "returns", "clients", "tasks",
  "pages", "page_blocks", "page_versions", "saved_sections", "track_events",
  "conversations", "messages", "users",
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

function pgSqlForRun(sql) {
  const ignore = /^\s*INSERT\s+OR\s+IGNORE\s+INTO\b/i.test(sql);
  let out = rewritePlaceholders(sql).replace(/^\s*INSERT\s+OR\s+IGNORE\s+INTO\b/i, "INSERT INTO");
  const table = /^\s*INSERT\s+INTO\s+["`]?([\w]+)/i.exec(out)?.[1]?.toLowerCase();
  if (ignore && !/\bON\s+CONFLICT\b/i.test(out)) out += " ON CONFLICT DO NOTHING";
  if (table && RETURN_ID_TABLES.has(table) && !/\bRETURNING\b/i.test(out)) out += " RETURNING id";
  return out;
}

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
const normalizeRow = (row) => {
  if (!row) return row;
  for (const [key, value] of Object.entries(row)) {
    if (typeof value === "bigint") row[key] = Number(value);
    else if (typeof value === "string" && key === "id" && /^\d+$/.test(value)) row[key] = Number(value);
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
      prepare(sql) {
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
    const pgl = new PGlite(dir);
    await pgl.waitReady;
    const query = async (sql, params = []) => {
      const run = async () => pgl.query(rewritePlaceholders(sql), params);
      return als.getStore() ? run() : mutex(run);
    };
    const db = {
      kind: "pglite",
      prepare(sql) {
        return {
          get: async (...params) => normalizeRow((await query(sql, params)).rows?.[0]),
          all: async (...params) => (await query(sql, params)).rows.map(normalizeRow),
          run: async (...params) => {
            const result = await query(pgSqlForRun(sql), params);
            return { lastInsertRowid: result.rows?.[0]?.id == null ? 0 : Number(result.rows[0].id), changes: Number(result.affectedRows ?? result.rowCount ?? 0) };
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
  const pool = new pg.Pool({ connectionString: url, max: Number(process.env.PG_POOL_MAX || 10) });
  await pool.query("SELECT 1");
  const query = async (sql, params = []) => {
    const client = als.getStore();
    return client ? client.query(sql, params) : pool.query(sql, params);
  };
  const db = {
    kind: "postgres",
    prepare(sql) {
      return {
        get: async (...params) => normalizeRow((await query(rewritePlaceholders(sql), params)).rows?.[0]),
        all: async (...params) => (await query(rewritePlaceholders(sql), params)).rows.map(normalizeRow),
        run: async (...params) => {
          const result = await query(pgSqlForRun(sql), params);
          return { lastInsertRowid: result.rows?.[0]?.id == null ? 0 : Number(result.rows[0].id), changes: Number(result.rowCount ?? 0) };
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

#!/usr/bin/env node
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const databaseUrl = String(process.env.DATABASE_URL ?? "").trim();
if (!/^postgres(?:ql)?:\/\//i.test(databaseUrl)) {
  console.error("DATABASE_URL must be a hosted PostgreSQL connection string. PGlite and SQLite are rehearsal/dev only.");
  process.exit(2);
}

const pgModule = await import("pg");
const pg = pgModule.default ?? pgModule;
const client = new pg.Client({ connectionString: databaseUrl, connectionTimeoutMillis: 10_000 });
try {
  await client.connect();
  await client.query("BEGIN");
  await client.query(fs.readFileSync(path.join(ROOT, "scripts/schema.postgres.sql"), "utf8"));
  await client.query("SELECT 1 FROM meta LIMIT 1");
  await client.query("COMMIT");
  console.log("[schema] PostgreSQL schema applied and meta table verified. No application/customer data was copied.");
} catch (error) {
  try { await client.query("ROLLBACK"); } catch { /* preserve the schema error */ }
  console.error("[schema] Failed to apply PostgreSQL schema:", error instanceof Error ? error.message : String(error));
  process.exitCode = 1;
} finally {
  await client.end().catch(() => {});
}

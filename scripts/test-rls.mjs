#!/usr/bin/env node
/**
 * Row-level security: the second lock on the shop's database (migration 020, master list T-163).
 *
 *   node scripts/test-rls.mjs
 *
 * The database is hosted by Supabase, which serves the public schema over HTTPS to anyone holding the project's
 * publishable key. The first lock takes every privilege from the two roles behind that API. This suite is about
 * the second lock, and its central assertion is the one a GRANT cannot undo:
 *
 *   **a role that has been granted everything on every table still sees no row and can write none.**
 *
 * The rest guards the ways such a lock goes wrong:
 *
 *   • It must not lock the application out. The application is the owner of the tables; the owner reads and
 *     writes as before.
 *   • It must refuse to run as somebody who is not the owner — applied like that, the shop would serve an
 *     empty catalogue the moment the migration is committed.
 *   • It must cover every table, including the ones a later migration creates. This suite builds the complete
 *     schema from every migration file, so a new table without row-level security fails here.
 *   • It must be possible to take off again, without touching policies that somebody else added.
 *
 * Runs on the embedded PostgreSQL (PGlite): row-level security does not exist on SQLite.
 */
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { ROOT, createPgliteDatabase, openPglite } from './lib/pg-fixture.mjs';

const work = fs.mkdtempSync(path.join(os.tmpdir(), 'woodex-rls-'));
const problems = [];
function check(ok, label, detail = '') {
  if (ok) console.log(`  ✓ ${label}`);
  else { problems.push(`${label}${detail ? ` — ${detail}` : ''}`); console.log(`  ✗ ${label}${detail ? ` — ${detail}` : ''}`); }
}
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8');
const MIGRATION = 'scripts/migrations/020_row_level_security.sql';
const ROLLBACK = 'scripts/migrations/rollback/020_row_level_security.rollback.sql';
const POLICY = 'no_access_through_the_data_api';

/** Every table of the schema with whether row-level security is on and which policies it carries. */
const state = (db) => db.q(`
  SELECT c.relname AS name, c.relrowsecurity AS rls, c.relforcerowsecurity AS forced,
         COALESCE((SELECT string_agg(p.policyname, ',' ORDER BY p.policyname) FROM pg_policies p
                    WHERE p.schemaname = n.nspname AND p.tablename = c.relname), '') AS policies
    FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
   WHERE n.nspname = current_schema() AND c.relkind IN ('r', 'p')
   ORDER BY c.relname`);
/** Run statements as another role, and come back to the owner whatever happens. */
async function as(db, role, run) {
  await db.exec(`SET ROLE ${role}`);
  try { return await run(); } finally { await db.exec('RESET ROLE'); }
}
const refused = async (run) => { try { await run(); return null; } catch (error) { return String(error.message || error); } };

let db;
try {
  console.log('Row-level security on the complete schema (every migration file)');
  const dir = path.join(work, 'pg');
  await createPgliteDatabase(dir);
  db = await openPglite(dir);

  const tables = await state(db);
  const ledger = await db.q('SELECT count(*)::int AS n FROM schema_migrations');
  check(tables.length >= 73, `the schema holds ${tables.length} tables`, 'fewer than the 73 of the live database');
  const open = tables.filter((table) => !table.rls);
  check(open.length === 0, 'every table has row-level security switched on',
    `${open.length} without: ${open.map((table) => table.name).join(', ')} — a migration that creates a table must enable it and add the policy (see ${MIGRATION})`);
  const bare = tables.filter((table) => !table.policies.split(',').includes(POLICY));
  check(bare.length === 0, `every table carries the policy "${POLICY}"`, `${bare.length} without: ${bare.map((table) => table.name).join(', ')}`);
  check(tables.every((table) => !table.forced), 'it is not forced on the owner of the tables (the application is the owner)');

  console.log('\nThe owner — the application — works as before');
  check(ledger[0].n >= 20, `the owner reads the migration ledger (${ledger[0].n} rows)`);
  await db.exec('BEGIN');
  await db.q("INSERT INTO schema_migrations(version,name,checksum,applied_at,duration_ms) VALUES(9999,'probe','x','now',0)");
  const written = await db.q('SELECT count(*)::int AS n FROM schema_migrations WHERE version = 9999');
  await db.exec('ROLLBACK');
  check(written[0].n === 1, 'the owner writes');

  console.log('\nA role that was granted everything still gets nothing');
  await db.exec('CREATE ROLE data_api_probe NOLOGIN');
  // The worst case: somebody opens the first lock again.
  await db.exec('GRANT USAGE ON SCHEMA public TO data_api_probe; GRANT ALL ON ALL TABLES IN SCHEMA public TO data_api_probe; GRANT ALL ON ALL SEQUENCES IN SCHEMA public TO data_api_probe');
  const seen = await as(db, 'data_api_probe', () => db.q('SELECT count(*)::int AS n FROM schema_migrations'));
  check(seen[0].n === 0, 'it reads no row of a table that holds rows', `it read ${seen[0].n}`);
  const insert = await refused(() => as(db, 'data_api_probe', () => db.q("INSERT INTO schema_migrations(version,name,checksum,applied_at,duration_ms) VALUES(9998,'probe','x','now',0)")));
  check(insert !== null && /row-level security/i.test(insert), 'it cannot insert', insert ?? 'the insert went through');
  await as(db, 'data_api_probe', () => db.q("UPDATE schema_migrations SET name = 'changed'"));
  await as(db, 'data_api_probe', () => db.q('DELETE FROM schema_migrations'));
  const after = await db.q("SELECT count(*)::int AS n, count(*) FILTER (WHERE name = 'changed')::int AS changed FROM schema_migrations");
  check(after[0].n === ledger[0].n && after[0].changed === 0, 'its UPDATE and DELETE touch no row');
  const everyTable = await as(db, 'data_api_probe', async () => {
    let rows = 0;
    for (const table of tables) rows += (await db.q(`SELECT count(*)::int AS n FROM "${table.name}"`))[0].n;
    return rows;
  });
  check(everyTable === 0, `it reads no row of any of the ${tables.length} tables`, `it read ${everyTable}`);

  console.log('\nIt refuses to run as somebody who is not the owner');
  const guard = await refused(() => as(db, 'data_api_probe', () => db.exec(read(MIGRATION))));
  check(guard !== null && /migration 020 refused/.test(guard), 'the migration stops with its reason and changes nothing', guard ?? 'it ran');

  console.log('\nIt can be run twice, taken off, and put back');
  await db.exec(read(MIGRATION));
  const twice = await state(db);
  check(twice.every((table) => table.rls && table.policies === tables.find((first) => first.name === table.name).policies), 'a second run changes nothing');
  // A policy somebody else added must survive the rollback, with row-level security on that table.
  await db.exec('CREATE POLICY somebody_elses ON schema_migrations FOR SELECT TO data_api_probe USING (true)');
  await db.exec(read(ROLLBACK));
  const undone = await state(db);
  const kept = undone.find((table) => table.name === 'schema_migrations');
  const others = undone.filter((table) => table.name !== 'schema_migrations');
  check(others.every((table) => !table.rls && table.policies === ''), 'the rollback switches it off and drops the policy');
  check(kept.rls && kept.policies === 'somebody_elses', 'a table with somebody else\'s policy keeps that policy and stays locked');
  const exposed = await as(db, 'data_api_probe', () => db.q('SELECT count(*)::int AS n FROM schema_migrations'));
  check(exposed[0].n === ledger[0].n, 'without the lock the granted role reads the rows (so the lock is what stopped it)');
  await db.exec('DROP POLICY somebody_elses ON schema_migrations');
  await db.exec(read(MIGRATION));
  const again = await state(db);
  check(again.every((table) => table.rls && table.policies.split(',').includes(POLICY)), 'applied again, every table is locked again');
} catch (error) {
  problems.push(`the suite stopped: ${error instanceof Error ? error.message : String(error)}`);
  console.log(`  ✗ the suite stopped: ${error instanceof Error ? error.stack : String(error)}`);
} finally {
  try { await db?.close(); } catch { /* nothing to close */ }
  fs.rmSync(work, { recursive: true, force: true });
}

if (problems.length) {
  console.error(`\n${problems.length} problem(s):\n  ${problems.join('\n  ')}`);
  process.exit(1);
}
console.log('\nRow-level security holds.');

#!/usr/bin/env node
/**
 * A fixed search path for every function of the shop's database (migration 021, master list T-172).
 *
 *   node scripts/test-search-path.mjs
 *
 * A function that names a table or another function without its schema looks the name up along the search
 * path of the session that calls it. Migration 021 gives each function of the schema a path of its own. The
 * central assertion of this suite is the one that stays true for functions nobody has written yet:
 *
 *   **after every migration, no function of the schema is without a search path.**
 *
 * The rest guards the ways this goes wrong, and tries each claim both ways:
 *
 *   • What it is for. With another schema in front of the session's path, an entry of the books that does not
 *     balance is refused, and the comma-joined list comes out right. The same statements with the migration
 *     taken off keep the entry and hand out the other schema's answer: so the migration is what holds.
 *   • A function added later. The check names a new function without a path, and a function that
 *     CREATE OR REPLACE has just rewritten (which takes a setting away that it does not state).
 *   • The base schema file is run again and again. It must leave its three functions with their path.
 *   • One function left out of the migration is named.
 *   • On a database as the live one was (no function with a path): applied, applied twice, rolled back to the
 *     row, somebody else's path kept, applied again; and the same through the runner a person would use
 *     (scripts/migrate.mjs), with its ledger row.
 *   • The aggregate. PostgreSQL gives an aggregate no setting; this suite asks it to, and expects the refusal.
 *     If a later PostgreSQL accepts, the suite says so and the migration can be extended.
 *   • The text that was sent to the hosted database (deploy/supabase/). It holds the migration's block word for
 *     word and the checksum of the migration's file. Sent to a database in the state the live one was read in:
 *     kept, with row 21 as the runner would have written it; sent a second time, refused; with a guard that
 *     fails after the change was made, nothing kept.
 *
 * Runs on the embedded PostgreSQL (PGlite): SQLite has no functions of this kind.
 */
import { spawnSync } from 'node:child_process';
import crypto from 'node:crypto';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { ROOT, applyFullSchema, createPgliteDatabase, openPglite } from './lib/pg-fixture.mjs';

const work = fs.mkdtempSync(path.join(os.tmpdir(), 'woodex-search-path-'));
const problems = [];
function check(ok, label, detail = '') {
  if (ok) console.log(`  ✓ ${label}`);
  else { problems.push(`${label}${detail ? ` — ${detail}` : ''}`); console.log(`  ✗ ${label}${detail ? ` — ${detail}` : ''}`); }
}
const read = (file) => fs.readFileSync(path.join(ROOT, file), 'utf8');
const BASE = 'scripts/schema.postgres.sql';
const MIGRATION = 'scripts/migrations/021_function_search_path.sql';
const ROLLBACK = 'scripts/migrations/rollback/021_function_search_path.rollback.sql';
const SENT = 'deploy/supabase/2026-11-20-apply-021-function-search-path.sql';

/** The nine functions of migration 021, as PostgreSQL writes their names. */
const NINE = [
  'activities_are_append_only()',
  'datetime(text)',
  'dispatch_runs_keep_departure()',
  'journal_entries_header_is_stable()',
  'journal_entry_must_balance()',
  'journal_lines_are_immutable()',
  'tickets_freeze_sla()',
  'wx_gc_final(text[])',
  'wx_gc_trans(text[],text)',
];

/**
 * Every routine of the schema that no extension owns: its kind ('f' function, 'a' aggregate, 'p' procedure,
 * 'w' window), its search path ('' when it has none) and a print of its whole catalogue row.
 */
const routines = (db) => db.q(`
  SELECT p.oid::regprocedure::text AS routine, p.proname AS name, p.prokind AS kind,
         COALESCE((SELECT c FROM unnest(p.proconfig) AS c WHERE c LIKE 'search_path=%' LIMIT 1), '') AS path,
         md5(to_jsonb(p)::text) AS print
    FROM pg_proc p JOIN pg_namespace n ON n.oid = p.pronamespace
   WHERE n.nspname = current_schema()
     AND NOT EXISTS (SELECT 1 FROM pg_depend d
                      WHERE d.classid = 'pg_proc'::regclass AND d.objid = p.oid AND d.deptype = 'e')
   ORDER BY 1`);
/** What the central assertion reads: the functions (not the aggregates) that have no path of their own. */
const withoutPath = (rows) => rows.filter((row) => row.kind !== 'a' && row.path === '').map((row) => row.routine);
/** A path is fixed when it does not follow the role that calls: no "$user" in it. */
const followsTheRole = (rows) => rows.filter((row) => row.path.includes('$user')).map((row) => row.routine);
const prints = (rows) => Object.fromEntries(rows.map((row) => [row.routine, row.print]));
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
const refused = async (run) => { try { await run(); return null; } catch (error) { return String(error.message || error); } };
/** The triggers of the schema with the function each one runs. */
const triggers = (db) => db.q(`
  SELECT t.tgname AS name, c.relname AS on_table, p.proname AS runs, t.tgenabled::text AS enabled
    FROM pg_trigger t JOIN pg_class c ON c.oid = t.tgrelid JOIN pg_namespace n ON n.oid = c.relnamespace
    JOIN pg_proc p ON p.oid = t.tgfoid
   WHERE n.nspname = current_schema() AND NOT t.tgisinternal ORDER BY 1`);

/**
 * Run statements in a transaction that is always rolled back, and hand out what `run` returned.
 * Changes of the catalogue are part of a transaction in PostgreSQL, so "the migration taken off" inside
 * here leaves nothing behind.
 */
async function undone(db, run) {
  await db.exec('BEGIN');
  try { return await run(); } finally { await db.exec('ROLLBACK'); }
}

/**
 * Two lines for one entry, 100 against 60, written by a session whose path puts another schema first; the
 * check of the books is then asked for at once instead of at the end. Null when the entry is kept, the
 * refusal's text when it is refused.
 */
const unbalancedEntry = (db) => refused(async () => {
  await db.exec("SET LOCAL search_path = elsewhere, public");
  await db.exec(`INSERT INTO public.journal_entries(id, ref, entry_date, created_at) VALUES (9001, 'JE-PROBE', '2026-11-20', '2026-11-20T00:00:00Z');
                 INSERT INTO public.journal_lines(entry_id, account_id, debit, credit) VALUES (9001, 9001, 100, 0), (9001, 9002, 0, 60);
                 SET CONSTRAINTS ALL IMMEDIATE`);
});
/** The comma-joined list of two values, asked by a session whose path puts another schema before the system's. */
const joinedList = async (db) => {
  await db.exec('SET LOCAL search_path = elsewhere, pg_catalog, public');
  return (await db.q("SELECT public.group_concat(x) AS joined FROM (VALUES ('a'), ('b')) v(x)"))[0].joined;
};

let db;
try {
  /* ── 1 ─────────────────────────────────────────────────────────────────────────────────────────────── */
  console.log('The complete schema (the base file and every migration)');
  const dir = path.join(work, 'pg');
  const built = await createPgliteDatabase(dir);
  db = await openPglite(dir);
  const home = (await db.q('SELECT current_schema() AS s, current_setting(\'server_version\') AS v'))[0];
  const OURS = `search_path=${home.s}, pg_temp`;
  console.log(`  (PostgreSQL ${home.v}, schema "${home.s}")`);

  check(built.applied.includes('021_function_search_path.sql'), 'migration 021 is applied with the others');
  const complete = await routines(db);
  const functions = complete.filter((row) => row.kind === 'f');
  const aggregates = complete.filter((row) => row.kind === 'a');
  check(functions.length >= 9 && aggregates.length >= 1, `the schema holds ${functions.length} functions and ${aggregates.length} aggregate`,
    'fewer than the nine functions and one aggregate of the live database');
  const bare = withoutPath(complete);
  check(bare.length === 0, 'no function of the schema is without a search path',
    `${bare.length} without: ${bare.join(', ')} — write "SET search_path = ${home.s}, pg_temp" into the CREATE FUNCTION (see ${MIGRATION})`);
  check(followsTheRole(complete).length === 0, 'no path follows the role that calls ("$user")', followsTheRole(complete).join(', '));
  const nine = complete.filter((row) => NINE.includes(row.routine));
  check(nine.length === 9 && nine.every((row) => row.path === OURS), `each of the nine has "${OURS}"`,
    nine.filter((row) => row.path !== OURS).map((row) => `${row.routine}: ${row.path || 'none'}`).join('; ') || `found ${nine.length} of 9`);
  const ledger = await db.q('SELECT version, name, checksum FROM schema_migrations ORDER BY version DESC LIMIT 1');
  check(ledger[0].version === 21 && ledger[0].name === '021_function_search_path.sql', 'the ledger\'s last row is migration 021');
  const before = await triggers(db);
  check(before.length === 6 && before.every((row) => row.enabled === 'O'), 'the six triggers are attached and switched on', JSON.stringify(before));

  console.log('\nThe aggregate takes no setting; the two functions it runs have theirs');
  for (const statement of [
    `ALTER FUNCTION group_concat(text) SET search_path = ${home.s}, pg_temp`,
    `ALTER ROUTINE group_concat(text) SET search_path = ${home.s}, pg_temp`,
    `ALTER AGGREGATE group_concat(text) SET search_path = ${home.s}, pg_temp`,
  ]) {
    const answer = await refused(() => db.exec(statement));
    check(answer !== null, `PostgreSQL refuses "${statement.split(' SET ')[0]} SET …"`,
      'it was accepted: this PostgreSQL gives an aggregate a setting, so the migration can name group_concat too');
  }
  const helpers = await db.q(`
    SELECT a.aggfnoid::regprocedure::text AS aggregate, f.oid::regprocedure::text AS helper,
           COALESCE((SELECT c FROM unnest(f.proconfig) AS c WHERE c LIKE 'search_path=%' LIMIT 1), '') AS path
      FROM pg_aggregate a JOIN pg_proc p ON p.oid = a.aggfnoid JOIN pg_namespace n ON n.oid = p.pronamespace
      JOIN pg_proc f ON f.oid IN (a.aggtransfn, a.aggfinalfn, a.aggcombinefn, a.aggserialfn, a.aggdeserialfn, a.aggmtransfn, a.aggminvtransfn, a.aggmfinalfn)
      JOIN pg_namespace fn ON fn.oid = f.pronamespace
     WHERE n.nspname = current_schema() AND fn.nspname = current_schema() ORDER BY 1, 2`);
  check(helpers.length === 2 && helpers.every((row) => row.path !== ''), 'every function an aggregate of the schema runs has a path',
    helpers.map((row) => `${row.aggregate} → ${row.helper}: ${row.path || 'none'}`).join('; '));
  /* Supabase's security advisor, lint 0011 (github.com/supabase/splinter): every row of pg_proc in the schema
     whose settings hold no search_path, aggregates included. This is what it goes on listing. */
  const advisor = complete.filter((row) => row.path === '').map((row) => row.routine);
  check(same(advisor, ['group_concat(text)']), 'the advisor\'s rule is left with one name, the aggregate', advisor.join(', ') || 'none');

  /* ── 2 ─────────────────────────────────────────────────────────────────────────────────────────────── */
  console.log('\nA function added later without a path is named');
  await db.exec('CREATE FUNCTION added_later_without_a_path() RETURNS integer LANGUAGE sql AS $$ SELECT 1 $$');
  check(same(withoutPath(await routines(db)), ['added_later_without_a_path()']), 'a new function without one is named by the check');
  await db.exec('DROP FUNCTION added_later_without_a_path()');
  await db.exec(`CREATE FUNCTION added_later_with_a_path() RETURNS integer LANGUAGE sql SET search_path = ${home.s}, pg_temp AS $$ SELECT 1 $$`);
  check(withoutPath(await routines(db)).length === 0, 'a new function that states its path is not');
  await db.exec('DROP FUNCTION added_later_with_a_path()');
  await db.exec(`CREATE OR REPLACE FUNCTION tickets_freeze_sla() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RETURN NEW; END; $$`);
  check(same(withoutPath(await routines(db)), ['tickets_freeze_sla()']), 'CREATE OR REPLACE without the setting takes the path away, and the check names the function');
  await db.exec(`CREATE OR REPLACE FUNCTION tickets_freeze_sla() RETURNS trigger LANGUAGE plpgsql SET search_path = ${home.s}, pg_temp AS $$
BEGIN
  IF TG_OP = 'UPDATE' AND OLD.resolve_by IS NOT NULL AND NEW.resolve_by IS DISTINCT FROM OLD.resolve_by THEN
    RAISE EXCEPTION 'the SLA deadline is set once, from the priority at intake: moving it after the fact makes a breach disappear'
      USING ERRCODE = 'P0001';
  END IF;
  RETURN NEW;
END;
$$`);
  check(same(prints(await routines(db)), prints(complete)), 'written again with its setting and its own body, the catalogue reads as before');

  /* ── 3 ─────────────────────────────────────────────────────────────────────────────────────────────── */
  console.log('\nWhat it is for: another schema in front of the session\'s path');
  await db.exec(`CREATE SCHEMA elsewhere;
    CREATE TABLE elsewhere.journal_lines(entry_id BIGINT, debit NUMERIC(14, 2), credit NUMERIC(14, 2));
    CREATE FUNCTION elsewhere.array_to_string(text[], text) RETURNS text LANGUAGE sql AS $$ SELECT 'the other schema answered'::text $$;
    INSERT INTO public.accounts(id, code, name, kind, active, created_at, updated_at) VALUES
      (9001, 'T-9001', 'Probe: cash', 'asset', 1, '2026-11-20T00:00:00Z', '2026-11-20T00:00:00Z'),
      (9002, 'T-9002', 'Probe: sales', 'revenue', 1, '2026-11-20T00:00:00Z', '2026-11-20T00:00:00Z')`);
  const refusedEntry = await undone(db, () => unbalancedEntry(db));
  check(refusedEntry !== null && /does not balance/.test(refusedEntry), 'an entry of 100 against 60 is refused, whatever the session\'s path', refusedEntry ?? 'the entry was kept');
  const keptEntry = await undone(db, async () => { await db.exec(read(ROLLBACK)); return unbalancedEntry(db); });
  check(keptEntry === null, 'the same statements with the migration taken off keep it (so the migration is what refuses)', keptEntry ?? '');
  const rightList = await undone(db, () => joinedList(db));
  check(rightList === 'a,b', 'the comma-joined list is joined by PostgreSQL\'s own function', `it read "${rightList}"`);
  const otherList = await undone(db, async () => { await db.exec(read(ROLLBACK)); return joinedList(db); });
  check(otherList === 'the other schema answered', 'with the migration taken off, the other schema\'s function answers', `it read "${otherList}"`);
  check(same(prints(await routines(db)), prints(complete)), 'none of these tries left anything behind');
  const stamp = await db.q("SELECT datetime('')::text AS empty, datetime('2026-11-20T06:00:00.000Z')::text AS stamp");
  check(stamp[0].empty === null && stamp[0].stamp === '2026-11-20 06:00:00', 'datetime() reads a time as before, and an empty text as nothing', JSON.stringify(stamp[0]));

  /* ── 4 ─────────────────────────────────────────────────────────────────────────────────────────────── */
  console.log('\nThe base schema file is run again and again');
  await db.close();
  db = await openPglite(dir);
  const again = await applyFullSchema(db);
  check(again.applied.length === 0, 'a second build applies no migration');
  check(same(prints(await routines(db)), prints(complete)), 'and leaves every function as it was, with its path');
  const baseWithoutItsLines = read(BASE).replace(/DO \$\$ BEGIN\s+EXECUTE format\('ALTER FUNCTION %I\.datetime\(text\) SET search_path[\s\S]*?END \$\$;/, '');
  check(baseWithoutItsLines !== read(BASE), 'the base file sets the path of its three functions itself');
  await db.exec(baseWithoutItsLines);
  check(same(withoutPath(await routines(db)), ['datetime(text)', 'wx_gc_final(text[])', 'wx_gc_trans(text[],text)']),
    'without those lines a second run takes the path off the three (so the lines are what keeps it)', withoutPath(await routines(db)).join(', ') || 'none was named');
  await db.exec(read(BASE));
  check(same(prints(await routines(db)), prints(complete)), 'the file as it is puts them back');

  /* ── 5 ─────────────────────────────────────────────────────────────────────────────────────────────── */
  console.log('\nOn a database as the live one was: no function with a path');
  await db.exec(read(ROLLBACK));
  const asLive = await routines(db);
  check(asLive.length === 10 && asLive.every((row) => row.path === ''), 'nine functions and one aggregate, none with a search path (as read from the live database)',
    asLive.filter((row) => row.path !== '').map((row) => row.routine).join(', '));
  check(same(withoutPath(asLive), NINE), 'the check names all nine');

  const shortened = read(MIGRATION).replace(/,\s*\n\s*-- 016 support[^\n]*\n\s*'tickets_freeze_sla\(\)'/, '');
  check(shortened !== read(MIGRATION), '(the migration text with one function taken out of its list)');
  const leftOut = await undone(db, async () => { await db.exec(shortened); return withoutPath(await routines(db)); });
  check(same(leftOut, ['tickets_freeze_sla()']), 'with one function left out of the migration, the check names it', leftOut.join(', ') || 'none was named');

  await db.exec(read(MIGRATION));
  const applied = await routines(db);
  check(withoutPath(applied).length === 0 && same(prints(applied), prints(complete)), 'applied: every function has its path, and the catalogue reads as on a new database');
  await db.exec(read(MIGRATION));
  check(same(prints(await routines(db)), prints(applied)), 'a second run changes nothing');
  check(same(await triggers(db), before), 'the six triggers are as they were');

  await db.exec(read(ROLLBACK));
  check(same(prints(await routines(db)), prints(asLive)), 'the rollback brings back the earlier state, row for row');
  await db.exec(read(ROLLBACK));
  check(same(prints(await routines(db)), prints(asLive)), 'a second rollback changes nothing');

  await db.exec(read(MIGRATION));
  await db.exec("ALTER FUNCTION activities_are_append_only() SET search_path = ''");
  await db.exec(read(ROLLBACK));
  const kept = await routines(db);
  check(kept.find((row) => row.routine === 'activities_are_append_only()').path === 'search_path=""'
    && kept.filter((row) => row.routine !== 'activities_are_append_only()').every((row) => row.path === ''),
    'the rollback leaves a path that somebody else set, and takes off the other eight');
  await db.exec(read(MIGRATION));
  check(same(prints(await routines(db)), prints(complete)), 'applied again, each of the nine has the migration\'s path again');

  /* ── 6 ─────────────────────────────────────────────────────────────────────────────────────────────── */
  console.log('\nThrough the runner a person would use against the hosted database (scripts/migrate.mjs)');
  await db.close();
  db = null;
  const runner = (...args) => spawnSync(process.execPath, ['scripts/migrate.mjs', '--pglite', dir, ...args], { cwd: ROOT, encoding: 'utf8' });
  const status = runner('--status');
  check(status.status === 0 && /021\s+applied/.test(status.stdout) && /Ledger consistent/.test(status.stdout), 'it reads 21 migrations applied and a consistent ledger', (status.stdout + status.stderr).trim().split('\n').slice(-3).join(' | '));
  const back = runner('--rollback', '021');
  check(back.status === 0 && /rolled back 021/.test(back.stdout), '--rollback 021 runs the rollback file and takes row 21 out of the ledger', (back.stdout + back.stderr).trim().split('\n').slice(-3).join(' | '));
  db = await openPglite(dir);
  const rolledBack = await routines(db);
  const rows = await db.q('SELECT count(*)::int AS n, max(version)::int AS last FROM schema_migrations');
  check(same(prints(rolledBack), prints(asLive)) && rows[0].n === 20 && rows[0].last === 20, 'after it: no function with a path, 20 rows in the ledger');
  await db.close();
  db = null;
  const forward = runner();
  check(forward.status === 0 && /1 migration\(s\) applied/.test(forward.stdout), 'run again, it applies 021 and nothing else', (forward.stdout + forward.stderr).trim().split('\n').slice(-3).join(' | '));
  db = await openPglite(dir);
  const forwardRows = await db.q('SELECT count(*)::int AS n, max(version)::int AS last FROM schema_migrations');
  check(same(prints(await routines(db)), prints(complete)) && forwardRows[0].n === 21 && forwardRows[0].last === 21, 'after it: every function has its path, 21 rows in the ledger');

  /* ── 7 ─────────────────────────────────────────────────────────────────────────────────────────────── */
  console.log('\nThe text that was sent to the hosted database');
  const sent = read(SENT);
  const statement = sent.slice(sent.indexOf('DO $apply$'));
  const migrationBlock = read(MIGRATION).slice(read(MIGRATION).indexOf('\nDO $$\n') + 1).trimEnd();
  const checksum = crypto.createHash('sha256').update(read(MIGRATION)).digest('hex');
  check(statement.startsWith('DO $apply$') && statement.trimEnd().endsWith('$apply$;') && !/^\s*(BEGIN|COMMIT)\s*;/m.test(statement),
    'it is one statement: all of it is kept, or nothing');
  check(migrationBlock.startsWith('DO $$') && statement.includes(`EXECUTE $migration$\n${migrationBlock}\n$migration$;`), 'it holds the migration\'s block, word for word');
  check(statement.includes(`'021_function_search_path.sql', '${checksum}'`), 'it writes row 21 with the checksum of the migration\'s file');

  // A database as the live one was read: 20 rows in the ledger, ten routines, none with a setting.
  const ledgerState = async () => (await db.q('SELECT count(*)::int AS n, max(version)::int AS last FROM schema_migrations'))[0];
  await db.exec(read(ROLLBACK));
  await db.exec('DELETE FROM schema_migrations WHERE version = 21');
  check(same(prints(await routines(db)), prints(asLive)) && (await ledgerState()).n === 20, '(a database as the live one was read: 20 rows in the ledger, no function with a path)');

  await db.exec("ALTER FUNCTION tickets_freeze_sla() SET search_path = ''");
  const notAsRead = await refused(() => db.exec(statement));
  check(notAsRead !== null && /migration 021 not sent: 1 routine\(s\) of the schema have a setting already/.test(notAsRead), 'a database that is not as it was read is left alone', notAsRead ?? 'it ran');
  await db.exec('ALTER FUNCTION tickets_freeze_sla() RESET search_path');
  check(same(prints(await routines(db)), prints(asLive)) && (await ledgerState()).n === 20, 'and nothing of it is kept');

  await db.exec('DROP TRIGGER trg_tickets_freeze_sla ON tickets');
  const guard = await refused(() => db.exec(statement));
  check(guard !== null && /migration 021 not kept: 5 triggers are attached/.test(guard), 'a guard that fails after the change was made stops it with its reason', guard ?? 'it ran');
  check(same(prints(await routines(db)), prints(asLive)) && (await ledgerState()).n === 20, 'and nothing is kept: no function with a path, 20 rows in the ledger');
  await db.exec('CREATE TRIGGER trg_tickets_freeze_sla BEFORE UPDATE ON tickets FOR EACH ROW EXECUTE FUNCTION tickets_freeze_sla()');
  check(same(await triggers(db), before), '(the trigger put back)');

  await db.exec(statement);
  const row21 = (await db.q('SELECT version, name, checksum, applied_at, duration_ms FROM schema_migrations ORDER BY version DESC LIMIT 1'))[0];
  check(same(prints(await routines(db)), prints(complete)) && (await ledgerState()).n === 21, 'sent as it is: every function has its path, 21 rows in the ledger');
  check(row21.version === 21 && row21.name === '021_function_search_path.sql' && row21.checksum === checksum && /^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\.\d{3}Z$/.test(row21.applied_at) && Number.isInteger(row21.duration_ms),
    'row 21 is as the runner writes it: the file\'s name, its checksum, the time in UTC, the milliseconds', JSON.stringify(row21));
  const second = await refused(() => db.exec(statement));
  check(second !== null && /migration 021 not sent: the ledger holds 21 rows/.test(second), 'sent a second time, it is refused', second ?? 'it ran');
  check(same(prints(await routines(db)), prints(complete)) && (await ledgerState()).n === 21, 'and changes nothing');
  await db.close();
  db = null;
  const afterwards = runner('--status');
  check(afterwards.status === 0 && /021\s+applied/.test(afterwards.stdout) && /Ledger consistent/.test(afterwards.stdout), 'the runner then reads the ledger as consistent: the row written by hand is the row it expects',
    (afterwards.stdout + afterwards.stderr).trim().split('\n').slice(-3).join(' | '));
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
console.log('\nEvery function of the schema has a fixed search path.');

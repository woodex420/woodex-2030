#!/usr/bin/env node
/**
 * WOODEX OS: the check of the table map (scripts/verify-table-map.mjs), tried against what it is there to stop.
 *
 * A check that passes is worth something only if it can fail. This copies the check and everything it reads
 * into a temporary folder, changes one thing at a time, and requires two things each time: the check fails, and
 * it names the reason. The working tree is never touched.
 *
 *   node scripts/test-verify-table-map.mjs
 */
import { spawnSync } from 'node:child_process';
import { cpSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const MAP = 'docs/woodex-os/acceptance/tables.json';
const DOCUMENT = 'docs/woodex-os/36-OLD-TABLES-AND-THEIR-LARAVEL-OWNERS.md';
const LIST = 'docs/woodex-os/14-PRODUCTION-TODO.md';
const OLD = 'scripts/migrations';
const NEW = 'platform/database/migrations';
const copied = ['scripts/verify-table-map.mjs', MAP, DOCUMENT, LIST, 'scripts/schema.postgres.sql', 'scripts/migrate.mjs', OLD, NEW,
  'platform/config/permission.php', 'platform/app/Support/AdminNavigation.php'];

const work = mkdtempSync(path.join(tmpdir(), 'woodex-tables-'));
const at = (file) => path.join(work, file);
const fresh = () => {
  rmSync(at('.'), { recursive: true, force: true });
  for (const file of copied) {
    mkdirSync(path.dirname(at(file)), { recursive: true });
    // Rollback files are no part of what the check reads; the folder is copied whole all the same.
    cpSync(path.join(root, file), at(file), { recursive: true });
  }
};
const read = (file) => readFileSync(at(file), 'utf8');
const save = (file, text) => writeFileSync(at(file), text);
const check = (...args) => {
  const run = spawnSync(process.execPath, [at('scripts/verify-table-map.mjs'), ...args], { encoding: 'utf8' });
  return { code: run.status, output: `${run.stdout}${run.stderr}` };
};

/** Fails loudly when a case no longer finds what it changes: a case that changes nothing proves nothing. */
const must = (condition, what) => {
  if (!condition) throw new Error(`the case cannot be set up: ${what}`);
};
const map = (change) => {
  const data = JSON.parse(read(MAP));
  const before = JSON.stringify(data);
  change(data);
  must(JSON.stringify(data) !== before, 'the map was not changed');
  save(MAP, `${JSON.stringify(data, null, 1)}\n`);
};
const rowOf = (data, name) => {
  const row = data.tables.find((candidate) => candidate.table === name);
  must(row, `${name} has no row in the map`);
  return row;
};
/** The file of the admin's migrations that creates a table. */
const migrationOf = (name) => {
  const file = readdirSync(at(NEW)).find((candidate) => read(`${NEW}/${candidate}`).includes(`Schema::create('${name}'`));
  must(file, `no migration of the admin creates ${name} by that name`);
  return `${NEW}/${file}`;
};

// The rows the cases use are read from the map, so that the cases keep working as the map moves on.
fresh();
const source = JSON.parse(read(MAP));
const moving = source.tables.find((row) => row.kind === 'moves' && (row.to ?? []).length === 1 && row.to[0] !== row.table);
const waiting = source.tables.find((row) => row.kind === 'waits');
const staying = source.tables.find((row) => row.kind === 'stays' && (row.instead ?? []).length);
must(moving && waiting && staying, 'the map has no row of each kind');
const doneLine = read(LIST).split('\n').map((line) => line.match(/^\| (T-\d{3}) \|.*\| \*\*Done\*\*/)?.[1]).find(Boolean);
must(doneLine, 'the master list has no line that is done');

const cases = [
  ['a row taken out of the map', () => map((data) => { data.tables = data.tables.filter((row) => row.table !== waiting.table); }),
    [`${waiting.table}: is created by the old schema files`, 'has no row in']],
  ['a row written twice', () => map((data) => { data.tables.push({ ...rowOf(data, moving.table) }); }),
    [`${moving.table}: has 2 rows`]],
  ['a row for a table that no schema file creates', () => map((data) => { data.tables.push({ ...rowOf(data, waiting.table), table: 'never_created' }); }),
    ['never_created: has a row, but no schema file of the old system creates it']],
  ['a migration of the old system that creates a table without a row', () => {
    const file = `${OLD}/${readdirSync(at(OLD)).filter((name) => name.endsWith('.sql')).sort().at(-1)}`;
    save(file, `${read(file)}\nCREATE TABLE IF NOT EXISTS added_by_a_later_migration(id BIGINT PRIMARY KEY);\n`);
  }, ['added_by_a_later_migration: is created by the old schema files', 'has no row in']],
  ['a table named only in a comment of a schema file is not a table', () => {
    save('scripts/schema.postgres.sql', `${read('scripts/schema.postgres.sql')}\n-- every index must come after its CREATE TABLE above\n/* CREATE TABLE in_a_comment(id BIGINT); */\n`);
  }, null],
  ['the wrong schema file as its origin', () => map((data) => { rowOf(data, moving.table).from = moving.from === '005' ? '006' : '005'; }),
    [`${moving.table}: is said to come from`]],
  ['rows sent into a table the admin does not create', () => map((data) => { rowOf(data, moving.table).to = ['a_table_nobody_creates']; }),
    [`${moving.table}: names the table "a_table_nobody_creates" of the admin, which no migration`]],
  ['the migration that creates a target is gone', () => { rmSync(at(migrationOf(moving.to[0]))); },
    [`names the table "${moving.to[0]}" of the admin, which no migration`]],
  ['"instead" names a table the admin does not create', () => map((data) => { rowOf(data, staying.table).instead = ['a_table_nobody_creates']; }),
    [`${staying.table}: names the table "a_table_nobody_creates" of the admin`]],
  ['a migration of the admin that names its table in a way the check cannot read', () => {
    const file = migrationOf(moving.to[0]);
    save(file, read(file).replace(`Schema::create('${moving.to[0]}'`, `Schema::create($this->name()`));
  }, ['creates a table named by "$this->name()", which this check cannot read']],
  ['a migration of the admin that renames a table going forward', () => {
    const file = migrationOf(moving.to[0]);
    const text = read(file);
    must(text.includes('public function up(): void'), 'the migration has no up() written as expected');
    save(file, text.replace('public function up(): void', "public function up(): void\n    {\n        Schema::rename('old_name', 'new_name');\n    }\n\n    public function before(): void"));
  }, ['drops or renames a table going forward']],
  ['a wave that is not a wave of the list', () => map((data) => { rowOf(data, staying.table).line = 'T-181'; rowOf(data, staying.table).wave = 14; }),
    [`${staying.table}: the wave 14 is not a wave of the master list`]],
  ['a wave other than the one the list gives the line', () => map((data) => { rowOf(data, moving.table).wave = Number(moving.wave) + 1; }),
    [`${moving.table}: the wave is ${Number(moving.wave) + 1}, but the master list has ${moving.line} in wave ${moving.wave}`]],
  ['a line that is not on the list', () => map((data) => { rowOf(data, waiting.table).line = 'T-999'; }),
    [`${waiting.table}: the line T-999 is not on the master list`]],
  ['a line that is done, named by a row that still has rows to bring', () => map((data) => { rowOf(data, waiting.table).line = doneLine; }),
    [`${waiting.table}: still has rows to bring over, but ${doneLine} is done`]],
  ['a line without a wave', () => map((data) => { delete rowOf(data, waiting.table).wave; }),
    [`${waiting.table}: names no line of the master list and no wave`]],
  ['a destination that is not in its area', () => map((data) => { rowOf(data, moving.table).destination = 'A screen that is not there'; }),
    [`${moving.table}: "A screen that is not there" is not a destination of the area ${moving.area}`]],
  ['a screen of the admin renamed in its menu', () => {
    const file = 'platform/app/Support/AdminNavigation.php';
    must(read(file).includes(`'${moving.destination}'`), `the registry does not name ${moving.destination}`);
    save(file, read(file).replace(`'${moving.destination}'`, `'${moving.destination} renamed'`));
  }, [`"${moving.destination}" is not a destination of the area ${moving.area}`]],
  ['an area that is not an area of the admin', () => map((data) => { rowOf(data, moving.table).area = 'Nowhere'; }),
    [`${moving.table}: the area "Nowhere" is not an area of the admin`]],
  ['a row that stays without a reason', () => map((data) => { delete rowOf(data, staying.table).why; }),
    [`${staying.table}: stays, but does not say why`]],
  ['a row that moves without a target', () => map((data) => { delete rowOf(data, moving.table).to; }),
    [`${moving.table}: moves, but names no table of the admin`]],
  ['a row that waits and names a target', () => map((data) => { rowOf(data, waiting.table).to = [moving.to[0]]; }),
    [`${waiting.table}: waits for a table to be built, yet names`]],
  ['a row that does not say what the table holds', () => map((data) => { rowOf(data, moving.table).holds = ''; }),
    [`${moving.table}: does not say what the table holds`]],
  ['a kind that is not one of the three', () => map((data) => { rowOf(data, moving.table).kind = 'copied'; }),
    [`${moving.table}: the kind is "copied"`]],
  ['a key that is misspelt', () => map((data) => { const row = rowOf(data, moving.table); row.destinaton = row.destination; }),
    [`${moving.table}: the key "destinaton" is not one of`]],
  ['the live database had a table the map does not know', () => map((data) => { data.live.rows.made_by_hand_in_the_database = 3; data.live.tables += 1; }),
    ['made_by_hand_in_the_database: was in the live database when it was read', 'the map does not know it']],
  ['the reading of the live database counts wrongly', () => map((data) => { data.live.tables += 1; }),
    ['the reading of the live database says']],
  ['the reading of the live database has no time', () => map((data) => { data.live.read_at = 'this morning'; }),
    ['does not say when it was made']],
  ['document 36 no longer shows what the map says', () => map((data) => { rowOf(data, moving.table).holds = `${moving.holds} And one sentence more.`; }),
    ['the block "areas" does not show what the map says']],
  ['a block of document 36 without its marks', () => { save(DOCUMENT, read(DOCUMENT).replace('<!-- /generated:waves -->', '')); },
    ['the marks of the block "waves" are missing']],
];

let failed = 0;
const report = (ok, label, detail = '') => {
  console.log(`  ${ok ? '✓' : '✗'} ${label}${ok || !detail ? '' : `\n      ${detail}`}`);
  if (!ok) failed += 1;
};

try {
  fresh();
  const untouched = check();
  report(untouched.code === 0, 'the copy as it is passes', untouched.output.trim().split('\n').slice(-6).join('\n      '));

  for (const [label, change, reasons] of cases) {
    fresh();
    change();
    const { code, output } = check();
    if (reasons === null) {
      report(code === 0, `${label}: the check still passes`, output.trim().split('\n').slice(-4).join('\n      '));
      continue;
    }
    const missing = reasons.filter((reason) => !output.includes(reason));
    report(code === 1 && missing.length === 0, `${label}: the check fails and says why`,
      code !== 1 ? `the check ended with ${code}` : `it did not say: ${missing.join(' | ')}\n      it said: ${output.trim().split('\n').filter((line) => line.startsWith('  - ')).slice(0, 3).join(' ')}`);
  }

  console.log('\nA new reading of the live database, compared with the map');
  fresh();
  const kept = Object.entries(source.live.rows).map(([name, count]) => `${name}=${count}`).join(' ');
  save('reading.txt', `{"read_at": "2026-12-01T00:00:00Z", "rows_per_table": "${kept}"}`);
  const sameReading = check('--live', at('reading.txt'));
  report(sameReading.code === 0 && /not in the map none/.test(sameReading.output) && /rows changed since \S+: none/.test(sameReading.output),
    'the reading that is kept in the map compares as the same', sameReading.output.trim().split('\n').slice(0, 4).join(' | '));
  save('reading.txt', `${kept.replace(/\b(\w+)=(\d+)\b/, (whole, name, count) => `${name}=${Number(count) + 5}`)} made_by_hand_in_the_database=3`);
  const newReading = check('--live', at('reading.txt'));
  report(newReading.code === 1 && /not in the map made_by_hand_in_the_database/.test(newReading.output) && /rows changed since \S+: \w+ \d+ → \d+/.test(newReading.output),
    'a reading with one table more names it, and says which counts changed', newReading.output.trim().split('\n').slice(0, 4).join(' | '));
  const query = check('--query');
  report(query.code === 0 && /^SELECT /.test(query.output) && !/\b(INSERT|UPDATE|DELETE|ALTER|DROP|CREATE|TRUNCATE|GRANT)\b/i.test(query.output),
    'the statement that reads the live database only reads');
} finally {
  rmSync(work, { recursive: true, force: true });
}

if (failed) {
  console.error(`\n${failed} of ${cases.length + 4} cases did not behave as required.`);
  process.exit(1);
}
console.log(`\nThe check of the table map fails, and says why, in each of the ${cases.filter(([, , reasons]) => reasons !== null).length} cases; it passes where it should.`);

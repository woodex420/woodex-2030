#!/usr/bin/env node
/**
 * WOODEX OS: every table of the old system and where it goes in the Laravel admin, kept true by a check.
 *
 * docs/woodex-os/acceptance/tables.json has one row for each table of the old system (the React shop and
 * dashboard with their Node API, on PostgreSQL): what it holds, whether its rows are brought over, into which
 * tables of the admin, who owns it there and in which wave (master list T-175). This script reads it together
 * with what it speaks about, and writes the tables of docs/woodex-os/36-OLD-TABLES-AND-THEIR-LARAVEL-OWNERS.md.
 *
 * It fails when:
 *   - a table that the old schema files create (scripts/schema.postgres.sql and scripts/migrations/) has no
 *     row, or two; or a row names a table that no schema file creates, or the wrong file;
 *   - a row sends rows into a table that no migration of platform/database/migrations creates;
 *   - a row's owner is not an area and a destination of the admin (platform/app/Support/AdminNavigation.php);
 *   - a row names a line that is not on the master list, a wave that is not a wave of the list, or a wave
 *     other than the one the list gives that line;
 *   - a row that still has rows to bring over names a line that is done;
 *   - a row does not say what the table holds; a row that stays does not say why;
 *   - the live database, when it was last read, had a table that has no row here;
 *   - a migration of the admin creates a table in a way this script cannot read (so that it is taught, not fooled);
 *   - document 36 does not show what the file says.
 *
 *   node scripts/verify-table-map.mjs                 check (CI)
 *   node scripts/verify-table-map.mjs --write         write the tables of document 36, then check
 *   node scripts/verify-table-map.mjs --query         print the statement that reads the live database (reading only)
 *   node scripts/verify-table-map.mjs --live <file>   compare an answer to that statement with the map
 */
import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const at = (...parts) => path.join(root, ...parts);
const read = (...parts) => readFileSync(at(...parts), 'utf8');
const args = process.argv.slice(2);
const write = args.includes('--write');
const valueOf = (flag) => { const index = args.indexOf(flag); return index >= 0 ? args[index + 1] : undefined; };

const mapPath = 'docs/woodex-os/acceptance/tables.json';
const documentPath = 'docs/woodex-os/36-OLD-TABLES-AND-THEIR-LARAVEL-OWNERS.md';
const listPath = 'docs/woodex-os/14-PRODUCTION-TODO.md';
const basePath = 'scripts/schema.postgres.sql';
const oldMigrations = 'scripts/migrations';
const toolPath = 'scripts/migrate.mjs';
const newMigrations = 'platform/database/migrations';
const permissionPath = 'platform/config/permission.php';
const registryPath = 'platform/app/Support/AdminNavigation.php';

/**
 * The statement that reads the live database: the name of every table of the public schema with the number
 * of its rows, as one line "name=rows name=rows …". It reads no row's content and changes nothing.
 */
const QUERY = `SELECT to_char(clock_timestamp() AT TIME ZONE 'UTC', 'YYYY-MM-DD"T"HH24:MI:SS"Z"') AS read_at,
       string_agg(c.relname || '=' || (xpath('/row/n/text()', query_to_xml(format('SELECT count(*) AS n FROM %I.%I', n.nspname, c.relname), false, true, '')))[1]::text, ' ' ORDER BY c.relname) AS rows_per_table
  FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
 WHERE n.nspname = 'public' AND c.relkind IN ('r', 'p')`;
if (args.includes('--query')) {
  console.log(QUERY);
  process.exit(0);
}

const problems = [];
const bad = (where, what) => problems.push(`${where}: ${what}`);
const plural = (count, one, many = `${one}s`) => `${count} ${count === 1 ? one : many}`;
const list = (names) => names.map((name) => `\`${name}\``).join(', ');

/* ---------- the old system: the tables its schema files create ---------- */

// Comments are taken out first: "-- … must come after every CREATE TABLE above" once counted as a table.
const stripSql = (source) => source.replace(/\/\*[\s\S]*?\*\//g, '').replace(/--[^\n]*/g, '');
const createdIn = (source) => [...stripSql(source).matchAll(/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?([a-z_][a-z0-9_]*)/gi)].map((match) => match[1].toLowerCase());

/** Old table → where it is created: 'base', or the number of its migration. The first creation counts. */
const origin = new Map();
for (const name of createdIn(read(basePath))) if (!origin.has(name)) origin.set(name, 'base');
for (const file of readdirSync(at(oldMigrations)).filter((name) => /^\d{3}_.+\.sql$/.test(name)).sort()) {
  for (const name of createdIn(read(oldMigrations, file))) if (!origin.has(name)) origin.set(name, file.slice(0, 3));
}
/** The one table the migration tool creates for itself: its ledger. */
const toolTables = new Set(createdIn(read(toolPath)));

/* ---------- the admin: the tables its migrations create ---------- */

const laravel = new Map();
{
  const permission = read(permissionPath);
  const block = permission.slice(permission.indexOf("'table_names'"));
  const packageNames = Object.fromEntries([...block.slice(0, block.indexOf('],')).matchAll(/'([a-z_]+)'\s*=>\s*'([a-z_]+)'/g)].map((match) => [match[1], match[2]]));
  for (const file of readdirSync(at(newMigrations)).filter((name) => name.endsWith('.php')).sort()) {
    const source = read(newMigrations, file);
    // What a migration does going forward stands before its `down()`.
    const up = source.split(/public function down\s*\(/)[0];
    if (/Schema::(?:rename|drop|dropIfExists)\s*\(/.test(up)) {
      bad(`${newMigrations}/${file}`, 'drops or renames a table going forward, which this check cannot follow: teach scripts/verify-table-map.mjs what the table is called afterwards');
    }
    for (const match of up.matchAll(/Schema::create\(\s*([^,]+),/g)) {
      const argument = match[1].trim();
      const plain = argument.match(/^(['"])([a-z0-9_]+)\1$/);
      const packaged = argument.match(/^\$tableNames\['([a-z_]+)'\]$/);
      const configured = argument.match(/^config\('[^']+'\)\s*\?\?\s*'([a-z0-9_]+)'$/);
      const name = plain?.[2] ?? (packaged ? packageNames[packaged[1]] : undefined) ?? configured?.[1];
      if (!name) bad(`${newMigrations}/${file}`, `creates a table named by "${argument}", which this check cannot read: teach scripts/verify-table-map.mjs`);
      else if (!laravel.has(name)) laravel.set(name, file);
    }
  }
}

/* ---------- the admin: its areas and destinations ---------- */

/** Area title → the labels of its destinations. */
const areas = new Map();
{
  let current = null;
  for (const match of read(registryPath).matchAll(/self::(group|item)\('[^']+',\s*'([^']+)'/g)) {
    if (match[1] === 'group') { current = match[2]; areas.set(current, []); } else if (current) areas.get(current).push(match[2]);
  }
  if (areas.size === 0) bad(registryPath, 'no area was read from the registry: teach scripts/verify-table-map.mjs how it is written now');
}

/* ---------- the master list: its lines and its waves ---------- */

/** Line → { wave, done }. A line's wave is the cell "Wave" of its row, or the one wave its section's heading names. */
const lines = new Map();
const waves = new Set();
{
  let sectionWave = null;
  let head = null;
  for (const line of read(listPath).split('\n')) {
    const heading = line.match(/^## (.+)$/);
    if (heading) {
      head = null;
      const range = heading[1].match(/\bwaves (\d+) to (\d+)\b/);
      const single = heading[1].match(/\bwave (\d+|S)\b/);
      if (range) for (let wave = Number(range[1]); wave <= Number(range[2]); wave += 1) waves.add(String(wave));
      sectionWave = !range && single ? single[1] : null;
      if (sectionWave) waves.add(sectionWave);
      continue;
    }
    if (/^\| # \|/.test(line)) {
      head = line.replace(/^\|\s*/, '').replace(/\s*\|\s*$/, '').split(' | ').map((cell) => cell.trim());
      continue;
    }
    const match = line.match(/^\| (T-\d{3}) \|/);
    if (!match || !head) continue;
    const cells = line.replace(/^\|\s*/, '').replace(/\s*\|\s*$/, '').split(' | ').map((cell) => cell.trim());
    const cell = Object.fromEntries(head.map((name, index) => [name, cells[index]]));
    const wave = /^(\d+|S)$/.test(cell.Wave ?? '') ? cell.Wave : sectionWave;
    if (wave) waves.add(wave);
    lines.set(match[1], { wave, done: (cell.Status ?? '').startsWith('**Done**') });
  }
}

/* ---------- the map ---------- */

const map = JSON.parse(read(mapPath));
const rows = map.tables ?? [];
const KEYS = new Set(['table', 'from', 'holds', 'kind', 'to', 'instead', 'why', 'area', 'destination', 'line', 'wave', 'note']);
const kinds = Object.keys(map.kinds ?? {});
for (const kind of ['moves', 'waits', 'stays']) if (!kinds.includes(kind)) bad(mapPath, `the kind "${kind}" is not explained under "kinds"`);

const seen = new Map();
for (const row of rows) {
  const name = row.table;
  if (typeof name !== 'string' || !name) { bad(mapPath, 'a row without the name of its table'); continue; }
  seen.set(name, (seen.get(name) ?? 0) + 1);
  for (const key of Object.keys(row)) if (!KEYS.has(key)) bad(name, `the key "${key}" is not one of ${[...KEYS].join(', ')}`);

  if (row.from === 'tool') {
    if (!toolTables.has(name)) bad(name, `is said to be created by the migration tool, but ${toolPath} creates no such table`);
  } else if (!origin.has(name)) {
    bad(name, 'has a row, but no schema file of the old system creates it');
  } else if (row.from !== origin.get(name)) {
    bad(name, `is said to come from "${row.from}"; the schema files create it in "${origin.get(name)}"`);
  }

  if (typeof row.holds !== 'string' || row.holds.trim().length < 12) bad(name, 'does not say what the table holds');
  if (!kinds.includes(row.kind)) bad(name, `the kind is "${row.kind}", not one of ${kinds.join(', ')}`);

  const to = row.to ?? [];
  const instead = row.instead ?? [];
  if (!Array.isArray(to) || !Array.isArray(instead)) { bad(name, '"to" and "instead" are lists of table names'); continue; }
  for (const target of [...to, ...instead]) {
    if (!laravel.has(target)) bad(name, `names the table "${target}" of the admin, which no migration in ${newMigrations} creates`);
  }
  if (row.kind === 'moves' && to.length === 0) bad(name, 'moves, but names no table of the admin its rows go into');
  if (row.kind === 'waits' && to.length) bad(name, `waits for a table to be built, yet names ${list(to)}: if the admin has the table, the row moves`);
  if (row.kind === 'stays' && to.length) bad(name, `stays, yet names ${list(to)} as where its rows go: name them under "instead"`);
  if (row.kind !== 'stays' && instead.length) bad(name, '"instead" belongs to a row that stays');
  if (row.kind === 'stays' && (typeof row.why !== 'string' || row.why.trim().length < 12)) bad(name, 'stays, but does not say why');
  if (row.kind !== 'stays' && row.why !== undefined) bad(name, '"why" belongs to a row that stays; say it under "note"');

  if (!areas.has(row.area)) bad(name, `the area "${row.area}" is not an area of the admin (${[...areas.keys()].join(', ')})`);
  else if (!areas.get(row.area).includes(row.destination)) bad(name, `"${row.destination}" is not a destination of the area ${row.area} (${areas.get(row.area).join(', ')})`);

  const hasLine = row.line !== undefined;
  const hasWave = row.wave !== undefined;
  if (row.kind !== 'stays' && !(hasLine && hasWave)) bad(name, 'names no line of the master list and no wave: say which line brings it over, and in which wave');
  if (hasLine !== hasWave) bad(name, 'a line and a wave stand together: one is given without the other');
  if (hasLine) {
    const line = lines.get(row.line);
    if (!line) bad(name, `the line ${row.line} is not on the master list`);
    else {
      if (line.wave && String(row.wave) !== line.wave) bad(name, `the wave is ${row.wave}, but the master list has ${row.line} in wave ${line.wave}`);
      if (line.done && row.kind !== 'stays') bad(name, `still has rows to bring over, but ${row.line} is done: say where the rows went, or name the line that brings them`);
    }
  }
  if (hasWave && !waves.has(String(row.wave))) bad(name, `the wave ${row.wave} is not a wave of the master list (${[...waves].join(', ')})`);
}
for (const [name, count] of seen) if (count > 1) bad(name, `has ${count} rows: one table, one row`);
for (const name of origin.keys()) if (!seen.has(name)) bad(name, `is created by the old schema files (${origin.get(name) === 'base' ? basePath : `migration ${origin.get(name)}`}) and has no row in ${mapPath}`);
for (const name of toolTables) if (!seen.has(name)) bad(name, `is created by ${toolPath} and has no row in ${mapPath}`);

/* ---------- the live database, as last read ---------- */

const live = map.live ?? {};
const liveRows = live.rows ?? {};
const liveNames = Object.keys(liveRows);
if (!/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/.test(live.read_at ?? '')) bad(mapPath, 'the reading of the live database does not say when it was made ("read_at", in UTC)');
if (live.tables !== liveNames.length) bad(mapPath, `the reading of the live database says ${live.tables} tables and lists ${liveNames.length}`);
for (const name of liveNames) {
  if (!seen.has(name)) bad(name, `was in the live database when it was read (${live.read_at}) and has no row in ${mapPath}: the map does not know it`);
  if (!Number.isInteger(liveRows[name]) || liveRows[name] < 0) bad(name, 'the number of its rows in the live database is not a whole number');
}
/** Not a fault: a table that a migration has added since the last reading. Read again when it is applied. */
const notInLive = [...seen.keys()].filter((name) => !(name in liveRows));

/* ---------- comparing a new reading (--live <file>) ---------- */

const liveFile = valueOf('--live');
if (liveFile !== undefined) {
  if (!liveFile || !existsSync(liveFile)) {
    console.error('usage: node scripts/verify-table-map.mjs --live <file with the answer to the statement of --query>');
    process.exit(2);
  }
  const text = readFileSync(liveFile, 'utf8');
  const pairs = [...text.matchAll(/\b([a-z_][a-z0-9_]*)=(\d+)\b/g)].map((match) => [match[1], Number(match[2])]);
  if (pairs.length === 0) {
    console.error(`${liveFile}: no "name=rows" was found in it`);
    process.exit(2);
  }
  const now = Object.fromEntries(pairs);
  const unknown = Object.keys(now).filter((name) => !seen.has(name));
  const gone = [...seen.keys()].filter((name) => !(name in now));
  const changed = Object.keys(now).filter((name) => name in liveRows && liveRows[name] !== now[name]);
  console.log(`the reading   ${plural(pairs.length, 'table')}, ${pairs.reduce((sum, [, count]) => sum + count, 0)} rows`);
  console.log(`not in the map ${unknown.length ? unknown.join(', ') : 'none'}`);
  console.log(`not in the reading ${gone.length ? gone.join(', ') : 'none'}`);
  console.log(`rows changed since ${live.read_at}: ${changed.length ? changed.map((name) => `${name} ${liveRows[name]} → ${now[name]}`).join(', ') : 'none'}`);
  console.log(`\nto keep this reading, put into "live" of ${mapPath}: "tables": ${pairs.length}, and under "rows":\n${JSON.stringify(Object.fromEntries(pairs.sort(([a], [b]) => a.localeCompare(b))))}`);
  process.exit(unknown.length ? 1 : 0);
}

/* ---------- document 36 ---------- */

const day = (iso) => {
  const [year, month, date] = iso.slice(0, 10).split('-').map(Number);
  return `${date} ${['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'][month - 1]} ${year}`;
};
const time = (iso) => `${day(iso)}, ${iso.slice(11, 16)} UTC`;
const cell = (value) => String(value ?? '').replace(/\|/g, '\\|').replace(/\n/g, ' ');
const table = (head, body) => [`| ${head.join(' | ')} |`, `|${head.map(() => '---').join('|')}|`, ...body.map((line) => `| ${line.map(cell).join(' | ')} |`)].join('\n');
const count = (kind) => rows.filter((row) => row.kind === kind).length;
const rowsToday = (name) => (name in liveRows ? liveRows[name] : null);
const where = (row) => (row.from === 'base' ? 'base' : row.from === 'tool' ? 'tool' : row.from);

const WHAT = {
  moves: (row) => `**Moves** into ${list(row.to ?? [])}`,
  waits: () => '**Waits**: the admin has no table for it yet',
  stays: (row) => `**Stays.** ${row.why ?? ''}${(row.instead ?? []).length ? ` In the admin: ${list(row.instead)}` : ''}`,
};
const withRows = liveNames.filter((name) => liveRows[name] > 0).sort((a, b) => liveRows[b] - liveRows[a] || a.localeCompare(b));
const total = liveNames.reduce((sum, name) => sum + liveRows[name], 0);
const sameName = [...origin.keys()].filter((name) => laravel.has(name)).sort();
const fed = new Set(rows.flatMap((row) => row.to ?? []));
const byWave = new Map();
for (const row of rows) if (row.wave !== undefined && row.kind !== 'stays') byWave.set(String(row.wave), [...(byWave.get(String(row.wave)) ?? []), row]);
const waveOrder = [...byWave.keys()].sort((a, b) => Number(a) - Number(b));

const blocks = {
  numbers: table(['', ''], [
    ['Tables of the old system', `**${rows.length}**: ${origin.size} that its schema files create, and the list of schema changes that the migration tool keeps`],
    ['Move into tables the admin has today', `**${count('moves')}**`],
    ['Wait for a table the admin does not have yet', `**${count('waits')}**`],
    ['Stay: their rows are not brought over', `**${count('stays')}**`],
    ['Tables the admin\'s migrations create', `${laravel.size}. ${fed.size} of them receive rows from the old system by this map`],
    ['Names that stand on both sides', `${sameName.length}. The same name is not the same table: see §4`],
    [`In the live database on ${day(live.read_at ?? '')}`, `${liveNames.length} tables, ${live.columns} columns, ${total} rows. ${withRows.length} tables hold rows, ${liveNames.length - withRows.length} are empty`],
  ]),

  live: [
    `Read on ${time(live.read_at ?? '')}, from ${live.project}. ${live.agrees}`,
    '',
    table(['Table', 'Rows', 'What happens to it'], withRows.map((name) => {
      const row = rows.find((candidate) => candidate.table === name);
      const fate = { moves: `Moves (wave ${row?.wave})`, waits: `Waits (wave ${row?.wave})`, stays: 'Stays' }[row?.kind] ?? 'not in the map';
      return [`\`${name}\``, liveRows[name], fate];
    })),
    '',
    `**Empty (${liveNames.length - withRows.length}):** ${list(liveNames.filter((name) => liveRows[name] === 0).sort())}.`,
    ...(notInLive.length ? ['', `**In the map and not in the database when it was read:** ${list(notInLive)}. Read again once the migration that adds them is applied.`] : []),
  ].join('\n'),

  areas: [...areas.keys()].filter((area) => rows.some((row) => row.area === area)).map((area) => {
    const mine = rows.filter((row) => row.area === area);
    return [
      `### ${area} (${plural(mine.length, 'table')})`,
      '',
      table(['Old table', 'From', 'What it holds', 'Rows today', 'What happens', 'Destination', 'Line · wave', 'To know'],
        mine.map((row) => [
          `\`${row.table}\``,
          where(row),
          row.holds,
          rowsToday(row.table) ?? '—',
          (WHAT[row.kind] ?? (() => row.kind))(row),
          row.destination,
          row.line ? `${row.line} · ${row.wave}` : '—',
          row.note ?? '',
        ])),
    ].join('\n');
  }).join('\n\n'),

  waves: table(['Wave', 'Tables', 'Move into tables that exist', 'Wait for a table', 'Rows in the live database today'],
    waveOrder.map((wave) => {
      const mine = byWave.get(wave);
      const moving = mine.filter((row) => row.kind === 'moves');
      const waiting = mine.filter((row) => row.kind === 'waits');
      return [wave, mine.length, moving.length ? list(moving.map((row) => row.table)) : '—', waiting.length ? list(waiting.map((row) => row.table)) : '—',
        mine.reduce((sum, row) => sum + (rowsToday(row.table) ?? 0), 0)];
    })),

  names: `${list(sameName)}.`,
};

let document = read(documentPath);
for (const [name, body] of Object.entries(blocks)) {
  const open = `<!-- generated:${name} — written by scripts/verify-table-map.mjs --write; do not edit by hand -->`;
  const close = `<!-- /generated:${name} -->`;
  const from = document.indexOf(open);
  const to = document.indexOf(close);
  if (from < 0 || to < from) {
    bad(documentPath, `the marks of the block "${name}" are missing`);
    continue;
  }
  const present = document.slice(from + open.length, to);
  const wanted = `\n${body}\n`;
  if (present === wanted) continue;
  if (write) document = `${document.slice(0, from + open.length)}${wanted}${document.slice(to)}`;
  else bad(documentPath, `the block "${name}" does not show what the map says (run: node scripts/verify-table-map.mjs --write)`);
}
if (write) writeFileSync(at(documentPath), document);

/* ---------- the result ---------- */

console.log(`old system  ${origin.size} tables in its schema files, ${toolTables.size} kept by the migration tool`);
console.log(`map         ${rows.length} rows: ${count('moves')} move, ${count('waits')} wait, ${count('stays')} stay`);
console.log(`admin       ${laravel.size} tables created by its migrations · ${fed.size} receive rows · ${sameName.length} names stand on both sides`);
console.log(`live        ${liveNames.length} tables, ${total} rows, read ${live.read_at}${notInLive.length ? ` · not in that reading: ${notInLive.join(', ')}` : ''}`);
if (problems.length) {
  console.error(`\n${plural(problems.length, 'problem')}:`);
  for (const problem of problems) console.error(`  - ${problem}`);
  process.exit(1);
}
console.log(write ? '\nDocument 36 written and checked.' : '\nThe schema files, the map, the admin\'s migrations, the master list and document 36 agree.');

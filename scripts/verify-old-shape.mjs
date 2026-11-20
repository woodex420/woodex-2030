#!/usr/bin/env node
/**
 * WOODEX OS: what the Laravel admin is told about the shape of the old system, kept true by a check.
 *
 * An importer of the admin has to know three things about a table of the old system (the React shop and
 * dashboard with their Node API, on PostgreSQL) that it cannot read from a row: which columns are its key, which
 * words a column may hold, and where the map sends its rows. The admin runs on a host that has neither the old
 * schema files nor a PostgreSQL, so those facts are written into one file inside `platform/`:
 *
 *   platform/resources/data/old-system-shape.json
 *
 * This script writes it, and CI runs it as a check (master list T-176 and T-177). The facts come from
 *   - the old schema itself: scripts/schema.postgres.sql and every file of scripts/migrations/ are applied to a
 *     test database (the embedded PostgreSQL the module suites use), and the database's own catalogue is asked
 *     for each table's primary key and CHECK constraints. Nothing is read out of the SQL text;
 *   - the lists of words that only the old API's code enforces (LISTS_IN_CODE below), read from the code;
 *   - the map of the tables, docs/woodex-os/acceptance/tables.json: whether a table's rows move, wait or stay.
 *
 * It fails when
 *   - the file is not what those three give (a migration added a table, a word or a key; the map changed);
 *   - a table of the schema has no row in the map;
 *   - a key is of a type this script does not know (so that somebody decides how it is written as text);
 *   - a CHECK constraint on one text column compares it with words in a way this script cannot read (so that
 *     it is taught, not fooled).
 *
 *   node scripts/verify-old-shape.mjs                 check (CI)
 *   node scripts/verify-old-shape.mjs --write         write the file, then check
 *   node scripts/verify-old-shape.mjs --file <path>   check another file instead (the test of this check uses it)
 *
 * scripts/test-verify-old-shape.mjs tries the check itself, one mistake at a time.
 */
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { ROOT, createPgliteDatabase, openPglite } from './lib/pg-fixture.mjs';

export const SHAPE_PATH = 'platform/resources/data/old-system-shape.json';
const MAP_PATH = 'docs/woodex-os/acceptance/tables.json';

/**
 * Words that no CHECK constraint holds but the old API refuses to write: the column, and the constant of the
 * code that lists them. Only closed lists are named here. A column the API lets any text into (`leads.status`,
 * `orders.due`) is not a vocabulary, and document 36 says so.
 */
export const LISTS_IN_CODE = {
  'conversations.status': { file: 'apps/api/server.mjs', constant: 'CONV_STATUS' },
  'projects.stage': { file: 'apps/api/server.mjs', constant: 'PROJECT_STAGES' },
  'quotes.status': { file: 'apps/api/server.mjs', constant: 'QUOTE_STATUSES' },
};

const ABOUT = 'What the Laravel admin is told about the shape of the old system (the React shop and dashboard with their Node '
  + 'API, on PostgreSQL): for each of its tables the columns of its key, the words its CHECK constraints allow, the '
  + 'words only the old API\'s code allows, the columns held to 0 or 1, the true yes-or-no columns, and where the map '
  + 'of the tables sends its rows. Written by `node scripts/verify-old-shape.mjs --write` from the old schema files '
  + '(applied to a test database, whose catalogue is then read), the old API\'s code and '
  + 'docs/woodex-os/acceptance/tables.json. Do not edit it by hand: CI fails when it is not what those give.';

const KEY_KINDS = { bigint: 'number', integer: 'number', smallint: 'number', text: 'text' };

/** `'a'::text, 'b c'::text` → ['a', 'b c']; null when something else stands in the list. */
function wordsOf(list) {
  const words = [];
  for (const item of list.split(/,\s*(?=')/)) {
    const match = item.trim().match(/^'((?:[^']|'')*)'::text$/);
    if (!match) return null;
    words.push(match[1].replaceAll("''", "'"));
  }
  return words;
}

/**
 * One CHECK constraint on one column, as PostgreSQL prints it. Answers
 *   { words: [...] }   the column may hold one of these words (also when it may be empty as well);
 *   { flag: true }     the column is held to 0 or 1;
 *   { other: true }    a rule that lists no words (a length, a pattern, a range);
 *   null               it compares a text with words in a way this function does not know.
 */
export function readCheck(column, definition) {
  const name = `"?${column}"?`;
  const list = new RegExp(`^CHECK \\(\\((?:\\(${name} IS NULL\\) OR \\()?${name} = ANY \\(ARRAY\\[(.*)\\]\\)\\)?\\)\\)$`).exec(definition);
  if (list) {
    const words = wordsOf(list[1]);
    if (words) return { words };
    if (/^0, 1$|^1, 0$/.test(list[1].trim())) return { flag: true };
    return null;
  }
  if (!definition.includes("'")) return { other: true };
  if (/btrim\(|TRIM\(|length\(| ~~ | ~ /.test(definition)) return { other: true };
  return null;
}

/** The words of `const NAME = new Set(["a", "b"])` or `const NAME = ["a", "b"]` in a file of the old API. */
export function listInCode(source, constant) {
  const match = new RegExp(`^const ${constant} = (?:new Set\\()?\\[([^\\]]*)\\]\\)?;`, 'm').exec(source);
  if (!match) return null;
  const words = [...match[1].matchAll(/"((?:[^"\\]|\\.)*)"/g)].map((found) => found[1]);
  return words.length && words.join('", "') === match[1].trim().replace(/^"|"$/g, '') ? words : null;
}

/** Build the shape from the schema files, the code and the map. Returns { shape, problems }. */
export async function buildShape({ root = ROOT } = {}) {
  const problems = [];
  const work = fs.mkdtempSync(path.join(os.tmpdir(), 'woodex-old-shape-'));
  const dir = path.join(work, 'db');
  await createPgliteDatabase(dir);
  const db = await openPglite(dir);
  let keys; let checks; let booleans;
  try {
    keys = await db.q(`
      SELECT c.relname AS table_name,
             (SELECT json_agg(json_build_array(a.attname, format_type(a.atttypid, NULL)) ORDER BY k.ord)
                FROM unnest(i.indkey) WITH ORDINALITY AS k(attnum, ord)
                JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = k.attnum) AS key
        FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
        LEFT JOIN pg_index i ON i.indrelid = c.oid AND i.indisprimary
       WHERE n.nspname = current_schema() AND c.relkind = 'r' ORDER BY 1`);
    checks = await db.q(`
      SELECT c.relname AS table_name, pg_get_constraintdef(con.oid) AS definition,
             (SELECT json_agg(a.attname ORDER BY a.attnum) FROM pg_attribute a
               WHERE a.attrelid = c.oid AND a.attnum = ANY(con.conkey)) AS columns,
             (SELECT json_agg(format_type(a.atttypid, NULL) ORDER BY a.attnum) FROM pg_attribute a
               WHERE a.attrelid = c.oid AND a.attnum = ANY(con.conkey)) AS types
        FROM pg_constraint con JOIN pg_class c ON c.oid = con.conrelid JOIN pg_namespace n ON n.oid = c.relnamespace
       WHERE n.nspname = current_schema() AND con.contype = 'c' ORDER BY 1, 2`);
    booleans = await db.q(`
      SELECT table_name, column_name FROM information_schema.columns
       WHERE table_schema = current_schema() AND data_type = 'boolean' ORDER BY 1, 2`);
  } finally {
    await db.close();
    fs.rmSync(work, { recursive: true, force: true });
  }

  const map = JSON.parse(fs.readFileSync(path.join(root, MAP_PATH), 'utf8'));
  const rows = new Map(map.tables.map((row) => [row.table, row]));
  const tables = {};

  for (const { table_name: table, key } of keys) {
    const row = rows.get(table);
    if (!row) { problems.push(`${table}: a table of the old schema that has no row in ${MAP_PATH}`); continue; }
    if (!key) { problems.push(`${table}: has no primary key, so nothing says which row is which`); continue; }
    const columns = [];
    for (const [column, type] of key) {
      if (!KEY_KINDS[type]) problems.push(`${table}.${column}: a key of the type "${type}", which this script does not know: say in KEY_KINDS how it is written as text`);
      columns.push([column, KEY_KINDS[type] ?? type]);
    }
    tables[table] = { key: columns, fate: row.kind, to: row.to ?? [], line: row.line, wave: row.wave, words: {}, words_in_code: {}, flags: [], booleans: [] };
  }
  for (const table of rows.keys()) if (!tables[table] && !problems.some((p) => p.startsWith(`${table}:`))) problems.push(`${table}: has a row in ${MAP_PATH} but the old schema files create no such table`);

  for (const { table_name: table, definition, columns, types } of checks) {
    if (!tables[table] || columns.length !== 1) continue;
    const [column] = columns;
    const found = readCheck(column, definition);
    if (found === null) {
      if (types[0] === 'text') problems.push(`${table}.${column}: a CHECK constraint this script cannot read: ${definition}`);
      continue;
    }
    if (found.words) {
      if (tables[table].words[column]) problems.push(`${table}.${column}: two CHECK constraints list its words`);
      tables[table].words[column] = found.words;
    }
    if (found.flag) tables[table].flags.push(column);
  }
  for (const { table_name: table, column_name: column } of booleans) tables[table]?.booleans.push(column);

  for (const [where, { file, constant }] of Object.entries(LISTS_IN_CODE)) {
    const [table, column] = where.split('.');
    if (!tables[table]) { problems.push(`${where}: named in LISTS_IN_CODE, but the old schema has no such table`); continue; }
    if (tables[table].words[column]) { problems.push(`${where}: a CHECK constraint lists its words now: take it out of LISTS_IN_CODE`); continue; }
    const words = listInCode(fs.readFileSync(path.join(root, file), 'utf8'), constant);
    if (!words) { problems.push(`${where}: the list ${constant} was not found in ${file} as a plain list of words`); continue; }
    tables[table].words_in_code[column] = { file, constant, words };
  }

  for (const table of Object.values(tables)) { table.flags.sort(); table.booleans.sort(); }
  const sorted = Object.fromEntries(Object.keys(tables).sort().map((name) => [name, tables[name]]));
  return { shape: { about: ABOUT, tables: sorted }, problems };
}

export const print = (shape) => `${JSON.stringify(shape, null, 1)}\n`;

/** In what the file on disk differs from what was built: one sentence for each table that differs. */
export function differences(built, onDisk) {
  const found = [];
  if (!onDisk || typeof onDisk !== 'object' || !onDisk.tables) return ['the file holds no tables'];
  if (onDisk.about !== built.about) found.push('the heading "about" was changed');
  const names = new Set([...Object.keys(built.tables), ...Object.keys(onDisk.tables)]);
  for (const name of [...names].sort()) {
    const now = built.tables[name]; const then = onDisk.tables[name];
    if (!then) { found.push(`${name}: a table of the old system that the file does not have`); continue; }
    if (!now) { found.push(`${name}: in the file, but the old system has no such table`); continue; }
    for (const field of Object.keys({ ...now, ...then })) {
      if (JSON.stringify(now[field]) !== JSON.stringify(then[field])) {
        found.push(`${name}: "${field}" is ${JSON.stringify(then[field])} in the file, and ${JSON.stringify(now[field])} by the schema, the code and the map`);
      }
    }
  }
  if (!found.length && print(built) !== print(onDisk)) found.push('the same facts in another order or layout');
  return found;
}

async function main() {
  const write = process.argv.includes('--write');
  const other = process.argv.indexOf('--file');
  const { shape, problems } = await buildShape();
  // `--file <path>` compares another file with what was built: the test of this check hands it a changed copy.
  const target = other >= 0 ? path.resolve(process.argv[other + 1] ?? '') : path.join(ROOT, SHAPE_PATH);
  if (write && other < 0 && !problems.length) fs.writeFileSync(target, print(shape));
  if (!fs.existsSync(target)) problems.push(`${SHAPE_PATH} does not exist (run: node scripts/verify-old-shape.mjs --write)`);
  else {
    const text = fs.readFileSync(target, 'utf8');
    let onDisk = null;
    try { onDisk = JSON.parse(text); } catch (error) { problems.push(`${SHAPE_PATH} is not JSON: ${error.message}`); }
    if (onDisk) {
      const found = differences(shape, onDisk);
      for (const difference of found) problems.push(`${SHAPE_PATH}: ${difference}`);
      if (!found.length && text !== print(shape)) problems.push(`${SHAPE_PATH}: the same facts in another layout`);
      if (found.length) problems.push('write it again: node scripts/verify-old-shape.mjs --write');
    }
  }

  const tables = Object.values(shape.tables);
  const count = (pick) => tables.reduce((sum, table) => sum + pick(table), 0);
  if (problems.length) {
    console.error(`Old system's shape: ${problems.length} problem(s)`);
    for (const problem of problems) console.error(`  - ${problem}`);
    process.exit(1);
  }
  console.log(`Old system's shape: ${tables.length} tables; keys: ${tables.filter((t) => t.key.length === 1 && t.key[0][1] === 'number').length} of one number, `
    + `${tables.filter((t) => t.key.length === 1 && t.key[0][1] === 'text').length} of one text, ${tables.filter((t) => t.key.length > 1).length} of two columns; `
    + `${count((t) => Object.keys(t.words).length)} columns whose words a CHECK constraint lists (${count((t) => Object.values(t.words).reduce((n, w) => n + w.length, 0))} words), `
    + `${count((t) => Object.keys(t.words_in_code).length)} whose words only the code lists (${count((t) => Object.values(t.words_in_code).reduce((n, w) => n + w.words.length, 0))} words); `
    + `${count((t) => t.flags.length)} columns held to 0 or 1, ${count((t) => t.booleans.length)} true yes-or-no columns. ${SHAPE_PATH} agrees.`);
  process.exit(0);
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
  main().catch((error) => { console.error(error); process.exit(1); });
}

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
 * Beside that file stands one that is written by hand, the decisions an importer follows:
 *
 *   platform/resources/data/old-system-rules.json
 *
 * the place of each word of the old system in an enumeration of the admin, the rule of every unique column, and
 * which records get no entry in the table of the old numbers. This script holds it to the old system. It fails when
 *   - a column of a table that moves lists its words and the rules leave it out, or leave one word without a
 *     place and without a reason, or name a word the old system does not allow;
 *   - a place is a value the admin's enumeration does not have (read from the enumeration's file);
 *   - a unique column is said to take its value from a column the old system does not have;
 *   - records are said to get no entry in a table the map does not send their row into;
 *   - docs/woodex-os/36-OLD-TABLES-AND-THEIR-LARAVEL-OWNERS.md does not show what the two files say (§8, §9).
 * What only a database can say (the admin's own unique indexes, on each server) is held by the admin's tests,
 * platform/tests/Feature/OldSystem.
 *
 *   node scripts/verify-old-shape.mjs                     check (CI)
 *   node scripts/verify-old-shape.mjs --write             write the shape file and the blocks of document 36, then check
 *   node scripts/verify-old-shape.mjs --file <path>       check another shape file instead
 *   node scripts/verify-old-shape.mjs --rules <path>      check another rules file instead
 *   node scripts/verify-old-shape.mjs --document <path>   check another copy of document 36 instead
 * (the last three are what the test of this check uses)
 *
 * scripts/test-verify-old-shape.mjs tries the check itself, one mistake at a time.
 */
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { ROOT, createPgliteDatabase, openPglite } from './lib/pg-fixture.mjs';

export const SHAPE_PATH = 'platform/resources/data/old-system-shape.json';
export const RULES_PATH = 'platform/resources/data/old-system-rules.json';
export const DOCUMENT_PATH = 'docs/woodex-os/36-OLD-TABLES-AND-THEIR-LARAVEL-OWNERS.md';
const MAP_PATH = 'docs/woodex-os/acceptance/tables.json';

/** The beginning of every name the old system's rows are entered under (App\\Domain\\System\\Import\\OldSystem\\Refs::SYSTEM). */
export const SYSTEM = 'node.';

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
  let keys; let checks; let booleans; let columnRows;
  try {
    columnRows = await db.q(`
      SELECT table_name, column_name FROM information_schema.columns WHERE table_schema = current_schema() ORDER BY 1, 2`);
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
  // Every column of the old schema, as "table.column": not written to the file, used to check the rules.
  const columns = new Set(columnRows.map((row) => `${row.table_name}.${row.column_name}`));
  return { shape: { about: ABOUT, tables: sorted }, problems, columns };
}

/* ---------- the rules, written by hand ---------- */

/** The words a column may hold by the shape: by a CHECK constraint, or else by the old API's code. */
const wordsBy = (table, column) => table.words[column] ?? table.words_in_code[column]?.words ?? null;

/** Every column of the shape whose words are listed, as [where, table's name, column, words]. */
export function vocabularies(shape) {
  const found = [];
  for (const [name, table] of Object.entries(shape.tables)) {
    for (const column of Object.keys({ ...table.words, ...table.words_in_code })) found.push([`${name}.${column}`, name, column, wordsBy(table, column)]);
  }
  return found.sort((a, b) => (a[0] < b[0] ? -1 : 1));
}

/** The values of a PHP enumeration, read from its file: `App\\Domain\\Sales\\Enums\\InvoiceStatus` → ['draft', …]; null when unreadable. */
export function enumValues(className, root = ROOT) {
  const match = /^App\\((?:[A-Za-z0-9_]+\\)*[A-Za-z0-9_]+)$/.exec(className ?? '');
  if (!match) return null;
  const file = path.join(root, 'platform/app', `${match[1].replaceAll('\\', '/')}.php`);
  if (!fs.existsSync(file)) return null;
  const source = fs.readFileSync(file, 'utf8');
  if (!new RegExp(`^enum ${className.split('\\').pop()}: string`, 'm').test(source)) return null;
  return [...source.matchAll(/^\s*case\s+\w+\s*=\s*'([^']*)';/gm)].map((found) => found[1]);
}

const VALUES = ['admin', 'numbers', 'key', 'carried'];
const WHEN_TWO_MEET = ['stop', 'first'];

/** What the rules say against the old system: one sentence for each mistake. */
export function rulesProblems(shape, rules, columns, { root = ROOT } = {}) {
  const found = [];
  if (!rules || typeof rules !== 'object' || Array.isArray(rules)) return ['the file holds no rules'];
  for (const part of ['about', 'words', 'unique', 'lines']) if (rules[part] === undefined) found.push(`the part "${part}" is missing`);
  if (found.length) return found;

  const listed = new Map(vocabularies(shape).map(([where, table, column, words]) => [where, { table, column, words }]));
  for (const [where, { table, words }] of listed) {
    if (shape.tables[table].fate !== 'moves') continue;
    const rule = rules.words[where];
    if (!rule) { found.push(`${where}: the old system lists its words and its table moves, but the rules give them no place`); continue; }
    const places = Object.keys(rule.places ?? {}); const without = Object.keys(rule.without ?? {});
    for (const word of words) {
      if (!places.includes(word) && !without.includes(word)) found.push(`${where}: the word "${word}" has neither a place nor a reason for having none`);
      if (places.includes(word) && without.includes(word)) found.push(`${where}: the word "${word}" has a place and a reason for having none`);
    }
    for (const word of [...places, ...without]) if (!words.includes(word)) found.push(`${where}: the word "${word}" is written, but the old system does not allow it there`);
    for (const [word, why] of Object.entries(rule.without ?? {})) if (typeof why !== 'string' || !why.trim()) found.push(`${where}: the word "${word}" has no place and no reason is written`);
    if (places.length) {
      const values = enumValues(rule.enum, root);
      if (!values) found.push(`${where}: "${rule.enum}" is not an enumeration of the admin that this script can read`);
      else for (const [word, place] of Object.entries(rule.places)) if (!values.includes(place)) found.push(`${where}: the word "${word}" is sent to "${place}", which ${rule.enum.split('\\').pop()} does not have`);
    }
  }
  for (const where of Object.keys(rules.words)) {
    const known = listed.get(where);
    if (!known) found.push(`${where}: the rules place its words, but the old system lists none for it`);
    else if (shape.tables[known.table].fate !== 'moves') found.push(`${where}: the rules place its words, but by the map the rows of "${known.table}" are not brought over`);
  }

  const seen = new Set();
  for (const rule of rules.unique) {
    const name = `${rule.table}: ${(rule.columns ?? []).join(' + ')}`;
    if (seen.has(name)) found.push(`${name}: written twice`);
    seen.add(name);
    if (!VALUES.includes(rule.value)) found.push(`${name}: "value" is "${rule.value}"; it is one of ${VALUES.join(', ')}`);
    if (typeof rule.why !== 'string' || !rule.why.trim()) found.push(`${name}: nothing says what the column is`);
    if (rule.value === 'carried') {
      if (!WHEN_TWO_MEET.includes(rule.rule)) found.push(`${name}: the old system's value is written here, and no rule says what happens when two meet ("stop" or "first")`);
      if (!columns.has(rule.from)) found.push(`${name}: its value is said to come from "${rule.from}", and the old system has no such column`);
    } else if (rule.rule !== undefined || rule.from !== undefined) found.push(`${name}: only a value the old system brings has a rule and a column it comes from`);
  }

  for (const rule of rules.lines) {
    const table = shape.tables[rule.from];
    if (!table || table.fate !== 'moves') found.push(`lines: "${rule.from}" is not a table of the old system that moves`);
    else if (!table.to.includes(rule.into)) found.push(`lines: the map does not send the rows of "${rule.from}" into "${rule.into}"`);
    if (typeof rule.why !== 'string' || !rule.why.trim()) found.push(`lines: ${rule.from} > ${rule.into}: no reason is written`);
  }
  return found;
}

/* ---------- document 36, §8 and §9 ---------- */

const code = (text) => `\`${text}\``;
const cell = (text) => String(text).replaceAll('|', '\\|').replaceAll('\n', ' ');
const table = (head, rows) => [`| ${head.join(' | ')} |`, `|${head.map(() => '---').join('|')}|`, ...rows.map((row) => `| ${row.map(cell).join(' | ')} |`)].join('\n');
const waitsFor = (entry) => (entry.fate === 'waits' ? `Waits for ${entry.line} (wave ${entry.wave})` : 'Stays in the old system');

/** The names the rows of a table are entered under: one for each table of the admin, without the lines. */
export function entryNames(shape, rules) {
  const names = [];
  for (const [name, entry] of Object.entries(shape.tables)) {
    if (entry.fate !== 'moves') continue;
    for (const into of entry.to) if (!rules.lines.some((rule) => rule.from === name && rule.into === into)) names.push([name, into, `${SYSTEM}${name}>${into}`]);
  }
  return names;
}

/** The blocks of document 36 that are written from the two files. */
export function documentBlocks(shape, rules) {
  const tables = Object.entries(shape.tables);
  const all = vocabularies(shape);
  const moving = all.filter(([, name]) => shape.tables[name].fate === 'moves');
  const other = all.filter(([, name]) => shape.tables[name].fate !== 'moves');
  const sum = (list, pick) => list.reduce((total, item) => total + pick(item), 0);
  const byCheck = tables.flatMap(([, entry]) => Object.values(entry.words));
  const byCode = tables.flatMap(([, entry]) => Object.values(entry.words_in_code));
  const placed = sum(Object.values(rules.words), (rule) => Object.keys(rule.places).length);
  const without = sum(Object.values(rules.words), (rule) => Object.keys(rule.without).length);
  const names = entryNames(shape, rules);
  const kinds = (value) => rules.unique.filter((rule) => rule.value === value);
  const keyOf = (entry) => entry.key.map(([column, kind]) => `${code(column)} (a ${kind})`).join(' and ');
  const plural = (count, one, many = `${one}s`) => `${count} ${count === 1 ? one : many}`;

  const numbers = table(['', ''], [
    ['Tables of the old system', `**${tables.length}**. The key is one number in ${tables.filter(([, e]) => e.key.length === 1 && e.key[0][1] === 'number').length}, one text in ${tables.filter(([, e]) => e.key.length === 1 && e.key[0][1] === 'text').length}, two columns in ${tables.filter(([, e]) => e.key.length > 1).length}`],
    ['Columns whose words are listed', `**${all.length}**, with ${sum(all, ([, , , words]) => words.length)} words: ${byCheck.length} by a CHECK constraint of the schema (${sum(byCheck, (words) => words.length)} words), ${byCode.length} only by the old API's code (${sum(byCode, (list) => list.words.length)} words)`],
    ['Of them in tables that move today', `**${moving.length}** columns, ${sum(moving, ([, , , words]) => words.length)} words: **${placed}** have a place in the admin, **${without}** have none, each with its reason`],
    ['In tables that wait or stay', `${other.length} columns, ${sum(other, ([, , , words]) => words.length)} words. They get a place when their table gets one`],
    ['Columns held to 0 or 1 · true yes-or-no columns', `${sum(tables, ([, e]) => e.flags.length)} · ${sum(tables, ([, e]) => e.booleans.length)}`],
    ['Unique indexes an importer can meet', `**${rules.unique.length}**: ${kinds('admin').length} the admin fills itself, ${kinds('numbers').length} hold only numbers of other records, ${kinds('key').length} is the table of the old numbers, **${kinds('carried').length}** take a value of the old system (${kinds('carried').filter((r) => r.rule === 'stop').length} stop the import when two meet, ${kinds('carried').filter((r) => r.rule === 'first').length} keeps the first)`],
    ['Names in the table of the old numbers', `**${names.length}**, for the ${tables.filter(([, e]) => e.fate === 'moves').length} tables that move. All begin with ${code(SYSTEM)}`],
  ]);

  const words = table(['Column of the old system', 'Its word', 'In the admin'], moving.flatMap(([where, , , list]) => {
    const rule = rules.words[where] ?? { places: {}, without: {} };
    const short = (rule.enum ?? '').split('\\').pop();
    return list.map((word) => [code(where), code(word), rule.places[word] !== undefined ? `${code(rule.places[word])} (${short})` : `**No place.** ${rule.without[word] ?? 'No reason is written.'}`]);
  }));
  const notes = Object.entries(rules.words).filter(([, rule]) => rule.note).map(([where, rule]) => `- ${code(where)}: ${rule.note}`);

  const waiting = table(['Column of the old system', 'Its words', 'Its table'], other.map(([where, name, , list]) => [code(where), list.map(code).join(', '), waitsFor(shape.tables[name])]));

  const whoGives = (rule) => ({ admin: 'The admin makes it', numbers: 'Numbers of other records only', key: 'The one class of §9', carried: `The old system: ${code(rule.from)}` })[rule.value];
  const whenTwoMeet = (rule) => (rule.value !== 'carried' ? '—' : rule.rule === 'stop' ? '**The import stops** and names both records' : '**The first is kept** (the lower old number); the other is listed');
  const unique = table(['Unique in the admin', 'What it is', 'The value comes from', 'When two old values meet'],
    rules.unique.map((rule) => [code(`${rule.table}.${rule.columns.join(' + ')}`), rule.why, whoGives(rule), whenTwoMeet(rule)]));

  const grouped = new Map();
  for (const [name, , full] of names) grouped.set(name, [...(grouped.get(name) ?? []), full]);
  const entries = table(['Table of the old system', 'Its key', 'Entered under'], [...grouped].map(([name, list]) => [code(name), keyOf(shape.tables[name]), list.map(code).join(', ')]));
  const noEntry = rules.lines.map((rule) => `- ${code(`${rule.from}`)} into ${code(rule.into)}: ${rule.why}`);
  const others = tables.filter(([, entry]) => entry.fate !== 'moves' && (entry.key.length > 1 || entry.key[0][1] === 'text'))
    .map(([name, entry]) => `${code(name)} (${keyOf(entry)}; ${entry.fate === 'waits' ? `waits for ${entry.line}, wave ${entry.wave}` : 'stays in the old system'})`);

  return {
    'shape-numbers': numbers,
    'shape-words': `${words}${notes.length ? `\n\n${notes.join('\n')}` : ''}`,
    'shape-waiting-words': waiting,
    'shape-unique': unique,
    'shape-entries': `${entries}\n\nNo entry of their own:\n\n${noEntry.join('\n')}\n\nNot entered today, because their rows are not brought over, and with a key that is not one number: ${others.join(', ')}. `
      + `The other ${plural(tables.filter(([, entry]) => entry.fate !== 'moves').length - others.length, 'table')} that wait or stay have one number for their key.`,
  };
}

const marks = (name) => [`<!-- generated:${name} — written by scripts/verify-old-shape.mjs --write; do not edit by hand -->`, `<!-- /generated:${name} -->`];

/** Put the blocks into the document's text. Returns { text, problems }: a problem for each block that is missing or differs. */
export function placeBlocks(text, blocks) {
  const problems = [];
  let document = text;
  for (const [name, body] of Object.entries(blocks)) {
    const [open, close] = marks(name);
    const from = document.indexOf(open); const to = document.indexOf(close);
    if (from < 0 || to < from) { problems.push(`the marks of the block "${name}" are missing`); continue; }
    const wanted = `\n${body}\n`;
    if (document.slice(from + open.length, to) === wanted) continue;
    problems.push(`the block "${name}" does not show what the two files say (run: node scripts/verify-old-shape.mjs --write)`);
    document = `${document.slice(0, from + open.length)}${wanted}${document.slice(to)}`;
  }
  return { text: document, problems };
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
  const option = (name) => { const at = process.argv.indexOf(name); return at >= 0 ? path.resolve(process.argv[at + 1] ?? '') : null; };
  const { shape, problems, columns } = await buildShape();
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

  // The rules, written by hand, and what document 36 shows of the two files.
  const rulesFile = option('--rules') ?? path.join(ROOT, RULES_PATH);
  const documentFile = option('--document') ?? path.join(ROOT, DOCUMENT_PATH);
  let rules = null;
  if (!fs.existsSync(rulesFile)) problems.push(`${RULES_PATH} does not exist`);
  else {
    try { rules = JSON.parse(fs.readFileSync(rulesFile, 'utf8')); } catch (error) { problems.push(`${RULES_PATH} is not JSON: ${error.message}`); }
  }
  if (rules) {
    const found = rulesProblems(shape, rules, columns);
    for (const problem of found) problems.push(`${RULES_PATH}: ${problem}`);
    if (!found.length) {
      if (!fs.existsSync(documentFile)) problems.push(`${DOCUMENT_PATH} does not exist`);
      else {
        const before = fs.readFileSync(documentFile, 'utf8');
        const placed = placeBlocks(before, documentBlocks(shape, rules));
        const missing = placed.problems.filter((problem) => problem.includes('are missing'));
        if (write && !option('--document') && !missing.length) { if (placed.text !== before) fs.writeFileSync(documentFile, placed.text); }
        else for (const problem of placed.problems) problems.push(`${DOCUMENT_PATH}: ${problem}`);
        for (const problem of write && !option('--document') ? missing : []) problems.push(`${DOCUMENT_PATH}: ${problem}`);
      }
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
  const placed = Object.values(rules.words).reduce((sum, rule) => sum + Object.keys(rule.places).length, 0);
  const without = Object.values(rules.words).reduce((sum, rule) => sum + Object.keys(rule.without).length, 0);
  console.log(`Old system's rules: ${Object.keys(rules.words).length} columns of tables that move have their words placed (${placed} with a place, ${without} with a reason for none); `
    + `${rules.unique.length} unique indexes have a rule (${rules.unique.filter((rule) => rule.value === 'carried').length} take a value of the old system); `
    + `${entryNames(shape, rules).length} names in the table of the old numbers. ${RULES_PATH} agrees with the old system, and document 36 shows both files.`);
  process.exit(0);
}

if (process.argv[1] && import.meta.url === pathToFileURL(path.resolve(process.argv[1])).href) {
  main().catch((error) => { console.error(error); process.exit(1); });
}

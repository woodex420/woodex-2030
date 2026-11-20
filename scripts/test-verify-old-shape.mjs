#!/usr/bin/env node
/**
 * Tries scripts/verify-old-shape.mjs: one thing is changed at a time in a copy of
 * platform/resources/data/old-system-shape.json, and the check must fail and name what was changed.
 * A check that has never been seen to fail proves nothing (master list T-176 and T-177).
 *
 *   node scripts/test-verify-old-shape.mjs
 */
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { ROOT } from './lib/pg-fixture.mjs';
import { SHAPE_PATH, buildShape, differences, listInCode, print, readCheck } from './verify-old-shape.mjs';

const problems = [];
let tried = 0;
function check(ok, label, detail = '') {
  tried += 1;
  if (ok) console.log(`  ✓ ${label}`);
  else { problems.push(label); console.log(`  ✗ ${label}${detail ? ` — ${detail}` : ''}`); }
}
const copy = (value) => JSON.parse(JSON.stringify(value));

const { shape, problems: unreadable } = await buildShape();
check(unreadable.length === 0, 'the schema, the code and the map are read without a problem', unreadable.join('; '));
const onDisk = JSON.parse(fs.readFileSync(path.join(ROOT, SHAPE_PATH), 'utf8'));
check(differences(shape, onDisk).length === 0, 'the file as it stands agrees with what is built', differences(shape, onDisk).join('; '));

console.log('\nOne mistake at a time in a copy of the file');
/** Each: what is changed, and the words the check must answer with. */
const mistakes = [
  ['a word taken out of a vocabulary', (s) => { s.tables.invoices.words.status.pop(); }, ['invoices: "words"', 'Cancelled']],
  ['a word added to a vocabulary by hand', (s) => { s.tables.tasks.words.priority.push('urgent'); }, ['tasks: "words"', 'urgent']],
  ['the two columns of a key in the other order', (s) => { s.tables.stock_levels.key.reverse(); }, ['stock_levels: "key"']],
  ['a key said to be a text where it is a number', (s) => { s.tables.leads.key[0][1] = 'text'; }, ['leads: "key"']],
  ['a table left out', (s) => { delete s.tables.meetings; }, ['meetings: a table of the old system that the file does not have']],
  ['a table added by hand', (s) => { s.tables.wishlists = copy(s.tables.leads); }, ['wishlists: in the file, but the old system has no such table']],
  ['a table that waits said to move', (s) => { s.tables.orders.fate = 'moves'; }, ['orders: "fate"', '"waits"']],
  ['another target than the map names', (s) => { s.tables.suppliers.to = ['suppliers']; }, ['suppliers: "to"', 'vendors']],
  ['a word taken out of a list that only the code holds', (s) => { s.tables.quotes.words_in_code.status.words.shift(); }, ['quotes: "words_in_code"', 'Draft']],
  ['a 0-or-1 column left out', (s) => { s.tables.tasks.flags = []; }, ['tasks: "flags"', 'done']],
  ['a yes-or-no column left out', (s) => { s.tables.contacts.booleans = []; }, ['contacts: "booleans"', 'is_primary']],
  ['the heading rewritten', (s) => { s.about = 'edited by hand'; }, ['the heading "about" was changed']],
];
for (const [label, change, expected] of mistakes) {
  const changed = copy(onDisk);
  change(changed);
  const found = differences(shape, changed).join('\n');
  check(found !== '' && expected.every((part) => found.includes(part)), label, found === '' ? 'the check found nothing' : `it said: ${found}`);
}
{
  const reordered = { about: onDisk.about, tables: Object.fromEntries(Object.entries(onDisk.tables).reverse()) };
  const found = differences(shape, reordered);
  check(found.length === 1 && found[0].includes('another order'), 'the same facts in another order', found.join('; '));
}

console.log('\nA CHECK constraint as PostgreSQL prints it');
const text = (words) => words.map((word) => `'${word}'::text`).join(', ');
check(JSON.stringify(readCheck('status', `CHECK ((status = ANY (ARRAY[${text(['open', 'closed'])}])))`)) === '{"words":["open","closed"]}', 'a list of words is read');
check(JSON.stringify(readCheck('match_state', `CHECK (((match_state IS NULL) OR (match_state = ANY (ARRAY[${text(['matched', 'unlinked'])}]))))`)) === '{"words":["matched","unlinked"]}', 'a list of words on a column that may be empty is read');
check(JSON.stringify(readCheck('state', `CHECK ((state = ANY (ARRAY[${text(['Partially Paid', "it's"]).replace("it's", "it''s")}])))`)) === '{"words":["Partially Paid","it\'s"]}', 'a word with a space, and one with a quote, are read whole');
check(readCheck('done', 'CHECK ((done = ANY (ARRAY[0, 1])))')?.flag === true, 'a column held to 0 or 1 is a flag, not a vocabulary');
check(readCheck('name', "CHECK ((btrim(name) <> ''::text))")?.other === true, 'a rule about a length is no vocabulary');
check(readCheck('qty', 'CHECK ((qty > 0))')?.other === true, 'a rule about a range is no vocabulary');
check(readCheck('status', "CHECK (((status = 'open'::text) OR (status = 'closed'::text)))") === null, 'words compared one by one are not guessed at: the script says it cannot read them');
check(readCheck('level', 'CHECK ((level = ANY (ARRAY[1, 2, 3])))') === null, 'a list of numbers other than 0 and 1 is not taken for a flag');

console.log('\nA list of words in the code of the old API');
check(JSON.stringify(listInCode('const A = new Set(["x", "y z"]);\n', 'A')) === '["x","y z"]', 'a set of words is read');
check(JSON.stringify(listInCode('const A = ["x", "y"];\n', 'A')) === '["x","y"]', 'a plain list is read');
check(listInCode('const A = new Set([...B, "x"]);\n', 'A') === null, 'a list built from another list is not guessed at');
check(listInCode('const B = ["x"];\n', 'A') === null, 'a list that is not there is not found');

console.log('\nThe command itself');
const work = fs.mkdtempSync(path.join(os.tmpdir(), 'woodex-old-shape-test-'));
const run = (file) => spawnSync(process.execPath, [path.join(ROOT, 'scripts/verify-old-shape.mjs'), '--file', file], { encoding: 'utf8' });
{
  const file = path.join(work, 'changed.json');
  const changed = copy(onDisk);
  changed.tables.site_visits.words.status = ['Scheduled'];
  fs.writeFileSync(file, print(changed));
  const result = run(file);
  check(result.status === 1 && result.stderr.includes('site_visits: "words"') && result.stderr.includes('--write'), 'with a changed file it ends with 1, names the table and says how to write the file again', `${result.status}: ${result.stderr.slice(0, 300)}`);
}
{
  const file = path.join(work, 'layout.json');
  fs.writeFileSync(file, JSON.stringify(onDisk, null, 2));
  const result = run(file);
  check(result.status === 1 && result.stderr.includes('another layout'), 'with the same facts in another layout it ends with 1', `${result.status}: ${result.stderr.slice(0, 300)}`);
}
{
  const file = path.join(work, 'same.json');
  fs.writeFileSync(file, print(onDisk));
  const result = run(file);
  check(result.status === 0, 'with the file as it stands it ends with 0', `${result.status}: ${result.stderr.slice(0, 300)}`);
}
fs.rmSync(work, { recursive: true, force: true });

console.log(problems.length ? `\ntest-verify-old-shape: ${problems.length} of ${tried} FAILED` : `\ntest-verify-old-shape: ${tried} of ${tried} hold`);
process.exit(problems.length ? 1 : 0);

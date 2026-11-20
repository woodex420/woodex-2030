#!/usr/bin/env node
/**
 * Tries scripts/verify-old-shape.mjs: one thing is changed at a time in a copy of
 * platform/resources/data/old-system-shape.json, of platform/resources/data/old-system-rules.json or of
 * document 36, and the check must fail and name what was changed.
 * A check that has never been seen to fail proves nothing (master list T-176 and T-177).
 *
 *   node scripts/test-verify-old-shape.mjs
 */
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { ROOT } from './lib/pg-fixture.mjs';
import {
  DOCUMENT_PATH, RULES_PATH, SHAPE_PATH, SYSTEM, buildShape, differences, documentBlocks, entryNames, enumValues, listInCode, placeBlocks, print,
  readCheck, rulesProblems,
} from './verify-old-shape.mjs';

const problems = [];
let tried = 0;
function check(ok, label, detail = '') {
  tried += 1;
  if (ok) console.log(`  ✓ ${label}`);
  else { problems.push(label); console.log(`  ✗ ${label}${detail ? ` — ${detail}` : ''}`); }
}
const copy = (value) => JSON.parse(JSON.stringify(value));

const { shape, problems: unreadable, columns } = await buildShape();
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

console.log('\nThe rules, written by hand: one mistake at a time in a copy');
const rules = JSON.parse(fs.readFileSync(path.join(ROOT, RULES_PATH), 'utf8'));
check(rulesProblems(shape, rules, columns).length === 0, 'the rules as they stand agree with the old system', rulesProblems(shape, rules, columns).join('; '));
const unique = (r, table, column) => r.unique.find((rule) => rule.table === table && rule.columns.join('+') === column);
const ruleMistakes = [
  ['a word loses its place', (r) => { delete r.words['invoices.status'].places.Paid; }, ['invoices.status: the word "Paid" has neither a place nor a reason']],
  ['a word the old system does not allow is given a place', (r) => { r.words['tasks.priority'].places.urgent = 'high'; }, ['tasks.priority: the word "urgent" is written, but the old system does not allow it there']],
  ['a word has a place and a reason for having none', (r) => { r.words['quotes.status'].places.Approved = 'approved'; }, ['quotes.status: the word "Approved" has a place and a reason for having none']],
  ['a word is sent to a value the admin\'s list does not have', (r) => { r.words['invoices.status'].places.Cancelled = 'cancelled'; }, ['invoices.status: the word "Cancelled" is sent to "cancelled", which InvoiceStatus does not have']],
  ['a list of the admin that does not exist', (r) => { r.words['tasks.priority'].enum = 'App\\Domain\\Crm\\Enums\\TaskPriority'; }, ['tasks.priority: "App\\Domain\\Crm\\Enums\\TaskPriority" is not an enumeration of the admin']],
  ['a whole column left out', (r) => { delete r.words['quotes.status']; }, ['quotes.status: the old system lists its words and its table moves, but the rules give them no place']],
  ['the words of a table that waits are placed', (r) => { r.words['orders.status'] = { enum: null, places: {}, without: {} }; }, ['orders.status: the rules place its words, but by the map the rows of "orders" are not brought over']],
  ['words are placed for a column that lists none', (r) => { r.words['leads.status'] = { enum: null, places: {}, without: {} }; }, ['leads.status: the rules place its words, but the old system lists none for it']],
  ['a word has no place and its reason is empty', (r) => { r.words['meetings.status'].without.no_show = ' '; }, ['meetings.status: the word "no_show" has no place and no reason is written']],
  ['a unique column takes its value from a column that does not exist', (r) => { unique(r, 'invoices', 'number').from = 'invoices.number'; }, ['invoices: number: its value is said to come from "invoices.number", and the old system has no such column']],
  ['a carried value without a rule for two that meet', (r) => { delete unique(r, 'leads', 'ref').rule; }, ['leads: ref: the old system\'s value is written here, and no rule says what happens when two meet']],
  ['a rule nobody knows', (r) => { unique(r, 'leads', 'ref').rule = 'last'; }, ['leads: ref: the old system\'s value is written here, and no rule says what happens when two meet']],
  ['a kind of value nobody knows', (r) => { unique(r, 'media', 'uuid').value = 'random'; }, ['media: uuid: "value" is "random"']],
  ['a unique column written twice', (r) => { r.unique.push(copy(unique(r, 'projects', 'code'))); }, ['projects: code: written twice']],
  ['a column the admin fills is given a rule', (r) => { unique(r, 'vendors', 'public_id').rule = 'stop'; }, ['vendors: public_id: only a value the old system brings has a rule']],
  ['a unique column with nothing said about it', (r) => { unique(r, 'payments', 'receipt_number').why = ''; }, ['payments: receipt_number: nothing says what the column is']],
  ['lines in a table the map does not name', (r) => { r.lines[1].into = 'quote_lines'; }, ['lines: the map does not send the rows of "quotes" into "quote_lines"']],
  ['lines of a table that waits', (r) => { r.lines[0].from = 'orders'; }, ['lines: "orders" is not a table of the old system that moves']],
  ['a part of the file taken out', (r) => { delete r.lines; }, ['the part "lines" is missing']],
];
for (const [label, change, expected] of ruleMistakes) {
  const changed = copy(rules);
  change(changed);
  const found = rulesProblems(shape, changed, columns).join('\n');
  check(found !== '' && expected.every((part) => found.includes(part)), label, found === '' ? 'the check found nothing' : `it said: ${found}`);
}
check(JSON.stringify(rulesProblems(shape, [], columns)) === '["the file holds no rules"]', 'a file that holds a list instead of rules');

console.log('\nWhat is read from the admin and derived from the map');
check(enumValues('App\\Domain\\Sales\\Enums\\InvoiceStatus')?.join(',') === 'draft,issued,partially_paid,paid,void', 'the values of an enumeration of the admin are read from its file', String(enumValues('App\\Domain\\Sales\\Enums\\InvoiceStatus')));
check(enumValues('App\\Domain\\Sales\\Enums\\NoSuchStatus') === null && enumValues('Vendor\\Thing') === null && enumValues(null) === null, 'an enumeration that is not there is not guessed at');
{
  const names = entryNames(shape, rules).map(([, , name]) => name);
  const targets = Object.values(shape.tables).filter((entry) => entry.fate === 'moves').reduce((sum, entry) => sum + entry.to.length, 0);
  check(names.length === targets - rules.lines.length && new Set(names).size === names.length && names.every((name) => name.startsWith(SYSTEM) && name.length <= 60),
    'one name for each table that moves and each table it goes into, without the lines; each begins with node. and fits 60 characters', `${names.length} names for ${targets} targets`);
  check(names.includes('node.leads>contacts') && names.includes('node.leads>leads') && !names.includes('node.quotes>quote_items') && !names.some((name) => name.startsWith('node.orders')),
    'an enquiry is entered twice, the lines of a quotation not at all, a table that waits not yet');
}

console.log('\nDocument 36, §8 and §9');
const document = fs.readFileSync(path.join(ROOT, DOCUMENT_PATH), 'utf8');
const blocks = documentBlocks(shape, rules);
check(placeBlocks(document, blocks).problems.length === 0, 'the document as it stands shows what the two files say', placeBlocks(document, blocks).problems.join('; '));
{
  const edited = document.replace('| `accounts.kind` | `revenue` | `income` (AccountType) |', '| `accounts.kind` | `revenue` | `revenue` (AccountType) |');
  const found = placeBlocks(edited, blocks);
  check(edited !== document && found.problems.length === 1 && found.problems[0].includes('the block "shape-words" does not show what the two files say') && found.text === document,
    'a line of a block edited by hand is named, and writing the block again restores it', found.problems.join('; '));
}
{
  const edited = document.replace('<!-- /generated:shape-unique -->', '<!-- /generated:shape-uniqe -->');
  const found = placeBlocks(edited, blocks).problems.join('; ');
  check(found.includes('the marks of the block "shape-unique" are missing'), 'a block whose marks are gone is named', found);
}
{
  const changed = copy(rules);
  changed.words['conversations.status'].places.resolved = 'pending';
  const found = placeBlocks(document, documentBlocks(shape, changed)).problems.join('; ');
  check(found.includes('the block "shape-words"'), 'a place changed in the rules and not in the document is named', found);
}
{
  const changed = copy(rules);
  changed.lines.pop();
  const found = placeBlocks(document, documentBlocks(shape, changed)).problems.join('; ');
  check(found.includes('the block "shape-entries"') && found.includes('the block "shape-numbers"'), 'an entry more than the document shows is named, in the list and in the numbers', found);
}

console.log('\nThe command itself');
const work = fs.mkdtempSync(path.join(os.tmpdir(), 'woodex-old-shape-test-'));
const run = (file, option = '--file') => spawnSync(process.execPath, [path.join(ROOT, 'scripts/verify-old-shape.mjs'), option, file], { encoding: 'utf8' });
{
  const file = path.join(work, 'rules.json');
  const changed = copy(rules);
  delete changed.words['site_visits.status'].places.Confirmed;
  fs.writeFileSync(file, `${JSON.stringify(changed, null, 1)}\n`);
  const result = run(file, '--rules');
  check(result.status === 1 && result.stderr.includes('site_visits.status: the word "Confirmed" has neither a place nor a reason'), 'with a word taken out of the rules it ends with 1 and names the word', `${result.status}: ${result.stderr.slice(0, 300)}`);
}
{
  const file = path.join(work, 'rules-broken.json');
  fs.writeFileSync(file, '{ "words": ');
  const result = run(file, '--rules');
  check(result.status === 1 && result.stderr.includes('is not JSON'), 'with rules that are not JSON it ends with 1 and says so', `${result.status}: ${result.stderr.slice(0, 300)}`);
}
{
  const file = path.join(work, 'document.md');
  const line = '| `leads` | `id` (a number) | `node.leads>leads`, `node.leads>contacts` |';
  fs.writeFileSync(file, document.replace(line, line.replace('`node.leads>contacts`', '`node.leads>people`')));
  const result = run(file, '--document');
  check(result.status === 1 && result.stderr.includes('the block "shape-entries" does not show what the two files say') && result.stderr.includes('--write'), 'with a name changed in the document it ends with 1, names the block and says how to write it again', `${result.status}: ${result.stderr.slice(0, 300)}`);
  check(fs.readFileSync(file, 'utf8').includes('`node.leads>people`'), 'and it does not write to the copy it was handed');
}
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

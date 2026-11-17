#!/usr/bin/env node
/**
 * WOODEX OS: the check of the pending-work plan (scripts/verify-plan.mjs), tried against what it is there to stop.
 *
 * A check that passes is worth something only if it can fail. This copies the check and the four files it reads
 * into a temporary folder, changes one thing at a time, and requires two things each time: the check fails, and
 * it names the reason. The working tree is never touched.
 *
 *   node scripts/test-verify-plan.mjs
 */
import { spawnSync } from 'node:child_process';
import { cpSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const LIST = 'docs/woodex-os/14-PRODUCTION-TODO.md';
const PLAN = 'docs/woodex-os/acceptance/plan.json';
const DOCUMENT = 'docs/woodex-os/21-PENDING-WORK-PLAN-AND-TESTS.md';
const copied = ['scripts/verify-plan.mjs', LIST, PLAN, DOCUMENT, 'docs/woodex-os/acceptance/defects.json'];

const work = mkdtempSync(path.join(tmpdir(), 'woodex-plan-'));
const at = (file) => path.join(work, file);
const fresh = () => {
  for (const file of copied) {
    mkdirSync(path.dirname(at(file)), { recursive: true });
    cpSync(path.join(root, file), at(file));
  }
};
const read = (file) => readFileSync(at(file), 'utf8');
const save = (file, text) => writeFileSync(at(file), text);
const check = () => {
  const run = spawnSync(process.execPath, [at('scripts/verify-plan.mjs')], { encoding: 'utf8' });
  return { code: run.status, output: `${run.stdout}${run.stderr}` };
};

/** Fails loudly when a case no longer finds what it changes: a case that changes nothing proves nothing. */
const must = (condition, what) => {
  if (!condition) throw new Error(`the case cannot be set up: ${what}`);
};
const replaceOnce = (file, from, to) => {
  const text = read(file);
  must(text.split(from).length === 2, `"${from.slice(0, 60)}" is not exactly once in ${file}`);
  save(file, text.replace(from, to));
};
const plan = (change) => {
  const data = JSON.parse(read(PLAN));
  change(data);
  save(PLAN, `${JSON.stringify(data, null, 2)}\n`);
};
const rowsOf = () => read(LIST).split('\n');
const rowIndex = (lines, id) => {
  const index = lines.findIndex((line) => line.startsWith(`| ${id} |`));
  must(index >= 0, `${id} is not a row of the list`);
  return index;
};
/** Rewrites the last cell of a row: its status. */
const status = (id, value) => {
  const lines = rowsOf();
  const index = rowIndex(lines, id);
  const cells = lines[index].split(' | ');
  cells[cells.length - 1] = `${value} |`;
  lines[index] = cells.join(' | ');
  save(LIST, lines.join('\n'));
};
const orderOfWork = (change) => {
  const text = read(LIST);
  const from = text.indexOf('1. **Now, needing nobody**');
  const to = text.indexOf('**Done on', from);
  must(from >= 0 && to > from, 'point 1 of "Order of work" was not found');
  const changed = change(text.slice(from, to));
  must(changed !== text.slice(from, to), 'point 1 of "Order of work" was not changed');
  save(LIST, text.slice(0, from) + changed + text.slice(to));
};

// The lines the cases use are read from the files, so that the cases keep working as the plan moves on.
fresh();
const source = JSON.parse(read(PLAN));
const planned = source.rounds.flatMap((round) => round.lines.map((line) => line.id));
must(planned.length >= 2 && source.rounds.length >= 2, 'the plan needs two rounds and two lines for these cases');
const [first, second] = planned;
const kindOf = (id) => rowsOf()[rowIndex(rowsOf(), id)].split(' | ').at(-1).replace(/ \|$/, '');
const listed = rowsOf().map((line) => line.match(/^\| (T-\d{3}) \|/)?.[1]).filter(Boolean);
const doneLine = listed.find((id) => kindOf(id).startsWith('**Done**'));
const waiting = listed.filter((id) => /^Later \(T-\d{3}\)$/.test(kindOf(id)));
const ownerLine = listed.find((id) => kindOf(id).startsWith('Owner'));
must(doneLine && waiting.length >= 2 && ownerLine, 'the list needs a done line, two waiting lines and a line of the owner');
const lastPlanned = planned.at(-1);

const cases = [
  ['a line that can start now is taken out of its round',
    () => plan((data) => data.rounds[0].lines.shift()), `${first} can start now and is in no round`],
  ['a line is marked done and still stands in a round',
    () => status(first, '**Done** today'), `${first}: is done in the list`],
  ['a line waits again and still stands in a round',
    () => status(first, `Later (${ownerLine})`), `${first}: waits for an earlier line in the list`],
  ['the same line in two rounds',
    () => plan((data) => data.rounds[1].lines.push({ ...data.rounds[0].lines[0] })), `${first}: is also in round 1`],
  ['a round names a line that is not on the list',
    () => plan((data) => { data.rounds[0].lines[0].id = 'T-999'; }), 'T-999: is not a line of the list'],
  ['a line of a round names no test',
    () => plan((data) => { data.rounds[0].lines[0].tests = []; }), `${first}: names no test`],
  ['a line of a round does not say what is built',
    () => plan((data) => { data.rounds[0].lines[0].build = []; }), `${first}: does not say what is built`],
  ['a line of a round does not say what its tests are tried against',
    () => plan((data) => { data.rounds[0].lines[0].against = ''; }), `${first}: does not say what its tests are tried against`],
  ['a line of a round gives no way back',
    () => plan((data) => { delete data.rounds[0].lines[0].back; }), `${first}: gives no way back`],
  ['a line of a round does not say what was found',
    () => plan((data) => { data.rounds[0].lines[0].found = ' '; }), `${first}: does not say what was found`],
  ['a line that is not done has no short title',
    () => plan((data) => { delete data.titles[ownerLine]; }), `${ownerLine} is not done and has no short title`],
  ['a title is kept for a line that is done',
    () => plan((data) => { data.titles[doneLine] = 'A line that is finished'; }), `a title for ${doneLine}, which is done`],
  ['a title longer than 80 characters',
    () => plan((data) => { data.titles[ownerLine] = 'x'.repeat(81); }), `the title of ${ownerLine} has 81 characters`],
  ['a waiting line waits only for a line that is done',
    () => status(waiting[0], `Later (${doneLine})`), `${waiting[0]}: waits only for ${doneLine}, which is done`],
  ['a waiting line waits for a line that is not on the list',
    () => status(waiting[0], 'Later (T-999)'), `${waiting[0]}: waits for T-999, which is not a line of the list`],
  ['a waiting line does not say what it waits for',
    () => status(waiting[0], 'Later'), `${waiting[0]}: a waiting line says what it waits for in brackets`],
  ['a status that is none of the four',
    () => status(second, 'Soon'), `${second}: the status begins "Soon"`],
  ['two rows out of order',
    () => {
      const lines = rowsOf();
      const index = rowIndex(lines, waiting[0]);
      const next = lines.findIndex((line, at) => at > index && /^\| T-\d{3} \|/.test(line));
      must(next > index, 'no row follows the one to be moved');
      [lines[index], lines[next]] = [lines[next], lines[index]];
      save(LIST, lines.join('\n'));
    }, 'the rows are in the order of their numbers'],
  ['the same row twice',
    () => {
      const lines = rowsOf();
      const index = rowIndex(lines, lastPlanned);
      lines.splice(index + 1, 0, lines[index]);
      save(LIST, lines.join('\n'));
    }, 'a line number is used twice'],
  ['the list\'s own numbers changed by hand',
    () => {
      const lines = rowsOf();
      const index = lines.findIndex((line) => line.startsWith('**This list in numbers:**'));
      must(index >= 0, 'the numbers line was not found');
      const changed = lines[index].replace(/(\d+) lines/, (all, count) => `${Number(count) + 1} lines`);
      must(changed !== lines[index], 'the numbers line was not changed');
      lines[index] = changed;
      save(LIST, lines.join('\n'));
    }, '"This list in numbers" does not agree with the rows'],
  ['"Order of work" leaves out a line that can start now',
    () => orderOfWork((text) => text.replace(new RegExp(`(, )?${lastPlanned}\\b`), '')), `does not name ${lastPlanned}, which can start now`],
  ['"Order of work" names a line that cannot start now',
    () => orderOfWork((text) => text.replace(first, `${first}, ${waiting[1]}`)), `names ${waiting[1]}, which is not a line that can start now`],
  ['a generated block of document 21 changed by hand',
    () => replaceOnce(DOCUMENT, `#### ${first} · `, `#### ${first} - `), 'the block "rounds" does not show what the list and the plan say'],
  ['a mark of a generated block damaged',
    () => replaceOnce(DOCUMENT, '<!-- /generated:standing -->', '<!-- /generated:standin -->'), 'the marks of the block "standing" are missing'],
];

const failures = [];
try {
  const before = check();
  if (before.code !== 0) throw new Error(`the check must pass on the repository as it is before it is tried:\n${before.output}`);
  for (const [name, change, reason] of cases) {
    fresh();
    change();
    const { code, output } = check();
    const refused = code !== 0 && output.includes(reason);
    console.log(`${refused ? 'refused' : 'NOT REFUSED'}  ${name}`);
    if (!refused) failures.push(`${name}\n    expected the check to fail and to say: ${reason}\n    exit ${code}; it said:\n${output.split('\n').map((line) => `      ${line}`).join('\n')}`);
  }
  fresh();
  const after = check();
  if (after.code !== 0) failures.push(`the check fails on an untouched copy:\n${after.output}`);
} finally {
  rmSync(work, { recursive: true, force: true });
}

if (failures.length) {
  console.error(`\n${failures.length} of ${cases.length} cases were not refused:\n\n${failures.join('\n\n')}`);
  process.exit(1);
}
console.log(`\nThe plan check refuses all ${cases.length} cases, each for its own reason.`);

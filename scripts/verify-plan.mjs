#!/usr/bin/env node
/**
 * WOODEX OS: what is still to do, the plan for it and the test of every line, kept true by a check.
 *
 * docs/woodex-os/14-PRODUCTION-TODO.md is the one list of the work. This script reads every row of it together
 * with docs/woodex-os/acceptance/plan.json and writes the tables of
 * docs/woodex-os/21-PENDING-WORK-PLAN-AND-TESTS.md from the two.
 *
 * It fails when:
 *   - a row of the list has a status that is not Done, Next, Later or Owner, or the rows are out of order;
 *   - the list's line "This list in numbers" does not agree with its rows;
 *   - the first point of the list's "Order of work" does not name exactly the lines that can start now;
 *   - a line that can start now is in no round of the plan, or in two; or a round names a line that cannot
 *     start now (it is done, it waits, or it is not on the list);
 *   - a line of a round does not say what is built, how it is tested, what its tests are tried against,
 *     or the way back;
 *   - a line that is not done has no short title, or a title belongs to a line that is done or unknown;
 *   - a waiting line does not say what it waits for, names a line that is not on the list, or waits only
 *     for lines that are done (then it can start, and the list must say so);
 *   - document 21 does not show what the list and the plan say.
 *
 *   node scripts/verify-plan.mjs           check (CI)
 *   node scripts/verify-plan.mjs --write   write the numbers line of document 14 and the tables of document 21,
 *                                          then check
 */
import { readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const at = (...parts) => path.join(root, ...parts);
const read = (...parts) => readFileSync(at(...parts), 'utf8');
const write = process.argv.includes('--write');

const listPath = 'docs/woodex-os/14-PRODUCTION-TODO.md';
const planPath = 'docs/woodex-os/acceptance/plan.json';
const documentPath = 'docs/woodex-os/21-PENDING-WORK-PLAN-AND-TESTS.md';

const problems = [];
const bad = (where, what) => problems.push(`${where}: ${what}`);
const unique = (values) => [...new Set(values)];
const number = (id) => Number(id.slice(2));
const plural = (count, one, many = `${one}s`) => `${count} ${count === 1 ? one : many}`;

/* ---------- the list ---------- */

const KINDS = { done: 'done', next: 'can start now', later: 'waits for an earlier line', owner: 'waits for the owner' };

let listText = read(listPath);
const rows = [];
{
  let section = '';
  let head = null;
  for (const line of listText.split('\n')) {
    const heading = line.match(/^## (.+)$/);
    if (heading) {
      section = heading[1];
      head = null;
      continue;
    }
    if (/^\| # \|/.test(line)) {
      head = line.replace(/^\|\s*/, '').replace(/\s*\|\s*$/, '').split(' | ').map((cell) => cell.trim());
      continue;
    }
    const match = line.match(/^\| (T-\d{3}) \|/);
    if (!match) continue;
    const id = match[1];
    if (!head) {
      bad(id, 'a row outside a table with a heading row');
      continue;
    }
    const cells = line.replace(/^\|\s*/, '').replace(/\s*\|\s*$/, '').split(' | ').map((cell) => cell.trim());
    if (cells.length !== head.length) {
      bad(id, `${cells.length} cells under ${head.length} headings`);
      continue;
    }
    const cell = Object.fromEntries(head.map((name, index) => [name, cells[index]]));
    const status = cell.Status ?? '';
    const kind = status.startsWith('**Done**') ? 'done' : (status.match(/^(Next|Later|Owner)\b/)?.[1] ?? '').toLowerCase();
    if (!KINDS[kind]) bad(id, `the status begins "${status.slice(0, 30)}", not Done, Next, Later or Owner`);
    if (!/^P[0-3]$/.test(cell.Pri ?? '')) bad(id, `the priority is "${cell.Pri}"`);
    rows.push({
      id,
      section,
      pri: cell.Pri,
      kind,
      status,
      task: cell.Task ?? cell['What is needed'] ?? '',
      doneWhen: cell['Done when'] ?? '',
      inForce: cell['Default in force until answered'] ?? '',
      blocks: cell.Blocks ?? '',
      isNew: line.includes('**NEW'),
      waitText: '',
      waitIds: [],
      waitOther: '',
      alsoBehind: [],
    });
  }
}
const byId = new Map(rows.map((row) => [row.id, row]));
if (byId.size !== rows.length) bad(listPath, 'a line number is used twice');
rows.forEach((row, index) => {
  if (index && number(rows[index - 1].id) >= number(row.id)) bad(row.id, `stands after ${rows[index - 1].id}: the rows are in the order of their numbers`);
});
const of = (kind) => rows.filter((row) => row.kind === kind);
const done = new Set(of('done').map((row) => row.id));
const pending = rows.filter((row) => row.kind !== 'done');

/** Every line number in a text; "T-130 to T-142" stands for the lines of the list between the two. */
function idsIn(text) {
  const found = [];
  const rest = text.replace(/T-(\d{3}) to T-(\d{3})/g, (whole, from, to) => {
    for (const row of rows) if (number(row.id) >= Number(from) && number(row.id) <= Number(to)) found.push(row.id);
    return ' ';
  });
  return unique([...found, ...(rest.match(/T-\d{3}/g) ?? [])]);
}

// What a waiting line waits for: the words in the brackets after "Later".
for (const row of of('later')) {
  const inner = row.status.match(/^Later \(([^)]*)\)/)?.[1];
  if (inner === undefined) {
    bad(row.id, 'a waiting line says what it waits for in brackets: "Later (T-003)"');
    continue;
  }
  row.waitText = inner;
  row.waitIds = idsIn(inner);
  row.waitOther = inner.replace(/T-\d{3}/g, ' ').replace(/\b(and|or|to)\b|[,;]/g, ' ').replace(/\s+/g, ' ').trim();
  for (const id of row.waitIds) if (!byId.has(id)) bad(row.id, `waits for ${id}, which is not a line of the list`);
}
for (const row of of('later')) {
  if (row.waitIds.length && !row.waitOther && row.waitIds.every((id) => done.has(id))) {
    bad(row.id, `waits only for ${row.waitIds.join(', ')}, which ${row.waitIds.length === 1 ? 'is' : 'are'} done: its status is "Next" now, or names what it still waits for`);
  }
}

/** The lines at the far end of what a line waits for: answers of the owner, and lines that can start now. */
function rootsOf(id, seen = new Set()) {
  const row = byId.get(id);
  if (!row || row.kind === 'done') return [];
  if (row.kind === 'owner' || row.kind === 'next') return [id];
  if (seen.has(id)) return [];
  seen.add(id);
  return unique(row.waitIds.flatMap((other) => rootsOf(other, seen))).sort();
}
// …and the owner's own table in the list (§3, "Blocks"): an answer it names as holding a line back is added to
// what that line waits for, unless the line stands behind that answer already through its own status.
for (const row of of('owner')) {
  for (const id of idsIn(row.blocks)) {
    const held = byId.get(id);
    if (!held) bad(row.id, `is said to hold back ${id}, which is not a line of the list`);
    else if (held.kind === 'later' && !rootsOf(id).includes(row.id)) {
      held.waitIds.push(row.id);
      held.alsoBehind.push(row.id);
    }
  }
}
for (const row of of('later')) {
  const roots = rootsOf(row.id);
  row.ownerRoots = roots.filter((id) => byId.get(id).kind === 'owner');
  row.agentRoots = roots.filter((id) => byId.get(id).kind === 'next');
  row.group = row.ownerRoots.length ? 'owner' : row.agentRoots.length ? 'agent' : 'event';
}
/** The waiting lines that can start as soon as every line of `set` is done. */
const opensWith = (set) => of('later').filter((row) => row.waitIds.length && !row.waitOther
  && row.waitIds.some((id) => set.has(id)) && row.waitIds.every((id) => set.has(id) || done.has(id)));
const heldBackBy = (id) => of('later').filter((row) => row.ownerRoots.includes(id));
/** What an answer of the owner sets going: the lines that then wait for nothing, or only for other answers. */
const setGoingBy = (id) => of('later')
  .filter((row) => !row.waitOther && row.waitIds.includes(id)
    && row.waitIds.every((other) => other === id || done.has(other) || byId.get(other)?.kind === 'owner'))
  .map((row) => {
    const others = row.waitIds.filter((other) => other !== id && !done.has(other));
    return others.length ? `${row.id} (with ${others.join(', ')})` : row.id;
  });

// The list's own count of itself.
const count = (kind) => of(kind).length;
const byPriority = (set) => ['P0', 'P1', 'P2', 'P3'].map((pri) => `${set.filter((row) => row.pri === pri).length} ${pri}`).join(', ');
const numbersLine = `**This list in numbers:** ${rows.length} lines — ${count('done')} done, ${count('next')} can start now, ${count('later')} wait for an earlier line or wave, ${count('owner')} wait for the owner. ${rows.filter((row) => row.isNew).length} are new since version 1. By priority: ${byPriority(rows)}.`;
{
  const present = listText.split('\n').find((line) => line.startsWith('**This list in numbers:**'));
  if (present === undefined) bad(listPath, 'the line "**This list in numbers:**" is missing');
  else if (present !== numbersLine) {
    if (write) {
      listText = listText.replace(present, numbersLine);
      writeFileSync(at(listPath), listText);
    } else bad(listPath, `"This list in numbers" does not agree with the rows; it should read: ${numbersLine} (run: node scripts/verify-plan.mjs --write)`);
  }
}
// The first point of "Order of work" names the lines that can start now, and no other.
{
  const start = listText.indexOf('1. **Now, needing nobody**');
  const end = listText.indexOf('**Done on', start);
  if (start < 0 || end < 0) bad(listPath, 'the point "1. **Now, needing nobody**" of "Order of work" is missing, or no longer ends with "**Done on"');
  else {
    const named = new Set(listText.slice(start, end).match(/T-\d{3}/g) ?? []);
    const startable = new Set(of('next').map((row) => row.id));
    for (const id of startable) if (!named.has(id)) bad(listPath, `"Order of work", point 1, does not name ${id}, which can start now`);
    for (const id of named) if (!startable.has(id)) bad(listPath, `"Order of work", point 1, names ${id}, which is not a line that can start now`);
  }
}

/* ---------- the plan ---------- */

const plan = JSON.parse(read(planPath));
const sizes = plan.sizes ?? {};
const text = (value) => typeof value === 'string' && value.trim() !== '';
const texts = (value) => Array.isArray(value) && value.every(text);

for (const row of pending) {
  const title = plan.titles?.[row.id];
  if (!text(title)) bad(planPath, `${row.id} is not done and has no short title`);
  else if (title.length > 80) bad(planPath, `the title of ${row.id} has ${title.length} characters; 80 at most`);
}
for (const id of Object.keys(plan.titles ?? {})) {
  if (!byId.has(id)) bad(planPath, `a title for ${id}, which is not a line of the list`);
  else if (done.has(id)) bad(planPath, `a title for ${id}, which is done: take it out`);
}
for (const id of Object.keys(plan.notes ?? {})) {
  if (!byId.has(id) || done.has(id)) bad(planPath, `a note for ${id}, which is done or not a line of the list: take it out`);
}
const roundOf = new Map();
(plan.rounds ?? []).forEach((round, index) => {
  const where = `${planPath}, round ${index + 1}`;
  if (!text(round.name) || !text(round.why)) bad(where, 'has no name, or does not say why it stands where it does ("name", "why")');
  if (!Array.isArray(round.lines) || !round.lines.length) bad(where, 'holds no line');
  for (const line of round.lines ?? []) {
    const row = byId.get(line.id);
    const here = `${where}, ${line.id}`;
    if (!row) bad(here, 'is not a line of the list');
    else if (row.kind !== 'next') bad(here, `${row.kind === 'done' ? 'is done' : KINDS[row.kind] ?? 'has no known status'} in the list: a round holds only lines that can start now. Take it out of the round`);
    if (roundOf.has(line.id)) bad(here, `is also in round ${roundOf.get(line.id)}`);
    roundOf.set(line.id, index + 1);
    if (!sizes[line.size]) bad(here, `its size is not one of ${Object.keys(sizes).join(', ')}`);
    if (!text(line.found)) bad(here, 'does not say what was found in the code or on the sites ("found")');
    if (!texts(line.build) || !line.build.length) bad(here, 'does not say what is built ("build")');
    if (!texts(line.tests) || !line.tests.length) bad(here, 'names no test ("tests")');
    if (!texts(line.browser)) bad(here, 'does not say what a browser does ("browser": an empty list when the line has no screen)');
    if (!text(line.against)) bad(here, 'does not say what its tests are tried against ("against")');
    if (!text(line.back)) bad(here, 'gives no way back ("back")');
  }
});
for (const row of of('next')) if (!roundOf.has(row.id)) bad(planPath, `${row.id} can start now and is in no round`);
for (const item of plan.standing ?? []) {
  if (!['check', 'where', 'today', 'catches'].every((key) => text(item[key]))) bad(planPath, 'a standing check does not say what it is, where it runs, its result today or what it stops');
}
if (!/^\d{4}-\d{2}-\d{2}$/.test(plan.written ?? '') || !text(plan.commit)) bad(planPath, '"written" is a date (2026-11-17) and "commit" names the commit the plan was read on');

/* ---------- document 21 ---------- */

const cell = (value) => String(value ?? '').replace(/\|/g, '\\|').replace(/\s*\n\s*/g, ' ').trim() || '—';
const table = (head, lines) => [`| ${head.join(' | ')} |`, `|${head.map(() => '---').join('|')}|`, ...lines.map((line) => `| ${line.map(cell).join(' | ')} |`)].join('\n');
const title = (id) => plan.titles?.[id] ?? byId.get(id)?.task ?? id;
const day = (iso) => {
  const [year, month, date] = iso.split('-').map(Number);
  return `${date} ${['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'][month - 1]} ${year}`;
};
const written = /^\d{4}-\d{2}-\d{2}$/.test(plan.written ?? '') ? day(plan.written) : 'the day it was written';
const numbered = (items) => items.map((item, index) => `  ${index + 1}. ${item}`).join('\n');

const groups = { owner: of('later').filter((row) => row.group === 'owner'), agent: of('later').filter((row) => row.group === 'agent'), event: of('later').filter((row) => row.group === 'event') };
const rounds = plan.rounds ?? [];
const most = of('owner').map((row) => ({ row, held: heldBackBy(row.id).length })).filter(({ held }) => held)
  .sort((a, b) => b.held - a.held || number(a.row.id) - number(b.row.id)).slice(0, 5);
// The defect register names the critical defects that are open, and the lines that close them.
const critical = JSON.parse(read('docs/woodex-os/acceptance/defects.json')).defects
  .filter((defect) => defect.severity === 'critical' && defect.state !== 'closed');

const blocks = {
  short: [
    `1. **${plural(pending.length, 'line')} of the list ${pending.length === 1 ? 'is' : 'are'} not done.** ${count('next')} can start now and need nobody: they are planned in ${plural(rounds.length, 'round')} (§2). ${count('owner')} wait for you (§3). ${count('later')} wait for an earlier line (§4), and ${groups.owner.length} of those stand behind an answer of yours.`,
    rounds.length
      ? `2. **The next round** is round 1: ${(rounds[0].lines ?? []).map((line) => `${line.id}, ${title(line.id)}`).join(' · ')}.`
      : '2. **No round is planned:** no line can start without you.',
    `3. **The answers of yours that the most lines stand behind:** ${most.length ? most.map(({ row, held }) => `${row.id}, ${title(row.id)} (${plural(held, 'line')})`).join(' · ') : 'none'}.`,
    critical.length
      ? `4. **${plural(critical.length, 'critical defect')} ${critical.length === 1 ? 'is' : 'are'} open:** ${critical.map((defect) => `${defect.id}, ${defect.title} — ${(defect.lines ?? []).map((id) => `${id}${byId.get(id)?.kind === 'owner' ? ', which waits for you' : ''}`).join('; ')}`).join(' · ')}.`
      : '4. **No critical defect is open.**',
    '5. **Every line has its test.** A line that can start now says in §2 what is built, which tests are written, what a browser does, and what the tests are tried against. A waiting line carries the list\'s own "done when" (§4). §5 names the checks every round passes before it is merged.',
  ].join('\n'),

  numbers: table(['', ''], [
    ['Lines on the list', `${rows.length}`],
    ['Done', `${count('done')}`],
    ['**Can start now, needing nobody**', `**${count('next')}**, in ${plural(rounds.length, 'round')} (§2)`],
    ['**Wait for you**', `**${count('owner')}** (§3)`],
    ['Wait for an earlier line', `${count('later')} (§4): ${groups.owner.length} stand behind an answer of yours · ${groups.agent.length} stand behind lines that need nobody · ${groups.event.length} go with a wave, a date or an event`],
    ['Not done, by priority', byPriority(pending)],
  ]),

  rounds: rounds.map((round, index) => {
    const lines = round.lines ?? [];
    const opened = opensWith(new Set(lines.map((line) => line.id)));
    return [
      `### Round ${index + 1} — ${round.name}`,
      '',
      `**Lines:** ${lines.map((line) => line.id).join(', ')} · **Why here:** ${round.why} · **When it is merged, these can start:** ${opened.length ? opened.map((row) => `${row.id} (${title(row.id)})`).join('; ') : 'no waiting line'}.`,
      ...lines.flatMap((line) => {
        const row = byId.get(line.id) ?? { pri: '?', doneWhen: '' };
        return [
          '',
          `#### ${line.id} · ${row.pri} · ${sizes[line.size] ?? line.size} — ${title(line.id)}`,
          '',
          `- **Done when** (the list): ${row.doneWhen || '—'}`,
          `- **Found on ${written}:** ${line.found}`,
          `- **What is built:**\n${numbered(line.build ?? [])}`,
          `- **Tests written:**\n${numbered(line.tests ?? [])}`,
          (line.browser ?? []).length ? `- **In a browser:**\n${numbered(line.browser)}` : '- **In a browser:** nothing; this line has no screen.',
          `- **Tried against:** ${line.against}`,
          `- **The way back:** ${line.back}`,
          ...(text(line.unknown) ? [`- **Not known yet:** ${line.unknown}`] : []),
          ...(text(line.yours) ? [`- **Yours:** ${line.yours}`] : []),
        ];
      }),
    ].join('\n');
  }).join('\n\n'),

  owner: table(['#', 'Pri', 'What is asked of you', 'In force until you answer', 'Can start the day you answer', 'Stand behind it'],
    of('owner')
      .map((row) => ({ row, opens: setGoingBy(row.id), held: heldBackBy(row.id) }))
      .sort((a, b) => a.row.pri.localeCompare(b.row.pri) || b.held.length - a.held.length || number(a.row.id) - number(b.row.id))
      .map(({ row, opens, held }) => [row.id, row.pri, title(row.id), row.inForce, opens.join(', '), held.length ? plural(held.length, 'line') : '—'])),

  waiting: [
    `#### Behind an answer of yours (${groups.owner.length})`,
    '',
    table(['#', 'Pri', 'Task', 'Waits for', 'Your answers it stands behind', 'Done when'],
      groups.owner.map((row) => [row.id, row.pri, title(row.id), waits(row), `${row.ownerRoots.join(', ')}${row.waitOther ? ` · and: ${row.waitOther}` : ''}`, row.doneWhen])),
    '',
    `#### Behind lines that need nobody (${groups.agent.length})`,
    '',
    table(['#', 'Pri', 'Task', 'Waits for', 'Stands behind', 'Done when'],
      groups.agent.map((row) => [row.id, row.pri, title(row.id), waits(row), row.agentRoots.map((id) => `${id} (round ${roundOf.get(id) ?? '?'})`).join(', '), row.doneWhen])),
    '',
    `#### With a wave, a date or an event (${groups.event.length})`,
    '',
    table(['#', 'Pri', 'Task', 'Waits for', 'Done when'],
      groups.event.map((row) => [row.id, row.pri, title(row.id), waits(row), row.doneWhen])),
  ].join('\n'),

  standing: table(['Check', 'Where it runs', `On ${written}`, 'What it stops'],
    (plan.standing ?? []).map((item) => [item.check, item.where, item.today, item.catches])),
};

/** What a waiting line waits for: the list's own words, what the owner's column adds, and the plan's note. */
function waits(row) {
  const also = row.alsoBehind.length ? `; also ${row.alsoBehind.join(', ')} (the list, §3)` : '';
  const note = plan.notes?.[row.id] ? ` — ${plan.notes[row.id]}` : '';
  return `${row.waitText}${also}${note}`;
}

let document = read(documentPath);
for (const [name, body] of Object.entries(blocks)) {
  const open = `<!-- generated:${name} — written by scripts/verify-plan.mjs --write; do not edit by hand -->`;
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
  else bad(documentPath, `the block "${name}" does not show what the list and the plan say (run: node scripts/verify-plan.mjs --write)`);
}
if (write) writeFileSync(at(documentPath), document);

/* ---------- the result ---------- */

console.log(`list        ${rows.length} lines: ${count('done')} done, ${count('next')} can start now, ${count('later')} wait for an earlier line, ${count('owner')} wait for the owner`);
console.log(`plan        ${plural(rounds.length, 'round')} for the ${count('next')} lines that can start now · ${Object.keys(plan.titles ?? {}).length} short titles`);
console.log(`waiting     ${groups.owner.length} behind an answer of the owner, ${groups.agent.length} behind lines that need nobody, ${groups.event.length} with a wave, a date or an event`);
console.log(`owner       the answers the most lines stand behind: ${most.map(({ row, held }) => `${row.id} (${held})`).join(', ')}`);
if (problems.length) {
  console.error(`\n${plural(problems.length, 'problem')}:`);
  for (const problem of problems) console.error(`  - ${problem}`);
  process.exit(1);
}
console.log(write ? '\nDocument 21 and the numbers of document 14 written and checked.' : '\nThe list, the plan and document 21 agree.');

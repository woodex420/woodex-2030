#!/usr/bin/env node
/**
 * WOODEX OS: the acceptance and release gates, kept true by a check.
 *
 * docs/woodex-os/acceptance/ holds five small files:
 *   scenarios.json  the 25 critical scenarios of document 10 §3, each with the tests that prove it
 *   flows.json      the eleven browser flows of document 10 §2, each with the journey steps that press it
 *   gates.json      the five release gates of document 10 §7, criterion by criterion
 *   modules.json    one acceptance sheet per open module: the ten conditions of document 07 §15
 *   defects.json    the defect register of document 10 §8
 *
 * This script fails when one of them stops being true to the repository:
 *   - the wording of a scenario, flow, gate criterion, condition or severity is not the specification's;
 *   - a named test is no longer declared in the named file under platform/tests;
 *   - a named journey step is no longer in scripts/os-journeys.mjs;
 *   - a file named in an evidence text does not exist;
 *   - a list line (T-…) is not a row of document 14, or a decision (DL-…) not a row of the decision log;
 *   - the open screens of the admin's menu are not exactly the screens the acceptance sheets cover;
 *   - a gate is marked passed while a criterion is not met, or a sheet accepted without the owner's decision;
 *   - docs/woodex-os/16-ACCEPTANCE-AND-RELEASE-GATES.md does not show what the five files say;
 *   - a release report (docs/woodex-os/NN-RELEASE-….md) lacks one of the ten points of document 10 §9.
 *
 *   node scripts/verify-acceptance.mjs           check (CI)
 *   node scripts/verify-acceptance.mjs --write   write the tables of document 16 from the five files, then check
 */
import { existsSync, readdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const at = (...parts) => path.join(root, ...parts);
const read = (...parts) => readFileSync(at(...parts), 'utf8');
const data = (name) => JSON.parse(read('docs/woodex-os/acceptance', name));
const write = process.argv.includes('--write');

const problems = [];
const bad = (where, what) => problems.push(`${where}: ${what}`);

/* ---------- what the specification says ---------- */

/** The bullet lines under a heading, up to the next heading of the same or a higher level. */
function bullets(text, heading) {
  const lines = text.split('\n');
  const start = lines.findIndex((line) => line.trim() === heading);
  if (start < 0) throw new Error(`heading "${heading}" not found`);
  const level = heading.match(/^#+/)[0].length;
  const found = [];
  for (const line of lines.slice(start + 1)) {
    const next = line.match(/^(#+) /);
    if (next && next[1].length <= level) break;
    const bullet = line.match(/^(?:- |\d+\. )(.+)$/);
    if (bullet) found.push(bullet[1].trim());
  }
  return found;
}
const spec10 = read('docs/woodex-os/spec/10-QA-AND-ACCEPTANCE.md');
const spec07 = read('docs/woodex-os/spec/07-MODULE-SPECIFICATIONS.md');
const specScenarios = ['CRM and sales', 'Finance', 'Inventory and production', 'Puck / Website Studio']
  .flatMap((group) => bullets(spec10, `### ${group}`).map((text) => ({ group, text })));
const specFlows = bullets(spec10, '### Browser end-to-end');
const specGates = [...spec10.matchAll(/^### Gate ([A-E]) — (.+)$/gm)].map((match) => ({ id: match[1], title: match[2].trim(), criteria: bullets(spec10, match[0]) }));
const specConditions = bullets(spec07, '## 15. Module completion definition');
const specSeverities = [...spec10.matchAll(/^\| (Critical|High|Medium|Low) \| ([^|]+) \| ([^|]+) \|$/gm)].map((match) => match[1].toLowerCase());

/* ---------- what the repository holds ---------- */

const listRows = new Set([...read('docs/woodex-os/14-PRODUCTION-TODO.md').matchAll(/^\| (T-\d{3}) \|/gm)].map((match) => match[1]));
const decisions = new Set([...read('docs/woodex-os/12-DECISION-LOG.md').matchAll(/^\| (DL-\d{3}) \|/gm)].map((match) => match[1]));
const journeySteps = new Set([...read('scripts/os-journeys.mjs').matchAll(/\bstep\('((?:\\.|[^'\\])*)'/g)].map((match) => match[1].replace(/\\(.)/g, '$1')));

const declared = new Map();
/** The names of the tests a Pest file declares: it('…') and test('…'), with either kind of quote. */
function testsOf(file) {
  if (!declared.has(file)) {
    const full = at('platform/tests', file);
    declared.set(file, existsSync(full)
      ? new Set([...readFileSync(full, 'utf8').matchAll(/^\s*(?:it|test)\(\s*(['"])((?:\\.|(?!\1)[^\\])*)\1/gm)].map((match) => match[2].replace(/\\(.)/g, '$1')))
      : null);
  }
  return declared.get(file);
}

/** Every list line, decision and file that a text names must exist. */
function references(where, text) {
  for (const [line] of text.matchAll(/\bT-\d{3}\b/g)) if (!listRows.has(line)) bad(where, `${line} is not a row of document 14`);
  for (const [decision] of text.matchAll(/\bDL-\d{3}\b/g)) if (!decisions.has(decision)) bad(where, `${decision} is not a row of the decision log`);
  for (const [, token] of text.matchAll(/`([^`]+)`/g)) {
    if (/^(Feature|Unit)\/[\w/]+\.php$/.test(token)) { if (!existsSync(at('platform/tests', token))) bad(where, `test file ${token} does not exist`); continue; }
    if (/^(scripts|docs|platform|deploy|apps|\.github)\/[\w./-]+$/.test(token) && !existsSync(at(token))) bad(where, `${token} does not exist`);
  }
}
const lines = (where, list, needed) => {
  if (!Array.isArray(list)) return bad(where, 'lines must be a list');
  for (const line of list) if (!listRows.has(line)) bad(where, `${line} is not a row of document 14`);
  if (needed && list.length === 0) bad(where, 'names no list line that closes it');
};
const sameList = (where, mine, theirs, what) => {
  if (mine.length !== theirs.length) bad(where, `${mine.length} ${what}, the specification has ${theirs.length}`);
  theirs.forEach((text, index) => { if (mine[index] !== text) bad(where, `${what} ${index + 1} reads "${mine[index] ?? '(missing)'}", the specification says "${text}"`); });
};

/* ---------- 1. scenarios ---------- */

const scenarios = data('scenarios.json').scenarios;
sameList('scenarios.json', scenarios.map((s) => s.text), specScenarios.map((s) => s.text), 'scenarios');
scenarios.forEach((scenario, index) => {
  const where = `scenarios.json ${scenario.id}`;
  if (scenario.id !== `AS-${String(index + 1).padStart(2, '0')}`) bad(where, 'ids must run AS-01, AS-02, … in the order of the specification');
  if (specScenarios[index] && scenario.group !== specScenarios[index].group) bad(where, `belongs to "${specScenarios[index].group}"`);
  if (!['proven', 'partly', 'not_built'].includes(scenario.state)) bad(where, `unknown state "${scenario.state}"`);
  if (scenario.state !== 'not_built' && scenario.tests.length === 0) bad(where, 'is called proven or partly proven and names no test');
  if (scenario.state === 'not_built' && scenario.tests.length > 0) bad(where, 'names tests for a module that is not built');
  for (const test of scenario.tests) {
    const names = testsOf(test.file);
    if (!names) bad(where, `test file ${test.file} does not exist`);
    else if (!names.has(test.name)) bad(where, `${test.file} no longer declares the test "${test.name}"`);
  }
  lines(where, scenario.lines, scenario.state !== 'proven');
  references(where, scenario.note ?? '');
});

/* ---------- 2. browser flows ---------- */

const flows = data('flows.json').flows;
sameList('flows.json', flows.map((f) => f.text), specFlows, 'flows');
for (const flow of flows) {
  const where = `flows.json "${flow.text}"`;
  if (!['covered', 'partly', 'none'].includes(flow.state)) bad(where, `unknown state "${flow.state}"`);
  if ((flow.state === 'none') !== (flow.steps.length === 0)) bad(where, 'a flow with no step is "none", and only such a flow');
  for (const step of flow.steps) if (!journeySteps.has(step)) bad(where, `scripts/os-journeys.mjs has no step "${step}"`);
  lines(where, flow.lines, flow.state !== 'covered');
  references(where, flow.note ?? '');
}

/* ---------- 3. defects ---------- */

const register = data('defects.json');
const defects = register.defects;
sameList('defects.json', Object.keys(register.severities), specSeverities, 'severities');
defects.forEach((defect, index) => {
  const where = `defects.json ${defect.id}`;
  if (defect.id !== `D-${String(index + 1).padStart(3, '0')}`) bad(where, 'ids must run D-001, D-002, … without a gap: a defect is never removed, it is closed');
  if (!register.severities[defect.severity]) bad(where, `unknown severity "${defect.severity}"`);
  if (!['open', 'accepted', 'closed'].includes(defect.state)) bad(where, `unknown state "${defect.state}"`);
  if (!/^\d{4}-\d{2}-\d{2}$/.test(defect.found ?? '')) bad(where, 'needs the day it was found');
  if (defect.state === 'closed' && !(defect.closed?.on && defect.closed?.how)) bad(where, 'a closed defect says when and how it was closed');
  if (defect.state !== 'closed' && defect.closed) bad(where, 'carries a closing note but is not closed');
  if (defect.state === 'accepted' && !/DL-\d{3}/.test(defect.accepted ?? '')) bad(where, 'an accepted defect names the decision (DL-…) in which the owner accepted it');
  if (defect.state === 'accepted' && ['critical', 'high'].includes(defect.severity)) bad(where, 'a critical or high defect blocks; it cannot be accepted');
  lines(where, defect.lines, defect.state !== 'closed');
  references(where, `${defect.title} ${defect.detail} ${defect.closed?.how ?? ''} ${defect.accepted ?? ''}`);
});
const openCritical = defects.filter((defect) => defect.severity === 'critical' && defect.state !== 'closed');

/* ---------- 4. gates ---------- */

const gates = data('gates.json').gates;
sameList('gates.json', gates.map((g) => `${g.id} — ${g.title}`), specGates.map((g) => `${g.id} — ${g.title}`), 'gates');
for (const [index, gate] of gates.entries()) {
  const where = `gates.json gate ${gate.id}`;
  sameList(where, gate.criteria.map((c) => c.text), specGates[index]?.criteria ?? [], 'criteria');
  for (const criterion of gate.criteria) {
    if (criterion.computed === 'no_open_critical') {
      criterion.state = openCritical.length ? 'open' : 'met';
      criterion.evidence = openCritical.length
        ? `${openCritical.length === 1 ? 'One critical defect is' : `${openCritical.length} critical defects are`} open: ${openCritical.map((d) => `${d.id} (${d.title.charAt(0).toLowerCase()}${d.title.slice(1)})`).join('; ')}.`
        : 'No critical defect is open in the register (§5).';
      criterion.lines = [...new Set(openCritical.flatMap((d) => d.lines))];
      continue;
    }
    if (!['met', 'partly', 'open'].includes(criterion.state)) bad(where, `"${criterion.text}" has the unknown state "${criterion.state}"`);
    lines(`${where} "${criterion.text}"`, criterion.lines, criterion.state !== 'met');
    references(`${where} "${criterion.text}"`, criterion.evidence);
  }
  const unmet = gate.criteria.filter((criterion) => criterion.state !== 'met');
  if (gate.passed && unmet.length) bad(where, `is marked passed while ${unmet.length} of its criteria are not met`);
  if (gate.passed && !/DL-\d{3}/.test(gate.owner_decision ?? '')) bad(where, 'is marked passed without the decision (DL-…) in which the owner passed it');
  references(where, `${gate.needs ?? ''} ${gate.owner_decision ?? ''}`);
}

/* ---------- 5. module sheets ---------- */

const modules = data('modules.json').modules;
for (const module of modules) {
  const where = `modules.json ${module.id}`;
  if (module.conditions.length !== specConditions.length) bad(where, `has ${module.conditions.length} conditions; document 07 §15 has ${specConditions.length}`);
  module.conditions.forEach((condition, index) => {
    const here = `${where} condition ${index + 1}`;
    if (!['met', 'partly', 'open', 'na'].includes(condition.state)) bad(here, `unknown state "${condition.state}"`);
    if (!condition.evidence) bad(here, 'says nothing about where it stands');
    lines(here, condition.lines, condition.state === 'partly' || condition.state === 'open');
    references(here, condition.evidence);
  });
  const last = module.conditions[specConditions.length - 1];
  if (module.signoff) {
    if (!['accepted', 'returned'].includes(module.signoff.decision)) bad(where, 'the owner\'s decision is "accepted" or "returned"');
    if (!/DL-\d{3}/.test(module.signoff.recorded ?? '') || !decisions.has(module.signoff.recorded.match(/DL-\d{3}/)[0])) bad(where, 'the owner\'s decision names its row of the decision log');
    if (module.signoff.decision === 'accepted' && module.conditions.some((c, i) => i < specConditions.length - 1 && !['met', 'na'].includes(c.state))) bad(where, 'is accepted while a condition before the last is not met');
  } else if (last && last.state === 'met') bad(where, 'the last condition is the owner\'s sign-off: it cannot be met without a recorded decision');
}
// The sheets cover exactly the screens that are open in the admin's menu: no more, no fewer.
const registry = [...read('platform/app/Support/AdminNavigation.php').matchAll(/self::item\('[^']+', '([^']+)', '[^']+', (null|'[^']+'), '[^']+'(?:, (?:null|'[^']+'))?(?:, (\d+))?\)/g)];
const openScreens = registry.filter((match) => match[2] !== 'null' && match[3] === undefined).map((match) => match[1]);
const covered = modules.flatMap((module) => module.destinations);
for (const screen of openScreens) if (!covered.includes(screen)) bad('modules.json', `the open screen "${screen}" belongs to no acceptance sheet`);
for (const screen of covered) if (!openScreens.includes(screen)) bad('modules.json', `"${screen}" is on a sheet but is not an open screen of the menu`);
if (new Set(covered).size !== covered.length) bad('modules.json', 'a screen is on two sheets');
if (registry.length < 60) bad('platform/app/Support/AdminNavigation.php', `only ${registry.length} menu entries could be read; the check no longer understands the file`);

/* ---------- the tables of document 16 ---------- */

const cell = (text) => String(text ?? '').replace(/\|/g, '\\|').replace(/\n/g, ' ');
const word = { met: 'Met', partly: 'Partly', open: 'Open', na: 'Does not apply', proven: 'Proven', not_built: 'Not built', covered: 'Pressed in full', none: 'Not pressed', closed: 'Closed', accepted: 'Accepted' };
const closedBy = (list) => (list.length ? list.join(', ') : '—');
const count = (list, key, value) => list.filter((item) => item[key] === value).length;
const table = (head, rows) => [`| ${head.join(' | ')} |`, `|${head.map(() => '---').join('|')}|`, ...rows.map((row) => `| ${row.map(cell).join(' | ')} |`)].join('\n');

const blocks = {};
blocks.gates = [
  table(['Gate', 'State', 'Criteria met', 'What it waits for'], gates.map((gate) => [
    `**${gate.id} — ${gate.title}**`, gate.passed ? `**Passed** (${gate.owner_decision})` : 'Not passed',
    `${count(gate.criteria, 'state', 'met')} of ${gate.criteria.length}`, gate.needs,
  ])),
  ...gates.map((gate) => `**Gate ${gate.id} — ${gate.title}**\n\n${table(['Criterion (document 10 §7)', 'State', 'Where it stands', 'Closed by'],
    gate.criteria.map((criterion) => [criterion.text, word[criterion.state], criterion.evidence, closedBy(criterion.lines)]))}`),
].join('\n\n');

blocks.scenarios = [
  `**${scenarios.length} scenarios: ${count(scenarios, 'state', 'proven')} proven, ${count(scenarios, 'state', 'partly')} partly, ${count(scenarios, 'state', 'not_built')} in modules that are not built.**`,
  ...[...new Set(scenarios.map((s) => s.group))].map((group) => `**${group}**\n\n${table(['#', 'Scenario (document 10 §3)', 'State', 'Proved by (under `platform/tests/`)', 'Note', 'Closed by'],
    scenarios.filter((s) => s.group === group).map((s) => [s.id, s.text, word[s.state],
      s.tests.length ? s.tests.map((t) => `\`${t.file.replace(/^Feature\//, '').replace(/\.php$/, '')}\` › ${t.name}`).join('<br>') : '—', s.note, closedBy(s.lines)]))}`),
].join('\n\n');

blocks.flows = [
  `**${flows.length} flows: ${count(flows, 'state', 'covered')} pressed in full, ${count(flows, 'state', 'partly')} in part, ${count(flows, 'state', 'none')} not pressed.** The journey has ${journeySteps.size} steps.`,
  table(['Flow (document 10 §2)', 'State', 'Steps of `scripts/os-journeys.mjs` that press it', 'Note', 'Closed by'],
    flows.map((f) => [f.text, word[f.state], f.steps.length ? f.steps.join('<br>') : '—', f.note, closedBy(f.lines)])),
].join('\n\n');

const decision = (module) => (module.signoff ? `**${module.signoff.decision === 'accepted' ? 'Accepted' : 'Returned'}** ${module.signoff.on} (${module.signoff.recorded})` : 'Not asked yet');
blocks.modules = [
  table(['Sheet', 'Screens', 'Met', 'Partly', 'Open', 'Does not apply', "Owner's decision"], modules.map((module, index) => [
    `4.${index + 1} **${module.title}**`, module.destinations.join(', '),
    count(module.conditions, 'state', 'met'), count(module.conditions, 'state', 'partly'), count(module.conditions, 'state', 'open'), count(module.conditions, 'state', 'na'), decision(module),
  ])),
  `${openScreens.length} screens are open in the menu, and each is on exactly one sheet.`,
  ...modules.map((module, index) => `### 4.${index + 1} ${module.title}\n\nScreens: ${module.destinations.join(', ')} · document 07 ${module.section} · owner's decision: ${decision(module)}\n\n${
    table(['#', 'Condition (document 07 §15)', 'State', 'Where it stands', 'Closed by'],
      module.conditions.map((condition, number) => [number + 1, specConditions[number], word[condition.state], condition.evidence, closedBy(condition.lines)]))}`),
].join('\n\n');

const unsettled = defects.filter((defect) => defect.state !== 'closed');
const settled = defects.filter((defect) => defect.state === 'closed');
const title = (text) => text.charAt(0).toUpperCase() + text.slice(1);
blocks.defects = [
  table(['Severity', 'Means (document 10 §8)', 'Release rule', 'Open', 'Accepted', 'Closed'], Object.entries(register.severities).map(([key, severity]) => [
    `**${title(key)}**`, severity.means, title(severity.rule),
    defects.filter((d) => d.severity === key && d.state === 'open').length, defects.filter((d) => d.severity === key && d.state === 'accepted').length, defects.filter((d) => d.severity === key && d.state === 'closed').length,
  ])),
  `**Not closed (${unsettled.length})**\n\n${table(['#', 'Severity', 'Found', 'Defect', 'Closed by'], unsettled.map((d) => [d.id, title(d.severity) + (d.state === 'accepted' ? ` — accepted (${d.accepted})` : ''), d.found, `**${d.title}.** ${d.detail}`, closedBy(d.lines)]))}`,
  `**Closed (${settled.length})**\n\n${table(['#', 'Severity', 'Found', 'Defect', 'Closed'], settled.map((d) => [d.id, title(d.severity), d.found, `**${d.title}.** ${d.detail}`, `${d.closed.on}. ${d.closed.how}`]))}`,
].join('\n\n');

const documentPath = 'docs/woodex-os/16-ACCEPTANCE-AND-RELEASE-GATES.md';
if (!existsSync(at(documentPath))) bad(documentPath, 'does not exist');
else {
  let text = read(documentPath);
  for (const [name, body] of Object.entries(blocks)) {
    const open = `<!-- generated:${name} — written by scripts/verify-acceptance.mjs --write; do not edit by hand -->`;
    const close = `<!-- /generated:${name} -->`;
    const from = text.indexOf(open);
    const to = text.indexOf(close);
    if (from < 0 || to < from) { bad(documentPath, `the markers of the block "${name}" are missing`); continue; }
    const current = text.slice(from + open.length, to).trim();
    if (current === body.trim()) continue;
    if (write) text = `${text.slice(0, from + open.length)}\n${body.trim()}\n${text.slice(to)}`;
    else bad(documentPath, `the block "${name}" does not show what docs/woodex-os/acceptance/ says; run: node scripts/verify-acceptance.mjs --write`);
  }
  if (write) writeFileSync(at(documentPath), text);
  references(documentPath, text);
}

/* ---------- release reports ---------- */

// Every release report carries the ten points of document 10 §9, as numbered headings and in that order.
const reportPoints = bullets(spec10, '## 9. Evidence report').map((text) => text.replace(/\.$/, ''));
const reports = readdirSync(at('docs/woodex-os')).filter((name) => /^\d{2}-RELEASE-.+\.md$/.test(name)).sort();
for (const name of reports) {
  const text = read('docs/woodex-os', name);
  const headings = [...text.matchAll(/^## (\d+)\. (.+)$/gm)].map((match) => `${match[1]}. ${match[2].trim()}`);
  reportPoints.forEach((point, index) => {
    if (headings[index] !== `${index + 1}. ${point}`) bad(`docs/woodex-os/${name}`, `heading ${index + 1} must be "## ${index + 1}. ${point}" (document 10 §9); it is "${headings[index] ?? '(missing)'}"`);
  });
  references(`docs/woodex-os/${name}`, text);
}

/* ---------- the answer ---------- */

const accepted = modules.filter((module) => module.signoff?.decision === 'accepted').length;
console.log(`scenarios   ${scenarios.length}: ${count(scenarios, 'state', 'proven')} proven, ${count(scenarios, 'state', 'partly')} partly, ${count(scenarios, 'state', 'not_built')} not built · ${scenarios.reduce((sum, s) => sum + s.tests.length, 0)} named tests found in ${declared.size} files`);
console.log(`flows       ${flows.length}: ${count(flows, 'state', 'covered')} pressed in full, ${count(flows, 'state', 'partly')} in part, ${count(flows, 'state', 'none')} not pressed · ${journeySteps.size} journey steps`);
console.log(`gates       ${gates.length}: ${gates.filter((gate) => gate.passed).length} passed · ${gates.map((gate) => `${gate.id} ${count(gate.criteria, 'state', 'met')}/${gate.criteria.length}`).join(', ')}`);
console.log(`modules     ${modules.length} sheets for ${openScreens.length} open screens: ${accepted} accepted by the owner`);
console.log(`reports     ${reports.length} release report${reports.length === 1 ? '' : 's'} with the ten points of document 10 §9`);
console.log(`defects     ${defects.length}: ${Object.keys(register.severities).map((key) => `${defects.filter((d) => d.severity === key && d.state !== 'closed').length} ${key}`).join(', ')} not closed · ${settled.length} closed`);
if (problems.length) {
  console.error(`\n${problems.length} problem${problems.length === 1 ? '' : 's'}:`);
  for (const problem of problems) console.error(`  - ${problem}`);
  process.exit(1);
}
console.log(write ? '\nDocument 16 written and checked.' : '\nThe acceptance files, the tests, the journeys and document 16 agree.');

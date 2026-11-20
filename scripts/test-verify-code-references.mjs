#!/usr/bin/env node
/**
 * Tries scripts/verify-code-references.mjs: one thing changed at a time in a copy of the repository, and what the
 * check must then say, with the file and the line (master list T-174).
 */
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { cpSync, mkdtempSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { DOCUMENTS, END_OF_NOTE } from './verify-code-references.mjs';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const SCRIPT = join(ROOT, 'scripts/verify-code-references.mjs');
const copy = mkdtempSync(join(tmpdir(), 'code-references-'));
let checks = 0;
const ok = (name) => { checks += 1; console.log(`  ok    ${name}`); };

// The copy holds what the check reads: the admin's code and the documents. Data, fonts and pictures are left out.
const LEFT_OUT = [/\/platform\/resources\/data(\/|$)/, /\/platform\/resources\/fonts(\/|$)/, /\/platform\/bootstrap\/cache(\/|$)/, /\.sqlite/, /\/node_modules(\/|$)/, /\.(png|jpe?g|webp|gif|pdf|zip|xlsx|woff2?)$/i];
for (const folder of ['platform/api', 'platform/app', 'platform/bootstrap', 'platform/config', 'platform/database', 'platform/resources', 'platform/routes', 'platform/tests', 'platform/tools', 'docs']) {
  cpSync(join(ROOT, folder), join(copy, folder), { recursive: true, filter: (source) => !LEFT_OUT.some((pattern) => pattern.test(source.replaceAll('\\', '/'))) });
}

const run = () => {
  const result = spawnSync(process.execPath, [SCRIPT, '--root', copy], { encoding: 'utf8' });
  return { code: result.status, text: `${result.stdout}\n${result.stderr}` };
};
const read = (path) => readFileSync(join(copy, path), 'utf8');
/** Changes one file of the copy, runs the check, puts the file back, and gives what the check said. */
const withChange = (path, change) => {
  const before = readFileSync(join(copy, path));
  const after = change(before.toString('utf8'));
  if (after === null) rmSync(join(copy, path)); else writeFileSync(join(copy, path), after);
  const result = run();
  writeFileSync(join(copy, path), before);
  return result;
};
const mustFail = (name, result, says) => {
  assert.equal(result.code, 1, `${name}: the check must fail, and said:\n${result.text.slice(0, 1500)}`);
  for (const text of [says].flat()) assert.ok(result.text.includes(text), `${name}: the check must say "${text}", and said:\n${result.text.slice(0, 2500)}`);
  ok(name);
};

// --- as it is --------------------------------------------------------------------------------------------------------
{
  const result = run();
  assert.equal(result.code, 0, `the copy as it is must pass, and the check said:\n${result.text.slice(0, 2000)}`);
  assert.match(result.text, /names \d{3,} decisions, documents and requirements in \d{3,} files/);
  ok('the copy as it is: passes, and says how much was read');
}

// A file of the code to write into, and the line a new last line gets.
const CODE = 'platform/app/Support/Brand.php';
const lastLine = read(CODE).split('\n').length;
const add = (line) => (text) => `${text}${line}\n`;

// --- a name that cannot be looked up, in the code ----------------------------------------------------------------------
mustFail('a decision number nobody defined, written into the code: fails with the file and the line',
  withChange(CODE, add('// As decided (D-999).')), `${CODE}:${lastLine} names D-999, which is not in the studio's decisions`);
mustFail("a number of this admin's own decision log that the log does not have: fails with the file and the line",
  withChange(CODE, add('// See DL-998.')), `${CODE}:${lastLine} names DL-998`);
mustFail('a requirement code of a family the requirements do not have: fails with the file and the line',
  withChange(CODE, add('// Required by XYZ-001.')), `${CODE}:${lastLine} names XYZ-001, which is not in the studio's requirements`);
mustFail('a requirement code of a known family and a number it does not have: fails',
  withChange(CODE, add('// Required by QUO-999.')), `${CODE}:${lastLine} names QUO-999`);
mustFail("one of the studio's documents that is not kept here and not listed: fails",
  withChange(CODE, add('// See docs/55 for the plan.')), `${CODE}:${lastLine} names docs/55, which is none of the three documents kept`);
mustFail('a section that the kept document does not have: fails',
  withChange(CODE, add('// See docs/06 §99.')), `${CODE}:${lastLine} names docs/06 §99, and docs/laravel-os/source/06-DEPLOYMENT-HOSTINGER.md has no section 99`);
mustFail('a document of this repository that is not there: fails',
  withChange(CODE, add('// See docs/woodex-os/99-NOT-WRITTEN.md.')), `${CODE}:${lastLine} names docs/woodex-os/99-NOT-WRITTEN.md, which is not in this repository`);
{ // What does resolve, written the same way, passes: the cases above fail for their name, not for their place.
  const result = withChange(CODE, add('// As decided (D-58, DL-017), required by QUO-006; see docs/06 §4, docs/07-DECISIONS.md and docs/woodex-os/12-DECISION-LOG.md.'));
  assert.equal(result.code, 0, `names that can all be looked up must pass, and the check said:\n${result.text.slice(0, 1500)}`);
  ok('the same line with names that can be looked up: passes');
}
{ // A longer word or number is not a reference.
  const result = withChange(CODE, add("// 'WF-10050', 'RC-2026-0001', 'INV-2026-0007', 'D-5812', 'X-123', 'ABCDEF-123', 'utf8-999' and 'UUID-58' are not references."));
  assert.equal(result.code, 0, `longer words must not be read as references, and the check said:\n${result.text.slice(0, 1500)}`);
  ok('longer words and numbers are not read as references');
}

// --- a document taken away ---------------------------------------------------------------------------------------------
{ // The check the master list asks for: 07-DECISIONS.md out of a copy → it fails naming D-58 and the first file that mentions it.
  const mentions = [];
  const walk = (folder) => {
    for (const entry of readdirSync(join(copy, folder), { withFileTypes: true }).sort((a, b) => (a.name < b.name ? -1 : 1))) {
      const path = `${folder}/${entry.name}`;
      if (entry.isDirectory()) { walk(path); continue; }
      if (!/\.(php|js|cjs|mjs|css|sh)$/.test(entry.name)) continue;
      read(path).split('\n').forEach((line, index) => { if (/(?<![A-Za-z0-9_-])D-58(?![0-9A-Za-z_]|-\d)/.test(line)) mentions.push({ path, line: index + 1 }); });
    }
  };
  for (const folder of ['platform/api', 'platform/app', 'platform/bootstrap', 'platform/config', 'platform/database', 'platform/resources', 'platform/routes', 'platform/tests', 'platform/tools']) walk(folder);
  mentions.sort((a, b) => (a.path === b.path ? a.line - b.line : a.path < b.path ? -1 : 1));
  assert.ok(mentions.length >= 5, 'the code no longer names D-58: choose another decision for this check');
  const first = mentions[0];
  mustFail(`the studio's decision record taken away: fails, names D-58 and the first file that mentions it (${first.path}:${first.line})`,
    withChange('docs/laravel-os/source/07-DECISIONS.md', () => null),
    ['docs/laravel-os/source/07-DECISIONS.md is missing', `${first.path}:${first.line} names D-58 (and ${mentions.length - 1} more times), which is not in the studio's decisions`]);
}
mustFail("the studio's requirements taken away: fails and names a requirement with its file",
  withChange('docs/laravel-os/source/02-MASTER-PRD.md', () => null), ['docs/laravel-os/source/02-MASTER-PRD.md is missing', 'names QUO-0']);
mustFail("the studio's instructions for Hostinger taken away: fails and names docs/06 with its file",
  withChange('docs/laravel-os/source/06-DEPLOYMENT-HOSTINGER.md', () => null), ['docs/laravel-os/source/06-DEPLOYMENT-HOSTINGER.md is missing', 'names docs/06 (and ']);

// --- a kept document changed -------------------------------------------------------------------------------------------
for (const [number, { file }] of Object.entries(DOCUMENTS)) {
  mustFail(`one word changed in the studio's document ${number}: fails, it is kept unchanged`,
    withChange(`docs/laravel-os/source/${file}`, (text) => { const at = text.indexOf(END_OF_NOTE) + END_OF_NOTE.length + 200; return `${text.slice(0, at)}x${text.slice(at)}`; }),
    `docs/laravel-os/source/${file} is not the studio's document any more`);
}
mustFail('the paragraph that says whose document it is taken off: fails',
  withChange('docs/laravel-os/source/07-DECISIONS.md', (text) => text.slice(text.indexOf(END_OF_NOTE) + END_OF_NOTE.length + 1)),
  'does not begin with the paragraph that says whose document it is');
mustFail('the commit taken out of that paragraph: fails',
  withChange('docs/laravel-os/source/07-DECISIONS.md', (text) => text.replace('603428cca1e45f9da2ffd9c4a0d88eb741285339', 'a commit')),
  'the first paragraph does not name the commit it was taken at');
mustFail('what says where this admin differs taken out of that paragraph: fails',
  withChange('docs/laravel-os/source/06-DEPLOYMENT-HOSTINGER.md', (text) => text.replace('**Where this admin differs.**', '')),
  'the first paragraph does not name where this admin differs from it');
mustFail("the pointer to this admin's own decisions taken out of that paragraph: fails",
  withChange('docs/laravel-os/source/02-MASTER-PRD.md', (text) => text.replaceAll('12-DECISION-LOG.md', 'the log')),
  "the first paragraph does not name that this admin's own decisions are document 12");

// --- the hand-kept list ------------------------------------------------------------------------------------------------
const LIST = 'docs/laravel-os/source/unresolved.json';
const changeList = (change) => (text) => { const list = JSON.parse(text); change(list); return JSON.stringify(list, null, 2); };
mustFail("an entry's reason taken out: fails and names it",
  withChange(LIST, changeList((list) => { delete list.unresolved.find((entry) => entry.name === 'docs/09').reason; })), 'docs/09 is listed without a reason');
mustFail('an entry taken off the list while the code still names it: fails with the first file and line',
  withChange(LIST, changeList((list) => { list.unresolved = list.unresolved.filter((entry) => entry.name !== 'D-112'); })), ['names D-112 (and ', "which is not in the studio's decisions"]);
mustFail('an entry for a name the code does not use: fails, the list holds nothing stale',
  withChange(LIST, changeList((list) => { list.unresolved.push({ name: 'docs/77', kind: 'document', reason: 'A document nobody names, listed here to see that the check notices it.' }); })), 'docs/77 is listed, and the code no longer names it');
mustFail('an entry for a name that can be looked up: fails',
  withChange(LIST, changeList((list) => { list.unresolved.push({ name: 'D-58', kind: 'decision', reason: 'Listed here although the decision record defines it, to see that the check notices.' }); })), 'D-58 is listed as not to be found, and it is defined in docs/laravel-os/source/07-DECISIONS.md');
mustFail('the same name twice: fails',
  withChange(LIST, changeList((list) => { list.unresolved.push({ ...list.unresolved[0] }); })), 'is listed twice');
mustFail('an entry that does not say what it is: fails',
  withChange(LIST, changeList((list) => { list.unresolved[0].kind = 'other'; })), 'must say what it is');
mustFail('the list taken away: fails', withChange(LIST, () => null), 'unresolved.json is missing');

rmSync(copy, { recursive: true, force: true });
console.log(`\nWhat the admin's code names can be looked up: ${checks} checks passed.`);

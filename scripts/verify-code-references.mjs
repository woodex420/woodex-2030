#!/usr/bin/env node
/**
 * Every decision, numbered document and requirement that the Laravel admin's code names can be looked up
 * (master list T-174).
 *
 * The admin's code came from another system, the Interior studio's (woodex420/woodexinterior), and its comments
 * say why a thing is so by naming that system's records: "D-58", "docs/06 §4", "QUO-006". Three of those records
 * are kept here, unchanged, in docs/laravel-os/source/. This script reads the code and fails when it names
 *
 *   - a decision number (D-58, DL-017) that is defined neither in the studio's decision record nor in this
 *     repository's own records (the owner's log, the defect register, this admin's decision log);
 *   - a numbered document (docs/06, docs/06 §4, docs/06-DEPLOYMENT-HOSTINGER.md) that is not one of the three kept
 *     here, or a section of one of them that it does not have; or a document of this repository, named by its
 *     path, that is not there;
 *   - a requirement code (QUO-006) that the studio's requirements document does not define.
 *
 * What cannot be resolved is listed once, by hand, with the reason: docs/laravel-os/source/unresolved.json. The
 * script fails on a name that is in neither place, on an entry of that list without a reason, on an entry that the
 * code no longer names, and on an entry that can be resolved after all. So the list says exactly what a reader of
 * the code cannot look up here, and why.
 *
 * It also holds the three documents to their source: each begins with a paragraph that says whose it is, and
 * everything after that paragraph must have the checksum the paragraph states.
 *
 *   node scripts/verify-code-references.mjs [--root <a copy of the repository>] [--print]
 */
import { createHash } from 'node:crypto';
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const args = process.argv.slice(2);
const rootAt = args.indexOf('--root');
const ROOT = rootAt === -1 ? resolve(dirname(fileURLToPath(import.meta.url)), '..') : resolve(args[rootAt + 1]);
const PRINT = args.includes('--print');

const SOURCE = 'docs/laravel-os/source';
export const SOURCE_COMMIT = '603428cca1e45f9da2ffd9c4a0d88eb741285339';
/** The three documents: their number in the studio's docs/ folder, their name, and the checksum of the file there. */
export const DOCUMENTS = {
  '02': { file: '02-MASTER-PRD.md', sha256: '3991d9fdcaff205109488965eb4ab0d47b4efdf19810fc42ac54ad19a642611d' },
  '06': { file: '06-DEPLOYMENT-HOSTINGER.md', sha256: '572c0102eb234718e217ea79b3248d05f490b70dc5dfec2f563b6005cf0cf8bc' },
  '07': { file: '07-DECISIONS.md', sha256: 'c5cc7f6a350eaeed22ba348ce9d80d5278e1c3cef0c4c9db4eedff0eb3d25e7c' },
};
/** The line that ends the paragraph this repository puts before a copied document. */
export const END_OF_NOTE = '<!-- The studio\'s document follows, unchanged. -->';
const UNRESOLVED = `${SOURCE}/unresolved.json`;

// The code: what was written by hand for the admin. Built files, packages and data are not code.
const CODE_FOLDERS = ['platform/api', 'platform/app', 'platform/bootstrap', 'platform/config', 'platform/database', 'platform/resources', 'platform/routes', 'platform/tests', 'platform/tools'];
const SKIPPED = [/^platform\/bootstrap\/cache\//, /^platform\/resources\/data\//, /^platform\/database\/.*\.sqlite/, /\/node_modules\//, /^platform\/resources\/fonts\//];
const CODE_FILE = /\.(php|js|cjs|mjs|css|sh)$/;

// Everything below runs only when this file is started, not when the test reads the names above from it.
if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
const problems = [];
const fail = (text) => problems.push(text);
const read = (path) => readFileSync(join(ROOT, path), 'utf8');
const has = (path) => existsSync(join(ROOT, path));

// --- the three documents, held to their source ---------------------------------------------------------------------
/** The studio's text of a copied document (what follows the note), or null when the file is not as it must be. */
function studioText(number) {
  const { file, sha256 } = DOCUMENTS[number];
  const path = `${SOURCE}/${file}`;
  if (!has(path)) { fail(`${path} is missing: the code's references to docs/${number} and to what it defines cannot be looked up.`); return null; }
  const text = read(path);
  const at = text.indexOf(`${END_OF_NOTE}\n`);
  if (at === -1) { fail(`${path} does not begin with the paragraph that says whose document it is (it ends with the line "${END_OF_NOTE}").`); return null; }
  const note = text.slice(0, at);
  const body = text.slice(at + END_OF_NOTE.length + 1);
  for (const [needed, what] of [['woodex420/woodexinterior', "the studio's repository"], [SOURCE_COMMIT, 'the commit it was taken at'], [sha256, 'its checksum'], ['Where this admin differs', 'where this admin differs from it'], ['12-DECISION-LOG.md', "that this admin's own decisions are document 12"]]) {
    if (!note.includes(needed)) fail(`${path}: the first paragraph does not name ${what} (${needed}).`);
  }
  const found = createHash('sha256').update(body).digest('hex');
  if (found !== sha256) fail(`${path} is not the studio's document any more: after the first paragraph its checksum is ${found}, and the file at ${SOURCE_COMMIT.slice(0, 7)} has ${sha256}. It is kept unchanged; what this admin decides is written in docs/woodex-os/12-DECISION-LOG.md.`);
  return body;
}
const studio = Object.fromEntries(Object.keys(DOCUMENTS).map((number) => [number, studioText(number)]));

// --- what can be looked up -------------------------------------------------------------------------------------------
const defined = { decisions: new Map(), requirements: new Map() };
const define = (map, key, where) => { if (!map.has(key)) map.set(key, where); };
// The studio's decisions: | **D-58** | … (also P-05, A-03 in the same document).
for (const match of (studio['07'] ?? '').matchAll(/^\|\s*\*\*(D-\d{1,3})\*\*\s*\|/gm)) define(defined.decisions, match[1], `${SOURCE}/07-DECISIONS.md`);
// The studio's requirements: | QUO-006 | …
for (const match of (studio['02'] ?? '').matchAll(/^\|\s*\**([A-Z]{2,5}-\d{3})\**\s*\|/gm)) define(defined.requirements, match[1], `${SOURCE}/02-MASTER-PRD.md`);
// This repository's own records. A row that begins with the number defines it.
const OWN_RECORDS = ['docs/woodex-os/spec/12-DECISION-LOG.md', 'docs/woodex-os/16-ACCEPTANCE-AND-RELEASE-GATES.md', 'docs/woodex-os/12-DECISION-LOG.md', 'docs/laravel-os/00-REUSE-PROPOSAL-AND-ADMIN-MASTER-PLAN.md'];
for (const path of OWN_RECORDS) {
  if (!has(path)) { fail(`${path} is missing: it is one of this repository's own records of decisions.`); continue; }
  for (const match of read(path).matchAll(/^\|\s*\**((?:D|DL)-\d{1,3})\**\s*\|/gm)) define(defined.decisions, match[1], path);
}
if (has('docs/woodex-os/acceptance/defects.json')) {
  for (const match of read('docs/woodex-os/acceptance/defects.json').matchAll(/"id":\s*"(D-\d{3})"/g)) define(defined.decisions, match[1], 'docs/woodex-os/acceptance/defects.json');
}
/** The numbers of the top-level sections of a copied document: "## 4. …" gives 4, "## B. …" gives B. */
const sections = Object.fromEntries(Object.entries(studio).map(([number, text]) => [number, new Set([...(text ?? '').matchAll(/^## ([0-9A-Z]+)\./gm)].map((match) => match[1]))]));

// --- the hand-kept list ----------------------------------------------------------------------------------------------
const KINDS = ['decision', 'document', 'requirement', 'not a reference'];
let unresolved = new Map();
if (!has(UNRESOLVED)) fail(`${UNRESOLVED} is missing: it lists what the code names and cannot be looked up here.`);
else {
  let list = { unresolved: [] };
  try { list = JSON.parse(read(UNRESOLVED)); } catch (error) { fail(`${UNRESOLVED} is not readable JSON: ${error.message}`); }
  for (const entry of list.unresolved ?? []) {
    const name = entry?.name ?? '(an entry without a name)';
    if (typeof entry?.name !== 'string' || !entry.name) { fail(`${UNRESOLVED}: an entry names nothing.`); continue; }
    if (unresolved.has(entry.name)) fail(`${UNRESOLVED}: ${name} is listed twice. Each name is listed once.`);
    if (!KINDS.includes(entry.kind)) fail(`${UNRESOLVED}: ${name} must say what it is: ${KINDS.join(', ')}.`);
    if (typeof entry.reason !== 'string' || entry.reason.trim().length < 30) fail(`${UNRESOLVED}: ${name} is listed without a reason a reader can weigh.`);
    unresolved.set(entry.name, { ...entry, used: 0 });
  }
}

// --- the code ----------------------------------------------------------------------------------------------------------
function* filesUnder(folder) {
  if (!has(folder)) return;
  for (const entry of readdirSync(join(ROOT, folder), { withFileTypes: true }).sort((a, b) => (a.name < b.name ? -1 : 1))) {
    const path = `${folder}/${entry.name}`;
    if (SKIPPED.some((pattern) => pattern.test(`${path}${entry.isDirectory() ? '/' : ''}`))) continue;
    if (entry.isDirectory()) yield* filesUnder(path);
    else if (CODE_FILE.test(entry.name) && statSync(join(ROOT, path)).size < 2_000_000) yield path;
  }
}

const PATTERNS = [
  // D-58, D-008, DL-017. Not a part of a longer word or number (UUID-58, D-5812, WD-58).
  { kind: 'decision', pattern: /(?<![A-Za-z0-9_-])(DL?-\d{1,3})(?![0-9A-Za-z_]|-\d)/g, name: (match) => match[1] },
  // QUO-006: two to five capitals and exactly three digits. DL-017 is a decision, read above.
  { kind: 'requirement', pattern: /(?<![A-Za-z0-9_-])((?!DL-)[A-Z]{2,5}-\d{3})(?![0-9A-Za-z_]|-\d)/g, name: (match) => match[1] },
  // docs/06, docs/06 §4, docs/06-DEPLOYMENT-HOSTINGER.md, docs/audit/16: the studio's way of naming its documents.
  // A section is checked when it is given by its number or letter ("§4", "§2.3", "§B"); "§WhatsApp" names a heading.
  { kind: 'document', pattern: /(?<![A-Za-z0-9_./-])docs\/((?:audit\/)?\d{2})(?:-[A-Z0-9_-]+\.md)?(?:\s*§\s*(\d+|[A-Z](?![A-Za-z])))?/g, name: (match) => `docs/${match[1]}`, section: (match) => match[2] },
  // docs/woodex-os/13-VERCEL-DEPLOYMENT.md: a document of this repository, named by its path.
  { kind: 'path', pattern: /(?<![A-Za-z0-9_./-])(docs\/[a-z][A-Za-z0-9_-]*\/(?:[A-Za-z0-9_.-]+\/)*[A-Za-z0-9_.-]+\.(?:md|json|txt))/g, name: (match) => match[1] },
];

const found = [];
for (const folder of CODE_FOLDERS) {
  for (const path of filesUnder(folder)) {
    const lines = read(path).split('\n');
    lines.forEach((line, index) => {
      for (const { kind, pattern, name, section } of PATTERNS) {
        for (const match of line.matchAll(pattern)) found.push({ kind, name: name(match), section: section?.(match), path, line: index + 1 });
      }
    });
  }
}
if (found.length < 100) fail(`only ${found.length} references were found in the code: the code was not read (looked under ${CODE_FOLDERS.join(', ')}).`);

/** Where a name is defined, or null. */
function lookUp(reference) {
  if (reference.kind === 'decision') return defined.decisions.get(reference.name) ?? null;
  if (reference.kind === 'requirement') return defined.requirements.get(reference.name) ?? null;
  if (reference.kind === 'path') return has(reference.name) ? reference.name : null;
  const number = reference.name.slice('docs/'.length);
  return DOCUMENTS[number] && studio[number] !== null ? `${SOURCE}/${DOCUMENTS[number].file}` : null;
}

const unknown = new Map();
const resolved = { decision: new Set(), requirement: new Set(), document: new Set(), path: new Set() };
for (const reference of found) {
  const where = lookUp(reference);
  const listed = unresolved.get(reference.name);
  if (where) {
    resolved[reference.kind].add(reference.name);
    if (listed) listed.resolves = where;
    if (reference.kind === 'document' && reference.section && !sections[reference.name.slice(5)].has(reference.section)) {
      fail(`${reference.path}:${reference.line} names ${reference.name} §${reference.section}, and ${where} has no section ${reference.section}.`);
    }
    continue;
  }
  if (listed) { listed.used += 1; continue; }
  if (!unknown.has(reference.name)) unknown.set(reference.name, { ...reference, count: 0 });
  unknown.get(reference.name).count += 1;
}
const WHERE_TO_LOOK = {
  decision: `the studio's decisions (${SOURCE}/07-DECISIONS.md) nor in this repository's own records (${OWN_RECORDS.join(', ')})`,
  requirement: `the studio's requirements (${SOURCE}/02-MASTER-PRD.md)`,
  document: `the three documents kept in ${SOURCE}/`,
  path: 'this repository',
};
for (const reference of [...unknown.values()].sort((a, b) => (a.path === b.path ? a.line - b.line : a.path < b.path ? -1 : 1))) {
  const more = reference.count > 1 ? ` (and ${reference.count - 1} more time${reference.count > 2 ? 's' : ''})` : '';
  fail(`${reference.path}:${reference.line} names ${reference.name}${more}, which is ${reference.kind === 'path' ? 'not in' : reference.kind === 'document' ? 'none of' : 'not in'} ${WHERE_TO_LOOK[reference.kind]}. Correct the name, or list it in ${UNRESOLVED} with the reason.`);
}
for (const entry of unresolved.values()) {
  if (entry.resolves) fail(`${UNRESOLVED}: ${entry.name} is listed as not to be found, and it is defined in ${entry.resolves}. Take it off the list.`);
  else if (entry.used === 0) fail(`${UNRESOLVED}: ${entry.name} is listed, and the code no longer names it. Take it off the list.`);
}

// --- the answer --------------------------------------------------------------------------------------------------------
if (PRINT) {
  for (const entry of [...unresolved.values()].sort((a, b) => b.used - a.used)) console.log(`${String(entry.used).padStart(4)}  ${entry.name}  (${entry.kind})`);
}
if (problems.length) {
  for (const problem of problems) console.error(`FAIL  ${problem}`);
  console.error(`\n${problems.length} problem(s) with what the admin's code names.`);
  process.exit(1);
}
const listed = [...unresolved.values()];
const count = (kind) => listed.filter((entry) => entry.kind === kind).length;
console.log(`The admin's code names ${found.length} decisions, documents and requirements in ${new Set(found.map((reference) => reference.path)).size} files. `
  + `Looked up: ${resolved.decision.size} decisions, ${resolved.requirement.size} requirements, ${resolved.document.size} of the studio's documents, ${resolved.path.size} documents of this repository. `
  + `Listed by hand as not to be found here, each with its reason: ${count('decision')} decisions, ${count('document')} documents, ${count('requirement')} requirements, and ${count('not a reference')} names that only look like one. `
  + 'The three documents are the studio\'s, unchanged.');
}

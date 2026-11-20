#!/usr/bin/env node
/**
 * Judges what npm and Composer say about known security advisories against the written list of the ones that are
 * accepted for now (master list T-071).
 *
 *   node scripts/audit-advisories.mjs npm      <the output of `npm audit --json`>
 *   node scripts/audit-advisories.mjs composer <the output of `composer audit --locked --format=json`>
 *   node scripts/audit-advisories.mjs newer npm      <the output of `npm outdated --json --workspaces --include-workspace-root`>
 *   node scripts/audit-advisories.mjs newer composer <the output of `composer outdated --locked --direct --format=json`>
 *
 * The list is docs/woodex-os/acceptance/advisories.json (another file: --list <file>). The rule:
 *
 *   - an advisory of ANY severity that is not on the list fails the run, and is named;
 *   - an entry on the list says why it is accepted, in words; an entry without a reason fails the run;
 *   - an entry that nothing reports any more fails the run: it is taken off the list, so the list cannot go stale;
 *   - an advisory that is reported with a higher severity than it was accepted at fails the run.
 *
 * So the list always says exactly what is known and tolerated, and each change of it is a commit somebody reads.
 * The two "newer" forms judge nothing: they write which packages have a newer version, as a notice of the run.
 *
 * Every result is written as a GitHub annotation, because a run's notices can be read without its log.
 */
import { readFileSync } from 'node:fs';
import { resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const args = process.argv.slice(2);
const listAt = args.indexOf('--list');
const LIST = listAt === -1 ? resolve(ROOT, 'docs/woodex-os/acceptance/advisories.json') : resolve(args.splice(listAt, 2)[1]);

const RANK = { info: 0, low: 1, moderate: 2, medium: 2, high: 3, critical: 4, unknown: 5 };
const MANAGERS = ['npm', 'composer'];
/** The shortest text that counts as a reason. "ok", "accepted" or "dev only" say nothing a reader can weigh. */
const SHORTEST_REASON = 60;

const annotate = (level, title, text) => console.log(`::${level} title=${title}::${String(text).replace(/\r?\n/g, ' ')}`);
const stop = (title, text) => { annotate('error', title, text); process.exit(1); };
const readJson = (file, what) => {
  let raw = '';
  try { raw = readFileSync(file, 'utf8'); } catch { stop(what, `${file} could not be read.`); }
  try { return JSON.parse(raw); } catch { return stop(what, `no answer that can be read: ${raw.trim().slice(0, 300) || '(nothing)'}`); }
};

// --- the list ----------------------------------------------------------------------------------------------------
/** The list's entries, or the faults that make it unusable. Used by the test as well. */
export function readList(file) {
  const faults = [];
  let list;
  try { list = JSON.parse(readFileSync(file, 'utf8')); } catch (error) { return { entries: [], faults: [`${file} is not readable JSON: ${error.message}`] }; }
  if (!Array.isArray(list.accepted)) return { entries: [], faults: [`${file} has no "accepted" list.`] };
  const seen = new Set();
  for (const [index, entry] of list.accepted.entries()) {
    const name = entry && entry.id ? `${entry.id} (${entry.package ?? 'no package named'})` : `entry ${index + 1}`;
    if (!entry || typeof entry !== 'object') { faults.push(`${name} is not an entry.`); continue; }
    if (!entry.id || typeof entry.id !== 'string') faults.push(`${name} names no advisory ("id").`);
    if (!MANAGERS.includes(entry.manager)) faults.push(`${name} must say which of ${MANAGERS.join(' or ')} reports it ("manager").`);
    if (!entry.package || typeof entry.package !== 'string') faults.push(`${name} names no package.`);
    if (!(String(entry.severity ?? '').toLowerCase() in RANK)) faults.push(`${name} does not say at which severity it was accepted.`);
    const reason = typeof entry.reason === 'string' ? entry.reason.trim() : '';
    if (!reason) faults.push(`${name} is on the list without a reason.`);
    else if (reason.length < SHORTEST_REASON) faults.push(`${name} is on the list with a reason of ${reason.length} characters ("${reason}"): say why it does no harm here, in a sentence.`);
    if (!/^\d{4}-\d{2}-\d{2}$/.test(String(entry.accepted_on ?? ''))) faults.push(`${name} does not say on which day it was accepted ("accepted_on", as 2026-11-20).`);
    if (!/^DL-\d{3}$/.test(String(entry.decision ?? ''))) faults.push(`${name} names no row of the decision log ("decision", as DL-043).`);
    if (typeof entry.falls_away_when !== 'string' || !entry.falls_away_when.trim()) faults.push(`${name} does not say what will end it ("falls_away_when").`);
    const key = `${entry.manager}|${entry.id}|${entry.package}`;
    if (seen.has(key)) faults.push(`${name} is on the list twice.`);
    seen.add(key);
  }
  return { entries: list.accepted, faults };
}

// --- what a manager reports --------------------------------------------------------------------------------------
/** npm audit --json: one finding for each advisory of each package; a package that only depends on one is not a finding. */
function fromNpm(report) {
  if (!report || !report.metadata || !report.vulnerabilities) return null;
  const found = new Map();
  for (const entry of Object.values(report.vulnerabilities)) {
    for (const via of entry.via ?? []) {
      if (typeof via === 'string') continue;
      const id = /GHSA-[0-9a-z]{4}-[0-9a-z]{4}-[0-9a-z]{4}/i.exec(via.url ?? '')?.[0] ?? `npm-${via.source}`;
      found.set(`${id}|${via.name}`, { ids: [id], package: via.name, severity: String(via.severity ?? 'unknown').toLowerCase(), title: via.title ?? '', range: via.range ?? '', link: via.url ?? '' });
    }
  }
  return [...found.values()];
}

/** composer audit --format=json. An advisory that names no severity counts as the highest. */
function fromComposer(report) {
  if (!report || typeof report !== 'object' || !('advisories' in report)) return null;
  const found = [];
  const advisories = report.advisories ?? {};
  for (const [name, list] of Object.entries(Array.isArray(advisories) ? {} : advisories)) {
    for (const advisory of Array.isArray(list) ? list : Object.values(list)) {
      const ids = [advisory.advisoryId, advisory.cve, ...(advisory.sources ?? []).map((source) => source.remoteId)].filter(Boolean);
      found.push({ ids: ids.length ? ids : ['no id'], package: name, severity: String(advisory.severity || 'unknown').toLowerCase(), title: advisory.title ?? '', range: advisory.affectedVersions ?? '', link: advisory.link ?? '' });
    }
  }
  return found;
}

/** Judges the findings of one manager against the list: what is off the list, and what is wrong with the list. */
export function judge(manager, findings, entries) {
  const mine = entries.filter((entry) => entry.manager === manager);
  const problems = [];
  const accepted = [];
  const used = new Set();
  for (const finding of findings) {
    const entry = mine.find((candidate) => candidate.package === finding.package && finding.ids.includes(candidate.id));
    const label = `${finding.ids[0]} in ${finding.package} (${finding.severity}${finding.range ? `, versions ${finding.range}` : ''}): ${finding.title || 'no title'}${finding.link ? ` ${finding.link}` : ''}`;
    if (!entry) { problems.push(`not on the list: ${label}`); continue; }
    used.add(entry);
    const then = RANK[String(entry.severity).toLowerCase()] ?? RANK.unknown;
    const now = RANK[finding.severity] ?? RANK.unknown;
    if (now > then) { problems.push(`${entry.id} in ${entry.package} was accepted as ${entry.severity} and is now reported as ${finding.severity}: decide again.`); continue; }
    accepted.push(`${entry.id} in ${entry.package} (${finding.severity}, accepted ${entry.accepted_on}, ${entry.decision})`);
  }
  for (const entry of mine) {
    if (!used.has(entry)) problems.push(`${entry.id} in ${entry.package} is on the list, and ${manager} reports it no longer: take it off the list.`);
  }
  return { problems, accepted };
}

// --- newer versions ----------------------------------------------------------------------------------------------
function newerNpm(report) {
  const lines = [];
  for (const [name, value] of Object.entries(report ?? {})) {
    for (const entry of Array.isArray(value) ? value : [value]) {
      if (!entry || !entry.latest || entry.current === entry.latest) continue;
      const within = entry.wanted && entry.wanted !== entry.current ? `, ${entry.wanted} within what package.json allows` : '';
      lines.push(`${name} ${entry.current ?? 'not installed'} → ${entry.latest}${within}${entry.dependent ? ` (${entry.dependent})` : ''}`);
    }
  }
  return [...new Set(lines)].sort();
}
function newerComposer(report) {
  const lines = [];
  for (const entry of report?.locked ?? report?.installed ?? []) {
    if (!entry.latest || entry.latest === entry.version) continue;
    const kind = entry['latest-status'] === 'semver-safe-update' ? '' : ' (a new major version, or outside what composer.json allows)';
    lines.push(`${entry.name} ${entry.version} → ${entry.latest}${kind}${entry.abandoned ? ' — no longer maintained' : ''}`);
  }
  return lines.sort();
}

// --- the run -----------------------------------------------------------------------------------------------------
function main() {
  const [mode, ...rest] = args;
  if (mode === 'newer') {
    const [manager, file] = rest;
    if (!MANAGERS.includes(manager) || !file) stop('newer versions', 'usage: audit-advisories.mjs newer npm|composer <file>');
    const lines = manager === 'npm' ? newerNpm(readJson(file, 'npm outdated')) : newerComposer(readJson(file, 'composer outdated'));
    const title = `${manager === 'npm' ? 'npm' : 'Composer'}: ${lines.length ? `${lines.length} package(s) named in ${manager === 'npm' ? 'a package.json' : 'composer.json'} have a newer version` : 'every package named here is at its newest version'}`;
    annotate('notice', title, lines.length ? lines.join('; ') : 'nothing to move');
    for (const line of lines) console.log(`    ${line}`);
    return;
  }
  if (!MANAGERS.includes(mode) || !rest[0]) stop('advisories', 'usage: audit-advisories.mjs npm|composer <file> [--list <file>]');
  const name = mode === 'npm' ? 'npm' : 'Composer';
  const report = readJson(rest[0], `${name} audit`);
  const findings = mode === 'npm' ? fromNpm(report) : fromComposer(report);
  if (findings === null) stop(`${name} audit`, `no answer from the advisory database: ${JSON.stringify(report).slice(0, 300)}`);

  const { entries, faults } = readList(LIST);
  const { problems, accepted } = judge(mode, findings, entries);
  const all = [...faults.map((fault) => `the list: ${fault}`), ...problems];
  if (mode === 'composer') {
    const abandoned = Object.entries(report.abandoned ?? {}).filter(([, value]) => value !== false).map(([key]) => key);
    if (abandoned.length) annotate('warning', 'Composer: packages that are no longer maintained', abandoned.join(', '));
  }
  const tail = accepted.length ? ` Accepted for now, each with its reason in docs/woodex-os/acceptance/advisories.json: ${accepted.join('; ')}.` : '';
  if (all.length) {
    annotate('error', `${name}: ${all.length} thing(s) to settle before this can pass`, `${all.join(' | ')}${tail ? ` —${tail}` : ''}`);
    for (const line of all) console.error(`  FAIL  ${line}`);
    process.exit(1);
  }
  annotate('notice', `${name}: no advisory that is not on the list`, `${findings.length} known advisory(ies), ${accepted.length} accepted.${tail}`);
}

if (process.argv[1] && resolve(process.argv[1]) === fileURLToPath(import.meta.url)) main();

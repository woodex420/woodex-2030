#!/usr/bin/env node
/**
 * Tries scripts/audit-advisories.mjs: one thing changed at a time, in a copy, and what it must then say.
 *
 * No network is used: what npm and Composer answer is written out here, in the form each of them answers in. The
 * list that is tried first is the real one, docs/woodex-os/acceptance/advisories.json, so a fault in it fails this
 * test in the shop's gate, where no advisory database is asked.
 */
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve, dirname } from 'node:path';
import { fileURLToPath } from 'node:url';
import { readList } from './audit-advisories.mjs';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const SCRIPT = join(ROOT, 'scripts/audit-advisories.mjs');
const REAL_LIST = join(ROOT, 'docs/woodex-os/acceptance/advisories.json');
const work = mkdtempSync(join(tmpdir(), 'advisories-'));
let checks = 0;
const ok = (name) => { checks += 1; console.log(`  ok    ${name}`); };

// --- the real list is whole ----------------------------------------------------------------------------------------
const real = readList(REAL_LIST);
assert.deepEqual(real.faults, [], 'the real list has a fault');
assert.ok(real.entries.length >= 1, 'the real list is empty: then this test tries nothing against it');
ok(`the real list is whole: ${real.entries.length} accepted, each with a reason, a day, a decision and what ends it`);

// --- what the two managers answer, built from the real list ---------------------------------------------------------
const npmEntries = real.entries.filter((entry) => entry.manager === 'npm');
const composerEntries = real.entries.filter((entry) => entry.manager === 'composer');
/** `npm audit --json` when it reports exactly these advisories. */
const npmReport = (advisories) => {
  const vulnerabilities = {};
  for (const advisory of advisories) {
    vulnerabilities[advisory.package] = {
      name: advisory.package, severity: advisory.severity, isDirect: false,
      via: [{ source: 1, name: advisory.package, dependency: advisory.package, title: advisory.title ?? 'a fault', url: `https://github.com/advisories/${advisory.id}`, severity: advisory.severity, range: '<9.9.9' }],
      effects: ['a-package-that-depends-on-it'], range: '<9.9.9', nodes: [`node_modules/${advisory.package}`], fixAvailable: false,
    };
    // A package that merely depends on a faulty one is in npm's answer too; it is not an advisory of its own.
    vulnerabilities['a-package-that-depends-on-it'] = { name: 'a-package-that-depends-on-it', severity: advisory.severity, isDirect: true, via: [advisory.package], effects: [], range: '*', nodes: ['node_modules/a-package-that-depends-on-it'], fixAvailable: false };
  }
  return { auditReportVersion: 2, vulnerabilities, metadata: { vulnerabilities: { info: 0, low: 0, moderate: advisories.length, high: 0, critical: 0, total: advisories.length } } };
};
/** `composer audit --format=json` when it reports exactly these advisories. */
const composerReport = (advisories, abandoned = {}) => {
  const byPackage = {};
  for (const advisory of advisories) {
    (byPackage[advisory.package] ??= []).push({ advisoryId: advisory.advisoryId ?? 'PKSA-0000-0000-0000', packageName: advisory.package, affectedVersions: '<9.9.9', title: advisory.title ?? 'a fault', cve: advisory.cve ?? null, link: 'https://example.test/advisory', reportedAt: '2026-11-01T00:00:00+00:00', sources: advisory.ghsa ? [{ name: 'GitHub', remoteId: advisory.ghsa }] : [], ...(advisory.severity ? { severity: advisory.severity } : {}) });
  }
  return { advisories: advisories.length ? byPackage : [], abandoned };
};

let number = 0;
/** Runs the script on an answer and a list; gives its exit code and everything it wrote. */
const run = (mode, report, list) => {
  number += 1;
  const reportFile = join(work, `report-${number}.json`);
  const listFile = join(work, `list-${number}.json`);
  writeFileSync(reportFile, typeof report === 'string' ? report : JSON.stringify(report));
  writeFileSync(listFile, JSON.stringify(list));
  const result = spawnSync(process.execPath, [SCRIPT, ...mode.split(' '), reportFile, '--list', listFile], { encoding: 'utf8' });
  return { code: result.status, text: `${result.stdout}\n${result.stderr}` };
};
const copy = () => JSON.parse(readFileSync(REAL_LIST, 'utf8'));
const mustPass = (name, result, says) => {
  assert.equal(result.code, 0, `${name}: must pass, and said:\n${result.text}`);
  for (const text of [says].flat().filter(Boolean)) assert.ok(result.text.includes(text), `${name}: must say "${text}", and said:\n${result.text}`);
  ok(name);
};
const mustFail = (name, result, says) => {
  assert.equal(result.code, 1, `${name}: must fail, and said:\n${result.text}`);
  for (const text of [says].flat()) assert.ok(result.text.includes(text), `${name}: must say "${text}", and said:\n${result.text}`);
  ok(name);
};

// --- as it is ------------------------------------------------------------------------------------------------------
const first = npmEntries[0];
assert.ok(first, 'the real list has no npm entry: write the cases below for what it has');
mustPass('npm reports exactly what the list accepts: passes, and names what is accepted', run('npm', npmReport(npmEntries), copy()), [`${first.id} in ${first.package}`, 'no advisory that is not on the list']);
mustPass('Composer reports exactly what the list accepts: passes', run('composer', composerReport(composerEntries.map((entry) => ({ package: entry.package, advisoryId: entry.id, severity: entry.severity }))), copy()), 'no advisory that is not on the list');

// --- the list, one fault at a time ----------------------------------------------------------------------------------
{ // The check the master list asks for: an accepted advisory's reason is taken out.
  const list = copy(); delete list.accepted.find((entry) => entry.id === first.id).reason;
  mustFail("an accepted advisory's reason taken out: fails and names it", run('npm', npmReport(npmEntries), list), [`${first.id} (${first.package}) is on the list without a reason`]);
}
{
  const list = copy(); list.accepted.find((entry) => entry.id === first.id).reason = '   ';
  mustFail('a reason of blanks: fails and names it', run('npm', npmReport(npmEntries), list), `${first.id} (${first.package}) is on the list without a reason`);
}
{
  const list = copy(); list.accepted.find((entry) => entry.id === first.id).reason = 'dev only';
  mustFail('a reason of two words: fails and says why', run('npm', npmReport(npmEntries), list), [`${first.id} (${first.package}) is on the list with a reason of 8 characters`, 'say why it does no harm here']);
}
for (const [field, says] of [['decision', 'names no row of the decision log'], ['accepted_on', 'does not say on which day it was accepted'], ['falls_away_when', 'does not say what will end it'], ['severity', 'does not say at which severity it was accepted'], ['package', 'names no package'], ['manager', 'must say which of npm or composer reports it']]) {
  const list = copy(); delete list.accepted.find((entry) => entry.id === first.id)[field];
  mustFail(`the entry's "${field}" taken out: fails`, run('npm', npmReport(npmEntries), list), says);
}
{
  const list = copy(); list.accepted.push({ ...list.accepted[0] });
  mustFail('the same entry twice: fails', run('npm', npmReport(npmEntries), list), 'is on the list twice');
}
mustFail('a list without its "accepted" part: fails', run('npm', npmReport(npmEntries), {}), 'has no "accepted" list');

// --- what is reported, one change at a time -------------------------------------------------------------------------
mustFail('an advisory of low severity that is not on the list: fails and names it',
  run('npm', npmReport([...npmEntries, { id: 'GHSA-aaaa-bbbb-cccc', package: 'left-pad', severity: 'low', title: 'Pads too far' }]), copy()),
  ['not on the list: GHSA-aaaa-bbbb-cccc in left-pad (low', 'Pads too far']);
mustFail('the same advisory in another package than the one accepted: fails',
  run('npm', npmReport([...npmEntries, { id: first.id, package: 'another-package', severity: first.severity }]), copy()),
  `not on the list: ${first.id} in another-package`);
mustFail('npm no longer reports an accepted advisory: fails until the entry is taken off',
  run('npm', npmReport(npmEntries.filter((entry) => entry.id !== first.id)), copy()),
  `${first.id} in ${first.package} is on the list, and npm reports it no longer: take it off the list`);
mustFail('an accepted advisory is now reported as high: fails',
  run('npm', npmReport(npmEntries.map((entry) => (entry.id === first.id ? { ...entry, severity: 'high' } : entry))), copy()),
  `${first.id} in ${first.package} was accepted as ${first.severity} and is now reported as high: decide again`);
mustFail('npm gives no answer: fails', run('npm', { error: { code: 'ENOAUDIT', summary: 'the registry did not answer' } }, copy()), 'no answer from the advisory database');
mustFail('npm answers with something that is not JSON: fails', run('npm', '<html>503</html>', copy()), 'no answer that can be read');

// --- Composer's own forms -------------------------------------------------------------------------------------------
const withComposer = (entry) => { const list = copy(); list.accepted.push({ ...list.accepted[0], manager: 'composer', title: 'a fault', where: 'vendor/', ...entry }); return list; };
mustFail('Composer reports an advisory that is not on the list, of low severity: fails and names it',
  run('composer', composerReport([{ package: 'laravel/framework', advisoryId: 'PKSA-1111-2222-3333', cve: 'CVE-2026-128083', severity: 'low', title: 'A header is trusted' }]), copy()),
  ['not on the list: PKSA-1111-2222-3333 in laravel/framework (low', 'A header is trusted']);
mustPass('…accepted by its CVE number: passes',
  run('composer', composerReport([{ package: 'laravel/framework', advisoryId: 'PKSA-1111-2222-3333', cve: 'CVE-2026-128083', severity: 'low' }]), withComposer({ id: 'CVE-2026-128083', package: 'laravel/framework', severity: 'low' })),
  'CVE-2026-128083 in laravel/framework');
mustPass("…accepted by GitHub's number for it: passes",
  run('composer', composerReport([{ package: 'laravel/framework', advisoryId: 'PKSA-1111-2222-3333', ghsa: 'GHSA-xxxx-yyyy-zzzz', severity: 'low' }]), withComposer({ id: 'GHSA-xxxx-yyyy-zzzz', package: 'laravel/framework', severity: 'low' })),
  'GHSA-xxxx-yyyy-zzzz in laravel/framework');
mustFail('an advisory that names no severity counts as the highest: an entry accepted as low does not cover it',
  run('composer', composerReport([{ package: 'laravel/framework', advisoryId: 'PKSA-1111-2222-3333' }]), withComposer({ id: 'PKSA-1111-2222-3333', package: 'laravel/framework', severity: 'low' })),
  'was accepted as low and is now reported as unknown');
mustPass('a package that is no longer maintained is written as a warning and does not fail the run',
  run('composer', composerReport([], { 'old/package': true, 'fine/package': false, 'moved/package': 'new/package' }), { accepted: [] }),
  ['no longer maintained', 'old/package, moved/package']);
mustFail('Composer gives no answer: fails', run('composer', { message: 'curl error 28' }, copy()), 'no answer from the advisory database');
{ // An npm entry is not Composer's to report: the list is judged manager by manager.
  mustPass("Composer reports nothing: the npm entries are not Composer's to miss", run('composer', composerReport([]), copy()), '0 known advisory(ies), 0 accepted');
}

// --- newer versions: printed, never judged --------------------------------------------------------------------------
{
  const report = { react: { current: '19.2.0', wanted: '19.2.4', latest: '20.0.1', dependent: 'storefront', location: 'node_modules/react' }, vite: [{ current: '8.2.2', wanted: '8.2.2', latest: '8.2.2', dependent: 'dashboard' }, { current: '8.2.2', wanted: '8.2.2', latest: '9.0.0', dependent: 'storefront' }] };
  const file = join(work, 'npm-outdated.json'); writeFileSync(file, JSON.stringify(report));
  const result = spawnSync(process.execPath, [SCRIPT, 'newer', 'npm', file], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stdout + result.stderr);
  assert.ok(result.stdout.includes('::notice title=npm: 2 package(s) named in a package.json have a newer version::react 19.2.0 → 20.0.1, 19.2.4 within what package.json allows (storefront); vite 8.2.2 → 9.0.0 (storefront)'), result.stdout);
  ok('npm: the packages with a newer version are written as one notice, and the run passes');
}
{
  const report = { locked: [{ name: 'laravel/framework', 'direct-dependency': true, version: 'v13.39.0', latest: 'v13.42.0', 'latest-status': 'semver-safe-update' }, { name: 'pestphp/pest', version: 'v5.3.4', latest: 'v6.0.0', 'latest-status': 'update-possible' }, { name: 'brick/math', version: '1.0.0', latest: '1.0.0', 'latest-status': 'up-to-date' }] };
  const file = join(work, 'composer-outdated.json'); writeFileSync(file, JSON.stringify(report));
  const result = spawnSync(process.execPath, [SCRIPT, 'newer', 'composer', file], { encoding: 'utf8' });
  assert.equal(result.status, 0, result.stdout + result.stderr);
  assert.ok(result.stdout.includes('::notice title=Composer: 2 package(s) named in composer.json have a newer version::laravel/framework v13.39.0 → v13.42.0; pestphp/pest v5.3.4 → v6.0.0 (a new major version, or outside what composer.json allows)'), result.stdout);
  ok('Composer: the packages with a newer version are written as one notice, and the run passes');
}
{
  const file = join(work, 'none.json'); writeFileSync(file, '{}');
  const result = spawnSync(process.execPath, [SCRIPT, 'newer', 'npm', file], { encoding: 'utf8' });
  assert.equal(result.status, 0);
  assert.ok(result.stdout.includes('every package named here is at its newest version'), result.stdout);
  ok('nothing newer: says so');
}

rmSync(work, { recursive: true, force: true });
console.log(`\nThe audit against the list of accepted advisories: ${checks} checks passed.`);

#!/usr/bin/env node
/**
 * One line on the run page for what the browser did on the rehearsal.
 *
 *   node scripts/os-notice.mjs "<what was run>" <folder> [<folder> …]
 *
 * Each folder is the --out of scripts/os-screens.mjs (screens.json) or of scripts/os-journeys.mjs
 * (journeys.json). The line is a GitHub annotation: a notice when everything held, an error that names what
 * did not. A job's annotations can be read on the run page and through the API without opening the log, which
 * is how a failed run is read from here. The exit code is 0 either way: the two scripts decide pass or fail,
 * this only says what happened. A folder without a report is named as missing, because a script that never
 * wrote one did not finish.
 */
import fs from 'node:fs';
import path from 'node:path';

const [label = 'the rehearsal', ...folders] = process.argv.slice(2);
const said = [];
const wrong = [];

for (const folder of folders) {
  const screens = path.join(folder, 'screens.json');
  const journeys = path.join(folder, 'journeys.json');

  if (fs.existsSync(screens)) {
    const report = JSON.parse(fs.readFileSync(screens, 'utf8'));
    let pages = 0;
    for (const [role, entry] of Object.entries(report.roles ?? {})) {
      pages += entry.pages.length;
      for (const page of [...entry.pages, ...(entry.phone ?? [])].filter((item) => item.bad)) wrong.push(`${role}: ${page.path} → ${page.status}${page.failure ? ` (${page.failure})` : ''}${page.blocked?.length ? ` (blocked by the content policy: ${page.blocked[0].slice(0, 120)})` : ''}`);
      for (const download of (entry.downloads ?? []).filter((item) => item.bad)) wrong.push(`${role}: download ${download.path} → ${download.status}`);
      if (!/^ok/.test(String(entry.signIn))) wrong.push(`${role}: sign-in ${entry.signIn}`);
    }
    said.push(`${pages} pages opened as ${Object.keys(report.roles ?? {}).length} roles`);
  } else if (fs.existsSync(journeys)) {
    const report = JSON.parse(fs.readFileSync(journeys, 'utf8'));
    const count = (result) => report.steps.filter((step) => step.result === result).length;
    said.push(`${count('ok')} of ${report.steps.length} steps held${count('left out') ? `, ${count('left out')} left out` : ''}`);
    for (const step of report.steps.filter((entry) => entry.result === 'FAILED')) wrong.push(`step "${step.name}": ${String(step.note).slice(0, 160)}`);
    for (const step of report.steps.filter((entry) => entry.result === 'left out')) wrong.push(`left out "${step.name}": ${String(step.note).slice(0, 120)}`);
    for (const problem of [...new Set(report.problems ?? [])].filter((entry) => entry.startsWith('HTTP 5') || /Content[ -]Security[ -]Policy/i.test(entry))) wrong.push(problem.slice(0, 200));
  } else {
    wrong.push(`no report in ${path.basename(folder)}: the script did not finish`);
  }
}

const line = (text) => String(text).replace(/\r?\n/g, ' ').replace(/::/g, ': :');
const summary = said.join(' · ') || 'nothing was reported';
if (wrong.length === 0) console.log(`::notice title=${line(label)} — the browser found nothing wrong::${line(summary)}`);
else console.log(`::error title=${line(label)} — ${wrong.length} thing(s) wrong::${line(`${summary} — ${wrong.slice(0, 8).join(' | ')}`).slice(0, 1500)}`);

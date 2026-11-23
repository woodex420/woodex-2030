#!/usr/bin/env node
/**
 * The sign-in of the Laravel admin, tried from the address a person types to the sign-out, in a real browser,
 * for each of the four demo accounts (master list T-151).
 *
 *   OS_BASE=https://woodex-os.vercel.app WX_PW_OWNER=… WX_PW_MANAGER=… WX_PW_SALES=… WX_PW_ACCOUNTS=… \
 *     node scripts/os-signin.mjs [--out <folder>]
 *
 * It needs a running admin with the four demo accounts (owner@, manager@, sales@, accounts@woodex.test): the
 * deployed demo, its rehearsal (platform/tools/vercel/serve-demo.sh) or a sandbox preview. It starts nothing and
 * writes no record: it signs in and out. A password is never printed, also not when a step fails.
 *
 * For each account whose password is given, eight steps:
 *   1. the sign-in page opens, says that this is not production, and names no password unless the site shows the
 *      owner's on purpose (WOODEX_DEMO_SHOW_PASSWORD, or a preview started with WXPREVIEW_SHOW_PASSWORD);
 *   2. a wrong password is refused: the form comes back and says so;
 *   3. …and the admin still asks for a sign-in;
 *   4. the right password opens the dashboard, under that person's name;
 *   5. a screen of that person's own work opens: the session holds on a second page;
 *   6. …and after the page is loaded again;
 *   7. a screen that person may not open is refused, with the admin's own page (the owner may open every screen:
 *      the step is left out);
 *   8. signing out leads to the sign-in page, and the dashboard asks for a sign-in again.
 * And once, without an account: the page behind "Forgot password?" opens.
 *
 * OS_ACCOUNTS=owner,sales tries those accounts only. An account without a password is left out and named; with
 * OS_STRICT=1 that fails the run, so that a missing secret cannot pass for a check.
 *
 * Output: one line a step, <folder>/signin.json, and a picture of the screen where a step failed. In GitHub
 * Actions the result is also one notice on the run. Exit code 1 when a step failed, 2 when nothing could be tried.
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { launchBrowser } from './lib/browser.mjs';

const base = (process.env.OS_BASE || 'http://127.0.0.1:8191').replace(/\/$/, '');
const args = process.argv.slice(2);
const out = args.includes('--out') ? args[args.indexOf('--out') + 1] : '/tmp/os-signin';
const strict = process.env.OS_STRICT === '1';
mkdirSync(out, { recursive: true });

const accounts = [
  { key: 'owner', email: 'owner@woodex.test', password: process.env.WX_PW_OWNER, own: '/admin/system/access', refused: null },
  { key: 'manager', email: 'manager@woodex.test', password: process.env.WX_PW_MANAGER, own: '/admin/leads', refused: '/admin/system/access' },
  { key: 'sales', email: 'sales@woodex.test', password: process.env.WX_PW_SALES, own: '/admin/leads', refused: '/admin/system/access' },
  { key: 'accounts', email: 'accounts@woodex.test', password: process.env.WX_PW_ACCOUNTS, own: '/admin/invoices', refused: '/admin/leads' },
].filter((account) => (process.env.OS_ACCOUNTS ? process.env.OS_ACCOUNTS.split(',').map((key) => key.trim()).includes(account.key) : true));

const tried = accounts.filter((account) => account.password);
const leftOut = accounts.filter((account) => !account.password);
if (tried.length === 0) {
  console.error('No password is set (WX_PW_OWNER, WX_PW_MANAGER, WX_PW_SALES, WX_PW_ACCOUNTS): nothing could be tried.');
  process.exit(2);
}

const browser = await launchBrowser();
if (!browser) process.exit(0); // lib/browser.mjs has said why, and has ended the run itself where a browser is required
const steps = [];
/** Every password of this run, so that none can reach the output inside an error message. */
const secrets = accounts.map((account) => account.password).filter(Boolean);
const clean = (text) => secrets.reduce((line, secret) => line.split(secret).join('•••'), String(text));

let page;
let account = null;
async function step(name, action) {
  const entry = { account: account ? account.email : '—', name, result: 'ok', note: '' };
  const started = Date.now();
  try {
    entry.note = clean((await action()) || '');
  } catch (error) {
    entry.result = 'FAILED';
    entry.note = clean(String(error.message).split('\n')[0]).slice(0, 300);
    await page.screenshot({ path: path.join(out, `failed-${steps.length + 1}.jpg`), type: 'jpeg', quality: 60 }).catch(() => {});
  }
  entry.ms = Date.now() - started;
  steps.push(entry);
  console.log(`  ${entry.result.padEnd(7)} ${name}${entry.note ? ` — ${entry.note}` : ''} (${entry.ms} ms)`);
  return entry.result === 'ok';
}
const must = (condition, message) => { if (!condition) throw new Error(message); };
const quiet = () => page.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
const here = () => new URL(page.url()).pathname;
const open = async (address) => {
  const response = await page.goto(`${base}${address}`, { waitUntil: 'domcontentloaded', timeout: 45000 });
  await quiet();
  return response ? response.status() : 0;
};
const signedInAs = async () => ((await page.getByRole('button', { name: /Your account/ }).first().getAttribute('aria-label', { timeout: 10000 }).catch(() => '')) || '').replace(/^Your account:\s*/, '').trim();
const heading = async () => ((await page.locator('h1').first().innerText({ timeout: 10000 }).catch(() => '')) || '').trim();
/**
 * Fill the form and send it. The form is sent by the page's own script: a sign-in that is accepted leaves the
 * page, one that is refused stays on it and writes why into the line above the fields. So the wait is for
 * whichever comes first: another address, that line, or (where the script did not run) a page loaded again.
 */
// While the page asks the server that line is a "status" ("Checking your account…"); a refusal makes it an "alert".
const refusal = () => page.locator('[data-sign-in-status][role="alert"]:not([hidden])');
const send = async (email, password) => {
  await page.locator('#email').fill(email);
  await page.locator('#password').fill(password);
  await page.getByRole('button', { name: 'Sign in', exact: true }).click();
  await Promise.race([
    page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 45000 }),
    refusal().waitFor({ state: 'visible', timeout: 45000 }),
    page.waitForEvent('load', { timeout: 45000 }),
  ]).catch(() => {});
  await quiet();
};

console.log(`The sign-in of ${base}, ${tried.length} of ${accounts.length} accounts${leftOut.length ? ` (no password given for ${leftOut.map((one) => one.email).join(', ')})` : ''}`);

for (account of tried) {
  // A new browser profile for each person: nothing of the one before is left, as on another machine.
  const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  page = await context.newPage();
  console.log(`\n${account.email}`);

  await step('the sign-in page opens and says that this is not production', async () => {
    const status = await open('/admin/login');
    must(status === 200, `the sign-in page answered ${status}`);
    const text = await page.locator('body').innerText();
    must(/not production/i.test(text), 'the page does not say "NOT PRODUCTION"');
    must(await page.locator('#email').isVisible() && await page.locator('#password').isVisible(), 'the form has no e-mail or password field');
    // A site may show the demo owner's password to everyone, on purpose; no site shows another person's.
    const markup = await page.content();
    const owners = process.env.WX_PW_OWNER || '';
    must(!secrets.some((secret) => secret !== owners && markup.includes(secret)), 'the page carries a password that is not the owner\'s');
    return owners && markup.includes(owners) ? 'this site shows the owner\'s password on the page, on purpose' : 'the page names no password';
  });

  await step('a wrong password is refused, and the form says so', async () => {
    await send(account.email, `not-the-password-${Date.now()}`);
    must(here().endsWith('/login'), `a wrong password led to ${here()}`);
    // The script's own line, or the form's error where the page was loaded again.
    const said = (await refusal().first().innerText({ timeout: 5000 }).catch(() => '')).trim()
      || (((await page.locator('body').innerText()).match(/[^\n]*(do not match|incorrect|invalid|wrong|too many)[^\n]*/i) || [''])[0]).trim();
    must(said !== '', 'the form came back without saying why');
    must(!/too many/i.test(said), 'the site answered "too many attempts": wait a minute and run again');
    return `"${said.slice(0, 90)}"`;
  });

  await step('…and the admin still asks for a sign-in', async () => {
    await open('/admin');
    must(here().endsWith('/login'), `the dashboard opened without a sign-in (${here()})`);
  });

  const inside = await step('the right password opens the dashboard, under that person\'s name', async () => {
    if (!here().endsWith('/login')) await open('/admin/login');
    await send(account.email, account.password);
    must(!here().endsWith('/login'), 'the right password was refused: the form came back');
    must(/\/admin\/?$/.test(here()), `signed in, but sent to ${here()}`);
    const name = await signedInAs();
    must(name !== '', 'the dashboard shows nobody\'s name');
    account.name = name;
    return `"${name}", ${await heading()}`;
  });

  if (inside) {
    await step(`a screen of that person's own work opens (${account.own})`, async () => {
      const status = await open(account.own);
      must(status === 200, `${account.own} answered ${status}`);
      must(await signedInAs() === account.name, 'the second page does not show the same person');
      return await heading();
    });

    await step('…and after the page is loaded again', async () => {
      const response = await page.reload({ waitUntil: 'domcontentloaded', timeout: 45000 });
      await quiet();
      must(response && response.status() === 200, `the page answered ${response ? response.status() : 'nothing'} when loaded again`);
      must(await signedInAs() === account.name, 'after loading again the page does not show the same person');
    });

    if (account.refused) {
      await step(`a screen that person may not open is refused (${account.refused})`, async () => {
        const status = await open(account.refused);
        must(status === 403, `${account.refused} answered ${status}, not 403`);
        const text = await page.locator('body').innerText();
        must(/403|not allowed|permission|forbidden|access/i.test(text), 'the refusal is not the admin\'s own page');
        return '403';
      });
    }

    await step('signing out leads to the sign-in page, and the dashboard asks for a sign-in again', async () => {
      await open('/admin');
      await page.getByRole('button', { name: /Your account/ }).first().click();
      await Promise.all([page.waitForURL((url) => url.pathname.endsWith('/login'), { timeout: 30000 }), page.getByRole('menuitem', { name: 'Sign out' }).click()]);
      await open('/admin');
      must(here().endsWith('/login'), `after signing out the dashboard still opens (${here()})`);
    });
  }
  await context.close();
}

account = null;
{
  const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  page = await context.newPage();
  console.log('\nwithout an account');
  await step('the page behind "Forgot password?" opens', async () => {
    await open('/admin/login');
    await Promise.all([page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30000 }), page.getByRole('link', { name: /Forgot password/i }).click()]);
    await quiet();
    must(await page.locator('input[type="email"], #email').first().isVisible(), 'the page has no field for the e-mail address');
    return `${here()}: ${await heading()}`;
  });
  await context.close();
}
await browser.close();

const failed = steps.filter((entry) => entry.result !== 'ok');
const missing = strict ? leftOut : [];
const summary = `${steps.length - failed.length} of ${steps.length} steps held for ${tried.length} account${tried.length === 1 ? '' : 's'} on ${base}`
  + (leftOut.length ? `; left out, no password given: ${leftOut.map((one) => one.email).join(', ')}` : '');
writeFileSync(path.join(out, 'signin.json'), JSON.stringify({ base, at: new Date().toISOString(), tried: tried.map((one) => ({ email: one.email, name: one.name || null })), leftOut: leftOut.map((one) => one.email), steps }, null, 2));
console.log(`\n${summary}.`);
for (const entry of failed) console.log(`  FAILED  ${entry.account}: ${entry.name} — ${entry.note}`);
if (process.env.GITHUB_ACTIONS === 'true') {
  const level = failed.length || missing.length ? 'error' : 'notice';
  const lines = steps.map((entry) => `${entry.result === 'ok' ? 'ok' : 'FAILED'} ${entry.account}: ${entry.name}${entry.note ? ` (${entry.note})` : ''}`).join(' | ');
  console.log(`::${level} title=${summary.replace(/[\r\n:]/g, ' ')}::${lines.replace(/[\r\n]/g, ' ')}`);
}
if (missing.length) console.error(`OS_STRICT=1: every account must be tried, and ${missing.length} had no password.`);
process.exit(failed.length || missing.length ? 1 : 0);

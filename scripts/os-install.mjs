#!/usr/bin/env node
/**
 * WOODEX OS: can the owner install it? A fresh release, the installer, the first sign-in, pressed in a browser.
 *
 * scripts/os-journeys.mjs and scripts/os-screens.mjs need an admin that is installed already. This one starts
 * before that: a website folder that holds a release and nothing else (no settings, no database tables, no
 * account), as the host has it after its first Git deployment. It does what the owner will do:
 *
 *   opens the site and is sent to the installer; reads the setup code where only the hosting account can read
 *   it; is refused with a wrong code; enters the database and the first account; watches the installer create the
 *   tables and close itself; signs in; reads on the Health screen what the site runs on; starts the scheduler
 *   with the cron line the installer printed, and sees its heartbeat.
 *
 * With --after-deploy it does what follows the next deployment instead: the site is installed, a newer release
 * that brings one more database change has taken the place of the first one. The owner signs in with the same
 * password (the settings and the database were not in the folder that was replaced), is sent to the one page
 * that applies the change, presses "Apply update", and works on.
 *
 * It WRITES: it installs the site it is pointed at. Point it only at a rehearsal that is thrown away afterwards.
 *
 *   OS_BASE              address of the site, default http://127.0.0.1:8195 (platform/tools/hostinger/rehearse.sh)
 *   WX_PW_OWNER          the password the first owner is given (required; ten characters, letters and numbers)
 *   OS_OWNER_EMAIL       the first owner's e-mail address, default owner@woodex.test
 *   OS_SETUP_CODE_FILE   the file the installer writes the setup code into, as this machine reaches it
 *   OS_SETUP_CODE_CMD    or a command that prints that file (for a site inside a container)
 *   OS_DB                mysql (default) or sqlite
 *   OS_DB_HOST, OS_DB_PORT, OS_DB_NAME, OS_DB_USER, OS_DB_PASSWORD   the database, for mysql
 *   OS_EXPECT_DB         what the Health screen must name as the database server, e.g. MariaDB (optional)
 *   OS_CRON_VIA          how a command is run on the site's machine, e.g. "docker exec -u u123456789 web": the
 *                        cron line is then run once exactly as the installer printed it (optional)
 *   OS_CRON_CMD          or a whole command that starts the scheduler once, where the printed line cannot be
 *                        used as it is (the sandbox keeps its PHP elsewhere)
 *   OS_EXPECT_MIGRATION  with --after-deploy: the database change the new release brings, by its file name
 *   --out <dir>          write journeys.json (the form scripts/os-notice.mjs reads) and pictures of failed steps
 *
 * Exit 1 when a step fails. The setup code and the password are never printed.
 * Browser: see scripts/lib/browser.mjs (E2E_CHROMIUM_PATH, E2E_CHROMIUM_ARGS, E2E_REQUIRED).
 */
import { execSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { launchBrowser } from './lib/browser.mjs';

const base = (process.env.OS_BASE || 'http://127.0.0.1:8195').replace(/\/$/, '');
const outIndex = process.argv.indexOf('--out');
const out = outIndex > -1 ? process.argv[outIndex + 1] : null;
if (out) mkdirSync(out, { recursive: true });

const password = process.env.WX_PW_OWNER;
if (!password) { console.error('WX_PW_OWNER is not set.'); process.exit(2); }
const email = process.env.OS_OWNER_EMAIL || 'owner@woodex.test';
const database = (process.env.OS_DB || 'mysql').toLowerCase();
const afterDeploy = process.argv.includes('--after-deploy');
if (!afterDeploy && !process.env.OS_SETUP_CODE_FILE && !process.env.OS_SETUP_CODE_CMD) {
  console.error('Set OS_SETUP_CODE_FILE or OS_SETUP_CODE_CMD: the installer asks for the code it wrote on the server.');
  process.exit(2);
}

const steps = [];
const problems = [];
const browser = await launchBrowser();
const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
const page = await context.newPage();
// A step that asks for a refusal on purpose names the status it expects.
let expected = null;
page.on('pageerror', (error) => problems.push(`page error: ${error.message.slice(0, 200)}`));
page.on('console', (message) => {
  if (message.type() !== 'error') return;
  if (expected && message.text().includes(`status of ${expected}`)) return;
  problems.push(`console: ${message.text().replace(/'nonce-[^']+'/g, "'nonce-…'").slice(0, 220)}`);
});
page.on('response', (response) => { if (response.status() >= 500) problems.push(`HTTP ${response.status()} ${new URL(response.url()).pathname}`); });

async function step(name, run) {
  const started = Date.now();
  const entry = { name, result: 'ok', note: '', by: 'the owner' };
  try {
    entry.note = (await run()) || '';
  } catch (error) {
    entry.result = 'FAILED';
    entry.note = String(error.message).split('\n')[0].slice(0, 300);
    if (out) await page.screenshot({ path: path.join(out, `failed-${steps.length + 1}.jpg`), type: 'jpeg', quality: 60 }).catch(() => {});
  }
  entry.ms = Date.now() - started;
  steps.push(entry);
  console.log(`  ${entry.result.padEnd(8)} ${name}${entry.note ? ` — ${entry.note}` : ''} (${entry.ms} ms)`);
  return entry.result === 'ok';
}
const must = (condition, message) => { if (!condition) throw new Error(message); };
const words = async () => (await page.locator('body').innerText()).replace(/\s+/g, ' ');

/** The setup code as the hosting account reads it; null when the file is not there. */
function setupCode() {
  let text;
  try {
    text = process.env.OS_SETUP_CODE_FILE
      ? readFileSync(process.env.OS_SETUP_CODE_FILE, 'utf8')
      : execSync(process.env.OS_SETUP_CODE_CMD, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'ignore'] });
  } catch {
    return null;
  }
  return text.match(/[A-Z2-9]{4}(?:-[A-Z2-9]{4}){5}/)?.[0] ?? null;
}

async function fillForm(code, { connection = database, databasePassword = process.env.OS_DB_PASSWORD || '' } = {}) {
  await page.fill('#setup_code', code);
  await page.check(`input[name="connection"][value="${connection}"]`);
  if (connection === 'mysql') {
    await page.fill('#db_host', process.env.OS_DB_HOST || '127.0.0.1');
    await page.fill('#db_port', process.env.OS_DB_PORT || '3306');
    await page.fill('#db_database', process.env.OS_DB_NAME || '');
    await page.fill('#db_username', process.env.OS_DB_USER || '');
    await page.fill('#db_password', databasePassword);
  }
  await page.fill('#owner_name', 'Rehearsal Owner');
  await page.fill('#owner_email', email);
  await page.fill('#owner_password', password);
  await page.fill('#owner_password_confirmation', password);
}

/** What System → Health shows: the facts of the installation and the state of each check. */
async function health() {
  await page.goto(`${base}/admin/system/health`, { waitUntil: 'load' });
  await page.getByRole('heading', { name: 'System health' }).waitFor({ timeout: 15000 });
  const facts = {};
  for (const row of await page.locator('dl > div').all()) {
    const [label, value] = [await row.locator('dt').innerText(), await row.locator('dd').innerText()];
    facts[label.trim()] = value.trim();
  }
  const checks = {};
  for (const card of await page.locator('[data-health-check]').all()) checks[await card.getAttribute('data-health-check')] = await card.getAttribute('data-health-state');
  return { facts, checks };
}

async function signOut() {
  await page.getByRole('button', { name: /Your account/ }).click();
  await Promise.all([page.waitForURL((url) => url.pathname.endsWith('/login'), { timeout: 20000 }), page.getByRole('menuitem', { name: 'Sign out' }).click()]);
  expected = 302;
  const answer = await context.request.get(`${base}/admin`, { maxRedirects: 0 });
  expected = null;
  must(answer.status() === 302 && /\/admin\/login$/.test(answer.headers().location ?? ''), 'after signing out the admin does not ask for a sign-in');
  return 'the admin asks for a sign-in again';
}

let code = null;
let cron = '';

if (afterDeploy) {
  console.log(`The site at ${base} after its next deployment, as its owner meets it`);
  const migration = process.env.OS_EXPECT_MIGRATION || '';

  await step('After a deployment the site is still installed and the installer stays closed', async () => {
    const installer = await context.request.get(`${base}/install`, { maxRedirects: 0 });
    must(installer.status() === 404, `/install answers ${installer.status()} after a deployment: the site no longer knows it is installed`);
    const answer = await page.goto(`${base}/admin/login`, { waitUntil: 'load' });
    must(answer.status() === 200 && new URL(page.url()).pathname === '/admin/login', `the sign-in page answered ${answer.status()} at ${new URL(page.url()).pathname}`);
    return '/install answers 404; the sign-in page opens';
  });

  await step('The owner signs in with the same password and is sent to the one page that applies the change', async () => {
    await page.fill('#email', email);
    await page.fill('#password', password);
    await Promise.all([page.waitForURL((url) => !/\/login$/.test(url.pathname), { timeout: 30000 }), page.getByRole('button', { name: 'Sign in' }).click()]);
    if (!/\/admin\/system\/updates$/.test(new URL(page.url()).pathname)) await page.goto(`${base}/admin`, { waitUntil: 'load' });
    must(/\/admin\/system\/updates$/.test(new URL(page.url()).pathname), `the owner is at ${new URL(page.url()).pathname}, not at the update page`);
    await page.getByRole('heading', { name: 'A database update is needed' }).waitFor({ timeout: 10000 });
    const waiting = await page.locator('section ul li').allInnerTexts();
    must(waiting.length > 0, 'the update page lists no change');
    if (migration) must(waiting.map((line) => line.trim()).includes(migration), `the update page does not name ${migration}: ${waiting.join(', ').slice(0, 160)}`);
    return `${waiting.length} change(s) waiting: ${waiting.map((line) => line.trim()).join(', ').slice(0, 160)}`;
  });

  await step('"Apply update" applies it, and the admin opens again', async () => {
    await Promise.all([page.waitForNavigation({ waitUntil: 'load', timeout: 180000 }), page.getByRole('button', { name: 'Apply update' }).click()]);
    const text = await words();
    must(/Update applied\./.test(text), `the update was not applied: ${text.slice(0, 200)}`);
    await page.getByRole('heading', { name: 'The database is up to date' }).waitFor({ timeout: 10000 });
    if (migration) must(text.includes(migration), `the log of the update does not name ${migration}`);
    await Promise.all([page.waitForURL(/\/admin$/, { timeout: 30000 }), page.getByRole('link', { name: 'Open the dashboard' }).click()]);
    return 'applied; the dashboard opens';
  });

  await step('System health agrees: the database is up to date, the settings folder is the one from before', async () => {
    const { facts, checks } = await health();
    for (const name of ['Database up to date', 'Settings and uploads folder', 'Front-end build', 'Debug mode off in production']) {
      must(checks[name] === 'ok', `"${name}" is ${checks[name] ?? 'not on the screen'}`);
    }
    must(facts['App environment'] === 'production', `the environment is "${facts['App environment']}", not production`);
    if (process.env.OS_EXPECT_DB) must(new RegExp(process.env.OS_EXPECT_DB, 'i').test(facts['Database server']), `the database server is "${facts['Database server']}", ${process.env.OS_EXPECT_DB} was expected`);
    return `${facts['Database server']}, release ${facts.Release}`;
  });

  await step('The owner signs out', signOut);
} else {
console.log(`Installing the site at ${base} as its owner would`);

await step('A site that is not installed sends every page to the installer', async () => {
  const answer = await page.goto(`${base}/admin`, { waitUntil: 'load' });
  must(new URL(page.url()).pathname === '/install', `/admin led to ${new URL(page.url()).pathname}, not to the installer`);
  must(answer.status() === 200, `the installer answered ${answer.status()}`);
  await page.getByRole('heading', { name: 'Install Woodex OS' }).waitFor({ timeout: 10000 });
  const missing = await page.locator('[data-requirement="missing"]').allInnerTexts();
  must(missing.length === 0, `the server check names something missing: ${missing.map((text) => text.replace(/\s+/g, ' ').trim()).join(' | ').slice(0, 220)}`);
  const met = await page.locator('[data-requirement="met"]').count();
  must(met >= 5, `the server check shows ${met} points, five are expected`);
  return `${met} points of the server check are met`;
});

await step('The setup code is where only the hosting account reads it, and the web server hands out nothing', async () => {
  code = setupCode();
  must(code, 'the installer was opened and no setup code can be read where it says it wrote one');
  must(!(await page.content()).includes(code), 'the installer page itself shows the setup code');
  const closed = ['/WOODEX-SETUP-CODE.txt', '/RELEASE.json', '/.htaccess', '/index.php.bak', '/platform/.env', '/platform/composer.json',
    '/platform/vendor/autoload.php', '/platform/storage/logs/laravel.log', '/platform/public/index.php', '/vendor/autoload.php', '/composer.json'];
  for (const address of closed) {
    const answer = await context.request.get(`${base}${address}`, { maxRedirects: 0 });
    // An address the web server does not refuse itself reaches the application, which sends it to the installer
    // like every other page of a site that is not installed. Laravel's public folder asked for by its own path
    // is sent to the clean address by that folder's own .htaccess (Apache reads the innermost one). In each
    // case nothing of the file is handed out.
    const cleanAddress = answer.status() === 301 && address.startsWith('/platform/public/') && !/platform/i.test(answer.headers().location ?? 'platform');
    must([302, 403, 404].includes(answer.status()) || cleanAddress, `${address} answered ${answer.status()}`);
    const body = await answer.text();
    must(!body.includes(code) && !/"require"\s*:|APP_KEY=|<\?php/.test(body), `${address} handed out a file`);
  }
  return `${closed.length} addresses asked, none handed out`;
});

await step('Without the setup code nothing is installed', async () => {
  const refused = async (what) => {
    expected = 422;
    await Promise.all([page.waitForNavigation({ waitUntil: 'load' }), page.getByRole('button', { name: 'Install Woodex OS' }).click()]);
    expected = null;
    must(await page.locator('#setup_code').count() === 1, `${what}: the form of step 1 is no longer shown`);
    const answer = await context.request.get(`${base}/admin/login`, { maxRedirects: 0 });
    must(answer.status() === 302 && /\/install$/.test(answer.headers().location ?? ''), `${what}: the site no longer sends its pages to the installer`);
    return words();
  };
  // A wrong code, and no database that could stand in for it (the SQLite file is nobody's proof).
  await fillForm('AAAA-BBBB-CCCC-DDDD-EEEE-FFFF', { connection: 'sqlite' });
  must(/does not match/i.test(await refused('a wrong code')), 'a wrong setup code was not refused with a reason');
  if (database !== 'mysql') return 'a wrong code is refused with its reason; the site is still not installed';
  // On MySQL the installer takes a database user of the hosting account in place of the code, for an owner who
  // cannot find the file: only the account can create such a user. Knowing its name must open nothing.
  await fillForm('', { databasePassword: `not-${randomBytes(9).toString('hex')}` });
  const text = await refused("no code, and the account's database user with a wrong password");
  must(!/is installed|creating the tables|step 2/i.test(text), "the account's database user with a wrong password was let through");
  return "a wrong code is refused with its reason; so is the account's database user with a wrong password; the site is still not installed";
});

await step('The owner installs: the database, the address of the site, the first account', async () => {
  await fillForm(code);
  const address = await page.inputValue('#app_url');
  must(address === base, `the installer proposes ${address} as the address of the site, not ${base}`);
  must(await page.isChecked('input[name="enforce_2fa"]'), 'two-factor sign-in for admin and accountant is not proposed as switched on');
  const began = Date.now();
  await page.getByRole('button', { name: 'Install Woodex OS' }).click();
  // Step 2 starts by itself in a fresh request (a script of the page; the content policy must let it run).
  await page.getByRole('heading', { name: 'Woodex OS is installed' }).waitFor({ timeout: 180000 });
  const text = await words();
  must(text.includes(`Owner account ${email.toLowerCase()} is ready`), 'the last page does not name the owner account that was made');
  cron = ((await page.locator('code').allInnerTexts()).find((line) => /artisan schedule:run$/.test(line.trim())) || '').trim();
  must(cron, 'the last page prints no cron line');
  return `installed in ${Math.round((Date.now() - began) / 1000)} s on ${database}; cron line: ${cron}`;
});

await step('The installer has closed itself', async () => {
  const answer = await context.request.get(`${base}/install`, { maxRedirects: 0 });
  must(answer.status() === 404, `/install answers ${answer.status()} after the installation`);
  const again = await context.request.post(`${base}/install`, { form: { setup_code: code }, maxRedirects: 0 });
  must(again.status() === 404, `a second installation is answered with ${again.status()}`);
  must(setupCode() === null, 'the setup code is still on the server after the installation');
  return '/install answers 404 and the setup code is deleted';
});

await step('The first owner signs in to an admin that holds no sample records', async () => {
  await page.getByRole('link', { name: 'Sign in to the admin' }).click();
  await page.waitForURL(/\/admin\/login$/);
  must(await page.inputValue('#password') === '', 'the sign-in form of a real installation is filled in');
  await page.fill('#email', email);
  await page.fill('#password', password);
  await Promise.all([page.waitForURL((url) => !/\/login$/.test(url.pathname), { timeout: 30000 }), page.getByRole('button', { name: 'Sign in' }).click()]);
  // A first sign-in is led to the welcome of Workspace preferences (list line T-056): protect the sign-in, make
  // the workspace yours, see what you can open. The owner who has just installed the site is such a person.
  must(/\/admin\/system\/preferences$/.test(new URL(page.url()).pathname), `after the first sign-in the owner is at ${new URL(page.url()).pathname}, not at the welcome`);
  await page.locator('[data-welcome]').waitFor({ timeout: 10000 });
  must(!/NOT PRODUCTION|Demo workspace|sample data/i.test(await words()), 'a real installation shows the demo notice');
  await page.goto(`${base}/admin`, { waitUntil: 'load' });
  const text = await words();
  must(/Dashboard/i.test(await page.title()) || /Dashboard/i.test(text), 'the dashboard does not open after the welcome');
  must(!/NOT PRODUCTION|Demo workspace|sample data/i.test(text), 'the dashboard of a real installation shows the demo notice');
  return 'the welcome of a first sign-in, then the dashboard, without the demo notice';
});

await step('System health says what the site runs on', async () => {
  const { facts, checks } = await health();
  must(facts['App environment'] === 'production', `the environment is "${facts['App environment']}", not production`);
  must(facts['Database server'], 'the Health screen does not name the database server');
  if (process.env.OS_EXPECT_DB) must(new RegExp(process.env.OS_EXPECT_DB, 'i').test(facts['Database server']), `the database server is "${facts['Database server']}", ${process.env.OS_EXPECT_DB} was expected`);
  must(facts.Release && !/not a packaged release/i.test(facts.Release), `the Health screen does not name the release (${facts.Release ?? 'no row'})`);
  for (const name of ['Database up to date', 'Settings and uploads folder', 'Front-end build', 'Debug mode off in production']) {
    must(checks[name] === 'ok', `"${name}" is ${checks[name] ?? 'not on the screen'}`);
  }
  must(checks['Cron heartbeat'] === 'check', 'the scheduler has a heartbeat before any cron line ran');
  return `PHP ${facts.PHP}, ${facts['Database server']}, release ${facts.Release}; no heartbeat yet, as it should be`;
});

if (process.env.OS_CRON_VIA || process.env.OS_CRON_CMD) {
  await step("The installer's cron line starts the scheduler, and its heartbeat is seen", async () => {
    must(cron, 'no cron line was read from the last page of the installer, so none can be run');
    execSync(process.env.OS_CRON_VIA ? `${process.env.OS_CRON_VIA} ${cron}` : process.env.OS_CRON_CMD, { stdio: ['ignore', 'ignore', 'pipe'], timeout: 180000 });
    await page.reload({ waitUntil: 'load' });
    const card = page.locator('[data-health-check="Cron heartbeat"]');
    await card.waitFor({ timeout: 15000 });
    must(await card.getAttribute('data-health-state') === 'ok', `after the cron line ran the heartbeat is still "${(await card.innerText()).replace(/\s+/g, ' ').slice(0, 80)}"`);
    return `${process.env.OS_CRON_VIA ? 'run exactly as printed' : 'run with this machine\'s PHP'}; ${(await card.innerText()).replace(/\s+/g, ' ').slice(0, 40)}`;
  });
}

await step('The owner signs out', signOut);
}

await browser.close();

const failed = steps.filter((entry) => entry.result === 'FAILED');
const serious = [...new Set(problems)];
if (out) writeFileSync(path.join(out, 'journeys.json'), `${JSON.stringify({ base, journey: afterDeploy ? 'after a deployment' : 'installation', database, steps, problems: serious }, null, 2)}\n`);
console.log(`\n${steps.length - failed.length} of ${steps.length} steps held${serious.length ? `; the browser reported ${serious.length} problem(s):` : '; the browser reported no problem'}`);
for (const problem of serious) console.log(`  ${problem}`);
process.exit(failed.length || serious.length ? 1 : 0);

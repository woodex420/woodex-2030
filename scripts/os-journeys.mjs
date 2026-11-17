#!/usr/bin/env node
/**
 * WOODEX OS: does the work get done? One person's working day, driven through the real screens.
 *
 * scripts/os-screens.mjs opens every screen and measures it; it presses nothing. This script presses: it signs in,
 * creates a lead, finds it again, adds a task, searches for it, writes a quotation with one priced line, opens the
 * PDFs and the CSV export, uploads a sheet, finds a product of the real catalogue by its code and opens it, asks for
 * a page that does not exist and one it may not see, and signs out. Every step is a Livewire action or a download
 * that the crawl cannot reach.
 *
 * The step names are cited by docs/woodex-os/acceptance/flows.json; scripts/verify-acceptance.mjs fails when a
 * cited step is renamed or removed.
 *
 * It WRITES records. Run it only against a demo, the rehearsal or a preview: never against real data.
 * The script refuses a page that does not carry the "sample data" notice unless OS_ALLOW_REAL=1.
 *
 *   OS_BASE        address of the admin's host, default http://127.0.0.1:8191 (tools/vercel/serve-demo.sh)
 *   WX_PW_OWNER    password of owner@woodex.test (required)
 *   WX_PW_SALES    password of sales@woodex.test (optional: adds the "no access" step)
 *   OS_TRACE=1     print every request with the time it took
 *   --out <dir>    write journeys.json and the screenshots of failed steps there
 *
 * Exit 1 when a step fails. A step that cannot run here (a switched-off module) is reported as "left out".
 * Browser: see scripts/lib/browser.mjs (E2E_CHROMIUM_PATH, E2E_CHROMIUM_ARGS, E2E_REQUIRED).
 */
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { launchBrowser } from './lib/browser.mjs';

// The catalogue the admin is loaded with (decision DL-022). The journey takes its product from this file, so it
// types nothing that the file does not say.
const catalogue = JSON.parse(readFileSync(path.join(path.dirname(fileURLToPath(import.meta.url)), '../platform/resources/data/woodex-catalogue.json'), 'utf8'));

const base = (process.env.OS_BASE || 'http://127.0.0.1:8191').replace(/\/$/, '');
const outIndex = process.argv.indexOf('--out');
const out = outIndex > -1 ? process.argv[outIndex + 1] : null;
if (out) mkdirSync(out, { recursive: true });
if (!process.env.WX_PW_OWNER) { console.error('WX_PW_OWNER is not set.'); process.exit(2); }

const stamp = String(Date.now()).slice(-6);
const person = `Journey Test ${stamp}`;
const phone = `0300 5${stamp}`; // a Pakistani mobile number in the form the admin accepts
const steps = [];
const problems = [];

const browser = await launchBrowser();
const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
const page = await context.newPage();
page.on('pageerror', (error) => problems.push(`page error: ${error.message.slice(0, 200)}`));
// A step that asks for a refusal on purpose (404, 403) names the status it expects; the browser's own console line
// about that answer is then not a problem.
let expected = null;
page.on('console', (message) => {
  if (message.type() !== 'error') return;
  if (expected && message.text().includes(`status of ${expected}`)) return;
  problems.push(`console: ${message.text().slice(0, 200)}`);
});
page.on('response', (response) => { if (response.status() >= 500) problems.push(`HTTP ${response.status()} ${new URL(response.url()).pathname}`); });
if (process.env.OS_TRACE === '1') {
  // Every request with the time it took, to find what a slow step was waiting for.
  const began = Date.now();
  page.on('requestfinished', (request) => {
    const timing = request.timing();
    const calls = [...new Set((request.postData() || '').match(/"method":"[^"]*"/g) || [])].map((call) => call.slice(10, -1)).join(', ');
    console.log(`    [${String(Date.now() - began).padStart(6)} ms] ${request.method()} ${new URL(request.url()).pathname.slice(0, 70)} took ${Math.round(timing.responseEnd)} ms${calls ? ` (${calls})` : ''}`);
  });
  page.on('requestfailed', (request) => console.log(`    [${String(Date.now() - began).padStart(6)} ms] FAILED ${request.method()} ${new URL(request.url()).pathname.slice(0, 70)} ${request.failure()?.errorText}`));
}

/** One step: its name, whether it held, and what was seen. A failed step does not stop the ones that can still run. */
async function step(name, run) {
  const started = Date.now();
  const entry = { name, result: 'ok', note: '' };
  try {
    const note = await run();
    if (note && note.leftOut) { entry.result = 'left out'; entry.note = note.leftOut; } else entry.note = note || '';
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
/**
 * Wait until something is on the screen, asking four times a second, and say in the failure what was expected.
 * (A Livewire answer changes the page without a navigation, so there is no load event to wait for.)
 */
async function shown(locator, timeout, what) {
  const until = Date.now() + timeout;
  while (Date.now() < until) {
    if (await locator.first().isVisible().catch(() => false)) return;
    await page.waitForTimeout(250);
  }
  throw new Error(`${what} did not appear within ${timeout / 1000} s`);
}
const quiet = () => page.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
/** A file or page asked for by the signed-in page itself, so that the session cookie travels as in a browser. */
const ask = (address) => page.evaluate(async (target) => {
  const response = await fetch(target, { credentials: 'same-origin' });
  const bytes = new Uint8Array(await response.arrayBuffer());
  return { status: response.status, type: response.headers.get('content-type') || '', bytes: bytes.length, starts: String.fromCharCode(...bytes.slice(0, 5)), text: new TextDecoder().decode(bytes.slice(0, 4000)) };
}, address);

async function signIn(email, password) {
  await page.goto(`${base}/admin/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('#email').fill(email);
  await page.locator('#password').fill(password);
  await Promise.all([page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 20000 }), page.getByRole('button', { name: 'Sign in', exact: true }).click()]);
  await quiet();
}
async function signOut() {
  await page.getByRole('button', { name: /Your account/ }).click();
  await Promise.all([page.waitForURL((url) => url.pathname.endsWith('/login'), { timeout: 20000 }), page.getByRole('menuitem', { name: 'Sign out' }).click()]);
}

console.log(`WOODEX OS journeys against ${base}`);
let leadPath = null;
let quotePath = null;
let contactId = null;

const signedIn = await step('sign in as the owner', async () => {
  await signIn('owner@woodex.test', process.env.WX_PW_OWNER);
  must(new URL(page.url()).pathname.startsWith('/admin'), `landed on ${page.url()}`);
  const sample = await page.locator('[data-demo-notice]').count();
  must(sample > 0 || process.env.OS_ALLOW_REAL === '1', 'this admin does not say it holds sample data; refusing to write (OS_ALLOW_REAL=1 overrides)');
  return `dashboard "${(await page.locator('h1').first().innerText()).trim()}"`;
});

if (signedIn) {
  await step('create a lead from the New menu', async () => {
    await page.getByRole('button', { name: 'Create new' }).click();
    await Promise.all([page.waitForURL(/\/admin\/leads\?/), page.locator('[data-quick-create="lead"]').click()]);
    await page.locator('#newName').fill(person);
    await page.locator('#newPhone').fill(phone);
    await page.locator('#newMessage').fill('Written by scripts/os-journeys.mjs.');
    // The admin opens the new lead's own page.
    await Promise.all([page.waitForURL(/\/admin\/leads\/\d+/, { timeout: 20000 }), page.getByRole('button', { name: 'Create lead' }).click()]);
    await quiet();
    leadPath = new URL(page.url()).pathname;
    must(await page.getByRole('heading', { name: person }).first().isVisible(), 'the page that opened is not the new lead');
    return `${(await page.locator('.wx-topbar-trail').innerText().catch(() => '')).replace(/\s+/g, ' ').trim() || leadPath}`;
  });

  await step('the lead is still there after a reload, and its page opens', async () => {
    must(leadPath, 'no lead was created');
    await page.goto(base + leadPath, { waitUntil: 'domcontentloaded' });
    await quiet();
    must(await page.getByText(person).first().isVisible(), 'the lead page does not show the name');
    const contact = await page.locator('a[href*="/admin/contacts/"]').first().getAttribute('href').catch(() => null);
    contactId = contact ? contact.split('/').pop().split('?')[0] : null;
    return `contact ${contactId ?? 'not linked'}`;
  });

  await step('add a task on the lead (a Livewire form)', async () => {
    must(leadPath, 'no lead was created');
    const title = `Call back ${stamp}`;
    await page.getByLabel('New task').fill(title);
    await page.getByRole('button', { name: 'Add task' }).click();
    await shown(page.getByText(title), 15000, 'the new task');
    await page.reload({ waitUntil: 'domcontentloaded' });
    await quiet();
    must(await page.getByText(title).first().isVisible(), 'the task is gone after a reload');
    return title;
  });

  await step('find the lead with the search in the top bar', async () => {
    await page.goto(`${base}/admin`, { waitUntil: 'domcontentloaded' });
    await quiet();
    await page.keyboard.press('Control+k');
    const field = page.getByRole('combobox').or(page.locator('input[type="search"]:visible')).first();
    await shown(field, 8000, 'the search field');
    await field.fill(person);
    const hit = page.locator('a:visible, [role="option"]:visible', { hasText: person }).first();
    await shown(hit, 15000, 'a search result with the lead\'s name');
    return `"${(await hit.innerText()).replace(/\s+/g, ' ').trim().slice(0, 70)}"`;
  });

  await step('write a draft quotation for that client', async () => {
    await page.goto(`${base}/admin`, { waitUntil: 'domcontentloaded' });
    await quiet();
    await page.getByRole('button', { name: 'Create new' }).click();
    const entry = page.locator('[data-quick-create="quotation"]');
    if ((await entry.count()) === 0) return { leftOut: 'the New menu offers no quotation to this role here' };
    await Promise.all([page.waitForURL(/\/admin\/quotes\//), entry.click()]);
    await quiet();
    await page.getByLabel('Search clients').fill(person);
    await page.getByRole('button', { name: new RegExp(person) }).first().click();
    await shown(page.getByText('Enquiry', { exact: true }), 15000, 'the chosen client');
    await page.getByRole('button', { name: /Blank quotation/ }).click();
    await shown(page.locator('button.ring-1', { hasText: 'Blank quotation' }), 15000, 'the chosen blank template');
    await quiet();
    const form = new URL(page.url()).pathname;
    await Promise.all([page.waitForURL((url) => url.pathname !== form, { timeout: 30000 }), page.getByRole('button', { name: 'Create draft quotation' }).click()]);
    await quiet();
    quotePath = new URL(page.url()).pathname;
    return `${(await page.locator('h1').first().innerText()).replace(/\s+/g, ' ').trim().slice(0, 60)} at ${quotePath}`;
  });

  await step('price one line; the total follows', async () => {
    if (!quotePath) return { leftOut: 'no quotation was written' };
    let quantity = page.getByLabel('Quantity').first();
    if ((await quantity.count()) === 0) {
      await page.getByRole('button', { name: 'Add line' }).first().click();
      await shown(quantity, 15000, 'a new line');
    }
    const name = page.locator('input[id^="line-"][id$="-product"]').first();
    if (await name.count()) { await name.fill('Journey desk'); await name.blur(); await quiet(); }
    quantity = page.getByLabel('Quantity').first();
    await quantity.fill('3'); await quantity.blur(); await quiet();
    const rate = page.getByLabel('Rate in rupees').first();
    await rate.fill('12500'); await rate.blur(); await quiet();
    await shown(page.getByText(/37,500/), 15000, 'the total of the priced line');
    await page.reload({ waitUntil: 'domcontentloaded' });
    await quiet();
    must(await page.getByText(/37,500/).first().isVisible(), 'the priced line is gone after a reload');
    return '3 × 12,500 = 37,500';
  });

  await step('the quotation PDF', async () => {
    if (!quotePath) return { leftOut: 'no quotation was written' };
    const file = await ask(`${quotePath}/pdf`);
    must(file.status === 200 && file.type.startsWith('application/pdf') && file.starts === '%PDF-', `answered ${file.status} ${file.type}, starts "${file.starts}"`);
    return `${file.bytes} bytes`;
  });

  await step('the client statement PDF', async () => {
    if (!contactId) return { leftOut: 'the lead page links no contact' };
    const file = await ask(`/admin/clients/${contactId}/statement.pdf`);
    if (file.status === 403 || file.status === 404) return { leftOut: `answered ${file.status} for this role or module` };
    must(file.status === 200 && file.type.startsWith('application/pdf') && file.starts === '%PDF-', `answered ${file.status} ${file.type}, starts "${file.starts}"`);
    return `${file.bytes} bytes`;
  });

  await step('the lead export is a CSV file with the new lead in it', async () => {
    const file = await ask(`/admin/leads/export.csv?year=${new Date().getFullYear()}`);
    if (file.status === 403) return { leftOut: 'this role may not export' };
    must(file.status === 200 && file.type.startsWith('text/csv'), `answered ${file.status} ${file.type}`);
    must(file.text.includes(person), 'the new lead is not in the export');
    return `${file.bytes} bytes`;
  });

  await step('upload a sheet (Livewire file upload)', async () => {
    await page.goto(`${base}/admin/leads/import`, { waitUntil: 'domcontentloaded' });
    await quiet();
    const field = page.getByLabel('Lead sheet CSV');
    if ((await field.count()) === 0) return { leftOut: 'the import screen is not open here' };
    const sent = page.waitForResponse((response) => response.url().includes('/upload-file'), { timeout: 30000 });
    await field.setInputFiles({ name: `journey-${stamp}.csv`, mimeType: 'text/csv', buffer: Buffer.from('Sr,Date,Name,Phone,City\n1,2026-01-05,Sheet Person,03001234567,Lahore\n') });
    const started = Date.now();
    const response = await sent;
    const uploaded = Date.now() - started;
    must(response.status() === 200, `the upload answered ${response.status()}`);
    // The screen names what it read: "1 rows · 5 columns found: Sr, Date, Name, Phone, City".
    await shown(page.getByText(/5\s+columns found/), 30000, 'what the screen read from the sheet');
    await shown(page.getByRole('heading', { name: /Match the columns/ }), 15000, 'the column matching');
    const refused = await page.locator('.text-error-600:visible').allInnerTexts();
    must(refused.length === 0, `the screen shows an error: ${refused.join(' / ')}`);
    return `sent in ${uploaded} ms, read (1 row, 5 columns) after ${Date.now() - started} ms`;
  });

  await step('find a product of the real catalogue by its code, and open it', async () => {
    const wanted = catalogue.items.find((item) => item.sku && item.price > 0);
    await page.goto(`${base}/admin/commerce/products`, { waitUntil: 'domcontentloaded' });
    await quiet();
    const search = page.getByLabel('Search products');
    if ((await search.count()) === 0) return { leftOut: 'the products screen is not open here' };
    if ((await page.locator('article').count()) === 0) return { leftOut: 'this workspace holds no catalogue' };
    await search.fill(wanted.sku);
    // The list answers through Livewire, without a navigation: wait until the code has narrowed it to one card.
    const until = Date.now() + 15000;
    while (Date.now() < until && (await page.locator('article').count()) !== 1) await page.waitForTimeout(250);
    const found = await page.locator('article').count();
    must(found === 1, `the code ${wanted.sku} found ${found} products, not one`);
    const card = page.locator('article').first();
    const text = await card.innerText();
    must(text.includes(wanted.name), `the code ${wanted.sku} found another product: ${text.split('\n')[0].slice(0, 80)}`);
    must(text.includes(wanted.sku), 'the card does not show the code');
    must(/Draft \/ review/.test(text), 'the product is not a draft: the catalogue must never publish itself');
    await Promise.all([page.waitForURL(/\/admin\/commerce\/products\/\d+\/edit/, { timeout: 20000 }), card.getByRole('link', { name: 'Edit product' }).click()]);
    await quiet();
    await shown(page.getByRole('heading', { name: `Edit product · ${wanted.name}` }), 15000, 'the editor of that product');
    return `${wanted.sku} "${wanted.name}" is a draft; its editor opens`;
  });

  await step('a page that does not exist gets the admin\'s own 404', async () => {
    expected = 404;
    const response = await page.goto(`${base}/admin/no-such-screen-${stamp}`, { waitUntil: 'domcontentloaded' });
    must(response.status() === 404, `answered ${response.status()}`);
    must((await page.locator('[data-error-page="404"]').count()) === 1, 'the framework\'s bare page was shown');
    must(!/Stack trace|vendor\/laravel/.test(await page.content()), 'the page shows internals');
    return (await page.locator('h1').innerText()).trim();
  });

  await step('sign out; the admin asks for a sign-in again', async () => {
    expected = null;
    await page.goto(`${base}/admin`, { waitUntil: 'domcontentloaded' });
    await quiet();
    await signOut();
    const response = await page.goto(`${base}/admin`, { waitUntil: 'domcontentloaded' });
    must(new URL(page.url()).pathname.endsWith('/login'), `still inside: ${page.url()} (${response.status()})`);
    return 'back at the sign-in page';
  });

  await step('a sales person is told plainly what they may not open', async () => {
    if (!process.env.WX_PW_SALES) return { leftOut: 'WX_PW_SALES is not set' };
    await signIn('sales@woodex.test', process.env.WX_PW_SALES);
    expected = 403;
    const response = await page.goto(`${base}/admin/system/health`, { waitUntil: 'domcontentloaded' });
    must(response.status() === 403, `answered ${response.status()}`);
    must((await page.locator('[data-error-page="403"]').count()) === 1, 'the framework\'s bare page was shown');
    const text = (await page.locator('h1').innerText()).trim();
    expected = null;
    await page.goto(`${base}/admin`, { waitUntil: 'domcontentloaded' });
    await quiet();
    await signOut();
    return text;
  });
}

await browser.close();
const failed = steps.filter((entry) => entry.result === 'FAILED');
const serverErrors = problems.filter((problem) => problem.startsWith('HTTP 5'));
console.log(`\n${steps.filter((entry) => entry.result === 'ok').length} of ${steps.length} steps held, ${failed.length} failed, ${steps.filter((entry) => entry.result === 'left out').length} left out.`);
if (problems.length) console.log(`Seen on the way (${problems.length}):\n  ${[...new Set(problems)].slice(0, 12).join('\n  ')}`);
if (out) writeFileSync(path.join(out, 'journeys.json'), JSON.stringify({ base, at: new Date().toISOString(), steps, problems }, null, 1));
process.exit(failed.length > 0 || serverErrors.length > 0 ? 1 : 0);

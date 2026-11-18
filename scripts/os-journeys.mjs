#!/usr/bin/env node
/**
 * WOODEX OS: does the work get done? Two journeys, driven through the real screens.
 *
 * scripts/os-screens.mjs opens every screen and measures it; it presses nothing. This script presses.
 *
 * 1. A working day. One person signs in, creates a lead, finds it again, adds a task, tries to walk away from
 *    text that is not saved (a menu link, the Back button, a reload, a click beside the form; on a phone and by
 *    keyboard too), searches for the lead, writes a quotation with one priced line and one product of the
 *    catalogue, presses a menu link with a figure half entered, opens the PDFs and the CSV export, uploads a
 *    sheet, finds a product of the real catalogue by its code and opens it, asks for a page that does not exist
 *    and one it may not see, and signs out.
 * 2. The sale (list line T-080), with the people who do each part: the owner takes an enquiry and hands it to a
 *    sales person; the sales person quotes above their discount limit and must ask; the approver approves; the
 *    quotation is sent; the client says yes; the lead is won; accounts bill the sale and issue the invoice; the
 *    advance is paid and confirmed; the receipt is a numbered PDF; the sales person sees all of it on the lead.
 *    Where the client's own pages are open the client accepts and reports the payment there, on a phone without
 *    a sign-in; where they are off (the product as it ships) staff write the answer down and take the payment.
 *
 * Every step is a Livewire action, a form or a download that the crawl cannot reach.
 *
 * The step names are cited by docs/woodex-os/acceptance/flows.json; scripts/verify-acceptance.mjs fails when a
 * cited step is renamed or removed.
 *
 * It WRITES records. Run it only against a demo, the rehearsal or a preview: never against real data.
 * The script refuses a page that does not carry the "sample data" notice unless OS_ALLOW_REAL=1.
 *
 *   OS_BASE        address of the admin's host, default http://127.0.0.1:8191 (tools/vercel/serve-demo.sh)
 *   WX_PW_OWNER    password of owner@woodex.test (required)
 *   WX_PW_SALES    password of sales@woodex.test (optional: adds the "no access" step and the sales person's
 *                  part of the sale; without it the owner does that part and no approval is needed)
 *   WX_PW_MANAGER  password of manager@woodex.test (optional: the approver; the owner stands in)
 *   WX_PW_ACCOUNTS password of accounts@woodex.test (optional: billing and payment; the owner stands in)
 *   OS_STRICT=1    a step that is left out counts as failed (for a rehearsal that must prove everything)
 *   OS_TRACE=1     print every request with the time it took
 *   --out <dir>    write journeys.json and the screenshots of failed steps there
 *
 * Exit 1 when a step fails. A step that cannot run here (a switched-off module) is reported as "left out".
 * journeys.json names for every step who pressed it.
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
// A step that asks for a refusal on purpose (404, 403) names the status it expects; the browser's own console line
// about that answer is then not a problem.
let expected = null;
// Questions the admin asked before an act that cannot be undone ("Issue this invoice? …"). Each is answered yes;
// the step that pressed the button checks that the question was asked.
const questions = [];
const BEFORE_LEAVING = '(the browser asks before the page is left)';
const ASKED_WRONGLY = 'the browser asked "leave this page?" where nothing was unsaved:';
let leavingExpected = false;
/** The step that is running, for a problem that is noticed outside it. */
let current = null;
/** Everything that goes wrong on a page is a problem of the run, whoever's page it is. */
function watch(target, whose = '') {
  target.on('pageerror', (error) => problems.push(`${whose}page error: ${error.message.slice(0, 200)}`));
  target.on('console', (message) => {
    if (message.type() !== 'error') return;
    if (expected && message.text().includes(`status of ${expected}`)) return;
    // The number in a page's content policy differs on every answer; without it the same message is one problem.
    problems.push(`${whose}console: ${message.text().replace(/'nonce-[^']+'/g, "'nonce-…'").slice(0, 220)}`);
  });
  target.on('response', (response) => { if (response.status() >= 500) problems.push(`HTTP ${response.status()} ${new URL(response.url()).pathname}`); });
  // The browser's own question before a page is left has no words a page can choose: it is recorded by its kind.
  // The admin raises it when it believes that typed text is not saved (list line T-043). One step asks for it on
  // purpose; anywhere else the admin believed so wrongly, after a save or on a form nobody touched, and people
  // would be asked on every page they leave. That fails the run.
  target.on('dialog', (dialog) => {
    const leaving = dialog.type() === 'beforeunload';
    if (leaving && !leavingExpected) problems.push(`${whose}${ASKED_WRONGLY} during "${current ?? 'the start'}"`);
    questions.push(leaving ? BEFORE_LEAVING : dialog.message());
    dialog.accept().catch(() => {});
  });
}
watch(page);
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
  current = name;
  try {
    const note = await run();
    if (note && note.leftOut) { entry.result = process.env.OS_STRICT === '1' ? 'FAILED' : 'left out'; entry.note = `${process.env.OS_STRICT === '1' ? 'left out, and nothing may be here: ' : ''}${note.leftOut}`; } else entry.note = note || '';
  } catch (error) {
    entry.result = 'FAILED';
    entry.note = String(error.message).split('\n')[0].slice(0, 300);
    if (out) await page.screenshot({ path: path.join(out, `failed-${steps.length + 1}.jpg`), type: 'jpeg', quality: 60 }).catch(() => {});
  }
  entry.ms = Date.now() - started;
  entry.by = who;
  steps.push(entry);
  console.log(`  ${entry.result.padEnd(8)} ${name}${entry.note ? ` — ${entry.note}` : ''} (${entry.ms} ms)`);
  return entry.result === 'ok';
}
const must = (condition, message) => { if (!condition) throw new Error(message); };
/**
 * A sum of rupees as the admin prints it, grouped in thousands or in lakhs, and not as a part of a longer number:
 * 15,000 is not found in 115,000 nor in 15,000,000.
 */
const amount = (rupees) => {
  const digits = String(Math.round(rupees));
  const thousands = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const lakhs = digits.length > 3 ? `${digits.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',')},${digits.slice(-3)}` : digits;
  return new RegExp(`(?<!\\d)(?<!\\d,)(?:${thousands}|${lakhs})(?!,?\\d)`);
};
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

/** Who is signed in on the staff page now (an e-mail address), and the name the admin shows for each address. */
let who = null;
const names = {};
async function signIn(email, password) {
  await page.goto(`${base}/admin/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('#email').fill(email);
  await page.locator('#password').fill(password);
  await Promise.all([page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 20000 }), page.getByRole('button', { name: 'Sign in', exact: true }).click()]);
  await quiet();
  who = email;
  names[email] = ((await page.getByRole('button', { name: /Your account/ }).getAttribute('aria-label').catch(() => '')) || '').replace(/^Your account:\s*/, '').trim();
}
async function signOut() {
  await page.getByRole('button', { name: /Your account/ }).click();
  await Promise.all([page.waitForURL((url) => url.pathname.endsWith('/login'), { timeout: 20000 }), page.getByRole('menuitem', { name: 'Sign out' }).click()]);
  who = null;
}
/** The demo's people. Where a password is not given the owner, who holds every right, does that person's part. */
const people = {
  owner: { email: 'owner@woodex.test', password: process.env.WX_PW_OWNER },
  sales: { email: 'sales@woodex.test', password: process.env.WX_PW_SALES },
  manager: { email: 'manager@woodex.test', password: process.env.WX_PW_MANAGER },
  accounts: { email: 'accounts@woodex.test', password: process.env.WX_PW_ACCOUNTS },
};
const played = (role) => (people[role].password ? people[role] : people.owner);
/** Change who sits at the screen: sign the present person out and the wanted one in, unless they are the same. */
async function become(role) {
  const person = played(role);
  if (who !== person.email) {
    if (who) {
      await page.goto(`${base}/admin`, { waitUntil: 'domcontentloaded' });
      await quiet();
      await signOut();
    }
    await signIn(person.email, person.password);
  }
  return names[person.email] || person.email;
}
/**
 * The watch on text that is not saved (list line T-043, platform/resources/js/admin/unsaved.js): what it believes,
 * the question it asks, and a link of the menu at the side.
 */
const unsaved = () => page.evaluate(() => (window.wxUnsaved ? { standing: window.wxUnsaved.standing(), ...window.wxUnsaved.state() } : { standing: null }));
const question = page.locator('dialog[data-unsaved-dialog]');
const asking = () => question.evaluate((box) => box.open).catch(() => false);
async function questionIs(open, otherwise) {
  const until = Date.now() + 8000;
  while (Date.now() < until) {
    if ((await asking()) === open) return;
    await page.waitForTimeout(100);
  }
  throw new Error(`${otherwise} (the watch: ${JSON.stringify(await unsaved().catch(() => null))})`);
}
const menuLink = (target) => page.locator(`#wx-sidebar a[href$="${target}"]`).first();
const onPath = (wanted) => new URL(page.url()).pathname === wanted;
/** Digits only, so that "Rs 4,96,800" and "Rs 496,800" are the same amount. */
const figures = (text) => String(text).replace(/[^0-9]/g, '');
const flat = (text) => String(text).replace(/\s+/g, ' ').trim();

console.log(`WOODEX OS journeys against ${base}`);
let leadPath = null;
let quotePath = null;
let quoted = null;
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

  // ---- What is typed is not left behind without a question (list line T-043) ----
  const half = `Half a thought ${stamp}`;
  await step('typed text is not left behind without a question: "Stay" keeps it, "Leave without saving" goes', async () => {
    must(leadPath, 'no lead was created');
    await page.goto(base + leadPath, { waitUntil: 'domcontentloaded' });
    await quiet();
    must((await unsaved()).standing === 0, 'a page nobody typed on says it holds unsaved text, or the watch is not there');
    await page.getByLabel('New task').click();
    await page.keyboard.type(half);
    must((await unsaved()).standing === 1, 'typing into the task form left no mark');
    await menuLink('/admin/contacts').click();
    await questionIs(true, 'a menu link was pressed over typed text and nothing asked');
    must(onPath(leadPath), `the screen changed before the answer: ${page.url()}`);
    const words = flat(await question.innerText());
    must(/^Leave without saving\?/.test(words) && /not saved yet/.test(words), `the question reads "${words}"`);
    must(await page.locator('[data-unsaved-stay]').evaluate((button) => button === document.activeElement), '"Stay" does not have the focus');
    // A key that opens another screen from anywhere (N: a new lead) must not act behind the question.
    await page.keyboard.press('n');
    await page.waitForTimeout(400);
    must(onPath(leadPath) && (await asking()), 'a shortcut key acted behind the question');
    await page.locator('[data-unsaved-stay]').click();
    await questionIs(false, '"Stay" did not close the question');
    must(onPath(leadPath), `"Stay" left the screen: ${page.url()}`);
    must((await page.getByLabel('New task').inputValue()) === half, '"Stay" kept the screen and lost the text');
    must((await unsaved()).standing === 1, 'after "Stay" the text is no longer marked as not saved');
    // The watch takes the cursor out of the field before it asks (a field that is stored when it is left must be
    // left); "Stay" puts it back where the person was.
    must(await page.getByLabel('New task').evaluate((field) => field === document.activeElement), 'after "Stay" the cursor is not back in the field');
    await menuLink('/admin/contacts').click();
    await questionIs(true, 'the second press of the link did not ask');
    await Promise.all([page.waitForURL(/\/admin\/contacts(\?|$)/, { timeout: 20000 }), page.locator('[data-unsaved-leave]').click()]);
    await quiet();
    must(!(await asking()) && (await unsaved()).standing === 0, 'the question or the mark followed to the next screen');
    return `"${half}" kept by "Stay"; "Leave without saving" opened ${new URL(page.url()).pathname}`;
  });

  await step('an untouched form and a saved one let go without a question; a refused save does not', async () => {
    must(leadPath, 'no lead was created');
    const leaves = async (what) => {
      await Promise.all([page.waitForURL(/\/admin\/contacts(\?|$)/, { timeout: 20000 }), menuLink('/admin/contacts').click()]);
      await quiet();
      must(!(await asking()), `${what}: the question is on the screen`);
    };
    // Untouched: the task that was left behind in the step before is not there, and nothing asks.
    await page.goto(base + leadPath, { waitUntil: 'domcontentloaded' });
    await quiet();
    must((await page.getByLabel('New task').inputValue()) === '', 'the text that was left without saving is back');
    must((await page.getByText(half).count()) === 0, 'the task that was left without saving was saved');
    await leaves('an untouched form');
    // Saved: typed, added, and then left.
    await page.goto(base + leadPath, { waitUntil: 'domcontentloaded' });
    await quiet();
    const kept = `Ring the site ${stamp}`;
    await page.getByLabel('New task').click();
    await page.keyboard.type(kept);
    must((await unsaved()).standing === 1, 'typing into the task form left no mark');
    await page.getByRole('button', { name: 'Add task' }).click();
    await shown(page.getByText(kept), 15000, 'the task that was added');
    must((await unsaved()).standing === 0, 'the task was saved and its form is still marked as not saved');
    await leaves('a saved form');
    // Refused: a new lead without a name. The answer carries a complaint, and the mark must stay.
    await page.goto(`${base}/admin/leads?tab=list&new=1`, { waitUntil: 'domcontentloaded' });
    await quiet();
    await page.locator('#newMessage').click();
    await page.keyboard.type('A message, and no name.');
    await page.getByRole('button', { name: 'Create lead' }).click();
    await shown(page.locator('form[wire\\:submit="createLead"]').getByText(/name field is required/i), 15000, 'the complaint under the name');
    must((await unsaved()).standing === 1, 'a save that was refused took the mark away: leaving would not ask');
    return `left twice without a question; "${kept}" saved; a lead without a name refused and still marked`;
  });

  await step("closing or reloading the tab on a changed form raises the browser's own question", async () => {
    // The screen of the step before: "New lead" with a message typed and refused.
    must((await unsaved()).standing === 1, 'the form of the step before is not marked as not saved');
    questions.length = 0;
    leavingExpected = true;
    await page.reload({ waitUntil: 'domcontentloaded' }).finally(() => { leavingExpected = false; });
    await quiet();
    const asked = questions.filter((text) => text === BEFORE_LEAVING).length;
    must(asked === 1, `a reload over typed text raised ${asked} questions of the browser`);
    // The form came back empty. Nothing was typed since: a reload asks nothing.
    questions.length = 0;
    must((await unsaved()).standing === 0, 'a freshly loaded form is marked as not saved');
    await page.reload({ waitUntil: 'domcontentloaded' });
    await quiet();
    must(questions.length === 0, `a reload of an untouched form asked: ${questions.join(' | ')}`);
    return 'asked once over typed text, not at all over an untouched form';
  });

  await step('the Back button asks too, and a click beside a changed form does not close it', async () => {
    await page.goto(`${base}/admin/contacts`, { waitUntil: 'domcontentloaded' });
    await quiet();
    // A click beside the window of a form: with text in it the window stays, without it closes as before.
    await page.getByRole('button', { name: 'New contact' }).click();
    const name = page.locator('[id="form.name"]');
    await shown(name, 15000, 'the form "New contact"');
    await page.mouse.click(8, 8);
    // Waited for, not slept through: a browser that stands still for a few seconds (seen on a machine that draws
    // without a graphics card, a second after a page was loaded) answers the click late, and that is no fault.
    await name.waitFor({ state: 'detached', timeout: 8000 }).catch(() => {});
    await quiet();
    must((await name.count()) === 0, 'a click beside an untouched form no longer closes it');
    await page.getByRole('button', { name: 'New contact' }).click();
    await shown(name, 15000, 'the form "New contact", opened again');
    await name.click();
    await page.keyboard.type(`Beside ${stamp}`);
    await page.mouse.click(8, 8);
    await page.waitForTimeout(600);
    await quiet();
    must((await name.inputValue().catch(() => null)) === `Beside ${stamp}`, 'a click beside the form closed it and the typed text is gone');
    await page.getByRole('button', { name: 'Cancel' }).click();
    await page.waitForTimeout(300);
    await quiet();
    must((await name.count()) === 0 && (await unsaved()).standing === 0, '"Cancel" did not close the form, or left its mark');

    // The Back button: the address has already changed when the admin hears of it, and is put back on "Stay".
    if (!(await page.evaluate(() => 'navigation' in window))) return { leftOut: 'this browser keeps no record of where a move in its history started; Back is let through' };
    await Promise.all([page.waitForURL(/\/admin\/leads(\?|$)/, { timeout: 20000 }), menuLink('/admin/leads').click()]);
    await quiet();
    const leads = page.url();
    await page.getByRole('button', { name: 'New lead' }).first().click();
    await shown(page.locator('#newName'), 15000, 'the form "New lead"');
    await page.locator('#newName').click();
    await page.keyboard.type(`Back ${stamp}`);
    await page.goBack({ waitUntil: 'commit' }).catch(() => {});
    await questionIs(true, 'Back was pressed over typed text and nothing asked');
    // Escape answers "Stay", and must not reach the form behind, which Escape closes.
    await page.keyboard.press('Escape');
    await questionIs(false, 'Escape did not close the question');
    await page.waitForFunction((wanted) => window.location.href === wanted, leads, { timeout: 8000 }).catch(() => {});
    must(page.url() === leads, `"Stay" did not put the address back: ${page.url()} instead of ${leads}`);
    must((await page.locator('#newName').inputValue().catch(() => null)) === `Back ${stamp}`, 'Escape closed the form behind the question, or the text is gone');
    // A second Back asks again; "Leave without saving" shows the page the address names.
    await page.goBack({ waitUntil: 'commit' }).catch(() => {});
    await questionIs(true, 'the second Back did not ask');
    await page.locator('[data-unsaved-leave]').click();
    await shown(page.getByRole('heading', { level: 1, name: 'Contacts' }), 20000, 'the contacts screen after "Leave without saving"');
    await quiet();
    must(/\/admin\/contacts(\?|$)/.test(page.url()) && (await page.locator('#newName').count()) === 0, `after leaving by Back the screen is ${page.url()}`);
    return 'a click beside: an empty form closes, a changed one stays; Back: Escape keeps the form and its text, "Leave without saving" shows the page before';
  });

  await step('the same on a phone, and with the keyboard only', async () => {
    must(leadPath, 'no lead was created');
    const desk = page.viewportSize();
    await page.setViewportSize({ width: 390, height: 844 });
    try {
      await page.goto(base + leadPath, { waitUntil: 'domcontentloaded' });
      await quiet();
      await page.getByLabel('New task').click();
      await page.keyboard.type(half);
      // The menu is a drawer here. A finger opens it and taps the link.
      await page.getByRole('button', { name: 'Open or close the menu' }).click();
      await shown(menuLink('/admin/contacts'), 8000, 'the link in the menu drawer');
      await menuLink('/admin/contacts').click();
      await questionIs(true, 'on a phone a menu link was pressed over typed text and nothing asked');
      const box = await question.boundingBox();
      must(box && box.x >= 0 && box.x + box.width <= 390 && box.y >= 0 && box.y + box.height <= 844, `the question does not fit a phone: ${JSON.stringify(box)}`);
      for (const button of ['[data-unsaved-stay]', '[data-unsaved-leave]']) {
        const size = await page.locator(button).boundingBox();
        must(size && size.height >= 40 && size.x >= box.x && size.x + size.width <= box.x + box.width, `${button} is cut off or too small for a finger: ${JSON.stringify(size)}`);
      }
      // Keyboard only from here. Tab never reaches the page behind: the focus is on one of the two buttons, or
      // with the browser's own bar (which a page sees as "nowhere").
      const focus = () => page.evaluate(() => {
        const at = document.activeElement;
        return at?.hasAttribute('data-unsaved-stay') ? 'stay' : at?.hasAttribute('data-unsaved-leave') ? 'leave' : at === document.body || at === null ? 'outside the page' : `the page behind: ${at.tagName.toLowerCase()} "${(at.innerText || at.getAttribute('aria-label') || '').trim().slice(0, 30)}"`;
      });
      const visited = [await focus()];
      for (let press = 0; press < 6; press += 1) {
        await page.keyboard.press('Tab');
        visited.push(await focus());
      }
      must(visited.every((place) => ['stay', 'leave', 'outside the page'].includes(place)), `Tab reached ${visited.join(' → ')}`);
      must(visited.includes('stay') && visited.includes('leave'), `Tab did not reach both buttons: ${visited.join(' → ')}`);
      // Enter on "Stay" keeps the screen and the text.
      await page.locator('[data-unsaved-stay]').focus();
      await page.keyboard.press('Enter');
      await questionIs(false, 'Enter on "Stay" did not close the question');
      must(onPath(leadPath) && (await page.getByLabel('New task').inputValue()) === half, 'Enter on "Stay" lost the screen or the text');
      // The link again, by the keyboard: Enter on it asks, Escape stays, Enter on "Leave without saving" goes.
      await menuLink('/admin/contacts').focus();
      await page.keyboard.press('Enter');
      await questionIs(true, 'Enter on a menu link over typed text did not ask');
      await page.keyboard.press('Escape');
      await questionIs(false, 'Escape did not close the question');
      must(onPath(leadPath) && (await page.getByLabel('New task').inputValue()) === half, 'Escape lost the screen or the text');
      await menuLink('/admin/contacts').focus();
      await page.keyboard.press('Enter');
      await questionIs(true, 'the third press of the link did not ask');
      for (let press = 0; press < 4 && (await focus()) !== 'leave'; press += 1) await page.keyboard.press('Tab');
      must((await focus()) === 'leave', 'Tab did not reach "Leave without saving"');
      await Promise.all([page.waitForURL(/\/admin\/contacts(\?|$)/, { timeout: 20000 }), page.keyboard.press('Enter')]);
      await quiet();
      return `390 px: the question is ${Math.round(box.width)} × ${Math.round(box.height)} px and inside the screen; Tab went ${visited.join(' → ')}; Enter, Escape and Enter did the rest`;
    } finally {
      await page.setViewportSize(desk);
    }
  });

  await step('a notice written without names says what it says', async () => {
    // $this->dispatch('toast', 'Sending failed.', 'error') reaches the browser as a list. The admin used to show a
    // green "Saved" for it (defect D-038). Sent here the way Livewire sends it.
    const text = `Sending failed ${stamp}: the host refused.`;
    await page.evaluate((message) => window.dispatchEvent(new CustomEvent('toast', { detail: [message, 'error'] })), text);
    const notice = page.locator('.wx-toast-stack > div', { hasText: text });
    await shown(notice, 8000, 'the notice with its own words');
    must(/text-error-700/.test(await notice.first().getAttribute('class')), 'the notice of a failure is not drawn as a failure');
    must((await page.locator('.wx-toast-stack > div', { hasText: /^Saved$/ }).count()) === 0, 'the notice reads "Saved"');
    // The usual way still reads the same.
    await page.evaluate((message) => window.dispatchEvent(new CustomEvent('toast', { detail: { message: `${message} (named)`, type: 'warning' } })), text);
    await shown(page.locator('.wx-toast-stack > div.text-warning-700', { hasText: `${text} (named)` }), 8000, 'the notice written with names');
    return `"${text}" shown as a failure`;
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

    // A second line, chosen from the real catalogue by its code (master list T-086): the name, the code and the
    // price are copied onto the line as the catalogue has them now.
    const wanted = catalogue.items.find((item) => item.sku && item.price > 0);
    const add = page.getByRole('button', { name: 'Add a product' }).first();
    must((await add.count()) > 0, 'the quotation offers no "Add a product"');
    await add.click();
    const search = page.getByLabel('Search the catalogue by name or code');
    await shown(search, 15000, 'the search of the catalogue');
    await search.fill(wanted.sku);
    const choices = page.locator('[data-product-choice]');
    const until = Date.now() + 15000;
    while (Date.now() < until && (await choices.count()) !== 1) await page.waitForTimeout(250);
    if ((await choices.count()) === 0) return { leftOut: 'this workspace holds no catalogue' };
    must((await choices.count()) === 1, `the code ${wanted.sku} offered ${await choices.count()} choices, not one`);
    const choice = page.locator(`[data-product-choice="${wanted.sku}"]`);
    const offered = (await choice.innerText()).replace(/\s+/g, ' ');
    must(offered.includes(wanted.name), `the code ${wanted.sku} offered another product: ${offered.slice(0, 80)}`);
    must(amount(wanted.price).test(offered), `the choice does not show the catalogue's price ${wanted.price}: ${offered.slice(0, 120)}`);
    await choice.getByRole('button', { name: /^Add / }).click();
    const line = page.locator(`[data-line-product="${wanted.sku}"]`);
    await shown(line, 15000, 'the line of the chosen product');
    await page.getByRole('button', { name: 'Done', exact: true }).click();
    await quiet();
    const total = 37500 + wanted.price;
    await shown(page.getByText(amount(total)).first(), 15000, `the total with the product, ${total}`);

    await page.reload({ waitUntil: 'domcontentloaded' });
    await quiet();
    must(await page.getByText(/37,500/).first().isVisible(), 'the priced line is gone after a reload');
    must(await line.isVisible(), 'the line from the catalogue is gone after a reload');
    must(await page.getByText(amount(total)).first().isVisible(), 'the total with the product is gone after a reload');
    quoted = { sku: wanted.sku, name: wanted.name, price: wanted.price, total };
    return `3 × 12,500 = 37,500; ${wanted.sku} "${wanted.name}" from the catalogue at ${wanted.price}; total ${total}`;
  });

  await step('a figure typed into the quotation is kept when a menu link is pressed at once', async () => {
    if (!quoted) return { leftOut: 'no quotation was priced' };
    // Every field of the quotation is stored when it is left. A menu link pressed with the cursor still in the
    // field does not leave the field: Livewire stops the browser's own handling of that press, and the cursor stays
    // where it was. Before the watch on unsaved text (T-043) the screen went and the figure was lost without a
    // word. Now the watch leaves the field itself, waits for the save, and asks nothing.
    const rate = () => page.getByLabel('Rate in rupees').first();
    const seen = problems.length;
    // The question must not come up at all, not even for the moment the save takes: every opening is counted.
    await page.evaluate(() => {
      const box = document.querySelector('dialog[data-unsaved-dialog]');
      window.wxAskedHere = 0;
      new MutationObserver(() => { if (box.open) window.wxAskedHere += 1; }).observe(box, { attributes: true, attributeFilter: ['open'] });
    });
    await rate().click();
    await rate().fill('12600');
    await Promise.all([page.waitForURL(/\/admin\/contacts(\?|$)/, { timeout: 20000 }), menuLink('/admin/contacts').click()]);
    const flashed = await page.evaluate(() => window.wxAskedHere);
    must(!(await asking()), 'the question is on the screen: the figure was not saved in time');
    await quiet();
    // The answer of the save was still being drawn into the page when the first version left: the page that was
    // going away threw errors. Leaving waits for that now.
    must(problems.length === seen, `leaving threw on the page: ${[...new Set(problems.slice(seen))].join(' | ').slice(0, 200)}`);
    await page.goto(base + quotePath, { waitUntil: 'domcontentloaded' });
    await quiet();
    const kept = await rate().inputValue();
    // Back to the price of this journey, the usual way: type, leave the field, see the total. This comes before
    // the verdict, so that a fault found here does not also fail the steps that read the total afterwards.
    await rate().fill('12500'); await rate().blur(); await quiet();
    await shown(page.getByText(amount(quoted.total)).first(), 15000, `the total again, ${quoted.total}`);
    must(kept === '12600', `the figure is lost: the rate read ${kept}, not 12600`);
    must(flashed === 0, `the question came up ${flashed === 1 ? 'once' : `${flashed} times`} while the figure was being saved, and closed by itself`);
    must((await unsaved()).standing === 0, 'the quotation is stored and still marked as not saved');
    return '12600 typed and the link pressed at once: Contacts opened, the question never came up, and the quotation holds 12600; put back to 12,500';
  });

  await step('the quotation PDF', async () => {
    if (!quotePath) return { leftOut: 'no quotation was written' };
    const file = await ask(`${quotePath}/pdf`);
    must(file.status === 200 && file.type.startsWith('application/pdf') && file.starts === '%PDF-', `answered ${file.status} ${file.type}, starts "${file.starts}"`);
    return `${file.bytes} bytes`;
  });

  await step('the same PDF is drawn on the screen (the preview: a worker, a later script, the fonts)', async () => {
    // The preview draws the saved file with pdf.js: a script fetched when the button is pressed, a worker, the
    // document's own fonts. It is the part of the admin a content policy breaks first (master list T-068), and
    // when it breaks the page only says "could not be displayed".
    if (!quotePath) return { leftOut: 'no quotation was written' };
    await page.goto(`${base}${quotePath}`, { waitUntil: 'domcontentloaded' });
    await quiet();
    await page.getByRole('link', { name: /^(Preview PDF|PDF)$/ }).first().click();
    const dialog = page.getByRole('dialog', { name: 'Document preview' });
    await shown(dialog, 15000, 'the preview');
    const ready = dialog.getByText(/\d+ pages? ready · actual PDF/);
    const refused = dialog.getByRole('alert');
    await Promise.race([ready.waitFor({ timeout: 45000 }), refused.waitFor({ timeout: 45000 })]).catch(() => {});
    if ((await refused.count()) > 0) throw new Error(`the preview says: ${(await refused.first().innerText({ timeout: 2000 }).catch(() => '')).replace(/\s+/g, ' ').slice(0, 120)}`);
    if (!(await ready.isVisible())) throw new Error(`the preview never finished: "${(await dialog.locator('[role="status"]').first().innerText({ timeout: 2000 }).catch(() => '')).slice(0, 80)}"`);
    const drawn = await dialog.locator('canvas').evaluateAll((sheets) => sheets.map((sheet) => {
      const dots = sheet.getContext('2d').getImageData(0, 0, sheet.width, Math.min(sheet.height, 400)).data;
      let ink = 0;
      for (let at = 0; at < dots.length; at += 4) if (dots[at] < 200 || dots[at + 1] < 200 || dots[at + 2] < 200) ink += 1;
      return { width: sheet.width, height: sheet.height, ink };
    }));
    must(drawn.length > 0 && drawn.every((sheet) => sheet.width > 200 && sheet.height > 200), `no page was drawn (${JSON.stringify(drawn).slice(0, 120)})`);
    must(drawn[0].ink > 500, `the first page is blank (${drawn[0].ink} dark dots in its top part)`);
    const said = (await ready.innerText()).trim();

    // What the PDF says, read with the reader the preview has just loaded: the product chosen from the catalogue
    // is in it with its code and its price, and the total is the one the screen showed.
    let read = '';
    if (quoted) {
      const words = await page.evaluate(async (address) => {
        const built = await (await fetch('/build/manifest.json')).json();
        const reader = await import(`/build/${built['node_modules/pdfjs-dist/build/pdf.mjs'].file}`);
        const bytes = new Uint8Array(await (await fetch(address, { credentials: 'same-origin' })).arrayBuffer());
        const file = await reader.getDocument({ data: bytes, isEvalSupported: false }).promise;
        let text = '';
        for (let number = 1; number <= file.numPages; number += 1) {
          const content = await (await file.getPage(number)).getTextContent();
          text += `${content.items.map((item) => item.str).join(' ')}\n`;
        }
        return text;
      }, `${quotePath}/pdf`);
      const text = words.replace(/\s+/g, ' ');
      must(text.includes(quoted.sku), `the PDF does not name the code ${quoted.sku}`);
      must(amount(quoted.price).test(text), `the PDF does not show the product's price ${quoted.price}`);
      must(amount(quoted.total).test(text), `the PDF does not show the total ${quoted.total}`);
      read = `; the PDF names ${quoted.sku} at ${quoted.price} and the total ${quoted.total}`;
    }

    await page.keyboard.press('Escape');
    await dialog.waitFor({ state: 'detached', timeout: 10000 });
    return `${said}; first page ${drawn[0].width} × ${drawn[0].height}, drawn${read}`;
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
    const read = Date.now() - started;
    // The sheet is read and not imported. Leaving now loses it, so the browser asks first (list line T-043).
    questions.length = 0;
    leavingExpected = true;
    await page.goto(`${base}/admin`, { waitUntil: 'domcontentloaded' }).finally(() => { leavingExpected = false; });
    await quiet();
    must(questions.includes(BEFORE_LEAVING), 'a sheet that was read and not imported was left without a question');
    return `sent in ${uploaded} ms, read (1 row, 5 columns) after ${read} ms; leaving it without importing asks first`;
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
    // The number of the request (master list T-062): on the page, and the same one in the answer's header. With
    // it, whoever is asked for help finds the lines the application wrote to its log while answering.
    const number = ((await page.locator('[data-request-number]').first().textContent().catch(() => '')) || '').trim();
    must(/^[0-9A-Z]{4}-[0-9A-Z]{4}-[0-9A-Z]{4}$/.test(number), `the page shows no request number ("${number.slice(0, 40)}")`);
    must(response.headers()['x-request-id'] === number, `the page says ${number}, the answer's header says ${response.headers()['x-request-id']}`);
    return `${(await page.locator('h1').innerText()).trim()} · request number ${number}`;
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

  // ----------------------------------------------------------------------------------------------------------------
  // The sale (list line T-080): an enquiry becomes a paid invoice, each part pressed by the person who does it.
  // ----------------------------------------------------------------------------------------------------------------
  const buyer = `Sale Test ${stamp}`;
  const sale = { lead: null, code: null, contact: null, quote: null, number: null, invoice: null, invoiceNumber: null, reference: `TRX-${stamp}`, clientQuote: null, clientInvoice: null, receipt: null, held: null, approval: false };
  // The client's own browser: a phone, with no session of the admin.
  let clientPage = null;
  const clientBrowser = async () => {
    if (!clientPage) {
      const phone = await browser.newContext({ viewport: { width: 390, height: 844 } });
      clientPage = await phone.newPage();
      watch(clientPage, 'client\'s ');
    }
    return clientPage;
  };
  const timeline = () => page.locator('#activity-stream li');
  const open = async (address) => { await page.goto(base + address, { waitUntil: 'domcontentloaded' }); await quiet(); };
  /** The last question the admin asked must be the one this act deserves. */
  const asked = (pattern, what) => must(questions.length > 0 && pattern.test(questions[questions.length - 1]), `${what} was done without the question it deserves`);

  await step('documents can be issued here: terms and a signer are in place', async () => {
    await become('owner');
    await open('/admin/settings?tab=sales');
    const notice = page.locator('[role="status"]', { hasText: 'Documents cannot be issued yet' });
    const points = async () => (await notice.count() ? (await notice.locator('li').allInnerTexts()).map(flat) : []);
    let open_ = await points();
    if (open_.length === 1 && /signatory/i.test(open_[0])) {
      // The one thing a person at the screen can close: name who signs.
      await page.getByRole('button', { name: 'Add signatory' }).click();
      await shown(page.getByLabel('Name', { exact: true }), 15000, 'the new signatory row');
      await page.getByLabel('Name', { exact: true }).last().fill('Journey Signer');
      await page.getByLabel('Title', { exact: true }).last().fill('Sample only');
      await page.getByRole('button', { name: 'Save sales rules' }).click();
      const until = Date.now() + 15000;
      while (Date.now() < until && (await notice.count())) await page.waitForTimeout(250);
      open_ = await points();
    }
    if (open_.length) {
      sale.held = open_.join(' / ');
      return { leftOut: `this site holds documents back: ${sale.held}` };
    }
    const signer = await page.getByLabel('Name', { exact: true }).first().inputValue().catch(() => '');
    must(signer.trim() !== '', 'no signatory is named, yet the screen raises no notice');
    return `signer "${signer}"`;
  });

  await step('an enquiry is taken and handed to a sales person, with the reason on record', async () => {
    const taker = await become('owner');
    await open('/admin');
    await page.getByRole('button', { name: 'Create new' }).click();
    await Promise.all([page.waitForURL(/\/admin\/leads\?/), page.locator('[data-quick-create="lead"]').click()]);
    await page.locator('#newName').fill(buyer);
    await page.locator('#newPhone').fill(`0300 6${stamp}`);
    await page.locator('#newMessage').fill('Twelve desks for a new office. Written by scripts/os-journeys.mjs.');
    await Promise.all([page.waitForURL(/\/admin\/leads\/\d+/, { timeout: 20000 }), page.getByRole('button', { name: 'Create lead' }).click()]);
    await quiet();
    sale.lead = new URL(page.url()).pathname;
    const owner = page.getByLabel('Lead owner');
    const holder = async () => owner.evaluate((field) => field.options[field.selectedIndex].text.trim());
    const first = await holder();
    const seller = names[played('sales').email] || taker;
    /** Hand the lead to somebody; taking it from another person asks for the reason, which goes on the timeline. */
    const hand = async (to, reason) => {
      const from = await holder();
      await owner.selectOption({ label: to });
      if (from === 'Unassigned') {
        await shown(timeline().filter({ hasText: `Assigned to ${to}` }).first(), 15000, `the hand-over to ${to} on the timeline`);
        return;
      }
      await shown(page.getByLabel('Reason'), 15000, 'the question why the lead changes hands');
      await page.getByLabel('Reason').fill(reason);
      await page.getByRole('button', { name: 'Reassign', exact: true }).click();
      // "Reassigned from A to B: the reason" — the entry is there only when the change has been saved.
      await shown(timeline().filter({ hasText: reason }).filter({ hasText: to }).first(), 15000, `the hand-over to ${to}, with its reason, on the timeline`);
    };
    // The assignment rule may already have given it to the sales person. The hand-over is pressed either way.
    if (first === seller && seller !== taker) await hand(taker, `Journey ${stamp}: taken back to check the hand-over`);
    if ((await holder()) !== seller) await hand(seller, `Journey ${stamp}: office furniture is theirs`);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await quiet();
    must((await holder()) === seller, `after a reload the lead belongs to ${await holder()}, not to ${seller}`);
    must(seller === taker || (await timeline().filter({ hasText: `Journey ${stamp}` }).count()) > 0, 'the reason for the hand-over is not on the timeline');
    const contact = await page.locator('a[href*="/admin/contacts/"]').first().getAttribute('href').catch(() => null);
    sale.contact = contact ? new URL(contact, base).pathname : null;
    return `first with ${first}; now with ${seller}`;
  });

  await step('the sales person finds the enquiry among their own leads', async () => {
    must(sale.lead, 'no enquiry was taken');
    const seller = await become('sales');
    await open('/admin/leads?owner=me');
    const mine = page.locator(`a[href$="${sale.lead}"]`).first();
    await shown(mine, 15000, 'the enquiry in "My leads"');
    sale.code = flat(await mine.innerText());
    await Promise.all([page.waitForURL((url) => url.pathname === sale.lead, { timeout: 20000 }), mine.click()]);
    await quiet();
    must(await page.getByRole('heading', { name: buyer }).first().isVisible(), 'the page that opened is not the enquiry');
    return `${sale.code} is with ${seller}`;
  });

  await step('a quotation above the discount limit cannot be sent: approval is asked for', async () => {
    must(sale.lead, 'no enquiry was taken');
    await become('sales');
    await open(sale.lead);
    await Promise.all([page.waitForURL(/\/admin\/quotes\/new/, { timeout: 20000 }), page.getByRole('link', { name: 'New quotation' }).first().click()]);
    await quiet();
    await page.getByLabel('Title (optional)').fill('Twelve desks');
    await page.getByRole('button', { name: /Blank quotation/ }).click();
    await shown(page.locator('button.ring-1', { hasText: 'Blank quotation' }), 15000, 'the chosen blank template');
    await quiet();
    const form = new URL(page.url()).pathname;
    await Promise.all([page.waitForURL((url) => url.pathname !== form, { timeout: 30000 }), page.getByRole('button', { name: 'Create draft quotation' }).click()]);
    await quiet();
    sale.quote = new URL(page.url()).pathname;
    sale.number = (flat(await page.locator('h1').first().innerText()).match(/[A-Z]{2,4}-\d+/) || [''])[0];
    const name = page.locator('input[id^="line-"][id$="-product"]').first();
    await name.fill('Journey desk'); await name.blur(); await quiet();
    const quantity = page.getByLabel('Quantity').first();
    await quantity.fill('12'); await quantity.blur(); await quiet();
    const rate = page.getByLabel('Rate in rupees').first();
    await rate.fill('45000'); await rate.blur(); await quiet();
    await page.getByLabel('Discount type', { exact: true }).selectOption('percent');
    await quiet();
    const discount = page.getByLabel('Discount value', { exact: true });
    await discount.fill('8'); await discount.blur(); await quiet();
    await shown(page.getByText(/4,?96,800/), 15000, 'the total after the discount (496,800)');
    await page.getByRole('button', { name: 'Send', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await shown(dialog, 15000, 'the Send dialog');
    await quiet();
    const said = flat(await dialog.innerText());
    if (/Fix these first/.test(said)) {
      // The draft stays a draft: the dialog lists what the owner has to close first, and offers no way round it.
      must((await dialog.getByRole('button', { name: /Send now|Request approval/ }).count()) === 0, 'the dialog lists what is missing and still offers to send');
      sale.held = sale.held || said.replace(/^.*Fix these first:?\s*/, '').slice(0, 220);
      await page.keyboard.press('Escape');
      return { leftOut: `nothing can be sent here: ${sale.held}` };
    }
    if (!/Approval needed before sending/.test(said)) {
      // The person at the screen may give this discount themself (the owner, when no sales password was given).
      await page.keyboard.press('Escape');
      return { leftOut: `${names[who] || who} may give 8% without asking${process.env.WX_PW_SALES ? '' : ' (WX_PW_SALES is not set)'}` };
    }
    must((await dialog.getByRole('button', { name: 'Send now' }).count()) === 0, 'the dialog offers "Send now" although approval is needed');
    const limit = (said.match(/Discount 8% is above the [\d.]+% limit for your role/) || ['the limit is not named'])[0];
    await dialog.getByLabel('Note for the approver (optional)').fill(`Journey ${stamp}: repeat client, twelve desks.`);
    await dialog.getByRole('button', { name: 'Request approval' }).click();
    await shown(page.getByText(/Waiting for approval/), 15000, 'the banner "Waiting for approval"');
    sale.approval = true;
    return `${sale.number}: 12 × 45,000 less 8% = 496,800. ${limit}`;
  });

  await step('the approver finds the request in the queue and approves it', async () => {
    if (!sale.approval) return { leftOut: 'no approval was asked for' };
    const approver = await become('manager');
    await open('/admin/approvals');
    const request = page.locator('[wire\\:key^="approval-"]', { hasText: sale.number });
    await shown(request, 15000, `the request for ${sale.number} in the queue`);
    must(flat(await request.innerText()).includes(`Journey ${stamp}`), 'the note for the approver is not shown with the request');
    await request.getByRole('button', { name: 'Approve' }).click();
    await shown(page.locator('[wire\\:key^="decided-"]', { hasText: sale.number }), 15000, 'the decision in the list of decisions');
    must((await request.count()) === 0, 'the request is still waiting after the approval');
    return `approved by ${approver}`;
  });

  await step('the approved quotation is sent: frozen, with its PDF', async () => {
    if (!sale.quote) return { leftOut: 'no quotation was written' };
    if (sale.held) return { leftOut: `nothing can be sent here: ${sale.held}` };
    await become('sales');
    await open(sale.quote);
    if (sale.approval) await shown(page.getByText(/ready to send/), 15000, 'the banner "Approved … ready to send"');
    await page.getByRole('button', { name: 'Send', exact: true }).click();
    const dialog = page.getByRole('dialog');
    await shown(dialog.getByRole('button', { name: 'Send now' }), 15000, 'the button "Send now"');
    await dialog.getByRole('button', { name: 'Send now' }).click();
    // What the admin hands over next depends on the site: the client's own page, or the PDF to pass on.
    await shown(page.getByRole('dialog').getByRole('link', { name: /Open client page|Download the PDF/ }).first(), 30000, 'what to give the client');
    const after = page.getByRole('dialog');
    const link = after.getByRole('link', { name: 'Open client page' });
    sale.clientQuote = (await link.count()) ? new URL(await link.first().getAttribute('href'), base).href : null;
    must(sale.clientQuote || /Give .* to the client/.test(flat(await after.innerText())), 'the dialog names neither a client page nor the PDF to hand over');
    await after.getByRole('button', { name: 'Close' }).first().click();
    await open(sale.quote);
    must(/\bSent\b/.test(flat(await page.locator('main').innerText()).slice(0, 400)), 'the quotation does not say "Sent"');
    const file = await ask(`${sale.quote}/pdf`);
    must(file.status === 200 && file.type.startsWith('application/pdf') && file.starts === '%PDF-', `the PDF answered ${file.status} ${file.type}`);
    return `${sale.number} sent; PDF ${file.bytes} bytes; the client gets ${sale.clientQuote ? 'a page of their own' : 'the PDF (the client pages are off here)'}`;
  });

  await step('the client says yes', async () => {
    if (!sale.quote) return { leftOut: 'no quotation was written' };
    if (sale.held) return { leftOut: `nothing was sent here: ${sale.held}` };
    let how;
    if (sale.clientQuote) {
      // On the client's own page, on a phone, without any sign-in.
      const theirs = await clientBrowser();
      const response = await theirs.goto(sale.clientQuote, { waitUntil: 'domcontentloaded' });
      must(response.status() === 200, `the client's page answered ${response.status()}`);
      must(figures(await theirs.locator('body').innerText()).includes('496800'), 'the client\'s page does not show the total');
      await theirs.getByLabel('Full name').fill(buyer);
      await theirs.getByLabel(/I accept this quotation/).check();
      await Promise.all([theirs.waitForLoadState('domcontentloaded'), theirs.getByRole('button', { name: 'Accept quotation' }).click()]);
      await theirs.getByText(/Your acceptance is confirmed/).first().waitFor({ timeout: 20000 });
      must((await theirs.getByRole('button', { name: 'Accept quotation' }).count()) === 0, 'the client\'s page still offers "Accept" after the acceptance');
      how = 'on their own page, on a phone, without a sign-in';
    } else {
      // The client pages are off: the client said yes on the telephone and the sales person writes it down.
      await become('sales');
      await open(sale.quote);
      await page.getByRole('button', { name: 'Client accepted' }).click();
      const dialog = page.getByRole('dialog');
      await shown(dialog.getByLabel('Who accepted'), 15000, 'the dialog for the client\'s answer');
      await dialog.getByLabel('Who accepted').fill(buyer);
      await dialog.getByLabel('How', { exact: true }).selectOption('phone');
      await dialog.getByLabel('Note (optional)').fill(`Journey ${stamp}: confirmed on a call.`);
      await dialog.getByRole('button', { name: 'Record acceptance' }).click();
      // The screen answers in place; leaving it before the answer would show the old state.
      await shown(page.locator('[role="status"]').filter({ hasText: 'recorded by' }).filter({ hasText: buyer }).first(), 20000, 'the banner that names who wrote the answer down');
      how = `by telephone, written down by ${names[who] || who}`;
    }
    await become('sales');
    await open(sale.quote);
    await shown(page.locator('[role="status"]').filter({ hasText: 'Accepted' }).filter({ hasText: buyer }).first(), 20000, 'the banner that says who accepted and how');
    must((await page.getByRole('button', { name: 'Client accepted' }).count()) === 0, 'the quotation still asks for the client\'s answer');
    return how;
  });

  await step('the lead is won and the contact has become a client', async () => {
    if (!sale.quote || sale.held) return { leftOut: 'nothing was accepted' };
    await become('sales');
    await open(sale.lead);
    await shown(timeline().filter({ hasText: /Stage: .+ → Won/ }).first(), 15000, 'the move to "Won" on the timeline');
    must((await timeline().filter({ hasText: `accepted ${sale.number}` }).count()) > 0 || (await timeline().filter({ hasText: /accepted/i }).count()) > 0, 'the acceptance is not on the timeline');
    if (!sale.contact) {
      const contact = await page.locator('a[href*="/admin/contacts/"]').first().getAttribute('href').catch(() => null);
      sale.contact = contact ? new URL(contact, base).pathname : null;
    }
    must(sale.contact, 'the lead links no contact');
    await open(sale.contact);
    await shown(page.getByText(/Client since/).first(), 15000, '"Client since" on the contact');
    return `${sale.code || sale.lead}: Won; ${buyer} is a client`;
  });

  await step('accounts bill the sale in full and issue the invoice; the ledger has it', async () => {
    if (!sale.quote || sale.held) return { leftOut: 'nothing was accepted' };
    const accountant = await become('accounts');
    await open(sale.quote);
    await Promise.all([page.waitForURL(/\/admin\/invoices\/\d+/, { timeout: 30000 }), page.getByRole('button', { name: 'Create full invoice' }).click()]);
    asked(/full Furniture invoice/, 'creating the invoice');
    await quiet();
    sale.invoice = new URL(page.url()).pathname;
    sale.invoiceNumber = flat(await page.locator('h1').first().innerText());
    const draft = flat(await page.locator('main').innerText());
    must(/\bDraft\b/.test(draft) && figures(draft).includes('496800'), 'the invoice is not a draft of 496,800');
    await page.getByRole('button', { name: 'Issue invoice' }).click();
    await shown(page.getByRole('heading', { name: 'Ledger' }), 20000, 'the ledger entry of the issued invoice');
    asked(/Issue this invoice/, 'issuing the invoice');
    const issued = flat(await page.locator('main').innerText());
    must(/STATUS\s+Issued|\bIssued\b/i.test(issued), 'the invoice does not say "Issued"');
    const entry = (issued.match(/JE-\d{4}-\d+/) || [''])[0];
    must(entry !== '', 'no journal entry is shown for the issued invoice');
    must((await page.getByRole('button', { name: 'Issue invoice' }).count()) === 0, 'an issued invoice can be issued again');
    const link = page.locator('main').getByRole('link', { name: 'Client link' });
    sale.clientInvoice = (await link.count()) ? new URL(await link.first().getAttribute('href'), base).href : null;
    const file = await ask(`${sale.invoice}/pdf`);
    must(file.status === 200 && file.type.startsWith('application/pdf') && file.starts === '%PDF-', `the invoice PDF answered ${file.status} ${file.type}`);
    return `${sale.invoiceNumber} issued by ${accountant}; ${entry}; PDF ${file.bytes} bytes`;
  });

  await step('the advance is paid and confirmed against the invoice', async () => {
    if (!sale.invoice) return { leftOut: 'no invoice was issued' };
    let how;
    if (sale.clientInvoice) {
      // The client reports the transfer on the invoice's own page and attaches the bank slip.
      const theirs = await clientBrowser();
      const response = await theirs.goto(sale.clientInvoice, { waitUntil: 'domcontentloaded' });
      must(response.status() === 200, `the client's invoice page answered ${response.status()}`);
      const shownThere = await theirs.locator('body').innerText();
      must(/Discount/.test(shownThere) && figures(shownThere).includes('43200'), 'the client\'s invoice page does not explain the discount between the lines and the total');
      await theirs.getByLabel('Amount paid (PKR)').fill('372600');
      await theirs.getByLabel('How you paid').selectOption('bank_transfer');
      await theirs.getByLabel('Reference / transaction id (optional)').fill(sale.reference);
      await theirs.getByLabel(/Screenshot or bank slip/).setInputFiles({ name: 'slip.png', mimeType: 'image/png', buffer: Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', 'base64') });
      await Promise.all([theirs.waitForLoadState('domcontentloaded'), theirs.getByRole('button', { name: 'Send payment details' }).click()]);
      await theirs.getByText(/we have your payment details/).first().waitFor({ timeout: 20000 });
      const accountant = await become('accounts');
      await open('/admin/payments');
      const claim = page.locator('tbody tr', { hasText: sale.reference });
      await shown(claim, 15000, 'the reported payment at Payments');
      must(/Pending Verification/.test(flat(await claim.innerText())), 'the reported payment is not waiting for accounts');
      const slip = await claim.getByRole('link', { name: 'Proof' }).getAttribute('href');
      const proof = await ask(new URL(slip, base).pathname);
      must(proof.status === 200 && proof.type.startsWith('image/png'), `the slip answered ${proof.status} ${proof.type}`);
      await claim.getByRole('button', { name: 'Confirm', exact: true }).click();
      await shown(claim.getByText('Verified'), 20000, 'the payment as "Verified"');
      asked(/Confirm this payment/, 'confirming the payment');
      how = `reported by the client with a slip, confirmed by ${accountant}`;
    } else {
      // The client pages are off: accounts take the payment on the invoice in one act.
      const accountant = await become('accounts');
      await open(sale.invoice);
      await page.getByLabel('Amount (Rs)').fill('372600');
      await page.getByLabel('Method').selectOption('bank_transfer');
      await page.getByLabel('Reference (optional)').fill(sale.reference);
      await page.getByRole('button', { name: 'Collect payment' }).click();
      await shown(page.getByText(sale.reference).first(), 20000, 'the payment on the invoice');
      how = `taken by ${accountant} on the invoice`;
    }
    await become('accounts');
    await open(sale.invoice);
    const after = flat(await page.locator('main').innerText());
    must(/Partially Paid/.test(after), 'the invoice does not say "Partially Paid"');
    must(/PAID\s+Rs\s+3,?72,600/i.test(after) && /BALANCE\s+Rs\s+1,?24,200/i.test(after), 'paid 372,600 and balance 124,200 are not what the invoice shows');
    return `372,600 of 496,800 ${how}; balance 124,200`;
  });

  await step('the receipt is a numbered PDF', async () => {
    if (!sale.invoice) return { leftOut: 'no invoice was issued' };
    await become('accounts');
    await open('/admin/payments');
    const row = page.locator('tbody tr', { hasText: sale.reference });
    await shown(row, 15000, 'the payment at Payments');
    sale.receipt = (flat(await row.innerText()).match(/RC-\d{4}-\d+/) || [''])[0];
    must(sale.receipt !== '', 'the payment carries no receipt number');
    must(flat(await row.innerText()).includes(sale.invoiceNumber), 'the payment is not set against the invoice');
    const [download] = await Promise.all([page.waitForEvent('download', { timeout: 30000 }), row.getByRole('button', { name: 'Receipt', exact: true }).click()]);
    const bytes = readFileSync(await download.path());
    must(download.suggestedFilename() === `receipt-${sale.receipt}.pdf`, `the file is called ${download.suggestedFilename()}`);
    must(bytes.subarray(0, 5).toString() === '%PDF-', 'the receipt is not a PDF');
    return `${download.suggestedFilename()}, ${bytes.length} bytes, against ${sale.invoiceNumber}`;
  });

  await step('the sales person sees on the lead that the invoice went out and the money came in', async () => {
    if (!sale.receipt) return { leftOut: 'no payment was confirmed' };
    const seller = await become('sales');
    await open(sale.lead);
    await shown(timeline().filter({ hasText: `Invoice ${sale.invoiceNumber} issued` }).first(), 15000, 'the invoice on the timeline');
    await shown(timeline().filter({ hasText: `Payment received: receipt ${sale.receipt}` }).first(), 15000, 'the payment on the timeline');
    const entries = (await timeline().allInnerTexts()).map(flat).join(' ¦ ');
    must(!figures(entries).includes('372600') && !figures(entries).includes('496800'), 'the timeline shows amounts to everybody who may open the lead');
    await open('/admin');
    await signOut();
    return `${seller} reads: invoice issued, payment received (${sale.receipt}), no amounts`;
  });
}

// The workspace as each person wants it, and the team's way in (list line T-056).
{
  const saved = () => shown(page.locator('.wx-toast-stack > div', { hasText: 'Your preferences are saved.' }).last(), 8000, 'the notice of the save');
  const look = (where) => where.evaluate(() => ({ dark: document.documentElement.classList.contains('dark'), keys: document.documentElement.dataset.shortcuts, kept: localStorage.getItem('wx.theme') }));

  await step('preferences are kept with the person: the appearance, the single keys, the screen after sign-in', async () => {
    await become('owner');
    await page.goto(`${base}/admin/system/preferences`, { waitUntil: 'domcontentloaded' });
    await quiet();
    must((await look(page)).keys === 'on', 'single keys are off before anybody chose so');
    await page.getByLabel('Dark', { exact: true }).check();
    await page.getByLabel(/Single keys act as shortcuts/).uncheck();
    await page.locator('#start').selectOption('leads');
    must((await unsaved()).standing === 1, 'choices that are not saved leave no mark');
    await page.getByRole('button', { name: 'Save preferences' }).click();
    await saved();
    const here = await look(page);
    must(here.dark && here.kept === 'dark', `the page did not turn dark at once (${JSON.stringify(here)})`);
    must(here.keys === 'off', 'single keys are still on in this page');
    must((await unsaved()).standing === 0, 'saved preferences are still marked as not saved');
    // N opens "New lead" from anywhere. Switched off, it does nothing.
    await page.goto(`${base}/admin`, { waitUntil: 'domcontentloaded' });
    await quiet();
    await page.keyboard.press('n');
    await page.waitForTimeout(700);
    must(onPath('/admin'), `N still acts with single keys switched off: ${page.url()}`);

    // Another browser, never switched by hand: sign-in opens the chosen screen, and it is dark there too.
    const other = await browser.newContext({ viewport: { width: 1366, height: 900 } });
    const second = await other.newPage();
    watch(second, 'second browser: ');
    try {
      const owner = played('owner');
      await second.goto(`${base}/admin/login`, { waitUntil: 'domcontentloaded' });
      await second.locator('#email').fill(owner.email);
      await second.locator('#password').fill(owner.password);
      await Promise.all([second.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 20000 }), second.getByRole('button', { name: 'Sign in', exact: true }).click()]);
      await second.waitForLoadState('networkidle', { timeout: 8000 }).catch(() => {});
      must(new URL(second.url()).pathname === '/admin/leads', `sign-in opened ${new URL(second.url()).pathname}, not the chosen screen`);
      const there = await look(second);
      must(there.dark && there.kept === null, `the appearance did not follow the person to a browser that was never switched (${JSON.stringify(there)})`);
      must(there.keys === 'off', 'single keys are on in the other browser');
    } finally {
      await other.close();
    }

    // Back to how it was: the next run of this journey starts as this one did.
    await page.goto(`${base}/admin/system/preferences`, { waitUntil: 'domcontentloaded' });
    await quiet();
    await page.getByLabel('As this device says', { exact: true }).check();
    await page.getByLabel(/Single keys act as shortcuts/).check();
    await page.locator('#start').selectOption('');
    await page.getByRole('button', { name: 'Save preferences' }).click();
    await saved();
    must((await look(page)).keys === 'on', 'single keys did not come back');
    // And with single keys on again, N acts: without this, "N does nothing" above would hold for a key that never did.
    await page.goto(`${base}/admin`, { waitUntil: 'domcontentloaded' });
    await quiet();
    await Promise.all([page.waitForURL(/\/admin\/leads\?/, { timeout: 15000 }), page.keyboard.press('n')]);
    await quiet();
    return 'dark at once, and in a second browser that was never switched; N does nothing with single keys off and opens "New lead" with them on; sign-in opened Leads; put back';
  });

  await step('team onboarding: an invited member stands as waiting, and the link is sent again', async () => {
    await become('owner');
    const mail = `joiner.${stamp}@woodex.test`;
    await page.goto(`${base}/admin/users`, { waitUntil: 'domcontentloaded' });
    await quiet();
    await page.getByRole('button', { name: 'Invite user' }).click();
    await shown(page.locator('#name'), 15000, 'the form "Invite a team member"');
    await page.locator('#name').fill(`Joiner ${stamp}`);
    await page.locator('#email').fill(mail);
    await page.getByRole('radio', { name: /^Sales\b(?! Manager)/ }).first().check();
    await page.getByRole('button', { name: 'Send invitation' }).click();
    await page.locator('#name').waitFor({ state: 'detached', timeout: 15000 });
    await quiet();

    await page.goto(`${base}/admin/system/onboarding`, { waitUntil: 'domcontentloaded' });
    await quiet();
    const row = page.locator(`tr[data-member="${mail}"]`);
    await shown(row, 15000, 'the invited member on Team onboarding');
    must((await row.getAttribute('data-through')) === 'no', 'a member who has never signed in stands as through');
    const words = flat(await row.innerText());
    must(/Link sent/.test(words) && /not used yet/.test(words) && /has not signed in/.test(words) && /Waiting to sign in/.test(words), `the row reads "${words.slice(0, 200)}"`);
    // The one button of the list is on the screen at this width, not pushed out of sight to the right.
    const fits = await row.evaluate((line) => { const frame = line.closest('table').parentElement; return { list: frame.scrollWidth, room: frame.clientWidth }; });
    must(fits.list <= fits.room, `the list is ${fits.list} px wide in a frame of ${fits.room} px: its button is pushed out of sight`);
    const me = page.locator(`tr[data-member="${played('owner').email}"]`);
    must((await me.getAttribute('data-through')) === 'yes', 'the owner, who is signed in, does not stand as through');
    const count = async (key) => Number(await page.locator(`[data-onboarding-count="${key}"]`).innerText());
    must((await count('not_signed_in')) >= 1 && (await count('through')) >= 1, 'the numbers above the list do not count the two');
    await row.getByRole('button', { name: `Send the link again to Joiner ${stamp}` }).click();
    await shown(page.locator('.wx-toast-stack > div', { hasText: `A new link to set a password was sent to ${mail}.` }), 8000, 'the notice that the link was sent');
    return `${mail}: link sent and not used, not signed in, waiting; the link was sent again; ${await count('through')} of ${await count('members')} members are through`;
  });
}

await browser.close();
const failed = steps.filter((entry) => entry.result === 'FAILED');
const serverErrors = problems.filter((problem) => problem.startsWith('HTTP 5'));
// What the browser refused under a page's Content-Security-Policy (master list T-068). A step can hold while a
// button beside it has stopped working, so a refusal anywhere on the way fails the run.
const blocked = [...new Set(problems.filter((problem) => /Content[ -]Security[ -]Policy/i.test(problem)))];
// Where the admin asked "leave this page?" over nothing (T-043): see the note at the dialog handler.
const askedWrongly = [...new Set(problems.filter((problem) => problem.includes(ASKED_WRONGLY)))];
console.log(`\n${steps.filter((entry) => entry.result === 'ok').length} of ${steps.length} steps held, ${failed.length} failed, ${steps.filter((entry) => entry.result === 'left out').length} left out.`);
if (problems.length) console.log(`Seen on the way (${problems.length}):\n  ${[...new Set(problems)].slice(0, 12).join('\n  ')}`);
if (out) writeFileSync(path.join(out, 'journeys.json'), JSON.stringify({ base, at: new Date().toISOString(), steps, problems }, null, 1));
if (blocked.length) console.log(`Blocked by the content policy (${blocked.length}):\n  ${blocked.slice(0, 8).join('\n  ')}`);
if (askedWrongly.length) console.log(`Asked without a reason (${askedWrongly.length}):\n  ${askedWrongly.slice(0, 8).join('\n  ')}`);
process.exit(failed.length > 0 || serverErrors.length > 0 || blocked.length > 0 || askedWrongly.length > 0 ? 1 : 0);

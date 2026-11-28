#!/usr/bin/env node
/**
 * WOODEX OS and the shop: a product made in the admin is what a visitor of the shop sees.
 *
 * The acceptance the owner named on 27 November 2026 (decision DL-049; master list T-105), pressed in a browser.
 * His seventeen steps, in his order:
 *
 *   1 sign in to the admin · 2 create a product · 3 upload a photograph · 4 its code, description and dimensions ·
 *   5 its category · 6 a price, or "Price on request" · 7 save · 8 it is in the database · 9 publish ·
 *   10 open the shop · 11 it stands in its category · 12 its page opens · 13 photograph, code, description and
 *   price are the ones entered · 14 edit it in the admin · 15 the shop shows the change · 16 withdraw it ·
 *   17 the shop no longer has it.
 *
 * And, since 27 November (master list T-191), what a visitor does with such a product, between his steps 13 and
 * 14: 13a no cart is offered for it, the quotation basket and WhatsApp are · 13b the visitor puts it into the
 * basket and sends the basket · 13c the owner finds the enquiry in the admin, with the product, its code and its
 * price on its line. Nothing the shop's page offered was answered with a refusal. For these three the admin takes
 * baskets (its part `quote_requests` is on) and knows the shop's server by the key the two share
 * (PUBLIC_LEADS_BRIDGE_KEY there, NATIVE_BRIDGE_KEY here), or has its robot check set up.
 *
 * Nothing here is a fixture: the product is typed into the admin's own form, the photograph is a file sent through
 * the admin's own upload, and the shop is the built shop asking its own server, which asks the admin.
 *
 * It needs two sites that run:
 *
 *   OS_BASE     the admin, default http://127.0.0.1:8195. Its products are handed out at /api/v1 (they are,
 *               unless PRODUCT_SYNC_ENABLED=0).
 *   SHOP_BASE   the shop, default http://127.0.0.1:4185. Its server was started with
 *               NATIVE_CATALOGUE_ORIGIN=<the admin> and NATIVE_PRODUCT_SYNC=1.
 *   WX_PW_OWNER, OS_OWNER_EMAIL (default owner@woodex.test)   who signs in: someone who may publish.
 *
 * And it can be given two commands, for a database it is allowed to look into and to stop:
 *
 *   OS_DB_QUERY_CMD   run with one SQL statement as its last argument, it prints the answer of the admin's
 *                     database. With it, steps 8, 9 and 16 are read in the database itself, not on a screen.
 *   OS_RESTART_CMD    stops what runs (the admin, and its database server where it has one) and starts it
 *                     again. Run after the product is saved: what is read afterwards was kept by the database
 *                     and by no process.
 *
 *   OS_PRICE_ON_REQUEST=1   the product has no price: it is published as "Price on request"
 *   OS_CATEGORY             the category it is filed under, by the end of its name (default "Executive Tables")
 *   OS_PICTURE              the photograph to upload (default apps/api/public/img/workstation-product.jpg)
 *   --out <dir>             journeys.json (the form scripts/os-notice.mjs reads) and pictures of the key screens
 *
 * It WRITES: it creates a product whose name begins "ACCEPTANCE", publishes it, changes it and withdraws it. The
 * product is left as a withdrawn draft. It sends one basket, which leaves one enquiry of a visitor whose name
 * begins "Acceptance Visitor". Point it at a rehearsal, or at a site whose owner asked for it.
 *
 * Exit 1 when a step fails or the browser reports a problem. No password is printed.
 * Browser: see scripts/lib/browser.mjs (E2E_CHROMIUM_PATH, E2E_CHROMIUM_ARGS, E2E_REQUIRED).
 */
import { execSync } from 'node:child_process';
import { existsSync, mkdirSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { launchBrowser } from './lib/browser.mjs';

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '..');
const base = (process.env.OS_BASE || 'http://127.0.0.1:8195').replace(/\/$/, '');
const shop = (process.env.SHOP_BASE || 'http://127.0.0.1:4185').replace(/\/$/, '');
const outIndex = process.argv.indexOf('--out');
const out = outIndex > -1 ? process.argv[outIndex + 1] : null;
if (out) mkdirSync(out, { recursive: true });

const password = process.env.WX_PW_OWNER;
if (!password) { console.error('WX_PW_OWNER is not set.'); process.exit(2); }
const email = process.env.OS_OWNER_EMAIL || 'owner@woodex.test';
const onRequest = process.env.OS_PRICE_ON_REQUEST === '1';
const categoryName = process.env.OS_CATEGORY || 'Executive Tables';
const picture = path.resolve(root, process.env.OS_PICTURE || 'apps/api/public/img/workstation-product.jpg');
if (!existsSync(picture)) { console.error(`The photograph ${picture} is not there.`); process.exit(2); }
const queryCommand = process.env.OS_DB_QUERY_CMD || '';
const restartCommand = process.env.OS_RESTART_CMD || '';

// One run, one product: the stamp keeps its address and its code from meeting an earlier run's.
const stamp = Date.now().toString(36);
const product = {
  name: `ACCEPTANCE Desk ${stamp.toUpperCase()}`,
  slug: `acceptance-desk-${stamp}`,
  code: `ACC-${stamp.toUpperCase()}`,
  short: 'An executive desk made for the acceptance of the product link.',
  description: `Typed into the admin on ${new Date().toISOString().slice(0, 10)}. A desk with a cable tray and a modesty panel.`,
  shipping: 'Delivered and installed by our own team. The date is agreed with you after the order.',
  warranty: 'The written warranty of this product is given with the quotation.',
  dimensions: '1800 × 900 × 750 mm',
  price: 185000,
  // What the editor changes in step 14.
  renamed: `ACCEPTANCE Desk ${stamp.toUpperCase()} II`,
  repriced: 172500,
};
// Who asks for it in step 13b: a name, an address and a telephone number made for this run, and written to
// nobody. The number is the run's own because the admin knows a returning visitor by it: two runs with one
// number are one person to it, and the second basket joins the first run's enquiry.
const shopper = {
  name: `Acceptance Visitor ${stamp.toUpperCase()}`,
  company: 'Acceptance Trading',
  email: `acceptance-${stamp}@example.test`,
  phone: `0300 00${String(Date.now() % 100000).padStart(5, '0')}`,
  about: 'Two desks for a new office',
  quantity: 2,
};
// Where the visitor stands. In front of a real shop a proxy of the host tells the shop's server each visitor's
// address, and the shop's server tells the admin, which counts its limits by it (defect D-082). A rehearsal has
// no proxy: every visitor would be 127.0.0.1, and two runs one after the other would be one visitor who never
// rests. So against a shop on this machine the browser says what the proxy would: an address of this run's own,
// from the range kept for examples. A shop anywhere else is told nothing; its own proxy knows better.
const visitorAddress = /^https?:\/\/(127\.0\.0\.1|localhost)(:\d+)?$/.test(shop) ? `198.51.100.${1 + (Date.now() % 254)}` : '';

const steps = [];
const problems = [];
const elsewhere = new Set();
const browser = await launchBrowser();
const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
const page = await context.newPage();
// A second sheet of the same signed-in owner, for the enquiries: the product's form stays open on the first.
const crm = await context.newPage();
const visitor = await (await browser.newContext({ viewport: { width: 1366, height: 900 }, ...(visitorAddress ? { extraHTTPHeaders: { 'X-Forwarded-For': visitorAddress } } : {}) })).newPage();
// An answer that is asked for as a refusal on purpose: its address, while that step runs.
let expected = null;
// Every answer of 400 or more that the shop's page was given, with what was asked: a button that leads to a
// refusal is what this journey exists to find (defect D-060).
const refusals = [];
// When the visitor's pages asked for something the shop's server hands on to the admin: the categories, the
// products and the photographs. The admin allows one visitor 120 such reads a minute; the end of the run says
// how many this one made, which is what a visitor who does not rest costs.
const reads = [];
const handedOn = /^\/api\/(v1\/(catalogue|media)(\/|$)|products(\/(?!overrides$)|$))/;
visitor.on('response', (response) => {
  const asked = new URL(response.url());
  if (!response.url().startsWith(shop)) return;
  if (handedOn.test(asked.pathname) && !response.fromServiceWorker()) reads.push(Date.now());
  if (response.status() < 400 || (expected && asked.pathname.includes(expected))) return;
  refusals.push(`${response.request().method()} ${asked.pathname} answered ${response.status()}`);
});
for (const [name, sheet] of [['admin', page], ['admin, enquiries', crm], ['shop', visitor]]) {
  sheet.on('pageerror', (error) => problems.push(`${name}, page error: ${error.message.slice(0, 200)}`));
  sheet.on('console', (message) => {
    if (message.type() !== 'error') return;
    const where = message.location()?.url || '';
    if (expected && where.includes(expected)) return;
    // A file of another server that did not arrive (a font, where this machine reaches no other server) is not a
    // fault of either site. It is counted apart and said at the end.
    if (where && !where.startsWith(base) && !where.startsWith(shop)) { elsewhere.add(new URL(where).host); return; }
    problems.push(`${name}, console: ${message.text().replace(/'nonce-[^']+'/g, "'nonce-…'").slice(0, 200)}${where ? ` (${new URL(where).pathname})` : ''}`);
  });
  sheet.on('response', (response) => { if (response.status() >= 500) problems.push(`${name}, HTTP ${response.status()} ${new URL(response.url()).pathname}`); });
}

async function step(name, run) {
  const started = Date.now();
  const entry = { name, result: 'ok', note: '', by: 'the owner' };
  try {
    entry.note = (await run()) || '';
  } catch (error) {
    entry.result = 'FAILED';
    entry.note = String(error.message).split('\n')[0].slice(0, 300);
    if (out) {
      await page.screenshot({ path: path.join(out, `failed-${steps.length + 1}-admin.jpg`), type: 'jpeg', quality: 60 }).catch(() => {});
      await visitor.screenshot({ path: path.join(out, `failed-${steps.length + 1}-shop.jpg`), type: 'jpeg', quality: 60 }).catch(() => {});
    }
  }
  entry.ms = Date.now() - started;
  steps.push(entry);
  console.log(`  ${entry.result.padEnd(8)} ${name}${entry.note ? ` — ${entry.note}` : ''} (${entry.ms} ms)`);
  return entry.result === 'ok';
}
const must = (condition, message) => { if (!condition) throw new Error(message); };
const quiet = (sheet = page) => sheet.waitForLoadState('networkidle', { timeout: 10000 }).catch(() => {});
const shot = async (sheet, name) => { if (out) await sheet.screenshot({ path: path.join(out, `${name}.jpg`), type: 'jpeg', quality: 72 }).catch(() => {}); };
/** Wait until something is on the screen: a Livewire answer changes the page without a navigation. */
async function shown(locator, what, timeout = 20000) {
  const until = Date.now() + timeout;
  while (Date.now() < until) {
    if (await locator.first().isVisible().catch(() => false)) return;
    await page.waitForTimeout(200);
  }
  throw new Error(`${what} did not appear within ${timeout / 1000} s`);
}
/** A field of the product form, by the name the form gives it (its id holds a dot). */
const field = (name) => page.locator(`[id="form.${name}"]`);
/** A sum of rupees as a page prints it, grouped in thousands or in lakhs, and not as part of a longer number. */
const amount = (rupees) => {
  const digits = String(Math.round(rupees));
  const thousands = digits.replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  const lakhs = digits.length > 3 ? `${digits.slice(0, -3).replace(/\B(?=(\d{2})+(?!\d))/g, ',')},${digits.slice(-3)}` : digits;
  return new RegExp(`(?<!\\d)(?<!\\d,)(?:${thousands}|${lakhs})(?!,?\\d)`);
};
/** The admin's database, asked one thing. Null when no command was given: the step then says what it could not read. */
function database(sql) {
  if (!queryCommand) return null;
  return execSync(`${queryCommand} ${JSON.stringify(sql)}`, { encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'], timeout: 30000 }).trim();
}
const row = () => database(`SELECT id, slug, version, COALESCE(published_revision_id, 'none'), (SELECT COUNT(*) FROM commerce_product_revisions r WHERE r.product_id = p.id), (SELECT COUNT(*) FROM commerce_product_media m WHERE m.product_id = p.id) FROM commerce_products p WHERE slug = '${product.slug}'`);

async function signIn() {
  await page.goto(`${base}/admin/login`, { waitUntil: 'domcontentloaded' });
  await page.locator('#email').fill(email);
  await page.locator('#password').fill(password);
  await Promise.all([page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30000 }), page.getByRole('button', { name: 'Sign in', exact: true }).click()]);
  await quiet();
}
/**
 * Something is pressed that asks the admin's server to run one action of the screen, and its answer is waited
 * for. A notice of an earlier action may still be on the screen, so the answer itself is what is waited for; and
 * what the screen refuses is said in the failure.
 */
async function called(action, press, timeout = 45000) {
  const answered = page.waitForResponse((response) => response.request().method() === 'POST'
    && new URL(response.url()).pathname.includes('/livewire') && (response.request().postData() || '').includes(`"method":"${action}"`), { timeout });
  await press();
  const response = await answered;
  must(response.status() === 200, `the admin answered ${response.status()} to "${action}"`);
  await quiet();
  // The answer has arrived; the screen draws it a moment later, and what follows reads the screen.
  await page.waitForTimeout(150);
  const refused = page.locator('[role="alert"]', { hasText: 'Please correct these issues' });
  if (await refused.isVisible().catch(() => false)) throw new Error(`the admin refused: ${(await refused.innerText()).replace(/\s+/g, ' ').slice(0, 220)}`);
}
/**
 * A field that the admin reads as it is typed (the name, the price, the texts, a tick): the value is given and
 * the admin's answer to it is waited for, so that what the next line reads is what the admin made of it.
 *
 * The answer to the request that carried this field, by the field's name, and no other answer of the admin that
 * happened to arrive first: every screen of the admin asks for its notifications twice a minute, and since the
 * journey grew by three steps that question fell into step 14, where its answer was taken for the price's. (A
 * request names what it changes, as "form.price"; the rest of its body is the screen's state, written with its
 * quotes escaped, so the name is found only where it is the change.)
 */
async function given(act, model) {
  const answered = page.waitForResponse((response) => response.request().method() === 'POST' && new URL(response.url()).pathname.includes('/livewire')
    && (response.request().postData() || '').includes(`"${model}"`), { timeout: 20000 });
  await act();
  await answered;
  await page.waitForTimeout(150);
}
const saveDraft = () => called('saveDraft', () => page.locator('form button[type="submit"]', { hasText: 'Save draft' }).click());
/** The saved product is opened as its preview by the person who will publish it, and then published. */
async function previewAndPublish() {
  await called('openPreview', () => page.getByRole('button', { name: 'Preview saved product' }).first().click());
  const frame = page.locator('iframe[title="Saved product preview"]');
  await shown(frame, 'the preview of the saved product');
  // The preview is a page of its own, asked for by this person's browser: that asking is what publication checks.
  const inside = page.frameLocator('iframe[title="Saved product preview"]');
  await inside.locator('h1').first().waitFor({ timeout: 20000 });
  const previewed = (await inside.locator('body').innerText()).replace(/\s+/g, ' ');
  await page.keyboard.press('Escape');
  await frame.waitFor({ state: 'detached', timeout: 15000 });
  await quiet();
  await page.locator('input[type="checkbox"][wire\\:model="confirmPublish"]').check();
  await called('publish', () => page.getByRole('button', { name: 'Publish this product' }).click());
  await shown(page.getByRole('button', { name: 'Withdraw publication' }), 'the button that withdraws a publication, which only a published product has');
  return previewed;
}
/** The shop's catalogue has arrived: the page says neither that it is loading nor that it cannot be reached. */
async function catalogueRead() {
  await visitor.waitForFunction(() => !document.body.innerText.includes('Loading the catalogue'), null, { timeout: 30000 });
  must(!(await visitor.getByText('The catalogue is unavailable right now').isVisible().catch(() => false)), 'the shop says its catalogue is unavailable: is its server connected to this admin?');
}
const card = () => visitor.locator(`main a[href="/shop/${product.slug}"]`);
async function openCategory() {
  await visitor.goto(`${shop}/shop?category=${categorySlug}`, { waitUntil: 'domcontentloaded' });
  await catalogueRead();
  await quiet(visitor);
}
async function openProduct() {
  await visitor.goto(`${shop}/shop/${product.slug}`, { waitUntil: 'domcontentloaded' });
  await catalogueRead();
  await quiet(visitor);
}
const answer = async (address) => (await visitor.request.get(address, { failOnStatusCode: false })).status();

console.log(`\nThe admin ${base}, the shop ${shop}. The product: ${product.name}, ${onRequest ? 'priced on request' : `PKR ${product.price}`}, in "${categoryName}".\n`);
let categorySlug = '';

/* ───────────────────────────── 1 to 8: made in the admin, kept by the database ───────────────────────────── */

const signedIn = await step('1. The owner signs in to the admin', async () => {
  await signIn();
  must(new URL(page.url()).pathname.startsWith('/admin'), `after signing in the browser stands at ${new URL(page.url()).pathname}`);
});

if (signedIn) {
  await step('2. A new product is opened and named', async () => {
    await page.goto(`${base}/admin/commerce/products/create`, { waitUntil: 'domcontentloaded' });
    await quiet();
    await shown(page.getByRole('heading', { name: 'New product' }), 'the form of a new product');
    await given(() => field('name').fill(product.name), 'form.name');
    await given(() => field('slug').fill(product.slug), 'form.slug');
    await given(() => field('short_description').fill(product.short), 'form.short_description');
    return `its page will be /shop/${product.slug}`;
  });

  await step('3. A photograph is uploaded, and described for someone who cannot see it', async () => {
    const sent = page.waitForResponse((response) => (response.request().postData() || '').includes('"method":"_finishUpload"'), { timeout: 45000 });
    await page.setInputFiles('#product-uploads', picture);
    await sent;
    await quiet();
    // The admin saves the product before it takes a photograph for it: a photograph belongs to a product.
    await called('uploadImages', () => page.getByRole('button', { name: 'Upload and save gallery' }).click());
    await page.locator('summary', { hasText: 'Gallery settings' }).click();
    await page.locator('input[aria-label="Image 1 alternative text"]').fill('A desk with a cable tray, seen from the front');
    const shownPicture = page.locator('#product-gallery img').first();
    await shownPicture.waitFor({ timeout: 20000 });
    await page.waitForFunction((image) => image.complete, await shownPicture.elementHandle(), { timeout: 20000 });
    must(await shownPicture.evaluate((image) => image.naturalWidth > 0), 'the admin does not show the uploaded photograph');
    return `${path.basename(picture)}, shown by the admin at ${await shownPicture.evaluate((image) => `${image.naturalWidth} × ${image.naturalHeight}`)}`;
  });

  await step('4. Its code, its description and its dimensions are entered', async () => {
    await called('addRow', () => page.getByRole('button', { name: 'Add a code or an option' }).click());
    await shown(page.locator('#variant-0-sku'), 'the row for the product code');
    await page.locator('#variant-0-sku').fill(product.code);
    for (const name of ['description', 'shipping', 'warranty']) await given(() => field(name).fill(product[name]), `form.${name}`);
    await called('addRow', () => page.getByRole('button', { name: 'Add specification' }).click());
    await shown(page.locator('textarea[aria-label="specifications 1 label"]'), 'the row for a specification');
    await page.locator('textarea[aria-label="specifications 1 label"]').fill('Dimensions');
    await page.locator('textarea[aria-label="specifications 1 value"]').fill(product.dimensions);
    return `code ${product.code}; dimensions ${product.dimensions}`;
  });

  await step('5. Its category is chosen', async () => {
    const select = field('primary_category_id');
    const options = await select.locator('option').evaluateAll((list) => list.map((option) => ({ value: option.value, text: option.textContent.trim() })));
    const wanted = options.find((option) => option.value !== '' && option.text.replace(/\s*\(inactive\)$/, '').endsWith(categoryName));
    must(wanted, `the admin has no category whose name ends in "${categoryName}"`);
    must(!/\(inactive\)$/.test(wanted.text), `the category "${wanted.text}" is switched off: a product in it cannot be published`);
    await given(() => select.selectOption(wanted.value), 'form.primary_category_id');
    // The address the shop knows that category by, read from what the admin hands out.
    const tree = await (await visitor.request.get(`${base}/api/v1/catalogue/categories`)).json();
    const found = tree.data.categories.find((category) => String(category.id) === wanted.value);
    must(found, `the admin does not hand the category "${wanted.text}" out to the shop`);
    categorySlug = found.slug;
    return `${wanted.text} (the shop's /shop?category=${categorySlug})`;
  });

  await step(`6. ${onRequest ? 'It is marked "Price on request", with no figure' : 'Its price is entered'}`, async () => {
    if (onRequest) {
      await given(() => field('price_on_request').check(), 'form.price_on_request');
      must((await field('price').inputValue()) === '', 'a price stands beside "Price on request"');
      return 'no price was typed, and none was made up';
    }
    await given(() => field('price').fill(String(product.price)), 'form.price');
    must(!(await field('price_on_request').isChecked()), '"Price on request" is ticked beside a price');
    return `PKR ${product.price}`;
  });

  await step('7. It is reviewed and saved as a draft', async () => {
    await page.locator('input[type="checkbox"][wire\\:model="form.reviewed"]').check();
    await saveDraft();
  });

  await step('8. The product is in the database, as a draft that nobody outside the admin can read', async () => {
    const kept = row();
    if (kept !== null) {
      const [id, slug, version, published, revisions, media] = kept.split('\t');
      must(slug === product.slug, `the database has no product at the address ${product.slug}: it answered "${kept.slice(0, 120)}"`);
      must(published === 'none', 'the product is published already');
      must(Number(revisions) >= 2 && Number(media) === 1, `the database holds ${revisions} saved copies and ${media} photographs of it`);
      product.id = id;
      product.kept = `row ${id} of commerce_products, version ${version}, ${revisions} saved copies, ${media} photograph`;
    }
    must(await answer(`${base}/api/v1/catalogue/products/${product.slug}`) === 404, 'the admin hands a draft out to the shop');
    expected = `/api/products/${product.slug}`;
    must(await answer(`${shop}/api/products/${product.slug}`) === 404, 'the shop\'s server hands a draft out');
    expected = null;
    return kept === null ? 'the database itself was not read (no OS_DB_QUERY_CMD); the admin and the shop answer "not found" for it' : `${product.kept}; the admin and the shop answer "not found" for it`;
  });

  if (restartCommand) {
    await step('8a. What runs is stopped and started again, and the product is still there', async () => {
      execSync(restartCommand, { stdio: ['ignore', 'ignore', 'pipe'], timeout: 180000 });
      const until = Date.now() + 60000;
      while (Date.now() < until && await answer(`${base}/up`).catch(() => 0) !== 200) await page.waitForTimeout(500);
      must(await answer(`${base}/up`) === 200, 'the admin did not come back after the restart');
      const kept = row();
      must(kept === null || kept.split('\t')[1] === product.slug, 'after the restart the database no longer has the product');
      // The editor is opened again from the list, as a person would after a day away.
      await page.goto(`${base}/admin/commerce/products`, { waitUntil: 'domcontentloaded' });
      if (new URL(page.url()).pathname.endsWith('/login')) await signIn();
      await page.goto(`${base}/admin/commerce/products`, { waitUntil: 'domcontentloaded' });
      await quiet();
      const listed = page.locator('article', { hasText: product.name });
      await shown(listed, 'the product in the list of products');
      await listed.getByRole('link', { name: 'Edit product' }).click();
      await page.waitForURL(/\/commerce\/products\/\d+\/edit/, { timeout: 20000 });
      await quiet();
      must((await field('name').inputValue()) === product.name, 'the name is not the one that was saved');
      must((await page.locator('#variant-0-sku').inputValue()) === product.code, 'the code is not the one that was saved');
      must(onRequest ? await field('price_on_request').isChecked() : Number(await field('price').inputValue()) === product.price, 'the price is not the one that was saved');
      return `${kept === null ? 'read on the screen' : 'read in the database and on the screen'}: the name, the code and the price are as they were saved`;
    });
  }

  /* ───────────────────────────── 9 to 13: published, and what a visitor sees ───────────────────────────── */

  await step('9. It is previewed by the person who publishes it, and published', async () => {
    const previewed = await previewAndPublish();
    must(previewed.includes(product.name), 'the preview does not show the product that was saved');
    const kept = row();
    must(kept === null || kept.split('\t')[3] !== 'none', 'the database does not say that it is published');
    await shot(page, 'admin-published');
    return kept === null ? 'the admin says it is published' : `the database names the published copy: revision ${kept.split('\t')[3]}`;
  });

  await step('10. The shop is opened', async () => {
    await visitor.goto(`${shop}/shop`, { waitUntil: 'domcontentloaded' });
    await catalogueRead();
    return 'its catalogue arrived from the admin';
  });

  await step('11. The product stands in its category', async () => {
    await openCategory();
    await card().first().waitFor({ timeout: 20000 });
    const text = (await card().first().locator('xpath=ancestor::*[self::article or self::div][1]').innerText().catch(() => '')).replace(/\s+/g, ' ');
    await shot(visitor, 'shop-category');
    return `listed under /shop?category=${categorySlug}${text ? `: "${text.slice(0, 90)}"` : ''}`;
  });

  await step('12. Its page opens', async () => {
    await openProduct();
    const heading = visitor.getByRole('heading', { level: 1 });
    await heading.first().waitFor({ timeout: 20000 });
    must((await heading.first().innerText()).trim() === product.name, `the page is headed "${(await heading.first().innerText()).trim()}"`);
    return `/shop/${product.slug}`;
  });

  await step('13. The photograph, the code, the description, the dimensions and the price are the ones entered', async () => {
    const main = visitor.locator('main');
    const words = (await main.innerText()).replace(/\s+/g, ' ');
    must(words.includes(`SKU ${product.code}`), `the page does not print the code ${product.code}`);
    must(words.includes(product.description), 'the page does not print the description');
    if (onRequest) {
      must(/Price on request/i.test(words), 'the page does not say "Price on request"');
      must(!/PKR 0\b/.test(words), 'the page prints a price of zero');
    } else {
      must(amount(product.price).test(words), `the page does not print the price ${product.price}`);
      must(!/Price on request/i.test(words), 'the page says "Price on request" beside a price');
    }
    // Each tab is opened as a visitor opens it, and must then be the one that is marked as open.
    const open = async (name) => {
      const tab = visitor.getByRole('tab', { name });
      await tab.click();
      must(await tab.getAttribute('aria-selected') === 'true', `the tab "${name}" was pressed and is not the open one`);
      return (await visitor.getByRole('tabpanel').innerText()).replace(/\s+/g, ' ');
    };
    must((await open('Specifications')).includes(`Dimensions ${product.dimensions}`), 'the page does not print the dimensions');
    must((await open('Shipping')).includes(product.shipping), 'the page does not print what the editor wrote about delivery');
    must((await open('Warranty')).includes(product.warranty), 'the page does not print what the editor wrote about the warranty');
    const image = main.locator(`img[src*="/api/v1/catalogue/products/${product.slug}/picture/"]`).first();
    await image.waitFor({ timeout: 20000 });
    await visitor.waitForFunction((element) => element.complete, await image.elementHandle(), { timeout: 20000 });
    const size = await image.evaluate((element) => ({ width: element.naturalWidth, height: element.naturalHeight, alt: element.alt }));
    must(size.width > 0, 'the photograph did not load on the shop');
    must((await open('Description')).includes(product.description), 'the tab "Description" does not print the description');
    await visitor.evaluate(() => window.scrollTo(0, 0));
    await visitor.waitForTimeout(300);
    await shot(visitor, 'shop-product');
    await visitor.setViewportSize({ width: 390, height: 844 });
    await visitor.waitForTimeout(300);
    const wide = await visitor.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
    await shot(visitor, 'shop-product-phone');
    await visitor.setViewportSize({ width: 1366, height: 900 });
    must(wide <= 1, `on a phone the page is ${wide} px wider than the screen`);
    return `photograph ${size.width} × ${size.height}; code ${product.code}; ${onRequest ? '"Price on request", no figure' : `PKR ${product.price}`}; dimensions, delivery and warranty as written`;
  });

  /* ───────────────────────────── 13a to 13c: what a visitor does with it ───────────────────────────── */

  let reference = '';

  await step('13a. No cart is offered for it: the page offers the quotation basket and WhatsApp', async () => {
    const main = visitor.locator('main');
    const words = (await main.innerText()).replace(/\s+/g, ' ');
    // The shop's own server prices an order from its own database, which does not hold this product: a cart
    // would be refused when it is sent. So none is offered, with a price or without.
    must(await main.getByRole('button', { name: /add to cart/i }).count() === 0, 'the page offers a cart for a product that only the admin has');
    must(await main.getByRole('button', { name: 'Add to Quote', exact: true }).isEnabled(), 'the page does not offer the quotation basket');
    must(onRequest ? words.includes('Add it to your quote and we will send you the price') : words.includes('Ordered by quotation'), 'the page does not say how the product is bought');
    const link = (await main.getByRole('link', { name: 'Ask on WhatsApp' }).getAttribute('href')) || '';
    must(/^https:\/\/wa\.me\/\d{10,15}\?text=/.test(link), `the WhatsApp link is "${link.slice(0, 60)}"`);
    must(decodeURIComponent(link).includes(product.name) && decodeURIComponent(link).includes(product.code), 'the WhatsApp message does not name the product and its code');
    // In its category the card offers the same: the basket, and no cart.
    await openCategory();
    await card().first().waitFor({ timeout: 20000 });
    must(await visitor.getByRole('button', { name: `Add ${product.name} to cart` }).count() === 0, 'the card in the category offers a cart');
    must(await visitor.getByRole('button', { name: `Add ${product.name} to quote` }).count() > 0, 'the card in the category does not offer the quotation basket');
    return `no cart on its page or on its card; "Add to Quote", and WhatsApp to ${link.slice(0, 26)}…`;
  });

  await step('13b. A visitor puts it into the quotation basket and sends the basket', async () => {
    await openProduct();
    const main = visitor.locator('main');
    for (let more = 1; more < shopper.quantity; more++) await main.getByRole('button', { name: 'One more', exact: true }).click();
    await main.getByRole('button', { name: 'Add to Quote', exact: true }).click();
    // The basket opens beside the page; from there the visitor goes on to the request.
    const onward = visitor.getByRole('link', { name: `Request Quote (${shopper.quantity})` });
    await onward.waitFor({ timeout: 20000 });
    await Promise.all([visitor.waitForURL((url) => url.pathname === '/quotation', { timeout: 20000 }), onward.click()]);
    await catalogueRead();
    const basket = (await visitor.locator('main').innerText()).replace(/\s+/g, ' ');
    must(basket.includes(product.name), 'the request page does not list the product');
    must(onRequest ? /on request/i.test(basket) : amount(product.price * shopper.quantity).test(basket), 'the request page does not show what the basket comes to');
    must(await visitor.getByRole('button', { name: /Log & open WhatsApp/ }).count() === 0, 'the request page offers a WhatsApp button that the admin cannot answer');

    await visitor.locator('#name').fill(shopper.name);
    await visitor.locator('#company').fill(shopper.company);
    await visitor.locator('#email').fill(shopper.email);
    await visitor.locator('#phone').fill(shopper.phone);
    await visitor.locator('#project-type').fill(shopper.about);
    await visitor.locator('input[name="contactConsent"]').check();
    const answered = visitor.waitForResponse((response) => response.request().method() === 'POST' && /^\/api\/(v1\/quote-requests|quotes)$/.test(new URL(response.url()).pathname), { timeout: 45000 });
    await visitor.getByRole('button', { name: 'Submit Quote Request' }).click();
    const response = await answered;
    const address = new URL(response.url()).pathname;
    const body = await response.text().catch(() => '');
    must(address === '/api/v1/quote-requests', `the basket was sent to ${address}, which cannot price a product of the admin`);
    must(response.status() === 201, `the basket was answered ${response.status()}: ${body.replace(/\s+/g, ' ').slice(0, 220)}`);
    reference = String(JSON.parse(body)?.data?.reference || '');
    must(/^QR-[0-9a-f-]{36}$/.test(reference), `the answer names no reference: ${body.slice(0, 120)}`);
    await visitor.getByRole('heading', { name: 'Quote Request Submitted!' }).waitFor({ timeout: 20000 });
    const told = (await visitor.locator('main').innerText()).replace(/\s+/g, ' ');
    must(told.includes(reference), 'the page does not give the visitor the reference of the request');
    must(told.includes('Pending staff review'), 'the page does not say that the request waits for the team');
    await shot(visitor, 'shop-basket-sent');
    must(refusals.length === 0, `something the shop's page asked was refused: ${refusals.join('; ')}`);
    return `${shopper.quantity} of it, sent to the admin through the shop's own server: ${reference.slice(0, 11)}…; nothing the page asked was refused`;
  });

  await step('13c. The enquiry is in the admin, with the product, its code and its price on its line', async () => {
    must(reference !== '', 'no basket was sent');
    // What the database kept: which enquiry, and what the priced lines come to, in paisa.
    const kept = database(`SELECT lead_id, estimated_total_minor, status FROM storefront_quote_requests WHERE reference = '${reference}'`);
    let address = '';
    if (kept !== null) {
      const [lead, estimate, status] = kept.split('\t');
      must(/^\d+$/.test(lead || ''), `the database holds no basket ${reference}`);
      must(status === 'pending_review', `the basket's state is "${status}"`);
      must(Number(estimate) === (onRequest ? 0 : product.price * shopper.quantity * 100), `the database keeps an estimate of ${estimate} paisa`);
      address = `${base}/admin/leads/${lead}`;
    } else {
      // No way into the database: the enquiry is found as its owner finds it, in the list, by the visitor's name.
      await crm.goto(`${base}/admin/leads`, { waitUntil: 'domcontentloaded' });
      await quiet(crm);
      const found = crm.locator('tr', { hasText: shopper.name }).first();
      await found.waitFor({ timeout: 20000 });
      address = new URL(await found.locator('a[href*="/admin/leads/"]').first().getAttribute('href'), base).href;
    }
    await crm.goto(address, { waitUntil: 'domcontentloaded' });
    await quiet(crm);
    const screen = (await crm.locator('main').innerText()).replace(/\s+/g, ' ');
    must(screen.includes(shopper.name), 'the enquiry is not this visitor\'s');
    must(screen.includes(reference), 'the enquiry does not show the basket');
    must(screen.includes(product.name) && screen.includes(`[${product.code}]`), 'the line does not name the product and its code');
    if (onRequest) {
      must(screen.includes(`${shopper.quantity} each × price on request`), 'the line does not say "price on request"');
      must(!/[×=] Rs 0\b/.test(screen) && !screen.includes('estimate: Rs 0'), 'the enquiry prints a price of zero');
      must(screen.includes('No catalogue estimate: the line is priced on request'), 'the enquiry does not say that there is no estimate');
    } else {
      must(amount(product.price).test(screen) && amount(product.price * shopper.quantity).test(screen), 'the line does not show the price as published and what the line comes to');
      must(!screen.includes('price on request'), 'the line says "price on request" beside a price');
      must(screen.includes('Saved catalogue estimate:') && !screen.includes('No catalogue estimate'), 'the enquiry does not give the estimate of the basket');
    }
    // The picture shows the basket itself, which stands below the first screen of the enquiry.
    await crm.getByText(reference).first().scrollIntoViewIfNeeded().catch(() => {});
    await shot(crm, 'admin-enquiry-with-basket');
    // The sheet is left empty: an open screen of the admin asks its server now and then, and the steps that
    // follow wait for the answers to what they do on the other sheet.
    await crm.goto('about:blank').catch(() => {});
    return `${kept === null ? 'read on the screen' : 'read in the database and on the screen'}: ${shopper.name}, ${shopper.quantity} × ${product.name} [${product.code}], ${onRequest ? 'price on request, no figure' : `PKR ${product.price} each`}`;
  });

  /* ───────────────────────────── 14, 15: changed in the admin, changed in the shop ───────────────────────────── */

  await step('14. The product is changed in the admin: a new name' + (onRequest ? ', and a price in place of "on request"' : ' and a new price'), async () => {
    await given(() => field('name').fill(product.renamed), 'form.name');
    // Typing a price into a product that says "Price on request" takes the tick off, where the editor sees it.
    // The admin's answer to the price is what takes it off, so that answer is waited for, and then the tick.
    await given(() => field('price').fill(String(product.repriced)), 'form.price');
    await page.waitForFunction(() => !document.getElementById('form.price_on_request')?.checked, null, { timeout: 10000 }).catch(() => {});
    must(!(await field('price_on_request').isChecked()), 'a price was typed and "Price on request" is still ticked');
    await page.locator('input[type="checkbox"][wire\\:model="form.reviewed"]').check();
    await saveDraft();
    // Saved and not yet published: the shop still shows what was published.
    await openProduct();
    must((await visitor.getByRole('heading', { level: 1 }).first().innerText()).trim() === product.name, 'the shop shows a draft that nobody published');
    await previewAndPublish();
    return `${product.renamed}, PKR ${product.repriced}; until it was published again the shop kept the copy of before`;
  });

  await step('15. The shop shows the change', async () => {
    await openProduct();
    const words = (await visitor.locator('main').innerText()).replace(/\s+/g, ' ');
    must((await visitor.getByRole('heading', { level: 1 }).first().innerText()).trim() === product.renamed, 'the shop still shows the old name');
    must(amount(product.repriced).test(words), 'the shop does not show the new price');
    must(onRequest || !amount(product.price).test(words), 'the shop still shows the old price');
    await openCategory();
    await card().first().waitFor({ timeout: 20000 });
    must((await visitor.locator('main').innerText()).includes(product.renamed), 'the category still lists the old name');
    return 'the new name and the new price, on the product\'s page and in its category';
  });

  /* ───────────────────────────── 16, 17: withdrawn, and gone ───────────────────────────── */

  await step('16. The publication is withdrawn in the admin', async () => {
    await page.locator('input[type="checkbox"][wire\\:model="confirmWithdraw"]').check();
    await called('withdraw', () => page.getByRole('button', { name: 'Withdraw publication' }).click());
    // Waited for, and then asserted: read in the same moment as the answer arrived, the button was once still there.
    await page.getByRole('button', { name: 'Withdraw publication' }).waitFor({ state: 'hidden', timeout: 15000 }).catch(() => {});
    must(!(await page.getByRole('button', { name: 'Withdraw publication' }).isVisible().catch(() => false)), 'the admin still offers to withdraw a publication');
    const kept = row();
    must(kept === null || kept.split('\t')[3] === 'none', 'the database still names a published copy');
    return kept === null ? 'the admin says it is withdrawn' : 'the database names no published copy; the product itself, its saved copies and its photograph are kept';
  });

  await step('17. The shop no longer has it: not in its category, not at its address', async () => {
    await openCategory();
    await visitor.waitForTimeout(500);
    must(await card().count() === 0, 'the category still lists the product');
    expected = `/api/products/${product.slug}`;
    must(await answer(`${shop}/api/products/${product.slug}`) === 404, 'the shop\'s server still hands it out');
    expected = null;
    must(await answer(`${base}/api/v1/catalogue/products/${product.slug}`) === 404, 'the admin still hands it out');
    await openProduct();
    await visitor.getByRole('heading', { name: 'Product Not Found' }).waitFor({ timeout: 20000 });
    must(await visitor.locator('meta[name="robots"][content="noindex"]').count() === 1, 'the page of a product that is gone does not ask search engines to leave it out');
    await shot(visitor, 'shop-withdrawn');
    return 'its page says "Product Not Found" and asks not to be indexed; both servers answer "not found"';
  });
}

await browser.close();

const failed = steps.filter((entry) => entry.result === 'FAILED');
const serious = [...new Set(problems)];
// The most reads in any one minute of the run: for each read, how many fell into the minute that began with it.
const busiest = reads.reduce((most, from, index) => Math.max(most, reads.filter((at, later) => later >= index && at < from + 60000).length), 0);
if (out) {
  writeFileSync(path.join(out, 'journeys.json'), `${JSON.stringify({
    base, shop, journey: 'a product from the admin to the shop', priced: onRequest ? 'on request' : 'with a price',
    product: { name: product.name, slug: product.slug, code: product.code }, database: queryCommand ? 'read' : 'not read', restarted: Boolean(restartCommand), steps, problems: serious,
    refused: refusals, visitor: { address: visitorAddress || 'as the shop\'s own proxy names it', readsHandedToTheAdmin: reads.length, mostInOneMinute: busiest, allowedInOneMinute: 120 },
  }, null, 2)}\n`);
}
console.log(`\n${steps.length - failed.length} of ${steps.length} steps held${serious.length ? `; the browser reported ${serious.length} problem(s):` : '; the browser reported no problem'}`);
for (const problem of serious) console.log(`  ${problem}`);
console.log(`  the visitor's pages asked the admin, through the shop's server, ${reads.length} times: at most ${busiest} in one minute, of the 120 the admin allows one visitor`);
if (elsewhere.size) console.log(`  (not counted: a file of another server did not arrive: ${[...elsewhere].join(', ')})`);
process.exit(failed.length || serious.length ? 1 : 0);

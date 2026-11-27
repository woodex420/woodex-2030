// The shop's reader of the admin's products, and the bridge in front of it (master list T-105).
//
// Run by `npm run test:native-products`, and by CI beside the categories' own script. It holds the two halves
// that meet at one address, because either half alone can be right and the pair wrong:
//
//   · the bridge answers at the address the shop already asks (/api/products), hands the admin's answer on
//     unchanged, forwards only the query parameters the admin named, and never forwards a cookie;
//   · the reader turns that answer into the product the pages use — paisa to rupees, "ask us" as a switch, a
//     picture per row, an address the shop can link to.
//
// Tried against (this file's own claims): with rupees printed where paisa were sent, the price assertion names
// the figure; with the switch taken away, the first assertion fails.
import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { nativeProductBridge, productSyncOn } from '../apps/api/native-product-bridge.mjs';

const bundle = await build({
  stdin: { contents: `export * from './apps/storefront/src/lib/nativeProducts';`, resolveDir: process.cwd() },
  bundle: true, platform: 'node', format: 'esm', write: false,
});
const reader = await import('data:text/javascript;base64,' + Buffer.from(bundle.outputFiles[0].text).toString('base64'));

/* ── The answer the admin gives (an example of the contract, docs/woodex-os/29-API-V1-RULES.md) ─────────────── */

const desk = {
  id: 'executive-desk', name: 'Executive Desk', slug: 'executive-desk',
  short_description: 'A desk for one.', description: 'A reviewed desk.', shipping: 'Three weeks.', warranty: 'One year.',
  category_ids: [3], primary_category_id: 3, series_ids: [7],
  availability: 'available', price_minor: 4250050, was_price_minor: null, currency: 'PKR', on_request: false,
  variants: [], specifications: [{ label: 'Width', value: '1800 mm' }], features: ['Cable tray'], faqs: [],
  is_hot: true, is_featured: false, related_mode: 'category', related_ids: [],
  pictures: [{ url: '/api/v1/catalogue/products/executive-desk/picture/1/4', alt: 'A desk by a window' }],
  position: 1, revision: 2, published_at: '2026-11-19T10:00:00Z', updated_at: '2026-11-19T10:00:00Z',
};
const askDesk = {
  ...desk, id: 'ask-desk', name: 'Ask Desk', slug: 'ask-desk', availability: 'on_request', on_request: true,
  price_minor: 0,
};
const priced = {
  ...desk, id: 'sale-desk', name: 'Sale Desk', slug: 'sale-desk', price_minor: 800000, was_price_minor: 1000000,
};
const lookups = { category: id => (id === 3 ? 'executive-tables' : null), series: id => (id === 7 ? 'nova-series' : null) };
const page = {
  data: [desk, askDesk, priced],
  meta: { request_id: '4F7K-9Q2M-T8RB', page: 1, per_page: 24, total: 61 },
  links: { self: '/api/v1/catalogue/products?page=1&per_page=24', next: '/api/v1/catalogue/products?page=2&per_page=24', prev: null },
};

/* ── The reader ─────────────────────────────────────────────────────────────────────────────────────────────── */

assert.equal(reader.rupees(4250050), 42501, 'paisa to rupees, to the nearest rupee');
assert.equal(reader.rupees(800000), 8000);
assert.equal(reader.rupees(null), 0, 'no figure is no figure, not NaN');

const read = reader.toProducts(page, lookups);
assert.equal(read.total, 61, 'the total is the size of the whole list, not of the page');
assert.equal(read.next, '/api/v1/catalogue/products?page=2&per_page=24', 'the next page is the admin’s own address');
assert.equal(read.products.length, 3);
const [first, ask, sale] = read.products;
assert.equal(first.id, 'executive-desk', 'the shop links by the slug the admin knows the product by');
assert.equal(first.price, 42501, 'the price in rupees');
assert.equal(first.category, 'executive-tables', 'the category is the shop’s own name for it');
assert.equal(first.series, 'nova-series');
assert.equal(first.priceOnRequest, false);
assert.deepEqual(first.images, ['/api/v1/catalogue/products/executive-desk/picture/1/4'], 'a picture per row, at the admin’s address');
assert.equal(first.isBestSeller, true);
assert.equal(first.shipping, 'Three weeks.', 'the text an editor wrote is handed on as written');
assert.equal(ask.priceOnRequest, true, '“ask us” is a switch the pages already understand');
assert.equal(ask.inStock, true, 'a product to ask about is not a product out of stock');
assert.equal(sale.originalPrice, 10000, 'the price it was reduced from, in rupees');

// A product the editor marked unavailable cannot be ordered, by price or by quote.
assert.equal(reader.toProduct({ ...desk, availability: 'unavailable' }, lookups).inStock, false);

// An answer that is not the contract’s shape is not taken: the shop keeps what it has.
assert.equal(reader.toProducts({ schema: 1, items: [] }, lookups), null, 'the answer of before the rules is not taken');
assert.equal(reader.toProducts({ data: 'not a list' }, lookups), null);
assert.equal(reader.toProduct({ ...desk, price_minor: 42.5 }, lookups), null, 'a fraction is not money of this API');
assert.equal(reader.toProduct({ ...desk, slug: 'Not A Slug' }, lookups), null);
assert.equal(reader.toProduct({ ...desk, on_request: 'yes' }, lookups), null);

// One row that cannot be read is dropped, and the rest of the page is still shown.
const partial = reader.toProducts({ ...page, data: [desk, { id: 'broken' }, askDesk] }, lookups);
assert.equal(partial.products.length, 2, 'a claim that cannot be read is dropped, not rendered as a blank card');

/* ── The bridge ────────────────────────────────────────────────────────────────────────────────────────────── */

const reply = () => ({
  statusCode: 200, headers: {},
  status(code) { this.statusCode = code; return this; },
  json(data) { this.data = data; return this; },
  set(headers) { this.headers = { ...this.headers, ...headers }; return this; },
  send(data) { this.data = data; return this; },
});
const shaped = (res, status, code, number = null) => {
  assert.equal(res.statusCode, status);
  assert.deepEqual(Object.keys(res.data).sort(), ['error', 'meta']);
  assert.equal(res.data.error.code, code);
  assert.deepEqual(res.data.error.details, {});
  assert.equal(res.data.meta.request_id, number);
};

// The switch is off unless it is asked for: that is the whole reason this round touches no live site.
// Called with nothing at all — which is how the API asks it, once, as it starts — it says "off" rather than
// throwing. (It threw there, and only there: the bridge's own tests passed while the shop could not boot.)
assert.equal(productSyncOn(), false, 'with no argument the switch reads this process’s environment, and it is off');
assert.equal(productSyncOn({ NATIVE_PRODUCT_SYNC: '0' }), false);
assert.equal(productSyncOn({ NATIVE_PRODUCT_SYNC: '1' }), true);

let res = reply();
await nativeProductBridge({})({ path: '/api/products', query: {}, headers: {} }, res);
shaped(res, 503, 'SWITCHED_OFF');

res = reply();
await nativeProductBridge({ NATIVE_PRODUCT_SYNC: '1', NATIVE_CATALOGUE_ORIGIN: 'http://example.com', NODE_ENV: 'production' })({ path: '/api/products', query: {}, headers: {} }, res);
shaped(res, 503, 'UNAVAILABLE');

// The answer is handed on as it is, at the address the shop asks, with only the parameters the admin named.
let seen;
globalThis.fetch = async (url, options) => {
  seen = { url, options };
  return new Response(JSON.stringify(page), { headers: { 'content-type': 'application/json', 'x-request-id': '4F7K-9Q2M-T8RB' } });
};
res = reply();
await nativeProductBridge({ NATIVE_PRODUCT_SYNC: '1', NATIVE_CATALOGUE_ORIGIN: 'https://admin.example.test' })(
  { path: '/api/products', query: { page: '2', category: 'executive-tables', status: 'draft', q: 'desk' }, headers: { cookie: 'STAFF', authorization: 'SECRET' } }, res,
);
assert.equal(res.statusCode, 200);
assert.equal(seen.url.pathname, '/api/v1/catalogue/products');
assert.equal(seen.url.searchParams.get('page'), '2');
assert.equal(seen.url.searchParams.get('category'), 'executive-tables');
assert.equal(seen.url.searchParams.get('status'), null, 'a parameter the admin did not name is not sent on');
assert.equal(seen.url.searchParams.get('q'), null);
assert.equal(seen.options.headers.cookie, undefined, 'no cookie goes to the admin');
assert.equal(seen.options.headers.authorization, undefined, 'no authorization goes to the admin');
assert.equal(res.data, JSON.stringify(page), 'the admin’s answer is handed on unchanged, byte for byte');
assert.equal(res.headers['X-Request-Id'], '4F7K-9Q2M-T8RB', 'the number of the admin’s request travels with its answer');

// One product, and its address.
res = reply();
await nativeProductBridge({ NATIVE_PRODUCT_SYNC: '1', NATIVE_CATALOGUE_ORIGIN: 'https://admin.example.test' })({ path: '/api/products/executive-desk', query: {}, headers: {} }, res);
assert.equal(seen.url.pathname, '/api/v1/catalogue/products/executive-desk');
assert.equal(res.statusCode, 200);

// An address of the shop's own that is not a product is not passed on.
res = reply();
await nativeProductBridge({ NATIVE_PRODUCT_SYNC: '1', NATIVE_CATALOGUE_ORIGIN: 'https://admin.example.test' })({ path: '/api/products/../../admin', query: {}, headers: {} }, res);
shaped(res, 404, 'NOT_FOUND');

// A product the admin does not hand out answers the shop with the admin's own 404, and its number.
globalThis.fetch = async () => Response.json({ error: { code: 'NOT_FOUND', message: 'x', details: {} }, meta: { request_id: 'ZZZZ-ZZZZ-ZZZZ' } }, { status: 404, headers: { 'x-request-id': 'ZZZZ-ZZZZ-ZZZZ' } });
res = reply();
await nativeProductBridge({ NATIVE_PRODUCT_SYNC: '1', NATIVE_CATALOGUE_ORIGIN: 'https://admin.example.test' })({ path: '/api/products/gone', query: {}, headers: {} }, res);
shaped(res, 404, 'NOT_FOUND', 'ZZZZ-ZZZZ-ZZZZ');

// An outage of the admin is an outage of the catalogue, not an empty catalogue.
globalThis.fetch = async () => Response.json({ error: { code: 'UNAVAILABLE', message: 'x', details: {} }, meta: { request_id: null } }, { status: 503 });
res = reply();
await nativeProductBridge({ NATIVE_PRODUCT_SYNC: '1', NATIVE_CATALOGUE_ORIGIN: 'https://admin.example.test' })({ path: '/api/products', query: {}, headers: {} }, res);
shaped(res, 503, 'UNAVAILABLE');

globalThis.fetch = async () => new Response('<script>unsafe</script>', { headers: { 'content-type': 'text/html' } });
res = reply();
await nativeProductBridge({ NATIVE_PRODUCT_SYNC: '1', NATIVE_CATALOGUE_ORIGIN: 'https://admin.example.test' })({ path: '/api/products', query: {}, headers: {} }, res);
shaped(res, 502, 'BAD_GATEWAY');

globalThis.fetch = async () => { throw new Error('outage'); };
res = reply();
await nativeProductBridge({ NATIVE_PRODUCT_SYNC: '1', NATIVE_CATALOGUE_ORIGIN: 'https://admin.example.test' })({ path: '/api/products', query: {}, headers: {} }, res);
shaped(res, 503, 'UNAVAILABLE');

/* ── A product's photograph, at the path the admin's answer names ───────────────────────────────────���──────────
   The reader's test builds no page, so the bug this holds was found only by a browser: the admin names a path
   on itself, a page asks the shop it stands on, and the shop had no route for it. Every product photograph
   was a 401 on the live shop with the rest of the round working. */

// A published picture at the address the answer gives: handed on as bytes, of the three formats, with its type.
globalThis.fetch = async url => {
  seen = { url };
  return new Response(new Uint8Array([137, 80, 78, 71]), { headers: { 'content-type': 'image/png' } });
};
res = reply();
await nativeProductBridge({ NATIVE_PRODUCT_SYNC: '1', NATIVE_CATALOGUE_ORIGIN: 'https://admin.example.test' })(
  { path: '/api/v1/catalogue/products/aero-home-office-desk/picture/1/7', query: {}, headers: {} }, res,
);
assert.equal(seen.url.pathname, '/api/v1/catalogue/products/aero-home-office-desk/picture/1/7', 'the picture is asked of the admin, at its own path');
assert.equal(seen.url.host, 'admin.example.test');
assert.equal(res.statusCode, 200, 'the shop answers the picture itself, so a page asks the shop it stands on');
assert.equal(res.headers['Content-Type'], 'image/png');
assert.equal(res.headers['X-Content-Type-Options'], 'nosniff');
assert.deepEqual([...res.data], [137, 80, 78, 71], 'the bytes are the admin’s bytes');

// With the switch off, the picture is not handed on either: the same refusal the list gives.
res = reply();
await nativeProductBridge({})({ path: '/api/v1/catalogue/products/aero-home-office-desk/picture/1/7', query: {}, headers: {} }, res);
shaped(res, 503, 'SWITCHED_OFF');

// A picture the admin no longer publishes is a 404 here, not an empty picture.
globalThis.fetch = async () => new Response('', { status: 404 });
res = reply();
await nativeProductBridge({ NATIVE_PRODUCT_SYNC: '1', NATIVE_CATALOGUE_ORIGIN: 'https://admin.example.test' })(
  { path: '/api/v1/catalogue/products/aero-home-office-desk/picture/1/7', query: {}, headers: {} }, res,
);
shaped(res, 404, 'NOT_FOUND');

// Something that is neither a picture nor JSON is a bad gateway: a page must not be handed a page.
globalThis.fetch = async () => new Response('<html>staff sign-in</html>', { headers: { 'content-type': 'text/html' } });
res = reply();
await nativeProductBridge({ NATIVE_PRODUCT_SYNC: '1', NATIVE_CATALOGUE_ORIGIN: 'https://admin.example.test' })(
  { path: '/api/v1/catalogue/products/aero-home-office-desk/picture/1/7', query: {}, headers: {} }, res,
);
shaped(res, 502, 'BAD_GATEWAY');

// A path that is not a whole picture address is not ours.
for (const path of ['/api/v1/catalogue/products/aero-home-office-desk/picture/1', '/api/v1/catalogue/products/aero-home-office-desk/picture/1/7/extra', '/api/v1/catalogue/products/aero-home-office-desk/picture/x/7']) {
  globalThis.fetch = async () => { throw new Error('the bridge must not have asked the admin'); };
  res = reply();
  await nativeProductBridge({ NATIVE_PRODUCT_SYNC: '1', NATIVE_CATALOGUE_ORIGIN: 'https://admin.example.test' })({ path, query: {}, headers: {} }, res);
  // Not a picture route: it falls through to the product route, which refuses it as not ours.
  assert.equal(res.statusCode, 404, `${path} is not a picture address`);
}

/* ── An option keeps its identity when the list is reordered ──────────────────────────────────────────────────
   The shop keeps a chosen option in a basket and a quote as `variantId` and matches it back by that number
   (contexts/reconcile.ts). An id that follows the position in the list would point a saved chair at a different
   chair the moment the editor reorders the options in the admin — with a plausible price attached and nothing
   on the screen to show it. The contract gives an option no `id`; it does give a unique `sku`. */

const twoOptions = {
  ...desk,
  variants: [
    { sku: 'HOF-10004', colour: 'Walnut', material: 'Oak', size: '', price_minor: 1800000, availability: 'available' },
    { sku: 'HOF-10005', colour: 'Oak', material: 'Oak', size: '', price_minor: 1950000, availability: 'available' },
  ],
};
const forward = reader.toProduct(twoOptions, lookups);
const reversed = reader.toProduct({ ...twoOptions, variants: [...twoOptions.variants].reverse() }, lookups);
const idOf = (product, sku) => product.variants.find(v => v.sku === sku).id;

assert.equal(forward.variants.length, 2);
assert.equal(idOf(forward, 'HOF-10004'), idOf(reversed, 'HOF-10004'), 'the same SKU keeps the same id when the options are reordered');
assert.equal(idOf(forward, 'HOF-10005'), idOf(reversed, 'HOF-10005'));
assert.notEqual(idOf(forward, 'HOF-10004'), idOf(forward, 'HOF-10005'), 'two options of one product never share an id');
for (const option of forward.variants) {
  assert.ok(Number.isInteger(option.id) && option.id > 0, 'an option id is a positive whole number, as the shop’s own are');
  assert.ok(!(option.id >= 2 ** 31), 'an option id fits the range the shop’s own ids use');
}
// And the id does not depend on which other options happen to be beside it.
const alone = reader.toProduct({ ...desk, variants: [twoOptions.variants[0]] }, lookups);
assert.equal(idOf(alone, 'HOF-10004'), idOf(forward, 'HOF-10004'), 'an option keeps its id when its neighbours are removed');

console.log('Native product reader: paisa to rupees, “ask us” as a switch, a photograph at its own path, a dropped claim, an option’s identity kept when the list is reordered, the switch off, the bridge’s own refusals, and the admin’s answer handed on unchanged — all passed.');

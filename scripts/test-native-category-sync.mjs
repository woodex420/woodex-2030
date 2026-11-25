import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { nativeCategoryBridge } from '../apps/api/native-category-bridge.mjs';
const result = await build({ stdin: { contents: `export * from './apps/storefront/src/lib/nativeCategories'; export * from './apps/storefront/src/lib/commerceTaxonomy';`, resolveDir: process.cwd() }, bundle: true, platform: 'node', format: 'esm', write: false });
const api = await import('data:text/javascript;base64,' + Buffer.from(result.outputFiles[0].text).toString('base64'));
let events = 0;
globalThis.window = { dispatchEvent() { events++; } };
/* What the admin answers since the rules of /api/v1 (docs/woodex-os/29-API-V1-RULES.md): {data: {categories, series},
   meta: {request_id}}, and the number of the request is new with every answer. */
let asked = 0;
const numbers = ['4F7K-9Q2M-T8RB', '0A1B-2C3D-4E5F', '6G7H-8J9K-0M1N', '2P3Q-4R5S-6T7V'];
const answer = data => ({ data, meta: { request_id: numbers[asked++ % numbers.length] } });
const switchedOff = () => Response.json({ error: { code: 'SWITCHED_OFF', message: 'This shop is not connected to the admin.', details: {} }, meta: { request_id: null } }, { status: 503 });
const fixture = { categories: [
  { id: 2, name: 'Home renamed', slug: 'home-furniture', parent_id: null, position: 0, page: null },
  { id: 1, name: 'Office renamed', slug: 'office-furniture', parent_id: null, position: 1, page: null },
  { id: 3, name: 'Tables', slug: 'office-tables', parent_id: 1, position: 0, page: null },
  { id: 4, name: 'Meeting first', slug: 'meeting-tables', parent_id: 3, position: 0, page: null },
  { id: 5, name: 'Executive second', slug: 'executive-tables', parent_id: 3, position: 1, page: null },
], series: [{ id: 'nova-series', name: 'Nova Series' }] };
globalThis.fetch = async () => Response.json(answer(fixture));
await api.syncNativeCategories();
assert.equal(api.categorySegments()[0].name, 'Home renamed');
assert.equal(api.categoryTree('office')[0].children[0].id, 'meeting-tables');
assert.equal(api.matchesCategory({ category: 'office-tables', subcategory: 'meeting-tables' }, 'office'), true);
assert.equal(api.matchesCategory({ category: 'office-tables' }, 'home'), false);
assert.equal(api.collectionList()[0].id, 'nova-series');
assert.equal(events, 1);
await api.syncNativeCategories(); assert.equal(events, 1, 'Unchanged replies do not rerender, though each carries a new request number');
assert.equal(asked, 2, 'both answers were read');
globalThis.fetch = async () => { throw new Error('outage'); };
await api.syncNativeCategories(); assert.equal(api.categorySegments()[0].name, 'Home renamed');
// The answer of before the rules ({schema, categories, series}) is not taken: the last good tree stays.
globalThis.fetch = async () => Response.json({ schema: 1, categories: [], series: [] });
await api.syncNativeCategories(); assert.equal(api.categorySegments()[0].name, 'Home renamed');
globalThis.fetch = async () => Response.json(answer({ categories: 'not a list', series: [] }));
await api.syncNativeCategories(); assert.equal(api.categorySegments()[0].name, 'Home renamed');
function reply() { return { statusCode: 200, status(code) { this.statusCode = code; return this; }, json(data) { this.data = data; return this; }, sendStatus(code) { this.statusCode = code; }, set(headers) { this.headers = headers; return this; }, send(data) { this.data = data; return this; } }; }
const req = { path: '/api/v1/catalogue/categories', headers: { cookie: 'STAFF', authorization: 'SECRET' } };
// What the bridge answers itself is in the API's error shape, each with its code.
const shaped = (res, status, code, number = null) => { assert.equal(res.statusCode, status); assert.deepEqual(Object.keys(res.data), ['error', 'meta']); assert.equal(res.data.error.code, code); assert.deepEqual(res.data.error.details, {}); assert.equal(res.data.meta.request_id, number); };
const on = { NATIVE_CATALOGUE_ORIGIN: 'https://native.example.test' };
let res = reply(); await nativeCategoryBridge({})(req, res); shaped(res, 503, 'SWITCHED_OFF');
res = reply(); await nativeCategoryBridge({ NATIVE_CATALOGUE_ORIGIN: 'http://example.com', NODE_ENV: 'production' })(req, res); shaped(res, 503, 'UNAVAILABLE');
let seen;
globalThis.fetch = async (url, options) => { seen = { url, options }; return Response.json({ data: fixture, meta: { request_id: '4F7K-9Q2M-T8RB' } }, { headers: { 'X-Request-Id': '4F7K-9Q2M-T8RB' } }); };
res = reply(); await nativeCategoryBridge(on)(req, res);
assert.equal(res.statusCode, 200); assert.equal(seen.url.pathname, req.path); assert.equal(seen.options.headers.cookie, undefined); assert.equal(seen.options.headers.authorization, undefined);
// The admin's answer is handed on as it is, with the number of its request.
assert.deepEqual(JSON.parse(res.data.toString()), { data: fixture, meta: { request_id: '4F7K-9Q2M-T8RB' } }); assert.equal(res.headers['X-Request-Id'], '4F7K-9Q2M-T8RB');
res = reply(); await nativeCategoryBridge(on)({ path: '/api/v1/catalogue/categories/../../admin' }, res); shaped(res, 404, 'NOT_FOUND');
globalThis.fetch = async () => new Response('<script>unsafe</script>', { headers: { 'content-type': 'text/html' } });
res = reply(); await nativeCategoryBridge(on)(req, res); shaped(res, 502, 'BAD_GATEWAY');
// The admin says its category list is switched off (503): for the shop that is an outage, and the last good tree stays.
globalThis.fetch = async () => Response.json({ error: { code: 'UNAVAILABLE', message: 'The category list is switched off at the moment.', details: {} }, meta: { request_id: '4F7K-9Q2M-T8RB' } }, { status: 503, headers: { 'X-Request-Id': '4F7K-9Q2M-T8RB' } });
res = reply(); await nativeCategoryBridge(on)(req, res); shaped(res, 503, 'UNAVAILABLE', '4F7K-9Q2M-T8RB');
await api.syncNativeCategories(); assert.equal(api.categorySegments()[0].name, 'Home renamed');
globalThis.fetch = switchedOff;
await api.syncNativeCategories(); assert.equal(api.nativeCatalogue(), null); assert.equal(api.categorySegments()[0].id, 'office');
// Told "disabled", the shop does not ask again fifteen seconds later.
let askedAfterDisabled = 0; globalThis.fetch = async () => { askedAfterDisabled++; return Response.json(answer(fixture)); };
await api.syncNativeCategories(); assert.equal(askedAfterDisabled, 0, 'After "disabled" the next poll is not sent');
const realNow = Date.now; Date.now = () => realNow() + 11 * 60_000;
await api.syncNativeCategories(); assert.equal(askedAfterDisabled, 1, 'Ten minutes after "disabled" the shop asks again');
assert.equal(api.categorySegments()[0].name, 'Home renamed', '…and takes the categories it is given');
// Refused outright (the bridge once answered every visitor 401): the shop does not repeat the question at once either.
let askedAfterRefusal = 0;
globalThis.fetch = async () => { askedAfterRefusal++; return Response.json({ error: { code: 'UNAUTHENTICATED', message: 'Sign in first.', details: {} }, meta: { request_id: null } }, { status: 401 }); };
await api.syncNativeCategories(); await api.syncNativeCategories();
assert.equal(askedAfterRefusal, 1, 'After a refusal the next poll is not sent');
assert.equal(api.categorySegments()[0].name, 'Home renamed', 'A refusal keeps the last categories');
console.log('Native category sync: dynamic names/order/descendants, Series, last-good outage, explicit rollback, schema rejection and read-only bridge safety passed.');

/* Built as Vercel builds a shop that is connected to no admin (scripts/build-vercel.mjs), the shop does not ask. */
{
  const off = await build({ stdin: { contents: `export * from './apps/storefront/src/lib/nativeCategories';`, resolveDir: process.cwd() }, bundle: true, platform: 'node', format: 'esm', write: false, define: { 'import.meta.env': '{"VITE_NATIVE_CATEGORIES":"off"}' } });
  const silent = await import('data:text/javascript;base64,' + Buffer.from(off.outputFiles[0].text + '\n// off').toString('base64'));
  let questions = 0;
  globalThis.fetch = async () => { questions++; return switchedOff(); };
  await silent.syncNativeCategories(); await silent.syncNativeCategories();
  assert.equal(questions, 0, 'a shop built as not connected must not ask for the categories');
}


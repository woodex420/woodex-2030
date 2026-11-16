import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { nativeCategoryBridge } from '../apps/api/native-category-bridge.mjs';
const result = await build({ stdin: { contents: `export * from './apps/storefront/src/lib/nativeCategories'; export * from './apps/storefront/src/lib/commerceTaxonomy';`, resolveDir: process.cwd() }, bundle: true, platform: 'node', format: 'esm', write: false });
const api = await import('data:text/javascript;base64,' + Buffer.from(result.outputFiles[0].text).toString('base64'));
let events = 0;
globalThis.window = { dispatchEvent() { events++; } };
const fixture = { schema: 1, categories: [
  { id: 2, name: 'Home renamed', slug: 'home-furniture', parent_id: null, position: 0, page: null },
  { id: 1, name: 'Office renamed', slug: 'office-furniture', parent_id: null, position: 1, page: null },
  { id: 3, name: 'Tables', slug: 'office-tables', parent_id: 1, position: 0, page: null },
  { id: 4, name: 'Meeting first', slug: 'meeting-tables', parent_id: 3, position: 0, page: null },
  { id: 5, name: 'Executive second', slug: 'executive-tables', parent_id: 3, position: 1, page: null },
], series: [{ id: 'nova-series', name: 'Nova Series' }] };
globalThis.fetch = async () => Response.json(fixture);
await api.syncNativeCategories();
assert.equal(api.categorySegments()[0].name, 'Home renamed');
assert.equal(api.categoryTree('office')[0].children[0].id, 'meeting-tables');
assert.equal(api.matchesCategory({ category: 'office-tables', subcategory: 'meeting-tables' }, 'office'), true);
assert.equal(api.matchesCategory({ category: 'office-tables' }, 'home'), false);
assert.equal(api.collectionList()[0].id, 'nova-series');
assert.equal(events, 1);
await api.syncNativeCategories(); assert.equal(events, 1, 'Unchanged replies do not rerender');
globalThis.fetch = async () => { throw new Error('outage'); };
await api.syncNativeCategories(); assert.equal(api.categorySegments()[0].name, 'Home renamed');
globalThis.fetch = async () => Response.json({ schema: 999, categories: [] });
await api.syncNativeCategories(); assert.equal(api.categorySegments()[0].name, 'Home renamed');
function reply() { return { statusCode: 200, status(code) { this.statusCode = code; return this; }, json(data) { this.data = data; return this; }, sendStatus(code) { this.statusCode = code; }, set(headers) { this.headers = headers; return this; }, send(data) { this.data = data; return this; } }; }
const req = { path: '/api/v1/catalogue/categories', headers: { cookie: 'STAFF', authorization: 'SECRET' } };
let res = reply(); await nativeCategoryBridge({})(req, res); assert.equal(res.statusCode, 503); assert.equal(res.data.disabled, true);
res = reply(); await nativeCategoryBridge({ NATIVE_CATALOGUE_ORIGIN: 'http://example.com', NODE_ENV: 'production' })(req, res); assert.equal(res.statusCode, 503);
let seen;
globalThis.fetch = async (url, options) => { seen = { url, options }; return Response.json(fixture); };
res = reply(); await nativeCategoryBridge({ NATIVE_CATALOGUE_ORIGIN: 'https://native.example.test' })(req, res);
assert.equal(res.statusCode, 200); assert.equal(seen.url.pathname, req.path); assert.equal(seen.options.headers.cookie, undefined); assert.equal(seen.options.headers.authorization, undefined);
res = reply(); await nativeCategoryBridge({ NATIVE_CATALOGUE_ORIGIN: 'https://native.example.test' })({ path: '/api/v1/catalogue/categories/../../admin' }, res); assert.equal(res.statusCode, 404);
globalThis.fetch = async () => new Response('<script>unsafe</script>', { headers: { 'content-type': 'text/html' } });
res = reply(); await nativeCategoryBridge({ NATIVE_CATALOGUE_ORIGIN: 'https://native.example.test' })(req, res); assert.equal(res.statusCode, 502);
globalThis.fetch = async () => Response.json({ disabled: true }, { status: 503 });
await api.syncNativeCategories(); assert.equal(api.nativeCatalogue(), null); assert.equal(api.categorySegments()[0].id, 'office');
console.log('Native category sync: dynamic names/order/descendants, Series, last-good outage, explicit rollback, schema rejection and read-only bridge safety passed.');

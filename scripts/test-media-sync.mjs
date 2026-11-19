import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { JSDOM } from 'jsdom';
import { nativeMediaBridge } from '../apps/api/native-media-bridge.mjs';
const built = await build({ entryPoints: ['apps/storefront/src/lib/mediaSync.ts'], bundle: true, format: 'esm', platform: 'node', write: false });
const api = await import('data:text/javascript;base64,' + Buffer.from(built.outputFiles[0].text).toString('base64'));
const dom = new JSDOM('<img id="photo" src="/img/chair.png"><div id="hero" style="background-image:url(/img/chair.png)"></div>', { url: 'https://shop.test' });
globalThis.document = dom.window.document; globalThis.window = dom.window; globalThis.HTMLImageElement = dom.window.HTMLImageElement;
/* What the admin answers since the rules of /api/v1 (docs/woodex-os/29-API-V1-RULES.md): the list is "data", whole,
   with its size and the number of the request beside it; an error is {error: {code, …}, meta}. */
const answer = items => ({ data: items, meta: { request_id: '4F7K-9Q2M-T8RB', total: items.length } });
const switchedOff = () => Response.json({ error: { code: 'SWITCHED_OFF', message: 'This shop is not connected to the admin.', details: {} }, meta: { request_id: null } }, { status: 503 });
let manifest = answer([{ key: '/img/chair.png', url: '/api/v1/media/3/4' }]);
globalThis.fetch = async () => Response.json(manifest);
await api.syncMediaMappings();
assert.equal(document.querySelector('#photo').getAttribute('src'), '/api/v1/media/3/4');
assert.match(document.querySelector('#hero').getAttribute('style'), /\/api\/v1\/media\/3\/4/);
api.applyMediaMappings(); assert.equal(document.querySelector('#photo').getAttribute('src'), '/api/v1/media/3/4');
manifest = answer([{ key: '/img/chair.png', url: 'https://evil.test/pixel' }]);
await api.syncMediaMappings(); assert.equal(document.querySelector('#photo').getAttribute('src'), '/api/v1/media/3/4');
// The answer of before the rules ({schema, items}) is not a list this shop takes: the last good one stays.
manifest = { schema: 1, items: [{ key: '/img/chair.png', url: '/api/v1/media/3/9' }] };
await api.syncMediaMappings(); assert.equal(document.querySelector('#photo').getAttribute('src'), '/api/v1/media/3/4');
// An outage of the admin (503 without "switched off") keeps the last good list too.
globalThis.fetch = async () => Response.json({ error: { code: 'UNAVAILABLE', message: 'Later.', details: {} }, meta: { request_id: null } }, { status: 503 });
await api.syncMediaMappings(); assert.equal(document.querySelector('#photo').getAttribute('src'), '/api/v1/media/3/4');
globalThis.fetch = switchedOff;
await api.syncMediaMappings(); assert.equal(document.querySelector('#photo').getAttribute('src'), '/img/chair.png');
assert.match(document.querySelector('#hero').getAttribute('style'), /\/img\/chair.png/);
// Told "disabled", the shop does not ask again at once; ten minutes later it does.
let mediaAsked = 0;
globalThis.fetch = async () => { mediaAsked++; return Response.json(answer([{ key: '/img/chair.png', url: '/api/v1/media/3/5' }])); };
await api.syncMediaMappings(); assert.equal(mediaAsked, 0, 'After "disabled" the next poll is not sent');
const realNow = Date.now; Date.now = () => realNow() + 11 * 60_000;
await api.syncMediaMappings(); assert.equal(mediaAsked, 1, 'Ten minutes after "disabled" the shop asks again');
// The replacement must really be on the page before its failure is simulated: otherwise the fallback below proves nothing.
assert.equal(document.querySelector('#photo').getAttribute('src'), '/api/v1/media/3/5');
document.querySelector('#photo').dispatchEvent(new dom.window.Event('error'));
assert.equal(document.querySelector('#photo').getAttribute('src'), '/img/chair.png');
api.applyMediaMappings(); assert.equal(document.querySelector('#photo').getAttribute('src'), '/img/chair.png');
function reply() { return { statusCode: 200, status(c) { this.statusCode = c; return this; }, json(data) { this.data = data; return this; }, sendStatus(c) { this.statusCode = c; return this; }, set(h) { this.headers = h; return this; }, send(data) { this.data = data; return this; } }; }
const req = { path: '/api/v1/media', headers: { cookie: 'private', authorization: 'secret' } };
// What the bridge answers itself is in the API's error shape, each with its code.
const shaped = (res, status, code, number = null) => { assert.equal(res.statusCode, status); assert.deepEqual(Object.keys(res.data), ['error', 'meta']); assert.equal(res.data.error.code, code); assert.deepEqual(res.data.error.details, {}); assert.equal(res.data.meta.request_id, number); };
const on = { NATIVE_MEDIA_SYNC: '1', NATIVE_CATALOGUE_ORIGIN: 'https://laravel.test' };
let res = reply(); await nativeMediaBridge({})(req, res); shaped(res, 503, 'SWITCHED_OFF');
res = reply(); await nativeMediaBridge({ NATIVE_MEDIA_SYNC: '1', NATIVE_CATALOGUE_ORIGIN: 'http://unsafe.test', NODE_ENV: 'production' })(req, res); shaped(res, 503, 'UNAVAILABLE');
let seen;
globalThis.fetch = async (url, options) => { seen = { url, options }; return Response.json(answer([]), { headers: { 'X-Request-Id': '4F7K-9Q2M-T8RB' } }); };
res = reply(); await nativeMediaBridge(on)(req, res);
assert.equal(res.statusCode, 200); assert.equal(seen.url.pathname, '/api/v1/media'); assert.equal(seen.options.headers.authorization, undefined); assert.equal(seen.options.headers.cookie, undefined);
// The admin's answer is handed on as it is, with the number of its request.
assert.deepEqual(JSON.parse(res.data.toString()), answer([])); assert.equal(res.headers['X-Request-Id'], '4F7K-9Q2M-T8RB');
res = reply(); await nativeMediaBridge(on)({ path: '/api/v1/media/../admin' }, res); shaped(res, 404, 'NOT_FOUND');
// What the admin refuses is not handed on: the bridge says "not found" or "not available", with the admin's number.
globalThis.fetch = async () => Response.json({ error: { code: 'NOT_FOUND', message: 'The inside of the admin.', details: {} }, meta: { request_id: '4F7K-9Q2M-T8RB' } }, { status: 404, headers: { 'X-Request-Id': '4F7K-9Q2M-T8RB' } });
res = reply(); await nativeMediaBridge(on)({ path: '/api/v1/media/3/4' }, res); shaped(res, 404, 'NOT_FOUND', '4F7K-9Q2M-T8RB'); assert.doesNotMatch(JSON.stringify(res.data), /inside of the admin/);
globalThis.fetch = async () => new Response('fault', { status: 500, headers: { 'X-Request-Id': 'not a number <script>' } });
res = reply(); await nativeMediaBridge(on)(req, res); shaped(res, 503, 'UNAVAILABLE');
globalThis.fetch = async () => new Response('<html>', { headers: { 'content-type': 'text/html' } });
res = reply(); await nativeMediaBridge(on)(req, res); shaped(res, 502, 'BAD_GATEWAY');
globalThis.fetch = async () => { throw new Error('offline'); };
res = reply(); await nativeMediaBridge(on)(req, res); shaped(res, 503, 'UNAVAILABLE');
dom.window.close();
console.log('PASS media mapping, repeat render, switched-off rollback, unsafe and old-shape manifests, image-error fallback, bridge origin/path/auth boundaries and its error shape');

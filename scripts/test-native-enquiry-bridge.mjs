// The basket a visitor sends to the Laravel admin, carried by the shop's own server (master list T-191), and the
// key by which that server says for whom it asks (defect D-082).
//
// Run by CI beside the other bridges' scripts. It holds apps/api/native-enquiry-bridge.mjs and
// apps/api/native-bridge-key.mjs to what they say of themselves, without a server: the admin is a `fetch` that
// records what it was asked.
//
//   · off unless the product link is on, and then only the one address, only POST, only a JSON object;
//   · the body is handed on as it was sent, the admin's answer is handed back as it was given, status and all;
//   · the Idempotency-Key travels, and the visitor's browser name; a cookie, an authorization and a header of
//     the visitor's that begins X-Woodex- never do;
//   · the key this server shares with the admin, and the visitor's address beside it, travel only when a key
//     of a key's length is set: nothing is claimed without one;
//   · the same two travel with everything the shop reads of the admin (categories, products, photographs), so
//     that the admin counts its limit of reads by the visitor and not by this one server;
//   · a request that got no answer is told so (503), and an answer that is not JSON is not handed on (502).
//
// Tried against (release report 47): with the visitor's own X-Woodex-Key handed on, the assertion of that name
// fails; with the cookie handed on, the next one; with the admin's status flattened to 200, the refusal's.
import assert from 'node:assert/strict';
import { ENQUIRY_ADDRESSES, nativeEnquiryBridge } from '../apps/api/native-enquiry-bridge.mjs';
import { bridgeKey, vouching } from '../apps/api/native-bridge-key.mjs';
import { nativeCategoryBridge } from '../apps/api/native-category-bridge.mjs';
import { nativeMediaBridge } from '../apps/api/native-media-bridge.mjs';
import { nativeProductBridge } from '../apps/api/native-product-bridge.mjs';

const NUMBER = '4F7K-9Q2M-T8RB';
const KEY = '123e4567-e89b-42d3-a456-426614174000';
// Not a secret: a word of the right length, made for this script.
const SHARED = 'synthetic-bridge-key-for-this-script-only';
const on = { NATIVE_PRODUCT_SYNC: '1', NATIVE_CATALOGUE_ORIGIN: 'https://admin.example.test' };

const reply = () => ({
  statusCode: 200, headers: {},
  status(code) { this.statusCode = code; return this; },
  json(data) { this.data = data; return this; },
  set(first, second) { this.headers = { ...this.headers, ...(typeof first === 'string' ? { [first]: second } : first) }; return this; },
  send(data) { this.data = data; return this; },
});
const shaped = (res, status, code, number = null) => {
  assert.equal(res.statusCode, status);
  assert.deepEqual(Object.keys(res.data).sort(), ['error', 'meta']);
  assert.equal(res.data.error.code, code);
  assert.deepEqual(res.data.error.details, {});
  assert.equal(res.data.meta.request_id, number);
  assert.equal(res.headers['Cache-Control'], 'no-store, private', 'an answer about one visitor is kept by nothing in between');
};
const basket = { name: 'Synthetic visitor', email: 'visitor@example.test', consent: true, items: [{ slug: 'executive-desk', sku: null, qty: 2 }] };
const asked = (more = {}) => ({
  method: 'POST', path: '/api/v1/quote-requests', ip: '203.0.113.10', body: basket,
  headers: { 'content-type': 'application/json', 'idempotency-key': KEY, 'user-agent': 'A browser', ...(more.headers ?? {}) },
  ...Object.fromEntries(Object.entries(more).filter(([name]) => name !== 'headers')),
});

let seen = null;
const admin = (body, status = 201, headers = {}) => {
  globalThis.fetch = async (url, options) => {
    seen = { url, options };
    return new Response(typeof body === 'string' ? body : JSON.stringify(body), { status, headers: { 'content-type': 'application/json', 'x-request-id': NUMBER, ...headers } });
  };
};
const never = () => { globalThis.fetch = async () => { throw new Error('the bridge must not have asked the admin'); }; };
const saved = { data: { reference: 'QR-SYNTHETIC', status: 'pending_review' }, meta: { request_id: NUMBER } };

/* ── What it carries: the address for a basket, and no other ─────────────────────────────────────────────── */

assert.deepEqual(ENQUIRY_ADDRESSES, ['/api/v1/quote-requests']);

/* ── Off unless the product link is on ────────────────────────────────────────────────────────────────────── */

never();
let res = reply();
await nativeEnquiryBridge({})(asked(), res);
shaped(res, 503, 'SWITCHED_OFF');

res = reply();
await nativeEnquiryBridge({ ...on, NATIVE_PRODUCT_SYNC: '0' })(asked(), res);
shaped(res, 503, 'SWITCHED_OFF');

// On, and the address of the admin not set up safely: nothing is sent anywhere.
for (const origin of ['', 'http://admin.example.test', 'https://user:word@admin.example.test', 'https://admin.example.test/path']) {
  res = reply();
  await nativeEnquiryBridge({ ...on, NATIVE_CATALOGUE_ORIGIN: origin })(asked(), res);
  shaped(res, 503, 'UNAVAILABLE');
}
// A loopback address is for a rehearsal: never in production, and never on Vercel.
for (const more of [{ NODE_ENV: 'production' }, { VERCEL: '1' }]) {
  res = reply();
  await nativeEnquiryBridge({ ...on, NATIVE_CATALOGUE_ORIGIN: 'http://127.0.0.1:8195', ...more })(asked(), res);
  shaped(res, 503, 'UNAVAILABLE');
}

/* ── Only these addresses, only POST, only a JSON object of a size ────────────────────────────────────────── */

res = reply();
await nativeEnquiryBridge(on)(asked({ path: '/api/v1/orders' }), res);
shaped(res, 404, 'NOT_FOUND');
res = reply();
await nativeEnquiryBridge(on)(asked({ path: '/api/v1/quote-requests/../../admin' }), res);
shaped(res, 404, 'NOT_FOUND');

res = reply();
await nativeEnquiryBridge(on)(asked({ method: 'GET' }), res);
shaped(res, 405, 'METHOD_NOT_ALLOWED');
assert.equal(res.headers.Allow, 'POST');

res = reply();
await nativeEnquiryBridge(on)(asked({ headers: { 'content-type': 'application/x-www-form-urlencoded' }, body: {} }), res);
shaped(res, 415, 'UNSUPPORTED_MEDIA_TYPE');
res = reply();
await nativeEnquiryBridge(on)(asked({ headers: { 'content-type': 'text/plain' }, body: undefined }), res);
shaped(res, 415, 'UNSUPPORTED_MEDIA_TYPE');

for (const body of [undefined, null, [], [basket], 'text']) {
  res = reply();
  await nativeEnquiryBridge(on)(asked({ body }), res);
  shaped(res, 400, 'BAD_REQUEST');
}

res = reply();
await nativeEnquiryBridge(on)(asked({ body: { ...basket, message: 'x'.repeat(70 * 1024) } }), res);
shaped(res, 413, 'PAYLOAD_TOO_LARGE');

/* ── Carried: the body as it was sent, the answer as it was given ─────────────────────────────────────────── */

admin(saved, 201);
res = reply();
await nativeEnquiryBridge(on)(asked({ headers: {
  cookie: 'STAFF', authorization: 'Bearer SECRET', 'x-woodex-session': 'SESSION',
  // A visitor who writes the server's own headers himself: neither is handed on.
  'x-woodex-key': SHARED, 'x-woodex-visitor': '198.51.100.9',
} }), res);

assert.equal(String(seen.url), 'https://admin.example.test/api/v1/quote-requests');
assert.equal(seen.options.method, 'POST');
assert.equal(seen.options.redirect, 'error', 'an answer that sends the request elsewhere is not followed');
assert.deepEqual(JSON.parse(seen.options.body), basket, 'the body is handed on as it was sent');
assert.equal(seen.options.headers['Idempotency-Key'], KEY, 'the key of the request travels: sent twice, it is written once');
assert.equal(seen.options.headers['User-Agent'], 'A browser');
assert.equal(seen.options.headers['Content-Type'], 'application/json');
const sent = Object.fromEntries(Object.entries(seen.options.headers).map(([name, value]) => [name.toLowerCase(), value]));
assert.equal(sent['x-woodex-key'], undefined, 'the visitor’s own X-Woodex-Key is not handed on');
assert.equal(sent['x-woodex-visitor'], undefined, 'nor an address he names for himself');
assert.equal(sent.cookie, undefined, 'no cookie goes to the admin');
assert.equal(sent.authorization, undefined, 'no authorization goes to the admin');
assert.equal(sent['x-woodex-session'], undefined);
assert.deepEqual(Object.keys(sent).sort(), ['accept', 'content-type', 'idempotency-key', 'user-agent'], 'and nothing else travels');

assert.equal(res.statusCode, 201);
assert.equal(res.data, JSON.stringify(saved), 'the admin’s answer is handed back unchanged, byte for byte');
assert.equal(res.headers['X-Request-Id'], NUMBER, 'the number of the admin’s request travels with its answer');
assert.equal(res.headers['Cache-Control'], 'no-store, private');
assert.equal(res.headers['Idempotent-Replayed'], undefined);

// The address goes to the same address there, at the admin's origin and nowhere else.
for (const path of ENQUIRY_ADDRESSES) {
  res = reply();
  await nativeEnquiryBridge(on)(asked({ path }), res);
  assert.equal(seen.url.href, 'https://admin.example.test' + path);
  assert.equal(seen.options.redirect, 'error', 'a redirect is an error: what is carried follows the admin nowhere');
  assert.equal(res.statusCode, 201);
}
// The admin's addresses for an enquiry without a basket are not carried: the shop's forms have not moved (T-100).
never();
for (const path of ['/api/v1/leads', '/api/v1/leads/whatsapp-handoff', '/api/v1/quote-requests/1']) {
  res = reply();
  await nativeEnquiryBridge({ ...on, NATIVE_BRIDGE_KEY: SHARED })(asked({ path }), res);
  shaped(res, 404, 'NOT_FOUND');
}
admin(saved, 201);

// A key that is not a key is not handed on as one: the admin then says that it needs one.
res = reply();
await nativeEnquiryBridge(on)(asked({ headers: { 'idempotency-key': 'not-a-uuid' } }), res);
assert.equal(seen.options.headers['Idempotency-Key'], undefined);

// A second sending, answered as the first was: the admin says so, and so does this answer.
admin(saved, 201, { 'idempotent-replayed': 'true' });
res = reply();
await nativeEnquiryBridge(on)(asked(), res);
assert.equal(res.headers['Idempotent-Replayed'], 'true');

/* ── What the admin refuses is a refusal here too, with its status, its code and its number ────────────────── */

const refusal = { error: { code: 'VALIDATION_FAILED', message: 'Not saved.', details: { 'items.0.slug': ['This product is not offered at the moment.'] } }, meta: { request_id: NUMBER } };
admin(refusal, 422);
res = reply();
await nativeEnquiryBridge(on)(asked(), res);
assert.equal(res.statusCode, 422, 'a refusal of the admin is not a success of the shop');
assert.deepEqual(JSON.parse(res.data), refusal);
assert.equal(res.headers['X-Request-Id'], NUMBER);

admin({ error: { code: 'TOO_MANY_REQUESTS', message: 'Wait.', details: {} }, meta: { request_id: NUMBER } }, 429, { 'retry-after': '37' });
res = reply();
await nativeEnquiryBridge(on)(asked(), res);
assert.equal(res.statusCode, 429);
assert.equal(res.headers['Retry-After'], '37', 'how long to wait is handed back');

// The part of the admin that takes baskets is switched off there: its "not found" is handed back as it is.
admin({ error: { code: 'NOT_FOUND', message: 'Nothing is at this address.', details: {} }, meta: { request_id: NUMBER } }, 404);
res = reply();
await nativeEnquiryBridge(on)(asked(), res);
assert.equal(res.statusCode, 404);
assert.equal(JSON.parse(res.data).error.code, 'NOT_FOUND');

/* ── The key both servers hold, and the visitor it lets this server name ──────────────────────────────────── */

assert.equal(bridgeKey({}), '');
assert.equal(bridgeKey({ NATIVE_BRIDGE_KEY: 'short' }), '', 'a word somebody typed is not a key');
assert.equal(bridgeKey({ NATIVE_BRIDGE_KEY: ` ${SHARED} ` }), SHARED);

admin(saved, 201);
res = reply();
await nativeEnquiryBridge({ ...on, NATIVE_BRIDGE_KEY: SHARED })(asked({ headers: { 'x-woodex-visitor': '198.51.100.9' } }), res);
assert.equal(seen.options.headers['X-Woodex-Key'], SHARED, 'with a key set, the request says who carried it');
assert.equal(seen.options.headers['X-Woodex-Visitor'], '203.0.113.10', 'and whom it was carried for: the address this server saw, not one the visitor named');

// An address written the long way round (IPv4 inside IPv6) is named the short way; no address, none.
res = reply();
await nativeEnquiryBridge({ ...on, NATIVE_BRIDGE_KEY: SHARED })(asked({ ip: '::ffff:203.0.113.44' }), res);
assert.equal(seen.options.headers['X-Woodex-Visitor'], '203.0.113.44');
res = reply();
await nativeEnquiryBridge({ ...on, NATIVE_BRIDGE_KEY: SHARED })(asked({ ip: undefined }), res);
assert.equal(seen.options.headers['X-Woodex-Key'], SHARED);
assert.equal(seen.options.headers['X-Woodex-Visitor'], undefined);

// A key too short to be one claims nothing: neither header is sent.
res = reply();
await nativeEnquiryBridge({ ...on, NATIVE_BRIDGE_KEY: 'short' })(asked(), res);
assert.equal(seen.options.headers['X-Woodex-Key'], undefined);
assert.equal(seen.options.headers['X-Woodex-Visitor'], undefined);

/* ── The same key with everything the shop reads of the admin (defect D-082) ──────────────────────────────────
   The admin allows one address 120 reads a minute. Every visitor's read reaches it from this one server, so the
   whole shop was one address: an open page asks nine times a minute, and about fourteen use the minute up.
   Each bridge that reads therefore says for whom it asks, exactly as the one above does for a basket. */

assert.deepEqual(vouching({}, { ip: '203.0.113.10' }), {}, 'without a key nothing is claimed');
assert.deepEqual(vouching({ NATIVE_BRIDGE_KEY: SHARED }, { ip: '203.0.113.10' }), { 'X-Woodex-Key': SHARED, 'X-Woodex-Visitor': '203.0.113.10' });
assert.deepEqual(vouching({ NATIVE_BRIDGE_KEY: SHARED }, { ip: 'not an address' }), { 'X-Woodex-Key': SHARED }, 'what is not an address is not named as one');

const reading = { ...on, NATIVE_MEDIA_SYNC: '1' };
const picture = () => { globalThis.fetch = async (url, options) => { seen = { url, options }; return new Response(new Uint8Array([137, 80, 78, 71]), { status: 200, headers: { 'content-type': 'image/png', 'x-request-id': NUMBER } }); }; };
// What a visitor's browser sends of its own is not this server's word: neither name is read from the request.
const visitorSays = { 'x-woodex-key': 'a-key-the-visitor-made-up-of-the-right-length', 'x-woodex-visitor': '198.51.100.9', cookie: 'session=abc', authorization: 'Bearer abc' };
const reads = [
  ['the categories', nativeCategoryBridge, '/api/v1/catalogue/categories', false],
  ['a category’s picture', nativeCategoryBridge, '/api/v1/catalogue/categories/5/hero/2', true],
  ['the replaced photographs', nativeMediaBridge, '/api/v1/media', false],
  ['one replaced photograph', nativeMediaBridge, '/api/v1/media/7/3', true],
  ['the products', nativeProductBridge, '/api/products', false],
  ['one product', nativeProductBridge, '/api/products/executive-desk', false],
  ['a product’s photograph', nativeProductBridge, '/api/v1/catalogue/products/executive-desk/picture/1/4', true],
];
for (const [what, bridge, path, bytes] of reads) {
  const request = { method: 'GET', path, query: {}, ip: '::ffff:203.0.113.10', headers: visitorSays };
  if (bytes) picture(); else admin({ data: [], meta: { request_id: NUMBER } }, 200);

  res = reply();
  await bridge({ ...reading, NATIVE_BRIDGE_KEY: SHARED })(request, res);
  assert.equal(res.statusCode, 200, what);
  assert.equal(new URL(seen.url).origin, 'https://admin.example.test', `${what}: the key goes to the admin and nowhere else`);
  assert.equal(seen.options.redirect, 'error', `${what}: and follows no redirect`);
  assert.equal(seen.options.headers['X-Woodex-Key'], SHARED, `${what}: the read says who carried it`);
  assert.equal(seen.options.headers['X-Woodex-Visitor'], '203.0.113.10', `${what}: and for whom, by the address this server saw`);
  for (const name of ['cookie', 'Cookie', 'authorization', 'Authorization', 'x-woodex-key', 'x-woodex-visitor']) {
    assert.equal(seen.options.headers[name], undefined, `${what}: the visitor's own ${name} is not handed on`);
  }

  res = reply();
  await bridge(reading)(request, res);
  assert.equal(res.statusCode, 200);
  assert.equal(seen.options.headers['X-Woodex-Key'], undefined, `${what}: without a key nothing is claimed`);
  assert.equal(seen.options.headers['X-Woodex-Visitor'], undefined);
}
admin(saved, 201);

/* ── No answer, and an answer that cannot be read ─────────────────────────────────────────────────────────── */

globalThis.fetch = async () => { throw new Error('connect ECONNREFUSED'); };
res = reply();
await nativeEnquiryBridge(on)(asked(), res);
shaped(res, 503, 'UNAVAILABLE');
assert.match(res.data.error.message, /Sent again, it is the same request/);

globalThis.fetch = async () => new Response('<html>Service unavailable</html>', { status: 503, headers: { 'content-type': 'text/html', 'x-request-id': NUMBER } });
res = reply();
await nativeEnquiryBridge(on)(asked(), res);
shaped(res, 502, 'BAD_GATEWAY', NUMBER);

admin('{"data": not json', 201);
res = reply();
await nativeEnquiryBridge(on)(asked(), res);
shaped(res, 502, 'BAD_GATEWAY', NUMBER);

admin('"a word"', 201);
res = reply();
await nativeEnquiryBridge(on)(asked(), res);
shaped(res, 502, 'BAD_GATEWAY', NUMBER);

console.log('Native enquiry bridge: off unless the product link is on, the one address for a basket and POST only, the body and the answer carried unchanged, no cookie and no borrowed header, the visitor named only beside the shared key, by the basket and by every read alike, no answer and an unreadable answer told apart.');

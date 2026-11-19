import assert from 'node:assert/strict';
import { build } from 'esbuild';

/* The shop's caller of the Laravel admin's basket address, /api/v1/quote-requests. Since the rules of /api/v1
   (docs/woodex-os/29-API-V1-RULES.md, master list T-063) the admin answers {data, meta} or {error, meta}, and the
   key of the request travels in the Idempotency-Key header: it is no field of the form any more. */
const built = await build({ entryPoints: ['apps/storefront/src/lib/quoteCapture.ts'], bundle: true, platform: 'node', format: 'esm', write: false });
const { captureBasketRequest: send } = await import('data:text/javascript;base64,' + Buffer.from(built.outputFiles[0].text).toString('base64'));
const NUMBER = '4F7K-9Q2M-T8RB';
const ok = data => ({ data, meta: { request_id: NUMBER } });
const refused = code => ({ error: { code, message: 'Not for the visitor.', details: {} }, meta: { request_id: NUMBER } });
let seen;
const mock = (body, status = 200) => async (url, options) => { seen = { url, body: JSON.parse(options.body), headers: options.headers }; return new Response(JSON.stringify(body), { status }); };
const KEY = '123e4567-e89b-42d3-a456-426614174000';
const input = { customer: 'Synthetic basket', email: 'basket@example.test', requestKey: KEY, contactConsent: true, items: [{ productId: 'external-node-key', qty: 2, price: 1, name: 'tampered' }] };
await send(input, 'legacy', mock({ id: 1 })); assert.equal(seen.url, '/api/quotes'); assert.deepEqual(seen.body, input);
const result = await send(input, 'v1', mock(ok({ reference: 'QR-SYNTHETIC', status: 'pending_review' }), 201));
assert.equal(seen.url, '/api/v1/quote-requests'); assert.deepEqual(seen.body.items, [{ product_id: 'external-node-key', qty: 2 }]);
assert.equal(seen.headers['Idempotency-Key'], KEY, 'the page\'s key of the request is sent as the header');
assert.equal(seen.body.request_key, undefined, '…and is no field of the form'); assert.equal(seen.body.name, input.customer);
assert.equal(result.ref, 'QR-SYNTHETIC'); assert.equal(result.id, undefined); assert.equal(result.handoffUnavailable, true);
await assert.rejects(send(input, 'v1', mock({}), true), /verification/);
await assert.rejects(send({ ...input, items: [{ productId: 'x', qty: 1, material: 'Unmapped colour' }] }, 'v1', mock({})), /team review/);
for (const status of [409, 422, 429, 500]) await assert.rejects(send(input, 'v1', mock({}, status)));
// The key was used for another basket: the visitor is told to start again, and given the number of the request.
await assert.rejects(send(input, 'v1', mock(refused('IDEMPOTENCY_KEY_REUSED'), 409)), error => /basket details changed/.test(error.message) && error.message.includes(NUMBER));
await assert.rejects(send(input, 'v1', mock(refused('CONFLICT'), 409)), /send it again/);
await assert.rejects(send(input, 'v1', mock(refused('VALIDATION_FAILED'), 422)), /could not be confirmed/);
await assert.rejects(send(input, 'v1', mock(refused('TOO_MANY_REQUESTS'), 429)), /wait a minute/);
// Taken for a robot's (202, nothing kept), and the answer of before the rules: neither is a confirmation.
await assert.rejects(send(input, 'v1', mock(ok({ reference: null, status: null }), 202)), /not confirmed/);
await assert.rejects(send(input, 'v1', mock({ ok: true, reference: 'QR-OLD-SHAPE', status: 'pending_review' }, 201)), /not confirmed/);
await assert.rejects(send(input, 'v1', mock(ok({ reference: 'QR-SYNTHETIC', status: 'issued' }), 201)), /not confirmed/);
// A page that gives no key gets one here: the same for the same basket until it is answered.
const { requestKey, ...keyless } = input;
await assert.rejects(send(keyless, 'v1', mock(refused('SERVER_ERROR'), 500)));
const first = seen.headers['Idempotency-Key'];
assert.match(first, /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/);
await send(keyless, 'v1', mock(ok({ reference: 'QR-SYNTHETIC', status: 'pending_review' }), 201));
assert.equal(seen.headers['Idempotency-Key'], first, 'after a fault, the second try carries the first one\'s key');
await send(keyless, 'v1', mock(ok({ reference: 'QR-SYNTHETIC-2', status: 'pending_review' }), 201));
assert.notEqual(seen.headers['Idempotency-Key'], first, 'once answered, the next basket is a new request');
await assert.rejects(send(input, 'typo', mock({})), /Unsupported/);
console.log('Basket adapter passed: opt-in routes, server-price-only mapping, the key as a header, challenge, variants, errors, no numeric handoff.');

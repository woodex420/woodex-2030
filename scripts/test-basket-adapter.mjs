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

/* A basket of products that were read from the Laravel admin (master list T-191): each line names the product by
   its address, which is this shop's id of such a product, and the option the visitor chose by its code. Nothing
   the page shows of a name or a price is sent, and the shop's own number of an option is not: the admin does not
   know it. */
const fromAdmin = { customer: 'Synthetic basket', email: 'basket@example.test', requestKey: KEY, contactConsent: true, items: [
  { productId: 'executive-desk', qty: 2, price: 1, name: 'tampered', sku: 'ET-10010' },
  { productId: 'two-finish-desk', qty: 1, variantId: 123456789, sku: 'TF-OAK', name: 'tampered' },
] };
const kept = await send(fromAdmin, 'admin', mock(ok({ reference: 'QR-SYNTHETIC', status: 'pending_review' }), 201));
assert.equal(seen.url, '/api/v1/quote-requests', 'the same address, asked of the origin the page stands on');
assert.deepEqual(seen.body.items, [
  // A product sold in one form is named by its address alone: its own code may change in the admin meanwhile.
  { slug: 'executive-desk', qty: 2 },
  { slug: 'two-finish-desk', sku: 'TF-OAK', qty: 1 },
], 'a line names the product by its address and the option by its code, and nothing else');
assert.equal(seen.headers['Idempotency-Key'], KEY);
assert.equal(seen.body.consent, true); assert.equal(seen.body.name, fromAdmin.customer);
assert.equal(kept.ref, 'QR-SYNTHETIC'); assert.equal(kept.handoffUnavailable, true);
// The robot check is asked of the visitor where the page has one to offer, as for the other route.
await assert.rejects(send(fromAdmin, 'admin', mock({}), true), /verification/);
// What the admin refuses is said in the admin's own sentence: which product, and why.
const lineRefused = { error: { code: 'VALIDATION_FAILED', message: 'Not saved.', details: { 'items.1.slug': ['The option chosen is not offered any more. Choose one on the product\'s page and add it to the basket again.'] } }, meta: { request_id: NUMBER } };
await assert.rejects(send(fromAdmin, 'admin', mock(lineRefused, 422)), error => /could not be saved\. The option chosen is not offered any more/.test(error.message) && error.message.includes(NUMBER));
// The part of the admin that takes baskets is switched off there: not a confirmation, and not a silence.
await assert.rejects(send(fromAdmin, 'admin', mock(refused('NOT_FOUND'), 404)), /could not be confirmed/);
// The shop's server could not reach the admin: the visitor is told to send it again, which is the same request.
await assert.rejects(send(fromAdmin, 'admin', mock(refused('UNAVAILABLE'), 503)), /send it again/);

/* Where a basket goes is decided when the page runs, by where its catalogue came from (master list T-191,
   decision DL-050). A catalogue read from the admin can be priced by the admin alone, so its basket goes there
   whatever the site was built with; a catalogue of the shop's own database goes where the build says, as before.
   Read from the page's own module, built twice: as every site is built today, and with the older setting. */
const path = await import('node:path');
const runtimeBuiltWith = async mode => {
  const bundle = await build({
    stdin: { contents: "export { basketDestination } from '@/lib/runtime'; export { catalog } from '@/data/products';", resolveDir: path.resolve('apps/storefront/src'), loader: 'ts' },
    bundle: true, platform: 'node', format: 'esm', write: false, logLevel: 'silent',
    alias: { '@': path.resolve('apps/storefront/src') },
    define: { 'import.meta.env.VITE_QUOTE_API_MODE': JSON.stringify(mode), 'import.meta.env': '{}' },
  });
  return import('data:text/javascript;base64,' + Buffer.from(bundle.outputFiles[0].text).toString('base64'));
};
for (const [mode, own] of [['', 'legacy'], ['v1', 'v1']]) {
  const page = await runtimeBuiltWith(mode);
  assert.equal(page.catalog.source, 'shop', 'until a catalogue is read, nothing is taken for the admin’s');
  assert.equal(page.basketDestination(), own, `built with "${mode}", the shop's own catalogue goes where the build says`);
  page.catalog.source = 'admin';
  assert.equal(page.basketDestination(), 'admin', `built with "${mode}", a catalogue of the admin goes to the admin`);
  page.catalog.source = 'shop';
  assert.equal(page.basketDestination(), own, 'and back, when the catalogue is the shop’s own again');
}

console.log('Basket adapter passed: opt-in routes, server-price-only mapping, a product of the admin by its address and its option by its code, the key as a header, challenge, variants, errors, no numeric handoff; the destination follows the catalogue, not the build.');

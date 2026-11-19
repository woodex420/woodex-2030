import assert from 'node:assert/strict';
import { build } from 'esbuild';

/* The shop's caller of the Laravel admin's enquiry address, /api/v1/leads, and of the WhatsApp hand-off.
   Since the rules of /api/v1 (docs/woodex-os/29-API-V1-RULES.md, master list T-063) the admin answers
   {data, meta} or {error, meta}, and a create carries the key of its request in the Idempotency-Key header. */
const built = await build({ entryPoints: ['apps/storefront/src/lib/leadCapture.ts'], bundle: true, platform: 'node', format: 'esm', write: false });
const { furnitureLeadPayload: map, captureStorefrontLead: send, redeemLeadHandoff: redeem } = await import('data:text/javascript;base64,' + Buffer.from(built.outputFiles[0].text).toString('base64'));
const input = { name: 'Synthetic Visitor', email: 'visitor@example.test', company: 'Synthetic Co', interest: 'Desk', note: 'Synthetic enquiry', source: 'Contact Form', contactConsent: true, preferredChannel: 'whatsapp', utmSource: 'test', landingPath: '/contact' };
const mapped = map(input);
assert.equal(mapped.source, 'website_form');
assert.equal(mapped.form, 'Contact Form');
assert.equal(mapped.company, 'Synthetic Co');
assert.equal(mapped.consent, true);
assert.equal(mapped.preferred_channel, 'whatsapp');
assert.equal(mapped.utm_source, 'test');
assert.equal(mapped.message, 'Desk\nSynthetic enquiry');
assert.equal(map({ name: 'Synthetic', contact: '+92 300 1234567' }).phone, '+92 300 1234567');
assert.equal(map({ name: 'Synthetic', contact: 'v@example.test' }).email, 'v@example.test');
assert.equal(map({ name: 'Synthetic', contact: 'v@example.test' }).consent, false);
assert.throws(() => map({ name: 'Synthetic', contact: 'call my office' }), /phone number or email/);
assert.throws(() => map({ name: 'Synthetic' }), /phone number or email/);
assert.equal(map({ ...input, utmSource: 'a'.repeat(200) }).utm_source.length, 120);
assert.ok(map({ ...input, interest: 'a'.repeat(100) }).message.includes('a'.repeat(100)));
assert.equal(map({ ...input, interest: 'a'.repeat(100) }).service, null);

/* What the admin answers, in its two shapes. */
const NUMBER = '4F7K-9Q2M-T8RB';
const ok = data => ({ data, meta: { request_id: NUMBER } });
const refused = (code, details = {}) => ({ error: { code, message: 'Not for the visitor.', details }, meta: { request_id: NUMBER } });
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/;
let seen;
const mock = (body, status = 200, headers = {}) => async (url, options) => {
  seen = { url, body: JSON.parse(options.body), headers: options.headers };
  return new Response(JSON.stringify(body), { status, headers });
};
const keyOf = () => seen.headers['Idempotency-Key'];

await send(input, 'legacy', mock({ id: 12 }));
assert.equal(seen.url, '/api/leads');
assert.deepEqual(seen.body, input);
assert.equal(keyOf(), undefined, 'the old API is sent no key');

const result = await send(input, 'v1', mock(ok({ reference: 'SYNTHETIC-REF', handoff: null }), 201));
assert.equal(seen.url, '/api/v1/leads');
assert.equal(result.ref, 'SYNTHETIC-REF');
assert.equal(result.id, undefined);
assert.equal(result.handoffUnavailable, true);
assert.equal(seen.body.business_line, 'furniture');
assert.match(keyOf(), UUID, 'an enquiry carries the key of its request, a UUID');
assert.equal(seen.body.request_key, undefined, 'the key is a header, not a field');
// Taken for a robot's: it looks accepted, and nothing was kept.
await assert.rejects(send(input, 'v1', mock(ok({ reference: null, handoff: null }), 202)), /not confirmed/);
await assert.rejects(send(input, 'v1', mock(refused('VALIDATION_FAILED'), 422)), /could not be saved/);
await assert.rejects(send(input, 'v1', mock(refused('TOO_MANY_REQUESTS'), 429)), /wait a minute/);
// An answer that is not in the API's shape is no confirmation, whatever its status says.
await assert.rejects(send(input, 'v1', mock({ ok: true, reference: 'OLD-SHAPE' })), /not confirmed/);
await assert.rejects(send(input, 'v1', mock({}, 422)), /could not be saved/);
await assert.rejects(send(input, 'v1', async () => { throw new Error('offline'); }), /offline/);
await assert.rejects(send(input, 'unknown', mock({})), /Unsupported/);
console.log('Storefront lead adapter contract checks passed.');

// What the admin says about a refused field reaches the visitor, with the number of the request.
await assert.rejects(
  send(input, 'v1', mock(refused('VALIDATION_FAILED', { phone: ['Please enter a valid phone number, for example 0322 4000768.'] }), 422)),
  error => /could not be saved\. Please enter a valid phone number/.test(error.message) && error.message.includes(`give this number: ${NUMBER}.`) && !error.message.includes('Not for the visitor'),
);
await assert.rejects(send(input, 'v1', mock(refused('CONFLICT'), 409)), /send it again/);
// The number is taken from the header when the body has none, and only when it looks like one.
await assert.rejects(send(input, 'v1', mock({}, 500, { 'X-Request-Id': NUMBER })), error => error.message.includes(NUMBER));
await assert.rejects(send(input, 'v1', mock({ meta: { request_id: '<script>' } }, 500)), error => !error.message.includes('script') && !error.message.includes('number'));
console.log('Refused fields and the request number reach the visitor.');

/* One key for one enquiry. A try that got no answer, or a fault, is the same request when it is sent again;
   an enquiry that was answered, or refused for good, is over, and the next one is new. */
const other = { ...input, note: 'A second, different enquiry' };
await assert.rejects(send(other, 'v1', async (url, options) => { seen = { headers: options.headers }; throw new Error('offline'); }), /offline/);
const first = keyOf();
await assert.rejects(send(other, 'v1', mock(refused('SERVER_ERROR'), 500)), /could not be saved/);
assert.equal(keyOf(), first, 'after no answer, the second try carries the first one\'s key');
await assert.rejects(send({ ...other, turnstile_token: 'a-new-token' }, 'v1', mock(refused('TOO_MANY_REQUESTS'), 429)), /wait a minute/);
assert.equal(keyOf(), first, 'a new token of the robot check is still the same enquiry');
await send(other, 'v1', mock(ok({ reference: 'SYNTHETIC-SAVED', handoff: null }), 201));
assert.equal(keyOf(), first, 'after a fault and "too many", the same key again');
await send(other, 'v1', mock(ok({ reference: 'SYNTHETIC-AGAIN', handoff: null }), 201));
assert.notEqual(keyOf(), first, 'once it was answered, the same form sent again is a new enquiry');
const afterAnswer = keyOf();
await assert.rejects(send({ ...other, note: 'Changed' }, 'v1', mock(refused('VALIDATION_FAILED'), 422)), /could not be saved/);
const refusedKey = keyOf();
assert.notEqual(refusedKey, afterAnswer, 'other content, another key');
await send({ ...other, note: 'Changed' }, 'v1', mock(ok({ reference: 'SYNTHETIC-CORRECTED', handoff: null }), 201));
assert.notEqual(keyOf(), refusedKey, 'a request that was refused is over: the next try is a new one');
// A page that names the key of its own request is heard.
await send({ ...input, requestKey: '123e4567-e89b-42d3-a456-426614174000' }, 'v1', mock(ok({ reference: 'SYNTHETIC-PAGE', handoff: null }), 201));
assert.equal(keyOf(), '123e4567-e89b-42d3-a456-426614174000');
console.log('One key for one enquiry: kept while the request is open, new once it is over.');

let requested = false;
await assert.rejects(send(input, 'v1', async () => { requested = true; }, true), /complete enquiry verification/);
assert.equal(requested, false);
await send({ ...input, turnstile_token: 'synthetic-token' }, 'v1', mock(ok({ reference: 'SYNTHETIC-VERIFIED', handoff: null })), true);
assert.equal(seen.body.turnstile_token, 'synthetic-token');
console.log('Strict client verification checks passed.');

const capability = 'a'.repeat(64);
const capable = await send(input, 'v1', mock(ok({ reference: 'SYNTHETIC', handoff: { token: capability, expires_at: '2026-11-11T22:00:00Z' } })));
assert.equal(capable.handoffToken, capability);
assert.equal(capable.handoffUnavailable, false);
assert.equal((await send(input, 'v1', mock(ok({ reference: 'SYNTHETIC', handoff: { token: 'not-a-token', expires_at: '2026-11-11T22:00:00Z' } })))).handoffUnavailable, true);
const href = 'https://wa.me/923001234567?text=SYNTHETIC';
assert.equal(await redeem(capability, mock(ok({ delivery: 'manual_handoff_not_sent', whatsapp_url: href }))), href);
assert.equal(seen.url, '/api/v1/leads/whatsapp-handoff');
assert.deepEqual(seen.body, { token: capability });
assert.equal(keyOf(), undefined, 'the token is the key of a hand-off');
await assert.rejects(redeem('12', mock({})), /Invalid handoff/);
await assert.rejects(redeem(capability, mock(refused('NOT_FOUND'), 404)), /expired or is unavailable/);
await assert.rejects(redeem(capability, mock(refused('TOO_MANY_REQUESTS'), 429)), /retry shortly/);
await assert.rejects(redeem(capability, mock(refused('UNAVAILABLE'), 503)), /retry shortly/);
await assert.rejects(redeem(capability, mock(ok({ delivery: 'sent', whatsapp_url: href }))), /not confirmed/);
await assert.rejects(redeem(capability, mock(ok({ delivery: 'manual_handoff_not_sent', whatsapp_url: 'https://evil.example.test' }))), /not confirmed/);
// The answer of before the rules ({logged, delivery, whatsapp_url}) is not taken for a confirmation.
await assert.rejects(redeem(capability, mock({ logged: true, delivery: 'manual_handoff_not_sent', whatsapp_url: href })), /not confirmed/);
console.log('Secure handoff adapter checks passed; no numeric ID, capability only in POST body.');

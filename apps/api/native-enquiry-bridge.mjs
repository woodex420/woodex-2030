// What a visitor of the shop writes to the Laravel admin, carried there by the shop's own server (master list
// T-191).
//
// With the product link on (NATIVE_PRODUCT_SYNC=1) the shop shows products that only the admin has. A basket of
// such products can be priced by nobody but the admin: this shop's own database does not hold them, and its own
// address for a basket (/api/quotes) refused every one of them (defect D-060). The shop's pages ask the origin
// they stand on, so the admin's address for a basket is answered here and handed on to the same address there:
//
//   POST /api/v1/quote-requests            a basket, saved as an enquiry with its lines
//
// One address, because one is needed. The admin's two addresses for an enquiry without a basket are not carried:
// the shop's forms still write to this shop's own database, and moving them is a line of its own (T-100).
//
// It carries; it decides nothing. The body is handed on as it was sent, the admin's answer is handed back as it
// was given, status and all, and the number of the admin's request with it. The admin prices the basket from
// what it published, keeps the enquiry, and is the only one that writes: this shop's database is not touched.
//
// What travels, and what never does:
//   · the JSON body, the Idempotency-Key (one request, however often it is sent) and the visitor's browser name;
//   · never a cookie, never an authorization, and never a header of the visitor's that begins X-Woodex-: the two
//     below are written here or not at all.
//   · X-Woodex-Key and X-Woodex-Visitor, when this server was given the key it shares with the admin
//     (native-bridge-key.mjs): the proof that the request was carried by the shop's own server, and the address
//     it came from, so that the admin counts its limit of enquiries by the visitor and not by this one server.
//     Without a key nothing is claimed: the admin then asks for its own robot check, and counts by the address
//     it sees.
//
// Answers of its own are in the API's error shape (docs/woodex-os/29-API-V1-RULES.md) with two codes the admin
// does not have, as the other bridges: SWITCHED_OFF (503), and BAD_GATEWAY (502) for an answer of the admin
// that is not JSON. A request that got no answer is told so with 503: it carries its key, and sent again it is
// the same request.
import { adminOrigin, productSyncOn } from './native-product-bridge.mjs';
import { vouching } from './native-bridge-key.mjs';

/** The addresses carried, and no others. */
export const ENQUIRY_ADDRESSES = ['/api/v1/quote-requests'];

const REQUEST_NUMBER = /^[0-9A-HJKMNP-TV-Z]{4}(?:-[0-9A-HJKMNP-TV-Z]{4}){2}$/;
const UUID = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;
/** A basket of fifty lines with a long message is a few thousand characters. */
const BODY_AT_MOST = 64 * 1024;

const numberOf = response => { const value = response?.headers?.get?.('x-request-id'); return typeof value === 'string' && REQUEST_NUMBER.test(value) ? value : null; };
const problem = (res, status, code, message, requestId = null) => res.status(status)
  .set({ 'Cache-Control': 'no-store, private', 'X-Content-Type-Options': 'nosniff' })
  .json({ error: { code, message, details: {} }, meta: { request_id: requestId } });

export function nativeEnquiryBridge(env = process.env) {
  return async (req, res) => {
    if (!productSyncOn(env)) return problem(res, 503, 'SWITCHED_OFF', 'This shop is not connected to the admin.');
    if (!ENQUIRY_ADDRESSES.includes(req.path)) return problem(res, 404, 'NOT_FOUND', 'Nothing is at this address.');
    if (req.method !== 'POST') {
      res.set('Allow', 'POST');
      return problem(res, 405, 'METHOD_NOT_ALLOWED', 'This address takes POST.');
    }

    const origin = adminOrigin(env);
    if (!origin) return problem(res, 503, 'UNAVAILABLE', 'The address of the admin is not set up safely.');

    // JSON, and an object: what the admin's own door asks. Said here, because a body that is not JSON was never
    // read by this server, and handing on an empty one would turn "not JSON" into "the name is missing".
    const type = String(req.headers?.['content-type'] ?? '').toLowerCase();
    if (!/^application\/(?:[a-z0-9.+-]+\+)?json(?:\s*;|$)/.test(type)) return problem(res, 415, 'UNSUPPORTED_MEDIA_TYPE', 'The body must be JSON.');
    if (!req.body || typeof req.body !== 'object' || Array.isArray(req.body)) return problem(res, 400, 'BAD_REQUEST', 'The body is not a JSON object.');
    const body = JSON.stringify(req.body);
    if (Buffer.byteLength(body) > BODY_AT_MOST) return problem(res, 413, 'PAYLOAD_TOO_LARGE', 'The request is larger than this shop takes.');

    const headers = { 'Content-Type': 'application/json', Accept: 'application/json' };
    const key = String(req.headers?.['idempotency-key'] ?? '');
    if (UUID.test(key)) headers['Idempotency-Key'] = key;
    const browser = String(req.headers?.['user-agent'] ?? '').replace(/[^\x20-\x7e]/g, '').slice(0, 255);
    if (browser) headers['User-Agent'] = browser;
    Object.assign(headers, vouching(env, req));

    let response;
    try {
      response = await fetch(new URL(req.path, origin), { method: 'POST', headers, body, redirect: 'error', signal: AbortSignal.timeout(8000) });
    } catch {
      return problem(res, 503, 'UNAVAILABLE', 'The admin could not be reached, and it is not known whether the request arrived. Sent again, it is the same request.');
    }

    const number = numberOf(response);
    const answered = (response.headers.get('content-type') || '').includes('application/json') ? await response.text().catch(() => '') : '';
    let parsed = null;
    try { parsed = answered ? JSON.parse(answered) : null; } catch { /* not JSON after all: said below */ }
    if (!parsed || typeof parsed !== 'object' || answered.length > 256 * 1024) {
      return problem(res, 502, 'BAD_GATEWAY', 'The admin did not answer in a way this shop can read.', number);
    }

    const out = { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store, private', 'X-Content-Type-Options': 'nosniff' };
    if (number) out['X-Request-Id'] = number;
    for (const name of ['idempotent-replayed', 'retry-after']) {
      const value = response.headers.get(name);
      if (value && /^[\x20-\x7e]{1,40}$/.test(value)) out[name === 'retry-after' ? 'Retry-After' : 'Idempotent-Replayed'] = value;
    }
    return res.status(response.status).set(out).send(answered);
  };
}

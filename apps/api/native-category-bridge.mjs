// Public read-only bridge. Never forwards cookies, authorization or staff routes. It says for which visitor it
// asks, when it holds the key it shares with the admin (native-bridge-key.mjs).
//
// It stands in front of an address of the Laravel admin's /api/v1 and answers at the same address on the shop's
// own site. What the admin answers with success is handed on as it is: {data, meta} for the list, the bytes for
// a picture. Where this bridge answers itself, it uses the API's own error shape (docs/woodex-os/29-API-V1-RULES.md):
//   {"error": {"code": …, "message": …, "details": {}}, "meta": {"request_id": …}}
// with the number of the admin's request when the admin was asked and named one, and null otherwise. Two codes are
// this bridge's own: SWITCHED_OFF (this shop is not connected to an admin; the shop then asks again much later)
// and BAD_GATEWAY (the admin answered something that is neither JSON nor a picture).
import { vouching } from './native-bridge-key.mjs';

const REQUEST_NUMBER = /^[0-9A-HJKMNP-TV-Z]{4}(?:-[0-9A-HJKMNP-TV-Z]{4}){2}$/;
const numberOf = response => { const value = response?.headers?.get?.('x-request-id'); return typeof value === 'string' && REQUEST_NUMBER.test(value) ? value : null; };
const problem = (res, status, code, message, requestId = null) => res.status(status).json({ error: { code, message, details: {} }, meta: { request_id: requestId } });

export function nativeCategoryBridge(env = process.env) {
  return async (req, res) => {
    const raw = env.NATIVE_CATALOGUE_ORIGIN;
    if (!raw || env.NATIVE_CATEGORY_SYNC === '0') return problem(res, 503, 'SWITCHED_OFF', 'This shop is not connected to the admin.');
    let origin;
    try {
      origin = new URL(raw);
      const local = ['127.0.0.1', 'localhost'].includes(origin.hostname) && env.NODE_ENV !== 'production' && env.VERCEL !== '1';
      if ((!local && origin.protocol !== 'https:') || origin.username || origin.password || origin.search || origin.hash || origin.pathname !== '/') throw new Error('Invalid origin');
    } catch { return problem(res, 503, 'UNAVAILABLE', 'The address of the admin is not set up safely.'); }
    const suffix = req.path.replace(/^\/api\/v1\/catalogue\/categories/, '');
    if (suffix !== '' && !/^\/\d+\/hero\/\d+$/.test(suffix)) return problem(res, 404, 'NOT_FOUND', 'Nothing is at this address.');
    try {
      const response = await fetch(new URL('/api/v1/catalogue/categories' + suffix, origin), {
        headers: { Accept: suffix ? 'image/*' : 'application/json', ...vouching(env, req) }, redirect: 'error', signal: AbortSignal.timeout(5000),
      });
      if (!response.ok) {
        return response.status === 404
          ? problem(res, 404, 'NOT_FOUND', 'Nothing is at this address.', numberOf(response))
          : problem(res, 503, 'UNAVAILABLE', 'The categories of the admin are not available at the moment.', numberOf(response));
      }
      const type = response.headers.get('content-type') || '';
      if (suffix ? !/^image\/(png|jpeg|webp)(;|$)/.test(type) : !type.includes('application/json')) return problem(res, 502, 'BAD_GATEWAY', 'The categories of the admin are not available at the moment.', numberOf(response));
      const limit = suffix ? 4 * 1024 * 1024 : 2 * 1024 * 1024;
      const chunks = []; let size = 0;
      for await (const chunk of response.body) {
        size += chunk.length;
        if (size > limit) throw new Error('Native response too large');
        chunks.push(chunk);
      }
      const headers = { 'Content-Type': type, 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff' };
      // The number of the admin's request travels with its answer, so that somebody who asks for help can name it.
      if (numberOf(response)) headers['X-Request-Id'] = numberOf(response);
      return res.set(headers).send(Buffer.concat(chunks));
    } catch { return problem(res, 503, 'UNAVAILABLE', 'The categories of the admin are not available at the moment.'); }
  };
}

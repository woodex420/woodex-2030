// Public read-only bridge to the products of the Laravel admin.
//
// The same shape as the bridge for categories (native-category-bridge.mjs): it answers at the address the shop
// already asks for its catalogue — GET /api/products and GET /api/products/:id — and hands on what the admin
// published, in the admin's own answer shape ({data, meta}, {data, meta, links} for a page). The shop's reader
// (apps/storefront/src/lib/nativeProducts.ts) turns that into the product its pages use.
//
// It also hands on one picture: the product's `pictures[].url` is a path on the admin
// (/api/v1/catalogue/products/{slug}/picture/{media}/{version}), and a shop's page asks its own origin, so the
// shop must answer that path. Without this route every product photograph is a 401 on the live shop — the
// rehearsal found it, and the reader's own test could not, because it never leaves the browser's chairs.
//
// It is behind a switch of this shop, NATIVE_PRODUCT_SYNC, which is '0' unless it is set to '1'. With the switch
// off — every site today — the Node API answers these two addresses from its own database exactly as before, and
// nothing here is reached at all. That is the point of the round: the shop can read Laravel's catalogue, and does
// not until the owner says so.
//
// Never forwards cookies or authorization: what the admin published is what a visitor may see. Where this bridge
// answers itself it uses the API's error shape (docs/woodex-os/29-API-V1-RULES.md), with two codes of its own:
//   SWITCHED_OFF (503) — this shop is not connected to an admin, and will not ask again for a while;
//   BAD_GATEWAY (502)  — the admin answered something that is not a JSON answer of the contract.
const REQUEST_NUMBER = /^[0-9A-HJKMNP-TV-Z]{4}(?:-[0-9A-HJKMNP-TV-Z]{4}){2}$/;
const numberOf = response => { const value = response?.headers?.get?.('x-request-id'); return typeof value === 'string' && REQUEST_NUMBER.test(value) ? value : null; };
const problem = (res, status, code, message, requestId = null) => res.status(status).json({ error: { code, message, details: {} }, meta: { request_id: requestId } });

/** The query parameters the shop may send on, and no others: the admin refuses anything it did not name. */
const FORWARDED = ['page', 'per_page', 'category', 'series', 'since'];

export const productSyncOn = (env = process.env) => String(env?.NATIVE_PRODUCT_SYNC ?? '') === '1';

export function nativeProductBridge(env = process.env) {
  return async (req, res) => {
    if (!productSyncOn(env)) return problem(res, 503, 'SWITCHED_OFF', 'This shop is not connected to the admin.');

    let origin;
    try {
      origin = new URL(String(env.NATIVE_CATALOGUE_ORIGIN ?? ''));
      // The same rule as the categories' bridge: a plain HTTPS origin, or a loopback address while developing.
      const local = ['127.0.0.1', 'localhost'].includes(origin.hostname) && env.NODE_ENV !== 'production' && env.VERCEL !== '1';
      if ((!local && origin.protocol !== 'https:') || origin.username || origin.password || origin.search || origin.hash || origin.pathname !== '/') throw new Error('Invalid origin');
    } catch { return problem(res, 503, 'UNAVAILABLE', 'The address of the admin is not set up safely.'); }

    // A picture of a published product: /api/v1/catalogue/products/<slug>/picture/<media>/<version>, the address
    // the admin's own answer names, answered here so that a page asks the shop it stands on. Asked before the
    // product route, which would read its slashes as a path that is not ours.
    const picture = /^\/api\/v1\/catalogue\/products\/([a-z0-9]+(?:-[a-z0-9]+)*)\/picture\/(\d+)\/(\d+)$/.exec(req.path);
    if (picture) return handOnPicture(res, origin, picture[1], picture[2], picture[3]);

    // What this shop's own database changed of a product's price or stock since a moment: nothing, any more.
    // The admin's products carry their own price, and a second writer of one price is what this bridge ends.
    // Answered here in the shape the page reads, so that no page is told "not found" four times a minute.
    if (req.path === '/api/products/overrides') {
      return res.set({ 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff' }).json({ serverTime: new Date().toISOString(), items: [] });
    }

    // /api/products → /api/v1/catalogue/products; /api/products/<slug> → .../<slug>. Anything else is not ours.
    const rest = req.path.replace(/^\/api\/products/, '');
    const one = /^\/([a-z0-9]+(?:-[a-z0-9]+)*)$/.exec(rest);
    if (rest !== '' && !one) return problem(res, 404, 'NOT_FOUND', 'Nothing is at this address.');

    const target = new URL('/api/v1/catalogue/products' + (one ? '/' + one[1] : ''), origin);
    for (const [key, value] of Object.entries(req.query ?? {})) {
      if (FORWARDED.includes(key) && typeof value === 'string') target.searchParams.set(key, value);
    }
    // The shop's page reads the whole catalogue, page after page. When it does not say how many a page holds,
    // it is handed the most the admin gives at a time, not the admin's own small page.
    if (!one && !target.searchParams.has('per_page')) target.searchParams.set('per_page', '100');

    try {
      const response = await fetch(target, {
        headers: { Accept: 'application/json' },
        redirect: 'error',
        signal: AbortSignal.timeout(5000),
      });

      if (!response.ok) {
        const number = numberOf(response);
        if (response.status === 404) return problem(res, 404, 'NOT_FOUND', 'No published product of the admin is at this address.', number);
        // A part of the admin that is switched off, or a fault there: for this shop it is an outage of the
        // catalogue, and the pages say so rather than showing a list that never existed.
        return problem(res, 503, 'UNAVAILABLE', 'The catalogue of the admin is not available at the moment.', number);
      }

      const type = response.headers.get('content-type') || '';
      if (!type.includes('application/json')) return problem(res, 502, 'BAD_GATEWAY', 'The catalogue of the admin is not available at the moment.', numberOf(response));

      const body = await response.text();
      if (body.length > 4 * 1024 * 1024) return problem(res, 502, 'BAD_GATEWAY', 'The catalogue of the admin is larger than this shop takes.', numberOf(response));

      const headers = { 'Content-Type': 'application/json; charset=utf-8', 'Cache-Control': 'no-store', 'X-Content-Type-Options': 'nosniff' };
      // The number of the admin's request travels with its answer, so that somebody who asks for help can name it.
      if (numberOf(response)) headers['X-Request-Id'] = numberOf(response);
      return res.set(headers).send(body);
    } catch { return problem(res, 503, 'UNAVAILABLE', 'The catalogue of the admin is not available at the moment.'); }
  };
}

// The bytes of one picture, handed on as they are — the same shape as the media bridge, and the same rules: only
// a picture of a publication, only bytes of the three formats a product photograph is kept in, and a size this
// shop takes.
async function handOnPicture(res, origin, slug, media, version) {
  const target = new URL(`/api/v1/catalogue/products/${slug}/picture/${media}/${version}`, origin);
  try {
    const response = await fetch(target, { headers: { Accept: 'image/*' }, redirect: 'error', signal: AbortSignal.timeout(5000) });
    if (!response.ok) {
      return response.status === 404
        ? problem(res, 404, 'NOT_FOUND', 'No picture of a published product of the admin is at this address.', numberOf(response))
        : problem(res, 503, 'UNAVAILABLE', 'The photographs of the admin are not available at the moment.', numberOf(response));
    }
    const type = response.headers.get('content-type') || '';
    if (!/^image\/(png|jpeg|webp)(;|$)/.test(type)) return problem(res, 502, 'BAD_GATEWAY', 'The photographs of the admin are not available at the moment.', numberOf(response));
    const chunks = []; let size = 0;
    for await (const chunk of response.body) {
      size += chunk.length;
      if (size > 4 * 1024 * 1024) return problem(res, 502, 'BAD_GATEWAY', 'The photograph of the admin is larger than this shop takes.', numberOf(response));
      chunks.push(chunk);
    }
    const headers = { 'Content-Type': type, 'Cache-Control': 'public, max-age=300', 'X-Content-Type-Options': 'nosniff' };
    if (numberOf(response)) headers['X-Request-Id'] = numberOf(response);
    return res.set(headers).send(Buffer.concat(chunks));
  } catch { return problem(res, 503, 'UNAVAILABLE', 'The photographs of the admin are not available at the moment.'); }
}

// The key this shop's server shares with the Laravel admin, and the two headers it writes with it.
//
// With the product link on, everything a visitor of the shop reads of the admin, and the basket a visitor sends
// to it, reaches the admin from one place: this server. The admin limits how often one address may ask (120
// reads a minute, five baskets a minute), so without help the whole shop is one visitor to it. An open page
// asks nine times a minute, so about fourteen open pages use the minute up, and then every page of the shop is
// told that the catalogue is not available (defect D-082; measured on 27 November on the rehearsal's admin: of
// 125 reads from one address within a minute, the last five were answered 429).
//
// A server that proves it is the shop's may therefore say for whom it asks:
//
//   X-Woodex-Key       the key both sides hold: NATIVE_BRIDGE_KEY here, PUBLIC_LEADS_BRIDGE_KEY in the admin.
//   X-Woodex-Visitor   the address this server was asked from. The admin counts by it, and keeps it nowhere.
//
// The four bridges write these two and never hand on a visitor's own header of either name. They are sent to the
// admin's address only (adminOrigin, native-product-bridge.mjs: https, or a loopback address while developing),
// and a redirect is an error, so the key cannot follow one to another server. Without a key nothing is claimed:
// the admin counts by the address it sees, and asks a basket for its own robot check.

/** Sixteen bytes written as text are 22 characters; a key shorter than 32 is a word somebody typed. */
const KEY_AT_LEAST = 32;

/** The key this server shares with the admin, or '' when none is set, or one too short to be a key. */
export const bridgeKey = (env = process.env) => {
  const key = String(env?.NATIVE_BRIDGE_KEY ?? '').trim();
  return key.length >= KEY_AT_LEAST ? key : '';
};

/** The headers by which this server vouches for what it hands on, and names its visitor: none without a key. */
export function vouching(env, req) {
  const key = bridgeKey(env);
  if (!key) return {};
  const headers = { 'X-Woodex-Key': key };
  // The address this server was asked from. Express names it from the proxy it trusts (one hop on Vercel).
  const visitor = String(req?.ip ?? '').replace(/^::ffff:/, '');
  if (/^[0-9a-f.:]{3,45}$/i.test(visitor)) headers['X-Woodex-Visitor'] = visitor;
  return headers;
}

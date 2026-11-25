export type CategoryPageContent = {
  seo_title: string; seo_description: string; og_title: string; og_description: string;
  canonical_path: string; allow_indexing: boolean; hero_template: string; hero_heading: string; hero_text: string;
  hero_alt: string; hero_url: string | null; cta_label: string; cta_path: string;
  top_html: string; intro_heading: string; intro_html: string; bottom_heading: string; bottom_html: string;
  carousel_heading: string; carousel_mode: string; featured_ids: string[];
  faqs: { question: string; answer: string }[];
};
export type NativeCategory = { id: number; slug: string; name: string; parent_id: number | null; position: number; page: { revision: number; content: CategoryPageContent } | null };
export type NativeCatalogue = { categories: NativeCategory[]; series: { id: string; name: string }[] };
let current: NativeCatalogue | null = null;
let signature = '';
let pending = false;
/* Where the connection to the Laravel admin is switched off, the API says so
   (503, "SWITCHED_OFF"). The shop then asks again in ten minutes, not in fifteen
   seconds: on a site that has the connection off — every site, today — each
   open page would otherwise send four requests a minute to be told no. A new
   visit asks at once, so switching the connection on needs no rebuild. */
const DISABLED_PAUSE_MS = 10 * 60_000;
let pausedUntil = 0;
export const nativeCatalogue = () => current;
export function nativeCategory(id: string) {
  return current?.categories.find(c => c.slug === id || (id === 'office' && c.slug === 'office-furniture') || (id === 'home' && c.slug === 'home-furniture'));
}
/* On Vercel the build knows whether this deployment is connected to an admin (scripts/build-vercel.mjs), and
   where it is not, the shop does not ask at all. It asked at every visit and was told no: a refused request in
   the visitor's browser and a call of the server function, for each page opened. Nothing is lost by knowing it
   at build time there: a setting of the project reaches a new deployment only, and a new deployment builds the
   shop again. Everywhere else the shop asks, as before. */
const OFF_AT_BUILD = import.meta.env?.VITE_NATIVE_CATEGORIES === 'off';
export async function syncNativeCategories() {
  if (OFF_AT_BUILD || pending || Date.now() < pausedUntil) return;
  pending = true;
  try {
    const response = await fetch('/api/v1/catalogue/categories', { headers: { accept: 'application/json' }, signal: AbortSignal.timeout(7000) });
    if (!response.ok) {
      if (response.status === 503) {
        // The shop's own server says so when this shop is not connected to an admin: {error: {code: "SWITCHED_OFF"}}.
        const body = await response.json().catch(() => ({}));
        const off = body?.error?.code === 'SWITCHED_OFF';
        if (off) pausedUntil = Date.now() + DISABLED_PAUSE_MS;
        if (off && current) { current = null; signature = ''; window.dispatchEvent(new CustomEvent('woodex:runtime')); }
      }
      // Refused (401, 403, 404…): the same question gets the same answer in fifteen seconds. Ask later.
      else if (response.status >= 400 && response.status < 500) pausedUntil = Date.now() + DISABLED_PAUSE_MS;
      return;
    } // Keep last known good on a transient outage; initial load uses the bundled tree.
    // {data: {categories, series}, meta: {request_id}}: one document, always whole (docs/woodex-os/29-API-V1-RULES.md).
    const data = (await response.json())?.data;
    if (!data || !Array.isArray(data.categories) || !Array.isArray(data.series) || data.categories.length > 2000) return;
    if (!data.categories.every((c: NativeCategory) => c && Number.isInteger(c.id) && typeof c.slug === 'string' && /^[a-z0-9-]+$/.test(c.slug) && typeof c.name === 'string' && (c.parent_id === null || Number.isInteger(c.parent_id)))) return;
    // Only what was asked for is compared: the number of the request beside it is new with every answer.
    const next = JSON.stringify(data);
    if (next !== signature) {
      current = data as NativeCatalogue; signature = next;
      window.dispatchEvent(new CustomEvent('woodex:runtime'));
    }
  } catch { /* Do not blank navigation when native host is unreachable. */ }
  finally { pending = false; }
}

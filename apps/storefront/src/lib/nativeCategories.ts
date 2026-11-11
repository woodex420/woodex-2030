export type CategoryPageContent = {
  seo_title: string; seo_description: string; og_title: string; og_description: string;
  canonical_path: string; allow_indexing: boolean; hero_template: string; hero_heading: string; hero_text: string;
  hero_alt: string; hero_url: string | null; cta_label: string; cta_path: string;
  top_html: string; intro_heading: string; intro_html: string; bottom_heading: string; bottom_html: string;
  carousel_heading: string; carousel_mode: string; featured_ids: string[];
  faqs: { question: string; answer: string }[];
};
export type NativeCategory = { id: number; slug: string; name: string; parent_id: number | null; position: number; page: { revision: number; content: CategoryPageContent } | null };
export type NativeCatalogue = { schema: 1; categories: NativeCategory[]; series: { id: string; name: string }[] };
let current: NativeCatalogue | null = null;
let signature = '';
let pending = false;
export const nativeCatalogue = () => current;
export function nativeCategory(id: string) {
  return current?.categories.find(c => c.slug === id || (id === 'office' && c.slug === 'office-furniture') || (id === 'home' && c.slug === 'home-furniture'));
}
export async function syncNativeCategories() {
  if (pending) return;
  pending = true;
  try {
    const response = await fetch('/api/v1/catalogue/categories', { headers: { accept: 'application/json' }, signal: AbortSignal.timeout(7000) });
    if (!response.ok) {
      if (response.status === 503) {
        const error = await response.json().catch(() => ({}));
        if (error.disabled === true && current) { current = null; signature = ''; window.dispatchEvent(new CustomEvent('woodex:runtime')); }
      }
      return;
    } // Keep last known good on a transient outage; initial load uses the bundled tree.
    const data = await response.json();
    if (data.schema !== 1 || !Array.isArray(data.categories) || !Array.isArray(data.series) || data.categories.length > 2000) return;
    if (!data.categories.every((c: NativeCategory) => Number.isInteger(c.id) && typeof c.slug === 'string' && /^[a-z0-9-]+$/.test(c.slug) && typeof c.name === 'string' && (c.parent_id === null || Number.isInteger(c.parent_id)))) return;
    const next = JSON.stringify(data);
    if (next !== signature) {
      current = data as NativeCatalogue; signature = next;
      window.dispatchEvent(new CustomEvent('woodex:runtime'));
    }
  } catch { /* Do not blank navigation when native host is unreachable. */ }
  finally { pending = false; }
}

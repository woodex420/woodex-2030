/** Reuse Laravel's existing Media library; preserve bundled originals when disconnected. */
type Mapping = { key: string; url: string };
let mappings = new Map<string, string>();
let pending = false;
let installed = false;
const attributes = new WeakMap<Element, Map<string, { original: string; applied: string }>>();
const failed = new WeakMap<Element, Set<string>>();
const observed = new WeakSet<Element>();

function substitute(value: string): string {
  return value.replace(/(?<![a-zA-Z0-9:/])\/img\/[a-zA-Z0-9_.-]+\.(?:jpe?g|png|webp|avif|gif)/gi, key => mappings.get(key) ?? key);
}

export function applyMediaMappings() {
  document.querySelectorAll('img, source, [style]').forEach(element => {
    let state = attributes.get(element);
    if (!state) { state = new Map(); attributes.set(element, state); }
    for (const name of element.matches('img, source') ? ['src', 'srcset'] : ['style']) {
      const actual = element.getAttribute(name);
      if (actual === null) continue;
      const previous = state.get(name);
      if (!previous && !actual.includes('/img/')) continue;
      const original = previous && actual === previous.applied ? previous.original : actual;
      const next = substitute(original);
      const applied = failed.get(element)?.has(next) ? original : next;
      state.set(name, { original, applied });
      if (actual !== applied) element.setAttribute(name, applied);
    }
    if (element instanceof HTMLImageElement && !observed.has(element)) {
      observed.add(element);
      element.addEventListener('error', () => {
        const targets: Element[] = [element, ...Array.from(element.closest('picture')?.querySelectorAll('source') ?? [])];
        for (const target of targets) {
          const state = attributes.get(target);
          if (!state) continue;
          for (const [name, current] of state) {
            if (current.original === current.applied) continue;
            const rejected = failed.get(target) ?? new Set<string>();
            rejected.add(current.applied); failed.set(target, rejected);
            target.setAttribute(name, current.original);
            state.set(name, { original: current.original, applied: current.original });
          }
        }
      });
    }
  });
}

/* Where the connection to the Laravel admin is switched off, the API says so
   (503, "SWITCHED_OFF"). The shop then asks again in ten minutes, not in fifteen
   seconds: on a site that has the connection off — every site, today — each
   open page would otherwise send four requests a minute to be told no. A new
   visit asks at once, so switching the connection on needs no rebuild. */
const DISABLED_PAUSE_MS = 10 * 60_000;
let pausedUntil = 0;

export async function syncMediaMappings() {
  if (pending || Date.now() < pausedUntil) return;
  pending = true;
  try {
    const response = await fetch('/api/v1/media', { headers: { accept: 'application/json' }, signal: AbortSignal.timeout(7000) });
    if (!response.ok) {
      if (response.status === 503) {
        // The shop's own server says so when this shop is not connected to an admin: {error: {code: "SWITCHED_OFF"}}.
        const body = await response.json().catch(() => ({}));
        if (body?.error?.code === 'SWITCHED_OFF') { pausedUntil = Date.now() + DISABLED_PAUSE_MS; mappings.clear(); applyMediaMappings(); }
      }
      // Refused (401, 403, 404…): the same question gets the same answer in fifteen seconds. Ask later.
      else if (response.status >= 400 && response.status < 500) pausedUntil = Date.now() + DISABLED_PAUSE_MS;
      return;
    }
    // {data: [{key, url}], meta: {request_id, total}}: always the whole list (docs/woodex-os/29-API-V1-RULES.md).
    const items = (await response.json())?.data;
    if (!Array.isArray(items) || items.length > 10000) return;
    if (!items.every((item: Mapping) => item && typeof item.key === 'string' && /^\/img\/[a-zA-Z0-9_.-]+\.(jpe?g|png|webp|avif|gif)$/i.test(item.key) && typeof item.url === 'string' && /^\/api\/v1\/media\/\d+\/\d+$/.test(item.url))) return;
    mappings = new Map(items.map((item: Mapping) => [item.key, item.url]));
    applyMediaMappings();
  } catch { /* Keep last known good mapping and original-photo fallback. */ }
  finally { pending = false; }
}

export function startMediaSync() {
  if (installed || typeof MutationObserver === 'undefined') return;
  installed = true;
  let scheduled = false;
  const observer = new MutationObserver(records => {
    if (!records.some(record => record.type === 'childList' || record.attributeName !== 'style' || (record.target instanceof Element && ((record.target.getAttribute('style') ?? '').includes('/img/') || attributes.get(record.target)?.has('style'))))) return;
    if (scheduled) return;
    scheduled = true;
    requestAnimationFrame(() => { scheduled = false; applyMediaMappings(); });
  });
  observer.observe(document.documentElement, { subtree: true, childList: true, attributes: true, attributeFilter: ['src', 'srcset', 'style'] });
  void syncMediaMappings();
  window.setInterval(() => void syncMediaMappings(), 15000);
  window.addEventListener('focus', () => void syncMediaMappings());
}

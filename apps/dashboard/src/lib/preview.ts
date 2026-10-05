/* Storefront-origin links from the dashboard (dev :5174, sandbox preview host swap 5173→5174)
   + signed draft preview (QA TODO-1): drafts are only fetchable with an HMAC sig or a session. */
export function storeUrl(path = "/"): string {
  const h = location.hostname;
  const m = /^5173-(.+)$/.exec(h);
  if (m) return location.protocol + "//5174-" + m[1] + path; // e2b preview: sibling port host
  if (h === "localhost" || /^\d+\.\d+\.\d+\.\d+$/.test(h)) return location.protocol + "//" + h + ":5174" + path;
  return location.origin + path; // production: one origin, path passthrough
}

export async function previewHref(page: { id?: number; slug: string; status?: string }): Promise<string> {
  const pub = (page.status ?? "Published") === "Published";
  if (pub) return storeUrl("/p/" + page.slug);
  try {
    const j = (await fetch("/api/pages/" + (page.id ?? encodeURIComponent(page.slug)) + "/preview-link", { method: "POST" }).then((r) => (r.ok ? r.json() : null))) as { url?: string } | null;
    if (j?.url) return storeUrl(j.url); // 15-min signed draft preview
  } catch { /* offline → best effort */ }
  return storeUrl("/p/" + page.slug + "?draft=1");
}

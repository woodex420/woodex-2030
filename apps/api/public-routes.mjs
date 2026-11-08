/**
 * The public, indexable routes of the storefront — one list, two consumers.
 *
 * - `apps/api/server.mjs` builds `/sitemap.xml` from it (it has database access,
 *   so it can add published CMS pages and products).
 * - `scripts/prerender.mjs` renders one HTML file per route at build time.
 *
 * Historically each side kept its own list: the API shipped 149 URLs and missed
 * every static marketing page, while the prerender shipped 76 and missed every
 * published CMS page. Neither was complete and no test noticed. Adding a route
 * here is now the only way to add it anywhere.
 *
 * `priority` and `changefreq` feed the sitemap; `title` and `desc` feed the
 * prerendered <head>.
 */
export const PUBLIC_ROUTES = [
  { path: "/", title: "WOODEX — Premium Office Furniture in Lahore", desc: "Ergonomic chairs, executive desks, workstations and complete office fit-outs, manufactured in Lahore with 48-hour quotations and nationwide delivery.", priority: "1.0", changefreq: "weekly" },
  { path: "/shop", title: "Shop Office Furniture", desc: "Browse the full WOODEX catalogue: ergonomic chairs, desks, workstations, storage and meeting furniture with live PKR prices.", priority: "0.9", changefreq: "daily" },
  { path: "/about", title: "About WOODEX", desc: "A Lahore furniture manufacturer building commercial workspaces since 2009 — our factory, our people and our standards.", priority: "0.6", changefreq: "monthly" },
  { path: "/contact", title: "Contact & Showrooms", desc: "Call, WhatsApp or visit a WOODEX showroom in Lahore, Karachi or Islamabad. Opening hours and directions.", priority: "0.7", changefreq: "monthly" },
  { path: "/quotation", title: "Request a Quotation", desc: "Build a quote basket and get a costed office furniture quotation within 48 hours. No account required.", priority: "0.9", changefreq: "monthly" },
  { path: "/services", title: "Design & Fit-out Services", desc: "Space planning, custom joinery, installation and after-sales service for offices across Pakistan.", priority: "0.7", changefreq: "monthly" },
  { path: "/materials", title: "Materials & Finishes", desc: "Seasoned hardwood, laminates, fabrics and marine-grade hardware — what our furniture is actually made of.", priority: "0.6", changefreq: "monthly" },
  { path: "/projects", title: "Completed Projects", desc: "Corporate fit-outs, hospitality spaces and turnkey interiors delivered by WOODEX.", priority: "0.6", changefreq: "monthly" },
  { path: "/showrooms", title: "Showrooms", desc: "Visit a WOODEX showroom to sit in the chairs and open the drawers before you buy.", priority: "0.6", changefreq: "monthly" },
  { path: "/b2b", title: "B2B & Bulk Orders", desc: "Volume pricing, phased delivery and single-invoice procurement for offices, schools and co-working operators.", priority: "0.7", changefreq: "monthly" },
  { path: "/blog", title: "Workspace Journal", desc: "Practical writing on ergonomics, office planning and buying furniture in Pakistan.", priority: "0.5", changefreq: "weekly" },
  { path: "/warranty", title: "Warranty & Care", desc: "What the WOODEX warranty covers, for how long, and how to keep solid wood and mechanisms working.", priority: "0.5", changefreq: "yearly" },
  { path: "/custom-design", title: "Custom Design", desc: "Send a sketch or a floor plan and we will build furniture to fit it exactly.", priority: "0.6", changefreq: "monthly" },
  { path: "/room-packages", title: "Room Packages", desc: "Furnished room packages for reception, meeting rooms, focus booths and breakout spaces.", priority: "0.6", changefreq: "monthly" },
  { path: "/series", title: "Collections by Series", desc: "WOODEX furniture families, from entry-level workstations to executive boardroom ranges.", priority: "0.5", changefreq: "monthly" },
  { path: "/virtual-showroom", title: "Virtual Showroom", desc: "Walk through the WOODEX range online when you cannot visit in person.", priority: "0.4", changefreq: "monthly" },
];

/** Absolute URL for a public path, given the site origin. */
export const absoluteUrl = (origin, p) => String(origin).replace(/\/+$/, "") + (p === "/" ? "/" : p);

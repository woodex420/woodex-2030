/**
 * Single source of brand identity.
 *
 * Every title, meta tag, sitemap entry and structured-data block reads from
 * here. Renaming the store is one edit in this file, not a 200-string sweep
 * across 26 components — which is exactly the sweep that kept a rename off the
 * table in the first place.
 *
 * Keep this file free of imports so it can be consumed by the Vite build, the
 * prerender step and plain Node scripts alike.
 */

export const brand = {
  /** Trading name, used in the wordmark and <title> suffixes. */
  name: "WOODEX",

  /** Legal/entity name for structured data. */
  legalName: "WOODEX Furniture",

  /** One-line positioning, used in meta descriptions and the footer. */
  tagline: "Premium office furniture, made in Lahore",

  /** Longer description used when a page has no specific one. */
  description:
    "WOODEX designs and manufactures premium office furniture in Lahore — desks, ergonomic chairs, workstations and complete fit-outs, with 48-hour quotations and nationwide delivery.",

  /** Canonical public origin. No trailing slash. It is the business's own site;
      a deployment elsewhere states its own address in SITE_URL at build time. */
  url: "https://woodexfurniture.pk",
  /** The website as it is printed on an estimate. */
  website: "www.woodexfurniture.pk",

  /* THE CONTACT FACTS. One place holds them, and every page, the printed
     estimate and the structured data read them from here. `npm run test:facts`
     fails on a telephone number, an e-mail address, a WhatsApp link or a street
     written anywhere else in the storefront.

     Where each comes from (decision DL-048):
     - phone, WhatsApp and e-mail: given by the owner on 25 November 2026.
     - the address and the point on the map: the business's own Google listing,
       as woodexfurniture.pk/contact embeds it (read the same day). The owner
       has not confirmed it in writing yet.
     Until that day the storefront showed a sample telephone number, three sample
     addresses and mailboxes on a domain that is not the business's. */
  phone: "+92 322 4000768",
  /** The same number as a link dials it. */
  phoneLink: "tel:+923224000768",
  /** WhatsApp (`wa.me`); digits only. */
  whatsapp: "923224000768",
  whatsappLink: "https://wa.me/923224000768",
  email: "woodexofficefurniture@gmail.com",
  emailLink: "mailto:woodexofficefurniture@gmail.com",

  /** The showroom, and the address in structured data. */
  address: {
    street: "LG 89, Zainab Tower, Model Town Link Road",
    city: "Lahore",
    region: "Punjab",
    country: "PK",
    postalCode: "54700",
  },
  /** Where the map opens. */
  geo: { lat: 31.4663355, lng: 74.3164285 },

  /** Showroom opening hours, as one string and as a range. */
  hours: "Mon–Sat: 9am–7pm",
  hoursRange: { opens: "09:00", closes: "19:00", days: ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"] },

  /** Social handles (without @). Empty until the owner names the accounts: the
      two that stood here were never confirmed as the business's, and structured
      data told search engines that they were. */
  social: {
    instagram: "",
    facebook: "",
  },

  /** Default social-share image, served from the API's single /img copy. */
  ogImage: "/img/hero-office.jpg",

  /** Locale + currency shown to customers. */
  locale: "en-PK",
  currency: "PKR",

  /**
   * The delivery charge, in whole rupees: `fee` per order, nothing from
   * `freeFrom` upwards. Every page that mentions delivery reads it from here —
   * the product page used to promise "free delivery nationwide" (and, in one
   * tab, "free within UAE above AED 2,000") while checkout charged for it.
   *
   * The API applies the same rule when it saves an order (POST /api/orders in
   * apps/api/server.mjs) and is the authority: change the two together.
   * Whether this is the business's rule at all is the owner's call (Q-4).
   */
  delivery: { fee: 2500, freeFrom: 50000 },

  /** Theme colour used by browsers and PWA manifests. */
  themeColor: "#16A34A",
} as const;

/** `${brand.name} — ${suffix}` unless the suffix already contains the name. */
export function title(suffix: string): string {
  return suffix.includes(brand.name) ? suffix : `${brand.name} — ${suffix}`;
}

/** Absolute URL for a path, tolerating a leading slash or its absence. */
export function absolute(path: string): string {
  return brand.url + (path.startsWith("/") ? path : "/" + path);
}

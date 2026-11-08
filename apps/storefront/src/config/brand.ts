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

  /** Canonical public origin. No trailing slash. */
  url: "https://woodex.pk",

  /** Contact details used in LocalBusiness structured data. */
  phone: "+92 300 1234567",
  /** WhatsApp used by the quotation flow (`wa.me`); digits only. */
  whatsapp: "923224000768",
  email: "info@woodex.pk",

  /** Registered address for structured data. */
  /* NOTE: these were previously duplicated across ~8 components with two
     different values (Gulberg III on the storefront, Johar Town on dashboard
     invoices). Gulberg III is the value the public site uses consistently, so
     it wins here — but confirm with the business before launch. */
  address: {
    street: "123 Gulberg III, Main Boulevard",
    city: "Lahore",
    region: "Punjab",
    country: "PK",
    postalCode: "54660",
  },

  /** Showroom opening hours, as one string and as a range. */
  hours: "Mon–Sat: 9am–7pm",
  hoursRange: { opens: "09:00", closes: "19:00", days: ["Monday", "Tuesday", "Wednesday", "Thursday", "Friday", "Saturday"] },

  /** Social handles (without @). */
  social: {
    instagram: "woodex.pk",
    facebook: "woodex.pk",
  },

  /** Default social-share image, served from the API's single /img copy. */
  ogImage: "/img/hero-office.jpg",

  /** Locale + currency shown to customers. */
  locale: "en-PK",
  currency: "PKR",

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

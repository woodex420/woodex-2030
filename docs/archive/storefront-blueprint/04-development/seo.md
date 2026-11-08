# SEO `[current + planned]`

## Current Implementation
- `SeoContentBlock.tsx` sets `<title>`, `<meta name="description">`, and injects JSON-LD (`CollectionPage`, `FAQPage`).
- `src/data/seoContent.ts` holds keyword-optimised copy for 18 categories + 5 series.
- Semantic HTML: single H1 per page, H2/H3 hierarchy, alt text on all images.

## Meta Rules
- Title ≤ 60 chars, includes primary keyword + brand.
- Description ≤ 160 chars, benefit + CTA.
- Open Graph: `og:title`, `og:description`, `og:type`, `og:image`.
- Twitter: `summary_large_image`.
- Canonical tag on every page.
- Viewport: `width=device-width, initial-scale=1`.

## Structured Data
| Type | Where |
|---|---|
| `Organization` | `[planned]` — index.html head |
| `WebSite` + `SearchAction` | `[planned]` |
| `BreadcrumbList` | `[planned]` — every non-home page |
| `Product` | `[planned]` — PDPs |
| `CollectionPage` | ✅ Shop, Series categories |
| `FAQPage` | ✅ Category, service, series pages |
| `LocalBusiness` | `[planned]` — Showrooms page |
| `Article` | `[planned]` — Blog posts |

## Sitemap & Robots
- `public/robots.txt` `[planned]`
- `scripts/generate-sitemap.ts` `[planned]` — pre-dev / pre-build hook writes `public/sitemap.xml` from routes + data files.

## Keyword Strategy `[draft — confirm]`
Primary: office furniture Lahore, executive office chairs Pakistan, modular workstations Pakistan, boardroom table Lahore, custom office furniture Pakistan.

Secondary: interior design Lahore, ergonomic chair PKR, home office furniture Pakistan, custom bedroom furniture Lahore.

## Localisation
- `hreflang` `[planned]` when Urdu variants launch.
- All copy Pakistan-tailored (PKR, Lahore, DHA references).

## Performance-linked SEO
- Lighthouse target ≥ 90 SEO score.
- Core Web Vitals: LCP < 2.5s, INP < 200ms, CLS < 0.1.

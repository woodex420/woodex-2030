# SEO Launch Checklist

## Pre-Launch
- [ ] Unique `<title>` + meta description on every route (verify via crawl).
- [ ] Single H1 per page.
- [ ] Canonical tags set to production domain.
- [ ] `og:title`, `og:description`, `og:type`, `og:image`, `twitter:card` on all pages.
- [ ] Alt text on every `<img>` (already enforced in components).
- [ ] `public/robots.txt` present, `Allow: /`, includes `Sitemap:` line.
- [ ] `public/sitemap.xml` generated via `scripts/generate-sitemap.ts` (predev + prebuild hooks).
- [ ] JSON-LD: Organization, LocalBusiness (showroom), WebSite + SearchAction added to `index.html`.
- [ ] Product, Article, BreadcrumbList JSON-LD on relevant pages.
- [ ] `hreflang` — skip until Urdu variants exist.
- [ ] 404 page returns proper status + helpful links.

## GSC / Analytics
- [ ] Google Search Console verified (both `www` and root).
- [ ] Sitemap submitted.
- [ ] GA4 property + measurement ID wired.
- [ ] Key events: `whatsapp_click`, `quote_submit`, `add_to_cart`, `checkout_submit`, `contact_submit`.
- [ ] Bing Webmaster Tools verified.

## Performance / Vitals
- [ ] Lighthouse SEO ≥ 90 on all top pages.
- [ ] LCP < 2.5s, CLS < 0.1, INP < 200ms.

## Content QA
- [ ] Every category page has SEO block copy (`seoContent.ts`).
- [ ] Every service page has 6 FAQs.
- [ ] Every series page has hero + narrative + gallery.
- [ ] No lorem, no placeholder images, no broken links.

## Post-Launch (Week 1)
- [ ] Fetch & render top 10 URLs in GSC.
- [ ] Watch Coverage report for indexation issues.
- [ ] Semrush baseline snapshot recorded.

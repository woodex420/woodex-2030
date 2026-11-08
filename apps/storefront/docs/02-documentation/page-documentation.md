# Page Documentation `[current]`

For every route, purpose + key sections + components used.

## `/` Home (`Index.tsx`)
- **Purpose:** Establish brand, funnel to Products / Series / B2B.
- **Sections:** Hero slider (5 slides), featured series, categories grid, B2B teaser, showroom teaser, testimonials, blog teaser.
- **Components:** `Header`, `HeroSlider`, `SeriesCarousel`, `Footer`.

## `/shop` (`Shop.tsx`)
- **Purpose:** Full product catalogue with filters.
- **Sections:** Hero, sidebar (category, price, material), product grid, SEO block, pagination.
- **Components:** `SeoContentBlock`, `ProductCard`, `FilterSidebar`.

## `/shop/:productId` (`ProductDetail.tsx`)
- Gallery + zoom + 360, variants, colour swatches, price, add-to-cart, add-to-quote, tabs (Description / Specs / Reviews / Warranty), related.

## `/series`, `/series/:seriesId`
- Series overview + individual series storytelling; `SeoContentBlock` per series.

## `/projects`, `/projects/:projectId`
- Case-study grid + full case study (challenge, solution, gallery, spec list).

## `/services` (`Services.tsx`)
- "Make Your Space Work" numbered list, 6-card grid → sub-pages.

## `/services/:slug` (`ServiceDetail.tsx`)
- Hero, overview, inclusions, process, FAQs, CTA. Data: `src/data/services.ts`.

## `/custom-design`
- WDS 3D tool, 6 service cards, split sections, CTA to quotation.

## `/b2b`
- Sector cards, tier matrix, quote CTA.

## `/quotation`
- 3-step process, feature cards, project form + benefits column.

## `/showrooms`, `/materials`, `/warranty`, `/about`, `/contact`
- Standard content pages with hero + SEO content block.

## `/blog`, `/blog/:postId`
- Blog index with hero + card grid; individual article with full content.

## `/checkout`
- B2C cart review + COD / Bank Transfer options; WhatsApp confirmation on submit.

## `/virtual-showroom`
- Hotspot configurator over floorplan image; add-to-quote on hotspot click.

## `/room-packages`
- Curated bundles per room type.

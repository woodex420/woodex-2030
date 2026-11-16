# Live site and catalogue

The shop at woodexfurniture.pk is a custom PHP website. It was not built from this repository. This document records its state, the quality of its product list, and what moving that list into this platform requires.

**Dates.** The site was read on 2026-11-11 through public pages only. `robots.txt` was re-read on 2026-11-16. The raw product list captured on 2026-11-11 was lost with the audit sandbox, so the figures in §2 must be reproduced by a fresh crawl before any migration.

**Since Phase 2 (2026-11-16).** The fresh capture was made and is kept in `catalog/source/woodexfurniture.pk/2026-11-16/`: 171 products on the live quotation page, 170 prepared for import, 1 left out. The figures the owner should now read are in [`catalogue/IMPORT-REPORT.md`](catalogue/IMPORT-REPORT.md); the product model of §3 exists as migration 019. See [07](07-PHASE-2-REPORT.md).

## 1. State of the live site

| Topic | Finding |
|---|---|
| Search engines | `robots.txt` is `Disallow: /`, so the whole shop is closed to search engines. `/sitemap.xml` is not found. |
| URL patterns | `/shop/{slug}`, `/categories/{slug}`, `/projects/{slug}`, `/e-quotation`, and older service pages ending in `.php`. |
| Old URLs still in search results | `/shop/{category}/{slug}/` and `/product-category/…` from an earlier WooCommerce site. They now return not found. |
| Category slugs seen | executive-chairs, manager-chair, staff-chairs, visitor-chair, reception-desk, cubicle-workstation, meeting-table, office-sofas, home-office-furniture, office-storages, executive-table, staff-table, workstation |
| Placeholder contact details | The contact page shows sample phone numbers and three sample addresses beside the real Lahore address. |
| Broken pages | `/about` is not found. The "Get Free Quote" button on Services points to a page that is not found. `/categories` shows the Services page. |
| Placeholder content | One project is titled "Project Title / Client Name / Location City". The shop page title is "Shop Premium Wardrobe Essentials". |
| Conflicting claims | Warranty appears as 2 years, 3 years and "5–7 year". Delivery appears as "Free Delivery Nationwide" and as "Free Shipping over PKR 3,000". Some products carry "92% OFF" badges. The quotation builder applies 17% GST. |

The claims matter for this platform because the storefront here makes its own: "since 2009", showrooms in three cities, quotations within 48 hours. One written source of truth is needed (Q-4 in [01](01-OWNER-DECISIONS.md)).

## 2. Quality of the live product list

From the `/e-quotation` page on 2026-11-11, which listed 171 products with SKU and price:

| Check | Count |
|---|---|
| Products listed | 171 |
| Without a SKU | 26 |
| SKU text starts with a literal "SKU:" | 82 |
| Price of PKR 0 | 34 |
| Price not a whole number | 7 |
| SKUs used by more than one product | 17 SKUs across 36 products |
| Duplicate product names | 3 |
| Names that match this repository's 148 seed products | 2 |

So the seed catalogue in this repository is not the real catalogue, and the real one cannot be imported as it stands.

## 3. What the product model needs first

Measured on 2026-11-16, the current model has no SKU and no variants (F-07 in [02](02-AUDIT-FINDINGS.md)). Before an import, the model needs at least:

- a unique SKU per sellable item;
- variants where one product is sold in several sizes, colours or materials, each with its own SKU and price;
- a price in whole rupees, or an explicit "price on request";
- a draft or published state, so imported items stay hidden until approved;
- real images per product, and no rating or review count unless it comes from real customers.

The exact schema is Phase 2 design work in [03](03-LAUNCH-PLAN.md) and goes through the migration ledger like every other schema change.

## 4. Proposed cleansing rules

For the owner to approve or change before any import runs.

| Rule | Treatment |
|---|---|
| R-1 | Remove the literal "SKU:" prefix and surrounding spaces. |
| R-2 | A product with no SKU gets a generated one under a stated scheme and is listed for review. |
| R-3 | A price of PKR 0 becomes "price on request": it can be quoted, not ordered, until the owner supplies a price. |
| R-4 | Where one SKU is used by several products, the owner chooses which product keeps it; the others are listed and receive new SKUs. |
| R-5 | Prices that are not whole rupees are listed for the owner to confirm. |
| R-6 | Duplicate names and junk rows are listed; nothing is dropped silently. |
| R-7 | Every imported product starts as a draft. Nothing is published until the owner signs the list. |

The import produces a report of every change it made, so the owner signs a list, not a promise.

## 5. Redirects at cutover

The new storefront keeps `/shop/{id}` for products, so product addresses can stay the same wherever the old slug is reused as the new product id. The other patterns need mapping:

| Old address | New address |
|---|---|
| `/shop/{slug}` | `/shop/{id}`, same slug where possible |
| `/categories/{slug}` | The shop page filtered to that category; the exact address is decided in Phase 2 |
| `/projects/{slug}` | `/projects/{id}` |
| `/e-quotation` | `/quotation` |
| Service pages ending in `.php` | `/services/{slug}` |
| `/shop/{category}/{slug}/` and `/product-category/…` | The matching product or category |
| Anything with no counterpart | Not found, listed in the cutover report |

A complete map needs a full crawl of the live site and an export of indexed URLs from Google Search Console (Q-12). Both belong to Phase 5.

## 6. Search engines at launch

The live shop is invisible to search engines today, and the public demo of this platform is visible with demo content (F-11). At cutover the new site must allow crawling, serve its sitemap on the real domain, and answer the old addresses with permanent redirects. Until then the demo and staging should refuse crawlers.

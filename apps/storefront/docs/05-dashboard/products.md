# Products `[planned]`

## Purpose
Manage catalogue displayed on `/shop`, `/shop/:productId`, `/series/*`, `/room-packages`.

## Fields
See `docs/04-development/cms-structure.md` for the schema.

## Editor UX
- Two-column: form left, live preview right.
- Sections: Basics, Media, Pricing, Variants, Dimensions & Materials, SEO, Related.
- Bulk actions: publish, unpublish, adjust price %, assign series.

## Variants
- Each variant: SKU, colour, size, price override, stock, image override.
- Auto-generate variant matrix from selected options.

## Inventory
- Manual stock counts in v1.
- Low-stock threshold + dashboard alert.

## Series Linking
Products assigned to at most one series; series pages auto-aggregate.

## Metrics
- Top-viewed products.
- Top add-to-quote products.
- Zero-conversion PDPs (flag for copy / photo review).

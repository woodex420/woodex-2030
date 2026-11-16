# Capture of the live catalogue, 2026-11-16

Everything in this directory was read from public pages of woodexfurniture.pk on 2026-11-16 and typed in row for row. Nothing here is corrected, rounded or renamed: the cleansing happens in `scripts/catalog-build.mjs`, which reads these files and writes the prepared catalogue and the owner's report.

| File | Page | What it holds |
|---|---|---|
| `e-quotation.txt` | `/e-quotation` | 171 rows: SKU as printed, name, unit price. The only page that lists every product with its SKU. |
| `category-<slug>.txt` | `/categories/<slug>` | One row per product card: address slug, name, price, compare-at price, image file. 18 listings, each complete ("N of N Products"). |
| `category-series.txt` | `/shop/team-staff-chair` | One product that appears in no listing; taken from its own page. |

## How the two sources check each other

The quotation page and the category pages were captured separately. `scripts/catalog-build.mjs` joins them on name and price and stops if a product in one has no partner in the other. On this capture all 171 products pair up, and a product that appears in two listings shows the same name, price and image in both.

## Limits

- The sandbox this was captured from cannot reach the shop directly, so pages were read as rendered text. Images were not downloaded; only their addresses are recorded.
- The category pages show a price already rounded to whole rupees. The quotation page shows the stored value, for example 61,999.99. The stored value is the source; the rounded one is used only to pair the rows.
- Product pages were read for three products only (`ambassador-executive-table`, `team-staff-chair`, and the listing pages name the rest). Descriptions, galleries and specifications were not captured. See the import report for why the live descriptions are not carried over as they stand.
- Parent categories (`office-tables`, `office-chairs`, `meeting-conference-table`, `collections`, `series`) and the empty or unrelated ones (`cubicle-workstation` has 0 products; `cafe-furniture`, `public-sitting`) were not needed to account for all 171 products.

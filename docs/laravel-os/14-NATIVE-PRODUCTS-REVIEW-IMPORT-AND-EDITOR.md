# Native Products: reviewed import, editor and portable transfer

2026-11-11. User chose **Products first**, **reviewable draft import**, and category carousel controls in the **full editor with clear Quick Edit shortcuts**. User explicitly restored Commerce Overview above Products, requested Shop customization as a subsequent scope, and requested a Series page tagged Soon rather than representing full Series content management as complete.

## Source audit and import decision

Repository source is `apps/api/seed-data.json`, not a live Agency OS database dump. The snapshot contains **148 products and only 18 distinct photo paths**. Repeated photos do not prove actual product identity. Source colours have names/hex codes but do not establish priced colour/material/size combinations. Source `inStock` booleans do not prove quantities or current availability. Product-specific shipping policy is absent; warranty labels and rating/review claims need review.

`scripts/build-product-import.mjs` creates a provenance-tagged review snapshot at `platform/resources/data/product-import-source.json`; `--check` is included in Node CI. It verifies existing local photo paths, records source SHA256 and attaches warnings. It does not invent products, variant prices, photos or stock. Full source records, including original price/rating/colour/flags, are preserved as **source_record** for review/export, not elevated into verified review counts, discounts or stock claims.

The runtime's 148 source products were imported into **native unpublished drafts** with actual source files copied privately. Initial native availability is `on_request`, variants are empty until configured, and image alternative text must be reviewed. Repeated gallery content is deduplicated. No existing record is overwritten on subsequent import, and no real source product has been published. Clearly labelled synthetic browser records were archived after testing.

## Commerce navigation

Native sidebar order is now **Commerce Overview → Products → Categories → Series (Soon) → Shop customization (Soon)**. Overview shows actual native counts and the review workflow. Products links to the native editor/list, not the old Node catalogue. Remaining Commerce native replacements show Soon; existing legacy links remain available where configured, with separate authentication. Existing procurement/other working modules were not deleted or silently reimplemented.

`/admin/commerce/series` is the requested **Series · Soon** planning page. The existing organizer is preserved at `/admin/commerce/series/organize`. Full collection hero/content/SEO/FAQ/product-assignment editing remains future work. Existing Series/category associations are preserved; no missing chair/table/workstation memberships are fabricated to make a collection look complete.

`/admin/commerce/shop` is **Shop customization · Soon**, with a declared future scope of hero, sidebar/category navigation, content/featured sections and footer using one storefront renderer. This slice does not add a disconnected page builder or claim custom sidebar drag-order settings are complete.

## Products workspace

`/admin/commerce/products` provides real native counts, name search, status filters, pagination, multi-select, image thumbnails and full-page Edit/Preview.

Full editor supports:

- Name, page slug, short description, **multiple category checklists** with an explicit primary category, and multiple separate Series assignments.
- Actual JPEG/PNG/WebP **file uploads**, maximum 4 MB / 6000 × 6000 each, up to 24 gallery images. No editable remote image URL field. First gallery item is primary; move up/down, remove from a draft and replace by uploading/reordering are supported. Source images and new uploads are visible in saved previews immediately.
- Explicit PKR base price and availability (`available`, `unavailable`, `on_request`); no inferred stock quantity.
- Structured variant rows with SKU, colour, material, size, per-combination price and availability. Duplicate SKUs and malformed/negative prices are rejected. Publication requires each configured variant's explicit price; source options are displayed as reference labels, not turned into invented combinations.
- Markdown Description, Shipping and Warranty with safe rendered previews; raw HTML/unsafe links/remote Markdown images remain blocked by the existing formatter.
- Add/edit/remove/reorder Specifications, Features and FAQs.
- SEO title/description and social title/description.
- Featured/related products: hidden, automatic shared-category eligibility or manual checklist and explicit ordering. Preview promotes only published non-archived native products and uses published identity, never a related product's newer draft. No sales-popularity metric is invented.
- Source-review warning panel, explicit review acknowledgement, immutable draft-save history and restore-history-as-unsaved-content (requiring fresh review/save/preview).

The authenticated saved-product preview includes gallery, identity/classification, price/availability, Description, Specifications, Features, Shipping, Warranty, variant table, FAQs and related products. It is private/no-store/noindex, text is escaped/sanitized and it cannot place an order or reserve stock. This is a native review preview, **not a claim that the current React product renderer has been switched to native products**.

## Draft/publication integrity and permissions

New additive schema: `commerce_products`, `commerce_product_media`, immutable `commerce_product_revisions` and per-user exact-version `commerce_product_previews`. Publication points at an immutable saved snapshot; newer drafts do not replace it. Optimistic versions reject stale saves; row locks/transactions protect saves/publication/bulk changes. Activities use the registered `commerce_product` morph alias. A restoration loads content as a new draft rather than rewriting history.

- Catalogue view: read-only staff see only published native product snapshots, not unreviewed draft cards/media/content.
- Catalogue manage: create/save drafts, upload, import/export, bulk draft classification/availability and preview.
- Catalogue publish: additionally required for explicit publication, withdrawal, archive and restore. Sales managers may edit but cannot publish by default.
- Publication requires completed active classification (including ancestors), actual image files with alt text, base price, Description/Shipping/Warranty, all configured variant prices, operator review acknowledgement, a preview receipt under **that publisher's own account**, matching saved version, no pending uploads/unsaved changes and a separate confirmation.

Files are content-addressed on private local storage. Image routes require catalogue permission and product ownership; view-only users can read only images in the current published snapshot. A product cannot attach another product's media ID. Removed/replaced images remain for immutable historical revisions; no destructive global prune is performed. Failed imports may leave unreferenced content-addressed files, which need a reviewed retention job rather than blind deletion. Private file/database backup/retention and production media hosting remain operational gates.

## Import/export and bulk tools

`/admin/commerce/products/transfers` shows source totals, hash, review warnings and sample records before an explicitly confirmed source import. It creates drafts and skips existing source keys/slugs. Actual original photos are copied from the deployed local source-image root; no browser URL download occurs.

For a standalone Laravel deployment, install the existing source images and set **`CATALOGUE_SOURCE_IMAGE_ROOT`** to that local directory before source import. Default is the monorepo's `../apps/api/public/img`. The sandbox preview is configured to the repository copy. No image binaries are duplicated into Git or fetched from arbitrary URLs.

Portable export is **`woodex-products-1` JSON with embedded, hash-verified image assets**, deduplicated by content. It includes editable draft content, variant rows, category/primary/Series/related references mapped by slug, media/alt/order, source records and source review warnings. Export a selection or all native products. Maximum 500 products, 25 MB JSON and 16 MB binary media; larger exports must be split. Export refuses missing files or a package that cannot fit the import limits.

Portable import validates schema, image type/dimensions/hash/size, nested field bounds and associations before writes. A per-upload hash requires confirmation of the exact previewed file. It maps native IDs by slug, skips existing keys/slugs, creates drafts, resets review and never restores publication flags or preview receipts. Related slugs are resolved after insert. Missing category/Series mappings require explicit review/reselection. No arbitrary filesystem path or external image URL can enter the editor through a package.

**This is a product/media interchange package, not a full database backup.** Users, credentials, orders, audit/revision history and operational tables are intentionally not imported from a product file. CSV/Excel import and destructive overwrite/upsert are not claimed. Exported original files remain private staff downloads.

Bulk tools: select across list pages, export selection/all, add category/Series to drafts without removing existing assignments, change explicit draft availability, withdraw publication, archive and restore. Confirmation and RBAC are enforced; draft classification/availability changes invalidate review and create revisions. Withdrawal/archive/restore change state without deleting prior snapshots. There is no bulk publish, inferred-stock update, automatic mass-price rewrite or hard delete.

## Storefront and deployment boundary

Current Node source products remain in place while the user reviews native drafts. The **category** runtime integration remains separate and continues to resolve category carousel references against the existing storefront catalogue. Native product publication currently stores a reviewed native snapshot only; it does **not** turn on a live native product endpoint or update the React product/basket price model.

A native product cutover must handle multi-category/Series filtering, variant selection, image delivery, shared product rendering, authoritative quote/basket mappings and availability parity together. Do not enable a display-only price/variant override that disagrees with the server's quote calculations. The permanent deployed Laravel origin requested in the preceding slice is still outstanding; a sandbox address must not become a production upstream. Live storefront upload propagation is therefore **not claimed** in this slice. Saved native previews show new images immediately; public product propagation is a subsequent reviewed cutover.

One proven current-storefront defect was fixed directly: Shipping/Warranty tabs and product quick-info cards no longer assert generic **UAE/AED delivery promises, free assembly or unsupported normal-wear/repair coverage**. They use explicit optional product terms or an honest instruction to confirm terms with WOODEX. Existing source warranty labels are preserved without inventing scope. This does not invent replacement Pakistan shipping promises.

## Evidence

- Focused products/navigation/category suites: **95 passed / 568 assertions**. Final product/security focus: **10 passed / 100 assertions**, including 148-record idempotent draft import, real media round trip, cross-product media denial, SVG denial, duplicate SKUs, stale edits, publication prerequisites, view-only draft privacy, manager publish denial, bulk confirmation and classification, archive/restore, safe rendering and Soon routes.
- Final full native suite: **2,098 passed, 34 skipped, 9 known environment failures**, **11,224 assertions**. Cause-summary exit 0; same 2 missing-pdo_mysql installer and 7 missing-xmlwriter QR cases, no unexplained failures. The first run caught an obsolete navigation destination count; the test now explicitly records the approved four new native destinations. MySQL CI acceptance remains pending.
- Changed PHP Pint, both-app typecheck/build, render smoke, SQLite/PGlite **108/108** each, **76/76** Vercel prerenders, Hostinger smoke, source-snapshot parity and documentation/secret checks passed. Existing bundle-size/React Router warnings remain.
- Chromium mobile: owner source audit → import **148 real repository drafts** → actual source thumbnails; synthetic new product → multiple categories → actual PNG upload/alt → explicit variant price → Markdown policies → FAQ → saved preview → native publish/withdraw → product+binary-media export → archive. Shop/Series Soon pages open. Zero page exceptions. A first browser wait accidentally matched the gallery section title before the upload completed; corrected to wait for the actual image-alt input, not treated as an application failure.
- A full 148-record export with **18 embedded real image assets (2,452,576 bytes)** was generated and revalidated successfully. No source records were overwritten.
- No real source record was published. Synthetic test records were archived. No live orders, leads, invoices, stock, messages, provider actions or production data were created/modified by this rehearsal. Local test fixtures and source imports do not demonstrate real-photo identity or deployed production acceptance.

## Next

User review of imported draft records and actual photos; reviewed native product/variant/quote mappings and storefront cutover; production media/backup/retention and permanent Laravel hosting; full Series CMS and Shop customization remain Soon. This is not a wholesale migration of all Agency OS Commerce, finance or operations modules.

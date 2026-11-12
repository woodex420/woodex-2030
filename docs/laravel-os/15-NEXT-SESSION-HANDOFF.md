# Paused work — next-session handoff

Saved 2026-11-11 at the user's explicit request: **hold product improvements, save all data/context and resume tomorrow**. This document records pending requests, not completed fixes. Do not resume implementation without the user's next instruction.

## Repository and saved implementation

- Repository: `woodex420/woodex-2030`, `/home/user/woodex-2030`.
- Session branch: `arena/69363bda-woodex-2030`. Stay on this branch; do not rename or switch it.
- Last implementation commit: **25f3b26**, pushed. Working tree was clean before this documentation-only handoff.
- Read `AGENTS.md`, `docs/product/MASTER-PLAN.md`, the docs index and slices 12–14 before changing code.
- This handoff changes no product records, media, application behavior or publication state.

## Latest user feedback — start here tomorrow

1. Update `/admin/commerce` overview and rename **Commerce Overview → E-commerce** (user spelling “E commernce”; confirm exact display spelling if needed). Use the supplied Agency OS Ecommerce screenshot as a layout reference: clear summary cards, catalogue overview, recent-order area and module cards. Compute real data only; do not copy screenshot numbers or mix native/legacy financial datasets without verified integration.
2. Products: replace the combined **Import / export** entry with **two separate buttons: Import and Export**. Underlying portable package workflow already exists; improve the entry points rather than creating a second import engine.
3. **Product Preview reported broken** at `https://sbx-q3zg6xfxjkav5bof.arena.site/admin/commerce/products/86/preview`. Root cause is **unknown/unreproduced** for that deployed origin/product. Prior local synthetic-product preview passed; that does not establish that product 86 or the user's deployment works. Reproduce this exact report first, with status/logs/permissions/data/media checks. Do not claim it is fixed or guess an error cause.
4. Clean, higher-quality Products/card/editor UI/UX, informed by the existing Agency OS product editor, not another long undifferentiated form.
5. Reorganize editor around prominent **Product identity** and **Pricing & stock** sections. Preserve explicit price/availability/variant semantics; do not turn source stock flags or sample 99 quantities into verified inventory.
6. Move primary image/gallery into a **real product preview sidebar**. Clicking the main image should initiate image replacement. Show approximately **3–4 thumbnail slots** underneath as appropriate; clicking a thumbnail should select/replace that image. Preserve primary selection, ordering, removal, alt text and revision-safe retention. No editable remote image URL field.
7. **Media library synchronization/reuse**: investigate existing Agency OS/native media facilities and their authorization/storage contracts before wiring a chooser. Current native product media are private product-owned records, not an integrated shared media library. Reuse must not bypass cross-product media authorization or delete files still referenced by history. This is pending, not implemented.
8. Improve multiple-category selection into a **smart hierarchical solution**: main category → group → subcategory, search/filter, clear selected-category chips and primary-category choice. Preserve separate Series assignments.
9. Deeply compare Agency OS and native UX/routing. Screenshots show the legacy Ecommerce overview/product editor, whereas the newer native workspace uses `/admin/commerce/...`. Establish which app/build each entry point opens; do not silently edit only one app or infer that the user's screenshots show the latest native implementation.

Latest attachments in the paused user message: `image-7.png` (Ecommerce overview), `image-8.png` (legacy product editor with right preview sidebar). They are visual references, not verified business data. Earlier attachment names may repeat across turns; use the actual attached image/context, not filename alone.

## Current product/data state

- Native product management exists; see `14-NATIVE-PRODUCTS-REVIEW-IMPORT-AND-EDITOR.md` for full scope, security and boundaries.
- **148 repository source products imported as unpublished local native drafts**, with actual local source files copied privately. No real source product was published. Synthetic browser test products were withdrawn/archived; preserve their audit history unless an explicit cleanup is approved.
- Source has only **18 distinct shared photographs**; actual product/photo identity needs user review. Source colours do not establish priced colour/material/size combinations. Initial native availability is `on_request`; no stock quantity inferred.
- Import is idempotent and skips existing keys/slugs; do not rerun a destructive setup/reset or overwrite reviewed content.
- Native editor supports private file upload, primary/gallery ordering, multi-category/Series assignment, explicit variant prices/availability, Description/Specifications/Features/Shipping/Warranty, metadata, FAQs, featured/related selection, exact saved preview, review acknowledgement, revisions, native publication/withdrawal.
- Product transfer is `woodex-products-1` JSON with embedded hash-verified media; selection/all export, preview/confirm import, no overwrite/auto-publish. Limits: 500 records / 25 MB package / 16 MB binary media; product images max 4 MB and 6000 × 6000 each, gallery max 24.
- Actual 148-record source export with 18 embedded image assets was generated and revalidated: 2,452,576 bytes. Local scratch file: `/home/user/.cache/woodex-os/products-source-export.json` (not committed; regenerate through export if unavailable).
- Bulk tools support draft category/Series additions, explicit draft availability, export, publication withdrawal, archive and restore. No bulk publish, hard delete, automatic mass-price update or inferred stock.
- View-only staff see only published native product snapshots/media. Catalogue managers edit/import/export; separate catalogue publish permission is required for native publication/withdraw/archive/restore.

## Existing approved scope and remaining gates

- User chose **Products first**, reviewable draft import, category carousel controls in the full editor with clear Quick Edit shortcuts.
- Current native sidebar: Commerce Overview, Products, Categories, Series (Soon), Shop customization (Soon), then remaining Commerce modules. Latest naming change to E-commerce is pending.
- Full Series and Shop customization remain **Soon**. Existing Series organizer preserved at `/admin/commerce/series/organize`; public Series planning page `/admin/commerce/series` is not a full collection CMS. Do not invent missing Series chair/table/workstation memberships.
- Category hierarchy and sibling drag/arrow ordering are implemented, including main Home/Office → groups → leaves; Series remain separate collections. Category manual carousel uses checklist/order, with Quick Edit shortcut to full editor.
- User authorized **category** storefront sync. It works in connected local runtime with startup/15-second/focus refresh and explicit fallback/rollback. A permanent deployed Laravel origin is still missing from the Vercel connection; do not configure a temporary sandbox as production upstream.
- **Product** public backend/basket/variant cutover is not enabled. Native publication stores reviewed native snapshots only. Uploaded images update saved native previews, not current public React product data. Full storefront propagation must integrate product rendering, image delivery, multi-category/Series filtering, variant selection and authoritative basket/quote mappings together. Avoid display-only native price changes that disagree with commerce calculations.
- Existing storefront Shipping/Warranty tabs and quick-info cards were corrected to remove unsupported UAE/AED/free assembly/warranty coverage assertions. Do not replace them with invented policies.
- This is not a completed import of all Agency OS orders, finance, procurement or operations.

## Code map

- `platform/app/Domain/Commerce/Models/{Product,ProductMedia}.php` — native drafts/media.
- `platform/app/Domain/Commerce/Services/{ProductCatalogue,ProductTransfer}.php` — validation, versioning, review/publication gate, import/export/media.
- `platform/app/Livewire/Admin/Commerce/{Products,ProductEditor,ProductTransfers,CommerceWorkspace}.php` — list/bulk/editor/transfer/overview/Soon screens.
- `platform/app/Http/Controllers/Admin/ProductPreviewController.php` — private saved preview and authorized media delivery; first place to inspect for the reported preview failure.
- `platform/resources/views/livewire/admin/commerce/{products,product-editor,product-transfers,workspace}.blade.php` and `platform/resources/views/commerce/product-preview.blade.php` — native UX.
- `platform/app/Support/FurnitureNavigation.php`, `platform/resources/views/components/admin/sidebar.blade.php`, `platform/routes/admin.php` — native navigation/routes.
- `apps/dashboard/src/pages/{ProductEditor,Catalog}.tsx`, `apps/dashboard/src/components/layout/Sidebar.tsx` — legacy Agency OS reference; separate auth/data model.
- `apps/storefront/src/pages/ProductDetail.tsx`, `apps/storefront/src/components/product/SpecificationTabs.tsx` — existing storefront renderer, not native product cutover.
- `platform/resources/data/product-import-source.json`, `scripts/build-product-import.mjs --check` — generated review snapshot/source parity check in CI.
- `platform/database/migrations/2026_11_12_030000_create_native_commerce_products.php` — additive schema; no default product auto-import migration.

## Runtime/data preservation

- Isolated runtime work/preview copies live under `/home/user/.cache/woodex-os/{work,preview}/platform`; PHP at `/home/user/.cache/woodex-os/php/php`.
- Sync source non-destructively with `python3 /home/user/_woodex_os_source/phase-1b/sync-work.py <runtime-platform>`, then migrate/view:clear as needed. **Never run setup-preview reset.** Do not delete preview DB/private media or regenerate sign-ins unnecessarily.
- Preview `CATALOGUE_SOURCE_IMAGE_ROOT` points to `/home/user/woodex-2030/apps/api/public/img`. Standalone deployment must install those source files/configure a real local root before source import.
- Runtime files may not survive sandbox replacement. Code/snapshots are committed, but the uncommitted local database/private media are not a production backup. If runtime is unavailable tomorrow, check before claiming records persist; regenerate/import only into an empty/new review runtime, never overwrite an existing review DB.
- Last known servers: storefront API 3002, storefront Vite 5174, native preview 8080. Check liveness before reusing; avoid duplicate servers and never `pkill` broadly.
- Browser test: `/home/user/_woodex_os_source/phase-1b/browser/native-products.mjs`; screenshots under that browser directory's `shots/`. Test uses clearly synthetic products, publishes/withdraws only those and then archives them.

## Validation baseline (implementation 25f3b26)

- Full native suite: **2,098 passed, 34 skipped, 9 known sandbox extension failures; 11,224 assertions**. Same 2 missing-pdo_mysql installer and 7 missing-xmlwriter QR cases; cause-summary exit 0. MySQL CI/deployed production acceptance remains pending.
- Focused product/navigation/category suite: **95 passed / 568 assertions**; final product/security focus **10 passed / 100 assertions**.
- PHP Pint, both-app typecheck/build, render smoke, SQLite/PGlite 108/108 each, Vercel 76/76 prerenders, Hostinger smoke, source parity, docs verification and secret scan passed. Existing size/React Router warnings remain.
- Chromium local mobile workflow passed with zero page exceptions. **User's subsequent product-86 Preview failure supersedes any assumption of universal preview success**; investigate it first tomorrow.

## Suggested first session tomorrow

1. Confirm branch/status and preserve the current DB/media; read this handoff and latest user direction.
2. Reproduce the exact Preview 86 failure on the reported origin; distinguish routing/auth/version/data/file/deployment failures with evidence, add a regression test and repair before cosmetic work.
3. Compare legacy/native overview and product editor; settle layout and entry points, rename overview to E-commerce, split Import/Export.
4. Implement preview-sidebar gallery and click-to-replace/media chooser with safe media ownership/revision behavior.
5. Improve hierarchical multi-category/primary selection and Product identity / Pricing & stock layout; keep unverified source quantities visibly unverified.
6. Run focused permission/data/media/preview tests, mobile+desktop browser checks, full regression and required repository checks; update docs without claiming live product cutover or full Shop/Series completion.

## Resumed product UX slice — 11 November 2026

Implemented E-commerce navigation/overview with real catalogue counts, recent saved records and honest Soon modules; separate Import/Export actions; responsive editor with prominent identity/pricing, sidebar gallery, selectable thumbnails, click-to-replace and retained historical media; hierarchical category search/chips/primary selection; authorized product/website media reuse via private product-owned copies; same-origin inline saved preview and relative media URLs. No CRM attachment aggregation, data reset, production changes or storefront product cutover.

Validation for this slice: 31 focused tests passed / 362 assertions (native products, navigation, Commerce feature tests), including replacement retention/review reset, website permissions, path rejection, category consistency and relative preview URLs. Vite production build passed (existing large-chunk warning). Authenticated browser checked overview/list/editor HTTP 200; inline product86 preview rendered both actual images (768/800 natural width), no JS exceptions, mobile viewport390 had scrollWidth390. This is focused validation, not a fresh full-suite run; earlier full-suite totals remain specific to the earlier commit.

The originally reported external Arena URL still failed with ERR_CONNECTION_CLOSED during diagnosis. Local authenticated preview86 and the new inline preview work; do not claim the reported deployment/origin problem resolved. Permanent origin and live native product cutover remain pending. Preview database/media were preserved, not included as Git backups.

## Follow-up hardening and full regression — 11 November 2026

- Website media picker now omits missing/unsupported/unsafe images using the same validation as the authorized image endpoint. This prevents broken thumbnails without weakening image delivery checks. Selection remains revalidated server-side.
- E-commerce product totals now exclude archived records, matching the recent-product cards. Added regressions for both fixes.
- Fresh native full run: **2,105 passed, 34 skipped, 9 known sandbox-extension failures, 11,262 assertions**. The summary was rerun with `WOODEX_PHP=/home/user/.cache/woodex-os/php/php` (the actual test binary): 2 installer cases confirmed missing pdo_mysql, 7 QR cases confirmed missing xmlwriter, no unexplained failures. A summary using the system PHP incorrectly treats those extensions as available; always pass the test binary to the summary too.
- Both-app typechecks, render smoke, secret scan, docs verification, isolated SQLite QA108/108, PGlite QA108/108, Vercel production build76/76, and Hostinger smoke passed. Existing React Router/build-size warnings remain. QA used disposable databases, not production or the preserved native preview DB.
- Browser rechecked overview/products/editor200, saved inline preview86 with both actual images, no page exceptions and no horizontal overflow at390px. No production changes, no native storefront cutover, and no claim that the originally reported external preview origin is repaired.

## Owner correction: Media is the master system

Owner explicitly clarified Website → Media must become the complete master media source, with optimized separate product folders, organization, usage-aware replacement and connected live-storefront/product-editor synchronization. See `16-CENTRAL-MEDIA-PLAN.md` for boundaries and next implementation slices. Current website→product copying is not real-time synchronization.

First correction slice opens only Media independently of the disabled Website module, adds upload/classification folders + filtering and authenticated thumbnails, strengthens native CMS deletion protection, and repairs the screenshot preview gallery sizing/selection. Focused tests:31/210 assertions plus module/navigation21/326; browser Media200, preview gallery first/second switching,390px no overflow, zero page errors. No new full-suite run for this slice. Exact supplied external origin still closes the browser connection; local preview86 works. Central asset versioning, optimization pipeline, usage graph and cross-app live sync remain to implement; do not claim the full request complete.

## Reused Media upgrade after owner correction

Owner rejected a parallel media system and explicitly requested reuse/integration of the existing Laravel Media library with the React storefront. No existing media was deleted. Interrupted master/version support was retained as an internal preservation layer, not a replacement management UI. See16-CENTRAL-MEDIA-PLAN.md's latest implementation section for workflow and deployment boundaries.

Implemented React-original import into existing website_media, product-vs-website folders, replace/upload/reuse confirmation, shared-key publication with website.publish, original rollback, reuse of old ImportLegacyMedia optimizer, retained originals/pinned product revisions, public allowlisted manifest/versioned image bridge and frontend15sec/focus polling with original fallback. Local preview imported92real React raster files; same-original-bytes mapping tested end-to-end via dedicated React5176/API3004 previews. Existing preview8080/data preserved;148source products remain drafts. Full live product/checkout cutover was not performed. Production sync remains disabled pending a permanent Laravel origin; never describe local preview success as deployed live synchronization.

Full native run2115passed/34skipped/9known extension failures/11316assertions; later focused39passed/256assertions and storefront-media4/26. SQLite and PGlite109/109, both-app types, render smoke, media-sync regression and secret scan passed. Local Vite HMR websocket warning observed separately from app errors. Final production-build results follow below when known.

Final production-build verification passed: Vercel76/76 routes prerendered, restore drill and Hostinger smoke passed. Route-metric docs updated216→218 for the two new public media bridge handlers. Final focus39/256 remains green. Existing development HMR warning and build-size/React Router warnings are not hidden as application regressions. No production deploy/configuration was performed.

# Office/Home category correction and series logic

2026-11-11. Corrects the missing population/integration in slice 10. No product/photo import, backend cutover, or product-editor completion is claimed.

## Analysis performed

Reviewed the repository's `docs/agency-os` current-state/engineering guidance, existing Node snapshot/loader, React Header/Shop/product data and Series/SeriesDetail, and the native category implementation. This is a **local source review**, not a live check of the production Vercel site or a separate external Agency OS repository.

The old native screen had CRUD but no requested category defaults. The storefront had independent Header/Shop/category-group lists (including mismatched legacy aliases); `segment` links did not filter; series badges used static counts and copy said four despite five series. Categories occurring in the menu but absent from products were not equivalent to missing category definitions.

## Shared taxonomy and native population

`platform/resources/data/commerce-taxonomy.json` is the shared versioned definition used by React navigation/filter helpers and the additive Laravel installer:

- **Office Furniture** → **Office Tables** → Executive, Manager, Staff, Meeting, Reception Tables.
- **Office Furniture** → **Seating & More** → Office Chairs, Workstations, Cubicle Workstations, Office Sofas, Office Storage, Cafe Furniture, Public Sitting.
- Existing **Home Office**, **Acoustic Furniture**, **Collaborative Furniture** are retained under Seating & More so no existing catalogue products disappear from segmentation.
- **Home Furniture** → **Bedroom** → Bed Sets, Bedside Tables, Dressing Tables, Mirrors, Bench & Settee.
- **Home Furniture** → **Living Room** → Home Sofa, Center & Side Tables, Coffee Tables, Console, TV Units.
- **Home Furniture** → **Dining** → Dining Sets, Dining Chairs, Dining Tables.

Total: **35 native categories** (2 roots, 5 groups, 28 leaves), plus any operator-created categories. Native activation does not publish to the public storefront. The category screen shows both Office/Home outlines, including empty leaves, without requiring the operator to discover them across pagination. Existing search/edit controls remain.

The installer inserts only missing slugs, preserving existing names, descriptions, parent changes, sort order and activation. Bootstrap reference inserts intentionally do not create staff audit events; subsequent real admin edits remain audited. The data migration is forward-only: rollback does not delete customized or linked categories. An existing operator slug collision is preserved rather than silently reparented; reconcile such custom collisions explicitly before publication.

## Storefront behavior

Header menus, category groups, flat category selectors and Shop's Office/Home trees derive from the shared definition. Existing slugs and links remain compatible; old `office-storage`, `cafe-furniture`, `public-sitting` aliases map to canonical `storage`, `cafe`, `public`.

`/shop?segment=office` and `/shop?segment=home` now filter existing product category/subcategory membership. Parent-category selection includes descendants; leaf selection matches only that category/subcategory. Category plus segment URLs use their intersection. Clicking a category or All Products in the sidebar clears the prior segment, preventing a stale Office segment from hiding Home results. Search/sort still apply. Empty categories display the existing honest empty state, never substituted or invented products.

The current source has **143 Office products and 5 Home products**. This is source-derived, not a target inventory count. Missing Home product coverage remains an import/content gap. Featured Collection, Make Your Workspace Work, New Arrivals, WOODEX Home Collection and View All Products are promo/navigation labels, **not categories**.

## Series

Ek, Infinity, Woodex, Cubicle and Nova remain separate **collections**, not category ancestors. Header series links use the shared definition. Series landing/detail pages continue to select products by the existing `product.series` key; product counts now derive from actual membership rather than `seriesList.products` metadata. Both pages subscribe to existing runtime catalogue refresh events, so changed membership updates without a page rebuild. Collection-count copy derives from the actual series list. Nova detail uses the existing Nova image rather than the unrelated fallback.

No new series association is guessed, no product count invented, no native series-to-product editing UI introduced. Structured variant and native product/series assignment remain the next implementation slice. Existing commercial descriptions/features in the source are not newly verified warranty promises.

## Snapshot guard correction

Source review exposed a pre-existing circular check: `build-seed-data` loaded the already-generated snapshot, so it could never detect source drift while that snapshot existed. Added an explicit source-only loader mode **for generation/checking**, retaining snapshot-first loading for production API boot. Regenerated the snapshot: only the category-group metadata line changed; all 148 products, prices, materials and services are unchanged. No existing Node DB rows or hosted data were migrated.

## Evidence

- `node scripts/test-commerce-taxonomy.mjs` (Node CI): every existing product belongs to exactly one segment, parent/leaf logic, aliases, known series keys and five series checked.
- Focused Laravel category/taxonomy/import regression: **11 passed, 173 assertions**. Preserved custom edits/repeated installation and full parent structure checked. Bootstrap audit logging initially produced reference-data entries that violated an unrelated import's clean-audit baseline; bootstrap logging was suppressed (matching existing import conventions), then both suites passed with actual admin auditing retained.
- Final full local suite: **2,069 passed, 34 skipped, 9 known extension failures**, 10,939 assertions. Cause-based summary exits 0: only two missing-pdo_mysql installer and seven missing-xmlwriter QR cases, no unexplained failures.
- Both-app typecheck/build, render smoke, source/snapshot parity, SQLite and PGlite QA **108/108 each**, Vercel **76/76** prerenders and Hostinger smoke pass. Existing bundle/React Router warnings remain. MySQL/Node CI results for this revision remain pending.
- Real Chromium mobile: Office 143 / Home 5 results, empty Bedside Tables state, five series and actual Woodex-series count 18; zero page exceptions. Separately signed in to native admin and verified both category outlines and missing leaves on mobile. Temporary browser server stopped. Preview data/sign-ins preserved.

## Remaining gates

Product editor with primary/gallery durable upload/reorder/replace and structured priced/available variants; native series management/product assignment; reviewed external-key product import/mappings and variant parity; real photo verification; backend publication/cutover acceptance. Default-off native basket request intake stays gated. No production deployment configuration, credentials, provider messaging, stock reservations or issued documents were changed.

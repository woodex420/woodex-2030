# Category ordering and storefront sync

2026-11-11. Owner explicitly authorized the live-category cutover and reconfirmed **Series as separate collections**, not category ancestors. This supersedes the previous native-only publication decision. Authorization does not mean that production routing or Laravel hosting already exists.

## What changed

- Categories now opens an actionable recursive tree, not two static Office/Home summary cards. Main categories, groups and subcategories each have Edit and Quick Edit controls.
- Drag a row onto a sibling to move it to that sibling's slot; ↑/↓ buttons provide a mobile/keyboard alternative. This includes main Home/Office ordering, Office Tables/Seating groups, Bedroom/Living/Dining and their leaves. Parent changes remain explicit Quick Edit operations, not accidental cross-parent drops.
- Ordering is saved transactionally, audited and versioned. A stale tree rejects with a reload instruction. Read-only staff cannot reorder; category-content editors opened before ordering changes must refresh before saving.
- Separate Series organizer at `/admin/commerce/series`, linked prominently from Categories, supports drag/arrow ordering. Additive `commerce_series` migration installs only the five existing collections. Product memberships remain the existing repository Series keys. Series content creation/editing and native product assignment are **not** claimed complete.
- New category creation continues from identity/parent setup into the full editor: hero upload, Markdown intro/bottom content, featured/hot carousel settings, FAQs, preview and publication. The metadata modal is deliberately not a second, incomplete CMS.

## Public contract

`GET /api/v1/catalogue/categories` is a read-only, public schema-1 projection, separate from lead capture/module flags. It contains active reachable categories with native ID/slug/parent/order/name, published content only, and ordered Series. An inactive parent removes its descendants from the feed. No author, preview receipt, draft pointer, filesystem path or unpublished copy is returned.

Published hero images use `/api/v1/catalogue/categories/{category}/hero/{revision}`. The route requires the exact current published revision and active ancestors, validates the private storage path and uses `nosniff`/no-store. Historical and draft files remain inaccessible publicly. Unpublish withdraws the page and image while retaining immutable revisions. Missing images are omitted rather than replaced with invented photos.

Formatted content uses the **same Blade content-block renderer and content CSS** in authenticated previews and the public projection. React consumes only that server-rendered, escaped/sanitized markup; it does not invent a second hero/intro/bottom/FAQ template. Preview carousel cards remain explicitly labelled references; published storefront carousels resolve returned reference keys to the existing React ProductCard component, with its real existing image/price/quote behavior. Unresolved keys are omitted, not fabricated. Reference eligibility is recalculated after category-tree changes. Sales/popularity rankings are not invented.

## Storefront runtime

- Header desktop navigation, shop sidebar/parent ordering, descendant filtering, home category row and Series menu/page order use native data when connected.
- Fetch at startup, every **15 seconds**, and browser focus. This is bounded polling plus focus refresh, not a promise of instantaneous WebSocket/SSE delivery.
- Draft saves do not change public page content. Name/order/active/tree changes affect navigation immediately in the feed; Save → Preview → Publish controls page content. Unpublish returns that category page to the existing storefront content fallback.
- On first-load unavailability the existing bundled tree/content is used. During a transient outage retain the last valid in-memory projection. No localStorage draft/content persistence is introduced. Explicit Node `NATIVE_CATEGORY_SYNC=0` resets an open client's native projection to the bundled fallback on its next refresh.
- Existing product source slugs/aliases remain classification keys. Keep slugs stable until a reviewed product-category mapping migration is available; renaming a slug without remapping source classifications can empty its product eligibility. New categories legitimately start empty. Native product classification/import and variant parity remain separate work.
- Runtime metadata/canonical/indexing changes are applied on category-page hydration. Build-time prerenders still use the bundled fallback; this is not a claim of dynamic native SSR or instantly refreshed search-engine indexing.

## Deployment and rollback

### Laravel

Deploy the native application, run additive migrations (including category content and Series), and back up private storage/database. `CATEGORY_SYNC_ENABLED=true` (default for this approved implementation) enables the public feed. Setting it false closes the native feed/images. This alone does not connect a separate Node/Vercel storefront.

### Vercel / single-process Node storefront

Set server-only `NATIVE_CATALOGUE_ORIGIN` to the **deployed Laravel HTTPS origin**, without a path/query/credentials, then deploy the current revision. The same-origin Node bridge handles only the fixed catalogue and numeric hero paths. It forwards neither browser cookies nor authorization, refuses redirects, bounds response size/type, times out and never forwards staff/editor endpoints. Production HTTP/localhost origins are rejected. Browser code always calls relative URLs.

Rollback: set `NATIVE_CATEGORY_SYNC=0` and redeploy the Node runtime. Clients revert on their next refresh. Keep native revisions and files intact; do not roll back schema just to disable the storefront consumer. Backend-only flag closure intentionally behaves like an outage to clients already holding a valid projection; use the Node switch to force fallback.

For local rehearsal, Vite proxies `/api/v1/catalogue` to the native preview using the existing local proxy environment variable. **Never put the sandbox origin in production settings.**

### Actual deployment status

Vercel project lookup found `woodex-2030` with Node deployments and no native catalogue origin configured. The native Laravel app in this session is a local sandbox runtime, not a verified deployed production origin. No Vercel environment/domain/production deployment was changed to point at that temporary runtime. A permanent deployed Laravel origin is required before production sync can be finished. User approval is recorded; the infrastructure prerequisite is still outstanding.

## Verification

- Category/CMS/taxonomy/sync focus: **25 tests / 231 assertions** before shared content extraction; the final full suite covers the extraction as well.
- Final full native suite: **2,088 passed, 34 skipped, 9 known environment failures**, **11,105 assertions**. Cause summary exits 0; remaining failures are the same 2 missing-pdo_mysql installer and 7 missing-xmlwriter QR cases. Architecture strict-type checks pass.
- Native runtime/bridge test script covers changed names/order/descendant filtering, Series order, malformed schema rejection, last-good outage, explicit rollback, HTTPS-origin validation, allowlisted paths, content types and credential isolation; added to Node CI.
- Both-app typecheck/build, render smoke, SQLite and PGlite **108/108** each, taxonomy/reference parity, Vercel **76/76** prerenders, Hostinger smoke and documentation/secret checks passed. Existing chunk-size/Router warnings remain; MySQL CI/deployed production acceptance is pending.
- Chromium mobile: real private hero upload and formatted intro → saved preview → publish → actual storefront hero/intro/three ProductCards/bottom/FAQ → unpublish fallback. Browser drag/drop reordered Home/Office and updated an already-open storefront without reload; original order restored. Series screen opens. Zero page exceptions. Synthetic rehearsal content remains unpublished; no real catalogue/media/provider records were fabricated.
- An initial full run caught missing strict-type declarations in new application classes; fixed and rerun. Concurrent focused/full fake-storage tests briefly interfered; final regression ran in isolation. Architecture tests require the suite's 2 GB memory budget, not CLI's 128 MB default. A browser text-selection race was fixed in the harness before the passing run.

## Still next

Permanent Laravel hosting/origin and production verification; native Products editor, structured priced/available variants, primary/gallery upload ordering and reviewed import/assignments; full Series content management. Current category content work does not complete those modules.

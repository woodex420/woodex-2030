# Commerce workflow upgrade — approved phased implementation specification

Authority: `docs/product/MASTER-PLAN.md`. This is its detailed Commerce implementation specification, not a competing roadmap. Owner approved the recommended catalogue-first approach after providing product-editor and multi-select media-library references.

## Decisions and limits

- Reuse Laravel Commerce, Categories, Series organizer, Website Media, draft/revision/preview/publish and transfer services. Reuse the Agency OS interaction patterns, not its invented defaults or a second authentication/data store.
- React storefront is the frontend target. Keep authoritative prices, variants, quote/basket mapping and publication aligned before product-backend cutover. Media key sync is separate from product price/content sync.
- Regular + optional lower sale price, optional UTC start/end (end exclusive), explicit per-variant prices/sales. No implicit variant discount. Manual Hot/Featured flags and featured order, not invented verified bestsellers.
- Home/Office → groups → leaves. Multiple categories + one primary; parent/group assignments remain allowed to preserve current data. Series are independent collections.
- Save draft and Publish/Update are separate. Quick edit never publishes and resets review. Keep exact saved-preview and permission gates.
- Uploaded video plus approved YouTube/Vimeo embeds are the proposed video target, pending storage/codec/embedding acceptance. Neither is claimed implemented in Phase1. No arbitrary iframe HTML or remote-fetch URLs.
- Preserve all existing products, media, originals and revision references. No production changes, database reset, fabricated records/photos/stock/reviews or silent publication.

## Audited flow and findings

1. **Existing native flow:** Products → full editor → identity/pricing/classification/variants/content/SEO + private gallery → save → own saved preview → review + explicit publish. Portable image transfer, confirmed bulk draft classification and archive/withdraw already exist. Website Media reuse retains immutable master references; React `/img/…` replacement sync is opt-in.
2. **View-only search defect:** list projected published card identity, but SQL search matched newer draft names. A viewer could test a private draft name through search results. Fixed by querying the product-owned published revision for search/classification and projecting it before Blade; test reproduces the old leak and verifies isolation.
3. **Quick-edit gap:** only full editor existed. Added a locked product/version quick editor for name, regular/sale pricing/schedule, availability, categories/primary, Series and Hot/Featured/order. Merge only allowed fields into the saved full draft, retaining descriptions, galleries, variants and publication pointer. Optimistic conflict rejection and archived-product protection remain enforced.
4. **Classification ambiguity:** bulk choices used bare category names. Full ancestry paths now appear in quick edit, bulk choices and list filters. Category filtering now includes selected branches and all descendants; Series filters remain direct independent membership. See the phase3 progress note below.
5. **Hidden bulk selections:** search/status changes previously retained selected IDs. Filters now reset selection and confirmation so hidden rows cannot be accidentally acted on.
6. **Published status:** published filtering/count now exclude archived rows. View-only totals omit draft/archive counts and view-only cards do not expose newer review issues. Published search/filter metadata uses the saved snapshot, not draft metadata.
7. **Merchandising gap:** added native draft sale schedules, manual flags/order and independent variant sale fields. Integer-cent comparison, lower-price rules, date format/window validation and UTC boundary evaluation. Older drafts/packages receive safe defaults; new fields survive validation/export/revisions. Saved preview evaluates current sale windows. Live React prices are intentionally unchanged until the authoritative cutover phase.
8. **Agency OS reference cautions:** legacy ProductEditor uses fallback stock10/99, prefilled photo/content/options and rating/review fields. Do not copy those defaults. Its product table/shape is not the native revision schema.
9. **Existing category safety is reusable:** parent edits already lock the tree and reject self/ancestor cycles; reorder already validates sibling groups and tree fingerprints. Extend those controls rather than implementing another taxonomy. Keep full category-content/carousel editor plus Quick Edit.
10. **Media/video gap:** current product picker selects one image and closes; image reorder/remove exists. A true multi-select staging tray and mixed image/video gallery, poster handling, portable video transfer and frontend playback are still required. Screenshot behavior is a target, not current functionality.
11. **Runtime limitation:** exact supplied Arena origin previously closed connections in sandbox probes; authenticated local Products/preview work. Do not call an external transport issue fixed by local UI changes. Permanent Laravel upstream/storage remains a deployment prerequisite.

## Delivery phases and acceptance

### Phase1 — product management cleanup and merchandising drafts (this slice)

Quick edit; full-path classification choices + category/Series filters; clear hidden selections; published-snapshot search isolation; sale schedule + Hot/Featured/order in full and quick editor; independent variant sale fields; active sale prices in saved native preview. Drafts/older packages remain compatible. Regression tests cover privacy, permissions, optimistic conflicts, content/publication retention, money/date validation and UTC boundaries. No new publications or source product mutations are needed for browser layout tests.

### Phase2 — shared media selection and gallery/video workflow

Extend **existing Website Media and its picker** with image/video kinds, upload and library tabs, searchable folder grid, multi-select, details sidebar, staged selection count and explicit Add to gallery. Preserve current image IDs and old revision references. Add/remove/reorder staged slots, set primary/poster and per-slot alt/caption, then Save/Publish. Removal detaches the slot; do not delete shared bytes.

Video requirements: allowlisted YouTube/Vimeo IDs only (no arbitrary iframe markup), MP4/WebM codec/container inspection rather than trusting filename/MIME, bounded upload/storage/processing budgets, processing/error states, required poster image, keyboard-accessible playback, no autoplay with sound, lazy loading and portable-transfer versioning. Derive actual duration/dimensions when tooling is available; do not invent metadata. Private drafts never become public merely by uploading. Prove video preview/playback and publication before enabling the frontend feature.

### Phase3 — hierarchy, Series and Shop management

Extend existing category tree with descendant-inclusive catalogue filters/counts, explicit move target/ancestor paths, inactive-branch warnings, assignment-impact preview and rollback-safe moves; keep existing cycle/concurrency/order safeguards. Category deletion must account for descendants and actual assignments. Preserve custom content/carousels and reviewed slug/redirect behavior.

Extend Series organizer into collections with membership, manual/automatic rules, order, hero/SEO, draft/preview/publish and empty states. Build Shop's section configuration by reusing existing content/revision patterns: category sections, featured/manual-hot, active-sale and Series sections; real products only, ordered deterministically. Draft-only products must never appear as live recommendations. Leave Soon labels honest until working acceptance.

### Phase4 — authoritative React catalogue projection and product cutover

Publish immutable native product/variant/merchandising/media snapshots through an allowlisted projection; validate source/external IDs and quote/basket mappings. Compute effective sale prices server-side for catalogue display AND quote/order totals using the same service and clock; UTC transitions must work without scheduled rewrites or rebuilds. Tie frontend cache versions to publication, with last-known-good behavior, rollback and visible connection state. Do not mix a native displayed discount with legacy checkout pricing. Confirm permanent origin, persistent media and production acceptance before switching traffic. Separate media-only sync does not satisfy this gate.

### Phase5 — operational Commerce (later)

After catalogue/shop acceptance: Orders → Delivery & Installation → Support & Warranty → Operations. Define stock reservations, payments, returns/refunds and audit/reconciliation against real integrations before implementation. Existing screens retain their own permissions and remain clearly legacy/Soon where appropriate; no fake operational metrics.

## Reuse map

- Native management: `app/Livewire/Admin/Commerce/{Products,ProductEditor,Categories,CategoryEditor,SeriesManager,CommerceWorkspace}`.
- Validation/revisions/media/transfer: `Domain/Commerce/Services/{ProductCatalogue,ProductMerchandising,ProductTransfer,ProductLibrary,CategoryContent}`.
- Existing library + preservation: `Livewire/Admin/Website/MediaLibrary`, `Domain/Website/Models/Media`, `Domain/Website/Services/StorefrontMedia`, `Domain/Media/Services/MasterMedia`.
- Agency reference: `apps/dashboard/src/pages/{ProductEditor,Catalog}.tsx`; React frontend: `apps/storefront/src/lib/{runtime,mediaSync,nativeCategories}` and actual product/gallery components.
- New phase1 regression: `tests/Feature/CommerceWorkflowTest.php`; retain NativeProducts, media, category, transfer and RBAC tests.


### Phase2 image-picker progress

Multi-select image staging, attachment details, selection ordering, separate upload/library tabs, folder/search pagination, duplicate skipping and atomic reference rollback are now implemented in the existing product editor. Existing gallery removal/reordering remains. Videos, full Shop/Series and product/quote cutover are not implemented by this slice. See18-PRODUCTION-READINESS.md and `commerce:readiness --production`; strict readiness currently blocks complete production Commerce rather than certifying it.


### Phase3 progress — descendant-inclusive product filtering

Products category filters now include direct assignments plus every descendant, including inactive assignments so management audits do not hide existing classification. Existing full path labels remain, with inactive options identified and a scope hint. Traversal uses one category-tree query and a visited set, so malformed legacy cycles terminate. The grouped JSON predicates cannot escape search, Series, publication or archive scopes; unknown/malformed filter IDs return zero results. Managers filter saved drafts; view-only users filter only product-owned published revision content, never newer draft assignments. No category/product records or publication pointers are changed by filtering.

This is only the filtering slice of phase3. Descendant counts, move/assignment-impact previews, full Series/Shop workflows, videos and native React product/price/quote cutover remain pending. Existing category cycle/concurrency/move safeguards are unchanged.

Validation:35focused tests/244assertions, plus23existing category CMS/sync tests/195assertions passed. Tests cover direct/inactive/grandchild membership, unrelated branches, search grouping, malformed/unknown IDs, published-vs-draft privacy and malformed legacy cycles. Browser preserved preview: Products200, real category option selected, scope hint present,390px scrollWidth390, no page errors; no records saved/published. Both React types/render and PGlite109/109 passed; PGlite tests are React API tests, not a PostgreSQL-specific Laravel JSON-query acceptance run.

Deployment-shape checks for the filter slice also passed: isolated disposable SQLite109/109, Vercel76/76routes rendered and Hostinger smoke. These are build/local acceptance, not live host deployment.

### Phase3 progress — branch counts and guarded Quick Edit moves

The category list now reports distinct direct/branch published-product counts. Managers additionally see counts from saved drafts (including the saved draft of a published product). Counts exclude archived products, and published counts require a product-owned publication pointer. View-only staff receive neither draft count keys nor draft count markup. Counts are computed from native records; they are not proof that those products have been cut over to React.

Quick Edit destinations show full ancestor paths/inactive labels and exclude the moving branch. Preview move impact shows old/destination parent paths, descendant count, saved-draft and published branch-product counts, and inactive-destination warning. Moving an existing category requires this preview and explicit confirmation. Both preview and save reject stale category-tree versions; save locks the tree, repeats cycle checks and recomputes the displayed impact before accepting the confirmation. Changed aggregate assignment counts require a fresh preview. Parent changes reset confirmation. Existing reorder controls retain their separate sibling-only safeguards.

Moves retain child relationships, product assignments, slugs and category page revisions/publication pointers. They change the connected category hierarchy immediately, not only a draft; the preview explicitly warns about that. Moving back through the same preview/confirmation flow reverses the parent change; no automatic undo/history UI is claimed. Counts are advisory snapshots, not product reservations or an exact membership fingerprint: concurrent product edits can continue and equal-count membership substitutions are not a blocker. No destructive category deletion was added.

Remaining phase3 work includes full Series/Shop workflows, richer move/redirect/delete impact policies and any scale-specific count optimization. Video and authoritative native product/pricing/quote projection remain separate unfinished gates.

Branch-impact acceptance: final focused37tests/289assertions passed; full native snapshot2139passed/34skipped/9confirmed missing-extension failures/11476assertions (2pdo_mysql,7xmlwriter). Full snapshot precedes a final parent-path/advisory-copy view refinement; final focus includes it. Browser opened a real preserved category, selected destination, inspected impact and cancelled without saving;390px no overflow/no page errors. Types/render, isolated SQLite109/109, PGlite109/109, native assets, Vercel76/76 and Hostinger smoke passed. None constitute hosted deployment or Laravel PostgreSQL-specific acceptance. Existing data and media preserved.

### Phase3 progress — manual native Series collection drafts

Existing Series organizer now links to an authorized manual collection editor for each existing collection. The additive `2026_11_12_060000` migration adds collection revision history, user-specific preview receipts and draft/publication pointers; it does not reseed Series or rewrite legacy identity/membership. Existing menu names/slugs/order/active state are preserved. Editors can save heading, plain-text introduction, SEO title/description and up to100ordered native product IDs; reorder/remove selected products, including removal of unavailable products. Explicit empty collections are supported.

Only currently published, non-archived native products with product-owned publication pointers are eligible. Display names/slugs are projected from their published revisions, never newer private drafts. Collection membership is independent curated content: it does not rewrite product `series_ids` or repository/React memberships. Draft-only records cannot become public recommendations merely because an editor selected them. Missing/withdrawn/archived selections block new save/manager preview/publication until removed; published viewers omit ineligible products safely.

Save creates a new immutable collection-content revision and leaves the existing publication untouched. Saved preview is authenticated, same-origin, private/no-store/noindex, escapes all text and records the exact revision/current eligible-product projection per user. Publication requires current locked version, current draft ownership, same user's exact preview receipt, no unsaved form changes and explicit confirmation. A product's newer private draft does not invalidate a receipt; changing its published revision does. Withdraw clears only the native publication pointer, retaining draft/history. View-only staff can preview only the current native publication, never private or historical collection revisions, and do not gain editor permissions.

This is **manual native collection content**, not completed full Series or live storefront integration. Existing `/commerce/series` and Shop remain honestly Soon; its organizer exposes the bounded draft slice. New-collection creation, collection identity/redirect editing, hero media, automatic membership rules, FAQs/full collection layouts, history restore UI, public React collection rendering and Shop section configuration are not delivered here. No live collection/product/price/quote cutover occurred. Preview intentionally lists product names without claiming live product pricing or rendering parity with a future React collection page. Product publications are resolved at display time, so membership/content revisions are immutable but referenced product publications are not pinned forever.

Manual-Series validation:38focused tests/293assertions; full2146passed/34skipped/9known extension failures/11541assertions (2pdo_mysql installer,7xmlwriter QR), with no unexplained failures. Types/render, isolated SQLite109/109, PGlite109/109, native assets/Pint, Vercel76/76 and Hostinger smoke passed. Browser preserved preview editor/mobile390px had no overflow/errors and no saved changes; zero eligible native publications meant browser membership was empty. Populated order/preview/publication flows are covered by disposable test fixtures, not claimed as a live browser publication. Additive migration applied locally without reset/reseed; existing products/media preserved. No hosted migration/deployment/cutover performed. Native target-host database acceptance remains mandatory.

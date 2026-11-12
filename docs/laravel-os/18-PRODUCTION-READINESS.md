# Commerce production-readiness gate and image gallery delivery

This is a status/runbook under `docs/product/MASTER-PLAN.md`, not a declaration that complete production Commerce has shipped. The owner requested the next production implementation phase. **No live deployment, provider configuration or catalogue/price cutover has been performed.**

## Delivered image-gallery slice

The existing Laravel product editor/Website Media now has a shared, paginated image picker: Media Library and Upload tabs, multi-select checkboxes, website folder/search filters, attachment details (actual dimensions/size/alt), selection ordering and explicit Add selected to gallery. Private product results are deduplicated by content and default to active product sources; archived files are retained. Pagination preserves selection and can reach supported older images even when the newest page contains unsupported/missing files.

- For existing products, Add selected stages slots in the form; it does **not** save draft content or publish. The sidebar warns about unsaved gallery changes. Save draft → review → saved preview → explicit publish remains mandatory.
- New products first need a private draft owner row; gallery slots are still staged. Failure rolls back that new row and all database references.
- Upload tab intentionally reuses the existing Upload and save gallery draft action; its different persistence behavior is explicitly labelled. No shared website original is overwritten by selecting or uploading a product image.
- Existing primary/order/alt are preserved on append; duplicate content is skipped, additions remain ordered, review is reset and the total gallery limit is24. Gallery remove/reorder remains available in existing settings; saved/historical media is not deleted.
- Each selection is reauthorized and revalidated at Add time. Website sources need website.edit; all mutations require catalogue management. Locked product version, inspection metadata and product IDs prevent stale/forged-state changes. CRM/customer attachment models are not queried.
- A failed batch rolls back its database changes and restores component form/product/version state. Content-addressed private files written before rollback may remain unreferenced; they are deliberately not blindly deleted because other references may share bytes. This is database atomicity, not a claim that filesystem writes are transactional.
- **Video upload/embeds/posters and mixed-media rendering are not enabled.** Do not advertise the picker as a completed video gallery.

## Read-only readiness command

From an installed Laravel release:

```sh
php artisan commerce:readiness
php artisan commerce:readiness --production
```

The command checks configured schema, image inspection, local storage read/write access, real source-image directory and registered routes. Strict mode also checks production environment, debug disabled, configured key (never prints it), a permanent HTTPS app URL and MySQL driver availability. It records the remaining feature/host-acceptance gates explicitly. It inspects the configured database/schema and local storage; it does not mutate them, publish, reset, enable modules, call a deployment provider or initiate external sync.

**Strict mode must currently exit1.** Its pending-feature gates are intentionally checked into code and must only be updated after the corresponding implementation and acceptance land. A non-strict success means local prerequisites were inspected, not that complete Commerce is production-ready. Filesystem permission checks do not prove persistence/backups; URL syntax does not prove reachability.

Preserved preview execution returned7 blockers: non-production environment, non-permanent application URL, missing MySQL driver, mixed video gallery, full Shop/Series management, authoritative product/price/quote cutover and target-host acceptance. Debug/key checks passed in that local runtime; this does not certify any real host configuration.

## What blocks complete production Commerce

| Area | Actual state / required acceptance |
|---|---|
| Product drafts, quick edit, schedule/manual merchandising | Implemented; native draft/preview/publication only |
| Image gallery/library | Multi-select image delivery implemented; no video claim |
| Video | Upload/container/codec checks, embed allowlist, posters, playback and portable format remain pending |
| Categories | Existing cycle-safe tree/reorder/full-content editor reused; descendant filters, direct/branch counts and guarded Quick Edit move previews implemented; richer redirect/delete impact policies remain pending |
| Full Series and Shop management | New inactive Series creation, content/SEO, manual/automatic published-product membership drafts, preview, native publish/withdraw implemented. Private hero upload/library selection, native layouts/bottom text/FAQs implemented; activation/redirect/public React collection integration and Shop remain pending; honest Soon screens retained |
| React image replacement | Implemented opt-in media-only mapping; permanent origin/storage/deployed acceptance still required |
| React product/pricing/quotes | Authoritative native product/variant/sale projection and quote/basket parity not implemented; do not mix native display prices with legacy charged totals |
| Host acceptance | Confirm target host + permanent Laravel URL, persistent public/private media, backups/restore, MySQL migrations/permissions, authorization and end-to-end publication/rollback |
| Deployment | Not performed; pushing code to the working branch is not deployment |

## Host rollout gates

1. Supply the target hosting environment and permanent HTTPS Laravel origin. Never use an Arena/e2b/local preview as the permanent upstream. Do not paste credentials into chat; use the hosting environment/connection controls.
2. Back up the actual host database and public/private media and prove restore. Git source commits and a portable product export are not a full production backup. Never reset or reseed an existing database to deploy.
3. Install additive migrations and PHP/runtime dependencies in staging. Keep the existing encryption key stable. Configure source-image root to the real deployed React photo directory and storage to persistent protected paths. Do not point this workspace at hosted production data.
4. Run strict readiness and the actual target-host tests. Missing pdo_mysql/xmlwriter failures in this sandbox are not excuses for a complete production PHP host.
5. Finish the feature gates above and the acceptance in17-COMMERCE-WORKFLOW-UPGRADE.md. Verify viewer vs editor/publisher permissions, private draft media, failed/retried image sync, sale UTC transitions, variants, quote/order totals, cache invalidation and rollback on the deployed environment.
6. Only after acceptance enable the specific approved projection/cutover. Media mapping is independent (`NATIVE_CATALOGUE_ORIGIN`, `NATIVE_MEDIA_SYNC=1` on the React API); these variables do not implement missing native product/price/quote projection. Other disabled modules remain disabled.

## Validation evidence for this slice

- Full native run snapshot:2128passed,34skipped,9confirmed sandbox extension failures,11411assertions. Cause summary found2missing-pdo_mysql installer and7missing-xmlwriter QR cases, no unexplained failures. Later archived-source/read-race/token-bound refinements passed the final focused32tests/233assertions. Do not run Pest fake-storage suites concurrently in the same runtime.
- Browser checked actual preserved preview product86: picker opened,2existing website photos selected, details inspected, selection reordered, mobile390px scrollWidth390, cancel closed without adding/saving media, zero page exceptions. No real source product draft/gallery/publication was changed by browser acceptance.
- Both-app typechecks/render smoke and PGlite109/109 passed. Native Vite build, docs consistency and secret scan passed. SQLite was not rerun for this image-picker-only slice; prior phase1 SQLite109/109 evidence is not a fresh run. This document is not evidence of deployed production acceptance.

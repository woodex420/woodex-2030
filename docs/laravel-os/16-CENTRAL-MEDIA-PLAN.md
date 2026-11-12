# Central media management — implementation direction

## Owner requirement

Website → Media is the master library, not a Soon destination. Organize product photography separately from banners, categories, collections and general website assets. Product upload/selection/replacement must use this master library, and explicitly connected published storefront uses must receive image replacements without re-entering URLs. Keep CRM/conversation/customer attachments outside this library.

## Current implementation boundary

The admin Media screen is now independently accessible with `website.edit`, while Pages & builder and other unfinished Website screens remain disabled. Upload folders, folder filtering and metadata organization are available. Changing a folder classifies the asset without breaking its physical path or existing references. Admin thumbnails use a permission-checked, private, non-cached image endpoint independent of the public Website module. Deletion checks both static page usage and native CMS media references.

Product editor currently **copies** selected website media into immutable private product media. That protects saved revisions but is NOT master-library synchronization. The existing React storefront uses a separate catalogue/image projection. No live synchronization or complete master-media implementation is claimed by the current slice.

## Next implementation slices

1. **Stable asset identity and immutable versions.** Add an asset table and version records containing disk/path/hash/MIME/bytes/dimensions. Retain originals and historical versions. Use adapters to index existing website and private product files without moving/deleting them; idempotent imports, no fabricated media. Private catalogue assets must not become public merely because they are indexed.
2. **Usage records.** Record asset/consumer/slot/publication/version explicitly for product primary/gallery, variant images, categories, series, CMS blocks, static site files and storefront catalogue. Show actual uses before replace/delete; cover references embedded in builder JSON/URLs as well as numeric IDs. Do not rely only on a filename stem or raw JSON substring.
3. **Product master integration.** Upload through the master service, select an asset ID in the product editor, and pin saved revisions to immutable asset versions. Existing private ProductMedia IDs remain valid during migration. Shared-asset replacement must clearly distinguish “replace only this product slot” from “replace all connected live uses”. Require catalog management plus website publishing authorization where applicable. Reset affected product draft review without silently rewriting historical revisions.
4. **Optimization.** Decode/validate actual raster content, cap pixels/bytes, strip unneeded metadata, retain source, generate bounded WebP/AVIF and responsive derivatives where supported; report actual original/output sizes. Never relabel an unconverted file or claim an unavailable encoder succeeded. Preserve transparent images, aspect ratios, alt text and crop intent.
5. **Publishing/sync.** Asset-version events plus durable outbox jobs update explicitly authorized live projections, with versioned URLs/cache invalidation, retries and visible pending/synced/failed states. The separate React storefront needs an authenticated projection contract and permanent reachable origin/storage, not a sandbox URL or local filesystem assumption. Avoid turning on the entire Website module as a shortcut. Replacement confirmation must list affected live uses; rollback selects the prior immutable version.
6. **Acceptance.** Verify upload → master → product editor → saved preview → authorized live projection; cross-session refresh; replace-one/all; old revision retention; cache freshness; failed-sync retry; optimized derivatives; RBAC; traversal/unsupported files; deletion protection; no exposure of CRM/private drafts; desktop/mobile layouts.

## Preview diagnosis, 11 November 2026

Exact supplied origin `https://sbx-cyk2y7bzb3jl6lqt.arena.site/admin/commerce/products/86/preview` returned browser `ERR_CONNECTION_CLOSED` from this sandbox. Local authenticated equivalent returned200 and both actual images loaded. The supplied screenshot shows a gallery layout issue, not an application error message. Repaired the grid sizing and replaced the clipped horizontal image strip with selectable image thumbnails, without enabling JavaScript or relaxing preview CSP/authentication. Browser verified selection switches visible figure and no overflow at390px.

The shown office-chair image versus café-chair text is an unreviewed source-data mismatch. Do not invent a replacement image or silently mark it reviewed. The external transport/origin issue remains unconfirmed and cannot be called repaired by a local CSS fix.

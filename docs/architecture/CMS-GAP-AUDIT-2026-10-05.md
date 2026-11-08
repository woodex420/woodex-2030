# CMS and page-management gap audit

**Date:** 2026-10-05

**Scope:** Dashboard Website Studio and Theme Studio, CMS/API persistence and publishing, storefront rendering, public-site routing, media and SEO plumbing.

**Mode:** Read-only audit. No CMS records were changed.

## Executive finding

The current Website Studio is a useful **campaign landing-page builder v1**, not yet a complete website CMS. It can create and publish `/p/:slug` landing pages from three starting options, edit a flat set of typed blocks, bind product blocks to the live catalog, save reusable sections, and configure per-page/site-wide themes. The existing public website—home, product/shop, blog, projects, services, about, contact, and other routes—remains implemented separately in storefront React pages and bundled data; it is not managed by Website Studio.

The most important release-safety gap is that **draft and published content are not separate**. A save to a page that is already `Published` updates the same blocks and metadata read by public visitors. Publishing changes the status and records a partial snapshot, but does not switch an immutable release into production. This should be fixed before relying on the CMS for production content changes.

**Source recheck (2026-10-06):** This gap is present in the current handler flow, not just an earlier audit assumption. `PUT /api/pages/:id/blocks` rewrites `page_blocks`; `GET /api/pages/:key/public` reads those same current blocks for a Published page. `POST /api/pages/:id/publish` records a version snapshot, but the public endpoint does not select the published snapshot. This conflicts with Decision Log D-017’s intended draft/published separation; treat the behavior as a release blocker until code and browser regression tests prove the intended boundary.

## Existing capabilities and live read-only checks

| Area | Present capability | Audit observation |
|---|---|---|
| Landing pages | Create from sale landing, collection intro, or blank; edit title, SEO fields, theme and block order. | `/api/pages` returned **1 page**, `ramadan-workspace-sale`, published with **8 blocks**. The public endpoint returned the page successfully. |
| Block editor | Typed registry, block previews, drag reorder/locks, navigator, media picker and publish validation. | Registry currently has **11 types**: hero, text, product grid/spotlight, materials, gallery, testimonials, FAQ, CTA, global section and lead form. |
| Reusable sections | Copy or reference a block; update a referenced source across pages; protect deletion while referenced. | API returned **1 saved section**. Source references resolve at read time, so source edits fan out immediately. |
| Theme | Per-page starter theme and site-wide Theme Studio; API-backed tokens, announcement bar, font, radius, color and dark mode. | Per-page theme is applied within the landing renderer; site-wide theme is consumed by the storefront theme runtime. |
| Media | Pick an existing image filename from the API image directory. | `/api/media` returned **92 image filenames**. There is no CMS upload/manage endpoint; the API only lists files (capped at 96). |
| SEO/analytics | Page SEO fields, client-side title/meta/OG/canonical, landing tracking, analytics and sitemap/robots endpoints. | Current `sitemap.xml` and `robots.txt` responses use `http://localhost:5174`, because `siteUrl` is unset and that is the fallback. |
| Public website | Existing storefront routes plus `/p/:slug` for CMS pages. | Existing routes and header navigation are code-defined; they are not page records in Website Studio. |

Read-only checks performed: authenticated `GET /api/pages`, `/api/blocks`, `/api/saved-sections`, `/api/media`; public `GET /api/pages/ramadan-workspace-sale/public`; and public reads of `/sitemap.xml` and `/robots.txt`. No create/edit/publish test was run, to avoid mutating the seeded live page. No browser E2E was performed as part of this audit.

## Prioritized gaps

### P0 — Separate draft content from the live release

**Impact:** A routine editor save can change a published landing page before a deliberate publish action. There is no reliable draft/live boundary.

- `PUT /api/pages/:id/blocks` rewrites the page's `page_blocks` rows and updates title, SEO and theme on the same `pages` row. The public endpoint reads those same rows and fields. The publish route validates the current blocks, stores a history row, and sets `status='Published'`; it does not promote a distinct snapshot. See `apps/api/server.mjs:1516-1544` and `1506-1514`.
- The editor auto-saves after a short debounce. For an already-published page, that save is therefore public immediately, even though the UI has separate Save and Publish actions. See `apps/dashboard/src/pages/Website.tsx:150-175`.
- A reusable-section source is also resolved dynamically from `saved_sections`; updating a source changes every referencing page at read time without a page release or page-specific revision. This is an intentional fan-out behavior, but it needs an explicit production approval/version mechanism. See `apps/api/server.mjs:1395-1403` and `1443-1447`.

**Recommended fix:** Store an editable draft and a distinct published snapshot (including block data and page metadata). Public reads must use only the last successful published snapshot; preview reads the draft. Publish should validate and atomically promote a complete snapshot. Decide whether global sections are versioned/released separately or copied into the page release.

### P0 — Make save/publish and history reliable

**Impact:** The UI can report a successful publish when saving the latest edits failed, and rollback does not restore the whole page state.

- `persist()` catches errors and resolves normally. `publish()` awaits it and then calls the publish endpoint regardless, so a failed save does not stop the publish flow. Concurrent autosaves can also finish out of order; a completed request unconditionally clears `dirty`. See `apps/dashboard/src/pages/Website.tsx:150-175`.
- Block replacement deletes all existing rows and then reinserts them without a transaction. A failed insert can leave a partial page. See `apps/api/server.mjs:1516-1530`.
- Published history snapshots include blocks and SEO strings, but omit title, slug, theme and publication metadata. Rollback restores only blocks and updates the timestamp; it does not restore those other fields. See `apps/api/server.mjs:1532-1568`.
- A blank SEO input is sent as `undefined`, and the API uses `COALESCE`, so an existing SEO value cannot be cleared from the editor. Resetting per-page theme to `null` is also omitted from the request and cannot clear the saved theme. See `apps/dashboard/src/pages/Website.tsx:153-160` and `apps/api/server.mjs:1525-1530`.
- Section create/update and page rollback UI handlers do not check `response.ok` before showing success. See `apps/dashboard/src/pages/Website.tsx:178-204`.

**Recommended fix:** Make saves transactional and ordered (or versioned), propagate save errors, block Publish until the latest draft is persisted, support explicit null/clear values, snapshot all page state, and make rollback restore that complete state. Show server errors for section actions and rollback.

### P1 — Bring the existing website under content management

**Impact:** Non-developers can publish a campaign landing page, but cannot manage the main pages visitors already use.

- The storefront router has separate hard-coded routes for `/`, `/about`, `/b2b`, `/contact`, `/services`, `/projects`, `/blog`, `/materials`, `/showrooms`, `/warranty`, `/series`, `/shop`, and other business flows; the CMS route is only `/p/:slug`. See `apps/storefront/src/App.tsx:45-70`.
- Header mega-menu and navigation items are defined in source code, not in an admin-managed menu. See `apps/storefront/src/components/Header.tsx:40-137`. Footer links are also source-controlled.
- Blog content is imported from bundled `blogPosts`; the projects page defines its project array in the page source; service pages use bundled service data. These are not Website Studio content records. See `apps/storefront/src/pages/Blog.tsx`, `apps/storefront/src/pages/Projects.tsx:17-25`, and `apps/storefront/src/pages/Services.tsx:7`.

**Recommended fix:** Add first-class managed page routes and collections for pages, blog posts, services and projects; expose navigation and footer editors; support assigning a published page to a route/menu location while preserving existing storefront flows during migration.

### P1 — Complete page lifecycle and routing controls

**Impact:** Pages can be created and edited, but are difficult to safely maintain as a growing site.

- The list exposes create/view/edit, but no page search/filter, duplicate, delete/archive, slug rename, route assignment or redirect management. The create dialog explicitly says the slug is permanent. See `apps/dashboard/src/pages/Website.tsx:446-479`; page API routes cover create/read/save/publish/unpublish/version/rollback but have no page delete or slug-update endpoint (`apps/api/server.mjs:1458-1568`).
- An unpublish API endpoint exists, but the editor/list has no Unpublish action. There is no scheduling, expiration, approval queue, authoring assignment, revision compare or per-page permission. See `apps/api/server.mjs:1545-1551`.
- Custom pages only resolve under `/p/:slug`; they cannot replace `/about` or another existing route, nor can a slug change preserve old links through a redirect.

**Recommended fix:** Add searchable status filters and safe archive/delete, duplicate, route assignment, controlled slug changes with redirects, visible unpublish action, and scheduled publish/unpublish. Add audit/revision comparison and approval gates if multiple editors will publish.

### P1 — Production SEO needs a real canonical origin and crawlable HTML

**Impact:** Current sitemap URLs are wrong until an operator configures the site URL; browser-rendered metadata is not guaranteed to be visible to crawlers or social unfurlers.

- The live sitemap and robots responses currently advertise `http://localhost:5174`. The fallback is hard-coded in `apps/api/server.mjs:1103-1116`; the Theme Studio's `siteUrl` is optional.
- Sitemap currently includes published `/p/*` pages and up to 500 product URLs, but not the existing static routes, blog posts, projects, services or media/content collections. See `apps/api/server.mjs:1103-1111`.
- The landing renderer sets `document.title`, description, Open Graph, Twitter and canonical tags in a client effect, and sets canonical to `location.origin`. There is no server-rendered/pre-rendered page HTML in this flow. Social crawlers that do not execute the app may miss this metadata; the canonical also ignores the configured `siteUrl`. See `apps/storefront/src/pages/PublishedPage.tsx:234-269`.

**Recommended fix:** Require and validate the production origin before release; generate sitemap entries from all published content types; use that origin for canonical/OG URLs; add SSR or static prerendering for public pages; include redirects and structured-data support in the SEO workflow.

### P1 — Expand the media library beyond a read-only filename picker

**Impact:** Editors cannot upload or manage campaign assets from the CMS and cannot maintain asset metadata.

- `GET /api/media` simply reads image filenames from `apps/api/public/img` and slices the list to 96. There are no upload, delete, rename, folder, search, alt-text, focal-point/crop or responsive-derivative flows. See `apps/api/server.mjs:1413-1416` and the picker in `apps/dashboard/src/pages/Website.tsx:375-384`.
- Block fields store a filename/URL string; there is no consistent required alt-text field or asset record to reuse attribution and accessibility metadata.

**Recommended fix:** Build an authenticated media asset API with upload validation, stable IDs, metadata (alt text, dimensions, MIME, attribution), search/folders and safe reference checks; add image transforms and video support only if needed.

### P1 — Harden the public block renderer

**Impact:** Changing/reordering content can expose runtime instability; some block content is not dynamically connected to managed data.

- The FAQ block calls `useState` conditionally inside a switch in `BlockView`. `PublishedPage` maps blocks with `key={index}`; when a block type changes at an index during reorder/navigation, this can change hook count for a reused `BlockView` instance and trigger a React hook-order error. Move FAQ state into a dedicated component and use stable block keys. See `apps/storefront/src/pages/PublishedPage.tsx:185-206` and `303`.
- Product grid/spotlight bind to the live catalog, but the materials block reads bundled storefront material data. Other existing content types (blog/projects/services) do not use the CMS renderer at all. See `apps/storefront/src/pages/PublishedPage.tsx:1-5`, `ProductGrid`, and the `materials` block.
- The only form block is a fixed lead form (name, contact, need) that creates a CRM lead; there is no form builder, custom field mapping or form-submission management workflow. See `apps/storefront/src/pages/PublishedPage.tsx:23-75` and the `lead-form` registry entry.
- The renderer is a flat set of 11 block types, not a general layout/content system. Important fields remain plain text/line formats; there are no general containers, columns, spacing/responsive controls, rich text, video, navigation/header/footer blocks, or structured link picker. Some seeded props (for example the second hero CTA) are not editable in the current registry.

**Recommended fix:** Fix the hook/component identity issue, add stable block IDs, add renderer tests, and expand the schema only around concrete site requirements (layout, rich text, images with alt text, internal-link picker, and first-class content queries).

### P2 — Improve editor recovery, accessibility and visual preview

- No unsaved-changes guard is apparent for route changes/closing the editor; autosave is the only recovery path for silent saves.
- Reorder is native drag-and-drop plus move buttons, but has no keyboard drag workflow or clear screen-reader position announcements. Block locks are local editor state and are not persisted.
- The iframe is a compact single viewport; there are no desktop/tablet/mobile preview controls or validation of overflow/contrast before publish.
- Editor feedback should distinguish a saved draft from a live release, and show the last successful save/release timestamp and editor identity.

**Recommended fix:** Add dirty-state navigation protection, keyboard-accessible reorder, persistent/clear lock behavior if locks are meant to be shared, responsive preview controls, and a publish checklist for accessibility, links, contrast and required metadata.

### P2 — Clarify global and per-page theme behavior

The site-wide Theme Studio controls storefront tokens, while page themes apply selected inline colors/background/font to `/p/*` content. The public page still uses the shared Header/Footer, and not every block element honors every per-page token. This makes per-page theming partial rather than a complete page-level design system. Document the precedence and either make header/footer theme-aware or explicitly constrain page themes to landing content.

## Recommended implementation sequence

1. **Release safety first:** separate draft/live snapshots; transactional and ordered saves; make failed saves block publish; complete publish/rollback snapshots.
2. **Page operations:** add unpublish UI, search/status filtering, duplicate/archive/delete, slug/redirect handling and route assignment.
3. **Site structure:** make menus/header/footer managed; migrate selected existing routes (start with About, Services, Projects and Blog) while retaining product/checkout flows as application routes.
4. **Content and assets:** add managed blog/project/service schemas and a secure media library with metadata.
5. **SEO and quality:** require production `siteUrl`, generate complete sitemap, add SSR/prerendered metadata, stabilize block keys/hooks, and add publish-time responsive/accessibility checks.

## Source references

- Dashboard builder/list: `apps/dashboard/src/pages/Website.tsx`
- Site-wide design controls: `apps/dashboard/src/pages/ThemeStudio.tsx`
- CMS API, validation, preview links, publishing and versions: `apps/api/server.mjs` (CMS section beginning around line 1348)
- Public CMS routes: `apps/storefront/src/App.tsx`; renderer: `apps/storefront/src/pages/PublishedPage.tsx`
- Preview URL helper: `apps/dashboard/src/lib/preview.ts`
- Hard-coded public navigation/footer: `apps/storefront/src/components/Header.tsx`, `apps/storefront/src/components/Footer.tsx`

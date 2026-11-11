# Category CMS: Quick Edit and full draft editor

2026-11-11. Owner selected Office/Home parent trees, **Series as separate collections**, full category CMS, both featured-product selection modes, and draft → preview → native publish. This is a native administration/content implementation, not live storefront activation or completion of the product editor.

## Editing workflow

`/admin/commerce/categories` keeps Quick Edit for name, slug, parent, description, order and activation. **Full Edit / CMS** opens `/admin/commerce/categories/{category}/edit` for:

- SEO title/description, Open Graph title/description, local canonical target and future indexing preference.
- Banner, split-image/text and minimal hero templates; heading/text, optional CTA label/local path, private hero upload/replacement/removal and alternative text.
- Introduction heading/text and bottom heading/content.
- Add/edit/remove/reorder up to 20 FAQs.
- Hidden/manual/automatic featured-product carousel; editable heading, 1–12 cards, catalogue-order or alphabetical sorting. Manual choices can be reordered.
- Save draft, open an exact saved preview, confirm native publication, unpublish without deleting history, and restore an older revision **as a new draft**.

All prose is plain text rendered with escaping and preserved paragraphs. Arbitrary HTML, CSS, scripts, remote embeds and arbitrary templates are not enabled. Text/FAQ/selection limits are enforced server-side. CTA/canonical values require single-slash local paths, not protocol-relative, script or backslash/control-character URLs. Full Editor is for **category-page content**, not product-detail-page SEO or a Series CMS.

## Draft and publication semantics

`category_content_revisions` stores append-only content snapshots, author and creation time. Category holds current draft/published pointers, publication time and an optimistic content version. Draft save never replaces the published pointer. Historical content is not edited in place. The history UI shows the latest ten snapshots; older snapshots/files remain retained.

Draft-save and publish/unpublish pointer changes are transactional with audit events. Only trusted internal pointer/version assignments use `forceFill`; validated editor input never uses it. Tests exposed Laravel's cached guardable-column list when earlier reference migrations instantiate Category before the new content columns exist; explicit internal assignments prevent silently dropping pointer/version updates.

A publisher must generate a preview for **that exact revision under their own account**. The receipt includes a fingerprint of the content, category identity and resolved carousel product references; category-tree/reference changes invalidate the prior context. Unsaved form edits and pending uploads block publishing. Stale content versions reject rather than overwrite. Quick Edit increments content version on identity/tree/activation changes, invalidating a previously opened full editor. Preview generation is not a claim that the person read every word; publication additionally requires explicit confirmation.

Native publication only stores a reviewed native snapshot. There is **no public category-content endpoint or automatic fetch from the existing React shop**, and no live-site backend flag was enabled. The current storefront keeps its existing source content. Public integration must consume the canonical content renderer rather than create a second guessed CMS preview. Native preview clearly states that it shows content/templates, not the live storefront's overall layout or product integration. Stored indexing settings do not bypass preview noindex.

## Permissions and privacy

- Existing `commerce.catalog.view` / `commerce.catalog.manage` still gate list/editor/actions. Sales remains read-only; sales_manager can draft/edit but cannot publish by default.
- New **`commerce.catalog.publish`** is granted only to owner/admin by additive migration and fresh-install seeder. View + manage + publish are all required for publication/unpublication.
- Existing admin authentication, active-account, database-readiness and 2FA middleware remain. Editing context/version/hero path/revision pointer are Livewire-locked.
- Preview and hero routes require catalogue view plus manage for unpublished revisions. View-only staff may read a native-published snapshot, not its unpublished history.
- Preview is private/no-store and always noindex/nofollow; its CSP blocks scripts/remote content and restricts framing to same-origin. HTML/meta/FAQ text is escaped.

## Images

Uploads accept real JPEG/PNG/WebP only, up to **4 MB**, with width/height at most **6000 × 6000**; no SVG/script uploads. The server generates a random file name on the private local disk. No path or arbitrary image URL is accepted from the form. Images are served only through authorized revision-based routes with strict path validation and `nosniff`, not a public storage URL.

A failed save cleans up its newly stored file. Removing/replacing an image affects the next draft only; older revisions retain their original image. No shared/old file is deleted by the editor. A missing saved asset blocks preview/publication until replaced. Backup and retention/pruning policy for revisions and their files remains an operational production gate; do not delete files still referenced by history.

## Product carousel limits

Because native products/variants/import are not yet accepted, the selector uses a **generated repository reference**, not live inventory. `platform/resources/data/catalogue-reference.json` contains only the existing 148 external keys, names, category/subcategory and series keys plus source hash—**no prices, images, ratings, stock or imported product records**. Generate with `node scripts/build-category-reference.mjs`; `--check` is included in Node CI to detect drift from the committed source snapshot.

Eligibility follows the current native category and descendants, including canonical slug aliases. Unknown/out-of-category/duplicate product keys are rejected. Automatic mode uses source catalogue order or name A–Z—not invented sales/newest rankings. Empty categories show an honest empty carousel. The native preview renders horizontal reference cards (names/keys), not live storefront product cards with unverified price/photo/availability. A “Hot Products” heading is editable marketing copy, not a verified popularity metric. Actual product images, authoritative availability, dynamic product assignment and storefront card integration remain with the product-editor/import slice.

## Evidence

- **16 focused category/CMS/taxonomy tests, 164 assertions**: draft persistence, revision isolation, preview-before-publish, explicit confirmation, unsaved/stale edits, changed category eligibility, per-publisher preview receipts, unpublish/restore, FAQ ordering/removal, text escaping, path safety, bounded/manual/automatic selections, actual private PNG upload/authorized serving/retention, SVG denial and view/manage/publish separation.
- Full local suite: **2,079 passed, 34 skipped, 9 known environment failures**, 11,038 assertions. Cause-based summary exits 0; only the existing two missing-pdo_mysql installer and seven missing-xmlwriter QR cases remain, no unexplained failures. MySQL/Node CI results for this revision remain pending; concurrency/load acceptance is not claimed.
- Changed PHP Pint checks, both-app typecheck/build and render smoke, taxonomy/reference parity, SQLite and PGlite QA **108/108** each, Vercel **76/76** prerenders and Hostinger smoke pass. Existing bundle-size/React Router warnings remain.
- Real Chromium mobile: owner sign-in → full editor → text/FAQ/automatic selection → actual private one-pixel PNG test upload → saved hero/FAQ/carousel preview with forced noindex → confirmed native publish → unpublish. Zero page exceptions. The first browser assertion inspected a collapsed FAQ's visible text; opening its disclosure fixed the test. No application fix was inferred from that harness failure.
- Additive migration and runtime sync preserved preview data/sign-ins. Browser rehearsal left clearly labelled **SYNTHETIC** content in an unpublished native Office draft; no live content or real product photo was changed. No real records/providers/messages/documents were involved.

## Remaining implementation

Native product editor (primary/gallery upload/reorder/replace, priced/available structured variants), Series management/assignment, reviewed catalogue import/mappings and variant parity; storefront category CMS/public renderer integration with deliberate cutover/rollback approval; real-photo verification, production media hosting/backup/retention and provider/domain acceptance. Native quote-request intake remains gated. Do not describe this slice as completion of all Commerce modules or live publication.

## Category usability correction (2026-11-11)

Follow-up screenshots showed Quick Edit being mistaken for the full editor and separated row actions. Sidebar/page now say **Categories**, with a labelled pencil Quick Edit icon immediately beside **Edit**. Creation is explicitly step 1 and redirects after save into the complete content editor (step 2); Quick Edit remains metadata-only. Parent summaries now include future top-level trees, not only two hardcoded slugs. Series remain separate collections; collection management is not falsely represented as a completed category feature.

Introduction/bottom content now have a Markdown formatting toolbar (bold, italic, headings, lists, links), editable source and immediate rendered content preview. New drafts default to Markdown. Older snapshots without `text_format` retain escaped plain rendering; editing/restoring them preserves plain mode until explicitly switched. Markdown rendering strips raw HTML, disallows unsafe links and strips images/unsupported tags. This supersedes the original plain-text-only scope above; hero/FAQ fields remain escaped plain text. Existing private upload/new-upload thumbnails are visible in the editor, and missing images are explicitly labelled.

`category-source-copy.json` is an explicit snapshot of 12 category-specific descriptions from `apps/storefront/src/pages/Shop.tsx`, with source hash. **Load existing storefront introduction** copies matching source text into an empty unsaved introduction only; it neither overwrites operator text nor publishes. Categories without matching copy report that honestly. Source claims still require review; no source product photo is silently assigned as a category hero. There is no verified complete category-image set or bottom-copy set to migrate. Product references/carousel capabilities and live cutover restrictions above remain unchanged.

Validation: **20 focused tests / 193 assertions**; Chromium mobile formatting selection/Bold → saved rendered preview, private image upload, three-card automatic carousel and publish/unpublish passed with zero page exceptions. Focused checks additionally cover unsafe Markdown/remote-image suppression, legacy plain preservation, source-copy no-overwrite/no-auto-save, and create-to-full-editor redirect. Full suite numbers above describe the preceding commit, not a rerun of this UI correction. Products is the next slice, not included in this correction.

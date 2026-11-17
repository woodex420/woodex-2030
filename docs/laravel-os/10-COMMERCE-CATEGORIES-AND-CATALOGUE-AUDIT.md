# Commerce stage 1: categories and source audit

2026-11-11. Owner approved staged delivery, existing repository catalogue and structured variants. This is the first implementation slice, **not a completed Products/Orders/Delivery/Support/Operations migration**.

## Navigation

Both Laravel and React dashboard sidebars now use **Commerce**, with its Commerce Overview link removed (the old URL is not destroyed). Laravel Commerce retains Products, Orders, Delivery & Installation, Support & Warranty and Operations as explicit **Legacy** links, without the 2030 suffix. Native **Catalogue categories** sits beside Products. Legacy links still require the operator-verified dashboard URL and separate dashboard authentication. No address is guessed; unconfigured links stay disabled and explain why. Procurement's previous roadmap and the requested Soon sections are unchanged.

## Native category management

`/admin/commerce/categories`: paginated/searchable category list, create/edit modal, unique normalized slug, plain-text description, optional parent, sort position and active/draft state. Categories default inactive. Parent changes reject self-parenting and descendant cycles against the locked current tree in a transaction. Deactivation preserves identity/history; there is deliberately no destructive delete action. HTML output is escaped. Loading/saving feedback and validation errors use existing admin components. A saved native category is **not publication to the existing storefront**.

New explicit permissions:

- `commerce.catalog.view`: owner, admin, sales_manager, sales.
- `commerce.catalog.manage`: owner, admin, sales_manager.

Fresh-install seeder and additive existing-install migration agree. Route, render and mutation methods enforce authorization, including forged Livewire calls. The editing ID is server-locked. Other roles receive no new catalogue grants. Category changes use the existing audit log with a registered `commerce_category` morph key. No new staff API or shared Node/Laravel session is introduced.

## Existing catalogue facts

Run `node scripts/audit-commerce-catalogue.mjs` from the repository root. It reads only `apps/api/seed-data.json` and referenced `apps/api/public/img` files; it does not import/publish or connect to a database.

- **148 products**, **14 category keys**, **10 nonempty subcategory keys**.
- **18 unique image paths**; all referenced files exist and passed safe-path checks.
- **148 repeated-image galleries**, **16 image paths shared across multiple products**.
- **148 products have colour options**, but that does not establish variant SKU, independent price or inventory parity.

Category keys: acoustic, bedroom, cafe, chairs, collaborative, cubicle-workstations, dining, home-office, living, office-sofas, office-tables, public, storage, workstations. Subcategory-parent reconciliation remains a deliberate import step rather than an inferred native tree. File existence is not proof the photo represents the named product; a real-photo review and replacement upload workflow is required. Existing ratings/review counts are not approved facts for migration.

No source products, categories, reviews or pictures were imported automatically. A clearly labelled synthetic category was created in the preview for browser validation; no product or commercial record was fabricated.

## Validation

- Focused category/navigation/module regressions: **93 passed, 503 assertions**. Create/edit/deactivate, audit persistence, sales read-only/forged-mutation denial, accountant denial, slug/name validation, duplicate slugs, self/descendant cycles, search/empty state and retained Soon roadmap checked.
- Initial audit-log tests revealed the new model needed the enforced morph map; added the key and reran successfully. Two full-suite nav assertions needed the intentional new live destination count and Legacy badge allowance; focused rerun passed without dropping route/permission checks.
- Initial dashboard typecheck caught the now-unused overview icon import; removed it, rerun passed.
- Both-app typecheck/build and render smoke, SQLite and PGlite QA **108/108 each**, Vercel build **76/76** prerenders and Hostinger smoke passed. Changed PHP Pint checks run separately. Existing chunk-size and React Router warnings remain.
- Chromium phone-width preview: actual owner sign-in, New category modal, save and persisted list, no page exceptions. The preview initially served a stale compiled view without the new save footer; clearing compiled views fixed it. Final browser run confirmed Save works. Preview synchronization/migration preserved data/sign-ins.

Final full local rerun: **2,067 passed, 34 skipped, 9 known environment failures**, 10,904 assertions. The cause-based summary exits 0: only the existing two missing-pdo_mysql installer and seven missing-xmlwriter QR cases remain, no unexplained failures. MySQL/Node CI for this revision remains pending. Changed PHP Pint checks and secret scan pass.

## Next slices and limits

Product create/edit/archive, native product/category assignment, primary/gallery durable uploads, reorder/replace/remove, storage failure and safe-file/size validation; structured variant SKU/colour/material/size/price/availability; audited mapping/import dry run and apply; quote-request variant integration are **not implemented in this slice**. Neither are replacements for Orders, Delivery & Installation, Support & Warranty or Operations. Catalogue publication and public basket-v1 remain disabled pending their separate acceptance gates. No real provider messages, credentials or production deployment changes.

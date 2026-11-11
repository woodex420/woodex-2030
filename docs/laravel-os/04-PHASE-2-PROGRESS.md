# Phase 2 — admin upgrade progress

Owner approved starting Phase 2 on 2026-11-11. This is a progress record, not completed acceptance or deployment approval.

## First slice: branding and asset cleanup

- Admin and auth layouts say **WOODEX OS** and **Woodex Furniture**, not Interior.
- Removed the excluded Interior photo preload; auth uses a CSS gradient instead.
- Removed three missing Nohemi font declarations; headings use the shipped Inter font.
- Installed locked npm dependencies and rebuilt the committed Vite assets. Build succeeds; the existing large-chunk warning remains. npm reports Preline's exact Node patch requirement (22.22.2 vs sandbox 22.22.3); install and build succeed.
- Added two regression tests: sign-in branding and built-CSS asset references. Both pass (7 assertions) on local SQLite with the test encryption key supplied. Full suite and MySQL CI have not been rerun for this slice.
- Recorded the superseded agency-os D-01 direction without changing the running Node deployment.

## Remaining Phase 2 work

Target navigation and badges, Furniture settings, roles, shared invoice/payment/document screens, API design and `/api/v1` foundation, storefront leads and quote requests, UTC implementation and MySQL/browser acceptance remain outstanding. No claim of Phase 2 completion.

Do not invent prices, legal/payment/warranty/delivery terms or signatories. Existing commercial defaults still need replacement after owner decisions. No real records were migrated and no production cutover was made.

## Second slice: transitional commerce navigation

Added Commerce & Fulfilment (six screens) and Procurement & Production (nine screens) after Sales, matching the reuse plan. These are **existing React screens, not native Laravel modules**. Entries are marked `2030` and use explicit external links with separate sign-in, no Livewire interception, no token forwarding and no data sync.

Set `LEGACY_DASHBOARD_URL` to the operator-verified HTTPS base URL, including `/dashboard` if required. It is deliberately blank by default: entries stay visible but disabled with “dashboard address not configured”. No guessed production URL is wired in. The existing dashboard must still enforce its own permissions; Laravel navigation discovery requires `dashboard.view`. Guests do not get the transitional groups. This is a bounded partial navigation upgrade, not the complete section 6 target (CMS, credit notes and login-lock integration remain later work).

Validation: **91 targeted tests passed, 478 assertions**, including unsafe URL rejection, absent configuration, actual rendered external links, guest filtering and existing native navigation/module-switch tests. Built assets were regenerated. Direct Chromium acceptance: **18/18**, no JavaScript exceptions; only the expected wrong-password 422 and closed-module 404 remained, with **no missing font/photo requests**. The existing preview database and proxy session were preserved.

Full local suite follow-up: 2,014 passed, 34 skipped, 10 failures. Nine are the known missing PHP extensions; the tenth was a title assertion still expecting `Woodex OS` rather than the approved `WOODEX OS`. Updated that assertion and reran its whole file: **6/6 passed, 42 assertions**. The entire suite has not been rerun after that assertion-only correction; do not represent the first run as all-green. MySQL validation is delegated to CI on the pushed revision.

## Third slice: settings defaults safety and access review

Added a forward-only settings migration (now **94 total migrations**, including 22 settings migrations). It replaces only exact known inherited values: brand becomes Woodex Furniture; legal name, tagline, address, phone, WhatsApp, email, website, execution/design/invoice terms and signatories become blank. Customized values are preserved. This is conservative exact matching, not a fuzzy scrub: customized copies containing Interior wording may remain and require owner review. No old settings migration was rewritten. Lahore, hours, founding year, discount limits, validity periods, payment percentages and other operational defaults remain inherited and unconfirmed.

The settings screen warns when legal name, execution terms or signatories are absent. Existing server-side settings permissions and required-field validation remain enforced; synthetic fixtures now supply the mandatory fields explicitly. **The warning is not a document-issuance lock.** Existing document designs/templates and already-created quotes/invoices are not rewritten; built-in Furniture document terms and milestone defaults still need a separate commercial-policy review. Do not use this preview to issue real business documents.

Applied the migration to the existing synthetic preview without rebuilding the database or changing sign-ins. Customized synthetic identity was preserved. Fresh-install and preservation tests pass; settings permissions still deny sales users.

Access review: retained the existing 8 roles / 45 permissions for this slice. The accountant role still lacks `accounts.view` (open O1); no silent finance-access expansion was made. Future catalogue/order/stock/production permissions must be introduced with their enforcing backend modules, not inferred from the transitional external links. Existing React dashboard access remains separately authorized. Role extension is **not implemented yet**.

Validation: settings tests **20/20, 101 assertions**. Full local run initially exposed five assumptions about inherited defaults; after replacing those with explicit synthetic fixtures, all **42 tests in the four affected files** passed. Reran the entire affected Q–Z group; combined with the unchanged other groups, the local results are **2,019 passed, 34 skipped, 9 known environment failures; 10,618 assertions**, summary exit 0. This is a combined grouped result, not a second full-suite run. CI migration count updated to 94. MySQL CI for the prior navigation commit `4f08aa7` is fully green; this settings revision requires its own CI run.

## Fourth slice: fail-closed document issuance safeguard

New `DocumentReadiness` is enforced by quotation-send validation and the shared invoice freezer. Manual, milestone/advance and recurring invoice issuance all reach that freezer inside their database transaction. If setup is incomplete, issuance fails before a new PDF is rendered and the transaction rolls back; draft editing and draft invoice creation remain available. Already-frozen invoices still verify and return their historical document, even after confirmation is revoked. The invoice screen displays an issuance error rather than failing silently.

Default `DOCUMENTS_OWNER_CONFIRMED=false` blocks issuance in the preview as well as normal installs. Before an operator sets it true, the owner must review all commercial defaults, legal identity and signers. The gate also requires nonblank legal/brand name, address, phone, email, execution/design/invoice terms and a named/titled signer. **No owner confirmation has been supplied or set.** This is an operator attestation, not an approval workflow, signature or audit record; it is not automatically invalidated by later settings edits. Revoke it before changing commercial policy. It checks presence, not legal correctness or every document's custom terms.

Review findings still requiring owner decisions: `CreateQuote` defaults Furniture advances to **75%** and delivery to **15–20 days**, uses `FurnitureDocument::TERMS`, and inherits milestone plans. `FurnitureDocument::text` supplies an outstation transport sentence. Template/design migrations and previously created documents may contain additional terms. These are not newly approved terms. Draft previews/PDF downloads are not blocked, so this is an issuance safeguard, not a complete distribution/DLP control. Historical mail/link/provider distribution is not globally intercepted by this gate.

Tests: phpunit explicitly bypasses readiness for inherited synthetic workflow tests, **only while the application actually runs in the testing environment**. Seven dedicated readiness tests disable it and verify incomplete settings, missing confirmation, production rejection of the bypass, blocked quote sending with no version/PDF/mail, manual invoice rollback and visible Livewire errors, blocked recurring schedule advancement, blocked milestone issuance with draft creation still allowed, and successful issuance followed by historical frozen-document access after revocation. Combined targeted run: **50 passed, 212 assertions**. Full local suite and MySQL CI have not been rerun for this slice. The platform Vite build succeeds with the existing chunk-size warning. A mistakenly invoked root Node build failed because root workspace TypeScript dependencies are not installed (`tsc` missing); it was not treated as platform build evidence.

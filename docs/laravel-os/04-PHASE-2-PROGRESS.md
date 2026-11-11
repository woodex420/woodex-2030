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

## Fifth slice: safeguard validation and accountant read access

The complete safeguard revision (`20f8b36`) is green on MySQL CI: **2,035 passed, 34 skipped, 0 failed**, 10,681 assertions; migrate, seed and rebuild job also green. Full local SQLite regression of that revision: **2,026 passed, 34 skipped, 9 known extension failures**, 10,649 assertions, summary exit 0.

On the owner's continuation approval, corrected O1 by granting the existing accountant role `accounts.view`, **not** `accounts.manage`. This opens the ledger/account/report, payables/expenses and cost screens gated by that permission; it is broader read access to the books, not just one report. Existing invoice management and payment recording/verifying permissions were not changed. Sales/support receive no new ledger permissions. Existing account, journal, period and payment-method mapping mutations still require `accounts.manage` at the server boundary.

Fresh installs receive the grant through the role seeder. A forward-only, idempotent migration adds it on existing installs without syncing/removing any custom permissions. Its rollback intentionally does not revoke a grant that may predate this change; explicit reviewed revocation is required. Total: **95 migrations** (73 ordinary, 22 settings), still 8 default roles / 45 permissions. CI's migration count assertion updated. Applied to the synthetic preview without resetting data or sessions.

Validation: **125 targeted finance/access tests passed, 617 assertions**. New tests cover viewing, seven denied mutation actions, sales/support denial and idempotent upgrades preserving unrelated custom grants. Two inherited tests explicitly pinned the old accountant denial and were updated to the intended read-only contract (the payment posting test still asserts no ledger-management permission). Final full local suite: **2,037 passed, 34 skipped, 9 known extension failures**, 10,670 assertions; cause-based summary exit 0. No unexplained failures. This new permission revision still needs its own MySQL CI result; do not substitute the prior green revision for it.

Next bounded work: versioned API foundation and storefront lead contract. Additional commerce/procurement/production roles remain deferred until their native modules and enforcing permissions exist. Owner commercial decisions remain outstanding; the document issuance safeguard stays closed.

## Sixth slice: visible roadmap and versioned lead foundation

Owner requested the hidden Inbox, Automation, Marketing, Workspace, Content and Pages & builder sections. Closed module destinations now remain visible as permission-filtered, non-clickable **Soon** entries. This deliberately supersedes the earlier hide-from-menu behavior; their routes remain closed. Workspace is a planned preferences/onboarding upgrade, not a duplicate claim that Settings is missing. Content and builder are labelled frontend reimagine. See the [expanded gap register and Puck/API plan](05-GAP-REGISTER-AND-NEXT-UPGRADE.md).

Started `POST /api/v1/leads` with the existing capture/validation/spam/throttle pipeline, Furniture-only business-line handling and explicit GET 405. No authenticated staff API, handoff capability, quote-request contract or live React cutover yet. The old response/payload differs from v1; compatibility work is explicitly listed rather than silently changing the storefront. Puck repository/README reviewed, not installed or selected as proven compatible.

Final local validation of this slice: **2,045 passed, 34 skipped, 9 explained PHP-extension failures**, 10,745 assertions; full runner and summary exit 0. Browser **18/18**, no JavaScript exceptions. Preview synchronized, no data reset. MySQL validation pending on the pushed head.

## Seventh slice: storefront lead adapter rehearsal

Added opt-in `VITE_LEAD_API_MODE=v1`, preserving the default legacy route, plus explicit input/reference mapping, safe contact parsing, company/channel metadata and error handling. No numeric IDs are invented; v1 manual WhatsApp handoff is explicitly unavailable. Product baskets/orders remain on Node. Started isolated synthetic Node and storefront preview servers with a same-origin lead-only proxy to Laravel. See [the validation and remaining cutover gates](06-STOREFRONT-LEAD-ADAPTER.md). Contract checks, storefront typecheck/build, both-app render smoke and 13 Laravel API tests passed; real-browser Contact persistence and phone-width retry behavior verified. No production switch.

## Eighth slice: storefront v1 challenge protection

Added a reusable Turnstile widget to v1 lead-only forms and strict client token handling, plus fail-closed backend verification for v1. Legacy behavior is preserved; no provider keys or production switch. Widget lifecycle, expiry/retry/reset and mocked real-browser flow verified. Full local suite: **2,049 passed, 34 skipped, 9 known environment failures**, 10,756 assertions; no unexplained failures. See [anti-spam evidence and remaining gates](07-STOREFRONT-ANTISPAM.md). Secure WhatsApp handoff and basket quote requests remain subsequent work.

## Ninth slice: secure v1 manual WhatsApp handoff

Added hashed 256-bit, 15-minute bearer capabilities for consenting Furniture WhatsApp enquiries and a module-gated, throttled POST redemption endpoint. Transactional/idempotent redemption records a timeline preparation event, not message delivery. Basket-free Quotation now logs before opening WhatsApp, with blocked-popup fallback and saved-enquiry expiry/errors. Legacy/basket paths unchanged. Focused **23 tests / 142 assertions**; full local **2,056 passed / 34 skipped / 9 known extension failures**, 10,825 assertions. SQLite/PGlite QA 108/108 each, production build 76/76 prerenders and Hostinger smoke passed. See [slice 08 contract, security rules and evidence](08-SECURE-WHATSAPP-HANDOFF.md). No production activation; real provider acceptance and basket persistence remain next gates.

## Tenth slice: opt-in basket request persistence

Added a default-off `quote_requests` module and Furniture-only v1 basket request contract with explicit external-product mappings, authoritative rate-book snapshots, integer totals, consent/challenge checks and transactional idempotent persistence. Staff review snapshots are visible only through the existing lead policy. Separate `VITE_QUOTE_API_MODE` defaults to legacy; unsupported variants/unmapped catalogue entries fail explicitly and builder WhatsApp is not migrated. Synthetic mobile persistence/retry verified; no real 148-product import, issued Quote/order, provider message or production cutover. See [basket scope, contract and remaining gates](09-BASKET-QUOTE-REQUESTS.md).

Basket final validation: **52 focused tests / 400 assertions**; full local suite **2,063 passed / 34 skipped / 9 known extension failures**, 10,874 assertions. SQLite/PGlite QA each 108/108, 76/76 prerenders, Hostinger smoke, adapters, render smoke and changed PHP Pint checks passed. MySQL CI and real-provider acceptance remain pending.

## Eleventh slice: approved staged Commerce, native categories

Renamed Commerce in both sidebars, removed the Commerce Overview link and Commerce 2030 badges while preserving explicitly legacy workflows. Added native category customization with view/manage RBAC, hierarchy validation, search/pagination and audited persistence. Source audit found 148 products / 18 unique image paths and repeated galleries; actual product-photo identity and variant prices need review, not invention. See [scope, evidence and next product-editor slice](10-COMMERCE-CATEGORIES-AND-CATALOGUE-AUDIT.md). Products/uploads/variants and the remaining requested Commerce modules are not claimed complete; storefront switching stays gated.

Commerce categories validation: **93 focused tests / 503 assertions**; full local **2,067 passed / 34 skipped / 9 known extension failures**, 10,904 assertions. Mobile browser category save, audit/RBAC, source audit, both-app typecheck/render, SQLite/PGlite 108/108, production prerender 76/76 and Hostinger smoke passed. CI remains pending.

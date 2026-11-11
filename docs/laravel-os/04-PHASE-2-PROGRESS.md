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

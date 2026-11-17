# Basket quote-request foundation — opt-in, not catalogue cutover

2026-11-11. Follows the [lead handoff](08-SECURE-WHATSAPP-HANDOFF.md). No real catalogue imported, production deployment changed, or issued quotation/order created.

## What is implemented

`POST /api/v1/quote-requests` is a separate **pending-review request**, not the formal `quotes` table. It requires both `lead_api` and the new **default-off** `quote_requests` module. GET returns 405; no public listing/detail endpoint exists. Existing origin allowlist CORS, shared public-lead throttling, honeypot/fill-time checks, strict Turnstile policy and server-only bridge-key behavior apply. Consent is required.

Input reuses validated Furniture lead fields, adding:

- `request_key`: UUID retained by the form for retries; treat as an unguessable replay identifier, not customer identity.
- `items`: 1–50 distinct lines, each `{product_id: string (max 100), qty: integer 1–100}`.
- Unsupported nonempty `material`/`variant_id` values are rejected, not silently selected. Native variant parity is not implemented yet.

An active **Furniture** rate-book entry must have an explicit unique `catalog_items.storefront_product_id` mapping to the submitted external product key. **Node IDs are not Laravel primary keys.** No automatic matching by ID, name or price is permitted. Unknown/inactive/Interior mappings reject the entire request before a lead or request is saved. A missing mapping never falls back to Node or silently creates a PKR 0 line.

Only the authoritative description, unit and rate in Laravel are snapshotted. Browser names, prices, totals and line totals are ignored. Rates must be nonnegative and at most 10,000,000,000 paisa per unit; quantity/line limits keep calculations bounded on the supported 64-bit runtime. Totals use integer paisa, currency PKR. This is a **catalogue estimate only**: no taxes, discounts, freight, payment terms, inventory reservation or approved commercial promise is invented.

`storefront_quote_requests` persists a nonsequential `QR-<UUID>` reference, unique request key, payload hash, lead FK, immutable line snapshots, estimated total/currency, timestamps and `pending_review` status. Capture, request and timeline entry commit together. The existing CaptureLead action merges/assigns Furniture enquiries; request creation does not bypass document readiness, create an issued Quote, send mail/messages or collect payment.

Response: 201 new / 200 exact retry `{ok:true, reference, status:"pending_review", message}` with `Cache-Control: no-store, private`. Spam 202 has null reference and is never displayed as saved; validation/provider errors 422; reused key with different validated details 409; rate limit 429; module off 404. No numeric IDs or handoff capability are exposed.

## Retry and staff review behavior

The key and hash provide idempotent retry for the same validated input (challenge token/fill timestamp and ignored client price/label fields excluded). A previously saved request is returned without repricing or creating another lead/activity even if its product is later deactivated. A unique constraint plus transaction rollback protects racing inserts; MySQL contention/load testing is still a gate. A changed valid payload with the same key returns 409. Verification is required again on retries when configured; the idempotency key is not an anti-spam bypass.

Authorized staff can see the latest 20 basket request snapshots and saved estimates on the lead detail screen, under its **existing lead view policy**. Other sales users cannot read it. This read-only review evidence remains available when public intake is switched off. There is no new staff mutation endpoint, implicit approval, or automatic request-to-Quote conversion. Older references remain in the timeline; paging/export/conversion workflows are future work.

## Storefront opt-in

`VITE_QUOTE_API_MODE=legacy` remains the default, independently of `VITE_LEAD_API_MODE`. Only an explicitly configured `v1` bundle sends the Quotation basket form to the new endpoint. The specific local `LARAVEL_LEAD_API_PROXY_TARGET` proxy covers both v1 lead and quote-request paths; browser calls stay same-origin.

The form keeps a UUID per mount for retries, renders the challenge according to the selected quote mode, resets verification after attempts, preserves inputs/basket on failure and displays the saved reference and pending-review wording on success. After a 409, reload to start a new request with edited details; local basket remains stored. An unknown response or absent reference is not success. Browser free-text colour/material choices are refused with team-review guidance rather than discarded.

V1 basket WhatsApp is **not implemented**. The legacy builder WhatsApp action is explicitly blocked in quote-v1 mode and directs the visitor to the request form. A WhatsApp preference can be recorded in the request, but no numeric Node handoff is called using a Laravel reference. Legacy-mode form/builder routing remains on Node. Orders, catalogue hydration and the basket's displayed local price remain unchanged.

## Catalogue mapping and activation gate

The new nullable unique `storefront_product_id` is an explicit integration field on the existing rate book, **not a completed native product/variant module**. No mappings are guessed or seeded into production by the migration. Operator tooling and a reviewed 148-product catalogue/import/variant reconciliation remain outstanding. Until that exists, do not enable quote-v1 for the public storefront: ordinary baskets will correctly reject unmapped products or unsupported variants.

Local rehearsal used only one clearly labelled synthetic mapped item (`SYNTHETIC-BASKET-PRODUCT`, rate 123,456 paisa) in the synthetic SQLite preview. The normal preview retains default-off basket intake and legacy quote mode. An additive migration was applied without resetting data/sign-ins; temporary API/browser servers enabled basket intake only for the rehearsal and were stopped afterward.

Rollback: deploy the legacy quote-mode bundle and disable `quote_requests`; retain persisted request snapshots for staff follow-up. Do not routinely run the down migration or delete pending requests. No hosted database connection was used.

## Evidence

- Focused regression: **52 passed, 400 assertions** across basket, lead/handoff, lead detail and module tests. Basket cases cover tampered prices/names, immutable estimates, Furniture scope, unknown/inactive products, consent/quantity bounds, duplicate lines, unsupported variants, idempotency/conflict, spam/provider errors, gates and staff-policy denial.
- Initial full-suite run exposed a module-coverage test assumption: it counted only a route's first gate. Updated it to count **every disabled module dependency**, without weakening its 404 checks. Targeted rerun passed. Final full rerun: **2,063 passed, 34 skipped, 9 known environment failures**, 10,874 assertions. Cause-based summary exits 0: only the existing two missing-pdo_mysql installer and seven missing-xmlwriter QR failures remain; no unexplained failures.
- `test-basket-adapter.mjs` (added to Node CI), lead adapter and challenge lifecycle checks pass. Both-app typecheck/build and render smoke pass. SQLite and PGlite QA each **108/108**; Vercel build **76/76** prerenders; Hostinger all-in-one smoke passes. Existing chunk-size/React Router warnings remain.
- Real Chromium mobile: mocked 422 retained form values and re-enabled retry; retry reached Laravel through the same-origin proxy and persisted a pending-review request with zero page exceptions. SQLite inspection confirmed **246,912 paisa** (2 × authoritative 123,456), not the tampered browser total of PKR 2; authoritative product description saved. No provider or WhatsApp message was sent. The final saved-reference/status copy was added after this browser run and subsequently typechecked/built/render-smoked.

## Remaining gates

Reviewed catalogue mapping management/import and variant parity; request browsing/paging/export and intentional staff conversion workflow; basket-specific capability handoff; MySQL/concurrency acceptance; actual Turnstile/domain acceptance; production same-origin routing and explicit cutover/rollback approval. This slice does **not** mean the storefront, catalogue or quote engine has migrated. Existing document safeguards stay closed.

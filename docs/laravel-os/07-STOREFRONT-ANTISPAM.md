# Storefront v1 anti-spam — implemented, provider rehearsal pending

**Follow-up:** [Secure v1 manual WhatsApp handoff implemented in slice 08](08-SECURE-WHATSAPP-HANDOFF.md); the earlier handoff gap below is historical. Basket quotes and production/provider gates remain outstanding.

2026-11-11. Follows the [lead adapter rehearsal](06-STOREFRONT-LEAD-ADAPTER.md). This is not production activation.

## UI and request behavior

`LeadChallenge` renders a Turnstile widget only in `VITE_LEAD_API_MODE=v1` with a configured public `VITE_TURNSTILE_SITE_KEY`. Contact, basket-free Quotation and PublishedPage lead forms submit its hidden token. Basket quotation requests remain unchanged on Node and are not claimed to be protected by this widget.

Success populates the token; expiry/error clears it and shows retry guidance. Script load failure/15-second timeout supports retry. A submission attempt resets the challenge (tokens are single-use); unmount removes the widget/listener. Stale widget callbacks cannot update an unmounted/replaced instance. The loader is shared across forms. Legacy mode does not load the provider script.

The adapter requires a nonblank token in production v1 bundles, and in development when a site key is configured. A missing token prevents the network request. With no site key, production displays a not-configured message; local rehearsal explicitly identifies its unconfigured provider. No verification secret is included in a VITE variable or browser bundle.

## Backend enforcement

V1 capture requests `failClosed` verification. A configured provider rejects missing/invalid tokens; provider exceptions return failure (422 with no lead saved). A missing secret is accepted only in explicitly local/testing environments; production and other environments fail verification. Existing trusted server-to-server bridge-key behavior remains and the key must never be shipped to the browser. Existing honeypot and rate limits still apply.

Legacy public capture/chat callers retain the source application's previous optional/fail-open policy; this change is intentionally not represented as hardening every legacy endpoint. The old route remains operational during migration. Production must use `APP_ENV=production`, correct provider hostname restrictions, matching public/secret keys, reviewed origin/CSP settings and HTTPS routing. No credentials or provider dashboard settings have been configured in this session.

## Evidence

- Adapter contract checks: token-required requests cannot reach fetch without a token; supplied token is forwarded. Existing legacy/response/error cases still pass.
- `node scripts/test-lead-challenge.mjs`: React widget lifecycle with a mocked provider — success, expiry, attempt reset, retry and cleanup pass. Wired into Node CI. Initial test harness used classic JSX and failed; corrected the harness to automatic JSX (the app already uses it); rerun passed.
- `npm run typecheck:storefront`, storefront build and both-app render smoke pass. Existing React Router future-flag warnings remain.
- **16 Laravel v1/legacy API tests, 73 assertions**, including missing production secret, missing token, provider exception, provider success and unchanged legacy behavior.
- Full local backend suite: **2,049 passed, 34 skipped, 9 known extension failures**, 10,756 assertions. Cause-based summary exits 0 with no unexplained failures.
- Real Chromium against a temporary Vite server with a **mock browser provider**: missing/expired tokens caused zero network submissions; a supplied synthetic token was forwarded to the local Laravel proxy, with success UI and no page JavaScript exceptions. This did not contact Cloudflare or prove real provider acceptance. Temporary test server stopped afterward.

The user-facing storefront on port 5174 remains a local synthetic rehearsal without a configured site key. It therefore shows the local-rehearsal notice rather than pretending that a live challenge is active. Backend runtime was synchronized without resetting data/sign-ins. MySQL/Node CI results for the new revision remain pending; earlier queued runs are not evidence for it.

## Remaining cutover gates

Real provider-domain acceptance, all-form/mobile/challenge-failure browser acceptance with actual configuration, secure expiring/capability-based WhatsApp handoff, product-basket quote-request persistence and authoritative catalogue validation, production same-origin routing, and explicit cutover/rollback approval remain outstanding. No messages, payments or real records were sent or migrated. The document issuance safeguard is unchanged and remains closed.

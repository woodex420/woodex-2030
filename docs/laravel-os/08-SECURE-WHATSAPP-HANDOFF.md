# Secure v1 manual WhatsApp handoff

2026-11-11. Synthetic rehearsal only; no production cutover or messages sent. This supersedes the handoff-unavailable limitation in [slice 06](06-STOREFRONT-LEAD-ADAPTER.md), not the basket-quote or legacy backend paths.

## Contract

A successful `POST /api/v1/leads` now includes `handoff: {token, expires_at}` only when **this request** explicitly grants consent and selects `preferred_channel=whatsapp`, the lead is Furniture, and the configured business WhatsApp number normalizes successfully. Otherwise `handoff` is null. Spam/failed captures never issue a token. Capture and issuance share a transaction. Repeated captures can merge an existing lead but issue separate capabilities for the new consenting request.

V1 keeps `whatsapp_url` in the capture shape but sets it to **null**: the browser must log preparation before obtaining the link. This is an intentional change to the opt-in v1 draft contract; the legacy endpoint retains its original direct URL/response. Do not activate v1 for unknown consumers without reviewing this difference.

`POST /api/v1/leads/whatsapp-handoff` accepts **only a bearer capability** in JSON `{token}`. No public numeric ID, token-in-query, redirect parameter, staff authentication, message send, inbox integration or delivery claim is introduced. The server chooses the destination from Business Settings and text from the bound lead reference; caller-supplied IDs/URLs are ignored.

Success: `{logged:true, delivery:"manual_handoff_not_sent", whatsapp_url:"https://wa.me/..."}`. Unknown/expired/deleted/non-Furniture targets return 404; malformed tokens 422; missing current destination 503; module disabled 404; throttled 429; GET 405. The exact CORS path uses the configured origin allowlist without browser credentials. The existing specific Vite lead proxy also covers the subpath.

## Capability and replay rules

- 256-bit cryptographically random token, 15-minute lifetime. Only SHA-256 hash, internal lead FK, creation/expiry/redemption timestamps are stored in `lead_handoff_capabilities`.
- The token is an intentionally narrow bearer authorization to prepare this enquiry's manual handoff. It is **not identity verification**, public lead access, or authorization to update a lead. It cannot change a lead/destination or read customer information.
- Redemption locks the row and atomically claims it with a null-redemption conditional update; the same transaction records a System timeline activity with explicit `manual_handoff_not_sent` data. Failure rolls both back. Retries before expiry return the server-generated URL without another activity. Expiry still rejects a previously redeemed token.
- Capture/redemption successes are `Cache-Control: no-store, private`. Tokens remain in React state, never local storage, URL, WhatsApp text, timeline or telemetry. Production access/application logging must not record request/response bodies containing capabilities.
- Hard-deleting a lead cascades capability removal; soft-deleted leads cannot redeem. Expired rows are inert but currently retained. Establish a retention/pruning policy before production; do not remove the separate CRM timeline evidence when pruning expired capability rows.

## Storefront behavior

The basket-free Quotation form exposes **Log & open WhatsApp** after a consenting WhatsApp capture. On click it opens a blank window synchronously, clears its opener, then redeems the capability. It navigates only on a confirmed manual-handoff reply with a `https://wa.me/<digits>?text=` URL. Blocked pop-ups get a manual link only after successful logging. Expiry/errors retain the saved-enquiry state and provide retry/follow-up guidance, with no Node fallback or duplicate capture. The UI states that the visitor must review/send the message themselves and that WOODEX did not send it.

Contact and PublishedPage remain lead capture forms without an added WhatsApp action. Basket-based form/builder handoffs continue using their existing Node contract and are **not** represented as secured by this change. The quote-builder/contact migration remains a separate acceptance gate.

## Evidence and limitations

- Focused Laravel tests: **23 passed, 142 assertions** (handoff + versioned + legacy lead API). Covers hashing, issuance consent/channel, spam/legacy withholding, binding, ignored malicious destinations/IDs, retry idempotence, expiry, deletion, missing destination, module switch, GET and throttling.
- Full local suite: **2,056 passed, 34 skipped, 9 known extension failures**, 10,825 assertions. Cause-based summary identifies the same two missing-pdo_mysql installer and seven missing-xmlwriter QR cases; no unexplained failures. MySQL concurrency behavior is designed around transaction/atomic-update semantics but not load-tested locally; CI remains authoritative.
- Adapter/token lifecycle tests, changed PHP Pint checks, both-app typecheck/build and render smoke passed. SQLite QA **108/108**, PGlite QA **108/108**, Vercel build **76/76 routes rendered**, all-in-one Hostinger smoke passed. Existing bundle-size/React Router warnings remain.
- Real Chromium mobile rehearsal: same-origin capture → Laravel capability → redemption → logged manual link with blocked pop-up, zero page JavaScript exceptions. Mocked 404 expiry response retained the saved enquiry and exposed no unlogged link. A reserved fictional destination `+12025550123` was temporarily set in the synthetic preview and restored afterward; `window.open` was blocked so no request went to WhatsApp. No real provider verification or message delivery was tested.
- Synthetic runtime synchronized and additive migration applied without resetting data or sign-ins. Temporary browser server stopped; normal preview remains local-only with provider unconfigured. The configured destination is restored, so an unavailable handoff is honest behavior if no valid business WhatsApp is set.

## Remaining production gates

Actual Turnstile-domain testing, reviewed destination/origins/CSP/body-log policy and retention, MySQL CI/concurrency acceptance, basket quote persistence/catalogue authority, production same-origin routing and deliberate cutover/rollback approval remain outstanding. No connected deployment service was changed, real records imported, or production database accessed. Document issuance safeguards remain closed.

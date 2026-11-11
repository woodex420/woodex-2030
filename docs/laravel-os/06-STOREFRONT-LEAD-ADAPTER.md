# Storefront → Laravel lead adapter: rehearsal, not cutover

**Follow-up:** [Secure v1 manual WhatsApp handoff implemented in slice 08](08-SECURE-WHATSAPP-HANDOFF.md); the earlier handoff gap below is historical. Basket quotes and production/provider gates remain outstanding.

2026-11-11. Implements the first compatibility slice of the [API/gap plan](05-GAP-REGISTER-AND-NEXT-UPGRADE.md). The visual storefront and public deployment are not redesigned or switched by this work.

## What changed

- `leadCapture.ts` explicitly maps existing Contact, basket-free Quotation and PublishedPage inputs to `/api/v1/leads` when `VITE_LEAD_API_MODE=v1`. Missing/default mode remains `legacy` and uses `/api/leads` with the unchanged input object. Unknown configured modes fail instead of silently changing destinations.
- Free-text source becomes `website_form` plus `form`; interest/note become service/message. Overlength interest is retained in the message rather than silently dropped. Combined contact text is accepted only as a single recognizable phone or email; ambiguous descriptions are refused.
- Existing consent is mapped strictly from boolean `contactConsent`; no consent is invented for published-page forms. Company text and preferred channel are persisted as metadata by the v1 request, not guessed into a company relationship. UTM fields/referrer/form are bounded to the receiving contract's lengths.
- V1 response `reference` becomes the form-facing `ref`. No Node numeric lead ID is invented. The current numeric-ID WhatsApp logging endpoint is never called for this result. Basket-free quotation submissions requesting WhatsApp get an explicit handoff-unavailable notice, not a claim that a message was sent.
- 202 responses without a reference are not presented as confirmed persistence. Invalid responses, network failures, 422 and 429 remain retryable errors. PublishedPage now catches these failures and prevents duplicate submission while a request is pending.
- Product-basket quotation requests, catalogue hydration, orders and existing quote-based handoffs remain on their existing Node paths. This is intentionally a partial backend rehearsal, not a unified-data-store migration.

## Run the rehearsal

Use a disposable/synthetic Node API for the current catalogue, pages and basket workflows, plus the synthetic Laravel preview. No real customers, prices or production database should be connected.

```
VITE_LEAD_API_MODE=v1 \
LARAVEL_LEAD_API_PROXY_TARGET=http://127.0.0.1:8080 \
API_PROXY_TARGET=http://127.0.0.1:3002 \
ARENA_PREVIEW=1 npm -w @woodex/storefront run dev
```

The browser calls relative same-origin URLs. Vite routes only `/api/v1/leads` to Laravel; remaining `/api` requests go to the existing Node rehearsal API. Neither backend address is compiled into browser fetch calls. Changing a `VITE_` variable requires restarting development/rebuilding deployment assets.

`apps/storefront/.env.example` leaves legacy mode as the default. **Do not set v1 in production yet:** current Vercel routing still owns `/api`, and no production Laravel reverse proxy has been deployed.

## Validation actually run

- `node scripts/test-storefront-lead-adapter.mjs`: explicit mappings, contact parsing, strict consent, legacy routing/body preservation, v1 normalization, no invented ID, unconfirmed 202, malformed replies, 422, 429, network failure and bad mode. Added as a Node CI step.
- `npm run typecheck:storefront`: passed.
- `npm -w @woodex/storefront run build`: passed.
- `npm run smoke:render`: storefront and dashboard both mounted with no render errors (existing React Router future-flag warnings).
- Laravel v1 + inherited public lead tests: **13 passed, 66 assertions**, including company/channel persistence without an invented company link.
- Real Chromium Contact form: submitted synthetic data through the Vite proxy, received a Laravel reference and showed success. SQLite join verified `business_line=furniture` and synthetic company/channel metadata. Repeated submissions merged into the same lead.
- Real Chromium phone-width failure: an intercepted 429 displayed the retry message, preserved entered text and re-enabled submission. This was a simulated response, not proof of a real browser rate-limit boundary (the backend limiter has separate tests).
- Initial direct-browser pass against the Arena-HMR configuration raised two dev websocket errors (direct localhost cannot connect via the configured TLS preview port). Repeated the checks on an otherwise identical direct-local Vite instance without the Arena HMR override: **zero page JavaScript exceptions**. Temporary validation server was then stopped; the user-facing preview remains running.

The browser exercised Contact, not every PublishedPage/Quotation variant. Full Laravel suite and full Node API/browser CI were not rerun locally for this slice; pushed CI results remain to be checked. Local Node dependencies were installed from the root lockfile; no package versions or React majors were changed.

## Still required before live switch

1. Add real Turnstile challenge/token handling to the storefront forms, including expired/failed challenges; production provider verification must not be bypassed by shipping a bridge key to the browser.
2. Define and implement a capability-based WhatsApp handoff endpoint with expiry/replay/ownership tests, then wire the v1 response into the form. Do not use a public sequential database ID.
3. Implement the product-basket quote-request contract with authoritative catalogue lookup. Do not trust client prices or pretend an enquiry is an issued quote.
4. End-to-end acceptance for Contact, empty-basket Quotation and PublishedPage (consent, attribution, spam/error/success/loading, desktop/mobile), production routing/CORS and rollback.
5. Decide migration/source-of-truth and record lookup boundaries. This rehearsal deliberately writes leads to Laravel but keeps catalogue/order/basket activity in isolated Node demo storage.

The document issuance safeguard remains closed. Nothing here approves commercial terms, sends WhatsApp messages, migrates real records or cuts over production.

# Woodex 2030 — Master QA, Security & Release Audit

**Audit date:** 2026-11-06
**Disposition:** **NO-GO for production use with real customer data or transactions**
**Audit subject:** the local workspace source, not a live deployment.

> **Update:** Sections 1–9 preserve the original pre-hardening audit snapshot for traceability. Section 10 records follow-up implementation and the latest verification. Findings in sections 1–6 describe the source and test state at the time of that original snapshot; consult section 10 for current local code. This is still not a production penetration test or go-live approval. No hosted PostgreSQL database, payment provider, or live messaging account has been exercised. Nothing here establishes that a live deployment is exposed.

## 1. Executive assessment

The reported QA suite is useful and materially stronger than its older counts: the current local suite passed **91/91 against SQLite** and **91/91 against PGlite**, including authentication, writes, rollback, CMS snapshots, throttling, and cleanup. Several earlier claims are now stale in the current workspace: checkout/contact/quotation no longer show success after a failed request, and the CMS public route uses its published snapshot rather than editable draft blocks.

Those green API results do **not** clear the main release risks. The current source still has unauthenticated order lookup and event-stream routes, server-trusted checkout prices, default demo accounts, and broad authenticated GET access. The storefront production build passes, but its standalone TypeScript check fails with a large dependency/type-resolution cascade. The browser E2E scripts did not complete their flows, and the local CI workflow is not tracked or run.

### Release blockers

1. **Unauthenticated data access:** a known order reference returns customer/order/invoice/return details; an anonymous SSE subscriber receives customer names and payment amount/method metadata.
2. **Unsafe checkout contract:** the API accepts a client-supplied total and unverified product/price data. A synthetic local request with a nonexistent product ID and item price `1` but total `100` was accepted; its auto-invoice total became `105` with zero tax and shipping recorded.
3. **Known seed credentials:** an empty database is bootstrapped with fixed owner/team demo credentials and demo data. No production-mode gate was found around those seeds.
4. **Role reads are not capability-scoped:** the `viewer` role can read invoices and client records, including contact fields, despite having no capabilities; writes are denied.
5. **The branch commit does not contain the tested app source:** the current `HEAD` tree contains only `README.md`; the application, tests, manifests, workflow, and docs are untracked local files. A CI/deployment receiving only the branch commit cannot reproduce this audit.
6. **Release evidence is incomplete:** there is no Woodex 2030 GitHub Actions run for the current branch or reference commit; the attempted local browser suites fail before the important user journeys; no hosted-PostgreSQL or real-payment test exists.

## 2. Scope, provenance, and confidence

### What was audited

- Current local source in `apps/api`, `apps/dashboard`, `apps/storefront`, `scripts`, `ci`, `.github`, and `docs`.
- Local SQLite and PGlite QA results and local HTTP checks against `127.0.0.1:3002`.
- The exact referenced GitHub commit [`6cd9fc2`](https://github.com/woodex420/woodex-2030/commit/6cd9fc238c8846e6dd1a40fde10f8c4efc5b07d9) for the relevant API, form, QA, and CI-copy source files.
- The supplied historical report at [`docs/QA-REPORT-2026-10-05.md`](QA-REPORT-2026-10-05.md), the existing [`docs/POSTGRES-CUTOVER.md`](POSTGRES-CUTOVER.md), and the local workflow copies.

### Source identity warning

- Active branch: `arena/01a10a97-woodex-2030`; local `HEAD`: `e7b7d154b39674c61083fca814daa6dcd1aab0f9` (2026-10-04).
- GitHub `main` still resolved to that same `e7b7…` commit when checked. GitHub’s compare endpoint reported `e7b7…` as **31 commits behind** `6cd9fc…`; the referenced commit is not the current `HEAD`.
- `git ls-tree HEAD` contains only `README.md`. `README.md` is modified, while `.github/`, `apps/`, `ci/`, `docs/`, `package.json`, `package-lock.json`, and `scripts/` are untracked in this workspace.
- Targeted comparison confirms that the order-pricing, public-lookup, public-SSE, default-account, and GET-RBAC issues described below exist both at `6cd9fc…` and in the current local source. The form false-success behavior existed at `6cd9fc…` but has since been corrected in the local workspace. The local `.github/workflows/ci.yml` does not exist at `6cd9fc…`.

**Implication:** findings are separately labeled as historical-commit, current-source, or local-runtime evidence. They are not assertions that the local worktree is committed, deployed, or serving public traffic.

## 3. Verification snapshot

| Check | Current result | What it demonstrates / does not demonstrate |
|---|---|---|
| `QA_BASE=http://127.0.0.1:3002 npm run qa` | **91/91 pass** | Current SQLite API regression battery; QA rows were swept and no orphans remained. Not a security sign-off: the suite misses the order-lookup and invoice/payment SSE cases below. |
| `npm run qa:pg` | **91/91 pass** | Migration/digest checks for 25 tables, 8 migration query probes, and the API battery on embedded PGlite. This is a PostgreSQL-semantics rehearsal, **not a hosted PostgreSQL canary**. |
| `npm run build` | **Pass** | Dashboard TypeScript build + Vite and Storefront Vite production build pass. Dashboard emits a 676.60 kB JS chunk warning; Storefront’s main JS chunk is 450.00 kB. Vite transpilation is not a Storefront typecheck. |
| `npm -w @woodex/storefront run lint` | **Pass, 0 errors / 13 warnings** | Mostly Fast Refresh and hook-dependency warnings. The earlier report’s 27 lint errors are stale for this worktree. |
| `tsc --noEmit --project apps/storefront/tsconfig.app.json` | **Fail: 1,039 diagnostics** | Dominated by React JSX/type incompatibilities; the installed tree resolves React/@types React 19 while Storefront declares React 18, and three imports cannot resolve `@lovable.dev/mcp-js`. These are likely cascades, not 1,039 independent defects. |
| Imported `apps/woodex-reimagined-preview` build / TypeScript / lint | **Build and TypeScript pass; lint 0 errors / 10 warnings** | Separate app, not proof of integrated Woodex 2030 behavior. Its current package audit reports 31 vulnerabilities (10 moderate, 21 high). |
| `npm audit` — root workspace | **16 reported vulnerabilities** (13 moderate, 3 high) | No audit fixes were applied. Review advisories and update deliberately; this audit did not classify each advisory as exploitable in a shipped path. |
| `npm run secret-scan` | Prints clean, but scans **1 tracked file** | Not meaningful coverage for this worktree: the scanner uses `git ls-files` and prefers `git show HEAD:<file>`, while almost all application source is untracked. |
| `npm run e2e` | **Fail before acceptance** | With Chromium and the local HTTP Dashboard, the script fails on Vite HMR’s `ws://127.0.0.1:443` connection refusal; the script treats console errors as fatal. This is an HTTP test-harness/HMR mismatch, not evidence that an HTTPS Arena preview is broken. |
| `e2e:storefront-crm`, `e2e:quotation-template`, `e2e:page-builder` | **All fail at the initial Dashboard locator** | Each uses `getByText("Overview")`; strict mode sees multiple “Overview”/“Commerce Overview” matches. The intended journeys do not run. Their cleanup paths ran; they are not E2E passes. |
| Woodex 2030 GitHub Actions | **No runs/check-runs found** | `gh run list` returned no runs for the current branch or `main`; the referenced commit had zero check-runs. The local workflow is not evidence of CI execution. |

**Historical count drift:** the report stored at exact commit `6cd9fc…` says 77/77; the current local report copy says 78 for its original snapshot and adds a later 88/88 update; `POSTGRES-CUTOVER.md` still says 77/77. The current suite run is 91/91. Update these documents from one canonical generated/result source after release-gate decisions.

## 4. Findings

### F-01 — Public order lookup exposes customer and transaction details

**Priority: P0 before public production use · Confirmed in source and local runtime**

`GET /api/orders/lookup` is explicitly allowlisted as public (`apps/api/server.mjs:583-593`) and accepts only an order reference (`:1027-1038`). The response includes customer name, status/stage, total, due date, placed date, item lines, invoice details, and return records. It does not return the customer’s email/phone in this route, but the commercial and return details are still sensitive. References are sequential `WX-####` values generated from the maximum numeric suffix (`:1806-1818`), and the public lookup has no endpoint-specific rate limit.

**Local repro:** anonymous `GET /api/orders/lookup?ref=WX-4210` returned HTTP 200 with customer, one item, and an invoice. The existing QA checks only an unknown reference (404); it does not inspect a known public order’s response or test reference guessing.

**Remediation:** use a high-entropy, unguessable customer access token separate from the display reference; store it hashed; rate-limit; return only fields needed for tracking; test that contact, finance, invoice notes, and return-resolution data are not exposed. Do not rely on sequential references as authorization.

### F-02 — Public SSE broadcasts private customer and payment events

**Priority: P0 before public production use · Confirmed in source and local runtime**

`GET /api/events` is public (`server.mjs:583-593`, `:1562-1568`). `emit()` serializes each event payload and writes it to every connected subscriber without a privacy filter (`:1352-1357`). Invoice events set `who` to the customer, while payment events include amount, invoice ID, and method (`:1907-1908`; other invoice emits also use the customer name).

**Local repro:** an unauthenticated subscriber received synthetic invoice/payment frames. The invoice frame contained the synthetic customer name; the payment frame contained the synthetic amount, method, and invoice ID. The synthetic invoice/payment rows were removed after the test.

The QA “PII-free” test creates a **lead** and checks only that its QA fingerprint is absent (`scripts/qa-full.mjs:581-601`). It never exercises invoice/payment events. The claim in the historical report that TODO-7 had scrubbed public SSE payloads is therefore **not supported by the source at `6cd9fc…` or by the current source**.

**Remediation:** make the stream authenticated and role-scoped, or keep it public only with an explicit allowlist of non-sensitive, aggregate event shapes. Add anonymous tests for invoice issuance, payment, login/team, and order events.

### F-03 — Order endpoint trusts browser-supplied prices and total

**Priority: P0 before real checkout · Confirmed in source and local runtime**

`POST /api/orders` accepts `customer`, `items`, and `total`; if `total` is a number, it is used directly (`server.mjs:1006-1014`). The API does not load each product from the catalog, compare the submitted price, validate quantity against stock, or recompute the amount from trusted values. The Storefront sends its cart prices and `grandTotal` (`apps/storefront/src/pages/Checkout.tsx:50-56`).

**Local repro (synthetic):** the API returned 201 for an item with a nonexistent catalog ID and line price 1 / quantity 1 while storing the submitted order total 100. The resulting invoice had subtotal 100, total 105, tax 0, shipping 0. All probe rows were removed.

The current checkout UI openly says server-side price validation is pending (`Checkout.tsx:139-140`), which is appropriate for a preview but is not a server-side safeguard.

**Remediation:** send product IDs, quantities, and validated options only; price from the server catalog; enforce quantity/availability and shipping policy; compute all totals server-side and return a canonical quote/order calculation. Add tampering tests, including negative/zero/huge quantities, discontinued products, and stale prices.

### F-04 — Checkout does not persist delivery details or payment preference; invoice adds an unexplained 5%

**Priority: P1 before accepting real orders · Confirmed in source**

The form includes name, email, phone, address, city, notes, and a local `paymentMethod` selection. The submitted payload puts address/city/notes into a `note` property and does not send `paymentMethod` (`Checkout.tsx:24-30`, `:50-56`). The API order handler ignores `note`; the `orders` table has no dedicated delivery-address or payment-preference fields (`server.mjs:72-76`, `:1006-1024`). Email/phone can be extracted from the combined contact string into the linked client, but delivery details and the selected payment preference are not persisted on the order. The page itself states that the preference is not saved and no payment is collected (`Checkout.tsx:217-219`).

The API automatically issues an invoice at `Math.round(orderTotal * 1.05)` (`server.mjs:1017-1019`), without recording that 5% as tax or shipping. The current QA asserts auto-invoice creation, not that the 5% is approved, disclosed, or reconciled.

**Remediation:** keep preview-only language until the order schema and fulfillment contract are complete. Persist address/city/notes and payment preference in dedicated validated fields, define a commercial/tax/shipping policy with approval, show each line to the customer, and test API/UI totals match exactly. Add idempotency keys so retries after timeouts cannot create duplicate orders.

### F-05 — Fresh databases receive known demo accounts and synthetic business data

**Priority: P0 if the API is exposed with default seeds · Source-confirmed; live state unverified**

Startup creates/migrates an owner account with a fixed demo password and ensures a team of editor/sales/finance/viewer accounts with one shared fixed password (`server.mjs:2397-2429`). The credentials are salted/hashed, but their values are hard-coded and logged in startup messages. A fresh database also receives demo products, leads, quotes, orders, invoices, returns, clients, and inbox threads (`:181-310`, `:2373-2396`). No `NODE_ENV`/explicit seed flag was found around these startup paths. Existing seeded users are not reset every boot, but missing demo users are re-created.

**Remediation:** make demo seeding opt-in and impossible in production mode; remove fixed credentials; use one-time environment-controlled bootstrap with forced password rotation/MFA or an approved identity provider; prevent demo data seeding in a production database; test that production startup refuses to create a known owner account. Do not reuse local preview accounts in live environments.

### F-06 — Authenticated GET routes ignore most role capabilities

**Priority: P1 · Source and local runtime confirmed; final policy is a product decision**

The API maps roles to capabilities and restricts writes, but after token validation it only has a special GET restriction for `/api/users`; the general capability check runs only when `req.method !== "GET"` (`server.mjs:562-624`). As a result, roles intended only for content, sales, finance, or view-only work can read unrelated protected endpoints.

**Local repro:** the viewer account read `/api/invoices` (HTTP 200, 3 records) and `/api/clients` (HTTP 200, 13 records); the returned client/invoice objects contain customer/contact fields. `/api/inbox` returned HTTP 200 but was empty in this particular seed. The suite explicitly expects viewer `/api/stats` to be 200 and every viewer write to be 403 (`scripts/qa-full.mjs:466-468`); it does not test viewer access to invoices/clients/inbox, or sales access to finance, or finance access to CRM.

**Remediation:** define resource-specific read capabilities and document which roles may see PII/financial data. Apply them to GETs as well as writes, then add denial tests across all role/resource combinations. If universal authenticated read access is intentional, rename/document the role and still minimize which fields it receives.

### F-07 — Storefront TypeScript is not a passing gate in the current workspace

**Priority: P1 · Current local run confirmed**

A direct Storefront `tsc --noEmit` check reports 1,039 diagnostics. `npm run build` still succeeds because the Storefront build runs Vite and does not perform a typecheck. The root `typecheck` script only invokes the Dashboard build. The workspace installs React and `@types/react` 19 for the Dashboard while Storefront declares React 18; `npm ls` shows the React 19 packages deduped into the Storefront tree and marked invalid for some React-18 peer ranges. `apps/storefront/src/lib/mcp/*` also imports `@lovable.dev/mcp-js`, which is not declared in the Storefront manifest and produces three TS2307 diagnostics.

The diagnostics are dominated by `TS2786`/`TS2344` JSX and generic incompatibilities and likely cascade from the type-version mismatch; they should not be presented as 1,039 independent application bugs. Nevertheless, the current local typecheck does not pass and is not gated by build/CI. Storefront lint passes with 13 warnings and no errors; the older “27 errors” claim is stale for this worktree.

**Remediation:** align workspace React/type versions or isolate the app’s dependency tree; remove or declare the MCP dependency; add a dedicated Storefront typecheck to root scripts and CI; treat the typecheck as required rather than inferring type safety from Vite output.

### F-08 — CI is untracked, has unauthenticated protected-endpoint smoke tests, and E2E cannot pass as written

**Priority: P1 · Source and local HTTP checks confirmed; workflow itself not run**

The current `.github/workflows/ci.yml` is untracked and absent from `6cd9fc…`; GitHub does not run a file stored only under `ci/`. The current `ci/github-actions.yml` source copy is also untracked in this checkout and omits the Page Builder E2E step found in `.github/workflows/ci.yml`, despite `ci/README.md` calling it a maintained copy.

The workflow calls protected `GET /api/stats` and `GET /api/invoices` without a token (`.github/workflows/ci.yml`, API smoke and finance loop). Both returned HTTP 401 anonymously in the local API, so the `curl -fsS` checks fail before CI can be green. The workflow has not been executed on GitHub.

Even after correcting those calls:
- `e2e-login.mjs` treats console errors as failures. Dashboard and Storefront Vite configs force HMR `clientPort: 443` (`apps/dashboard/vite.config.ts:14-20`, `apps/storefront/vite.config.ts:9-15`); the CI workflow serves the apps over plain `http://localhost`, and the local login E2E reproduced a connection refusal to `ws://127.0.0.1:443`.
- The CRM, quotation-template, and Page Builder scripts all stop at `getByText("Overview")`, which is ambiguous in the current Dashboard navigation (`scripts/e2e-storefront-crm.mjs:118`, `e2e-quotation-template.mjs:74`, `e2e-page-builder.mjs:69`). All three were executed locally and failed at that locator before their intended flows.

**Remediation:** put one canonical workflow at `.github/workflows/ci.yml`; use auth for protected smoke routes or keep health smoke public; make test HMR match the HTTP origin without weakening HTTPS preview configuration; use unique semantic locators; execute CI and retain results. Add Storefront typecheck and a checkout/order/privacy journey.

### F-09 — Dashboard mixes live API data with hard-coded demo operations

**Priority: P1 for operational decision-making · Source-confirmed**

The source calls `apps/dashboard/src/data/mock.ts` “mock operational data.” The Dashboard’s 12-month revenue series and summary are imported from that file (`pages/Dashboard.tsx:20`, `:80-115`); quotation pipeline, operations counts, and sample activity are also hard-coded (`components/dashboard.tsx:10`, `:260-360`). The activity feed appends the mock entries after API events, and the operations status cards use static `opsStatus` values. `Projects.tsx` imports fixed projects, BOQ, milestones, and project files (`:14`, `:32-50`); the project workspace is not backed by a projects API. Analytics also imports static campaign/source/conversion series.

Some Dashboard elements are API-backed (for example, recent leads, payments, and several KPIs), so this is a **mixed** dashboard rather than an entirely fake one. The risk is that static cards appear beside “live from shared DB” copy and may be mistaken for actual operational metrics. Several actions are preview/toast-only.

**Remediation:** connect each operational panel to persisted APIs or label it clearly as demo/sample and disable its use for business decisions in production. Keep live data provenance, selected time range, and empty/error state explicit; do not claim multi-tenant isolation from the client-only workspace selector.

### F-10 — No production payment path or hosted PostgreSQL verification

**Priority: P1 release gate · Confirmed absence in audited source/test scope**

The checkout explicitly says no payment is collected. Its COD/bank-transfer choice is a request for follow-up, not a payment. The invoice screen lists methods such as cash, cheque, JazzCash, EasyPaisa, and card for manual ledger entry; no payment gateway client, signed provider webhook, authorization/capture, refund integration, or provider reconciliation path was found. The API’s invoice-payment endpoint records a supplied amount/method in the internal ledger; it does not verify that money moved.

`npm run qa:pg` exercises the PostgreSQL adapter against embedded PGlite using local SQLite seed data. It does not establish hosted PostgreSQL connectivity, TLS/pooling behavior, production migration, concurrent workload, backup/restore, or rollback readiness. No real payment, hosted database, production deployment, or recovery drill was run.

**Remediation:** keep payments and production cutover approval-gated. Before launch, obtain an approved provider/database decision, then test provider webhooks/idempotency/reconciliation and a staged PostgreSQL migration/canary with backup and restore. PGlite remains rehearsal evidence only.

### F-11 — Public write abuse and URL-token exposure need hardening

**Priority: P2 · Source-confirmed; no live abuse test**

The login endpoint has per-account and per-IP throttles (`server.mjs:1285-1304`), but public lead/order capture and public lookup/SSE do not have equivalent route-specific rate limits. The same sequential references that make order lookup guessable increase enumeration risk. In addition, the global token resolver accepts `req.query.token` for protected requests (`:594-598`), not just the signed CMS-preview route; URL tokens can be copied into browser history, proxy/access logs, and referrers.

**Remediation:** add public-route throttles/abuse controls; restrict query-token support to a narrowly scoped, short-lived preview flow; prefer headers for authenticated API calls; add retention/monitoring limits for anonymous SSE connections and analytics writes.

### F-12 — Current secret-scan result is not evidence for this workspace tree

**Priority: P1 hygiene gap · Confirmed**

`scripts/secret-scan.mjs` calls `git ls-files` and uses `git show HEAD:<file>` when possible. The current run reported “clean (1 tracked files),” while nearly all app source, dependencies, workflow, and docs are untracked. It therefore did not scan most audited code and may scan committed rather than modified contents for tracked files.

**Remediation:** make the scanner inspect worktree contents, including untracked files (with safe file exclusions), and prove its coverage in CI after the application files are tracked. Do not treat the current clean output as a full-repo scan.

### F-13 — CMS publish isolation is currently passing; old draft-leak concern is stale

**Status: resolved in current source and covered by API QA**

Public page reads use `published_snapshot`; draft content requires a valid signed preview or authorized session (`server.mjs:2183-2206`, `:2273`; `scripts/qa-full.mjs:362-397`, `:605-621`). The current SQLite and PGlite suites passed published/draft isolation, valid/forged/expired preview checks, and rollback. This resolves the claim that ordinary edits immediately change the live public page. It does not substitute for the Page Builder browser journey, which did not run successfully.

### F-14 — Form false-success behavior is fixed locally, but not browser-accepted

**Status: stale at the current worktree; historically accurate at `6cd9fc…`**

At the referenced commit, the form handlers awaited submissions but proceeded to success without checking the nullable result. In the current local source, `runtime.ts:121-137` returns `null` on non-2xx/network failure; Contact and Quotation reject a null result (`Contact.tsx:45-67`, `Quotation.tsx:68-115`), and Checkout requires a returned order reference and only clears the cart after confirmation (`Checkout.tsx:50-68`).

This is a source review, not browser acceptance: the cross-app E2E failed at the Dashboard locator before it reached the forms. The public lead/quotation API paths themselves passed the current API suite.

## 5. Supplied-report claim adjudication

| Report claim | Audit disposition |
|---|---|
| Contact/quotation/checkout can show success after a failed request | **Was true at `6cd9fc…`; corrected in current local source.** Browser journey remains unverified. |
| Anonymous-data checks cover known public order lookup | **Not established.** The denial battery covers protected routes, while lookup is intentionally allowlisted; tests only an unknown reference (404), not a known order’s response. |
| TODO-7 public SSE PII scrub is complete | **Incorrect for the cited source and current source.** Invoice/payment events still broadcast names and financial metadata; QA exercises only a lead event. |
| Viewer is read-only and therefore safely scoped | **Writes are denied, reads are broad.** Current viewer can read invoices and client records; test only codifies a viewer read of stats. Decide and enforce data-read policy. |
| CMS drafts are visible to the public before publish | **Stale for current source.** Published snapshots and signed previews pass local API QA. |
| Storefront lint/typecheck both pass | **Lint passes with 13 warnings; current standalone TypeScript check fails.** The documented typecheck pass is not reproducible in this install. |
| Full API suite is 77/78/88 checks | **Historical and inconsistent counts.** Current worktree run is 91/91 on SQLite and 91/91 on PGlite. |
| Hosted PostgreSQL readiness | **The report correctly leaves the live cutover pending.** Current PGlite success remains rehearsal evidence only; no hosted canary was run. |
| Payment / browser E2E coverage | **The report marks browser E2E as pending, correctly.** No payment provider was found; the current browser journey scripts fail before reaching their target flows. |
| Clean secret scan proves no secrets in this workspace | **Not supported.** Current scanner run covered one tracked file only. |

## 6. Prioritized remediation and release gates

### Gate A — security and transaction correctness

1. Remove or sanitize the public SSE stream; add tests for invoice issuance, payment, login/team, and order events.
2. Replace reference-only public order access with an unguessable token and minimal response; add rate limits and authorization/privacy tests.
3. Disable fixed demo-account and synthetic business-data seeds outside explicit local/demo mode; require secure bootstrap and rotation.
4. Reprice orders server-side from catalog records, validate inventory/quantities, define approved tax/shipping/fee math, persist delivery/payment-preference fields, and add idempotency.
5. Define least-privilege read scopes; enforce them for every protected resource and test cross-role access to CRM, inbox, invoices, returns, pages, users, and catalog.

### Gate B — reproducible build and browser acceptance

6. Align React, React DOM, and type packages across workspaces; remove/declare the missing MCP dependency; make Storefront typecheck required.
7. Correct CI’s anonymous protected-endpoint calls; fix HMR for the CI HTTP origin and ambiguous Playwright locators; keep one canonical tracked workflow at `.github/workflows/ci.yml`.
8. Run all browser E2E journeys to completion, adding checkout/order lookup, price tampering, error/retry, privacy, and payment-preview coverage. A browser script starting is not a pass; retain the run and artifacts.
9. Make analytics/projects/operations either API-backed or unmistakably demo-only. Verify every “live” claim against its data source.
10. Expand secret scan to untracked/worktree content and triage the reported dependency advisories before release.

### Gate C — approval-gated production readiness

11. Obtain explicit approval for hosting/database/payment-provider choices before a live cutover. Run a hosted PostgreSQL canary, load/concurrency checks, migration rollback, backup/restore, monitoring, and incident-readiness checks.
12. Implement and verify any payment-provider authorization, signed webhook, idempotency, reconciliation, refund, and customer-facing disclosure requirements before presenting the storefront as accepting payment.
13. Use synthetic data for previews. Do not import or expose real customer data until privacy controls, access roles, retention, and operational ownership are approved.

The canonical planning document remains [`AGENCY-OS-MASTER-PLAN-V2.md`](AGENCY-OS-MASTER-PLAN-V2.md); keep the old plan for traceability. This audit recommends gates but does **not** approve a stack rewrite, hosted database cutover, payment provider, or production launch.

## 7. Separate reference-app evidence

The imported `apps/woodex-reimagined-preview` is an independent preview app and should not be confused with the Woodex 2030 dashboard/API/storefront integration. Its local build and TypeScript checks pass; lint has 10 warnings; `npm audit` reports 31 vulnerabilities (10 moderate, 21 high). A separate upstream GitHub Actions run, [42252898470](https://github.com/blackibexofficial-blip/woodex-reimagined/actions/runs/42252898470), passed typecheck, lint, build, smoke E2E, and accessibility steps for commit `771052ac2eb0aeab8f4dca1c374c2b8fe310a98b`. That is useful evidence for that app only—not CI or production evidence for Woodex 2030.

## 8. Vercel deployment-readiness check — 2026-11-06

The linked Vercel project `woodmax-2026` already shows a Git connection to `woodex420/woodex-2030`, with `main` as its production branch. The Git link itself does not need to be re-created. However, the project has no selected framework, root directory, install/build command, or output directory, and no `vercel.json` is present in the local workspace.

The latest two production deployment records are **ERROR** with `pnpm run build` exiting 1; their metadata identifies the old `woodmax-2026` repository, not `woodex-2030`. An older READY deployment also points at the old repository. The project record currently says `live: false`, and the Preview deployment query returned no deployments. These records do not prove the status of any external custom domain.

The GitHub `main` branch currently resolves to `e7b7…`, whose tree contains only `README.md`. The Dashboard, Storefront, API, package manifests, and workflow being audited are untracked local files. Vercel’s Git integration cannot deploy those unpushed files. The monorepo also contains two Vite frontends plus an Express/SQLite API, so deploying the repository root without an approved routing/runtime/database plan would not produce a complete, working production application.

**No Vercel settings were changed and no deployment was triggered.** I held production deployment because the target branch has no tracked app source, the Vercel build is unconfigured and has recent failed production builds, and the audit’s security/checkout blockers remain open. This is a safety hold—not a claim that the Vercel project is inaccessible.

## 9. Final status of the original audit snapshot

**The original audit was completed before the follow-up implementation; no application or workflow code had been changed at that point.** The runtime/API findings in sections 1–8 are evidence from that earlier local source state, not claims of live exposure. See the dated follow-up below for the current code and verification state.

## 10. Follow-up implementation and release status — 2026-11-06

This addendum supersedes only the statements above about the current local source and current QA counts. It does not erase the original repro evidence, represent a production penetration test, or establish that the project is live. All recent test records are local and synthetic.

### Implemented and covered by local API regression tests

- **Order pricing:** checkout reloads each product and stock status from the catalog, replaces client-supplied item names/prices, validates requested quantity, and calculates subtotal/shipping/total on the server. The generated invoice is checked against those values.
- **Order tracking:** the API returns a high-entropy one-time code at order submission, stores only its SHA-256 digest, requires both reference and code, compares digests in constant time, and returns progress fields only. Reference-only and incorrect-code requests are denied. Browser-based recovery/checkout behavior is still unverified.
- **Read authorization and events:** protected GET routes are capability-checked; the events stream requires owner authentication locally and is disabled with HTTP 410 in the serverless runtime. Vercel clients use polling because function instances do not share an in-memory event bus. The current tests verify anonymous/viewer stream denial and a local owner stream, but are not a full privacy review of every owner-authorized event type.
- **Production bootstrap:** Vercel/production requires PostgreSQL and fails closed without `DATABASE_URL`; local demo users, customer/order/invoice records and inbox threads are not seeded in the serverless runtime. First owner creation requires `INITIAL_ADMIN_EMAIL` and a unique password of at least 16 characters. The serverless bootstrap may insert repository-owned catalog rows only.

### Latest local verification

| Check | Result | Boundary |
|---|---|---|
| SQLite API/security suite | **92/92 pass** | Local isolated SQLite rehearsal with synthetic records; not hosted production evidence. |
| PGlite/PostgreSQL-semantics API suite | **92/92 pass** | Local rehearsal; not a hosted PostgreSQL canary. |
| Combined Vercel build | **Pass** | Builds Storefront and Dashboard into the configured `dist` layout; not a Vercel deployment. |
| Full local CI/browser run | **Database/API stages pass; overall exit 1** | Playwright could not launch because Chromium is absent in this sandbox; browser journeys did not run. Require a completed GitHub PR workflow before merge. |
| Secret scan / staged diff checks | **Clean on the staged tree at the latest recorded scan** | Re-run after any further changes or restaging. |

### Vercel and production database status

The current target is the authenticated Vercel project **`woodex-2030`**, connected to `woodex420/woodex-2030`, with `main` as the production branch. The project's build command, install command and output directory are configured for the combined app. The current environment-variable list is empty. The only known promoted production deployment is still the prior README-only `main` commit; no new deployment was triggered.

No hosted database provider/project has been selected or provisioned. Existing Supabase projects were not reused. No database credentials or production environment variables were created, and no hosted migration or customer-data import was performed. The exact database provider and plan still need explicit approval before provisioning.

### Current disposition

**Still NO-GO for production traffic or real customer transactions.** Before release, the owner must approve a dedicated database and its plan; provision isolated Preview/Production databases and secrets; apply and verify the schema; run a hosted PostgreSQL canary plus backup/restore checks; complete the browser E2E suite in CI; and finish the release-readiness audit. Checkout currently creates an order request only: it does not capture payment and does not persist the delivery address or payment preference. No live messaging or payment-provider delivery is claimed.

# WOODEX 2030 — Technical Debt

**Date:** 2026-11-09 · **Commit:** `f583ac0`

> **Note (post-`f583ac0`):** migration `008_audit_log.sql` adds `audit_logs`.
> Counts in this document describe `f583ac0`; current counts live in
> [`00-CURRENT-STATE.md`](../agency-os/00-CURRENT-STATE.md) — now
> **32 tables, 31 CHECKs, 8 migrations, 108 routes**.

Debt is recorded with the *reason it is dangerous*, not just its existence. An
item with no concrete consequence is not debt, it is preference.

## T-1 · Single 3,570-line API file — HIGH

`apps/api/server.mjs` is one module holding 106 routes, schema bootstrap, seeds,
RBAC, vocabulary normalisers and SSR routing. There is no service layer and no
bounded-context boundary.

**Consequence:** every change risks unrelated features; a merge conflict is
likely at any two simultaneous changes. This is the structural reason the spec's
"modular monolith" is a real goal and not pedantry.

**Direction:** extract by bounded context *incrementally*, keeping the same
routes. Not a rewrite.

## T-2 · No durable queue — HIGH

Email, PDF generation, exports and webhooks (when they exist) would run inline.
A request that generates a quotation PDF blocks the lambda.

**Consequence:** timeouts under load, and no retry semantics.

## T-3 · In-memory rate limit — HIGH (see Security S-2)

## T-4 · `stock_reservations` written by nothing — MEDIUM

The table and its FKs exist; no code path inserts a row. A cart therefore does
not hold stock, so two shoppers can both pass availability and one loses at
checkout.

**Consequence:** overselling under concurrency. Documented as a deliberate
deferral in `03-DATA-AND-TENANCY.md`, but it is still a live behavioural gap.

## T-5 · Two preview renderers in the page builder — MEDIUM

`BlockPreview` (`Website.tsx:55`) and the iframe preview (`Website.tsx:414`)
render the same blocks through different code paths.

**Consequence:** the editor can show something the published page does not. This
is the classic WYSIWYG lie and it erodes trust in the editor more than a missing
feature would.

## T-6 · Tailwind 3 vs Tailwind 4 split — MEDIUM

Storefront on 3.4, dashboard on 4.3. A shared design system (spec §9) cannot be
built across both.

## T-7 · `api/products?*` pagination added but unpaged elsewhere — LOW

`/api/products` now honours `limit`/`offset` (PR #18). Leads, clients, orders,
invoices and tasks still return whole tables. The catalogue was the worst case
(148 rows); the others are small today and will not stay small.

## T-8 · QA check count is unguardable — LOW

`docs:verify` verifies route/table/constraint counts but **cannot** verify the QA
count: checks are generated inside loops (87 static `await T(` calls → 108 at
runtime). It can rot silently. Recorded here so a future reader does not assume
it is guarded.

## T-9 · `BlockPreview`-adjacent dead paths and TODO markers — LOW

The codebase carries numbered `TODO-n` comments referenced by QA checks (e.g.
`TODO-2`, `TODO-5`, `TODO-9`). These are load-bearing — a QA check asserts each
one — so they must not be "cleaned up" without reading the test.

## Deliberately **not** repaid

- **Express → Laravel.** The spec prefers Laravel long-term. Repaying this now
  buys nothing and risks everything; 108 tests are written against Express.
- **SQLite adapter.** It is the local/test engine and works. Removing it would
  make local development depend on a hosted database.

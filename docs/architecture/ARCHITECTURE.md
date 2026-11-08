# 02 · Architecture (approved application shape)

**Status:** The single-project Vercel shape and existing Vite/Express application are selected. The production database provider, plan, multi-tenant model and go-live remain approval-gated; see the current Vercel runbook.

## Bounded contexts (modules ↔ tables ↔ owners)
| Context | Tables (SQLite locally; PostgreSQL on Vercel) | Dashboard route | Notes |
|---|---|---|---|
| Catalog | products, materials, services | /catalog | storefront hydrates through the shared API and polls for catalog updates |
| CRM | leads, clients, client_links, tasks | /crm, /clients | identity conflicts require human review; no silent merge |
| Sales | quotes, quote_versions, quote_templates, invoices, payments | /quotations, /invoices | calculated quote totals, immutable revisions; manual payment records are not gateway settlements |
| Operations | orders, returns, site_visits | /operations, /orders | order request workflow; payment capture is not implemented |
| Website/CMS | pages, page_blocks, page_versions, saved_sections | /website | typed blocks, published snapshots, signed previews and rollback |
| Platform | users, sessions, roles/capabilities, meta | /settings | API auth/RBAC exists; production owner bootstrap requires secure Vercel environment variables |
| Omnichannel | conversations, messages | /inbox | manual/logged WhatsApp handoff only; no provider delivery claimed |
| Automation/AI | not implemented as a workflow engine | — | future, explicitly permission-gated work |

## Data flow

**Local development:** Storefront and Dashboard use Vite proxies to the shared Express API, which persists to SQLite at `data/woodex.db`.

**Vercel target:** one project serves `/` (Storefront), `/dashboard/*` (Dashboard), and `/api/*` (Node serverless functions). The API requires PostgreSQL through `DATABASE_URL`; SQLite/PGlite are local/rehearsal-only. The production database has not yet been selected or configured.

Writes include public lead, quote and order-request submissions plus authenticated Dashboard mutations. Dashboard consumers refresh on a 15-second poll; the Storefront checks catalog overrides every 15 seconds and refreshes shared theme settings about every 60 seconds. The owner-only `/api/events` stream is local-runtime only and returns 410 in serverless; browser clients use polling because Vercel function instances do not share an in-memory event bus.

## Tenancy plan (kept dormant until P2)
Every future table gets `tenant_id INTEGER DEFAULT 1`; existing tables add it in the Postgres migration with RLS `USING (tenant_id = current_tenant())`. API gains `X-Tenant` resolution after auth. Agency OS stays generic: contexts above become modules in `packages/` (crm, ecommerce, builder-core, theme-engine, ai, integrations) per PRD monorepo §09 — extraction only when a second tenant is real (Interiors stays its own product; boundary per plan v2.1 §0).

## P5 finance invariants
Invoice total = subtotal − discount + tax + shipping; `paid ≥ total → Paid`, `paid>0 → Partially Paid`; due < today && balance>0 && not Paid/Cancelled → **Overdue** (computed on read, never stored stale). 50% advance convention: recorded as first payment; order board doesn't gate on it yet (P13 production gate). One invoice per quote enforced at app layer via quote audit + relink (quote_id), cancel is explicit status, never delete (PRD §23 preserve-data rule).

## Static-HTML export (P7, rule A1)
Published pages render to DB JSON (source of truth) + emitted static `.html` for SEO/cache. Delete/rename admin-only with forced redirect (Interiors lesson). Renderer is shared between editor preview and public route (one contract).

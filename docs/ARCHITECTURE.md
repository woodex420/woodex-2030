# 02 · Architecture (Phase 1 — approved direction)

**Status:** v1.0 · follows PRD §09; adapts to this repo's reality (Vite apps + proxy API) with the PRD target as the migration shape.

## Bounded contexts (modules ↔ tables ↔ owners)
| Context | Tables (SQLite today → Postgres in P2) | Dashboard route | Notes |
|---|---|---|---|
| Catalog | products, materials, services | /catalog | storefront hydrates via overrides poll |
| CRM | leads (+ clients in P4) | /crm | kanban PATCH; single client record scheduled |
| Sales | quotes, **invoices, payments** | /quotations, /invoices | quote→invoice→payment live now; PDF = print-view |
| Operations | orders, **returns** | /operations | 5-stage board; RMA queue inside |
| Website/CMS | pages, page_blocks, page_versions (P7) | /website | builder §30 |
| Platform | tenants, users, roles, capabilities, audit_log (P2) | /settings | mock sign-in until then |
| Omnichannel | conversations, messages, wa_events (P10) | /omnichannel | provider abstraction: Meta Cloud + 3P |
| Automation/AI | flows, flow_runs, ai_actions (P11–12) | — | human-approved sends |

## Data flow (live, verified 5 Oct)
REST `/api` via Vite proxy both apps → Express → `node:sqlite` WAL at `data/woodex.db`.
Writes: storefront (leads/quotes/orders via `src/lib/runtime.ts`) + dashboard mutations.
Reads: dashboard poll 15–30 s; storefront overrides 15 s + `woodex:runtime` event. → **P3: replace with SSE.**

## Tenancy plan (kept dormant until P2)
Every future table gets `tenant_id INTEGER DEFAULT 1`; existing tables add it in the Postgres migration with RLS `USING (tenant_id = current_tenant())`. API gains `X-Tenant` resolution after auth. Agency OS stays generic: contexts above become modules in `packages/` (crm, ecommerce, builder-core, theme-engine, ai, integrations) per PRD monorepo §09 — extraction only when a second tenant is real (Interiors stays its own product; boundary per plan v2.1 §0).

## P5 finance invariants
Invoice total = subtotal − discount + tax + shipping; `paid ≥ total → Paid`, `paid>0 → Partially Paid`; due < today && balance>0 && not Paid/Cancelled → **Overdue** (computed on read, never stored stale). 50% advance convention: recorded as first payment; order board doesn't gate on it yet (P13 production gate). One invoice per quote enforced at app layer via quote audit + relink (quote_id), cancel is explicit status, never delete (PRD §23 preserve-data rule).

## Static-HTML export (P7, rule A1)
Published pages render to DB JSON (source of truth) + emitted static `.html` for SEO/cache. Delete/rename admin-only with forced redirect (Interiors lesson). Renderer is shared between editor preview and public route (one contract).

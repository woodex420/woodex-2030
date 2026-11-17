# 12 — Decision Log

This is the canonical record of product and architecture decisions. Update this file when an owner decision changes.

## Confirmed decisions

| ID | Decision | Status | Evidence |
|---|---|---|---|
| D-001 | Product is one unified WOODEX Agency OS for the WOODEX furniture business | Confirmed | Owner selection |
| D-002 | Reuse generic Laravel modules, remove Interior-specific dependencies and rebuild missing functionality | Confirmed | Owner selection |
| D-003 | Keep existing React storefront and dashboard; reimagine both UIs | Confirmed | Owner selection |
| D-004 | Production database target is MySQL on Hostinger | Confirmed | Owner selection |
| D-005 | Hostinger environment is Shared Hosting | Confirmed | Owner statement |
| D-006 | Puck is selected direction for complete storefront page builder | Confirmed direction; technical gate pending | Owner selection |
| D-007 | Laravel manages page persistence, permissions and publishing | Confirmed direction | Owner recommendation accepted |
| D-008 | Do not include Woodex Interior-specific branding/data/workflows in this product plan | Confirmed | Explicit project boundary |
| D-009 | Preserve React storefront and dashboard as separate applications | Confirmed | Owner selection |

## Repository-backed findings

| ID | Finding | Status |
|---|---|---|
| F-001 | Current React storefront and dashboard are separate apps | Verified in repository |
| F-002 | Current API is Express/Node; repository documents PostgreSQL/Supabase production | Verified in current-state documentation; revalidate before migration |
| F-003 | Laravel foundation exists under `platform/` | Verified in repository |
| F-004 | Some Laravel modules are disabled/incomplete | Verified in Laravel gap register |
| F-005 | Existing typed-block React CMS is current content authority | Verified in gap register |
| F-006 | Puck is evaluated but not yet integrated/accepted | Verified in gap register |
| F-007 | `/dashboard` public route shows authentication screen | Verified during live URL inspection |
| F-008 | React sidebar source includes old “Woodex Interiors” workspace identity | Verified in source inspection |
| F-009 | Storefront and dashboard currently use different Tailwind major versions | Verified in package manifests |
| F-010 | Current production and target Hostinger/MySQL architectures differ | Verified from current-state docs and owner decision |

## Provisional assumptions — confirm before implementation

| ID | Assumption | Why confirmation is needed |
|---|---|---|
| P-001 | Currency is PKR | Financial documents, reports and price schema |
| P-002 | Tax rules are configurable | Invoice/quotation calculations and compliance |
| P-003 | Payments are manually recorded initially | Determines checkout/gateway scope |
| P-004 | Client portal is included | Determines auth, privacy and project scope |
| P-005 | Quote approval is role-based | Needs role matrix and thresholds |
| P-006 | Single WOODEX organization/workspace initially | Multi-branch and multi-tenant schema implications |
| P-007 | English is initial admin language | Localization and content schema |
| P-008 | Existing domain and URL structure should be preserved where possible | SEO and redirect plan |

## Open decisions

| ID | Question | Required before |
|---|---|---|
| O-001 | Exact Hostinger plan name and resource limits? | Deployment architecture |
| O-002 | PHP version, extensions, SSH, Composer and cron availability? | Laravel release planning |
| O-003 | Domain/subdomain topology for storefront, dashboard and API? | Authentication/CORS/session design |
| O-004 | Does storefront require online payment gateway checkout? | Commerce/finance design |
| O-005 | Are delivery and installation separate workflows? | Operations schema |
| O-006 | Product variants/options and stock policy? | Catalogue/inventory |
| O-007 | Warehouse/branch count and locations? | Inventory model |
| O-008 | Accounting policy and chart of accounts? | Ledger and financial reports |
| O-009 | WhatsApp/Telegram providers and approved credentials? | Integration build |
| O-010 | Who can approve quotes, discounts, expenses, purchase orders and publishing? | Permission matrix |
| O-011 | What is the actual AI agent failure example and task? | Agent workflow diagnosis |
| O-012 | Is the authenticated dashboard accessible in a staging/test account for visual QA? | Pixel-level UI acceptance |

## Change procedure

For each decision change:
1. Record date and owner.
2. State old and new decision.
3. Identify affected docs, code, schema, APIs and tests.
4. Assess migration and compatibility.
5. Obtain approval for data/deployment impact.
6. Update this log and linked documents in one reviewable change.

# 11 — Implementation Roadmap

## Delivery principles

- Discovery before destructive work.
- Build a vertical slice before broad parallel implementation.
- Reuse existing working code where proven.
- Keep production stable.
- One module cutover at a time.
- Every phase ends with evidence and owner review.

## Phase 0 — Discovery and baseline

**Deliverables**
- Current route and feature inventory.
- React component and design-token inventory.
- Laravel module and migration audit.
- API endpoint and data ownership map.
- Database schema comparison.
- Hostinger capability checklist.
- Verified / partial / planned / retired feature register.
- Authenticated dashboard visual reference in staging.

**Exit criteria**
- No major unknown about current code ownership.
- No production changes.
- Risks and open decisions recorded.

## Phase 1 — Product and design foundation

**Deliverables**
- Approved design system.
- Navigation registry.
- Shared component standards.
- Responsive app shell.
- Branding cleanup from Interior-specific UI labels.
- UX prototypes for dashboard and core list/detail patterns.

**Exit criteria**
- Owner approves sidebar, global tokens and shell.
- Existing routes still work.

## Phase 2 — Dashboard shell

**Deliverables**
- Redesigned sidebar.
- Topbar and global search/command palette.
- Favorites and recent screens.
- Role-aware navigation.
- Responsive mobile drawer.
- Dashboard widget framework.

**Exit criteria**
- Permission and keyboard tests pass.
- No duplicate/placeholder navigation.

## Phase 3 — Core business workflow vertical slice

Recommended first slice:
Lead → Contact → Estimate → Quotation → Approval → Invoice/Order handoff.

**Deliverables**
- Laravel API and domain behavior.
- React screens.
- Data model and migration rehearsal.
- Tests and audit events.

**Exit criteria**
- End-to-end flow works on staging with real test records.
- Single writer established for the migrated slice.

## Phase 4 — Commerce and operations

**Deliverables**
- Product catalogue.
- Categories and series.
- Orders.
- Suppliers and purchasing.
- Goods receipts.
- Stock ledger and warehouses.
- Production jobs/stages.
- Delivery/installation.

**Exit criteria**
- Stock and order invariants pass.
- Reconciliation and exception handling pass.

## Phase 5 — Finance and projects

**Deliverables**
- Invoice/payment allocation.
- Payables and expenses.
- Ledger/reporting foundations.
- Projects, milestones, vendors/costs and handovers.
- Client portal access.

**Exit criteria**
- Finance owner validates accounting behavior.
- Client data isolation tests pass.

## Phase 6 — Storefront reimagination

**Deliverables**
- Global storefront design system.
- Homepage and page templates.
- Catalogue/product/category/series experience.
- Quote/contact journeys.
- Responsive and SEO improvements.

**Exit criteria**
- Key conversion journeys pass.
- Existing URL preservation/redirect plan accepted.

## Phase 7 — Puck prototype

**Deliverables**
- Pinned Puck version.
- Shared component registry.
- Editor/renderer round trip.
- Laravel persistence and revisioning.
- Protected preview and publishing proof.

**Exit criteria**
- Technical prototype accepted before full editor rollout.

## Phase 8 — Website Studio rollout

**Deliverables**
- All Pages.
- Visual Page Builder.
- Navigation manager.
- Reusable sections/templates.
- Theme/design system.
- Media library.
- SEO and redirects.
- Approval, publish and rollback.

**Exit criteria**
- Selected representative pages migrated and approved.

## Phase 9 — Automation, inbox and integrations

**Deliverables**
- Conversation inbox.
- WhatsApp/Telegram/email adapters as approved.
- Templates and sequences.
- Automation engine with dry-run, retries and audit.
- Marketing attribution.

**Exit criteria**
- Consent, opt-out, failure handling and provider tests pass.

## Phase 10 — Hostinger production migration

**Deliverables**
- Verified hosting matrix.
- Laravel/MySQL staging.
- Static React builds.
- Cron/queue strategy.
- Backup and restore evidence.
- Migration rehearsal and cutover plan.

**Exit criteria**
- Owner authorizes production cutover.
- Rollback and support coverage ready.

## Priority model

- **P0:** security, data integrity, auth, deployment viability, core sales flow.
- **P1:** core commerce, procurement, inventory, projects and finance.
- **P2:** Puck full rollout, automation, marketing and advanced reports.
- **P3:** convenience enhancements and low-impact polish.

Actual priority can change after Phase 0 evidence and owner review.

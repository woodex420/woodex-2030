# WOODEX Agency-Grade Dashboard & No-Code Platform — Master Plan

**Plan version:** 2.0 · **Prepared:** 2026-10-05 · **Status:** planning only — implementation requires your approval
**Scope:** dashboard UX, no-code page builder/theme studio, CRM and automations, frontend data model, component/page catalogue, security gates.

> **Approval boundary:** This document is the deliverable for this stage. No dashboard source code, Supabase schema, or migration is being changed or deployed as part of this plan. The page-builder migration remains a draft until the complete preflight JSON and live schema/RLS review are available and you explicitly approve application.

---

## 0. Decisions confirmed before planning

| Decision | Confirmed direction | Planning consequence |
|---|---|---|
| Work sequence | Write the plan first; wait for approval before implementation | Do not begin coding from this document until you approve it. |
| Sign-in | Both: mock demo now, Supabase Auth later | Keep preview sign-in isolated to seeded mock users. Real users are invited/managed in your Supabase project after its schema and policies are checked. Never copy demo credentials into live Auth. |
| Builder/platform scope | All-industry platform from v1 | Plan a shared shell, design system, page builder, module registry, and starter page packs for SaaS, CRM, ERP, e-commerce, finance/banking, healthcare, education, HRM, AI/analytics, project management, SEO, and WOODEX. See the scope boundary below: “ready UI packs” are not the same as production integrations or regulated-domain compliance. |
| First communications channels | Website live chat + WhatsApp | Build one conversation/inbox model with two launch adapters. Keep Instagram/Facebook DMs and email in the roadmap behind provider, account, consent, and webhook readiness. |
| Product priority | The visual page builder and master theme controls are a primary project pillar | Promote Theme Studio, drag-and-drop editing, reusable blocks/templates, and controlled publishing into the critical path—not a small final add-on. |
| Charting | Deferred | Keep one shared chart adapter so the chart library can be changed later without redesigning modules. |

### Working interpretation of “all industries from v1”

V1 should make the **platform UX and starter page/dashboard packs** available across the named industries, using common responsive components, theme tokens, route manifests, permissions, and clearly marked synthetic demo data. WOODEX furniture operations and its CRM lifecycle are the first production data model. Each other vertical needs its own approved domain schema, privacy/security review, and integration plan before it can be described as production-ready. Healthcare, banking, and finance pages must not imply regulatory compliance merely because their UI template exists.

If “all industries from v1” means full live business workflows, integrations, and regulated compliance for every vertical, that is a materially larger multi-product program; that interpretation must be confirmed before estimates or implementation.

---

## 1. Executive direction

WOODEX should become a polished, responsive operations platform with a **no-code website experience at its centre**. A marketer or operator should be able to build a page, change the site's global theme, preview it on devices/languages, connect approved WOODEX data blocks, and publish a reviewed version without editing code.

Around that core, the dashboard provides a common shell and reusable application patterns for CRM, sales, commerce, operations, analytics, support, and industry starter packs. The CRM should connect the customer's journey from first website or WhatsApp contact through qualification, quotation, order, delivery, and after-sales support.

This is a **no-code product for the dashboard user**, not a promise that the platform itself contains no code. The editor will expose validated visual controls and registered blocks; it will not allow arbitrary JavaScript, SQL, or executable HTML to be pasted into a page.

### Experience principles

1. **One coherent system:** shared semantic tokens, typography, spacing, interaction states, components, and page patterns.
2. **Power without code:** discoverable controls, inline help, searchable blocks, previews, safe defaults, undo/redo, and recoverable drafts.
3. **Same preview and published output:** one block/document renderer and one theme contract across the editor and storefront.
4. **Role-aware, not role-fragmented:** one interaction model with the right modules/actions visible to each role; database policies remain the security authority.
5. **Responsive and inclusive by default:** desktop, tablet, mobile, keyboard navigation, screen-reader labels, reduced motion, light/dark, and tested RTL.
6. **Data is explicit:** live, demo, stale, and unavailable states are never visually conflated; demo metrics are labelled.
7. **Composable across verticals:** common app shell and component catalogue; vertical manifests add pages, navigation, permissions, and typed data adapters without forking the shell.
8. **Safe publishing and messaging:** preview, review, permission checks, audit trail, rollback, consent, and provider rules precede public release or outbound automation.

---

## 2. Research baseline and reuse rules

### Current WOODEX baseline

The checked-in analysis records a migration-derived schema of **45 tables, 106 policies, and 32 Edge Functions**. These figures describe repository migrations, not a live database introspection. The actual Supabase project is different from the placeholder project in the original repo and is not reachable from this environment; row counts and live policy drift are unknown.

The local monorepo already has a React 19/Vite/Tailwind v4 dashboard and storefront, shared design-system and builder-core packages, mock data mode, a basic page editor, and a draft five-table page-builder migration. The editor currently has a useful v1 foundation (registered blocks, outline, schema-driven controls, device preview, undo/redo, draft save, validation/publish); it still needs the full Theme Studio, true pointer/keyboard drag-and-drop, reusable global sections/templates, review/revision UI, and broader publishing controls described here.

### Preline UI assessment

Preline is a useful **component and interaction reference**, not a replacement for WOODEX's product architecture:

- The current public repository identifies v5.0.0 and includes Tailwind component examples, plugins, semantic CSS-variable themes, and React/Vite guidance.
- Preline's React guidance describes a DOM-driven plugin system, not a native React component package. React owns rendering/state; plugin initialization, route changes, and cleanup must be handled deliberately. Use a thin WOODEX wrapper and add only the needed plugins rather than initializing a large bundle everywhere.
- Themes expose semantic variables and a `.dark` strategy; map these to WOODEX tokens rather than creating a second source of truth. RTL behavior must be tested in WOODEX layouts and not assumed from component examples alone.
- **License gate:** the current repository/package describes dual MIT + Preline UI Fair Use terms, including attribution and additional conditions for commercial derivatives. Because WOODEX's core feature is itself a visual page builder, obtain a licensing review/clearance before copying Preline implementation code into or distributing it with the builder. Do not use Pro-only templates/assets without the required license. If clearance is not given, use Preline only as a functional reference and implement original components under the WOODEX design system.
- MCP Server, Agent Skills, AI Prompts, Figma resources, and changelog tooling are **developer resources**, not dashboard runtime modules. If evaluated later, keep them away from customer data, production tokens, and service credentials.

### WOODEX interior repository reference

The supplied `marketingwoodex-cloud/-marketingwoodex` branch is reachable. GitHub reports no repository license metadata, so this plan does not copy its code, assets, content, page JSON, customer information, credentials, or database records. Treat it only as a user-owned reference until ownership/reuse rights are confirmed. No business content or dataset from it is part of this plan or the WOODEX mock seed.

### Reference implications

- Elementor's Site Settings pattern is a strong UX reference: global colours, typography, theme styles, site identity, layout, and header/footer settings belong in one predictable place.
- GrapesJS is a possible research comparator for drag/drop interaction and JSON project persistence, not an automatic engine choice. Retain the current typed `builder-core` renderer as the default; run a bounded comparison only if native drag-and-drop proves disproportionately costly. Any candidate must preserve WOODEX's typed block allowlist, safe dynamic bindings, and identical editor/storefront renderer.

---

## 3. Product architecture and dashboard information hierarchy

### Global shell

- **Workspace/site switcher:** current workspace, website/brand, environment (demo/staging/production), and context-specific permissions.
- **Navigation:** collapsible, grouped, searchable, keyboard-accessible, and permission-aware; show current page, parent context, and breadcrumb.
- **Header:** global search/command palette, create action, notifications, help, locale/theme controls, and profile/session menu.
- **Page header:** title, concise description, status, primary action, secondary actions, filters/date range, and contextual breadcrumbs.
- **Content surfaces:** consistent max-width/grid, predictable page spacing, accessible forms and tables, useful empty/loading/error/offline states.
- **Small screens:** sidebar becomes a drawer, tables adapt to cards or horizontal overflow, filters become sheets, and the builder has a deliberate tablet/mobile editing mode rather than a compressed desktop-only canvas.

### Navigation groups

1. **Home:** role-specific overview, assigned work, alerts, recent activity.
2. **CRM:** customer/contact directory, lead pipeline, customer 360, tasks, appointments, segments, and activity history.
3. **Inbox & Support:** unified inbox, website chat, WhatsApp, queues, saved replies, SLA, and support outcomes.
4. **Automation:** visual workflows, trigger catalogue, test/simulation, execution log, approvals, and error/retry queue.
5. **Commerce & Sales:** products, categories, variants, pricing, quotations, orders, invoices, payments, and B2B accounts.
6. **Operations:** inventory, stock movements, delivery scheduling, proof of delivery, returns, calendar, and Kanban.
7. **Website Studio:** pages, visual editor, Theme Studio, global header/footer, templates, reusable sections, media, navigation, SEO, redirects, and revisions.
8. **Insights:** overview, sales, CRM/conversion, campaign/channel, inventory, content/page performance, and exports.
9. **Industry packs:** starter page sets and dashboards for the agreed verticals.
10. **Administration:** users/teams/roles, integrations, data mode, security, audit log, workspace settings, and billing configuration.

### Ready page catalogue

| Area | V1 page families |
|---|---|
| Profile & account | Profile, preferences, security/MFA, active sessions/devices, invitations, users, teams, roles, permissions, and audit activity. |
| Dashboard | Role-specific overview, KPI cards, date comparisons, trend charts, activity timeline, tasks, alerts, and configurable widgets. |
| CRM | Contacts/companies, lead inbox, pipeline board, customer 360, contact history, tasks/reminders, appointments, segments, and import/export. |
| Conversations | Unified inbox, queue/assignment views, conversation detail, website live chat, WhatsApp, templates/saved replies, SLA and CSAT. |
| Website/CMS | Page list, builder, global themes, header/footer, navigation, reusable blocks, templates, media manager, SEO, redirects, revision history, preview/publish. |
| Commerce | Product catalogue, categories, variants, pricing rules, stock, quotations, quotation details/PDF, orders, invoices/payments, delivery, returns, B2B account. |
| Forms & data | Basic/advanced forms, multi-step forms, validation, responsive data tables, search/filter/sort, server-side pagination, bulk actions, column settings, CSV import/export. |
| Planning | Calendar with day/week/month/agenda views, appointment scheduling, Kanban boards with labels/assignees/status, and activity timelines. |
| Finance | Invoice, billing, payment and transaction layouts; financial KPI/report templates. Do not enable live money movement until payment provider, authorization, reconciliation, and audit requirements are signed off. |
| Files & assets | Folder/grid/list views, search/tags, storage usage, upload progress, image preview, metadata, alt text, and permission-aware asset selection. |
| Analytics | KPI, trends, comparisons, funnel, cohort/channel, content, inventory, and business performance layouts using the shared chart adapter. |
| Industry packs | Dashboards and page templates for SaaS, CRM, ERP, e-commerce, finance, banking, AI/analytics, project management, SEO, healthcare, education, and HRM. Each pack declares which screens are templates, which use synthetic data, and which have an approved live connector. |

---

## 4. Design system and Preline component coverage

The `packages/design-system` tokens remain the source of truth. Preline patterns may be adapted only under the license gate above; avoid mixing unwrapped Tailwind snippets with a second set of colors or component behaviors.

### Theme token families

- Brand and semantic colours: primary/secondary/accent, text, surface/layers, border, success, warning, error, info, chart series, focus, and overlay.
- Typography: font family, display/headline/body/label/code roles, size scale, weight, line-height, letter spacing, and language-specific fallbacks.
- Layout: content/container width, grid and columns, breakpoints, layout splitter, spacing rhythm, page gutters, and density presets.
- Shape/elevation: radius, border width, shadow, focus ring, z-index/layering, and motion duration/easing.
- Media and utilities: images, links, dividers/`hr`, KBD, custom scrollbar, icon sizing, and empty/skeleton states.
- Modes: light, dark, optional high-contrast, tenant brand palettes, and locale direction (`ltr`/`rtl`).

### Reusable component catalogue

The required UI kit is organised into stable WOODEX React components with shared props, tokens, loading/disabled/error states, accessibility behavior, and tests:

- **Base:** accordion, alerts, avatar/avatar group, badge, blockquote, buttons/button groups, cards, chat bubbles, carousel, collapse, date/time pickers, device previews, lists/list groups, legend indicators, progress/upload progress, ratings, skeletons, spinners, styled icons, toasts, timeline, tree view, and marquee.
- **Navigation:** navbar, mega menu, navs, tabs, sidebar, scrollspy, breadcrumbs, pagination, and stepper.
- **Forms:** input/input group, textarea, file input/upload, checkbox, radio, switch, select, range slider, colour picker, time picker, advanced select, combobox, search box, numeric input, strong-password meter, show/hide password, character counter, copy-to-clipboard, PIN input, validation states, and multi-step forms.
- **Overlays:** dropdown, context menu, modal, offcanvas/drawer, popover, tooltip, and confirmation dialogs.
- **Data and plugins:** responsive tables/data tables, sorting/filtering/pagination, charts, clipboard, confetti, datamaps, drag-and-drop, upload, maps, toast notifications, and rich-text editing.
- **Application patterns:** profile/account, CRM pipeline, inbox/chat, ecommerce/catalogue, finance/invoice, calendar/scheduling, Kanban, file manager, analytics, and SEO pages.

Not every plugin belongs in the initial bundle. Adopt behind stable wrappers, load heavy integrations on demand, test route unmount/cleanup, and use a plugin only when it provides a genuine capability beyond a semantic React component.

### Visual quality and accessibility bar

- Define layouts and tokens before porting every vertical page; no one-off spacing/color overrides for routine screens.
- Use clear hierarchy, readable density, consistent table/form actions, restrained motion, visible focus, and purposeful data visualization.
- Target WCAG 2.2 AA as a product goal; validate contrast, keyboard behavior, labels, focus trapping, live-region announcements, and screen-reader paths in the actual app. A library's accessibility claims do not replace application-level testing.
- Test light/dark and LTR/RTL combinations on representative pages, especially navigation, tables, date pickers, builder controls, chat bubbles, and charts.
- Use logical CSS properties (`margin-inline`, `padding-inline`, start/end alignment) and explicit `dir` switching; do not treat RTL as a translation-only task.

---

## 5. No-code Page Builder + Theme Studio (primary product pillar)

### User outcome

A non-developer can create a page from a template, drag and arrange blocks, edit content in context, change the global theme once, preview desktop/tablet/mobile and RTL, bind approved WOODEX data, send it for review, publish, and restore a prior revision—all without writing code.

### Editor workspace

- **Top toolbar:** workspace/site/environment, page title/slug/status, save state, undo/redo, device/locale preview, preview link, validation, review request, publish/unpublish, and revision history.
- **Block library:** searchable, grouped by layout/content/commerce/lead generation/social proof/industry; includes recent/favourite blocks and templates.
- **Canvas:** real page rendering at the selected viewport; inline text/image editing; selectable blocks; visible drop targets; snap/spacing guides; locked/global blocks; pointer drag-and-drop with keyboard move alternatives.
- **Navigator/outline:** nested page tree with rename, reorder, hide/show, lock, duplicate, delete, parent/child move, and accessible selection.
- **Inspector:** schema-generated Content, Style, Responsive, Data, and Accessibility/SEO controls; controls show inherited global values and local overrides clearly.
- **Theme Studio:** site-wide settings as a dedicated panel/workspace, with a live preview and staged apply/rollback.
- **Responsive/RTL controls:** named breakpoints, breakpoint preview, device frame, `ltr`/`rtl`, language preview, and per-breakpoint controls.
- **History and recovery:** undo/redo, safe local recovery, explicit save state, immutable revisions, visual diff, restore, author/time, and audit event.

### Master Theme Studio — Elementor-style global control

Global controls must affect every page/block that inherits them and should be editable without redeployment:

1. **Site identity:** site name/description, logo, favicon, social preview image, default page shell.
2. **Global palette:** named semantic tokens with accessible suggestions; primary/secondary/accent, neutral surfaces, text, borders, status, overlays, and chart colours; light/dark variants.
3. **Global typography:** font family and fallback, role-based heading/body/label/code styles, responsive scale, line-height, weight, and link styling.
4. **Theme styles:** defaults for headings, paragraphs, links, buttons, cards, images, forms/inputs, tables, badges, and focus states.
5. **Layout system:** content max width, wide/full containers, section spacing, grid/column system, component density, breakpoints, layout split ratios, and responsive gutters.
6. **Shape and motion:** radii, borders, shadows, focus rings, transition presets, reduced-motion behavior, and scrollbar appearance.
7. **Global chrome:** reusable header, footer, navigation, announcement bar, mobile menu, sticky/transparent variants, and display conditions.
8. **Brand presets:** save named theme presets, duplicate/export/import a validated theme JSON file, preview before apply, compare revisions, restore, and apply to a site or workspace only with explicit confirmation.
9. **Dark/RTL support:** editable mode palettes and direction-aware component preview; language content remains separate from theme settings.

**Guardrails:** all values map to a versioned token schema; no unbounded CSS, JavaScript, SQL, arbitrary component import, or unsafe iframe injection. Advanced custom CSS is deferred until a sandboxed, sanitized, reviewed design is approved; it must never be required for normal page creation.

### Blocks, templates, reusable components

- **Structure:** section, container, columns, grid, stack, spacer, divider, tabs, accordion, and layout group.
- **Content/media:** heading, rich text, image, gallery, video, icon, button/CTA, quote, table, logo strip, testimonial, FAQ, stat, and timeline.
- **Commerce:** product card/grid, category strip, variant selector, price table, room package, quote basket, availability, and catalogue search.
- **Lead capture:** validated form, quote request, contact card, WhatsApp CTA, appointment request, and consent/privacy notice.
- **Global/reusable:** saved block/section, reusable page section, header/footer template, page template, and theme preset with versioning and permission-aware reuse.
- **Industry pack blocks:** enabled only by a vertical manifest and a typed data adapter; a block must not expose an unapproved table/query.

Drag-and-drop must support pointer and keyboard movement, clear drop zones, nesting limits, no accidental moves while editing text, and undo/redo. A page can use a template then be edited safely without mutating the shared template.

### Page workflow and publishing

`Create → template → edit → autosave/recover → validate → preview/share → review → approve → publish → monitor → rollback`

- Draft, review, published, archived states; editors can draft, reviewers can approve, publishers can publish according to an explicit capability matrix.
- A published revision is immutable. Editing a published page creates a draft copy; rollback points the route back to a prior validated revision.
- Preview links are scoped, signed, expiring, non-indexed, and never grant database privileges.
- Validation checks slug collisions/redirects, required alt text, heading order, broken links, contrast, missing bindings, consent/form fields, locale completeness, and unsupported block/schema versions.
- Publish includes a diff/summary, actor, timestamp, approval, cache invalidation result, and audit record. Unpublish/rollback are explicit actions with confirmation.

### Dynamic bindings and performance

- Bind through a typed allowlist (`products`, `categories`, `room_packages`, `blog_posts`, `testimonials`, `faqs`, `services`, and later approved CRM/industry read models), never a user-authored SQL string.
- The binding designer exposes permitted fields, filter operators, sort choices, row limits, and fallback content. Server/RLS authorization remains authoritative.
- Preview and storefront use the same renderer and block schema version. Test migration/compatibility for older saved documents.
- Use the current storefront routing model for the first release; decide on prerender/edge caching only after deployment/hosting is confirmed. Do not promise SSR or edge publishing before that architecture exists.
- Lazy-load interactive/high-cost blocks, paginate dynamic collections, optimize media variants, and set page-weight/performance budgets in CI.

### Builder technical choice

**Recommendation:** extend `packages/builder-core` (typed block registry, token validation, sanitization, shared renderer) rather than replacing it immediately. Before committing to a large editor rewrite, compare native canvas drag/drop with an alternative editor in a bounded spike. Acceptance gates: typed JSON round-trip, same renderer in preview/live, keyboard accessibility, safe rendering, custom data bindings, undo/redo, and no loss of existing draft documents. GrapesJS may inform interaction patterns and JSON persistence; importing arbitrary generated HTML/CSS as the canonical WOODEX format is not approved.

---

## 6. CRM hierarchy, lifecycle, inbox, and automation

### Customer and work hierarchy

```text
Platform
└── Workspace / tenant
    ├── Sites / brands
    ├── Teams and queues
    │   └── Members + role/capability grants
    └── Customer/company
        ├── Contacts, consent, source and journey
        ├── Lead/opportunity + tasks/appointments
        ├── Conversations (website chat, WhatsApp, later channels)
        │   └── Messages, attachments, assignments, SLA, events
        ├── Quotations and quotation activities
        ├── Orders, payments/invoices, delivery and returns
        └── Support cases, outcomes and customer feedback
```

### Complete lifecycle

`Website form/chat or WhatsApp → consent + source capture → identity resolution/deduplication → lead created/updated → queue/owner assigned → SLA starts → qualification → needs/products → quotation → follow-up → won/lost → order/payment → fulfilment/delivery → support/return → feedback/repeat purchase`

1. **Capture:** builder forms, website live chat, WhatsApp, and later approved social/email adapters produce a normalized intake event. Store channel/source, locale, consent basis, and timestamp.
2. **Resolve:** match by normalized email/phone plus human review for ambiguous company/contact duplicates; preserve the original source and never merge records silently.
3. **Route:** assign by queue, region, source, product/service, working hours, skills, and round-robin/fairness policy. Keep assignment history and an SLA clock.
4. **Qualify:** capture need, timeline, budget band, product/category, B2B account, owner, stage, score explanation, and next task. Score must be explainable and editable.
5. **Sell:** customer 360 links interactions, conversations, quotations, orders, and delivery. Quote follow-up is scheduled and logged; won/lost reasons are structured.
6. **Fulfil and support:** track payment/fulfilment/delivery/returns, support case, owner, resolution reason, CSAT, and next-best follow-up.
7. **Measure:** funnel conversion, response time, SLA breach, channel/lead source, quote win rate, order revenue, returns, and retained customers—using approved aggregate views, not arbitrary client-side joins.

### Unified inbox: launch website chat + WhatsApp

The frontend should feel like one inbox even while the connectors are separate:

- Inbox queue, channel filter, assignment state, unread/priority/SLA badges, search, tags, saved replies, and bulk triage.
- Conversation view with customer context, messages, attachments, internal notes, assignment history, tasks, related lead/quote/order, and a safe send composer.
- Website chat: site-level enable/disable and theme position from the builder, pre-chat/consent options, visitor/session identity, transcript, offline form, file restrictions, assignment, typing/presence if available, and CSAT.
- WhatsApp: use the existing WhatsApp-specific tables/functions behind a channel adapter during transition; preserve provider IDs/statuses, template approvals, opt-out/consent, delivery failures, and webhook idempotency.
- Later adapters: Instagram/Facebook direct messages and email after the provider business accounts, app review/permissions, inbound webhook verification, sender rules, and support ownership are confirmed.
- No live provider tokens in browser storage or chat. Secrets stay in server-side secret management/Edge Functions. No real outbound message is sent from demo mode.

### No-code automation studio

A workflow is a versioned, auditable graph with `Trigger → Conditions → Actions → Branch/Wait → End`, a visual test simulator, enable/disable, execution history, and retry/error handling.

**V1 triggers:** form/lead received, first inbound message, conversation unassigned, SLA nearly breached/breached, lead stage changed, quotation created/sent/expiring, order status changed, appointment upcoming, delivery issue, return opened, or approved segment membership.

**Conditions:** consent/opt-out, source/channel, workspace/site, language, region, customer type, product/category, stage/status, value band, assigned team, business hours, and time since last response.

**Actions:** assign queue/owner, add/remove tag, set allowed stage/field, create task/reminder, notify a role/team, send an approved WhatsApp template, send a website chat response from an approved library, request human review, or call an allowlisted server-side integration.

**Safety:** require consent and provider rules for outbound contact; respect quiet hours/frequency caps/opt-outs; restrict loops and execution limits; idempotency keys; bounded retries/dead-letter queue; preview/simulate before enable; human approval for bulk campaigns, discounts outside policy, refunds, payments, or destructive changes; record actor, workflow version, input event ID, actions, and outcome in an audit log. AI may draft/score/summarize, but a human approves customer-facing or financially consequential actions.

---

## 7. Frontend data and database plan

### Data boundary and current truth

- The current 45-table inventory is migration-derived, not an introspected live schema. Existing `customers`, `customer_interactions`, `customer_journey_events`, `quotations`, `orders`, `deliveries`, and WhatsApp-specific tables provide useful starting points.
- Current CRM data does not yet provide a fully normalized provider-agnostic conversation model, workflow execution log, website chat session, or general team/tenant hierarchy. `user_permissions` represents coarse module CRUD flags; the all-industry product needs capability/resource scopes and tenant boundaries.
- Keep existing tables/data and build a typed adapter first. Do not drop/rename/migrate production data until live schema, foreign keys, RLS, triggers, functions, and row counts are reviewed.
- The five-table CMS migration in the local monorepo is a **draft**, has not been applied, and does not by itself implement the full Theme Studio, tenant/site model, workflow system, or unified inbox.

### Proposed logical model (not approved DDL)

| Domain | Logical records | Reuse / gap |
|---|---|---|
| Identity & tenancy | `workspaces`, `workspace_memberships`, `teams`, `team_memberships`, `roles`, `role_permissions`, scoped assignments | Reconcile with `profiles`, `admin_users`, and `user_permissions`. Decide single-WOODEX workspace vs true SaaS tenant isolation before adding `workspace_id` across domain tables. |
| Sites & themes | `sites`, `site_themes`, `theme_revisions`, theme presets, header/footer/menu configuration | Not present in the known schema; store validated token JSON and schema version, not arbitrary CSS. |
| Pages & CMS | `pages`, page document/tree, immutable `page_revisions`, `navigation_menus`, `redirects`, page templates/reusable sections | Existing local draft: pages, page_blocks, page_revisions, navigation, redirects; review serialization/concurrency and extend only after preflight. |
| Media | metadata, ownership, workspace/site path, alt text, rendition/size, tags, licence/source | Reuse `media_assets` and existing buckets only after storage RLS/path policies are reviewed. |
| Customer/CRM | companies/contacts, lead-stage view, source, consent, interactions, journey, tasks, appointments, assignment history | Reuse `customers`, addresses, interactions, journey events, `b2b_*`, appointments; decide if an explicit opportunities/tasks table is needed after workflow mapping. |
| Conversations | channel accounts, conversations, participants, normalized messages, attachments, assignment/history, tags, delivery/webhook events, chat sessions | Keep WhatsApp schema behind an adapter first; migrate/normalize only with verified IDs, policy map, and a data-preserving migration plan. |
| Automation | flow definitions, immutable versions, trigger/condition/action configuration, runs, steps, retry/dead-letter state, idempotency/event outbox | New logical domain; every flow/action is workspace-scoped and permission-checked. |
| Sales & operations | quotations/items/activities, orders/items/status, stock, delivery, returns, payment/invoice references | Reuse the existing 45-table domains only after live schema reconciliation; do not create a second copy of existing business records. |
| Analytics | approved aggregate/read models, daily/event rollups, dashboard preferences/widgets | Reuse `analytics_daily` where correct; never derive authoritative revenue from demo or incomplete client-side rows. |

### Frontend access architecture

```text
Route / screen
  → feature view + typed form/table state
  → query/mutation hooks (stable keys, workspace/site scope)
  → packages/data domain adapter + generated DB types
  → Supabase client (publishable key + signed-in session) OR deterministic mock adapter
  → PostgreSQL grants/RLS, Storage policies, and server-side Edge Functions
```

- **No direct table calls inside presentation components.** Organize repositories/hooks by `auth`, `workspace`, `builder`, `media`, `crm`, `conversations`, `commerce`, `operations`, and `analytics`.
- Generate types from the reviewed target schema; validate external payloads at boundaries. Keep UI view models separate from raw table rows when legacy tables combine concepts such as leads/customers.
- Use server-side pagination/filter/sort for large lists; include workspace, route, filter, locale, and permission-relevant inputs in query keys. Specify safe retry/cancellation, stale state, optimistic mutation boundaries, and Realtime cache invalidation.
- Every screen has loading, empty, error, stale/offline, permission-denied, and partial-data states. Supabase/database errors are logged with correlation IDs and shown without leaking SQL or secrets.
- Demo/mock and live data share domain contracts but cannot be confused. Add a visible “Demo data” indicator and prevent outbound provider actions/payment actions from mock mode.
- Use the publishable/anon key only in the browser. Secret/service-role keys are forbidden in frontend builds, local storage, committed files, and chat. Any privileged operation is server-side and explicitly authorizes the current user.

### Security and preflight gate before any schema change

Before a migration is applied to any Supabase project:

1. Confirm the exact project ref and environment; do not infer it from `.env` or the older project.
2. Obtain the complete safe preflight JSON for schema objects, FKs, constraints/indexes, grants, RLS enablement/policies, views/security-invoker settings, functions/triggers, buckets/storage policies, and migration history. Use the existing exact request in `SUPABASE-SETUP.md`.
3. Compare every draft migration against the live schema and resolve naming/type/ownership conflicts.
4. Produce a permission matrix for anon/authenticated roles and each app role; default deny, grant only needed operations, test `USING` and `WITH CHECK` paths.
5. Test with at least two separate users/workspaces and a signed-out visitor; verify no cross-tenant read/write, no editor bypass of publish, and storage path isolation.
6. Review the migration diff and rollback/back-up plan with the user. Apply only after explicit approval; verify afterward with safe metadata and test records—not customer dumps.

Supabase policies are defense in depth and do not replace server-side authorization for Edge Functions, Storage, Realtime, or webhooks. Storage buckets need their own access policies. Never use a service-role key in a browser.

---

## 8. Role and sign-in hierarchy

### Recommended scope hierarchy

`Platform operator → workspace owner/admin → teams/queues → member → role/capabilities → permitted records/actions`

Use current WOODEX roles as the starting point, then add `support_agent`, `support_supervisor`, `marketer/content_editor`, `analyst`, and `workspace_owner` only where required. Keep management, sales, warehouse, delivery, and accounts. Avoid a single `admin/editor/viewer` role for production workflows.

| Role | Primary access |
|---|---|
| Workspace owner/admin | Workspace/site configuration, invites, role grants, integrations, high-impact approvals, audit; cannot bypass financial audit trails. |
| Management | Cross-module overview, approvals, policy-scoped reporting, high-value quotation/return approvals. |
| Sales | Assigned leads/customers, conversations, quotations, follow-up tasks, order view; cost/margin fields hidden unless explicitly granted. |
| Support agent/supervisor | Assigned queues/conversations, saved replies, SLA/customer history; supervisor can reassign/escalate. |
| Marketer/content editor | Pages, themes, media, forms, campaigns/templates; draft/review rights separated from publish/send rights. |
| Warehouse/delivery | Stock and assigned fulfilment tasks; row-level scope limited to relevant location/order/assignment. |
| Accounts | Invoices, payment records, reconciliation/report views; no product/theme changes. |
| Analyst/viewer | Read-only permitted aggregates and exports; PII columns masked or unavailable unless separately allowed. |

UI hiding is usability only. Route/action authorization, server functions, database grants/RLS, and Storage policies must enforce the same policy. Permissions should be named capabilities (e.g., `page.publish`, `conversation.assign`, `campaign.send`, `order.refund`) rather than relying on generic CRUD flags alone.

### Preview sign-in

For the current mock dashboard preview, use the seeded management demo account:

- **Email:** `management@woodex.pk`
- **Password:** `woodex-demo`

This is mock-only authentication against in-memory seeded data. It is not a Supabase account and must not be reused as a live or production password. The live path will use your own Supabase Auth users/invites and policies after the project is confirmed.

---

## 9. Delivery roadmap and approval gates

No calendar estimates are committed until the target Supabase project, hosting, provider accounts, license choices, and the all-industry backend boundary are confirmed. Each phase ends with a reviewable demonstration and explicit exit criteria.

### Phase 0 — Confirm scope, legal rights, and data/security preflight

- Approve this master plan and the “all-industry UI packs vs production integrations” interpretation.
- Confirm workspace/tenant model, approved Preline use/attribution, and reuse rights for the marketingwoodex reference repo.
- Gather exact safe Supabase preflight (schema/policies/metadata only); no data dump and no migration application.
- Document environments, current hosting, support hours, channel business accounts, and outbound consent requirements.
- **Exit:** a signed-off data/schema/RLS map and permission matrix; no secret key in browser or chat; clear source/license scope.

### Phase 1 — Design system, shell, and reusable application patterns

- Finalize semantic design tokens, density, typography, spacing, light/dark, RTL, responsive behavior, and accessibility baseline.
- Evaluate Preline patterns selectively; implement stable WOODEX wrappers only after licensing review. Keep one chart adapter; chart-library choice remains deferred.
- Establish workspace/site switcher, route/module registry, global search, notification/help/profile menus, shared list/detail/form patterns, and real error/empty/loading states.
- Implement demo-only sign-in usability and role simulation; plan Supabase Auth invitation/MFA flows for after the project gate.
- **Exit:** reviewed shell and component catalogue; same UI states across representative pages; keyboard, mobile, dark, and RTL smoke checks.

### Phase 2 — Theme Studio + Builder canvas (primary differentiator)

- Define versioned theme/site/page document contracts before extending migration draft.
- Build Theme Studio controls for global tokens and site chrome; save/preview/compare/restore theme revisions.
- Build true pointer and keyboard drag-and-drop, nested outline, searchable block library, inspector, device/RTL preview, undo/redo, recoverable drafts, and reusable templates/sections.
- Compare native editor with an alternative only if needed; keep typed blocks and safe renderer as acceptance gates.
- **Exit:** a non-developer builds and previews a branded landing page without code; changing a global token updates inheriting blocks consistently; no arbitrary code execution.

### Phase 3 — CMS workflow, publishing, media, and safe dynamic data

- Finalize page/theme/revision/navigation/media contracts against the safe preflight; produce migration + RLS tests but do not apply until approval.
- Implement review/publish/rollback/preview links, SEO/accessibility validation, menu/header/footer editor, redirects, media library, and approved typed bindings.
- Connect demo mode first; connect live mode only after schema/RLS review and migration approval.
- **Exit:** draft→review→publish→rollback works for authorized roles; editor and published storefront use the same renderer; public visitors can read only published content.

### Phase 4 — CRM customer 360 and end-to-end WOODEX lifecycle

- Normalize the frontend model over existing customer/interactions/quotation/order/fulfillment tables; add only missing workflow records after schema review.
- Build intake dedupe, ownership/queues, stage/pipeline, tasks/appointments, customer timeline, quote follow-up, order/fulfilment/support linkage, and reporting.
- **Exit:** a synthetic lead created by a builder form can be assigned, qualified, quoted, converted to an order, and followed through delivery/after-sales with audit events.

### Phase 5 — Website live chat + WhatsApp unified inbox and automation

- Add the provider-neutral conversation view, website chat widget/settings, WhatsApp adapter, assignment/SLA, saved replies, consent/opt-out, message status, and human takeover.
- Add visual automation editor, simulator, approvals, execution logs, retries, idempotency, quiet hours, rate limits, and emergency disable.
- Use sandbox/test accounts only until provider rules and production credentials are approved.
- **Exit:** an inbound synthetic website chat and test WhatsApp event resolve to one customer timeline; assignment, SLA, reply, and automation audit are verified; demo mode cannot send a real message.

### Phase 6 — Industry starter packs and broad ready-page library

- Ship shared manifests, navigation/page templates, mock dashboards, component examples, and typed data contracts for all agreed verticals.
- Mark each page pack as `template-only`, `synthetic-demo`, or `live-connected`; do not imply full backend or compliance where absent.
- Add additional live vertical backends only after separate domain, security, privacy, and compliance sign-off.
- **Exit:** each named pack renders from shared components and theme tokens, works responsively, and exposes its actual data mode/scope.

### Phase 7 — Later channels and advanced capabilities

- Add Instagram/Facebook DMs and email after account/app review, webhook verification, consent, and support ownership are ready.
- Consider campaign journeys, deeper analytics, AI summaries/drafts, file manager enhancements, calendar integrations, and additional vertical data connectors.
- Keep AI assistive and human-reviewed; no unattended financial or mass-messaging action.

---

## 10. Acceptance criteria for the master dashboard

### UI/UX

- Shared design tokens, reusable components, clear typography/spacing hierarchy, responsive layout, light/dark, and tested RTL.
- Every ready page includes loading/empty/error/permission-denied/stale states and usable keyboard/focus behavior.
- Tables support server-side sorting/filtering/pagination and accessible row/bulk actions; forms have validation, dirty-state protection, and clear errors.
- Calendar, Kanban, chat, file, billing, chart, navigation, overlay, and advanced-form patterns are accessible and share the design system.
- No page silently claims live data when it is demo/mock or partial.

### Builder

- No-code editor includes actual drag/drop and keyboard reorder, hierarchy/outlining, reusable templates/global sections, global theme controls, preview across device sizes and direction, undo/redo, save/recovery, revisions, review/publish, and rollback.
- Preview and published output share the same typed renderer and schema version.
- Theme changes propagate to inherited blocks/pages; block-level overrides are visible and can be reset to global.
- Validation blocks unsafe content and broken required bindings; normal page creation never needs custom code.
- Non-developer usability test: create and publish a templated landing page with product/lead blocks and theme adjustments without developer intervention.

### CRM, channels, and automation

- End-to-end lead-to-after-sales path is observable and role-scoped.
- Website chat and WhatsApp share a customer/conversation timeline while retaining provider/channel metadata.
- Consent, opt-out, assignment, SLA, retries, idempotency, message status, workflow version, and audit actor are inspectable.
- High-impact actions and bulk sends require explicit permission/approval; demo-mode actions cannot reach external providers.

### Data and security

- Schema is regenerated from the approved target project, and migrations are reviewed/tested before application.
- Every exposed table/storage operation has deliberate grants and RLS; tests prove cross-user/workspace denials and valid-role paths.
- Service-role credentials stay server-side; frontend includes publishable key only; secrets never enter repository, browser storage, UI, or chat.
- No reference-repository content/customer records/secrets are imported into WOODEX.

---

## 11. Risks, open decisions, and recommended defaults

| Risk / decision | Recommended default | Gate |
|---|---|---|
| “All industries” could be interpreted as full backend parity | Ship common shell + page/dashboard template packs for all; WOODEX production model first | Confirm before implementation estimates. |
| Single tenant vs SaaS tenant isolation | Preserve workspace/site boundary in contracts; do not add `workspace_id` to all legacy tables until live schema review and deployment model are approved | Phase 0. |
| Preline Fair Use / page-builder derivative | No copied code/Pro assets until legal clearance; if uncertain, implement original wrappers using tokenized patterns | Before adding dependency/source. |
| Reference repo has no license metadata | No code/assets/content/data reuse until ownership and license/permission are confirmed | Before any reuse. |
| Page-builder migration differs from live Supabase | Keep draft; compare complete preflight JSON, policies, functions/triggers, and table types first | No migration application before explicit user approval. |
| WhatsApp/social provider conditions | Website chat + WhatsApp first; use sandbox; provider approvals and consent define launch readiness | Before sending production messages. |
| Broad module list risks inconsistent quality | Build shared component/page patterns and vertical manifests; do not fork the shell per industry | Every phase. |
| Drag/drop editor may create unsaved or invalid trees | Accessible move/reorder commands, schema validation, transaction-like save, recovery, immutable revisions | Builder phase. |
| Analytics can misstate finance | Explicit metric definitions, time zone/currency rules, source freshness, sample/live labels | Before KPI release. |
| Personal/financial/health data exposure | Minimize fields, mask by role, retention/export audit, no regulated claims without review | Before live connectors. |

### Remaining decisions to ask after you review this plan

1. Confirm that the v1 all-industry promise means **ready UI/page packs for every listed vertical, with WOODEX as the first live data integration**, rather than full live backend parity for every industry.
2. Confirm the workspace model: one WOODEX workspace initially with a future tenant boundary, or a multi-customer SaaS tenant model from day one.
3. Confirm whether the marketingwoodex repo is yours/cleared for source reuse; its public repo metadata does not declare a license. Until confirmed, reuse no source/content/assets.
4. When ready for live Supabase, provide the project-specific safe preflight requested in `SUPABASE-SETUP.md`; never send service-role keys or personal access tokens in chat.

---

## 12. Research links

- [Preline UI repository](https://github.com/htmlstreamofficial/preline) and [official license](https://preline.co/docs/license.html)
- [Preline React + Vite integration guide](https://preline.co/docs/guides/react-vite.html), [themes](https://preline.co/docs/themes.html), [accessibility guidance](https://preline.co/docs/accessibility.html), and [changelog](https://preline.co/docs/changelog.html)
- [Elementor Site Settings](https://elementor.com/help/site-settings/) and [Elementor Site Settings panel for developers](https://developers.elementor.com/docs/editor/site-settings-panel.html)
- [Supabase Row Level Security](https://supabase.com/docs/guides/database/postgres/row-level-security), [secure data](https://supabase.com/docs/guides/database/secure-data), and [Storage access control](https://supabase.com/docs/guides/storage/security/access-control)
- [GrapesJS Storage Manager](https://grapesjs.com/docs/modules/Storage.html) (research comparator only)
- User-supplied WOODEX interior reference: [marketingwoodex branch](https://github.com/marketingwoodex-cloud/-marketingwoodex/tree/arena/01a0ec23-marketingwoodex) — no code/content/data reused in this plan.

---

## Approval request

Please review this plan and reply **“approve plan”** or tell me the sections you want changed. I will not start dashboard implementation, update the CMS migration, or connect to live Supabase until you approve the plan and the remaining scope gates above are resolved.

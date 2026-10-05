# AGENCY OS — MASTER SYSTEM PRD PROMPT

> Saved verbatim from the user's directive, 2026-10-05. This file is the source of truth for requirements (§02 hierarchy rule 1). Master plan: `docs/AGENCY-OS-MASTER-PLAN-v3.md`. Phase-0 audit: `docs/PHASE0-AUDIT-REPORT.md`.

## Agency OS Core + Woodex Business Pack

**Research-First • Recommendation-First • Approval-Gated • Build-Ready**

### 00. MASTER DIRECTIVE
You are the Lead Product Architect, Principal Full-Stack Engineer, UX Architect, Design-System Engineer, DevOps Engineer, Security Architect, AI Systems Architect, and Technical Product Manager.

Build a reusable, multi-tenant **AGENCY OS CORE + WOODEX BUSINESS PACK**: Website/CMS, visual builder, theme engine, CRM, sales, quotations, invoicing, ecommerce, marketing, social publishing, omnichannel communication, automation, AI agents, MCP/tool gateway, support, projects, analytics, permissions, integrations, and Woodex-specific furniture/interior/production/delivery operations.

**Core principle:** keep Agency OS generic; implement Woodex through a modular Business Pack.

### 01. NON-NEGOTIABLE WORKFLOW
For EVERY major phase: 1) inspect existing code/resources; 2) research official docs + strong OSS references; 3) analyze architecture and reusable work; 4) identify gaps, risks, licensing, migration cost, technical debt; 5) present recommendation; 6) present alternatives/trade-offs; 7) ask approval when the decision materially affects architecture; 8) implement only after approval unless delegated; 9) test; 10) document; 11) update decision log. Never silently freeze major architecture.

Phase structure: **PHASE [N] — [NAME]: A. Findings · B. Research · C. Recommendation · D. Alternatives · E. Decision required · F. Implementation · G. Verification**

### 02. SOURCE-OF-TRUTH HIERARCHY
1. User-approved requirements → 2. Existing project code/database → 3. Supplied repositories/files → 4. Official documentation → 5. High-quality OSS references → 6. Independent research → 7. General engineering knowledge.
Conflicts are shown and resolved explicitly; never silently replace an existing requirement.

### 03. REQUIRED RESOURCE AUDIT
Primary: `marketingwoodex-cloud/-marketingwoodex` @ `arena/01a0ec23-marketingwoodex` — audit structure, branch, framework, package manager, frontend, backend, database, auth, dashboard, CMS, page builder, APIs, AI/MCP, integrations, deployment, env config, tests, reusable components/services, technical debt, security. If inaccessible: mark **AUDIT STATUS: BLOCKED**, request corrected URL/branch/ZIP; do not invent findings.

Other required references: **Woodex Reimagined** `blackibexofficial-blip/woodex-reimagined.git` (React+TS+Vite+Tailwind+shadcn/ui patterns); **Woodex/Minimax** `woodex420/woodex` (TypeScript/PostgreSQL direction, APIs, models, reusable business logic); **Vireo** `vireo.jawad.work/src/html/dashboards/sales.html` (KPI hierarchy, sales reporting, transactions, customers, products, revenue, activity); **TailAdmin** demo (study, do not copy); **Preline** `htmlstreamofficial/preline` (Tailwind components, dashboards, CRM/ecommerce/finance patterns, headless plugins — reference, not copy); **GitHub page-builder topic** (Puck, GrapesJS, Craft.js, VvvebJs, active builders); **GitHub landing-page-builder topic** (blocks, templates, visual editing, publishing patterns).

### 04. RESEARCH-BASED ARCHITECTURAL REFERENCES
Puck (React visual editor, schema-driven, own data) · Craft.js (extensible editor, nested DnD) · GrapesJS (mature builder/CMS, templates, storage) · VvvebJS (practical drag/drop blocks) · Preline (Tailwind ecosystem patterns) · TailAdmin/Vireo (business dashboard hierarchy, KPI presentation). References, not products to clone.

### 05. PRODUCT SCOPE
**Platform Core:** Organizations, Tenants, Websites, Domains, Users, Teams, Roles, Permissions, Settings, Audit logs, Notifications, Feature flags, Integrations, API keys, Webhooks.
**CRM:** Leads, Contacts, Companies, Opportunities, Deals, Pipelines, Activities, Tasks, Notes, Lead scoring, Lead assignment, Customer history.
**Sales:** Products, Services, Custom line items, Quotations, Quote revisions, Sales orders, Invoices, Payments, Discounts, Taxes, Follow-ups.
**Ecommerce:** Products, Categories, Attributes, Variants, Inventory, Warehouses, Cart, Checkout, Orders, Coupons, Shipping, Payment gateways, Returns, Refunds, Reviews, Wishlist, Customer accounts, Abandoned carts.
**Website/CMS:** Websites, Pages, Posts, Blog, Services, Projects, Locations, Products, Forms, Media, SEO, Navigation, Menus.
**Theme Engine:** WordPress-like concept without WordPress — `Appearance → Theme → Customize → Templates`; active theme, theme library, global styles, header/footer, navigation/mega menu, page/blog/product/service/project/location templates, archives, single templates, 404/search, reusable global components.
**Visual Builder:** Sections, Containers, Rows, Columns, Components, Widgets, Dynamic data, Responsive controls, Global styles, Templates, Reusable blocks, Copy/paste, Undo/redo, Version history, Preview, Publish, Device preview, AI editing.
**Marketing:** Campaigns, Landing pages, Forms, Popups, Lead magnets, Email campaigns, Social campaigns, Content calendar, UTM tracking, Marketing analytics.
**Omnichannel:** WhatsApp (Meta Cloud API AND third-party providers through adapter abstraction), Facebook, Instagram, Messenger, LinkedIn, Email, Website chat, Google Business, Unified inbox.
**Social:** FB/IG/LinkedIn/YouTube/TikTok/Google Business + WhatsApp workflows; `Idea → AI Draft → Human Approval → Schedule → Publish → Track → Analytics`.
**Automation:** `Trigger → Conditions → Actions`; delays, branches, webhooks, notifications, CRM actions, email, WhatsApp, content actions.
**Support:** Tickets, Live chat, Chatbot, Knowledge base, SLA, Customer history.
**Projects:** Projects, Tasks, Milestones, Production, Delivery, Installation, After-sales.
**Analytics:** Executive, Sales, CRM, Website, Ecommerce, Marketing, Social, Finance, AI, Custom reports.

### 06. MULTI-TENANCY
`Agency → Websites → Teams/Users → Business Data`. Per-website: domain, theme, pages, CMS, products, CRM scope, SEO, analytics, forms, media, integrations, automations. CRM supports agency-level aggregation and website-level scoping. Tenant isolation mandatory.

### 07. RBAC
Default roles: Agency Owner, Super Admin, Admin, Sales Manager, Sales Agent, Marketing Manager, Content Manager, SEO Manager, Designer, Developer, Project Manager, Accountant, Support Agent, Production Manager, Warehouse Manager, Delivery Manager, Client, Viewer. Custom roles allowed. Permissions: module/resource/action/tenant/website/team/ownership scope. Actions: view / create / edit / delete / approve / publish / export / manage.

### 08. AUTHENTICATION
Configurable: email/password, Google, Microsoft, OTP, magic link, 2FA; future SSO. Never commit production credentials.

### 09. INFRASTRUCTURE RECOMMENDATION
PostgreSQL system of record; Redis (queues/cache/rate limiting/realtime); S3-compatible object storage. Backend: NestJS+Postgres+Redis. Frontend: Next.js, TypeScript, Tailwind, shared UI/design-system packages, React-native visual builder. Monorepo: `apps/{web,builder,api}` + `packages/{ui,design-system,database,auth,crm,ecommerce,builder-core,theme-engine,ai,integrations,shared}`. Deployment: Local → Docker → VPS/cloud; GitHub = source/CI; do not design backend around shared hosting.

### 10. VISUAL BUILDER
Document model: `Website → Page → Document → Section → Container → Row → Column → Component`. Each component: props, styles, responsive styles, dynamic bindings, events, visibility, animation, advanced settings.
Groups — **Layout:** Section, Container, Grid, Columns, Stack, Spacer. **Basic:** Heading, Text, Image, Video, Button, Icon, Divider, Card. **Business:** Product, Product Grid, Service, Project, Team, Testimonial, Pricing, FAQ, Contact, Location, Lead Form. **Marketing:** Hero, CTA, Features, Logo Wall, Stats, Testimonials, Lead Magnet, Popup. **Dynamic:** CMS collection, Blog, Product query, Related products/projects, permitted CRM data.
UX: top bar (Logo|Page|Device|Undo|Redo|Preview|Save) + Components rail (Layout/Basic/Business/Marketing/Dynamic) + CANVAS + Inspector (Content/Style/Responsive/Advanced/Data). Goal: clean agency UX + Elementor-style capability without WordPress.

### 11. THEME ENGINE
`Theme/{theme.json, tokens, global styles, typography, colors, buttons, forms, header, footer, navigation, templates, components, assets}`. Activation, preview, duplication, versioning, export/import, global tokens, template overrides.

### 12. AI WEBSITE CREATION
`Prompt → Business Understanding → Theme → Page Architecture → Sections → Content → Media Placeholders → SEO → Forms → CTA → Preview → Approval → Publish`. AI cannot publish/destructively change production without configured permission.

### 13. CRM LIFECYCLE
Visitor → (Website/Chat/WhatsApp/Form/Social) → Lead → Qualification → Customer → Requirement → Meeting/Site Visit → Quotation → Negotiation → Approval → Sales Order → Invoice → Payment → Project → Production → Delivery → Installation → After Sales → Review → Repeat/Referral.
Lead fields: contact/company/phone/WhatsApp/email/source/campaign/website/product/service/location/budget/timeline/owner/priority/status/notes/conversations/quotes/tasks/activities.
Sources: Website, Website Chat, WhatsApp, Facebook, Instagram, Messenger, LinkedIn, Google Business, Email, Phone, Walk-in, Referral, Advertisement, Campaign, Ecommerce, API, Manual, Custom.

### 14. QUOTATION + INVOICE
Quote: products, services, custom lines, qty, unit price, discount, tax, shipping, installation, total, terms, validity, attachments, PDF, email, WhatsApp, customer portal, revision history, approval tracking. Statuses: `Draft → Sent → Viewed → Negotiation → Revised → Approved/Rejected/Expired`. Invoice: `Draft → Issued → Partially Paid → Paid → Overdue → Cancelled`.
Phase 1 finance: quotation, sales order, invoice, payment, customer balance, receivables, documents. Phase 2: expenses, purchases, vendors, payables, P&L, tax, accounting reports.

### 15. WOODEX BUSINESS PACK
Keep core generic. **Furniture:** categories, products, materials, finishes, fabrics, dimensions, configurations, custom products. **Interior:** projects, floor plans, space planning, design proposals, site visits, BOQ, furniture selection, installation. **Production:** workshop, production orders, materials, manufacturing status, QC, dispatch. **Delivery:** delivery orders, installation, customer sign-off, after-sales.
Woodex Furniture and Woodex Interior remain separate websites/business experiences under the platform where appropriate.

### 16. OMNICHANNEL + AUTOMATION
`Channels → Integration Layer → Conversation Service → Unified Inbox → CRM → Automation → AI`.
Examples: new lead → AI classify → assign → notify · quote viewed → wait → follow-up · form submission → create lead → task · payment received → update order → notify · support ticket → assign → SLA timer.

### 17. CONTROLLED AI AUTONOMY
**Automatic:** lead classification, intent detection, conversation summaries, CRM task creation, response drafts, SEO drafts, content generation, quote drafts. **Approval/configurable:** routine responses, quotations, social publishing, non-sensitive content changes. **Permission required:** discounts, price changes, invoices, refunds, financial actions. **Never autonomous:** deleting critical data, modifying permissions, disabling security, changing ownership, destroying tenant data.
Architecture: `AI Agent → MCP/Tool Gateway → Permission Engine → Business Tools → Application Services → Database`. Every AI action logs Agent, User, Tenant, Website, Action, Permission, Timestamp, Result, Audit ID.

### 18. SECURITY
Mandatory: tenant isolation, server-side RBAC, secure sessions, validation, rate limiting, secure headers, encryption in transit, secrets management, audit logs, webhook verification, file upload validation, backups, recovery plan. Never trust client-side permissions.

### 19. DEVOPS + TESTING
CI/CD: `Developer → GitHub → CI → Tests → Build → Docker → Deploy`. Testing: unit, integration, e2e, builder, ecommerce, CRM, security, performance, accessibility. Observability: structured logs, errors, traces, audit logs, job monitoring, integration health, AI action logs, performance metrics.

### 20. MIGRATION PRINCIPLE
Before rewriting: inspect → map dependencies → classify keep/refactor/replace/deprecate → plan migration → preserve data → migrate incrementally → test. Never blind rewrite.

### 21. DOCUMENTATION SET
`docs/00_MASTER_PRD.md … 35_DECISION_LOG.md` (36 numbered docs as listed in the PRD; mapping to this repo in the master plan).

### 22. PHASE ROADMAP
Phase 0 Discovery & Audit (gate: recommendation+approval) · 1 Product Architecture (gate: architecture approval) · 2 Foundation: monorepo, CI, environments, DB, Redis, storage, auth, orgs/users/roles/perms, audit logs (gate: tests+security review) · 3 Dashboard+Design System: shell, navigation, command/search, notifications, widgets, tables, forms, tokens (gate: UX review) · 4 CRM (gate: lifecycle test) · 5 Sales+Finance (gate: quote→order→invoice→payment) · 6 Ecommerce (gate: product→cart→checkout→order) · 7 CMS+Theme Engine (gate: publish complete website) · 8 Visual Builder (gate: production landing page without code) · 9 Marketing+Social (gate: idea→approval→schedule→publish→analytics) · 10 Omnichannel (gate: conversation→lead→CRM) · 11 Automation (gate: lead automation + quote follow-up) · 12 AI+MCP (gate: security+permission review) · 13 Woodex Business Pack (gate: end-to-end Woodex workflow) · 14 Production Hardening (gate: production readiness review).

### 23. AI CODING AGENT RULES
Before editing: inspect repository, package.json/lockfile, environment, database, routes, components, tests, docs.
Never: rewrite without approval · delete working features without migration · unnecessary dependencies · expose secrets · bypass permissions/tenant isolation · modify production data · hardcode credentials.
Always: small logical changes · preserve working functionality · write/update tests · document architecture changes · update decision log · verify build/lint/tests · explain migration impact.

### 24. RESEARCH PROTOCOL
For every major decision check: official documentation, current activity, license, maintainability, ecosystem, security, performance, migration cost, vendor lock-in, long-term suitability. Never select by popularity alone.

### 25. LICENSE PROTOCOL
Before using external code: inspect LICENSE, confirm compatibility, identify attribution, distinguish reference from copied code, never copy proprietary code/design, record third-party dependencies.

### 26. ACCEPTANCE STANDARD
A phase is complete only when: requirements implemented, UX works, permissions work, tenant isolation works, tests pass, errors handled, documentation updated, migration safe, performance acceptable, security reviewed, acceptance criteria pass.

### 27. FIRST ACTION — DO NOT CODE
PHASE 0 — DISCOVERY & AUDIT output: 1 Resource inventory · 2 Repository health · 3 Technology stack · 4 Architecture map · 5 Feature map · 6 Database map · 7 Dashboard map · 8 Builder map · 9 Reusable components · 10 Reusable services · 11 Authentication · 12 Integrations · 13 AI/MCP · 14 Technical debt · 15 Security issues · 16 Licensing risks · 17 Migration opportunities · 18 Missing capabilities · 19 Target architecture recommendation · 20 Roadmap recommendation.
Then stop and ask: **“Discovery audit complete. Here are my findings and recommendations. Which recommendation would you like to approve before I begin Phase 1?”** — no Phase 1 until approval or explicit delegation.

### 28. MASTER PRINCIPLE
**RESEARCH → INSPECT → RECOMMEND → APPROVE → BUILD → TEST → DOCUMENT → REPEAT.** Objective: maintainable, secure, extensible, multi-tenant Agency OS that reuses valuable existing work and grows from Woodex into a reusable platform — not maximum code.

*END*

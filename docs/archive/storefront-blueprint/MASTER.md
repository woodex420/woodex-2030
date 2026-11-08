# WOODEX — Master Website Blueprint

> **Purpose:** Single source of truth to redevelop and redesign the WOODEX platform (frontend, backend, database, APIs, integrations). This document consolidates all 32 phase docs under `/docs` into one build-ready specification.
>
> **Status legend:** `[current]` shipped · `[planned]` next phase · `[draft — confirm]` brand-owner validation needed.

---

## 0. Executive Summary

**WOODEX** is Lahore-based premium furniture manufacturer serving Pakistan's B2B fitout market (60% revenue), B2C home/office retail (25%), and custom manufacturing (15%). The web platform is HON-inspired corporate minimalism, PKR-priced, WhatsApp-first, and structured around a dual **e-Quotation (B2B)** and **e-Commerce cart (B2C)** flow.

**Redevelopment goals**
1. Migrate from localStorage prototype → Lovable Cloud (Postgres + Auth + Storage + Edge Functions).
2. Ship a full admin dashboard (CRM, quotes, orders, products, blog, analytics).
3. Automate WhatsApp + email + PDF quote pipeline.
4. Reach Lighthouse SEO ≥ 90, LCP < 2.5s, top-3 rank for "office furniture Lahore".

---

## 1. Master Website Architecture

Multi-tier separation of concerns for performance, SEO, and maintainability.

```text
┌──────────────────────────────────────────────────────────────┐
│  PRESENTATION TIER  (React 18 + Vite + TS + Tailwind + shadcn)│
│  • Public site (SSR-lite via prerender)                       │
│  • Admin dashboard (SPA, auth-gated)                          │
└──────────────────────────────────────────────────────────────┘
                          │  HTTPS / JSON
┌──────────────────────────────────────────────────────────────┐
│  APPLICATION TIER  (Lovable Cloud — Supabase)                 │
│  • PostgREST auto-API (public reads)                          │
│  • Edge Functions (Deno) — quotes, orders, leads, webhooks    │
│  • Auth (email + magic link + role-based)                     │
│  • Storage buckets (product-images, quote-pdfs, project-media)│
└──────────────────────────────────────────────────────────────┘
                          │  SQL
┌──────────────────────────────────────────────────────────────┐
│  DATA TIER  (Postgres 15 + RLS)                               │
│  • Catalog · Series · Projects · Blog · Services              │
│  • Leads · Quotes · Orders · Users · Roles                    │
└──────────────────────────────────────────────────────────────┘
                          │
┌──────────────────────────────────────────────────────────────┐
│  INTEGRATIONS                                                 │
│  WhatsApp Cloud API · Resend/SendGrid · @react-pdf/renderer   │
│  Google Analytics 4 · Google Search Console · Meta Pixel      │
└──────────────────────────────────────────────────────────────┘
```

**Tier boundaries**
- Presentation never talks to DB directly — always via PostgREST or Edge Function.
- All write paths route through Edge Functions to enforce validation, rate limits, and side-effects (WhatsApp, email, PDF).
- Static assets (product images) served from Supabase Storage CDN.

Full technical detail: [`04-development/`](./04-development/).

---

## 2. Web Page Mapping & Navigation Structure

### 2.1 Sitemap (extracted from `src/App.tsx`)

```text
/
├── /shop  ──────────── /shop/:productId
├── /room-packages
├── /virtual-showroom
├── /series ──────────── /series/:seriesId
├── /projects ────────── /projects/:projectId
├── /services ────────── /services/:slug   (7 sub-pages)
├── /custom-design
├── /b2b
├── /quotation
├── /showrooms
├── /materials
├── /warranty
├── /about
├── /contact
├── /blog ────────────── /blog/:postId
├── /checkout                              (noindex)
└── /*  NotFound                           (noindex)
```

### 2.2 Global Navigation

```text
Utility Bar   Find Showroom | Warranty | Track Order | +92-XXX | WhatsApp
Primary Nav   Products ▾ | Series ▾ | Solutions ▾ | Services ▾ | Custom Design | About | Contact
Right Rail    Search | Quote Basket | Cart
```

**Mega-menus**
- **Products** → Office (Workstations, Executive, Conference, Reception, Storage, Ergonomic Chairs) + Home (Bedroom, Living, Dining, Home Office, Kids, Outdoor).
- **Series** → Ek · Infinity · Kraft · Legacy · Modo.
- **Solutions** → Corporate · Startups · Hospitality · Education · Healthcare · Government.
- **Services** → Custom Design · B2B Solutions · Custom Manufacturing · Delivery & Installation · Space Planning · After-Sales · Project Management.

### 2.3 Journey Maps

| Journey | Path |
|---|---|
| B2B | Home → Solutions → Virtual Showroom → Add to Quote → Basket → Submit → PDF + WhatsApp → Sales follow-up |
| B2C | Home → Shop / Series → PDP → Cart → Checkout (COD / Bank) → WhatsApp confirmation |
| Designer | Home → Materials → Custom Design → Quotation |

Full IA: [`02-documentation/information-architecture.md`](./02-documentation/information-architecture.md).

---

## 3. Targeted SEO & Content Strategy

### 3.1 Keyword Clusters `[draft — confirm]`

| Cluster | Primary keywords | Target pages |
|---|---|---|
| Office (Lahore) | office furniture Lahore, executive office chairs Pakistan, modular workstations Pakistan, boardroom table Lahore | `/shop?cat=office`, `/series/*`, `/b2b` |
| Custom / Fitout | custom office furniture Pakistan, office fitout Lahore, turnkey interior Lahore | `/custom-design`, `/services/*`, `/b2b` |
| Home | home office furniture Pakistan, custom bedroom furniture Lahore, dining table Lahore | `/shop?cat=home`, `/room-packages` |
| Brand | woodex furniture, woodex Lahore | `/`, `/about` |

### 3.2 On-page Rules
- Title ≤ 60 chars, includes primary keyword + brand.
- Meta description ≤ 160 chars with CTA.
- One H1 per page; strict H2/H3 hierarchy.
- Canonical on every page; hreflang added when Urdu variants launch.
- OG (`og:title/description/type/image`) + Twitter `summary_large_image`.

### 3.3 Structured Data
| Schema | Location | Status |
|---|---|---|
| `Organization` + `LocalBusiness` | `index.html`, `/showrooms` | `[planned]` |
| `WebSite` + `SearchAction` | `index.html` | `[planned]` |
| `BreadcrumbList` | every non-home page | `[planned]` |
| `Product` | PDPs | `[planned]` |
| `CollectionPage` | Shop, Series | `[current]` |
| `FAQPage` | category, service, series | `[current]` |
| `Article` | blog posts | `[planned]` |

### 3.4 Content Assets
- `src/data/seoContent.ts` — 18 categories + 5 series copy.
- `src/data/services.ts` — 7 service pages.
- `src/data/blogPosts.ts` — editorial hub.
- `SeoContentBlock.tsx` — injects meta + JSON-LD per page.

### 3.5 Off-page & Technical
- `public/robots.txt` — allow all; disallow `/checkout`.
- `public/sitemap.xml` — generated at build via `scripts/generate-sitemap.ts` `[planned]`.
- GA4 + Search Console + Meta Pixel via `<script>` in `index.html`.
- Core Web Vitals targets: **LCP < 2.5s · INP < 200ms · CLS < 0.1**.

Full plan: [`04-development/seo.md`](./04-development/seo.md) · [`06-launch/seo-checklist.md`](./06-launch/seo-checklist.md).

---

## 4. Product Data & Catalog Mapping

### 4.1 Taxonomy

```text
Catalog
├── Office
│   ├── Workstations · Executive Desks · Conference · Reception · Storage · Ergonomic Chairs
├── Home
│   ├── Bedroom · Living · Dining · Home Office · Kids · Outdoor
└── Series (cross-cuts categories)
    └── Ek · Infinity · Kraft · Legacy · Modo
```

### 4.2 `products` Schema

| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `slug` | text unique | URL segment |
| `title` | text | H1 on PDP |
| `category` | text | office / home / accessory |
| `subcategory` | text | workstation, bedroom, … |
| `series_id` | uuid FK → series | nullable |
| `description` | text | long-form |
| `price_pkr` | numeric | base price |
| `dimensions` | jsonb | `{w,h,d,unit}` |
| `materials` | text[] | wood, metal, upholstery |
| `colors` | jsonb | `[{name, hex, swatch_url}]` |
| `images` | text[] | Storage URLs |
| `variants` | jsonb | SKU matrix |
| `stock` | int | manual v1 |
| `is_published` | bool | |
| `seo_title` / `seo_description` | text | override defaults |
| `created_at` / `updated_at` | timestamptz | |

### 4.3 Variant Matrix
Each variant row: `{sku, color, size, price_override, stock, image_override}`. Auto-generated from selected option axes in admin.

### 4.4 Content-per-Product Requirements
- 5–8 photos (hero + detail + in-context).
- 3 short bullets (materials, dimensions, warranty).
- Long description (≥ 150 words, keyword-rich).
- 4–6 FAQ entries.
- Related products (same series + same subcategory).

### 4.5 Series & Projects
- **Series:** `id, slug, title, tagline, story, hero_image, gallery[], is_published`.
- **Projects:** `id, slug, title, sector, location, area_sqft, challenge, solution, result, gallery[], products[], published_at`.

Full CMS spec: [`04-development/cms-structure.md`](./04-development/cms-structure.md).

---

## 5. Integrations & API Architecture

### 5.1 Public Reads (PostgREST, auto)

```http
GET /rest/v1/products?is_published=eq.true&category=eq.office
GET /rest/v1/series?is_published=eq.true
GET /rest/v1/projects?is_published=eq.true
GET /rest/v1/blog_posts?is_published=eq.true&order=published_at.desc
```

### 5.2 Edge Functions (writes + side-effects)

| Function | Purpose | Side-effects |
|---|---|---|
| `POST /functions/v1/submit-lead` | Insert `leads` row | WhatsApp + email to sales |
| `POST /functions/v1/submit-quote` | Build quote, calc 75% advance | Generate PDF → Storage → WhatsApp + email |
| `POST /functions/v1/submit-order` | B2C order (COD / Bank) | WhatsApp confirmation to customer + sales |
| `POST /functions/v1/newsletter-subscribe` | Add to list | Welcome email |
| `POST /functions/v1/whatsapp-webhook` | Inbound WhatsApp events | Update lead/quote/order |
| `POST /functions/v1/admin-mutate` | Auth-gated CRUD | Guarded by `has_role('admin')` |

**Envelope**
```json
{ "data": { ... }, "error": null }
{ "data": null, "error": { "code": "RATE_LIMIT", "message": "…" } }
```

Rate limit: 5 req/min per IP on public write endpoints.

### 5.3 Third-party Integrations

| Service | Use | Secret |
|---|---|---|
| **WhatsApp Cloud API** | Outbound quotes/orders, inbound webhook | `WHATSAPP_TOKEN`, `WHATSAPP_PHONE_ID` |
| **Resend** (preferred) or SendGrid | Transactional email | `RESEND_API_KEY` |
| **@react-pdf/renderer** | PDF quotes (in-function) | — |
| **Google Analytics 4** | Traffic + funnel | `GA4_ID` (public) |
| **Google Search Console** | Indexing, queries | verified via DNS |
| **Meta Pixel** | Retargeting | `META_PIXEL_ID` (public) |

Full API contract: [`04-development/api.md`](./04-development/api.md).

---

## 6. Technical Architecture

### 6.1 Frontend
- **Framework:** React 18 + Vite 5 + TypeScript 5.
- **Styling:** Tailwind v3 with semantic tokens in `src/index.css`; no hardcoded colors in components.
- **UI kit:** shadcn/ui primitives + custom (`SeoContentBlock`, `CartDrawer`, `QuoteBasket`, `RoomConfigurator`, `PrintableInvoice`).
- **State:** React Context (`QuoteContext`, `CartContext`) + TanStack Query for server data.
- **Routing:** `react-router-dom` v6; lazy-load Services, VirtualShowroom, Blog `[planned]`.
- **Forms:** `react-hook-form` + `zod` schemas.

### 6.2 Backend (Lovable Cloud)
- **Runtime:** Deno edge functions.
- **DB:** Postgres 15 with RLS on every user-facing table.
- **Roles:** `admin`, `sales`, `editor` in `user_roles` table; access via `has_role(auth.uid(), role)` security-definer.
- **Storage buckets:** `product-images` (public), `quote-pdfs` (signed URL), `project-media` (public).

### 6.3 Data Model Overview

```text
auth.users ──< user_roles
                 │
products ─┐    leads ──< quotes ──> quote_items
series ───┼─< projects_products
projects ─┘
blog_posts     orders ──< order_items
services       newsletter_subscribers
```

RLS sketch:
- Public: `SELECT` where `is_published = true` on catalog tables.
- Anon: `INSERT` on `leads`, `quotes`, `orders` (via Edge Function only).
- Admin: full CRUD via `has_role`.

Full schema + RLS: [`04-development/cms-structure.md`](./04-development/cms-structure.md).

### 6.4 Environments & Deployment
- **Preview:** every Lovable branch auto-preview.
- **Production:** Lovable publish → custom domain (`woodex.pk` `[draft]`).
- **Secrets:** managed via Lovable Cloud secrets (never in repo).
- **Monitoring:** Supabase logs + GA4 + Sentry `[planned]`.

Full plan: [`06-launch/deployment.md`](./06-launch/deployment.md) · [`06-launch/performance.md`](./06-launch/performance.md) · [`06-launch/testing.md`](./06-launch/testing.md).

---

## 7. Brand Voice Guidelines `[draft — confirm]`

### 7.1 Voice Pillars
1. **Craft-confident** — we make furniture, we know how.
2. **Corporate-warm** — precise, but not cold.
3. **Locally rooted, globally aware** — Lahore-built, HON-inspired.
4. **Sales-honest** — real prices in PKR, real lead times, no fluff.

### 7.2 Tone Matrix

| Context | Tone | Example |
|---|---|---|
| Homepage hero | Aspirational, short | "Workspaces that work as hard as you do." |
| PDP | Descriptive, spec-forward | "Kiln-dried sheesham frame · 10-year structural warranty." |
| B2B / Quotation | Professional, outcome-led | "From layout to delivery — one partner, one timeline." |
| Custom Design | Consultative, invitational | "Tell us the space. We'll design the rest." |
| Contact | Direct, human | "Reach us on WhatsApp — we usually reply in minutes." |
| Blog | Editorial, useful | "Five workstation layouts that fit a 900 sq ft office." |

### 7.3 Do / Don't

| Do | Don't |
|---|---|
| Use active voice | Use marketing filler ("world-class", "cutting-edge") |
| Quote prices in PKR (e.g. "PKR 48,500") | Say "affordable luxury" |
| Say "Lahore-built" / "Made in Pakistan" | Overuse superlatives |
| Use short sentences on mobile | Use jargon without context |
| Prompt WhatsApp for anything B2B | Force phone-only contact |

### 7.4 Example Copy

**Homepage — hero**
> **Workspaces that work as hard as you do.**
> Lahore-built office and home furniture, engineered for the way Pakistan really works. Browse the catalog, or start a custom fitout in one message.
> `[Shop the range]` `[Start a project]`

**Homepage — B2B strip**
> **60+ offices fitted across Pakistan.** Workstations, boardrooms, receptions — one partner from floor plan to install day.

**Custom Design — hero**
> **Design first. Build once.**
> Share your floor plan or a few photos. Our team returns a 3D layout, material palette, and a fixed-price quote within 72 hours — no obligation, no template kits.
> `[Start a design brief]`

**Custom Design — WDS tool blurb**
> **WDS 3D — see it before we build it.**
> Drop in dimensions, pick your palette, and walk through the space in your browser. Every configuration exports straight to a WOODEX quote.

**Contact — hero**
> **Get in touch — we're on WhatsApp.**
> Sales, samples, site visits, aftercare: message us and a human replies (usually in minutes, always within one working day).
> `[WhatsApp us]` `[Book a showroom visit]` `[Email sales]`

**Contact — form intro**
> Prefer to write? Tell us a bit about the project and we'll come back with next steps — timeline, budget bands, and who from the team will own it.

---

## 8. Documentation Index (32 files)

All files live under [`/docs`](./). Redevelopment teams should read this master doc first, then drill down.

### Root
- [`design.md`](./design.md) — brand + doc map
- [`MASTER.md`](./MASTER.md) — this file

### Phase 1 — Discovery (4)
- [`01-discovery/business-model.md`](./01-discovery/business-model.md)
- [`01-discovery/website-goals.md`](./01-discovery/website-goals.md)
- [`01-discovery/user-personas.md`](./01-discovery/user-personas.md)
- [`01-discovery/competitor-analysis.md`](./01-discovery/competitor-analysis.md)

### Phase 2 — Documentation (4)
- [`02-documentation/sitemap.md`](./02-documentation/sitemap.md)
- [`02-documentation/information-architecture.md`](./02-documentation/information-architecture.md)
- [`02-documentation/page-documentation.md`](./02-documentation/page-documentation.md)
- [`02-documentation/content-documentation.md`](./02-documentation/content-documentation.md)

### Phase 3 — Design System (5)
- [`03-design-system/colors.md`](./03-design-system/colors.md)
- [`03-design-system/typography.md`](./03-design-system/typography.md)
- [`03-design-system/grid-spacing.md`](./03-design-system/grid-spacing.md)
- [`03-design-system/components.md`](./03-design-system/components.md)
- [`03-design-system/animations.md`](./03-design-system/animations.md)

### Phase 4 — Development (6)
- [`04-development/component-library.md`](./04-development/component-library.md)
- [`04-development/routing.md`](./04-development/routing.md)
- [`04-development/cms-structure.md`](./04-development/cms-structure.md)
- [`04-development/seo.md`](./04-development/seo.md)
- [`04-development/forms.md`](./04-development/forms.md)
- [`04-development/api.md`](./04-development/api.md)

### Phase 5 — Dashboard (8)
- [`05-dashboard/overview.md`](./05-dashboard/overview.md)
- [`05-dashboard/crm.md`](./05-dashboard/crm.md)
- [`05-dashboard/quotes.md`](./05-dashboard/quotes.md)
- [`05-dashboard/orders.md`](./05-dashboard/orders.md)
- [`05-dashboard/projects.md`](./05-dashboard/projects.md)
- [`05-dashboard/products.md`](./05-dashboard/products.md)
- [`05-dashboard/blog.md`](./05-dashboard/blog.md)
- [`05-dashboard/analytics.md`](./05-dashboard/analytics.md)

### Phase 6 — Launch (4)
- [`06-launch/seo-checklist.md`](./06-launch/seo-checklist.md)
- [`06-launch/performance.md`](./06-launch/performance.md)
- [`06-launch/testing.md`](./06-launch/testing.md)
- [`06-launch/deployment.md`](./06-launch/deployment.md)

**Total: 32 phase docs + `design.md` + `MASTER.md` = 34 files.**

---

## 9. Redevelopment Roadmap

| Sprint | Deliverable | Depends on |
|---|---|---|
| 1 | Enable Lovable Cloud, provision tables + RLS, migrate seed data | Phase 4 docs |
| 2 | Public read migration (PostgREST) + Storage buckets | Sprint 1 |
| 3 | Edge Functions: leads, quotes (PDF), orders | Sprint 2 + secrets |
| 4 | WhatsApp Cloud API + Resend integration | Sprint 3 |
| 5 | Admin dashboard shell (auth, roles, layout) | Sprint 1 |
| 6 | Admin: Products + Series + Projects + Blog CRUD | Sprint 5 |
| 7 | Admin: CRM + Quotes + Orders + Analytics | Sprint 4 + 5 |
| 8 | SEO hardening (schema, sitemap.xml, breadcrumbs), Lighthouse pass | All |
| 9 | QA, Playwright smoke suite, launch | Sprint 8 |

---

## 10. Approval

Owner sign-off required on: brand voice examples (§7), keyword clusters (§3.1), competitor set (`01-discovery/competitor-analysis.md`), and CMS schema (§4.2). Once approved, Sprint 1 can start immediately.

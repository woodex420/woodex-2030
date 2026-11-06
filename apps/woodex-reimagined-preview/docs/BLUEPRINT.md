# WOODEX — Website Blueprint: Architecture, Page Mapping & Navigation

> Companion to [`MASTER.md`](./MASTER.md). This document is the **build-ready structural blueprint** for the WOODEX furniture website. It expands three areas in depth:
>
> 1. Master Website Architecture (tiers, data flow, module boundaries)
> 2. Web Page Mapping (every route, purpose, template, data source, SEO)
> 3. Navigation Structure (global nav, mega-menus, footer, breadcrumbs, mobile)
>
> **Status legend:** `[current]` shipped · `[planned]` next phase · `[draft — confirm]` needs brand-owner sign-off.

---

## 1. Master Website Architecture

### 1.1 Architectural Principles

1. **Separation of tiers** — presentation, application, data, integrations never blur.
2. **Read-heavy, write-guarded** — public reads via PostgREST; every write goes through an Edge Function for validation, rate-limits, and side-effects.
3. **Semantic tokens only** — no hardcoded colors in components; theme lives in `src/index.css`.
4. **SEO-first rendering** — meta, canonical, JSON-LD injected per route via `SeoContentBlock`.
5. **WhatsApp as primary conversion channel** — every B2B surface has a WhatsApp CTA.
6. **Progressive enhancement** — site works with JS disabled for content pages (SSG target `[planned]`).

### 1.2 Tier Diagram

```text
┌──────────────────────────────────────────────────────────────────────┐
│  1. PRESENTATION TIER                                                 │
│  React 18 · Vite 5 · TypeScript 5 · Tailwind v3 · shadcn/ui           │
│  ─────────────────────────────────────────────────────────────────    │
│  Public site (SPA + prerender)      Admin dashboard (auth-gated SPA)  │
│  ├── Pages (src/pages/*)            ├── /admin/*                      │
│  ├── Components (src/components/*)  ├── CRM · Quotes · Orders         │
│  ├── Contexts (Cart, Quote)         ├── Products · Series · Blog      │
│  └── SeoContentBlock                └── Analytics                     │
└──────────────────────────────────────────────────────────────────────┘
                    │ HTTPS · JSON · JWT (admin)
┌──────────────────────────────────────────────────────────────────────┐
│  2. APPLICATION TIER — Lovable Cloud (Supabase)  [planned]            │
│  ─────────────────────────────────────────────────────────────────    │
│  PostgREST auto-API              Edge Functions (Deno)                │
│  • public SELECT on catalog      • submit-lead                        │
│  • RLS-enforced                  • submit-quote  (+ PDF)              │
│                                  • submit-order                       │
│  Auth (email + magic link)       • newsletter-subscribe               │
│  Storage buckets                 • whatsapp-webhook                   │
│  • product-images (public)       • admin-mutate  (has_role)           │
│  • quote-pdfs (signed)                                                │
│  • project-media (public)                                             │
└──────────────────────────────────────────────────────────────────────┘
                    │ SQL
┌──────────────────────────────────────────────────────────────────────┐
│  3. DATA TIER — Postgres 15 + RLS                                     │
│  ─────────────────────────────────────────────────────────────────    │
│  Catalog:   products · series · projects · blog_posts · services      │
│  Commerce:  leads · quotes · quote_items · orders · order_items       │
│  Identity:  auth.users · user_roles · newsletter_subscribers          │
└──────────────────────────────────────────────────────────────────────┘
                    │
┌──────────────────────────────────────────────────────────────────────┐
│  4. INTEGRATIONS TIER                                                 │
│  WhatsApp Cloud API · Resend · @react-pdf/renderer                    │
│  GA4 · Google Search Console · Meta Pixel · Sentry [planned]          │
└──────────────────────────────────────────────────────────────────────┘
```

### 1.3 Module Boundaries (Frontend)

```text
src/
├── pages/           Route components (one per URL)
├── components/
│   ├── home/        Homepage sections
│   ├── shop/        ProductCard, Filters, ListItem
│   ├── product/     Gallery, Swatches, SpecTabs
│   ├── quotation/   PrintableInvoice
│   ├── configurator/ RoomConfigurator, Hotspots, MaterialPanel
│   ├── Header · Footer · NavLink · SeoContentBlock
│   └── CartDrawer · QuoteBasket
├── contexts/        CartContext · QuoteContext
├── data/            products · series · services · blogPosts · seoContent · materials
├── hooks/           use-mobile · use-toast
├── lib/             utils (cn)
└── index.css        Design tokens (colors, spacing, radii, shadows)
```

### 1.4 Data Flow — Read Path (Public)

```text
User → Route (React Router) → Page component
     → TanStack Query fetch → PostgREST GET
     → RLS filter (is_published = true) → JSON
     → Component render + SeoContentBlock (meta + JSON-LD)
```

### 1.5 Data Flow — Write Path (Quote Submission)

```text
User → QuoteBasket → submit()
     → Edge Function `submit-quote`
       ├── Zod validate payload
       ├── Rate-limit check (5/min/IP)
       ├── INSERT quotes + quote_items
       ├── Generate PDF (@react-pdf/renderer)
       ├── Upload PDF → Storage `quote-pdfs` (signed URL)
       ├── WhatsApp Cloud API → sales + customer
       └── Resend email → sales + customer
     → return { data: { quote_id, pdf_url } }
     → UI success + WhatsApp deep-link
```

### 1.6 Environments

| Env | URL | Data | Purpose |
|---|---|---|---|
| Local | `http://localhost:8080` | seed | dev |
| Preview | Lovable branch preview | staging DB | PR review |
| Production | `woodex.pk` `[draft]` | production DB | live |

### 1.7 Non-Functional Targets

| Metric | Target |
|---|---|
| LCP | < 2.5s |
| INP | < 200ms |
| CLS | < 0.1 |
| Lighthouse SEO | ≥ 90 |
| Lighthouse Performance (mobile) | ≥ 85 |
| Uptime | 99.9% |
| API p95 latency | < 300ms |

---

## 2. Web Page Mapping

### 2.1 Complete Route Table

Every route currently in `src/App.tsx`, with template, data source, primary CTA, and indexability.

| # | Path | Component | Template | Data Source | Primary CTA | Index |
|---|---|---|---|---|---|---|
| 1 | `/` | `Index` | Home | static + `products` (featured) | Shop / Start a project | ✅ |
| 2 | `/shop` | `Shop` | Catalog | `products` (filter, sort) | Add to Cart / Quote | ✅ |
| 3 | `/shop/:productId` | `ProductDetail` | PDP | `products` by slug | Add to Cart / Quote | ✅ |
| 4 | `/room-packages` | `RoomPackages` | Grid | `data/products` bundles | Configure package | ✅ |
| 5 | `/virtual-showroom` | `VirtualShowroom` | Interactive | `data/products` + hotspots | Add to Quote | ✅ |
| 6 | `/series` | `Series` | Index | `series` | View series | ✅ |
| 7 | `/series/:seriesId` | `SeriesDetail` | Series page | `series` + `products` join | View products | ✅ |
| 8 | `/projects` | `Projects` | Case-study grid | `projects` | View project | ✅ |
| 9 | `/projects/:projectId` | `ProjectDetail` | Case study | `projects` by slug | Start a project | ✅ |
| 10 | `/services` | `Services` | Services hub | `data/services` | Explore service | ✅ |
| 11 | `/services/:slug` | `ServiceDetail` | Service page | `data/services` by slug | Request quote | ✅ |
| 12 | `/custom-design` | `CustomDesign` | Landing | static + WDS tool | Start a brief | ✅ |
| 13 | `/b2b` | `B2B` | Landing | static + `projects` | Request a quote | ✅ |
| 14 | `/about` | `About` | About | static | Contact | ✅ |
| 15 | `/contact` | `Contact` | Contact | form → `submit-lead` | WhatsApp / Submit | ✅ |
| 16 | `/quotation` | `Quotation` | Quote flow | `QuoteContext` | Submit quote | ✅ |
| 17 | `/showrooms` | `Showrooms` | Locations | static (Lahore) | Get directions | ✅ |
| 18 | `/materials` | `Materials` | Palette | `data/materials` | Request sample | ✅ |
| 19 | `/warranty` | `Warranty` | Legal | static | Contact | ✅ |
| 20 | `/blog` | `Blog` | Index | `data/blogPosts` | Read | ✅ |
| 21 | `/blog/:postId` | `BlogPost` | Article | `data/blogPosts` by slug | Related / CTA | ✅ |
| 22 | `/checkout` | `Checkout` | Checkout | `CartContext` → `submit-order` | Place order | ❌ noindex |
| 23 | `*` | `NotFound` | 404 | — | Home | ❌ noindex |

### 2.2 Page Template Types

| Template | Layout skeleton | Used by |
|---|---|---|
| **Home** | Hero slider → USP strip → categories → featured series → projects → services → blog → FAQ → CTA | `/` |
| **Catalog** | Filter sidebar + product grid + SEO block | `/shop` |
| **PDP** | Gallery + info + tabs + related + FAQ | `/shop/:productId` |
| **Landing** | Hero → split sections → gallery → CTA form | `/custom-design`, `/b2b` |
| **Hub** | Hero → grid of children → CTA | `/services`, `/series`, `/projects` |
| **Detail** | Hero → long-form + gallery → related → CTA | `/services/:slug`, `/series/:seriesId`, `/projects/:projectId` |
| **Article** | Hero + prose + author + related | `/blog/:postId` |
| **Form-first** | Hero + form + trust signals | `/contact`, `/quotation`, `/checkout` |
| **Info** | Hero + prose + FAQ | `/warranty`, `/showrooms`, `/materials`, `/about` |

### 2.3 SEO Metadata per Template

| Template | Title pattern | Schema |
|---|---|---|
| Home | `WOODEX — Premium Office & Home Furniture Lahore` | `Organization` + `WebSite` + `LocalBusiness` |
| Catalog | `{Category} — WOODEX Furniture Pakistan` | `CollectionPage` + `BreadcrumbList` |
| PDP | `{Product} — {Series} \| WOODEX` | `Product` + `BreadcrumbList` + `FAQPage` |
| Series detail | `{Series} Collection — WOODEX` | `CollectionPage` + `BreadcrumbList` |
| Project | `{Project} — {Sector} Fitout Lahore \| WOODEX` | `Article` + `BreadcrumbList` |
| Service | `{Service} — WOODEX Pakistan` | `Service` + `FAQPage` + `BreadcrumbList` |
| Article | `{Title} — WOODEX Journal` | `Article` + `BreadcrumbList` |
| Info | `{Page} — WOODEX` | `WebPage` + `BreadcrumbList` |

### 2.4 Sitemap Generation `[planned]`

Generator script `scripts/generate-sitemap.ts` runs on `predev` and `prebuild`. Entries: all indexable routes above, plus one entry per published row for dynamic routes (`products`, `series`, `projects`, `services`, `blog_posts`). Emits `public/sitemap.xml`.

`public/robots.txt` — `Allow: /` and `Disallow: /checkout`; `Sitemap:` directive to project domain.

---

## 3. Navigation Structure

### 3.1 Header Anatomy

```text
┌─ Utility bar (h: 36px, bg: charcoal) ─────────────────────────────┐
│ Find Showroom │ Warranty │ Track Order │ +92-XXX │ WhatsApp       │
├─ Primary nav (h: 72px, bg: white, shadow-sm) ─────────────────────┤
│ [LOGO]  Products▾ Series▾ Solutions▾ Services▾ Custom About Contact  [🔍 Quote(2) Cart(0)] │
└───────────────────────────────────────────────────────────────────┘
```

### 3.2 Mega-Menus

**Products** (980px wide, 2 columns + featured tile)

| Office | Home | Featured |
|---|---|---|
| Workstations | Bedroom | Latest series card |
| Executive Desks | Living | "Shop all" tile |
| Conference | Dining | |
| Reception | Home Office | |
| Storage | Kids | |
| Ergonomic Chairs | Outdoor | |

**Series** — Ek · Infinity · Kraft · Legacy · Modo (5 cards with imagery).

**Solutions** (B2B) — Corporate · Startups · Hospitality · Education · Healthcare · Government (each → `/b2b#{sector}` or dedicated page `[planned]`).

**Services** — Custom Design · B2B Office Solutions · Custom Manufacturing · Delivery & Installation · Space Planning · After-Sales · Project Management (each → `/services/:slug`).

### 3.3 Right-Rail Actions

| Icon | Label | Behavior |
|---|---|---|
| 🔍 | Search | Opens overlay, searches `products` + `blog_posts` |
| 📋 | Quote Basket (n) | Opens `QuoteBasket` drawer |
| 🛒 | Cart (n) | Opens `CartDrawer` |

### 3.4 Mobile Nav

- Hamburger → full-screen overlay
- Accordion sections for each mega-menu group
- Utility items collapsed under "More"
- Sticky WhatsApp FAB (bottom-right)
- Cart + Quote icons persist in top bar

### 3.5 Breadcrumbs `[planned]`

Rendered on every non-home page, driven by route metadata. Emits `BreadcrumbList` JSON-LD.

```text
Home / Shop / Office / Executive Desks / Legacy Executive Desk
```

### 3.6 Footer (5 columns)

| Company | Products | Services | Support | Contact |
|---|---|---|---|---|
| About | Office | Custom Design | Warranty | Showroom address |
| Series | Home | B2B Fitouts | FAQ | WhatsApp |
| Projects | New Arrivals | Delivery | Track Order | Email |
| Blog | Sale | Aftercare | Materials | Socials |
| Careers | All Series | Project Mgmt | Contact | Newsletter |

Bottom bar: © WOODEX {year} · Privacy · Terms · Sitemap.

### 3.7 Internal Linking Rules

- Every PDP links to: parent category, parent series, 4 related products.
- Every series page links to all products in that series + 2 related projects.
- Every project links to the products used + related sector page.
- Every service page links to related projects + Custom Design + Contact.
- Every blog post links to 3 related posts + 1 relevant product/service.

### 3.8 Journey Maps

**B2B**
```text
Home → Solutions (sector) → Virtual Showroom → Add to Quote →
Quote Basket → Submit → PDF + WhatsApp → Sales follow-up → Site visit → Order
```

**B2C**
```text
Home → Shop / Series → PDP → Add to Cart → Slide-out Cart →
Checkout (COD / Bank Transfer) → WhatsApp confirmation → Delivery
```

**Designer / Custom**
```text
Home → Materials & Colors → Custom Design (WDS 3D) → Brief form →
Consultation → 3D layout + fixed quote → Approval → Manufacturing → Install
```

---

## 4. Build Checklist

Use this as the go/no-go list before development starts on any page.

- [ ] Route registered in `src/App.tsx`
- [ ] Page component in `src/pages/`
- [ ] Data source identified (static `data/*` or Cloud table)
- [ ] `SeoContentBlock` with title, description, JSON-LD
- [ ] H1 present, single, keyword-aligned
- [ ] Breadcrumbs rendered (non-home)
- [ ] Primary CTA visible above fold on desktop + mobile
- [ ] WhatsApp CTA on all B2B surfaces
- [ ] Images use semantic `alt`, lazy-loaded, WebP
- [ ] Sitemap entry added (or auto-generated)
- [ ] robots.txt reviewed if noindex needed
- [ ] Lighthouse pass (SEO ≥ 90, Perf ≥ 85 mobile)

---

## 5. Related Docs

- [`MASTER.md`](./MASTER.md) — full blueprint (business, SEO, catalog, API, brand voice)
- [`02-documentation/sitemap.md`](./02-documentation/sitemap.md)
- [`02-documentation/information-architecture.md`](./02-documentation/information-architecture.md)
- [`02-documentation/page-documentation.md`](./02-documentation/page-documentation.md)
- [`04-development/routing.md`](./04-development/routing.md)
- [`04-development/api.md`](./04-development/api.md)
- [`04-development/cms-structure.md`](./04-development/cms-structure.md)

---

## 6. Approval Gates

Before Sprint 1 begins, brand-owner sign-off required on:

1. Mega-menu categories and labels (§3.2)
2. Footer columns and links (§3.6)
3. Solutions sector list (§3.2 Solutions)
4. Custom domain (`woodex.pk` vs alternate)
5. Non-functional targets (§1.7)

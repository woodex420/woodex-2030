# WOODEX Page Builder — Specification (Elementor replacement)

**Module:** M13 · **Priority:** primary product pillar · **Status:** existing editor/core v1 is implemented locally; live schema/migration verification pending. The complete v2 product scope (Theme Studio, full drag-and-drop, global components, multi-industry starter packs, CRM bindings, and approval gates) is defined in [`AGENCY-GRADE-DASHBOARD-MASTER-PLAN.md`](AGENCY-GRADE-DASHBOARD-MASTER-PLAN.md) §5. This file is a technical v1 baseline, not approval to implement or apply the migration.

---

## 1. Goal

A **visual, block-based page builder** inside the WOODEX dashboard that lets a non-developer compose and preview storefront pages—landing pages, campaigns, product showcases, and B2B pitches—using the approved design system and typed data adapters. Until the actual Supabase project and its RLS/schema are verified, the editor runs on deterministic mock data; live publishing is gated on that review.

### Why not Elementor

| Elementor | This builder |
|---|---|
| WordPress/PHP, separate hosting, separate content store | Native to the existing dashboard + Supabase, one login, one permission model |
| Outputs its own HTML/CSS you don't control | Emits a **JSON document** rendered by your own React components |
| Cannot query your products/quotations | Native **dynamic bindings** to `products`, `categories`, `room_packages`, `blog_posts`, `testimonials`, `faqs` |
| Styling drifts from brand | Styling restricted to **design tokens**; no arbitrary values |
| Pages are slow | Shared renderer and lazy interactive blocks; prerender/edge caching is a later hosting decision, not an assumed current capability |
| No idea about your roles | Uses `user_permissions` — editors draft, managers publish |

---

## 2. Architecture

```
┌──────────────────────────── EDITOR (apps/dashboard/builder) ────────────────────────────┐
│  Canvas (iframe, live React)  │  Outline tree  │  Inspector  │  Device preview  │  Undo  │
└───────────────────────────────────────┬─────────────────────────────────────────────────┘
                                        │ read/write page JSON document
                                        ▼
                        ┌──────────── Supabase (Postgres) ─────────────┐
                        │ pages · page_blocks · page_revisions         │
                        │ navigation · redirects                       │
                        └───────────────────┬──────────────────────────┘
                                            │ published document
                                            ▼
                    ┌──── packages/builder-core (SHARED) ────┐
                    │ block registry · schema · resolver     │
                    └───────────────┬────────────────────────┘
                                    │  same components, same tokens
              ┌─────────────────────┴─────────────────────┐
              ▼                                            ▼
   Editor canvas (preview ≡ production)         apps/storefront (public pages)
```

**The core guarantee:** the editor canvas and the published storefront run **the same renderer from `packages/builder-core`**. A page cannot look different in preview and production — that class of bug is designed out.

Reference implementation to reuse: the storefront already has a working **`RoomConfigurator`** (`src/components/configurator/`) with material panels and hotspot markers, and the AI Dashboard 3.1 already demonstrates **runtime Supabase credentials + mock fallback**. Fold both patterns into the builder.

---

## 3. Data model

The migration-derived inventory has no CMS tables. A five-table migration draft exists in the local monorepo but is unapplied. The schema below is a design sketch only—not approved DDL—and must be reconciled against the complete safe preflight JSON, live grants, functions, triggers, and RLS before any migration is applied.

```sql
-- Pages: one row per page, per locale
create table pages (
  id             uuid primary key default gen_random_uuid(),
  slug           text not null,                    -- '/landing/ramadan-offer'
  title          text not null,
  locale         text not null default 'en',       -- i18n: en | ur | ar
  status         text not null default 'draft',    -- draft | review | published | archived
  layout         text not null default 'default',  -- storefront layout shell
  seo            jsonb not null default '{}',      -- title, description, og_image, noindex, canonical
  settings       jsonb not null default '{}',      -- validated layout/header/footer options only; no executable scripts or arbitrary CSS in v1
  created_by     uuid references profiles(id),
  updated_by     uuid references profiles(id),
  published_at   timestamptz,
  created_at     timestamptz default now(),
  updated_at     timestamptz default now(),
  unique (slug, locale)
);
create index on pages (status, locale);

-- Blocks: ordered tree nodes belonging to a page (adjacency list keeps diffs small)
create table page_blocks (
  id           uuid primary key default gen_random_uuid(),
  page_id      uuid not null references pages(id) on delete cascade,
  parent_id    uuid references page_blocks(id) on delete cascade,
  position     int  not null default 0,
  type         text not null,                      -- 'section' | 'heading' | 'product_grid' | ...
  props        jsonb not null default '{}',        -- block-specific content
  style        jsonb not null default '{}',        -- token-bound + responsive overrides
  binding      jsonb,                              -- dynamic data source, see §6
  is_visible   boolean not null default true,
  created_at   timestamptz default now(),
  updated_at   timestamptz default now()
);
create index on page_blocks (page_id, parent_id, position);

-- Revisions: full immutable snapshot on every publish (and named manual saves)
create table page_revisions (
  id           uuid primary key default gen_random_uuid(),
  page_id      uuid not null references pages(id) on delete cascade,
  snapshot     jsonb not null,                     -- {page, blocks[]} frozen
  label        text,
  created_by   uuid references profiles(id),
  created_at   timestamptz default now()
);

-- Navigation menus (header/footer), editable without code
create table navigation (
  id         uuid primary key default gen_random_uuid(),
  key        text not null unique,                 -- 'header-main' | 'footer-services'
  locale     text not null default 'en',
  items      jsonb not null default '[]',          -- [{label, href, children[]}]
  updated_at timestamptz default now()
);

-- Redirects so slugs can be changed safely
create table redirects (
  id         uuid primary key default gen_random_uuid(),
  from_path  text not null unique,
  to_path    text not null,
  status     int  not null default 301,
  created_at timestamptz default now()
);
```

Candidate access rule (not final policy): public/anonymous reads are restricted to published documents and the requested locale; authenticated writes require workspace membership plus scoped capabilities; publishing is a separate capability from editing. The exact grants, policy expressions, workspace boundary, and role mapping must be reviewed/tested against the user's live project before use. Publish is separate from edit by design.

---

## 4. Block document format

A published page is a single JSON document (assembled from `pages` + `page_blocks`), cacheable as a unit:

```json
{
  "slug": "/landing/executive-suites",
  "locale": "en",
  "seo": { "title": "Executive Suites | WOODEX", "description": "…" },
  "tree": [
    {
      "id": "b1", "type": "section",
      "style": { "padding": "var(--space-16)", "background": "var(--surface-base)" },
      "children": [
        { "id": "b2", "type": "heading",
          "props": { "text": "Executive Office Suites", "level": 1 },
          "style": { "color": "var(--text-primary)", "align": "center" } },
        { "id": "b3", "type": "text",
          "props": { "html": "<p>Design-to-delivery for 50–500 seats.</p>" },
          "style": { "color": "var(--text-secondary)", "maxWidth": "prose" } },
        { "id": "b4", "type": "product_grid",
          "props": { "columns": { "desktop": 4, "tablet": 2, "mobile": 1 }, "card": "shop" },
          "binding": { "source": "products", "filter": { "category": "executive-tables" }, "limit": 8, "order": "is_featured.desc" } },
        { "id": "b5", "type": "cta_button",
          "props": { "label": "Request a quote", "action": "open_quote_basket" },
          "style": { "variant": "primary", "size": "lg" } }
      ]
    }
  ]
}
```

Design rules enforced by the schema (not by convention):
- **No raw colours or pixel values in `style`** — only `var(--token)` references and named scale steps. A validator rejects anything else.
- Every block type has a typed props schema (zod) → the inspector form is *generated*, so adding a block is a single registry entry, not UI work.
- Responsive overrides live under `style.responsive.{tablet,mobile}`; desktop is the base.
- `binding` is optional on any block; when present, props become the *fallback* while the query result is the content.

---

## 5. Block library

| Group | Blocks |
|---|---|
| **Layout** (6) | `section`, `container`, `columns`, `grid`, `spacer`, `divider` |
| **Content** (11) | `heading`, `text`, `image`, `gallery`, `video`, `button`, `icon`, `accordion`, `tabs`, `quote`, `table` |
| **Commerce** (6) | `product_grid`, `product_card`, `category_strip`, `room_package`, `price_table`, `add_to_quote` |
| **Lead-gen** (5) | `form` (name/email/phone/message → `customers` + `customer_interactions`), `whatsapp_cta`, `contact_card`, `map`, `newsletter` |
| **Dynamic** (6) | `latest_blog`, `testimonials`, `faq`, `client_logos`, `stats`, `showroom_teaser` |

Start with 12 blocks (layout 6 + heading + text + image + button + product_grid + form) — that is enough to reproduce every page the storefront has today, which is the acceptance test for a v1 builder.

---

## 6. Dynamic bindings (the Elementor-killer feature)

| Source | Filter | Typical use |
|---|---|---|
| `products` | `category`, `is_featured`, `price between`, `search` | Product grids that update when the catalogue changes |
| `categories` | parent, position | Category strips |
| `room_packages` | price, seats | Bundle offers |
| `blog_posts` | tag, limit | Latest insights, auto-updating |
| `testimonials` | min rating | Social proof |
| `faqs` | category | Per-page FAQ + SEO schema |
| `services` | slug | Service landing pages |

Target behavior after a trusted resolver and hosting/cache architecture exist: resolve bindings with server-side authorization, bounded queries, safe fallbacks, and deliberate invalidation. The current Vite storefront does not establish server rendering or edge caching; initial mock/editor work must not claim either. Live bindings require the schema/RLS gate in the master plan.

---

## 7. Editor UX

| Area | Behaviour |
|---|---|
| **Canvas (target v2)** | Live render using the shared renderer; click to select/edit; pointer drag with clear drop zones and keyboard move/reorder; responsive and direction preview. The current local v1 has manual up/down reordering, not full pointer drag/drop. |
| **Outline tree (target v2)** | Full page structure, accessible reorder/reparent, rename, toggle visibility, lock, duplicate, and select. |
| **Inspector** | Generated from the block's zod schema: Content / Style / Advanced tabs; style pickers only offer design tokens |
| **Device preview** | Desktop / tablet / mobile widths; per-breakpoint style editing; content is shared unless explicitly overridden |
| **Toolbar** | Undo/redo (100 steps, ⌘Z/⌘⇧Z), autosave every 20s, Save draft, Preview (shareable signed link), Publish |
| **Revisions** | Visual diff between any two revisions; one-click restore; "who changed what" |
| **Shortcuts** | ⌘S save, ⌘Z/⌘⇧Z, ⌘D duplicate, ⌫ delete, ⌘/ command palette (reuses the `cmdk` dependency already installed and unused) |
| **Assets** | Media library drawer backed by `media_assets` + the `product-images`/`media-library` buckets |
| **Copy/paste** | Copy a block or whole section between pages; JSON import/export for staging → production |
| **Accessibility panel** | Alt text, heading order validation, contrast check against tokens, link labels |

**Validation before publish:** broken bindings, missing alt text on images, duplicate slugs, heading-level skips, empty required fields. Warnings are non-blocking; errors are.

---

## 8. Rendering & performance

- **Initial release:** use the current storefront route and the shared renderer; lazy-load interactive blocks and optimize media. Confirm hosting and rendering capability before promising server rendering or edge cache.
- **Future cache contract:** once a trusted server/prerender layer is selected, publishing or a relevant bound-record change invalidates only affected routes; measure cache behavior and fallback states.
- **Budget in CI:** a builder page must stay within the same performance budget as a hand-coded page (LCP/CLS/JS-byte caps); the builder must never be the reason the site slows down.
- **Fallbacks:** if a binding returns nothing, the block renders its fallback props (never an empty hole in the layout).
- **SEO:** builder pages emit the same `<title>`/meta/JSON-LD pipeline as coded pages, including `Product` and `FAQPage` structured data from bindings.

---

## 9. Migration path (if existing Elementor content must move)

1. Export the Elementor page as JSON (Elementor stores `_elementor_data` as JSON in `wp_postmeta`).
2. Map Elementor widget types → builder blocks (`heading`, `text-editor`, `image`, `button`, `image-gallery`, `accordion`, `tabs`, `icon-list`, `counter`, `testimonial`, `products`).
3. Map section/column structure → `section`/`columns`; drop unknown widgets with a report rather than silently losing them.
4. Convert hard-coded colours/fonts to the nearest design token, and report any value with no token equivalent for human review.
5. Import as `status='draft'` with a diff checklist — nothing publishes automatically.

---

## 10. Build phases & acceptance criteria

| Phase | Deliverable | Acceptance |
|---|---|---|
| **P1 — Core** | `builder-core` package: block registry, zod schemas, renderer; `pages`/`page_blocks` tables; storefront renders a builder page from JSON | A hand-written JSON page renders identically in storefront and editor canvas |
| **P2 — Editor** | Canvas, outline, inspector, drag/drop, undo/redo, autosave | A non-developer builds a landing page with 12 blocks in under 30 minutes |
| **P3 — Publish** | Revisions, preview links, publish/unpublish, redirects, navigation editor, cache purge | Publish → live on storefront in < 5s → rollback works |
| **P4 — Dynamic** | Bindings to products/categories/blog/testimonials/faqs + fallbacks | Product grid reflects a product added in the dashboard, without republishing the page |
| **P5 — Governance** | RBAC (draft vs publish), accessibility validation, SEO fields, revision diff | An `editor` user can draft but cannot publish; `management` can |
| **P6 — Scale** | A/B variants, per-locale content (Urdu/Arabic RTL), analytics per page, templates/blocks library, Elementor import | RTL page renders correctly; a new page starts from a template |

---

## 11. What must be true before P1 starts

1. Phase 1A/1B done — the design system is tokenised (blocks style themselves exclusively from tokens).
2. Phase 2 done — dashboard shell + dark mode, so the editor inherits them.
3. Safe schema/RLS preflight is available before any live binding or migration. A deterministic mock adapter is sufficient to build and test the visual editor before live database access.
4. A decision on **who can publish** (role names in §6 of MASTER-PLAN), because it defines the permission checks built into P5.

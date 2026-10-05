# CMS Structure `[planned]`

Currently content lives in TypeScript data files. Phase 2 migrates to Lovable Cloud (Supabase) with an admin UI.

## Proposed Tables

### `products`
| Column | Type | Notes |
|---|---|---|
| `id` | uuid PK | |
| `slug` | text unique | |
| `title` | text | |
| `category` | text | office / home / accessory |
| `subcategory` | text | workstation, executive, bedroom, etc. |
| `series_id` | uuid FK → series | nullable |
| `description` | text | |
| `price_pkr` | numeric | |
| `dimensions` | jsonb | `{w,h,d,unit}` |
| `materials` | text[] | |
| `colors` | jsonb | `[{name, hex, swatch_url}]` |
| `images` | text[] | |
| `variants` | jsonb | |
| `stock` | int | |
| `is_published` | bool | |
| `created_at` / `updated_at` | timestamptz | |

### `series`
`id, slug, title, tagline, story, hero_image, gallery[], is_published`.

### `projects`
`id, slug, title, sector, location, area_sqft, challenge, solution, result, gallery[], products[], published_at`.

### `blog_posts`
`id, slug, title, excerpt, body_md, cover_image, author, read_minutes, tags[], published_at, seo_title, seo_description`.

### `services`
`id, slug, title, hero_image, overview, inclusions[], process[], faqs jsonb`.

### `leads`
`id, name, email, phone, company, source, message, page_url, created_at, status`.

### `quotes`
`id, quote_no, lead_id FK, items jsonb, subtotal, tax, total, advance_amount, status, pdf_url, created_at`.

### `orders` (B2C)
`id, order_no, customer jsonb, items jsonb, subtotal, shipping, total, payment_method, status, created_at`.

## RLS Policy Sketch `[planned]`
- Public: `SELECT` on `is_published = true` rows of products / series / projects / blog_posts / services.
- Authenticated (admin role): full CRUD via `has_role(auth.uid(), 'admin')`.
- `leads`, `quotes`, `orders`: INSERT open to `anon`; SELECT/UPDATE admin-only.

Roles table follows the `user_roles + has_role()` pattern from platform standards.

## Media
- Supabase Storage bucket `product-images` (public read).
- Admin uploads via signed URLs.

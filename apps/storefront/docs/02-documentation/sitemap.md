# Sitemap `[current]`

Generated from `src/App.tsx` routes.

## Public Routes

```text
/
├── /shop
│   └── /shop/:productId
├── /room-packages
├── /virtual-showroom
├── /series
│   └── /series/:seriesId
├── /projects
│   └── /projects/:projectId
├── /services
│   └── /services/:slug
├── /custom-design
├── /b2b
├── /quotation
├── /showrooms
├── /materials
├── /warranty
├── /about
├── /contact
├── /blog
│   └── /blog/:postId
├── /checkout
└── /* (NotFound)
```

## Dynamic Route Sources
| Route | Data source |
|---|---|
| `/shop/:productId` | Product catalogue (localStorage seeds) |
| `/series/:seriesId` | 5 series in `src/data/` (Ek, Infinity, etc.) |
| `/projects/:projectId` | `src/pages/ProjectDetail.tsx` case studies |
| `/services/:slug` | `src/data/services.ts` (7 entries) |
| `/blog/:postId` | `src/data/blogPosts.ts` |

## Excluded from Sitemap
- `/checkout` (transactional)
- `*` NotFound

## XML Sitemap `[planned]`
Add `scripts/generate-sitemap.ts` (see `docs/06-launch/seo-checklist.md`).

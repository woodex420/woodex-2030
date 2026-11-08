# Projects `[planned]`

## Purpose
CMS for case studies displayed on `/projects` and `/projects/:projectId`.

## Fields
title, slug, sector (Corporate | Startup | Hospitality | Retail | Education | Healthcare), location (city, area), area_sqft, year, client_name (public/anonymised), challenge, solution, result, testimonial, cover_image, gallery[], products_used[] (FK), tags[], is_published, published_at.

## Editor UX
- Rich text (Markdown) for challenge / solution / result.
- Drag-drop gallery with alt text per image.
- Auto-slugify from title.
- SEO fields (title, description, OG image).

## Publishing
- Draft → Preview → Publish workflow.
- Scheduled publishing via `published_at`.

## Metrics
- Case-study page views.
- Time on page.
- Leads attributed via URL source tag.

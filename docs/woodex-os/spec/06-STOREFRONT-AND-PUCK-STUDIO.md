# 06 — Storefront and Puck Website Studio

## 1. Product intent

The storefront is the customer-facing experience for WOODEX. Website Studio gives authorized staff control over page composition and presentation without duplicating product catalogue, pricing or order management.

## 2. Storefront information architecture

Proposed public navigation:
- Home.
- Shop / Products.
- Categories.
- Series / Collections.
- Custom Design / Request a Quote (label and workflow to confirm).
- Projects / Portfolio.
- About / Company.
- Blog / Insights.
- Contact.
- Support / Warranty.

Header/footer and menu labels must be configurable in Website Studio. Preserve existing URLs where possible; use redirects for approved route changes.

## 3. Storefront page types

- Homepage.
- Campaign/landing page.
- Category page.
- Series/collection page.
- Product detail page.
- Custom design/enquiry page.
- Portfolio/project detail.
- Blog index and article.
- Contact page.
- FAQ/support pages.
- Legal/policy pages.

Dynamic pages consume typed data from Laravel. A page editor must not copy live product data into static JSON when a typed product component can render the canonical catalogue record.

## 4. Puck selection and implementation boundary

Puck is the selected visual editor direction, subject to a technical prototype. It is a React editor; its output is application-owned data. WOODEX must own the storage, schema validation, authorization, revisioning and publication lifecycle.

### Shared component registry

One versioned registry must drive:
1. Puck editor component configuration.
2. Frontend renderer.
3. Server-side validation.
4. Preview and published rendering.

Initial families:

| Family | Components |
|---|---|
| Layout | Section, Container, Grid, Columns, Spacer |
| Typography | Heading, Rich Text, Eyebrow, List |
| Media | Image, Gallery, approved Video Embed |
| Conversion | Button, CTA, Quote Request, Contact Form |
| Commerce | Product Grid, Featured Product, Category Grid, Series Grid, Product Hero |
| Brand | Logo Strip, Trust Bar, Testimonial, Stats |
| Navigation | Header, Footer, Mega Menu |
| Content | Blog Grid, Portfolio Grid, FAQ, Related Content |

Do not allow arbitrary script execution, arbitrary PHP/Blade, unrestricted raw HTML or unrestricted CSS injection.

## 5. Editor workflow

1. User selects a page or template.
2. Puck loads the latest editable draft.
3. User adds, reorders and configures approved components.
4. Media is selected from the authorized media library.
5. Commerce blocks bind to product/category/series records.
6. User previews desktop, tablet and mobile.
7. User saves a new revision.
8. User submits for approval if required.
9. Server validates component schema, media references, links, SEO and data bindings.
10. Authorized publisher publishes the revision.
11. Storefront renders the published revision.
12. Authorized user can roll back to a prior published revision.

## 6. Content lifecycle

Suggested statuses:
- `draft`
- `in_review`
- `approved`
- `published`
- `scheduled` (only if scheduler is reliable on hosting)
- `archived`

Publishing is not a normal save. It is a separate permission-checked operation and creates a publish event.

## 7. Data model

- Page identity: title, slug, type, locale, status, current draft revision, published revision.
- Revision: page ID, revision number, schema version, Puck JSON, change summary, author, timestamp.
- Template: type, allowed components, default data.
- Saved section: reusable component tree, schema version and usage references.
- Navigation menu: menu key, items, order, visibility and destination.
- Theme settings: design token values with version history.
- SEO metadata: title, description, canonical, robots, social preview.
- Redirect: source path, target path, status code, active flag.
- Media: storage key, MIME, size, dimensions, alt text, focal point, owner and visibility.

## 8. Theme and design settings

Global settings:
- Brand colors.
- Typography family and scale.
- Spacing scale.
- Button variants.
- Border radius.
- Content width.
- Header/footer variants.
- Breakpoint behavior.

Theme changes should be previewable and versioned. A global token change can affect many pages; show impact and require appropriate permission.

## 9. Media library

Requirements:
- Upload with file-type and size validation.
- Image dimensions and metadata.
- Alt text required for meaningful images.
- Folders/tags/search.
- Responsive image derivatives where infrastructure permits.
- No executable uploads.
- Authorization checks for private assets.
- Replace media with explicit reference behavior.
- Track where an asset is used before deletion.

## 10. SEO and publishing

Per page:
- SEO title and description.
- Canonical URL.
- Index/follow settings.
- Social share title/image.
- Structured data only from validated templates.
- Sitemap inclusion.
- Redirect handling.
- Preview noindex and access protection.
- Published content crawlability.

Do not claim SEO score or indexing success without measured evidence.

## 11. Prototype acceptance gates

Before broad implementation:
- Pin Puck version and verify React peer compatibility.
- Demonstrate edit/save/reload round trip.
- Prove editor preview matches storefront renderer.
- Prove schema-version migration strategy.
- Prove media authorization.
- Prove draft preview is private.
- Prove revision conflict handling.
- Prove approval, publish and rollback.
- Prove commerce data bindings remain canonical.
- Prove existing selected pages can be adapted without content loss.

## 12. Migration of existing page content

Inventory current page model and component types first. Create a mapping table:
- Source component.
- Target Puck component.
- Data transform.
- Unsupported behavior.
- Manual remediation.
- QA result.

Do not mass-convert pages before testing representative pages and comparing rendered output. Keep original content backup and route rollback.

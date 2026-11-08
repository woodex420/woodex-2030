# Components `[current]`

## Shadcn Primitives (in use)
`button`, `input`, `textarea`, `select`, `checkbox`, `radio-group`, `switch`, `label`, `form`, `card`, `dialog`, `sheet`, `drawer`, `tabs`, `accordion`, `tooltip`, `dropdown-menu`, `navigation-menu`, `sonner`, `toast`, `badge`, `separator`, `avatar`, `carousel`.

All live under `src/components/ui/`. Do not edit generated primitives; extend via wrappers.

## Custom Components

### Layout
- `Header.tsx` — utility bar, mega-menu, right-rail (search / quote / cart).
- `Footer.tsx` — 5-column footer + newsletter.

### Merchandising
- `HeroSlider.tsx` — 5-slide hero with autoplay + dots.
- `SeriesCarousel.tsx` — horizontal scroll of 5 series.
- `ProductCard.tsx` — image, title, price, quick add.

### Commerce
- `CartSheet.tsx` — slide-out B2C cart (COD / Bank Transfer).
- `QuoteBasket.tsx` — B2B quote basket (75% advance calc).
- `QuoteForm.tsx` — company + project fields.

### Content
- `SeoContentBlock.tsx` — sets `<title>`, meta desc, JSON-LD (CollectionPage + FAQPage).
- `HotspotConfigurator.tsx` — virtual showroom overlay.
- `BeforeAfterSlider.tsx` — project transformations.

## Buttons

| Variant | Token | Usage |
|---|---|---|
| `default` | `bg-primary text-primary-foreground` | Primary CTA (dark) |
| `accent` | `bg-accent text-accent-foreground` | Green CTA (WhatsApp, Get Quote) |
| `outline` | `border-primary` | Secondary |
| `ghost` | transparent | Tertiary / nav |
| `link` | underline accent | Inline |

Sizes: `sm (h-8)`, `default (h-10)`, `lg (h-12)`, `icon (h-10 w-10)`.

## Cards
- Base: `bg-card border border-border rounded-md p-6`.
- Elevated: add `shadow-sm hover:shadow-md transition`.
- Feature card: icon (32px) + H4 + body 15px.

## Forms
- `react-hook-form` + `zod` schema.
- Labels above inputs, error text `text-destructive text-sm mt-1`.
- Required inputs marked with `*` in label.
- Submit button full-width on mobile.

## Icons
- `lucide-react` at 16 / 20 / 24 / 32 px.
- Colour: `text-foreground` default, `text-accent` for active/branded.
- Never mix icon libraries.

## States
| State | Style |
|---|---|
| Hover | subtle elevation + accent border |
| Focus | `ring-2 ring-ring ring-offset-2` |
| Active | `bg-hon-green-dark` on CTAs |
| Disabled | `opacity-50 cursor-not-allowed` |
| Loading | spinner icon + disabled |

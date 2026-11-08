# Typography `[current]`

## Font Stack
- **Primary:** `Inter` (loaded via Google Fonts, weights 300–900).
- **Fallback:** `-apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif`.
- **Never** use serif, Poppins, or purple-adjacent display fonts.

## Base
- Body: `15px / 1.6`, `Inter 400`.
- Antialiased (`-webkit-font-smoothing: antialiased`).

## Scale

| Element | Desktop | Mobile | Weight | Notes |
|---|---|---|---|---|
| H1 | 48–64px | 32–40px | 700–800 | One per page |
| H2 | 32–40px | 24–28px | 700 | Section titles; pair with `hon-accent-underline` |
| H3 | 24–28px | 20–22px | 600 | Sub-sections |
| H4 | 18–20px | 16–18px | 600 | Card titles |
| H5 | 16px | 15px | 600 | Meta labels |
| H6 | 14px | 13px | 600 uppercase tracking-wide | Eyebrow labels |
| Body | 15px | 15px | 400 | Default paragraph |
| Small | 13px | 13px | 400 | Captions, meta |
| Micro | 11px | 11px | 500 uppercase | Utility bar, tags |

## Mobile Input Rule
Form inputs use `font-size: 16px` on mobile to prevent iOS zoom.

## Accent Underline
`hon-accent-underline` — small green bar under H2 section titles. Do not replace with icons or dots.

## Utility Classes
- `.text-balance` — for long H1/H2.
- `.tracking-tight` — display headings.
- `.tracking-wide uppercase` — H6 eyebrows.

## PDF Invoice Type (Quotation PDFs)
- Company name: 20px bold
- Section headings: 12px bold uppercase
- Line items: 10px
- Totals: 12px bold

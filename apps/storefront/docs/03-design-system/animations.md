# Animations `[current]`

## Principles
- Corporate restraint — motion supports comprehension, never decoration.
- Duration: `150ms` (micro), `250ms` (default), `400ms` (hero).
- Easing: `ease-out` for enter, `ease-in` for exit.

## Tailwind Keyframes (config)
- `accordion-down` / `accordion-up` — 200ms ease-out, Radix accordion.

## Custom
| Class / effect | Where | Notes |
|---|---|---|
| `hon-accent-underline` | H2 section titles | 3px green bar, 250ms grow on scroll-in |
| Hero slider auto-advance | `HeroSlider.tsx` | 6s per slide, fade + subtle zoom |
| Card hover | Product / service cards | 250ms shadow + border-accent |
| Cart / Quote drawer | Sheet from right | 300ms cubic-bezier |
| Nav mega-menu | Header | 200ms fade + 4px translateY |

## Scroll Behaviour
- Smooth scroll only for in-page anchor links.
- No parallax (rejected — off-brand for corporate feel).

## Reduced Motion
Respect `prefers-reduced-motion: reduce` — disable slider autoplay, use instant transitions on drawers.

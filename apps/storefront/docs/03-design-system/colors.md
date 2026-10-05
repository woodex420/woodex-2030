# Colors `[current]`

All colours are HSL semantic tokens defined in `src/index.css`. **Never hard-code hex or Tailwind colour utilities in components.**

## Light Theme (default)

| Token | HSL | Approx Hex | Usage |
|---|---|---|---|
| `--background` | `0 0% 100%` | `#FFFFFF` | Page background |
| `--foreground` | `0 0% 12%` | `#1F1F1F` | Body text |
| `--primary` | `210 11% 15%` | `#22262B` | Nav, footer, primary CTA |
| `--primary-foreground` | `0 0% 100%` | `#FFFFFF` | Text on primary |
| `--secondary` | `0 0% 95%` | `#F2F2F2` | Subtle surface |
| `--muted` | `0 0% 96%` | `#F5F5F5` | Card backgrounds |
| `--muted-foreground` | `0 0% 45%` | `#737373` | Secondary text |
| `--accent` | `88 62% 37%` | `#5FA023` | Signature green — links, badges, active |
| `--accent-foreground` | `0 0% 100%` | `#FFFFFF` | Text on accent |
| `--border` | `0 0% 88%` | `#E0E0E0` | Dividers |
| `--ring` | `88 62% 37%` | `#5FA023` | Focus ring |

## HON Green Scale
| Token | HSL | Usage |
|---|---|---|
| `--hon-green` | `88 62% 37%` | Base accent |
| `--hon-green-light` | `88 50% 48%` | Hover states |
| `--hon-green-dark` | `88 70% 28%` | Active / pressed |
| `--hon-green-pale` | `88 40% 95%` | Section wash |

## Utility & Section Tokens
| Token | Purpose |
|---|---|
| `--utility-bar` / `--utility-text` | Top thin nav bar |
| `--hero-overlay` | Overlay on hero images |
| `--section-light` / `--section-mid` | Alternating section backgrounds |
| `--badge-success` / `--badge-success-foreground` | In-stock, verified badges |

## Dark Theme
Defined under `.dark`. Same token names, inverted values. Primary swaps to light, accent slightly brighter (`88 62% 42%`).

## Rules
- ✅ `bg-primary text-primary-foreground`
- ✅ `text-accent border-accent`
- ❌ `bg-black text-white bg-[#5FA023]`
- ❌ Purple / indigo / gradient combinations — off-brand.

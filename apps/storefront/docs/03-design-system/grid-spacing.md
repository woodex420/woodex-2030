# Grid & Spacing `[current]`

## Container
Defined in `tailwind.config.ts`:
- `center: true`
- `padding: 2rem`
- `screens.2xl: 1400px`

Use `<div class="container">` for standard content width.

## Breakpoints (Tailwind default)
| Name | Min-width |
|---|---|
| `sm` | 640px |
| `md` | 768px |
| `lg` | 1024px |
| `xl` | 1280px |
| `2xl` | 1400px (custom) |

## Spacing Scale
Tailwind default 4px base (`1 = 4px`, `2 = 8px`, `4 = 16px`, `8 = 32px`, `16 = 64px`, `24 = 96px`).

## Section Rhythm
- Section vertical padding: `py-16 md:py-24`.
- Container gutters: `px-4 md:px-8`.
- Card gap in grids: `gap-6 md:gap-8`.
- Hero min-height: `min-h-[560px] md:min-h-[720px]`.

## Grid Patterns
| Pattern | Cols mobile | Cols tablet | Cols desktop |
|---|---|---|---|
| Product grid | 2 | 3 | 4 |
| Service cards | 1 | 2 | 3 |
| Footer | 1 | 2 | 5 |
| Mega menu | — | — | 4 |

## Radius
- `--radius: 0.25rem` (4px) — deliberately tight to match HON corporate feel.
- Buttons, inputs, cards inherit via `rounded-md` / `rounded-lg`.

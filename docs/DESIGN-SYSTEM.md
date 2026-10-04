# WOODEX Design System

**Purpose:** one token source that serves the storefront, the dashboard **and** the page builder — so a builder page can never drift from the brand, and the Phase 1 upgrade ships with zero visual diff.

---

## 1. Current state — three parallel systems (measured)

| App | Palette | Type | Tokens | Notes |
|---|---|---|---|---|
| **Dashboard** (`woodex-admin/src`) | workspace.ae greys: `#333333` / `#484848` / `#FFFFFF` / `#000000` + `rgba(183,183,183,.11)` separators | **Raleway**, 11→51px scale, section 100px / component 30px, `xs:480px` screen | Tailwind 3 config tokens | **614 token-class usages** |
| **Storefront** (`woodex-reimagined`) | HON-inspired white/dark/**green** via HSL CSS vars (`--primary`, `--accent`) | **Inter**, 15px base | HSL vars in `index.css` | 0 of the dashboard's tokens |
| **AI Dashboard 3.1** | Dark: `#050505`/`#121212`/`#1E1E1E`/`#2D2D2D` + **blue `#3B82F6`** | Inter | CDN Tailwind inline config | Prototype, dark-only |

Three palettes, three type scales, two fonts, zero sharing. The page builder cannot exist on top of this: a block styled "primary" would mean three different colours depending on where it renders.

### Bug found during analysis

Two class names are used but **never defined** in `tailwind.config.js`, so Tailwind generates no CSS for them:

| Class | Usages | Effect today |
|---|---:|---|
| `bg-surface-gray` | 23 | elements have **no background** (silently transparent) |
| `text-text-tertiary` | 11 | text inherits the parent colour instead of the intended tertiary grey |

**34 silent no-ops.** These are genuinely broken UI today, and the token bridge in §3 is the natural place to fix them by *defining the tokens properly* rather than patching call sites.

---

## 2. Target: semantic tokens, three themed surfaces

Single source of truth = CSS custom properties, consumed by Tailwind v4 `@theme`. Colours are **semantic, not literal**, so the same block renders correctly in the storefront (light, green accent) and the dashboard (grey, dark-mode capable).

```css
/* packages/design-system/tokens.css */
@theme {
  /* ── Brand ───────────────────────────────────────────── */
  --color-brand-50:  …; --color-brand-500: …; --color-brand-600: …;
  --color-accent-500: …;

  /* ── Surfaces ────────────────────────────────────────── */
  --color-surface-base:      var(--surface-base);
  --color-surface-subtle:    var(--surface-subtle);   /* replaces undefined surface-gray */
  --color-surface-raised:    var(--surface-raised);
  --color-surface-ink:       var(--surface-ink);
  --color-border-separator:  var(--separator);

  /* ── Content ─────────────────────────────────────────── */
  --color-text-primary:      var(--text-primary);
  --color-text-primary-alt:  var(--text-primary-alt);
  --color-text-secondary:    var(--text-secondary);
  --color-text-tertiary:     var(--text-tertiary);    /* replaces undefined text-tertiary */
  --color-text-inverse:      var(--text-inverse);

  /* ── Status ──────────────────────────────────────────── */
  --color-success-*: …; --color-warning-*: …; --color-error-*: …; --color-info-*: …;

  /* ── Type scale (dashboard parity) ───────────────────── */
  --text-xs: 11px;  --text-sm: 12px;  --text-base: 14px;
  --text-lg: 16px;  --text-xl: 18px;  --text-2xl: 22px;
  --text-3xl: 26px; --text-4xl: 32px; --text-5xl: 40px; --text-6xl: 51px;

  /* ── Spacing / radius ────────────────────────────────── */
  --spacing-section: 100px; --spacing-component: 30px;
  --radius-sm: 4px; --radius-md: 6px; --radius-lg: 8px;

  /* ── Screens ─────────────────────────────────────────── */
  --breakpoint-xs: 480px;  /* must be preserved — storefront + dashboard both rely on it */
}

/* Dashboard theme (light + dark) */
.theme-dashboard           { --surface-base:#FFFFFF; --surface-subtle:#F5F5F5; --text-primary:#333333; --text-secondary:#484848; --text-tertiary:#6B6B6B; --separator:rgba(183,183,183,.11); }
.theme-dashboard.dark      { --surface-base:#121212; --surface-subtle:#1E1E1E; --text-primary:#F5F5F5; --text-secondary:#B7B7B7; --separator:rgba(255,255,255,.10); }

/* Storefront theme (HON-inspired) */
.theme-storefront          { --surface-base:#FFFFFF; --surface-subtle:#F7F7F5; --text-primary:#1F1F1F; --text-secondary:#5A5A5A; --surface-ink:#111111; }
```

**Why this shape:** `--breakpoint-xs: 480px` and the dashboard type scale are preserved verbatim, so Phase 1B changes **zero visuals** while moving to Tailwind v4.

---

## 3. The token bridge (zero-visual-diff upgrade)

Every legacy class must keep working, or the upgrade becomes a redesign. The bridge defines the old names as aliases of the new semantic tokens:

| Legacy class (614 usages) | Count | Bridges to | Fixes |
|---|---:|---|---|
| `text-text-primary` | 164 | `--color-text-primary` | — |
| `text-text-secondary` | 215 | `--color-text-secondary` | — |
| `border-separator` | 143 | `--color-border-separator` | — |
| `ring-text-primary` / `focus:ring-text-primary` | 15 | `--color-text-primary` | — |
| `bg-text-primary` | 10 | `--color-text-primary` | — |
| `bg-text-primary-alt` / `hover:bg-text-primary-alt` | 9 | `--color-text-primary-alt` | — |
| `border-text-primary` | 6 | `--color-text-primary` | — |
| `bg-surface-base` | 5 | `--color-surface-base` | — |
| `bg-surface-ink` | 2 | `--color-surface-ink` | — |
| `text-text-primary-alt` | 1 | `--color-text-primary-alt` | — |
| **`bg-surface-gray` / `hover:bg-surface-gray`** | **23** | `--color-surface-subtle` | ✅ **was undefined → now renders** |
| **`text-text-tertiary`** | **11** | `--color-text-tertiary` | ✅ **was undefined → now renders** |

Implementation: keep a `legacy-bridge.css` in `packages/design-system` that declares the old Tailwind names for one release, alongside a codemod (`text-text-tertiary` → `text-tertiary`) run in a later, purely-mechanical pass. Delete the bridge once grep returns zero.

> ⚠️ **Behaviour change to expect (not a bug):** fixing the 34 no-op classes *will* visibly change those 34 spots — that is the intended correction, but it should be reviewed on a staging URL rather than merged blind, and listed in the PR description so nobody mistakes it for a regression.

---

## 4. TailAdmin v4 alignment

TailAdmin v2.4 ships its own v4-era token set. Rather than adopting theirs, **map them onto ours** so the shell and our modules agree:

| TailAdmin token | Ours |
|---|---|
| `text-title-sm/md`, `text-theme-sm/xs` | `--text-*` scale above (values already match closely) |
| `bg-white` / `bg-gray-*` in shell | `--surface-base` / `--surface-subtle` |
| `text-gray-500` / `text-gray-800` | `--text-secondary` / `--text-primary` |
| `border-gray-200` | `--border-separator` |
| their `brand-*` | our `--color-brand-*` |
| their dark-mode class strategy (`dark`) | ours: `class` — already identical |

The `ThemeProvider` from TailAdmin then toggles `.dark` on `<html>`, and because our tokens are variables, **every existing module gains dark mode without being edited**. That is the single biggest reason to tokenise before Phase 3.

---

## 5. Typography, spacing, motion

- **Dashboard:** Raleway (already the configured sans) — keep, to avoid a font-swap diff.
- **Storefront:** Inter (already wired, including weight 900 for `font-black` headings; the Google Fonts `@import` was moved out of `index.css` because it sat after the `@tailwind` directives and browsers dropped it).
- **Builder:** blocks set type by *role* (`heading-1…4`, `body`, `caption`, `overline`), never by pixel size — so a token change propagates to every built page.
- **Spacing:** keep `section` (100px) and `component` (30px) as named tokens; blocks reference `--spacing-section` rather than `py-24`.
- **Radius:** preserve `sm/md/lg` (4/6/8px) exactly.
- **Motion:** `fade-in` (0.3s), `accordion-down/up` (0.2s) already exist — move keyframes into `@theme`, and add one `prefers-reduced-motion` guard globally.
- **Focus rings:** one `--ring` definition used by both apps; accessibility must not regress during the Tailwind v4 migration.

---

## 6. Component strategy

- **Dashboard:** TailAdmin components (buttons, tables, badges, dropdowns, modals) *wrapped* in our own `ui/` layer, so a future swap doesn't touch 13 modules.
- **Storefront:** keep the 18 shadcn wrappers actually in use (`accordion, badge, breadcrumb, button, card, dialog, input, label, radio-group, select, separator, sheet, sonner, tabs, textarea, toast, toaster, tooltip`) and **delete the 31 unused wrapper files** plus the ~17 unreachable Radix dependencies. Do *not* delete all 27 Radix packages — 10 back live components.
- **Builder:** blocks are composed from these same primitives — never bespoke markup — so builder output and coded pages are literally the same components.
- **Charts:** one chart wrapper (`reports/Chart`) with a shared theme; swap the underlying library in one file when decision D2 lands.

---

## 7. Migration order

1. Publish `packages/design-system` with `tokens.css` + `legacy-bridge.css`; no app changes yet.
2. Point the dashboard at it (Tailwind v4) and confirm **zero visual diff** on all 13 pages, dark mode off.
3. Add TailAdmin `ThemeProvider`; verify dark mode on every module without editing modules.
4. Point the storefront at it; confirm the 23 routes are pixel-identical.
5. Fix the 34 no-op classes and review the diff on staging.
6. Delete the bridge, run the codemod, delete dead wrappers and unused Radix deps.
7. Freeze: the design system becomes a versioned package; both apps + builder pin the same version.

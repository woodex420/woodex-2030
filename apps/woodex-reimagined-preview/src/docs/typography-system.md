# WOODEX Typography System

## Font Base Settings

| Property | Value |
|----------|-------|
| **Font Family** | `'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Helvetica, Arial, sans-serif` |
| **Base Size** | `15px` |
| **Line Height** | `1.6` (body), `1.2` (headings) |
| **Rendering** | `-webkit-font-smoothing: antialiased` |
| **Letter Spacing** | `-0.02em` (headings), `normal` (body) |

### Available Weights

| Weight | Token | Usage |
|--------|-------|-------|
| 300 | `font-light` | Subtle labels, captions |
| 400 | `font-normal` | Body text, descriptions |
| 500 | `font-medium` | Labels, form fields, nav links |
| 600 | `font-semibold` | Sub-labels, badges, table headers |
| 700 | `font-bold` | Headings, card titles, CTAs |
| 800 | `font-extrabold` | Hero headlines, page titles |
| 900 | `font-black` | Display / feature callouts |

---

## Heading System

| Level | Tailwind Classes | Size (desktop) | Weight | Use Case |
|-------|-----------------|----------------|--------|----------|
| **H1** | `text-4xl md:text-5xl lg:text-6xl font-extrabold` | 48–60px | 800 | Page hero titles |
| **H2** | `text-3xl md:text-4xl font-bold` | 36–48px | 700 | Section headings |
| **H3** | `text-2xl md:text-3xl font-bold` | 24–30px | 700 | Sub-section headings |
| **H4** | `text-xl font-bold` | 20px | 700 | Card titles, sidebar headings |
| **H5** | `text-lg font-semibold` | 18px | 600 | Group labels, accordion headers |
| **H6** | `text-base font-semibold` | 15px | 600 | Small section labels |

### Heading Color Tokens

| Context | Token | Class |
|---------|-------|-------|
| Default | `--foreground` | `text-foreground` |
| Muted heading | `--muted-foreground` | `text-muted-foreground` |
| On dark bg | `--primary-foreground` | `text-primary-foreground` |
| Accent heading | `--accent` | `text-accent` |

### Accent Underline

Add `hon-accent-underline` class to any heading for the signature green bar (48px wide, 3px tall).

---

## Body & Supporting Text

| Role | Tailwind Classes | Size | Color Token |
|------|-----------------|------|-------------|
| **Body** | `text-base` | 15px | `text-foreground` |
| **Body large** | `text-lg` | 18px | `text-foreground` |
| **Body small** | `text-sm` | 14px | `text-foreground` |
| **Lead paragraph** | `text-lg md:text-xl text-muted-foreground` | 18–20px | `text-muted-foreground` |
| **Caption** | `text-xs text-muted-foreground` | 12px | `text-muted-foreground` |
| **Label** | `text-sm font-medium` | 14px | `text-foreground` |
| **Helper text** | `text-xs text-muted-foreground` | 12px | `text-muted-foreground` |
| **Error text** | `text-sm font-medium text-destructive` | 14px | `text-destructive` |
| **Price** | `text-2xl font-bold` | 24px | `text-foreground` |
| **Price (sale)** | `text-sm line-through text-muted-foreground` | 14px | `text-muted-foreground` |

---

## Button & UI Typography

### Button Sizes

| Size | Tailwind Classes | Height | Font |
|------|-----------------|--------|------|
| **Default** | `h-10 px-4 py-2 text-sm font-medium` | 40px | 14px / 500 |
| **Small** | `h-9 px-3 text-sm font-medium` | 36px | 14px / 500 |
| **Large** | `h-11 px-8 text-sm font-medium` | 44px | 14px / 500 |
| **Icon** | `h-10 w-10` | 40px | — |

### UI Element Typography

| Element | Classes | Notes |
|---------|---------|-------|
| **Nav link** | `text-sm font-medium` | Uppercase optional via `uppercase tracking-wider` |
| **Utility bar** | `text-xs` | Color: `text-utility-text` |
| **Badge** | `text-xs font-semibold` | Inline with padding |
| **Breadcrumb** | `text-sm text-muted-foreground` | Active item: `text-foreground font-medium` |
| **Tab** | `text-sm font-medium` | Active: accent underline |
| **Table header** | `text-xs font-semibold uppercase tracking-wider` | Color: `text-muted-foreground` |
| **Table cell** | `text-sm` | Default weight |
| **Tooltip** | `text-xs` | Max-width constrained |
| **Toast title** | `text-sm font-semibold` | — |
| **Toast description** | `text-sm text-muted-foreground` | — |
| **Input** | `text-base md:text-sm` | 16px mobile (prevents zoom), 14px desktop |
| **Placeholder** | `text-muted-foreground` | Same size as input |

---

## Responsive Scaling Rules

```
Mobile (< 768px):   base 15px, headings scale down 1 step
Tablet (768-1024px): base 15px, headings at md breakpoint
Desktop (> 1024px):  base 15px, headings at full size
```

### Example

```tsx
<h1 className="text-4xl md:text-5xl lg:text-6xl font-extrabold tracking-tight">
  Hero Title
</h1>
<p className="text-lg md:text-xl text-muted-foreground mt-4">
  Lead paragraph supporting the headline.
</p>
```

---

## Print Typography

| Element | Size | Notes |
|---------|------|-------|
| Invoice title | 22px / 800 | Uppercase, `letter-spacing: 2px` |
| Invoice body | 10–11px | Tighter for A4 fit |
| Table header | 9px / 700 | Uppercase |
| Footer | 7–8.5px | Muted color |

---

## Quick Reference — Copy-Paste Snippets

```tsx
// Hero headline
<h1 className="text-4xl md:text-5xl lg:text-6xl font-extrabold tracking-tight text-foreground">

// Section heading with accent bar
<h2 className="text-3xl md:text-4xl font-bold hon-accent-underline">

// Card title
<h4 className="text-xl font-bold text-foreground">

// Body
<p className="text-base text-foreground leading-relaxed">

// Muted description
<p className="text-sm text-muted-foreground">

// CTA Button text (already handled by Button component)
<Button size="lg">Get Started</Button>

// Badge
<Badge className="text-xs font-semibold">New</Badge>

// Price display
<span className="text-2xl font-bold text-foreground">PKR 45,000</span>
```

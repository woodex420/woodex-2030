# Component Library `[current]`

## Stack
- React 18 + TypeScript 5 + Vite 5
- Tailwind CSS v3 + shadcn/ui (Radix primitives)
- `react-router-dom` v6
- `@tanstack/react-query` (installed, ready for API use)
- `react-hook-form` + `zod`
- `lucide-react` icons
- `sonner` + shadcn `toaster` for notifications

## Directory
```text
src/
├── assets/          # Images (photorealistic hero + section imagery)
├── components/
│   ├── ui/          # shadcn primitives (do not edit)
│   ├── Header.tsx
│   ├── Footer.tsx
│   ├── SeoContentBlock.tsx
│   ├── HeroSlider.tsx
│   └── ...
├── contexts/
│   ├── QuoteContext.tsx   # B2B quote basket state
│   └── CartContext.tsx    # B2C cart state
├── data/
│   ├── seoContent.ts
│   ├── services.ts
│   └── blogPosts.ts
├── hooks/
├── lib/             # utils.ts (cn helper)
├── pages/
└── index.css        # design tokens
```

## Naming Conventions
- Components: `PascalCase.tsx`.
- Hooks: `useThing.ts`.
- Data files: `kebab-case.ts` or grouped `entity.ts`.
- Routes: `kebab-case` in URL, PascalCase page component.

## Rules
- Prefer composition over prop explosion.
- Keep components under ~200 lines; split when exceeded.
- All colour references via semantic tokens.
- Never import from `src/components/ui/*` inside `src/components/ui/*`.

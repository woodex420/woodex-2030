# Third-party notices

| Component | Licence | Notes |
|---|---|---|
| Laravel framework and first-party packages | MIT | https://laravel.com |
| Livewire | MIT | https://livewire.laravel.com |
| Tailwind CSS | MIT | https://tailwindcss.com |
| Preline UI (free components and blocks) | MIT + Preline Fair Use | https://preline.co. Pro blocks are used only with a purchased licence. |
| TailAdmin Laravel (layout patterns, utilities) | MIT | https://github.com/TailAdmin/tailadmin-laravel |
| Lucide icons (inline subset) | ISC | https://lucide.dev |
| Spatie packages (permission, activitylog, medialibrary, settings, backup) | MIT | https://spatie.be/open-source |
| barryvdh/laravel-dompdf, dompdf | MIT / LGPL | PDF generation |
| brick/math | MIT | Exact decimal arithmetic for money |
| Alpine.js, SortableJS, ApexCharts | MIT | Front-end libraries |
| Inter typeface | SIL Open Font License 1.1 | `resources/fonts/Inter-LICENSE.txt` |
| Nohemi typeface | Pangram Pangram licence | Commercial licence to be confirmed (docs/07 P-09) |

## First-party: the v2.1 admin (ported, not third-party)

`public/assets/admin-v21/` is the Woodex admin v2.1 content front end, written for the studio by Imtiaz Ahmad
and maintained in `marketingwoodex-cloud/-marketingwoodex` (`frontend-v1/admin/`, commit `63868e0`, 2026-10-17).
It is **our own code**, not a third-party dependency: the owner confirmed provenance on 2026-10-20. It is
copied here to run inside Woodex OS — the screens are unchanged, the server behind them is this application.

It carries no separate licence file; it is covered by the studio's own copyright, like the rest of this
repository. Every change we make to a copied file is noted in `public/assets/admin-v21/PORT-NOTES.md`.

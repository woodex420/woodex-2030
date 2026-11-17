# Ported v2.1 admin — what is original, what we changed

Source: `marketingwoodex-cloud/-marketingwoodex`, `frontend-v1/admin/`, commit `63868e0` (2026-10-17).
Our own code, recorded in `platform/THIRD-PARTY.md`.

## Copied unchanged

Every `admin-*.js` module in this folder, `admin.css`, `seo-analyzer.js` and `vendor/icons.js`,
`vendor/chart.umd.js`. The modules are the system; the OS runs them as they are.

## Left behind (not copied)

The other 40-odd modules of the full v2.1 admin — CRM, sales, chat, WhatsApp, social, approvals, system.
Woodex OS already owns those workflows (ledger, permissions, CRM links); porting them would replace a
working system with a copy. They can be added later one at a time.

## Added by the port

| File | Why |
|---|---|
| `wx-embed.js` | The adapter: screen from the OS URL, the two API endpoints rewritten to the OS routes, the CSRF token on every request, the OS session instead of their token, and `X-WX-ADM` set to a marker because the copied modules read a token before they boot (the server ignores it) |
| `wx-embed.css` | Hides their sidebar, top bar and sign-in screen; draws the OS strip at the top |
| `resources/views/admin/website/studio.blade.php` | The OS host page: their markup, their stylesheet, the OS scripts in their original order |

Nothing in a copied module was edited. If a module ever needs a change, it is listed here with the reason.

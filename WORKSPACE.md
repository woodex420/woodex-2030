# Workspace map

This sandbox holds the plan repo plus four source/work checkouts. The large clone of `woodex-admin` was **trimmed** to keep the
workspace inside its snapshot budget — the code that matters is intact, the regenerable bulk
(git history, committed screenshots, `dist`, `node_modules`, and duplicate project copies) was removed.

| Path | What it is | State |
|---|---|---|
| `/home/user/woodex-2030` | **This repo** — planning/coordination for the WOODEX platform | plan docs in `docs/`; fixed Arena branch |
| `/home/user/woodex-platform` | New WOODEX monorepo | Dashboard + storefront + shared builder; 55 tests pass; local only, no remote configured |
| `/home/user/woodex-admin` | `woodex420/woodex@woodex-admin` — **the real dashboard + real backend** | trimmed to 1.8 MB: `src/` (13 pages), `supabase/` (16 migrations, 45-table schema, 32 edge functions), `docs/`, 40+ handover reports |
| `/home/user/woodex-reimagined` | `blackibexofficial-blip/woodex-reimagined` — public storefront | full clone; `node_modules` installed; dev server running |
| `/home/user/woodex-ai-dashboard-3.1` | `blackibexofficial-blip/Woodex-AI-Dashboard-3.1` — AI/UX prototype | full clone, 503 KB |

## Restoring a trimmed area

The dashboard clone has no `.git` and no duplicate trees. To get anything back:

```sh
git clone --branch woodex-admin https://github.com/woodex420/woodex.git /tmp/woodex-full
```

Removed on purpose (all regenerable, none of it source of truth):
`.git/`, `.browser_screenshots/` (27 MB), `user_input_files/` (29 MB, incl. a 19 MB PDF),
`imgs/` (11 MB), `dist/`, `node_modules/`, and the duplicate project trees
`woodex-master/`, `woodex-ecommerce/`, `woodex-furniture-mpa/`, `woodex-furniture-v2/`,
`complete-project/`, `apps/` — all documented in `docs/PROJECT-ANALYSIS.md` §1.

## Note on secrets

`/home/user/woodex-admin/.env` and `docs/CREDENTIALS.md` are **copies of files committed to a public
GitHub repo** and contain exposed credentials. They are analysed in `MASTER-PLAN.md` §10 and must be
rotated; do not copy their contents anywhere, and do not paste secret values into chat.

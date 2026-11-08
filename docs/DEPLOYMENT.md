# Deployment

Two targets, both built from the same commit. Pick one per environment; they are
not meant to run side by side.

| | Vercel | Hostinger shared |
|---|---|---|
| Shape | Static frontends + serverless API | **Shape B** (one Node app) or Shape A (static + API elsewhere) |
| Database | Postgres (Neon/Supabase) — required | Postgres via `DATABASE_URL`, or local SQLite in Shape B |
| Realtime | SSE **disabled** (serverless) → dashboard polls | SSE works |
| Entry | `api/index.mjs` | `npm run start:hostinger` |

`main` is the deployable branch. Everything below assumes the full gate is green
on the commit being deployed — see `AGENTS.md` §3.

---

## 0. The gate (run before every deploy)

```bash
npm ci --legacy-peer-deps
npm run qa            # 107/107 on SQLite
npm run qa:pg         # 107/107 on Postgres (PGlite)
npm run typecheck:all
npm run smoke:render
npm run smoke:hostinger   # needs `npm run build` first
npm run secret-scan
```

`npm run vercel:build` is the production build for both targets: it builds the
dashboard, builds the storefront, assembles `dist/`, and prerenders all 76 public
routes. It takes ~18 s and must print `76/76 routes rendered`.

---

## 1. Vercel

### One-time setup

1. **Create the database** (Neon or Supabase). Copy the pooled connection string.
2. **Environment variables** (Production *and* Preview — Vercel does not inherit):

   | Variable | Value |
   |---|---|
   | `DATABASE_URL` | `postgres://…` — required, the app refuses to boot without it on Vercel |
   | `INITIAL_ADMIN_EMAIL` | first owner account |
   | `INITIAL_ADMIN_PASSWORD` | **≥ 16 characters**, used only on first boot |
   | `NODE_ENV` | `production` |

3. **Create the schema once** against the new database:

   ```bash
   DATABASE_URL="postgres://…" npm run db:apply-schema
   ```

   Then `npm run db:migrate -- --dsn "$DATABASE_URL"` if you are bringing across
   existing data (`scripts/pg-migrate.mjs` copies every table by explicit id,
   resets identities and verifies per-table digests).

4. Set the public site URL in the database so the sitemap and canonical links are
   absolute: **Dashboard → Settings → site URL**. It falls back to
   `http://localhost:5174`, which would be published to Google.

### Deploy

`vercel.json` drives it: `vercel:build`, output `dist/`, and rewrites that send
`/api/*` to the serverless function, `/dashboard/*` to the dashboard shell, and
everything else to the storefront shell.

Two things about that config are load-bearing:

- **`/sitemap.xml` is rewritten to `/api/sitemap`.** Vercel serves static files
  *before* rewrites, so a static `dist/sitemap.xml` would shadow the API's
  complete sitemap. The prerenderer therefore does **not** write one.
- **`/api/*` must never be rewritten to the SPA shell.** If the API returns HTML,
  every dashboard request fails with a JSON parse error.

### Verify after deploy

```bash
curl -sI https://<domain>/ | head -1                 # 200 HTML
curl -s  https://<domain>/about | grep '<title>'     # real title, not "Loading page"
curl -s  https://<domain>/sitemap.xml | grep -c '<loc>'   # ~165
curl -s  https://<domain>/api/health                 # {"ok":true,…}
```

### Known constraint

SSE is disabled when `VERCEL === "1"` (`/api/events` returns `410` by design).
The dashboard detects this and falls back to polling. Nothing needs configuring;
just do not "fix" the 410.

---

## 2. Hostinger — Shape B (recommended)

One Node process serves everything: prerendered pages, dashboard, API, imagery,
sitemap.

### Build and upload

```bash
npm ci --legacy-peer-deps
npm run vercel:build          # → dist/ (76 prerendered routes)
```

Upload to the panel:

```
public_html/            ← contents of dist/
public_html/.htaccess   ← copy deploy/hostinger/.htaccess
```

Put the app itself **outside** `public_html` (e.g. `~/woodex-app/`) so source is
not web-reachable, then install and start it as the panel's Node application:

```bash
cd ~/woodex-app && npm ci --legacy-peer-deps --omit=dev
PORT=3000 WOODEX_SERVE_STATIC=~/woodex-app/dist \
  WOODEX_DATA_DIR=~/woodex-app/data \
  node --experimental-sqlite apps/api/server.mjs
```

`npm run start:hostinger` is the same command for a local rehearsal.

| Variable | Purpose |
|---|---|
| `WOODEX_SERVE_STATIC` | path to `dist/`; enables all-in-one serving |
| `WOODEX_DATA_DIR` | where `woodex.db` lives — **must be writable and persistent** |
| `DATABASE_URL` | set this to use Postgres instead of SQLite |
| `INITIAL_ADMIN_EMAIL` / `INITIAL_ADMIN_PASSWORD` | first boot only |

### SQLite caveat, stated plainly

Shape B with SQLite is single-writer. The app serialises writes through an
internal mutex, so it is correct, but throughput is one write at a time. For a
furniture retailer that is fine. If you expect concurrent staff editing, use
`DATABASE_URL` with Postgres and keep the same Shape B process.

### Verify after deploy

```bash
npm run smoke:hostinger   # locally, against the same dist/ you uploaded
curl -sI https://<domain>/about | head -1
curl -s  https://<domain>/api/health
```

---

## 3. Hostinger — Shape A (static only)

Only if the API must live elsewhere. Build the frontends with an absolute API
origin, because there is no `/api` on the static host:

```bash
VITE_API_BASE=https://api.example.com npm run build
```

Upload `dist/` + `.htaccess` as above; stop the Node app. `/api/*` then 404s on
this host, which is expected — the frontend talks to `VITE_API_BASE` directly.
**Realtime and the signed preview iframe both need that origin to send CORS
headers and allow the storefront origin**, so check `curl -H 'Origin: https://<domain>' -I https://api.example.com/api/health` before going live.

---

## 4. Backups

Nothing else in this document matters if this section is skipped.

```bash
npm run db:backup                      # → backups/woodex-<timestamp>.db.gz
npm run db:verify-backup -- <file>     # restores, integrity-checks, compares counts
```

`db:backup` uses `VACUUM INTO`, which produces a consistent snapshot while the
API is serving. A plain file copy can capture a torn write — do not substitute
`cp`.

- **Schedule** it (cron/Plesk task) at least daily and keep copies **off** the
  server: `node scripts/backup.mjs --out /backups/woodex-$(date +\%F).db.gz`.
- **Verify** the newest backup weekly; the QA suite already asserts that a
  fresh backup restores and matches live row counts.
- **Postgres:** use the provider's PITR (Neon/Supabase both offer it) *and*
  `pg_dump` for portability:
  `pg_dump "$DATABASE_URL" -Fc -f woodex-$(date +%F).dump`
- `backups/` is gitignored. Never commit a database — it contains customer
  contact details.

---

## 5. After any deploy

1. `curl` the four checks for your target above.
2. Log in as the owner and confirm the dashboard loads real numbers.
3. Run `npm run smoke:hostinger` (Shape B) or the Vercel `curl` set.
4. Check `/sitemap.xml` is the API's (compare `<loc>` count to the gate output).
5. If you changed the schema, `npm run qa:pg` on the target database before
   announcing the deploy.

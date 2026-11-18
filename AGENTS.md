# AGENTS.md — working rules for this repository

Any agent (human or machine) picking up this repo **must** read this file first.
It exists because several sessions have lost hours to the same handful of traps.
They are not opinions; each one below cost real debugging time.

---

## 1. Read order

1. **This file.**
2. `docs/woodex-os/12-DECISION-LOG.md`, then `docs/woodex-os/00-MASTER-PLAN.md` — **the direction since
   2026-11-16**: one Laravel admin (`platform/`), with this repository's React dashboard and Node API
   migrated into it module by module. The owner's latest recorded decision wins over every document.
3. `docs/product/MASTER-PLAN.md` — the plan the React/Node system was built to. Still the reference for how
   that system works and is released; no longer the destination.
4. `docs/README.md` — the index. Anything under `docs/archive/` is history, not instruction.

Never start from a random doc you found by grepping. There are 56 markdown files and
several are superseded.

---

## 2. The six traps

### Trap 1 — Duplicate React (or Vite) gives a blank page with a green build

React keeps its state in a module-level global. If two copies of React exist, a hook
reads state the other copy never created, gets `null`, and the first render throws.
The page goes white. `vite build` passes. `curl` returns 200. Every test that only
talks to the API passes. **You see nothing wrong until you look at pixels.**

This repo already shipped this bug once: storefront declared `react@^18.3.1`, dashboard
`^19.2.0`, npm hoisted 19 and nested 18 inside `apps/storefront`.

**Rule:** both apps declare the same React major, and `npm run smoke:render` must pass.
Never add a dependency that pins a different React major. Anchor the version in
`package.json` (`"react": "^19.2.0"`), not a caret-free range that drifts.

### Trap 2 — `JSDOM.fromURL` cannot prove a page renders

jsdom never executes `<script type="module">`. A jsdom page with an empty `#root`
proves *nothing* — it would be empty even for a perfect app.

**Rule:** use `npm run smoke:render`, which bundles the real entry with esbuild
(`format: "iife"`) and evaluates it in a jsdom window. If you write a one-off probe:
- bundle with esbuild, do not use `fromURL`;
- shim `matchMedia`, `ResizeObserver`, `IntersectionObserver`, `requestAnimationFrame`;
- define `import.meta.env` (esbuild leaves it `undefined`);
- re-define `globalThis.fetch` **and** make sure your shim does not call itself
  (infinite recursion presents as "Maximum call stack size exceeded");
- relative URLs fail in Node's `fetch` — resolve against the dev server origin.

### Trap 3 — Never regex-rewrite across `.tsx` with a bare word

`res.json(...)` → `return {...}` style bulk rewrites look fine and silently corrupt
string literals and JSX text. One such rewrite took a full session to repair.

**Rule:** restrict identifier replacement to non-string contexts, or edit files
individually. Prefer `edit_file` over `sed`/regex for anything with a `.` or `(`
in it.

### Trap 4 — `pkill` in the same shell call as a launch kills the new process

`pkill -f "server.mjs" && npm run dev` matches and kills the process you just
started (exit −1, no useful output). Split into two calls, or use the process tools.

### Trap 5 — Never point the workspace at a hosted database

Local servers run on **SQLite** (`data/woodex.db`) or PGlite. Vercel runs Postgres.
Putting a hosted URL in a local env silently mutates production data. The API
refuses to boot on Vercel without `DATABASE_URL`; respect that split.

---

### Trap 6 — Prerender can "succeed" while shipping an empty shell

`scripts/prerender.mjs` renders the static public routes in jsdom and writes
`dist/<route>/index.html`. Three failure modes all report **16/16 routes
written** while the HTML contains nothing:

- **`React.lazy` never settles.** Every route is lazy-loaded, and inside a single
  `w.eval` there is no module loader to resolve the dynamic import, so the page
  stays on `Loading page…`. The bundle must rewrite `lazy(() => import("x"))`
  into a static import — that is what the `prerender-eager-lazy` esbuild plugin
  does. If the regex stops matching, every page silently becomes a 529-byte
  fallback.
- **Polling for the wrong thing.** The toast portal mounts immediately, so
  `root.children.length > 0` is true before the route commits. Wait for
  `children.length > 1` **and** the absence of `Loading page`.
- **Replacing a root that is not there.** Matching the literal
  `<div id="root"></div>` no-ops when the shell is a previous prerender. The
  swap is anchored on `</body>` instead, and re-running strips old JSON-LD so
  structured data does not double.

**Product pages are not prerendered.** The catalogue lives in the database and a
build has no database, so the 16 routes are the static pages of
`apps/api/public-routes.mjs` and nothing else. (It used to be 76: the extra 60
were demo products from a list compiled into the storefront.) The prerender
window sets `__WOODEX_PRERENDER__ = true`; the storefront reads it and leaves
the catalogue "loading", so a static page carries the loading placeholder.
Remove that flag and every static page that lists products says the catalogue
is unavailable — the stubbed `fetch` answers 503, which is what "unavailable"
means in a real browser.

Guard when you touch it: `PRERENDER_ONLY=/about node scripts/prerender.mjs`
renders one route in under a second. Assert the root is **> 5 kB** and does not
contain `Loading page` before believing a run. Always `process.exit(0)` at the
end — leftover jsdom handles otherwise hang the build for minutes after the
work is done.

## 3. Definition of done

A change is not finished until this passes, in this order:

```bash
npm run secret-scan          # no credentials in the working tree: tokens, and passwords written as prose
npm run typecheck:all        # both apps, 0 errors
npm run smoke:render         # both apps actually mount
npm run qa                   # every check passes against the running API (needs one on :3001)
npm run qa:pg                # the same battery on PGlite
npm run test:modules         # every module suite, on SQLite AND on PGlite
npm run test:return-id       # every insert reports its new id on PostgreSQL
npm run test:migrate         # the SQLite → PostgreSQL copy moves every row, or changes nothing
npm run vercel:build         # production shape; must print "16/16 routes rendered"
npm run smoke:hostinger      # all-in-one shape: real HTML, not the Loading shell
```

Several of these exist because the same class of bug shipped more than once:

- **`qa:pg` is not optional.** SQLite is the dev database and it tolerates things
  Postgres does not: `GROUP_CONCAT` does not exist, and `MAX(a, b)` is a scalar in
  SQLite but an aggregate in Postgres. Both were caught here and nowhere else.
- **`qa:pg` is not enough either — that is what `test:modules` is for.** `qa:pg`
  runs the core battery; it does not call the module endpoints (procurement,
  production, inventory, finance, CRM, delivery, support). Every module suite
  used to pin SQLite, and with this whole list green, ten of eleven failed on
  PostgreSQL: inserts into 41 tables reported id 0, and four statements were
  valid on SQLite only. A new module suite starts its server through
  `scripts/lib/api-under-test.mjs` — never `DATABASE_URL: ""`, never
  `new DatabaseSync` — and is added to `SUITES` in `scripts/test-modules.mjs`.
- **A new table with an integer id goes in `RETURN_ID_TABLES`**
  (`apps/api/db-adapters.mjs`). `test:return-id` compares that list with the
  database catalogue and fails in both directions, so it cannot be forgotten.
- **`test:migrate` counts rows itself.** The copy tool once printed `ALL GREEN`
  after emptying nine tables, because it verified each table as it went and
  never looked again. Do not trust a tool's own report of its success; count.
- **`smoke:hostinger`** boots the single-process deployment on a throwaway port
  and database and asserts the prerendered routes serve real HTML. A static server
  will happily serve a build whose prerender silently failed. It also fetches
  every script and stylesheet the two HTML shells name: for a long time it
  checked the dashboard's shell only, and passed while the server answered the
  dashboard's own bundle with HTML — a blank page in that deployment shape.
  **A check that fetches a page must also fetch what the page loads.**

`npm run e2e` (browser journeys) and `npm run e2e:routes` additionally require
Chromium: `npx playwright install chromium`. CI runs both, after the gates above,
through `node scripts/ci-test.mjs` — which opens every screen twice: on the dev
server, and in the built bundle served from `dist/` (so run `vercel:build` first).

- **`e2e:routes` signs in and opens every dashboard screen** declared in
  `apps/dashboard/src/App.tsx`. It fails on an error card, an uncaught error, an
  API error, a missing file, a console error or an empty page. It exists because
  two screens crashed on open with everything else green: nothing opened them.
  It opens screens; it does not press their buttons.
- **`e2e:catalog` is the catalogue journey**, shop and admin together, in a
  browser: the catalogue arrives from the API, a product without a price never
  reads "PKR 0", an option is carried from the product page to the order, an
  old cart is reconciled with what the catalogue says today, a refused order
  says why, and the real catalogue is loaded as drafts and released in the
  admin. By default it serves `dist/` itself (port `E2E_CATALOG_PORT`, 4183) on
  a database of its own. `ci-test.mjs` runs it after the built-routes step
  (port `CI_CATALOG_PORT`). It was checked by breaking the storefront on
  purpose, nine ways; each made it fail.
- **The storefront shows what the database holds, and nothing else.** No
  product is compiled into it. `apps/storefront/src/data/demo-products.ts` is
  read by the seed build only, and `smoke:render` fails if a demo product is
  found in the storefront bundle. When the API cannot be reached the shop says
  so; it never falls back to a list of its own.
- **`test:catalog`** (one of the module suites, both engines) is the server
  side of the same rules: drafts are invisible, one SKU names one thing, no
  price is not a price of zero, an order and a quotation are priced by the
  catalogue whatever the page sent, and a database that has not received
  migration 019 keeps working as before. A price check must **send** a price:
  the suite once passed against a server that priced lines from the request,
  because it never sent one.
- **A browser script signs in through `signInToDashboard()`**
  (`scripts/lib/browser.mjs`), never by typing straight after `goto`. The
  sign-in form fills in the demo account when the API answers; typing in the
  same instant lost one sign-in in 120. The form now keeps what was typed and
  marks itself `aria-busy` until it has heard from the API; the helper waits
  for that.
- **Playwright's Chromium cannot be installed in the Arena sandbox** — the
  download host is blocked. Without a browser the scripts print `SKIP` and exit
  0 (or `ERROR` and exit 1 with `E2E_REQUIRED=1`, as in CI). Do not treat a skip
  as a pass.
- A Chromium that is already on disk can be used instead: set
  `E2E_CHROMIUM_PATH` (and `E2E_CHROMIUM_ARGS`), which every browser script
  reads through `scripts/lib/browser.mjs`. See
  [`docs/furniture-2030/evidence/README.md`](docs/furniture-2030/evidence/README.md)
  for the way that worked in the sandbox on 2026-11-16.

---

## 4. Map: what is load-bearing

| Path | Rule |
|---|---|
| `apps/api/server.mjs` | Single file, ~3k lines. Routes are appended, never reordered. Schema bootstrap is at the top — new tables go there **and** in `scripts/schema.postgres.sql`. |
| `apps/api/db-adapters.mjs` | `RETURN_ID_TABLES` must list every table whose `INSERT` needs its id back. Miss it and Postgres silently returns `0`. |
| `scripts/schema.postgres.sql` | The Postgres source of truth. PGlite tests and Vercel both depend on it. |
| `apps/dashboard/src/lib/auth.ts` | Patches global `fetch`: attaches the token, and turns any 401 on `/api/*` into a sign-out. |
| `apps/dashboard/src/lib/api.ts` | `useApi` / `useRealtime`. The SSE transport lives here. |
| `apps/storefront/src/lib/theme.ts` | Type the snapshot explicitly — TS narrows `resolved` to the literal `"light"` otherwise and the dark-mode toggle becomes dead code. |
| `apps/api/public/img/` | The only copy of photography, served at `/img/*`. Never duplicate into an app. |
| `scripts/qa-full.mjs` | The acceptance suite. Add tests with the feature, not after. |

---

## 5. House rules that keep the build honest

- **No invented data in the UI.** If the API does not return a number, render `—` or
  an empty state. A hardcoded `12,400` or `↑24%` on a real screen is a bug, not a
  placeholder. Search for `@/data/mock` before shipping any page.
- **Server computes money.** Client-supplied `total`, `lineTotal`, `subtotal` are
  ignored and recomputed. There are QA tests that assert this; keep them passing.
- **`wrapTxJson` handlers take `(req, afterCommit)`.** Effects pushed to
  `afterCommit` run after COMMIT. Pushing to a missing array throws a 500.
- **One renderer.** The page builder must not maintain a second, simplified copy of
  the storefront renderer — that is how previews start lying.
- **Realtime is polling *and* SSE.** The stream is the fast path; the poll is the
  floor for serverless. Do not remove one without the other.

---

## 6. The Laravel application — `platform/`

WOODEX OS, the admin that everything is moving into (§1). Its record is `docs/laravel-os/`; the programme is
`docs/woodex-os/`. Sections 2–5 above are about `apps/` and `scripts/` and still apply to every change there.

- **What is open** is one list: `docs/woodex-os/14-PRODUCTION-TODO.md`, lines `T-001` onward, each with a
  priority (P0 to P3), who acts, and what "done" means. Change a line's status in the same commit as the
  work. Its sources are in `docs/woodex-os/15-SPECIFICATION-ANALYSIS.md`. The production host is Hostinger
  shared hosting with MySQL (DL-020); Vercel carries a preview workspace only.
- **Acceptance and release gates** are `docs/woodex-os/16-ACCEPTANCE-AND-RELEASE-GATES.md`. Its tables are
  written from the five files in `docs/woodex-os/acceptance/` by `node scripts/verify-acceptance.mjs --write`;
  CI runs the same script as a check. If you rename a test or a journey step that is named as proof there,
  rename it there too. Enter every finding of your round in `acceptance/defects.json` with its severity, and
  write a release report with the ten headings of document 10 §9 (`17-RELEASE-2026-11-17.md` is the model).
- **What is pending, in which order, and how each line is tested** is
  `docs/woodex-os/21-PENDING-WORK-PLAN-AND-TESTS.md`. Its tables are written by
  `node scripts/verify-plan.mjs --write` from the rows of the list and from
  `docs/woodex-os/acceptance/plan.json`; CI runs the same script as a check, and
  `node scripts/test-verify-plan.mjs` tries the check itself. Take the lines in the order of its rounds unless
  the owner says otherwise. When a line is done: mark it done in the list, take it out of its round and its
  title out of `plan.json`, give a round to every line it sets free, and run the script with `--write`. A line
  enters a round only with what is built, its tests, what the tests are tried against and the way back.
- **Furniture only.** The code came from the Woodex Interior application. One setting names the lines an
  installation sells: `WOODEX_BUSINESS_LINES`, default `furniture` (DL-021). With one line there is no line
  chooser, filter or column, and a line that is not sold is refused. The test suite runs with all three lines;
  a test of the Furniture-only admin calls `sellFurnitureAlone()`. Do not add Interior wording, defaults or
  sample content to a screen that is open; what is left of it is lines T-033 and T-034.
- **The real catalogue** (170 products, DL-022) is `platform/resources/data/woodex-catalogue.json`, built by
  `node scripts/build-woodex-catalogue.mjs` and loaded by `php artisan commerce:import-catalogue`. It loads
  drafts only: nothing in the code may publish a product by itself.
- **Done, for a change under `platform/`:** its test suite passes **on MySQL 8.4 in CI**, and on MariaDB
  10.11 and 11.4 beside it, which is what the production host gives (workflow `os-ci.yml`). That is the
  authority. In a sandbox it runs on SQLite:
  `bash platform/tools/preview/sandbox/setup.sh` builds PHP, Composer, the packages and the rehearsal in
  about a minute where only GitHub and npm are reachable (`platform/tools/preview/README.md` says what it
  does). With the older static PHP build nine tests cannot run (they need `pdo_mysql` or `xmlwriter`); read a
  sandbox run with `tools/preview/sandbox/suite-summary.py`, which excuses a failure only by its cause, never
  by its name.
- **A release for the host** is a folder the host can run as it is: `platform/tools/hostinger/build-release.sh`
  (the bridge of the website's folder, the application with its packages and built files, no settings, no
  data). CI carries it through Git, as a deployment does, installs it in a browser on Apache with the folder's
  own `.htaccess` and MariaDB, and deploys it a second time (job `hostinger` of `os-ci.yml`; the browser is `scripts/os-install.mjs`). That job is the proof
  for a change to the installer, the root `.htaccess` or `index.php`, System health, signing in or out. In a
  sandbox `platform/tools/hostinger/rehearse.sh` serves the same folder without Apache. From `main` only, the
  job `publish` writes the release to the branch `hostinger-release`: never push to that branch by hand. The
  owner's steps on the host are `docs/woodex-os/24-HOSTINGER-STEP-BY-STEP.md`; nothing has been seen on the
  account itself.
- **A page may run only what the policy allows** (`App\Support\ContentPolicy`, sent by `SecurityHeaders`). A
  `<script>` written into a view needs `nonce="{{ Vite::cspNonce() }}"`; an `onclick=` or any other handler
  in the markup never runs — use Alpine (`@click`); a script, font or frame from another server is refused.
  `tests/Feature/Security/ContentPolicyTest.php` reads every screen of the menu for this, and the crawl and the
  journeys fail on anything the browser refuses.
- **Every answer carries a request number** (`X-Request-Id`, `App\Support\RequestNumber`), every error page
  shows it, and every log line of that request has it. The log is one JSON object a line. A log line must never
  hold a query string, a token, a value typed into a form, an e-mail or an IP address: write the route's pattern
  and the numbers of records (`App\Support\RequestLog` shows how;
  `tests/Feature/Observability/RequestNumberTest.php` pins it).
- **Never run it against a real database.** The preview refuses anything but a SQLite file named
  `woodex-synthetic-*`. Trap 5 applies here too.
- **One navigation registry:** `app/Support/AdminNavigation.php`. A new screen is added there, once, with an
  id and a permission. The sidebar shows only what can be opened; what cannot is told on the Feature status
  screen. Do not put a disabled or "Soon" entry in the sidebar. The registry tests assert exact counts on
  purpose, and one test opens every sidebar screen for four roles.
- **Colours are roles** (`--wx-*`) and scales in `resources/css/admin.css`; a view names a role or a scale
  step, not a hex value. `tests/Unit/AdminDesignTokensTest.php` fails below WCAG AA. The logo green
  `#4f9d21` carries no white text (3.4 : 1); `brand-500` does.
- **`public/build` is committed** — the production host has no Node.js. After changing CSS or JavaScript run
  `npm ci && npm run build` in `platform/` and commit the new files with the old ones removed.
- **A changed requirement changes a test; it does not delete one.** When the roadmap left the sidebar, the
  tests that expected "Soon" entries were rewritten to expect them on Feature status.
- The root `index.php` and `.htaccess` are the Hostinger entry of this application. Vercel does not use them.
- **Vercel** (`docs/woodex-os/13-VERCEL-DEPLOYMENT.md`): the entry is `platform/api/index.php`, and
  `deploy/vercel-livewire/` holds byte-identical copies of it and of `vercel.json`. Today it serves a demo
  with sample data (`WOODEX_DEMO=1` in the Vercel project, never in the repository). Before changing the
  entry, `vercel.json`, the fixtures or anything in `platform/public`, run
  `platform/tools/vercel/check-entry.sh`: it rehearses the deployment with Vercel's own PHP runtime and
  launcher from npm. A new folder in `platform/public` needs a route in `vercel.json`, or its files answer 404
  there. That same PHP (8.5, with `pdo_mysql`, `exif` and `xmlwriter`) also runs the nine tests the static
  sandbox PHP cannot: `platform/tools/preview/README.md`.

---

## 7. If you are stuck

1. Reproduce in `npm run smoke:render` before theorising.
2. Check `git log --oneline -20` — the recent commits document what was just fixed.
3. Read the relevant audit in `docs/audits/`.
4. When a probe says the app is broken, **verify the probe** first. In this repo a
   broken harness has twice been mistaken for a broken app.

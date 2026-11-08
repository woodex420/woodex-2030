# AGENTS.md — working rules for this repository

Any agent (human or machine) picking up this repo **must** read this file first.
It exists because several sessions have lost hours to the same handful of traps.
They are not opinions; each one below cost real debugging time.

---

## 1. Read order

1. **This file.**
2. `docs/product/MASTER-PLAN.md` — the only plan. If a doc disagrees with it, the plan wins.
3. `docs/README.md` — the index. Anything under `docs/archive/` is history, not instruction.

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

`scripts/prerender.mjs` renders the public routes in jsdom and writes
`dist/<route>/index.html`. Three failure modes all report **76/76 routes
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

Guard when you touch it: `PRERENDER_ONLY=/about node scripts/prerender.mjs`
renders one route in under a second. Assert the root is **> 5 kB** and does not
contain `Loading page` before believing a run. Always `process.exit(0)` at the
end — leftover jsdom handles otherwise hang the build for minutes after the
work is done.

## 3. Definition of done

A change is not finished until this passes, in this order:

```bash
npm run secret-scan          # no credentials committed
npm run typecheck:all        # both apps, 0 errors
npm run smoke:render         # both apps actually mount
npm run qa                   # 107/107 against the running API (needs one on :3001)
npm run qa:pg                # 107/107 on PGlite — catches every Postgres-only bug
npm run vercel:build         # production shape; must print "76/76 routes rendered"
npm run smoke:hostinger      # all-in-one shape: real HTML, not the Loading shell
```

Two of these exist because the same class of bug shipped twice:

- **`qa:pg` is not optional.** SQLite is the dev database and it tolerates things
  Postgres does not: `GROUP_CONCAT` does not exist, and `MAX(a, b)` is a scalar in
  SQLite but an aggregate in Postgres. Both were caught here and nowhere else.
- **`smoke:hostinger`** boots the single-process deployment on a throwaway port
  and database and asserts the prerendered routes serve real HTML. A static server
  will happily serve a build whose prerender silently failed.

`npm run e2e` (browser journeys) additionally requires Chromium:
`npx playwright install chromium`. **Chromium cannot be installed in the Arena
sandbox** — the download is blocked. The script skips cleanly; do not treat a skip
as a pass, and do not plan work that depends on it locally.

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

## 6. If you are stuck

1. Reproduce in `npm run smoke:render` before theorising.
2. Check `git log --oneline -20` — the recent commits document what was just fixed.
3. Read the relevant audit in `docs/audits/`.
4. When a probe says the app is broken, **verify the probe** first. In this repo a
   broken harness has twice been mistaken for a broken app.

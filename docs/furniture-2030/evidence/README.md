# Evidence

> **After Phase 1 (2026-11-16).** The scripts in this folder were written to show the audit's findings on commit `9b8cdca`. Those findings are now fixed, so on the current code most of these scripts no longer show a defect, and two of them print something misleading. Read [the section on that](#these-scripts-after-phase-1) before running any of them. The checks that replaced them are part of the repository's gate and are listed there.

Every figure quoted in [`../02-AUDIT-FINDINGS.md`](../02-AUDIT-FINDINGS.md) can be reproduced with the scripts in this folder. They read the code and use throwaway databases under the operating system's temporary folder. None of them changes the repository, and none of them may be pointed at a hosted database.

Run them from the repository root after `npm ci --legacy-peer-deps`. Node 22.5 or newer is required.

## Scripts

| Script | What it shows | Finding |
|---|---|---|
| `return-id-gap.mjs` | Which tables have their generated id read back by the API, and which of those are missing from `RETURN_ID_TABLES` | F-02 |
| `pg-module-harness.mjs` | The repository's module tests run on PostgreSQL semantics (PGlite), with only the database pin changed | F-02, F-03 |
| `migrator-truncate-order.mjs` | Which tables `pg-migrate.mjs --truncate` empties, derived from the copy order and the foreign keys | F-04 |
| `migrator-rowcount-probe.mjs` | Row counts in the SQLite source and the PostgreSQL target after a `--truncate` migration | F-04 |
| `migrator-no-truncate.mjs` | The exit code and error of the same migration without `--truncate` | F-04 |
| `generate-inventory.mjs` | Regenerates [`../inventory/`](../inventory/api-routes.md) from the code | F-06, F-08, F-09 |
| `browser/check.mjs` | Loads the dashboard and storefront in a headless Chromium the way the preview serves them | F-05, F-14 |

```bash
node docs/furniture-2030/evidence/return-id-gap.mjs
node docs/furniture-2030/evidence/pg-module-harness.mjs
node docs/furniture-2030/evidence/migrator-truncate-order.mjs
node --experimental-sqlite docs/furniture-2030/evidence/migrator-rowcount-probe.mjs
node --experimental-sqlite docs/furniture-2030/evidence/migrator-no-truncate.mjs
node --experimental-sqlite docs/furniture-2030/evidence/generate-inventory.mjs --db <a seeded woodex.db>
```

On the audited commit `pg-module-harness.mjs` exits 1, because F-02 was open. Phase 1 replaced it with a proper two-database test run, `npm run test:modules`.

### Reading the harness output

A failed check that mentions `no such table` or `DatabaseSync` comes from a test opening the SQLite file directly. That is an artefact of running a SQLite-shaped test on PostgreSQL, not an application defect. A failed check with HTTP 500 or an id of 0 is an application defect.

### The browser check

`browser/check.mjs` is not part of the repository's dependencies. It was run in a scratch folder with two packages installed there, because Playwright's browser download is blocked in the audit sandbox:

```bash
mkdir /tmp/previewcheck && cp docs/furniture-2030/evidence/browser/*.mjs /tmp/previewcheck && cd /tmp/previewcheck
npm init -y && npm i puppeteer-core @sparticuz/chromium
mkdir -p tls shots && openssl req -x509 -newkey rsa:2048 -nodes -keyout tls/key.pem -out tls/cert.pem -days 2 \
  -subj "/CN=preview-sim" -addext "subjectAltName=DNS:*.e2b.app,DNS:arena-parent.test"
AWS_EXECUTION_ENV=AWS_Lambda_nodejs22.x AWS_LAMBDA_JS_RUNTIME=nodejs22.x node check.mjs
```

It expects the API on port 3002, the dashboard on 3001 and the storefront on 5174 (`npm run dev:preview:*`). `sim-proxy.mjs` stands in for the preview proxy: HTTPS, a preview-style host name, and a second site that embeds the dashboard in an iframe. The certificate is throwaway and is never stored. In CI the same check should use the Playwright browser the workflow already installs.

## These scripts after Phase 1

| Script | On the audited commit `9b8cdca` | On the current code | Replaced by |
|---|---|---|---|
| `return-id-gap.mjs` | 30 tables missing | Reports `stock_levels` as missing. That is wrong: the script matches an id read to the nearest insert above it in the source text, and the id read there belongs to the `stock_movements` insert further up. `stock_levels` has no integer id. | `npm run test:return-id`, which asks the database instead of reading source text |
| `pg-module-harness.mjs` | 1 of 11 suites passes | "could not retarget the test" for every suite. The suites no longer pin a database, so there is nothing to retarget. | `npm run test:modules` |
| `migrator-truncate-order.mjs` | 3 pairs in the wrong order | Still prints 3 pairs, because it reads the order of the table list. The tool no longer uses that order and no longer empties tables one by one, so nothing is lost. The script also undercounts: the database has 86 foreign keys and 4 such pairs; reading the schema text finds 59 and 3. | `npm run test:migrate` |
| `migrator-rowcount-probe.mjs` | 9 tables differ after a "successful" copy | 0 tables differ | `npm run test:migrate` |
| `migrator-no-truncate.mjs` | Exit 1 on a duplicate key, halfway through | Exit 1 with a refusal before anything is changed. That is now the intended behaviour. | — |
| `generate-inventory.mjs` | — | Still valid: it reads the code. | — |
| `browser/check.mjs` | 36 of 38 menu routes render | Still runs. | `npm run e2e:routes`, which is in the repository, runs in CI, and takes its route list from the router |

To see the original results again, check out `9b8cdca` in a separate folder, copy this folder to the same path there, and run the scripts in that checkout.

### A browser for the browser checks, in a sandbox

Playwright's own Chromium cannot be downloaded in the Arena sandbox. The npm registry can be reached, and the package `@sparticuz/chromium` carries a Chromium build. This is what worked on 2026-11-16 (version 153.0.0, Node 22):

```bash
mkdir -p /tmp/chromium-kit && cd /tmp/chromium-kit && npm init -y >/dev/null && npm i @sparticuz/chromium
export AWS_EXECUTION_ENV=AWS_Lambda_nodejs22.x AWS_LAMBDA_JS_RUNTIME=nodejs22.x
node -e 'import("@sparticuz/chromium").then(async (m) => console.log(await m.default.executablePath()))'   # unpacks to /tmp/chromium
```

Then, in the repository:

```bash
export AWS_EXECUTION_ENV=AWS_Lambda_nodejs22.x AWS_LAMBDA_JS_RUNTIME=nodejs22.x
export LD_LIBRARY_PATH=/tmp:/tmp/al2023/lib:$LD_LIBRARY_PATH FONTCONFIG_PATH=/tmp/fonts
export E2E_CHROMIUM_PATH=/tmp/chromium
export E2E_CHROMIUM_ARGS="--no-sandbox --disable-setuid-sandbox --disable-dev-shm-usage --use-gl=angle --use-angle=swiftshader --enable-unsafe-swiftshader"
BASE=http://127.0.0.1:5173 npm run e2e:routes      # needs the API and the dashboard running
node scripts/ci-test.mjs                           # the whole CI script; needs port 5174 free
```

Install the package outside the repository so that it never reaches `package.json`. CI does not need any of this: it installs Playwright's Chromium.

## Results after Phase 1, 2026-11-16

| File | Content |
|---|---|
| `results/phase1-gate-2026-11-16.tsv` | The full gate on the Phase 1 branch, including the three new checks |
| `results/phase1-modules-both-engines-2026-11-16.json` | 12 module suites on SQLite and on PGlite: 662 checks each, 0 failed |
| `results/phase1-dashboard-routes-2026-11-16.json` | 43 of 43 dashboard screens open without an error |
| `results/phase1-dashboard-routes-on-main-2026-11-16.json` | The same check against `main` at `9b8cdca`: it fails on 5 screens, which is the proof that it detects what it was written for |

## Results on 2026-11-16, commit `9b8cdca`

| File | Content |
|---|---|
| `results/gate-2026-11-16.tsv` | The repository's full gate and all 18 module tests on SQLite: 31 steps, all exit 0 |
| `results/return-id-gap-2026-11-16.json` | 22 tables listed, 47 read back, 30 missing |
| `results/pg-modules-head-2026-11-16.json` | 1 of 11 module suites passes on PostgreSQL semantics |
| `results/pg-modules-with-id-list-completed-2026-11-16.json` | The same run on a scratch copy with the 30 tables added: what remains is F-03, F-04 and test artefacts |
| `results/migrator-truncate-2026-11-16.txt` | Exit code 0 and "ALL GREEN", with 9 tables empty in the target |
| `results/browser-check-2026-11-16.json` | 36 of 38 dashboard menu routes render; 2 show the error screen |

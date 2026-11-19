# WOODEX OS — release report, 19 November 2026, second merge

The report of the round that analyses the Feature status screen and completes it, in the ten points of
document 10 §9, written **before** the merge (document 16 §8; the lesson of defect D-014).

**On whose word.** The owner's message of 19 November, after the first merge of the day:

> `https://woodex-os.vercel.app/admin/system/features` — analayize compalte laraval admin and report

Read twice, as the two words invite. **"Analyse"**: the page was read against its own specification
(document 05 §3 and §10, document 07 §14), against the registry it draws from, and in a browser on the
rehearsal of the hosted workspace — as four roles, on a desktop, in dark mode, and on a 390 px phone. **"Complete"**:
what the reading found is fixed in this round, listed below. **"Report"**: this page.

**What this round is.** No list line is closed and no module is switched on. Two defects found by the analysis
are fixed, one medium and one low, with the tests that hold them; the screen the owner named is completed in the
three ways the specification asks of it and does not yet do; the "What is new" entry is written; and the
analysis itself is recorded where the repository keeps such things (this report, and
`docs/woodex-os/32-FEATURE-STATUS-ANALYSIS.md`).

| Found | Where it stands |
|---|---|
| **D-045 (medium)** The page promised to name every part of WOODEX OS and could not name three System screens it offered in the menu. Three hubs admit one of **two** permissions in their route; the registry names one, and the page read only that. A person holding the second of each pair opened the screen from the sidebar and did not find it on the page; a role built from those permissions reached nothing at all | **Fixed.** The permission is read from the route's own middleware (`can:` all, `can-any:` one of), so the menu, the page and the route now give one answer. `Feature/FeatureStatusTest.php` fails if the old reading comes back |
| **D-046 (low)** The destinations began 1,129 px down a desktop and 2,255 px down a phone, because 488 words of release notes came first; the 32 "Open…" links were 17 px tall on a phone; and 68 destinations could be neither searched nor filtered while the sidebar beside them does both | **Fixed.** The four numbers filter by state, a search box finds a destination by name and reports how many match (or that none do), the release notes sit below the list with only the newest open, and every "Open…" link is at least 24 px tall. The first destination is now 488 px down |
| The page is read-only and follows the same permissions as the menu | **As specified** (document 05 §3: no roadmap in the menu, a separate view for it). Nothing to change |
| The 68 destinations, their four states and the three ways a person reaches the work that is not here | **Correct**, and checked against `routes/admin.php` and `config/modules.php`: 32 available, 13 in the old dashboard, 20 built and switched off, 3 not built — the same four numbers the owner saw, and the same ones document 27 predicted for this merge |

## 1. Commit/branch/build identifier

Branch `arena/d0136e24-woodex-2030`, from `main` at `dd7a308` (the merge of pull request #47). Pull request
opened from this branch.

| File | What changed |
|---|---|
| `platform/app/Support/AdminNavigation.php` | `permitted()` reads the route's gate; `routeGate()` added |
| `platform/resources/views/livewire/admin/system/feature-status.blade.php` | Search, state filters, the numbers as buttons, release notes below the list and folded, an "Open…" link of at least 24 px |
| `platform/tests/Feature/FeatureStatusTest.php` | A test for the gate (D-045) |
| `platform/app/Support/ReleaseNotes.php` | The entry under "What is new" |
| `docs/woodex-os/acceptance/defects.json`, `16-ACCEPTANCE-AND-RELEASE-GATES.md` | D-045 and D-046, and the tables written from them |
| `docs/woodex-os/32-FEATURE-STATUS-ANALYSIS.md` | The analysis: what the screen does, what it must do, what it does not |
| `docs/woodex-os/evidence/feature-status-2026-11-19-*.jpg` | The page as it is now: light, dark, a filter, a phone |

Built files: none. `platform/public/build` is as on `main`.

## 2. Environment

- Sandbox: PHP 8.5 (Vercel's own runtime from npm), the suite on SQLite in memory, and the rehearsal of the
  hosted workspace (`platform/tools/vercel/serve-demo.sh`, on the vercel-php 0.9.0 runtime, with `vercel.json`'s env and routes, a
  read-only tree, the demo's sample database) served on `127.0.0.1:8191`.
- Browser: Chromium from npm, driven by Playwright, at 1440 × 1000, 390 × 844, and in dark mode.
- No live site was written to, no setting of either Vercel project was changed, and no database outside the
  sandbox was touched. The live address itself cannot be reached from the sandbox (TLS is cut, document 14 §17),
  so every signed-in observation below is from the rehearsal of the same commit.

## 3. Test command and result

| What | Where | Result |
|---|---|---|
| The whole Laravel suite, on `main` before this round | Sandbox, SQLite, 19 November 13:30 to 13:37 UTC | **2,674 pass, 36 skipped, none fails** (16,423 assertions) — the number document 14 §1 records |
| The whole suite on this branch, before the `StoredScriptTest` fix | Sandbox, the same runner | **2,674 pass, 36 skipped, 1 failed**: `Security/StoredScriptTest`, "it puts only numbers and fixed words inside a script attribute" — the two echoes described below |
| The whole suite on this branch, after the fix | Sandbox, `run-suite.sh`, five groups (unit, feature-a-f, feature-g-p, feature-q-z, arch), 19 November 14:20 to 14:33 UTC | **2,675 pass, 36 skipped, 0 fail** (16,444 assertions). `suite-summary.py`: 0 failing tests, nothing unexplained |
| The suite after the change, the two files that own the screen and its registry | Sandbox | `FeatureStatusTest` 10 of 10, `FurnitureRoadmapNavigationTest` 2 of 2, `SystemNavigationTest` 8 of 8, `FurnitureNavigationTest` 9 of 9, `AdminLinksOpenTest` 8 roles, `AdminRouteAccessTest`, `WorkspacePreferencesTest` 14 of 14 — **48 passed** |
| `Feature/FeatureStatusTest.php` — "it follows the gate of each route, not one word of the registry" | Sandbox | Passes. With `permitted()` put back to reading one word, it fails (tried by hand, 19 November) |
| `scripts/verify-acceptance.mjs` | Sandbox | 46 defects, 1 critical, 7 medium and 4 low not closed; 34 closed; the five files, the tests they name and document 16 agree |
| The page in a browser, four roles | Rehearsal | Owner 68 destinations (32/13/20/3), sales manager 54, sales 35, accountant 32 — no console error, one `h1`, no image without `alt` |
| The page's new controls | Rehearsal | The four numbers are buttons with `aria-pressed`; pressing "Built, switched off" leaves exactly the 20 off destinations in 4 groups; "order" finds Orders and Purchase Orders; "zzz" says "0 of 68 destinations match" and "No destination of your account matches"; the release notes show one open release and two folded |
| A phone, 390 × 844 | Rehearsal | 0 px wider than the window, 0 controls under 24 px, the filters and the search usable |

**One failure found and fixed before the merge.** The first full run of the suite on this branch failed in
`Security/StoredScriptTest` — "it puts only numbers and fixed words inside a script attribute". The new page put two
PHP echoes inside Alpine attributes (`x-text` with `array_sum($counts)`, `x-on:click` with `{{ $state }}`), which is
exactly what that test exists to refuse: a value that is not proven to be a number or a word of a fixed list must
not be written into a script attribute. The counts now reach Alpine through a `data-feature-total` attribute on the
root and the tile buttons through `data-feature-tile`, with `x-bind:` in place of the colon shorthand. **The full
suite is green after that change** (see below), and the check did its work.

**Tried against:** the gate test as above. The filter and the search are display-only (Alpine); a wrongly
hidden row would be caught by the count above and by `FeatureStatusTest`, which reads each state's number and
five named destinations from the HTML.

## 4. Browser/device coverage

Chromium 141 (npm), desktop 1440 × 1000 (light and dark) and phone 390 × 844. Four demo roles. Screenshots in
`docs/woodex-os/evidence/feature-status-2026-11-19-*.jpg`. Not tried: Safari, Firefox, a real phone, and a
screen reader (the markup is unchanged in its landmarks and adds a labelled search field and `aria-pressed`
buttons).

## 5. Defects open/closed by severity

| Severity | Open | Accepted | Closed |
|---|---|---|---|
| Critical | 1 | 0 | 1 |
| High | 0 | 0 | 6 |
| Medium | 7 | 0 | 22 |
| Low | 4 | 0 | 5 |

Closed this round: **D-045** (medium) and **D-046** (low), both found by this analysis. The one open critical
defect is unchanged and is the owner's: **D-002**, the old dashboard's owner password printed in a public
document (T-021). Gate E cannot pass while it is open.

## 6. Migration reconciliation

None. No table, no column and no seed changed. One file of app code and one Blade view changed; nothing that
holds data.

## 7. Backup/restore status

Not touched. No database was written to. The rehearsal's database is the demo build, made fresh in `/tmp` at
every start and thrown away.

## 8. Performance observations

Feature status, measured on the rehearsal (`/admin/system/features`):

| | Before | After |
|---|---|---|
| HTML | 318 KB (25.7 KB gzipped) | 322 KB (26 KB gzipped): the search field and the filter buttons cost about 4 KB |
| First destination, desktop | 1,129 px down | **488 px down** |
| First destination, phone | 2,255 px down | about 1,050 px down |
| Reload, warm | ~0.70 s | ~0.72 s |
| Server round trips for a filter or a search | — | **none**: both are Alpine, over rows already in the page |

The page still names "dashboard address not configured" 18 times and "Not adapted to Woodex Furniture" 20
times, which is what makes it 300 KB; the search box is now the way through it.

## 9. Screenshots or recording of key flows

`docs/woodex-os/evidence/` — `feature-status-2026-11-19-light-desktop.jpg`,
`feature-status-2026-11-19-dark-desktop.jpg`, `feature-status-2026-11-19-filter-desktop.jpg`,
`feature-status-2026-11-19-phone.jpg`.

## 10. Explicit owner approval status

**Nothing is approved as accepted, and nothing needs to be:** the round closes no list line and accepts no
module. The one thing asked of the owner is unchanged — that he opens the admin on the hosted workspace after
the merge and says whether Feature status now reads as he expects, and, separately, the answers document 21 §3
lists (the price list T-003, the messaging account T-014, a Facebook Page T-120, and the old dashboard's password T-021).

## What this round did not do

- **It did not switch a module on.** The 20 destinations that are built and off stay off: 13 of them are a
  website Laravel serves itself and Furniture's shop is a React application, and the other 7 wait for one thing
  of the owner's (a price list, a messaging account, a Facebook Page).
- **It did not set `LEGACY_DASHBOARD_URL`.** 13 destinations are still reached in the old dashboard, and the
  page says so 18 times. Pointing it at `woodex-2030.vercel.app/dashboard` would turn each of those lines into a
  link — and the old dashboard's owner password is the one open critical defect (D-002, T-021), which is the
  owner's alone to change. It remains proposed, not done.
- **It did not make the hosted workspace keep data.** The notice is true and unchanged (document 13 §2): the
  site that keeps what is typed is the installation on Hostinger, whose ten steps are the owner's (document 24).
- **It did not build the three destinations that are built nowhere** — Site overview, Navigation, Sections &
  templates. They belong to the Website Studio rebuilt against the shop (T-116), after gate C.

## What comes next

Unchanged from document 21: round 1 is **T-105**, the shop reading products, categories and series from
`/api/v1`, behind a switch that stays off. The next round after that is T-041, one table for every list — which
is also the line under which this page's own lists would gain their five states.

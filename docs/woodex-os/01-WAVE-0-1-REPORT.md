# WOODEX OS — report: Wave 0, and the first slices of Wave 1

2026-11-16. Format: task, evidence, not done, safety, status (spec 08 §11).

## Task

| | |
|---|---|
| Objective | One code line that holds both applications (Wave 0). Then the start of the UI/UX upgrade of the Laravel admin: tokens, one navigation registry, the sidebar (Wave 1, slices 1.1–1.3). |
| Authority | Decision log DL-001, DL-005 to DL-008. |
| Scope completed | Phase 2 finished and merged first (PR #34). The Laravel branch merged into the working branch. The Agency OS look applied to the admin through its tokens. The navigation rebuilt as one registry in the owner's order. A Feature status screen. |

## Evidence

### Wave 0 — the merge

`arena/69363bda-woodex-2030` @ `063fc7c` merged into the working branch on top of `main` @ `4363530` (commit `9194028`). 1,695 files came in without conflict. **Eleven files conflicted, in 24 places**; the commit message says how each was settled. In short:

| Area | Settled as |
|---|---|
| API (`server.mjs`) | Both sides kept. 227 routes: Phase 2's 223 and the four read-only bridge routes for categories and images. |
| Dashboard (2 files) | Phase 1/2 kept. Both sides had fixed the same crash. |
| Storefront (6 files) | The catalogue still comes only from the database (Phase 2). From the Laravel side: the opt-in adapters for enquiries and quotation requests, category content from the Laravel admin where that connection is on, the address-driven category selection. On the default path a refused quotation still shows the API's reason. |
| One company claim | "Free professional assembly for all furniture" is gone from the product page. The Laravel side had removed it as unconfirmed; Phase 2 had left it. |

Fixed straight after the merge (`208ab02`): the merged storefront asked the API every 15 seconds for categories and images kept in the Laravel admin. That connection is off on every deployment today, so each open page would have sent eight requests a minute to be told no. It now asks again after ten minutes. Both sync tests assert this and fail without it; one of them had to be strengthened, because with the pause in place it would have passed without ever replacing an image.

| Check on the merged tree | Result |
|---|---|
| Both type checks | 0 errors |
| The React gate without the browser step | 38 steps, all exit 0 |
| The catalogue journey in a browser, on the merged build | 55 checks, pass |
| The six adapter tests of the Laravel side and its two generated-file checks | pass (two generated files were regenerated: Phase 2 removed ratings from their source) |
| The Laravel suite, in the sandbox (PHP 8.4.21, SQLite) | 2165 passed, 34 skipped, 9 failed — the nine that cannot run here (2 need `pdo_mysql`, 7 need `xmlwriter`). The same figures the Laravel branch recorded. |

### Wave 1, slices 1.1–1.3 — commit `537d89a` and the one after it

| Slice | What changed | Where |
|---|---|---|
| 1.1 Tokens | The Agency OS palette, as roles (`--wx-*`) and scales in one file. Charcoal sidebar, light canvas, white surfaces, WOODEX green. | `platform/resources/css/admin.css` |
| 1.2 Navigation registry | 10 groups and 68 destinations in the order of spec 05 §2, each with a stable id. The sidebar and the command palette show only what exists here, is switched on, and the person may use. | `platform/app/Support/AdminNavigation.php` |
| — Feature status | A new screen, **System → Feature status** (`/admin/system/features`): every destination the person may know about, with its state and the wave in which it arrives. Work still done in the Agency OS dashboard is linked from here when that address is configured. | `platform/app/Livewire/Admin/System/FeatureStatus.php` and its view |
| 1.3 Sidebar | Charcoal in both themes. The group that holds the current screen is always open. On a phone the drawer keeps the focus until it is closed. | `platform/resources/views/components/admin/sidebar.blade.php` |

With only the shipped modules on, an owner's Feature status reads: **29 available here · 13 in the Agency OS dashboard · 21 built but switched off · 5 not built yet** (68).

**The contrast validation the specification asks for** (spec 05 §5, "subject to contrast validation") found two things, and both are now in the tokens:

- White on the logo green `#4f9d21` is 3.4 : 1 — not enough for text. The fill that carries white text is `#43851c` (4.55 : 1).
- The secondary text `#66717a` was 4.48 : 1 on the elevated surface `#f1f3f4`. The surface is now `#f3f5f6` (4.56 : 1).

`platform/tests/Unit/AdminDesignTokensTest.php` reads the CSS and fails below WCAG AA: 14 text pairs and 6 edge pairs in each theme, and the scale steps that carry text.

| Check | Result |
|---|---|
| Laravel suite in the sandbox | **2181 passed, 34 skipped, 9 failed** (the same nine), 12,339 assertions, no unexplained failure. Before the change: 2165. |
| Roles | For the owner, sales manager, sales and accountant roles, every screen the sidebar offers opens (HTTP 200). A sales person is refused the screens it leaves out. The lowest-privilege role (support) is still refused everything outside its list; Feature status was added to that list on purpose. |
| Browser, 1366 px and 390 px | Dashboard, products, leads, Feature status: HTTP 200, no horizontal overflow, no console or page error. The phone drawer opens, keeps the focus over 80 Tab presses, and closes on Escape. |
| MySQL 8.4 (GitHub Actions, five jobs) | See "Status" below. |

Screenshots: [`evidence/`](evidence/) — the shell before and after, Feature status, and the phone drawer.

### Wave 1, slice 1.4, the findings of the analysis, and the Reimagined theme — commits `493ce22` and `7e5fffc`

| What | What changed | Proof |
|---|---|---|
| 1.4 Top bar | One row at every width: 56 px on a phone (it was two rows, 113 px), 64 px above. The page's trail, search, a "New" menu filtered by permission and module, bells, assistant, user menu with the theme choice | `AdminTopBarTest` (12 cases); the crawl: no top bar taller than 72 px |
| Findings of the analysis | Nine dead links, sideways scrolling on a phone, thirty unlabelled fields, bare error pages, small touch targets, a silent quotation form, the PHP version in every answer. The full table is in [`14-PRODUCTION-TODO.md`](14-PRODUCTION-TODO.md) §2 | `AdminLinksOpenTest`, `AdminFormLabelsTest`, `ErrorPagesTest`, `SecurityHeadersTest`; crawl and journeys |
| The Reimagined theme (DL-017) | Light sidebar with the current screen filled green (charcoal kept as a setting), green brand tile, bold tight headings, cards with 12 px corners and a hair of shadow, figures with upper-case labels and tinted tiles, pills with an edge, the theme's arrow on selects. Done in five shared components (card, figure, pill, "nothing here", page header), the sidebar and the stylesheet, so every screen follows | `AdminDesignTokensTest` checks every text pair for three tones (light, charcoal sidebar, dark); screenshots `evidence/theme-before-*` and `theme-after-*` |

The suite after both: 2,244 passed, 34 skipped, 0 failed (PHP 8.5, SQLite). The crawl of four roles and the
fourteen journeys were repeated with the theme: every page opens, nothing is wider than a phone, 14 of 14 hold.

**A test whose expectation changed, and why.** `AdminDesignTokensTest` pinned the sidebar's ground to the store's
charcoal. The sidebar is light now (DL-017), so the charcoal is pinned as its own role (`--wx-charcoal`) and as
the ground of the charcoal tone, and the contrast pairs are checked for three tones instead of two. The pair
"bar at the edge of the current screen" is asked only where that bar is drawn (the charcoal tone); the other two
tones must show the current screen as a fill that stands out from the sidebar. No pair was dropped.

**Tests that were changed, and why.** Eight existing test files asserted the old sidebar: its group names, and switched-off modules shown as disabled "Soon" entries. The specification moved the roadmap out of the sidebar (spec 05 §3; DL-009), so those assertions were rewritten to the new rule — a closed module is absent from the sidebar and listed on Feature status — not deleted. The exact count of live destinations is still asserted (48 → 50: the audit log is named on its own, and Feature status joined).

## Not done

| | |
|---|---|
| Slices 1.5–1.7 | The rest of the component pass (tables as one component, pagination, modal, toasts, the five states on every list), the dashboard layout of spec 05 §7, and the screen-by-screen pass. Next, in the order of DL-008; lines U-4 to U-6 of [`14-PRODUCTION-TODO.md`](14-PRODUCTION-TODO.md). |
| Selected states | Stage steps, activity types and the business-line switch are still charcoal pills; the theme fills them green. Part of U-6. |
| The dark theme | Looked at in a browser on the dashboard, Leads and a lead page, desktop and phone. Not on every screen. |
| Hard-coded colours | A few rules in `admin.css` and some views still name a grey directly instead of a role. To be removed in the component pass. |
| Wave S (Hostinger staging) | Untouched. It needs the SSH secrets and the plan details from the owner. |
| The browser journeys of the React side on the merged tree | Only the catalogue journey was run locally. The full browser step runs in CI. |

Unverified assumptions: that staff find Feature status without being told (it is the last-but-two entry under System, and the sidebar's empty-search message names it).

## Safety

| | |
|---|---|
| Data migrations | None. No table, column or row was changed anywhere. |
| Production impact | **None yet: this work is on the working branch and is not merged into `main`.** Merging it would deploy the merged storefront to the live shop (the changes listed under Wave 0). The Laravel application is not deployed by that merge; it has no production host. |
| Rollback | Each slice is one commit; reverting it restores the previous shell. The merge is one commit. |
| Approval required | The owner's go for merging into `main` (DL-011). |

## Status

**Partial** — Wave 0 complete; Wave 1: four of seven slices, and the theme of DL-017 on the shared parts. Everything still open is in [`14-PRODUCTION-TODO.md`](14-PRODUCTION-TODO.md).

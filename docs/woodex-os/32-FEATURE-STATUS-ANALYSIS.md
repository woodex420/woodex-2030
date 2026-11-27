# Feature status — the analysis, and what the screen does not do

**19 November 2026 · branch `arena/d0136e24-woodex-2030` · the owner's message: "analayize compalte laraval
admin and report", sent with the address of this one screen.**

This page is the answer to a reading, a completion and a report — in that order. What the screen is for, what it
does, where it stands against its own specification, what the reading found, and what is left for the owner.
Nothing here is a wish list: every gap either closed in this round or names the list line that closes it.

---

## 1. What the screen is, and why it exists

`/admin/system/features` is the **one** place where the admin tells the truth about itself. Until 16 November the
sidebar carried the roadmap itself — disabled "Soon" entries and "2030" badges beside screens that did not exist.
The owner's specification forbids that (document 05 §3: *"Hide unavailable destinations from normal navigation;
list roadmap items in a separate release/feature-status view"*, *"no repeated Soon badges and no Legacy labels"*),
and DL-009 moved it here.

So the admin has two views of **one registry** (`platform/app/Support/AdminNavigation.php`):

| View | Method | Shows | Rule |
|---|---|---|---|
| The sidebar and the command palette | `AdminNavigation::for()` | only destinations that exist, whose module is on, and that this person may open | nothing disabled, no "soon", no "legacy" |
| This page | `AdminNavigation::status()` | every destination this person's role could ever use, with its state and where the work is done today | the same permissions as the sidebar, so the page never advertises a screen the person could not open if it existed |

The registry holds **68 destinations in ten groups** (Workspace, Customers, Sales, Commerce, Operations,
Procurement & Production, Accounts, Growth, Website Studio, System) — the owner's own §2 list, in his order. That
number is not decorative: `scripts/verify-acceptance.mjs` reads the registry on every CI run, refuses a
destination that no acceptance sheet covers, refuses a sheet screen that is not a destination, and refuses the
whole check if fewer than 60 entries can be read (`if the check no longer understands the file`).

## 2. The four states, and how each is decided

The state is **derived, never written down** (`AdminNavigation::state()`), from two facts about the route and one
about the configuration:

| State | Badge | Decided by | Count as the admin ships | Count on the demo | Count per role |
|---|---|---|---|---|---|
| `available` | Available here (green) | the route exists **and** its `module:` middleware names a module that is on | **31** | **32** | owner 32, manager 22, sales 20, accountant 18 |
| `existing` | In the Agency OS dashboard (blue) | there is no route here, and the registry names a screen of the old React dashboard (`legacy`) | **13** | 13 | 13 for every role |
| `off` | Built, switched off (amber) | the route exists and its module is off | **21** | **20** | 2, 16, 2, 1 |
| `planned` | Not built yet (grey) | no route and no legacy screen | **3** | 3 | 3 for owner/manager/editor, 0 for the rest |

18 of the 68 carry a `legacy` path — the 13 of the `existing` state and five that are built here but still have
their old screen named (Conversations, All pages, Page builder, Theme, Marketing). A `legacy` link is only made a
link when `LEGACY_DASHBOARD_URL` holds a plain HTTPS address (`FurnitureNavigation::url()` refuses a script URL, a
protocol-relative one, or one with credentials); otherwise the page says *"dashboard address not configured here"*.
**The variable is empty on the hosted workspace today**, which is why those 13 rows read as dead ends rather than
links. That is one setting, and it is the owner's (T-021, below).

### Where the 20 built-and-switched-off destinations sit

| Module off | Destinations | What switching it on needs |
|---|---|---|
| `website` | All pages, Page builder, Blog, Portfolio, Forms, Theme, SEO, SEO agent, Speed, Site health, Website Settings, Redirects, Find & replace (13) | **Not a switch.** They edit a website Laravel serves from its own tables; Furniture's shop is a React application (DL-003). They are rebuilt against the shop after gate C (T-115 → T-116 → T-117) |
| `inbox` | Conversations, Chat Bot (2) | A WhatsApp/Telegram account and Furniture's prepared replies (T-014 → T-118) |
| `estimator` | Estimations (1) | Furniture's price list; the rate book in the code is the Interior studio's and is not loaded (T-003 → T-085) |
| `automation` | Automations, Sequences (2) | The inbox first, then a dry run and approvals (T-119) |
| `marketing` | Marketing (1) | A Facebook Page connection (T-120) |
| `whatsapp` | WhatsApp Templates (1) | The WhatsApp account (T-014) |

**No destination is off for a reason the agent could remove alone**, which is why this round switched nothing on.

## 3. The reading: the page against its specification

| # | The specification asks | What the page did | Now |
|---|---|---|---|
| 1 | One registry, two views, **no screen promised that would refuse the person** (05 §3, §10) | The two views were built from the same list but **not from the same rule**: the page read one permission per destination, while three routes admit *one of two* | **Fixed** (D-045). The gate is read from the route's own middleware, so menu, page and route give one answer. Approvals and Estimations keep their named abilities |
| 2 | Everything not in the menu is told here, with where the work is done today | Yes — 4 states, the reason in words, the legacy link when configured, the migration wave | Kept |
| 3 | Feature availability **and release notes** (07 §14) | Both, but the notes (488 words, three releases) came **first**, pushing the list 1,129 px down a desktop and 2,255 px down a phone | **Fixed** (D-046): the notes moved below the destinations and open one release at a time |
| 4 | Findable — the sidebar has a filter and `Ctrl-K` searches the whole admin | 68 rows, no search, no filter, and the four numbers were inert | **Fixed** (D-046): a search field, and the four numbers act as filters |
| 5 | Reachable by touch (10 §5: 24 px) | the 32 "Open…" links were 17 px tall | **Fixed** (D-046): at least 24 px |
| 6 | Read-only; follows the menu's permissions | Read-only, yes | Kept |
| 7 | A person with no access to the admin sees nothing | 403 (`can:dashboard.view`) | Kept; `Feature/FeatureStatusTest.php` holds it |
| 8 | Loading, empty and error states (07 §15 condition 6) | An empty state exists; no loading placeholder and no "connection lost" notice — the page is a Livewire component with no list fetch | **Open**, under T-041 and T-051, which are the standard for every list in the admin |

## 4. What the reading found that is *not* this screen

Four things are visible from this page and belong to other lines. They are recorded here because this is the
screen a person looks at when they ask "why can't I do this?".

1. **13 destinations have no screen in this admin at all** — Orders, Delivery & Installation, Support & Warranty,
   the Operations board, Suppliers, Purchase Orders, Goods Receipts, Supplier Invoices, Production, Stock Ledger,
   Warehouses, Stock Transfers, Reservations. They exist in the old React dashboard and nowhere here. Waves 6–8
   (T-106 to T-111). The Interior admin had none of them: there is nothing to reuse.
2. **The three that are built nowhere** — Site overview, Navigation (header, footer, mega menu), Sections &
   templates — are the part of the Website Studio that only makes sense once the page builder is rebuilt against
   the shop (T-116, after gate C).
3. **The two applications do not share a sign-in.** Every "Open in the Agency OS dashboard" link is a second
   sign-in, with a password that is the one open critical defect (D-002). This is why the link may be a
   convenience but cannot yet be the answer.
4. **The admin has 134 named routes and 81 Livewire screens, but only 52 of those routes are destinations of this
   page.** The other 82 are records, tabs, PDFs, exports and the hubs' inner screens. That is by design — a
   destination is a place you go, not a page you pass through — but it means this page is a *map of the product*,
   not an inventory of the code. `AdminLinksOpenTest` is the check for the other direction: every link any screen
   shows must open.

## 5. The proof

| Claim | Where it is held |
|---|---|
| The page names every destination the person could use, and no other | `Feature/FeatureStatusTest.php` — "it tells each person only about what their role could use", "it follows the gate of each route, not one word of the registry" |
| Nothing in the menu is missing from the page, and nothing planned is in the menu | `Feature/FurnitureRoadmapNavigationTest.php`, `Feature/SystemNavigationTest.php` |
| The four numbers are the four states | `Feature/FeatureStatusTest.php` — "it renders Feature status with the state of every part and its counts" |
| Every legacy link is a safe address, and says so when it is not configured | `Feature/FurnitureNavigationTest.php` (9 of 9, including six refused bases) |
| A person with no access sees 403 | `Feature/FeatureStatusTest.php`, `Feature/AdminRouteAccessTest.php` |
| What changed, in the person's words, newest first | `Feature/WorkspacePreferencesTest.php` — and it refuses a note that names a list line or a commit |
| The registry and the acceptance sheets are the same 68 destinations | `scripts/verify-acceptance.mjs`, in CI |

Measurements of the completed page, on the rehearsal of the hosted workspace (1440 × 1000 and 390 × 844, four
roles, light and dark): four counts; 68 rows; one `h1`; no console error; no image without `alt`; 0 px of sideways
scroll; 0 controls under 24 px; the first destination 488 px down; a filter and a search that cost no server round
trip. Screenshots: `evidence/feature-status-2026-11-19-*.jpg`.

## 6. What is left for the owner on this one screen

| Decision | What it changes here |
|---|---|
| **T-021** Change the old dashboard's owner password (and make the repository private) | The one open critical defect. Until it is done, a link to that dashboard is not offered, and gate E cannot pass |
| Say **yes** to `LEGACY_DASHBOARD_URL=https://woodex-2030.vercel.app/dashboard` | 13 rows stop saying "dashboard address not configured" and become links, each with its own sign-in. Proposed on 18 November, not done |
| **T-003** Furniture's price list, terms, signers | Turns one row (Estimations) from amber to green |
| **T-014** A WhatsApp/Telegram account; a Facebook Page (T-120, which begins with T-014) | Turns six rows green (Conversations, Chat Bot, WhatsApp Templates, Automations, Sequences, Marketing) |
| **Gate C**, the page-builder prototype accepted | Decides the fate of the 13 website rows and the 3 that are built nowhere |

Everything else on the page moves by the agent's own work, in the order of document 21.

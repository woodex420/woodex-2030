# Release report, 27 November 2026: a product from the admin to the shop

Written before the merge, on the owner's "WOODEX 2030 — Master System Implementation Prompt" of 27 November
(decision [DL-049](12-DECISION-LOG.md)). It is the report of the first phase of that programme: its P0 points
1, 4, 6, 7 and 8. The audit he asked for first is [document 46](46-IMPLEMENTATION-MATRIX-2026-11-27.md).

His goal, in his words: "to create a real product in the admin and see it correctly published on the public
storefront", and "Do not claim completion until the actual database, browser workflow and deployment have been
verified."

**What this report claims, and what it does not.** The product link is built, and his seventeen steps pass in a
browser against an installed admin and its database, with nothing mocked. **It is not deployed, and it is not
complete by his rule**: no live admin has a database (his P0 point 5; only he can give one), so the proof is a
rehearsal, and the live shop still shows the 148 sample products.

## What was wrong

1. **Pull request #48 had been open since 19 November and no longer fitted `main`.** It held two rounds that
   never arrived: Feature status reading each address's own gate (DL-038), and round 1 of the product link
   ([document 33](33-RELEASE-2026-11-19.md)). It also held the next eight numbers of the defect register, so the
   register had taken no entry for eight days (audit finding AU-19).
2. **Issue #59**: the check of the live sites failed at 00:02 UTC on 27 November, on one point of 56.
3. **The product link had never been used from end to end.** Round 1 was built and tested piece by piece and
   switched on nowhere. Read against the real catalogue, and then pressed in a browser, it showed these faults:

| # | What was wrong | Found by |
|---|---|---|
| 1 | A product without a price could not be published (AU-13): 33 of the 170 real products | The audit of 23 November |
| 2 | **None** of the 170 could be published as imported: every option row had to repeat the price, and the import writes each product's code into one such row without a price | Reading the publication rule beside the import |
| 3 | Every product whose availability was "confirm on request" was handed out as "ask us". That is every product of the import: the real catalogue would have shown no price at all | Reading the contract beside the import |
| 4 | The shop read the first 24 products and stopped. A catalogue of 170 would have shown 24 | Reading the reader beside the catalogue's size |
| 5 | A product could arrive before the categories, and then stood in none | The browser, step 11 |
| 6 | A product stood under its sub-category as if that were a heading of the shop | The browser, step 11 |
| 7 | The product's own code was offered as an option to choose | The browser, step 13 |
| 8 | With the link on, every open page was told "not found" four times a minute | The browser's console |
| 9 | The delivery text written in the admin was not shown; the shop's general sentence stood there | The browser, step 13 |
| 10 | Two products could carry one code | Reading the save rule |
| 11 | The admin's product addresses shipped switched off; the contract and document 33 said they were on | The first request of the rehearsal |

## What was built

**Pull request #48, brought home.** Its branch was merged into this one (commit `982637b`), not built again. The
code merged without a conflict; eight documents and records were settled by hand, each side's lines kept. This
pull request therefore closes #48.

**The admin** (`platform/`):

- **"Price on request" is a tick of the product's editor.** A product is published with a price or with that
  tick; never both, never neither. Typing a price takes the tick off; the tick beside a price is refused with the
  reason. No figure is made up anywhere, and none is handed out for such a product.
- **Availability says nothing about the price.** "Made to order (confirmed on request)" is how a product can be
  ordered; a product made to order keeps its price.
- **An option without a price is sold at the product's price**, as a quotation line already was.
- **One code for one product**: a code another product has is refused when the draft is saved and again at
  publication, whatever its capitals, and the screen names the product that has it.
- **The product's own code is handed out as `sku`**: the one row with a code and no colour, material or size.
  No table and no column was added.
- The product addresses of `/api/v1` are on unless an installation switches them off. They hand out the
  published copy of a product and nothing else: not a draft, not a withdrawn or archived product, and none of
  the editor's own fields (the review, the import's record, the saved copies).
- The form's words: "Product code and options" for "Structured variants"; what publishing and withdrawing do, in
  the shop's terms. The list of products and the summary beside the form say "Made to order", not `on_request`,
  and write a price with its thousands.

**The contract** (`platform/resources/api/v1.openapi.json`, [document 29](29-API-V1-RULES.md) §7): `sku`,
`on_request` as the price fact, `price_minor` null exactly then. Held to the code key by key.

**The shop** (`apps/`):

- It reads **every page** of the admin's list. A page that fails makes the whole reading fail, so that half a
  catalogue is never shown as the catalogue; the shop keeps the last whole list it has.
- It reads the categories first, and places a product under the shop's heading and part.
- It shows the product's code, and the delivery text its editor wrote.
- Its own server answers the question "what changed of a price" itself, with nothing: the admin is the only
  writer of a product's price.

**The proof** (new): `scripts/os-product-to-shop.mjs` presses the owner's seventeen steps in a browser and reads
the database by a process of its own. `platform/tools/hostinger/rehearse-with-shop.sh` puts the release
together, installs it in a browser, starts the built shop against it and runs the journey twice. The job
`hostinger` of `os-ci.yml` does the same on Apache and MariaDB 10.11.

**The check of the live sites** (issue #59): it asks who answered. An answer without the application's request
number is asked again after a pause, up to three times, and written down; the question is asked before the ten
file names; a failure says whether the modules differ or the application could not be asked.

**The records**: 28 entries in the defect register (D-053 to D-080: this round's, and those owed since 19
November); nine new lines in the master list (T-191 to T-199); decision DL-049; document 46.

## The owner's seventeen steps

Pressed by `scripts/os-product-to-shop.mjs`, twice: once with a price, once priced on request. Nothing is mocked:
a real sign-in, the real form, a real file, the real database, the built shop.

| # | His step | What was done and seen |
|---|---|---|
| 1 | Sign in to the real admin | The owner's account that the installer made, at `/login` |
| 2 | Create a product | "New product", a name, and from it the product's address on the shop |
| 3 | Upload a photograph | A file from disk; the admin shows it at 800 × 800, with its describing text |
| 4 | Code, description, dimensions | A code, a description, 1800 × 900 × 750 mm, delivery and warranty texts |
| 5 | Category | Office Furniture → Office Tables → Executive Tables |
| 6 | Price, or Price on Request | PKR 185,000; in the second run the tick, and no figure typed |
| 7 | Save | Reviewed, saved as a draft |
| 8 | It is in the database | Read by a separate process: one row of `commerce_products`, its saved copies, its photograph. The admin and the shop both answer "not found" for it: a draft is nobody's to read |
| 8a | (added) What runs is stopped and started | The name, the code and the price are as they were saved |
| 9 | Publish | Previewed by the person who publishes; the database names the published copy |
| 10 | Open the shop | Its catalogue arrived from the admin |
| 11 | Find it in its category | Listed under Office Tables → Executive Tables |
| 12 | Open its page | `/shop/<its address>` |
| 13 | Image, code, description, price | The photograph at 800 × 800, the code, the description, the dimensions, delivery and warranty as written; PKR 185,000, or "Price on request" and no figure |
| 14 | Edit in Laravel | A new name and PKR 172,500. Until it is published again the shop keeps the copy of before |
| 15 | The shop shows the change | On the product's page and in its category |
| 16 | Unpublish | The database names no published copy; the product, its saved copies and its photograph are kept |
| 17 | No longer public | Not in its category; its page says "Product Not Found" and asks not to be indexed; both servers answer "not found" |

**Result in the sandbox: 18 of 18 with a price, 17 of 17 priced on request, no problem reported.** In CI, on
Apache and MariaDB 10.11: on this pull request.

## What a person sees differently

- **Whoever keeps the products**: a box "Price on request" under the price; "Product code and options"; a clear
  refusal when a code is taken; "Made to order" where it said "Confirm on request"; after "Publish", the words
  "A shop that reads its products from this admin shows this product now."
- **A visitor of a shop that reads from the admin**: the product in its category, with its photograph, its code,
  its texts and its price, or "Price on request" with an invitation to ask.
- **A visitor of the live shop today**: nothing. It reads its own catalogue.

## What this merge changes on each live site

| Site | Changes | Does not change |
|---|---|---|
| The admin demo, `woodex-os.vercel.app` | The product form as above. `/api/v1/catalogue/products` answers, with an empty list: the demo has published no product. Feature status reads each address's own gate (from #48) | It still keeps nothing |
| The shop, `woodex-2030.vercel.app` | Nothing a visitor sees | Its switch `NATIVE_PRODUCT_SYNC` is not set, so it reads its own database and shows the 148 samples |
| Hostinger | Nothing: nothing is installed there | — |

## 1. Commit/branch/build identifier

Branch `arena/85c0460e-woodex-2030`, from `main` at `31f2306`. Two commits: `982637b`, the merge of pull request
#48's branch (head `3df14ec`), and one that carries this round. Against `main`: 68 files, 27 of them new. 24 of the admin
(8 of its code, 6 screens, 5 test files, the contract, the routes, one setting, 2 tools), 7 of the shop, 2
scripts, 3 workflows, `package.json`, `.gitignore`, 12 documents, 4 records of the acceptance and 14 pictures.
The merge of #48 brought 40 of them; this round changed or added 47.

The prompt names the branch `arena/bd1e6bb4-woodex-2030`. That branch was merged on 25 November (pull request
#58) and is closed; this is the branch the session gives.

## 2. Environment

- **Sandbox**: PHP 8.5, Node 22, Chromium. The Laravel suite on SQLite in memory. The acceptance on the release
  as Git carries it, installed in a browser into an empty database, which is **a database file**: the only
  database server a sandbox can fetch is MariaDB 10.2, and the installer refuses anything older than 10.6. The
  admin's process was stopped and started after the product was saved.
- **CI** (`os-ci.yml`, job `hostinger`): Apache, PHP 8.4, MariaDB 10.11, the same release, the same journey; the
  web server and the database server are both stopped and started after the product is saved. That database
  keeps its files on disk (the test jobs keep theirs in memory), so what is read after the restart is what
  MariaDB wrote. Its result is on the pull request; this report was written before it ran.
- **The live sites**: nothing was written to Vercel, to Supabase or to Hostinger. No database of production was
  read or changed.

## 3. Test command and result

| Command | Result |
|---|---|
| The whole Laravel suite (`tools/preview/sandbox/run-suite.sh`) | **2,905 pass, 36 skipped, none failing.** On the merge of #48 alone: 2,899 |
| `node scripts/os-product-to-shop.mjs`, with a price | **18 of 18** |
| The same with `OS_PRICE_ON_REQUEST=1` | **17 of 17** |
| `node scripts/os-install.mjs` (inside the rehearsal) | 8 of 8: installed in a browser |
| `node scripts/test-native-product-reader.mjs`, `test-native-category-sync.mjs`, `test-media-sync.mjs` | pass |
| `npm run typecheck:all`, `npm run vercel:build`, `npm run smoke:render`, `npm run smoke:hostinger` | pass; 16 of 16 static pages written |
| `node scripts/test-storefront-facts.mjs`, `npm run secret-scan`, `npm run docs:verify` | pass |
| `verify-acceptance.mjs`, `verify-plan.mjs`, `verify-table-map.mjs`, `verify-code-references.mjs` | pass |

**Each new protection was taken out once, and its test failed**: thirteen changes, eight in the admin and five in
the shop. No price and no tick; `on_request` repeating the availability; an option without a price having none;
the code row left among the options; a code of another product accepted; a price saved beside the tick; the tick
kept when a price is typed; the import not saying "on request"; the reader stopping at the first page; a failed
page skipped; the price question sent on to the admin; the admin's small page; the code not read.

## 4. Browser/device coverage

Chromium only. The admin at 1366 × 900; the shop at 1366 × 900 and, for the product's page, at 390 × 844. Not
tried: Safari, Firefox, a real phone, the live sites.

## 5. Defects open/closed by severity

The register is open again: 80 entries, 62 closed.

- **Entered and closed in this round**: D-053 to D-059, D-079 and D-080 (medium and low): the eleven faults of
  the table above and issue #59. D-049 (from round 1) closed.
- **Entered and open**: **D-060, high**: with the link on, an order or a basket for a product that only the
  admin has is refused. It blocks one release, the switch of the live shop, and is the next round (T-191).
- **Owed since 19 November and entered now**: D-061 to D-078, the findings of the audit of 23 November: eleven
  closed (nine of them by the round of 25 November), seven open, each with its line.
- **Open in all**: 1 critical (D-002: the old dashboard's password, the owner's act), 2 high (D-060, D-063), 11
  medium, 4 low.

## 6. Migration reconciliation

No migration. No table and no column was added: "Price on request" is a field of the product's saved draft, and
the product's own code is a row the drafts already had. A database-level guard of the code is its own line
(T-193), because it needs a column.

## 7. Backup/restore status

Nothing that needs a backup changed. No backup of the live shop's database is known, as before (D-011).

## 8. Performance observations

The shop asks for the admin's list in pages of 100: a catalogue of 170 is two requests where it would have been
eight. With the link on, an open page no longer sends a request every fifteen seconds that is answered "not
found". Nothing was timed, and the real catalogue was not loaded: the rehearsal held two products.

## 9. Screenshots or recording of key flows

- [`evidence/product-to-shop-2026-11-27-admin-published.jpg`](evidence/product-to-shop-2026-11-27-admin-published.jpg):
  the admin after "Publish this product".
- [`evidence/product-to-shop-2026-11-27-shop-category.jpg`](evidence/product-to-shop-2026-11-27-shop-category.jpg):
  the product in its category on the shop.
- [`evidence/product-to-shop-2026-11-27-shop-product.jpg`](evidence/product-to-shop-2026-11-27-shop-product.jpg):
  its page, with the photograph, the code and the price.
- [`evidence/product-to-shop-2026-11-27-on-request-phone.jpg`](evidence/product-to-shop-2026-11-27-on-request-phone.jpg):
  a product priced on request, at 390 px: no figure.
- [`evidence/product-to-shop-2026-11-27-shop-withdrawn.jpg`](evidence/product-to-shop-2026-11-27-shop-withdrawn.jpg):
  the same address after the publication was withdrawn.

## 10. Explicit owner approval status

Ordered by the owner on 27 November. He has not seen the result. Merged by the agent when every check is green,
by his standing word of 17 November (DL-026). Nothing here is accepted by him, and the product module is not
signed (T-171).

## The phase, in the owner's form

| | |
|---|---|
| **Phase** | P0, points 1, 4, 6, 7 and 8 |
| **Status** | Built and proved on a rehearsal. **Not deployed as a working link; not complete** |
| **Files** | See point 1 |
| **Database** | No change of structure. Read in the proof: `commerce_products`, `commerce_product_revisions`, `commerce_product_media` |
| **APIs** | `GET /api/v1/catalogue/products` (narrowed by category, series and "changed since"), `/products/{slug}` and the product's photographs: on, with `sku`, `on_request`, `price_minor`. Already there: `/catalogue/categories` (the series come with it), `/media`, `POST /leads`, `POST /quote-requests`. Not built as addresses of their own: `/series`, `/filters`, `/search` (T-192) |
| **UI** | The product form and list of the admin; the product's page of the shop |
| **Integration** | Admin → `/api/v1` → the shop's server → the shop's pages. One writer: the admin |
| **Tests** | Point 3 |
| **Browser** | The seventeen steps, twice; point 4 |
| **Deployment** | Both Vercel projects deploy this merge. The link stays off on the live shop. No Hostinger |
| **Risks** | D-060; a shop switched over before T-191 would show buttons that are refused. The contract changed the meaning of `on_request`: the old meaning was switched on nowhere |
| **Owner input** | Below |
| **Next phase** | T-191: a visitor asks for a product of the admin. Its own branch and pull request |

## What is not cured, and why

- **A visitor cannot yet order or ask for a product that only the admin has** (D-060). The product's page says
  "Add it to your quote and we will send you the price", and the request is refused. This is why the live shop
  is not switched, and it is the next round.
- **The 170 real products are still drafts.** Their photographs are only linked (T-089: it needs a server that
  can reach woodexfurniture.pk) and 116 carry a point to check (T-090, the owner's).
- **Series, filters and search have no address of their own** in `/api/v1` yet (T-192). The series come with
  the categories, the list is narrowed by category and series, and the shop searches what it has read.
- **His five steps to publication** (draft → review → approved → published → archived) are four states and a
  tick today (T-199); who may approve is his to say (T-004).
- **No demo sign-in file was made** (`.local/DEMO-ACCESS-LOCAL.txt`). The demo's passwords are settings of the
  Vercel project; they are in no file and not in Git, and nothing here needed a copy of them. The place he
  named for such a file is ignored by Git by name, so that one made later cannot be committed.
- The list of products and the import screen still say "native" (D-069).

## What is not proved

- **MySQL or MariaDB in the sandbox**: the acceptance there ran on a database file. CI runs it on MariaDB 10.11.
- **A live site**: none has the link on, and no live admin has a database.
- **The real catalogue through the link**: two products were read, not 170. The reader's script reads three
  pages; a browser has not.
- **Issue #59's cause** is inferred, not proved: the failed answer's headers were not kept. The check now keeps
  them.

## What the owner is asked

1. **Change the old dashboard's password** (D-002, critical): `woodex-2030.vercel.app/dashboard` → Settings →
   "Your password". Five minutes; only he can.
2. **Which Hostinger plan is it?** (document 46, section 5.) On "Business" the recorded decisions stand; on a
   VPS the prompt's target (Next.js, PostgreSQL, Docker, Redis, tenants) becomes possible and is then a
   decision of its own.
3. **A database for the real admin**: the ten steps of [document 24](24-HOSTINGER-STEP-BY-STEP.md) (T-006).
   Until then every proof is a rehearsal.

## What comes next

T-191, then T-192 and T-193; the plan is [document 21](21-PENDING-WORK-PLAN-AND-TESTS.md).

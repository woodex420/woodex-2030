# Release report, 28 November 2026: a visitor asks for a product of the admin

Written before the merge, on the owner's message of 27 November, "Start next" (decision
[DL-050](12-DECISION-LOG.md)). It is the round the report of that day named as the next:
[document 45](45-RELEASE-2026-11-27-A-PRODUCT-FROM-THE-ADMIN-TO-THE-SHOP.md) showed a product of the admin on
the shop, and found that a visitor could not ask for it (defect D-060, master list T-191).

**What this report claims, and what it does not.** With the product link on, a visitor of the shop can ask for
a product that only the admin has, and the request is in the admin as an enquiry that names the product, its
code, how many, and its price as published. That is proved in a browser against an installed admin and its
database, with nothing mocked, on a rehearsal and in CI. **It is not on a live site, and by the owner's rule it
is not complete**: no live admin has a database, and the live shop still shows its 148 sample products.

## What was wrong

With the link on, the page of such a product offered "Add to Cart" and "Add to Quote". Both were refused when
they were sent (400, "item 1 is not an available catalog product"): the shop's own server priced an order and a
basket from its own database, which does not hold a product of the admin.

Four more things were found while this was cured. Each is in the register:

| | Found | Cured |
|---|---|---|
| **D-082**, high | The admin counted every visitor of the shop as one address. Everything a visitor reads of the admin reaches it from the shop's one server, and the admin allowed one address 120 reads a minute. An open page asks nine times a minute, so about fourteen open pages would have used up the reads of everybody, and the shop would then have said that its catalogue is not available. Measured: of 125 reads from one address within a minute, the last five were answered 429 | The shop's server says for whom it asks, by a key both sides hold. The admin counts each visitor |
| **D-084**, low | On the rehearsal's database file, one of two requests that met was ended at once: a visitor's read was answered 503, a save of the owner 409. Measured: of 40 enquiries written while the categories were read 400 times, 3 enquiries and 19 reads were ended. It is a property of SQLite, which no host runs | The rehearsal tells SQLite to make the second writer wait, as MySQL does. The same measurement then ended none |
| **D-083**, low | The journey script could read the admin's screen before the admin had answered: twice it took another answer for the one it waited for | It waits for the answer by the name of the field it typed |
| Not shipped | A basket in which every line is priced on request printed "Saved catalogue estimate: Rs 0", which reads as free. And a shop built with the robot check's public key would have shown a box whose token nobody read | "No catalogue estimate: the line is priced on request". The token is checked |

## What was built

**The admin** takes a basket whose lines name its own published products.

- A line is `{slug, sku, qty}`: the product by its address, its option by its code. The older line,
  `{product_id, qty}`, is read as before. No table maps the shop's products to the admin's.
- The name, the code and the price are read from the copy that is published in that moment. Nothing of them is
  taken from the request, and a basket that was sent is not changed by a later publication.
- Refused, by the name of the line, with nothing written: a draft, a withdrawn, an archived or an unavailable
  product; a product sold in options without its option; an option it does not have.
- "Price on request" is saved without a figure, never as 0. The estimate adds up the lines that have a price
  and says how many it leaves out; where none has one, there is no estimate.
- The enquiry's page shows each line: `Executive Desk [ET-10010] · 2 each × Rs 42,500 = Rs 85,000`, or
  `× price on request`.
- The limits are counted for each visitor: five baskets a minute and fifty a day, 120 reads a minute, the
  numbers as before. The shop's server names the visitor; from anyone without the key that header is not read.
  The visitor's address is used for counting and kept nowhere.

**The shop's server** carries the basket to the admin and writes nothing itself
(`apps/api/native-enquiry-bridge.mjs`). One address is carried, `POST /api/v1/quote-requests`: the body and the
answer unchanged, the status and the request number with them; never a cookie, never an authorization. It and
the three bridges that read say for whom they ask (`apps/api/native-bridge-key.mjs`).

**The shop's pages**, for a product that came from the admin:

- no cart, on its page and on its card. The page says "Ordered by quotation. Add it to your quote and our team
  will confirm it with you." and offers "Add to Quote" and "Ask on WhatsApp", which names the product and its
  code;
- a cart that already holds such a product says so and offers "Move to quote";
- the basket goes to the admin. The page decides that when it runs, by where its catalogue came from, and not
  by a setting of its build: the two cannot disagree;
- an option the admin marked unavailable is not offered.

A product of the shop's own database is offered exactly as before. So is everything while the link is off.

**Different from what the plan said** (DL-050): the plan named two build settings; the page now reads where its
catalogue came from. And only the basket is carried: an enquiry without a basket still goes to the shop's own
database until the forms are moved (T-100).

## The journey's three new steps

`scripts/os-product-to-shop.mjs`, after step 13, in a browser, against the installed admin:

| | Step | What is asserted |
|---|---|---|
| 13a | No cart is offered for it | No cart button on the product's page or on its card; "Add to Quote"; the WhatsApp link names the product and its code |
| 13b | A visitor puts it into the quotation basket and sends the basket | Two of the product, sent from the request page; answered 201 with a reference; shown to the visitor with "Pending staff review"; nothing the page asked was answered with a refusal |
| 13c | The enquiry is in the admin | Read in the database: the basket, its state, and what its priced lines come to. Read on the owner's screen: the visitor, the product, its code, "2 each × Rs 185,000 = Rs 370,000", or "2 each × price on request" and no estimate |

The journey now has 21 steps with a price (the admin is stopped and started in between) and 20 priced on
request.

## What a person sees differently

- **Whoever answers enquiries**: under "Storefront basket requests" on an enquiry, each product of a basket with
  its code and its price, or "price on request".
- **A visitor of a shop that reads from the admin**: "Add to Quote" and "Ask on WhatsApp" where "Add to Cart"
  stood, and a request that is received.
- **A visitor of the live shop today**: nothing. It reads its own catalogue and offers its cart as before.

## What this merge changes on each live site

| Site | Changes | Does not change |
|---|---|---|
| The admin demo, `woodex-os.vercel.app` | An enquiry's page prints a basket's lines as above. Feature status has the entry of 28 November | It keeps nothing. `quote_requests` answers there as before: every module is on (DL-045) |
| The shop, `woodex-2030.vercel.app` | Nothing a visitor sees | Its switch `NATIVE_PRODUCT_SYNC` is not set: it reads its own database, offers its cart and sends its baskets to its own server |
| Hostinger | Nothing: nothing is installed there | — |

## 1. Commit/branch/build identifier

Branch `arena/8d3c1c4c-woodex-2030`, from `main` at `1fb856c`. One commit; its number is on pull request #61.
Against `main`: 61 files, 13 of them
new. 17 of the admin (7 of its code, one screen, the contract, the routes, two settings, 3 test files, 2 tools),
19 of the shop (6 of its server, 13 of its pages), 4 scripts, 2 workflows, 11 documents, 4 records of the
acceptance and 4 pictures.

## 2. Environment

- **Sandbox**: PHP 8.5, Node 22, Chromium. The Laravel suite on SQLite in memory. The acceptance on the release
  as Git carries it, installed in a browser into an empty database, which is a database file there: the
  sandbox's MariaDB is too old for the installer. The admin is told to take baskets as an owner would tell it
  (`platform/tools/hostinger/take-baskets.sh`: two lines of the settings file), with a key made for the run.
- **CI** (`os-ci.yml`, job `hostinger`): Apache, PHP 8.4, MariaDB 10.11, the same release, the same journey, the
  same two lines. The run on this commit is on the pull request.
- **The live sites**: nothing was written to Vercel, to Supabase or to Hostinger. No database of production was
  read or changed.

## 3. Test command and result

| Command | Result |
|---|---|
| The whole Laravel suite (`tools/preview/sandbox/run-suite.sh`) | **2,917 pass, 36 skipped, none failing** (2,905 before this round), run on the code as it is merged |
| `node scripts/os-product-to-shop.mjs`, with a price | **21 of 21**, five rehearsals in a row |
| The same with `OS_PRICE_ON_REQUEST=1` | **20 of 20**, the same rehearsals |
| `node scripts/os-install.mjs` (inside the rehearsal) | 8 of 8: installed in a browser |
| `node scripts/test-native-enquiry-bridge.mjs` (new), `test-native-product-reader.mjs`, `test-basket-adapter.mjs`, `test-native-category-sync.mjs`, `test-media-sync.mjs`, `test-lead-challenge.mjs`, `test-storefront-lead-adapter.mjs` | pass |
| `npm run typecheck:all`, `npm run vercel:build`, `npm run smoke:render` | pass; 16 of 16 static pages written |
| `npm run secret-scan`, `npm run docs:verify`, `node scripts/test-storefront-facts.mjs` | pass |
| `verify-acceptance.mjs`, `verify-plan.mjs`, `verify-code-references.mjs` | pass; also `test-verify-plan.mjs` and `verify-table-map.mjs` |

**Each new protection was taken out once, and its test failed**: 24 changes.

- **In the admin, twelve**: the draft read in place of the published copy; 0 written for "on request"; an
  unavailable product accepted; the price taken from the request; a product in options taken without its
  option; a basket taken with the products switched off; "Rs 0" printed as an estimate; the visitor's header
  read from anybody; the reads counted by the sender's address; a token of the page not checked; the token
  checked for the shop's server instead of the visitor; a carried basket asked for a token it does not have.
- **In the shop's server, nine**: a visitor's own key handed on; his cookie handed on; the admin's refusal
  flattened to a success; the enquiry forms carried too; the categories and a photograph read without saying
  for whom; the visitor named by a header of his own; a short word used as a key; no answer told as an answer.
- **In the shop's pages, two, each in a script and again in a built shop in a browser**: with the cart left
  on, the journey fails at 13a, "the page offers a cart for a product that only the admin has"; with the basket
  sent to the shop's own server, at 13b, "sent to /api/quotes, which cannot price a product of the admin".
- **On the rehearsal, one**: the admin not told to take baskets (`BASKET=off`). The journey fails at 13b with
  the admin's own 404, and no basket is kept.

## 4. Browser/device coverage

Chromium only. The admin at 1366 × 900; the shop at 1366 × 900 and, for the product's page, at 390 × 844. Not
tried: Safari, Firefox, a real phone, the live sites.

## 5. Defects open/closed by severity

The register: 84 entries, 67 closed.

- **Closed in this round**: **D-060**, high, the reason for the round.
- **Entered and closed in this round**: D-082 (high), D-083 and D-084 (low): the table at the top.
- **Open in all**: 1 critical (D-002: the old dashboard's password, the owner's act), 1 high (D-063: facts and
  claims on the shop that nobody confirmed, the owner's), 11 medium, 4 low.

## 6. Migration reconciliation

No migration. No table and no column was added. A basket's lines are kept where they were kept, as one value of
the basket's row; a line of a published product has more keys than a line of the rate book (`slug`, `sku`,
`option`, `revision`), and the screen reads both.

## 7. Backup/restore status

Nothing that needs a backup changed. No backup of the live shop's database is known, as before (D-011).

## 8. Performance observations

Measured on the rehearsal, with the link on. A page of the shop that stands open and is not touched asks the
admin **nine times a minute** through the shop's server: the categories and the replaced photographs every
fifteen seconds, the whole list of products once a minute. A journey of ten pages asked 36 times in under a
minute; the admin allows one visitor 120. Each is a request the host must answer, for answers that are the same
for everybody. For a few visitors that is nothing; for many, on shared hosting, it is the first thing to cure,
and it has its line and its plan (T-200): the shop's server keeps what it read for a few seconds, and a page
asks whether anything changed.

The writing of 40 enquiries beside 400 reads took 38 seconds on the rehearsal's database file, in either mode.
That is the sandbox, not a host. Nothing was timed on MySQL, and the real catalogue was not loaded.

## 9. Screenshots or recording of key flows

- [`evidence/ask-for-a-product-2026-11-28-shop-product.jpg`](evidence/ask-for-a-product-2026-11-28-shop-product.jpg):
  the product's page on the shop: "Add to Quote", "Ask on WhatsApp", and no cart.
- [`evidence/ask-for-a-product-2026-11-28-shop-basket-sent.jpg`](evidence/ask-for-a-product-2026-11-28-shop-basket-sent.jpg):
  what the visitor is shown after sending: the reference, and "Pending staff review".
- [`evidence/ask-for-a-product-2026-11-28-admin-enquiry.jpg`](evidence/ask-for-a-product-2026-11-28-admin-enquiry.jpg):
  the enquiry in the admin, with the product, its code and its price on its line.
- [`evidence/ask-for-a-product-2026-11-28-admin-enquiry-on-request.jpg`](evidence/ask-for-a-product-2026-11-28-admin-enquiry-on-request.jpg):
  the same for a product priced on request: no figure, and no estimate.

## 10. Explicit owner approval status

Ordered by the owner on 27 November ("Start next"). He has not seen the result. Merged by the agent when every
check is green, by his standing word of 17 November (DL-026). Nothing here is accepted by him. What was decided
on the way is DL-050, and each point of it can be reversed by saying so.

## The phase, in the owner's form

| | |
|---|---|
| **Phase** | P0, point 8, its second half: a visitor asks for a product the storefront read from Laravel |
| **Status** | Built and proved on a rehearsal and in CI. **Not deployed as a working link; not complete** |
| **Files** | See point 1 |
| **Database** | No change of structure. Written in the proof: `leads`, `contacts`, `storefront_quote_requests` (the lines in its column `lines`), the keys of the creates. Read: the published copy of a product |
| **APIs** | `POST /api/v1/quote-requests`: a line may name a published product by `slug` and its option by `sku`. Two headers of the shop's server, `X-Woodex-Key` and `X-Woodex-Visitor`, on that address and on every address that is read. The contract and document 29 say both. No address was added |
| **UI** | The admin: the basket on an enquiry's page. The shop: the product's page and card, the cart, the checkout and the request page, for a product of the admin |
| **Integration** | The visitor's page → the shop's server → the admin, and back. One writer of an enquiry and of a product: the admin. The shop's database is not written |
| **Tests** | Point 3 |
| **Browser** | The journey, twice, with its three new steps; point 4 |
| **Deployment** | Both Vercel projects deploy this merge. The link stays off on the live shop. No Hostinger |
| **Risks** | A shop switched over without the key works for a few visitors and then says that its catalogue is not available (D-082): the key is in the list of the switch (document 24, §6), and the shop's server says when it is missing. An open page costs the host nine reads a minute (T-200) |
| **Owner input** | Nothing new. The three of before, below |
| **Next phase** | T-192: the addresses for series, filters and search. Its own branch and pull request |

## What is not cured, and why

- **An order for a product of the admin.** The admin does not take orders (T-106, which waits for the role
  matrix and for its own lines). Until it does, such a product is asked for by quotation, also when it has a
  price. For a product with a price that is less than a visitor of the shop has today.
- **An enquiry without a basket** (the contact form, the request form with nothing in the basket) still goes to
  the shop's own database, with the link on too (T-100).
- **What an open page costs the host** (T-200), above.
- **A visitor over his own limit** (more than about thirteen open pages) is told that the catalogue is not
  available, not that he asked too often: the shop's server answers 503 for every refusal of the admin. Changed
  with T-200.
- **The robot check on the live shop.** A carried basket is put to it when the page made a token. Whether the
  shop is built with the check's public key, and the admin given its secret, are settings nobody has made:
  until then the limits of both servers and the trap field guard a basket, as they guard the shop's own
  baskets today.
- **The other two tools that serve SQLite with several workers** (the rehearsal without the shop, the sandbox's
  preview) were not given the setting of D-084.
- **The check from outside runs about twice a day, not four times** (T-142): fifteen runs for twenty-six times
  due between 21 and 28 November, all green. GitHub starts a timer when it will.

## What is not proved

- **A live site**: none has the link on, and no live admin has a database.
- **MySQL or MariaDB in the sandbox**: the acceptance there ran on a database file. CI runs it on MariaDB 10.11.
- **The visitor's address on Vercel.** The shop's server names the address it was asked from. On the rehearsal
  that address was given to it as a proxy would give it. That Vercel hands the visitor's own address to the
  shop's server in the way the server reads it was not tried: it is tried on the day of the switch, by sending
  six baskets from one place and reading which is refused.
- **Cloudflare's own answer.** The check of a token was tried against a stand-in that answers yes, no, or not
  at all; no real token was made.
- **The real catalogue through the link**: two products were read and asked for, not 170.

## What the owner is asked

Nothing new. The three of 27 November stand, and the first is the urgent one:

1. **Change the old dashboard's password** (D-002, critical): `woodex-2030.vercel.app/dashboard` → Settings →
   "Your password". Five minutes; only he can.
2. **Which Hostinger plan is it?** (document 46, section 5.)
3. **A database for the real admin**: the ten steps of [document 24](24-HOSTINGER-STEP-BY-STEP.md) (T-006).
   With it, and the products published, the live shop can be switched: nothing of the agent's stands in the
   way any more.

## What comes next

T-192, then T-193 and T-200; the plan is [document 21](21-PENDING-WORK-PLAN-AND-TESTS.md).

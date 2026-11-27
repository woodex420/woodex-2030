# WOODEX OS — the rules of `/api/v1`

Written 19 November 2026 with master list line T-063. It is the document the code, the contract and the shop's
files point at. When a rule here and the code disagree, the code is wrong or this page is: say so, and change one
of them in the same commit as the other.

**Which `/api/v1` this is.** The one the Laravel admin (`platform/`) answers: what the shop and outside clients
use to send enquiries and read what the admin has published. The old Node API of the React shop (`apps/api`) also
answers under `/api/v1/…` on the shop's own site. That is another program, on another site, being replaced part
by part; nothing on this page describes it.

**Who these addresses are for.** The shop, and later other programs of the company. **Not staff**: the admin's
screens are drawn by the server and have no JSON API. Nobody needs to write one for them, and a screen of the
admin never calls these addresses.

## The short version

1. Every answer is JSON in one of four shapes: one thing, a whole list, one page of a list, or an error (§1).
2. An error always carries a **code** from a closed list of fifteen (§2). A program decides by the code, a person
   reads the message.
3. Every answer carries the **request number**, in the body (`meta.request_id`) and in the header
   `X-Request-Id`. They are the same (§3).
4. A list is either handed out whole or in pages; which of the two is part of the address's contract (§4).
5. An address reads only the query parameters it names. Any other is refused, not ignored (§5).
6. Everything that **creates** takes an `Idempotency-Key` header. The same key sent twice writes once and is
   answered the same (§6).
7. **Money** is a whole number of minor units (paisa) in a key that ends in `_minor`, with its currency beside
   it. Never a fraction (§7).
8. **Times** are UTC, to the second, ending in `Z` (§8).
9. Nothing needs a **sign-in** today. What protects the creates is in §9; what a private address will need when
   the first one is built is there too.
10. The rules are held by a **contract file** and a **contract test**. An address added without its contract
    fails the test (§12).

## 1. The four shapes

One thing: a record that was made, a result, or one document made of several lists.

```json
{ "data": { "reference": "L-2026-000123", "handoff": null },
  "meta": { "request_id": "7K3M-9QWT-2HXA" } }
```

A whole list: one a client needs complete to work with. It is never cut into pages. Its size is `meta.total`.

```json
{ "data": [ { "key": "hero-1", "url": "/api/v1/media/12/3" } ],
  "meta": { "request_id": "7K3M-9QWT-2HXA", "total": 1 } }
```

One page of a list:

```json
{ "data": [ … ],
  "meta": { "request_id": "7K3M-9QWT-2HXA", "page": 2, "per_page": 25, "total": 61 },
  "links": { "self": "/api/v1/…?page=2&per_page=25", "next": "/api/v1/…?page=3&per_page=25", "prev": "/api/v1/…?page=1&per_page=25" } }
```

An error:

```json
{ "error": { "code": "VALIDATION_FAILED", "message": "The submitted data is invalid.",
             "details": { "email": ["The email field must be a valid email address."] } },
  "meta": { "request_id": "7K3M-9QWT-2HXA" } }
```

What holds for all four:

- `data` and `error` never stand in one answer.
- `data` is an object or a list as the contract says, also when it is empty: `{}` and `[]` are not the same and
  are never swapped.
- `error.details` is always an object. For a refused field it holds the field's name and its messages; for a
  refused parameter it holds `refused` and `allowed`; otherwise it is `{}`.
- A key that the contract names is always there. A value that is not known is `null`, not a missing key.
- The links of a page are addresses without the site's name (`/api/v1/…`), so that they are as true on the shop's
  own server, which hands these answers on, as they are here. `next` and `prev` are `null` at the ends.
- Two addresses answer with a picture, not JSON (§11). Their errors are JSON in the error shape.

## 2. The codes

A closed list. A new code is added here, to `App\Support\Api\ApiErrors::CODES` and to the contract in one commit;
the contract test fails on an answer whose code is not in the list.

| Status | Code | When it is given |
|---|---|---|
| 400 | `BAD_REQUEST` | The body is not JSON, or is JSON but not an object. Also any refusal of the framework that has no code of its own |
| 400 | `PARAMETER_NOT_ALLOWED` | The address was given a query parameter it does not name. `details.refused` lists what was refused, `details.allowed` what the address takes |
| 400 | `IDEMPOTENCY_KEY_REQUIRED` | A create came without an `Idempotency-Key` header, or the key is not a UUID |
| 401 | `UNAUTHENTICATED` | A sign-in is needed and none was given. No address gives it today |
| 403 | `FORBIDDEN` | Signed in, and not allowed. No address gives it today |
| 404 | `NOT_FOUND` | Nothing is at this address; or the record is not there; or the part is switched off (then every method gets 404, as if it had never been there) |
| 405 | `METHOD_NOT_ALLOWED` | The address exists and does not take this method. The header `Allow` names what it takes |
| 409 | `CONFLICT` | Somebody else was writing the same records in that moment and the database ended this request. Nothing was written. Send it again, with the same key |
| 409 | `IDEMPOTENCY_KEY_REUSED` | The key was used before, for a request with other content. Make a new key for a new request |
| 413 | `PAYLOAD_TOO_LARGE` | The request is larger than the server takes |
| 415 | `UNSUPPORTED_MEDIA_TYPE` | A body that is not sent as `Content-Type: application/json` |
| 422 | `VALIDATION_FAILED` | A field was refused. `details` names each refused field with its messages. A failed robot check is this code on the field `turnstile_token` |
| 429 | `TOO_MANY_REQUESTS` | The limit of the address was reached (§10). The header `Retry-After` says how many seconds to wait |
| 500 | `SERVER_ERROR` | A fault on our side. The answer says nothing about it but the request number, with debugging on or off; the fault is in the log under that number |
| 503 | `UNAVAILABLE` | The address is there and what it hands out is switched off for now (the category list, when its switch is off) |

A status the list does not know is answered as its family: a refusal as 400 `BAD_REQUEST`, a fault as 500
`SERVER_ERROR`.

The React shop's own server stands between the browser and two of these addresses (categories, photographs). It
answers in the same error shape and adds two codes of its own, which this application never gives:
`SWITCHED_OFF` (503: the shop's switch for that part is off) and `BAD_GATEWAY` (502: the admin did not answer, or
not in the shape of this page). A shop that Vercel builds while it is connected to no admin does not ask at all
(`scripts/build-vercel.mjs`, decision DL-048): there the answer was known before the first visitor came, and
every visit was two refused requests. Asked all the same, its server answers as above.

The statuses of a good answer: **200** read, or a create that joined something that was there; **201** created;
**202** taken and not processed (a request this application took for a robot's is answered 202 with `null` values,
and nothing is written).

## 3. The request number

Every request gets a number of twelve signs, `7K3M-9QWT-2HXA` (master list T-062). It is in the header
`X-Request-Id` of every answer and in `meta.request_id` of every JSON answer, and the two are equal. Every line
the request writes to the log carries it. A client that shows an error to a person shows the number with it;
whoever looks for the fault finds it by the number.

A client may send its own `X-Request-Id` (a plain token of 8 to 120 signs). It is written to the log beside
ours, never instead of it; ours is the one in the answer.

## 4. Lists and pages

- A list that a client needs whole (today: the photographs that were replaced) is handed out whole, with
  `meta.total`. It is never cut into pages: a client that read page one before a change and page two after it
  would hold a list that never existed.
- Any other list comes in pages, with `page` and `per_page` as its query parameters, `meta.page`,
  `meta.per_page`, `meta.total`, and `links`. **`GET /catalogue/products` is the first address in pages**
  (T-105); every other list of today is whole. The builder for them
  (`ApiAnswer::page`) is written and tested, so that the first such list (the products, T-105) has one way to do
  it.
- Which of the two a list is stands in the contract and does not change without a new version.

## 5. Query parameters

An address reads the query parameters its route names (`api.v1:query=a|b`) and no others. A parameter it does not
name is answered 400 `PARAMETER_NOT_ALLOWED`. So a filter that is misspelt does not silently hand out the
unfiltered list, and a client cannot come to rely on a parameter that was never promised.

Ten addresses of today. One takes query parameters: `GET /catalogue/products` reads `page`, `per_page`,
`category`, `series` and `since`, and refuses any other (§5).

## 6. Creating: the `Idempotency-Key`

A visitor presses the button twice, a phone loses its connection and sends again, a program retries. Each time
the same thing must be written once.

- Every address that creates takes the header `Idempotency-Key`: a UUID the client makes, **one for each thing
  it wants created**, and sends again unchanged with every retry of that same thing.
- The first request with a key does the work. A later one with the same key and the same content does nothing
  and is answered what the first one was: the same status, the same `data`, and the header
  `Idempotent-Replayed: true`.
- The same key with **other content** is refused: 409 `IDEMPOTENCY_KEY_REUSED`. Nothing is written.
- "The same content" is decided on the fields the visitor filled in, in any order, without the empty ones and
  without what changes from one sending to the next (the token of the robot check, the time the form was opened).
- A request that was refused (a field, the robot check, a limit) or that failed leaves the key free: the same
  key may be sent again with the corrected request.
- A second sending is not asked the robot check again. The first one passed it, and its token cannot be used
  twice.
- Two requests with one key in the same moment: the first writes, the second waits for it and is answered what
  the first one was. Tried with two processes on MySQL and MariaDB on every change
  (`platform/tools/ci/two-writers.php`, the steps `one-key-two-enquiries` and `one-key-two-baskets`).
- Keys are kept apart for each kind of create: the key of an enquiry says nothing about a basket.
- What is kept: a hash of the key, a hash of the content, the status and what the answer is made from. **Not**
  the key itself, not what was sent, and not the hand-off token (it is worked out again from the enquiry and
  the key, so a second sending gets the same one).
- Keys are kept without an end date today. Clearing old ones is open (§13).

The hand-off of an enquiry to WhatsApp (`POST /leads/whatsapp-handoff`) takes no key: the token it is given is
its key, and is good once.

## 7. Money

Money is a **whole number of minor units**: paisa for rupees. Its key ends in `_minor`, and the currency stands
beside it in a key `currency` as three letters (`PKR`). So `"price_minor": 4250000, "currency": "PKR"` is
Rs 42,500.00. Never a fraction, never text with a decimal point, never a formatted amount.

**`GET /catalogue/products` carries money** — `price_minor`, `was_price_minor`, `currency` on a product and on
each of its options (T-105). The rule above has three consequences, each held by a test
(`tests/Feature/Api/ProductCatalogueApiTest.php`):

- A price is a whole number of paisa. The editor keeps rupees as text ("42500.50"); the answer converts once, and
  the builder of answers refuses to send any fraction, wherever it stands in an answer.
- **A product with no price is not a product that costs zero.** Publication refuses a product without a base
  price, so every published product carries a figure; what the shop must not do is print it as a buying price
  when the editor published it to ask about. That is the switch `on_request`, and `availability` says it in the
  admin's own words. The shop shows the product, offers a quote, and never a cart.
- `was_price_minor` is the regular price while a sale is on, and `null` at every other time: a sale price is
  handed out only between its two dates.

A number that is not whole and is not money (a width in metres) is written as text.

## 8. Times

A moment is written in UTC, to the second, ending in `Z`: `2026-11-19T04:15:00Z`. Whatever the clock of the
site is (it is Pakistan time), no answer carries a local time or an offset like `+05:00`. A day without a time
is `2026-11-19`.

## 9. Signing in, and what protects the creates

**Nothing under `/api/v1` needs a sign-in today.** What is read is what the admin has published for everyone;
what is created is an enquiry or a basket of a visitor nobody knows yet. No answer sets a cookie, and none
reads one.

The creates are protected by:

1. **The switch of the part.** `POST /leads`, `POST /leads/whatsapp-handoff` and `POST /quote-requests` exist
   only where the admin's module for them is on (`lead_api`; the basket also needs `quote_requests`). Off, every
   method gets 404.
2. **The limits** of §10, counted for each address a request comes from.
3. **The robot check** (Cloudflare Turnstile): the field `turnstile_token`. Where the check is not set up on a
   site, a create without the shop's key (next point) is refused.
4. **The shop's own key**, the header `X-Woodex-Key`: the shop's server, which has already checked its visitor,
   sends it and is not asked the robot check. The key is a setting of the site and is compared in constant
   time. It lets a request skip the robot check and nothing else.
5. **The trap for robots**: a field no person sees, and the time a form was open. A request caught by it is
   answered 202 with `null` values, as if it had been taken, and nothing is written.

**When the first private address is built** (a client's own orders, a partner's stock), it will need a sign-in,
and that is a decision of its own, not made here: a session cookie of the shop's own site, or a token of the
caller (§13). Until then `UNAUTHENTICATED` and `FORBIDDEN` are in the list of codes and no address gives them.

## 10. Limits

| Addresses | Limit, for each address a request comes from |
|---|---|
| `POST /leads`, `POST /quote-requests` | 5 a minute and 50 a day, counted together, and together with the older `POST /api/public/leads` |
| `POST /leads/whatsapp-handoff` | 20 a minute |
| Every address that reads, and any address that is not there | 120 a minute |

Over the limit: 429 `TOO_MANY_REQUESTS`, with `Retry-After` in seconds. The numbers are the ones the addresses
had before this page; whether they fit a busy day of the shop is open (§13).

## 11. What every answer carries, and browsers on other sites

Every JSON answer: `X-Request-Id`; `Cache-Control: no-store` (the creates: `no-store, private`), so nothing in
between keeps it; `X-Robots-Tag: noindex, nofollow`; no cookie.

The two addresses that answer with a picture (`…/hero/{revision}` and `/media/{media}/{version}`) send the file
with `X-Content-Type-Options: nosniff` and `X-Robots-Tag: noindex, nofollow`. The last number in the address
changes when the picture does, and the old number then answers 404. A replaced photograph may be kept for thirty
seconds by what stands in between; the picture at the head of a category page is not kept.

**Browsers on other sites.** A page of another website may call the three creates, and only those, and only from
the websites named in the site's setting `PUBLIC_LEADS_ALLOWED_ORIGINS`. It may send `Content-Type`, `Accept`,
`Idempotency-Key`, `X-Woodex-Key` and `X-Requested-With`, and may read `X-Request-Id`, `Idempotent-Replayed` and
`Retry-After`. No cookie goes with such a request. The addresses that read are not opened to other websites: the
shop's own server asks for them, not the visitor's browser.

## 12. The seven addresses, and how the rules are held

| Method and address | What it is | Takes | Good answers |
|---|---|---|---|
| `POST /api/v1/leads` | An enquiry of a visitor | key | 201 made · 200 joined the visitor's open enquiry · 202 taken for a robot's. `data`: `reference`, `handoff` (`token`, `expires_at`, or `null`) |
| `POST /api/v1/leads/whatsapp-handoff` | Turn the hand-off token of an enquiry into the WhatsApp link, once | the token | 200. `data`: `delivery`, `whatsapp_url` |
| `POST /api/v1/quote-requests` | A basket of products sent for a quotation | key | 201 made · 202 taken for a robot's. `data`: `reference`, `status` |
| `GET /api/v1/catalogue/categories` | The published category tree with the page of each category, and the series | — | 200. `data`: `categories`, `series` |
| `GET /api/v1/catalogue/categories/{category}/hero/{revision}` | The picture at the head of a category page | — | 200, the picture |
| `GET /api/v1/media` | The photographs of the shop that the admin has replaced | — | 200, a whole list. `data`: `[{key, url}]` |
| `GET /api/v1/media/{media}/{version}` | One of those photographs | — | 200, the picture |

Anything else under `/api/v1`: 404. A wrong method on one of the seven: 405 with `Allow`.

**The contract** is `platform/resources/api/v1.openapi.json` (OpenAPI 3.1): every address, the fields each create
reads, every status it can answer, and the shape of each answer key by key.

**The contract test** is `platform/tests/Feature/Api/V1ContractTest.php`. It fails when:

- a route under `/api/v1` is not in the contract, or the contract names an address that has no route;
- a route does not carry the door `api.v1` with what the contract says the address takes (the key, the query
  parameters);
- an answer has a key the contract does not name, lacks one it names, or holds another kind of value: asked of
  every address for every status the contract documents (a good answer, 400, 404, 405, 409, 415, 422, 429, 500,
  503 where they apply);
- a status is documented and not asked for by the test, or asked for and not documented;
- a create reads a field the contract does not name, or the contract names one the create does not read;
- an answer carries a fraction, a time that is not UTC, or a request number that differs from the header;
- an error names a class of the application, or says more about a fault than the request number;
- the older enquiry address (`POST /api/public/leads`) no longer answers in the shape its callers read.

`tests/Feature/Api/IdempotencyTest.php` holds §6, `tests/Feature/Api/ApiAnswerTest.php` the builder of answers.
The shop's side is held by `scripts/test-storefront-lead-adapter.mjs`, `test-basket-adapter.mjs`,
`test-media-sync.mjs` and `test-native-category-sync.mjs`, which run in CI on every change.

**To add an address:**

1. Write it into the contract: the address, what it takes, every status, every key of every answer.
2. Add the route in the `v1` group of `platform/routes/api.php`, named, with `api.v1` and what it takes
   (`api.v1:key` for a create, `api.v1:query=a|b` for the parameters it reads), above the last route of the
   group.
3. Build every answer with `App\Support\Api\ApiAnswer`. Refuse with `App\Support\Api\Problem` and a code of §2;
   let validation and missing records be answered by the handler. Never `response()->json(…)`.
4. A create goes through `App\Support\Api\Idempotency::once`, and gets a step in
   `platform/tools/ci/two-writers.php`.
5. Give the contract test the requests that produce each documented status. It tells you which are missing.
6. Move the shop's caller in the same commit, through `apps/storefront/src/lib/apiV1.ts`.

**To change an address:** a new key in an answer, or a new field a create may be sent, is added to the contract
and the code together and breaks no client. Taking a key away, changing what a key means or its kind, or making a
field required breaks the shop: the shop's caller moves in the same commit and the release report names it; once
a program outside this repository uses the address, such a change is a new version (`/api/v2`), not a change of
this one.

## 13. What is decided here, and what is still open

The owner's API document (`spec/04-API-SPECIFICATION.md` §10) lists six open decisions. Where this page stands on
each:

| Open decision of the specification | Here |
|---|---|
| Money: decimal text or whole minor units | **Decided: whole minor units**, key ending `_minor`, currency beside it (§7). It is how the admin keeps money, so nothing is converted on the way |
| Route names compatible with the endpoints that exist | **Decided for these seven**: the addresses stay as they were; only the shape of the answers changed (release report of 19 November). The older `POST /api/public/leads` and the old website's two addresses keep address and shape |
| Limits of the API and of public forms | **Kept as they were** (§10). Whether 5 enquiries a minute from one address fits an office that sits behind one address is the owner's to say when the shop's forms are switched over |
| Cookie or token for a sign-in | **Open.** Nothing needs a sign-in yet (§9). To be decided with the first private address |
| Public checkout and a payment gateway | **Answered by the owner before, and not this page's**: no online payment at checkout at launch, cash on delivery; payments are recorded by hand in the admin (master list §3, "Already answered"). An address for orders comes with T-106 |
| Webhooks: how they prove who they are, and idempotency | **Open for webhooks; for creates it is §6.** The webhooks this application receives and sends are outside `/api/v1` and are not changed by this page |

Also open, smaller:

- **Old keys.** A key is kept without an end date. A day's enquiries are a few rows; a timed job that clears keys
  older than, say, thirty days belongs with the other timed jobs (T-134).
- **Languages.** Messages are in English. The codes are what a program reads, so a shop in Urdu can show its own
  sentence for each code.
- **A version in the address.** `/api/v1` is the only version. §12 says what makes a second one.

## 14. What this page does not cover

- `POST /api/public/leads`: the older enquiry address, kept in its older shape for the callers that read it.
- `/api/forms.php` and `/api/chat.php`: the two addresses the old website's own scripts call, answered in the
  shape those scripts expect.
- `/api/public/chat/…`, `/api/mcp`, `/api/bridge/v1/…`, `/webhooks/…`: each has its own rules and its own tests.
- The Node API of the React shop (`apps/api`), as said at the top.

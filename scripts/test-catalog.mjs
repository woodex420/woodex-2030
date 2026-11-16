#!/usr/bin/env node
/**
 * The catalogue: SKUs, product states, price on request, made-to-order stock,
 * options, and the import of the real catalogue.
 *
 *   node --experimental-sqlite scripts/test-catalog.mjs
 *   WOODEX_TEST_DB=pglite node --experimental-sqlite scripts/test-catalog.mjs
 *
 * The assertions that matter, in the order a real catalogue meets them:
 *
 *   1. A DRAFT IS INVISIBLE. Not "filtered in the storefront": the public API
 *      answers a draft exactly as it answers a product that does not exist,
 *      on the list, on the product, in the price feed, in the sitemap, in an
 *      order and in a quotation. One leak and "load it, check it, then show
 *      it" stops being true.
 *
 *   2. A SKU NAMES ONE THING. Across products and options, without regard to
 *      case, and the database agrees even if the API is bypassed. The live
 *      shop has 17 codes on 36 products; that must be impossible here.
 *
 *   3. NO PRICE IS NOT A PRICE OF ZERO. A product on request can be quoted and
 *      cannot be ordered. The old shop sells its PKR 0 products at PKR 0.
 *
 *   4. NOTHING IS INVENTED. No rating, no review count, and no stock quantity
 *      that nobody counted — in what the API stores or in what it returns.
 *
 *   5. THE IMPORT CHANGES NOTHING TWICE AND PUBLISHES NOTHING ONCE. A second
 *      run must not undo a correction staff made after the first.
 *
 *   6. THE CODE SURVIVES A DATABASE THAT HAS NOT BEEN MIGRATED. The hosted
 *      database receives 019 by hand, before or after the deploy. Until it
 *      does, the catalogue must keep working exactly as it did.
 */
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { spawnApi, baseUrlOf, rawDb, ENGINE, IS_POSTGRES } from "./lib/api-under-test.mjs";
import { createPgliteDatabase, openPglite, applyFullSchema } from "./lib/pg-fixture.mjs";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const tempRoot = path.join(os.tmpdir(), `woodex-catalog-${process.pid}`);
const problems = [];
const check = (ok, label, detail = "") => {
  if (ok) console.log(`  ✓ ${label}`);
  else { problems.push(`${label}${detail ? ` — ${detail}` : ""}`); console.log(`  ✗ ${label}${detail ? ` — ${detail}` : ""}`); }
};
const same = (actual, expected, label) => check(JSON.stringify(actual) === JSON.stringify(expected), label, `got ${JSON.stringify(actual)}, expected ${JSON.stringify(expected)}`);

const client = (base) => async (p, opts = {}) => {
  const res = await fetch(base + p, {
    method: opts.method ?? "GET",
    headers: { "content-type": "application/json", ...(opts.token ? { authorization: "Bearer " + opts.token } : {}) },
    body: opts.body ? JSON.stringify(opts.body) : undefined,
  });
  const text = await res.text();
  let json = null;
  try { json = JSON.parse(text); } catch { /* non-JSON is itself a finding */ }
  return { status: res.status, json, text };
};
const signIn = async (api) => (await api("/api/auth/login", { method: "POST", body: { email: "admin@woodex.pk", password: "admin" } })).json?.token;
const refuses = async (db, sql, ...params) => { try { await db.run(sql, ...params); return false; } catch { return true; } };

const dataset = JSON.parse(fs.readFileSync(path.join(ROOT, "apps/api/catalog-data.json"), "utf8"));
const DEMO = JSON.parse(fs.readFileSync(path.join(ROOT, "apps/api/seed-data.json"), "utf8")).products;
const IMG = ["/img/desk-product.jpg"];
const desk = (extra) => ({ name: "Probe Desk", category: "office-tables", price: 40000, images: IMG, ...extra });

const children = [];
try {
  fs.mkdirSync(tempRoot, { recursive: true });
  const child = await spawnApi({ tempRoot });
  children.push(child);
  const base = await baseUrlOf(child);
  const api = client(base);
  const db = rawDb(child);
  const token = await signIn(api);
  check(Boolean(token), `operator signed in (${ENGINE})`);
  const staff = (p, opts = {}) => api(p, { ...opts, token });
  const publicIds = async () => (await api("/api/products")).json.items.map((p) => p.id);

  /* ───────── 1. what is already there keeps working ───────── */
  console.log("\n[1] the existing catalogue after the upgrade");
  const first = await api("/api/products");
  same([first.status, first.json?.total], [200, DEMO.length], "the demo catalogue is still public, every product of it");
  const sample = first.json.items[0];
  same([sample.status, sample.priceOnRequest, sample.trackStock, sample.sku, Array.isArray(sample.variants)], ["published", false, true, null, true],
    "an existing product reads as published, priced, stock counted, no SKU, no options");
  check(first.json.items.every((p) => !("rating" in p) && !("reviews" in p)), "no product carries a rating or a review count");
  check(DEMO.every((p) => "rating" in p && "reviews" in p), "…although the stored demo documents still hold them (the API drops them on the way out)");
  check(!("counts" in first.json), "the public is not told how many drafts exist");
  check(first.json.items.every((p) => !("review" in p) && !("source" in p)), "the public never receives the import's working notes");

  /* ───────── 2. states ───────── */
  console.log("\n[2] draft, published, archived");
  const draft = await staff("/api/products", { method: "POST", body: desk({ id: "probe-draft" }) });
  same([draft.status, draft.json?.status], [201, "draft"], "a new product is a draft unless it says otherwise");
  check(!(await publicIds()).includes("probe-draft"), "a draft is not on the public list");
  same((await api("/api/products/probe-draft")).status, 404, "a draft answers 404 to the public, like a product that does not exist");
  same((await api("/api/products/no-such-product")).status, 404, "…which is the same answer a missing product gets");
  same((await staff("/api/products/probe-draft")).json?.status, "draft", "staff can open the draft");
  same((await api("/api/products?status=draft")).status, 401, "the public may not ask for drafts");
  same((await api("/api/products?status=all")).status, 401, "…nor for everything");
  same((await api("/api/products?status=published")).status, 200, "asking for the published list is always allowed");
  same((await api("/api/products?status=nonsense")).status, 400, "an unknown state is refused, not ignored");
  const drafts = await staff("/api/products?status=draft");
  same(drafts.json?.items.map((p) => p.id), ["probe-draft"], "staff asking for drafts get the draft");
  same(drafts.json?.counts, { draft: 1, published: DEMO.length, archived: 0 }, "staff are told how many products are in each state");
  same((await staff("/api/products?status=all")).json?.total, DEMO.length + 1, "staff asking for all get all");
  same((await staff("/api/products")).json?.total, DEMO.length, "without a state even staff get the published catalogue: one default, for everybody");
  const feed = await api("/api/products/overrides");
  check(!feed.json.items.some((p) => p.id === "probe-draft"), "the price feed the storefront polls does not carry the draft");
  check(!(await api("/api/sitemap.xml")).text.includes("/shop/probe-draft"), "the sitemap does not list the draft");

  const blocked = await staff("/api/products/probe-draft", { method: "PATCH", body: { status: "published" } });
  same([blocked.status, blocked.json?.code], [409, "publish_blocked"], "a product with no SKU cannot be published");
  const published = await staff("/api/products/probe-draft", { method: "PATCH", body: { status: "published", sku: "pd-10001" } });
  same([published.status, published.json?.status, published.json?.sku], [200, "published", "PD-10001"], "with a SKU it is published; the SKU is stored upper-case");
  check((await publicIds()).includes("probe-draft"), "a published product is on the public list");
  check((await api("/api/sitemap.xml")).text.includes("/shop/probe-draft"), "…and in the sitemap");
  same((await staff("/api/products/probe-draft", { method: "PATCH", body: { sku: "" } })).json?.code, "publish_blocked", "a published product cannot lose its SKU");
  const archived = await staff("/api/products/probe-draft", { method: "PATCH", body: { status: "archived" } });
  same(archived.json?.status, "archived", "it can be archived");
  same((await api("/api/products/probe-draft")).status, 404, "an archived product is gone for the public");
  same((await staff("/api/products/probe-draft", { method: "PATCH", body: { status: "published" } })).json?.status, "published", "…and can be brought back");
  same((await staff("/api/products", { method: "POST", body: desk({ id: "probe-straight", status: "published" }) })).json?.code, "publish_blocked", "creating a product as published needs a SKU too");
  same((await staff("/api/products", { method: "POST", body: desk({ id: "probe-bad-state", status: "live" }) })).status, 400, "an unknown state is refused on create");
  const legacy = DEMO[0].id;
  const legacyEdit = await staff(`/api/products/${legacy}`, { method: "PATCH", body: { price: DEMO[0].price + 1000 } });
  same([legacyEdit.status, legacyEdit.json?.status, legacyEdit.json?.sku], [200, "published", null],
    "a product published before the rule existed can still be edited without a SKU");
  const audit = await db.all("SELECT action, entity_id FROM audit_logs WHERE action = 'product.updated' AND entity_id = 'probe-draft'");
  check(audit.length >= 3, "each change of state is in the audit trail", `${audit.length} rows`);

  /* ───────── 3. SKUs ───────── */
  console.log("\n[3] one SKU, one thing");
  const twin = await staff("/api/products", { method: "POST", body: desk({ id: "probe-twin", sku: "PD-10001" }) });
  same([twin.status, twin.json?.code, twin.json?.productId], [409, "sku_taken", "probe-draft"], "a second product cannot take the SKU, and is told who holds it");
  same((await staff("/api/products", { method: "POST", body: desk({ id: "probe-twin", sku: "pd-10001" }) })).status, 409, "…in any spelling of the letters");
  same((await staff("/api/products/probe-twin")).status, 404, "the refused product was not created");
  for (const bad of ["A", "has space", "-lead", "trail-", "x".repeat(41), "naïve"])
    same((await staff("/api/products", { method: "POST", body: desk({ id: "probe-badsku", sku: bad }) })).status, 400, `"${bad.slice(0, 12)}" is not a SKU`);
  same((await staff("/api/products", { method: "POST", body: desk({ id: "probe-two", sku: "PD-10002" }) })).status, 201, "a free SKU is accepted");
  same((await staff("/api/products/probe-two", { method: "PATCH", body: { sku: "PD-10001" } })).json?.code, "sku_taken", "an edit cannot take a SKU either");
  same((await staff("/api/products/probe-two", { method: "PATCH", body: { sku: "PD-10002", name: "Probe Two" } })).status, 200, "saving a product with its own SKU is not a conflict with itself");
  same((await staff("/api/products?status=all&q=pd-10002")).json?.items.map((p) => p.id), ["probe-two"], "staff can find a product by its SKU");
  check(await refuses(db, "INSERT INTO products(id,json,price,in_stock,stock_qty,updated_at,sku) VALUES('probe-raw','{}',1,1,0,'2026-01-01','pd-10001')"),
    "the database itself refuses a duplicate SKU, in any spelling");
  check(await refuses(db, "UPDATE products SET status='live' WHERE id='probe-two'"), "the database refuses a state it does not know");
  check(await refuses(db, "UPDATE products SET sku='  ' WHERE id='probe-two'"), "the database refuses a blank SKU");

  /* ───────── 4. prices ───────── */
  console.log("\n[4] whole rupees, or on request");
  same((await staff("/api/products", { method: "POST", body: desk({ id: "probe-fraction", price: 61999.99 }) })).status, 400, "a price with a fraction is refused");
  same((await staff("/api/products", { method: "POST", body: desk({ id: "probe-zero", price: 0 }) })).status, 400, "a price of zero is refused: it is not a price");
  same((await staff("/api/products", { method: "POST", body: desk({ id: "probe-neg", price: -5 }) })).status, 400, "a negative price is refused");
  same((await staff("/api/products", { method: "POST", body: desk({ id: "probe-both", price: 5000, priceOnRequest: true }) })).status, 400, "a price together with \"on request\" is refused: it cannot be told which was meant");
  same((await staff("/api/products", { method: "POST", body: desk({ id: "probe-was", originalPrice: 99.5 }) })).status, 400, "a \"was\" price with a fraction is refused");
  const onRequest = await staff("/api/products", { method: "POST", body: { ...desk({ id: "probe-request", sku: "PD-10003", status: "published", priceOnRequest: true }), price: undefined } });
  same([onRequest.status, onRequest.json?.price, onRequest.json?.priceOnRequest], [201, 0, true], "a product on request is stored with no price");
  const nowPriced = await staff("/api/products/probe-request", { method: "PATCH", body: { price: 52000 } });
  same([nowPriced.json?.price, nowPriced.json?.priceOnRequest], [52000, false], "entering a price ends \"on request\"");
  const backOnRequest = await staff("/api/products/probe-request", { method: "PATCH", body: { priceOnRequest: true } });
  same([backOnRequest.json?.price, backOnRequest.json?.priceOnRequest, backOnRequest.json?.originalPrice ?? null], [0, true, null], "switching back to \"on request\" clears the price");
  check(await refuses(db, "UPDATE products SET price = 5 WHERE id='probe-request'"), "the database refuses a product that is on request and carries a price");
  check(await refuses(db, "UPDATE products SET price = 10.5 WHERE id='probe-two'"), "the database refuses a price with a fraction");
  check(await refuses(db, "UPDATE products SET price = 0 WHERE id='probe-two'"), "the database refuses a priced product at zero");

  /* ───────── 5. stock ───────── */
  console.log("\n[5] stock is counted, or the product is made to order");
  const mto = await staff("/api/products", { method: "POST", body: desk({ id: "probe-mto", sku: "PD-10004", status: "published" }) });
  same([mto.json?.trackStock, mto.json?.stockQty, mto.json?.inStock], [false, 0, true], "no quantity sent: made to order, quantity 0, available — not 99");
  const counted = await staff("/api/products", { method: "POST", body: desk({ id: "probe-counted", sku: "PD-10005", status: "published", stockQty: 3 }) });
  same([counted.json?.trackStock, counted.json?.stockQty], [true, 3], "a quantity sent: stock is counted");
  same((await api("/api/products/probe-mto/stock")).status, 401, "the stock ledger of a product is not public");
  same((await staff("/api/products/probe-counted/stock")).json?.ledgerQty, 3, "staff can read it, and the opening quantity is in the ledger");
  same((await staff("/api/products/probe-mto/stock")).json?.total, 0, "a made-to-order product starts with no movement at all");

  /* ───────── 6. options ───────── */
  console.log("\n[6] options of a product");
  const option = (body) => staff("/api/products/probe-two/variants", { method: "POST", body });
  const small = await option({ sku: "pd-10002-s", label: "1600 mm", price: 41000, attributes: { size: "1600 mm" } });
  same([small.status, small.json?.sku, small.json?.price, small.json?.attributes], [201, "PD-10002-S", 41000, { size: "1600 mm" }], "an option has its own SKU, price and attributes");
  const large = await option({ sku: "PD-10002-L", label: "1800 mm", price: 46000, position: 1 });
  same((await option({ sku: "PD-10001", label: "Other", price: 1000 })).json?.code, "sku_taken", "an option cannot take a product's SKU");
  same((await option({ sku: "PD-10002-L", label: "Third", price: 1000 })).json?.code, "sku_taken", "…nor another option's");
  same((await staff("/api/products", { method: "POST", body: desk({ id: "probe-steal", sku: "PD-10002-S" }) })).json?.code, "sku_taken", "a product cannot take an option's SKU");
  same((await option({ sku: "PD-10002-X", label: "1600 MM", price: 1000 })).json?.code, "label_taken", "two options of one product cannot read the same");
  same((await option({ sku: "PD-10002-F", label: "Fraction", price: 10.5 })).status, 400, "an option's price is whole rupees too");
  const quoted = await option({ sku: "PD-10002-Q", label: "Custom size", priceOnRequest: true, position: 2 });
  same([quoted.status, quoted.json?.price, quoted.json?.priceOnRequest], [201, 0, true], "an option can be on request");
  const hidden = await option({ sku: "PD-10002-H", label: "Withdrawn", price: 30000, active: false, position: 3 });
  same((await staff("/api/products/probe-two", { method: "PATCH", body: { status: "published" } })).json?.status, "published", "the product is published");
  same((await api("/api/products/probe-two")).json?.variants.map((v) => v.label), ["1600 mm", "1800 mm", "Custom size"], "the public sees the options on sale, in order");
  same((await staff("/api/products/probe-two")).json?.variants.length, 4, "staff see the withdrawn one too");
  same((await api("/api/products/probe-two/variants")).json?.total, 3, "the options route follows the same rule");
  same((await api("/api/products/probe-request/variants")).status, 200, "a published product without options answers an empty list");
  same((await staff(`/api/products/probe-two/variants/${large.json.id}`, { method: "PATCH", body: { price: 47000 } })).json?.price, 47000, "an option's price can be changed");
  same((await staff(`/api/products/probe-two/variants/${large.json.id}`, { method: "PATCH", body: { sku: "PD-10002-S" } })).json?.code, "sku_taken", "an edit cannot take a SKU");
  same((await staff(`/api/products/probe-draft/variants/${large.json.id}`, { method: "PATCH", body: { price: 1 } })).status, 404, "an option is reached only through its own product");
  check(await refuses(db, "INSERT INTO product_variants(product_id,sku,label,price,created_at,updated_at) VALUES('probe-two','pd-10002-s','Dup',5,'2026-01-01','2026-01-01')"),
    "the database refuses a duplicate option SKU");
  /* An option-only product: no SKU of its own, sold by its options. */
  await staff("/api/products", { method: "POST", body: desk({ id: "probe-options-only" }) });
  const only = await staff("/api/products/probe-options-only/variants", { method: "POST", body: { sku: "PD-20001", label: "Only", price: 9000 } });
  same((await staff("/api/products/probe-options-only", { method: "PATCH", body: { status: "published" } })).json?.status, "published", "a product with no SKU of its own can be published once it has an option");
  same((await staff(`/api/products/probe-options-only/variants/${only.json.id}`, { method: "DELETE" })).json?.code, "publish_blocked", "…and then its last option cannot be removed");

  /* ───────── 7. ordering ───────── */
  console.log("\n[7] what a customer can order");
  const order = (items) => api("/api/orders", { method: "POST", body: { customer: "Probe Customer", contact: "0300 0000000", items } });
  await staff("/api/products", { method: "POST", body: desk({ id: "probe-hidden", sku: "PD-10006" }) });
  same((await order([{ id: "probe-hidden", qty: 1 }])).status, 400, "a draft cannot be ordered");
  same((await order([{ id: "probe-hidden", qty: 1 }])).json?.error, (await order([{ id: "no-such-product", qty: 1 }])).json?.error, "…and the refusal reads the same as for a product that does not exist");
  const madeToOrder = await order([{ id: "probe-mto", qty: 7 }]);
  same([madeToOrder.status, madeToOrder.json?.items?.[0]?.sku, madeToOrder.json?.items?.[0]?.price], [201, "PD-10004", 40000], "a made-to-order product can be ordered in any quantity, and the line carries its SKU");
  same((await staff("/api/products/probe-mto/stock")).json?.total, 0, "…and no stock movement is written for it");
  same((await staff("/api/products/probe-mto", { method: "PATCH", body: { inStock: false } })).json?.inStock, false, "switching it off…");
  same((await order([{ id: "probe-mto", qty: 1 }])).status, 409, "…makes it unavailable");
  same((await order([{ id: "probe-counted", qty: 4 }])).status, 409, "a counted product cannot be ordered beyond its stock");
  same((await order([{ id: "probe-counted", qty: 2 }])).status, 201, "within its stock it can");
  same((await staff("/api/products/probe-counted")).json?.stockQty, 1, "…and the stock goes down");
  const refused = await order([{ id: "probe-request", qty: 1 }]);
  same([refused.status, refused.json?.code], [409, "price_on_request"], "a product on request cannot be ordered");
  same((await order([{ id: "probe-two", qty: 1 }])).status, 400, "a product sold by option needs the option");
  const chosen = await order([{ id: "probe-two", qty: 2, variantId: small.json.id }]);
  same([chosen.status, chosen.json?.items?.[0]?.price, chosen.json?.items?.[0]?.sku, chosen.json?.items?.[0]?.variant, chosen.json?.total],
    [201, 41000, "PD-10002-S", "1600 mm", 82000], "with the option: the option's price, SKU and label are on the line");
  same((await order([{ id: "probe-two", qty: 1, variantId: hidden.json.id }])).status, 400, "a withdrawn option cannot be ordered");
  same((await order([{ id: "probe-two", qty: 1, variantId: quoted.json.id }])).json?.code, "price_on_request", "an option on request cannot be ordered");
  same((await order([{ id: "probe-request", qty: 1, variantId: small.json.id }])).status, 400, "an option of another product is refused");

  console.log("\n[8] what a customer can ask a quotation for");
  const quote = (items) => api("/api/quotes", { method: "POST", body: { customer: "Probe Customer", phone: "0300 0000000", items } });
  same((await quote([{ productId: "probe-hidden", qty: 1 }])).status, 400, "a draft cannot be quoted");
  const mixed = await quote([{ productId: "probe-request", qty: 2 }, { productId: "probe-two", qty: 1, variantId: large.json.id }]);
  same(mixed.status, 201, "a product on request can be quoted, next to a priced one");
  same(mixed.json?.items?.map((i) => [i.id, i.price, i.sku, i.variant ?? null]), [["probe-request", 0, "PD-10003", null], ["probe-two", 47000, "PD-10002-L", "1800 mm"]],
    "the on-request line carries no price; the other carries the option's");
  same([mixed.json?.total, mixed.json?.details?.pricingReview?.required, mixed.json?.details?.pricingReview?.unpricedLines], [47000, true, ["Probe Desk"]],
    "the total counts only what has a price, and sales are told a line needs one");
  same((await quote([{ productId: "probe-two", qty: 1 }])).status, 400, "a product sold by option needs the option in a quotation too");

  /* ───────── 9. publishing many, deleting ───────── */
  console.log("\n[9] many at once, and deleting");
  await staff("/api/products", { method: "POST", body: desk({ id: "probe-nosku" }) });
  const bulk = await staff("/api/products/bulk-status", { method: "POST", body: { status: "published", ids: ["probe-hidden", "probe-nosku", "probe-draft", "no-such-product"] } });
  same([bulk.status, bulk.json?.changed, bulk.json?.unchanged, bulk.json?.failed], [200, 1, 1, 2], "each product gets its own answer: one published, one already was, two refused");
  same(bulk.json?.results.find((r) => r.id === "probe-nosku")?.code, "publish_blocked", "the one without a SKU says why");
  check((await publicIds()).includes("probe-hidden") && !(await publicIds()).includes("probe-nosku"), "the public list reflects exactly that");
  same((await staff("/api/products/bulk-status", { method: "POST", body: { status: "published", ids: [] } })).status, 400, "an empty list is refused");
  same((await api("/api/products/bulk-status", { method: "POST", body: { status: "archived", ids: ["probe-hidden"] } })).status, 401, "the public cannot change a state");
  const inUse = await staff("/api/products/probe-counted", { method: "DELETE" });
  same([inUse.status, inUse.json?.code], [409, "product_in_use"], "a product with stock history is not deleted; the answer says to archive it");
  same((await staff("/api/products/probe-counted")).status, 200, "…and it is still there");
  same((await staff("/api/products/probe-options-only", { method: "DELETE" })).status, 200, "a product nothing refers to can be deleted");
  same((await db.all("SELECT id FROM product_variants WHERE product_id = 'probe-options-only'")).length, 0, "…and its options go with it");

  /* ───────── 10. the import ───────── */
  console.log("\n[10] loading the real catalogue");
  const before = (await api("/api/products")).json.total;
  const plan = await staff("/api/catalog/import");
  const heldByDemo = dataset.products.filter((p) => DEMO.some((d) => d.id === p.id)).map((p) => p.id).sort();
  same([plan.status, plan.json?.dataset?.products, plan.json?.summary], [200, dataset.products.length,
    { create: dataset.products.length - heldByDemo.length, replace: 0, present: 0, conflict: heldByDemo.length, heldByDemo: heldByDemo.length }],
    `the plan: ${dataset.products.length - heldByDemo.length} to load, ${heldByDemo.length} addresses held by demo products`);
  same((await api("/api/catalog/import")).status, 401, "the plan is not public");
  same((await api("/api/catalog/status")).status, 401, "nor is the state of the catalogue");
  same((await staff("/api/catalog/import", { method: "POST", body: { dataset: "something-else" } })).json?.code, "dataset_changed", "the import refuses to run on a list other than the one that was shown");
  same((await staff("/api/products?status=draft")).json?.total, 1, "…and loaded nothing");
  const run = await staff("/api/catalog/import", { method: "POST", body: { dataset: dataset.dataset } });
  same([run.status, run.json?.created, run.json?.replaced?.length, run.json?.present, run.json?.conflicts?.length, run.json?.refused?.length, run.json?.published],
    [201, dataset.products.length - heldByDemo.length, 0, 0, heldByDemo.length, 0, 0], "first run: every free address is loaded, nothing is published, nothing is refused");
  same(run.json?.conflicts.map((c) => c.id).sort(), heldByDemo, "the products left out are named");
  same((await api("/api/products")).json.total, before, "the public catalogue did not change");
  const loaded = (await staff("/api/products?status=draft")).json.items.filter((p) => p.source?.site === "woodexfurniture.pk");
  same(loaded.length, dataset.products.length - heldByDemo.length, "every loaded product is a draft");
  check(loaded.every((p) => p.sku && p.trackStock === false && p.stockQty === 0 && p.inStock === true), "each has a SKU, is made to order and has no invented quantity");
  check(loaded.every((p) => p.images.length === 1 && p.images[0].startsWith("https://")), "each has its own image");
  same(loaded.filter((p) => p.priceOnRequest).length, dataset.products.filter((p) => p.priceOnRequest && !heldByDemo.includes(p.id)).length, "the products with no price are on request");
  check(loaded.filter((p) => !p.priceOnRequest).every((p) => Number.isInteger(p.price) && p.price > 0), "the others carry a whole-rupee price");
  const flagged = loaded.find((p) => p.id === "ambassador-executive-table");
  check(flagged?.review?.some((r) => r.code === "discount-high"), "a product keeps the points the build raised about it");
  same(flagged?.source?.sku, "SKU: WST-10001", "…and what the old shop showed, untouched");
  same((await db.all("SELECT COUNT(*) AS n FROM stock_movements WHERE product_id = 'ambassador-executive-table'"))[0].n * 1, 0, "the import writes no stock movement");

  const edited = await staff("/api/products/zone-staff-table", { method: "PATCH", body: { price: 31000, name: "Zone Staff Table (corrected)" } });
  same(edited.json?.price, 31000, "staff correct a draft");
  const again = await staff("/api/catalog/import", { method: "POST", body: { dataset: dataset.dataset } });
  same([again.status, again.json?.created, again.json?.present], [200, 0, dataset.products.length - heldByDemo.length], "second run: nothing new, everything already present");
  const kept = (await staff("/api/products/zone-staff-table")).json;
  same([kept.price, kept.name], [31000, "Zone Staff Table (corrected)"], "the correction survives the second run");

  const replace = await staff("/api/catalog/import", { method: "POST", body: { dataset: dataset.dataset, replaceDemo: true } });
  same([replace.json?.created, replace.json?.replaced?.map((r) => r.id).sort(), replace.json?.conflicts?.length], [0, heldByDemo, 0], "asked to, the import replaces the demo products that held an address");
  for (const id of heldByDemo) {
    const real = (await staff(`/api/products/${id}`)).json;
    const want = dataset.products.find((p) => p.id === id);
    same([real.status, real.name, real.sku, real.trackStock, real.stockQty], ["draft", want.name, want.sku, false, 0], `${id}: now the real product, a draft, with the demo stock written off`);
    check((await staff(`/api/products/${id}/stock`)).json?.reconciles === true, `${id}: its ledger still reconciles`);
  }
  same((await api("/api/products")).json.total, before - heldByDemo.length, "the replaced demo products left the public catalogue; nothing else moved");

  const state = (await staff("/api/catalog/status")).json;
  same([state.schemaReady, state.imported.total, state.imported.published, state.demo.total], [true, dataset.products.length, 0, DEMO.length - heldByDemo.length], "the state of the catalogue adds up");

  console.log("\n[11] releasing it");
  const release = await staff("/api/products/bulk-status", { method: "POST", body: { status: "published", ids: dataset.products.map((p) => p.id) } });
  same([release.json?.changed, release.json?.failed], [dataset.products.length, 0], "every loaded product can be published: each has a SKU");
  const live = (await api("/api/products")).json;
  same(live.total, before - heldByDemo.length + dataset.products.length, "they are all public now");
  const livePublic = live.items.find((p) => p.id === "ambassador-executive-table");
  check(livePublic && !("review" in livePublic) && !("source" in livePublic), "the public still does not receive the review points of a published product");
  same((await staff("/api/catalog/archive-demo", { method: "POST", body: {} })).status, 400, "withdrawing the demo catalogue needs an explicit confirmation");
  const gone = await staff("/api/catalog/archive-demo", { method: "POST", body: { confirm: "archive-demo" } });
  same(gone.json?.archived, DEMO.length - heldByDemo.length, "the demo catalogue is withdrawn");
  const real = (await api("/api/products")).json;
  const probes = real.items.filter((p) => p.id.startsWith("probe-")).length;
  same(real.total - probes, dataset.products.length, "the public catalogue is now the real one, and only the real one");
  same((await staff("/api/catalog/archive-demo", { method: "POST", body: { confirm: "archive-demo" } })).json?.archived, 0, "withdrawing it twice changes nothing");
  same((await staff("/api/products?status=archived")).json?.total >= DEMO.length - heldByDemo.length, true, "the demo products are archived, not deleted");
  const realOrder = await order([{ id: "zone-staff-table", qty: 2 }]);
  same([realOrder.status, realOrder.json?.items?.[0]?.sku, realOrder.json?.total], [201, "ST-10002", 62000], "a real product can be ordered, at the corrected price, under its SKU");
  same((await order([{ id: "evolution-workstation", qty: 1 }])).json?.code, "price_on_request", "a real product with no price cannot be ordered");
  const realQuote = await quote([{ productId: "evolution-workstation", qty: 4 }]);
  same([realQuote.status, realQuote.json?.details?.pricingReview?.required], [201, true], "…and can be quoted, flagged for a price");
  same((await order([{ id: DEMO[5].id, qty: 1 }])).status, 400, "an archived demo product cannot be ordered");
  const stats = (await staff("/api/stats")).json;
  same(stats?.products?.total, real.total, "the dashboard's product count is the published catalogue");
  check(Number.isFinite(stats?.products?.avgPrice) && stats.products.avgPrice > 1000, "the average price ignores products that have none");

  child.kill("SIGKILL");

  /* ───────── 12. a database without migration 019 ───────── */
  if (IS_POSTGRES) {
    console.log("\n[12] a PostgreSQL database that has not received 019");
    const oldRoot = path.join(tempRoot, "old");
    fs.mkdirSync(oldRoot, { recursive: true });
    await createPgliteDatabase(path.join(oldRoot, "pglite"), { upTo: 18 });
    let old = await spawnApi({ tempRoot: oldRoot });
    children.push(old);
    let oldApi = client(await baseUrlOf(old));
    let oldToken = await signIn(oldApi);
    const asStaff = (p, opts = {}) => oldApi(p, { ...opts, token: oldToken });
    same((await oldApi("/api/products")).json?.total, DEMO.length, "the catalogue is served");
    same((await oldApi("/api/products/overrides")).status, 200, "the price feed answers");
    check((await oldApi("/api/sitemap.xml")).text.includes("/shop/" + DEMO[0].id), "the sitemap lists the products");
    same((await asStaff("/api/stats")).json?.products?.total, DEMO.length, "the dashboard figures answer");
    const state18 = (await asStaff("/api/catalog/status")).json;
    check(state18?.schemaReady === false && /019/.test(state18?.schemaNote ?? ""), "the state of the catalogue says the migration is missing");
    const plain = await asStaff("/api/products", { method: "POST", body: desk({ id: "old-plain", stockQty: 5 }) });
    same([plain.status, plain.json?.status], [201, "published"], "a product can be created as it could before: published at once");
    same((await asStaff("/api/products/old-plain", { method: "PATCH", body: { price: 41000 } })).json?.price, 41000, "…and edited");
    same((await oldApi("/api/orders", { method: "POST", body: { customer: "Old", contact: "0300", items: [{ id: "old-plain", qty: 1 }] } })).status, 201, "…and ordered");
    for (const [what, body] of [["a SKU", { sku: "OLD-10001" }], ["a draft", { status: "draft" }], ["price on request", { priceOnRequest: true }], ["made-to-order stock", { trackStock: false }]]) {
      const r = await asStaff("/api/products/old-plain", { method: "PATCH", body });
      same([r.status, r.json?.code], [409, "catalog_migration_pending"], `${what} is refused with the reason, not with a database error`);
    }
    same((await asStaff("/api/products/old-plain/variants", { method: "POST", body: { sku: "OLD-1", label: "A", price: 1 } })).json?.code, "catalog_migration_pending", "an option is refused the same way");
    same((await asStaff("/api/catalog/import", { method: "POST", body: { dataset: dataset.dataset } })).json?.code, "catalog_migration_pending", "the import waits for the migration");
    same((await asStaff("/api/products/bulk-status", { method: "POST", body: { status: "archived", ids: ["old-plain"] } })).json?.code, "catalog_migration_pending", "so does a change of state");
    same((await oldApi("/api/products/old-plain")).json?.price, 41000, "the refused writes changed nothing");

    console.log("\n[13] …and then receives it");
    old.kill("SIGKILL");
    await new Promise((resolve) => old.once("exit", resolve));
    const target = await openPglite(path.join(oldRoot, "pglite"));
    const applied = await applyFullSchema(target);
    await target.close();
    same(applied.applied, ["019_catalogue.sql"], "migration 019 applies to the database that holds data");
    old = await spawnApi({ tempRoot: oldRoot });
    children.push(old);
    oldApi = client(await baseUrlOf(old));
    oldToken = await signIn(oldApi);
    same((await oldApi("/api/products")).json?.total, DEMO.length + 1, "every product that was there is still public");
    same((await asStaff("/api/catalog/status")).json?.schemaReady, true, "the state of the catalogue says the migration is present");
    same((await asStaff("/api/products/old-plain", { method: "PATCH", body: { sku: "OLD-10001", status: "draft" } })).json?.status, "draft", "what was refused now works");
    same((await asStaff("/api/catalog/import", { method: "POST", body: { dataset: dataset.dataset } })).status, 201, "the import runs");
    old.kill("SIGKILL");
  } else {
    /* SQLite has no ledger; it upgrades a file in place at boot. Build the
       table as it was before 019 and let the server meet it. */
    console.log("\n[12] a SQLite file created before 019");
    const { DatabaseSync } = await import("node:sqlite");
    const oldRoot = path.join(tempRoot, "old");
    fs.mkdirSync(oldRoot, { recursive: true });
    const file = new DatabaseSync(path.join(oldRoot, "woodex.db"));
    file.exec(`CREATE TABLE products(id TEXT PRIMARY KEY, json TEXT NOT NULL, price REAL NOT NULL,
      in_stock INTEGER NOT NULL DEFAULT 1, stock_qty INTEGER NOT NULL DEFAULT 99, updated_at TEXT NOT NULL);`);
    file.prepare("INSERT INTO products(id,json,price,in_stock,stock_qty,updated_at) VALUES(?,?,?,?,?,?)")
      .run("old-desk", JSON.stringify({ id: "old-desk", name: "Old Desk", category: "office-tables", images: IMG, rating: 4.9, reviews: 412 }), 30000, 1, 99, "2026-01-01T00:00:00.000Z");
    file.close();
    const old = await spawnApi({ tempRoot: oldRoot });
    children.push(old);
    const oldApi = client(await baseUrlOf(old));
    const oldDb = rawDb(old);
    const columns = (await oldDb.all("PRAGMA table_info(products)")).map((c) => c.name);
    check(["sku", "status", "price_on_request", "track_stock"].every((c) => columns.includes(c)), "the file gained the four columns at boot");
    const oldDesk = (await oldApi("/api/products/old-desk")).json;
    same([oldDesk?.status, oldDesk?.priceOnRequest, oldDesk?.trackStock, oldDesk?.stockQty, oldDesk?.price], ["published", false, true, 99, 30000],
      "the product that was there reads exactly as before: published, priced, stock counted");
    check(!("rating" in oldDesk) && !("reviews" in oldDesk), "…without the invented rating it was stored with");
    const oldToken = await signIn(oldApi);
    same((await oldApi("/api/products/old-desk", { method: "PATCH", token: oldToken, body: { sku: "OLD-10001", status: "draft" } })).json?.status, "draft", "the new features work on the upgraded file");
    check(await refuses(oldDb, "INSERT INTO products(id,json,price,in_stock,stock_qty,updated_at,sku) VALUES('old-dup','{}',1,1,0,'2026-01-01','old-10001')"),
      "…including the unique SKU, enforced by the file itself");
    old.kill("SIGKILL");
  }

  /* ───────── 14. the two schemas say the same thing ───────── */
  console.log("\n[14] the schema, read as text");
  const migration = fs.readFileSync(path.join(ROOT, "scripts/migrations/019_catalogue.sql"), "utf8");
  const server = fs.readFileSync(path.join(ROOT, "apps/api/server.mjs"), "utf8");
  for (const name of ["uq_products_sku", "idx_products_status", "uq_product_variants_sku", "uq_product_variants_label", "idx_product_variants_product"])
    check(migration.includes(name) && server.includes(name), `${name} exists on both engines`);
  check(fs.existsSync(path.join(ROOT, "scripts/migrations/rollback/019_catalogue.rollback.sql")), "the migration has a rollback");
  check(!/rating|reviews/.test(JSON.stringify(dataset.products)), "the prepared catalogue holds no rating and no review count");
} catch (error) {
  problems.push(`rehearsal crashed: ${error.message}`);
  console.error(`\n✗ ${error.stack ?? error.message}`);
} finally {
  for (const c of children) c?.kill("SIGKILL");
  fs.rmSync(tempRoot, { recursive: true, force: true });
}

if (problems.length) {
  console.error(`\n✗ ${problems.length} catalogue problem(s):\n`);
  for (const p of problems) console.error(`  • ${p}`);
  process.exit(1);
}
console.log("\n✓ the catalogue behaves: a draft is invisible, a SKU names one thing, no price is not a price of zero, nothing is invented, and the import neither repeats itself nor publishes.\n");

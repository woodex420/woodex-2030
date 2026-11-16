#!/usr/bin/env node
/**
 * Checks the catalogue build: the rules one by one, the cross-check that
 * guards the capture, and the figures of the real capture.
 *
 *   node scripts/test-catalog-build.mjs
 *
 * Needs no server and no database. The build is a pure function, so every
 * check here feeds it text and reads what comes back.
 *
 * Why the cross-check gets the most attention: the capture was typed in by
 * hand from two pages. The only thing standing between a slip of the keyboard
 * and a wrong price in the shop is the rule that both pages must agree. A test
 * that only proved the happy path would not prove that rule can fail.
 */
import fs from "node:fs";
import {
  buildAll, buildCatalogue, readInputs, renderData, renderReport, renderCsv,
  cleanSku, readSku, cleanName, parseListing, parseQuotation, DATA_FILE, REPORT_FILE, CSV_FILE,
} from "./catalog-build.mjs";

let pass = 0, fail = 0;
const ok = (name, condition, detail = "") => {
  if (condition) { pass++; console.log("  ✓ " + name); }
  else { fail++; console.log("  ✗ " + name + (detail ? " — " + detail : "")); }
};
const eq = (name, actual, expected) => ok(name, JSON.stringify(actual) === JSON.stringify(expected), `got ${JSON.stringify(actual)}, expected ${JSON.stringify(expected)}`);
const throws = (name, fn, pattern) => {
  try { fn(); ok(name, false, "did not throw"); }
  catch (error) { ok(name, pattern.test(error.message), `threw "${error.message.split("\n")[0]}"`); }
};

/* A small shop with one of everything the rules handle. */
const RULES = {
  categoryRules: {
    categories: {
      "desk": { label: "Desk", category: "office-tables", subcategory: "desks", skuPrefix: "DK", family: "desks", nameKeywords: ["desk"] },
      "chair": { label: "Chair", category: "chairs", subcategory: null, skuPrefix: "CH", alsoPrefix: ["CR"], family: "chairs", nameKeywords: ["chair"] },
      "sofa": { label: "Sofa", category: "sofas", subcategory: null, skuPrefix: "SF", family: "sofas", nameKeywords: ["sofa"], new: true, why: "none had a SKU" },
      "series": { label: "Series", notAProductCategory: true },
    },
    specificity: ["desk", "chair", "sofa", "series"],
  },
  noteRules: { notes: { "oak-desk": "A person looked at this." } },
  decisionRules: { decisions: {} },
  capturedOn: "2026-01-01",
};
const listing = (slug, label, rows) => ({
  file: `category-${slug}.txt`,
  text: `# Source: https://example.test/categories/${slug}   label: ${label}   page says: ${rows.length} of ${rows.length} Products\n` + rows.map((r) => r.join(" | ")).join("\n") + "\n",
});
const shop = (overrides = {}) => ({
  ...RULES,
  quotationText: [
    "SKU: DK-10001 | Oak Desk | 50,000.00",
    "DK-10001 | Pine Chair | 9,999.99",
    "— | walnut-side-desk | 0.00",
    "— | Plain Sofa.jpg | 80,000.00",
    "CH-10001 | Tall Chair | 12,000.00",
    "CR-10002 | Low Chair | 4,000.00",
    "— | x | 0.00",
    "CH-100010 | Wide Chair | 15,000.00",
    "CH-10010 | Deep Chair | 15,000.00",
    "CH-1000 | Odd Chair | 15,000.00",
    "CH-10003 | Twin Chair | 20,000.00",
    "SKU: CH-10004 | Twin Chair | 20,000.00",
    "CH-10005 | Lost Chair | 7,000.00",
  ].join("\n") + "\n",
  listings: [
    listing("desk", "Desk", [
      ["oak-desk", "Oak Desk", "50,000", "120,000", "1700000000_Oak%20Desk.jpg"],
      ["walnut-side-desk", "walnut-side-desk", "0", "", "1700000001_w-300x300.jpg"],
    ]),
    listing("chair", "Chair", [
      ["pine-chair", "Pine Chair", "10,000", "", "1700000002_Pine.jpg"],
      ["tall-chair", "Tall Chair", "12,000", "", "1700000003_Low%20Chair.jpg"],
      ["low-chair", "Low Chair", "4,000", "", "1700000004_Low.jpg"],
      ["wide-chair", "Wide Chair", "15,000", "", "1700000005_a.jpg"],
      ["deep-chair", "Deep Chair", "15,000", "", "1700000006_b.jpg"],
      ["odd-chair", "Odd Chair", "15,000", "", "1700000007_c.jpg"],
      ["twin-chair", "Twin Chair", "20,000", "", "1700000008_d.jpg"],
      ["twin-chair-1700000009", "Twin Chair", "20,000", "", "1700000009_e.jpg"],
    ]),
    listing("sofa", "Sofa", [
      ["plain-sofa-jpg", "Plain Sofa.jpg", "80,000", "", "1700000010_Plain.jpg"],
      ["x", "x", "0", "", "1700000011_x.jpg"],
    ]),
    listing("series", "Series", [
      ["lost-chair", "Lost Chair", "7,000", "", "1700000012_Lost.jpg"],
      ["oak-desk", "Oak Desk", "50,000", "120,000", "1700000000_Oak%20Desk.jpg"],
    ]),
  ],
  ...overrides,
});
const product = (result, id) => result.products.find((p) => p.id === id);
const codes = (p) => p.flags.map((f) => f.code).sort();

console.log("\n[1] SKU and name helpers");
eq("R-1 removes one printed label", cleanSku("SKU: MT-10001"), { sku: "MT-10001", hadLabel: true });
eq("R-1 removes a doubled label", cleanSku("SKU: SKU: MT-10002"), { sku: "MT-10002", hadLabel: true });
eq("a clean SKU is left alone", cleanSku("mc-10001"), { sku: "MC-10001", hadLabel: false });
eq("the dash the page prints means no SKU", cleanSku("—"), { sku: null, hadLabel: false });
eq("five digits: place in the series", readSku("MC-10009"), { prefix: "MC", counter: 9 });
eq("six digits written after 1000: place in the series", readSku("MC-100014"), { prefix: "MC", counter: 14 });
eq("five digits past nine", readSku("MT-10013"), { prefix: "MT", counter: 13 });
eq("a truncated code has no place", readSku("SC-1000"), { prefix: "SC", counter: null });
eq("not a code at all", readSku("hello"), { prefix: null, counter: null });
eq("a file extension is removed", cleanName("Venus Executive Sofa.jpg").name, "Venus Executive Sofa");
eq("hyphens used as spaces are replaced", cleanName("Aspire-Conference-Table").name, "Aspire Conference Table");
eq("a hyphen inside a spaced name is kept", cleanName("X-Pro Staff Table").name, "X-Pro Staff Table");
eq("each word gets a capital", cleanName("phoenix Executive chair").name, "Phoenix Executive Chair");
eq("capitals already there are kept", cleanName("QUAD Conference Visitor Chair").name, "QUAD Conference Visitor Chair");
eq("spelling is not corrected", cleanName("Spot Work Manger Chair").name, "Spot Work Manger Chair");
eq("a clean name reports no change", cleanName("Oak Desk").changes, []);

console.log("\n[2] the rules on a small shop");
const small = buildCatalogue(shop());
eq("one row is left out, the rest are loaded", [small.summary.onLiveShop, small.summary.imported, small.summary.skipped], [13, 12, 1]);
eq("R-6: the row that is not a product is listed with its reason", small.skipped.map((s) => s.address), ["x"]);
ok("R-6: the reason names the row", /"x" is not a product name/.test(small.skipped[0].reason));
eq("R-4: the category that owns the prefix keeps the shared SKU", product(small, "oak-desk").sku, "DK-10001");
eq("R-4: the other product gets the next free code of its own category", product(small, "pine-chair").sku, "CH-10006");
ok("R-4: both sides are flagged for the owner", codes(product(small, "oak-desk")).includes("sku-shared-kept") && codes(product(small, "pine-chair")).includes("sku-reassigned"));
eq("R-5: 9,999.99 becomes 10,000", product(small, "pine-chair").price, 10000);
eq("R-3: a price of 0 becomes on request", [product(small, "walnut-side-desk").price, product(small, "walnut-side-desk").priceOnRequest], [0, true]);
eq("R-2: no SKU, so the next free code of the category", product(small, "walnut-side-desk").sku, "DK-10002");
eq("R-2: a category with no codes starts at 10001", product(small, "plain-sofa-jpg").sku, "SF-10001");
eq("names: extension removed", product(small, "plain-sofa-jpg").name, "Plain Sofa");
eq("names: hyphens and capitals", product(small, "walnut-side-desk").name, "Walnut Side Desk");
ok("the address is never changed by the rules", small.products.every((p) => p.id === p.source.address));
eq("a second spelling of the prefix counts as the same series", readSku(product(small, "low-chair").sku), { prefix: "CR", counter: 2 });
ok("generated codes skip numbers used under either spelling", !small.products.some((p) => p.skuAction !== "kept" && p.skuAction !== "cleaned" && ["CH-10001", "CH-10002", "CH-10003", "CH-10004", "CH-10005", "CH-10010"].includes(p.sku)));
ok("a price below the floor is flagged, not changed", codes(product(small, "low-chair")).includes("price-low") && product(small, "low-chair").price === 4000);
ok("a 58% reduction is flagged and both prices are kept", codes(product(small, "oak-desk")).includes("discount-high") && product(small, "oak-desk").compareAt === 120000);
ok("a 300x300 image is flagged", codes(product(small, "walnut-side-desk")).includes("image-small"));
ok("an image named after another product is flagged", codes(product(small, "tall-chair")).includes("image-other-product"));
ok("the same number written two ways is flagged on both", codes(product(small, "wide-chair")).includes("sku-lookalike") && codes(product(small, "deep-chair")).includes("sku-lookalike"));
ok("a truncated code is flagged and kept", codes(product(small, "odd-chair")).includes("sku-format") && product(small, "odd-chair").sku === "CH-1000");
ok("two products with one name and price are flagged as possible duplicates", codes(product(small, "twin-chair")).includes("name-duplicate") && codes(product(small, "twin-chair")).includes("pairing-assumed"));
eq("same name and price: SKUs follow the order of creation", [product(small, "twin-chair").sku, product(small, "twin-chair-1700000009").sku], ["CH-10003", "CH-10004"]);
ok("an address with a number is flagged", codes(product(small, "twin-chair-1700000009")).includes("address-number"));
ok("an address that does not match the name is flagged", codes(product(small, "plain-sofa-jpg")).includes("address-name"));
eq("a product found only on a collection page is placed by SKU prefix and name", [product(small, "lost-chair").category, codes(product(small, "lost-chair")).includes("category-inferred")], ["chairs", true]);
ok("a collection page is never a product's category", !codes(product(small, "oak-desk")).includes("category-also"));
ok("a person's note is attached", product(small, "oak-desk").flags.some((f) => f.code === "note" && f.text === "A person looked at this."));
ok("every SKU is unique, compared without case", new Set(small.products.map((p) => p.sku.toLowerCase())).size === small.products.length);
ok("every record carries its source untouched", product(small, "oak-desk").source.sku === "SKU: DK-10001" && product(small, "pine-chair").source.price === 9999.99);

console.log("\n[3] the cross-check that guards the capture");
const broken = (mutate) => () => { const s = shop(); mutate(s); buildCatalogue(s); };
throws("a price typed wrong in the listing stops the build", broken((s) => { s.listings[1].text = s.listings[1].text.replace("| 12,000 |", "| 12,500 |"); }), /do not describe the same products/);
throws("a name typed wrong in the quotation stops the build", broken((s) => { s.quotationText = s.quotationText.replace("Tall Chair", "Tall Chiar"); }), /on the quotation page only/);
throws("a product missing from the listings stops the build", broken((s) => { s.listings[2] = listing("sofa", "Sofa", [["x", "x", "0", "", "1700000011_x.jpg"]]); }), /on the quotation page only/);
throws("a product missing from the quotation stops the build", broken((s) => { s.quotationText = s.quotationText.replace("CH-10005 | Lost Chair | 7,000.00\n", ""); }), /in a listing only/);
throws("a listing with fewer rows than the page stated stops the build",
  broken((s) => { s.listings[2].text = s.listings[2].text.replace("2 of 2 Products", "3 of 3 Products"); }), /the page says "3 of 3 Products" and the capture holds 2 rows/);
throws("the same address shown with two prices stops the build", broken((s) => { s.listings[3].text = s.listings[3].text.replace("oak-desk | Oak Desk | 50,000", "oak-desk | Oak Desk | 51,000"); }), /differs between two listings/);
throws("a category with no rule stops the build", broken((s) => { s.listings.push(listing("lamp", "Lamp", [])); }), /has no rule/);
throws("a malformed price stops the build", () => parseQuotation("A-1 | Thing | 12,00\n"), /is not a price/);
throws("a malformed listing row stops the build", () => parseListing("# Source: x   label: X   page says: 1 of 1 Products\nthing | Thing | 5\n", "category-x.txt"), /got 3 fields/);
ok("the error tells the reader not to bend the build", (() => { try { broken((s) => { s.quotationText = s.quotationText.replace("Tall Chair", "Tall Chiar"); })(); } catch (e) { return /do not edit the build/.test(e.message); } return false; })());

console.log("\n[4] the owner's decisions win over the rules");
const decide = (decisions) => buildCatalogue(shop({ decisionRules: { decisions } }));
const priced = decide({ "walnut-side-desk": { price: 61000, decidedBy: "Owner", decidedOn: "2026-01-02" } });
eq("a price supplied by the owner replaces on request", [product(priced, "walnut-side-desk").price, product(priced, "walnut-side-desk").priceOnRequest], [61000, false]);
ok("the price flag is gone and the decision is on record", !codes(product(priced, "walnut-side-desk")).includes("price-missing")
  && product(priced, "walnut-side-desk").changes.some((c) => c.rule === "owner" && /Decided by Owner on 2026-01-02/.test(c.text)));
eq("a product the owner leaves out is listed, not lost", decide({ "odd-chair": { skip: true, reason: "discontinued" } }).skipped.map((s) => s.address).sort(), ["odd-chair", "x"]);
eq("a SKU the owner sets is used", product(decide({ "pine-chair": { sku: "ch-10099" } }), "pine-chair").sku, "CH-10099");
eq("a new address is used and the old one is kept on record", (() => { const r = decide({ "plain-sofa-jpg": { id: "plain-sofa" } }); const p = product(r, "plain-sofa"); return [p?.id, p?.source.address]; })(), ["plain-sofa", "plain-sofa-jpg"]);
throws("a decision that would duplicate a SKU stops the build", () => decide({ "pine-chair": { sku: "CH-10001" } }), /share the SKU/);
throws("a decision for a product that does not exist stops the build", () => decide({ "no-such-thing": { price: 1 } }), /not a product in the capture/);
throws("a decision with a fractional price stops the build", () => decide({ "oak-desk": { price: 10.5 } }), /whole rupees/);
throws("a decision with an unknown category stops the build", () => decide({ "oak-desk": { category: "series" } }), /not a product category/);

console.log("\n[5] the real capture");
const { result, files } = buildAll();
const s = result.summary;
eq("171 products on the live shop", s.onLiveShop, 171);
eq("26 of them have no SKU, as the audit counted on 2026-11-11", s.withoutSkuOnLive, 26);
eq("170 drafts and 1 row left out", [s.imported, s.skipped], [170, 1]);
eq("82 printed labels removed", s.skuLabelRemoved, 82);
eq("17 SKUs shared by 36 products", [s.sharedSkuGroups, s.productsInSharedGroups], [17, 36]);
eq("19 receive a new code, 25 had none", [s.skuReassigned, s.skuGenerated], [19, 25]);
eq("33 on request: the 34 at PKR 0 less the row left out", s.priceOnRequest, 33);
eq("7 prices rounded", s.priceRounded, 7);
ok("every product has a unique address", new Set(result.records.map((p) => p.id)).size === 170);
ok("every product has a unique SKU, compared without case", new Set(result.records.map((p) => p.sku.toLowerCase())).size === 170);
ok("every address is one the API accepts", result.records.every((p) => /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(p.id) && p.id.length >= 2 && p.id.length <= 80));
ok("every price is whole rupees, or on request with no price", result.records.every((p) => (p.priceOnRequest ? p.price === 0 : Number.isInteger(p.price) && p.price > 0)));
ok("every product has exactly one image, served over https from the live shop", result.records.every((p) => p.images.length === 1 && p.images[0].startsWith("https://woodexfurniture.pk/assets/images/products/")));
ok("no two products share an image", new Set(result.records.map((p) => p.images[0])).size === 170);
ok("every product has a category", result.records.every((p) => p.category));
ok("a \"was\" price is always above the price", result.records.every((p) => !p.originalPrice || p.originalPrice > p.price));
ok("no record invents a rating, a review count or a stock number", result.records.every((p) => !("rating" in p) && !("reviews" in p) && !("stockQty" in p)));
ok("no record carries a description", result.records.every((p) => !("description" in p)));
ok("every change a rule made is recorded on the product", result.products.filter((p) => p.name !== p.source.name).every((p) => p.changes.some((c) => c.rule === "name")));
eq("the nine meeting tables that shared a manager-table code all moved to MTG", result.products.filter((p) => p.liveCategory === "meeting-table" && p.sku.startsWith("MTG-")).length, 9);
eq("the manager chair filed under Meeting Table is a manager chair", product(result, "apex-flow-manager-chair").subcategory, "manager-chairs");
eq("the staff chair filed under Series is a staff chair", product(result, "team-staff-chair").subcategory, "staff-chairs");
eq("the PKR 15,000 table marked down from 185,000 is flagged", codes(product(result, "ambassador-executive-table")).includes("discount-high"), true);

console.log("\n[6] the outputs");
const again = buildAll();
ok("building twice gives the same bytes", files.every(([, text], i) => text === again.files[i][1]));
ok("the committed catalogue matches the build", fs.existsSync(DATA_FILE) && fs.readFileSync(DATA_FILE, "utf8") === renderData(result));
ok("the committed report matches the build", fs.existsSync(REPORT_FILE) && fs.readFileSync(REPORT_FILE, "utf8") === renderReport(result));
ok("the committed sheet matches the build", fs.existsSync(CSV_FILE) && fs.readFileSync(CSV_FILE, "utf8") === renderCsv(result));
const data = JSON.parse(renderData(result));
eq("the catalogue file holds what the summary says", [data.products.length, data.skipped.length, data.counts.priceOnRequest], [170, 1, 33]);
ok("the dataset name changes when the content changes", data.dataset !== JSON.parse(renderData(buildCatalogue({ ...readInputs(), decisionRules: { decisions: { "zone-staff-table": { price: 31000 } } } }))).dataset);
const csv = renderCsv(result).trim().split("\n");
eq("the sheet has a header and one row per product", csv.length, 171);
ok("the report states its numbers", /\| \*\*Loaded as drafts\*\* \| \*\*170\*\* \|/.test(renderReport(result)));
ok("the report has a sign-off block", /## 10\. Sign-off/.test(renderReport(result)));
ok("the report contains no credential-shaped text", !/password|passwd|secret/i.test(renderReport(result)));

console.log(`\n${pass} passed, ${fail} failed`);
process.exit(fail ? 1 : 0);

#!/usr/bin/env node
/**
 * Builds the prepared catalogue from the capture of the live shop.
 *
 *   node scripts/catalog-build.mjs            write the three outputs
 *   node scripts/catalog-build.mjs --check    fail if an output is stale
 *
 * Inputs — all in the repository, nothing is fetched:
 *   catalog/source/woodexfurniture.pk/<date>/   what the live shop showed
 *   catalog/rules/categories.json               category map and SKU prefixes
 *   catalog/rules/review-notes.json             observations a person made
 *   catalog/rules/decisions.json                the owner's decisions
 *
 * Outputs:
 *   apps/api/catalog-data.json                        what the import loads
 *   docs/furniture-2030/catalogue/IMPORT-REPORT.md    what the owner signs
 *   docs/furniture-2030/catalogue/import-list.csv     the same list as a sheet
 *
 * Three properties this file exists to guarantee:
 *
 *   1. Nothing is changed silently. Every difference between what the shop
 *      showed and what the import will load is a line in the report, with the
 *      rule that caused it. A value the rules cannot settle is kept, flagged
 *      and left for the owner — never guessed and hidden.
 *
 *   2. The capture is checked before it is used. The quotation page and the
 *      category pages were typed in separately; they must agree on the name
 *      and price of every product, or the build stops. A typing error in
 *      either one therefore cannot reach the catalogue unnoticed.
 *
 *   3. The output is a pure function of the inputs: no clock, no random
 *      value, no network. Run twice, get the same bytes — which is what lets
 *      `--check` prove the committed outputs match the committed inputs.
 */
import fs from "node:fs";
import path from "node:path";
import crypto from "node:crypto";
import { fileURLToPath } from "node:url";

export const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
export const CAPTURE_DIR = path.join(ROOT, "catalog/source/woodexfurniture.pk/2026-11-16");
export const RULES_DIR = path.join(ROOT, "catalog/rules");
export const DATA_FILE = path.join(ROOT, "apps/api/catalog-data.json");
export const REPORT_FILE = path.join(ROOT, "docs/furniture-2030/catalogue/IMPORT-REPORT.md");
export const CSV_FILE = path.join(ROOT, "docs/furniture-2030/catalogue/import-list.csv");

const IMAGE_BASE = "https://woodexfurniture.pk/assets/images/products/";
const SITE = "woodexfurniture.pk";

/* Thresholds for the checks that only flag. Stated here so the report can
   quote them and a reader can disagree with a number rather than with a mood. */
export const LOW_PRICE = 5000;          // a furniture price below this is flagged
export const HIGH_DISCOUNT = 50;        // a "was" price this many percent above is flagged
export const SHARED_PRICE_AT_LEAST = 5; // this many products at one price in a category is flagged

const fail = (message) => { throw new Error(message); };
export const slugify = (value) => String(value).toLowerCase().replace(/[^a-z0-9]+/g, "-").replace(/^-+|-+$/g, "");
const rupees = (n) => "PKR " + Number(n).toLocaleString("en-US");
const plural = (n, one, many = one + "s") => `${n} ${n === 1 ? one : many}`;

function parseMoney(text, where) {
  const t = String(text).trim();
  if (!/^(\d{1,3}(,\d{3})+|\d+)(\.\d{1,2})?$/.test(t)) fail(`${where}: "${text}" is not a price`);
  return Number(t.replace(/,/g, ""));
}

/* ---------- reading the capture ---------- */

export function parseQuotation(text, file = "e-quotation.txt") {
  const rows = [];
  for (const [index, line] of text.split("\n").entries()) {
    if (!line.trim() || line.startsWith("#")) continue;
    const parts = line.split(" | ");
    if (parts.length !== 3) fail(`${file}:${index + 1}: expected "SKU | name | price", got ${parts.length} fields`);
    const [skuRaw, name, price] = parts.map((p) => p.trim());
    if (!name) fail(`${file}:${index + 1}: the product has no name`);
    rows.push({ line: index + 1, skuRaw, name, price: parseMoney(price, `${file}:${index + 1}`) });
  }
  return rows;
}

export function parseListing(text, file) {
  const slug = path.basename(file).replace(/^category-/, "").replace(/\.txt$/, "");
  const header = text.split("\n").find((line) => line.startsWith("# Source:")) ?? "";
  const label = header.match(/label:\s*(.+?)\s{2,}/)?.[1]?.trim() ?? slug;
  const pageSays = header.match(/page says:\s*(.+)$/)?.[1]?.trim() ?? "";
  const rows = [];
  for (const [index, line] of text.split("\n").entries()) {
    if (!line.trim() || line.startsWith("#")) continue;
    const parts = line.split("|").map((p) => p.trim());
    if (parts.length !== 5) fail(`${file}:${index + 1}: expected "address | name | price | compare-at | image", got ${parts.length} fields`);
    const [address, name, price, compareAt, imageFile] = parts;
    if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(address)) fail(`${file}:${index + 1}: "${address}" is not an address`);
    if (!/^[\w%().-]+\.(jpg|jpeg|png|webp)$/i.test(imageFile)) fail(`${file}:${index + 1}: "${imageFile}" is not an image file name`);
    rows.push({
      row: rows.length, address, name,
      price: parseMoney(price, `${file}:${index + 1}`),
      compareAt: compareAt ? parseMoney(compareAt, `${file}:${index + 1}`) : null,
      imageFile,
    });
  }
  /* "N of N Products" is the page's own statement that the listing is
     complete. A capture that holds a different number of rows is a capture
     with a row missing or doubled. */
  const stated = pageSays.match(/^(\d+) of (\d+) Products/);
  if (stated && (Number(stated[1]) !== Number(stated[2]) || Number(stated[1]) !== rows.length))
    fail(`${file}: the page says "${pageSays}" and the capture holds ${rows.length} rows`);
  return { slug, label, pageSays, rows };
}

export function readInputs({ captureDir = CAPTURE_DIR, rulesDir = RULES_DIR } = {}) {
  const read = (file) => fs.readFileSync(file, "utf8");
  const listingFiles = fs.readdirSync(captureDir).filter((f) => /^category-.+\.txt$/.test(f)).sort();
  return {
    capturedOn: path.basename(captureDir),
    quotationText: read(path.join(captureDir, "e-quotation.txt")),
    listings: listingFiles.map((file) => ({ file, text: read(path.join(captureDir, file)) })),
    categoryRules: JSON.parse(read(path.join(rulesDir, "categories.json"))),
    noteRules: JSON.parse(read(path.join(rulesDir, "review-notes.json"))),
    decisionRules: JSON.parse(read(path.join(rulesDir, "decisions.json"))),
  };
}

/* ---------- the rules ---------- */

/** R-1: the live SKU field often holds the label "SKU:" as well as the code. */
export function cleanSku(raw) {
  const original = String(raw ?? "").trim();
  if (!original || original === "—" || original === "-") return { sku: null, hadLabel: false };
  const stripped = original.replace(/^(\s*SKU\s*:\s*)+/i, "").replace(/\s+/g, "").toUpperCase();
  return { sku: stripped || null, hadLabel: stripped !== original.replace(/\s+/g, "").toUpperCase() };
}

/** Splits "MC-100010" into its prefix and its place in the series.
    The shop numbered by writing the counter after "1000": 10001 … 10009, then
    100010, 100011. Both spellings are read; anything else has no place. */
export function readSku(sku) {
  const m = String(sku ?? "").match(/^([A-Z]{2,4})-(\d+)$/);
  if (!m) return { prefix: null, counter: null };
  const digits = m[2];
  let counter = null;
  if (digits.length === 5 && digits.startsWith("100") && Number(digits) > 10000) counter = Number(digits) - 10000;
  else if (digits.length === 5 && Number(digits) > 10000 && Number(digits) < 11000) counter = Number(digits) - 10000;
  else if (digits.length === 6 && digits.startsWith("1000")) counter = Number(digits.slice(4));
  return { prefix: m[1], counter: counter && counter > 0 ? counter : null };
}
const makeSku = (prefix, counter) => `${prefix}-${10000 + counter}`;

/** Mechanical repairs to a name. Spelling is never touched. */
export function cleanName(raw) {
  const changes = [];
  let name = String(raw).trim();
  const withoutExtension = name.replace(/\.(jpe?g|png|webp)$/i, "");
  if (withoutExtension !== name) { changes.push("an image file extension was removed"); name = withoutExtension; }
  if (!/\s/.test(name) && name.includes("-")) { changes.push("hyphens between the words were replaced by spaces"); name = name.replace(/-+/g, " "); }
  const collapsed = name.replace(/\s+/g, " ").trim();
  if (collapsed !== name) { changes.push("extra spaces were removed"); name = collapsed; }
  const capitalised = name.split(" ").map((word) => (/^[a-z]/.test(word) ? word[0].toUpperCase() + word.slice(1) : word)).join(" ");
  if (capitalised !== name) { changes.push("each word now starts with a capital letter"); name = capitalised; }
  return { name, changes };
}

const isJunkName = (name) => String(name).replace(/[^a-z]/gi, "").length < 3;

/** The image file's name with the upload stamp and extension removed, as a slug. */
function imageSubject(imageFile) {
  let base = imageFile;
  try { base = decodeURIComponent(imageFile); } catch { /* keep as typed */ }
  base = base.replace(/\.(jpe?g|png|webp)$/i, "");
  base = base.replace(/^\d{10}_(\d_)?/, "").replace(/^[0-9a-f]{13,14}(\.\d+)?_?/, "");
  return slugify(base);
}

/* ---------- the build ---------- */

export function buildCatalogue(inputs) {
  const { categoryRules, noteRules, decisionRules, capturedOn } = inputs;
  const categories = categoryRules.categories;
  const specificity = categoryRules.specificity;
  const rank = (slug) => { const i = specificity.indexOf(slug); return i < 0 ? specificity.length : i; };

  const quotation = parseQuotation(inputs.quotationText);
  const listings = inputs.listings.map(({ file, text }) => parseListing(text, file)).sort((a, b) => rank(a.slug) - rank(b.slug));
  for (const listing of listings) if (!categories[listing.slug]) fail(`category "${listing.slug}" has no rule in catalog/rules/categories.json`);

  /* One record per address. A product listed under two categories must look
     the same in both, or one of the two captures is wrong. */
  const byAddress = new Map();
  for (const listing of listings) {
    for (const row of listing.rows) {
      const seen = byAddress.get(row.address);
      if (!seen) { byAddress.set(row.address, { ...row, liveCategories: [listing.slug], rowIn: { [listing.slug]: row.row }, order: byAddress.size }); continue; }
      for (const key of ["name", "price", "compareAt", "imageFile"])
        if (seen[key] !== row[key]) fail(`"${row.address}" differs between two listings: ${key} is "${seen[key]}" in ${seen.liveCategories[0]} and "${row[key]}" in ${listing.slug}`);
      seen.liveCategories.push(listing.slug);
      seen.rowIn[listing.slug] = row.row;
    }
  }

  /* The cross-check. Pair every quotation row with a listing row of the same
     name and the same price (the listing shows it rounded). Whatever is left
     over on either side stops the build. */
  const pool = [...byAddress.values()];
  const used = new Set();
  const unpairedQuotation = [];
  const paired = [];
  for (const q of quotation) {
    const partner = pool.find((p) => !used.has(p.address) && p.name === q.name && p.price === Math.round(q.price));
    if (!partner) { unpairedQuotation.push(q); continue; }
    used.add(partner.address);
    paired.push({ q, listing: partner });
  }
  const unpairedListing = pool.filter((p) => !used.has(p.address));
  if (unpairedQuotation.length || unpairedListing.length) {
    fail([
      "The quotation page and the category pages do not describe the same products.",
      ...unpairedQuotation.map((q) => `  on the quotation page only (line ${q.line}): ${q.name} · ${q.price}`),
      ...unpairedListing.map((p) => `  in a listing only: ${p.address} · ${p.name} · ${p.price}`),
      "Correct the capture, or capture the missing listing; do not edit the build to make it pass.",
    ].join("\n"));
  }
  const sameNameAndPrice = new Map();
  for (const { q } of paired) { const key = q.name + "|" + Math.round(q.price); sameNameAndPrice.set(key, (sameNameAndPrice.get(key) ?? 0) + 1); }

  /* Which categories own which SKU prefix. */
  const ownersOf = new Map();
  for (const [slug, rule] of Object.entries(categories)) {
    if (rule.notAProductCategory) continue;
    for (const prefix of [rule.skuPrefix, ...(rule.alsoPrefix ?? [])]) ownersOf.set(prefix, [...(ownersOf.get(prefix) ?? []), slug]);
  }
  const keywordCategory = (name) => {
    const text = " " + String(name).toLowerCase().replace(/[^a-z0-9]+/g, " ").trim() + " ";
    let best = null;
    for (const slug of specificity) {
      for (const keyword of categories[slug]?.nameKeywords ?? []) {
        const at = text.indexOf(" " + keyword + " ");
        const loose = at < 0 ? text.indexOf(keyword) : at;
        if (loose >= 0 && (!best || keyword.length > best.keyword.length)) best = { slug, keyword };
      }
    }
    return best?.slug ?? null;
  };

  const products = [];
  const skipped = [];

  for (const { q, listing } of paired) {
    const flags = [];
    const changes = [];
    const flag = (code, text) => flags.push({ code, text });
    const change = (rule, text) => changes.push({ rule, text });

    /* R-6: a row that is not a product is listed and left out, never dropped quietly. */
    if (isJunkName(q.name)) {
      skipped.push({ address: listing.address, name: q.name, sku: q.skuRaw, price: q.price, liveCategories: listing.liveCategories,
        reason: `The name "${q.name}" is not a product name; the row has ${cleanSku(q.skuRaw).sku ? "a SKU" : "no SKU"} and ${q.price ? "a price" : "no price"}.` });
      continue;
    }

    /* name */
    const named = cleanName(q.name);
    if (named.changes.length) change("name", `Name "${q.name}" becomes "${named.name}": ${named.changes.join("; ")}.`);

    /* SKU, first pass: R-1 */
    const cleaned = cleanSku(q.skuRaw);
    if (cleaned.hadLabel) change("R-1", `SKU "${q.skuRaw}" becomes "${cleaned.sku}": the printed "SKU:" label was removed.`);
    const read = readSku(cleaned.sku);

    /* category */
    const candidates = listing.liveCategories.filter((slug) => !categories[slug].notAProductCategory);
    const owners = read.prefix ? ownersOf.get(read.prefix) ?? [] : [];
    const byName = keywordCategory(named.name);
    let live = null;
    const ownedHere = candidates.filter((slug) => owners.includes(slug));
    if (ownedHere.length) live = ownedHere.sort((a, b) => rank(a) - rank(b))[0];
    else if (owners.length && byName && owners.includes(byName)) {
      /* The SKU prefix and the name agree with each other and disagree with
         where the shop filed the product. Two of three: follow them. */
      live = byName;
      const filedUnder = listing.liveCategories.map((slug) => categories[slug].label).join(", ");
      flag(candidates.length ? "category-moved" : "category-inferred",
        `Filed under ${filedUnder} on the live shop. Its name and its SKU prefix ${read.prefix} both say ${categories[live].label}, so it is placed there.`);
    } else if (candidates.length) live = [...candidates].sort((a, b) => rank(a) - rank(b))[0];
    if (!live) fail(`"${listing.address}" (${q.name}) cannot be placed in a category: listed under ${listing.liveCategories.join(", ")}`);
    const rule = categories[live];
    const others = candidates.filter((slug) => slug !== live);
    if (others.length && listing.liveCategories.includes(live))
      flag("category-also", `Also listed under ${others.map((slug) => categories[slug].label).join(", ")} on the live shop. The new shop files a product in one place: ${rule.label}.`);
    if (byName && categories[byName].family !== rule.family && !flags.some((f) => f.code === "category-moved" || f.code === "category-inferred"))
      flag("category-check", `Named like a product of ${categories[byName].label} and filed under ${rule.label}. Check which is right.`);

    /* price: R-5 then R-3 */
    let price = q.price;
    if (!Number.isInteger(price)) {
      price = Math.round(price);
      change("R-5", `Price ${rupees(q.price.toFixed(2))} becomes ${rupees(price)}: rounded to the whole rupee, which is what the shop already displays.`);
    }
    const priceOnRequest = price === 0;
    if (priceOnRequest) flag("price-missing", "The live price is PKR 0. Imported as \"price on request\": customers can ask for a quotation and cannot order it until a price is entered.");
    let compareAt = listing.compareAt;
    if (compareAt !== null && (priceOnRequest || compareAt <= price)) {
      change("price", `The "was" price ${rupees(compareAt)} is dropped: it is not above the selling price.`);
      compareAt = null;
    }
    if (!priceOnRequest && price < LOW_PRICE) flag("price-low", `${rupees(price)} is below ${rupees(LOW_PRICE)}, which is unusual for furniture. A digit may be missing.`);
    if (compareAt !== null) {
      const discount = Math.round((1 - price / compareAt) * 100);
      if (discount >= HIGH_DISCOUNT) flag("discount-high", `The live shop shows ${rupees(price)}, down from ${rupees(compareAt)}: a ${discount}% reduction. One of the two prices is probably wrong.`);
    }

    /* image */
    if (/-300x300\./i.test(listing.imageFile)) flag("image-small", "The image file name says 300 × 300 pixels: a thumbnail, too small for a product page.");

    /* things only the pairing can tell */
    if (sameNameAndPrice.get(q.name + "|" + Math.round(q.price)) > 1)
      flag("pairing-assumed", "Another product has the same name and price. The two SKUs were matched to the two addresses in the order they were created; check that each product carries its own SKU.");

    products.push({
      id: listing.address,
      name: named.name,
      sku: cleaned.sku,
      skuAction: cleaned.sku ? (cleaned.hadLabel ? "cleaned" : "kept") : "missing",
      price, priceOnRequest, compareAt,
      liveCategory: live, category: rule.category, subcategory: rule.subcategory,
      imageFile: listing.imageFile,
      flags, changes,
      source: { address: listing.address, name: q.name, sku: q.skuRaw, price: q.price, compareAt: listing.compareAt, categories: listing.liveCategories },
      _order: rank(live) * 1000 + (listing.rowIn[live] ?? listing.order),
      _prefixOwners: owners,
    });
  }

  /* R-4: a SKU carried by several products. The product whose category owns
     the prefix keeps it; among several, the one created first. */
  const bySku = new Map();
  for (const p of products) if (p.sku) bySku.set(p.sku, [...(bySku.get(p.sku) ?? []), p]);
  const sharedGroups = [];
  for (const [sku, members] of bySku) {
    if (members.length < 2) continue;
    const owning = members.filter((p) => p._prefixOwners.includes(p.liveCategory));
    const field = (owning.length ? owning : members).sort((a, b) => a._order - b._order);
    const keeper = field[0];
    const reason = owning.length === 1
      ? `its category, ${categories[keeper.liveCategory].label}, is the one that uses the prefix ${readSku(sku).prefix}`
      : owning.length > 1 ? "it was created first among the products of the category that uses this prefix" : "it was created first; no member's category uses this prefix";
    sharedGroups.push({ sku, keeper: keeper.id, reason, members: members.map((p) => p.id) });
    for (const p of members) {
      if (p === keeper) { p.flags.push({ code: "sku-shared-kept", text: `${plural(members.length - 1, "other product")} carried the SKU ${sku} on the live shop. This one keeps it because ${reason}.` }); continue; }
      p._sharedWith = { sku, keeper: keeper.name };
      p.sku = null;
      p.skuAction = "reassigned";
    }
  }

  /* R-2 and the second half of R-4: a code for every product without one. */
  const taken = new Set(products.filter((p) => p.sku).map((p) => p.sku));
  const countersUsed = (prefixes) => new Set([...taken].map(readSku).filter((r) => prefixes.includes(r.prefix) && r.counter).map((r) => r.counter));
  for (const p of [...products].filter((x) => !x.sku).sort((a, b) => a._order - b._order)) {
    const rule = categories[p.liveCategory];
    const family = [rule.skuPrefix, ...(rule.alsoPrefix ?? [])];
    const usedCounters = countersUsed(family);
    let counter = 1;
    while (usedCounters.has(counter) || taken.has(makeSku(rule.skuPrefix, counter))) counter++;
    p.sku = makeSku(rule.skuPrefix, counter);
    taken.add(p.sku);
    if (p.skuAction === "reassigned") {
      p.changes.push({ rule: "R-4", text: `SKU ${p._sharedWith.sku} is also carried by ${p._sharedWith.keeper}, which keeps it. This product is given ${p.sku}, the next free code of ${rule.label}.` });
      p.flags.push({ code: "sku-reassigned", text: `Given the SKU ${p.sku} because ${p._sharedWith.sku} was shared with ${p._sharedWith.keeper}. Confirm the new code or enter another.` });
    } else {
      p.skuAction = "generated";
      p.changes.push({ rule: "R-2", text: `No SKU on the live shop. Given ${p.sku}, the next free code of ${rule.label}.` });
      p.flags.push({ code: "sku-generated", text: `Had no SKU on the live shop and is given ${p.sku}. Confirm the code or enter another.` });
    }
  }

  /* Flags that need the whole list. */
  for (const p of products) {
    const read = readSku(p.sku);
    if (p.skuAction === "kept" || p.skuAction === "cleaned") {
      if (!read.prefix || !read.counter) p.flags.push({ code: "sku-format", text: `The SKU ${p.sku} does not follow the pattern of the others (letters, a dash, and a number from 10001). It may be incomplete.` });
      else if (!(ownersOf.get(read.prefix) ?? []).includes(p.liveCategory)) {
        const usual = ownersOf.get(read.prefix);
        p.flags.push({ code: "sku-prefix", text: usual
          ? `The SKU ${p.sku} carries the prefix of ${usual.map((slug) => categories[slug].label).join(" / ")}; this product is filed under ${categories[p.liveCategory].label}. The code is unique, so it is kept.`
          : `The SKU prefix ${read.prefix} is used by no other product. The code is unique, so it is kept.` });
      }
    }
  }
  /* "MT-10010" and "MT-100010" are different strings and the same number: the
     shop wrote the tenth code both ways. The database will accept both; a
     person reading a delivery note will not tell them apart. */
  const byPlace = new Map();
  for (const p of products) { const r = readSku(p.sku); if (r.prefix && r.counter) byPlace.set(r.prefix + "|" + r.counter, [...(byPlace.get(r.prefix + "|" + r.counter) ?? []), p]); }
  for (const members of byPlace.values()) {
    if (members.length < 2) continue;
    for (const p of members) {
      const twin = members.find((x) => x !== p);
      p.flags.push({ code: "sku-lookalike", text: `The SKU ${p.sku} and the SKU ${twin.sku} of ${twin.name} are the same number written two ways. One of them should change.` });
    }
  }
  const byName = new Map();
  for (const p of products) byName.set(p.name.toLowerCase(), [...(byName.get(p.name.toLowerCase()) ?? []), p]);
  for (const members of byName.values()) {
    if (members.length < 2) continue;
    const samePrice = new Set(members.map((p) => p.price)).size === 1;
    for (const p of members) p.flags.push({ code: "name-duplicate", text: samePrice
      ? `${plural(members.length - 1, "other product")} has the same name and the same price. It may be one product entered twice.`
      : `${plural(members.length - 1, "other product")} has the same name at a different price. Two different products need two different names.` });
  }
  const byCategoryPrice = new Map();
  for (const p of products) if (!p.priceOnRequest) { const key = p.liveCategory + "|" + p.price; byCategoryPrice.set(key, [...(byCategoryPrice.get(key) ?? []), p]); }
  for (const members of byCategoryPrice.values()) {
    if (members.length < SHARED_PRICE_AT_LEAST) continue;
    for (const p of members) p.flags.push({ code: "price-shared", text: `${members.length} products of ${categories[p.liveCategory].label} are priced at exactly ${rupees(p.price)}. It may be a default that was never replaced.` });
  }
  const subjectOwner = new Map(products.map((p) => [slugify(p.name), p]));
  for (const p of products) {
    const subject = imageSubject(p.imageFile);
    const other = subjectOwner.get(subject);
    if (other && other !== p && subject !== slugify(p.name))
      p.flags.push({ code: "image-other-product", text: `The image file is named after another product, ${other.name}. Check that the picture shows this product.` });
    const address = p.id.replace(/-\d{10}$/, "");
    if (address !== p.id) p.flags.push({ code: "address-number", text: `The address ends in a number (${p.id.slice(address.length + 1)}) because another product took the plain address first.` });
    if (address !== slugify(p.name)) p.flags.push({ code: "address-name", text: `The address "${p.id}" does not match the name "${p.name}".` });
    const note = noteRules.notes?.[p.id];
    if (note) p.flags.push({ code: "note", text: note });
  }

  /* The owner's decisions, applied last so they win over every rule. */
  const decided = [];
  for (const [address, decision] of Object.entries(decisionRules.decisions ?? {})) {
    const p = products.find((x) => x.source.address === address);
    if (!p) fail(`catalog/rules/decisions.json names "${address}", which is not a product in the capture`);
    const by = `${decision.decidedBy ?? "the owner"}${decision.decidedOn ? " on " + decision.decidedOn : ""}`;
    const say = (text) => { p.changes.push({ rule: "owner", text: `${text} Decided by ${by}.` }); decided.push({ address, text }); };
    if (decision.skip) {
      skipped.push({ address, name: p.name, sku: p.source.sku, price: p.source.price, liveCategories: p.source.categories, reason: `Left out by decision of ${by}: ${decision.reason ?? "no reason recorded"}.` });
      products.splice(products.indexOf(p), 1);
      decided.push({ address, text: "Not imported." });
      continue;
    }
    if (decision.name) { say(`Name set to "${decision.name}".`); p.name = String(decision.name); }
    if (decision.sku) { say(`SKU set to ${decision.sku}.`); p.sku = String(decision.sku).toUpperCase(); p.skuAction = "decided"; p.flags = p.flags.filter((f) => !f.code.startsWith("sku-")); }
    if (decision.price !== undefined) {
      if (decision.price === "on-request") { say("Price set to \"on request\"."); p.price = 0; p.priceOnRequest = true; }
      else {
        if (!Number.isInteger(decision.price) || decision.price <= 0) fail(`decisions.json: the price of "${address}" must be whole rupees above zero, or "on-request"`);
        say(`Price set to ${rupees(decision.price)}.`); p.price = decision.price; p.priceOnRequest = false;
      }
      p.flags = p.flags.filter((f) => !f.code.startsWith("price-") && f.code !== "discount-high");
    }
    if (decision.compareAt !== undefined) { say(decision.compareAt === null ? "The \"was\" price is dropped." : `The "was" price is set to ${rupees(decision.compareAt)}.`); p.compareAt = decision.compareAt; p.flags = p.flags.filter((f) => f.code !== "discount-high"); }
    if (decision.category) {
      const chosen = categories[decision.category];
      if (!chosen || chosen.notAProductCategory) fail(`decisions.json: "${decision.category}" is not a product category`);
      say(`Category set to ${chosen.label}.`); p.liveCategory = decision.category; p.category = chosen.category; p.subcategory = chosen.subcategory;
      p.flags = p.flags.filter((f) => !f.code.startsWith("category-"));
    }
    if (decision.id) {
      if (!/^[a-z0-9]+(-[a-z0-9]+)*$/.test(decision.id)) fail(`decisions.json: "${decision.id}" is not a valid address`);
      say(`Address set to "${decision.id}"; the live address "${address}" will need a redirect.`); p.id = decision.id;
      p.flags = p.flags.filter((f) => !f.code.startsWith("address-"));
    }
  }

  /* What must hold for the import to be loadable at all. */
  const ids = new Set();
  const skus = new Set();
  for (const p of products) {
    if (ids.has(p.id)) fail(`two products would share the address "${p.id}"`);
    ids.add(p.id);
    if (!p.sku) fail(`"${p.id}" has no SKU after the rules ran`);
    if (skus.has(p.sku.toLowerCase())) fail(`two products would share the SKU ${p.sku}`);
    skus.add(p.sku.toLowerCase());
    if (!p.priceOnRequest && !(Number.isInteger(p.price) && p.price > 0)) fail(`"${p.id}" has neither a whole-rupee price nor "price on request"`);
    if (p.priceOnRequest && p.price !== 0) fail(`"${p.id}" is on request and still carries a price`);
  }

  products.sort((a, b) => a._order - b._order || a.id.localeCompare(b.id));
  const records = products.map((p) => ({
    id: p.id,
    name: p.name,
    sku: p.sku,
    price: p.price,
    priceOnRequest: p.priceOnRequest,
    ...(p.compareAt ? { originalPrice: p.compareAt } : {}),
    category: p.category,
    ...(p.subcategory ? { subcategory: p.subcategory } : {}),
    images: [IMAGE_BASE + p.imageFile],
    review: p.flags,
    source: { site: SITE, capturedOn, ...p.source },
  }));

  const count = (code) => products.filter((p) => p.flags.some((f) => f.code === code)).length;
  const summary = {
    onLiveShop: quotation.length,
    imported: products.length,
    skipped: skipped.length,
    withoutSkuOnLive: quotation.filter((q) => !cleanSku(q.skuRaw).sku).length,
    skuLabelRemoved: products.filter((p) => p.changes.some((c) => c.rule === "R-1")).length,
    skuGenerated: products.filter((p) => p.skuAction === "generated").length,
    skuReassigned: products.filter((p) => p.skuAction === "reassigned").length,
    sharedSkuGroups: sharedGroups.length,
    productsInSharedGroups: sharedGroups.reduce((n, g) => n + g.members.length, 0),
    priceOnRequest: products.filter((p) => p.priceOnRequest).length,
    priceRounded: products.filter((p) => p.changes.some((c) => c.rule === "R-5")).length,
    withComparePrice: products.filter((p) => p.compareAt).length,
    namesChanged: products.filter((p) => p.changes.some((c) => c.rule === "name")).length,
    needingReview: products.filter((p) => p.flags.length).length,
    clean: products.filter((p) => !p.flags.length).length,
    flags: Object.fromEntries([...new Set(products.flatMap((p) => p.flags.map((f) => f.code)))].sort().map((code) => [code, count(code)])),
  };

  return { capturedOn, products, records, skipped, sharedGroups, decided, summary, categories, listings };
}

/* ---------- outputs ---------- */

export function renderData(result) {
  const body = result.records;
  const digest = crypto.createHash("sha256").update(JSON.stringify(body)).digest("hex").slice(0, 16);
  const head = {
    version: 1,
    dataset: `${SITE}-${result.capturedOn}-${digest}`,
    source: { site: SITE, capturedOn: result.capturedOn, builtBy: "scripts/catalog-build.mjs" },
    counts: { products: body.length, skipped: result.skipped.length, priceOnRequest: result.summary.priceOnRequest, needingReview: result.summary.needingReview },
    skipped: result.skipped.map(({ address, name, reason }) => ({ address, name, reason })),
  };
  return "{\n"
    + Object.entries(head).map(([key, value]) => `  ${JSON.stringify(key)}: ${JSON.stringify(value)},`).join("\n")
    + "\n  \"products\": [\n" + body.map((p) => "    " + JSON.stringify(p)).join(",\n") + "\n  ]\n}\n";
}

const csvCell = (value) => { const s = value === null || value === undefined ? "" : String(value); return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s; };
export function renderCsv(result) {
  const head = ["address", "name", "name on live shop", "SKU", "SKU on live shop", "SKU action", "price (PKR)", "price on live shop", "price on request", "was price (PKR)",
    "category", "sub-category", "listed under on live shop", "image", "to check", "owner: approve (yes/no)", "owner: correction"];
  const rows = result.products.map((p) => [
    p.id, p.name, p.source.name, p.sku, p.source.sku === "—" ? "" : p.source.sku, p.skuAction,
    p.priceOnRequest ? "" : p.price, p.source.price, p.priceOnRequest ? "yes" : "no", p.compareAt ?? "",
    p.category, p.subcategory ?? "", p.source.categories.map((slug) => result.categories[slug].label).join("; "),
    IMAGE_BASE + p.imageFile, p.flags.map((f) => f.text).join(" | "), "", "",
  ]);
  return [head, ...rows].map((row) => row.map(csvCell).join(",")).join("\n") + "\n";
}

export function renderReport(result) {
  const { summary: s, products, skipped, sharedGroups, categories } = result;
  const md = (text) => String(text).replace(/\|/g, "\\|");
  const withFlag = (code) => products.filter((p) => p.flags.some((f) => f.code === code));
  const flagText = (p, code) => p.flags.find((f) => f.code === code)?.text ?? "";
  const priceCell = (p) => (p.priceOnRequest ? "on request" : rupees(p.price));
  const labelOf = (p) => categories[p.liveCategory].label;
  const out = [];
  const line = (text = "") => out.push(text);
  const table = (head, rows) => {
    line("| " + head.join(" | ") + " |");
    line("|" + head.map(() => "---").join("|") + "|");
    for (const row of rows) line("| " + row.map(md).join(" | ") + " |");
    line();
  };

  line("# Catalogue import report");
  line();
  line(`Generated by \`scripts/catalog-build.mjs\` from the capture of ${SITE} made on ${result.capturedOn}. Do not edit this file by hand: change the capture, the rules in \`catalog/rules/\`, or the owner's decisions in \`catalog/rules/decisions.json\`, and build again.`);
  line();
  line("**What this is.** The list of products the import will load, every change the rules made to what the live shop shows, and every point the rules could not settle. The owner reads it, corrects it, and signs it. Nothing in it is on sale until the owner publishes it.");
  line();

  line("## 1. Summary");
  line();
  table(["", "Count"], [
    ["Products on the live shop", s.onLiveShop],
    ["Left out, listed in §8", s.skipped],
    ["**Loaded as drafts**", `**${s.imported}**`],
    ["of which need nothing checked", s.clean],
    ["of which have at least one point to check", s.needingReview],
  ]);
  line("Every product is loaded as a **draft**. A draft is visible in the dashboard and invisible to customers. It becomes visible when a member of staff publishes it, one product at a time or many together.");
  line();

  line("## 2. What the rules did");
  line();
  table(["Rule", "What it does", "Products"], [
    ["R-1", "Removes the printed label \"SKU:\" from the SKU", s.skuLabelRemoved],
    ["R-2", "Gives a SKU to a product that has none, using the next free code of its category", s.skuGenerated],
    ["R-3", "Turns a price of PKR 0 into \"price on request\"", s.priceOnRequest],
    ["R-4", `Where several products carry one SKU, one keeps it and the others get the next free code (${s.sharedSkuGroups} SKUs carried by ${s.productsInSharedGroups} products)`, s.skuReassigned],
    ["R-5", "Rounds a price that is not in whole rupees to the whole rupee", s.priceRounded],
    ["R-6", "Leaves out a row that is not a product, and lists it", s.skipped],
    ["R-7", "Loads every product as a draft", s.imported],
    ["Names", "Removes a file extension, replaces hyphens used as spaces, and starts each word with a capital letter. Spelling is not changed.", s.namesChanged],
  ]);
  line("**How a generated SKU is formed.** The category's letters, a dash, and the lowest unused number from 10001 upward in that category: `MC-10015`, not `MC-100015`. The live shop wrote the counter after the digits \"1000\", which gave five digits up to 10009 and six after that. Existing codes are kept exactly as they are; only new ones use the plain form.");
  line();
  const invented = Object.values(categories).filter((c) => c.new && products.some((p) => p.sku.startsWith(c.skuPrefix + "-")));
  if (invented.length) {
    line("**Prefixes that are new.** These letters were not used on the live shop. They are proposals, and the owner may replace them:");
    line();
    table(["Category", "Proposed prefix", "Why a new one"], invented.map((c) => [c.label, c.skuPrefix, c.why]));
  }

  line("## 3. Prices");
  line();
  line(`### 3.1 Price on request (${s.priceOnRequest})`);
  line();
  line("The live shop shows PKR 0 for these and lets a customer put them in the cart at that price. In the new shop a customer can ask for a quotation for them and cannot order them. **Needed from the owner: a price for each, or confirmation that it stays on request.**");
  line();
  const onRequestBy = new Map();
  for (const p of products.filter((x) => x.priceOnRequest)) onRequestBy.set(labelOf(p), [...(onRequestBy.get(labelOf(p)) ?? []), p]);
  table(["Category", "Count", "Products"], [...onRequestBy.entries()].map(([label, members]) => [label, members.length, members.map((p) => `${p.name} (${p.sku})`).join(", ")]));

  const rounded = products.filter((p) => p.changes.some((c) => c.rule === "R-5"));
  line(`### 3.2 Rounded to the whole rupee (${rounded.length})`);
  line();
  if (rounded.length) table(["Product", "Stored on the live shop", "Loaded as"], rounded.map((p) => [p.name, rupees(p.source.price.toFixed(2)), rupees(p.price)]));
  else { line("None."); line(); }

  const priceChecks = products.filter((p) => p.flags.some((f) => ["price-low", "discount-high"].includes(f.code)));
  line(`### 3.3 Prices that look wrong (${priceChecks.length})`);
  line();
  line(`A price below ${rupees(LOW_PRICE)}, or a reduction of ${HIGH_DISCOUNT}% or more. These are loaded as the shop shows them and must be confirmed before the product is published.`);
  line();
  if (priceChecks.length) table(["Product", "Price", "Was", "Why it is listed"], priceChecks.map((p) => [p.name, priceCell(p), p.compareAt ? rupees(p.compareAt) : "", p.flags.filter((f) => ["price-low", "discount-high"].includes(f.code)).map((f) => f.text).join(" ")]));

  const sharedPrice = new Map();
  for (const p of withFlag("price-shared")) { const key = labelOf(p) + "|" + p.price; sharedPrice.set(key, [...(sharedPrice.get(key) ?? []), p]); }
  line(`### 3.4 One price used many times (${withFlag("price-shared").length})`);
  line();
  line(`${SHARED_PRICE_AT_LEAST} or more products of one category at exactly the same price. It may be right; it may be a default that was never replaced.`);
  line();
  if (sharedPrice.size) table(["Category", "Price", "Count", "Products"], [...sharedPrice.values()].map((members) => [labelOf(members[0]), rupees(members[0].price), members.length, members.map((p) => p.name).join(", ")]));
  else { line("None."); line(); }

  const compare = products.filter((p) => p.compareAt);
  line(`### 3.5 "Was" prices carried over (${compare.length})`);
  line();
  if (compare.length) table(["Product", "Price", "Was", "Reduction"], compare.map((p) => [p.name, rupees(p.price), rupees(p.compareAt), Math.round((1 - p.price / p.compareAt) * 100) + "%"]));

  line("## 4. SKUs");
  line();
  line(`### 4.1 One SKU on several products (${sharedGroups.length} SKUs)`);
  line();
  line("The product whose category uses the prefix keeps the SKU; among several of the same category, the one created first. The others receive the next free code of their own category. **Needed from the owner: confirm each line, or say which product should keep the SKU.**");
  line();
  table(["SKU", "Keeps it", "Why", "Receives a new code"], sharedGroups.map((g) => {
    const keeper = products.find((p) => p.id === g.keeper);
    const rest = g.members.filter((id) => id !== g.keeper).map((id) => products.find((p) => p.id === id)).filter(Boolean);
    return [g.sku, `${keeper.name} (${labelOf(keeper)})`, g.reason, rest.map((p) => `${p.name} (${labelOf(p)}) → ${p.sku}`).join("; ")];
  }));
  const generated = products.filter((p) => p.skuAction === "generated");
  line(`### 4.2 Products that had no SKU (${generated.length})`);
  line();
  table(["Product", "Category", "Proposed SKU"], generated.map((p) => [p.name, labelOf(p), p.sku]));
  const remarkCodes = ["sku-format", "sku-prefix", "sku-lookalike"];
  const odd = products.filter((p) => p.flags.some((f) => remarkCodes.includes(f.code)));
  line(`### 4.3 SKUs kept as they are, with a remark (${odd.length})`);
  line();
  if (odd.length) table(["Product", "SKU", "Remark"], odd.map((p) => [p.name, p.sku, p.flags.filter((f) => remarkCodes.includes(f.code)).map((f) => f.text).join(" ")]));
  else { line("None."); line(); }

  line("## 5. Names and addresses");
  line();
  const renamed = products.filter((p) => p.changes.some((c) => c.rule === "name"));
  line(`### 5.1 Names repaired (${renamed.length})`);
  line();
  if (renamed.length) table(["On the live shop", "Loaded as"], renamed.map((p) => [p.source.name, p.name]));
  const duplicates = withFlag("name-duplicate");
  line(`### 5.2 The same name on more than one product (${duplicates.length})`);
  line();
  if (duplicates.length) table(["Name", "Address", "SKU", "Price", "Category", "Remark"], duplicates.map((p) => [p.name, p.id, p.sku, priceCell(p), labelOf(p), flagText(p, "name-duplicate")]));
  else { line("None."); line(); }
  const addresses = products.filter((p) => p.flags.some((f) => f.code.startsWith("address-")));
  line(`### 5.3 Addresses that do not match the name (${addresses.length})`);
  line();
  line("Each product keeps the address it has on the live shop, so that links people have saved keep working. Where the address is wrong for the product, the owner can ask for a new one before the import; the old address then needs a redirect.");
  line();
  if (addresses.length) table(["Product", "Address", "Remark"], addresses.map((p) => [p.name, "`" + p.id + "`", p.flags.filter((f) => f.code.startsWith("address-")).map((f) => f.text).join(" ")]));

  line("## 6. Categories");
  line();
  const byCategory = new Map();
  for (const p of products) byCategory.set(p.liveCategory, (byCategory.get(p.liveCategory) ?? 0) + 1);
  table(["Category on the live shop", "Filed in the new shop as", "Products"], [...byCategory.entries()].map(([slug, n]) => [categories[slug].label, categories[slug].category + (categories[slug].subcategory ? " › " + categories[slug].subcategory : ""), n]));
  const categoryPoints = products.filter((p) => p.flags.some((f) => f.code.startsWith("category-")));
  line(`### 6.1 Products whose category needs a look (${categoryPoints.length})`);
  line();
  if (categoryPoints.length) table(["Product", "Filed as", "Remark"], categoryPoints.map((p) => [p.name, labelOf(p), p.flags.filter((f) => f.code.startsWith("category-")).map((f) => f.text).join(" ")]));
  else { line("None."); line(); }

  line("## 7. Images and descriptions");
  line();
  line(`Each product is loaded with the one image the live shop shows on its listing, ${products.length} images for ${products.length} products. The image is referred to by its address on ${SITE}; the file itself was not copied, because the environment this was built in cannot reach the shop. Before the old shop is switched off, the folder \`assets/images/products/\` must be copied to the new host and served at the same address. That step belongs to the Hostinger phases.`);
  line();
  const imagePoints = products.filter((p) => p.flags.some((f) => f.code.startsWith("image-")));
  line(`### 7.1 Images to replace or check (${imagePoints.length})`);
  line();
  if (imagePoints.length) table(["Product", "Image file", "Remark"], imagePoints.map((p) => [p.name, "`" + p.imageFile + "`", p.flags.filter((f) => f.code.startsWith("image-")).map((f) => f.text).join(" ")]));
  else { line("None."); line(); }
  line("### 7.2 Descriptions");
  line();
  line("No description is loaded. Three product pages were read. Their text names a different product from the one on the page, repeats one passage about office chairs under a table, and gives the warranty as two years and as three years on the same page; it also asks for 50% to 75% payment in advance, which contradicts cash on delivery (OD-4). Text of that kind should be written or approved once by the person who answers Q-4, not copied. Until then a draft has a name, a SKU, a price, a category and an image, and staff can add a description in the dashboard.");
  line();

  const notes = withFlag("note");
  line(`## 8. Other remarks (${notes.length}) and rows left out (${skipped.length})`);
  line();
  if (notes.length) table(["Product", "Remark"], notes.map((p) => [p.name, flagText(p, "note")]));
  if (skipped.length) table(["Address", "Name", "Why it is left out"], skipped.map((x) => ["`" + x.address + "`", x.name, x.reason]));
  const pairing = withFlag("pairing-assumed");
  if (pairing.length) {
    line(`**Matched by order (${pairing.length}).** These products share a name and a price with another, so the quotation page cannot tell which SKU belongs to which address. They were matched in the order they were created.`);
    line();
    table(["Product", "Address", "SKU on the live shop"], pairing.map((p) => [p.name, "`" + p.id + "`", p.source.sku]));
  }

  line("## 9. The full list");
  line();
  line("The same list, with one more column per product for the owner's answer, is in [`import-list.csv`](import-list.csv); it opens in Excel or Google Sheets.");
  line();
  table(["#", "Product", "SKU", "Price", "Category", "Points to check"], products.map((p, i) => [i + 1, p.name, p.sku + (["generated", "reassigned"].includes(p.skuAction) ? " *" : ""), priceCell(p), labelOf(p), p.flags.length || ""]));
  line("\\* a code proposed by the import, not one from the live shop.");
  line();

  line("## 10. Sign-off");
  line();
  line("| | |");
  line("|---|---|");
  line("| The source is the product list of the live shop (Q-5) | ☐ agreed ☐ another source: |");
  line("| The rules in §2 | ☐ agreed ☐ changes: |");
  line("| The prices in §3 | ☐ supplied in the sheet |");
  line("| The SKUs in §4 | ☐ agreed ☐ corrected in the sheet |");
  line("| Names, addresses and categories in §5 and §6 | ☐ agreed ☐ corrected in the sheet |");
  line("| Name, date | |");
  line();
  return out.join("\n");
}

export function buildAll(options) {
  const result = buildCatalogue(readInputs(options));
  return { result, files: [[DATA_FILE, renderData(result)], [REPORT_FILE, renderReport(result)], [CSV_FILE, renderCsv(result)]] };
}

/* ---------- command line ---------- */
if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  const check = process.argv.includes("--check");
  let built;
  try { built = buildAll(); } catch (error) { console.error("[catalog] " + error.message); process.exit(1); }
  const { result, files } = built;
  if (check) {
    const stale = files.filter(([file, text]) => !fs.existsSync(file) || fs.readFileSync(file, "utf8") !== text).map(([file]) => path.relative(ROOT, file));
    if (stale.length) { console.error(`[catalog] stale: ${stale.join(", ")} — run: npm run catalog:build`); process.exit(1); }
    console.log(`[catalog] the prepared catalogue, the report and the sheet match the capture and the rules (${result.summary.imported} products).`);
  } else {
    for (const [file, text] of files) { fs.mkdirSync(path.dirname(file), { recursive: true }); fs.writeFileSync(file, text); }
    const s = result.summary;
    console.log(`[catalog] ${s.onLiveShop} on the live shop → ${s.imported} drafts, ${s.skipped} left out`);
    console.log(`[catalog] SKUs: ${s.skuLabelRemoved} labels removed, ${s.skuGenerated} generated, ${s.skuReassigned} reassigned (${s.sharedSkuGroups} shared codes)`);
    console.log(`[catalog] prices: ${s.priceOnRequest} on request, ${s.priceRounded} rounded, ${s.withComparePrice} with a "was" price`);
    console.log(`[catalog] ${s.clean} need nothing checked, ${s.needingReview} have a point to check`);
    for (const [file] of files) console.log("          wrote " + path.relative(ROOT, file));
  }
}

#!/usr/bin/env node
/**
 * Browser journey: the catalogue, from the import to the checkout.
 *
 * `npm run test:catalog` proves what the API does. This proves what a person
 * is SHOWN, which is where the old catalogue went wrong: sample products with
 * sample prices on screen while the real ones were somewhere else, ratings
 * nobody gave, "PKR 0" for a product that had no price.
 *
 *   1. The shop while the catalogue is on its way, and when it cannot be read:
 *      a placeholder, then a message — never a list of products from nowhere.
 *   2. Staff load the prepared catalogue. It arrives as drafts and the shop
 *      does not change.
 *   3. Staff publish one priced product and one priced on request.
 *   4. The shop shows them: SKU, price, "made to order"; the one without a
 *      price can be quoted and cannot be ordered, and no total reads "PKR 0".
 *      A photograph that does not load is replaced, not shown broken.
 *   5. Staff add two options. The shop asks for a choice, prices each option,
 *      and keeps two options of one product as two lines of the cart.
 *   6. The order is placed and carries the option and its SKU.
 *   7. A cart saved before a product lost its price is stopped at checkout.
 *   8. Staff withdraw the demo catalogue. The shop holds the real products
 *      only; an old address and an old cart say so.
 *   9. Staff take a product back to draft; it leaves the shop.
 *
 * By default the script owns its server: the built site (dist/) served by one
 * process on a throwaway SQLite file with the demo seed — the bundles and the
 * shape that ship. Needs `npm run vercel:build` first.
 *
 *   node scripts/e2e-catalog.mjs
 *
 * With STOREFRONT_BASE and DASHBOARD_BASE it drives servers that are already
 * running. Their database must be a fresh demo seed: the journey loads the
 * catalogue and withdraws the demo products.
 *
 * Env: E2E_REQUIRED=1        a browser that cannot start is an error, not a skip
 *      E2E_CATALOG_PORT      port of the script's own server (default 4183)
 *      E2E_SHOT_DIR          keep a screenshot of each stage, and of a failure
 */
import { spawn } from "node:child_process";
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { launchBrowser, signInToDashboard } from "./lib/browser.mjs";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const dataset = JSON.parse(fs.readFileSync(path.join(ROOT, "apps/api/catalog-data.json"), "utf8"));
const DEMO = JSON.parse(fs.readFileSync(path.join(ROOT, "apps/api/seed-data.json"), "utf8")).products;
const SHOTS = process.env.E2E_SHOT_DIR ? path.resolve(process.env.E2E_SHOT_DIR) : "";
if (SHOTS) fs.mkdirSync(SHOTS, { recursive: true });

const problems = [];
const check = (ok, label, detail = "") => {
  if (ok) console.log(`  ✓ ${label}`);
  else { problems.push(`${label}${detail ? ` — ${detail}` : ""}`); console.log(`  ✗ ${label}${detail ? ` — ${detail}` : ""}`); }
};
const same = (actual, expected, label) =>
  check(JSON.stringify(actual) === JSON.stringify(expected), label, `got ${JSON.stringify(actual)}, expected ${JSON.stringify(expected)}`);
const delay = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/* ---------- the site under test ---------- */
let server = null;
let tempRoot = "";
let STOREFRONT = (process.env.STOREFRONT_BASE ?? "").replace(/\/+$/, "");
let DASHBOARD = (process.env.DASHBOARD_BASE ?? "").replace(/\/+$/, "");
const external = Boolean(STOREFRONT && DASHBOARD);

async function startOwnServer() {
  const dist = path.join(ROOT, "dist");
  if (!fs.existsSync(path.join(dist, "index.html")) || !fs.existsSync(path.join(dist, "dashboard", "index.html"))) {
    console.error("dist/ is missing or incomplete — run `npm run vercel:build` before this journey, or point it at running servers with STOREFRONT_BASE and DASHBOARD_BASE.");
    process.exit(1);
  }
  const port = Number(process.env.E2E_CATALOG_PORT || 4183);
  tempRoot = fs.mkdtempSync(path.join(os.tmpdir(), "woodex-e2e-catalog-"));
  const env = { ...process.env, NODE_ENV: "test", PORT: String(port), WOODEX_DATA_DIR: tempRoot, WOODEX_SERVE_STATIC: dist, WOODEX_DEMO_SEED: "1" };
  for (const key of ["DATABASE_URL", "VERCEL", "VERCEL_ENV", "INITIAL_ADMIN_EMAIL", "INITIAL_ADMIN_PASSWORD"]) delete env[key];
  let log = "";
  server = spawn(process.execPath, ["--experimental-sqlite", "apps/api/server.mjs"], { cwd: ROOT, env, stdio: ["ignore", "pipe", "pipe"] });
  server.stdout.on("data", (chunk) => { log = (log + chunk).slice(-4000); });
  server.stderr.on("data", (chunk) => { log = (log + chunk).slice(-4000); });
  const origin = `http://127.0.0.1:${port}`;
  for (let i = 0; i < 100; i++) {
    if (server.exitCode !== null) break;
    try { if ((await fetch(origin + "/api/health", { signal: AbortSignal.timeout(1500) })).ok) { STOREFRONT = origin; DASHBOARD = origin + "/dashboard"; return; } }
    catch { /* not up yet */ }
    await delay(200);
  }
  console.error("the journey's own server did not start:\n" + log);
  process.exit(1);
}

async function stopOwnServer() {
  if (server && server.exitCode === null) {
    const exited = new Promise((resolve) => server.once("exit", resolve));
    server.kill("SIGTERM");
    await Promise.race([exited, delay(4000)]);
    if (server.exitCode === null) server.kill("SIGKILL");
  }
  if (tempRoot) fs.rmSync(tempRoot, { recursive: true, force: true });
}

/* The API, asked directly — to learn what is true, never to do the work: every
   change in this journey is made through a screen. */
let token = "";
async function api(pathname, { method = "GET", body, staff = false } = {}) {
  const res = await fetch(STOREFRONT + pathname, {
    method,
    headers: { "content-type": "application/json", ...(staff ? { authorization: "Bearer " + token } : {}) },
    body: body ? JSON.stringify(body) : undefined,
  });
  let json = null;
  try { json = await res.json(); } catch { /* not JSON */ }
  return { status: res.status, json };
}

if (!external) await startOwnServer();
const browser = await launchBrowser();

let exitCode = 0;
let failingPage = null;
try {
  token = (await api("/api/auth/login", { method: "POST", body: { email: "admin@woodex.pk", password: "admin" } })).json?.token ?? "";
  if (!token) throw new Error("could not sign in to the API as the demo owner");
  const before = (await api("/api/products")).json;
  if (before?.total !== DEMO.length) {
    throw new Error(`this journey needs a fresh demo seed (${DEMO.length} products on sale); the database at ${STOREFRONT} has ${before?.total}`);
  }

  const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  context.setDefaultTimeout(15_000);
  /* The prepared catalogue names its photographs by their address on the old
     shop. This journey must not depend on that server, and must not load it:
     every such request is refused here, which is also how a photograph that
     has gone missing looks to the page. */
  const photoHost = new URL(dataset.products.find((p) => /^https?:/.test(p.images?.[0] ?? ""))?.images[0] ?? "https://woodexfurniture.pk/").origin;
  await context.route(photoHost + "/**", (route) => route.abort());
  const pageErrors = [];
  const watch = (page, name) => { page.on("pageerror", (error) => pageErrors.push(`${name}: ${error.message}`)); return page; };
  const shot = async (page, name) => { if (SHOTS) await page.screenshot({ path: path.join(SHOTS, name + ".png"), fullPage: false }); };

  /* A product card, on the shop page and wherever else cards are drawn. */
  const CARD = 'main a[href^="/shop/"] h3';
  /* Amounts are written by the browser's own formatter; ask it rather than guess its grouping. */
  const pkr = (page, amount) => page.evaluate((n) => "PKR " + n.toLocaleString("en-PK"), amount);
  const text = async (locator) => (await locator.innerText()).replace(/\s+/g, " ").trim();

  /* ───────── 1. before the catalogue is on screen ───────── */
  console.log("\n[1] the shop while the catalogue is on its way, and when it cannot be read");
  const shop = watch(await context.newPage(), "storefront");
  failingPage = shop;
  let release;
  const held = new Promise((resolve) => { release = resolve; });
  await shop.route("**/api/products", async (route) => { await held; await route.continue(); });
  await shop.goto(STOREFRONT + "/shop", { waitUntil: "domcontentloaded" });
  await shop.getByText("Loading the catalogue…").waitFor({ state: "attached" });
  await shop.getByRole("heading", { level: 1, name: /Shop Office/ }).waitFor();
  same(await shop.locator(CARD).count(), 0, "while the catalogue is loading no product is on the page");
  same([await shop.getByText("No products found").count(), await shop.getByText("The catalogue is unavailable right now").count()], [0, 0],
    "…and the page says neither \"no products\" nor \"unavailable\": it says it is loading");
  await shot(shop, "1-shop-loading");
  release();
  await shop.locator(CARD).first().waitFor();
  await shop.unroute("**/api/products");
  same(await shop.locator(CARD).count(), before.total, "when the catalogue arrives, every product on sale is on the page");
  same(await shop.locator("main svg.lucide-star").count(), 0, "no product card carries a star rating");
  await shop.getByRole("combobox").click();
  const sortOptions = await shop.getByRole("option").allInnerTexts();
  check(sortOptions.length >= 4 && !sortOptions.some((option) => /rated/i.test(option)), "the shop no longer offers to sort by rating", sortOptions.join(" | "));
  await shop.keyboard.press("Escape");

  const down = watch(await context.newPage(), "storefront (API down)");
  failingPage = down;
  await down.route("**/api/products", (route) => route.fulfill({ status: 503, contentType: "application/json", body: '{"error":"unavailable"}' }));
  await down.goto(STOREFRONT + "/shop", { waitUntil: "domcontentloaded" });
  await down.getByText("The catalogue is unavailable right now").waitFor();
  same(await down.locator(CARD).count(), 0, "when the catalogue cannot be read, no product is shown — there is no compiled list to fall back on");
  await shot(down, "1-shop-unavailable");
  await down.goto(`${STOREFRONT}/shop/${DEMO[0].id}`, { waitUntil: "domcontentloaded" });
  await down.getByText("The catalogue is unavailable right now").waitFor();
  same(await down.getByText("Product Not Found").count(), 0, "a product address then says \"unavailable\", not \"product not found\"");
  await down.unroute("**/api/products");
  await down.getByRole("button", { name: "Try again" }).click();
  await down.getByRole("heading", { level: 1, name: DEMO[0].name }).waitFor();
  check(true, "\"Try again\" loads the product once the API answers");
  await down.close();

  /* ───────── 2. staff load the prepared catalogue ───────── */
  console.log("\n[2] staff load the prepared catalogue");
  const dash = watch(await context.newPage(), "dashboard");
  failingPage = dash;
  await dash.goto(DASHBOARD + "/", { waitUntil: "domcontentloaded" });
  await signInToDashboard(dash);
  await dash.getByRole("link", { name: "Overview", exact: true }).waitFor();
  await dash.goto(DASHBOARD + "/catalog/import", { waitUntil: "domcontentloaded" });
  await dash.getByRole("heading", { name: "Catalogue import" }).waitFor();
  const plan = (await api("/api/catalog/import", { staff: true })).json;
  same(plan?.dataset?.products, dataset.products.length, "the screen reads the prepared catalogue that ships with the build");
  const loadButton = dash.getByRole("button", { name: /^Load \d+ products? as drafts$/ });
  same(await text(loadButton), `Load ${plan.summary.create} products as drafts`, "the button says how many products it will load");
  const importing = dash.waitForResponse((r) => new URL(r.url()).pathname === "/api/catalog/import" && r.request().method() === "POST");
  await loadButton.click();
  const importResponse = await importing;
  const imported = await importResponse.json();
  same([importResponse.status(), imported.created], [201, plan.summary.create], "the catalogue is loaded");
  await dash.getByText(/loaded as drafts/).first().waitFor();
  await dash.getByRole("link", { name: /^Review \d+ drafts?$/ }).waitFor();
  await shot(dash, "2-import-done");
  const afterImport = (await api("/api/catalog/status", { staff: true })).json;
  same([afterImport?.counts?.draft, afterImport?.counts?.published], [imported.created, DEMO.length], "every product arrived as a draft; nothing was published");
  same((await api("/api/products")).json?.total, DEMO.length, "the shop is unchanged: a draft is not on sale");

  const loaded = new Set(((await api("/api/catalog/import", { staff: true })).json?.items ?? []).filter((item) => item.action === "present").map((item) => item.id));
  const priced = dataset.products.find((p) => loaded.has(p.id) && !p.priceOnRequest && !(p.review ?? []).length && !DEMO.some((d) => d.id === p.id));
  const quoted = dataset.products.find((p) => loaded.has(p.id) && p.priceOnRequest && !DEMO.some((d) => d.id === p.id));
  if (!priced || !quoted) throw new Error("the prepared catalogue has no priced product, or none priced on request, to continue with");
  failingPage = shop;
  await shop.goto(`${STOREFRONT}/shop/${priced.id}`, { waitUntil: "domcontentloaded" });
  await shop.getByRole("heading", { name: "Product Not Found" }).waitFor();
  check(true, "a draft's address answers \"product not found\"");

  /* ───────── 3. staff publish two products ───────── */
  console.log("\n[3] staff publish one priced product and one priced on request");
  failingPage = dash;
  const publish = async (product) => {
    await dash.goto(DASHBOARD + "/catalog?status=draft", { waitUntil: "domcontentloaded" });
    await dash.getByPlaceholder("Search by name, SKU or address…").fill(product.sku);
    await dash.getByLabel(`Select ${product.name}`, { exact: true }).check();
    const changing = dash.waitForResponse((r) => new URL(r.url()).pathname === "/api/products/bulk-status" && r.request().method() === "POST");
    await dash.getByRole("button", { name: "Publish", exact: true }).click();
    const reply = await (await changing).json();
    same([reply.changed, reply.results?.[0]?.ok], [1, true], `${product.name} (${product.sku}) is published from the catalogue screen`);
  };
  await publish(priced);
  await publish(quoted);
  await shot(dash, "3-published");
  same((await api("/api/products")).json?.total, DEMO.length + 2, "two more products are on sale");

  /* ───────── 4. the shop shows them ───────── */
  console.log("\n[4] the shop shows the real products");
  failingPage = shop;
  await shop.goto(`${STOREFRONT}/shop/${priced.id}`, { waitUntil: "domcontentloaded" });
  await shop.getByRole("heading", { level: 1, name: priced.name }).waitFor();
  /* The product itself: the first section of the page. "Related products" below it has cards of its own. */
  const productInfo = shop.locator("main > section").first();
  check((await text(productInfo)).includes(`SKU ${priced.sku}`), `the product page shows its SKU (${priced.sku})`);
  check((await text(productInfo)).includes(await pkr(shop, priced.price)), `…and its price (${await pkr(shop, priced.price)})`);
  same([await productInfo.getByText("Made to order", { exact: true }).count(), /\bin stock\b/i.test(await text(productInfo))], [1, false],
    "…and says it is made to order, not a quantity in stock");
  await productInfo.getByRole("img", { name: `${priced.name}: no photograph` }).first().waitFor();
  same(await productInfo.locator("img").evaluateAll((images) => images.filter((image) => image.complete && image.naturalWidth === 0).length), 0,
    "a photograph that cannot be loaded is replaced by the product's initial, not left as a broken image");
  same(await shop.locator("main svg.lucide-star").count(), 0, "…and carries no star rating");
  check((await shop.title()).includes(priced.name), "the browser tab is named after the product", await shop.title());
  const ld = JSON.parse(await shop.locator("#product-jsonld").innerText());
  same([ld.sku, ld.offers?.price, ld.offers?.priceCurrency, ld.offers?.availability], [priced.sku, priced.price, "PKR", "https://schema.org/MadeToOrder"],
    "its structured data states the SKU, the price and \"made to order\"");
  check(await shop.getByRole("button", { name: "Add to Cart" }).isEnabled(), "it can be added to the cart");
  await shot(shop, "4-product-priced");

  await shop.goto(`${STOREFRONT}/shop/${quoted.id}`, { waitUntil: "domcontentloaded" });
  await shop.getByRole("heading", { level: 1, name: quoted.name }).waitFor();
  same(await shop.getByText("Price on request", { exact: true }).count(), 1, "a product without a price reads \"Price on request\"");
  same(await shop.getByRole("button", { name: /Add to Cart/ }).count(), 0, "…and has no cart button: it cannot be ordered");
  const ldQuoted = JSON.parse(await shop.locator("#product-jsonld").innerText());
  same(["offers" in ldQuoted, ldQuoted.sku], [false, quoted.sku], "…and its structured data carries no offer, rather than an offer of 0");
  check(!/PKR 0\b/.test(await text(productInfo)), "…and \"PKR 0\" is nowhere on its page");
  await shot(shop, "4-product-on-request");
  await shop.getByRole("button", { name: "Add to Quote" }).click();
  const quoteDrawer = shop.locator("div.fixed.right-0").filter({ has: shop.getByRole("heading", { name: "Quote Basket" }) });
  await quoteDrawer.getByText("Price on request", { exact: true }).waitFor();
  const drawerText = await text(quoteDrawer);
  check(/Estimated Total On request/.test(drawerText) && !/PKR 0\b/.test(drawerText), "in the quote basket its estimate reads \"On request\", not \"PKR 0\"", drawerText.slice(0, 200));
  await shot(shop, "4-quote-basket-on-request");
  await shop.getByRole("button", { name: "Close quote basket" }).click();

  /* ───────── 5. options ───────── */
  console.log("\n[5] staff add two options; the shop asks for a choice");
  failingPage = dash;
  const options = [
    { label: "1600 mm", sku: `${priced.sku}-16`, price: priced.price },
    { label: "1800 mm", sku: `${priced.sku}-18`, price: priced.price + 5000 },
  ];
  await dash.goto(`${DASHBOARD}/catalog/${priced.id}/edit`, { waitUntil: "domcontentloaded" });
  await dash.getByRole("heading", { name: "Options" }).waitFor();
  for (const option of options) {
    const newRow = dash.locator("#variant-new-label").locator("xpath=..");
    await dash.locator("#variant-new-label").fill(option.label);
    await newRow.getByLabel("Option SKU").fill(option.sku);
    await newRow.getByLabel("Option price in rupees").fill(String(option.price));
    const adding = dash.waitForResponse((r) => new URL(r.url()).pathname === `/api/products/${priced.id}/variants` && r.request().method() === "POST");
    await newRow.getByRole("button", { name: "Add option" }).click();
    same((await adding).status(), 201, `the option "${option.label}" is added under its own SKU ${option.sku}`);
    await dash.getByText(`Now: ${option.label} · ${option.sku}`, { exact: false }).waitFor();
  }
  await shot(dash, "5-options-editor");

  failingPage = shop;
  await shop.goto(`${STOREFRONT}/shop/${priced.id}`, { waitUntil: "domcontentloaded" });
  await shop.getByRole("heading", { level: 1, name: priced.name }).waitFor();
  await shop.getByText("Choose an option to see its price.").waitFor();
  check(await shop.getByRole("button", { name: "Choose an option" }).isDisabled(), "with options, the product cannot go into the cart before one is chosen");
  await shop.getByText(options[1].label, { exact: true }).click();
  await shop.getByText(`SKU ${options[1].sku}`).waitFor();
  check((await text(productInfo)).includes(await pkr(shop, options[1].price)), `choosing "${options[1].label}" shows that option's price and SKU`);
  await shot(shop, "5-product-option-chosen");
  await shop.getByRole("button", { name: "Add to Cart" }).click();
  const cart = shop.getByRole("dialog").filter({ hasText: "Shopping Cart" });
  await cart.getByText(options[1].label).waitFor();
  await shop.keyboard.press("Escape");
  await shop.getByText(options[0].label, { exact: true }).click();
  await shop.getByText(`SKU ${options[0].sku}`).waitFor();
  await shop.getByRole("button", { name: /Add to Cart|Added to Cart!/ }).click();
  await cart.getByText(options[0].label).waitFor();
  same(await cart.getByRole("button", { name: `Remove ${priced.name}` }).count(), 2, "two options of one product are two lines of the cart");
  await cart.locator("div.border").filter({ hasText: options[0].label }).getByRole("button", { name: `Remove ${priced.name}` }).click();
  await cart.getByText(options[0].label).waitFor({ state: "detached" });
  same([await cart.getByText(options[1].label).count(), await cart.getByRole("button", { name: `Remove ${priced.name}` }).count()], [1, 1],
    "removing one option leaves the other in the cart");
  await shot(shop, "5-cart-one-option-left");

  /* ───────── 6. the order ───────── */
  console.log("\n[6] the order carries the option");
  await cart.getByRole("link", { name: "Proceed to Checkout" }).click();
  await shop.getByRole("heading", { level: 1, name: "Checkout" }).waitFor();
  const summary = shop.locator("main form");
  check((await text(summary)).includes(options[1].label), "the order summary names the option");
  const subtotal = options[1].price;
  const expectedTotal = subtotal + (subtotal >= 50_000 ? 0 : 2_500);
  check((await text(summary)).includes(`Total ${await pkr(shop, expectedTotal)}`), `the total shown is ${await pkr(shop, expectedTotal)} (price${subtotal >= 50_000 ? ", delivery free" : " plus delivery"})`);
  await shop.locator("#name").fill("E2E Catalogue Journey");
  await shop.locator("#phone").fill("+92 300 5550123");
  await shop.locator("#address").fill("1 Test Street, Lahore");
  const ordering = shop.waitForResponse((r) => new URL(r.url()).pathname === "/api/orders" && r.request().method() === "POST");
  await shop.getByRole("button", { name: "Submit order request" }).click();
  const orderResponse = await ordering;
  const order = await orderResponse.json();
  same(orderResponse.status(), 201, "the order is accepted");
  same([order.items?.[0]?.id, order.items?.[0]?.sku, order.items?.[0]?.variant, order.items?.[0]?.price, order.items?.[0]?.qty],
    [priced.id, options[1].sku, options[1].label, options[1].price, 1], "the saved order line names the product, the option, its SKU and its price");
  same(order.total, expectedTotal, "the total the server saved is the total the page showed");
  await shop.getByRole("heading", { name: "Order request received" }).waitFor();
  check((await text(shop.locator("main"))).includes(order.ref), `the customer is given the reference ${order.ref}`);
  await shot(shop, "6-order-received");

  /* ───────── 7. a cart from before ───────── */
  console.log("\n[7] a cart saved before a product lost its price");
  await shop.evaluate(([id, name]) => {
    localStorage.setItem("woodex-cart", JSON.stringify([{ id, name, category: "office-tables", price: 45000, quantity: 1 }]));
  }, [quoted.id, quoted.name]);
  await shop.goto(STOREFRONT + "/checkout", { waitUntil: "domcontentloaded" });
  await shop.getByText("Priced on request — add it to a quote instead").waitFor();
  check(await shop.getByRole("button", { name: "Submit order request" }).isDisabled(), "the line says why it cannot be ordered, and the order cannot be sent");
  check(!(await text(shop.locator("main form"))).includes(await pkr(shop, 45000)), "the price the old cart remembered is not shown");
  await shot(shop, "7-checkout-blocked");
  same((await api("/api/orders", { method: "POST", body: { customer: "E2E", contact: "0300", items: [{ id: quoted.id, qty: 1 }] } })).json?.code, "price_on_request",
    "the API refuses the same order, whatever the page does");

  /* ───────── 8. the demo catalogue is withdrawn ───────── */
  console.log("\n[8] staff withdraw the demo catalogue");
  await shop.evaluate(([id, name, price]) => {
    localStorage.setItem("woodex-cart", JSON.stringify([{ id, name, category: "office-tables", price, quantity: 2 }]));
  }, [DEMO[0].id, DEMO[0].name, DEMO[0].price]);
  failingPage = dash;
  await dash.goto(DASHBOARD + "/catalog/import", { waitUntil: "domcontentloaded" });
  await dash.getByRole("button", { name: "Withdraw the demo catalogue" }).click();
  const archiving = dash.waitForResponse((r) => new URL(r.url()).pathname === "/api/catalog/archive-demo" && r.request().method() === "POST");
  await dash.getByRole("button", { name: `Withdraw ${DEMO.length} demo products` }).click();
  const archived = await (await archiving).json();
  same([archived.archived, archived.publishedLeft], [DEMO.length, 2], "every demo product is archived; the two real ones stay on sale");
  await dash.getByText(`All ${DEMO.length} demo products are withdrawn.`, { exact: false }).waitFor();
  await shot(dash, "8-demo-withdrawn");

  failingPage = shop;
  await shop.goto(STOREFRONT + "/shop", { waitUntil: "domcontentloaded" });
  await shop.locator(CARD).first().waitFor();
  same((await shop.locator(CARD).allInnerTexts()).map((name) => name.trim()).sort(), [priced.name, quoted.name].sort(), "the shop lists the real products and nothing else");
  const pageText = await text(shop.locator("main"));
  check(pageText.includes("From " + await pkr(shop, options[0].price)), "a product sold in options reads \"From …\" its lowest price");
  check(pageText.includes("Price on request") && !/PKR 0\b/.test(pageText), "the product without a price reads \"Price on request\" in the list too");
  await shot(shop, "8-shop-real-catalogue");
  await shop.goto(`${STOREFRONT}/shop/${DEMO[0].id}`, { waitUntil: "domcontentloaded" });
  await shop.getByRole("heading", { name: "Product Not Found" }).waitFor();
  same(await shop.locator('meta[name="robots"][content="noindex"]').count(), 1, "a demo product's old address answers \"product not found\" and asks not to be indexed");
  await shop.goto(STOREFRONT + "/checkout", { waitUntil: "domcontentloaded" });
  await shop.getByText("No longer available").first().waitFor();
  check(await shop.getByRole("button", { name: "Submit order request" }).isDisabled(), "a cart that still holds a demo product is stopped, with the reason on the line");
  await shop.goto(STOREFRONT + "/", { waitUntil: "domcontentloaded" });
  await shop.getByRole("heading", { name: "Latest Collection" }).waitFor();
  const latest = shop.locator("section").filter({ has: shop.getByRole("heading", { name: "Latest Collection" }) });
  await latest.locator('a[href^="/shop/"] h3').first().waitFor();
  const latestLinks = await latest.locator('a[href^="/shop/"]').evaluateAll((links) => links.map((a) => a.getAttribute("href")).filter((href) => href !== "/shop"));
  same([...new Set(latestLinks)].sort(), [`/shop/${priced.id}`, `/shop/${quoted.id}`].sort(), "the home page's \"Latest Collection\" links to the products the shop really has");

  /* ───────── 9. back to draft ───────── */
  console.log("\n[9] staff take a product back to draft");
  failingPage = dash;
  await dash.goto(DASHBOARD + "/catalog?status=published", { waitUntil: "domcontentloaded" });
  await dash.getByPlaceholder("Search by name, SKU or address…").fill(quoted.sku);
  await dash.getByLabel(`Select ${quoted.name}`, { exact: true }).check();
  const withdrawing = dash.waitForResponse((r) => new URL(r.url()).pathname === "/api/products/bulk-status" && r.request().method() === "POST");
  await dash.getByRole("button", { name: "Back to draft", exact: true }).click();
  same((await (await withdrawing).json()).changed, 1, `${quoted.name} is taken back to draft`);
  failingPage = shop;
  await shop.goto(STOREFRONT + "/shop", { waitUntil: "domcontentloaded" });
  await shop.locator(CARD).first().waitFor();
  same((await shop.locator(CARD).allInnerTexts()).map((name) => name.trim()), [priced.name], "it has left the shop; one product is on sale");

  same(pageErrors, [], "neither app threw an error during the journey");
  failingPage = null;
  await context.close();
} catch (error) {
  exitCode = 1;
  console.error("\nE2E FAIL:", error instanceof Error ? error.stack?.split("\n").slice(0, 4).join(" | ") : String(error));
  if (failingPage) {
    try {
      const where = failingPage.url();
      const file = path.join(SHOTS || os.tmpdir(), "e2e-catalog-failure.png");
      await failingPage.screenshot({ path: file, fullPage: true });
      console.error(`  page: ${where}\n  screenshot: ${file}`);
    } catch { /* the page is gone */ }
  }
} finally {
  try { await browser.close(); } catch { /* best effort */ }
  if (!external) await stopOwnServer();
}

if (problems.length) {
  exitCode = 1;
  console.error(`\n✗ ${problems.length} problem(s) in the catalogue journey:\n`);
  for (const problem of problems) console.error("  • " + problem);
} else if (exitCode === 0) {
  console.log("\nE2E PASS: loading and unavailable states → import as drafts → publish → SKU, price, price on request → options → order with the option → withdrawn demo catalogue → back to draft.");
}
process.exit(exitCode);

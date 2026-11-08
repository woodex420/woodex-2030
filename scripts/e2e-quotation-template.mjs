#!/usr/bin/env node
/* Browser acceptance for the quotation-template designer and per-quote print snapshot.
   Requires the API + dashboard and Playwright Chromium. Set E2E_REQUIRED=1 in CI. */
import { existsSync } from "node:fs";
import { DatabaseSync } from "node:sqlite";

const BASE = process.env.DASHBOARD_BASE ?? process.env.BASE ?? "http://127.0.0.1:5173";
const browserRequired = process.env.E2E_REQUIRED === "1";
const unavailable = (message) => {
  console.log(`${browserRequired ? "ERROR" : "SKIP"}: ${message}`);
  process.exit(browserRequired ? 1 : 0);
};
let chromium;
try { ({ chromium } = await import("playwright")); }
catch { unavailable("Playwright is unavailable. Install dependencies before browser E2E."); }
let browser;
try { browser = await chromium.launch(); }
catch (error) {
  unavailable("no Chromium binary — run `npx playwright install --with-deps chromium` in a network-enabled environment. (" + String(error.message).split("\n")[0] + ")");
}

const marker = `E2E Quote Template ${Date.now()}`;
const templateName = `${marker} Layout`;
const customerName = `${marker} Customer`;
let templateId = null;
let quoteId = null;
let context;

const requireCondition = (condition, message) => { if (!condition) throw new Error(message); };

function cleanup() {
  const dbPath = process.env.E2E_SQLITE_DB ?? new URL("../data/woodex.db", import.meta.url).pathname;
  if (!existsSync(dbPath)) return;
  const db = new DatabaseSync(dbPath);
  try {
    db.exec("BEGIN IMMEDIATE");
    try {
      if (quoteId != null) {
        db.prepare("DELETE FROM tasks WHERE quote_id=?").run(quoteId);
        db.prepare("DELETE FROM quote_versions WHERE quote_id=?").run(quoteId);
        db.prepare("DELETE FROM client_links WHERE entity='quote' AND entity_id=?").run(quoteId);
        db.prepare("DELETE FROM quotes WHERE id=?").run(quoteId);
      }
      if (templateId) db.prepare("DELETE FROM quote_templates WHERE id=?").run(templateId);
      const client = db.prepare("SELECT id FROM clients WHERE name=?").get(customerName);
      if (client) {
        db.prepare("DELETE FROM messages WHERE conversation_id IN (SELECT id FROM conversations WHERE client_id=?)").run(client.id);
        db.prepare("DELETE FROM conversations WHERE client_id=?").run(client.id);
        db.prepare("DELETE FROM tasks WHERE client_id=?").run(client.id);
        db.prepare("DELETE FROM client_links WHERE client_id=?").run(client.id);
        db.prepare("DELETE FROM clients WHERE id=?").run(client.id);
      }
      db.exec("COMMIT");
    } catch (error) {
      db.exec("ROLLBACK");
      throw error;
    }
  } finally {
    db.close();
  }
}

try {
  context = await browser.newContext({ timezoneId: "Asia/Karachi" });
  context.setDefaultTimeout(12_000);
  const page = await context.newPage();
  const pageErrors = [];
  page.on("pageerror", (error) => pageErrors.push(error.message));

  await page.goto(`${BASE}/dashboard`, { waitUntil: "domcontentloaded" });
  await page.getByPlaceholder("admin").first().fill("admin");
  await page.locator('input[type="password"]').fill("admin");
  await page.getByRole("button", { name: /sign in/i }).click();
  await page.getByRole("link", { name: "Overview", exact: true }).waitFor();

  await page.goto(`${BASE}/quotations/new`, { waitUntil: "domcontentloaded" });
  await page.getByLabel("Client name").fill(customerName);
  await page.getByLabel("Company").fill(`${marker} Company`);
  await page.getByText(/items · from the \d+-SKU catalog/).waitFor();
  await page.getByRole("button", { name: /Add a catalog suggestion/ }).click();
  await page.getByLabel("Item name").waitFor();
  await page.getByText(/\d+ reusable template/).waitFor();

  await page.getByRole("button", { name: "Design template" }).click();
  const designer = page.getByRole("dialog");
  await designer.getByLabel("Template name").fill(templateName);
  await designer.getByLabel("Company name").fill(`${marker} Woodex`);
  await designer.getByLabel("Logo mark").fill("WX");

  const headerSection = designer.locator("section").filter({ hasText: "Header blocks" }).first();
  const headerBlocks = headerSection.locator("[data-block-row]");
  const titleBlock = headerBlocks.nth(1);
  const metaBlock = headerBlocks.nth(2);
  /* Drag by the grab handle: a press on the block's centre lands on an <input>,
     where Chromium starts a text selection instead of an HTML5 drag. */
  await page.evaluate(() => {
    const log = [];
    globalThis.__dragLog = log;
    document.addEventListener("drop", () => {
      queueMicrotask(() => log.push("afterDrop:" + [...document.querySelectorAll('[data-block-row] input:not([type="checkbox"])')].map((i) => i.value).join("/")));
    }, true);
    for (const type of ["dragstart", "dragover", "drop", "dragend"]) {
      document.addEventListener(type, (event) => {
        const target = event.target instanceof Element ? event.target : null;
        log.push(`${type}:${target?.getAttribute("data-block-drag-handle") != null ? "handle" : target?.getAttribute("data-block-row") != null ? "row" : target?.tagName ?? "?"}`);
      }, true);
    }
  });
  /* Drive the drag explicitly instead of relying on dragTo's synthesis so the
     sequence is deterministic: hover the target (dragover) before releasing. */
  const source = await titleBlock.locator("[data-block-drag-handle]").boundingBox();
  const target = await metaBlock.boundingBox();
  requireCondition(source && target, "drag blocks were not measurable");
  await page.mouse.move(source.x + source.width / 2, source.y + source.height / 2);
  await page.mouse.down();
  await page.mouse.move(target.x + target.width / 2, target.y + target.height / 2, { steps: 12 });
  await page.mouse.move(target.x + target.width / 2, target.y + target.height / 2 + 1, { steps: 2 });
  await page.mouse.up();
  await page.waitForTimeout(150);
  const dragLog = await page.evaluate(() => globalThis.__dragLog);
  const handleCount = await titleBlock.locator("[data-block-drag-handle]").count();
  const box = await titleBlock.locator("[data-block-drag-handle]").boundingBox().catch(() => null);
  const headerOrder = await headerSection.locator('[data-block-row] input:not([type="checkbox"])').evaluateAll((inputs) => inputs.map((input) => input.value));
  requireCondition(headerOrder[1] === "Date and reference" && headerOrder[2] === "Quotation",
    `Drag reorder did not update header order: ${headerOrder.join(" | ")} :: events=[${dragLog.join(",")}] handles=${handleCount} box=${JSON.stringify(box)}`);

  const footerSection = designer.locator("section").filter({ hasText: "Footer blocks" }).first();
  const thankYouBlock = footerSection.locator("[data-block-row]").nth(3);
  await thankYouBlock.locator('input:not([type="checkbox"])').first().fill("E2E footer note");
  await thankYouBlock.locator("textarea").fill("Reusable footer content from the browser acceptance test.");

  const saveTemplateResponsePromise = page.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname === "/api/quote-templates" && response.request().method() === "POST";
  });
  await designer.getByRole("button", { name: "Save as new" }).click();
  const saveTemplateResponse = await saveTemplateResponsePromise;
  requireCondition(saveTemplateResponse.status() === 201, `Template save returned HTTP ${saveTemplateResponse.status()}.`);
  const savedTemplate = await saveTemplateResponse.json();
  templateId = savedTemplate.id;
  requireCondition(savedTemplate.header?.[1]?.type === "meta" && savedTemplate.header?.[2]?.type === "title", "Saved template did not retain the dragged header order.");
  requireCondition(savedTemplate.logoText === "WX", "Editable logo mark did not persist.");
  requireCondition(savedTemplate.footer?.some((item) => item.type === "custom" && item.content === "Reusable footer content from the browser acceptance test."), "Edited footer block did not persist.");
  requireCondition(savedTemplate.footer?.some((item) => item.type === "payment-note" && item.visible && item.content === "Final payment terms will be included in formal quotation."), "Required formal payment wording was not preserved.");
  await designer.getByRole("button", { name: "Use for this quote" }).click();
  const templateSelect = page.getByLabel("Template used for this quotation");
  requireCondition(await templateSelect.inputValue() === templateId, "The saved template was not selected for this quote.");

  const saveQuoteResponsePromise = page.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname === "/api/quotes" && response.request().method() === "POST";
  });
  await page.getByRole("button", { name: /Save Draft/ }).click();
  const saveQuoteResponse = await saveQuoteResponsePromise;
  requireCondition(saveQuoteResponse.status() === 201, `Quote save returned HTTP ${saveQuoteResponse.status()}.`);
  const quote = await saveQuoteResponse.json();
  quoteId = quote.id;
  requireCondition(quote.details?.quoteTemplateId === templateId && quote.details?.quoteTemplateSnapshot?.id === templateId, "Quote did not persist its selected template snapshot.");

  await page.goto(`${BASE}/quotations/new?id=${quoteId}`, { waitUntil: "domcontentloaded" });
  await page.getByLabel("Client name").waitFor();
  await page.waitForFunction((id) => Array.from(document.querySelectorAll("select")).some((select) => select.value === id), templateId);
  await page.getByRole("button", { name: "Preview quote" }).click();
  const printTarget = page.locator("#quote-print");
  await printTarget.waitFor();
  const printText = await printTarget.innerText();
  requireCondition(printText.includes("Final payment terms will be included in formal quotation."), "Print preview omitted the required payment note.");
  requireCondition(printText.includes("Estimated total · PKR"), "Print preview omitted the estimated total.");
  requireCondition(printText.includes("WX"), "Print preview omitted the customized logo mark.");
  requireCondition(!/\b75%|25%\b/.test(printText), "Print preview reintroduced a 75%/25% advance split.");
  requireCondition(printText.includes("Reusable footer content from the browser acceptance test."), "Saved footer snapshot was not used by print preview.");

  await page.evaluate(() => { window.__woodexPrintCalled = false; window.print = () => { window.__woodexPrintCalled = true; }; });
  await page.getByRole("button", { name: "Print / Save PDF" }).click();
  requireCondition(await page.evaluate(() => window.__woodexPrintCalled), "Print / Save PDF did not invoke the browser print flow.");
  requireCondition(pageErrors.length === 0, `Browser page errors: ${pageErrors.join(" | ")}`);
  console.log(`E2E PASS: drag/edit/save template ${templateName} → apply to quote ${quote.ref} → persisted print/PDF snapshot.`);
} catch (error) {
  console.error("E2E FAIL:", error instanceof Error ? error.stack?.split("\n").slice(0, 5).join(" | ") : String(error));
  process.exitCode = 1;
} finally {
  try { await context?.close(); } catch { /* best effort */ }
  try { cleanup(); } catch (error) {
    console.error("E2E cleanup failed:", error instanceof Error ? error.message : String(error));
    process.exitCode = 1;
  }
  try { await browser.close(); } catch { /* best effort */ }
}

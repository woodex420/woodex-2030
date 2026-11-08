#!/usr/bin/env node
/* Browser acceptance for the existing Website / Pages builder.
   Requires API, dashboard, storefront and Playwright Chromium. Set E2E_REQUIRED=1 in CI. */
import { existsSync } from "node:fs";
import { DatabaseSync } from "node:sqlite";
import { fileURLToPath } from "node:url";

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

const slug = `e2e-page-${Date.now()}`;
const title = `E2E Page Builder ${Date.now()}`;
let pageId = null;
let context;

const requireCondition = (condition, message) => { if (!condition) throw new Error(message); };

function cleanup() {
  const dbPath = process.env.E2E_SQLITE_DB ?? fileURLToPath(new URL("../data/woodex.db", import.meta.url));
  if (!existsSync(dbPath)) return;
  const db = new DatabaseSync(dbPath);
  try {
    db.exec("BEGIN IMMEDIATE");
    try {
      const row = db.prepare("SELECT id FROM pages WHERE slug=?").get(slug);
      const id = pageId ?? row?.id;
      if (id != null) {
        db.prepare("DELETE FROM page_blocks WHERE page_id=?").run(id);
        db.prepare("DELETE FROM page_versions WHERE page_id=?").run(id);
        db.prepare("DELETE FROM track_events WHERE page_id=? OR slug=?").run(id, slug);
        db.prepare("DELETE FROM pages WHERE id=?").run(id);
      } else {
        db.prepare("DELETE FROM track_events WHERE slug=?").run(slug);
        db.prepare("DELETE FROM pages WHERE slug=?").run(slug);
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
  context.setDefaultTimeout(15_000);
  const page = await context.newPage();
  const pageErrors = [];
  page.on("pageerror", (error) => pageErrors.push(error.message));

  await page.goto(`${BASE}/dashboard`, { waitUntil: "domcontentloaded" });
  await page.getByPlaceholder("admin").first().fill("admin");
  await page.locator('input[type="password"]').fill("admin");
  await page.getByRole("button", { name: /sign in/i }).click();
  await page.getByRole("link", { name: "Overview", exact: true }).waitFor();

  await page.goto(`${BASE}/website`, { waitUntil: "domcontentloaded" });
  await page.getByRole("button", { name: "New page" }).click();
  const createDialog = page.getByRole("dialog");
  await createDialog.getByLabel("Title").fill(title);
  await createDialog.getByLabel("Slug").fill(slug);
  /* A substring /Blank/ also matches the "Sale landing" template button. */
  await createDialog.getByRole("button", { name: "Blank", exact: true }).click();
  const createResponsePromise = page.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname === "/api/pages" && response.request().method() === "POST";
  });
  await createDialog.getByRole("button", { name: "Create & edit" }).click();
  const createResponse = await createResponsePromise;
  requireCondition(createResponse.status() === 201, `Page creation returned HTTP ${createResponse.status()}.`);
  const created = await createResponse.json();
  pageId = created.id;
  await page.waitForURL(new RegExp(`/website/edit/${pageId}$`));
  await page.getByText("Empty page").waitFor();

  const addBlock = async (label) => {
    await page.getByRole("button", { name: "Add block / section" }).click();
    const library = page.getByRole("dialog");
    await library.getByRole("button", { name: new RegExp(`^${label}`) }).click();
  };
  await addBlock("Hero");
  const heroHeading = "E2E hero before reorder";
  await page.getByLabel("heading", { exact: true }).fill(heroHeading);

  await addBlock("CTA band");
  const ctaHeading = "E2E reordered CTA";
  await page.getByLabel("heading", { exact: true }).fill("E2E CTA before reorder");
  await page.locator("#wx-blk-1").getByRole("button", { name: "move up" }).click();
  await page.locator("#wx-blk-0").getByLabel("heading", { exact: true }).fill(ctaHeading);
  requireCondition((await page.locator("#wx-blk-0").innerText()).includes(ctaHeading), "Moving a block up did not preserve its selected editor.");
  requireCondition((await page.locator("#wx-blk-1").innerText()).includes(heroHeading), "The remaining block order is incorrect after reordering.");

  await page.locator('#wx-blk-0 [draggable="true"]').dragTo(page.locator('#wx-blk-1 [draggable="true"]'));
  requireCondition((await page.locator("#wx-blk-0").innerText()).includes(heroHeading), "Drag-and-drop did not move the target block into the expected position.");
  await page.locator("#wx-blk-1").getByRole("button", { name: "move up" }).click();
  requireCondition((await page.locator("#wx-blk-0").innerText()).includes(ctaHeading), "Arrow reordering did not restore the selected CTA block.");

  const saveResponsePromise = page.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname === `/api/pages/${pageId}/blocks` && response.request().method() === "PUT";
  });
  await page.getByRole("button", { name: "Save draft" }).click();
  const saveResponse = await saveResponsePromise;
  requireCondition(saveResponse.ok(), `Draft save returned HTTP ${saveResponse.status()}.`);
  const saved = await saveResponse.json();
  requireCondition(saved.blocks?.[0]?.type === "cta-band" && saved.blocks?.[0]?.props?.heading === ctaHeading, "Saved draft did not retain the reordered and edited CTA block.");
  requireCondition(saved.blocks?.[1]?.type === "hero" && saved.blocks?.[1]?.props?.heading === heroHeading, "Saved draft did not retain the second block in order.");

  await page.getByRole("button", { name: "Preview page" }).click();
  const previewDialog = page.getByRole("dialog").filter({ hasText: "Responsive page preview" });
  await previewDialog.waitFor();
  const preview = previewDialog.frameLocator('iframe[title="Responsive page preview"]');
  await preview.getByRole("heading", { name: ctaHeading }).waitFor();
  await preview.getByRole("heading", { name: heroHeading }).waitFor();

  const viewportGroup = previewDialog.getByRole("group", { name: "Preview viewport" });
  for (const [device, expectedWidth] of [["Tablet", 768], ["Mobile", 390]]) {
    await viewportGroup.getByRole("button", { name: new RegExp(device) }).click();
    const width = await previewDialog.locator(`[data-preview-mode="${device.toLowerCase()}"]`).evaluate((element) => Math.round(element.getBoundingClientRect().width));
    requireCondition(width === expectedWidth, `${device} preview width was ${width}px, expected ${expectedWidth}px.`);
  }
  await previewDialog.getByRole("button", { name: "Close dialog" }).click();

  await page.locator("#wx-blk-1").getByRole("button", { name: "delete" }).click();
  requireCondition(await page.locator("#wx-blk-1").count() === 0, "Removing a block did not update the structure.");
  const finalSavePromise = page.waitForResponse((response) => {
    const url = new URL(response.url());
    return url.pathname === `/api/pages/${pageId}/blocks` && response.request().method() === "PUT";
  });
  await page.getByRole("button", { name: "Save draft" }).click();
  const finalSave = await finalSavePromise;
  const finalDraft = await finalSave.json();
  requireCondition(finalDraft.blocks?.length === 1 && finalDraft.blocks[0]?.type === "cta-band", "Removed section was not absent from the saved page.");
  requireCondition(pageErrors.length === 0, `Browser page errors: ${pageErrors.join(" | ")}`);
  console.log(`E2E PASS: create blank page → add/edit/reorder/preview/remove blocks (${slug}).`);
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

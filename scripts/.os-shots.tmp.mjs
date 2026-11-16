// Throwaway: screenshots of the Laravel admin preview. Usage: node scripts/.os-shots.tmp.mjs <outDir> <label>
import { launchBrowser } from "./lib/browser.mjs";
import fs from "node:fs";
const [outDir, label] = process.argv.slice(2);
const BASE = process.env.OS_BASE ?? "http://127.0.0.1:8080";
const user = process.env.OS_USER ?? "owner@woodex.test";
const password = process.env.OS_PASSWORD;
fs.mkdirSync(outDir, { recursive: true });
const browser = await launchBrowser();
const problems = [];
async function session(viewport, tag, pages) {
  const context = await browser.newContext({ viewport });
  const page = await context.newPage();
  page.on("pageerror", (e) => problems.push(`${tag}: page error: ${e.message}`));
  page.on("console", (m) => { if (m.type() === "error") problems.push(`${tag}: console: ${m.text().slice(0, 160)}`); });
  await page.goto(BASE + "/admin/login", { waitUntil: "domcontentloaded" });
  await page.locator("#email").fill(user);
  await page.locator("#password").fill(password);
  await page.locator('button[type="submit"]').click();
  await page.waitForURL((u) => !u.pathname.endsWith("/login"), { timeout: 20000 });
  for (const [name, path, action] of pages) {
    const res = await page.goto(BASE + path, { waitUntil: "networkidle" });
    if (action) await action(page);
    await page.waitForTimeout(400);
    const width = await page.evaluate(() => [document.documentElement.scrollWidth, window.innerWidth]);
    await page.screenshot({ path: `${outDir}/${label}-${tag}-${name}.jpg`, type: "jpeg", quality: 72 });
    console.log(`${tag} ${name}: HTTP ${res?.status()} scrollWidth ${width[0]} / viewport ${width[1]}`);
  }
  await context.close();
}
await session({ width: 1366, height: 900 }, "desktop", [["dashboard", "/admin"], ["products", "/admin/commerce/products"], ["leads", "/admin/leads"]]);
await session({ width: 390, height: 844 }, "phone", [
  ["dashboard", "/admin"],
  ["menu", "/admin", async (page) => { const b = page.locator('[aria-label*="menu" i], [aria-label*="navigation" i][type="button"], button[aria-controls*="sidebar" i]').first(); if (await b.count()) await b.click(); }],
]);
await browser.close();
if (problems.length) console.log("PROBLEMS:\n" + [...new Set(problems)].slice(0, 12).join("\n"));

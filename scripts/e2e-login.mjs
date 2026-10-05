#!/usr/bin/env node
/* Browser E2E (QA TODO-6): login → dashboard → sign-out round-trip.
   Needs Playwright browsers (npx playwright install chromium) — skipped gracefully where
   downloads are blocked (e.g. this sandbox); CI runs it for real.
   BASE=e2e target, default http://127.0.0.1:5173 */
const BASE = process.env.BASE ?? "http://127.0.0.1:5173";
let chromium;
try { ({ chromium } = await import("playwright")); }
catch { console.log("SKIP: playwright not resolvable (npm i -D playwright)"); process.exit(0); }
let browser;
try { browser = await chromium.launch(); }
catch (e) { console.log("SKIP: no browser binary — run `npx playwright install chromium` in an env with network. (" + String(e.message).split("\n")[0] + ")"); process.exit(0); }
const fail = (m) => { console.error("E2E FAIL:", m); return browser.close().then(() => process.exit(1)); };
try {
  const page = await browser.newPage();
  const errors = [];
  page.on("pageerror", (e) => errors.push(String(e)));
  page.on("console", (m) => { if (m.type() === "error") errors.push(m.text()); });

  await page.goto(BASE + "/dashboard", { waitUntil: "networkidle" }); // stray path on purpose → must land on Login
  await page.getByPlaceholder("admin").first().fill("admin");
  await page.locator('input[type="password"]').fill("admin");
  await page.getByRole("button", { name: /sign in/i }).click();

  await page.waitForSelector("text=Overview", { timeout: 15000 }).catch(() => {});
  if (await page.locator("text=Screen not found").count()) await fail("landed on 404 card after login");
  if (!/\/$|\/(crm|orders|settings)/.test(new URL(page.url()).pathname)) await fail("url after login unexpected: " + page.url());
  if (errors.length) await fail("console/page errors: " + errors.slice(0, 3).join(" | "));
  if (!(await page.getByText("Admin").first().count())) await fail("topbar user chip missing");

  // sign out (topbar leave icon button)
  const out = page.locator('button[aria-label*="sign out" i], button[title*="sign out" i]').first();
  if (await out.count()) { await out.click(); await page.waitForSelector('input[type="password"]', { timeout: 8000 }).catch(() => {}); }
  if (!(await page.locator('input[type="password"]').count())) await fail("sign-out did not return to login form");
  await page.screenshot({ path: "/tmp/e2e-final.png" });
  console.log("E2E PASS: login → dashboard (no 404) → sign-out round-trip · screenshot /tmp/e2e-final.png");
  await browser.close();
} catch (e) { await fail(e.stack?.split("\n").slice(0, 3).join(" | ")); }

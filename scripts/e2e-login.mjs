#!/usr/bin/env node
/* Browser E2E (QA TODO-6): login → dashboard → sign-out round-trip.
   Needs Playwright Chromium. Local runs skip gracefully if the browser is missing;
   set E2E_REQUIRED=1 in CI to make missing browser dependencies fail the job.
   BASE=e2e target, default http://127.0.0.1:5173 */
const BASE = process.env.BASE ?? "http://127.0.0.1:5173";
const browserRequired = process.env.E2E_REQUIRED === "1";
const unavailable = (message) => {
  console.log(`${browserRequired ? "ERROR" : "SKIP"}: ${message}`);
  process.exit(browserRequired ? 1 : 0);
};
let chromium;
try { ({ chromium } = await import("playwright")); }
catch { unavailable("Playwright is unavailable (npm i -D playwright)."); }
let browser;
try { browser = await chromium.launch((await import("./lib/browser.mjs")).launchOptions()); }
catch (e) { unavailable("no browser binary — run `npx playwright install chromium` in an environment with network. (" + String(e.message).split("\n")[0] + ")"); }
const { signInToDashboard } = await import("./lib/browser.mjs");
const fail = (m) => { console.error("E2E FAIL:", m); return browser.close().then(() => process.exit(1)); };
try {
  const page = await browser.newPage();
  const errors = [];
  page.on("pageerror", (e) => errors.push(String(e)));
  page.on("console", (m) => { if (m.type() === "error") errors.push(m.text()); });

  await page.goto(BASE + "/dashboard", { waitUntil: "networkidle" }); // stray path on purpose → must land on Login
  await signInToDashboard(page);

  await page.waitForSelector("text=Overview", { timeout: 15000 }).catch(() => {});
  if (await page.locator("text=Screen not found").count()) await fail("landed on 404 card after login");
  if (!/\/$|\/(crm|orders|settings)/.test(new URL(page.url()).pathname)) await fail("url after login unexpected: " + page.url());
  if (errors.length) await fail("console/page errors: " + errors.slice(0, 3).join(" | "));
  if (!(await page.getByText("Admin").first().count())) await fail("topbar user chip missing");

  // sign out (topbar leave icon button)
  const out = page.locator('button[aria-label*="sign out" i], button[title*="sign out" i]').first();
  if (await out.count()) { await out.click(); await page.waitForSelector('input[type="password"]', { timeout: 8000 }).catch(() => {}); }
  if (!(await page.locator('input[type="password"]').count())) await fail("sign-out did not return to login form");

  /* The demo account, where it exists, arrives selected: whoever starts to
     type their own e-mail replaces "admin" instead of adding to it. */
  await page.goto(BASE + "/dashboard", { waitUntil: "domcontentloaded" });
  await page.locator('form[aria-busy="false"]').waitFor({ timeout: 8000 }).catch(() => fail("the sign-in form never settled"));
  const userField = page.getByPlaceholder("admin").first();
  if ((await userField.inputValue()) === "admin") {
    const selection = await userField.evaluate((el) => [document.activeElement === el, el.selectionStart, el.selectionEnd]);
    if (!selection[0] || selection[1] !== 0 || selection[2] !== 5) await fail(`the prefilled demo username is not focused and selected (focused ${selection[0]}, selection ${selection[1]}–${selection[2]})`);
    await page.keyboard.type("bilal@woodex.pk");
    const after = await userField.inputValue();
    if (after !== "bilal@woodex.pk") await fail(`typing over the prefilled demo username gave "${after}"`);
  }

  /* An e-mail and a password, typed before the form has heard from the API.
     The form asks whether the demo account exists and fills it in when the
     answer comes; on a slow connection that is after somebody has begun to
     type, and the answer used to replace their e-mail and password with the
     demo ones. The answer is held back here, as a slow connection holds it. */
  let release;
  const held = new Promise((resolve) => { release = resolve; });
  await page.route("**/api/auth/demo-available", async (route) => { await held; await route.continue().catch(() => {}); });
  await page.goto(BASE + "/dashboard", { waitUntil: "domcontentloaded" });
  await page.locator('form[aria-busy="true"]').waitFor({ timeout: 8000 }).catch(() => fail("the sign-in form does not say that it is still waiting for the API (aria-busy)"));
  const typedEmail = "ayesha@woodex.pk";
  const typedPassword = "woodex123";
  await page.getByPlaceholder("admin").first().fill(typedEmail);
  await page.locator('input[type="password"]').fill(typedPassword);
  release();
  await page.locator('form[aria-busy="false"]').waitFor({ timeout: 8000 }).catch(() => fail("the sign-in form never settled after the API answered"));
  const kept = [await page.getByPlaceholder("admin").first().inputValue(), await page.locator('input[type="password"]').inputValue()];
  if (kept[0] !== typedEmail || kept[1] !== typedPassword)
    await fail(`the demo prefill replaced what had been typed: the e-mail field holds "${kept[0]}", the password field ${kept[1] === typedPassword ? "what was typed" : "something else"}`);
  await page.unroute("**/api/auth/demo-available");
  await page.getByRole("button", { name: /^sign in$/i }).click();
  await page.getByRole("link", { name: "Overview", exact: true }).waitFor({ timeout: 15000 }).catch(() => fail(`${typedEmail} could not sign in with a typed e-mail and password`));
  /* Who the session belongs to, asked of the API with the token the page holds:
     names on the screen are not evidence of who is signed in. */
  const me = await page.evaluate(() => fetch("/api/auth/me").then((r) => r.json()).catch(() => null));
  if (me?.user?.email !== typedEmail) await fail(`signed in, but the session belongs to ${me?.user?.email ?? "nobody"}, not to ${typedEmail}`);
  await page.screenshot({ path: "/tmp/e2e-final.png" });
  console.log(`E2E PASS: login → dashboard (no 404) → sign-out round-trip → an e-mail and password typed before the form had settled are kept, and sign in as ${me.user.role} · screenshot /tmp/e2e-final.png`);
  await browser.close();
} catch (e) { await fail(e.stack?.split("\n").slice(0, 3).join(" | ")); }

#!/usr/bin/env node
/**
 * Browser gate: sign in, then open every screen the dashboard has.
 *
 *   BASE=http://127.0.0.1:5173 node scripts/e2e-dashboard-routes.mjs
 *   … --json result.json          machine-readable result
 *   … --only /production,/crm     a subset, while fixing one screen
 *
 * WHY THIS EXISTS (Furniture 2030 Phase 1, finding F-05)
 *
 * Purchase Orders and Production crashed the moment they were opened: both read
 * a list where the API returns { total, items }. Typecheck passed, the build
 * passed, every API test passed and the gate was green, because nothing in the
 * gate ever opened those two screens. The four browser journeys walk four
 * paths; the dashboard has more than forty.
 *
 * So this check takes the route list from the source (apps/dashboard/src/App.tsx)
 * — a screen added tomorrow is covered tomorrow, with no list to remember — and
 * for each one requires:
 *
 *   - no uncaught error in the page;
 *   - no error card ([data-error-boundary]) and no "Screen not found";
 *   - not bounced to the sign-in screen;
 *   - no request to /api that the server answered with an error;
 *   - no error written to the browser console;
 *   - something actually rendered, and the page finished loading.
 *
 * It then proves its own detectors — a check that cannot fail proves nothing:
 * an unknown address must be reported as "Screen not found", and a screen
 * forced to crash must be reported as an error card. That forced crash is also
 * how the error card itself is tested: the sidebar must survive it, choosing
 * another screen must clear it, and "Go to Overview" must leave it.
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { launchBrowser } from "./lib/browser.mjs";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const BASE = (process.env.DASHBOARD_BASE ?? process.env.BASE ?? "http://127.0.0.1:5173").replace(/\/+$/, "");
const ORIGIN = new URL(BASE).origin;
const USER = process.env.E2E_USER ?? "admin";
const PASSWORD = process.env.E2E_PASSWORD ?? "admin";
const arg = (name) => { const i = process.argv.indexOf(name); return i > -1 ? process.argv[i + 1] : null; };
const jsonOut = arg("--json");
const only = arg("--only")?.split(",").map((s) => s.trim()).filter(Boolean);
const SIGN_IN_TEXT = "Sign in — the storefront stays public";

/* ---- the route list, from the source ---- */
const appSource = fs.readFileSync(path.join(ROOT, "apps/dashboard/src/App.tsx"), "utf8");
const declared = [...new Set([...appSource.matchAll(/<Route\s+path="([^"]+)"/g)].map((m) => m[1]))].filter((p) => p !== "*");
if (!/<Route\s+index\s/.test(appSource) || declared.length < 30) {
  console.error(`ERROR: could not read the route list from apps/dashboard/src/App.tsx (found ${declared.length}). This check must be updated with the router.`);
  process.exit(1);
}
const ROUTES = ["/", ...declared];

/* A route with a parameter needs a real value. Each resolver asks the API for
   one; a new parameterised route without a resolver fails the check rather
   than being skipped. */
async function apiSession() {
  const response = await fetch(`${ORIGIN}/api/auth/login`, {
    method: "POST", headers: { "content-type": "application/json" },
    body: JSON.stringify({ [USER.includes("@") ? "email" : "username"]: USER, password: PASSWORD }),
  });
  const body = await response.json().catch(() => ({}));
  if (!response.ok || !body.token) throw new Error(`could not sign in to the API as "${USER}" (HTTP ${response.status})`);
  return body.token;
}
let token = null;
const apiGet = async (url) => {
  token ??= await apiSession();
  const response = await fetch(`${ORIGIN}${url}`, { headers: { authorization: `Bearer ${token}` } });
  if (!response.ok) throw new Error(`${url} → HTTP ${response.status}`);
  return response.json();
};
const firstId = async (url) => {
  const body = await apiGet(url);
  const list = Array.isArray(body) ? body : body.items ?? body.pages ?? body.orders ?? [];
  return list[0]?.id ?? null;
};
const RESOLVERS = {
  "/catalog/:id": async () => { const id = await firstId("/api/products?limit=1"); return id ? `/catalog/${encodeURIComponent(id)}` : null; },
  "/catalog/:id/edit": async () => { const id = await firstId("/api/products?limit=1"); return id ? `/catalog/${encodeURIComponent(id)}/edit` : null; },
  "/orders/:id": async () => { const id = await firstId("/api/orders"); return id ? `/orders/${encodeURIComponent(id)}` : null; },
  "/operations/:stage": async () => "/operations/production",
  "/website/edit/:id": async () => { const id = await firstId("/api/pages"); return id ? `/website/edit/${encodeURIComponent(id)}` : null; },
};

const problems = [];
const results = [];
const browser = await launchBrowser();
let exitCode = 1;
try {
  const context = await browser.newContext({ viewport: { width: 1440, height: 900 } });
  const page = await context.newPage();

  /* ---- what the page does, attributed to the route being opened ---- */
  let current = null;
  let inflight = 0;
  let lastActivity = Date.now();
  const counts = (request) => request.resourceType() !== "eventsource" && request.resourceType() !== "websocket" && !/\/api\/events(\?|$)/.test(request.url());
  page.on("request", (request) => { if (counts(request)) { inflight += 1; lastActivity = Date.now(); } });
  const finished = (request) => { if (counts(request)) { inflight = Math.max(0, inflight - 1); lastActivity = Date.now(); } };
  page.on("requestfinished", finished);
  page.on("requestfailed", finished);
  /* The page editor embeds the storefront in a frame, and screens show images
     from other hosts. An error raised by a script from another origin, or a
     resource another host failed to deliver, is not this application's screen
     failing: it is recorded as a note and does not fail the route. Anything
     with no origin at all is treated as ours. */
  const foreign = (urls) => {
    const origins = urls.map((u) => { try { return new URL(u).origin; } catch { return null; } }).filter(Boolean);
    return origins.length > 0 && origins.every((origin) => origin !== ORIGIN);
  };
  page.on("pageerror", (error) => {
    const text = String(error.message ?? error).split("\n")[0];
    const urls = String(error.stack ?? "").match(/https?:\/\/[^\s)]+/g) ?? [];
    (foreign(urls) ? current?.notes : current?.pageErrors)?.push(foreign(urls) ? `uncaught error in a script from ${new URL(urls[0]).origin}: ${text}` : text);
  });
  page.on("console", (message) => {
    if (message.type() !== "error") return;
    const text = message.text().split("\n")[0].slice(0, 300);
    const source = message.location()?.url ?? "";
    /* "Failed to load resource" repeats a response this check already judges
       by address (below), where it can tell an API error from a missing image. */
    if (/^Failed to load resource/.test(text)) return;
    if (source && foreign([source])) current?.notes.push(`console error from ${new URL(source).origin}: ${text}`);
    else current?.consoleErrors.push(text);
  });
  page.on("response", (response) => {
    if (response.status() < 400) return;
    const url = new URL(response.url());
    const line = `${response.request().method()} ${url.origin === ORIGIN ? "" : url.origin}${url.pathname}${url.search} → ${response.status()}`;
    const fromOurPage = response.frame() === page.mainFrame();
    if (url.origin === ORIGIN && fromOurPage && url.pathname.startsWith("/api/")) current?.httpErrors.push(line);
    else if (url.origin === ORIGIN && fromOurPage) current?.assetErrors.push(line);
    else current?.notes.push(`${fromOurPage ? "another host" : "embedded frame"}: ${line}`);
  });

  /* Finished loading = the shell is on screen and nothing has been requested
     for half a second. The live-update stream stays open by design, so it is
     not counted — which is why this is not Playwright's own "networkidle". */
  const settle = async (timeoutMs = 20_000) => {
    const deadline = Date.now() + timeoutMs;
    try { await page.waitForSelector("main, [data-error-boundary]", { timeout: timeoutMs }); } catch { return false; }
    while (Date.now() < deadline) {
      if (inflight === 0 && Date.now() - lastActivity >= 500) return true;
      await page.waitForTimeout(100);
    }
    return false;
  };

  const open = async (route, target) => {
    const record = { route, path: target, pageErrors: [], consoleErrors: [], httpErrors: [], assetErrors: [], notes: [], faults: [] };
    current = record;
    const started = Date.now();
    await page.goto(BASE + target, { waitUntil: "domcontentloaded", timeout: 30_000 });
    record.settled = await settle();
    record.ms = Date.now() - started;
    record.signIn = (await page.getByText(SIGN_IN_TEXT).count()) > 0;
    record.notFound = (await page.getByText("Screen not found").count()) > 0;
    record.errorCard = (await page.locator("[data-error-boundary]").count()) > 0;
    record.errorText = record.errorCard ? (await page.locator("[data-error-boundary]").first().innerText()).replace(/\s+/g, " ").slice(0, 240) : "";
    record.heading = ((await page.locator("main h1").first().textContent({ timeout: 1000 }).catch(() => "")) ?? "").trim().slice(0, 60);
    record.rendered = ((await page.locator("main").first().innerText({ timeout: 1000 }).catch(() => "")) ?? "").trim().length;
    current = null;
    return record;
  };
  const judge = (record) => {
    const f = record.faults;
    if (record.signIn) f.push("bounced to the sign-in screen");
    if (record.errorCard) f.push(`error card: ${record.errorText}`);
    if (record.notFound) f.push("Screen not found");
    for (const e of record.pageErrors) f.push(`uncaught error: ${e}`);
    for (const e of record.httpErrors) f.push(`API error: ${e}`);
    for (const e of record.assetErrors) f.push(`missing file: ${e}`);
    for (const e of record.consoleErrors) f.push(`console error: ${e}`);
    if (!record.settled) f.push("still loading after 20 s");
    if (!record.signIn && !record.errorCard && record.rendered < 20) f.push("rendered nothing");
    return f.length === 0;
  };

  /* ---- sign in ---- */
  console.log(`\n[routes] ${BASE} — signing in as "${USER}"`);
  current = { pageErrors: [], consoleErrors: [], httpErrors: [], assetErrors: [], notes: [] };
  const signIn = current;
  await page.goto(BASE + "/", { waitUntil: "domcontentloaded", timeout: 30_000 });
  await page.getByText(SIGN_IN_TEXT).waitFor({ timeout: 20_000 });
  await page.getByPlaceholder("admin").first().fill(USER);
  await page.locator('input[type="password"]').first().fill(PASSWORD);
  await page.getByRole("button", { name: /^sign in$/i }).click();
  await page.waitForSelector("main", { timeout: 20_000 });
  await settle();
  current = null;
  if (signIn.pageErrors.length) problems.push(`sign-in screen: uncaught error: ${signIn.pageErrors[0]}`);
  console.log(`  ✓ signed in; the workspace is on screen${signIn.pageErrors.length ? " (with an uncaught error — see below)" : ""}`);

  /* ---- every route ---- */
  const wanted = only?.length ? ROUTES.filter((r) => only.includes(r)) : ROUTES;
  console.log(`\n[routes] opening ${wanted.length} of ${ROUTES.length} routes declared in apps/dashboard/src/App.tsx`);
  for (const route of wanted) {
    let target = route;
    if (route.includes(":")) {
      const resolver = RESOLVERS[route];
      if (!resolver) { problems.push(`${route}: no way to choose a value for its parameter — add a resolver in scripts/e2e-dashboard-routes.mjs`); console.log(`  ✗ ${route} — no resolver for its parameter`); continue; }
      target = await resolver().catch((error) => { problems.push(`${route}: ${error.message}`); return null; });
      if (!target) { problems.push(`${route}: there is no record to open it with`); console.log(`  ✗ ${route} — no record to open it with`); continue; }
    }
    const record = await open(route, target);
    const ok = judge(record);
    results.push(record);
    console.log(`  ${ok ? "✓" : "✗"} ${route.padEnd(24)} ${String(record.ms).padStart(5)} ms  ${record.heading || "(no heading)"}`);
    if (record.notes.length) console.log(`        note: ${record.notes.length} message(s) from an embedded frame or another host — not counted (${[...new Set(record.notes.map((n) => n.split(":")[0]))].join(", ")})`);
    if (!ok) {
      for (const fault of record.faults) { console.log(`        ${fault}`); problems.push(`${route}: ${fault}`); }
      if (process.env.E2E_ARTIFACTS) {
        fs.mkdirSync(process.env.E2E_ARTIFACTS, { recursive: true });
        await page.screenshot({ path: path.join(process.env.E2E_ARTIFACTS, `route${route.replace(/[^a-z0-9]+/gi, "-")}.png`) }).catch(() => {});
      }
    }
  }

  /* ---- the detectors must be able to fail ---- */
  console.log("\n[routes] the check can tell a broken screen from a working one");
  /* React re-renders a tick after a click; "is the card gone?" has to wait for
     the answer rather than sample the instant after. */
  const cardGone = () => page.waitForFunction(() => !document.querySelector("[data-error-boundary]"), null, { timeout: 8000 }).then(() => true, () => false);
  const expect = (ok, label, detail = "") => {
    console.log(`  ${ok ? "✓" : "✗"} ${label}${ok || !detail ? "" : ` — ${detail}`}`);
    if (!ok) problems.push(`${label}${detail ? ` — ${detail}` : ""}`);
  };
  const unknown = await open("(unknown address)", "/no-such-screen-for-the-gate");
  expect(unknown.notFound && !unknown.errorCard, 'an unknown address is reported as "Screen not found"');

  /* Force one screen to throw while rendering: answer its product request with
     something that is not a list. */
  const breakProducts = (route) => route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ total: 1, items: { not: "a list" } }) });
  await page.route("**/api/products**", breakProducts);
  const crashed = await open("(forced crash)", "/production");
  expect(crashed.errorCard, "a screen that throws is reported as an error card", `faults seen: ${JSON.stringify({ pageErrors: crashed.pageErrors, rendered: crashed.rendered })}`);
  expect((await page.locator("aside a, nav a").count()) > 5, "the sidebar is still there beside the error card");
  await page.unroute("**/api/products**", breakProducts);

  console.log("\n[routes] the error card can be left");
  const elsewhere = page.locator('aside a[href$="/clients"], nav a[href$="/clients"]').first();
  expect((await elsewhere.count()) > 0, "there is another screen to choose in the sidebar");
  if (await elsewhere.count()) {
    await elsewhere.click();
    const gone = await cardGone();
    await settle();
    expect(gone && new URL(page.url()).pathname.endsWith("/clients") && (await page.locator("main h1").count()) > 0,
      "choosing another screen clears the card and shows that screen", `now at ${page.url()}`);
  }
  await page.route("**/api/products**", breakProducts);
  await open("(forced crash)", "/production");
  await page.unroute("**/api/products**", breakProducts);
  const home = page.locator("[data-error-boundary] a", { hasText: /overview/i }).first();
  expect((await home.count()) > 0, 'the card offers "Go to Overview"');
  if (await home.count()) {
    await Promise.all([page.waitForNavigation({ waitUntil: "domcontentloaded", timeout: 20_000 }), home.click()]);
    await settle();
    const at = new URL(page.url()).pathname.replace(/\/+$/, "");
    expect((await page.locator("[data-error-boundary]").count()) === 0 && at === new URL(BASE + "/").pathname.replace(/\/+$/, "")
      && ((await page.locator("main").first().innerText().catch(() => "")) ?? "").trim().length > 20, '"Go to Overview" opens Overview, without the card', `now at ${page.url()}`);
  }
  await page.route("**/api/products**", breakProducts);
  await open("(forced crash)", "/production");
  await page.unroute("**/api/products**", breakProducts);
  const retry = page.locator("[data-error-boundary] button", { hasText: /try again/i }).first();
  if (await retry.count()) {
    await retry.click();
    const recovered = await cardGone();
    await settle();
    expect(recovered && (await page.locator("[data-error-boundary]").count()) === 0 && (await page.locator("main h1").count()) > 0,
      '"Try again" brings the screen back once the cause is gone');
  } else expect(false, 'the card offers "Try again"');

  exitCode = problems.length ? 1 : 0;
} catch (error) {
  problems.push(`check crashed: ${error instanceof Error ? error.message.split("\n")[0] : String(error)}`);
  console.error(`\n✗ ${error instanceof Error ? error.stack?.split("\n").slice(0, 4).join("\n") : error}`);
  exitCode = 1;
} finally {
  await browser.close().catch(() => {});
}

const opened = results.length;
const clean = results.filter((r) => r.faults.length === 0).length;
if (jsonOut) {
  fs.writeFileSync(jsonOut, JSON.stringify({ ranAt: new Date().toISOString(), base: BASE, declared: ROUTES.length, opened, clean, ok: exitCode === 0, problems, results }, null, 1) + "\n");
}
if (exitCode === 0) {
  console.log(`\nE2E PASS: ${clean}/${opened} dashboard routes open without an error (${ROUTES.length} declared), and the error card can be left.\n`);
} else {
  console.error(`\nE2E FAIL: ${problems.length} problem(s) — ${clean}/${opened} routes clean:\n`);
  for (const p of problems) console.error(`  • ${p}`);
}
process.exit(exitCode);

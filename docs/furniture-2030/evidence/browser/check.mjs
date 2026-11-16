// Real-browser check of the running preview, loaded the way the Arena preview loads it (HTTPS, "<port>-<id>.e2b.app" host, HMR over wss:443).
//  A) dashboard inside a cross-site iframe with site storage blocked   B) dashboard direct: sign in, then every menu route loaded fresh   C) storefront home + shop
import { spawn } from "node:child_process"; import fs from "node:fs";
const chromium = (await import("@sparticuz/chromium")).default; const puppeteer = (await import("puppeteer-core")).default;
const here = new URL(".", import.meta.url).pathname; const ID = process.env.E2B_SANDBOX_ID || "simcheck";
const proxy = spawn(process.execPath, [here + "sim-proxy.mjs"], { stdio: ["ignore", "pipe", "inherit"] }); await new Promise((r) => proxy.stdout.once("data", r));
const DASH = `https://3001-${ID}.e2b.app`, SHOP = `https://5174-${ID}.e2b.app`; let browser; const out = { dashboardAddress: DASH };
const U = 'input[autocomplete="username"]', P = 'input[type="password"]';
const watch = (page) => { const log = { errs: [], bad: [], failed: [], vite: [] };
  page.on("console", (m) => { const t = m.text(); if (/\[vite\]/.test(t)) log.vite.push(t.slice(0, 60)); if (m.type() === "error") log.errs.push(t.replace(/\s+/g, " ").slice(0, 220)); });
  page.on("pageerror", (e) => log.errs.push("pageerror: " + String(e?.message || e).slice(0, 200)));
  page.on("requestfailed", (r) => { const f = r.failure()?.errorText || ""; if (!/ERR_ABORTED/.test(f)) log.failed.push(`${r.url().slice(0, 90)} — ${f}`); });
  page.on("response", (r) => { if (r.status() >= 400) log.bad.push(`${r.status()} ${r.request().method()} ${r.url().replace(/^https:\/\/[^/]+/, "").slice(0, 70)}`); });
  return log; };
const state = (f) => f.evaluate(() => { const t = (document.body.innerText || "").replace(/\s+/g, " ").trim(); const m = /This screen hit an error[^.]*\.([\s\S]{0,300}?)(?=Try again)/.exec(t);
  return { path: location.pathname, title: document.title, secure: window.isSecureContext, signInScreen: /Sign in — the storefront stays public/.test(t), errorScreen: /This screen hit an error/.test(t),
    errorText: m ? m[1].replace(/The rest of the workspace is still fine — go back to Overview, or reload to try again\./, "").trim().slice(0, 150) : "", mainLen: ((document.querySelector("main")?.innerText) || t).trim().length, head: t.slice(0, 110) }; });
const signIn = async (f, page) => { await f.waitForSelector(U, { timeout: 30000 });
  await f.waitForFunction((U) => document.querySelector(U)?.value !== "", { timeout: 10000 }, U).catch(() => {});       // demo login is pre-filled after /api/auth/demo-available answers
  const pre = await f.evaluate((U, P) => ({ u: document.querySelector(U).value, p: document.querySelector(P).value }), U, P);
  const resp = page.waitForResponse((r) => r.url().includes("/api/auth/login"), { timeout: 30000 });
  await f.click('button[type="submit"]'); const r = await resp;
  await f.waitForFunction(() => !/Sign in — the storefront stays public/.test(document.body.innerText), { timeout: 30000 }).catch(() => {});
  await page.waitForNetworkIdle({ idleTime: 700, timeout: 30000 }).catch(() => {});
  return { demoLoginPrefilled: pre.u === "admin" && pre.p === "admin", loginHttp: r.status() }; };
try {
  browser = await puppeteer.launch({ executablePath: await chromium.executablePath(), headless: "shell", defaultViewport: { width: 1320, height: 900 },
    args: ["--no-sandbox", "--disable-setuid-sandbox", "--disable-dev-shm-usage", "--use-gl=angle", "--use-angle=swiftshader", "--enable-unsafe-swiftshader", "--font-render-hinting=none",
      "--host-resolver-rules=MAP *.e2b.app 127.0.0.1:8443, MAP arena-parent.test 127.0.0.1:8443", "--ignore-certificate-errors"] });
  await (await browser.newPage()).goto("about:blank"); out.browser = await browser.version();

  { // A) embedded cross-site, storage blocked
    const page = await browser.newPage(); const log = watch(page);
    await page.evaluateOnNewDocument(() => { if (location.hostname.endsWith(".e2b.app")) for (const k of ["localStorage", "sessionStorage"]) Object.defineProperty(window, k, { configurable: true, get() { throw new DOMException("Access is denied for this document.", "SecurityError"); } }); });
    await page.goto("https://arena-parent.test/?src=" + encodeURIComponent(DASH + "/"), { waitUntil: "networkidle2", timeout: 120000 });
    const frame = page.frames().find((f) => f.url().startsWith(DASH)); const A = { frameFound: !!frame };
    A.storageBlocked = await frame.evaluate(() => { try { window.localStorage.getItem("x"); return false; } catch { return true; } });
    A.before = await state(frame);
    A.signIn = await signIn(frame, page); A.after = await state(frame); await page.screenshot({ path: here + "shots/A-embedded-overview.png" });
    A.menuLinks = await frame.evaluate(() => document.querySelectorAll("nav a[href], aside a[href]").length);
    A.liveReloadConnected = log.vite.some((v) => /connected/.test(v)); A.consoleErrors = log.errs; A.http4xx5xx = log.bad; A.failedRequests = log.failed; out.A = A; }

  { // B) direct: sign in, then each menu route loaded fresh
    const page = await browser.newPage(); const log = watch(page); const B = {};
    const resp = await page.goto(DASH + "/", { waitUntil: "networkidle2", timeout: 120000 }); B.http = resp.status();
    B.signIn = await signIn(page, page); B.overview = await state(page);
    const links = await page.evaluate(() => [...new Map([...document.querySelectorAll("a[href]")].map((a) => [a.getAttribute("href"), (a.innerText || "").replace(/\s+/g, " ").trim().slice(0, 34)])).entries()]);
    const routes = links.filter(([h]) => h && h.startsWith("/") && !h.startsWith("//")); const rows = [];
    for (const [h, label] of routes) { log.errs.length = 0; log.bad.length = 0; let status = "ok", note = "";
      try { await page.goto(DASH + h, { waitUntil: "networkidle2", timeout: 60000 }); } catch (e) { status = "LOAD-TIMEOUT"; note = String(e.message).slice(0, 80); }
      const s = await state(page);
      if (s.errorScreen) { status = "ERROR-SCREEN"; note = s.errorText; } else if (s.signInScreen) status = "SIGNED-OUT"; else if (s.mainLen < 40 && status === "ok") status = "EMPTY";
      rows.push({ route: h, label, status, note, consoleErr: (log.errs.find((e) => /TypeError|ReferenceError|render failed|is not a function|Cannot read|pageerror/.test(e)) || "").slice(0, 130), http: log.bad.slice(0, 2) }); }
    B.rows = rows; await page.goto(DASH + "/", { waitUntil: "networkidle2" }); B.stillSignedInAfterReload = !(await state(page)).signInScreen;
    out.B = B; }

  { // C) storefront
    const page = await browser.newPage(); const log = watch(page); const C = {};
    const resp = await page.goto(SHOP + "/", { waitUntil: "networkidle2", timeout: 120000 }); C.http = resp.status(); C.home = await state(page);
    await page.goto(SHOP + "/shop", { waitUntil: "networkidle2", timeout: 120000 }); C.shop = await state(page); C.productLinks = await page.evaluate(() => new Set([...document.querySelectorAll('a[href^="/shop/"]')].map((a) => a.getAttribute("href"))).size);
    C.liveReloadConnected = log.vite.some((v) => /connected/.test(v)); C.consoleErrors = log.errs.filter((e) => !/ERR_CONNECTION_CLOSED|Failed to load resource/.test(e)); C.failedNonFont = log.failed.filter((f) => !/fonts\.(googleapis|gstatic)\.com/.test(f)); C.failedFontRequests = log.failed.length - C.failedNonFont.length; C.http4xx5xx = log.bad; out.C = C; }
} catch (e) { out.fatal = String(e?.stack || e).slice(0, 900); }
finally { try { await browser?.close(); } catch {} proxy.kill(); }
fs.writeFileSync(here + "check-result.json", JSON.stringify(out, null, 1));
const rows = out.B?.rows || []; const by = {}; for (const r of rows) (by[r.status] ||= []).push(r);
const brief = (s) => s && `${s.path} "${s.title}" signIn=${s.signInScreen} error=${s.errorScreen} text=${s.mainLen}`;
console.log("browser:", out.browser, "| address used:", out.dashboardAddress); if (out.fatal) console.log("FATAL:", out.fatal);
const A = out.A || {}; console.log(`\nA) embedded cross-site, storage blocked: frame=${A.frameFound} storageBlocked=${A.storageBlocked}\n   before: ${brief(A.before)}\n   signIn: ${JSON.stringify(A.signIn)}\n   after:  ${brief(A.after)} | menu links ${A.menuLinks} | live reload ${A.liveReloadConnected}\n   console errors ${JSON.stringify(A.consoleErrors)} | http errors ${JSON.stringify(A.http4xx5xx)} | failed ${JSON.stringify(A.failedRequests)}`);
const B = out.B || {}; console.log(`\nB) direct: http ${B.http} | signIn ${JSON.stringify(B.signIn)} | overview: ${brief(B.overview)} | still signed in after reload: ${B.stillSignedInAfterReload}`);
console.log(`   routes checked: ${rows.length} | ` + Object.entries(by).map(([k, v]) => `${k}: ${v.length}`).join(" | "));
for (const r of rows.filter((r) => r.status !== "ok")) console.log(`   ✗ ${r.route.padEnd(24)} ${r.status.padEnd(13)} [${r.label}] ${r.note || r.consoleErr} ${r.http.join("; ")}`);
for (const r of rows.filter((r) => r.status === "ok" && (r.consoleErr || r.http.length))) console.log(`   ! ${r.route.padEnd(24)} rendered, but: ${r.consoleErr} ${r.http.join("; ")}`);
const C = out.C || {}; console.log(`\nC) storefront: http ${C.http} | home: ${brief(C.home)} | shop: ${brief(C.shop)} | product links ${C.productLinks} | live reload ${C.liveReloadConnected}\n   console errors ${JSON.stringify(C.consoleErrors)} | non-font failed ${JSON.stringify(C.failedNonFont)} | font requests failed (no internet in sandbox) ${C.failedFontRequests} | http errors ${JSON.stringify(C.http4xx5xx)}`);

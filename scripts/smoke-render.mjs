#!/usr/bin/env node
/**
 * Render smoke test — proves an app's React tree actually mounts.
 *
 * A green HTTP 200 from Vite means nothing: if the bundle throws during the
 * first render (duplicate React copies, bad provider, missing shim) the page
 * still returns index.html and the user gets a blank white screen. This script
 * bundles the real entry point with esbuild, evaluates it against a jsdom
 * window, and reports how many children landed in the mount node.
 *
 * Usage:
 *   node scripts/smoke-render.mjs              # both apps
 *   node scripts/smoke-render.mjs storefront   # just one
 *   node scripts/smoke-render.mjs dashboard
 *
 * Exits non-zero when an app mounts nothing or logs an error, so it can gate CI.
 */
import { JSDOM, VirtualConsole } from "jsdom";
import { build } from "esbuild";
import path from "node:path";
import { fileURLToPath } from "node:url";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");

const APPS = {
  storefront: { dir: "apps/storefront", mount: "root", url: "http://127.0.0.1:5174/" },
  dashboard: { dir: "apps/dashboard", mount: "root", url: "http://127.0.0.1:5173/" },
};

async function render(name, app) {
  const src = path.join(ROOT, app.dir, "src");
  const errs = [];
  const vc = new VirtualConsole();
  vc.on("jsdomError", (e) => {
    // Font CDN / network fetches are expected to fail offline; ignore them.
    const msg = e.stack ?? e.message ?? String(e);
    if (/Could not load link|Could not load img|resource-errors/.test(msg)) return;
    errs.push("[jsdom] " + msg);
  });
  vc.on("error", (...a) => errs.push("[console.error] " + a.join(" ")));

  const dom = new JSDOM(
    `<!doctype html><html><head></head><body><div id="${app.mount}"></div></body></html>`,
    { url: app.url, pretendToBeVisual: true, virtualConsole: vc },
  );
  const w = dom.window;

  // Browser APIs jsdom lacks, or that the apps touch at boot.
  const noop = () => {};
  w.matchMedia =
    w.matchMedia ||
    (() => ({ matches: false, addEventListener: noop, removeEventListener: noop, addListener: noop, removeListener: noop }));
  w.fetch = globalThis.fetch;
  w.scrollTo = noop;

  const globals = {
    window: w,
    document: w.document,
    location: w.location,
    history: w.history,
    localStorage: w.localStorage,
    sessionStorage: w.sessionStorage,
    HTMLElement: w.HTMLElement,
    Element: w.Element,
    Node: w.Node,
    CustomEvent: w.CustomEvent,
    Event: w.Event,
    getComputedStyle: w.getComputedStyle,
    matchMedia: w.matchMedia,
    requestAnimationFrame: (cb) => setTimeout(() => cb(Date.now()), 16),
    cancelAnimationFrame: clearTimeout,
    ResizeObserver: class { observe() {} unobserve() {} disconnect() {} },
    IntersectionObserver: class { observe() {} unobserve() {} disconnect() {} takeRecords() { return []; } },
    scrollTo: noop,
  };
  for (const [k, v] of Object.entries(globals)) {
    try {
      w[k] = v;
    } catch {
      /* window.window and friends are getter-only */
    }
    try {
      Object.defineProperty(globalThis, k, { value: v, configurable: true, writable: true });
    } catch {
      /* some Node 22 globals are getter-only */
    }
  }

  const result = await build({
    entryPoints: [path.join(src, "main.tsx")],
    bundle: true,
    write: false,
    format: "iife",
    platform: "browser",
    jsx: "automatic",
    loader: { ".jpg": "text", ".png": "text", ".svg": "text", ".css": "empty" },
    alias: { "@": src },
    define: {
      "process.env.NODE_ENV": '"development"',
      // Vite-only global; esbuild leaves import.meta.env undefined without this.
      "import.meta.env": JSON.stringify({ MODE: "development", DEV: true, PROD: false, BASE_URL: "/" }),
    },
    absWorkingDir: path.join(ROOT, app.dir),
    logLevel: "silent",
  });

  try {
    w.eval(result.outputFiles[0].text);
  } catch (e) {
    errs.unshift("[top-level throw] " + (e.stack ?? e.message));
  }

  await new Promise((r) => setTimeout(r, 2000));
  const el = w.document.getElementById(app.mount);
  const children = el ? el.children.length : 0;
  const length = el ? el.innerHTML.length : 0;
  const ok = children > 0 && errs.length === 0;

  console.log(
    `${ok ? "PASS" : "FAIL"}  ${name.padEnd(10)} children=${children} html=${length}B errors=${errs.length}`,
  );
  if (errs.length) console.log("      " + errs.slice(0, 6).join("\n      "));
  return ok;
}

const only = process.argv[2];
const targets = Object.entries(APPS).filter(([n]) => !only || n === only);
if (!targets.length) {
  console.error(`Unknown app "${only}". Choose one of: ${Object.keys(APPS).join(", ")}`);
  process.exit(2);
}

let failed = 0;
for (const [name, app] of targets) {
  if (!(await render(name, app))) failed++;
}
console.log(failed ? `\n${failed} app(s) failed to mount` : "\nAll apps mounted");
process.exit(failed ? 1 : 0);

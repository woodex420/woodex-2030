#!/usr/bin/env node
/**
 * Static prerender for the public storefront routes.
 *
 * Why this exists: the storefront is a client-rendered SPA. A crawler that does
 * not execute JavaScript sees `<div id="root"></div>` and nothing else — no
 * heading, no product, no meta description tied to the page. For a furniture
 * retailer whose traffic starts on Google, that is a revenue bug.
 *
 * What this does: after `vite build`, it renders each public route with esbuild
 * + jsdom (the same harness as `npm run smoke:render`), then writes the resulting
 * HTML into `dist/<route>/index.html` with route-specific title, description,
 * canonical, OG tags and JSON-LD. The app still hydrates as a normal SPA — the
 * markup is a fast first paint and crawler fodder, not a second renderer.
 *
 * Usage:  node scripts/prerender.mjs [--dist dist] [--api http://127.0.0.1:3001]
 * Runs automatically as part of `npm run vercel:build`.
 */
import { JSDOM, VirtualConsole } from "jsdom";
import { build } from "esbuild";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { PUBLIC_ROUTES } from "../apps/api/public-routes.mjs";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const args = process.argv.slice(2);
const argOf = (flag, fallback) => {
  const i = args.indexOf(flag);
  return i >= 0 && args[i + 1] ? args[i + 1] : fallback;
};

const DIST = path.resolve(ROOT, argOf("--dist", "dist"));
const SRC = path.join(ROOT, "apps/storefront/src");
/* Brand facts come from the app's own config so a rename is one edit there.
   Bundled with esbuild exactly like the product catalogue below: the file is
   TypeScript with no imports, so it can be evaluated in plain Node. */
async function loadBrand() {
  const out = await build({
    entryPoints: [path.join(SRC, "config/brand.ts")],
    bundle: true, write: false, format: "esm", platform: "node", logLevel: "silent",
  });
  const tmp = path.join(ROOT, ".prerender-brand.mjs");
  fs.writeFileSync(tmp, out.outputFiles[0].text);
  const mod = await import(tmp + "?t=" + Date.now());
  fs.unlinkSync(tmp);
  return mod.brand;
}
const BRAND = await loadBrand();
/* Canonical origin baked into every prerendered page's canonical, OG and
   JSON-LD tags. It defaults to the brand's production URL (woodex.pk), which is
   correct for the real site — but that domain is not attached to the Vercel
   deployment, and only woodex-2030.vercel.app is. SITE_URL lets the deployed
   origin be stated instead of assumed, so the tags describe the site actually
   serving them. Prerendering is static, so this is captured at build time. */
const ORIGIN = String(process.env.SITE_URL || BRAND.url).replace(/\/+$/, "");
if (!/^https?:\/\//.test(ORIGIN)) throw new Error(`SITE_URL must be an absolute http(s) URL, got "${ORIGIN}"`);

/* ---------- route table ----------
   Static routes plus their per-route metadata. Dynamic product/series/blog
   pages are enumerated from the catalogue below. */
/* Shared with the API, which builds /sitemap.xml from the same list, so a
   public page cannot exist in one place and be missing from the other. */
const STATIC_ROUTES = PUBLIC_ROUTES;

/* ---------- runtime shims (shared with smoke-render) ---------- */
function makeWindow(url, errs) {
  const vc = new VirtualConsole();
  vc.on("jsdomError", (e) => {
    const m = e.stack ?? e.message ?? String(e);
    if (/Could not load (link|img)|resource-errors/.test(m)) return;
    errs.push(m.split("\n")[0]);
  });
  vc.on("error", (...a) => errs.push("[console.error] " + a.join(" ").slice(0, 200)));
  const dom = new JSDOM(`<!doctype html><html><head></head><body><div id="root"></div></body></html>`, {
    url,
    pretendToBeVisual: true,
    virtualConsole: vc,
  });
  const w = dom.window;
  const noop = () => {};
  w.matchMedia = w.matchMedia || (() => ({ matches: false, addEventListener: noop, removeEventListener: noop, addListener: noop, removeListener: noop }));
  w.scrollTo = noop;
  // Every API call during prerender is answered with an empty payload: the goal
  // is a stable shell, not a database snapshot that would go stale in dist/.
  w.fetch = async () => ({ ok: false, status: 503, json: async () => ({}), text: async () => "" });
  /* React (and Radix) reach for constructor globals at render time. Missing
     ones surface as "ReferenceError: HTMLFormElement is not defined". */
  const DOM_GLOBALS = [
    "HTMLElement", "HTMLFormElement", "HTMLInputElement", "HTMLButtonElement", "HTMLSelectElement",
    "HTMLTextAreaElement", "HTMLAnchorElement", "HTMLImageElement", "HTMLDivElement",
    "HTMLStyleElement", "HTMLScriptElement", "HTMLCanvasElement", "HTMLIFrameElement",
    "SVGElement", "Element", "Node", "DocumentFragment", "DOMParser", "MutationObserver",
    "MouseEvent", "KeyboardEvent", "FocusEvent", "InputEvent", "PointerEvent", "CSS",
    "getComputedStyle", "getSelection", "innerWidth", "innerHeight", "scrollY", "scrollX",
  ];
  const g = {
    window: w, document: w.document, location: w.location, history: w.history,
    localStorage: w.localStorage, sessionStorage: w.sessionStorage,
    CustomEvent: w.CustomEvent, Event: w.Event, getComputedStyle: w.getComputedStyle,
    matchMedia: w.matchMedia,
    requestAnimationFrame: (cb) => setTimeout(() => cb(Date.now()), 0),
    cancelAnimationFrame: clearTimeout,
    ResizeObserver: class { observe() {} unobserve() {} disconnect() {} },
    IntersectionObserver: class { observe() {} unobserve() {} disconnect() {} takeRecords() { return []; } },
    scrollTo: noop, fetch: w.fetch,
    EventSource: class { constructor() { this.readyState = 0; } addEventListener() {} close() {} },
  };
  for (const name of DOM_GLOBALS) if (w[name] !== undefined) g[name] = w[name];
  for (const [k, v] of Object.entries(g)) {
    try { w[k] = v; } catch { /* getter-only */ }
    try { Object.defineProperty(globalThis, k, { value: v, configurable: true, writable: true }); } catch { /* getter-only */ }
  }
  Object.defineProperty(globalThis, "addEventListener", { value: (...a) => w.addEventListener(...a), configurable: true, writable: true });
  Object.defineProperty(globalThis, "removeEventListener", { value: (...a) => w.removeEventListener(...a), configurable: true, writable: true });
  return w;
}

/* ---------- one-time bundle ---------- */
async function bundle() {
  const result = await build({
    entryPoints: [path.join(SRC, "main.tsx")],
    bundle: true, write: false, format: "iife", platform: "browser", jsx: "automatic",
    loader: { ".jpg": "text", ".png": "text", ".svg": "text", ".css": "empty" },
    alias: { "@": SRC },
    define: {
      "process.env.NODE_ENV": '"production"',
      "import.meta.env": JSON.stringify({ MODE: "production", DEV: false, PROD: true, BASE_URL: "/" }),
    },
    minify: false,
    /* The app lazy-loads every route. Inside a single `w.eval` there is no
       module loader to resolve those dynamic imports, so each route would stall
       at its Suspense fallback and prerender as an empty shell. Inlining every
       chunk makes the whole tree synchronous and the routes resolve. */
    splitting: false,
    /* React.lazy cannot resolve inside jsdom: the dynamic import returns a
       promise that never settles (no module loader), so every route prerenders
       as its "Loading page…" fallback. Rewrite `lazy(() => import("x"))` into a
       static import so the tree is synchronous at eval time. */
    plugins: [{
      name: "prerender-eager-lazy",
      setup(b) {
        b.onLoad({ filter: /App\.tsx$/ }, async (a) => {
          const fs = await import("node:fs/promises");
          const src = await fs.readFile(a.path, "utf8");
          const m = [...src.matchAll(/const\s+(\w+)\s*=\s*lazy\(\s*\(\)\s*=>\s*import\((["'][^"']+["'])\)\s*\)/g)];
          if (!m.length) return null;
          let out = m.map(([, n, sp], i) => `import __l_${i} from ${sp};`).join("\n") + "\n" + src;
          m.forEach(([full, n], i) => { out = out.replace(full, `const ${n} = __l_${i};`); });
          return { contents: out, loader: "tsx", resolveDir: path.dirname(a.path) };
        });
      },
    }],
    logLevel: "silent",
  });
  return result.outputFiles[0].text;
}

function esc(s) {
  return String(s).replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;");
}

/** Structured data shared by every page. */
function siteSchema() {
  return [
    {
      "@context": "https://schema.org", "@type": "Organization",
      name: BRAND.legalName, url: ORIGIN + "/", logo: ORIGIN + "/img/woodex-logo.png",
      contactPoint: [{ "@type": "ContactPoint", telephone: BRAND.phone, contactType: "sales", areaServed: BRAND.address.country, availableLanguage: ["en", "ur"] }],
      sameAs: [`https://instagram.com/${BRAND.social.instagram}`, `https://facebook.com/${BRAND.social.facebook}`],
    },
    {
      "@context": "https://schema.org", "@type": "WebSite",
      name: BRAND.name, url: ORIGIN + "/",
      potentialAction: { "@type": "SearchAction", target: { "@type": "EntryPoint", urlTemplate: ORIGIN + "/shop?q={search_term_string}" }, "query-input": "required name=search_term_string" },
    },
    {
      "@context": "https://schema.org", "@type": "FurnitureStore",
      name: BRAND.name, url: ORIGIN + "/", telephone: BRAND.phone, email: BRAND.email,
      priceRange: BRAND.currency, currenciesAccepted: BRAND.currency, paymentAccepted: "Cash, Bank Transfer, Card",
      address: { "@type": "PostalAddress", streetAddress: BRAND.address.street, addressLocality: BRAND.address.city, addressRegion: BRAND.address.region, postalCode: BRAND.address.postalCode, addressCountry: BRAND.address.country },
      openingHoursSpecification: [{ "@type": "OpeningHoursSpecification", dayOfWeek: BRAND.hoursRange.days, opens: BRAND.hoursRange.opens, closes: BRAND.hoursRange.closes }],
    },
  ];
}

function breadcrumbSchema(route) {
  if (route === "/") return null;
  const label = route.replace(/^\//, "").replace(/-/g, " ").replace(/\//g, " / ");
  return {
    "@context": "https://schema.org", "@type": "BreadcrumbList",
    itemListElement: [
      { "@type": "ListItem", position: 1, name: "Home", item: ORIGIN + "/" },
      { "@type": "ListItem", position: 2, name: label.replace(/\b\w/g, (c) => c.toUpperCase()), item: ORIGIN + route },
    ],
  };
}

/** Replace the head block for this route. */
function headFor(route, title, desc, extraSchema = []) {
  const canonical = ORIGIN + (route === "/" ? "/" : route);
  const fullTitle = title.includes(BRAND.name) ? title : `${title} | ${BRAND.name}`;
  const schemas = [...siteSchema(), ...(breadcrumbSchema(route) ? [breadcrumbSchema(route)] : []), ...extraSchema];
  const ld = schemas.map((s) => `<script type="application/ld+json">${JSON.stringify(s)}</script>`).join("\n    ");
  return `    <title>${esc(fullTitle)}</title>
    <meta name="description" content="${esc(desc)}" />
    <meta name="author" content="${esc(BRAND.legalName)}" />
    <link rel="canonical" href="${canonical}" />
    <meta property="og:title" content="${esc(fullTitle)}" />
    <meta property="og:description" content="${esc(desc)}" />
    <meta property="og:type" content="website" />
    <meta property="og:url" content="${canonical}" />
    <meta property="og:image" content="${ORIGIN}${BRAND.ogImage}" />
    <meta property="og:site_name" content="${esc(BRAND.name)}" />
    <meta property="og:locale" content="${BRAND.locale.replace("-", "_")}" />
    <meta name="twitter:card" content="summary_large_image" />
    <meta name="twitter:site" content="@${BRAND.name}" />
    <meta name="twitter:title" content="${esc(fullTitle)}" />
    <meta name="twitter:image" content="${ORIGIN}${BRAND.ogImage}" />
    <meta name="theme-color" content="${BRAND.themeColor}" />
    ${ld}`;
}

async function main() {
  const shellPath = path.join(DIST, "index.html");
  if (!fs.existsSync(shellPath)) {
    console.error("[prerender] dist/index.html missing — run `npm run vercel:build` first (or vite build).");
    process.exit(1);
  }
  const shell = fs.readFileSync(shellPath, "utf8");
  const code = await bundle();

  // Product, series and blog routes come from the built catalogue module.
  let catalogue = { products: [], series: [], posts: [] };
  try {
    const dataBundle = await build({
      entryPoints: [path.join(SRC, "data/products.ts")],
      bundle: true, write: false, format: "esm", platform: "node", logLevel: "silent",
      loader: { ".jpg": "text", ".png": "text", ".svg": "text" },
    });
    const tmp = path.join(ROOT, ".prerender-products.mjs");
    fs.writeFileSync(tmp, dataBundle.outputFiles[0].text);
    const mod = await import(tmp + "?t=" + Date.now());
    catalogue.products = (mod.products ?? []).slice(0, 60);
    fs.unlinkSync(tmp);
  } catch (e) {
    console.warn("[prerender] product catalogue unavailable:", e.message);
  }

  let targets = [...STATIC_ROUTES];
  for (const p of catalogue.products) {
    if (!p?.id) continue;
    targets.push({
      path: `/shop/${p.id}`,
      title: `${p.name} — ${p.category ?? "Office Furniture"}`,
      desc: String(p.description ?? `${p.name} by WOODEX.`).slice(0, 158),
      priority: "0.7", changefreq: "weekly",
      productSchema: true,
      product: p,
    });
  }

  /* PRERENDER_ONLY=/about,/shop narrows a run for debugging. Never set in CI:
     a partial run rewrites sitemap.xml with only the routes it rendered. */
  if (process.env.PRERENDER_ONLY) {
    const want = new Set(process.env.PRERENDER_ONLY.split(",").map((x) => x.trim()).filter(Boolean));
    targets = targets.filter((t) => want.has(t.path));
    console.log(`[prerender] PRERENDER_ONLY → ${targets.length} route(s)`);
  }

  let ok = 0;
  const written = [];
  const t0 = Date.now();
  for (const route of targets) {
    const url = ORIGIN + route.path;
    const before = new Set();
    for (const s of siteSchema()) before.add(JSON.stringify(s));

    const errs = [];
    const w = makeWindow(url, errs);
    try {
      w.eval(code);
    } catch (e) {
      console.warn(`[prerender] ${route.path}: render threw — ${String(e.message).slice(0, 200)}`);
      continue;
    }
    /* React commits asynchronously. Poll for the *route*, not merely for any
       child: the toast portal mounts immediately, so `children.length > 0` was
       satisfied while the tree was still on its Suspense fallback and every
       page prerendered as "Loading page…". Wait until there is more than one
       child AND the fallback text is gone. */
    for (let i = 0; i < 200; i++) {
      const el = w.document.getElementById("root");
      const txt = el ? el.textContent : "";
      if (el && el.children.length > 1 && !/Loading page/.test(txt)) break;
      await new Promise((r) => setTimeout(r, 20));
    }

    const root = w.document.getElementById("root");
    const appHtml = root ? root.innerHTML : "";
    if (!appHtml && written.length === 0) {
      console.warn(`[prerender] ${route.path}: empty root after wait; errors=${errs.length}`);
      errs.slice(0, 3).forEach((e) => console.warn("   ", e.slice(0, 240)));
    }
    w.close();

    const extra = [];
    if (route.productSchema && route.product) {
      extra.push({
        "@context": "https://schema.org", "@type": "Product",
        name: route.product.name, sku: route.product.id, brand: { "@type": "Brand", name: "WOODEX" },
        category: route.product.category,
        offers: { "@type": "Offer", priceCurrency: "PKR", price: Number(route.product.price) || 0, availability: route.product.inStock === false ? "https://schema.org/OutOfStock" : "https://schema.org/InStock", url },
      });
    }

    // Compose: original shell with a replaced <head> block and prefilled root.
    let html = shell.replace(/<title>[\s\S]*?<\/title>/, "");
    html = html.replace(/\s*<meta name="description"[^>]*>/, "");
    html = html.replace(/\s*<link rel="canonical"[^>]*>/, "");
    html = html.replace(/\s*<meta property="og:[^"]*"[^>]*>/g, "");
    html = html.replace(/\s*<meta name="twitter:[^"]*"[^>]*>/g, "");
    html = html.replace(/\s*<meta name="author"[^>]*>/, "");
    html = html.replace(/\s*<meta name="theme-color"[^>]*>/, "");
    /* Idempotency: pre-existing structured data / sitemap hints from a previous
       run must be dropped, or re-running prerender over its own output doubles
       every JSON-LD block. */
    html = html.replace(/\s*<script type="application\/ld\+json">[\s\S]*?<\/script>/g, "");
    html = html.replace(/\s*<link rel="sitemap"[^>]*>/g, "");
    html = html.replace("</head>", headFor(route.path, route.title, route.desc, extra) + "\n  </head>");
    /* Replace whatever is inside #root (an EMPTY shell, or — when prerender is
       run twice against its own output — a previous render with its Suspense
       fallback still in it). A literal `<div id="root"></div>` match silently
       no-ops on the second run, which is exactly how a "532-byte" page was
       shipped twice. Anchor on the balanced end: <div id="root"> … </div> at the
       very end of <body>. */
    const rootRe = /<div id="root">[\s\S]*?<\/div>(?=\s*<\/body>)/;
    if (!rootRe.test(html)) throw new Error(`${route.path}: shell has no closing #root before </body>`);
    html = html.replace(rootRe, `<div id="root">${appHtml}</div>`);

    const outDir = route.path === "/" ? DIST : path.join(DIST, route.path.replace(/^\//, ""));
    fs.mkdirSync(outDir, { recursive: true });
    fs.writeFileSync(path.join(outDir, "index.html"), html);
    written.push({ path: route.path, priority: route.priority, changefreq: route.changefreq });
    ok += 1;
  }

  console.log(`[prerender] rendered ${ok} routes in ${((Date.now() - t0) / 1000).toFixed(1)}s`);
  if (process.env.PRERENDER_ONLY) {
    console.log("[prerender] partial run — sitemap.xml and robots.txt left untouched");
    return;
  }

  /* Only robots.txt is written here. A static dist/sitemap.xml would be served
     by Vercel *before* rewrites and shadow the API's /sitemap.xml — and only the
     API can see published CMS pages, so the API's is the complete one. One
     sitemap, one owner. */
  fs.writeFileSync(
    path.join(DIST, "robots.txt"),
    `User-agent: *\nAllow: /\nDisallow: /api/\nDisallow: /order-status\nDisallow: /checkout\n\nSitemap: ${ORIGIN}/sitemap.xml\n`
  );
  console.log(`[prerender] ${ok}/${targets.length} routes rendered · robots.txt written · sitemap served by the API`);
}

main()
  .then(() => {
    /* jsdom keeps timers/handles alive per window; without an explicit exit a
       finished 12-second build sat idle until the CI timeout killed it. */
    process.exit(0);
  })
  .catch((e) => {
    console.error("[prerender] failed:", e);
    process.exit(1);
  });

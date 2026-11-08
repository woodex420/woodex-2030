import { JSDOM, VirtualConsole } from "jsdom";
import { build } from "esbuild";
import path from "node:path";
const SRC = "/home/user/woodex-2030/apps/storefront/src";
const seed = process.argv[2]; // "" | "seed"

const errs = []; const vc = new VirtualConsole();
vc.on("jsdomError", e => { const m = e.stack ?? e.message ?? String(e); if (/Could not load|resource-errors/.test(m)) return; errs.push(m.split("\n")[0]); });
const dom = new JSDOM(`<!doctype html><html><head></head><body><div id="root"></div></body></html>`, { url: process.env.PROBE_URL ?? "https://woodex.pk/quotation", pretendToBeVisual: true, virtualConsole: vc });
const w = dom.window; const noop = () => {};
w.matchMedia = w.matchMedia || (() => ({ matches: false, addEventListener: noop, removeEventListener: noop, addListener: noop, removeListener: noop }));
w.scrollTo = noop;
const NATIVE = globalThis.fetch;
w.fetch = (u, o) => NATIVE(new URL(String(u), "http://127.0.0.1:3001").href, o);
if (seed === "seed") {
  w.localStorage.setItem("woodex-quote", JSON.stringify([{ id: "x", name: "Seeded", category: "Desk", price: 1000, image: "", quantity: 1 }]));
}
const DG = ["HTMLElement","HTMLFormElement","HTMLInputElement","HTMLButtonElement","HTMLSelectElement","HTMLTextAreaElement","HTMLAnchorElement","HTMLImageElement","HTMLDivElement","HTMLStyleElement","HTMLScriptElement","HTMLCanvasElement","HTMLIFrameElement","SVGElement","Element","Node","DocumentFragment","DOMParser","MutationObserver","MouseEvent","KeyboardEvent","FocusEvent","InputEvent","PointerEvent","CSS","getComputedStyle","getSelection"];
const g = { window: w, document: w.document, location: w.location, history: w.history, localStorage: w.localStorage, sessionStorage: w.sessionStorage, CustomEvent: w.CustomEvent, Event: w.Event, matchMedia: w.matchMedia, requestAnimationFrame: (cb) => setTimeout(() => cb(Date.now()), 0), cancelAnimationFrame: clearTimeout, ResizeObserver: class { observe() {} unobserve() {} disconnect() {} }, IntersectionObserver: class { observe() {} unobserve() {} disconnect() {} takeRecords() { return []; } }, scrollTo: noop, fetch: w.fetch, EventSource: class { constructor() { this.readyState = 0; } addEventListener() {} close() {} } };
for (const n of DG) if (w[n] !== undefined) g[n] = w[n];
for (const [k, v] of Object.entries(g)) { try { w[k] = v; } catch {} try { Object.defineProperty(globalThis, k, { value: v, configurable: true, writable: true }); } catch {} }
Object.defineProperty(globalThis, "addEventListener", { value: (...a) => w.addEventListener(...a), configurable: true, writable: true });
Object.defineProperty(globalThis, "removeEventListener", { value: (...a) => w.removeEventListener(...a), configurable: true, writable: true });

const r = await build({ entryPoints: [path.join(SRC, "main.tsx")], bundle: true, write: false, format: "iife", platform: "browser", jsx: "automatic",
  loader: { ".jpg": "text", ".png": "text", ".svg": "text", ".css": "empty" }, alias: { "@": SRC },
  define: { "process.env.NODE_ENV": '"production"', "import.meta.env": JSON.stringify({ MODE: "production", DEV: false, PROD: true, BASE_URL: "/" }) },
  splitting: false,
  plugins: [{ name: "eager", setup(b) { b.onLoad({ filter: /App\.tsx$/ }, async (a) => {
    const fs = await import("node:fs/promises");
    const src = await fs.readFile(a.path, "utf8");
    const m = [...src.matchAll(/const\s+(\w+)\s*=\s*lazy\(\s*\(\)\s*=>\s*import\((["'][^"']+["'])\)\s*\)/g)];
    let out = m.map(([, , sp], i) => `import __l_${i} from ${sp};`).join("\n") + "\n" + src;
    m.forEach(([full, nm], i) => { out = out.replace(full, `const ${nm} = __l_${i};`); });
    return { contents: out, loader: "tsx", resolveDir: path.dirname(a.path) };
  }); } }], logLevel: "silent" });
w.eval(r.outputFiles[0].text);
for (let i = 0; i < 250; i++) {
  const el = w.document.getElementById("root");
  if (el && /E-Quotation/.test(el.textContent)) break;
  await new Promise(r => setTimeout(r, 20));
}
const el = w.document.getElementById("root");
const text = el.textContent;
console.log(`localStorage seed=${seed || "none"} | root ${el.innerHTML.length} B | errors ${errs.length}`);
console.log("  'Your Quote Basket' occurrences:", (el.innerHTML.match(/Your Quote Basket/g) ?? []).length);
console.log("  getByText('Your Quote Basket') matches:", (text.match(/Your Quote Basket/g) ?? []).length);
console.log("  text sample:", text.replace(/\s+/g," ").slice(0, 200));
process.exit(0);

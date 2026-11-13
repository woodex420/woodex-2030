#!/usr/bin/env node
/** Render existing operational pickers with the actual products response envelope.
 * Fixtures only: no API server, database, original project or provider is touched. */
import { JSDOM, VirtualConsole } from "jsdom";
import { build } from "esbuild";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
let failed = false;
for (const name of ["PurchaseOrders", "Production", "StockReservations"]) {
  const errors = [];
  const vc = new VirtualConsole();
  vc.on("jsdomError", (error) => errors.push(error.message));
  vc.on("error", (...args) => errors.push(args.join(" ")));
  const dom = new JSDOM('<div id="root"></div>', { url: "http://fixture.test/", pretendToBeVisual: true, runScripts: "outside-only", virtualConsole: vc });
  const w = dom.window;
  const calls = [];
  w.matchMedia = () => ({ matches: false, addEventListener() {}, removeEventListener() {}, addListener() {}, removeListener() {} });
  w.ResizeObserver = class { observe() {} unobserve() {} disconnect() {} };
  w.scrollTo = () => {};
  w.fetch = async (input) => {
    const url = new URL(String(input), w.location.href);
    calls.push(url.pathname);
    let data;
    switch (url.pathname) {
      case "/api/products": data = { total: 1, items: [{ id: "fixture-chair", name: "Contract fixture chair", price: 1200 }] }; break;
      case "/api/materials": data = [{ id: "fixture-finish", name: "Contract fixture finish" }]; break;
      case "/api/suppliers": data = { items: [{ id: 1, name: "Fixture supplier", code: "FIXTURE", active: true }] }; break;
      default: data = { total: 0, open: 0, awaitingQc: 0, active: 0, items: [] };
    }
    return new Response(JSON.stringify(data), { headers: { "content-type": "application/json" } });
  };
  const src = path.join(root, "apps/dashboard/src");
  const bundle = await build({
    stdin: { contents: `import React from 'react'; import {createRoot} from 'react-dom/client'; import {MemoryRouter} from 'react-router'; import {ToastProvider} from '@/components/ui/Toast'; import Page from '@/pages/${name}'; window.fixtureRoot=createRoot(document.getElementById('root')); window.fixtureRoot.render(<MemoryRouter><ToastProvider><Page /></ToastProvider></MemoryRouter>);`, loader: "tsx", resolveDir: path.join(root, "apps/dashboard") },
    bundle: true, write: false, format: "iife", platform: "browser", jsx: "automatic", alias: { "@": src },
    define: { "process.env.NODE_ENV": '"development"', "import.meta.env": JSON.stringify({ DEV: true, PROD: false, BASE_URL: "/" }) },
    loader: { ".css": "empty" }, logLevel: "silent",
  });
  try {
    w.eval(bundle.outputFiles[0].text);
    await new Promise((resolve) => setTimeout(resolve, 350));
    const label = name === "PurchaseOrders" ? /new purchase order/i : name === "Production" ? /raise job card/i : /hold stock/i;
    const button = [...w.document.querySelectorAll("button")].find((element) => label.test(element.textContent));
    if (!button) throw new Error("Creation action not rendered");
    button.click();
    await new Promise((resolve) => setTimeout(resolve, 150));
    if (!calls.includes("/api/products")) throw new Error("Products were not requested");
    if (![...w.document.querySelectorAll("option")].some((element) => element.textContent.includes("Contract fixture chair"))) throw new Error("API envelope product missing from picker");
    if (name === "StockReservations" && calls.includes("/api/stock-reservations/availability")) throw new Error("Availability requested without a selected product");
    if (errors.length) throw new Error(errors.join("; "));
    console.log(`PASS ${name}: real component loads product envelope and opens picker`);
  } catch (error) {
    failed = true;
    console.error(`FAIL ${name}: ${error.message}`);
  } finally {
    w.fixtureRoot?.unmount();
    w.close();
  }
}
process.exit(failed ? 1 : 0);

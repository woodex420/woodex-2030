// A page of the shop works out again what it worked out from the catalogue, whenever the catalogue moves:
// also when it moves in the moment between the page's first drawing and the page's own listener (defect D-085).
//
// Run by CI beside the other scripts of the shop's readers. What it holds is `useRuntimeRevision` of
// apps/storefront/src/lib/runtime.ts, drawn by React in a window as the shop draws it:
//
//   · the frame of the shop is on the screen first and listens (main.tsx wraps the whole shop in WithRuntimeSync);
//   · a page arrives later, because every page is loaded apart (App.tsx), and works a list out from the catalogue;
//   · the catalogue arrives after that page was first drawn and before the page's listener is attached.
//
// The page must then show what the catalogue holds. On 28 November 2026 it did not: the frame heard the
// announcement and drew everything again, the page read the catalogue's size anew ("All Products 148") and kept
// the list it had worked out from the empty catalogue ("Nothing is listed in this category yet").
//
// Tried against (this file's own claim): with the hook as it was until that day, a count kept by each component
// from the moment its own listener is attached, the first assertion fails and prints
// "worked out from 0, beside a count of 1".
import assert from 'node:assert/strict';
import path from 'node:path';
import { build } from 'esbuild';
import { JSDOM } from 'jsdom';

// The window comes first: the counter attaches its one listener when the module is first read.
const dom = new JSDOM('<div id="root"></div>', { url: 'https://shop.test', pretendToBeVisual: true });
globalThis.window = dom.window;
globalThis.document = dom.window.document;
globalThis.CustomEvent = dom.window.CustomEvent;
globalThis.IS_REACT_ACT_ENVIRONMENT = true;

const bundle = await build({
  stdin: {
    contents: `
      import { act, createElement as h, useLayoutEffect, useMemo } from 'react';
      import { createRoot } from 'react-dom/client';
      import { useRuntimeRevision, WithRuntimeSync } from '@/lib/runtime';
      import { products } from '@/data/products';
      export { products };

      /** A page as the shop's pages are: it works something out from the catalogue and keeps it until the catalogue moves. */
      function Page({ onFirstDrawing }) {
        const moved = useRuntimeRevision();
        const worked = useMemo(() => products.length, [moved]);
        // Runs once the page is drawn and before any listener of it is attached: the moment in question.
        useLayoutEffect(() => { onFirstDrawing(); }, []);
        return h('p', null, 'worked out from ' + worked + ', beside a count of ' + products.length);
      }

      export async function shop(container) {
        const root = createRoot(container);
        // The frame alone: it is drawn, and it listens.
        await act(async () => { root.render(h(WithRuntimeSync, { render: () => null })); });
        return {
          /** The page arrives; what is passed in happens in the moment after its first drawing. */
          async open(onFirstDrawing) { await act(async () => { root.render(h(WithRuntimeSync, { render: () => h(Page, { onFirstDrawing }) })); }); },
          async during(what) { await act(async () => { what(); }); },
          async close() { await act(async () => { root.unmount(); }); },
        };
      }
    `,
    resolveDir: path.resolve('apps/storefront/src'), loader: 'tsx',
  },
  bundle: true, platform: 'node', format: 'esm', write: false, logLevel: 'silent',
  alias: { '@': path.resolve('apps/storefront/src') },
  define: { 'import.meta.env': '{}' },
  // React is asked for the way a script asks for it, so that the one copy this script runs is the one it bundles.
  banner: { js: "import { createRequire as __createRequire } from 'node:module'; const require = __createRequire(" + JSON.stringify(path.resolve('package.json')) + ');' },
});
const page = await import('data:text/javascript;base64,' + Buffer.from(bundle.outputFiles[0].text).toString('base64'));

const container = document.getElementById('root');
const announce = () => window.dispatchEvent(new CustomEvent('woodex:runtime'));
const product = (id) => ({ id, name: id, category: 'office-tables', price: 1, description: '', shortDescription: '', images: [], colors: [], specifications: [], features: [], inStock: true, isNew: false, isBestSeller: false });

assert.equal(page.products.length, 0, 'nothing is compiled into the shop: the catalogue is empty until it is read');

/* 1. The catalogue arrives in the moment after the page was first drawn. */
const shop = await page.shop(container);
await shop.open(() => { page.products.push(product('desk-one')); announce(); });
assert.equal(container.textContent, 'worked out from 1, beside a count of 1',
  'the catalogue arrived between the first drawing of a page and its listener, and the page kept what it had worked out from the empty catalogue');

/* 2. And afterwards, as it always did: every announcement is counted once, by the page that is on the screen. */
await shop.during(() => { page.products.push(product('desk-two')); announce(); });
assert.equal(container.textContent, 'worked out from 2, beside a count of 2', 'a change that arrived while the page was open was not worked out');

/* 3. Without an announcement nothing is worked out again: that is what the count is for. */
await shop.during(() => { page.products.push(product('desk-three')); });
assert.equal(container.textContent, 'worked out from 2, beside a count of 2', 'the page worked its list out again though nothing was announced');
await shop.during(announce);
assert.equal(container.textContent, 'worked out from 3, beside a count of 3');

/* 4. A page that has gone is not told any more, and a page opened after the catalogue arrived starts from it. */
await shop.close();
announce();
const again = await page.shop(container);
await again.open(() => {});
assert.equal(container.textContent, 'worked out from 3, beside a count of 3', 'a page opened after the catalogue had arrived did not start from it');
await again.close();

console.log('The page\'s count of changes passed: a catalogue that arrives right after a page is first drawn is worked out, every announcement is counted once, and nothing is worked out without one.');
// React keeps a channel open for its own scheduling, and the window a timer: neither ends by itself.
dom.window.close();
process.exit(0);

import assert from 'node:assert/strict';
import { build } from 'esbuild';
import { JSDOM } from 'jsdom';
const dom = new JSDOM('<div id="root"></div>', { url: 'https://storefront.example.test/contact' });
for (const key of ['window', 'document', 'HTMLElement', 'Event']) globalThis[key] = dom.window[key];
let callbacks, rendered = 0, reset = 0, removed = 0;
window.turnstile = {
  render(_host, options) { rendered++; callbacks = options; return `synthetic-${rendered}`; },
  reset() { reset++; }, remove() { removed++; },
};
const built = await build({
  stdin: { contents: `import React from 'react'; import {createRoot} from 'react-dom/client'; import LeadChallenge from './apps/storefront/src/components/LeadChallenge'; export const root=createRoot(document.getElementById('root')); root.render(React.createElement(LeadChallenge));`, resolveDir: process.cwd(), loader: 'tsx' },
  bundle: true, platform: 'browser', format: 'esm', jsx: 'automatic', write: false,
  define: { 'process.env.NODE_ENV': '"test"', 'import.meta.env.VITE_LEAD_API_MODE': '"v1"', 'import.meta.env.VITE_TURNSTILE_SITE_KEY': '"synthetic-site-key"', 'import.meta.env.PROD': 'false' },
});
const { root } = await import('data:text/javascript;base64,' + Buffer.from(built.outputFiles[0].text).toString('base64'));
const settle = () => new Promise(resolve => setTimeout(resolve, 40));
await settle(); assert.equal(rendered, 1);
callbacks.callback('synthetic-token'); await settle();
assert.equal(document.querySelector('[name=turnstile_token]').value, 'synthetic-token');
callbacks['expired-callback'](); await settle();
assert.equal(document.querySelector('[name=turnstile_token]').value, '');
assert.match(document.body.textContent, /expired/);
callbacks.callback('synthetic-retry-token'); await settle();
window.dispatchEvent(new Event('woodex:lead-attempt')); await settle();
assert.equal(reset, 1); assert.equal(document.querySelector('[name=turnstile_token]').value, '');
callbacks['error-callback'](); await settle();
assert.match(document.body.textContent, /failed/);
document.querySelector('button').click(); await settle();
assert.equal(rendered, 2); assert.equal(removed, 1);
root.unmount(); await settle(); assert.equal(removed, 2);
window.dispatchEvent(new Event('woodex:lead-attempt')); assert.equal(reset, 1);
dom.window.close();
console.log('Lead challenge lifecycle passed: success, expiry, reset, retry, unmount cleanup. Provider mocked; no external calls.');

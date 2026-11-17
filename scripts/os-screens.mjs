#!/usr/bin/env node
/**
 * Opens every screen of the Laravel admin in a real browser, as each role, and writes down what it finds.
 *
 *   OS_BASE=http://127.0.0.1:8191 WX_PW_OWNER=… WX_PW_MANAGER=… WX_PW_SALES=… WX_PW_ACCOUNTS=… \
 *     node scripts/os-screens.mjs [--out <folder>] [--shots]
 *
 * It needs a running admin with the four demo accounts (owner@, manager@, sales@, accounts@woodex.test):
 * the rehearsed Vercel demo (platform/tools/vercel/serve-demo.sh), a sandbox preview, or the deployed demo.
 * It starts nothing and changes nothing: it only opens pages.
 *
 * For every role it signs in through the form, then follows the links of the dashboard (the menu, the tabs,
 * the dashboard's own links), two levels deep for the owner and one for the others, one page per kind of address
 * (/admin/leads/1 and /admin/leads/2 are one kind). Then it opens the same pages again as a phone (390 px wide).
 *
 * Per page: the status, the heading, errors in the console, requests that failed, images that did not load,
 * the time to the first byte and to a finished page, the bytes transferred, whether the page is wider than the
 * window (and which element sticks out), the height of the top bar on a phone, and four accessibility counts.
 * Downloads (PDF, CSV) are fetched with the signed-in session, not opened.
 *
 * OS_WATCH="Interior,Studio" names words that no screen may carry (the admin is for Woodex Furniture only). They are
 * looked for where a person meets them: in the page's text, shown or folded away (a dialog, a menu), in the choices
 * of a list, in the hints and names of fields and buttons, and in the links that leave the admin — a WhatsApp
 * greeting is a link. Capital letters count: "Interior" is not "interior".
 *
 * Output: <folder>/screens.json (everything) and a summary on the screen. Exit code 1 when a page did not open,
 * threw an error, a request failed or a watched word was found; layout and accessibility findings are reported,
 * they do not fail the run.
 */
import { mkdirSync, writeFileSync } from 'node:fs';
import { launchBrowser } from './lib/browser.mjs';

const base = (process.env.OS_BASE || 'http://127.0.0.1:8191').replace(/\/$/, '');
const args = process.argv.slice(2);
const out = args.includes('--out') ? args[args.indexOf('--out') + 1] : '/tmp/os-screens';
const shots = args.includes('--shots');
const maxPages = Number(process.env.OS_MAX_PAGES || 220);
const watch = (process.env.OS_WATCH || '').split(',').map((word) => word.trim()).filter(Boolean);
mkdirSync(out, { recursive: true });

const roles = [
  { key: 'owner', email: 'owner@woodex.test', password: process.env.WX_PW_OWNER, depth: 2 },
  { key: 'sales_manager', email: 'manager@woodex.test', password: process.env.WX_PW_MANAGER, depth: 1 },
  { key: 'sales', email: 'sales@woodex.test', password: process.env.WX_PW_SALES, depth: 1 },
  { key: 'accountant', email: 'accounts@woodex.test', password: process.env.WX_PW_ACCOUNTS, depth: 1 },
].filter((role) => (process.env.OS_ROLES ? process.env.OS_ROLES.split(',').includes(role.key) : true));
for (const role of roles) {
  if (!role.password) { console.error(`The password of ${role.email} is not set.`); process.exit(2); }
}

const isDownload = (path) => /\.(pdf|csv|xlsx|zip)(\?|$)|\/pdf(\?|$)|\/export(\?|$|\.)|\/download(\?|$)/i.test(path);
/** /admin/leads/12?tab=x → /admin/leads/{n}?tab=x : one page per kind of address is enough. */
const kind = (path) => path.replace(/\/\d+(?=\/|\?|$)/g, '/{n}').replace(/([?&](?:page|search|q|new|contact|lead|from|to|date|month|year|id|description|created|preset|status)=)[^&]*/g, '$1…');
const skip = (path) => /\/logout(\?|$)/.test(path) || !path.startsWith('/admin');
const fileName = (role, path, width) => `${out}/${role}-${kind(path).replace(/[^a-z0-9]+/gi, '_').replace(/^_|_$/g, '').slice(0, 60) || 'admin'}-${width}.jpg`;

/** Runs in the page: what the page shows, and what sticks out of the window. */
const inPage = (watch = []) => {
  const visible = (el) => !!(el.offsetWidth || el.offsetHeight || el.getClientRects().length);
  const named = (el) => (el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.getAttribute('title') || el.textContent || '').trim() !== '';
  const labelled = (el) => el.getAttribute('aria-label') || el.getAttribute('aria-labelledby') || el.getAttribute('title')
    || (el.id && document.querySelector(`label[for="${CSS.escape(el.id)}"]`)) || el.closest('label');
  const describe = (el) => `${el.tagName.toLowerCase()}${el.id ? `#${el.id}` : ''}${typeof el.className === 'string' && el.className ? `.${el.className.split(/\s+/).slice(0, 3).join('.')}` : ''}`;
  const fields = [...document.querySelectorAll('input:not([type=hidden]):not([type=submit]):not([type=button]), select, textarea')].filter(visible);
  const main = document.querySelector('main') || document.body;
  const width = document.documentElement.clientWidth;
  // What a finger can hit: the control itself or, for a tick box or radio button, the label that wraps or names it.
  const tooSmall = (el) => {
    const boxes = [el.getBoundingClientRect()];
    if (el.matches('input[type=checkbox], input[type=radio]')) {
      const label = el.closest('label') || (el.id ? document.querySelector(`label[for="${CSS.escape(el.id)}"]`) : null);
      if (label) boxes.push(label.getBoundingClientRect());
    }
    return !boxes.some((box) => box.width >= 24 && box.height >= 24);
  };
  const sticking = [];
  for (const el of document.querySelectorAll('body *')) {
    const box = el.getBoundingClientRect();
    if (box.width === 0 || box.height === 0 || box.right <= width + 0.5 || box.left >= width) continue;
    let contained = false;
    for (let parent = el.parentElement; parent && parent !== document.body; parent = parent.parentElement) {
      if (/(auto|scroll|hidden|clip)/.test(getComputedStyle(parent).overflowX)) { contained = true; break; }
    }
    if (!contained) sticking.push(`${describe(el)} ends at ${Math.round(box.right)} px`);
  }
  // Watched words, with where each was met and the words around it.
  const watched = [];
  if (watch.length > 0) {
    const readable = document.body.cloneNode(true);
    for (const el of readable.querySelectorAll('script, style, noscript, template')) el.remove();
    const decoded = (href) => { try { return decodeURIComponent(href.replace(/\+/g, ' ')); } catch { return href; } };
    const places = {
      text: readable.textContent.replace(/\s+/g, ' '),
      choice: [...document.querySelectorAll('option')].map((option) => option.textContent.trim()).join(' | '),
      hint: [...document.querySelectorAll('[placeholder], [title], [aria-label], [alt]')]
        .flatMap((el) => ['placeholder', 'title', 'aria-label', 'alt'].map((name) => el.getAttribute(name)).filter(Boolean)).join(' | '),
      link: [...document.querySelectorAll('a[href]')].map((a) => decoded(a.getAttribute('href'))).join(' | '),
    };
    for (const word of watch) {
      for (const [place, text] of Object.entries(places)) {
        const at = text.indexOf(word);
        if (at >= 0) watched.push({ word, place, around: text.slice(Math.max(0, at - 50), at + word.length + 50).trim() });
      }
    }
  }
  const header = document.querySelector('header.wx-admin-header');
  const navigation = performance.getEntriesByType('navigation')[0];
  const resources = performance.getEntriesByType('resource');
  return {
    title: document.title,
    h1: [...document.querySelectorAll('h1')].filter(visible).map((el) => el.textContent.trim().replace(/\s+/g, ' ')).slice(0, 3),
    demoNotice: document.querySelectorAll('[data-demo-notice]').length,
    watched,
    overflow: document.documentElement.scrollWidth - width,
    sticking: sticking.slice(0, 4),
    headerHeight: header ? Math.round(header.getBoundingClientRect().height) : null,
    smallTargets: [...main.querySelectorAll('button, [role=button], select, input[type=checkbox], input[type=radio]')]
      .filter((el) => visible(el) && !el.classList.contains('sr-only') && tooSmall(el)).length,
    brokenImages: [...document.images].filter((img) => img.complete && img.naturalWidth === 0 && visible(img)).map((img) => img.currentSrc || img.src).slice(0, 5),
    errorText: /Server Error|Whoops|SQLSTATE|Stack trace|ErrorException|Symfony\\Component|Illuminate\\/.test(main.innerText) ? main.innerText.slice(0, 200) : '',
    a11y: {
      imagesWithoutAlt: [...document.images].filter((img) => visible(img) && !img.hasAttribute('alt')).length,
      fieldsWithoutLabel: fields.filter((el) => !labelled(el)).map(describe).slice(0, 12),
      buttonsWithoutName: [...document.querySelectorAll('button, a[href]')].filter((el) => visible(el) && !named(el) && !el.querySelector('img[alt]:not([alt=""]), svg[aria-label], [aria-label]')).map(describe).slice(0, 12),
      headingsH1: [...document.querySelectorAll('h1')].filter(visible).length,
      lang: document.documentElement.lang || '',
    },
    links: [...document.querySelectorAll('a[href]')].map((a) => a.getAttribute('href')),
    deadLinks: [...document.querySelectorAll('a[href="#"], a[href=""], a:not([href])')].filter(visible).length,
    timing: navigation ? {
      firstByte: Math.round(navigation.responseStart - navigation.requestStart),
      ready: Math.round(navigation.domContentLoadedEventEnd),
      html: navigation.encodedBodySize,
      requests: resources.length + 1,
      transferred: Math.round(navigation.transferSize + resources.reduce((total, entry) => total + (entry.transferSize || 0), 0)),
    } : null,
    // Every part of the page that asks the server again on a timer, with its interval ("wire:poll.30s.visible").
    polls: [...document.querySelectorAll('*')].flatMap((el) => [...el.attributes].filter((attribute) => attribute.name.startsWith('wire:poll')).map((attribute) => attribute.name)),
  };
};

const browser = await launchBrowser();
const report = { base, at: new Date().toISOString(), roles: {} };
let failures = 0;

for (const role of roles) {
  const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
  const page = await context.newPage();
  let current = null;
  const listen = (target) => {
    target.on('console', (message) => { if (message.type() === 'error' && current) current.console.push(message.text().slice(0, 240)); });
    target.on('pageerror', (error) => { if (current) current.pageErrors.push(String(error.message).slice(0, 240)); });
    target.on('response', (response) => {
      const status = response.status();
      if (status >= 400 && current && response.url() !== current.requested) current.failedRequests.push(`${status} ${response.request().method()} ${response.url().replace(base, '')}`.slice(0, 200));
    });
  };
  listen(page);

  const entry = { email: role.email, pages: [], downloads: [], phone: [], signIn: 'not tried' };
  report.roles[role.key] = entry;
  try {
    const started = Date.now();
    await page.goto(`${base}/admin/login`, { waitUntil: 'domcontentloaded' });
    entry.signInPageMs = Date.now() - started;
    await page.locator('#email').fill(role.email);
    await page.locator('#password').fill(role.password);
    await Promise.all([page.waitForURL((url) => !url.pathname.endsWith('/login'), { timeout: 30000 }), page.getByRole('button', { name: 'Sign in', exact: true }).click()]);
    entry.signIn = `ok → ${new URL(page.url()).pathname}`;
  } catch (error) {
    entry.signIn = `FAILED: ${String(error.message).split('\n')[0]}`;
    failures += 1;
    await context.close();
    continue;
  }

  // --- desktop: follow the links ---------------------------------------------------------------------------------
  const seen = new Set();
  const queue = [{ path: new URL(page.url()).pathname, depth: 0, from: 'sign-in' }];
  while (queue.length > 0 && entry.pages.length + entry.downloads.length < maxPages) {
    const { path, depth, from } = queue.shift();
    if (skip(path) || seen.has(kind(path))) continue;
    seen.add(kind(path));

    if (isDownload(path)) {
      const download = await page.evaluate(async (url) => {
        const response = await fetch(url, { credentials: 'same-origin' });
        const bytes = new Uint8Array(await response.arrayBuffer());
        return { status: response.status, type: response.headers.get('content-type') || '', disposition: response.headers.get('content-disposition') || '', bytes: bytes.length, starts: String.fromCharCode(...bytes.slice(0, 5)) };
      }, path).catch((error) => ({ status: 0, type: '', disposition: '', bytes: 0, starts: '', failure: String(error.message).slice(0, 160) }));
      download.path = path; download.from = from;
      download.bad = download.status !== 200 || /text\/html/.test(download.type);
      if (download.bad) failures += 1;
      entry.downloads.push(download);
      continue;
    }

    current = { path, from, depth, requested: base + path, console: [], pageErrors: [], failedRequests: [] };
    try {
      const response = await page.goto(base + path, { waitUntil: 'domcontentloaded', timeout: 30000 });
      current.status = response ? response.status() : 0;
      current.final = new URL(page.url()).pathname + new URL(page.url()).search;
      const idleFrom = Date.now();
      current.settled = await page.waitForLoadState('networkidle', { timeout: 5000 }).then(() => true, () => false);
      current.idleMs = Date.now() - idleFrom;
      const { links, ...found } = await page.evaluate(inPage, watch);
      Object.assign(current, found);
      if (shots && depth <= 1) await page.screenshot({ path: fileName(role.key, path, 1366), type: 'jpeg', quality: 60 }).catch(() => {});
      if (depth < role.depth) {
        for (const href of links) {
          let url;
          try { url = new URL(href, base + path); } catch { continue; }
          if (url.origin !== base) continue;
          const next = url.pathname + url.search;
          if (!skip(next) && !seen.has(kind(next))) queue.push({ path: next, depth: depth + 1, from: path });
        }
      }
    } catch (error) {
      current.status = current.status ?? 0;
      current.failure = String(error.message).split('\n')[0].slice(0, 200);
    }
    current.bad = Boolean(current.status !== 200 || current.failure || current.errorText || current.pageErrors.length > 0 || current.failedRequests.length > 0 || (current.watched?.length ?? 0) > 0);
    if (current.bad) failures += 1;
    entry.pages.push(current);
    current = null;
  }
  entry.left = queue.length;

  // --- phone: the same pages, loaded as a phone loads them -----------------------------------------------------------
  const phone = await browser.newContext({ storageState: await context.storageState(), viewport: { width: 390, height: 844 }, deviceScaleFactor: 2, isMobile: true, hasTouch: true });
  await context.close();
  const small = await phone.newPage();
  listen(small);
  for (const opened of entry.pages.filter((item) => item.status === 200 && !item.failure)) {
    current = { path: opened.path, requested: base + opened.path, console: [], pageErrors: [], failedRequests: [] };
    try {
      const response = await small.goto(base + opened.path, { waitUntil: 'domcontentloaded', timeout: 30000 });
      current.status = response ? response.status() : 0;
      await small.waitForLoadState('networkidle', { timeout: 4000 }).catch(() => {});
      const found = await small.evaluate(inPage);
      Object.assign(current, { overflow: found.overflow, sticking: found.sticking, headerHeight: found.headerHeight, smallTargets: found.smallTargets, h1: found.h1 });
      if (shots && opened.depth <= 1) await small.screenshot({ path: fileName(role.key, opened.path, 390), type: 'jpeg', quality: 60 }).catch(() => {});
    } catch (error) {
      current.failure = String(error.message).split('\n')[0].slice(0, 200);
    }
    current.bad = Boolean(current.status !== 200 || current.failure || current.pageErrors.length > 0 || current.failedRequests.length > 0);
    if (current.bad) failures += 1;
    entry.phone.push(current);
    current = null;
  }
  await phone.close();
}
await browser.close();

writeFileSync(`${out}/screens.json`, JSON.stringify(report, null, 2));

// --- summary ---------------------------------------------------------------------------------------------------------
const middle = (numbers) => { const sorted = numbers.filter((n) => Number.isFinite(n)).sort((a, b) => a - b); return sorted.length ? sorted[Math.floor(sorted.length / 2)] : null; };
const worst = (numbers) => { const sorted = numbers.filter((n) => Number.isFinite(n)).sort((a, b) => a - b); return sorted.length ? sorted[Math.min(sorted.length - 1, Math.floor(sorted.length * 0.95))] : null; };
for (const [key, entry] of Object.entries(report.roles)) {
  const pages = entry.pages;
  const fine = pages.filter((p) => p.status === 200);
  console.log(`\n${key} (${entry.email}) — sign-in ${entry.signIn}`);
  console.log(`  desktop: ${pages.length} pages opened, ${pages.filter((p) => p.bad).length} not fine; downloads ${entry.downloads.length}, ${entry.downloads.filter((d) => d.bad).length} not fine; addresses left unvisited ${entry.left ?? 0}`);
  console.log(`  time to first byte: median ${middle(fine.map((p) => p.timing?.firstByte))} ms, 95th ${worst(fine.map((p) => p.timing?.firstByte))} ms; page ready: median ${middle(fine.map((p) => p.timing?.ready))} ms`);
  console.log(`  slowest first byte: ${[...fine].sort((a, b) => (b.timing?.firstByte ?? 0) - (a.timing?.firstByte ?? 0)).slice(0, 3).map((p) => `${p.path} ${p.timing?.firstByte} ms`).join(' · ')}`);
  console.log(`  HTML size: median ${middle(fine.map((p) => p.timing?.html))} bytes, largest ${[...fine].sort((a, b) => (b.timing?.html ?? 0) - (a.timing?.html ?? 0)).slice(0, 2).map((p) => `${p.path} ${p.timing?.html}`).join(' · ')}`);
  console.log(`  never quiet within 5 s (a page that keeps asking the server): ${fine.filter((p) => p.settled === false).map((p) => p.path).slice(0, 6).join(', ') || 'none'}`);
  const timers = {};
  for (const p of fine) for (const timer of p.polls || []) timers[timer] = (timers[timer] || 0) + 1;
  console.log(`  asks the server again on a timer: ${Object.entries(timers).sort((a, b) => b[1] - a[1]).map(([timer, count]) => `${timer} on ${count} pages`).join(' · ') || 'no page'}`);
  console.log(`  wider than the window at 1366 px: ${fine.filter((p) => p.overflow > 0).length}; without the demo notice: ${fine.filter((p) => !p.demoNotice).length}; without exactly one h1: ${fine.filter((p) => p.a11y?.headingsH1 !== 1).length}`);
  console.log(`  accessibility counts — images without alt: ${fine.reduce((t, p) => t + (p.a11y?.imagesWithoutAlt ?? 0), 0)}; fields without a label: ${fine.reduce((t, p) => t + (p.a11y?.fieldsWithoutLabel?.length ?? 0), 0)} on ${fine.filter((p) => p.a11y?.fieldsWithoutLabel?.length).length} pages; buttons or links without a name: ${fine.reduce((t, p) => t + (p.a11y?.buttonsWithoutName?.length ?? 0), 0)}; links that lead nowhere: ${fine.reduce((t, p) => t + (p.deadLinks ?? 0), 0)}`);
  const phone = entry.phone;
  console.log(`  phone (390 px): ${phone.length} pages, ${phone.filter((p) => p.bad).length} not fine; wider than the window: ${phone.filter((p) => p.overflow > 0).length}; top bar taller than 72 px: ${phone.filter((p) => (p.headerHeight ?? 0) > 72).length}; controls smaller than 24 px: ${phone.reduce((t, p) => t + (p.smallTargets ?? 0), 0)} on ${phone.filter((p) => p.smallTargets).length} pages`);
  for (const p of pages.filter((p) => p.bad)) {
    console.log(`  NOT FINE ${p.path} (from ${p.from}) → ${p.status}${p.failure ? ` · ${p.failure}` : ''}${p.errorText ? ` · text: ${p.errorText.slice(0, 80)}` : ''}${p.pageErrors.length ? ` · page error: ${p.pageErrors[0]}` : ''}${p.failedRequests.length ? ` · requests: ${[...new Set(p.failedRequests)].slice(0, 3).join(', ')}` : ''}${p.watched?.length ? ` · watched word: ${p.watched.map((w) => `“${w.word}” in a ${w.place}: …${w.around}…`).slice(0, 3).join(' · ')}` : ''}`);
  }
  if (watch.length > 0) console.log(`  watched words (${watch.join(', ')}): on ${fine.filter((p) => p.watched?.length).length} of ${fine.length} pages`);
  for (const p of phone.filter((p) => p.overflow > 0)) console.log(`  PHONE WIDER ${p.path} by ${p.overflow} px — ${(p.sticking || []).slice(0, 2).join('; ')}`);
  for (const p of fine.filter((p) => p.a11y?.fieldsWithoutLabel?.length)) console.log(`  NO LABEL ${p.path}: ${p.a11y.fieldsWithoutLabel.slice(0, 4).join(', ')}`);
  for (const d of entry.downloads) console.log(`  download ${d.path} → ${d.status} ${d.type} ${d.bytes} bytes (starts "${d.starts.replace(/[^\x20-\x7e]/g, '.')}")${d.bad ? ' NOT FINE' : ''}`);
}
console.log(failures === 0 ? '\nEvery page opened without an error.' : `\n${failures} page(s) or download(s) were not fine.`);
console.log(`Details: ${out}/screens.json`);
process.exit(failures === 0 ? 0 : 1);

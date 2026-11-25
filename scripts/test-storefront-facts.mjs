#!/usr/bin/env node
/**
 * The shop gives a visitor ONE telephone number, ONE WhatsApp number, ONE e-mail address and ONE showroom, and
 * they are the business's own.
 *
 *   node scripts/test-storefront-facts.mjs            the source of the storefront
 *   node scripts/test-storefront-facts.mjs --built    also the pages of dist/ (run `npm run vercel:build` first)
 *
 * WHY THIS EXISTS (docs/woodex-os/43-DEEP-AUDIT-2026-11-23.md, finding AU-03; decision DL-048). Until 25 November
 * 2026 the shop showed "+92 300 1234567" in nineteen places of nine files, three WhatsApp links to that number
 * beside one to the real one, mailboxes on a domain that is not the business's, three different streets for one
 * head office, and four showrooms with sample streets and numbers nobody can dial ("+92 21 111 WOODEX"). Every
 * check of the shop passed: no check read what the pages say to a person who wants to call.
 *
 * What it holds:
 *  1. The facts stand in apps/storefront/src/config/brand.ts and agree with each other there.
 *  2. Nowhere else in the storefront is a telephone number, an e-mail address or a link that dials, writes or
 *     opens WhatsApp written out: a page reads them from that file. (An example inside a form field is allowed,
 *     and listed here by name.)
 *  3. None of the samples that stood in the shop is anywhere in it, also not in the file of the facts.
 *  4. No picture is imported into the storefront's code: it is served from /img (src/assets/img.ts). One import
 *     wrote the logo's bytes into the quotation page (finding AU-09).
 *  5. With --built: every page of dist/ carries the telephone number, none carries a sample or the bytes of a
 *     picture, and the structured data search engines read names the same number, street and a logo that is
 *     really there.
 *
 * Exit code 1 with the file and the line of every finding.
 */
import fs from "node:fs";
import os from "node:os";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { build } from "esbuild";

const ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const SRC = path.join(ROOT, "apps/storefront/src");
const BRAND_FILE = path.join(SRC, "config/brand.ts");
const withBuilt = process.argv.includes("--built");
const problems = [];
const rel = (file) => path.relative(ROOT, file);

/* ---------- 1. the facts, and that they agree ---------- */
const bundled = await build({ entryPoints: [BRAND_FILE], bundle: true, write: false, format: "esm", platform: "node" });
const tmp = path.join(os.tmpdir(), `woodex-brand-${process.pid}.mjs`);
fs.writeFileSync(tmp, bundled.outputFiles[0].text);
const { brand } = await import(tmp);
fs.unlinkSync(tmp);

const expect = (ok, message) => { if (!ok) problems.push(`${rel(BRAND_FILE)}: ${message}`); };
expect(/^\+92 3\d{2} \d{7}$/.test(brand.phone), `phone "${brand.phone}" is not written as "+92 3XX XXXXXXX"`);
const digits = String(brand.phone).replace(/\D/g, "");
expect(brand.phoneLink === `tel:+${digits}`, `phoneLink "${brand.phoneLink}" does not dial the phone ${brand.phone}`);
expect(/^92\d{10}$/.test(brand.whatsapp), `whatsapp "${brand.whatsapp}" is not twelve digits starting with 92`);
expect(brand.whatsappLink === `https://wa.me/${brand.whatsapp}`, `whatsappLink "${brand.whatsappLink}" does not open the WhatsApp number ${brand.whatsapp}`);
expect(/^[^@\s]+@[^@\s]+\.[a-z]{2,}$/i.test(brand.email), `email "${brand.email}" is not an e-mail address`);
expect(brand.emailLink === `mailto:${brand.email}`, `emailLink "${brand.emailLink}" does not write to ${brand.email}`);
expect(String(brand.address?.street ?? "").length > 5 && String(brand.address?.city ?? "").length > 1, "the address has no street or no city");
expect(/^https:\/\/[a-z0-9.-]+$/.test(brand.url) && brand.website === brand.url.replace(/^https:\/\//, "www."), `url "${brand.url}" and website "${brand.website}" do not name one site`);
expect(Number.isFinite(brand.geo?.lat) && Number.isFinite(brand.geo?.lng), "the point on the map is missing");

/* ---------- 2. and 3. the source ---------- */
/** The samples that stood in the shop. None may come back, in any file. */
const SAMPLES = [
  [/300[ -]?1234567/, "the sample telephone number +92 300 1234567"],
  [/\+92 \d{2} \d{3} WOODEX/, 'a number nobody can dial ("+92 21 111 WOODEX")'],
  [/Gulberg|Clifton Block|Blue Area|Saddar Road|D-Ground/, "a sample street of a showroom"],
  [/@woodex\.pk/, "a mailbox on woodex.pk, which is not the business's domain"],
  [/(?<![a-z0-9-])woodex\.pk/i, "the address woodex.pk, which is not the business's site (it is woodexfurniture.pk)"],
];
/** What only config/brand.ts may write out. */
const FACTS = [
  [/[A-Za-z0-9._+-]+@[A-Za-z0-9-]+\.[A-Za-z]{2,}/g, "an e-mail address"],
  [/(?:\+|00)?92[ -]?3\d{2}[ -]?\d{7}|\b03\d{2}[ -]?\d{7}\b/g, "a telephone number"],
  [/\b(?:tel:|mailto:)[^\s"'`<>${}]{3,}/g, "a link that dials or writes, written out"],
  [/wa\.me\/\d+/g, "a WhatsApp link with a number written out"],
];
/** Examples inside a form field: what a visitor may type, not a way to reach the business. */
const EXAMPLES = new Set(["your@email.com", "john@company.com"]);

function* files(dir) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) yield* files(full);
    else if (/\.(ts|tsx|css|html)$/.test(entry.name)) yield full;
  }
}
const sources = [...files(SRC), path.join(ROOT, "apps/storefront/index.html"), path.join(ROOT, "apps/api/public-routes.mjs"), path.join(ROOT, "scripts/prerender.mjs")];
let read = 0;
for (const file of sources) {
  read++;
  const lines = fs.readFileSync(file, "utf8").split("\n");
  lines.forEach((line, index) => {
    const at = `${rel(file)}:${index + 1}`;
    for (const [pattern, what] of SAMPLES) if (pattern.test(line)) problems.push(`${at}: ${what} — "${line.trim().slice(0, 110)}"`);
    if (file === BRAND_FILE) return;
    for (const [pattern, what] of FACTS) {
      for (const found of line.match(pattern) ?? []) {
        if (EXAMPLES.has(found)) continue;
        problems.push(`${at}: ${what} ("${found}") outside config/brand.ts — a page reads it from there`);
      }
    }
    /* ---------- 4. no picture is imported ---------- */
    if (/^\s*import\b.*\.(png|jpe?g|gif|webp|avif|svg)["']/.test(line)) problems.push(`${at}: a picture is imported into the code — serve it from /img and name it with img("…")`);
  });
}

/* ---------- 5. the built pages ---------- */
let pages = 0;
if (withBuilt) {
  const DIST = path.join(ROOT, "dist");
  if (!fs.existsSync(path.join(DIST, "index.html"))) {
    problems.push("dist/index.html is not there: run `npm run vercel:build` before `--built`");
  } else {
    const built = [path.join(DIST, "index.html")];
    for (const entry of fs.readdirSync(DIST, { withFileTypes: true })) {
      const page = path.join(DIST, entry.name, "index.html");
      if (entry.isDirectory() && entry.name !== "dashboard" && fs.existsSync(page)) built.push(page);
    }
    for (const page of built) {
      pages++;
      const html = fs.readFileSync(page, "utf8");
      for (const [pattern, what] of SAMPLES) if (pattern.test(html)) problems.push(`${rel(page)}: ${what}`);
      if (/JFIF|\uFFFD{4}/.test(html)) problems.push(`${rel(page)}: the bytes of a picture are written into the page`);
      if (!html.includes(brand.phone)) problems.push(`${rel(page)}: the page does not carry the telephone number ${brand.phone}`);
      if (!html.includes(brand.phoneLink)) problems.push(`${rel(page)}: no link on the page dials ${brand.phone}`);
    }
    // What a search engine is told, on the home page.
    const home = fs.readFileSync(path.join(DIST, "index.html"), "utf8");
    const data = [...home.matchAll(/<script type="application\/ld\+json"[^>]*>([\s\S]*?)<\/script>/g)].flatMap((match) => { try { const json = JSON.parse(match[1]); return Array.isArray(json) ? json : [json]; } catch { problems.push("dist/index.html: structured data that is not JSON"); return []; } });
    const organization = data.find((item) => item["@type"] === "Organization");
    const store = data.find((item) => item["@type"] === "FurnitureStore");
    if (!organization || !store) problems.push("dist/index.html: the structured data names no Organization or no FurnitureStore");
    else {
      if (organization.contactPoint?.[0]?.telephone !== brand.phone) problems.push(`dist/index.html: the structured data gives the telephone "${organization.contactPoint?.[0]?.telephone}", not ${brand.phone}`);
      if (store.telephone !== brand.phone || store.email !== brand.email) problems.push("dist/index.html: the shop's structured data gives another telephone number or e-mail address than config/brand.ts");
      if (store.address?.streetAddress !== brand.address.street) problems.push(`dist/index.html: the structured data gives the street "${store.address?.streetAddress}"`);
      const logo = String(organization.logo ?? "");
      const logoFile = path.join(DIST, new URL(logo, "https://shop.invalid").pathname);
      if (!fs.existsSync(logoFile)) problems.push(`dist/index.html: the structured data names the logo ${logo}, and no such file is in dist/`);
      if ("sameAs" in organization && organization.sameAs.some((link) => /\/$|\/undefined$/.test(link))) problems.push("dist/index.html: the structured data names a social account without a name");
    }
  }
}

if (problems.length) {
  console.error(`FAIL: ${problems.length} finding(s) about what the shop tells a visitor who wants to reach the business:`);
  for (const problem of problems) console.error("  - " + problem);
  process.exit(1);
}
console.log(`The shop gives one telephone number (${brand.phone}), one WhatsApp number, one e-mail address (${brand.email}) and one showroom (${brand.address.street}, ${brand.address.city}): all in config/brand.ts, written out nowhere else in ${read} files${withBuilt ? `, and on each of the ${pages} built pages` : ""}. No sample is left, and no picture is imported.`);

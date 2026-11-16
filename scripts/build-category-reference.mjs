import fs from 'node:fs';
import { createHash } from 'node:crypto';
const source = 'apps/api/seed-data.json';
const target = 'platform/resources/data/catalogue-reference.json';
const raw = fs.readFileSync(source);
const items = JSON.parse(raw).products.map(row => Object.fromEntries(Object.entries(row).filter(([key]) => ['id', 'name', 'category', 'subcategory', 'series'].includes(key))));
const expected = JSON.stringify({ source, sha256: createHash('sha256').update(raw).digest('hex'), items }, null, 2);
if (process.argv.includes('--check')) {
  if (fs.readFileSync(target, 'utf8') !== expected) throw new Error('Category reference is stale. Run node scripts/build-category-reference.mjs');
  console.log(`Category CMS reference matches ${items.length} source product keys; no prices, images or inventory copied.`);
} else {
  fs.writeFileSync(target, expected);
  console.log(`Wrote ${items.length} reference products to ${target}. Not a live catalogue import.`);
}

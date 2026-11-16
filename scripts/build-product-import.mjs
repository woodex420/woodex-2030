import fs from 'node:fs';
import { createHash } from 'node:crypto';
const source = 'apps/api/seed-data.json';
const raw = fs.readFileSync(source);
const products = JSON.parse(raw).products;
const uses = new Map();
for (const p of products) for (const image of new Set(p.images)) uses.set(image, (uses.get(image) || 0) + 1);
const items = products.map(p => ({ ...p, reviewIssues: [
  'Source price and availability need operator review; stock quantities are not verified.',
  'Colour options are not priced colour/material/size variant combinations.',
  'Shipping policy is absent from source product data.',
  ...(p.images.some(i => uses.get(i) > 1) ? ['Photos shared with other products; verify actual product identity.'] : []),
  ...(new Set(p.images).size < p.images.length ? ['Gallery contains repeated photographs. Imported once per distinct file.'] : []),
  ...(p.rating || p.reviews ? ['Legacy rating/review claims are not imported as verified reviews.'] : []),
]}));
for (const p of items) for (const image of p.images) {
  if (!/^\/img\/[a-zA-Z0-9_-]+\.(jpg|jpeg|png|webp)$/.test(image) || !fs.existsSync('apps/api/public' + image)) throw new Error('Unverified source image path: ' + image);
}
const result = JSON.stringify({ schema: 1, source, sha256: createHash('sha256').update(raw).digest('hex'), items }, null, 2) + '\n';
const path = 'platform/resources/data/product-import-source.json';
if (process.argv.includes('--check')) { if (fs.readFileSync(path, 'utf8') !== result) throw new Error('Product import snapshot is stale'); }
else fs.writeFileSync(path, result);
console.log(`Reviewable product source: ${items.length} records, ${uses.size} distinct files; no native publication or stock verification.`);

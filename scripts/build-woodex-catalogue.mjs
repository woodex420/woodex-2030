// The real Woodex catalogue in the form the Laravel admin imports.
//
// apps/api/catalog-data.json is the catalogue of woodexfurniture.pk as captured on 16 November 2026 and passed
// through the rules of catalog/rules/ (scripts/catalog-build.mjs; docs/furniture-2030/catalogue/IMPORT-REPORT.md).
// platform/ is deployed on its own, so it carries its own copy: platform/resources/data/woodex-catalogue.json.
//
//   node scripts/build-woodex-catalogue.mjs           write the copy
//   node scripts/build-woodex-catalogue.mjs --check   fail when the copy is not what the catalogue gives (CI)
import fs from 'node:fs';
import { createHash } from 'node:crypto';

const source = 'apps/api/catalog-data.json';
const raw = fs.readFileSync(source);
const data = JSON.parse(raw);
const taxonomy = JSON.parse(fs.readFileSync('platform/resources/data/commerce-taxonomy.json', 'utf8'));

// Categories the Laravel taxonomy already installs (database migration install_office_home_catalogue_taxonomy).
const known = new Set();
for (const segment of taxonomy.segments) for (const group of segment.groups) { known.add(group.id); for (const child of group.children) known.add(child.id); }
const alias = (slug) => taxonomy.aliases[slug] ?? slug;

// Categories the live shop sells under and the taxonomy does not have yet. The import creates them, switched off.
const names = {
  'ceo-tables': 'CEO Tables', 'conference-tables': 'Conference Tables', 'discussion-tables': 'Discussion Tables', 'center-tables': 'Center Tables',
  'executive-chairs': 'Executive Chairs', 'manager-chairs': 'Manager Chairs', 'staff-chairs': 'Staff Chairs', 'visitor-chairs': 'Visitor Chairs',
};
const added = new Map();
const skus = new Set();
for (const product of data.products) {
  if (!/^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(product.id) || product.id.length > 140) throw new Error('Not an address the admin accepts: ' + product.id);
  if (!product.sku || skus.has(product.sku)) throw new Error('Missing or repeated SKU: ' + product.id);
  skus.add(product.sku);
  if (!Number.isInteger(product.price) || product.price < 0 || (product.priceOnRequest && product.price !== 0)) throw new Error('Price is not whole rupees: ' + product.id);
  if (!known.has(alias(product.category))) throw new Error('Category not in the taxonomy: ' + product.category);
  if (product.subcategory && !known.has(alias(product.subcategory))) {
    if (!names[product.subcategory]) throw new Error('Sub-category without a name: ' + product.subcategory);
    added.set(product.subcategory, { slug: product.subcategory, name: names[product.subcategory], parent: alias(product.category) });
  }
}

const result = JSON.stringify({
  schema: 1,
  source,
  sha256: createHash('sha256').update(raw).digest('hex'),
  dataset: data.dataset,
  site: data.source.site,
  capturedOn: data.source.capturedOn,
  counts: data.counts,
  categories: [...added.values()].sort((a, b) => a.parent.localeCompare(b.parent) || a.slug.localeCompare(b.slug)),
  items: data.products,
}, null, 2) + '\n';

const path = 'platform/resources/data/woodex-catalogue.json';
if (process.argv.includes('--check')) {
  if (fs.readFileSync(path, 'utf8') !== result) throw new Error('platform/resources/data/woodex-catalogue.json is stale: run node scripts/build-woodex-catalogue.mjs');
} else fs.writeFileSync(path, result);
console.log(`Woodex catalogue for the admin: ${data.products.length} products, ${data.counts.priceOnRequest} on request, ${data.counts.needingReview} with a point to check, ${added.size} categories to add.`);

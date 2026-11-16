import fs from 'node:fs';
import path from 'node:path';
const data = JSON.parse(fs.readFileSync('apps/api/seed-data.json', 'utf8'));
const products = data.products;
const ids = new Set(); const problems = []; const images = new Map();
for (const product of products) {
  if (!product.id || ids.has(product.id)) problems.push(`Missing or duplicate product ID: ${product.id}`);
  ids.add(product.id);
  if (!Number.isFinite(product.price) || product.price < 0) problems.push(`Invalid price: ${product.id}`);
  for (const image of product.images ?? []) {
    if (!image.startsWith('/img/') || image.includes('..') || !fs.existsSync(path.join('apps/api/public', image))) problems.push(`Missing/unsafe image: ${product.id}: ${image}`);
    if (!images.has(image)) images.set(image, new Set());
    images.get(image).add(product.id);
  }
}
const report = {
  source: 'apps/api/seed-data.json', products: products.length,
  categories: [...new Set(products.map(p => p.category))].sort(),
  subcategories: [...new Set(products.map(p => p.subcategory).filter(Boolean))].sort(),
  uniqueImagePaths: images.size,
  productsWithRepeatedGalleryImages: products.filter(p => new Set(p.images).size < p.images.length).map(p => p.id),
  imagesSharedByMultipleProducts: [...images].filter(([, users]) => users.size > 1).map(([image, users]) => ({ image, products: users.size })),
  productsWithColourOptions: products.filter(p => p.colors?.length).length,
  warning: 'File existence does not verify product-photo identity or variant pricing. Ratings/review counts are not approved migration facts. No import or publication performed.',
  problems,
};
console.log(JSON.stringify(report, null, 2));
if (problems.length) process.exitCode = 1;

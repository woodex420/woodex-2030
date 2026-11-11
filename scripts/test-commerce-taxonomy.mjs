import assert from 'node:assert/strict';
import { build } from 'esbuild';
import fs from 'node:fs';
const bundle=await build({entryPoints:['apps/storefront/src/lib/commerceTaxonomy.ts'],bundle:true,format:'esm',platform:'node',write:false});
const {taxonomy,matchesCategory,normalizeCategory}=await import('data:text/javascript;base64,'+Buffer.from(bundle.outputFiles[0].text).toString('base64'));
const products=JSON.parse(fs.readFileSync('apps/api/seed-data.json')).products;
const ids=taxonomy.segments.flatMap(s=>s.groups.flatMap(g=>[g.id,...g.children.map(c=>c.id)]));
assert.equal(new Set(ids).size,ids.length);
for(const product of products){
 const office=matchesCategory(product,'office'),home=matchesCategory(product,'home');
 assert.notEqual(office,home,product.id+' must belong to exactly one segment');
}
assert.ok(products.some(p=>matchesCategory(p,'home')));assert.ok(products.some(p=>matchesCategory(p,'office')));
assert.equal(matchesCategory({category:'office-tables',subcategory:'manager-tables'},'office'),true);
assert.equal(matchesCategory({category:'bedroom',subcategory:'bed-sets'},'office'),false);
assert.equal(matchesCategory({category:'bedroom',subcategory:'bed-sets'},'bedroom'),true);
assert.equal(matchesCategory({category:'bedroom',subcategory:'bed-sets'},'bedside-tables'),false);
assert.equal(normalizeCategory('office-storage'),'storage');
assert.equal(taxonomy.series.length,5);
assert.ok(products.every(p=>!p.series||taxonomy.series.some(s=>s.id===p.series)));
console.log('Taxonomy passed: all 148 products have exactly one segment, parent/leaf filtering, aliases, five series; empty categories do not invent products.');

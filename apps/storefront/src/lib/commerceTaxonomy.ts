import taxonomy from '../../../../platform/resources/data/commerce-taxonomy.json';
import { nativeCatalogue, nativeCategory } from './nativeCategories';
export { taxonomy };
export function categorySegments() {
  const native = nativeCatalogue();
  if (!native) return taxonomy.segments;
  const children = (id: number) => native.categories.filter(c => c.parent_id === id);
  return native.categories.filter(c => c.parent_id === null).map(c => ({ id: c.slug === 'office-furniture' ? 'office' : c.slug === 'home-furniture' ? 'home' : c.slug, slug: c.slug, name: c.name, groups: children(c.id).map(g => ({ id: g.slug, name: g.name, children: children(g.id).map(leaf => ({ id: leaf.slug, name: leaf.name })) })) }));
}
export const collectionList = () => nativeCatalogue()?.series ?? taxonomy.series;
export const normalizeCategory = (id: string) => (taxonomy.aliases as Record<string, string>)[id] || id;
export function categoryIds(id: string): string[] {
  const native = nativeCatalogue();
  if (native) {
    const root = nativeCategory(id) ?? native.categories.find(c => normalizeCategory(c.slug) === normalizeCategory(id));
    if (!root) return [];
    const ids = [root.id];
    for (let i = 0; i < ids.length; i++) {
      native.categories.filter(c => c.parent_id === ids[i]).forEach(c => { if (!ids.includes(c.id)) ids.push(c.id); });
    }
    return native.categories.filter(c => ids.includes(c.id)).map(c => normalizeCategory(c.slug));
  }
  id = normalizeCategory(id);
  const segment = taxonomy.segments.find(s => s.id === id || s.slug === id);
  if (segment) return segment.groups.flatMap(g => [g.id, ...g.children.map(c => c.id)]);
  const group = taxonomy.segments.flatMap(s => s.groups).find(g => g.id === id);
  return group ? [group.id, ...group.children.map(c => c.id)] : [id];
}
export function matchesCategory(product: { category: string; subcategory?: string }, id: string): boolean {
  if (id === 'all') return true;
  const ids = categoryIds(id);
  return ids.includes(normalizeCategory(product.category)) || ids.includes(normalizeCategory(product.subcategory || ''));
}
export const categoryTree = (segment: string) => (categorySegments().find(s => s.id === segment)?.groups ?? []).map(g => ({
  id: g.id, label: g.name, children: g.children.map(c => ({ id: c.id, label: c.name })),
}));

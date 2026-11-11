import taxonomy from '../../../../platform/resources/data/commerce-taxonomy.json';
export { taxonomy };
export const normalizeCategory = (id: string) => (taxonomy.aliases as Record<string, string>)[id] || id;
export function categoryIds(id: string): string[] {
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
export const categoryTree = (segment: string) => taxonomy.segments.find(s => s.id === segment)!.groups.map(g => ({
  id: g.id, label: g.name, children: g.children.map(c => ({ id: c.id, label: c.name })),
}));

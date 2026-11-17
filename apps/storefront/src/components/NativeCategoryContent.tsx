import '../../../../platform/resources/data/category-content.css';
import { useEffect } from 'react';
import type { CategoryPageContent } from '@/lib/nativeCategories';
import { products } from '@/data/products';
import ProductCard from '@/components/shop/ProductCard';

/** The published content contract: server-sanitized formatted text, escaped React prose, existing product cards. */
export function NativeCategoryContent({ content: c, placement }: { content: CategoryPageContent; placement: 'top' | 'bottom' }) {
  useEffect(() => {
    if (placement !== 'top') return;
    const oldTitle = document.title;
    document.title = c.seo_title;
    const selectors = [
      ['name', 'description', c.seo_description], ['name', 'robots', c.allow_indexing ? 'index,follow' : 'noindex,nofollow'],
      ['property', 'og:title', c.og_title || c.seo_title], ['property', 'og:description', c.og_description || c.seo_description],
    ];
    const undo = selectors.map(([attr, key, value]) => {
      let el = document.querySelector(`meta[${attr}="${key}"]`); const created = !el; const old = el?.getAttribute('content');
      if (!el) { el = document.createElement('meta'); el.setAttribute(attr, key); document.head.appendChild(el); }
      el.setAttribute('content', value);
      return () => { if (created) el?.remove(); else el?.setAttribute('content', old || ''); };
    });
    let canonical = document.querySelector('link[rel="canonical"]'); const created = !canonical; const old = canonical?.getAttribute('href');
    if (!canonical) { canonical = document.createElement('link'); canonical.setAttribute('rel', 'canonical'); document.head.appendChild(canonical); }
    canonical.setAttribute('href', new URL(c.canonical_path, window.location.origin).href);
    return () => { document.title = oldTitle; undo.forEach(fn => fn()); if(created) canonical?.remove(); else canonical?.setAttribute('href', old || ''); };
  }, [c, placement]);
  if (placement === 'top') return <>
    <div className="native-category-content container mx-auto px-4 py-8" dangerouslySetInnerHTML={{ __html: c.top_html }} />
    {c.carousel_mode !== 'off' && <section className="container mx-auto px-4 py-8"><h2 className="mb-4 text-2xl font-bold">{c.carousel_heading}</h2><div className="flex snap-x gap-4 overflow-x-auto pb-4">{c.featured_ids.map(id => products.find(p => p.id === id)).filter(p => !!p).map(p => <div className="w-72 shrink-0 snap-start" key={p.id}><ProductCard product={p} /></div>)}</div>{c.featured_ids.length === 0 && <p>No featured products selected.</p>}</section>}
  </>;
  return <div className="native-category-content" dangerouslySetInnerHTML={{ __html: c.bottom_html }} />;
}

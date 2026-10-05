import { useEffect } from "react";
import { ChevronDown } from "lucide-react";
import { useState } from "react";
import type { SeoBlock } from "@/data/seoContent";
import { buildFaqJsonLd, buildCollectionJsonLd } from "@/data/seoContent";

interface Props {
  seo: SeoBlock;
  /** absolute path used for canonical/og:url and CollectionPage @id */
  path: string;
  /** Hide intro paragraph (e.g. when caller already shows it in hero) */
  hideIntro?: boolean;
  /** Hide FAQ block */
  hideFaqs?: boolean;
}

/**
 * Renders SEO + AIO long-form content for a category or series page.
 *
 * - Updates <title> and <meta description>
 * - Injects CollectionPage + FAQPage JSON-LD
 * - Renders H2 sections + bullet lists + accordion FAQs
 */
const SeoContentBlock = ({ seo, path, hideIntro, hideFaqs }: Props) => {
  const [openFaq, setOpenFaq] = useState<number | null>(null);
  const url = `https://woodex-reimagined.lovable.app${path}`;

  useEffect(() => {
    document.title = seo.title;
    const m = document.querySelector('meta[name="description"]');
    if (m) m.setAttribute("content", seo.metaDescription);

    // canonical
    let canon = document.querySelector('link[rel="canonical"]');
    if (!canon) {
      canon = document.createElement("link");
      canon.setAttribute("rel", "canonical");
      document.head.appendChild(canon);
    }
    canon.setAttribute("href", url);

    // JSON-LD (CollectionPage + FAQ)
    const id = "seo-jsonld-block";
    document.getElementById(id)?.remove();
    const s = document.createElement("script");
    s.id = id;
    s.type = "application/ld+json";
    s.text = JSON.stringify([
      buildCollectionJsonLd(seo.title, seo.metaDescription, url),
      buildFaqJsonLd(seo.faqs),
    ]);
    document.head.appendChild(s);

    return () => {
      document.getElementById(id)?.remove();
    };
  }, [seo, url]);

  return (
    <div className="space-y-10">
      {!hideIntro && (
        <div className="prose max-w-3xl">
          <p className="text-base text-muted-foreground leading-relaxed">{seo.intro}</p>
        </div>
      )}

      <div className="grid md:grid-cols-2 gap-x-10 gap-y-8">
        {seo.sections.map((sec) => (
          <section key={sec.h2}>
            <h2 className="text-lg font-bold mb-2 text-foreground">{sec.h2}</h2>
            <p className="text-sm text-muted-foreground leading-relaxed mb-3">{sec.body}</p>
            {sec.bullets && (
              <ul className="space-y-1.5">
                {sec.bullets.map((b) => (
                  <li key={b} className="text-sm text-muted-foreground flex gap-2">
                    <span className="text-accent mt-1.5">•</span>
                    <span>{b}</span>
                  </li>
                ))}
              </ul>
            )}
          </section>
        ))}
      </div>

      {!hideFaqs && seo.faqs.length > 0 && (
        <div className="border-t pt-8">
          <h2 className="text-2xl font-bold mb-5">Frequently Asked Questions</h2>
          <div className="space-y-2">
            {seo.faqs.map((faq, i) => (
              <div key={i} className="border border-border rounded-sm overflow-hidden">
                <button
                  onClick={() => setOpenFaq(openFaq === i ? null : i)}
                  className="w-full flex items-center justify-between px-5 py-3.5 text-left hover:bg-section-light transition-colors"
                >
                  <span className="font-semibold text-sm">{faq.q}</span>
                  <ChevronDown
                    className={`h-4 w-4 text-muted-foreground transition-transform flex-shrink-0 ml-4 ${openFaq === i ? "rotate-180" : ""}`}
                  />
                </button>
                {openFaq === i && (
                  <div className="px-5 pb-4 text-sm text-muted-foreground leading-relaxed border-t border-border bg-section-light">
                    <div className="pt-3">{faq.a}</div>
                  </div>
                )}
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
};

export default SeoContentBlock;

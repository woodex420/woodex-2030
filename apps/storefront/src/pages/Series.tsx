import { useState, useEffect } from "react";
import seriesHero from "@/assets/series-hero.jpg";
import { Link } from "react-router-dom";
import { ArrowRight } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import ProductCard from "@/components/shop/ProductCard";
import execImg from "@/assets/series-executive.jpg";
import modernImg from "@/assets/series-modern.jpg";
import ecoImg from "@/assets/series-eco.jpg";
import novaImg from "@/assets/series-nova.jpg";
import { seriesList, getProductsBySeries } from "@/data/products";
import SeoContentBlock from "@/components/SeoContentBlock";
import { getSeriesSeo } from "@/data/seoContent";

const seriesImages: Record<string, string> = {
  "ek-series": ecoImg,
  "infinity-series": modernImg,
  "woodex-series": execImg,
  "cubicle-series": modernImg,
  "nova-series": novaImg,
};

const Series = () => {
  const [activeTab, setActiveTab] = useState("woodex-series");
  const tabProducts = getProductsBySeries(activeTab).slice(0, 4);
  const activeSeo = getSeriesSeo(activeTab);

  useEffect(() => {
    document.title = "Furniture Series in Pakistan — Ek, Infinity, Woodex, Cubicle & Nova | WOODEX";
    const m = document.querySelector('meta[name="description"]');
    if (m) m.setAttribute("content", "Explore WOODEX's 5 furniture series in Pakistan: Ek (budget), Infinity (modular), Woodex (premium), Cubicle (privacy), Nova (minimal). Made in Lahore, delivered nationwide.");
  }, []);

  return (
    <div className="min-h-screen flex flex-col">
      <Header />
      <main className="flex-1">
        {/* Hero */}
        <section className="relative bg-primary text-primary-foreground py-20 overflow-hidden">
          <img src={seriesHero} alt="WOODEX furniture series" className="absolute inset-0 w-full h-full object-cover opacity-30" />
          <div className="absolute inset-0 bg-gradient-to-r from-primary via-primary/85 to-primary/40" />
          <div className="container mx-auto px-4 relative">
            <p className="text-accent text-xs font-bold uppercase tracking-widest mb-2">Collections</p>
            <h1 className="text-4xl lg:text-5xl font-black mb-3">Furniture Series</h1>
            <p className="text-primary-foreground/75 max-w-xl">
              Four distinct collections designed for different workspace needs, budgets, and aesthetics — all made in Pakistan.
            </p>
          </div>
        </section>

        {/* Series Tabs */}
        <section className="border-b bg-background sticky top-16 z-20">
          <div className="container mx-auto px-4">
            <div className="flex gap-0 overflow-x-auto">
              {seriesList.map((s) => (
                <button
                  key={s.id}
                  onClick={() => setActiveTab(s.id)}
                  className={`px-5 py-4 text-sm font-semibold whitespace-nowrap border-b-2 transition-colors ${
                    activeTab === s.id
                      ? "border-accent text-accent"
                      : "border-transparent text-muted-foreground hover:text-foreground"
                  }`}
                >
                  {s.name}
                </button>
              ))}
            </div>
          </div>
        </section>

        {/* Active Series Detail */}
        {seriesList.map((s) => activeTab === s.id && (
          <section key={s.id} className="py-16">
            <div className="container mx-auto px-4">
              <div className="grid lg:grid-cols-2 gap-12 items-center mb-14">
                <div className="relative overflow-hidden rounded-sm">
                  <img src={seriesImages[s.id] || execImg} alt={s.name} className="w-full h-80 object-cover" />
                  <div className="absolute top-4 left-4">
                    <Badge className="bg-accent text-accent-foreground">{s.badge}</Badge>
                  </div>
                  <div className="absolute bottom-4 left-4">
                    <span className="text-xs text-white/80 bg-primary/60 px-2 py-1 rounded">{s.products} products in this series</span>
                  </div>
                </div>
                <div>
                  <div className="w-12 h-1 bg-accent mb-5" />
                  <h2 className="text-3xl lg:text-4xl font-bold mb-2">{s.name}</h2>
                  <p className="text-accent font-semibold mb-4">{s.tagline}</p>
                  <p className="text-muted-foreground leading-relaxed mb-6">{s.description}</p>
                  <ul className="grid grid-cols-2 gap-3 mb-7">
                    {s.features.map((f) => (
                      <li key={f} className="flex items-center gap-2 text-sm text-muted-foreground">
                        <div className="w-1.5 h-1.5 rounded-full bg-accent flex-shrink-0" />
                        {f}
                      </li>
                    ))}
                  </ul>
                  <div className="flex gap-3">
                    <Button className="bg-accent hover:bg-hon-green-dark text-accent-foreground" asChild>
                      <Link to={`/series/${s.id}`}>
                        View Full Series <ArrowRight className="h-4 w-4 ml-2" />
                      </Link>
                    </Button>
                    <Button variant="outline" className="border-accent text-accent hover:bg-accent hover:text-accent-foreground" asChild>
                      <Link to="/quotation">Request Quote</Link>
                    </Button>
                  </div>
                </div>
              </div>

              {/* Products from this series */}
              {tabProducts.length > 0 && (
                <div>
                  <div className="flex items-center justify-between mb-6">
                    <h3 className="text-xl font-bold">Featured {s.name} Products</h3>
                    <Button variant="ghost" className="text-accent hover:text-hon-green-dark" asChild>
                      <Link to={`/series/${s.id}`}>View All <ArrowRight className="h-4 w-4 ml-1" /></Link>
                    </Button>
                  </div>
                  <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-5">
                    {tabProducts.map((p) => <ProductCard key={p.id} product={p} />)}
                  </div>
                </div>
              )}
            </div>
          </section>
        ))}

        {/* Active series SEO + AIO content */}
        {activeSeo && (
          <section className="py-14 border-t bg-background">
            <div className="container mx-auto px-4">
              <SeoContentBlock seo={activeSeo} path={`/series#${activeTab}`} />
            </div>
          </section>
        )}


        {/* All Series Overview */}
        <section className="py-14 bg-section-light border-t">
          <div className="container mx-auto px-4">
            <h2 className="text-2xl font-bold mb-8">All WOODEX Series</h2>
            <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-5">
              {seriesList.map((s) => (
                <Link
                  key={s.id}
                  to={`/series/${s.id}`}
                  className="group p-6 border border-border bg-background rounded-sm hover:border-accent hover:shadow-md transition-all"
                >
                  <div className="w-8 h-1 bg-accent mb-4 group-hover:w-14 transition-all duration-300" />
                  <Badge className="bg-hon-green-pale text-accent text-xs mb-3">{s.badge}</Badge>
                  <h3 className="font-bold text-lg mb-1 group-hover:text-accent transition-colors">{s.name}</h3>
                  <p className="text-sm text-muted-foreground mb-3">{s.tagline}</p>
                  <p className="text-xs text-accent font-semibold">{s.products} products</p>
                </Link>
              ))}
            </div>
          </div>
        </section>

        {/* CTA */}
        <section className="py-14 bg-accent text-accent-foreground">
          <div className="container mx-auto px-4 text-center">
            <h2 className="text-3xl font-bold mb-3">Can't Find Your Perfect Series?</h2>
            <p className="mb-7 opacity-85 max-w-md mx-auto">We create completely custom collections for enterprise clients. Tell us your vision.</p>
            <Button size="lg" className="bg-primary hover:bg-primary/90 text-primary-foreground px-8" asChild>
              <Link to="/quotation">Request Custom Series</Link>
            </Button>
          </div>
        </section>
      </main>
      <Footer />
    </div>
  );
};

export default Series;

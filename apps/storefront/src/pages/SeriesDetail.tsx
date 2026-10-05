import { useParams, Link } from "react-router-dom";
import { ArrowRight, CheckCircle2 } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import ProductCard from "@/components/shop/ProductCard";
import { seriesList, getProductsBySeries } from "@/data/products";
import { useEffect } from "react";
import SeoContentBlock from "@/components/SeoContentBlock";
import { getSeriesSeo } from "@/data/seoContent";
import execImg from "@/assets/series-executive.jpg";
import modernImg from "@/assets/series-modern.jpg";
import ecoImg from "@/assets/series-eco.jpg";

const seriesImages: Record<string, string> = {
  "ek-series": ecoImg,
  "infinity-series": modernImg,
  "woodex-series": execImg,
  "cubicle-series": modernImg,
};

const SeriesDetail = () => {
  const { seriesId } = useParams<{ seriesId: string }>();
  const series = seriesList.find((s) => s.id === seriesId);
  const seriesProducts = getProductsBySeries(seriesId || "");
  const seo = series ? getSeriesSeo(series.id) : undefined;

  useEffect(() => {
    if (series && !seo) {
      document.title = `${series.name} — WOODEX Pakistan`;
    }
  }, [series, seo]);

  if (!series) {
    return (
      <div className="min-h-screen flex flex-col">
        <Header />
        <main className="flex-1 flex items-center justify-center">
          <div className="text-center">
            <h1 className="text-2xl font-bold mb-4">Series Not Found</h1>
            <Button asChild><Link to="/series">View All Series</Link></Button>
          </div>
        </main>
        <Footer />
      </div>
    );
  }

  return (
    <div className="min-h-screen flex flex-col">
      <Header />
      <main className="flex-1">
        {/* Hero */}
        <section className="relative min-h-[380px] bg-primary text-primary-foreground overflow-hidden flex items-center">
          <img
            src={seriesImages[series.id] || execImg}
            alt={series.name}
            className="absolute inset-0 w-full h-full object-cover opacity-25"
          />
          <div className="container mx-auto px-4 relative z-10 py-16">
            <div className="flex items-center gap-2 text-sm mb-4">
              <Link to="/series" className="text-accent hover:underline">Series</Link>
              <ArrowRight className="h-3 w-3 text-primary-foreground/50" />
              <span className="text-primary-foreground/70">{series.name}</span>
            </div>
            <Badge className="bg-accent text-accent-foreground mb-4">{series.badge}</Badge>
            <h1 className="text-4xl lg:text-6xl font-black mb-3 leading-tight">{series.name}</h1>
            <p className="text-accent font-semibold text-lg mb-4">{series.tagline}</p>
            <p className="text-primary-foreground/75 max-w-xl text-base leading-relaxed mb-8">{series.description}</p>
            <div className="flex flex-wrap gap-4">
              <Button size="lg" className="bg-accent hover:bg-hon-green-dark text-accent-foreground px-8" asChild>
                <Link to="/quotation">Request Quote</Link>
              </Button>
              <Button size="lg" variant="outline" className="border-primary-foreground/40 text-primary-foreground hover:bg-primary-foreground/10" asChild>
                <Link to="/contact">Talk to an Expert</Link>
              </Button>
            </div>
          </div>
        </section>

        {/* Features */}
        <section className="py-10 bg-accent text-accent-foreground">
          <div className="container mx-auto px-4">
            <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-6">
              {series.features.map((f) => (
                <div key={f} className="flex items-center gap-3">
                  <CheckCircle2 className="h-5 w-5 flex-shrink-0" />
                  <span className="font-medium text-sm">{f}</span>
                </div>
              ))}
            </div>
          </div>
        </section>

        {/* Products */}
        <section className="py-16">
          <div className="container mx-auto px-4">
            <div className="flex items-end justify-between mb-10">
              <div>
                <div className="w-12 h-1 bg-accent mb-4" />
                <h2 className="text-3xl font-bold">{series.name} Products</h2>
                <p className="text-muted-foreground mt-2">{seriesProducts.length} products in this collection</p>
              </div>
              <Button variant="outline" className="border-accent text-accent hover:bg-accent hover:text-accent-foreground" asChild>
                <Link to="/shop">All Products</Link>
              </Button>
            </div>

            {seriesProducts.length > 0 ? (
              <div className="grid sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-6">
                {seriesProducts.map((product) => (
                  <ProductCard key={product.id} product={product} />
                ))}
              </div>
            ) : (
              <div className="text-center py-16 border border-dashed border-border rounded-sm">
                <p className="text-muted-foreground mb-4">Products coming soon to this series</p>
                <Button asChild className="bg-accent hover:bg-hon-green-dark text-accent-foreground">
                  <Link to="/shop">Browse All Products</Link>
                </Button>
              </div>
            )}
          </div>
        </section>

        {/* SEO + AIO content */}
        {seo && (
          <section className="py-14 border-t bg-background">
            <div className="container mx-auto px-4">
              <SeoContentBlock seo={seo} path={`/series/${series.id}`} />
            </div>
          </section>
        )}

        {/* Other Series */}
        <section className="py-14 bg-section-light border-t">
          <div className="container mx-auto px-4">
            <h2 className="text-2xl font-bold mb-8">Explore Other Series</h2>
            <div className="grid sm:grid-cols-2 lg:grid-cols-4 gap-4">
              {seriesList.filter((s) => s.id !== series.id).map((s) => (
                <Link
                  key={s.id}
                  to={`/series/${s.id}`}
                  className="group p-5 border border-border bg-background rounded-sm hover:border-accent hover:shadow-md transition-all"
                >
                  <div className="w-8 h-1 bg-accent mb-3 group-hover:w-14 transition-all duration-300" />
                  <h3 className="font-bold mb-1 group-hover:text-accent transition-colors">{s.name}</h3>
                  <p className="text-xs text-muted-foreground">{s.tagline}</p>
                  <Badge className="mt-3 bg-hon-green-pale text-accent text-xs">{s.badge}</Badge>
                </Link>
              ))}
            </div>
          </div>
        </section>

        {/* CTA */}
        <section className="py-14 bg-primary text-primary-foreground">
          <div className="container mx-auto px-4 text-center">
            <h2 className="text-3xl font-bold mb-3">Interested in the {series.name}?</h2>
            <p className="text-primary-foreground/75 mb-7 max-w-md mx-auto">
              Get a personalized quote tailored to your space and requirements.
            </p>
            <Button className="bg-accent hover:bg-hon-green-dark text-accent-foreground px-8" asChild>
              <Link to="/quotation">Get Free Quote</Link>
            </Button>
          </div>
        </section>
      </main>
      <Footer />
    </div>
  );
};

export default SeriesDetail;

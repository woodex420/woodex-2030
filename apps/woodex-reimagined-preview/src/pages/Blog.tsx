import { useEffect } from "react";
import { Link } from "react-router-dom";
import { Calendar, Clock, ArrowRight, User } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import { blogPosts } from "@/data/blogPosts";
import blogErgonomic from "@/assets/blog-ergonomic.jpg";

const categories = ["All", "Ergonomics", "Workspace Design", "Sustainability", "Interior Design", "Case Study"];

const Blog = () => {
  useEffect(() => {
    document.title = "Blog — WOODEX Pakistan | Office Design Tips, Trends & Case Studies";
  }, []);

  const featured = blogPosts.find((p) => p.featured);
  const rest = blogPosts.filter((p) => !p.featured);

  return (
    <div className="min-h-screen flex flex-col">
      <Header />

      <main className="flex-1">
        {/* Hero */}
        <section className="relative h-[420px] overflow-hidden bg-primary">
          <img src={blogErgonomic} alt="WOODEX Blog" className="w-full h-full object-cover opacity-30" />
          <div className="absolute inset-0 bg-gradient-to-r from-primary via-primary/85 to-primary/40" />
          <div className="absolute inset-0 flex items-center">
            <div className="container mx-auto px-4">
              <nav className="text-xs text-primary-foreground/60 mb-4 flex items-center gap-2">
                <Link to="/" className="hover:text-accent transition-colors">Home</Link>
                <span>/</span>
                <span className="text-accent">Blog</span>
              </nav>
              <p className="text-accent text-xs font-bold uppercase tracking-[0.25em] mb-3">Ideas & Inspiration</p>
              <h1 className="text-5xl lg:text-6xl font-black text-primary-foreground mb-4 leading-[1.05]">WOODEX Journal</h1>
              <p className="text-primary-foreground/80 max-w-2xl text-base lg:text-lg leading-relaxed">
                Expert insights on office design, ergonomics, sustainability, and workspace productivity from Pakistan's leading furniture manufacturer.
              </p>
              <div className="flex gap-6 mt-6 text-xs text-primary-foreground/60 uppercase tracking-widest">
                <span><span className="text-accent font-bold text-base">{blogPosts.length}</span> &nbsp;Articles</span>
                <span><span className="text-accent font-bold text-base">{categories.length - 1}</span> &nbsp;Categories</span>
                <span><span className="text-accent font-bold text-base">Weekly</span> &nbsp;Updates</span>
              </div>
            </div>
          </div>
        </section>

        {/* Category Filter */}
        <section className="py-4 bg-background border-b sticky top-16 z-20 shadow-sm">
          <div className="container mx-auto px-4">
            <div className="flex gap-2 overflow-x-auto pb-1 scrollbar-hide">
              {categories.map((cat) => (
                <Button
                  key={cat}
                  variant={cat === "All" ? "default" : "outline"}
                  size="sm"
                  className={cat === "All" ? "bg-accent text-accent-foreground hover:bg-hon-green-dark whitespace-nowrap" : "border-border hover:border-accent hover:text-accent whitespace-nowrap"}
                >
                  {cat}
                </Button>
              ))}
            </div>
          </div>
        </section>

        {/* Featured Post */}
        {featured && (
          <section className="py-16 bg-background">
            <div className="container mx-auto px-4">
              <div className="flex items-center gap-3 mb-8">
                <div className="w-10 h-0.5 bg-accent" />
                <p className="text-xs font-bold uppercase tracking-[0.2em] text-accent">Editor's Pick</p>
              </div>
              <Link to={`/blog/${featured.id}`} className="group grid lg:grid-cols-5 gap-10 items-center">
                <div className="lg:col-span-3 aspect-[16/10] rounded-sm overflow-hidden relative">
                  <img src={featured.image} alt={featured.title} className="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700" />
                  <div className="absolute top-4 left-4">
                    <Badge className="bg-accent text-accent-foreground shadow-md">{featured.category}</Badge>
                  </div>
                </div>
                <div className="lg:col-span-2">
                  <h2 className="text-3xl lg:text-4xl font-bold mb-5 leading-[1.15] group-hover:text-accent transition-colors">{featured.title}</h2>
                  <p className="text-muted-foreground leading-relaxed mb-6 text-base">{featured.excerpt}</p>
                  <div className="flex items-center gap-5 text-xs text-muted-foreground mb-7 pb-7 border-b border-border">
                    <span className="flex items-center gap-1.5"><User className="h-3.5 w-3.5 text-accent" />{featured.author}</span>
                    <span className="flex items-center gap-1.5"><Calendar className="h-3.5 w-3.5 text-accent" />{featured.date}</span>
                    <span className="flex items-center gap-1.5"><Clock className="h-3.5 w-3.5 text-accent" />{featured.readTime}</span>
                  </div>
                  <Button className="bg-accent hover:bg-hon-green-dark text-accent-foreground px-7 group/btn">
                    Read Full Article <ArrowRight className="h-4 w-4 ml-2 group-hover/btn:translate-x-1 transition-transform" />
                  </Button>
                </div>
              </Link>
            </div>
          </section>
        )}

        {/* Posts Grid */}
        <section className="py-16 bg-section-light border-t">
          <div className="container mx-auto px-4">
            <div className="flex items-end justify-between mb-10 flex-wrap gap-4">
              <div>
                <div className="flex items-center gap-3 mb-3">
                  <div className="w-10 h-0.5 bg-accent" />
                  <p className="text-xs font-bold uppercase tracking-[0.2em] text-accent">From the Journal</p>
                </div>
                <h2 className="text-3xl font-bold">Latest Articles</h2>
              </div>
              <p className="text-sm text-muted-foreground">Showing {rest.length} of {blogPosts.length} articles</p>
            </div>
            <div className="grid md:grid-cols-2 lg:grid-cols-3 gap-7">
              {rest.map((post) => (
                <Link to={`/blog/${post.id}`} key={post.id} className="group bg-background border border-border rounded-sm overflow-hidden hover:border-accent hover:shadow-xl hover:-translate-y-1 transition-all duration-300 flex flex-col">
                  <div className="aspect-[16/10] overflow-hidden relative">
                    <img src={post.image} alt={post.title} className="w-full h-full object-cover group-hover:scale-110 transition-transform duration-700" />
                    <div className="absolute top-3 left-3">
                      <Badge className="bg-background/95 backdrop-blur text-accent border-0 text-[10px] uppercase tracking-wider font-bold">{post.category}</Badge>
                    </div>
                    <div className="absolute bottom-3 right-3">
                      <span className="bg-primary/85 backdrop-blur text-primary-foreground text-[10px] px-2.5 py-1 rounded-sm font-semibold flex items-center gap-1">
                        <Clock className="h-2.5 w-2.5" />{post.readTime}
                      </span>
                    </div>
                  </div>
                  <div className="p-6 flex flex-col flex-1">
                    <h3 className="font-bold text-lg mb-3 group-hover:text-accent transition-colors leading-snug line-clamp-2">{post.title}</h3>
                    <p className="text-sm text-muted-foreground leading-relaxed mb-5 line-clamp-3 flex-1">{post.excerpt}</p>
                    <div className="flex items-center justify-between text-xs text-muted-foreground pt-4 border-t border-border">
                      <span className="flex items-center gap-1.5"><User className="h-3 w-3 text-accent" />{post.author}</span>
                      <span className="flex items-center gap-1.5"><Calendar className="h-3 w-3 text-accent" />{post.date}</span>
                    </div>
                    <span className="mt-4 text-accent text-xs font-bold uppercase tracking-widest flex items-center gap-1 opacity-0 group-hover:opacity-100 -translate-y-1 group-hover:translate-y-0 transition-all">
                      Read Article <ArrowRight className="h-3 w-3" />
                    </span>
                  </div>
                </Link>
              ))}
            </div>
          </div>
        </section>

        {/* Newsletter CTA */}
        <section className="py-14 bg-primary text-primary-foreground">
          <div className="container mx-auto px-4 text-center">
            <h2 className="text-3xl font-bold mb-3">Stay Updated</h2>
            <p className="text-primary-foreground/75 mb-7 max-w-md mx-auto">
              Subscribe to our newsletter for the latest office design trends, product launches, and exclusive offers.
            </p>
            <div className="flex flex-wrap gap-4 justify-center">
              <Button className="bg-accent hover:bg-accent/90 text-accent-foreground px-8" asChild>
                <Link to="/contact">Subscribe Now</Link>
              </Button>
              <Button variant="outline" className="border-primary-foreground/30 text-primary-foreground hover:bg-primary-foreground/10" asChild>
                <Link to="/shop">Browse Products</Link>
              </Button>
            </div>
          </div>
        </section>
      </main>

      <Footer />
    </div>
  );
};

export default Blog;

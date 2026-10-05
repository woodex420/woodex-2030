import { useEffect } from "react";
import { Link, useParams, Navigate } from "react-router-dom";
import { Calendar, Clock, User, ArrowLeft, ArrowRight, Share2, Tag } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import { blogPosts, getBlogPost } from "@/data/blogPosts";

const BlogPost = () => {
  const { postId } = useParams<{ postId: string }>();
  const post = postId ? getBlogPost(postId) : undefined;

  useEffect(() => {
    if (post) {
      document.title = `${post.title} — WOODEX Blog`;
      const meta =
        document.querySelector('meta[name="description"]') ??
        (() => {
          const m = document.createElement("meta");
          m.setAttribute("name", "description");
          document.head.appendChild(m);
          return m;
        })();
      meta.setAttribute("content", post.excerpt.slice(0, 158));
      window.scrollTo({ top: 0, behavior: "instant" as ScrollBehavior });
    }
  }, [post]);

  if (!post) return <Navigate to="/blog" replace />;

  const related = blogPosts.filter((p) => p.id !== post.id && p.category === post.category).slice(0, 3);
  const fallback = blogPosts.filter((p) => p.id !== post.id).slice(0, 3);
  const recommendations = related.length ? related : fallback;

  return (
    <div className="min-h-screen flex flex-col">
      <Header />

      <main className="flex-1">
        {/* Hero */}
        <section className="relative h-[480px] overflow-hidden bg-primary">
          <img src={post.image} alt={post.title} className="w-full h-full object-cover opacity-30" />
          <div className="absolute inset-0 bg-gradient-to-r from-primary via-primary/85 to-primary/40" />
          <div className="absolute inset-0 flex items-end pb-14">
            <div className="container mx-auto px-4">
              <nav className="text-xs text-primary-foreground/60 mb-4 flex items-center gap-2">
                <Link to="/" className="hover:text-accent transition-colors">Home</Link>
                <span>/</span>
                <Link to="/blog" className="hover:text-accent transition-colors">Blog</Link>
                <span>/</span>
                <span className="text-accent truncate max-w-[40ch]">{post.title}</span>
              </nav>
              <Badge className="bg-accent text-accent-foreground mb-4">{post.category}</Badge>
              <h1 className="text-3xl md:text-5xl font-black text-primary-foreground max-w-4xl leading-[1.1] mb-5">
                {post.title}
              </h1>
              <div className="flex flex-wrap items-center gap-5 text-xs text-primary-foreground/75 uppercase tracking-widest">
                <span className="flex items-center gap-1.5"><User className="h-3.5 w-3.5 text-accent" />{post.author}</span>
                <span className="flex items-center gap-1.5"><Calendar className="h-3.5 w-3.5 text-accent" />{post.date}</span>
                <span className="flex items-center gap-1.5"><Clock className="h-3.5 w-3.5 text-accent" />{post.readTime}</span>
              </div>
            </div>
          </div>
        </section>

        {/* Article */}
        <section className="py-16 bg-background">
          <div className="container mx-auto px-4 grid lg:grid-cols-12 gap-12">
            <article className="lg:col-span-8">
              <p className="text-lg text-muted-foreground leading-relaxed mb-10 pb-10 border-b border-border">
                {post.excerpt}
              </p>

              <div className="space-y-10">
                {post.content.map((block, i) => (
                  <div key={i}>
                    {block.heading && (
                      <h2 className="text-2xl md:text-3xl font-bold mb-4 leading-tight">{block.heading}</h2>
                    )}
                    {block.body.split("\n").map((para, j) => (
                      <p key={j} className="text-base text-foreground/85 leading-[1.8] mb-4 whitespace-pre-line">
                        {para}
                      </p>
                    ))}
                  </div>
                ))}
              </div>

              {/* Tags */}
              <div className="mt-12 pt-8 border-t border-border flex flex-wrap items-center gap-2">
                <Tag className="h-4 w-4 text-accent mr-1" />
                {post.tags.map((t) => (
                  <Badge key={t} variant="outline" className="border-border text-muted-foreground">{t}</Badge>
                ))}
              </div>

              {/* Author + share */}
              <div className="mt-10 p-6 bg-section-light border border-border rounded-sm flex flex-wrap items-center justify-between gap-4">
                <div className="flex items-center gap-4">
                  <div className="w-12 h-12 rounded-full bg-accent/15 flex items-center justify-center text-accent font-bold">
                    {post.author.split(" ").map((n) => n[0]).join("").slice(0, 2)}
                  </div>
                  <div>
                    <p className="text-xs uppercase tracking-widest text-muted-foreground">Written by</p>
                    <p className="font-bold">{post.author}</p>
                  </div>
                </div>
                <Button
                  variant="outline"
                  className="border-border hover:border-accent hover:text-accent"
                  onClick={() => {
                    if (navigator.share) {
                      navigator.share({ title: post.title, url: window.location.href }).catch(() => {});
                    } else {
                      navigator.clipboard.writeText(window.location.href);
                    }
                  }}
                >
                  <Share2 className="h-4 w-4 mr-2" /> Share Article
                </Button>
              </div>

              <div className="mt-10">
                <Button variant="outline" asChild className="border-border hover:border-accent hover:text-accent">
                  <Link to="/blog"><ArrowLeft className="h-4 w-4 mr-2" /> Back to all articles</Link>
                </Button>
              </div>
            </article>

            {/* Sidebar */}
            <aside className="lg:col-span-4 space-y-8">
              <div className="bg-primary text-primary-foreground p-7 rounded-sm">
                <p className="text-accent text-xs font-bold uppercase tracking-[0.2em] mb-3">Need Office Furniture?</p>
                <h3 className="text-xl font-bold mb-3 leading-snug">Get a B2B quote tailored to your workspace.</h3>
                <p className="text-primary-foreground/75 text-sm leading-relaxed mb-5">
                  Talk to WOODEX's project team for ergonomic, modular, and bespoke furniture across Pakistan.
                </p>
                <Button className="bg-accent hover:bg-hon-green-dark text-accent-foreground w-full" asChild>
                  <Link to="/quotation">Request Quote</Link>
                </Button>
              </div>

              <div className="border border-border rounded-sm p-6">
                <p className="text-xs font-bold uppercase tracking-[0.2em] text-accent mb-4">In this article</p>
                <ul className="space-y-3 text-sm">
                  {post.content.filter((b) => b.heading).map((b, i) => (
                    <li key={i} className="text-muted-foreground hover:text-accent transition-colors">
                      <span className="text-accent mr-2">0{i + 1}</span>{b.heading}
                    </li>
                  ))}
                </ul>
              </div>
            </aside>
          </div>
        </section>

        {/* Related */}
        <section className="py-16 bg-section-light border-t">
          <div className="container mx-auto px-4">
            <div className="flex items-center gap-3 mb-8">
              <div className="w-10 h-0.5 bg-accent" />
              <p className="text-xs font-bold uppercase tracking-[0.2em] text-accent">Keep Reading</p>
            </div>
            <h2 className="text-3xl font-bold mb-10">Related Articles</h2>
            <div className="grid md:grid-cols-3 gap-7">
              {recommendations.map((p) => (
                <Link
                  key={p.id}
                  to={`/blog/${p.id}`}
                  className="group bg-background border border-border rounded-sm overflow-hidden hover:border-accent hover:shadow-xl hover:-translate-y-1 transition-all duration-300 flex flex-col"
                >
                  <div className="aspect-[16/10] overflow-hidden">
                    <img src={p.image} alt={p.title} className="w-full h-full object-cover group-hover:scale-110 transition-transform duration-700" />
                  </div>
                  <div className="p-6 flex flex-col flex-1">
                    <Badge variant="outline" className="self-start mb-3 text-[10px] uppercase tracking-wider">{p.category}</Badge>
                    <h3 className="font-bold text-lg mb-3 group-hover:text-accent transition-colors leading-snug line-clamp-2">{p.title}</h3>
                    <p className="text-sm text-muted-foreground line-clamp-2 flex-1">{p.excerpt}</p>
                    <span className="mt-4 text-accent text-xs font-bold uppercase tracking-widest flex items-center gap-1">
                      Read Article <ArrowRight className="h-3 w-3" />
                    </span>
                  </div>
                </Link>
              ))}
            </div>
          </div>
        </section>
      </main>

      <Footer />
    </div>
  );
};

export default BlogPost;

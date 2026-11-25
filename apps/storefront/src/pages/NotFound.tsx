import { Link } from "react-router-dom";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import { Button } from "@/components/ui/button";
import { NotFoundMeta } from "@/components/NotFoundMeta";

/** Every address that is no page of the shop. It keeps the header and the footer, so a visitor can go on. */
const NotFound = () => (
  <div className="min-h-screen flex flex-col">
    <NotFoundMeta />
    <Header />
    <main className="flex-1 flex items-center justify-center px-4 py-20">
      <div className="text-center">
        <p className="text-accent text-xs font-bold uppercase tracking-widest mb-2">404</p>
        <h1 className="text-3xl font-bold mb-3">Page not found</h1>
        <p className="text-muted-foreground mb-6">Nothing is at this address. The page may have moved, or the link was mistyped.</p>
        <div className="flex flex-wrap justify-center gap-3">
          <Button asChild><Link to="/">Home</Link></Button>
          <Button variant="outline" asChild><Link to="/shop">Browse the products</Link></Button>
        </div>
      </div>
    </main>
    <Footer />
  </div>
);

export default NotFound;

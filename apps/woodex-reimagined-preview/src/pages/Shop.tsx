import { useState, useMemo, useEffect } from "react";
import { useSearchParams } from "react-router-dom";
import Header from "@/components/Header";
import Footer from "@/components/Footer";
import ProductCard from "@/components/shop/ProductCard";
import ProductListItem from "@/components/shop/ProductListItem";
import { products, getProductsByCategory, sortProducts, getCategoryImages } from "@/data/products";
import { Input } from "@/components/ui/input";
import { Button } from "@/components/ui/button";
import { Search, Grid3X3, LayoutList, SlidersHorizontal, ChevronDown, ChevronRight } from "lucide-react";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import SeoContentBlock from "@/components/SeoContentBlock";
import { getCategorySeo } from "@/data/seoContent";

interface CategoryNode {
  label: string;
  id: string;
  children: { label: string; id: string }[];
}

const officeCategoryTree: CategoryNode[] = [
  {
    label: "Office Tables", id: "office-tables",
    children: [
      { label: "Executive Tables", id: "executive-tables" },
      { label: "Manager Tables", id: "manager-tables" },
      { label: "Staff Tables", id: "staff-tables" },
      { label: "Meeting Tables", id: "meeting-tables" },
      { label: "Reception Tables", id: "reception-tables" },
    ],
  },
  { label: "Office Chairs", id: "chairs", children: [] },
  { label: "Workstations", id: "workstations", children: [] },
  { label: "Cubicle Workstations", id: "cubicle-workstations", children: [] },
  { label: "Office Storage", id: "storage", children: [] },
  { label: "Lounge / Office Sofas", id: "office-sofas", children: [] },
  { label: "Acoustic Furniture", id: "acoustic", children: [] },
  { label: "Collaborative Furniture", id: "collaborative", children: [] },
  { label: "Cafe Furniture", id: "cafe", children: [] },
  { label: "Public Sitting", id: "public", children: [] },
];

const homeCategoryTree: CategoryNode[] = [
  {
    label: "Bedroom Furniture", id: "bedroom",
    children: [
      { label: "Bed Sets", id: "bed-sets" },
      { label: "Bedside Tables", id: "bedside-tables" },
      { label: "Dressing Tables", id: "dressing-tables" },
      { label: "Mirrors", id: "mirrors" },
      { label: "Bench & Settee", id: "bench-settee" },
    ],
  },
  {
    label: "Living Room", id: "living",
    children: [
      { label: "Home Sofa", id: "home-sofa" },
      { label: "Center & Side Tables", id: "center-side-tables" },
      { label: "Coffee Tables", id: "coffee-tables" },
      { label: "Console", id: "console" },
      { label: "TV Units", id: "tv-units" },
    ],
  },
  {
    label: "Dining", id: "dining",
    children: [
      { label: "Dining Sets", id: "dining-sets" },
      { label: "Dining Chairs", id: "dining-chairs" },
      { label: "Dining Tables", id: "dining-tables" },
    ],
  },
];

const allCategoryTree = [...officeCategoryTree, ...homeCategoryTree];

const categoryDescriptions: Record<string, string> = {
  "all": "Browse our complete collection of premium office and home furniture — all made in Pakistan with nationwide delivery.",
  "office-tables": "Executive, manager, and staff desks crafted from premium materials with integrated cable management.",
  "executive-tables": "Commanding desks for C-suite offices with veneer finishes, glass inserts, and premium hardware.",
  "chairs": "Ergonomic office chairs with lumbar support, adjustable armrests, and breathable mesh — BIFMA certified.",
  "workstations": "Modular open-plan workstations optimized for collaboration and space efficiency.",
  "cubicle-workstations": "Privacy-focused cubicle systems with acoustic panels for focused individual work.",
  "storage": "Filing cabinets, bookshelves, and modular storage solutions for organized workspaces.",
  "office-sofas": "Premium lounge seating for reception areas, waiting rooms, and executive lounges.",
  "acoustic": "Sound-absorbing furniture and privacy pods for noise-sensitive work environments.",
  "collaborative": "Flexible meeting tables, huddle pods, and brainstorming furniture for team collaboration.",
  "bedroom": "Complete bedroom solutions including bed sets, dressing tables, mirrors, and storage.",
  "living": "Sofas, coffee tables, TV units, and console tables for sophisticated living spaces.",
  "dining": "Dining sets, chairs, and tables crafted for elegant home dining experiences.",
};

const faqs = [
  { q: "Do you deliver across Pakistan?", a: "Yes, WOODEX delivers nationwide to all major cities including Lahore, Karachi, Islamabad, Rawalpindi, Faisalabad, and more." },
  { q: "Can I request custom sizes?", a: "Absolutely. Our custom manufacturing service allows you to specify exact dimensions, materials, and finishes for any product." },
  { q: "How does the E-Quotation work?", a: "Add products to your quote basket, then submit a quote request. Our team will prepare a detailed pricing proposal within 24 hours." },
  { q: "What is the minimum order quantity?", a: "There is no minimum order — we cater to individual buyers and large corporate orders alike." },
  { q: "Are prices inclusive of installation?", a: "Professional assembly and installation is included free of charge for all orders within major cities." },
];

const Shop = () => {
  const [searchParams, setSearchParams] = useSearchParams();
  const initialCategory = searchParams.get("category") || "all";

  const [searchQuery, setSearchQuery] = useState("");
  const [selectedCategory, setSelectedCategory] = useState(initialCategory);
  const [sortBy, setSortBy] = useState("featured");
  const [viewMode, setViewMode] = useState<"grid" | "list">("grid");
  const [expandedCategories, setExpandedCategories] = useState<string[]>(["office-tables", "bedroom"]);

  const seo = getCategorySeo(selectedCategory);

  useEffect(() => {
    // SeoContentBlock owns <title>, <meta description>, canonical, JSON-LD.
    if (selectedCategory === "all") {
      searchParams.delete("category");
    } else {
      searchParams.set("category", selectedCategory);
    }
    setSearchParams(searchParams, { replace: true });
  }, [selectedCategory]);

  useEffect(() => {
    const cat = searchParams.get("category") || "all";
    setSelectedCategory(cat);
  }, [searchParams]);

  const filteredProducts = useMemo(() => {
    let result = getProductsByCategory(selectedCategory);
    if (searchQuery.trim()) {
      const query = searchQuery.toLowerCase();
      result = result.filter(
        (p) =>
          p.name.toLowerCase().includes(query) ||
          p.shortDescription.toLowerCase().includes(query) ||
          p.category.toLowerCase().includes(query)
      );
    }
    return sortProducts(result, sortBy);
  }, [selectedCategory, searchQuery, sortBy]);

  const toggleExpand = (id: string) => {
    setExpandedCategories((prev) =>
      prev.includes(id) ? prev.filter((x) => x !== id) : [...prev, id]
    );
  };

  const getCategoryLabel = () => {
    if (selectedCategory === "all") return "All Products";
    for (const cat of allCategoryTree) {
      if (cat.id === selectedCategory) return cat.label;
      for (const child of cat.children) {
        if (child.id === selectedCategory) return child.label;
      }
    }
    return "All Products";
  };

  const getCategoryCount = (catId: string): number => {
    return getProductsByCategory(catId).length;
  };

  const renderCategoryNode = (cat: CategoryNode) => (
    <div key={cat.id}>
      <div className="flex items-center">
        <button
          onClick={() => setSelectedCategory(cat.id)}
          className={`flex-1 text-left px-3 py-2 text-sm rounded-sm transition-colors flex items-center justify-between ${
            selectedCategory === cat.id
              ? "bg-accent text-accent-foreground font-semibold"
              : "text-foreground hover:bg-muted hover:text-accent"
          }`}
        >
          <span>{cat.label}</span>
          <span className={`text-xs font-normal ${selectedCategory === cat.id ? "text-accent-foreground/70" : "text-muted-foreground"}`}>
            {getCategoryCount(cat.id)}
          </span>
        </button>
        {cat.children.length > 0 && (
          <button
            onClick={() => toggleExpand(cat.id)}
            className="p-2 text-muted-foreground hover:text-accent"
          >
            <ChevronDown className={`h-3.5 w-3.5 transition-transform ${expandedCategories.includes(cat.id) ? "rotate-180" : ""}`} />
          </button>
        )}
      </div>
      {cat.children.length > 0 && expandedCategories.includes(cat.id) && (
        <div className="ml-3 border-l border-border pl-3 space-y-0.5 mt-0.5 mb-1">
          {cat.children.map((child) => (
            <button
              key={child.id}
              onClick={() => setSelectedCategory(child.id)}
              className={`flex items-center gap-1 w-full text-left px-2 py-1.5 text-xs rounded-sm transition-colors justify-between ${
                selectedCategory === child.id
                  ? "text-accent font-semibold bg-accent/10"
                  : "text-muted-foreground hover:text-accent"
              }`}
            >
              <span className="flex items-center gap-1">
                <ChevronRight className="h-3 w-3 flex-shrink-0" />
                {child.label}
              </span>
              <span className="text-xs text-muted-foreground">{getCategoryCount(child.id)}</span>
            </button>
          ))}
        </div>
      )}
    </div>
  );

  return (
    <div className="min-h-screen flex flex-col">
      <Header />
      <main className="flex-1">
        {/* Hero */}
        <section className="bg-primary text-primary-foreground py-12">
          <div className="container mx-auto px-4">
            <p className="text-accent text-xs font-bold uppercase tracking-widest mb-2">Pakistan's Finest</p>
            <h1 className="text-4xl lg:text-5xl font-black mb-3">Shop Office & Home Furniture</h1>
            <p className="text-primary-foreground/75 max-w-2xl">
              Discover premium ergonomic chairs, executive desks, workstations, home furniture and more —
              all priced in PKR with nationwide delivery included.
            </p>
          </div>
        </section>

        {/* Breadcrumb */}
        <section className="py-3 bg-section-light border-b">
          <div className="container mx-auto px-4">
            <nav className="text-xs text-muted-foreground flex items-center gap-1">
              <a href="/" className="hover:text-accent">Home</a>
              <ChevronRight className="h-3 w-3" />
              <span className="font-medium text-foreground">{getCategoryLabel()}</span>
            </nav>
          </div>
        </section>

        <section className="py-10">
          <div className="container mx-auto px-4">
            <div className="flex flex-col lg:flex-row gap-8">
              {/* Sidebar */}
              <aside className="lg:w-64 flex-shrink-0">
                <div className="sticky top-28">
                  {/* All Products */}
                  <button
                    onClick={() => setSelectedCategory("all")}
                    className={`w-full text-left px-3 py-2.5 text-sm rounded-sm transition-colors mb-3 font-semibold ${
                      selectedCategory === "all"
                        ? "bg-accent text-accent-foreground"
                        : "text-foreground hover:bg-muted hover:text-accent"
                    }`}
                  >
                    All Products
                    <span className={`ml-2 text-xs font-normal ${selectedCategory === "all" ? "text-accent-foreground/70" : "text-muted-foreground"}`}>
                      {products.length}
                    </span>
                  </button>

                  {/* Office Furniture Group */}
                  <div className="mb-4">
                    <h3 className="text-xs font-bold uppercase tracking-widest text-accent px-3 mb-2">Office Furniture</h3>
                    <nav className="space-y-0.5">
                      {officeCategoryTree.map(renderCategoryNode)}
                    </nav>
                  </div>

                  {/* Home Furniture Group */}
                  <div className="border-t border-border pt-4">
                    <h3 className="text-xs font-bold uppercase tracking-widest text-accent px-3 mb-2">Home Furniture</h3>
                    <nav className="space-y-0.5">
                      {homeCategoryTree.map(renderCategoryNode)}
                    </nav>
                  </div>
                </div>
              </aside>

              {/* Products */}
              <div className="flex-1">
                {/* Toolbar */}
                <div className="flex flex-col sm:flex-row gap-3 items-start sm:items-center justify-between mb-6 p-4 bg-section-light rounded-sm">
                  <div className="relative flex-1 max-w-sm w-full">
                    <Search className="absolute left-3 top-1/2 -translate-y-1/2 h-4 w-4 text-muted-foreground" />
                    <Input
                      placeholder="Search products..."
                      value={searchQuery}
                      onChange={(e) => setSearchQuery(e.target.value)}
                      className="pl-10 bg-background"
                    />
                  </div>
                  <div className="flex items-center gap-2">
                    <Select value={sortBy} onValueChange={setSortBy}>
                      <SelectTrigger className="w-44 bg-background">
                        <SlidersHorizontal className="h-3.5 w-3.5 mr-2" />
                        <SelectValue placeholder="Sort by" />
                      </SelectTrigger>
                      <SelectContent>
                        <SelectItem value="featured">Featured</SelectItem>
                        <SelectItem value="newest">Newest</SelectItem>
                        <SelectItem value="price-low">Price: Low to High</SelectItem>
                        <SelectItem value="price-high">Price: High to Low</SelectItem>
                        <SelectItem value="rating">Highest Rated</SelectItem>
                      </SelectContent>
                    </Select>
                    <div className="flex border rounded-sm">
                      <Button variant={viewMode === "grid" ? "secondary" : "ghost"} size="icon" className="rounded-r-none h-9 w-9" onClick={() => setViewMode("grid")}>
                        <Grid3X3 className="h-4 w-4" />
                      </Button>
                      <Button variant={viewMode === "list" ? "secondary" : "ghost"} size="icon" className="rounded-l-none h-9 w-9" onClick={() => setViewMode("list")}>
                        <LayoutList className="h-4 w-4" />
                      </Button>
                    </div>
                  </div>
                </div>

                {/* Category description for SEO */}
                <div className="mb-6">
                  <h2 className="font-bold text-lg mb-1">{getCategoryLabel()}</h2>
                  <p className="text-sm text-muted-foreground">{seo.intro}</p>
                  <p className="text-xs text-muted-foreground mt-1">{filteredProducts.length} products</p>
                </div>

                {filteredProducts.length === 0 ? (
                  <div className="text-center py-20 border border-dashed border-border rounded-sm">
                    <p className="text-xl font-bold mb-2">No products found</p>
                    <p className="text-muted-foreground mb-4">Try adjusting your search or filters</p>
                    <Button onClick={() => { setSelectedCategory("all"); setSearchQuery(""); }} className="bg-accent hover:bg-accent/90 text-accent-foreground">
                      View All Products
                    </Button>
                  </div>
                ) : viewMode === "grid" ? (
                  <div className="grid sm:grid-cols-2 lg:grid-cols-3 gap-5">
                    {filteredProducts.map((product) => (
                      <ProductCard key={product.id} product={product} />
                    ))}
                  </div>
                ) : (
                  <div className="space-y-3">
                    {filteredProducts.map((product) => (
                      <ProductListItem key={product.id} product={product} />
                    ))}
                  </div>
                )}

                {/* SEO + AIO long-form content + FAQ */}
                <div className="mt-16 border-t pt-12">
                  <SeoContentBlock
                    seo={seo}
                    path={selectedCategory === "all" ? "/shop" : `/shop?category=${selectedCategory}`}
                    hideIntro
                  />
                </div>
              </div>
            </div>
          </div>
        </section>
      </main>
      <Footer />
    </div>
  );
};

export default Shop;

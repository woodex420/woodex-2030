/**
 * The storefront's catalogue: types, taxonomy and the list itself.
 *
 * `products` starts EMPTY and is filled from the API by lib/runtime.ts. The
 * site shows what the database holds — published products — and nothing else.
 * Until Furniture 2030 Phase 2, 148 demo products were compiled in here; they
 * now live in ./demo-products.ts, which only the seed snapshot reads.
 */
export interface ProductColor {
  name: string;
  hex: string;
  image?: string;
}

export interface ProductSpecification {
  label: string;
  value: string;
}

/** One sellable form of a product: a size, a finish, a material. */
export interface ProductVariant {
  id: number;
  sku: string;
  label: string;
  attributes?: Record<string, string>;
  /** Whole rupees. 0 when `priceOnRequest` is true; never print it then. */
  price: number;
  priceOnRequest: boolean;
}

export interface Product {
  id: string;
  name: string;
  category: string;
  subcategory?: string;
  series?: string;
  /** Whole rupees. 0 when `priceOnRequest` is true; print it with `priceText()`. */
  price: number;
  originalPrice?: number;
  description: string;
  shortDescription: string;
  images: string[];
  colors: ProductColor[];
  specifications: ProductSpecification[];
  features: string[];
  /** Whether it can be ordered at all — a switch, not a count. */
  inStock: boolean;
  isNew?: boolean;
  isBestSeller?: boolean;
  sku?: string | null;
  /** No agreed price yet: it can be quoted and cannot be ordered. */
  priceOnRequest?: boolean;
  /** false = made to order; there is no stock count to show. */
  trackStock?: boolean;
  /** Options on sale. A product that has them is ordered by option. */
  variants?: ProductVariant[];
  /** When the product was last changed, as the API reports it. */
  updatedAt?: string;
}

// ─── CATEGORY STRUCTURE ────────────────────────────────────────────────
export const categoryGroups = [
  {
    id: "office",
    name: "Office Furniture",
    subcategories: [
      { id: "executive-tables", name: "Executive Tables", parent: "office-tables" },
      { id: "manager-tables", name: "Manager Tables", parent: "office-tables" },
      { id: "staff-tables", name: "Staff Tables", parent: "office-tables" },
      { id: "meeting-tables", name: "Meeting Tables", parent: "office-tables" },
      { id: "reception-tables", name: "Reception Tables", parent: "office-tables" },
      { id: "chairs", name: "Office Chairs", parent: "chairs" },
      { id: "workstations", name: "Workstations", parent: "workstations" },
      { id: "cubicle-workstations", name: "Cubicle Workstations", parent: "workstations" },
      { id: "office-sofas", name: "Office Sofas", parent: "sofas" },
      { id: "office-storage", name: "Office Storage", parent: "storage" },
      { id: "cafe-furniture", name: "Cafe Furniture", parent: "cafe" },
      { id: "public-sitting", name: "Public Sitting", parent: "public" },
      { id: "home-office", name: "Home Office", parent: "home-office" },
      { id: "acoustic", name: "Acoustic Furniture", parent: "acoustic" },
      { id: "collaborative", name: "Collaborative Furniture", parent: "collaborative" },
    ],
  },
  {
    id: "home",
    name: "Home Furniture",
    subcategories: [
      { id: "bed-sets", name: "Bed Sets", parent: "bedroom" },
      { id: "bedside-tables", name: "Bedside Tables", parent: "bedroom" },
      { id: "dressing-tables", name: "Dressing Tables", parent: "bedroom" },
      { id: "mirrors", name: "Mirrors", parent: "bedroom" },
      { id: "bench-settee", name: "Bench & Settee", parent: "bedroom" },
      { id: "home-sofa", name: "Home Sofa", parent: "living" },
      { id: "center-side-tables", name: "Center & Side Tables", parent: "living" },
      { id: "coffee-tables", name: "Coffee Tables", parent: "living" },
      { id: "console", name: "Console", parent: "living" },
      { id: "tv-units", name: "TV Units", parent: "living" },
      { id: "dining-sets", name: "Dining Sets", parent: "dining" },
      { id: "dining-chairs", name: "Dining Chairs", parent: "dining" },
      { id: "dining-tables", name: "Dining Tables", parent: "dining" },
    ],
  },
];

export const categories = [
  { id: "all", name: "All Products" },
  // Office
  { id: "office-tables", name: "Office Tables" },
  { id: "executive-tables", name: "↳ Executive Tables" },
  { id: "manager-tables", name: "↳ Manager Tables" },
  { id: "staff-tables", name: "↳ Staff Tables" },
  { id: "meeting-tables", name: "↳ Meeting Tables" },
  { id: "reception-tables", name: "↳ Reception Tables" },
  { id: "chairs", name: "Office Chairs" },
  { id: "workstations", name: "Workstations" },
  { id: "cubicle-workstations", name: "Cubicle Workstations" },
  { id: "office-sofas", name: "Office Sofas" },
  { id: "storage", name: "Office Storage" },
  { id: "cafe", name: "Cafe Furniture" },
  { id: "public", name: "Public Sitting" },
  { id: "home-office", name: "Home Office" },
  { id: "acoustic", name: "Acoustic Furniture" },
  { id: "collaborative", name: "Collaborative Furniture" },
  // Home
  { id: "bedroom", name: "Bedroom Furniture" },
  { id: "living", name: "Living Room" },
  { id: "dining", name: "Dining Furniture" },
];

// ─── SERIES ─────────────────────────────────────────────────────────────
export const seriesList = [
  {
    id: "ek-series",
    name: "Ek Series",
    tagline: "Affordable quality for every workspace",
    badge: "Budget-Friendly",
    description: "The Ek Series delivers reliable quality at an accessible price point. Perfect for startups, schools, and growing businesses that need functional furniture without breaking the budget.",
    features: ["Engineered wood construction", "Powder-coated steel frames", "Standard ergonomics", "3-year warranty"],
    color: "#5a7a3a",
  },
  {
    id: "infinity-series",
    name: "Infinity Series",
    tagline: "Modular systems that grow with you",
    badge: "Modular",
    description: "The Infinity Series is built for flexibility. Every component connects seamlessly, allowing you to expand, reconfigure, and adapt your workspace as your team evolves.",
    features: ["Fully modular components", "Expandable layouts", "Integrated cable management", "5-year warranty"],
    color: "#3a5a7a",
  },
  {
    id: "woodex-series",
    name: "Woodex Series",
    tagline: "Premium flagship collection",
    badge: "Premium",
    description: "Our flagship Woodex Series represents the pinnacle of Pakistani craftsmanship. Designed for executives and discerning clients who demand nothing but the best.",
    features: ["Solid hardwood & premium veneer", "Full-grain leather options", "Handcrafted detailing", "10-year warranty"],
    color: "#7a5a3a",
  },
  {
    id: "cubicle-series",
    name: "Cubicle Series",
    tagline: "Privacy-focused workstation solutions",
    badge: "Privacy",
    description: "The Cubicle Series creates focused, private workspaces within open offices. Acoustic panels and intelligent layouts maximize productivity for individual contributors.",
    features: ["Acoustic privacy panels", "Height-adjustable options", "Personal storage integration", "5-year warranty"],
    color: "#5a5a7a",
  },
  {
    id: "nova-series",
    name: "Nova Series",
    tagline: "Minimalist design-forward furniture",
    badge: "Minimal",
    description: "Nova Series brings Scandinavian-inspired minimalism to Pakistani workspaces. Clean lines, hidden hardware, and slim profiles for design-conscious offices.",
    features: ["Hidden hardware", "Slim steel frames", "Push-to-open drawers", "3-year warranty"],
    color: "#7a7a5a",
  },
];

// ─── THE CATALOGUE ─────────────────────────────────────────────────────
/** Filled by lib/runtime.ts from GET /api/products. Never from a compiled list. */
export const products: Product[] = [];

/**
 * Where the catalogue stands.
 *   loading      — the API has not answered yet: show a placeholder, not "0 products";
 *   ready        — `products` is what the shop sells (possibly nothing);
 *   unavailable  — the API could not be read and nothing was loaded before.
 * A refresh that fails after a successful load leaves the last good list and
 * the status "ready": a customer mid-order should not lose the page.
 */
export type CatalogStatus = "loading" | "ready" | "unavailable";
export const catalog: { status: CatalogStatus } = { status: "loading" };

// ─── CATEGORY NAMES AND TREE ───────────────────────────────────────────
/* A name for every category the catalogue may use. A category that is not
   listed still works: its id is shown with capitals. */
export const CATEGORY_LABELS: Record<string, string> = {
  "office-tables": "Office Tables",
  "ceo-tables": "CEO Tables",
  "executive-tables": "Executive Tables",
  "manager-tables": "Manager Tables",
  "staff-tables": "Staff Tables",
  "meeting-tables": "Meeting Tables",
  "conference-tables": "Conference Tables",
  "discussion-tables": "Discussion Tables",
  "reception-tables": "Reception Desks",
  "center-tables": "Center Tables",
  "chairs": "Office Chairs",
  "executive-chairs": "Executive Chairs",
  "manager-chairs": "Manager Chairs",
  "staff-chairs": "Staff Chairs",
  "visitor-chairs": "Visitor Chairs",
  "workstations": "Workstations",
  "cubicle-workstations": "Cubicle Workstations",
  "office-sofas": "Office Sofas",
  "storage": "Office Storage",
  "cafe": "Cafe Furniture",
  "public": "Public Seating",
  "home-office": "Home Office",
  "acoustic": "Acoustic Furniture",
  "collaborative": "Collaborative Furniture",
  "bedroom": "Bedroom Furniture",
  "bed-sets": "Bed Sets",
  "bedside-tables": "Bedside Tables",
  "dressing-tables": "Dressing Tables",
  "mirrors": "Mirrors",
  "bench-settee": "Bench & Settee",
  "living": "Living Room",
  "home-sofa": "Home Sofa",
  "center-side-tables": "Center & Side Tables",
  "coffee-tables": "Coffee Tables",
  "console": "Console",
  "tv-units": "TV Units",
  "dining": "Dining Furniture",
  "dining-sets": "Dining Sets",
  "dining-chairs": "Dining Chairs",
  "dining-tables": "Dining Tables",
};
export const categoryLabel = (id: string): string =>
  CATEGORY_LABELS[id] ?? id.split("-").map((word) => word.charAt(0).toUpperCase() + word.slice(1)).join(" ");

const CATEGORY_ORDER = Object.keys(CATEGORY_LABELS);
const orderOf = (id: string) => { const at = CATEGORY_ORDER.indexOf(id); return at === -1 ? CATEGORY_ORDER.length : at; };
const HOME_CATEGORIES = new Set(["bedroom", "living", "dining"]);

export interface CategoryNode {
  id: string;
  label: string;
  count: number;
  home: boolean;
  children: { id: string; label: string; count: number }[];
}

/**
 * The categories the catalogue actually holds, with how many products each
 * has. Built from the products, so the shop never offers an empty category
 * and a category added in the dashboard appears without a release.
 */
export const categoryTree = (): CategoryNode[] => {
  const nodes = new Map<string, CategoryNode>();
  for (const p of products) {
    if (!p.category) continue;
    let node = nodes.get(p.category);
    if (!node) {
      node = { id: p.category, label: categoryLabel(p.category), count: 0, home: HOME_CATEGORIES.has(p.category), children: [] };
      nodes.set(p.category, node);
    }
    node.count++;
    if (p.subcategory && p.subcategory !== p.category) {
      const child = node.children.find((c) => c.id === p.subcategory);
      if (child) child.count++;
      else node.children.push({ id: p.subcategory, label: categoryLabel(p.subcategory), count: 1 });
    }
  }
  const byOrder = (a: { id: string; label: string }, b: { id: string; label: string }) =>
    orderOf(a.id) - orderOf(b.id) || a.label.localeCompare(b.label);
  const tree = [...nodes.values()].sort(byOrder);
  for (const node of tree) node.children.sort(byOrder);
  return tree;
};

// ─── PRICES ────────────────────────────────────────────────────────────
export const formatPKR = (amount: number): string => {
  return `PKR ${amount.toLocaleString("en-PK")}`;
};

export const hasOptions = (p: Product): boolean => (p.variants?.length ?? 0) > 0;

/**
 * The lowest price this product can be bought at, or null when nothing about
 * it has a price yet. For a product sold in options that is the cheapest
 * priced option. null is "price on request" — never PKR 0.
 */
export const fromPrice = (p: Product): number | null => {
  if (hasOptions(p)) {
    const priced = (p.variants ?? []).filter((v) => !v.priceOnRequest).map((v) => v.price);
    return priced.length ? Math.min(...priced) : null;
  }
  return p.priceOnRequest ? null : p.price;
};

/** What to print as the price of a product on a card or a list. */
export const priceText = (p: Product): string => {
  const price = fromPrice(p);
  if (price === null) return "Price on request";
  const several = hasOptions(p) && new Set((p.variants ?? []).map((v) => (v.priceOnRequest ? "request" : v.price))).size > 1;
  return (several ? "From " : "") + formatPKR(price);
};

/** The price of one chosen option, or of the product itself. */
export const optionPrice = (p: Product, variant?: ProductVariant | null): number | null =>
  variant ? (variant.priceOnRequest ? null : variant.price) : (p.priceOnRequest ? null : p.price);

/** "In stock", "Made to order" or "Not available" — and never a quantity. */
export const availabilityText = (p: Product): string =>
  !p.inStock ? "Not available" : p.trackStock === false ? "Made to order" : "In stock";

// ─── LOOKUPS ───────────────────────────────────────────────────────────
export const getProductById = (id: string): Product | undefined => {
  return products.find((p) => p.id === id);
};

export const getProductsByCategory = (category: string): Product[] => {
  if (category === "all") return products;
  return products.filter((p) => p.category === category || p.subcategory === category);
};

export const getProductsBySeries = (seriesId: string): Product[] => {
  return products.filter((p) => p.series === seriesId);
};

export const sortProducts = (prods: Product[], sortBy: string): Product[] => {
  const sorted = [...prods];
  /* A product with no price has no place in a price order: it goes last,
     whichever way the list is sorted. */
  const byPrice = (direction: 1 | -1) => (a: Product, b: Product) => {
    const pa = fromPrice(a), pb = fromPrice(b);
    if (pa === null || pb === null) return pa === pb ? 0 : pa === null ? 1 : -1;
    return direction * (pa - pb);
  };
  switch (sortBy) {
    case "price-low":
      return sorted.sort(byPrice(1));
    case "price-high":
      return sorted.sort(byPrice(-1));
    case "name":
      return sorted.sort((a, b) => a.name.localeCompare(b.name));
    case "newest":
      return sorted.sort((a, b) => (b.isNew ? 1 : 0) - (a.isNew ? 1 : 0));
    default:
      return sorted;
  }
};

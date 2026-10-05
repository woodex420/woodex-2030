import { defineTool, ToolError } from "@lovable.dev/mcp-js";
import { z } from "zod";
import data from "../data.json";
const products = data.products as any[];
const categories = data.categories as any[];
const seriesList = data.series as any[];

const toProductJson = (p: (typeof products)[number]) => ({
  id: p.id,
  name: p.name,
  category: p.category,
  subcategory: p.subcategory ?? null,
  series: p.series ?? null,
  pricePkr: p.price,
  shortDescription: p.shortDescription,
  inStock: p.inStock,
  rating: p.rating,
});

export const searchProducts = defineTool({
  name: "search_products",
  title: "Search products",
  description: "Search the WOODEX furniture catalog by keyword, category, series or max price (PKR).",
  inputSchema: {
    query: z.string().optional().describe("Keyword to match in name or description."),
    category: z.string().optional().describe("Category id, e.g. 'office-chairs'."),
    series: z.string().optional().describe("Series id, e.g. 'ek-series'."),
    maxPrice: z.number().optional().describe("Maximum price in PKR."),
    limit: z.number().int().min(1).max(50).optional().describe("Max results (default 20)."),
  },
  annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: false },
  handler: ({ query, category, series, maxPrice, limit }) => {
    const q = query?.toLowerCase();
    const list = products
      .filter((p) => !q || `${p.name} ${p.description}`.toLowerCase().includes(q))
      .filter((p) => !category || p.category === category || p.subcategory === category)
      .filter((p) => !series || p.series === series)
      .filter((p) => maxPrice === undefined || p.price <= maxPrice)
      .slice(0, limit ?? 20)
      .map(toProductJson);
    return { content: [{ type: "text", text: JSON.stringify(list) }], structuredContent: { products: list } };
  },
});

export const getProduct = defineTool({
  name: "get_product",
  title: "Get product details",
  description: "Get full details, specifications and colors for one product by id.",
  inputSchema: { id: z.string().min(1).describe("Product id.") },
  annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: false },
  handler: ({ id }) => {
    const p = products.find((x) => x.id === id);
    if (!p) throw new ToolError(`No product with id "${id}"`);
    const product = {
      ...toProductJson(p),
      description: p.description,
      features: [...p.features],
      colors: p.colors.map((c) => ({ name: c.name, hex: c.hex })),
      specifications: p.specifications.map((s) => ({ label: s.label, value: s.value })),
    };
    return { content: [{ type: "text", text: JSON.stringify(product) }], structuredContent: { product } };
  },
});

export const listCategories = defineTool({
  name: "list_categories_and_series",
  title: "List categories and series",
  description: "List all product categories and furniture series offered by WOODEX.",
  inputSchema: {},
  annotations: { readOnlyHint: true, idempotentHint: true, openWorldHint: false },
  handler: () => {
    const data = {
      categories: categories.map((c: any) => ({ id: String(c.id), name: String(c.name ?? c.label ?? c.id) })),
      series: seriesList.map((s) => ({ id: s.id, name: s.name, tagline: s.tagline, description: s.description })),
    };
    return { content: [{ type: "text", text: JSON.stringify(data) }], structuredContent: data };
  },
});

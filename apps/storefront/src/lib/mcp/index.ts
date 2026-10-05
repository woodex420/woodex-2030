import { defineMcp } from "@lovable.dev/mcp-js";
import { searchProducts, getProduct, listCategories } from "./tools/catalog";
import { listServices, listBlogPosts, getBlogPost } from "./tools/content";

export default defineMcp({
  name: "woodex-reimagined",
  title: "WOODEX Reimagined",
  version: "0.1.0",
  instructions:
    "Public catalog tools for WOODEX, a Lahore furniture maker. Prices are in PKR. Use search_products / get_product for furniture, list_categories_and_series for taxonomy, list_services for services, and list_blog_posts / get_blog_post for articles.",
  tools: [searchProducts, getProduct, listCategories, listServices, listBlogPosts, getBlogPost],
});

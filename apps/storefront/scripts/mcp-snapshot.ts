// Regenerates src/lib/mcp/data.json (image-free catalog snapshot for the MCP server).
// Run: bun --preload ./scripts/stub-assets.ts scripts/mcp-snapshot.ts
import { products, categories, seriesList } from "../src/data/products";
import { services } from "../src/data/services";
import { blogPosts } from "../src/data/blogPosts";
type ImageBearing = { images?: unknown; image?: unknown };
const strip = <T extends ImageBearing>(record: T) => {
  const { images: _images, image: _image, ...rest } = record;
  return rest;
};
await Bun.write("src/lib/mcp/data.json", JSON.stringify({
  products: products.map(strip), categories, series: seriesList,
  services: services.map(strip), blogPosts: blogPosts.map(strip),
}, null, 1));

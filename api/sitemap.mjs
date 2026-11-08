import handler from "./index.mjs";

/* /sitemap.xml is rewritten here by vercel.json. The API serves the sitemap at
   the site root (app.get("/sitemap.xml")), but the generic handler prefixes any
   path outside /api with /api — which would 404 this one. Map it back to the
   root path the Express route actually listens on. */
export default async function sitemapHandler(req, res) {
  const incoming = new URL(req.url || "/", "http://localhost");
  req.url = `/sitemap.xml${incoming.search}`;
  return handler(req, res);
}

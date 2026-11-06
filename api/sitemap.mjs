import { app } from "../apps/api/server.mjs";

export default function sitemapHandler(req, res) {
  const incoming = new URL(req.url || "/", "http://localhost");
  req.url = `/sitemap.xml${incoming.search}`;
  return app(req, res);
}

import { app } from "../apps/api/server.mjs";

export default function handler(req, res) {
  // Vercel catch-all functions may expose the path relative to /api; Express routes keep the prefix.
  const incoming = new URL(req.url || "/", "http://localhost");
  if (incoming.pathname !== "/api" && !incoming.pathname.startsWith("/api/")) {
    req.url = `/api${incoming.pathname === "/" ? "" : incoming.pathname}${incoming.search}`;
  }
  return app(req, res);
}

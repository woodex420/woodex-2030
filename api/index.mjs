/* Vercel serverless entry point for the Woodex API.
   All /api/* traffic is rewritten here by vercel.json.

   The catch that matters: importing server.mjs throws when a required setting is
   missing (for example DATABASE_URL on Vercel). Unhandled, that surfaces to the
   browser as FUNCTION_INVOCATION_FAILED with an empty body, so a misconfigured
   deployment looks identical to a broken application. Catching it lets us answer
   with a real status and a message that names the missing variable. */
let appPromise = null;

function loadApp() {
  if (!appPromise) {
    appPromise = import("../apps/api/server.mjs").then(
      (module) => ({ app: module.app, error: null }),
      (error) => ({ app: null, error }),
    );
  }
  return appPromise;
}

export default async function handler(req, res) {
  const { app, error } = await loadApp();

  if (!app) {
    console.error("[woodex-api] server failed to initialise:", error);
    res.statusCode = 503;
    res.setHeader("content-type", "application/json; charset=utf-8");
    res.setHeader("cache-control", "no-store");
    res.end(JSON.stringify({
      error: "The Woodex API is not configured on this deployment.",
      detail: error?.message ?? String(error),
      fix: "Set DATABASE_URL (and INITIAL_ADMIN_EMAIL / INITIAL_ADMIN_PASSWORD) in Vercel → Settings → Environment Variables, then run `DATABASE_URL=... npm run db:apply-schema` once and redeploy.",
    }));
    return;
  }

  // Vercel catch-all functions may expose the path relative to /api; Express routes keep the prefix.
  const incoming = new URL(req.url || "/", "http://localhost");
  if (incoming.pathname !== "/api" && !incoming.pathname.startsWith("/api/")) {
    req.url = `/api${incoming.pathname === "/" ? "" : incoming.pathname}${incoming.search}`;
  }
  return app(req, res);
}

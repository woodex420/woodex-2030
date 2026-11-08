/* Path normalisation for the dashboard's client router.
 *
 * The dashboard is served from /dashboard and its router is basenamed to match,
 * but vercel.json also REWRITES /admin[...] onto /dashboard[...]. A rewrite,
 * unlike a redirect, leaves the address bar untouched, so <BrowserRouter
 * basename="/dashboard"> is asked to match "/admin" — a path outside its
 * basename. React Router then matches nothing and renders nothing, which the
 * user sees as a blank white screen after a perfectly successful sign-in, with
 * no error to explain it. Normalise the alias before the router mounts.
 */
export function canonicalDashboardPath(basename: string, pathname: string): string | null {
  if (!basename || basename === "/") return null;
  const lower = pathname.toLowerCase();
  const base = basename.toLowerCase();
  if (lower === base || lower.startsWith(base + "/")) return null; // already canonical

  /* /admin was this dashboard's advertised address before /dashboard. */
  if (base.endsWith("/dashboard")) {
    const alias = basename.slice(0, -"dashboard".length) + "admin";
    const aliasLower = alias.toLowerCase();
    if (lower === aliasLower || lower.startsWith(aliasLower + "/"))
      return basename + pathname.slice(alias.length);
  }
  return null;
}

/** True when the path is outside the router's basename entirely (it would render nothing). */
export function isOutsideDashboard(basename: string, pathname: string): boolean {
  if (!basename || basename === "/") return false;
  const lower = pathname.toLowerCase();
  const base = basename.toLowerCase();
  return !(lower === base || lower.startsWith(base + "/"));
}

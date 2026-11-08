/**
 * Single source of imagery for the storefront.
 *
 * Photography is served once, by the API (`/img/*`, backed by
 * `apps/api/public/img`). Vite proxies `/img` in development and the Vercel
 * build copies the same files to `dist/img`, so there is exactly one copy of
 * every photograph in the repository.
 *
 * Import sites use `img("product-office-chair.jpg")` instead of a bundler
 * asset import, which keeps these images out of the JS/CSS graph and lets the
 * browser cache them independently of the app shell.
 */
export const IMG_BASE = "/img/";

export function img(file: string): string {
  if (!file) return "";
  if (/^(https?:|data:)/i.test(file) || file.startsWith("/")) return file;
  return IMG_BASE + file;
}

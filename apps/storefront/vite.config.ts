import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";

const apiTarget = process.env.API_PROXY_TARGET ?? "http://localhost:3001";

// https://vitejs.dev/config/
export default defineConfig({
  server: {
    host: "0.0.0.0",
    port: 5174,
    strictPort: true,
    allowedHosts: [".e2b.app", "localhost"],
    // Arena terminates TLS at its preview proxy; ordinary local/CI E2E uses Vite's direct websocket port.
    hmr: process.env.ARENA_PREVIEW === "1" ? { clientPort: 443 } : undefined,
    proxy: {
      // Local rehearsal only. Keep the browser same-origin; do not bake a sandbox URL into bundles.
      ...(process.env.LARAVEL_LEAD_API_PROXY_TARGET ? { "/api/v1/leads": process.env.LARAVEL_LEAD_API_PROXY_TARGET, "/api/v1/quote-requests": process.env.LARAVEL_LEAD_API_PROXY_TARGET, "/api/v1/catalogue": process.env.LARAVEL_LEAD_API_PROXY_TARGET } : {}),
      "/api": apiTarget,
      "/img": apiTarget,
      "/sitemap.xml": apiTarget,
      "/robots.txt": apiTarget,
    },
  },
  plugins: [react()],
  resolve: {
    alias: {
      "@": new URL("./src", import.meta.url).pathname,
    },
  },
});

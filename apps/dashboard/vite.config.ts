import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import { defineConfig } from "vite";

const apiTarget = process.env.API_PROXY_TARGET ?? "http://localhost:3001";

export default defineConfig({
  base: process.env.VITE_BASE_PATH ?? "/",
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      "@": "/src",
    },
  },
  server: {
    host: "0.0.0.0",
    port: 5173,
    strictPort: true,
    allowedHosts: [".e2b.app", "localhost"],
    // Arena terminates TLS at its preview proxy; ordinary local/CI E2E uses Vite's direct websocket port.
    hmr: process.env.ARENA_PREVIEW === "1" ? { clientPort: 443 } : undefined,
    proxy: {
      "/api": apiTarget,
      "/img": apiTarget,
    },
  },
});

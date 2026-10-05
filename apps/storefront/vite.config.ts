import { defineConfig } from "vite";
import react from "@vitejs/plugin-react-swc";
import path from "path";

// https://vitejs.dev/config/
export default defineConfig({
  server: {
    host: "0.0.0.0",
    port: 5174,
    strictPort: true,
    allowedHosts: [".e2b.app", "localhost"],
    proxy: {
      "/api": "http://localhost:3001",
      "/img": "http://localhost:3001",
      "/sitemap.xml": "http://localhost:3001",
      "/robots.txt": "http://localhost:3001",
    },
  },
  plugins: [react()],
  resolve: {
    alias: {
      "@": path.resolve(__dirname, "./src"),
    },
  },
});

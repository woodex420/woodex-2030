// Read-only health crawl of a Vite dev server: loads the same module graph a browser would, reports any module that fails to serve.
const base = process.argv[2] || "http://localhost:3001";
const host = process.argv[3] || "3001-previewcheck.e2b.app";
const hdr = { Host: host };
const seen = new Map(); const queue = [];
const push = (p) => { if (!p || seen.has(p)) return; seen.set(p, null); queue.push(p); };
const t0 = Date.now();
const html = await (await fetch(base + "/", { headers: hdr })).text();
for (const m of html.matchAll(/<script[^>]+src="([^"]+)"/g)) push(m[1]);
push("/@react-refresh");
let bytes = 0; const bad = [];
while (queue.length) {
  const batch = queue.splice(0, 16);
  await Promise.all(batch.map(async (p) => {
    try {
      const r = await fetch(base + p, { headers: hdr, signal: AbortSignal.timeout(90000) });
      const body = await r.text(); bytes += body.length;
      const ct = r.headers.get("content-type") || "";
      seen.set(p, r.status);
      if (r.status >= 400 || /Internal server error|\[plugin:vite|Failed to resolve import|Outdated Optimize Dep/.test(body.slice(0, 4000))) { bad.push([p, r.status, body.slice(0, 200).replace(/\s+/g, " ")]); return; }
      if (!/javascript/.test(ct)) return;
      for (const m of body.matchAll(/(?:from\s*|import\s*\(?\s*)"(\/[^"]+)"/g)) push(m[1]);
    } catch (e) { seen.set(p, "ERR"); bad.push([p, "ERR", String(e.message || e)]); }
  }));
}
const kinds = { src: 0, deps: 0, other: 0 };
for (const p of seen.keys()) (p.startsWith("/src/") ? kinds.src++ : p.includes("/.vite/deps/") ? kinds.deps++ : kinds.other++);
console.log(`modules requested: ${seen.size} (app source ${kinds.src}, pre-bundled deps ${kinds.deps}, other ${kinds.other}) | ${(bytes/1024).toFixed(0)} KB | ${Date.now()-t0} ms`);
console.log(`failed modules: ${bad.length}`); for (const b of bad.slice(0, 15)) console.log("  ✗", b.join(" | "));
process.exit(bad.length ? 1 : 0);

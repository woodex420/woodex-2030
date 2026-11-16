// Local stand-in for the Arena preview proxy: HTTPS on :8443, routes "<port>-<id>.e2b.app" to localhost:<port> (HTTP + WebSocket),
// and serves a cross-site parent page ("arena-parent.test") that embeds the preview in an iframe, the way the Arena UI shows it.
import https from "node:https"; import http from "node:http"; import net from "node:net"; import fs from "node:fs";
const tls = { key: fs.readFileSync(new URL("./tls/key.pem", import.meta.url)), cert: fs.readFileSync(new URL("./tls/cert.pem", import.meta.url)) };
const portOf = (host) => { const m = /^(\d+)-[a-z0-9]+\.e2b\.app/.exec(host || ""); return m ? Number(m[1]) : null; };
const srv = https.createServer(tls, (req, res) => {
  const host = (req.headers.host || "").replace(/:\d+$/, "");
  if (host === "arena-parent.test") {
    const u = new URL(req.url, "https://x"); const target = u.searchParams.get("src") || "https://3001-simcheck.e2b.app/";
    res.writeHead(200, { "content-type": "text/html" });
    return res.end(`<!doctype html><title>parent (different site)</title><body style="margin:0;background:#223"><p style="color:#fff;font:14px sans-serif;margin:6px">Cross-site parent page embedding the preview</p><iframe id="preview" src="${target}" style="width:1280px;height:820px;border:0;background:#fff"></iframe></body>`);
  }
  const port = portOf(host); if (!port) { res.writeHead(502); return res.end("no route"); }
  const up = http.request({ host: "127.0.0.1", port, method: req.method, path: req.url, headers: { ...req.headers, host, "x-forwarded-proto": "https", "x-forwarded-host": host } }, (r) => { res.writeHead(r.statusCode, r.headers); r.pipe(res); });
  up.on("error", (e) => { res.writeHead(502); res.end("upstream error: " + e.message); }); req.pipe(up);
});
srv.on("upgrade", (req, socket, head) => {
  const host = (req.headers.host || "").replace(/:\d+$/, ""); const port = portOf(host); if (!port) return socket.destroy();
  const up = net.connect(port, "127.0.0.1", () => {
    up.write(`${req.method} ${req.url} HTTP/1.1\r\n` + Object.entries({ ...req.headers, host }).map(([k, v]) => `${k}: ${v}`).join("\r\n") + "\r\n\r\n");
    if (head?.length) up.write(head); socket.pipe(up); up.pipe(socket);
  });
  up.on("error", () => socket.destroy()); socket.on("error", () => up.destroy());
});
srv.listen(8443, "127.0.0.1", () => console.log("sim proxy on 127.0.0.1:8443"));

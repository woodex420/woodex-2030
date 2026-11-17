#!/usr/bin/env node
/**
 * Stands in for Vercel's network in tools/vercel/check-entry.sh.
 *
 * 1. The "routes" of vercel.json are applied as Vercel applies them: the first route whose pattern matches the path
 *    decides. A destination under /public/ is a static file (the @vercel/static build of public/**); a missing file
 *    is 404. The destination api/index.php is the function.
 * 2. A request for the function becomes the event that the vercel-php launcher receives on Vercel, and the
 *    launcher's answer becomes the HTTP response. The launcher is Vercel's own
 *    (node_modules/vercel-php/dist/launchers/builtin.js, the file that ships in the function): it starts PHP's
 *    built-in server on port 3000 with the entry as its router, exactly as in production.
 *
 *   LAMBDA_TASK_ROOT   folder that holds user/ (the application) and php/ (the runtime's PHP)
 *   NOW_ENTRYPOINT     api/index.php
 *   VERCEL_PHP_PACKAGE node_modules/vercel-php
 *   STAND_IN_ROUTES    JSON: the "routes" of vercel.json
 *   STAND_IN_PORT      port to listen on
 */
const fs = require('node:fs');
const http = require('node:http');
const path = require('node:path');

const { launcher } = require(path.join(process.env.VERCEL_PHP_PACKAGE, 'dist/launchers/builtin.js'));
const port = Number(process.env.STAND_IN_PORT || 8191);
const routes = JSON.parse(process.env.STAND_IN_ROUTES || '[]');
const application = path.join(process.env.LAMBDA_TASK_ROOT, 'user');
const types = {
  '.css': 'text/css; charset=utf-8', '.js': 'application/javascript; charset=utf-8', '.mjs': 'application/javascript; charset=utf-8',
  '.json': 'application/json; charset=utf-8', '.svg': 'image/svg+xml', '.png': 'image/png', '.jpg': 'image/jpeg', '.jpeg': 'image/jpeg',
  '.webp': 'image/webp', '.gif': 'image/gif', '.ico': 'image/x-icon', '.woff2': 'font/woff2', '.woff': 'font/woff', '.mp3': 'audio/mpeg',
  '.txt': 'text/plain; charset=utf-8', '.map': 'application/json; charset=utf-8',
};

/** The destination of a path under the routes of vercel.json, or null when no route matches. */
function destination(pathname) {
  for (const route of routes) {
    const match = new RegExp(`^${route.src}$`).exec(pathname);
    if (match) return route.dest.replace(/\$(\d+)/g, (_, index) => match[Number(index)] ?? '');
  }
  return null;
}

http.createServer((request, response) => {
  const pathname = decodeURIComponent(request.url.split('?')[0]);
  const target = destination(pathname);

  if (target === null) {
    response.writeHead(404, { 'content-type': 'text/plain', 'x-stand-in': 'no-route' });
    return response.end('NOT_FOUND');
  }
  if (target !== `/${process.env.NOW_ENTRYPOINT}`) {
    // A static file of the deployment. Only public/** is built as static output.
    const file = path.join(application, path.normalize(target));
    const inside = file.startsWith(path.join(application, 'public') + path.sep);
    if (!inside || !fs.existsSync(file) || !fs.statSync(file).isFile()) {
      response.writeHead(404, { 'content-type': 'text/plain', 'x-stand-in': 'static' });
      return response.end('NOT_FOUND');
    }
    response.writeHead(200, { 'content-type': types[path.extname(file).toLowerCase()] || 'application/octet-stream', 'x-stand-in': 'static' });
    return response.end(fs.readFileSync(file));
  }

  const chunks = [];
  request.on('data', (chunk) => chunks.push(chunk));
  request.on('end', async () => {
    const body = Buffer.concat(chunks);
    try {
      const answer = await launcher({
        Action: 'Invoke',
        body: JSON.stringify({
          method: request.method,
          path: request.url,
          host: request.headers.host,
          headers: request.headers,
          encoding: 'base64',
          body: body.length > 0 ? body.toString('base64') : undefined,
        }),
      });
      const headers = { ...answer.headers, 'x-stand-in': 'function' };
      // The body arrives complete; headers that describe how PHP's server sent it no longer apply.
      for (const name of ['transfer-encoding', 'connection', 'content-length', 'keep-alive']) delete headers[name];
      response.writeHead(answer.statusCode, headers);
      response.end(Buffer.from(answer.body, answer.encoding || 'base64'));
    } catch (error) {
      console.error('stand-in: the launcher failed', error);
      response.writeHead(502, { 'content-type': 'text/plain' });
      response.end('The launcher failed.');
    }
  });
}).listen(port, '127.0.0.1', () => console.log(`stand-in: listening on ${port}`));

// The launcher stops PHP when this process exits; a bare signal would skip that and leave PHP holding port 3000.
for (const signal of ['SIGTERM', 'SIGINT', 'SIGHUP']) process.on(signal, () => process.exit(0));

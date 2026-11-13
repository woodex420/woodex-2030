import assert from 'node:assert/strict';
import { mkdtemp, mkdir, copyFile, rm } from 'node:fs/promises';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import { spawn } from 'node:child_process';
import { createServer } from 'node:net';

// Isolated no-vendor fixture: never boots Laravel or reads any application .env/database.
const php = process.env.PHP_BINARY;
if (!php) throw new Error('Set PHP_BINARY to a PHP 8.4.1+ CLI binary. No tests executed.');
const temp = await mkdtemp(join(tmpdir(), 'woodex-entrypoint-'));
let child;
try {
  await mkdir(join(temp, 'platform/public'), { recursive: true });
  await copyFile(resolve('platform/public/index.php'), join(temp, 'platform/public/index.php'));
  await copyFile(resolve('index.php'), join(temp, 'index.php'));
  const probe = createServer();
  await new Promise((yes, no) => { probe.once('error', no); probe.listen(0, '127.0.0.1', yes); });
  const port = probe.address().port;
  await new Promise(yes => probe.close(yes));
  child = spawn(php, ['-S', `127.0.0.1:${port}`, '-t', temp], { stdio: ['ignore', 'ignore', 'pipe'] });
  await new Promise((yes, no) => {
    const timer = setTimeout(() => no(new Error('PHP startup timeout')), 10000);
    child.once('error', error => { clearTimeout(timer); no(error); });
    child.once('exit', code => { clearTimeout(timer); no(new Error(`PHP exited early: ${code}`)); });
    child.stderr.on('data', data => { if (data.toString().includes('Development Server')) { clearTimeout(timer); yes(); } });
  });
  for (const method of ['GET', 'HEAD']) {
    const res = await fetch(`http://127.0.0.1:${port}/index.php`, { method });
    assert.equal(res.status, 503);
    assert.equal(res.headers.get('retry-after'), '300');
    assert.equal(res.headers.get('cache-control'), 'no-store');
    assert.equal(res.headers.get('x-content-type-options'), 'nosniff');
    assert.equal(res.headers.get('x-robots-tag'), 'noindex, nofollow');
    const body = await res.text();
    if (method === 'GET') {
      assert.match(body, /Temporarily unavailable/);
      assert.doesNotMatch(body, /PHP|Composer|vendor|Hostinger|platform|\.env|\/install|stack trace/i);
    } else assert.equal(body, '');
  }
  console.log('PASS: isolated Laravel entrypoint returns safe 503 for GET/HEAD without dependencies. Not hosted acceptance.');
} finally {
  if (child && child.exitCode === null) {
    const exited = new Promise(yes => child.once('exit', yes));
    child.kill('SIGTERM');
    await exited;
  }
  await rm(temp, { recursive: true, force: true });
}

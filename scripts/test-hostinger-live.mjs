import assert from 'node:assert/strict';

// Fixed staging target only. No credentials, writes, installers or database access.
const origin = 'https://staging.blackibex.net';
const checks = [];
async function request(path) {
  const response = await fetch(origin + path, {
    redirect: 'manual', signal: AbortSignal.timeout(20000),
    headers: { 'User-Agent': 'Woodex-Staging-Acceptance/1.0', 'Cache-Control': 'no-cache' },
  });
  const body = (await response.text()).slice(0, 200000);
  assert.doesNotMatch(body, /-----BEGIN (?:OPENSSH |RSA )?PRIVATE KEY-----|APP_KEY=base64:|Illuminate\\.*Stack trace/i, 'Sensitive debug/source response');
  return { response, body };
}
const root = await request('/');
assert.equal(root.response.status, 302, 'Staging homepage must keep its temporary frontend redirect');
assert.equal(root.response.headers.get('location'), 'https://woodex-2030.vercel.app/');
checks.push('homepage redirect');
for (const path of ['/composer.json', '/README.md', '/platform/composer.json', '/install']) {
  const { response, body } = await request(path);
  assert.ok([403, 404].includes(response.status), `${path}: expected blocked source/installer, received ${response.status}`);
  assert.doesNotMatch(body, /"require"\s*:\s*\{|APP_DEBUG=/);
  checks.push(`blocked ${path}`);
}
let current = new URL('/admin', origin);
let login;
for (let redirects = 0; redirects < 6; redirects++) {
  const result = await request(current.pathname + current.search);
  const status = result.response.status;
  if ([301, 302, 303, 307, 308].includes(status)) {
    const location = result.response.headers.get('location');
    assert.ok(location, 'Missing admin redirect target');
    const next = new URL(location, current);
    assert.equal(next.origin, origin, 'Admin must not redirect outside staging');
    current = next;
    continue;
  }
  assert.notEqual(status, 503, 'Hosted admin is not ready: entrypoint prerequisites or maintenance still block startup');
  assert.equal(status, 200, `Hosted admin/login failed with HTTP ${status}`);
  assert.match(result.body, /type=["']password["']/i, 'Expected native login password field, not an empty shell or public page');
  assert.match(result.body, /name=["']_token["']/i, 'Expected CSRF-protected native login form');
  assert.doesNotMatch(result.body, /whoops|stack trace|SQLSTATE\[/i, 'Runtime/debug error page');
  login = current.pathname;
  break;
}
assert.ok(login, 'Admin redirect loop or missing login');
checks.push(`native login at ${login}`);
console.log(`PASS hosted read-only staging smoke: ${checks.join('; ')}. Authentication, database writes and TOTP not tested.`);

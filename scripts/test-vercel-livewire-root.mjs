#!/usr/bin/env node
/**
 * The Laravel admin can be deployed to Vercel in two ways, and both must serve the same entry:
 *
 *   - as a package built by scripts/package-vercel-livewire.mjs (deploy/vercel-livewire/*), and
 *   - straight from Git, as a second Vercel project whose Root Directory is `platform/`
 *     (platform/api/index.php, platform/vercel.json).
 *
 * Two copies of an entry point drift. This fails when they differ, and when the entry stops refusing
 * to start without its configuration.
 */
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';

for (const [packaged, inRoot] of [
  ['deploy/vercel-livewire/index.php', 'platform/api/index.php'],
  ['deploy/vercel-livewire/vercel.json', 'platform/vercel.json'],
]) {
  assert.equal(readFileSync(inRoot, 'utf8'), readFileSync(packaged, 'utf8'), `${inRoot} differs from ${packaged}`);
}

const entry = readFileSync('platform/api/index.php', 'utf8');
for (const name of ['APP_KEY', 'DB_CONNECTION', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD']) {
  assert.ok(entry.includes(`'${name}'`), `the entry no longer requires ${name}`);
}
assert.match(entry, /getenv\('DB_CONNECTION'\) === 'mysql'/, 'the entry must refuse any database but MySQL');
assert.match(entry, /http_response_code\(503\)/, 'the entry must answer 503 when it is not configured');

const config = JSON.parse(readFileSync('platform/vercel.json', 'utf8'));
assert.equal(config.builds.find((build) => build.src === 'api/index.php')?.use, 'vercel-php@0.9.0');
assert.deepEqual(config.routes.at(-1), { src: '/(.*)', dest: '/api/index.php' }, 'every request must reach Laravel through the entry');
for (const forbidden of ['APP_KEY', 'DB_PASSWORD', 'DB_HOST', 'DB_USERNAME', 'DB_DATABASE']) {
  assert.ok(!(forbidden in (config.env ?? {})), `${forbidden} must be set in Vercel's settings, never in the repository`);
}
console.log('Vercel entry for the Laravel admin: the Git-root copy matches the packaged one, refuses to start unconfigured, and carries no secret.');

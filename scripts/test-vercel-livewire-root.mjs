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
import { readdirSync, readFileSync, statSync } from 'node:fs';

for (const [packaged, inRoot] of [
  ['deploy/vercel-livewire/index.php', 'platform/api/index.php'],
  ['deploy/vercel-livewire/vercel.json', 'platform/vercel.json'],
]) {
  assert.equal(readFileSync(inRoot, 'utf8'), readFileSync(packaged, 'utf8'), `${inRoot} differs from ${packaged}`);
}

// --- the entry ---------------------------------------------------------------------------------------------------
const entry = readFileSync('platform/api/index.php', 'utf8');
for (const name of ['APP_KEY', 'DB_CONNECTION', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD']) {
  assert.ok(entry.includes(`'${name}'`), `the entry no longer requires ${name}`);
}
assert.match(entry, /\$demo \|\| \$read\('DB_CONNECTION'\) === 'mysql'/, 'outside a demo the entry must refuse any database but MySQL');
assert.match(entry, /http_response_code\(503\)/, 'the entry must answer 503 when it is not configured');
assert.match(entry, /\$demo = \$read\('WOODEX_DEMO'\) === '1';/, 'a demo is switched on by WOODEX_DEMO=1 and nothing else');
// A demo must be unable to reach anything real: it is given a SQLite file, and every setting that names a server
// or holds a key is emptied. The behaviour is checked by platform/tools/vercel/check-entry.sh; this keeps the
// list of what is emptied from shrinking unnoticed.
const emptied = /preg_match\('\/\^\(([A-Z_|]+)\)_\|\(([A-Z_|]+)\)\$\|/.exec(entry);
assert.ok(emptied, 'the entry must empty real settings in a demo');
for (const prefix of ['DB', 'LEGACY_DB', 'MAIL', 'REDIS', 'WHATSAPP', 'TELEGRAM', 'GOOGLE', 'TURNSTILE', 'AWS', 'WX_PW']) {
  assert.ok(emptied[1].split('|').includes(prefix), `a demo must empty every ${prefix}_* setting`);
}
for (const suffix of ['_KEY', '_TOKEN', '_SECRET', '_PASSWORD']) {
  assert.ok(emptied[2].split('|').includes(suffix), `a demo must empty every *${suffix} setting`);
}
// A demo encrypts with a key the build made for it, never with the project's APP_KEY: a link signed in the demo
// would otherwise also be accepted by the real admin.
assert.match(entry, /\$required = \$demo \? \[\] : \['APP_KEY',/, 'only a real deployment takes APP_KEY from the project settings');
assert.ok(entry.includes("'APP_KEY' => $key,") && entry.includes("file_get_contents($built.'.key')"), 'a demo must take its key from the file the build made');
assert.ok(!/\$name === 'APP_KEY'/.test(entry), "the project's APP_KEY must be emptied in a demo like every other key");
for (const pair of ["'DB_CONNECTION' => 'sqlite'", "'MAIL_MAILER' => 'log'", "'APP_DEBUG' => 'false'", "'SESSION_DRIVER' => 'cookie'"]) {
  assert.ok(entry.includes(pair), `a demo must set ${pair}`);
}
assert.match(entry, /\$write\('LARAVEL_STORAGE_PATH', \$storage\);/, 'the storage path must be written to $_SERVER and $_ENV, where Laravel reads it');
assert.match(entry, /\$write\('WOODEX_CONFIG_FROM_SERVER', '1'\);/, 'the entry must mark the site as configured by the hosting platform');

// --- vercel.json -------------------------------------------------------------------------------------------------
const config = JSON.parse(readFileSync('platform/vercel.json', 'utf8'));
assert.equal(config.builds.find((build) => build.src === 'api/index.php')?.use, 'vercel-php@0.9.0');
for (const forbidden of ['APP_KEY', 'DB_PASSWORD', 'DB_HOST', 'DB_USERNAME', 'DB_DATABASE', 'WX_PW_OWNER', 'WX_PW_MANAGER', 'WX_PW_SALES', 'WX_PW_ACCOUNTS']) {
  assert.ok(!(forbidden in (config.env ?? {})), `${forbidden} must be set in Vercel's settings, never in the repository`);
}
// Whether a deployment is a demo is decided in the Vercel project, not in the repository: otherwise every
// deployment of this code, the real one included, would serve sample data.
for (const name of ['WOODEX_DEMO', 'WOODEX_DEMO_SHOW_PASSWORD']) {
  assert.ok(!(name in (config.env ?? {})), `${name} must not be set in the repository`);
}
assert.equal(config.env.TRUSTED_PROXIES, '*', 'behind Vercel the forwarded scheme and host must be trusted, or links are built with http');

// Routes: the first whose pattern matches the whole path decides (Vercel's rule for "routes"). The routes come in
// phases: those before the first {"handle": …} say where a path goes; those after {"handle": "hit"} add headers
// to an answer that was found.
const FUNCTION = '/api/index.php';
const phases = { first: [] };
let phase = 'first';
for (const route of config.routes) {
  if (route.handle) {
    assert.deepEqual(Object.keys(route), ['handle'], 'a route that names a phase says nothing else');
    assert.ok(!(route.handle in phases), `the phase "${route.handle}" is named twice`);
    phase = route.handle;
    phases[phase] = [];
    continue;
  }
  phases[phase].push(route);
}
const whole = (pattern) => new RegExp(`${pattern.startsWith('^') ? '' : '^'}${pattern}${pattern.endsWith('$') ? '' : '$'}`);
const destination = (path) => {
  for (const route of phases.first) {
    const match = whole(route.src).exec(path);
    if (match) return route.dest.replace(/\$(\d+)/g, (_, index) => match[Number(index)] ?? '');
  }
  return null;
};
assert.deepEqual(Object.keys(phases), ['first', 'hit'], 'vercel.json has the routes that decide and the routes for an answer that was found, and no other phase');
assert.deepEqual(phases.first.at(-1), { src: '/(.*)', dest: FUNCTION }, 'everything else must reach Laravel through the entry');
// Laravel's own addresses, also the ones that look like files.
for (const path of ['/', '/admin', '/admin/login', '/up', '/install', '/livewire-4e18b90f/livewire.min.js', '/livewire-4e18b90f/update',
  '/assets/theme.css', '/robots.txt', '/sitemap.xml', '/storage/some/file.pdf', '/api/v1/leads', '/media/1/photo.jpg']) {
  assert.equal(destination(path), FUNCTION, `${path} must reach Laravel`);
}
// Never handed out as a file: PHP source and anything in a folder or file whose name starts with a dot.
for (const path of ['/index.php', '/public/index.php', '/api/index.php', '/build/x.php', '/assets/uploads/shell.php', '/.env', '/.htaccess',
  '/assets/uploads/.htaccess', '/build/.vite/manifest.json', '/composer.json', '/vendor/autoload.php', '/database/woodex-synthetic-vercel-demo.sqlite']) {
  assert.equal(destination(path), FUNCTION, `${path} must not be served as a static file`);
}
// Every file in public/ that a page may load is served: a new folder or file there needs a route.
const publicFiles = [];
const walk = (folder) => {
  for (const name of readdirSync(folder)) {
    const path = `${folder}/${name}`;
    if (statSync(path).isDirectory()) walk(path);
    else publicFiles.push(path.slice('platform/public'.length));
  }
};
walk('platform/public');
const unserved = publicFiles.filter((path) => !path.endsWith('.php') && !/(^|\/)\./.test(path) && destination(path) !== `/public${path}`);
assert.deepEqual(unserved, [], 'files in platform/public that no route of vercel.json serves (add the folder to a route)');
assert.ok(publicFiles.length > 100, 'platform/public was not read');

// --- how long a browser may keep a file (master list T-152) -------------------------------------------------------
// A file whose name carries a mark of its content never changes under that name: a browser may keep it for a year
// and need not ask again. Every other answer keeps what it has today. The rule stands in the phase for an answer
// that was found, so a name that is not there is not kept for a year either.
const YEAR = 'public, max-age=31536000, immutable';
for (const route of phases.hit) {
  // Vercel's own rules for this phase (@vercel/routing-utils, normalizeRoutes): a route here adds headers and
  // lets the routing go on; it cannot send the request elsewhere or set a status.
  assert.equal(route.continue, true, 'a route for an answer that was found must say "continue": true');
  assert.ok(!('dest' in route) && !('status' in route), 'a route for an answer that was found cannot name a destination or a status');
}
assert.ok(phases.first.every((route) => !('headers' in route)), 'no deciding route adds a header: a header there would also go out with "not found"');
/** What the routes say about keeping the answer for this destination, or null. The hit phase is tried on the destination. */
const kept = (target) => {
  let value = null;
  for (const route of phases.hit) {
    for (const [name, header] of Object.entries(route.headers ?? {})) {
      if (name.toLowerCase() === 'cache-control' && whole(route.src).test(target)) value = header;
    }
  }
  return value;
};
// The built files, by what the build itself says of them: Vite marks every name but the two it is told to keep.
const viteConfig = readFileSync('platform/vite.config.js', 'utf8');
const stableNames = [...viteConfig.matchAll(/'(assets\/[^'\[\]]+)'/g)].map((match) => match[1]).sort();
assert.deepEqual(stableNames, ['assets/site.css', 'assets/site.js'],
  'vite.config.js gives a built file a name without a mark: say here whether a browser may keep it (it may not, the name stays while the content changes)');
const manifest = JSON.parse(readFileSync('platform/public/build/manifest.json', 'utf8'));
const built = new Set();
for (const entry of Object.values(manifest)) for (const file of [entry.file, ...(entry.css ?? []), ...(entry.assets ?? [])]) built.add(file);
const onDisk = readdirSync('platform/public/build/assets').map((name) => `assets/${name}`).sort();
assert.deepEqual([...built].sort(), onDisk, 'public/build/assets holds a file the manifest does not name, or the other way round');
const marked = onDisk.filter((file) => !stableNames.includes(file));
assert.ok(marked.length >= 5, 'the built files were not read');
for (const file of marked) {
  assert.match(file, /^assets\/[^/]+-[A-Za-z0-9_-]{8}\.[A-Za-z0-9]+$/, `${file} is a built file whose name does not look marked: the rule for a year would miss it`);
  // As Vercel names the file it found, and as the visitor asked for it: the rule must hold either way.
  assert.equal(kept(`/public/build/${file}`), YEAR, `/build/${file} carries a mark of its content and must be kept for a year`);
  assert.equal(kept(`/build/${file}`), YEAR, `/build/${file} must be kept for a year also when the rule is tried on the address asked for`);
}
for (const file of stableNames) {
  assert.equal(kept(`/public/build/${file}`), null, `/build/${file} keeps its name when its content changes: a browser must ask again`);
  assert.equal(kept(`/build/${file}`), null, `/build/${file} keeps its name when its content changes: a browser must ask again`);
}
// Nothing else is kept: no other file of public/, no page, nothing Laravel answers.
const keptWrongly = publicFiles.filter((path) => !marked.includes(path.slice('/build/'.length)) && (kept(`/public${path}`) !== null || kept(path) !== null));
assert.deepEqual(keptWrongly, [], 'files of platform/public that would be kept for a year although their name carries no mark');
for (const path of ['/', '/admin', '/admin/login', '/up', '/api/index.php', '/build/manifest.json', '/public/build/manifest.json', '/livewire-4e18b90f/livewire.min.js',
  '/assets/admin-v21/app.css', '/favicon.svg', '/build/assets/', '/build/assets/admin.css', '/build/assets/sub/admin-Ct8n60mi.css', '/builder/assets/admin-Ct8n60mi.css']) {
  assert.equal(kept(path), null, `${path} must not be kept for a year`);
}

// --- the demo build ----------------------------------------------------------------------------------------------
// The demo's workspace is built during the deployment by "composer run vercel" (the vercel-php builder runs it).
const composer = JSON.parse(readFileSync('platform/composer.json', 'utf8'));
assert.deepEqual(composer.scripts.vercel, ['@php tools/vercel/build-demo.php']);
const build = readFileSync('platform/tools/vercel/build-demo.php', 'utf8');
assert.match(build, /if \(getenv\('WOODEX_DEMO'\) !== '1'\) \{[\s\S]{0,200}exit\(0\);/, 'the build step must do nothing unless the project is a demo');
const buildCode = build.replace(/\/\/.*$/gm, '').replace(/\/\*[\s\S]*?\*\//g, '');
assert.ok(!/DB_HOST|DB_USERNAME|DB_PASSWORD/.test(buildCode), 'the build step must not pass on MySQL settings');
assert.ok(!/getenv\('APP_KEY'\)/.test(buildCode) && /random_bytes\(32\)/.test(buildCode), "the build step must make the demo's own key, not read the project's");
const ignore = readFileSync('platform/.vercelignore', 'utf8').split('\n').map((line) => line.trim());
for (const needed of ['/tools', '/tools/vercel', '/tools/preview', '/tools/preview/synthetic_fixtures.php', '/database', '/vendor', '/public']) {
  assert.ok(!ignore.includes(needed), `${needed} must be uploaded: the demo is built with it`);
}
// The fixtures run without the development packages (Vercel installs none): no model factory, no faker.
const fixtures = readFileSync('platform/tools/preview/synthetic_fixtures.php', 'utf8');
assert.ok(!/factory\(\)|fake\(\)|Faker/.test(fixtures), 'the fixtures must not need fakerphp/faker, a development package');

console.log(`Vercel deployment of the Laravel admin: both copies of the entry match, it refuses to start unconfigured, a demo is kept away from real services, ${publicFiles.length} public files are routed, the ${marked.length} built files with a marked name may be kept for a year and nothing else is, and no secret is in the repository.`);

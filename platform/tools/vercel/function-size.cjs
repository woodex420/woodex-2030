#!/usr/bin/env node
/**
 * How large the function is that Vercel runs for the admin (master list T-153).
 *
 * Vercel refuses a function whose files are more than 250 MB unpacked. This counts the files the `vercel-php`
 * builder puts into the function, the way the builder collects them (vercel-php 0.9.0, dist/index.js and
 * dist/utils.js):
 *
 *   - the runtime: every file of @libphp's native/php, native/php/modules and native/lib, without php-cgi, php-fpm
 *     and php-fpm.ini, and the two launcher files;
 *   - the application: every file of the folder it is built in (dotfiles too, a link as a link), without
 *     .vercel/, node_modules/, now.json, .nowignore, vercel.json and .vercelignore, or without what
 *     "config.excludeFiles" of the build in vercel.json names, when that is set.
 *
 * .vercelignore is not applied, because Vercel does not apply it to this project: its build log says
 * "Removed 0 ignored files defined in .vercelignore" (docs/woodex-os/13-VERCEL-DEPLOYMENT.md). So tests/ and the
 * tools are counted, as they are in the function.
 *
 * Run it from platform/, after the packages were installed as the builder installs them and the demo's workspace
 * was built (tools/vercel/check-entry.sh --build does both):
 *
 *   node tools/vercel/function-size.cjs --runtime <node_modules with vercel-php> [--limit-mb 240] [--quiet] [--only-print]
 *
 * 1 MB is 1,048,576 bytes here, as in Vercel's own message when a function is too large. It ends with 1 when the
 * function is larger than the limit, and with 2 when it cannot measure. --quiet writes no notice on a GitHub run;
 * --only-print measures and judges nothing (for a tree with the development packages, which Vercel never has).
 */
const fs = require('node:fs');
const path = require('node:path');

const PLATFORM_LIMIT_MB = 250;
const MB = 1024 * 1024;
const option = (name, fallback) => {
  const index = process.argv.indexOf(name);
  return index === -1 ? fallback : process.argv[index + 1];
};
const stop = (message) => { console.error(`function-size: ${message}`); process.exit(2); };

const runtime = option('--runtime', process.env.VERCEL_PHP || '');
const limit = Number(option('--limit-mb', '240'));
const tree = path.resolve(option('--tree', '.'));
if (!runtime) stop('name the node_modules folder that holds vercel-php with --runtime (or VERCEL_PHP).');
if (!Number.isFinite(limit) || limit <= 0) stop('--limit-mb must be a number above 0.');
if (limit > PLATFORM_LIMIT_MB) stop(`--limit-mb ${limit} is above the ${PLATFORM_LIMIT_MB} MB that Vercel allows.`);
for (const needed of ['api/index.php', 'vercel.json', 'composer.json']) {
  if (!fs.existsSync(path.join(tree, needed))) stop(`${needed} is not in ${tree}: run this from platform/.`);
}
if (!fs.existsSync(path.join(tree, 'vendor/autoload.php'))) stop('vendor/ is missing: the packages are part of the function.');

// --- what the builder leaves out ---------------------------------------------------------------------------------
const builderFolder = path.join(runtime, 'vercel-php');
const builderSource = (() => {
  try { return fs.readFileSync(path.join(builderFolder, 'dist/index.js'), 'utf8'); } catch { return stop(`vercel-php is not in ${runtime}.`); }
})();
const DEFAULT_EXCLUDES = ['node_modules/**', 'now.json', '.nowignore', 'vercel.json', '.vercelignore'];
// The list above is written out in the builder's source. A builder that leaves out something else must be read
// again before its function is measured.
for (const piece of ["'.vercel/**'", ...DEFAULT_EXCLUDES.map((name) => `'${name}'`), 'config.excludeFiles', "glob)('**'"]) {
  if (!builderSource.includes(piece)) stop(`this vercel-php collects the application's files in another way than this script knows (${piece} is not in its dist/index.js). Read its build() and bring this script up to date.`);
}
const config = JSON.parse(fs.readFileSync(path.join(tree, 'vercel.json'), 'utf8'));
const build = (config.builds || []).find((entry) => entry.src === 'api/index.php');
if (!build) stop('vercel.json has no build for api/index.php.');
const version = JSON.parse(fs.readFileSync(path.join(builderFolder, 'package.json'), 'utf8')).version;
if (build.use !== `vercel-php@${version}`) stop(`vercel.json builds with ${build.use}, and ${runtime} holds vercel-php@${version}.`);
const configured = build.config?.excludeFiles;
const excludes = ['.vercel/**', ...(configured ? (Array.isArray(configured) ? configured : [configured]) : DEFAULT_EXCLUDES)];
/** Whether a path (relative to the tree) is left out. Only the two forms the lists above use are understood. */
const excluded = (relative) => excludes.some((pattern) => {
  if (pattern.endsWith('/**') && !/[*?[\]{}!]/.test(pattern.slice(0, -3))) return relative.startsWith(`${pattern.slice(0, -3)}/`);
  if (!/[*?[\]{}!]/.test(pattern)) return relative === pattern;
  return stop(`"${pattern}" in excludeFiles is a pattern this script cannot read; teach it first.`);
});

// --- the application ---------------------------------------------------------------------------------------------
const parts = { 'the packages (vendor/)': 0, 'public/': 0, 'tests/ and tools/': 0, 'the rest of the application': 0 };
let files = 0;
const partOf = (relative) => {
  if (relative.startsWith('vendor/')) return 'the packages (vendor/)';
  if (relative.startsWith('public/')) return 'public/';
  if (relative.startsWith('tests/') || relative.startsWith('tools/')) return 'tests/ and tools/';
  return 'the rest of the application';
};
const walk = (folder) => {
  for (const entry of fs.readdirSync(path.join(tree, folder), { withFileTypes: true })) {
    const relative = folder ? `${folder}/${entry.name}` : entry.name;
    if (excluded(relative)) continue;
    // A link is kept as a link, not followed: the builder does the same.
    if (entry.isDirectory()) { walk(relative); continue; }
    parts[partOf(relative)] += fs.lstatSync(path.join(tree, relative)).size;
    files += 1;
  }
};
walk('');

// --- the runtime -------------------------------------------------------------------------------------------------
const libphpName = /require\("(@libphp\/[^"]+)"\)/.exec(fs.readFileSync(path.join(builderFolder, 'dist/utils.js'), 'utf8'))?.[1];
if (!libphpName) stop("vercel-php's dist/utils.js does not name its @libphp package.");
const utilsSource = fs.readFileSync(path.join(builderFolder, 'dist/utils.js'), 'utf8');
const DROPPED = ['php/php-cgi', 'php/php-fpm', 'php/php-fpm.ini'];
for (const name of DROPPED) {
  if (!utilsSource.includes(`delete files['${name}']`)) stop(`this vercel-php no longer leaves out ${name}: read its getPhpFiles() and bring this script up to date.`);
}
const sizeOf = (file) => fs.lstatSync(file).size;

(async () => {
  let runtimeFiles;
  try { runtimeFiles = await require(path.join(runtime, libphpName)).getFiles(); } catch (error) { return stop(`${libphpName} could not list its files: ${error.message}`); }
  for (const name of DROPPED) delete runtimeFiles[name];
  let runtimeBytes = 0;
  for (const file of Object.values(runtimeFiles)) runtimeBytes += sizeOf(file);
  for (const launcher of ['dist/launchers/helpers.js', 'dist/launchers/builtin.js']) runtimeBytes += sizeOf(path.join(builderFolder, launcher));
  files += Object.keys(runtimeFiles).length + 2;

  const applicationBytes = Object.values(parts).reduce((sum, bytes) => sum + bytes, 0);
  const total = runtimeBytes + applicationBytes;
  const mb = (bytes) => (bytes / MB).toFixed(1);
  const development = fs.existsSync(path.join(tree, 'vendor/pestphp')) || fs.existsSync(path.join(tree, 'vendor/fakerphp'));
  const demo = fs.existsSync(path.join(tree, 'database/woodex-synthetic-vercel-demo.sqlite'));

  const lines = [
    `Vercel's PHP runtime (${libphpName}, vercel-php ${version}): ${mb(runtimeBytes)} MB`,
    ...Object.entries(parts).map(([name, bytes]) => `${name}: ${mb(bytes)} MB`),
  ];
  const judged = !process.argv.includes('--only-print');
  const summary = `${mb(total)} MB in ${files} files (${total} bytes); ${judged ? `the limit here is ${limit} MB, ` : ''}Vercel's limit is ${PLATFORM_LIMIT_MB} MB`;
  const notes = [
    development ? 'development packages are installed here, and Vercel installs none: the function is smaller there' : null,
    demo ? null : "the demo's workspace is not built here: about 2 MB more on Vercel",
  ].filter(Boolean);
  const over = judged && total > limit * MB;

  console.log(`The function: ${summary}`);
  for (const line of lines) console.log(`    ${line}`);
  for (const note of notes) console.log(`    note: ${note}`);
  if (process.env.GITHUB_ACTIONS && !process.argv.includes('--quiet')) {
    const text = `${summary} — ${lines.join('; ')}${notes.length ? ` — ${notes.join('; ')}` : ''}`;
    console.log(`::${over ? 'error' : 'notice'} title=${over ? 'The function is too large' : 'Size of the function'}::${text}`);
  }
  if (over) {
    console.error(`function-size: the function is ${mb(total)} MB, more than the ${limit} MB allowed here. Vercel refuses a function above ${PLATFORM_LIMIT_MB} MB.`);
    process.exit(1);
  }
})();

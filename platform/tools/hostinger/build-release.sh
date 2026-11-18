#!/usr/bin/env bash
# WOODEX OS: a release, the website folder as the host receives it.
#
# Shared hosting takes a folder of files, by Git from one branch or as an upload, and is not relied on to run
# anything on it: no Composer and no Node. So a release already holds everything the admin needs to start. This
# script makes that folder from the repository as it is checked out:
#
#   <out>/.htaccess, <out>/index.php   the bridge from the website's folder into platform/public
#   <out>/platform/                    the application without its tests and tools, with its packages (vendor/,
#                                      no development packages) and the built stylesheet and scripts
#   <out>/RELEASE.json                 which commit this is, and when it was put together
#   <out>/vercel.json, <out>/platform/vercel.json   tell Vercel not to build the branch that carries this
#
#   bash platform/tools/hostinger/build-release.sh <out: a folder that is empty or does not exist>
#
# It holds no settings: no .env, no database, no key. The installer writes those on the host, outside this folder.
#
#   PHP_BIN        the PHP to use (default: php); 8.4.1 or newer
#   COMPOSER_BIN   how Composer is started (default: composer); may be several words
#
# The sandbox has neither on its path: see platform/tools/preview/sandbox/setup.sh for where it keeps them.
set -euo pipefail

out="${1:-}"
[ -n "$out" ] || { echo "usage: build-release.sh <out folder>" >&2; exit 2; }
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../.." && pwd)"
php="${PHP_BIN:-php}"
read -r -a composer <<< "${COMPOSER_BIN:-composer}"

mkdir -p "$out"
[ -z "$(ls -A "$out")" ] || { echo "$out is not empty: a release is put together in an empty folder" >&2; exit 2; }
out="$(cd "$out" && pwd)"

# 1. The files of the repository, as they are in the working tree: tracked files and new files that Git would take.
(cd "$root" && git ls-files -z --cached --others --exclude-standard -- platform index.php .htaccess \
  | tar --null --ignore-failed-read -T - -cf - 2>/dev/null) | tar -xf - -C "$out"

# 2. What a host never needs: tests, the tools of the sandbox and of Vercel, Vercel's entry file, the sources of
#    the build, and the record of where the imported files came from.
cd "$out/platform"
rm -rf tests tools api PROVENANCE node_modules
rm -f phpunit.xml phpstan.neon pint.json package.json package-lock.json vite.config.js PROVENANCE.md
# The working copy's own housekeeping. The ignore list would keep vendor/ out of the branch that carries a release,
# and the attributes file would have Git rewrite line ends inside the packages.
rm -f .gitignore .gitattributes .editorconfig .npmrc .vercelignore
rm -f .env .env.* database/*.sqlite* public/hot bootstrap/cache/*.php
[ -s public/build/manifest.json ] || { echo "platform/public/build/manifest.json is missing: the built stylesheet and scripts are committed with the code" >&2; exit 1; }

# 3. The packages, from the lock file, without the development ones.
log="$(mktemp)"
"${composer[@]}" install --no-dev --prefer-dist --no-interaction --no-progress --no-scripts --optimize-autoloader > "$log" 2>&1 \
  || { cat "$log" >&2; rm -f "$log"; echo "Composer could not install the packages" >&2; exit 1; }
rm -f "$log"
[ -s vendor/autoload.php ] || { echo "Composer left no vendor/autoload.php" >&2; exit 1; }
# Which packages Laravel loads is written down now. A list left over from an earlier release would name packages
# that are gone, and the host has no command line to write it again.
APP_ENV=production APP_DEBUG=false "$php" artisan package:discover >/dev/null
[ -s bootstrap/cache/packages.php ] && [ -s bootstrap/cache/services.php ] || { echo "the package lists were not written" >&2; exit 1; }
if grep -l "$out" bootstrap/cache/*.php >/dev/null 2>&1; then echo "a package list holds this machine's folder name" >&2; exit 1; fi

# A release travels through Git: the branch that carries it, the host's Git page, GitHub's ZIP of that branch. An
# attributes file inside a package would have Git rewrite line ends on the way, or leave folders out of the ZIP.
# Nothing reads these files when the application runs.
find "$out" -name .gitattributes -type f -delete

# 4. Vercel builds every branch it sees. The branch that carries this folder is not a Vercel site.
printf '{\n  "git": { "deploymentEnabled": false }\n}\n' > "$out/vercel.json"
printf '{\n  "git": { "deploymentEnabled": false }\n}\n' > "$out/platform/vercel.json"

# 5. Which commit this is.
commit="$(git -C "$root" rev-parse HEAD)"
dirty=false; [ -z "$(git -C "$root" status --porcelain -- platform index.php .htaccess)" ] || dirty=true
printf '{\n  "commit": "%s",\n  "uncommitted_changes": %s,\n  "built_at": "%s",\n  "source": "woodex420/woodex-2030",\n  "note": "Made by platform/tools/hostinger/build-release.sh. Holds no settings and no data."\n}\n' \
  "$commit" "$dirty" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$out/RELEASE.json"

# 6. Checked before anybody takes it.
cd "$out"
fail() { echo "release refused: $1" >&2; exit 1; }
for needed in .htaccess index.php platform/artisan platform/public/index.php platform/public/build/manifest.json \
              platform/vendor/autoload.php platform/bootstrap/app.php platform/bootstrap/cache/.gitignore \
              platform/storage/app/.gitignore platform/storage/framework/.gitignore platform/storage/logs/.gitignore; do
  [ -e "$needed" ] || fail "$needed is missing"
done
for never in platform/.env platform/tests platform/tools platform/api platform/node_modules platform/vendor/pestphp \
             platform/vendor/phpunit platform/vendor/mockery platform/vendor/fakerphp platform/vendor/larastan \
             platform/bootstrap/cache/config.php platform/bootstrap/cache/routes-v7.php platform/public/hot; do
  [ ! -e "$never" ] || fail "$never must not be in a release"
done
[ -z "$(find . -name '.env' -o -name '.env.*' -o -name '*.sqlite' -o -name '*.sqlite-*' -o -name '*.pem' -o -name 'id_rsa*' | head -1)" ] || fail "a settings file, a database or a key is in the tree"
[ -z "$(find . -type l | head -1)" ] || fail "a link is in the tree: $(find . -type l | head -1)"
[ -z "$(find . -name .git | head -1)" ] || fail "a Git folder is in the tree: $(find . -name .git | head -1)"
[ -z "$(find . -name .gitattributes | head -1)" ] || fail "a Git attributes file is in the tree: $(find . -name .gitattributes | head -1)"
[ -z "$(find . -type f -size +50M | head -1)" ] || fail "a file of more than 50 MB is in the tree: $(find . -type f -size +50M | head -1)"
"$php" -r '
$manifest = json_decode((string) file_get_contents("platform/public/build/manifest.json"), true);
foreach (["resources/css/admin.css", "resources/js/admin.js", "resources/js/auth.js"] as $entry) {
    $file = $manifest[$entry]["file"] ?? null;
    if ($file === null || ! is_file("platform/public/build/".$file)) { fwrite(STDERR, "release refused: the build has no file for $entry\n"); exit(1); }
}
require "platform/vendor/autoload.php";
foreach (["Illuminate\\Foundation\\Application", "Livewire\\Livewire", "Dompdf\\Dompdf"] as $class) {
    if (! class_exists($class)) { fwrite(STDERR, "release refused: $class cannot be loaded from vendor/\n"); exit(1); }
}
'
files="$(find . -type f | wc -l | tr -d ' ')"
size="$(du -sm . | cut -f1)"
echo "release of ${commit:0:7}$([ "$dirty" = true ] && echo ' with uncommitted changes') in $out: $files files, $size MB (vendor $(du -sm platform/vendor | cut -f1) MB)"

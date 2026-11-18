#!/usr/bin/env bash
# WOODEX OS: what the website's folder hands out, asked from outside.
#
#   bash platform/tools/hostinger/check-bridge.sh <address of the site> [<website folder, to pick real file names>]
#
# A release lies inside a folder that the web server serves. The .htaccess of that folder decides what a visitor
# gets: a file of Laravel's public folder, the application, or a refusal. This asks for one address of every
# kind and compares the answer with what the file promises. It reads only; it works on a site that is installed
# and on one that is not.
#
# Run against Apache with the real .htaccess in CI (workflow os-ci.yml, job "hostinger"), and against the
# stand-in of the sandbox (router.php), which has to give the same answers.
set -uo pipefail

base="${1:?the address of the site, e.g. http://127.0.0.1:8080}"; base="${base%/}"
site="${2:-}"
failed=0; asked=0
tmp="$(mktemp -d)"; trap 'rm -rf "$tmp"' EXIT

ask() { curl -s -o "$tmp/body" -D "$tmp/head" --path-as-is --max-time 30 -w '%{http_code}' "$base$1" 2>/dev/null || echo 000; }
found=""
wrong() { failed=$((failed + 1)); echo "  WRONG  $1"; found="$found | $1"; }
header() { grep -i "^$1:" "$tmp/head" | tail -1 | tr -d '\r' | cut -d: -f2- | sed 's/^ *//'; }

# 1. Refused by the web server itself, before any PHP starts: 403.
refused=(
  /.htaccess /.env /.git/config /.gitignore /platform /platform/ /platform/.env /platform/artisan
  /platform/composer.json /platform/composer.lock /platform/vendor/autoload.php /platform/storage/logs/laravel.log
  /platform/bootstrap/cache/packages.php /platform/database/migrations/ /platform/public/index.php
  /platform/public/build/manifest.json /platform/app/Support/Install/Installer.php /platform/config/app.php
  /PLATFORM/composer.json /Platform/vendor/autoload.php /%70latform/composer.json /platform%2Fcomposer.json
  /vendor/autoload.php /storage/logs/laravel.log /config/app.php /database/database.sqlite /bootstrap/app.php
  /resources/views/welcome.blade.php /tests/Pest.php /composer.json /composer.lock /package.json /vercel.json
  /RELEASE.json /release.json /WOODEX-SETUP-CODE.txt /woodex-setup-code.txt /README.md /build/.htaccess
  /build/../../platform/.env /build/..%2F..%2Fplatform/composer.json
)
for path in "${refused[@]}"; do
  asked=$((asked + 1)); status="$(ask "$path")"
  # An address with an encoded slash, or one that climbs out of the folder, is refused by Apache before any rule
  # is read (404, 400). Inside platform/public Apache reads that folder's own .htaccess instead of the website
  # folder's, and it sends the visitor to the clean address (301). Nothing is handed out in any of these cases.
  ok=no
  [ "$status" = 403 ] && ok=yes
  [[ "$path" == *%2F* || "$path" == *..* ]] && { [ "$status" = 404 ] || [ "$status" = 400 ]; } && ok=yes
  [[ "$path" == /platform/public/* ]] && [ "$status" = 301 ] && [[ "$(header location)" != *platform* ]] && ok=yes
  [ "$ok" = yes ] || wrong "$path answered $status, 403 was expected"
  if grep -q -E '"require"|APP_KEY=|<\?php|RewriteRule|"commit"' "$tmp/body" 2>/dev/null; then wrong "$path handed out a file"; fi
done

# 2. The root of the site is the shop's: forwarded, and a query is not carried along.
asked=$((asked + 2))
status="$(ask /)"; [ "$status" = 302 ] && [ "$(header location)" = "https://woodex-2030.vercel.app/" ] || wrong "/ answered $status to \"$(header location)\""
status="$(ask '/?token=private')"; [ "$status" = 302 ] && [ "$(header location)" = "https://woodex-2030.vercel.app/" ] || wrong "/?token=… answered $status to \"$(header location)\": the query must not be forwarded"

# 3. Files of Laravel's public folder are served as they are, with a type a browser accepts under nosniff.
served() { # path, what the Content-Type must contain
  asked=$((asked + 1)); status="$(ask "$1")"
  [ "$status" = 200 ] || { wrong "$1 answered $status, the file was expected"; return; }
  case "$(header content-type)" in *"$2"*) ;; *) wrong "$1 has the type \"$(header content-type)\", $2 was expected" ;; esac
  [ "$(header x-content-type-options)" = nosniff ] || wrong "$1 is served without X-Content-Type-Options: nosniff"
  [ -s "$tmp/body" ] || wrong "$1 is empty"
}
served /build/manifest.json json
served /favicon.svg svg
served /favicon.ico image/
if [ -n "$site" ]; then
  css="$(cd "$site/platform/public" && ls build/assets/admin-*.css | head -1)"; js="$(cd "$site/platform/public" && ls build/assets/admin-*.js | head -1)"
  font="$(cd "$site/platform/public" && ls build/assets/*.woff2 | head -1)"; module="$(cd "$site/platform/public" && ls build/assets/*.mjs | head -1)"
  served "/$css" text/css; served "/$js" javascript; served "/$font" font/woff2; served "/$module" javascript
fi

# 4. Never a script as a file, never a folder as a list: these are the application's.
# The last one is Laravel's public folder asked for under a disguised name: it may answer as the application
# does (that folder is public by nature), never with a script's text.
for path in /index.php /build/ /build/assets/ /fonts/ /images/ /%70latform/public/index.php /%70latform/public/; do
  asked=$((asked + 1)); status="$(ask "$path")"
  case "$status" in 200|301|302|403|404) ;; *) wrong "$path answered $status" ;; esac
  if grep -q -E '<\?php|Index of /' "$tmp/body" 2>/dev/null; then wrong "$path handed out a script or a list of files"; fi
done

# 5. The application answers its own addresses through the bridge.
asked=$((asked + 3))
status="$(ask /up)"; [ "$status" = 200 ] || wrong "/up (the application's own sign of life) answered $status"
# robots.txt is the application's to answer (a site without public pages has none: 404), never a file left behind.
status="$(ask /robots.txt)"; case "$status" in 200|302|404) ;; *) wrong "/robots.txt answered $status: it is always the application's" ;; esac
status="$(ask /admin/login)"; case "$status" in 200|302) ;; *) wrong "/admin/login answered $status" ;; esac

# On GitHub the result is also written as one line on the run page, readable without the log.
note() { [ "${GITHUB_ACTIONS:-}" = true ] && echo "::$1 title=The website's folder, asked from outside::$(echo "$2" | tr '\n' ' ' | cut -c1-1500)"; return 0; }
if [ "$failed" -gt 0 ]; then
  echo "$failed of $asked answers are not what the folder's .htaccess promises"
  note error "$failed of $asked answers are not what the folder's .htaccess promises$found"
  exit 1
fi
echo "$asked addresses asked: every answer is what the folder's .htaccess promises"
note notice "$asked addresses asked at $base: nothing of the application's own files is handed out, the public files are served, the rest is the application's"

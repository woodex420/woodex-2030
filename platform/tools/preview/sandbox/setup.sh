#!/usr/bin/env bash
# Builds, outside the repository, what a sandbox needs to work on this application when only GitHub and npm can be
# reached (see ../README.md, "In a sandbox that can reach only GitHub and npm"). About one minute.
#
#   bash platform/tools/preview/sandbox/setup.sh [dir]        (default: ~/.cache/woodex-os)
#
# What it leaves in <dir>:
#   php85              PHP 8.5 as Vercel's builder ships it (npm: vercel-php), with pdo_mysql, exif, xmlwriter, gd
#   composer/          Composer, run from its source
#   work/platform      a copy of platform/ with vendor/ installed and no .env: the tests run here, as in CI
#   work/vercel-emu    the same, for the rehearsal of the Vercel deployment
#   bin/wxsync [dest]  copy the repository's platform/ tree into a work tree (after every edit)
#   bin/wxtest <args>  Pest in work/platform with the settings CI uses
#   bin/wxrehearse [port]  serve the demo as Vercel does, on 127.0.0.1:<port> (log: /tmp/rehearsal-<port>.log)
#   bin/wxenv          source it: the rehearsal's passwords (made here, for this machine only) and the browser
#
# The whole suite:  cd <dir>/work/platform && WOODEX_PHP=<dir>/php85 sh tools/preview/sandbox/run-suite.sh <out>
# Code style:       cd platform && <dir>/php85 <dir>/work/platform/vendor/bin/pint --config pint.json <files>
# Nothing here is a secret, and nothing is written into the repository.
set -u
R="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
W="${1:-$HOME/.cache/woodex-os}"
mkdir -p "$W/bin" "$W/work"
say() { echo "[$(date -u +%T)] $*"; }
DIRS="bootstrap/cache storage/framework/cache/data storage/framework/sessions storage/framework/testing storage/framework/views storage/logs storage/app/private storage/app/public"

( cd "$R" && npm ci --legacy-peer-deps --no-audit --no-fund --ignore-scripts > "$W/npm-ci.log" 2>&1; say "the scripts' packages (repository root): exit $?" ) &
ROOT_NPM=$!

npm install --prefix "$W/vercel-php" --no-audit --no-fund vercel-php@0.9.0 > "$W/vercel-php.log" 2>&1; say "PHP from npm: exit $?"
cat > "$W/php85" <<SH
#!/bin/bash
N="\$(ls -d "$W"/vercel-php/node_modules/@libphp/*/native | head -1)"
LD_LIBRARY_PATH="\$N/lib:/usr/lib64:/lib64" PHP_INI_SCAN_DIR=":$W/vercel-php/node_modules/vercel-php/conf" PHP_INI_EXTENSION_DIR="\$N/php/modules" exec "\$N/php/php" -d opcache.enable=0 -d opcache.enable_cli=0 -d memory_limit=1024M "\$@"
SH
cat > "$W/bin/wxsync" <<SH
#!/bin/sh
# Copy the repository's platform/ tree into a work tree that has vendor/. A file deleted in the repository stays in
# the work tree: remove it there by hand.
T="\${1:-$W/work/platform}"
mkdir -p "\$T"
tar -C "$R/platform" --exclude=./vendor --exclude=./node_modules --exclude=./.env --exclude='./database/*.sqlite*' --exclude=./storage/framework --exclude=./storage/logs --exclude=./bootstrap/cache -cf - . | tar -C "\$T" -xf -
cd "\$T" && mkdir -p $DIRS
SH
cat > "$W/bin/wxtest" <<SH
#!/bin/sh
# Pest as CI runs it: no .env, the testing settings in the environment.
cd "$W/work/platform" || exit 2
export APP_ENV=testing APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= APP_URL=http://localhost
exec "$W/php85" vendor/bin/pest "\$@"
SH
[ -f "$W/bin/wxenv" ] || cat > "$W/bin/wxenv" <<SH
# source this: the rehearsal's passwords (for this machine only) and the browser that came from npm
export WX_PW_OWNER=rehearsal-$(openssl rand -hex 10) WX_PW_MANAGER=rehearsal-$(openssl rand -hex 10) WX_PW_SALES=rehearsal-$(openssl rand -hex 10) WX_PW_ACCOUNTS=rehearsal-$(openssl rand -hex 10)
export AWS_EXECUTION_ENV=AWS_Lambda_nodejs22.x AWS_LAMBDA_JS_RUNTIME=nodejs22.x
export LD_LIBRARY_PATH=/tmp:/tmp/al2023/lib:\${LD_LIBRARY_PATH:-} FONTCONFIG_PATH=/tmp/fonts
export E2E_CHROMIUM_PATH=/tmp/chromium
export E2E_CHROMIUM_ARGS="--no-sandbox --disable-setuid-sandbox --disable-dev-shm-usage --use-gl=angle --use-angle=swiftshader --enable-unsafe-swiftshader"
export E2E_REQUIRED=1
SH
cat > "$W/bin/wxrehearse" <<SH
#!/bin/bash
# Serve the rehearsal from the repository's current tree. DOCUMENTS_OWNER_CONFIRMED and WOODEX_MODULES are handed on.
PORT="\${1:-8191}"
"$W/bin/wxsync" "$W/work/vercel-emu"
cd "$W/work/vercel-emu" || exit 2
. "$W/bin/wxenv"
unset LD_LIBRARY_PATH
export VERCEL_PHP="$W/vercel-php/node_modules"
echo "rehearsal starting on \$PORT (log: /tmp/rehearsal-\$PORT.log)"
exec bash tools/vercel/serve-demo.sh "\$PORT" > "/tmp/rehearsal-\$PORT.log" 2>&1
SH
chmod +x "$W/php85" "$W/bin/wxsync" "$W/bin/wxtest" "$W/bin/wxrehearse"
say "php: $("$W/php85" -v 2>&1 | head -1)"

python3 "$R/platform/tools/preview/sandbox/bootstrap_composer.py" "$W/composer" 2.11.2 > "$W/composer.log" 2>&1; say "Composer from source: exit $?"
"$W/bin/wxsync"
cd "$W/work/platform" || exit 2
COMPOSER_HOME="$W/composer-home" COMPOSER_ALLOW_SUPERUSER=1 COMPOSER_AUTH="{\"github-oauth\":{\"github.com\":\"$(gh auth token)\"}}" \
  "$W/php85" "$W/composer/composer-src/bin/composer" install --no-interaction --prefer-dist --no-scripts --no-progress > "$W/vendor.log" 2>&1; say "the application's packages: exit $?"
APP_ENV=testing APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= APP_URL=http://localhost "$W/php85" artisan package:discover > "$W/discover.log" 2>&1; say "package:discover: exit $?"
"$W/bin/wxsync" "$W/work/vercel-emu" && { [ -d "$W/work/vercel-emu/vendor" ] || cp -al "$W/work/platform/vendor" "$W/work/vercel-emu/vendor"; }
say "rehearsal tree: vendor $( [ -f "$W/work/vercel-emu/vendor/autoload.php" ] && echo present || echo MISSING )"
mkdir -p "$W/chromium-kit" && cd "$W/chromium-kit" && { [ -f package.json ] || npm init -y > /dev/null; } && npm i --no-audit --no-fund @sparticuz/chromium > "$W/chromium.log" 2>&1; say "the browser's package: exit $?"
AWS_EXECUTION_ENV=AWS_Lambda_nodejs22.x AWS_LAMBDA_JS_RUNTIME=nodejs22.x node -e 'import("@sparticuz/chromium").then(async (m) => console.log("the browser is at", await m.default.executablePath()))' 2>&1 | tail -1
wait "$ROOT_NPM"
say "READY in $W"

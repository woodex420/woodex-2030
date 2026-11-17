#!/usr/bin/env bash
# Serves the demo exactly as the Vercel deployment does, on this machine, to look at it or to test against it:
# Vercel's own PHP runtime and launcher (npm: vercel-php), the "env" and "routes" of vercel.json.
#
#   VERCEL_PHP=<node_modules> WX_PW_OWNER=… WX_PW_MANAGER=… WX_PW_SALES=… WX_PW_ACCOUNTS=… \
#     bash tools/vercel/serve-demo.sh [port]
#
# Run it from platform/, in a tree with vendor/ and without .env (see tools/vercel/check-entry.sh for the parts
# and for a full rehearsal). The four demo passwords need 16 characters or more. The site answers on
# http://127.0.0.1:<port> (default 8191) until the script is stopped; PHP itself listens on port 3000, as the
# launcher always does. Everything is fictional and lives in /tmp.
#
# Two settings are handed on to the running site when they are set, for a rehearsal on this machine only (the
# Vercel project carries neither):
#   DOCUMENTS_OWNER_CONFIRMED=true   lets a quotation and an invoice be issued, so that the whole sale can be
#                                    pressed through (scripts/os-journeys.mjs). On any real site this switch
#                                    is the owner's word that terms and signers are right (list line T-084).
#   WOODEX_MODULES=lead_api,…        the optional modules that are switched on (config/modules.php).
set -u
PORT="${1:-8191}"
[ -f api/index.php ] && [ -f vercel.json ] && [ -f vendor/autoload.php ] || { echo "Run from platform/, with vendor/ installed."; exit 2; }
[ ! -e .env ] || { echo "platform/.env exists: Vercel has none. Run this in a tree without it."; exit 2; }
PACKAGE="${VERCEL_PHP:-}/vercel-php"
NATIVE="$(ls -d "${VERCEL_PHP:-}"/@libphp/*/native 2>/dev/null | head -1)"
[ -f "$PACKAGE/dist/launchers/builtin.js" ] && [ -x "$NATIVE/php/php" ] || { echo "VERCEL_PHP must name a node_modules folder with vercel-php (npm install --prefix <dir> vercel-php@0.9.0)."; exit 2; }
for name in WX_PW_OWNER WX_PW_MANAGER WX_PW_SALES WX_PW_ACCOUNTS; do value="${!name:-}"; [ "${#value}" -ge 16 ] || { echo "$name must be set (16 characters or more)."; exit 2; }; done
PHP_DIR="$NATIVE/php"; LIB_DIR="$NATIVE/lib"; HOME_DIR="${HOME:-/tmp}"
PASSWORDS=(WX_PW_OWNER="$WX_PW_OWNER" WX_PW_MANAGER="$WX_PW_MANAGER" WX_PW_SALES="$WX_PW_SALES" WX_PW_ACCOUNTS="$WX_PW_ACCOUNTS")
# PHP as Vercel's builder runs it:   builder NAME=value ... -- command
builder() {
  local settings=(); while [ "$1" != "--" ]; do settings+=("$1"); shift; done; shift
  env -i HOME="$HOME_DIR" "${settings[@]}" PATH="$PHP_DIR:$PATH" COMPOSER_HOME=/tmp PHP_INI_EXTENSION_DIR="$PHP_DIR/modules" PHP_INI_SCAN_DIR=":$PACKAGE/conf" LD_LIBRARY_PATH="$LIB_DIR:/usr/lib64:/lib64" "$@"
}

# A new instance: nothing of an earlier one in /tmp (see fresh_instance in check-entry.sh).
rm -rf /tmp/woodex-synthetic-vercel-demo.sqlite* /tmp/woodex-livewire /tmp/woodex-config.php /tmp/woodex-events.php /tmp/woodex-packages.php /tmp/woodex-routes.php /tmp/woodex-services.php
for cache in /tmp/????????????????????????????????*; do
  [ -d "$cache" ] && [[ "$(basename "$cache")" =~ ^[0-9a-f]{32}(phar:)?$ ]] || continue
  if [ -e "$cache$PWD" ] || [ -e "$cache/tmp" ] || [ -e "$cache/var/task" ]; then rm -rf "$cache"; fi
done

builder WOODEX_DEMO=1 "${PASSWORDS[@]}" -- php "$PHP_DIR/composer" run vercel > "${TMPDIR:-/tmp}/woodex-demo-build.log" 2>&1 || { echo "The demo could not be built:"; tail -15 "${TMPDIR:-/tmp}/woodex-demo-build.log"; exit 1; }
grep '\[demo\]' "${TMPDIR:-/tmp}/woodex-demo-build.log" | tail -1
mapfile -t BLOCK < <(builder -- php -r 'foreach (json_decode(file_get_contents("vercel.json"), true)["env"] as $k => $v) { echo $k."=".$v.PHP_EOL; }' | grep '=')
ROUTES="$(builder -- php -r 'echo json_encode(json_decode(file_get_contents("vercel.json"), true)["routes"]);' | tail -1)"
TASK="$(mktemp -d)"; ln -s "$PWD" "$TASK/user"; ln -s "$PHP_DIR" "$TASK/php"; ln -s "$LIB_DIR" "$TASK/lib"
trap 'rm -rf "$TASK"' EXIT
echo "The demo answers on http://127.0.0.1:$PORT/admin (stop with Ctrl+C)."
env -i PATH="$PATH" HOME="$HOME_DIR" "${BLOCK[@]}" WOODEX_DEMO=1 "${PASSWORDS[@]}" ${WOODEX_DEMO_SHOW_PASSWORD:+WOODEX_DEMO_SHOW_PASSWORD="$WOODEX_DEMO_SHOW_PASSWORD"} \
  ${DOCUMENTS_OWNER_CONFIRMED:+DOCUMENTS_OWNER_CONFIRMED="$DOCUMENTS_OWNER_CONFIRMED"} ${WOODEX_MODULES:+WOODEX_MODULES="$WOODEX_MODULES"} \
  LAMBDA_TASK_ROOT="$TASK" NOW_ENTRYPOINT=api/index.php NOW_PHP_DEV=0 VERCEL_PHP_PACKAGE="$PACKAGE" STAND_IN_PORT="$PORT" STAND_IN_ROUTES="$ROUTES" \
  LD_LIBRARY_PATH="$LIB_DIR" PHP_INI_EXTENSION_DIR="$PHP_DIR/modules" PHP_INI_SCAN_DIR=":$PACKAGE/conf" node tools/vercel/launcher-stand-in.cjs

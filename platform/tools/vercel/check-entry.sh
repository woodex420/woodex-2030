#!/usr/bin/env bash
# Checks what a visitor gets from the Vercel deployment of the admin, before anything is deployed.
#
#   VERCEL_PHP=<node_modules> bash tools/vercel/check-entry.sh [--build] [--read-only]
#
# Run it from platform/, in a tree without .env (Vercel has none).
#
# It uses Vercel's own parts, not look-alikes:
#   - the PHP that the `vercel-php` builder ships (npm: vercel-php and @libphp/*), with its php.ini;
#   - its launcher (dist/launchers/builtin.js), which starts PHP's built-in server on port 3000 with
#     api/index.php as router;
#   - the "env" and "routes" of vercel.json.
# Only Vercel's network is replaced, by tools/vercel/launcher-stand-in.cjs. Get the parts with:
#       npm install --prefix <dir> vercel-php@0.9.0        then VERCEL_PHP=<dir>/node_modules
#
# --build      do what Vercel's builder does first (vercel-php, src/utils.ts): `composer install --no-dev
#              --no-scripts --ignore-platform-reqs`, then `composer run vercel`. It removes the development
#              packages from vendor/: use it in a throwaway tree or in CI. Without it, vendor/ must exist.
# --read-only  make the tree unwritable while the demo is served, as Vercel's file system is.
#
# The passwords used here are generated for this run and thrown away with it.
set -u
BUILD=0; READONLY=0
for argument in "$@"; do
  case "$argument" in
    --build) BUILD=1 ;;
    --read-only) READONLY=1 ;;
    *) echo "Unknown option: $argument"; exit 2 ;;
  esac
done

PORT="${CHECK_PORT:-8191}"
BASE="http://127.0.0.1:$PORT"
OUT="$(mktemp -d)"
FAILS=0
SERVER=""
HOME_DIR="${HOME:-/tmp}"
DEMO_DB=database/woodex-synthetic-vercel-demo.sqlite

ok()    { printf '  ok    %s\n' "$1"; }
bad()   { printf '  FAIL  %s\n' "$1"; FAILS=$((FAILS + 1)); }
check() { if [ "$2" = "$3" ]; then ok "$1"; else bad "$1 (expected '$3', got '$2')"; fi; }
has()   { if grep -q -- "$2" "$3"; then ok "$1"; else bad "$1 (not found: $2)"; fi; }
hasnt() { if grep -q -- "$2" "$3"; then bad "$1 (found: $2)"; else ok "$1"; fi; }

[ -f api/index.php ] && [ -f composer.json ] && [ -f vercel.json ] || { echo "Run from platform/."; exit 2; }
[ ! -e .env ] || { echo "platform/.env exists: Vercel has none. Run this in a tree without it."; exit 2; }

# --- Vercel's PHP ------------------------------------------------------------------------------------------------
PACKAGE="${VERCEL_PHP:-}/vercel-php"
NATIVE="$(ls -d "${VERCEL_PHP:-}"/@libphp/*/native 2>/dev/null | head -1)"
if [ -z "${VERCEL_PHP:-}" ] || [ ! -f "$PACKAGE/dist/launchers/builtin.js" ] || [ ! -x "$NATIVE/php/php" ]; then
  echo "VERCEL_PHP must name a node_modules folder with Vercel's PHP runtime:"
  echo "    npm install --prefix /some/folder vercel-php@0.9.0   then   VERCEL_PHP=/some/folder/node_modules"
  exit 2
fi
PHP_DIR="$NATIVE/php"; LIB_DIR="$NATIVE/lib"
# PHP as the builder runs it during a build (vercel-php, src/utils.ts, runPhp()), with nothing else in the
# environment than what is given: the project's settings must be the only settings.
#   run_php NAME=value ... -- arguments
run_php() {
  local settings=(); while [ "$1" != "--" ]; do settings+=("$1"); shift; done; shift
  env -i HOME="$HOME_DIR" ${COMPOSER_AUTH:+COMPOSER_AUTH="$COMPOSER_AUTH"} "${settings[@]}" PATH="$PHP_DIR:$PATH" COMPOSER_HOME="${COMPOSER_HOME:-/tmp}" \
    PHP_INI_EXTENSION_DIR="$PHP_DIR/modules" PHP_INI_SCAN_DIR=":$PACKAGE/conf" LD_LIBRARY_PATH="$LIB_DIR:/usr/lib64:/lib64" php "$@"
}
build_step() { run_php "$@" -- "$PHP_DIR/composer" run vercel; }   # build_step NAME=value ...
echo "PHP: $(run_php -- -r 'echo PHP_VERSION;') from $(basename "$(dirname "$NATIVE")"), vercel-php $(run_php -- -r 'echo json_decode(file_get_contents($argv[1]))->version;' "$PACKAGE/package.json")"

# What one instance of the function keeps in /tmp (api/index.php and the *_CACHE settings of vercel.json).
# A new instance starts without them; a leftover from another build would name packages that are not installed.
fresh_instance() {
  rm -rf /tmp/woodex-synthetic-vercel-demo.sqlite* /tmp/woodex-livewire /tmp/woodex-config.php /tmp/woodex-events.php /tmp/woodex-packages.php /tmp/woodex-routes.php /tmp/woodex-services.php
  # The runtime's php.ini keeps compiled scripts in /tmp/<32 hex digits> and never reads a changed file again
  # (opcache.file_cache=/tmp, validate_timestamps=0). Left in place, a later run would get the earlier run's code.
  local cache
  for cache in /tmp/????????????????????????????????*; do
    [ -d "$cache" ] && [[ "$(basename "$cache")" =~ ^[0-9a-f]{32}(phar:)?$ ]] || continue
    if [ -e "$cache$PWD" ] || [ -e "$cache/tmp" ] || [ -e "$cache/var/task" ]; then rm -rf "$cache"; fi
  done
}
port_busy() { (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null; }
stop() {
  [ -n "$SERVER" ] || return 0
  kill "$SERVER" 2>/dev/null; wait "$SERVER" 2>/dev/null; SERVER=""
  for _ in $(seq 1 50); do port_busy "$PORT" || port_busy 3000 || return 0; sleep 0.1; done
  echo "A server of this check is still running (ports $PORT, 3000)."; exit 1
}
cleanup() {
  stop
  [ "$READONLY" = 1 ] && chmod -R u+w . 2>/dev/null
  [ "${TASK:-}" = /var/task ] && rm -f /var/task/user /var/task/php /var/task/lib
  rm -rf "$OUT"; fresh_instance
}
trap cleanup EXIT
fresh_instance
port_busy "$PORT" && { echo "Port $PORT is in use."; exit 2; }
port_busy 3000 && { echo "Port 3000 is in use: Vercel's launcher always starts PHP there."; exit 2; }

# The project's own APP_KEY, as a real deployment has it. A demo must never encrypt with it.
KEY="base64:$(head -c 32 /dev/urandom | base64)"
PW_OWNER="check-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
PW_OTHER="check-$(head -c 12 /dev/urandom | od -An -tx1 | tr -d ' \n')"
PASSWORDS=(WX_PW_OWNER="$PW_OWNER" WX_PW_MANAGER="$PW_OTHER" WX_PW_SALES="$PW_OTHER" WX_PW_ACCOUNTS="$PW_OTHER")
# Settings of real services. A demo must leave every one of them unused.
REAL=(APP_KEY="$KEY" CHECK_PROJECT="$KEY" DB_CONNECTION=mysql DB_HOST=db.real.example DB_DATABASE=must_not_be_used DB_USERNAME=nobody DB_PASSWORD=real-db-password
  LEGACY_DB_HOST=legacy.real.example LEGACY_DB_USERNAME=legacy LEGACY_DB_PASSWORD=real-legacy-password
  MAIL_MAILER=smtp MAIL_HOST=smtp.real.example MAIL_USERNAME=mailbox MAIL_PASSWORD=real-mail-password
  WHATSAPP_TOKEN=real-whatsapp-token WHATSAPP_PHONE_NUMBER_ID=123 TELEGRAM_BOT_TOKEN=real-telegram-token
  GOOGLE_CLIENT_ID=real-google-id GOOGLE_CLIENT_SECRET=real-google-secret TURNSTILE_SECRET_KEY=real-turnstile-key
  PUBLIC_LEADS_BRIDGE_KEY=real-bridge-key)

# --- 0. the packages, as Vercel's builder installs them ----------------------------------------------------------
rm -f "$DEMO_DB" "$DEMO_DB.key"
if [ "$BUILD" = 1 ]; then
  echo "0. Install the packages as Vercel's builder does"
  run_php -- "$PHP_DIR/composer" install --profile --no-dev --no-interaction --no-scripts --ignore-platform-reqs --no-progress > "$OUT/install.log" 2>&1
  CODE=$?
  check "composer install --no-dev --no-scripts --ignore-platform-reqs" "$CODE" 0
  [ "$CODE" = 0 ] || tail -15 "$OUT/install.log"
fi
[ -f vendor/autoload.php ] || { echo "vendor/ is missing: run with --build, or install the packages first."; exit 2; }
if [ -d vendor/pestphp ] || [ -d vendor/fakerphp ]; then
  echo "  note  development packages are installed here; Vercel has none (run with --build in a throwaway tree to check without them)"
else
  ok "no development package is installed (as on Vercel)"
fi

# A run that opened the installer (a broken entry does) leaves a setup code behind, and a waiting setup code sends
# every later request to the installer. Say so instead of failing in a confusing way.
WAITING="$(run_php -- -r 'require "vendor/autoload.php"; echo (new App\Support\Install\Installer(App\Support\Install\SiteState::detect(getcwd())))->codePath();' 2>/dev/null | tail -1)"
[ -z "$WAITING" ] || [ ! -e "$WAITING" ] || { echo "A setup code from an earlier run is waiting: delete $WAITING and run again."; exit 2; }

# The "env" block and the "routes" of vercel.json, exactly as Vercel uses them.
mapfile -t BLOCK < <(run_php -- -r 'foreach (json_decode(file_get_contents("vercel.json"), true)["env"] as $k => $v) { echo $k."=".$v.PHP_EOL; }' 2>/dev/null | grep '=')
ROUTES="$(run_php -- -r 'echo json_encode(json_decode(file_get_contents("vercel.json"), true)["routes"]);' 2>/dev/null | tail -1)"
[ "${#BLOCK[@]}" -gt 5 ] && [ -n "$ROUTES" ] || { echo "Cannot read vercel.json."; exit 2; }
cp tools/vercel/launcher-stand-in.cjs "$OUT/stand-in.cjs"

# On Vercel the function lives in /var/task. Use that folder when it can be written (CI); otherwise a private one,
# and only then PHP must be told where its extensions are (the runtime's php.ini names /var/task/php/modules).
if [ -d /var/task ] && [ -w /var/task ]; then TASK=/var/task; rm -f /var/task/user /var/task/php /var/task/lib; else TASK="$OUT/task"; mkdir -p "$TASK"; fi
ln -s "$PWD" "$TASK/user"; ln -s "$PHP_DIR" "$TASK/php"; ln -s "$LIB_DIR" "$TASK/lib"
if [ "$TASK" = /var/task ]; then EXTENSIONS=(); echo "Function folder: /var/task, as on Vercel"; else EXTENSIONS=(PHP_INI_EXTENSION_DIR="$PHP_DIR/modules" PHP_INI_SCAN_DIR=":$PACKAGE/conf"); echo "Function folder: a private one (/var/task cannot be written here)"; fi

serve() { # serve NAME=value ...   (starts the function with vercel.json's settings plus the ones given)
  stop
  env -i PATH="$PATH" HOME="$HOME_DIR" "${BLOCK[@]}" "$@" LAMBDA_TASK_ROOT="$TASK" NOW_ENTRYPOINT=api/index.php NOW_PHP_DEV=0 VERCEL_PHP_PACKAGE="$PACKAGE" \
    STAND_IN_PORT="$PORT" STAND_IN_ROUTES="$ROUTES" LD_LIBRARY_PATH="$LIB_DIR" "${EXTENSIONS[@]}" node "$OUT/stand-in.cjs" > "$OUT/server.log" 2>&1 &
  SERVER=$!
  for _ in $(seq 1 100); do curl -s -o /dev/null "$BASE/up" && return 0; sleep 0.1; done
  echo "The function did not start:"; tail -8 "$OUT/server.log"; exit 1
}
# Every request carries what Vercel's network adds: the scheme and the host the visitor used.
# get PATH [curl options]    one request without cookies; the body goes to $OUT/body, the headers to $OUT/head.
get() { curl -s -o "$OUT/body" -D "$OUT/head" -w '%{http_code}' -H 'X-Forwarded-Proto: https' -H 'X-Forwarded-Host: demo.woodex.test' "${@:2}" "$BASE$1"; }
# visit PATH [curl options]  the same as a browser would: sends the cookies it holds and keeps the ones it is given.
# (curl's own cookie handling is not used: it does not send "Secure" cookies to http://127.0.0.1.)
: > "$OUT/cookies"
cookies() { paste -sd';' "$OUT/cookies" | sed 's/;/; /g'; }
visit() {
  local code; code="$(get "$1" -H "Cookie: $(cookies)" "${@:2}")"
  tr -d '\r' < "$OUT/head" | awk -v store="$OUT/cookies" '
    BEGIN { while ((getline line < store) > 0) { i = index(line, "="); held[substr(line, 1, i - 1)] = substr(line, i + 1) } }
    tolower($0) ~ /^set-cookie:/ {
      attributes = tolower($0); sub(/^[^:]*:[ ]*/, ""); pair = $0; sub(/;.*/, "", pair)
      i = index(pair, "="); name = substr(pair, 1, i - 1); value = substr(pair, i + 1)
      if (attributes ~ /max-age=(0|-)/ || value == "deleted" || value == "") delete held[name]; else held[name] = value
    }
    END { for (name in held) print name "=" held[name] }' > "$OUT/cookies.new"
  mv -f "$OUT/cookies.new" "$OUT/cookies"
  printf '%s' "$code"
}
token() { grep -o 'name="_token" value="[^"]*"' "$1" | head -1 | sed 's/.*value="//;s/"$//'; }
sign_in() { visit /admin/login -X POST --data-urlencode "_token=$(token "$OUT/body")" --data-urlencode "email=$1" --data-urlencode "password=$2"; }
# The files a page loads (stylesheets, scripts, icons, images), as paths on this site.
loaded_by() { grep -oE '(href|src)="[^"]+\.(css|js|mjs|svg|png|jpe?g|webp|gif|ico|woff2?|mp3)(\?[^"]*)?"' "$1" | sed -E 's/^(href|src)="//;s/"$//;s#^https?://demo\.woodex\.test##' | grep '^/' | sed 's/&amp;/\&/g' | sort -u; }

echo "1. Without settings the function refuses to start"
serve
check "no settings: 503" "$(get /admin/login)" 503
check "no settings: the plain message" "$(cat "$OUT/body")" "Admin service is not configured."
serve APP_KEY="$KEY" DB_CONNECTION=sqlite DB_HOST=x DB_DATABASE=x DB_USERNAME=x DB_PASSWORD=x
check "a database that is not MySQL: 503" "$(get /admin/login)" 503
serve DB_CONNECTION=mysql DB_HOST=x DB_DATABASE=x DB_USERNAME=x DB_PASSWORD=x
check "MySQL settings without APP_KEY: 503" "$(get /admin/login)" 503
serve WOODEX_DEMO=true APP_KEY="$KEY" "${PASSWORDS[@]}"
check "WOODEX_DEMO must be exactly 1 (\"true\" is not a demo): 503" "$(get /admin/login)" 503

echo "2. A demo whose workspace was not built is not served"
serve WOODEX_DEMO=1 APP_KEY="$KEY" "${PASSWORDS[@]}"
check "demo without its workspace: 503" "$(get /admin/login)" 503
check "demo without its workspace: says why" "$(cat "$OUT/body")" "The demo workspace was not built with this deployment."
stop

echo "3. The build step (composer run vercel)"
build_step > "$OUT/build.log" 2>&1
check "not a demo: the build step does nothing and succeeds" "$?" 0
[ ! -e "$DEMO_DB" ] && ok "not a demo: no workspace is created" || bad "not a demo: a workspace was created"
build_step "${REAL[@]}" > "$OUT/build.log" 2>&1
check "a real deployment (MySQL settings, no WOODEX_DEMO): the build step touches nothing" "$? $([ -e "$DEMO_DB" ] && echo created || echo none)" "0 none"
build_step WOODEX_DEMO=1 > "$OUT/build.log" 2>&1
[ "$?" != 0 ] && ok "a demo without passwords fails the build" || bad "a demo without passwords did not fail the build"
build_step WOODEX_DEMO=1 "${REAL[@]}" "${PASSWORDS[@]}" > "$OUT/build.log" 2>&1
CODE=$?
check "a demo builds its workspace, ignoring every real setting" "$CODE" 0
[ "$CODE" = 0 ] || tail -25 "$OUT/build.log"
grep '\[demo\]' "$OUT/build.log" | tail -1 | sed 's/^/        /'
DEMO_KEY="$(cat "$DEMO_DB.key" 2>/dev/null)"
[ "${#DEMO_KEY}" -ge 50 ] && [ "$DEMO_KEY" != "$KEY" ] && ok "the build made a key for this demo alone" || bad "the demo has no key of its own"
hasnt "the build log shows no password and no key" "$PW_OWNER\|$PW_OTHER\|real-db-password\|${DEMO_KEY:-no-key-was-made}\|$KEY" "$OUT/build.log"

echo "4. The demo, served as on Vercel$([ "$READONLY" = 1 ] && echo ' (read-only file system)')"
fresh_instance
[ "$READONLY" = 1 ] && chmod -R a-w .
serve WOODEX_DEMO=1 "${PASSWORDS[@]}" "${REAL[@]}"
check "the address opens the admin: 302" "$(get /)" 302
has "…to /admin" '^[Ll]ocation: /admin' "$OUT/head"
check "/admin sends a visitor to sign in: 302" "$(get /admin)" 302
has "…on https, on the host the visitor used" '^[Ll]ocation: https://demo.woodex.test/admin/login' "$OUT/head"
check "the installer is closed: 404" "$(get /install)" 404
check "health address: 200" "$(get /up)" 200
for file in /composer.json /vendor/autoload.php "/$DEMO_DB" "/$DEMO_DB.key" /artisan /api/index.php /config/app.php /storage/logs/laravel.log /.env /tools/vercel/build-demo.php \
  /public/index.php /index.php /build/../index.php /assets/uploads/.htaccess /.htaccess /public/.htaccess; do
  CODE="$(get "$file" --path-as-is)"
  if { [ "$CODE" = 404 ] || [ "$CODE" = 403 ]; } && ! grep -q 'SQLite format\|<?php\|"require"\|APP_KEY\|base64:\|RewriteRule\|FilesMatch' "$OUT/body"; then ok "a file of the application is not handed out: $file ($CODE)"; else bad "$file answered $CODE"; fi
done
check "sign-in page: 200" "$(visit /admin/login)" 200
cp "$OUT/body" "$OUT/login.html"
check "the page starts as a page (no PHP notice printed before it)" "$(head -c 15 "$OUT/login.html")" "<!DOCTYPE html>"
has "the sign-in page sets the session cookie, marked Secure" '^[Ss]et-[Cc]ookie: woodex_livewire_session=.*; [Ss]ecure' "$OUT/head"
has "…and the form-token cookie" '^[Ss]et-[Cc]ookie: XSRF-TOKEN=' "$OUT/head"
has "sign-in page says it is not production" 'NOT PRODUCTION' "$OUT/login.html"
has "sign-in page names the demo account" 'owner@woodex.test' "$OUT/login.html"
hasnt "sign-in page does not show the password" "$PW_OWNER" "$OUT/login.html"
has "the stylesheet is linked on https" 'href="https://demo.woodex.test/build/assets/admin-[A-Za-z0-9_-]*\.css"' "$OUT/login.html"

CODE="$(sign_in owner@woodex.test "wrong-$PW_OWNER")"
check "a wrong password is refused: back to the form" "$CODE $(grep -ci '^location: https://demo.woodex.test/admin/login' "$OUT/head")" "302 1"
check "…and the form says so" "$(visit /admin/login)" 200
has "…in words" 'do not match\|These credentials' "$OUT/body"
check "the demo account signs in: 302" "$(sign_in owner@woodex.test "$PW_OWNER")" 302
has "…to the admin" '^[Ll]ocation: https://demo.woodex.test/admin\s*$' "$OUT/head"
check "signed in, the dashboard: 200" "$(visit /admin)" 200
cp "$OUT/body" "$OUT/dashboard.html"
check "the dashboard starts as a page" "$(head -c 15 "$OUT/dashboard.html")" "<!DOCTYPE html>"
has "the admin says it is a demo with sample data" 'data-demo-notice' "$OUT/dashboard.html"
has "the signed-in person is the demo owner" 'SYNTHETIC Owner' "$OUT/dashboard.html"

# Every screen the dashboard links to must open: the sidebar, the command palette and the dashboard's own links.
mapfile -t SCREENS < <(grep -o 'href="https://demo.woodex.test/admin[^"#?]*"' "$OUT/dashboard.html" | sed 's/^href="https:\/\/demo.woodex.test//;s/"$//' | grep -v '/logout$' | sort -u)
OPENED=0; REFUSED=""; : > "$OUT/pages.html"
for screen in "${SCREENS[@]}"; do
  CODE="$(visit "$screen")"
  if [ "$CODE" = 200 ] && [ "$(head -c 15 "$OUT/body")" = "<!DOCTYPE html>" ]; then OPENED=$((OPENED + 1)); cat "$OUT/body" >> "$OUT/pages.html"; else REFUSED="$REFUSED $screen($CODE)"; fi
done
[ "${#SCREENS[@]}" -ge 25 ] && ok "the dashboard links to ${#SCREENS[@]} screens" || bad "only ${#SCREENS[@]} screens were found on the dashboard"
[ -z "$REFUSED" ] && ok "all $OPENED of them open (200)" || bad "screens that did not open:$REFUSED"
check "the sample records are there (leads)" "$(visit /admin/leads)" 200
has "…named SYNTHETIC" 'SYNTHETIC Contact' "$OUT/body"
check "the Health page opens: 200" "$(visit /admin/system/health)" 200
has "…and says that this host keeps no files" 'this host keeps no files' "$OUT/body"
hasnt "…without advice meant for another host" 'Hostinger Git deploys' "$OUT/body"

# Every stylesheet, script, icon and image those pages load must be there. On Vercel a file of public/ is served
# only when a route of vercel.json names it; everything else goes to Laravel, which has no such page.
cat "$OUT/login.html" "$OUT/dashboard.html" >> "$OUT/pages.html"
mapfile -t FILES < <(loaded_by "$OUT/pages.html")
MISSING=""; STATIC=0
for file in "${FILES[@]}"; do
  CODE="$(get "$file")"
  [ "$CODE" = 200 ] && [ -s "$OUT/body" ] || MISSING="$MISSING $file($CODE)"
  grep -qi '^x-stand-in: static' "$OUT/head" && STATIC=$((STATIC + 1))
done
[ "${#FILES[@]}" -ge 4 ] && [ -z "$MISSING" ] && ok "all ${#FILES[@]} files those pages load are served ($STATIC as static files, $(( ${#FILES[@]} - STATIC )) by Laravel)" || bad "files the pages load but the site does not serve:$MISSING"
STYLE="$(grep -o '/build/assets/admin-[A-Za-z0-9_-]*\.css' "$OUT/login.html" | head -1)"
get "$STYLE" > /dev/null; cp "$OUT/body" "$OUT/admin.css"
MISSING=""
for file in $(grep -oE 'url\([^)]+\)' "$OUT/admin.css" | sed -E 's/^url\(//;s/\)$//;s/["'"'"']//g' | grep -v '^data:' | sort -u); do
  case "$file" in /*) target="$file" ;; *) target="/build/assets/$file" ;; esac
  [ "$(get "$target")" = 200 ] || MISSING="$MISSING $target"
done
[ -z "$MISSING" ] && ok "the fonts and images the stylesheet names are served" || bad "named by the stylesheet but not served:$MISSING"

# A browser drops a cookie longer than 4096 bytes without a word, and the visitor is signed out.
LONGEST="$(awk '{ if (length($0) > max) max = length($0) } END { print max + 0 }' "$OUT/cookies")"
[ "$LONGEST" -gt 100 ] && [ "$LONGEST" -lt 3800 ] && ok "the longest cookie fits in a browser ($LONGEST of 4096 bytes; $(wc -l < "$OUT/cookies") cookies, $(cookies | wc -c) bytes together)" || bad "the longest cookie is $LONGEST bytes (a browser keeps at most 4096)"
hasnt "no error was written to the function's log" 'PHP Fatal\|PHP Warning\|PHP Deprecated\|\.ERROR:\|\.CRITICAL:' "$OUT/server.log"

# What the application itself works with, read after the entry has handled a request.
SETTINGS="$(run_php "${BLOCK[@]}" WOODEX_DEMO=1 "${PASSWORDS[@]}" "${REAL[@]}" -- -r '
    $_SERVER["REQUEST_URI"] = "/up"; $_SERVER["REQUEST_METHOD"] = "GET"; $_SERVER["HTTP_HOST"] = "demo.woodex.test";
    ob_start(); require "api/index.php"; ob_end_clean();
    $empty = fn ($value) => blank($value) ? "-" : "SET";
    echo implode("|", [app()->storagePath(), config("view.compiled"), config("database.default"),
        config("database.connections.sqlite.database"), config("session.driver"), app()->environment(),
        config("app.debug") ? "debug" : "no-debug", config("mail.default"),
        app(App\Support\Install\SiteState::class)->isConfigured() ? "configured" : "unconfigured",
        config("app.key") === trim(file_get_contents("database/woodex-synthetic-vercel-demo.sqlite.key")) && config("app.key") !== getenv("CHECK_PROJECT") ? "own-key" : "THE-PROJECT-KEY",
        "real:".implode("", array_map($empty, [
            config("database.connections.mysql.host"), config("database.connections.mysql.username"), config("database.connections.mysql.password"),
            config("database.connections.legacy.host"), config("database.connections.legacy.password"),
            config("mail.mailers.smtp.host"), config("mail.mailers.smtp.password"),
            config("services.whatsapp.token"), config("services.telegram.bot_token"), config("services.google.client_secret"),
            config("services.turnstile.secret_key"), config("woodex.public_leads.bridge_key"),
            getenv("WX_PW_OWNER"), getenv("DB_PASSWORD"), $_ENV["MAIL_PASSWORD"] ?? "", $_SERVER["WHATSAPP_TOKEN"] ?? "",
        ]))]);' 2>"$OUT/settings.err" | tail -1)"
check "storage, views, database, session, mail and key are the demo's own; every real setting is empty" "$SETTINGS" \
  "/tmp/woodex-livewire/storage|/tmp/woodex-livewire/storage/framework/views|sqlite|/tmp/woodex-synthetic-vercel-demo.sqlite|cookie|local|no-debug|log|configured|own-key|real:----------------"

# A second instance has its own copy of the database: the same cookies must still be signed in.
stop; fresh_instance
serve WOODEX_DEMO=1 "${PASSWORDS[@]}"
check "a new instance (fresh copy of the workspace) keeps the visitor signed in: 200" "$(visit /admin)" 200
check "signing out works: 302" "$(visit /admin/logout -X POST --data-urlencode "_token=$(token "$OUT/body")")" 302
check "…and the admin asks for a sign-in again: 302" "$(visit /admin)" 302

echo "5. The open-door option"
serve WOODEX_DEMO=1 WOODEX_DEMO_SHOW_PASSWORD=1 "${PASSWORDS[@]}"
check "sign-in page: 200" "$(get /admin/login)" 200
has "WOODEX_DEMO_SHOW_PASSWORD=1 prints the demo password on the sign-in page" "$PW_OWNER" "$OUT/body"
hasnt "…and only the owner's" "$PW_OTHER" "$OUT/body"
stop
[ "$READONLY" = 1 ] && chmod -R u+w .

echo
if [ "$FAILS" = 0 ]; then echo "Vercel deployment check: every check passed."; else echo "Vercel deployment check: $FAILS check(s) FAILED."; grep -v 'Accepted$\|Closing$\|Accessing\|Querying\|^$' "$OUT/server.log" | tail -25; exit 1; fi

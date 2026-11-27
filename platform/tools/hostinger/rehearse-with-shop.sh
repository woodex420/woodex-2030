#!/usr/bin/env bash
# WOODEX OS: the admin installed as a host runs it, with the shop reading its products; then the owner's
# acceptance of the product link, pressed in a browser (scripts/os-product-to-shop.mjs).
#
# For a machine that has PHP, Node and nothing else (the sandbox). It is rehearse.sh with three things added:
#
#   an installation     the release is installed by the installer, in a browser, as the owner will install it
#                       (scripts/os-install.mjs), into an empty database. No sample record is loaded: the only
#                       products are the ones the acceptance types in.
#   the shop            the built shop (dist/, from `npm run vercel:build`) served by its own Node server, which is
#                       told where the admin is and that it reads its products there (NATIVE_PRODUCT_SYNC=1), as it
#                       reads the categories and the replaced photographs (NATIVE_MEDIA_SYNC=1).
#   the acceptance      run twice: a product with a price, with the admin stopped and started again after the
#                       product is saved; and a product priced on request.
#
# The database is a file (SQLite), which the installer offers beside MySQL. The sandbox's own database server
# (tools/preview/sandbox/database.sh, MariaDB 10.2 of 2017) is no use here: the installer refuses a server older
# than MySQL 8.0 or MariaDB 10.6, and it is right to. The same acceptance on MariaDB 10.11, under Apache, is the
# job "hostinger" of the workflow os-ci.yml; what a product is on MySQL 8.4 and MariaDB 10.11 and 11.4 is held by
# the test suite there. What this shows is the whole way, from a form to a visitor's screen, with the admin
# stopped in between: what is read afterwards was kept by the database and by no process.
#
#   bash platform/tools/hostinger/rehearse-with-shop.sh [folder, default /tmp/woodex-product-to-shop]
#
#   ADMIN_PORT (8195), SHOP_PORT (4185)   where the two sites listen, on 127.0.0.1
#   KEEP=1                                leave both sites running when it is done
#   WOODEX_SANDBOX                        where setup.sh built PHP (~/.cache/woodex-os)
#
# What it leaves in the folder: the release, the installed site with its database, the logs of both servers, and
# acceptance-priced/ and acceptance-on-request/ with journeys.json and pictures of the key screens.
set -uo pipefail

here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
root="$(cd "$here/../../.." && pwd)"
W="${WOODEX_SANDBOX:-$HOME/.cache/woodex-os}"
work="${1:-/tmp/woodex-product-to-shop}"
admin_port="${ADMIN_PORT:-8195}"
shop_port="${SHOP_PORT:-4185}"
php="${PHP_BIN:-$W/php85}"
home="$work/home/u123456789"
site="$home/domains/staging.woodex.test/public_html"
database="$home/woodex-os/staging.woodex.test/database.sqlite"
say() { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }
listening() { (exec 3<>"/dev/tcp/127.0.0.1/$1") 2>/dev/null; }
until_listening() { for _ in $(seq 1 120); do listening "$1" && return 0; sleep 0.5; done; return 1; }

[ -x "$php" ] || { echo "run platform/tools/preview/sandbox/setup.sh first, or name a PHP in PHP_BIN" >&2; exit 2; }
[ -s "$root/dist/index.html" ] || { echo "the built shop is not there: run \`npm run vercel:build\` in the repository first" >&2; exit 2; }
for port in "$admin_port" "$shop_port"; do
  listening "$port" && { echo "port $port is in use" >&2; exit 2; }
done

rm -rf "$work" && mkdir -p "$work" "$(dirname "$site")"
stop_all() {
  [ "${KEEP:-}" = "1" ] && return 0
  for file in "$work"/*.pid; do [ -f "$file" ] && kill "$(cat "$file")" 2>/dev/null; done
  return 0
}
trap stop_all EXIT

# ── The admin's server, started by a few lines that the restart below runs again ─────────────────────────────
cat > "$work/start-admin.sh" <<SH
#!/usr/bin/env bash
cd "$site" || exit 2
WOODEX_HOME="$home" HOME="$home" PHP_CLI_SERVER_WORKERS=4 setsid nohup "$php" -d display_errors=0 -d log_errors=1 \\
  -S "127.0.0.1:$admin_port" -t "$site" "$here/router.php" >> "$work/admin.log" 2>&1 &
echo \$! > "$work/admin.pid"
SH
# What the acceptance runs after the product is saved: the admin is stopped, and started. No process lives across
# it; what is read afterwards was kept by the database.
cat > "$work/restart.sh" <<SH
#!/usr/bin/env bash
listening() { (exec 3<>"/dev/tcp/127.0.0.1/\$1") 2>/dev/null; }
kill "\$(cat "$work/admin.pid")" 2>/dev/null
for _ in \$(seq 1 120); do listening $admin_port || break; sleep 0.5; done
listening $admin_port && { echo "the admin did not stop" >&2; exit 1; }
echo "\$(date -u +%H:%M:%S) the admin is stopped; nothing answers on $admin_port" >> "$work/restarts.log"
bash "$work/start-admin.sh"
for _ in \$(seq 1 120); do listening $admin_port && break; sleep 0.5; done
listening $admin_port || { echo "the admin did not start again" >&2; exit 1; }
echo "\$(date -u +%H:%M:%S) the admin is started again" >> "$work/restarts.log"
SH
# One statement, asked of the admin's database by a process of its own; the answer in rows, the columns apart by
# a tab. It opens the file for reading only.
cat > "$work/query.sh" <<SH
#!/usr/bin/env bash
"$php" -r '
  \$p = new PDO("sqlite:file:$database?mode=ro", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
  foreach (\$p->query(\$argv[1], PDO::FETCH_NUM) as \$row) { echo implode("\t", array_map("strval", \$row)), PHP_EOL; }
' "\$1"
SH
chmod +x "$work"/*.sh

# ── The release, as Git delivers it to a host, and the admin served from it ──────────────────────────────────
say "putting the release together"
export PHP_BIN="$php"
export COMPOSER_HOME="$W/composer-home" COMPOSER_ALLOW_SUPERUSER=1
export COMPOSER_BIN="${COMPOSER_BIN:-$php $W/composer/composer-src/bin/composer}"
bash "$here/build-release.sh" "$work/built" > "$work/build.log" 2>&1 || { tail -20 "$work/build.log" >&2; echo "the release could not be put together" >&2; exit 1; }
bash "$here/carry-through-git.sh" "$work/built" "$site" >> "$work/build.log" 2>&1 || { tail -20 "$work/build.log" >&2; exit 1; }
bash "$work/start-admin.sh"
until_listening "$admin_port" || { echo "the admin did not start (see $work/admin.log)" >&2; exit 1; }
say "the admin: http://127.0.0.1:$admin_port (not installed yet)"

# ── Installed in a browser, as the owner installs it ─────────────────────────────────────────────────────────
# A password for this rehearsal only, kept in the folder and printed nowhere.
password="Wx$(head -c 9 /dev/urandom | od -An -tx1 | tr -d ' \n')7k"
( umask 077; printf '%s\n' "$password" > "$work/owner-password" )
# Where the sandbox keeps its browser (setup.sh); elsewhere the scripts find their own.
if [ -f "$W/bin/wxenv" ]; then set +u; . "$W/bin/wxenv"; set -u; fi
export WX_PW_OWNER="$password" OS_BASE="http://127.0.0.1:$admin_port" SHOP_BASE="http://127.0.0.1:$shop_port"
say "installing it in a browser"
( cd "$root" && OS_SETUP_CODE_FILE="$site/WOODEX-SETUP-CODE.txt" OS_DB=sqlite node scripts/os-install.mjs --out "$work/install" ) > "$work/install.log" 2>&1
installed=$?
tail -3 "$work/install.log"
[ "$installed" = 0 ] || { echo "the installation did not hold (see $work/install.log)" >&2; exit 1; }
[ -s "$database" ] || { echo "the installer left no database at $database" >&2; exit 1; }
say "installed; its database: $database ($(bash "$work/query.sh" "SELECT COUNT(*) FROM migrations") changes applied, $(bash "$work/query.sh" "SELECT COUNT(*) FROM commerce_products") products)"

# ── The shop, told where the admin is and that it reads its products there ───────────────────────────────────
cd "$root" || exit 2
PORT="$shop_port" WOODEX_SERVE_STATIC=dist WOODEX_DATA_DIR="$work/shop-data" NODE_ENV=test \
  NATIVE_CATALOGUE_ORIGIN="http://127.0.0.1:$admin_port" NATIVE_PRODUCT_SYNC=1 NATIVE_MEDIA_SYNC=1 \
  setsid nohup node --experimental-sqlite apps/api/server.mjs >> "$work/shop.log" 2>&1 &
echo $! > "$work/shop.pid"
until_listening "$shop_port" || { echo "the shop did not start (see $work/shop.log)" >&2; exit 1; }
say "the shop: http://127.0.0.1:$shop_port, reading its products from the admin"

# ── The acceptance, twice ────────────────────────────────────────────────────────────────────────────────────
failed=0
say "the acceptance: a product with a price, the admin stopped and started in between"
( cd "$root" && OS_DB_QUERY_CMD="bash $work/query.sh" OS_RESTART_CMD="bash $work/restart.sh" node scripts/os-product-to-shop.mjs --out "$work/acceptance-priced" ) 2>&1 | tee "$work/acceptance-priced.log"
[ "${PIPESTATUS[0]}" = 0 ] || failed=1
say "the acceptance: a product priced on request"
( cd "$root" && OS_DB_QUERY_CMD="bash $work/query.sh" OS_PRICE_ON_REQUEST=1 node scripts/os-product-to-shop.mjs --out "$work/acceptance-on-request" ) 2>&1 | tee "$work/acceptance-on-request.log"
[ "${PIPESTATUS[0]}" = 0 ] || failed=1

say "what the database holds at the end (address, version, published copy, saved copies):"
bash "$work/query.sh" "SELECT slug, version, COALESCE(published_revision_id, 'not published'), (SELECT COUNT(*) FROM commerce_product_revisions r WHERE r.product_id = p.id) FROM commerce_products p ORDER BY id"
if [ "${KEEP:-}" = "1" ]; then
  say "left running: the admin http://127.0.0.1:$admin_port (owner@woodex.test, the password is in $work/owner-password), the shop http://127.0.0.1:$shop_port"
fi
[ "$failed" = 0 ] && say "BOTH ACCEPTANCES HELD" || say "AN ACCEPTANCE DID NOT HOLD"
exit "$failed"

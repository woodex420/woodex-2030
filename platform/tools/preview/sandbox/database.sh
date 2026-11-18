#!/usr/bin/env bash
# A database server of its own for a sandbox that can reach only GitHub and npm (see ../README.md).
#
# Why: SQLite lets one writer in at a time. What two people writing at the same moment do to the books shows only
# on MySQL or MariaDB (master list T-093, tools/ci/two-writers.php). CI has both; a sandbox has neither, and no
# package mirror, container registry or release download can be reached from it. One server binary can: MariaDB
# 10.2.11 for Linux, kept as a file in the Git tree of the MariaDB4j project at its tag mariaDB4j-3.0.1.
#
# It is an OLD server (2017). Its locks behave as the newer ones do in everything this work has met, but it is not
# the server the product runs on: what counts is CI's run on MySQL 8.4, MariaDB 10.11 and MariaDB 11.4. Use it to
# see a fault in seconds instead of waiting for CI, never as the proof.
#
#   bash platform/tools/preview/sandbox/database.sh           build it under ~/.cache/woodex-os/mariadb (once, ~1 min)
#   bash platform/tools/preview/sandbox/database.sh --serve   run the server in the foreground (127.0.0.1:33106)
#   bash platform/tools/preview/sandbox/database.sh --create  with the server running: the database and its user
#
# Then, from the work tree that has vendor/ (setup.sh made it):
#   . ~/.cache/woodex-os/bin/wxdb                              CI's database settings, pointed at this server
#   ~/.cache/woodex-os/php85 artisan migrate:fresh --force
#   ~/.cache/woodex-os/php85 tools/ci/two-writers.php --runs=10
set -u
W="${WOODEX_SANDBOX:-$HOME/.cache/woodex-os}"
M="$W/mariadb"
PORT=33106
say() { printf '[%s] %s\n' "$(date +%H:%M:%S)" "$*"; }

if [ "${1:-}" = "--serve" ]; then
  cd "$M" || { echo "run this script without arguments first" >&2; exit 2; }
  export LD_LIBRARY_PATH="$M/shim"
  # explicit-defaults-for-timestamp: off in this old server, on in every server the product meets; without it a
  # migration with two timestamp columns is refused.
  exec ./bin/mysqld --no-defaults --basedir="$M" --datadir="$M/data" --tmpdir="$M/tmp" --socket="$M/mysql.sock" \
    --port="$PORT" --bind-address=127.0.0.1 --skip-name-resolve --character-set-server=utf8mb4 \
    --collation-server=utf8mb4_unicode_ci --explicit-defaults-for-timestamp=1 --innodb-flush-log-at-trx-commit=0 \
    --innodb-buffer-pool-size=256M --max-connections=300 --console
fi

if [ "${1:-}" = "--create" ]; then
  "$W/php85" -r '
    $p = new PDO("mysql:host=127.0.0.1;port='"$PORT"'", "root", "");
    $p->exec("CREATE DATABASE IF NOT EXISTS woodex_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $p->exec("CREATE USER IF NOT EXISTS \"woodex_test\"@\"127.0.0.1\" IDENTIFIED BY \"disposable-ci-only\"");
    $p->exec("GRANT ALL ON woodex_test.* TO \"woodex_test\"@\"127.0.0.1\"");
    echo "database woodex_test on ", $p->query("SELECT VERSION()")->fetchColumn(), PHP_EOL;
  ' || { echo "is the server running? (--serve)" >&2; exit 1; }
  mkdir -p "$W/bin"
  cat > "$W/bin/wxdb" <<'EOF'
#!/bin/sh
# Source this: the settings CI's database jobs use, pointed at the sandbox's own server (127.0.0.1:33106).
export APP_ENV=testing APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= APP_URL=http://localhost
export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT=33106 DB_DATABASE=woodex_test DB_USERNAME=woodex_test DB_PASSWORD=disposable-ci-only DB_URL= DB_SOCKET=
export MAIL_MAILER=array QUEUE_CONNECTION=sync CACHE_STORE=array SESSION_DRIVER=array WOODEX_MODULES='*' COLUMNS=200
EOF
  say "settings: . $W/bin/wxdb"
  exit 0
fi

[ -x "$W/php85" ] || { echo "run platform/tools/preview/sandbox/setup.sh first (it brings PHP with pdo_mysql)" >&2; exit 2; }
mkdir -p "$M" && cd "$M" || exit 2

if [ ! -x "$M/bin/mysqld" ]; then
  # Only the folder that holds the server is fetched: one file of 57 MB.
  rm -rf "$M/source"
  git -c advice.detachedHead=false clone --quiet --depth 1 --filter=blob:none --sparse --branch mariaDB4j-3.0.1 https://github.com/MariaDB4j/MariaDB4j.git "$M/source" \
    && git -C "$M/source" sparse-checkout set DBs/mariaDB4j-db-linux64-10.2.11 > /dev/null
  say "the archive from GitHub: exit $?"
  echo "d9b110dd61d7ba3250e5d36441d527a296d5662761e04d11c739b77da8460f24  $M/source/DBs/mariaDB4j-db-linux64-10.2.11/mariaDB/linux.zip" | sha256sum -c --quiet - \
    || { echo "the archive is not the one this script was written for: stopping" >&2; exit 1; }
  unzip -q -o "$M/source/DBs/mariaDB4j-db-linux64-10.2.11/mariaDB/linux.zip" -d "$M" && chmod +x "$M"/bin/* "$M"/scripts/*
  say "unpacked: exit $?"
  rm -rf "$M/source"
fi

# The server asks for libaio.so.1, which a bare image does not have and cannot install. Its five calls are thin
# wrappers over system calls: a stand-in of a few lines is compiled here.
if ! LD_LIBRARY_PATH="$M/shim" "$M/bin/mysqld" --version > /dev/null 2>&1; then
  mkdir -p "$M/shim" && cd "$M/shim" || exit 2
  cat > aio.c <<'EOF'
#define _GNU_SOURCE
#include <unistd.h>
#include <sys/syscall.h>
#include <errno.h>
#include <time.h>
struct iocb; struct io_event;
static long ret(long r) { return r < 0 ? -errno : r; }
int io_setup(int maxevents, unsigned long *ctxp) { return (int) ret(syscall(SYS_io_setup, maxevents, ctxp)); }
int io_destroy(unsigned long ctx) { return (int) ret(syscall(SYS_io_destroy, ctx)); }
int io_submit(unsigned long ctx, long nr, struct iocb **iocbpp) { return (int) ret(syscall(SYS_io_submit, ctx, nr, iocbpp)); }
int io_cancel(unsigned long ctx, struct iocb *iocb, struct io_event *evt) { return (int) ret(syscall(SYS_io_cancel, ctx, iocb, evt)); }
int io_getevents(unsigned long ctx, long min_nr, long nr, struct io_event *events, struct timespec *timeout) { return (int) ret(syscall(SYS_io_getevents, ctx, min_nr, nr, events, timeout)); }
EOF
  cat > aio.map <<'EOF'
LIBAIO_0.1 { global: io_destroy; io_submit; io_cancel; local: *; };
LIBAIO_0.4 { global: io_setup; io_getevents; } LIBAIO_0.1;
EOF
  gcc -shared -fPIC -O1 -o libaio.so.1 aio.c -Wl,--version-script=aio.map -Wl,-soname,libaio.so.1
  say "the stand-in for libaio: exit $?"
  cd "$M" || exit 2
fi

if [ ! -d "$M/data/mysql" ]; then
  mkdir -p "$M/data" "$M/tmp"
  LD_LIBRARY_PATH="$M/shim" "$M/scripts/mysql_install_db" --no-defaults --basedir="$M" --datadir="$M/data" \
    --auth-root-authentication-method=normal --skip-name-resolve > "$M/install.log" 2>&1
  say "an empty data folder: exit $?"
fi

say "$(LD_LIBRARY_PATH="$M/shim" "$M/bin/mysqld" --version 2>&1)"
say "READY. Next: run this script with --serve (as a background process), then with --create."

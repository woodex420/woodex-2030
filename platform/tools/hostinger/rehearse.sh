#!/usr/bin/env bash
# WOODEX OS: a fresh release in a folder laid out like a Hostinger account, served without Apache.
#
# For a machine that has PHP and nothing else (the sandbox). It puts a release together
# (build-release.sh), carries it through Git as a deployment does (carry-through-git.sh), into
#
#   <folder>/home/u123456789/domains/staging.woodex.test/public_html     the website's folder
#   <folder>/home/u123456789/woodex-os/staging.woodex.test/              where the installer writes the settings
#
# and serves the website's folder with PHP's own server and router.php, which answers as the folder's .htaccess
# does. Nothing is installed yet when it starts: the first page is the installer. scripts/os-install.mjs presses
# through it.
#
#   bash platform/tools/hostinger/rehearse.sh [port, default 8195] [folder, default /tmp/woodex-hostinger]
#
#   PHP_BIN, COMPOSER_BIN   as for build-release.sh
#
# The check that counts is the same release under Apache with the real .htaccess and MariaDB
# (workflow os-ci.yml, job "hostinger").
set -euo pipefail

port="${1:-8195}"
folder="${2:-/tmp/woodex-hostinger}"
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
php="${PHP_BIN:-php}"
home="$folder/home/u123456789"
site="$home/domains/staging.woodex.test/public_html"

rm -rf "$folder"
mkdir -p "$(dirname "$site")"
# What is served is the release as Git delivers it to a host, as in CI.
bash "$here/build-release.sh" "$folder/built"
bash "$here/carry-through-git.sh" "$folder/built" "$site"

echo "the website's folder: $site"
echo "the setup code, once the installer has been opened: $site/WOODEX-SETUP-CODE.txt"
echo "serving on port $port"
cd "$site"
# WOODEX_HOME names the account's home folder for a machine whose own home is elsewhere; the installer keeps the
# settings under it. Four workers: a page of the admin asks for several things at once.
# PHP's server writes two lines for every connection; they are left out, what the application logs is kept.
WOODEX_HOME="$home" HOME="$home" PHP_CLI_SERVER_WORKERS=4 exec "$php" -d display_errors=0 -d log_errors=1 \
  -S "0.0.0.0:$port" -t "$site" "$here/router.php" 2> >(grep --line-buffered -v -E ' (Accepted|Closing)$' >&2)

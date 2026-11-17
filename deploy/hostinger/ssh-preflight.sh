#!/usr/bin/env bash
# Read-only runtime checks. No environment files, database access or filesystem writes.
set -euo pipefail
if ! command -v php >/dev/null; then
  echo 'BLOCKED: PHP CLI not on PATH; hosting must identify the supported PHP 8.4+ binary.'
  exit 1
fi
php -r '
$ok = version_compare(PHP_VERSION, "8.4.1", ">=");
echo "PHP >=8.4.1: ".($ok ? "PASS" : "FAIL").PHP_EOL;
foreach (["pdo_mysql", "mbstring", "bcmath", "intl", "zip", "gd", "exif", "dom", "xml", "xmlwriter", "fileinfo", "sodium"] as $ext) {
    $loaded = extension_loaded($ext);
    echo "extension ".$ext.": ".($loaded ? "PASS" : "FAIL").PHP_EOL;
    $ok = $ok && $loaded;
}
exit($ok ? 0 : 1);
'
echo 'CLI prerequisites passed. Web PHP, document root, database, login and backup restoration NOT verified.'

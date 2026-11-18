#!/usr/bin/env bash
# WOODEX OS: the next deployment, in a rehearsal.
#
#   bash platform/tools/hostinger/redeploy.sh <release folder> <website folder> [replace|over]
#
# Puts a release in the place of the one a website's folder holds, as a deployment does, and adds one database
# change to it (rehearsal-migration.php), so that what follows a deployment can be tried: the site is still
# installed, the owner still signs in, and "Apply update" applies the change (scripts/os-install.mjs --after-deploy).
#
#   replace  (default) everything in the website's folder is removed first. This is the hardest case: whatever
#            the installer or the application wrote into that folder is gone. The settings, the uploads and an
#            SQLite database are kept outside it for exactly this reason.
#   over     the release is copied over what is there; files the new release does not have stay where they are.
#
# Which of the two a host's Git deployment does is the host's business; the application has to survive both.
set -euo pipefail

release="${1:?the folder of a release (build-release.sh)}"
site="${2:?the website folder to deploy into}"
how="${3:-replace}"
here="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

[ -f "$release/RELEASE.json" ] || { echo "$release is not a release: it has no RELEASE.json" >&2; exit 1; }
[ -f "$site/platform/artisan" ] || { echo "$site does not hold an earlier release" >&2; exit 1; }

case "$how" in
  replace) find "$site" -mindepth 1 -maxdepth 1 -exec rm -rf {} + ;;
  over) ;;
  *) echo "replace or over, not $how" >&2; exit 1 ;;
esac

cp -a "$release/." "$site/"
cp "$here/rehearsal-migration.php" "$site/platform/database/migrations/2099_01_01_000000_rehearsal_second_release.php"
echo "deployed again ($how) into $site, with one more database change: 2099_01_01_000000_rehearsal_second_release"

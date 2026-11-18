#!/usr/bin/env bash
# WOODEX OS: a release after its journey through Git.
#
#   bash platform/tools/hostinger/carry-through-git.sh <release folder> <out: a folder that does not exist>
#
# A release reaches the host through Git: GitHub writes it to a branch, and the host's Git page takes it from
# there. Git keeps no empty folder and only one bit of a file's mode. So that what is rehearsed is what arrives,
# the rehearsal installs the release after the same journey: committed, then taken out of the commit again.
#
# Fails when a file is lost or changed on the way. Empty folders are lost by nature; they are counted.
set -euo pipefail

built="${1:?the folder of a release (build-release.sh)}"
out="${2:?where the carried release is put}"
[ -f "$built/RELEASE.json" ] || { echo "$built is not a release: it has no RELEASE.json" >&2; exit 1; }
[ ! -e "$out" ] || { echo "$out exists already" >&2; exit 1; }

work="$(mktemp -d)"; trap 'rm -rf "$work" 2>/dev/null || true' EXIT
git init --quiet "$work/carried"
cp -a "$built/." "$work/carried/"
git -C "$work/carried" config user.name "rehearsal"
git -C "$work/carried" config user.email "rehearsal@woodex.test"
git -C "$work/carried" config core.autocrlf false
# Ten thousand new files make Git tidy up behind the scenes; that would still be writing when this is done.
git -C "$work/carried" config gc.auto 0
# --force: a release keeps the small ignore files that hold its folders, and they must not hide what lies beside them.
git -C "$work/carried" add --all --force .
git -C "$work/carried" commit --quiet -m "release"
git clone --quiet --no-hardlinks "$work/carried" "$out"
rm -rf "$out/.git"

files=$(find "$built" -type f | wc -l)
arrived=$(find "$out" -type f | wc -l)
empty=$(find "$built" -type d -empty | wc -l)
changed=$(diff -r --brief "$built" "$out" | grep -c -v '^Only in ' || true)
result="${arrived} of ${files} files arrive, ${changed} of them changed on the way; ${empty} empty folders of the packages are not carried"
if [ "$files" != "$arrived" ] || [ "$changed" != "0" ]; then
  echo "the release does not survive Git: $result" >&2
  diff -r --brief "$built" "$out" | grep -v -E '^Only in .*: [^ ]+$' | head -20 >&2 || true
  exit 1
fi
echo "the release as Git carries it: $result"
